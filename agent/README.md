# halo-ziemia – agent

Lekki agent w bashu dla Debiana i Raspberry Pi OS. Co minutę (systemd timer) zbiera metryki i wysyła heartbeat do panelu halo-ziemia.
Wymagania: `bash`, `curl`, `jq`, systemd. Opcjonalnie: `smartmontools` (SMART), `mdadm` (RAID), `vcgencmd` (Raspberry Pi).

## Instalacja

W panelu: **Urządzenia → urządzenie → Tokeny i agent → Utwórz token**, potem na urządzeniu:

```bash
curl -fsSL https://monitor.example.com/agent/halo-ziemia-agent.tar.gz | tar xz
cd halo-ziemia-agent
sudo ./install.sh --url https://monitor.example.com
```

Instalator:
- sprawdza zależności (podpowiada `sudo apt install jq`),
- pyta o token bez echa, więc token nie trafia do historii powłoki,
- zapisuje `/etc/halo-ziemia/agent.conf` z `chmod 600`,
- instaluje `halo-ziemia-agent.service` i `.timer`, wysyła testowy heartbeat i włącza timer.

Ponowne uruchomienie `install.sh` aktualizuje agenta, a konfiguracja i checki zostają. Zmiana tokenu lub adresu: `sudo ./install.sh --reconfigure`.
Odinstalowanie: `sudo /usr/local/lib/halo-ziemia/uninstall.sh`, z usunięciem konfiguracji: `--purge`.

Paczkę rozpakuj gdziekolwiek (np. w katalogu domowym albo `/tmp`) – instalator kopiuje wszystko do ścieżek systemowych, więc po instalacji katalog `halo-ziemia-agent/` można usunąć. Do aktualizacji pobierz paczkę z panelu ponownie.

| Ścieżka | Zawartość |
|---|---|
| `/usr/local/bin/halo-ziemia-agent` | agent |
| `/usr/local/lib/halo-ziemia/` | biblioteka zbierania metryk, `uninstall.sh` |
| `/etc/halo-ziemia/` | `agent.conf` z tokenem (`600`, tylko root), `checks.d/` |
| `/var/lib/halo-ziemia/` | stan (cache SMART) |
| `/etc/systemd/system/halo-ziemia-agent.{service,timer}` | uruchamianie co minutę |

## Obsługa

```bash
sudo halo-ziemia-agent --dry-run   # pokaż payload (nic nie wysyła)
sudo halo-ziemia-agent --test      # wyślij teraz i pokaż odpowiedź serwera
journalctl -u halo-ziemia-agent -f # logi (błędy; udane wysyłki są ciche)
systemctl list-timers halo-ziemia-agent.timer
```

Kody wyjścia: `0` OK, `1` błąd konfiguracji, `2` błąd połączenia (po 2 ponowieniach), `3` serwer odrzucił dane.

## Co jest zbierane

| Sekcja | Źródło |
|--------|--------|
| uptime, load, RAM/swap | `/proc/uptime`, `/proc/loadavg`, `/proc/meminfo` |
| wykorzystanie CPU, iowait (średnia od poprzedniego uruchomienia; pierwsze uruchomienie tylko zapisuje liczniki w `cpu.stat`) | `/proc/stat` |
| temperatura CPU | najwyższa z `/sys/class/thermal/thermal_zone*/temp`, fallback `vcgencmd measure_temp` |
| dyski i i-węzły | `df` (bez tmpfs/overlay/squashfs itp.; bind mounty liczone raz; opcjonalnie tylko `HZ_MOUNTS`) |
| SMART | `smartctl -H -A -j -n standby` (uśpione dyski nie są wybudzane; odczyt co `HZ_SMART_INTERVAL`, domyślnie 10 min) |
| RAID | `/proc/mdstat` (stan, degradacja, resync/recovery) |
| Raspberry Pi | `vcgencmd get_throttled` |
| IP, hostname | `hostname -I`, `hostname` |
| checki | pluginy z `/etc/halo-ziemia/checks.d/` |

Czas pomiaru ustala serwer w chwili odbioru, więc zegar urządzenia nie ma znaczenia.
Agent nie buforuje danych lokalnie: brak heartbeatów jest sygnałem dla serwera.

## Bezpieczeństwo

- Token jest tylko w `/etc/halo-ziemia/agent.conf` (root, `600`). Do `curl` trafia przez stdin (`curl -K -`), więc nie widać go w `ps` ani w logach.
- Plik konfiguracyjny jest parsowany (`KLUCZ="wartość"`), a nie wykonywany.
- Agent odmawia wysyłki po HTTP, chyba że ustawisz `HZ_ALLOW_HTTP=1` (tylko sieć lokalna lub dev).
- Usługa działa jako root (smartctl i vcgencmd), ale z ograniczeniami systemd: `ProtectSystem=full`, `ProtectHome=read-only`, `NoNewPrivileges`.

## Odporność

- `curl --max-time` (domyślnie 15 s) i 2 ponowienia (po 2 s i 5 s) przy błędzie sieci, 5xx lub 429.
- Gdy serwer odrzuci dane (`422`, np. nietypowa nazwa dysku), agent wysyła zastępczy `ping`. Urządzenie nie zostanie wtedy fałszywie uznane za DOWN, a błąd trafi do journald.
- Każdy kolektor jest izolowany: awaria jednego (np. `smartctl`) nie blokuje reszty heartbeatu.

## Pluginy checków – kontrakt

Każdy **wykonywalny** plik w `/etc/halo-ziemia/checks.d/` to jeden check. Pliki `*.example`, `*.disabled`, `*~` są pomijane.

- **Nazwa checka** to nazwa pliku bez `.sh`. Dozwolone znaki: `[a-z0-9_.-]`, maksymalnie 64. Przykład: `backup.sh` daje check `backup`.
- **Limit czasu:** `HZ_CHECK_TIMEOUT`, domyślnie 20 s. Po przekroczeniu check dostaje status `fail` z komunikatem „przekroczono limit czasu”.
- **Uruchamianie:** jako root, przy każdym heartbeacie (co minutę). Kosztowne sprawdzenia cache'uj sam.

Plugin zwraca wynik na jeden z dwóch sposobów.

**A. JSON na stdout** (zalecany):

```json
{"status": "ok", "message": "rsync OK, 12 GB", "last_success_age_s": 5400, "metrics": {"backup.size_b": 12000000000}}
```

| Pole | Wymagane | Opis |
|------|----------|------|
| `status` | tak | `ok`, `warn` albo `fail` (na serwerze: OK / WARN / CRIT) |
| `message` | nie | krótki opis, max 500 znaków |
| `last_success_age_s` | nie | sekundy od ostatniego sukcesu. Serwer tworzy z tego metrykę `check.<nazwa>.age_s` |
| `metrics` | nie | dodatkowe metryki liczbowe `{"klucz": liczba}`. Klucze `[a-z0-9_./-]`, nie nadpisują wbudowanych |

**B. Kod wyjścia:** `0` to ok, `1` to warn, każdy inny to fail. Pierwsza linia stdout staje się komunikatem.

Na serwerze nowe urządzenie ma domyślne reguły:
- **„Check”** (cel `*`): status każdego checka przekłada się na stan alarmowy;
- **„Wiek backupu”** (`check.backup.age_s`): powyżej 26 h WARN, powyżej 50 h CRIT.

Progi zmienisz w panelu, bez zmian na urządzeniu.

Gotowy przykład: [`checks.d/backup.sh.example`](checks.d/backup.sh.example). Działa na podstawie pliku-znacznika, którego mtime ustawia skrypt backupu po udanym przebiegu.

## Konfiguracja

Wszystkie opcje: [`agent.conf.example`](agent.conf.example).

## Testy (dla deweloperów)

```bash
shellcheck -x halo-ziemia-agent lib/collect.sh install.sh uninstall.sh
bats tests/
```
