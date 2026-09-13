<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\BetService;
use App\Http\Requests\PlaceBetRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\UniqueConstraintViolationException;
use App\Services\TransferFunds;

class TransferController extends Controller
{
    public function __construct(private readonly TransferFunds $transfers) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from_wallet_id' => ['required', 'integer', 'different:to_wallet_id'],
            'to_wallet_id' => ['required', 'integer'],
            'amount' => ['required', 'string', 'regex:/^\d{1,16}(\.\d{1,4})?$/'],
            'transfer_id' => ['nullable', 'string', 'max:48'],
        ]);

        $transferId = $this->transfers->execute(
            (int) $data['from_wallet_id'],
            (int) $data['to_wallet_id'],
            $data['amount'],
            $data['transfer_id'] ?? null,
        );

        return response()->json(['transfer_id' => $transferId], 201);
    }
}
