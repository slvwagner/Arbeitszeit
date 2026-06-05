<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

$projects = get_projects($pdo);
$defaultProjectId = (int) ($projects[0]['id'] ?? 0);
[$year, $month] = selected_month();
$projectId = filter_input(INPUT_GET, 'project_id', FILTER_VALIDATE_INT) ?: $defaultProjectId;
$employeeId = (int) $currentEmployee['id'];
$project = get_project($pdo, $projectId);

$weekStartForMonth = static function (int $year, int $month): array {
    $firstDay = sprintf('%04d-%02d-01', $year, $month);
    $lastDay = date('Y-m-t', strtotime($firstDay));
    return [week_start_from_date($firstDay), week_start_from_date($lastDay)];
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $projectId = (int) ($_POST['project_id'] ?? $projectId);
    $year = (int) ($_POST['year'] ?? $year);
    $month = (int) ($_POST['month'] ?? $month);
    $project = get_project($pdo, $projectId);

    $firstDay = sprintf('%04d-%02d-01', $year, $month);
    $daysInMonth = (int) date('t', strtotime($firstDay));
    $existingEntries = get_month_entries($pdo, $employeeId, $projectId, $year, $month);
    [$weekStartCursor, $weekStartEnd] = $weekStartForMonth($year, $month);

    $startTimes = $_POST['start_time'] ?? [];
    $endTimes = $_POST['end_time'] ?? [];
    $breakMinutesByDate = $_POST['break_minutes'] ?? [];
    $weeklySummaries = $_POST['weekly_summary'] ?? [];

    if (!is_array($startTimes) || !is_array($endTimes) || !is_array($breakMinutesByDate) || !is_array($weeklySummaries)) {
        flash('Ungültige Eingabe.', 'error');
        redirect('monat_bearbeiten.php?year=' . $year . '&month=' . $month . '&project_id=' . $projectId);
    }

    try {
        $pdo->beginTransaction();

        $upsertEntryStmt = $pdo->prepare(
            'INSERT INTO work_entries (employee_id, project_id, work_date, break_minutes, activity)
             VALUES (?, ?, ?, ?, NULL)
             ON DUPLICATE KEY UPDATE
                id = LAST_INSERT_ID(id),
                break_minutes = VALUES(break_minutes),
                updated_at = current_timestamp()'
        );
        $deleteSegmentsStmt = $pdo->prepare('DELETE FROM work_segments WHERE work_entry_id = ?');
        $insertSegmentStmt = $pdo->prepare(
            'INSERT INTO work_segments (work_entry_id, segment_no, start_time, end_time) VALUES (?, 1, ?, ?)'
        );
        $deleteEntryStmt = $pdo->prepare('DELETE FROM work_entries WHERE id = ? AND employee_id = ?');

        for ($day = 1; $day <= $daysInMonth; $day++) {
            $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
            $start = trim((string) ($startTimes[$date] ?? ''));
            $end = trim((string) ($endTimes[$date] ?? ''));
            $breakMinutes = (int) ($breakMinutesByDate[$date] ?? 0);

            if ($start === '' && $end === '') {
                $existing = $existingEntries[$date] ?? null;
                if ($existing) {
                    $deleteEntryStmt->execute([(int) $existing['id'], $employeeId]);
                }
                continue;
            }

            if ($start === '' || $end === '' || minutes_between($start, $end) <= 0) {
                throw new RuntimeException('Ungültige Zeitangabe am ' . format_date($date) . '. Bitte Start und Ende korrekt erfassen.');
            }

            $grossMinutes = minutes_between($start, $end);
            if ($breakMinutes < 0 || $breakMinutes >= $grossMinutes) {
                throw new RuntimeException('Ungültige Pause am ' . format_date($date) . '. Die Pause muss kleiner als die Arbeitszeit sein.');
            }

            $upsertEntryStmt->execute([$employeeId, $projectId, $date, $breakMinutes]);
            $entryId = (int) $pdo->lastInsertId();

            $deleteSegmentsStmt->execute([$entryId]);
            $insertSegmentStmt->execute([$entryId, $start, $end]);
        }

        $cursor = $weekStartCursor;
        while (strtotime($cursor) <= strtotime($weekStartEnd)) {
            $summary = trim((string) ($weeklySummaries[$cursor] ?? ''));
            upsert_weekly_task($pdo, $employeeId, $projectId, $cursor, $summary);
            $cursor = date('Y-m-d', strtotime($cursor . ' +7 day'));
        }

        $pdo->commit();
        flash('Monat erfolgreich aktualisiert.');
        redirect('monthly.php?year=' . $year . '&month=' . $month . '&project_id=' . $projectId);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash($exception->getMessage(), 'error');
        redirect('monat_bearbeiten.php?year=' . $year . '&month=' . $month . '&project_id=' . $projectId);
    }
}

$entries = get_month_entries($pdo, $employeeId, $projectId, $year, $month);
$monthTotal = get_month_total($pdo, $employeeId, $projectId, $year, $month);

$firstDay = sprintf('%04d-%02d-01', $year, $month);
$lastDay = date('Y-m-t', strtotime($firstDay));
$daysInMonth = (int) date('t', strtotime($firstDay));
$prev = date('Y-n', strtotime($firstDay . ' -1 month'));
$next = date('Y-n', strtotime($firstDay . ' +1 month'));
[$prevYear, $prevMonth] = array_map('intval', explode('-', $prev));
[$nextYear, $nextMonth] = array_map('intval', explode('-', $next));

$weekStartCursor = week_start_from_date($firstDay);
$weekStartEnd = week_start_from_date($lastDay);
$weeklyTasks = get_weekly_tasks_for_period($pdo, $employeeId, $projectId, $weekStartCursor, $weekStartEnd);

render_header('Monat bearbeiten', 'monthly');
?>

<section class="toolbar">
    <div>
        <h1>Monat bearbeiten</h1>
        <p><?= h((string) ($project['name'] ?? '')) ?> · <?= h(month_name($month)) ?> <?= $year ?></p>
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
    <a class="icon-button" title="Vorheriger Monat" href="monat_bearbeiten.php?year=<?= $prevYear ?>&month=<?= $prevMonth ?>&project_id=<?= $projectId ?>">‹</a>
    <a class="icon-button" title="Nächster Monat" href="monat_bearbeiten.php?year=<?= $nextYear ?>&month=<?= $nextMonth ?>&project_id=<?= $projectId ?>">›</a>
</form>

<section class="panel">
    <div class="panel-head">
        <h2>Mehrfachbearbeitung</h2>
        <div class="actions">
            <span class="metric-sub">Monatssumme: <?= format_hours((float) $monthTotal['total_hours']) ?> h</span>
            <a class="button light-purple" href="monthly.php?year=<?= $year ?>&month=<?= $month ?>&project_id=<?= $projectId ?>">Ohne Speichern verlassen</a>
        </div>
    </div>
    <form method="post" class="table-wrap">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="year" value="<?= $year ?>">
        <input type="hidden" name="month" value="<?= $month ?>">
        <input type="hidden" name="project_id" value="<?= $projectId ?>">

        <table>
            <thead>
            <tr>
                <th>Tag</th>
                <th>Datum</th>
                <th>Start</th>
                <th>Ende</th>
                <th>Pause (Min.)</th>
                <th class="number">Stunden</th>
            </tr>
            </thead>
            <tbody>
            <?php for ($day = 1; $day <= $daysInMonth; $day++): ?>
                <?php
                $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
                $entry = $entries[$date] ?? null;
                $display = $entry ? entry_display_times($entry) : ['start' => '', 'end' => '', 'pause_minutes' => 0];
                $hours = (float) ($entry['total_hours'] ?? 0);
                $isWeekend = in_array((int) date('w', strtotime($date)), [0, 6], true);
                $isMonday = date('N', strtotime($date)) === '1';
                $weekStart = week_start_from_date($date);
                $weekEnd = week_end_from_start($weekStart);
                $weekTask = (string) ($weeklyTasks[$weekStart]['summary'] ?? '');
                ?>
                <?php if ($isMonday): ?>
                    <tr class="week-header-row">
                        <td colspan="6"><strong>Woche <?= h(iso_week_label($weekStart)) ?></strong> - <?= h(format_date($weekStart)) ?> bis <?= h(format_date($weekEnd)) ?></td>
                    </tr>
                    <tr class="week-task-row">
                        <td colspan="6">
                            <label>
                                Wochenaufgaben
                                <textarea name="weekly_summary[<?= h($weekStart) ?>]" rows="2" placeholder="Wochenaufgaben erfassen"><?= h($weekTask) ?></textarea>
                            </label>
                        </td>
                    </tr>
                <?php endif; ?>
                <tr class="<?= $isWeekend ? 'weekend' : '' ?>">
                    <td><?= h(weekday_short($date)) ?></td>
                    <td><?= h(format_date($date)) ?></td>
                    <td><input type="time" name="start_time[<?= h($date) ?>]" value="<?= h((string) $display['start']) ?>"></td>
                    <td><input type="time" name="end_time[<?= h($date) ?>]" value="<?= h((string) $display['end']) ?>"></td>
                    <td><input type="number" min="0" step="5" name="break_minutes[<?= h($date) ?>]" value="<?= (int) $display['pause_minutes'] ?>"></td>
                    <td class="number"><?= format_hours($hours) ?></td>
                </tr>
            <?php endfor; ?>
            </tbody>
        </table>

        <div class="form-actions bulk-actions">
            <button class="button primary" type="submit">Alle Änderungen speichern</button>
        </div>
    </form>
</section>

<?php render_footer(); ?>
