<?php

namespace App\Exceptions;

use Exception;

class WalletNotFound extends Exception
{
    public function render(): \Illuminate\Http\JsonResponse
    {
        return response()->json(['error' => 'insufficient_funds'], 422);
    }
}
