# Wspólne funkcje dla deploy.ps1 (dot-source z katalogu głównego repo).

# Serwery – z lokalnego pliku poza gitem (szablon: deploy/targets.example.psd1), celowo bez nadpisywania
# z parametrów. Konto SSH może obsługiwać też inne strony, więc wszystko dzieje się wyłącznie w katalogu
# domeny (deploy.sh dodatkowo sprawdza, że katalog pasuje do APP_BASE_URL z shared/.env.local).
$TargetsFile = 'deploy/targets.local.psd1'
function Get-DeployTarget([string]$name) {
    if (-not (Test-Path $TargetsFile)) {
        throw "Brak $TargetsFile. Skopiuj deploy/targets.example.psd1 do $TargetsFile i uzupełnij dane serwera."
    }
    $all = Import-PowerShellDataFile $TargetsFile
    $t = $all[$name]
    if (-not $t) { throw "W $TargetsFile brak celu '$name'." }
    $remote = [string]$t.Remote
    $domain = [string]$t.Domain
    $php = if ($t.Php) { [string]$t.Php } else { '85' }
    if ($remote -notmatch '^[A-Za-z0-9._-]+@[A-Za-z0-9.-]+$') { throw "${TargetsFile}: '$name'.Remote musi mieć postać uzytkownik@host (jest: '$remote')." }
    if ($domain -notmatch '^[A-Za-z0-9.-]+\.[A-Za-z]{2,}$') { throw "${TargetsFile}: '$name'.Domain musi być nazwą domeny (jest: '$domain')." }
    if (-not ($t.Port -is [int]) -or $t.Port -lt 1 -or $t.Port -gt 65535) { throw "${TargetsFile}: '$name'.Port musi być liczbą 1–65535." }
    if ($php -notmatch '^8[5-9]$') { throw "${TargetsFile}: '$name'.Php musi być wersją PHP bez kropki, np. '85' (jest: '$php')." }
    # RemoteDir względem katalogu domowego na serwerze (ssh/scp startują w ~)
    return @{ Remote = $remote; Port = $t.Port; Domain = $domain; RemoteDir = "domains/$domain"; Php = $php }
}

function Step($msg) { Write-Host "`n== $msg" -ForegroundColor Cyan }
function Check($what) { if ($LASTEXITCODE -ne 0) { throw "$what zakończone błędem (kod $LASTEXITCODE)" } }
# Polecenie w kontenerze php (katalog projektu = /app), jako www-data (UID hosta – pliki nie należą do roota).
# Lokalnie 'Continue': w Windows PowerShell 5.1 przy przekierowaniu wyjścia każda linia stderr narzędzia
# (composer pisze tam postęp) staje się błędem i przy 'Stop' przerywa skrypt. O porażce decyduje $LASTEXITCODE.
function InPhp($cmd) { $ErrorActionPreference = 'Continue'; docker compose exec -T --user www-data php sh -c $cmd }
function Native([scriptblock]$block) { $ErrorActionPreference = 'Continue'; & $block }
