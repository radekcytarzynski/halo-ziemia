# Wdrożenie

Panel i API Symfony na jednej domenie, hosting współdzielony z DirectAdminem (cyber_Folks), bez sudo i bez demonów. Całe tło to cron.
Cel wybiera parametr `-Target`; dane serwera są w `deploy/targets.local.psd1` (poza repo).

| | |
|---|---|
| Wdrożenie | `.\deploy.ps1` (Windows) albo `pwsh ./deploy.ps1` (WSL) |
| Katalog domeny | `~/domains/<domena>` |
| PHP CLI | `/usr/local/bin/php85` (samo `php` w CLI to zwykle starsza wersja – nie używać) |
| PHP WWW | `AddHandler application/x-httpd-php85` – dopisywany do `public/.htaccess` w paczce |

W przykładach poleceń `<użytkownik>@<host>`, `<port>` i `<domena>` to dane celu z `deploy/targets.local.psd1`.

## Jak to działa

`deploy.ps1` (lokalnie, PowerShell, katalog główny repo):

0. tylko `-Target prod` (domyślny): bieżąca gałąź to `main`, brak niezacommitowanych zmian, `HEAD` = `origin/main` (jeśli jest `origin`), wpisanie nazwy domeny jako potwierdzenie
1. czysta kopia projektu z gita (`git archive HEAD`) do `build/`, bez testów, narzędzi deweloperskich, `docker/`, `deploy/`, `.env.dev`/`.env.test`
2. w kontenerze `php`: `composer install --no-dev --classmap-authoritative`, `asset-map:compile` (CSS/JS/uPlot do `public/assets/`)
3. `AddHandler application/x-httpd-php<Php>` na początku `public/.htaccess` (tylko w paczce – lokalny Apache z mod_php by go nie zniósł)
4. pakowanie do `halo-ziemia.tar.gz`, `scp` do katalogu domeny i `ssh … bash deploy.sh`

`deploy.sh` (na serwerze, w katalogu domeny):

0. ochrona katalogu: musi istnieć `shared/.env.local`, a host z jego `APP_BASE_URL` musi być równy nazwie katalogu domeny. Do tego blokada `flock`, więc dwa wdrożenia naraz się nie wykonają
1. kontrola serwera: PHP CLI ≥ 8.5 (`$PHP`), `tar`, `gzip`, `mysqldump`, `curl`, obecność paczki, prawa `600` dla `shared/.env.local`
2. rozpakowanie do `public_html/app_new/`, skopiowanie `shared/.env.local`, podpięcie `var/log` → `public_html/log`
3. `cache:warmup` (kontrola konfiguracji). Jeśli są nowe migracje: [kopia bazy](#kopie-bazy-przed-migracjami) (poza pierwszym wdrożeniem na pustą bazę), potem `doctrine:migrations:migrate`
4. `app/` → `app_prev/`, `app_new/` → `app/` (w `public_html/`), zapis `public_html/.htaccess`, ponowny `cache:warmup`
5. healthcheck: `GET <APP_BASE_URL>/health` musi zwrócić 200; ostrzega, gdy aplikacja nie widzi HTTPS (`"secure":false` – wtedy API odrzuci agentów). `/health/db` jest tylko raportowany
6. `app:status`: ostatni `app:tick`, urządzenia, kolejka powiadomień
7. jeśli krok 4 lub 5 się nie uda, następuje automatyczny powrót do `app_prev/`, a nieudana wersja zostaje w `app_bad/`. **Migracje nie są cofane** (zob. [Rollback](#rollback))

Układ katalogu domeny po wdrożeniu:

```
~/domains/<domena>/
├── deploy.sh
├── shared/                 poza katalogiem WWW (czyta go tylko deploy.sh)
│   ├── .env.local          konfiguracja (sekrety), chmod 600
│   └── backup/             kopie bazy sprzed migracji (5 ostatnich)
└── public_html/            katalog WWW
    ├── .htaccess           generowany przez deploy.sh: każdy adres → app/public/
    ├── app/                bieżąca wersja (cron wskazuje public_html/app/bin/console)
    ├── app_prev/           poprzednia wersja
    ├── app_bad/            tylko po automatycznym wycofaniu
    └── log/                prod.log (rotacja dzienna, 14 plików)
```

### Dlaczego aplikacja leży w public_html

Na cyber_Folks *separacja domen* ustawia `open_basedir` na katalog WWW domeny, więc PHP strony nie może otworzyć niczego poza `public_html/`. Separację zostawiamy włączoną: jeśli w aplikacji znajdzie się dziura pozwalająca czytać pliki, atakujący nie sięgnie do innych domen na koncie. Dlatego kod i logi leżą wewnątrz `public_html/`, a z sieci dostępne jest tylko `app/public/`:

1. `public_html/.htaccess` przepisuje **każdy** adres na `app/public/…`. Adres `/app/.env.local` trafia więc do `app/public/app/.env.local`, którego nie ma, i kończy się stroną 404 z Symfony. Bez `mod_rewrite` ten sam plik blokuje wszystko.
2. Druga warstwa, gdyby ktoś usunął lub zepsuł plik z punktu 1: `app/.htaccess` (z repo) ma `Require all denied`, a otwiera go tylko `app/public/.htaccess` (`Require all granted`). To samo działa dla `app_prev/` i `app_bad/`. `log/.htaccess` ma `Require all denied`.
3. Sekrety i kopie bazy (`shared/`) w ogóle nie leżą w katalogu WWW. Do `app/` trafia tylko kopia `.env.local`, chroniona jak wyżej.

Wymaga to `AllowOverride` z `FileInfo` i `AuthConfig` (na cyber_Folks domyślnie jest). Jeśli nie ma, healthcheck dostanie 500 i wdrożenie się wycofa.

Wymagania lokalne: PowerShell (`pwsh` w WSL albo Windows PowerShell), działające `docker compose up -d`, klient OpenSSH (`ssh`, `scp`) i klucz SSH dodany do konta na serwerze.

**Dane serwera** – jednorazowo:

```powershell
Copy-Item deploy/targets.example.psd1 deploy/targets.local.psd1   # i uzupełnij: Remote, Port, Domain, Php
```

## Pierwsze wdrożenie

### 1. Domena, PHP, baza

- **Domena lub subdomena** w DirectAdminie, z certyfikatem SSL (Let's Encrypt). Agent wysyła dane tylko po HTTPS.
- **cyber_Folks:** *separację domen* zostaw włączoną (zob. [wyżej](#dlaczego-aplikacja-leży-w-public_html)). W ustawieniach katalogu HTTPS wybierz *symlink z private_html do public_html* (te same dane dla http i https).
- **PHP 8.5** sprawdź przez SSH: `ls /usr/local/bin/php8*` i `/usr/local/bin/php85 -v`.
- **Baza:** DirectAdmin → *MySQL Management* → *Create new database*. Zanotuj nazwę bazy, użytkownika i hasło (z prefiksem konta). Wersja serwera: `mysql -u <uzytkownik_db> -p -e 'SELECT VERSION();'`.
- **Skrzynka do wysyłki** powiadomień (np. `monitor@<domena>`) – dane SMTP z panelu.

### 2. Konfiguracja na serwerze

```bash
ssh -p <port> <użytkownik>@<host>
cd ~/domains/<domena>
mkdir -p shared
openssl rand -hex 32          # → APP_SECRET (generuj na serwerze, nie kopiuj z dev)
nano shared/.env.local        # na podstawie deploy/env.local.example
chmod 600 shared/.env.local
```

`APP_BASE_URL` musi być równy `https://<domena>`, czyli nazwie katalogu domeny. Inaczej `deploy.sh` odmówi działania.

### 3. Wgranie deploy.sh

Z katalogu głównego repo (`deploy.sh` musi mieć końce linii LF, czego pilnuje `.gitattributes`):

```powershell
scp -P <port> deploy/deploy.sh <użytkownik>@<host>:domains/<domena>/
```

Skrypt na serwerze nie aktualizuje się sam. Po każdej zmianie `deploy/deploy.sh` w repo wgraj go ponownie.

### 4. Wdrożenie

```powershell
.\deploy.ps1                    # Windows
pwsh ./deploy.ps1               # WSL
```

Za pierwszym razem `deploy.sh` przeniesie ewentualny obcy `public_html/.htaccess` do `public_html.htaccess.orig-<data>` (poza katalog WWW). Domyślne pliki z panelu (np. `index.html`) mogą zostać, bo żaden adres już do nich nie prowadzi, ale możesz je usunąć. Pustą bazę zmigruje bez robienia kopii.
Samą paczkę bez wysyłki zbudujesz przez `-SkipUpload`.

### 5. Administrator

```bash
cd ~/domains/<domena>/public_html/app
/usr/local/bin/php85 bin/console app:user:create twoj@adres.pl
```

Hasło (minimum 12 znaków) wpisujesz interaktywnie.

### 6. Cron (DirectAdmin → *Cron Jobs*)

| Kiedy | Polecenie |
|-------|-----------|
| `* * * * *` | `/usr/local/bin/php85 /home/<użytkownik>/domains/<domena>/public_html/app/bin/console app:tick --no-interaction > /dev/null 2>&1` |
| `5 * * * *` | `… app:rollup --no-interaction > /dev/null 2>&1` (od etapu 6) |
| `30 3 * * *` | `… app:retention --no-interaction > /dev/null 2>&1` (od etapu 6) |

Gotowe linie z poprawnymi ścieżkami są w panelu: **Ustawienia → Cron**. Ścieżka `app/` się nie zmienia, bo wdrożenie podmienia katalogi, więc cron nie wymaga poprawek po kolejnych wdrożeniach.
Błędy crona trafiają do `public_html/log/prod.log`. Dashboard pokazuje czerwony banner, gdy `app:tick` nie działa dłużej niż 3 minuty.

Opcjonalnie [healthchecks.io](https://healthchecks.io) (darmowe): utwórz check z okresem 1 min i grace 5 min, a jego URL wpisz jako `WATCHDOG_URL` w `shared/.env.local`. Dostaniesz alarm, gdy przestanie działać sam monitor.

### 7. Sprawdzenie

- `https://<domena>/health` → `{"status":"ok","secure":true}` (`secure:false` przy HTTPS oznacza, że trzeba ustawić `TRUSTED_PROXIES`)
- `https://<domena>/health/db` → `{"status":"ok"}`
- `https://<domena>/` → logowanie kontem z kroku 5
- przez SSH: `/usr/local/bin/php85 public_html/app/bin/console app:status`
- `https://<domena>/app/.env.local`, `https://<domena>/log/prod.log` → 404 (nic poza `app/public/` nie jest dostępne)
- **Odbiorcy → dodaj siebie → „Wyślij testowy mail”**

### 8. Urządzenia

W panelu: **Urządzenia → Dodaj → Utwórz token**, potem na urządzeniu snippet ze strony urządzenia (`curl … | tar xz && sudo ./install.sh --url https://<domena>`). Szczegóły: [agent/README.md](../agent/README.md).

## Kolejne wdrożenia

Zacommituj (i wypchnij na `origin/main`, jeśli jest), potem:

```powershell
.\deploy.ps1
```

Migracje uruchamiają się automatycznie, z kopią bazy, jeśli są nowe. Nowe zmienne środowiskowe dopisz ręcznie do `shared/.env.local` **przed** wdrożeniem (i do `env.local.example` w repo). Jeśli zmienił się `deploy/deploy.sh`, najpierw wgraj go na serwer (krok 3).

## Kopie bazy przed migracjami

Jeśli są nowe migracje, `deploy.sh` najpierw robi `mysqldump` do `shared/backup/db-RRRRMMDD-GGMMSS.sql.gz` (prawa 600, katalog 700). Tabele próbek (`sample`, `sample_hourly`) trafiają do kopii bez danych: są duże, a kopia ma chronić konfigurację, reguły i historię zdarzeń. Dane logowania pochodzą z `DATABASE_URL`, a hasło trafia do tymczasowego pliku opcji, nie do wiersza poleceń. Gdy kopia się nie uda, wdrożenie zatrzymuje się **przed** migracjami. Zostaje 5 ostatnich kopii.

Usunięcie kopii: `.\deploy.ps1 -CleanBackups` (albo na serwerze `bash deploy.sh --clean-backups`).

Przywrócenie:

```bash
cd ~/domains/<domena>
gunzip < shared/backup/db-RRRRMMDD-GGMMSS.sql.gz | mysql -u <uzytkownik_db> -p <nazwa_db>
```

Regularne kopie całej bazy robi hosting. Dodatkowo możesz raz na dobę zrobić zrzut cronem, np. `mysqldump … | gzip > ~/backup/halo-$(date +%u).sql.gz`, co daje 7 rotujących plików.

## Rollback

```bash
cd ~/domains/<domena>/public_html
rm -rf app_bad && mv app app_bad && mv app_prev app
```

`.htaccess` kieruje ruch do `app/public`, a cron na `app/bin/console`, więc zmiana działa od razu. Dostępna jest tylko jedna poprzednia wersja.

**Migracje nie są cofane.** Jeśli nowa wersja zmieniła schemat w sposób niezgodny ze starszym kodem, przed rollbackiem (jeszcze w `app/`) wykonaj:

```bash
/usr/local/bin/php85 bin/console doctrine:migrations:migrate prev
```

## Rotacja tokenu agenta

Panel → urządzenie → **Tokeny i agent → Rotuj token** (z oknem przejściowym, np. 24 h). Potem na urządzeniu:

```bash
sudo nano /etc/halo-ziemia/agent.conf     # nowy HZ_TOKEN
sudo halo-ziemia-agent --test
```

albo `sudo ./install.sh --reconfigure`. Stary token przestaje działać po upływie okna, a w razie wycieku możesz go od razu **unieważnić**.

## Logi

- **Aplikacja:** `~/domains/<domena>/public_html/log/prod.log` (błędy żądań i crona; rotacja dzienna, 14 plików)
- **Serwer WWW/PHP:** DirectAdmin → *Site Summary / Statistics / Logs* albo `~/domains/<domena>/logs/`. Trafiają tu błędy sprzed startu Symfony (zły handler PHP, `.htaccess`)
- **Agent na urządzeniu:** `journalctl -u halo-ziemia-agent`

| Objaw | Przyczyna |
|---|---|
| 500 na wszystkim, w `prod.log` pusto | zły `AddHandler` (wersja PHP niedostępna dla WWW), `.htaccess` niedozwolony przez `AllowOverride` (log serwera WWW) albo błąd w `shared/.env.local` |
| `/health` daje `"secure":false` mimo HTTPS | aplikacja za proxy: ustaw `TRUSTED_PROXIES` w `shared/.env.local` |
| agent: `HTTP 403 https_required` | jak wyżej, albo agent ma w `HZ_URL` adres `http://` |
| agent: `HTTP 401` | token unieważniony, wygasły po rotacji albo wpisany z błędem |
| czerwony banner „Cron nie działa” | brak wpisu crona albo zła ścieżka do PHP 8.5 w cronie |
| brak maili | Odbiorcy → „Wyślij testowy mail” pokazuje błąd SMTP; **Dziennik → Powiadomienia** pokazuje ponowienia |
