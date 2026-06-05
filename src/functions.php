<?php

declare(strict_types=1);

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function csrf_token(): string
{
    return $_SESSION['csrf_token'] ?? '';
}

function verify_csrf(): void
{
    $posted = $_POST['csrf_token'] ?? '';
    if (!is_string($posted) || !hash_equals(csrf_token(), $posted)) {
        http_response_code(400);
        exit('Invalid form token.');
    }
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function consume_flash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($flash) ? $flash : null;
}

function get_default_employee(PDO $pdo): array
{
    $employee = $pdo->query('SELECT * FROM employees WHERE active = 1 ORDER BY id LIMIT 1')->fetch();
    if (!$employee) {
        throw new RuntimeException('No active employee found.');
    }
    return $employee;
}

function get_projects(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM projects WHERE status = "active" ORDER BY name')->fetchAll();
}

function get_project(PDO $pdo, int $projectId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM projects WHERE id = ?');
    $stmt->execute([$projectId]);
    $project = $stmt->fetch();
    return $project ?: null;
}

function month_name(int $month): string
{
    $names = [
        1 => 'Januar',
        2 => 'Februar',
        3 => 'März',
        4 => 'April',
        5 => 'Mai',
        6 => 'Juni',
        7 => 'Juli',
        8 => 'August',
        9 => 'September',
        10 => 'Oktober',
        11 => 'November',
        12 => 'Dezember',
    ];
    return $names[$month] ?? '';
}

function selected_month(): array
{
    $period = filter_input(INPUT_GET, 'period', FILTER_UNSAFE_RAW);
    if (is_string($period) && preg_match('/^(\d{4})-(\d{2})$/', $period, $matches)) {
        $year = (int) $matches[1];
        $month = (int) $matches[2];
        return [$year, max(1, min(12, $month))];
    }

    $year = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT) ?: (int) date('Y');
    $month = filter_input(INPUT_GET, 'month', FILTER_VALIDATE_INT) ?: (int) date('n');
    $month = max(1, min(12, $month));
    return [$year, $month];
}

function minutes_between(?string $start, ?string $end): int
{
    if (!$start || !$end) {
        return 0;
    }

    $startParts = explode(':', $start);
    $endParts = explode(':', $end);
    $startMinutes = ((int) $startParts[0] * 60) + (int) $startParts[1];
    $endMinutes = ((int) $endParts[0] * 60) + (int) $endParts[1];

    return max(0, $endMinutes - $startMinutes);
}

function shift_date(string $date, int $days): string
{
    return date('Y-m-d', strtotime($date . ' ' . ($days >= 0 ? '+' : '') . $days . ' day'));
}

function time_from_minutes(int $minutes): string
{
    $minutes = max(0, min((24 * 60) - 1, $minutes));
    $hours = intdiv($minutes, 60);
    $minutePart = $minutes % 60;
    return sprintf('%02d:%02d', $hours, $minutePart);
}

function segment_from_decimal_hours(float $hours, string $startTime = '08:00'): ?array
{
    if ($hours <= 0) {
        return null;
    }

    $parts = explode(':', $startTime);
    $startMinutes = ((int) ($parts[0] ?? 8) * 60) + (int) ($parts[1] ?? 0);
    $durationMinutes = (int) round($hours * 60);
    if ($durationMinutes <= 0) {
        return null;
    }

    $endMinutes = min((24 * 60) - 1, $startMinutes + $durationMinutes);
    if ($endMinutes <= $startMinutes) {
        return null;
    }

    return [
        'start_time' => time_from_minutes($startMinutes),
        'end_time' => time_from_minutes($endMinutes),
    ];
}

function format_hours(float|int|null $hours): string
{
    return number_format((float) $hours, 2, '.', "'");
}

function format_date(string $date): string
{
    return date('d.m.Y', strtotime($date));
}

function entry_display_times(array $entry): array
{
    $segments = $entry['segments'] ?? [];
    if (!$segments) {
        return [
            'start' => '',
            'end' => '',
            'pause_minutes' => (int) ($entry['break_minutes'] ?? 0),
        ];
    }

    usort($segments, static fn(array $a, array $b): int => ((int) $a['segment_no']) <=> ((int) $b['segment_no']));
    $start = substr((string) ($segments[0]['start_time'] ?? ''), 0, 5);
    $end = substr((string) ($segments[count($segments) - 1]['end_time'] ?? ''), 0, 5);

    $workedMinutes = 0;
    foreach ($segments as $segment) {
        $workedMinutes += minutes_between(
            substr((string) ($segment['start_time'] ?? ''), 0, 5),
            substr((string) ($segment['end_time'] ?? ''), 0, 5)
        );
    }

    $grossMinutes = minutes_between($start, $end);
    $gapPause = max(0, $grossMinutes - $workedMinutes);
    $manualPause = (int) ($entry['break_minutes'] ?? 0);

    return [
        'start' => $start,
        'end' => $end,
        'pause_minutes' => $gapPause + max(0, $manualPause),
    ];
}

function weekday_short(string $date): string
{
    $names = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
    return $names[(int) date('w', strtotime($date))];
}

function get_entry_for_date(PDO $pdo, int $employeeId, int $projectId, string $date): ?array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM work_entries WHERE employee_id = ? AND project_id = ? AND work_date = ?'
    );
    $stmt->execute([$employeeId, $projectId, $date]);
    $entry = $stmt->fetch();
    if (!$entry) {
        return null;
    }

    $segmentStmt = $pdo->prepare(
        'SELECT * FROM work_segments WHERE work_entry_id = ? ORDER BY segment_no'
    );
    $segmentStmt->execute([(int) $entry['id']]);
    $entry['segments'] = $segmentStmt->fetchAll();

    return $entry;
}

function get_last_entry_for_project(PDO $pdo, int $employeeId, int $projectId, string $beforeDate): ?array
{
    $stmt = $pdo->prepare(
        'SELECT *
         FROM work_entries
         WHERE employee_id = ? AND project_id = ? AND work_date < ?
         ORDER BY work_date DESC
         LIMIT 1'
    );
    $stmt->execute([$employeeId, $projectId, $beforeDate]);
    $entry = $stmt->fetch();
    if (!$entry) {
        return null;
    }

    $segmentStmt = $pdo->prepare(
        'SELECT * FROM work_segments WHERE work_entry_id = ? ORDER BY segment_no'
    );
    $segmentStmt->execute([(int) $entry['id']]);
    $entry['segments'] = $segmentStmt->fetchAll();

    return $entry;
}

function get_month_entries(PDO $pdo, int $employeeId, int $projectId, int $year, int $month): array
{
    $start = sprintf('%04d-%02d-01', $year, $month);
    $end = date('Y-m-t', strtotime($start));
    $stmt = $pdo->prepare(
        'SELECT we.*, wet.total_hours, wet.total_days
         FROM work_entries we
         LEFT JOIN v_work_entry_totals wet ON wet.work_entry_id = we.id
         WHERE we.employee_id = ? AND we.project_id = ? AND we.work_date BETWEEN ? AND ?
         ORDER BY we.work_date'
    );
    $stmt->execute([$employeeId, $projectId, $start, $end]);

    $entries = [];
    foreach ($stmt->fetchAll() as $entry) {
        $segmentStmt = $pdo->prepare(
            'SELECT * FROM work_segments WHERE work_entry_id = ? ORDER BY segment_no'
        );
        $segmentStmt->execute([(int) $entry['id']]);
        $entry['segments'] = $segmentStmt->fetchAll();
        $entries[$entry['work_date']] = $entry;
    }

    return $entries;
}

function get_month_total(PDO $pdo, int $employeeId, int $projectId, int $year, int $month): array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM v_monthly_project_totals
         WHERE employee_id = ? AND project_id = ? AND report_year = ? AND report_month = ?'
    );
    $stmt->execute([$employeeId, $projectId, $year, $month]);
    return $stmt->fetch() ?: [
        'total_hours' => 0,
        'total_days' => 0,
        'worked_dates' => 0,
    ];
}

function get_project_total(PDO $pdo, int $employeeId, int $projectId): array
{
    $stmt = $pdo->prepare(
        'SELECT
            ROUND(COALESCE(SUM(total_hours), 0), 2) AS total_hours,
            ROUND(COALESCE(SUM(total_days), 0), 2) AS total_days,
            COUNT(CASE WHEN total_hours > 0 THEN 1 END) AS worked_dates
         FROM v_work_entry_totals
         WHERE employee_id = ? AND project_id = ?'
    );
    $stmt->execute([$employeeId, $projectId]);
    return $stmt->fetch() ?: [
        'total_hours' => 0,
        'total_days' => 0,
        'worked_dates' => 0,
    ];
}

function get_monthly_report(PDO $pdo, int $employeeId, int $projectId, int $year, int $month): array
{
    $stmt = $pdo->prepare(
        'SELECT mr.*, a.display_name AS approver_name
         FROM monthly_reports mr
         LEFT JOIN approvers a ON a.id = mr.approved_by
         WHERE mr.employee_id = ? AND mr.project_id = ? AND mr.report_year = ? AND mr.report_month = ?'
    );
    $stmt->execute([$employeeId, $projectId, $year, $month]);
    return $stmt->fetch() ?: [
        'approval_status' => 'draft',
        'invoice_status' => 'not_ready',
        'approved_by' => null,
        'signature_name' => null,
        'invoice_reference' => null,
        'submitted_at' => null,
        'approved_at' => null,
        'approver_name' => null,
    ];
}

function get_approvers(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM approvers WHERE active = 1 ORDER BY display_name')->fetchAll();
}

function status_label(string $status): string
{
    return [
        'draft' => 'Entwurf',
        'submitted' => 'Eingereicht',
        'approved' => 'Visiert',
        'rejected' => 'Abgelehnt',
        'not_ready' => 'Nicht bereit',
        'ready' => 'Bereit',
        'paid' => 'Bezahlt',
    ][$status] ?? $status;
}

function budget_status(float $ratio): string
{
    if ($ratio >= 1.0) {
        return 'critical';
    }
    if ($ratio >= 0.85) {
        return 'warning';
    }
    return 'healthy';
}

function week_start_from_date(string $date): string
{
    return date('Y-m-d', strtotime($date . ' monday this week'));
}

function week_end_from_start(string $startDate): string
{
    return date('Y-m-d', strtotime($startDate . ' +6 day'));
}

function iso_week_label(string $date): string
{
    return date('W/Y', strtotime($date));
}

function summarize_weeks_from_entries(array $entries): array
{
    $weeks = [];
    foreach ($entries as $date => $entry) {
        $start = week_start_from_date($date);
        if (!isset($weeks[$start])) {
            $weeks[$start] = [
                'start' => $start,
                'end' => week_end_from_start($start),
                'label' => iso_week_label($start),
                'hours' => 0.0,
                'days' => 0.0,
                'entries' => [],
            ];
        }

        $hours = (float) ($entry['total_hours'] ?? 0);
        $days = (float) ($entry['total_days'] ?? 0);
        $weeks[$start]['hours'] += $hours;
        $weeks[$start]['days'] += $days;
        $weeks[$start]['entries'][$date] = $entry;
    }

    ksort($weeks);
    return $weeks;
}

function get_weekly_tasks_for_period(PDO $pdo, int $employeeId, int $projectId, string $startDate, string $endDate): array
{
    $stmt = $pdo->prepare(
        'SELECT *
         FROM weekly_tasks
         WHERE employee_id = ? AND project_id = ? AND week_start BETWEEN ? AND ?
         ORDER BY week_start'
    );
    $stmt->execute([$employeeId, $projectId, $startDate, $endDate]);

    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[$row['week_start']] = $row;
    }
    return $rows;
}

function upsert_weekly_task(PDO $pdo, int $employeeId, int $projectId, string $weekStart, string $summary): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO weekly_tasks (employee_id, project_id, week_start, summary)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            summary = VALUES(summary),
            updated_at = current_timestamp()'
    );
    $stmt->execute([$employeeId, $projectId, $weekStart, $summary !== '' ? $summary : null]);
}

function render_header(string $title, string $active = ''): void
{
    $flash = consume_flash();
    ?>
    <!doctype html>
    <html lang="de">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= h($title) ?> · Arbeitszeit</title>
        <link rel="stylesheet" href="assets/app.css">
    </head>
    <body>
    <header class="topbar">
        <a class="brand" href="index.php">Arbeitszeit</a>
        <nav class="nav">
            <a class="<?= $active === 'dashboard' ? 'active' : '' ?>" href="index.php">Übersicht</a>
            <a class="<?= $active === 'monthly' ? 'active' : '' ?>" href="monthly.php">Monat</a>
            <a class="<?= $active === 'projects' ? 'active' : '' ?>" href="projekte.php">Projekte</a>
        </nav>
    </header>
    <main class="page">
        <?php if ($flash): ?>
            <div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
        <?php endif; ?>
    <?php
}

function render_footer(): void
{
    ?>
    </main>
    </body>
    </html>
    <?php
}
