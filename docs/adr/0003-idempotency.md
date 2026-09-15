# ADR 0003 — Idempotency via the ledger's unique index

Status: accepted
Date: 2026-09-11

## Context

A game provider sends a bet, the network drops the response, and the provider
cannot tell a timeout from a failure. Its specification requires it to retry.
Retried writes are therefore the normal operating condition of this service,
not an edge case.

The naive endpoint handled this badly: a second request carrying the same key
hit the unique index on `transactions.idempotency_key` and returned 500 with a
stack trace. The money was correct — the constraint prevented the double debit
— but the caller received an error for a request that had, in fact, succeeded.
A provider seeing that may retry again, or mark the round failed while the
player has already been charged.

Two designs were considered.

**A dedicated idempotency table.** Insert a key row in a `processing` state,
do the work, store the response, mark it complete. A duplicate arriving later
reads that row and learns whether the original finished or is still in flight.
This is the pattern Stripe and similar APIs use.

**The ledger row as the record.** The `transactions` table already carries a
non-null unique `idempotency_key`, and a row exists if and only if the debit
happened. Look for it; if it is there, the work is done.

## Decision

The second. No separate table, no state machine.

`BetService::place()` looks up the key before doing anything. If a transaction
exists, it returns that transaction marked as replayed and the controller
answers 200 instead of 201. If the insert loses a race, the resulting unique
constraint violation is caught and resolved the same way.

## Rationale

The `processing` state exists to answer one question: *a duplicate has arrived
and the original has not finished — what now?* That question does not arise
here, because of ADR 0002.

The debit takes an exclusive row lock on the wallet. Two requests carrying the
same key cannot overlap: the second blocks on that lock until the first
commits, and only then reaches its own insert, which the unique index rejects.
The whole transaction rolls back, so its debit is undone. The wallet was
charged exactly once, by whichever request arrived first, and the loser can
read the winner's row because the winner has committed.

The in-flight case resolves itself. Building machinery to handle a state the
database already prevents would be machinery that never runs.

The second argument is that a separate table introduces a second source of
truth about whether a debit happened. The ledger row is already that record —
it is what reconciliation reads, what the invariant is computed from, and what
gets returned to the caller. Adding a parallel record raises the question of
what to do when the two disagree.

## Consequences

- **A replay returns the original result, not the current balance.** If other
  bets landed between the first request and its retry, the replayed response
  reports history. That is what idempotency means, but it is a real edge and
  callers should not treat the returned balance as live.
- **A rejected bet does not consume its key.** Insufficient funds writes no
  row, so retrying the same key re-evaluates against the balance as it stands
  then. This is deliberate: the earlier refusal was about funds, not about the
  request, and the funds may have changed.
- **No expiry.** Keys live as long as the ledger, which is forever. A
  dedicated table would normally carry a TTL; a ledger cannot.
- **Keys are globally unique, not scoped per operator.** With multiple
  providers, two of them could in principle choose the same key. Scoping would
  mean a composite unique index on (provider, key). Not needed with one
  integration; noted as the first thing to change when there is a second.
- **Response bodies are not stored.** The response is rebuilt from the
  transaction row, so it is only reproducible because it is small and derived
  entirely from that row. An endpoint returning something richer would need
  the response persisted.

## When this would be the wrong choice

If the operation spans more than one system — a debit here plus a call to an
external service — then no single database transaction covers it, the in-flight
state becomes real, and the dedicated table with a `processing` state earns its
place. That is exactly the situation the planned async partner notification
work creates, so this decision is likely to be revisited rather than extended.