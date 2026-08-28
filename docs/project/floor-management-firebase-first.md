# Floor Management API/MySQL contract

`project_building_floor` is an API/masterlist-derived lookup table. It is not a
Firebase/TRAVERSE collection. The approved Bed Masterlist source remains the
authority for branch, building, and floor rows.

## MySQL table contract

On first Floor Management open, the application checks for
`project_building_floor`. If it is absent, it creates the table and derives all
rows from the existing `project_bed` masterlist/API data. The current fields are
`building_floor_key`, `branch_key`, `branch_name`, `building_key`,
`building_name`, `floor_key`, `floor_name`, `building_sort_order`,
`floor_sort_order`, and `floor_status`, plus the internal `x_id` key and audit
columns required by this local lookup.

`building_floor_key` is the stable source-derived key from the API's
branch/building/floor identity. It is not a Firebase document ID because this
table is not Firebase-backed.

## MySQL projection

The table is created by the API/masterlist path with `x_id BIGINT UNSIGNED
AUTO_INCREMENT PRIMARY KEY`, a unique `building_floor_key`, scoped uniqueness on
`branch_key, building_key, floor_key`, and ordering/status indexes.

Required indexes are the unique `building_floor_key`, scoped lookup on
`branch_key, building_key, floor_key`, ordering on
`branch_key, building_key, building_sort_order, floor_sort_order`, and status/
sync-status indexes used by TRAVERSE.

## Runtime flow

1. The approved masterlist/API refresh supplies `project_bed` rows.
2. Opening Floor Management calls the schema/discovery routine.
3. The routine creates the table if missing and inserts missing floor scopes,
   then verifies the row count/read-back.
4. For manual ordering, the Administrator sends the complete API-derived floor
   set to Firebase with the new `floor_sort_order` values first.
5. Only after Firebase acknowledges and reads back the write does the server
   update the local MySQL order projection.

Status changes remain API/masterlist-managed until their separate Firebase-first
mutation contract is enabled; they are not part of the manual ordering change.

Bed Masterlist synchronization remains a separate approved source workflow and
is intentionally not routed through TRAVERSE.
