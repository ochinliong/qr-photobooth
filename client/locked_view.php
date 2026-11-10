<?php
if (!defined('LUMABOOTH_SYNC_LOADED') && !isset($display_title)) {
    http_response_code(403);
    exit;
}

$app_base_url = rtrim($base_url ?? 'https://files.yuhu.co.id', '/');
$tgl_formatted = '';
if (!empty($db_client['tanggal_event']) && $db_client['tanggal_event'] !== '0000-00-00') {
    $tgl_obj = strtotime($db_client['tanggal_event']);
    $bulan_indo = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $tgl_formatted = date('d', $tgl_obj) . ' ' . $bulan_indo[(int)date('m', $tgl_obj) - 1] . ' ' . date('Y', $tgl_obj);
}
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
    <title>Akses Galeri Selesai - <?php echo htmlspecialchars($display_title); ?></title>
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
            background: linear-gradient(180deg, #f8fafc 0%, #eef2f6 100%);
            color: #1e293b;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
            align-items: center;
            padding: 36px 16px 50px;
        }

        .header-logo {
            text-align: center;
            margin-bottom: 24px;
        }

        .header-logo img {
            max-height: 64px;
            width: auto;
            display: inline-block;
        }

        .locked-card {
            background: #ffffff;
            width: 100%;
            max-width: 580px;
            border-radius: 24px;
            box-shadow: 0 12px 35px -8px rgba(15, 23, 42, 0.08), 0 4px 12px -2px rgba(15, 23, 42, 0.04);
            border: 1px solid #e2e8f0;
            padding: 38px 28px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .locked-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #f59e0b, #ef4444, #8b5cf6);
        }

        .lock-icon-wrap {
            width: 68px;
            height: 68px;
            background: #fef3c7;
            border: 1px solid #fde68a;
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 18px;
            color: #d97706;
            box-shadow: 0 4px 12px rgba(217, 119, 6, 0.12);
        }

        .badge-locked {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #f1f5f9;
            color: #475569;
            font-size: 11.5px;
            font-weight: 700;
            letter-spacing: 0.8px;
            padding: 5px 14px;
            border-radius: 999px;
            border: 1px solid #cbd5e1;
            margin-bottom: 14px;
            text-transform: uppercase;
        }

        .locked-card h1 {
            font-family: 'Poppins', sans-serif;
            font-size: clamp(20px, 4.5vw, 25px);
            font-weight: 700;
            color: #0f172a;
            line-height: 1.35;
            margin-bottom: 8px;
        }

        .event-meta {
            font-size: 13.5px;
            color: #64748b;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-wrap: wrap;
            gap: 8px;
        }

        .event-meta span {
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .divider {
            height: 1px;
            background: #e2e8f0;
            margin: 0 auto 20px;
            width: 80%;
        }

        .locked-desc {
            font-size: 14.5px;
            color: #334155;
            line-height: 1.65;
            margin-bottom: 24px;
        }

        .privacy-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 18px;
            text-align: left;
            margin-bottom: 26px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .privacy-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }

        .privacy-icon {
            font-size: 18px;
            line-height: 1.2;
            flex-shrink: 0;
            margin-top: 1px;
        }

        .privacy-text strong {
            display: block;
            font-size: 13.5px;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 2px;
        }

        .privacy-text p {
            font-size: 12.5px;
            color: #64748b;
            line-height: 1.5;
            margin: 0;
        }

        .actions-group {
            display: flex;
            flex-direction: column;
            gap: 11px;
        }

        .btn-wa-request {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: #ffffff;
            font-size: 14.5px;
            font-weight: 700;
            padding: 15px 24px;
            border-radius: 14px;
            text-decoration: none;
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.35);
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }

        .btn-wa-request:active {
            transform: scale(0.98);
        }

        .btn-visual-scan {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            background: #ffffff;
            color: #2563eb;
            font-size: 14px;
            font-weight: 600;
            padding: 13px 20px;
            border-radius: 14px;
            text-decoration: none;
            border: 1.5px solid #bfdbfe;
            transition: all 0.15s ease;
        }

        .btn-visual-scan:hover {
            background: #eff6ff;
            border-color: #93c5fd;
        }

        .btn-home {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: #f1f5f9;
            color: #475569;
            font-size: 13.5px;
            font-weight: 600;
            padding: 12px 20px;
            border-radius: 12px;
            text-decoration: none;
            border: 1px solid #cbd5e1;
            transition: background 0.15s ease;
        }

        .btn-home:hover {
            background: #e2e8f0;
        }

        .footer-note {
            margin-top: 22px;
            font-size: 11.5px;
            color: #94a3b8;
            line-height: 1.5;
        }

        @media (max-width: 480px) {
            body {
                padding: 22px 12px 40px;
            }
            .locked-card {
                padding: 28px 18px;
                border-radius: 20px;
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

    <main class="locked-card">
        <div class="lock-icon-wrap">
            <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
        </div>

        <span class="badge-locked">🔒 Sesi Acara Telah Selesai</span>
        
        <h1><?php echo htmlspecialchars($display_title); ?></h1>

        <div class="event-meta">
            <?php if (!empty($tgl_formatted)): ?>
                <span>📅 <?php echo htmlspecialchars($tgl_formatted); ?></span>
            <?php endif; ?>
            <?php if (!empty($db_client['lokasi']) && $db_client['lokasi'] !== '-'): ?>
                <span>📍 <?php echo htmlspecialchars(str_replace(["\r\n", "\n"], ' ', $db_client['lokasi'])); ?></span>
            <?php endif; ?>
        </div>

        <div class="divider"></div>

        <p class="locked-desc">
            Terima kasih telah merayakan momen bersama kami! Sesi photobooth untuk acara ini telah selesai. Demi menjaga <strong>privasi dan kerahasiaan foto</strong> seluruh tamu serta penyelenggara acara, akses galeri publik kini telah dinonaktifkan.
        </p>

        <div class="privacy-box">
            <div class="privacy-item">
                <span class="privacy-icon">🛡️</span>
                <div class="privacy-text">
                    <strong>Kerahasiaan File Terjaga</strong>
                    <p>File foto dan video tidak lagi dapat diakses bebas oleh publik demi kenyamanan dan privasi seluruh tamu.</p>
                </div>
            </div>
            <div class="privacy-item">
                <span class="privacy-icon">📸</span>
                <div class="privacy-text">
                    <strong>Punya Cetakan Fisik?</strong>
                    <p>Anda tetap dapat mencari softfile milik Anda sendiri secara privat menggunakan fitur Scan Cetakan Fisik.</p>
                </div>
            </div>
        </div>

        <?php
        $wa_msg = "Halo YUHU Photobooth, saya ingin meminta file foto saya dari acara: " . $display_title;
        if (!empty($tgl_formatted)) {
            $wa_msg .= " (" . $tgl_formatted . ")";
        }
        $wa_msg .= ". Mohon bantuannya, terima kasih!";
        $wa_url = "https://wa.me/6285603202222?text=" . rawurlencode($wa_msg);
        ?>

        <div class="actions-group">
            <a href="<?php echo htmlspecialchars($wa_url); ?>" target="_blank" class="btn-wa-request">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946.003-6.556 5.338-11.891 11.893-11.891 3.181.001 6.167 1.24 8.413 3.488 2.245 2.248 3.481 5.236 3.48 8.414-.003 6.557-5.338 11.892-11.893 11.892-1.99-.001-3.951-.5-5.688-1.448l-6.305 1.654zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.521.151-.172.2-.296.3-.495.099-.198.05-.372-.025-.521-.075-.148-.669-1.611-.916-2.206-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.095 3.2 5.076 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.695.248-1.29.173-1.414z"/></svg>
                <span>Minta File Foto via WhatsApp</span>
            </a>

            <?php if (!empty($enable_visual_search)): ?>
            <a href="<?php echo htmlspecialchars($app_base_url); ?>/scan" class="btn-visual-scan">
                <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"></path><circle cx="12" cy="13" r="4"></circle></svg>
                <span>Cari Foto dari Cetakan Fisik</span>
            </a>
            <?php endif; ?>

            <a href="<?php echo htmlspecialchars($app_base_url); ?>/" class="btn-home">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
                <span>Kembali ke Beranda</span>
            </a>
        </div>

        <p class="footer-note">
            Khusus Pengantin / Penyelenggara Acara yang memiliki Tautan VIP, Silahkan buka tautan VIP Anda untuk akses dan unduhan tanpa batas.
        </p>
    </main>

</body>
</html>
