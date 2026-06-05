<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

$projects = get_projects($pdo);
$budgetRows = $pdo->query(
    'SELECT v.*, p.name AS project_name, p.code
     FROM v_project_budget_usage v
     JOIN projects p ON p.id = v.project_id
     ORDER BY p.name'
)->fetchAll();

usort($budgetRows, static function (array $a, array $b): int {
    return ((float) $a['remaining_days'] <=> (float) $b['remaining_days']);
});

$recentStmt = $pdo->prepare(
    'SELECT we.*, p.name AS project_name, wet.total_hours
     FROM work_entries we
     JOIN projects p ON p.id = we.project_id
     LEFT JOIN v_work_entry_totals wet ON wet.work_entry_id = we.id
     WHERE we.employee_id = ?
     ORDER BY we.work_date DESC
     LIMIT 8'
);
$recentStmt->execute([(int) $currentEmployee['id']]);
$recentEntries = $recentStmt->fetchAll();

[$year, $month] = selected_month();

render_header('Übersicht', 'dashboard');
?>

<section class="toolbar">
    <div>
        <h1>Übersicht</h1>
        <p><?= h($currentEmployee['display_name']) ?></p>
    </div>
    <div class="actions">
        <a class="button primary" href="entry.php">Zeit erfassen</a>
        <a class="button" href="monthly.php?year=<?= $year ?>&month=<?= $month ?>">Monat öffnen</a>
    </div>
</section>

<section class="grid two">
    <?php foreach ($budgetRows as $budget): ?>
        <?php
        $used = (float) $budget['used_days'];
        $total = max(1.0, (float) $budget['budget_days']);
        $percent = min(100, max(0, ($used / $total) * 100));
        $ratio = $used / $total;
        $status = budget_status($ratio);
        $remainingHours = max(0.0, (float) $budget['budget_hours'] - (float) $budget['used_hours']);

        $statusText = 'Im Plan';
        if ($status === 'warning') {
            $statusText = 'Bald ausgeschöpft';
        }
        if ($status === 'critical') {
            $statusText = 'Kontingent ausgeschöpft';
        }
        ?>
        <article class="metric-card budget-card <?= h($status) ?>">
            <div class="metric-title"><?= h($budget['project_name']) ?></div>
            <div class="budget-chip <?= h($status) ?>"><?= h($statusText) ?></div>
            <div class="metric-value"><?= format_hours((float) $budget['remaining_days']) ?> Tage</div>
            <div class="metric-sub"><?= format_hours($remainingHours) ?> Stunden verbleibend</div>
            <div class="metric-sub"><?= format_hours((float) $budget['used_days']) ?> von <?= format_hours((float) $budget['budget_days']) ?> Tagen verwendet (<?= format_hours($percent) ?>%)</div>
            <div class="meter"><span style="width: <?= h((string) $percent) ?>%"></span></div>
        </article>
    <?php endforeach; ?>
</section>

<section class="panel">
    <div class="panel-head">
        <h2>Letzte Einträge</h2>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Datum</th>
                <th>Projekt</th>
                <th>Tätigkeit</th>
                <th class="number">Stunden</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$recentEntries): ?>
                <tr><td colspan="5" class="empty">Noch keine Zeiten erfasst.</td></tr>
            <?php endif; ?>
            <?php foreach ($recentEntries as $entry): ?>
                <tr>
                    <td><?= h(format_date($entry['work_date'])) ?></td>
                    <td><?= h($entry['project_name']) ?></td>
                    <td><?= h($entry['activity']) ?></td>
                    <td class="number"><?= format_hours((float) $entry['total_hours']) ?></td>
                    <td class="row-action"><a href="entry.php?date=<?= h($entry['work_date']) ?>&project_id=<?= (int) $entry['project_id'] ?>">Bearbeiten</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php render_footer(); ?>
