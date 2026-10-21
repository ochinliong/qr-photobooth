<?php
// Halaman Admin Galeri sudah disatukan ke dalam Dashboard Admin Utama (/admin/gallery.php)
// agar seluruh pengelolaan Klien, Link QR, serta Hapus/Sembunyikan Foto terorganisir di satu tempat.
$client_param = isset($_GET['client']) ? '?client=' . rawurlencode($_GET['client']) : '';
header("Location: ../admin/gallery.php" . $client_param, true, 302);
exit;
?>
