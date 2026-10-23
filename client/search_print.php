<?php
/**
 * ============================================================================
 * YUHU PHOTOBOOTH - SMART VISUAL IMAGE SEARCH ENGINE (v2.0 REVISED)
 * ============================================================================
 * Fitur pencarian foto visual canggih tanpa barcode:
 * 1. Multi-Aspect Crop Engine (menguji Full, Center Strip 1:3, Center 2:3, Inner 80%
 *    untuk membuang background meja/tangan secara otomatis).
 * 2. 64-bit Difference Hash (dHash) + 32-Zone Color & Luminance Signature.
 * 3. Smart Lighting Compensation (tahan terhadap bayangan/lampu redup).
 * 4. Fuzzy Session Finder & Top-3 Candidates Card (selalu menyajikan hasil terbaik).
 * ============================================================================
 */

header('Content-Type: application/json; charset=utf-8');
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
@set_time_limit(90);

require_once '../config.php';
if (session_status() === PHP_SESSION_ACTIVE) {
    @session_write_close();
}
define('LUMABOOTH_SKIP_AUTOSYNC', true);
require_once __DIR__ . '/../lumabooth_sync.php';

// 1. Validasi Parameter Client Event
$raw_param = $_REQUEST['client'] ?? ($_REQUEST['c'] ?? '');
if (empty($raw_param) && !empty($_SERVER['HTTP_REFERER'])) {
    $ref_path = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_PATH);
    if (preg_match('#/client/([^/?#]+)#i', $ref_path, $m)) {
        $raw_param = urldecode($m[1]);
    }
}
$client_name = trim(str_replace(['..', '/', '\\', "\0"], '', $raw_param));

if (empty($client_name)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Parameter event (client) harus diisi. Silahkan muat ulang halaman galeri.']);
    exit;
}

// Cari folder foto event yang sesuai
$photos_root = __DIR__ . '/photos';
$req_clean = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $client_name));

// Coba cocokkan dengan Database Portal jika folder tidak langsung ditemukan
$db_client = null;
try {
    $stmt = $pdo->query("SELECT * FROM clients ORDER BY id DESC");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $c_name = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $row['nama_client']));
        $c_slug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $row['slug']));
        $c_folder = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', extractLumaboothFolderFromUrl($row['folder_foto_path'] ?? '')));
        if ($req_clean !== '' && ($c_name === $req_clean || $c_slug === $req_clean || ($c_folder !== '' && $c_folder === $req_clean))) {
            $db_client = $row;
            break;
        }
    }
} catch (Exception $e) {}

$resolved_folder = $client_name;

if (!is_dir($photos_root . '/' . $resolved_folder)) {
    $search_candidates = [$req_clean];
    if ($db_client) {
        $search_candidates[] = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $db_client['nama_client']));
        $f_hint = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', extractLumaboothFolderFromUrl($db_client['folder_foto_path'] ?? '')));
        if ($f_hint !== '') $search_candidates[] = $f_hint;
    }
    $dirs = @scandir($photos_root) ?: [];
    foreach ($dirs as $dir) {
        if ($dir !== '.' && $dir !== '..' && is_dir($photos_root . '/' . $dir)) {
            $dir_clean = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $dir));
            if ($dir_clean !== '' && in_array($dir_clean, $search_candidates, true)) {
                $resolved_folder = $dir;
                break;
            }
        }
    }
}

$target_dir = $photos_root . '/' . $resolved_folder;
if (!is_dir($target_dir)) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => "Folder event '$resolved_folder' tidak ditemukan."]);
    exit;
}

// Cek apakah fitur visual search diizinkan untuk event ini
$search_enabled = true;
$cfg_file = $target_dir . '/config.json';
if (file_exists($cfg_file)) {
    $cfg = @json_decode(@file_get_contents($cfg_file), true);
    if (isset($cfg['enable_visual_search'])) {
        $search_enabled = (bool)$cfg['enable_visual_search'];
    }
}
if ($search_enabled) {
    try {
        $stmt_check = $pdo->query("SELECT * FROM clients");
        foreach ($stmt_check->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $c_name = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $row['nama_client']));
            $c_slug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $row['slug']));
            $c_folder = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', extractLumaboothFolderFromUrl($row['folder_foto_path'] ?? '')));
            if ($req_clean !== '' && ($c_name === $req_clean || $c_slug === $req_clean || ($c_folder !== '' && $c_folder === $req_clean))) {
                if (isset($row['enable_visual_search']) && (int)$row['enable_visual_search'] === 0) {
                    $search_enabled = false;
                }
                break;
            }
        }
    } catch (Exception $e) {}
}

if (!$search_enabled) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'match_found' => false,
        'message' => 'Fitur pencarian foto cetak dinonaktifkan untuk event ini.'
    ]);
    exit;
}

// 2. Ambil Data Gambar yang Diunggah Tamu
$rawImageData = null;
if (!empty($_FILES['photo']['tmp_name']) && is_uploaded_file($_FILES['photo']['tmp_name'])) {
    $rawImageData = @file_get_contents($_FILES['photo']['tmp_name']);
} elseif (!empty($_POST['image_base64'])) {
    $base64 = $_POST['image_base64'];
    if (preg_match('/^data:image\/(\w+);base64,/', $base64)) {
        $base64 = substr($base64, strpos($base64, ',') + 1);
    }
    $rawImageData = @base64_decode($base64);
}

if (!$rawImageData || strlen($rawImageData) < 100) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Tidak ada foto yang diterima untuk dicocokkan. Silahkan ambil foto cetakan Anda.']);
    exit;
}

if (!extension_loaded('gd')) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Ekstensi PHP GD belum aktif di server.']);
    exit;
}

// 3. Muat Gambar Query Tamu ke GD Resource
$queryImg = @imagecreatefromstring($rawImageData);
if (!$queryImg) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Format foto tidak valid atau rusak. Silahkan coba foto ulang.']);
    exit;
}

// Tangani orientasi EXIF (Kamera HP sering menyertakan tag EXIF orientation)
if (function_exists('exif_read_data')) {
    $exif = null;
    if (!empty($_FILES['photo']['tmp_name']) && file_exists($_FILES['photo']['tmp_name'])) {
        $exif = @exif_read_data($_FILES['photo']['tmp_name']);
    }
    if (!$exif && strlen($rawImageData) > 100) {
        $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode(substr($rawImageData, 0, 131072)));
    }
    if ($exif && !empty($exif['Orientation'])) {
        switch ((int)$exif['Orientation']) {
            case 3:
                $rotated = imagerotate($queryImg, 180, 0);
                if ($rotated) { imagedestroy($queryImg); $queryImg = $rotated; }
                break;
            case 6:
                $rotated = imagerotate($queryImg, -90, 0);
                if ($rotated) { imagedestroy($queryImg); $queryImg = $rotated; }
                break;
            case 8:
                $rotated = imagerotate($queryImg, 90, 0);
                if ($rotated) { imagedestroy($queryImg); $queryImg = $rotated; }
                break;
        }
    }
}

// ============================================================================
// FUNGSI-FUNGSI SIGNATURE & POSE-AWARE MULTI-SCALE MATCHING ENGINE
// ============================================================================

function extractLuminanceMatrix($srcImg, $targetW, $targetH) {
    $thumb = imagecreatetruecolor($targetW, $targetH);
    imagecopyresampled($thumb, $srcImg, 0, 0, 0, 0, $targetW, $targetH, imagesx($srcImg), imagesy($srcImg));

    $lumValues = [];
    $sum = 0;
    for ($y = 0; $y < $targetH; $y++) {
        for ($x = 0; $x < $targetW; $x++) {
            $rgb = imagecolorat($thumb, $x, $y);
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;
            $lum = (int)(($r * 299 + $g * 587 + $b * 114) / 1000);
            $lumValues[] = $lum;
            $sum += $lum;
        }
    }
    imagedestroy($thumb);

    $count = $targetW * $targetH;
    $mean = ($count > 0) ? (int)($sum / $count) : 0;
    $normalized = [];
    for ($i = 0; $i < $count; $i++) {
        $normalized[] = $lumValues[$i] - $mean;
    }
    return $normalized;
}

function extractGradientMap($normLum, $w, $h) {
    $gradients = [];
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $idx = $y * $w + $x;
            $val = $normLum[$idx];
            $dx = ($x < $w - 1) ? abs($normLum[$idx + 1] - $val) : 0;
            $dy = ($y < $h - 1) ? abs($normLum[$idx + $w] - $val) : 0;
            $gradients[] = (int)($dx + $dy);
        }
    }
    return $gradients;
}

function extractPoseAwareSignature($img) {
    $w = imagesx($img);
    $h = imagesy($img);

    // 1. Global context (12x16)
    $globalLum = extractLuminanceMatrix($img, 12, 16);

    // 2. Tentukan Area Foto Utama (Inner Photo Area, pisahkan dari border/footer event)
    $innerLum = [];
    $innerEdge = [];
    $slot1Lum = [];
    $slot2Lum = [];

    if ($h > $w) {
        // Strip Photobooth (Vertikal)
        $inX = (int)($w * 0.06);
        $inW = max(20, (int)($w * 0.88));
        $inY = (int)($h * 0.05);
        $inH = max(40, (int)($h * 0.74));

        $cropInner = @imagecrop($img, ['x' => $inX, 'y' => $inY, 'width' => $inW, 'height' => $inH]);
        if ($cropInner) {
            $innerLum = extractLuminanceMatrix($cropInner, 16, 24);
            $innerEdge = extractGradientMap($innerLum, 16, 24);
            imagedestroy($cropInner);
        }

        // Slot 1 (Foto Atas): y = 5% s/d 42%
        $s1Y = (int)($h * 0.05);
        $s1H = max(20, (int)($h * 0.38));
        $cropS1 = @imagecrop($img, ['x' => $inX, 'y' => $s1Y, 'width' => $inW, 'height' => $s1H]);
        if ($cropS1) {
            $slot1Lum = extractLuminanceMatrix($cropS1, 16, 16);
            imagedestroy($cropS1);
        }

        // Slot 2 (Foto Bawah): y = 39% s/d 78%
        $s2Y = (int)($h * 0.39);
        $s2H = max(20, (int)($h * 0.39));
        $cropS2 = @imagecrop($img, ['x' => $inX, 'y' => $s2Y, 'width' => $inW, 'height' => $s2H]);
        if ($cropS2) {
            $slot2Lum = extractLuminanceMatrix($cropS2, 16, 16);
            imagedestroy($cropS2);
        }
    } else {
        // Horizontal (Postcard / 4x6)
        $inX = (int)($w * 0.05);
        $inW = max(20, (int)($w * 0.90));
        $inY = (int)($h * 0.05);
        $inH = max(20, (int)($h * 0.90));

        $cropInner = @imagecrop($img, ['x' => $inX, 'y' => $inY, 'width' => $inW, 'height' => $inH]);
        if ($cropInner) {
            $innerLum = extractLuminanceMatrix($cropInner, 24, 16);
            $innerEdge = extractGradientMap($innerLum, 24, 16);
            imagedestroy($cropInner);
        }

        // Slot 1 (Kiri)
        $s1W = max(20, (int)($inW * 0.52));
        $cropS1 = @imagecrop($img, ['x' => $inX, 'y' => $inY, 'width' => $s1W, 'height' => $inH]);
        if ($cropS1) {
            $slot1Lum = extractLuminanceMatrix($cropS1, 16, 16);
            imagedestroy($cropS1);
        }

        // Slot 2 (Kanan)
        $s2X = (int)($inX + $inW * 0.48);
        $s2W = max(20, (int)($inW * 0.52));
        $cropS2 = @imagecrop($img, ['x' => $s2X, 'y' => $inY, 'width' => $s2W, 'height' => $inH]);
        if ($cropS2) {
            $slot2Lum = extractLuminanceMatrix($cropS2, 16, 16);
            imagedestroy($cropS2);
        }
    }

    return [
        'global'     => $globalLum,
        'inner'      => $innerLum,
        'inner_edge' => $innerEdge,
        'slot1'      => $slot1Lum,
        'slot2'      => $slot2Lum
    ];
}

function compareVectorDist($v1, $v2) {
    if (empty($v1) || empty($v2) || count($v1) !== count($v2)) return 999;
    $sum = 0;
    $n = count($v1);
    for ($i = 0; $i < $n; $i++) {
        $sum += abs($v1[$i] - $v2[$i]);
    }
    return $sum / $n;
}

function comparePoseAwareSignatures($qSig, $cSig) {
    if (empty($qSig) || empty($cSig)) return 0;

    // 1. Global context difference
    $diffG = compareVectorDist($qSig['global'] ?? [], $cSig['global'] ?? []);
    $scoreG = max(0, 100 - $diffG * 1.5);

    // 2. Inner photo luminance (pose & people) difference
    $diffI = compareVectorDist($qSig['inner'] ?? [], $cSig['inner'] ?? []);
    $scoreI = max(0, 100 - $diffI * 1.6);

    // 3. Inner edge/contour (hands & gestures) difference
    $diffE = compareVectorDist($qSig['inner_edge'] ?? [], $cSig['inner_edge'] ?? []);
    $scoreE = max(0, 100 - $diffE * 2.2);

    // 4. Slot-level pose comparison (Slot 1 & Slot 2)
    $diffS1 = compareVectorDist($qSig['slot1'] ?? [], $cSig['slot1'] ?? []);
    $scoreS1 = max(0, 100 - $diffS1 * 1.8);

    $diffS2 = compareVectorDist($qSig['slot2'] ?? [], $cSig['slot2'] ?? []);
    $scoreS2 = max(0, 100 - $diffS2 * 1.8);

    $diffCross1 = compareVectorDist($qSig['slot1'] ?? [], $cSig['slot2'] ?? []);
    $scoreCross1 = max(0, 100 - $diffCross1 * 1.8);

    $diffCross2 = compareVectorDist($qSig['slot2'] ?? [], $cSig['slot1'] ?? []);
    $scoreCross2 = max(0, 100 - $diffCross2 * 1.8);

    $directSlots = ($scoreS1 + $scoreS2) / 2;
    $bestSlot = max($directSlots, $scoreS1, $scoreS2, $scoreCross1, $scoreCross2);

    // Bobot Terkalibrasi:
    // Global Border: 15% (hanya verifikasi tema/event)
    // Inner People & Pose: 35% (posisi kepala, baju, tubuh)
    // Inner Edge & Hand Gestures: 20% (kontur pose & gestur tangan)
    // Slot Pose Detail: 30% (detail pose slot foto individu)
    $finalScore = ($scoreG * 0.15) + ($scoreI * 0.35) + ($scoreE * 0.20) + ($bestSlot * 0.30);
    return round($finalScore, 1);
}

function generateQueryVariations($img) {
    $w = imagesx($img);
    $h = imagesy($img);
    $images = [$img];

    if ($h > $w) {
        // 1. Center Crop 1:3 (Strip Photobooth)
        $stripW = (int)($h / 2.9);
        if ($stripW < $w && $stripW > 40) {
            $c1 = @imagecrop($img, [
                'x' => (int)(($w - $stripW) / 2),
                'y' => 0,
                'width' => $stripW,
                'height' => $h
            ]);
            if ($c1) $images[] = $c1;
        }

        // 2. Center Crop 2:3 (Postcard 4x6)
        $cardW = (int)($h * 2 / 3);
        if ($cardW < $w && $cardW > 40) {
            $c2 = @imagecrop($img, [
                'x' => (int)(($w - $cardW) / 2),
                'y' => 0,
                'width' => $cardW,
                'height' => $h
            ]);
            if ($c2) $images[] = $c2;
        }

        // 3. Center Inner 80% (Menghilangkan margin meja dan jari pemegang foto)
        $inW = (int)($w * 0.82);
        $inH = (int)($h * 0.82);
        $c3 = @imagecrop($img, [
            'x' => (int)($w * 0.09),
            'y' => (int)($h * 0.09),
            'width' => $inW,
            'height' => $inH
        ]);
        if ($c3) $images[] = $c3;

        // 4. Fokus Foto Atas
        $cTop = @imagecrop($img, [
            'x' => 0,
            'y' => 0,
            'width' => $w,
            'height' => (int)($h * 0.60)
        ]);
        if ($cTop) $images[] = $cTop;

        // 5. Fokus Foto Bawah
        $cBottom = @imagecrop($img, [
            'x' => 0,
            'y' => (int)($h * 0.40),
            'width' => $w,
            'height' => (int)($h * 0.60)
        ]);
        if ($cBottom) $images[] = $cBottom;
    } else {
        // Horizontal orientation
        $cardH = (int)($w * 2 / 3);
        if ($cardH < $h && $cardH > 40) {
            $c1 = @imagecrop($img, [
                'x' => 0,
                'y' => (int)(($h - $cardH) / 2),
                'width' => $w,
                'height' => $cardH
            ]);
            if ($c1) $images[] = $c1;
        }

        // Coba rotasi 90 derajat CW & CCW
        $rCW = @imagerotate($img, -90, 0);
        if ($rCW) {
            $images[] = $rCW;
            $rH = imagesy($rCW);
            $rW = imagesx($rCW);
            $stripW = (int)($rH / 2.9);
            if ($stripW < $rW && $stripW > 40) {
                $cStrip = @imagecrop($rCW, [
                    'x' => (int)(($rW - $stripW) / 2),
                    'y' => 0,
                    'width' => $stripW,
                    'height' => $rH
                ]);
                if ($cStrip) $images[] = $cStrip;
            }
        }
        $rCCW = @imagerotate($img, 90, 0);
        if ($rCCW) {
            $images[] = $rCCW;
        }
    }

    $signatures = [];
    foreach ($images as $im) {
        $signatures[] = extractPoseAwareSignature($im);
        if ($im !== $img) {
            imagedestroy($im);
        }
    }
    return $signatures;
}

// 4. Hitung Signature untuk semua variasi foto tamu
$querySignatures = generateQueryVariations($queryImg);
imagedestroy($queryImg);

// ============================================================================
// 5. AMBIL ATAU BANGUN CACHE SIGNATURE UNTUK MASTER PRINT EVENT
// ============================================================================
$cacheFile = $target_dir . '/.search_cache_v4.json';
$candidateSignatures = [];
$needRebuild = true;

// Kumpulkan seluruh file cetakan (Prints) yang valid
$candidateFiles = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($target_dir, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

foreach ($iterator as $item) {
    if ($item->isFile()) {
        $ext = strtolower($item->getExtension());
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
            $relPath = str_replace('\\', '/', substr($item->getPathname(), strlen($target_dir) + 1));
            $pathLower = strtolower($relPath);

            // Lewati Singles dan Originals jika ada
            if (strpos($pathLower, 'singles/') !== false || strpos($pathLower, 'originals/') !== false) {
                continue;
            }

            // Prioritaskan file di folder Prints / print
            $isPrint = (strpos($pathLower, 'prints/') !== false || strpos($pathLower, 'print') !== false);
            $candidateFiles[] = [
                'rel_path' => $relPath,
                'full_path' => $item->getPathname(),
                'mtime' => $item->getMTime(),
                'is_print' => $isPrint
            ];
        }
    }
}

// Urutkan agar Prints berada di urutan teratas
usort($candidateFiles, function($a, $b) {
    return ($b['is_print'] ? 1 : 0) - ($a['is_print'] ? 1 : 0);
});

// Cek apakah cache v4 masih valid
if (file_exists($cacheFile)) {
    $cacheContent = @file_get_contents($cacheFile);
    $cached = $cacheContent ? @json_decode($cacheContent, true) : null;
    if (is_array($cached) && isset($cached['count']) && $cached['count'] === count($candidateFiles)) {
        $candidateSignatures = $cached['signatures'] ?? [];
        $needRebuild = false;
    }
}

if ($needRebuild || empty($candidateSignatures)) {
    $candidateSignatures = [];
    foreach ($candidateFiles as $cand) {
        $candBytes = @file_get_contents($cand['full_path']);
        if (!$candBytes) continue;
        $img = @imagecreatefromstring($candBytes);
        if ($img) {
            $candidateSignatures[] = [
                'file' => $cand['rel_path'],
                'pose_sig' => extractPoseAwareSignature($img),
                'is_print' => $cand['is_print']
            ];
            imagedestroy($img);
        }
    }
    // Simpan cache agar pencarian berikutnya instan
    @file_put_contents($cacheFile, json_encode([
        'count' => count($candidateFiles),
        'timestamp' => time(),
        'signatures' => $candidateSignatures
    ]));
}

if (empty($candidateSignatures)) {
    echo json_encode([
        'success' => true,
        'match_found' => false,
        'message' => 'Belum ada foto master cetakan yang diunggah pada galeri event ini.'
    ]);
    exit;
}

// ============================================================================
// 6. PENCOCOKAN MULTI-VARIASI DENGAN SELURUH MASTER PRINT
// ============================================================================
$results = [];
foreach ($candidateSignatures as $cand) {
    $bestScoreForCand = 0;

    // Uji candidate terhadap semua variasi crop foto tamu (ambil skor tertinggi)
    foreach ($querySignatures as $qSig) {
        $score = comparePoseAwareSignatures($qSig, $cand['pose_sig']);
        if ($score > $bestScoreForCand) {
            $bestScoreForCand = $score;
        }
    }

    // Sedikit bonus jika memang file dari folder Prints resmi
    if (!empty($cand['is_print'])) {
        $bestScoreForCand = min(100, $bestScoreForCand + 2);
    }

    $encodedClient = rawurlencode($resolved_folder);
    $encodedPath = implode('/', array_map('rawurlencode', explode('/', $cand['file'])));

    // Tentukan Base Session ID
    $fname = basename($cand['file']);
    if (preg_match('/^(\d{8}_\d{6}_\d{3})/', $fname, $m)) {
        $baseId = $m[1];
    } else {
        $baseId = preg_replace('/(_mp4|_gif|\.[a-zA-Z0-9]+)$/i', '', $fname);
    }

    $results[] = [
        'file' => $cand['file'],
        'path' => 'photos/' . $encodedClient . '/' . $encodedPath,
        'base_id' => $baseId,
        'score' => $bestScoreForCand
    ];
}

// Urutkan dari skor tertinggi
usort($results, function($a, $b) {
    return $b['score'] <=> $a['score'];
});

$bestMatch = $results[0] ?? null;

// Ambang Batas Akurat Berbasis Pose & Kontur Detail:
// 1. High Confidence: >= 58% (Pasti Cocok)
// 2. Medium Confidence: >= 45% (Kandidat Terbaik)
$isHighMatch = ($bestMatch && $bestMatch['score'] >= 58.0);
$isPossibleMatch = ($bestMatch && $bestMatch['score'] >= 45.0);

echo json_encode([
    'success' => true,
    'match_found' => ($isHighMatch || $isPossibleMatch),
    'confidence_level' => $isHighMatch ? 'high' : ($isPossibleMatch ? 'medium' : 'low'),
    'best_match' => ($isHighMatch || $isPossibleMatch) ? $bestMatch : null,
    'highest_score' => $bestMatch ? $bestMatch['score'] : 0,
    'top_candidates' => []
]);
exit;
