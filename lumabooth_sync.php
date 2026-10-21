<?php
/**
 * ============================================================================
 * YUHU PHOTOBOOTH - LUMABOOTH AUTO-LINK & DATABASE SYNC ENGINE
 * ============================================================================
 * File ini terpisah dari config.php agar kredensial database cPanel di config.php
 * tidak pernah tertimpa saat git update.
 *
 * Dilengkapi:
 * - File Locking (flock LOCK_EX) & Deterministic Unique Slug (Anti-Double / Anti Race Condition)
 * - Auto-Deduplicator (Otomatis membersihkan jika ada data kembar akibat upload bersamaan)
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
            // Urutkan agar baris yang sudah diedit lokasi oleh Admin (lokasi != '-') diprioritaskan tetap disimpan
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
                    // Hapus baris duplikat
                    $del = $pdo->prepare("DELETE FROM clients WHERE id = ?");
                    $del->execute([$r['id']]);
                } else {
                    $seen_keys[$key] = $r['id'];
                }
            }
        } catch (Exception $e) {}
    }
}

if (!function_exists('ensureLumaboothEventInDb')) {
    function ensureLumaboothEventInDb($pdo, $base_url, $folder_name, $is_active_upload = false) {
        $folder_name = trim(str_replace(['..', '/', '\\', "\0"], '', (string)$folder_name));
        if ($folder_name === '') return null;

        $lower = strtolower($folder_name);
        $ignored_folders = ['settings', 'templates', 'thumb', 'file_upload', 'www'];
        if (in_array($lower, $ignored_folders, true)) {
            return null;
        }

        $photos_root = __DIR__ . '/client/photos';
        $event_dir = $photos_root . '/' . $folder_name;
        $deleted_marker = $event_dir . '/.deleted_by_admin';

        // Jika pemindaian pasif dan pernah sengaja dihapus oleh Admin, jangan buat ulang otomatis
        if (!$is_active_upload && file_exists($deleted_marker)) {
            return null;
        }

        $req_clean = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $folder_name));
        if ($req_clean === '') return null;

        // Kunci proses (Mutex Lock) agar 2 upload yang masuk di detik yang sama tidak membuat 2 baris!
        $lock_dir = __DIR__ . '/uploads';
        if (!is_dir($lock_dir)) @mkdir($lock_dir, 0777, true);
        $lock_fp = @fopen($lock_dir . '/lumabooth_sync.lock', 'c+');
        if ($lock_fp) {
            @flock($lock_fp, LOCK_EX);
        }

        try {
            $stmt = $pdo->query("SELECT * FROM clients ORDER BY id DESC");
            $all_clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
                if ($lock_fp) { @flock($lock_fp, LOCK_UN); @fclose($lock_fp); }
                return $matched;
            }

            if ($is_active_upload && file_exists($deleted_marker)) {
                @unlink($deleted_marker);
            }

            // Gunakan slug deterministik berbasis nama folder agar MySQL UNIQUE index juga mencegah duplikat
            $readable_name = trim(str_replace('_', ' ', $folder_name));
            $slug_base = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $readable_name), '-'));
            if ($slug_base === '') $slug_base = 'event';
            $slug = 'lb-' . $slug_base;

            $tanggal_event = date('Y-m-d');
            $jam_mulai = '00:00:00';
            $jam_selesai = '23:59:00';
            $lokasi_default = '-';

            $qr_folder_path = __DIR__ . '/uploads/qrcodes';
            if (!is_dir($qr_folder_path)) {
                @mkdir($qr_folder_path, 0777, true);
            }
            $qr_filename = $slug . '.png';
            $qr_filepath = $qr_folder_path . '/' . $qr_filename;

            // INSERT ke Database TERLEBIH DAHULU di dalam Lock (< 1ms) sebelum download gambar QR
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

            // Lepaskan Lock segera setelah INSERT selesai
            if ($lock_fp) { @flock($lock_fp, LOCK_UN); @fclose($lock_fp); $lock_fp = null; }

            // Download QR Code hanya jika file QR belum ada
            if (!file_exists($qr_filepath) && function_exists('curl_init')) {
                $tahun_event = date('Y', strtotime($tanggal_event));
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
    function autoSyncAllLumaboothFolders($pdo, $base_url) {
        // 1. Bersihkan jika ada entri yang terlanjur dobel
        deduplicateLumaboothClients($pdo);

        // 2. Pindai folder di /client/photos/
        $synced_list = [];
        $photos_root = __DIR__ . '/client/photos';
        if (!is_dir($photos_root)) return $synced_list;
        $dirs = @scandir($photos_root) ?: [];
        foreach ($dirs as $d) {
            if ($d === '.' || $d === '..') continue;
            if (is_dir($photos_root . '/' . $d)) {
                $res = ensureLumaboothEventInDb($pdo, $base_url, $d, false);
                if ($res) {
                    $synced_list[] = $res['nama_client'] . ' -> ' . rtrim($base_url, '/') . '/' . date('Y', strtotime($res['tanggal_event'])) . '/' . preg_replace('/\s+/', '', $res['nama_client']);
                }
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
    $list = autoSyncAllLumaboothFolders($pdo, $base_url);
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

// Jalankan sinkronisasi & deduplikasi otomatis setiap kali file ini di-include oleh halaman web
if (isset($pdo) && isset($base_url)) {
    autoSyncAllLumaboothFolders($pdo, $base_url);
}
?>
