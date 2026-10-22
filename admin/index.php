<?php
require_once '../config.php';
require_once __DIR__ . '/../lumabooth_sync.php';
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
                        @unlink($photos_root . '/' . $d . '/.db_registered.lock');
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

$all_clients = $pdo->query("SELECT * FROM clients ORDER BY created_at DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC);

$pending_clients = [];
$scheduled_clients = [];

foreach ($all_clients as $c) {
    $has_valid_schedule = !empty($c['tanggal_event']) 
        && $c['tanggal_event'] !== '0000-00-00' 
        && $c['tanggal_event'] !== '1970-01-01'
        && !empty($c['jam_mulai']) 
        && !empty($c['jam_selesai'])
        && !($c['jam_mulai'] === '00:00:00' && $c['jam_selesai'] === '23:59:00' && (trim($c['lokasi'] ?? '') === '' || trim($c['lokasi'] ?? '') === '-'));

    if ($has_valid_schedule) {
        $scheduled_clients[] = $c;
    } else {
        $pending_clients[] = $c;
    }
}
$clients = $all_clients;

// Helper untuk membuat Direct Link otomatis dari data klien
function buildDirectLink($c, $base_url) {
    $year = (!empty($c['tanggal_event']) && $c['tanggal_event'] !== '0000-00-00')
        ? date('Y', strtotime($c['tanggal_event']))
        : date('Y');
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

// Helper untuk render 1 baris tabel klien (dipakai baik untuk project belum ada tanggal maupun terjadwal)
function renderClientRow($c, $base_url, $is_pending_section = false) {
    $direct_link = buildDirectLink($c, $base_url);
    $photo_count = countLocalPhotos($c);
    $folder_c = resolveClientFolder($c);
    $internal_gallery_url = rtrim($base_url, '/') . '/client/' . rawurlencode($folder_c);
    $is_auto_lokasi = (trim($c['lokasi'] ?? '') === '' || trim($c['lokasi'] ?? '') === '-');

    $tgl_obj = (!empty($c['tanggal_event']) && $c['tanggal_event'] !== '0000-00-00') ? strtotime($c['tanggal_event']) : null;
    $bulan_indo = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    $tgl_formatted = $tgl_obj ? (date('d', $tgl_obj) . ' ' . $bulan_indo[(int)date('m', $tgl_obj) - 1] . ' ' . date('Y', $tgl_obj)) : null;
    $has_real_time = (!empty($c['jam_mulai']) && !empty($c['jam_selesai']) && !($c['jam_mulai'] === '00:00:00' && $c['jam_selesai'] === '23:59:00' && empty($tgl_formatted)));
    $jam_formatted = $has_real_time ? (substr($c['jam_mulai'], 0, 5) . ' - ' . substr($c['jam_selesai'], 0, 5) . ' WIB') : null;
?>
<tr class="<?= $is_pending_section ? 'hover:bg-amber-50/70' : 'hover:bg-slate-50/70' ?> transition">
    <!-- Kolom 1: Klien & Acara -->
    <td class="px-5 py-4 align-top w-[30%] min-w-[260px]">
        <div class="font-extrabold text-slate-900 text-base leading-snug mb-1.5 flex items-center gap-2 flex-wrap">
            <span><?= htmlspecialchars($c['nama_client']) ?></span>
            <?php if ($is_pending_section): ?>
                <span class="text-[10px] font-black uppercase tracking-wider bg-amber-500 text-white px-2 py-0.5 rounded-full shadow-2xs">
                    Perlu Jadwal
                </span>
            <?php endif; ?>
        </div>

        <div class="space-y-1 mb-2.5">
            <div class="text-xs text-slate-600 flex items-center gap-1.5 font-medium flex-wrap">
                <?php if ($tgl_formatted && $jam_formatted): ?>
                    <span>📅 <?= $tgl_formatted ?></span>
                    <span class="text-slate-300">•</span>
                    <span>🕒 <?= $jam_formatted ?></span>
                <?php else: ?>
                    <a href="edit.php?id=<?= $c['id'] ?>" class="inline-flex items-center gap-1 bg-amber-100 hover:bg-amber-200 text-amber-900 border border-amber-300 font-bold px-2.5 py-1 rounded-lg text-xs transition shadow-2xs">
                        📅 Atur Tanggal & Jam Acara →
                    </a>
                    <span class="text-[10px] text-slate-500 bg-slate-100 px-1.5 py-0.5 rounded border border-slate-200" title="Project ini belum tampil di Live Events sampai tanggal & jam acara ditentukan">
                        🔒 Belum Tampil di Live
                    </span>
                <?php endif; ?>
            </div>
            <div class="text-xs">
                <?php if ($is_auto_lokasi): ?>
                    <a href="edit.php?id=<?= $c['id'] ?>" class="inline-flex items-center gap-1 text-amber-600 font-semibold hover:underline">
                        📍 + Isi Lokasi Acara
                    </a>
                <?php else: ?>
                    <div class="text-slate-600 flex items-start gap-1 leading-snug">
                        <span class="flex-shrink-0">📍</span>
                        <span><?= nl2br(htmlspecialchars($c['lokasi'])) ?></span>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="flex items-center gap-1.5 flex-wrap">
            <a href="<?= htmlspecialchars($internal_gallery_url) ?>" target="_blank" class="inline-flex items-center gap-1 text-[11px] font-bold px-2.5 py-0.5 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200 hover:bg-emerald-100 transition">
                👁️ Lihat Galeri ↗
            </a>
            <?php if ($is_auto_lokasi): ?>
                <span class="inline-flex items-center gap-1 text-[10px] font-bold px-2 py-0.5 rounded-full bg-amber-50 text-amber-700 border border-amber-200" title="Dibuat otomatis oleh sistem saat foto pertama dikirim dari LumaBooth">
                    ⚡ Auto LumaBooth
                </span>
            <?php endif; ?>
        </div>
    </td>

    <!-- Kolom 2: Link Direct Tamu (QR) -->
    <td class="px-5 py-4 align-top w-[28%] min-w-[270px]">
        <div class="bg-blue-50/80 border border-blue-200 rounded-xl p-3 shadow-2xs">
            <div class="flex items-center justify-between gap-2 mb-1.5">
                <span class="text-[11px] font-extrabold uppercase tracking-wide text-blue-700 flex items-center gap-1">
                    🔗 Link Tamu (QR)
                </span>
                <a href="../<?= htmlspecialchars($c['qr_code_path']) ?>" target="_blank" class="inline-flex items-center gap-1 text-[10px] font-bold bg-white text-blue-700 border border-blue-200 hover:bg-blue-600 hover:text-white px-2 py-0.5 rounded-md shadow-2xs transition">
                    📱 QR Code
                </a>
            </div>
            <div class="flex items-center gap-1 bg-white border border-blue-100 rounded-lg p-1 mb-2">
                <input type="text" readonly value="<?= htmlspecialchars($direct_link) ?>" class="w-full bg-transparent text-xs font-mono text-slate-700 border-none outline-none focus:ring-0 select-all truncate px-1" title="<?= htmlspecialchars($direct_link) ?>">
                <button type="button" onclick="copyText('<?= htmlspecialchars($direct_link, ENT_QUOTES) ?>', this)" class="bg-blue-600 hover:bg-blue-700 text-white text-[11px] font-bold px-2.5 py-1 rounded-md transition whitespace-nowrap shadow-xs">
                    Salin
                </button>
                <a href="<?= htmlspecialchars($direct_link) ?>" target="_blank" class="bg-slate-100 hover:bg-slate-200 text-slate-700 text-[11px] font-bold px-2 py-1 rounded-md transition whitespace-nowrap" title="Buka Link Tamu">
                    ↗
                </a>
            </div>
            <!-- Tombol Buat Pesan Otomatis Tamu -->
            <button type="button" onclick="copyGuestMsg(this)" data-name="<?= htmlspecialchars($c['nama_client'], ENT_QUOTES) ?>" data-link="<?= htmlspecialchars($direct_link, ENT_QUOTES) ?>" class="w-full flex items-center justify-center gap-1.5 bg-blue-100/90 hover:bg-blue-200 text-blue-800 text-[11px] font-bold py-1.5 px-2.5 rounded-lg border border-blue-200 transition shadow-2xs">
                <span>💬 Salin Pesan Tamu (WA)</span>
            </button>
        </div>
    </td>

    <!-- Kolom 3: Link Khusus Klien (VIP) -->
    <td class="px-5 py-4 align-top w-[28%] min-w-[270px]">
        <div class="bg-gradient-to-r from-amber-50 to-orange-50/60 border border-amber-200 rounded-xl p-3 shadow-2xs">
            <div class="flex items-center justify-between gap-2 mb-1.5">
                <span class="text-[11px] font-extrabold uppercase tracking-wide text-amber-800 flex items-center gap-1">
                    👑 Link Khusus Klien (VIP)
                </span>
                <span class="text-[10px] text-amber-800 font-bold bg-amber-100 border border-amber-200 px-1.5 py-0.2 rounded">ZIP</span>
            </div>
            <div class="flex items-center gap-1 bg-white border border-amber-200 rounded-lg p-1 mb-2">
                <input type="text" readonly value="<?= htmlspecialchars($direct_link . '/vip') ?>" class="w-full bg-transparent text-xs font-mono text-amber-950 font-semibold border-none outline-none focus:ring-0 select-all truncate px-1" title="<?= htmlspecialchars($direct_link . '/vip') ?>">
                <button type="button" onclick="copyText('<?= htmlspecialchars($direct_link . '/vip', ENT_QUOTES) ?>', this)" class="bg-amber-500 hover:bg-amber-600 text-white text-[11px] font-bold px-2.5 py-1 rounded-md transition whitespace-nowrap shadow-xs">
                    Salin VIP
                </button>
                <a href="<?= htmlspecialchars($direct_link . '/vip') ?>" target="_blank" class="bg-slate-100 hover:bg-slate-200 text-slate-700 text-[11px] font-bold px-2 py-1 rounded-md transition whitespace-nowrap" title="Buka Link Khusus VIP">
                    ↗
                </a>
            </div>
            <!-- Tombol Buat Pesan Otomatis VIP Klien -->
            <button type="button" onclick="copyVipMsg(this)" data-name="<?= htmlspecialchars($c['nama_client'], ENT_QUOTES) ?>" data-link="<?= htmlspecialchars($direct_link . '/vip', ENT_QUOTES) ?>" class="w-full flex items-center justify-center gap-1.5 bg-amber-100 hover:bg-amber-200 text-amber-900 text-[11px] font-bold py-1.5 px-2.5 rounded-lg border border-amber-200 shadow-2xs mb-1.5 transition">
                <span>👑 Salin Pesan VIP Klien (WA)</span>
            </button>
            <p class="text-[10px] text-amber-700/80 font-medium leading-tight">
                ✨ Halaman download all media ZIP untuk pengantin
            </p>
        </div>
    </td>

    <!-- Kolom 4: Aksi -->
    <td class="px-5 py-4 align-top text-right w-[14%] whitespace-nowrap">
        <div class="flex flex-col gap-2 items-end">
            <a href="gallery.php?id=<?= $c['id'] ?>" class="w-full inline-flex items-center justify-center gap-1.5 bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold px-3 py-2 rounded-xl shadow-xs transition">
                🖼️ Kelola Foto (<?= $photo_count ?>)
            </a>
            <div class="flex items-center gap-1.5 w-full justify-end">
                <a href="edit.php?id=<?= $c['id'] ?>" class="flex-1 text-center bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold px-2.5 py-1.5 rounded-lg border border-slate-200 transition">
                    ✏️ Edit
                </a>
                <a href="?delete=<?= $c['id'] ?>" onclick="return confirm('Yakin ingin menghapus klien ini beserta seluruh datanya?')" class="flex-1 text-center bg-red-50 hover:bg-red-100 text-red-600 text-xs font-bold px-2.5 py-1.5 rounded-lg border border-red-200 transition">
                    🗑️ Hapus
                </a>
            </div>
        </div>
    </td>
</tr>
<?php
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
<body class="bg-slate-100">
    <!-- Top Navigation Bar Terpadu -->
    <nav class="bg-white shadow-sm border-b border-slate-200 sticky top-0 z-40">
        <div class="container mx-auto px-4 py-3 flex flex-wrap justify-between items-center gap-3">
            <div class="flex items-center gap-6">
                <a href="index.php" class="flex items-center gap-2.5">
                    <img src="https://qr.yuhu.co.id/2025/icon.png" class="w-8 h-8 rounded-lg shadow-sm" alt="Logo">
                    <span class="text-lg font-extrabold text-slate-900 tracking-tight">YUHU <span class="text-blue-600">Admin</span></span>
                </a>
                <!-- Menu Tab Terpadu -->
                <div class="flex items-center bg-slate-100 p-1 rounded-xl border border-slate-200">
                    <a href="index.php" class="px-3.5 py-1.5 rounded-lg text-xs md:text-sm font-bold bg-blue-600 text-white shadow-sm transition">
                        📋 Daftar Klien & QR
                    </a>
                    <a href="gallery.php" class="px-3.5 py-1.5 rounded-lg text-xs md:text-sm font-semibold text-slate-600 hover:text-slate-900 transition">
                        🖼️ Kelola Foto & Galeri
                    </a>
                </div>
            </div>
            <div class="flex items-center gap-4 text-sm font-medium">
                <a href="../" target="_blank" class="text-slate-600 hover:text-blue-600">Lihat Live Portal ↗</a>
                <a href="change_password.php" class="text-blue-600 hover:underline">Ganti Password</a>
                <a href="logout.php" class="text-red-600 hover:underline font-semibold">Logout</a>
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

        <!-- 1. Bagian Khusus: Project Belum Ada Tanggal (Selalu di Atas untuk Tracking & Pengingat) -->
        <?php if (!empty($pending_clients)): ?>
        <div class="bg-amber-50/90 border-2 border-amber-300 rounded-2xl shadow-sm p-6 mb-8 ring-4 ring-amber-100/60">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-4">
                <div class="flex items-center gap-3">
                    <span class="flex h-4 w-4 relative flex-shrink-0">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-amber-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-4 w-4 bg-amber-500"></span>
                    </span>
                    <div>
                        <h2 class="text-lg font-black text-amber-950 flex items-center gap-2 flex-wrap">
                            ⚠️ Project Menunggu Pengaturan Jadwal
                            <span class="bg-amber-500 text-white text-xs font-black px-2.5 py-0.5 rounded-full shadow-xs">
                                <?= count($pending_clients) ?> Project Perlu Diatur
                            </span>
                        </h2>
                        <p class="text-xs text-amber-800 mt-0.5 font-medium">
                            Project otomatis dari LumaBooth yang <strong>belum memiliki Tanggal & Jam Acara</strong>. Diletakkan paling atas agar mudah diingat & di-tracking. (Tidak terlihat oleh tamu di Live Events sampai jadwal diatur).
                        </p>
                    </div>
                </div>
            </div>

            <div class="overflow-x-auto rounded-xl border border-amber-200 bg-white">
                <table class="min-w-full divide-y divide-amber-200">
                    <thead class="bg-amber-100/70">
                        <tr>
                            <th class="px-5 py-3.5 text-left text-xs font-bold text-amber-900 uppercase tracking-wider">Project Baru (LumaBooth)</th>
                            <th class="px-5 py-3.5 text-left text-xs font-bold text-blue-700 uppercase tracking-wider">Link Direct Tamu (QR)</th>
                            <th class="px-5 py-3.5 text-left text-xs font-bold text-amber-800 uppercase tracking-wider">Link Khusus Klien (VIP ZIP)</th>
                            <th class="px-5 py-3.5 text-right text-xs font-bold text-slate-600 uppercase tracking-wider">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-amber-100 bg-white">
                        <?php foreach($pending_clients as $c): 
                            renderClientRow($c, $base_url, true);
                        endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- 2. Bagian Utama: Daftar Event Terjadwal -->
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 mb-5">
                <div>
                    <h2 class="text-xl font-extrabold text-slate-900 flex items-center gap-2">
                        <span>📅 Daftar Event Terjadwal</span>
                        <span class="bg-emerald-100 text-emerald-800 text-xs font-bold px-2.5 py-0.5 rounded-full border border-emerald-200">
                            Aktif / Terjadwal
                        </span>
                    </h2>
                    <p class="text-xs text-slate-500 mt-0.5">Event yang sudah memiliki jadwal resmi dan tampil di Live Events saat waktu acara tiba.</p>
                </div>
                <span class="self-start sm:self-auto bg-slate-100 text-slate-700 text-xs font-bold px-3 py-1 rounded-full border border-slate-200">
                    Total: <?= count($scheduled_clients) ?> Event Terjadwal
                </span>
            </div>

            <div class="overflow-x-auto rounded-xl border border-slate-200">
                <table class="min-w-full divide-y divide-slate-200 bg-white">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-5 py-3.5 text-left text-xs font-bold text-slate-600 uppercase tracking-wider">Klien & Acara</th>
                            <th class="px-5 py-3.5 text-left text-xs font-bold text-blue-700 uppercase tracking-wider">Link Direct Tamu (QR)</th>
                            <th class="px-5 py-3.5 text-left text-xs font-bold text-amber-800 uppercase tracking-wider">Link Khusus Klien (VIP ZIP)</th>
                            <th class="px-5 py-3.5 text-right text-xs font-bold text-slate-600 uppercase tracking-wider">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        <?php if (empty($scheduled_clients)): ?>
                            <tr>
                                <td colspan="4" class="px-6 py-12 text-center text-slate-400 text-sm">
                                    Belum ada event terjadwal. Silahkan tambahkan event baru di atas atau atur tanggal untuk project yang ada di atas.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach($scheduled_clients as $c): 
                                renderClientRow($c, $base_url, false);
                            endforeach; ?>
                        <?php endif; ?>
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
                btn.innerText = '✓ Tersalin!';
                const origBg = btn.className;
                btn.className = btn.className.replace(/bg-\w+-\d+/, 'bg-emerald-600').replace(/hover:bg-\w+-\d+/, 'hover:bg-emerald-700');
                setTimeout(() => {
                    btn.innerText = orig;
                    btn.className = origBg;
                }, 1500);
            });
        }

        // Fitur Salin Pesan Otomatis Tamu (WhatsApp)
        function copyGuestMsg(btn) {
            const name = btn.getAttribute('data-name');
            const link = btn.getAttribute('data-link');
            const msg = `Hai Kak, terima kasih telah menghubungi YUHU Photobooth! ✨\n\nTerlampir kami kirimkan link galeri foto acara *${name}*:\n🔗 ${link}\n\nApabila ada kendala atau kesulitan dalam mengunduh foto, silahkan menghubungi kami kembali ya Kak. Terima kasih! 🙏😊`;

            navigator.clipboard.writeText(msg).then(() => {
                const span = btn.querySelector('span');
                const orig = span ? span.innerText : btn.innerText;
                if (span) span.innerText = '✓ Pesan Tamu Tersalin!';
                btn.classList.add('bg-emerald-100', 'text-emerald-800', 'border-emerald-300');
                setTimeout(() => {
                    if (span) span.innerText = orig;
                    btn.classList.remove('bg-emerald-100', 'text-emerald-800', 'border-emerald-300');
                }, 1800);
            });
        }

        // Fitur Salin Pesan Otomatis VIP Klien (WhatsApp)
        function copyVipMsg(btn) {
            const name = btn.getAttribute('data-name');
            const link = btn.getAttribute('data-link');
            const msg = `Halo Kak *${name}*, terima kasih banyak telah mempercayakan momen bahagianya bersama YUHU Photobooth! ✨\n\nBerikut kami kirimkan Link VIP Khusus Klien untuk mengunduh seluruh file foto & video acara secara lengkap (All Media ZIP):\n👑 ${link}\n\nSemoga hasilnya berkesan dan menjadi kenangan yang indah. Apabila ada hal yang ingin ditanyakan, silahkan menghubungi kami kembali ya Kak. Terima kasih banyak! 🙏🎉`;

            navigator.clipboard.writeText(msg).then(() => {
                const span = btn.querySelector('span');
                const orig = span ? span.innerText : btn.innerText;
                if (span) span.innerText = '✓ Pesan VIP Tersalin!';
                btn.classList.add('bg-emerald-100', 'text-emerald-800', 'border-emerald-300');
                setTimeout(() => {
                    if (span) span.innerText = orig;
                    btn.classList.remove('bg-emerald-100', 'text-emerald-800', 'border-emerald-300');
                }, 1800);
            });
        }
    </script>
</body>
</html>
