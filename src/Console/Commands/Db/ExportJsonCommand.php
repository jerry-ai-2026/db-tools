<?php

namespace Jerry2026\DbTool\Console\Commands\Db;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Jerry2026\DbTool\Console\Commands\Db\Concerns\SelectsExportTargets;

class ExportJsonCommand extends Command
{
    use SelectsExportTargets;

    protected $signature = 'db:export:json
        {--connection= : 数据库连接名（默认使用 .env 配置）}
        {--database= : 数据库名（默认从连接配置读取）}
        {--table= : 指定表名，多个用逗号分隔，不传则交互选择}
        {--chunk-size=1000 : 分块大小，每次导出的记录数}
        {--output-dir=storage/exports : 输出目录}';

    protected $description = '将指定数据库表导出为JSON文件，支持分块导出';

    public function handle(): int
    {
        $targets = $this->resolveExportTargets();
        if ($targets === null) {
            return self::FAILURE;
        }

        [$connection, $database, $tables] = $targets;

        $chunkSize = max(1, (int) $this->option('chunk-size'));
        $outputDir = $this->option('output-dir');

        File::ensureDirectoryExists($outputDir);

        foreach ($tables as $table) {
            $progressBar = null;

            try {
                $totalCount = DB::connection($connection)
                    ->table($database . '.' . $table)
                    ->count();

                if ($totalCount === 0) {
                    $this->writeStatusLine($table, 'SKIP', 'comment');
                    continue;
                }

                $totalPages = (int) ceil($totalCount / $chunkSize);
                $progressBar = $this->output->createProgressBar($totalPages);
                $progressBar->start();

                for ($page = 1; $page <= $totalPages; $page++) {
                    $offset = ($page - 1) * $chunkSize;

                    $data = DB::connection($connection)
                        ->table($database . '.' . $table)
                        ->offset($offset)
                        ->limit($chunkSize)
                        ->get()
                        ->toArray();

                    $fileName = "{$table}_part_{$page}_of_{$totalPages}.json";
                    $filePath = rtrim($outputDir, '/\\') . DIRECTORY_SEPARATOR . $fileName;
                    File::put($filePath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

                    $progressBar->advance();
                }

                $progressBar->finish();
                $this->newLine();

                $this->writeStatusLine($table, 'DONE');
            } catch (\Throwable) {
                if ($progressBar !== null) {
                    $progressBar->finish();
                    $this->newLine();
                }

                $this->writeStatusLine($table, 'FAIL', 'error');
            }
        }

        $this->line('');
        $this->info("导出完成！文件保存在: {$outputDir}");

        return self::SUCCESS;
    }
}
