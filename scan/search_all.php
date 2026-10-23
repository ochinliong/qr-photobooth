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
// FUNGSI-FUNGSI SIGNATURE & MULTI-CROP ENGINE
// ============================================================================

function computeDHash($srcImg) {
    $w = imagesx($srcImg);
    $h = imagesy($srcImg);
    $target = imagecreatetruecolor(9, 8);
    imagecopyresampled($target, $srcImg, 0, 0, 0, 0, 9, 8, $w, $h);

    $hash = '';
    for ($y = 0; $y < 8; $y++) {
        for ($x = 0; $x < 8; $x++) {
            $rgbLeft = imagecolorat($target, $x, $y);
            $rL = ($rgbLeft >> 16) & 0xFF;
            $gL = ($rgbLeft >> 8) & 0xFF;
            $bL = $rgbLeft & 0xFF;
            $lumLeft = ($rL * 299 + $gL * 587 + $bL * 114) / 1000;

            $rgbRight = imagecolorat($target, $x + 1, $y);
            $rR = ($rgbRight >> 16) & 0xFF;
            $gR = ($rgbRight >> 8) & 0xFF;
            $bR = $rgbRight & 0xFF;
            $lumRight = ($rR * 299 + $gR * 587 + $bR * 114) / 1000;

            $hash .= ($lumLeft > $lumRight) ? '1' : '0';
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

function computeGridSignature($srcImg, $cols = 4, $rows = 8) {
    $w = imagesx($srcImg);
    $h = imagesy($srcImg);
    $blockW = max(1, (int)($w / $cols));
    $blockH = max(1, (int)($h / $rows));

    $grid = [];
    for ($r = 0; $r < $rows; $r++) {
        for ($c = 0; $c < $cols; $c++) {
            $x = (int)($c * $blockW);
            $y = (int)($r * $blockH);
            $bw = ($c === $cols - 1) ? ($w - $x) : $blockW;
            $bh = ($r === $rows - 1) ? ($h - $y) : $blockH;

            $sampleW = 4;
            $sampleH = 4;
            $thumb = imagecreatetruecolor($sampleW, $sampleH);
            imagecopyresampled($thumb, $srcImg, 0, 0, $x, $y, $sampleW, $sampleH, $bw, $bh);

            $rSum = 0; $gSum = 0; $bSum = 0;
            for ($sy = 0; $sy < $sampleH; $sy++) {
                for ($sx = 0; $sx < $sampleW; $sx++) {
                    $rgb = imagecolorat($thumb, $sx, $sy);
                    $rSum += ($rgb >> 16) & 0xFF;
                    $gSum += ($rgb >> 8) & 0xFF;
                    $bSum += $rgb & 0xFF;
                }
            }
            imagedestroy($thumb);

            $pixelCount = $sampleW * $sampleH;
            $rAvg = (int)($rSum / $pixelCount);
            $gAvg = (int)($gSum / $pixelCount);
            $bAvg = (int)($bSum / $pixelCount);
            $lum = (int)(($rAvg * 299 + $gAvg * 587 + $bAvg * 114) / 1000);

            $grid[] = [$rAvg, $gAvg, $bAvg, $lum];
        }
    }
    return $grid;
}

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

        // 3. Center Inner 80%
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

        // Rotasi 90 derajat jika strip difoto mendatar
        $rCW = imagerotate($img, -90, 0);
        if ($rCW) {
            $images[] = $rCW;
            $rH = imagesy($rCW);
            $rW = imagesx($rCW);
            $stripW = (int)($rH / 2.9);
            if ($stripW < $rW && $stripW > 40) {
                $cStrip = imagecrop($rCW, [
                    'x' => (int)(($rW - $stripW) / 2),
                    'y' => 0,
                    'width' => $stripW,
                    'height' => $rH
                ]);
                if ($cStrip) $images[] = $cStrip;
            }
        }
        $rCCW = imagerotate($img, 90, 0);
        if ($rCCW) {
            $images[] = $rCCW;
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
    $cacheFile = $target_dir . '/.search_cache_v2.json';
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
                    'dhash' => computeDHash($im),
                    'grid' => computeGridSignature($im),
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
        $hamming = hammingDistance($qSig['dhash'], $cand['dhash']);
        $dhashScore = max(0, (64 - $hamming) / 64);
        $gridScore = compareGridSignatures($qSig['grid'], $cand['grid']);

        // Bobot: 35% dHash + 65% Grid Color
        $score = round(($dhashScore * 0.35 + $gridScore * 0.65) * 100, 1);
        if ($score > $bestScoreForCand) {
            $bestScoreForCand = $score;
        }
    }

    if (!empty($cand['is_print'])) {
        $bestScoreForCand = min(100, $bestScoreForCand + 3);
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

// Ambang Batas:
// 1. High Confidence: >= 48% (Pasti Cocok)
// 2. Medium Confidence: >= 32% (Kandidat Terbaik)
$isHighMatch = ($bestMatch && $bestMatch['score'] >= 48.0);
$isPossibleMatch = ($bestMatch && $bestMatch['score'] >= 32.0);

// Sertakan file media lengkap HANYA untuk sesi ini (Privasi Terjaga: Tidak bisa lihat galeri orang lain)
if ($bestMatch) {
    $bestMatch['session_files'] = collectSessionMedia(
        $bestMatch['event_dir'],
        $bestMatch['base_id'],
        $app_base_url,
        $bestMatch['event_folder']
    );
    unset($bestMatch['event_dir']);
}

$topCandidates = array_slice($results, 0, 5);
foreach ($topCandidates as &$candItem) {
    $candItem['session_files'] = collectSessionMedia(
        $candItem['event_dir'],
        $candItem['base_id'],
        $app_base_url,
        $candItem['event_folder']
    );
    unset($candItem['event_dir']);
}
unset($candItem);

echo json_encode([
    'success' => true,
    'match_found' => ($isHighMatch || $isPossibleMatch),
    'confidence_level' => $isHighMatch ? 'high' : ($isPossibleMatch ? 'medium' : 'low'),
    'best_match' => $bestMatch,
    'highest_score' => $bestMatch ? $bestMatch['score'] : 0,
    'top_candidates' => $topCandidates,
    'total_events_searched' => count($all_events),
    'total_photos_searched' => $total_photos_count
]);
exit;
