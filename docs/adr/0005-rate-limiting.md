# ADR 0005 — Rate limiting via a single atomic Redis operation

Status: accepted
Date: 2026-09-19

## Context

`POST /api/v1/bets` needed a cap on requests per source IP per window. Redis
was already provisioned for exactly this (README: "Distributed rate
limiting... currently provisioned and unused") but nothing used it yet.

The obvious implementation is two commands: `INCR` the counter, then `EXPIRE`
it if this was the first hit in the window. That is the same check-then-act
shape the rest of this repo has spent its effort removing — `docs/race-condition.md`,
ADR 0002, ADR 0004 — just relocated from application code to two sequential
Redis calls instead of one.

## Decision

Do the whole thing in one Redis `EVAL` (Lua script): increment, conditionally
set the expiry, and read back the TTL, all as a single atomic operation on
the server (`App\Services\RateLimiter`).

```lua
local current = redis.call('INCR', KEYS[1])
if tonumber(current) == 1 then
    redis.call('EXPIRE', KEYS[1], ARGV[1])
end
local ttl = redis.call('TTL', KEYS[1])
return {current, ttl}
```

## Why

**INCR alone is already atomic** — Redis is single-threaded, so concurrent
requests never see a torn increment, and the counter itself is never wrong.
The defect is specifically in the *second* command. Between `INCR` returning
1 and `EXPIRE` running, the key exists with no TTL. If the process is killed
in that window — deploy, OOM, worker crash — the key is permanently
persistent: that IP is rate-limited forever, with no window ever resetting
it, until someone notices and deletes the key by hand.

A Lua script closes that window entirely. Redis executes the whole script as
one unit; there is no point between the increment and the expiry where the
server can be interrupted, because from the server's perspective there is no
"between" — it's one operation.

This also collapses what would be up to three round trips (INCR, EXPIRE,
TTL for the `Retry-After` header) into one, which matters more here than it
sounds: this check runs on every request to the endpoint it guards, not just
the rare contested case ADR 0002 and ADR 0004 are about.

## Consequences

- One more moving part (a Lua script) instead of two Redis facade calls.
  Mitigated by it being short enough to read in one glance and living in one
  place (`RateLimiter::hit()`).
- Fixed window, not sliding: a burst can land up to `max_attempts` requests
  right at the end of one window and another `max_attempts` right at the
  start of the next, i.e. up to 2x the nominal rate over a short span at the
  boundary. Accepted — the property being demonstrated here is atomicity of
  the count, not a particular smoothing algorithm.
- Keyed on IP, not `player_id`. The request body's `player_id` is
  caller-supplied and not authenticated (this project has no auth, by
  design — see README "Scope"), so it would let a client dodge the limit by
  varying that field. The source IP is not something the caller controls.

## Status of the evidence

`scripts/rate-limit-race.sh` fires more concurrent bets than the configured
limit from one source and asserts exactly `max_attempts` succeed and the
rest receive 429 — the same "prove the count, don't assert it" pattern as
`scripts/race.sh` and `scripts/transfer-race.sh`.
