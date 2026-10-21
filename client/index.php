<?php
require_once '../config.php';

// Dukung parameter ?client= (standar LumaBooth Cloud) maupun ?c= (standar Portal)
$raw_param = $_GET['client'] ?? ($_GET['c'] ?? '');
$client_name = trim(str_replace(['..', '/', '\\', "\0"], '', $raw_param));

if (empty($client_name)) {
    header("Location: ../");
    exit;
}

// Cari kecocokan dengan Database Portal (clients) untuk mengambil judul/lokasi/jadwal resmi jika ada
$db_client = null;
$req_clean = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $client_name));
try {
    $stmt = $pdo->query("SELECT * FROM clients ORDER BY id DESC");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $c_name = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $row['nama_client']));
        $c_slug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $row['slug']));
        if ($req_clean !== '' && ($c_name === $req_clean || $c_slug === $req_clean)) {
            $db_client = $row;
            break;
        }
    }
} catch (Exception $e) {}

// Pencarian cerdas (Fuzzy Matching) pada direktori photos/
$photos_root = __DIR__ . '/photos';
if (!is_dir($photos_root)) {
    @mkdir($photos_root, 0777, true);
}

$resolved_folder = $client_name;
if (!is_dir($photos_root . '/' . $resolved_folder)) {
    // Coba cocokkan dengan nama_client dari DB jika tadi dipanggil pakai slug
    $search_candidates = [$req_clean];
    if ($db_client) {
        $search_candidates[] = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $db_client['nama_client']));
    }
    $dirs = @scandir($photos_root) ?: [];
    foreach ($dirs as $dir) {
        if ($dir !== '.' && $dir !== '..' && is_dir($photos_root . '/' . $dir)) {
            $dir_clean = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $dir));
            if ($dir_clean !== '' && in_array($dir_clean, $search_candidates, true)) {
                $resolved_folder = $dir;
                break;
            }
        }
    }
}

$target_dir = $photos_root . '/' . $resolved_folder;

// Membaca Judul Custom & Pengaturan Visibility (Jika ada di config.json atau Database)
$display_title = $db_client ? $db_client['nama_client'] : str_replace('_', ' ', $resolved_folder);
$display_subtitle = "Terima kasih telah merayakan bersama kami!";
if ($db_client && !empty($db_client['lokasi'])) {
    $display_subtitle = str_replace(["\r\n", "\n"], ' • ', $db_client['lokasi']);
}
$display_tab_title = "Galeri - " . $display_title;
$visible_folders = ['Prints', 'Singles', 'Animated', 'Videos'];
$hidden_files = [];

$config_path = $target_dir . '/config.json';
if (file_exists($config_path)) {
    $json = json_decode(file_get_contents($config_path), true);
    if (!empty($json['title'])) {
        $display_title = $json['title'];
        $display_tab_title = "Galeri - " . $display_title;
    }
    if (!empty(trim($json['subtitle'] ?? ''))) {
        $display_subtitle = $json['subtitle'];
    }
    if (!empty(trim($json['tab_title'] ?? ''))) {
        $display_tab_title = trim($json['tab_title']);
    }
    if (isset($json['visible_folders']) && is_array($json['visible_folders'])) {
        $visible_folders = $json['visible_folders'];
    }
    if (isset($json['hidden_files']) && is_array($json['hidden_files'])) {
        $hidden_files = $json['hidden_files'];
    }
}

// Membaca file rekursif dari photos/{EventName}/{Prints,Animated,Singles,...}
$files_data = [];
if (is_dir($target_dir)) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target_dir));
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $fname_only = $file->getFilename();
            if (strpos($fname_only, '.part_') !== false || $fname_only === 'config.json') continue;

            $ext = strtolower($file->getExtension());
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'mp4', 'mov', 'webm'])) {
                $path = str_replace('\\', '/', $file->getPathname());
                $target_norm = str_replace('\\', '/', $target_dir);
                $rel_path = ltrim( substr($path, strlen($target_norm)), '/' );

                $parts = explode('/', $rel_path);
                $parent_folder = count($parts) > 1 ? $parts[0] : '';

                // Aturan khusus dslrBooth / LumaBooth:
                // 1. Abaikan folder 'thumb', 'settings', dan 'templates'
                $rel_path_lower = strtolower($rel_path);
                if (strpos($rel_path_lower, 'thumb') !== false ||
                    strpos($rel_path_lower, 'settings') !== false ||
                    strpos($rel_path_lower, 'template') !== false) {
                    continue;
                }

                // 2. Di folder Animated, abaikan file JPG thumbnail pendamping MP4
                if (strtolower($parent_folder) === 'animated' && in_array($ext, ['jpg', 'jpeg', 'png'])) {
                    continue;
                }

                // 3. Sembunyikan file jika masuk daftar hidden_files
                if (in_array($rel_path, $hidden_files)) {
                    continue;
                }

                if ($parent_folder === '' || in_array($parent_folder, $visible_folders)) {
                    $encoded_client = rawurlencode($resolved_folder);
                    $encoded_rel_path = implode('/', array_map('rawurlencode', explode('/', $rel_path)));
                    $files_data[] = [
                        'file' => $rel_path,
                        'path' => 'photos/' . $encoded_client . '/' . $encoded_rel_path,
                        'ext'  => $ext,
                        'time' => $file->getMTime()
                    ];
                }
            }
        }
    }
}

// =========================================================================
// ALGORITMA GROUPING PER SESI LUMABOOTH (Anchor-Based Proximity <= 45 detik)
// Mengelompokkan Prints + Animated (MP4/GIF) + Singles dari 1 sesi foto tamu!
// =========================================================================
$sessions_map = [];
$anchor_timestamps = [];

$extractTs = function($fname, $fallback_time) {
    if (preg_match('/^(\d{4})(\d{2})(\d{2})_(\d{2})(\d{2})(\d{2})/', $fname, $m)) {
        return strtotime("{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:{$m[6]}");
    }
    return $fallback_time;
};

// Tahap 1: Daftarkan semua Anchor Sesi terlebih dahulu (dari Prints / Animated / Videos / GIF)
foreach ($files_data as $data) {
    $path_lower = strtolower($data['file']);
    $filename = basename($data['file']);
    $ext = $data['ext'];

    if (strpos($path_lower, 'prints/') !== false ||
        strpos($path_lower, 'animated/') !== false ||
        strpos($path_lower, 'videos/') !== false ||
        in_array($ext, ['mp4', 'gif', 'mov', 'webm'])) {

        if (preg_match('/^(\d{8}_\d{6}_\d{3})/', $filename, $matches)) {
            $base_id = $matches[1];
        } else {
            $base_id = preg_replace('/(_mp4|_gif|\.[a-zA-Z0-9]+)$/i', '', $filename);
        }

        if (!isset($sessions_map[$base_id])) {
            $ts = $extractTs($filename, $data['time']);
            $sessions_map[$base_id] = [
                'id' => 'session_' . $base_id,
                'time' => $ts,
                'files' => []
            ];
            $anchor_timestamps[$base_id] = $ts;
        }
    }
}

// Tahap 2: Masukkan seluruh file (termasuk Singles & Originals) ke Sesi Anchor terdekat
foreach ($files_data as $data) {
    $filename = basename($data['file']);
    $ts = $extractTs($filename, $data['time']);

    if (preg_match('/^(\d{8}_\d{6}_\d{3})/', $filename, $matches)) {
        $base_id = $matches[1];
    } else {
        $base_id = preg_replace('/(_mp4|_gif|\.[a-zA-Z0-9]+)$/i', '', $filename);
    }

    if (isset($sessions_map[$base_id])) {
        $sessions_map[$base_id]['files'][] = $data;
        if ($ts > $sessions_map[$base_id]['time']) {
            $sessions_map[$base_id]['time'] = $ts;
        }
        continue;
    }

    $best_anchor_id = null;
    $min_diff = 999999;
    foreach ($anchor_timestamps as $aid => $ats) {
        $diff = abs($ats - $ts);
        if ($diff <= 45 && $diff < $min_diff) {
            $min_diff = $diff;
            $best_anchor_id = $aid;
        }
    }

    if ($best_anchor_id !== null) {
        $sessions_map[$best_anchor_id]['files'][] = $data;
        if ($ts > $sessions_map[$best_anchor_id]['time']) {
            $sessions_map[$best_anchor_id]['time'] = $ts;
        }
    } else {
        if (!isset($sessions_map[$base_id])) {
            $sessions_map[$base_id] = [
                'id' => 'session_' . $base_id,
                'time' => $ts,
                'files' => [$data]
            ];
        } else {
            $sessions_map[$base_id]['files'][] = $data;
            if ($ts > $sessions_map[$base_id]['time']) {
                $sessions_map[$base_id]['time'] = $ts;
            }
        }
    }
}

// Urutkan sesi dari yang paling baru
usort($sessions_map, function($a, $b) {
    return $b['time'] - $a['time'];
});

// Urutkan isi file dalam tiap sesi: 1. Prints (Cover Utama), 2. Animated/Video, 3. Singles
foreach ($sessions_map as &$session) {
    usort($session['files'], function($a, $b) {
        $getPriority = function($f) {
            $path_lower = strtolower($f['file']);
            if (strpos($path_lower, 'prints/') !== false) return 3;
            if (strpos($path_lower, 'animated/') !== false || strpos($path_lower, 'videos/') !== false || in_array($f['ext'], ['mp4', 'gif', 'mov', 'webm'])) return 2;
            return 1;
        };
        $pA = $getPriority($a);
        $pB = $getPriority($b);
        if ($pA !== $pB) return $pB - $pA;
        return $a['time'] - $b['time'];
    });
}
unset($session);

$sessions = array_values($sessions_map);

// Jika dipanggil via AJAX untuk live auto-refresh tanpa kedip
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'session_count' => count($sessions),
        'total_files' => count($files_data),
        'sessions' => $sessions
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($display_tab_title); ?></title>
    <link rel="icon" href="https://qr.yuhu.co.id/2025/icon.png" type="image/png">
    <link rel="apple-touch-icon" href="https://qr.yuhu.co.id/2025/icon.png">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&family=Poppins:wght@700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <script>
        let gallerySessions = <?php echo json_encode($sessions); ?>;
        let totalFilesCount = <?php echo count($files_data); ?>;
    </script>
</head>
<body>

    <header class="gallery-header">
        <div class="logo">
            <img src="https://qr.yuhu.co.id/client/logo.png" onerror="this.src='logo.png'" alt="YUHU PhotoBooth Logo">
        </div>
        <div class="event-info">
            <h1><?php echo htmlspecialchars($display_title); ?></h1>
            <p><?php echo nl2br(htmlspecialchars($display_subtitle)); ?></p>
        </div>
    </header>

    <main class="gallery-container">
        <div id="emptyState" class="empty-state" style="<?php echo empty($sessions) ? '' : 'display:none;'; ?>">
            <p>Belum ada foto yang diunggah untuk event ini.</p>
            <p>Menunggu foto dari LumaBooth...</p>
        </div>

        <div id="masonryGrid" class="masonry-grid" style="<?php echo empty($sessions) ? 'display:none;' : ''; ?>">
            <?php foreach ($sessions as $index => $session): ?>
                <?php $cover = $session['files'][0]; ?>
                <div class="grid-item" onclick="openSession(<?php echo $index; ?>)">
                    <?php if (in_array($cover['ext'], ['mp4', 'mov', 'webm'])): ?>
                        <video src="<?php echo $cover['path']; ?>" muted loop autoplay playsinline></video>
                        <div class="play-icon">▶</div>
                    <?php else: ?>
                        <img src="<?php echo $cover['path']; ?>" alt="Photobooth Session Cover" loading="lazy">
                    <?php endif; ?>

                    <div class="session-badge">
                        <?php echo count($session['files']); ?> Media
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </main>

    <!-- Modal / Lightbox Multi-file per Sesi -->
    <div id="mediaModal" class="modal">
        <span class="close-btn" onclick="closeModal()">&times;</span>
        <div class="modal-content-wrapper">
            <a class="nav-btn prev-btn" onclick="changeSlide(-1)">&#10094;</a>
            <div id="modalMediaContainer"></div>
            <a class="nav-btn next-btn" onclick="changeSlide(1)">&#10095;</a>

            <div class="modal-actions">
                <a id="downloadBtn" href="#" download class="btn-download">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                    Download File Ini
                </a>
                <div class="slide-indicator" id="slideIndicator">1 / 1</div>
            </div>
        </div>
    </div>

    <script src="script.js"></script>
    <script>
        // Auto-Sync Real-time setiap 8 detik: otomatis memunculkan foto baru dari LumaBooth tanpa refresh
        setInterval(async () => {
            try {
                const res = await fetch(`?client=<?php echo rawurlencode($resolved_folder); ?>&ajax=1`);
                const data = await res.json();
                if (data.total_files !== totalFilesCount) {
                    totalFilesCount = data.total_files;
                    gallerySessions = data.sessions;
                    const grid = document.getElementById('masonryGrid');
                    const empty = document.getElementById('emptyState');
                    if (gallerySessions.length === 0) {
                        grid.style.display = 'none';
                        empty.style.display = 'block';
                    } else {
                        empty.style.display = 'none';
                        grid.style.display = 'grid';
                        grid.innerHTML = gallerySessions.map((session, idx) => {
                            const cover = session.files[0];
                            const isVid = ['mp4', 'mov', 'webm'].includes(cover.ext);
                            const mediaTag = isVid
                                ? `<video src="${cover.path}" muted loop autoplay playsinline></video><div class="play-icon">▶</div>`
                                : `<img src="${cover.path}" alt="Photobooth Session Cover" loading="lazy">`;
                            return `
                                <div class="grid-item" onclick="openSession(${idx})">
                                    ${mediaTag}
                                    <div class="session-badge">${session.files.length} Media</div>
                                </div>
                            `;
                        }).join('');
                    }
                }
            } catch (e) {}
        }, 8000);
    </script>
</body>
</html>
