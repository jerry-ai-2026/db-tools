<?php

namespace Jerry2026\DbTool\Console\Commands\Db;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Jerry2026\DbTool\Console\Commands\Db\Concerns\SelectsExportTargets;

class ExportMigrationCommand extends Command
{
    use SelectsExportTargets;

    /**
     * 命令签名和参数定义
     */
    protected $signature = 'db:export:migrate
                            {--connection= : 数据库连接名（默认使用 .env 配置）}
                            {--database= : 数据库名（默认从连接配置读取）}
                            {--table= : 指定表名，多个用逗号分隔，不传则交互选择}';

    /**
     * 命令描述
     */
    protected $description = '根据现有数据库表结构生成迁移文件，并保留表和列的注释（支持 MySQL）';

    /**
     * Laravel 及常用扩展包内置表名（导出全部表时自动过滤）
     */
    protected array $builtInTables = [
        // Laravel 内置
        'migrations',
        'failed_jobs',
        'job_batches',
        'jobs',
        'cache',
        'cache_locks',
        'sessions',
        'password_resets',
        'password_reset_tokens',
        'personal_access_tokens',
        // spatie/laravel-permission
        'roles',
        'permissions',
        'model_has_permissions',
        'model_has_roles',
        'role_has_permissions',
    ];

    /**
     * 执行命令
     */
    public function handle(): int
    {
        $builtInTables = $this->resolveBuiltInTables();
        $targets = $this->resolveExportTargets([
            'filter' => fn($table) => !in_array($table, $builtInTables, true),
        ]);
        if ($targets === null) {
            return self::FAILURE;
        }

        [$connectionName, $dbName, $tables] = $targets;

        $connection = DB::connection($connectionName);

        if (empty($tables)) {
            $this->error('没有找到任何数据表。');
            return self::FAILURE;
        }

        $migrationPath = database_path('migrations/');
        if (!is_dir($migrationPath)) {
            mkdir($migrationPath, 0755, true);
        }

        $baseTime = time();
        foreach ($tables as $index => $table) {
            try {
                // 获取表注释（MySQL 特有）
                $tableStatus = $connection->select("SHOW TABLE STATUS WHERE Name = '{$table}'");
                $tableComment = $tableStatus[0]->Comment ?? '';

                // 获取列详情
                $columns = $connection->select("SHOW FULL COLUMNS FROM `{$table}`");

                // 获取索引
                $indexesRaw = $connection->select("SHOW INDEX FROM `{$table}`");
                $indexCollection = [];
                foreach ($indexesRaw as $idx) {
                    $indexCollection[$idx->Key_name]['columns'][] = $idx->Column_name;
                    $indexCollection[$idx->Key_name]['unique'] = !$idx->Non_unique;
                    $indexCollection[$idx->Key_name]['primary'] = ($idx->Key_name === 'PRIMARY');
                }

                // 生成迁移 PHP 代码
                $migrationContent = $this->generateMigrationContent($table, $columns, $indexCollection, $tableComment);

                $fileName = date('Y_m_d_000000', $baseTime + $index) . '_create_' . $table . '_table.php';
                $filePath = $migrationPath . $fileName;
                file_put_contents($filePath, $migrationContent);

                $this->writeStatusLine($table, 'DONE');
            } catch (\Throwable) {
                $this->writeStatusLine($table, 'FAIL', 'error');
            }
        }

        $this->line('');
        $this->info('✅ 所有迁移文件生成完毕！');
        $this->line('运行 <fg=green>php artisan migrate</> 执行迁移。');
        return self::SUCCESS;
    }

    /**
     * 解析需要过滤的内置表名（合并配置中自定义的表名）
     */
    protected function resolveBuiltInTables(): array
    {
        $tables = array_merge($this->builtInTables, [
            config('database.migrations.table'),
            config('queue.failed.table'),
            config('queue.batches.table'),
            config('session.table'),
            config('auth.passwords.users.table'),
            config('permission.table_names.roles'),
            config('permission.table_names.permissions'),
            config('permission.table_names.model_has_permissions'),
            config('permission.table_names.model_has_roles'),
            config('permission.table_names.role_has_permissions'),
        ]);

        return array_values(array_unique(array_filter($tables)));
    }

    /**
     * 根据表结构生成迁移文件内容
     */
    private function generateMigrationContent($table, $columns, $indexes, $tableComment): string
    {
        $columnDefinitions = '';

        foreach ($columns as $column) {
            $name = $column->Field;
            $type = $column->Type;
            $nullable = $column->Null === 'YES';
            $default = $column->Default;
            $comment = $column->Comment;
            $extra = $column->Extra;

            // 解析类型与长度
            $isUnsigned = str_contains($type, 'unsigned');
            $typeClean = str_replace(['unsigned', ' '], '', $type);
            preg_match('/([a-z]+)(\((.*?)\))?/', $typeClean, $matches);
            $baseType = $matches[1] ?? $typeClean;
            $length = $matches[3] ?? null;

            $line = '            ';

            // 自增主键
            if ($extra === 'auto_increment') {
                $line .= $baseType === 'bigint'
                    ? '$table->bigIncrements(\'' . $name . '\')'
                    : '$table->increments(\'' . $name . '\')';
            } else {
                // 常规字段映射
                switch ($baseType) {
                    case 'tinyint':
                        $line .= ($length == 1)
                            ? '$table->boolean(\'' . $name . '\')'
                            : '$table->tinyInteger(\'' . $name . '\')' . ($isUnsigned ? '->unsigned()' : '');
                        break;
                    case 'smallint':
                        $line .= '$table->smallInteger(\'' . $name . '\')' . ($isUnsigned ? '->unsigned()' : '');
                        break;
                    case 'mediumint':
                        $line .= '$table->mediumInteger(\'' . $name . '\')' . ($isUnsigned ? '->unsigned()' : '');
                        break;
                    case 'int':
                        $line .= '$table->integer(\'' . $name . '\')' . ($isUnsigned ? '->unsigned()' : '');
                        break;
                    case 'bigint':
                        $line .= '$table->bigInteger(\'' . $name . '\')' . ($isUnsigned ? '->unsigned()' : '');
                        break;
                    case 'varchar':
                    case 'char':
                        $line .= '$table->string(\'' . $name . '\'' . ($length ? ', ' . $length : '') . ')';
                        break;
                    case 'text':
                    case 'tinytext':
                    case 'mediumtext':
                    case 'longtext':
                        $line .= '$table->text(\'' . $name . '\')';
                        break;
                    case 'date':
                        $line .= '$table->date(\'' . $name . '\')';
                        break;
                    case 'datetime':
                    case 'timestamp':
                        $line .= '$table->dateTime(\'' . $name . '\')';
                        break;
                    case 'time':
                        $line .= '$table->time(\'' . $name . '\')';
                        break;
                    case 'float':
                        $line .= '$table->float(\'' . $name . '\')';
                        break;
                    case 'double':
                        $line .= '$table->double(\'' . $name . '\')';
                        break;
                    case 'decimal':
                        $line .= '$table->decimal(\'' . $name . '\', ' . ($length ?: '8,2') . ')';
                        break;
                    case 'enum':
                        preg_match('/enum\((.*)\)/', $column->Type, $enumMatches);
                        $line .= '$table->enum(\'' . $name . '\', [' . ($enumMatches[1] ?? '') . '])';
                        break;
                    case 'json':
                        $line .= '$table->json(\'' . $name . '\')';
                        break;
                    default:
                        $line .= '$table->string(\'' . $name . '\')';
                }
            }

            // 可空
            if ($nullable && $extra !== 'auto_increment') {
                $line .= '->nullable()';
            }

            // 默认值
            if ($default !== null && $default !== '' && $extra !== 'auto_increment') {
                $line .= is_numeric($default)
                    ? '->default(' . $default . ')'
                    : '->default(\'' . addslashes($default) . '\')';
            }

            // 列注释
            if ($comment) {
                $line .= '->comment(\'' . addslashes($comment) . '\')';
            }

            $columnDefinitions .= $line . ';' . PHP_EOL;
        }

        // 索引（排除主键）
        foreach ($indexes as $indexName => $indexData) {
            if ($indexData['primary']) {
                continue;
            }
            $cols = $indexData['columns'];
            $colStr = count($cols) === 1 ? '\'' . $cols[0] . '\'' : '[\'' . implode('\', \'', $cols) . '\']';
            if ($indexData['unique']) {
                $columnDefinitions .= '            $table->unique(' . $colStr . ', \'' . $indexName . '\');' . PHP_EOL;
            } else {
                $columnDefinitions .= '            $table->index(' . $colStr . ', \'' . $indexName . '\');' . PHP_EOL;
            }
        }

        // 表注释（通过 DB::statement 追加）
        $tableCommentStatement = '';
        if ($tableComment) {
            $tableCommentStatement = '        DB::statement("ALTER TABLE `' . $table . '` COMMENT = \'' . addslashes($tableComment) . '\';");' . PHP_EOL;
        }

        // 返回完整的迁移类模板
        return <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('{$table}', function (Blueprint \$table) {
{$columnDefinitions}        });
{$tableCommentStatement}    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('{$table}');
    }
};
PHP;
    }
}
