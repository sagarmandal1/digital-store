<?php
declare(strict_types=1);

auth_require_login();
csrf_verify();

$pdo = db();
$adminId = (int)($_SESSION['admin_id'] ?? 0);
$action = (string)($_GET['action'] ?? 'index');
$scopeId = app_scope_admin_id();

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'edit') {
    app_require_user_scope_for_write();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($action === 'create' || $action === 'edit')) {
    $ownerId = app_require_user_scope_for_write();
    $date = trim((string)($_POST['expense_date'] ?? today_iso()));
    $category = trim((string)($_POST['category'] ?? 'General'));
    $amount = to_float($_POST['amount'] ?? 0);
    $note = trim((string)($_POST['note'] ?? ''));

    if ($amount <= 0) {
        flash_set('danger', 'Amount must be greater than 0.');
    } else {
        if ($action === 'create') {
            $stmt = $pdo->prepare('INSERT INTO expenses (expense_date, category, amount, note, admin_id) VALUES (:date, :cat, :amt, :note, :aid)');
            $stmt->execute([':date' => $date, ':cat' => $category, ':amt' => $amount, ':note' => $note, ':aid' => $ownerId]);
            $newId = (int)$pdo->lastInsertId();
            app_audit_log('create', 'expense', $newId, ['amount' => $amount, 'category' => $category]);
            flash_set('success', 'Expense recorded.');
        } else {
            $id = (int)($_GET['id'] ?? 0);
            $stmt = $pdo->prepare('UPDATE expenses SET expense_date=:date, category=:cat, amount=:amt, note=:note WHERE id=:id AND admin_id=:aid');
            $stmt->execute([':date' => $date, ':cat' => $category, ':amt' => $amount, ':note' => $note, ':id' => $id, ':aid' => $ownerId]);
            if ($stmt->rowCount() > 0) {
                app_audit_log('update', 'expense', $id, ['amount' => $amount, 'category' => $category]);
            }
            flash_set('success', 'Expense updated.');
        }
    }
    header('Location: index.php?page=expenses');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete') {
    $ownerId = app_require_user_scope_for_write();
    $id = (int)($_POST['id'] ?? 0);
    try {
        $stmt = $pdo->prepare('DELETE FROM expenses WHERE id=:id AND admin_id=:aid');
        $stmt->execute([':id' => $id, ':aid' => $ownerId]);
        
        if ($stmt->rowCount() > 0) {
            app_audit_log('delete', 'expense', $id);
            flash_set('success', 'Expense deleted.');
        } else {
            flash_set('danger', 'Expense not found or access denied.');
        }
    } catch (PDOException $e) {
        flash_set('danger', 'Error deleting expense: ' . $e->getMessage());
    }
    header('Location: index.php?page=expenses');
    exit;
}

$expense = null;
if ($action === 'edit') {
    $id = (int)($_GET['id'] ?? 0);
    $ownerId = app_scope_admin_id();
    $stmt = $pdo->prepare('SELECT * FROM expenses WHERE id=:id AND admin_id=:aid LIMIT 1');
    $stmt->execute([':id' => $id, ':aid' => $ownerId]);
    $expense = $stmt->fetch();
}

$stmt = $pdo->prepare('SELECT * FROM expenses WHERE (admin_id = :aid OR :aid = 0) ORDER BY expense_date DESC, id DESC LIMIT 500');
$stmt->execute([':aid' => $scopeId]);
$expenses = $stmt->fetchAll();
$currency = app_setting('currency_symbol', '');

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0">Expense Management</h1>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#expenseModal" onclick="resetForm()" <?= app_can_write() ? '' : 'disabled' ?>><i class="bi bi-plus-lg me-1"></i>Add Expense</button>
</div>

<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light text-muted small text-uppercase fw-bold border-bottom">
                    <tr>
                        <th class="ps-3 py-3">Date</th>
                        <th class="py-3">Category</th>
                        <th class="py-3">Note</th>
                        <th class="text-end py-3">Amount</th>
                        <th class="text-end pe-3 py-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$expenses): ?>
                        <tr><td colspan="5" class="text-center py-5 text-muted">No expenses found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($expenses as $ex): ?>
                            <tr>
                                <td class="ps-3"><?= e((string)$ex['expense_date']) ?></td>
                                <td><span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle fw-normal"><?= e((string)$ex['category']) ?></span></td>
                                <td class="text-muted small"><?= e((string)$ex['note']) ?></td>
                                <td class="text-end fw-bold text-danger"><?= money_fmt(to_float($ex['amount']), $currency) ?></td>
                                <td class="text-end pe-3">
                                    <button class="btn btn-sm btn-outline-primary me-1" onclick="editExpense(<?= e(json_encode($ex)) ?>)"><i class="bi bi-pencil"></i></button>
                                    <form method="post" action="index.php?page=expenses&action=delete" class="d-inline" onsubmit="return confirm('Delete this expense?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= (int)$ex['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Expense Modal -->
<div class="modal fade" id="expenseModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-primary text-white border-0">
                <h5 class="modal-title fw-bold" id="modalTitle">Add Expense</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="expenseForm" method="post" action="index.php?page=expenses&action=create">
                <?= csrf_field() ?>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-uppercase">Amount</label>
                        <div class="input-group">
                            <span class="input-group-text"><?= e($currency) ?></span>
                            <input type="number" step="0.01" class="form-control form-control-lg" name="amount" id="formAmount" required>
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label small text-uppercase">Date</label>
                            <input type="date" class="form-control" name="expense_date" id="formDate" value="<?= today_iso() ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small text-uppercase">Category</label>
                            <select class="form-select" name="category" id="formCategory">
                                <option value="Rent">Rent</option>
                                <option value="Electricity">Electricity</option>
                                <option value="Internet">Internet</option>
                                <option value="Salary">Salary</option>
                                <option value="Supplies">Supplies</option>
                                <option value="Marketing">Marketing</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-0">
                        <label class="form-label small text-uppercase">Note</label>
                        <textarea class="form-control" name="note" id="formNote" rows="2" placeholder="Describe the expense..."></textarea>
                    </div>
                </div>
                <div class="modal-footer border-0 p-4 pt-0">
                    <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4">Save Expense</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    function resetForm() {
        document.getElementById('modalTitle').innerText = 'Add Expense';
        document.getElementById('expenseForm').action = 'index.php?page=expenses&action=create';
        document.getElementById('formAmount').value = '';
        document.getElementById('formDate').value = '<?= today_iso() ?>';
        document.getElementById('formCategory').value = 'Rent';
        document.getElementById('formNote').value = '';
    }

    function editExpense(ex) {
        document.getElementById('modalTitle').innerText = 'Edit Expense';
        document.getElementById('expenseForm').action = 'index.php?page=expenses&action=edit&id=' + ex.id;
        document.getElementById('formAmount').value = ex.amount;
        document.getElementById('formDate').value = ex.expense_date;
        document.getElementById('formCategory').value = ex.category;
        document.getElementById('formNote').value = ex.note;
        new bootstrap.Modal(document.getElementById('expenseModal')).show();
    }
</script>

<?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
