import os

base_dir = r"C:\Users\user\.gemini\antigravity\scratch\qr-photobooth"

files = {
    'database.sql': '''CREATE DATABASE IF NOT EXISTS photobooth_db;
USE photobooth_db;

CREATE TABLE IF NOT EXISTS admin (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL,
    password VARCHAR(255) NOT NULL
);

-- Default admin: admin / yuhu8080 (hashed)
INSERT INTO admin (username, password) VALUES ('admin', '$2y$10$wN35rZqQ6kP3TjG39W2Ebe98q7XfO.gR02o4v5.n.009k1hY6f3pC');

CREATE TABLE IF NOT EXISTS clients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nama_client VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL UNIQUE,
    tanggal_event DATE NOT NULL,
    jam_mulai TIME NOT NULL,
    jam_selesai TIME NOT NULL,
    qr_code_path VARCHAR(255) NOT NULL,
    folder_foto_path VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
''',
    'config.php': '''<?php
$host = 'localhost';
$db   = 'photobooth_db';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Koneksi gagal: " . $e->getMessage());
}
session_start();
$base_url = 'http://' . $_SERVER['HTTP_HOST'] . '/qr-photobooth';
?>''',
    'admin/login.php': '''<?php
require_once '../config.php';
if (isset($_SESSION['admin_id'])) {
    header("Location: index.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = $_POST['username'];
    $password = $_POST['password'];

    $stmt = $pdo->prepare("SELECT * FROM admin WHERE username = ?");
    $stmt->execute([$username]);
    $admin = $stmt->fetch();

    if ($admin && password_verify($password, $admin['password'])) {
        $_SESSION['admin_id'] = $admin['id'];
        header("Location: index.php");
        exit;
    } else {
        $error = "Username atau password salah!";
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - Portal QR Photobooth</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 h-screen flex items-center justify-center">
    <div class="bg-white p-8 rounded-lg shadow-md w-96">
        <h2 class="text-2xl font-bold mb-6 text-center text-gray-800">Admin Login</h2>
        <?php if(isset($error)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4"><?= $error ?></div>
        <?php endif; ?>
        <form method="POST" action="">
            <div class="mb-4">
                <label class="block text-gray-700 text-sm font-bold mb-2">Username</label>
                <input type="text" name="username" class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:border-blue-500" required>
            </div>
            <div class="mb-6">
                <label class="block text-gray-700 text-sm font-bold mb-2">Password</label>
                <input type="password" name="password" class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:border-blue-500" required>
            </div>
            <button type="submit" class="w-full bg-blue-600 text-white font-bold py-2 px-4 rounded-lg hover:bg-blue-700">Login</button>
        </form>
    </div>
</body>
</html>''',
    'admin/index.php': '''<?php
require_once '../config.php';
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit;
}

// Handle Add Client
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add') {
    $nama_client = $_POST['nama_client'];
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $nama_client)));
    $tanggal_event = $_POST['tanggal_event'];
    $jam_mulai = $_POST['jam_mulai'];
    $jam_selesai = $_POST['jam_selesai'];
    
    // Create folder for client photos
    $folder_path = '../uploads/' . $slug;
    if (!file_exists($folder_path)) {
        mkdir($folder_path, 0777, true);
    }

    // Generate QR Code via API
    $qr_data = $base_url . '/client/?c=' . $slug;
    $qr_api = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($qr_data);
    $qr_filename = $slug . '.png';
    $qr_filepath = '../uploads/qrcodes/' . $qr_filename;
    
    // Fetch and save QR image
    $qr_content = @file_get_contents($qr_api);
    if ($qr_content !== false) {
        file_put_contents($qr_filepath, $qr_content);
    }

    $stmt = $pdo->prepare("INSERT INTO clients (nama_client, slug, tanggal_event, jam_mulai, jam_selesai, qr_code_path, folder_foto_path) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$nama_client, $slug, $tanggal_event, $jam_mulai, $jam_selesai, 'uploads/qrcodes/'.$qr_filename, 'uploads/'.$slug]);
    header("Location: index.php");
    exit;
}

// Handle Delete Client
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM clients WHERE id = ?");
    $stmt->execute([$id]);
    header("Location: index.php");
    exit;
}

$clients = $pdo->query("SELECT * FROM clients ORDER BY created_at DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">
    <nav class="bg-white shadow-md p-4">
        <div class="container mx-auto flex justify-between items-center">
            <h1 class="text-xl font-bold text-gray-800">Photobooth Admin</h1>
            <div>
                <a href="change_password.php" class="text-blue-600 hover:underline mr-4">Ganti Password</a>
                <a href="logout.php" class="text-red-600 hover:underline">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container mx-auto mt-8 p-4">
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-2xl font-bold mb-4">Tambah Klien Baru</h2>
            <form method="POST" action="" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <input type="hidden" name="action" value="add">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Nama Klien</label>
                    <input type="text" name="nama_client" required class="mt-1 w-full px-3 py-2 border rounded-md">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Tanggal Event</label>
                    <input type="date" name="tanggal_event" required class="mt-1 w-full px-3 py-2 border rounded-md">
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
                    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700">Simpan & Generate QR</button>
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
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Jadwal</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Folder Foto</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">QR Code</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php foreach($clients as $c): ?>
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap"><?= htmlspecialchars($c['nama_client']) ?></td>
                            <td class="px-6 py-4 whitespace-nowrap"><?= $c['tanggal_event'] ?> <br> <?= $c['jam_mulai'] ?> - <?= $c['jam_selesai'] ?></td>
                            <td class="px-6 py-4 whitespace-nowrap"><code class="bg-gray-100 p-1 rounded"><?= $c['folder_foto_path'] ?></code></td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <a href="<?= '../' . $c['qr_code_path'] ?>" target="_blank" class="text-blue-600 hover:underline">Lihat QR</a>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <a href="?delete=<?= $c['id'] ?>" onclick="return confirm('Yakin ingin menghapus?')" class="text-red-600 hover:underline">Hapus</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>''',
    'admin/logout.php': '''<?php
session_start();
session_destroy();
header("Location: login.php");
exit;
?>''',
    'admin/change_password.php': '''<?php
require_once '../config.php';
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $new_pass = $_POST['new_password'];
    $hash = password_hash($new_pass, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("UPDATE admin SET password = ? WHERE id = ?");
    $stmt->execute([$hash, $_SESSION['admin_id']]);
    $msg = "Password berhasil diubah!";
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Ganti Password</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 flex items-center justify-center h-screen">
    <div class="bg-white p-8 rounded-lg shadow-md w-96">
        <h2 class="text-2xl font-bold mb-6">Ganti Password</h2>
        <?php if(isset($msg)): ?>
            <div class="bg-green-100 text-green-700 px-4 py-2 mb-4 rounded"><?= $msg ?></div>
        <?php endif; ?>
        <form method="POST" action="">
            <div class="mb-4">
                <label class="block text-sm font-medium mb-1">Password Baru</label>
                <input type="password" name="new_password" required class="w-full px-3 py-2 border rounded-md">
            </div>
            <button type="submit" class="w-full bg-blue-600 text-white py-2 rounded-md hover:bg-blue-700">Simpan</button>
            <a href="index.php" class="block text-center mt-4 text-blue-600">Kembali ke Dashboard</a>
        </form>
    </div>
</body>
</html>''',
    'index.php': '''<?php
require_once 'config.php';

// Waktu saat ini (Server Time)
$current_time = time();

// Ambil semua klien
$stmt = $pdo->query("SELECT * FROM clients");
$all_clients = $stmt->fetchAll();

$active_clients = [];

foreach ($all_clients as $client) {
    // LOGIKA VISIBILITAS (1 Jam Sebelum s/d 1 Jam Sesudah)
    
    // Gabungkan tanggal event dan jam untuk mendapatkan timestamp presisi
    $event_start_ts = strtotime($client['tanggal_event'] . ' ' . $client['jam_mulai']);
    $event_end_ts   = strtotime($client['tanggal_event'] . ' ' . $client['jam_selesai']);
    
    // Visibilitas dimulai 1 Jam (3600 detik) sebelum jam mulai
    $visible_start = $event_start_ts - 3600;
    
    // Visibilitas berakhir 1 Jam (3600 detik) sesudah jam selesai
    $visible_end   = $event_end_ts + 3600;
    
    // Jika waktu server saat ini berada di antara batas waktu tampil
    if ($current_time >= $visible_start && $current_time <= $visible_end) {
        $active_clients[] = $client;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portal Photobooth</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap');
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body class="bg-gray-50 flex flex-col min-h-screen">
    <div class="flex-grow flex flex-col items-center justify-center p-4">
        <!-- Logo Brand -->
        <div class="mb-10 text-center">
            <h1 class="text-4xl font-extrabold text-gray-900 tracking-tight">YUHU<span class="text-blue-600">.</span></h1>
            <p class="text-gray-500 mt-2">Photobooth Portal</p>
        </div>

        <div class="w-full max-w-md bg-white rounded-2xl shadow-xl overflow-hidden p-6">
            <h2 class="text-xl font-semibold text-gray-800 mb-6 text-center">Live Events</h2>
            
            <div class="space-y-4">
                <?php if (count($active_clients) > 0): ?>
                    <?php foreach($active_clients as $c): ?>
                        <a href="client/?c=<?= $c['slug'] ?>" class="block group relative p-4 bg-gray-50 border border-gray-100 rounded-xl hover:bg-blue-50 hover:border-blue-200 transition-all duration-300">
                            <div class="flex justify-between items-center">
                                <div>
                                    <h3 class="font-bold text-gray-900 group-hover:text-blue-700 text-lg"><?= htmlspecialchars($c['nama_client']) ?></h3>
                                    <p class="text-xs text-gray-500 mt-1">Live Now</p>
                                </div>
                                <div class="text-blue-500">
                                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-center py-8 text-gray-400">
                        <svg class="mx-auto h-12 w-12 mb-3 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        <p>Tidak ada event yang sedang berlangsung saat ini.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <footer class="text-center py-6 text-sm text-gray-400">
        &copy; <?= date('Y') ?> Yuhu Photobooth. All rights reserved.
    </footer>
</body>
</html>''',
    'client/index.php': '''<?php
require_once '../config.php';

if (!isset($_GET['c'])) {
    die("Klien tidak ditemukan.");
}

$slug = $_GET['c'];
$stmt = $pdo->prepare("SELECT * FROM clients WHERE slug = ?");
$stmt->execute([$slug]);
$client = $stmt->fetch();

if (!$client) {
    die("Data klien tidak valid.");
}

// Ambil foto dari folder klien
$folder_path = '../' . $client['folder_foto_path'];
$photos = [];
if (is_dir($folder_path)) {
    $files = scandir($folder_path);
    foreach ($files as $file) {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
            $photos[] = $client['folder_foto_path'] . '/' . $file;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($client['nama_client']) ?> - Gallery</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap');
        body { font-family: 'Inter', sans-serif; }
        /* Masonry Grid CSS */
        .masonry {
            column-count: 2;
            column-gap: 1rem;
        }
        @media (min-width: 768px) { .masonry { column-count: 3; } }
        @media (min-width: 1024px) { .masonry { column-count: 4; } }
        .masonry-item {
            break-inside: avoid;
            margin-bottom: 1rem;
            position: relative;
        }
    </style>
</head>
<body class="bg-gray-100">
    <!-- Header -->
    <header class="bg-white shadow-sm sticky top-0 z-50">
        <div class="container mx-auto px-4 py-4 flex justify-between items-center">
            <h1 class="text-xl font-bold text-gray-800"><?= htmlspecialchars($client['nama_client']) ?></h1>
            <a href="../" class="text-sm font-medium text-gray-500 hover:text-gray-900 bg-gray-100 px-3 py-1 rounded-full">Kembali</a>
        </div>
    </header>

    <main class="container mx-auto px-4 py-8">
        <?php if (count($photos) > 0): ?>
            <div class="masonry">
                <?php foreach($photos as $index => $photo): ?>
                    <div class="masonry-item group rounded-xl overflow-hidden shadow-sm hover:shadow-md bg-white">
                        <img src="../<?= $photo ?>" alt="Foto" class="w-full h-auto object-cover transition-transform duration-300 group-hover:scale-105" loading="lazy">
                        <!-- Overlay Download Button -->
                        <div class="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition-opacity duration-300 flex items-center justify-center">
                            <a href="../<?= $photo ?>" download class="bg-white text-gray-900 rounded-full p-3 shadow-lg transform translate-y-4 group-hover:translate-y-0 transition-all duration-300">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="text-center py-20">
                <svg class="mx-auto h-16 w-16 text-gray-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                <h3 class="text-lg font-medium text-gray-900">Belum ada foto</h3>
                <p class="mt-1 text-gray-500">Foto akan segera diunggah oleh admin ke folder <code><?= $client['folder_foto_path'] ?></code>.</p>
            </div>
        <?php endif; ?>
    </main>
</body>
</html>'''
}

for name, content in files.items():
    path = os.path.join(base_dir, name)
    with open(path, 'w', encoding='utf-8') as f:
        f.write(content)

print("Files generated successfully.")
