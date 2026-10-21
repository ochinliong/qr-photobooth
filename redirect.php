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

if (!empty($year) && !empty($search_slug)) {
    // Cari di database yang tahunnya cocok
    $stmt = $pdo->prepare("SELECT * FROM clients WHERE YEAR(tanggal_event) = ?");
    $stmt->execute([$year]);
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($clients as $c) {
        $client_clean_name = strtolower(preg_replace('/\s+/', '', $c['nama_client']));
        // Cocokkan nama tanpa spasi atau cocokkan dengan kolom slug
        if ($client_clean_name === $search_slug || strtolower($c['slug']) === $search_slug) {
            $target_url = $c['folder_foto_path'];
            header("Location: " . $target_url, true, 302);
            exit;
        }
    }

    // Fallback: jika ada perbedaan tahun, cek seluruh data berdasarkan nama
    $stmt_all = $pdo->query("SELECT * FROM clients ORDER BY id DESC");
    foreach ($stmt_all->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $client_clean_name = strtolower(preg_replace('/\s+/', '', $c['nama_client']));
        if ($client_clean_name === $search_slug || strtolower($c['slug']) === $search_slug) {
            header("Location: " . $c['folder_foto_path'], true, 302);
            exit;
        }
    }
}

// Jika tidak ditemukan, arahkan kembali ke halaman utama
header("Location: " . $base_url);
exit;
?>
