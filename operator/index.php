<?php
require_once '../config.php';

// Ambil semua data klien, urutkan dari tanggal terbaru, lalu jam mulai
$stmt = $pdo->query("SELECT * FROM clients ORDER BY tanggal_event DESC, jam_mulai DESC");
$all_clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Kelompokkan berdasarkan tanggal
$grouped_clients = [];
foreach ($all_clients as $c) {
    $date = $c['tanggal_event'];
    if (!isset($grouped_clients[$date])) {
        $grouped_clients[$date] = [];
    }
    $grouped_clients[$date][] = $c;
}

// Helper format tanggal bahasa Indonesia
function formatTanggalIndo($dateStr) {
    $date = date_create($dateStr);
    $hari = ['Sunday' => 'Minggu', 'Monday' => 'Senin', 'Tuesday' => 'Selasa', 'Wednesday' => 'Rabu', 'Thursday' => 'Kamis', 'Friday' => 'Jumat', 'Saturday' => 'Sabtu'];
    $bulan = ['01' => 'Jan', '02' => 'Feb', '03' => 'Mar', '04' => 'Apr', '05' => 'Mei', '06' => 'Jun', '07' => 'Jul', '08' => 'Agu', '09' => 'Sep', '10' => 'Okt', '11' => 'Nov', '12' => 'Des'];
    
    $hari_nama = $hari[date_format($date, 'l')];
    $tgl = date_format($date, 'd');
    $bln = $bulan[date_format($date, 'm')];
    $thn = date_format($date, 'Y');
    
    return "$hari_nama, $tgl $bln $thn";
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Operator Panel - YUHU Photobooth</title>
    <link rel="icon" href="https://qr.yuhu.co.id/2025/icon.png" type="image/png">
    <link rel="apple-touch-icon" href="https://qr.yuhu.co.id/2025/icon.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap');
        body { 
            font-family: 'Inter', sans-serif; 
            touch-action: pan-y;
        }
    </style>
</head>
<body class="bg-slate-50 flex flex-col min-h-screen">
    <!-- Navbar / Header -->
    <div class="bg-white shadow-[0_2px_10px_-3px_rgba(6,81,237,0.1)] sticky top-0 z-50">
        <div class="max-w-3xl mx-auto px-5 py-3.5 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <img src="https://qr.yuhu.co.id/2025/icon.png" class="w-9 h-9 rounded-md shadow-sm" alt="Logo">
                <div>
                    <h1 class="font-extrabold text-slate-800 leading-tight text-lg tracking-tight">Operator Panel</h1>
                    <p class="text-[10px] text-blue-600 font-bold uppercase tracking-widest">Internal Use Only</p>
                </div>
            </div>
            <a href="../" class="p-2 text-slate-400 hover:text-slate-700 bg-slate-50 rounded-full transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path></svg>
            </a>
        </div>
    </div>

    <!-- Main Content -->
    <div class="flex-grow w-full max-w-3xl mx-auto p-4 space-y-6 mt-2 mb-10">
        
        <?php if (empty($grouped_clients)): ?>
            <div class="text-center py-12 text-slate-400 bg-white rounded-2xl border border-slate-100 shadow-sm">
                <svg class="mx-auto h-12 w-12 mb-3 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
                <p>Belum ada data event apapun di database.</p>
            </div>
        <?php else: ?>
            
            <?php foreach($grouped_clients as $date => $clients): ?>
                <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
                    <!-- Header Tanggal -->
                    <div class="bg-slate-800 text-white px-5 py-3 border-b border-slate-700 flex items-center justify-between">
                        <h2 class="font-bold tracking-wide text-sm flex items-center gap-2">
                            <svg class="w-4 h-4 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                            <?= formatTanggalIndo($date) ?>
                        </h2>
                        <span class="text-[10px] bg-slate-700 text-slate-300 px-2 py-1 rounded-md font-bold uppercase tracking-wider"><?= count($clients) ?> Event</span>
                    </div>
                    
                    <!-- List Event di Tanggal Tersebut -->
                    <div class="divide-y divide-slate-100">
                        <?php foreach($clients as $c): ?>
                            <div class="p-5 hover:bg-slate-50 transition-colors">
                                <div class="flex justify-between items-start mb-2">
                                    <h3 class="font-bold text-slate-900 text-lg leading-tight pr-3"><?= htmlspecialchars($c['nama_client']) ?></h3>
                                    <span class="text-xs font-bold text-slate-500 bg-slate-100 px-2 py-1 rounded border border-slate-200 whitespace-nowrap">
                                        <?= substr($c['jam_mulai'], 0, 5) ?> - <?= substr($c['jam_selesai'], 0, 5) ?>
                                    </span>
                                </div>
                                
                                <div class="flex items-start text-xs text-slate-500 mb-4 mt-1">
                                    <svg class="w-3.5 h-3.5 mr-1.5 flex-shrink-0 text-red-500 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.242-4.243a8 8 0 1111.314 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                                    <div class="leading-relaxed">
                                        <?= nl2br(htmlspecialchars($c['lokasi'] ?? '-')) ?>
                                    </div>
                                </div>
                                
                                <!-- Action Buttons -->
                                <div class="flex gap-2">
                                    <a href="<?= htmlspecialchars($c['folder_foto_path']) ?>" target="_blank" class="flex-1 flex items-center justify-center bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold py-2.5 rounded-lg transition-colors shadow-sm">
                                        <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path></svg>
                                        Buka Galeri
                                    </a>
                                    <button onclick="copyToClipboard('<?= htmlspecialchars($c['folder_foto_path']) ?>', this);" class="flex-1 flex items-center justify-center bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold py-2.5 rounded-lg transition-colors border border-slate-200 shadow-sm">
                                        <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"></path></svg>
                                        <span>Copy Link</span>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            
        <?php endif; ?>
    </div>
    
    <script>
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
    </script>
</body>
</html>
