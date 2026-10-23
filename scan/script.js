/**
 * ============================================================================
 * YUHU PHOTOBOOTH - GLOBAL SCAN (scan.yuhu.co.id) JAVASCRIPT ENGINE
 * ============================================================================
 * Privasi Ketat: Hanya menampilkan dan mengunduh file sesi foto milik tamu
 * yang dipindai (Foto Cetak, Video Animasi, Foto Satuan). Tidak membuka akses
 * ke seluruh galeri acara.
 * ============================================================================
 */

const stepInit = document.getElementById('stepInit');
const stepLoading = document.getElementById('stepLoading');
const stepSuccess = document.getElementById('stepSuccess');
const stepFail = document.getElementById('stepFail');

let currentSessionMediaList = [];
let currentActiveMediaIndex = 0;

function resetScan() {
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
    if (Array.isArray(matchData.session_files) && matchData.session_files.length > 0) {
        currentSessionMediaList = matchData.session_files;
    } else {
        currentSessionMediaList = [{
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
            // Hitung label duplikat
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
}

// Beralih antar file media dalam satu sesi (Foto / Video)
function switchSessionMedia(index) {
    if (!currentSessionMediaList || !currentSessionMediaList[index]) return;
    currentActiveMediaIndex = index;

    const media = currentSessionMediaList[index];
    const resultImg = document.getElementById('matchResultImg');
    const resultVideo = document.getElementById('matchResultVideo');
    const btnDownload = document.getElementById('btnDownloadCurrent');
    const btnDownloadText = document.getElementById('btnDownloadText');

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
        if (btnDownloadText) btnDownloadText.innerText = 'Download Video Ini';
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
        if (btnDownloadText) {
            btnDownloadText.innerText = media.is_print ? 'Download Foto Cetak' : 'Download Foto Ini';
        }
    }

    if (btnDownload) {
        btnDownload.href = media.url;
        const fileName = media.name || (media.url ? media.url.split('/').pop() : 'photobooth_file');
        btnDownload.setAttribute('download', fileName);
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
            // Tampilkan sesi foto ini langsung di layar hasil tanpa membuka galeri acara
            stepLoading.style.display = 'none';
            stepFail.style.display = 'none';
            stepSuccess.style.display = 'block';

            displayMatchedSession(cand, 'Foto Alternatif Terpilih');

            // Scroll halus ke kartu hasil
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
