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
    // Slug digunakan untuk nama unik file QR & folder galeri
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $nama_client))) . '-' . time();
    $tanggal_event = $_POST['tanggal_event'];
    $jam_mulai = $_POST['jam_mulai'];
    $jam_selesai = $_POST['jam_selesai'];
    $link_galeri = trim($_POST['folder_foto_path'] ?? '');
    $folder_event_name = preg_replace('/[^A-Za-z0-9_\-&() ]/', '', str_replace(' ', '_', $nama_client));
    if (empty($link_galeri)) {
        // Default menggunakan Portal Galeri Internal (otomatis mengikuti nama Event LumaBooth)
        $link_galeri = rtrim($base_url, '/') . '/client/?client=' . rawurlencode($folder_event_name);
    }

    // Buat folder galeri lokal di ../client/photos/{EventName} untuk sinkronisasi LumaBooth
    $gallery_folder_path = '../client/photos/' . $folder_event_name;
    if (!file_exists($gallery_folder_path)) {
        @mkdir($gallery_folder_path, 0777, true);
    }

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
    $stmt_find = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
    $stmt_find->execute([$id]);
    $del_client = $stmt_find->fetch(PDO::FETCH_ASSOC);
    if ($del_client) {
        $photos_root = '../client/photos';
        if (is_dir($photos_root)) {
            $cand_folder = extractLumaboothFolderFromUrl($del_client['folder_foto_path'] ?? '');
            $cand_clean = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $cand_folder ?: $del_client['nama_client']));
            foreach (scandir($photos_root) as $d) {
                if ($d !== '.' && $d !== '..' && is_dir($photos_root . '/' . $d)) {
                    if ($cand_clean !== '' && strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $d)) === $cand_clean) {
                        @file_put_contents($photos_root . '/' . $d . '/.deleted_by_admin', date('Y-m-d H:i:s'));
                    }
                }
            }
        }
    }
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

// Helper menentukan nama folder LumaBooth dari data klien
function resolveClientFolder($c) {
    $from_url = extractLumaboothFolderFromUrl($c['folder_foto_path'] ?? '');
    if ($from_url !== '') return $from_url;
    return str_replace(' ', '_', $c['nama_client']);
}

// Helper hitung foto lokal di Portal Gallery (/client/photos/{NamaEvent})
function countLocalPhotos($c) {
    $photos_root = '../client/photos';
    if (!is_dir($photos_root)) return 0;
    $folder_hint = resolveClientFolder($c);
    $clean_hint = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $folder_hint));
    $clean_name = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $c['nama_client']));
    $matched_dir = '';
    foreach (scandir($photos_root) as $d) {
        if ($d !== '.' && $d !== '..' && is_dir($photos_root . '/' . $d)) {
            $d_clean = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $d));
            if ($d_clean !== '' && ($d_clean === $clean_hint || $d_clean === $clean_name)) {
                $matched_dir = $photos_root . '/' . $d;
                break;
            }
        }
    }
    if (empty($matched_dir) || !is_dir($matched_dir)) return 0;
    $count = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($matched_dir));
    foreach ($it as $file) {
        if ($file->isFile()) {
            $ext = strtolower($file->getExtension());
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'mov', 'webm'])) {
                $rel = strtolower($file->getPathname());
                if (strpos($rel, 'thumb') === false && strpos($rel, '.part_') === false) {
                    $count++;
                }
            }
        }
    }
    return $count;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - YUHU Photobooth</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">
    <nav class="bg-white shadow-md p-4">
        <div class="container mx-auto flex justify-between items-center">
            <div class="flex items-center gap-3">
                <h1 class="text-xl font-bold text-gray-800">Photobooth Admin</h1>
                <span class="bg-emerald-100 text-emerald-700 text-xs font-bold px-2.5 py-1 rounded-full">LumaBooth Windows 2026 Ready</span>
            </div>
            <div>
                <a href="change_password.php" class="text-blue-600 hover:underline mr-4">Ganti Password</a>
                <a href="logout.php" class="text-red-600 hover:underline">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container mx-auto mt-8 p-4">
        <!-- Banner Info LumaBooth Sync -->
        <div class="bg-slate-900 text-white rounded-xl p-5 mb-6 shadow-md flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
            <div>
                <h3 class="font-bold text-base flex items-center gap-2">
                    <span class="w-2.5 h-2.5 rounded-full bg-emerald-400 animate-ping"></span>
                    Integrasi LumaBooth Windows (Oktober 2026) Aktif
                </h3>
                <p class="text-xs text-slate-300 mt-1">
                    Aplikasi Windows <strong>YUHU LumaBooth Sync Pro</strong> dapat langsung mengupload foto/GIF/Video dari PC Photobooth ke Portal Gallery setiap klien secara <em>real-time</em>.
                </p>
            </div>
            <div class="text-xs bg-slate-800 border border-slate-700 px-3.5 py-2 rounded-lg font-mono text-slate-200 flex-shrink-0">
                API Endpoint: <span class="text-emerald-400"><?= htmlspecialchars(rtrim($base_url, '/') . '/api/lumabooth.php') ?></span>
            </div>
        </div>

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
                    <label class="block text-sm font-medium text-gray-700">Tujuan Galeri (Kosongkan untuk Portal Gallery LumaBooth)</label>
                    <input type="text" name="folder_foto_path" placeholder="Kosongkan untuk Portal Gallery Website ini, atau isi URL eksternal" class="mt-1 w-full px-3 py-2 border rounded-md">
                    <p class="text-xs text-emerald-700 mt-1 font-medium">
                        ✨ <strong>Otomatis Portal Gallery:</strong> Jika dikosongkan, hasil foto dari LumaBooth Windows langsung tampil di galeri website Anda.
                    </p>
                    <p class="text-xs text-blue-600 mt-1 font-medium" id="preview_direct_link">
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
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Link QR & Galeri</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">QR Code</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php foreach($clients as $c): 
                            $direct_link = buildDirectLink($c, $base_url);
                            $photo_count = countLocalPhotos($c);
                            $folder_c = resolveClientFolder($c);
                            $internal_gallery_url = rtrim($base_url, '/') . '/client/' . rawurlencode($folder_c);
                            $is_auto_lokasi = (trim($c['lokasi'] ?? '') === '' || trim($c['lokasi'] ?? '') === '-');
                        ?>
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap font-medium text-gray-900">
                                <?= htmlspecialchars($c['nama_client']) ?>
                                <div class="mt-1 flex items-center gap-1.5 flex-wrap">
                                    <a href="<?= htmlspecialchars($internal_gallery_url) ?>" target="_blank" class="inline-flex items-center gap-1 text-[11px] font-bold px-2 py-0.5 rounded-full <?= $photo_count > 0 ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-600' ?>">
                                        📷 <?= $photo_count ?> File di Portal Gallery
                                    </a>
                                    <?php if ($is_auto_lokasi): ?>
                                        <a href="edit.php?id=<?= $c['id'] ?>" class="inline-flex items-center gap-1 text-[10px] font-bold px-2 py-0.5 rounded-full bg-amber-100 text-amber-800 hover:bg-amber-200">
                                            ⚡ Auto LumaBooth (Klik Edit Data)
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-pre-wrap text-sm leading-snug">
                                <?php if ($is_auto_lokasi): ?>
                                    <a href="edit.php?id=<?= $c['id'] ?>" class="text-xs text-amber-600 font-semibold hover:underline">+ Isi Lokasi Acara</a>
                                <?php else: ?>
                                    <?= htmlspecialchars($c['lokasi']) ?>
                                <?php endif; ?>
                            </td>
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
                                    <span class="text-[11px] font-bold uppercase text-gray-400 block">Tujuan Akhir (Folder / URL):</span>
                                    <?php if (strpos($c['folder_foto_path'], 'uploads/gallery/') === 0): ?>
                                        <a href="<?= htmlspecialchars($internal_gallery_url) ?>" target="_blank" class="text-emerald-600 font-semibold hover:underline font-mono text-xs break-all">Portal Gallery Internal (<?= htmlspecialchars($c['folder_foto_path']) ?>)</a>
                                    <?php else: ?>
                                        <a href="<?= htmlspecialchars($c['folder_foto_path']) ?>" target="_blank" class="text-gray-500 hover:underline font-mono text-xs break-all"><?= htmlspecialchars($c['folder_foto_path']) ?></a>
                                    <?php endif; ?>
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
