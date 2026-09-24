<?php

return [

    /*
    | Nested by feature so another endpoint (e.g. transfers) can get its own
    | budget later without reshaping this file — the same convention
    | config/outbox.php uses for its 'health' block.
    */
    'bets' => [
        'max_attempts' => (int) env('RATE_LIMIT_BETS_MAX_ATTEMPTS', 20),
        'window_seconds' => (int) env('RATE_LIMIT_BETS_WINDOW_SECONDS', 60),
    ],

];
