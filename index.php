<?php
require_once 'config.php';

// Ambil semua klien
$stmt = $pdo->query("SELECT * FROM clients");
$all_clients = $stmt->fetchAll();

$active_clients = [];
$now = new DateTime('now', new DateTimeZone('Asia/Jakarta'));

foreach ($all_clients as $client) {
    $start = new DateTime($client['tanggal_event'] . ' ' . $client['jam_mulai'], new DateTimeZone('Asia/Jakarta'));
    $end   = new DateTime($client['tanggal_event'] . ' ' . $client['jam_selesai'], new DateTimeZone('Asia/Jakarta'));
    
    $visible_start = clone $start;
    $visible_start->modify('-1 hour');
    
    $visible_end = clone $end;
    $visible_end->modify('+1 hour');
    
    if ($now >= $visible_start && $now <= $visible_end) {
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
        <div class="mb-10 flex flex-col items-center justify-center text-center">
            <img src="https://qr.yuhu.co.id/client/logo.png" alt="Yuhu Logo" class="h-16 md:h-20 w-auto mb-3 drop-shadow-sm transition-transform duration-300 hover:scale-105">
            <p class="text-gray-500 font-medium tracking-wide">Photobooth Portal</p>
        </div>

        <div class="w-full max-w-md bg-white rounded-2xl shadow-xl overflow-hidden p-6">
            <h2 class="text-xl font-semibold text-gray-800 mb-6 text-center">Live Events</h2>
            
            <div id="event-list" class="space-y-4">
                <?php if (count($active_clients) > 0): ?>
                    <?php foreach($active_clients as $c): ?>
                        <a href="<?= htmlspecialchars($c['folder_foto_path']) ?>" target="_blank" class="block group relative p-4 bg-gray-50 border border-gray-100 rounded-xl hover:bg-blue-50 hover:border-blue-200 transition-all duration-300">
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

    <script>
        // Fitur Auto-Refresh "Tanpa Kedip" (AJAX)
        // Mengecek ke server setiap 30 detik apakah ada perubahan data
        setInterval(() => {
            fetch(window.location.href)
                .then(response => response.text())
                .then(html => {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, 'text/html');
                    const newContent = doc.querySelector('#event-list').innerHTML;
                    const currentElement = document.querySelector('#event-list');
                    
                    // Jika ada perbedaan (event baru mulai / event selesai / admin menambah event)
                    if (newContent.trim() !== currentElement.innerHTML.trim()) {
                        // Terapkan animasi transisi (fade out & in) agar halus
                        currentElement.style.opacity = 0;
                        setTimeout(() => {
                            currentElement.innerHTML = newContent;
                            currentElement.style.transition = 'opacity 0.5s ease-in-out';
                            currentElement.style.opacity = 1;
                        }, 300);
                    }
                })
                .catch(err => console.error('Gagal mengecek update otomatis:', err));
        }, 30000); // 30000 ms = 30 detik
    </script>
</body>
</html>