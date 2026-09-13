# casino-wallet

A wallet service for iGaming-style bets and transfers, built to work correctly
under concurrent load — and to show the evidence rather than assert it.

## The problem this repository is about

A wallet holds 100.00 EUR. Fifty concurrent requests each try to bet 10.00.
Exactly ten should succeed.

The first implementation read the balance, checked affordability in PHP, and
wrote back a computed value. Six consecutive runs against identical starting
state:

| Run | Accepted | Ledger sum | Final balance | Unaccounted |
|-----|----------|------------|---------------|-------------|
| 1   | 19       | -190.00    | 0.00          | 90.00       |
| 2   | 18       | -180.00    | 0.00          | 80.00       |
| 3   | 37       | -370.00    | 0.00          | 270.00      |
| 4   | **50**   | -500.00    | **50.00**     | **450.00**  |
| 5   | 28       | -280.00    | 0.00          | 180.00      |
| 6   | 27       | -270.00    | 0.00          | 170.00      |

Run 4 accepted every single bet. The affordability check rejected nothing, and
the wallet finished holding *more* than ten legitimate bets would have left it:
a player wagered 500.00 against a balance of 100.00 and was charged 50.00.

No request errored. Every response was well-formed. The balances — 0.00, 50.00
— look entirely ordinary. The loss is visible only by reconciling the ledger
against the wallet, which is what makes this worse than a negative balance
would be.

After replacing read-check-write with a single atomic conditional statement:

| Concurrency | Accepted | Rejected | Ledger sum | Final balance | Invariant |
|-------------|----------|----------|------------|---------------|-----------|
| 50          | 10       | 40       | -100.00    | 0.00          | holds     |
| 50          | 10       | 40       | -100.00    | 0.00          | holds     |
| 50          | 10       | 40       | -100.00    | 0.00          | holds     |
| 50          | 10       | 40       | -100.00    | 0.00          | holds     |
| 100         | 10       | 90       | -100.00    | 0.00          | holds     |

Six runs, six different answers. Then five runs, one answer — and doubling the
concurrency changes nothing.

Full write-up with the mechanism and raw captures:
[`docs/race-condition.md`](docs/race-condition.md).
The broken version is tagged `v0-naive` if you want to run it yourself.

## Reproducing it

```bash
docker compose up -d
./scripts/race.sh 50
```

The script resets to a known state, fires N concurrent bets, and reports
whether the starting balance plus every recorded ledger movement still equals
the stored balance.

## What's here

Two write paths, each concurrency-safe by a different mechanism, because the
shape of the decision differs.

**`POST /api/v1/bets`** — debits a wallet. The affordability condition lives in
the write itself:

```sql
UPDATE wallets SET balance = balance - ? WHERE id = ? AND balance >= ?
```

The affected-row count is the answer. There is no interval in which the value
the decision rests on can go stale, because the decision and the write are one
operation.

**`POST /api/v1/transfers`** — moves funds between two wallets. Here the
decision spans two rows, so it cannot live in a single `WHERE` clause. Both
rows are locked in one statement, ordered by primary key, so a transfer A→B and
a concurrent B→A request their locks in the same sequence and queue instead of
deadlocking. Genuine deadlocks are retried.

Both endpoints are idempotent. A repeated request returns the original result
rather than applying the work twice — a retried bet answers 200 with the
original transaction instead of the 500 the unique index would otherwise
produce. This matters more in iGaming than most places: a provider that never
received a response cannot tell a timeout from a failure, and its spec requires
it to retry.

**`GET /api/health`** — checks that MySQL and Redis are actually reachable, not
merely that the framework booted.

## Design decisions

Recorded as ADRs, because the reasoning is more useful than the outcome:

- [ADR 0001](docs/adr/0001-money-representation.md) — money is `DECIMAL(20,4)`,
  never a float, and never computed in PHP where the database can do it
- [ADR 0002](docs/adr/0002-atomic-conditional-update.md) — why the conditional
  UPDATE over `SELECT ... FOR UPDATE` for single-wallet debits, and the
  boundary at which that choice reverses

## Running it

```bash
docker compose up -d --build
docker compose exec app php artisan migrate --seed
make health
```

Setup detail, including the first-run steps, is in [`SETUP.md`](SETUP.md).

## Tests

```bash
make test
```

Twenty feature tests covering the ledger invariant, rejection paths, rollback
on failure, decimal boundaries, and idempotent replay.

They run against **MySQL, not SQLite**. This project's claims are about how a
specific database behaves under contention, so a green suite on a different
engine would prove nothing about the thing being claimed.

They also make no attempt to prove concurrency safety. A single PHP process
cannot produce the interleaving that makes a race visible, and a test that
pretended otherwise would be worse than no test. That evidence is
`scripts/race.sh`.

## Stack

Laravel 13 on PHP 8.4, MySQL 8.4, nginx, Docker. Service classes behind
explicit interfaces, repository pattern, no query logic in controllers.

Redis is provisioned but unused — it's there for the async partner-integration
work, which isn't built yet.

## Scope

A portfolio project, built to work through concurrency and money-handling
problems in a domain where both matter.

Not built yet, in rough order of what would teach me most:

- **Async partner notification.** A wallet change has to reach an external
  system that may be down. Outbox table, at-least-once delivery, idempotency
  on the receiving side. This is the distributed-state half of the problem the
  rest of the repo only covers locally.
- **Distributed rate limiting.** Atomic increment-with-expiry in Redis, which
  is currently provisioned and unused. Another contention problem, smaller.
- **Load testing.** k6 against the fixed endpoint, with numbers.

Authentication is deliberately absent and likely to stay that way — it would
add lines without adding anything to the argument.
