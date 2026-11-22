#!/usr/bin/env bash
#
# Instalator agenta halo-ziemia (Debian / Raspberry Pi OS, systemd).
#
#   sudo ./install.sh --url https://monitor.example.com
#
# Opcje:
#   --url URL          adres panelu (wymagany przy pierwszej instalacji)
#   --token-file PLIK  wczytaj token z pliku zamiast pytać (np. automatyzacja)
#   --reconfigure      zapytaj ponownie o adres i token mimo istniejącej konfiguracji
#   --no-start         nie uruchamiaj testu ani timera
#
# Ponowne uruchomienie = aktualizacja (konfiguracja i checki zostają).
# Token wpisuje się interaktywnie (bez echa), więc nie trafia do historii powłoki.

set -euo pipefail

PREFIX_BIN=/usr/local/bin
PREFIX_LIB=/usr/local/lib/halo-ziemia
PREFIX_DOC=/usr/local/share/doc/halo-ziemia
CONF_DIR=/etc/halo-ziemia
CONF=$CONF_DIR/agent.conf
STATE_DIR=/var/lib/halo-ziemia
UNIT_DIR=/etc/systemd/system

SRC=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
URL=""
TOKEN_FILE=""
RECONFIGURE=0
START=1

say() { printf '\033[1;36m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33mUwaga:\033[0m %s\n' "$*" >&2; }
die() { printf '\033[1;31mBłąd:\033[0m %s\n' "$*" >&2; exit 1; }

while (($# > 0)); do
    case $1 in
        --url) URL=${2:-}; shift 2 ;;
        --token-file) TOKEN_FILE=${2:-}; shift 2 ;;
        --reconfigure) RECONFIGURE=1; shift ;;
        --no-start) START=0; shift ;;
        -h | --help) sed -n '3,16p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) die "nieznana opcja: $1 (zobacz --help)" ;;
    esac
done

((EUID == 0)) || die "uruchom jako root: sudo $0 $*"
command -v systemctl > /dev/null || die "wymagany systemd"

missing=()
for cmd in curl jq; do command -v "$cmd" > /dev/null || missing+=("$cmd"); done
if ((${#missing[@]} > 0)); then
    die "brak programów: ${missing[*]}. Zainstaluj: sudo apt install ${missing[*]}"
fi
command -v smartctl > /dev/null || warn "brak smartctl – dane SMART nie będą zbierane (sudo apt install smartmontools)"

# --- pliki ---
say "Instaluję pliki agenta"
install -d -m 755 "$PREFIX_LIB" "$PREFIX_DOC"
install -m 644 "$SRC/lib/collect.sh" "$PREFIX_LIB/collect.sh"
install -m 755 "$SRC/halo-ziemia-agent" "$PREFIX_BIN/halo-ziemia-agent"
install -m 755 "$SRC/uninstall.sh" "$PREFIX_LIB/uninstall.sh"
install -m 644 "$SRC/README.md" "$PREFIX_DOC/README.md"
install -d -m 700 "$CONF_DIR" "$CONF_DIR/checks.d" "$STATE_DIR"
for example in "$SRC"/checks.d/*.example; do
    [[ -e $example ]] || continue
    target="$CONF_DIR/checks.d/$(basename "$example")"
    [[ -e $target ]] || install -m 600 "$example" "$target"
done
install -m 644 "$SRC/agent.conf.example" "$CONF_DIR/agent.conf.example"

# --- konfiguracja ---
read_conf_value() { sed -n "s/^$1=\"\{0,1\}\([^\"]*\)\"\{0,1\}$/\1/p" "$CONF" 2> /dev/null | tail -n 1; }

if [[ -f $CONF && $RECONFIGURE == 0 ]]; then
    say "Konfiguracja $CONF już istnieje – zostawiam (zmiana: --reconfigure)"
    if [[ -n $URL && $URL != "$(read_conf_value HZ_URL)" ]]; then
        sed -i "s|^HZ_URL=.*|HZ_URL=\"${URL%/}\"|" "$CONF"
        say "Zaktualizowano HZ_URL na ${URL%/}"
    fi
else
    if [[ -z $URL ]]; then
        read -r -p "Adres panelu (https://…): " URL < /dev/tty
    fi
    URL=${URL%/}
    [[ $URL =~ ^https?://[^[:space:]]+$ ]] || die "niepoprawny adres: $URL"
    [[ $URL == https://* ]] || warn "adres bez HTTPS – agent odmówi wysyłki (chyba że ustawisz HZ_ALLOW_HTTP=1)"

    if [[ -n $TOKEN_FILE ]]; then
        TOKEN=$(tr -d '[:space:]' < "$TOKEN_FILE")
    else
        read -r -s -p "Token urządzenia (hz_…, wklej i Enter): " TOKEN < /dev/tty
        echo
    fi
    [[ $TOKEN =~ ^hz_[A-Za-z0-9]{40,128}$ ]] || die "token ma niepoprawny format (oczekiwano hz_ + min. 40 znaków)"

    say "Zapisuję $CONF (chmod 600)"
    (
        umask 077
        {
            echo "# halo-ziemia agent – wygenerowane przez install.sh $(date '+%Y-%m-%d %H:%M')"
            echo "# Wszystkie opcje: $CONF_DIR/agent.conf.example"
            printf 'HZ_URL="%s"\n' "$URL"
            printf 'HZ_TOKEN="%s"\n' "$TOKEN"
        } > "$CONF.tmp"
        mv -f "$CONF.tmp" "$CONF"
    )
    unset TOKEN
fi
chmod 600 "$CONF"

# --- systemd ---
say "Instaluję usługę systemd"
install -m 644 "$SRC/systemd/halo-ziemia-agent.service" "$UNIT_DIR/halo-ziemia-agent.service"
install -m 644 "$SRC/systemd/halo-ziemia-agent.timer" "$UNIT_DIR/halo-ziemia-agent.timer"
systemctl daemon-reload

if ((START == 0)); then
    say "Pominięto uruchomienie (--no-start). Start: sudo systemctl enable --now halo-ziemia-agent.timer"
    exit 0
fi

say "Testowy heartbeat"
if ! "$PREFIX_BIN/halo-ziemia-agent" --test; then
    die "test nieudany – sprawdź adres i token: sudo nano $CONF, potem: sudo halo-ziemia-agent --test"
fi

systemctl enable --now halo-ziemia-agent.timer > /dev/null
say "Gotowe. Agent wysyła heartbeat co minutę."
echo "    Logi:        journalctl -u halo-ziemia-agent -f"
echo "    Podgląd:     sudo halo-ziemia-agent --dry-run"
echo "    Checki:      $CONF_DIR/checks.d/ (przykład: backup.sh.example)"
echo "    Usunięcie:   sudo $PREFIX_LIB/uninstall.sh [--purge]"
echo "    Katalog z rozpakowaną paczką ($SRC) nie jest już potrzebny."
