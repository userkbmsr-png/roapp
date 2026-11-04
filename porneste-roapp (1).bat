@echo off
rem ============================================================
rem  roAdmin - pornire locala (Windows)
rem  Pune acest fisier in folderul roapp\ (langa folderul public\)
rem    porneste-roapp.bat         -> verifica PHP, porneste serverul, deschide browserul
rem    porneste-roapp.bat stop    -> opreste serverul
rem  Setarile (port, ascuns/minimizat, URL PHP) sunt in sectiunea "Setari" de mai jos.
rem ============================================================
setlocal
set "ROAPP_DIR=%~dp0"
set "ROAPP_ARG=%~1"
powershell -NoProfile -ExecutionPolicy Bypass -Command "$t=[IO.File]::ReadAllText('%~f0',[Text.Encoding]::UTF8);$m='#PS_'+'START';iex $t.Substring($t.IndexOf($m)+$m.Length)"
exit /b %errorlevel%

#PS_START
# ===== Setari =====
$Addr          = '127.0.0.1'
$Port          = 8080
$WindowStyle   = 'Hidden'      # 'Hidden' = ascuns, 'Minimized' = minimizat in bara de activitati
$DownloadUrl   = 'https://downloads.php.net/~windows/releases/archives/php-8.5.12-1-nts-Win32-vs17-x64.zip'
$InstallDir    = 'C:\php'
$Required      = @('dom','zip')   # lipsa lor = avertisment, pornirea continua
$Optional      = @('mbstring')    # folosita de aplicatie (mb_*); se activeaza daca lipseste
$VcRedistUrl   = 'https://aka.ms/vs/17/release/vc_redist.x64.exe'
$MinVcVersion  = [version]'14.40' # versiune minima estimata pentru VC++ 2015-2022 x64; ajusteaza daca e nevoie
# ==================

$ErrorActionPreference = 'Continue'
$Root   = $env:ROAPP_DIR.TrimEnd('\')
$Public = Join-Path $Root 'public'
$Arg    = $env:ROAPP_ARG
$Warnings = @()

function Say([string]$m, [string]$c = 'Gray') { Write-Host $m -ForegroundColor $c }

function Get-Listener {
    Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue | Select-Object -First 1
}

function Test-FileReady([string]$p) {
    try { $fs = [IO.File]::Open($p, 'Open', 'Read', 'None'); $fs.Close(); return $true } catch { return $false }
}

function Get-DownloadsDir {
    try {
        $d = (New-Object -ComObject Shell.Application).Namespace('shell:Downloads').Self.Path
        if ($d -and (Test-Path $d)) { return $d }
    } catch { }
    return (Join-Path $env:USERPROFILE 'Downloads')
}

function Get-Modules {
    @(& $php -m 2>$null | ForEach-Object { "$_".Trim().ToLower() })
}

function Get-VcProblem {
    # Returneaza textul problemei sau $null daca Visual C++ Redistributable pare in regula
    $sys = Join-Path $env:WINDIR 'System32'
    $found = $null
    foreach ($f in @('vcruntime140.dll', 'vcruntime140_1.dll', 'msvcp140.dll')) {
        $p = Join-Path $sys $f
        if (-not (Test-Path $p)) { return "lipseste $f" }
        $vi = (Get-Item $p).VersionInfo
        $v = [version]('{0}.{1}.{2}' -f $vi.FileMajorPart, $vi.FileMinorPart, $vi.FileBuildPart)
        if ($f -eq 'msvcp140.dll') { $found = $v }
        if ($v -lt $MinVcVersion) { return "$f are versiunea $v (minim necesar: $MinVcVersion)" }
    }
    return $null
}

try {
    if (-not (Test-Path (Join-Path $Public 'index.php'))) {
        throw "Nu gasesc public\index.php. Pune acest fisier in folderul roapp (langa folderul public)."
    }

    # ---------- STOP ----------
    if ($Arg -eq 'stop') {
        $l = Get-Listener
        if ($l -and ((Get-Process -Id $l.OwningProcess -ErrorAction SilentlyContinue).ProcessName -eq 'php')) {
            Stop-Process -Id $l.OwningProcess -Force
            Say "Serverul de pe portul $Port a fost oprit." 'Green'
        } else {
            Say "Nu ruleaza niciun server PHP pe portul $Port." 'Yellow'
        }
        Start-Sleep 2
        exit 0
    }

    # ---------- 1. Cauta PHP ----------
    $m = [Environment]::GetEnvironmentVariable('Path', 'Machine')
    $u = [Environment]::GetEnvironmentVariable('Path', 'User')
    $env:Path = (@($m, $u) | Where-Object { $_ }) -join ';'

    $php = $null
    $cmd = Get-Command php.exe -ErrorAction SilentlyContinue
    if ($cmd) { $php = $cmd.Source }
    elseif (Test-Path (Join-Path $InstallDir 'php.exe')) { $php = Join-Path $InstallDir 'php.exe' }

    # ---------- 2. Descarca si dezarhiveaza PHP daca lipseste ----------
    if (-not $php) {
        $zipName = [IO.Path]::GetFileName(([Uri]$DownloadUrl).AbsolutePath)
        $zip = Join-Path $env:TEMP $zipName
        Say 'PHP nu este instalat.' 'Yellow'
        Say "Descarc $zipName (aprox. 35 MB)..." 'Gray'
        $downloaded = $false
        try {
            [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
            $ProgressPreference = 'SilentlyContinue'
            Invoke-WebRequest -Uri $DownloadUrl -OutFile $zip -UseBasicParsing -ErrorAction Stop
            $downloaded = (Test-Path $zip) -and ((Get-Item $zip).Length -gt 1MB)
        } catch {
            Say "Descarcarea automata a esuat: $($_.Exception.Message)" 'Yellow'
        }
        if (-not $downloaded) {
            Say 'Deschid linkul in browser. Salveaza fisierul in Downloads; astept pana la 20 de minute...' 'Yellow'
            $started = Get-Date
            Start-Process $DownloadUrl
            $dl = Get-DownloadsDir
            $deadline = (Get-Date).AddMinutes(20)
            $zip = $null
            while (-not $zip -and (Get-Date) -lt $deadline) {
                Start-Sleep 3
                $c = Join-Path $dl $zipName
                if ((Test-Path $c) -and ((Get-Item $c).LastWriteTime -gt $started.AddMinutes(-1)) -and (Test-FileReady $c)) { $zip = $c }
            }
            if (-not $zip) { throw "Arhiva PHP nu a fost descarcata. Descarc-o manual de la $DownloadUrl si dezarhiveaz-o in $InstallDir" }
        }

        Say "Dezarhivez in $InstallDir ..." 'Gray'
        try {
            New-Item -ItemType Directory -Force -Path $InstallDir -ErrorAction Stop | Out-Null
            Expand-Archive -LiteralPath $zip -DestinationPath $InstallDir -Force -ErrorAction Stop
        } catch {
            throw "Nu pot scrie in $InstallDir ($($_.Exception.Message)). Ruleaza fisierul ca Administrator (clic dreapta - Run as administrator)."
        }
        $php = Join-Path $InstallDir 'php.exe'
        if (-not (Test-Path $php)) { throw "php.exe lipseste dupa dezarhivare in $InstallDir" }
        if ($zip -like "$env:TEMP*") { Remove-Item $zip -Force -ErrorAction SilentlyContinue }
    }

    # ---------- 3. PATH (utilizator) ----------
    $phpDir = Split-Path -Parent $php
    $inPath = ($env:Path -split ';') | Where-Object { $_ -and ($_.TrimEnd('\') -ieq $phpDir.TrimEnd('\')) }
    if (-not $inPath) {
        $userPath = [Environment]::GetEnvironmentVariable('Path', 'User')
        $parts = @()
        if ($userPath) { $parts = @($userPath -split ';' | Where-Object { $_ }) }
        [Environment]::SetEnvironmentVariable('Path', (($parts + $phpDir) -join ';'), 'User')
        $env:Path = "$phpDir;$env:Path"
        Say "Am adaugat $phpDir in PATH. Ferestrele de terminal deja deschise trebuie redeschise." 'Green'
    }

    # ---------- 4. Visual C++ Redistributable (eroare = oprire) ----------
    $vc = Get-VcProblem
    if ($vc) {
        Say '' 'Red'
        Say 'EROARE: Visual C++ Redistributable 2015-2022 (x64) lipseste sau este prea vechi.' 'Red'
        Say "  Detaliu: $vc" 'Red'
        Say "  Instaleaza versiunea curenta: $VcRedistUrl" 'Yellow'
        Say '  Apoi reporneste acest fisier. Deschid acum linkul de descarcare...' 'Yellow'
        Start-Process $VcRedistUrl
        Read-Host 'Apasa Enter pentru inchidere' | Out-Null
        exit 2
    }

    $ver = (& $php -v 2>$null | Select-Object -First 1)
    if (-not $ver) {
        Say 'EROARE: php.exe nu porneste (probabil Visual C++ Redistributable incompatibil).' 'Red'
        Say "  Instaleaza/actualizeaza: $VcRedistUrl" 'Yellow'
        Start-Process $VcRedistUrl
        Read-Host 'Apasa Enter pentru inchidere' | Out-Null
        exit 2
    }
    Say "PHP gasit: $ver" 'Gray'

    # ---------- 5. Extensii (dom, zip = avertisment; se continua) ----------
    $mods = Get-Modules
    $need = @(($Required + $Optional) | Where-Object { $mods -notcontains $_ })
    if ($need.Count -gt 0) {
        try {
            $ini = $null
            foreach ($l in (& $php --ini 2>$null)) {
                if ($l -match '^Loaded Configuration File:\s*(.+)$') {
                    $v = $Matches[1].Trim()
                    if ($v -ne '(none)') { $ini = $v }
                }
            }
            if (-not $ini) {
                $ini = Join-Path $phpDir 'php.ini'
                $tpl = Join-Path $phpDir 'php.ini-development'
                if (Test-Path $tpl) { Copy-Item $tpl $ini -ErrorAction Stop } else { Set-Content -Path $ini -Value '' -ErrorAction Stop }
            }
            $txt = [IO.File]::ReadAllText($ini)
            if ($txt -notmatch '(?m)^\s*extension_dir\s*=') {
                $extDir = Join-Path $phpDir 'ext'
                $line = 'extension_dir = "' + $extDir + '"'
                if ($txt -match '(?m)^;\s*extension_dir\s*=\s*"ext"') {
                    $txt = [regex]::Replace($txt, '(?m)^;\s*extension_dir\s*=\s*"ext"', $line.Replace('$', '$$'))
                } else {
                    $txt += "`r`n" + $line + "`r`n"
                }
            }
            foreach ($e in $need) {
                $pat = '(?m)^;\s*extension\s*=\s*' + $e + '\s*$'
                if ($txt -match $pat) {
                    $txt = [regex]::Replace($txt, $pat, "extension=$e")
                } elseif ($txt -notmatch ('(?m)^\s*extension\s*=\s*' + $e + '\s*$')) {
                    $txt += "`r`nextension=$e`r`n"
                }
            }
            [IO.File]::WriteAllText($ini, $txt, (New-Object System.Text.UTF8Encoding($false)))
            Say "Am incercat sa activez extensiile ($($need -join ', ')) in $ini" 'Gray'
        } catch {
            Say "Nu am putut edita php.ini: $($_.Exception.Message)" 'Yellow'
        }
        $mods = Get-Modules
    }
    $lipsa = @($Required | Where-Object { $mods -notcontains $_ })
    if ($lipsa.Count -gt 0) {
        $Warnings += "Extensii PHP lipsa: $($lipsa -join ', '). Aplicatia poate functiona incomplet (de ex. generarea documentelor). Verifica php.ini ($phpDir)."
    }
    $lipsaOpt = @($Optional | Where-Object { $mods -notcontains $_ })
    if ($lipsaOpt.Count -gt 0) {
        $Warnings += "Extensie optionala lipsa: $($lipsaOpt -join ', ')."
    }

    # ---------- 6. Porneste serverul ----------
    $l = Get-Listener
    if ($l) {
        $pn = (Get-Process -Id $l.OwningProcess -ErrorAction SilentlyContinue).ProcessName
        if ($pn -ne 'php') { throw "Portul $Port este folosit de alt program ($pn). Schimba variabila `$Port din fisier." }
        Say "Serverul ruleaza deja pe http://${Addr}:$Port/" 'Yellow'
    } else {
        Start-Process -FilePath $php -ArgumentList @('-S', "${Addr}:$Port", '-t', ('"' + $Public + '"')) -WorkingDirectory $Root -WindowStyle $WindowStyle
        $ok = $false
        for ($i = 0; $i -lt 20; $i++) {
            Start-Sleep -Milliseconds 500
            if (Get-Listener) { $ok = $true; break }
        }
        if (-not $ok) { throw "Serverul nu a pornit. Incearca manual: php -S ${Addr}:$Port -t public" }
        Say "Server pornit pe http://${Addr}:$Port/" 'Green'
    }

    Start-Process "http://${Addr}:$Port/"

    foreach ($w in $Warnings) { Say "ATENTIE: $w" 'Yellow' }
    Say 'Gata. Pentru oprire: porneste-roapp.bat stop' 'Gray'
    if ($Warnings.Count -gt 0) { Read-Host 'Apasa Enter pentru inchidere' | Out-Null } else { Start-Sleep 3 }
    exit 0
}
catch {
    Say "EROARE: $($_.Exception.Message)" 'Red'
    Read-Host 'Apasa Enter pentru inchidere' | Out-Null
    exit 1
}
