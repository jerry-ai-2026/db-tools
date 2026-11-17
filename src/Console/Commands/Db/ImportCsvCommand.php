<?php

namespace Jerry2026\DbTool\Console\Commands\Db;

use Jerry2026\DbTool\Support\CsvReader;
use Exception;

class ImportCsvCommand extends ImportAbstract
{

    protected $signature = 'db:import:csv
                            {file : CSV文件路径}
                            {--table= : 表名，默认使用CSV文件名}
                            {--columns= : 列名，多个以半角逗号分隔，CSV第一行不包含列名时使用}
                            {--without-header : 是否有表头}
                            {--start-row=2 : 从第几行开始导入，1表示第一行}
                            {--max-row=0 : 最大导入行数，0表示不限制}
                            {--truncate : 导入前清空表}
                            {--create-table : 当表不存在时自动创建表}
                            {--drop-table : 导入前删除并重新创建表（危险！）}
                            {--ignore-errors : 忽略错误继续导入}
                            {--csv-charset=UTF-8 : 忽略错误继续导入}
                            {--batch-size=1000 : 批量插入大小}';


    protected $description = '导入CSV文件到数据库';
    protected int $totalRows = 0;
    protected int $successCount = 0;
    protected int $errorCount = 0;
    protected int $skipCount = 0;
    protected array $columnNames = [];
    protected array $batchData = [];
    protected ?string $tableName;
    private int $startRow;
    private int $maxRow = 0;
    private CsvReader $csv;


    /**
     * @param $file
     * @throws Exception
     */
    protected function import($file)
    {
        $this->startRow = max(1, intval($this->option('start-row') ?: '0'));
        $this->maxRow = max(0, intval($this->option('max-row') ?: '0'));
        if ($this->startRow != $this->option('start-row')) {
            $this->warn(sprintf("无效的--start-row，使用%s", $this->startRow));
        }
        $this->openCsv($file);
        $this->parseTableName($file);
        $this->parseColumnNames();
        $this->handleTableOperations();
        $this->totalRows = $this->csv->getTotalFileRows() - $this->startRow + 1;
        if ($this->totalRows < 1) {
            $this->warn('没有数据可导入');
        } else {
            if ($this->maxRow && $this->totalRows > $this->maxRow) {
                $this->warn(sprintf("警告：仅处理%s行的记录", $this->maxRow));
            }
            $total = $this->totalRows;
            if ($this->maxRow){
                $total = $this->maxRow;
            }
            $this->info(sprintf('开始导入%s记录到表%s', $total, $this->tableName));
            $batchSize = (int)$this->option('batch-size') ?: 1000;
            $bar = $this->output->createProgressBar($total);
            $bar->start();
            $this->successCount = $this->errorCount = $this->skipCount = 0;
            foreach ($this->csv->getRecords() as $rowIndex => $row) {
                if (empty($row)) {
                    $this->skipCount++;
                    $this->warn("跳过空行: {$rowIndex}");
                } else {
                    $this->addToBatch($row, $batchSize);
                    $bar->advance();
                }
            }
            $this->flushBatch();
            $bar->finish();
            $this->newLine();
            $this->showResults();
        }
    }

    private function openCsv($file): void
    {
        $this->info("正在加载CSV文件: {$file}");
        $this->csv = new CsvReader($file);
        $csvCharset = $this->option('csv-charset');
        if (strtoupper($csvCharset) != 'UTF-8') {
            $this->csv->setCharset($csvCharset);
        }
        $this->csv->setStartRow($this->startRow - 1);
        empty($this->maxRow) || $this->csv->setMaxRows($this->maxRow);
        if (!$this->option('without-header')) {
            $this->csv->withHeader();
        }
    }

    /**
     * @throws Exception
     */
    private function parseColumnNames()
    {
        $this->columnNames = [];
        if ($columnsOption = $this->option('columns')) {
            $this->columnNames = array_map('trim', explode(',', $columnsOption));
        } else {
            $this->columnNames = array_map(
                [$this, 'sanitizeColumnName'],
                array_filter($this->csv->getHeaders())
            );
            if (empty($this->columnNames)) {
                $cnt = count($this->csv->getLine(
                    max(0, intval($this->option('start-row') ?: 1)))
                );
                for ($i = 1; $i < ($cnt + 1); $i++) {
                    $this->columnNames[] = 'col_' . $i;
                }
            }
        }
        if (empty($this->columnNames)) {
            throw new Exception("无法获取列名，请检查CSV文件格式或使用--columns选项指定列名");
        }
        $this->info('表字段:'.implode(',',$this->columnNames));
    }
}
