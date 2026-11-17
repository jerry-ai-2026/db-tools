<?php

namespace Jerry2026\DbTool\Console\Commands\Db;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Jerry2026\DbTool\Console\Commands\Db\Concerns\SelectsExportTargets;

class ExportSeederCommand extends Command
{
    use SelectsExportTargets;

    protected $signature = 'db:export:seeder
                            {--connection= : 数据库连接名（默认使用 .env 配置）}
                            {--database= : 数据库名（默认从连接配置读取）}
                            {--table= : 指定表名，多个用逗号分隔，不传则交互选择}';

    protected $description = '将指定数据库表中的数据生成 Laravel 种子文件';

    public function handle(): int
    {
        $targets = $this->resolveExportTargets();
        if ($targets === null) {
            return self::FAILURE;
        }

        [$connection, $database, $tables] = $targets;

        if (empty($tables)) {
            $this->error('没有找到任何数据表。');
            return self::FAILURE;
        }

        // 逐表导出
        $generated = [];
        foreach ($tables as $table) {
            $seederName = $this->exportTable($connection, $database, $table);
            if ($seederName !== null) {
                $generated[] = $seederName;
            }
        }

        $this->line('');
        $this->info('✅ 所有种子文件生成完毕！');

        if (!empty($generated)) {
            $this->line('');
            $this->line('方式一 · 单独运行：');
            foreach ($generated as $seederName) {
                $this->line("  <fg=green>php artisan db:seed --class={$seederName}</>");
            }

        }
        return self::SUCCESS;
    }

    /**
     * 导出单张表
     *
     * @return string|null 生成的 Seeder 类名，失败或跳过时返回 null
     */
    protected function exportTable($connection, $database, $table)
    {
        try {
            $rows = DB::connection($connection)->table($database . '.' . $table)->get();
        } catch (\Exception $e) {
            $this->writeStatusLine($table, 'FAIL', 'error');
            return null;
        }

        if ($rows->isEmpty()) {
            $this->writeStatusLine($table, 'SKIP', 'comment');
            return null;
        }

        $data = $rows->map(function ($row) {
            return (array) $row;
        })->toArray();

        $seederName = Str::studly($table) . 'Seeder';
        $seederPath = database_path('seeders/' . $seederName . '.php');
        $content = $this->buildSeederContent($table, $data);

        File::ensureDirectoryExists(database_path('seeders'));
        File::put($seederPath, $content);

        $this->writeStatusLine($table, 'DONE');

        return $seederName;
    }

    /**
     * 构建 Seeder 文件内容
     */
    protected function buildSeederContent($table, array $data)
    {
        $exported = $this->exportArray($data, 2);

        $className = Str::studly($table) . 'Seeder';

        return <<<PHP
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class {$className} extends Seeder
{
    /**
     * 从数据库表 [{$table}] 自动生成的种子数据
     * 生成时间: {$this->now()}
     */
    public function run()
    {
        DB::table('{$table}')->insert({$exported});
    }
}
PHP;
    }

    protected function exportArray(array $data, int $level): string
    {
        if ($data === []) {
            return '[]';
        }

        $indent = str_repeat('    ', $level);
        $childIndent = str_repeat('    ', $level + 1);
        $isList = array_is_list($data);
        $lines = ['['];

        foreach ($data as $key => $value) {
            $prefix = $isList ? '' : var_export($key, true) . ' => ';
            $lines[] = $childIndent . $prefix . $this->exportValue($value, $level + 1) . ',';
        }

        $lines[] = $indent . ']';

        return implode(PHP_EOL, $lines);
    }

    protected function exportValue($value, int $level): string
    {
        if (is_array($value)) {
            return $this->exportArray($value, $level);
        }

        $exported = var_export($value, true);

        return $exported === 'NULL' ? 'null' : $exported;
    }

    protected function now()
    {
        return date('Y-m-d H:i:s');
    }
}
