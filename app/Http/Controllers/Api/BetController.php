<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\BetService;
use App\Http\Requests\PlaceBetRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\UniqueConstraintViolationException;

class BetController extends Controller
{
    public function __construct(private readonly BetService $bets) {}

    public function store(PlaceBetRequest $request): JsonResponse
    {
        $result = $this->bets->place(
            playerId: $request->string('player_id')->toString(),
            currency: $request->string('currency')->toString(),
            amount: $request->string('amount')->toString(),
            idempotencyKey: $request->string('idempotency_key')->toString(),
            roundId: $request->input('round_id'),
        );

        return response()->json([
            'transaction_id' => $result->transaction->id,
            'balance' => (string) $result->transaction->balance_after,
        ], $result->replayed ? 200 : 201);
    }
}
