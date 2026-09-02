<?php

namespace App\Enums;

enum TransactionType: string
{
    case Bet = 'bet';
    case Win = 'win';
    case Rollback = 'rollback';

    /**
     * Whether this type debits the wallet. Used to keep the sign convention
     * in one place rather than scattered across services.
     */
    public function isDebit(): bool
    {
        return $this === self::Bet;
    }
}
