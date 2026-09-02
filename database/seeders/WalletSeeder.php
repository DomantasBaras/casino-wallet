<?php

namespace Database\Seeders;

use App\Models\Wallet;
use Illuminate\Database\Seeder;

class WalletSeeder extends Seeder
{
    public function run(): void
    {
        Wallet::query()->updateOrCreate(
            ['player_id' => 'player-1', 'currency' => 'EUR'],
            ['balance' => '100.0000'],
        );
    }
}