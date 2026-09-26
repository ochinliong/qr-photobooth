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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Portal Photobooth</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap');
        body { 
            font-family: 'Inter', sans-serif; 
            touch-action: pan-y; /* Hanya izinkan scroll atas-bawah */
        }
    </style>
</head>
<body class="bg-gray-50 flex flex-col min-h-screen">
    <div class="flex-grow flex flex-col items-center justify-center p-4">
        <!-- Logo Brand -->
        <div class="mb-8 flex flex-col items-center justify-center text-center w-full px-4">
            <img src="https://qr.yuhu.co.id/client/logo.png" alt="Yuhu Logo" class="h-16 md:h-20 w-auto mb-6 drop-shadow-sm transition-transform duration-300 hover:scale-105">
            
            <!-- Card Sambutan -->
            <div class="w-full max-w-md mx-auto bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
                <p class="text-slate-800 font-bold text-[15px] mb-3">Terima kasih telah mengabadikan momen bersama kami! ✨</p>
                <div class="w-12 h-1 bg-slate-200 mx-auto rounded-full mb-4"></div>
                <p class="text-slate-500 text-sm leading-relaxed">
                    Silahkan cari nama acara yang Anda hadiri pada daftar di bawah ini, lalu klik tombol tersebut untuk melihat dan mengunduh hasil foto Anda.
                </p>
            </div>
        </div>

        <!-- Card Live Events -->
        <div class="w-full max-w-md bg-white rounded-2xl shadow-lg border border-gray-100 overflow-hidden p-6 relative">
            <!-- Aksen Garis Atas -->
            <div class="absolute top-0 left-0 w-full h-1.5 bg-gradient-to-r from-blue-600 via-indigo-500 to-purple-500"></div>
            
            <div class="flex items-center justify-center gap-2.5 mb-6">
                <!-- Indikator Merah Berkedip -->
                <span class="relative flex h-3.5 w-3.5">
                  <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                  <span class="relative inline-flex rounded-full h-3.5 w-3.5 bg-red-500"></span>
                </span>
                <h2 class="text-xl font-extrabold text-gray-900 tracking-wider uppercase">Live Events</h2>
            </div>
            
            <div id="event-list" class="space-y-4">
                <?php if (count($active_clients) > 0): ?>
                    <?php foreach($active_clients as $c): ?>
                        <a href="<?= htmlspecialchars($c['folder_foto_path']) ?>" target="_blank" class="block group relative p-4 bg-gray-50 border border-gray-100 rounded-xl hover:bg-blue-50 hover:border-blue-200 transition-all duration-300">
                            <div class="flex flex-col flex-grow">
                                <div class="flex justify-between items-start mb-1">
                                    <h3 class="font-bold text-gray-900 group-hover:text-blue-700 text-lg"><?= htmlspecialchars($c['nama_client']) ?></h3>
                                    <div class="text-blue-500 mt-1">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                                    </div>
                                </div>
                                
                                <!-- Meta Info: Lokasi & Tanggal -->
                                <div class="flex items-center text-xs text-gray-500 mt-1.5 gap-3">
                                    <div class="flex items-center">
                                        <svg class="w-3.5 h-3.5 mr-1 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.242-4.243a8 8 0 1111.314 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                                        <?= htmlspecialchars($c['lokasi'] ?? 'Lokasi Acara') ?>
                                    </div>
                                    <div class="flex items-center">
                                        <svg class="w-3.5 h-3.5 mr-1 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                                        <?php 
                                            $dateObj = date_create($c['tanggal_event']);
                                            echo date_format($dateObj, 'd M Y');
                                        ?>
                                    </div>
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

        <!-- Action Buttons -->
        <div class="w-full max-w-md mt-8 space-y-3">
            <a href="https://wa.me/6285603202222" target="_blank" class="flex items-center justify-center w-full bg-slate-800 text-white font-semibold py-3 rounded-xl shadow-md hover:bg-slate-900 transition-all">
                Pricelist & Booking
            </a>
            <div class="flex gap-3">
                <a href="https://wa.me/6285603202222" target="_blank" class="flex-1 flex items-center justify-center bg-slate-800 text-white font-semibold py-3 rounded-xl shadow-md hover:bg-slate-900 transition-all">
                    WhatsApp
                </a>
                <a href="https://www.instagram.com/yuhuphotobooth" target="_blank" class="flex-1 flex items-center justify-center bg-slate-800 text-white font-semibold py-3 rounded-xl shadow-md hover:bg-slate-900 transition-all">
                    Instagram
                </a>
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