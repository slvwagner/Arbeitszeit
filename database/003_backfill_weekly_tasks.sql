USE `arbeitszeit`;

INSERT INTO `weekly_tasks` (`employee_id`, `project_id`, `week_start`, `summary`)
SELECT
  `we`.`employee_id`,
  `we`.`project_id`,
  DATE_SUB(`we`.`work_date`, INTERVAL WEEKDAY(`we`.`work_date`) DAY) AS `week_start`,
  GROUP_CONCAT(DISTINCT TRIM(`we`.`activity`) ORDER BY TRIM(`we`.`activity`) SEPARATOR '\n') AS `summary`
FROM `work_entries` `we`
WHERE `we`.`activity` IS NOT NULL
  AND TRIM(`we`.`activity`) <> ''
GROUP BY
  `we`.`employee_id`,
  `we`.`project_id`,
  DATE_SUB(`we`.`work_date`, INTERVAL WEEKDAY(`we`.`work_date`) DAY)
ON DUPLICATE KEY UPDATE
  `summary` = VALUES(`summary`),
  `updated_at` = current_timestamp();
