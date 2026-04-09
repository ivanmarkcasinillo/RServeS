<?php

if (!function_exists('rserves_bootstrap_schema')) {
    function rserves_schema_identifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    function rserves_table_exists(mysqli $conn, string $table): bool
    {
        $table_name = $conn->real_escape_string($table);
        $result = $conn->query("SHOW TABLES LIKE '{$table_name}'");

        return $result instanceof mysqli_result && $result->num_rows > 0;
    }

    function rserves_column_exists(mysqli $conn, string $table, string $column): bool
    {
        if (!rserves_table_exists($conn, $table)) {
            return false;
        }

        $table_name = rserves_schema_identifier($table);
        $column_name = $conn->real_escape_string($column);
        $result = $conn->query("SHOW COLUMNS FROM {$table_name} LIKE '{$column_name}'");

        return $result instanceof mysqli_result && $result->num_rows > 0;
    }

    function rserves_ensure_column(mysqli $conn, string $table, string $column, string $definition): bool
    {
        if (rserves_column_exists($conn, $table, $column)) {
            return true;
        }

        $table_name = rserves_schema_identifier($table);
        $column_name = rserves_schema_identifier($column);
        $sql = "ALTER TABLE {$table_name} ADD COLUMN {$column_name} {$definition}";

        if (!$conn->query($sql)) {
            error_log("Schema bootstrap failed for {$table}.{$column}: " . $conn->error);
        }

        return rserves_column_exists($conn, $table, $column);
    }

    function rserves_bootstrap_schema(mysqli $conn): void
    {
        static $bootstrapped = false;

        if ($bootstrapped) {
            return;
        }

        $bootstrapped = true;

        if (!rserves_table_exists($conn, 'tasks')) {
            return;
        }

        rserves_ensure_column($conn, 'tasks', 'duration', 'VARCHAR(50) NULL');
        rserves_ensure_column($conn, 'tasks', 'created_by_student', 'INT NULL');
        rserves_ensure_column($conn, 'tasks', 'created_at', 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP');
        rserves_ensure_column($conn, 'tasks', 'is_deleted', 'TINYINT(1) NOT NULL DEFAULT 0');

        if (rserves_table_exists($conn, 'student_tasks')) {
            rserves_ensure_column($conn, 'student_tasks', 'assigned_at', 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP');
            rserves_ensure_column($conn, 'student_tasks', 'completed_at', 'DATETIME NULL');
        }
    }
}
