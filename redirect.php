<?php
require_once 'config.php';
require_once __DIR__ . '/lumabooth_sync.php';

// Ambil URI asli untuk menghindari masalah pemotongan karakter '&' pada query string
$request_uri = $_SERVER['REQUEST_URI'] ?? '';
$uri_path = parse_url($request_uri, PHP_URL_PATH);
$uri_path = rawurldecode($uri_path);

// Cari kecocokan pola /TAHUN/NAMA_ACARA atau /TAHUN/NAMA_ACARA/vip dari URL
$is_vip_mode = false;
if (preg_match('#/([0-9]{4})/([^/]+?)/(vip|all|client|master|download)/?$#i', $uri_path, $matches)) {
    $year = $matches[1];
    $custom_slug = $matches[2];
    $is_vip_mode = true;
} elseif (preg_match('#/([0-9]{4})/([^/]+?)/?$#', $uri_path, $matches)) {
    $year = $matches[1];
    $custom_slug = $matches[2];
} else {
    $year = $_GET['year'] ?? '';
    $custom_slug = $_GET['slug'] ?? '';
    if (preg_match('#^(.+?)/(vip|all|client|master|download)/?$#i', $custom_slug, $m)) {
        $custom_slug = $m[1];
        $is_vip_mode = true;
    }
}

// Normalisasi: hapus semua spasi dan ubah ke huruf kecil untuk pencocokan
$search_slug = strtolower(preg_replace('/\s+/', '', $custom_slug));
$search_alnum = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $custom_slug));

// Helper untuk meroute klien ke Galeri Internal (/client/<FolderName>) atau Link Eksternal
function routeToClientDestination($c, $base_url, $is_vip_mode = false) {
    $target = trim($c['folder_foto_path'] ?? '');
    $vip_suffix = $is_vip_mode ? '?vip=1' : '';

    // 1. Cek apakah ada folder di /client/photos/ yang cocok dengan folder_foto_path atau nama_client
    $photos_dir = __DIR__ . '/client/photos';
    $matched_photo_folder = '';
    $folder_from_url = extractLumaboothFolderFromUrl($target);
    $hint_alnum = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $folder_from_url));
    $client_alnum = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $c['nama_client']));

    if (is_dir($photos_dir)) {
        foreach (scandir($photos_dir) as $d) {
            if ($d !== '.' && $d !== '..' && is_dir($photos_dir . '/' . $d)) {
                $d_alnum = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $d));
                if ($d_alnum !== '' && ($d_alnum === $hint_alnum || $d_alnum === $client_alnum)) {
                    $matched_photo_folder = $d;
                    break;
                }
            }
        }
    }

    // 2. Jika target adalah URL pihak ketiga (seperti fotoshare / youtube / drive)
    $is_external_thirdparty = preg_match('#^https?://#i', $target)
        && stripos($target, 'files.yuhu.co.id/client') === false
        && stripos($target, 'qr.yuhu.co.id/client') === false;

    if ($is_external_thirdparty) {
        header("Location: " . $target, true, 302);
        exit;
    }

    // 3. Jika folder ditemukan di /client/photos/ lokal pada files.yuhu.co.id, arahkan ke galeri internal
    if ($matched_photo_folder !== '') {
        $gallery_url = rtrim($base_url, '/') . '/client/' . rawurlencode($matched_photo_folder) . $vip_suffix;
        header("Location: " . $gallery_url, true, 302);
        exit;
    }

    // 4. Jika target sudah berupa URL lengkap (misal acara lama di qr.yuhu.co.id/client/...), arahkan ke sana
    if (preg_match('#^https?://#i', $target)) {
        $sep = (strpos($target, '?') !== false) ? '&' : '?';
        header("Location: " . $target . ($is_vip_mode ? $sep . 'vip=1' : ''), true, 302);
        exit;
    }

    // 5. Fallback ke galeri internal berdasarkan nama folder / nama klien
    $folder_param = $folder_from_url !== '' ? $folder_from_url : str_replace(' ', '_', $c['nama_client']);
    $gallery_url = rtrim($base_url, '/') . '/client/' . rawurlencode($folder_param) . $vip_suffix;
    header("Location: " . $gallery_url, true, 302);
    exit;
}

if (!empty($year) && !empty($search_slug)) {
    // 1. Cari di database yang tahunnya cocok atau tanggal_event masih kosong (project baru LumaBooth)
    $stmt = $pdo->prepare("SELECT * FROM clients WHERE (YEAR(tanggal_event) = ? OR tanggal_event IS NULL OR tanggal_event = '0000-00-00') ORDER BY id DESC");
    $stmt->execute([$year]);
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($clients as $c) {
        $client_clean_name = strtolower(preg_replace('/\s+/', '', $c['nama_client']));
        $client_alnum = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $c['nama_client']));
        $folder_alnum = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', extractLumaboothFolderFromUrl($c['folder_foto_path'] ?? '')));
        if ($client_clean_name === $search_slug || $client_alnum === $search_alnum || ($folder_alnum !== '' && $folder_alnum === $search_alnum) || strtolower($c['slug']) === $search_slug) {
            routeToClientDestination($c, $base_url, $is_vip_mode);
        }
    }

    // 2. Fallback: cek seluruh data di database berdasarkan nama
    $stmt_all = $pdo->query("SELECT * FROM clients ORDER BY id DESC");
    foreach ($stmt_all->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $client_clean_name = strtolower(preg_replace('/\s+/', '', $c['nama_client']));
        $client_alnum = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $c['nama_client']));
        $folder_alnum = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', extractLumaboothFolderFromUrl($c['folder_foto_path'] ?? '')));
        if ($client_clean_name === $search_slug || $client_alnum === $search_alnum || ($folder_alnum !== '' && $folder_alnum === $search_alnum) || strtolower($c['slug']) === $search_slug) {
            routeToClientDestination($c, $base_url, $is_vip_mode);
        }
    }

    // 3. Fallback Langsung ke Folder LumaBooth (/client/photos/<EventName>) jika ada
    $photos_dir = __DIR__ . '/client/photos';
    if (is_dir($photos_dir) && $search_alnum !== '') {
        foreach (scandir($photos_dir) as $d) {
            if ($d !== '.' && $d !== '..' && is_dir($photos_dir . '/' . $d)) {
                if (strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $d)) === $search_alnum) {
                    $vip_suffix = $is_vip_mode ? '?vip=1' : '';
                    header("Location: " . rtrim($base_url, '/') . '/client/' . rawurlencode($d) . $vip_suffix, true, 302);
                    exit;
                }
            }
        }
    }
}

// Jika tidak ditemukan di database maupun folder, arahkan ke /client/{custom_slug}
// agar tamu mendapatkan halaman informasi resmi "Tautan Acara Tidak Terdaftar"
if (!empty($custom_slug)) {
    $vip_suffix = $is_vip_mode ? '?vip=1' : '';
    header("Location: " . rtrim($base_url, '/') . '/client/' . rawurlencode($custom_slug) . $vip_suffix, true, 302);
    exit;
}

// Fallback jika memang tidak ada slug sama sekali
header("Location: " . $base_url);
exit;
?>
