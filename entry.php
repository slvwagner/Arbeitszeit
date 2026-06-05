<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

$projects = get_projects($pdo);
$defaultProjectId = (int) ($projects[0]['id'] ?? 0);
$projectId = filter_input(INPUT_GET, 'project_id', FILTER_VALIDATE_INT) ?: $defaultProjectId;
$date = $_GET['date'] ?? date('Y-m-d');
$employeeId = (int) $currentEmployee['id'];

$saveEntry = static function (PDO $pdo, int $employeeId, int $projectId, string $date, int $breakMinutes, array $segments): void {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        'INSERT INTO work_entries (employee_id, project_id, work_date, break_minutes)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE break_minutes = VALUES(break_minutes), updated_at = current_timestamp()'
    );
    $stmt->execute([$employeeId, $projectId, $date, max(0, $breakMinutes)]);

    $entry = get_entry_for_date($pdo, $employeeId, $projectId, $date);
    $entryId = (int) $entry['id'];

    $pdo->prepare('DELETE FROM work_segments WHERE work_entry_id = ?')->execute([$entryId]);
    $segmentStmt = $pdo->prepare(
        'INSERT INTO work_segments (work_entry_id, segment_no, start_time, end_time) VALUES (?, ?, ?, ?)'
    );
    foreach ($segments as [$segmentNo, $start, $end]) {
        $segmentStmt->execute([$entryId, $segmentNo, $start, $end]);
    }

    $pdo->commit();
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? 'save';

    if ($action === 'delete') {
        $entryId = (int) ($_POST['entry_id'] ?? 0);
        if ($entryId > 0) {
            $stmt = $pdo->prepare('DELETE FROM work_entries WHERE id = ? AND employee_id = ?');
            $stmt->execute([$entryId, $employeeId]);
            flash('Eintrag gelöscht.');
        }
        redirect('entry.php');
    }

    $projectId = (int) ($_POST['project_id'] ?? 0);
    $date = (string) ($_POST['work_date'] ?? date('Y-m-d'));
    $start = trim((string) ($_POST['start_time'] ?? ''));
    $end = trim((string) ($_POST['end_time'] ?? ''));
    $breakMinutes = (int) ($_POST['break_minutes'] ?? 0);

    if ($start === '' || $end === '' || minutes_between($start, $end) <= 0) {
        flash('Bitte Start und Ende korrekt erfassen.', 'error');
        redirect('entry.php?date=' . urlencode($date) . '&project_id=' . $projectId);
    }

    $grossMinutes = minutes_between($start, $end);
    if ($breakMinutes < 0 || $breakMinutes >= $grossMinutes) {
        flash('Pause muss kleiner als die Arbeitsdauer sein.', 'error');
        redirect('entry.php?date=' . urlencode($date) . '&project_id=' . $projectId);
    }

    $validSegments = [[1, $start, $end]];

    $saveEntry($pdo, $employeeId, $projectId, $date, $breakMinutes, $validSegments);

    flash('Arbeitszeit gespeichert.');
    redirect('entry.php?date=' . urlencode($date) . '&project_id=' . $projectId);
}

$entry = get_entry_for_date($pdo, $employeeId, $projectId, $date);
$displayTimes = $entry ? entry_display_times($entry) : ['start' => '', 'end' => '', 'pause_minutes' => 0];
$breakMinutes = (int) $displayTimes['pause_minutes'];

$grossMinutes = minutes_between(
    (string) $displayTimes['start'],
    (string) $displayTimes['end']
);
$netMinutes = max(0, $grossMinutes - $breakMinutes);

render_header('Zeiten erfassen', 'entry');
?>

<section class="toolbar">
    <div>
        <h1>Zeiten erfassen</h1>
        <p><?= h(format_date($date)) ?></p>
    </div>
    <div class="actions">
        <a class="button" href="monthly.php?year=<?= (int) date('Y', strtotime($date)) ?>&month=<?= (int) date('n', strtotime($date)) ?>&project_id=<?= $projectId ?>">Monat</a>
    </div>
</section>

<section class="editor-layout">
    <form class="panel form-panel" method="post" id="entry-form">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="save">

        <div class="form-grid">
            <label>
                Datum
                <input type="date" name="work_date" value="<?= h($date) ?>" required>
            </label>
            <label>
                Projekt
                <select name="project_id" required>
                    <?php foreach ($projects as $project): ?>
                        <option value="<?= (int) $project['id'] ?>" <?= (int) $project['id'] === $projectId ? 'selected' : '' ?>>
                            <?= h($project['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>

        <div class="time-grid">
            <label>
                Start
                <input type="time" name="start_time" value="<?= h((string) $displayTimes['start']) ?>" required>
            </label>
            <label>
                Ende
                <input type="time" name="end_time" value="<?= h((string) $displayTimes['end']) ?>" required>
            </label>
            <label>
                Pause (Minuten)
                <input type="number" min="0" step="5" name="break_minutes" value="<?= $breakMinutes ?>">
            </label>
        </div>

        <div class="form-actions">
            <button class="button primary" type="submit">Speichern</button>
            <?php if ($entry): ?>
                <button class="button danger" type="submit" name="action" value="delete" form="delete-entry">Löschen</button>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($entry): ?>
        <aside class="panel summary-panel">
            <h2>Tagessumme</h2>
            <div class="large-number"><?= format_hours($netMinutes / 60) ?> h</div>
            <p>Brutto: <?= format_hours($grossMinutes / 60) ?> h</p>
            <p>Pause: <?= $breakMinutes ?> Min.</p>
            <p><?= format_hours($netMinutes / 480) ?> Arbeitstage</p>
        </aside>
        <form id="delete-entry" method="post">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="entry_id" value="<?= (int) $entry['id'] ?>">
        </form>
    <?php endif; ?>
</section>

<?php render_footer(); ?>
