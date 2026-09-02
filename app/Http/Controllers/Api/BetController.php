<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\BetService;
use App\Http\Requests\PlaceBetRequest;
use Illuminate\Http\JsonResponse;

class BetController extends Controller
{
    public function __construct(private readonly BetService $bets) {}

    public function store(PlaceBetRequest $request): JsonResponse
    {
        $transaction = $this->bets->place(
            playerId: $request->string('player_id')->toString(),
            currency: $request->string('currency')->toString(),
            amount: $request->string('amount')->toString(),
            idempotencyKey: $request->string('idempotency_key')->toString(),
            roundId: $request->input('round_id'),
        );

        return response()->json([
            'transaction_id' => $transaction->id,
            // String, not a JSON number — a client parsing this as a float
            // would reintroduce exactly the problem ADR 0001 avoids.
            'balance' => (string) $transaction->balance_after,
        ], 201);
    }
}
