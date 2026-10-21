<?php
/**
 * ============================================================================
 * YUHU PHOTOBOOTH - LUMABOOTH AUTO-LINK & HIGH-CONCURRENCY SYNC ENGINE
 * ============================================================================
 * Dirancang khusus agar tetap cepat & lancar meskipun digunakan oleh
 * 4 hingga 20+ Komputer Photobooth sekaligus secara bersamaan (Multi-PC Ready):
 *
 * 1. Fast-Path Cache (.db_registered.lock):
 *    Setelah foto pertama membuat link di database, upload foto ke-2 s/d ribuan
 *    dari 4+ komputer langsung masuk paralel (< 0.01ms) tanpa perlu query DB
 *    dan tanpa antre lock!
 * 2. Per-Folder Mutex Lock:
 *    Kunci sinkronisasi dipisah per folder acara sehingga Komputer 1, 2, 3, 4+
 *    tidak pernah saling menunggu.
 * 3. Auto-Deduplicator & Deterministic Unique Slug:
 *    Mencegah 100% pembuatan link ganda meskipun 4 komputer mengupload ke
 *    nama project yang sama di detik yang sama persis.
 */

if (!function_exists('extractLumaboothFolderFromUrl')) {
    function extractLumaboothFolderFromUrl($path) {
        $path = trim((string)$path);
        if ($path === '') return '';
        if (preg_match('/[?&](?:client|c)=([^&]+)/i', $path, $m)) {
            return rawurldecode($m[1]);
        }
        if (preg_match('#/client/photos/([^/?\#]+)#i', $path, $m)) {
            return rawurldecode($m[1]);
        }
        if (preg_match('#/client/([^/?\#]+)#i', $path, $m)) {
            $seg = rawurldecode($m[1]);
            if (!in_array(strtolower($seg), ['index.php', 'admin.php', 'api_upload.php', 'photos'], true)) {
                return $seg;
            }
        }
        return '';
    }
}

/**
 * Otomatis gabungkan/hapus baris duplikat jika sempat terbuat 2x karena request bersamaan
 */
if (!function_exists('deduplicateLumaboothClients')) {
    function deduplicateLumaboothClients($pdo) {
        try {
            $stmt = $pdo->query("SELECT * FROM clients ORDER BY (CASE WHEN lokasi != '-' AND lokasi != '' THEN 0 ELSE 1 END) ASC, id ASC");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $seen_keys = [];
            foreach ($rows as $r) {
                $folder_raw = extractLumaboothFolderFromUrl($r['folder_foto_path'] ?? '');
                $clean_folder = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $folder_raw));
                $clean_name = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $r['nama_client']));

                $key = $clean_folder !== '' ? ('folder:' . $clean_folder) : ('name:' . $clean_name . ':' . $r['tanggal_event']);
                if ($clean_name === '' && $clean_folder === '') continue;

                if (isset($seen_keys[$key])) {
                    $del = $pdo->prepare("DELETE FROM clients WHERE id = ?");
                    $del->execute([$r['id']]);
                } else {
                    $seen_keys[$key] = $r['id'];
                }
            }
        } catch (Exception $e) {}
    }
}

// Migrasi satu kali: pastikan tanggal_event dan jam_mulai/selesai mengizinkan NULL
// sehingga project baru otomatis dari LumaBooth belum memiliki jadwal default (tidak terlihat di Live Events tamu)
if (!file_exists(__DIR__ . '/.db_schema_v2_migrated')) {
    try {
        $pdo->exec("ALTER TABLE clients MODIFY tanggal_event DATE NULL DEFAULT NULL, MODIFY jam_mulai TIME NULL DEFAULT NULL, MODIFY jam_selesai TIME NULL DEFAULT NULL");
        $pdo->exec("UPDATE clients SET tanggal_event = NULL, jam_mulai = NULL, jam_selesai = NULL WHERE (lokasi = '-' OR lokasi = '' OR slug LIKE 'lb-%') AND jam_mulai = '00:00:00' AND jam_selesai = '23:59:00'");
        @file_put_contents(__DIR__ . '/.db_schema_v2_migrated', date('Y-m-d H:i:s'));
    } catch (Exception $e) {}
}

if (!function_exists('ensureLumaboothEventInDb')) {
    function ensureLumaboothEventInDb($pdo, $base_url, $folder_name, $is_active_upload = false, $preloaded_clients = null) {
        $folder_name = trim(str_replace(['..', '/', '\\', "\0"], '', (string)$folder_name));
        if ($folder_name === '') return null;

        $lower = strtolower($folder_name);
        $ignored_folders = ['settings', 'templates', 'thumb', 'file_upload', 'www'];
        if (in_array($lower, $ignored_folders, true)) {
            return null;
        }

        $photos_root = __DIR__ . '/client/photos';
        $event_dir = $photos_root . '/' . $folder_name;
        if (!is_dir($event_dir)) {
            @mkdir($event_dir, 0777, true);
        }

        $deleted_marker = $event_dir . '/.deleted_by_admin';
        $registered_marker = $event_dir . '/.db_registered.lock';

        // Jika pemindaian pasif dan pernah sengaja dihapus oleh Admin, lewati
        if (!$is_active_upload && file_exists($deleted_marker)) {
            return null;
        }

        // FAST-PATH MULTI-PC (< 0.01ms):
        // Jika saat upload foto ke-2 dst folder ini sudah tercatat di DB (.db_registered.lock ada),
        // langsung lewati query DB & lewati lock agar 4+ komputer bisa upload paralel tanpa hambatan!
        if ($is_active_upload && file_exists($registered_marker) && !file_exists($deleted_marker) && (!isset($_REQUEST['action']) || $_REQUEST['action'] !== 'register_event')) {
            return ['nama_client' => str_replace('_', ' ', $folder_name), 'tanggal_event' => null];
        }

        $req_clean = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $folder_name));
        if ($req_clean === '') return null;

        // Per-Folder Mutex Lock: Komputer di Event A tidak pernah menghalangi Komputer di Event B
        $lock_fp = @fopen($event_dir . '/.sync_mutex.lock', 'c+');
        if ($lock_fp) {
            @flock($lock_fp, LOCK_EX);
        }

        try {
            // Cek ulang di dalam lock apakah request dari komputer lain baru saja mendaftarkannya
            if ($is_active_upload && file_exists($registered_marker) && !file_exists($deleted_marker) && (!isset($_REQUEST['action']) || $_REQUEST['action'] !== 'register_event')) {
                if ($lock_fp) { @flock($lock_fp, LOCK_UN); @fclose($lock_fp); }
                return ['nama_client' => str_replace('_', ' ', $folder_name), 'tanggal_event' => null];
            }

            if (is_array($preloaded_clients)) {
                $all_clients = $preloaded_clients;
            } else {
                $stmt = $pdo->query("SELECT * FROM clients ORDER BY id DESC");
                $all_clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            $matched = null;
            foreach ($all_clients as $row) {
                $db_clean_name = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $row['nama_client']));
                $db_folder_raw = extractLumaboothFolderFromUrl($row['folder_foto_path'] ?? '');
                $db_clean_folder = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $db_folder_raw));

                if ($db_clean_name === $req_clean || ($db_clean_folder !== '' && $db_clean_folder === $req_clean)) {
                    $matched = $row;
                    break;
                }
            }

            $gallery_link = rtrim($base_url, '/') . '/client/' . rawurlencode($folder_name);

            if ($matched) {
                $cur_path = trim($matched['folder_foto_path'] ?? '');
                if ($cur_path === '' || stripos($cur_path, 'uploads/gallery/') === 0 || $cur_path === '#') {
                    $upd = $pdo->prepare("UPDATE clients SET folder_foto_path = ? WHERE id = ?");
                    $upd->execute([$gallery_link, $matched['id']]);
                    $matched['folder_foto_path'] = $gallery_link;
                }
                @file_put_contents($registered_marker, (string)$matched['id']);
                if ($lock_fp) { @flock($lock_fp, LOCK_UN); @fclose($lock_fp); }
                return $matched;
            }

            if ($is_active_upload && file_exists($deleted_marker)) {
                @unlink($deleted_marker);
            }

            // Slug deterministik berbasis nama folder agar MySQL UNIQUE index mencegah duplikat 100%
            $readable_name = trim(str_replace('_', ' ', $folder_name));
            $slug_base = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $readable_name), '-'));
            if ($slug_base === '') $slug_base = 'event';
            $slug = 'lb-' . $slug_base;

            // Project baru dari LumaBooth belum memiliki tanggal & jam acara secara default
            // agar TIDAK terlihat oleh tamu umum di halaman Live Events (files.yuhu.co.id)
            $tanggal_event = null;
            $jam_mulai = null;
            $jam_selesai = null;
            $lokasi_default = '-';

            $qr_folder_path = __DIR__ . '/uploads/qrcodes';
            if (!is_dir($qr_folder_path)) {
                @mkdir($qr_folder_path, 0777, true);
            }
            $qr_filename = $slug . '.png';
            $qr_filepath = $qr_folder_path . '/' . $qr_filename;

            // INSERT ke Database TERLEBIH DAHULU di dalam Lock (< 1ms)
            $ins = $pdo->prepare("INSERT IGNORE INTO clients (nama_client, slug, lokasi, tanggal_event, jam_mulai, jam_selesai, qr_code_path, folder_foto_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $ins->execute([
                $readable_name,
                $slug,
                $lokasi_default,
                $tanggal_event,
                $jam_mulai,
                $jam_selesai,
                'uploads/qrcodes/' . $qr_filename,
                $gallery_link
            ]);

            $new_id = (int)$pdo->lastInsertId();
            @file_put_contents($registered_marker, (string)$new_id);

            // Lepaskan Lock segera setelah INSERT selesai
            if ($lock_fp) { @flock($lock_fp, LOCK_UN); @fclose($lock_fp); $lock_fp = null; }

            // Download QR Code hanya jika file QR belum ada
            if (!file_exists($qr_filepath) && function_exists('curl_init')) {
                $tahun_event = !empty($tanggal_event) ? date('Y', strtotime($tanggal_event)) : date('Y');
                $nama_clean = preg_replace('/\s+/', '', $readable_name);
                $custom_direct_link = rtrim($base_url, '/') . '/' . $tahun_event . '/' . $nama_clean;
                $qr_api = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($custom_direct_link);

                $ch = curl_init($qr_api);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_TIMEOUT, 4);
                $img = @curl_exec($ch);
                if ($img) @file_put_contents($qr_filepath, $img);
                curl_close($ch);
            }

            return [
                'id' => $new_id,
                'nama_client' => $readable_name,
                'slug' => $slug,
                'lokasi' => $lokasi_default,
                'tanggal_event' => $tanggal_event,
                'jam_mulai' => $jam_mulai,
                'jam_selesai' => $jam_selesai,
                'qr_code_path' => 'uploads/qrcodes/' . $qr_filename,
                'folder_foto_path' => $gallery_link
            ];
        } catch (Exception $e) {
            if ($lock_fp) { @flock($lock_fp, LOCK_UN); @fclose($lock_fp); }
            return null;
        }
    }
}

if (!function_exists('autoSyncAllLumaboothFolders')) {
    function autoSyncAllLumaboothFolders($pdo, $base_url, $force_full_scan = false) {
        $photos_root = __DIR__ . '/client/photos';
        if (!is_dir($photos_root)) return [];

        // Cek apakah ada folder yang belum punya .db_registered.lock
        $dirs = @scandir($photos_root) ?: [];
        $unregistered_dirs = [];
        foreach ($dirs as $d) {
            if ($d === '.' || $d === '..') continue;
            $full_d = $photos_root . '/' . $d;
            if (is_dir($full_d)) {
                if ($force_full_scan || (!file_exists($full_d . '/.db_registered.lock') && !file_exists($full_d . '/.deleted_by_admin'))) {
                    $unregistered_dirs[] = $d;
                }
            }
        }

        // Jika tidak ada folder baru dan bukan force scan, keluar instan (< 0.1ms) tanpa membebani DB!
        if (empty($unregistered_dirs) && !$force_full_scan) {
            return [];
        }

        deduplicateLumaboothClients($pdo);

        $preloaded = null;
        try {
            $stmt = $pdo->query("SELECT * FROM clients ORDER BY id DESC");
            $preloaded = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {}

        $synced_list = [];
        foreach ($unregistered_dirs as $d) {
            $res = ensureLumaboothEventInDb($pdo, $base_url, $d, false, $preloaded);
            if ($res) {
                $yr_res = (!empty($res['tanggal_event'])) ? date('Y', strtotime($res['tanggal_event'])) : date('Y');
                $synced_list[] = $res['nama_client'] . ' -> ' . rtrim($base_url, '/') . '/' . $yr_res . '/' . preg_replace('/\s+/', '', $res['nama_client']);
                $preloaded = null; // refresh jika ada insert baru
            }
        }
        return $synced_list;
    }
}

// Jika dijalankan langsung dari Terminal cPanel (php lumabooth_sync.php) atau URL
if (php_sapi_name() === 'cli' || basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'lumabooth_sync.php') {
    require_once __DIR__ . '/config.php';
    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }
    $list = autoSyncAllLumaboothFolders($pdo, $base_url, true);
    if (php_sapi_name() === 'cli') {
        echo "\n=======================================================\n";
        echo "  SINKRONISASI & DEDUPLIKASI LUMABOOTH -> FILES.YUHU.CO.ID\n";
        echo "=======================================================\n";
        foreach ($list as $item) {
            echo "  [OK] " . $item . "\n";
        }
        echo "=======================================================\n\n";
    } else {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'success', 'synced' => $list]);
    }
    exit;
}

// Jangan jalankan scan semua folder saat request upload satuan atau polling AJAX galeri tamu
if (isset($pdo) && isset($base_url) && !defined('LUMABOOTH_SKIP_AUTOSYNC') && !isset($_GET['ajax'])) {
    $is_admin_page = (strpos($_SERVER['SCRIPT_NAME'] ?? '', '/admin/') !== false);
    autoSyncAllLumaboothFolders($pdo, $base_url, $is_admin_page);
}
?>
