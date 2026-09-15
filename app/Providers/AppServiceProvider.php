<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Repositories\EloquentWalletRepository;
use App\Repositories\WalletRepositoryInterface;
use App\Services\Partner\PartnerClient;
use App\Services\Partner\HttpPartnerClient;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */

    public function register(): void
    {
      $this->app->bind(
          WalletRepositoryInterface::class,
          EloquentWalletRepository::class,
      );

      $this->app->bind(PartnerClient::class, fn () => new HttpPartnerClient(
          config('services.partner.url'),
          config('services.partner.timeout'),
      ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
