/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `cost_centers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cost_centers` (
  `code` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `name` varchar(120) DEFAULT NULL,
  PRIMARY KEY (`code`),
  CONSTRAINT `ck_cost_center_reserved_key` CHECK (`code` <> '__ALL__')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `scrap_revisions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `scrap_revisions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `snapshot_id` bigint(20) unsigned NOT NULL,
  `cost_center_code` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `work_order` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `product_code` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `revision` int(10) unsigned NOT NULL,
  `value_m2` decimal(19,6) DEFAULT NULL,
  `recorded_at` datetime(6) NOT NULL,
  `recorded_by` varchar(256) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_scrap_revision_target` (`snapshot_id`,`cost_center_code`,`work_order`,`product_code`,`revision`),
  CONSTRAINT `fk_scrap_revision_target` FOREIGN KEY (`snapshot_id`, `cost_center_code`, `work_order`, `product_code`) REFERENCES `snapshot_scraps` (`snapshot_id`, `cost_center_code`, `work_order`, `product_code`),
  CONSTRAINT `ck_scrap_revision_positive` CHECK (`revision` > 0),
  CONSTRAINT `ck_scrap_revision_value_nonnegative` CHECK (`value_m2` is null or `value_m2` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `snapshot_metrics`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `snapshot_metrics` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `snapshot_id` bigint(20) unsigned NOT NULL,
  `grouping_mask` tinyint(3) unsigned NOT NULL,
  `cost_center_key` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `movement_type_key` varchar(12) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `unit_code_key` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `record_count` int(10) unsigned NOT NULL,
  `distinct_work_orders` int(10) unsigned NOT NULL,
  `total_cost` decimal(19,2) NOT NULL,
  `quantity_total` decimal(19,6) DEFAULT NULL,
  `scrap_total_m2` decimal(19,6) NOT NULL,
  `reported_scrap_count` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_snapshot_metric_dimensions` (`snapshot_id`,`grouping_mask`,`cost_center_key`,`movement_type_key`,`unit_code_key`),
  CONSTRAINT `fk_metric_snapshot` FOREIGN KEY (`snapshot_id`) REFERENCES `upload_snapshots` (`id`),
  CONSTRAINT `ck_metric_grouping_mask` CHECK (`grouping_mask` between 0 and 7),
  CONSTRAINT `ck_metric_counts_nonnegative` CHECK (`record_count` >= 0 and `distinct_work_orders` >= 0 and `reported_scrap_count` >= 0),
  CONSTRAINT `ck_metric_quantity_requires_unit` CHECK (`unit_code_key` <> '__ALL__' or `quantity_total` is null)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `snapshot_movements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `snapshot_movements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `snapshot_id` bigint(20) unsigned NOT NULL,
  `source_row` int(10) unsigned NOT NULL,
  `cost_center_code` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `work_order` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `movement_type` varchar(12) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `product_code` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `description` varchar(500) NOT NULL,
  `quantity` decimal(19,6) NOT NULL,
  `unit_code` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `unit_cost` decimal(19,6) NOT NULL,
  `cost` decimal(19,2) NOT NULL,
  `document` varchar(40) NOT NULL,
  `issue_date` date NOT NULL,
  `standard_cost` decimal(19,6) DEFAULT NULL,
  `standard_total` decimal(19,6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_movement_snapshot_source_row` (`snapshot_id`,`source_row`),
  KEY `fk_movement_cost_center` (`cost_center_code`),
  KEY `fk_movement_unit` (`unit_code`),
  KEY `ix_movement_snapshot_filters` (`snapshot_id`,`cost_center_code`,`movement_type`,`unit_code`,`issue_date`),
  KEY `ix_movement_snapshot_work_order` (`snapshot_id`,`work_order`),
  CONSTRAINT `fk_movement_cost_center` FOREIGN KEY (`cost_center_code`) REFERENCES `cost_centers` (`code`),
  CONSTRAINT `fk_movement_snapshot` FOREIGN KEY (`snapshot_id`) REFERENCES `upload_snapshots` (`id`),
  CONSTRAINT `fk_movement_unit` FOREIGN KEY (`unit_code`) REFERENCES `units_of_measure` (`code`),
  CONSTRAINT `ck_movement_source_row_positive` CHECK (`source_row` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `snapshot_scraps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `snapshot_scraps` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `snapshot_id` bigint(20) unsigned NOT NULL,
  `cost_center_code` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `work_order` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `product_code` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `description` varchar(500) NOT NULL,
  `movement_type` varchar(12) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_snapshot_scrap_target` (`snapshot_id`,`cost_center_code`,`work_order`,`product_code`),
  KEY `fk_scraps_cost_center` (`cost_center_code`),
  CONSTRAINT `fk_scraps_cost_center` FOREIGN KEY (`cost_center_code`) REFERENCES `cost_centers` (`code`),
  CONSTRAINT `fk_scraps_snapshot` FOREIGN KEY (`snapshot_id`) REFERENCES `upload_snapshots` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `units_of_measure`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `units_of_measure` (
  `code` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  PRIMARY KEY (`code`),
  CONSTRAINT `ck_unit_reserved_key` CHECK (`code` <> '__ALL__')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `upload_snapshots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `upload_snapshots` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `reference_date` date NOT NULL,
  `version` int(10) unsigned NOT NULL,
  `filename` varchar(260) NOT NULL,
  `sha256` char(64) NOT NULL,
  `imported_at` datetime(6) NOT NULL,
  `row_count` int(10) unsigned NOT NULL,
  `source_total` decimal(19,2) NOT NULL,
  `imported_total` decimal(19,2) NOT NULL,
  `reconciliation_difference` decimal(19,2) NOT NULL,
  `reconciliation_status` varchar(24) NOT NULL,
  `confirmed_by` varchar(256) DEFAULT NULL,
  `confirmed_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_snapshot_date_version` (`reference_date`,`version`),
  UNIQUE KEY `uq_snapshot_date_sha256` (`reference_date`,`sha256`),
  CONSTRAINT `ck_snapshot_version_positive` CHECK (`version` > 0),
  CONSTRAINT `ck_snapshot_row_count_nonnegative` CHECK (`row_count` >= 0),
  CONSTRAINT `ck_snapshot_reconciliation_status` CHECK (`reconciliation_status` in ('matched','confirmed_mismatch')),
  CONSTRAINT `ck_snapshot_reconciliation_difference` CHECK (`reconciliation_difference` = `imported_total` - `source_total`),
  CONSTRAINT `ck_snapshot_reconciliation_confirmation` CHECK (`reconciliation_status` = 'matched' and `reconciliation_difference` = 0 or `reconciliation_status` = 'confirmed_mismatch' and `reconciliation_difference` <> 0 and `confirmed_by` is not null and `confirmed_at` is not null)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (2,'2026_11_12_000000_create_material_control_schema',1);
