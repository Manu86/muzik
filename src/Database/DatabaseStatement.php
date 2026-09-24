<?php

declare(strict_types=1);

final class DatabaseStatement extends PDOStatement
{
    /** @return array<mixed, mixed>|false */
    public function fetch(
        int $mode = PDO::FETCH_DEFAULT,
        int $cursorOrientation = PDO::FETCH_ORI_NEXT,
        int $cursorOffset = 0
    ): array|false {
        $row = parent::fetch($mode, $cursorOrientation, $cursorOffset);
        if ($row === false || is_array($row)) {
            return $row;
        }

        throw new RuntimeException('The SQL row is not an associative array.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchAll(
        int $mode = PDO::FETCH_DEFAULT,
        mixed ...$args
    ): array {
        if ($mode !== PDO::FETCH_DEFAULT && $mode !== PDO::FETCH_ASSOC) {
            throw new InvalidArgumentException('fetchAll() only supports associative rows.');
        }

        $rows = parent::fetchAll($mode, ...$args);
        $clean = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $item = [];
            foreach ($row as $key => $value) {
                if (is_string($key)) {
                    $item[$key] = $value;
                }
            }
            $clean[] = $item;
        }

        return $clean;
    }

    /** @return list<mixed> */
    public function fetchColumnValues(int $column = 0): array
    {
        $values = parent::fetchAll(PDO::FETCH_COLUMN, $column);

        return array_values($values);
    }
}
