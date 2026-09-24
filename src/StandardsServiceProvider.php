<?php

namespace Pxlrbt\LaravelStandards;

use Illuminate\Support\ServiceProvider;
use Pxlrbt\LaravelStandards\Commands\InstallCommand;

class StandardsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([InstallCommand::class]);
        }
    }
}
