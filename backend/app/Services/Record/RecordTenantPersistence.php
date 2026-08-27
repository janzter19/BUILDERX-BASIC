<?php
declare(strict_types=1);

namespace App\Services\Record;

use App\Services\TableBuilder\TableSchemaBuilder;
use mysqli;
use RuntimeException;

final class RecordTenantPersistence
{
    public function __construct(
        private readonly mysqli $db,
        private readonly TableSchemaBuilder $schemaBuilder = new TableSchemaBuilder(),
    ) {
    }

    public function activeRecordPredicate(?string $alias = null, bool $includeDeletedAt = true): string
    {
        $predicate = $this->qualifiedColumn('record_status', $alias) . " <> 'DELETED'";
        if ($includeDeletedAt) {
            $predicate .= ' AND ' . $this->qualifiedColumn('deleted_at', $alias) . ' IS NULL';
        }

        return $predicate;
    }

    public function activeAttachmentPredicate(?string $alias = null): string
    {
        return $this->qualifiedColumn('attachment_status', $alias) . " = 'ACTIVE' AND "
            . $this->qualifiedColumn('deleted_at', $alias) . ' IS NULL';
    }

    public function tenantScopedActiveWhere(array $tenant, ?string $alias = null, bool $requireCompleteScope = true, bool $includeDeletedAt = true): array
    {
        [$tenantSql, $types, $params] = $this->tenantPredicate($tenant, $alias, $requireCompleteScope);

        return [
            $this->activeRecordPredicate($alias, $includeDeletedAt) . ' AND ' . $tenantSql,
            $types,
            $params,
        ];
    }

    public function tenantPredicate(array $tenant, ?string $alias = null, bool $requireCompleteScope = true): array
    {
        $clauses = [];
        $types = '';
        $params = [];

        foreach (['branch_key', 'project_key'] as $field) {
            $value = trim((string) ($tenant[$field] ?? ''));
            if ($value === '') {
                if ($requireCompleteScope) {
                    throw new RuntimeException('Tenant branch and project keys are required.');
                }
                continue;
            }

            $this->assertUuid($value, $field);
            $clauses[] = $this->qualifiedColumn($field, $alias) . ' = ?';
            $types .= 's';
            $params[] = $value;
        }

        if ($clauses === []) {
            throw new RuntimeException('Tenant branch and project keys are required.');
        }

        return [
            implode(' AND ', $clauses),
            $types,
            $params,
        ];
    }

    public function readActiveRecord(string $tableName, string $recordKey, array $tenant): array
    {
        return $this->readRecordByStatus($tableName, $recordKey, $tenant, 'ACTIVE');
    }

    public function readDeletedRecord(string $tableName, string $recordKey, array $tenant): array
    {
        return $this->readRecordByStatus($tableName, $recordKey, $tenant, 'DELETED');
    }

    private function readRecordByStatus(string $tableName, string $recordKey, array $tenant, string $status): array
    {
        [$where, $types, $params] = $this->tenantScopedActiveWhere($tenant);
        if ($status === 'DELETED') {
            [$tenantSql, $types, $params] = $this->tenantPredicate($tenant);
            $where = "record_status = 'DELETED' AND " . $tenantSql;
        }
        $sql = 'SELECT * FROM ' . $this->schemaBuilder->quoteIdentifier($tableName)
            . ' WHERE record_key = ? AND ' . $where . ' LIMIT 1';
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException($this->db->error);
        }

        array_unshift($params, $recordKey);
        $stmt->bind_param('s' . $types, ...$params);
        $stmt->execute();
        $record = $stmt->get_result()->fetch_assoc();
        if (!$record) {
            throw new RuntimeException('Record was not found.');
        }

        return $record;
    }

    public function afterCommitReadBack(callable $mutation, callable $readBack): array
    {
        $this->db->begin_transaction();
        try {
            $mutation();
            $this->db->commit();
        } catch (\Throwable $error) {
            $this->db->rollback();
            throw $error;
        }

        $record = $readBack();
        if (!is_array($record) || $record === []) {
            throw new RuntimeException('Persistence read-back did not return a saved row.');
        }

        return $record;
    }

    private function qualifiedColumn(string $column, ?string $alias = null): string
    {
        $quotedColumn = $this->schemaBuilder->quoteIdentifier($column);
        $alias = trim((string) $alias);
        if ($alias === '') {
            return $quotedColumn;
        }

        return $this->schemaBuilder->quoteIdentifier($alias) . '.' . $quotedColumn;
    }

    private function assertUuid(string $value, string $field): void
    {
        if (preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/', $value) !== 1) {
            throw new RuntimeException("Invalid {$field}.");
        }
    }
}
