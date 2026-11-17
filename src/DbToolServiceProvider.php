<?php

namespace Jerry2026\DbTool;

use Illuminate\Support\ServiceProvider;
use Jerry2026\DbTool\Console\Commands\Db\ExportJsonCommand;
use Jerry2026\DbTool\Console\Commands\Db\ExportMigrationCommand;
use Jerry2026\DbTool\Console\Commands\Db\ExportSeederCommand;
use Jerry2026\DbTool\Console\Commands\Db\ImportCsvCommand;
use Jerry2026\DbTool\Console\Commands\Db\ImportExcelCommand;
use Jerry2026\DbTool\Console\Commands\Db\ImportJsonCommand;
use Jerry2026\DbTool\Console\Commands\Db\ShowCommand;

class DbToolServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ExportJsonCommand::class,
                ExportMigrationCommand::class,
                ExportSeederCommand::class,
                ImportJsonCommand::class,
                ImportExcelCommand::class,
                ImportCsvCommand::class,
                ShowCommand::class,
            ]);
        }
    }
}
