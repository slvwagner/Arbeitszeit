USE `arbeitszeit`;

ALTER TABLE `projects`
  ADD COLUMN IF NOT EXISTS `invoice_reference_default` varchar(120) DEFAULT NULL AFTER `description`;

ALTER TABLE `work_entries`
  ADD COLUMN IF NOT EXISTS `break_minutes` smallint unsigned NOT NULL DEFAULT 0 AFTER `work_date`;

CREATE TABLE IF NOT EXISTS `weekly_tasks` (
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

DROP VIEW IF EXISTS `v_work_entry_totals`;
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

DROP VIEW IF EXISTS `v_monthly_project_totals`;
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

DROP VIEW IF EXISTS `v_project_budget_usage`;
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
