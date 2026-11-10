<?php
date_default_timezone_set('Asia/Jakarta');
$host = 'localhost';
$db   = 'photobooth_db';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Auto-migration kolom baru tabel clients (keterangan & lock_after_event)
    try {
        $chkCols = $pdo->query("SHOW COLUMNS FROM clients")->fetchAll(PDO::FETCH_COLUMN);
        if ($chkCols && !in_array('keterangan', $chkCols, true)) {
            $pdo->exec("ALTER TABLE clients ADD COLUMN keterangan TEXT NULL DEFAULT NULL AFTER lokasi");
        }
        if ($chkCols && !in_array('lock_after_event', $chkCols, true)) {
            $pdo->exec("ALTER TABLE clients ADD COLUMN lock_after_event TINYINT(1) NOT NULL DEFAULT 1 AFTER enable_visual_search");
        }
    } catch (Exception $eCol) {}
} catch (PDOException $e) {
    die("Koneksi gagal: " . $e->getMessage());
}
session_start();
$base_url = 'https://files.yuhu.co.id';
?>