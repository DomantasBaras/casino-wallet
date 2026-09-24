#!/usr/bin/env bash
#
# Fires more concurrent bets than the configured rate limit allows, all from
# the same source, and checks the split between 2xx and 429 is exact.
#
# The limiter's atomicity comes from a single Redis Lua script (ADR 0005), not
# from a separate INCR then EXPIRE. If that atomicity were broken — say, the
# increment and the limit check were two round trips instead of one — this is
# the kind of run that would expose it: concurrent requests racing past the
# check before any of them registers, letting more than max_attempts through.
#
# Correct behaviour: exactly max_attempts requests succeed (or fail wallet
# affordability, never rate limiting), and the rest get HTTP 429.
#
# Usage: ./scripts/rate-limit-race.sh [concurrency]

set -u

API="http://localhost:${APP_PORT:-8080}/api/v1/bets"
CONCURRENCY="${1:-50}"
AMOUNT="1.00"
RUN_ID="$(date +%s)"

mysql_q() {
    docker compose exec -T mysql \
        mysql -ucasino -psecret casino_wallet -N -B -e "$1" 2>/dev/null
}

echo "=== Resetting to a known state ==="
docker compose exec -T app php artisan migrate:fresh --seed >/dev/null 2>&1

# Same reasoning as migrate:fresh above: a leftover window from a previous
# run of this script would make every request in this run see an already-hot
# counter, rejecting from the very first request rather than after the limit.
docker compose exec -T redis redis-cli FLUSHDB >/dev/null 2>&1

# A balance large enough that affordability never rejects a request — the
# only thing that should turn a request away here is the rate limiter.
mysql_q "UPDATE wallets SET balance = 1000000.0000 WHERE player_id = 'player-1';"

MAX_ATTEMPTS=$(docker compose exec -T app php artisan config:show rate_limit.bets.max_attempts 2>/dev/null \
    | awk -F'\\.+ ' '{print $NF}' | tr -d '\r\n ')

echo "configured limit: ${MAX_ATTEMPTS} requests per window"
echo

echo "=== Firing ${CONCURRENCY} concurrent bets from one source ==="
echo "response codes:"

seq 1 "$CONCURRENCY" | xargs -P "$CONCURRENCY" -I{} \
    curl -s -o /dev/null -w '%{http_code}\n' \
        -X POST "$API" \
        -H 'Content-Type: application/json' \
        -d "{\"player_id\":\"player-1\",\"currency\":\"EUR\",\"amount\":\"${AMOUNT}\",\"idempotency_key\":\"${RUN_ID}-{}\"}" \
    | sort | uniq -c > /tmp/rate-limit-race-codes

awk '{printf "  %s x HTTP %s\n", $1, $2}' /tmp/rate-limit-race-codes

ACCEPTED=$(awk '$2 ~ /^(200|201)$/ {sum += $1} END {print sum + 0}' /tmp/rate-limit-race-codes)
REJECTED=$(awk '$2 == "429" {sum += $1} END {print sum + 0}' /tmp/rate-limit-race-codes)
rm -f /tmp/rate-limit-race-codes

echo
echo "=== Result ==="
printf "  accepted (2xx) : %s\n" "$ACCEPTED"
printf "  rejected (429) : %s\n" "$REJECTED"
echo

if [ "$ACCEPTED" = "$MAX_ATTEMPTS" ] && [ "$((ACCEPTED + REJECTED))" = "$CONCURRENCY" ]; then
    echo "  PASS — exactly ${MAX_ATTEMPTS} of ${CONCURRENCY} requests were let through"
else
    echo "  FAIL — expected exactly ${MAX_ATTEMPTS} accepted and the remaining"
    echo "  $((CONCURRENCY - MAX_ATTEMPTS)) rejected with 429. Either the limiter let"
    echo "  extra requests through, or something other than the limiter rejected some."
fi
