<?php

declare(strict_types=1);

namespace Axn\ModelsOrganizer\Tests;

use Axn\ModelsOrganizer\ServiceProvider;
use Axn\ModelsOrganizer\Tests\Support\TemporaryApplication;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            ServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        // La commande n'est enregistrée qu'en environnement local
        $app->detectEnvironment(static fn (): string => 'local');
    }

    protected function tearDown(): void
    {
        TemporaryApplication::cleanup();

        parent::tearDown();
    }
}
