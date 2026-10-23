<?php
require_once __DIR__ . '/../config.php';
$app_base_url = rtrim($base_url ?? 'https://files.yuhu.co.id', '/');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>YUHU Photobooth - Scan Foto Cetakan</title>
    <meta name="description" content="Pindai foto cetakan fisik photobooth Anda untuk menemukan softfile dan video aslinya secara otomatis di seluruh galeri YUHU Photobooth.">
    <link rel="stylesheet" href="style.css?v=<?php echo @filemtime(__DIR__ . '/style.css') ?: '1'; ?>">
</head>
<body>
    <div class="scan-container">
        <!-- Header -->
        <header class="scan-header">
            <a href="<?= htmlspecialchars($app_base_url) ?>">
                <img src="../client/logo.png" alt="YUHU Photobooth" class="scan-logo" onerror="this.style.display='none'">
            </a>
            <h1 class="scan-title">Scan Foto Cetakan</h1>
            <p class="scan-subtitle">
                Punya foto cetakan fisik? Ambil fotonya sekarang untuk mencari softfile asli & video di seluruh galeri YUHU tanpa barcode!
            </p>
        </header>

        <!-- Main Card Container -->
        <main class="scan-card">
            <!-- Step 1: Inisiasi Pilihan Input Foto -->
            <div id="stepInit">
                <div class="viewfinder-box" onclick="document.getElementById('cameraInput').click()">
                    <div class="viewfinder-icon">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"></path><circle cx="12" cy="13" r="4"></circle></svg>
                    </div>
                    <div class="viewfinder-text">Ketuk untuk Mengambil Foto Cetakan</div>
                    <div class="viewfinder-sub">Pastikan cetakan dipegang tegak lurus</div>
                </div>

                <div class="tips-container">
                    <div class="tip-item">
                        <span>📐</span>
                        Tegak Lurus
                    </div>
                    <div class="tip-item">
                        <span>💡</span>
                        Cahaya Cukup
                    </div>
                    <div class="tip-item">
                        <span>🔍</span>
                        Penuhi Layar
                    </div>
                </div>

                <!-- Hidden file inputs -->
                <input type="file" id="cameraInput" accept="image/*" capture="environment" style="display:none;" onchange="handleScanFile(this)">
                <input type="file" id="galleryInput" accept="image/*" style="display:none;" onchange="handleScanFile(this)">

                <div class="btn-group">
                    <button type="button" onclick="document.getElementById('cameraInput').click()" class="btn-scan btn-primary">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"></path><circle cx="12" cy="13" r="4"></circle></svg>
                        Ambil Foto dengan Kamera
                    </button>
                    <button type="button" onclick="document.getElementById('galleryInput').click()" class="btn-scan btn-secondary">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
                        Pilih dari Galeri Foto HP
                    </button>
                </div>
            </div>

            <!-- Step 2: Sedang Menganalisis / Scanning -->
            <div id="stepLoading" style="display:none;">
                <div class="scanning-box">
                    <img id="scanningPreviewImg" src="" alt="Preview Cetakan" class="scanning-preview-img">
                    <div class="laser-line"></div>
                </div>
                <div class="scanning-status">
                    <h3 class="scanning-title">Memindai di Seluruh Galeri...</h3>
                    <p class="scanning-desc">Menganalisis kontur visual & palet pose di seluruh acara YUHU Photobooth</p>
                </div>
            </div>

            <!-- Step 3: Hasil Berhasil Ditemukan -->
            <div id="stepSuccess" style="display:none;">
                <div class="result-header">
                    <div class="badge-success" id="matchBadgeTitle">🎉 Foto Berhasil Ditemukan!</div>
                    <div class="badge-score" id="matchScoreText">Tingkat Kemiripan: 95%</div>
                </div>

                <div class="event-info-card">
                    <div class="event-info-label">Ditemukan pada Acara:</div>
                    <div class="event-info-name" id="matchEventName">-</div>
                    <div class="event-info-meta" id="matchEventMeta"></div>
                </div>

                <div class="result-image-box">
                    <img id="matchResultImg" src="" alt="Foto Ditemukan">
                </div>

                <div class="btn-group">
                    <a id="btnOpenSession" href="#" class="btn-scan btn-primary">
                        🔍 Buka Sesi Lengkap (Foto & Video)
                    </a>
                    <a id="btnDownloadMatched" href="#" download class="btn-scan btn-secondary">
                        ⬇️ Download Foto Ini
                    </a>
                    <a id="btnOpenGallery" href="#" class="btn-link">
                        Lihat Seluruh Galeri Acara Ini →
                    </a>
                </div>

                <!-- Kandidat Alternatif Lainnya -->
                <div id="topCandidatesContainer" class="candidates-wrap" style="display:none;">
                    <div class="candidates-heading">Bukan foto di atas? Coba cek alternatif ini:</div>
                    <div id="topCandidatesList" class="candidates-grid"></div>
                </div>

                <button type="button" onclick="resetScan()" class="btn-link" style="width:100%; margin-top:14px;">
                    🔄 Scan Foto Cetakan Lain
                </button>
            </div>

            <!-- Step 4: Tidak Ditemukan / Tampilkan Kandidat Terdekat -->
            <div id="stepFail" style="display:none;">
                <div style="text-align:center;">
                    <div class="badge-fail">🔍 Belum Menemukan Foto yang Cocok</div>
                    <p class="fail-desc" id="failMsg">
                        Pastikan foto cetakan diambil tegak lurus dan pencahayaan ruangan cukup.
                    </p>
                </div>

                <div id="fallbackCandidatesList" class="candidates-grid" style="margin-bottom:18px;"></div>

                <div class="btn-group">
                    <button type="button" onclick="resetScan()" class="btn-scan btn-primary">
                        🔄 Coba Foto Ulang Cetakan
                    </button>
                    <a href="<?= htmlspecialchars($app_base_url) ?>" class="btn-scan btn-secondary">
                        Kembali ke Portal Acara
                    </a>
                </div>
            </div>
        </main>

        <!-- Footer -->
        <footer class="scan-footer">
            <p>&copy; <?= date('Y') ?> <a href="<?= htmlspecialchars($app_base_url) ?>">YUHU Photobooth</a>. All rights reserved.</p>
        </footer>
    </div>

    <script src="script.js?v=<?php echo @filemtime(__DIR__ . '/script.js') ?: '1'; ?>"></script>
</body>
</html>
