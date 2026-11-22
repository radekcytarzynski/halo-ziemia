#!/usr/bin/env bats
# Kontrakt pluginów checków.

load helper

setup() { hz_test_setup; }

add_check() {
    local name=$1
    shift
    printf '#!/usr/bin/env bash\n%s\n' "$*" > "$HZ_CHECKS_DIR/$name"
    chmod +x "$HZ_CHECKS_DIR/$name"
}

@test "JSON: status, komunikat, wiek i metryki" {
    add_check backup.sh 'echo "{\"status\":\"warn\",\"message\":\"stary\",\"last_success_age_s\":100.7,\"metrics\":{\"backup.size_b\":5,\"zly\":\"x\"}}"'
    run hz_checks
    [ "$output" = '[{"name":"backup","status":"warn","message":"stary","last_success_age_s":100,"metrics":{"backup.size_b":5}}]' ]
}

@test "kod wyjścia: 0 ok, 1 warn, 3 fail; komunikat z pierwszej linii" {
    add_check a-ok.sh 'echo "wszystko dobrze"; exit 0'
    add_check b-warn.sh 'echo "uwaga"; echo "druga linia"; exit 1'
    add_check c-fail.sh 'exit 3'
    run hz_checks
    [ "$(jq -c '[.[] | [.name, .status, .message]]' <<< "$output")" = '[["a-ok","ok","wszystko dobrze"],["b-warn","warn","uwaga"],["c-fail","fail",null]]' ]
}

@test "przekroczony limit czasu → fail" {
    HZ_CHECK_TIMEOUT=1
    add_check slow.sh 'sleep 5'
    run hz_checks
    [ "$(jq -c '.[0] | [.status, .message]' <<< "$output")" = '["fail","przekroczono limit czasu"]' ]
}

@test "niepoprawny status w JSON → status z kodu wyjścia" {
    add_check weird.sh 'echo "{\"status\":\"ERROR\"}"; exit 2'
    run hz_checks
    [ "$(jq -r '.[0].status' <<< "$output")" = "fail" ]
}

@test "pomija: przykłady, niewykonywalne, niedozwolone nazwy" {
    add_check backup.sh.example 'exit 0'
    add_check Zla-Nazwa.sh 'exit 0'
    printf 'exit 0\n' > "$HZ_CHECKS_DIR/plain.sh"
    run hz_checks
    [ "${lines[-1]}" = "null" ]
    [[ $output == *"niedozwolonej nazwie: Zla-Nazwa"* ]]
}

@test "przykładowy check backupu: brak znacznika = fail, świeży znacznik = ok z wiekiem" {
    cp "$AGENT_DIR/checks.d/backup.sh.example" "$HZ_CHECKS_DIR/backup.sh"
    chmod +x "$HZ_CHECKS_DIR/backup.sh"
    export SUCCESS_MARKER="$BATS_TEST_TMPDIR/last-success" ERROR_FILE="$BATS_TEST_TMPDIR/last-error"

    run hz_checks
    [ "$(jq -r '.[0].status' <<< "$output")" = "fail" ]

    touch -d '-2 hours' "$SUCCESS_MARKER"
    run hz_checks
    [ "$(jq -r '.[0].status' <<< "$output")" = "ok" ]
    age=$(jq '.[0].last_success_age_s' <<< "$output")
    [ "$age" -ge 7190 ] && [ "$age" -le 7300 ]

    echo "rsync: kod 23" > "$ERROR_FILE"
    run hz_checks
    [ "$(jq -c '.[0] | [.status, .message]' <<< "$output")" = '["fail","rsync: kod 23"]' ]
}
