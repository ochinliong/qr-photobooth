<?php
require_once __DIR__ . '/../config.php';
$app_base_url = rtrim($base_url ?? 'https://files.yuhu.co.id', '/');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="format-detection" content="telephone=no">
    <title>YUHU Photobooth - Pencarian Foto Cetakan</title>
    <meta name="description" content="Pindai foto cetakan fisik photobooth Anda untuk menemukan softfile dan video aslinya secara otomatis di seluruh galeri YUHU Photobooth.">
    <link rel="stylesheet" href="style.css?v=<?php echo @filemtime(__DIR__ . '/style.css') ?: '2'; ?>">
</head>
<body>
    <div class="scan-container">
        <!-- Header -->
        <header class="scan-header">
            <a href="<?= htmlspecialchars($app_base_url) ?>">
                <img src="../client/logo.png" alt="YUHU Photobooth" class="scan-logo" onerror="this.style.display='none'">
            </a>
            <div class="scan-pill-badge">
                <span class="dot"></span>
                <span>Pencarian Cerdas</span>
            </div>
            <h1 class="scan-title">Pencarian Foto Cetakan</h1>
            <p class="scan-subtitle">
                Arahkan kamera ke foto cetakan fisik Anda untuk menemukan softfile asli dan video dari seluruh arsip galeri YUHU Photobooth.
            </p>
        </header>

        <!-- Main Card Container -->
        <main class="scan-card">
            <!-- Step 1: Inisiasi Pilihan Input Foto -->
            <div id="stepInit">
                <div class="viewfinder-box" onclick="document.getElementById('cameraInput').click()">
                    <!-- Corner Brackets (Viewfinder Focus Style) -->
                    <div class="corner-bracket corner-tl"></div>
                    <div class="corner-bracket corner-tr"></div>
                    <div class="corner-bracket corner-bl"></div>
                    <div class="corner-bracket corner-br"></div>

                    <div class="viewfinder-icon">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"></path>
                            <circle cx="12" cy="13" r="4"></circle>
                        </svg>
                    </div>
                    <div class="viewfinder-text">Ambil Foto Cetakan Fisik</div>
                    <div class="viewfinder-sub">Ketuk di sini untuk membuka kamera</div>
                </div>

                <div class="tips-container">
                    <div class="tip-item">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><line x1="12" y1="8" x2="12" y2="16"></line></svg>
                        Posisi Tegak Lurus
                    </div>
                    <div class="tip-item">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"></circle><line x1="12" y1="1" x2="12" y2="3"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line><line x1="1" y1="12" x2="3" y2="12"></line><line x1="21" y1="12" x2="23" y2="12"></line><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line></svg>
                        Cahaya Merata
                    </div>
                    <div class="tip-item">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 3 21 3 21 9"></polyline><polyline points="9 21 3 21 3 15"></polyline><line x1="21" y1="3" x2="14" y2="10"></line><line x1="3" y1="21" x2="10" y2="14"></line></svg>
                        Penuhi Bingkai
                    </div>
                </div>

                <!-- Hidden file inputs -->
                <input type="file" id="cameraInput" accept="image/*" capture="environment" style="display:none;" onchange="handleScanFile(this)">
                <input type="file" id="galleryInput" accept="image/*" style="display:none;" onchange="handleScanFile(this)">

                <div class="btn-group">
                    <button type="button" onclick="document.getElementById('cameraInput').click()" class="btn-scan btn-primary">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"></path><circle cx="12" cy="13" r="4"></circle></svg>
                        Buka Kamera Foto
                    </button>
                    <button type="button" onclick="document.getElementById('galleryInput').click()" class="btn-scan btn-secondary">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
                        Pilih dari Galeri HP
                    </button>
                </div>
            </div>

            <!-- Step 2: Sedang Menganalisis / Scanning -->
            <div id="stepLoading" style="display:none;">
                <div class="scanning-box">
                    <img id="scanningPreviewImg" src="" alt="Pratinjau Foto" class="scanning-preview-img">
                    <div class="scan-focus-overlay"></div>
                    <div class="scan-pulse-ring"></div>
                </div>
                <div class="scanning-status">
                    <h3 class="scanning-title">Mencari di Seluruh Galeri...</h3>
                    <p class="scanning-desc">Mencocokkan visual foto Anda dengan arsip acara YUHU Photobooth</p>
                </div>
            </div>

            <!-- Step 3: Hasil Berhasil Ditemukan -->
            <div id="stepSuccess" style="display:none;">
                <div class="result-top-bar">
                    <button type="button" onclick="resetScan()" class="btn-back-nav">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                        <span>Pindai Lain</span>
                    </button>
                    <div class="auto-reset-badge" id="autoResetBadge" title="Halaman akan kembali otomatis jika tidak ada aktivitas">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                        <span>Kembali dlm <strong id="autoResetTimerNum">60</strong>s</span>
                    </div>
                </div>

                <div class="result-header">
                    <div class="badge-success" id="matchBadgeTitle">Foto Berhasil Ditemukan</div>
                    <div class="badge-score" id="matchScoreText">Tingkat Kemiripan 95%</div>
                </div>

                <div class="event-info-card">
                    <div class="event-info-label">Ditemukan pada Acara:</div>
                    <div class="event-info-name" id="matchEventName">-</div>
                    <div class="event-info-meta" id="matchEventMeta"></div>
                </div>

                <!-- Tabs jika dalam 1 sesi terdapat beberapa media (Foto Cetak, Video, Foto Satuan) -->
                <div class="session-tabs" id="sessionTabs" style="display:none;"></div>

                <!-- Box Tampilan Media (Foto / Video) -->
                <div class="result-image-box" id="resultMediaBox">
                    <img id="matchResultImg" src="" alt="Foto Ditemukan">
                    <video id="matchResultVideo" src="" controls playsinline class="result-video" style="display:none;"></video>
                </div>

                <div class="btn-group">
                    <button type="button" id="btnDownloadCurrent" onclick="handleDownloadOrSaveMedia()" class="btn-scan btn-primary">
                        <svg id="btnDownloadIcon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                        <span id="btnDownloadText">Simpan Foto ke Galeri</span>
                    </button>
                </div>

                <div class="privacy-badge">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                    <span>Hanya menampilkan sesi foto Anda (Arsip galeri lain dirahasiakan).</span>
                </div>

                <!-- Kandidat Alternatif Lainnya -->
                <div id="topCandidatesContainer" class="candidates-wrap" style="display:none;">
                    <div class="candidates-heading">Bukan foto Anda? Cek alternatif terdekat:</div>
                    <div id="topCandidatesList" class="candidates-grid"></div>
                </div>

                <button type="button" onclick="resetScan()" class="btn-link" style="width:100%; margin-top:14px;">
                    Pindai Foto Cetakan Lain
                </button>
            </div>

            <!-- Step 4: Tidak Ditemukan / Tampilkan Kandidat Terdekat -->
            <div id="stepFail" style="display:none;">
                <div style="text-align:center;">
                    <div class="badge-fail">Foto Belum Ditemukan</div>
                    <p class="fail-desc" id="failMsg">
                        Pastikan foto cetakan diambil tegak lurus dan pencahayaan ruangan cukup.
                    </p>
                </div>

                <div id="fallbackCandidatesList" class="candidates-grid" style="margin-bottom:18px;"></div>

                <div class="btn-group">
                    <button type="button" onclick="resetScan()" class="btn-scan btn-primary">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"></polyline><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path></svg>
                        Coba Foto Ulang
                    </button>
                </div>
            </div>
        </main>

        <!-- Footer -->
        <footer class="scan-footer">
            <p>&copy; <?= date('Y') ?> <a href="<?= htmlspecialchars($app_base_url) ?>">YUHU Photobooth</a>. Hak Cipta Dilindungi.</p>
        </footer>
    </div>

    <script src="script.js?v=<?php echo @filemtime(__DIR__ . '/script.js') ?: '2'; ?>"></script>
</body>
</html>
