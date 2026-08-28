<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$foundation = file_get_contents($root . '/app/foundation.php');
$administrator = file_get_contents($root . '/administrator/index.php');
$phases = file_get_contents($root . '/phases/index.php');
$frontend = file_get_contents($root . '/frontend/src/App.tsx');
$firebaseBedReferenceSync = file_get_contents($root . '/scripts/firebase-bed-reference-sync.mjs');
$firebaseBedSync = file_get_contents($root . '/scripts/firebase-bed-sync.mjs');

foreach ([
    'app/foundation.php' => $foundation,
    'administrator/index.php' => $administrator,
    'phases/index.php' => $phases,
    'frontend/src/App.tsx' => $frontend,
    'scripts/firebase-bed-reference-sync.mjs' => $firebaseBedReferenceSync,
    'scripts/firebase-bed-sync.mjs' => $firebaseBedSync,
] as $label => $source) {
    if (!is_string($source) || $source === '') {
        throw new RuntimeException($label . ' is not readable.');
    }
}

$foundationMarkers = [
    'CREATE TABLE IF NOT EXISTS project_bed',
    'CREATE TABLE IF NOT EXISTS project_bed_analytics',
    'bed_key VARCHAR(40) NOT NULL UNIQUE',
    'RENAME TABLE project_bed_list TO project_bed',
    'RENAME TABLE bed_master_list TO project_bed',
    "'firebaseDocumentIdFormat' => 'bed_key'",
    "TRIM(bed_key) REGEXP '^[A-Za-z0-9]{20}$'",
    'function bx_project_bed_firebase_rows',
    'function bx_project_bed_floor_group_key',
    'function bx_project_bed_floor_documents',
    'function bx_ensure_project_bed_analytics_schema',
    'function bx_refresh_project_bed_analytics',
    'function bx_project_bed_analytics_documents',
    'function bx_project_bed_analytics_rows',
    'function bx_sync_project_bed_rows_to_firebase',
    'function bx_sync_project_bed_to_firebase',
    'function bx_project_bed_lookup_rows',
    'function bx_project_bed_lookup_options',
    'function bx_project_bed_lookup_where',
    'function bx_resync_project_bed',
    'function bx_bed_master_list_group_counts',
    'function bx_update_project_bed_reference_sort_order',
    "'groupCounts' => bx_bed_master_list_group_counts()",
    "'analyticsRows' =>",
    'bx_project_bed_floor_documents(null, $floorKeys',
    "bx_project_bed_analytics_key('GROUP_DOCUMENT'",
    "'row_count' => count(\$groupRows)",
    "'availableRows' =>",
    "'vacantRows' =>",
    "'occupiedRows' =>",
    "'nurse_station_name', 'label' => 'Nurse station'",
    "'room_key', 'label' => 'Room'",
    "'source_bed_status', 'label' => 'Bed status'",
    'ON DUPLICATE KEY UPDATE',
    "bx_audit('SYNC', 'project_bed_analytics'",
];
foreach ($foundationMarkers as $marker) {
    if (!str_contains($foundation, $marker)) {
        throw new RuntimeException('Managed bed foundation marker is missing: ' . $marker);
    }
}

$analyticsDocumentGroups = [
    "'key' => 'managed_status'",
    "'key' => 'branch_name'",
    "'key' => 'building_name'",
    "'key' => 'floor_name'",
    "'key' => 'nurse_station_name'",
    "'key' => 'room_key'",
    "'key' => 'room_class'",
    "'key' => 'source_bed_status'",
];
foreach ($analyticsDocumentGroups as $marker) {
    if (!str_contains($foundation, $marker)) {
        throw new RuntimeException('Managed bed analytics group marker is missing: ' . $marker);
    }
}

foreach ([
    'firebase_document_id CHAR',
    'ADD COLUMN firebase_document_id',
    'bed_key, firebase_document_id',
] as $marker) {
    if (str_contains($foundation, $marker)) {
        throw new RuntimeException('Managed bed foundation must not define a separate Firebase document id field: ' . $marker);
    }
}

foreach (['resync_bed_master_list', 'bx_resync_bed_master_list', 'Bed list refreshed.'] as $marker) {
    if (str_contains($administrator, $marker) || str_contains($frontend, $marker)) {
        throw new RuntimeException('Obsolete Bed Summary refresh marker remains: ' . $marker);
    }
}

foreach (['resync_project_bed', 'bx_resync_project_bed', 'Bed row refreshed.'] as $marker) {
    if (!str_contains($administrator, $marker)) {
        throw new RuntimeException('Administrator bed lookup sync marker is missing: ' . $marker);
    }
}

foreach ([
    'bx_admin_bed_reference_firebase_step',
] as $marker) {
    if (!str_contains($administrator, $marker)) {
        throw new RuntimeException('Administrator bed reference Firebase marker is missing: ' . $marker);
    }
}

$adminResyncStart = strpos($administrator, "if (\$action === 'resync_project_bed')");
$adminResyncEnd = strpos($administrator, "if (\$action === 'apply_runtime_project_config')", $adminResyncStart === false ? 0 : $adminResyncStart);
if ($adminResyncStart === false || $adminResyncEnd === false) {
    throw new RuntimeException('Unable to isolate the Administrator Bed Lookup resync action.');
}
$adminResyncSource = substr($administrator, $adminResyncStart, $adminResyncEnd - $adminResyncStart);
foreach ([
    'bx_admin_project_bed_firebase_step',
    'Bed reports refreshed',
    'report group',
    'floor group',
    'Bed list update was not completed. Please check the service status and try again.',
    "'firebaseSync'",
    "'firebase_sync' => \$firebaseSync",
] as $marker) {
    if (str_contains($adminResyncSource, $marker)) {
        throw new RuntimeException('Administrator Bed Lookup must not retain downstream Firebase refresh marker: ' . $marker);
    }
}

$resyncStart = strpos($foundation, 'function bx_resync_project_bed');
$resyncEnd = strpos($foundation, 'function bx_setting', $resyncStart === false ? 0 : $resyncStart);
if ($resyncStart === false || $resyncEnd === false) {
    throw new RuntimeException('Unable to isolate bx_resync_project_bed for the downstream sync guard.');
}
$resyncSource = substr($foundation, $resyncStart, $resyncEnd - $resyncStart);
foreach ([
    'bx_sync_project_bed_rows_to_firebase',
    'bx_refresh_project_bed_analytics',
    'bx_project_bed_firebase_rows',
    'bx_project_bed_analytics_documents',
] as $marker) {
    if (str_contains($resyncSource, $marker)) {
        throw new RuntimeException('bx_resync_project_bed must not trigger downstream Firebase/task/analytics work: ' . $marker);
    }
}
if (!str_contains($resyncSource, 'bx_project_bed_source_row') || !str_contains($foundation, 'FROM `RBMS_BedMasterlist`')) {
    throw new RuntimeException('bx_resync_project_bed must retain the external RBMS_BedMasterlist source helper.');
}
if (!str_contains($resyncSource, 'UPDATE project_bed')) {
    throw new RuntimeException('bx_resync_project_bed must retain the MySQL project_bed update.');
}

foreach ([
    "collection('project_bed')",
    "collection('project_bed_analytics')",
    "collection('project_bed_floor')",
    'bed_key_invalid_firebase_document_id',
    'analytics_key_invalid_firebase_document_id',
    'floor_group_key_invalid_firebase_document_id',
    'analytics_synced',
    'floor_synced',
    'deleteStaleFloorGroups',
    'floor_replace_all',
    'deleteStaleAnalytics',
    "batch.delete(doc.ref)",
    'row_count: Number(row.row_count || rows.length)',
    'rows,',
    'server_synced_at: FieldValue.serverTimestamp()',
    "firebase_collection: 'project_bed'",
    "firebase_collection: 'project_bed_analytics'",
    "firebase_collection: 'project_bed_floor'",
] as $marker) {
    if (!str_contains($firebaseBedSync, $marker)) {
        throw new RuntimeException('Firebase project bed sync marker is missing: ' . $marker);
    }
}

foreach ([
    "collection: 'project_bed_treatment'",
    "collection: 'project_bed_source'",
    "key: 'bed_treatment_key'",
    "key: 'bed_source_key'",
    'server_synced_at: FieldValue.serverTimestamp()',
    "doc(normalized.documentKey)",
] as $marker) {
    if (!str_contains($firebaseBedReferenceSync, $marker)) {
        throw new RuntimeException('Firebase bed reference sync marker is missing: ' . $marker);
    }
}

foreach ([
    "'bed-management', 'bed-lookup', 'bed-treatment', 'bed-source', 'task-builder'",
    "\$params = ['tab' => 'bed-lookup'];",
    'update_bed_reference_sort_order',
] as $marker) {
    if (!str_contains($administrator, $marker)) {
        throw new RuntimeException('Administrator bed management route marker is missing: ' . $marker);
    }
}

$frontendMarkers = [
    'bedMasterListSummary',
    'bedLookupOptions',
    'bedGroupCounts',
    'BedAnalyticsCanvas',
    'BedGroupAnalyticsCanvas',
    'drawPieChart',
    'requestAnimationFrame',
    'pointermove',
    'BedFieldGroupReport',
    'BedLookupResultCard',
    'BedLookupFilterSelect',
    'Bed Lookup',
    'Lookup Filters',
    'Lookup Results Canvas',
    'Refresh this bed',
    'tab" value="bed-lookup"',
    'Analytics Count Report',
    'Field Group Reports',
    'Total, available, and vacant beds per group.',
    'Total Bed',
    'Available',
    'Vacant',
    'project_bed',
    'resync_project_bed',
    'Latest hospital bed list',
    'border-sky-500/30 bg-sky-500/10 text-sky-700',
    'Usage',
    'name="return_tab" value={returnTab}',
];
foreach ($frontendMarkers as $marker) {
    if (!str_contains($frontend, $marker)) {
        throw new RuntimeException('Frontend managed bed marker is missing: ' . $marker);
    }
}

$bedCardStart = strpos($frontend, 'function BedLookupResultCard');
$bedCardEnd = strpos($frontend, 'const bedLookupDropdownFields', $bedCardStart === false ? 0 : $bedCardStart);
if ($bedCardStart === false || $bedCardEnd === false) {
    throw new RuntimeException('Unable to isolate the Bed Lookup result card.');
}
$bedCardSource = substr($frontend, $bedCardStart, $bedCardEnd - $bedCardStart);
if (!str_contains($bedCardSource, 'onClick={() => formRef.current?.requestSubmit()}')) {
    throw new RuntimeException('Bed Lookup refresh must submit through the single global confirmation flow.');
}
if (!str_contains($bedCardSource, 'data-skip-shell-loading="true"')) {
    throw new RuntimeException('Bed Lookup refresh must not hold the global shell loading lock during its redirect.');
}
if (!str_contains($frontend, 'adminNetworkLoading && activeView !== \'bed-lookup\'')) {
    throw new RuntimeException('Bed Lookup must hide the global shell loading indicators.');
}
$filterFormStart = strpos($frontend, '<form method="get" action={projectUrl(\'administrator/\')}');
if ($filterFormStart === false || !str_contains(substr($frontend, $filterFormStart, 240), 'data-skip-submit-confirmation="true"')) {
    throw new RuntimeException('Bed Lookup search filters must submit without confirmation.');
}
foreach ([
    "title: 'Refresh this bed'",
    'setConfirmation',
] as $marker) {
    if (str_contains($bedCardSource, $marker)) {
        throw new RuntimeException('Bed Lookup refresh must not define a second confirmation flow: ' . $marker);
    }
}

foreach (['AS available', 'AS vacant', "'available' =>", "'vacant' =>"] as $marker) {
    if (!str_contains($foundation, $marker)) {
        throw new RuntimeException('Managed bed grouped availability marker is missing: ' . $marker);
    }
}

echo json_encode([
    'managed_bed_table' => 'project_bed',
    'source_table' => 'RBMS_BedMasterlist',
    'administrator_resync_action' => true,
    'phase_manager_resync_action' => true,
    'project_bed_analytics_table' => true,
    'firebase_bed_analytics_sync' => true,
    'firebase_bed_analytics_documents' => 8,
    'firebase_bed_floor_groups' => true,
    'downstream_bed_firebase_refresh' => false,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
