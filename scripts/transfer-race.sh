#!/usr/bin/env bash
#
# Fires N concurrent transfers alternating between A -> B and B -> A.
#
# This is the case lock ordering exists to handle. If each transfer locked its
# own source wallet first, the two directions would form a cycle and InnoDB
# would start killing victims. Sorting the lock acquisition by primary key
# should make them queue instead.
#
# Correct behaviour: every transfer succeeds, and the two balances still sum
# to what they did at the start.
#
# Usage: ./scripts/transfer-race.sh [concurrency]

set -u

API="http://localhost:8080/api/v1/transfers"
CONCURRENCY="${1:-40}"
AMOUNT="1.00"
START_EACH="100"

mysql_q() {
    docker compose exec -T mysql \
        mysql -ucasino -psecret casino_wallet -N -B -e "$1" 2>/dev/null
}

echo "=== Resetting to a known state ==="
docker compose exec -T app php artisan migrate:fresh >/dev/null 2>&1

mysql_q "
INSERT INTO wallets (player_id, currency, balance, created_at, updated_at)
VALUES ('race-a', 'EUR', ${START_EACH}.0000, NOW(), NOW()),
       ('race-b', 'EUR', ${START_EACH}.0000, NOW(), NOW());
"

A=$(mysql_q "SELECT id FROM wallets WHERE player_id='race-a';")
B=$(mysql_q "SELECT id FROM wallets WHERE player_id='race-b';")

echo "wallet A = ${A}, wallet B = ${B}, ${START_EACH}.00 each"
echo

echo "=== Firing ${CONCURRENCY} concurrent transfers of ${AMOUNT}, alternating direction ==="
echo

post_transfer() {
    local n="$1" from to
    # Odd requests go A -> B, even ones go B -> A. Half the requests want the
    # locks in the opposite order from the other half, which is precisely the
    # condition that deadlocks without deterministic ordering.
    if (( n % 2 == 1 )); then
        from="$2"; to="$3"
    else
        from="$3"; to="$2"
    fi

    curl -s -o /dev/null -w '%{http_code}\n' \
        -X POST "$API" \
        -H 'Content-Type: application/json' \
        -d "{\"from_wallet_id\":${from},\"to_wallet_id\":${to},\"amount\":\"${AMOUNT}\"}"
}
export -f post_transfer
export API AMOUNT

echo "response codes:"
seq 1 "$CONCURRENCY" \
    | xargs -P "$CONCURRENCY" -I{} bash -c "post_transfer {} ${A} ${B}" \
    | sort | uniq -c | awk '{printf "  %s x HTTP %s\n", $1, $2}'

echo
echo "=== Final state ==="

BAL_A=$(mysql_q "SELECT balance FROM wallets WHERE id=${A};")
BAL_B=$(mysql_q "SELECT balance FROM wallets WHERE id=${B};")
ROWS=$(mysql_q "SELECT COUNT(*) FROM transactions;")
LEDGER_SUM=$(mysql_q "SELECT COALESCE(SUM(amount), 0) FROM transactions;")
TOTAL=$(mysql_q "SELECT (SELECT balance FROM wallets WHERE id=${A})
                      + (SELECT balance FROM wallets WHERE id=${B});")

printf "  wallet A balance : %s\n" "$BAL_A"
printf "  wallet B balance : %s\n" "$BAL_B"
printf "  combined         : %s (started at %s.0000)\n" "$TOTAL" "$((START_EACH * 2))"
printf "  ledger rows      : %s (expected %s, two per transfer)\n" "$ROWS" "$((CONCURRENCY * 2))"
printf "  ledger sum       : %s (transfers move money, they do not create it)\n" "$LEDGER_SUM"
echo

FAILED=0

if [ "$(printf '%.0f' "$TOTAL")" != "$((START_EACH * 2))" ]; then
    echo "  FAIL — money was created or destroyed"
    FAILED=1
fi

if [ "$ROWS" != "$((CONCURRENCY * 2))" ]; then
    echo "  FAIL — expected $((CONCURRENCY * 2)) ledger rows, found ${ROWS}"
    echo "  A transfer that was killed as a deadlock victim and exhausted its"
    echo "  retries would show up here."
    FAILED=1
fi

if [ "$FAILED" -eq 0 ]; then
    echo "  PASS — all ${CONCURRENCY} transfers applied, money conserved"
fi

echo
echo "=== InnoDB deadlock counter ==="
echo "Non-zero does not necessarily mean a request failed — Laravel retries"
echo "genuine deadlocks up to three times. It does mean lock ordering is not"
echo "eliminating them as cleanly as intended."
mysql_q "SHOW ENGINE INNODB STATUS\G" \
    | grep -A2 'LATEST DETECTED DEADLOCK' \
    || echo "  no deadlock recorded since server start"