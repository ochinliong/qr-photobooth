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
$client_name = trim(str_replace(['..', '/', '\\', "\0"], '', $raw_param));

if (empty($client_name)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Parameter event (client) harus diisi.']);
    exit;
}

// Cari folder foto event yang sesuai
$photos_root = __DIR__ . '/photos';
$req_clean = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $client_name));
$resolved_folder = $client_name;

if (!is_dir($photos_root . '/' . $resolved_folder)) {
    $dirs = @scandir($photos_root) ?: [];
    foreach ($dirs as $dir) {
        if ($dir !== '.' && $dir !== '..' && is_dir($photos_root . '/' . $dir)) {
            $dir_clean = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $dir));
            if ($dir_clean !== '' && $dir_clean === $req_clean) {
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
    $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($rawImageData));
    if ($exif && !empty($exif['Orientation'])) {
        switch ((int)$exif['Orientation']) {
            case 3:
                $queryImg = imagerotate($queryImg, 180, 0);
                break;
            case 6:
                $queryImg = imagerotate($queryImg, -90, 0);
                break;
            case 8:
                $queryImg = imagerotate($queryImg, 90, 0);
                break;
        }
    }
}

// ============================================================================
// FUNGSI-FUNGSI SIGNATURE & MULTI-CROP ENGINE
// ============================================================================

/**
 * 1. Compute 64-bit Difference Hash (dHash)
 */
function computeDHash($srcImg) {
    $w = imagesx($srcImg);
    $h = imagesy($srcImg);
    $target = imagecreatetruecolor(9, 8);
    imagecopyresampled($target, $srcImg, 0, 0, 0, 0, 9, 8, $w, $h);

    $hash = '';
    for ($y = 0; $y < 8; $y++) {
        $prevGray = null;
        for ($x = 0; $x < 9; $x++) {
            $rgb = imagecolorat($target, $x, $y);
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;
            $gray = (int)(0.299 * $r + 0.587 * $g + 0.114 * $b);

            if ($prevGray !== null) {
                $hash .= ($prevGray > $gray) ? '1' : '0';
            }
            $prevGray = $gray;
        }
    }
    imagedestroy($target);
    return $hash;
}

function hammingDistance($hash1, $hash2) {
    $dist = 0;
    $len = min(strlen($hash1), strlen($hash2));
    for ($i = 0; $i < $len; $i++) {
        if ($hash1[$i] !== $hash2[$i]) $dist++;
    }
    return $dist;
}

/**
 * 2. Multi-Zone Block Color Signature (4 kolom x 8 baris = 32 blok)
 */
function computeGridSignature($srcImg, $cols = 4, $rows = 8) {
    $w = imagesx($srcImg);
    $h = imagesy($srcImg);
    
    $sampleW = 32;
    $sampleH = 64;
    $sample = imagecreatetruecolor($sampleW, $sampleH);
    imagecopyresampled($sample, $srcImg, 0, 0, 0, 0, $sampleW, $sampleH, $w, $h);

    $blockW = (int)($sampleW / $cols);
    $blockH = (int)($sampleH / $rows);

    $blocks = [];
    for ($r = 0; $r < $rows; $r++) {
        for ($c = 0; $c < $cols; $c++) {
            $startX = $c * $blockW;
            $startY = $r * $blockH;
            $sumR = 0; $sumG = 0; $sumB = 0; $pixelCount = 0;

            for ($y = $startY; $y < $startY + $blockH; $y++) {
                for ($x = $startX; $x < $startX + $blockW; $x++) {
                    $rgb = imagecolorat($sample, $x, $y);
                    $sumR += ($rgb >> 16) & 0xFF;
                    $sumG += ($rgb >> 8) & 0xFF;
                    $sumB += $rgb & 0xFF;
                    $pixelCount++;
                }
            }
            $avgR = $pixelCount > 0 ? (int)($sumR / $pixelCount) : 0;
            $avgG = $pixelCount > 0 ? (int)($sumG / $pixelCount) : 0;
            $avgB = $pixelCount > 0 ? (int)($sumB / $pixelCount) : 0;
            $lum = (int)(0.299 * $avgR + 0.587 * $avgG + 0.114 * $avgB);

            $blocks[] = [$avgR, $avgG, $avgB, $lum];
        }
    }
    imagedestroy($sample);
    return $blocks;
}

/**
 * Menghitung kemiripan Grid Signature (0.0 sampai 1.0) dengan kompensasi perbedaan kecerahan
 */
function compareGridSignatures($sig1, $sig2) {
    if (empty($sig1) || empty($sig2) || count($sig1) !== count($sig2)) return 0;

    $n = count($sig1);
    $lumDiffSum = 0;
    for ($i = 0; $i < $n; $i++) {
        $lumDiffSum += ($sig1[$i][3] - $sig2[$i][3]);
    }
    $avgOffset = (int)($lumDiffSum / $n);

    $totalDiff = 0;
    for ($i = 0; $i < $n; $i++) {
        $rDiff = abs($sig1[$i][0] - $sig2[$i][0] - $avgOffset);
        $gDiff = abs($sig1[$i][1] - $sig2[$i][1] - $avgOffset);
        $bDiff = abs($sig1[$i][2] - $sig2[$i][2] - $avgOffset);
        $lDiff = abs($sig1[$i][3] - $sig2[$i][3] - $avgOffset);

        $blockDiff = ($rDiff + $gDiff + $bDiff + $lDiff * 2) / 5;
        $totalDiff += $blockDiff;
    }

    $avgDiff = $totalDiff / $n;
    return max(0, min(1, 1 - ($avgDiff / 85)));
}

/**
 * Multi-Crop Generator: Menghasilkan beberapa variasi crop query image
 * (Full, Center 1:3 Strip, Center 2:3 Postcard, dan Center Inner 80%)
 * untuk membuang background meja/tangan tamu secara otomatis!
 */
function generateQueryVariations($img) {
    $w = imagesx($img);
    $h = imagesy($img);
    $images = [$img];

    if ($h > $w) {
        // 1. Center Crop 1:3 (Strip Photobooth)
        $stripW = (int)($h / 2.9);
        if ($stripW < $w && $stripW > 40) {
            $c1 = imagecrop($img, [
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
            $c2 = imagecrop($img, [
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
        $c3 = imagecrop($img, [
            'x' => (int)($w * 0.09),
            'y' => (int)($h * 0.09),
            'width' => $inW,
            'height' => $inH
        ]);
        if ($c3) $images[] = $c3;
    } else {
        // Horizontal orientation
        $cardH = (int)($w * 2 / 3);
        if ($cardH < $h && $cardH > 40) {
            $c1 = imagecrop($img, [
                'x' => 0,
                'y' => (int)(($h - $cardH) / 2),
                'width' => $w,
                'height' => $cardH
            ]);
            if ($c1) $images[] = $c1;
        }
    }

    $signatures = [];
    foreach ($images as $im) {
        $signatures[] = [
            'dhash' => computeDHash($im),
            'grid'  => computeGridSignature($im)
        ];
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
$cacheFile = $target_dir . '/.search_cache_v2.json';
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

// Cek apakah cache v2 masih valid
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
                'dhash' => computeDHash($img),
                'grid' => computeGridSignature($img),
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
        $hamming = hammingDistance($qSig['dhash'], $cand['dhash']);
        $dhashScore = max(0, (64 - $hamming) / 64);
        $gridScore = compareGridSignatures($qSig['grid'], $cand['grid']);

        // Bobot: 35% dHash + 65% Grid Color
        $score = round(($dhashScore * 0.35 + $gridScore * 0.65) * 100, 1);
        if ($score > $bestScoreForCand) {
            $bestScoreForCand = $score;
        }
    }

    // Sedikit bonus jika memang file dari folder Prints resmi
    if (!empty($cand['is_print'])) {
        $bestScoreForCand = min(100, $bestScoreForCand + 3);
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

// Ambang Batas Fleksibel:
// 1. High Confidence: >= 52% (Pasti Cocok)
// 2. Medium Confidence: >= 38% (Kemungkinan Besar Cocok / Kandidat Terbaik)
$isHighMatch = ($bestMatch && $bestMatch['score'] >= 52.0);
$isPossibleMatch = ($bestMatch && $bestMatch['score'] >= 38.0);

echo json_encode([
    'success' => true,
    'match_found' => ($isHighMatch || $isPossibleMatch),
    'confidence_level' => $isHighMatch ? 'high' : ($isPossibleMatch ? 'medium' : 'low'),
    'best_match' => $bestMatch,
    'highest_score' => $bestMatch ? $bestMatch['score'] : 0,
    'top_candidates' => array_slice($results, 0, 4)
]);
exit;
