# halo-ziemia

Monitoring stanu urządzeń (heartbeat + metryki) z alarmami e-mail i panelem administracyjnym.
Symfony 7.4 LTS, PHP 8.5, MariaDB. Działa na hostingu współdzielonym: całe przetwarzanie w tle to komendy konsolowe z crona.

> Dokumentacja jest uzupełniana etapami. Pełna instrukcja wdrożenia, crona i agenta pojawi się w etapie 6.

## Środowisko deweloperskie (Docker, Windows/WSL2)

Wymagania: Docker z `docker compose`. Lokalny PHP nie jest potrzebny, bo wszystko działa w kontenerze.

```bash
docker compose up -d --build
docker compose exec --user www-data php composer install
docker compose exec --user www-data php bin/console doctrine:migrations:migrate -n
docker compose exec --user www-data php bin/console app:user:create admin@example.com
```

| Usługa  | Adres                          | Zmienna portu      |
|---------|--------------------------------|--------------------|
| Panel   | http://localhost:8090          | `HZ_HTTP_PORT`     |
| Mailpit | http://localhost:8035          | `HZ_MAILPIT_PORT`  |
| MariaDB | `localhost:3317` (halo/halo)   | `HZ_DB_PORT`       |

### Jakość kodu

```bash
docker compose exec --user www-data php composer qa
```

`composer qa` uruchamia PHP-CS-Fixer (dry-run), PHPStan (level max), PHPUnit oraz testy agenta (shellcheck i bats). Przed testami PHPUnit tworzy i migruje bazę `halo_test`.
Poprawki stylu: `composer cs-fix`.

## Konfiguracja (zmienne środowiskowe)

Wartości domyślne są w `.env`. Lokalne i produkcyjne nadpisania umieszczaj w `.env.local`, który nie trafia do repozytorium.

| Zmienna                | Opis |
|------------------------|------|
| `APP_ENV`              | `dev` / `prod` |
| `APP_SECRET`           | Losowy sekret: `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'` |
| `DATABASE_URL`         | np. `mysql://user:pass@host:3306/db?serverVersion=11.4.0-MariaDB&charset=utf8mb4` |
| `MAILER_DSN`           | np. `smtp://user:pass@smtp.example.com:587` |
| `APP_BASE_URL`         | Publiczny adres panelu (linki w mailach z crona) |
| `APP_TIMEZONE_DISPLAY` | Strefa prezentacji w UI, domyślnie `Europe/Warsaw` (baza zawsze UTC) |
| `MAILER_FROM`          | Domyślny nadawca powiadomień (na hostingu zwykle adres skrzynki SMTP) |
| `WATCHDOG_URL`         | Opcjonalny URL pingowany po każdym udanym `app:tick` |
| `API_REQUIRE_HTTPS`    | `1` = API tylko po HTTPS (domyślnie) |
| `LOCK_DSN`             | Domyślnie `flock` (pliki w `var/`) |

## API agentów

Uwierzytelnianie: nagłówek `Authorization: Bearer hz_…` (token per urządzenie). API jest bezstanowe, bez sesji i ciasteczek.
W produkcji przyjmuje wyłącznie HTTPS (`API_REQUIRE_HTTPS=1`); żądanie po HTTP dostaje `403 https_required`.

| Endpoint | Opis |
|----------|------|
| `POST /api/v1/heartbeat` | Heartbeat z metrykami (JSON, max 64 KB) |
| `GET /api/v1/ping` | Minimalny heartbeat bez metryk |

Odpowiedź `200`: `{"ok":true,"server_time":"2026-11-19T12:00:00Z","interval":60}`.
Błędy (`{"ok":false,"error":"…","message":"…","details":[…]}`):
`401 unauthorized`, `413 payload_too_large`, `422 invalid_json` / `validation_failed`, `429 rate_limited` (12/min na token, nagłówek `Retry-After`).

Czas kontaktu to zawsze czas odbioru po stronie serwera, a zegar agenta jest ignorowany.

### Payload (schema 1)

Wymagane jest tylko `"schema": 1`. Pozostałe sekcje są opcjonalne, a nieznane pola są ignorowane.
Przykład: `tests/Unit/Ingest/HeartbeatParserTest.php::examplePayload()`.

Spłaszczanie do kluczy metryk:

| Sekcja payloadu | Klucze metryk |
|-----------------|---------------|
| `uptime_s`, `cpu_temp_c` | `uptime_s`, `cpu.temp_c` |
| `load: [1, 5, 15]` | `load.1`, `load.5`, `load.15` |
| `mem` | `mem.available_pct`, `mem.available_kb`, `mem.swap_used_kb`, `mem.swap_used_pct` (gdy jest `swap_total_kb`) |
| `disks[]` | `disk.<mount>.used_pct`, `disk.<mount>.free_b`, `disk.<mount>.inodes_used_pct` |
| `smart[]` | `smart.<dev>.passed` (1/0), `.temp_c`, `.power_on_hours`, `.reallocated`, `.pending`, `.percentage_used` |
| `raid[]` | `raid.<name>.degraded` (1/0) |
| `rpi.throttled` (`"0x50005"`) | `rpi.throttled` (liczba; flagi opisowo w snapshotcie) |
| `checks[]` | wynik checka + `check.<name>.age_s` (z `last_success_age_s`) |
| `metrics: {"klucz": liczba}` | własne metryki pluginów (nie nadpisują wbudowanych) |

### Tokeny

- Format: `hz_` + 48 znaków base62 z CSPRNG. W bazie trzymany jest tylko SHA-256 i 10-znakowy prefiks do identyfikacji.
- Rotacja wydaje nowy token, a dotychczasowe działają jeszcze przez okno przejściowe (domyślnie 24 h).
- Pomocniczo w dev: `bin/console app:device:create "NAS Debian"` wypisuje token jeden raz.

## Alarmy

- **Stany:** `PENDING` (brak kontaktu, bez alarmów), `OK`, `WARN`, `CRIT`, `DOWN`, `PAUSED` (urządzenie wyłączone). Status urządzenia to najgorszy ze stanów składowych.
- **DOWN:** `now > last_seen + interval × missed_threshold + grace`. Ocenia to `app:tick`; próg globalny jest w ustawieniach, można go nadpisać per urządzenie.
- **Reguły** są oceniane przy odbiorze heartbeatu. Cel reguły może mieć jeden `*`, np. `disk.*.used_pct` obejmuje wszystkie dyski, a każdy ma osobny stan.
  Anty-flapping: N kolejnych próbek potrzebnych do zmiany stanu (w obie strony). Histereza: powrót dopiero za progiem pomniejszonym o margines.
- **Domyślne reguły** nowego urządzenia: dysk, i-węzły, pamięć, temperatura CPU i dysków, SMART, realokacje, NVMe, RAID, zasilanie RPi, wiek backupu (> 26 h WARN, > 50 h CRIT) i status checków.
- **Powiadomienia** idą tylko przy zmianie stanu względem ostatnio zgłoszonego, plus przypomnienia (domyślnie co 6 h, 0 = wyłączone) i „odzyskano”.
  Zmiany z jednej oceny trafiają do jednego maila per odbiorca. Subskrypcja „od CRIT” traktuje WARN jak OK.
- **Tryb serwisowy** (`muted_until`): zdarzenia są zapisywane, a powiadomienia wstrzymane. Po końcu wyciszenia wychodzi jedno powiadomienie o bieżącym stanie, jeśli różni się od ostatnio zgłoszonego.
- **Metryka, która przestała być raportowana** (np. odłączony dysk), jest zamykana jako OK po 24 h, o ile urządzenie się melduje.
- **Outbox:** wiadomość jest zapisywana przed wysyłką. Błąd SMTP oznacza ponowienie z backoffem (1, 2, 5, 10, 30 min, potem co godzinę) przez ~2 doby.

### Komendy

| Komenda | Cron | Opis |
|---------|------|------|
| `app:tick` | co minutę | DOWN, przypomnienia, wysyłka outboxa, ping `WATCHDOG_URL`, zapis czasu ticku |
| `app:notify:test <email>` | – | Natychmiastowy testowy e-mail (sprawdzenie `MAILER_DSN`) |

## Agent

Agent w bashu (Debian / Raspberry Pi OS, systemd) jest w katalogu [`agent/`](agent/README.md). Panel serwuje go jako `/agent/halo-ziemia-agent.tar.gz`,
a gotowy snippet instalacyjny z adresem jest na stronie urządzenia. Kontrakt pluginów checków opisuje [agent/README.md](agent/README.md#pluginy-checków--kontrakt).

## Struktura

- `src/Entity`, `src/Enum`, `src/Repository`: model danych
- `src/Controller/Admin`: panel (Twig)
- `src/Command`: komendy konsolowe (`app:user:create`, później `app:tick`, `app:rollup`, `app:retention`)
- `assets/`: CSS/JS przez AssetMapper (bez Node, bez CDN)
- `agent/`: agent bash dla urządzeń (testy: `composer agent`, czyli shellcheck i bats)
- `deploy/`: miejsce na skrypty wdrożeniowe (etap 6)
