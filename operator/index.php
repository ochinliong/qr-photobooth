<?php
session_start();
require_once '../config.php';
require_once __DIR__ . '/../lumabooth_sync.php';

// Konfigurasi Keamanan PIN (PIN: 8080)
// Di-hash menggunakan SHA-256 agar tidak bisa dibaca langsung (anti-hack)
$secured_pin_hash = '6c237681e70921603a306be9a1a5d9833fce5c1e268f52b1650970eaad0dce21';

// Cek Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit;
}

// Cek Login PIN
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['operator_pin'])) {
    if (hash('sha256', $_POST['operator_pin']) === $secured_pin_hash) {
        $_SESSION['operator_logged_in'] = true;
        header("Location: index.php");
        exit;
    } else {
        $error_msg = "PIN yang Anda masukkan salah!";
    }
}

// Jika belum login, tampilkan form PIN
if (!isset($_SESSION['operator_logged_in']) || $_SESSION['operator_logged_in'] !== true) {
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Login Operator</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap');
        body { font-family: 'Inter', sans-serif; }
        /* Animasi Shake untuk Error */
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
            20%, 40%, 60%, 80% { transform: translateX(5px); }
        }
        .shake-animation { animation: shake 0.5s; }
    </style>
</head>
<body class="bg-slate-50 flex items-center justify-center min-h-screen p-4">
    <div class="w-full max-w-sm bg-white rounded-3xl shadow-xl p-8 border border-slate-100 text-center <?= isset($error_msg) ? 'shake-animation' : '' ?>">
        <div class="mx-auto bg-blue-50 w-16 h-16 rounded-full flex items-center justify-center mb-6 border-4 border-white shadow-sm">
            <svg class="w-8 h-8 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
        </div>
        <h2 class="text-2xl font-extrabold text-slate-800 mb-2">Akses Operator</h2>
        <p class="text-sm text-slate-500 mb-8">Masukkan PIN Keamanan Anda</p>
        
        <?php if(isset($error_msg)): ?>
            <div class="bg-red-50 text-red-600 text-xs font-bold px-4 py-2 rounded-lg mb-6 inline-block w-full">
                <?= $error_msg ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="mb-8">
                <input type="password" name="operator_pin" inputmode="numeric" pattern="[0-9]*" maxlength="4" placeholder="••••" required autofocus
                    class="w-full text-center text-3xl font-bold tracking-[1em] text-slate-800 border-b-2 border-slate-200 focus:border-blue-500 bg-transparent py-2 outline-none transition-colors">
            </div>
            <button type="submit" class="w-full bg-slate-800 hover:bg-slate-700 text-white font-bold py-3.5 rounded-xl transition-all shadow-md active:scale-95">
                Buka Panel
            </button>
        </form>
    </div>
</body>
</html>
<?php
    exit;
}

// =========================================================================
// KODE DI BAWAH INI HANYA AKAN DIEKSEKUSI JIKA OPERATOR SUDAH LOGIN
// =========================================================================

// Helper format tanggal bahasa Indonesia
function formatTanggalIndo($dateStr) {
    if (empty($dateStr) || $dateStr === '0000-00-00') return '-';
    $date = date_create($dateStr);
    $hari = ['Sunday' => 'Minggu', 'Monday' => 'Senin', 'Tuesday' => 'Selasa', 'Wednesday' => 'Rabu', 'Thursday' => 'Kamis', 'Friday' => 'Jumat', 'Saturday' => 'Sabtu'];
    $bulan = ['01' => 'Jan', '02' => 'Feb', '03' => 'Mar', '04' => 'Apr', '05' => 'Mei', '06' => 'Jun', '07' => 'Jul', '08' => 'Agu', '09' => 'Sep', '10' => 'Okt', '11' => 'Nov', '12' => 'Des'];
    
    $hari_nama = $hari[date_format($date, 'l')];
    $tgl = date_format($date, 'd');
    $bln = $bulan[date_format($date, 'm')];
    $thn = date_format($date, 'Y');
    
    return "$hari_nama, $tgl $bln $thn";
}

// Cek apakah sedang melihat foto spesifik satu klien (?client=...)
$viewing_client = trim($_GET['client'] ?? '');

if (!empty($viewing_client)) {
    // Cari data client dari Database
    $client_row = null;
    $req_clean = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $viewing_client));
    try {
        $stmt_c = $pdo->query("SELECT * FROM clients ORDER BY id DESC");
        foreach ($stmt_c->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $c_name = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $row['nama_client']));
            $c_slug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $row['slug']));
            $c_folder = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', extractLumaboothFolderFromUrl($row['folder_foto_path'] ?? '')));
            if ($req_clean !== '' && ($c_name === $req_clean || $c_slug === $req_clean || ($c_folder !== '' && $c_folder === $req_clean))) {
                $client_row = $row;
                break;
            }
        }
    } catch (Exception $e) {}

    $photos_root = __DIR__ . '/../client/photos';
    $resolved_folder = $viewing_client;
    if (!is_dir($photos_root . '/' . $resolved_folder)) {
        if (is_dir($photos_root)) {
            foreach (@scandir($photos_root) ?: [] as $d) {
                if ($d !== '.' && $d !== '..' && is_dir($photos_root . '/' . $d)) {
                    if (strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $d)) === $req_clean) {
                        $resolved_folder = $d;
                        break;
                    }
                }
            }
        }
    }
    $target_dir = $photos_root . '/' . $resolved_folder;

    $display_title = $client_row ? $client_row['nama_client'] : str_replace('_', ' ', $resolved_folder);
    $display_lokasi = $client_row ? ($client_row['lokasi'] ?? '') : '';

    // Baca file foto di target_dir
    $files_data = [];
    if (is_dir($target_dir)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target_dir));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $fname = $file->getFilename();
                if (strpos($fname, '.part_') !== false || strpos($fname, '.cache_') === 0 || $fname === 'config.json' || substr($fname, -5) === '.lock') {
                    continue;
                }
                $ext = strtolower($file->getExtension());
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'mov', 'webm'])) {
                    $path = str_replace('\\', '/', $file->getPathname());
                    $target_norm = str_replace('\\', '/', $target_dir);
                    $rel_path = ltrim(substr($path, strlen($target_norm)), '/');
                    $rel_lower = strtolower($rel_path);

                    if (strpos($rel_lower, 'thumb') !== false || strpos($rel_lower, 'settings') !== false || strpos($rel_lower, 'template') !== false) {
                        continue;
                    }
                    if (strpos($rel_lower, 'animated/') !== false && in_array($ext, ['jpg', 'jpeg', 'png'])) {
                        continue;
                    }

                    $encoded_client = rawurlencode($resolved_folder);
                    $encoded_rel_path = implode('/', array_map('rawurlencode', explode('/', $rel_path)));

                    $files_data[] = [
                        'file' => $rel_path,
                        'path' => '../client/photos/' . $encoded_client . '/' . $encoded_rel_path,
                        'web_url' => rtrim($base_url, '/') . '/client/?client=' . rawurlencode($resolved_folder) . '&single_file=' . rawurlencode($rel_path),
                        'ext'  => $ext,
                        'time' => $file->getMTime()
                    ];
                }
            }
        }
    }

    // Kelompokkan Sesi (Anchor-based <= 45 detik)
    $sessions_map = [];
    $anchor_timestamps = [];
    $extractTs = function($fname, $fallback) {
        if (preg_match('/(\d{4})(\d{2})(\d{2})_(\d{2})(\d{2})(\d{2})/', $fname, $m)) {
            return strtotime("{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:{$m[6]}");
        }
        return $fallback;
    };

    foreach ($files_data as $data) {
        $p_lower = strtolower($data['file']);
        $fn = basename($data['file']);
        $ext = $data['ext'];

        if (strpos($p_lower, 'prints/') !== false || strpos($p_lower, 'animated/') !== false || in_array($ext, ['mp4', 'gif', 'mov', 'webm'])) {
            $base_id = preg_match('/^(\d{8}_\d{6}_\d{3})/', $fn, $matches) ? $matches[1] : preg_replace('/(_mp4|_gif|\.[a-zA-Z0-9]+)$/i', '', $fn);
            if (!isset($sessions_map[$base_id])) {
                $ts = $extractTs($fn, $data['time']);
                $sessions_map[$base_id] = [
                    'id' => 'session_' . $base_id,
                    'time' => $ts,
                    'files' => []
                ];
                $anchor_timestamps[$base_id] = $ts;
            }
        }
    }

    foreach ($files_data as $data) {
        $fn = basename($data['file']);
        $ts = $extractTs($fn, $data['time']);
        $base_id = preg_match('/^(\d{8}_\d{6}_\d{3})/', $fn, $matches) ? $matches[1] : preg_replace('/(_mp4|_gif|\.[a-zA-Z0-9]+)$/i', '', $fn);

        if (isset($sessions_map[$base_id])) {
            $sessions_map[$base_id]['files'][] = $data;
            continue;
        }

        $best_aid = null;
        $min_diff = 999999;
        foreach ($anchor_timestamps as $aid => $ats) {
            $diff = abs($ats - $ts);
            if ($diff <= 45 && $diff < $min_diff) {
                $min_diff = $diff;
                $best_aid = $aid;
            }
        }

        if ($best_aid !== null) {
            $sessions_map[$best_aid]['files'][] = $data;
        } else {
            if (!isset($sessions_map[$base_id])) {
                $sessions_map[$base_id] = ['id' => 'session_' . $base_id, 'time' => $ts, 'files' => [$data]];
            } else {
                $sessions_map[$base_id]['files'][] = $data;
            }
        }
    }

    usort($sessions_map, function($a, $b) {
        return $b['time'] - $a['time'];
    });

    // Urutkan file di setiap sesi: Prints pertama, Video/Animated kedua
    foreach ($sessions_map as &$session) {
        usort($session['files'], function($a, $b) {
            $getPri = function($f) {
                $pl = strtolower($f['file']);
                $ex = strtolower($f['ext']);
                if (strpos($pl, 'prints/') !== false) return 4;
                if (in_array($ex, ['mp4', 'mov', 'webm'])) return 3;
                if ($ex === 'gif') return 2;
                return 1;
            };
            return $getPri($b) - $getPri($a);
        });
    }
    unset($session);
    $sessions = array_values($sessions_map);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>QR Foto Per-File - <?= htmlspecialchars($display_title) ?></title>
    <link rel="icon" href="https://qr.yuhu.co.id/2025/icon.png" type="image/png">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&family=Poppins:wght@700&display=swap');
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body class="bg-slate-50 min-h-screen flex flex-col pb-16">

    <!-- Header Navbar -->
    <div class="bg-white border-b border-slate-200 sticky top-0 z-40 shadow-xs">
        <div class="max-w-5xl mx-auto px-4 py-3 flex items-center justify-between">
            <a href="index.php" class="inline-flex items-center gap-1.5 text-xs font-bold text-slate-700 bg-slate-100 hover:bg-slate-200 px-3 py-1.5 rounded-lg transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
                <span>Daftar Acara</span>
            </a>
            <div class="text-center truncate px-2">
                <h1 class="font-extrabold text-slate-800 text-sm md:text-base truncate"><?= htmlspecialchars($display_title) ?></h1>
                <p class="text-[11px] text-blue-600 font-semibold truncate"><?= count($sessions) ?> Sesi Foto • <?= count($files_data) ?> Media</p>
            </div>
            <a href="../client/?client=<?= rawurlencode($resolved_folder) ?>" target="_blank" class="inline-flex items-center gap-1 text-xs font-bold text-emerald-700 bg-emerald-50 hover:bg-emerald-100 border border-emerald-200 px-3 py-1.5 rounded-lg transition">
                <span>Galeri ↗</span>
            </a>
        </div>
    </div>

    <!-- Main Content: Photo Grid -->
    <div class="max-w-5xl mx-auto w-full px-4 pt-6">

        <div class="bg-blue-50 border border-blue-200 rounded-2xl p-4 mb-6 text-center">
            <h2 class="text-sm font-bold text-blue-950 flex items-center justify-center gap-1.5">
                <span>📱 Mode Operator: QR Code Per-File Foto</span>
            </h2>
            <p class="text-xs text-blue-800 mt-1">
                Klik tombol <strong>"📱 Tampilkan QR"</strong> pada foto tamu yang diinginkan. Layar akan menampilkan QR Code dengan animasi smooth yang khusus mengunduh file foto tersebut saja tanpa memperlihatkan foto tamu lain.
            </p>
        </div>

        <?php if (empty($sessions)): ?>
            <div class="text-center py-16 bg-white rounded-2xl border border-slate-200 shadow-sm p-8">
                <div class="text-4xl mb-3">📷</div>
                <h3 class="font-bold text-slate-700 text-base mb-1">Belum Ada Foto Tersimpan</h3>
                <p class="text-xs text-slate-500">Folder foto untuk acara ini masih kosong atau belum disinkronkan dari LumaBooth PC.</p>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-4">
                <?php foreach ($sessions as $sIndex => $sess): 
                    $cover = $sess['files'][0];
                    $time_str = date('H:i:s', $sess['time']);
                    $is_vid = in_array($cover['ext'], ['mp4', 'mov', 'webm']);
                ?>
                    <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden shadow-sm flex flex-col hover:shadow-md transition">
                        <!-- Media Preview -->
                        <div class="relative bg-slate-900 aspect-[3/4] flex items-center justify-center overflow-hidden cursor-pointer" onclick="openQrForFile('<?= htmlspecialchars($cover['web_url'], ENT_QUOTES) ?>', '<?= htmlspecialchars($cover['path'], ENT_QUOTES) ?>', 'Sesi <?= $time_str ?> WIB')">
                            <?php if ($is_vid): ?>
                                <video src="<?= htmlspecialchars($cover['path']) ?>" muted loop autoplay playsinline class="w-full h-full object-cover"></video>
                                <span class="absolute top-2 right-2 bg-black/60 text-white text-[10px] font-bold px-2 py-0.5 rounded-full">▶ Video</span>
                            <?php else: ?>
                                <img src="<?= htmlspecialchars($cover['path']) ?>" alt="Foto" loading="lazy" class="w-full h-full object-cover">
                            <?php endif; ?>

                            <div class="absolute bottom-2 left-2 bg-black/60 backdrop-blur-xs text-white text-[10px] font-bold px-2 py-0.5 rounded-md">
                                🕒 <?= $time_str ?>
                            </div>
                            <div class="absolute bottom-2 right-2 bg-blue-600/90 text-white text-[10px] font-bold px-2 py-0.5 rounded-md">
                                <?= count($sess['files']) ?> Media
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="p-2.5 flex flex-col gap-1.5 flex-grow justify-between bg-white">
                            <!-- Main QR Button for Cover -->
                            <button type="button" onclick="openQrForFile('<?= htmlspecialchars($cover['web_url'], ENT_QUOTES) ?>', '<?= htmlspecialchars($cover['path'], ENT_QUOTES) ?>', 'Foto/Cetak - <?= $time_str ?>')" class="w-full bg-blue-600 hover:bg-blue-700 active:scale-98 text-white font-bold text-xs py-2 px-2.5 rounded-xl transition shadow-xs flex items-center justify-center gap-1.5">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"></path></svg>
                                <span>📱 QR Foto Ini</span>
                            </button>

                            <!-- If multiple media (e.g. video), show button for video too -->
                            <?php if (count($sess['files']) > 1): ?>
                                <?php foreach (array_slice($sess['files'], 1) as $subFile): 
                                    $sub_is_vid = in_array($subFile['ext'], ['mp4', 'mov', 'webm']);
                                    $sub_label = $sub_is_vid ? '🎬 QR Video Animasi' : '📸 QR File Alternatif';
                                ?>
                                    <button type="button" onclick="openQrForFile('<?= htmlspecialchars($subFile['web_url'], ENT_QUOTES) ?>', '<?= htmlspecialchars($subFile['path'], ENT_QUOTES) ?>', '<?= $sub_label ?> - <?= $time_str ?>')" class="w-full bg-purple-50 hover:bg-purple-100 text-purple-700 border border-purple-200 font-bold text-[11px] py-1.5 px-2 rounded-lg transition flex items-center justify-center gap-1">
                                        <span><?= $sub_label ?></span>
                                    </button>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </div>

    <!-- Smooth Popup QR Code Modal -->
    <div id="smoothQrModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm opacity-0 pointer-events-none transition-all duration-300" onclick="closeSmoothQrModal(event)">
        <div id="smoothQrCard" class="bg-white rounded-3xl p-6 max-w-sm w-full text-center transform scale-95 transition-all duration-300 shadow-2xl relative" onclick="event.stopPropagation()">
            <!-- Close Button -->
            <button onclick="closeSmoothQrModal()" class="absolute top-4 right-4 text-slate-400 hover:text-slate-600 bg-slate-100 hover:bg-slate-200 p-2 rounded-full transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>

            <div class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-blue-50 text-blue-700 font-bold text-xs mb-3 border border-blue-200">
                <span>📱 QR Code File Tamu</span>
            </div>

            <h3 id="modalFileTitle" class="font-extrabold text-slate-900 text-base mb-1">Unduh Foto Ini</h3>
            <p class="text-xs text-slate-500 mb-4 leading-relaxed">
                Arahkan kamera HP tamu ke QR Code di bawah untuk langsung membuka & menyimpan file ini saja.
            </p>

            <!-- Media Preview -->
            <div class="w-24 h-24 mx-auto rounded-xl overflow-hidden bg-slate-100 mb-4 border border-slate-200 shadow-inner flex items-center justify-center">
                <img id="modalFileThumb" src="" alt="Thumbnail" class="w-full h-full object-cover">
            </div>

            <!-- QR Code Image -->
            <div class="bg-white p-3 rounded-2xl border-2 border-slate-200 shadow-sm inline-block mb-4">
                <img id="modalQrImg" src="" alt="QR Code" class="w-48 h-48 mx-auto block rounded-lg">
            </div>

            <!-- Action Buttons -->
            <div class="space-y-2">
                <button id="btnCopyFileUrl" onclick="copySmoothQrUrl(this)" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-2.5 px-4 rounded-xl text-xs transition shadow-md flex items-center justify-center gap-1.5">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"></path></svg>
                    <span>Salin Link File Ini</span>
                </button>
                <a id="btnPreviewFileUrl" href="#" target="_blank" class="w-full bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold py-2 px-4 rounded-xl text-xs transition border border-slate-200 flex items-center justify-center gap-1.5">
                    <span>👁️ Buka Tampilan Tamu</span>
                </a>
            </div>
        </div>
    </div>

    <script>
        let activeFileUrl = '';

        function openQrForFile(fileUrl, thumbUrl, title) {
            activeFileUrl = fileUrl;
            const modal = document.getElementById('smoothQrModal');
            const card = document.getElementById('smoothQrCard');
            const qrImg = document.getElementById('modalQrImg');
            const thumbImg = document.getElementById('modalFileThumb');
            const titleEl = document.getElementById('modalFileTitle');
            const previewBtn = document.getElementById('btnPreviewFileUrl');

            if (titleEl) titleEl.innerText = title || 'Unduh Foto Ini';
            if (thumbImg) thumbImg.src = thumbUrl;
            if (previewBtn) previewBtn.href = fileUrl;

            // Generate Sharp QR Code
            const qrApi = 'https://api.qrserver.com/v1/create-qr-code/?size=320x320&data=' + encodeURIComponent(fileUrl);
            if (qrImg) qrImg.src = qrApi;

            modal.classList.remove('opacity-0', 'pointer-events-none');
            modal.classList.add('opacity-100', 'pointer-events-auto');
            if (card) {
                card.classList.remove('scale-95');
                card.classList.add('scale-100');
            }
            document.body.style.overflow = 'hidden';
        }

        function closeSmoothQrModal() {
            const modal = document.getElementById('smoothQrModal');
            const card = document.getElementById('smoothQrCard');
            if (card) {
                card.classList.remove('scale-100');
                card.classList.add('scale-95');
            }
            if (modal) {
                modal.classList.remove('opacity-100', 'pointer-events-auto');
                modal.classList.add('opacity-0', 'pointer-events-none');
            }
            document.body.style.overflow = '';
        }

        function copySmoothQrUrl(btn) {
            if (!activeFileUrl) return;
            navigator.clipboard.writeText(activeFileUrl).then(() => {
                const span = btn.querySelector('span');
                const orig = span.innerText;
                span.innerText = 'Link File Tersalin!';
                btn.classList.replace('bg-blue-600', 'bg-emerald-600');
                setTimeout(() => {
                    span.innerText = orig;
                    btn.classList.replace('bg-emerald-600', 'bg-blue-600');
                }, 2000);
            });
        }

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeSmoothQrModal();
        });
    </script>
</body>
</html>
<?php
    exit;
}

// =========================================================================
// TAMPILAN UTAMA OPERATOR: DAFTAR SELURUH EVENT
// =========================================================================

// Ambil semua data klien, urutkan dari tanggal terbaru, lalu jam mulai
$stmt = $pdo->query("SELECT * FROM clients ORDER BY tanggal_event DESC, jam_mulai DESC");
$all_clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Kelompokkan berdasarkan tanggal
$grouped_clients = [];
foreach ($all_clients as $c) {
    $date = $c['tanggal_event'] ?: '0000-00-00';
    if (!isset($grouped_clients[$date])) {
        $grouped_clients[$date] = [];
    }
    $grouped_clients[$date][] = $c;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Operator Panel - YUHU Photobooth</title>
    <link rel="icon" href="https://qr.yuhu.co.id/2025/icon.png" type="image/png">
    <link rel="apple-touch-icon" href="https://qr.yuhu.co.id/2025/icon.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap');
        body { 
            font-family: 'Inter', sans-serif; 
            touch-action: pan-y;
        }
    </style>
</head>
<body class="bg-slate-50 flex flex-col min-h-screen">
    <!-- Navbar / Header -->
    <div class="bg-white shadow-[0_2px_10px_-3px_rgba(6,81,237,0.1)] sticky top-0 z-50">
        <div class="max-w-3xl mx-auto px-5 py-3.5 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <img src="https://qr.yuhu.co.id/2025/icon.png" class="w-9 h-9 rounded-md shadow-sm" alt="Logo">
                <div>
                    <h1 class="font-extrabold text-slate-800 leading-tight text-lg tracking-tight">Operator Panel</h1>
                    <p class="text-[10px] text-blue-600 font-bold uppercase tracking-widest">Internal Use Only</p>
                </div>
            </div>
            <div class="flex gap-2">
                <a href="../" class="p-2 text-slate-400 hover:text-slate-700 bg-slate-50 rounded-full transition-colors" title="Ke Halaman Utama">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path></svg>
                </a>
                <a href="?logout=1" class="p-2 text-red-400 hover:text-red-600 bg-red-50 rounded-full transition-colors" title="Logout">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path></svg>
                </a>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="flex-grow w-full max-w-3xl mx-auto p-4 space-y-6 mt-2 mb-10">
        
        <?php if (empty($grouped_clients)): ?>
            <div class="text-center py-12 text-slate-400 bg-white rounded-2xl border border-slate-100 shadow-sm">
                <svg class="mx-auto h-12 w-12 mb-3 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
                <p>Belum ada data event apapun di database.</p>
            </div>
        <?php else: ?>
            
            <?php foreach($grouped_clients as $date => $clients): ?>
                <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
                    <!-- Header Tanggal -->
                    <div class="bg-slate-800 text-white px-5 py-3 border-b border-slate-700 flex items-center justify-between">
                        <h2 class="font-bold tracking-wide text-sm flex items-center gap-2">
                            <svg class="w-4 h-4 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                            <?= formatTanggalIndo($date) ?>
                        </h2>
                        <span class="text-[10px] bg-slate-700 text-slate-300 px-2 py-1 rounded-md font-bold uppercase tracking-wider"><?= count($clients) ?> Event</span>
                    </div>
                    
                    <!-- List Event di Tanggal Tersebut -->
                    <div class="divide-y divide-slate-100">
                        <?php foreach($clients as $c): 
                            $year_c = (!empty($c['tanggal_event']) && $c['tanggal_event'] !== '0000-00-00') ? date('Y', strtotime($c['tanggal_event'])) : date('Y');
                            $clean_name_c = preg_replace('/\s+/', '', $c['nama_client']);
                            $direct_url_c = rtrim($base_url, '/') . '/' . $year_c . '/' . $clean_name_c;
                            $folder_c = extractLumaboothFolderFromUrl($c['folder_foto_path'] ?? '') ?: str_replace(' ', '_', $c['nama_client']);
                        ?>
                            <div class="p-5 hover:bg-slate-50 transition-colors">
                                <div class="flex justify-between items-start mb-2">
                                    <h3 class="font-bold text-slate-900 text-lg leading-tight pr-3"><?= htmlspecialchars($c['nama_client']) ?></h3>
                                    <?php if (!empty($c['jam_mulai']) && !empty($c['jam_selesai'])): ?>
                                    <span class="text-xs font-bold text-slate-500 bg-slate-100 px-2 py-1 rounded border border-slate-200 whitespace-nowrap">
                                        <?= substr($c['jam_mulai'], 0, 5) ?> - <?= substr($c['jam_selesai'], 0, 5) ?>
                                    </span>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="flex items-start text-xs text-slate-500 mb-2 mt-1">
                                    <svg class="w-3.5 h-3.5 mr-1.5 flex-shrink-0 text-red-500 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.242-4.243a8 8 0 1111.314 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                                    <div class="leading-relaxed whitespace-pre-line">
                                        <?= nl2br(htmlspecialchars($c['lokasi'] ?? '-')) ?>
                                    </div>
                                </div>

                                <?php if (!empty(trim($c['keterangan'] ?? ''))): ?>
                                <div class="flex items-start text-xs text-slate-600 mb-3 bg-amber-50/70 p-2 rounded-lg border border-amber-100">
                                    <span class="mr-1.5">📝</span>
                                    <div class="leading-relaxed whitespace-pre-line text-[11px] font-medium text-slate-700">
                                        <?= nl2br(htmlspecialchars(trim($c['keterangan']))) ?>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <div class="bg-blue-50/60 border border-blue-100 rounded-lg px-3 py-2 mb-4 text-xs text-blue-700 font-mono break-all">
                                    <?= htmlspecialchars($direct_url_c) ?>
                                </div>
                                
                                <!-- Action Buttons: 4 Tombol Bersih & Praktis -->
                                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                                    <!-- 1. Tombol QR Per-File (Fitur Baru) -->
                                    <a href="?client=<?= rawurlencode($folder_c) ?>" class="flex items-center justify-center bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold py-2.5 px-2 rounded-lg transition-colors shadow-sm gap-1.5">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"></path></svg>
                                        <span>QR Per-File</span>
                                    </a>

                                    <!-- 2. Tombol Buka Link QR Acara -->
                                    <a href="<?= htmlspecialchars($direct_url_c) ?>" target="_blank" class="flex items-center justify-center bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold py-2.5 px-2 rounded-lg transition-colors shadow-sm gap-1.5">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path></svg>
                                        <span>Link QR</span>
                                    </a>

                                    <!-- 3. Tombol Portal Gallery -->
                                    <a href="../client/?client=<?= rawurlencode($folder_c) ?>" target="_blank" class="flex items-center justify-center bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold py-2.5 px-2 rounded-lg transition-colors shadow-sm gap-1.5">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                                        <span>Galeri</span>
                                    </a>

                                    <!-- 4. Tombol Copy Link -->
                                    <button onclick="copyToClipboard('<?= htmlspecialchars($direct_url_c, ENT_QUOTES) ?>', this);" class="flex items-center justify-center bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold py-2.5 px-2 rounded-lg transition-colors border border-slate-200 shadow-sm gap-1.5">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"></path></svg>
                                        <span>Copy Link</span>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            
        <?php endif; ?>
    </div>
    
    <script>
        function copyToClipboard(text, btn) {
            navigator.clipboard.writeText(text).then(() => {
                const span = btn.querySelector('span');
                const originalText = span.innerText;
                span.innerText = 'Tersalin!';
                btn.classList.replace('bg-slate-100', 'bg-green-100');
                btn.classList.replace('text-slate-700', 'text-green-700');
                btn.classList.replace('border-slate-200', 'border-green-200');
                
                setTimeout(() => {
                    span.innerText = originalText;
                    btn.classList.replace('bg-green-100', 'bg-slate-100');
                    btn.classList.replace('text-green-700', 'text-slate-700');
                    btn.classList.replace('border-green-200', 'border-slate-200');
                }, 2000);
            }).catch(err => {
                alert('Gagal menyalin link: ' + err);
            });
        }
    </script>
</body>
</html>
