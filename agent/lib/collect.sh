# shellcheck shell=bash
#
# halo-ziemia – kolektory metryk agenta.
# Każda funkcja hz_* wypisuje fragment JSON albo "null" (brak danych / brak narzędzia).
# Ścieżki /proc i /sys oraz katalog stanu można podmienić (testy): HZ_PROC, HZ_SYS, HZ_STATE_DIR.

HZ_PROC=${HZ_PROC:-/proc}
HZ_SYS=${HZ_SYS:-/sys}
HZ_STATE_DIR=${HZ_STATE_DIR:-/var/lib/halo-ziemia}
HZ_SMART_INTERVAL=${HZ_SMART_INTERVAL:-600}
HZ_CPU_MAX_GAP=${HZ_CPU_MAX_GAP:-600}
HZ_CHECK_TIMEOUT=${HZ_CHECK_TIMEOUT:-20}
HZ_CHECKS_DIR=${HZ_CHECKS_DIR:-/etc/halo-ziemia/checks.d}
HZ_MOUNTS=${HZ_MOUNTS:-}
HZ_SMART_DEVICES=${HZ_SMART_DEVICES:-}

# Systemy plików pomijane przy zajętości dysków.
HZ_EXCLUDED_FS=(tmpfs devtmpfs overlay squashfs ramfs efivarfs autofs nsfs proc sysfs cgroup cgroup2 fuse.lxcfs fuse.gvfsd-fuse fuse.portal tracefs debugfs securityfs pstore bpf configfs mqueue hugetlbfs devpts binfmt_misc rpc_pipefs nfsd zram)

hz_uptime() {
    local up _
    [[ -r $HZ_PROC/uptime ]] || { echo null; return; }
    read -r up _ < "$HZ_PROC/uptime" || { echo null; return; }
    [[ $up =~ ^[0-9]+(\.[0-9]+)?$ ]] || { echo null; return; }
    echo "${up%%.*}"
}

hz_load() {
    local l1 l5 l15 _
    [[ -r $HZ_PROC/loadavg ]] || { echo null; return; }
    read -r l1 l5 l15 _ < "$HZ_PROC/loadavg" || { echo null; return; }
    jq -cn --arg a "$l1" --arg b "$l5" --arg c "$l15" '[$a, $b, $c] | map(tonumber)' 2>/dev/null || echo null
}

hz_mem() {
    [[ -r $HZ_PROC/meminfo ]] || { echo null; return; }
    awk '
        /^MemTotal:/     { total = $2 }
        /^MemAvailable:/ { avail = $2 }
        /^SwapTotal:/    { stotal = $2 }
        /^SwapFree:/     { sfree = $2 }
        END {
            if (total == "" || avail == "") { print "null"; exit }
            printf "{\"total_kb\":%d,\"available_kb\":%d,\"swap_total_kb\":%d,\"swap_used_kb\":%d}\n", total, avail, stotal, stotal - sfree
        }' "$HZ_PROC/meminfo"
}

# Najwyższa temperatura spośród stref termicznych (°C); na RPi fallback do vcgencmd.
hz_cpu_temp() {
    local zone value max=""
    for zone in "$HZ_SYS"/class/thermal/thermal_zone*/temp; do
        [[ -r $zone ]] || continue
        read -r value < "$zone" || continue
        [[ $value =~ ^-?[0-9]+$ ]] || continue
        if [[ -z $max || $value -gt $max ]]; then max=$value; fi
    done
    if [[ -n $max ]]; then
        awk -v m="$max" 'BEGIN { printf "%.1f\n", m / 1000 }'
        return
    fi
    if command -v vcgencmd > /dev/null 2>&1; then
        # "temp=48.3'C"
        value=$(vcgencmd measure_temp 2> /dev/null | sed -n "s/^temp=\([0-9.]*\).*/\1/p")
        [[ $value =~ ^[0-9]+(\.[0-9]+)?$ ]] && { echo "$value"; return; }
    fi
    echo null
}

# Wykorzystanie CPU od poprzedniego uruchomienia (czyli średnia z ostatniej minuty) z liczników /proc/stat:
# {"used_pct": praca (bez idle i iowait), "iowait_pct": bezczynność w oczekiwaniu na dysk}.
# Pierwsze uruchomienie, restart systemu albo przerwa > HZ_CPU_MAX_GAP s: null (tylko zapis liczników).
hz_cpu() {
    local cache="$HZ_STATE_DIR/cpu.stat" current previous="" now mtime
    local re='^[0-9]+ [0-9]+ [0-9]+$'
    [[ -r $HZ_PROC/stat ]] || { echo null; return; }
    # Suma: user nice system idle iowait irq softirq steal (guest jest już wliczony w user).
    current=$(awk '$1 == "cpu" { printf "%.0f %.0f %.0f\n", $2 + $3 + $4 + $5 + $6 + $7 + $8 + $9, $5, $6; exit }' "$HZ_PROC/stat")
    [[ $current =~ $re ]] || { echo null; return; }

    if [[ -r $cache ]]; then
        now=$(date +%s)
        mtime=$(stat -c %Y "$cache" 2> /dev/null || echo 0)
        if ((now - mtime <= HZ_CPU_MAX_GAP)); then
            read -r previous < "$cache" || previous=""
        fi
    fi
    if [[ -d $HZ_STATE_DIR && -w $HZ_STATE_DIR ]]; then
        printf '%s\n' "$current" > "$cache.tmp" && mv -f "$cache.tmp" "$cache"
    fi
    [[ $previous =~ $re ]] || { echo null; return; }

    awk -v c="$current" -v p="$previous" 'BEGIN {
        split(c, a, " "); split(p, b, " ")
        total = a[1] - b[1]; idle = a[2] - b[2]; iowait = a[3] - b[3]
        if (total <= 0 || idle < 0) { print "null"; exit }    # restart systemu albo brak upływu czasu
        if (iowait < 0) iowait = 0                             # licznik iowait potrafi się cofnąć
        used = total - idle - iowait
        if (used < 0) used = 0
        printf "{\"used_pct\":%.1f,\"iowait_pct\":%.1f}\n", used * 100 / total, iowait * 100 / total
    }'
}

# Wejście: wyjście `df -B1 --output=source,fstype,size,used,ipcent,target` (bez nagłówka).
# Pomija punkty montowania z białymi znakami (serwer ich nie przyjmuje) i spoza HZ_MOUNTS (jeśli ustawione).
hz_parse_df() {
    jq -R -s -c --arg mounts "$HZ_MOUNTS" '
        ($mounts | split(" ") | map(select(length > 0))) as $only
        | split("\n")
        | map(select(length > 0)
              | capture("^(?<device>\\S+)\\s+(?<fstype>\\S+)\\s+(?<size>[0-9]+)\\s+(?<used>[0-9]+)\\s+(?<ip>\\S+)\\s+(?<mount>/.*)$")
              | select(.mount | test("\\s") | not)
              | select((.mount | length) <= 150)
              | select(($only | length) == 0 or (.mount as $m | $only | index($m)) != null)
              | {
                  mount,
                  device,
                  size_b: (.size | tonumber),
                  used_b: (.used | tonumber),
                  inodes_used_pct: (if (.ip | test("^[0-9]+%$")) then (.ip | rtrimstr("%") | tonumber) else null end)
                })
        | unique_by(.mount)
        # Bind mounty: ten sam system plików pod kilkoma ścieżkami – zostaw najkrótszą ścieżkę.
        | group_by(.device) | map(min_by(.mount | length)) | sort_by(.mount)
        | if length == 0 then null else . end
    ' 2> /dev/null || echo null
}

hz_disks() {
    local args=() fs
    for fs in "${HZ_EXCLUDED_FS[@]}"; do args+=(-x "$fs"); done
    LC_ALL=C df -B1 --output=source,fstype,size,used,ipcent,target "${args[@]}" 2> /dev/null | tail -n +2 | hz_parse_df
}

# Filtr jq: wynik `smartctl -H -A -j` → wpis SMART (wartości null, gdy brak).
hz_smart_filter() {
    jq -c --arg dev "$1" '
        def attr($id): ((.ata_smart_attributes.table // []) | map(select(.id == $id)) | .[0].raw.value) // null;
        {
            device: $dev,
            passed: .smart_status.passed,
            temp_c: .temperature.current,
            power_on_hours: .power_on_time.hours,
            reallocated: attr(5),
            pending: attr(197),
            percentage_used: .nvme_smart_health_information_log.percentage_used
        }
        | select([.passed, .temp_c, .power_on_hours, .reallocated, .pending, .percentage_used] | any(. != null))
    ' 2> /dev/null
}

hz_smart_collect() {
    local scan entries=() entry name type out
    if [[ -n $HZ_SMART_DEVICES ]]; then
        # "/dev/sda /dev/sdb:sat /dev/nvme0:nvme"
        scan=$(for entry in $HZ_SMART_DEVICES; do
            name=${entry%%:*}
            type=""
            [[ $entry == *:* ]] && type=${entry#*:}
            printf '%s\t%s\n' "$name" "$type"
        done)
    else
        scan=$(smartctl --scan -j 2> /dev/null | jq -r '.devices[]? | [.name, (.type // "")] | @tsv' 2> /dev/null)
    fi

    while IFS=$'\t' read -r name type; do
        [[ -n $name ]] || continue
        # -n standby: nie wybudzaj uśpionych dysków (NAS).
        if [[ -n $type ]]; then
            out=$(smartctl -H -A -j -n standby -d "$type" "$name" 2> /dev/null)
        else
            out=$(smartctl -H -A -j -n standby "$name" 2> /dev/null)
        fi
        entry=$(printf '%s' "$out" | hz_smart_filter "$name")
        [[ -n $entry ]] && entries+=("$entry")
    done <<< "$scan"

    if ((${#entries[@]} == 0)); then
        echo null
    else
        printf '%s\n' "${entries[@]}" | jq -cs '.'
    fi
}

# SMART jest kosztowny – odczyt co HZ_SMART_INTERVAL s, w międzyczasie z cache.
hz_smart() {
    command -v smartctl > /dev/null 2>&1 || { echo null; return; }
    local cache="$HZ_STATE_DIR/smart.json" now mtime tmp
    now=$(date +%s)
    if [[ -r $cache ]]; then
        mtime=$(stat -c %Y "$cache" 2> /dev/null || echo 0)
        if ((now - mtime < HZ_SMART_INTERVAL)); then
            cat "$cache"
            return
        fi
    fi

    tmp=$(hz_smart_collect)
    if [[ -d $HZ_STATE_DIR && -w $HZ_STATE_DIR ]]; then
        printf '%s\n' "$tmp" > "$cache.tmp" && mv -f "$cache.tmp" "$cache"
    fi
    printf '%s\n' "$tmp"
}

# Wejście: /proc/mdstat. Wyjście: [{"name":"md0","state":"clean","degraded":false}, ...]
hz_parse_mdstat() {
    awk '
        /^md[0-9a-z_]+ : / {
            cur = $1; names[++n] = cur; st[cur] = $3; deg[cur] = ($3 == "inactive") ? "true" : "false"; next
        }
        cur != "" && /\[[U_]+\]/ {
            if (match($0, /\[[U_]+\]/)) { s = substr($0, RSTART + 1, RLENGTH - 2); if (index(s, "_") > 0) deg[cur] = "true" }
        }
        cur != "" && /(recovery|resync|reshape|check) *=/ {
            if (match($0, /(recovery|resync|reshape|check)/)) prog[cur] = substr($0, RSTART, RLENGTH)
        }
        /^[[:space:]]*$/ { cur = "" }
        END {
            if (n == 0) { print "null"; exit }
            printf "["
            for (i = 1; i <= n; i++) {
                name = names[i]
                state = (prog[name] != "") ? prog[name] : (st[name] == "active" ? (deg[name] == "true" ? "degraded" : "clean") : st[name])
                printf "%s{\"name\":\"%s\",\"state\":\"%s\",\"degraded\":%s}", (i > 1 ? "," : ""), name, state, deg[name]
            }
            print "]"
        }'
}

hz_raid() {
    [[ -r $HZ_PROC/mdstat ]] || { echo null; return; }
    hz_parse_mdstat < "$HZ_PROC/mdstat"
}

hz_rpi() {
    command -v vcgencmd > /dev/null 2>&1 || { echo null; return; }
    local value
    value=$(vcgencmd get_throttled 2> /dev/null | sed -n 's/^throttled=\(0x[0-9a-fA-F]*\)$/\1/p')
    [[ -n $value ]] || { echo null; return; }
    jq -cn --arg t "$value" '{throttled: $t}'
}

hz_ip() {
    hostname -I 2> /dev/null | jq -R -c 'split(" ") | map(select(length > 0)) | .[0:16] | if length == 0 then null else . end' 2> /dev/null || echo null
}

# Pojedynczy check: wyjście JSON {"status","message","last_success_age_s","metrics"} albo kod wyjścia 0/1/inny.
hz_run_check() {
    local file=$1 name out rc tmp
    name=$(basename "$file")
    name=${name%.sh}
    [[ $name =~ ^[a-z0-9][a-z0-9_.-]{0,63}$ ]] || { echo "halo-ziemia: pomijam check o niedozwolonej nazwie: $name" >&2; return; }

    tmp=$(mktemp)
    timeout "$HZ_CHECK_TIMEOUT" "$file" > "$tmp" 2> /dev/null
    rc=$?
    out=$(head -c 8192 "$tmp")
    rm -f "$tmp"

    jq -cn --arg name "$name" --arg out "$out" --argjson rc "$rc" '
        def by_code: if $rc == 0 then "ok" elif $rc == 1 then "warn" else "fail" end;
        ($out | try fromjson catch null) as $j
        | if ($j | type) == "object" then
            {
                name: $name,
                status: (if ($j.status | IN("ok", "warn", "fail")) then $j.status else by_code end),
                message: (($j.message // null) | if . == null then null else tostring | .[0:500] end),
                last_success_age_s: (if ($j.last_success_age_s | type) == "number" and $j.last_success_age_s >= 0 then ($j.last_success_age_s | floor) else null end),
                metrics: (if ($j.metrics | type) == "object" then ($j.metrics | with_entries(select(.value | type == "number"))) else null end)
            }
          else
            {
                name: $name,
                status: (if $rc == 124 then "fail" else by_code end),
                message: (if $rc == 124 then "przekroczono limit czasu" else ($out | split("\n")[0] | .[0:500] | if . == "" then null else . end) end)
            }
          end
    '
}

hz_checks() {
    local file entries=()
    [[ -d $HZ_CHECKS_DIR ]] || { echo null; return; }
    for file in "$HZ_CHECKS_DIR"/*; do
        [[ -f $file && -x $file ]] || continue
        case $file in *.example | *.disabled | *~ | *.dpkg-*) continue ;; esac
        local entry
        entry=$(hz_run_check "$file")
        [[ -n $entry ]] && entries+=("$entry")
    done
    if ((${#entries[@]} == 0)); then
        echo null
    else
        printf '%s\n' "${entries[@]}" | jq -cs '.[0:32]'
    fi
}

# Uruchamia kolektor i gwarantuje poprawny fragment JSON (w razie błędu: null).
hz_json() {
    local value
    value=$("$@" 2> /dev/null) || value=null
    if [[ -z $value ]] || ! printf '%s' "$value" | jq -e . > /dev/null 2>&1; then
        value=null
    fi
    printf '%s' "$value"
}

hz_build_payload() {
    local version=$1
    jq -cn \
        --arg version "$version" \
        --arg hostname "$(hostname 2> /dev/null || echo unknown)" \
        --argjson uptime "$(hz_json hz_uptime)" \
        --argjson load "$(hz_json hz_load)" \
        --argjson mem "$(hz_json hz_mem)" \
        --argjson temp "$(hz_json hz_cpu_temp)" \
        --argjson cpu "$(hz_json hz_cpu)" \
        --argjson disks "$(hz_json hz_disks)" \
        --argjson smart "$(hz_json hz_smart)" \
        --argjson raid "$(hz_json hz_raid)" \
        --argjson rpi "$(hz_json hz_rpi)" \
        --argjson ip "$(hz_json hz_ip)" \
        --argjson checks "$(hz_json hz_checks)" '
        {
            schema: 1,
            agent_version: $version,
            hostname: $hostname,
            uptime_s: $uptime,
            load: $load,
            mem: $mem,
            cpu_temp_c: $temp,
            cpu: $cpu,
            disks: $disks,
            smart: $smart,
            raid: $raid,
            rpi: $rpi,
            ip: $ip,
            checks: (($checks // []) | map(del(.metrics))),
            metrics: ([($checks // [])[] | .metrics // {}] | add // {})
        }
        | with_entries(select(.value != null and .value != [] and .value != {}))
    '
}
