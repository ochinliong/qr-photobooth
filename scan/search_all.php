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
// FUNGSI-FUNGSI SIGNATURE, PEOPLE, FACE, POSE & LANDSCAPE MATCHING ENGINE (v6)
// ============================================================================

/**
 * Deteksi cerdas batas fisik kertas cetakan dari permukaan meja / background
 */
function findCardBoundingBox($img) {
    $w = imagesx($img);
    $h = imagesy($img);
    if ($w < 60 || $h < 60) return null;

    $sw = 120;
    $sh = max(40, (int)($h * 120 / $w));
    $small = imagecreatetruecolor($sw, $sh);
    imagecopyresampled($small, $img, 0, 0, 0, 0, $sw, $sh, $w, $h);

    // Ambil sampel warna background di 4 sudut luar
    $corners = [
        imagecolorat($small, 2, 2),
        imagecolorat($small, $sw - 3, 2),
        imagecolorat($small, 2, $sh - 3),
        imagecolorat($small, $sw - 3, $sh - 3)
    ];
    $bgR = 0; $bgG = 0; $bgB = 0;
    foreach ($corners as $c) {
        $bgR += ($c >> 16) & 0xFF;
        $bgG += ($c >> 8) & 0xFF;
        $bgB += $c & 0xFF;
    }
    $bgR = (int)($bgR / 4);
    $bgG = (int)($bgG / 4);
    $bgB = (int)($bgB / 4);

    $diffThreshold = 26; // Batas kontras warna kertas vs meja

    // Scan batas Atas
    $top = 0;
    for ($y = 0; $y < (int)($sh * 0.42); $y++) {
        $cnt = 0;
        for ($x = (int)($sw * 0.2); $x < (int)($sw * 0.8); $x++) {
            $rgb = imagecolorat($small, $x, $y);
            $dr = abs((($rgb >> 16) & 0xFF) - $bgR);
            $dg = abs((($rgb >> 8) & 0xFF) - $bgG);
            $db = abs(($rgb & 0xFF) - $bgB);
            if (($dr + $dg + $db) > $diffThreshold * 3) $cnt++;
        }
        if ($cnt > (int)($sw * 0.22)) {
            $top = $y;
            break;
        }
    }

    // Scan batas Bawah
    $bottom = $sh - 1;
    for ($y = $sh - 1; $y > (int)($sh * 0.58); $y--) {
        $cnt = 0;
        for ($x = (int)($sw * 0.2); $x < (int)($sw * 0.8); $x++) {
            $rgb = imagecolorat($small, $x, $y);
            $dr = abs((($rgb >> 16) & 0xFF) - $bgR);
            $dg = abs((($rgb >> 8) & 0xFF) - $bgG);
            $db = abs(($rgb & 0xFF) - $bgB);
            if (($dr + $dg + $db) > $diffThreshold * 3) $cnt++;
        }
        if ($cnt > (int)($sw * 0.22)) {
            $bottom = $y;
            break;
        }
    }

    // Scan batas Kiri
    $left = 0;
    for ($x = 0; $x < (int)($sw * 0.42); $x++) {
        $cnt = 0;
        for ($y = (int)($sh * 0.2); $y < (int)($sh * 0.8); $y++) {
            $rgb = imagecolorat($small, $x, $y);
            $dr = abs((($rgb >> 16) & 0xFF) - $bgR);
            $dg = abs((($rgb >> 8) & 0xFF) - $bgG);
            $db = abs(($rgb & 0xFF) - $bgB);
            if (($dr + $dg + $db) > $diffThreshold * 3) $cnt++;
        }
        if ($cnt > (int)($sh * 0.22)) {
            $left = $x;
            break;
        }
    }

    // Scan batas Kanan
    $right = $sw - 1;
    for ($x = $sw - 1; $x > (int)($sw * 0.58); $x--) {
        $cnt = 0;
        for ($y = (int)($sh * 0.2); $y < (int)($sh * 0.8); $y++) {
            $rgb = imagecolorat($small, $x, $y);
            $dr = abs((($rgb >> 16) & 0xFF) - $bgR);
            $dg = abs((($rgb >> 8) & 0xFF) - $bgG);
            $db = abs(($rgb & 0xFF) - $bgB);
            if (($dr + $dg + $db) > $diffThreshold * 3) $cnt++;
        }
        if ($cnt > (int)($sh * 0.22)) {
            $right = $x;
            break;
        }
    }

    imagedestroy($small);

    $scaleX = $w / $sw;
    $scaleY = $h / $sh;

    $boxX = max(0, (int)($left * $scaleX));
    $boxY = max(0, (int)($top * $scaleY));
    $boxW = min($w - $boxX, (int)(($right - $left + 1) * $scaleX));
    $boxH = min($h - $boxY, (int)(($bottom - $top + 1) * $scaleY));

    // Validasi bounding box rasional
    if ($boxW >= (int)($w * 0.35) && $boxH >= (int)($h * 0.35)) {
        return ['x' => $boxX, 'y' => $boxY, 'width' => $boxW, 'height' => $boxH];
    }
    return null;
}

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

function compareVectorDist($v1, $v2) {
    if (empty($v1) || empty($v2) || count($v1) !== count($v2)) return 999;
    $sum = 0;
    $n = count($v1);
    for ($i = 0; $i < $n; $i++) {
        $sum += abs($v1[$i] - $v2[$i]);
    }
    return $sum / $n;
}

/**
 * Ekstraksi Ciri Orang, Wajah, Pakaian, dan Pose Gestur (Bebas dari Bias Desain Template)
 */
function extractPeopleAndPoseFeatures($imArea) {
    $pw = imagesx($imArea);
    $ph = imagesy($imArea);

    $gw = 64;
    $gh = 48;
    $thumb = imagecreatetruecolor($gw, $gh);
    imagecopyresampled($thumb, $imArea, 0, 0, 0, 0, $gw, $gh, $pw, $ph);

    $faceGrid = array_fill(0, $gh, array_fill(0, $gw, false));
    $colSkinCounts = array_fill(0, 16, 0);
    $faceMaxY = (int)($gh * 0.72); // Area kepala manusia (atas s/d dada)

    // 1. Spatial Color Matrix Pakaian & Tubuh (6x4 = 24 petak warna)
    $colorMap = [];
    $patchW = max(1, (int)($gw / 6));
    $patchH = max(1, (int)($gh / 4));
    for ($py = 0; $py < 4; $py++) {
        for ($px = 0; $px < 6; $px++) {
            $sumR = 0; $sumG = 0; $sumB = 0; $cnt = 0;
            $startY = $py * $patchH;
            $endY = min($gh, ($py + 1) * $patchH);
            $startX = $px * $patchW;
            $endX = min($gw, ($px + 1) * $patchW);

            for ($yy = $startY; $yy < $endY; $yy++) {
                for ($xx = $startX; $xx < $endX; $xx++) {
                    $rgb = imagecolorat($thumb, $xx, $yy);
                    $sumR += ($rgb >> 16) & 0xFF;
                    $sumG += ($rgb >> 8) & 0xFF;
                    $sumB += $rgb & 0xFF;
                    $cnt++;
                }
            }
            $colorMap[] = [
                ($cnt > 0) ? (int)($sumR / $cnt) : 128,
                ($cnt > 0) ? (int)($sumG / $cnt) : 128,
                ($cnt > 0) ? (int)($sumB / $cnt) : 128
            ];
        }
    }

    // 2. Segmentasi Kulit Manusia & Penandaan Wajah
    $totalSkin = 0;
    for ($y = 0; $y < $gh; $y++) {
        for ($x = 0; $x < $gw; $x++) {
            $rgb = imagecolorat($thumb, $x, $y);
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;

            $cb = (int)(128 - 0.168736 * $r - 0.331264 * $g + 0.5 * $b);
            $cr = (int)(128 + 0.5 * $r - 0.418688 * $g - 0.081312 * $b);
            $sum = $r + $g + $b;

            // Model Kulit Adaptif Multietnis (Akurat membedakan kulit orang dari warna kayu meja / baju)
            $isSkin = ($cb >= 75 && $cb <= 130 && $cr >= 130 && $cr <= 180 
                       && $r > 55 && $g > 35 && $b > 20 
                       && $r > $g && $g > $b && ($r - $g >= 5) && ($r - $b >= 10)
                       && ($sum > 0 && ($r / $sum) > 0.35 && ($b / $sum) < 0.33));

            if ($isSkin) {
                $totalSkin++;
                $colIdx = min(15, (int)($x / 4));
                $colSkinCounts[$colIdx]++;
                if ($y < $faceMaxY) {
                    $faceGrid[$y][$x] = true;
                }
            }
        }
    }
    imagedestroy($thumb);

    // 3. BFS Connected Components untuk Menemukan Kluster Kepala / Wajah Orang
    $visited = array_fill(0, $gh, array_fill(0, $gw, false));
    $faces = [];
    $dirs = [[-1, 0], [1, 0], [0, -1], [0, 1], [-1, -1], [1, 1], [-1, 1], [1, -1]];

    for ($y = 0; $y < $gh; $y++) {
        for ($x = 0; $x < $gw; $x++) {
            if ($faceGrid[$y][$x] && !$visited[$y][$x]) {
                $blob = [];
                $q = [[$x, $y]];
                $visited[$y][$x] = true;
                $idx = 0;
                while ($idx < count($q)) {
                    $c = $q[$idx++];
                    $blob[] = $c;
                    foreach ($dirs as $d) {
                        $nx = $c[0] + $d[0];
                        $ny = $c[1] + $d[1];
                        if ($nx >= 0 && $nx < $gw && $ny >= 0 && $ny < $gh) {
                            if ($faceGrid[$ny][$nx] && !$visited[$ny][$nx]) {
                                $visited[$ny][$nx] = true;
                                $q[] = [$nx, $ny];
                            }
                        }
                    }
                }
                // Ambang batas ukuran kepala (minimal 8 piksel di thumbnail agar menolak noise titik/kain)
                if (count($blob) >= 8) {
                    $sx = 0; $sy = 0;
                    foreach ($blob as $p) { $sx += $p[0]; $sy += $p[1]; }
                    $faces[] = [
                        'x' => round(($sx / count($blob)) / $gw, 3),
                        'y' => round(($sy / count($blob)) / $gh, 3),
                        'size' => count($blob)
                    ];
                }
            }
        }
    }

    // Urutkan posisi wajah dari kiri ke kanan
    usort($faces, function($a, $b) { return $a['x'] <=> $b['x']; });

    // 4. Profil Distribusi Siluet Tubuh Manusia (Horizontal 16 bin)
    $maxCol = max(1, max($colSkinCounts));
    $normProfile = [];
    foreach ($colSkinCounts as $c) { $normProfile[] = round($c / $maxCol, 2); }

    // 5. Matriks Gradien Kontur Pose, Gestur Tangan & Bahu (16x16)
    $lum16 = extractLuminanceMatrix($imArea, 16, 16);
    $edge16 = extractGradientMap($lum16, 16, 16);

    return [
        'face_count' => count($faces),
        'faces'      => $faces,
        'profile'    => $normProfile,
        'color_map'  => $colorMap,
        'lum16'      => $lum16,
        'edge16'     => $edge16,
        'skin_ratio' => round($totalSkin / ($gw * $gh), 3)
    ];
}

/**
 * Ekstraksi Signature Master & Query:
 * Mengidentifikasi orientasi (Landscape vs Strip) dan membuang footer tulisan acara & border frame.
 */
function extractPoseAwareSignature($img) {
    $w = imagesx($img);
    $h = imagesy($img);
    $aspect = $w / $h;

    $isLandscape = ($aspect >= 1.25);
    $isStrip = ($h / $w >= 1.6);

    // Potong Area Foto Orang (Keluarkan frame border dan teks footer event)
    if ($isLandscape) {
        // Cetakan Horizontal 4R / 6x4 (Postcard): buang border 4% dan buang footer teks bawah 20%
        $inX = (int)($w * 0.03);
        $inW = max(20, (int)($w * 0.94));
        $inY = (int)($h * 0.03);
        $inH = max(20, (int)($h * 0.79));
    } elseif ($isStrip) {
        // Strip Photobooth Vertikal: buang border 5% dan buang footer teks bawah 26%
        $inX = (int)($w * 0.05);
        $inW = max(20, (int)($w * 0.90));
        $inY = (int)($h * 0.04);
        $inH = max(20, (int)($h * 0.72));
    } else {
        // Pasfoto / Postcard Vertikal
        $inX = (int)($w * 0.04);
        $inW = max(20, (int)($w * 0.92));
        $inY = (int)($h * 0.04);
        $inH = max(20, (int)($h * 0.80));
    }

    $photoCrop = @imagecrop($img, ['x' => $inX, 'y' => $inY, 'width' => $inW, 'height' => $inH]);
    if (!$photoCrop) {
        $photoCrop = $img;
    }

    // Ekstraksi fitur utama orang di area foto
    $mainFeat = extractPeopleAndPoseFeatures($photoCrop);

    // Sub-slot untuk variasi pose ganda
    $slot1Feat = null;
    $slot2Feat = null;

    if ($isStrip) {
        // Strip Vertikal memiliki 2 atau 3 slot pose berurutan atas-bawah
        $cw = imagesx($photoCrop);
        $ch = imagesy($photoCrop);
        $s1H = (int)($ch * 0.50);
        $s1 = @imagecrop($photoCrop, ['x' => 0, 'y' => 0, 'width' => $cw, 'height' => $s1H]);
        if ($s1) {
            $slot1Feat = extractPeopleAndPoseFeatures($s1);
            imagedestroy($s1);
        }
        $s2H = (int)($ch * 0.50);
        $s2 = @imagecrop($photoCrop, ['x' => 0, 'y' => (int)($ch * 0.50), 'width' => $cw, 'height' => $s2H]);
        if ($s2) {
            $slot2Feat = extractPeopleAndPoseFeatures($s2);
            imagedestroy($s2);
        }
    } elseif ($isLandscape) {
        // Cetakan Landscape: bisa berupa 1 foto lebar atau 2 foto berdampingan kiri-kanan
        $cw = imagesx($photoCrop);
        $ch = imagesy($photoCrop);
        $s1W = (int)($cw * 0.52);
        $s1 = @imagecrop($photoCrop, ['x' => 0, 'y' => 0, 'width' => $s1W, 'height' => $ch]);
        if ($s1) {
            $slot1Feat = extractPeopleAndPoseFeatures($s1);
            imagedestroy($s1);
        }
        $s2W = (int)($cw * 0.52);
        $s2 = @imagecrop($photoCrop, ['x' => (int)($cw * 0.48), 'y' => 0, 'width' => $s2W, 'height' => $ch]);
        if ($s2) {
            $slot2Feat = extractPeopleAndPoseFeatures($s2);
            imagedestroy($s2);
        }
    }

    if ($photoCrop !== $img) {
        imagedestroy($photoCrop);
    }

    return [
        'is_landscape' => $isLandscape,
        'is_strip'     => $isStrip,
        'aspect_ratio' => round($aspect, 2),
        'main'         => $mainFeat,
        'slot1'        => $slot1Feat,
        'slot2'        => $slot2Feat
    ];
}

/**
 * Pembanding Signature Cerdas:
 * Menilai orang, wajah, warna pakaian, dan pose secara teliti.
 * Desain template/background dekorasi diberi bobot 0% agar foto berbeda tidak tertukar.
 */
function comparePoseAwareSignatures($qSig, $cSig) {
    if (empty($qSig) || empty($cSig)) return 0;

    // 1. Validasi Kecocokan Orientasi (Landscape vs Strip)
    $qLand = !empty($qSig['is_landscape']);
    $cLand = !empty($cSig['is_landscape']);
    if ($qLand !== $cLand) {
        return 0; // Cetakan landscape tidak boleh dicocokkan dengan strip vertikal
    }

    $qM = $qSig['main'];
    $cM = $cSig['main'];

    // 2. Kompatibilitas Jumlah Orang & Wajah (Face Count & Head Positions)
    $qFaces = $qM['face_count'] ?? 0;
    $cFaces = $cM['face_count'] ?? 0;
    $faceDiff = abs($qFaces - $cFaces);

    if ($qFaces === 0 && $cFaces === 0) {
        $faceCompat = 0.85; // Tidak ada wajah terdeteksi (silhouette / jarak jauh)
    } elseif ($faceDiff === 0) {
        $faceCompat = 1.0;  // Jumlah orang identik!
    } elseif ($faceDiff === 1) {
        $faceCompat = 0.78; // Beda 1 orang (mungkin 1 kepala sedikit tertutup)
    } elseif ($faceDiff === 2) {
        $faceCompat = 0.45;
    } else {
        $faceCompat = 0.15; // Beda banyak orang -> tolak keras!
    }

    // Cocokkan juga posisi horizontal kepala jika ada
    if ($qFaces > 0 && $cFaces > 0 && !empty($qM['faces']) && !empty($cM['faces'])) {
        $posDiffSum = 0;
        $minCount = min($qFaces, $cFaces);
        for ($i = 0; $i < $minCount; $i++) {
            $posDiffSum += abs(($qM['faces'][$i]['x'] ?? 0.5) - ($cM['faces'][$i]['x'] ?? 0.5));
        }
        $avgPosDiff = $posDiffSum / $minCount;
        $posCompat = max(0.0, 1.0 - ($avgPosDiff * 2.0));
        $faceCompat = ($faceCompat * 0.75) + ($posCompat * 0.25);
    }

    // 3. Kemiripan Warna Pakaian & Orang (24 Patches Spatial Color Map)
    $diffColor = 0;
    $nPatches = count($qM['color_map']);
    for ($i = 0; $i < $nPatches; $i++) {
        $rD = abs(($qM['color_map'][$i][0] ?? 128) - ($cM['color_map'][$i][0] ?? 128));
        $gD = abs(($qM['color_map'][$i][1] ?? 128) - ($cM['color_map'][$i][1] ?? 128));
        $bD = abs(($qM['color_map'][$i][2] ?? 128) - ($cM['color_map'][$i][2] ?? 128));
        $diffColor += ($rD + $gD + $bD) / 3.0;
    }
    $avgColorDiff = $diffColor / max(1, $nPatches);
    $colorScore = max(0.0, 100.0 - $avgColorDiff * 1.6);

    // 4. Kemiripan Pose, Gestur & Kontur Badan (Edge Gradient Map)
    $diffGrad = 0;
    for ($i = 0; $i < 256; $i++) {
        $diffGrad += abs(($qM['edge16'][$i] ?? 0) - ($cM['edge16'][$i] ?? 0));
    }
    $avgGradDiff = $diffGrad / 256.0;
    $poseScore = max(0.0, 100.0 - $avgGradDiff * 2.4);

    // 5. Kemiripan Siluet Distribusi Orang (16 Columns)
    $diffProf = compareVectorDist($qM['profile'] ?? [], $cM['profile'] ?? []);
    $silScore = max(0.0, 100.0 - ($diffProf * 90.0));

    // Skor Dasar Komponen Orang & Pose:
    // Pose & Gestur: 40%
    // Warna Pakaian & Orang: 35%
    // Siluet & Posisi Orang: 25%
    $baseScore = ($poseScore * 0.40) + ($colorScore * 0.35) + ($silScore * 0.25);

    // Pengaruh Sub-Slot Jika Tersedia (Top/Bottom atau Left/Right)
    if (!empty($qSig['slot1']) && !empty($cSig['slot1']) && !empty($qSig['slot2']) && !empty($cSig['slot2'])) {
        $s1Grad = compareVectorDist($qSig['slot1']['edge16'] ?? [], $cSig['slot1']['edge16'] ?? []);
        $s2Grad = compareVectorDist($qSig['slot2']['edge16'] ?? [], $cSig['slot2']['edge16'] ?? []);
        $subSlotScore = max(0.0, 100.0 - (($s1Grad + $s2Grad) / 2.0) * 2.4);
        $baseScore = ($baseScore * 0.70) + ($subSlotScore * 0.30);
    }

    // Penalti Ketat jika Struktur Jumlah Orang / Wajah Berbeda
    if ($faceCompat < 0.65) {
        $baseScore *= ($faceCompat / 0.65);
    }

    return round($baseScore, 1);
}

/**
 * Menghasilkan Berbagai Variasi Pemotongan Gambar Query
 * Menangani foto cetakan Landscape dan Strip dalam semua orientasi (Tegak, Mendatar, Miring)
 */
function generateQueryVariations($img) {
    $w = imagesx($img);
    $h = imagesy($img);
    $images = [$img];

    // 1. Deteksi Batas Otomatis Kertas Cetakan (Bounding Box)
    $box = findCardBoundingBox($img);
    if ($box) {
        $cBox = @imagecrop($img, $box);
        if ($cBox) $images[] = $cBox;
    }

    if ($h > $w) {
        // HP dipegang TEGAK (Portrait)
        // A. Jika memotret cetakan LANDSCAPE (Kartu horizontal berada di tengah layar HP)
        $cardH32 = (int)($w / 1.5); // Rasio 3:2 landscape
        $cardY32 = (int)(($h - $cardH32) / 2);
        if ($cardH32 < $h && $cardH32 > 50) {
            $cL32 = @imagecrop($img, ['x' => 0, 'y' => $cardY32, 'width' => $w, 'height' => $cardH32]);
            if ($cL32) $images[] = $cL32;
        }

        $cardH43 = (int)($w / 1.33); // Rasio 4:3 landscape
        $cardY43 = (int)(($h - $cardH43) / 2);
        if ($cardH43 < $h && $cardH43 > 50) {
            $cL43 = @imagecrop($img, ['x' => 0, 'y' => $cardY43, 'width' => $w, 'height' => $cardH43]);
            if ($cL43) $images[] = $cL43;
        }

        // B. Jika memotret STRIP Vertikal (1:3 dan 1:2)
        $stripW = (int)($h / 2.9);
        if ($stripW < $w && $stripW > 40) {
            $cStrip = @imagecrop($img, ['x' => (int)(($w - $stripW) / 2), 'y' => 0, 'width' => $stripW, 'height' => $h]);
            if ($cStrip) $images[] = $cStrip;
        }

        $stripW2 = (int)($h / 2.0);
        if ($stripW2 < $w && $stripW2 > 40) {
            $cStrip2 = @imagecrop($img, ['x' => (int)(($w - $stripW2) / 2), 'y' => 0, 'width' => $stripW2, 'height' => $h]);
            if ($cStrip2) $images[] = $cStrip2;
        }

        // C. Inner 85%
        $inW = (int)($w * 0.85);
        $inH = (int)($h * 0.85);
        $cInner = @imagecrop($img, ['x' => (int)($w * 0.075), 'y' => (int)($h * 0.075), 'width' => $inW, 'height' => $inH]);
        if ($cInner) $images[] = $cInner;

        // D. Rotasi 90 derajat jika cetakan dipegang miring / terbalik
        $rCW = @imagerotate($img, -90, 0);
        if ($rCW) {
            $images[] = $rCW;
            $boxRot = findCardBoundingBox($rCW);
            if ($boxRot) {
                $cRotBox = @imagecrop($rCW, $boxRot);
                if ($cRotBox) $images[] = $cRotBox;
            }
        }
        $rCCW = @imagerotate($img, 90, 0);
        if ($rCCW) $images[] = $rCCW;

    } else {
        // HP dipegang MENDATAR (Landscape)
        // A. Cetakan Landscape
        $cardW32 = (int)($h * 1.5);
        if ($cardW32 < $w && $cardW32 > 50) {
            $cL32 = @imagecrop($img, ['x' => (int)(($w - $cardW32) / 2), 'y' => 0, 'width' => $cardW32, 'height' => $h]);
            if ($cL32) $images[] = $cL32;
        }

        $cardW43 = (int)($h * 1.33);
        if ($cardW43 < $w && $cardW43 > 50) {
            $cL43 = @imagecrop($img, ['x' => (int)(($w - $cardW43) / 2), 'y' => 0, 'width' => $cardW43, 'height' => $h]);
            if ($cL43) $images[] = $cL43;
        }

        $cInner = @imagecrop($img, ['x' => (int)($w * 0.075), 'y' => (int)($h * 0.075), 'width' => (int)($w * 0.85), 'height' => (int)($h * 0.85)]);
        if ($cInner) $images[] = $cInner;

        // B. Rotasi jika strip vertikal difoto mendatar
        $rCW = @imagerotate($img, -90, 0);
        if ($rCW) {
            $images[] = $rCW;
            $rH = imagesy($rCW);
            $rW = imagesx($rCW);
            $stripW = (int)($rH / 2.9);
            if ($stripW < $rW && $stripW > 40) {
                $cStrip = @imagecrop($rCW, ['x' => (int)(($rW - $stripW) / 2), 'y' => 0, 'width' => $stripW, 'height' => $rH]);
                if ($cStrip) $images[] = $cStrip;
            }
        }
        $rCCW = @imagerotate($img, 90, 0);
        if ($rCCW) $images[] = $rCCW;
    }

    $signatures = [];
    foreach ($images as $im) {
        if ($im) {
            $signatures[] = extractPoseAwareSignature($im);
            if ($im !== $img) {
                imagedestroy($im);
            }
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

// Cek apakah pencarian dibatasi ke event tertentu (Scoped Event Search - Lebih Cepat & Privat)
$scoped_param = trim($_POST['event'] ?? ($_GET['event'] ?? ($_POST['client'] ?? ($_GET['client'] ?? ''))));
$scoped_param = str_replace(['..', '/', '\\', "\0"], '', $scoped_param);
$scoped_clean = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $scoped_param));

$all_events = [];
$matched_scoped_dir = null;

if ($scoped_clean !== '') {
    // 1. Cek langsung nama folder asli di filesystem
    if (is_dir($photos_root . '/' . $scoped_param)) {
        $matched_scoped_dir = $scoped_param;
    } else {
        // 2. Cocokkan dengan database clients
        $db_match = $db_clients_map[$scoped_clean] ?? null;
        $candidates = [$scoped_clean];
        if ($db_match) {
            $candidates[] = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $db_match['nama_client']));
            $f_hint = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', extractLumaboothFolderFromUrl($db_match['folder_foto_path'] ?? '')));
            if ($f_hint !== '') $candidates[] = $f_hint;
        }

        // 3. Scan direktori photos untuk mencari folder yang cocok
        $all_dirs = @scandir($photos_root) ?: [];
        foreach ($all_dirs as $d) {
            if ($d === '.' || $d === '..' || !is_dir($photos_root . '/' . $d)) continue;
            $d_c = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $d));
            if (in_array($d_c, $candidates, true)) {
                $matched_scoped_dir = $d;
                break;
            }
        }
    }

    if (!$matched_scoped_dir || !is_dir($photos_root . '/' . $matched_scoped_dir)) {
        echo json_encode([
            'success' => false,
            'match_found' => false,
            'message' => 'Folder galeri acara tidak ditemukan di server.'
        ]);
        exit;
    }

    $d = $matched_scoped_dir;
    $d_clean = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $d));
    $db_info = $db_clients_map[$d_clean] ?? null;

    $is_enabled = true;
    if ($db_info && isset($db_info['enable_visual_search']) && (int)$db_info['enable_visual_search'] === 0) {
        $is_enabled = false;
    }
    $cfg_path = $photos_root . '/' . $d . '/config.json';
    $cfg = [];
    if (file_exists($cfg_path)) {
        $cfg = @json_decode(@file_get_contents($cfg_path), true) ?: [];
        if (isset($cfg['enable_visual_search'])) {
            $is_enabled = (bool)$cfg['enable_visual_search'];
        }
    }

    if (!$is_enabled) {
        echo json_encode([
            'success' => false,
            'match_found' => false,
            'message' => 'Fitur pencarian foto cetak dinonaktifkan untuk acara ini.'
        ]);
        exit;
    }

    $event_title = $db_info ? $db_info['nama_client'] : str_replace('_', ' ', $d);
    if (!empty($cfg['title'])) {
        $event_title = $cfg['title'];
    }
    $event_location = ($db_info && !empty(trim($db_info['lokasi'] ?? '')) && trim($db_info['lokasi']) !== '-') ? trim($db_info['lokasi']) : '';
    $event_date = '';
    if ($db_info && !empty($db_info['tanggal_event']) && $db_info['tanggal_event'] !== '0000-00-00') {
        $tgl_obj = strtotime($db_info['tanggal_event']);
        $bulan_indo = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
        $event_date = date('d', $tgl_obj) . ' ' . $bulan_indo[(int)date('m', $tgl_obj) - 1] . ' ' . date('Y', $tgl_obj);
    }

    $event_url = $app_base_url . '/client/' . rawurlencode($d);

    $all_events[] = [
        'folder' => $d,
        'title' => $event_title,
        'location' => $event_location,
        'date' => $event_date,
        'url' => $event_url,
        'dir_path' => $photos_root . '/' . $d
    ];
} else {
    // Mode Global: Scan semua event yang diaktifkan
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
        $cfg = [];
        if (file_exists($cfg_path)) {
            $cfg = @json_decode(@file_get_contents($cfg_path), true) ?: [];
            if (isset($cfg['enable_visual_search'])) {
                $is_enabled = (bool)$cfg['enable_visual_search'];
            }
        }

        if (!$is_enabled) continue; // Hormati pengaturan OFF

        $event_title = $db_info ? $db_info['nama_client'] : str_replace('_', ' ', $d);
        if (!empty($cfg['title'])) {
            $event_title = $cfg['title'];
        }
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
}

$all_candidate_signatures = [];
$total_photos_count = 0;

foreach ($all_events as $ev) {
    $target_dir = $ev['dir_path'];
    $cacheFile = $target_dir . '/.search_cache_v6.json';
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
    $noPhotosMsg = ($matched_scoped_dir !== null && !empty($all_events[0]['title']))
        ? ('Belum ada foto master cetakan yang terunggah untuk acara ' . $all_events[0]['title'] . '.')
        : 'Belum ada foto master cetakan yang terunggah di server YUHU Photobooth.';

    echo json_encode([
        'success' => true,
        'match_found' => false,
        'message' => $noPhotosMsg,
        'scoped_event' => $matched_scoped_dir,
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
                $rel_lower = strtolower($item->getPathname());

                // Di folder Animated, abaikan thumbnail JPG pendamping MP4 LumaBooth
                if (strpos($rel_lower, 'animated') !== false && in_array($ext, ['jpg', 'jpeg', 'png'])) {
                    continue;
                }

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

    // Jika sesi memiliki video MP4/MOV/WebM asli, singkirkan GIF agar kualitas tidak pecah
    $hasHdVideo = false;
    foreach ($mediaList as $m) {
        if (!empty($m['is_video'])) {
            $hasHdVideo = true;
            break;
        }
    }
    if ($hasHdVideo) {
        $mediaList = array_values(array_filter($mediaList, function($m) {
            return ($m['ext'] ?? '') !== 'gif';
        }));
    }

    // Deduplikasi cetakan ganda (_1x vs _2x) dalam sesi yang sama jika keduanya ada
    $has1x = false;
    foreach ($mediaList as $m) {
        if (preg_match('/_1x\.(jpg|jpeg|png)$/i', $m['name'])) {
            $has1x = true;
            break;
        }
    }
    if ($has1x) {
        $mediaList = array_values(array_filter($mediaList, function($m) {
            return !preg_match('/_2x\.(jpg|jpeg|png)$/i', $m['name']);
        }));
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
// 5. PENCOCOKAN DENGAN FOTO (MATCHING ENGINE)
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

// Ambang Batas Akurasi:
// Jika scoped per-event: threshold 45.0% (pencarian terarah dalam 1 event khusus)
// Jika global multi-event: threshold 48.0% (mencegah false positive antar-acara)
$minConfidenceScore = ($matched_scoped_dir !== null) ? 45.0 : 48.0;

$isHighMatch = ($bestMatch && $bestMatch['score'] >= 60.0);
$isPossibleMatch = ($bestMatch && $bestMatch['score'] >= $minConfidenceScore);

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
    'scoped_event' => $matched_scoped_dir,
    'total_events_searched' => count($all_events),
    'total_photos_searched' => $total_photos_count
]);
exit;
