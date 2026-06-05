<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create_project') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $code = trim((string) ($_POST['code'] ?? ''));
        $budgetDays = (float) ($_POST['budget_days'] ?? 0);
        $hoursPerDay = (float) ($_POST['default_hours_per_day'] ?? 8);
        $invoiceRef = trim((string) ($_POST['invoice_reference_default'] ?? ''));

        if ($name === '') {
            flash('Projektname ist erforderlich.', 'error');
            redirect('index.php');
        }

        if ($code === '') {
            $code = strtolower(preg_replace('/[^a-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT', $name) ?: $name));
            $code = trim($code, '-');
            if ($code === '') {
                $code = 'projekt-' . date('YmdHis');
            }
        }

        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            'INSERT INTO projects (code, name, default_hours_per_day, invoice_reference_default, status)
             VALUES (?, ?, ?, ?, "active")'
        );
        $stmt->execute([$code, $name, max(1.0, $hoursPerDay), $invoiceRef !== '' ? $invoiceRef : null]);
        $projectId = (int) $pdo->lastInsertId();

        if ($budgetDays > 0) {
            $budgetStmt = $pdo->prepare(
                'INSERT INTO project_budgets (project_id, employee_id, label, budget_days, hours_per_day, valid_from)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $budgetStmt->execute([
                $projectId,
                (int) $currentEmployee['id'],
                'Gesamtkontingent',
                $budgetDays,
                max(1.0, $hoursPerDay),
                date('Y-m-d'),
            ]);
        }

        $pdo->commit();
        flash('Projekt wurde erstellt.');
        redirect('index.php');
    }
}

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

render_header('Übersicht', 'dashboard');
?>

<section class="toolbar">
    <div>
        <h1>Übersicht</h1>
        <p><?= h($currentEmployee['display_name']) ?></p>
    </div>
    <div class="actions">
        <a class="button primary" href="entry.php">Zeit erfassen</a>
        <a class="button primary" href="monthly.php?year=<?= $year ?>&month=<?= $month ?>">Monat öffnen</a>
    </div>
</section>

<section class="panel form-panel">
    <h2>Projekt hinzufügen</h2>
    <form class="form-grid" method="post">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="create_project">

        <label>
            Projektname
            <input type="text" name="name" required>
        </label>
        <label>
            Kürzel (optional)
            <input type="text" name="code" placeholder="z. B. projekt-2026">
        </label>
        <label>
            Kontingent (Arbeitstage)
            <input type="number" name="budget_days" step="0.25" min="0" placeholder="z. B. 60">
        </label>
        <label>
            Stunden pro Arbeitstag
            <input type="number" name="default_hours_per_day" step="0.25" min="1" value="8">
        </label>
        <label>
            Rechnungsreferenz (Projekt)
            <input type="text" name="invoice_reference_default" placeholder="z. B. INV-ABC-2026">
        </label>
        <div class="form-actions">
            <button class="button primary" type="submit">Projekt speichern</button>
        </div>
    </form>
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
