<?php

namespace Pxlrbt\LaravelStandards\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use Pxlrbt\LaravelStandards\StandardsServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [StandardsServiceProvider::class];
    }
}
