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

// Helper untuk meroute klien ke Galeri Internal (LumaBooth Sync) atau Link Eksternal
function routeToClientDestination($c, $base_url) {
    $target = trim($c['folder_foto_path'] ?? '');
    $local_gallery_dir = __DIR__ . '/uploads/gallery/' . $c['slug'];

    // Cek apakah ada foto yang sudah terupload dari LumaBooth di folder lokal
    $has_local_photos = false;
    if (is_dir($local_gallery_dir)) {
        $files = scandir($local_gallery_dir);
        foreach ($files as $f) {
            if ($f !== '.' && $f !== '..') {
                $has_local_photos = true;
                break;
            }
        }
    }

    // Jika target diarahkan ke galeri lokal (uploads/gallery/... atau client/ atau internal)
    // ATAU jika target bukan URL http(s) eksternal, ATAU jika sudah ada foto LumaBooth di server & bukan link eksternal:
    $is_external_url = preg_match('#^https?://#i', $target) && stripos($target, 'files.yuhu.co.id/client') === false;

    if (!$is_external_url || strpos($target, 'uploads/gallery/') === 0 || $target === 'internal') {
        $gallery_url = rtrim($base_url, '/') . '/client/?c=' . urlencode($c['slug']);
        header("Location: " . $gallery_url, true, 302);
        exit;
    }

    // Jika menggunakan URL eksternal (misal Fotoshare / Google Drive), namun admin belum mengisi dan ada foto lokal
    if (empty($target) && $has_local_photos) {
        $gallery_url = rtrim($base_url, '/') . '/client/?c=' . urlencode($c['slug']);
        header("Location: " . $gallery_url, true, 302);
        exit;
    }

    header("Location: " . $target, true, 302);
    exit;
}

if (!empty($year) && !empty($search_slug)) {
    // Cari di database yang tahunnya cocok
    $stmt = $pdo->prepare("SELECT * FROM clients WHERE YEAR(tanggal_event) = ?");
    $stmt->execute([$year]);
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($clients as $c) {
        $client_clean_name = strtolower(preg_replace('/\s+/', '', $c['nama_client']));
        // Cocokkan nama tanpa spasi atau cocokkan dengan kolom slug
        if ($client_clean_name === $search_slug || strtolower($c['slug']) === $search_slug) {
            routeToClientDestination($c, $base_url);
        }
    }

    // Fallback: jika ada perbedaan tahun, cek seluruh data berdasarkan nama
    $stmt_all = $pdo->query("SELECT * FROM clients ORDER BY id DESC");
    foreach ($stmt_all->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $client_clean_name = strtolower(preg_replace('/\s+/', '', $c['nama_client']));
        if ($client_clean_name === $search_slug || strtolower($c['slug']) === $search_slug) {
            routeToClientDestination($c, $base_url);
        }
    }
}

// Jika tidak ditemukan, arahkan kembali ke halaman utama
header("Location: " . $base_url);
exit;
?>
