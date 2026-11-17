<?php

namespace Jerry2026\DbTool\Console\Commands\Db;

use Illuminate\Console\Command;

class ShowCommand extends Command
{

    protected $signature = 'db:show
        {--connection= : 数据库连接}';

    protected $description = '显示数据库的连接信息';

    public function handle(): int
    {
        $connection = $this->option('connection');
        if (!$connection) {
            $connection = config('database.default');
        }
        dump(config('database.connections.' . $connection));
        return Command::SUCCESS;
    }
}
