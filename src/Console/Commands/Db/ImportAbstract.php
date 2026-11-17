<?php


namespace Jerry2026\DbTool\Console\Commands\Db;


use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

abstract class ImportAbstract extends Command
{


    public function handle(): int
    {
        $filePath = $this->argument('file');

        $files = glob($filePath, GLOB_BRACE);
        if (empty($files)) {
            $this->error("没有匹配到文件");
        }
        foreach ($files as $file) {
            if (!file_exists($file)) {
                $this->error(sprintf("文件不存在:%s", $file));
                continue;
            }
            try {
                $this->import($file);
            } catch (\Exception $e) {
                $this->error($e->getMessage());

                if ($this->verbosity) {
                    $this->info($e->getTraceAsString());
                }
            }
        }
        return Command::SUCCESS;
    }


    protected function parseTableName(string $filePath)
    {
        if ($table = $this->option('table')) {
            $this->tableName = $this->sanitizeTableName($table);
        } else {
            $this->tableName = $this->sanitizeTableName(pathinfo($filePath, PATHINFO_FILENAME));
        }
    }

    protected function sanitizeTableName(string $name): string
    {
        $newName = preg_replace('/[^\p{L}\p{N}_]/u', '_', $name);
        $newName = mb_strtolower($newName, 'UTF-8');
        if (mb_strlen($name, 'UTF-8') > 64) {
            $newName = mb_substr($newName, 0, 60, 'UTF-8') . '_tbl';
        }
        if ($name != $newName) {
            $this->warn(sprintf("%s不适合做表名，换成%s", $name, $newName));
        }
        return $newName;
    }

    protected function sanitizeColumnName(string $name): string
    {
        $newName = preg_replace('/[^\p{L}\p{N}_]/u', '_', $name);
        $newName = mb_strtolower($newName, 'UTF-8');
        if (mb_strlen($name, 'UTF-8') > 64) {
            $newName = mb_substr($newName, 0, 60, 'UTF-8') . '_tbl';
        }
        if ($name != $newName) {
            $this->warn(sprintf("警告：%s不适合做字段名，换成%s", $name, $newName));
        }
        return $newName;
    }

    /**
     * 处理表操作
     *
     * @return bool
     */
    protected function handleTableOperations(): bool
    {
        if (!Schema::hasTable($this->tableName)) {
            if (!$this->shouldCreateTable()) {
                $this->error("表 '{$this->tableName}' 不存在");
                return false;
            }
            $this->createTable();
        } else {
            if ($this->option('drop-table')) {
                if (!$this->confirmDropTable()) {
                    $this->info('导入已取消');
                    return false;
                }
                Schema::dropIfExists($this->tableName);
                $this->createTable();
            } elseif ($this->option('truncate')) {
                if (!$this->confirmTruncateTable()) {
                    $this->info('跳过清空表操作');
                } else {
                    DB::table($this->tableName)->truncate();
                    $this->info("表 '{$this->tableName}' 已清空");
                }
            }
        }

        return true;
    }

    /**
     * 是否应该创建表
     *
     * @return bool
     */
    protected function shouldCreateTable(): bool
    {
        if ($this->option('create-table')) {
            return true;
        }
        return true;
        return $this->confirm("表 '{$this->tableName}' 不存在，是否自动创建？", true);
    }

    /**
     * 创建表
     *
     * @return void
     */
    protected function createTable(): void
    {
        $this->info("正在创建表 '{$this->tableName}'...");

        Schema::create($this->tableName, function (Blueprint $table) {
            $table->id();

            foreach ($this->columnNames as $column) {
                if (strtolower($column) === 'id') {
                    continue;
                }
                $table->text($column)->nullable();
            }

            $table->timestamps();
        });

        $this->info("表 '{$this->tableName}' 创建成功");
    }

    /**
     * 确认删除表
     *
     * @return bool
     */
    protected function confirmDropTable(): bool
    {
        $this->warn("警告：这将删除表 '{$this->tableName}' 并重新创建，所有数据将丢失！");
        return true;
        return $this->confirm('确定要删除并重新创建表吗？', false);
    }

    /**
     * 确认清空表
     *
     * @return bool
     */
    protected function confirmTruncateTable(): bool
    {
        $this->warn("警告：这将清空表 '{$this->tableName}' 中的所有数据！");
        return true;
        return $this->confirm('确定要清空表吗？', false);
    }

    /**
     * @param array $record
     * @param int $batchSize
     * @throws \Exception
     */
    protected function addToBatch(array $record, int $batchSize): void
    {
        $row = [];
        $values = array_values($record);
        foreach ($this->columnNames as $idx => $name) {
            $row[$name] = $values[$idx];
        }
        $row['created_at'] = $row['updated_at'] = Carbon::now();
        $this->batchData[] = $row;
        if (count($this->batchData) >= $batchSize) {
            $this->flushBatch();
        }
    }

    /**
     * @throws \Exception
     */
    protected function flushBatch(): void
    {
        if (empty($this->batchData)) {
            return;
        }
        try {
            DB::table($this->tableName)->insert($this->batchData);
            $this->successCount += count($this->batchData);
            $this->batchData = [];
        } catch (\Exception $e) {
            $this->batchData = [];
            if ($this->option('ignore-errors')) {
                $this->error($e->getMessage());
                if ($this->verbosity) {
                    $this->info($e->getTraceAsString());
                }
            } else {
                throw $e;
            }
        }
    }

    protected function showResults(): void
    {
        $this->newLine();
        $this->info("成功地导入了 {$this->successCount} 条记录");
        if ($this->skipCount) {
            $this->warn("警告:跳过了 {$this->skipCount} 条记录");
        }
        if ($this->errorCount) {
            $this->error("错误:{$this->errorCount} 条记录导入失败");
        }
    }
}
