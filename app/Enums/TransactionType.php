<?php

namespace App\Enums;

enum TransactionType: string
{
    case Bet = 'bet';
    case Win = 'win';
    case Rollback = 'rollback';
    case TransferOut = 'transfer_out';
    case TransferIn = 'transfer_in';

    public function isDebit(): bool
    {
        return in_array($this, [self::Bet, self::TransferOut], true);
    }
}
