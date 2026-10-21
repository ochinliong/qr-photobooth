const modal = document.getElementById('mediaModal');
const modalMediaContainer = document.getElementById('modalMediaContainer');
const downloadBtn = document.getElementById('downloadBtn');
const slideIndicator = document.getElementById('slideIndicator');
const prevBtn = document.querySelector('.prev-btn');
const nextBtn = document.querySelector('.next-btn');

let currentSessionFiles = [];
let currentIndex = 0;

function openSession(sessionIndex) {
    if (!gallerySessions || !gallerySessions[sessionIndex]) return;
    
    currentSessionFiles = gallerySessions[sessionIndex].files;
    currentIndex = 0;
    
    // Array sudah diurutkan dari PHP (Prioritas: Prints -> Singles -> Animasi/Video)
    // Jadi tidak perlu di-sort ulang di JS.

    updateModalMedia();

    if (currentSessionFiles.length > 1) {
        prevBtn.style.display = 'block';
        nextBtn.style.display = 'block';
        slideIndicator.style.display = 'block';
    } else {
        prevBtn.style.display = 'none';
        nextBtn.style.display = 'none';
        slideIndicator.style.display = 'none';
    }

    modal.classList.add('active');
    document.body.style.overflow = 'hidden'; // Prevent scrolling background
}

function updateModalMedia() {
    modalMediaContainer.innerHTML = ''; // Clear previous
    const file = currentSessionFiles[currentIndex];

    if (file.ext === 'mp4') {
        const video = document.createElement('video');
        video.src = file.path;
        video.controls = true;
        video.autoplay = true;
        modalMediaContainer.appendChild(video);
    } else {
        const img = document.createElement('img');
        img.src = file.path;
        modalMediaContainer.appendChild(img);
    }

    // Update download link
    downloadBtn.href = file.path;
    downloadBtn.setAttribute('download', file.file);
    
    // Update indicator
    slideIndicator.innerText = (currentIndex + 1) + " / " + currentSessionFiles.length;
}

function changeSlide(direction) {
    currentIndex += direction;
    
    // Loop around
    if (currentIndex >= currentSessionFiles.length) {
        currentIndex = 0;
    } else if (currentIndex < 0) {
        currentIndex = currentSessionFiles.length - 1;
    }
    
    updateModalMedia();
}

function closeModal() {
    modal.classList.remove('active');
    modalMediaContainer.innerHTML = ''; // Stop video playback by clearing
    document.body.style.overflow = 'auto'; // Restore scrolling
    currentSessionFiles = [];
}

// Close modal when clicking outside the media
window.onclick = function(event) {
    if (event.target == modal) {
        closeModal();
    }
}

// Keyboard navigation
document.addEventListener('keydown', function(event) {
    if (!modal.classList.contains('active')) return;
    
    if (event.key === "Escape") {
        closeModal();
    } else if (event.key === "ArrowRight") {
        changeSlide(1);
    } else if (event.key === "ArrowLeft") {
        changeSlide(-1);
    }
});
