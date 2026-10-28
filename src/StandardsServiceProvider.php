<?php

namespace Pxlrbt\LaravelStandards;

use Illuminate\Support\ServiceProvider;
use Pxlrbt\LaravelStandards\Commands\CheckCommand;
use Pxlrbt\LaravelStandards\Commands\FailedJobsCommand;
use Pxlrbt\LaravelStandards\Commands\InstallCommand;
use Pxlrbt\LaravelStandards\Commands\MutateCommand;

class StandardsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([CheckCommand::class, FailedJobsCommand::class, InstallCommand::class, MutateCommand::class]);
        }
    }
}
