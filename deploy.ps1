# Buduje paczkę halo-ziemia.tar.gz i wdraża ją na serwer.
# Użycie (w katalogu głównym repo):
#   .\deploy.ps1                       # produkcja: kontrola gita, build, wysyłka, deploy/deploy.sh na serwerze
#   .\deploy.ps1 -Target staging       # inny cel z deploy/targets.local.psd1 (bez kontroli gałęzi)
#   .\deploy.ps1 -SkipUpload           # tylko zbudowanie halo-ziemia.tar.gz (bez łączenia z serwerem)
#   .\deploy.ps1 -CleanBackups         # usunięcie kopii bazy sprzed migracji (shared/backup/)
# Z WSL/Linuksa: pwsh ./deploy.ps1 …
# Wymaga: działającego `docker compose up -d` (kontener php z composerem), dostępu SSH do serwera.
# Szczegóły: deploy/README.md

param(
    [ValidateSet('prod', 'staging')]
    [string]$Target = 'prod',
    [switch]$SkipUpload,
    [switch]$CleanBackups
)

$ErrorActionPreference = 'Stop'
Set-Location $PSScriptRoot
. ./deploy/common.ps1

$Package = 'halo-ziemia.tar.gz'
$Build = 'build'          # w katalogu projektu, więc kontener php widzi go jako /app/build
$PhpVersion = '85'        # handler PHP w .htaccess dla -SkipUpload bez targets.local.psd1

if (-not $SkipUpload) {
    $cfg = Get-DeployTarget $Target
    $Remote = $cfg.Remote
    $Port = $cfg.Port
    $Domain = $cfg.Domain
    $RemoteDir = $cfg.RemoteDir
    $PhpVersion = $cfg.Php
    Write-Host "Cel: $Target ($Remote, port $Port, ~/$RemoteDir, PHP $PhpVersion)" -ForegroundColor Cyan
}
$RemotePhp = "/usr/local/bin/php$PhpVersion"

if ($CleanBackups) {
    if ($SkipUpload) { throw "-CleanBackups łączy się z serwerem – nie łącz go z -SkipUpload." }
    Step "Usuwanie kopii bazy z ~/$RemoteDir/shared/backup/"
    Native { ssh -p $Port $Remote "PHP=$RemotePhp bash $RemoteDir/deploy.sh --clean-backups" }; Check "deploy.sh --clean-backups"
    return
}

if ($Target -eq 'prod' -and -not $SkipUpload) {
    Step "Kontrola przed wdrożeniem na produkcję"
    if (-not (Test-Path .git)) { throw "Produkcję wdraża się tylko z repozytorium gita." }
    $branch = Native { git rev-parse --abbrev-ref HEAD }; Check "git rev-parse"
    if ($branch -ne 'main') { throw "Produkcję wdraża się z gałęzi main (bieżąca: $branch)." }
    if (Native { git status --porcelain }) { throw "Masz niezacommitowane zmiany – najpierw commit i push." }
    $hasOrigin = Native { git remote } | Where-Object { $_ -eq 'origin' }
    if ($hasOrigin) {
        Native { git fetch --quiet origin main }; Check "git fetch"
        $head = Native { git rev-parse HEAD }
        $originHead = Native { git rev-parse origin/main }
        if ($head -ne $originHead) {
            throw "HEAD różni się od origin/main – najpierw git push / git pull. Na produkcję idzie tylko kod z origin/main."
        }
    } else {
        Write-Warning "Brak zdalnego repozytorium 'origin' – pomijam porównanie z origin/main."
    }
    Native { git log -1 --date=format:'%Y-%m-%d %H:%M' --format='Wdrażany commit: %h %s (%an, %ad)' }
    $answer = Read-Host "Wdrożenie na PRODUKCJĘ. Wpisz nazwę domeny ($Domain), aby kontynuować"
    if ($answer -ne $Domain) { throw "Przerwano – nie wpisano nazwy domeny." }
}

# Pliki niepotrzebne na serwerze (ścieżki względem katalogu projektu).
$Junk = @(
    'tests', 'var', 'vendor', 'public/bundles', 'public/assets', 'agent/tests', 'docker', 'deploy', 'deploy.ps1',
    '.env.dev', '.env.test', '.env.local', '.phpunit.cache', '.php-cs-fixer.cache', '.php-cs-fixer.dist.php',
    'phpstan.dist.neon', 'phpunit.dist.xml', 'compose.yaml', 'compose.override.yaml', '.editorconfig',
    '.gitlab-ci.yml', '.gitattributes', '.gitignore', 'bin/phpunit'
)

Step "Czysta kopia projektu"
if (Test-Path $Build) { Remove-Item $Build -Recurse -Force }
if (Test-Path .git) {
    if (Native { git status --porcelain }) {
        throw "Masz niezacommitowane zmiany. Paczka powstaje z HEAD, więc najpierw commit."
    }
    Native { git archive --format=tar --prefix=build/ -o build.tar HEAD }; Check "git archive"
    tar -xf build.tar; Check "tar -x"
    Remove-Item build.tar
} else {
    throw "Paczkę buduje się z repozytorium gita (HEAD)."
}
$JunkPaths = ($Junk | ForEach-Object { "'$Build/$_'" }) -join ' '
InPhp "rm -rf $JunkPaths"; Check "czyszczenie kopii"

Step "Composer (produkcyjnie, w kontenerze PHP)"
InPhp "cd $Build && composer install --no-dev --optimize-autoloader --classmap-authoritative --no-scripts --no-interaction --no-progress"
Check "composer install"

Step "Zasoby (AssetMapper: CSS, JS, uPlot → public/assets)"
# APP_ENV jawnie: w paczce nie ma dev-bundli. APP_SECRET tymczasowy – komenda go nie używa, ale kontener wymaga wartości.
InPhp "cd $Build && APP_ENV=prod APP_DEBUG=0 APP_SECRET=build php bin/console asset-map:compile --no-interaction && rm -rf var"
Check "asset-map:compile"

Step "Handler PHP $PhpVersion w public/.htaccess"
$htaccess = "$Build/public/.htaccess"
$handler = "AddHandler application/x-httpd-php$PhpVersion .php"
# Na hostingu PHP-FPM/LSAPI wersję wybiera AddHandler; lokalnie (mod_php w Dockerze) ta linia by przeszkadzała,
# dlatego trafia tylko do paczki.
Set-Content -Path $htaccess -Value ("# Wersja PHP na hostingu (dodane przez deploy.ps1)`n$handler`n`n" + (Get-Content $htaccess -Raw)) -NoNewline
if (-not (Select-String -Path $htaccess -Pattern ([regex]::Escape($handler)) -Quiet)) { throw "Nie udało się dopisać AddHandler do $htaccess" }

Step "Pakowanie"
if (Test-Path $Package) { Remove-Item $Package }
# tar z hosta (Windows: bsdtar, WSL: GNU tar), nie z kontenera
Native { tar -czf $Package -C $Build . }; Check "tar -cz"
Remove-Item $Build -Recurse -Force
$size = "{0:N1} MB" -f ((Get-Item $Package).Length / 1MB)
Write-Host "Paczka: $Package ($size)"

if ($SkipUpload) {
    Write-Host "`n-SkipUpload: kończę bez wysyłki." -ForegroundColor Yellow
    return
}

Step "Wysyłka"
Native { scp -P $Port $Package "${Remote}:${RemoteDir}/" }; Check "scp"

Step "Wdrożenie na serwerze"
Native { ssh -p $Port $Remote "PHP=$RemotePhp bash $RemoteDir/deploy.sh" }; Check "deploy.sh"

Remove-Item $Package
Write-Host "`nGotowe." -ForegroundColor Green
