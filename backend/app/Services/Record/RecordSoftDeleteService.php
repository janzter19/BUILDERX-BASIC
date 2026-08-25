<?php
declare(strict_types=1);

namespace App\Services\Record;

use App\Services\TableBuilder\BackendTableResolver;
use App\Services\TableBuilder\TableSchemaBuilder;
use mysqli;
use RuntimeException;

final class RecordSoftDeleteService
{
    public function __construct(
        private readonly mysqli $db,
        private readonly BackendTableResolver $tableResolver,
        private readonly TableSchemaBuilder $schemaBuilder = new TableSchemaBuilder(),
        private readonly RecordIndexService|null $recordIndexService = null,
        private readonly RecordTenantPersistence|null $tenantPersistence = null,
    ) {
    }

    public function delete(string $dataRecordKey, string $recordKey, ?string $userKey = null): void
    {
        $registry = $this->tableResolver->resolveByDataRecordKey($dataRecordKey);
        $tableName = (string) $registry['record_table_name'];

        $this->tenantPersistence()->afterCommitReadBack(function () use ($tableName, $registry, $userKey, $recordKey): void {
            [$where, $tenantTypes, $tenantParams] = $this->tenantPersistence()->tenantScopedActiveWhere($registry);
            $stmt = $this->db->prepare(
                'UPDATE ' . $this->schemaBuilder->quoteIdentifier($tableName)
                . " SET record_status = 'DELETED', deleted_at = CURRENT_TIMESTAMP, deleted_by_key = ?, updated_by_key = ? WHERE record_key = ? AND " . $where
            );
            if (!$stmt) {
                throw new RuntimeException($this->db->error);
            }

            $params = [$userKey, $userKey, $recordKey, ...$tenantParams];
            $stmt->bind_param('sss' . $tenantTypes, ...$params);
            $stmt->execute();
            if ($stmt->affected_rows < 1) {
                throw new RuntimeException('Record soft delete did not affect an active row.');
            }

            ($this->recordIndexService ?? new RecordIndexService($this->db))->markStatus($recordKey, 'DELETED');
        }, fn (): array => $this->tenantPersistence()->readDeletedRecord($tableName, $recordKey, $registry));
    }

    public function restore(string $dataRecordKey, string $recordKey, ?string $userKey = null): void
    {
        $registry = $this->tableResolver->resolveByDataRecordKey($dataRecordKey);
        $tableName = (string) $registry['record_table_name'];

        $this->tenantPersistence()->afterCommitReadBack(function () use ($tableName, $registry, $userKey, $recordKey): void {
            [$tenantSql, $tenantTypes, $tenantParams] = $this->tenantPersistence()->tenantPredicate($registry);
            $stmt = $this->db->prepare(
                'UPDATE ' . $this->schemaBuilder->quoteIdentifier($tableName)
                . " SET record_status = 'ACTIVE', deleted_at = NULL, deleted_by_key = NULL, updated_by_key = ? WHERE record_key = ? AND record_status = 'DELETED' AND " . $tenantSql
            );
            if (!$stmt) {
                throw new RuntimeException($this->db->error);
            }

            $params = [$userKey, $recordKey, ...$tenantParams];
            $stmt->bind_param('ss' . $tenantTypes, ...$params);
            $stmt->execute();
            if ($stmt->affected_rows < 1) {
                throw new RuntimeException('Record restore did not affect a deleted row.');
            }

            ($this->recordIndexService ?? new RecordIndexService($this->db))->markStatus($recordKey, 'ACTIVE');
        }, fn (): array => $this->tenantPersistence()->readActiveRecord($tableName, $recordKey, $registry));
    }

    private function tenantPersistence(): RecordTenantPersistence
    {
        return $this->tenantPersistence ?? new RecordTenantPersistence($this->db, $this->schemaBuilder);
    }
}
