<?php
session_start();

$password = 'sukses123';
$base_dir = 'photos/';

$all_possible_folders = ['Prints', 'Animated', 'Singles', 'Videos', 'Originals', 'GreenScreen'];

if (isset($_POST['login'])) {
    if ($_POST['password'] === $password) {
        $_SESSION['admin_logged_in'] = true;
    } else {
        $error = "Password salah!";
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: admin.php");
    exit;
}

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Login Admin YUHU</title>
        <link rel="icon" href="https://yuhu.co.id/icon.png" type="image/png">
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
        <style>
            * { box-sizing: border-box; }
            body { font-family: 'Inter', sans-serif; background: #0f172a; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; color: white; padding: 20px; }
            .login-box { background: #1e293b; padding: 40px; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.5); text-align: center; width: 100%; max-width: 350px; border: 1px solid #334155; }
            .login-box h2 { margin-top: 0; margin-bottom: 25px; color: #f8fafc; font-weight: 700; }
            input[type="password"] { padding: 12px 15px; width: 100%; margin-bottom: 20px; border: 1px solid #475569; border-radius: 6px; background: #0f172a; color: white; font-family: 'Inter', sans-serif; font-size: 16px; }
            input[type="password"]:focus { outline: none; border-color: #3b82f6; }
            button { background: #3b82f6; color: white; border: none; padding: 12px 20px; border-radius: 6px; cursor: pointer; font-weight: 600; width: 100%; font-size: 16px; transition: 0.2s; }
            button:hover { background: #2563eb; }
            .error { background: rgba(239,68,68,0.1); color: #ef4444; padding: 10px; border-radius: 6px; margin-bottom: 20px; font-size: 14px; border: 1px solid rgba(239,68,68,0.2); }
        </style>
    </head>
    <body>
        <div class="login-box">
            <h2>Admin Login</h2>
            <?php if(isset($error)) echo "<div class='error'>$error</div>"; ?>
            <form method="POST">
                <input type="password" name="password" placeholder="Masukkan Password" required>
                <button type="submit" name="login">Masuk</button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$clients = [];
if (is_dir($base_dir)) {
    $dirs = scandir($base_dir);
    foreach ($dirs as $d) {
        if ($d !== '.' && $d !== '..' && is_dir($base_dir . $d)) {
            $dl = strtolower($d);
            if (strpos($dl, 'settings') !== false || strpos($dl, 'template') !== false) continue;
            $clients[] = $d;
        }
    }
}
rsort($clients);

$selected_client = isset($_GET['client']) ? trim(str_replace(['..', '/', '\\', "\0"], '', $_GET['client'])) : '';

if (!empty($selected_client) && !is_dir($base_dir . $selected_client) && is_dir($base_dir)) {
    $req_clean = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $selected_client));
    foreach ($clients as $dir) {
        $dir_clean = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $dir));
        if ($req_clean !== '' && $req_clean === $dir_clean) { $selected_client = $dir; break; }
    }
}

function get_client_config($config_path) {
    if (file_exists($config_path)) return json_decode(file_get_contents($config_path), true) ?: [];
    return [];
}
function save_client_config($config_path, $data) {
    file_put_contents($config_path, json_encode($data, JSON_PRETTY_PRINT));
}

if (isset($_POST['update_settings']) && !empty($selected_client)) {
    $config_path = $base_dir . $selected_client . '/config.json';
    $cfg = get_client_config($config_path);
    $cfg['title'] = trim($_POST['custom_title']);
    $cfg['subtitle'] = trim($_POST['custom_subtitle']);
    $cfg['tab_title'] = isset($_POST['custom_tab_title']) ? trim($_POST['custom_tab_title']) : '';
    $cfg['visible_folders'] = isset($_POST['visible_folders']) ? $_POST['visible_folders'] : [];
    $cfg['upload_buffer_seconds'] = isset($_POST['upload_buffer_seconds']) ? max(3, (int)$_POST['upload_buffer_seconds']) : 15;
    save_client_config($config_path, $cfg);
    $success_msg = "Pengaturan berhasil diperbarui!";
}

if (isset($_POST['upload_manual']) && !empty($selected_client)) {
    $target_folder = isset($_POST['upload_target']) ? $_POST['upload_target'] : 'Originals';
    if (strpos($target_folder, '..') === false) {
        $upload_dir = $base_dir . $selected_client . '/' . $target_folder . '/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        $upload_count = 0;
        if (isset($_FILES['new_files']['name']) && is_array($_FILES['new_files']['name'])) {
            for ($i = 0; $i < count($_FILES['new_files']['name']); $i++) {
                if ($_FILES['new_files']['error'][$i] === UPLOAD_ERR_OK) {
                    $name = preg_replace('/[^a-zA-Z0-9_.\-& ]/', '', basename($_FILES['new_files']['name'][$i]));
                    if (move_uploaded_file($_FILES['new_files']['tmp_name'][$i], $upload_dir . $name)) $upload_count++;
                }
            }
            $success_msg = $upload_count > 0 ? "$upload_count file berhasil diupload ke $target_folder!" : "Gagal upload.";
        }
    }
}

if (isset($_POST['delete_file']) && !empty($selected_client)) {
    $fn = $_POST['filename'];
    if (strpos($fn, '..') === false) {
        $fp = $base_dir . $selected_client . '/' . $fn;
        if (file_exists($fp) && !is_dir($fp)) { unlink($fp); $success_msg = "File berhasil dihapus!"; }
    }
}

if (isset($_POST['delete_session']) && !empty($selected_client)) {
    $ftd = json_decode($_POST['session_files'], true);
    if (is_array($ftd)) {
        foreach ($ftd as $fn) {
            if (strpos($fn, '..') === false) { $fp = $base_dir . $selected_client . '/' . $fn; if (file_exists($fp) && !is_dir($fp)) unlink($fp); }
        }
        $success_msg = "Sesi berhasil dihapus!";
    }
}

if (isset($_POST['delete_all_files']) && !empty($selected_client)) {
    if (is_dir($base_dir . $selected_client)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base_dir . $selected_client), RecursiveIteratorIterator::CHILD_FIRST);
        $cnt = 0;
        foreach ($it as $file) {
            if ($file->isFile() && in_array(strtolower($file->getExtension()), ['jpg','jpeg','png','gif','mp4'])) { unlink($file->getPathname()); $cnt++; }
        }
        $success_msg = "Berhasil menghapus $cnt file media!";
    }
}

if (isset($_POST['bulk_delete']) && !empty($selected_client)) {
    if (isset($_POST['bulk_files']) && is_array($_POST['bulk_files'])) {
        $cnt = 0;
        foreach ($_POST['bulk_files'] as $fn) {
            if (strpos($fn, '..') === false) { $fp = $base_dir . $selected_client . '/' . $fn; if (file_exists($fp) && !is_dir($fp)) { unlink($fp); $cnt++; } }
        }
        $success_msg = "Berhasil menghapus $cnt file!";
    }
}

if (isset($_POST['bulk_hide']) && !empty($selected_client)) {
    if (isset($_POST['bulk_files']) && is_array($_POST['bulk_files'])) {
        $cp = $base_dir . $selected_client . '/config.json';
        $cfg = get_client_config($cp);
        $hidden = isset($cfg['hidden_files']) ? $cfg['hidden_files'] : [];
        foreach ($_POST['bulk_files'] as $f) { if (!in_array($f, $hidden)) $hidden[] = $f; }
        $cfg['hidden_files'] = array_values($hidden);
        save_client_config($cp, $cfg);
        $success_msg = "Berhasil menyembunyikan " . count($_POST['bulk_files']) . " file!";
    }
}

if (isset($_POST['bulk_unhide']) && !empty($selected_client)) {
    if (isset($_POST['bulk_files']) && is_array($_POST['bulk_files'])) {
        $cp = $base_dir . $selected_client . '/config.json';
        $cfg = get_client_config($cp);
        $hidden = isset($cfg['hidden_files']) ? $cfg['hidden_files'] : [];
        $hidden = array_diff($hidden, $_POST['bulk_files']);
        $cfg['hidden_files'] = array_values($hidden);
        save_client_config($cp, $cfg);
        $success_msg = "Berhasil menampilkan kembali " . count($_POST['bulk_files']) . " file!";
    }
}

if (isset($_POST['toggle_hide_file']) && !empty($selected_client)) {
    $fn = $_POST['filename'];
    $cp = $base_dir . $selected_client . '/config.json';
    $cfg = get_client_config($cp);
    $hidden = isset($cfg['hidden_files']) ? $cfg['hidden_files'] : [];
    if (in_array($fn, $hidden)) { $hidden = array_diff($hidden, [$fn]); $msg = "Foto ditampilkan kembali!"; }
    else { $hidden[] = $fn; $msg = "Foto disembunyikan!"; }
    $cfg['hidden_files'] = array_values($hidden);
    save_client_config($cp, $cfg);
    $success_msg = $msg;
}

$current_title = $selected_client;
$current_subtitle = "Terima kasih telah merayakan bersama kami!";
$current_tab_title = "Galeri - " . $selected_client;
$current_visible = ['Prints', 'Singles', 'Animated', 'Videos'];
$current_hidden = [];
$current_upload_buffer = 15;

if (!empty($selected_client)) {
    $cp = $base_dir . $selected_client . '/config.json';
    if (file_exists($cp)) {
        $json = json_decode(file_get_contents($cp), true);
        if (!empty($json['title'])) $current_title = $json['title'];
        if (!empty(trim($json['subtitle'] ?? ''))) $current_subtitle = $json['subtitle'];
        if (!empty(trim($json['tab_title'] ?? ''))) $current_tab_title = $json['tab_title'];
        if (isset($json['visible_folders'])) $current_visible = $json['visible_folders'];
        if (isset($json['hidden_files'])) $current_hidden = $json['hidden_files'];
        if (isset($json['upload_buffer_seconds'])) $current_upload_buffer = (int)$json['upload_buffer_seconds'];
    }
}

$files_data = [];
if (!empty($selected_client) && is_dir($base_dir . $selected_client)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base_dir . $selected_client));
    foreach ($it as $file) {
        if ($file->isFile()) {
            $ext = strtolower($file->getExtension());
            if (in_array($ext, ['jpg','jpeg','png','gif','mp4'])) {
                $path = str_replace('\\', '/', $file->getPathname());
                $rel = str_replace($base_dir . $selected_client . '/', '', $path);
                $parts = explode('/', $rel);
                $pf = count($parts) > 1 ? $parts[0] : '';
                $rl = strtolower($rel);
                if (strpos($rl,'thumb')!==false||strpos($rl,'settings')!==false||strpos($rl,'template')!==false) continue;
                if (strtolower($pf)==='animated' && in_array($ext,['jpg','jpeg','png'])) continue;
                $ep = implode('/', array_map('rawurlencode', explode('/', $path)));
                $files_data[] = ['file'=>$rel,'path'=>$ep,'ext'=>$ext,'time'=>$file->getMTime()];
            }
        }
    }
}

$sessions_map = []; $anchor_ts = [];
$exTs = function($fn, $fb) {
    if (preg_match('/^(\d{4})(\d{2})(\d{2})_(\d{2})(\d{2})(\d{2})/', $fn, $m)) return strtotime("{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:{$m[6]}");
    return $fb;
};

foreach ($files_data as $d) {
    $pl = strtolower($d['file']); $fn = basename($d['file']);
    if (strpos($pl,'prints/')!==false||strpos($pl,'animated/')!==false||strpos($pl,'videos/')!==false||in_array($d['ext'],['mp4','gif'])) {
        $bid = preg_match('/^(\d{8}_\d{6}_\d{3})/',$fn,$m)?$m[1]:preg_replace('/(_mp4|_gif|\.[a-zA-Z0-9]+)$/i','',$fn);
        if (!isset($sessions_map[$bid])) { $ts=$exTs($fn,$d['time']); $sessions_map[$bid]=['id'=>'s_'.$bid,'time'=>$ts,'files'=>[]]; $anchor_ts[$bid]=$ts; }
    }
}
foreach ($files_data as $d) {
    $fn=basename($d['file']); $ts=$exTs($fn,$d['time']);
    $bid=preg_match('/^(\d{8}_\d{6}_\d{3})/',$fn,$m)?$m[1]:preg_replace('/(_mp4|_gif|\.[a-zA-Z0-9]+)$/i','',$fn);
    if (isset($sessions_map[$bid])) { $sessions_map[$bid]['files'][]=$d; if($ts>$sessions_map[$bid]['time'])$sessions_map[$bid]['time']=$ts; continue; }
    $best=null; $min=999999;
    foreach ($anchor_ts as $aid=>$ats) { $diff=abs($ats-$ts); if($diff<=45&&$diff<$min){$min=$diff;$best=$aid;} }
    if ($best!==null) { $sessions_map[$best]['files'][]=$d; if($ts>$sessions_map[$best]['time'])$sessions_map[$best]['time']=$ts; }
    else { if(!isset($sessions_map[$bid]))$sessions_map[$bid]=['id'=>'s_'.$bid,'time'=>$ts,'files'=>[$d]]; else{$sessions_map[$bid]['files'][]=$d;if($ts>$sessions_map[$bid]['time'])$sessions_map[$bid]['time']=$ts;} }
}
usort($sessions_map,function($a,$b){return $b['time']-$a['time'];});
foreach ($sessions_map as &$sess) {
    usort($sess['files'],function($a,$b){
        $gp=function($f){$pl=strtolower($f['file']);if(strpos($pl,'prints/')!==false)return 3;if(strpos($pl,'animated/')!==false||strpos($pl,'videos/')!==false||in_array($f['ext'],['mp4','gif']))return 2;return 1;};
        $pA=$gp($a);$pB=$gp($b);if($pA!==$pB)return $pB-$pA;return $a['time']-$b['time'];
    });
}
unset($sess);
$sessions = array_values($sessions_map);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard YUHU</title>
    <link rel="icon" href="https://yuhu.co.id/icon.png" type="image/png">
    <link rel="shortcut icon" href="https://yuhu.co.id/icon.png" type="image/png">
    <link rel="apple-touch-icon" href="https://yuhu.co.id/icon.png">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary:#3b82f6; --primary-hover:#2563eb;
            --sidebar-bg:#0f172a; --sidebar-text:#94a3b8;
            --bg:#f1f5f9; --card:#ffffff;
            --text:#334155; --border:#e2e8f0;
            --danger:#ef4444; --danger-h:#dc2626;
            --warn:#f59e0b; --ok:#10b981;
        }
        *{box-sizing:border-box;}
        body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);margin:0;display:flex;height:100vh;overflow:hidden;}

        /* SIDEBAR */
        .sidebar{width:280px;background:var(--sidebar-bg);color:var(--sidebar-text);display:flex;flex-direction:column;flex-shrink:0;height:100vh;transition:left 0.3s ease;}
        .sidebar-header{padding:30px 25px;font-size:22px;font-weight:800;color:white;letter-spacing:-0.5px;border-bottom:1px solid rgba(255,255,255,0.05);}
        .sidebar-header span{color:var(--primary);}
        .nav-label{padding:20px 25px 10px;font-size:12px;text-transform:uppercase;letter-spacing:1px;font-weight:600;color:#64748b;}
        .client-list{list-style:none;padding:0 15px;margin:0;overflow-y:auto;flex:1;}
        .client-list li{margin-bottom:5px;}
        .client-list a{display:block;padding:12px 15px;color:var(--sidebar-text);text-decoration:none;border-radius:8px;transition:0.2s;font-size:14px;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
        .client-list a:hover{background:rgba(255,255,255,0.05);color:white;}
        .client-list a.active{background:var(--primary);color:white;}
        .client-list a.home-btn{background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);margin-bottom:15px;}
        .client-list a.home-btn.active{background:var(--primary);border-color:var(--primary);}
        .sidebar-footer{padding:20px;border-top:1px solid rgba(255,255,255,0.05);}
        .btn-logout{display:block;text-align:center;background:rgba(239,68,68,0.1);color:var(--danger);text-decoration:none;padding:12px;border-radius:8px;font-weight:600;transition:0.2s;}
        .btn-logout:hover{background:var(--danger);color:white;}

        /* MAIN */
        .main-content{flex:1;padding:40px;overflow-y:auto;height:100vh;}
        .page-title{font-size:28px;font-weight:700;color:#0f172a;margin-top:0;margin-bottom:30px;}
        .card{background:var(--card);border-radius:16px;padding:30px;box-shadow:0 4px 6px -1px rgba(0,0,0,0.05);margin-bottom:30px;border:1px solid var(--border);}
        .card-header{margin-top:0;margin-bottom:25px;font-size:18px;color:#0f172a;border-bottom:1px solid var(--border);padding-bottom:15px;font-weight:600;}
        .form-group{margin-bottom:20px;}
        .form-group label{display:block;font-weight:600;margin-bottom:8px;font-size:14px;color:#475569;}
        .form-group input[type="text"]{width:100%;padding:12px 15px;border:1px solid #cbd5e1;border-radius:8px;font-family:'Inter',sans-serif;transition:0.2s;font-size:15px;}
        .form-group input[type="text"]:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px rgba(59,130,246,0.15);}
        .checkbox-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:15px;margin-top:10px;}
        .checkbox-item{background:#f8fafc;padding:12px 15px;border-radius:8px;border:1px solid #e2e8f0;display:flex;align-items:center;gap:10px;cursor:pointer;transition:0.2s;font-weight:500;font-size:14px;}
        .checkbox-item:hover{background:#f1f5f9;}
        .checkbox-item input{margin:0;width:18px;height:18px;accent-color:var(--primary);}
        .btn-save{background:var(--primary);color:white;border:none;padding:12px 25px;border-radius:8px;cursor:pointer;font-weight:600;transition:0.2s;font-size:15px;}
        .btn-save:hover{background:var(--primary-hover);transform:translateY(-1px);}
        .success{background:rgba(16,185,129,0.1);color:#047857;padding:15px 20px;border-radius:8px;margin-bottom:30px;font-weight:500;border:1px solid rgba(16,185,129,0.2);display:flex;align-items:center;gap:10px;}
        .link-box{background:rgba(59,130,246,0.05);border:1px solid rgba(59,130,246,0.2);padding:20px;border-radius:12px;margin-bottom:30px;}
        .link-box .label{font-weight:600;color:#1e40af;font-size:14px;margin-bottom:5px;}
        .link-box a{color:var(--primary);text-decoration:none;font-size:18px;font-weight:700;display:inline-block;margin-bottom:8px;word-break:break-all;}
        .link-box a:hover{text-decoration:underline;}
        .link-box small{color:#64748b;font-size:13px;}
        .drop-zone{border:2px dashed #cbd5e1;border-radius:8px;padding:15px;text-align:center;background:#f8fafc;transition:all 0.2s;position:relative;color:#64748b;font-size:14px;min-height:60px;display:flex;align-items:center;justify-content:center;}
        .drop-zone.dragover{border-color:var(--primary);background:#eff6ff;color:var(--primary);}
        .drop-zone input[type="file"]{position:absolute;top:0;left:0;width:100%;height:100%;opacity:0;cursor:pointer;}
        .session-block{background:#f8fafc;padding:25px;border-radius:12px;margin-bottom:30px;border:1px solid #e2e8f0;}
        .session-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:10px;}
        .session-header h4{margin:0;color:#0f172a;font-size:18px;}
        .session-header .meta{color:#64748b;font-size:14px;font-weight:400;margin-left:10px;}
        .file-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:20px;}
        .file-item{border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;position:relative;background:white;transition:0.2s;box-shadow:0 1px 3px rgba(0,0,0,0.05);}
        .file-item:hover{box-shadow:0 4px 6px -1px rgba(0,0,0,0.1);transform:translateY(-2px);}
        .file-item img,.file-item video{width:100%;height:180px;object-fit:cover;display:block;background:#f1f5f9;cursor:pointer;}
        .file-item .actions{padding:12px;text-align:center;border-top:1px solid #e2e8f0;}
        .file-folder-badge{position:absolute;top:10px;left:10px;background:rgba(15,23,42,0.75);color:white;padding:4px 8px;border-radius:6px;font-size:11px;font-weight:600;letter-spacing:0.5px;backdrop-filter:blur(4px);pointer-events:none;}
        .preview-modal{display:none;position:fixed;z-index:99999;top:0;left:0;width:100%;height:100%;background-color:rgba(15,23,42,0.92);backdrop-filter:blur(8px);align-items:center;justify-content:center;padding:20px;}
        .preview-modal.active{display:flex;animation:fadeIn 0.2s ease;}
        @keyframes fadeIn{from{opacity:0;}to{opacity:1;}}
        .preview-close{position:absolute;top:25px;right:35px;color:#f1f5f9;font-size:40px;font-weight:bold;cursor:pointer;transition:0.2s;z-index:100000;line-height:1;}
        .preview-close:hover{color:var(--danger);transform:scale(1.1);}
        .btn-delete{background:white;color:var(--danger);border:1px solid var(--danger);padding:8px 12px;border-radius:6px;cursor:pointer;font-size:13px;font-weight:600;width:100%;transition:0.2s;}
        .btn-delete:hover{background:var(--danger);color:white;}
        .btn-delete-session{background:var(--warn);color:white;border:none;padding:10px 15px;border-radius:6px;cursor:pointer;font-size:13px;font-weight:600;transition:0.2s;}
        .btn-delete-session:hover{background:#d97706;}
        .btn-delete-all{background:var(--danger);color:white;border:none;padding:10px 18px;border-radius:8px;cursor:pointer;font-size:14px;font-weight:700;transition:0.2s;}
        .btn-delete-all:hover{background:var(--danger-h);transform:translateY(-1px);}
        .bulk-checkbox-wrapper{position:absolute;top:10px;right:10px;z-index:15;cursor:pointer;}
        .bulk-check{width:22px;height:22px;accent-color:var(--danger);cursor:pointer;margin:0;box-shadow:0 2px 5px rgba(0,0,0,0.3);border-radius:4px;}
        .file-item:has(.bulk-check:checked){outline:3px solid var(--danger);outline-offset:-3px;background:#fef2f2;}
        .btn-select-all,.btn-select-session{background:#e2e8f0;color:#334155;border:none;padding:8px 14px;border-radius:6px;cursor:pointer;font-size:13px;font-weight:600;transition:0.2s;}
        .btn-select-all:hover,.btn-select-session:hover{background:#cbd5e1;}
        .btn-bulk-delete{background:var(--danger);color:white;border:none;padding:10px 16px;border-radius:8px;cursor:pointer;font-size:14px;font-weight:700;transition:0.2s;}
        .btn-bulk-delete:disabled{background:#cbd5e1;color:#94a3b8;cursor:not-allowed;}
        .btn-bulk-delete:not(:disabled):hover{background:var(--danger-h);transform:translateY(-1px);}
        .btn-bulk-hide{background:var(--warn);color:white;border:none;padding:10px 16px;border-radius:8px;cursor:pointer;font-size:14px;font-weight:700;transition:0.2s;}
        .btn-bulk-hide:disabled{background:#cbd5e1;color:#94a3b8;cursor:not-allowed;}
        .btn-bulk-hide:not(:disabled):hover{background:#d97706;transform:translateY(-1px);}
        .btn-bulk-unhide{background:var(--ok);color:white;border:none;padding:10px 16px;border-radius:8px;cursor:pointer;font-size:14px;font-weight:700;transition:0.2s;}
        .btn-bulk-unhide:disabled{background:#cbd5e1;color:#94a3b8;cursor:not-allowed;}
        .btn-bulk-unhide:not(:disabled):hover{background:#059669;transform:translateY(-1px);}
        .file-item.is-hidden{border:2px dashed var(--warn);background:#fffbeb;opacity:0.85;}
        .hidden-badge{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:rgba(245,158,11,0.95);color:white;padding:6px 14px;border-radius:20px;font-size:12px;font-weight:800;z-index:5;pointer-events:none;}
        .btn-hide{background:#fef3c7;color:#d97706;border:1px solid #f59e0b;padding:8px 12px;border-radius:6px;cursor:pointer;font-size:13px;font-weight:600;width:100%;transition:0.2s;margin-bottom:6px;}
        .btn-hide:hover{background:#f59e0b;color:white;}
        .btn-unhide{background:#d1fae5;color:#059669;border:1px solid #10b981;padding:8px 12px;border-radius:6px;cursor:pointer;font-size:13px;font-weight:600;width:100%;transition:0.2s;margin-bottom:6px;}
        .btn-unhide:hover{background:#10b981;color:white;}
        .stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:20px;margin-bottom:40px;}
        .stat-card{background:white;border-radius:16px;padding:25px;border:1px solid var(--border);display:flex;align-items:center;gap:20px;}
        .stat-icon{width:60px;height:60px;border-radius:12px;background:rgba(59,130,246,0.1);color:var(--primary);display:flex;align-items:center;justify-content:center;flex-shrink:0;}
        .stat-info h3{margin:0;font-size:32px;font-weight:700;color:#0f172a;}
        .stat-info p{margin:5px 0 0 0;color:#64748b;font-weight:500;font-size:14px;}
        .welcome-hero{background:linear-gradient(135deg,#1e293b 0%,#0f172a 100%);border-radius:16px;padding:40px;color:white;margin-bottom:30px;position:relative;overflow:hidden;}
        .welcome-hero h2{margin:0 0 10px 0;font-size:28px;}
        .welcome-hero p{margin:0;color:#94a3b8;font-size:16px;max-width:600px;line-height:1.5;}
        .welcome-hero::after{content:'';position:absolute;right:-50px;bottom:-50px;width:200px;height:200px;background:var(--primary);opacity:0.2;filter:blur(40px);border-radius:50%;}

        /* ===== HAMBURGER BUTTON ===== */
        .mobile-toggle{
            display:none;
            position:fixed;top:12px;left:12px;z-index:9999;
            background:var(--primary);color:white;border:none;
            border-radius:10px;width:44px;height:44px;font-size:22px;
            cursor:pointer;align-items:center;justify-content:center;
            box-shadow:0 4px 12px rgba(59,130,246,0.4);
        }

        /* ===== OVERLAY ===== */
        .sidebar-overlay{
            display:none;position:fixed;top:0;left:0;
            width:100%;height:100%;background:rgba(0,0,0,0.55);
            z-index:998;backdrop-filter:blur(2px);
        }
        .sidebar-overlay.open{display:block;}

        /* ===== MOBILE RESPONSIVE ===== */
        @media(max-width:768px){
            .mobile-toggle{display:flex;}
            .sidebar{
                position:fixed!important;left:-290px;top:0;
                height:100vh;z-index:999;width:270px;
                box-shadow:4px 0 20px rgba(0,0,0,0.4);
            }
            .sidebar.open{left:0;}
            .main-content{margin-left:0!important;padding:70px 15px 30px 15px!important;width:100%!important;}
            .page-title{font-size:20px;margin-bottom:20px;}
            .card{padding:18px;border-radius:12px;margin-bottom:20px;}
            .session-block{padding:15px;}
            .card-header{font-size:16px;margin-bottom:18px;}
            .link-box{padding:15px;}
            .link-box a{font-size:14px;}
            .file-grid{grid-template-columns:repeat(2,1fr)!important;gap:10px!important;}
            .file-item img,.file-item video{height:130px!important;}
            .file-item .actions{padding:8px;}
            .file-folder-badge{font-size:9px;padding:3px 6px;}
            .session-header{flex-direction:column;align-items:flex-start;}
            .session-header h4{font-size:15px;}
            .session-header>div:last-child{display:flex;flex-wrap:wrap;gap:6px;width:100%;}
            .session-header button{flex:1;min-width:110px;font-size:12px!important;padding:8px 10px!important;}
            .bulk-actions-row{flex-direction:column!important;gap:8px!important;}
            .bulk-actions-row>*{width:100%!important;}
            .btn-bulk-delete,.btn-bulk-hide,.btn-bulk-unhide,.btn-select-all,.btn-delete-all{width:100%!important;font-size:13px!important;padding:10px 12px!important;text-align:center!important;}
            .stats-grid{grid-template-columns:repeat(2,1fr);gap:12px;}
            .stat-card{padding:15px;flex-direction:column;align-items:flex-start;gap:10px;}
            .stat-icon{width:44px;height:44px;}
            .stat-info h3{font-size:24px;}
            .welcome-hero{padding:25px 20px;}
            .welcome-hero h2{font-size:20px;}
            .welcome-hero p{font-size:14px;}
            .upload-row{flex-direction:column!important;gap:12px!important;}
            .upload-row>div{min-width:unset!important;width:100%!important;}
            .upload-row .btn-save{width:100%!important;margin-top:0!important;}
            input[type="text"],input[type="number"],input[type="password"],select,textarea{font-size:16px!important;}
            .btn-save{width:100%;}
            .checkbox-grid{grid-template-columns:1fr 1fr;gap:10px;}
            .checkbox-item{padding:10px 12px;font-size:13px;}
            .preview-modal{padding:10px;}
            .preview-close{top:10px;right:15px;font-size:36px;}
        }
        @media(max-width:420px){
            .file-grid{grid-template-columns:repeat(2,1fr)!important;}
            .file-item img,.file-item video{height:110px!important;}
        }
    </style>
</head>
<body>

    <!-- Tombol Hamburger (Mobile Only) -->
    <button class="mobile-toggle" onclick="toggleSidebar()" title="Buka Menu">☰</button>
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">YUHU<span>Booth</span></div>
        <div class="nav-label">Menu Utama</div>
        <ul class="client-list">
            <li><a href="admin.php" class="home-btn <?php echo empty($selected_client)?'active':''; ?>" onclick="closeSidebarMobile()">📊 Dashboard Home</a></li>
        </ul>
        <div class="nav-label">Daftar Event (<?php echo count($clients); ?>)</div>
        <ul class="client-list">
            <?php foreach($clients as $cl): ?>
                <li><a href="?client=<?php echo urlencode($cl); ?>" class="<?php echo($selected_client===$cl)?'active':''; ?>" onclick="closeSidebarMobile()">📁 <?php echo htmlspecialchars($cl); ?></a></li>
            <?php endforeach; ?>
            <?php if(empty($clients)) echo "<li style='padding:10px 25px;color:#64748b;'>Belum ada event.</li>"; ?>
        </ul>
        <div class="sidebar-footer">
            <a href="?logout=1" class="btn-logout">Keluar (Logout)</a>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">

        <?php if(empty($selected_client)): ?>
            <h1 class="page-title">Dashboard Overview</h1>
            <div class="welcome-hero">
                <h2>Selamat Datang, Admin! 👋</h2>
                <p>Ini adalah pusat kendali YUHU PhotoBooth Gallery. Pilih event di menu kiri untuk mengatur galeri tamu.</p>
            </div>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg></div>
                    <div class="stat-info"><h3><?php echo count($clients); ?></h3><p>Total Event Aktif</p></div>
                </div>
                <div class="stat-card" style="background:rgba(59,130,246,0.05);border-color:rgba(59,130,246,0.2);">
                    <div class="stat-info" style="width:100%;">
                        <p style="color:#1e40af;margin-bottom:10px;font-weight:600;">Panduan Singkat:</p>
                        <ul style="margin:0;padding-left:20px;color:#334155;font-size:14px;line-height:1.8;">
                            <li>Pilih event di menu kiri (atau tekan ☰)</li>
                            <li>Atur judul & subtitle galeri tamu</li>
                            <li>Centang folder yang ingin ditampilkan</li>
                        </ul>
                    </div>
                </div>
            </div>

        <?php else: ?>
            <h1 class="page-title">Pengaturan Event</h1>

            <?php if(isset($success_msg)): ?>
                <div class="success">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                    <?php echo $success_msg; ?>
                </div>
            <?php endif; ?>

            <?php
                $proto=(isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https://':'http://';
                $pub_link=$proto.$_SERVER['HTTP_HOST'].rtrim(dirname($_SERVER['SCRIPT_NAME']),'/\\').'/'.rawurlencode($selected_client);
            ?>
            <div class="link-box">
                <div class="label">Link Publik Galeri Tamu:</div>
                <a href="<?php echo htmlspecialchars($pub_link); ?>" target="_blank"><?php echo htmlspecialchars($pub_link); ?></a>
                <br><small>(Bagikan link ini ke tamu, atau gunakan sebagai QR Code)</small>
            </div>

            <div class="card">
                <h3 class="card-header">Detail & Tampilan Galeri</h3>
                <form method="POST">
                    <div class="form-group">
                        <label>Judul Event</label>
                        <input type="text" name="custom_title" value="<?php echo htmlspecialchars($current_title); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Teks Subtitle</label>
                        <input type="text" name="custom_subtitle" value="<?php echo htmlspecialchars($current_subtitle); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Judul Tab Browser</label>
                        <input type="text" name="custom_tab_title" value="<?php echo htmlspecialchars($current_tab_title); ?>">
                        <small style="color:#64748b;font-size:13px;margin-top:4px;display:block;">Teks ini muncul di tab browser tamu.</small>
                    </div>
                    <div class="form-group">
                        <label>Jeda Stabilisasi Upload (Detik)</label>
                        <input type="number" name="upload_buffer_seconds" value="<?php echo $current_upload_buffer; ?>" min="3" max="120" style="width:100%;padding:12px 15px;border:1px solid #cbd5e1;border-radius:8px;font-family:'Inter',sans-serif;font-size:15px;">
                        <small style="color:#64748b;font-size:13px;margin-top:4px;display:block;">Waktu tunggu agar foto siap sebelum tayang ke tamu.</small>
                    </div>
                    <div class="form-group" style="margin-top:25px;">
                        <label>Folder Media yang Diizinkan Ditampilkan ke Tamu</label>
                        <div class="checkbox-grid">
                            <?php foreach($all_possible_folders as $fn): ?>
                                <label class="checkbox-item">
                                    <input type="checkbox" name="visible_folders[]" value="<?php echo $fn; ?>" <?php if(in_array($fn,$current_visible))echo 'checked'; ?>>
                                    <?php echo $fn; ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div style="margin-top:25px;padding-top:20px;border-top:1px solid var(--border);">
                        <button type="submit" name="update_settings" class="btn-save">Simpan Perubahan</button>
                    </div>
                </form>
            </div>

            <div class="card">
                <h3 class="card-header" style="margin-top:0;">Upload File Media Baru</h3>
                <form method="POST" enctype="multipart/form-data">
                    <div class="upload-row" style="display:flex;gap:15px;align-items:flex-start;flex-wrap:wrap;">
                        <div style="flex:1;min-width:220px;">
                            <label style="display:block;font-size:14px;font-weight:600;color:#475569;margin-bottom:8px;">Pilih File (Bisa lebih dari satu)</label>
                            <div class="drop-zone" id="dropZone">
                                <span id="dropText">Tarik & Lepas file ke sini, atau klik untuk memilih</span>
                                <input type="file" name="new_files[]" id="fileInput" multiple required>
                            </div>
                        </div>
                        <div style="flex:1;min-width:160px;">
                            <label style="display:block;font-size:14px;font-weight:600;color:#475569;margin-bottom:8px;">Target Folder</label>
                            <select name="upload_target" style="width:100%;padding:10px 15px;border:1px solid #cbd5e1;border-radius:6px;font-size:15px;">
                                <option value="Originals">Originals</option>
                                <option value="Singles">Singles</option>
                                <option value="Prints">Prints</option>
                                <option value="Animated">Animated</option>
                            </select>
                        </div>
                        <div style="display:flex;align-items:flex-end;">
                            <button type="submit" name="upload_manual" class="btn-save" style="padding:11px 20px;white-space:nowrap;">📤 Upload</button>
                        </div>
                    </div>
                </form>
            </div>

            <div class="card">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:15px;margin-bottom:25px;">
                    <div style="display:flex;align-items:center;gap:15px;flex-wrap:wrap;">
                        <h3 class="card-header" style="margin:0;border:none;padding:0;">Manajemen File Media</h3>
                        <div style="font-weight:600;color:var(--primary);background:rgba(59,130,246,0.1);padding:5px 12px;border-radius:20px;font-size:14px;"><?php echo count($sessions); ?> Sesi</div>
                    </div>
                    <?php if(!empty($sessions)): ?>
                    <div class="bulk-actions-row" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                        <button type="button" onclick="toggleSelectAll()" class="btn-select-all" id="btnSelectAll">☑️ Pilih Semua</button>
                        <button type="submit" form="bulkDeleteForm" name="bulk_hide" class="btn-bulk-hide" id="btnBulkHide" disabled>🙈 Sembunyikan (0)</button>
                        <button type="submit" form="bulkDeleteForm" name="bulk_unhide" class="btn-bulk-unhide" id="btnBulkUnhide" disabled>👁️ Tampilkan (0)</button>
                        <button type="submit" form="bulkDeleteForm" name="bulk_delete" class="btn-bulk-delete" id="btnBulkDelete" disabled onclick="return confirm('Yakin hapus media yang dicentang?');">🗑️ Hapus (0)</button>
                        <form method="POST" onsubmit="return confirm('PERINGATAN: Hapus SEMUA media event ini PERMANEN?');" style="margin:0;width:100%;">
                            <button type="submit" name="delete_all_files" class="btn-delete-all">⚠️ Hapus Semua Media Event Ini</button>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>

                <p style="color:#64748b;font-size:14px;margin-bottom:25px;line-height:1.5;background:#f8fafc;padding:15px;border-radius:8px;border-left:4px solid var(--primary);">
                    <strong>Mode Admin:</strong> Centang foto lalu klik <strong>🙈 Sembunyikan</strong> agar tidak tampil ke tamu, atau <strong>🗑️ Hapus</strong> untuk hapus permanen.
                </p>

                <form method="POST" id="bulkDeleteForm">
                    <?php foreach($sessions as $idx=>$sess): ?>
                    <div class="session-block">
                        <div class="session-header">
                            <h4>Sesi <?php echo($idx+1); ?> <span class="meta">(<?php echo count($sess['files']); ?> media)</span></h4>
                            <div>
                                <button type="button" class="btn-select-session" onclick="toggleSelectSession(<?php echo $idx; ?>)">☑️ Pilih Sesi</button>
                                <button type="submit" form="fds_<?php echo $idx; ?>" name="delete_session" class="btn-delete-session">Hapus Sesi Ini</button>
                            </div>
                        </div>
                        <div class="file-grid">
                            <?php foreach($sess['files'] as $fd): ?>
                                <?php $ih=in_array($fd['file'],$current_hidden); ?>
                                <div class="file-item <?php if($ih)echo 'is-hidden'; ?>">
                                    <label class="bulk-checkbox-wrapper">
                                        <input type="checkbox" name="bulk_files[]" value="<?php echo htmlspecialchars($fd['file']); ?>" class="bulk-check sc_<?php echo $idx; ?>" onchange="updateBulkUI()">
                                    </label>
                                    <div class="file-folder-badge"><?php echo explode('/',$fd['file'])[0]; ?></div>
                                    <?php if($ih):?><div class="hidden-badge">🙈 DISEMBUNYIKAN</div><?php endif;?>
                                    <?php if($fd['ext']==='mp4'):?>
                                        <video src="<?php echo $fd['path'];?>" muted style="<?php if($ih)echo 'opacity:0.5;filter:grayscale(100%);';?>" onclick="openAdminPreview('<?php echo $fd['path'];?>','mp4')"></video>
                                    <?php else:?>
                                        <img src="<?php echo $fd['path'];?>" alt="Media" loading="lazy" style="<?php if($ih)echo 'opacity:0.5;filter:grayscale(100%);';?>" onclick="openAdminPreview('<?php echo $fd['path'];?>','image')">
                                    <?php endif;?>
                                    <div class="actions">
                                        <button type="submit" form="fth_<?php echo md5($fd['file']);?>" name="toggle_hide_file" class="<?php echo $ih?'btn-unhide':'btn-hide';?>">
                                            <?php echo $ih?'👁️ Tampilkan':'🙈 Sembunyikan';?>
                                        </button>
                                        <button type="submit" form="fdf_<?php echo md5($fd['file']);?>" name="delete_file" class="btn-delete">Hapus File</button>
                                    </div>
                                </div>
                            <?php endforeach;?>
                        </div>
                    </div>
                    <?php endforeach;?>
                </form>

                <?php foreach($sessions as $idx=>$sess):?>
                    <form method="POST" id="fds_<?php echo $idx;?>" onsubmit="return confirm('Yakin hapus SELURUH file Sesi <?php echo($idx+1);?> ini PERMANEN?');">
                        <input type="hidden" name="session_files" value="<?php echo htmlspecialchars(json_encode(array_map(function($f){return $f['file'];},$sess['files'])));?>">
                    </form>
                    <?php foreach($sess['files'] as $fd):?>
                        <form method="POST" id="fdf_<?php echo md5($fd['file']);?>" onsubmit="return confirm('Yakin hapus media ini?');">
                            <input type="hidden" name="filename" value="<?php echo htmlspecialchars($fd['file']);?>">
                        </form>
                        <form method="POST" id="fth_<?php echo md5($fd['file']);?>">
                            <input type="hidden" name="filename" value="<?php echo htmlspecialchars($fd['file']);?>">
                        </form>
                    <?php endforeach;?>
                <?php endforeach;?>

                <?php if(empty($sessions)):?>
                    <div style="text-align:center;padding:40px 20px;color:#94a3b8;">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom:15px;opacity:0.5;"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
                        <p>Belum ada media untuk event ini.</p>
                    </div>
                <?php endif;?>
            </div>
        <?php endif;?>
    </div>

    <script>
    function toggleSidebar(){
        const s=document.getElementById('sidebar'),o=document.getElementById('sidebarOverlay');
        s.classList.toggle('open');o.classList.toggle('open');
        document.body.style.overflow=s.classList.contains('open')?'hidden':'';
    }
    function closeSidebarMobile(){
        if(window.innerWidth<=768){
            document.getElementById('sidebar').classList.remove('open');
            document.getElementById('sidebarOverlay').classList.remove('open');
            document.body.style.overflow='';
        }
    }
    function updateBulkUI(){
        const checks=document.querySelectorAll('.bulk-check'),checked=document.querySelectorAll('.bulk-check:checked'),n=checked.length;
        const b1=document.getElementById('btnBulkDelete');if(b1){b1.innerHTML='🗑️ Hapus ('+n+')';b1.disabled=n===0;}
        const b2=document.getElementById('btnBulkHide');if(b2){b2.innerHTML='🙈 Sembunyikan ('+n+')';b2.disabled=n===0;}
        const b3=document.getElementById('btnBulkUnhide');if(b3){b3.innerHTML='👁️ Tampilkan ('+n+')';b3.disabled=n===0;}
        const b4=document.getElementById('btnSelectAll');if(b4&&checks.length>0)b4.innerHTML=n===checks.length?'☑️ Batalkan Pilih Semua':'☑️ Pilih Semua';
    }
    function toggleSelectAll(){
        const checks=document.querySelectorAll('.bulk-check'),checked=document.querySelectorAll('.bulk-check:checked');
        const sel=checked.length!==checks.length;checks.forEach(c=>c.checked=sel);updateBulkUI();
    }
    function toggleSelectSession(i){
        const checks=document.querySelectorAll('.sc_'+i),checked=document.querySelectorAll('.sc_'+i+':checked');
        const sel=checked.length!==checks.length;checks.forEach(c=>c.checked=sel);updateBulkUI();
    }
    function openAdminPreview(path,type){
        const modal=document.getElementById('adminPreviewModal'),cont=document.getElementById('previewContainer');
        cont.innerHTML=type==='mp4'
            ?`<video src="${path}" controls autoplay style="max-width:100%;max-height:88vh;border-radius:12px;box-shadow:0 20px 50px rgba(0,0,0,0.5);"></video>`
            :`<img src="${path}" style="max-width:100%;max-height:88vh;border-radius:12px;box-shadow:0 20px 50px rgba(0,0,0,0.5);">`;
        modal.classList.add('active');
    }
    function closeAdminPreview(e){
        if(!e||e.target.id==='adminPreviewModal'||e.target.classList.contains('preview-close')){
            document.getElementById('adminPreviewModal').classList.remove('active');
            document.getElementById('previewContainer').innerHTML='';
        }
    }
    const dz=document.getElementById('dropZone'),fi=document.getElementById('fileInput'),dt=document.getElementById('dropText');
    if(dz&&fi){
        ['dragenter','dragover','dragleave','drop'].forEach(ev=>{
            dz.addEventListener(ev,e=>{e.preventDefault();e.stopPropagation();},false);
            document.body.addEventListener(ev,e=>{e.preventDefault();e.stopPropagation();},false);
        });
        ['dragenter','dragover'].forEach(ev=>dz.addEventListener(ev,()=>dz.classList.add('dragover'),false));
        ['dragleave','drop'].forEach(ev=>dz.addEventListener(ev,()=>dz.classList.remove('dragover'),false));
        dz.addEventListener('drop',e=>{const f=e.dataTransfer.files;if(f.length>0){fi.files=f;dt.textContent=f.length+' file dipilih';dt.style.fontWeight='bold';dt.style.color='var(--primary)';}});
        fi.addEventListener('change',function(){
            if(this.files.length>0){dt.textContent=this.files.length+' file dipilih';dt.style.fontWeight='bold';dt.style.color='var(--primary)';}
            else{dt.textContent='Tarik & Lepas file ke sini, atau klik untuk memilih';dt.style.fontWeight='normal';dt.style.color='#64748b';}
        });
    }
    </script>

    <div id="adminPreviewModal" class="preview-modal" onclick="closeAdminPreview(event)">
        <span class="preview-close" onclick="closeAdminPreview()">&times;</span>
        <div id="previewContainer" style="display:flex;align-items:center;justify-content:center;max-width:90%;max-height:90vh;"></div>
    </div>
</body>
</html>