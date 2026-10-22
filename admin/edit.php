<?php
require_once '../config.php';
require_once __DIR__ . '/../lumabooth_sync.php';
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

$lumabooth_folder = extractLumaboothFolderFromUrl($client['folder_foto_path'] ?? '');
if ($lumabooth_folder === '') {
    $lumabooth_folder = str_replace(' ', '_', $client['nama_client']);
}
$default_gallery_link = rtrim($base_url, '/') . '/client/' . rawurlencode($lumabooth_folder);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $nama_client = trim($_POST['nama_client']);
    $lokasi = trim($_POST['lokasi'] ?? '');
    if ($lokasi === '') {
        $lokasi = '-';
    }
    $link_galeri = trim($_POST['folder_foto_path'] ?? '');
    if (empty($link_galeri)) {
        $link_galeri = $default_gallery_link;
    }
    $tanggal_event = !empty($_POST['tanggal_event']) ? $_POST['tanggal_event'] : null;
    $jam_mulai = !empty($_POST['jam_mulai']) ? $_POST['jam_mulai'] : null;
    $jam_selesai = !empty($_POST['jam_selesai']) ? $_POST['jam_selesai'] : null;
    $enable_visual_search = isset($_POST['enable_visual_search']) ? 1 : 0;

    // Buat Direct Link Otomatis sesuai Tahun & Nama Klien (tanpa spasi)
    $tahun_event = $tanggal_event ? date('Y', strtotime($tanggal_event)) : date('Y');
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

    try {
        $stmt = $pdo->prepare("UPDATE clients SET nama_client = ?, lokasi = ?, folder_foto_path = ?, tanggal_event = ?, jam_mulai = ?, jam_selesai = ?, enable_visual_search = ? WHERE id = ?");
        $stmt->execute([$nama_client, $lokasi, $link_galeri, $tanggal_event, $jam_mulai, $jam_selesai, $enable_visual_search, $id]);
    } catch (Exception $e) {
        $stmt = $pdo->prepare("UPDATE clients SET nama_client = ?, lokasi = ?, folder_foto_path = ?, tanggal_event = ?, jam_mulai = ?, jam_selesai = ? WHERE id = ?");
        $stmt->execute([$nama_client, $lokasi, $link_galeri, $tanggal_event, $jam_mulai, $jam_selesai, $id]);
    }

    // Sinkronkan juga pengaturan enable_visual_search ke config.json di folder photos
    $photos_dir = '../client/photos/' . $lumabooth_folder;
    if (is_dir($photos_dir)) {
        $cfg_path = $photos_dir . '/config.json';
        $cfg = file_exists($cfg_path) ? (@json_decode(file_get_contents($cfg_path), true) ?: []) : [];
        $cfg['enable_visual_search'] = ($enable_visual_search === 1);
        @file_put_contents($cfg_path, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
    
    header("Location: index.php");
    exit;
}

$init_year = (!empty($client['tanggal_event']) && $client['tanggal_event'] !== '0000-00-00')
    ? date('Y', strtotime($client['tanggal_event']))
    : date('Y');
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
                <label class="block text-sm font-medium text-gray-700">Lokasi Acara <span class="text-xs text-gray-400 font-normal">(Bisa diisi manual kapan saja)</span></label>
                <textarea name="lokasi" placeholder="Baris 1: Nama Gedung&#10;Baris 2: Nama Kota" class="mt-1 w-full px-3 py-2 border rounded-md focus:border-blue-500 focus:outline-none" rows="2"><?= htmlspecialchars(($client['lokasi'] ?? '') === '-' ? '' : ($client['lokasi'] ?? '')) ?></textarea>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Tujuan Galeri (Folder LumaBooth / URL)</label>
                <input type="text" name="folder_foto_path" id="input_folder" value="<?= htmlspecialchars($client['folder_foto_path']) ?>" placeholder="Default: <?= htmlspecialchars($default_gallery_link) ?>" class="mt-1 w-full px-3 py-2 border rounded-md focus:border-blue-500 focus:outline-none">
                <div class="mt-1.5 flex items-center gap-2">
                    <button type="button" onclick="document.getElementById('input_folder').value = '<?= htmlspecialchars($default_gallery_link, ENT_QUOTES) ?>'" class="text-xs bg-emerald-50 text-emerald-700 border border-emerald-200 px-2.5 py-1 rounded font-semibold hover:bg-emerald-100">
                        Gunakan Portal Gallery LumaBooth (<?= htmlspecialchars($lumabooth_folder) ?>)
                    </button>
                </div>
                <p class="text-xs text-blue-600 mt-2 font-medium" id="preview_direct_link">
                    🔗 Link QR Otomatis: <span class="underline font-mono"><?= htmlspecialchars($init_direct_link) ?></span>
                </p>
                <p class="text-xs text-orange-600 mt-1">Saat disimpan, QR Code akan otomatis diperbarui mengikuti Link QR Otomatis di atas.</p>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Tanggal Event <span class="text-xs text-gray-400 font-normal">(Opsional)</span></label>
                    <input type="date" name="tanggal_event" id="input_tanggal" value="<?= htmlspecialchars($client['tanggal_event'] ?? '') ?>" class="mt-1 w-full px-3 py-2 border rounded-md focus:border-blue-500 focus:outline-none" onchange="updatePreviewLink()">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Jam Mulai <span class="text-xs text-gray-400 font-normal">(Opsional)</span></label>
                    <input type="time" name="jam_mulai" value="<?= htmlspecialchars($client['jam_mulai'] ?? '') ?>" class="mt-1 w-full px-3 py-2 border rounded-md focus:border-blue-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Jam Selesai <span class="text-xs text-gray-400 font-normal">(Opsional)</span></label>
                    <input type="time" name="jam_selesai" value="<?= htmlspecialchars($client['jam_selesai'] ?? '') ?>" class="mt-1 w-full px-3 py-2 border rounded-md focus:border-blue-500 focus:outline-none">
                </div>
            </div>
            <p class="text-xs text-amber-600 font-medium mt-1">
                💡 <em>Catatan:</em> Project baru belum akan ditampilkan di halaman Live Events (files.yuhu.co.id) sampai Tanggal dan Jam Acara di atas diisi.
            </p>
            <div class="p-3.5 bg-purple-50/80 border border-purple-200 rounded-lg">
                <label class="flex items-start gap-3 cursor-pointer">
                    <input type="checkbox" name="enable_visual_search" value="1" <?= (!isset($client['enable_visual_search']) || (int)$client['enable_visual_search'] === 1) ? 'checked' : '' ?> class="mt-0.5 h-4 w-4 text-purple-600 focus:ring-purple-500 border-gray-300 rounded">
                    <div>
                        <span class="block text-xs font-bold text-purple-900">📸 Fitur Pencarian Foto dari Hasil Cetak (Visual Search)</span>
                        <span class="block text-[11px] text-purple-700 mt-0.5">Jika aktif, tamu dapat memfoto kertas cetakan fisik untuk mencari softfile secara otomatis tanpa kode. Jika dinonaktifkan, tombol ini tidak akan tampil di galeri acara ini.</span>
                    </div>
                </label>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-3 pt-6">
                <a href="gallery.php?id=<?= $client['id'] ?>" class="bg-emerald-50 text-emerald-700 border border-emerald-200 px-4 py-2 rounded-md hover:bg-emerald-100 font-semibold text-sm">
                    🖼️ Kelola Foto Galeri Acara Ini
                </a>
                <div class="flex space-x-3">
                    <a href="index.php" class="bg-gray-300 text-gray-700 px-4 py-2 rounded-md hover:bg-gray-400 font-medium">Batal</a>
                    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700 font-medium">Simpan Perubahan</button>
                </div>
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
