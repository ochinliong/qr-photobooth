<?php
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
</html>