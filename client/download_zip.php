<?php
// ============================================================================
// PORTAL DOWNLOAD ALL FILES (.ZIP) KHUSUS CLIENT - ANTI CORRUPT & RESUMABLE
// ============================================================================
@error_reporting(0);
@ini_set('display_errors', '0');
@ini_set('zlib.output_compression', 'Off');
@ini_set('output_buffering', 'Off');
@set_time_limit(0);
@ignore_user_abort(true);

if (session_status() === PHP_SESSION_ACTIVE) {
    @session_write_close();
}

$raw_client = isset($_GET['client']) ? $_GET['client'] : '';
$client_folder = trim(str_replace(['..', '/', '\\', "\0"], '', $raw_client));

$photos_base = __DIR__ . '/photos/';
$resolved_folder = $client_folder;
$target_dir = $photos_base . $resolved_folder;

if ($client_folder === '' || !is_dir($target_dir)) {
    // Coba pencocokan case-insensitive / alphanumeric
    $req_alnum = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $client_folder));
    if ($req_alnum !== '' && is_dir($photos_base)) {
        foreach (scandir($photos_base) as $dir) {
            if ($dir === '.' || $dir === '..') continue;
            if (is_dir($photos_base . $dir)) {
                $dir_alnum = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $dir));
                if ($dir_alnum === $req_alnum) {
                    $resolved_folder = $dir;
                    $target_dir = $photos_base . $dir;
                    break;
                }
            }
        }
    }
}

if ($resolved_folder === '' || !is_dir($target_dir)) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Folder acara tidak ditemukan.']);
    exit;
}

// Baca config.json untuk mengecek daftar file yang di-hide oleh Admin & judul acara
$hidden_files = [];
$event_title = str_replace('_', ' ', $resolved_folder);
$config_path = $target_dir . '/config.json';
if (file_exists($config_path)) {
    $cfg = json_decode(@file_get_contents($config_path), true);
    if (is_array($cfg)) {
        if (!empty($cfg['hidden_files']) && is_array($cfg['hidden_files'])) {
            $hidden_files = $cfg['hidden_files'];
        }
        if (!empty($cfg['title'])) {
            $event_title = $cfg['title'];
        }
    }
}

// Kumpulkan semua file media yang valid di dalam folder acara
$allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'mov', 'webm'];
$media_files = [];
$manifest_parts = [];
$total_raw_bytes = 0;

$target_norm = rtrim(str_replace('\\', '/', realpath($target_dir) ?: $target_dir), '/');

// Cek basenames MP4/GIF di Animated agar tidak menyertakan thumbnail JPG ganda di folder Animated
$animated_video_basenames = [];
$anim_dir = $target_dir . '/Animated';
if (is_dir($anim_dir)) {
    foreach (scandir($anim_dir) as $af) {
        $ext_a = strtolower(pathinfo($af, PATHINFO_EXTENSION));
        if (in_array($ext_a, ['mp4', 'gif', 'mov', 'webm'], true)) {
            $animated_video_basenames[strtolower(pathinfo($af, PATHINFO_FILENAME))] = true;
        }
    }
}

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($target_dir, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if (!$file->isFile()) continue;

    $fname = $file->getFilename();
    if (
        strpos($fname, '.part_') !== false ||
        strpos($fname, '.cache_') === 0 ||
        strpos($fname, '.tmp_zip_') === 0 ||
        $fname === 'config.json' ||
        $fname === '.deleted_by_admin' ||
        substr($fname, -5) === '.lock'
    ) {
        continue;
    }

    $ext = strtolower($file->getExtension());
    if (!in_array($ext, $allowed_ext, true)) continue;

    $full_path = str_replace('\\', '/', $file->getRealPath() ?: $file->getPathname());
    $rel_path = ltrim(substr($full_path, strlen($target_norm)), '/');
    $rel_lower = strtolower($rel_path);

    // Abaikan folder sistem LumaBooth / dslrBooth
    if (
        strpos($rel_lower, 'thumb') !== false ||
        strpos($rel_lower, 'settings') !== false ||
        strpos($rel_lower, 'template') !== false
    ) {
        continue;
    }

    // Abaikan file yang sengaja disembunyikan oleh Admin
    if (in_array($rel_path, $hidden_files, true)) {
        continue;
    }

    // Abaikan JPG thumbnail pendamping di dalam folder Animated jika sudah ada MP4/GIF-nya
    if (strpos($rel_lower, 'animated/') === 0 && in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
        $base_no_ext = strtolower(pathinfo($fname, PATHINFO_FILENAME));
        if (isset($animated_video_basenames[$base_no_ext])) {
            continue;
        }
    }

    $fsize = @filesize($full_path);
    if ($fsize === false || $fsize <= 0) continue;

    $fmtime = @filemtime($full_path) ?: 0;
    $media_files[] = [
        'full_path' => $full_path,
        'zip_path'  => $rel_path,
        'size'      => $fsize,
        'mtime'     => $fmtime
    ];
    $total_raw_bytes += $fsize;
}

// Urutkan berdasarkan nama path di dalam ZIP agar konsisten
usort($media_files, function ($a, $b) {
    return strcmp($a['zip_path'], $b['zip_path']);
});

foreach ($media_files as $mf) {
    $manifest_parts[] = $mf['zip_path'] . ':' . $mf['size'] . ':' . $mf['mtime'];
}

$action = $_GET['action'] ?? 'download';

if (empty($media_files)) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Belum ada file foto/video untuk didownload pada event ini.']);
    exit;
}

// Hash unik dari seluruh daftar file + ukuran + waktu modifikasi
$manifest_hash = substr(md5(implode('|', $manifest_parts)), 0, 16);
$cached_zip_name = '.cache_all_' . $manifest_hash . '.zip';
$cached_zip_path = $target_dir . '/' . $cached_zip_name;

/**
 * Fallback Pure-PHP ZIP Writer (Mode STORE / Tanpa Kompresi Ulang) jika ekstensi ZipArchive tidak aktif
 */
function buildZipStoreFallback($dest_zip_path, $files) {
    $fp = @fopen($dest_zip_path, 'wb');
    if (!$fp) return false;

    $central_dir = '';
    $offset = 0;
    $count = 0;

    foreach ($files as $f) {
        $real = $f['full_path'];
        $name = str_replace('\\', '/', $f['zip_path']);
        if (!is_readable($real)) continue;

        $size = (int)$f['size'];
        $crc_hex = @hash_file('crc32b', $real);
        if ($crc_hex === false) continue;
        $crc = hexdec($crc_hex);

        $mtime = (int)$f['mtime'];
        $dtime = getdate($mtime);
        $dos_date = (($dtime['year'] - 1980) << 9) | ($dtime['mon'] << 5) | $dtime['mday'];
        $dos_time = ($dtime['hours'] << 11) | ($dtime['minutes'] << 5) | ($dtime['seconds'] >> 1);

        $name_len = strlen($name);

        // Local File Header (30 bytes + filename)
        $local_header = pack(
            'VvvvvvVVVvv',
            0x04034b50,
            20,
            0x0800, // UTF-8 filename flag
            0,      // Method 0 = STORE
            $dos_time,
            $dos_date,
            $crc,
            $size,
            $size,
            $name_len,
            0
        ) . $name;

        if (fwrite($fp, $local_header) !== strlen($local_header)) {
            fclose($fp);
            return false;
        }

        // Stream isi file dalam chunk 1MB
        $in = @fopen($real, 'rb');
        if (!$in) {
            fclose($fp);
            return false;
        }
        $written = 0;
        while (!feof($in)) {
            $buf = fread($in, 1048576);
            if ($buf === false) break;
            $w = fwrite($fp, $buf);
            if ($w === false) break;
            $written += $w;
        }
        fclose($in);

        if ($written !== $size) {
            fclose($fp);
            return false;
        }

        // Central Directory File Header (46 bytes + filename)
        $central_dir .= pack(
            'VvvvvvvVVVvvvvvVV',
            0x02014b50,
            20,
            20,
            0x0800,
            0,
            $dos_time,
            $dos_date,
            $crc,
            $size,
            $size,
            $name_len,
            0,
            0,
            0,
            0,
            32,
            $offset
        ) . $name;

        $offset += 30 + $name_len + $size;
        $count++;
    }

    $cd_size = strlen($central_dir);
    fwrite($fp, $central_dir);

    // End of Central Directory Record (22 bytes)
    $eocd = pack(
        'VvvvvVVv',
        0x06054b50,
        0,
        0,
        $count,
        $count,
        $cd_size,
        $offset,
        0
    );
    fwrite($fp, $eocd);
    fflush($fp);
    fclose($fp);

    return $count === count($files) && filesize($dest_zip_path) > 22;
}

/**
 * Buat & Verifikasi File ZIP secara Atomic agar 100% bebas corrupt
 */
function ensureValidEventZip($target_dir, $cached_zip_path, $cached_zip_name, $media_files) {
    // Jika file cache ZIP sudah ada dan valid, langsung gunakan
    if (file_exists($cached_zip_path) && filesize($cached_zip_path) > 100) {
        if (class_exists('ZipArchive')) {
            $zv = new ZipArchive();
            if ($zv->open($cached_zip_path, ZipArchive::CHECKCONS) === true) {
                $num = $zv->numFiles;
                $zv->close();
                if ($num === count($media_files)) {
                    return true;
                }
            }
        } else {
            return true;
        }
    }

    // Gunakan lock per-folder agar jika diklik 2x bersamaan tidak tabrakan saat membuat ZIP
    $lock_file = $target_dir . '/.zip_build.lock';
    $lock_fp = @fopen($lock_file, 'c+');
    if ($lock_fp) {
        @flock($lock_fp, LOCK_EX);
    }

    // Cek lagi setelah mendapat lock (mungkin request sebelumnya sudah selesai membangun ZIP)
    clearstatcache(true, $cached_zip_path);
    if (file_exists($cached_zip_path) && filesize($cached_zip_path) > 100) {
        if ($lock_fp) {
            @flock($lock_fp, LOCK_UN);
            @fclose($lock_fp);
        }
        return true;
    }

    // Bersihkan file cache ZIP versi lama di folder ini agar hemat ruang disk cPanel
    foreach (scandir($target_dir) as $f) {
        if (strpos($f, '.cache_all_') === 0 || strpos($f, '.tmp_zip_') === 0) {
            @unlink($target_dir . '/' . $f);
        }
    }

    $tmp_zip = $target_dir . '/.tmp_zip_' . uniqid('', true) . '.zip';
    $built_ok = false;

    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($tmp_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            $added = 0;
            foreach ($media_files as $idx => $mf) {
                if ($zip->addFile($mf['full_path'], $mf['zip_path'])) {
                    // Gunakan CM_STORE untuk file JPG/PNG/MP4 yang sudah terkompresi agar proses pembuatan ZIP 20x lebih cepat & bebas timeout
                    if (defined('ZipArchive::CM_STORE')) {
                        @$zip->setCompressionIndex($added, ZipArchive::CM_STORE);
                    }
                    $added++;
                }
            }
            $closed_ok = @$zip->close();
            if ($closed_ok && $added === count($media_files) && file_exists($tmp_zip) && filesize($tmp_zip) > 100) {
                // Verifikasi integritas struktur ZIP (CHECKCONS) sebelum disajikan ke Client
                $check = new ZipArchive();
                if ($check->open($tmp_zip, ZipArchive::CHECKCONS) === true && $check->numFiles === count($media_files)) {
                    $check->close();
                    $built_ok = true;
                }
            }
        }
    }

    // Jika ZipArchive gagal atau tidak tersedia, gunakan fallback Pure-PHP STORE
    if (!$built_ok) {
        @unlink($tmp_zip);
        $built_ok = buildZipStoreFallback($tmp_zip, $media_files);
    }

    if ($built_ok && file_exists($tmp_zip)) {
        if (!@rename($tmp_zip, $cached_zip_path)) {
            @copy($tmp_zip, $cached_zip_path);
            @unlink($tmp_zip);
        }
    } else {
        @unlink($tmp_zip);
    }

    if ($lock_fp) {
        @flock($lock_fp, LOCK_UN);
        @fclose($lock_fp);
    }

    clearstatcache(true, $cached_zip_path);
    return file_exists($cached_zip_path) && filesize($cached_zip_path) > 100;
}

// ============================================================================
// MODE 1: PREPARE & VERIFY ZIP VIA AJAX (Memastikan ZIP 100% siap & utuh)
// ============================================================================
if ($action === 'prepare') {
    header('Content-Type: application/json; charset=utf-8');
    $ok = ensureValidEventZip($target_dir, $cached_zip_path, $cached_zip_name, $media_files);
    if (!$ok) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Gagal menyiapkan arsip ZIP di server.']);
        exit;
    }
    $zip_size = filesize($cached_zip_path);
    echo json_encode([
        'ok' => true,
        'file_count' => count($media_files),
        'zip_size' => $zip_size,
        'zip_size_mb' => round($zip_size / (1024 * 1024), 2),
        'download_url' => 'download_zip.php?client=' . rawurlencode($resolved_folder) . '&action=download&h=' . $manifest_hash
    ]);
    exit;
}

// ============================================================================
// MODE 2: STREAM VERIFIED ZIP FILE DENGAN DUKUNGAN HTTP RANGE & EXACT LENGTH
// ============================================================================
if (!ensureValidEventZip($target_dir, $cached_zip_path, $cached_zip_name, $media_files)) {
    http_response_code(500);
    echo "Gagal membuat file ZIP.";
    exit;
}

// Bersihkan semua output buffer agar tidak ada 1 byte pun spasi/karakter yang merusak header ZIP
while (ob_get_level() > 0) {
    @ob_end_clean();
}

$zip_size = filesize($cached_zip_path);
$safe_download_name = preg_replace('/[^a-zA-Z0-9_\-&() ]/', '', $event_title);
$safe_download_name = trim(preg_replace('/\s+/', '_', $safe_download_name), '_');
if ($safe_download_name === '') {
    $safe_download_name = 'Photobooth_Gallery';
}
$download_filename = $safe_download_name . '_All_Files.zip';

$fp = @fopen($cached_zip_path, 'rb');
if (!$fp) {
    http_response_code(500);
    exit;
}

$start = 0;
$end = $zip_size - 1;
$length = $zip_size;

header('Content-Type: application/zip');
header('Content-Transfer-Encoding: binary');
header('Content-Disposition: attachment; filename="' . $download_filename . '"');
header('Accept-Ranges: bytes');
header('Cache-Control: private, no-transform, no-store, must-revalidate');

// Dukungan Resume Download (HTTP 206 Partial Content) jika koneksi HP/WiFi sempat terputus
if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=\h*(\d+)-(\d*)[\D.*]?/i', $_SERVER['HTTP_RANGE'], $matches)) {
    $start = intval($matches[1]);
    if (!empty($matches[2])) {
        $end = intval($matches[2]);
    }
    if ($start > $end || $start >= $zip_size || $end >= $zip_size) {
        header('HTTP/1.1 416 Requested Range Not Satisfiable');
        header("Content-Range: bytes */$zip_size");
        fclose($fp);
        exit;
    }
    $length = $end - $start + 1;
    fseek($fp, $start);
    header('HTTP/1.1 206 Partial Content');
    header("Content-Range: bytes $start-$end/$zip_size");
}

header('Content-Length: ' . $length);

$chunk_size = 1048576; // 1 MB per chunk
$bytes_sent = 0;

while (!feof($fp) && $bytes_sent < $length && !connection_aborted()) {
    $read_len = min($chunk_size, $length - $bytes_sent);
    $buffer = fread($fp, $read_len);
    if ($buffer === false) break;
    echo $buffer;
    flush();
    $bytes_sent += strlen($buffer);
}

fclose($fp);
exit;
