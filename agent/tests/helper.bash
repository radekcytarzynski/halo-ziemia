# shellcheck shell=bash
# Wspólne ustawienia testów: fixture'y zamiast /proc i /sys, stuby narzędzi na początku PATH.

AGENT_DIR="$(cd "$BATS_TEST_DIRNAME/.." && pwd)"
FIXTURES="$BATS_TEST_DIRNAME/fixtures"

hz_test_setup() {
    export HZ_PROC="$FIXTURES/proc"
    export HZ_SYS="$FIXTURES/sys"
    export HZ_STATE_DIR="$BATS_TEST_TMPDIR/state"
    export HZ_CHECKS_DIR="$BATS_TEST_TMPDIR/checks.d"
    export HZ_TEST_SMART="$FIXTURES/smart"
    export HZ_TEST_DF="$FIXTURES/df.txt"
    export HZ_TEST_LOG="$BATS_TEST_TMPDIR/calls.log"
    export HZ_RETRY_DELAYS="0 0"
    export PATH="$BATS_TEST_DIRNAME/stubs:$PATH"
    mkdir -p "$HZ_STATE_DIR" "$HZ_CHECKS_DIR"
    # shellcheck source=../lib/collect.sh source-path=SCRIPTDIR
    . "$AGENT_DIR/lib/collect.sh"
}

# Token testowy (format zgodny z serwerem).
TEST_TOKEN="hz_AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA"
export TEST_TOKEN

write_config() {
    local file="$BATS_TEST_TMPDIR/agent.conf"
    printf '%s\n' "$@" > "$file"
    chmod 600 "$file"
    export HZ_CONFIG="$file"
}
