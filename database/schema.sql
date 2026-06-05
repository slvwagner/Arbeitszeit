CREATE DATABASE IF NOT EXISTS `arbeitszeit`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `arbeitszeit`;

SET FOREIGN_KEY_CHECKS = 0;

DROP VIEW IF EXISTS `v_project_budget_usage`;
DROP VIEW IF EXISTS `v_monthly_project_totals`;
DROP VIEW IF EXISTS `v_work_entry_totals`;

DROP TABLE IF EXISTS `monthly_reports`;
DROP TABLE IF EXISTS `weekly_tasks`;
DROP TABLE IF EXISTS `work_segments`;
DROP TABLE IF EXISTS `work_entries`;
DROP TABLE IF EXISTS `project_budgets`;
DROP TABLE IF EXISTS `projects`;
DROP TABLE IF EXISTS `approvers`;
DROP TABLE IF EXISTS `employees`;
DROP TABLE IF EXISTS `schema_migrations`;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE `schema_migrations` (
  `version` varchar(50) NOT NULL,
  `applied_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `employees` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `display_name` varchar(160) NOT NULL,
  `email` varchar(190) DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_employees_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `approvers` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `display_name` varchar(160) NOT NULL,
  `email` varchar(190) DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_approvers_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `projects` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(60) NOT NULL,
  `name` varchar(220) NOT NULL,
  `description` text DEFAULT NULL,
  `invoice_reference_default` varchar(120) DEFAULT NULL,
  `default_hours_per_day` decimal(5,2) NOT NULL DEFAULT 8.00,
  `status` enum('active','archived') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_projects_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `project_budgets` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `project_id` int unsigned NOT NULL,
  `employee_id` int unsigned DEFAULT NULL,
  `label` varchar(160) NOT NULL,
  `budget_days` decimal(8,2) NOT NULL,
  `hours_per_day` decimal(5,2) NOT NULL DEFAULT 8.00,
  `valid_from` date DEFAULT NULL,
  `valid_until` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_project_budgets_project` (`project_id`),
  KEY `idx_project_budgets_employee` (`employee_id`),
  CONSTRAINT `fk_project_budgets_project`
    FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_project_budgets_employee`
    FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `work_entries` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `employee_id` int unsigned NOT NULL,
  `project_id` int unsigned NOT NULL,
  `work_date` date NOT NULL,
  `break_minutes` smallint unsigned NOT NULL DEFAULT 0,
  `activity` text DEFAULT NULL,
  `approval_status` enum('draft','submitted','approved','rejected') NOT NULL DEFAULT 'draft',
  `invoice_status` enum('not_ready','ready','submitted','paid') NOT NULL DEFAULT 'not_ready',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_work_entries_employee_project_date` (`employee_id`,`project_id`,`work_date`),
  KEY `idx_work_entries_project_date` (`project_id`,`work_date`),
  CONSTRAINT `fk_work_entries_employee`
    FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_work_entries_project`
    FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `weekly_tasks` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `employee_id` int unsigned NOT NULL,
  `project_id` int unsigned NOT NULL,
  `week_start` date NOT NULL,
  `summary` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_weekly_tasks_employee_project_week` (`employee_id`,`project_id`,`week_start`),
  KEY `idx_weekly_tasks_project_week` (`project_id`,`week_start`),
  CONSTRAINT `fk_weekly_tasks_employee`
    FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_weekly_tasks_project`
    FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `work_segments` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `work_entry_id` int unsigned NOT NULL,
  `segment_no` tinyint unsigned NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_work_segments_entry_segment` (`work_entry_id`,`segment_no`),
  CONSTRAINT `fk_work_segments_entry`
    FOREIGN KEY (`work_entry_id`) REFERENCES `work_entries` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `monthly_reports` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `employee_id` int unsigned NOT NULL,
  `project_id` int unsigned NOT NULL,
  `report_year` smallint unsigned NOT NULL,
  `report_month` tinyint unsigned NOT NULL,
  `report_title` varchar(220) DEFAULT NULL,
  `approval_status` enum('draft','submitted','approved','rejected') NOT NULL DEFAULT 'draft',
  `invoice_status` enum('not_ready','ready','submitted','paid') NOT NULL DEFAULT 'not_ready',
  `submitted_at` datetime DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `approved_by` int unsigned DEFAULT NULL,
  `invoice_reference` varchar(120) DEFAULT NULL,
  `signature_name` varchar(160) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_monthly_reports_employee_project_month` (`employee_id`,`project_id`,`report_year`,`report_month`),
  KEY `idx_monthly_reports_project_month` (`project_id`,`report_year`,`report_month`),
  KEY `idx_monthly_reports_approver` (`approved_by`),
  CONSTRAINT `fk_monthly_reports_employee`
    FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_monthly_reports_project`
    FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_monthly_reports_approver`
    FOREIGN KEY (`approved_by`) REFERENCES `approvers` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE VIEW `v_work_entry_totals` AS
SELECT
  `we`.`id` AS `work_entry_id`,
  `we`.`employee_id`,
  `we`.`project_id`,
  `we`.`work_date`,
  `we`.`activity`,
  GREATEST(COALESCE(SUM(TIME_TO_SEC(TIMEDIFF(`ws`.`end_time`, `ws`.`start_time`)) / 3600), 0) - (`we`.`break_minutes` / 60), 0) AS `total_hours`,
  GREATEST(COALESCE(SUM(TIME_TO_SEC(TIMEDIFF(`ws`.`end_time`, `ws`.`start_time`)) / 3600), 0) - (`we`.`break_minutes` / 60), 0) / `p`.`default_hours_per_day` AS `total_days`
FROM `work_entries` `we`
JOIN `projects` `p` ON `p`.`id` = `we`.`project_id`
LEFT JOIN `work_segments` `ws` ON `ws`.`work_entry_id` = `we`.`id`
GROUP BY
  `we`.`id`,
  `we`.`employee_id`,
  `we`.`project_id`,
  `we`.`work_date`,
  `we`.`activity`,
  `we`.`break_minutes`,
  `p`.`default_hours_per_day`;

CREATE VIEW `v_monthly_project_totals` AS
SELECT
  `employee_id`,
  `project_id`,
  YEAR(`work_date`) AS `report_year`,
  MONTH(`work_date`) AS `report_month`,
  ROUND(SUM(`total_hours`), 2) AS `total_hours`,
  ROUND(SUM(`total_days`), 2) AS `total_days`,
  COUNT(CASE WHEN `total_hours` > 0 THEN 1 END) AS `worked_dates`
FROM `v_work_entry_totals`
GROUP BY
  `employee_id`,
  `project_id`,
  YEAR(`work_date`),
  MONTH(`work_date`);

CREATE VIEW `v_project_budget_usage` AS
SELECT
  `pb`.`id` AS `budget_id`,
  `pb`.`project_id`,
  `pb`.`employee_id`,
  `pb`.`label`,
  `pb`.`budget_days`,
  ROUND(`pb`.`budget_days` * `pb`.`hours_per_day`, 2) AS `budget_hours`,
  ROUND(COALESCE(SUM(`wet`.`total_hours`), 0), 2) AS `used_hours`,
  ROUND(COALESCE(SUM(`wet`.`total_hours`), 0) / `pb`.`hours_per_day`, 2) AS `used_days`,
  ROUND(`pb`.`budget_days` - (COALESCE(SUM(`wet`.`total_hours`), 0) / `pb`.`hours_per_day`), 2) AS `remaining_days`
FROM `project_budgets` `pb`
LEFT JOIN `v_work_entry_totals` `wet`
  ON `wet`.`project_id` = `pb`.`project_id`
  AND (`pb`.`employee_id` IS NULL OR `pb`.`employee_id` = `wet`.`employee_id`)
  AND (`pb`.`valid_from` IS NULL OR `wet`.`work_date` >= `pb`.`valid_from`)
  AND (`pb`.`valid_until` IS NULL OR `wet`.`work_date` <= `pb`.`valid_until`)
GROUP BY
  `pb`.`id`,
  `pb`.`project_id`,
  `pb`.`employee_id`,
  `pb`.`label`,
  `pb`.`budget_days`,
  `pb`.`hours_per_day`;

INSERT INTO `employees` (`display_name`, `email`)
VALUES ('Demo Employee', NULL);

INSERT INTO `approvers` (`display_name`, `email`)
VALUES
  ('Demo Approver', NULL),
  ('Demo Approver', NULL);

INSERT INTO `projects` (`code`, `name`, `description`, `invoice_reference_default`, `default_hours_per_day`)
VALUES
  ('demo-project', 'Demo Project', 'Projekt aus der Arbeitszeit-Übersicht.', 'INV-DEMO-2026', 8.00),
  ('demo-project', 'Demo Project', 'Zweiter Kontingentblock aus der Arbeitszeit-Übersicht.', 'INV-DEMO-2026', 8.00);

INSERT INTO `project_budgets` (`project_id`, `employee_id`, `label`, `budget_days`, `hours_per_day`, `valid_from`, `valid_until`)
SELECT `p`.`id`, `e`.`id`, 'Gesamtkontingent', 120.00, 8.00, '2025-10-01', NULL
FROM `projects` `p`
JOIN `employees` `e` ON `e`.`display_name` = 'Demo Employee'
WHERE `p`.`code` = 'demo-project';

INSERT INTO `project_budgets` (`project_id`, `employee_id`, `label`, `budget_days`, `hours_per_day`, `valid_from`, `valid_until`)
SELECT `p`.`id`, `e`.`id`, 'Gesamtkontingent', 100.00, 8.00, '2026-06-01', NULL
FROM `projects` `p`
JOIN `employees` `e` ON `e`.`display_name` = 'Demo Employee'
WHERE `p`.`code` = 'demo-project';

INSERT INTO `schema_migrations` (`version`)
VALUES ('001_initial_schema');
