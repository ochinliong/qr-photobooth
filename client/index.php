<?php
require_once '../config.php';

$slug = $_GET['c'] ?? '';
if (empty($slug)) {
    header("Location: ../");
    exit;
}

// Cari berdasarkan slug atau nama tanpa spasi
$stmt = $pdo->prepare("SELECT * FROM clients WHERE slug = ?");
$stmt->execute([$slug]);
$client = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$client) {
    $clean_q = strtolower(preg_replace('/\s+/', '', $slug));
    $stmt_all = $pdo->query("SELECT * FROM clients ORDER BY id DESC");
    foreach ($stmt_all->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (strtolower(preg_replace('/\s+/', '', $row['nama_client'])) === $clean_q) {
            $client = $row;
            break;
        }
    }
}

if (!$client) {
    die("Data acara tidak ditemukan.");
}

// Cek folder galeri: utama di ../uploads/gallery/{slug}, fallback ke folder_foto_path jika path relatif
$gallery_rel = 'uploads/gallery/' . $client['slug'];
$folder_path = '../' . $gallery_rel;

if (!is_dir($folder_path)) {
    $custom_rel = ltrim($client['folder_foto_path'] ?? '', '/');
    if (!empty($custom_rel) && !preg_match('#^https?://#i', $custom_rel) && is_dir('../' . $custom_rel)) {
        $gallery_rel = $custom_rel;
        $folder_path = '../' . $custom_rel;
    }
}

$media_items = [];
if (is_dir($folder_path)) {
    $files = scandir($folder_path);
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'mov', 'webm'])) {
            $full_file = $folder_path . '/' . $file;
            $type = 'photo';
            if (in_array($ext, ['mp4', 'mov', 'webm'])) {
                $type = 'video';
            } elseif ($ext === 'gif' || strpos(strtolower($file), 'gifs_') === 0) {
                $type = 'gif';
            }
            $media_items[] = [
                'name' => $file,
                'url'  => '../' . $gallery_rel . '/' . rawurlencode($file),
                'ext'  => $ext,
                'type' => $type,
                'mtime' => @filemtime($full_file) ?: 0,
                'size' => @filesize($full_file) ?: 0
            ];
        }
    }
}

// Urutkan dari yang terbaru diupload (LumaBooth live stream)
usort($media_items, function($a, $b) {
    return $b['mtime'] <=> $a['mtime'];
});

// Mode AJAX JSON untuk live polling tanpa reload
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'count' => count($media_items),
        'items' => $media_items
    ]);
    exit;
}

// Helper format tanggal Indo
function formatTglIndo($dateStr) {
    $date = date_create($dateStr);
    if (!$date) return $dateStr;
    $bulan = ['01'=>'Januari','02'=>'Februari','03'=>'Maret','04'=>'April','05'=>'Mei','06'=>'Juni','07'=>'Juli','08'=>'Agustus','09'=>'September','10'=>'Oktober','11'=>'November','12'=>'Desember'];
    return date_format($date, 'd') . ' ' . ($bulan[date_format($date, 'm')] ?? '') . ' ' . date_format($date, 'Y');
}

$year_c = date('Y', strtotime($client['tanggal_event']));
$clean_name_c = preg_replace('/\s+/', '', $client['nama_client']);
$direct_url_c = rtrim($base_url, '/') . '/' . $year_c . '/' . $clean_name_c;
$highlight_file = $_GET['file'] ?? '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= htmlspecialchars($client['nama_client']) ?> - YUHU Photobooth Gallery</title>
    <link rel="icon" href="https://qr.yuhu.co.id/2025/icon.png" type="image/png">
    <link rel="apple-touch-icon" href="https://qr.yuhu.co.id/2025/icon.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
        body { font-family: 'Inter', sans-serif; }
        .masonry {
            column-count: 2;
            column-gap: 0.85rem;
        }
        @media (min-width: 640px) { .masonry { column-count: 3; column-gap: 1rem; } }
        @media (min-width: 1024px) { .masonry { column-count: 4; column-gap: 1.25rem; } }
        .masonry-item {
            break-inside: avoid;
            margin-bottom: 0.85rem;
        }
        @media (min-width: 640px) { .masonry-item { margin-bottom: 1rem; } }
        @media (min-width: 1024px) { .masonry-item { margin-bottom: 1.25rem; } }
    </style>
</head>
<body class="bg-slate-50 min-h-screen flex flex-col text-slate-800">

    <!-- Sticky Navbar -->
    <header class="bg-white/90 backdrop-blur-md border-b border-slate-200/80 sticky top-0 z-40">
        <div class="max-w-6xl mx-auto px-4 py-3.5 flex items-center justify-between gap-3">
            <div class="flex items-center gap-3 min-w-0">
                <a href="../" class="p-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 transition-colors flex-shrink-0" title="Kembali ke Portal">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
                </a>
                <img src="https://qr.yuhu.co.id/2025/icon.png" alt="Yuhu" class="w-9 h-9 rounded-lg shadow-sm flex-shrink-0">
                <div class="truncate">
                    <h1 class="font-extrabold text-slate-900 text-base md:text-lg leading-tight truncate"><?= htmlspecialchars($client['nama_client']) ?></h1>
                    <div class="flex items-center gap-2 text-xs text-slate-500">
                        <span><?= formatTglIndo($client['tanggal_event']) ?></span>
                        <span>•</span>
                        <span class="inline-flex items-center gap-1 text-emerald-600 font-semibold">
                            <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                            Live Sync
                        </span>
                    </div>
                </div>
            </div>

            <div class="flex items-center gap-2 flex-shrink-0">
                <button onclick="copyShareLink('<?= htmlspecialchars($direct_url_c, ENT_QUOTES) ?>', this)" class="inline-flex items-center gap-1.5 bg-slate-900 hover:bg-slate-800 text-white text-xs font-bold px-3.5 py-2.5 rounded-xl shadow-sm transition-all">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"></path></svg>
                    <span>Bagikan Galeri</span>
                </button>
            </div>
        </div>
    </header>

    <!-- Event Banner & Filter Bar -->
    <section class="max-w-6xl mx-auto w-full px-4 pt-5 pb-2">
        <div class="bg-white rounded-2xl p-4 md:p-5 border border-slate-200/80 shadow-sm flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
                <div class="inline-flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wider text-blue-600 bg-blue-50 px-2.5 py-1 rounded-md mb-1.5">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.242-4.243a8 8 0 1111.314 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                    <?= htmlspecialchars(str_replace(["\r\n", "\n"], ' • ', $client['lokasi'] ?? 'YUHU Photobooth Event')) ?>
                </div>
                <p class="text-xs text-slate-500">Foto dan video dari mesin LumaBooth akan otomatis muncul di halaman ini secara *real-time*.</p>
            </div>

            <!-- Filter Tabs -->
            <div class="flex items-center gap-1.5 bg-slate-100 p-1 rounded-xl self-start md:self-auto">
                <button onclick="filterMedia('all')" id="tab-all" class="filter-tab bg-white text-slate-900 shadow-sm text-xs font-bold px-3 py-1.5 rounded-lg transition-all">
                    Semua (<span id="count-all"><?= count($media_items) ?></span>)
                </button>
                <button onclick="filterMedia('photo')" id="tab-photo" class="filter-tab text-slate-600 hover:text-slate-900 text-xs font-semibold px-3 py-1.5 rounded-lg transition-all">
                    Foto
                </button>
                <button onclick="filterMedia('gif')" id="tab-gif" class="filter-tab text-slate-600 hover:text-slate-900 text-xs font-semibold px-3 py-1.5 rounded-lg transition-all">
                    GIF
                </button>
                <button onclick="filterMedia('video')" id="tab-video" class="filter-tab text-slate-600 hover:text-slate-900 text-xs font-semibold px-3 py-1.5 rounded-lg transition-all">
                    Video / Boomerang
                </button>
            </div>
        </div>
    </section>

    <!-- Gallery Content -->
    <main class="max-w-6xl mx-auto w-full px-4 py-4 flex-grow">
        <div id="empty-state" class="<?= count($media_items) > 0 ? 'hidden' : '' ?> bg-white rounded-3xl border border-slate-200/80 p-12 text-center my-6 shadow-sm">
            <div class="w-16 h-16 rounded-2xl bg-blue-50 text-blue-600 flex items-center justify-center mx-auto mb-4">
                <svg class="w-8 h-8 animate-bounce" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
            </div>
            <h3 class="text-lg font-extrabold text-slate-900">Menunggu Foto dari Photobooth...</h3>
            <p class="mt-1 text-sm text-slate-500 max-w-md mx-auto">Halaman ini terhubung langsung dengan aplikasi LumaBooth. Begitu sesi foto selesai, hasilnya akan otomatis tampil di sini tanpa perlu refresh.</p>
        </div>

        <div id="gallery-grid" class="masonry <?= count($media_items) === 0 ? 'hidden' : '' ?>">
            <?php foreach ($media_items as $idx => $item): ?>
                <div class="masonry-item media-card group relative rounded-2xl overflow-hidden bg-white border border-slate-200/80 shadow-sm hover:shadow-xl transition-all duration-300 cursor-pointer"
                     data-type="<?= $item['type'] ?>"
                     data-name="<?= htmlspecialchars($item['name']) ?>"
                     onclick="openLightbox(<?= $idx ?>)">

                    <?php if ($item['type'] === 'video'): ?>
                        <video src="<?= $item['url'] ?>" class="w-full h-auto object-cover" muted loop autoplay playsinline preload="metadata"></video>
                        <span class="absolute top-2.5 left-2.5 bg-black/60 backdrop-blur-sm text-white text-[10px] font-bold uppercase px-2 py-0.5 rounded-md flex items-center gap-1">
                            ▶ VIDEO
                        </span>
                    <?php else: ?>
                        <img src="<?= $item['url'] ?>" alt="<?= htmlspecialchars($item['name']) ?>" class="w-full h-auto object-cover transition-transform duration-500 group-hover:scale-105" loading="lazy">
                        <?php if ($item['type'] === 'gif'): ?>
                            <span class="absolute top-2.5 left-2.5 bg-purple-600/90 backdrop-blur-sm text-white text-[10px] font-bold uppercase px-2 py-0.5 rounded-md">
                                GIF
                            </span>
                        <?php endif; ?>
                    <?php endif; ?>

                    <!-- Quick Download Overlay -->
                    <div class="absolute inset-0 bg-gradient-to-t from-black/70 via-black/10 to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300 flex items-end justify-between p-3">
                        <span class="text-white text-xs font-medium truncate pr-2"><?= htmlspecialchars($item['name']) ?></span>
                        <a href="<?= $item['url'] ?>" download="<?= htmlspecialchars($item['name']) ?>" onclick="event.stopPropagation();" class="bg-white text-slate-900 hover:bg-blue-600 hover:text-white rounded-xl p-2 shadow-lg transition-colors flex-shrink-0" title="Download">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </main>

    <!-- Lightbox Modal -->
    <div id="lightbox" class="fixed inset-0 z-50 bg-black/95 backdrop-blur-md hidden flex-col justify-between p-4 md:p-6 select-none">
        <!-- Top Bar -->
        <div class="flex items-center justify-between text-white max-w-5xl mx-auto w-full">
            <div class="truncate pr-4">
                <p id="lb-title" class="font-bold text-sm md:text-base truncate"></p>
                <p class="text-xs text-slate-400"><?= htmlspecialchars($client['nama_client']) ?></p>
            </div>
            <button onclick="closeLightbox()" class="p-2.5 rounded-full bg-white/10 hover:bg-white/20 text-white transition-colors">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>

        <!-- Center Media -->
        <div class="flex-grow flex items-center justify-center relative my-4 overflow-hidden">
            <button onclick="navLightbox(-1)" class="absolute left-1 md:left-4 z-10 p-3 rounded-full bg-white/10 hover:bg-white/25 text-white transition-colors">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
            </button>

            <div id="lb-container" class="max-h-[75vh] max-w-full flex items-center justify-center"></div>

            <button onclick="navLightbox(1)" class="absolute right-1 md:right-4 z-10 p-3 rounded-full bg-white/10 hover:bg-white/25 text-white transition-colors">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
            </button>
        </div>

        <!-- Bottom Action Bar -->
        <div class="max-w-md mx-auto w-full flex items-center gap-3">
            <a id="lb-download" href="#" download class="flex-1 bg-blue-600 hover:bg-blue-500 text-white font-bold py-3.5 px-6 rounded-2xl flex items-center justify-center gap-2 shadow-lg transition-all">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
                <span>Download ke HP</span>
            </a>
            <button onclick="shareCurrentMedia()" class="bg-white/15 hover:bg-white/25 text-white font-bold py-3.5 px-5 rounded-2xl flex items-center justify-center gap-2 transition-all">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"></path></svg>
                <span>Share</span>
            </button>
        </div>
    </div>

    <footer class="text-center py-6 text-xs text-slate-400">
        &copy; <?= date('Y') ?> YUHU Photobooth • Powered by LumaBooth Live Sync
    </footer>

    <script>
        let mediaItems = <?= json_encode($media_items) ?>;
        let currentIndex = 0;
        let activeFilter = 'all';

        function filterMedia(type) {
            activeFilter = type;
            document.querySelectorAll('.filter-tab').forEach(btn => {
                btn.className = 'filter-tab text-slate-600 hover:text-slate-900 text-xs font-semibold px-3 py-1.5 rounded-lg transition-all';
            });
            const activeBtn = document.getElementById('tab-' + type);
            if (activeBtn) {
                activeBtn.className = 'filter-tab bg-white text-slate-900 shadow-sm text-xs font-bold px-3 py-1.5 rounded-lg transition-all';
            }

            document.querySelectorAll('.media-card').forEach(card => {
                if (type === 'all' || card.dataset.type === type) {
                    card.classList.remove('hidden');
                } else {
                    card.classList.add('hidden');
                }
            });
        }

        function openLightbox(index) {
            if (!mediaItems[index]) return;
            currentIndex = index;
            const item = mediaItems[index];
            const lb = document.getElementById('lightbox');
            const container = document.getElementById('lb-container');
            document.getElementById('lb-title').textContent = item.name;
            const dlBtn = document.getElementById('lb-download');
            dlBtn.href = item.url;
            dlBtn.setAttribute('download', item.name);

            if (item.type === 'video') {
                container.innerHTML = `<video src="${item.url}" controls autoplay loop playsinline class="max-h-[72vh] max-w-full rounded-2xl shadow-2xl"></video>`;
            } else {
                container.innerHTML = `<img src="${item.url}" alt="${item.name}" class="max-h-[72vh] max-w-full object-contain rounded-2xl shadow-2xl">`;
            }

            lb.classList.remove('hidden');
            lb.classList.add('flex');
        }

        function closeLightbox() {
            const lb = document.getElementById('lightbox');
            document.getElementById('lb-container').innerHTML = '';
            lb.classList.add('hidden');
            lb.classList.remove('flex');
        }

        function navLightbox(dir) {
            if (mediaItems.length === 0) return;
            currentIndex = (currentIndex + dir + mediaItems.length) % mediaItems.length;
            openLightbox(currentIndex);
        }

        async function shareCurrentMedia() {
            const item = mediaItems[currentIndex];
            if (!item) return;
            const shareUrl = window.location.origin + window.location.pathname + '?c=<?= urlencode($client['slug']) ?>&file=' + encodeURIComponent(item.name);
            if (navigator.share) {
                try {
                    await navigator.share({
                        title: '<?= addslashes($client['nama_client']) ?> - YUHU Photobooth',
                        url: shareUrl
                    });
                } catch (e) {}
            } else {
                navigator.clipboard.writeText(shareUrl);
                alert('Link foto berhasil disalin!');
            }
        }

        function copyShareLink(url, btn) {
            navigator.clipboard.writeText(url).then(() => {
                const span = btn.querySelector('span');
                const orig = span.innerText;
                span.innerText = 'Link Tersalin!';
                setTimeout(() => span.innerText = orig, 2000);
            });
        }

        // Buka otomatis jika ada parameter ?file=... (dari scan QR individual)
        const highlightFile = <?= json_encode($highlight_file) ?>;
        if (highlightFile) {
            const foundIdx = mediaItems.findIndex(m => m.name === highlightFile);
            if (foundIdx !== -1) {
                openLightbox(foundIdx);
            }
        }

        // Live Auto-Sync Polling setiap 8 detik untuk mendeteksi foto baru dari LumaBooth Windows
        setInterval(async () => {
            try {
                const res = await fetch(`?c=<?= urlencode($client['slug']) ?>&ajax=1`);
                const data = await res.json();
                if (data.count !== mediaItems.length) {
                    mediaItems = data.items;
                    document.getElementById('count-all').textContent = data.count;
                    renderGalleryGrid();
                }
            } catch (e) {}
        }, 8000);

        function renderGalleryGrid() {
            const grid = document.getElementById('gallery-grid');
            const empty = document.getElementById('empty-state');
            if (mediaItems.length === 0) {
                grid.classList.add('hidden');
                empty.classList.remove('hidden');
                return;
            }
            empty.classList.add('hidden');
            grid.classList.remove('hidden');

            grid.innerHTML = mediaItems.map((item, idx) => {
                const isHidden = (activeFilter !== 'all' && item.type !== activeFilter) ? 'hidden' : '';
                let mediaHtml = '';
                if (item.type === 'video') {
                    mediaHtml = `
                        <video src="${item.url}" class="w-full h-auto object-cover" muted loop autoplay playsinline preload="metadata"></video>
                        <span class="absolute top-2.5 left-2.5 bg-black/60 backdrop-blur-sm text-white text-[10px] font-bold uppercase px-2 py-0.5 rounded-md">▶ VIDEO</span>
                    `;
                } else {
                    const badge = item.type === 'gif' ? `<span class="absolute top-2.5 left-2.5 bg-purple-600/90 backdrop-blur-sm text-white text-[10px] font-bold uppercase px-2 py-0.5 rounded-md">GIF</span>` : '';
                    mediaHtml = `<img src="${item.url}" alt="${item.name}" class="w-full h-auto object-cover transition-transform duration-500 group-hover:scale-105" loading="lazy">${badge}`;
                }
                return `
                    <div class="masonry-item media-card ${isHidden} group relative rounded-2xl overflow-hidden bg-white border border-slate-200/80 shadow-sm hover:shadow-xl transition-all duration-300 cursor-pointer"
                         data-type="${item.type}"
                         data-name="${item.name}"
                         onclick="openLightbox(${idx})">
                        ${mediaHtml}
                        <div class="absolute inset-0 bg-gradient-to-t from-black/70 via-black/10 to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300 flex items-end justify-between p-3">
                            <span class="text-white text-xs font-medium truncate pr-2">${item.name}</span>
                            <a href="${item.url}" download="${item.name}" onclick="event.stopPropagation();" class="bg-white text-slate-900 hover:bg-blue-600 hover:text-white rounded-xl p-2 shadow-lg transition-colors flex-shrink-0">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
                            </a>
                        </div>
                    </div>
                `;
            }).join('');
        }
    </script>
</body>
</html>
