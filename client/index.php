<?php
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
</html>