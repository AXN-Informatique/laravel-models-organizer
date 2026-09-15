<?php

declare(strict_types=1);

namespace Axn\ModelsOrganizer;

use Axn\ModelsOrganizer\Console\OrganizeModelsCommand;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    private string $basePath = '';

    public function register(): void
    {
        $this->basePath = __DIR__.'/../';

        $this->registerConfig();
    }

    public function boot(): void
    {
        if (! $this->app->isLocal()) {
            return;
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                OrganizeModelsCommand::class,
            ]);

            $this->configurePublishing();
        }
    }

    private function registerConfig(): void
    {
        $this->mergeConfigFrom(
            $this->basePath.'config/models-organizer.php',
            'models-organizer'
        );
    }

    private function configurePublishing(): void
    {
        // config
        $this->publishes([
            $this->basePath.'config/models-organizer.php' => $this->app->configPath('models-organizer.php'),
        ], 'models-organizer-config');
    }
}
