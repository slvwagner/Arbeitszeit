<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

$projects = get_projects($pdo);
$defaultProjectId = (int) ($projects[0]['id'] ?? 0);
$projectId = filter_input(INPUT_GET, 'project_id', FILTER_VALIDATE_INT) ?: $defaultProjectId;
$date = $_GET['date'] ?? date('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? 'save';

    if ($action === 'delete') {
        $entryId = (int) ($_POST['entry_id'] ?? 0);
        if ($entryId > 0) {
            $stmt = $pdo->prepare('DELETE FROM work_entries WHERE id = ? AND employee_id = ?');
            $stmt->execute([$entryId, (int) $currentEmployee['id']]);
            flash('Eintrag gelöscht.');
        }
        redirect('entry.php');
    }

    $projectId = (int) ($_POST['project_id'] ?? 0);
    $date = (string) ($_POST['work_date'] ?? date('Y-m-d'));
    $activity = trim((string) ($_POST['activity'] ?? ''));
    $segments = [
        [trim((string) ($_POST['start_time_1'] ?? '')), trim((string) ($_POST['end_time_1'] ?? ''))],
        [trim((string) ($_POST['start_time_2'] ?? '')), trim((string) ($_POST['end_time_2'] ?? ''))],
    ];

    $validSegments = [];
    foreach ($segments as $index => [$start, $end]) {
        if ($start === '' && $end === '') {
            continue;
        }
        if ($start === '' || $end === '' || minutes_between($start, $end) <= 0) {
            flash('Bitte Start und Ende je Zeitblock korrekt erfassen.', 'error');
            redirect('entry.php?date=' . urlencode($date) . '&project_id=' . $projectId);
        }
        $validSegments[] = [$index + 1, $start, $end];
    }

    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        'INSERT INTO work_entries (employee_id, project_id, work_date, activity)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE activity = VALUES(activity), updated_at = current_timestamp()'
    );
    $stmt->execute([(int) $currentEmployee['id'], $projectId, $date, $activity !== '' ? $activity : null]);

    $entry = get_entry_for_date($pdo, (int) $currentEmployee['id'], $projectId, $date);
    $entryId = (int) $entry['id'];

    $pdo->prepare('DELETE FROM work_segments WHERE work_entry_id = ?')->execute([$entryId]);
    $segmentStmt = $pdo->prepare(
        'INSERT INTO work_segments (work_entry_id, segment_no, start_time, end_time) VALUES (?, ?, ?, ?)'
    );
    foreach ($validSegments as [$segmentNo, $start, $end]) {
        $segmentStmt->execute([$entryId, $segmentNo, $start, $end]);
    }
    $pdo->commit();

    flash('Arbeitszeit gespeichert.');
    redirect('entry.php?date=' . urlencode($date) . '&project_id=' . $projectId);
}

$entry = get_entry_for_date($pdo, (int) $currentEmployee['id'], $projectId, $date);
$segmentValues = [
    1 => ['start_time' => '', 'end_time' => ''],
    2 => ['start_time' => '', 'end_time' => ''],
];
foreach (($entry['segments'] ?? []) as $segment) {
    $segmentValues[(int) $segment['segment_no']] = $segment;
}

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
    <form class="panel form-panel" method="post">
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
                <input type="time" name="start_time_1" value="<?= h(substr((string) $segmentValues[1]['start_time'], 0, 5)) ?>">
            </label>
            <label>
                Ende
                <input type="time" name="end_time_1" value="<?= h(substr((string) $segmentValues[1]['end_time'], 0, 5)) ?>">
            </label>
            <label>
                Start 2
                <input type="time" name="start_time_2" value="<?= h(substr((string) $segmentValues[2]['start_time'], 0, 5)) ?>">
            </label>
            <label>
                Ende 2
                <input type="time" name="end_time_2" value="<?= h(substr((string) $segmentValues[2]['end_time'], 0, 5)) ?>">
            </label>
        </div>

        <label>
            Tätigkeit
            <textarea name="activity" rows="5"><?= h($entry['activity'] ?? '') ?></textarea>
        </label>

        <div class="form-actions">
            <button class="button primary" type="submit">Speichern</button>
            <?php if ($entry): ?>
                <button class="button danger" type="submit" name="action" value="delete" form="delete-entry">Löschen</button>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($entry): ?>
        <?php
        $totalMinutes = 0;
        foreach ($segmentValues as $segment) {
            $totalMinutes += minutes_between(
                substr((string) $segment['start_time'], 0, 5),
                substr((string) $segment['end_time'], 0, 5)
            );
        }
        ?>
        <aside class="panel summary-panel">
            <h2>Tagessumme</h2>
            <div class="large-number"><?= format_hours($totalMinutes / 60) ?> h</div>
            <p><?= format_hours($totalMinutes / 480) ?> Arbeitstage</p>
        </aside>
        <form id="delete-entry" method="post">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="entry_id" value="<?= (int) $entry['id'] ?>">
        </form>
    <?php endif; ?>
</section>

<?php render_footer(); ?>
