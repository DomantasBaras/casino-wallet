<?php

namespace App\Enums;

enum OutboxStatus: string
{
    case Pending = 'pending';
    case Claimed = 'claimed';
    case Delivered = 'delivered';
    case Failed = 'failed';
}
