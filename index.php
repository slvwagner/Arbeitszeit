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
    'SELECT
        mpt.report_year,
        mpt.report_month,
        p.name AS project_name,
        mpt.project_id,
        mpt.total_hours,
        mpt.total_days,
        COALESCE(mr.approval_status, "draft") AS approval_status,
        COALESCE(mr.invoice_reference, p.invoice_reference_default) AS invoice_reference
     FROM v_monthly_project_totals mpt
     JOIN projects p ON p.id = mpt.project_id
     LEFT JOIN monthly_reports mr
       ON mr.employee_id = mpt.employee_id
      AND mr.project_id = mpt.project_id
      AND mr.report_year = mpt.report_year
      AND mr.report_month = mpt.report_month
     WHERE mpt.employee_id = ?
     ORDER BY mpt.report_year DESC, mpt.report_month DESC, p.name ASC
     LIMIT 12'
);
$recentStmt->execute([(int) $currentEmployee['id']]);
$recentEntries = $recentStmt->fetchAll();

[$year, $month] = selected_month();
$defaultProjectId = $projects ? (int) $projects[0]['id'] : 0;

render_header('Übersicht', 'dashboard');
?>

<section class="toolbar">
    <div>
        <h1>Übersicht</h1>
        <p><?= h($currentEmployee['display_name']) ?></p>
    </div>
    <div class="actions">
        <a class="button primary" href="monat_bearbeiten.php?year=<?= $year ?>&month=<?= $month ?>&project_id=<?= $defaultProjectId ?>">Monat bearbeiten</a>
        <a class="button primary" href="monthly.php?year=<?= $year ?>&month=<?= $month ?>">Monat öffnen</a>
        <a class="icon-button" href="projekte.php#new-project" title="Neues Projekt" aria-label="Neues Projekt">+</a>
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
            <div class="metric-actions">
                <a class="button" href="projekte.php?project_id=<?= (int) $budget['project_id'] ?>">Bearbeiten</a>
            </div>
        </article>
    <?php endforeach; ?>
</section>

<section class="panel">
    <div class="panel-head">
        <h2>Vergangene Monate</h2>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Monat</th>
                <th>Projekt</th>
                <th>Status</th>
                <th>Rechnungsreferenz</th>
                <th class="number">Stunden</th>
                <th class="number">Arbeitstage</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$recentEntries): ?>
                <tr><td colspan="7" class="empty">Noch keine Monatsdaten vorhanden.</td></tr>
            <?php endif; ?>
            <?php foreach ($recentEntries as $entry): ?>
                <tr>
                    <td><?= h(month_name((int) $entry['report_month'])) ?> <?= (int) $entry['report_year'] ?></td>
                    <td><?= h($entry['project_name']) ?></td>
                    <td><?= h(status_label((string) $entry['approval_status'])) ?></td>
                    <td><?= h((string) $entry['invoice_reference']) ?></td>
                    <td class="number"><?= format_hours((float) $entry['total_hours']) ?></td>
                    <td class="number"><?= format_hours((float) $entry['total_days']) ?></td>
                    <td class="row-action"><a href="monthly.php?year=<?= (int) $entry['report_year'] ?>&month=<?= (int) $entry['report_month'] ?>&project_id=<?= (int) $entry['project_id'] ?>">Öffnen</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php render_footer(); ?>
