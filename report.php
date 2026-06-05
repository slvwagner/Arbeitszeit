<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

$projects = get_projects($pdo);
$defaultProjectId = (int) ($projects[0]['id'] ?? 0);
$scope = (string) ($_GET['scope'] ?? 'month');
$scope = in_array($scope, ['month', 'week'], true) ? $scope : 'month';
$date = (string) ($_GET['date'] ?? date('Y-m-d'));

[$year, $month] = selected_month();
$projectId = filter_input(INPUT_GET, 'project_id', FILTER_VALIDATE_INT) ?: $defaultProjectId;
$project = get_project($pdo, $projectId);
$employeeId = (int) $currentEmployee['id'];

$periodStart = sprintf('%04d-%02d-01', $year, $month);
$periodEnd = date('Y-m-t', strtotime($periodStart));
if ($scope === 'week') {
    $periodStart = week_start_from_date($date);
    $periodEnd = week_end_from_start($periodStart);
    $year = (int) date('Y', strtotime($periodStart));
    $month = (int) date('n', strtotime($periodStart));
}

$stmt = $pdo->prepare(
    'SELECT we.*, wet.total_hours, wet.total_days
     FROM work_entries we
     LEFT JOIN v_work_entry_totals wet ON wet.work_entry_id = we.id
     WHERE we.employee_id = ? AND we.project_id = ? AND we.work_date BETWEEN ? AND ?
     ORDER BY we.work_date'
);
$stmt->execute([$employeeId, $projectId, $periodStart, $periodEnd]);

$entries = [];
foreach ($stmt->fetchAll() as $entry) {
    $segmentStmt = $pdo->prepare('SELECT * FROM work_segments WHERE work_entry_id = ? ORDER BY segment_no');
    $segmentStmt->execute([(int) $entry['id']]);
    $entry['segments'] = $segmentStmt->fetchAll();
    $entries[$entry['work_date']] = $entry;
}

$weeklyBlocks = summarize_weeks_from_entries($entries);
$weeklyTasks = get_weekly_tasks_for_period($pdo, $employeeId, $projectId, week_start_from_date($periodStart), week_start_from_date($periodEnd));
$totalHours = 0.0;
$totalDays = 0.0;
foreach ($entries as $entry) {
    $totalHours += (float) ($entry['total_hours'] ?? 0);
    $totalDays += (float) ($entry['total_days'] ?? 0);
}

$report = get_monthly_report($pdo, $employeeId, $projectId, $year, $month);
$signatureName = trim((string) ($_GET['signature_name'] ?? ''));
if ($signatureName === '') {
    $signatureName = (string) ($report['signature_name'] ?: $report['approver_name'] ?: 'Demo Approver');
}

$titlePeriod = $scope === 'week'
    ? 'Woche ' . iso_week_label($periodStart) . ' (' . format_date($periodStart) . ' - ' . format_date($periodEnd) . ')'
    : month_name($month) . ' ' . $year . ' (' . format_date($periodStart) . ' - ' . format_date($periodEnd) . ')';

$remainingStmt = $pdo->prepare(
    'SELECT ROUND(COALESCE(SUM(remaining_days), 0), 2) AS remaining_days
     FROM v_project_budget_usage
     WHERE project_id = ? AND (employee_id = ? OR employee_id IS NULL)'
);
$remainingStmt->execute([$projectId, $employeeId]);
$remainingDays = (float) (($remainingStmt->fetch()['remaining_days'] ?? 0));

$startTs = strtotime($periodStart);
$endTs = strtotime($periodEnd);

render_header('Arbeitsnachweis', 'monthly');
?>
<main class="report-sheet print-page">
    <form class="report-actions" method="get">
        <input type="hidden" name="project_id" value="<?= $projectId ?>">
        <input type="hidden" name="year" value="<?= $year ?>">
        <input type="hidden" name="month" value="<?= $month ?>">

        <label>
            Zeitraum
            <select name="scope" onchange="this.form.submit()">
                <option value="month" <?= $scope === 'month' ? 'selected' : '' ?>>Monat</option>
                <option value="week" <?= $scope === 'week' ? 'selected' : '' ?>>Woche</option>
            </select>
        </label>
        <?php if ($scope === 'week'): ?>
            <label>
                Woche
                <input type="date" name="date" value="<?= h($periodStart) ?>">
            </label>
        <?php else: ?>
            <label>
                Monat
                <input type="month" name="period" value="<?= sprintf('%04d-%02d', $year, $month) ?>">
            </label>
        <?php endif; ?>
        <label>
            Signatur Name
            <input type="text" name="signature_name" value="<?= h($signatureName) ?>" placeholder="Name fuer Signatur">
        </label>
        <button class="button" type="submit">Aktualisieren</button>
        <button class="button primary" type="button" onclick="window.print()">Als PDF drucken</button>
        <a class="button" href="monthly.php?year=<?= $year ?>&month=<?= $month ?>&project_id=<?= $projectId ?>">Zurück</a>
    </form>

    <h1>Arbeitsnachweis <?= h($currentEmployee['display_name']) ?></h1>
    <div class="report-meta">
        <div><strong>Projekt</strong><br><?= h($project['name'] ?? '') ?></div>
        <div><strong>Zeitraum</strong><br><?= h($titlePeriod) ?></div>
        <div><strong>Rechnungsreferenz</strong><br><?= h((string) ($project['invoice_reference_default'] ?? $report['invoice_reference'] ?? '-')) ?></div>
    </div>

    <table class="report-table">
        <thead>
        <tr>
            <th>Arbeitstag</th>
            <th>Start</th>
            <th>Ende</th>
            <th>Pause</th>
            <th>Std.</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($scope === 'month'): ?>
            <?php $printedWeeks = []; ?>
            <?php foreach ($entries as $currentDate => $entry): ?>
                <?php
                $displayTimes = entry_display_times($entry);
                $weekStart = week_start_from_date($currentDate);
                $isFirstEntryInWeek = !isset($printedWeeks[$weekStart]);
                if ($isFirstEntryInWeek) {
                    $printedWeeks[$weekStart] = true;
                }
                ?>
                <?php if ($isFirstEntryInWeek): ?>
                    <tr class="week-header-row">
                        <td colspan="5"><strong>Woche <?= h(iso_week_label($weekStart)) ?></strong> - <?= h(format_date($weekStart)) ?> bis <?= h(format_date(week_end_from_start($weekStart))) ?></td>
                    </tr>
                    <tr class="week-task-row">
                        <td colspan="5"><strong>Wochenaufgaben:</strong> <?= h((string) ($weeklyTasks[$weekStart]['summary'] ?? '-')) ?></td>
                    </tr>
                <?php endif; ?>
                <tr>
                    <td><?= h(format_date($currentDate)) ?></td>
                    <td><?= h((string) $displayTimes['start']) ?></td>
                    <td><?= h((string) $displayTimes['end']) ?></td>
                    <td><?= (int) $displayTimes['pause_minutes'] ?> Min.</td>
                    <td class="number"><?= format_hours((float) ($entry['total_hours'] ?? 0)) ?></td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <?php
            $cursorTs = $startTs;
            while ($cursorTs <= $endTs):
                $currentDate = date('Y-m-d', $cursorTs);
                $entry = $entries[$currentDate] ?? null;

                if (date('N', $cursorTs) === '1'):
                    $weekStart = week_start_from_date($currentDate);
            ?>
                <tr class="week-header-row">
                    <td colspan="5"><strong>Woche <?= h(iso_week_label($weekStart)) ?></strong> - <?= h(format_date($weekStart)) ?> bis <?= h(format_date(week_end_from_start($weekStart))) ?></td>
                </tr>
                <tr class="week-task-row">
                    <td colspan="5"><strong>Wochenaufgaben:</strong> <?= h((string) ($weeklyTasks[$weekStart]['summary'] ?? '-')) ?></td>
                </tr>
            <?php endif; ?>
                <?php $displayTimes = $entry ? entry_display_times($entry) : ['start' => '', 'end' => '', 'pause_minutes' => 0]; ?>
                <tr>
                    <td><?= h(format_date($currentDate)) ?></td>
                    <td><?= h((string) $displayTimes['start']) ?></td>
                    <td><?= h((string) $displayTimes['end']) ?></td>
                    <td><?= (int) $displayTimes['pause_minutes'] ?> Min.</td>
                    <td class="number"><?= format_hours((float) ($entry['total_hours'] ?? 0)) ?></td>
                </tr>
            <?php
                $cursorTs = strtotime('+1 day', $cursorTs);
            endwhile;
            ?>
        <?php endif; ?>
        </tbody>
        <tfoot>
        <tr>
            <th colspan="3">Summe geleisteter Stunden</th>
            <th class="number"><?= format_hours($totalHours) ?></th>
            <th>Std.</th>
        </tr>
        <tr>
            <th colspan="3">Anzahl geleisteter Arbeitstage (8 Std./Tag)</th>
            <th class="number"><?= format_hours($totalDays) ?></th>
            <th>Tage</th>
        </tr>
        <tr>
            <th colspan="3">Restkontingent Arbeitstage</th>
            <th class="number"><?= format_hours($remainingDays) ?></th>
            <th>Tage</th>
        </tr>
        </tfoot>
    </table>

    <section class="signature-row">
        <div>
            <div class="signature-line"></div>
            <strong><?= h($signatureName) ?></strong>
        </div>
    </section>
</main>
<?php render_footer(); ?>
