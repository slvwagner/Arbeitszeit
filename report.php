<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

$projects = get_projects($pdo);
$defaultProjectId = (int) ($projects[0]['id'] ?? 0);
[$year, $month] = selected_month();
$projectId = filter_input(INPUT_GET, 'project_id', FILTER_VALIDATE_INT) ?: $defaultProjectId;
$project = get_project($pdo, $projectId);
$entries = get_month_entries($pdo, (int) $currentEmployee['id'], $projectId, $year, $month);
$monthTotal = get_month_total($pdo, (int) $currentEmployee['id'], $projectId, $year, $month);
$report = get_monthly_report($pdo, (int) $currentEmployee['id'], $projectId, $year, $month);
$firstDay = sprintf('%04d-%02d-01', $year, $month);
$daysInMonth = (int) date('t', strtotime($firstDay));
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Arbeitsnachweis <?= h(month_name($month)) ?> <?= $year ?></title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body class="print-page">
<main class="report-sheet">
    <div class="report-actions">
        <button class="button primary" onclick="window.print()">Drucken</button>
        <a class="button" href="monthly.php?year=<?= $year ?>&month=<?= $month ?>&project_id=<?= $projectId ?>">Zurück</a>
    </div>

    <h1>Arbeitsnachweis <?= h($currentEmployee['display_name']) ?></h1>
    <div class="report-meta">
        <div><strong>Projekt</strong><br><?= h($project['name'] ?? '') ?></div>
        <div><strong>Monat</strong><br><?= h(month_name($month)) ?> <?= $year ?></div>
        <div><strong>Status</strong><br><?= h(status_label((string) $report['approval_status'])) ?></div>
    </div>

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
        <?php for ($day = 1; $day <= $daysInMonth; $day++): ?>
            <?php
            $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
            $entry = $entries[$date] ?? null;
            $segments = $entry['segments'] ?? [];
            ?>
            <tr>
                <td><?= h(format_date($date)) ?></td>
                <td><?= h(substr((string) ($segments[0]['start_time'] ?? ''), 0, 5)) ?></td>
                <td><?= h(substr((string) ($segments[0]['end_time'] ?? ''), 0, 5)) ?></td>
                <td><?= h(substr((string) ($segments[1]['start_time'] ?? ''), 0, 5)) ?></td>
                <td><?= h(substr((string) ($segments[1]['end_time'] ?? ''), 0, 5)) ?></td>
                <td class="number"><?= format_hours((float) ($entry['total_hours'] ?? 0)) ?></td>
                <td><?= h($entry['activity'] ?? '') ?></td>
            </tr>
        <?php endfor; ?>
        </tbody>
        <tfoot>
        <tr>
            <th colspan="5">Summe geleisteter Stunden</th>
            <th class="number"><?= format_hours((float) $monthTotal['total_hours']) ?></th>
            <th>Std.</th>
        </tr>
        <tr>
            <th colspan="5">Anzahl geleisteter Arbeitstage (8 Std./Tag)</th>
            <th class="number"><?= format_hours((float) $monthTotal['total_days']) ?></th>
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
            <strong><?= h($report['signature_name'] ?: $report['approver_name'] ?: '') ?></strong>
        </div>
    </section>
</main>
<script>
    document.documentElement.dataset.theme = localStorage.getItem('arbeitszeit-theme') || 'dark';
</script>
</body>
</html>
