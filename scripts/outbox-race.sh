#!/usr/bin/env bash
#
# Proves that concurrent OutboxDrainer workers claiming with
# SELECT ... FOR UPDATE SKIP LOCKED never deliver the same outbox message
# twice.
#
# Seeds N pending outbox rows, fires W concurrent drain workers against a
# fake instant-success partner client, and checks a shared delivery log for
# any idempotency key logged more than once. The outbox table's final state
# cannot catch a double-delivery bug on its own -- a message sent twice by
# two racing workers still ends up with one row in one final status -- so
# the log is the actual witness.
#
# Usage: ./scripts/outbox-race.sh [messages] [workers] [batch]

set -u

MESSAGES="${1:-100}"
WORKERS="${2:-20}"
BATCH="${3:-10}"
EVENT="race.test"
LOG_PATH="/var/www/html/storage/logs/outbox-race.log"

mysql_q() {
    docker compose exec -T mysql \
        mysql -ucasino -psecret casino_wallet -N -B -e "$1" 2>/dev/null
}

echo "=== Preparing ==="

TX_ID=$(mysql_q "SELECT id FROM transactions ORDER BY id LIMIT 1;")
if [ -z "$TX_ID" ]; then
    echo "No row in transactions to reference. Seed at least one bet first."
    exit 1
fi

mysql_q "DELETE FROM outbox_messages WHERE event = '${EVENT}';"
docker compose exec -T app rm -f "$LOG_PATH"
docker compose exec -T app touch "$LOG_PATH"

echo "Seeding ${MESSAGES} pending outbox rows referencing transaction ${TX_ID}"

VALUES=""
for i in $(seq 1 "$MESSAGES"); do
    if [ -n "$VALUES" ]; then VALUES="${VALUES},"; fi
    VALUES="${VALUES}(${TX_ID}, '${EVENT}', JSON_OBJECT('n', ${i}), 'pending', 0, NOW(), NOW())"
done

mysql_q "
INSERT INTO outbox_messages
    (transaction_id, event, payload, status, attempts, available_at, created_at)
VALUES ${VALUES};
"

echo

echo "=== Draining with ${WORKERS} concurrent workers, batch=${BATCH}, up to 3 rounds ==="
echo

for round in 1 2 3; do
    PENDING=$(mysql_q "SELECT COUNT(*) FROM outbox_messages WHERE event = '${EVENT}' AND status = 'pending';")
    if [ "$PENDING" -eq 0 ]; then
        echo "round ${round}: nothing pending, stopping early"
        break
    fi
    echo "round ${round}: ${PENDING} pending"

    pids=()
    for w in $(seq 1 "$WORKERS"); do
        docker compose exec -T app php artisan outbox:drain-race \
            --batch="$BATCH" \
            --worker="race-${w}" \
            --log="$LOG_PATH" \
            < /dev/null > "/tmp/outbox-race-worker-${w}.log" 2>&1 &
        pids+=($!)
    done

    for i in "${!pids[@]}"; do
        wait "${pids[$i]}" || echo "worker $((i+1)) exited non-zero: $?"
    done
done

echo
echo "=== Results ==="

DELIVERED=$(mysql_q "SELECT COUNT(*) FROM outbox_messages WHERE event = '${EVENT}' AND status = 'delivered';")
NOT_DELIVERED=$(mysql_q "SELECT COUNT(*) FROM outbox_messages WHERE event = '${EVENT}' AND status != 'delivered';")

echo "delivered      : ${DELIVERED} / ${MESSAGES}"
echo "not delivered  : ${NOT_DELIVERED}"

docker compose exec -T app cat "$LOG_PATH" > /tmp/outbox-race-log.txt
TOTAL_LOG_LINES=$(wc -l < /tmp/outbox-race-log.txt | tr -d ' ')
UNIQUE_LOG_LINES=$(sort -u /tmp/outbox-race-log.txt | wc -l | tr -d ' ')
DUPLICATES=$((TOTAL_LOG_LINES - UNIQUE_LOG_LINES))

echo "log entries    : ${TOTAL_LOG_LINES} (unique: ${UNIQUE_LOG_LINES})"

FAILED=0

if [ "$NOT_DELIVERED" -ne 0 ]; then
    echo "  FAIL — ${NOT_DELIVERED} message(s) never reached delivered status"
    mysql_q "SELECT id, status, attempts, last_error FROM outbox_messages WHERE event = '${EVENT}' AND status != 'delivered';"
    FAILED=1
fi

if [ "$TOTAL_LOG_LINES" -ne "$MESSAGES" ]; then
    echo "  FAIL — expected ${MESSAGES} send() calls, log has ${TOTAL_LOG_LINES}"
    FAILED=1
fi

if [ "$DUPLICATES" -ne 0 ]; then
    echo "  FAIL — ${DUPLICATES} idempotency key(s) sent more than once:"
    sort /tmp/outbox-race-log.txt | uniq -d
    FAILED=1
fi

if [ "$FAILED" -eq 0 ]; then
    echo "  PASS — every message delivered exactly once, no duplicate sends"
fi

rm -f /tmp/outbox-race-log.txt