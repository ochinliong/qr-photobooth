#!/bin/bash
# ==============================================================================
# YUHU PHOTOBOOTH - LUMABOOTH MACOS REAL-TIME AUTO SYNC
# ==============================================================================
# Skrip ini dirancang khusus untuk macOS (MacBook, Mac Mini, iMac).
# Cukup klik 2x (double-click) file ini di Finder untuk mulai sinkronisasi!
#
# FITUR:
# 1. Mengunggah foto secara real-time ke https://files.yuhu.co.id/client/api_upload.php
# 2. 100% Otomatis melewati folder "Singles" dan "Originals" (hanya Prints & Video).
# 3. Menggunakan curl bawaan macOS (tanpa perlu install software tambahan).
# 4. Mendeteksi otomatis folder LumaBooth / dslrBooth di Mac.
# ==============================================================================

# Pindah ke direktori skrip ini berada
cd "$(dirname "$0")" || exit 1

# Konfigurasi Server YUHU Photobooth
API_URL="https://files.yuhu.co.id/client/api_upload.php"
API_TOKEN="YuhuSecretToken2026"

# Warna Terminal macOS
C_CYAN='\033[0;36m'
C_GREEN='\033[0;32m'
C_YELLOW='\033[1;33m'
C_RED='\033[0;31m'
C_BOLD='\033[1m'
C_RESET='\033[0m'

clear
echo -e "${C_CYAN}${C_BOLD}"
echo "================================================================="
echo "   ✨ YUHU PHOTOBOOTH - LUMABOOTH MACOS SYNC ENGINE ✨           "
echo "================================================================="
echo -e "${C_RESET}"

# Deteksi lokasi default folder LumaBooth di Mac
POSSIBLE_DIRS=(
    "$HOME/Pictures/LumaBooth"
    "$HOME/Pictures/dslrBooth"
    "$HOME/Documents/LumaBooth"
    "$HOME/Documents/dslrBooth"
    "$HOME/Desktop/LumaBooth"
)

WATCH_DIR=""
for d in "${POSSIBLE_DIRS[@]}"; do
    if [ -d "$d" ]; then
        WATCH_DIR="$d"
        break
    fi
done

# Jika tidak ditemukan otomatis, minta input dari pengguna
if [ -z "$WATCH_DIR" ] || [ ! -d "$WATCH_DIR" ]; then
    echo -e "${C_YELLOW}Folder default LumaBooth tidak ditemukan otomatis di Mac ini.${C_RESET}"
    echo -e "Silahkan ${C_BOLD}drag & drop folder LumaBooth Anda${C_RESET} ke jendela Terminal ini, lalu tekan Enter:"
    read -r USER_INPUT_DIR
    # Hilangkan tanda kutip jika di-drag & drop
    WATCH_DIR=$(echo "$USER_INPUT_DIR" | sed -e "s/^'//" -e "s/'$//" -e 's/^"//' -e 's/"$//')
fi

# Validasi folder
if [ ! -d "$WATCH_DIR" ]; then
    echo -e "${C_RED}❌ Error: Folder '$WATCH_DIR' tidak ditemukan!${C_RESET}"
    echo "Tekan Enter untuk keluar..."
    read -r
    exit 1
fi

echo -e "${C_GREEN}📁 Memantau Folder:${C_RESET} ${C_BOLD}$WATCH_DIR${C_RESET}"
echo -e "${C_GREEN}🌐 Server Tujuan:${C_RESET} ${C_BOLD}$API_URL${C_RESET}"
echo -e "${C_YELLOW}🚫 Filter Aktif:${C_RESET} Folder 'Singles' & 'Originals' otomatis diabaikan (Hanya Prints & Videos)."
echo "-----------------------------------------------------------------"
echo -e "${C_BOLD}Sedang memantau foto & video baru... (Tekan Ctrl+C untuk berhenti)${C_RESET}"
echo ""

# File history untuk mencatat file yang sudah berhasil diunggah
HISTORY_FILE="$(dirname "$0")/.uploaded_history.txt"
touch "$HISTORY_FILE"

while true; do
    # Cari file foto dan video di dalam folder acara
    # Ekstensi yang didukung: jpg, jpeg, png, mp4, mov, gif
    find "$WATCH_DIR" -type f \( -iname "*.jpg" -o -iname "*.jpeg" -o -iname "*.png" -o -iname "*.mp4" -o -iname "*.mov" -o -iname "*.gif" \) 2>/dev/null | while read -r FILEPATH; do
        
        # 1. ABAIKAN FOLDER SINGLES & ORIGINALS
        case "$FILEPATH" in
            */Singles/*|*/singles/*|*/Originals/*|*/originals/*)
                continue
                ;;
        esac

        # 2. Cek apakah file ini sudah pernah diunggah sebelumnya
        FILE_MTIME=$(stat -f "%m" "$FILEPATH" 2>/dev/null || stat -c "%Y" "$FILEPATH" 2>/dev/null)
        FILE_KEY="${FILEPATH}_${FILE_MTIME}"

        if grep -Fxq "$FILE_KEY" "$HISTORY_FILE" 2>/dev/null; then
            continue
        fi

        # 3. Ekstrak nama folder event (subfolder di dalam WATCH_DIR)
        REL_PATH="${FILEPATH#$WATCH_DIR/}"
        EVENT_NAME=$(echo "$REL_PATH" | cut -d'/' -f1)
        FILENAME=$(basename "$FILEPATH")

        # Jika file berada langsung di root folder, gunakan nama folder WATCH_DIR
        if [ "$EVENT_NAME" = "$FILENAME" ]; then
            EVENT_NAME=$(basename "$WATCH_DIR")
        fi

        # Pastikan file sudah selesai ditulis (tidak sedang ditulis oleh LumaBooth)
        FILE_SIZE_1=$(stat -f "%z" "$FILEPATH" 2>/dev/null || stat -c "%s" "$FILEPATH" 2>/dev/null)
        sleep 0.5
        FILE_SIZE_2=$(stat -f "%z" "$FILEPATH" 2>/dev/null || stat -c "%s" "$FILEPATH" 2>/dev/null)
        if [ "$FILE_SIZE_1" != "$FILE_SIZE_2" ] || [ "$FILE_SIZE_2" -eq 0 ]; then
            continue
        fi

        TIME_NOW=$(date "+%H:%M:%S")
        echo -e "[${TIME_NOW}] ${C_CYAN}Mengunggah:${C_RESET} ${C_BOLD}$FILENAME${C_RESET} -> Acara: ${C_YELLOW}$EVENT_NAME${C_RESET}..."

        # 4. Kirim ke Server YUHU menggunakan cURL bawaan macOS
        RESPONSE=$(curl -s -S -X POST "$API_URL" \
            -H "X-API-TOKEN: $API_TOKEN" \
            -H "X-CLIENT-EVENT: $EVENT_NAME" \
            -F "client_event=$EVENT_NAME" \
            -F "token=$API_TOKEN" \
            -F "file=@$FILEPATH" \
            --connect-timeout 10 \
            --max-time 120 2>&1)

        # Cek apakah respon mengandung status success
        if echo "$RESPONSE" | grep -q '"status":"success"'; then
            echo -e "         ${C_GREEN}✅ Berhasil Terupload!${C_RESET}"
            echo "$FILE_KEY" >> "$HISTORY_FILE"
        elif echo "$RESPONSE" | grep -q '"ignored"'; then
            echo -e "         ${C_YELLOW}⏩ Dilewati (Single/Original/Duplikat).${C_RESET}"
            echo "$FILE_KEY" >> "$HISTORY_FILE"
        else
            echo -e "         ${C_RED}❌ Gagal:${C_RESET} $RESPONSE"
            echo -e "         ${C_YELLOW}Akan dicoba kembali dalam siklus berikutnya...${C_RESET}"
        fi
    done

    # Tunggu 2 detik sebelum memindai file baru berikutnya
    sleep 2
done
