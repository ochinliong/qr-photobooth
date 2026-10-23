/**
 * ============================================================================
 * YUHU PHOTOBOOTH - GLOBAL SCAN (scan.yuhu.co.id) JAVASCRIPT ENGINE
 * ============================================================================
 */

const stepInit = document.getElementById('stepInit');
const stepLoading = document.getElementById('stepLoading');
const stepSuccess = document.getElementById('stepSuccess');
const stepFail = document.getElementById('stepFail');

function resetScan() {
    if (stepInit) stepInit.style.display = 'block';
    if (stepLoading) stepLoading.style.display = 'none';
    if (stepSuccess) stepSuccess.style.display = 'none';
    if (stepFail) stepFail.style.display = 'none';

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

function renderCandidateCards(container, candidates, excludePhotoUrl) {
    if (!container) return 0;
    container.innerHTML = '';
    const filtered = (candidates || []).filter(c => c && c.photo_url && c.photo_url !== excludePhotoUrl);
    if (filtered.length === 0) return 0;

    filtered.forEach(cand => {
        const card = document.createElement('div');
        card.className = 'candidate-card';
        card.title = `Buka foto di acara: ${cand.event_title}`;
        card.innerHTML = `
            <img src="${cand.photo_url}" alt="Kandidat Foto" loading="lazy">
            <span class="candidate-card-score">${cand.score}% Cocok</span>
            <span class="candidate-card-event">${cand.event_title}</span>
        `;
        card.addEventListener('click', () => {
            if (cand.session_url) {
                window.location.href = cand.session_url;
            } else if (cand.event_url) {
                window.location.href = cand.event_url;
            } else {
                window.open(cand.photo_url, '_blank');
            }
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
            const badgeTitle = document.getElementById('matchBadgeTitle');
            const scoreText = document.getElementById('matchScoreText');
            const eventName = document.getElementById('matchEventName');
            const eventMeta = document.getElementById('matchEventMeta');
            const resultImg = document.getElementById('matchResultImg');
            const btnOpenSession = document.getElementById('btnOpenSession');
            const btnOpenGallery = document.getElementById('btnOpenGallery');
            const btnDownload = document.getElementById('btnDownloadMatched');

            if (badgeTitle) {
                badgeTitle.innerText = (data.confidence_level === 'high') 
                    ? '🎉 Foto Berhasil Ditemukan!' 
                    : '✨ Foto Terdekat Ditemukan';
            }

            if (scoreText) scoreText.innerText = `Tingkat Kemiripan: ${match.score}%`;
            if (eventName) eventName.innerText = match.event_title;

            if (eventMeta) {
                const parts = [];
                if (match.event_date) parts.push(`📅 ${match.event_date}`);
                if (match.event_location) parts.push(`📍 ${match.event_location}`);
                eventMeta.innerHTML = parts.join(' • ');
            }

            if (resultImg) resultImg.src = match.photo_url;

            if (btnOpenSession) {
                btnOpenSession.href = match.session_url || match.event_url;
            }

            if (btnOpenGallery) {
                btnOpenGallery.href = match.event_url;
            }

            if (btnDownload) {
                btnDownload.href = match.photo_url;
                btnDownload.setAttribute('download', match.file.split('/').pop());
            }

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
                if (failBadge) failBadge.innerText = '🔍 Ditemukan Beberapa Foto Terdekat:';
                if (failMsg) failMsg.innerText = 'Sistem mendeteksi beberapa foto cetakan dengan kemiripan visual terdekat di galeri YUHU. Silahkan ketuk foto Anda jika sesuai:';
                renderCandidateCards(fallbackList, data.top_candidates, '');
                if (fallbackList) fallbackList.style.display = 'grid';
            } else {
                if (failBadge) failBadge.innerText = '❌ Belum Menemukan Foto yang Cocok';
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
        if (failBadge) failBadge.innerText = '⚠️ Gangguan Pemindaian';
        if (failMsg) {
            failMsg.innerText = 'Terjadi gangguan jaringan atau pemrosesan gambar. Silahkan coba lagi.';
        }
        if (fallbackList) fallbackList.style.display = 'none';
        stepFail.style.display = 'block';
    }
}
