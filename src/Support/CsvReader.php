<?php

namespace Jerry2026\DbTool\Support;

class CsvReader
{
    private $fileHandle;
    private $filePath;
    private $headers = [];
    private $delimiter = ',';
    private $enclosure = '"';
    private $escape = '\\';
    private $charset = 'UTF-8';
    private $startRow = 0;
    private $maxRows = null;
    private $hasHeader = false;
    private $bomSkipped = false;
    private $totalFileRows = null;

    /**
     * 构造函数
     */
    public function __construct($csvFile)
    {
        if (!file_exists($csvFile)) {
            throw new \InvalidArgumentException("CSV文件不存在: {$csvFile}");
        }

        $this->filePath = $csvFile;
    }

    /**
     * 设置字符集
     */
    public function setCharset($charset)
    {
        $this->charset = $charset;
        return $this;
    }

    /**
     * 设置起始行（从0开始）
     */
    public function setStartRow($row)
    {
        $this->startRow = (int)$row;
        return $this;
    }

    /**
     * 设置最大读取行数
     */
    public function setMaxRows($maxRows)
    {
        $this->maxRows = $maxRows === null ? null : (int)$maxRows;
        return $this;
    }

    /**
     * 设置是否包含表头
     */
    public function withHeader()
    {
        $this->hasHeader = true;
        return $this;
    }

    /**
     * 打开文件
     */
    private function openFile()
    {
        if ($this->fileHandle) {
            rewind($this->fileHandle);
            $this->bomSkipped = false;
            return;
        }

        $this->fileHandle = fopen($this->filePath, 'r');
        if ($this->fileHandle === false) {
            throw new \RuntimeException("无法打开CSV文件: {$this->filePath}");
        }
    }

    /**
     * 处理BOM
     */
    private function skipBom()
    {
        if ($this->bomSkipped) {
            return;
        }

        rewind($this->fileHandle);
        $bom = fread($this->fileHandle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            fseek($this->fileHandle, 0);
        }

        $this->bomSkipped = true;
    }

    /**
     * 读取表头
     */
    private function readHeaders()
    {
        if (!$this->hasHeader) {
            return;
        }
        $headerRow = $this->startRow - 1;
        if ($headerRow < 0) {
            $headerRow = 0;
        }

        $this->skipToLine($headerRow);
        $headers = fgetcsv($this->fileHandle, 0, $this->delimiter, $this->enclosure, $this->escape);
        $headers = array_filter($headers);
        if ($headers !== false && $headers !== null) {
            $this->headers = $this->convertEncoding($headers);
        }
    }

    /**
     * 跳转到指定行
     */
    private function skipToLine($lineNumber)
    {
        rewind($this->fileHandle);
        $this->skipBom();

        $currentLine = 0;
        while ($currentLine < $lineNumber && !feof($this->fileHandle)) {
            fgetcsv($this->fileHandle, 0, $this->delimiter, $this->enclosure, $this->escape);
            $currentLine++;
        }

        return $currentLine === $lineNumber;
    }

    /**
     * 返回所有记录的迭代器
     */
    public function getRecords()
    {
        $this->openFile();
        $this->skipBom();

        // 读取表头
        if ($this->hasHeader && empty($this->headers)) {
            $this->readHeaders();
        }

        // 跳转到起始行
        $this->skipToLine($this->startRow);

        $rowCount = 0;

        while (($row = fgetcsv($this->fileHandle, 0, $this->delimiter, $this->enclosure, $this->escape)) !== false) {
            // 检查是否达到最大行数
            if ($this->maxRows !== null && $rowCount >= $this->maxRows) {
                break;
            }

            $row = $this->convertEncoding($row);

            if ($this->hasHeader && !empty($this->headers)) {
                $row = $this->combineWithHeaders($row);
            }

            yield $row;
            $rowCount++;
        }

        fclose($this->fileHandle);
        $this->fileHandle = null;
    }

    /**
     * 读取特定行（按文件行号，从0开始）
     */
    public function getLine($lineNumber)
    {
        $this->openFile();
        $this->skipBom();

        if (!$this->skipToLine($lineNumber)) {
            fclose($this->fileHandle);
            $this->fileHandle = null;
            return null;
        }

        $row = fgetcsv($this->fileHandle, 0, $this->delimiter, $this->enclosure, $this->escape);

        fclose($this->fileHandle);
        $this->fileHandle = null;

        if ($row === false || $row === null) {
            return null;
        }

        $row = $this->convertEncoding($row);

        // 注意：getLine不关心hasHeader和headers设置，返回原始行
        return $row;
    }

    /**
     * 获取文件总行数
     */
    public function getTotalFileRows()
    {
        if ($this->totalFileRows !== null) {
            return $this->totalFileRows;
        }

        $this->openFile();
        $this->skipBom();

        $count = 0;
        while (($row = fgetcsv($this->fileHandle, 0, $this->delimiter, $this->enclosure, $this->escape)) !== false) {
            $count++;
        }

        fclose($this->fileHandle);
        $this->fileHandle = null;
        $this->totalFileRows = $count;

        return $count;
    }

    /**
     * 转换为UTF-8编码
     */
    private function convertEncoding($data)
    {
        if ($this->charset === 'UTF-8' || !function_exists('mb_convert_encoding')) {
            return $data;
        }

        if (!is_array($data)) {
            return mb_convert_encoding($data, 'UTF-8', $this->charset);
        }

        $converted = [];
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $converted[$key] = mb_convert_encoding($value, 'UTF-8', $this->charset);
            } else {
                $converted[$key] = $value;
            }
        }

        return $converted;
    }

    /**
     * 与表头组合
     */
    private function combineWithHeaders($row)
    {
        if (empty($this->headers)) {
            return $row;
        }

        $result = [];
        $headerCount = count($this->headers);

        for ($i = 0; $i < $headerCount; $i++) {
            $header = $this->headers[$i];
            $value = $row[$i] ?? null;
            $result[$header] = $value;
        }

        return $result;
    }

    /**
     * 获取表头
     */
    public function getHeaders()
    {
        if ($this->hasHeader && empty($this->headers)) {
            $this->openFile();
            $this->readHeaders();
            fclose($this->fileHandle);
            $this->fileHandle = null;
        }

        return $this->headers;
    }

    /**
     * 设置分隔符
     */
    public function setDelimiter($delimiter)
    {
        $this->delimiter = $delimiter;
        return $this;
    }

    /**
     * 设置包围符
     */
    public function setEnclosure($enclosure)
    {
        $this->enclosure = $enclosure;
        return $this;
    }

    /**
     * 析构函数
     */
    public function __destruct()
    {
        if ($this->fileHandle && is_resource($this->fileHandle)) {
            fclose($this->fileHandle);
        }
    }
}
