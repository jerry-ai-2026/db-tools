<?php

namespace Jerry2026\DbTool\Console\Commands\Db;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class ImportJsonCommand extends Command
{
    protected $signature = 'db:import:json
        {--table= : 要导入的目标表名}
        {--output-dir=storage/exports : JSON文件路径或目录}
        {--connection=mysql : 数据库连接}
        {--truncate : 导入前清空表}';
    protected $description = '从JSON文件导入数据到数据库表';

    public function handle(): int
    {
        $table = $this->option('table');
        $connection = $this->option('connection');
        $outputDir = $this->option('output-dir');
        $truncate = $this->option('truncate');

        if (!Schema::connection($connection)->hasTable($table)) {
            $this->error("表 '{$table}' 不存在于连接 '{$connection}'");
            return Command::FAILURE;
        }

        $pattern = rtrim($outputDir, '/') . "/{$table}_part_*.json";
        $files = File::glob($pattern);

        if (empty($files)) {
            $this->error("在目录 '{$outputDir}' 中未找到表 '{$table}' 的JSON文件");
            $this->info("文件命名规则应为: {$table}_part_X_of_Y.json");
            return Command::FAILURE;
        }

        $this->info("找到 " . count($files) . " 个JSON文件:");
        foreach ($files as $file) {
            $this->info("  - " . basename($file));
        }
        $this->newLine();

        if ($truncate) {
            if (!$this->confirm("确定要清空表 '{$table}' 吗？此操作不可恢复！")) {
                $this->info("操作已取消");
                return Command::SUCCESS;
            }
        }

        if (!$this->confirm("确定要导入数据到表 '{$table}' 吗？")) {
            $this->info("操作已取消");
            return Command::SUCCESS;
        }

        if ($truncate) {
            DB::connection($connection)->table($table)->truncate();
            $this->info("✅ 已清空表: {$table}");
        }

        $totalImported = 0;
        $failedFiles = [];

        $progressBar = $this->output->createProgressBar(count($files));
        $progressBar->start();

        foreach ($files as $file) {
            $result = $this->importFile($file, $table, $connection);

            if ($result['success']) {
                $totalImported += $result['count'];
            } else {
                $failedFiles[] = [
                    'file' => basename($file),
                    'error' => $result['error']
                ];
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        if ($totalImported > 0) {
            $this->info("✅ 导入完成！共导入 {$totalImported} 条记录");
        } else {
            $this->warn("⚠️  没有导入任何记录");
        }
        if (!empty($failedFiles)) {
            $this->error("以下文件导入失败:");
            $this->table(['文件', '错误信息'], $failedFiles);
        }

        return Command::SUCCESS;
    }

    private function importFile($file, $table, $connection): array
    {
        try {
            $content = File::get($file);
            $data = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return [
                    'success' => false,
                    'error' => 'JSON解析错误: ' . json_last_error_msg(),
                    'count' => 0
                ];
            }
            DB::connection($connection)
                ->table($table)
                ->insert($data);

            return [
                'success' => true,
                'error' => null,
                'count' => count($data)
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => '数据库错误: ' . $e->getMessage(),
                'count' => 0
            ];
        }
    }
}
