#!/usr/bin/env bats
# Główny skrypt: konfiguracja, payload, wysyłka.

load helper

setup() {
    hz_test_setup
    AGENT="$AGENT_DIR/halo-ziemia-agent"
    export HZ_LIB_DIR="$AGENT_DIR/lib"
    export HZ_TEST_RESPONSES="$BATS_TEST_TMPDIR/responses"
    write_config 'HZ_URL="https://monitor.example.test/"' "HZ_TOKEN=\"$TEST_TOKEN\""
}

responses() { printf '%s\n' "$@" > "$HZ_TEST_RESPONSES"; }

@test "--version" {
    run "$AGENT" --version
    [ "$status" -eq 0 ]
    [[ $output == "halo-ziemia-agent 1."* ]]
}

@test "--dry-run: kompletny payload schema 1" {
    run "$AGENT" --dry-run
    [ "$status" -eq 0 ]
    [ "$(jq -r '.schema' <<< "$output")" = "1" ]
    [ "$(jq -r '.hostname' <<< "$output")" = "nas-test" ]
    [ "$(jq -c 'keys' <<< "$output")" = '["agent_version","cpu_temp_c","disks","hostname","ip","load","mem","rpi","schema","smart","uptime_s"]' ]
}

@test "wysyłka: token tylko w stdin curl, nie w argumentach" {
    responses '200|{"ok":true,"server_time":"2026-11-20T12:00:00Z","interval":60}'
    run "$AGENT"
    [ "$status" -eq 0 ]
    [ -z "$output" ]
    grep -q "^URL: https://monitor.example.test/api/v1/heartbeat$" "$HZ_TEST_LOG"
    grep -q "^STDIN: header = \"Authorization: Bearer $TEST_TOKEN\"$" "$HZ_TEST_LOG"
    ! grep "^ARGS:" "$HZ_TEST_LOG" | grep -q "$TEST_TOKEN"
    jq -e '.schema == 1' "$HZ_TEST_LOG.payload.1"
}

@test "--test pokazuje odpowiedź serwera" {
    responses '200|{"ok":true,"interval":60}'
    run "$AGENT" --test
    [ "$status" -eq 0 ]
    [[ $output == *'OK (HTTP 200)'* ]]
}

@test "ponowienia przy błędzie sieci i 5xx, potem sukces" {
    responses '000|' '503|{"ok":false}' '200|{"ok":true}'
    run "$AGENT"
    [ "$status" -eq 0 ]
    [ "$(cat "$HZ_TEST_LOG.count")" = "3" ]
}

@test "po wyczerpaniu prób: kod 2" {
    responses '500|' '500|' '500|'
    run "$AGENT"
    [ "$status" -eq 2 ]
    [[ $output == *"HTTP 500"* ]]
}

@test "401: bez ponowień, kod 3" {
    responses '401|{"ok":false,"error":"unauthorized","message":"Nieprawidłowy token"}'
    run "$AGENT"
    [ "$status" -eq 3 ]
    [ "$(cat "$HZ_TEST_LOG.count")" = "1" ]
    [[ $output == *"unieważniony token"* ]]
}

@test "422: log szczegółów i zastępczy ping" {
    responses '422|{"ok":false,"message":"Payload nie przeszedł walidacji.","details":["disks[0].mount: zła ścieżka"]}' '200|{"ok":true}'
    run "$AGENT"
    [ "$status" -eq 3 ]
    [[ $output == *"disks[0].mount: zła ścieżka"* ]]
    grep -q "^URL: https://monitor.example.test/api/v1/ping$" "$HZ_TEST_LOG"
}

@test "odmowa wysyłki po HTTP bez HZ_ALLOW_HTTP" {
    write_config 'HZ_URL="http://monitor.example.test"' "HZ_TOKEN=\"$TEST_TOKEN\""
    run "$AGENT"
    [ "$status" -eq 1 ]
    [[ $output == *"HTTPS"* ]]
}

@test "zły format tokenu" {
    write_config 'HZ_URL="https://monitor.example.test"' 'HZ_TOKEN="sekret"'
    run "$AGENT"
    [ "$status" -eq 1 ]
    [[ $output == *"niepoprawny format"* ]]
}

@test "brak konfiguracji" {
    HZ_CONFIG="$BATS_TEST_TMPDIR/brak.conf" run "$AGENT"
    [ "$status" -eq 1 ]
}

@test "konfiguracja jest parsowana, nie wykonywana" {
    write_config 'HZ_URL="https://monitor.example.test"' "HZ_TOKEN=\"$TEST_TOKEN\"" 'HZ_MOUNTS="$(touch '"$BATS_TEST_TMPDIR"'/pwned)"' 'rm -rf /tmp/x' 'OTHER=1'
    responses '200|{"ok":true}'
    run "$AGENT"
    [ "$status" -eq 0 ]
    [ ! -e "$BATS_TEST_TMPDIR/pwned" ]
    [[ $output == *"nieznany klucz"* || $output == *"niezrozumiała linia"* ]]
}

@test "ostrzeżenie o zbyt szerokich uprawnieniach konfiguracji" {
    chmod 644 "$HZ_CONFIG"
    responses '200|{"ok":true}'
    run "$AGENT"
    [ "$status" -eq 0 ]
    [[ $output == *"uprawnienia 644"* ]]
}
