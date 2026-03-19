<?php
require_once 'config.php';
require_once 'header.php';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_staff_budget'])) {
        $stmt = $conn->prepare("INSERT INTO staff_budgets 
            (department_id, position, staff_count, basic_salary, increment_percent, 
             pension_contribution, trust_fund, fiscal_year) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("isidddds", 
            $_POST['department_id'],
            $_POST['position'],
            $_POST['staff_count'],
            $_POST['basic_salary'],
            $_POST['increment_percent'],
            $_POST['pension_contribution'],
            $_POST['trust_fund'],
            $_POST['fiscal_year']);
        $stmt->execute();
    }
}

// Fetch all staff budgets with department names
$staff_budgets = $conn->query("
    SELECT s.*, d.department_name 
    FROM staff_budgets s
    JOIN departments d ON s.department_id = d.id
    ORDER BY d.department_name, s.position
");
?>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped data-table">
                <thead>
                    <tr>
                        <th>Department</th>
                        <th>Position</th>
                        <th>Staff Count</th>
                        <th>Basic Salary</th>
                        <th>Increment %</th>
                        <th>Annual Salary</th>
                        <th>Pension</th>
                        <th>Trust Fund</th>
                        <th>Total Budget</th>
                        <th>Fiscal Year</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($budget = $staff_budgets->fetch_assoc()): ?>
                    <tr>
                        <td><?= htmlspecialchars($budget['department_name']) ?></td>
                        <td><?= htmlspecialchars($budget['position']) ?></td>
                        <td><?= $budget['staff_count'] ?></td>
                        <td><?= number_format($budget['basic_salary'], 2) ?></td>
                        <td><?= number_format($budget['increment_percent'], 2) ?>%</td>
                        <td><?= number_format($budget['annual_salary'], 2) ?></td>
                        <td><?= number_format($budget['pension_contribution'], 2) ?></td>
                        <td><?= number_format($budget['trust_fund'], 2) ?></td>
                        <td><?= number_format($budget['total_budget'], 2) ?></td>
                        <td><?= htmlspecialchars($budget['fiscal_year']) ?></td>
                        <td>
                            <!-- Edit and Delete buttons -->
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Staff Budget Modal -->
<div class="modal fade" id="addStaffBudgetModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Staff Budget</h5>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Department</label>
                            <select class="form-select" name="department_id" required>
                                <?php 
                                $depts = $conn->query("SELECT id, department_name FROM departments");
                                while ($dept = $depts->fetch_assoc()): 
                                ?>
                                <option value="<?= $dept['id'] ?>"><?= htmlspecialchars($dept['department_name']) ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Fiscal Year</label>
                            <select class="form-select" name="fiscal_year" required>
                                <option value="2024/2025">2024/2025</option>
                                <option value="2025/2026">2025/2026</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Position</label>
                            <input type="text" class="form-control" name="position" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Staff Count</label>
                            <input type="number" class="form-control" name="staff_count" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Basic Salary</label>
                            <input type="number" step="0.01" class="form-control" name="basic_salary" required>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Increment %</label>
                            <input type="number" step="0.01" class="form-control" name="increment_percent">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Pension Contribution</label>
                            <input type="number" step="0.01" class="form-control" name="pension_contribution">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Trust Fund</label>
                            <input type="number" step="0.01" class="form-control" name="trust_fund">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" name="add_staff_budget" class="btn btn-primary">Add Budget</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>