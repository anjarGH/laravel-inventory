<?php

namespace ESolution\Inventory\Tests;

use ESolution\Inventory\InventoryServiceProvider;
use ESolution\Inventory\Tests\Concerns\BuildsInventoryScenario;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use BuildsInventoryScenario;

    protected function getPackageProviders($app): array
    {
        return [InventoryServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }
}
