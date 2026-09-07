<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InsufficientFunds extends Exception
{
    public function render(Request $request): JsonResponse
    {
        return response()->json(['error' => 'insufficient_funds'], 422);
    }

    /**
     * A player betting more than they hold is a normal outcome, not a fault.
     * Returning false stops Laravel writing a stack trace for every rejected
     * bet — without this the concurrency test buries the log in noise.
     */
    public function report(): bool
    {
        return false;
    }
}