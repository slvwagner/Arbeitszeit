<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

$projects = get_projects($pdo);
$defaultProjectId = (int) ($projects[0]['id'] ?? 0);
[$year, $month] = selected_month();
$projectId = filter_input(INPUT_GET, 'project_id', FILTER_VALIDATE_INT) ?: $defaultProjectId;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $projectId = (int) ($_POST['project_id'] ?? $projectId);
    $year = (int) ($_POST['year'] ?? $year);
    $month = (int) ($_POST['month'] ?? $month);
    $approvalStatus = (string) ($_POST['approval_status'] ?? 'draft');
    $invoiceStatus = (string) ($_POST['invoice_status'] ?? 'not_ready');
    $approvedBy = ($_POST['approved_by'] ?? '') !== '' ? (int) $_POST['approved_by'] : null;
    $signatureName = trim((string) ($_POST['signature_name'] ?? ''));
    $invoiceReference = trim((string) ($_POST['invoice_reference'] ?? ''));

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
        (int) $currentEmployee['id'],
        $projectId,
        $year,
        $month,
        month_name($month) . ' ' . $year,
        $approvalStatus,
        $invoiceStatus,
        $approvedBy,
        $signatureName !== '' ? $signatureName : null,
        $invoiceReference !== '' ? $invoiceReference : null,
        in_array($approvalStatus, ['submitted', 'approved'], true) ? date('Y-m-d H:i:s') : null,
        $approvalStatus === 'approved' ? date('Y-m-d H:i:s') : null,
    ]);

    flash('Monatsadministration gespeichert.');
    redirect('monthly.php?year=' . $year . '&month=' . $month . '&project_id=' . $projectId);
}

$project = get_project($pdo, $projectId);
$entries = get_month_entries($pdo, (int) $currentEmployee['id'], $projectId, $year, $month);
$monthTotal = get_month_total($pdo, (int) $currentEmployee['id'], $projectId, $year, $month);
$report = get_monthly_report($pdo, (int) $currentEmployee['id'], $projectId, $year, $month);
$approvers = get_approvers($pdo);

$firstDay = sprintf('%04d-%02d-01', $year, $month);
$daysInMonth = (int) date('t', strtotime($firstDay));
$prev = date('Y-n', strtotime($firstDay . ' -1 month'));
$next = date('Y-n', strtotime($firstDay . ' +1 month'));
[$prevYear, $prevMonth] = array_map('intval', explode('-', $prev));
[$nextYear, $nextMonth] = array_map('intval', explode('-', $next));

render_header('Monatsadministration', 'monthly');
?>

<section class="toolbar">
    <div>
        <h1><?= h(month_name($month)) ?> <?= $year ?></h1>
        <p><?= h($project['name'] ?? '') ?></p>
    </div>
    <div class="actions">
        <a class="icon-button" title="Vorheriger Monat" href="monthly.php?year=<?= $prevYear ?>&month=<?= $prevMonth ?>&project_id=<?= $projectId ?>">‹</a>
        <a class="icon-button" title="Nächster Monat" href="monthly.php?year=<?= $nextYear ?>&month=<?= $nextMonth ?>&project_id=<?= $projectId ?>">›</a>
        <a class="button" href="report.php?year=<?= $year ?>&month=<?= $month ?>&project_id=<?= $projectId ?>" target="_blank">Drucken</a>
    </div>
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
    <button class="button" type="submit">Öffnen</button>
</form>

<section class="grid three">
    <article class="metric-card">
        <div class="metric-title">Stunden</div>
        <div class="metric-value"><?= format_hours((float) $monthTotal['total_hours']) ?></div>
        <div class="metric-sub"><?= (int) $monthTotal['worked_dates'] ?> Arbeitstage mit Eintrag</div>
    </article>
    <article class="metric-card">
        <div class="metric-title">Arbeitstage</div>
        <div class="metric-value"><?= format_hours((float) $monthTotal['total_days']) ?></div>
        <div class="metric-sub">Basis <?= format_hours((float) ($project['default_hours_per_day'] ?? 8)) ?> h pro Tag</div>
    </article>
    <article class="metric-card">
        <div class="metric-title">Status</div>
        <div class="metric-value compact"><?= h(status_label((string) $report['approval_status'])) ?></div>
        <div class="metric-sub">Rechnung: <?= h(status_label((string) $report['invoice_status'])) ?></div>
    </article>
</section>

<section class="panel">
    <div class="panel-head">
        <h2>Arbeitstage</h2>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Tag</th>
                <th>Datum</th>
                <th>Zeiten</th>
                <th>Tätigkeit</th>
                <th class="number">Stunden</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php for ($day = 1; $day <= $daysInMonth; $day++): ?>
                <?php
                $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
                $entry = $entries[$date] ?? null;
                $segments = $entry['segments'] ?? [];
                $timeText = [];
                foreach ($segments as $segment) {
                    $timeText[] = substr((string) $segment['start_time'], 0, 5) . '–' . substr((string) $segment['end_time'], 0, 5);
                }
                $isWeekend = in_array((int) date('w', strtotime($date)), [0, 6], true);
                ?>
                <tr class="<?= $isWeekend ? 'weekend' : '' ?>">
                    <td><?= h(weekday_short($date)) ?></td>
                    <td><?= h(format_date($date)) ?></td>
                    <td><?= h(implode(' / ', $timeText)) ?></td>
                    <td><?= h($entry['activity'] ?? '') ?></td>
                    <td class="number"><?= format_hours((float) ($entry['total_hours'] ?? 0)) ?></td>
                    <td class="row-action">
                        <a href="entry.php?date=<?= h($date) ?>&project_id=<?= $projectId ?>"><?= $entry ? 'Bearbeiten' : 'Erfassen' ?></a>
                    </td>
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
                    <?php foreach (['draft', 'submitted', 'approved', 'rejected'] as $status): ?>
                        <option value="<?= h($status) ?>" <?= $report['approval_status'] === $status ? 'selected' : '' ?>><?= h(status_label($status)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Rechnung
                <select name="invoice_status">
                    <?php foreach (['not_ready', 'ready', 'submitted', 'paid'] as $status): ?>
                        <option value="<?= h($status) ?>" <?= $report['invoice_status'] === $status ? 'selected' : '' ?>><?= h(status_label($status)) ?></option>
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
                <input type="text" name="invoice_reference" value="<?= h($report['invoice_reference']) ?>">
            </label>
        </div>

        <div class="form-actions">
            <button class="button primary" type="submit">Speichern</button>
        </div>
    </form>
</section>

<?php render_footer(); ?>
