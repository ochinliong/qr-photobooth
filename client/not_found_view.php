<?php
if (!defined('LUMABOOTH_SYNC_LOADED') && !isset($client_name)) {
    http_response_code(404);
}

$app_base_url = rtrim($base_url ?? 'https://files.yuhu.co.id', '/');
$current_full_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
$display_requested_url = preg_replace('#^https?://#i', '', $current_full_url);
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
    <title>Tautan Tidak Terdaftar - YUHU Photobooth</title>
    <link rel="icon" href="https://qr.yuhu.co.id/2025/icon.png" type="image/png">
    <link rel="apple-touch-icon" href="https://qr.yuhu.co.id/2025/icon.png">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            -webkit-tap-highlight-color: transparent;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background-color: #f1f5f9;
            color: #1e293b;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
            align-items: center;
            padding: 30px 16px 50px;
            touch-action: pan-x pan-y;
        }

        .header-logo {
            text-align: center;
            margin-bottom: 25px;
        }

        .header-logo img {
            max-height: 64px;
            width: auto;
            display: inline-block;
        }

        .error-card {
            background: #ffffff;
            width: 100%;
            max-width: 580px;
            border-radius: 20px;
            box-shadow: 0 10px 30px -5px rgba(0, 0, 0, 0.07), 0 4px 10px -2px rgba(0, 0, 0, 0.04);
            border: 1px solid #e2e8f0;
            padding: 36px 28px;
            text-align: center;
        }

        .badge-warning {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #fef3c7;
            color: #92400e;
            font-size: 11.5px;
            font-weight: 700;
            letter-spacing: 0.8px;
            padding: 6px 14px;
            border-radius: 999px;
            border: 1px solid #fde68a;
            margin-bottom: 16px;
            text-transform: uppercase;
        }

        .error-card h1 {
            font-family: 'Poppins', sans-serif;
            font-size: clamp(20px, 4.5vw, 25px);
            font-weight: 700;
            color: #0f172a;
            line-height: 1.3;
            margin-bottom: 12px;
        }

        .url-box {
            background: #f8fafc;
            border: 1px dashed #cbd5e1;
            border-radius: 12px;
            padding: 10px 14px;
            margin-bottom: 18px;
            word-break: break-all;
            display: flex;
            flex-direction: column;
            gap: 4px;
            text-align: left;
        }

        .url-label {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #64748b;
        }

        .url-box code {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 13px;
            color: #dc2626;
            font-weight: 600;
        }

        .error-desc {
            font-size: 14.5px;
            color: #475569;
            line-height: 1.6;
            margin-bottom: 22px;
        }

        .guidance-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 18px;
            text-align: left;
            margin-bottom: 24px;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .guidance-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }

        .guidance-icon {
            font-size: 18px;
            line-height: 1.2;
            flex-shrink: 0;
            margin-top: 1px;
        }

        .guidance-text strong {
            display: block;
            font-size: 13.5px;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 2px;
        }

        .guidance-text p {
            font-size: 12.5px;
            color: #64748b;
            line-height: 1.45;
            margin: 0;
        }

        .suggestions-card {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 14px;
            padding: 16px;
            text-align: left;
            margin-bottom: 24px;
        }

        .suggestions-title {
            font-size: 12.5px;
            font-weight: 700;
            color: #1e40af;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .suggestions-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .suggestion-link {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #ffffff;
            border: 1px solid #93c5fd;
            padding: 10px 14px;
            border-radius: 10px;
            text-decoration: none;
            color: #1d4ed8;
            font-weight: 600;
            font-size: 13px;
            transition: all 0.2s ease;
        }

        .suggestion-link:hover {
            background: #dbeafe;
            border-color: #60a5fa;
            transform: translateY(-1px);
        }

        .actions-group {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .btn-action-primary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: #ffffff;
            font-size: 14.5px;
            font-weight: 600;
            padding: 14px 24px;
            border-radius: 12px;
            text-decoration: none;
            box-shadow: 0 4px 14px rgba(16, 185, 129, 0.3);
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }

        .btn-action-primary:active {
            transform: scale(0.98);
        }

        .btn-action-secondary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: #f1f5f9;
            color: #334155;
            font-size: 14px;
            font-weight: 600;
            padding: 12px 20px;
            border-radius: 12px;
            text-decoration: none;
            border: 1px solid #cbd5e1;
            transition: background 0.15s ease;
        }

        .btn-action-secondary:hover {
            background: #e2e8f0;
        }

        .footer-note {
            margin-top: 22px;
            font-size: 12px;
            color: #94a3b8;
            line-height: 1.5;
        }

        @media (max-width: 480px) {
            body {
                padding: 20px 12px 40px;
            }
            .error-card {
                padding: 26px 18px;
                border-radius: 16px;
            }
            .guidance-box {
                padding: 14px;
            }
        }
    </style>
</head>
<body>

    <header class="header-logo">
        <a href="<?php echo htmlspecialchars($app_base_url); ?>/">
            <img src="https://qr.yuhu.co.id/client/logo.png" onerror="this.src='logo.png'" alt="YUHU PhotoBooth Logo">
        </a>
    </header>

    <main class="error-card">
        <span class="badge-warning">⚠️ Tautan Tidak Terdaftar</span>
        
        <h1>Acara Tidak Ditemukan</h1>

        <div class="url-box">
            <span class="url-label">URL yang Anda buka:</span>
            <code><?php echo htmlspecialchars($display_requested_url); ?></code>
        </div>

        <p class="error-desc">
            Tautan galeri atau nama acara <strong>"<?php echo htmlspecialchars($client_name); ?>"</strong> belum terdaftar di sistem kami atau terdapat kesalahan pengetikan URL.
        </p>

        <?php if (!empty($similar_events)): ?>
        <div class="suggestions-card">
            <div class="suggestions-title">
                <span>💡 Mungkin acara yang Anda maksud:</span>
            </div>
            <div class="suggestions-list">
                <?php foreach ($similar_events as $s_title => $s_url): ?>
                    <a href="<?php echo htmlspecialchars($s_url); ?>" class="suggestion-link">
                        <span><?php echo htmlspecialchars($s_title); ?></span>
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="guidance-box">
            <div class="guidance-item">
                <span class="guidance-icon">✏️</span>
                <div class="guidance-text">
                    <strong>Periksa Kembali Ejaan URL</strong>
                    <p>Pastikan huruf besar/kecil, pemisah spasi, atau tanda strip sudah sesuai dengan link yang diberikan oleh penyelenggara.</p>
                </div>
            </div>
            <div class="guidance-item">
                <span class="guidance-icon">📷</span>
                <div class="guidance-text">
                    <strong>Scan Ulang QR Code</strong>
                    <p>Silahkan scan langsung kode QR yang tertera pada lembaran cetakan foto atau kartu panduan photobooth Anda.</p>
                </div>
            </div>
            <div class="guidance-item">
                <span class="guidance-icon">🔍</span>
                <div class="guidance-text">
                    <strong>Cari Foto Anda Otomatis</strong>
                    <p>Punya cetakan fisik foto? Gunakan kamera HP Anda untuk memfoto cetakan tersebut, dan AI YUHU akan mencari softfile & video Anda secara otomatis!</p>
                </div>
            </div>
        </div>

        <div class="actions-group">
            <a href="<?php echo htmlspecialchars($app_base_url); ?>/scan" class="btn-action-primary">
                <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"></path><circle cx="12" cy="13" r="4"></circle></svg>
                <span>Cari Foto dari Hasil Cetak</span>
            </a>
            <a href="<?php echo htmlspecialchars($app_base_url); ?>/" class="btn-action-secondary">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
                <span>Kembali ke Beranda</span>
            </a>
        </div>

        <p class="footer-note">
            Jika Anda adalah penyelenggara acara dan merasa tautan ini seharusnya sudah aktif, Silahkan hubungi operator photobooth di lokasi atau tim YUHU Photobooth.
        </p>
    </main>

</body>
</html>
