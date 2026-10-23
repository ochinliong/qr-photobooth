<?php
/**
 * ============================================================================
 * YUHU PHOTOBOOTH - GLOBAL VISUAL SEARCH ENGINE (scan.yuhu.co.id)
 * ============================================================================
 * Mencari foto cetakan fisik tamu di SELURUH galeri event yang ada di server
 * YUHU Photobooth (Multi-Event Global Search), tanpa barcode.
 *
 * Menggunakan kombinasi:
 * 1. Multi-Aspect Auto-Crop (Center 1:3 Strip, Center 2:3, Inner 82%, Rotasi 90°)
 * 2. 64-bit Difference Hash (dHash) + 32-Zone Color Luminance Signature
 * 3. Smart Caching (.search_cache_v2.json) per event untuk kecepatan kilat (<50ms)
 * 4. Sinkronisasi penuh dengan database clients & pengaturan On/Off per event.
 * ============================================================================
 */

header('Content-Type: application/json; charset=utf-8');
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
@set_time_limit(120);

require_once __DIR__ . '/../config.php';
if (session_status() === PHP_SESSION_ACTIVE) {
    @session_write_close();
}
define('LUMABOOTH_SKIP_AUTOSYNC', true);
require_once __DIR__ . '/../lumabooth_sync.php';

// Pastikan base_url tersedia
$app_base_url = rtrim($base_url ?? 'https://files.yuhu.co.id', '/');

// 1. Ambil Data Gambar yang Diunggah Tamu
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
    echo json_encode([
        'success' => false,
        'error' => 'Tidak ada foto yang diterima untuk dipindai. Silahkan ambil foto cetakan Anda.'
    ]);
    exit;
}

if (!extension_loaded('gd')) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Ekstensi PHP GD belum aktif di server.'
    ]);
    exit;
}

// 2. Muat Gambar Query Tamu ke GD Resource
$queryImg = @imagecreatefromstring($rawImageData);
if (!$queryImg) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Format foto tidak valid atau rusak. Silahkan coba foto ulang.'
    ]);
    exit;
}

// Tangani orientasi EXIF jika ada
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

        // 3. Center Inner 80%
        $inW = (int)($w * 0.82);
        $inH = (int)($h * 0.82);
        $c3 = @imagecrop($img, [
            'x' => (int)($w * 0.09),
            'y' => (int)($h * 0.09),
            'width' => $inW,
            'height' => $inH
        ]);
        if ($c3) $images[] = $c3;

        // 4. Fokus Foto Atas (Jika pengguna memotret lebih condong ke atas)
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

        // Rotasi 90 derajat jika strip difoto mendatar
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

// 3. Hitung Signature untuk semua variasi foto tamu
$querySignatures = generateQueryVariations($queryImg);
imagedestroy($queryImg);

// ============================================================================
// 4. LOAD SELURUH EVENT & KUMPULKAN SIGNATURE MASTER DARI SEMUA FOLDER
// ============================================================================
$photos_root = __DIR__ . '/../client/photos';
if (!is_dir($photos_root)) {
    echo json_encode([
        'success' => false,
        'match_found' => false,
        'message' => 'Direktori galeri foto belum tersedia di server.'
    ]);
    exit;
}

// Ambil data database klien untuk info acara (judul, jadwal, link, enable_visual_search)
$db_clients_map = [];
try {
    $stmt = $pdo->query("SELECT * FROM clients ORDER BY id DESC");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $c_name = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $c['nama_client']));
        $c_slug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $c['slug']));
        $c_folder = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', extractLumaboothFolderFromUrl($c['folder_foto_path'] ?? '')));

        if ($c_name !== '') $db_clients_map[$c_name] = $c;
        if ($c_slug !== '') $db_clients_map[$c_slug] = $c;
        if ($c_folder !== '') $db_clients_map[$c_folder] = $c;
    }
} catch (Exception $e) {}

$all_events = [];
$dirs = @scandir($photos_root) ?: [];

foreach ($dirs as $d) {
    if ($d === '.' || $d === '..' || !is_dir($photos_root . '/' . $d)) continue;

    $d_clean = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $d));
    $db_info = $db_clients_map[$d_clean] ?? null;

    // Cek apakah visual search diaktifkan untuk event ini
    $is_enabled = true;
    if ($db_info && isset($db_info['enable_visual_search']) && (int)$db_info['enable_visual_search'] === 0) {
        $is_enabled = false;
    }
    $cfg_path = $photos_root . '/' . $d . '/config.json';
    if (file_exists($cfg_path)) {
        $cfg = @json_decode(@file_get_contents($cfg_path), true);
        if (isset($cfg['enable_visual_search'])) {
            $is_enabled = (bool)$cfg['enable_visual_search'];
        }
    }

    if (!$is_enabled) continue; // Hormati pengaturan OFF

    $event_title = $db_info ? $db_info['nama_client'] : str_replace('_', ' ', $d);
    $event_location = ($db_info && !empty(trim($db_info['lokasi'] ?? '')) && trim($db_info['lokasi']) !== '-') ? trim($db_info['lokasi']) : '';
    $event_date = '';
    if ($db_info && !empty($db_info['tanggal_event']) && $db_info['tanggal_event'] !== '0000-00-00') {
        $tgl_obj = strtotime($db_info['tanggal_event']);
        $bulan_indo = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
        $event_date = date('d', $tgl_obj) . ' ' . $bulan_indo[(int)date('m', $tgl_obj) - 1] . ' ' . date('Y', $tgl_obj);
    }

    // Direct Gallery Link
    $event_url = $app_base_url . '/client/' . rawurlencode($d);
    if ($db_info && !empty($db_info['tanggal_event']) && $db_info['tanggal_event'] !== '0000-00-00') {
        $year = date('Y', strtotime($db_info['tanggal_event']));
        $clean_name = preg_replace('/\s+/', '', $db_info['nama_client']);
        $event_url = $app_base_url . '/' . $year . '/' . $clean_name;
    }

    $all_events[] = [
        'folder' => $d,
        'title' => $event_title,
        'location' => $event_location,
        'date' => $event_date,
        'url' => $event_url,
        'dir_path' => $photos_root . '/' . $d
    ];
}

$all_candidate_signatures = [];
$total_photos_count = 0;

foreach ($all_events as $ev) {
    $target_dir = $ev['dir_path'];
    $cacheFile = $target_dir . '/.search_cache_v4.json';
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

                if (strpos($pathLower, 'singles/') !== false || strpos($pathLower, 'originals/') !== false || strpos($pathLower, 'thumb') !== false) {
                    continue;
                }

                $isPrint = (strpos($pathLower, 'prints/') !== false || strpos($pathLower, 'print') !== false);
                $candidateFiles[] = [
                    'rel_path' => $relPath,
                    'full_path' => $item->getPathname(),
                    'is_print' => $isPrint
                ];
            }
        }
    }

    if (empty($candidateFiles)) continue;

    $eventSignatures = [];
    $needRebuild = true;

    if (file_exists($cacheFile)) {
        $cacheContent = @file_get_contents($cacheFile);
        $cached = $cacheContent ? @json_decode($cacheContent, true) : null;
        if (is_array($cached) && isset($cached['count']) && $cached['count'] === count($candidateFiles)) {
            $eventSignatures = $cached['signatures'] ?? [];
            $needRebuild = false;
        }
    }

    if ($needRebuild || empty($eventSignatures)) {
        $eventSignatures = [];
        foreach ($candidateFiles as $cand) {
            $candBytes = @file_get_contents($cand['full_path']);
            if (!$candBytes) continue;
            $im = @imagecreatefromstring($candBytes);
            if ($im) {
                $eventSignatures[] = [
                    'file' => $cand['rel_path'],
                    'pose_sig' => extractPoseAwareSignature($im),
                    'is_print' => $cand['is_print']
                ];
                imagedestroy($im);
            }
        }
        @file_put_contents($cacheFile, json_encode([
            'count' => count($candidateFiles),
            'timestamp' => time(),
            'signatures' => $eventSignatures
        ]));
    }

    foreach ($eventSignatures as $sig) {
        $total_photos_count++;
        $all_candidate_signatures[] = [
            'event' => $ev,
            'sig' => $sig
        ];
    }
}

if (empty($all_candidate_signatures)) {
    echo json_encode([
        'success' => true,
        'match_found' => false,
        'message' => 'Belum ada foto master cetakan yang terunggah di server YUHU Photobooth.',
        'total_events_searched' => count($all_events),
        'total_photos_searched' => 0
    ]);
    exit;
}

// Helper untuk mengumpulkan seluruh file (Foto Cetak, Video, Foto Satuan) HANYA untuk sesi ini
function collectSessionMedia($dirPath, $baseId, $app_base_url, $folderName) {
    $encodedFolder = rawurlencode($folderName);
    $mediaList = [];
    if (!is_dir($dirPath) || empty($baseId)) return $mediaList;

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dirPath, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($it as $item) {
        if ($item->isFile()) {
            $fname = $item->getFilename();
            if (strpos($fname, $baseId) !== false && strpos($fname, '.part_') === false && strpos($fname, 'thumb') === false) {
                $ext = strtolower($item->getExtension());
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'mp4', 'mov', 'webm', 'gif'])) {
                    $rel = str_replace('\\', '/', substr($item->getPathname(), strlen($dirPath) + 1));
                    $encodedPath = implode('/', array_map('rawurlencode', explode('/', $rel)));
                    $url = $app_base_url . '/client/photos/' . $encodedFolder . '/' . $encodedPath;
                    $isVid = in_array($ext, ['mp4', 'mov', 'webm']);
                    $isPrint = (strpos(strtolower($rel), 'prints/') !== false || strpos(strtolower($rel), 'print') !== false);
                    
                    $label = 'Foto Satuan';
                    if ($isPrint) $label = 'Foto Cetak (Strip)';
                    elseif ($isVid) $label = 'Video Animasi';
                    elseif (strpos(strtolower($rel), 'animated/') !== false) $label = 'Animasi GIF';

                    $mediaList[] = [
                        'folder' => $folderName,
                        'file' => $rel,
                        'name' => $fname,
                        'url' => $url,
                        'is_video' => $isVid,
                        'is_print' => $isPrint,
                        'label' => $label,
                        'ext' => $ext
                    ];
                }
            }
        }
    }

    // Urutkan: Foto Cetak di urutan pertama, Video kedua, Foto Satuan berikutnya
    usort($mediaList, function($a, $b) {
        $priorityA = $a['is_print'] ? 1 : ($a['is_video'] ? 2 : 3);
        $priorityB = $b['is_print'] ? 1 : ($b['is_video'] ? 2 : 3);
        return $priorityA <=> $priorityB;
    });

    return $mediaList;
}

// ============================================================================
// 5. PENCOCOKAN DENGAN SELURUH FOTO DI SERVER (GLOBAL MATCHING)
// ============================================================================
$results = [];

foreach ($all_candidate_signatures as $item) {
    $ev = $item['event'];
    $cand = $item['sig'];
    $bestScoreForCand = 0;

    foreach ($querySignatures as $qSig) {
        $score = comparePoseAwareSignatures($qSig, $cand['pose_sig']);
        if ($score > $bestScoreForCand) {
            $bestScoreForCand = $score;
        }
    }

    if (!empty($cand['is_print'])) {
        $bestScoreForCand = min(100, $bestScoreForCand + 2);
    }

    $encodedClient = rawurlencode($ev['folder']);
    $encodedPath = implode('/', array_map('rawurlencode', explode('/', $cand['file'])));
    $fullPhotoUrl = $app_base_url . '/client/photos/' . $encodedClient . '/' . $encodedPath;

    $fname = basename($cand['file']);
    if (preg_match('/^(\d{8}_\d{6}_\d{3})/', $fname, $m)) {
        $baseId = $m[1];
    } else {
        $baseId = preg_replace('/(_mp4|_gif|\.[a-zA-Z0-9]+)$/i', '', $fname);
    }

    $results[] = [
        'event_title' => $ev['title'],
        'event_folder' => $ev['folder'],
        'event_dir' => $ev['dir_path'],
        'event_location' => $ev['location'],
        'event_date' => $ev['date'],
        'file' => $cand['file'],
        'photo_url' => $fullPhotoUrl,
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

// Sertakan file media lengkap HANYA untuk sesi ini (Eksklusif: Privasi 100% terjaga)
if ($bestMatch && ($isHighMatch || $isPossibleMatch)) {
    $bestMatch['session_files'] = collectSessionMedia(
        $bestMatch['event_dir'],
        $bestMatch['base_id'],
        $app_base_url,
        $bestMatch['event_folder']
    );
    unset($bestMatch['event_dir']);
} else {
    $bestMatch = null;
}

echo json_encode([
    'success' => true,
    'match_found' => ($bestMatch !== null),
    'confidence_level' => $isHighMatch ? 'high' : ($isPossibleMatch ? 'medium' : 'low'),
    'best_match' => $bestMatch,
    'highest_score' => $bestMatch ? $bestMatch['score'] : 0,
    'top_candidates' => [],
    'total_events_searched' => count($all_events),
    'total_photos_searched' => $total_photos_count
]);
exit;
