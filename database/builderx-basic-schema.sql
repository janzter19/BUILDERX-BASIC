/*M!999999\- enable the sandbox mode */

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `RBMS_BedMasterlist`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `RBMS_BedMasterlist` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `PK_psBeds` varchar(100) DEFAULT NULL,
  `bedno` varchar(100) DEFAULT NULL,
  `PK_mscBranches` varchar(100) DEFAULT NULL,
  `branchname` varchar(100) DEFAULT NULL,
  `PK_mscBldgs` varchar(100) DEFAULT NULL,
  `bldgname` varchar(100) DEFAULT NULL,
  `PK_mscBldgFloors` varchar(100) DEFAULT NULL,
  `floorname` varchar(100) DEFAULT NULL,
  `PK_mscNrstation` varchar(100) DEFAULT NULL,
  `Nrstation` varchar(100) DEFAULT NULL,
  `PK_psRooms` varchar(100) DEFAULT NULL,
  `PK_mscRoomClass` varchar(100) DEFAULT NULL,
  `RoomClass` varchar(100) DEFAULT NULL,
  `PK_mscBedStatus` varchar(100) DEFAULT NULL,
  `BedStatus` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `PK_psBeds` (`PK_psBeds`)
) ENGINE=InnoDB AUTO_INCREMENT=405 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `RBMS_CheckBedStatus`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `RBMS_CheckBedStatus` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `PK_psBeds` varchar(50) DEFAULT NULL,
  `bedno` varchar(50) DEFAULT NULL,
  `PK_mscBedStatus` varchar(50) DEFAULT NULL,
  `BedStatus` varchar(50) DEFAULT NULL,
  `FK_emdPatients` varchar(50) DEFAULT NULL,
  `PK_psAdmissions` varchar(50) DEFAULT NULL,
  `PK_psPatRegisters` varchar(50) DEFAULT NULL,
  `admsource` varchar(50) DEFAULT NULL,
  `patientName` varchar(50) DEFAULT NULL,
  `patientDays` varchar(50) DEFAULT NULL,
  `registrationDate` varchar(50) DEFAULT NULL,
  `registrationTime` varchar(50) DEFAULT NULL,
  `mainAttending` varchar(50) DEFAULT NULL,
  `admissionDiagnosis` text DEFAULT NULL,
  `fullname` varchar(50) DEFAULT NULL,
  `registrystatus` varchar(50) DEFAULT NULL,
  `gender` varchar(50) DEFAULT NULL,
  `birthdate` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `PK_psBeds` (`PK_psBeds`)
) ENGINE=InnoDB AUTO_INCREMENT=951 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `builder_ai_approval`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `builder_ai_approval` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `approval_key` varchar(128) NOT NULL,
  `operation` enum('delete','move','database','backup','audit') NOT NULL,
  `target` varchar(1024) NOT NULL,
  `target_hash` varchar(128) NOT NULL,
  `actor_user_key` char(36) DEFAULT NULL,
  `approval_status` enum('pending','approved','consumed','expired','rejected') NOT NULL DEFAULT 'pending',
  `approved_by_key` char(36) DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `approved_at` timestamp NULL DEFAULT NULL,
  `consumed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `approval_key` (`approval_key`),
  KEY `idx_builder_ai_approval_status` (`approval_status`),
  KEY `idx_builder_ai_approval_expiry` (`expires_at`),
  KEY `idx_builder_ai_approval_actor` (`actor_user_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `builder_ai_memory`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `builder_ai_memory` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `memory_key` varchar(128) NOT NULL,
  `memory_version` int(10) unsigned NOT NULL DEFAULT 1,
  `title` varchar(240) NOT NULL,
  `content` longtext NOT NULL,
  `memory_type` enum('brand_rule','decision','instruction','example','task_result','reference') NOT NULL,
  `retrieval_types_json` text NOT NULL,
  `tags_json` text NOT NULL,
  `metadata_json` longtext NOT NULL,
  `source_reference` varchar(512) DEFAULT NULL,
  `parent_memory_key` varchar(128) DEFAULT NULL,
  `memory_status` enum('pending_approval','approved','archived','rejected') NOT NULL DEFAULT 'pending_approval',
  `review_status` enum('unreviewed','approved','rejected','needs_revision') NOT NULL DEFAULT 'unreviewed',
  `vault_path` varchar(512) DEFAULT NULL,
  `owner_user_key` char(36) DEFAULT NULL,
  `approved_by_key` char(36) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `approved_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `memory_key` (`memory_key`),
  KEY `idx_builder_ai_memory_status` (`memory_status`),
  KEY `idx_builder_ai_memory_type` (`memory_type`),
  KEY `idx_builder_ai_memory_parent` (`parent_memory_key`),
  KEY `idx_builder_ai_memory_updated` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `builder_ai_specialist`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `builder_ai_specialist` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `specialist_key` varchar(128) NOT NULL,
  `specialist_version` varchar(32) NOT NULL DEFAULT '1.0.0',
  `specialist_name` varchar(120) NOT NULL,
  `purpose` text NOT NULL,
  `stages_json` text NOT NULL,
  `skills_json` text NOT NULL,
  `allowed_tools_json` text NOT NULL,
  `write_scope` enum('none','communication_only','build_allowlist','phase_manager_approval') NOT NULL DEFAULT 'none',
  `rag_scopes_json` text NOT NULL,
  `specialist_status` enum('pending_approval','active','inactive','retired') NOT NULL DEFAULT 'pending_approval',
  `review_status` enum('unreviewed','approved','rejected','needs_revision') NOT NULL DEFAULT 'unreviewed',
  `approval_reference` varchar(128) DEFAULT NULL,
  `is_temporary` tinyint(1) NOT NULL DEFAULT 0,
  `owner_user_key` char(36) DEFAULT NULL,
  `evidence_json` longtext DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `specialist_key` (`specialist_key`),
  KEY `idx_builder_ai_specialist_status` (`specialist_status`),
  KEY `idx_builder_ai_specialist_review` (`review_status`),
  KEY `idx_builder_ai_specialist_owner` (`owner_user_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `builder_ai_task`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `builder_ai_task` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `task_key` varchar(128) NOT NULL,
  `correlation_id` varchar(128) NOT NULL,
  `parent_task_key` varchar(128) DEFAULT NULL,
  `action` varchar(80) NOT NULL,
  `stage` enum('Think','Design','Build','Validate','Document','Preserve') NOT NULL,
  `specialist` varchar(80) NOT NULL,
  `task_status` enum('queued','running','awaiting_approval','completed','failed','cancelled') NOT NULL DEFAULT 'queued',
  `input_json` longtext NOT NULL,
  `output_json` longtext DEFAULT NULL,
  `error_json` longtext DEFAULT NULL,
  `permissions_json` text NOT NULL,
  `attempt` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `created_by_key` char(36) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `task_key` (`task_key`),
  KEY `idx_builder_ai_task_correlation` (`correlation_id`),
  KEY `idx_builder_ai_task_status` (`task_status`),
  KEY `idx_builder_ai_task_owner` (`created_by_key`),
  KEY `idx_builder_ai_task_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `builder_android_client_app`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `builder_android_client_app` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `client_app_key` char(36) NOT NULL,
  `client_app_code` varchar(40) NOT NULL,
  `client_name` varchar(160) NOT NULL,
  `firebase_project_id` varchar(120) NOT NULL,
  `firebase_database_url` varchar(255) NOT NULL,
  `firebase_firestore_database_id` varchar(120) NOT NULL DEFAULT '(default)',
  `firebase_api_key` varchar(180) NOT NULL DEFAULT '',
  `firebase_app_id` varchar(180) NOT NULL DEFAULT '',
  `firebase_messaging_sender_id` varchar(80) NOT NULL DEFAULT '',
  `firebase_storage_bucket` varchar(180) NOT NULL DEFAULT '',
  `android_package_name` varchar(160) NOT NULL,
  `apk_download_path` varchar(500) NOT NULL DEFAULT '',
  `banner_image_url` text DEFAULT NULL,
  `login_background_image_url` text DEFAULT NULL,
  `splash_screen_image_url_1` text DEFAULT NULL,
  `splash_screen_image_url_2` text DEFAULT NULL,
  `splash_screen_image_url_3` text DEFAULT NULL,
  `splash_screen_image_url_4` text DEFAULT NULL,
  `current_version_code` int(10) unsigned NOT NULL DEFAULT 1,
  `min_supported_version_code` int(10) unsigned NOT NULL DEFAULT 1,
  `force_update_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `release_acknowledgement_required` tinyint(1) NOT NULL DEFAULT 1,
  `geofence_required` tinyint(1) NOT NULL DEFAULT 0,
  `geofence_latitude` decimal(10,7) DEFAULT NULL,
  `geofence_longitude` decimal(10,7) DEFAULT NULL,
  `geofence_max_radius_meters` int(10) unsigned NOT NULL DEFAULT 100,
  `offline_queue_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `offline_retry_interval_seconds` int(10) unsigned NOT NULL DEFAULT 300,
  `dashboard_refresh_seconds` int(10) unsigned NOT NULL DEFAULT 60,
  `media_upload_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `client_app_status` enum('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `client_app_key` (`client_app_key`),
  UNIQUE KEY `client_app_code` (`client_app_code`),
  KEY `idx_android_client_app_status` (`client_app_status`,`client_app_code`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `builder_audit_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `builder_audit_log` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `audit_key` char(36) NOT NULL,
  `user_key` char(36) DEFAULT NULL,
  `action` varchar(80) NOT NULL,
  `module` varchar(80) NOT NULL,
  `record_key` char(36) DEFAULT NULL,
  `previous_values` longtext DEFAULT NULL,
  `new_values` longtext DEFAULT NULL,
  `ip_address` varchar(80) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `branch_key` char(36) DEFAULT NULL,
  `project_key` char(36) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `audit_key` (`audit_key`),
  KEY `idx_builder_audit_user` (`user_key`),
  KEY `idx_builder_audit_action` (`action`),
  KEY `idx_builder_audit_module` (`module`),
  KEY `idx_builder_audit_created` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=278348 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `builder_branch`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `builder_branch` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `branch_key` char(36) NOT NULL,
  `branch_name` varchar(160) NOT NULL,
  `branch_code` varchar(40) NOT NULL,
  `branch_status` enum('DRAFT','ACTIVE','INACTIVE','ARCHIVED','DELETED') NOT NULL DEFAULT 'ACTIVE',
  `branch_address` text DEFAULT NULL,
  `branch_contact` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `branch_key` (`branch_key`),
  UNIQUE KEY `branch_code` (`branch_code`),
  KEY `idx_builder_branch_status` (`branch_status`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `builder_family_member`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `builder_family_member` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `member_key` char(36) NOT NULL,
  `owner_user_key` char(36) NOT NULL,
  `first_name` varchar(80) NOT NULL,
  `middle_name` varchar(80) DEFAULT NULL,
  `last_name` varchar(80) NOT NULL,
  `suffix` varchar(40) DEFAULT NULL,
  `birth_date` date DEFAULT NULL,
  `relationship_to_user` varchar(80) NOT NULL,
  `contact_email` varchar(190) DEFAULT NULL,
  `contact_phone` varchar(40) DEFAULT NULL,
  `consent_privacy` tinyint(1) NOT NULL DEFAULT 0,
  `consent_contact` tinyint(1) NOT NULL DEFAULT 0,
  `member_status` enum('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  `member_created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `member_created_by_key` char(36) DEFAULT NULL,
  `member_updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `member_updated_by_key` char(36) DEFAULT NULL,
  `member_deleted_at` timestamp NULL DEFAULT NULL,
  `member_deleted_by_key` char(36) DEFAULT NULL,
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `member_key` (`member_key`),
  KEY `idx_builder_family_member_owner` (`owner_user_key`),
  KEY `idx_builder_family_member_status` (`member_status`),
  KEY `idx_builder_family_member_lookup` (`owner_user_key`,`last_name`,`first_name`,`birth_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `builder_family_member_education`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `builder_family_member_education` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `education_key` char(36) NOT NULL,
  `member_key` char(36) NOT NULL,
  `owner_user_key` char(36) NOT NULL,
  `education_level` varchar(80) NOT NULL,
  `institution_name` varchar(190) NOT NULL,
  `program_name` varchar(190) DEFAULT NULL,
  `date_started` date DEFAULT NULL,
  `date_completed` date DEFAULT NULL,
  `completion_status` varchar(80) NOT NULL,
  `education_status` enum('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  `education_created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `education_created_by_key` char(36) DEFAULT NULL,
  `education_updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `education_updated_by_key` char(36) DEFAULT NULL,
  `education_deleted_at` timestamp NULL DEFAULT NULL,
  `education_deleted_by_key` char(36) DEFAULT NULL,
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `education_key` (`education_key`),
  KEY `idx_builder_family_education_member` (`member_key`),
  KEY `idx_builder_family_education_owner` (`owner_user_key`),
  KEY `idx_builder_family_education_lookup` (`owner_user_key`,`education_level`,`institution_name`),
  KEY `idx_builder_family_education_status` (`education_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `builder_family_member_vehicle`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `builder_family_member_vehicle` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `vehicle_key` char(36) NOT NULL,
  `member_key` char(36) NOT NULL,
  `owner_user_key` char(36) NOT NULL,
  `plate_number` varchar(40) NOT NULL,
  `make` varchar(80) DEFAULT NULL,
  `model` varchar(80) DEFAULT NULL,
  `model_year` smallint(5) unsigned DEFAULT NULL,
  `color` varchar(60) DEFAULT NULL,
  `ownership_type` varchar(80) NOT NULL,
  `registration_status` varchar(80) DEFAULT NULL,
  `vehicle_status` enum('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  `vehicle_created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `vehicle_created_by_key` char(36) DEFAULT NULL,
  `vehicle_updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `vehicle_updated_by_key` char(36) DEFAULT NULL,
  `vehicle_deleted_at` timestamp NULL DEFAULT NULL,
  `vehicle_deleted_by_key` char(36) DEFAULT NULL,
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `vehicle_key` (`vehicle_key`),
  KEY `idx_builder_family_vehicle_member` (`member_key`),
  KEY `idx_builder_family_vehicle_owner` (`owner_user_key`),
  KEY `idx_builder_family_vehicle_plate` (`owner_user_key`,`plate_number`),
  KEY `idx_builder_family_vehicle_status` (`vehicle_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `builder_group`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `builder_group` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `group_key` char(36) NOT NULL,
  `group_name` varchar(120) NOT NULL,
  `group_description` text DEFAULT NULL,
  `group_status` enum('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `group_key` (`group_key`),
  UNIQUE KEY `group_name` (`group_name`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `builder_permission`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `builder_permission` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `permission_key` char(36) NOT NULL,
  `permission_code` varchar(120) NOT NULL,
  `permission_name` varchar(160) NOT NULL,
  `permission_scope` varchar(60) NOT NULL,
  `permission_status` enum('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `permission_key` (`permission_key`),
  UNIQUE KEY `permission_code` (`permission_code`)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `builder_phase`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `builder_phase` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `phase_key` char(36) NOT NULL,
  `phase_number` int(10) unsigned NOT NULL,
  `phase_code` varchar(20) DEFAULT NULL,
  `phase_title` varchar(150) NOT NULL,
  `phase_summary` text DEFAULT NULL,
  `phase_status` varchar(30) NOT NULL DEFAULT 'Not Started',
  `phase_sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `phase_key` (`phase_key`),
  UNIQUE KEY `uq_builder_phase_code` (`phase_code`),
  KEY `idx_builder_phase_status` (`phase_status`),
  KEY `idx_builder_phase_sort` (`phase_sort_order`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `builder_phase_task`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `builder_phase_task` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `task_key` char(36) NOT NULL,
  `phase_key` char(36) NOT NULL,
  `task_code` varchar(30) DEFAULT NULL,
  `task_title` varchar(255) NOT NULL,
  `task_details` text DEFAULT NULL,
  `task_reference` mediumtext DEFAULT NULL,
  `task_scope` mediumtext DEFAULT NULL,
  `task_acceptance_checklist` mediumtext DEFAULT NULL,
  `task_exclusions` mediumtext DEFAULT NULL,
  `task_notes` text DEFAULT NULL,
  `is_completed` tinyint(1) NOT NULL DEFAULT 0,
  `task_status` varchar(20) NOT NULL DEFAULT 'ACTIVE',
  `task_sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `task_key` (`task_key`),
  UNIQUE KEY `uq_builder_phase_task_order` (`phase_key`,`task_sort_order`),
  UNIQUE KEY `uq_builder_phase_task_code` (`task_code`),
  KEY `idx_builder_phase_task_phase` (`phase_key`),
  KEY `idx_builder_phase_task_status` (`task_status`),
  KEY `idx_builder_phase_task_completed` (`is_completed`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `builder_phase_task_checklist`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `builder_phase_task_checklist` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `checklist_key` char(36) NOT NULL,
  `task_key` char(36) NOT NULL,
  `checklist_text` text NOT NULL,
  `is_done` tinyint(1) NOT NULL DEFAULT 0,
  `checklist_status` varchar(20) NOT NULL DEFAULT 'ACTIVE',
  `checklist_sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `checklist_key` (`checklist_key`),
  KEY `idx_builder_phase_task_checklist_task` (`task_key`),
  KEY `idx_builder_phase_task_checklist_status` (`checklist_status`),
  KEY `idx_builder_phase_task_checklist_done` (`is_done`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `builder_phase_task_note`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `builder_phase_task_note` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `note_key` char(36) NOT NULL,
  `task_key` char(36) NOT NULL,
  `note_body` text NOT NULL,
  `note_status` enum('ACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `note_key` (`note_key`),
  KEY `idx_builder_phase_note_task` (`task_key`,`note_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `builder_project`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `builder_project` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `project_key` char(36) NOT NULL,
  `branch_key` char(36) NOT NULL,
  `project_name` varchar(160) NOT NULL,
  `project_code` varchar(40) NOT NULL,
  `project_status` enum('DRAFT','ACTIVE','INACTIVE','ARCHIVED','DELETED') NOT NULL DEFAULT 'ACTIVE',
  `project_description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `project_key` (`project_key`),
  UNIQUE KEY `project_code` (`project_code`),
  KEY `idx_builder_project_branch` (`branch_key`),
  KEY `idx_builder_project_status` (`project_status`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `builder_role`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `builder_role` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `role_key` char(36) NOT NULL,
  `role_name` varchar(120) NOT NULL,
  `role_description` text DEFAULT NULL,
  `role_status` enum('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `role_key` (`role_key`),
  UNIQUE KEY `role_name` (`role_name`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `builder_role_permission`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `builder_role_permission` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `role_key` char(36) NOT NULL,
  `permission_key` char(36) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `uq_builder_role_permission` (`role_key`,`permission_key`)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `builder_system_setting`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `builder_system_setting` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `setting_key` char(36) NOT NULL,
  `setting_name` varchar(120) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `setting_group` varchar(80) NOT NULL DEFAULT 'general',
  `is_secret` tinyint(1) NOT NULL DEFAULT 0,
  `setting_status` enum('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `setting_key` (`setting_key`),
  UNIQUE KEY `setting_name` (`setting_name`)
) ENGINE=InnoDB AUTO_INCREMENT=89 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `builder_user`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `builder_user` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_key` char(36) NOT NULL,
  `firebase_uid` varchar(255) DEFAULT NULL,
  `user_login` varchar(80) NOT NULL,
  `user_password_hash` varchar(255) NOT NULL,
  `user_name` varchar(160) NOT NULL,
  `user_task_notification_sound` varchar(80) NOT NULL DEFAULT 'ding_sound_01',
  `user_task_notification_volume` tinyint(3) unsigned NOT NULL DEFAULT 100,
  `user_messenger_notification_sound` varchar(80) NOT NULL DEFAULT 'ding_sound_01',
  `user_messenger_notification_volume` tinyint(3) unsigned NOT NULL DEFAULT 100,
  `user_email` varchar(190) DEFAULT NULL,
  `position_key` char(36) DEFAULT NULL,
  `user_status` enum('DRAFT','ACTIVE','INACTIVE','LOCKED','DELETED') NOT NULL DEFAULT 'DRAFT',
  `user_failed_login_count` int(10) unsigned NOT NULL DEFAULT 0,
  `user_password_changed_at` timestamp NULL DEFAULT NULL,
  `user_password_expires_at` timestamp NULL DEFAULT NULL,
  `user_email_verified_at` timestamp NULL DEFAULT NULL,
  `user_two_factor_required` tinyint(1) NOT NULL DEFAULT 0,
  `user_recovery_codes_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `user_last_login_at` timestamp NULL DEFAULT NULL,
  `server_timestamp` timestamp NULL DEFAULT NULL,
  `user_created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `user_created_by_key` char(36) DEFAULT NULL,
  `user_updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `user_updated_by_key` char(36) DEFAULT NULL,
  `user_deleted_at` timestamp NULL DEFAULT NULL,
  `user_deleted_by_key` char(36) DEFAULT NULL,
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `user_key` (`user_key`),
  UNIQUE KEY `user_login` (`user_login`),
  UNIQUE KEY `user_email` (`user_email`),
  UNIQUE KEY `uq_builder_user_firebase_uid` (`firebase_uid`),
  KEY `idx_builder_user_status` (`user_status`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `builder_user_branch`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `builder_user_branch` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_key` char(36) NOT NULL,
  `branch_key` char(36) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `uq_builder_user_branch` (`user_key`,`branch_key`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `builder_user_group`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `builder_user_group` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_key` char(36) NOT NULL,
  `group_key` char(36) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `uq_builder_user_group` (`user_key`,`group_key`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `builder_user_login_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `builder_user_login_history` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `login_key` char(36) NOT NULL,
  `user_key` char(36) DEFAULT NULL,
  `user_login` varchar(120) DEFAULT NULL,
  `login_status` enum('SUCCESS','FAILED','LOCKED') NOT NULL,
  `ip_address` varchar(80) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `failure_reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `login_key` (`login_key`),
  KEY `idx_builder_login_user` (`user_key`),
  KEY `idx_builder_login_status` (`login_status`)
) ENGINE=InnoDB AUTO_INCREMENT=52 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `builder_user_password_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `builder_user_password_history` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `history_key` char(36) NOT NULL,
  `user_key` char(36) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `changed_by_key` char(36) DEFAULT NULL,
  `change_reason` varchar(120) NOT NULL DEFAULT 'password-reset',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `history_key` (`history_key`),
  KEY `idx_builder_password_history_user` (`user_key`),
  KEY `idx_builder_password_history_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `builder_user_password_reset`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `builder_user_password_reset` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `reset_key` char(36) NOT NULL,
  `user_key` char(36) NOT NULL,
  `reset_token_hash` char(64) NOT NULL,
  `reset_status` enum('PENDING','USED','EXPIRED','REVOKED') NOT NULL DEFAULT 'PENDING',
  `requested_ip` varchar(80) DEFAULT NULL,
  `requested_user_agent` varchar(255) DEFAULT NULL,
  `used_ip` varchar(80) DEFAULT NULL,
  `used_user_agent` varchar(255) DEFAULT NULL,
  `email_delivery_status` enum('PENDING','QUEUED','SENT','FAILED','PLACEHOLDER') NOT NULL DEFAULT 'PLACEHOLDER',
  `email_verification_required` tinyint(1) NOT NULL DEFAULT 1,
  `two_factor_required` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expires_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `used_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `reset_key` (`reset_key`),
  UNIQUE KEY `reset_token_hash` (`reset_token_hash`),
  KEY `idx_builder_password_reset_user` (`user_key`),
  KEY `idx_builder_password_reset_status` (`reset_status`),
  KEY `idx_builder_password_reset_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `builder_user_position`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `builder_user_position` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `position_key` char(36) NOT NULL,
  `position_code` varchar(80) NOT NULL,
  `position_name` varchar(160) NOT NULL,
  `group_key` char(36) DEFAULT NULL,
  `position_description` text DEFAULT NULL,
  `position_status` enum('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `position_key` (`position_key`),
  UNIQUE KEY `position_code` (`position_code`),
  UNIQUE KEY `position_name` (`position_name`),
  KEY `idx_builder_user_position_status` (`position_status`),
  KEY `idx_builder_user_position_group` (`group_key`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `builder_user_project`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `builder_user_project` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_key` char(36) NOT NULL,
  `project_key` char(36) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `uq_builder_user_project` (`user_key`,`project_key`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `builder_user_role`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `builder_user_role` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_key` char(36) NOT NULL,
  `role_key` char(36) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `uq_builder_user_role` (`user_key`,`role_key`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `builder_user_session`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `builder_user_session` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `session_key` char(36) NOT NULL,
  `user_key` char(36) NOT NULL,
  `session_token_hash` char(64) NOT NULL,
  `ip_address` varchar(80) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `session_status` enum('ACTIVE','REVOKED','EXPIRED') NOT NULL DEFAULT 'ACTIVE',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expires_at` timestamp NULL DEFAULT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `session_key` (`session_key`),
  KEY `idx_builder_user_session_user` (`user_key`),
  KEY `idx_builder_user_session_status` (`session_status`)
) ENGINE=InnoDB AUTO_INCREMENT=50 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `firebase_mysql_sync_attempt_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `firebase_mysql_sync_attempt_history` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `queue_id` bigint(20) unsigned NOT NULL,
  `from_state` varchar(32) NOT NULL,
  `to_state` varchar(32) NOT NULL,
  `error_code` varchar(120) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT current_timestamp(6),
  PRIMARY KEY (`x_id`),
  KEY `idx_firebase_mysql_sync_attempt_queue` (`queue_id`,`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=201 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `firebase_mysql_sync_collection_registry`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `firebase_mysql_sync_collection_registry` (
  `collection_name` varchar(80) NOT NULL,
  `table_name` varchar(80) NOT NULL,
  `key_column` varchar(80) NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime(6) NOT NULL DEFAULT current_timestamp(6),
  `updated_at` datetime(6) NOT NULL DEFAULT current_timestamp(6) ON UPDATE current_timestamp(6),
  PRIMARY KEY (`collection_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `firebase_mysql_sync_field_registry`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `firebase_mysql_sync_field_registry` (
  `collection_name` varchar(80) NOT NULL,
  `field_name` varchar(80) NOT NULL,
  `ordinal_no` int(10) unsigned NOT NULL,
  `inferred_type` varchar(32) NOT NULL,
  `observed_bytes` bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`collection_name`,`field_name`),
  UNIQUE KEY `uq_firebase_mysql_sync_field_ordinal` (`collection_name`,`ordinal_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `firebase_mysql_sync_migration_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `firebase_mysql_sync_migration_history` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `collection_name` varchar(80) NOT NULL,
  `table_name` varchar(80) NOT NULL,
  `backup_table_name` varchar(100) NOT NULL,
  `repair_kind` varchar(40) NOT NULL,
  `status` enum('BACKED_UP','COMPLETED','FAILED') NOT NULL,
  `source_row_count` bigint(20) unsigned NOT NULL,
  `backup_row_count` bigint(20) unsigned NOT NULL,
  `source_checksum` varchar(255) DEFAULT NULL,
  `backup_checksum` varchar(255) DEFAULT NULL,
  `pre_schema_fingerprint` char(64) NOT NULL,
  `post_schema_fingerprint` char(64) DEFAULT NULL,
  `error_code` varchar(120) DEFAULT NULL,
  `started_at` datetime(6) NOT NULL DEFAULT current_timestamp(6),
  `completed_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`x_id`),
  KEY `idx_firebase_mysql_sync_migration_table` (`table_name`,`backup_table_name`,`started_at`)
) ENGINE=InnoDB AUTO_INCREMENT=27 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `firebase_mysql_sync_projection_state`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `firebase_mysql_sync_projection_state` (
  `collection_name` varchar(80) NOT NULL,
  `document_id` varchar(255) NOT NULL,
  `source_revision` varchar(80) NOT NULL,
  `payload_fingerprint` char(64) NOT NULL,
  `projected_at` datetime(6) NOT NULL DEFAULT current_timestamp(6),
  PRIMARY KEY (`collection_name`,`document_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `firebase_mysql_sync_queue`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `firebase_mysql_sync_queue` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `collection_name` varchar(80) NOT NULL,
  `document_id` varchar(255) NOT NULL,
  `source_revision` varchar(80) NOT NULL,
  `state` enum('QUEUED','CLAIMED','RETRY_WAIT','ACK_PENDING','ACKED','SUPERSEDED','DEAD_LETTER') NOT NULL DEFAULT 'QUEUED',
  `resume_state` varchar(32) DEFAULT NULL,
  `attempt_count` int(10) unsigned NOT NULL DEFAULT 0,
  `lease_key` varchar(80) DEFAULT NULL,
  `lease_expires_at` datetime(6) DEFAULT NULL,
  `next_attempt_at` datetime(6) NOT NULL DEFAULT current_timestamp(6),
  `last_error_code` varchar(120) DEFAULT NULL,
  `payload_fingerprint` char(64) NOT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT current_timestamp(6),
  `updated_at` datetime(6) NOT NULL DEFAULT current_timestamp(6) ON UPDATE current_timestamp(6),
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `uq_firebase_mysql_sync_revision` (`collection_name`,`document_id`,`source_revision`),
  KEY `idx_firebase_mysql_sync_ready` (`state`,`next_attempt_at`,`lease_expires_at`),
  KEY `idx_firebase_mysql_sync_document` (`collection_name`,`document_id`)
) ENGINE=InnoDB AUTO_INCREMENT=168 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phase_builder_ai_context`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phase_builder_ai_context` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `context_key` varchar(160) NOT NULL,
  `project_identity` char(64) NOT NULL,
  `context_type` varchar(120) NOT NULL,
  `context_json` longtext NOT NULL,
  `byte_size` bigint(20) unsigned NOT NULL,
  `sha256` char(64) NOT NULL,
  `created_by_user_key` char(36) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `context_key` (`context_key`),
  KEY `idx_phase_ai_context_project` (`project_identity`,`created_at`),
  KEY `idx_phase_ai_context_type` (`project_identity`,`context_type`,`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=265 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phase_builder_ai_job`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phase_builder_ai_job` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `job_key` char(36) NOT NULL,
  `run_key` char(36) NOT NULL,
  `stage_key` varchar(80) NOT NULL,
  `project_identity` char(64) NOT NULL,
  `engine_type` varchar(20) NOT NULL,
  `workflow_key` varchar(80) NOT NULL,
  `execution_mode` varchar(40) NOT NULL DEFAULT 'read_only',
  `instruction_text` longtext NOT NULL,
  `status` varchar(24) NOT NULL DEFAULT 'QUEUED',
  `claim_count` smallint(5) unsigned NOT NULL DEFAULT 0,
  `worker_id` varchar(160) DEFAULT NULL,
  `result_json` longtext DEFAULT NULL,
  `error_code` varchar(80) DEFAULT NULL,
  `error_detail` text DEFAULT NULL,
  `created_by_user_key` char(36) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `claimed_at` timestamp NULL DEFAULT NULL,
  `heartbeat_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `job_key` (`job_key`),
  UNIQUE KEY `uq_phase_ai_job_stage` (`run_key`,`stage_key`),
  KEY `idx_phase_ai_job_project_status` (`project_identity`,`status`,`x_id`),
  KEY `idx_phase_ai_job_run` (`run_key`,`stage_key`,`status`)
) ENGINE=InnoDB AUTO_INCREMENT=286 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phase_builder_ai_run`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phase_builder_ai_run` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `run_key` char(36) NOT NULL,
  `project_identity` char(64) NOT NULL,
  `engine_type` varchar(20) NOT NULL,
  `workflow_key` varchar(80) NOT NULL,
  `route_key` varchar(80) NOT NULL,
  `stage_key` varchar(80) NOT NULL,
  `draft_key` char(36) DEFAULT NULL,
  `phase_key` char(36) DEFAULT NULL,
  `task_id` varchar(200) DEFAULT NULL,
  `subtask_id` varchar(200) DEFAULT NULL,
  `todo_id` varchar(200) DEFAULT NULL,
  `source_hash` char(64) NOT NULL,
  `request_version` varchar(80) NOT NULL,
  `idempotency_key` varchar(64) NOT NULL,
  `status` varchar(24) NOT NULL DEFAULT 'QUEUED',
  `attempt` smallint(5) unsigned NOT NULL DEFAULT 0,
  `max_attempts` smallint(5) unsigned NOT NULL DEFAULT 3,
  `provider_key` varchar(80) DEFAULT NULL,
  `model_key` varchar(120) DEFAULT NULL,
  `provider_request_id` varchar(200) DEFAULT NULL,
  `worker_id` varchar(120) DEFAULT NULL,
  `locked_until` datetime DEFAULT NULL,
  `request_json` longtext NOT NULL,
  `result_json` longtext DEFAULT NULL,
  `error_code` varchar(80) DEFAULT NULL,
  `error_detail` text DEFAULT NULL,
  `created_by_user_key` char(36) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `started_at` timestamp NULL DEFAULT NULL,
  `heartbeat_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `run_key` (`run_key`),
  UNIQUE KEY `uq_phase_ai_run_project_idempotency` (`project_identity`,`idempotency_key`),
  KEY `idx_phase_ai_run_scope` (`project_identity`,`workflow_key`,`draft_key`,`x_id`),
  KEY `idx_phase_ai_run_owner_scope` (`project_identity`,`created_by_user_key`,`route_key`,`workflow_key`,`x_id`),
  KEY `idx_phase_ai_run_status` (`status`,`locked_until`)
) ENGINE=InnoDB AUTO_INCREMENT=575 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phase_builder_ai_run_chunk`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phase_builder_ai_run_chunk` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `chunk_record_key` char(36) NOT NULL,
  `chunk_key` varchar(160) NOT NULL,
  `run_key` char(36) NOT NULL,
  `stage_key` varchar(80) NOT NULL,
  `chunk_type` varchar(40) NOT NULL,
  `chunk_order` smallint(5) unsigned NOT NULL,
  `source_hash` char(64) NOT NULL,
  `status` varchar(24) NOT NULL DEFAULT 'QUEUED',
  `attempt_count` smallint(5) unsigned NOT NULL DEFAULT 0,
  `request_json` longtext DEFAULT NULL,
  `result_json` longtext DEFAULT NULL,
  `error_code` varchar(80) DEFAULT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `chunk_record_key` (`chunk_record_key`),
  UNIQUE KEY `uq_phase_ai_run_chunk` (`run_key`,`stage_key`,`chunk_key`),
  KEY `idx_phase_ai_run_chunk_status` (`run_key`,`status`,`chunk_order`)
) ENGINE=InnoDB AUTO_INCREMENT=2758 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phase_builder_ai_run_event`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phase_builder_ai_run_event` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `event_key` char(36) NOT NULL,
  `run_key` char(36) NOT NULL,
  `stage_key` varchar(80) DEFAULT NULL,
  `chunk_key` varchar(160) DEFAULT NULL,
  `event_type` varchar(80) NOT NULL,
  `status` varchar(24) NOT NULL,
  `message` varchar(500) NOT NULL,
  `payload_json` longtext DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `event_key` (`event_key`),
  KEY `idx_phase_ai_run_event` (`run_key`,`x_id`)
) ENGINE=InnoDB AUTO_INCREMENT=4915 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phase_builder_ai_run_stage`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phase_builder_ai_run_stage` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `stage_record_key` char(36) NOT NULL,
  `run_key` char(36) NOT NULL,
  `stage_key` varchar(80) NOT NULL,
  `stage_order` smallint(5) unsigned NOT NULL,
  `status` varchar(24) NOT NULL DEFAULT 'QUEUED',
  `attempt_count` smallint(5) unsigned NOT NULL DEFAULT 0,
  `max_attempts` smallint(5) unsigned NOT NULL DEFAULT 3,
  `source_hash` char(64) NOT NULL,
  `request_json` longtext DEFAULT NULL,
  `result_json` longtext DEFAULT NULL,
  `provider_request_id` varchar(200) DEFAULT NULL,
  `error_code` varchar(80) DEFAULT NULL,
  `error_detail` text DEFAULT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `heartbeat_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `stage_record_key` (`stage_record_key`),
  UNIQUE KEY `uq_phase_ai_run_stage` (`run_key`,`stage_key`),
  KEY `idx_phase_ai_run_stage_status` (`run_key`,`status`,`stage_order`)
) ENGINE=InnoDB AUTO_INCREMENT=2758 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phase_builder_execution_roadmap`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phase_builder_execution_roadmap` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `roadmap_key` char(36) NOT NULL,
  `draft_key` char(36) DEFAULT NULL,
  `phase_key` char(36) DEFAULT NULL,
  `source_architecture_hash` char(64) NOT NULL,
  `roadmap_json` longtext NOT NULL,
  `progress_json` longtext NOT NULL,
  `stages_json` longtext DEFAULT NULL,
  `created_by_user_key` char(36) DEFAULT NULL,
  `updated_by_user_key` char(36) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `roadmap_key` (`roadmap_key`),
  UNIQUE KEY `draft_key` (`draft_key`),
  UNIQUE KEY `phase_key` (`phase_key`),
  KEY `idx_phase_builder_roadmap_updated` (`updated_at`),
  KEY `idx_phase_builder_roadmap_source` (`source_architecture_hash`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phase_builder_narrative_draft`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phase_builder_narrative_draft` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `draft_key` char(36) NOT NULL,
  `phase_key` char(36) DEFAULT NULL,
  `product_goal` longtext NOT NULL,
  `users_and_roles` longtext NOT NULL,
  `main_user_journey` longtext NOT NULL,
  `web_requirements` longtext NOT NULL,
  `android_requirements` longtext NOT NULL,
  `database_and_synchronization` longtext NOT NULL,
  `security_and_permissions` longtext NOT NULL,
  `validation_and_error_handling` longtext NOT NULL,
  `open_questions` longtext NOT NULL,
  `created_by_user_key` char(36) DEFAULT NULL,
  `updated_by_user_key` char(36) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `draft_key` (`draft_key`),
  UNIQUE KEY `phase_key` (`phase_key`),
  KEY `idx_phase_builder_narrative_updated` (`updated_at`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phase_builder_narrative_draft_backup`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phase_builder_narrative_draft_backup` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `draft_key` char(36) NOT NULL,
  `phase_key` char(36) DEFAULT NULL,
  `product_goal` longtext NOT NULL,
  `users_and_roles` longtext NOT NULL,
  `main_user_journey` longtext NOT NULL,
  `web_requirements` longtext NOT NULL,
  `android_requirements` longtext NOT NULL,
  `database_and_synchronization` longtext NOT NULL,
  `security_and_permissions` longtext NOT NULL,
  `validation_and_error_handling` longtext NOT NULL,
  `open_questions` longtext NOT NULL,
  `created_by_user_key` char(36) DEFAULT NULL,
  `updated_by_user_key` char(36) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `draft_key` (`draft_key`),
  UNIQUE KEY `phase_key` (`phase_key`),
  KEY `idx_phase_builder_narrative_updated` (`updated_at`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phase_builder_requirements_analysis`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phase_builder_requirements_analysis` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `analysis_key` char(36) NOT NULL,
  `draft_key` char(36) DEFAULT NULL,
  `phase_key` char(36) DEFAULT NULL,
  `source_narrative_hash` char(64) NOT NULL,
  `analysis_json` longtext NOT NULL,
  `created_by_user_key` char(36) DEFAULT NULL,
  `updated_by_user_key` char(36) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `analysis_key` (`analysis_key`),
  UNIQUE KEY `draft_key` (`draft_key`),
  UNIQUE KEY `phase_key` (`phase_key`),
  KEY `idx_phase_builder_requirements_updated` (`updated_at`),
  KEY `idx_phase_builder_requirements_source` (`source_narrative_hash`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phase_builder_system_architecture`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phase_builder_system_architecture` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `architecture_key` char(36) NOT NULL,
  `draft_key` char(36) DEFAULT NULL,
  `phase_key` char(36) DEFAULT NULL,
  `source_requirements_hash` char(64) NOT NULL,
  `architecture_json` longtext NOT NULL,
  `created_by_user_key` char(36) DEFAULT NULL,
  `updated_by_user_key` char(36) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `architecture_key` (`architecture_key`),
  UNIQUE KEY `draft_key` (`draft_key`),
  UNIQUE KEY `phase_key` (`phase_key`),
  KEY `idx_phase_builder_architecture_updated` (`updated_at`),
  KEY `idx_phase_builder_architecture_source` (`source_requirements_hash`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phase_builder_todo_chat_attachments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phase_builder_todo_chat_attachments` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `attachment_key` char(36) NOT NULL,
  `message_key` char(36) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `mime_type` varchar(120) NOT NULL,
  `byte_size` int(10) unsigned NOT NULL,
  `storage_path` varchar(500) DEFAULT NULL,
  `sha256` char(64) DEFAULT NULL,
  `attachment_status` varchar(20) NOT NULL DEFAULT 'ACTIVE',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `attachment_key` (`attachment_key`),
  KEY `idx_phase_builder_todo_chat_attachment_message` (`message_key`,`attachment_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phase_builder_todo_chat_consolidations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phase_builder_todo_chat_consolidations` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `consolidation_key` char(36) NOT NULL,
  `draft_key` char(36) NOT NULL,
  `phase_id` varchar(160) NOT NULL,
  `task_id` varchar(200) NOT NULL,
  `subtask_id` varchar(200) NOT NULL,
  `todo_id` varchar(200) NOT NULL,
  `context_json` longtext NOT NULL,
  `ai_result_json` longtext DEFAULT NULL,
  `approval_status` varchar(20) NOT NULL DEFAULT 'PENDING',
  `created_by_user_key` char(36) DEFAULT NULL,
  `approved_by_user_key` char(36) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `approved_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `consolidation_key` (`consolidation_key`),
  KEY `idx_phase_builder_todo_consolidation_scope` (`draft_key`,`todo_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phase_builder_todo_chat_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phase_builder_todo_chat_messages` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `message_key` char(36) NOT NULL,
  `draft_key` char(36) NOT NULL,
  `phase_id` varchar(160) NOT NULL,
  `task_id` varchar(200) NOT NULL,
  `subtask_id` varchar(200) NOT NULL,
  `todo_id` varchar(200) NOT NULL,
  `sender` varchar(20) NOT NULL DEFAULT 'user',
  `message_text` text NOT NULL,
  `message_status` varchar(20) NOT NULL DEFAULT 'ACTIVE',
  `edited_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by_user_key` char(36) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `message_key` (`message_key`),
  KEY `idx_phase_builder_todo_chat_scope` (`draft_key`,`todo_id`,`message_status`),
  KEY `idx_phase_builder_todo_chat_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phase_builder_todo_execution_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phase_builder_todo_execution_logs` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `execution_key` char(36) NOT NULL,
  `draft_key` char(36) NOT NULL,
  `phase_id` varchar(160) NOT NULL,
  `task_id` varchar(200) NOT NULL,
  `subtask_id` varchar(200) NOT NULL,
  `todo_id` varchar(200) NOT NULL,
  `context_json` longtext NOT NULL,
  `source_checkpoint_json` longtext DEFAULT NULL,
  `result_json` longtext DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'RUNNING',
  `rollback_status` varchar(20) NOT NULL DEFAULT 'NOT_REQUESTED',
  `rollback_source_checkpoint_json` longtext DEFAULT NULL,
  `rollback_result_json` longtext DEFAULT NULL,
  `created_by_user_key` char(36) DEFAULT NULL,
  `updated_by_user_key` char(36) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `completed_at` timestamp NULL DEFAULT NULL,
  `rolled_back_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `execution_key` (`execution_key`),
  KEY `idx_phase_builder_todo_exec_scope` (`draft_key`,`todo_id`,`created_at`),
  KEY `idx_phase_builder_todo_exec_status` (`status`,`rollback_status`)
) ENGINE=InnoDB AUTO_INCREMENT=125 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phase_builder_ui_ux_design`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phase_builder_ui_ux_design` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ui_ux_key` char(36) NOT NULL,
  `draft_key` char(36) DEFAULT NULL,
  `phase_key` char(36) DEFAULT NULL,
  `source_architecture_hash` char(64) NOT NULL,
  `ui_ux_json` longtext NOT NULL,
  `created_by_user_key` char(36) DEFAULT NULL,
  `updated_by_user_key` char(36) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `ui_ux_key` (`ui_ux_key`),
  UNIQUE KEY `draft_key` (`draft_key`),
  UNIQUE KEY `phase_key` (`phase_key`),
  KEY `idx_phase_builder_ui_ux_updated` (`updated_at`),
  KEY `idx_phase_builder_ui_ux_source` (`source_architecture_hash`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_bed`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_bed` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `bed_key` varchar(40) NOT NULL,
  `bed_source_key` varchar(160) NOT NULL,
  `source_table` varchar(64) NOT NULL DEFAULT 'RBMS_BedMasterlist',
  `source_id` int(10) unsigned DEFAULT NULL,
  `source_pk_psbeds` varchar(100) DEFAULT NULL,
  `bed_no` varchar(100) DEFAULT NULL,
  `branch_key` varchar(100) DEFAULT NULL,
  `branch_name` varchar(100) DEFAULT NULL,
  `building_key` varchar(100) DEFAULT NULL,
  `building_name` varchar(100) DEFAULT NULL,
  `floor_key` varchar(100) DEFAULT NULL,
  `floor_name` varchar(100) DEFAULT NULL,
  `nurse_station_key` varchar(100) DEFAULT NULL,
  `nurse_station_name` varchar(100) DEFAULT NULL,
  `room_key` varchar(100) DEFAULT NULL,
  `room_class_key` varchar(100) DEFAULT NULL,
  `room_class` varchar(100) DEFAULT NULL,
  `source_bed_status_key` varchar(100) DEFAULT NULL,
  `source_bed_status` varchar(100) DEFAULT NULL,
  `managed_status` enum('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  `sync_batch_key` char(36) DEFAULT NULL,
  `first_synced_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_synced_at` timestamp NULL DEFAULT NULL,
  `last_seen_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `bed_key` (`bed_key`),
  UNIQUE KEY `uq_bed_master_list_source` (`bed_source_key`),
  KEY `idx_bed_master_list_status` (`managed_status`),
  KEY `idx_bed_master_list_bed_no` (`bed_no`),
  KEY `idx_bed_master_list_floor` (`branch_key`,`building_key`,`floor_key`),
  KEY `idx_bed_master_list_sync` (`sync_batch_key`,`last_synced_at`),
  KEY `idx_project_bed_source_pk_psbeds` (`source_pk_psbeds`),
  KEY `idx_project_bed_source_status` (`source_bed_status`,`managed_status`),
  KEY `idx_project_bed_location_lookup` (`branch_name`,`building_name`,`floor_name`,`nurse_station_name`,`room_key`,`room_class`)
) ENGINE=InnoDB AUTO_INCREMENT=1030 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_bed_analytics`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_bed_analytics` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `analytics_key` varchar(40) NOT NULL,
  `analytics_scope` enum('SUMMARY','GROUP') NOT NULL DEFAULT 'GROUP',
  `group_key` varchar(100) NOT NULL DEFAULT '',
  `group_label` varchar(120) NOT NULL DEFAULT '',
  `item_label` varchar(160) NOT NULL DEFAULT '',
  `total_rows` int(10) unsigned NOT NULL DEFAULT 0,
  `active_rows` int(10) unsigned NOT NULL DEFAULT 0,
  `inactive_rows` int(10) unsigned NOT NULL DEFAULT 0,
  `available_rows` int(10) unsigned NOT NULL DEFAULT 0,
  `vacant_rows` int(10) unsigned NOT NULL DEFAULT 0,
  `occupied_rows` int(10) unsigned NOT NULL DEFAULT 0,
  `analytics_status` enum('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  `sync_batch_key` char(36) DEFAULT NULL,
  `last_computed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `analytics_key` (`analytics_key`),
  KEY `idx_project_bed_analytics_scope` (`analytics_scope`,`group_key`,`analytics_status`),
  KEY `idx_project_bed_analytics_sync` (`sync_batch_key`,`analytics_status`)
) ENGINE=InnoDB AUTO_INCREMENT=601 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_bed_source`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_bed_source` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `bed_source_key` varchar(40) NOT NULL,
  `bed_source_code` varchar(80) NOT NULL,
  `bed_source_name` varchar(160) NOT NULL,
  `bed_source_description` text DEFAULT NULL,
  `bed_source_status` enum('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  `bed_source_sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `created_by_user_key` char(36) DEFAULT NULL,
  `updated_by_user_key` char(36) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `admission_source_key` (`bed_source_key`),
  UNIQUE KEY `uq_project_admission_source_code` (`bed_source_code`),
  UNIQUE KEY `bed_source_key` (`bed_source_key`),
  UNIQUE KEY `uq_project_bed_source_code` (`bed_source_code`),
  KEY `idx_project_admission_source_status` (`bed_source_status`,`bed_source_sort_order`),
  KEY `idx_project_bed_source_status` (`bed_source_status`,`bed_source_sort_order`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_bed_task`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_bed_task` (
  `tenant_key` text DEFAULT NULL,
  `project_key` text DEFAULT NULL,
  `bed_task_key` varchar(40) NOT NULL,
  `bed_key` varchar(40) NOT NULL,
  `bed_source_key` varchar(160) NOT NULL DEFAULT '',
  `source_pk_psbeds` varchar(100) DEFAULT NULL,
  `bed_no` varchar(100) NOT NULL DEFAULT '',
  `branch_key` varchar(100) NOT NULL DEFAULT '',
  `branch_name` varchar(100) NOT NULL DEFAULT '',
  `building_key` varchar(100) NOT NULL DEFAULT '',
  `building_name` varchar(100) NOT NULL DEFAULT '',
  `floor_key` varchar(100) NOT NULL DEFAULT '',
  `floor_name` varchar(100) NOT NULL DEFAULT '',
  `nurse_station_key` varchar(100) NOT NULL DEFAULT '',
  `nurse_station_name` varchar(100) NOT NULL DEFAULT '',
  `room_key` varchar(100) NOT NULL DEFAULT '',
  `room_class_key` varchar(100) NOT NULL DEFAULT '',
  `room_class` varchar(100) NOT NULL DEFAULT '',
  `source_bed_status_key` varchar(100) NOT NULL DEFAULT '',
  `source_bed_status` varchar(100) NOT NULL DEFAULT '',
  `task_key` varchar(40) NOT NULL,
  `task_code` varchar(80) DEFAULT NULL,
  `task_title` varchar(255) NOT NULL DEFAULT '',
  `task_type` enum('PRIMARY','SECONDARY') NOT NULL,
  `task_color_hex` text DEFAULT NULL,
  `task_sort_order` bigint(20) DEFAULT NULL,
  `task_group_keys` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`task_group_keys`)),
  `task_status` enum('PENDING','IN_PROGRESS','ON_HOLD','FAILED') NOT NULL DEFAULT 'PENDING',
  `current_task_stage_key` varchar(40) NOT NULL DEFAULT '',
  `current_stage_label` varchar(160) NOT NULL DEFAULT '',
  `current_stage_color_hex` char(9) NOT NULL DEFAULT '#00000000',
  `task_stage_key` varchar(40) NOT NULL DEFAULT '',
  `stage_label` varchar(160) NOT NULL DEFAULT '',
  `stage_color_hex` char(9) NOT NULL DEFAULT '#00000000',
  `task_stage_response_key` text DEFAULT NULL,
  `response_label` text DEFAULT NULL,
  `response_description` text DEFAULT NULL,
  `response_color_hex` text DEFAULT NULL,
  `bed_status_at_request` varchar(100) NOT NULL DEFAULT '',
  `bed_class` varchar(100) NOT NULL DEFAULT '',
  `bed_treatment_key` varchar(40) DEFAULT NULL,
  `bed_treatment_name` varchar(160) NOT NULL DEFAULT '',
  `bed_source_option_key` varchar(40) DEFAULT NULL,
  `bed_source_option_name` varchar(160) NOT NULL DEFAULT '',
  `remarks` text DEFAULT NULL,
  `requester_user_key` char(36) NOT NULL,
  `requester_fullname` varchar(160) NOT NULL,
  `firebase_collection` text DEFAULT NULL,
  `mysql_sync_status` text DEFAULT NULL,
  `mysql_created_at` datetime(6) DEFAULT NULL,
  `mysql_updated_at` datetime(6) DEFAULT NULL,
  `mysql_synced_at` datetime(6) DEFAULT NULL,
  `mysql_deleted_at` datetime(6) DEFAULT NULL,
  UNIQUE KEY `bed_task_key` (`bed_task_key`),
  KEY `idx_project_bed_task_bed` (`bed_key`,`task_status`),
  KEY `idx_project_bed_task_task` (`task_key`,`task_status`),
  KEY `idx_project_bed_task_requester` (`requester_user_key`),
  KEY `idx_project_bed_task_status` (`task_status`),
  KEY `idx_project_bed_task_bed_type` (`bed_key`,`task_type`,`task_status`),
  KEY `idx_project_bed_task_stage` (`current_task_stage_key`,`task_status`),
  KEY `idx_project_bed_task_stage_alias` (`task_stage_key`,`task_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_bed_task_2026_08_26_22_13`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_bed_task_2026_08_26_22_13` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `bed_task_key` varchar(40) NOT NULL,
  `bed_key` varchar(40) NOT NULL,
  `bed_source_key` varchar(160) NOT NULL DEFAULT '',
  `source_pk_psbeds` varchar(100) DEFAULT NULL,
  `bed_no` varchar(100) NOT NULL DEFAULT '',
  `branch_key` varchar(100) NOT NULL DEFAULT '',
  `branch_name` varchar(100) NOT NULL DEFAULT '',
  `building_key` varchar(100) NOT NULL DEFAULT '',
  `building_name` varchar(100) NOT NULL DEFAULT '',
  `floor_key` varchar(100) NOT NULL DEFAULT '',
  `floor_name` varchar(100) NOT NULL DEFAULT '',
  `nurse_station_key` varchar(100) NOT NULL DEFAULT '',
  `nurse_station_name` varchar(100) NOT NULL DEFAULT '',
  `room_key` varchar(100) NOT NULL DEFAULT '',
  `room_class_key` varchar(100) NOT NULL DEFAULT '',
  `room_class` varchar(100) NOT NULL DEFAULT '',
  `source_bed_status_key` varchar(100) NOT NULL DEFAULT '',
  `source_bed_status` varchar(100) NOT NULL DEFAULT '',
  `task_key` varchar(40) NOT NULL,
  `task_code` varchar(80) DEFAULT NULL,
  `task_title` varchar(255) NOT NULL DEFAULT '',
  `task_type` enum('PRIMARY','SECONDARY') NOT NULL,
  `task_status` enum('PENDING','IN_PROGRESS','ON_HOLD','FAILED') NOT NULL DEFAULT 'PENDING',
  `current_task_stage_key` varchar(40) NOT NULL DEFAULT '',
  `current_stage_label` varchar(160) NOT NULL DEFAULT '',
  `current_stage_color_hex` char(9) NOT NULL DEFAULT '#00000000',
  `task_stage_key` varchar(40) NOT NULL DEFAULT '',
  `stage_label` varchar(160) NOT NULL DEFAULT '',
  `stage_color_hex` char(9) NOT NULL DEFAULT '#00000000',
  `bed_status_at_request` varchar(100) NOT NULL DEFAULT '',
  `bed_class` varchar(100) NOT NULL DEFAULT '',
  `bed_treatment_key` varchar(40) DEFAULT NULL,
  `bed_treatment_name` varchar(160) NOT NULL DEFAULT '',
  `bed_source_option_key` varchar(40) DEFAULT NULL,
  `bed_source_option_name` varchar(160) NOT NULL DEFAULT '',
  `remarks` text DEFAULT NULL,
  `requester_user_key` char(36) NOT NULL,
  `requester_fullname` varchar(160) NOT NULL,
  `firebase_sync_status` enum('PENDING','SYNCED','FAILED') NOT NULL DEFAULT 'PENDING',
  `firebase_synced_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `bed_task_key` (`bed_task_key`),
  KEY `idx_project_bed_task_bed` (`bed_key`,`task_status`,`created_at`),
  KEY `idx_project_bed_task_task` (`task_key`,`task_status`),
  KEY `idx_project_bed_task_requester` (`requester_user_key`,`created_at`),
  KEY `idx_project_bed_task_status` (`task_status`,`created_at`),
  KEY `idx_project_bed_task_firebase` (`firebase_sync_status`,`updated_at`),
  KEY `idx_project_bed_task_bed_type` (`bed_key`,`task_type`,`task_status`,`created_at`),
  KEY `idx_project_bed_task_stage` (`current_task_stage_key`,`task_status`,`updated_at`),
  KEY `idx_project_bed_task_stage_alias` (`task_stage_key`,`task_status`,`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_bed_task_2026_08_27_13_48`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_bed_task_2026_08_27_13_48` (
  `tenant_key` text DEFAULT NULL,
  `project_key` text DEFAULT NULL,
  `bed_task_key` varchar(40) NOT NULL,
  `bed_key` varchar(40) NOT NULL,
  `bed_source_key` varchar(160) NOT NULL DEFAULT '',
  `source_pk_psbeds` varchar(100) DEFAULT NULL,
  `bed_no` varchar(100) NOT NULL DEFAULT '',
  `branch_key` varchar(100) NOT NULL DEFAULT '',
  `branch_name` varchar(100) NOT NULL DEFAULT '',
  `building_key` varchar(100) NOT NULL DEFAULT '',
  `building_name` varchar(100) NOT NULL DEFAULT '',
  `floor_key` varchar(100) NOT NULL DEFAULT '',
  `floor_name` varchar(100) NOT NULL DEFAULT '',
  `nurse_station_key` varchar(100) NOT NULL DEFAULT '',
  `nurse_station_name` varchar(100) NOT NULL DEFAULT '',
  `room_key` varchar(100) NOT NULL DEFAULT '',
  `room_class_key` varchar(100) NOT NULL DEFAULT '',
  `room_class` varchar(100) NOT NULL DEFAULT '',
  `source_bed_status_key` varchar(100) NOT NULL DEFAULT '',
  `source_bed_status` varchar(100) NOT NULL DEFAULT '',
  `task_key` varchar(40) NOT NULL,
  `task_code` varchar(80) DEFAULT NULL,
  `task_title` varchar(255) NOT NULL DEFAULT '',
  `task_type` enum('PRIMARY','SECONDARY') NOT NULL,
  `task_color_hex` text DEFAULT NULL,
  `task_sort_order` bigint(20) DEFAULT NULL,
  `task_group_keys` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`task_group_keys`)),
  `task_status` enum('PENDING','IN_PROGRESS','ON_HOLD','FAILED') NOT NULL DEFAULT 'PENDING',
  `current_task_stage_key` varchar(40) NOT NULL DEFAULT '',
  `current_stage_label` varchar(160) NOT NULL DEFAULT '',
  `current_stage_color_hex` char(9) NOT NULL DEFAULT '#00000000',
  `task_stage_key` varchar(40) NOT NULL DEFAULT '',
  `stage_label` varchar(160) NOT NULL DEFAULT '',
  `stage_color_hex` char(9) NOT NULL DEFAULT '#00000000',
  `task_stage_response_key` text DEFAULT NULL,
  `response_label` text DEFAULT NULL,
  `response_description` text DEFAULT NULL,
  `response_color_hex` text DEFAULT NULL,
  `bed_status_at_request` varchar(100) NOT NULL DEFAULT '',
  `bed_class` varchar(100) NOT NULL DEFAULT '',
  `bed_treatment_key` varchar(40) DEFAULT NULL,
  `bed_treatment_name` varchar(160) NOT NULL DEFAULT '',
  `bed_source_option_key` varchar(40) DEFAULT NULL,
  `bed_source_option_name` varchar(160) NOT NULL DEFAULT '',
  `remarks` text DEFAULT NULL,
  `requester_user_key` char(36) NOT NULL,
  `requester_fullname` varchar(160) NOT NULL,
  `firebase_collection` text DEFAULT NULL,
  `mysql_sync_status` text DEFAULT NULL,
  `mysql_created_at` datetime(6) DEFAULT NULL,
  `mysql_updated_at` datetime(6) DEFAULT NULL,
  `mysql_synced_at` datetime(6) DEFAULT NULL,
  `mysql_deleted_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT current_timestamp(6),
  `updated_at` datetime(6) NOT NULL DEFAULT current_timestamp(6) ON UPDATE current_timestamp(6),
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `firebase_sync_status` enum('PENDING','SYNCED','FAILED') NOT NULL DEFAULT 'PENDING',
  `firebase_synced_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `bed_task_key` (`bed_task_key`),
  KEY `idx_project_bed_task_bed` (`bed_key`,`task_status`,`created_at`),
  KEY `idx_project_bed_task_task` (`task_key`,`task_status`),
  KEY `idx_project_bed_task_requester` (`requester_user_key`,`created_at`),
  KEY `idx_project_bed_task_status` (`task_status`,`created_at`),
  KEY `idx_project_bed_task_firebase` (`firebase_sync_status`,`updated_at`),
  KEY `idx_project_bed_task_bed_type` (`bed_key`,`task_type`,`task_status`,`created_at`),
  KEY `idx_project_bed_task_stage` (`current_task_stage_key`,`task_status`,`updated_at`),
  KEY `idx_project_bed_task_stage_alias` (`task_stage_key`,`task_status`,`updated_at`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_bed_task_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_bed_task_log` (
  `bed_task_log_key` varchar(40) NOT NULL,
  `tenant_key` text DEFAULT NULL,
  `project_key` text DEFAULT NULL,
  `bed_task_key` varchar(40) NOT NULL,
  `bed_key` varchar(40) NOT NULL,
  `bed_source_key` varchar(160) NOT NULL DEFAULT '',
  `source_pk_psbeds` varchar(100) DEFAULT NULL,
  `bed_no` varchar(100) NOT NULL DEFAULT '',
  `branch_key` varchar(100) NOT NULL DEFAULT '',
  `branch_name` varchar(100) NOT NULL DEFAULT '',
  `building_key` varchar(100) NOT NULL DEFAULT '',
  `building_name` varchar(100) NOT NULL DEFAULT '',
  `floor_key` varchar(100) NOT NULL DEFAULT '',
  `floor_name` varchar(100) NOT NULL DEFAULT '',
  `nurse_station_key` varchar(100) NOT NULL DEFAULT '',
  `nurse_station_name` varchar(100) NOT NULL DEFAULT '',
  `room_key` varchar(100) NOT NULL DEFAULT '',
  `room_class_key` varchar(100) NOT NULL DEFAULT '',
  `room_class` varchar(100) NOT NULL DEFAULT '',
  `source_bed_status_key` varchar(100) NOT NULL DEFAULT '',
  `source_bed_status` varchar(100) NOT NULL DEFAULT '',
  `task_key` varchar(40) NOT NULL,
  `task_code` varchar(80) DEFAULT NULL,
  `task_title` varchar(255) NOT NULL DEFAULT '',
  `task_type` enum('PRIMARY','SECONDARY') NOT NULL,
  `task_color_hex` text DEFAULT NULL,
  `task_sort_order` bigint(20) DEFAULT NULL,
  `task_group_keys` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`task_group_keys`)),
  `task_status` text DEFAULT NULL,
  `current_task_stage_key` varchar(40) NOT NULL DEFAULT '',
  `current_stage_label` varchar(160) NOT NULL DEFAULT '',
  `current_stage_color_hex` char(9) NOT NULL DEFAULT '#00000000',
  `task_stage_key` varchar(40) NOT NULL DEFAULT '',
  `stage_label` varchar(160) NOT NULL DEFAULT '',
  `stage_color_hex` char(9) NOT NULL DEFAULT '#00000000',
  `task_stage_response_key` varchar(40) NOT NULL DEFAULT '',
  `response_label` varchar(160) NOT NULL DEFAULT '',
  `response_description` text DEFAULT NULL,
  `response_color_hex` char(9) NOT NULL DEFAULT '#00000000',
  `bed_status_at_request` varchar(100) NOT NULL DEFAULT '',
  `bed_class` varchar(100) NOT NULL DEFAULT '',
  `bed_treatment_key` varchar(40) DEFAULT NULL,
  `bed_treatment_name` varchar(160) NOT NULL DEFAULT '',
  `bed_source_option_key` varchar(40) DEFAULT NULL,
  `bed_source_option_name` varchar(160) NOT NULL DEFAULT '',
  `remarks` text DEFAULT NULL,
  `requester_user_key` char(36) NOT NULL,
  `requester_fullname` varchar(160) NOT NULL,
  `firebase_collection` text DEFAULT NULL,
  `mysql_sync_status` text DEFAULT NULL,
  `mysql_created_at` datetime(6) DEFAULT NULL,
  `mysql_updated_at` datetime(6) DEFAULT NULL,
  `mysql_synced_at` datetime(6) DEFAULT NULL,
  `mysql_deleted_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT current_timestamp(6),
  `updated_at` datetime(6) DEFAULT NULL,
  `event_type` enum('CREATED','ASSIGNED','STARTED','UPDATED','COMPLETED','CANCELLED','FAILED') NOT NULL,
  `status_from` varchar(40) DEFAULT NULL,
  `status_to` varchar(40) NOT NULL,
  `actor_fullname` varchar(160) NOT NULL,
  `actor_user_key` char(36) NOT NULL,
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `firebase_sync_status` enum('PENDING','SYNCED','FAILED') NOT NULL DEFAULT 'PENDING',
  `firebase_synced_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `bed_task_log_key` (`bed_task_log_key`),
  KEY `idx_project_bed_task_log_task` (`bed_task_key`,`created_at`),
  KEY `idx_project_bed_task_log_event` (`event_type`,`created_at`),
  KEY `idx_project_bed_task_log_actor` (`actor_user_key`,`created_at`),
  KEY `idx_project_bed_task_log_requester` (`requester_user_key`,`created_at`),
  KEY `idx_project_bed_task_log_firebase` (`firebase_sync_status`,`created_at`),
  KEY `idx_project_bed_task_log_stage` (`current_task_stage_key`,`created_at`),
  KEY `idx_project_bed_task_log_stage_alias` (`task_stage_key`,`created_at`),
  KEY `idx_project_bed_task_log_response` (`task_stage_response_key`,`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_bed_task_log_2026_08_26_22_13`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_bed_task_log_2026_08_26_22_13` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `bed_task_log_key` varchar(40) NOT NULL,
  `bed_task_key` varchar(40) NOT NULL,
  `bed_key` varchar(40) NOT NULL,
  `bed_source_key` varchar(160) NOT NULL DEFAULT '',
  `source_pk_psbeds` varchar(100) DEFAULT NULL,
  `bed_no` varchar(100) NOT NULL DEFAULT '',
  `branch_key` varchar(100) NOT NULL DEFAULT '',
  `branch_name` varchar(100) NOT NULL DEFAULT '',
  `building_key` varchar(100) NOT NULL DEFAULT '',
  `building_name` varchar(100) NOT NULL DEFAULT '',
  `floor_key` varchar(100) NOT NULL DEFAULT '',
  `floor_name` varchar(100) NOT NULL DEFAULT '',
  `nurse_station_key` varchar(100) NOT NULL DEFAULT '',
  `nurse_station_name` varchar(100) NOT NULL DEFAULT '',
  `room_key` varchar(100) NOT NULL DEFAULT '',
  `room_class_key` varchar(100) NOT NULL DEFAULT '',
  `room_class` varchar(100) NOT NULL DEFAULT '',
  `source_bed_status_key` varchar(100) NOT NULL DEFAULT '',
  `source_bed_status` varchar(100) NOT NULL DEFAULT '',
  `task_key` varchar(40) NOT NULL,
  `task_code` varchar(80) DEFAULT NULL,
  `task_title` varchar(255) NOT NULL DEFAULT '',
  `task_type` enum('PRIMARY','SECONDARY') NOT NULL,
  `event_type` enum('CREATED','ASSIGNED','STARTED','UPDATED','COMPLETED','CANCELLED','FAILED') NOT NULL,
  `status_from` varchar(40) DEFAULT NULL,
  `status_to` varchar(40) NOT NULL,
  `current_task_stage_key` varchar(40) NOT NULL DEFAULT '',
  `current_stage_label` varchar(160) NOT NULL DEFAULT '',
  `current_stage_color_hex` char(9) NOT NULL DEFAULT '#00000000',
  `task_stage_key` varchar(40) NOT NULL DEFAULT '',
  `stage_label` varchar(160) NOT NULL DEFAULT '',
  `stage_color_hex` char(9) NOT NULL DEFAULT '#00000000',
  `task_stage_response_key` varchar(40) NOT NULL DEFAULT '',
  `response_label` varchar(160) NOT NULL DEFAULT '',
  `response_description` text DEFAULT NULL,
  `response_color_hex` char(9) NOT NULL DEFAULT '#00000000',
  `bed_status_at_request` varchar(100) NOT NULL DEFAULT '',
  `bed_class` varchar(100) NOT NULL DEFAULT '',
  `bed_treatment_key` varchar(40) DEFAULT NULL,
  `bed_treatment_name` varchar(160) NOT NULL DEFAULT '',
  `bed_source_option_key` varchar(40) DEFAULT NULL,
  `bed_source_option_name` varchar(160) NOT NULL DEFAULT '',
  `remarks` text DEFAULT NULL,
  `requester_user_key` char(36) NOT NULL,
  `requester_fullname` varchar(160) NOT NULL,
  `actor_user_key` char(36) NOT NULL,
  `actor_fullname` varchar(160) NOT NULL,
  `firebase_sync_status` enum('PENDING','SYNCED','FAILED') NOT NULL DEFAULT 'PENDING',
  `firebase_synced_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `bed_task_log_key` (`bed_task_log_key`),
  KEY `idx_project_bed_task_log_task` (`bed_task_key`,`created_at`),
  KEY `idx_project_bed_task_log_event` (`event_type`,`created_at`),
  KEY `idx_project_bed_task_log_actor` (`actor_user_key`,`created_at`),
  KEY `idx_project_bed_task_log_requester` (`requester_user_key`,`created_at`),
  KEY `idx_project_bed_task_log_firebase` (`firebase_sync_status`,`created_at`),
  KEY `idx_project_bed_task_log_stage` (`current_task_stage_key`,`created_at`),
  KEY `idx_project_bed_task_log_stage_alias` (`task_stage_key`,`created_at`),
  KEY `idx_project_bed_task_log_response` (`task_stage_response_key`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_bed_task_log_2026_08_27_13_48`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_bed_task_log_2026_08_27_13_48` (
  `bed_task_log_key` varchar(40) NOT NULL,
  `tenant_key` text DEFAULT NULL,
  `project_key` text DEFAULT NULL,
  `bed_task_key` varchar(40) NOT NULL,
  `bed_key` varchar(40) NOT NULL,
  `bed_source_key` varchar(160) NOT NULL DEFAULT '',
  `source_pk_psbeds` varchar(100) DEFAULT NULL,
  `bed_no` varchar(100) NOT NULL DEFAULT '',
  `branch_key` varchar(100) NOT NULL DEFAULT '',
  `branch_name` varchar(100) NOT NULL DEFAULT '',
  `building_key` varchar(100) NOT NULL DEFAULT '',
  `building_name` varchar(100) NOT NULL DEFAULT '',
  `floor_key` varchar(100) NOT NULL DEFAULT '',
  `floor_name` varchar(100) NOT NULL DEFAULT '',
  `nurse_station_key` varchar(100) NOT NULL DEFAULT '',
  `nurse_station_name` varchar(100) NOT NULL DEFAULT '',
  `room_key` varchar(100) NOT NULL DEFAULT '',
  `room_class_key` varchar(100) NOT NULL DEFAULT '',
  `room_class` varchar(100) NOT NULL DEFAULT '',
  `source_bed_status_key` varchar(100) NOT NULL DEFAULT '',
  `source_bed_status` varchar(100) NOT NULL DEFAULT '',
  `task_key` varchar(40) NOT NULL,
  `task_code` varchar(80) DEFAULT NULL,
  `task_title` varchar(255) NOT NULL DEFAULT '',
  `task_type` enum('PRIMARY','SECONDARY') NOT NULL,
  `task_color_hex` text DEFAULT NULL,
  `task_sort_order` bigint(20) DEFAULT NULL,
  `task_group_keys` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`task_group_keys`)),
  `task_status` text DEFAULT NULL,
  `current_task_stage_key` varchar(40) NOT NULL DEFAULT '',
  `current_stage_label` varchar(160) NOT NULL DEFAULT '',
  `current_stage_color_hex` char(9) NOT NULL DEFAULT '#00000000',
  `task_stage_key` varchar(40) NOT NULL DEFAULT '',
  `stage_label` varchar(160) NOT NULL DEFAULT '',
  `stage_color_hex` char(9) NOT NULL DEFAULT '#00000000',
  `task_stage_response_key` varchar(40) NOT NULL DEFAULT '',
  `response_label` varchar(160) NOT NULL DEFAULT '',
  `response_description` text DEFAULT NULL,
  `response_color_hex` char(9) NOT NULL DEFAULT '#00000000',
  `bed_status_at_request` varchar(100) NOT NULL DEFAULT '',
  `bed_class` varchar(100) NOT NULL DEFAULT '',
  `bed_treatment_key` varchar(40) DEFAULT NULL,
  `bed_treatment_name` varchar(160) NOT NULL DEFAULT '',
  `bed_source_option_key` varchar(40) DEFAULT NULL,
  `bed_source_option_name` varchar(160) NOT NULL DEFAULT '',
  `remarks` text DEFAULT NULL,
  `requester_user_key` char(36) NOT NULL,
  `requester_fullname` varchar(160) NOT NULL,
  `firebase_collection` text DEFAULT NULL,
  `mysql_sync_status` text DEFAULT NULL,
  `mysql_created_at` datetime(6) DEFAULT NULL,
  `mysql_updated_at` datetime(6) DEFAULT NULL,
  `mysql_synced_at` datetime(6) DEFAULT NULL,
  `mysql_deleted_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT current_timestamp(6),
  `updated_at` datetime(6) DEFAULT NULL,
  `event_type` enum('CREATED','ASSIGNED','STARTED','UPDATED','COMPLETED','CANCELLED','FAILED') NOT NULL,
  `status_from` varchar(40) DEFAULT NULL,
  `status_to` varchar(40) NOT NULL,
  `actor_user_key` char(36) NOT NULL,
  `actor_fullname` varchar(160) NOT NULL,
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `firebase_sync_status` enum('PENDING','SYNCED','FAILED') NOT NULL DEFAULT 'PENDING',
  `firebase_synced_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `bed_task_log_key` (`bed_task_log_key`),
  KEY `idx_project_bed_task_log_task` (`bed_task_key`,`created_at`),
  KEY `idx_project_bed_task_log_event` (`event_type`,`created_at`),
  KEY `idx_project_bed_task_log_actor` (`actor_user_key`,`created_at`),
  KEY `idx_project_bed_task_log_requester` (`requester_user_key`,`created_at`),
  KEY `idx_project_bed_task_log_firebase` (`firebase_sync_status`,`created_at`),
  KEY `idx_project_bed_task_log_stage` (`current_task_stage_key`,`created_at`),
  KEY `idx_project_bed_task_log_stage_alias` (`task_stage_key`,`created_at`),
  KEY `idx_project_bed_task_log_response` (`task_stage_response_key`,`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_bed_treatment`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_bed_treatment` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `bed_treatment_key` varchar(40) NOT NULL,
  `treatment_code` varchar(80) NOT NULL,
  `treatment_name` varchar(160) NOT NULL,
  `treatment_description` text DEFAULT NULL,
  `treatment_status` enum('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  `treatment_sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `created_by_user_key` char(36) DEFAULT NULL,
  `updated_by_user_key` char(36) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `bed_treatment_key` (`bed_treatment_key`),
  UNIQUE KEY `uq_project_bed_treatment_code` (`treatment_code`),
  KEY `idx_project_bed_treatment_status` (`treatment_status`,`treatment_sort_order`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_building_floor`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_building_floor` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `building_floor_key` varchar(40) NOT NULL,
  `branch_key` varchar(100) NOT NULL DEFAULT '',
  `branch_name` varchar(100) NOT NULL DEFAULT '',
  `building_key` varchar(100) NOT NULL DEFAULT '',
  `building_name` varchar(100) NOT NULL DEFAULT '',
  `floor_key` varchar(100) NOT NULL DEFAULT '',
  `floor_name` varchar(100) NOT NULL DEFAULT '',
  `building_sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `floor_sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `floor_status` enum('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  `created_by_user_key` char(36) DEFAULT NULL,
  `updated_by_user_key` char(36) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `building_floor_key` (`building_floor_key`),
  UNIQUE KEY `uq_project_building_floor_scope` (`branch_key`,`building_key`,`floor_key`),
  KEY `idx_project_building_floor_order` (`branch_key`,`building_key`,`building_sort_order`,`floor_sort_order`),
  KEY `idx_project_building_floor_status` (`floor_status`)
) ENGINE=InnoDB AUTO_INCREMENT=19552 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_group`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_group` (
  `group_key` varchar(255) DEFAULT NULL,
  `project_key` char(36) NOT NULL,
  `group_name` varchar(120) NOT NULL,
  `group_description` text DEFAULT NULL,
  `group_status` enum('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  `group_image_path` text DEFAULT NULL,
  `group_image_original_name` text DEFAULT NULL,
  `group_image_mime_type` text DEFAULT NULL,
  `group_image_byte_size` bigint(20) DEFAULT NULL,
  `group_image_sha256` text DEFAULT NULL,
  `group_image_uploaded_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT current_timestamp(6),
  `updated_at` datetime(6) NOT NULL DEFAULT current_timestamp(6) ON UPDATE current_timestamp(6),
  `firebase_collection` varchar(80) NOT NULL DEFAULT 'project_group',
  `mysql_created_at` datetime(6) DEFAULT NULL,
  `mysql_updated_at` datetime(6) DEFAULT NULL,
  `mysql_deleted_at` datetime(6) DEFAULT NULL,
  `mysql_synced_at` datetime(6) DEFAULT NULL,
  `mysql_sync_status` enum('PENDING','SYNCED','FAILED') NOT NULL DEFAULT 'PENDING',
  UNIQUE KEY `uq_project_group_name` (`project_key`,`group_name`),
  KEY `idx_project_group_project` (`project_key`),
  KEY `idx_project_group_status` (`group_status`),
  KEY `idx_project_group_sync_status` (`mysql_sync_status`),
  KEY `idx_project_group_firebase_collection` (`firebase_collection`),
  KEY `idx_project_group_group_key` (`group_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_group_2026_08_27_13_48`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_group_2026_08_27_13_48` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `group_key` char(36) NOT NULL,
  `project_key` char(36) NOT NULL,
  `group_name` varchar(120) NOT NULL,
  `group_description` text DEFAULT NULL,
  `group_status` enum('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  `firebase_collection` varchar(80) NOT NULL DEFAULT 'project_group',
  `mysql_created_at` datetime(6) DEFAULT NULL,
  `mysql_updated_at` datetime(6) DEFAULT NULL,
  `mysql_deleted_at` datetime(6) DEFAULT NULL,
  `mysql_synced_at` datetime(6) DEFAULT NULL,
  `mysql_sync_status` enum('PENDING','SYNCED','FAILED') NOT NULL DEFAULT 'PENDING',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `group_key` (`group_key`),
  UNIQUE KEY `uq_project_group_name` (`project_key`,`group_name`),
  KEY `idx_project_group_project` (`project_key`),
  KEY `idx_project_group_status` (`group_status`),
  KEY `idx_project_group_sync_status` (`mysql_sync_status`),
  KEY `idx_project_group_firebase_collection` (`firebase_collection`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_group_2026_08_27_14_40`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_group_2026_08_27_14_40` (
  `project_key` char(36) NOT NULL,
  `group_key` varchar(255) DEFAULT NULL,
  `group_name` varchar(120) NOT NULL,
  `group_description` text DEFAULT NULL,
  `group_status` enum('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  `firebase_collection` varchar(80) NOT NULL DEFAULT 'project_group',
  `mysql_created_at` datetime(6) DEFAULT NULL,
  `mysql_updated_at` datetime(6) DEFAULT NULL,
  `mysql_deleted_at` datetime(6) DEFAULT NULL,
  `mysql_synced_at` datetime(6) DEFAULT NULL,
  `mysql_sync_status` enum('PENDING','SYNCED','FAILED') NOT NULL DEFAULT 'PENDING',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  UNIQUE KEY `uq_project_group_name` (`project_key`,`group_name`),
  KEY `idx_project_group_project` (`project_key`),
  KEY `idx_project_group_status` (`group_status`),
  KEY `idx_project_group_sync_status` (`mysql_sync_status`),
  KEY `idx_project_group_firebase_collection` (`firebase_collection`),
  KEY `idx_project_group_group_key` (`group_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_group_2026_08_27_15_09`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_group_2026_08_27_15_09` (
  `group_key` varchar(255) DEFAULT NULL,
  `project_key` char(36) NOT NULL,
  `group_name` varchar(120) NOT NULL,
  `group_description` text DEFAULT NULL,
  `group_status` enum('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  `group_image_path` text DEFAULT NULL,
  `group_image_original_name` text DEFAULT NULL,
  `group_image_mime_type` text DEFAULT NULL,
  `group_image_byte_size` bigint(20) DEFAULT NULL,
  `group_image_sha256` text DEFAULT NULL,
  `group_image_uploaded_at` datetime(6) DEFAULT NULL,
  `firebase_collection` varchar(80) NOT NULL DEFAULT 'project_group',
  `mysql_created_at` datetime(6) DEFAULT NULL,
  `mysql_updated_at` datetime(6) DEFAULT NULL,
  `mysql_deleted_at` datetime(6) DEFAULT NULL,
  `mysql_synced_at` datetime(6) DEFAULT NULL,
  `mysql_sync_status` enum('PENDING','SYNCED','FAILED') NOT NULL DEFAULT 'PENDING',
  `created_at` datetime(6) NOT NULL DEFAULT current_timestamp(6),
  `updated_at` datetime(6) NOT NULL DEFAULT current_timestamp(6) ON UPDATE current_timestamp(6),
  UNIQUE KEY `uq_project_group_name` (`project_key`,`group_name`),
  KEY `idx_project_group_project` (`project_key`),
  KEY `idx_project_group_status` (`group_status`),
  KEY `idx_project_group_sync_status` (`mysql_sync_status`),
  KEY `idx_project_group_firebase_collection` (`firebase_collection`),
  KEY `idx_project_group_group_key` (`group_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_messenger_chat`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_messenger_chat` (
  `chat_key` varchar(40) NOT NULL,
  `project_key` varchar(80) NOT NULL,
  `group_key` varchar(40) NOT NULL,
  `conversation_type` enum('group','direct') NOT NULL DEFAULT 'group',
  `direct_recipient_user_key` varchar(80) DEFAULT NULL,
  `reply_to_chat_key` varchar(40) DEFAULT NULL,
  `sender_user_key` varchar(80) NOT NULL,
  `sender_name` varchar(160) NOT NULL,
  `message_text` text DEFAULT NULL,
  `message_type` enum('text','image','mixed') NOT NULL DEFAULT 'text',
  `message_status` enum('ACTIVE','REMOVED') NOT NULL DEFAULT 'ACTIVE',
  `removed_at` timestamp NULL DEFAULT NULL,
  `removed_by_user_key` varchar(80) DEFAULT NULL,
  `firebase_collection` varchar(80) NOT NULL DEFAULT 'project_messenger_chat',
  `mysql_sync_status` enum('PENDING','SYNCED','FAILED') NOT NULL DEFAULT 'PENDING',
  `mysql_created_at` datetime(6) DEFAULT NULL,
  `mysql_updated_at` datetime(6) DEFAULT NULL,
  `mysql_synced_at` datetime(6) DEFAULT NULL,
  `mysql_deleted_at` datetime DEFAULT NULL,
  UNIQUE KEY `chat_key` (`chat_key`),
  KEY `idx_project_messenger_chat_group` (`group_key`),
  KEY `idx_project_messenger_chat_project` (`project_key`,`group_key`),
  KEY `idx_project_messenger_chat_sender` (`sender_user_key`),
  KEY `idx_project_messenger_chat_reply` (`reply_to_chat_key`),
  KEY `idx_project_messenger_chat_firebase` (`firebase_collection`),
  KEY `idx_project_messenger_chat_direct` (`group_key`,`conversation_type`,`direct_recipient_user_key`,`sender_user_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_messenger_chat_2026_08_26_21_23`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_messenger_chat_2026_08_26_21_23` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `chat_key` varchar(40) NOT NULL,
  `project_key` varchar(80) NOT NULL,
  `group_key` varchar(40) NOT NULL,
  `conversation_type` enum('group','direct') NOT NULL DEFAULT 'group',
  `direct_recipient_user_key` varchar(80) DEFAULT NULL,
  `reply_to_chat_key` varchar(40) DEFAULT NULL,
  `sender_user_key` varchar(80) NOT NULL,
  `sender_name` varchar(160) NOT NULL,
  `message_text` text DEFAULT NULL,
  `message_type` enum('text','image','mixed') NOT NULL DEFAULT 'text',
  `message_status` enum('ACTIVE','REMOVED') NOT NULL DEFAULT 'ACTIVE',
  `removed_at` timestamp NULL DEFAULT NULL,
  `removed_by_user_key` varchar(80) DEFAULT NULL,
  `firebase_collection` varchar(80) NOT NULL DEFAULT 'project_messenger_chat',
  `firebase_sync_status` enum('PENDING','SYNCED','FAILED') NOT NULL DEFAULT 'PENDING',
  `firebase_synced_at` timestamp NULL DEFAULT NULL,
  `mysql_created_at` datetime DEFAULT NULL,
  `mysql_updated_at` datetime DEFAULT NULL,
  `mysql_synced_at` datetime DEFAULT NULL,
  `mysql_deleted_at` datetime DEFAULT NULL,
  `mysql_sync_status` enum('PENDING','SYNCED','FAILED') NOT NULL DEFAULT 'PENDING',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `chat_key` (`chat_key`),
  KEY `idx_project_messenger_chat_group` (`group_key`,`x_id`),
  KEY `idx_project_messenger_chat_project` (`project_key`,`group_key`),
  KEY `idx_project_messenger_chat_sender` (`sender_user_key`),
  KEY `idx_project_messenger_chat_reply` (`reply_to_chat_key`),
  KEY `idx_project_messenger_chat_firebase` (`firebase_collection`,`firebase_sync_status`),
  KEY `idx_project_messenger_chat_direct` (`group_key`,`conversation_type`,`direct_recipient_user_key`,`sender_user_key`,`x_id`)
) ENGINE=InnoDB AUTO_INCREMENT=34 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_messenger_chat_2026_08_26_21_29`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_messenger_chat_2026_08_26_21_29` (
  `chat_key` varchar(40) NOT NULL,
  `project_key` varchar(80) NOT NULL,
  `group_key` varchar(40) NOT NULL,
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `conversation_type` enum('group','direct') NOT NULL DEFAULT 'group',
  `direct_recipient_user_key` varchar(80) DEFAULT NULL,
  `reply_to_chat_key` varchar(40) DEFAULT NULL,
  `sender_user_key` varchar(80) NOT NULL,
  `sender_name` varchar(160) NOT NULL,
  `message_text` text DEFAULT NULL,
  `message_type` enum('text','image','mixed') NOT NULL DEFAULT 'text',
  `message_status` enum('ACTIVE','REMOVED') NOT NULL DEFAULT 'ACTIVE',
  `removed_at` timestamp NULL DEFAULT NULL,
  `removed_by_user_key` varchar(80) DEFAULT NULL,
  `firebase_collection` varchar(80) NOT NULL DEFAULT 'project_messenger_chat',
  `firebase_sync_status` enum('PENDING','SYNCED','FAILED') NOT NULL DEFAULT 'PENDING',
  `firebase_synced_at` timestamp NULL DEFAULT NULL,
  `mysql_created_at` datetime DEFAULT NULL,
  `mysql_updated_at` datetime DEFAULT NULL,
  `mysql_synced_at` datetime DEFAULT NULL,
  `mysql_deleted_at` datetime DEFAULT NULL,
  `mysql_sync_status` enum('PENDING','SYNCED','FAILED') NOT NULL DEFAULT 'PENDING',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `chat_key` (`chat_key`),
  KEY `idx_project_messenger_chat_group` (`group_key`,`x_id`),
  KEY `idx_project_messenger_chat_project` (`project_key`,`group_key`),
  KEY `idx_project_messenger_chat_sender` (`sender_user_key`),
  KEY `idx_project_messenger_chat_reply` (`reply_to_chat_key`),
  KEY `idx_project_messenger_chat_firebase` (`firebase_collection`,`firebase_sync_status`),
  KEY `idx_project_messenger_chat_direct` (`group_key`,`conversation_type`,`direct_recipient_user_key`,`sender_user_key`,`x_id`)
) ENGINE=InnoDB AUTO_INCREMENT=34 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_messenger_chat_2026_08_26_21_33`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_messenger_chat_2026_08_26_21_33` (
  `chat_key` varchar(40) NOT NULL,
  `project_key` varchar(80) NOT NULL,
  `group_key` varchar(40) NOT NULL,
  `conversation_type` enum('group','direct') NOT NULL DEFAULT 'group',
  `direct_recipient_user_key` varchar(80) DEFAULT NULL,
  `reply_to_chat_key` varchar(40) DEFAULT NULL,
  `sender_user_key` varchar(80) NOT NULL,
  `sender_name` varchar(160) NOT NULL,
  `message_text` text DEFAULT NULL,
  `message_type` enum('text','image','mixed') NOT NULL DEFAULT 'text',
  `message_status` enum('ACTIVE','REMOVED') NOT NULL DEFAULT 'ACTIVE',
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `removed_at` timestamp NULL DEFAULT NULL,
  `removed_by_user_key` varchar(80) DEFAULT NULL,
  `firebase_collection` varchar(80) NOT NULL DEFAULT 'project_messenger_chat',
  `firebase_sync_status` enum('PENDING','SYNCED','FAILED') NOT NULL DEFAULT 'PENDING',
  `firebase_synced_at` timestamp NULL DEFAULT NULL,
  `mysql_created_at` datetime DEFAULT NULL,
  `mysql_updated_at` datetime DEFAULT NULL,
  `mysql_synced_at` datetime DEFAULT NULL,
  `mysql_deleted_at` datetime DEFAULT NULL,
  `mysql_sync_status` enum('PENDING','SYNCED','FAILED') NOT NULL DEFAULT 'PENDING',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `chat_key` (`chat_key`),
  KEY `idx_project_messenger_chat_group` (`group_key`,`x_id`),
  KEY `idx_project_messenger_chat_project` (`project_key`,`group_key`),
  KEY `idx_project_messenger_chat_sender` (`sender_user_key`),
  KEY `idx_project_messenger_chat_reply` (`reply_to_chat_key`),
  KEY `idx_project_messenger_chat_firebase` (`firebase_collection`,`firebase_sync_status`),
  KEY `idx_project_messenger_chat_direct` (`group_key`,`conversation_type`,`direct_recipient_user_key`,`sender_user_key`,`x_id`)
) ENGINE=InnoDB AUTO_INCREMENT=34 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_messenger_chat_2026_08_27_13_48`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_messenger_chat_2026_08_27_13_48` (
  `chat_key` varchar(40) NOT NULL,
  `project_key` varchar(80) NOT NULL,
  `group_key` varchar(40) NOT NULL,
  `conversation_type` enum('group','direct') NOT NULL DEFAULT 'group',
  `direct_recipient_user_key` varchar(80) DEFAULT NULL,
  `reply_to_chat_key` varchar(40) DEFAULT NULL,
  `sender_user_key` varchar(80) NOT NULL,
  `sender_name` varchar(160) NOT NULL,
  `message_text` text DEFAULT NULL,
  `message_type` enum('text','image','mixed') NOT NULL DEFAULT 'text',
  `message_status` enum('ACTIVE','REMOVED') NOT NULL DEFAULT 'ACTIVE',
  `removed_at` timestamp NULL DEFAULT NULL,
  `removed_by_user_key` varchar(80) DEFAULT NULL,
  `firebase_collection` varchar(80) NOT NULL DEFAULT 'project_messenger_chat',
  `mysql_sync_status` enum('PENDING','SYNCED','FAILED') NOT NULL DEFAULT 'PENDING',
  `mysql_created_at` datetime(6) DEFAULT NULL,
  `mysql_updated_at` datetime(6) DEFAULT NULL,
  `mysql_synced_at` datetime(6) DEFAULT NULL,
  `mysql_deleted_at` datetime DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT current_timestamp(6),
  `updated_at` datetime(6) NOT NULL DEFAULT current_timestamp(6) ON UPDATE current_timestamp(6),
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `firebase_sync_status` enum('PENDING','SYNCED','FAILED') NOT NULL DEFAULT 'PENDING',
  `firebase_synced_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `chat_key` (`chat_key`),
  KEY `idx_project_messenger_chat_group` (`group_key`,`x_id`),
  KEY `idx_project_messenger_chat_project` (`project_key`,`group_key`),
  KEY `idx_project_messenger_chat_sender` (`sender_user_key`),
  KEY `idx_project_messenger_chat_reply` (`reply_to_chat_key`),
  KEY `idx_project_messenger_chat_firebase` (`firebase_collection`,`firebase_sync_status`),
  KEY `idx_project_messenger_chat_direct` (`group_key`,`conversation_type`,`direct_recipient_user_key`,`sender_user_key`,`x_id`)
) ENGINE=InnoDB AUTO_INCREMENT=34 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_messenger_chat_attachment`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_messenger_chat_attachment` (
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_messenger_chat_attachment_2026_08_27_13_48`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_messenger_chat_attachment_2026_08_27_13_48` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `attachment_key` varchar(40) NOT NULL,
  `chat_key` varchar(40) NOT NULL,
  `project_key` varchar(80) NOT NULL,
  `group_key` varchar(40) NOT NULL,
  `uploaded_image_url` varchar(500) NOT NULL,
  `image_original_name` varchar(255) DEFAULT NULL,
  `image_mime_type` varchar(100) DEFAULT NULL,
  `image_byte_size` bigint(20) unsigned NOT NULL DEFAULT 0,
  `image_sha256` varchar(128) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `attachment_status` enum('ACTIVE','REMOVED') NOT NULL DEFAULT 'ACTIVE',
  `firebase_collection` varchar(80) NOT NULL DEFAULT 'project_messenger_chat_attachment',
  `firebase_sync_status` enum('PENDING','SYNCED','FAILED') NOT NULL DEFAULT 'PENDING',
  `firebase_synced_at` timestamp NULL DEFAULT NULL,
  `mysql_created_at` datetime DEFAULT NULL,
  `mysql_updated_at` datetime DEFAULT NULL,
  `mysql_synced_at` datetime DEFAULT NULL,
  `mysql_deleted_at` datetime DEFAULT NULL,
  `mysql_sync_status` enum('PENDING','SYNCED','FAILED') NOT NULL DEFAULT 'PENDING',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `attachment_key` (`attachment_key`),
  KEY `idx_project_messenger_attachment_chat` (`chat_key`,`sort_order`),
  KEY `idx_project_messenger_attachment_group` (`group_key`,`chat_key`),
  KEY `idx_project_messenger_attachment_firebase` (`firebase_collection`,`firebase_sync_status`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_messenger_chat_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_messenger_chat_log` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `log_key` varchar(40) NOT NULL,
  `chat_key` varchar(40) NOT NULL,
  `project_key` varchar(80) NOT NULL,
  `group_key` varchar(40) NOT NULL,
  `conversation_type` enum('group','direct') NOT NULL DEFAULT 'group',
  `direct_recipient_user_key` varchar(80) DEFAULT NULL,
  `reply_to_chat_key` varchar(40) DEFAULT NULL,
  `sender_user_key` varchar(80) NOT NULL,
  `sender_name` varchar(160) NOT NULL,
  `message_text` text DEFAULT NULL,
  `message_type` enum('text','image','mixed') NOT NULL DEFAULT 'text',
  `message_status` enum('ACTIVE','REMOVED') NOT NULL DEFAULT 'ACTIVE',
  `removed_at` timestamp NULL DEFAULT NULL,
  `removed_by_user_key` varchar(80) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `archived_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `log_key` (`log_key`),
  UNIQUE KEY `uq_project_messenger_chat_log_chat` (`chat_key`),
  KEY `idx_project_messenger_chat_log_conversation` (`group_key`,`conversation_type`,`direct_recipient_user_key`,`sender_user_key`,`x_id`),
  KEY `idx_project_messenger_chat_log_created` (`group_key`,`created_at`,`x_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_messenger_chat_reaction`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_messenger_chat_reaction` (
  `chat_key` varchar(40) NOT NULL,
  `project_key` varchar(80) NOT NULL,
  `group_key` varchar(40) NOT NULL,
  `user_key` varchar(80) NOT NULL,
  `reaction_value` varchar(40) NOT NULL,
  `reaction_status` enum('ACTIVE','REMOVED') NOT NULL DEFAULT 'ACTIVE',
  `firebase_collection` varchar(80) NOT NULL DEFAULT 'project_messenger_chat_reaction',
  `firebase_sync_status` enum('PENDING','SYNCED','FAILED') NOT NULL DEFAULT 'PENDING',
  `firebase_synced_at` timestamp NULL DEFAULT NULL,
  `mysql_created_at` datetime DEFAULT NULL,
  `mysql_updated_at` datetime DEFAULT NULL,
  `mysql_synced_at` datetime DEFAULT NULL,
  `mysql_deleted_at` datetime DEFAULT NULL,
  `mysql_sync_status` enum('PENDING','SYNCED','FAILED') NOT NULL DEFAULT 'PENDING',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  UNIQUE KEY `uq_project_messenger_reaction_user` (`chat_key`,`user_key`,`reaction_value`),
  KEY `idx_project_messenger_reaction_group` (`group_key`,`chat_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_messenger_chat_reaction_2026_08_27_13_48`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_messenger_chat_reaction_2026_08_27_13_48` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `reaction_key` varchar(40) NOT NULL,
  `chat_key` varchar(40) NOT NULL,
  `project_key` varchar(80) NOT NULL,
  `group_key` varchar(40) NOT NULL,
  `user_key` varchar(80) NOT NULL,
  `reaction_value` varchar(40) NOT NULL,
  `reaction_status` enum('ACTIVE','REMOVED') NOT NULL DEFAULT 'ACTIVE',
  `firebase_collection` varchar(80) NOT NULL DEFAULT 'project_messenger_chat_reaction',
  `firebase_sync_status` enum('PENDING','SYNCED','FAILED') NOT NULL DEFAULT 'PENDING',
  `firebase_synced_at` timestamp NULL DEFAULT NULL,
  `mysql_created_at` datetime DEFAULT NULL,
  `mysql_updated_at` datetime DEFAULT NULL,
  `mysql_synced_at` datetime DEFAULT NULL,
  `mysql_deleted_at` datetime DEFAULT NULL,
  `mysql_sync_status` enum('PENDING','SYNCED','FAILED') NOT NULL DEFAULT 'PENDING',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `reaction_key` (`reaction_key`),
  UNIQUE KEY `uq_project_messenger_reaction_user` (`chat_key`,`user_key`,`reaction_value`),
  KEY `idx_project_messenger_reaction_group` (`group_key`,`chat_key`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_messenger_chat_read`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_messenger_chat_read` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `read_key` varchar(40) NOT NULL,
  `chat_key` varchar(40) NOT NULL,
  `project_key` varchar(80) NOT NULL,
  `group_key` varchar(40) NOT NULL,
  `user_key` varchar(80) NOT NULL,
  `read_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `firebase_collection` varchar(80) NOT NULL DEFAULT 'project_messenger_chat_read',
  `firebase_sync_status` enum('PENDING','SYNCED','FAILED') NOT NULL DEFAULT 'PENDING',
  `firebase_synced_at` timestamp NULL DEFAULT NULL,
  `mysql_created_at` datetime DEFAULT NULL,
  `mysql_updated_at` datetime DEFAULT NULL,
  `mysql_synced_at` datetime DEFAULT NULL,
  `mysql_deleted_at` datetime DEFAULT NULL,
  `mysql_sync_status` enum('PENDING','SYNCED','FAILED') NOT NULL DEFAULT 'PENDING',
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `read_key` (`read_key`),
  UNIQUE KEY `uq_project_messenger_read_user` (`chat_key`,`user_key`),
  KEY `idx_project_messenger_read_group` (`group_key`,`user_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_messenger_sync_event`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_messenger_sync_event` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `event_key` varchar(40) NOT NULL,
  `source` varchar(24) NOT NULL DEFAULT 'web',
  `target` varchar(24) NOT NULL DEFAULT 'firebase',
  `event_type` varchar(40) NOT NULL,
  `project_key` varchar(80) NOT NULL,
  `group_key` varchar(40) NOT NULL,
  `chat_key` varchar(40) DEFAULT NULL,
  `payload_json` longtext NOT NULL,
  `status` enum('PENDING','PROCESSING','SYNCED','FAILED') NOT NULL DEFAULT 'PENDING',
  `attempt_count` int(10) unsigned NOT NULL DEFAULT 0,
  `claim_key` varchar(80) DEFAULT NULL,
  `available_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_error` varchar(1000) DEFAULT NULL,
  `processed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `event_key` (`event_key`),
  KEY `idx_project_messenger_sync_event_ready` (`target`,`status`,`available_at`,`x_id`),
  KEY `idx_project_messenger_sync_event_chat` (`chat_key`,`target`,`status`),
  KEY `idx_project_messenger_sync_event_claim` (`claim_key`,`status`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_position`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_position` (
  `xId` int(10) NOT NULL AUTO_INCREMENT,
  `position_key` varchar(255) NOT NULL,
  `project_key` text DEFAULT NULL,
  `group_key` text DEFAULT NULL,
  `position_code` text DEFAULT NULL,
  `position_name` text DEFAULT NULL,
  `position_description` text DEFAULT NULL,
  `position_status` text DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `firebase_collection` text DEFAULT NULL,
  `mysql_created_at` datetime(6) DEFAULT NULL,
  `mysql_updated_at` datetime(6) DEFAULT NULL,
  `mysql_deleted_at` datetime(6) DEFAULT NULL,
  `mysql_synced_at` datetime(6) DEFAULT NULL,
  `mysql_sync_status` text DEFAULT NULL,
  PRIMARY KEY (`xId`),
  UNIQUE KEY `uq_project_position_position_key` (`position_key`),
  KEY `idx_project_position_sync_status` (`mysql_sync_status`(768)),
  KEY `idx_project_position_firebase_collection` (`firebase_collection`(768))
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_setting`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_setting` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `setting_key` char(36) NOT NULL,
  `project_key` char(36) NOT NULL,
  `setting_name` varchar(120) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `setting_group` varchar(80) NOT NULL DEFAULT 'general',
  `is_secret` tinyint(1) NOT NULL DEFAULT 0,
  `setting_status` enum('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `setting_key` (`setting_key`),
  UNIQUE KEY `uq_project_setting_name` (`project_key`,`setting_name`),
  KEY `idx_project_setting_group` (`project_key`,`setting_group`,`setting_status`)
) ENGINE=InnoDB AUTO_INCREMENT=29 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_setting_media`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_setting_media` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `setting_key` char(36) NOT NULL,
  `project_key` char(36) NOT NULL,
  `setting_name` varchar(120) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `setting_group` varchar(80) NOT NULL DEFAULT 'media',
  `is_secret` tinyint(1) NOT NULL DEFAULT 0,
  `setting_status` enum('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `setting_key` (`setting_key`),
  UNIQUE KEY `uq_project_setting_media_name` (`project_key`,`setting_name`),
  KEY `idx_project_setting_media_group` (`project_key`,`setting_group`,`setting_status`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_task`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_task` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `task_key` varchar(40) NOT NULL,
  `task_code` varchar(80) DEFAULT NULL,
  `task_title` varchar(255) NOT NULL,
  `task_description` mediumtext DEFAULT NULL,
  `task_group_keys` text DEFAULT NULL,
  `task_bypass_group_keys` text DEFAULT NULL,
  `task_type` enum('PRIMARY','SECONDARY') NOT NULL DEFAULT 'PRIMARY',
  `task_status` enum('ACTIVE','INACTIVE') NOT NULL DEFAULT 'INACTIVE',
  `task_priority` enum('LOW','NORMAL','HIGH','URGENT') NOT NULL DEFAULT 'NORMAL',
  `task_color_hex` char(9) NOT NULL DEFAULT '#00000000',
  `task_can_run_manually` tinyint(1) NOT NULL DEFAULT 0,
  `task_can_run_via_api` tinyint(1) NOT NULL DEFAULT 0,
  `task_can_run_if_bed_vacant` tinyint(1) NOT NULL DEFAULT 1,
  `task_can_run_if_bed_occupied` tinyint(1) NOT NULL DEFAULT 1,
  `task_requires_bed_treatment` tinyint(1) NOT NULL DEFAULT 1,
  `task_requires_admission_source` tinyint(1) NOT NULL DEFAULT 1,
  `task_canvas_x` int(10) unsigned NOT NULL DEFAULT 24,
  `task_canvas_y` int(10) unsigned NOT NULL DEFAULT 24,
  `task_sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `created_by_user_key` char(36) DEFAULT NULL,
  `updated_by_user_key` char(36) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `task_key` (`task_key`),
  KEY `idx_project_task_status` (`task_status`),
  KEY `idx_project_task_priority` (`task_priority`),
  KEY `idx_project_task_code` (`task_code`),
  KEY `idx_project_task_type` (`task_type`,`task_status`,`task_sort_order`),
  KEY `idx_project_task_portal_run` (`task_type`,`task_status`,`task_can_run_manually`,`task_can_run_if_bed_vacant`,`task_can_run_if_bed_occupied`,`task_sort_order`)
) ENGINE=InnoDB AUTO_INCREMENT=114 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_task_stage`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_task_stage` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `task_stage_key` varchar(40) NOT NULL,
  `task_key` varchar(40) NOT NULL,
  `stage_label` varchar(160) NOT NULL DEFAULT '',
  `stage_description` text DEFAULT NULL,
  `stage_color_hex` char(9) NOT NULL DEFAULT '#00000000',
  `stage_status` enum('ACTIVE','INACTIVE') NOT NULL DEFAULT 'INACTIVE',
  `stage_ends_task` tinyint(1) NOT NULL DEFAULT 0,
  `stage_can_run_manually` tinyint(1) NOT NULL DEFAULT 0,
  `stage_can_run_via_api` tinyint(1) NOT NULL DEFAULT 0,
  `connected_task_key` varchar(40) DEFAULT NULL,
  `connected_task_trigger_point` enum('PREVIOUS_STAGE_FINISHED','CURRENT_STAGE_FINISHED') NOT NULL DEFAULT 'CURRENT_STAGE_FINISHED',
  `stage_sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `created_by_user_key` char(36) DEFAULT NULL,
  `updated_by_user_key` char(36) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `task_stage_key` (`task_stage_key`),
  KEY `idx_project_task_stage_task` (`task_key`,`stage_status`,`stage_sort_order`),
  KEY `idx_project_task_stage_status` (`stage_status`),
  KEY `idx_project_task_stage_connected` (`connected_task_key`)
) ENGINE=InnoDB AUTO_INCREMENT=187 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_task_stage_response`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_task_stage_response` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `task_stage_response_key` varchar(40) NOT NULL,
  `task_key` varchar(40) NOT NULL,
  `task_stage_key` varchar(40) NOT NULL,
  `response_label` varchar(160) NOT NULL,
  `response_description` text DEFAULT NULL,
  `response_color_hex` char(9) NOT NULL DEFAULT '#00000000',
  `response_status` enum('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  `response_sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `created_by_user_key` char(36) DEFAULT NULL,
  `updated_by_user_key` char(36) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `task_stage_response_key` (`task_stage_response_key`),
  KEY `idx_project_task_stage_response_stage` (`task_stage_key`,`response_status`,`response_sort_order`),
  KEY `idx_project_task_stage_response_task` (`task_key`,`response_status`,`response_sort_order`),
  KEY `idx_project_task_stage_response_status` (`response_status`)
) ENGINE=InnoDB AUTO_INCREMENT=51 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_traverse_document`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_traverse_document` (
  `xId` int(10) NOT NULL AUTO_INCREMENT,
  `firebase_collection` varchar(80) NOT NULL,
  `traverse_status` enum('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  PRIMARY KEY (`xId`),
  UNIQUE KEY `uq_project_traverse_collection` (`firebase_collection`),
  KEY `idx_project_traverse_document_status` (`traverse_status`,`firebase_collection`)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_traverse_document_legacy_2026_08_27_15_01`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_traverse_document_legacy_2026_08_27_15_01` (
  `xId` int(10) NOT NULL AUTO_INCREMENT,
  `firebase_collection` varchar(80) NOT NULL,
  `firebase_document_id` varchar(255) NOT NULL,
  `traverse_status` enum('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  PRIMARY KEY (`xId`),
  UNIQUE KEY `uq_project_traverse_document` (`firebase_collection`,`firebase_document_id`),
  KEY `idx_project_traverse_document_status` (`traverse_status`,`firebase_collection`)
) ENGINE=InnoDB AUTO_INCREMENT=209 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_user`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_user` (
  `xId` int(10) NOT NULL AUTO_INCREMENT,
  `user_key` varchar(255) NOT NULL,
  `firebase_uid` text DEFAULT NULL,
  `project_key` text DEFAULT NULL,
  `user_login` text DEFAULT NULL,
  `user_auth_username` text DEFAULT NULL,
  `user_auth_email` text DEFAULT NULL,
  `user_name` text DEFAULT NULL,
  `user_chat_name` text DEFAULT NULL,
  `user_mobile_number` text DEFAULT NULL,
  `user_avatar_path` text DEFAULT NULL,
  `user_avatar_original_name` text DEFAULT NULL,
  `user_avatar_mime_type` text DEFAULT NULL,
  `user_avatar_byte_size` bigint(20) DEFAULT NULL,
  `user_avatar_sha256` text DEFAULT NULL,
  `user_avatar_uploaded_at` datetime(6) DEFAULT NULL,
  `user_status` text DEFAULT NULL,
  `user_password_change_required` tinyint(1) DEFAULT NULL,
  `user_disabled` tinyint(1) DEFAULT NULL,
  `user_deleted` tinyint(1) DEFAULT NULL,
  `user_locked` tinyint(1) DEFAULT NULL,
  `user_last_login_at` datetime(6) DEFAULT NULL,
  `user_last_login_ip_address` text DEFAULT NULL,
  `user_last_login_device` text DEFAULT NULL,
  `user_last_logout_at` datetime(6) DEFAULT NULL,
  `user_last_logout_ip_address` text DEFAULT NULL,
  `user_last_logout_device` text DEFAULT NULL,
  `user_password_reset_at` datetime(6) DEFAULT NULL,
  `user_activated_at` datetime(6) DEFAULT NULL,
  `user_deactivated_at` datetime(6) DEFAULT NULL,
  `user_locked_at` datetime(6) DEFAULT NULL,
  `firebase_collection` text DEFAULT NULL,
  `mysql_created_at` datetime(6) DEFAULT NULL,
  `mysql_updated_at` datetime(6) DEFAULT NULL,
  `mysql_deleted_at` datetime(6) DEFAULT NULL,
  `mysql_synced_at` datetime(6) DEFAULT NULL,
  `mysql_sync_status` text DEFAULT NULL,
  `firebase_created_at` datetime(6) DEFAULT NULL,
  `firebase_updated_at` datetime(6) DEFAULT NULL,
  `firebase_deleted_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`xId`),
  UNIQUE KEY `uq_project_user_user_key` (`user_key`),
  UNIQUE KEY `uq_project_user_auth_username` (`project_key`,`user_auth_username`) USING HASH,
  UNIQUE KEY `uq_project_user_auth_email` (`project_key`,`user_auth_email`) USING HASH,
  UNIQUE KEY `uq_project_user_mobile` (`project_key`,`user_mobile_number`) USING HASH,
  UNIQUE KEY `uq_project_user_firebase_uid` (`project_key`,`firebase_uid`) USING HASH,
  KEY `idx_project_user_sync_status` (`mysql_sync_status`(768))
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_user_group`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_user_group` (
  `xId` int(10) NOT NULL AUTO_INCREMENT,
  `assignment_key` varchar(255) NOT NULL,
  `project_key` text DEFAULT NULL,
  `group_key` text DEFAULT NULL,
  `user_key` text DEFAULT NULL,
  `position_key` text DEFAULT NULL,
  `assignment_status` text DEFAULT NULL,
  `firebase_collection` text DEFAULT NULL,
  `mysql_created_at` datetime(6) DEFAULT NULL,
  `mysql_updated_at` datetime(6) DEFAULT NULL,
  `mysql_deleted_at` datetime(6) DEFAULT NULL,
  `mysql_synced_at` datetime(6) DEFAULT NULL,
  `mysql_sync_status` text DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`xId`),
  UNIQUE KEY `uq_project_user_group_assignment_key` (`assignment_key`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_user_group_2026_08_27_15_17`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_user_group_2026_08_27_15_17` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `group_key` char(36) NOT NULL,
  `project_key` char(36) NOT NULL,
  `group_name` varchar(120) NOT NULL,
  `group_description` text DEFAULT NULL,
  `group_image_path` varchar(500) DEFAULT NULL,
  `group_image_original_name` varchar(255) DEFAULT NULL,
  `group_image_mime_type` varchar(120) DEFAULT NULL,
  `group_image_byte_size` bigint(20) unsigned DEFAULT NULL,
  `group_image_sha256` char(64) DEFAULT NULL,
  `group_image_uploaded_at` timestamp NULL DEFAULT NULL,
  `group_status` enum('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `group_key` (`group_key`),
  KEY `idx_project_user_group_name` (`project_key`,`group_name`),
  KEY `idx_project_user_group_project` (`project_key`),
  KEY `idx_project_user_group_status` (`group_status`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_user_login_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_user_login_history` (
  `xId` int(10) NOT NULL AUTO_INCREMENT,
  `user_login_history_key` varchar(255) NOT NULL,
  `project_key` text DEFAULT NULL,
  `user_key` text DEFAULT NULL,
  `user_login` text DEFAULT NULL,
  `user_action` text DEFAULT NULL,
  `user_action_status` text DEFAULT NULL,
  `user_action_at` datetime(6) DEFAULT NULL,
  `user_previous_status` text DEFAULT NULL,
  `user_new_status` text DEFAULT NULL,
  `user_action_reason` text DEFAULT NULL,
  `user_performed_by_key` text DEFAULT NULL,
  `user_ip_address` text DEFAULT NULL,
  `user_device` text DEFAULT NULL,
  `firebase_collection` text DEFAULT NULL,
  `mysql_created_at` datetime(6) DEFAULT NULL,
  `mysql_updated_at` datetime(6) DEFAULT NULL,
  `mysql_deleted_at` datetime(6) DEFAULT NULL,
  `mysql_synced_at` datetime(6) DEFAULT NULL,
  `mysql_sync_status` text DEFAULT NULL,
  `firebase_created_at` datetime(6) DEFAULT NULL,
  `firebase_updated_at` datetime(6) DEFAULT NULL,
  `firebase_deleted_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`xId`),
  UNIQUE KEY `uq_project_user_login_history_user_login_history_key` (`user_login_history_key`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_user_position`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_user_position` (
  `x_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `position_key` char(36) NOT NULL,
  `project_key` char(36) NOT NULL,
  `group_key` char(36) NOT NULL,
  `position_code` varchar(80) NOT NULL,
  `position_name` varchar(160) NOT NULL,
  `position_description` text DEFAULT NULL,
  `position_status` enum('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`x_id`),
  UNIQUE KEY `position_key` (`position_key`),
  UNIQUE KEY `uq_project_user_position_code` (`project_key`,`position_code`),
  UNIQUE KEY `uq_project_user_position_name` (`project_key`,`position_name`),
  KEY `idx_project_user_position_project` (`project_key`),
  KEY `idx_project_user_position_group` (`group_key`),
  KEY `idx_project_user_position_status` (`position_status`)
) ENGINE=InnoDB AUTO_INCREMENT=229 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `px_for_hras`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `px_for_hras` (
  `zId` int(10) NOT NULL AUTO_INCREMENT,
  `Operation` text DEFAULT NULL,
  `SurgicalCode` text DEFAULT NULL,
  `FinalDiagnosis` text DEFAULT NULL,
  `ICD10 Code` text DEFAULT NULL,
  `AdmissionDiagnosis` text DEFAULT NULL,
  `ChiefComplaint` text DEFAULT NULL,
  `Room Class` text DEFAULT NULL,
  `PatientDays` text DEFAULT NULL,
  `Patient Disposition` text DEFAULT NULL,
  `Main Attending` text DEFAULT NULL,
  `Attending Doctors` text DEFAULT NULL,
  `DeathType` text DEFAULT NULL,
  `DeliveryType` text DEFAULT NULL,
  `BedSequence` text DEFAULT NULL,
  `PatientRooms` text DEFAULT NULL,
  `NurseStn` text DEFAULT NULL,
  `PHICMembership` text DEFAULT NULL,
  `HospitalPlan` text DEFAULT NULL,
  `SubServiceType` text DEFAULT NULL,
  `ServiceType` text DEFAULT NULL,
  `Year Discharged` text DEFAULT NULL,
  `Month Discharged` text DEFAULT NULL,
  `DischargeTime` text DEFAULT NULL,
  `Discharge Date` text DEFAULT NULL,
  `How Admitted` text DEFAULT NULL,
  `Registry Status` text DEFAULT NULL,
  `Year Registered` text DEFAULT NULL,
  `Month Registered` text DEFAULT NULL,
  `RegistrationTime` text DEFAULT NULL,
  `RegistrationDate` text DEFAULT NULL,
  `CivilStatus` text DEFAULT NULL,
  `religion` text DEFAULT NULL,
  `nationality` text DEFAULT NULL,
  `Province` text DEFAULT NULL,
  `TownCity` text DEFAULT NULL,
  `Barangay` text DEFAULT NULL,
  `BldgStreet` text DEFAULT NULL,
  `Birthdate` text DEFAULT NULL,
  `Gender` text DEFAULT NULL,
  `Age Class` text DEFAULT NULL,
  `Age` text DEFAULT NULL,
  `email` text DEFAULT NULL,
  `suffixname` text DEFAULT NULL,
  `lastname` text DEFAULT NULL,
  `middlename` text DEFAULT NULL,
  `firstname` text DEFAULT NULL,
  `PatientName` text DEFAULT NULL,
  `CaseType` text DEFAULT NULL,
  `AdmissionNO` text DEFAULT NULL,
  `Patient ID` text DEFAULT NULL,
  `Patient Registry` text DEFAULT NULL,
  `Patient Type` text DEFAULT NULL,
  `zStatus` enum('ACTIVE','INACTIVE') NOT NULL DEFAULT 'INACTIVE',
  `zDateTime` datetime NOT NULL DEFAULT current_timestamp(),
  `zUpDateTime` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`zId`),
  UNIQUE KEY `AdmissionNO` (`AdmissionNO`,`Patient ID`,`Patient Registry`) USING HASH
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;
