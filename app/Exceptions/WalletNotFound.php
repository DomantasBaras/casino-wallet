<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletNotFound extends Exception
{
    public function render(Request $request): JsonResponse
    {
        return response()->json(['error' => 'wallet_not_found'], 404);
    }

    public function report(): bool
    {
        return false;
    }
}