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

`composer qa` uruchamia PHP-CS-Fixer (dry-run), PHPStan (level max) oraz PHPUnit. Przed testami tworzy i migruje bazę `halo_test`.
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
| `WATCHDOG_URL`         | Opcjonalny URL pingowany po każdym udanym `app:tick` |
| `LOCK_DSN`             | Domyślnie `flock` (pliki w `var/`) |

## Struktura

- `src/Entity`, `src/Enum`, `src/Repository`: model danych
- `src/Controller/Admin`: panel (Twig)
- `src/Command`: komendy konsolowe (`app:user:create`, później `app:tick`, `app:rollup`, `app:retention`)
- `assets/`: CSS/JS przez AssetMapper (bez Node, bez CDN)
- `agent/`: agent bash dla urządzeń (etap 5)
- `deploy/`: miejsce na skrypty wdrożeniowe (etap 6)
