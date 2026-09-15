# The outbox

Everything in `docs/race-condition.md` is about correctness inside one
database. This is about correctness across a boundary to a system that can
fail independently — a partner that must be told about every wallet change,
and that may be unreachable when the change happens.

Reproduce with `scripts/` and the local partner stub; the numbers below are
from a real run on 2026-09-15.

## The problem with dispatching a job

The obvious approach is to queue a notification from `BetService` after the
debit succeeds:

```php
$transaction = $this->wallets->recordTransaction(...);
NotifyPartner::dispatch($transaction);
```

This has two failure modes, and only one of them has an easy fix.

**Ordering.** Dispatching inside a transaction can put the job on the queue
before the transaction commits, so a worker may pick it up and find no
transaction to read. Laravel's `afterCommit()` solves this.

**The gap.** If the process dies between the commit and the dispatch, the
money has moved and the notification does not exist. Nothing anywhere records
that it should have happened. No amount of ordering helps, because the debit
and the enqueue are writes to two different systems and cannot be made atomic
with respect to each other.

## The outbox

Write the message to a table in the same database, in the same transaction as
the wallet change:

```php
DB::transaction(function () {
    // debit the wallet
    // append the ledger row
    // insert the outbox message
});
```

Now either all three exist or none do. There is no gap, because there is only
one commit. A separate worker drains the table later.

`OutboxTest` asserts this in both directions: a successful debit always leaves
exactly one pending message, and a refused debit leaves none.

The payload is a **snapshot, not a reference**. A worker delivering three days
later cannot join back to the wallet, because the balance has moved on and the
partner needs to know what it was at the time.

## Delivery

`OutboxDrainer` claims a batch, delivers each message, and records the outcome.

**Claiming** uses `SELECT ... FOR UPDATE SKIP LOCKED`. A second worker running
concurrently skips rows the first already holds rather than queueing behind
them, so both get disjoint batches. The atomic conditional UPDATE from ADR 0002
would also be correct here — "still pending" fits in a `WHERE` clause — but it
wastes work, because both workers target the same ids and one loses every race.
SKIP LOCKED is built for this shape specifically. The rule in ADR 0004 gives a
correct answer here, not the best one.

**Delivery happens outside the transaction.** Holding row locks across a
network call to a partner that might be timing out would block every other
worker for the duration of the outage. The claim is a short transaction; the
HTTP call is not in one.

**Retries live in exactly one layer.** The HTTP client deliberately does not
use `Http::retry()`. The drainer owns retry and backoff, and retrying in both
would multiply the schedules together — three attempts inside five means
fifteen deliveries and a backoff that means nothing.

## What a failure actually looks like

Partner pointed at an unreachable host, one pending message:

| Attempt | Result       | Backoff applied | Error                                    |
|---------|--------------|-----------------|------------------------------------------|
| 1       | retrying     | ~2s             | cURL 28: resolving timed out after 5000ms |
| 2       | retrying     | ~4s             | same                                     |
| 3       | retrying     | ~8s             | same                                     |
| 4       | retrying     | ~16s            | same                                     |
| 5       | **failed**   | —               | parked at MAX_ATTEMPTS                   |

Delay is `2 ^ attempts`, capped at 300 seconds, expressed by pushing
`available_at` into the future rather than by sleeping a worker — a sleeping
worker is a worker not draining anything else.

The gate was verified separately: two drains run back to back produced
`{"claimed":1,"retrying":1}` then no output at all, because the second found
nothing eligible. Backoff that is recorded but not enforced would look
identical in the database and behave completely differently in production.

The cURL error is worth noting. `PARTNER_TIMEOUT` is 5 seconds; without it the
worker would hang on DNS for the system default and one unreachable partner
would stall the entire drain loop.

## Recovering a parked message

Failed is not lost. Reset three columns and it re-enters the pool:

```sql
UPDATE outbox_messages
SET status = 'pending', attempts = 0, available_at = NOW()
WHERE id = ?;
```

Observed: a message parked after five failures was reset, drained, and
delivered — `{"claimed":2,"delivered":2,"retrying":0,"failed":0}` — alongside
another message whose backoff had expired in the meantime.

## Delivery is at-least-once, not exactly-once

This is the part that shapes everything downstream.

A worker can complete the HTTP call and die before marking the message
delivered. Its claim eventually goes stale, another worker picks the message
up, and the partner receives the same event twice. There is no way to close
this window: the send and the mark-as-sent are writes to two different
systems, which is the same problem the outbox solved on the way *in*, now
appearing on the way *out*.

So the guarantee is at-least-once, and the partner must deduplicate. Every
delivery carries a stable `Idempotency-Key` derived from the outbox row id, so
a retry is recognisable as a retry rather than a new event.

Which is the same conclusion ADR 0003 reached about inbound requests, arrived
at from the opposite direction. A system that retries needs a counterpart that
deduplicates, at every boundary, in both directions.

## Still missing

- A concurrency script in the style of `race.sh` proving two workers never
  deliver the same message. Currently covered only by unit-level fakes.
- Transfers emit no outbox messages yet; only `bet.placed` exists.
- No metric or alert on the failed queue. A parked message currently waits for
  someone to run a query.
