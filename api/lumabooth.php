<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-KEY');

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
    http_response_code(401);
    echo json_encode([
        'status' => 'error',
        'message' => 'Unauthorized: Invalid API Key / PIN (Gunakan PIN Operator: 8080 atau yuhu8080)'
    ]);
    exit;
}

$action = $_GET['action'] ?? ($_POST['action'] ?? 'list_events');

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
// 2. ACTION: upload (Menerima upload foto/GIF/MP4 dari LumaBooth Windows)
// =========================================================================
if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $client_id = $_POST['client_id'] ?? '';
    $slug      = $_POST['slug'] ?? '';
    $album     = $_POST['album_name'] ?? '';

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
        // Cari berdasarkan kecocokan nama event / album LumaBooth
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
            'message' => 'Upload error code: ' . $uploaded['error']
        ]);
        exit;
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

    // Bersihkan nama file agar aman di URL namun tetap menjaga urutan LumaBooth
    $safe_basename = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $orig_name);
    // Opsional: subfolder tipe file jika dikirim dari LumaBooth (prints, gifs, videos, originals)
    $media_category = isset($_POST['category']) ? preg_replace('/[^a-z0-9_]/', '', strtolower($_POST['category'])) : '';
    if (!empty($media_category) && in_array($media_category, ['prints', 'gifs', 'videos', 'originals'])) {
        $safe_basename = $media_category . '_' . $safe_basename;
    }

    $target_path = $target_dir . '/' . $safe_basename;

    // Jika mode overwrite=0 dan file sudah ada dengan ukuran sama, lewati
    if (file_exists($target_path) && filesize($target_path) === $uploaded['size']) {
        $year = date('Y', strtotime($client['tanggal_event']));
        $clean_name = preg_replace('/\s+/', '', $client['nama_client']);
        $direct_link = rtrim($base_url, '/') . '/' . $year . '/' . $clean_name;

        echo json_encode([
            'status' => 'skipped',
            'message' => 'File sudah ada di server.',
            'file_name' => $safe_basename,
            'file_url' => rtrim($base_url, '/') . '/' . $rel_folder . '/' . rawurlencode($safe_basename),
            'direct_link' => $direct_link
        ]);
        exit;
    }

    if (!move_uploaded_file($uploaded['tmp_name'], $target_path)) {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Gagal menyimpan file di server. Periksa permission folder uploads/.'
        ]);
        exit;
    }

    // Jika folder_foto_path klien masih kosong atau mengarah ke luar tapi admin ingin otomatis menggunakan galeri internal,
    // atau jika auto_set_gallery=1 dikirim dari aplikasi sync:
    $auto_set = $_POST['auto_set_gallery'] ?? '1';
    if ($auto_set === '1') {
        $current_path = trim($client['folder_foto_path']);
        // Jika masih kosong, placeholder, atau sudah mengarah ke uploads/ atau client/, pastikan menunjuk ke folder galeri ini
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
        'message' => 'File berhasil diupload ke Portal Gallery.',
        'client_name' => $client['nama_client'],
        'slug' => $client['slug'],
        'file_name' => $safe_basename,
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
