<?php

namespace App\Models;

use App\Enums\OutboxStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OutboxMessage extends Model
{
    /**
     * Rows are updated in place as they move through the delivery lifecycle,
     * so unlike Transaction this is not append-only. created_at is set by the
     * database default; there is no meaningful updated_at beyond the explicit
     * claimed_at / delivered_at columns.
     */
    public $timestamps = false;

    protected $fillable = [
        'transaction_id',
        'event',
        'payload',
        'status',
        'available_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status' => OutboxStatus::class,
            'available_at' => 'datetime',
            'claimed_at' => 'datetime',
            'delivered_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
