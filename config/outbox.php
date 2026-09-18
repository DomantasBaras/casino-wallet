<?php

return [

    /*
    | A claim older than this is treated as abandoned — the worker that took
    | it is assumed dead, and the message becomes eligible again. Read by both
    | OutboxDrainer (which releases stale claims) and the health endpoint
    | (which counts them). One source of truth so the two cannot disagree.
    */
    'claim_timeout_minutes' => (int) env('OUTBOX_CLAIM_TIMEOUT_MINUTES', 5),

    /*
    | The health endpoint reports "degraded" past these. They are the
    | endpoint's own opinion of unhealthy, surfaced for a cheap liveness
    | check; a real monitor applies its own thresholds to the raw numbers.
    */
    'health' => [
        'failed_degraded_at' => (int) env('OUTBOX_FAILED_DEGRADED_AT', 1),
        'oldest_pending_degraded_seconds' => (int) env('OUTBOX_OLDEST_PENDING_DEGRADED_SECONDS', 300),
    ],

];