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
$totalHours = 0.0;
$totalDays = 0.0;
foreach ($entries as $entry) {
    $totalHours += (float) ($entry['total_hours'] ?? 0);
    $totalDays += (float) ($entry['total_days'] ?? 0);
}

$report = get_monthly_report($pdo, $employeeId, $projectId, $year, $month);
$signatureName = trim((string) ($_GET['signature_name'] ?? ''));
if ($signatureName === '') {
    $signatureName = (string) ($report['signature_name'] ?: $report['approver_name'] ?: '');
}

$titlePeriod = $scope === 'week'
    ? 'Woche ' . iso_week_label($periodStart) . ' (' . format_date($periodStart) . ' - ' . format_date($periodEnd) . ')'
    : month_name($month) . ' ' . $year;

$startTs = strtotime($periodStart);
$endTs = strtotime($periodEnd);
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Arbeitsnachweis <?= h($titlePeriod) ?></title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body class="print-page">
<main class="report-sheet">
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
        <a class="button" href="monthly.php?year=<?= $year ?>&month=<?= $month ?>&project_id=<?= $projectId ?>">Zurueck</a>
    </form>

    <h1>Arbeitsnachweis <?= h($currentEmployee['display_name']) ?></h1>
    <div class="report-meta">
        <div><strong>Projekt</strong><br><?= h($project['name'] ?? '') ?></div>
        <div><strong>Zeitraum</strong><br><?= h($titlePeriod) ?></div>
        <div><strong>Status</strong><br><?= h(status_label((string) $report['approval_status'])) ?></div>
    </div>

    <section class="project-specs">
        <h2>Projektspezifikation</h2>
        <div class="project-spec-grid">
            <div><strong>Code</strong><br><?= h((string) ($project['code'] ?? '-')) ?></div>
            <div><strong>Std./Tag</strong><br><?= format_hours((float) ($project['default_hours_per_day'] ?? 8.0)) ?></div>
            <div class="project-spec-wide"><strong>Beschreibung</strong><br><?= nl2br(h((string) ($project['description'] ?? 'Keine Beschreibung hinterlegt.'))) ?></div>
        </div>
    </section>

    <table class="report-table">
        <thead>
        <tr>
            <th>Arbeitstag</th>
            <th>Start</th>
            <th>Ende</th>
            <th>Start 2</th>
            <th>Ende 2</th>
            <th>Std.</th>
            <th>Tätigkeit</th>
        </tr>
        </thead>
        <tbody>
        <?php
        $cursorTs = $startTs;
        while ($cursorTs <= $endTs):
            $currentDate = date('Y-m-d', $cursorTs);
            $entry = $entries[$currentDate] ?? null;
            $segments = $entry['segments'] ?? [];

            if (date('N', $cursorTs) === '1'):
                $weekStart = week_start_from_date($currentDate);
                $week = $weeklyBlocks[$weekStart] ?? null;
                if ($week):
        ?>
            <tr class="week-header-row">
                <td colspan="7"><strong>Woche <?= h($week['label']) ?></strong> - <?= h(format_date($week['start'])) ?> bis <?= h(format_date($week['end'])) ?></td>
            </tr>
        <?php
                endif;
            endif;
        ?>
            <tr>
                <td><?= h(format_date($currentDate)) ?></td>
                <td><?= h(substr((string) ($segments[0]['start_time'] ?? ''), 0, 5)) ?></td>
                <td><?= h(substr((string) ($segments[0]['end_time'] ?? ''), 0, 5)) ?></td>
                <td><?= h(substr((string) ($segments[1]['start_time'] ?? ''), 0, 5)) ?></td>
                <td><?= h(substr((string) ($segments[1]['end_time'] ?? ''), 0, 5)) ?></td>
                <td class="number"><?= format_hours((float) ($entry['total_hours'] ?? 0)) ?></td>
                <td><?= h((string) ($entry['activity'] ?? '')) ?></td>
            </tr>
        <?php
            if (date('N', $cursorTs) === '7'):
                $weekStart = week_start_from_date($currentDate);
                $week = $weeklyBlocks[$weekStart] ?? null;
                if ($week):
        ?>
            <tr class="week-total-row">
                <td colspan="5"><strong>Wochensumme</strong></td>
                <td class="number"><strong><?= format_hours((float) $week['hours']) ?></strong></td>
                <td><?= format_hours((float) $week['days']) ?> Tage</td>
            </tr>
        <?php
                endif;
            endif;

            $cursorTs = strtotime('+1 day', $cursorTs);
        endwhile;
        ?>
        </tbody>
        <tfoot>
        <tr>
            <th colspan="5">Summe geleisteter Stunden</th>
            <th class="number"><?= format_hours($totalHours) ?></th>
            <th>Std.</th>
        </tr>
        <tr>
            <th colspan="5">Anzahl geleisteter Arbeitstage (8 Std./Tag)</th>
            <th class="number"><?= format_hours($totalDays) ?></th>
            <th>Tage</th>
        </tr>
        </tfoot>
    </table>

    <section class="signature-row">
        <div>
            <div class="signature-line"></div>
            <strong>Visierung</strong>
        </div>
        <div>
            <div class="signature-line"></div>
            <strong><?= h($signatureName) ?></strong>
        </div>
    </section>
</main>
<script>
    document.documentElement.dataset.theme = localStorage.getItem('arbeitszeit-theme') || 'dark';
</script>
</body>
</html>
