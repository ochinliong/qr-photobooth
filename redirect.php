<?php
require_once 'config.php';

// Ambil URI asli untuk menghindari masalah pemotongan karakter '&' pada query string
$request_uri = $_SERVER['REQUEST_URI'] ?? '';
$uri_path = parse_url($request_uri, PHP_URL_PATH);
$uri_path = rawurldecode($uri_path);

// Cari kecocokan pola /TAHUN/NAMA_ACARA dari URL
if (preg_match('#/([0-9]{4})/([^/]+?)/?$#', $uri_path, $matches)) {
    $year = $matches[1];
    $custom_slug = $matches[2];
} else {
    $year = $_GET['year'] ?? '';
    $custom_slug = $_GET['slug'] ?? '';
}

// Normalisasi: hapus semua spasi dan ubah ke huruf kecil untuk pencocokan
$search_slug = strtolower(preg_replace('/\s+/', '', $custom_slug));
$search_alnum = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $custom_slug));

// Helper untuk meroute klien ke Galeri Internal (/client/?client=...) atau Link Eksternal
function routeToClientDestination($c, $base_url) {
    $target = trim($c['folder_foto_path'] ?? '');

    // Cek apakah sudah ada folder acara di /client/photos/ yang cocok dengan nama klien
    $photos_dir = __DIR__ . '/client/photos';
    $matched_photo_folder = '';
    $client_alnum = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $c['nama_client']));
    if (is_dir($photos_dir) && $client_alnum !== '') {
        foreach (scandir($photos_dir) as $d) {
            if ($d !== '.' && $d !== '..' && is_dir($photos_dir . '/' . $d)) {
                if (strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $d)) === $client_alnum) {
                    $matched_photo_folder = $d;
                    break;
                }
            }
        }
    }

    // Jika target diarahkan ke galeri internal (/client/ atau kosong) ATAU ditemukan folder LumaBooth di /client/photos/
    // dan target bukan link pihak ketiga (seperti fotoshare / drive):
    $is_external_thirdparty = preg_match('#^https?://#i', $target)
        && stripos($target, 'files.yuhu.co.id/client') === false
        && stripos($target, 'qr.yuhu.co.id/client') === false;

    if (!$is_external_thirdparty) {
        $folder_param = $matched_photo_folder !== '' ? $matched_photo_folder : $c['nama_client'];
        $gallery_url = rtrim($base_url, '/') . '/client/?client=' . rawurlencode($folder_param);
        header("Location: " . $gallery_url, true, 302);
        exit;
    }

    header("Location: " . $target, true, 302);
    exit;
}

if (!empty($year) && !empty($search_slug)) {
    // 1. Cari di database yang tahunnya cocok
    $stmt = $pdo->prepare("SELECT * FROM clients WHERE YEAR(tanggal_event) = ?");
    $stmt->execute([$year]);
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($clients as $c) {
        $client_clean_name = strtolower(preg_replace('/\s+/', '', $c['nama_client']));
        $client_alnum = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $c['nama_client']));
        if ($client_clean_name === $search_slug || $client_alnum === $search_alnum || strtolower($c['slug']) === $search_slug) {
            routeToClientDestination($c, $base_url);
        }
    }

    // 2. Fallback: cek seluruh data di database berdasarkan nama
    $stmt_all = $pdo->query("SELECT * FROM clients ORDER BY id DESC");
    foreach ($stmt_all->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $client_clean_name = strtolower(preg_replace('/\s+/', '', $c['nama_client']));
        $client_alnum = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $c['nama_client']));
        if ($client_clean_name === $search_slug || $client_alnum === $search_alnum || strtolower($c['slug']) === $search_slug) {
            routeToClientDestination($c, $base_url);
        }
    }

    // 3. Fallback Langsung ke Folder LumaBooth (/client/photos/<EventName>) jika ada
    $photos_dir = __DIR__ . '/client/photos';
    if (is_dir($photos_dir) && $search_alnum !== '') {
        foreach (scandir($photos_dir) as $d) {
            if ($d !== '.' && $d !== '..' && is_dir($photos_dir . '/' . $d)) {
                if (strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $d)) === $search_alnum) {
                    header("Location: " . rtrim($base_url, '/') . '/client/?client=' . rawurlencode($d), true, 302);
                    exit;
                }
            }
        }
    }
}

// Jika tidak ditemukan, arahkan kembali ke halaman utama
header("Location: " . $base_url);
exit;
?>
