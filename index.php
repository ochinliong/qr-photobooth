<?php
require_once 'config.php';
require_once __DIR__ . '/lumabooth_sync.php';

// Ambil semua klien (termasuk yang baru otomatis disinkronkan dari LumaBooth)
$stmt = $pdo->query("SELECT * FROM clients ORDER BY id DESC");
$all_clients = $stmt->fetchAll();

$active_clients = [];
$now = new DateTime('now', new DateTimeZone('Asia/Jakarta'));

foreach ($all_clients as $client) {
    // Project baru otomatis dari LumaBooth (atau yang belum diatur tanggal & jam acaranya)
    // TIDAK AKAN PERNAH muncul di Live Events tamu files.yuhu.co.id
    if (empty($client['tanggal_event']) || empty($client['jam_mulai']) || empty($client['jam_selesai']) || 
        $client['tanggal_event'] === '0000-00-00' || $client['tanggal_event'] === '1970-01-01') {
        continue;
    }

    // Abaikan juga jika jam masih default LumaBooth 00:00 - 23:59 dan lokasi belum diisi
    if (($client['jam_mulai'] === '00:00:00' && $client['jam_selesai'] === '23:59:00') && (trim($client['lokasi'] ?? '') === '' || trim($client['lokasi'] ?? '') === '-')) {
        continue;
    }

    try {
        $start = new DateTime($client['tanggal_event'] . ' ' . $client['jam_mulai'], new DateTimeZone('Asia/Jakarta'));
        $end   = new DateTime($client['tanggal_event'] . ' ' . $client['jam_selesai'], new DateTimeZone('Asia/Jakarta'));
        
        $visible_start = clone $start;
        $visible_start->modify('-1 hour');
        
        $visible_end = clone $end;
        $visible_end->modify('+1 hour');
        
        if ($now >= $visible_start && $now <= $visible_end) {
            $active_clients[] = $client;
        }
    } catch (Exception $e) {
        continue;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Live Events - YUHU Photobooth</title>
    <link rel="icon" href="https://qr.yuhu.co.id/2025/icon.png" type="image/png">
    <link rel="apple-touch-icon" href="https://qr.yuhu.co.id/2025/icon.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
        <div class="mb-6 md:mb-8 flex flex-col items-center justify-center text-center w-full">
            <img src="https://qr.yuhu.co.id/client/logo.png" alt="Yuhu Logo" class="h-16 md:h-20 w-auto drop-shadow-sm transition-transform duration-300 hover:scale-105">
        </div>

        <div id="main-portal-view" class="w-full flex flex-col items-center">
            <?php if (count($active_clients) > 0): ?>
                <!-- ================= KONDISI 1: ADA EVENT BERLANGSUNG ================= -->
                
                <!-- Card Sambutan -->
                <div class="w-full max-w-md mx-auto mb-6 bg-white rounded-2xl p-6 shadow-sm border border-gray-100 text-center">
                    <p class="text-slate-800 font-bold text-[15px] mb-2">Terima kasih telah mengabadikan momen bersama kami! ✨</p>
                    <div class="w-12 h-1 bg-slate-200 mx-auto rounded-full mb-3"></div>
                    <p class="text-slate-500 text-sm leading-relaxed">
                        Silahkan cari nama acara yang Anda hadiri pada daftar di bawah ini, lalu klik tombol tersebut untuk melihat dan mengunduh hasil foto Anda.
                    </p>
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
                        <?php foreach($active_clients as $c): 
                            $year_c = date('Y', strtotime($c['tanggal_event']));
                            $clean_name_c = preg_replace('/\s+/', '', $c['nama_client']);
                            $direct_url_c = rtrim($base_url, '/') . '/' . $year_c . '/' . $clean_name_c;
                            $has_lokasi = (trim($c['lokasi'] ?? '') !== '' && trim($c['lokasi'] ?? '') !== '-');
                        ?>
                            <div class="block relative p-5 bg-gray-50 border border-gray-100 rounded-xl hover:bg-blue-50/50 transition-all duration-300 shadow-sm">
                                <div class="flex flex-col flex-grow">
                                    <div class="flex justify-between items-start mb-1">
                                        <h3 class="font-bold text-gray-900 text-lg leading-tight"><?= htmlspecialchars($c['nama_client']) ?></h3>
                                        <div class="text-blue-500 mt-0.5">
                                            <!-- Ikon Kamera -->
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                                        </div>
                                    </div>
                                    
                                    <!-- Meta Info: Lokasi & Tanggal -->
                                    <div class="flex flex-col text-xs text-gray-500 mt-2 space-y-1.5 mb-5">
                                        <?php if ($has_lokasi): ?>
                                        <div class="flex items-start">
                                            <svg class="w-3.5 h-3.5 mr-1.5 flex-shrink-0 text-red-500 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.242-4.243a8 8 0 1111.314 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                                            <div class="leading-relaxed">
                                                <?= nl2br(htmlspecialchars($c['lokasi'])) ?>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                        <div class="flex items-start">
                                            <svg class="w-3.5 h-3.5 mr-1.5 flex-shrink-0 text-blue-500 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                                            <div class="leading-relaxed">
                                                <?php 
                                                    $dateObj = date_create($c['tanggal_event']);
                                                    echo date_format($dateObj, 'd F Y');
                                                ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Tombol Aksi Klien -->
                                    <div class="flex gap-2">
                                        <!-- Tombol Buka Galeri -->
                                        <a href="<?= htmlspecialchars($direct_url_c) ?>" target="_blank" class="flex-1 flex items-center justify-center bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold py-2.5 rounded-lg transition-colors shadow-sm">
                                            <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path></svg>
                                            Buka Galeri
                                        </a>
                                        <!-- Tombol Copy Link -->
                                        <button onclick="copyToClipboard('<?= htmlspecialchars($direct_url_c, ENT_QUOTES) ?>', this);" class="flex-1 flex items-center justify-center bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold py-2.5 rounded-lg transition-colors border border-slate-200 shadow-sm">
                                            <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"></path></svg>
                                            <span>Copy Link</span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Info Notice / Bookmark Tip -->
                <div class="w-full max-w-md mt-4 bg-amber-50/90 border border-amber-200/80 rounded-xl p-3.5 shadow-xs text-left">
                    <div class="flex items-start gap-2.5">
                        <div class="text-amber-500 mt-0.5 flex-shrink-0">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        </div>
                        <p class="text-xs text-amber-800 leading-relaxed font-medium">
                            <strong>Penting:</strong> Jangan menyimpan <em>(bookmark)</em> halaman portal ini karena daftar acara akan berganti. Gunakan tombol <strong>Copy Link</strong> pada acara Anda di atas untuk menyimpan tautan galeri permanen.
                        </p>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="w-full max-w-md mt-6 space-y-3">
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

            <?php else: ?>
                <!-- ================= KONDISI 2: ACARA SELESAI / TIDAK ADA LIVE EVENT ================= -->
                
                <div class="w-full max-w-md bg-white rounded-3xl shadow-xl border border-gray-100 overflow-hidden p-6 md:p-8 text-center relative">
                    <!-- Aksen Garis Gradien Atas -->
                    <div class="absolute top-0 left-0 w-full h-1.5 bg-gradient-to-r from-amber-400 via-orange-500 to-rose-500"></div>

                    <!-- Ikon Selesai -->
                    <div class="w-16 h-16 bg-amber-50 border border-amber-100 rounded-2xl flex items-center justify-center mx-auto mb-4 text-amber-500 shadow-xs">
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    </div>

                    <span class="inline-block px-3 py-1 bg-amber-100/90 text-amber-800 text-xs font-bold rounded-full mb-3 uppercase tracking-wider">
                        Sesi Acara Telah Selesai
                    </span>

                    <h2 class="text-xl font-extrabold text-gray-900 mb-2 leading-snug">
                        Terima kasih telah berfoto di YUHU Photobooth! ✨
                    </h2>

                    <div class="w-12 h-1 bg-gray-200 mx-auto rounded-full mb-4"></div>

                    <p class="text-slate-600 text-xs md:text-sm leading-relaxed mb-5">
                        Mohon maaf, barcode atau tautan yang Anda buka saat ini sudah tidak aktif karena QR barcode ini hanya berlaku pada saat acara berlangsung.
                    </p>

                    <!-- Box Bantuan Softfile -->
                    <div class="bg-blue-50/80 border border-blue-100 rounded-2xl p-4 text-left mb-6 shadow-2xs">
                        <div class="flex items-start gap-2.5">
                            <span class="text-xl flex-shrink-0">📸</span>
                            <div class="text-xs md:text-sm text-slate-700 leading-relaxed">
                                <p class="font-bold text-blue-900 mb-1">Ingin mencari softfile fotomu?</p>
                                <p>Silakan <strong>fotokan cetakan fisik foto Anda</strong> dan hubungi tim kami via WhatsApp. Kami akan dengan senang hati membantu Anda mencarikan softfilenya!</p>
                            </div>
                        </div>
                    </div>

                    <!-- Tombol WhatsApp Khusus Softfile -->
                    <a href="https://wa.me/6285603202222?text=<?= rawurlencode('Halo YUHU Photobooth, saya ingin mencari softfile foto saya karena sesi acara sudah selesai. Berikut foto cetakan fisik saya: ') ?>" target="_blank" class="flex items-center justify-center w-full bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-3.5 px-4 rounded-xl shadow-md hover:shadow-lg transition-all duration-300 gap-2.5 text-sm md:text-base">
                        <svg class="w-5 h-5 fill-current" viewBox="0 0 24 24"><path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946.003-6.556 5.338-11.891 11.893-11.891 3.181.001 6.167 1.24 8.413 3.488 2.245 2.248 3.481 5.236 3.48 8.414-.003 6.557-5.338 11.892-11.893 11.892-1.99-.001-3.951-.5-5.688-1.448l-6.305 1.654zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.521.151-.172.2-.296.3-.495.099-.198.05-.372-.025-.521-.075-.148-.669-1.611-.916-2.206-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.095 3.2 5.076 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.695.248-1.29.173-1.414z"/></svg>
                        <span>WhatsApp: 0856-0320-2222</span>
                    </a>
                </div>

                <!-- Secondary Action Links -->
                <div class="w-full max-w-md mt-6 space-y-3">
                    <a href="https://wa.me/6285603202222" target="_blank" class="flex items-center justify-center w-full bg-slate-800 text-white font-semibold py-3 rounded-xl shadow-md hover:bg-slate-900 transition-all">
                        Pricelist & Booking
                    </a>
                    <a href="https://www.instagram.com/yuhuphotobooth" target="_blank" class="flex items-center justify-center w-full bg-white hover:bg-slate-50 text-slate-700 font-semibold py-3 rounded-xl border border-slate-200 shadow-sm transition-all">
                        Instagram @yuhuphotobooth
                    </a>
                </div>

            <?php endif; ?>
        </div>

    </div>
    <footer class="text-center py-6 text-sm text-gray-400">
        &copy; <?= date('Y') ?> Yuhu Photobooth. All rights reserved.
    </footer>

    <script>
        <?php if (count($active_clients) > 0): ?>
        // Tampilkan Popup Peringatan (SweetAlert) saat pengunjung mengetuk Address Bar (kehilangan fokus)
        window.addEventListener('blur', function() {
            if (!sessionStorage.getItem('blur_warning_shown')) {
                Swal.fire({
                    title: 'Live Events - YUHU Photobooth',
                    html: `
                        <div class="text-slate-600 text-sm leading-relaxed mt-1 text-center">
                            <p class="mb-3">Link halaman portal ini <strong>(files.yuhu.co.id)</strong> bersifat sementara dan otomatis hilang setelah acara selesai.</p>
                            <p>Untuk membagikan atau menyimpan galeri Anda, mohon gunakan tombol <br><span class="inline-block bg-slate-100 text-slate-700 font-bold px-3 py-1.5 rounded-lg text-xs border border-slate-200 mt-2 shadow-sm">Copy Link</span><br> yang tersedia pada daftar acara Anda di bawah.</p>
                        </div>
                    `,
                    imageUrl: 'https://qr.yuhu.co.id/2025/icon.png',
                    imageWidth: 64,
                    imageAlt: 'Yuhu Icon',
                    confirmButtonText: 'Siap, Mengerti!',
                    buttonsStyling: false,
                    customClass: {
                        popup: 'rounded-[1.5rem] shadow-2xl border border-gray-100 p-4',
                        title: 'text-xl font-extrabold text-slate-800 mt-2',
                        image: 'mb-0 mx-auto drop-shadow-sm',
                        confirmButton: 'bg-slate-800 text-white rounded-xl font-bold px-8 py-3.5 mt-3 hover:bg-slate-700 transition-colors w-full shadow-md'
                    }
                });
                sessionStorage.setItem('blur_warning_shown', 'true');
            }
        });

        // Fitur Copy Link
        function copyToClipboard(text, btn) {
            navigator.clipboard.writeText(text).then(() => {
                const span = btn.querySelector('span');
                const originalText = span.innerText;
                span.innerText = 'Tersalin!';
                btn.classList.replace('bg-slate-100', 'bg-green-100');
                btn.classList.replace('text-slate-700', 'text-green-700');
                btn.classList.replace('border-slate-200', 'border-green-200');
                
                setTimeout(() => {
                    span.innerText = originalText;
                    btn.classList.replace('bg-green-100', 'bg-slate-100');
                    btn.classList.replace('text-green-700', 'text-slate-700');
                    btn.classList.replace('border-green-200', 'border-slate-200');
                }, 2000);
            }).catch(err => {
                alert('Gagal menyalin link: ' + err);
            });
        }
        <?php endif; ?>

        // Fitur Auto-Refresh "Tanpa Kedip" (AJAX)
        // Mengecek ke server setiap 30 detik apakah ada perubahan data
        setInterval(() => {
            fetch(window.location.href)
                .then(response => response.text())
                .then(html => {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, 'text/html');
                    const newContainer = doc.querySelector('#main-portal-view');
                    const currentContainer = document.querySelector('#main-portal-view');
                    
                    if (newContainer && currentContainer && newContainer.innerHTML.trim() !== currentContainer.innerHTML.trim()) {
                        // Terapkan transisi lembut
                        currentContainer.style.opacity = 0;
                        setTimeout(() => {
                            currentContainer.innerHTML = newContainer.innerHTML;
                            currentContainer.style.transition = 'opacity 0.5s ease-in-out';
                            currentContainer.style.opacity = 1;
                        }, 300);
                    }
                })
                .catch(err => console.error('Gagal mengecek update otomatis:', err));
        }, 30000); // 30000 ms = 30 detik
    </script>
</body>
</html>
