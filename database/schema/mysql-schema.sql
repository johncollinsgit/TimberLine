/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `accounting_audit_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `accounting_audit_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `actor_user_id` bigint unsigned DEFAULT NULL,
  `event_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject_type` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subject_id` bigint unsigned DEFAULT NULL,
  `context` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `occurred_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `acct_audit_tenant_time_idx` (`tenant_id`,`occurred_at`),
  KEY `acct_audit_actor_fk` (`actor_user_id`),
  CONSTRAINT `acct_audit_actor_fk` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `acct_audit_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `accounting_close_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `accounting_close_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `accounting_close_period_id` bigint unsigned NOT NULL,
  `definition_key` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `sort_order` smallint unsigned NOT NULL DEFAULT '0',
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `deep_link` varchar(1024) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `owner_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `evidence` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `completed_at` timestamp NULL DEFAULT NULL,
  `completed_by_user_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `acct_close_items_definition_unique` (`accounting_close_period_id`,`definition_key`),
  KEY `acct_close_items_status_idx` (`tenant_id`,`status`),
  KEY `acct_close_items_user_fk` (`completed_by_user_id`),
  KEY `accounting_close_items_status_index` (`status`),
  CONSTRAINT `acct_close_items_period_fk` FOREIGN KEY (`accounting_close_period_id`) REFERENCES `accounting_close_periods` (`id`) ON DELETE CASCADE,
  CONSTRAINT `acct_close_items_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `acct_close_items_user_fk` FOREIGN KEY (`completed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `accounting_close_periods`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `accounting_close_periods` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `completed_items` smallint unsigned NOT NULL DEFAULT '0',
  `total_items` smallint unsigned NOT NULL DEFAULT '0',
  `closed_at` timestamp NULL DEFAULT NULL,
  `closed_by_user_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `acct_close_period_unique` (`tenant_id`,`period_start`),
  KEY `acct_close_period_closer_fk` (`closed_by_user_id`),
  KEY `accounting_close_periods_status_index` (`status`),
  CONSTRAINT `acct_close_period_closer_fk` FOREIGN KEY (`closed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `acct_close_period_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `accounting_compliance_tasks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `accounting_compliance_tasks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `accounting_profile_id` bigint unsigned DEFAULT NULL,
  `task_key` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `period_key` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'setup',
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `explanation` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `jurisdiction` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `obligation` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `due_at` timestamp NULL DEFAULT NULL,
  `amount_due` decimal(14,2) DEFAULT NULL,
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'needs_setup',
  `destination_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `destination_url` varchar(1024) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_url` varchar(1024) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `quickbooks_expected` tinyint(1) NOT NULL DEFAULT '0',
  `confidence` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unverified',
  `assignee_label` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `completed_at` timestamp NULL DEFAULT NULL,
  `completed_by_user_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `acct_tasks_period_unique` (`tenant_id`,`task_key`,`period_key`),
  KEY `acct_tasks_due_idx` (`tenant_id`,`status`,`due_at`),
  KEY `acct_tasks_profile_fk` (`accounting_profile_id`),
  KEY `acct_tasks_completer_fk` (`completed_by_user_id`),
  KEY `accounting_compliance_tasks_status_index` (`status`),
  CONSTRAINT `acct_tasks_completer_fk` FOREIGN KEY (`completed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `acct_tasks_profile_fk` FOREIGN KEY (`accounting_profile_id`) REFERENCES `accounting_profiles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `acct_tasks_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `accounting_debt_snapshots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `accounting_debt_snapshots` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `source_account_id` varchar(180) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_type` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `observed_on` date NOT NULL,
  `balance` decimal(14,2) NOT NULL,
  `credit_limit` decimal(14,2) DEFAULT NULL,
  `available_credit` decimal(14,2) DEFAULT NULL,
  `interest_rate` decimal(8,5) DEFAULT NULL,
  `source_metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `acct_debt_snapshot_unique` (`tenant_id`,`source_account_id`,`observed_on`),
  KEY `acct_debt_snapshot_date_idx` (`tenant_id`,`observed_on`),
  CONSTRAINT `acct_debt_snapshot_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `accounting_event_source_imports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `accounting_event_source_imports` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `source_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'spreadsheet',
  `source_filename` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `sheet_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `checksum` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `mapping_version` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'mapping_required',
  `source_metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `imported_by_user_id` bigint unsigned DEFAULT NULL,
  `imported_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `acct_event_import_unique` (`tenant_id`,`checksum`,`mapping_version`),
  KEY `acct_event_import_user_fk` (`imported_by_user_id`),
  KEY `accounting_event_source_imports_status_index` (`status`),
  CONSTRAINT `acct_event_import_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `acct_event_import_user_fk` FOREIGN KEY (`imported_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `accounting_profiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `accounting_profiles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `preset_key` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `entity_type` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `country_code` char(2) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'US',
  `state_code` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tax_year_basis` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'calendar',
  `accounting_basis` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'accrual',
  `setup_status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'needs_review',
  `configuration` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `reviewed_by_user_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `accounting_profiles_tenant_id_unique` (`tenant_id`),
  KEY `acct_profiles_reviewer_fk` (`reviewed_by_user_id`),
  KEY `accounting_profiles_setup_status_index` (`setup_status`),
  CONSTRAINT `acct_profiles_reviewer_fk` FOREIGN KEY (`reviewed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `acct_profiles_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `accounting_revenue_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `accounting_revenue_rules` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `stream_key` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_system` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `matcher_type` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `matcher_fingerprint` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `matcher_value` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `approved_by_user_id` bigint unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `acct_rev_rules_match_unique` (`tenant_id`,`source_system`,`matcher_type`,`matcher_fingerprint`),
  KEY `acct_rev_rules_stream_idx` (`tenant_id`,`stream_key`,`status`),
  KEY `acct_rev_rules_approver_fk` (`approved_by_user_id`),
  KEY `accounting_revenue_rules_status_index` (`status`),
  CONSTRAINT `acct_rev_rules_approver_fk` FOREIGN KEY (`approved_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `acct_rev_rules_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agentic_changes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agentic_changes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `slug` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `summary` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `category` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'platform',
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'shipped',
  `impact` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reference` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `changed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `agentic_changes_slug_unique` (`slug`),
  KEY `agentic_changes_changed_at_index` (`changed_at`),
  KEY `agentic_changes_status_index` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agreement_acceptances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agreement_acceptances` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agreement_id` bigint unsigned NOT NULL,
  `agreement_version_id` bigint unsigned NOT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  `accepted_by_user_id` bigint unsigned DEFAULT NULL,
  `signer_legal_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `signer_title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `signer_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `electronic_signature_value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `electronic_signature_type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'typed',
  `authorized_to_bind` tinyint(1) NOT NULL,
  `accepted_scope` tinyint(1) NOT NULL,
  `accepted_pricing` tinyint(1) NOT NULL,
  `accepted_subscription` tinyint(1) NOT NULL,
  `accepted_hourly_rate` tinyint(1) NOT NULL,
  `accepted_termination` tinyint(1) NOT NULL,
  `electronic_consent` tinyint(1) NOT NULL,
  `accepted_at` timestamp NOT NULL,
  `ip_address` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `user_agent` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `evidence_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `snapshot_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `snapshot_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `agreement_acceptances_agreement_id_agreement_version_id_unique` (`agreement_id`,`agreement_version_id`),
  UNIQUE KEY `agreement_acceptances_evidence_hash_unique` (`evidence_hash`),
  KEY `agreement_acceptances_agreement_version_id_foreign` (`agreement_version_id`),
  KEY `agreement_acceptances_accepted_by_user_id_foreign` (`accepted_by_user_id`),
  KEY `agreement_acceptances_tenant_id_accepted_at_index` (`tenant_id`,`accepted_at`),
  CONSTRAINT `agreement_acceptances_accepted_by_user_id_foreign` FOREIGN KEY (`accepted_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `agreement_acceptances_agreement_id_foreign` FOREIGN KEY (`agreement_id`) REFERENCES `agreements` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `agreement_acceptances_agreement_version_id_foreign` FOREIGN KEY (`agreement_version_id`) REFERENCES `agreement_versions` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `agreement_acceptances_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agreement_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agreement_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agreement_id` bigint unsigned NOT NULL,
  `agreement_version_id` bigint unsigned DEFAULT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  `actor_user_id` bigint unsigned DEFAULT NULL,
  `event_type` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `agreement_events_agreement_version_id_foreign` (`agreement_version_id`),
  KEY `agreement_events_actor_user_id_foreign` (`actor_user_id`),
  KEY `agreement_events_tenant_id_event_type_index` (`tenant_id`,`event_type`),
  KEY `agreement_events_agreement_id_created_at_index` (`agreement_id`,`created_at`),
  CONSTRAINT `agreement_events_actor_user_id_foreign` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `agreement_events_agreement_id_foreign` FOREIGN KEY (`agreement_id`) REFERENCES `agreements` (`id`) ON DELETE CASCADE,
  CONSTRAINT `agreement_events_agreement_version_id_foreign` FOREIGN KEY (`agreement_version_id`) REFERENCES `agreement_versions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `agreement_events_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agreement_terminations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agreement_terminations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agreement_id` bigint unsigned NOT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'requested',
  `reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `requested_at` timestamp NOT NULL,
  `effective_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `export_window_ends_at` timestamp NULL DEFAULT NULL,
  `export_requested_at` timestamp NULL DEFAULT NULL,
  `export_completed_at` timestamp NULL DEFAULT NULL,
  `export_status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'not_requested',
  `export_reference` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `requested_by_user_id` bigint unsigned DEFAULT NULL,
  `completed_by_user_id` bigint unsigned DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `agreement_terminations_agreement_id_unique` (`agreement_id`),
  KEY `agreement_terminations_requested_by_user_id_foreign` (`requested_by_user_id`),
  KEY `agreement_terminations_completed_by_user_id_foreign` (`completed_by_user_id`),
  KEY `agreement_terminations_tenant_id_status_index` (`tenant_id`,`status`),
  CONSTRAINT `agreement_terminations_agreement_id_foreign` FOREIGN KEY (`agreement_id`) REFERENCES `agreements` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `agreement_terminations_completed_by_user_id_foreign` FOREIGN KEY (`completed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `agreement_terminations_requested_by_user_id_foreign` FOREIGN KEY (`requested_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `agreement_terminations_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agreement_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agreement_versions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agreement_id` bigint unsigned NOT NULL,
  `version_number` int unsigned NOT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `rendered_content` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `content_payload` json NOT NULL,
  `scope_payload` json NOT NULL,
  `pricing_payload` json NOT NULL,
  `subscription_payload` json NOT NULL,
  `termination_payload` json NOT NULL,
  `content_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `agreement_versions_agreement_id_version_number_unique` (`agreement_id`,`version_number`),
  UNIQUE KEY `agreement_versions_agreement_id_content_hash_unique` (`agreement_id`,`content_hash`),
  KEY `agreement_versions_created_by_foreign` (`created_by`),
  CONSTRAINT `agreement_versions_agreement_id_foreign` FOREIGN KEY (`agreement_id`) REFERENCES `agreements` (`id`) ON DELETE CASCADE,
  CONSTRAINT `agreement_versions_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agreements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agreements` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `parent_agreement_id` bigint unsigned DEFAULT NULL,
  `agreement_type` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `template_key` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `current_version_id` bigint unsigned DEFAULT NULL,
  `public_token_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `public_token_encrypted` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `password_hash` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `access_expires_at` timestamp NULL DEFAULT NULL,
  `access_revoked_at` timestamp NULL DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `recipient_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email_sent_at` timestamp NULL DEFAULT NULL,
  `recipient_phone` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sms_sent_at` timestamp NULL DEFAULT NULL,
  `agreement_sms_message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `agreement_mms_image_url` varchar(2048) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `first_viewed_at` timestamp NULL DEFAULT NULL,
  `last_viewed_at` timestamp NULL DEFAULT NULL,
  `view_count` int unsigned NOT NULL DEFAULT '0',
  `effective_at` timestamp NULL DEFAULT NULL,
  `accepted_at` timestamp NULL DEFAULT NULL,
  `declined_at` timestamp NULL DEFAULT NULL,
  `terminated_at` timestamp NULL DEFAULT NULL,
  `internal_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `agreements_public_token_hash_unique` (`public_token_hash`),
  KEY `agreements_parent_agreement_id_foreign` (`parent_agreement_id`),
  KEY `agreements_created_by_foreign` (`created_by`),
  KEY `agreements_updated_by_foreign` (`updated_by`),
  KEY `agreements_tenant_id_status_index` (`tenant_id`,`status`),
  KEY `agreements_agreement_type_status_index` (`agreement_type`,`status`),
  KEY `agreements_current_version_id_index` (`current_version_id`),
  CONSTRAINT `agreements_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `agreements_current_version_id_foreign` FOREIGN KEY (`current_version_id`) REFERENCES `agreement_versions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `agreements_parent_agreement_id_foreign` FOREIGN KEY (`parent_agreement_id`) REFERENCES `agreements` (`id`) ON DELETE SET NULL,
  CONSTRAINT `agreements_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `agreements_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `automation_workflow_action_receipts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `automation_workflow_action_receipts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `automation_workflow_id` bigint unsigned NOT NULL,
  `automation_workflow_version_id` bigint unsigned NOT NULL,
  `automation_workflow_run_item_id` bigint unsigned NOT NULL,
  `step_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `component_key` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `idempotency_key` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload_hash` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'dispatching',
  `target_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `target_id` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `result` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `error_summary` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `reserved_at` timestamp NOT NULL,
  `succeeded_at` timestamp NULL DEFAULT NULL,
  `failed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `awar_idempotency_uq` (`idempotency_key`),
  KEY `awar_workflow_fk` (`automation_workflow_id`),
  KEY `awar_version_fk` (`automation_workflow_version_id`),
  KEY `awar_tenant_workflow_status_idx` (`tenant_id`,`automation_workflow_id`,`status`),
  KEY `awar_item_step_idx` (`automation_workflow_run_item_id`,`step_id`),
  CONSTRAINT `awar_item_fk` FOREIGN KEY (`automation_workflow_run_item_id`) REFERENCES `automation_workflow_run_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `awar_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `awar_version_fk` FOREIGN KEY (`automation_workflow_version_id`) REFERENCES `automation_workflow_versions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `awar_workflow_fk` FOREIGN KEY (`automation_workflow_id`) REFERENCES `automation_workflows` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `automation_workflow_audit_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `automation_workflow_audit_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `automation_workflow_id` bigint unsigned DEFAULT NULL,
  `actor_user_id` bigint unsigned DEFAULT NULL,
  `event_type` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `before_state` json DEFAULT NULL,
  `after_state` json DEFAULT NULL,
  `context` json DEFAULT NULL,
  `occurred_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `automation_workflow_audit_events_automation_workflow_id_foreign` (`automation_workflow_id`),
  KEY `automation_workflow_audit_events_actor_user_id_foreign` (`actor_user_id`),
  KEY `automation_audit_tenant_type_idx` (`tenant_id`,`event_type`,`occurred_at`),
  CONSTRAINT `automation_workflow_audit_events_actor_user_id_foreign` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `automation_workflow_audit_events_automation_workflow_id_foreign` FOREIGN KEY (`automation_workflow_id`) REFERENCES `automation_workflows` (`id`) ON DELETE SET NULL,
  CONSTRAINT `automation_workflow_audit_events_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `automation_workflow_domain_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `automation_workflow_domain_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `event_key` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_type` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject_type` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject_id` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `occurred_at` timestamp NOT NULL,
  `consumed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `awde_tenant_event_uq` (`tenant_id`,`event_key`),
  KEY `awde_tenant_consumed_type_idx` (`tenant_id`,`consumed_at`,`event_type`),
  CONSTRAINT `awde_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `automation_workflow_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `automation_workflow_links` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned DEFAULT NULL,
  `automation_workflow_id` bigint unsigned DEFAULT NULL,
  `step_key` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'action',
  `workflow_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_system` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `destination_system` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `destination_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_fingerprint` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `last_synced_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `awl_workflow_step_source_uq` (`automation_workflow_id`,`step_key`,`source_system`,`source_id`),
  KEY `automation_workflow_links_destination_index` (`destination_system`,`destination_id`),
  KEY `automation_links_tenant_workflow_idx` (`tenant_id`,`automation_workflow_id`),
  CONSTRAINT `automation_workflow_links_automation_workflow_id_foreign` FOREIGN KEY (`automation_workflow_id`) REFERENCES `automation_workflows` (`id`) ON DELETE SET NULL,
  CONSTRAINT `automation_workflow_links_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `automation_workflow_run_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `automation_workflow_run_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `automation_workflow_id` bigint unsigned NOT NULL,
  `automation_workflow_run_id` bigint unsigned NOT NULL,
  `automation_workflow_version_id` bigint unsigned NOT NULL,
  `trigger_step_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_system` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_id` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_fingerprint` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `event_key` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `context` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `execution_stack` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `current_step_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `available_at` timestamp NULL DEFAULT NULL,
  `attempt_count` smallint unsigned NOT NULL DEFAULT '0',
  `error_summary` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `started_at` timestamp NULL DEFAULT NULL,
  `finished_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `awri_workflow_event_uq` (`automation_workflow_id`,`event_key`),
  KEY `awri_version_fk` (`automation_workflow_version_id`),
  KEY `awri_tenant_status_available_idx` (`tenant_id`,`status`,`available_at`),
  KEY `awri_run_status_idx` (`automation_workflow_run_id`,`status`),
  KEY `awri_workflow_source_idx` (`automation_workflow_id`,`source_system`,`source_id`),
  CONSTRAINT `awri_run_fk` FOREIGN KEY (`automation_workflow_run_id`) REFERENCES `automation_workflow_runs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `awri_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `awri_version_fk` FOREIGN KEY (`automation_workflow_version_id`) REFERENCES `automation_workflow_versions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `awri_workflow_fk` FOREIGN KEY (`automation_workflow_id`) REFERENCES `automation_workflows` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `automation_workflow_run_steps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `automation_workflow_run_steps` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `automation_workflow_run_id` bigint unsigned NOT NULL,
  `automation_workflow_run_item_id` bigint unsigned DEFAULT NULL,
  `position` smallint unsigned NOT NULL,
  `step_key` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `parent_step_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `branch_key` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `attempt` smallint unsigned NOT NULL DEFAULT '1',
  `idempotency_key` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `provider` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `kind` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `summary` json DEFAULT NULL,
  `input_summary` json DEFAULT NULL,
  `output_summary` json DEFAULT NULL,
  `error_message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `duration_ms` int unsigned DEFAULT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `finished_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `awrs_item_idempotency_uq` (`automation_workflow_run_item_id`,`idempotency_key`),
  KEY `automation_workflow_run_steps_tenant_id_foreign` (`tenant_id`),
  KEY `automation_run_steps_position_idx` (`automation_workflow_run_id`,`position`),
  KEY `awrs_item_status_idx` (`automation_workflow_run_item_id`,`status`),
  CONSTRAINT `automation_workflow_run_steps_automation_workflow_run_id_foreign` FOREIGN KEY (`automation_workflow_run_id`) REFERENCES `automation_workflow_runs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `automation_workflow_run_steps_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `awrs_run_item_fk` FOREIGN KEY (`automation_workflow_run_item_id`) REFERENCES `automation_workflow_run_items` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `automation_workflow_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `automation_workflow_runs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `automation_workflow_id` bigint unsigned NOT NULL,
  `automation_workflow_version_id` bigint unsigned DEFAULT NULL,
  `mode` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'scheduled',
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'queued',
  `counts` json DEFAULT NULL,
  `context` json DEFAULT NULL,
  `error_summary` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `idempotency_key` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `initiated_by_user_id` bigint unsigned DEFAULT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `finished_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `automation_workflow_runs_idempotency_unique` (`automation_workflow_id`,`idempotency_key`),
  KEY `automation_workflow_runs_automation_workflow_version_id_foreign` (`automation_workflow_version_id`),
  KEY `automation_workflow_runs_initiated_by_user_id_foreign` (`initiated_by_user_id`),
  KEY `automation_workflow_runs_tenant_status_idx` (`tenant_id`,`status`,`created_at`),
  KEY `automation_workflow_runs_workflow_idx` (`automation_workflow_id`,`created_at`),
  CONSTRAINT `automation_workflow_runs_automation_workflow_id_foreign` FOREIGN KEY (`automation_workflow_id`) REFERENCES `automation_workflows` (`id`) ON DELETE CASCADE,
  CONSTRAINT `automation_workflow_runs_automation_workflow_version_id_foreign` FOREIGN KEY (`automation_workflow_version_id`) REFERENCES `automation_workflow_versions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `automation_workflow_runs_initiated_by_user_id_foreign` FOREIGN KEY (`initiated_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `automation_workflow_runs_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `automation_workflow_states`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `automation_workflow_states` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned DEFAULT NULL,
  `automation_workflow_id` bigint unsigned DEFAULT NULL,
  `workflow_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'idle',
  `cursor` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `context` json DEFAULT NULL,
  `last_started_at` timestamp NULL DEFAULT NULL,
  `last_finished_at` timestamp NULL DEFAULT NULL,
  `last_status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_error` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `last_result` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `automation_workflow_states_workflow_key_unique` (`workflow_key`),
  UNIQUE KEY `automation_states_workflow_unique` (`automation_workflow_id`),
  KEY `automation_states_tenant_workflow_idx` (`tenant_id`,`automation_workflow_id`),
  CONSTRAINT `automation_workflow_states_automation_workflow_id_foreign` FOREIGN KEY (`automation_workflow_id`) REFERENCES `automation_workflows` (`id`) ON DELETE SET NULL,
  CONSTRAINT `automation_workflow_states_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `automation_workflow_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `automation_workflow_versions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `automation_workflow_id` bigint unsigned NOT NULL,
  `version` int unsigned NOT NULL,
  `definition_hash` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `definition` json NOT NULL,
  `published_by_user_id` bigint unsigned DEFAULT NULL,
  `published_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `automation_workflow_versions_unique` (`automation_workflow_id`,`version`),
  KEY `automation_workflow_versions_published_by_user_id_foreign` (`published_by_user_id`),
  KEY `automation_workflow_versions_tenant_id_published_at_index` (`tenant_id`,`published_at`),
  CONSTRAINT `automation_workflow_versions_automation_workflow_id_foreign` FOREIGN KEY (`automation_workflow_id`) REFERENCES `automation_workflows` (`id`) ON DELETE CASCADE,
  CONSTRAINT `automation_workflow_versions_published_by_user_id_foreign` FOREIGN KEY (`published_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `automation_workflow_versions_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `automation_workflows`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `automation_workflows` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `template_key` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `draft_definition` json NOT NULL,
  `definition_schema_version` smallint unsigned NOT NULL DEFAULT '1',
  `draft_revision` bigint unsigned NOT NULL DEFAULT '1',
  `published_version_id` bigint unsigned DEFAULT NULL,
  `test_state` json DEFAULT NULL,
  `created_by_user_id` bigint unsigned DEFAULT NULL,
  `updated_by_user_id` bigint unsigned DEFAULT NULL,
  `published_at` timestamp NULL DEFAULT NULL,
  `last_run_at` timestamp NULL DEFAULT NULL,
  `next_run_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `automation_workflows_created_by_user_id_foreign` (`created_by_user_id`),
  KEY `automation_workflows_updated_by_user_id_foreign` (`updated_by_user_id`),
  KEY `automation_workflows_tenant_id_status_index` (`tenant_id`,`status`),
  KEY `automation_workflows_tenant_id_template_key_index` (`tenant_id`,`template_key`),
  KEY `automation_workflows_published_version_fk` (`published_version_id`),
  KEY `aw_tenant_status_next_run_idx` (`tenant_id`,`status`,`next_run_at`),
  CONSTRAINT `automation_workflows_created_by_user_id_foreign` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `automation_workflows_published_version_fk` FOREIGN KEY (`published_version_id`) REFERENCES `automation_workflow_versions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `automation_workflows_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `automation_workflows_updated_by_user_id_foreign` FOREIGN KEY (`updated_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `base_oils`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `base_oils` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `grams_on_hand` decimal(10,2) NOT NULL DEFAULT '0.00',
  `reorder_threshold` decimal(10,2) NOT NULL DEFAULT '0.00',
  `jug_size_grams` decimal(10,2) NOT NULL DEFAULT '2263.00',
  `supplier` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cost_per_jug` decimal(10,2) DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `base_oils_name_unique` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `birthday_message_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `birthday_message_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `customer_birthday_profile_id` bigint unsigned NOT NULL,
  `marketing_profile_id` bigint unsigned NOT NULL,
  `birthday_reward_issuance_id` bigint unsigned DEFAULT NULL,
  `event_key` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `campaign_type` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `channel` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `provider` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `provider_message_id` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `delivered_at` timestamp NULL DEFAULT NULL,
  `opened_at` timestamp NULL DEFAULT NULL,
  `clicked_at` timestamp NULL DEFAULT NULL,
  `conversion_at` timestamp NULL DEFAULT NULL,
  `utm_campaign` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `utm_source` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `birthday_message_events_event_key_unique` (`event_key`),
  KEY `birthday_message_events_customer_birthday_profile_id_foreign` (`customer_birthday_profile_id`),
  KEY `birthday_message_events_marketing_profile_id_foreign` (`marketing_profile_id`),
  KEY `birthday_message_events_birthday_reward_issuance_id_foreign` (`birthday_reward_issuance_id`),
  KEY `birthday_message_events_campaign_type_index` (`campaign_type`),
  KEY `birthday_message_events_channel_index` (`channel`),
  KEY `birthday_message_events_provider_index` (`provider`),
  KEY `birthday_message_events_provider_message_id_index` (`provider_message_id`),
  KEY `birthday_message_events_status_index` (`status`),
  KEY `birthday_message_events_sent_at_index` (`sent_at`),
  KEY `birthday_message_events_delivered_at_index` (`delivered_at`),
  KEY `birthday_message_events_opened_at_index` (`opened_at`),
  KEY `birthday_message_events_clicked_at_index` (`clicked_at`),
  KEY `birthday_message_events_conversion_at_index` (`conversion_at`),
  KEY `birthday_message_events_utm_campaign_index` (`utm_campaign`),
  KEY `birthday_message_events_utm_source_index` (`utm_source`),
  CONSTRAINT `birthday_message_events_birthday_reward_issuance_id_foreign` FOREIGN KEY (`birthday_reward_issuance_id`) REFERENCES `birthday_reward_issuances` (`id`) ON DELETE SET NULL,
  CONSTRAINT `birthday_message_events_customer_birthday_profile_id_foreign` FOREIGN KEY (`customer_birthday_profile_id`) REFERENCES `customer_birthday_profiles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `birthday_message_events_marketing_profile_id_foreign` FOREIGN KEY (`marketing_profile_id`) REFERENCES `marketing_profiles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `birthday_reward_issuances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `birthday_reward_issuances` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `customer_birthday_profile_id` bigint unsigned NOT NULL,
  `marketing_profile_id` bigint unsigned NOT NULL,
  `cycle_year` smallint unsigned NOT NULL,
  `reward_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `reward_name` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'issued',
  `points_awarded` int DEFAULT NULL,
  `candle_cash_awarded` int DEFAULT NULL,
  `reward_value` decimal(10,2) DEFAULT NULL,
  `reward_code` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `shopify_discount_id` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `shopify_store_key` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `shopify_discount_node_id` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `discount_sync_status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `discount_sync_error` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `claim_window_starts_at` timestamp NULL DEFAULT NULL,
  `claim_window_ends_at` timestamp NULL DEFAULT NULL,
  `issued_at` timestamp NULL DEFAULT NULL,
  `claimed_at` timestamp NULL DEFAULT NULL,
  `activated_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `redeemed_at` timestamp NULL DEFAULT NULL,
  `order_number` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `order_total` decimal(10,2) DEFAULT NULL,
  `attributed_revenue` decimal(10,2) DEFAULT NULL,
  `campaign_type` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `order_id` bigint unsigned DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `birthday_reward_issuance_cycle_unique` (`marketing_profile_id`,`cycle_year`,`reward_type`),
  KEY `birthday_reward_issuances_customer_birthday_profile_id_foreign` (`customer_birthday_profile_id`),
  KEY `birthday_reward_issuances_cycle_year_index` (`cycle_year`),
  KEY `birthday_reward_issuances_reward_type_index` (`reward_type`),
  KEY `birthday_reward_issuances_status_index` (`status`),
  KEY `birthday_reward_issuances_reward_code_index` (`reward_code`),
  KEY `birthday_reward_issuances_claim_window_starts_at_index` (`claim_window_starts_at`),
  KEY `birthday_reward_issuances_claim_window_ends_at_index` (`claim_window_ends_at`),
  KEY `birthday_reward_issuances_issued_at_index` (`issued_at`),
  KEY `birthday_reward_issuances_claimed_at_index` (`claimed_at`),
  KEY `birthday_reward_issuances_shopify_discount_id_index` (`shopify_discount_id`),
  KEY `birthday_reward_issuances_expires_at_index` (`expires_at`),
  KEY `birthday_reward_issuances_redeemed_at_index` (`redeemed_at`),
  KEY `birthday_reward_issuances_order_id_index` (`order_id`),
  KEY `birthday_reward_issuances_order_number_index` (`order_number`),
  KEY `birthday_reward_issuances_campaign_type_index` (`campaign_type`),
  KEY `birthday_reward_issuances_shopify_store_key_index` (`shopify_store_key`),
  KEY `birthday_reward_issuances_shopify_discount_node_id_index` (`shopify_discount_node_id`),
  KEY `birthday_reward_issuances_discount_sync_status_index` (`discount_sync_status`),
  KEY `birthday_reward_issuances_activated_at_index` (`activated_at`),
  CONSTRAINT `birthday_reward_issuances_customer_birthday_profile_id_foreign` FOREIGN KEY (`customer_birthday_profile_id`) REFERENCES `customer_birthday_profiles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `birthday_reward_issuances_marketing_profile_id_foreign` FOREIGN KEY (`marketing_profile_id`) REFERENCES `marketing_profiles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `blend_components`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `blend_components` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `blend_id` bigint unsigned NOT NULL,
  `component_type` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'oil',
  `base_oil_id` bigint unsigned DEFAULT NULL,
  `blend_template_id` bigint unsigned DEFAULT NULL,
  `ratio_weight` int unsigned NOT NULL,
  `percentage` decimal(8,4) DEFAULT NULL,
  `sort_order` smallint unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `blend_components_blend_id_base_oil_id_unique` (`blend_id`,`base_oil_id`),
  KEY `blend_components_base_oil_id_foreign` (`base_oil_id`),
  KEY `blend_components_blend_template_id_foreign` (`blend_template_id`),
  CONSTRAINT `blend_components_base_oil_id_foreign` FOREIGN KEY (`base_oil_id`) REFERENCES `base_oils` (`id`) ON DELETE CASCADE,
  CONSTRAINT `blend_components_blend_id_foreign` FOREIGN KEY (`blend_id`) REFERENCES `blends` (`id`) ON DELETE CASCADE,
  CONSTRAINT `blend_components_blend_template_id_foreign` FOREIGN KEY (`blend_template_id`) REFERENCES `blends` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `blends`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `blends` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_blend` tinyint(1) NOT NULL DEFAULT '1',
  `lifecycle_status` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `blends_name_unique` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache` (
  `key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` int NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `owner` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` int NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `candle_cash_balances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `candle_cash_balances` (
  `marketing_profile_id` bigint unsigned NOT NULL,
  `balance` decimal(12,3) NOT NULL DEFAULT '0.000',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`marketing_profile_id`),
  CONSTRAINT `candle_cash_balances_marketing_profile_id_foreign` FOREIGN KEY (`marketing_profile_id`) REFERENCES `marketing_profiles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `candle_cash_legacy_compatibility_usages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `candle_cash_legacy_compatibility_usages` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `path` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `operation` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `context` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `hits` bigint unsigned NOT NULL DEFAULT '0',
  `first_seen_at` timestamp NULL DEFAULT NULL,
  `last_seen_at` timestamp NULL DEFAULT NULL,
  `meta` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cc_legacy_compat_usage_unique` (`path`,`operation`,`context`),
  KEY `cc_legacy_compat_usage_operation_idx` (`operation`,`last_seen_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `candle_cash_redemptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `candle_cash_redemptions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `marketing_profile_id` bigint unsigned NOT NULL,
  `reward_id` bigint unsigned NOT NULL,
  `points_spent` int unsigned NOT NULL,
  `candle_cash_spent` int unsigned NOT NULL DEFAULT '0',
  `platform` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `redeemed_channel` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `external_order_source` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `external_order_id` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `redemption_context` json DEFAULT NULL,
  `reconciliation_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `redeemed_by` bigint unsigned DEFAULT NULL,
  `redemption_code` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'issued',
  `issued_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `redeemed_at` timestamp NULL DEFAULT NULL,
  `canceled_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `candle_cash_redemptions_redemption_code_unique` (`redemption_code`),
  KEY `candle_cash_redemptions_marketing_profile_id_foreign` (`marketing_profile_id`),
  KEY `candle_cash_redemptions_reward_id_foreign` (`reward_id`),
  KEY `candle_cash_redemptions_platform_index` (`platform`),
  KEY `candle_cash_redemptions_redeemed_at_index` (`redeemed_at`),
  KEY `candle_cash_redemptions_redeemed_by_foreign` (`redeemed_by`),
  KEY `candle_cash_redemptions_status_index` (`status`),
  KEY `candle_cash_redemptions_issued_at_index` (`issued_at`),
  KEY `candle_cash_redemptions_expires_at_index` (`expires_at`),
  KEY `candle_cash_redemptions_canceled_at_index` (`canceled_at`),
  KEY `candle_cash_redemptions_redeemed_channel_index` (`redeemed_channel`),
  KEY `candle_cash_redemptions_external_order_source_index` (`external_order_source`),
  KEY `candle_cash_redemptions_external_order_id_index` (`external_order_id`),
  CONSTRAINT `candle_cash_redemptions_marketing_profile_id_foreign` FOREIGN KEY (`marketing_profile_id`) REFERENCES `marketing_profiles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `candle_cash_redemptions_redeemed_by_foreign` FOREIGN KEY (`redeemed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `candle_cash_redemptions_reward_id_foreign` FOREIGN KEY (`reward_id`) REFERENCES `candle_cash_rewards` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `candle_cash_referrals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `candle_cash_referrals` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `referrer_marketing_profile_id` bigint unsigned NOT NULL,
  `referred_marketing_profile_id` bigint unsigned DEFAULT NULL,
  `referral_code` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `referred_identity_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `referred_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `normalized_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `referred_phone` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `normalized_phone` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'captured',
  `qualifying_order_source` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `qualifying_order_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `qualifying_order_number` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `qualifying_order_total` decimal(10,2) DEFAULT NULL,
  `referrer_completion_id` bigint unsigned DEFAULT NULL,
  `referred_completion_id` bigint unsigned DEFAULT NULL,
  `referrer_transaction_id` bigint unsigned DEFAULT NULL,
  `referred_transaction_id` bigint unsigned DEFAULT NULL,
  `referrer_reward_status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `referred_reward_status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `first_seen_at` timestamp NULL DEFAULT NULL,
  `qualified_at` timestamp NULL DEFAULT NULL,
  `rewarded_at` timestamp NULL DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ccr_code_identity_unique` (`referral_code`,`referred_identity_key`),
  KEY `candle_cash_referrals_referrer_marketing_profile_id_foreign` (`referrer_marketing_profile_id`),
  KEY `candle_cash_referrals_referred_marketing_profile_id_foreign` (`referred_marketing_profile_id`),
  KEY `candle_cash_referrals_referrer_completion_id_foreign` (`referrer_completion_id`),
  KEY `candle_cash_referrals_referred_completion_id_foreign` (`referred_completion_id`),
  KEY `candle_cash_referrals_referrer_transaction_id_foreign` (`referrer_transaction_id`),
  KEY `candle_cash_referrals_referred_transaction_id_foreign` (`referred_transaction_id`),
  KEY `candle_cash_referrals_referral_code_index` (`referral_code`),
  KEY `candle_cash_referrals_referred_email_index` (`referred_email`),
  KEY `candle_cash_referrals_normalized_email_index` (`normalized_email`),
  KEY `candle_cash_referrals_referred_phone_index` (`referred_phone`),
  KEY `candle_cash_referrals_normalized_phone_index` (`normalized_phone`),
  KEY `candle_cash_referrals_status_index` (`status`),
  KEY `candle_cash_referrals_qualifying_order_source_index` (`qualifying_order_source`),
  KEY `candle_cash_referrals_qualifying_order_id_index` (`qualifying_order_id`),
  KEY `candle_cash_referrals_referrer_reward_status_index` (`referrer_reward_status`),
  KEY `candle_cash_referrals_referred_reward_status_index` (`referred_reward_status`),
  KEY `candle_cash_referrals_first_seen_at_index` (`first_seen_at`),
  KEY `candle_cash_referrals_qualified_at_index` (`qualified_at`),
  KEY `candle_cash_referrals_rewarded_at_index` (`rewarded_at`),
  CONSTRAINT `candle_cash_referrals_referred_completion_id_foreign` FOREIGN KEY (`referred_completion_id`) REFERENCES `candle_cash_task_completions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `candle_cash_referrals_referred_marketing_profile_id_foreign` FOREIGN KEY (`referred_marketing_profile_id`) REFERENCES `marketing_profiles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `candle_cash_referrals_referred_transaction_id_foreign` FOREIGN KEY (`referred_transaction_id`) REFERENCES `candle_cash_transactions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `candle_cash_referrals_referrer_completion_id_foreign` FOREIGN KEY (`referrer_completion_id`) REFERENCES `candle_cash_task_completions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `candle_cash_referrals_referrer_marketing_profile_id_foreign` FOREIGN KEY (`referrer_marketing_profile_id`) REFERENCES `marketing_profiles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `candle_cash_referrals_referrer_transaction_id_foreign` FOREIGN KEY (`referrer_transaction_id`) REFERENCES `candle_cash_transactions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `candle_cash_rewards`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `candle_cash_rewards` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `points_cost` int unsigned NOT NULL,
  `candle_cash_cost` int unsigned NOT NULL DEFAULT '0',
  `reward_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `reward_value` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `candle_cash_rewards_reward_type_index` (`reward_type`),
  KEY `candle_cash_rewards_is_active_index` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `candle_cash_task_completions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `candle_cash_task_completions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `candle_cash_task_id` bigint unsigned NOT NULL,
  `marketing_profile_id` bigint unsigned NOT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `completion_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `request_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reward_amount` decimal(8,2) NOT NULL DEFAULT '0.00',
  `reward_points` int NOT NULL DEFAULT '0',
  `reward_candle_cash` int NOT NULL DEFAULT '0',
  `source_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `proof_url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `proof_text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `submission_payload` json DEFAULT NULL,
  `blocked_reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `review_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `approved_by` bigint unsigned DEFAULT NULL,
  `candle_cash_transaction_id` bigint unsigned DEFAULT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `awarded_at` timestamp NULL DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `candle_cash_task_completions_completion_key_unique` (`completion_key`),
  KEY `candle_cash_task_completions_marketing_profile_id_foreign` (`marketing_profile_id`),
  KEY `candle_cash_task_completions_approved_by_foreign` (`approved_by`),
  KEY `candle_cash_task_completions_candle_cash_transaction_id_foreign` (`candle_cash_transaction_id`),
  KEY `cctc_task_profile_status_idx` (`candle_cash_task_id`,`marketing_profile_id`,`status`),
  KEY `candle_cash_task_completions_status_index` (`status`),
  KEY `candle_cash_task_completions_request_key_index` (`request_key`),
  KEY `candle_cash_task_completions_source_type_index` (`source_type`),
  KEY `candle_cash_task_completions_source_id_index` (`source_id`),
  KEY `candle_cash_task_completions_blocked_reason_index` (`blocked_reason`),
  KEY `candle_cash_task_completions_started_at_index` (`started_at`),
  KEY `candle_cash_task_completions_submitted_at_index` (`submitted_at`),
  KEY `candle_cash_task_completions_reviewed_at_index` (`reviewed_at`),
  KEY `candle_cash_task_completions_awarded_at_index` (`awarded_at`),
  CONSTRAINT `candle_cash_task_completions_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `candle_cash_task_completions_candle_cash_task_id_foreign` FOREIGN KEY (`candle_cash_task_id`) REFERENCES `candle_cash_tasks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `candle_cash_task_completions_candle_cash_transaction_id_foreign` FOREIGN KEY (`candle_cash_transaction_id`) REFERENCES `candle_cash_transactions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `candle_cash_task_completions_marketing_profile_id_foreign` FOREIGN KEY (`marketing_profile_id`) REFERENCES `marketing_profiles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `candle_cash_task_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `candle_cash_task_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `candle_cash_task_id` bigint unsigned NOT NULL,
  `marketing_profile_id` bigint unsigned DEFAULT NULL,
  `candle_cash_task_completion_id` bigint unsigned DEFAULT NULL,
  `verification_mode` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_event_key` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'received',
  `reward_awarded` tinyint(1) NOT NULL DEFAULT '0',
  `blocked_reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `duplicate_hits` int unsigned NOT NULL DEFAULT '0',
  `duplicate_last_seen_at` timestamp NULL DEFAULT NULL,
  `occurred_at` timestamp NULL DEFAULT NULL,
  `processed_at` timestamp NULL DEFAULT NULL,
  `awarded_at` timestamp NULL DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ccte_task_source_event_unique` (`candle_cash_task_id`,`source_event_key`),
  KEY `candle_cash_task_events_candle_cash_task_completion_id_foreign` (`candle_cash_task_completion_id`),
  KEY `ccte_profile_status_idx` (`marketing_profile_id`,`status`),
  KEY `candle_cash_task_events_verification_mode_index` (`verification_mode`),
  KEY `candle_cash_task_events_source_type_index` (`source_type`),
  KEY `candle_cash_task_events_source_id_index` (`source_id`),
  KEY `candle_cash_task_events_status_index` (`status`),
  KEY `candle_cash_task_events_reward_awarded_index` (`reward_awarded`),
  KEY `candle_cash_task_events_blocked_reason_index` (`blocked_reason`),
  KEY `candle_cash_task_events_duplicate_last_seen_at_index` (`duplicate_last_seen_at`),
  KEY `candle_cash_task_events_occurred_at_index` (`occurred_at`),
  KEY `candle_cash_task_events_processed_at_index` (`processed_at`),
  KEY `candle_cash_task_events_awarded_at_index` (`awarded_at`),
  CONSTRAINT `candle_cash_task_events_candle_cash_task_completion_id_foreign` FOREIGN KEY (`candle_cash_task_completion_id`) REFERENCES `candle_cash_task_completions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `candle_cash_task_events_candle_cash_task_id_foreign` FOREIGN KEY (`candle_cash_task_id`) REFERENCES `candle_cash_tasks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `candle_cash_task_events_marketing_profile_id_foreign` FOREIGN KEY (`marketing_profile_id`) REFERENCES `marketing_profiles` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `candle_cash_tasks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `candle_cash_tasks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `handle` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `reward_amount` decimal(8,2) NOT NULL DEFAULT '0.00',
  `enabled` tinyint(1) NOT NULL DEFAULT '1',
  `display_order` int unsigned NOT NULL DEFAULT '0',
  `task_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `verification_mode` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual_review_fallback',
  `auto_award` tinyint(1) NOT NULL DEFAULT '0',
  `campaign_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `external_object_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `verification_window_hours` int unsigned DEFAULT NULL,
  `matching_rules` json DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `action_url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `button_text` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `completion_rule` json DEFAULT NULL,
  `max_completions_per_customer` int unsigned NOT NULL DEFAULT '1',
  `requires_manual_approval` tinyint(1) NOT NULL DEFAULT '0',
  `requires_customer_submission` tinyint(1) NOT NULL DEFAULT '0',
  `icon` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `eligibility_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'everyone',
  `required_customer_tags` json DEFAULT NULL,
  `required_membership_status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `visible_to_noneligible_customers` tinyint(1) NOT NULL DEFAULT '0',
  `locked_message` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `locked_cta_text` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `locked_cta_url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `admin_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `archived_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `candle_cash_tasks_handle_unique` (`handle`),
  KEY `candle_cash_tasks_enabled_index` (`enabled`),
  KEY `candle_cash_tasks_display_order_index` (`display_order`),
  KEY `candle_cash_tasks_task_type_index` (`task_type`),
  KEY `candle_cash_tasks_requires_manual_approval_index` (`requires_manual_approval`),
  KEY `candle_cash_tasks_requires_customer_submission_index` (`requires_customer_submission`),
  KEY `candle_cash_tasks_start_date_index` (`start_date`),
  KEY `candle_cash_tasks_end_date_index` (`end_date`),
  KEY `candle_cash_tasks_eligibility_type_index` (`eligibility_type`),
  KEY `candle_cash_tasks_required_membership_status_index` (`required_membership_status`),
  KEY `candle_cash_tasks_archived_at_index` (`archived_at`),
  KEY `candle_cash_tasks_verification_mode_index` (`verification_mode`),
  KEY `candle_cash_tasks_auto_award_index` (`auto_award`),
  KEY `candle_cash_tasks_campaign_key_index` (`campaign_key`),
  KEY `candle_cash_tasks_external_object_id_index` (`external_object_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `candle_cash_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `candle_cash_transactions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `marketing_profile_id` bigint unsigned NOT NULL,
  `type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `points` int NOT NULL,
  `legacy_points_origin` tinyint(1) NOT NULL DEFAULT '0',
  `legacy_points_value` int DEFAULT NULL,
  `candle_cash_delta` decimal(12,3) NOT NULL DEFAULT '0.000',
  `source` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `gift_intent` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gift_origin` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notified_via` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notification_status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `campaign_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `candle_cash_transactions_marketing_profile_id_foreign` (`marketing_profile_id`),
  KEY `candle_cash_transactions_type_index` (`type`),
  KEY `candle_cash_transactions_source_index` (`source`),
  KEY `candle_cash_transactions_source_id_index` (`source_id`),
  CONSTRAINT `candle_cash_transactions_marketing_profile_id_foreign` FOREIGN KEY (`marketing_profile_id`) REFERENCES `marketing_profiles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `candle_club_scents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `candle_club_scents` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `month` smallint unsigned NOT NULL,
  `year` smallint unsigned NOT NULL,
  `scent_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `candle_club_scents_month_year_unique` (`month`,`year`),
  KEY `candle_club_scents_scent_id_foreign` (`scent_id`),
  CONSTRAINT `candle_club_scents_scent_id_foreign` FOREIGN KEY (`scent_id`) REFERENCES `scents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `catalog_item_costs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `catalog_item_costs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `shopify_store_key` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `shopify_product_id` bigint unsigned DEFAULT NULL,
  `shopify_variant_id` bigint unsigned DEFAULT NULL,
  `sku` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `scent_id` bigint unsigned DEFAULT NULL,
  `size_id` bigint unsigned DEFAULT NULL,
  `cost_amount` decimal(10,2) NOT NULL,
  `currency_code` varchar(8) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'USD',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `effective_at` timestamp NULL DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `catalog_item_costs_size_id_foreign` (`size_id`),
  KEY `catalog_costs_store_variant_idx` (`shopify_store_key`,`shopify_variant_id`),
  KEY `catalog_costs_store_product_idx` (`shopify_store_key`,`shopify_product_id`),
  KEY `catalog_costs_store_sku_idx` (`shopify_store_key`,`sku`),
  KEY `catalog_costs_scent_size_idx` (`scent_id`,`size_id`),
  KEY `catalog_item_costs_shopify_store_key_index` (`shopify_store_key`),
  KEY `catalog_item_costs_shopify_product_id_index` (`shopify_product_id`),
  KEY `catalog_item_costs_shopify_variant_id_index` (`shopify_variant_id`),
  KEY `catalog_item_costs_sku_index` (`sku`),
  KEY `catalog_item_costs_is_active_index` (`is_active`),
  KEY `catalog_item_costs_effective_at_index` (`effective_at`),
  CONSTRAINT `catalog_item_costs_scent_id_foreign` FOREIGN KEY (`scent_id`) REFERENCES `scents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `catalog_item_costs_size_id_foreign` FOREIGN KEY (`size_id`) REFERENCES `sizes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `class_enrollments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `class_enrollments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `scheduled_class_id` bigint unsigned NOT NULL,
  `marketing_profile_id` bigint unsigned DEFAULT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `normalized_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `normalized_phone` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `seats` int unsigned NOT NULL DEFAULT '1',
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'confirmed',
  `email_reminders_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `sms_reminders_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `source` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'public_signup',
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `class_enrollments_class_email_unique` (`tenant_id`,`scheduled_class_id`,`normalized_email`),
  KEY `class_enrollments_scheduled_class_id_foreign` (`scheduled_class_id`),
  KEY `class_enrollments_marketing_profile_id_foreign` (`marketing_profile_id`),
  KEY `class_enrollments_tenant_class_status_idx` (`tenant_id`,`scheduled_class_id`,`status`),
  KEY `class_enrollments_tenant_profile_idx` (`tenant_id`,`marketing_profile_id`),
  KEY `class_enrollments_status_index` (`status`),
  CONSTRAINT `class_enrollments_marketing_profile_id_foreign` FOREIGN KEY (`marketing_profile_id`) REFERENCES `marketing_profiles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `class_enrollments_scheduled_class_id_foreign` FOREIGN KEY (`scheduled_class_id`) REFERENCES `scheduled_classes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `class_enrollments_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `class_reminders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `class_reminders` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `class_enrollment_id` bigint unsigned NOT NULL,
  `created_by_user_id` bigint unsigned DEFAULT NULL,
  `channel` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `scheduled_for` timestamp NOT NULL,
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'scheduled',
  `message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `sent_at` timestamp NULL DEFAULT NULL,
  `provider_metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `class_reminders_class_enrollment_id_foreign` (`class_enrollment_id`),
  KEY `class_reminders_created_by_user_id_foreign` (`created_by_user_id`),
  KEY `class_reminders_tenant_status_schedule_idx` (`tenant_id`,`status`,`scheduled_for`),
  KEY `class_reminders_scheduled_for_index` (`scheduled_for`),
  KEY `class_reminders_status_index` (`status`),
  CONSTRAINT `class_reminders_class_enrollment_id_foreign` FOREIGN KEY (`class_enrollment_id`) REFERENCES `class_enrollments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `class_reminders_created_by_user_id_foreign` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `class_reminders_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `class_scheduling_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `class_scheduling_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `public_signup_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `timezone` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'America/New_York',
  `public_heading` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Upcoming classes',
  `public_intro` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `contact_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `logo_url` varchar(2048) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `hero_image_url` varchar(2048) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `brand_color` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#42654a',
  `default_reminder_offsets` json DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `class_scheduling_settings_tenant_id_unique` (`tenant_id`),
  CONSTRAINT `class_scheduling_settings_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_project_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_project_links` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `client_project_id` bigint unsigned NOT NULL,
  `label` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `client_project_links_client_project_id_foreign` (`client_project_id`),
  KEY `client_project_links_scope_sort_idx` (`tenant_id`,`client_project_id`,`sort_order`),
  CONSTRAINT `client_project_links_client_project_id_foreign` FOREIGN KEY (`client_project_id`) REFERENCES `client_projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `client_project_links_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_project_milestones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_project_milestones` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `client_project_id` bigint unsigned NOT NULL,
  `client_project_phase_id` bigint unsigned DEFAULT NULL,
  `title` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `summary` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'upcoming',
  `starts_on` date DEFAULT NULL,
  `due_on` date DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `client_project_milestones_client_project_id_foreign` (`client_project_id`),
  KEY `client_project_milestones_client_project_phase_id_foreign` (`client_project_phase_id`),
  KEY `client_project_milestones_scope_due_idx` (`tenant_id`,`client_project_id`,`due_on`),
  CONSTRAINT `client_project_milestones_client_project_id_foreign` FOREIGN KEY (`client_project_id`) REFERENCES `client_projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `client_project_milestones_client_project_phase_id_foreign` FOREIGN KEY (`client_project_phase_id`) REFERENCES `client_project_phases` (`id`) ON DELETE SET NULL,
  CONSTRAINT `client_project_milestones_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_project_phases`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_project_phases` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `client_project_id` bigint unsigned NOT NULL,
  `name` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `summary` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'not_started',
  `starts_on` date DEFAULT NULL,
  `due_on` date DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `percent_complete` tinyint unsigned NOT NULL DEFAULT '0',
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `client_project_phases_client_project_id_foreign` (`client_project_id`),
  KEY `client_project_phases_scope_sort_idx` (`tenant_id`,`client_project_id`,`sort_order`),
  CONSTRAINT `client_project_phases_client_project_id_foreign` FOREIGN KEY (`client_project_id`) REFERENCES `client_projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `client_project_phases_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_project_ticket_comments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_project_ticket_comments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `client_project_ticket_id` bigint unsigned NOT NULL,
  `author_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `body` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `public_visible` tinyint(1) NOT NULL DEFAULT '1',
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `client_project_ticket_comments_client_project_ticket_id_foreign` (`client_project_ticket_id`),
  KEY `cp_ticket_comments_public_idx` (`tenant_id`,`client_project_ticket_id`,`public_visible`),
  CONSTRAINT `client_project_ticket_comments_client_project_ticket_id_foreign` FOREIGN KEY (`client_project_ticket_id`) REFERENCES `client_project_tickets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `client_project_ticket_comments_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_project_ticket_references`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_project_ticket_references` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `client_project_ticket_id` bigint unsigned NOT NULL,
  `label` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reference_type` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'link',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `cp_ticket_refs_ticket_fk` (`client_project_ticket_id`),
  KEY `cp_ticket_refs_scope_sort_idx` (`tenant_id`,`client_project_ticket_id`,`sort_order`),
  CONSTRAINT `client_project_ticket_references_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cp_ticket_refs_ticket_fk` FOREIGN KEY (`client_project_ticket_id`) REFERENCES `client_project_tickets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_project_ticket_tasks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_project_ticket_tasks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `client_project_ticket_id` bigint unsigned NOT NULL,
  `client_project_phase_id` bigint unsigned DEFAULT NULL,
  `title` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `details` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `owner_type` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'evergrove',
  `status` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `due_on` date DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `client_project_ticket_tasks_client_project_ticket_id_foreign` (`client_project_ticket_id`),
  KEY `client_project_ticket_tasks_client_project_phase_id_foreign` (`client_project_phase_id`),
  KEY `cp_ticket_tasks_scope_sort_idx` (`tenant_id`,`client_project_ticket_id`,`sort_order`),
  CONSTRAINT `client_project_ticket_tasks_client_project_phase_id_foreign` FOREIGN KEY (`client_project_phase_id`) REFERENCES `client_project_phases` (`id`) ON DELETE SET NULL,
  CONSTRAINT `client_project_ticket_tasks_client_project_ticket_id_foreign` FOREIGN KEY (`client_project_ticket_id`) REFERENCES `client_project_tickets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `client_project_ticket_tasks_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_project_ticket_votes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_project_ticket_votes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `client_project_ticket_id` bigint unsigned NOT NULL,
  `voter_hash` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `ip_hash` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent_hash` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cp_ticket_votes_ticket_voter_unique` (`client_project_ticket_id`,`voter_hash`),
  KEY `cp_ticket_votes_scope_idx` (`tenant_id`,`client_project_ticket_id`),
  CONSTRAINT `client_project_ticket_votes_client_project_ticket_id_foreign` FOREIGN KEY (`client_project_ticket_id`) REFERENCES `client_project_tickets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `client_project_ticket_votes_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_project_tickets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_project_tickets` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `client_project_id` bigint unsigned NOT NULL,
  `client_project_phase_id` bigint unsigned DEFAULT NULL,
  `client_project_milestone_id` bigint unsigned DEFAULT NULL,
  `custom_module_request_id` bigint unsigned DEFAULT NULL,
  `requested_by_user_id` bigint unsigned DEFAULT NULL,
  `reviewed_by_user_id` bigint unsigned DEFAULT NULL,
  `type` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'feature',
  `title` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `problem_summary` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `desired_outcome` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `scope_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `urgency` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'normal',
  `priority` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'normal',
  `status` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'new',
  `customer_visible` tinyint(1) NOT NULL DEFAULT '1',
  `landlord_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `client_project_tickets_client_project_id_foreign` (`client_project_id`),
  KEY `client_project_tickets_client_project_phase_id_foreign` (`client_project_phase_id`),
  KEY `client_project_tickets_client_project_milestone_id_foreign` (`client_project_milestone_id`),
  KEY `client_project_tickets_custom_module_request_id_foreign` (`custom_module_request_id`),
  KEY `client_project_tickets_requested_by_user_id_foreign` (`requested_by_user_id`),
  KEY `client_project_tickets_reviewed_by_user_id_foreign` (`reviewed_by_user_id`),
  KEY `cp_tickets_scope_status_idx` (`tenant_id`,`client_project_id`,`status`),
  KEY `cp_tickets_status_priority_idx` (`tenant_id`,`status`,`priority`),
  CONSTRAINT `client_project_tickets_client_project_id_foreign` FOREIGN KEY (`client_project_id`) REFERENCES `client_projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `client_project_tickets_client_project_milestone_id_foreign` FOREIGN KEY (`client_project_milestone_id`) REFERENCES `client_project_milestones` (`id`) ON DELETE SET NULL,
  CONSTRAINT `client_project_tickets_client_project_phase_id_foreign` FOREIGN KEY (`client_project_phase_id`) REFERENCES `client_project_phases` (`id`) ON DELETE SET NULL,
  CONSTRAINT `client_project_tickets_custom_module_request_id_foreign` FOREIGN KEY (`custom_module_request_id`) REFERENCES `custom_module_requests` (`id`) ON DELETE SET NULL,
  CONSTRAINT `client_project_tickets_requested_by_user_id_foreign` FOREIGN KEY (`requested_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `client_project_tickets_reviewed_by_user_id_foreign` FOREIGN KEY (`reviewed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `client_project_tickets_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_project_updates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_project_updates` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `client_project_id` bigint unsigned NOT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `title` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `body` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `visibility` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'client',
  `published_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `client_project_updates_client_project_id_foreign` (`client_project_id`),
  KEY `client_project_updates_created_by_foreign` (`created_by`),
  KEY `client_project_updates_scope_published_idx` (`tenant_id`,`client_project_id`,`published_at`),
  CONSTRAINT `client_project_updates_client_project_id_foreign` FOREIGN KEY (`client_project_id`) REFERENCES `client_projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `client_project_updates_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `client_project_updates_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_projects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_projects` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `title` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `summary` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'planning',
  `health` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'on_track',
  `starts_on` date DEFAULT NULL,
  `due_on` date DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `client_projects_tenant_status_sort_idx` (`tenant_id`,`status`,`sort_order`),
  KEY `client_projects_tenant_due_idx` (`tenant_id`,`due_on`),
  CONSTRAINT `client_projects_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `commerce_external_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `commerce_external_records` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `commerce_source_id` bigint unsigned NOT NULL,
  `resource_type` varchar(48) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `external_id` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `external_parent_id` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fingerprint` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_updated_at` timestamp NULL DEFAULT NULL,
  `snapshot` json DEFAULT NULL,
  `imported_at` timestamp NULL DEFAULT NULL,
  `archived_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `commerce_external_records_source_resource_external_uq` (`commerce_source_id`,`resource_type`,`external_id`),
  KEY `commerce_external_records_tenant_resource_imported_idx` (`tenant_id`,`resource_type`,`imported_at`),
  CONSTRAINT `commerce_external_records_commerce_source_id_foreign` FOREIGN KEY (`commerce_source_id`) REFERENCES `commerce_sources` (`id`) ON DELETE CASCADE,
  CONSTRAINT `commerce_external_records_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `commerce_import_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `commerce_import_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `commerce_import_run_id` bigint unsigned NOT NULL,
  `event_type` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'recorded',
  `message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `context` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `commerce_import_events_commerce_import_run_id_foreign` (`commerce_import_run_id`),
  KEY `commerce_import_events_tenant_run_created_idx` (`tenant_id`,`commerce_import_run_id`,`created_at`),
  CONSTRAINT `commerce_import_events_commerce_import_run_id_foreign` FOREIGN KEY (`commerce_import_run_id`) REFERENCES `commerce_import_runs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `commerce_import_events_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `commerce_import_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `commerce_import_runs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `commerce_source_id` bigint unsigned NOT NULL,
  `initiated_by_user_id` bigint unsigned DEFAULT NULL,
  `mode` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'dry_run',
  `status` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'queued',
  `requested_resources` json DEFAULT NULL,
  `counts` json DEFAULT NULL,
  `report` json DEFAULT NULL,
  `cursor` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `started_at` timestamp NULL DEFAULT NULL,
  `paused_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `commerce_import_runs_commerce_source_id_foreign` (`commerce_source_id`),
  KEY `commerce_import_runs_initiated_by_user_id_foreign` (`initiated_by_user_id`),
  KEY `commerce_import_runs_tenant_source_created_idx` (`tenant_id`,`commerce_source_id`,`created_at`),
  KEY `commerce_import_runs_status_index` (`status`),
  CONSTRAINT `commerce_import_runs_commerce_source_id_foreign` FOREIGN KEY (`commerce_source_id`) REFERENCES `commerce_sources` (`id`) ON DELETE CASCADE,
  CONSTRAINT `commerce_import_runs_initiated_by_user_id_foreign` FOREIGN KEY (`initiated_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `commerce_import_runs_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `commerce_sources`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `commerce_sources` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `integration_connection_id` bigint unsigned DEFAULT NULL,
  `provider` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `external_account_id` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `external_account_label` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mode` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'connected_operations',
  `status` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `capabilities` json DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `last_imported_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `commerce_sources_tenant_provider_account_uq` (`tenant_id`,`provider`,`external_account_id`),
  KEY `commerce_sources_integration_connection_id_foreign` (`integration_connection_id`),
  KEY `commerce_sources_status_index` (`status`),
  CONSTRAINT `commerce_sources_integration_connection_id_foreign` FOREIGN KEY (`integration_connection_id`) REFERENCES `integration_connections` (`id`) ON DELETE SET NULL,
  CONSTRAINT `commerce_sources_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `custom_module_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `custom_module_requests` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `requested_by_user_id` bigint unsigned DEFAULT NULL,
  `related_module_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `problem_summary` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `current_workaround` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `desired_outcome` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `tools_involved` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `users_impacted` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `frequency` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `urgency` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `budget_range` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reusable_module_interest` tinyint(1) NOT NULL DEFAULT '0',
  `mobile_relevance` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'new',
  `landlord_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `next_action` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `reviewed_by_user_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `custom_module_requests_requested_by_user_id_foreign` (`requested_by_user_id`),
  KEY `custom_module_requests_reviewed_by_user_id_foreign` (`reviewed_by_user_id`),
  KEY `custom_module_requests_tenant_id_status_index` (`tenant_id`,`status`),
  KEY `custom_module_requests_mobile_relevance_index` (`mobile_relevance`),
  KEY `custom_module_requests_reusable_module_interest_index` (`reusable_module_interest`),
  KEY `custom_module_requests_related_module_key_index` (`related_module_key`),
  KEY `custom_module_requests_status_index` (`status`),
  CONSTRAINT `custom_module_requests_requested_by_user_id_foreign` FOREIGN KEY (`requested_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `custom_module_requests_reviewed_by_user_id_foreign` FOREIGN KEY (`reviewed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `custom_module_requests_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `customer_access_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `customer_access_requests` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `intent` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'production',
  `application_kind` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'platform_access',
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `name` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `company` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `requested_tenant_slug` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `metadata` json DEFAULT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `tenant_id` bigint unsigned DEFAULT NULL,
  `approved_by` bigint unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `decision_note` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `rejected_by` bigint unsigned DEFAULT NULL,
  `rejected_at` timestamp NULL DEFAULT NULL,
  `rejection_note` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `activation_email_sent_at` timestamp NULL DEFAULT NULL,
  `activation_email_last_attempted_at` timestamp NULL DEFAULT NULL,
  `activation_email_last_attempt_status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `activation_email_last_sent_at` timestamp NULL DEFAULT NULL,
  `activation_email_resend_count` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `customer_access_requests_email_index` (`email`),
  KEY `customer_access_requests_requested_tenant_slug_index` (`requested_tenant_slug`),
  KEY `customer_access_requests_user_id_index` (`user_id`),
  KEY `customer_access_requests_tenant_id_index` (`tenant_id`),
  KEY `customer_access_requests_approved_by_index` (`approved_by`),
  KEY `customer_access_requests_approved_at_index` (`approved_at`),
  KEY `car_rejected_by_idx` (`rejected_by`),
  KEY `car_rejected_at_idx` (`rejected_at`),
  KEY `car_act_email_sent_idx` (`activation_email_sent_at`),
  KEY `car_act_email_attempt_idx` (`activation_email_last_attempted_at`),
  KEY `car_act_email_last_sent_idx` (`activation_email_last_sent_at`),
  KEY `customer_access_requests_application_kind_index` (`application_kind`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `customer_birthday_audits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `customer_birthday_audits` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `customer_birthday_profile_id` bigint unsigned NOT NULL,
  `marketing_profile_id` bigint unsigned DEFAULT NULL,
  `action` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_uncertain` tinyint(1) NOT NULL DEFAULT '0',
  `payload` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `customer_birthday_audits_customer_birthday_profile_id_foreign` (`customer_birthday_profile_id`),
  KEY `customer_birthday_audits_marketing_profile_id_foreign` (`marketing_profile_id`),
  KEY `customer_birthday_audits_action_index` (`action`),
  KEY `customer_birthday_audits_source_index` (`source`),
  KEY `customer_birthday_audits_is_uncertain_index` (`is_uncertain`),
  CONSTRAINT `customer_birthday_audits_customer_birthday_profile_id_foreign` FOREIGN KEY (`customer_birthday_profile_id`) REFERENCES `customer_birthday_profiles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `customer_birthday_audits_marketing_profile_id_foreign` FOREIGN KEY (`marketing_profile_id`) REFERENCES `marketing_profiles` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `customer_birthday_profiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `customer_birthday_profiles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `marketing_profile_id` bigint unsigned NOT NULL,
  `birth_month` tinyint unsigned DEFAULT NULL,
  `birth_day` tinyint unsigned DEFAULT NULL,
  `birth_year` smallint unsigned DEFAULT NULL,
  `birthday_full_date` date DEFAULT NULL,
  `source` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `signup_source` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `capture_date` timestamp NULL DEFAULT NULL,
  `email_subscribed` tinyint(1) DEFAULT NULL,
  `sms_subscribed` tinyint(1) DEFAULT NULL,
  `unsubscribed` tinyint(1) DEFAULT NULL,
  `source_file` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_captured_at` timestamp NULL DEFAULT NULL,
  `reward_last_issued_at` timestamp NULL DEFAULT NULL,
  `reward_last_issued_year` smallint unsigned DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `customer_birthday_profiles_marketing_profile_id_foreign` (`marketing_profile_id`),
  KEY `cbp_month_day_idx` (`birth_month`,`birth_day`),
  KEY `customer_birthday_profiles_birth_month_index` (`birth_month`),
  KEY `customer_birthday_profiles_birth_day_index` (`birth_day`),
  KEY `customer_birthday_profiles_birth_year_index` (`birth_year`),
  KEY `customer_birthday_profiles_birthday_full_date_index` (`birthday_full_date`),
  KEY `customer_birthday_profiles_source_index` (`source`),
  KEY `customer_birthday_profiles_source_captured_at_index` (`source_captured_at`),
  KEY `customer_birthday_profiles_reward_last_issued_at_index` (`reward_last_issued_at`),
  KEY `customer_birthday_profiles_reward_last_issued_year_index` (`reward_last_issued_year`),
  KEY `customer_birthday_profiles_signup_source_index` (`signup_source`),
  KEY `customer_birthday_profiles_capture_date_index` (`capture_date`),
  KEY `customer_birthday_profiles_email_subscribed_index` (`email_subscribed`),
  KEY `customer_birthday_profiles_sms_subscribed_index` (`sms_subscribed`),
  KEY `customer_birthday_profiles_unsubscribed_index` (`unsubscribed`),
  CONSTRAINT `customer_birthday_profiles_marketing_profile_id_foreign` FOREIGN KEY (`marketing_profile_id`) REFERENCES `marketing_profiles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `customer_equipment`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `customer_equipment` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `marketing_profile_id` bigint unsigned NOT NULL,
  `assigned_user_id` bigint unsigned DEFAULT NULL,
  `equipment_type` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'generator',
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `manufacturer` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `model_number` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `serial_number` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `installed_at` date DEFAULT NULL,
  `maintenance_interval_days` int unsigned NOT NULL DEFAULT '365',
  `last_serviced_at` date DEFAULT NULL,
  `next_service_due_at` date DEFAULT NULL,
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `external_source` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `external_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `equipment_tenant_external_unique` (`tenant_id`,`external_source`,`external_id`),
  KEY `customer_equipment_marketing_profile_id_foreign` (`marketing_profile_id`),
  KEY `customer_equipment_assigned_user_id_foreign` (`assigned_user_id`),
  KEY `equipment_tenant_customer_idx` (`tenant_id`,`marketing_profile_id`),
  KEY `equipment_tenant_due_idx` (`tenant_id`,`status`,`next_service_due_at`),
  KEY `customer_equipment_next_service_due_at_index` (`next_service_due_at`),
  KEY `customer_equipment_status_index` (`status`),
  CONSTRAINT `customer_equipment_assigned_user_id_foreign` FOREIGN KEY (`assigned_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `customer_equipment_marketing_profile_id_foreign` FOREIGN KEY (`marketing_profile_id`) REFERENCES `marketing_profiles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `customer_equipment_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `customer_external_profiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `customer_external_profiles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `marketing_profile_id` bigint unsigned DEFAULT NULL,
  `tenant_id` bigint unsigned DEFAULT NULL,
  `provider` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `integration` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `store_key` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `external_customer_id` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `external_customer_gid` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `first_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `full_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `normalized_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `normalized_phone` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `accepts_marketing` tinyint(1) DEFAULT NULL,
  `order_count` int unsigned DEFAULT NULL,
  `total_spent` decimal(12,2) DEFAULT NULL,
  `last_order_at` timestamp NULL DEFAULT NULL,
  `last_activity_at` timestamp NULL DEFAULT NULL,
  `source_channels` json DEFAULT NULL,
  `raw_metafields` json DEFAULT NULL,
  `points_balance` int DEFAULT NULL,
  `vip_tier` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `referral_link` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `synced_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cep_provider_integration_store_customer_unique` (`provider`,`integration`,`store_key`,`external_customer_id`),
  KEY `customer_external_profiles_marketing_profile_id_foreign` (`marketing_profile_id`),
  KEY `customer_external_profiles_provider_index` (`provider`),
  KEY `customer_external_profiles_integration_index` (`integration`),
  KEY `customer_external_profiles_store_key_index` (`store_key`),
  KEY `customer_external_profiles_external_customer_id_index` (`external_customer_id`),
  KEY `customer_external_profiles_email_index` (`email`),
  KEY `customer_external_profiles_normalized_email_index` (`normalized_email`),
  KEY `customer_external_profiles_phone_index` (`phone`),
  KEY `customer_external_profiles_normalized_phone_index` (`normalized_phone`),
  KEY `customer_external_profiles_synced_at_index` (`synced_at`),
  KEY `customer_external_profiles_tenant_id_index` (`tenant_id`),
  CONSTRAINT `customer_external_profiles_marketing_profile_id_foreign` FOREIGN KEY (`marketing_profile_id`) REFERENCES `marketing_profiles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `customer_external_profiles_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `customer_loop_actions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `customer_loop_actions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `customer_loop_activity_id` bigint unsigned DEFAULT NULL,
  `marketing_profile_id` bigint unsigned DEFAULT NULL,
  `assigned_to_user_id` bigint unsigned DEFAULT NULL,
  `created_by_user_id` bigint unsigned DEFAULT NULL,
  `action_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'suggested',
  `title` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `draft_body` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `due_at` timestamp NULL DEFAULT NULL,
  `prepared_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `snoozed_until` timestamp NULL DEFAULT NULL,
  `safe_context` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `customer_loop_actions_customer_loop_activity_id_foreign` (`customer_loop_activity_id`),
  KEY `customer_loop_actions_marketing_profile_id_foreign` (`marketing_profile_id`),
  KEY `customer_loop_actions_assigned_to_user_id_foreign` (`assigned_to_user_id`),
  KEY `customer_loop_actions_created_by_user_id_foreign` (`created_by_user_id`),
  KEY `cl_action_tenant_status_due_idx` (`tenant_id`,`status`,`due_at`),
  KEY `cl_action_tenant_profile_status_idx` (`tenant_id`,`marketing_profile_id`,`status`),
  CONSTRAINT `customer_loop_actions_assigned_to_user_id_foreign` FOREIGN KEY (`assigned_to_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `customer_loop_actions_created_by_user_id_foreign` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `customer_loop_actions_customer_loop_activity_id_foreign` FOREIGN KEY (`customer_loop_activity_id`) REFERENCES `customer_loop_activities` (`id`) ON DELETE SET NULL,
  CONSTRAINT `customer_loop_actions_marketing_profile_id_foreign` FOREIGN KEY (`marketing_profile_id`) REFERENCES `marketing_profiles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `customer_loop_actions_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `customer_loop_activities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `customer_loop_activities` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `marketing_profile_id` bigint unsigned DEFAULT NULL,
  `actor_user_id` bigint unsigned DEFAULT NULL,
  `source_type` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_id` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `event_key` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `summary` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `safe_context` json DEFAULT NULL,
  `occurred_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `customer_loop_activities_event_key_unique` (`event_key`),
  KEY `customer_loop_activities_marketing_profile_id_foreign` (`marketing_profile_id`),
  KEY `customer_loop_activities_actor_user_id_foreign` (`actor_user_id`),
  KEY `cl_activity_tenant_occurred_idx` (`tenant_id`,`occurred_at`),
  KEY `cl_activity_tenant_profile_occurred_idx` (`tenant_id`,`marketing_profile_id`,`occurred_at`),
  CONSTRAINT `customer_loop_activities_actor_user_id_foreign` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `customer_loop_activities_marketing_profile_id_foreign` FOREIGN KEY (`marketing_profile_id`) REFERENCES `marketing_profiles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `customer_loop_activities_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `customer_merge_operation_members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `customer_merge_operation_members` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `customer_merge_operation_id` bigint unsigned NOT NULL,
  `marketing_profile_id` bigint unsigned NOT NULL,
  `shopify_customer_gid` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `role` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'donor',
  `outcome` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `snapshot` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `customer_merge_member_operation_profile_unique` (`customer_merge_operation_id`,`marketing_profile_id`),
  KEY `customer_merge_operation_members_marketing_profile_id_index` (`marketing_profile_id`),
  KEY `customer_merge_operation_members_shopify_customer_gid_index` (`shopify_customer_gid`),
  CONSTRAINT `customer_merge_member_operation_fk` FOREIGN KEY (`customer_merge_operation_id`) REFERENCES `customer_merge_operations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `customer_merge_operations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `customer_merge_operations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `store_key` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `source` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'everbranch_wizard',
  `idempotency_key` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `survivor_profile_id` bigint unsigned DEFAULT NULL,
  `shopify_kept_customer_gid` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `shopify_deleted_customer_gid` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `shopify_job_id` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `initiated_by` bigint unsigned DEFAULT NULL,
  `approved_by` bigint unsigned DEFAULT NULL,
  `shopify_admin_user_id` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `field_choices` json DEFAULT NULL,
  `reward_resolution` json DEFAULT NULL,
  `shopify_preview` json DEFAULT NULL,
  `before_state` json DEFAULT NULL,
  `after_state` json DEFAULT NULL,
  `errors` json DEFAULT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `customer_merge_operation_tenant_idempotency_unique` (`tenant_id`,`idempotency_key`),
  KEY `customer_merge_operations_initiated_by_foreign` (`initiated_by`),
  KEY `customer_merge_operations_approved_by_foreign` (`approved_by`),
  KEY `customer_merge_operations_store_key_index` (`store_key`),
  KEY `customer_merge_operations_status_index` (`status`),
  KEY `customer_merge_operations_source_index` (`source`),
  KEY `customer_merge_operations_survivor_profile_id_index` (`survivor_profile_id`),
  KEY `customer_merge_operations_shopify_kept_customer_gid_index` (`shopify_kept_customer_gid`),
  KEY `customer_merge_operations_shopify_deleted_customer_gid_index` (`shopify_deleted_customer_gid`),
  CONSTRAINT `customer_merge_operations_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `customer_merge_operations_initiated_by_foreign` FOREIGN KEY (`initiated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `customer_merge_operations_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `development_change_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `development_change_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `title` varchar(180) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `summary` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `area` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `shopify_admin_user_id` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `shopify_admin_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `development_change_logs_created_by_foreign` (`created_by`),
  KEY `development_change_logs_tenant_created_idx` (`tenant_id`,`created_at`),
  CONSTRAINT `development_change_logs_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `development_change_logs_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `development_notes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `development_notes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `title` varchar(180) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `body` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `shopify_admin_user_id` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `shopify_admin_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `development_notes_created_by_foreign` (`created_by`),
  KEY `development_notes_updated_by_foreign` (`updated_by`),
  KEY `development_notes_tenant_updated_idx` (`tenant_id`,`updated_at`),
  CONSTRAINT `development_notes_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `development_notes_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `development_notes_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `event_box_plans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `event_box_plans` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `event_instance_id` bigint unsigned NOT NULL,
  `scent_raw` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `box_count_sent` decimal(6,2) DEFAULT NULL,
  `box_count_returned` decimal(6,2) DEFAULT NULL,
  `line_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `is_split_box` tinyint(1) NOT NULL DEFAULT '0',
  `import_batch_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `event_box_plans_event_instance_id_index` (`event_instance_id`),
  KEY `event_box_plans_scent_raw_index` (`scent_raw`),
  KEY `event_box_plans_import_batch_id_index` (`import_batch_id`),
  CONSTRAINT `event_box_plans_event_instance_id_foreign` FOREIGN KEY (`event_instance_id`) REFERENCES `event_instances` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `event_instances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `event_instances` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `public_slug` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `venue` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `city` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `state` varchar(2) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `starts_at` date DEFAULT NULL,
  `ends_at` date DEFAULT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unknown',
  `notes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `primary_runner` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `days_attended` int unsigned DEFAULT NULL,
  `selling_hours` decimal(6,2) DEFAULT NULL,
  `total_sales` decimal(10,2) DEFAULT NULL,
  `boxes_sold` decimal(6,2) DEFAULT NULL,
  `source_file` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_sheet` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `import_batch_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `event_instances_public_slug_unique` (`public_slug`),
  KEY `event_instances_title_starts_at_index` (`title`,`starts_at`),
  KEY `event_instances_starts_at_index` (`starts_at`),
  KEY `event_instances_state_starts_at_index` (`state`,`starts_at`),
  KEY `event_instances_import_batch_id_index` (`import_batch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `event_mappings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `event_mappings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `upcoming_event_id` bigint unsigned NOT NULL,
  `past_event_id` bigint unsigned NOT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `event_mappings_upcoming_event_id_unique` (`upcoming_event_id`),
  KEY `event_mappings_created_by_foreign` (`created_by`),
  KEY `event_mappings_past_event_id_index` (`past_event_id`),
  CONSTRAINT `event_mappings_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `event_mappings_past_event_id_foreign` FOREIGN KEY (`past_event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `event_mappings_upcoming_event_id_foreign` FOREIGN KEY (`upcoming_event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `event_match_overrides`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `event_match_overrides` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `upcoming_event_id` bigint unsigned NOT NULL,
  `candidate_event_id` bigint unsigned NOT NULL,
  `created_by_user_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `event_match_overrides_upcoming_event_id_unique` (`upcoming_event_id`),
  KEY `event_match_overrides_candidate_event_id_index` (`candidate_event_id`),
  CONSTRAINT `event_match_overrides_candidate_event_id_foreign` FOREIGN KEY (`candidate_event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `event_match_overrides_upcoming_event_id_foreign` FOREIGN KEY (`upcoming_event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `event_shipments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `event_shipments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `event_id` bigint unsigned NOT NULL,
  `scent_id` bigint unsigned DEFAULT NULL,
  `size_id` bigint unsigned DEFAULT NULL,
  `wick_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `planned_qty` int unsigned NOT NULL DEFAULT '0',
  `sent_qty` int unsigned DEFAULT NULL,
  `returned_qty` int unsigned DEFAULT NULL,
  `sold_qty` int unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `event_shipments_event_id_foreign` (`event_id`),
  KEY `event_shipments_scent_id_foreign` (`scent_id`),
  KEY `event_shipments_size_id_foreign` (`size_id`),
  CONSTRAINT `event_shipments_event_id_foreign` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `event_shipments_scent_id_foreign` FOREIGN KEY (`scent_id`) REFERENCES `scents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `event_shipments_size_id_foreign` FOREIGN KEY (`size_id`) REFERENCES `sizes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `market_id` bigint unsigned DEFAULT NULL,
  `year` smallint unsigned DEFAULT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `display_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `venue` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `city` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `state` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `starts_at` date DEFAULT NULL,
  `ends_at` date DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `ship_date` date DEFAULT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'planned',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `source` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_ref` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `parse_confidence` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `parse_notes_json` json DEFAULT NULL,
  `needs_review` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `events_market_id_year_index` (`market_id`,`year`),
  KEY `events_source_source_ref_index` (`source`,`source_ref`),
  CONSTRAINT `events_market_id_foreign` FOREIGN KEY (`market_id`) REFERENCES `markets` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `everbranch_mobile_push_devices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `everbranch_mobile_push_devices` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `platform` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `device_token` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `device_token_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `app_version` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `device_name` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notifications_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `last_seen_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `everbranch_mobile_push_devices_device_token_hash_unique` (`device_token_hash`),
  KEY `eb_push_user_enabled_idx` (`user_id`,`notifications_enabled`),
  CONSTRAINT `everbranch_mobile_push_devices_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `failed_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `connection` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `queue` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `exception` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `field_inventory_movements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `field_inventory_movements` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `field_material_catalog_item_id` bigint unsigned NOT NULL,
  `field_service_vehicle_id` bigint unsigned DEFAULT NULL,
  `field_service_job_id` bigint unsigned DEFAULT NULL,
  `created_by_user_id` bigint unsigned DEFAULT NULL,
  `movement_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantity` decimal(12,2) NOT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `field_inventory_movement_tenant_idx` (`tenant_id`,`created_at`),
  KEY `field_inventory_movement_item_idx` (`field_material_catalog_item_id`,`created_at`),
  KEY `field_inventory_movement_vehicle_fk` (`field_service_vehicle_id`),
  KEY `field_inventory_movement_job_fk` (`field_service_job_id`),
  KEY `field_inventory_movement_user_fk` (`created_by_user_id`),
  CONSTRAINT `field_inventory_movement_catalog_fk` FOREIGN KEY (`field_material_catalog_item_id`) REFERENCES `field_material_catalog_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `field_inventory_movement_job_fk` FOREIGN KEY (`field_service_job_id`) REFERENCES `field_service_jobs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `field_inventory_movement_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `field_inventory_movement_user_fk` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `field_inventory_movement_vehicle_fk` FOREIGN KEY (`field_service_vehicle_id`) REFERENCES `field_service_vehicles` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `field_material_catalog_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `field_material_catalog_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `sku` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `unit` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `quantity_on_hand` decimal(12,2) NOT NULL DEFAULT '0.00',
  `reorder_level` decimal(12,2) NOT NULL DEFAULT '0.00',
  `unit_cost` decimal(12,2) DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `field_material_catalog_active_idx` (`tenant_id`,`active`,`name`),
  CONSTRAINT `field_material_catalog_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `field_service_estimate_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `field_service_estimate_lines` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `field_service_estimate_id` bigint unsigned NOT NULL,
  `field_service_price_book_item_id` bigint unsigned DEFAULT NULL,
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantity` decimal(14,4) NOT NULL DEFAULT '1.0000',
  `unit_price` decimal(14,4) NOT NULL DEFAULT '0.0000',
  `line_total` decimal(14,2) NOT NULL DEFAULT '0.00',
  `source_snapshot` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fs_estimate_lines_sort_idx` (`field_service_estimate_id`,`sort_order`),
  KEY `fs_estimate_lines_tenant_fk` (`tenant_id`),
  KEY `fs_estimate_lines_item_fk` (`field_service_price_book_item_id`),
  CONSTRAINT `fs_estimate_lines_estimate_fk` FOREIGN KEY (`field_service_estimate_id`) REFERENCES `field_service_estimates` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fs_estimate_lines_item_fk` FOREIGN KEY (`field_service_price_book_item_id`) REFERENCES `field_service_price_book_items` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fs_estimate_lines_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `field_service_estimates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `field_service_estimates` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `marketing_profile_id` bigint unsigned DEFAULT NULL,
  `field_service_job_id` bigint unsigned DEFAULT NULL,
  `created_by_user_id` bigint unsigned DEFAULT NULL,
  `estimate_number` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `subtotal` decimal(14,2) NOT NULL DEFAULT '0.00',
  `discount_amount` decimal(14,2) NOT NULL DEFAULT '0.00',
  `tax_amount` decimal(14,2) NOT NULL DEFAULT '0.00',
  `total_amount` decimal(14,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fs_estimates_tenant_number_unique` (`tenant_id`,`estimate_number`),
  KEY `fs_estimates_profile_fk` (`marketing_profile_id`),
  KEY `fs_estimates_job_fk` (`field_service_job_id`),
  KEY `fs_estimates_creator_fk` (`created_by_user_id`),
  KEY `field_service_estimates_status_index` (`status`),
  CONSTRAINT `fs_estimates_creator_fk` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fs_estimates_job_fk` FOREIGN KEY (`field_service_job_id`) REFERENCES `field_service_jobs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fs_estimates_profile_fk` FOREIGN KEY (`marketing_profile_id`) REFERENCES `marketing_profiles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fs_estimates_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `field_service_financial_document_attachments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `field_service_financial_document_attachments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `field_service_financial_document_id` bigint unsigned NOT NULL,
  `external_id` varchar(180) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `content_type` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_size` bigint unsigned DEFAULT NULL,
  `note` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fs_fin_attachments_document_external_unique` (`field_service_financial_document_id`,`external_id`),
  KEY `fs_fin_attachments_tenant_fk` (`tenant_id`),
  CONSTRAINT `fs_fin_attachments_document_fk` FOREIGN KEY (`field_service_financial_document_id`) REFERENCES `field_service_financial_documents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fs_fin_attachments_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `field_service_financial_document_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `field_service_financial_document_lines` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `field_service_financial_document_id` bigint unsigned NOT NULL,
  `source_line_id` varchar(180) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `detail_type` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `item_external_id` varchar(180) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `item_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `quantity` decimal(14,4) DEFAULT NULL,
  `unit_price` decimal(14,4) DEFAULT NULL,
  `amount` decimal(14,2) DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fs_fin_lines_tenant_item_idx` (`tenant_id`,`item_external_id`),
  KEY `fs_fin_lines_document_sort_idx` (`field_service_financial_document_id`,`sort_order`),
  CONSTRAINT `fs_fin_lines_document_fk` FOREIGN KEY (`field_service_financial_document_id`) REFERENCES `field_service_financial_documents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fs_fin_lines_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `field_service_financial_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `field_service_financial_documents` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `marketing_profile_id` bigint unsigned DEFAULT NULL,
  `field_service_job_id` bigint unsigned DEFAULT NULL,
  `source` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'quickbooks',
  `document_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `external_id` varchar(180) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `document_number` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `transaction_date` date DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `total_amount` decimal(14,2) DEFAULT NULL,
  `balance` decimal(14,2) DEFAULT NULL,
  `currency` varchar(12) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `private_note` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `customer_memo` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `linked_transactions` json DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fs_fin_docs_tenant_source_type_external_unique` (`tenant_id`,`source`,`document_type`,`external_id`),
  KEY `fs_fin_docs_tenant_type_date_idx` (`tenant_id`,`document_type`,`transaction_date`),
  KEY `fs_fin_docs_tenant_job_idx` (`tenant_id`,`field_service_job_id`),
  KEY `fs_fin_docs_profile_fk` (`marketing_profile_id`),
  KEY `fs_fin_docs_job_fk` (`field_service_job_id`),
  CONSTRAINT `fs_fin_docs_job_fk` FOREIGN KEY (`field_service_job_id`) REFERENCES `field_service_jobs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fs_fin_docs_profile_fk` FOREIGN KEY (`marketing_profile_id`) REFERENCES `marketing_profiles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fs_fin_docs_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `field_service_job_note_mentions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `field_service_job_note_mentions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `field_service_job_note_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fs_job_note_mention_unique` (`field_service_job_note_id`,`user_id`),
  KEY `fs_note_mention_tenant_fk` (`tenant_id`),
  KEY `fs_note_mention_user_fk` (`user_id`),
  CONSTRAINT `fs_note_mention_note_fk` FOREIGN KEY (`field_service_job_note_id`) REFERENCES `field_service_job_notes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fs_note_mention_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fs_note_mention_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `field_service_job_notes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `field_service_job_notes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `field_service_job_id` bigint unsigned NOT NULL,
  `created_by_user_id` bigint unsigned DEFAULT NULL,
  `body` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status_update` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `noted_at` timestamp NULL DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `field_service_job_notes_field_service_job_id_foreign` (`field_service_job_id`),
  KEY `field_service_job_notes_created_by_user_id_foreign` (`created_by_user_id`),
  KEY `fs_notes_tenant_job_idx` (`tenant_id`,`field_service_job_id`),
  KEY `fs_notes_tenant_noted_idx` (`tenant_id`,`noted_at`),
  KEY `field_service_job_notes_status_update_index` (`status_update`),
  CONSTRAINT `field_service_job_notes_created_by_user_id_foreign` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `field_service_job_notes_field_service_job_id_foreign` FOREIGN KEY (`field_service_job_id`) REFERENCES `field_service_jobs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `field_service_job_notes_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `field_service_job_notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `field_service_job_notifications` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `field_service_job_id` bigint unsigned NOT NULL,
  `field_service_job_note_id` bigint unsigned DEFAULT NULL,
  `user_id` bigint unsigned NOT NULL,
  `channel` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'comment',
  `event_key` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `provider_message_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `failure_code` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `read_at` timestamp NULL DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fs_job_notification_unique` (`field_service_job_note_id`,`user_id`,`channel`),
  UNIQUE KEY `fs_job_notification_event_unique` (`tenant_id`,`user_id`,`channel`,`event_key`),
  KEY `fs_job_notification_job_fk` (`field_service_job_id`),
  KEY `fs_job_notification_user_fk` (`user_id`),
  KEY `fs_job_notification_inbox_idx` (`tenant_id`,`user_id`,`read_at`),
  KEY `field_service_job_notifications_event_type_index` (`event_type`),
  CONSTRAINT `fs_job_notification_job_fk` FOREIGN KEY (`field_service_job_id`) REFERENCES `field_service_jobs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fs_job_notification_note_fk` FOREIGN KEY (`field_service_job_note_id`) REFERENCES `field_service_job_notes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fs_job_notification_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fs_job_notification_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `field_service_job_participants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `field_service_job_participants` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `field_service_job_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `role` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'member',
  `following` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fs_job_participant_unique` (`field_service_job_id`,`user_id`),
  KEY `fs_job_part_user_fk` (`user_id`),
  KEY `fs_job_participant_tenant_user_idx` (`tenant_id`,`user_id`),
  CONSTRAINT `fs_job_part_job_fk` FOREIGN KEY (`field_service_job_id`) REFERENCES `field_service_jobs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fs_job_part_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fs_job_part_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `field_service_job_photos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `field_service_job_photos` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `field_service_job_id` bigint unsigned NOT NULL,
  `field_service_job_note_id` bigint unsigned DEFAULT NULL,
  `file_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `caption` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `uploaded_by_user_id` bigint unsigned DEFAULT NULL,
  `captured_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `field_service_job_photos_field_service_job_id_foreign` (`field_service_job_id`),
  KEY `field_service_job_photos_uploaded_by_user_id_foreign` (`uploaded_by_user_id`),
  KEY `fs_photos_tenant_job_idx` (`tenant_id`,`field_service_job_id`),
  KEY `field_service_job_photos_field_service_job_note_id_foreign` (`field_service_job_note_id`),
  CONSTRAINT `field_service_job_photos_field_service_job_id_foreign` FOREIGN KEY (`field_service_job_id`) REFERENCES `field_service_jobs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `field_service_job_photos_field_service_job_note_id_foreign` FOREIGN KEY (`field_service_job_note_id`) REFERENCES `field_service_job_notes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `field_service_job_photos_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `field_service_job_photos_uploaded_by_user_id_foreign` FOREIGN KEY (`uploaded_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `field_service_job_vehicle_assignments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `field_service_job_vehicle_assignments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `field_service_job_id` bigint unsigned NOT NULL,
  `field_service_vehicle_id` bigint unsigned NOT NULL,
  `assigned_by_user_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fs_job_vehicle_unique` (`field_service_job_id`,`field_service_vehicle_id`),
  KEY `fs_job_vehicle_tenant_idx` (`tenant_id`,`field_service_vehicle_id`),
  KEY `fs_job_vehicle_vehicle_fk` (`field_service_vehicle_id`),
  KEY `fs_job_vehicle_actor_fk` (`assigned_by_user_id`),
  CONSTRAINT `fs_job_vehicle_actor_fk` FOREIGN KEY (`assigned_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fs_job_vehicle_job_fk` FOREIGN KEY (`field_service_job_id`) REFERENCES `field_service_jobs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fs_job_vehicle_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fs_job_vehicle_vehicle_fk` FOREIGN KEY (`field_service_vehicle_id`) REFERENCES `field_service_vehicles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `field_service_job_vehicle_crews`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `field_service_job_vehicle_crews` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `field_service_job_id` bigint unsigned NOT NULL,
  `field_service_vehicle_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fs_job_vehicle_crew_unique` (`field_service_job_id`,`field_service_vehicle_id`,`user_id`),
  KEY `fs_job_vehicle_crew_user_idx` (`tenant_id`,`user_id`,`field_service_job_id`),
  KEY `fs_job_vehicle_crew_vehicle_fk` (`field_service_vehicle_id`),
  KEY `fs_job_vehicle_crew_user_fk` (`user_id`),
  CONSTRAINT `fs_job_vehicle_crew_job_fk` FOREIGN KEY (`field_service_job_id`) REFERENCES `field_service_jobs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fs_job_vehicle_crew_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fs_job_vehicle_crew_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fs_job_vehicle_crew_vehicle_fk` FOREIGN KEY (`field_service_vehicle_id`) REFERENCES `field_service_vehicles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `field_service_job_workspace_asset`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `field_service_job_workspace_asset` (
  `tenant_id` bigint unsigned NOT NULL,
  `field_service_job_id` bigint unsigned NOT NULL,
  `workspace_asset_id` bigint unsigned NOT NULL,
  `linked_by_user_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`field_service_job_id`,`workspace_asset_id`),
  KEY `fs_job_asset_tenant_asset_idx` (`tenant_id`,`workspace_asset_id`),
  KEY `fs_job_asset_asset_fk` (`workspace_asset_id`),
  KEY `fs_job_asset_linker_fk` (`linked_by_user_id`),
  CONSTRAINT `fs_job_asset_asset_fk` FOREIGN KEY (`workspace_asset_id`) REFERENCES `workspace_assets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fs_job_asset_job_fk` FOREIGN KEY (`field_service_job_id`) REFERENCES `field_service_jobs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fs_job_asset_linker_fk` FOREIGN KEY (`linked_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fs_job_asset_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `field_service_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `field_service_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `marketing_profile_id` bigint unsigned DEFAULT NULL,
  `customer_equipment_id` bigint unsigned DEFAULT NULL,
  `assigned_user_id` bigint unsigned DEFAULT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `operational_status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status_source` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'system',
  `priority` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'normal',
  `customer_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `customer_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `customer_phone` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `project_manager_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `project_manager_company` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `project_manager_phone` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `project_manager_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `lock_box_code` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `service_address_line_1` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `service_address_line_2` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `service_city` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `service_state` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `service_postal_code` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `service_country` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `scheduled_for` timestamp NULL DEFAULT NULL,
  `scheduled_end_at` timestamp NULL DEFAULT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `blocked_reason` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `canceled_at` timestamp NULL DEFAULT NULL,
  `last_financial_activity_at` timestamp NULL DEFAULT NULL,
  `archived_at` timestamp NULL DEFAULT NULL,
  `external_source` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `external_id` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `field_service_jobs_marketing_profile_id_foreign` (`marketing_profile_id`),
  KEY `field_service_jobs_assigned_user_id_foreign` (`assigned_user_id`),
  KEY `fs_jobs_tenant_status_schedule_idx` (`tenant_id`,`status`,`scheduled_for`),
  KEY `fs_jobs_tenant_profile_idx` (`tenant_id`,`marketing_profile_id`),
  KEY `field_service_jobs_status_index` (`status`),
  KEY `field_service_jobs_customer_email_index` (`customer_email`),
  KEY `fs_jobs_tenant_external_idx` (`tenant_id`,`external_source`,`external_id`),
  KEY `field_service_jobs_operational_status_index` (`operational_status`),
  KEY `field_service_jobs_last_financial_activity_at_index` (`last_financial_activity_at`),
  KEY `field_service_jobs_archived_at_index` (`archived_at`),
  KEY `field_service_jobs_priority_index` (`priority`),
  KEY `field_service_jobs_customer_equipment_id_foreign` (`customer_equipment_id`),
  KEY `fs_jobs_tenant_equipment_idx` (`tenant_id`,`customer_equipment_id`,`completed_at`),
  CONSTRAINT `field_service_jobs_assigned_user_id_foreign` FOREIGN KEY (`assigned_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `field_service_jobs_customer_equipment_id_foreign` FOREIGN KEY (`customer_equipment_id`) REFERENCES `customer_equipment` (`id`) ON DELETE SET NULL,
  CONSTRAINT `field_service_jobs_marketing_profile_id_foreign` FOREIGN KEY (`marketing_profile_id`) REFERENCES `marketing_profiles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `field_service_jobs_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `field_service_materials`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `field_service_materials` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `field_service_job_id` bigint unsigned DEFAULT NULL,
  `field_material_catalog_item_id` bigint unsigned DEFAULT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantity` decimal(10,2) NOT NULL DEFAULT '1.00',
  `pulled_quantity` decimal(10,2) NOT NULL DEFAULT '0.00',
  `loaded_quantity` decimal(10,2) NOT NULL DEFAULT '0.00',
  `used_quantity` decimal(10,2) NOT NULL DEFAULT '0.00',
  `unit` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `unit_cost` decimal(10,2) DEFAULT NULL,
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'needed',
  `external_source` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `external_id` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `field_service_materials_field_service_job_id_foreign` (`field_service_job_id`),
  KEY `fs_materials_tenant_status_idx` (`tenant_id`,`status`),
  KEY `field_service_materials_status_index` (`status`),
  KEY `fs_materials_tenant_external_idx` (`tenant_id`,`external_source`,`external_id`),
  KEY `fs_material_catalog_fk` (`field_material_catalog_item_id`),
  CONSTRAINT `field_service_materials_field_service_job_id_foreign` FOREIGN KEY (`field_service_job_id`) REFERENCES `field_service_jobs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `field_service_materials_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fs_material_catalog_fk` FOREIGN KEY (`field_material_catalog_item_id`) REFERENCES `field_material_catalog_items` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `field_service_price_book_candidates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `field_service_price_book_candidates` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `source` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'quickbooks',
  `normalized_key` varchar(180) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'suggested',
  `sample_count` int unsigned NOT NULL DEFAULT '0',
  `median_unit_price` decimal(14,4) DEFAULT NULL,
  `minimum_unit_price` decimal(14,4) DEFAULT NULL,
  `maximum_unit_price` decimal(14,4) DEFAULT NULL,
  `recent_unit_price` decimal(14,4) DEFAULT NULL,
  `high_variance` tinyint(1) NOT NULL DEFAULT '0',
  `last_invoiced_at` date DEFAULT NULL,
  `approved_price_book_item_id` bigint unsigned DEFAULT NULL,
  `reviewed_by_user_id` bigint unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fs_price_candidates_source_key_unique` (`tenant_id`,`source`,`normalized_key`),
  KEY `fs_price_candidates_item_fk` (`approved_price_book_item_id`),
  KEY `fs_price_candidates_reviewer_fk` (`reviewed_by_user_id`),
  KEY `field_service_price_book_candidates_status_index` (`status`),
  CONSTRAINT `fs_price_candidates_item_fk` FOREIGN KEY (`approved_price_book_item_id`) REFERENCES `field_service_price_book_items` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fs_price_candidates_reviewer_fk` FOREIGN KEY (`reviewed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fs_price_candidates_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `field_service_price_book_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `field_service_price_book_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `source` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'quickbooks',
  `external_id` varchar(180) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `item_type` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sku` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `unit_price` decimal(14,4) DEFAULT NULL,
  `purchase_cost` decimal(14,4) DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `taxable` tinyint(1) DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fs_price_book_tenant_source_external_unique` (`tenant_id`,`source`,`external_id`),
  KEY `fs_price_book_tenant_type_active_idx` (`tenant_id`,`item_type`,`active`),
  CONSTRAINT `fs_price_book_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `field_service_reminder_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `field_service_reminder_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT '0',
  `channel` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'sms',
  `cadence` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'daily',
  `send_time` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `timezone` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'America/New_York',
  `provider_status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'not_verified',
  `customer_copy` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `internal_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `job_update_sms` json DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `field_service_reminder_settings_tenant_id_unique` (`tenant_id`),
  KEY `fs_reminders_enabled_provider_idx` (`enabled`,`provider_status`),
  CONSTRAINT `field_service_reminder_settings_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `field_service_task_assignees`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `field_service_task_assignees` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `field_service_task_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `assigned_by_user_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fs_task_assignees_unique` (`tenant_id`,`field_service_task_id`,`user_id`),
  KEY `field_service_task_assignees_field_service_task_id_foreign` (`field_service_task_id`),
  KEY `field_service_task_assignees_user_id_foreign` (`user_id`),
  KEY `field_service_task_assignees_assigned_by_user_id_foreign` (`assigned_by_user_id`),
  KEY `fs_task_assignees_user_idx` (`tenant_id`,`user_id`,`field_service_task_id`),
  CONSTRAINT `field_service_task_assignees_assigned_by_user_id_foreign` FOREIGN KEY (`assigned_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `field_service_task_assignees_field_service_task_id_foreign` FOREIGN KEY (`field_service_task_id`) REFERENCES `field_service_tasks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `field_service_task_assignees_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `field_service_task_assignees_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `field_service_task_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `field_service_task_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `field_service_task_id` bigint unsigned NOT NULL,
  `actor_user_id` bigint unsigned DEFAULT NULL,
  `event_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `from_status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `to_status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `note` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `idempotency_key` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fs_task_events_idempotency_unique` (`tenant_id`,`idempotency_key`),
  KEY `field_service_task_events_field_service_task_id_foreign` (`field_service_task_id`),
  KEY `field_service_task_events_actor_user_id_foreign` (`actor_user_id`),
  KEY `fs_task_events_task_idx` (`tenant_id`,`field_service_task_id`,`created_at`),
  KEY `field_service_task_events_event_type_index` (`event_type`),
  CONSTRAINT `field_service_task_events_actor_user_id_foreign` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `field_service_task_events_field_service_task_id_foreign` FOREIGN KEY (`field_service_task_id`) REFERENCES `field_service_tasks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `field_service_task_events_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `field_service_tasks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `field_service_tasks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `field_service_job_id` bigint unsigned NOT NULL,
  `assigned_user_id` bigint unsigned DEFAULT NULL,
  `created_by_user_id` bigint unsigned DEFAULT NULL,
  `completed_by_user_id` bigint unsigned DEFAULT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `priority` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'normal',
  `due_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `field_service_tasks_field_service_job_id_foreign` (`field_service_job_id`),
  KEY `field_service_tasks_assigned_user_id_foreign` (`assigned_user_id`),
  KEY `fs_tasks_tenant_status_due_idx` (`tenant_id`,`status`,`due_at`),
  KEY `field_service_tasks_status_index` (`status`),
  KEY `field_service_tasks_created_by_user_id_foreign` (`created_by_user_id`),
  KEY `field_service_tasks_completed_by_user_id_foreign` (`completed_by_user_id`),
  KEY `field_service_tasks_priority_index` (`priority`),
  CONSTRAINT `field_service_tasks_assigned_user_id_foreign` FOREIGN KEY (`assigned_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `field_service_tasks_completed_by_user_id_foreign` FOREIGN KEY (`completed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `field_service_tasks_created_by_user_id_foreign` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `field_service_tasks_field_service_job_id_foreign` FOREIGN KEY (`field_service_job_id`) REFERENCES `field_service_jobs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `field_service_tasks_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `field_service_time_breaks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `field_service_time_breaks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `field_service_time_session_id` bigint unsigned NOT NULL,
  `client_uuid` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `started_at` timestamp NOT NULL,
  `ended_at` timestamp NULL DEFAULT NULL,
  `duration_seconds` int unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fs_time_break_idempotency_unique` (`field_service_time_session_id`,`client_uuid`),
  KEY `fs_time_break_tenant_fk` (`tenant_id`),
  CONSTRAINT `fs_time_break_session_fk` FOREIGN KEY (`field_service_time_session_id`) REFERENCES `field_service_time_sessions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fs_time_break_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `field_service_time_change_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `field_service_time_change_requests` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `field_service_time_session_id` bigint unsigned DEFAULT NULL,
  `field_service_time_entry_id` bigint unsigned DEFAULT NULL,
  `requested_by_user_id` bigint unsigned NOT NULL,
  `reviewed_by_user_id` bigint unsigned DEFAULT NULL,
  `status` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `before_snapshot` json NOT NULL,
  `requested_snapshot` json NOT NULL,
  `resolution_snapshot` json DEFAULT NULL,
  `reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `reviewer_note` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fs_time_change_tenant_status_idx` (`tenant_id`,`status`,`created_at`),
  KEY `fs_time_change_requester_idx` (`tenant_id`,`requested_by_user_id`,`created_at`),
  KEY `fs_time_change_session_fk` (`field_service_time_session_id`),
  KEY `fs_time_change_entry_fk` (`field_service_time_entry_id`),
  KEY `fs_time_change_requester_fk` (`requested_by_user_id`),
  KEY `fs_time_change_reviewer_fk` (`reviewed_by_user_id`),
  CONSTRAINT `fs_time_change_entry_fk` FOREIGN KEY (`field_service_time_entry_id`) REFERENCES `field_service_time_entries` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fs_time_change_requester_fk` FOREIGN KEY (`requested_by_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fs_time_change_reviewer_fk` FOREIGN KEY (`reviewed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fs_time_change_session_fk` FOREIGN KEY (`field_service_time_session_id`) REFERENCES `field_service_time_sessions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fs_time_change_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `field_service_time_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `field_service_time_entries` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `field_service_job_id` bigint unsigned DEFAULT NULL,
  `user_id` bigint unsigned NOT NULL,
  `reviewed_by_user_id` bigint unsigned DEFAULT NULL,
  `work_date` date NOT NULL,
  `started_at` time NOT NULL,
  `ended_at` time NOT NULL,
  `break_minutes` smallint unsigned NOT NULL DEFAULT '0',
  `duration_minutes` int unsigned NOT NULL,
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'submitted',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `field_service_time_entries_field_service_job_id_foreign` (`field_service_job_id`),
  KEY `field_service_time_entries_user_id_foreign` (`user_id`),
  KEY `field_service_time_entries_reviewed_by_user_id_foreign` (`reviewed_by_user_id`),
  KEY `fs_time_tenant_date_status_idx` (`tenant_id`,`work_date`,`status`),
  KEY `fs_time_tenant_user_date_idx` (`tenant_id`,`user_id`,`work_date`),
  KEY `field_service_time_entries_status_index` (`status`),
  CONSTRAINT `field_service_time_entries_field_service_job_id_foreign` FOREIGN KEY (`field_service_job_id`) REFERENCES `field_service_jobs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `field_service_time_entries_reviewed_by_user_id_foreign` FOREIGN KEY (`reviewed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `field_service_time_entries_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `field_service_time_entries_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `field_service_time_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `field_service_time_sessions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `field_service_job_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `reviewed_by_user_id` bigint unsigned DEFAULT NULL,
  `client_uuid` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `active_user_key` bigint unsigned DEFAULT NULL,
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'running',
  `clocked_in_at` timestamp NOT NULL,
  `clocked_out_at` timestamp NULL DEFAULT NULL,
  `break_seconds` int unsigned NOT NULL DEFAULT '0',
  `duration_seconds` int unsigned DEFAULT NULL,
  `clock_out_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `source` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'mobile',
  `device_context` json DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fs_time_session_idempotency_unique` (`tenant_id`,`user_id`,`client_uuid`),
  UNIQUE KEY `fs_time_session_one_active_unique` (`tenant_id`,`active_user_key`),
  KEY `fs_time_session_active_idx` (`tenant_id`,`user_id`,`status`),
  KEY `fs_time_session_job_idx` (`tenant_id`,`field_service_job_id`,`clocked_in_at`),
  KEY `fs_time_session_job_fk` (`field_service_job_id`),
  KEY `fs_time_session_user_fk` (`user_id`),
  KEY `fs_time_session_reviewer_fk` (`reviewed_by_user_id`),
  CONSTRAINT `fs_time_session_job_fk` FOREIGN KEY (`field_service_job_id`) REFERENCES `field_service_jobs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fs_time_session_reviewer_fk` FOREIGN KEY (`reviewed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fs_time_session_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fs_time_session_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `field_service_vehicle_inventory`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `field_service_vehicle_inventory` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `field_service_vehicle_id` bigint unsigned NOT NULL,
  `field_material_catalog_item_id` bigint unsigned NOT NULL,
  `quantity` decimal(12,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fs_vehicle_inventory_unique` (`field_service_vehicle_id`,`field_material_catalog_item_id`),
  KEY `fs_vehicle_inventory_item_idx` (`tenant_id`,`field_material_catalog_item_id`),
  KEY `fs_vehicle_inventory_catalog_fk` (`field_material_catalog_item_id`),
  CONSTRAINT `fs_vehicle_inventory_catalog_fk` FOREIGN KEY (`field_material_catalog_item_id`) REFERENCES `field_material_catalog_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fs_vehicle_inventory_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fs_vehicle_inventory_vehicle_fk` FOREIGN KEY (`field_service_vehicle_id`) REFERENCES `field_service_vehicles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `field_service_vehicles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `field_service_vehicles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `identifier` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fs_vehicles_tenant_status_idx` (`tenant_id`,`status`),
  KEY `field_service_vehicles_status_index` (`status`),
  CONSTRAINT `field_service_vehicles_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `field_service_work_candidates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `field_service_work_candidates` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `field_service_financial_document_id` bigint unsigned DEFAULT NULL,
  `reviewed_by_user_id` bigint unsigned DEFAULT NULL,
  `converted_job_id` bigint unsigned DEFAULT NULL,
  `source` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'quickbooks',
  `source_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `external_id` varchar(180) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `customer_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `customer_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `customer_phone` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `amount` decimal(14,2) DEFAULT NULL,
  `balance` decimal(14,2) DEFAULT NULL,
  `description` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `service_address_line_1` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `service_address_line_2` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `service_city` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `service_state` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `service_postal_code` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `service_country` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `priority` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'normal',
  `scheduled_for` timestamp NULL DEFAULT NULL,
  `scheduled_end_at` timestamp NULL DEFAULT NULL,
  `assigned_user_id` bigint unsigned DEFAULT NULL,
  `participant_user_ids` json DEFAULT NULL,
  `project_manager_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `project_manager_company` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `project_manager_phone` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `project_manager_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payload` json DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `archived_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fs_candidate_source_unique` (`tenant_id`,`source`,`source_type`,`external_id`),
  KEY `fs_candidate_status_idx` (`tenant_id`,`status`,`updated_at`),
  KEY `fs_candidate_doc_fk` (`field_service_financial_document_id`),
  KEY `fs_candidate_reviewer_fk` (`reviewed_by_user_id`),
  KEY `fs_candidate_job_fk` (`converted_job_id`),
  KEY `field_service_work_candidates_assigned_user_id_foreign` (`assigned_user_id`),
  CONSTRAINT `field_service_work_candidates_assigned_user_id_foreign` FOREIGN KEY (`assigned_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fs_candidate_doc_fk` FOREIGN KEY (`field_service_financial_document_id`) REFERENCES `field_service_financial_documents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fs_candidate_job_fk` FOREIGN KEY (`converted_job_id`) REFERENCES `field_service_jobs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fs_candidate_reviewer_fk` FOREIGN KEY (`reviewed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fs_candidate_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `field_service_work_shifts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `field_service_work_shifts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `field_service_job_id` bigint unsigned DEFAULT NULL,
  `created_by_user_id` bigint unsigned DEFAULT NULL,
  `status` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'scheduled',
  `starts_at` timestamp NOT NULL,
  `ends_at` timestamp NOT NULL,
  `unpaid_break_minutes` smallint unsigned NOT NULL DEFAULT '0',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `canceled_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fs_shift_tenant_user_start_idx` (`tenant_id`,`user_id`,`starts_at`),
  KEY `fs_shift_tenant_status_start_idx` (`tenant_id`,`status`,`starts_at`),
  KEY `fs_shift_user_fk` (`user_id`),
  KEY `fs_shift_job_fk` (`field_service_job_id`),
  KEY `fs_shift_created_by_fk` (`created_by_user_id`),
  CONSTRAINT `fs_shift_created_by_fk` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fs_shift_job_fk` FOREIGN KEY (`field_service_job_id`) REFERENCES `field_service_jobs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fs_shift_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fs_shift_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `financial_document_workspace_asset`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `financial_document_workspace_asset` (
  `tenant_id` bigint unsigned NOT NULL,
  `field_service_financial_document_id` bigint unsigned NOT NULL,
  `workspace_asset_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`field_service_financial_document_id`,`workspace_asset_id`),
  KEY `fin_doc_asset_tenant_fk` (`tenant_id`),
  KEY `fin_doc_asset_asset_fk` (`workspace_asset_id`),
  CONSTRAINT `fin_doc_asset_asset_fk` FOREIGN KEY (`workspace_asset_id`) REFERENCES `workspace_assets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fin_doc_asset_document_fk` FOREIGN KEY (`field_service_financial_document_id`) REFERENCES `field_service_financial_documents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fin_doc_asset_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `fleet_location_points`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fleet_location_points` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `fleet_tracking_device_id` bigint unsigned DEFAULT NULL,
  `field_service_vehicle_id` bigint unsigned DEFAULT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `field_service_time_session_id` bigint unsigned DEFAULT NULL,
  `source` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_key` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_type` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `latitude` decimal(10,7) NOT NULL,
  `longitude` decimal(10,7) NOT NULL,
  `accuracy_meters` int unsigned DEFAULT NULL,
  `recorded_at` timestamp NOT NULL,
  `received_at` timestamp NOT NULL,
  `safe_payload` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ft_point_event_unique` (`tenant_id`,`source`,`event_key`),
  KEY `ft_point_tenant_time_idx` (`tenant_id`,`recorded_at`),
  KEY `ft_point_vehicle_time_idx` (`tenant_id`,`field_service_vehicle_id`,`recorded_at`),
  KEY `ft_point_user_time_idx` (`tenant_id`,`user_id`,`recorded_at`),
  KEY `ft_point_device_fk` (`fleet_tracking_device_id`),
  KEY `ft_point_vehicle_fk` (`field_service_vehicle_id`),
  KEY `ft_point_user_fk` (`user_id`),
  KEY `ft_point_session_fk` (`field_service_time_session_id`),
  CONSTRAINT `ft_point_device_fk` FOREIGN KEY (`fleet_tracking_device_id`) REFERENCES `fleet_tracking_devices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `ft_point_session_fk` FOREIGN KEY (`field_service_time_session_id`) REFERENCES `field_service_time_sessions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `ft_point_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ft_point_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `ft_point_vehicle_fk` FOREIGN KEY (`field_service_vehicle_id`) REFERENCES `field_service_vehicles` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `fleet_tracking_devices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fleet_tracking_devices` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `field_service_vehicle_id` bigint unsigned NOT NULL,
  `provider` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'bouncie',
  `external_device_id` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `label` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `installed_at` timestamp NULL DEFAULT NULL,
  `uninstalled_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ft_device_provider_unique` (`tenant_id`,`provider`,`external_device_id`),
  UNIQUE KEY `ft_device_vehicle_unique` (`tenant_id`,`field_service_vehicle_id`),
  KEY `ft_device_vehicle_fk` (`field_service_vehicle_id`),
  CONSTRAINT `ft_device_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ft_device_vehicle_fk` FOREIGN KEY (`field_service_vehicle_id`) REFERENCES `field_service_vehicles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `fleet_tracking_policy_acknowledgements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fleet_tracking_policy_acknowledgements` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `policy_version` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `policy_sha256` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `accepted_at` timestamp NOT NULL,
  `acceptance_source` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'mobile',
  `device_context` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ft_ack_policy_unique` (`tenant_id`,`user_id`,`policy_version`),
  KEY `ft_ack_user_fk` (`user_id`),
  CONSTRAINT `ft_ack_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ft_ack_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `form_submissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `form_submissions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `tenant_form_id` bigint unsigned NOT NULL,
  `customer_access_request_id` bigint unsigned DEFAULT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'submitted',
  `source` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_key` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `submitter_name` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `submitter_email` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `submitter_phone` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `submitter_company` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payload` json DEFAULT NULL,
  `normalized_payload` json DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `form_submissions_source_key_unique` (`source_key`),
  KEY `form_submissions_tenant_id_foreign` (`tenant_id`),
  KEY `form_submissions_tenant_form_id_foreign` (`tenant_form_id`),
  KEY `form_submissions_customer_access_request_id_foreign` (`customer_access_request_id`),
  KEY `form_submissions_user_id_foreign` (`user_id`),
  KEY `form_submissions_status_index` (`status`),
  KEY `form_submissions_source_index` (`source`),
  KEY `form_submissions_submitted_at_index` (`submitted_at`),
  KEY `form_submissions_submitter_email_index` (`submitter_email`),
  CONSTRAINT `form_submissions_customer_access_request_id_foreign` FOREIGN KEY (`customer_access_request_id`) REFERENCES `customer_access_requests` (`id`) ON DELETE SET NULL,
  CONSTRAINT `form_submissions_tenant_form_id_foreign` FOREIGN KEY (`tenant_form_id`) REFERENCES `tenant_forms` (`id`) ON DELETE CASCADE,
  CONSTRAINT `form_submissions_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `form_submissions_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `form_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `form_templates` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `visibility` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'internal',
  `handler_key` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `schema` json DEFAULT NULL,
  `settings` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `form_templates_key_unique` (`key`),
  KEY `form_templates_status_index` (`status`),
  KEY `form_templates_visibility_index` (`visibility`),
  KEY `form_templates_handler_key_index` (`handler_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `google_business_profile_connections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `google_business_profile_connections` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `provider_key` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `connection_status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'disconnected',
  `connected_by_user_id` bigint unsigned DEFAULT NULL,
  `google_subject` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `google_account_label` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `access_token` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `refresh_token` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `token_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `granted_scopes` json DEFAULT NULL,
  `linked_account_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `linked_account_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `linked_account_display_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `linked_location_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `linked_location_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `linked_location_title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `linked_location_place_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `linked_location_maps_uri` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `project_approval_status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unknown',
  `connected_at` timestamp NULL DEFAULT NULL,
  `last_synced_at` timestamp NULL DEFAULT NULL,
  `last_error_code` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_error_message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `last_error_at` timestamp NULL DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `google_business_profile_connections_provider_key_unique` (`provider_key`),
  KEY `google_business_profile_connections_connected_by_user_id_foreign` (`connected_by_user_id`),
  KEY `gbp_connections_status_idx` (`connection_status`),
  KEY `gbp_connections_subject_idx` (`google_subject`),
  KEY `gbp_connections_expires_idx` (`expires_at`),
  KEY `gbp_connections_account_idx` (`linked_account_id`),
  KEY `gbp_connections_location_idx` (`linked_location_id`),
  KEY `gbp_connections_approval_idx` (`project_approval_status`),
  KEY `gbp_connections_connected_idx` (`connected_at`),
  KEY `gbp_connections_synced_idx` (`last_synced_at`),
  KEY `gbp_connections_error_idx` (`last_error_code`),
  KEY `gbp_connections_error_at_idx` (`last_error_at`),
  CONSTRAINT `google_business_profile_connections_connected_by_user_id_foreign` FOREIGN KEY (`connected_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `google_business_profile_locations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `google_business_profile_locations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `google_business_profile_connection_id` bigint unsigned NOT NULL,
  `account_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_display_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `location_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `location_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `store_code` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `website_uri` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `place_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `maps_uri` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `storefront_address` json DEFAULT NULL,
  `is_selected` tinyint(1) NOT NULL DEFAULT '0',
  `selected_at` timestamp NULL DEFAULT NULL,
  `last_seen_at` timestamp NULL DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `gbp_locations_connection_location_unique` (`google_business_profile_connection_id`,`location_name`),
  KEY `gbp_locations_account_idx` (`account_id`),
  KEY `gbp_locations_location_idx` (`location_id`),
  KEY `gbp_locations_selected_idx` (`is_selected`),
  KEY `gbp_locations_selected_at_idx` (`selected_at`),
  KEY `gbp_locations_seen_idx` (`last_seen_at`),
  CONSTRAINT `gbp_locations_connection_fk` FOREIGN KEY (`google_business_profile_connection_id`) REFERENCES `google_business_profile_connections` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `google_business_profile_reviews`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `google_business_profile_reviews` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `google_business_profile_connection_id` bigint unsigned NOT NULL,
  `marketing_profile_id` bigint unsigned DEFAULT NULL,
  `candle_cash_task_event_id` bigint unsigned DEFAULT NULL,
  `candle_cash_task_completion_id` bigint unsigned DEFAULT NULL,
  `marketing_storefront_event_id` bigint unsigned DEFAULT NULL,
  `external_review_id` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `review_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `account_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `account_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `location_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `location_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `star_rating` tinyint unsigned DEFAULT NULL,
  `reviewer_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reviewer_profile_photo_url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reviewer_is_anonymous` tinyint(1) NOT NULL DEFAULT '0',
  `comment` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `review_reply_comment` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_time` timestamp NULL DEFAULT NULL,
  `updated_time` timestamp NULL DEFAULT NULL,
  `sync_status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'synced',
  `matched_at` timestamp NULL DEFAULT NULL,
  `awarded_at` timestamp NULL DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `raw_payload` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `gbp_reviews_connection_external_unique` (`google_business_profile_connection_id`,`external_review_id`),
  KEY `google_business_profile_reviews_marketing_profile_id_foreign` (`marketing_profile_id`),
  KEY `gbp_reviews_task_event_fk` (`candle_cash_task_event_id`),
  KEY `gbp_reviews_completion_fk` (`candle_cash_task_completion_id`),
  KEY `gbp_reviews_storefront_fk` (`marketing_storefront_event_id`),
  KEY `gbp_reviews_account_idx` (`account_id`),
  KEY `gbp_reviews_location_idx` (`location_id`),
  KEY `gbp_reviews_reviewer_idx` (`reviewer_name`),
  KEY `gbp_reviews_anon_idx` (`reviewer_is_anonymous`),
  KEY `gbp_reviews_created_idx` (`created_time`),
  KEY `gbp_reviews_updated_idx` (`updated_time`),
  KEY `gbp_reviews_sync_status_idx` (`sync_status`),
  KEY `gbp_reviews_matched_idx` (`matched_at`),
  KEY `gbp_reviews_awarded_idx` (`awarded_at`),
  CONSTRAINT `gbp_reviews_completion_fk` FOREIGN KEY (`candle_cash_task_completion_id`) REFERENCES `candle_cash_task_completions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `gbp_reviews_connection_fk` FOREIGN KEY (`google_business_profile_connection_id`) REFERENCES `google_business_profile_connections` (`id`) ON DELETE CASCADE,
  CONSTRAINT `gbp_reviews_storefront_fk` FOREIGN KEY (`marketing_storefront_event_id`) REFERENCES `marketing_storefront_events` (`id`) ON DELETE SET NULL,
  CONSTRAINT `gbp_reviews_task_event_fk` FOREIGN KEY (`candle_cash_task_event_id`) REFERENCES `candle_cash_task_events` (`id`) ON DELETE SET NULL,
  CONSTRAINT `google_business_profile_reviews_marketing_profile_id_foreign` FOREIGN KEY (`marketing_profile_id`) REFERENCES `marketing_profiles` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `google_business_profile_sync_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `google_business_profile_sync_runs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `google_business_profile_connection_id` bigint unsigned NOT NULL,
  `triggered_by_user_id` bigint unsigned DEFAULT NULL,
  `trigger_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'running',
  `fetched_reviews_count` int unsigned NOT NULL DEFAULT '0',
  `new_reviews_count` int unsigned NOT NULL DEFAULT '0',
  `updated_reviews_count` int unsigned NOT NULL DEFAULT '0',
  `matched_reviews_count` int unsigned NOT NULL DEFAULT '0',
  `awarded_reviews_count` int unsigned NOT NULL DEFAULT '0',
  `duplicate_reviews_count` int unsigned NOT NULL DEFAULT '0',
  `unmatched_reviews_count` int unsigned NOT NULL DEFAULT '0',
  `error_code` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `error_message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `started_at` timestamp NULL DEFAULT NULL,
  `finished_at` timestamp NULL DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `google_business_profile_sync_runs_triggered_by_user_id_foreign` (`triggered_by_user_id`),
  KEY `gbp_sync_runs_connection_fk` (`google_business_profile_connection_id`),
  KEY `gbp_sync_runs_trigger_idx` (`trigger_type`),
  KEY `gbp_sync_runs_status_idx` (`status`),
  KEY `gbp_sync_runs_error_idx` (`error_code`),
  KEY `gbp_sync_runs_started_idx` (`started_at`),
  KEY `gbp_sync_runs_finished_idx` (`finished_at`),
  CONSTRAINT `gbp_sync_runs_connection_fk` FOREIGN KEY (`google_business_profile_connection_id`) REFERENCES `google_business_profile_connections` (`id`) ON DELETE CASCADE,
  CONSTRAINT `google_business_profile_sync_runs_triggered_by_user_id_foreign` FOREIGN KEY (`triggered_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `import_normalizations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `import_normalizations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `store_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `shopify_order_id` bigint unsigned DEFAULT NULL,
  `shopify_line_item_id` bigint unsigned DEFAULT NULL,
  `order_id` bigint unsigned DEFAULT NULL,
  `field` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `raw_value` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `normalized_value` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `context_json` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `tenant_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `in_tenant_idx` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `integration_connections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `integration_connections` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `provider` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `external_account_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `external_account_secret` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `external_account_label` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `access_token` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `refresh_token` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `token_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `oauth_client_id` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `oauth_client_secret` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `expires_at` timestamp NULL DEFAULT NULL,
  `scopes` json DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `connected_by_user_id` bigint unsigned DEFAULT NULL,
  `connected_at` timestamp NULL DEFAULT NULL,
  `last_synced_at` timestamp NULL DEFAULT NULL,
  `last_error_code` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_error_message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `last_error_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `integration_connections_tenant_provider_account_unique` (`tenant_id`,`provider`,`external_account_id`),
  KEY `integration_connections_tenant_id_provider_index` (`tenant_id`,`provider`),
  KEY `integration_connections_status_index` (`status`),
  KEY `integration_connections_expires_at_index` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `integration_health_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `integration_health_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned DEFAULT NULL,
  `shopify_store_id` bigint unsigned DEFAULT NULL,
  `store_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `provider` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_type` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `severity` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'info',
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `dedupe_key` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `related_model_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `related_model_id` bigint unsigned DEFAULT NULL,
  `context` json DEFAULT NULL,
  `occurred_at` timestamp NOT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `integration_health_events_tenant_id_index` (`tenant_id`),
  KEY `integration_health_events_shopify_store_id_index` (`shopify_store_id`),
  KEY `integration_health_events_store_key_index` (`store_key`),
  KEY `integration_health_events_provider_index` (`provider`),
  KEY `integration_health_events_event_type_index` (`event_type`),
  KEY `integration_health_events_severity_index` (`severity`),
  KEY `integration_health_events_status_index` (`status`),
  KEY `integration_health_events_dedupe_key_index` (`dedupe_key`),
  KEY `integration_health_events_occurred_at_index` (`occurred_at`),
  KEY `integration_health_events_resolved_at_index` (`resolved_at`),
  CONSTRAINT `integration_health_events_shopify_store_id_foreign` FOREIGN KEY (`shopify_store_id`) REFERENCES `shopify_stores` (`id`) ON DELETE SET NULL,
  CONSTRAINT `integration_health_events_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `inventory_adjustments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `inventory_adjustments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `item_type` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `base_oil_id` bigint unsigned DEFAULT NULL,
  `wax_inventory_id` bigint unsigned DEFAULT NULL,
  `grams_delta` decimal(12,2) NOT NULL,
  `before_grams` decimal(12,2) NOT NULL,
  `after_grams` decimal(12,2) NOT NULL,
  `reason` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `performed_by` bigint unsigned DEFAULT NULL,
  `source_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `inventory_adjustments_base_oil_id_foreign` (`base_oil_id`),
  KEY `inventory_adjustments_wax_inventory_id_foreign` (`wax_inventory_id`),
  KEY `inventory_adjustments_item_type_base_oil_id_index` (`item_type`,`base_oil_id`),
  KEY `inventory_adjustments_item_type_wax_inventory_id_index` (`item_type`,`wax_inventory_id`),
  KEY `inventory_adjustments_reason_index` (`reason`),
  KEY `inventory_adjustments_source_type_source_id_index` (`source_type`,`source_id`),
  KEY `inventory_adjustments_created_at_index` (`created_at`),
  CONSTRAINT `inventory_adjustments_base_oil_id_foreign` FOREIGN KEY (`base_oil_id`) REFERENCES `base_oils` (`id`) ON DELETE SET NULL,
  CONSTRAINT `inventory_adjustments_wax_inventory_id_foreign` FOREIGN KEY (`wax_inventory_id`) REFERENCES `wax_inventories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `inventory_counts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `inventory_counts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `scent_id` bigint unsigned NOT NULL,
  `size_id` bigint unsigned DEFAULT NULL,
  `on_hand_qty` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `inventory_counts_scent_id_size_id_unique` (`scent_id`,`size_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_batches` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_jobs` int NOT NULL,
  `pending_jobs` int NOT NULL,
  `failed_jobs` int NOT NULL,
  `failed_job_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `options` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `cancelled_at` int DEFAULT NULL,
  `created_at` int NOT NULL,
  `finished_at` int DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempts` tinyint unsigned NOT NULL,
  `reserved_at` int unsigned DEFAULT NULL,
  `available_at` int unsigned NOT NULL,
  `created_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `landlord_catalog_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `landlord_catalog_entries` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `entry_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `entry_key` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `is_public` tinyint(1) NOT NULL DEFAULT '1',
  `position` int unsigned NOT NULL DEFAULT '100',
  `currency` varchar(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'USD',
  `recurring_price_cents` int DEFAULT NULL,
  `recurring_interval` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'month',
  `setup_price_cents` int DEFAULT NULL,
  `payload` json DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `landlord_catalog_entries_type_key_unique` (`entry_type`,`entry_key`),
  KEY `landlord_catalog_entries_type_status_index` (`entry_type`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `landlord_operator_actions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `landlord_operator_actions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned DEFAULT NULL,
  `actor_user_id` bigint unsigned DEFAULT NULL,
  `action_type` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'success',
  `target_type` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `target_id` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `context` json DEFAULT NULL,
  `confirmation` json DEFAULT NULL,
  `before_state` json DEFAULT NULL,
  `after_state` json DEFAULT NULL,
  `result` json DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `landlord_operator_actions_tenant_id_index` (`tenant_id`),
  KEY `landlord_operator_actions_actor_user_id_index` (`actor_user_id`),
  KEY `landlord_operator_actions_action_type_index` (`action_type`),
  KEY `landlord_operator_actions_status_index` (`status`),
  KEY `landlord_operator_actions_target_type_index` (`target_type`),
  KEY `landlord_operator_actions_target_id_index` (`target_id`),
  CONSTRAINT `landlord_operator_actions_actor_user_id_foreign` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `landlord_operator_actions_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `landlord_prospect_communications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `landlord_prospect_communications` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `landlord_prospect_id` bigint unsigned NOT NULL,
  `direction` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'note',
  `channel` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'email',
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'logged',
  `subject` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `body` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `from_address` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `to_address` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `external_message_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `occurred_at` timestamp NOT NULL,
  `created_by_user_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `landlord_prospect_communications_landlord_prospect_id_index` (`landlord_prospect_id`),
  KEY `landlord_prospect_communications_direction_index` (`direction`),
  KEY `landlord_prospect_communications_channel_index` (`channel`),
  KEY `landlord_prospect_communications_status_index` (`status`),
  KEY `landlord_prospect_communications_external_message_id_index` (`external_message_id`),
  KEY `landlord_prospect_communications_occurred_at_index` (`occurred_at`),
  KEY `landlord_prospect_communications_created_by_user_id_index` (`created_by_user_id`),
  CONSTRAINT `landlord_prospect_communications_created_by_user_id_foreign` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `landlord_prospect_communications_landlord_prospect_id_foreign` FOREIGN KEY (`landlord_prospect_id`) REFERENCES `landlord_prospects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `landlord_prospect_discovery_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `landlord_prospect_discovery_runs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `provider` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'google_places',
  `trade` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `search_region` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `search_query` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `website_preference` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'missing_only',
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `maximum_results` smallint unsigned NOT NULL DEFAULT '10',
  `api_request_count` smallint unsigned NOT NULL DEFAULT '0',
  `estimated_api_cost` decimal(10,4) NOT NULL DEFAULT '0.0000',
  `actual_api_cost` decimal(10,4) NOT NULL DEFAULT '0.0000',
  `results_discovered` int unsigned NOT NULL DEFAULT '0',
  `results_created` int unsigned NOT NULL DEFAULT '0',
  `duplicates_suppressed` int unsigned NOT NULL DEFAULT '0',
  `website_missing_count` int unsigned NOT NULL DEFAULT '0',
  `source_log` json DEFAULT NULL,
  `error_message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_by_user_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `landlord_prospect_discovery_runs_trade_index` (`trade`),
  KEY `landlord_prospect_discovery_runs_website_preference_index` (`website_preference`),
  KEY `landlord_prospect_discovery_runs_status_index` (`status`),
  KEY `landlord_prospect_discovery_runs_created_by_user_id_index` (`created_by_user_id`),
  CONSTRAINT `landlord_prospect_discovery_runs_created_by_user_id_foreign` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `landlord_prospects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `landlord_prospects` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `business_name` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_name` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `trade` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `county` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `city` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `website` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `website_status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fit_score` tinyint unsigned DEFAULT NULL,
  `opportunity_priority` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `google_rating` decimal(3,2) DEFAULT NULL,
  `google_review_count` int unsigned DEFAULT NULL,
  `discovery_query` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_snapshot` json DEFAULT NULL,
  `last_verified_at` timestamp NULL DEFAULT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'new',
  `source` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `google_place_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `google_maps_url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `formatted_address` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `last_contacted_at` timestamp NULL DEFAULT NULL,
  `responded_at` timestamp NULL DEFAULT NULL,
  `next_follow_up_at` timestamp NULL DEFAULT NULL,
  `converted_tenant_id` bigint unsigned DEFAULT NULL,
  `converted_at` timestamp NULL DEFAULT NULL,
  `created_by_user_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `landlord_prospects_google_place_id_unique` (`google_place_id`),
  KEY `landlord_prospects_trade_index` (`trade`),
  KEY `landlord_prospects_county_index` (`county`),
  KEY `landlord_prospects_email_index` (`email`),
  KEY `landlord_prospects_status_index` (`status`),
  KEY `landlord_prospects_last_contacted_at_index` (`last_contacted_at`),
  KEY `landlord_prospects_responded_at_index` (`responded_at`),
  KEY `landlord_prospects_next_follow_up_at_index` (`next_follow_up_at`),
  KEY `landlord_prospects_converted_tenant_id_index` (`converted_tenant_id`),
  KEY `landlord_prospects_created_by_user_id_index` (`created_by_user_id`),
  KEY `landlord_prospects_website_status_index` (`website_status`),
  KEY `landlord_prospects_fit_score_index` (`fit_score`),
  KEY `landlord_prospects_opportunity_priority_index` (`opportunity_priority`),
  KEY `landlord_prospects_last_verified_at_index` (`last_verified_at`),
  CONSTRAINT `landlord_prospects_converted_tenant_id_foreign` FOREIGN KEY (`converted_tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `landlord_prospects_created_by_user_id_foreign` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mapping_exceptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `mapping_exceptions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `store_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `shopify_order_id` bigint unsigned DEFAULT NULL,
  `account_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `raw_scent_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `canonical_scent_id` bigint unsigned DEFAULT NULL,
  `shopify_line_item_id` bigint unsigned DEFAULT NULL,
  `order_id` bigint unsigned DEFAULT NULL,
  `order_line_id` bigint unsigned DEFAULT NULL,
  `raw_title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `raw_variant` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sku` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payload_json` json DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `excluded_at` timestamp NULL DEFAULT NULL,
  `excluded_by` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `excluded_reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `resolved_by` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `tenant_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `mapping_exceptions_canonical_scent_id_foreign` (`canonical_scent_id`),
  KEY `me_tenant_idx` (`tenant_id`),
  CONSTRAINT `mapping_exceptions_canonical_scent_id_foreign` FOREIGN KEY (`canonical_scent_id`) REFERENCES `scents` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `market_box_shipments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `market_box_shipments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `event_id` bigint unsigned NOT NULL,
  `item_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `product_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sku` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `scent` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `size` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `qty` int unsigned NOT NULL DEFAULT '0',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `raw_row` json DEFAULT NULL,
  `source_row_hash` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `market_box_shipments_event_id_source_row_hash_index` (`event_id`,`source_row_hash`),
  CONSTRAINT `market_box_shipments_event_id_foreign` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `market_event_sync_states`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `market_event_sync_states` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `sync_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'idle',
  `weeks` int unsigned NOT NULL DEFAULT '4',
  `queued_by_user_id` bigint unsigned DEFAULT NULL,
  `queued_at` timestamp NULL DEFAULT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `finished_at` timestamp NULL DEFAULT NULL,
  `last_sync_at` timestamp NULL DEFAULT NULL,
  `last_sync_status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_http_status` int unsigned DEFAULT NULL,
  `last_error` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `last_result` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `market_event_sync_states_sync_key_unique` (`sync_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `market_plans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `market_plans` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `event_title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_date` date DEFAULT NULL,
  `normalized_title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `box_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `scent` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `box_count` int unsigned NOT NULL DEFAULT '1',
  `top_shelf_definition_json` json DEFAULT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `market_plans_norm_title_event_date_idx` (`normalized_title`,`event_date`),
  KEY `market_plans_normalized_title_index` (`normalized_title`),
  KEY `market_plans_status_index` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `market_pour_list_event_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `market_pour_list_event_lines` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `market_pour_list_id` bigint unsigned NOT NULL,
  `event_id` bigint unsigned NOT NULL,
  `scent_id` bigint unsigned DEFAULT NULL,
  `size_id` bigint unsigned DEFAULT NULL,
  `wick_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recommended_qty` int unsigned NOT NULL DEFAULT '0',
  `edited_qty` int unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `market_pour_list_event_lines_market_pour_list_id_foreign` (`market_pour_list_id`),
  KEY `market_pour_list_event_lines_event_id_foreign` (`event_id`),
  KEY `market_pour_list_event_lines_scent_id_foreign` (`scent_id`),
  KEY `market_pour_list_event_lines_size_id_foreign` (`size_id`),
  CONSTRAINT `market_pour_list_event_lines_event_id_foreign` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `market_pour_list_event_lines_market_pour_list_id_foreign` FOREIGN KEY (`market_pour_list_id`) REFERENCES `market_pour_lists` (`id`) ON DELETE CASCADE,
  CONSTRAINT `market_pour_list_event_lines_scent_id_foreign` FOREIGN KEY (`scent_id`) REFERENCES `scents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `market_pour_list_event_lines_size_id_foreign` FOREIGN KEY (`size_id`) REFERENCES `sizes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `market_pour_list_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `market_pour_list_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `market_pour_list_id` bigint unsigned NOT NULL,
  `event_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `market_pour_list_events_market_pour_list_id_foreign` (`market_pour_list_id`),
  KEY `market_pour_list_events_event_id_foreign` (`event_id`),
  CONSTRAINT `market_pour_list_events_event_id_foreign` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `market_pour_list_events_market_pour_list_id_foreign` FOREIGN KEY (`market_pour_list_id`) REFERENCES `market_pour_lists` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `market_pour_list_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `market_pour_list_lines` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `market_pour_list_id` bigint unsigned NOT NULL,
  `scent_id` bigint unsigned DEFAULT NULL,
  `size_id` bigint unsigned DEFAULT NULL,
  `wick_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recommended_qty` int unsigned NOT NULL DEFAULT '0',
  `edited_qty` int unsigned DEFAULT NULL,
  `reason_json` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `market_pour_list_lines_market_pour_list_id_foreign` (`market_pour_list_id`),
  KEY `market_pour_list_lines_scent_id_foreign` (`scent_id`),
  KEY `market_pour_list_lines_size_id_foreign` (`size_id`),
  CONSTRAINT `market_pour_list_lines_market_pour_list_id_foreign` FOREIGN KEY (`market_pour_list_id`) REFERENCES `market_pour_lists` (`id`) ON DELETE CASCADE,
  CONSTRAINT `market_pour_list_lines_scent_id_foreign` FOREIGN KEY (`scent_id`) REFERENCES `scents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `market_pour_list_lines_size_id_foreign` FOREIGN KEY (`size_id`) REFERENCES `sizes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `market_pour_lists`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `market_pour_lists` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `event_id` bigint unsigned DEFAULT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `generated_at` timestamp NULL DEFAULT NULL,
  `published_at` timestamp NULL DEFAULT NULL,
  `generated_by_user_id` bigint unsigned DEFAULT NULL,
  `created_by_user_id` bigint unsigned DEFAULT NULL,
  `published_by_user_id` bigint unsigned DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `market_pour_lists_event_id_status_index` (`event_id`,`status`),
  CONSTRAINT `market_pour_lists_event_id_foreign` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `marketing_automation_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `marketing_automation_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned DEFAULT NULL,
  `marketing_profile_id` bigint unsigned DEFAULT NULL,
  `trigger_key` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `channel` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'queued_intent',
  `store_key` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `context` json DEFAULT NULL,
  `occurred_at` timestamp NOT NULL,
  `processed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `marketing_automation_events_marketing_profile_id_foreign` (`marketing_profile_id`),
  KEY `marketing_automation_dedupe_idx` (`tenant_id`,`marketing_profile_id`,`trigger_key`,`channel`,`occurred_at`),
  KEY `marketing_automation_events_tenant_id_index` (`tenant_id`),
  KEY `marketing_automation_events_trigger_key_index` (`trigger_key`),
  KEY `marketing_automation_events_channel_index` (`channel`),
  KEY `marketing_automation_events_status_index` (`status`),
  KEY `marketing_automation_events_store_key_index` (`store_key`),
  KEY `marketing_automation_events_occurred_at_index` (`occurred_at`),
  KEY `marketing_automation_events_processed_at_index` (`processed_at`),
  CONSTRAINT `marketing_automation_events_marketing_profile_id_foreign` FOREIGN KEY (`marketing_profile_id`) REFERENCES `marketing_profiles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `marketing_automation_events_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `marketing_campaign_conversions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `marketing_campaign_conversions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `campaign_id` bigint unsigned NOT NULL,
  `marketing_profile_id` bigint unsigned NOT NULL,
  `campaign_recipient_id` bigint unsigned DEFAULT NULL,
  `attribution_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `converted_at` timestamp NOT NULL,
  `order_total` decimal(10,2) DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `attribution_snapshot` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `marketing_campaign_conversions_campaign_id_foreign` (`campaign_id`),
  KEY `marketing_campaign_conversions_marketing_profile_id_foreign` (`marketing_profile_id`),
  KEY `marketing_campaign_conversions_campaign_recipient_id_foreign` (`campaign_recipient_id`),
  KEY `marketing_campaign_conversions_attribution_type_index` (`attribution_type`),
  KEY `marketing_campaign_conversions_source_type_index` (`source_type`),
  KEY `marketing_campaign_conversions_source_id_index` (`source_id`),
  KEY `marketing_campaign_conversions_converted_at_index` (`converted_at`),
  CONSTRAINT `marketing_campaign_conversions_campaign_id_foreign` FOREIGN KEY (`campaign_id`) REFERENCES `marketing_campaigns` (`id`) ON DELETE CASCADE,
  CONSTRAINT `marketing_campaign_conversions_campaign_recipient_id_foreign` FOREIGN KEY (`campaign_recipient_id`) REFERENCES `marketing_campaign_recipients` (`id`) ON DELETE SET NULL,
  CONSTRAINT `marketing_campaign_conversions_marketing_profile_id_foreign` FOREIGN KEY (`marketing_profile_id`) REFERENCES `marketing_profiles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `marketing_campaign_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `marketing_campaign_groups` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `campaign_id` bigint unsigned NOT NULL,
  `marketing_group_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mcg_campaign_group_unique` (`campaign_id`,`marketing_group_id`),
  KEY `marketing_campaign_groups_marketing_group_id_foreign` (`marketing_group_id`),
  CONSTRAINT `marketing_campaign_groups_campaign_id_foreign` FOREIGN KEY (`campaign_id`) REFERENCES `marketing_campaigns` (`id`) ON DELETE CASCADE,
  CONSTRAINT `marketing_campaign_groups_marketing_group_id_foreign` FOREIGN KEY (`marketing_group_id`) REFERENCES `marketing_groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `marketing_campaign_recipients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `marketing_campaign_recipients` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `campaign_id` bigint unsigned NOT NULL,
  `marketing_profile_id` bigint unsigned NOT NULL,
  `segment_snapshot` json DEFAULT NULL,
  `recommendation_snapshot` json DEFAULT NULL,
  `variant_id` bigint unsigned DEFAULT NULL,
  `channel` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `send_attempt_count` int unsigned NOT NULL DEFAULT '0',
  `last_send_attempt_at` timestamp NULL DEFAULT NULL,
  `reason_codes` json DEFAULT NULL,
  `scheduled_for` timestamp NULL DEFAULT NULL,
  `approved_by` bigint unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `delivered_at` timestamp NULL DEFAULT NULL,
  `failed_at` timestamp NULL DEFAULT NULL,
  `rejected_by` bigint unsigned DEFAULT NULL,
  `rejected_at` timestamp NULL DEFAULT NULL,
  `last_status_note` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mcr_campaign_profile_unique` (`campaign_id`,`marketing_profile_id`),
  KEY `marketing_campaign_recipients_marketing_profile_id_foreign` (`marketing_profile_id`),
  KEY `marketing_campaign_recipients_variant_id_foreign` (`variant_id`),
  KEY `marketing_campaign_recipients_approved_by_foreign` (`approved_by`),
  KEY `marketing_campaign_recipients_rejected_by_foreign` (`rejected_by`),
  KEY `marketing_campaign_recipients_channel_index` (`channel`),
  KEY `marketing_campaign_recipients_status_index` (`status`),
  CONSTRAINT `marketing_campaign_recipients_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `marketing_campaign_recipients_campaign_id_foreign` FOREIGN KEY (`campaign_id`) REFERENCES `marketing_campaigns` (`id`) ON DELETE CASCADE,
  CONSTRAINT `marketing_campaign_recipients_marketing_profile_id_foreign` FOREIGN KEY (`marketing_profile_id`) REFERENCES `marketing_profiles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `marketing_campaign_recipients_rejected_by_foreign` FOREIGN KEY (`rejected_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `marketing_campaign_recipients_variant_id_foreign` FOREIGN KEY (`variant_id`) REFERENCES `marketing_campaign_variants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `marketing_campaign_variants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `marketing_campaign_variants` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `campaign_id` bigint unsigned NOT NULL,
  `template_id` bigint unsigned DEFAULT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `variant_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `message_text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `weight` int unsigned NOT NULL DEFAULT '100',
  `is_control` tinyint(1) NOT NULL DEFAULT '0',
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `marketing_campaign_variants_template_id_foreign` (`template_id`),
  KEY `mcv_campaign_status_idx` (`campaign_id`,`status`),
  KEY `marketing_campaign_variants_status_index` (`status`),
  CONSTRAINT `marketing_campaign_variants_campaign_id_foreign` FOREIGN KEY (`campaign_id`) REFERENCES `marketing_campaigns` (`id`) ON DELETE CASCADE,
  CONSTRAINT `marketing_campaign_variants_template_id_foreign` FOREIGN KEY (`template_id`) REFERENCES `marketing_message_templates` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `marketing_campaigns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `marketing_campaigns` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned DEFAULT NULL,
  `store_key` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `message_subject` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `message_body` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `message_html` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `target_snapshot` json DEFAULT NULL,
  `status_counts` json DEFAULT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `channel` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'sms',
  `source_label` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `segment_id` bigint unsigned DEFAULT NULL,
  `objective` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `attribution_window_days` int unsigned NOT NULL DEFAULT '7',
  `coupon_code` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `send_window_json` json DEFAULT NULL,
  `quiet_hours_override_json` json DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `launched_at` timestamp NULL DEFAULT NULL,
  `queued_at` timestamp NULL DEFAULT NULL,
  `scheduled_for` timestamp NULL DEFAULT NULL,
  `test_sent_at` timestamp NULL DEFAULT NULL,
  `template_instance_id` bigint unsigned DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `marketing_campaigns_tenant_slug_unique` (`tenant_id`,`slug`),
  KEY `marketing_campaigns_segment_id_foreign` (`segment_id`),
  KEY `marketing_campaigns_created_by_foreign` (`created_by`),
  KEY `marketing_campaigns_updated_by_foreign` (`updated_by`),
  KEY `marketing_campaigns_status_index` (`status`),
  KEY `marketing_campaigns_channel_index` (`channel`),
  KEY `marketing_campaigns_objective_index` (`objective`),
  KEY `marketing_campaigns_tenant_id_index` (`tenant_id`),
  KEY `marketing_campaigns_source_label_idx` (`source_label`),
  KEY `marketing_campaigns_store_key_idx` (`store_key`),
  KEY `marketing_campaigns_scheduled_for_idx` (`scheduled_for`),
  KEY `marketing_campaigns_template_instance_idx` (`template_instance_id`),
  CONSTRAINT `marketing_campaigns_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `marketing_campaigns_segment_id_foreign` FOREIGN KEY (`segment_id`) REFERENCES `marketing_segments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `marketing_campaigns_template_instance_fk` FOREIGN KEY (`template_instance_id`) REFERENCES `marketing_template_instances` (`id`) ON DELETE SET NULL,
  CONSTRAINT `marketing_campaigns_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `marketing_campaigns_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `marketing_consent_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `marketing_consent_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `marketing_profile_id` bigint unsigned NOT NULL,
  `tenant_id` bigint unsigned DEFAULT NULL,
  `channel` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `details` json DEFAULT NULL,
  `occurred_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `marketing_consent_events_marketing_profile_id_foreign` (`marketing_profile_id`),
  KEY `marketing_consent_events_channel_index` (`channel`),
  KEY `marketing_consent_events_event_type_index` (`event_type`),
  KEY `marketing_consent_events_source_type_index` (`source_type`),
  KEY `marketing_consent_events_source_id_index` (`source_id`),
  KEY `marketing_consent_events_occurred_at_index` (`occurred_at`),
  KEY `marketing_consent_events_tenant_id_index` (`tenant_id`),
  KEY `mce_tenant_channel_profile_occurred_id_idx` (`tenant_id`,`channel`,`marketing_profile_id`,`occurred_at`,`id`),
  KEY `mce_tenant_channel_event_source_profile_idx` (`tenant_id`,`channel`(32),`marketing_profile_id`,`event_type`(32),`source_type`(64)),
  CONSTRAINT `marketing_consent_events_marketing_profile_id_foreign` FOREIGN KEY (`marketing_profile_id`) REFERENCES `marketing_profiles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `marketing_consent_events_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `marketing_consent_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `marketing_consent_requests` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `marketing_profile_id` bigint unsigned DEFAULT NULL,
  `tenant_id` bigint unsigned DEFAULT NULL,
  `channel` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'sms',
  `token` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'requested',
  `source_type` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_id` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payload` json DEFAULT NULL,
  `requested_at` timestamp NULL DEFAULT NULL,
  `confirmed_at` timestamp NULL DEFAULT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `reward_awarded_points` int unsigned NOT NULL DEFAULT '0',
  `reward_awarded_candle_cash` int unsigned NOT NULL DEFAULT '0',
  `reward_awarded_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `marketing_consent_requests_token_unique` (`token`),
  KEY `marketing_consent_requests_marketing_profile_id_foreign` (`marketing_profile_id`),
  KEY `marketing_consent_requests_channel_index` (`channel`),
  KEY `marketing_consent_requests_status_index` (`status`),
  KEY `marketing_consent_requests_source_type_index` (`source_type`),
  KEY `marketing_consent_requests_source_id_index` (`source_id`),
  KEY `marketing_consent_requests_requested_at_index` (`requested_at`),
  KEY `marketing_consent_requests_confirmed_at_index` (`confirmed_at`),
  KEY `marketing_consent_requests_revoked_at_index` (`revoked_at`),
  KEY `marketing_consent_requests_expires_at_index` (`expires_at`),
  KEY `marketing_consent_requests_reward_awarded_at_index` (`reward_awarded_at`),
  KEY `marketing_consent_requests_tenant_id_index` (`tenant_id`),
  CONSTRAINT `marketing_consent_requests_marketing_profile_id_foreign` FOREIGN KEY (`marketing_profile_id`) REFERENCES `marketing_profiles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `marketing_consent_requests_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `marketing_delivery_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `marketing_delivery_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `marketing_message_delivery_id` bigint unsigned DEFAULT NULL,
  `provider` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `provider_message_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `event_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `event_hash` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payload` json DEFAULT NULL,
  `occurred_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `marketing_delivery_events_event_hash_unique` (`event_hash`),
  KEY `marketing_delivery_events_marketing_message_delivery_id_foreign` (`marketing_message_delivery_id`),
  KEY `marketing_delivery_events_provider_index` (`provider`),
  KEY `marketing_delivery_events_provider_message_id_index` (`provider_message_id`),
  KEY `marketing_delivery_events_event_type_index` (`event_type`),
  KEY `marketing_delivery_events_event_status_index` (`event_status`),
  CONSTRAINT `marketing_delivery_events_marketing_message_delivery_id_foreign` FOREIGN KEY (`marketing_message_delivery_id`) REFERENCES `marketing_message_deliveries` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `marketing_email_deliveries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `marketing_email_deliveries` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `marketing_campaign_recipient_id` bigint unsigned DEFAULT NULL,
  `marketing_profile_id` bigint unsigned DEFAULT NULL,
  `tenant_id` bigint unsigned DEFAULT NULL,
  `store_key` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `batch_id` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_label` varchar(140) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `message_subject` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `provider` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `provider_message_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `campaign_type` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `template_key` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sendgrid_message_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'queued',
  `sent_at` timestamp NULL DEFAULT NULL,
  `delivered_at` timestamp NULL DEFAULT NULL,
  `opened_at` timestamp NULL DEFAULT NULL,
  `clicked_at` timestamp NULL DEFAULT NULL,
  `failed_at` timestamp NULL DEFAULT NULL,
  `raw_payload` json DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `marketing_email_deliveries_marketing_profile_id_foreign` (`marketing_profile_id`),
  KEY `med_recipient_status_idx` (`marketing_campaign_recipient_id`,`status`),
  KEY `marketing_email_deliveries_sendgrid_message_id_index` (`sendgrid_message_id`),
  KEY `marketing_email_deliveries_email_index` (`email`),
  KEY `marketing_email_deliveries_status_index` (`status`),
  KEY `marketing_email_deliveries_tenant_id_index` (`tenant_id`),
  KEY `marketing_email_deliveries_provider_index` (`provider`),
  KEY `marketing_email_deliveries_provider_message_id_index` (`provider_message_id`),
  KEY `marketing_email_deliveries_campaign_type_index` (`campaign_type`),
  KEY `marketing_email_deliveries_template_key_index` (`template_key`),
  KEY `marketing_email_deliveries_store_key_idx` (`store_key`),
  KEY `marketing_email_deliveries_batch_id_idx` (`batch_id`),
  KEY `marketing_email_deliveries_source_label_idx` (`source_label`),
  KEY `marketing_email_deliveries_message_subject_idx` (`message_subject`),
  CONSTRAINT `marketing_email_deliveries_marketing_profile_id_foreign` FOREIGN KEY (`marketing_profile_id`) REFERENCES `marketing_profiles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `marketing_email_deliveries_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `med_recipient_fk` FOREIGN KEY (`marketing_campaign_recipient_id`) REFERENCES `marketing_campaign_recipients` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `marketing_event_source_mappings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `marketing_event_source_mappings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned DEFAULT NULL,
  `source_system` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `raw_value` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `normalized_value` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `event_instance_id` bigint unsigned DEFAULT NULL,
  `confidence` decimal(5,2) DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mesm_tenant_source_raw_unique_idx` (`tenant_id`,`source_system`,`raw_value`),
  KEY `marketing_event_source_mappings_event_instance_id_foreign` (`event_instance_id`),
  KEY `marketing_event_source_mappings_raw_value_index` (`raw_value`),
  KEY `marketing_event_source_mappings_normalized_value_index` (`normalized_value`),
  KEY `marketing_event_source_mappings_tenant_id_index` (`tenant_id`),
  CONSTRAINT `marketing_event_source_mappings_event_instance_id_foreign` FOREIGN KEY (`event_instance_id`) REFERENCES `event_instances` (`id`) ON DELETE SET NULL,
  CONSTRAINT `marketing_event_source_mappings_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `marketing_external_campaign_stats`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `marketing_external_campaign_stats` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `marketing_profile_id` bigint unsigned NOT NULL,
  `source_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `external_contact_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sends_count` int unsigned NOT NULL DEFAULT '0',
  `opens_count` int unsigned NOT NULL DEFAULT '0',
  `clicks_count` int unsigned NOT NULL DEFAULT '0',
  `unsubscribed_at` timestamp NULL DEFAULT NULL,
  `last_engaged_at` timestamp NULL DEFAULT NULL,
  `raw_payload` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mecs_profile_source_external_unique` (`marketing_profile_id`,`source_type`,`external_contact_id`),
  KEY `marketing_external_campaign_stats_source_type_index` (`source_type`),
  KEY `marketing_external_campaign_stats_external_contact_id_index` (`external_contact_id`),
  KEY `marketing_external_campaign_stats_unsubscribed_at_index` (`unsubscribed_at`),
  KEY `marketing_external_campaign_stats_last_engaged_at_index` (`last_engaged_at`),
  CONSTRAINT `marketing_external_campaign_stats_marketing_profile_id_foreign` FOREIGN KEY (`marketing_profile_id`) REFERENCES `marketing_profiles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `marketing_group_import_rows`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `marketing_group_import_rows` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `marketing_group_import_run_id` bigint unsigned NOT NULL,
  `row_number` int unsigned DEFAULT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `external_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `marketing_profile_id` bigint unsigned DEFAULT NULL,
  `messages` json DEFAULT NULL,
  `payload` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `mgir_import_run_fk` (`marketing_group_import_run_id`),
  KEY `marketing_group_import_rows_marketing_profile_id_foreign` (`marketing_profile_id`),
  KEY `marketing_group_import_rows_row_number_index` (`row_number`),
  KEY `marketing_group_import_rows_status_index` (`status`),
  KEY `marketing_group_import_rows_external_key_index` (`external_key`),
  CONSTRAINT `marketing_group_import_rows_marketing_profile_id_foreign` FOREIGN KEY (`marketing_profile_id`) REFERENCES `marketing_profiles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mgir_import_run_fk` FOREIGN KEY (`marketing_group_import_run_id`) REFERENCES `marketing_group_import_runs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `marketing_group_import_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `marketing_group_import_runs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `marketing_group_id` bigint unsigned DEFAULT NULL,
  `file_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'running',
  `started_at` timestamp NULL DEFAULT NULL,
  `finished_at` timestamp NULL DEFAULT NULL,
  `summary` json DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `marketing_group_import_runs_marketing_group_id_foreign` (`marketing_group_id`),
  KEY `marketing_group_import_runs_created_by_foreign` (`created_by`),
  KEY `marketing_group_import_runs_status_index` (`status`),
  KEY `marketing_group_import_runs_started_at_index` (`started_at`),
  KEY `marketing_group_import_runs_finished_at_index` (`finished_at`),
  CONSTRAINT `marketing_group_import_runs_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `marketing_group_import_runs_marketing_group_id_foreign` FOREIGN KEY (`marketing_group_id`) REFERENCES `marketing_groups` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `marketing_group_members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `marketing_group_members` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `marketing_group_id` bigint unsigned NOT NULL,
  `marketing_profile_id` bigint unsigned NOT NULL,
  `added_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mgm_group_profile_unique` (`marketing_group_id`,`marketing_profile_id`),
  KEY `marketing_group_members_added_by_foreign` (`added_by`),
  KEY `mgm_profile_group_idx` (`marketing_profile_id`,`marketing_group_id`),
  CONSTRAINT `marketing_group_members_added_by_foreign` FOREIGN KEY (`added_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `marketing_group_members_marketing_group_id_foreign` FOREIGN KEY (`marketing_group_id`) REFERENCES `marketing_groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `marketing_group_members_marketing_profile_id_foreign` FOREIGN KEY (`marketing_profile_id`) REFERENCES `marketing_profiles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `marketing_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `marketing_groups` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `is_internal` tinyint(1) NOT NULL DEFAULT '0',
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `marketing_groups_created_by_foreign` (`created_by`),
  KEY `marketing_groups_is_internal_index` (`is_internal`),
  CONSTRAINT `marketing_groups_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `marketing_identity_reviews`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `marketing_identity_reviews` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `proposed_marketing_profile_id` bigint unsigned DEFAULT NULL,
  `raw_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `raw_phone` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `raw_first_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `raw_last_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `conflict_reasons` json DEFAULT NULL,
  `payload` json DEFAULT NULL,
  `reviewed_by` bigint unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `resolution_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `marketing_identity_reviews_proposed_marketing_profile_id_foreign` (`proposed_marketing_profile_id`),
  KEY `marketing_identity_reviews_reviewed_by_foreign` (`reviewed_by`),
  KEY `mir_source_lookup_idx` (`source_type`,`source_id`),
  KEY `marketing_identity_reviews_status_index` (`status`),
  KEY `marketing_identity_reviews_raw_email_index` (`raw_email`),
  KEY `marketing_identity_reviews_raw_phone_index` (`raw_phone`),
  CONSTRAINT `marketing_identity_reviews_proposed_marketing_profile_id_foreign` FOREIGN KEY (`proposed_marketing_profile_id`) REFERENCES `marketing_profiles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `marketing_identity_reviews_reviewed_by_foreign` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `marketing_import_rows`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `marketing_import_rows` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `marketing_import_run_id` bigint unsigned NOT NULL,
  `row_number` int unsigned DEFAULT NULL,
  `external_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'imported',
  `messages` json DEFAULT NULL,
  `payload` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `mir_run_status_idx` (`marketing_import_run_id`,`status`),
  KEY `marketing_import_rows_external_key_index` (`external_key`),
  KEY `marketing_import_rows_status_index` (`status`),
  CONSTRAINT `marketing_import_rows_marketing_import_run_id_foreign` FOREIGN KEY (`marketing_import_run_id`) REFERENCES `marketing_import_runs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `marketing_import_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `marketing_import_runs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'running',
  `source_label` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `finished_at` timestamp NULL DEFAULT NULL,
  `summary` json DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `tenant_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `marketing_import_runs_created_by_foreign` (`created_by`),
  KEY `marketing_import_runs_type_index` (`type`),
  KEY `marketing_import_runs_status_index` (`status`),
  KEY `marketing_import_runs_started_at_index` (`started_at`),
  KEY `marketing_import_runs_finished_at_index` (`finished_at`),
  KEY `marketing_import_runs_tenant_id_index` (`tenant_id`),
  CONSTRAINT `marketing_import_runs_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `marketing_import_runs_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `marketing_message_deliveries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `marketing_message_deliveries` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `campaign_id` bigint unsigned DEFAULT NULL,
  `campaign_recipient_id` bigint unsigned DEFAULT NULL,
  `marketing_profile_id` bigint unsigned NOT NULL,
  `tenant_id` bigint unsigned DEFAULT NULL,
  `store_key` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `batch_id` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_label` varchar(140) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `message_subject` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `channel` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `provider` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `provider_message_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `to_phone` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `from_identifier` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `variant_id` bigint unsigned DEFAULT NULL,
  `attempt_number` int unsigned NOT NULL DEFAULT '1',
  `rendered_message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `send_status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'queued',
  `error_code` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `error_message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `provider_payload` json DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `delivered_at` timestamp NULL DEFAULT NULL,
  `failed_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mmd_provider_message_unique` (`provider`,`provider_message_id`),
  KEY `marketing_message_deliveries_campaign_recipient_id_foreign` (`campaign_recipient_id`),
  KEY `marketing_message_deliveries_marketing_profile_id_foreign` (`marketing_profile_id`),
  KEY `marketing_message_deliveries_variant_id_foreign` (`variant_id`),
  KEY `marketing_message_deliveries_created_by_foreign` (`created_by`),
  KEY `mmd_campaign_status_idx` (`campaign_id`,`send_status`),
  KEY `marketing_message_deliveries_channel_index` (`channel`),
  KEY `marketing_message_deliveries_provider_index` (`provider`),
  KEY `marketing_message_deliveries_provider_message_id_index` (`provider_message_id`),
  KEY `marketing_message_deliveries_send_status_index` (`send_status`),
  KEY `marketing_message_deliveries_tenant_id_idx` (`tenant_id`),
  KEY `marketing_message_deliveries_store_key_idx` (`store_key`),
  KEY `marketing_message_deliveries_batch_id_idx` (`batch_id`),
  KEY `marketing_message_deliveries_source_label_idx` (`source_label`),
  KEY `marketing_message_deliveries_message_subject_idx` (`message_subject`),
  CONSTRAINT `marketing_message_deliveries_campaign_id_foreign` FOREIGN KEY (`campaign_id`) REFERENCES `marketing_campaigns` (`id`) ON DELETE SET NULL,
  CONSTRAINT `marketing_message_deliveries_campaign_recipient_id_foreign` FOREIGN KEY (`campaign_recipient_id`) REFERENCES `marketing_campaign_recipients` (`id`) ON DELETE SET NULL,
  CONSTRAINT `marketing_message_deliveries_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `marketing_message_deliveries_marketing_profile_id_foreign` FOREIGN KEY (`marketing_profile_id`) REFERENCES `marketing_profiles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `marketing_message_deliveries_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `marketing_message_deliveries_variant_id_foreign` FOREIGN KEY (`variant_id`) REFERENCES `marketing_campaign_variants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `marketing_message_engagement_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `marketing_message_engagement_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned DEFAULT NULL,
  `store_key` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `marketing_email_delivery_id` bigint unsigned DEFAULT NULL,
  `marketing_message_delivery_id` bigint unsigned DEFAULT NULL,
  `marketing_profile_id` bigint unsigned DEFAULT NULL,
  `channel` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_type` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_hash` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `provider` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `provider_event_id` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `provider_message_id` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `link_label` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `url` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `normalized_url` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `url_domain` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip_address` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `payload` json DEFAULT NULL,
  `occurred_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `marketing_message_engagement_events_event_hash_unique` (`event_hash`),
  KEY `mm_engagement_tenant_store_type_time_idx` (`tenant_id`,`store_key`,`event_type`,`occurred_at`),
  KEY `mm_engagement_tenant_profile_type_time_idx` (`tenant_id`,`marketing_profile_id`,`event_type`,`occurred_at`),
  KEY `mm_engagement_tenant_email_type_idx` (`tenant_id`,`marketing_email_delivery_id`,`event_type`),
  KEY `mm_eng_evt_email_delivery_fk` (`marketing_email_delivery_id`),
  KEY `mm_eng_evt_msg_delivery_fk` (`marketing_message_delivery_id`),
  KEY `mm_eng_evt_profile_fk` (`marketing_profile_id`),
  KEY `marketing_message_engagement_events_tenant_id_index` (`tenant_id`),
  KEY `marketing_message_engagement_events_store_key_index` (`store_key`),
  KEY `marketing_message_engagement_events_channel_index` (`channel`),
  KEY `marketing_message_engagement_events_event_type_index` (`event_type`),
  KEY `marketing_message_engagement_events_provider_index` (`provider`),
  KEY `marketing_message_engagement_events_provider_event_id_index` (`provider_event_id`),
  KEY `marketing_message_engagement_events_provider_message_id_index` (`provider_message_id`),
  KEY `marketing_message_engagement_events_url_domain_index` (`url_domain`),
  KEY `marketing_message_engagement_events_occurred_at_index` (`occurred_at`),
  CONSTRAINT `mm_eng_evt_email_delivery_fk` FOREIGN KEY (`marketing_email_delivery_id`) REFERENCES `marketing_email_deliveries` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mm_eng_evt_msg_delivery_fk` FOREIGN KEY (`marketing_message_delivery_id`) REFERENCES `marketing_message_deliveries` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mm_eng_evt_profile_fk` FOREIGN KEY (`marketing_profile_id`) REFERENCES `marketing_profiles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mm_engagement_events_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `marketing_message_group_members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `marketing_message_group_members` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `marketing_message_group_id` bigint unsigned NOT NULL,
  `marketing_profile_id` bigint unsigned DEFAULT NULL,
  `source_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'profile',
  `full_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `normalized_phone` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `marketing_message_group_members_marketing_profile_id_foreign` (`marketing_profile_id`),
  KEY `mmgm_group_phone_idx` (`marketing_message_group_id`,`normalized_phone`),
  KEY `mmgm_group_profile_idx` (`marketing_message_group_id`,`marketing_profile_id`),
  KEY `marketing_message_group_members_source_type_index` (`source_type`),
  KEY `marketing_message_group_members_email_index` (`email`),
  KEY `marketing_message_group_members_normalized_phone_index` (`normalized_phone`),
  CONSTRAINT `marketing_message_group_members_marketing_profile_id_foreign` FOREIGN KEY (`marketing_profile_id`) REFERENCES `marketing_profiles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mmgm_message_group_fk` FOREIGN KEY (`marketing_message_group_id`) REFERENCES `marketing_message_groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `marketing_message_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `marketing_message_groups` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned DEFAULT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `channel` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'sms',
  `is_reusable` tinyint(1) NOT NULL DEFAULT '1',
  `is_system` tinyint(1) NOT NULL DEFAULT '0',
  `system_key` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_by` bigint unsigned DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mmg_tenant_system_unique` (`tenant_id`,`system_key`),
  KEY `marketing_message_groups_created_by_foreign` (`created_by`),
  KEY `marketing_message_groups_channel_index` (`channel`),
  KEY `marketing_message_groups_is_reusable_index` (`is_reusable`),
  KEY `marketing_message_groups_last_used_at_index` (`last_used_at`),
  KEY `mmg_tenant_id_idx` (`tenant_id`),
  KEY `mmg_is_system_idx` (`is_system`),
  KEY `mmg_system_key_idx` (`system_key`),
  CONSTRAINT `marketing_message_groups_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mmg_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `marketing_message_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `marketing_message_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `campaign_id` bigint unsigned DEFAULT NULL,
  `campaign_recipient_id` bigint unsigned DEFAULT NULL,
  `marketing_profile_id` bigint unsigned DEFAULT NULL,
  `tenant_id` bigint unsigned DEFAULT NULL,
  `store_key` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `channel` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `job_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'send',
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'queued',
  `attempt_count` int unsigned NOT NULL DEFAULT '0',
  `max_attempts` int unsigned NOT NULL DEFAULT '3',
  `priority` tinyint unsigned NOT NULL DEFAULT '5',
  `available_at` timestamp NULL DEFAULT NULL,
  `dispatched_at` timestamp NULL DEFAULT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `failed_at` timestamp NULL DEFAULT NULL,
  `delivery_id` bigint unsigned DEFAULT NULL,
  `provider_message_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_error_code` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_error_message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `payload` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `marketing_message_jobs_campaign_recipient_id_foreign` (`campaign_recipient_id`),
  KEY `marketing_message_jobs_marketing_profile_id_foreign` (`marketing_profile_id`),
  KEY `marketing_message_jobs_delivery_id_foreign` (`delivery_id`),
  KEY `mmj_campaign_status_available_idx` (`campaign_id`,`status`,`available_at`),
  KEY `marketing_message_jobs_tenant_id_index` (`tenant_id`),
  KEY `marketing_message_jobs_store_key_index` (`store_key`),
  KEY `marketing_message_jobs_channel_index` (`channel`),
  KEY `marketing_message_jobs_job_type_index` (`job_type`),
  KEY `marketing_message_jobs_status_index` (`status`),
  KEY `marketing_message_jobs_priority_index` (`priority`),
  KEY `marketing_message_jobs_available_at_index` (`available_at`),
  KEY `marketing_message_jobs_provider_message_id_index` (`provider_message_id`),
  CONSTRAINT `marketing_message_jobs_campaign_id_foreign` FOREIGN KEY (`campaign_id`) REFERENCES `marketing_campaigns` (`id`) ON DELETE SET NULL,
  CONSTRAINT `marketing_message_jobs_campaign_recipient_id_foreign` FOREIGN KEY (`campaign_recipient_id`) REFERENCES `marketing_campaign_recipients` (`id`) ON DELETE SET NULL,
  CONSTRAINT `marketing_message_jobs_delivery_id_foreign` FOREIGN KEY (`delivery_id`) REFERENCES `marketing_message_deliveries` (`id`) ON DELETE SET NULL,
  CONSTRAINT `marketing_message_jobs_marketing_profile_id_foreign` FOREIGN KEY (`marketing_profile_id`) REFERENCES `marketing_profiles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mmj_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `marketing_message_media_assets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `marketing_message_media_assets` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `store_key` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `channel` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'email',
  `disk` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'public',
  `path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `public_url` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `original_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime_type` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `size_bytes` bigint unsigned NOT NULL DEFAULT '0',
  `width` int unsigned DEFAULT NULL,
  `height` int unsigned DEFAULT NULL,
  `alt_text` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `uploaded_by` bigint unsigned DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `mmma_scope_created_idx` (`tenant_id`,`store_key`,`channel`,`created_at`),
  KEY `mmma_scope_mime_idx` (`tenant_id`,`store_key`,`channel`,`mime_type`),
  KEY `marketing_message_media_assets_tenant_id_index` (`tenant_id`),
  KEY `marketing_message_media_assets_store_key_index` (`store_key`),
  KEY `marketing_message_media_assets_channel_index` (`channel`),
  KEY `marketing_message_media_assets_uploaded_by_index` (`uploaded_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `marketing_message_order_attributions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `marketing_message_order_attributions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned DEFAULT NULL,
  `store_key` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `order_id` bigint unsigned DEFAULT NULL,
  `marketing_profile_id` bigint unsigned DEFAULT NULL,
  `marketing_email_delivery_id` bigint unsigned DEFAULT NULL,
  `marketing_message_delivery_id` bigint unsigned DEFAULT NULL,
  `marketing_message_engagement_event_id` bigint unsigned DEFAULT NULL,
  `channel` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_module_key` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_campaign_id` bigint unsigned DEFAULT NULL,
  `source_campaign_label` varchar(180) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `attribution_model` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'last_click',
  `attribution_type` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'direct',
  `confidence_percent` tinyint unsigned NOT NULL DEFAULT '100',
  `attribution_window_days` smallint unsigned NOT NULL DEFAULT '7',
  `attributed_url` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `normalized_url` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `click_occurred_at` timestamp NULL DEFAULT NULL,
  `order_occurred_at` timestamp NULL DEFAULT NULL,
  `revenue_cents` int NOT NULL DEFAULT '0',
  `currency_code` varchar(8) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'USD',
  `gross_revenue_cents` int NOT NULL DEFAULT '0',
  `refund_cents` int NOT NULL DEFAULT '0',
  `net_revenue_cents` int NOT NULL DEFAULT '0',
  `provider_cost_micros` bigint unsigned NOT NULL DEFAULT '0',
  `buyer_spend_micros` bigint unsigned NOT NULL DEFAULT '0',
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mm_order_attribution_order_unique` (`tenant_id`,`store_key`,`order_id`,`attribution_model`),
  KEY `mm_order_attribution_tenant_store_delivery_idx` (`tenant_id`,`store_key`,`marketing_email_delivery_id`),
  KEY `mm_order_attribution_tenant_event_idx` (`tenant_id`,`marketing_message_engagement_event_id`),
  KEY `mm_ord_attr_order_fk` (`order_id`),
  KEY `mm_ord_attr_profile_fk` (`marketing_profile_id`),
  KEY `mm_ord_attr_email_delivery_fk` (`marketing_email_delivery_id`),
  KEY `mm_ord_attr_engagement_event_fk` (`marketing_message_engagement_event_id`),
  KEY `marketing_message_order_attributions_tenant_id_index` (`tenant_id`),
  KEY `marketing_message_order_attributions_store_key_index` (`store_key`),
  KEY `marketing_message_order_attributions_channel_index` (`channel`),
  KEY `marketing_message_order_attributions_attribution_model_index` (`attribution_model`),
  KEY `marketing_message_order_attributions_click_occurred_at_index` (`click_occurred_at`),
  KEY `marketing_message_order_attributions_order_occurred_at_index` (`order_occurred_at`),
  KEY `mm_order_attribution_tenant_store_msg_delivery_idx` (`tenant_id`,`store_key`,`marketing_message_delivery_id`),
  KEY `mm_ord_attr_message_delivery_fk` (`marketing_message_delivery_id`),
  KEY `marketing_message_order_attributions_source_module_key_index` (`source_module_key`),
  KEY `marketing_message_order_attributions_attribution_type_index` (`attribution_type`),
  CONSTRAINT `mm_ord_attr_email_delivery_fk` FOREIGN KEY (`marketing_email_delivery_id`) REFERENCES `marketing_email_deliveries` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mm_ord_attr_engagement_event_fk` FOREIGN KEY (`marketing_message_engagement_event_id`) REFERENCES `marketing_message_engagement_events` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mm_ord_attr_message_delivery_fk` FOREIGN KEY (`marketing_message_delivery_id`) REFERENCES `marketing_message_deliveries` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mm_ord_attr_order_fk` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mm_ord_attr_profile_fk` FOREIGN KEY (`marketing_profile_id`) REFERENCES `marketing_profiles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mm_order_attribution_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `marketing_message_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `marketing_message_templates` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned DEFAULT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `channel` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `objective` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tone` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `template_text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `variables_json` json DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `marketing_message_templates_created_by_foreign` (`created_by`),
  KEY `marketing_message_templates_updated_by_foreign` (`updated_by`),
  KEY `marketing_message_templates_channel_index` (`channel`),
  KEY `marketing_message_templates_objective_index` (`objective`),
  KEY `marketing_message_templates_is_active_index` (`is_active`),
  KEY `marketing_message_templates_tenant_id_index` (`tenant_id`),
  CONSTRAINT `marketing_message_templates_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `marketing_message_templates_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `marketing_message_templates_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `marketing_order_event_attributions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `marketing_order_event_attributions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned DEFAULT NULL,
  `source_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_instance_id` bigint unsigned NOT NULL,
  `attribution_method` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `confidence` decimal(5,2) DEFAULT NULL,
  `meta` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `moea_tenant_source_event_unique` (`tenant_id`,`source_type`,`source_id`,`event_instance_id`),
  KEY `marketing_order_event_attributions_event_instance_id_foreign` (`event_instance_id`),
  KEY `marketing_order_event_attributions_source_type_index` (`source_type`),
  KEY `marketing_order_event_attributions_source_id_index` (`source_id`),
  KEY `marketing_order_event_attributions_tenant_id_index` (`tenant_id`),
  CONSTRAINT `marketing_order_event_attributions_event_instance_id_foreign` FOREIGN KEY (`event_instance_id`) REFERENCES `event_instances` (`id`) ON DELETE CASCADE,
  CONSTRAINT `marketing_order_event_attributions_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `marketing_paid_media_daily_stats`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `marketing_paid_media_daily_stats` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned DEFAULT NULL,
  `store_key` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `platform` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_id` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `metric_date` date NOT NULL,
  `campaign_id` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `campaign_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ad_set_id` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ad_set_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ad_id` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ad_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `spend` decimal(12,2) NOT NULL DEFAULT '0.00',
  `impressions` bigint unsigned NOT NULL DEFAULT '0',
  `clicks` bigint unsigned NOT NULL DEFAULT '0',
  `reach` bigint unsigned NOT NULL DEFAULT '0',
  `purchases` int unsigned NOT NULL DEFAULT '0',
  `purchase_value` decimal(12,2) NOT NULL DEFAULT '0.00',
  `utm_source` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `utm_medium` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `utm_campaign` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `utm_content` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `utm_term` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `row_fingerprint` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `raw_payload` json DEFAULT NULL,
  `last_synced_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `marketing_paid_media_daily_stats_row_fingerprint_unique` (`row_fingerprint`),
  KEY `marketing_paid_media_daily_scope_idx` (`tenant_id`,`store_key`,`platform`,`metric_date`),
  KEY `marketing_paid_media_daily_stats_tenant_id_index` (`tenant_id`),
  KEY `marketing_paid_media_daily_stats_store_key_index` (`store_key`),
  KEY `marketing_paid_media_daily_stats_platform_index` (`platform`),
  KEY `marketing_paid_media_daily_stats_account_id_index` (`account_id`),
  KEY `marketing_paid_media_daily_stats_metric_date_index` (`metric_date`),
  KEY `marketing_paid_media_daily_stats_campaign_id_index` (`campaign_id`),
  KEY `marketing_paid_media_daily_stats_ad_set_id_index` (`ad_set_id`),
  KEY `marketing_paid_media_daily_stats_ad_id_index` (`ad_id`),
  KEY `marketing_paid_media_daily_stats_utm_source_index` (`utm_source`),
  KEY `marketing_paid_media_daily_stats_utm_medium_index` (`utm_medium`),
  KEY `marketing_paid_media_daily_stats_utm_campaign_index` (`utm_campaign`),
  KEY `marketing_paid_media_daily_stats_last_synced_at_index` (`last_synced_at`),
  CONSTRAINT `marketing_paid_media_daily_stats_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `marketing_profile_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `marketing_profile_links` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `marketing_profile_id` bigint unsigned NOT NULL,
  `tenant_id` bigint unsigned DEFAULT NULL,
  `source_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_meta` json DEFAULT NULL,
  `match_method` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `confidence` decimal(5,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mpl_tenant_source_unique_idx` (`tenant_id`,`source_type`,`source_id`),
  KEY `mpl_profile_source_type_idx` (`marketing_profile_id`,`source_type`),
  KEY `marketing_profile_links_tenant_id_index` (`tenant_id`),
  CONSTRAINT `marketing_profile_links_marketing_profile_id_foreign` FOREIGN KEY (`marketing_profile_id`) REFERENCES `marketing_profiles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `marketing_profile_links_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `marketing_profile_scent_quiz_results`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `marketing_profile_scent_quiz_results` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `marketing_profile_id` bigint unsigned NOT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  `quiz_version` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'scent-v1',
  `axis_scores` json NOT NULL,
  `dominant_traits` json DEFAULT NULL,
  `headline` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `personality_title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `personality_body` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `public_share_token` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `answers` json DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mpsqr_profile_unique` (`marketing_profile_id`),
  UNIQUE KEY `marketing_profile_scent_quiz_results_public_share_token_unique` (`public_share_token`),
  KEY `mpsqr_tenant_completed_idx` (`tenant_id`,`completed_at`),
  KEY `marketing_profile_scent_quiz_results_completed_at_index` (`completed_at`),
  CONSTRAINT `mpsqr_profile_fk` FOREIGN KEY (`marketing_profile_id`) REFERENCES `marketing_profiles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mpsqr_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `marketing_profile_scores`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `marketing_profile_scores` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `marketing_profile_id` bigint unsigned NOT NULL,
  `score_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `score` int NOT NULL,
  `reasons_json` json DEFAULT NULL,
  `calculated_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `mps_profile_type_idx` (`marketing_profile_id`,`score_type`),
  KEY `marketing_profile_scores_score_type_index` (`score_type`),
  KEY `marketing_profile_scores_calculated_at_index` (`calculated_at`),
  CONSTRAINT `marketing_profile_scores_marketing_profile_id_foreign` FOREIGN KEY (`marketing_profile_id`) REFERENCES `marketing_profiles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `marketing_profile_wishlist_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `marketing_profile_wishlist_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned DEFAULT NULL,
  `marketing_profile_id` bigint unsigned DEFAULT NULL,
  `wishlist_list_id` bigint unsigned DEFAULT NULL,
  `guest_token` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `provider` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'backstage',
  `integration` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'native',
  `store_key` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `product_id` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `product_variant_id` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `product_handle` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `product_title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `product_url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `source` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_surface` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_ref` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `added_at` timestamp NULL DEFAULT NULL,
  `last_added_at` timestamp NULL DEFAULT NULL,
  `removed_at` timestamp NULL DEFAULT NULL,
  `source_synced_at` timestamp NULL DEFAULT NULL,
  `raw_payload` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mpwi_list_store_product_unique` (`wishlist_list_id`,`store_key`,`product_id`),
  KEY `marketing_profile_wishlist_items_tenant_id_foreign` (`tenant_id`),
  KEY `mpwi_profile_status_idx` (`marketing_profile_id`,`status`),
  KEY `marketing_profile_wishlist_items_provider_index` (`provider`),
  KEY `marketing_profile_wishlist_items_integration_index` (`integration`),
  KEY `marketing_profile_wishlist_items_store_key_index` (`store_key`),
  KEY `marketing_profile_wishlist_items_product_id_index` (`product_id`),
  KEY `marketing_profile_wishlist_items_product_variant_id_index` (`product_variant_id`),
  KEY `marketing_profile_wishlist_items_product_handle_index` (`product_handle`),
  KEY `marketing_profile_wishlist_items_status_index` (`status`),
  KEY `marketing_profile_wishlist_items_source_index` (`source`),
  KEY `marketing_profile_wishlist_items_source_surface_index` (`source_surface`),
  KEY `marketing_profile_wishlist_items_source_ref_index` (`source_ref`),
  KEY `marketing_profile_wishlist_items_added_at_index` (`added_at`),
  KEY `marketing_profile_wishlist_items_last_added_at_index` (`last_added_at`),
  KEY `marketing_profile_wishlist_items_removed_at_index` (`removed_at`),
  KEY `marketing_profile_wishlist_items_source_synced_at_index` (`source_synced_at`),
  KEY `mpwi_wishlist_list_id_idx` (`wishlist_list_id`),
  KEY `mpwi_guest_token_idx` (`guest_token`),
  CONSTRAINT `marketing_profile_wishlist_items_marketing_profile_id_foreign` FOREIGN KEY (`marketing_profile_id`) REFERENCES `marketing_profiles` (`id`),
  CONSTRAINT `marketing_profile_wishlist_items_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `marketing_profile_wishlist_items_wishlist_list_id_foreign` FOREIGN KEY (`wishlist_list_id`) REFERENCES `marketing_wishlist_lists` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `marketing_profiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `marketing_profiles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned DEFAULT NULL,
  `first_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `normalized_first_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `normalized_last_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `first_name_phonetic` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_name_phonetic` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `normalized_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `normalized_phone` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address_line_1` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address_line_2` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `city` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `state` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `postal_code` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `country` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `accepts_email_marketing` tinyint(1) NOT NULL DEFAULT '0',
  `accepts_sms_marketing` tinyint(1) NOT NULL DEFAULT '0',
  `email_opted_out_at` timestamp NULL DEFAULT NULL,
  `sms_opted_out_at` timestamp NULL DEFAULT NULL,
  `source_channels` json DEFAULT NULL,
  `marketing_score` decimal(8,2) DEFAULT NULL,
  `last_marketing_score_at` timestamp NULL DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `tags` json DEFAULT NULL,
  `merged_into_profile_id` bigint unsigned DEFAULT NULL,
  `merge_operation_id` bigint unsigned DEFAULT NULL,
  `merged_at` timestamp NULL DEFAULT NULL,
  `archived_at` timestamp NULL DEFAULT NULL,
  `mobile_avatar_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mobile_avatar_uploaded_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `marketing_profiles_email_index` (`email`),
  KEY `marketing_profiles_normalized_email_index` (`normalized_email`),
  KEY `marketing_profiles_phone_index` (`phone`),
  KEY `marketing_profiles_normalized_phone_index` (`normalized_phone`),
  KEY `marketing_profiles_tenant_id_index` (`tenant_id`),
  KEY `mp_tenant_normalized_email_id_idx` (`tenant_id`,`normalized_email`,`id`),
  KEY `mp_tenant_normalized_phone_id_idx` (`tenant_id`,`normalized_phone`,`id`),
  KEY `marketing_profiles_merged_into_profile_id_foreign` (`merged_into_profile_id`),
  KEY `marketing_profiles_merge_operation_id_foreign` (`merge_operation_id`),
  KEY `marketing_profiles_normalized_first_name_index` (`normalized_first_name`),
  KEY `marketing_profiles_normalized_last_name_index` (`normalized_last_name`),
  KEY `marketing_profiles_first_name_phonetic_index` (`first_name_phonetic`),
  KEY `marketing_profiles_last_name_phonetic_index` (`last_name_phonetic`),
  KEY `marketing_profiles_merged_at_index` (`merged_at`),
  CONSTRAINT `marketing_profiles_merge_operation_id_foreign` FOREIGN KEY (`merge_operation_id`) REFERENCES `customer_merge_operations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `marketing_profiles_merged_into_profile_id_foreign` FOREIGN KEY (`merged_into_profile_id`) REFERENCES `marketing_profiles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `marketing_profiles_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `marketing_recommendation_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `marketing_recommendation_runs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'running',
  `summary` json DEFAULT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `finished_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `marketing_recommendation_runs_type_index` (`type`),
  KEY `marketing_recommendation_runs_status_index` (`status`),
  KEY `marketing_recommendation_runs_started_at_index` (`started_at`),
  KEY `marketing_recommendation_runs_finished_at_index` (`finished_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `marketing_recommendations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `marketing_recommendations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `campaign_id` bigint unsigned DEFAULT NULL,
  `marketing_profile_id` bigint unsigned DEFAULT NULL,
  `related_variant_id` bigint unsigned DEFAULT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `summary` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `details_json` json DEFAULT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `confidence` decimal(5,2) DEFAULT NULL,
  `created_by_system` tinyint(1) NOT NULL DEFAULT '1',
  `reviewed_by` bigint unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `resolution_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `marketing_recommendations_campaign_id_foreign` (`campaign_id`),
  KEY `marketing_recommendations_marketing_profile_id_foreign` (`marketing_profile_id`),
  KEY `marketing_recommendations_related_variant_id_foreign` (`related_variant_id`),
  KEY `marketing_recommendations_reviewed_by_foreign` (`reviewed_by`),
  KEY `marketing_recommendations_type_index` (`type`),
  KEY `marketing_recommendations_status_index` (`status`),
  KEY `marketing_recommendations_created_by_system_index` (`created_by_system`),
  CONSTRAINT `marketing_recommendations_campaign_id_foreign` FOREIGN KEY (`campaign_id`) REFERENCES `marketing_campaigns` (`id`) ON DELETE SET NULL,
  CONSTRAINT `marketing_recommendations_marketing_profile_id_foreign` FOREIGN KEY (`marketing_profile_id`) REFERENCES `marketing_profiles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `marketing_recommendations_related_variant_id_foreign` FOREIGN KEY (`related_variant_id`) REFERENCES `marketing_campaign_variants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `marketing_recommendations_reviewed_by_foreign` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `marketing_review_histories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `marketing_review_histories` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `marketing_profile_id` bigint unsigned DEFAULT NULL,
  `tenant_id` bigint unsigned DEFAULT NULL,
  `marketing_review_summary_id` bigint unsigned DEFAULT NULL,
  `provider` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `integration` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `store_key` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `external_customer_id` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `external_review_id` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `rating` tinyint unsigned DEFAULT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `body` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `reviewer_name` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reviewer_email` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_published` tinyint(1) DEFAULT NULL,
  `status` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'approved',
  `submission_source` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_pinned` tinyint(1) DEFAULT NULL,
  `is_verified_buyer` tinyint(1) DEFAULT NULL,
  `votes` int DEFAULT NULL,
  `has_media` tinyint(1) NOT NULL DEFAULT '0',
  `media_count` int unsigned NOT NULL DEFAULT '0',
  `media_assets` json DEFAULT NULL,
  `product_id` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `order_id` bigint unsigned DEFAULT NULL,
  `order_line_id` bigint unsigned DEFAULT NULL,
  `product_handle` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `variant_id` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `product_url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `product_title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `published_at` timestamp NULL DEFAULT NULL,
  `rejected_at` timestamp NULL DEFAULT NULL,
  `moderated_by` bigint unsigned DEFAULT NULL,
  `moderation_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `admin_response` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `admin_response_created_at` timestamp NULL DEFAULT NULL,
  `admin_response_updated_at` timestamp NULL DEFAULT NULL,
  `admin_response_by` bigint unsigned DEFAULT NULL,
  `admin_response_notified_at` timestamp NULL DEFAULT NULL,
  `notification_sent_at` timestamp NULL DEFAULT NULL,
  `reward_eligibility_status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reward_award_status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reward_amount_cents` int DEFAULT NULL,
  `reward_rule_key` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `candle_cash_task_event_id` bigint unsigned DEFAULT NULL,
  `candle_cash_task_completion_id` bigint unsigned DEFAULT NULL,
  `source_synced_at` timestamp NULL DEFAULT NULL,
  `raw_payload` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mrh_provider_integration_store_review_unique` (`provider`,`integration`,`store_key`,`external_review_id`),
  KEY `marketing_review_histories_marketing_profile_id_foreign` (`marketing_profile_id`),
  KEY `marketing_review_histories_marketing_review_summary_id_foreign` (`marketing_review_summary_id`),
  KEY `marketing_review_histories_provider_index` (`provider`),
  KEY `marketing_review_histories_integration_index` (`integration`),
  KEY `marketing_review_histories_store_key_index` (`store_key`),
  KEY `marketing_review_histories_external_customer_id_index` (`external_customer_id`),
  KEY `marketing_review_histories_external_review_id_index` (`external_review_id`),
  KEY `marketing_review_histories_is_published_index` (`is_published`),
  KEY `marketing_review_histories_has_media_index` (`has_media`),
  KEY `marketing_review_histories_product_id_index` (`product_id`),
  KEY `marketing_review_histories_reviewed_at_index` (`reviewed_at`),
  KEY `marketing_review_histories_source_synced_at_index` (`source_synced_at`),
  KEY `mrh_reviewer_email_idx` (`reviewer_email`),
  KEY `mrh_product_handle_idx` (`product_handle`),
  KEY `mrh_status_idx` (`status`),
  KEY `mrh_submitted_at_idx` (`submitted_at`),
  KEY `mrh_approved_at_idx` (`approved_at`),
  KEY `mrh_rejected_at_idx` (`rejected_at`),
  KEY `mrh_moderated_by_idx` (`moderated_by`),
  KEY `mrh_cc_event_idx` (`candle_cash_task_event_id`),
  KEY `mrh_cc_completion_idx` (`candle_cash_task_completion_id`),
  KEY `mrh_tenant_product_idx` (`tenant_id`,`store_key`,`product_id`),
  KEY `mrh_tenant_id_idx` (`tenant_id`),
  KEY `mrh_order_id_idx` (`order_id`),
  KEY `mrh_order_line_id_idx` (`order_line_id`),
  KEY `mrh_variant_id_idx` (`variant_id`),
  KEY `mrh_published_at_idx` (`published_at`),
  KEY `mrh_reward_eligibility_idx` (`reward_eligibility_status`),
  KEY `mrh_reward_award_idx` (`reward_award_status`),
  KEY `mrh_admin_response_by_idx` (`admin_response_by`),
  CONSTRAINT `marketing_review_histories_marketing_profile_id_foreign` FOREIGN KEY (`marketing_profile_id`) REFERENCES `marketing_profiles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `marketing_review_histories_marketing_review_summary_id_foreign` FOREIGN KEY (`marketing_review_summary_id`) REFERENCES `marketing_review_summaries` (`id`) ON DELETE SET NULL,
  CONSTRAINT `marketing_review_histories_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mrh_cc_completion_fk` FOREIGN KEY (`candle_cash_task_completion_id`) REFERENCES `candle_cash_task_completions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mrh_cc_event_fk` FOREIGN KEY (`candle_cash_task_event_id`) REFERENCES `candle_cash_task_events` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mrh_moderated_by_fk` FOREIGN KEY (`moderated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `marketing_review_summaries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `marketing_review_summaries` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `marketing_profile_id` bigint unsigned DEFAULT NULL,
  `provider` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `integration` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `store_key` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `external_customer_id` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `external_customer_email` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `review_count` int unsigned NOT NULL DEFAULT '0',
  `published_review_count` int unsigned NOT NULL DEFAULT '0',
  `average_rating` decimal(5,2) DEFAULT NULL,
  `last_reviewed_at` timestamp NULL DEFAULT NULL,
  `source_synced_at` timestamp NULL DEFAULT NULL,
  `raw_payload` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mrs_provider_integration_store_customer_unique` (`provider`,`integration`,`store_key`,`external_customer_id`),
  KEY `marketing_review_summaries_marketing_profile_id_foreign` (`marketing_profile_id`),
  KEY `marketing_review_summaries_provider_index` (`provider`),
  KEY `marketing_review_summaries_integration_index` (`integration`),
  KEY `marketing_review_summaries_store_key_index` (`store_key`),
  KEY `marketing_review_summaries_external_customer_id_index` (`external_customer_id`),
  KEY `marketing_review_summaries_external_customer_email_index` (`external_customer_email`),
  KEY `marketing_review_summaries_last_reviewed_at_index` (`last_reviewed_at`),
  KEY `marketing_review_summaries_source_synced_at_index` (`source_synced_at`),
  CONSTRAINT `marketing_review_summaries_marketing_profile_id_foreign` FOREIGN KEY (`marketing_profile_id`) REFERENCES `marketing_profiles` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `marketing_segments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `marketing_segments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned DEFAULT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `channel_scope` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rules_json` json DEFAULT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT '0',
  `last_previewed_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `marketing_segments_tenant_slug_unique` (`tenant_id`,`slug`),
  KEY `marketing_segments_created_by_foreign` (`created_by`),
  KEY `marketing_segments_updated_by_foreign` (`updated_by`),
  KEY `marketing_segments_status_index` (`status`),
  KEY `marketing_segments_is_system_index` (`is_system`),
  KEY `marketing_segments_tenant_id_index` (`tenant_id`),
  CONSTRAINT `marketing_segments_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `marketing_segments_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `marketing_segments_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `marketing_send_approvals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `marketing_send_approvals` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `campaign_recipient_id` bigint unsigned DEFAULT NULL,
  `recommendation_id` bigint unsigned DEFAULT NULL,
  `approval_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `approver_id` bigint unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `rejected_at` timestamp NULL DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `marketing_send_approvals_campaign_recipient_id_foreign` (`campaign_recipient_id`),
  KEY `marketing_send_approvals_recommendation_id_foreign` (`recommendation_id`),
  KEY `marketing_send_approvals_approver_id_foreign` (`approver_id`),
  KEY `marketing_send_approvals_approval_type_index` (`approval_type`),
  KEY `marketing_send_approvals_status_index` (`status`),
  CONSTRAINT `marketing_send_approvals_approver_id_foreign` FOREIGN KEY (`approver_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `marketing_send_approvals_campaign_recipient_id_foreign` FOREIGN KEY (`campaign_recipient_id`) REFERENCES `marketing_campaign_recipients` (`id`) ON DELETE SET NULL,
  CONSTRAINT `marketing_send_approvals_recommendation_id_foreign` FOREIGN KEY (`recommendation_id`) REFERENCES `marketing_recommendations` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `marketing_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `marketing_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` json DEFAULT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `marketing_settings_key_unique` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `marketing_short_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `marketing_short_links` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `destination_url` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `url_hash` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `usage_count` int unsigned NOT NULL DEFAULT '0',
  `last_used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `marketing_short_links_code_unique` (`code`),
  UNIQUE KEY `marketing_short_links_url_hash_unique` (`url_hash`),
  KEY `marketing_short_links_created_by_foreign` (`created_by`),
  KEY `marketing_short_links_last_used_at_index` (`last_used_at`),
  CONSTRAINT `marketing_short_links_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `marketing_social_share_claims`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `marketing_social_share_claims` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `marketing_profile_id` bigint unsigned NOT NULL,
  `platform` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_type` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_id` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `share_url` varchar(2048) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'started',
  `proof_url` varchar(2048) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `proof_text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `candle_cash_transaction_id` bigint unsigned DEFAULT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `claimed_at` timestamp NULL DEFAULT NULL,
  `awarded_at` timestamp NULL DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mssc_profile_platform_target_unique` (`marketing_profile_id`,`platform`,`target_type`,`target_id`),
  KEY `mssc_tenant_platform_status_idx` (`tenant_id`,`platform`,`status`),
  KEY `mssc_tenant_target_idx` (`tenant_id`,`target_type`,`target_id`),
  KEY `mssc_transaction_fk` (`candle_cash_transaction_id`),
  KEY `marketing_social_share_claims_status_index` (`status`),
  KEY `marketing_social_share_claims_started_at_index` (`started_at`),
  KEY `marketing_social_share_claims_claimed_at_index` (`claimed_at`),
  KEY `marketing_social_share_claims_awarded_at_index` (`awarded_at`),
  CONSTRAINT `mssc_profile_fk` FOREIGN KEY (`marketing_profile_id`) REFERENCES `marketing_profiles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mssc_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mssc_transaction_fk` FOREIGN KEY (`candle_cash_transaction_id`) REFERENCES `candle_cash_transactions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `marketing_storefront_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `marketing_storefront_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `event_type` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ok',
  `issue_type` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_surface` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `endpoint` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `request_key` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `signature_mode` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `marketing_profile_id` bigint unsigned DEFAULT NULL,
  `tenant_id` bigint unsigned DEFAULT NULL,
  `event_instance_id` bigint unsigned DEFAULT NULL,
  `candle_cash_redemption_id` bigint unsigned DEFAULT NULL,
  `source_type` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_id` varchar(180) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `meta` json DEFAULT NULL,
  `occurred_at` timestamp NULL DEFAULT NULL,
  `resolution_status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `resolved_by` bigint unsigned DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `resolution_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `marketing_storefront_events_event_instance_id_foreign` (`event_instance_id`),
  KEY `marketing_storefront_events_candle_cash_redemption_id_foreign` (`candle_cash_redemption_id`),
  KEY `marketing_storefront_events_resolved_by_foreign` (`resolved_by`),
  KEY `mse_status_issue_idx` (`status`,`issue_type`),
  KEY `mse_profile_occurred_idx` (`marketing_profile_id`,`occurred_at`),
  KEY `marketing_storefront_events_event_type_index` (`event_type`),
  KEY `marketing_storefront_events_status_index` (`status`),
  KEY `marketing_storefront_events_issue_type_index` (`issue_type`),
  KEY `marketing_storefront_events_source_surface_index` (`source_surface`),
  KEY `marketing_storefront_events_endpoint_index` (`endpoint`),
  KEY `marketing_storefront_events_request_key_index` (`request_key`),
  KEY `marketing_storefront_events_signature_mode_index` (`signature_mode`),
  KEY `marketing_storefront_events_source_type_index` (`source_type`),
  KEY `marketing_storefront_events_source_id_index` (`source_id`),
  KEY `marketing_storefront_events_occurred_at_index` (`occurred_at`),
  KEY `marketing_storefront_events_resolution_status_index` (`resolution_status`),
  KEY `marketing_storefront_events_resolved_at_index` (`resolved_at`),
  KEY `marketing_storefront_events_tenant_id_index` (`tenant_id`),
  CONSTRAINT `marketing_storefront_events_candle_cash_redemption_id_foreign` FOREIGN KEY (`candle_cash_redemption_id`) REFERENCES `candle_cash_redemptions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `marketing_storefront_events_event_instance_id_foreign` FOREIGN KEY (`event_instance_id`) REFERENCES `event_instances` (`id`) ON DELETE SET NULL,
  CONSTRAINT `marketing_storefront_events_marketing_profile_id_foreign` FOREIGN KEY (`marketing_profile_id`) REFERENCES `marketing_profiles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `marketing_storefront_events_resolved_by_foreign` FOREIGN KEY (`resolved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `marketing_storefront_events_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `marketing_template_definitions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `marketing_template_definitions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `template_key` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `channel` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `thumbnail_svg` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `default_subject` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `default_sections` json DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `marketing_template_definitions_template_key_unique` (`template_key`),
  KEY `marketing_template_definitions_channel_index` (`channel`),
  KEY `marketing_template_definitions_is_active_index` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `marketing_template_instances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `marketing_template_instances` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `template_definition_id` bigint unsigned DEFAULT NULL,
  `campaign_id` bigint unsigned DEFAULT NULL,
  `tenant_id` bigint unsigned DEFAULT NULL,
  `store_key` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `channel` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subject` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `body` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `sections` json DEFAULT NULL,
  `advanced_html` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `rendered_html` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `metadata` json DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `marketing_template_instances_template_definition_id_foreign` (`template_definition_id`),
  KEY `marketing_template_instances_campaign_id_foreign` (`campaign_id`),
  KEY `marketing_template_instances_created_by_foreign` (`created_by`),
  KEY `marketing_template_instances_tenant_id_index` (`tenant_id`),
  KEY `marketing_template_instances_store_key_index` (`store_key`),
  KEY `marketing_template_instances_channel_index` (`channel`),
  CONSTRAINT `marketing_template_instances_campaign_id_foreign` FOREIGN KEY (`campaign_id`) REFERENCES `marketing_campaigns` (`id`) ON DELETE SET NULL,
  CONSTRAINT `marketing_template_instances_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `marketing_template_instances_template_definition_id_foreign` FOREIGN KEY (`template_definition_id`) REFERENCES `marketing_template_definitions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mti_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `marketing_timing_insights`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `marketing_timing_insights` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `channel` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `objective` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `segment_key` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `event_context` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recommended_hour` tinyint unsigned DEFAULT NULL,
  `recommended_daypart` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `confidence` decimal(5,2) DEFAULT NULL,
  `reasons_json` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mti_channel_objective_segment_event_unique` (`channel`,`objective`,`segment_key`,`event_context`),
  KEY `marketing_timing_insights_channel_index` (`channel`),
  KEY `marketing_timing_insights_objective_index` (`objective`),
  KEY `marketing_timing_insights_segment_key_index` (`segment_key`),
  KEY `marketing_timing_insights_event_context_index` (`event_context`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `marketing_variant_performance_snapshots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `marketing_variant_performance_snapshots` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `campaign_id` bigint unsigned DEFAULT NULL,
  `variant_id` bigint unsigned DEFAULT NULL,
  `channel` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `window_start` timestamp NULL DEFAULT NULL,
  `window_end` timestamp NULL DEFAULT NULL,
  `recipients_count` int unsigned NOT NULL DEFAULT '0',
  `sent_count` int unsigned NOT NULL DEFAULT '0',
  `delivered_count` int unsigned NOT NULL DEFAULT '0',
  `opened_count` int unsigned NOT NULL DEFAULT '0',
  `clicked_count` int unsigned NOT NULL DEFAULT '0',
  `converted_count` int unsigned NOT NULL DEFAULT '0',
  `attributed_revenue` decimal(12,2) DEFAULT NULL,
  `snapshot_meta` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mvps_campaign_variant_channel_window_unique` (`campaign_id`,`variant_id`,`channel`,`window_start`,`window_end`),
  KEY `marketing_variant_performance_snapshots_variant_id_foreign` (`variant_id`),
  KEY `marketing_variant_performance_snapshots_channel_index` (`channel`),
  KEY `marketing_variant_performance_snapshots_window_start_index` (`window_start`),
  KEY `marketing_variant_performance_snapshots_window_end_index` (`window_end`),
  CONSTRAINT `marketing_variant_performance_snapshots_campaign_id_foreign` FOREIGN KEY (`campaign_id`) REFERENCES `marketing_campaigns` (`id`) ON DELETE SET NULL,
  CONSTRAINT `marketing_variant_performance_snapshots_variant_id_foreign` FOREIGN KEY (`variant_id`) REFERENCES `marketing_campaign_variants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `marketing_wishlist_lists`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `marketing_wishlist_lists` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned DEFAULT NULL,
  `marketing_profile_id` bigint unsigned DEFAULT NULL,
  `guest_token` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `store_key` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT '0',
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `source` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_activity_at` timestamp NULL DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `marketing_wishlist_lists_marketing_profile_id_foreign` (`marketing_profile_id`),
  KEY `mwl_profile_default_idx` (`tenant_id`,`marketing_profile_id`,`is_default`),
  KEY `mwl_guest_default_idx` (`tenant_id`,`guest_token`,`is_default`),
  KEY `marketing_wishlist_lists_guest_token_index` (`guest_token`),
  KEY `marketing_wishlist_lists_store_key_index` (`store_key`),
  KEY `marketing_wishlist_lists_is_default_index` (`is_default`),
  KEY `marketing_wishlist_lists_status_index` (`status`),
  KEY `marketing_wishlist_lists_source_index` (`source`),
  KEY `marketing_wishlist_lists_last_activity_at_index` (`last_activity_at`),
  CONSTRAINT `marketing_wishlist_lists_marketing_profile_id_foreign` FOREIGN KEY (`marketing_profile_id`) REFERENCES `marketing_profiles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `marketing_wishlist_lists_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `marketing_wishlist_outreach_queue`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `marketing_wishlist_outreach_queue` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned DEFAULT NULL,
  `marketing_profile_id` bigint unsigned DEFAULT NULL,
  `wishlist_list_id` bigint unsigned DEFAULT NULL,
  `wishlist_item_id` bigint unsigned DEFAULT NULL,
  `store_key` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `product_id` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `product_variant_id` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `product_handle` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `product_title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `channel` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'sms',
  `queue_status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'queued',
  `offer_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `offer_value` decimal(10,2) DEFAULT NULL,
  `offer_code` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `provider` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `provider_message_id` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `message_body` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `delivery_error` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_by` bigint unsigned DEFAULT NULL,
  `last_updated_by` bigint unsigned DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `redeemed_at` timestamp NULL DEFAULT NULL,
  `last_attempt_at` timestamp NULL DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `marketing_wishlist_outreach_queue_marketing_profile_id_foreign` (`marketing_profile_id`),
  KEY `marketing_wishlist_outreach_queue_wishlist_list_id_foreign` (`wishlist_list_id`),
  KEY `marketing_wishlist_outreach_queue_wishlist_item_id_foreign` (`wishlist_item_id`),
  KEY `mwq_tenant_status_idx` (`tenant_id`,`queue_status`),
  KEY `mwq_tenant_profile_product_idx` (`tenant_id`,`marketing_profile_id`,`product_id`),
  KEY `marketing_wishlist_outreach_queue_store_key_index` (`store_key`),
  KEY `marketing_wishlist_outreach_queue_product_id_index` (`product_id`),
  KEY `marketing_wishlist_outreach_queue_product_variant_id_index` (`product_variant_id`),
  KEY `marketing_wishlist_outreach_queue_product_handle_index` (`product_handle`),
  KEY `marketing_wishlist_outreach_queue_channel_index` (`channel`),
  KEY `marketing_wishlist_outreach_queue_queue_status_index` (`queue_status`),
  KEY `marketing_wishlist_outreach_queue_offer_type_index` (`offer_type`),
  KEY `marketing_wishlist_outreach_queue_offer_code_index` (`offer_code`),
  KEY `marketing_wishlist_outreach_queue_provider_index` (`provider`),
  KEY `marketing_wishlist_outreach_queue_provider_message_id_index` (`provider_message_id`),
  KEY `marketing_wishlist_outreach_queue_created_by_index` (`created_by`),
  KEY `marketing_wishlist_outreach_queue_last_updated_by_index` (`last_updated_by`),
  KEY `marketing_wishlist_outreach_queue_sent_at_index` (`sent_at`),
  KEY `marketing_wishlist_outreach_queue_redeemed_at_index` (`redeemed_at`),
  KEY `marketing_wishlist_outreach_queue_last_attempt_at_index` (`last_attempt_at`),
  CONSTRAINT `marketing_wishlist_outreach_queue_marketing_profile_id_foreign` FOREIGN KEY (`marketing_profile_id`) REFERENCES `marketing_profiles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `marketing_wishlist_outreach_queue_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `marketing_wishlist_outreach_queue_wishlist_item_id_foreign` FOREIGN KEY (`wishlist_item_id`) REFERENCES `marketing_profile_wishlist_items` (`id`) ON DELETE SET NULL,
  CONSTRAINT `marketing_wishlist_outreach_queue_wishlist_list_id_foreign` FOREIGN KEY (`wishlist_list_id`) REFERENCES `marketing_wishlist_lists` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `markets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `markets` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `default_location_city` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `default_location_state` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `markets_slug_unique` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `messaging_contact_channel_states`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `messaging_contact_channel_states` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `marketing_profile_id` bigint unsigned DEFAULT NULL,
  `phone` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sms_status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unknown',
  `email_status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unknown',
  `sms_status_reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email_status_reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sms_status_changed_at` timestamp NULL DEFAULT NULL,
  `email_status_changed_at` timestamp NULL DEFAULT NULL,
  `provider_source` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mccs_tenant_phone_unique` (`tenant_id`,`phone`),
  UNIQUE KEY `mccs_tenant_email_unique` (`tenant_id`,`email`),
  KEY `mccs_profile_fk` (`marketing_profile_id`),
  KEY `mccs_tenant_profile_idx` (`tenant_id`,`marketing_profile_id`),
  KEY `messaging_contact_channel_states_sms_status_index` (`sms_status`),
  KEY `messaging_contact_channel_states_email_status_index` (`email_status`),
  CONSTRAINT `mccs_profile_fk` FOREIGN KEY (`marketing_profile_id`) REFERENCES `marketing_profiles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mccs_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `messaging_conversation_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `messaging_conversation_messages` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `conversation_id` bigint unsigned NOT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  `store_key` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `marketing_profile_id` bigint unsigned DEFAULT NULL,
  `marketing_message_delivery_id` bigint unsigned DEFAULT NULL,
  `marketing_email_delivery_id` bigint unsigned DEFAULT NULL,
  `channel` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `direction` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `provider` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unknown',
  `provider_message_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dedupe_hash` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `body` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `normalized_body` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `subject` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `from_identity` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `to_identity` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `received_at` timestamp NULL DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `delivery_status` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `message_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'normal',
  `operator_read_at` timestamp NULL DEFAULT NULL,
  `customer_read_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `raw_payload` json DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mcm_provider_message_unique` (`provider`,`provider_message_id`),
  UNIQUE KEY `messaging_conversation_messages_dedupe_hash_unique` (`dedupe_hash`),
  KEY `mcm_profile_fk` (`marketing_profile_id`),
  KEY `mcm_sms_delivery_fk` (`marketing_message_delivery_id`),
  KEY `mcm_email_delivery_fk` (`marketing_email_delivery_id`),
  KEY `mcm_created_by_fk` (`created_by`),
  KEY `mcm_conversation_created_idx` (`conversation_id`,`created_at`),
  KEY `mcm_tenant_channel_direction_created_idx` (`tenant_id`,`channel`,`direction`,`created_at`),
  KEY `messaging_conversation_messages_store_key_index` (`store_key`),
  KEY `messaging_conversation_messages_channel_index` (`channel`),
  KEY `messaging_conversation_messages_direction_index` (`direction`),
  KEY `messaging_conversation_messages_provider_index` (`provider`),
  KEY `messaging_conversation_messages_received_at_index` (`received_at`),
  KEY `messaging_conversation_messages_sent_at_index` (`sent_at`),
  KEY `messaging_conversation_messages_delivery_status_index` (`delivery_status`),
  KEY `messaging_conversation_messages_message_type_index` (`message_type`),
  KEY `messaging_conversation_messages_operator_read_at_index` (`operator_read_at`),
  CONSTRAINT `mcm_conversation_fk` FOREIGN KEY (`conversation_id`) REFERENCES `messaging_conversations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mcm_created_by_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mcm_email_delivery_fk` FOREIGN KEY (`marketing_email_delivery_id`) REFERENCES `marketing_email_deliveries` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mcm_profile_fk` FOREIGN KEY (`marketing_profile_id`) REFERENCES `marketing_profiles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mcm_sms_delivery_fk` FOREIGN KEY (`marketing_message_delivery_id`) REFERENCES `marketing_message_deliveries` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mcm_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `messaging_conversations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `messaging_conversations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `store_key` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `channel` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `marketing_profile_id` bigint unsigned DEFAULT NULL,
  `phone` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subject` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `last_message_at` timestamp NULL DEFAULT NULL,
  `last_inbound_at` timestamp NULL DEFAULT NULL,
  `last_outbound_at` timestamp NULL DEFAULT NULL,
  `unread_count` int unsigned NOT NULL DEFAULT '0',
  `assigned_to` bigint unsigned DEFAULT NULL,
  `source_type` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_context` json DEFAULT NULL,
  `last_message_preview` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `messaging_conversations_marketing_profile_id_foreign` (`marketing_profile_id`),
  KEY `messaging_conversations_assigned_to_foreign` (`assigned_to`),
  KEY `msg_conv_tenant_store_channel_status_last_idx` (`tenant_id`,`store_key`,`channel`,`status`,`last_message_at`),
  KEY `msg_conv_tenant_phone_channel_idx` (`tenant_id`,`phone`,`channel`),
  KEY `msg_conv_tenant_email_channel_idx` (`tenant_id`,`email`,`channel`),
  KEY `messaging_conversations_store_key_index` (`store_key`),
  KEY `messaging_conversations_channel_index` (`channel`),
  KEY `messaging_conversations_phone_index` (`phone`),
  KEY `messaging_conversations_email_index` (`email`),
  KEY `messaging_conversations_status_index` (`status`),
  KEY `messaging_conversations_last_message_at_index` (`last_message_at`),
  KEY `messaging_conversations_last_inbound_at_index` (`last_inbound_at`),
  KEY `messaging_conversations_last_outbound_at_index` (`last_outbound_at`),
  KEY `messaging_conversations_source_type_index` (`source_type`),
  KEY `messaging_conversations_source_id_index` (`source_id`),
  CONSTRAINT `messaging_conversations_assigned_to_foreign` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `messaging_conversations_marketing_profile_id_foreign` FOREIGN KEY (`marketing_profile_id`) REFERENCES `marketing_profiles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `messaging_conversations_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mobile_authorization_codes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `mobile_authorization_codes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `code_hash` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `code_challenge` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `redirect_uri` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `client_id` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'everbranch-mobile',
  `device_name` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `state` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `expires_at` timestamp NOT NULL,
  `consumed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mobile_authorization_codes_code_hash_unique` (`code_hash`),
  KEY `mobile_authorization_codes_user_id_foreign` (`user_id`),
  KEY `mobile_authorization_codes_expires_at_index` (`expires_at`),
  CONSTRAINT `mobile_authorization_codes_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mobile_push_devices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `mobile_push_devices` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `marketing_profile_id` bigint unsigned NOT NULL,
  `platform` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ios',
  `device_token` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `authorization_status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `push_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `app_version` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `app_build` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `device_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `device_model` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `locale` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_seen_at` timestamp NULL DEFAULT NULL,
  `last_registered_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mobile_push_devices_tenant_id_device_token_unique` (`tenant_id`,`device_token`),
  KEY `mobile_push_devices_tenant_id_index` (`tenant_id`),
  KEY `mobile_push_devices_marketing_profile_id_index` (`marketing_profile_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `modern_forestry_fundraiser_invoice_packages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `modern_forestry_fundraiser_invoice_packages` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `package_reference` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'review_required',
  `delivery_status` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'not_sent',
  `tracking_status` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'not_available',
  `payer_name` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `payer_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `notification_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `currency` varchar(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'usd',
  `payment_terms_days` smallint unsigned NOT NULL,
  `invoice_date` date NOT NULL,
  `due_date` date NOT NULL,
  `subtotal_cents` int unsigned NOT NULL,
  `discount_cents` int unsigned NOT NULL DEFAULT '0',
  `shipping_cents` int unsigned NOT NULL DEFAULT '0',
  `tax_cents` int unsigned NOT NULL DEFAULT '0',
  `total_cents` int unsigned NOT NULL,
  `order_ids` json NOT NULL,
  `invoice_lines` json NOT NULL,
  `review_notes` json DEFAULT NULL,
  `prepared_by` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `prepared_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mffip_package_reference_uq` (`package_reference`),
  KEY `mffip_tenant_status_prepared_idx` (`tenant_id`,`status`,`prepared_at`),
  CONSTRAINT `mffip_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `modern_forestry_fundraiser_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `modern_forestry_fundraiser_orders` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `source` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'zapier',
  `external_order_id` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `order_reference` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recipient_name` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `recipient_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recipient_phone` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `shipping_address` json NOT NULL,
  `currency` varchar(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'usd',
  `subtotal_cents` int unsigned NOT NULL,
  `discount_cents` int unsigned NOT NULL DEFAULT '0',
  `shipping_cents` int unsigned NOT NULL DEFAULT '0',
  `tax_cents` int unsigned NOT NULL DEFAULT '0',
  `total_cents` int unsigned NOT NULL,
  `status` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'needs_review',
  `fingerprint` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `line_items` json NOT NULL,
  `source_payload` json DEFAULT NULL,
  `source_created_at` timestamp NULL DEFAULT NULL,
  `received_at` timestamp NOT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `reviewed_by` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `review_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mffo_tenant_source_external_uq` (`tenant_id`,`source`,`external_order_id`),
  KEY `mffo_tenant_status_received_idx` (`tenant_id`,`status`,`received_at`),
  CONSTRAINT `mffo_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `modern_forestry_mobile_bag_snapshots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `modern_forestry_mobile_bag_snapshots` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `marketing_profile_id` bigint unsigned NOT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `currency_code` varchar(8) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `item_count` int unsigned NOT NULL DEFAULT '0',
  `subtotal_amount` decimal(10,2) DEFAULT NULL,
  `items` json DEFAULT NULL,
  `content_hash` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `reminder_count` int unsigned NOT NULL DEFAULT '0',
  `last_synced_at` timestamp NULL DEFAULT NULL,
  `cart_started_at` timestamp NULL DEFAULT NULL,
  `last_reminded_at` timestamp NULL DEFAULT NULL,
  `next_reminder_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `meta` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mf_mobile_bag_snapshots_unique_profile` (`tenant_id`,`marketing_profile_id`),
  KEY `mf_mobile_bag_snapshots_due_idx` (`tenant_id`,`is_active`,`next_reminder_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `oil_abbreviations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `oil_abbreviations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `abbreviation` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `oil_movements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `oil_movements` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `base_oil_id` bigint unsigned NOT NULL,
  `grams` decimal(10,2) NOT NULL,
  `reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_id` bigint unsigned DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `oil_movements_base_oil_id_foreign` (`base_oil_id`),
  KEY `oil_movements_source_type_source_id_index` (`source_type`,`source_id`),
  CONSTRAINT `oil_movements_base_oil_id_foreign` FOREIGN KEY (`base_oil_id`) REFERENCES `base_oils` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `operator_alert_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `operator_alert_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `event_key` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `dedupe_key` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `tenant_id` bigint unsigned DEFAULT NULL,
  `target_type` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `target_id` bigint unsigned DEFAULT NULL,
  `destination` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `operator_alert_logs_dedupe_key_unique` (`dedupe_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `operator_recurring_costs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `operator_recurring_costs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `vendor` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount_cents` bigint unsigned NOT NULL,
  `currency` varchar(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'USD',
  `cadence` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'monthly',
  `effective_on` date DEFAULT NULL,
  `source` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `receipt_reference` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `operator_recurring_costs_active_vendor_index` (`active`,`vendor`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `order_line_scent_splits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `order_line_scent_splits` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `order_line_id` bigint unsigned NOT NULL,
  `mapping_exception_id` bigint unsigned DEFAULT NULL,
  `scent_id` bigint unsigned DEFAULT NULL,
  `raw_scent_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `quantity` int NOT NULL DEFAULT '1',
  `allocation_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual_split',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `olss_order_line_idx` (`order_line_id`),
  KEY `olss_exception_idx` (`mapping_exception_id`),
  KEY `olss_scent_idx` (`scent_id`),
  CONSTRAINT `olss_exception_fk` FOREIGN KEY (`mapping_exception_id`) REFERENCES `mapping_exceptions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `olss_order_line_fk` FOREIGN KEY (`order_line_id`) REFERENCES `order_lines` (`id`) ON DELETE CASCADE,
  CONSTRAINT `olss_scent_fk` FOREIGN KEY (`scent_id`) REFERENCES `scents` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `order_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `order_lines` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `order_id` bigint unsigned NOT NULL,
  `scent_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `size_code` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `quantity` int NOT NULL DEFAULT '1',
  `ordered_qty` int NOT NULL DEFAULT '0',
  `extra_qty` int NOT NULL DEFAULT '0',
  `shopify_line_item_id` bigint unsigned DEFAULT NULL,
  `shopify_product_id` bigint unsigned DEFAULT NULL,
  `shopify_variant_id` bigint unsigned DEFAULT NULL,
  `currency_code` varchar(8) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `line_total` decimal(10,2) DEFAULT NULL,
  `discount_total` decimal(10,2) DEFAULT NULL,
  `line_subtotal` decimal(10,2) DEFAULT NULL,
  `unit_price` decimal(10,2) DEFAULT NULL,
  `external_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sku` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `raw_title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `raw_variant` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `image_url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `wick_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pour_status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `brought_down_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `scent_id` bigint unsigned DEFAULT NULL,
  `size_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_lines_unique_order_shopify_line` (`order_id`,`shopify_line_item_id`),
  UNIQUE KEY `order_lines_unique_order_scent_size_not_null` (`order_id`,`scent_id`,`size_id`),
  KEY `order_lines_order_scent_size_idx` (`order_id`,`scent_id`,`size_id`),
  KEY `order_lines_shopify_product_id_idx` (`shopify_product_id`),
  KEY `order_lines_shopify_variant_id_idx` (`shopify_variant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `orders` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `source` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `order_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `event_id` bigint unsigned DEFAULT NULL,
  `order_label` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `shipping_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `billing_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `shipping_company` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `shipping_address1` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `shipping_city` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `shipping_province` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `shipping_province_code` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `shipping_zip` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `shipping_country_code` varchar(8) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `billing_company` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `billing_address1` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `shopify_store` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `shopify_store_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `shopify_order_id` bigint unsigned DEFAULT NULL,
  `shopify_customer_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `shopify_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `currency_code` varchar(8) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `order_number` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `container_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `customer_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `billing_phone` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `billing_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `shipping_phone` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `shipping_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `customer_phone` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `customer_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `first_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ordered_at` timestamp NULL DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `due_at` timestamp NULL DEFAULT NULL,
  `ship_by_at` timestamp NULL DEFAULT NULL,
  `published_at` timestamp NULL DEFAULT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'new',
  `requires_shipping_review` tinyint(1) NOT NULL DEFAULT '0',
  `internal_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `attribution_meta` json DEFAULT NULL,
  `storefront_checkout_token` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `storefront_cart_token` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `storefront_session_key` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `storefront_client_id` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `storefront_message_delivery_id` bigint unsigned DEFAULT NULL,
  `storefront_linked_event_id` bigint unsigned DEFAULT NULL,
  `storefront_link_confidence` decimal(5,2) DEFAULT NULL,
  `storefront_link_method` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `storefront_linked_at` timestamp NULL DEFAULT NULL,
  `total_price` decimal(10,2) DEFAULT NULL,
  `refund_total` decimal(10,2) DEFAULT NULL,
  `shipping_total` decimal(10,2) DEFAULT NULL,
  `tax_total` decimal(10,2) DEFAULT NULL,
  `discount_total` decimal(10,2) DEFAULT NULL,
  `subtotal_price` decimal(10,2) DEFAULT NULL,
  `tenant_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `orders_unique_shopify_store_order` (`shopify_store_key`,`shopify_order_id`),
  KEY `orders_new_shopify_order_id_index` (`shopify_order_id`),
  KEY `orders_event_id_foreign` (`event_id`),
  KEY `orders_order_type_event_id_idx` (`order_type`,`event_id`),
  KEY `orders_shopify_customer_id_index` (`shopify_customer_id`),
  KEY `orders_tenant_customer_store_key_idx` (`tenant_id`,`shopify_customer_id`,`shopify_store_key`),
  KEY `orders_tenant_id_idx` (`tenant_id`,`id`),
  KEY `orders_storefront_checkout_token_idx` (`storefront_checkout_token`),
  KEY `orders_storefront_cart_token_idx` (`storefront_cart_token`),
  KEY `orders_storefront_session_key_idx` (`storefront_session_key`),
  KEY `orders_storefront_client_id_idx` (`storefront_client_id`),
  KEY `orders_storefront_message_delivery_id_idx` (`storefront_message_delivery_id`),
  KEY `orders_storefront_linked_event_id_idx` (`storefront_linked_event_id`),
  CONSTRAINT `orders_event_id_foreign` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE SET NULL,
  CONSTRAINT `orders_storefront_linked_event_fk` FOREIGN KEY (`storefront_linked_event_id`) REFERENCES `marketing_storefront_events` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `personal_access_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `personal_access_tokens` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `tokenable_id` bigint unsigned NOT NULL,
  `name` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `abilities` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`),
  KEY `personal_access_tokens_expires_at_index` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pour_batch_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pour_batch_lines` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `pour_batch_id` bigint unsigned NOT NULL,
  `order_id` bigint unsigned DEFAULT NULL,
  `order_line_id` bigint unsigned DEFAULT NULL,
  `scent_id` bigint unsigned DEFAULT NULL,
  `size_id` bigint unsigned DEFAULT NULL,
  `sku` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `quantity` int unsigned NOT NULL DEFAULT '0',
  `wax_grams` decimal(10,2) NOT NULL DEFAULT '0.00',
  `oil_grams` decimal(10,2) NOT NULL DEFAULT '0.00',
  `alcohol_grams` decimal(10,2) NOT NULL DEFAULT '0.00',
  `water_grams` decimal(10,2) NOT NULL DEFAULT '0.00',
  `total_grams` decimal(10,2) NOT NULL DEFAULT '0.00',
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'queued',
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `pour_batch_lines_pour_batch_id_foreign` (`pour_batch_id`),
  KEY `pour_batch_lines_order_id_order_line_id_index` (`order_id`,`order_line_id`),
  CONSTRAINT `pour_batch_lines_pour_batch_id_foreign` FOREIGN KEY (`pour_batch_id`) REFERENCES `pour_batches` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pour_batch_pitchers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pour_batch_pitchers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `pour_batch_id` bigint unsigned NOT NULL,
  `pitcher_index` int unsigned NOT NULL,
  `wax_grams` decimal(10,2) NOT NULL DEFAULT '0.00',
  `oil_grams` decimal(10,2) NOT NULL DEFAULT '0.00',
  `total_grams` decimal(10,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pour_batch_pitchers_pour_batch_id_pitcher_index_unique` (`pour_batch_id`,`pitcher_index`),
  CONSTRAINT `pour_batch_pitchers_pour_batch_id_foreign` FOREIGN KEY (`pour_batch_id`) REFERENCES `pour_batches` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pour_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pour_batches` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `selection_mode` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `order_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `wax_total_grams` decimal(10,2) NOT NULL DEFAULT '0.00',
  `oil_total_grams` decimal(10,2) NOT NULL DEFAULT '0.00',
  `alcohol_total_grams` decimal(10,2) NOT NULL DEFAULT '0.00',
  `water_total_grams` decimal(10,2) NOT NULL DEFAULT '0.00',
  `total_grams` decimal(10,2) NOT NULL DEFAULT '0.00',
  `pitcher_count` int unsigned NOT NULL DEFAULT '0',
  `created_by` bigint unsigned DEFAULT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pour_request_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pour_request_lines` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `pour_request_id` bigint unsigned NOT NULL,
  `scent_id` bigint unsigned DEFAULT NULL,
  `size_id` bigint unsigned DEFAULT NULL,
  `wick_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `qty` int unsigned NOT NULL DEFAULT '0',
  `produced_qty` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `pour_request_lines_pour_request_id_foreign` (`pour_request_id`),
  KEY `pour_request_lines_scent_id_foreign` (`scent_id`),
  KEY `pour_request_lines_size_id_foreign` (`size_id`),
  CONSTRAINT `pour_request_lines_pour_request_id_foreign` FOREIGN KEY (`pour_request_id`) REFERENCES `pour_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `pour_request_lines_scent_id_foreign` FOREIGN KEY (`scent_id`) REFERENCES `scents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `pour_request_lines_size_id_foreign` FOREIGN KEY (`size_id`) REFERENCES `sizes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pour_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pour_requests` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `source_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_id` bigint unsigned DEFAULT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `due_date` date DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pouring_measurements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pouring_measurements` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `size_code` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `product_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'candle',
  `wax_grams` decimal(10,2) NOT NULL DEFAULT '0.00',
  `oil_grams` decimal(10,2) NOT NULL DEFAULT '0.00',
  `total_grams` decimal(10,2) NOT NULL DEFAULT '0.00',
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pouring_measurements_size_code_product_type_unique` (`size_code`,`product_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `quickbooks_audit_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `quickbooks_audit_runs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `integration_connection_id` bigint unsigned NOT NULL,
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'running',
  `dry_run` tinyint(1) NOT NULL DEFAULT '1',
  `summary` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `errors` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `started_at` timestamp NOT NULL,
  `finished_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `qbo_audit_tenant_started_idx` (`tenant_id`,`started_at`),
  KEY `qbo_audit_connection_fk` (`integration_connection_id`),
  KEY `quickbooks_audit_runs_status_index` (`status`),
  CONSTRAINT `qbo_audit_connection_fk` FOREIGN KEY (`integration_connection_id`) REFERENCES `integration_connections` (`id`) ON DELETE CASCADE,
  CONSTRAINT `qbo_audit_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `quickbooks_reporting_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `quickbooks_reporting_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `integration_connection_id` bigint unsigned DEFAULT NULL,
  `scheduled_sync_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `sync_cadence` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'hourly',
  `supplies_account_mappings` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `wage_account_mappings` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `contract_labor_account_mappings` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `owner_compensation_account_mappings` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `owner_compensation_adjustments` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `mappings_reviewed_at` timestamp NULL DEFAULT NULL,
  `mappings_reviewed_by_user_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `quickbooks_reporting_settings_tenant_id_unique` (`tenant_id`),
  KEY `qbo_report_settings_connection_fk` (`integration_connection_id`),
  KEY `qbo_report_settings_reviewer_fk` (`mappings_reviewed_by_user_id`),
  KEY `quickbooks_reporting_settings_scheduled_sync_enabled_index` (`scheduled_sync_enabled`),
  CONSTRAINT `qbo_report_settings_connection_fk` FOREIGN KEY (`integration_connection_id`) REFERENCES `integration_connections` (`id`) ON DELETE SET NULL,
  CONSTRAINT `qbo_report_settings_reviewer_fk` FOREIGN KEY (`mappings_reviewed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `qbo_report_settings_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `quickbooks_reporting_snapshots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `quickbooks_reporting_snapshots` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `integration_connection_id` bigint unsigned NOT NULL,
  `range_key` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `metrics` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `observed_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `qbo_report_snapshot_period_unique` (`tenant_id`,`range_key`,`period_start`,`period_end`),
  KEY `qbo_report_snapshot_observed_idx` (`tenant_id`,`observed_at`),
  KEY `qbo_report_snapshots_connection_fk` (`integration_connection_id`),
  CONSTRAINT `qbo_report_snapshots_connection_fk` FOREIGN KEY (`integration_connection_id`) REFERENCES `integration_connections` (`id`) ON DELETE CASCADE,
  CONSTRAINT `qbo_report_snapshots_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `quickbooks_source_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `quickbooks_source_records` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `integration_connection_id` bigint unsigned NOT NULL,
  `entity_type` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `external_id` varchar(180) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_updated_at` timestamp NULL DEFAULT NULL,
  `observed_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `qbo_source_tenant_connection_entity_external_unique` (`tenant_id`,`integration_connection_id`,`entity_type`,`external_id`),
  KEY `qbo_source_tenant_entity_idx` (`tenant_id`,`entity_type`),
  KEY `qbo_source_connection_fk` (`integration_connection_id`),
  CONSTRAINT `qbo_source_connection_fk` FOREIGN KEY (`integration_connection_id`) REFERENCES `integration_connections` (`id`) ON DELETE CASCADE,
  CONSTRAINT `qbo_source_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `quickbooks_sync_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `quickbooks_sync_runs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `integration_connection_id` bigint unsigned NOT NULL,
  `mode` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'incremental',
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'running',
  `checkpoint_started_at` timestamp NULL DEFAULT NULL,
  `checkpoint_finished_at` timestamp NULL DEFAULT NULL,
  `summary` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `errors` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `started_at` timestamp NOT NULL,
  `finished_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `qbo_sync_runs_tenant_started_idx` (`tenant_id`,`started_at`),
  KEY `qbo_sync_runs_connection_fk` (`integration_connection_id`),
  KEY `quickbooks_sync_runs_status_index` (`status`),
  CONSTRAINT `qbo_sync_runs_connection_fk` FOREIGN KEY (`integration_connection_id`) REFERENCES `integration_connections` (`id`) ON DELETE CASCADE,
  CONSTRAINT `qbo_sync_runs_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `readiness_checklist_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `readiness_checklist_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `slug` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `label` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `category` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'platform',
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'todo',
  `detail` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `readiness_checklist_items_slug_unique` (`slug`),
  KEY `readiness_checklist_items_category_index` (`category`),
  KEY `readiness_checklist_items_status_index` (`status`),
  KEY `readiness_checklist_items_sort_order_index` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `retail_plan_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `retail_plan_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `retail_plan_id` bigint unsigned NOT NULL,
  `order_id` bigint unsigned DEFAULT NULL,
  `order_line_id` bigint unsigned DEFAULT NULL,
  `scent_id` bigint unsigned DEFAULT NULL,
  `size_id` bigint unsigned DEFAULT NULL,
  `sku` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `quantity` int unsigned NOT NULL DEFAULT '0',
  `inventory_quantity` int unsigned NOT NULL DEFAULT '0',
  `source` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'order',
  `source_label` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `box_tier` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `upcoming_event_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `retail_plan_items_retail_plan_id_foreign` (`retail_plan_id`),
  KEY `retail_plan_items_upcoming_event_id_index` (`upcoming_event_id`),
  KEY `retail_plan_items_source_label_index` (`source_label`),
  CONSTRAINT `retail_plan_items_retail_plan_id_foreign` FOREIGN KEY (`retail_plan_id`) REFERENCES `retail_plans` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `retail_plans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `retail_plans` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `queue_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'retail',
  `event_id` bigint unsigned DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `published_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `retail_plans_event_id_foreign` (`event_id`),
  KEY `retail_plans_queue_type_event_id_idx` (`queue_type`,`event_id`),
  CONSTRAINT `retail_plans_event_id_foreign` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `room_spray_measurements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `room_spray_measurements` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `quantity` int unsigned NOT NULL,
  `alcohol_grams` decimal(10,2) NOT NULL DEFAULT '0.00',
  `oil_grams` decimal(10,2) NOT NULL DEFAULT '0.00',
  `water_grams` decimal(10,2) NOT NULL DEFAULT '0.00',
  `total_grams` decimal(10,2) NOT NULL DEFAULT '0.00',
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `room_spray_measurements_quantity_unique` (`quantity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `scent_aliases`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `scent_aliases` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `alias` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `scent_id` bigint unsigned NOT NULL,
  `scope` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'markets',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `scent_aliases_alias_scope_unique` (`alias`,`scope`),
  KEY `scent_aliases_scent_id_foreign` (`scent_id`),
  KEY `scent_aliases_alias_index` (`alias`),
  CONSTRAINT `scent_aliases_scent_id_foreign` FOREIGN KEY (`scent_id`) REFERENCES `scents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `scent_recipe_components`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `scent_recipe_components` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `scent_recipe_id` bigint unsigned NOT NULL,
  `component_type` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'oil',
  `base_oil_id` bigint unsigned DEFAULT NULL,
  `blend_template_id` bigint unsigned DEFAULT NULL,
  `parts` decimal(10,4) DEFAULT NULL,
  `percentage` decimal(8,4) DEFAULT NULL,
  `sort_order` smallint unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `scent_recipe_components_base_oil_id_foreign` (`base_oil_id`),
  KEY `scent_recipe_components_blend_template_id_foreign` (`blend_template_id`),
  KEY `scent_recipe_components_scent_recipe_id_component_type_index` (`scent_recipe_id`,`component_type`),
  KEY `scent_recipe_components_component_type_base_oil_id_index` (`component_type`,`base_oil_id`),
  KEY `scent_recipe_components_component_type_blend_template_id_index` (`component_type`,`blend_template_id`),
  CONSTRAINT `scent_recipe_components_base_oil_id_foreign` FOREIGN KEY (`base_oil_id`) REFERENCES `base_oils` (`id`) ON DELETE SET NULL,
  CONSTRAINT `scent_recipe_components_blend_template_id_foreign` FOREIGN KEY (`blend_template_id`) REFERENCES `blends` (`id`) ON DELETE SET NULL,
  CONSTRAINT `scent_recipe_components_scent_recipe_id_foreign` FOREIGN KEY (`scent_recipe_id`) REFERENCES `scent_recipes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `scent_recipes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `scent_recipes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `scent_id` bigint unsigned NOT NULL,
  `version` int unsigned NOT NULL DEFAULT '1',
  `status` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `is_active` tinyint(1) NOT NULL DEFAULT '0',
  `activated_at` timestamp NULL DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `source_context` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `scent_recipes_scent_id_version_unique` (`scent_id`,`version`),
  KEY `scent_recipes_scent_id_is_active_index` (`scent_id`,`is_active`),
  KEY `scent_recipes_status_index` (`status`),
  CONSTRAINT `scent_recipes_scent_id_foreign` FOREIGN KEY (`scent_id`) REFERENCES `scents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `scent_template_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `scent_template_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `template_id` bigint unsigned NOT NULL,
  `scent_id` bigint unsigned DEFAULT NULL,
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `scent_template_items_scent_id_foreign` (`scent_id`),
  KEY `scent_template_items_template_id_sort_order_index` (`template_id`,`sort_order`),
  CONSTRAINT `scent_template_items_scent_id_foreign` FOREIGN KEY (`scent_id`) REFERENCES `scents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `scent_template_items_template_id_foreign` FOREIGN KEY (`template_id`) REFERENCES `scent_templates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `scent_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `scent_templates` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT '0',
  `configuration` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `scent_templates_type_is_default_index` (`type`,`is_default`),
  KEY `scent_templates_type_index` (`type`),
  KEY `scent_templates_is_default_index` (`is_default`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `scents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `scents` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `display_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `oil_reference_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `abbreviation` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_blend` tinyint(1) NOT NULL DEFAULT '0',
  `oil_blend_id` bigint unsigned DEFAULT NULL,
  `blend_oil_count` smallint unsigned DEFAULT NULL,
  `canonical_scent_id` bigint unsigned DEFAULT NULL,
  `source_wholesale_custom_scent_id` bigint unsigned DEFAULT NULL,
  `current_scent_recipe_id` bigint unsigned DEFAULT NULL,
  `recipe_components_json` json DEFAULT NULL,
  `availability_json` json DEFAULT NULL,
  `lifecycle_status` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_wholesale_custom` tinyint(1) NOT NULL DEFAULT '0',
  `is_candle_club` tinyint(1) NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `scents_name_unique` (`name`),
  KEY `scents_oil_blend_id_foreign` (`oil_blend_id`),
  KEY `scents_canonical_scent_id_foreign` (`canonical_scent_id`),
  KEY `scents_source_wholesale_custom_scent_id_foreign` (`source_wholesale_custom_scent_id`),
  KEY `scents_current_scent_recipe_id_foreign` (`current_scent_recipe_id`),
  CONSTRAINT `scents_canonical_scent_id_foreign` FOREIGN KEY (`canonical_scent_id`) REFERENCES `scents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `scents_current_scent_recipe_id_foreign` FOREIGN KEY (`current_scent_recipe_id`) REFERENCES `scent_recipes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `scents_oil_blend_id_foreign` FOREIGN KEY (`oil_blend_id`) REFERENCES `blends` (`id`) ON DELETE SET NULL,
  CONSTRAINT `scents_source_wholesale_custom_scent_id_foreign` FOREIGN KEY (`source_wholesale_custom_scent_id`) REFERENCES `wholesale_custom_scents` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `scheduled_classes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `scheduled_classes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `category` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `location` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `starts_at` timestamp NOT NULL,
  `ends_at` timestamp NULL DEFAULT NULL,
  `capacity` int unsigned NOT NULL DEFAULT '12',
  `price` decimal(10,2) DEFAULT NULL,
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'published',
  `registration_open` tinyint(1) NOT NULL DEFAULT '1',
  `image_url` varchar(2048) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reminder_offsets` json DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `scheduled_classes_tenant_id_slug_unique` (`tenant_id`,`slug`),
  KEY `scheduled_classes_tenant_start_status_idx` (`tenant_id`,`starts_at`,`status`),
  KEY `scheduled_classes_category_index` (`category`),
  KEY `scheduled_classes_starts_at_index` (`starts_at`),
  KEY `scheduled_classes_status_index` (`status`),
  CONSTRAINT `scheduled_classes_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `service_inquiries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_inquiries` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `company` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `website` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `business_size` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `current_tools` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `timeline` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `budget_range` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pain_point` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `calculator_payload` json DEFAULT NULL,
  `source_page` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'new',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `service_inquiries_status_created_idx` (`status`,`created_at`),
  KEY `service_inquiries_email_idx` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_activity` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `shopify_import_exceptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shopify_import_exceptions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `shop` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `shopify_order_id` bigint unsigned DEFAULT NULL,
  `shopify_line_item_id` bigint unsigned DEFAULT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payload` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `tenant_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `sie_tenant_idx` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `shopify_import_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shopify_import_runs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `store_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_dry_run` tinyint(1) NOT NULL DEFAULT '0',
  `imported_count` int unsigned NOT NULL DEFAULT '0',
  `updated_count` int unsigned NOT NULL DEFAULT '0',
  `lines_count` int unsigned NOT NULL DEFAULT '0',
  `merged_lines_count` int unsigned NOT NULL DEFAULT '0',
  `mapping_exceptions_count` int unsigned NOT NULL DEFAULT '0',
  `started_at` timestamp NULL DEFAULT NULL,
  `finished_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `tenant_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `sir_tenant_idx` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `shopify_privacy_webhook_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shopify_privacy_webhook_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `topic` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `shop_domain` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `webhook_id` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payload_hash` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload_summary` json DEFAULT NULL,
  `status` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual_review_required',
  `action_required` tinyint(1) NOT NULL DEFAULT '1',
  `handled_at` timestamp NULL DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `spwe_webhook_id_unique` (`webhook_id`),
  KEY `spwe_topic_idx` (`topic`),
  KEY `spwe_shop_domain_idx` (`shop_domain`),
  KEY `spwe_status_action_idx` (`status`,`action_required`),
  KEY `spwe_topic_payload_hash_idx` (`topic`,`payload_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `shopify_product_option_assignments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shopify_product_option_assignments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `ruleset_id` bigint unsigned NOT NULL,
  `shopify_product_id` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `product_handle` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `product_url` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `shopify_option_assignments_product_id_index` (`tenant_id`,`shopify_product_id`),
  KEY `shopify_option_assignments_handle_index` (`tenant_id`,`product_handle`),
  KEY `shopify_option_assignments_ruleset_fk` (`ruleset_id`),
  CONSTRAINT `shopify_option_assignments_ruleset_fk` FOREIGN KEY (`ruleset_id`) REFERENCES `shopify_product_option_rulesets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `shopify_option_assignments_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `shopify_product_option_rulesets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shopify_product_option_rulesets` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `name` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `option_count` smallint unsigned NOT NULL DEFAULT '1',
  `allowed_values` json NOT NULL,
  `require_distinct_values` tinyint(1) NOT NULL DEFAULT '0',
  `enabled` tinyint(1) NOT NULL DEFAULT '1',
  `source` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'everbranch',
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `shopify_option_rulesets_tenant_name_unique` (`tenant_id`,`name`),
  KEY `shopify_option_rulesets_tenant_enabled_index` (`tenant_id`,`enabled`),
  CONSTRAINT `shopify_option_rulesets_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `shopify_stores`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shopify_stores` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned DEFAULT NULL,
  `store_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `store_role` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'retail',
  `shop_domain` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `access_token` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `scopes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `storefront_widget_settings` json DEFAULT NULL,
  `installed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `shopify_stores_store_key_unique` (`store_key`),
  UNIQUE KEY `shopify_stores_shop_domain_unique` (`shop_domain`),
  KEY `shopify_stores_tenant_id_index` (`tenant_id`),
  KEY `shopify_stores_tenant_role_index` (`tenant_id`,`store_role`),
  CONSTRAINT `shopify_stores_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sizes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sizes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `label` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `wholesale_price` decimal(8,2) DEFAULT NULL,
  `retail_price` decimal(8,2) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sizes_code_unique` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `square_customers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `square_customers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `square_customer_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `given_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `family_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reference_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `group_ids` json DEFAULT NULL,
  `segment_ids` json DEFAULT NULL,
  `preferences` json DEFAULT NULL,
  `raw_payload` json DEFAULT NULL,
  `synced_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `tenant_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `square_customers_tenant_customer_unique` (`tenant_id`,`square_customer_id`),
  KEY `square_customers_email_index` (`email`),
  KEY `square_customers_phone_index` (`phone`),
  KEY `square_customers_reference_id_index` (`reference_id`),
  KEY `square_customers_synced_at_index` (`synced_at`),
  KEY `square_customers_tenant_id_index` (`tenant_id`),
  CONSTRAINT `square_customers_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `square_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `square_orders` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `square_order_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `square_customer_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `location_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `state` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `total_money_amount` bigint DEFAULT NULL,
  `total_money_currency` varchar(8) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `closed_at` timestamp NULL DEFAULT NULL,
  `source_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `raw_tax_names` json DEFAULT NULL,
  `raw_payload` json DEFAULT NULL,
  `synced_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `tenant_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `square_orders_tenant_order_unique` (`tenant_id`,`square_order_id`),
  KEY `square_orders_square_customer_id_index` (`square_customer_id`),
  KEY `square_orders_location_id_index` (`location_id`),
  KEY `square_orders_state_index` (`state`),
  KEY `square_orders_closed_at_index` (`closed_at`),
  KEY `square_orders_source_name_index` (`source_name`),
  KEY `square_orders_synced_at_index` (`synced_at`),
  KEY `square_orders_tenant_id_index` (`tenant_id`),
  CONSTRAINT `square_orders_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `square_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `square_payments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `square_payment_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `square_order_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `square_customer_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `location_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `amount_money` bigint DEFAULT NULL,
  `currency` varchar(8) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at_source` timestamp NULL DEFAULT NULL,
  `raw_payload` json DEFAULT NULL,
  `synced_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `tenant_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `square_payments_tenant_payment_unique` (`tenant_id`,`square_payment_id`),
  KEY `square_payments_square_order_id_index` (`square_order_id`),
  KEY `square_payments_square_customer_id_index` (`square_customer_id`),
  KEY `square_payments_location_id_index` (`location_id`),
  KEY `square_payments_status_index` (`status`),
  KEY `square_payments_created_at_source_index` (`created_at_source`),
  KEY `square_payments_synced_at_index` (`synced_at`),
  KEY `square_payments_tenant_id_index` (`tenant_id`),
  CONSTRAINT `square_payments_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `stripe_webhook_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stripe_webhook_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `event_id` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_type` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'received',
  `livemode` tinyint(1) NOT NULL DEFAULT '0',
  `tenant_id` bigint unsigned DEFAULT NULL,
  `checkout_session_id` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `processed_at` timestamp NULL DEFAULT NULL,
  `payload` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `swe_event_id_unique` (`event_id`),
  KEY `swe_tenant_idx` (`tenant_id`),
  KEY `swe_type_idx` (`event_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `subscription_announcements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `subscription_announcements` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `subscription_poll_id` bigint unsigned DEFAULT NULL,
  `created_by_user_id` bigint unsigned DEFAULT NULL,
  `title` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `body` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `published_at` timestamp NULL DEFAULT NULL,
  `channels` json DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `subscription_announcements_subscription_poll_id_foreign` (`subscription_poll_id`),
  KEY `subscription_announcements_created_by_user_id_foreign` (`created_by_user_id`),
  KEY `subscription_announcements_status_idx` (`tenant_id`,`status`),
  CONSTRAINT `subscription_announcements_created_by_user_id_foreign` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `subscription_announcements_subscription_poll_id_foreign` FOREIGN KEY (`subscription_poll_id`) REFERENCES `subscription_polls` (`id`) ON DELETE SET NULL,
  CONSTRAINT `subscription_announcements_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `subscription_authorizations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `subscription_authorizations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `agreement_id` bigint unsigned NOT NULL,
  `agreement_version_id` bigint unsigned NOT NULL,
  `agreement_acceptance_id` bigint unsigned NOT NULL,
  `billing_lane` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `provider` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `purchase_key` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'authorized',
  `pricing_model` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'agreement_specific',
  `currency` varchar(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'USD',
  `billing_interval` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'month',
  `onboarding_amount_cents` int unsigned NOT NULL DEFAULT '0',
  `promotional_amount_cents` int unsigned NOT NULL DEFAULT '0',
  `promotional_cycles` int unsigned NOT NULL DEFAULT '0',
  `standard_amount_cents` int unsigned NOT NULL DEFAULT '0',
  `tax_treatment` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'provider_calculated_if_applicable',
  `tax_disclosure` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `authorized_line_items` json DEFAULT NULL,
  `provider_subscription_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `provider_plan_handle` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `authorized_at` timestamp NOT NULL,
  `last_reconciled_at` timestamp NULL DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `subscription_authorizations_agreement_version_id_unique` (`agreement_version_id`),
  KEY `subscription_authorizations_agreement_id_foreign` (`agreement_id`),
  KEY `subscription_authorizations_agreement_acceptance_id_foreign` (`agreement_acceptance_id`),
  KEY `subscription_authorizations_tenant_id_status_index` (`tenant_id`,`status`),
  CONSTRAINT `subscription_authorizations_agreement_acceptance_id_foreign` FOREIGN KEY (`agreement_acceptance_id`) REFERENCES `agreement_acceptances` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `subscription_authorizations_agreement_id_foreign` FOREIGN KEY (`agreement_id`) REFERENCES `agreements` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `subscription_authorizations_agreement_version_id_foreign` FOREIGN KEY (`agreement_version_id`) REFERENCES `agreement_versions` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `subscription_authorizations_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `subscription_billing_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `subscription_billing_attempts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `subscription_contract_id` bigint unsigned DEFAULT NULL,
  `shopify_subscription_contract_gid` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `shopify_billing_attempt_gid` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `shopify_order_gid` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `idempotency_key` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `billing_date` date DEFAULT NULL,
  `amount_cents` int unsigned NOT NULL DEFAULT '0',
  `currency` varchar(8) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'USD',
  `error_message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `next_action_url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `subscription_billing_attempts_idempotency_unique` (`tenant_id`,`idempotency_key`),
  UNIQUE KEY `subscription_billing_attempts_shopify_unique` (`tenant_id`,`shopify_billing_attempt_gid`),
  KEY `subscription_billing_attempts_subscription_contract_id_foreign` (`subscription_contract_id`),
  KEY `subscription_billing_attempts_status_idx` (`tenant_id`,`status`,`billing_date`),
  CONSTRAINT `subscription_billing_attempts_subscription_contract_id_foreign` FOREIGN KEY (`subscription_contract_id`) REFERENCES `subscription_contracts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `subscription_billing_attempts_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `subscription_candle_club_monthly_scents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `subscription_candle_club_monthly_scents` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `candle_club_scent_id` bigint unsigned DEFAULT NULL,
  `scent_id` bigint unsigned DEFAULT NULL,
  `month` smallint unsigned NOT NULL,
  `year` smallint unsigned NOT NULL,
  `title` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'chosen',
  `shopify_product_gid` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `shopify_product_handle` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `shopify_product_status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `shopify_collection_gid` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `photo_url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `photo_source` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `photo_author` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `photo_query` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `photo_metadata` json DEFAULT NULL,
  `selected_at` timestamp NULL DEFAULT NULL,
  `published_at` timestamp NULL DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `subscription_cc_monthly_scents_period_unique` (`tenant_id`,`year`,`month`),
  KEY `subscription_cc_monthly_scents_status_idx` (`tenant_id`,`status`),
  KEY `subscription_cc_monthly_scents_product_idx` (`tenant_id`,`shopify_product_gid`),
  KEY `sub_cc_monthly_recipe_fk` (`candle_club_scent_id`),
  KEY `sub_cc_monthly_scent_fk` (`scent_id`),
  CONSTRAINT `sub_cc_monthly_recipe_fk` FOREIGN KEY (`candle_club_scent_id`) REFERENCES `candle_club_scents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sub_cc_monthly_scent_fk` FOREIGN KEY (`scent_id`) REFERENCES `scents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sub_cc_monthly_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `subscription_candle_club_scent_feedback`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `subscription_candle_club_scent_feedback` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `subscription_candle_club_monthly_scent_id` bigint unsigned DEFAULT NULL,
  `subscription_contract_id` bigint unsigned DEFAULT NULL,
  `marketing_profile_id` bigint unsigned DEFAULT NULL,
  `rating` tinyint unsigned DEFAULT NULL,
  `title` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `body` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `visibility` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'candle_club',
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `exported_marketing_review_history_id` bigint unsigned DEFAULT NULL,
  `exported_at` timestamp NULL DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `subscription_cc_scent_feedback_status_idx` (`tenant_id`,`status`),
  KEY `subscription_cc_scent_feedback_month_idx` (`tenant_id`,`subscription_candle_club_monthly_scent_id`),
  KEY `subscription_cc_scent_feedback_profile_idx` (`tenant_id`,`marketing_profile_id`),
  KEY `sub_cc_feedback_month_fk` (`subscription_candle_club_monthly_scent_id`),
  KEY `sub_cc_feedback_contract_fk` (`subscription_contract_id`),
  KEY `sub_cc_feedback_profile_fk` (`marketing_profile_id`),
  KEY `sub_cc_feedback_review_fk` (`exported_marketing_review_history_id`),
  CONSTRAINT `sub_cc_feedback_contract_fk` FOREIGN KEY (`subscription_contract_id`) REFERENCES `subscription_contracts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sub_cc_feedback_month_fk` FOREIGN KEY (`subscription_candle_club_monthly_scent_id`) REFERENCES `subscription_candle_club_monthly_scents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sub_cc_feedback_profile_fk` FOREIGN KEY (`marketing_profile_id`) REFERENCES `marketing_profiles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sub_cc_feedback_review_fk` FOREIGN KEY (`exported_marketing_review_history_id`) REFERENCES `marketing_review_histories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sub_cc_feedback_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `subscription_candle_club_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `subscription_candle_club_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `commitment_months` int unsigned NOT NULL DEFAULT '6',
  `allowed_pauses_per_commitment` int unsigned NOT NULL DEFAULT '2',
  `pause_duration_options` json DEFAULT NULL,
  `renewal_reward_months` int unsigned NOT NULL DEFAULT '6',
  `first_gift_product_variant_gid` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `first_gift_label` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Free 8oz Coffeehouse candle',
  `renewal_gift_product_variant_gid` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `renewal_gift_label` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Free renewal candle',
  `cancellation_prompt` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `voting_reward_candle_cash` int unsigned NOT NULL DEFAULT '0',
  `poll_defaults` json DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `subscription_candle_club_settings_tenant_unique` (`tenant_id`),
  CONSTRAINT `subscription_candle_club_settings_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `subscription_contract_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `subscription_contract_lines` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `subscription_contract_id` bigint unsigned NOT NULL,
  `shopify_subscription_line_gid` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `shopify_product_gid` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `shopify_product_variant_gid` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `shopify_selling_plan_gid` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `product_title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `variant_title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `quantity` int unsigned NOT NULL DEFAULT '1',
  `price_cents` int unsigned NOT NULL DEFAULT '0',
  `currency` varchar(8) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'USD',
  `custom_attributes` json DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `subscription_lines_shopify_unique` (`tenant_id`,`shopify_subscription_line_gid`),
  KEY `subscription_contract_lines_subscription_contract_id_foreign` (`subscription_contract_id`),
  KEY `subscription_lines_variant_idx` (`tenant_id`,`shopify_product_variant_gid`),
  CONSTRAINT `subscription_contract_lines_subscription_contract_id_foreign` FOREIGN KEY (`subscription_contract_id`) REFERENCES `subscription_contracts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `subscription_contract_lines_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `subscription_contracts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `subscription_contracts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `subscription_customer_id` bigint unsigned DEFAULT NULL,
  `marketing_profile_id` bigint unsigned DEFAULT NULL,
  `shopify_subscription_contract_gid` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recharge_subscription_id` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `shopify_customer_gid` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `shopify_payment_method_gid` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `is_candle_club` tinyint(1) NOT NULL DEFAULT '0',
  `next_billing_date` date DEFAULT NULL,
  `next_shipping_date` date DEFAULT NULL,
  `billing_interval_count` int unsigned NOT NULL DEFAULT '1',
  `billing_interval` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'month',
  `delivery_interval_count` int unsigned NOT NULL DEFAULT '1',
  `delivery_interval` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'month',
  `completed_cycles` int unsigned NOT NULL DEFAULT '0',
  `pause_count_current_commitment` int unsigned NOT NULL DEFAULT '0',
  `commitment_started_on` date DEFAULT NULL,
  `commitment_ends_on` date DEFAULT NULL,
  `shipping_address` json DEFAULT NULL,
  `billing_address` json DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `subscription_contracts_shopify_unique` (`tenant_id`,`shopify_subscription_contract_gid`),
  UNIQUE KEY `subscription_contracts_recharge_unique` (`tenant_id`,`recharge_subscription_id`),
  KEY `subscription_contracts_subscription_customer_id_foreign` (`subscription_customer_id`),
  KEY `subscription_contracts_marketing_profile_id_foreign` (`marketing_profile_id`),
  KEY `subscription_contracts_status_idx` (`tenant_id`,`status`,`is_candle_club`),
  KEY `subscription_contracts_billing_idx` (`tenant_id`,`next_billing_date`),
  KEY `subscription_contracts_profile_idx` (`tenant_id`,`marketing_profile_id`),
  CONSTRAINT `subscription_contracts_marketing_profile_id_foreign` FOREIGN KEY (`marketing_profile_id`) REFERENCES `marketing_profiles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `subscription_contracts_subscription_customer_id_foreign` FOREIGN KEY (`subscription_customer_id`) REFERENCES `subscription_customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `subscription_contracts_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `subscription_customers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `subscription_customers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `marketing_profile_id` bigint unsigned DEFAULT NULL,
  `shopify_customer_gid` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recharge_customer_id` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `normalized_email` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `normalized_phone` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `billing_address` json DEFAULT NULL,
  `shipping_address` json DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `subscription_customers_shopify_unique` (`tenant_id`,`shopify_customer_gid`),
  UNIQUE KEY `subscription_customers_recharge_unique` (`tenant_id`,`recharge_customer_id`),
  KEY `subscription_customers_marketing_profile_id_foreign` (`marketing_profile_id`),
  KEY `subscription_customers_profile_idx` (`tenant_id`,`marketing_profile_id`),
  KEY `subscription_customers_email_idx` (`tenant_id`,`normalized_email`),
  KEY `subscription_customers_phone_idx` (`tenant_id`,`normalized_phone`),
  CONSTRAINT `subscription_customers_marketing_profile_id_foreign` FOREIGN KEY (`marketing_profile_id`) REFERENCES `marketing_profiles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `subscription_customers_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `subscription_lifecycle_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `subscription_lifecycle_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `subscription_contract_id` bigint unsigned DEFAULT NULL,
  `actor_user_id` bigint unsigned DEFAULT NULL,
  `event_type` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'evergrove',
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'recorded',
  `before_payload` json DEFAULT NULL,
  `after_payload` json DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `subscription_lifecycle_events_subscription_contract_id_foreign` (`subscription_contract_id`),
  KEY `subscription_lifecycle_events_actor_user_id_foreign` (`actor_user_id`),
  KEY `subscription_events_type_idx` (`tenant_id`,`event_type`),
  KEY `subscription_events_contract_idx` (`tenant_id`,`subscription_contract_id`),
  CONSTRAINT `subscription_lifecycle_events_actor_user_id_foreign` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `subscription_lifecycle_events_subscription_contract_id_foreign` FOREIGN KEY (`subscription_contract_id`) REFERENCES `subscription_contracts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `subscription_lifecycle_events_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `subscription_migration_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `subscription_migration_batches` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `created_by_user_id` bigint unsigned DEFAULT NULL,
  `approved_by_user_id` bigint unsigned DEFAULT NULL,
  `source` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'recharge_api',
  `mode` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'dry_run',
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `recharge_billing_paused_confirmed` tinyint(1) NOT NULL DEFAULT '0',
  `approved_at` timestamp NULL DEFAULT NULL,
  `cutover_enabled_at` timestamp NULL DEFAULT NULL,
  `summary` json DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `subscription_migration_batches_created_by_user_id_foreign` (`created_by_user_id`),
  KEY `subscription_migration_batches_approved_by_user_id_foreign` (`approved_by_user_id`),
  KEY `subscription_migration_batches_status_idx` (`tenant_id`,`status`),
  CONSTRAINT `subscription_migration_batches_approved_by_user_id_foreign` FOREIGN KEY (`approved_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `subscription_migration_batches_created_by_user_id_foreign` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `subscription_migration_batches_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `subscription_migration_rows`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `subscription_migration_rows` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `subscription_migration_batch_id` bigint unsigned NOT NULL,
  `source_type` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'subscription',
  `source_id` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `shopify_customer_gid` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `shopify_subscription_contract_gid` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recharge_customer_id` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recharge_subscription_id` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mapped_payload` json DEFAULT NULL,
  `errors` json DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `subscription_migration_rows_source_unique` (`subscription_migration_batch_id`,`source_type`,`source_id`),
  KEY `subscription_migration_rows_status_idx` (`tenant_id`,`status`),
  CONSTRAINT `subscription_migration_rows_batch_fk` FOREIGN KEY (`subscription_migration_batch_id`) REFERENCES `subscription_migration_batches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `subscription_migration_rows_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `subscription_module_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `subscription_module_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `module_key` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'subscriptions',
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'setup',
  `billing_scheduler_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `billing_scheduler_enabled_at` timestamp NULL DEFAULT NULL,
  `shopify_store_key` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `shopify_settings` json DEFAULT NULL,
  `recharge_settings` json DEFAULT NULL,
  `notification_settings` json DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `subscription_module_settings_unique` (`tenant_id`,`module_key`),
  CONSTRAINT `subscription_module_settings_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `subscription_payment_methods`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `subscription_payment_methods` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `subscription_customer_id` bigint unsigned DEFAULT NULL,
  `shopify_payment_method_gid` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `shopify_customer_gid` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `brand` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_digits` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `expiry_month` varchar(8) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `expiry_year` varchar(8) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_update_email_sent_at` timestamp NULL DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `subscription_payment_methods_unique` (`tenant_id`,`shopify_payment_method_gid`),
  KEY `subscription_payment_methods_subscription_customer_id_foreign` (`subscription_customer_id`),
  KEY `subscription_payment_methods_status_idx` (`tenant_id`,`status`),
  CONSTRAINT `subscription_payment_methods_subscription_customer_id_foreign` FOREIGN KEY (`subscription_customer_id`) REFERENCES `subscription_customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `subscription_payment_methods_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `subscription_poll_options`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `subscription_poll_options` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `subscription_poll_id` bigint unsigned NOT NULL,
  `label` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `shopify_product_variant_gid` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `scent_id` bigint unsigned DEFAULT NULL,
  `position` int unsigned NOT NULL DEFAULT '0',
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `subscription_poll_options_subscription_poll_id_foreign` (`subscription_poll_id`),
  KEY `subscription_poll_options_scent_id_foreign` (`scent_id`),
  KEY `subscription_poll_options_poll_idx` (`tenant_id`,`subscription_poll_id`),
  CONSTRAINT `subscription_poll_options_scent_id_foreign` FOREIGN KEY (`scent_id`) REFERENCES `scents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `subscription_poll_options_subscription_poll_id_foreign` FOREIGN KEY (`subscription_poll_id`) REFERENCES `subscription_polls` (`id`) ON DELETE CASCADE,
  CONSTRAINT `subscription_poll_options_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `subscription_polls`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `subscription_polls` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `poll_type` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'candle_club_scent',
  `title` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `opens_at` timestamp NULL DEFAULT NULL,
  `closes_at` timestamp NULL DEFAULT NULL,
  `share_token` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `subscription_polls_share_token_unique` (`share_token`),
  KEY `subscription_polls_status_idx` (`tenant_id`,`status`,`poll_type`),
  CONSTRAINT `subscription_polls_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `subscription_voter_verification_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `subscription_voter_verification_tokens` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `subscription_poll_id` bigint unsigned DEFAULT NULL,
  `subscription_contract_id` bigint unsigned DEFAULT NULL,
  `identifier_type` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `identifier_hash` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `code_hash` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `delivery_channel` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `expires_at` timestamp NOT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `subscription_tokens_identifier_idx` (`tenant_id`,`identifier_hash`,`status`),
  KEY `subscription_tokens_poll_idx` (`tenant_id`,`subscription_poll_id`),
  KEY `subscription_tokens_poll_fk` (`subscription_poll_id`),
  KEY `subscription_tokens_contract_fk` (`subscription_contract_id`),
  CONSTRAINT `subscription_tokens_contract_fk` FOREIGN KEY (`subscription_contract_id`) REFERENCES `subscription_contracts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `subscription_tokens_poll_fk` FOREIGN KEY (`subscription_poll_id`) REFERENCES `subscription_polls` (`id`) ON DELETE CASCADE,
  CONSTRAINT `subscription_voter_verification_tokens_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `subscription_votes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `subscription_votes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `subscription_poll_id` bigint unsigned NOT NULL,
  `subscription_poll_option_id` bigint unsigned NOT NULL,
  `subscription_contract_id` bigint unsigned DEFAULT NULL,
  `marketing_profile_id` bigint unsigned DEFAULT NULL,
  `shopify_subscription_contract_gid` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `shopify_customer_gid` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `normalized_email` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `normalized_phone` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'storefront',
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `subscription_votes_contract_unique` (`tenant_id`,`subscription_poll_id`,`shopify_subscription_contract_gid`),
  KEY `subscription_votes_subscription_poll_id_foreign` (`subscription_poll_id`),
  KEY `subscription_votes_subscription_poll_option_id_foreign` (`subscription_poll_option_id`),
  KEY `subscription_votes_subscription_contract_id_foreign` (`subscription_contract_id`),
  KEY `subscription_votes_marketing_profile_id_foreign` (`marketing_profile_id`),
  KEY `subscription_votes_option_idx` (`tenant_id`,`subscription_poll_option_id`),
  KEY `subscription_votes_email_idx` (`tenant_id`,`normalized_email`),
  CONSTRAINT `subscription_votes_marketing_profile_id_foreign` FOREIGN KEY (`marketing_profile_id`) REFERENCES `marketing_profiles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `subscription_votes_subscription_contract_id_foreign` FOREIGN KEY (`subscription_contract_id`) REFERENCES `subscription_contracts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `subscription_votes_subscription_poll_id_foreign` FOREIGN KEY (`subscription_poll_id`) REFERENCES `subscription_polls` (`id`) ON DELETE CASCADE,
  CONSTRAINT `subscription_votes_subscription_poll_option_id_foreign` FOREIGN KEY (`subscription_poll_option_id`) REFERENCES `subscription_poll_options` (`id`) ON DELETE CASCADE,
  CONSTRAINT `subscription_votes_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `team_channel_members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `team_channel_members` (
  `tenant_id` bigint unsigned NOT NULL,
  `team_channel_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `last_read_at` timestamp NULL DEFAULT NULL,
  `muted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`team_channel_id`,`user_id`),
  KEY `team_channel_member_tenant_idx` (`tenant_id`,`user_id`),
  KEY `team_channel_member_user_fk` (`user_id`),
  CONSTRAINT `team_channel_member_channel_fk` FOREIGN KEY (`team_channel_id`) REFERENCES `team_channels` (`id`) ON DELETE CASCADE,
  CONSTRAINT `team_channel_member_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `team_channel_member_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `team_channels`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `team_channels` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `field_service_job_id` bigint unsigned DEFAULT NULL,
  `created_by_user_id` bigint unsigned DEFAULT NULL,
  `kind` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `direct_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `archived_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `team_channel_job_unique` (`tenant_id`,`field_service_job_id`),
  UNIQUE KEY `team_channel_direct_unique` (`tenant_id`,`direct_key`),
  KEY `team_channel_tenant_kind_idx` (`tenant_id`,`kind`,`updated_at`),
  KEY `team_channel_job_fk` (`field_service_job_id`),
  KEY `team_channel_actor_fk` (`created_by_user_id`),
  CONSTRAINT `team_channel_actor_fk` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `team_channel_job_fk` FOREIGN KEY (`field_service_job_id`) REFERENCES `field_service_jobs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `team_channel_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `team_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `team_messages` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `team_channel_id` bigint unsigned NOT NULL,
  `created_by_user_id` bigint unsigned DEFAULT NULL,
  `parent_message_id` bigint unsigned DEFAULT NULL,
  `client_uuid` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `body` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `mention_user_ids` json DEFAULT NULL,
  `reactions` json DEFAULT NULL,
  `edited_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `team_message_idempotency_unique` (`tenant_id`,`created_by_user_id`,`client_uuid`),
  KEY `team_message_channel_time_idx` (`team_channel_id`,`created_at`),
  KEY `team_message_actor_fk` (`created_by_user_id`),
  KEY `team_message_parent_fk` (`parent_message_id`),
  CONSTRAINT `team_message_actor_fk` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `team_message_channel_fk` FOREIGN KEY (`team_channel_id`) REFERENCES `team_channels` (`id`) ON DELETE CASCADE,
  CONSTRAINT `team_message_parent_fk` FOREIGN KEY (`parent_message_id`) REFERENCES `team_messages` (`id`) ON DELETE SET NULL,
  CONSTRAINT `team_message_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tenant_access_addons`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tenant_access_addons` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `addon_key` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT '1',
  `source` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `starts_at` timestamp NULL DEFAULT NULL,
  `ends_at` timestamp NULL DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenant_access_addons_unique` (`tenant_id`,`addon_key`),
  KEY `tenant_access_addons_key_enabled_index` (`addon_key`,`enabled`),
  CONSTRAINT `tenant_access_addons_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tenant_access_profiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tenant_access_profiles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `plan_key` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'shopify_proof_of_concept',
  `operating_mode` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'shopify',
  `source` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenant_access_profiles_tenant_id_unique` (`tenant_id`),
  KEY `tenant_access_profiles_plan_key_index` (`plan_key`),
  KEY `tenant_access_profiles_mode_index` (`operating_mode`),
  CONSTRAINT `tenant_access_profiles_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tenant_billing_fulfillments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tenant_billing_fulfillments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `provider` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'stripe',
  `provider_customer_reference` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `provider_subscription_reference` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `provider_checkout_session_id` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `state_hash` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `desired_plan_key` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `desired_addon_keys` json DEFAULT NULL,
  `desired_operating_mode` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'attempted',
  `message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `source_event_id` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_event_type` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `triggered_by` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'webhook',
  `actor_user_id` bigint unsigned DEFAULT NULL,
  `attempted_at` timestamp NULL DEFAULT NULL,
  `applied_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tbf_unique_state_hash` (`tenant_id`,`provider`,`state_hash`),
  KEY `tbf_tenant_provider_idx` (`tenant_id`,`provider`),
  KEY `tbf_subscription_idx` (`provider_subscription_reference`),
  KEY `tbf_status_idx` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tenant_billing_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tenant_billing_orders` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `agreement_id` bigint unsigned NOT NULL,
  `agreement_version_id` bigint unsigned NOT NULL,
  `agreement_acceptance_id` bigint unsigned NOT NULL,
  `subscription_authorization_id` bigint unsigned DEFAULT NULL,
  `order_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'authorized',
  `provider` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'stripe',
  `currency` varchar(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'USD',
  `line_items` json NOT NULL,
  `authorized_subtotal_cents` bigint unsigned NOT NULL DEFAULT '0',
  `provider_tax_cents` bigint unsigned NOT NULL DEFAULT '0',
  `provider_total_cents` bigint unsigned NOT NULL DEFAULT '0',
  `provider_checkout_session_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `provider_customer_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `provider_payment_intent_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `provider_invoice_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `provider_subscription_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `provider_schedule_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_provider_event_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_provider_event_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `authorized_at` timestamp NOT NULL,
  `checkout_started_at` timestamp NULL DEFAULT NULL,
  `processing_at` timestamp NULL DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `failed_at` timestamp NULL DEFAULT NULL,
  `refunded_at` timestamp NULL DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenant_billing_orders_agreement_version_id_order_type_unique` (`agreement_version_id`,`order_type`),
  UNIQUE KEY `tenant_billing_orders_provider_checkout_session_id_unique` (`provider_checkout_session_id`),
  KEY `tenant_billing_orders_agreement_id_foreign` (`agreement_id`),
  KEY `tenant_billing_orders_agreement_acceptance_id_foreign` (`agreement_acceptance_id`),
  KEY `tenant_billing_orders_subscription_authorization_id_foreign` (`subscription_authorization_id`),
  KEY `tenant_billing_orders_tenant_id_status_index` (`tenant_id`,`status`),
  KEY `tenant_billing_orders_provider_customer_id_index` (`provider_customer_id`),
  KEY `tenant_billing_orders_provider_payment_intent_id_index` (`provider_payment_intent_id`),
  KEY `tenant_billing_orders_provider_invoice_id_index` (`provider_invoice_id`),
  KEY `tenant_billing_orders_provider_subscription_id_index` (`provider_subscription_id`),
  KEY `tenant_billing_orders_provider_schedule_id_index` (`provider_schedule_id`),
  CONSTRAINT `tenant_billing_orders_agreement_acceptance_id_foreign` FOREIGN KEY (`agreement_acceptance_id`) REFERENCES `agreement_acceptances` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `tenant_billing_orders_agreement_id_foreign` FOREIGN KEY (`agreement_id`) REFERENCES `agreements` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `tenant_billing_orders_agreement_version_id_foreign` FOREIGN KEY (`agreement_version_id`) REFERENCES `agreement_versions` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `tenant_billing_orders_subscription_authorization_id_foreign` FOREIGN KEY (`subscription_authorization_id`) REFERENCES `subscription_authorizations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tenant_billing_orders_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tenant_billing_receipts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tenant_billing_receipts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `tenant_billing_order_id` bigint unsigned DEFAULT NULL,
  `tenant_direct_invoice_id` bigint unsigned DEFAULT NULL,
  `subscription_authorization_id` bigint unsigned DEFAULT NULL,
  `provider` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `provider_receipt_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `provider_subscription_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `invoice_number` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `currency` varchar(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'USD',
  `subtotal_amount_cents` bigint unsigned NOT NULL DEFAULT '0',
  `tax_amount_cents` bigint unsigned NOT NULL DEFAULT '0',
  `total_amount_cents` bigint unsigned NOT NULL DEFAULT '0',
  `provider_calculated_tax` tinyint(1) NOT NULL DEFAULT '1',
  `tax_jurisdiction` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `billing_period_starts_at` timestamp NULL DEFAULT NULL,
  `billing_period_ends_at` timestamp NULL DEFAULT NULL,
  `billed_at` timestamp NULL DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `hosted_invoice_url` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `receipt_url` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `source_event_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenant_billing_receipts_provider_provider_receipt_id_unique` (`provider`,`provider_receipt_id`),
  KEY `tenant_billing_receipts_subscription_authorization_id_foreign` (`subscription_authorization_id`),
  KEY `tenant_billing_receipts_tenant_id_billed_at_index` (`tenant_id`,`billed_at`),
  KEY `tenant_billing_receipts_tenant_billing_order_id_foreign` (`tenant_billing_order_id`),
  KEY `tenant_billing_receipts_tenant_direct_invoice_id_foreign` (`tenant_direct_invoice_id`),
  CONSTRAINT `tenant_billing_receipts_subscription_authorization_id_foreign` FOREIGN KEY (`subscription_authorization_id`) REFERENCES `subscription_authorizations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tenant_billing_receipts_tenant_billing_order_id_foreign` FOREIGN KEY (`tenant_billing_order_id`) REFERENCES `tenant_billing_orders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tenant_billing_receipts_tenant_direct_invoice_id_foreign` FOREIGN KEY (`tenant_direct_invoice_id`) REFERENCES `tenant_direct_invoices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tenant_billing_receipts_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tenant_billing_refunds`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tenant_billing_refunds` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `tenant_billing_receipt_id` bigint unsigned NOT NULL,
  `tenant_billing_order_id` bigint unsigned DEFAULT NULL,
  `tenant_direct_invoice_id` bigint unsigned DEFAULT NULL,
  `requested_by_user_id` bigint unsigned DEFAULT NULL,
  `provider` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `provider_refund_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `provider_payment_intent_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `amount_cents` bigint unsigned NOT NULL,
  `currency` varchar(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'USD',
  `reason` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'requested_by_customer',
  `note` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `idempotency_key` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `processed_at` timestamp NULL DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenant_billing_refunds_idempotency_key_unique` (`idempotency_key`),
  UNIQUE KEY `tenant_billing_refunds_provider_refund_id_unique` (`provider_refund_id`),
  KEY `tenant_billing_refunds_tenant_billing_order_id_foreign` (`tenant_billing_order_id`),
  KEY `tenant_billing_refunds_tenant_direct_invoice_id_foreign` (`tenant_direct_invoice_id`),
  KEY `tenant_billing_refunds_requested_by_user_id_foreign` (`requested_by_user_id`),
  KEY `tenant_billing_refunds_tenant_id_status_index` (`tenant_id`,`status`),
  KEY `billing_refunds_receipt_created_idx` (`tenant_billing_receipt_id`,`created_at`),
  CONSTRAINT `tenant_billing_refunds_requested_by_user_id_foreign` FOREIGN KEY (`requested_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tenant_billing_refunds_tenant_billing_order_id_foreign` FOREIGN KEY (`tenant_billing_order_id`) REFERENCES `tenant_billing_orders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tenant_billing_refunds_tenant_billing_receipt_id_foreign` FOREIGN KEY (`tenant_billing_receipt_id`) REFERENCES `tenant_billing_receipts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `tenant_billing_refunds_tenant_direct_invoice_id_foreign` FOREIGN KEY (`tenant_direct_invoice_id`) REFERENCES `tenant_direct_invoices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tenant_billing_refunds_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tenant_billing_subscriptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tenant_billing_subscriptions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `provider` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `provider_customer_reference` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `provider_subscription_reference` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `purchase_key` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `started_at` timestamp NULL DEFAULT NULL,
  `current_period_ends_at` timestamp NULL DEFAULT NULL,
  `canceled_at` timestamp NULL DEFAULT NULL,
  `ended_at` timestamp NULL DEFAULT NULL,
  `last_event_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_event_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenant_billing_subscriptions_provider_purchase_unique` (`provider`,`provider_subscription_reference`,`purchase_key`),
  KEY `tenant_billing_subscriptions_tenant_id_status_index` (`tenant_id`,`status`),
  CONSTRAINT `tenant_billing_subscriptions_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tenant_brand_assets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tenant_brand_assets` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `tenant_brand_profile_id` bigint unsigned NOT NULL,
  `uploaded_by_user_id` bigint unsigned DEFAULT NULL,
  `kind` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `label` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'bundled',
  `storage_disk` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime_type` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_size` bigint unsigned DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `brand_assets_profile_kind_path_uq` (`tenant_brand_profile_id`,`kind`,`path`),
  KEY `tenant_brand_assets_uploaded_by_user_id_foreign` (`uploaded_by_user_id`),
  KEY `brand_assets_tenant_kind_idx` (`tenant_id`,`kind`),
  CONSTRAINT `tenant_brand_assets_tenant_brand_profile_id_foreign` FOREIGN KEY (`tenant_brand_profile_id`) REFERENCES `tenant_brand_profiles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tenant_brand_assets_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tenant_brand_assets_uploaded_by_user_id_foreign` FOREIGN KEY (`uploaded_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tenant_brand_profiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tenant_brand_profiles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `display_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `tagline` varchar(180) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `light_logo_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dark_logo_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `icon_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `asset_sources` json DEFAULT NULL,
  `primary_color` varchar(7) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#123C43',
  `accent_color` varchar(7) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#1E5A63',
  `surface_color` varchar(7) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#FFFFFF',
  `text_color` varchar(7) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#0F1C1F',
  `display_style` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'classic',
  `corner_style` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'soft',
  `decor_preset` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none',
  `theme_key` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'custom',
  `metadata` json DEFAULT NULL,
  `created_by_user_id` bigint unsigned DEFAULT NULL,
  `updated_by_user_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenant_brand_profiles_tenant_id_unique` (`tenant_id`),
  KEY `tenant_brand_profiles_created_by_user_id_foreign` (`created_by_user_id`),
  KEY `tenant_brand_profiles_updated_by_user_id_foreign` (`updated_by_user_id`),
  CONSTRAINT `tenant_brand_profiles_created_by_user_id_foreign` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tenant_brand_profiles_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tenant_brand_profiles_updated_by_user_id_foreign` FOREIGN KEY (`updated_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tenant_bud_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tenant_bud_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'disabled',
  `ai_status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'disabled',
  `ai_monthly_budget_cents` int unsigned NOT NULL DEFAULT '0',
  `ai_used_cents` int unsigned NOT NULL DEFAULT '0',
  `ai_period_started_at` timestamp NULL DEFAULT NULL,
  `requested_by_user_id` bigint unsigned DEFAULT NULL,
  `reviewed_by_user_id` bigint unsigned DEFAULT NULL,
  `requested_at` timestamp NULL DEFAULT NULL,
  `ai_requested_at` timestamp NULL DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `ai_reviewed_at` timestamp NULL DEFAULT NULL,
  `review_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `ai_review_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `ai_requested_by_user_id` bigint unsigned DEFAULT NULL,
  `ai_reviewed_by_user_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenant_bud_settings_tenant_id_unique` (`tenant_id`),
  KEY `tenant_bud_settings_requested_by_user_id_foreign` (`requested_by_user_id`),
  KEY `tenant_bud_settings_reviewed_by_user_id_foreign` (`reviewed_by_user_id`),
  KEY `tenant_bud_settings_ai_requested_by_user_id_foreign` (`ai_requested_by_user_id`),
  KEY `tenant_bud_settings_ai_reviewed_by_user_id_foreign` (`ai_reviewed_by_user_id`),
  KEY `bud_ai_status_idx` (`ai_status`),
  CONSTRAINT `tenant_bud_settings_ai_requested_by_user_id_foreign` FOREIGN KEY (`ai_requested_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tenant_bud_settings_ai_reviewed_by_user_id_foreign` FOREIGN KEY (`ai_reviewed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tenant_bud_settings_requested_by_user_id_foreign` FOREIGN KEY (`requested_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tenant_bud_settings_reviewed_by_user_id_foreign` FOREIGN KEY (`reviewed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tenant_bud_settings_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tenant_candle_cash_reward_overrides`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tenant_candle_cash_reward_overrides` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `candle_cash_reward_id` bigint unsigned NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `candle_cash_cost` int unsigned NOT NULL,
  `reward_value` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenant_cc_reward_overrides_tenant_reward_unique` (`tenant_id`,`candle_cash_reward_id`),
  KEY `tenant_cc_reward_override_reward_fk` (`candle_cash_reward_id`),
  KEY `tenant_cc_reward_overrides_tenant_active_idx` (`tenant_id`,`is_active`),
  CONSTRAINT `tenant_candle_cash_reward_overrides_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tenant_cc_reward_override_reward_fk` FOREIGN KEY (`candle_cash_reward_id`) REFERENCES `candle_cash_rewards` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tenant_candle_cash_task_overrides`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tenant_candle_cash_task_overrides` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `candle_cash_task_id` bigint unsigned NOT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `reward_amount` decimal(8,2) NOT NULL DEFAULT '0.00',
  `enabled` tinyint(1) NOT NULL DEFAULT '1',
  `display_order` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenant_cc_task_overrides_tenant_task_unique` (`tenant_id`,`candle_cash_task_id`),
  KEY `tenant_candle_cash_task_overrides_candle_cash_task_id_foreign` (`candle_cash_task_id`),
  KEY `tenant_cc_task_overrides_tenant_order_idx` (`tenant_id`,`display_order`),
  CONSTRAINT `tenant_candle_cash_task_overrides_candle_cash_task_id_foreign` FOREIGN KEY (`candle_cash_task_id`) REFERENCES `candle_cash_tasks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tenant_candle_cash_task_overrides_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tenant_commercial_overrides`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tenant_commercial_overrides` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `template_key` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `store_channel_allowance` int unsigned DEFAULT NULL,
  `plan_pricing_overrides` json DEFAULT NULL,
  `addon_pricing_overrides` json DEFAULT NULL,
  `included_usage_overrides` json DEFAULT NULL,
  `display_labels` json DEFAULT NULL,
  `billing_mapping` json DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenant_commercial_overrides_tenant_id_unique` (`tenant_id`),
  CONSTRAINT `tenant_commercial_overrides_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tenant_direct_invoices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tenant_direct_invoices` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `currency` varchar(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'USD',
  `customer_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `customer_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `customer_phone` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `billing_address` json NOT NULL,
  `days_until_due` smallint unsigned NOT NULL DEFAULT '30',
  `authorization_reference` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `memo` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `footer` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `line_items` json NOT NULL,
  `authorized_subtotal_cents` bigint unsigned NOT NULL,
  `provider_tax_cents` bigint unsigned NOT NULL DEFAULT '0',
  `provider_total_cents` bigint unsigned NOT NULL DEFAULT '0',
  `provider_amount_due_cents` bigint unsigned NOT NULL DEFAULT '0',
  `provider_customer_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `provider_invoice_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `provider_payment_intent_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `provider_invoice_number` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `hosted_invoice_url` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `invoice_pdf_url` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `last_provider_event_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_provider_event_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `finalized_at` timestamp NULL DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `failed_at` timestamp NULL DEFAULT NULL,
  `voided_at` timestamp NULL DEFAULT NULL,
  `refunded_at` timestamp NULL DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenant_direct_invoices_provider_invoice_id_unique` (`provider_invoice_id`),
  KEY `tenant_direct_invoices_created_by_foreign` (`created_by`),
  KEY `tenant_direct_invoices_tenant_id_status_index` (`tenant_id`,`status`),
  KEY `tenant_direct_invoices_tenant_id_customer_email_index` (`tenant_id`,`customer_email`),
  KEY `tenant_direct_invoices_provider_customer_id_index` (`provider_customer_id`),
  KEY `tenant_direct_invoices_provider_payment_intent_id_index` (`provider_payment_intent_id`),
  CONSTRAINT `tenant_direct_invoices_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tenant_direct_invoices_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tenant_discovery_pages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tenant_discovery_pages` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `page_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `page_type` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `meta_description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `summary` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `intent_label` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `audience_type` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recommended_domain_role` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `canonical_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cta_label` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cta_url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `service_regions` json DEFAULT NULL,
  `keywords` json DEFAULT NULL,
  `faq_items` json DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `position` int unsigned NOT NULL DEFAULT '0',
  `is_public` tinyint(1) NOT NULL DEFAULT '1',
  `is_indexable` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenant_discovery_pages_tenant_key_unique` (`tenant_id`,`page_key`),
  KEY `tenant_discovery_pages_public_idx` (`tenant_id`,`is_public`,`is_indexable`),
  KEY `tenant_discovery_pages_type_idx` (`tenant_id`,`page_type`),
  CONSTRAINT `tenant_discovery_pages_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tenant_discovery_profiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tenant_discovery_profiles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `primary_brand_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `alternate_brand_names` json DEFAULT NULL,
  `wholesale_brand_label` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `retail_brand_label` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `short_brand_summary` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `long_form_description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `support_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `support_phone` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `social_profiles` json DEFAULT NULL,
  `primary_logo_url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `brand_keywords` json DEFAULT NULL,
  `why_choose_us_bullets` json DEFAULT NULL,
  `domain_map` json DEFAULT NULL,
  `canonical_rules` json DEFAULT NULL,
  `geography` json DEFAULT NULL,
  `audience_map` json DEFAULT NULL,
  `trust_facts` json DEFAULT NULL,
  `merchant_signals` json DEFAULT NULL,
  `placeholders` json DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenant_discovery_profiles_tenant_unique` (`tenant_id`),
  KEY `tenant_discovery_profiles_active_idx` (`is_active`),
  CONSTRAINT `tenant_discovery_profiles_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tenant_email_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tenant_email_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `email_provider` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'sendgrid',
  `email_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `from_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `from_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reply_to_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `provider_status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'not_configured',
  `provider_status_checked_at` timestamp NULL DEFAULT NULL,
  `provider_status_message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `provider_config` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `analytics_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `last_tested_at` timestamp NULL DEFAULT NULL,
  `last_error` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenant_email_settings_tenant_id_unique` (`tenant_id`),
  KEY `tenant_email_settings_provider_index` (`email_provider`),
  CONSTRAINT `tenant_email_settings_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tenant_employee_invitations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tenant_employee_invitations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `invited_by_user_id` bigint unsigned DEFAULT NULL,
  `accepted_by_user_id` bigint unsigned DEFAULT NULL,
  `phone` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `role` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'member',
  `token_hash` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `delivery_status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'not_sent',
  `provider_message_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `delivery_error` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `expires_at` timestamp NOT NULL,
  `accepted_at` timestamp NULL DEFAULT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenant_employee_invitations_token_hash_unique` (`token_hash`),
  KEY `tenant_employee_invite_status_idx` (`tenant_id`,`status`,`expires_at`),
  KEY `tenant_employee_invite_actor_fk` (`invited_by_user_id`),
  KEY `tenant_employee_invite_user_fk` (`accepted_by_user_id`),
  CONSTRAINT `tenant_employee_invite_actor_fk` FOREIGN KEY (`invited_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tenant_employee_invite_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tenant_employee_invite_user_fk` FOREIGN KEY (`accepted_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tenant_fleet_tracking_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tenant_fleet_tracking_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `phone_tracking_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `bouncie_tracking_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `policy_version` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `policy_sha256` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `counsel_review_reference` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `legal_reviewed_at` timestamp NULL DEFAULT NULL,
  `legal_reviewed_by_user_id` bigint unsigned DEFAULT NULL,
  `retention_days` smallint unsigned NOT NULL DEFAULT '30',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ft_setting_tenant_unique` (`tenant_id`),
  KEY `ft_setting_legal_by_fk` (`legal_reviewed_by_user_id`),
  CONSTRAINT `ft_setting_legal_by_fk` FOREIGN KEY (`legal_reviewed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `ft_setting_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tenant_forms`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tenant_forms` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `form_template_id` bigint unsigned DEFAULT NULL,
  `slug` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `channel` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'backstage',
  `schema` json DEFAULT NULL,
  `destination` json DEFAULT NULL,
  `settings` json DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenant_forms_tenant_slug_unique` (`tenant_id`,`slug`),
  KEY `tenant_forms_form_template_id_foreign` (`form_template_id`),
  KEY `tenant_forms_created_by_foreign` (`created_by`),
  KEY `tenant_forms_updated_by_foreign` (`updated_by`),
  KEY `tenant_forms_status_index` (`status`),
  KEY `tenant_forms_channel_index` (`channel`),
  CONSTRAINT `tenant_forms_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tenant_forms_form_template_id_foreign` FOREIGN KEY (`form_template_id`) REFERENCES `form_templates` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tenant_forms_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tenant_forms_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tenant_marketing_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tenant_marketing_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` json DEFAULT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenant_marketing_settings_tenant_key_unique` (`tenant_id`,`key`),
  KEY `tenant_marketing_settings_key_index` (`key`),
  CONSTRAINT `tenant_marketing_settings_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tenant_member_preferences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tenant_member_preferences` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `phone` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `phone_verified_at` timestamp NULL DEFAULT NULL,
  `push_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `operational_sms_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `operational_sms_opted_in_at` timestamp NULL DEFAULT NULL,
  `job_comment_notifications` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'participating',
  `upcoming_job_notifications` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenant_member_preference_unique` (`tenant_id`,`user_id`),
  KEY `tenant_member_pref_user_fk` (`user_id`),
  CONSTRAINT `tenant_member_pref_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tenant_member_pref_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tenant_messaging_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tenant_messaging_accounts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `channel` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `provider` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `mode` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'platform_managed',
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'not_configured',
  `provider_account_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `provider_resource_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sender_identifier` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `authenticated_domain` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `credentials` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `provider_config` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `dns_records` json DEFAULT NULL,
  `registration` json DEFAULT NULL,
  `diagnostics` json DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `suspended_at` timestamp NULL DEFAULT NULL,
  `last_error_at` timestamp NULL DEFAULT NULL,
  `last_error_code` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_error_message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenant_messaging_accounts_tenant_channel_unique` (`tenant_id`,`channel`),
  KEY `tenant_messaging_accounts_provider_account_idx` (`provider`,`provider_account_id`),
  KEY `tenant_messaging_accounts_tenant_status_idx` (`tenant_id`,`status`),
  CONSTRAINT `tenant_messaging_accounts_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tenant_messaging_credit_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tenant_messaging_credit_accounts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `currency` varchar(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'USD',
  `balance_micros` bigint unsigned NOT NULL DEFAULT '0',
  `reserved_micros` bigint unsigned NOT NULL DEFAULT '0',
  `low_balance_threshold_micros` bigint unsigned NOT NULL DEFAULT '5000000',
  `last_funded_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenant_messaging_credit_accounts_tenant_id_unique` (`tenant_id`),
  CONSTRAINT `tenant_messaging_credit_accounts_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tenant_messaging_ledger_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tenant_messaging_ledger_entries` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `tenant_messaging_credit_account_id` bigint unsigned DEFAULT NULL,
  `tenant_messaging_usage_period_id` bigint unsigned DEFAULT NULL,
  `entry_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'settled',
  `channel` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `unit_type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `units` bigint unsigned NOT NULL DEFAULT '0',
  `amount_micros` bigint NOT NULL DEFAULT '0',
  `provider_cost_micros` bigint unsigned NOT NULL DEFAULT '0',
  `pricing_version` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `idempotency_key` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_type` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_id` bigint unsigned DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `occurred_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenant_messaging_ledger_idempotency_unique` (`tenant_id`,`idempotency_key`),
  KEY `tm_ledger_credit_fk` (`tenant_messaging_credit_account_id`),
  KEY `tm_ledger_period_fk` (`tenant_messaging_usage_period_id`),
  KEY `tenant_messaging_ledger_tenant_date_idx` (`tenant_id`,`occurred_at`),
  KEY `tenant_messaging_ledger_source_idx` (`source_type`,`source_id`),
  CONSTRAINT `tenant_messaging_ledger_entries_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tm_ledger_credit_fk` FOREIGN KEY (`tenant_messaging_credit_account_id`) REFERENCES `tenant_messaging_credit_accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tm_ledger_period_fk` FOREIGN KEY (`tenant_messaging_usage_period_id`) REFERENCES `tenant_messaging_usage_periods` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tenant_messaging_sender_profiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tenant_messaging_sender_profiles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `tenant_messaging_account_id` bigint unsigned DEFAULT NULL,
  `channel` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'email',
  `store_key` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `label` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `display_name` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `from_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `reply_to_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `authenticated_domain` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `reply_mode` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'everbranch_inbox',
  `verification_status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `verified_at` timestamp NULL DEFAULT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT '0',
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenant_sender_profiles_tenant_store_from_unique` (`tenant_id`,`store_key`,`from_email`),
  KEY `tm_sender_account_fk` (`tenant_messaging_account_id`),
  KEY `tenant_sender_profiles_default_idx` (`tenant_id`,`channel`,`is_default`),
  CONSTRAINT `tenant_messaging_sender_profiles_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tm_sender_account_fk` FOREIGN KEY (`tenant_messaging_account_id`) REFERENCES `tenant_messaging_accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tenant_messaging_usage_periods`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tenant_messaging_usage_periods` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `channel` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `included_units` bigint unsigned NOT NULL DEFAULT '0',
  `used_units` bigint unsigned NOT NULL DEFAULT '0',
  `reserved_units` bigint unsigned NOT NULL DEFAULT '0',
  `provider_cost_micros` bigint unsigned NOT NULL DEFAULT '0',
  `buyer_charge_micros` bigint unsigned NOT NULL DEFAULT '0',
  `tenant_direct_invoice_id` bigint unsigned DEFAULT NULL,
  `invoiced_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenant_messaging_usage_period_unique` (`tenant_id`,`channel`,`period_start`),
  KEY `tenant_messaging_usage_period_end_idx` (`tenant_id`,`period_end`),
  KEY `tm_usage_period_invoice_fk` (`tenant_direct_invoice_id`),
  KEY `tm_usage_period_invoice_due_idx` (`tenant_id`,`period_end`,`invoiced_at`),
  CONSTRAINT `tenant_messaging_usage_periods_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tm_usage_period_invoice_fk` FOREIGN KEY (`tenant_direct_invoice_id`) REFERENCES `tenant_direct_invoices` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tenant_module_access_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tenant_module_access_requests` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `module_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `requested_by` bigint unsigned DEFAULT NULL,
  `resolved_by` bigint unsigned DEFAULT NULL,
  `source` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `request_reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `request_note` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `decision_note` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `metadata` json DEFAULT NULL,
  `requested_at` timestamp NULL DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `tenant_module_access_requests_requested_by_foreign` (`requested_by`),
  KEY `tenant_module_access_requests_resolved_by_foreign` (`resolved_by`),
  KEY `tenant_module_access_requests_tenant_id_module_key_index` (`tenant_id`,`module_key`),
  KEY `tenant_module_access_requests_tenant_id_status_index` (`tenant_id`,`status`),
  KEY `tenant_module_access_requests_status_index` (`status`),
  CONSTRAINT `tenant_module_access_requests_requested_by_foreign` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tenant_module_access_requests_resolved_by_foreign` FOREIGN KEY (`resolved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tenant_module_access_requests_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tenant_module_entitlements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tenant_module_entitlements` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `module_key` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `availability_status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'available',
  `enabled_status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'inherit',
  `billing_status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `price_override_cents` int DEFAULT NULL,
  `currency` varchar(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'USD',
  `entitlement_source` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `price_source` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `starts_at` timestamp NULL DEFAULT NULL,
  `ends_at` timestamp NULL DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `metadata` json DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenant_module_entitlements_tenant_module_unique` (`tenant_id`,`module_key`),
  KEY `tenant_module_entitlements_module_availability_index` (`module_key`,`availability_status`),
  KEY `tenant_module_entitlements_billing_enabled_index` (`billing_status`,`enabled_status`),
  KEY `tenant_module_entitlements_created_by_fk` (`created_by`),
  KEY `tenant_module_entitlements_updated_by_fk` (`updated_by`),
  CONSTRAINT `tenant_module_entitlements_created_by_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tenant_module_entitlements_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tenant_module_entitlements_updated_by_fk` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tenant_module_states`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tenant_module_states` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `module_key` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `enabled_override` tinyint(1) DEFAULT NULL,
  `setup_status` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'not_started',
  `setup_completed_at` timestamp NULL DEFAULT NULL,
  `coming_soon_override` tinyint(1) DEFAULT NULL,
  `upgrade_prompt_override` tinyint(1) DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenant_module_states_unique` (`tenant_id`,`module_key`),
  KEY `tenant_module_states_key_setup_index` (`module_key`,`setup_status`),
  CONSTRAINT `tenant_module_states_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tenant_onboarding_blueprint_provisionings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tenant_onboarding_blueprint_provisionings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `source_blueprint_id` bigint unsigned NOT NULL,
  `provisioned_tenant_id` bigint unsigned DEFAULT NULL,
  `created_by_user_id` bigint unsigned DEFAULT NULL,
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'completed',
  `first_opened_at` timestamp NULL DEFAULT NULL,
  `first_open_acknowledged_by_user_id` bigint unsigned DEFAULT NULL,
  `first_open_payload_anchor` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `first_open_opened_path` varchar(2048) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenant_onboarding_blueprint_provisionings_blueprint_unique` (`source_blueprint_id`),
  KEY `tenant_onboarding_blueprint_provisionings_provisioned_idx` (`provisioned_tenant_id`),
  KEY `onb_bp_prov_tenant_fk` (`tenant_id`),
  KEY `onb_bp_prov_creator_fk` (`created_by_user_id`),
  KEY `onb_bp_prov_first_open_actor_fk` (`first_open_acknowledged_by_user_id`),
  CONSTRAINT `onb_bp_prov_creator_fk` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `onb_bp_prov_first_open_actor_fk` FOREIGN KEY (`first_open_acknowledged_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `onb_bp_prov_prov_tenant_fk` FOREIGN KEY (`provisioned_tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `onb_bp_prov_src_bp_fk` FOREIGN KEY (`source_blueprint_id`) REFERENCES `tenant_onboarding_blueprints` (`id`) ON DELETE CASCADE,
  CONSTRAINT `onb_bp_prov_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tenant_onboarding_blueprints`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tenant_onboarding_blueprints` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `created_by_user_id` bigint unsigned DEFAULT NULL,
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `account_mode` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'production',
  `rail` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'direct',
  `blueprint_version` int unsigned NOT NULL DEFAULT '1',
  `payload` json DEFAULT NULL,
  `origin` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `tenant_onboarding_blueprints_created_by_user_id_foreign` (`created_by_user_id`),
  KEY `tenant_onboarding_blueprints_tenant_status_idx` (`tenant_id`,`status`),
  KEY `tenant_onboarding_blueprints_tenant_rail_idx` (`tenant_id`,`rail`),
  CONSTRAINT `tenant_onboarding_blueprints_created_by_user_id_foreign` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tenant_onboarding_blueprints_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tenant_onboarding_journey_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tenant_onboarding_journey_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `final_blueprint_id` bigint unsigned DEFAULT NULL,
  `event_key` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `occurred_at` timestamp NOT NULL,
  `actor_user_id` bigint unsigned DEFAULT NULL,
  `dedupe_key` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenant_onboarding_journey_events_dedupe_key_unique` (`dedupe_key`),
  KEY `tenant_onboarding_journey_events_actor_user_id_foreign` (`actor_user_id`),
  KEY `tenant_onboarding_journey_events_tenant_key_idx` (`tenant_id`,`event_key`,`occurred_at`),
  KEY `tenant_onboarding_journey_events_blueprint_key_idx` (`final_blueprint_id`,`event_key`,`occurred_at`),
  CONSTRAINT `tenant_onboarding_journey_events_actor_user_id_foreign` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tenant_onboarding_journey_events_final_blueprint_id_foreign` FOREIGN KEY (`final_blueprint_id`) REFERENCES `tenant_onboarding_blueprints` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tenant_onboarding_journey_events_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tenant_payment_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tenant_payment_accounts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `provider` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'stripe_connect',
  `provider_account_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'not_started',
  `charges_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `payouts_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `details_submitted` tinyint(1) NOT NULL DEFAULT '0',
  `platform_fee_bps` int unsigned NOT NULL DEFAULT '0',
  `onboarding_started_at` timestamp NULL DEFAULT NULL,
  `onboarding_completed_at` timestamp NULL DEFAULT NULL,
  `last_checked_at` timestamp NULL DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenant_payment_accounts_tenant_id_unique` (`tenant_id`),
  UNIQUE KEY `tenant_payment_accounts_provider_account_id_unique` (`provider_account_id`),
  KEY `tenant_payment_accounts_status_index` (`status`),
  CONSTRAINT `tenant_payment_accounts_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tenant_plant_inventory_adjustments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tenant_plant_inventory_adjustments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `plant_inventory_item_id` bigint unsigned NOT NULL,
  `performed_by_user_id` bigint unsigned DEFAULT NULL,
  `adjustment_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantity_delta` int NOT NULL DEFAULT '0',
  `reserved_delta` int NOT NULL DEFAULT '0',
  `before_quantity_on_hand` int NOT NULL,
  `after_quantity_on_hand` int NOT NULL,
  `before_reserved_quantity` int NOT NULL,
  `after_reserved_quantity` int NOT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `tpi_adjustments_item_fk` (`plant_inventory_item_id`),
  KEY `tpi_adjustments_actor_fk` (`performed_by_user_id`),
  KEY `tenant_plant_adjustments_scope_idx` (`tenant_id`,`plant_inventory_item_id`),
  KEY `tenant_plant_inventory_adjustments_adjustment_type_index` (`adjustment_type`),
  CONSTRAINT `tpi_adjustments_actor_fk` FOREIGN KEY (`performed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tpi_adjustments_item_fk` FOREIGN KEY (`plant_inventory_item_id`) REFERENCES `tenant_plant_inventory_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tpi_adjustments_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tenant_plant_inventory_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tenant_plant_inventory_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `category` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sku` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `vendor_source` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `purchased_cost` decimal(10,2) DEFAULT NULL,
  `sell_price` decimal(10,2) DEFAULT NULL,
  `quantity_on_hand` int NOT NULL DEFAULT '0',
  `reserved_quantity` int NOT NULL DEFAULT '0',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `square_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `shopify_product_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `shopify_variant_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenant_plant_inventory_items_tenant_sku_unique` (`tenant_id`,`sku`),
  KEY `tenant_plant_inventory_items_scope_idx` (`tenant_id`,`status`,`category`),
  KEY `tenant_plant_inventory_items_category_index` (`category`),
  KEY `tenant_plant_inventory_items_status_index` (`status`),
  CONSTRAINT `tpi_items_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tenant_setup_statuses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tenant_setup_statuses` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `business_profile_status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'not_started',
  `import_path` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'undecided',
  `shopify_connection_status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'not_connected',
  `square_status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'not_requested',
  `csv_manual_status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'not_started',
  `module_interests` json DEFAULT NULL,
  `mobile_interest` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'undecided',
  `plan_interest` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'undecided',
  `billing_lane_interest` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'undecided',
  `implementation_help_interest` tinyint(1) NOT NULL DEFAULT '0',
  `commercial_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `commercial_review_status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending_review',
  `commercial_next_action` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `commercial_reviewed_by` bigint unsigned DEFAULT NULL,
  `commercial_reviewed_at` timestamp NULL DEFAULT NULL,
  `landlord_review_status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending_review',
  `next_recommended_action` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `internal_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `reviewed_by` bigint unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenant_setup_statuses_tenant_id_unique` (`tenant_id`),
  KEY `tenant_setup_statuses_reviewed_by_foreign` (`reviewed_by`),
  KEY `tenant_setup_statuses_import_path_mobile_interest_index` (`import_path`,`mobile_interest`),
  KEY `tenant_setup_statuses_landlord_review_status_index` (`landlord_review_status`),
  KEY `tenant_setup_statuses_commercial_reviewed_by_foreign` (`commercial_reviewed_by`),
  KEY `tss_commercial_intent_idx` (`plan_interest`,`billing_lane_interest`),
  KEY `tss_commercial_review_idx` (`commercial_review_status`),
  CONSTRAINT `tenant_setup_statuses_commercial_reviewed_by_foreign` FOREIGN KEY (`commercial_reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tenant_setup_statuses_reviewed_by_foreign` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tenant_setup_statuses_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tenant_site_domains`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tenant_site_domains` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `tenant_site_id` bigint unsigned NOT NULL,
  `hostname` varchar(253) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `is_primary` tinyint(1) NOT NULL DEFAULT '0',
  `verification_token` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `verification_checked_at` timestamp NULL DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `activated_at` timestamp NULL DEFAULT NULL,
  `last_error` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by_user_id` bigint unsigned DEFAULT NULL,
  `updated_by_user_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `site_domains_host_uq` (`hostname`),
  KEY `tenant_site_domains_created_by_user_id_foreign` (`created_by_user_id`),
  KEY `tenant_site_domains_updated_by_user_id_foreign` (`updated_by_user_id`),
  KEY `site_domains_tenant_site_idx` (`tenant_id`,`tenant_site_id`),
  KEY `site_domains_site_primary_idx` (`tenant_site_id`,`is_primary`),
  KEY `site_domains_status_idx` (`status`),
  CONSTRAINT `tenant_site_domains_created_by_user_id_foreign` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tenant_site_domains_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tenant_site_domains_tenant_site_id_foreign` FOREIGN KEY (`tenant_site_id`) REFERENCES `tenant_sites` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tenant_site_domains_updated_by_user_id_foreign` FOREIGN KEY (`updated_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tenant_site_media`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tenant_site_media` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `tenant_site_id` bigint unsigned NOT NULL,
  `uploaded_by_user_id` bigint unsigned DEFAULT NULL,
  `storage_disk` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'local',
  `storage_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_size` bigint unsigned NOT NULL,
  `checksum` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `kind` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'image',
  `source` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'upload',
  `source_url` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `alt_text` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_starter` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tsm_site_path_uq` (`tenant_site_id`,`storage_path`),
  KEY `tsm_tenant_site_idx` (`tenant_id`,`tenant_site_id`),
  KEY `tsm_uploader_fk` (`uploaded_by_user_id`),
  CONSTRAINT `tsm_site_fk` FOREIGN KEY (`tenant_site_id`) REFERENCES `tenant_sites` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tsm_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tsm_uploader_fk` FOREIGN KEY (`uploaded_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tenant_site_page_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tenant_site_page_versions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `tenant_site_id` bigint unsigned NOT NULL,
  `tenant_site_page_id` bigint unsigned NOT NULL,
  `version_number` int unsigned NOT NULL,
  `status` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `title` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `blocks` json NOT NULL,
  `seo` json DEFAULT NULL,
  `created_by_user_id` bigint unsigned DEFAULT NULL,
  `published_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `site_page_versions_page_version_uq` (`tenant_site_page_id`,`version_number`),
  KEY `tenant_site_page_versions_tenant_site_id_foreign` (`tenant_site_id`),
  KEY `tenant_site_page_versions_created_by_user_id_foreign` (`created_by_user_id`),
  KEY `site_versions_tenant_site_idx` (`tenant_id`,`tenant_site_id`),
  KEY `tenant_site_page_versions_status_index` (`status`),
  KEY `tenant_site_page_versions_published_at_index` (`published_at`),
  CONSTRAINT `tenant_site_page_versions_created_by_user_id_foreign` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tenant_site_page_versions_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tenant_site_page_versions_tenant_site_id_foreign` FOREIGN KEY (`tenant_site_id`) REFERENCES `tenant_sites` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tenant_site_page_versions_tenant_site_page_id_foreign` FOREIGN KEY (`tenant_site_page_id`) REFERENCES `tenant_site_pages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tenant_site_pages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tenant_site_pages` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `tenant_site_id` bigint unsigned NOT NULL,
  `slug` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `page_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'landing',
  `title` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_navigation_visible` tinyint(1) NOT NULL DEFAULT '1',
  `draft_version_id` bigint unsigned DEFAULT NULL,
  `published_version_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `site_pages_site_slug_uq` (`tenant_site_id`,`slug`),
  KEY `site_pages_tenant_site_idx` (`tenant_id`,`tenant_site_id`),
  CONSTRAINT `tenant_site_pages_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tenant_site_pages_tenant_site_id_foreign` FOREIGN KEY (`tenant_site_id`) REFERENCES `tenant_sites` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tenant_site_publish_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tenant_site_publish_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `tenant_site_id` bigint unsigned NOT NULL,
  `tenant_site_page_id` bigint unsigned DEFAULT NULL,
  `actor_user_id` bigint unsigned DEFAULT NULL,
  `event_type` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `context` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `tenant_site_publish_events_tenant_site_id_foreign` (`tenant_site_id`),
  KEY `tenant_site_publish_events_tenant_site_page_id_foreign` (`tenant_site_page_id`),
  KEY `tenant_site_publish_events_actor_user_id_foreign` (`actor_user_id`),
  KEY `site_events_tenant_site_type_idx` (`tenant_id`,`tenant_site_id`,`event_type`),
  CONSTRAINT `tenant_site_publish_events_actor_user_id_foreign` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tenant_site_publish_events_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tenant_site_publish_events_tenant_site_id_foreign` FOREIGN KEY (`tenant_site_id`) REFERENCES `tenant_sites` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tenant_site_publish_events_tenant_site_page_id_foreign` FOREIGN KEY (`tenant_site_page_id`) REFERENCES `tenant_site_pages` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tenant_site_redirects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tenant_site_redirects` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `tenant_site_id` bigint unsigned NOT NULL,
  `from_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `to_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status_code` smallint unsigned NOT NULL DEFAULT '301',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `site_redirects_site_from_uq` (`tenant_site_id`,`from_path`),
  KEY `tenant_site_redirects_tenant_id_foreign` (`tenant_id`),
  CONSTRAINT `tenant_site_redirects_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tenant_site_redirects_tenant_site_id_foreign` FOREIGN KEY (`tenant_site_id`) REFERENCES `tenant_sites` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tenant_site_setups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tenant_site_setups` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `tenant_site_id` bigint unsigned NOT NULL,
  `business_mode` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'trades',
  `offering_mode` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'services',
  `visitor_actions` json DEFAULT NULL,
  `design_key` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'collins-electric',
  `domain_choice` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'everbranch_subdomain',
  `contact_name` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_email` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_phone` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `hours` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `service_area` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `completed_steps` json DEFAULT NULL,
  `created_by_user_id` bigint unsigned DEFAULT NULL,
  `updated_by_user_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenant_site_setups_tenant_id_unique` (`tenant_id`),
  UNIQUE KEY `tenant_site_setups_tenant_site_id_unique` (`tenant_site_id`),
  KEY `tenant_site_setups_created_by_fk` (`created_by_user_id`),
  KEY `tenant_site_setups_updated_by_fk` (`updated_by_user_id`),
  CONSTRAINT `tenant_site_setups_created_by_fk` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tenant_site_setups_site_fk` FOREIGN KEY (`tenant_site_id`) REFERENCES `tenant_sites` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tenant_site_setups_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tenant_site_setups_updated_by_fk` FOREIGN KEY (`updated_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tenant_site_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tenant_site_versions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `tenant_site_id` bigint unsigned NOT NULL,
  `version_number` int unsigned NOT NULL,
  `status` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `settings` json DEFAULT NULL,
  `navigation` json DEFAULT NULL,
  `seo` json DEFAULT NULL,
  `thumbnail_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_manifest` json DEFAULT NULL,
  `created_by_user_id` bigint unsigned DEFAULT NULL,
  `published_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tsv_site_version_uq` (`tenant_site_id`,`version_number`),
  KEY `tsv_tenant_site_idx` (`tenant_id`,`tenant_site_id`),
  KEY `tsv_site_status_idx` (`tenant_site_id`,`status`),
  KEY `tsv_creator_fk` (`created_by_user_id`),
  CONSTRAINT `tsv_creator_fk` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tsv_site_fk` FOREIGN KEY (`tenant_site_id`) REFERENCES `tenant_sites` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tsv_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tenant_sites`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tenant_sites` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `status` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `public_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `subdomain` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `settings` json DEFAULT NULL,
  `draft_site_version_id` bigint unsigned DEFAULT NULL,
  `published_site_version_id` bigint unsigned DEFAULT NULL,
  `published_at` timestamp NULL DEFAULT NULL,
  `created_by_user_id` bigint unsigned DEFAULT NULL,
  `updated_by_user_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenant_sites_tenant_id_unique` (`tenant_id`),
  UNIQUE KEY `tenant_sites_subdomain_unique` (`subdomain`),
  KEY `tenant_sites_created_by_user_id_foreign` (`created_by_user_id`),
  KEY `tenant_sites_updated_by_user_id_foreign` (`updated_by_user_id`),
  KEY `tenant_sites_status_index` (`status`),
  KEY `tenant_sites_public_enabled_index` (`public_enabled`),
  KEY `tenant_sites_published_at_index` (`published_at`),
  KEY `ts_draft_version_idx` (`draft_site_version_id`),
  KEY `ts_published_version_idx` (`published_site_version_id`),
  CONSTRAINT `tenant_sites_created_by_user_id_foreign` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tenant_sites_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tenant_sites_updated_by_user_id_foreign` FOREIGN KEY (`updated_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tenant_support_ticket_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tenant_support_ticket_messages` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_support_ticket_id` bigint unsigned NOT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `author_context` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `body` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `tenant_support_ticket_messages_tenant_id_foreign` (`tenant_id`),
  KEY `tenant_support_ticket_messages_user_id_foreign` (`user_id`),
  KEY `tenant_support_messages_ticket_idx` (`tenant_support_ticket_id`,`id`),
  CONSTRAINT `tenant_support_ticket_messages_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tenant_support_ticket_messages_tenant_support_ticket_id_foreign` FOREIGN KEY (`tenant_support_ticket_id`) REFERENCES `tenant_support_tickets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tenant_support_ticket_messages_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tenant_support_tickets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tenant_support_tickets` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `created_by_user_id` bigint unsigned NOT NULL,
  `assigned_to_user_id` bigint unsigned DEFAULT NULL,
  `subject` varchar(180) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `category` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'help',
  `priority` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'normal',
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `resolution_summary` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `source_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'account_help',
  `dedupe_key` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_activity_at` timestamp NULL DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenant_support_tickets_dedupe_key_unique` (`dedupe_key`),
  KEY `tenant_support_tickets_created_by_user_id_foreign` (`created_by_user_id`),
  KEY `tenant_support_tickets_assigned_to_user_id_foreign` (`assigned_to_user_id`),
  KEY `tenant_support_status_activity_idx` (`tenant_id`,`status`,`last_activity_at`),
  CONSTRAINT `tenant_support_tickets_assigned_to_user_id_foreign` FOREIGN KEY (`assigned_to_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tenant_support_tickets_created_by_user_id_foreign` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tenant_support_tickets_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tenant_usage_counters`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tenant_usage_counters` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `metric_key` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `metric_value` bigint unsigned NOT NULL DEFAULT '0',
  `included_limit` bigint unsigned DEFAULT NULL,
  `source` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'computed',
  `last_recorded_at` timestamp NULL DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenant_usage_counters_tenant_metric_unique` (`tenant_id`,`metric_key`),
  KEY `tenant_usage_counters_metric_value_index` (`metric_key`,`metric_value`),
  CONSTRAINT `tenant_usage_counters_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tenant_user`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tenant_user` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `role` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `membership_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenant_user_unique` (`tenant_id`,`user_id`),
  KEY `tenant_user_user_tenant_idx` (`user_id`,`tenant_id`),
  KEY `tenant_user_membership_active_index` (`membership_active`),
  CONSTRAINT `tenant_user_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tenant_user_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tenant_wholesale_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tenant_wholesale_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `shopify_store_id` bigint unsigned NOT NULL,
  `qualification_mode` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'dedicated_store',
  `product_categories` json DEFAULT NULL,
  `discovery_keywords` json DEFAULT NULL,
  `website_enrichment_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `confirmed_at` timestamp NOT NULL,
  `confirmed_by_user_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenant_wholesale_settings_tenant_id_unique` (`tenant_id`),
  UNIQUE KEY `tenant_wholesale_settings_shopify_store_id_unique` (`shopify_store_id`),
  KEY `tenant_wholesale_settings_confirmed_by_user_id_foreign` (`confirmed_by_user_id`),
  KEY `tenant_wholesale_settings_active_index` (`qualification_mode`,`confirmed_at`),
  CONSTRAINT `tenant_wholesale_settings_confirmed_by_user_id_foreign` FOREIGN KEY (`confirmed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tenant_wholesale_settings_shopify_store_id_foreign` FOREIGN KEY (`shopify_store_id`) REFERENCES `shopify_stores` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tenant_wholesale_settings_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tenant_workforce_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tenant_workforce_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `enforce_scheduled_clocking` tinyint(1) NOT NULL DEFAULT '0',
  `clock_early_minutes` smallint unsigned NOT NULL DEFAULT '15',
  `clock_late_minutes` smallint unsigned NOT NULL DEFAULT '15',
  `updated_by_user_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tws_tenant_unique` (`tenant_id`),
  KEY `tws_updated_by_fk` (`updated_by_user_id`),
  CONSTRAINT `tws_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tws_updated_by_fk` FOREIGN KEY (`updated_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tenants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tenants` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenants_slug_unique` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `google_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `google_avatar` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `role` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'admin',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `requested_via` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `approval_requested_at` timestamp NULL DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `approved_by` bigint unsigned DEFAULT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `two_factor_secret` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `two_factor_recovery_codes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `two_factor_confirmed_at` timestamp NULL DEFAULT NULL,
  `remember_token` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dashboard_layout` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `ui_preferences` json DEFAULT NULL,
  `onboarding_guide_answers` json DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  UNIQUE KEY `users_google_id_unique` (`google_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `vision_ideas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `vision_ideas` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `slug` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `pitch` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `impact` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'medium',
  `effort` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'medium',
  `category` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'platform',
  `source` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'proposed',
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `vision_ideas_slug_unique` (`slug`),
  KEY `vision_ideas_sort_order_index` (`sort_order`),
  KEY `vision_ideas_status_index` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `wax_inventories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `wax_inventories` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `on_hand_grams` decimal(12,2) NOT NULL DEFAULT '0.00',
  `reorder_threshold_grams` decimal(12,2) NOT NULL DEFAULT '163293.26',
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `wax_inventories_name_unique` (`name`),
  KEY `wax_inventories_active_index` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `website_cart_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `website_cart_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `website_cart_id` bigint unsigned NOT NULL,
  `website_product_variant_id` bigint unsigned NOT NULL,
  `quantity` int unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `website_cart_items_cart_variant_uq` (`website_cart_id`,`website_product_variant_id`),
  KEY `website_cart_items_tenant_fk` (`tenant_id`),
  KEY `website_cart_items_variant_fk` (`website_product_variant_id`),
  CONSTRAINT `website_cart_items_cart_fk` FOREIGN KEY (`website_cart_id`) REFERENCES `website_carts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `website_cart_items_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `website_cart_items_variant_fk` FOREIGN KEY (`website_product_variant_id`) REFERENCES `website_product_variants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `website_carts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `website_carts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `tenant_site_id` bigint unsigned NOT NULL,
  `token` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `currency` varchar(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'usd',
  `status` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `website_customer_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `website_carts_token_unique` (`token`),
  KEY `website_carts_site_fk` (`tenant_site_id`),
  KEY `website_carts_customer_fk` (`website_customer_id`),
  KEY `website_carts_tenant_site_status_idx` (`tenant_id`,`tenant_site_id`,`status`),
  KEY `website_carts_status_index` (`status`),
  CONSTRAINT `website_carts_customer_fk` FOREIGN KEY (`website_customer_id`) REFERENCES `website_customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `website_carts_site_fk` FOREIGN KEY (`tenant_site_id`) REFERENCES `tenant_sites` (`id`) ON DELETE CASCADE,
  CONSTRAINT `website_carts_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `website_customer_addresses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `website_customer_addresses` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `website_customer_id` bigint unsigned NOT NULL,
  `label` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Primary',
  `name` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `line1` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `line2` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `city` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `state` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `postal_code` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `country` varchar(2) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'US',
  `is_default` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `website_addresses_customer_fk` (`website_customer_id`),
  KEY `website_addresses_tenant_customer_idx` (`tenant_id`,`website_customer_id`),
  CONSTRAINT `website_addresses_customer_fk` FOREIGN KEY (`website_customer_id`) REFERENCES `website_customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `website_addresses_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `website_customers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `website_customers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `email` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `first_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `notes` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `website_customers_tenant_email_uq` (`tenant_id`,`email`),
  KEY `website_customers_tenant_created_idx` (`tenant_id`,`created_at`),
  KEY `website_customers_status_index` (`status`),
  CONSTRAINT `website_customers_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `website_fulfillment_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `website_fulfillment_lines` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `website_fulfillment_id` bigint unsigned NOT NULL,
  `website_order_line_id` bigint unsigned NOT NULL,
  `quantity` int unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `website_fulfillment_lines_fulfillment_line_uq` (`website_fulfillment_id`,`website_order_line_id`),
  KEY `website_fulfillment_lines_tenant_id_foreign` (`tenant_id`),
  KEY `website_fulfillment_lines_website_order_line_id_foreign` (`website_order_line_id`),
  CONSTRAINT `website_fulfillment_lines_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `website_fulfillment_lines_website_fulfillment_id_foreign` FOREIGN KEY (`website_fulfillment_id`) REFERENCES `website_fulfillments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `website_fulfillment_lines_website_order_line_id_foreign` FOREIGN KEY (`website_order_line_id`) REFERENCES `website_order_lines` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `website_fulfillment_locations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `website_fulfillment_locations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `tenant_site_id` bigint unsigned NOT NULL,
  `name` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `address` json NOT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT '0',
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `website_fulfillment_locations_tenant_site_id_foreign` (`tenant_site_id`),
  KEY `website_locations_tenant_site_active_idx` (`tenant_id`,`tenant_site_id`,`active`),
  CONSTRAINT `website_fulfillment_locations_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `website_fulfillment_locations_tenant_site_id_foreign` FOREIGN KEY (`tenant_site_id`) REFERENCES `tenant_sites` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `website_fulfillments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `website_fulfillments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `website_order_id` bigint unsigned NOT NULL,
  `status` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unfulfilled',
  `method` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `note` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `fulfilled_by_user_id` bigint unsigned DEFAULT NULL,
  `fulfilled_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `website_fulfillments_order_fk` (`website_order_id`),
  KEY `website_fulfillments_user_fk` (`fulfilled_by_user_id`),
  KEY `website_fulfillments_tenant_order_idx` (`tenant_id`,`website_order_id`),
  KEY `website_fulfillments_status_index` (`status`),
  CONSTRAINT `website_fulfillments_order_fk` FOREIGN KEY (`website_order_id`) REFERENCES `website_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `website_fulfillments_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `website_fulfillments_user_fk` FOREIGN KEY (`fulfilled_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `website_inventory_movements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `website_inventory_movements` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `website_product_variant_id` bigint unsigned NOT NULL,
  `quantity_delta` int NOT NULL,
  `reason` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `reference_type` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reference_id` bigint unsigned DEFAULT NULL,
  `actor_user_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `website_movements_variant_fk` (`website_product_variant_id`),
  KEY `website_movements_actor_fk` (`actor_user_id`),
  KEY `website_inventory_variant_created_idx` (`tenant_id`,`website_product_variant_id`,`created_at`),
  CONSTRAINT `website_movements_actor_fk` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `website_movements_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `website_movements_variant_fk` FOREIGN KEY (`website_product_variant_id`) REFERENCES `website_product_variants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `website_inventory_reservations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `website_inventory_reservations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `website_product_variant_id` bigint unsigned NOT NULL,
  `website_order_id` bigint unsigned NOT NULL,
  `quantity` int unsigned NOT NULL,
  `status` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `expires_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `website_reservation_order_variant_uq` (`website_order_id`,`website_product_variant_id`),
  KEY `website_reservations_variant_fk` (`website_product_variant_id`),
  KEY `website_reservation_variant_status_idx` (`tenant_id`,`website_product_variant_id`,`status`),
  KEY `website_inventory_reservations_status_index` (`status`),
  KEY `website_inventory_reservations_expires_at_index` (`expires_at`),
  CONSTRAINT `website_reservations_order_fk` FOREIGN KEY (`website_order_id`) REFERENCES `website_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `website_reservations_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `website_reservations_variant_fk` FOREIGN KEY (`website_product_variant_id`) REFERENCES `website_product_variants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `website_order_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `website_order_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `website_order_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `event_type` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `visibility` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'staff',
  `message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `data` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `website_order_events_website_order_id_foreign` (`website_order_id`),
  KEY `website_order_events_user_id_foreign` (`user_id`),
  KEY `website_order_events_tenant_order_created_idx` (`tenant_id`,`website_order_id`,`created_at`),
  CONSTRAINT `website_order_events_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `website_order_events_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `website_order_events_website_order_id_foreign` FOREIGN KEY (`website_order_id`) REFERENCES `website_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `website_order_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `website_order_lines` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `website_order_id` bigint unsigned NOT NULL,
  `website_product_variant_id` bigint unsigned DEFAULT NULL,
  `title` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `product_type` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantity` int unsigned NOT NULL,
  `unit_price_cents` int unsigned NOT NULL,
  `line_total_cents` int unsigned NOT NULL,
  `snapshot` json NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `website_lines_order_fk` (`website_order_id`),
  KEY `website_lines_variant_fk` (`website_product_variant_id`),
  KEY `website_lines_tenant_order_idx` (`tenant_id`,`website_order_id`),
  CONSTRAINT `website_lines_order_fk` FOREIGN KEY (`website_order_id`) REFERENCES `website_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `website_lines_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `website_lines_variant_fk` FOREIGN KEY (`website_product_variant_id`) REFERENCES `website_product_variants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `website_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `website_orders` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `tenant_site_id` bigint unsigned NOT NULL,
  `website_customer_id` bigint unsigned DEFAULT NULL,
  `number` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `lookup_token` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `currency` varchar(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'usd',
  `order_status` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `source` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'native',
  `risk_status` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'normal',
  `review_status` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none',
  `exception_status` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none',
  `payment_status` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `fulfillment_status` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unfulfilled',
  `fulfillment_method` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subtotal_cents` int unsigned NOT NULL DEFAULT '0',
  `discount_cents` int unsigned NOT NULL DEFAULT '0',
  `tax_cents` int unsigned NOT NULL DEFAULT '0',
  `shipping_cents` int unsigned NOT NULL DEFAULT '0',
  `total_cents` int unsigned NOT NULL DEFAULT '0',
  `refunded_cents` int unsigned NOT NULL DEFAULT '0',
  `customer_snapshot` json DEFAULT NULL,
  `shipping_address` json DEFAULT NULL,
  `billing_address` json DEFAULT NULL,
  `shipping_rate_snapshot` json DEFAULT NULL,
  `service_request` json DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `fulfilled_at` timestamp NULL DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `closed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `website_orders_tenant_number_uq` (`tenant_id`,`number`),
  UNIQUE KEY `website_orders_lookup_token_unique` (`lookup_token`),
  KEY `website_orders_site_fk` (`tenant_site_id`),
  KEY `website_orders_customer_fk` (`website_customer_id`),
  KEY `website_orders_tenant_created_idx` (`tenant_id`,`created_at`),
  KEY `website_orders_payment_status_index` (`payment_status`),
  KEY `website_orders_fulfillment_status_index` (`fulfillment_status`),
  KEY `website_orders_paid_at_index` (`paid_at`),
  KEY `website_orders_fulfilled_at_index` (`fulfilled_at`),
  KEY `website_orders_order_status_index` (`order_status`),
  KEY `website_orders_source_index` (`source`),
  KEY `website_orders_risk_status_index` (`risk_status`),
  KEY `website_orders_review_status_index` (`review_status`),
  KEY `website_orders_exception_status_index` (`exception_status`),
  KEY `website_orders_cancelled_at_index` (`cancelled_at`),
  KEY `website_orders_closed_at_index` (`closed_at`),
  CONSTRAINT `website_orders_customer_fk` FOREIGN KEY (`website_customer_id`) REFERENCES `website_customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `website_orders_site_fk` FOREIGN KEY (`tenant_site_id`) REFERENCES `tenant_sites` (`id`) ON DELETE CASCADE,
  CONSTRAINT `website_orders_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `website_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `website_payments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `website_order_id` bigint unsigned NOT NULL,
  `provider` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'stripe',
  `provider_session_id` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `provider_payment_intent_id` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `amount_cents` int unsigned NOT NULL DEFAULT '0',
  `currency` varchar(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'usd',
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `website_payments_provider_session_uq` (`provider`,`provider_session_id`),
  KEY `website_payments_order_fk` (`website_order_id`),
  KEY `website_payments_tenant_order_idx` (`tenant_id`,`website_order_id`),
  KEY `website_payments_status_index` (`status`),
  CONSTRAINT `website_payments_order_fk` FOREIGN KEY (`website_order_id`) REFERENCES `website_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `website_payments_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `website_product_variants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `website_product_variants` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `website_product_id` bigint unsigned NOT NULL,
  `title` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Default',
  `sku` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `price_cents` int unsigned NOT NULL,
  `wholesale_price_cents` int unsigned DEFAULT NULL,
  `compare_at_price_cents` int unsigned DEFAULT NULL,
  `inventory_quantity` int DEFAULT NULL,
  `shipping_weight_ounces` int unsigned DEFAULT NULL,
  `shipping_length_inches` int unsigned DEFAULT NULL,
  `shipping_width_inches` int unsigned DEFAULT NULL,
  `shipping_height_inches` int unsigned DEFAULT NULL,
  `is_available` tinyint(1) NOT NULL DEFAULT '1',
  `options` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `website_variants_product_sku_uq` (`website_product_id`,`sku`),
  KEY `website_variants_tenant_product_idx` (`tenant_id`,`website_product_id`),
  CONSTRAINT `website_variants_product_fk` FOREIGN KEY (`website_product_id`) REFERENCES `website_products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `website_variants_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `website_products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `website_products` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `tenant_site_id` bigint unsigned NOT NULL,
  `handle` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `product_type` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'physical',
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `track_inventory` tinyint(1) NOT NULL DEFAULT '0',
  `media` json DEFAULT NULL,
  `service_details` json DEFAULT NULL,
  `seo` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `website_products_site_handle_uq` (`tenant_site_id`,`handle`),
  KEY `website_products_tenant_site_status_idx` (`tenant_id`,`tenant_site_id`,`status`),
  KEY `website_products_product_type_index` (`product_type`),
  KEY `website_products_status_index` (`status`),
  CONSTRAINT `website_products_site_fk` FOREIGN KEY (`tenant_site_id`) REFERENCES `tenant_sites` (`id`) ON DELETE CASCADE,
  CONSTRAINT `website_products_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `website_shipment_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `website_shipment_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `website_shipment_id` bigint unsigned NOT NULL,
  `provider_event_id` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `event_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `payload` json DEFAULT NULL,
  `occurred_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `website_shipment_events_shipment_provider_event_uq` (`website_shipment_id`,`provider_event_id`),
  KEY `website_shipment_events_tenant_id_foreign` (`tenant_id`),
  CONSTRAINT `website_shipment_events_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `website_shipment_events_website_shipment_id_foreign` FOREIGN KEY (`website_shipment_id`) REFERENCES `website_shipments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `website_shipments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `website_shipments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `website_order_id` bigint unsigned NOT NULL,
  `website_fulfillment_id` bigint unsigned NOT NULL,
  `website_fulfillment_location_id` bigint unsigned DEFAULT NULL,
  `provider` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'easypost',
  `provider_shipment_id` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `provider_rate_id` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `carrier` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `service` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tracking_number` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tracking_url` varchar(2048) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `label_url` varchar(2048) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `label_cost_cents` int unsigned DEFAULT NULL,
  `currency` varchar(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'usd',
  `status` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `destination` json DEFAULT NULL,
  `parcel` json DEFAULT NULL,
  `purchased_at` timestamp NULL DEFAULT NULL,
  `voided_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `website_shipments_provider_shipment_id_unique` (`provider_shipment_id`),
  KEY `website_shipments_website_order_id_foreign` (`website_order_id`),
  KEY `website_shipments_website_fulfillment_id_foreign` (`website_fulfillment_id`),
  KEY `website_shipments_website_fulfillment_location_id_foreign` (`website_fulfillment_location_id`),
  KEY `website_shipments_tenant_order_status_idx` (`tenant_id`,`website_order_id`,`status`),
  KEY `website_shipments_tracking_number_index` (`tracking_number`),
  KEY `website_shipments_status_index` (`status`),
  CONSTRAINT `website_shipments_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `website_shipments_website_fulfillment_id_foreign` FOREIGN KEY (`website_fulfillment_id`) REFERENCES `website_fulfillments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `website_shipments_website_fulfillment_location_id_foreign` FOREIGN KEY (`website_fulfillment_location_id`) REFERENCES `website_fulfillment_locations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `website_shipments_website_order_id_foreign` FOREIGN KEY (`website_order_id`) REFERENCES `website_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `website_shipping_packages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `website_shipping_packages` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `tenant_site_id` bigint unsigned NOT NULL,
  `name` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `length_inches` int unsigned NOT NULL,
  `width_inches` int unsigned NOT NULL,
  `height_inches` int unsigned NOT NULL,
  `weight_ounces` int unsigned NOT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT '0',
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `website_shipping_packages_tenant_site_id_foreign` (`tenant_site_id`),
  KEY `website_packages_tenant_site_active_idx` (`tenant_id`,`tenant_site_id`,`active`),
  CONSTRAINT `website_shipping_packages_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `website_shipping_packages_tenant_site_id_foreign` FOREIGN KEY (`tenant_site_id`) REFERENCES `tenant_sites` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `website_shipping_rate_quotes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `website_shipping_rate_quotes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `tenant_site_id` bigint unsigned NOT NULL,
  `website_cart_id` bigint unsigned NOT NULL,
  `website_fulfillment_location_id` bigint unsigned NOT NULL,
  `provider` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'easypost',
  `provider_shipment_id` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `provider_rate_id` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `carrier` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `service` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount_cents` int unsigned NOT NULL,
  `currency` varchar(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'usd',
  `delivery_days` int DEFAULT NULL,
  `destination` json NOT NULL,
  `parcel` json NOT NULL,
  `expires_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `website_shipping_rate_quotes_tenant_site_id_foreign` (`tenant_site_id`),
  KEY `website_shipping_rate_quotes_website_cart_id_foreign` (`website_cart_id`),
  KEY `website_rate_quotes_location_fk` (`website_fulfillment_location_id`),
  KEY `website_rate_quotes_tenant_cart_expiry_idx` (`tenant_id`,`website_cart_id`,`expires_at`),
  KEY `website_shipping_rate_quotes_expires_at_index` (`expires_at`),
  CONSTRAINT `website_rate_quotes_location_fk` FOREIGN KEY (`website_fulfillment_location_id`) REFERENCES `website_fulfillment_locations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `website_shipping_rate_quotes_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `website_shipping_rate_quotes_tenant_site_id_foreign` FOREIGN KEY (`tenant_site_id`) REFERENCES `tenant_sites` (`id`) ON DELETE CASCADE,
  CONSTRAINT `website_shipping_rate_quotes_website_cart_id_foreign` FOREIGN KEY (`website_cart_id`) REFERENCES `website_carts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `website_stripe_webhook_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `website_stripe_webhook_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned DEFAULT NULL,
  `stripe_event_id` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'received',
  `payload` json NOT NULL,
  `processed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `website_stripe_webhook_events_stripe_event_id_unique` (`stripe_event_id`),
  KEY `website_stripe_events_tenant_fk` (`tenant_id`),
  CONSTRAINT `website_stripe_events_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `wholesale_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `wholesale_accounts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `public_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `canonical_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `company_name` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_name` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `website` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `city` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `state` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `postal_code` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'confirmed',
  `source_prospect_public_id` varchar(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `existing_customer_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `original_discovery_source` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `conversion_snapshot` json DEFAULT NULL,
  `confirmed_by_user_id` bigint unsigned DEFAULT NULL,
  `confirmed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `wholesale_accounts_tenant_key_unique` (`tenant_id`,`canonical_key`),
  UNIQUE KEY `wholesale_accounts_public_id_unique` (`public_id`),
  KEY `wholesale_accounts_confirmed_by_user_id_foreign` (`confirmed_by_user_id`),
  KEY `wholesale_accounts_tenant_status_idx` (`tenant_id`,`status`),
  CONSTRAINT `wholesale_accounts_confirmed_by_user_id_foreign` FOREIGN KEY (`confirmed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `wholesale_accounts_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `wholesale_custom_scents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `wholesale_custom_scents` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `account_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `custom_scent_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `oil_1` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `oil_2` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `oil_3` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `total_oils` smallint unsigned DEFAULT NULL,
  `abbreviation` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `canonical_scent_id` bigint unsigned DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `top_level_recipe_json` json DEFAULT NULL,
  `resolved_recipe_json` json DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `wholesale_custom_scents_account_name_custom_scent_name_unique` (`account_name`,`custom_scent_name`),
  KEY `wholesale_custom_scents_canonical_scent_id_foreign` (`canonical_scent_id`),
  CONSTRAINT `wholesale_custom_scents_canonical_scent_id_foreign` FOREIGN KEY (`canonical_scent_id`) REFERENCES `scents` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `wholesale_email_messenger_drafts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `wholesale_email_messenger_drafts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `store_key` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `sections` json NOT NULL,
  `personalization` json DEFAULT NULL,
  `revision` int unsigned NOT NULL DEFAULT '1',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `wem_draft_tenant_store_name_uq` (`tenant_id`,`store_key`,`name`),
  KEY `wem_draft_created_by_fk` (`created_by`),
  KEY `wem_draft_updated_by_fk` (`updated_by`),
  CONSTRAINT `wem_draft_created_by_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `wem_draft_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `wem_draft_updated_by_fk` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `wholesale_follow_ups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `wholesale_follow_ups` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `public_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `wholesale_suggestion_id` bigint unsigned DEFAULT NULL,
  `target_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `follow_up_type` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'sales_review',
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `priority` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'normal',
  `assigned_user_id` bigint unsigned DEFAULT NULL,
  `created_by_user_id` bigint unsigned NOT NULL,
  `due_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `outcome` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `wholesale_follow_ups_public_id_unique` (`public_id`),
  KEY `wholesale_follow_ups_wholesale_suggestion_id_foreign` (`wholesale_suggestion_id`),
  KEY `wholesale_follow_ups_assigned_user_id_foreign` (`assigned_user_id`),
  KEY `wholesale_follow_ups_created_by_user_id_foreign` (`created_by_user_id`),
  KEY `wholesale_follow_ups_tenant_due_idx` (`tenant_id`,`status`,`due_at`),
  KEY `wholesale_follow_ups_tenant_target_idx` (`tenant_id`,`target_type`,`target_key`),
  CONSTRAINT `wholesale_follow_ups_assigned_user_id_foreign` FOREIGN KEY (`assigned_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `wholesale_follow_ups_created_by_user_id_foreign` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `wholesale_follow_ups_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `wholesale_follow_ups_wholesale_suggestion_id_foreign` FOREIGN KEY (`wholesale_suggestion_id`) REFERENCES `wholesale_suggestions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `wholesale_order_classifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `wholesale_order_classifications` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `order_id` bigint unsigned NOT NULL,
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending_review',
  `classification_basis` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `evidence` json DEFAULT NULL,
  `classified_by_user_id` bigint unsigned DEFAULT NULL,
  `classified_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `wholesale_order_classifications_tenant_order_unique` (`tenant_id`,`order_id`),
  KEY `wholesale_order_classifications_order_id_foreign` (`order_id`),
  KEY `wholesale_order_classifications_classified_by_user_id_foreign` (`classified_by_user_id`),
  KEY `wholesale_order_classifications_tenant_status_idx` (`tenant_id`,`status`),
  CONSTRAINT `wholesale_order_classifications_classified_by_user_id_foreign` FOREIGN KEY (`classified_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `wholesale_order_classifications_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `wholesale_order_classifications_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `wholesale_prospect_discovery_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `wholesale_prospect_discovery_runs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `public_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'queued',
  `search_region` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `radius_meters` int unsigned DEFAULT NULL,
  `categories` json DEFAULT NULL,
  `search_phrases` json NOT NULL,
  `maximum_results` int unsigned NOT NULL DEFAULT '20',
  `website_enrichment` tinyint(1) NOT NULL DEFAULT '0',
  `instagram_enrichment` tinyint(1) NOT NULL DEFAULT '0',
  `assigned_owner_user_id` bigint unsigned DEFAULT NULL,
  `campaign_name` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `estimated_api_cost` decimal(10,4) NOT NULL DEFAULT '0.0000',
  `actual_api_cost` decimal(10,4) NOT NULL DEFAULT '0.0000',
  `api_request_count` int unsigned NOT NULL DEFAULT '0',
  `results_discovered` int unsigned NOT NULL DEFAULT '0',
  `results_created` int unsigned NOT NULL DEFAULT '0',
  `duplicates_suppressed` int unsigned NOT NULL DEFAULT '0',
  `large_search_confirmed` tinyint(1) NOT NULL DEFAULT '0',
  `requested_by_user_id` bigint unsigned NOT NULL,
  `source_log` json DEFAULT NULL,
  `error_message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `wholesale_prospect_discovery_runs_public_id_unique` (`public_id`),
  KEY `wholesale_prospect_discovery_runs_assigned_owner_user_id_foreign` (`assigned_owner_user_id`),
  KEY `wholesale_prospect_discovery_runs_requested_by_user_id_foreign` (`requested_by_user_id`),
  KEY `wholesale_prospect_runs_tenant_status_idx` (`tenant_id`,`status`),
  CONSTRAINT `wholesale_prospect_discovery_runs_assigned_owner_user_id_foreign` FOREIGN KEY (`assigned_owner_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `wholesale_prospect_discovery_runs_requested_by_user_id_foreign` FOREIGN KEY (`requested_by_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `wholesale_prospect_discovery_runs_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `wholesale_prospect_evidence`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `wholesale_prospect_evidence` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `wholesale_prospect_id` bigint unsigned NOT NULL,
  `source_type` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_url` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `signal_type` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `summary` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `supports_fit` tinyint(1) DEFAULT NULL,
  `observed_at` timestamp NOT NULL,
  `source_reference` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `wholesale_prospect_evidence_wholesale_prospect_id_foreign` (`wholesale_prospect_id`),
  KEY `wholesale_prospect_evidence_tenant_prospect_idx` (`tenant_id`,`wholesale_prospect_id`),
  CONSTRAINT `wholesale_prospect_evidence_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `wholesale_prospect_evidence_wholesale_prospect_id_foreign` FOREIGN KEY (`wholesale_prospect_id`) REFERENCES `wholesale_prospects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `wholesale_prospects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `wholesale_prospects` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `public_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `business_name` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'newly_discovered',
  `primary_category` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `secondary_categories` json DEFAULT NULL,
  `address` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `city` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `state` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `postal_code` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `phone` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `website` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `public_business_email` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_form_url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `instagram_handle` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `facebook_page` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `google_place_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `google_maps_url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `operational_status` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `discovery_source` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `discovery_query` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `discovered_at` timestamp NULL DEFAULT NULL,
  `discovery_run_id` bigint unsigned DEFAULT NULL,
  `assigned_owner_user_id` bigint unsigned DEFAULT NULL,
  `fit_score` tinyint unsigned NOT NULL DEFAULT '0',
  `fit_confidence` tinyint unsigned NOT NULL DEFAULT '0',
  `fit_explanation` json DEFAULT NULL,
  `opportunity_priority` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'normal',
  `suggested_product_positioning` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `suggested_opening_message_topic` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `last_reviewed_at` timestamp NULL DEFAULT NULL,
  `last_contact_at` timestamp NULL DEFAULT NULL,
  `next_action_at` timestamp NULL DEFAULT NULL,
  `do_not_contact` tinyint(1) NOT NULL DEFAULT '0',
  `rejection_reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `duplicate_status` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `existing_customer_match` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `converted_wholesale_account_id` bigint unsigned DEFAULT NULL,
  `converted_by_user_id` bigint unsigned DEFAULT NULL,
  `converted_at` timestamp NULL DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `source_snapshot` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `wholesale_prospects_public_id_unique` (`public_id`),
  UNIQUE KEY `wholesale_prospects_tenant_place_unique` (`tenant_id`,`google_place_id`),
  KEY `wholesale_prospects_discovery_run_id_foreign` (`discovery_run_id`),
  KEY `wholesale_prospects_assigned_owner_user_id_foreign` (`assigned_owner_user_id`),
  KEY `wholesale_prospects_converted_wholesale_account_id_foreign` (`converted_wholesale_account_id`),
  KEY `wholesale_prospects_converted_by_user_id_foreign` (`converted_by_user_id`),
  KEY `wholesale_prospects_tenant_review_idx` (`tenant_id`,`status`,`fit_score`),
  CONSTRAINT `wholesale_prospects_assigned_owner_user_id_foreign` FOREIGN KEY (`assigned_owner_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `wholesale_prospects_converted_by_user_id_foreign` FOREIGN KEY (`converted_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `wholesale_prospects_converted_wholesale_account_id_foreign` FOREIGN KEY (`converted_wholesale_account_id`) REFERENCES `wholesale_accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `wholesale_prospects_discovery_run_id_foreign` FOREIGN KEY (`discovery_run_id`) REFERENCES `wholesale_prospect_discovery_runs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `wholesale_prospects_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `wholesale_suggestion_decisions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `wholesale_suggestion_decisions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `wholesale_suggestion_id` bigint unsigned NOT NULL,
  `actor_user_id` bigint unsigned NOT NULL,
  `action` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `note` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `dismissal_reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `resulting_follow_up_id` bigint unsigned DEFAULT NULL,
  `original_suggestion` json NOT NULL,
  `decided_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `wholesale_suggestion_decisions_wholesale_suggestion_id_foreign` (`wholesale_suggestion_id`),
  KEY `wholesale_suggestion_decisions_actor_user_id_foreign` (`actor_user_id`),
  KEY `wholesale_suggestion_decisions_resulting_follow_up_id_foreign` (`resulting_follow_up_id`),
  KEY `wholesale_suggestion_decisions_tenant_suggestion_idx` (`tenant_id`,`wholesale_suggestion_id`),
  CONSTRAINT `wholesale_suggestion_decisions_actor_user_id_foreign` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `wholesale_suggestion_decisions_resulting_follow_up_id_foreign` FOREIGN KEY (`resulting_follow_up_id`) REFERENCES `wholesale_follow_ups` (`id`) ON DELETE SET NULL,
  CONSTRAINT `wholesale_suggestion_decisions_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `wholesale_suggestion_decisions_wholesale_suggestion_id_foreign` FOREIGN KEY (`wholesale_suggestion_id`) REFERENCES `wholesale_suggestions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `wholesale_suggestions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `wholesale_suggestions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `public_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `suggestion_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `recommended_action` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `priority` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'normal',
  `confidence` tinyint unsigned NOT NULL DEFAULT '0',
  `supporting_evidence` json NOT NULL,
  `estimated_opportunity` decimal(12,2) DEFAULT NULL,
  `suggested_follow_up_at` timestamp NULL DEFAULT NULL,
  `reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `evidence_fingerprint` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `snoozed_until` timestamp NULL DEFAULT NULL,
  `last_evaluated_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `wholesale_suggestions_tenant_fingerprint_unique` (`tenant_id`,`evidence_fingerprint`),
  UNIQUE KEY `wholesale_suggestions_public_id_unique` (`public_id`),
  KEY `wholesale_suggestions_tenant_queue_idx` (`tenant_id`,`status`,`priority`),
  KEY `wholesale_suggestions_tenant_target_idx` (`tenant_id`,`target_type`,`target_key`),
  CONSTRAINT `wholesale_suggestions_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `wicks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `wicks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `wicks_name_unique` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `workspace_asset_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `workspace_asset_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `workspace_asset_id` bigint unsigned DEFAULT NULL,
  `actor_user_id` bigint unsigned DEFAULT NULL,
  `action` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `context` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `occurred_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `workspace_asset_events_tenant_time_idx` (`tenant_id`,`occurred_at`),
  KEY `workspace_asset_events_asset_fk` (`workspace_asset_id`),
  KEY `workspace_asset_events_actor_fk` (`actor_user_id`),
  KEY `workspace_asset_events_action_index` (`action`),
  CONSTRAINT `workspace_asset_events_actor_fk` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `workspace_asset_events_asset_fk` FOREIGN KEY (`workspace_asset_id`) REFERENCES `workspace_assets` (`id`) ON DELETE SET NULL,
  CONSTRAINT `workspace_asset_events_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `workspace_asset_uploads`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `workspace_asset_uploads` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `uploaded_by_user_id` bigint unsigned NOT NULL,
  `field_service_job_id` bigint unsigned DEFAULT NULL,
  `token_hash` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `storage_disk` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `storage_path` varchar(1024) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime_type` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `max_file_size` bigint unsigned NOT NULL,
  `visibility` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'team',
  `caption` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'initialized',
  `expires_at` timestamp NOT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `workspace_asset_uploads_token_hash_unique` (`token_hash`),
  KEY `workspace_upload_status_idx` (`tenant_id`,`status`,`expires_at`),
  KEY `workspace_upload_user_fk` (`uploaded_by_user_id`),
  KEY `workspace_upload_job_fk` (`field_service_job_id`),
  CONSTRAINT `workspace_upload_job_fk` FOREIGN KEY (`field_service_job_id`) REFERENCES `field_service_jobs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `workspace_upload_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `workspace_upload_user_fk` FOREIGN KEY (`uploaded_by_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `workspace_assets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `workspace_assets` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `uploaded_by_user_id` bigint unsigned DEFAULT NULL,
  `source` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'upload',
  `external_id` varchar(180) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `visibility` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'team',
  `storage_disk` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'local',
  `storage_path` varchar(1024) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `thumbnail_disk` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `thumbnail_path` varchar(1024) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime_type` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_size` bigint unsigned DEFAULT NULL,
  `checksum` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `caption` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tags` json DEFAULT NULL,
  `search_text` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `metadata` json DEFAULT NULL,
  `captured_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `workspace_assets_source_unique` (`tenant_id`,`source`,`external_id`),
  KEY `workspace_assets_tenant_created_idx` (`tenant_id`,`created_at`),
  KEY `workspace_assets_uploader_fk` (`uploaded_by_user_id`),
  KEY `workspace_assets_visibility_index` (`visibility`),
  CONSTRAINT `workspace_assets_tenant_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `workspace_assets_uploader_fk` FOREIGN KEY (`uploaded_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (1,'0001_01_01_000000_create_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (2,'0001_01_01_000001_create_cache_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (3,'0001_01_01_000002_create_jobs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (4,'2025_08_14_170933_add_two_factor_columns_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (5,'2026_02_02_020840_create_orders_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (6,'2026_02_02_020850_create_order_lines_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (7,'2026_02_04_031802_create_tenants_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (8,'2026_02_04_044834_add_meta_to_orders_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (9,'2026_02_10_024549_add_dashboard_layout_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (10,'2026_02_17_000001_create_scents_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (11,'2026_02_17_000002_create_sizes_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (12,'2026_02_17_000003_add_scent_id_size_id_to_order_lines_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (13,'2026_02_17_135654_add_unique_order_scent_size_to_order_lines_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (14,'2026_02_17_141911_make_order_lines_scent_name_and_size_code_nullable',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (15,'2026_02_17_144612_make_legacy_scent_fields_nullable_on_order_lines',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (16,'2026_02_17_145112_rebuild_order_lines_to_make_legacy_fields_nullable',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (17,'2026_02_17_152203_add_source_and_shopify_fields_and_dates_to_orders_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (18,'2026_02_17_152802_add_ordered_and_extra_qty_to_order_lines_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (19,'2026_02_17_174039_add_unique_order_scent_size_to_order_lines',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (20,'2026_02_18_000001_rebuild_orders_for_shopify_phase1',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (21,'2026_02_18_000002_rebuild_order_lines_for_shopify_phase1',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (22,'2026_02_18_000003_create_mapping_exceptions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (23,'2026_02_18_120000_add_due_at_to_orders_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (24,'2026_02_18_130000_create_shopify_stores_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (25,'2026_02_18_140000_add_order_type_and_label_to_orders',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (26,'2026_02_18_150000_add_ui_preferences_to_users',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (27,'2026_02_18_200000_add_pouring_fields_to_scents_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (28,'2026_02_18_200001_create_oil_and_blend_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (29,'2026_02_18_200002_create_scent_reference_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (30,'2026_02_18_200003_create_pouring_measurements_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (31,'2026_02_18_200004_create_pour_batches_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (32,'2026_02_18_200005_add_publishing_fields_to_orders',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (33,'2026_02_18_200006_create_retail_plans_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (34,'2026_02_18_200007_seed_pouring_measurements',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (35,'2026_02_18_200008_create_room_spray_measurements',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (36,'2026_02_18_200009_seed_room_spray_measurements',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (37,'2026_02_18_200010_add_room_spray_totals_to_pour_batches',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (38,'2026_02_18_200011_add_wick_type_to_order_lines',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (39,'2026_02_19_120000_add_blend_oil_count_to_scents',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (40,'2026_02_19_120010_create_wicks_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (41,'2026_02_19_120020_seed_scents_sizes_wicks',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (42,'2026_02_19_130000_seed_core_scents_list',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (43,'2026_02_19_160000_add_image_url_to_order_lines',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (44,'2026_02_19_170000_backfill_ship_by_at_for_existing_orders',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (45,'2026_02_19_180000_create_shopify_import_runs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (46,'2026_02_19_190000_add_prices_to_sizes_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (47,'2026_02_19_200000_add_external_key_to_order_lines',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (48,'2026_02_19_200010_create_shopify_import_exceptions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (49,'2026_02_19_210000_add_role_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (50,'2026_02_19_210100_create_oil_abbreviations_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (51,'2026_02_19_220000_add_shipping_billing_fields_to_orders_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (52,'2026_02_19_230000_add_shipping_name_billing_name_to_orders_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (53,'2026_02_19_240000_rebuild_wholesale_custom_scents_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (54,'2026_02_19_240010_add_wholesale_fields_to_mapping_exceptions',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (55,'2026_02_19_240020_add_oil_blend_id_to_scents_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (56,'2026_02_19_240030_add_requires_shipping_review_to_orders',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (57,'2026_02_20_120000_replace_sizes_with_canonical',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (58,'2026_02_20_140000_add_excluded_fields_to_mapping_exceptions',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (59,'2026_02_20_140010_create_import_normalizations_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (60,'2026_02_20_150000_add_inventory_qty_to_retail_plan_items',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (61,'2026_02_20_160000_create_inventory_counts_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (62,'2026_02_20_170000_create_events_module_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (63,'2026_02_23_120000_add_queue_type_to_retail_plans_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (64,'2026_02_23_160000_add_google_oauth_fields_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (65,'2026_02_23_170000_add_user_approval_fields',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (66,'2026_02_24_215123_create_markets_and_market_box_shipments_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (67,'2026_02_24_215124_extend_events_and_market_pour_lists_for_market_browser',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (68,'2026_02_24_220032_add_parse_metadata_to_events_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (69,'2026_02_26_030000_create_market_plans_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (70,'2026_02_26_050000_add_event_id_to_retail_plans_and_orders',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (71,'2026_02_26_080000_create_market_event_sync_states_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (72,'2026_02_26_081000_create_event_match_overrides_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (73,'2026_02_27_120000_add_upcoming_event_id_to_retail_plan_items_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (74,'2026_02_28_120000_create_event_mappings_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (75,'2026_03_01_100000_create_event_instances_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (76,'2026_03_01_101000_create_event_box_plans_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (77,'2026_03_01_120000_add_box_tier_and_notes_to_retail_plan_items_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (78,'2026_03_02_000000_create_scent_templates_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (79,'2026_03_02_020000_create_scent_aliases_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (80,'2026_03_02_020100_add_source_label_to_retail_plan_items_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (81,'2026_03_06_220000_add_recipe_columns_to_wholesale_custom_scents',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (82,'2026_03_06_230000_add_mapping_columns_to_scents_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (83,'2026_03_07_160000_add_wizard_metadata_to_scents_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (84,'2026_03_07_180000_create_scent_recipe_foundation_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (85,'2026_03_08_090000_create_inventory_foundation_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (86,'2026_03_08_153000_create_order_line_scent_splits_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (87,'2026_03_10_130000_create_marketing_foundation_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (88,'2026_03_10_180000_create_marketing_square_and_import_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (89,'2026_03_10_210000_create_marketing_campaign_domain_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (90,'2026_03_11_090000_create_marketing_sms_delivery_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (91,'2026_03_12_090000_add_marketing_groups_and_addresses',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (92,'2026_03_12_100000_create_marketing_email_and_candle_cash_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (93,'2026_03_13_090000_create_marketing_optimization_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (94,'2026_03_14_090000_add_stage8_storefront_slug_and_redemption_hardening',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (95,'2026_03_15_090000_create_marketing_storefront_events_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (96,'2026_03_16_090000_create_customer_external_profiles_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (97,'2026_03_17_090000_create_customer_birthday_domain_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (98,'2026_03_17_170000_add_attribution_snapshot_to_marketing_campaign_conversions',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (99,'2026_03_18_090000_add_customer_identity_columns_to_orders_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (100,'2026_03_18_150000_add_attribution_meta_to_orders_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (101,'2026_03_19_090000_create_marketing_message_group_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (102,'2026_03_19_090000_create_marketing_review_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (103,'2026_03_19_100000_create_marketing_short_links_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (104,'2026_03_20_090000_backfill_customer_external_profiles_identity_columns',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (105,'2026_03_21_090000_extend_birthday_domain_for_imports_and_analytics',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (106,'2026_03_21_120000_add_shopify_sync_columns_to_birthday_reward_issuances',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (107,'2026_03_21_180000_create_candle_cash_growth_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (108,'2026_03_24_100000_refactor_candle_cash_engine_for_automatic_verification',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (109,'2026_03_24_130000_add_google_business_profile_support_to_candle_cash',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (110,'2026_03_24_180000_extend_marketing_review_histories_for_native_product_reviews',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (111,'2026_03_24_190000_backfill_google_review_task_action_url',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (112,'2026_03_24_191000_normalize_candle_cash_integration_setting',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (113,'2026_03_25_090000_add_gift_metadata_to_candle_cash_transactions',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (114,'2026_03_25_090000_add_profit_infrastructure_storage',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (115,'2026_03_26_090000_add_tenant_scope_to_shopify_and_marketing_identity_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (116,'2026_03_26_120000_create_integration_health_events_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (117,'2026_03_27_090000_create_tenant_user_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (118,'2026_03_27_140000_create_marketing_automation_events_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (119,'2026_03_27_150000_stage_candle_cash_persistence_cleanup',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (120,'2026_03_27_170000_create_candle_cash_legacy_compatibility_usages_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (121,'2026_03_27_180000_correct_legacy_points_origin_candle_cash_values',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (122,'2026_03_28_090000_create_tenant_email_settings_and_extend_marketing_email_deliveries',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (123,'2026_03_28_120000_create_marketing_profile_wishlist_items_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (124,'2026_03_29_090000_create_tenant_access_entitlement_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (125,'2026_03_30_090000_create_landlord_commercial_configuration_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (126,'2026_03_30_100000_create_tenant_rewards_editor_isolation_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (127,'2026_03_31_100000_extend_marketing_reviews_and_wishlists_for_growave_replacement',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (128,'2026_04_01_090000_add_tenant_to_marketing_import_runs',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (129,'2026_04_01_120000_create_tenant_module_entitlements_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (130,'2026_04_01_150000_add_admin_response_to_marketing_review_histories',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (131,'2026_04_01_150000_add_storefront_widget_settings_to_shopify_stores',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (132,'2026_04_01_180000_create_tenant_module_access_requests_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (133,'2026_04_01_190000_add_provider_diagnostics_to_tenant_email_settings',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (134,'2026_04_03_090000_extend_marketing_message_groups_for_tenant_workspace',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (135,'2026_04_03_091000_seed_modern_forestry_messaging_entitlement',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (136,'2026_04_04_130000_extend_message_deliveries_for_message_analytics',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (137,'2026_04_04_130100_create_message_analytics_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (138,'2026_04_04_132000_enable_modern_forestry_tenant_one_messaging_module',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (139,'2026_04_04_220000_add_shopify_messaging_audience_indexes',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (140,'2026_04_04_231500_add_message_delivery_id_to_message_order_attributions',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (141,'2026_04_05_120000_add_tenant_to_square_sources',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (142,'2026_04_06_090000_add_tenant_ownership_to_marketing_authoring_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (143,'2026_04_06_150000_add_weekly_reward_windows_to_review_tasks',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (144,'2026_04_06_180000_create_marketing_message_media_assets_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (145,'2026_04_06_180746_create_messaging_conversations_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (146,'2026_04_07_090000_create_landlord_operator_actions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (147,'2026_04_07_150000_add_total_spent_to_customer_external_profiles_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (148,'2026_04_08_090000_create_embedded_messaging_campaign_pipeline_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (149,'2026_04_08_130000_add_customer_grid_tenant_search_indexes',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (150,'2026_04_09_100000_create_messaging_conversation_messages_and_channel_states',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (151,'2026_04_09_130000_add_instagram_comment_candle_cash_task',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (152,'2026_04_09_140000_update_instagram_comment_task_action_url_to_modernforestry',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (153,'2026_04_10_090000_create_tenant_discovery_profile_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (154,'2026_04_11_180000_create_tenant_onboarding_blueprints_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (155,'2026_04_11_190000_create_tenant_onboarding_blueprint_provisionings_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (156,'2026_04_11_195000_add_first_open_fields_to_tenant_onboarding_blueprint_provisionings_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (157,'2026_04_11_200000_create_tenant_onboarding_journey_events_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (158,'2026_04_12_160000_create_customer_access_requests_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (159,'2026_04_12_183000_add_lifecycle_columns_to_customer_access_requests_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (160,'2026_04_12_203000_create_stripe_webhook_events_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (161,'2026_04_13_020000_create_tenant_billing_fulfillments_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (162,'2026_04_20_150000_add_storefront_linkage_columns_to_orders_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (163,'2026_04_20_200000_create_marketing_paid_media_daily_stats_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (164,'2026_04_23_090000_create_development_notes_and_change_logs_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (165,'2026_05_21_143300_create_tenant_setup_statuses_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (166,'2026_05_21_160000_create_custom_module_requests_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (167,'2026_05_21_170000_create_shopify_privacy_webhook_events_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (168,'2026_05_21_180000_add_commercial_intent_to_tenant_setup_statuses_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (169,'2026_05_27_100000_create_service_inquiries_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (170,'2026_05_27_101000_create_client_project_portal_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (171,'2026_05_27_102000_create_client_project_ticket_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (172,'2026_06_01_150000_create_automation_workflow_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (173,'2026_06_01_170000_enable_workflow_automations_for_modern_forestry_tenant_one',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (174,'2026_06_01_180000_grant_john_collins_dual_console_access',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (175,'2026_06_25_220000_create_mobile_push_devices_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (176,'2026_06_26_120000_add_customer_read_at_to_messaging_conversation_messages_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (177,'2026_06_26_163500_add_mobile_avatar_to_marketing_profiles_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (178,'2026_06_27_120000_create_marketing_profile_scent_quiz_results_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (179,'2026_06_28_120000_create_marketing_social_share_claims_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (180,'2026_06_29_140000_create_modern_forestry_mobile_bag_snapshots_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (181,'2026_06_29_180000_create_forms_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (182,'2026_07_02_120000_create_subscription_module_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (183,'2026_07_02_121000_enable_subscription_module_for_modern_forestry_tenant_one',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (184,'2026_07_02_121500_extend_subscription_candle_club_scent_feedback',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (185,'2026_07_02_130000_create_field_service_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (186,'2026_07_04_090000_add_onboarding_guide_answers_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (187,'2026_07_06_170000_create_agentic_changes_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (188,'2026_07_06_170001_create_vision_ideas_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (189,'2026_07_06_170002_create_readiness_checklist_items_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (190,'2026_07_07_000001_create_integration_connections_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (191,'2026_07_07_000002_backfill_tenant_id_on_flagship_operational_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (192,'2026_07_09_103000_seed_modern_forestry_app_request_board',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (193,'2026_07_09_111000_create_modern_forestry_feedback_engagement_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (194,'2026_07_10_120000_create_everbranch_mobile_auth_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (195,'2026_07_10_120100_create_tenant_billing_subscriptions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (196,'2026_07_10_140000_create_tenant_messaging_platform_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (197,'2026_07_10_150000_create_shopify_product_option_rulesets',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (198,'2026_07_10_200000_create_everbranch_mobile_push_devices_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (199,'2026_07_10_220000_create_tenant_support_tickets_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (200,'2026_07_10_230000_match_shopify_product_option_storefront_handles',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (201,'2026_07_11_120000_extend_field_service_for_electrician_mvp',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (202,'2026_07_11_235500_encrypt_sensitive_integration_account_ids',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (203,'2026_07_13_120000_create_customer_merge_foundation',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (204,'2026_07_13_130000_create_quickbooks_discovery_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (205,'2026_07_13_150000_tenant_scope_shopify_import_records',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (206,'2026_07_13_170000_create_quickbooks_reporting_estimator_and_assets',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (207,'2026_07_13_230000_extend_field_service_collaboration_and_lifecycle',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (208,'2026_07_13_231000_enable_collins_estimator_and_owner',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (209,'2026_07_14_120000_extend_field_service_for_work_2',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (210,'2026_07_15_090000_create_wholesale_operations_foundation',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (211,'2026_07_15_090500_create_tenant_wholesale_settings_and_store_roles',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (212,'2026_07_15_091000_bind_wholesale_shopify_store_to_modern_forestry',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (213,'2026_07_15_120000_add_application_kind_to_customer_access_requests',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (214,'2026_07_15_130000_create_class_scheduling_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (215,'2026_07_16_190000_create_agreement_and_subscription_authorization_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (216,'2026_07_16_191000_create_tenant_billing_orders_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (217,'2026_07_17_170000_create_tenant_direct_invoices_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (218,'2026_07_17_210000_create_tenant_plant_inventory_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (219,'2026_07_18_120000_productize_workflow_automations',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (220,'2026_07_20_130000_add_equipment_maintenance_and_time_tracking',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (221,'2026_07_20_140000_link_messaging_usage_periods_to_invoices',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (222,'2026_07_20_160000_create_tenant_payment_accounts_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (223,'2026_07_20_170000_add_delivery_fields_to_agreements_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (224,'2026_07_20_180000_create_tenant_branding_profiles_and_assets',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (225,'2026_07_20_181000_repair_agreement_sms_delivery_columns',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (226,'2026_07_20_184000_add_agreement_text_composer_fields',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (227,'2026_07_20_220000_add_operator_command_center_and_support_fields',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (228,'2026_07_20_230000_create_tenant_billing_refunds_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (229,'2026_07_21_040000_reconcile_tenant_billing_refund_indexes',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (230,'2026_07_21_130000_add_customer_phone_to_tenant_direct_invoices',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (231,'2026_07_21_180000_add_multi_assignee_field_service_tasks',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (232,'2026_07_21_180000_create_reusable_field_operations_v3_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (233,'2026_07_21_190000_create_field_resource_operations_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (234,'2026_07_22_120000_add_field_operations_v7_job_draft_fields',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (235,'2026_07_23_120000_create_accounting_command_center_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (236,'2026_07_23_140000_create_landlord_prospect_pipeline_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (237,'2026_07_24_120000_add_workflow_studio_v2_foundation',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (238,'2026_07_24_120100_create_workflow_action_receipts',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (239,'2026_07_24_120200_add_oauth_client_credentials_to_integration_connections',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (240,'2026_07_27_120000_create_managed_website_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (241,'2026_07_27_180000_create_website_commerce_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (242,'2026_07_27_181000_repair_partial_website_commerce_schema',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (243,'2026_07_27_200000_add_versioned_theme_and_media_to_tenant_sites',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (244,'2026_07_27_230000_create_tenant_site_domains_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (245,'2026_07_30_120000_add_discovery_to_landlord_prospect_pipeline',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (246,'2026_08_01_120000_create_tenant_site_setups_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (247,'2026_08_01_180000_normalize_collins_workspace_theme',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (248,'2026_08_02_200000_add_wholesale_price_to_website_product_variants',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (249,'2026_08_07_180000_create_customer_loop_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (250,'2026_08_07_190000_add_commerce_operations_and_shipping_foundation',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (251,'2026_08_08_030000_add_paid_ai_controls_to_tenant_bud_settings',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (252,'2026_08_11_120000_create_wholesale_email_messenger_drafts_table',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (253,'2026_08_13_150000_add_completion_state_to_modern_forestry_mobile_bag_snapshots',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (254,'2026_08_13_151000_reconcile_modern_forestry_product_option_assignments',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (255,'2026_08_13_160000_create_field_workforce_and_fleet_tracking_tables',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (256,'2026_08_19_140000_create_modern_forestry_fundraiser_invoice_preparation_tables',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (257,'2026_08_20_120000_add_reporting_destination_fields_to_orders_table',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (258,'2026_08_24_120000_add_archival_to_marketing_profiles',8);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (259,'2026_08_25_150000_add_job_update_sms_setting_to_field_service_reminder_settings',9);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (260,'2026_09_02_160000_enable_collins_messaging_branch',9);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (261,'2026_09_03_150000_add_quickbooks_source_to_customer_equipment',9);
