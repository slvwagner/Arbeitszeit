<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

$projects = get_projects($pdo);
$defaultProjectId = (int) ($projects[0]['id'] ?? 0);
[$year, $month] = selected_month();
$projectId = filter_input(INPUT_GET, 'project_id', FILTER_VALIDATE_INT) ?: $defaultProjectId;
$employeeId = (int) $currentEmployee['id'];
$project = get_project($pdo, $projectId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? 'save_admin');
    $projectId = (int) ($_POST['project_id'] ?? $projectId);
    $year = (int) ($_POST['year'] ?? $year);
    $month = (int) ($_POST['month'] ?? $month);
    $project = get_project($pdo, $projectId);

    if ($action === 'save_weekly_task') {
        $weekStart = (string) ($_POST['week_start'] ?? '');
        $summary = trim((string) ($_POST['weekly_summary'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekStart) === 1) {
            upsert_weekly_task($pdo, $employeeId, $projectId, $weekStart, $summary);
            flash('Wöchentliche Aufgaben gespeichert.');
        }
        redirect('monthly.php?year=' . $year . '&month=' . $month . '&project_id=' . $projectId);
    }

    $approvalStatus = (string) ($_POST['approval_status'] ?? 'draft');
    if (!in_array($approvalStatus, ['draft', 'approved'], true)) {
        $approvalStatus = 'draft';
    }
    $approvedBy = ($_POST['approved_by'] ?? '') !== '' ? (int) $_POST['approved_by'] : null;
    $signatureName = trim((string) ($_POST['signature_name'] ?? ''));
    $invoiceReference = trim((string) ($_POST['invoice_reference_default'] ?? (string) ($project['invoice_reference_default'] ?? '')));

    $updateProject = $pdo->prepare('UPDATE projects SET invoice_reference_default = ? WHERE id = ?');
    $updateProject->execute([$invoiceReference !== '' ? $invoiceReference : null, $projectId]);
    $project['invoice_reference_default'] = $invoiceReference;

    $stmt = $pdo->prepare(
        'INSERT INTO monthly_reports
            (employee_id, project_id, report_year, report_month, report_title, approval_status, invoice_status, approved_by, signature_name, invoice_reference, submitted_at, approved_at)
         VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            approval_status = VALUES(approval_status),
            invoice_status = VALUES(invoice_status),
            approved_by = VALUES(approved_by),
            signature_name = VALUES(signature_name),
            invoice_reference = VALUES(invoice_reference),
            submitted_at = VALUES(submitted_at),
            approved_at = VALUES(approved_at),
            updated_at = current_timestamp()'
    );
    $stmt->execute([
        $employeeId,
        $projectId,
        $year,
        $month,
        month_name($month) . ' ' . $year,
        $approvalStatus,
        'not_ready',
        $approvedBy,
        $signatureName !== '' ? $signatureName : null,
        $invoiceReference !== '' ? $invoiceReference : null,
        $approvalStatus === 'approved' ? date('Y-m-d H:i:s') : null,
        $approvalStatus === 'approved' ? date('Y-m-d H:i:s') : null,
    ]);

    flash('Monatsadministration gespeichert.');
    redirect('monthly.php?year=' . $year . '&month=' . $month . '&project_id=' . $projectId);
}

$entries = get_month_entries($pdo, $employeeId, $projectId, $year, $month);
$monthTotal = get_month_total($pdo, $employeeId, $projectId, $year, $month);
$projectTotal = get_project_total($pdo, $employeeId, $projectId);
$report = get_monthly_report($pdo, $employeeId, $projectId, $year, $month);
$approvers = get_approvers($pdo);

$firstDay = sprintf('%04d-%02d-01', $year, $month);
$daysInMonth = (int) date('t', strtotime($firstDay));
$lastDay = date('Y-m-t', strtotime($firstDay));
$prev = date('Y-n', strtotime($firstDay . ' -1 month'));
$next = date('Y-n', strtotime($firstDay . ' +1 month'));
[$prevYear, $prevMonth] = array_map('intval', explode('-', $prev));
[$nextYear, $nextMonth] = array_map('intval', explode('-', $next));

$weekStartCursor = week_start_from_date($firstDay);
$weekStartEnd = week_start_from_date($lastDay);
$weeklyTasks = get_weekly_tasks_for_period($pdo, $employeeId, $projectId, $weekStartCursor, $weekStartEnd);

render_header('Monatsadministration', 'monthly');
?>

<section class="toolbar">
    <div>
        <h1>Monatsadministration</h1>
        <p><?= h($currentEmployee['display_name']) ?></p>
    </div>
</section>

<section class="grid three">
    <article class="metric-card">
        <div class="metric-title">Stunden im Monat</div>
        <div class="metric-value"><?= format_hours((float) $monthTotal['total_hours']) ?></div>
        <div class="metric-sub">Projekt gesamt: <?= format_hours((float) $projectTotal['total_hours']) ?> h</div>
    </article>
    <article class="metric-card">
        <div class="metric-title">Arbeitstage im Monat</div>
        <div class="metric-value"><?= format_hours((float) $monthTotal['total_days']) ?></div>
        <div class="metric-sub">Projekt gesamt: <?= format_hours((float) $projectTotal['total_days']) ?> Tage</div>
    </article>
    <article class="metric-card">
        <div class="metric-title">Status</div>
        <div class="metric-value compact"><?= h(status_label((string) $report['approval_status'])) ?></div>
        <div class="metric-sub">Rechnungsreferenz: <?= h((string) ($project['invoice_reference_default'] ?? '-')) ?></div>
    </article>
</section>

<form class="filter-bar" method="get">
    <label>
        Monat
        <input type="month" name="period" value="<?= sprintf('%04d-%02d', $year, $month) ?>">
    </label>
    <label>
        Projekt
        <select name="project_id" onchange="this.form.submit()">
            <?php foreach ($projects as $item): ?>
                <option value="<?= (int) $item['id'] ?>" <?= (int) $item['id'] === $projectId ? 'selected' : '' ?>><?= h($item['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <a class="icon-button" title="Vorheriger Monat" href="monthly.php?year=<?= $prevYear ?>&month=<?= $prevMonth ?>&project_id=<?= $projectId ?>">‹</a>
    <a class="icon-button" title="Nächster Monat" href="monthly.php?year=<?= $nextYear ?>&month=<?= $nextMonth ?>&project_id=<?= $projectId ?>">›</a>
    <a class="button" href="report.php?scope=month&year=<?= $year ?>&month=<?= $month ?>&project_id=<?= $projectId ?>&signature_name=<?= urlencode((string) ($report['signature_name'] ?: $report['approver_name'] ?: 'Demo Approver')) ?>" target="_blank">PDF Monat</a>
</form>

<section class="panel">
    <div class="panel-head">
        <h2>Arbeitstage · <?= h(month_name($month)) ?> <?= $year ?> · <?= h((string) ($project['name'] ?? '')) ?></h2>
        <a class="button light-purple" href="monat_bearbeiten.php?year=<?= $year ?>&month=<?= $month ?>&project_id=<?= $projectId ?>">Monat bearbeiten</a>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Tag</th>
                <th>Datum</th>
                <th>Start</th>
                <th>Ende</th>
                <th>Pause</th>
                <th class="number">Stunden</th>
            </tr>
            </thead>
            <tbody>
            <?php for ($day = 1; $day <= $daysInMonth; $day++): ?>
                <?php
                $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
                $entry = $entries[$date] ?? null;
                $displayTimes = $entry ? entry_display_times($entry) : ['start' => '', 'end' => '', 'pause_minutes' => 0];
                $isWeekend = in_array((int) date('w', strtotime($date)), [0, 6], true);
                $isMonday = date('N', strtotime($date)) === '1';
                $weekStart = week_start_from_date($date);
                $weekEnd = week_end_from_start($weekStart);
                $weekTask = (string) ($weeklyTasks[$weekStart]['summary'] ?? '-');
                ?>
                <?php if ($isMonday): ?>
                    <tr class="week-header-row">
                        <td colspan="6"><strong>Woche <?= h(iso_week_label($weekStart)) ?></strong> - <?= h(format_date($weekStart)) ?> bis <?= h(format_date($weekEnd)) ?></td>
                    </tr>
                    <tr class="week-task-row">
                        <td colspan="6"><strong>Wochenaufgaben:</strong> <?= h($weekTask) ?></td>
                    </tr>
                <?php endif; ?>
                <tr class="<?= $isWeekend ? 'weekend' : '' ?>">
                    <td><?= h(weekday_short($date)) ?></td>
                    <td><?= h(format_date($date)) ?></td>
                    <td><?= h((string) $displayTimes['start']) ?></td>
                    <td><?= h((string) $displayTimes['end']) ?></td>
                    <td><?= (int) $displayTimes['pause_minutes'] ?> Min.</td>
                    <td class="number"><?= format_hours((float) ($entry['total_hours'] ?? 0)) ?></td>
                </tr>
            <?php endfor; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel">
    <div class="panel-head">
        <h2>Administration</h2>
    </div>
    <form class="admin-form" method="post">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="year" value="<?= $year ?>">
        <input type="hidden" name="month" value="<?= $month ?>">
        <input type="hidden" name="project_id" value="<?= $projectId ?>">

        <div class="form-grid">
            <label>
                Arbeitsnachweis
                <select name="approval_status">
                    <?php foreach (['draft', 'approved'] as $status): ?>
                        <option value="<?= h($status) ?>" <?= $report['approval_status'] === $status ? 'selected' : '' ?>><?= h(status_label($status)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Visierung durch
                <select name="approved_by">
                    <option value="">-</option>
                    <?php foreach ($approvers as $approver): ?>
                        <option value="<?= (int) $approver['id'] ?>" <?= (int) ($report['approved_by'] ?? 0) === (int) $approver['id'] ? 'selected' : '' ?>><?= h($approver['display_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Signatur
                <input type="text" name="signature_name" value="<?= h($report['signature_name']) ?>">
            </label>
            <label>
                Rechnungsreferenz
                <input type="text" name="invoice_reference_default" value="<?= h((string) ($project['invoice_reference_default'] ?? '')) ?>">
            </label>
        </div>

        <div class="form-actions">
            <input type="hidden" name="action" value="save_admin">
            <button class="button primary" type="submit">Speichern</button>
        </div>
    </form>
</section>

<?php render_footer(); ?>
