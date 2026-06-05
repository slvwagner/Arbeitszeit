<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

$projects = get_projects($pdo);
$defaultProjectId = (int) ($projects[0]['id'] ?? 0);
$projectId = filter_input(INPUT_GET, 'project_id', FILTER_VALIDATE_INT) ?: $defaultProjectId;
$date = $_GET['date'] ?? date('Y-m-d');
$employeeId = (int) $currentEmployee['id'];

$saveEntry = static function (PDO $pdo, int $employeeId, int $projectId, string $date, string $activity, array $segments): void {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        'INSERT INTO work_entries (employee_id, project_id, work_date, activity)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE activity = VALUES(activity), updated_at = current_timestamp()'
    );
    $stmt->execute([$employeeId, $projectId, $date, $activity !== '' ? $activity : null]);

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

    if ($action === 'quick_add') {
        $projectId = (int) ($_POST['quick_project_id'] ?? 0);
        $date = (string) ($_POST['quick_work_date'] ?? date('Y-m-d'));
        $hours = (float) ($_POST['quick_hours'] ?? 0);
        $activity = trim((string) ($_POST['quick_activity'] ?? ''));
        $startTime = trim((string) ($_POST['quick_start_time'] ?? '08:00'));

        $segment = segment_from_decimal_hours($hours, $startTime);
        if (!$segment || $projectId <= 0) {
            flash('Quick Add: Bitte Projekt und Stunden korrekt erfassen.', 'error');
            redirect('entry.php?date=' . urlencode($date) . '&project_id=' . $projectId);
        }

        $saveEntry(
            $pdo,
            $employeeId,
            $projectId,
            $date,
            $activity,
            [[1, $segment['start_time'], $segment['end_time']]]
        );

        flash('Eintrag per Quick Add gespeichert.');
        redirect('entry.php?date=' . urlencode($date) . '&project_id=' . $projectId);
    }

    if ($action === 'copy_yesterday' || $action === 'copy_last_project') {
        $projectId = (int) ($_POST['project_id'] ?? 0);
        $date = (string) ($_POST['work_date'] ?? date('Y-m-d'));

        $sourceEntry = null;
        if ($action === 'copy_yesterday') {
            $sourceEntry = get_entry_for_date($pdo, $employeeId, $projectId, shift_date($date, -1));
        }
        if ($action === 'copy_last_project') {
            $sourceEntry = get_last_entry_for_project($pdo, $employeeId, $projectId, $date);
        }

        if (!$sourceEntry) {
            flash('Keine passende Vorlage zum Kopieren gefunden.', 'error');
            redirect('entry.php?date=' . urlencode($date) . '&project_id=' . $projectId);
        }

        $segments = [];
        foreach (($sourceEntry['segments'] ?? []) as $segment) {
            $segments[] = [
                (int) $segment['segment_no'],
                substr((string) $segment['start_time'], 0, 5),
                substr((string) $segment['end_time'], 0, 5),
            ];
        }

        $saveEntry(
            $pdo,
            $employeeId,
            $projectId,
            $date,
            trim((string) ($sourceEntry['activity'] ?? '')),
            $segments
        );

        flash('Eintrag aus Vorlage übernommen.');
        redirect('entry.php?date=' . urlencode($date) . '&project_id=' . $projectId);
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

    $saveEntry($pdo, $employeeId, $projectId, $date, $activity, $validSegments);

    flash('Arbeitszeit gespeichert.');
    redirect('entry.php?date=' . urlencode($date) . '&project_id=' . $projectId);
}

$entry = get_entry_for_date($pdo, $employeeId, $projectId, $date);
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

<section class="panel form-panel quick-add-panel">
    <h2>Quick Add</h2>
    <p class="quick-add-note">Schneller Eintrag in einer Zeile. Stunden werden als ein Zeitblock gespeichert.</p>
    <form class="quick-add-grid" method="post" id="quick-add-form">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="quick_add">

        <label>
            Datum
            <input type="date" name="quick_work_date" value="<?= h($date) ?>" required>
        </label>
        <label>
            Projekt
            <select name="quick_project_id" required>
                <?php foreach ($projects as $project): ?>
                    <option value="<?= (int) $project['id'] ?>" <?= (int) $project['id'] === $projectId ? 'selected' : '' ?>>
                        <?= h($project['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            Stunden
            <input type="number" name="quick_hours" min="0.25" step="0.25" placeholder="z. B. 7.5" required>
        </label>
        <label>
            Start
            <input type="time" name="quick_start_time" value="08:00">
        </label>
        <label class="quick-add-wide">
            Tätigkeit
            <input type="text" name="quick_activity" placeholder="Optional, z. B. Workshop Vorbereitung">
        </label>
        <div class="form-actions">
            <button class="button primary" type="submit">Quick Add speichern</button>
        </div>
    </form>
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
            <button class="button" type="submit" name="action" value="copy_yesterday">Gestern kopieren</button>
            <button class="button" type="submit" name="action" value="copy_last_project">Letzten Projekteintrag kopieren</button>
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

<script>
    (() => {
        const projectStorageKey = 'arbeitszeit-last-project';
        const activityStorageKey = 'arbeitszeit-last-activity';

        const entryForm = document.getElementById('entry-form');
        const quickForm = document.getElementById('quick-add-form');
        const entryProject = entryForm?.querySelector('select[name="project_id"]');
        const quickProject = quickForm?.querySelector('select[name="quick_project_id"]');
        const entryActivity = entryForm?.querySelector('textarea[name="activity"]');
        const quickActivity = quickForm?.querySelector('input[name="quick_activity"]');

        const savedProject = localStorage.getItem(projectStorageKey);
        if (savedProject) {
            if (entryProject && !entryProject.value) {
                entryProject.value = savedProject;
            }
            if (quickProject && !quickProject.value) {
                quickProject.value = savedProject;
            }
        }

        const savedActivity = localStorage.getItem(activityStorageKey);
        if (savedActivity && entryActivity && !entryActivity.value.trim()) {
            entryActivity.value = savedActivity;
        }
        if (savedActivity && quickActivity && !quickActivity.value.trim()) {
            quickActivity.value = savedActivity;
        }

        const persistProject = (event) => localStorage.setItem(projectStorageKey, event.target.value);
        const persistActivity = (event) => localStorage.setItem(activityStorageKey, event.target.value.trim());
        entryProject?.addEventListener('change', persistProject);
        quickProject?.addEventListener('change', persistProject);
        entryActivity?.addEventListener('blur', persistActivity);
        quickActivity?.addEventListener('blur', persistActivity);

        document.addEventListener('keydown', (event) => {
            if ((event.ctrlKey || event.metaKey) && event.key === 'Enter' && entryForm) {
                event.preventDefault();
                entryForm.requestSubmit();
            }
        });
    })();
</script>

<?php render_footer(); ?>
