@echo off
setlocal enabledelayedexpansion
title YUHU PHOTOBOOTH - EMERGENCY RECOVERY

echo =====================================================================
echo   YUHU PHOTOBOOTH - EMERGENCY RECOVERY DAN FAST SYNC
echo =====================================================================
echo [*] Menghentikan proses curl lama dan membersihkan antrean nyangkut...
taskkill /F /IM curl.exe >nul 2>&1
del /f /q "%~dp0*.lock" "%~dp0active_batch.txt" "%~dp0active_priority.txt" "%~dp0upload_queue.txt" "%~dp0raw_scan.tmp" >nul 2>&1

powershell -NoProfile -ExecutionPolicy Bypass -Command ^
    "$apiUrl = 'https://files.yuhu.co.id/client/api_upload.php';" ^
    "$token = 'YuhuSecretToken2026';" ^
    "$curl = 'curl.exe';" ^
    "Write-Host '[*] Menghubungkan ke Server files.yuhu.co.id...' -ForegroundColor Cyan;" ^
    "$candidates = @('C:\dslrBooth', 'D:\dslrBooth', \"$env:USERPROFILE\Pictures\dslrBooth\", \"$env:USERPROFILE\Pictures\LumaBooth\", \"$env:USERPROFILE\OneDrive\Pictures\dslrBooth\", \"$env:USERPROFILE\OneDrive\Pictures\LumaBooth\", 'C:\LumaBooth');" ^
    "$dslrDir = $null;" ^
    "foreach ($d in $candidates) { if (Test-Path $d) { $dslrDir = $d; break } }" ^
    "if (-not $dslrDir) { Write-Host '[!] Folder data LumaBooth tidak ditemukan!' -ForegroundColor Red; pause; exit 1 };" ^
    "Write-Host \"[V] Folder LumaBooth: $dslrDir\" -ForegroundColor Green;" ^
    "$projects = Get-ChildItem -Directory $dslrDir | Where-Object { $_.Name -notin @('Settings', 'Templates') } | Sort-Object LastWriteTime -Descending;" ^
    "if ($projects.Count -eq 0) { Write-Host '[!] Tidak ada folder project!' -ForegroundColor Yellow; pause; exit 0 };" ^
    "$activeProj = $projects[0];" ^
    "Write-Host \"[V] Project Aktif: $($activeProj.Name)\" -ForegroundColor Yellow;" ^
    "$files = @();" ^
    "$pDir = Join-Path $activeProj.FullName 'Prints';" ^
    "$aDir = Join-Path $activeProj.FullName 'Animated';" ^
    "if (Test-Path $pDir) { Get-ChildItem -File $pDir | Where-Object { $_.Extension -match '\.(jpg|jpeg|png)$' -and $_.Name -notmatch '_mp4\.jpg$' } | ForEach-Object { $files += [PSCustomObject]@{ File=$_; Target='Prints'; Event=$activeProj.Name } } };" ^
    "if (Test-Path $aDir) { Get-ChildItem -File $aDir | Where-Object { $_.Extension -match '\.(mp4|mov|webm|gif)$' -and $_.Name -notmatch '_mp4\.jpg$' } | ForEach-Object { $files += [PSCustomObject]@{ File=$_; Target='Animated'; Event=$activeProj.Name } } };" ^
    "Write-Host \"[*] Memulai upload $($files.Count) media...\" -ForegroundColor Cyan;" ^
    "$idx = 0; $sukses = 0; $gagal = 0;" ^
    "foreach ($item in $files) {" ^
    "    $idx++; $f = $item.File; $tgt = $item.Target; $ev = $item.Event;" ^
    "    Write-Host \"[$idx/$($files.Count)] Mengirim: $($f.Name) ($tgt)... \" -NoNewline;" ^
    "    $args = @('-4', '--ssl-no-revoke', '--http1.1', '-H', 'Expect:', '--fail', '--max-time', '35', '--connect-timeout', '10', '-s', '-k', '-X', 'POST', $apiUrl, '-F', \"token=$token\", '-F', \"client_event=$ev\", '-F', \"folder_target=$tgt\", '-F', \"file=@$($f.FullName)\");" ^
    "    $res = & $curl $args;" ^
    "    if ($LASTEXITCODE -eq 0) { Write-Host 'SUKSES' -ForegroundColor Green; $sukses++ } else { Write-Host \"GAGAL (Code: $LASTEXITCODE)\" -ForegroundColor Red; $gagal++ };" ^
    "};" ^
    "Write-Host '=================================================' -ForegroundColor Cyan;" ^
    "Write-Host \"SELESAI! Sukses: $sukses, Gagal: $gagal\" -ForegroundColor Green;" ^
    "Write-Host '=================================================' -ForegroundColor Cyan;" ^
    "Write-Host 'Memantau foto baru (tekan Ctrl+C untuk berhenti)...' -ForegroundColor Gray;" ^
    "while ($true) {" ^
    "    Start-Sleep -Seconds 3;" ^
    "    $newFiles = @();" ^
    "    if (Test-Path $pDir) { Get-ChildItem -File $pDir | Where-Object { (Get-Date) - $_.LastWriteTime -lt (New-TimeSpan -Minutes 2) -and $_.Extension -match '\.(jpg|jpeg|png)$' -and $_.Name -notmatch '_mp4\.jpg$' } | ForEach-Object { $newFiles += [PSCustomObject]@{ File=$_; Target='Prints'; Event=$activeProj.Name } } };" ^
    "    if (Test-Path $aDir) { Get-ChildItem -File $aDir | Where-Object { (Get-Date) - $_.LastWriteTime -lt (New-TimeSpan -Minutes 2) -and $_.Extension -match '\.(mp4|mov|webm|gif)$' -and $_.Name -notmatch '_mp4\.jpg$' } | ForEach-Object { $newFiles += [PSCustomObject]@{ File=$_; Target='Animated'; Event=$activeProj.Name } } };" ^
    "    foreach ($nf in $newFiles) {" ^
    "        $f = $nf.File; $tgt = $nf.Target; $ev = $nf.Event;" ^
    "        $args = @('-4', '--ssl-no-revoke', '--http1.1', '-H', 'Expect:', '--fail', '--max-time', '35', '--connect-timeout', '10', '-s', '-k', '-X', 'POST', $apiUrl, '-F', \"token=$token\", '-F', \"client_event=$ev\", '-F', \"folder_target=$tgt\", '-F', \"file=@$($f.FullName)\");" ^
    "        $res = & $curl $args;" ^
    "        if ($LASTEXITCODE -eq 0) { Write-Host \"[REALTIME] Berhasil upload: $($f.Name)\" -ForegroundColor Green };" ^
    "    };" ^
    "};"
