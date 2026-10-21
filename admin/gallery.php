<?php
require_once '../config.php';
require_once __DIR__ . '/../lumabooth_sync.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit;
}

$photos_base_dir = __DIR__ . '/../client/photos/';
if (!is_dir($photos_base_dir)) {
    @mkdir($photos_base_dir, 0777, true);
}

$all_possible_folders = ['Prints', 'Animated', 'Singles', 'Videos', 'Originals'];

// Ambil daftar semua klien dari database
$stmt_clients = $pdo->query("SELECT * FROM clients ORDER BY tanggal_event DESC, id DESC");
$db_clients = $stmt_clients->fetchAll(PDO::FETCH_ASSOC);

// Helper mencari nama folder fisik di client/photos/ untuk suatu baris client DB
function findPhysicalFolderForClient($c, $photos_base_dir) {
    $from_url = extractLumaboothFolderFromUrl($c['folder_foto_path'] ?? '');
    $candidates = [];
    if ($from_url !== '') $candidates[] = $from_url;
    $candidates[] = str_replace(' ', '_', $c['nama_client']);
    $candidates[] = $c['nama_client'];

    foreach ($candidates as $cand) {
        if ($cand !== '' && is_dir($photos_base_dir . $cand)) {
            return $cand;
        }
    }

    $clean_targets = [];
    foreach ($candidates as $cand) {
        $cl = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $cand));
        if ($cl !== '') $clean_targets[] = $cl;
    }

    if (is_dir($photos_base_dir)) {
        foreach (scandir($photos_base_dir) as $d) {
            if ($d === '.' || $d === '..') continue;
            if (is_dir($photos_base_dir . $d)) {
                $d_clean = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $d));
                if ($d_clean !== '' && in_array($d_clean, $clean_targets, true)) {
                    return $d;
                }
            }
        }
    }

    return $from_url !== '' ? $from_url : preg_replace('/[^A-Za-z0-9_\-&() ]/', '', str_replace(' ', '_', $c['nama_client']));
}

// Helper hitung jumlah foto di suatu folder event
function countEventMediaFiles($folder_path) {
    if (!is_dir($folder_path)) return 0;
    $cnt = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder_path));
    foreach ($it as $file) {
        if ($file->isFile()) {
            $ext = strtolower($file->getExtension());
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'mov', 'webm'])) {
                $rel = strtolower(str_replace('\\', '/', $file->getPathname()));
                if (strpos($rel, 'thumb') === false && strpos($rel, '.part_') === false && strpos($rel, 'settings') === false && strpos($rel, 'template') === false) {
                    // Abaikan thumbnail JPG pendamping MP4 di folder Animated
                    if (strpos($rel, '/animated/') !== false && in_array($ext, ['jpg', 'jpeg', 'png'])) continue;
                    $cnt++;
                }
            }
        }
    }
    return $cnt;
}

// Susun daftar event gabungan (dari Database + Folder Fisik)
$events_list = [];
$mapped_folders_lower = [];

foreach ($db_clients as $c) {
    $folder_name = findPhysicalFolderForClient($c, $photos_base_dir);
    $mapped_folders_lower[] = strtolower($folder_name);
    $media_count = countEventMediaFiles($photos_base_dir . $folder_name);
    $year_c = date('Y', strtotime($c['tanggal_event']));
    $clean_name_c = preg_replace('/\s+/', '', $c['nama_client']);
    $direct_link = rtrim($base_url, '/') . '/' . $year_c . '/' . $clean_name_c;

    $events_list[] = [
        'id' => (int)$c['id'],
        'nama_client' => $c['nama_client'],
        'folder' => $folder_name,
        'tanggal_event' => $c['tanggal_event'],
        'jam_mulai' => $c['jam_mulai'],
        'jam_selesai' => $c['jam_selesai'],
        'lokasi' => $c['lokasi'],
        'direct_link' => $direct_link,
        'gallery_url' => rtrim($base_url, '/') . '/client/' . rawurlencode($folder_name),
        'media_count' => $media_count
    ];
}

// Tentukan event yang sedang dipilih
$selected_event = null;
$req_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$req_folder = isset($_GET['client']) ? trim(str_replace(['..', '/', '\\', "\0"], '', $_GET['client'])) : '';

if ($req_id > 0) {
    foreach ($events_list as $ev) {
        if ($ev['id'] === $req_id) {
            $selected_event = $ev;
            break;
        }
    }
} elseif ($req_folder !== '') {
    $rf_clean = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $req_folder));
    foreach ($events_list as $ev) {
        if (strtolower($ev['folder']) === strtolower($req_folder) || strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $ev['folder'])) === $rf_clean) {
            $selected_event = $ev;
            break;
        }
    }
}

// Jika belum ada yang dipilih, otomatis pilih event terbaru yang memiliki foto (atau event pertama)
if (!$selected_event && !empty($events_list)) {
    foreach ($events_list as $ev) {
        if ($ev['media_count'] > 0) {
            $selected_event = $ev;
            break;
        }
    }
    if (!$selected_event) {
        $selected_event = $events_list[0];
    }
}

$selected_folder = $selected_event ? $selected_event['folder'] : '';
$selected_dir_path = $selected_folder !== '' ? $photos_base_dir . $selected_folder : '';

function getEventGalleryConfig($config_path) {
    if (file_exists($config_path)) {
        return json_decode(file_get_contents($config_path), true) ?: [];
    }
    return [];
}

function saveEventGalleryConfig($config_path, $data) {
    $dir = dirname($config_path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    file_put_contents($config_path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

// ============================================================================
// HANDLE POST ACTIONS (Hide, Unhide, Delete, Bulk Actions, Settings, Upload)
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $selected_folder !== '') {
    $config_file = $selected_dir_path . '/config.json';
    $cfg = getEventGalleryConfig($config_file);
    $hidden = isset($cfg['hidden_files']) && is_array($cfg['hidden_files']) ? $cfg['hidden_files'] : [];

    // 1. Update Pengaturan Folder & Judul Galeri
    if (isset($_POST['update_gallery_settings'])) {
        $cfg['title'] = trim($_POST['custom_title'] ?? '');
        $cfg['subtitle'] = trim($_POST['custom_subtitle'] ?? '');
        $cfg['visible_folders'] = isset($_POST['visible_folders']) && is_array($_POST['visible_folders']) ? $_POST['visible_folders'] : [];
        saveEventGalleryConfig($config_file, $cfg);
        $flash_msg = "Pengaturan tampilan galeri berhasil disimpan!";
    }

    // 2. Upload Manual File Baru
    if (isset($_POST['upload_manual'])) {
        $target_sub = preg_replace('/[^A-Za-z0-9]/', '', $_POST['upload_target'] ?? 'Prints');
        if ($target_sub === '') $target_sub = 'Prints';
        $upload_dir = $selected_dir_path . '/' . $target_sub . '/';
        if (!is_dir($upload_dir)) @mkdir($upload_dir, 0777, true);

        $uploaded_cnt = 0;
        if (isset($_FILES['new_files']['name']) && is_array($_FILES['new_files']['name'])) {
            for ($i = 0; $i < count($_FILES['new_files']['name']); $i++) {
                if ($_FILES['new_files']['error'][$i] === UPLOAD_ERR_OK) {
                    $safe_name = preg_replace('/[^a-zA-Z0-9_.\-& ]/', '', basename($_FILES['new_files']['name'][$i]));
                    if ($safe_name !== '' && move_uploaded_file($_FILES['new_files']['tmp_name'][$i], $upload_dir . $safe_name)) {
                        $uploaded_cnt++;
                    }
                }
            }
        }
        $flash_msg = $uploaded_cnt > 0 ? "Berhasil mengupload $uploaded_cnt file ke folder $target_sub!" : "Tidak ada file yang berhasil diupload.";
    }

    // 3. Toggle Sembunyikan / Tampilkan 1 File
    if (isset($_POST['toggle_hide_file']) && !empty($_POST['filename'])) {
        $fn = str_replace('\\', '/', $_POST['filename']);
        if (in_array($fn, $hidden, true)) {
            $hidden = array_values(array_diff($hidden, [$fn]));
            $flash_msg = "Foto berhasil ditampilkan kembali ke galeri tamu!";
        } else {
            $hidden[] = $fn;
            $flash_msg = "Foto berhasil disembunyikan dari galeri tamu!";
        }
        $cfg['hidden_files'] = array_values(array_unique($hidden));
        saveEventGalleryConfig($config_file, $cfg);
    }

    // 4. Hapus 1 File Permanen
    if (isset($_POST['delete_single_file']) && !empty($_POST['filename'])) {
        $fn = str_replace(['..', '\\'], ['', '/'], $_POST['filename']);
        $fp = $selected_dir_path . '/' . ltrim($fn, '/');
        if (file_exists($fp) && is_file($fp)) {
            @unlink($fp);
            $hidden = array_values(array_diff($hidden, [$fn]));
            $cfg['hidden_files'] = $hidden;
            saveEventGalleryConfig($config_file, $cfg);
            $flash_msg = "File berhasil dihapus permanen!";
        }
    }

    // 5. Aksi Per Sesi (Sembunyikan Sesi / Tampilkan Sesi / Hapus Sesi)
    if (!empty($_POST['session_files'])) {
        $sess_files = json_decode($_POST['session_files'], true);
        if (is_array($sess_files)) {
            if (isset($_POST['hide_session'])) {
                foreach ($sess_files as $fn) {
                    $fn = str_replace('\\', '/', $fn);
                    if (!in_array($fn, $hidden, true)) $hidden[] = $fn;
                }
                $cfg['hidden_files'] = array_values(array_unique($hidden));
                saveEventGalleryConfig($config_file, $cfg);
                $flash_msg = "Seluruh foto pada sesi tersebut berhasil disembunyikan!";
            } elseif (isset($_POST['unhide_session'])) {
                $hidden = array_values(array_diff($hidden, $sess_files));
                $cfg['hidden_files'] = $hidden;
                saveEventGalleryConfig($config_file, $cfg);
                $flash_msg = "Seluruh foto pada sesi tersebut berhasil ditampilkan kembali!";
            } elseif (isset($_POST['delete_session'])) {
                $del_cnt = 0;
                foreach ($sess_files as $fn) {
                    $fn = str_replace(['..', '\\'], ['', '/'], $fn);
                    $fp = $selected_dir_path . '/' . ltrim($fn, '/');
                    if (file_exists($fp) && is_file($fp)) {
                        @unlink($fp);
                        $del_cnt++;
                    }
                }
                $hidden = array_values(array_diff($hidden, $sess_files));
                $cfg['hidden_files'] = $hidden;
                saveEventGalleryConfig($config_file, $cfg);
                $flash_msg = "Sesi berhasil dihapus ($del_cnt file dihapus permanen)!";
            }
        }
    }

    // 6. Bulk Actions (Sembunyikan Terpilih / Tampilkan Terpilih / Hapus Terpilih)
    if (!empty($_POST['bulk_files']) && is_array($_POST['bulk_files'])) {
        $b_files = array_map(function($f) { return str_replace('\\', '/', $f); }, $_POST['bulk_files']);
        if (isset($_POST['bulk_hide'])) {
            foreach ($b_files as $f) {
                if (!in_array($f, $hidden, true)) $hidden[] = $f;
            }
            $cfg['hidden_files'] = array_values(array_unique($hidden));
            saveEventGalleryConfig($config_file, $cfg);
            $flash_msg = count($b_files) . " file terpilih berhasil disembunyikan dari galeri tamu!";
        } elseif (isset($_POST['bulk_unhide'])) {
            $hidden = array_values(array_diff($hidden, $b_files));
            $cfg['hidden_files'] = $hidden;
            saveEventGalleryConfig($config_file, $cfg);
            $flash_msg = count($b_files) . " file terpilih berhasil ditampilkan kembali!";
        } elseif (isset($_POST['bulk_delete'])) {
            $del_cnt = 0;
            foreach ($b_files as $fn) {
                $fn_clean = str_replace('..', '', $fn);
                $fp = $selected_dir_path . '/' . ltrim($fn_clean, '/');
                if (file_exists($fp) && is_file($fp)) {
                    @unlink($fp);
                    $del_cnt++;
                }
            }
            $hidden = array_values(array_diff($hidden, $b_files));
            $cfg['hidden_files'] = $hidden;
            saveEventGalleryConfig($config_file, $cfg);
            $flash_msg = "Berhasil menghapus permanen $del_cnt file terpilih!";
        }
    }

    // 7. Hapus Semua Media Event Ini
    if (isset($_POST['delete_all_files']) && is_dir($selected_dir_path)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($selected_dir_path), RecursiveIteratorIterator::CHILD_FIRST);
        $del_cnt = 0;
        foreach ($it as $file) {
            if ($file->isFile()) {
                $ext = strtolower($file->getExtension());
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'mov', 'webm'])) {
                    @unlink($file->getPathname());
                    $del_cnt++;
                }
            }
        }
        $cfg['hidden_files'] = [];
        saveEventGalleryConfig($config_file, $cfg);
        $flash_msg = "Berhasil menghapus seluruh media ($del_cnt file) pada event ini!";
    }

    // Update jumlah media di $selected_event setelah aksi
    if ($selected_event) {
        $selected_event['media_count'] = countEventMediaFiles($selected_dir_path);
        foreach ($events_list as &$ev_ref) {
            if ($ev_ref['id'] === $selected_event['id']) {
                $ev_ref['media_count'] = $selected_event['media_count'];
            }
        }
        unset($ev_ref);
    }
}

// ============================================================================
// BACA KONFIGURASI & FILE MEDIA UNTUK EVENT YANG DIPILIH
// ============================================================================
$current_title = $selected_event ? $selected_event['nama_client'] : '';
$current_subtitle = ($selected_event && !empty($selected_event['lokasi']) && $selected_event['lokasi'] !== '-')
    ? str_replace(["\r\n", "\n"], ' • ', $selected_event['lokasi'])
    : "Terima kasih telah merayakan bersama kami!";
$current_visible = ['Prints', 'Singles', 'Animated', 'Videos'];
$current_hidden = [];

if ($selected_folder !== '') {
    $cfg_file = $selected_dir_path . '/config.json';
    if (file_exists($cfg_file)) {
        $json = getEventGalleryConfig($cfg_file);
        if (!empty($json['title'])) $current_title = $json['title'];
        if (!empty(trim($json['subtitle'] ?? ''))) $current_subtitle = $json['subtitle'];
        if (isset($json['visible_folders']) && is_array($json['visible_folders'])) $current_visible = $json['visible_folders'];
        if (isset($json['hidden_files']) && is_array($json['hidden_files'])) $current_hidden = $json['hidden_files'];
    }
}

$filter_status = $_GET['filter'] ?? 'all'; // all, visible, hidden

$files_data = [];
$total_hidden_count = 0;
$total_visible_count = 0;

if ($selected_folder !== '' && is_dir($selected_dir_path)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($selected_dir_path));
    foreach ($it as $file) {
        if ($file->isFile()) {
            $fname_only = $file->getFilename();
            if (strpos($fname_only, '.part_') !== false || $fname_only === 'config.json' || $fname_only === '.deleted_by_admin') continue;

            $ext = strtolower($file->getExtension());
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'mov', 'webm'])) {
                $path = str_replace('\\', '/', $file->getPathname());
                $base_norm = str_replace('\\', '/', $selected_dir_path);
                $rel = ltrim(substr($path, strlen($base_norm)), '/');

                $parts = explode('/', $rel);
                $pf = count($parts) > 1 ? $parts[0] : '';
                $rl = strtolower($rel);

                if (strpos($rl, 'thumb') !== false || strpos($rl, 'settings') !== false || strpos($rl, 'template') !== false) continue;
                if (strtolower($pf) === 'animated' && in_array($ext, ['jpg', 'jpeg', 'png'])) continue;

                $is_hidden = in_array($rel, $current_hidden, true);
                if ($is_hidden) {
                    $total_hidden_count++;
                } else {
                    $total_visible_count++;
                }

                if ($filter_status === 'visible' && $is_hidden) continue;
                if ($filter_status === 'hidden' && !$is_hidden) continue;

                $encoded_rel = implode('/', array_map('rawurlencode', explode('/', $rel)));
                $web_path = '../client/photos/' . rawurlencode($selected_folder) . '/' . $encoded_rel;

                $files_data[] = [
                    'file' => $rel,
                    'folder' => $pf ?: 'Root',
                    'path' => $web_path,
                    'ext' => $ext,
                    'time' => $file->getMTime(),
                    'is_hidden' => $is_hidden
                ];
            }
        }
    }
}

// Kelompokkan ke dalam Sesi Tamu (sama persis dengan tampilan galeri klien)
$sessions_map = [];
$anchor_ts = [];
$exTs = function($fn, $fb) {
    if (preg_match('/(\d{4})(\d{2})(\d{2})_(\d{2})(\d{2})(\d{2})/', $fn, $m)) {
        return strtotime("{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:{$m[6]}");
    }
    return $fb;
};

foreach ($files_data as $d) {
    $pl = strtolower($d['file']);
    $fn = basename($d['file']);
    if (strpos($pl, 'prints/') !== false || strpos($pl, 'animated/') !== false || strpos($pl, 'videos/') !== false || in_array($d['ext'], ['mp4', 'gif', 'mov', 'webm'])) {
        $bid = preg_match('/^(\d{8}_\d{6}_\d{3})/', $fn, $m) ? $m[1] : preg_replace('/(_mp4|_gif|\.[a-zA-Z0-9]+)$/i', '', $fn);
        if (!isset($sessions_map[$bid])) {
            $ts = $exTs($fn, $d['time']);
            $sessions_map[$bid] = ['id' => 's_' . $bid, 'time' => $ts, 'files' => []];
            $anchor_ts[$bid] = $ts;
        }
    }
}

foreach ($files_data as $d) {
    $fn = basename($d['file']);
    $ts = $exTs($fn, $d['time']);
    $bid = preg_match('/^(\d{8}_\d{6}_\d{3})/', $fn, $m) ? $m[1] : preg_replace('/(_mp4|_gif|\.[a-zA-Z0-9]+)$/i', '', $fn);
    if (isset($sessions_map[$bid])) {
        $sessions_map[$bid]['files'][] = $d;
        if ($ts > $sessions_map[$bid]['time']) $sessions_map[$bid]['time'] = $ts;
        continue;
    }
    $best = null;
    $min = 999999;
    foreach ($anchor_ts as $aid => $ats) {
        $diff = abs($ats - $ts);
        if ($diff <= 45 && $diff < $min) {
            $min = $diff;
            $best = $aid;
        }
    }
    if ($best !== null) {
        $sessions_map[$best]['files'][] = $d;
        if ($ts > $sessions_map[$best]['time']) $sessions_map[$best]['time'] = $ts;
    } else {
        if (!isset($sessions_map[$bid])) {
            $sessions_map[$bid] = ['id' => 's_' . $bid, 'time' => $ts, 'files' => [$d]];
        } else {
            $sessions_map[$bid]['files'][] = $d;
            if ($ts > $sessions_map[$bid]['time']) $sessions_map[$bid]['time'] = $ts;
        }
    }
}

usort($sessions_map, function($a, $b) { return $b['time'] - $a['time']; });
foreach ($sessions_map as &$sess) {
    usort($sess['files'], function($a, $b) {
        $gp = function($f) {
            $pl = strtolower($f['file']);
            if (strpos($pl, 'prints/') !== false) return 3;
            if (strpos($pl, 'animated/') !== false || strpos($pl, 'videos/') !== false || in_array($f['ext'], ['mp4', 'gif', 'mov', 'webm'])) return 2;
            return 1;
        };
        $pA = $gp($a);
        $pB = $gp($b);
        if ($pA !== $pB) return $pB - $pA;
        return $a['time'] - $b['time'];
    });
}
unset($sess);
$sessions = array_values($sessions_map);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Foto & Galeri - Admin YUHU Photobooth</title>
    <link rel="icon" href="https://qr.yuhu.co.id/2025/icon.png" type="image/png">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
        body { font-family: 'Inter', sans-serif; }
        .media-card:has(.bulk-check:checked) {
            outline: 3px solid #ef4444;
            outline-offset: -3px;
            background-color: #fef2f2;
        }
    </style>
</head>
<body class="bg-slate-100 min-h-screen flex flex-col">

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
                    <a href="index.php" class="px-3.5 py-1.5 rounded-lg text-xs md:text-sm font-semibold text-slate-600 hover:text-slate-900 transition">
                        📋 Daftar Klien & QR
                    </a>
                    <a href="gallery.php<?= $selected_event ? '?id=' . $selected_event['id'] : '' ?>" class="px-3.5 py-1.5 rounded-lg text-xs md:text-sm font-bold bg-blue-600 text-white shadow-sm transition">
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

    <div class="container mx-auto px-4 py-6 flex-grow">
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">

            <!-- Sidebar Daftar Event (Kiri: 3 Kolom di Desktop) -->
            <div class="lg:col-span-3">
                <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden sticky top-20">
                    <div class="bg-slate-900 text-white px-4 py-3.5 flex items-center justify-between">
                        <span class="font-bold text-sm tracking-wide">📁 Pilih Event Galeri</span>
                        <span class="text-xs bg-slate-800 text-slate-300 px-2 py-0.5 rounded-full font-semibold"><?= count($events_list) ?> Acara</span>
                    </div>
                    <div class="max-h-[70vh] overflow-y-auto divide-y divide-slate-100 p-2 space-y-1">
                        <?php foreach ($events_list as $ev):
                            $is_active = ($selected_event && $selected_event['id'] === $ev['id']);
                        ?>
                            <a href="gallery.php?id=<?= $ev['id'] ?>" class="block p-3 rounded-xl transition <?= $is_active ? 'bg-blue-600 text-white shadow-sm' : 'hover:bg-slate-50 text-slate-800' ?>">
                                <div class="font-bold text-sm truncate"><?= htmlspecialchars($ev['nama_client']) ?></div>
                                <div class="flex items-center justify-between mt-1.5 text-xs <?= $is_active ? 'text-blue-100' : 'text-slate-500' ?>">
                                    <span><?= htmlspecialchars($ev['tanggal_event']) ?></span>
                                    <span class="font-bold px-2 py-0.5 rounded-full text-[11px] <?= $is_active ? 'bg-blue-700 text-white' : ($ev['media_count'] > 0 ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500') ?>">
                                        📷 <?= $ev['media_count'] ?> File
                                    </span>
                                </div>
                            </a>
                        <?php endforeach; ?>
                        <?php if (empty($events_list)): ?>
                            <div class="p-6 text-center text-sm text-slate-400">Belum ada event terdaftar.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Panel Utama Manajemen Foto & Galeri (Kanan: 9 Kolom di Desktop) -->
            <div class="lg:col-span-9 space-y-6">

                <?php if (!$selected_event): ?>
                    <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-12 text-center text-slate-500">
                        Pilih salah satu event di sebelah kiri untuk mulai mengelola foto galeri.
                    </div>
                <?php else: ?>

                    <?php if (isset($flash_msg)): ?>
                        <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 px-4 py-3.5 rounded-xl font-semibold text-sm flex items-center justify-between shadow-sm">
                            <div class="flex items-center gap-2">
                                <span>✅</span>
                                <span><?= htmlspecialchars($flash_msg) ?></span>
                            </div>
                            <button onclick="this.parentElement.remove()" class="text-emerald-600 hover:text-emerald-900 font-bold text-lg leading-none">&times;</button>
                        </div>
                    <?php endif; ?>

                    <!-- Header Info Event & Tombol Pintas -->
                    <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6">
                        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 border-b border-slate-100 pb-4 mb-4">
                            <div>
                                <div class="flex items-center gap-2.5 flex-wrap">
                                    <h1 class="text-2xl font-extrabold text-slate-900"><?= htmlspecialchars($selected_event['nama_client']) ?></h1>
                                    <span class="bg-blue-50 text-blue-700 border border-blue-200 text-xs font-mono px-2.5 py-0.5 rounded-full">
                                        Folder: <?= htmlspecialchars($selected_event['folder']) ?>
                                    </span>
                                </div>
                                <p class="text-xs text-slate-500 mt-1">
                                    📅 <?= htmlspecialchars($selected_event['tanggal_event']) ?> (<?= substr($selected_event['jam_mulai'], 0, 5) ?> - <?= substr($selected_event['jam_selesai'], 0, 5) ?>)
                                    • 📍 <?= htmlspecialchars($selected_event['lokasi'] === '-' ? 'Lokasi belum diisi' : $selected_event['lokasi']) ?>
                                </p>
                            </div>
                            <div class="flex items-center gap-2 flex-wrap">
                                <a href="edit.php?id=<?= $selected_event['id'] ?>" class="bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold px-3.5 py-2.5 rounded-xl border border-slate-200 transition">
                                    ✏️ Edit Info / Lokasi / Jam
                                </a>
                                <a href="<?= htmlspecialchars($selected_event['gallery_url']) ?>" target="_blank" class="bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold px-4 py-2.5 rounded-xl shadow-sm transition">
                                    👁️ Lihat Galeri Tamu ↗
                                </a>
                            </div>
                        </div>

                        <!-- Baris Link Direct QR & Statistik Cepat -->
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                            <div class="md:col-span-2 bg-blue-50/70 border border-blue-200 rounded-xl p-3 flex items-center justify-between gap-2">
                                <div class="min-w-0">
                                    <span class="text-[11px] font-bold uppercase text-blue-700 block">🔗 Link Direct (QR Code Tamu):</span>
                                    <a href="<?= htmlspecialchars($selected_event['direct_link']) ?>" target="_blank" class="font-mono text-xs md:text-sm font-bold text-blue-600 hover:underline truncate block">
                                        <?= htmlspecialchars($selected_event['direct_link']) ?>
                                    </a>
                                </div>
                                <button type="button" onclick="copyText('<?= htmlspecialchars($selected_event['direct_link'], ENT_QUOTES) ?>', this)" class="bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold px-3 py-2 rounded-lg flex-shrink-0 transition">
                                    Copy Link
                                </button>
                            </div>
                            <div class="bg-slate-50 border border-slate-200 rounded-xl p-3 flex items-center justify-around text-center">
                                <div>
                                    <div class="text-lg font-extrabold text-slate-900"><?= count($sessions) ?></div>
                                    <div class="text-[11px] text-slate-500 font-semibold">Total Sesi</div>
                                </div>
                                <div class="h-8 w-px bg-slate-200"></div>
                                <div>
                                    <div class="text-lg font-extrabold text-emerald-600"><?= $total_visible_count ?></div>
                                    <div class="text-[11px] text-slate-500 font-semibold">Tampil</div>
                                </div>
                                <div class="h-8 w-px bg-slate-200"></div>
                                <div>
                                    <div class="text-lg font-extrabold text-amber-600"><?= $total_hidden_count ?></div>
                                    <div class="text-[11px] text-slate-500 font-semibold">Disembunyikan</div>
                                </div>
                            </div>
                        </div>

                        <!-- Accordion Pengaturan Folder & Upload Manual -->
                        <details class="mt-4 bg-slate-50 border border-slate-200 rounded-xl overflow-hidden">
                            <summary class="px-4 py-3 cursor-pointer font-bold text-xs md:text-sm text-slate-700 hover:bg-slate-100 select-none flex items-center justify-between">
                                <span>⚙️ Pengaturan Folder Tampil (Prints / Animated / Singles) & Upload Foto Manual</span>
                                <span class="text-xs text-blue-600 font-semibold">Klik untuk buka/tutup ▾</span>
                            </summary>
                            <div class="p-4 border-t border-slate-200 bg-white grid grid-cols-1 md:grid-cols-2 gap-6">
                                <!-- Form Pengaturan Folder & Judul -->
                                <form method="POST" class="space-y-3">
                                    <h4 class="font-bold text-sm text-slate-800 border-b pb-1.5">Folder yang Ditampilkan ke Tamu</h4>
                                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                                        <?php foreach ($all_possible_folders as $fn): ?>
                                            <label class="flex items-center gap-2 bg-slate-50 border border-slate-200 px-3 py-2 rounded-lg text-xs font-semibold cursor-pointer hover:bg-slate-100">
                                                <input type="checkbox" name="visible_folders[]" value="<?= $fn ?>" <?= in_array($fn, $current_visible) ? 'checked' : '' ?> class="w-4 h-4 accent-blue-600 rounded">
                                                <span><?= $fn ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 pt-1">
                                        <div>
                                            <label class="block text-xs font-semibold text-slate-600 mb-1">Judul Galeri</label>
                                            <input type="text" name="custom_title" value="<?= htmlspecialchars($current_title) ?>" class="w-full px-3 py-1.5 border rounded-lg text-xs">
                                        </div>
                                        <div>
                                            <label class="block text-xs font-semibold text-slate-600 mb-1">Subtitle Galeri</label>
                                            <input type="text" name="custom_subtitle" value="<?= htmlspecialchars($current_subtitle) ?>" class="w-full px-3 py-1.5 border rounded-lg text-xs">
                                        </div>
                                    </div>
                                    <button type="submit" name="update_gallery_settings" class="bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold px-4 py-2 rounded-lg transition">
                                        💾 Simpan Pengaturan Galeri
                                    </button>
                                </form>

                                <!-- Form Upload Manual -->
                                <form method="POST" enctype="multipart/form-data" class="space-y-3">
                                    <h4 class="font-bold text-sm text-slate-800 border-b pb-1.5">📤 Upload File Foto / Video Manual</h4>
                                    <div>
                                        <label class="block text-xs font-semibold text-slate-600 mb-1">Pilih File (Bisa pilih banyak sekaligus)</label>
                                        <input type="file" name="new_files[]" multiple required class="w-full text-xs border border-slate-200 rounded-lg p-2 bg-slate-50">
                                    </div>
                                    <div class="flex items-end gap-2">
                                        <div class="flex-1">
                                            <label class="block text-xs font-semibold text-slate-600 mb-1">Masukkan ke Sub-Folder</label>
                                            <select name="upload_target" class="w-full px-3 py-2 border border-slate-200 rounded-lg text-xs font-semibold bg-white">
                                                <option value="Prints">Prints (Strip Cetak)</option>
                                                <option value="Animated">Animated (MP4 / GIF)</option>
                                                <option value="Singles">Singles (Foto Satuan)</option>
                                                <option value="Originals">Originals</option>
                                            </select>
                                        </div>
                                        <button type="submit" name="upload_manual" class="bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold px-4 py-2 rounded-lg transition">
                                            Upload Sekarang
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </details>
                    </div>

                    <!-- Toolbar Filter & Aksi Massal (Sticky) -->
                    <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-4 sticky top-16 z-30">
                        <div class="flex flex-col md:flex-row items-stretch md:items-center justify-between gap-3">
                            <!-- Filter Tab Status -->
                            <div class="flex items-center gap-1.5 bg-slate-100 p-1 rounded-xl border border-slate-200">
                                <a href="gallery.php?id=<?= $selected_event['id'] ?>&filter=all" class="px-3 py-1.5 rounded-lg text-xs font-bold transition <?= $filter_status === 'all' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900' ?>">
                                    Semua (<?= $total_visible_count + $total_hidden_count ?>)
                                </a>
                                <a href="gallery.php?id=<?= $selected_event['id'] ?>&filter=visible" class="px-3 py-1.5 rounded-lg text-xs font-bold transition <?= $filter_status === 'visible' ? 'bg-emerald-600 text-white shadow-sm' : 'text-slate-600 hover:text-slate-900' ?>">
                                    👁️ Ditampilkan (<?= $total_visible_count ?>)
                                </a>
                                <a href="gallery.php?id=<?= $selected_event['id'] ?>&filter=hidden" class="px-3 py-1.5 rounded-lg text-xs font-bold transition <?= $filter_status === 'hidden' ? 'bg-amber-500 text-white shadow-sm' : 'text-slate-600 hover:text-slate-900' ?>">
                                    🙈 Disembunyikan (<?= $total_hidden_count ?>)
                                </a>
                            </div>

                            <!-- Tombol Bulk Action -->
                            <?php if (!empty($sessions)): ?>
                            <div class="flex items-center gap-2 flex-wrap">
                                <button type="button" onclick="toggleSelectAll()" id="btnSelectAll" class="bg-slate-200 hover:bg-slate-300 text-slate-800 text-xs font-bold px-3 py-2 rounded-lg transition">
                                    ☑️ Pilih Semua
                                </button>
                                <button type="submit" form="bulkActionForm" name="bulk_hide" id="btnBulkHide" disabled class="bg-amber-500 hover:bg-amber-600 disabled:bg-slate-200 disabled:text-slate-400 text-white text-xs font-bold px-3 py-2 rounded-lg transition">
                                    🙈 Sembunyikan (0)
                                </button>
                                <button type="submit" form="bulkActionForm" name="bulk_unhide" id="btnBulkUnhide" disabled class="bg-emerald-600 hover:bg-emerald-700 disabled:bg-slate-200 disabled:text-slate-400 text-white text-xs font-bold px-3 py-2 rounded-lg transition">
                                    👁️ Tampilkan (0)
                                </button>
                                <button type="submit" form="bulkActionForm" name="bulk_delete" id="btnBulkDelete" disabled onclick="return confirm('Yakin ingin MENGHAPUS PERMANEN file yang dicentang?');" class="bg-red-600 hover:bg-red-700 disabled:bg-slate-200 disabled:text-slate-400 text-white text-xs font-bold px-3 py-2 rounded-lg transition">
                                    🗑️ Hapus Permanen (0)
                                </button>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Daftar Sesi & Foto -->
                    <?php if (empty($sessions)): ?>
                        <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-12 text-center">
                            <div class="text-4xl mb-3">📷</div>
                            <h3 class="text-lg font-bold text-slate-800">Belum Ada Foto pada Kategori Ini</h3>
                            <p class="text-xs text-slate-500 mt-1">
                                Saat LumaBooth mengambil foto baru, foto akan otomatis muncul di sini lengkap per sesi.
                            </p>
                        </div>
                    <?php else: ?>
                        <form method="POST" id="bulkActionForm" class="space-y-6">
                            <?php foreach ($sessions as $idx => $sess): ?>
                                <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
                                    <!-- Header Sesi -->
                                    <div class="bg-slate-50 px-5 py-3.5 border-b border-slate-200 flex flex-wrap items-center justify-between gap-3">
                                        <div class="flex items-center gap-2.5">
                                            <span class="bg-slate-800 text-white text-xs font-extrabold px-2.5 py-1 rounded-lg">
                                                Sesi <?= count($sessions) - $idx ?>
                                            </span>
                                            <span class="text-xs font-semibold text-slate-600">
                                                <?= count($sess['files']) ?> Media • 🕒 <?= date('d M Y, H:i:s', $sess['time']) ?>
                                            </span>
                                        </div>
                                        <div class="flex items-center gap-1.5 flex-wrap">
                                            <button type="button" onclick="toggleSelectSession(<?= $idx ?>)" class="bg-slate-200 hover:bg-slate-300 text-slate-700 text-xs font-bold px-2.5 py-1.5 rounded-lg transition">
                                                ☑️ Pilih Sesi
                                            </button>
                                            <button type="submit" form="sessForm_<?= $idx ?>" name="hide_session" class="bg-amber-100 hover:bg-amber-200 text-amber-800 text-xs font-bold px-2.5 py-1.5 rounded-lg transition">
                                                🙈 Sembunyikan Sesi
                                            </button>
                                            <button type="submit" form="sessForm_<?= $idx ?>" name="unhide_session" class="bg-emerald-100 hover:bg-emerald-200 text-emerald-800 text-xs font-bold px-2.5 py-1.5 rounded-lg transition">
                                                👁️ Tampilkan Sesi
                                            </button>
                                            <button type="submit" form="sessForm_<?= $idx ?>" name="delete_session" onclick="return confirm('Hapus PERMANEN seluruh foto dalam Sesi ini?');" class="bg-red-100 hover:bg-red-200 text-red-700 text-xs font-bold px-2.5 py-1.5 rounded-lg transition">
                                                🗑️ Hapus Sesi
                                            </button>
                                        </div>
                                    </div>

                                    <!-- Grid Foto dalam Sesi -->
                                    <div class="p-5 grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-4">
                                        <?php foreach ($sess['files'] as $fd):
                                            $ih = $fd['is_hidden'];
                                            $f_hash = md5($fd['file']);
                                            $is_vid = in_array($fd['ext'], ['mp4', 'mov', 'webm']);
                                        ?>
                                            <div class="media-card rounded-xl border <?= $ih ? 'border-amber-400 bg-amber-50/40' : 'border-slate-200 bg-white' ?> overflow-hidden shadow-sm flex flex-col justify-between relative group transition hover:shadow-md">
                                                <!-- Checkbox Pilih Massal -->
                                                <label class="absolute top-2.5 right-2.5 z-20 cursor-pointer bg-white/90 p-1 rounded-md shadow">
                                                    <input type="checkbox" name="bulk_files[]" value="<?= htmlspecialchars($fd['file']) ?>" class="bulk-check sc_<?= $idx ?> w-5 h-5 accent-red-600 cursor-pointer block" onchange="updateBulkUI()">
                                                </label>

                                                <!-- Badge Nama Sub-Folder (Prints / Animated / Singles) -->
                                                <div class="absolute top-2.5 left-2.5 z-10 bg-slate-900/80 text-white text-[10px] font-bold px-2 py-0.5 rounded-md backdrop-blur-sm">
                                                    <?= htmlspecialchars($fd['folder']) ?>
                                                </div>

                                                <!-- Badge Status Disembunyikan -->
                                                <?php if ($ih): ?>
                                                    <div class="absolute inset-x-0 top-1/3 z-10 flex justify-center pointer-events-none">
                                                        <span class="bg-amber-500 text-white text-[11px] font-extrabold px-3 py-1 rounded-full shadow-md">
                                                            🙈 DISEMBUNYIKAN
                                                        </span>
                                                    </div>
                                                <?php endif; ?>

                                                <!-- Thumbnail Gambar / Video -->
                                                <div class="relative bg-slate-100 aspect-[4/3] overflow-hidden cursor-pointer" onclick="openPreviewModal('<?= htmlspecialchars($fd['path'], ENT_QUOTES) ?>', '<?= $is_vid ? 'video' : 'image' ?>')">
                                                    <?php if ($is_vid): ?>
                                                        <video src="<?= htmlspecialchars($fd['path']) ?>" muted loop playsinline onmouseover="this.play()" onmouseout="this.pause()" class="w-full h-full object-cover <?= $ih ? 'opacity-45 grayscale' : '' ?>"></video>
                                                        <span class="absolute bottom-2 right-2 bg-black/70 text-white text-[10px] font-bold px-2 py-0.5 rounded">▶ VIDEO</span>
                                                    <?php else: ?>
                                                        <img src="<?= htmlspecialchars($fd['path']) ?>" alt="Media" loading="lazy" class="w-full h-full object-cover <?= $ih ? 'opacity-45 grayscale' : '' ?> group-hover:scale-105 transition duration-200">
                                                    <?php endif; ?>
                                                </div>

                                                <!-- Tombol Aksi Cepat per Foto -->
                                                <div class="p-2.5 bg-white border-t border-slate-100 space-y-1.5">
                                                    <div class="text-[10px] text-slate-400 font-mono truncate" title="<?= htmlspecialchars(basename($fd['file'])) ?>">
                                                        <?= htmlspecialchars(basename($fd['file'])) ?>
                                                    </div>
                                                    <div class="flex gap-1.5">
                                                        <button type="submit" form="singleForm_<?= $f_hash ?>" name="toggle_hide_file" class="flex-1 text-[11px] font-bold py-1.5 px-2 rounded-lg border transition <?= $ih ? 'bg-emerald-50 border-emerald-300 text-emerald-700 hover:bg-emerald-600 hover:text-white' : 'bg-amber-50 border-amber-300 text-amber-800 hover:bg-amber-500 hover:text-white' ?>">
                                                            <?= $ih ? '👁️ Tampilkan' : '🙈 Sembunyikan' ?>
                                                        </button>
                                                        <button type="submit" form="singleForm_<?= $f_hash ?>" name="delete_single_file" onclick="return confirm('Hapus file ini secara permanen?');" class="text-[11px] font-bold py-1.5 px-2.5 rounded-lg border border-red-200 text-red-600 hover:bg-red-600 hover:text-white transition" title="Hapus Permanen">
                                                            🗑️
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </form>

                        <!-- Hidden Forms untuk Eksekusi Sesi & File Tunggal -->
                        <?php foreach ($sessions as $idx => $sess):
                            $s_files = array_map(function($i) { return $i['file']; }, $sess['files']);
                        ?>
                            <form method="POST" id="sessForm_<?= $idx ?>" class="hidden">
                                <input type="hidden" name="session_files" value="<?= htmlspecialchars(json_encode($s_files)) ?>">
                            </form>
                            <?php foreach ($sess['files'] as $fd):
                                $f_hash = md5($fd['file']);
                            ?>
                                <form method="POST" id="singleForm_<?= $f_hash ?>" class="hidden">
                                    <input type="hidden" name="filename" value="<?= htmlspecialchars($fd['file']) ?>">
                                </form>
                            <?php endforeach; ?>
                        <?php endforeach; ?>

                        <!-- Zona Bahaya: Hapus Seluruh Media Event Ini -->
                        <div class="bg-red-50 border border-red-200 rounded-2xl p-4 flex flex-col sm:flex-row items-center justify-between gap-3">
                            <div>
                                <h4 class="text-sm font-bold text-red-800">⚠️ Bersihkan Semua Foto Event Ini</h4>
                                <p class="text-xs text-red-600">Menghapus seluruh file foto & video di dalam folder event ini secara permanen dari server.</p>
                            </div>
                            <form method="POST" onsubmit="return confirm('PERINGATAN KERAS: Yakin ingin menghapus SEMUA foto/video di event ini secara permanen?');">
                                <button type="submit" name="delete_all_files" class="bg-red-600 hover:bg-red-700 text-white text-xs font-bold px-4 py-2.5 rounded-xl shadow-sm whitespace-nowrap transition">
                                    🗑️ Hapus Semua Media Event Ini
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>

                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Lightbox Modal Preview Foto/Video -->
    <div id="previewModal" class="fixed inset-0 bg-slate-950/90 backdrop-blur-sm z-50 hidden items-center justify-center p-4" onclick="if(event.target===this) closePreviewModal()">
        <button onclick="closePreviewModal()" class="absolute top-5 right-6 text-white hover:text-red-400 text-4xl font-bold leading-none">&times;</button>
        <div id="previewContainer" class="max-w-4xl max-h-[90vh] flex items-center justify-center"></div>
    </div>

    <script>
        function copyText(text, btn) {
            navigator.clipboard.writeText(text).then(() => {
                const orig = btn.innerText;
                btn.innerText = 'Tersalin!';
                btn.classList.replace('bg-blue-600', 'bg-emerald-600');
                setTimeout(() => {
                    btn.innerText = orig;
                    btn.classList.replace('bg-emerald-600', 'bg-blue-600');
                }, 1500);
            });
        }

        function updateBulkUI() {
            const checks = document.querySelectorAll('.bulk-check');
            const checked = document.querySelectorAll('.bulk-check:checked');
            const cnt = checked.length;

            const btnDel = document.getElementById('btnBulkDelete');
            const btnHide = document.getElementById('btnBulkHide');
            const btnUnhide = document.getElementById('btnBulkUnhide');
            const btnAll = document.getElementById('btnSelectAll');

            if (btnDel) { btnDel.disabled = cnt === 0; btnDel.innerText = `🗑️ Hapus Permanen (${cnt})`; }
            if (btnHide) { btnHide.disabled = cnt === 0; btnHide.innerText = `🙈 Sembunyikan (${cnt})`; }
            if (btnUnhide) { btnUnhide.disabled = cnt === 0; btnUnhide.innerText = `👁️ Tampilkan (${cnt})`; }
            if (btnAll) {
                btnAll.innerText = (cnt === checks.length && checks.length > 0) ? '🔲 Batal Pilih Semua' : '☑️ Pilih Semua';
            }
        }

        function toggleSelectAll() {
            const checks = document.querySelectorAll('.bulk-check');
            const allChecked = Array.from(checks).every(c => c.checked);
            checks.forEach(c => c.checked = !allChecked);
            updateBulkUI();
        }

        function toggleSelectSession(idx) {
            const checks = document.querySelectorAll('.sc_' + idx);
            const allChecked = Array.from(checks).every(c => c.checked);
            checks.forEach(c => c.checked = !allChecked);
            updateBulkUI();
        }

        function openPreviewModal(src, type) {
            const modal = document.getElementById('previewModal');
            const container = document.getElementById('previewContainer');
            if (type === 'video') {
                container.innerHTML = `<video src="${src}" controls autoplay loop class="max-w-full max-h-[85vh] rounded-xl shadow-2xl"></video>`;
            } else {
                container.innerHTML = `<img src="${src}" class="max-w-full max-h-[85vh] rounded-xl shadow-2xl object-contain">`;
            }
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        function closePreviewModal() {
            const modal = document.getElementById('previewModal');
            const container = document.getElementById('previewContainer');
            container.innerHTML = '';
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') closePreviewModal();
        });
    </script>
</body>
</html>
