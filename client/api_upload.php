<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
@set_time_limit(300);

require_once '../config.php';
// Lepaskan pengunci sesi PHP seketika agar 4+ komputer yang upload bersamaan tidak saling antre!
if (session_status() === PHP_SESSION_ACTIVE) {
    @session_write_close();
}
define('LUMABOOTH_SKIP_AUTOSYNC', true);
require_once __DIR__ . '/../lumabooth_sync.php';

// 1. Password/Token Keamanan (Mendukung token YUHU v5-v11 & token Portal)
$VALID_TOKENS = ["YuhuSecretToken2026", "yuhu8080", "8080"];

// 2. Cek autentikasi token keamanan (dari Header, POST, atau GET)
$headers = function_exists('apache_request_headers') ? apache_request_headers() : [];
$auth_token = '';
if (isset($headers['Authorization'])) {
    $auth_token = trim(str_replace('Bearer ', '', $headers['Authorization']));
} elseif (isset($_SERVER['HTTP_X_API_TOKEN'])) {
    $auth_token = trim($_SERVER['HTTP_X_API_TOKEN']);
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
    http_response_code(403);
    exit;
}

// Health check ping (untuk worker memeriksa sinyal internet)
if (isset($_GET['action']) && $_GET['action'] === 'ping') {
    echo json_encode(["status" => "success", "message" => "pong"]);
    exit;
}

// 3. Cek parameter Event / Client dari LumaBooth (mendukung Header X-Client-Event, POST, atau GET)
$client_event = '';
if (!empty($_SERVER['HTTP_X_CLIENT_EVENT'])) {
    $client_event = trim(rawurldecode($_SERVER['HTTP_X_CLIENT_EVENT']));
} elseif (isset($_POST['client_event'])) {
    $client_event = trim($_POST['client_event']);
} elseif (isset($_GET['client_event'])) {
    $client_event = trim($_GET['client_event']);
}

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

// Bersihkan file dummy pengujian jika ada
@unlink($base_dir . 'The_Wedding_of_Cipung_Cipang/Prints/test_1900kb.jpg');

// =========================================================================
// AUTO-SYNC DENGAN DATABASE PORTAL QR (photobooth_db -> tabel clients)
// Otomatis membuat link di files.yuhu.co.id sesuai nama project LumaBooth!
// =========================================================================
$synced_client = ensureLumaboothEventInDb($pdo, $base_url, $matched_folder, true);

// Jika hanya request registrasi folder event tanpa upload file
if (isset($_REQUEST['action']) && $_REQUEST['action'] === 'register_event') {
    $year_ev = $synced_client ? date('Y', strtotime($synced_client['tanggal_event'])) : date('Y');
    $clean_ev = $synced_client ? preg_replace('/\s+/', '', $synced_client['nama_client']) : preg_replace('/\s+/', '', $matched_folder);
    echo json_encode([
        "status" => "success",
        "message" => "Event $matched_folder terdaftar otomatis di Portal QR",
        "event" => $matched_folder,
        "client" => $synced_client,
        "direct_link" => rtrim($base_url, '/') . '/' . $year_ev . '/' . $clean_ev,
        "gallery_link" => rtrim($base_url, '/') . '/client/' . rawurlencode($matched_folder)
    ]);
    exit;
}

// 4. Tentukan mode upload:
//    Mode A: Raw Binary Stream (php://input dengan Header X-File-Name atau GET file_name)
//            -> Bebas batas upload_max_filesize=2M bawaan php.ini untuk foto/video ukuran besar!
//    Mode B: Multipart Form-Data standar ($_FILES['file'])
$is_raw_stream = false;
$original_name = '';
$expected_size = 0;

if (!empty($_SERVER['HTTP_X_FILE_SIZE'])) {
    $expected_size = (int)$_SERVER['HTTP_X_FILE_SIZE'];
} elseif (isset($_REQUEST['file_size'])) {
    $expected_size = (int)$_REQUEST['file_size'];
}

if (!empty($_SERVER['HTTP_X_FILE_NAME'])) {
    $original_name = basename(rawurldecode(trim($_SERVER['HTTP_X_FILE_NAME'])));
    $is_raw_stream = true;
} elseif (!empty($_GET['file_name'])) {
    $original_name = basename(trim($_GET['file_name']));
    $is_raw_stream = true;
}

$tmp_name = '';
if (!$is_raw_stream) {
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $err_code = isset($_FILES['file']['error']) ? (int)$_FILES['file']['error'] : -1;
        http_response_code(400);
        echo json_encode([
            "status" => "error",
            "error_code" => $err_code,
            "message" => "Gagal: File korup atau melebihi batas upload multipart (err=$err_code)"
        ]);
        exit;
    }
    $tmp_name = $_FILES['file']['tmp_name'];
    $original_name = basename($_FILES['file']['name']);
}

$ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));

// 5. Tentukan Target Sub-Folder Otomatis (Prints, Animated, Singles, Videos, Originals)
$target_folder = '';
if (!empty($_SERVER['HTTP_X_FOLDER_TARGET'])) {
    $target_folder = trim($_SERVER['HTTP_X_FOLDER_TARGET']);
} elseif (isset($_REQUEST['folder_target'])) {
    $target_folder = trim($_REQUEST['folder_target']);
}
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

$filename = preg_replace('/[^a-zA-Z0-9_.\-& ]/', '', $original_name);
if (empty($filename)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Nama file tidak valid"]);
    exit;
}
$target_path = $upload_dir . $filename;

// Jika file sudah ada secara utuh dengan ukuran yang sama (idempotent retry), langsung sukses tanpa perlu menulis ulang!
if ($expected_size > 0 && file_exists($target_path) && @filesize($target_path) === $expected_size) {
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

$temp_path = $target_path . '.part_' . uniqid('', true) . '_' . mt_rand(1000, 9999);

if ($is_raw_stream) {
    $in = @fopen('php://input', 'rb');
    $out = @fopen($temp_path, 'wb');
    if (!$in || !$out) {
        if ($in) @fclose($in);
        if ($out) @fclose($out);
        @unlink($temp_path);
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Gagal membuka stream di server"]);
        exit;
    }
    $written = @stream_copy_to_stream($in, $out);
    @fclose($in);
    @fclose($out);
    clearstatcache(true, $temp_path);
    $actual_size = @filesize($temp_path);
    if ($actual_size === false || $actual_size === 0 || ($expected_size > 0 && $actual_size !== $expected_size)) {
        @unlink($temp_path);
        http_response_code(422);
        echo json_encode([
            "status" => "error",
            "message" => "Ukuran stream tidak utuh (diterima $actual_size dari $expected_size bytes)"
        ]);
        exit;
    }
} else {
    $actual_size = @filesize($tmp_name);
    if ($expected_size > 0 && $actual_size !== $expected_size) {
        http_response_code(422);
        echo json_encode(["status" => "error", "message" => "Ukuran file tidak utuh (terputus saat upload)"]);
        exit;
    }
    if (!@move_uploaded_file($tmp_name, $temp_path)) {
        @unlink($temp_path);
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Internal Server Error: Gagal menyimpan file di server"]);
        exit;
    }
}

// Multi-PC Collision Protection: Cek apakah sudah ada file dengan nama sama di tujuan
if (file_exists($target_path) && $actual_size > 0) {
    $existing_size = @filesize($target_path);
    if ($existing_size === $actual_size) {
        @unlink($temp_path);
        http_response_code(200);
        echo json_encode([
            "status" => "success",
            "message" => "File $filename sudah ada secara utuh di folder $target_folder",
            "file" => $filename,
            "folder" => $target_folder,
            "event" => $matched_folder
        ]);
        exit;
    } else {
        $file_hash = substr(@md5_file($temp_path) ?: uniqid(), 0, 4);
        $base_no_ext = pathinfo($filename, PATHINFO_FILENAME);
        $filename = $base_no_ext . '_' . $file_hash . '.' . $ext;
        $target_path = $upload_dir . $filename;
        if (file_exists($target_path) && @filesize($target_path) === $actual_size) {
            @unlink($temp_path);
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
    }
}

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
