#!/usr/bin/env bash
# Wdrożenie paczki halo-ziemia.tar.gz zbudowanej przez deploy.ps1. Uruchamiane na serwerze:
#   PHP=/usr/local/bin/php85 bash ~/domains/<domena>/deploy.sh
# Leży w katalogu domeny, obok paczki. Układ katalogu domeny (~/domains/<domena>/):
#   deploy.sh, halo-ziemia.tar.gz   ten skrypt i paczka (paczka usuwana po wdrożeniu)
#   shared/.env.local    konfiguracja (szablon: deploy/env.local.example w repo); poza katalogiem WWW
#   shared/backup/       kopie bazy sprzed migracji (ostatnie 5; usuwanie: bash deploy.sh --clean-backups)
#   public_html/         katalog WWW. Przy włączonej separacji domen (open_basedir) PHP widzi tylko jego,
#                        dlatego cała aplikacja leży w środku, a z sieci dostępne jest tylko app/public/:
#     .htaccess          generowany przez ten skrypt: każdy adres -> app/public/
#     app/               bieżąca wersja (cron: public_html/app/bin/console); app/.htaccess: Require all denied
#     app_prev/          poprzednia wersja (rollback: mv app app_bad && mv app_prev app)
#     log/               logi Symfony (app/var/log -> ../../log, przetrwają wdrożenia)
set -euo pipefail

PHP=${PHP:-/usr/local/bin/php85}   # samo `php` w CLI na hostingu to zwykle starsza wersja
PHP_MIN=8.5.0
cd "$(dirname "$(readlink -f "$0")")"

PACKAGE=halo-ziemia.tar.gz
WEB=public_html
APP=$WEB/app
PREV=$WEB/app_prev
BAD=$WEB/app_bad
NEW=$WEB/app_new
LOGS=$WEB/log
HTACCESS_MARK='# halo-ziemia: plik zarządzany przez deploy.sh'
ENV_LOCAL=shared/.env.local
BACKUPS=shared/backup
KEEP_BACKUPS=5

step() { printf '\n== %s\n' "$1"; }
warn() { printf 'UWAGA: %s\n' "$1" >&2; }
fail() { printf 'BŁĄD: %s\n' "$1" >&2; exit 1; }

# Wartość zmiennej z shared/.env.local (ostatnie wystąpienie, bez cudzysłowów).
env_local() { sed -n "s/^$1=//p" "$ENV_LOCAL" | tail -n 1 | tr -d "\"'\r"; }

# Ochrona katalogu: konto SSH może obsługiwać też inne strony. Skrypt działa tylko w katalogu domeny,
# dla której założono shared/.env.local – nazwa katalogu musi być równa hostowi z APP_BASE_URL.
[ -f "$ENV_LOCAL" ] || fail "brak $ENV_LOCAL w $(pwd) – to nie jest katalog domeny halo-ziemia (szablon: deploy/env.local.example)"
BASE_URI=$(env_local APP_BASE_URL)
BASE_URI=${BASE_URI%/}
host=${BASE_URI#*://}
host=${host%%/*}
host=${host%%:*}
[ -n "$host" ] || fail "brak APP_BASE_URL w $ENV_LOCAL"
[ "$host" = "$(basename "$(pwd)")" ] \
    || fail "APP_BASE_URL ($BASE_URI) nie pasuje do katalogu $(pwd) – deploy.sh musi leżeć w ~/domains/$host"

# Jedno wdrożenie naraz: blokada na pliku, zwalniana przez system przy każdym zakończeniu skryptu.
command -v flock > /dev/null || fail "brak polecenia: flock"
exec 9> shared/.deploy.lock
flock -n 9 || fail "inne wdrożenie właśnie trwa (blokada shared/.deploy.lock)"

MODE=${1:-deploy}
[ $# -le 1 ] || fail "za dużo argumentów"
case "$MODE" in
    deploy | --clean-backups) ;;
    *) fail "nieznany argument: $MODE (dostępne: --clean-backups)" ;;
esac

if [ "$MODE" = --clean-backups ]; then
    shopt -s nullglob
    files=("$BACKUPS"/*.sql.gz)
    if [ ${#files[@]} -eq 0 ]; then
        printf 'Brak kopii w %s/\n' "$BACKUPS"
        exit 0
    fi
    ls -lh "${files[@]}"
    rm -f "${files[@]}"
    printf 'Usunięto kopii: %d\n' "${#files[@]}"
    exit 0
fi

check_server() {
    local available
    available=$(compgen -G '/usr/local/bin/php[0-9]*' | tr '\n' ' ' || true)
    [ -x "$PHP" ] || fail "brak PHP CLI: $PHP (ustaw ścieżkę: PHP=/ścieżka bash deploy.sh; dostępne: ${available:-brak})"
    "$PHP" -r "exit(version_compare(PHP_VERSION, '$PHP_MIN', '>=') ? 0 : 1);" \
        || fail "$PHP to PHP $("$PHP" -r 'echo PHP_VERSION;'), wymagane >= $PHP_MIN"
    local cmd
    for cmd in "$@"; do
        command -v "$cmd" > /dev/null || fail "brak polecenia: $cmd"
    done
    printf 'PHP %s, domena %s\n' "$("$PHP" -r 'echo PHP_VERSION;')" "$host" >&2
}

console() { (cd "$1" && "$PHP" bin/console --no-interaction "${@:2}"); }

# Plik opcji klienta MySQL ($2) z DATABASE_URL (Symfony Dotenv: .env + .env.local z katalogu $1); wypisuje nazwę
# bazy. Hasło trafia do pliku (prawa 600), nie do wiersza poleceń widocznego w `ps`.
db_options() {
    # shellcheck disable=SC2016  # kod PHP w apostrofach celowo
    (cd "$1" && "$PHP" -r '
        require "vendor/autoload.php";
        (new Symfony\Component\Dotenv\Dotenv())->bootEnv(".env");
        $u = parse_url($_SERVER["DATABASE_URL"] ?? "");
        if (!is_array($u) || !isset($u["user"], $u["path"])) { fwrite(STDERR, "nieprawidłowy DATABASE_URL\n"); exit(1); }
        $q = fn (string $v): string => "\"" . addcslashes($v, "\\\"") . "\"";
        file_put_contents($argv[1], "[client]\nuser=" . $q(urldecode($u["user"]))
            . "\npassword=" . $q(urldecode($u["pass"] ?? ""))
            . "\nhost=" . $q($u["host"] ?? "localhost")
            . "\nport=" . ($u["port"] ?? 3306) . "\n");
        echo urldecode(ltrim($u["path"], "/"));
    ' -- "$2")
}

DUMP_OPTIONS=(--single-transaction --routines --triggers --no-tablespaces --default-character-set=utf8mb4)
# Duże tabele metryk pomijamy w kopii przed migracją (odtwarzalne; kopia ma chronić konfigurację i historię).
DUMP_DATA_SKIP=(sample sample_hourly)

# Zrzut bazy (z konfiguracji katalogu $1) do shared/backup/; zostaje ostatnie $KEEP_BACKUPS kopii.
dump_db() {
    local cnf db file old ignore=() table
    mkdir -p "$BACKUPS"
    chmod 700 "$BACKUPS"
    cnf=$(mktemp)
    if ! db=$(db_options "$1" "$cnf"); then
        rm -f "$cnf"
        fail "nie udało się odczytać DATABASE_URL"
    fi
    for table in "${DUMP_DATA_SKIP[@]}"; do ignore+=("--ignore-table=$db.$table"); done
    file="$BACKUPS/db-$(date +%Y%m%d-%H%M%S).sql.gz"
    if ! (umask 077 && {
        mysqldump --defaults-extra-file="$cnf" "${DUMP_OPTIONS[@]}" "${ignore[@]}" "$db"
        mysqldump --defaults-extra-file="$cnf" "${DUMP_OPTIONS[@]}" --no-data "$db" "${DUMP_DATA_SKIP[@]}"
    } | gzip > "$file"); then
        rm -f "$cnf" "$file"
        fail "kopia bazy nie powiodła się – baza NIE została zmieniona"
    fi
    rm -f "$cnf"
    printf 'Kopia: %s (%s; bez danych tabel %s)\n' "$file" "$(du -h "$file" | cut -f1)" "${DUMP_DATA_SKIP[*]}"

    old=$(find "$BACKUPS" -maxdepth 1 -name 'db-*.sql.gz' | sort -r | tail -n +$((KEEP_BACKUPS + 1)))
    if [ -n "$old" ]; then
        printf 'Usuwam starsze kopie:\n%s\n' "$old"
        printf '%s\n' "$old" | xargs rm -f
    fi
}

step "Kontrola serwera"
check_server tar gzip mysqldump curl
[ -f "$PACKAGE" ] || fail "brak $PACKAGE w $(pwd)"
[ ! -L "$WEB" ] || fail "$WEB to symlink (stary układ). Usuń go i utwórz zwykły katalog: rm $WEB && mkdir $WEB"
[ -d "$WEB" ] || fail "brak katalogu $WEB w $(pwd) (tworzy go panel hostingu razem z domeną)"
perms=$(stat -c %a "$ENV_LOCAL")
[ "$perms" = 600 ] || warn "$ENV_LOCAL ma prawa $perms (zalecane 600: chmod 600 $ENV_LOCAL)"

# Powrót do app_prev/ po nieudanym przełączeniu; nieudana wersja zostaje w app_bad/ do analizy.
rollback() {
    if [ -d "$PREV" ]; then
        printf '%s – przywracam poprzednią wersję (nieudana w %s/)\n' "$1" "$BAD" >&2
        rm -rf "$BAD"
        mv "$APP" "$BAD"
        mv "$PREV" "$APP"
        fail "wdrożenie wycofane. Migracje bazy NIE zostały cofnięte (kopia w $BACKUPS/, jeśli były nowe)"
    fi
    fail "$1 (brak $PREV/ – nie ma do czego wrócić)"
}

# public_html/.htaccess: cały ruch do app/public/. Reszta katalogu WWW (kod, .env.local, vendor, logi)
# nie ma adresu. Druga warstwa: app*/.htaccess i log/.htaccess z Require all denied.
write_web_htaccess() {
    local file=$WEB/.htaccess backup
    if [ -f "$file" ] && ! grep -qF "$HTACCESS_MARK" "$file"; then
        backup="public_html.htaccess.orig-$(date +%Y%m%d%H%M%S)"
        printf 'Obcy %s – przenoszę do %s\n' "$file" "$backup"
        mv "$file" "$backup"
    fi
    cat > "$file.tmp" << EOF
$HTACCESS_MARK (nadpisywany przy każdym wdrożeniu).
# Cała aplikacja leży w katalogu WWW (separacja domen), ale z sieci dostępne jest tylko app/public/.
<IfModule !mod_rewrite.c>
    Require all denied
</IfModule>
<IfModule mod_rewrite.c>
    RewriteEngine On
    # Odnawianie certyfikatu Let's Encrypt
    RewriteRule ^\.well-known/acme-challenge/ - [L]
    RewriteRule ^app/public/ - [L]
    RewriteRule ^(.*)\$ app/public/\$1 [L]
</IfModule>
EOF
    mv "$file.tmp" "$file"
    mkdir -p "$LOGS"
    printf 'Require all denied\n' > "$LOGS/.htaccess"
}

# Treść odpowiedzi dla adresu, gdy HTTP 200 (kilka prób – serwer może chwilę ładować nową wersję).
http_get() {
    local body code tmp
    tmp=$(mktemp)
    for _ in 1 2 3; do
        code=$(curl -sS -o "$tmp" -w '%{http_code}' --max-time 15 "$1" 2> /dev/null || true)
        [ "$code" = 200 ] && break
        sleep 2
    done
    body=$(cat "$tmp")
    rm -f "$tmp"
    [ "$code" = 200 ] || { printf 'HTTP %s' "${code:-000}"; return 1; }
    printf '%s' "$body"
}

step "Rozpakowanie do $NEW/"
rm -rf "$NEW"
mkdir "$NEW"
tar -xzf "$PACKAGE" -C "$NEW"

step "Konfiguracja z shared/"
cp "$ENV_LOCAL" "$NEW/.env.local"
chmod 600 "$NEW/.env.local"
[ -f "$NEW/.htaccess" ] || fail "w paczce brak .htaccess blokującego katalog aplikacji – zbuduj ją ponownie"
mkdir -p "$LOGS" "$NEW/var"
ln -sfn ../../log "$NEW/var/log"

step "Kontrola konfiguracji (cache:warmup w $NEW/)"
console "$NEW" cache:warmup

step "Migracje bazy"
if console "$NEW" doctrine:migrations:up-to-date; then
    printf 'Brak nowych migracji – kopia bazy niepotrzebna.\n'
else
    # Wiersz "| Executed | 0 |" (nie "Executed Unavailable") = żadna migracja jeszcze nie wykonana.
    if console "$NEW" doctrine:migrations:status --no-ansi | grep -qE '\|[[:space:]]+Executed[[:space:]]+\|[[:space:]]+0[[:space:]]+\|'; then
        printf 'Pierwsze wdrożenie – pusta baza, kopia niepotrzebna.\n'
    else
        step "Kopia bazy przed migracjami"
        dump_db "$NEW"
    fi
    step "Uruchamianie migracji"
    console "$NEW" doctrine:migrations:migrate --allow-no-migration
fi

# Cache Symfony zawiera ścieżki bezwzględne – po przeniesieniu katalogu trzeba go zbudować od nowa.
rm -rf "$NEW/var/cache"

step "Przełączenie wersji"
rm -rf "$PREV"
if [ -d "$APP" ]; then mv "$APP" "$PREV"; fi
mv "$NEW" "$APP"
write_web_htaccess

console "$APP" cache:warmup || rollback "cache:warmup po przełączeniu nie przeszedł"

step "Healthcheck ($BASE_URI)"
health=$(http_get "$BASE_URI/health") || rollback "$BASE_URI/health: $health"
printf 'health: %s\n' "$health"
case $health in
    *'"secure":true'*) ;;
    *) warn "aplikacja nie widzi HTTPS (\"secure\":false) – API odrzuci agentów. Sprawdź SSL domeny albo TRUSTED_PROXIES w $ENV_LOCAL" ;;
esac
# Baza tylko raportowana: migracje już przeszły, powrót do starego kodu niczego by tu nie naprawił.
if db=$(http_get "$BASE_URI/health/db"); then printf 'db: %s\n' "$db"; else warn "health/db: $db – sprawdź bazę i $LOGS/prod.log"; fi

step "Stan aplikacji"
console "$APP" app:status --no-ansi || warn "app:status nie powiódł się"

rm -rf "$BAD"
rm -f "$PACKAGE"
step "Gotowe ($(date '+%Y-%m-%d %H:%M:%S'))"
