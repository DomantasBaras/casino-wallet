<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Wallet extends Model
{
    protected $fillable = [
        'player_id',
        'currency',
        'balance',
    ];

    protected function casts(): array
    {
        return [
            // decimal:4 returns a string, not a float. That is the whole
            // point — see docs/adr/0001-money-representation.md. Never cast
            // a money column to float or int.
            'balance' => 'decimal:4',
        ];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
