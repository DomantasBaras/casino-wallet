# ADR 0004 — Two concurrency techniques, and the rule for choosing

Status: accepted
Date: 2026-09-11

## Context

This service has two write paths that both need to be correct under
concurrency, and they use different mechanisms.

`POST /api/v1/bets` puts the affordability condition inside the write:

```sql
UPDATE wallets SET balance = balance - ? WHERE id = ? AND balance >= ?
```

`POST /api/v1/transfers` takes exclusive row locks first, decides in
application code, then writes:

```php
Wallet::query()
    ->whereIn('id', [$fromId, $toId])
    ->orderBy('id')
    ->lockForUpdate()
    ->get();
```

Two patterns in one codebase is a cost. A reader has to learn both and know
which applies where. This records why the cost is worth paying, and what the
rule is.

## Decision

**If the entire condition can be expressed against the row the statement is
already writing, put it in the statement.** Otherwise take locks.

For a single-wallet debit the condition is `balance >= amount` on the row being
updated, so it goes in the `WHERE` clause. For a transfer the condition also
involves the destination wallet's currency, and the write touches two rows, so
it cannot.

## Why the conditional UPDATE where it fits

It removes the window rather than guarding it. With pessimistic locking the
decision is still made in application code; the lock merely ensures nothing
changes underneath it. With the conditional update there is no interval at all
— the database evaluates the condition against the row's actual state at the
moment of the write.

It also holds its lock for less wall-clock time. Both approaches take an
exclusive row lock until commit, but `SELECT ... FOR UPDATE` acquires it at the
read and holds it across whatever the application does next. The conditional
update acquires it at the write, immediately before commit. On a single hot
wallet — the normal shape of slot play — that difference is the queue length.

And it keeps balance arithmetic out of PHP, which ADR 0001 wants anyway.

## Why locking where the other does not fit

A transfer's decision spans two rows. No `WHERE` clause on the source wallet
can check the destination's currency, and no single statement writes both
balances. The decision has to happen in application code with both rows held
still, which is what `lockForUpdate()` is for.

Locking brings two problems the conditional update does not have.

**Deadlock.** A transfer 10 → 20 and a concurrent 20 → 10 will, if each locks
its own source first, each end up holding the lock the other needs. Both wait
forever until InnoDB kills one.

The fix is ordering. Both wallets are fetched in one statement sorted by
primary key, so every transfer requests locks in ascending id order regardless
of which direction the money moves. The second transfer queues behind the first
instead of deadlocking against it.

One honest caveat: `ORDER BY` constrains the result order, and InnoDB happens
to acquire locks in the order it scans rows. With a primary-key `IN` list the
scan is ascending, so this works — but it rests on the access path rather than
on a guarantee from `ORDER BY`. Locking on a non-indexed column would break the
reasoning without any visible change to the code.

**Retries.** Ordering makes deadlocks unlikely, not impossible — InnoDB can
still detect a cycle involving other statements. `DB::transaction(..., attempts: 3)`
retries genuine concurrency errors and rethrows everything else immediately, so
`InsufficientFunds` fails once rather than three times as slowly.

## Consequences

- Two patterns to learn. Mitigated by the rule above being short enough to
  state in one sentence.
- The transfer path reads, decides, then writes — the same shape
  `docs/race-condition.md` shows losing money. It is correct only because of
  the lock. Anyone editing that method needs to know the lock is load-bearing;
  the comment in `TransferFunds` says so explicitly.
- The transfer path must compute `balance_after` in PHP, since it no longer
  gets the resulting value from the database. Safe under the lock, and
  inconsistent with how the bet path works. Noted rather than resolved.
- The bet path needs an extra read (`refresh()`) to report the resulting
  balance, because it deliberately does not compute one.

## Status of the evidence

The bet path's claim is demonstrated: `scripts/race.sh` produces six different
results before the fix and one consistent result after, at 50 and 100
concurrency.

The transfer path's deadlock claim is now demonstrated. `scripts/transfer-race.sh`
ran 40 concurrent transfers alternating direction (A→B and B→A) between two
wallets starting at 100.0000 each. Result: all 40 requests returned 201, the
combined balance stayed at 200.0000, exactly 80 ledger rows were written (two
per transfer, none lost to a killed retry), and InnoDB recorded zero deadlocks.
Lock ordering held under the exact condition designed to defeat it.