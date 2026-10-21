const modal = document.getElementById('mediaModal');
const modalMediaContainer = document.getElementById('modalMediaContainer');
const downloadBtn = document.getElementById('downloadBtn');
const slideIndicator = document.getElementById('slideIndicator');
const slideDots = document.getElementById('slideDots');
const prevBtn = document.querySelector('.prev-btn');
const nextBtn = document.querySelector('.next-btn');

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

    // Update link download sesuai slide yang sedang tampil
    downloadBtn.href = file.path;
    downloadBtn.setAttribute('download', file.file ? file.file.split('/').pop() : 'download');

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
