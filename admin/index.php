<?php
require_once '../config.php';
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit;
}

// Handle Add Client
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add') {
    $nama_client = $_POST['nama_client'];
    $lokasi = $_POST['lokasi'];
    // Slug digunakan untuk nama unik file QR
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $nama_client))) . '-' . time();
    $tanggal_event = $_POST['tanggal_event'];
    $jam_mulai = $_POST['jam_mulai'];
    $jam_selesai = $_POST['jam_selesai'];
    $link_galeri = $_POST['folder_foto_path'];
    
    // Create folder for QR Codes if not exists
    $qr_folder_path = '../uploads/qrcodes';
    if (!file_exists($qr_folder_path)) {
        mkdir($qr_folder_path, 0777, true);
    }

    // Buat Direct Link Otomatis sesuai Tahun & Nama Klien (tanpa spasi)
    $tahun_event = date('Y', strtotime($tanggal_event));
    $nama_clean = preg_replace('/\s+/', '', $nama_client);
    $custom_direct_link = rtrim($base_url, '/') . '/' . $tahun_event . '/' . $nama_clean;

    // Generate QR Code via API, pointing to CUSTOM DIRECT LINK
    $qr_data = $custom_direct_link;
    $qr_api = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($qr_data);
    $qr_filename = $slug . '.png';
    $qr_filepath = '../uploads/qrcodes/' . $qr_filename;
    
    // Fetch and save QR image using cURL
    if (function_exists('curl_init')) {
        $ch = curl_init($qr_api);
        $fp = @fopen($qr_filepath, 'wb');
        if ($fp) {
            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_setopt($ch, CURLOPT_HEADER, 0);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_exec($ch);
            curl_close($ch);
            fclose($fp);
        } else {
            // Fallback if fopen fails due to permissions
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $img = curl_exec($ch);
            @file_put_contents($qr_filepath, $img);
            curl_close($ch);
        }
    } else {
        $qr_content = @file_get_contents($qr_api);
        if ($qr_content !== false) {
            @file_put_contents($qr_filepath, $qr_content);
        }
    }

    $stmt = $pdo->prepare("INSERT INTO clients (nama_client, slug, lokasi, tanggal_event, jam_mulai, jam_selesai, qr_code_path, folder_foto_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$nama_client, $slug, $lokasi, $tanggal_event, $jam_mulai, $jam_selesai, 'uploads/qrcodes/'.$qr_filename, $link_galeri]);
    header("Location: index.php");
    exit;
}

// Handle Delete Client
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM clients WHERE id = ?");
    $stmt->execute([$id]);
    header("Location: index.php");
    exit;
}

$clients = $pdo->query("SELECT * FROM clients ORDER BY created_at DESC")->fetchAll();

// Helper untuk membuat Direct Link otomatis dari data klien
function buildDirectLink($c, $base_url) {
    $year = date('Y', strtotime($c['tanggal_event']));
    $clean_name = preg_replace('/\s+/', '', $c['nama_client']);
    return rtrim($base_url, '/') . '/' . $year . '/' . $clean_name;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">
    <nav class="bg-white shadow-md p-4">
        <div class="container mx-auto flex justify-between items-center">
            <h1 class="text-xl font-bold text-gray-800">Photobooth Admin</h1>
            <div>
                <a href="change_password.php" class="text-blue-600 hover:underline mr-4">Ganti Password</a>
                <a href="logout.php" class="text-red-600 hover:underline">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container mx-auto mt-8 p-4">
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-2xl font-bold mb-4">Tambah Klien Baru</h2>
            <form method="POST" action="" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <input type="hidden" name="action" value="add">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Nama Klien</label>
                    <input type="text" name="nama_client" id="input_nama" placeholder="Contoh: Fathya & Fadhil (Wedding)" required class="mt-1 w-full px-3 py-2 border rounded-md" oninput="updatePreviewLink()">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Lokasi Acara</label>
                    <textarea name="lokasi" placeholder="Baris 1: Nama Gedung&#10;Baris 2: Nama Kota" required class="mt-1 w-full px-3 py-2 border rounded-md" rows="2"></textarea>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Link Galeri (Tujuan Akhir / Folder Foto)</label>
                    <input type="url" name="folder_foto_path" placeholder="https://fotoshare.co/e/..." required class="mt-1 w-full px-3 py-2 border rounded-md">
                    <p class="text-xs text-blue-600 mt-1.5 font-medium" id="preview_direct_link">
                        🔗 Link QR Otomatis: <span class="underline font-mono">https://files.yuhu.co.id/<?= date('Y') ?>/NamaKlien</span>
                    </p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Tanggal Event</label>
                    <input type="date" name="tanggal_event" id="input_tanggal" required class="mt-1 w-full px-3 py-2 border rounded-md" onchange="updatePreviewLink()">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Jam Mulai</label>
                    <input type="time" name="jam_mulai" required class="mt-1 w-full px-3 py-2 border rounded-md">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Jam Selesai</label>
                    <input type="time" name="jam_selesai" required class="mt-1 w-full px-3 py-2 border rounded-md">
                </div>
                <div class="md:col-span-2">
                    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700 font-semibold">Simpan & Generate QR</button>
                </div>
            </form>
        </div>

        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-2xl font-bold mb-4">Daftar Klien</h2>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead>
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Nama</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Lokasi</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Jadwal</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Link QR & Tujuan</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">QR Code</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php foreach($clients as $c): 
                            $direct_link = buildDirectLink($c, $base_url);
                        ?>
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap font-medium text-gray-900"><?= htmlspecialchars($c['nama_client']) ?></td>
                            <td class="px-6 py-4 whitespace-pre-wrap text-sm leading-snug"><?= htmlspecialchars($c['lokasi'] ?? '-') ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm"><?= $c['tanggal_event'] ?> <br> <span class="text-gray-500"><?= $c['jam_mulai'] ?> - <?= $c['jam_selesai'] ?></span></td>
                            <td class="px-6 py-4 text-sm">
                                <div class="mb-1.5">
                                    <span class="text-[11px] font-bold uppercase text-blue-600 block">Link Direct (QR):</span>
                                    <div class="flex items-center gap-2 mt-0.5">
                                        <a href="<?= htmlspecialchars($direct_link) ?>" target="_blank" class="bg-blue-50 text-blue-700 border border-blue-200 px-2 py-0.5 rounded font-mono text-xs hover:underline break-all"><?= htmlspecialchars($direct_link) ?></a>
                                        <button type="button" onclick="copyText('<?= htmlspecialchars($direct_link, ENT_QUOTES) ?>', this)" class="text-xs bg-slate-800 text-white px-2 py-0.5 rounded hover:bg-slate-700 whitespace-nowrap">Copy</button>
                                    </div>
                                </div>
                                <div>
                                    <span class="text-[11px] font-bold uppercase text-gray-400 block">Tujuan Akhir (Folder Foto):</span>
                                    <a href="<?= htmlspecialchars($c['folder_foto_path']) ?>" target="_blank" class="text-gray-500 hover:underline font-mono text-xs break-all"><?= htmlspecialchars($c['folder_foto_path']) ?></a>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <a href="<?= '../' . $c['qr_code_path'] ?>" target="_blank" class="text-blue-600 hover:underline font-medium">Lihat QR</a>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <a href="edit.php?id=<?= $c['id'] ?>" class="text-indigo-600 hover:underline mr-3">Edit</a>
                                <a href="?delete=<?= $c['id'] ?>" onclick="return confirm('Yakin ingin menghapus?')" class="text-red-600 hover:underline">Hapus</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
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

        function copyText(text, btn) {
            navigator.clipboard.writeText(text).then(() => {
                const orig = btn.innerText;
                btn.innerText = 'Copied!';
                btn.classList.replace('bg-slate-800', 'bg-green-600');
                setTimeout(() => {
                    btn.innerText = orig;
                    btn.classList.replace('bg-green-600', 'bg-slate-800');
                }, 1500);
            });
        }
    </script>
</body>
</html>