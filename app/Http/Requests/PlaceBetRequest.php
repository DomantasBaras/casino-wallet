<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class PlaceBetRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'player_id' => ['required', 'string', 'max:64'],
            'currency' => ['required', 'string', 'size:3'],
            // Validated as a string, not numeric: casting to float here would
            // undo ADR 0001 before the value ever reaches the service.
            'amount' => ['required', 'string', 'regex:/^\d{1,16}(\.\d{1,4})?$/'],
            'round_id' => ['nullable', 'string', 'max:64'],
            'idempotency_key' => ['required', 'string', 'max:64'],
        ];
    }
}
