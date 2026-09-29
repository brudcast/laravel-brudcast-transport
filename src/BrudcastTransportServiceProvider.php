<?php

declare(strict_types=1);

namespace Brudcast\LaravelTransport;

use Brudcast\LaravelTransport\Console\VerifyCommand;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Mail\MailManager;
use Illuminate\Support\ServiceProvider;

final class BrudcastTransportServiceProvider extends ServiceProvider
{
    public const TRANSPORT = 'brudcast';

    public function register(): void
    {
        $this->app->singleton(
            BrudcastTransportFactory::class,
            static fn (Application $app): BrudcastTransportFactory => new BrudcastTransportFactory($app->make('config')),
        );

        $this->callAfterResolving(MailManager::class, function (MailManager $manager): void {
            $manager->extend(
                self::TRANSPORT,
                fn (array $config) => $this->app->make(BrudcastTransportFactory::class)->make($config),
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([VerifyCommand::class]);
        }
    }
}
