<?php

namespace Jerry2026\DbTool\Console\Commands\Db\Concerns;

use Illuminate\Support\Facades\DB;

trait SelectsExportTargets
{
    protected function resolveExportTargets(array $options = []): ?array
    {
        $connection = $this->option('connection');
        if (empty($connection)) {
            $connections = $this->getConnectionChoices();
            $connection = $this->choice(
                '请选择数据库连接',
                $connections,
                config('database.default')
            );
        }

        $database = $this->option('database');
        if (empty($database)) {
            $databases = $this->getDatabaseChoices($connection);
            if (empty($databases)) {
                $this->error('没有获取到可选数据库。');
                return null;
            }

            $database = $this->choice(
                '请选择数据库',
                $databases,
                config("database.connections.{$connection}.database")
            );
        }

        $tableOption = $this->option('table');
        if (!empty($tableOption)) {
            return [
                $connection,
                $database,
                array_values(array_filter(array_map('trim', explode(',', $tableOption)))),
            ];
        }

        $allTables = $this->getAllTables($connection, $database);
        $this->writeStatusLine('database tables', 'DONE');

        if (!empty($options['filter']) && is_callable($options['filter'])) {
            $allTables = array_values(array_filter($allTables, $options['filter']));
        }

        if (empty($allTables)) {
            $this->error('没有找到任何数据表。');
            return null;
        }

        $tableChoices = array_merge(['全部表'], $allTables);
        $selected = $this->choice(
            '请选择要导出的表（可多选，用逗号分隔；直接回车导出全部）',
            $tableChoices,
            '0',
            3,
            true
        );

        $selected = is_array($selected) ? $selected : [$selected];
        $tables = in_array('全部表', $selected, true)
            ? $allTables
            : array_values(array_intersect($allTables, $selected));

        $this->line('将导出以下 ' . count($tables) . ' 张表: ' . implode(', ', $tables));

        return [$connection, $database, $tables];
    }

    protected function getConnectionChoices(): array
    {
        $connections = array_keys(config('database.connections', []));
        $default = config('database.default');

        if (is_string($default) && $default !== '' && !in_array($default, $connections, true)) {
            array_unshift($connections, $default);
        }

        return array_values(array_unique($connections));
    }

    protected function getDatabaseChoices(string $connection): array
    {
        $default = config("database.connections.{$connection}.database");
        $databases = [];

        try {
            $rows = DB::connection($connection)->select('SHOW DATABASES');
            foreach ($rows as $row) {
                $row = (array) $row;
                $name = $row['Database'] ?? $row['database'] ?? null;
                if (is_string($name) && $name !== '') {
                    $databases[] = $name;
                }
            }
        } catch (\Throwable) {
        }

        if (is_string($default) && $default !== '') {
            array_unshift($databases, $default);
        }

        return array_values(array_unique($databases));
    }

    protected function getAllTables(string $connection, string $database): array
    {
        $tables = [];
        $result = DB::connection($connection)->select("SHOW FULL TABLES WHERE Table_type != 'VIEW'");
        $key = 'Tables_in_' . $database;

        foreach ($result as $row) {
            $name = $row->$key ?? null;
            if (is_string($name) && $name !== '') {
                $tables[] = $name;
            }
        }

        return $tables;
    }

    protected function writeStatusLine(string $label, string $status = 'DONE', string $style = 'info'): void
    {
        $dots = str_repeat('.', max(3, 100 - strlen($label) - strlen($status)));

        $this->line(sprintf(
            '  %s %s <%s>%s</%s>',
            $label,
            $dots,
            $style,
            $status,
            $style
        ));
    }
}
