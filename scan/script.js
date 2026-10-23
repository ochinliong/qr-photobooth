/**
 * ============================================================================
 * YUHU PHOTOBOOTH - GLOBAL SCAN (scan.yuhu.co.id) JAVASCRIPT ENGINE
 * ============================================================================
 * Privasi Ketat & App-Like Native Experience:
 * 1. Simpan Foto/Video langsung ke Galeri (iOS Action Sheet "Simpan Gambar/Video")
 * 2. Download instan di Android tanpa reload/pindah halaman
 * 3. Navigasi kembali manual dan auto-return setelah idle tanpa aktivitas
 * ============================================================================
 */

const stepInit = document.getElementById('stepInit');
const stepLoading = document.getElementById('stepLoading');
const stepSuccess = document.getElementById('stepSuccess');
const stepFail = document.getElementById('stepFail');

let currentSessionMediaList = [];
let currentActiveMediaIndex = 0;

// Deteksi perangkat iOS (iPhone / iPad)
function isIOS() {
    return /iPad|iPhone|iPod/.test(navigator.userAgent) || 
           (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
}

// ============================================================================
// PENGUNCIAN ZOOM (APP-LIKE EXPERIENCE)
// Mencegah zoom / gesture multi-touch / double-tap zoom di iOS & Android
// ============================================================================
// Mencegah gesture zoom di iOS Safari
document.addEventListener('gesturestart', function(e) {
    e.preventDefault();
}, { passive: false });

document.addEventListener('gesturechange', function(e) {
    e.preventDefault();
}, { passive: false });

document.addEventListener('gestureend', function(e) {
    e.preventDefault();
}, { passive: false });

// Mencegah pinch zoom 2 jari
document.addEventListener('touchstart', function(e) {
    if (e.touches && e.touches.length > 1) {
        e.preventDefault();
    }
}, { passive: false });

document.addEventListener('touchmove', function(e) {
    if (e.touches && e.touches.length > 1) {
        e.preventDefault();
    }
}, { passive: false });

// Mencegah zoom akibat double-tap cepat di iOS Safari
let lastTouchEndTime = 0;
document.addEventListener('touchend', function(e) {
    const now = Date.now();
    if (now - lastTouchEndTime <= 300) {
        e.preventDefault();
        if (e.target) {
            const clickable = e.target.closest('button, a, .viewfinder-box, .session-tab-btn, .candidate-card, .tip-item');
            if (clickable) {
                clickable.click();
            }
        }
    }
    lastTouchEndTime = now;
}, false);

// Mencegah Ctrl + MouseWheel zoom di PC / trackpad
document.addEventListener('wheel', function(e) {
    if (e.ctrlKey) {
        e.preventDefault();
    }
}, { passive: false });

// ============================================================================
// AUTO-RESET / INACTIVITY TIMER
// ============================================================================
let autoResetTimerInterval = null;
const IDLE_TIMEOUT_SECONDS = 75;
let idleSecondsLeft = IDLE_TIMEOUT_SECONDS;

function startAutoResetTimer() {
    stopAutoResetTimer();
    idleSecondsLeft = IDLE_TIMEOUT_SECONDS;
    updateAutoResetDisplay();

    autoResetTimerInterval = setInterval(() => {
        idleSecondsLeft--;
        updateAutoResetDisplay();
        if (idleSecondsLeft <= 0) {
            stopAutoResetTimer();
            resetScan();
        }
    }, 1000);
}

function stopAutoResetTimer() {
    if (autoResetTimerInterval) {
        clearInterval(autoResetTimerInterval);
        autoResetTimerInterval = null;
    }
}

function updateAutoResetDisplay() {
    const timerNum = document.getElementById('autoResetTimerNum');
    if (timerNum) {
        timerNum.innerText = Math.max(0, idleSecondsLeft);
    }
}

function refreshUserActivity() {
    if (stepSuccess && stepSuccess.style.display !== 'none') {
        idleSecondsLeft = IDLE_TIMEOUT_SECONDS;
        updateAutoResetDisplay();
    }
}

// Reset idle timer pada setiap sentuhan atau interaksi pengguna
['touchstart', 'mousedown', 'click', 'scroll', 'keydown'].forEach(evtName => {
    document.addEventListener(evtName, refreshUserActivity, { passive: true });
});

// ============================================================================
// RESET SCAN
// ============================================================================
function resetScan() {
    stopAutoResetTimer();

    if (stepInit) stepInit.style.display = 'block';
    if (stepLoading) stepLoading.style.display = 'none';
    if (stepSuccess) stepSuccess.style.display = 'none';
    if (stepFail) stepFail.style.display = 'none';

    // Pause video jika sedang menyala
    const resultVideo = document.getElementById('matchResultVideo');
    if (resultVideo) {
        resultVideo.pause();
        resultVideo.src = '';
    }

    const camInput = document.getElementById('cameraInput');
    const galInput = document.getElementById('galleryInput');
    if (camInput) camInput.value = '';
    if (galInput) galInput.value = '';

    const topList = document.getElementById('topCandidatesList');
    if (topList) topList.innerHTML = '';
    const topCont = document.getElementById('topCandidatesContainer');
    if (topCont) topCont.style.display = 'none';

    const fallbackList = document.getElementById('fallbackCandidatesList');
    if (fallbackList) fallbackList.innerHTML = '';

    currentSessionMediaList = [];
    currentActiveMediaIndex = 0;
}

// Kompresi cepat & auto-orientasi EXIF via Canvas di browser HP
function preprocessImage(file) {
    return new Promise((resolve) => {
        const reader = new FileReader();
        reader.onload = function(e) {
            const dataUrl = e.target.result;
            const img = new Image();
            img.onload = function() {
                try {
                    const maxDim = 1200;
                    let w = img.naturalWidth || img.width;
                    let h = img.naturalHeight || img.height;
                    if (w > maxDim || h > maxDim) {
                        if (w > h) {
                            h = Math.round((h * maxDim) / w);
                            w = maxDim;
                        } else {
                            w = Math.round((w * maxDim) / h);
                            h = maxDim;
                        }
                    }
                    const canvas = document.createElement('canvas');
                    canvas.width = w;
                    canvas.height = h;
                    const ctx = canvas.getContext('2d');
                    ctx.drawImage(img, 0, 0, w, h);
                    canvas.toBlob((blob) => {
                        resolve({
                            blob: blob || file,
                            previewUrl: dataUrl
                        });
                    }, 'image/jpeg', 0.85);
                } catch (err) {
                    resolve({ blob: file, previewUrl: dataUrl });
                }
            };
            img.onerror = function() {
                resolve({ blob: file, previewUrl: dataUrl });
            };
            img.src = dataUrl;
        };
        reader.onerror = function() {
            resolve({ blob: file, previewUrl: '' });
        };
        reader.readAsDataURL(file);
    });
}

async function handleScanFile(input) {
    if (!input.files || !input.files[0]) return;
    const file = input.files[0];

    stopAutoResetTimer();
    stepInit.style.display = 'none';
    stepLoading.style.display = 'block';
    stepSuccess.style.display = 'none';
    stepFail.style.display = 'none';

    try {
        const processed = await preprocessImage(file);
        const preview = document.getElementById('scanningPreviewImg');
        if (preview && processed.previewUrl) {
            preview.src = processed.previewUrl;
        }
        executeGlobalScan(processed.blob || file);
    } catch (e) {
        executeGlobalScan(file);
    }
}

// Menampilkan media sesi (Foto Strip, Video, Foto Satuan)
function displayMatchedSession(matchData, badgeLabel = '') {
    const badgeTitle = document.getElementById('matchBadgeTitle');
    const scoreText = document.getElementById('matchScoreText');
    const eventName = document.getElementById('matchEventName');
    const eventMeta = document.getElementById('matchEventMeta');

    if (badgeTitle && badgeLabel) {
        badgeTitle.innerText = badgeLabel;
    }
    if (scoreText) {
        scoreText.innerText = `Tingkat Kemiripan ${matchData.score}%`;
    }
    if (eventName) {
        eventName.innerText = matchData.event_title || 'Acara Photobooth';
    }
    if (eventMeta) {
        const parts = [];
        if (matchData.event_date) parts.push(matchData.event_date);
        if (matchData.event_location) parts.push(matchData.event_location);
        eventMeta.innerHTML = parts.join(' • ');
    }

    // Persiapkan daftar media sesi
    const folderName = matchData.event_folder || '';
    if (Array.isArray(matchData.session_files) && matchData.session_files.length > 0) {
        currentSessionMediaList = matchData.session_files.map(m => ({
            ...m,
            folder: m.folder || folderName
        }));
    } else {
        currentSessionMediaList = [{
            folder: folderName,
            file: matchData.file,
            name: matchData.file ? matchData.file.split('/').pop() : 'foto_photobooth.jpg',
            url: matchData.photo_url,
            is_video: false,
            is_print: true,
            label: 'Foto Cetak'
        }];
    }

    // Render tab switcher jika terdapat lebih dari 1 file dalam sesi
    const tabsContainer = document.getElementById('sessionTabs');
    if (tabsContainer) {
        tabsContainer.innerHTML = '';
        if (currentSessionMediaList.length > 1) {
            const labelCounts = {};
            currentSessionMediaList.forEach(m => {
                const baseLbl = m.label || 'Media';
                labelCounts[baseLbl] = (labelCounts[baseLbl] || 0) + 1;
            });

            const labelIndices = {};
            currentSessionMediaList.forEach((media, idx) => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = `session-tab-btn ${idx === 0 ? 'active' : ''}`;
                
                const baseLbl = media.label || 'Media';
                let displayLbl = baseLbl;
                if (labelCounts[baseLbl] > 1 && baseLbl === 'Foto Satuan') {
                    labelIndices[baseLbl] = (labelIndices[baseLbl] || 0) + 1;
                    displayLbl = `${baseLbl} ${labelIndices[baseLbl]}`;
                }

                btn.innerText = displayLbl;
                btn.addEventListener('click', () => switchSessionMedia(idx));
                tabsContainer.appendChild(btn);
            });
            tabsContainer.style.display = 'flex';
        } else {
            tabsContainer.style.display = 'none';
        }
    }

    // Tampilkan media pertama
    switchSessionMedia(0);

    // Mulai timer auto-reset
    startAutoResetTimer();
}

// Beralih antar file media dalam satu sesi (Foto / Video)
function switchSessionMedia(index) {
    if (!currentSessionMediaList || !currentSessionMediaList[index]) return;
    currentActiveMediaIndex = index;
    refreshUserActivity();

    const media = currentSessionMediaList[index];
    const resultImg = document.getElementById('matchResultImg');
    const resultVideo = document.getElementById('matchResultVideo');

    // Update tab active classes
    const tabButtons = document.querySelectorAll('.session-tab-btn');
    tabButtons.forEach((btn, i) => {
        if (i === index) btn.classList.add('active');
        else btn.classList.remove('active');
    });

    if (media.is_video) {
        if (resultImg) resultImg.style.display = 'none';
        if (resultVideo) {
            resultVideo.style.display = 'block';
            resultVideo.src = media.url;
            resultVideo.load();
        }
    } else {
        if (resultVideo) {
            resultVideo.pause();
            resultVideo.style.display = 'none';
            resultVideo.src = '';
        }
        if (resultImg) {
            resultImg.style.display = 'block';
            resultImg.src = media.url;
        }
    }

    updateDownloadButtonLabel(media);
}

// Update label tombol sesuai platform (iPhone vs Android) dan tipe file
function updateDownloadButtonLabel(media) {
    const btnText = document.getElementById('btnDownloadText');
    if (!btnText || !media) return;

    const ios = isIOS();
    if (media.is_video) {
        btnText.innerText = ios ? 'Simpan Video ke Galeri' : 'Download Video';
    } else if (media.is_print) {
        btnText.innerText = ios ? 'Simpan Foto Cetak' : 'Download Foto Cetak';
    } else {
        btnText.innerText = ios ? 'Simpan Foto ke Galeri' : 'Download Foto';
    }
}

// ============================================================================
// DOWNLOAD & SIMPAN FOTO / VIDEO (IPHONE & ANDROID)
// ============================================================================
async function handleDownloadOrSaveMedia() {
    const media = currentSessionMediaList[currentActiveMediaIndex];
    if (!media) return;

    refreshUserActivity();

    const btn = document.getElementById('btnDownloadCurrent');
    const btnText = document.getElementById('btnDownloadText');
    const originalText = btnText ? btnText.innerText : 'Download';

    if (btn) btn.classList.add('btn-loading');
    if (btnText) btnText.innerText = 'Menyiapkan File...';

    const isVid = media.is_video;
    const ext = media.ext || (isVid ? 'mp4' : 'jpg');
    const mime = isVid ? 'video/mp4' : (ext === 'png' ? 'image/png' : 'image/jpeg');
    const filename = media.name || (isVid ? 'photobooth_video.mp4' : 'photobooth_photo.jpg');

    // Endpoint download proxy same-origin agar bebas CORS
    const proxyBlobUrl = `download.php?folder=${encodeURIComponent(media.folder || '')}&file=${encodeURIComponent(media.file || '')}&action=inline`;
    const proxyDownloadUrl = `download.php?folder=${encodeURIComponent(media.folder || '')}&file=${encodeURIComponent(media.file || '')}&action=download`;

    try {
        if (isIOS()) {
            // ================================================================
            // JALUR IPHONE / IPAD:
            // Gunakan Web Share API dengan File object. Ini akan memunculkan
            // Action Sheet asli iOS dengan pilihan "Simpan Gambar" / "Simpan Video"
            // langsung masuk ke Galeri Foto (Camera Roll) iPhone!
            // ================================================================
            let blob = null;
            try {
                const resp = await fetch(proxyBlobUrl);
                if (resp.ok) blob = await resp.blob();
            } catch (fetchErr) {
                // Fallback ke direct URL jika proxy gagal
                const respDirect = await fetch(media.url);
                if (respDirect.ok) blob = await respDirect.blob();
            }

            if (blob) {
                const fileObj = new File([blob], filename, { type: mime });
                if (navigator.canShare && navigator.canShare({ files: [fileObj] })) {
                    await navigator.share({
                        files: [fileObj],
                        title: isVid ? 'Video YUHU Photobooth' : 'Foto YUHU Photobooth'
                    });
                } else {
                    // Fallback jika canShare false di browser pihak ketiga iOS (misal in-app browser)
                    const blobUrl = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = blobUrl;
                    a.download = filename;
                    a.target = '_blank';
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    setTimeout(() => URL.revokeObjectURL(blobUrl), 10000);
                }
            } else {
                // Fallback direct link
                window.open(proxyDownloadUrl, '_blank');
            }
        } else {
            // ================================================================
            // JALUR ANDROID & DESKTOP:
            // Unduh langsung di latar belakang melalui trigger anchor invisible.
            // Layar tetap bertahan di halaman hasil tanpa reload / jumping.
            // ================================================================
            const a = document.createElement('a');
            a.href = proxyDownloadUrl;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
        }

        // Tampilkan indikator berhasil sejenak tanpa mengubah tampilan
        if (btn) btn.classList.add('btn-success-state');
        if (btnText) btnText.innerText = isIOS() ? 'Berhasil Disimpan!' : 'Berhasil Diunduh!';

        setTimeout(() => {
            if (btn) {
                btn.classList.remove('btn-loading');
                btn.classList.remove('btn-success-state');
            }
            updateDownloadButtonLabel(media);
        }, 2200);

    } catch (err) {
        // Jika pengguna membatalkan share sheet di iPhone (AbortError), kembalikan tombol normal
        if (btn) {
            btn.classList.remove('btn-loading');
            btn.classList.remove('btn-success-state');
        }
        updateDownloadButtonLabel(media);
    }
}

// Render kartu-kartu alternatif terdekat.
// CATATAN PRIVASI: Klik pada kartu HANYA menampilkan sesi milik kartu tersebut,
// TIDAK PERNAH membuka atau membocorkan arsip galeri acara!
function renderCandidateCards(container, candidates, excludePhotoUrl) {
    if (!container) return 0;
    container.innerHTML = '';
    const filtered = (candidates || []).filter(c => c && c.photo_url && c.photo_url !== excludePhotoUrl);
    if (filtered.length === 0) return 0;

    filtered.forEach(cand => {
        const card = document.createElement('div');
        card.className = 'candidate-card';
        card.title = `Lihat hasil foto sesi ini (${cand.event_title})`;
        card.innerHTML = `
            <img src="${cand.photo_url}" alt="Kandidat Foto" loading="lazy">
            <span class="candidate-card-score">${cand.score}% Cocok</span>
            <span class="candidate-card-event">${cand.event_title}</span>
        `;
        card.addEventListener('click', () => {
            stepLoading.style.display = 'none';
            stepFail.style.display = 'none';
            stepSuccess.style.display = 'block';

            displayMatchedSession(cand, 'Foto Alternatif Terpilih');

            const cardElem = document.querySelector('.scan-card');
            if (cardElem) cardElem.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
        container.appendChild(card);
    });
    return filtered.length;
}

async function executeGlobalScan(fileBlob) {
    const formData = new FormData();
    formData.append('photo', fileBlob, 'scan_query.jpg');

    try {
        const response = await fetch('search_all.php', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();
        stepLoading.style.display = 'none';

        if (data && data.success && data.match_found && data.best_match) {
            const match = data.best_match;
            const badgeTitleText = (data.confidence_level === 'high') 
                ? 'Foto Berhasil Ditemukan' 
                : 'Foto Terdekat Ditemukan';

            displayMatchedSession(match, badgeTitleText);

            // Tampilkan foto kandidat alternatif lainnya jika ada
            const topCandidatesList = document.getElementById('topCandidatesList');
            const topCandidatesCont = document.getElementById('topCandidatesContainer');
            if (topCandidatesList && topCandidatesCont) {
                const count = renderCandidateCards(topCandidatesList, data.top_candidates, match.photo_url);
                topCandidatesCont.style.display = count > 0 ? 'block' : 'none';
            }

            stepSuccess.style.display = 'block';
        } else {
            // Belum Menemukan Kecocokan Pasti
            const failBadge = document.querySelector('#stepFail .badge-fail');
            const failMsg = document.getElementById('failMsg');
            const fallbackList = document.getElementById('fallbackCandidatesList');

            if (data && data.top_candidates && data.top_candidates.length > 0) {
                if (failBadge) failBadge.innerText = 'Ditemukan Beberapa Foto Terdekat';
                if (failMsg) failMsg.innerText = 'Sistem menemukan beberapa foto dengan kemiripan visual terdekat. Silahkan ketuk foto Anda di bawah ini jika sesuai:';
                renderCandidateCards(fallbackList, data.top_candidates, '');
                if (fallbackList) fallbackList.style.display = 'grid';
            } else {
                if (failBadge) failBadge.innerText = 'Foto Belum Ditemukan';
                if (failMsg) {
                    failMsg.innerText = (data && (data.message || data.error)) 
                        ? (data.message || data.error) 
                        : 'Pastikan foto cetakan diambil tegak lurus, tidak terpotong, dan dengan pencahayaan yang cukup.';
                }
                if (fallbackList) {
                    fallbackList.innerHTML = '';
                    fallbackList.style.display = 'none';
                }
            }

            stepFail.style.display = 'block';
        }
    } catch (err) {
        stepLoading.style.display = 'none';
        const failBadge = document.querySelector('#stepFail .badge-fail');
        const failMsg = document.getElementById('failMsg');
        const fallbackList = document.getElementById('fallbackCandidatesList');
        if (failBadge) failBadge.innerText = 'Gangguan Pemindaian';
        if (failMsg) {
            failMsg.innerText = 'Terjadi gangguan jaringan atau pemrosesan gambar. Silahkan coba lagi.';
        }
        if (fallbackList) fallbackList.style.display = 'none';
        stepFail.style.display = 'block';
    }
}
