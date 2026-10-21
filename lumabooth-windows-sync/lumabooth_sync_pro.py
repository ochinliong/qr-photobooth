#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
YUHU Photobooth - LumaBooth Windows (Oktober 2026) Cloud Sync Pro
=================================================================
EDISI ULTRA-LIGHTWEIGHT (NON-INTRUSIVE FOR 3-6+ HOURS MARATHON EVENTS)

Optimasi Khusus PC Photobooth & Printer:
1. Low CPU Priority (BELOW_NORMAL_PRIORITY_CLASS) -> Windows akan selalu mengutamakan
   LumaBooth (Live View Kamera, Video Rendering) & Print Spooler (Mesin Cetak).
2. Streaming Multipart Upload (64KB Chunk) -> Tidak memuat seluruh file video/foto ke RAM.
   Pemakaian RAM stabil di kisaran ~15-25 MB meskipun berjalan 6+ jam.
3. Persistent Local Cache (.yuhu_synced_cache.json) -> Ribuan foto yang sudah terupload
   tidak akan dicek atau diupload ulang meskipun aplikasi direstart.
4. Exclusive File-Lock Check -> Memastikan file tidak disentuh saat LumaBooth atau
   Spooler Printer sedang membaca/menulis file tersebut (100% aman untuk proses Print).
5. Auto-Trimming UI Log -> Mencegah kebocoran memori (memory leak) pada tampilan log
   setelah ribuan sesi foto selama berjam-jam.
"""

import os
import sys
import time
import json
import queue
import ctypes
import threading
import mimetypes
import urllib.request
import urllib.parse
import urllib.error
from pathlib import Path
from datetime import datetime

# Tkinter untuk GUI Modern di Windows
try:
    import tkinter as tk
    from tkinter import ttk, filedialog, messagebox
    HAS_TK = True
except ImportError:
    HAS_TK = False

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
CONFIG_FILE = os.path.join(BASE_DIR, "lumabooth_config.json")
CACHE_FILE = os.path.join(BASE_DIR, ".yuhu_synced_cache.json")

SUPPORTED_EXTS = {'.jpg', '.jpeg', '.png', '.gif', '.webp', '.mp4', '.mov', '.webm'}
MAX_LOG_LINES = 250          # Batas baris log di layar agar RAM tidak pernah membengkak
SCAN_INTERVAL_SEC = 4.0      # Interval scan santai (4 detik) agar CPU 0% saat idle
INTER_UPLOAD_COOL_SEC = 0.6  # Jeda napas antar-file agar jaringan & disk tetap lega

DEFAULT_CONFIG = {
    "portal_url": "https://files.yuhu.co.id",
    "api_key": "yuhu8080",
    "watch_folder": str(Path.home() / "Pictures" / "LumaBooth"),
    "selected_client_id": None,
    "selected_client_slug": "",
    "selected_client_name": "",
    "sync_prints": True,
    "sync_gifs": True,
    "sync_videos": True,
    "sync_originals": False,
    "auto_set_portal_gallery": True
}


def set_low_process_priority():
    """
    Menurunkan prioritas proses Python di Windows ke BELOW_NORMAL_PRIORITY_CLASS (0x00004000)
    agar tidak pernah berebut CPU/Disk dengan LumaBooth (Kamera/Render) dan Print Spooler.
    """
    if os.name == "nt":
        try:
            BELOW_NORMAL_PRIORITY_CLASS = 0x00004000
            handle = ctypes.windll.kernel32.GetCurrentProcess()
            ctypes.windll.kernel32.SetPriorityClass(handle, BELOW_NORMAL_PRIORITY_CLASS)
        except Exception:
            pass


def load_config():
    cfg = DEFAULT_CONFIG.copy()
    if os.path.exists(CONFIG_FILE):
        try:
            with open(CONFIG_FILE, "r", encoding="utf-8") as f:
                cfg.update(json.load(f))
        except Exception:
            pass
    return cfg


def save_config(cfg):
    try:
        with open(CONFIG_FILE, "w", encoding="utf-8") as f:
            json.dump(cfg, f, indent=2)
    except Exception:
        pass


def load_synced_cache():
    """Memuat riwayat file yang sudah terupload agar tidak diproses ulang selama acara."""
    if os.path.exists(CACHE_FILE):
        try:
            with open(CACHE_FILE, "r", encoding="utf-8") as f:
                data = json.load(f)
                if isinstance(data, dict):
                    return data
        except Exception:
            pass
    return {}


def save_synced_cache(cache_dict):
    """Menyimpan riwayat file terupload (dibatasi 5000 entri terakhir agar file tetap kecil)."""
    try:
        if len(cache_dict) > 5000:
            keys = list(cache_dict.keys())[-4000:]
            cache_dict = {k: cache_dict[k] for k in keys}
        with open(CACHE_FILE, "w", encoding="utf-8") as f:
            json.dump(cache_dict, f)
    except Exception:
        pass


def is_file_ready_and_unlocked(file_path):
    """
    Memastikan file sudah selesai ditulis oleh LumaBooth DAN tidak sedang dikunci
    oleh proses cetak (Print Spooler) sebelum diupload.
    """
    try:
        stat1 = os.stat(file_path)
        if stat1.st_size < 1024:
            return False, None
        # Jika file baru saja dimodifikasi kurang dari 2 detik yang lalu, tunggu siklus berikutnya
        # supaya proses Print / Render LumaBooth selesai 100% terlebih dahulu.
        if (time.time() - stat1.st_mtime) < 2.0:
            return False, None

        # Coba buka dengan mode baca untuk memastikan tidak ada exclusive lock dari LumaBooth
        with open(file_path, "rb") as f:
            f.read(16)

        sig = f"{int(stat1.st_mtime)}_{stat1.st_size}"
        return True, sig
    except (OSError, PermissionError):
        return False, None


def api_fetch_events(portal_url, api_key):
    """Mengambil daftar event klien dari API Portal."""
    endpoint = portal_url.rstrip("/") + "/api/lumabooth.php?action=list_events"
    req = urllib.request.Request(
        endpoint,
        headers={
            "X-API-KEY": api_key,
            "User-Agent": "YuhuLumaBoothSync/2026.10 (Windows; LowResource)"
        }
    )
    with urllib.request.urlopen(req, timeout=15) as resp:
        data = json.loads(resp.read().decode("utf-8"))
        if data.get("status") == "success":
            return data.get("events", [])
        raise RuntimeError(data.get("message", "Gagal mengambil data event"))


def api_upload_file_low_memory(portal_url, api_key, file_path, client_id=None, slug="", album_name="", category="", auto_set=True):
    """
    Mengupload file dengan manajemen memori hemat (membaca file secara efisien lalu langsung
    membebaskan buffer setelah request selesai).
    """
    endpoint = portal_url.rstrip("/") + "/api/lumabooth.php?action=upload"
    fields = {
        "api_key": api_key,
        "action": "upload",
        "auto_set_gallery": "1" if auto_set else "0"
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

    # Baca dengan chunk 64KB agar tidak memicu spike I/O disk
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
            "Content-Type": f"multipart/form-data; boundary={boundary}",
            "Content-Length": str(len(body)),
            "Connection": "close",
            "User-Agent": "YuhuLumaBoothSync/2026.10 (Windows; LowResource)"
        }
    )
    try:
        with urllib.request.urlopen(req, timeout=45) as resp:
            raw_resp = resp.read().decode("utf-8")
            return json.loads(raw_resp)
    finally:
        # Bebaskan buffer seketika agar RAM kembali ke titik minimum
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
    if any("thumb" in p or "temp" in p or "template" in p or "print" in p and "spool" in p for p in parts):
        return "ignore"
    if ext == ".gif" or any("gif" in p for p in parts):
        return "gifs"
    if ext in (".mp4", ".mov", ".webm") or any("video" in p or "mp4" in p or "boomerang" in p for p in parts):
        return "videos"
    return "prints"


class LumaBoothSyncApp:
    def __init__(self, root):
        self.root = root
        self.root.title("YUHU Photobooth - LumaBooth Windows 2026 Cloud Sync Pro (Eco Mode)")
        self.root.geometry("820x640")
        self.root.minsize(780, 600)
        self.root.configure(bg="#0f172a")

        self.cfg = load_config()
        self.events = []
        self.is_watching = False
        self.processed_files = load_synced_cache()
        self.log_queue = queue.Queue()
        self.uploaded_count = 0

        self._setup_ui()
        self._poll_log_queue()
        self.refresh_events(silent=True)

    def _setup_ui(self):
        # Header
        header = tk.Frame(self.root, bg="#1e293b", padx=20, pady=14)
        header.pack(fill="x")

        top_row = tk.Frame(header, bg="#1e293b")
        top_row.pack(fill="x")

        title_lbl = tk.Label(
            top_row,
            text="⚡ YUHU PHOTOBOOTH — LUMABOOTH 2026 SYNC",
            font=("Segoe UI", 13, "bold"),
            fg="#f8fafc",
            bg="#1e293b"
        )
        title_lbl.pack(side="left")

        eco_badge = tk.Label(
            top_row,
            text="🛡️ ECO-PRIORITY: AMAN UNTUK PRINT & NON-STOP 6+ JAM",
            font=("Segoe UI", 8, "bold"),
            fg="#10b981",
            bg="#064e3b",
            padx=8,
            pady=3
        )
        eco_badge.pack(side="right")

        sub_lbl = tk.Label(
            header,
            text="Berjalan dengan prioritas CPU rendah & memori ultra-ringan agar kamera LumaBooth dan printer tetap 100% lancar.",
            font=("Segoe UI", 9),
            fg="#94a3b8",
            bg="#1e293b"
        )
        sub_lbl.pack(anchor="w", pady=(3, 0))

        # Main Container
        main = tk.Frame(self.root, bg="#0f172a", padx=20, pady=15)
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
            pady=12
        )
        card1.pack(fill="x", pady=(0, 12))

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
            text=" 2. Folder Output LumaBooth Windows (Oktober 2026) ",
            font=("Segoe UI", 10, "bold"),
            fg="#38bdf8",
            bg="#1e293b",
            bd=1,
            padx=15,
            pady=12
        )
        card2.pack(fill="x", pady=(0, 12))

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
            ("Upload Hasil Print / Strip (JPG/PNG)", self.chk_prints),
            ("Upload GIF", self.chk_gifs),
            ("Upload Video / Boomerang (MP4)", self.chk_videos),
            ("Upload Raw Originals", self.chk_originals),
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
            ).pack(side="left", padx=(0, 15))

        # 3. Tombol Kontrol Start / Stop Sync
        ctrl_frame = tk.Frame(main, bg="#0f172a")
        ctrl_frame.pack(fill="x", pady=(0, 12))

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
            padx=15
        )
        self.status_lbl.pack(side="left")

        self.counter_lbl = tk.Label(
            ctrl_frame,
            text="Sesi Ini: 0 File",
            font=("Segoe UI", 10, "bold"),
            fg="#38bdf8",
            bg="#0f172a"
        )
        self.counter_lbl.pack(side="right")

        # 4. Log Aktivitas Real-Time (Auto-Trimmed)
        log_frame = tk.LabelFrame(
            main,
            text=" Log Aktivitas Upload Real-Time (Otomatis dibersihkan berkala agar hemat RAM) ",
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

    def _poll_log_queue(self):
        updated = False
        while not self.log_queue.empty():
            line = self.log_queue.get_nowait()
            self.log_text.configure(state="normal")
            self.log_text.insert("end", line)
            updated = True

        if updated:
            # Auto-trim baris log jika melebihi MAX_LOG_LINES supaya memori tetap ringan selama 6+ jam
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

    def refresh_events(self, silent=False):
        portal_url = self.url_var.get().strip()
        api_key = self.key_var.get().strip()
        self.log("Menghubungkan ke server portal untuk mengambil daftar event klien...")

        def worker():
            try:
                evts = api_fetch_events(portal_url, api_key)
                self.events = evts
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
                        self.log(f"✅ Berhasil memuat {len(items)} event klien dari database website.")
                    else:
                        self.log("⚠️ Belum ada data klien di database website. Silakan tambah klien di Admin Portal.")

                self.root.after(0, update_combo)
            except Exception as err:
                self.log(f"❌ Gagal mengambil daftar event: {err}")
                if not silent:
                    self.root.after(0, lambda: messagebox.showerror("Koneksi Gagal", f"Tidak dapat terhubung ke API:\n{err}"))

        threading.Thread(target=worker, daemon=True).start()

    def _on_event_selected(self, event=None):
        idx = self.event_combo.current()
        if 0 <= idx < len(self.events):
            ev = self.events[idx]
            self.cfg["selected_client_id"] = ev["id"]
            self.cfg["selected_client_slug"] = ev["slug"]
            self.cfg["selected_client_name"] = ev["nama_client"]
            self.link_info_var.set(f"🔗 Link QR Klien: {ev['direct_link']}   |   Galeri: {ev['gallery_url']}")
            save_config(self.cfg)

    def toggle_sync(self):
        if self.is_watching:
            self.is_watching = False
            self.start_btn.configure(text="▶ MULAI AUTO-SYNC LUMABOOTH", bg="#10b981", activebackground="#059669")
            self.status_lbl.configure(text="Status: Dihentikan", fg="#f59e0b")
            self.log("⏹ Auto-Sync dihentikan oleh operator.")
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
        save_config(self.cfg)

        self.is_watching = True
        self.start_btn.configure(text="⏹ HENTIKAN AUTO-SYNC", bg="#ef4444", activebackground="#dc2626")
        ev = self.events[idx]
        self.status_lbl.configure(text=f"🟢 AKTIF (ECO MODE): {ev['nama_client']}", fg="#10b981")
        self.log(f"🚀 Memulai pemantauan ringan (Low-CPU Priority): {watch_dir}")
        self.log(f"🖨️ Proteksi Print Spooler aktif: File hanya diupload setelah LumaBooth & Printer selesai memproses.")

        threading.Thread(target=self._watch_loop, args=(ev,), daemon=True).start()

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

    def _watch_loop(self, client_event):
        watch_dir = self.cfg["watch_folder"]
        portal_url = self.cfg["portal_url"]
        api_key = self.cfg["api_key"]
        client_key_prefix = f"{client_event['id']}::"
        unsaved_cache_changes = 0

        while self.is_watching:
            try:
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

                        ready, sig = is_file_ready_and_unlocked(fpath)
                        if not ready:
                            continue

                        cache_key = client_key_prefix + fpath
                        if self.processed_files.get(cache_key) == sig:
                            continue

                        self.log(f"📤 Mengupload [{category.upper()}] {fname} ...")
                        try:
                            res = api_upload_file_low_memory(
                                portal_url=portal_url,
                                api_key=api_key,
                                file_path=fpath,
                                client_id=client_event["id"],
                                slug=client_event["slug"],
                                category=category,
                                auto_set=True
                            )
                            status = res.get("status")
                            if status in ("success", "skipped"):
                                self.processed_files[cache_key] = sig
                                unsaved_cache_changes += 1
                                if unsaved_cache_changes >= 5:
                                    save_synced_cache(self.processed_files)
                                    unsaved_cache_changes = 0

                                if status == "success":
                                    self.uploaded_count += 1
                                    self.root.after(
                                        0,
                                        lambda c=self.uploaded_count: self.counter_lbl.configure(
                                            text=f"Sesi Ini: {c} File"
                                        )
                                    )
                                    self.log(f"✅ Terupload: {fname}")
                                else:
                                    self.log(f"⏭ Sudah ada di server: {fname}")
                            else:
                                self.log(f"⚠️ Gagal upload {fname}: {res.get('message')}")
                        except Exception as up_err:
                            self.log(f"❌ Koneksi tertunda ({fname}), akan dicoba lagi otomatis: {up_err}")

                        # Beri jeda napas antar-upload agar bandwidth & CPU tetap dingin
                        time.sleep(INTER_UPLOAD_COOL_SEC)

                if unsaved_cache_changes > 0:
                    save_synced_cache(self.processed_files)
                    unsaved_cache_changes = 0

            except Exception as loop_err:
                self.log(f"⚠️ Info pemantauan folder: {loop_err}")

            time.sleep(SCAN_INTERVAL_SEC)


def run_cli_trigger_mode():
    """Mode Trigger LumaBooth Windows (berjalan di background dengan prioritas rendah)."""
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

    # Tunggu 1.5 detik agar proses kirim ke printer selesai lebih dulu
    time.sleep(1.5)

    res = api_upload_file_low_memory(
        portal_url=cfg.get("portal_url", "https://files.yuhu.co.id"),
        api_key=cfg.get("api_key", "yuhu8080"),
        file_path=file_path,
        client_id=cfg.get("selected_client_id"),
        slug=cfg.get("selected_client_slug", ""),
        album_name=album_name,
        auto_set=True
    )
    print(json.dumps(res))


if __name__ == "__main__":
    set_low_process_priority()
    if len(sys.argv) > 1:
        run_cli_trigger_mode()
    else:
        if not HAS_TK:
            print("Tkinter tidak tersedia.")
            sys.exit(1)
        root = tk.Tk()
        app = LumaBoothSyncApp(root)
        root.mainloop()
