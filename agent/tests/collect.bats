#!/usr/bin/env bats
# Testy parsowania zbieranych metryk.

load helper

setup() { hz_test_setup; }

@test "uptime: sekundy całkowite" {
    run hz_uptime
    [ "$status" -eq 0 ]
    [ "$output" = "864123" ]
}

@test "load: trzy liczby" {
    run hz_load
    [ "$output" = "[0.42,0.38,0.31]" ]
}

@test "mem: pamięć i swap w kB" {
    run hz_mem
    [ "$(jq -c . <<< "$output")" = '{"total_kb":3884196,"available_kb":2201004,"swap_total_kb":102396,"swap_used_kb":10236}' ]
}

@test "brak /proc → null zamiast błędu" {
    HZ_PROC="$BATS_TEST_TMPDIR/nie-ma"
    run hz_uptime;  [ "$output" = "null" ]
    run hz_load;    [ "$output" = "null" ]
    run hz_mem;     [ "$output" = "null" ]
    run hz_raid;    [ "$output" = "null" ]
}

@test "temperatura: najwyższa strefa termiczna" {
    run hz_cpu_temp
    [ "$output" = "52.3" ]
}

@test "temperatura: fallback na vcgencmd (RPi)" {
    HZ_SYS="$BATS_TEST_TMPDIR/brak-sys"
    run hz_cpu_temp
    [ "$output" = "47.2" ]
}

@test "df: pomija spacje w ścieżce, bind mounty i liczy i-węzły" {
    run hz_disks
    [ "$status" -eq 0 ]
    mounts=$(jq -r '[.[].mount] | join(",")' <<< "$output")
    [ "$mounts" = "/,/boot/firmware,/mnt/backup,/mnt/nas" ]
    [ "$(jq '.[] | select(.mount == "/mnt/backup") | .used_b' <<< "$output")" = "1800359040614" ]
    [ "$(jq '.[] | select(.mount == "/mnt/backup") | .inodes_used_pct' <<< "$output")" = "12" ]
    [ "$(jq '.[] | select(.mount == "/boot/firmware") | .inodes_used_pct' <<< "$output")" = "null" ]
}

@test "df: filtr HZ_MOUNTS" {
    HZ_MOUNTS="/ /mnt/backup"
    run hz_disks
    [ "$(jq -r '[.[].mount] | join(",")' <<< "$output")" = "/,/mnt/backup" ]
}

@test "SMART: ATA, NVMe; dysk w standby pomijany i nie wybudzany" {
    run hz_smart_collect
    [ "$status" -eq 0 ]
    [ "$(jq length <<< "$output")" = "2" ]
    [ "$(jq -c '.[0]' <<< "$output")" = '{"device":"/dev/sda","passed":true,"temp_c":36,"power_on_hours":12034,"reallocated":3,"pending":0,"percentage_used":null}' ]
    [ "$(jq -c '.[1]' <<< "$output")" = '{"device":"/dev/nvme0","passed":false,"temp_c":41,"power_on_hours":3021,"reallocated":null,"pending":null,"percentage_used":7}' ]
    grep -q -- "-n standby -d sat /dev/sdb" "$HZ_TEST_LOG"
}

@test "SMART: lista dysków z konfiguracji z typem" {
    HZ_SMART_DEVICES="/dev/sda:sat"
    run hz_smart_collect
    [ "$(jq length <<< "$output")" = "1" ]
    grep -q -- "-d sat /dev/sda" "$HZ_TEST_LOG"
}

@test "SMART: wynik z cache przez HZ_SMART_INTERVAL" {
    run hz_smart
    [ -f "$HZ_STATE_DIR/smart.json" ]
    : > "$HZ_TEST_LOG"
    run hz_smart
    [ "$(jq length <<< "$output")" = "2" ]
    [ ! -s "$HZ_TEST_LOG" ]
}

@test "RAID: czysty raid1 i raid0" {
    run hz_parse_mdstat < "$FIXTURES/mdstat/clean"
    [ "$output" = '[{"name":"md0","state":"clean","degraded":false},{"name":"md1","state":"clean","degraded":false}]' ]
}

@test "RAID: zdegradowany" {
    run hz_parse_mdstat < "$FIXTURES/mdstat/degraded"
    [ "$output" = '[{"name":"md0","state":"degraded","degraded":true}]' ]
}

@test "RAID: odbudowa i macierz nieaktywna" {
    run hz_parse_mdstat < "$FIXTURES/mdstat/recovery"
    [ "$output" = '[{"name":"md0","state":"recovery","degraded":true},{"name":"md127","state":"inactive","degraded":true}]' ]
}

@test "RAID: brak macierzy" {
    run hz_parse_mdstat < "$FIXTURES/mdstat/none"
    [ "$output" = "null" ]
}

@test "RPi: get_throttled" {
    HZ_TEST_THROTTLED=0x50005 run hz_rpi
    [ "$output" = '{"throttled":"0x50005"}' ]
}

@test "IP: lista adresów" {
    run hz_ip
    [ "$output" = '["192.168.1.10","fd00::10"]' ]
}

@test "hz_json: błędny fragment zamieniany na null" {
    broken() { echo "{nie-json"; }
    run hz_json broken
    [ "$output" = "null" ]
}

@test "cpu: pierwsze uruchomienie zapisuje liczniki i zwraca null" {
    run hz_cpu
    [ "$output" = "null" ]
    [ "$(cat "$HZ_STATE_DIR/cpu.stat")" = "95200 80000 1500" ]
}

@test "cpu: wykorzystanie i iowait od poprzedniego odczytu" {
    # Różnica: razem 1200, idle 900, iowait 60 → praca 240 (20%), iowait 5%.
    echo "94000 79100 1440" > "$HZ_STATE_DIR/cpu.stat"
    run hz_cpu
    [ "$output" = '{"used_pct":20.0,"iowait_pct":5.0}' ]
    [ "$(cat "$HZ_STATE_DIR/cpu.stat")" = "95200 80000 1500" ]
}

@test "cpu: restart systemu albo stary odczyt → null" {
    echo "999999 900000 1500" > "$HZ_STATE_DIR/cpu.stat"
    run hz_cpu
    [ "$output" = "null" ]

    echo "94000 79100 1440" > "$HZ_STATE_DIR/cpu.stat"
    touch -d '-1 hour' "$HZ_STATE_DIR/cpu.stat"
    run hz_cpu
    [ "$output" = "null" ]
}

@test "cpu: brak /proc/stat → null" {
    HZ_PROC="$BATS_TEST_TMPDIR/nie-ma"
    run hz_cpu
    [ "$output" = "null" ]
}
