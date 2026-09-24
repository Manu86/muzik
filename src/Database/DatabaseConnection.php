<?php

declare(strict_types=1);

final class DatabaseConnection extends PDO
{
    /** @param array<int|string, mixed> $options */
    public function prepare(string $query, array $options = []): DatabaseStatement
    {
        $statement = parent::prepare($query, $options);
        if (!$statement instanceof DatabaseStatement) {
            throw new RuntimeException('Unable to prepare the SQL statement.');
        }

        return $statement;
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): DatabaseStatement
    {
        $statement = $fetchMode === null
            ? parent::query($query)
            : parent::query($query, $fetchMode, ...$fetchModeArgs);
        if (!$statement instanceof DatabaseStatement) {
            throw new RuntimeException('Unable to execute the SQL query.');
        }

        return $statement;
    }
}
