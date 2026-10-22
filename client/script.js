const modal = document.getElementById('mediaModal');
const modalMediaContainer = document.getElementById('modalMediaContainer');
const downloadBtn = document.getElementById('downloadBtn');
const slideIndicator = document.getElementById('slideIndicator');
const slideDots = document.getElementById('slideDots');
const prevBtn = document.querySelector('.prev-btn');
const nextBtn = document.querySelector('.next-btn');

// Deteksi iOS (iPhone / iPad / iPod)
const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) || 
              (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

let currentSessionFiles = [];
let currentIndex = 0;
let scrollRaf = null;

function openSession(sessionIndex) {
    if (!gallerySessions || !gallerySessions[sessionIndex]) return;

    currentSessionFiles = gallerySessions[sessionIndex].files;
    currentIndex = 0;

    renderAllSlides();

    if (currentSessionFiles.length > 1) {
        prevBtn.style.display = 'block';
        nextBtn.style.display = 'block';
        slideIndicator.style.display = 'block';
        if (slideDots) slideDots.style.display = 'flex';
    } else {
        prevBtn.style.display = 'none';
        nextBtn.style.display = 'none';
        slideIndicator.style.display = 'none';
        if (slideDots) slideDots.style.display = 'none';
    }

    // Tampilkan petunjuk khusus jika dibuka dari iPhone
    if (isIOS) {
        const iosHint = document.getElementById('iosHint');
        if (iosHint) iosHint.style.display = 'block';
    }

    modal.classList.add('active');
    document.body.style.overflow = 'hidden';

    // Pastikan mulai dari slide pertama tanpa animasi lompat
    requestAnimationFrame(() => {
        modalMediaContainer.style.scrollBehavior = 'auto';
        modalMediaContainer.scrollLeft = 0;
        modalMediaContainer.style.scrollBehavior = 'smooth';
        syncActiveSlide(0);
    });
}

function renderAllSlides() {
    modalMediaContainer.innerHTML = '';
    if (slideDots) slideDots.innerHTML = '';

    currentSessionFiles.forEach((file, idx) => {
        const slide = document.createElement('div');
        slide.className = 'modal-slide';
        slide.dataset.index = idx;

        const ext = (file.ext || '').toLowerCase();
        if (['mp4', 'mov', 'webm'].includes(ext)) {
            const video = document.createElement('video');
            video.src = file.path;
            video.controls = true;
            video.loop = true;
            video.muted = true;
            video.playsInline = true;
            video.preload = 'metadata';
            video.setAttribute('playsinline', '');
            video.setAttribute('webkit-playsinline', '');
            video.setAttribute('controlsList', 'nodownload');
            slide.appendChild(video);
        } else {
            const img = document.createElement('img');
            img.src = file.path;
            img.alt = 'Photobooth Media ' + (idx + 1);
            img.draggable = false;
            slide.appendChild(img);
        }

        // Klik area kosong di luar foto/video menutup modal
        slide.addEventListener('click', (e) => {
            if (e.target === slide) {
                closeModal();
            }
        });

        modalMediaContainer.appendChild(slide);

        if (slideDots && currentSessionFiles.length > 1) {
            const dot = document.createElement('span');
            dot.className = 'slide-dot' + (idx === 0 ? ' active' : '');
            dot.addEventListener('click', () => scrollToSlide(idx, true));
            slideDots.appendChild(dot);
        }
    });
}

function syncActiveSlide(index) {
    if (!currentSessionFiles.length) return;
    if (index < 0) index = 0;
    if (index >= currentSessionFiles.length) index = currentSessionFiles.length - 1;

    currentIndex = index;
    const file = currentSessionFiles[currentIndex];
    const ext = (file.ext || '').toLowerCase();
    const isVideo = ['mp4', 'mov', 'webm'].includes(ext);

    // Update teks tombol sesuai jenis media & OS (iPhone vs Android/PC)
    const btnText = document.getElementById('downloadBtnText');
    if (btnText) {
        if (isIOS) {
            btnText.innerText = isVideo ? 'Simpan Video ke Photos' : 'Simpan Foto ke Photos';
        } else {
            btnText.innerText = isVideo ? 'Download Video' : 'Download Foto';
        }
    }

    // Update link fallback download
    if (downloadBtn) {
        downloadBtn.setAttribute('data-path', file.path);
        downloadBtn.setAttribute('data-name', file.file ? file.file.split('/').pop() : 'download');
    }

    // Update teks indikator & titik (dots)
    slideIndicator.innerText = (currentIndex + 1) + ' / ' + currentSessionFiles.length;
    if (slideDots) {
        const dots = slideDots.querySelectorAll('.slide-dot');
        dots.forEach((d, i) => {
            d.classList.toggle('active', i === currentIndex);
        });
    }

    // Putar otomatis (inline tanpa fullscreen zoom) jika slide aktif adalah video, pause slide lainnya
    const slides = modalMediaContainer.querySelectorAll('.modal-slide');
    slides.forEach((slide, i) => {
        const vid = slide.querySelector('video');
        if (vid) {
            if (i === currentIndex) {
                vid.play().catch(() => {});
            } else {
                vid.pause();
            }
        }
    });
}

// Download handler khusus: Di iPhone menggunakan Web Share API Level 2 (Save Image/Video langsung ke Photos)
async function handleMediaDownload(e) {
    if (e) e.preventDefault();
    if (!currentSessionFiles.length || !currentSessionFiles[currentIndex]) return;

    const file = currentSessionFiles[currentIndex];
    const filePath = file.path;
    const fileName = file.file ? file.file.split('/').pop() : ('photobooth_' + Date.now());
    const ext = (file.ext || '').toLowerCase();
    const isVideo = ['mp4', 'mov', 'webm'].includes(ext);

    const btn = document.getElementById('downloadBtn');
    const btnText = document.getElementById('downloadBtnText');
    const origText = btnText ? btnText.innerText : (isVideo ? 'Simpan Video' : 'Simpan Foto');

    // 1. Khusus iOS (iPhone / iPad): Gunakan Web Share API agar muncul opsi "Simpan Gambar / Simpan Video" ke Photos
    if (isIOS && navigator.canShare) {
        try {
            if (btnText) btnText.innerText = 'Menyiapkan...';
            btn.style.pointerEvents = 'none';

            // Ambil file sebagai Blob
            const response = await fetch(filePath);
            const blob = await response.blob();

            // Tentukan MIME type yang tepat untuk iOS Photos
            let mimeType = 'image/jpeg';
            if (ext === 'png') mimeType = 'image/png';
            else if (ext === 'webp') mimeType = 'image/webp';
            else if (ext === 'mp4') mimeType = 'video/mp4';
            else if (ext === 'mov') mimeType = 'video/quicktime';

            const fileObj = new File([blob], fileName, { type: mimeType });

            if (navigator.canShare({ files: [fileObj] })) {
                await navigator.share({
                    files: [fileObj],
                    title: fileName,
                });
                if (btnText) btnText.innerText = origText;
                btn.style.pointerEvents = 'auto';
                return;
            }
        } catch (err) {
            // Jika user membatalkan (AbortError) saat share sheet terbuka, abaikan dengan tenang
            if (err.name === 'AbortError') {
                if (btnText) btnText.innerText = origText;
                btn.style.pointerEvents = 'auto';
                return;
            }
            console.warn('Gagal share iOS, gunakan fallback download:', err);
        } finally {
            if (btnText) btnText.innerText = origText;
            btn.style.pointerEvents = 'auto';
        }
    }

    // 2. Untuk Android / Desktop / Browser Lain: Download file secara langsung
    try {
        const a = document.createElement('a');
        a.href = filePath;
        a.download = fileName;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
    } catch (err) {
        window.open(filePath, '_blank');
    }
}

function scrollToSlide(index, smooth = true) {
    if (!currentSessionFiles.length) return;
    if (index >= currentSessionFiles.length) {
        index = 0;
    } else if (index < 0) {
        index = currentSessionFiles.length - 1;
    }

    const slideWidth = modalMediaContainer.clientWidth;
    modalMediaContainer.scrollTo({
        left: slideWidth * index,
        behavior: smooth ? 'smooth' : 'auto'
    });
    syncActiveSlide(index);
}

function changeSlide(direction) {
    scrollToSlide(currentIndex + direction, true);
}

// Deteksi swipe/scroll horizontal secara real-time di HP & Desktop
modalMediaContainer.addEventListener('scroll', () => {
    if (scrollRaf) cancelAnimationFrame(scrollRaf);
    scrollRaf = requestAnimationFrame(() => {
        const width = modalMediaContainer.clientWidth;
        if (width > 0) {
            const newIdx = Math.round(modalMediaContainer.scrollLeft / width);
            if (newIdx !== currentIndex && newIdx >= 0 && newIdx < currentSessionFiles.length) {
                syncActiveSlide(newIdx);
            }
        }
    });
}, { passive: true });

// Pastikan posisi tetap presisi saat orientasi layar HP diputar
window.addEventListener('resize', () => {
    if (modal.classList.contains('active')) {
        scrollToSlide(currentIndex, false);
    }
});

function closeModal() {
    modal.classList.remove('active');
    modalMediaContainer.innerHTML = ''; // Hentikan semua video
    document.body.style.overflow = 'auto';
    currentSessionFiles = [];
}

// Tutup modal jika klik latar belakang gelap
window.onclick = function(event) {
    if (event.target == modal) {
        closeModal();
    }
};

// Navigasi keyboard (Desktop)
document.addEventListener('keydown', function(event) {
    if (!modal.classList.contains('active')) return;

    if (event.key === 'Escape') {
        closeModal();
    } else if (event.key === 'ArrowRight') {
        changeSlide(1);
    } else if (event.key === 'ArrowLeft') {
        changeSlide(-1);
    }
});

/* ==========================================================================
   VISUAL SEARCH (PILIHAN 1: PENCARIAN DARI HASIL CETAK FISIK)
   ========================================================================== */
const visualModal = document.getElementById('visualSearchModal');
const stepInit = document.getElementById('visualSearchStepInit');
const stepLoading = document.getElementById('visualSearchStepLoading');
const stepSuccess = document.getElementById('visualSearchStepSuccess');
const stepFail = document.getElementById('visualSearchStepFail');
let matchedSessionIdx = null;

function openVisualSearchModal() {
    if (!visualModal) return;
    resetVisualSearch();
    visualModal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeVisualSearchModal() {
    if (!visualModal) return;
    visualModal.style.display = 'none';
    if (!modal.classList.contains('active')) {
        document.body.style.overflow = 'auto';
    }
}

function resetVisualSearch() {
    if (stepInit) stepInit.style.display = 'block';
    if (stepLoading) stepLoading.style.display = 'none';
    if (stepSuccess) stepSuccess.style.display = 'none';
    if (stepFail) stepFail.style.display = 'none';
    const camInput = document.getElementById('visualCameraInput');
    const galInput = document.getElementById('visualGalleryInput');
    if (camInput) camInput.value = '';
    if (galInput) galInput.value = '';
}

async function handleVisualSearchFile(input) {
    if (!input.files || !input.files[0]) return;
    const file = input.files[0];

    // Tampilkan pratinjau foto yang diambil tamu dan mulai animasi laser
    const reader = new FileReader();
    reader.onload = function(e) {
        const preview = document.getElementById('visualPreviewImg');
        if (preview) preview.src = e.target.result;

        stepInit.style.display = 'none';
        stepLoading.style.display = 'block';
        stepSuccess.style.display = 'none';
        stepFail.style.display = 'none';

        executeVisualSearch(file);
    };
    reader.readAsDataURL(file);
}

async function executeVisualSearch(file) {
    const urlParams = new URLSearchParams(window.location.search);
    const clientParam = urlParams.get('client') || urlParams.get('c') || '';

    const formData = new FormData();
    formData.append('photo', file);
    formData.append('client', clientParam);

    try {
        const response = await fetch('search_print.php', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();
        stepLoading.style.display = 'none';

        if (data && data.success && data.match_found && data.best_match) {
            // Berhasil Ditemukan!
            const match = data.best_match;
            const resultImg = document.getElementById('matchResultImg');
            const scoreText = document.getElementById('matchScoreText');
            const downloadBtn = document.getElementById('btnDownloadMatchedFile');
            const openSessionBtn = document.getElementById('btnOpenMatchedSession');

            if (resultImg) resultImg.src = match.path;
            if (scoreText) scoreText.innerText = `Tingkat Kemiripan: ${match.score}%`;
            if (downloadBtn) {
                downloadBtn.href = match.path;
                downloadBtn.setAttribute('download', match.file.split('/').pop());
            }

            // Cari index sesi di galeri lokal
            matchedSessionIdx = -1;
            if (typeof gallerySessions !== 'undefined' && Array.isArray(gallerySessions)) {
                for (let i = 0; i < gallerySessions.length; i++) {
                    const sess = gallerySessions[i];
                    const found = sess.files.some(f => 
                        f.path === match.path || 
                        f.file === match.file || 
                        (match.base_id && (f.file.includes(match.base_id) || sess.id.includes(match.base_id)))
                    );
                    if (found) {
                        matchedSessionIdx = i;
                        break;
                    }
                }
            }

            if (openSessionBtn) {
                if (matchedSessionIdx >= 0) {
                    openSessionBtn.style.display = 'flex';
                    openSessionBtn.onclick = function() {
                        closeVisualSearchModal();
                        // Scroll halus ke kartu sesi tersebut di grid
                        const gridItems = document.querySelectorAll('.grid-item');
                        if (gridItems[matchedSessionIdx]) {
                            gridItems[matchedSessionIdx].scrollIntoView({ behavior: 'smooth', block: 'center' });
                        }
                        openSession(matchedSessionIdx);
                    };
                } else {
                    openSessionBtn.style.display = 'none';
                }
            }

            stepSuccess.style.display = 'block';
        } else {
            // Belum Menemukan Kecocokan
            const failMsg = document.getElementById('matchFailMsg');
            if (failMsg && data && data.message) {
                failMsg.innerText = data.message;
            }
            stepFail.style.display = 'block';
        }
    } catch (err) {
        stepLoading.style.display = 'none';
        const failMsg = document.getElementById('matchFailMsg');
        if (failMsg) {
            failMsg.innerText = 'Terjadi gangguan jaringan saat memindai foto. Silahkan coba lagi.';
        }
        stepFail.style.display = 'block';
    }
}

// Tutup modal jika klik di luar card
window.addEventListener('click', function(e) {
    if (e.target === visualModal) {
        closeVisualSearchModal();
    }
});
