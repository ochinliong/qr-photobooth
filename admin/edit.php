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
    $link_galeri = trim($_POST['folder_foto_path'] ?? '');
    if (empty($link_galeri)) {
        $link_galeri = 'uploads/gallery/' . $client['slug'];
    }
    $tanggal_event = $_POST['tanggal_event'];
    $jam_mulai = $_POST['jam_mulai'];
    $jam_selesai = $_POST['jam_selesai'];

    // Buat Direct Link Otomatis sesuai Tahun & Nama Klien (tanpa spasi)
    $tahun_event = date('Y', strtotime($tanggal_event));
    $nama_clean = preg_replace('/\s+/', '', $nama_client);
    $custom_direct_link = rtrim($base_url, '/') . '/' . $tahun_event . '/' . $nama_clean;

    // Selalu pastikan QR Code mengarah ke Custom Direct Link terbaru
    $qr_api = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($custom_direct_link);
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

    $stmt = $pdo->prepare("UPDATE clients SET nama_client = ?, lokasi = ?, folder_foto_path = ?, tanggal_event = ?, jam_mulai = ?, jam_selesai = ? WHERE id = ?");
    $stmt->execute([$nama_client, $lokasi, $link_galeri, $tanggal_event, $jam_mulai, $jam_selesai, $id]);
    
    header("Location: index.php");
    exit;
}

$init_year = date('Y', strtotime($client['tanggal_event']));
$init_clean_name = preg_replace('/\s+/', '', $client['nama_client']);
$init_direct_link = rtrim($base_url, '/') . '/' . $init_year . '/' . $init_clean_name;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Klien - Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen p-4">
    <div class="bg-white p-8 rounded-lg shadow-md w-full max-w-2xl">
        <h2 class="text-2xl font-bold mb-6 text-gray-800">Edit Klien</h2>
        <form method="POST" action="" class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-700">Nama Klien</label>
                <input type="text" name="nama_client" id="input_nama" value="<?= htmlspecialchars($client['nama_client']) ?>" required class="mt-1 w-full px-3 py-2 border rounded-md focus:border-blue-500 focus:outline-none" oninput="updatePreviewLink()">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Lokasi Acara</label>
                <textarea name="lokasi" required class="mt-1 w-full px-3 py-2 border rounded-md focus:border-blue-500 focus:outline-none" rows="2"><?= htmlspecialchars($client['lokasi'] ?? '') ?></textarea>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Tujuan Galeri (Kosongkan untuk Portal Gallery LumaBooth)</label>
                <input type="text" name="folder_foto_path" id="input_folder" value="<?= htmlspecialchars($client['folder_foto_path']) ?>" placeholder="Kosongkan untuk Galeri Internal: uploads/gallery/<?= htmlspecialchars($client['slug']) ?>" class="mt-1 w-full px-3 py-2 border rounded-md focus:border-blue-500 focus:outline-none">
                <div class="mt-1.5 flex items-center gap-2">
                    <button type="button" onclick="document.getElementById('input_folder').value = 'uploads/gallery/<?= htmlspecialchars($client['slug']) ?>'" class="text-xs bg-emerald-50 text-emerald-700 border border-emerald-200 px-2.5 py-1 rounded font-semibold hover:bg-emerald-100">
                        Gunakan Portal Gallery Website Ini (LumaBooth Sync)
                    </button>
                </div>
                <p class="text-xs text-blue-600 mt-2 font-medium" id="preview_direct_link">
                    🔗 Link QR Otomatis: <span class="underline font-mono"><?= htmlspecialchars($init_direct_link) ?></span>
                </p>
                <p class="text-xs text-orange-600 mt-1">Saat disimpan, QR Code akan otomatis diperbarui mengikuti Link QR Otomatis di atas.</p>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Tanggal Event</label>
                    <input type="date" name="tanggal_event" id="input_tanggal" value="<?= $client['tanggal_event'] ?>" required class="mt-1 w-full px-3 py-2 border rounded-md focus:border-blue-500 focus:outline-none" onchange="updatePreviewLink()">
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
    <script>
        function updatePreviewLink() {
            const namaInput = document.getElementById('input_nama').value;
            const tanggalInput = document.getElementById('input_tanggal').value;
            const year = tanggalInput ? tanggalInput.substring(0, 4) : '<?= date('Y') ?>';
            const cleanName = namaInput ? namaInput.replace(/\s+/g, '') : 'NamaKlien';
            const previewEl = document.querySelector('#preview_direct_link span');
            previewEl.textContent = `https://files.yuhu.co.id/${year}/${cleanName}`;
        }
    </script>
</body>
</html>
