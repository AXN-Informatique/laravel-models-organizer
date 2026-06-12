<?php

declare(strict_types=1);

namespace Axn\ModelsOrganizer;

use Axn\ModelsOrganizer\OrganizeModelsCommand;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {}

    public function boot(): void
    {
        if (! $this->app->isLocal()) {
            return;
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                OrganizeModelsCommand::class,
            ]);
        }
    }
}
