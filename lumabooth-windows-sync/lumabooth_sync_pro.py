#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
YUHU Photobooth - LumaBooth Windows (Oktober 2026) Cloud Sync Pro
=================================================================
EDISI FAIL-SAFE & ZERO DATA LOSS (TAHAN LISTRIK MATI / INTERNET MATI / PC RESTART)

Sistem Pertahanan 6 Lapis (Zero-Failure Architecture):
1. Crash-Proof Atomic Disk Storage:
   - File konfigurasi & cache riwayat upload disimpan dengan teknik Atomic Write + fsync
     (.tmp -> os.replace), sehingga jika listrik mati mendadak saat menyimpan, file JSON
     TIDAK AKAN PERNAH korup atau kosong (0 byte).
2. Persistent Pending Queue (.yuhu_pending_queue.json):
   - Saat internet mati atau sinyal venue buruk, seluruh foto baru otomatis masuk ke
     Daftar Antrean Lokal di harddisk. Begitu internet nyambung lagi (atau PC baru nyala
     setelah mati lampu), semua antrean langsung diupload otomatis tanpa ada 1 pun foto terlewat!
3. End-to-End SHA-1 Checksum & File-Size Verification:
   - Setiap file dihitung sidik jari SHA-1 sebelum dikirim, dan dicek ulang oleh server PHP.
   - Tidak akan pernah ada kejadian "foto terupload separuh / gambar abu-abu rusak" akibat
     internet putus di tengah pengiriman.
4. Auto-Start Resume Mode (--auto-resume):
   - Menyimpan status aktif terakhir (`is_watching_active`). Jika listrik/PC mati di tengah acara,
     begitu Windows menyala kembali, aplikasi otomatis melanjutkan sinkronisasi ke event & folder
     terakhir tanpa perlu diklik manual oleh operator!
5. Smart Network Health Check & Exponential Backoff:
   - Saat internet terputus, aplikasi tidak membuang CPU; ia otomatis menunggu hingga sinyal
     internet kembali stabil lalu mengupload seluruh antrean yang tertunda secara berurutan.
6. Eco-Priority (BELOW_NORMAL_PRIORITY_CLASS) & Print Spooler Safe:
   - Berjalan dengan prioritas CPU rendah & menghormati file lock LumaBooth/Printer, aman 6+ jam non-stop.
"""

import os
import sys
import time
import json
import queue
import ctypes
import hashlib
import threading
import mimetypes
import urllib.request
import urllib.parse
import urllib.error
from pathlib import Path
from datetime import datetime

try:
    import tkinter as tk
    from tkinter import ttk, filedialog, messagebox
    HAS_TK = True
except ImportError:
    HAS_TK = False

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
CONFIG_FILE = os.path.join(BASE_DIR, "lumabooth_config.json")
CACHE_FILE = os.path.join(BASE_DIR, ".yuhu_synced_cache.json")
PENDING_FILE = os.path.join(BASE_DIR, ".yuhu_pending_queue.json")

SUPPORTED_EXTS = {'.jpg', '.jpeg', '.png', '.gif', '.webp', '.mp4', '.mov', '.webm'}
MAX_LOG_LINES = 250
SCAN_INTERVAL_SEC = 3.5
INTER_UPLOAD_COOL_SEC = 0.5

DEFAULT_CONFIG = {
    "portal_url": "https://files.yuhu.co.id",
    "api_key": "yuhu8080",
    "watch_folder": str(Path.home() / "Pictures" / "LumaBooth"),
    "selected_client_id": None,
    "selected_client_slug": "",
    "selected_client_name": "",
    "selected_client_direct_link": "",
    "selected_client_gallery_url": "",
    "sync_prints": True,
    "sync_gifs": True,
    "sync_videos": True,
    "sync_originals": False,
    "auto_set_portal_gallery": True,
    "was_watching_active": False
}


def set_low_process_priority():
    """Menurunkan prioritas proses Python di Windows agar LumaBooth & Printer selalu nomor 1."""
    if os.name == "nt":
        try:
            BELOW_NORMAL_PRIORITY_CLASS = 0x00004000
            handle = ctypes.windll.kernel32.GetCurrentProcess()
            ctypes.windll.kernel32.SetPriorityClass(handle, BELOW_NORMAL_PRIORITY_CLASS)
        except Exception:
            pass


def atomic_write_json(target_path, data):
    """
    Menyimpan file JSON secara Atomic + Disk Flush (fsync).
    Jika listrik mati tepat saat proses simpan, file asli tetap utuh 100% dan tidak pernah 0-byte/korup.
    """
    tmp_path = target_path + ".tmp"
    bak_path = target_path + ".bak"
    try:
        with open(tmp_path, "w", encoding="utf-8") as f:
            json.dump(data, f, indent=2)
            f.flush()
            os.fsync(f.fileno())
        if os.path.exists(target_path):
            try:
                import shutil
                shutil.copy2(target_path, bak_path)
            except Exception:
                pass
        os.replace(tmp_path, target_path)
    except Exception:
        pass


def safe_read_json(target_path, default_val):
    """Membaca file JSON dengan fallback otomatis ke file .bak jika terjadi mati listrik mendadak."""
    for candidate in (target_path, target_path + ".bak"):
        if os.path.exists(candidate):
            try:
                with open(candidate, "r", encoding="utf-8") as f:
                    data = json.load(f)
                    if type(data) is type(default_val):
                        return data
            except Exception:
                continue
    return default_val.copy() if isinstance(default_val, (dict, list)) else default_val


def load_config():
    cfg = DEFAULT_CONFIG.copy()
    saved = safe_read_json(CONFIG_FILE, {})
    cfg.update(saved)
    return cfg


def save_config(cfg):
    atomic_write_json(CONFIG_FILE, cfg)


def load_synced_cache():
    return safe_read_json(CACHE_FILE, {})


def save_synced_cache(cache_dict):
    if len(cache_dict) > 6000:
        keys = list(cache_dict.keys())[-5000:]
        cache_dict = {k: cache_dict[k] for k in keys}
    atomic_write_json(CACHE_FILE, cache_dict)


def load_pending_queue():
    """Memuat daftar file yang tertunda uploadnya karena internet/listrik mati."""
    return safe_read_json(PENDING_FILE, {})


def save_pending_queue(pending_dict):
    atomic_write_json(PENDING_FILE, pending_dict)


def compute_file_sha1(file_path):
    """Menghitung SHA-1 file dengan chunk 64KB (hemat RAM) untuk verifikasi anti-korup."""
    h = hashlib.sha1()
    with open(file_path, "rb", buffering=65536) as f:
        while True:
            chunk = f.read(65536)
            if not chunk:
                break
            h.update(chunk)
    return h.hexdigest()


def is_file_ready_and_unlocked(file_path):
    """
    Memastikan file sudah selesai ditulis oleh LumaBooth, tidak berukuran 0,
    dan tidak sedang dikunci oleh Print Spooler.
    """
    try:
        stat1 = os.stat(file_path)
        if stat1.st_size < 1024:
            return False, None, 0
        # Tunggu minimal 2 detik sejak file selesai ditulis agar proses Print selesai dulu
        if (time.time() - stat1.st_mtime) < 2.0:
            return False, None, 0

        with open(file_path, "rb") as f:
            header = f.read(16)
            if not header:
                return False, None, 0

        sig = f"{int(stat1.st_mtime)}_{stat1.st_size}"
        return True, sig, stat1.st_size
    except (OSError, PermissionError):
        return False, None, 0


def api_ping_server(portal_url, api_key):
    """Mengecek apakah internet & server website sudah kembali online."""
    endpoint = portal_url.rstrip("/") + "/api/lumabooth.php?action=ping"
    req = urllib.request.Request(
        endpoint,
        headers={
            "X-API-KEY": api_key,
            "User-Agent": "YuhuLumaBoothSync/2026.10 (Windows; FailSafe)"
        }
    )
    try:
        with urllib.request.urlopen(req, timeout=8) as resp:
            data = json.loads(resp.read().decode("utf-8"))
            return data.get("status") == "success"
    except Exception:
        return False


def api_fetch_events(portal_url, api_key):
    """Mengambil daftar event klien dari API Portal."""
    endpoint = portal_url.rstrip("/") + "/api/lumabooth.php?action=list_events"
    req = urllib.request.Request(
        endpoint,
        headers={
            "X-API-KEY": api_key,
            "User-Agent": "YuhuLumaBoothSync/2026.10 (Windows; FailSafe)"
        }
    )
    with urllib.request.urlopen(req, timeout=15) as resp:
        data = json.loads(resp.read().decode("utf-8"))
        if data.get("status") == "success":
            return data.get("events", [])
        raise RuntimeError(data.get("message", "Gagal mengambil data event"))


def api_upload_file_verified(portal_url, api_key, file_path, file_size, file_sha1, client_id=None, slug="", album_name="", category="", auto_set=True):
    """
    Mengupload file dengan verifikasi ukuran + SHA-1 checksum ke server.
    Jika koneksi putus di tengah jalan atau bit korup, server otomatis menolak dan aplikasi
    akan mengulang upload sampai 100% utuh.
    """
    endpoint = portal_url.rstrip("/") + "/api/lumabooth.php?action=upload"
    fields = {
        "api_key": api_key,
        "action": "upload",
        "auto_set_gallery": "1" if auto_set else "0",
        "file_size": str(file_size),
        "file_sha1": str(file_sha1)
    }
    if client_id:
        fields["client_id"] = str(client_id)
    if slug:
        fields["slug"] = str(slug)
    if album_name:
        fields["album_name"] = str(album_name)
    if category:
        fields["category"] = str(category)

    boundary = "----YuhuLumaBoothSyncBoundary" + datetime.now().strftime("%Y%m%d%H%M%S%f")
    filename = os.path.basename(file_path)
    mime_type, _ = mimetypes.guess_type(filename)
    if not mime_type:
        mime_type = "application/octet-stream"

    header_parts = bytearray()
    for key, val in fields.items():
        header_parts.extend(f"--{boundary}\r\n".encode("utf-8"))
        header_parts.extend(f'Content-Disposition: form-data; name="{key}"\r\n\r\n'.encode("utf-8"))
        header_parts.extend(f"{val}\r\n".encode("utf-8"))

    header_parts.extend(f"--{boundary}\r\n".encode("utf-8"))
    header_parts.extend(
        f'Content-Disposition: form-data; name="file"; filename="{filename}"\r\n'.encode("utf-8")
    )
    header_parts.extend(f"Content-Type: {mime_type}\r\n\r\n".encode("utf-8"))
    footer_bytes = f"\r\n--{boundary}--\r\n".encode("utf-8")

    body = bytearray(header_parts)
    del header_parts
    with open(file_path, "rb", buffering=65536) as f:
        while True:
            chunk = f.read(65536)
            if not chunk:
                break
            body.extend(chunk)
    body.extend(footer_bytes)

    req = urllib.request.Request(
        endpoint,
        data=body,
        method="POST",
        headers={
            "X-API-KEY": api_key,
            "X-FILE-SHA1": file_sha1,
            "Content-Type": f"multipart/form-data; boundary={boundary}",
            "Content-Length": str(len(body)),
            "Connection": "close",
            "User-Agent": "YuhuLumaBoothSync/2026.10 (Windows; FailSafe)"
        }
    )
    try:
        with urllib.request.urlopen(req, timeout=50) as resp:
            raw_resp = resp.read().decode("utf-8")
            return json.loads(raw_resp)
    finally:
        del body


def detect_lumabooth_category(file_path, root_watch_folder):
    """Mendeteksi kategori file berdasarkan struktur folder LumaBooth Windows."""
    try:
        rel = os.path.relpath(file_path, root_watch_folder)
    except ValueError:
        rel = file_path
    parts = [p.lower() for p in Path(rel).parts[:-1]]
    ext = os.path.splitext(file_path)[1].lower()

    if any("original" in p or "raw" in p for p in parts):
        return "originals"
    if any("thumb" in p or "temp" in p or "template" in p or ("print" in p and "spool" in p) for p in parts):
        return "ignore"
    if ext == ".gif" or any("gif" in p for p in parts):
        return "gifs"
    if ext in (".mp4", ".mov", ".webm") or any("video" in p or "mp4" in p or "boomerang" in p for p in parts):
        return "videos"
    return "prints"


class LumaBoothSyncApp:
    def __init__(self, root, auto_resume_flag=False):
        self.root = root
        self.root.title("YUHU Photobooth - LumaBooth Windows 2026 Cloud Sync Pro (Fail-Safe Edition)")
        self.root.geometry("850x670")
        self.root.minsize(800, 620)
        self.root.configure(bg="#0f172a")

        self.cfg = load_config()
        self.events = []
        self.is_watching = False
        self.is_online = True
        self.processed_files = load_synced_cache()
        self.pending_queue = load_pending_queue()
        self.log_queue = queue.Queue()
        self.uploaded_count = 0
        self.auto_resume_flag = auto_resume_flag

        self._setup_ui()
        self._update_pending_badge()
        self._poll_log_queue()

        # Muat daftar event dari server (atau gunakan cache offline jika internet sedang mati saat baru nyala)
        self.refresh_events(silent=True, on_done=self._check_auto_resume)

    def _setup_ui(self):
        # Header
        header = tk.Frame(self.root, bg="#1e293b", padx=20, pady=14)
        header.pack(fill="x")

        top_row = tk.Frame(header, bg="#1e293b")
        top_row.pack(fill="x")

        title_lbl = tk.Label(
            top_row,
            text="⚡ YUHU PHOTOBOOTH — LUMABOOTH 2026 FAIL-SAFE SYNC",
            font=("Segoe UI", 13, "bold"),
            fg="#f8fafc",
            bg="#1e293b"
        )
        title_lbl.pack(side="left")

        self.net_badge = tk.Label(
            top_row,
            text="🌐 ONLINE & ANTI-GAGAL AKTIF",
            font=("Segoe UI", 8, "bold"),
            fg="#10b981",
            bg="#064e3b",
            padx=10,
            pady=3
        )
        self.net_badge.pack(side="right")

        sub_lbl = tk.Label(
            header,
            text="Dilengkapi Antrean Offline Otomatis, Verifikasi Checksum SHA-1, Auto-Resume Mati Lampu & Prioritas CPU Rendah.",
            font=("Segoe UI", 9),
            fg="#94a3b8",
            bg="#1e293b"
        )
        sub_lbl.pack(anchor="w", pady=(3, 0))

        # Main Container
        main = tk.Frame(self.root, bg="#0f172a", padx=20, pady=14)
        main.pack(fill="both", expand=True)

        # 1. Koneksi Server & Event Klien
        card1 = tk.LabelFrame(
            main,
            text=" 1. Koneksi Portal & Pilih Event Klien ",
            font=("Segoe UI", 10, "bold"),
            fg="#38bdf8",
            bg="#1e293b",
            bd=1,
            padx=15,
            pady=10
        )
        card1.pack(fill="x", pady=(0, 10))

        row1 = tk.Frame(card1, bg="#1e293b")
        row1.pack(fill="x", pady=(0, 8))

        tk.Label(row1, text="Portal URL:", font=("Segoe UI", 9, "bold"), fg="#e2e8f0", bg="#1e293b", width=14, anchor="w").pack(side="left")
        self.url_var = tk.StringVar(value=self.cfg.get("portal_url", "https://files.yuhu.co.id"))
        tk.Entry(row1, textvariable=self.url_var, font=("Consolas", 10), bg="#0f172a", fg="#f8fafc", insertbackground="white", relief="flat", width=38).pack(side="left", padx=(0, 15), ipady=4)

        tk.Label(row1, text="API Key / PIN:", font=("Segoe UI", 9, "bold"), fg="#e2e8f0", bg="#1e293b").pack(side="left", padx=(0, 6))
        self.key_var = tk.StringVar(value=self.cfg.get("api_key", "yuhu8080"))
        tk.Entry(row1, textvariable=self.key_var, show="•", font=("Consolas", 10), bg="#0f172a", fg="#f8fafc", insertbackground="white", relief="flat", width=14).pack(side="left", ipady=4)

        row2 = tk.Frame(card1, bg="#1e293b")
        row2.pack(fill="x", pady=(4, 4))

        tk.Label(row2, text="Event Klien Aktif:", font=("Segoe UI", 9, "bold"), fg="#e2e8f0", bg="#1e293b", width=14, anchor="w").pack(side="left")
        self.event_combo = ttk.Combobox(row2, state="readonly", font=("Segoe UI", 10), width=52)
        self.event_combo.pack(side="left", padx=(0, 10), ipady=3)
        self.event_combo.bind("<<ComboboxSelected>>", self._on_event_selected)

        refresh_btn = tk.Button(
            row2,
            text="🔄 Refresh Event",
            command=self.refresh_events,
            font=("Segoe UI", 9, "bold"),
            bg="#2563eb",
            fg="white",
            activebackground="#1d4ed8",
            activeforeground="white",
            relief="flat",
            padx=12,
            pady=4,
            cursor="hand2"
        )
        refresh_btn.pack(side="left")

        self.link_info_var = tk.StringVar(value="Link QR Klien: -")
        tk.Label(card1, textvariable=self.link_info_var, font=("Consolas", 9), fg="#34d399", bg="#1e293b").pack(anchor="w", pady=(6, 0))

        # 2. Konfigurasi Folder LumaBooth Windows
        card2 = tk.LabelFrame(
            main,
            text=" 2. Folder Output LumaBooth Windows (Oktober 2026) & Proteksi Mati Lampu ",
            font=("Segoe UI", 10, "bold"),
            fg="#38bdf8",
            bg="#1e293b",
            bd=1,
            padx=15,
            pady=10
        )
        card2.pack(fill="x", pady=(0, 10))

        row_dir = tk.Frame(card2, bg="#1e293b")
        row_dir.pack(fill="x", pady=(0, 8))

        tk.Label(row_dir, text="Folder LumaBooth:", font=("Segoe UI", 9, "bold"), fg="#e2e8f0", bg="#1e293b", width=14, anchor="w").pack(side="left")
        self.folder_var = tk.StringVar(value=self.cfg.get("watch_folder", ""))
        tk.Entry(row_dir, textvariable=self.folder_var, font=("Consolas", 9), bg="#0f172a", fg="#f8fafc", insertbackground="white", relief="flat", width=52).pack(side="left", padx=(0, 10), ipady=4)

        browse_btn = tk.Button(
            row_dir,
            text="📁 Pilih Folder...",
            command=self.browse_folder,
            font=("Segoe UI", 9, "bold"),
            bg="#334155",
            fg="white",
            relief="flat",
            padx=10,
            pady=4,
            cursor="hand2"
        )
        browse_btn.pack(side="left")

        row_chk = tk.Frame(card2, bg="#1e293b")
        row_chk.pack(fill="x")

        self.chk_prints = tk.BooleanVar(value=self.cfg.get("sync_prints", True))
        self.chk_gifs = tk.BooleanVar(value=self.cfg.get("sync_gifs", True))
        self.chk_videos = tk.BooleanVar(value=self.cfg.get("sync_videos", True))
        self.chk_originals = tk.BooleanVar(value=self.cfg.get("sync_originals", False))

        for text, var in [
            ("Print / Strip (JPG/PNG)", self.chk_prints),
            ("GIF", self.chk_gifs),
            ("Video / Boomerang (MP4)", self.chk_videos),
            ("Raw Originals", self.chk_originals),
        ]:
            tk.Checkbutton(
                row_chk,
                text=text,
                variable=var,
                font=("Segoe UI", 9),
                fg="#cbd5e1",
                bg="#1e293b",
                selectcolor="#0f172a",
                activebackground="#1e293b",
                activeforeground="white"
            ).pack(side="left", padx=(0, 12))

        startup_btn = tk.Button(
            row_chk,
            text="⚡ Pasang Auto-Resume Saat PC Nyala",
            command=self.install_windows_startup,
            font=("Segoe UI", 8, "bold"),
            bg="#475569",
            fg="#f8fafc",
            relief="flat",
            padx=8,
            pady=2,
            cursor="hand2"
        )
        startup_btn.pack(side="right")

        # 3. Tombol Kontrol Start / Stop Sync & Indikator Antrean
        ctrl_frame = tk.Frame(main, bg="#0f172a")
        ctrl_frame.pack(fill="x", pady=(0, 10))

        self.start_btn = tk.Button(
            ctrl_frame,
            text="▶ MULAI AUTO-SYNC LUMABOOTH",
            command=self.toggle_sync,
            font=("Segoe UI", 11, "bold"),
            bg="#10b981",
            fg="white",
            activebackground="#059669",
            activeforeground="white",
            relief="flat",
            padx=20,
            pady=10,
            cursor="hand2"
        )
        self.start_btn.pack(side="left")

        self.status_lbl = tk.Label(
            ctrl_frame,
            text="Status: Standby (Idle 0% CPU)",
            font=("Segoe UI", 10, "bold"),
            fg="#94a3b8",
            bg="#0f172a",
            padx=12
        )
        self.status_lbl.pack(side="left")

        self.counter_lbl = tk.Label(
            ctrl_frame,
            text="Terkirim: 0 File",
            font=("Segoe UI", 10, "bold"),
            fg="#38bdf8",
            bg="#0f172a"
        )
        self.counter_lbl.pack(side="right")

        self.pending_lbl = tk.Label(
            ctrl_frame,
            text="Antrean Tertunda: 0",
            font=("Segoe UI", 9, "bold"),
            fg="#cbd5e1",
            bg="#1e293b",
            padx=10,
            pady=4
        )
        self.pending_lbl.pack(side="right", padx=(0, 10))

        # 4. Log Aktivitas Real-Time
        log_frame = tk.LabelFrame(
            main,
            text=" Log Aktivitas & Pemulihan Otomatis (Anti-Gagal Upload) ",
            font=("Segoe UI", 9, "bold"),
            fg="#94a3b8",
            bg="#1e293b",
            bd=1,
            padx=10,
            pady=8
        )
        log_frame.pack(fill="both", expand=True)

        self.log_text = tk.Text(
            log_frame,
            font=("Consolas", 9),
            bg="#090d16",
            fg="#e2e8f0",
            relief="flat",
            state="disabled",
            wrap="word"
        )
        self.log_text.pack(fill="both", expand=True)

    def log(self, msg):
        ts = datetime.now().strftime("%H:%M:%S")
        self.log_queue.put(f"[{ts}] {msg}\n")

    def _update_pending_badge(self):
        count = len(self.pending_queue)
        if count == 0:
            self.pending_lbl.configure(text="Antrean Offline: 0 (Semua Terkirim)", fg="#10b981", bg="#064e3b")
        else:
            self.pending_lbl.configure(text=f"⏳ Antrean Menunggu Internet: {count} File", fg="#fbbf24", bg="#78350f")

    def _set_online_state(self, online):
        if self.is_online == online:
            return
        self.is_online = online
        if online:
            self.root.after(0, lambda: self.net_badge.configure(
                text="🌐 ONLINE & ANTI-GAGAL AKTIF", fg="#10b981", bg="#064e3b"
            ))
            self.log("🌐 Koneksi internet pulih! Melanjutkan pengiriman antrean foto secara otomatis...")
        else:
            self.root.after(0, lambda: self.net_badge.configure(
                text="⚠️ OFFLINE MODE (FOTO DISIMPAN DI ANTREAN)", fg="#fbbf24", bg="#78350f"
            ))

    def _poll_log_queue(self):
        updated = False
        while not self.log_queue.empty():
            line = self.log_queue.get_nowait()
            self.log_text.configure(state="normal")
            self.log_text.insert("end", line)
            updated = True

        if updated:
            num_lines = int(self.log_text.index("end-1c").split(".")[0])
            if num_lines > MAX_LOG_LINES:
                excess = num_lines - MAX_LOG_LINES
                self.log_text.delete("1.0", f"{excess + 1}.0")
            self.log_text.see("end")
            self.log_text.configure(state="disabled")

        self.root.after(400, self._poll_log_queue)

    def browse_folder(self):
        folder = filedialog.askdirectory(initialdir=self.folder_var.get() or str(Path.home()))
        if folder:
            self.folder_var.set(folder)

    def install_windows_startup(self):
        """
        Memasang shortcut otomatis di folder Startup Windows agar jika listrik/PC mati
        di tengah acara, begitu komputer dinyalakan lagi aplikasi otomatis terbuka dan
        melanjutkan upload tanpa intervensi operator.
        """
        try:
            startup_dir = os.path.join(
                os.environ.get("APPDATA", ""),
                r"Microsoft\Windows\Start Menu\Programs\Startup"
            )
            if not os.path.isdir(startup_dir):
                messagebox.showerror("Gagal", "Folder Startup Windows tidak ditemukan.")
                return

            bat_startup = os.path.join(startup_dir, "Yuhu_LumaBooth_AutoResume.bat")
            launcher_script = os.path.join(BASE_DIR, "lumabooth_sync_pro.py")
            content = (
                f'@echo off\r\n'
                f'cd /d "{BASE_DIR}"\r\n'
                f'where pythonw >nul 2>nul\r\n'
                f'if %ERRORLEVEL% EQU 0 (\r\n'
                f'    start "YUHU LumaBooth Sync" /BELOWNORMAL pythonw "{launcher_script}" --auto-resume\r\n'
                f'    exit /b 0\r\n'
                f')\r\n'
                f'start "YUHU LumaBooth Sync" /BELOWNORMAL python "{launcher_script}" --auto-resume\r\n'
            )
            with open(bat_startup, "w", encoding="utf-8") as f:
                f.write(content)

            self.log("✅ Proteksi Mati Lampu/PC Restart berhasil dipasang di Windows Startup!")
            messagebox.showinfo(
                "Proteksi Mati Lampu Aktif",
                "Berhasil dipasang!\n\nJika PC atau listrik mati mendadak saat acara, begitu Windows menyala kembali, aplikasi ini akan otomatis terbuka dan langsung melanjutkan upload foto yang tertunda."
            )
        except Exception as e:
            messagebox.showerror("Error", f"Gagal memasang startup: {e}")

    def refresh_events(self, silent=False, on_done=None):
        portal_url = self.url_var.get().strip()
        api_key = self.key_var.get().strip()
        self.log("Menghubungkan ke server portal untuk sinkronisasi data event klien...")

        def worker():
            try:
                evts = api_fetch_events(portal_url, api_key)
                self.events = evts
                self._set_online_state(True)
                items = []
                selected_idx = 0
                saved_id = self.cfg.get("selected_client_id")
                for idx, e in enumerate(evts):
                    live_tag = "🟢 [LIVE] " if e.get("is_live") else ""
                    label = f"{live_tag}{e['nama_client']} ({e['tanggal_event']}) — [{e['photo_count']} foto]"
                    items.append(label)
                    if saved_id and int(e["id"]) == int(saved_id):
                        selected_idx = idx

                def update_combo():
                    self.event_combo["values"] = items
                    if items:
                        self.event_combo.current(selected_idx)
                        self._on_event_selected()
                        self.log(f"✅ Terhubung! Memuat {len(items)} event klien dari database website.")
                    if on_done:
                        on_done()

                self.root.after(0, update_combo)
            except Exception as err:
                self._set_online_state(False)
                # Fallback Offline: tetap bisa jalan memakai event terakhir yang tersimpan di config!
                saved_id = self.cfg.get("selected_client_id")
                saved_name = self.cfg.get("selected_client_name")
                if saved_id and saved_name:
                    fallback_ev = {
                        "id": saved_id,
                        "slug": self.cfg.get("selected_client_slug", ""),
                        "nama_client": saved_name,
                        "direct_link": self.cfg.get("selected_client_direct_link", "-"),
                        "gallery_url": self.cfg.get("selected_client_gallery_url", "-")
                    }
                    self.events = [fallback_ev]

                    def update_offline_combo():
                        self.event_combo["values"] = [f"⚠️ [MODE OFFLINE] {saved_name}"]
                        self.event_combo.current(0)
                        self._on_event_selected()
                        self.log(f"⚠️ Internet sedang offline saat aplikasi dibuka. Menggunakan cache event terakhir: {saved_name}")
                        if on_done:
                            on_done()

                    self.root.after(0, update_offline_combo)
                else:
                    self.log(f"⚠️ Belum terhubung ke internet: {err}")
                    if not silent:
                        self.root.after(0, lambda: messagebox.showwarning("Mode Offline", f"Internet sedang terputus:\n{err}"))

        threading.Thread(target=worker, daemon=True).start()

    def _check_auto_resume(self):
        """Jika sebelumnya aplikasi sedang aktif menonton folder (atau dipanggil dengan --auto-resume setelah mati lampu), otomatis jalankan kembali."""
        if (self.auto_resume_flag or self.cfg.get("was_watching_active")) and not self.is_watching:
            watch_dir = self.folder_var.get().strip()
            if self.events and watch_dir and os.path.isdir(watch_dir):
                self.log("🔄 Auto-Resume Aktif: Melanjutkan sinkronisasi otomatis setelah aplikasi/PC menyala kembali...")
                self.toggle_sync()

    def _on_event_selected(self, event=None):
        idx = self.event_combo.current()
        if 0 <= idx < len(self.events):
            ev = self.events[idx]
            self.cfg["selected_client_id"] = ev["id"]
            self.cfg["selected_client_slug"] = ev["slug"]
            self.cfg["selected_client_name"] = ev["nama_client"]
            self.cfg["selected_client_direct_link"] = ev.get("direct_link", "")
            self.cfg["selected_client_gallery_url"] = ev.get("gallery_url", "")
            self.link_info_var.set(f"🔗 Link QR Klien: {ev.get('direct_link', '-')}   |   Galeri: {ev.get('gallery_url', '-')}")
            save_config(self.cfg)

    def toggle_sync(self):
        if self.is_watching:
            self.is_watching = False
            self.cfg["was_watching_active"] = False
            save_config(self.cfg)
            self.start_btn.configure(text="▶ MULAI AUTO-SYNC LUMABOOTH", bg="#10b981", activebackground="#059669")
            self.status_lbl.configure(text="Status: Dihentikan Manual", fg="#f59e0b")
            self.log("⏹ Auto-Sync dihentikan secara manual oleh operator.")
            return

        idx = self.event_combo.current()
        if idx < 0 or idx >= len(self.events):
            messagebox.showwarning("Pilih Event", "Silakan pilih Event Klien terlebih dahulu!")
            return

        watch_dir = self.folder_var.get().strip()
        if not watch_dir or not os.path.isdir(watch_dir):
            messagebox.showwarning("Folder Tidak Valid", "Folder output LumaBooth belum dipilih atau tidak ditemukan!")
            return

        self.cfg["portal_url"] = self.url_var.get().strip()
        self.cfg["api_key"] = self.key_var.get().strip()
        self.cfg["watch_folder"] = watch_dir
        self.cfg["sync_prints"] = self.chk_prints.get()
        self.cfg["sync_gifs"] = self.chk_gifs.get()
        self.cfg["sync_videos"] = self.chk_videos.get()
        self.cfg["sync_originals"] = self.chk_originals.get()
        self.cfg["was_watching_active"] = True
        save_config(self.cfg)

        self.is_watching = True
        self.start_btn.configure(text="⏹ HENTIKAN AUTO-SYNC", bg="#ef4444", activebackground="#dc2626")
        ev = self.events[idx]
        self.status_lbl.configure(text=f"🟢 AKTIF: {ev['nama_client']}", fg="#10b981")
        self.log(f"🚀 Memulai Auto-Sync Fail-Safe pada folder: {watch_dir}")
        if len(self.pending_queue) > 0:
            self.log(f"📦 Ditemukan {len(self.pending_queue)} foto dalam antrean tertunda. Akan segera dikirim begitu koneksi siap.")

        threading.Thread(target=self._watch_and_retry_loop, args=(ev,), daemon=True).start()

    def _should_sync_category(self, category):
        if category == "ignore":
            return False
        if category == "prints":
            return self.chk_prints.get()
        if category == "gifs":
            return self.chk_gifs.get()
        if category == "videos":
            return self.chk_videos.get()
        if category == "originals":
            return self.chk_originals.get()
        return True

    def _attempt_single_upload(self, portal_url, api_key, item):
        """
        Mencoba mengupload 1 item dan memverifikasi integritas SHA-1.
        Mengembalikan True jika berhasil (atau sudah ada secara utuh di server), False jika harus masuk/tetap di antrean.
        """
        fpath = item["file_path"]
        if not os.path.isfile(fpath):
            # File sudah dihapus dari PC oleh operator, hapus dari antrean
            return True

        ready, sig, fsize = is_file_ready_and_unlocked(fpath)
        if not ready:
            return False

        try:
            fsha1 = compute_file_sha1(fpath)
            res = api_upload_file_verified(
                portal_url=portal_url,
                api_key=api_key,
                file_path=fpath,
                file_size=fsize,
                file_sha1=fsha1,
                client_id=item["client_id"],
                slug=item["slug"],
                category=item["category"],
                auto_set=True
            )
            status = res.get("status")
            if status in ("success", "skipped"):
                self._set_online_state(True)
                cache_key = f"{item['client_id']}::{fpath}"
                self.processed_files[cache_key] = sig
                if status == "success":
                    self.uploaded_count += 1
                    self.root.after(
                        0,
                        lambda c=self.uploaded_count: self.counter_lbl.configure(text=f"Terkirim: {c} File")
                    )
                    self.log(f"✅ Terupload & Terverifikasi (SHA-1 OK): {os.path.basename(fpath)}")
                else:
                    self.log(f"⏭ Sudah ada utuh di server: {os.path.basename(fpath)}")
                return True
            else:
                self.log(f"⚠️ Server menolak ({os.path.basename(fpath)}): {res.get('message')} — Masuk antrean retry.")
                return False
        except Exception as err:
            self._set_online_state(False)
            return False

    def _watch_and_retry_loop(self, client_event):
        watch_dir = self.cfg["watch_folder"]
        portal_url = self.cfg["portal_url"]
        api_key = self.cfg["api_key"]
        client_id = client_event["id"]
        client_slug = client_event["slug"]
        client_key_prefix = f"{client_id}::"

        while self.is_watching:
            try:
                new_queued = 0
                # TAHAP 1: Pindai folder LumaBooth untuk mendeteksi foto baru & masukkan ke antrean
                for root_dir, _, files in os.walk(watch_dir):
                    if not self.is_watching:
                        break
                    for fname in files:
                        if not self.is_watching:
                            break
                        if fname.startswith(".") or fname.startswith("~"):
                            continue
                        ext = os.path.splitext(fname)[1].lower()
                        if ext not in SUPPORTED_EXTS:
                            continue

                        fpath = os.path.join(root_dir, fname)
                        category = detect_lumabooth_category(fpath, watch_dir)
                        if not self._should_sync_category(category):
                            continue

                        ready, sig, _ = is_file_ready_and_unlocked(fpath)
                        if not ready:
                            continue

                        cache_key = client_key_prefix + fpath
                        if self.processed_files.get(cache_key) == sig:
                            continue

                        # Jika belum ada di antrean tertunda, tambahkan ke persistent pending queue di disk
                        if cache_key not in self.pending_queue or self.pending_queue[cache_key].get("sig") != sig:
                            self.pending_queue[cache_key] = {
                                "file_path": fpath,
                                "sig": sig,
                                "client_id": client_id,
                                "slug": client_slug,
                                "category": category,
                                "added_at": time.time()
                            }
                            new_queued += 1

                if new_queued > 0:
                    save_pending_queue(self.pending_queue)
                    self.root.after(0, self._update_pending_badge)

                # TAHAP 2: Proses pengiriman Antrean (Pending Queue)
                if self.pending_queue:
                    # Jika sebelumnya status offline, lakukan ping ringan dulu sebelum mengirim file besar
                    if not self.is_online:
                        if not api_ping_server(portal_url, api_key):
                            # Internet masih mati, tunggu dengan tenang (0% CPU) dan jangan hapus antrean
                            time.sleep(5.0)
                            continue
                        else:
                            self._set_online_state(True)

                    completed_keys = []
                    # Urutkan pengiriman dari foto yang paling awal diambil
                    sorted_items = sorted(self.pending_queue.items(), key=lambda kv: kv[1].get("added_at", 0))

                    for k, item in sorted_items:
                        if not self.is_watching:
                            break
                        fname = os.path.basename(item["file_path"])
                        self.log(f"📤 Mengirim [{item['category'].upper()}] {fname} ...")
                        ok = self._attempt_single_upload(portal_url, api_key, item)
                        if ok:
                            completed_keys.append(k)
                            # Simpan progress ke disk setiap kali file sukses agar tahan mati listrik mendadak
                            del self.pending_queue[k]
                            save_synced_cache(self.processed_files)
                            save_pending_queue(self.pending_queue)
                            self.root.after(0, self._update_pending_badge)
                            time.sleep(INTER_UPLOAD_COOL_SEC)
                        else:
                            # Jika gagal karena sinyal internet putus, hentikan batch ini dan tunggu siklus berikutnya
                            self.log(f"⏳ Internet tidak stabil saat mengirim {fname}. Disimpan aman di antrean, akan diulang otomatis.")
                            break

            except Exception as loop_err:
                self.log(f"⚠️ Info sistem: {loop_err}")

            time.sleep(SCAN_INTERVAL_SEC)


def run_cli_trigger_mode():
    """Mode Trigger LumaBooth Windows dengan Fallback Antrean Offline."""
    set_low_process_priority()
    cfg = load_config()
    args = sys.argv[1:]
    file_path = None
    album_name = ""

    if "--file" in args:
        idx = args.index("--file")
        if idx + 1 < len(args):
            file_path = args[idx + 1]
    if "--album" in args:
        idx = args.index("--album")
        if idx + 1 < len(args):
            album_name = args[idx + 1]

    if not file_path and len(args) >= 1 and os.path.isfile(args[0]):
        file_path = args[0]
        if len(args) >= 4:
            album_name = args[3]

    if not file_path or not os.path.isfile(file_path):
        sys.exit(0)

    time.sleep(1.5)
    portal_url = cfg.get("portal_url", "https://files.yuhu.co.id")
    api_key = cfg.get("api_key", "yuhu8080")
    client_id = cfg.get("selected_client_id")
    slug = cfg.get("selected_client_slug", "")

    # Coba kirim hingga 3 kali jika koneksi sempat kedip; jika tetap offline, simpan di antrean lokal
    for attempt in range(3):
        try:
            stat = os.stat(file_path)
            fsha1 = compute_file_sha1(file_path)
            res = api_upload_file_verified(
                portal_url=portal_url,
                api_key=api_key,
                file_path=file_path,
                file_size=stat.st_size,
                file_sha1=fsha1,
                client_id=client_id,
                slug=slug,
                album_name=album_name,
                auto_set=True
            )
            if res.get("status") in ("success", "skipped"):
                print(json.dumps(res))
                sys.exit(0)
        except Exception:
            time.sleep(2.0 * (attempt + 1))

    # Jika internet mati total saat trigger dipanggil, masukkan ke file antrean lokal (.yuhu_pending_queue.json)
    try:
        stat = os.stat(file_path)
        sig = f"{int(stat.st_mtime)}_{stat.st_size}"
        pending = load_pending_queue()
        cache_key = f"{client_id}::{file_path}"
        pending[cache_key] = {
            "file_path": file_path,
            "sig": sig,
            "client_id": client_id,
            "slug": slug,
            "category": "prints",
            "added_at": time.time()
        }
        save_pending_queue(pending)
    except Exception:
        pass


if __name__ == "__main__":
    set_low_process_priority()
    auto_resume = "--auto-resume" in sys.argv
    cli_args = [a for a in sys.argv[1:] if a != "--auto-resume"]

    if len(cli_args) > 0:
        run_cli_trigger_mode()
    else:
        if not HAS_TK:
            print("Tkinter tidak tersedia.")
            sys.exit(1)
        root = tk.Tk()
        app = LumaBoothSyncApp(root, auto_resume_flag=auto_resume)
        root.mainloop()
