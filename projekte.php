<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

$allProjects = $pdo->query(
    'SELECT p.*, pb.budget_days
     FROM projects p
     LEFT JOIN (
        SELECT pb1.*
        FROM project_budgets pb1
        JOIN (
            SELECT project_id, employee_id, MAX(id) AS max_id
            FROM project_budgets
            GROUP BY project_id, employee_id
        ) latest ON latest.max_id = pb1.id
     ) pb ON pb.project_id = p.id AND pb.employee_id = ' . (int) $currentEmployee['id'] . '
     ORDER BY p.name'
)->fetchAll();
$selectedProjectId = filter_input(INPUT_GET, 'project_id', FILTER_VALIDATE_INT) ?: 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'update_project') {
        $projectId = (int) ($_POST['project_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $code = trim((string) ($_POST['code'] ?? ''));
        $hoursPerDay = (float) ($_POST['default_hours_per_day'] ?? 8);
        $budgetDays = (float) ($_POST['budget_days'] ?? 0);
        $invoiceRef = trim((string) ($_POST['invoice_reference_default'] ?? ''));
        $status = (string) ($_POST['status'] ?? 'active');
        if (!in_array($status, ['active', 'archived'], true)) {
            $status = 'active';
        }

        if ($projectId <= 0 || $name === '' || $code === '') {
            flash('Projekt konnte nicht aktualisiert werden. Bitte Felder prüfen.', 'error');
            redirect('projekte.php');
        }

        $stmt = $pdo->prepare(
            'UPDATE projects
             SET name = ?, code = ?, default_hours_per_day = ?, invoice_reference_default = ?, status = ?, updated_at = current_timestamp()
             WHERE id = ?'
        );
        $stmt->execute([
            $name,
            $code,
            max(1.0, $hoursPerDay),
            $invoiceRef !== '' ? $invoiceRef : null,
            $status,
            $projectId,
        ]);

        $budgetStmt = $pdo->prepare(
            'SELECT id
             FROM project_budgets
             WHERE project_id = ? AND employee_id = ?
             ORDER BY id DESC
             LIMIT 1'
        );
        $budgetStmt->execute([$projectId, (int) $currentEmployee['id']]);
        $existingBudget = $budgetStmt->fetch();

        if ($existingBudget) {
            $updateBudget = $pdo->prepare(
                'UPDATE project_budgets
                 SET budget_days = ?, hours_per_day = ?, updated_at = current_timestamp()
                 WHERE id = ?'
            );
            $updateBudget->execute([
                max(0.0, $budgetDays),
                max(1.0, $hoursPerDay),
                (int) $existingBudget['id'],
            ]);
        } else {
            $insertBudget = $pdo->prepare(
                'INSERT INTO project_budgets (project_id, employee_id, label, budget_days, hours_per_day, valid_from)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $insertBudget->execute([
                $projectId,
                (int) $currentEmployee['id'],
                'Gesamtkontingent',
                max(0.0, $budgetDays),
                max(1.0, $hoursPerDay),
                date('Y-m-d'),
            ]);
        }

        flash('Projekt aktualisiert.');
        redirect('projekte.php?project_id=' . $projectId);
    }

    if ($action === 'create_project') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $code = trim((string) ($_POST['code'] ?? ''));
        $budgetDays = (float) ($_POST['budget_days'] ?? 0);
        $hoursPerDay = (float) ($_POST['default_hours_per_day'] ?? 8);
        $invoiceRef = trim((string) ($_POST['invoice_reference_default'] ?? ''));

        if ($name === '') {
            flash('Projektname ist erforderlich.', 'error');
            redirect('projekte.php#new-project');
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
        redirect('projekte.php?project_id=' . $projectId);
    }
}

render_header('Projekte', 'projects');
?>

<section class="toolbar">
    <div>
        <h1>Projekte</h1>
        <p>Neue Projekte erfassen und bestehende Projekte bearbeiten.</p>
    </div>
</section>

<section class="panel form-panel" id="new-project">
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

<section class="panel form-panel">
    <h2>Bestehende Projekte bearbeiten</h2>
    <div class="projects-edit-list">
        <?php foreach ($allProjects as $project): ?>
            <?php $highlight = (int) $project['id'] === $selectedProjectId ? ' highlighted' : ''; ?>
            <form method="post" class="project-edit-card<?= $highlight ?>">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="update_project">
                <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">

                <div class="project-edit-head">
                    <h3><?= h((string) $project['name']) ?></h3>
                    <span>#<?= (int) $project['id'] ?></span>
                </div>

                <div class="form-grid">
                    <label>
                        Projektname
                        <input type="text" name="name" value="<?= h((string) $project['name']) ?>" required>
                    </label>
                    <label>
                        Kürzel
                        <input type="text" name="code" value="<?= h((string) $project['code']) ?>" required>
                    </label>
                    <label>
                        Kontingent (Arbeitstage)
                        <input type="number" min="0" step="0.25" name="budget_days" value="<?= h((string) ($project['budget_days'] ?? 0)) ?>">
                    </label>
                    <label>
                        Stunden pro Arbeitstag
                        <input type="number" min="1" step="0.25" name="default_hours_per_day" value="<?= h((string) $project['default_hours_per_day']) ?>">
                    </label>
                    <label>
                        Rechnungsreferenz
                        <input type="text" name="invoice_reference_default" value="<?= h((string) $project['invoice_reference_default']) ?>">
                    </label>
                    <label>
                        Status
                        <select name="status">
                            <option value="active" <?= $project['status'] === 'active' ? 'selected' : '' ?>>Aktiv</option>
                            <option value="archived" <?= $project['status'] === 'archived' ? 'selected' : '' ?>>Archiviert</option>
                        </select>
                    </label>
                </div>

                <div class="form-actions">
                    <button class="button" type="submit">Projekt speichern</button>
                </div>
            </form>
        <?php endforeach; ?>
    </div>
</section>

<?php render_footer(); ?>
