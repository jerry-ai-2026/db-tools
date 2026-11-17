<?php

namespace Jerry2026\DbTool\Console\Commands\Db;

use Exception;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ImportExcelCommand extends ImportAbstract
{

    protected $signature = 'db:import:excel
                            {file : Excel文件路径}
                            {--sheet=0 : 工作表索引或名称，all表示导入所有工作表（每个工作表导入到独立表）}
                            {--table= : 表名，默认使用Excel文件名}
                            {--columns= : 列名，多个以半角逗号分隔，Excel第一行不包含列名时使用}
                            {--without-header : 是否有表头}
                            {--start-row=2 : 从第几行开始导入，2表示第一行}
                            {--max-row=0 : 最大导入行数，0表示不限制}
                            {--truncate : 导入前清空表}
                            {--create-table : 当表不存在时自动创建表}
                            {--drop-table : 导入前删除并重新创建表（危险！）}
                            {--ignore-errors : 忽略错误继续导入}
                            {--batch-size=1000 : 批量插入大小}
                            {--force : 跳过预检确认，直接导入}
                            ';
    protected $description = '导入Excel文件到数据库';
    protected int $totalRows = 0;
    protected int $successCount = 0;
    protected int $errorCount = 0;
    protected int $skipCount = 0;
    protected array $columnNames = [];
    protected array $batchData = [];
    protected ?string $tableName;
    protected ?Worksheet $worksheet = null;
    protected ?Spreadsheet $spreadsheet = null;
    private int $startRow = 2;
    private int $maxRow = 0;
    private int $actualHighestRow = 0;
    private int $actualHighestColumnIndex = 0;

    protected function import($file): void
    {
        $this->startRow = max(1, intval($this->option('start-row') ?: '1'));
        $this->maxRow = max(0, intval($this->option('max-row') ?: '0'));
        if ($this->startRow != $this->option('start-row')) {
            $this->warn(sprintf("无效的--start-row，使用%s", $this->startRow));
        }
        $this->openExcel($file);

        if ($this->option('sheet') === 'all') {
            $this->importAllSheets($file);
            return;
        }

        $this->parseTableName($file);

        $preFlightResult = $this->preFlightCheck();
        if (!$preFlightResult['confirmed']) {
            $this->info('导入已取消');
            return;
        }

        $this->parseColumnNames();
        $this->handleTableOperations();

        $this->doImport();
    }

    /**
     * 导入所有工作表，每个工作表导入到独立的表（表名取自工作表标题）
     */
    private function importAllSheets(string $file): void
    {
        $sheetCount = $this->spreadsheet->getSheetCount();
        $this->info(sprintf("检测到 %d 个工作表，将依次导入，每个工作表导入到独立表", $sheetCount));

        foreach ($this->spreadsheet->getWorksheetIterator() as $worksheet) {
            $this->worksheet = $worksheet;
            $sheetTitle = $worksheet->getTitle();
            $this->tableName = $this->parseTableNameForSheet($file, $sheetTitle);

            $this->info(sprintf("\n=== 正在处理工作表: %s (导入表: %s) ===", $sheetTitle, $this->tableName));

            $preFlightResult = $this->preFlightCheck(true);
            if (!$preFlightResult['confirmed']) {
                $this->warn(sprintf("工作表 %s 导入已跳过", $sheetTitle));
                continue;
            }

            $this->parseColumnNames();
            if (!$this->handleTableOperations()) {
                $this->warn(sprintf("工作表 %s 表操作失败，已跳过", $sheetTitle));
                continue;
            }

            $this->doImport();
        }
    }

    /**
     * 根据工作表标题解析表名（指定 --table 时作为前缀拼接，避免各表重名）
     */
    private function parseTableNameForSheet(string $filePath, string $sheetTitle): string
    {
        if ($this->option('table')) {
            return $this->sanitizeTableName($this->option('table') . '_' . $sheetTitle);
        }
        return $this->sanitizeTableName($sheetTitle);
    }

    private function preFlightCheck(bool $skipConfirm = false): array
    {
        $this->info("\n=== Excel文件预检报告 ===");

        $excelHighestColumn = $this->worksheet->getHighestColumn();
        $excelHighestRow = $this->worksheet->getHighestRow();
        $excelHighestColumnIndex = Coordinate::columnIndexFromString($excelHighestColumn);

        $actualHighestRow = $this->findLastNonEmptyRow($excelHighestRow);
        $actualHighestColumnIndex = $this->findLastNonEmptyColumn($excelHighestRow);

        $this->actualHighestRow = $actualHighestRow;
        $this->actualHighestColumnIndex = $actualHighestColumnIndex;

        $headerRow = max(1, $this->startRow - 1);
        $headerColumnCount = $this->countHeaderColumns($headerRow);

        $dataRows = $actualHighestRow - $this->startRow + 1;
        if ($dataRows < 0) $dataRows = 0;

        $emptyRows = [];
        for ($row = $this->startRow; $row <= $actualHighestRow; $row++) {
            if ($this->isRowEmpty($row, $actualHighestColumnIndex)) {
                $emptyRows[] = $row;
            }
        }

        $maxDataColumnIndex = $this->findMaxDataColumnIndex();

        $risks = [];

        if ($excelHighestRow > $actualHighestRow) {
            $phantomRows = $excelHighestRow - $actualHighestRow;
            $risks[] = sprintf("ℹ️  Excel报告的最高行是%d，但实际数据只到%d行。第%d-%d行全为空（Excel历史编辑残留），将被自动忽略",
                $excelHighestRow, $actualHighestRow, $actualHighestRow + 1, $excelHighestRow);
        }

        if ($excelHighestColumnIndex > $actualHighestColumnIndex) {
            $phantomCols = $excelHighestColumnIndex - $actualHighestColumnIndex;
            $risks[] = sprintf("ℹ️  Excel报告的最高列是%s，但实际数据只到%s列。%s-%s列全为空（Excel历史编辑残留），将被自动忽略",
                Coordinate::stringFromColumnIndex($excelHighestColumnIndex),
                Coordinate::stringFromColumnIndex($actualHighestColumnIndex),
                Coordinate::stringFromColumnIndex($actualHighestColumnIndex + 1),
                Coordinate::stringFromColumnIndex($excelHighestColumnIndex));
        }

        if (!$this->option('without-header') && $headerColumnCount < $maxDataColumnIndex) {
            $risks[] = sprintf("⚠️  表头列数(%d)少于数据行最大列数(%d)，多出的列将使用自动命名(column_N)",
                $headerColumnCount, $maxDataColumnIndex);
        }

        if (count($emptyRows) > 0) {
            $sampleRows = array_slice($emptyRows, 0, 5);
            $moreCount = count($emptyRows) - 5;
            $rowList = implode(',', $sampleRows);
            if ($moreCount > 0) {
                $rowList .= " 等共{$moreCount}行";
            }
            $risks[] = sprintf("ℹ️  数据区域内存在%d个空行，将被跳过（行号:%s）", count($emptyRows), $rowList);
        }

        $this->info(sprintf("文件最高行/列: %s%d", $excelHighestColumn, $excelHighestRow));
        $this->info(sprintf("实际数据行/列: %s%d", Coordinate::stringFromColumnIndex($actualHighestColumnIndex), $actualHighestRow));
        $this->info(sprintf("表头行: %d", $headerRow));
        $this->info(sprintf("数据起始行: %d", $this->startRow));
        $this->info(sprintf("预计导入数据行数: %d", $dataRows));

        if ($risks) {
            $this->newLine();
            foreach ($risks as $risk) {
                $this->warn($risk);
            }
        }

        $this->newLine();

        if ($this->option('force') || $skipConfirm) {
            $this->info("跳过确认，继续导入");
            return ['confirmed' => true];
        }

        $confirmed = $this->confirm("确认以上信息并继续导入？", true);
        return ['confirmed' => $confirmed];
    }

    private function findLastNonEmptyRow(int $highestRow): int
    {
        for ($row = $highestRow; $row >= 1; $row--) {
            if (!$this->isRowEmpty($row, 100)) {
                return $row;
            }
        }
        return 1;
    }

    private function findLastNonEmptyColumn(int $highestRow): int
    {
        $maxCol = 0;
        $highestColumn = $this->worksheet->getHighestColumn();
        $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);

        for ($row = 1; $row <= $highestRow; $row++) {
            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                $cell = $this->worksheet->getCell(Coordinate::stringFromColumnIndex($col) . $row);
                $value = $cell->getValue();
                if (!empty(trim($value ?? ''))) {
                    $maxCol = max($maxCol, $col);
                }
            }
        }

        return $maxCol > 0 ? $maxCol : $highestColumnIndex;
    }

    private function countHeaderColumns(int $headerRow): int
    {
        $highestColumn = $this->worksheet->getHighestColumn();
        $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);

        $count = 0;
        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $cell = $this->worksheet->getCell(Coordinate::stringFromColumnIndex($col) . $headerRow);
            $value = $cell->getValue();
            if (!empty(trim($value ?? ''))) {
                $count++;
            }
        }
        return $count;
    }

    private function findMaxDataColumnIndex(): int
    {
        $maxCol = 0;
        for ($row = $this->startRow; $row <= $this->actualHighestRow; $row++) {
            for ($col = 1; $col <= $this->actualHighestColumnIndex; $col++) {
                $cell = $this->worksheet->getCell(Coordinate::stringFromColumnIndex($col) . $row);
                $value = $cell->getValue();
                if (!empty(trim($value ?? ''))) {
                    $maxCol = max($maxCol, $col);
                }
            }
        }
        return $maxCol;
    }

    private function isRowEmpty(int $row, int $maxCol): bool
    {
        for ($col = 1; $col <= $maxCol; $col++) {
            $cell = $this->worksheet->getCell(Coordinate::stringFromColumnIndex($col) . $row);
            $value = $cell->getValue();
            if (!empty(trim($value ?? ''))) {
                return false;
            }
        }
        return true;
    }

    private function doImport(): void
    {
        $highestRow = $this->actualHighestRow;
        $columnCount = $this->actualHighestColumnIndex;
        $this->totalRows = $highestRow - $this->startRow;
        if ($this->totalRows < 0) {
            $this->warn('没有数据可导入');
            return;
        }

        if ($this->maxRow && $this->totalRows > $this->maxRow) {
            $this->warn(sprintf("警告：仅处理%s行的记录", $this->maxRow));
        }

        $endRow = $highestRow;
        $total = $highestRow - ($this->startRow - 1);
        if ($this->maxRow) {
            $endRow = $this->startRow + $this->maxRow - 1;
            $total = $this->maxRow;
        }

        $this->info(sprintf('开始导入%s记录到表%s', $total, $this->tableName));
        $batchSize = (int)$this->option('batch-size') ?: 1000;
        $bar = $this->output->createProgressBar($total);
        $bar->start();
        $this->successCount = $this->errorCount = $this->skipCount = 0;

        for ($rowIndex = $this->startRow; $rowIndex <= $endRow; $rowIndex++) {
            $row = [];
            for ($col = 1; $col <= $columnCount; $col++) {
                $cell = $this->worksheet->getCell(Coordinate::stringFromColumnIndex($col) . $rowIndex);
                $value = $cell->getFormattedValue();
                $row[] = $value;
            }

            if (empty(array_filter($row))) {
                $this->skipCount++;
            } else {
                $this->addToBatch($row, $batchSize);
            }
            $bar->advance();
        }

        $this->flushBatch();
        $bar->finish();
        $this->newLine();
        $this->showResults();
    }

    private function openExcel(string $file): void
    {
        $this->info("正在加载Excel文件: {$file}");
        $reader = IOFactory::createReader(ucfirst(pathinfo($file, PATHINFO_EXTENSION)));
        $reader->setReadDataOnly(true);
        $this->spreadsheet = $reader->load($file);
        $sheetOption = $this->option('sheet');
        if (is_numeric($sheetOption)) {
            $sheetIndex = (int)$sheetOption;
            $this->worksheet = $this->spreadsheet->getSheet($sheetIndex);
        } else {
            $this->worksheet = $this->spreadsheet->getSheetByName($sheetOption)
                ?: $this->spreadsheet->getActiveSheet();
        }
    }

    private function parseColumnNames()
    {
        $this->columnNames = [];
        $highestColumnIndex = $this->actualHighestColumnIndex;

        if ($columnsOption = $this->option('columns')) {
            $this->columnNames = array_map('trim', explode(',', $columnsOption));
        } elseif ($start = $this->option('start-row')) {
            $headerRow = max(1, $start - 1);

            if ($this->option('without-header')) {
                for ($col = 1; $col <= $highestColumnIndex; $col++) {
                    $this->columnNames[] = "column_{$col}";
                }
            } else {
                for ($col = 1; $col <= $highestColumnIndex; $col++) {
                    $cell = $this->worksheet->getCell(Coordinate::stringFromColumnIndex($col) . $headerRow);
                    $value = $cell->getValue();
                    if (empty($value)) {
                        $value = "column_{$col}";
                    }
                    $this->columnNames[] = $this->sanitizeColumnName($value);
                }
            }
        } else {
            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                $this->columnNames[] = "column_{$col}";
            }
        }

        if (empty($this->columnNames)) {
            throw new Exception("无法获取列名，请检查Excel文件格式或使用--columns选项指定列名");
        }

        $this->info('表字段:' . implode(',', $this->columnNames));
    }

    private function getStartRowIndex(): int
    {
        $startRow = (int)$this->option('start-row');
        return max(1, $startRow);
    }
}
