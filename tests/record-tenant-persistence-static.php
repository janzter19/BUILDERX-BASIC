<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$helperPath = $root . '/backend/app/Services/Record/RecordTenantPersistence.php';
$dynamicPath = $root . '/backend/app/Services/Record/DynamicRecordService.php';
$searchPath = $root . '/backend/app/Services/Record/RecordSearchService.php';
$softDeletePath = $root . '/backend/app/Services/Record/RecordSoftDeleteService.php';

$helper = (string) file_get_contents($helperPath);
$dynamic = (string) file_get_contents($dynamicPath);
$search = (string) file_get_contents($searchPath);
$softDelete = (string) file_get_contents($softDeletePath);

$requiredHelperMarkers = [
    'final class RecordTenantPersistence',
    'activeRecordPredicate',
    'activeAttachmentPredicate',
    'tenantScopedActiveWhere',
    'tenantPredicate',
    'readActiveRecord',
    'readDeletedRecord',
    'afterCommitReadBack',
    "record_status', \$alias) . \" <> 'DELETED'\"",
    "deleted_at', \$alias) . ' IS NULL'",
    "attachment_status', \$alias) . \" = 'ACTIVE'",
    'branch_key',
    'project_key',
    '$this->assertUuid($value, $field);',
    '$this->db->begin_transaction();',
    '$this->db->commit();',
    '$this->db->rollback();',
    'Persistence read-back did not return a saved row.',
];

foreach ($requiredHelperMarkers as $marker) {
    if (!str_contains($helper, $marker)) {
        throw new RuntimeException('Missing tenant persistence helper marker: ' . $marker);
    }
}

$requiredDynamicMarkers = [
    'RecordTenantPersistence|null $tenantPersistence = null',
    '$this->tenantPersistence()->afterCommitReadBack',
    '$this->tenantPersistence()->readActiveRecord($tableName, $recordKey, $registry)',
    '$this->tenantPersistence()->tenantScopedActiveWhere($tenant)',
    'private function tenantPersistence(): RecordTenantPersistence',
];

foreach ($requiredDynamicMarkers as $marker) {
    if (!str_contains($dynamic, $marker)) {
        throw new RuntimeException('Missing dynamic record persistence marker: ' . $marker);
    }
}

$requiredSearchMarkers = [
    '$this->tenantPersistence()->activeRecordPredicate(null, false)',
    '$this->tenantPersistence()->tenantPredicate($filters, null, false)',
    "foreach (['form_key', 'data_record_key'] as \$field)",
];

foreach ($requiredSearchMarkers as $marker) {
    if (!str_contains($search, $marker)) {
        throw new RuntimeException('Missing record search tenant marker: ' . $marker);
    }
}

$requiredSoftDeleteMarkers = [
    '$this->tenantPersistence()->afterCommitReadBack',
    '$this->tenantPersistence()->tenantScopedActiveWhere($registry)',
    '$this->tenantPersistence()->tenantPredicate($registry)',
    '$this->tenantPersistence()->readDeletedRecord($tableName, $recordKey, $registry)',
    '$this->tenantPersistence()->readActiveRecord($tableName, $recordKey, $registry)',
];

foreach ($requiredSoftDeleteMarkers as $marker) {
    if (!str_contains($softDelete, $marker)) {
        throw new RuntimeException('Missing soft-delete tenant marker: ' . $marker);
    }
}

$attachmentPath = $root . '/backend/app/Services/Record/RecordAttachmentService.php';
$attachment = (string) file_get_contents($attachmentPath);
$requiredAttachmentMarkers = [
    'RecordTenantPersistence|null $tenantPersistence = null',
    '$this->tenantPersistence()->afterCommitReadBack',
    '$this->tenantPersistence()->activeAttachmentPredicate()',
    'branch_key = ? AND project_key = ?',
    'private function tenantPersistence(): RecordTenantPersistence',
];

foreach ($requiredAttachmentMarkers as $marker) {
    if (!str_contains($attachment, $marker)) {
        throw new RuntimeException('Missing attachment tenant metadata marker: ' . $marker);
    }
}

foreach ([$helper, $dynamic, $search, $softDelete, $attachment] as $source) {
    if (str_contains($source, '/phases/') || str_contains($source, 'PhaseAiJobStore')) {
        throw new RuntimeException('Phase Manager control-plane marker found in tenant persistence source.');
    }
}

echo json_encode([
    'tenant_persistence_helper_declared' => true,
    'active_record_filter_centralized' => true,
    'tenant_predicates_require_branch_and_project' => true,
    'partial_search_tenant_filters_preserved' => true,
    'attachment_metadata_tenant_filters_verified' => true,
    'transaction_commit_rollback_wrapped' => true,
    'post_commit_read_back_required' => true,
    'phase_manager_control_plane_untouched' => true,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
