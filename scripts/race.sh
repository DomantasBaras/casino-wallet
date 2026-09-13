#!/usr/bin/env bash
#
# Fires N concurrent bets at a wallet that can only afford a few of them.
#
# Correct behaviour, given a balance of 100 and bets of 10: exactly 10 requests
# succeed, the rest are rejected, and the final balance is 0.
#
# Usage: ./scripts/race.sh [concurrency]

set -u

API="http://localhost:8080/api/v1/bets"
CONCURRENCY="${1:-50}"
AMOUNT="10.00"
START_BALANCE="100"
RUN_ID="$(date +%s)"

mysql_q() {
    docker compose exec -T mysql \
        mysql -ucasino -psecret casino_wallet -N -B -e "$1" 2>/dev/null
}

echo "=== Resetting to a known state ==="
docker compose exec -T app php artisan migrate:fresh --seed >/dev/null 2>&1
echo "starting balance: $(mysql_q "SELECT balance FROM wallets WHERE player_id='player-1';")"
echo

echo "=== Firing ${CONCURRENCY} concurrent bets of ${AMOUNT} ==="
echo "affordable: $(( ${START_BALANCE%%.*} / ${AMOUNT%%.*} )) of ${CONCURRENCY}"
echo
echo "response codes:"

seq 1 "$CONCURRENCY" | xargs -P "$CONCURRENCY" -I{} \
    curl -s -o /dev/null -w '%{http_code}\n' \
        -X POST "$API" \
        -H 'Content-Type: application/json' \
        -d "{\"player_id\":\"player-1\",\"currency\":\"EUR\",\"amount\":\"${AMOUNT}\",\"idempotency_key\":\"${RUN_ID}-{}\"}" \
    | sort | uniq -c | awk '{printf "  %s x HTTP %s\n", $1, $2}'

echo
echo "=== Final state ==="

BALANCE=$(mysql_q "SELECT balance FROM wallets WHERE player_id='player-1';")
ROWS=$(mysql_q "SELECT COUNT(*) FROM transactions;")
LEDGER_SUM=$(mysql_q "SELECT COALESCE(SUM(amount), 0) FROM transactions;")
EXPECTED=$(mysql_q "SELECT ${START_BALANCE} + COALESCE(SUM(amount), 0) FROM transactions;")

printf "  wallet balance        : %s\n" "$BALANCE"
printf "  ledger rows           : %s\n" "$ROWS"
printf "  ledger sum            : %s\n" "$LEDGER_SUM"
printf "  balance implied by ledger : %s\n" "$EXPECTED"
echo

# The invariant: starting balance plus every recorded movement must equal the
# balance actually stored. If these diverge, money was created or destroyed.
if [ "$BALANCE" = "$EXPECTED" ]; then
    echo "  INVARIANT HOLDS — balance agrees with the ledger"
else
    echo "  INVARIANT VIOLATED — the ledger records ${LEDGER_SUM} of movement,"
    echo "  which should leave ${EXPECTED}, but the wallet holds ${BALANCE}."
    echo "  The difference is money the house cannot account for."
fi