<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

require_once '../config.php';

// 1. Password/Token Keamanan (Mendukung token YUHU v5 & token Portal)
$VALID_TOKENS = ["YuhuSecretToken2026", "yuhu8080", "8080"];

// 2. Cek autentikasi token keamanan
$headers = function_exists('apache_request_headers') ? apache_request_headers() : [];
$auth_token = '';
if (isset($headers['Authorization'])) {
    $auth_token = trim(str_replace('Bearer ', '', $headers['Authorization']));
} elseif (isset($_SERVER['HTTP_X_API_KEY'])) {
    $auth_token = trim($_SERVER['HTTP_X_API_KEY']);
} elseif (isset($_POST['token'])) {
    $auth_token = trim($_POST['token']);
} elseif (isset($_POST['api_key'])) {
    $auth_token = trim($_POST['api_key']);
} elseif (isset($_GET['token'])) {
    $auth_token = trim($_GET['token']);
}

if (!in_array($auth_token, $VALID_TOKENS, true)) {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "Ditolak: Token keamanan tidak valid"]);
    exit;
}

// Health check ping (untuk worker memeriksa sinyal internet)
if (isset($_GET['action']) && $_GET['action'] === 'ping') {
    echo json_encode(["status" => "success", "message" => "pong"]);
    exit;
}

// 3. Cek parameter Event / Client dari LumaBooth (nama folder di C:\dslrBooth\<EventName>)
$client_event = isset($_POST['client_event']) ? trim($_POST['client_event']) : '';
if (empty($client_event)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Gagal: Nama event harus diisi (client_event)"]);
    exit;
}

// Bersihkan karakter berbahaya (Path Traversal protection)
$client_event = str_replace(['..', '/', '\\', "\0"], '', $client_event);
if (empty($client_event)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Gagal: Nama event tidak valid"]);
    exit;
}

// Abaikan jika folder sistem dslrBooth
$event_lower = strtolower($client_event);
if ($event_lower === 'settings' || $event_lower === 'templates' || $event_lower === 'thumb') {
    echo json_encode(["status" => "skipped", "message" => "System folder diabaikan"]);
    exit;
}

$base_dir = __DIR__ . '/photos/';
if (!is_dir($base_dir)) {
    @mkdir($base_dir, 0777, true);
}

// Cek apakah sudah ada folder dengan nama yang ekuivalen (fuzzy match spasi/underscore)
$matched_folder = $client_event;
$req_clean = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $client_event));
if (!is_dir($base_dir . $client_event)) {
    $existing_dirs = @scandir($base_dir) ?: [];
    foreach ($existing_dirs as $d) {
        if ($d !== '.' && $d !== '..' && is_dir($base_dir . $d)) {
            if (strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $d)) === $req_clean && $req_clean !== '') {
                $matched_folder = $d;
                break;
            }
        }
    }
}

$event_dir = $base_dir . $matched_folder . '/';
if (!is_dir($event_dir)) {
    @mkdir($event_dir, 0777, true);
}

// =========================================================================
// AUTO-SYNC DENGAN DATABASE PORTAL QR (photobooth_db -> tabel clients)
// =========================================================================
// 1. Cari apakah klien sudah ada di database (cocokkan nama_client / slug)
$matched_client_db = null;
try {
    $stmt_all = $pdo->query("SELECT * FROM clients ORDER BY id DESC");
    $all_db_clients = $stmt_all->fetchAll(PDO::FETCH_ASSOC);
    foreach ($all_db_clients as $row) {
        $db_clean_name = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $row['nama_client']));
        $db_clean_slug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $row['slug']));
        if ($req_clean !== '' && ($db_clean_name === $req_clean || $db_clean_slug === $req_clean || strpos($db_clean_name, $req_clean) !== false || strpos($req_clean, $db_clean_name) !== false)) {
            $matched_client_db = $row;
            break;
        }
    }

    $internal_gallery_link = rtrim($base_url, '/') . '/client/?client=' . rawurlencode($matched_folder);

    if ($matched_client_db) {
        // Jika folder_foto_path masih kosong atau sudah mengarah ke /client/ lokal, pastikan tersinkronisasi
        $cur_path = trim($matched_client_db['folder_foto_path'] ?? '');
        if (empty($cur_path) || stripos($cur_path, '/client/') !== false || stripos($cur_path, 'uploads/gallery/') !== false) {
            $upd = $pdo->prepare("UPDATE clients SET folder_foto_path = ? WHERE id = ?");
            $upd->execute([$internal_gallery_link, $matched_client_db['id']]);
        }
    } else {
        // Jika admin belum sempat menginput di dashboard, otomatis buatkan entri Live Event hari ini!
        $readable_name = trim(str_replace('_', ' ', $matched_folder));
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $readable_name))) . '-' . time();
        $tanggal_event = date('Y-m-d');
        $jam_mulai = date('H:00:00');
        $jam_selesai = date('H:i:s', strtotime('+5 hours'));
        $lokasi_default = 'YUHU Photobooth Live Event';

        // Buat QR Code otomatis untuk Direct Link
        $qr_folder_path = __DIR__ . '/../uploads/qrcodes';
        if (!file_exists($qr_folder_path)) {
            @mkdir($qr_folder_path, 0777, true);
        }
        $tahun_event = date('Y');
        $nama_clean = preg_replace('/\s+/', '', $readable_name);
        $custom_direct_link = rtrim($base_url, '/') . '/' . $tahun_event . '/' . $nama_clean;
        $qr_api = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($custom_direct_link);
        $qr_filename = $slug . '.png';
        $qr_filepath = $qr_folder_path . '/' . $qr_filename;

        if (function_exists('curl_init')) {
            $ch = curl_init($qr_api);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            $img = @curl_exec($ch);
            if ($img) @file_put_contents($qr_filepath, $img);
            curl_close($ch);
        }

        $ins = $pdo->prepare("INSERT INTO clients (nama_client, slug, lokasi, tanggal_event, jam_mulai, jam_selesai, qr_code_path, folder_foto_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $ins->execute([
            $readable_name,
            $slug,
            $lokasi_default,
            $tanggal_event,
            $jam_mulai,
            $jam_selesai,
            'uploads/qrcodes/' . $qr_filename,
            $internal_gallery_link
        ]);
    }
} catch (Exception $e) {
    // Tetap lanjutkan penyimpanan foto meskipun sinkronisasi DB mengalami kendala
}

// 4. Cek File yang masuk
if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Gagal: File korup atau tidak ada file yang dikirim"]);
    exit;
}

$tmp_name = $_FILES['file']['tmp_name'];
$original_name = basename($_FILES['file']['name']);
$ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));

// Validasi ukuran file (opsional jika dikirim dari script YUHU v5)
$actual_size = @filesize($tmp_name);
if (isset($_POST['file_size']) && (int)$_POST['file_size'] > 0) {
    if ($actual_size !== (int)$_POST['file_size']) {
        http_response_code(422);
        echo json_encode(["status" => "error", "message" => "Ukuran file tidak utuh (terputus saat upload)"]);
        exit;
    }
}

// 5. Tentukan Target Sub-Folder Otomatis (Prints, Animated, Singles, Videos, Originals)
$target_folder = isset($_POST['folder_target']) ? trim($_POST['folder_target']) : '';
$target_folder = str_replace(['..', '/', '\\', "\0"], '', $target_folder);

if (empty($target_folder)) {
    if (in_array($ext, ['mp4', 'gif', 'mov', 'webm'])) {
        $target_folder = 'Animated';
    } elseif (strpos(strtolower($original_name), 'print') !== false || strpos(strtolower($original_name), 'template') !== false) {
        $target_folder = 'Prints';
    } else {
        $target_folder = 'Singles';
    }
}

// Normalisasi nama folder standar LumaBooth
$tf_lower = strtolower($target_folder);
if ($tf_lower === 'prints' || $tf_lower === 'print') $target_folder = 'Prints';
elseif ($tf_lower === 'singles' || $tf_lower === 'single') $target_folder = 'Singles';
elseif ($tf_lower === 'animated' || $tf_lower === 'gifs' || $tf_lower === 'gif' || $tf_lower === 'videos' || $tf_lower === 'video') $target_folder = 'Animated';
elseif ($tf_lower === 'originals' || $tf_lower === 'original') $target_folder = 'Originals';

// Di folder Animated, abaikan file JPG thumbnail pendamping MP4 LumaBooth (seperti 20260821_231200_371_mp4.jpg)
if ($target_folder === 'Animated' && in_array($ext, ['jpg', 'jpeg', 'png'])) {
    echo json_encode(["status" => "skipped", "message" => "Thumbnail JPG di folder Animated diabaikan"]);
    exit;
}

$upload_dir = $event_dir . $target_folder . '/';
if (!is_dir($upload_dir)) {
    @mkdir($upload_dir, 0777, true);
}

// 6. Simpan File dengan Atomic Write (.part -> rename) agar tidak pernah tampil setengah korup
$filename = preg_replace('/[^a-zA-Z0-9_.\-& ]/', '', $original_name);
$target_path = $upload_dir . $filename;

// Jika file sudah ada dan ukurannya sama persis, lewati (idempotent)
if (file_exists($target_path) && @filesize($target_path) === $actual_size && $actual_size > 0) {
    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "message" => "File $filename sudah ada secara utuh di folder $target_folder",
        "file" => $filename,
        "folder" => $target_folder,
        "event" => $matched_folder
    ]);
    exit;
}

$temp_path = $target_path . '.part_' . uniqid();
if (move_uploaded_file($tmp_name, $temp_path)) {
    if (!@rename($temp_path, $target_path)) {
        @copy($temp_path, $target_path);
        @unlink($temp_path);
    }
    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "message" => "Berhasil! File $filename mendarat di folder $matched_folder/$target_folder",
        "file" => $filename,
        "folder" => $target_folder,
        "event" => $matched_folder
    ]);
} else {
    @unlink($temp_path);
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Internal Server Error: Gagal menyimpan file di server"]);
}
