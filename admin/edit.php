<?php
require_once '../config.php';
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit;
}

if (!isset($_GET['id'])) {
    header("Location: index.php");
    exit;
}

$id = $_GET['id'];
$stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
$stmt->execute([$id]);
$client = $stmt->fetch();

if (!$client) {
    header("Location: index.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $nama_client = $_POST['nama_client'];
    $lokasi = $_POST['lokasi'];
    $link_galeri = $_POST['folder_foto_path'];
    $tanggal_event = $_POST['tanggal_event'];
    $jam_mulai = $_POST['jam_mulai'];
    $jam_selesai = $_POST['jam_selesai'];

    // Check if link changed to regenerate QR Code
    if ($link_galeri !== $client['folder_foto_path']) {
        $qr_api = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($link_galeri);
        $qr_filepath = '../' . $client['qr_code_path'];
        
        if (function_exists('curl_init')) {
            $ch = curl_init($qr_api);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $img = curl_exec($ch);
            @file_put_contents($qr_filepath, $img);
            curl_close($ch);
        } else {
            $qr_content = @file_get_contents($qr_api);
            if ($qr_content !== false) {
                @file_put_contents($qr_filepath, $qr_content);
            }
        }
    }

    $stmt = $pdo->prepare("UPDATE clients SET nama_client = ?, lokasi = ?, folder_foto_path = ?, tanggal_event = ?, jam_mulai = ?, jam_selesai = ? WHERE id = ?");
    $stmt->execute([$nama_client, $lokasi, $link_galeri, $tanggal_event, $jam_mulai, $jam_selesai, $id]);
    
    header("Location: index.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Klien - Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen">
    <div class="bg-white p-8 rounded-lg shadow-md w-full max-w-2xl">
        <h2 class="text-2xl font-bold mb-6 text-gray-800">Edit Klien</h2>
        <form method="POST" action="" class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-700">Nama Klien</label>
                <input type="text" name="nama_client" value="<?= htmlspecialchars($client['nama_client']) ?>" required class="mt-1 w-full px-3 py-2 border rounded-md focus:border-blue-500 focus:outline-none">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Lokasi Acara</label>
                <input type="text" name="lokasi" value="<?= htmlspecialchars($client['lokasi'] ?? '') ?>" required class="mt-1 w-full px-3 py-2 border rounded-md focus:border-blue-500 focus:outline-none">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Link Galeri (Tujuan QR)</label>
                <input type="url" name="folder_foto_path" value="<?= htmlspecialchars($client['folder_foto_path']) ?>" required class="mt-1 w-full px-3 py-2 border rounded-md focus:border-blue-500 focus:outline-none">
                <p class="text-xs text-orange-600 mt-1">Jika Anda mengubah URL ini, gambar QR Code lama akan otomatis tertimpa (di-generate ulang) dengan link yang baru.</p>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Tanggal Event</label>
                    <input type="date" name="tanggal_event" value="<?= $client['tanggal_event'] ?>" required class="mt-1 w-full px-3 py-2 border rounded-md focus:border-blue-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Jam Mulai</label>
                    <input type="time" name="jam_mulai" value="<?= $client['jam_mulai'] ?>" required class="mt-1 w-full px-3 py-2 border rounded-md focus:border-blue-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Jam Selesai</label>
                    <input type="time" name="jam_selesai" value="<?= $client['jam_selesai'] ?>" required class="mt-1 w-full px-3 py-2 border rounded-md focus:border-blue-500 focus:outline-none">
                </div>
            </div>
            <div class="flex justify-end space-x-3 pt-6">
                <a href="index.php" class="bg-gray-300 text-gray-700 px-4 py-2 rounded-md hover:bg-gray-400 font-medium">Batal</a>
                <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700 font-medium">Simpan Perubahan</button>
            </div>
        </form>
    </div>
</body>
</html>
