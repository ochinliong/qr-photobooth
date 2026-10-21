<?php
date_default_timezone_set('Asia/Jakarta');
$host = 'localhost';
$db   = 'photobooth_db';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Koneksi gagal: " . $e->getMessage());
}
session_start();
$base_url = 'https://files.yuhu.co.id';
?>