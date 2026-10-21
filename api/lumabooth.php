<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-KEY, X-FILE-SHA1');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once '../config.php';

// Token keamanan default untuk LumaBooth Sync Agent / Operator
$API_SECRET_KEY = 'yuhu8080';

// Helper untuk mengambil API Key dari Header, POST, atau GET
function getRequestApiKey() {
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    foreach ($headers as $k => $v) {
        if (strtolower($k) === 'x-api-key') return trim($v);
        if (strtolower($k) === 'authorization' && preg_match('/Bearer\s+(.*)$/i', $v, $m)) return trim($m[1]);
    }
    if (isset($_SERVER['HTTP_X_API_KEY'])) return trim($_SERVER['HTTP_X_API_KEY']);
    if (isset($_POST['api_key'])) return trim($_POST['api_key']);
    if (isset($_GET['api_key'])) return trim($_GET['api_key']);
    return '';
}

$req_key = getRequestApiKey();
if ($req_key !== $API_SECRET_KEY) {
    http_response_code(403);
    exit;
}

$action = $_GET['action'] ?? ($_POST['action'] ?? 'list_events');

// =========================================================================
// 0. ACTION: ping (Health check cepat untuk deteksi internet/server pulih)
// =========================================================================
if ($action === 'ping') {
    echo json_encode([
        'status' => 'success',
        'message' => 'pong',
        'timestamp' => time()
    ]);
    exit;
}

// =========================================================================
// 1. ACTION: list_events (Mengambil daftar event untuk aplikasi Windows)
// =========================================================================
if ($action === 'list_events') {
    $stmt = $pdo->query("SELECT * FROM clients ORDER BY tanggal_event DESC, jam_mulai DESC");
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $now = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
    $result = [];

    foreach ($clients as $c) {
        $year = date('Y', strtotime($c['tanggal_event']));
        $clean_name = preg_replace('/\s+/', '', $c['nama_client']);
        $direct_link = rtrim($base_url, '/') . '/' . $year . '/' . $clean_name;

        // Hitung jumlah file lokal jika sudah ada di uploads/gallery/{slug}
        $local_dir = '../uploads/gallery/' . $c['slug'];
        $file_count = 0;
        if (is_dir($local_dir)) {
            $files = scandir($local_dir);
            foreach ($files as $f) {
                if (strpos($f, '.part_') !== false) continue; // Abaikan file temp
                $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'mov', 'webm'])) {
                    $file_count++;
                }
            }
        }

        // Cek apakah sedang live
        $start = new DateTime($c['tanggal_event'] . ' ' . $c['jam_mulai'], new DateTimeZone('Asia/Jakarta'));
        $end   = new DateTime($c['tanggal_event'] . ' ' . $c['jam_selesai'], new DateTimeZone('Asia/Jakarta'));
        $vis_start = (clone $start)->modify('-1 hour');
        $vis_end   = (clone $end)->modify('+1 hour');
        $is_live   = ($now >= $vis_start && $now <= $vis_end);

        $result[] = [
            'id' => (int)$c['id'],
            'nama_client' => $c['nama_client'],
            'slug' => $c['slug'],
            'lokasi' => $c['lokasi'] ?? '',
            'tanggal_event' => $c['tanggal_event'],
            'jam_mulai' => $c['jam_mulai'],
            'jam_selesai' => $c['jam_selesai'],
            'direct_link' => $direct_link,
            'gallery_url' => rtrim($base_url, '/') . '/client/?c=' . urlencode($c['slug']),
            'folder_foto_path' => $c['folder_foto_path'],
            'photo_count' => $file_count,
            'is_live' => $is_live
        ];
    }

    echo json_encode([
        'status' => 'success',
        'server_time' => $now->format('Y-m-d H:i:s'),
        'events' => $result
    ]);
    exit;
}

// =========================================================================
// 2. ACTION: upload (Atomic & Checksum-Verified Upload dari LumaBooth)
// =========================================================================
if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $client_id = $_POST['client_id'] ?? '';
    $slug      = $_POST['slug'] ?? '';
    $album     = $_POST['album_name'] ?? '';
    $expected_sha1 = strtolower(trim($_POST['file_sha1'] ?? ($_SERVER['HTTP_X_FILE_SHA1'] ?? '')));
    $expected_size = isset($_POST['file_size']) ? (int)$_POST['file_size'] : 0;

    $client = null;
    if (!empty($client_id)) {
        $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
        $stmt->execute([$client_id]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);
    } elseif (!empty($slug)) {
        $stmt = $pdo->prepare("SELECT * FROM clients WHERE slug = ?");
        $stmt->execute([$slug]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);
    } elseif (!empty($album)) {
        $clean_album = strtolower(preg_replace('/\s+/', '', $album));
        $stmt = $pdo->query("SELECT * FROM clients ORDER BY tanggal_event DESC, id DESC");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $clean_c = strtolower(preg_replace('/\s+/', '', $c['nama_client']));
            if ($clean_c === $clean_album || strpos($clean_c, $clean_album) !== false || strpos($clean_album, $clean_c) !== false) {
                $client = $c;
                break;
            }
        }
    }

    if (!$client) {
        http_response_code(404);
        echo json_encode([
            'status' => 'error',
            'message' => 'Event/Client tidak ditemukan di database portal.'
        ]);
        exit;
    }

    if (!isset($_FILES['file']) && !isset($_FILES['photo'])) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'Tidak ada file yang dikirim (gunakan field "file" atau "photo").'
        ]);
        exit;
    }

    $uploaded = $_FILES['file'] ?? $_FILES['photo'];
    if ($uploaded['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'Upload terputus di tengah jalan (error code: ' . $uploaded['error'] . '). Akan diulang otomatis.'
        ]);
        exit;
    }

    // Validasi ukuran file (mencegah file terpotong saat internet putus tiba-tiba)
    $actual_tmp_size = @filesize($uploaded['tmp_name']);
    if ($expected_size > 0 && $actual_tmp_size !== $expected_size) {
        http_response_code(422);
        echo json_encode([
            'status' => 'error',
            'message' => "Ukuran file tidak utuh ($actual_tmp_size vs $expected_size bytes). Kemungkinan koneksi terputus."
        ]);
        exit;
    }

    // Validasi integritas SHA-1 (100% memastikan foto tidak korup/rusak separuh)
    if (!empty($expected_sha1)) {
        $actual_sha1 = strtolower(@sha1_file($uploaded['tmp_name']));
        if ($actual_sha1 !== $expected_sha1) {
            http_response_code(422);
            echo json_encode([
                'status' => 'error',
                'message' => 'Checksum SHA-1 tidak cocok (file korup saat transmisi). Akan diupload ulang.'
            ]);
            exit;
        }
    }

    $orig_name = basename($uploaded['name']);
    $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
    $allowed_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'mov', 'webm'];

    if (!in_array($ext, $allowed_exts)) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'Format file tidak didukung: .' . $ext
        ]);
        exit;
    }

    // Buat direktori penyimpanan galeri klien: uploads/gallery/{slug}/
    $rel_folder = 'uploads/gallery/' . $client['slug'];
    $target_dir = '../' . $rel_folder;
    if (!file_exists($target_dir)) {
        mkdir($target_dir, 0777, true);
    }

    $safe_basename = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $orig_name);
    $media_category = isset($_POST['category']) ? preg_replace('/[^a-z0-9_]/', '', strtolower($_POST['category'])) : '';
    if (!empty($media_category) && in_array($media_category, ['prints', 'gifs', 'videos', 'originals'])) {
        if (strpos($safe_basename, $media_category . '_') !== 0) {
            $safe_basename = $media_category . '_' . $safe_basename;
        }
    }

    $target_path = $target_dir . '/' . $safe_basename;

    // Jika file sudah ada di server dan ukurannya + SHA1-nya identik, langsung nyatakan sukses (idempotent)
    if (file_exists($target_path)) {
        $existing_size = @filesize($target_path);
        $same_file = ($existing_size === $actual_tmp_size);
        if ($same_file && !empty($expected_sha1)) {
            $same_file = (strtolower(@sha1_file($target_path)) === $expected_sha1);
        }
        if ($same_file) {
            $year = date('Y', strtotime($client['tanggal_event']));
            $clean_name = preg_replace('/\s+/', '', $client['nama_client']);
            $direct_link = rtrim($base_url, '/') . '/' . $year . '/' . $clean_name;

            echo json_encode([
                'status' => 'skipped',
                'message' => 'File utuh sudah ada di server.',
                'file_name' => $safe_basename,
                'file_sha1' => $expected_sha1,
                'file_url' => rtrim($base_url, '/') . '/' . $rel_folder . '/' . rawurlencode($safe_basename),
                'direct_link' => $direct_link
            ]);
            exit;
        }
    }

    // Atomic Write: pindahkan ke file .part sementara dulu, baru rename ke nama asli
    // supaya galeri klien tidak pernah menampilkan gambar yang sedang setengah ditulis
    $temp_target = $target_path . '.part_' . uniqid();
    if (!move_uploaded_file($uploaded['tmp_name'], $temp_target)) {
        @unlink($temp_target);
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Gagal menyimpan file sementara di server.'
        ]);
        exit;
    }

    if (!@rename($temp_target, $target_path)) {
        if (!@copy($temp_target, $target_path)) {
            @unlink($temp_target);
            http_response_code(500);
            echo json_encode([
                'status' => 'error',
                'message' => 'Gagal memfinalisasi file di server.'
            ]);
            exit;
        }
        @unlink($temp_target);
    }

    $auto_set = $_POST['auto_set_gallery'] ?? '1';
    if ($auto_set === '1') {
        $current_path = trim($client['folder_foto_path']);
        if (empty($current_path) || strpos($current_path, 'uploads/gallery/') === 0 || $current_path === '#') {
            $upd = $pdo->prepare("UPDATE clients SET folder_foto_path = ? WHERE id = ?");
            $upd->execute([$rel_folder, $client['id']]);
        }
    }

    $year = date('Y', strtotime($client['tanggal_event']));
    $clean_name = preg_replace('/\s+/', '', $client['nama_client']);
    $direct_link = rtrim($base_url, '/') . '/' . $year . '/' . $clean_name;
    $single_view_url = rtrim($base_url, '/') . '/client/?c=' . urlencode($client['slug']) . '&file=' . rawurlencode($safe_basename);

    echo json_encode([
        'status' => 'success',
        'message' => 'File berhasil diupload dan terverifikasi utuh.',
        'client_name' => $client['nama_client'],
        'slug' => $client['slug'],
        'file_name' => $safe_basename,
        'file_sha1' => $expected_sha1,
        'file_url' => rtrim($base_url, '/') . '/' . $rel_folder . '/' . rawurlencode($safe_basename),
        'single_photo_url' => $single_view_url,
        'gallery_url' => rtrim($base_url, '/') . '/client/?c=' . urlencode($client['slug']),
        'direct_link' => $direct_link
    ]);
    exit;
}

// =========================================================================
// 3. ACTION: set_portal_mode (Mengatur agar event menggunakan Galeri Internal)
// =========================================================================
if ($action === 'set_portal_mode' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $client_id = $_POST['client_id'] ?? '';
    $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
    $stmt->execute([$client_id]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$client) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Client tidak ditemukan']);
        exit;
    }

    $rel_folder = 'uploads/gallery/' . $client['slug'];
    if (!file_exists('../' . $rel_folder)) {
        mkdir('../' . $rel_folder, 0777, true);
    }

    $upd = $pdo->prepare("UPDATE clients SET folder_foto_path = ? WHERE id = ?");
    $upd->execute([$rel_folder, $client['id']]);

    echo json_encode([
        'status' => 'success',
        'message' => 'Tujuan galeri klien berhasil diatur ke Portal Gallery Internal (' . $rel_folder . ').',
        'folder_foto_path' => $rel_folder
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
