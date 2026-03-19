<?php
require_once '../db.php';
require_once 'header.php';

// Function to safely delete a department
function deleteDepartment($id) {
    global $conn;
    $stmt = $conn->prepare("DELETE FROM departments WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    
    if ($stmt->affected_rows > 0) {
        $_SESSION['message'] = '<div class="alert alert-success">Department deleted successfully!</div>';
    } else {
        $_SESSION['message'] = '<div class="alert alert-danger">Error deleting department: ' . $conn->error . '</div>';
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_department'])) {
        $department_name = $_POST['department_name'];
        $department_code = $_POST['department_code'];
        $manager_id = $_POST['manager_id'] ?: NULL;
        $parent_department_id = $_POST['parent_department_id'] ?: NULL;
        $cost_center_code = $_POST['cost_center_code'];
        
        $stmt = $conn->prepare("INSERT INTO departments (department_name, department_code, manager_id, parent_department_id, cost_center_code) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("ssiss", $department_name, $department_code, $manager_id, $parent_department_id, $cost_center_code);
        $stmt->execute();
        
        if ($stmt->affected_rows > 0) {
            $_SESSION['message'] = '<div class="alert alert-success">Department added successfully!</div>';
        } else {
            $_SESSION['message'] = '<div class="alert alert-danger">Error adding department: ' . $conn->error . '</div>';
        }
       // header("Location: departments.php");
       // exit();
    } elseif (isset($_POST['update_department'])) {
        $id = $_POST['id'];
        $department_name = $_POST['department_name'];
        $department_code = $_POST['department_code'];
        $manager_id = $_POST['manager_id'] ?: NULL;
        $parent_department_id = $_POST['parent_department_id'] ?: NULL;
        $cost_center_code = $_POST['cost_center_code'];
        
        $stmt = $conn->prepare("UPDATE departments SET department_name=?, department_code=?, manager_id=?, parent_department_id=?, cost_center_code=? WHERE id=?");
        $stmt->bind_param("ssissi", $department_name, $department_code, $manager_id, $parent_department_id, $cost_center_code, $id);
        $stmt->execute();
        
        if ($stmt->affected_rows > 0) {
            $_SESSION['message'] = '<div class="alert alert-success">Department updated successfully!</div>';
        } else {
            $_SESSION['message'] = '<div class="alert alert-danger">Error updating department: ' . $conn->error . '</div>';
        }
       // header("Location: departments.php");
       // exit();
    }
}

// Handle delete action with dependency checks
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    
    // Check for budget items using this department
    $budget_items_check = $conn->query("SELECT COUNT(*) as count FROM budget_items WHERE department_id = $id");
    $budget_result = $budget_items_check->fetch_assoc();
    
    // Check for child departments
    $child_depts_check = $conn->query("SELECT COUNT(*) as count FROM departments WHERE parent_department_id = $id");
    $child_result = $child_depts_check->fetch_assoc();
    
    $total_dependencies = $budget_result['count'] + $child_result['count'];
    
    if ($total_dependencies > 0) {
        $_SESSION['reassign_dept_id'] = $id;
        $_SESSION['budget_items_count'] = $budget_result['count'];
        $_SESSION['child_depts_count'] = $child_result['count'];
    } else {
        deleteDepartment($id);
    }
   // header("Location: departments.php");
   // exit();
}

// Handle budget items reassignment
if (isset($_POST['reassign_dept_items'])) {
    $old_id = $_POST['old_dept_id'];
    $new_id = $_POST['new_dept_id'];
    
    $update = $conn->query("UPDATE budget_items SET department_id = $new_id WHERE department_id = $old_id");
    
    if ($update) {
        // Now check if we can delete the department
        $check_children = $conn->query("SELECT COUNT(*) as count FROM departments WHERE parent_department_id = $old_id");
        $children = $check_children->fetch_assoc();
        
        if ($children['count'] == 0) {
            deleteDepartment($old_id);
        } else {
            $_SESSION['message'] = '<div class="alert alert-warning">Budget items reassigned, but department still has sub-departments.</div>';
        }
    } else {
        $_SESSION['message'] = '<div class="alert alert-danger">Error reassigning budget items: ' . $conn->error . '</div>';
    }
    unset($_SESSION['reassign_dept_id']);
    unset($_SESSION['budget_items_count']);
    unset($_SESSION['child_depts_count']);
    //header("Location: departments.php");
    //exit();
}

// Handle child departments reassignment
if (isset($_POST['reassign_child_depts'])) {
    $parent_id = $_POST['parent_dept_id'];
    $new_parent_id = $_POST['new_parent_id'] ?: 'NULL'; // Handle NULL for no parent
    
    $update = $conn->query("UPDATE departments SET parent_department_id = $new_parent_id WHERE parent_department_id = $parent_id");
    
    if ($update) {
        // Now check if we can delete the department
        $check_items = $conn->query("SELECT COUNT(*) as count FROM budget_items WHERE department_id = $parent_id");
        $items = $check_items->fetch_assoc();
        
        if ($items['count'] == 0) {
            deleteDepartment($parent_id);
        } else {
            $_SESSION['message'] = '<div class="alert alert-warning">Sub-departments reassigned, but department still has budget items.</div>';
        }
    } else {
        $_SESSION['message'] = '<div class="alert alert-danger">Error reassigning sub-departments: ' . $conn->error . '</div>';
    }
    unset($_SESSION['reassign_dept_id']);
    unset($_SESSION['budget_items_count']);
    unset($_SESSION['child_depts_count']);
    header("Location: departments.php");
    exit();
}

// Fetch all departments with parent department names
$departments = $conn->query("
    SELECT d1.*, d2.department_name as parent_department_name 
    FROM departments d1 
    LEFT JOIN departments d2 ON d1.parent_department_id = d2.id 
    ORDER BY d1.department_name
");
?>

            <!-- Page content will be inserted here -->
            <div class="container-fluid">

<style>
    .card {
        border: none;
        border-radius: 0.5rem;
        box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
    }
    
    .modal-header {
        background-color: #273274;
        color: white;
    }
    
    .modal-header .btn-close {
        filter: invert(1);
    }
    
    .table th {
        background-color: rgba(39, 50, 116, 0.1);
        color: #273274;
        font-weight: 600;
    }
    
    .alert-danger {
        border-left: 4px solid #dc3545;
    }
    
    .alert-warning {
        border-left: 4px solid #ffc107;
    }
    
    .alert-success {
        border-left: 4px solid #198754;
    }
    
    .badge-paid {
        background-color: #28a745;
    }
    
    .badge-pending {
        background-color: #ffc107;
        color: #212529;
    }
    
    .badge-rejected {
        background-color: #dc3545;
    }
</style>

<?php
// Display messages
if (isset($_SESSION['message'])) {
    echo $_SESSION['message'];
    unset($_SESSION['message']);
}

// Display reassignment form if needed
if (isset($_SESSION['reassign_dept_id'])) {
    echo '<div class="alert alert-danger">';
    echo '<h5>Cannot delete department</h5>';
    
    if ($_SESSION['budget_items_count'] > 0) {
        echo '<p>• Used in '.$_SESSION['budget_items_count'].' budget item(s)</p>';
    }
    if ($_SESSION['child_depts_count'] > 0) {
        echo '<p>• Has '.$_SESSION['child_depts_count'].' sub-department(s)</p>';
    }
    
    echo '<div class="mt-3">';
    
    // Only show reassignment if there are budget items (not for child departments)
    if ($_SESSION['budget_items_count'] > 0) {
        echo '<form method="POST" class="mb-3">';
        echo '<input type="hidden" name="old_dept_id" value="'.$_SESSION['reassign_dept_id'].'">';
        echo '<div class="row g-3 align-items-center">';
        echo '<div class="col-md-5">';
        echo '<label class="form-label">Reassign budget items to:</label>';
        echo '<select name="new_dept_id" class="form-select" required>';
        
        // Get other departments (excluding current and its children)
        $depts = $conn->query("
            SELECT id, department_name 
            FROM departments 
            WHERE id != ".$_SESSION['reassign_dept_id']." AND (parent_department_id != ".$_SESSION['reassign_dept_id']." OR parent_department_id IS NULL)
            ORDER BY department_name
        ");
        while ($dept = $depts->fetch_assoc()) {
            echo '<option value="'.$dept['id'].'">'.htmlspecialchars($dept['department_name']).'</option>';
        }
        
        echo '</select>';
        echo '</div>';
        echo '<div class="col-md-4">';
        echo '<button type="submit" name="reassign_dept_items" class="btn btn-dashen">';
        echo '<i class="fas fa-exchange-alt me-2"></i>Reassign Items';
        echo '</button>';
        echo '</div>';
        echo '</div>';
        echo '</form>';
    }
    
    // Show different options for child departments
    if ($_SESSION['child_depts_count'] > 0) {
        echo '<form method="POST">';
        echo '<input type="hidden" name="parent_dept_id" value="'.$_SESSION['reassign_dept_id'].'">';
        echo '<div class="row g-3 align-items-center">';
        echo '<div class="col-md-5">';
        echo '<label class="form-label">Reassign sub-departments to:</label>';
        echo '<select name="new_parent_id" class="form-select" required>';
        echo '<option value="">-- No parent --</option>';
        
        // Get possible parent departments (excluding current and its children)
        $parent_depts = $conn->query("
            SELECT id, department_name 
            FROM departments 
            WHERE id != ".$_SESSION['reassign_dept_id']." AND (parent_department_id != ".$_SESSION['reassign_dept_id']." OR parent_department_id IS NULL)
            ORDER by department_name
        ");
        while ($parent = $parent_depts->fetch_assoc()) {
            echo '<option value="'.$parent['id'].'">'.htmlspecialchars($parent['department_name']).'</option>';
        }
        
        echo '</select>';
        echo '</div>';
        echo '<div class="col-md-4">';
        echo '<button type="submit" name="reassign_child_depts" class="btn btn-dashen">';
        echo '<i class="fas fa-sitemap me-2"></i>Reassign Sub-departments';
        echo '</button>';
        echo '</div>';
        echo '</div>';
        echo '</form>';
    }
    
    echo '</div></div>';
}
?>

<div class="row mb-4">
    <div class="col-md-6">
        <h2 class="page-title">Departments</h2>
    </div>
    <div class="col-md-6 text-end">
        <button class="btn btn-dashen" data-bs-toggle="modal" data-bs-target="#addDepartmentModal">
            <i class="fas fa-plus me-2"></i>Add Department
        </button>
    </div>
</div>

<div class="card card-dashen">
    <div class="card-header-dashen">
        <h5 class="mb-0">Departments Management</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Department Name</th>
                        <th>Code</th>
                        <th>Parent Department</th>
                        <th>Cost Center</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($department = $departments->fetch_assoc()): ?>
                    <tr>
                        <td><?= $department['id'] ?></td>
                        <td class="fw-bold"><?= htmlspecialchars($department['department_name']) ?></td>
                        <td><?= htmlspecialchars($department['department_code']) ?></td>
                        <td><?= $department['parent_department_name'] ? htmlspecialchars($department['parent_department_name']) : '—' ?></td>
                        <td><?= htmlspecialchars($department['cost_center_code']) ?></td>
                        <td>
                            <button class="btn btn-sm btn-warning me-1" data-bs-toggle="modal" data-bs-target="#editDepartmentModal_<?= $department['id'] ?>">
                                <i class="fas fa-edit"></i>
                            </button>
                            <a href="departments.php?delete=<?= $department['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete this department?')">
                                <i class="fas fa-trash"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Department Modal -->
<div class="modal fade" id="addDepartmentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add New Department</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="department_name" class="form-label">Department Name</label>
                        <input type="text" class="form-control" id="department_name" name="department_name" required>
                    </div>
                    <div class="mb-3">
                        <label for="department_code" class="form-label">Department Code</label>
                        <input type="text" class="form-control" id="department_code" name="department_code">
                    </div>
                    <div class="mb-3">
                        <label for="manager_id" class="form-label">Manager</label>
                        <select class="form-select" id="manager_id" name="manager_id">
                            <option value="">— No Manager —</option>
                            <option value="1">John Doe</option>
                            <option value="2">Jane Smith</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="parent_department_id" class="form-label">Parent Department</label>
                        <select class="form-select" id="parent_department_id" name="parent_department_id">
                            <option value="">— No Parent Department —</option>
                            <?php 
                            $parent_departments = $conn->query("SELECT id, department_name FROM departments ORDER BY department_name");
                            while ($parent = $parent_departments->fetch_assoc()): 
                            ?>
                            <option value="<?= $parent['id'] ?>"><?= htmlspecialchars($parent['department_name']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="cost_center_code" class="form-label">Cost Center Code</label>
                        <input type="text" class="form-control" id="cost_center_code" name="cost_center_code">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" name="add_department" class="btn btn-dashen">Add Department</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Department Modals - Placed outside the loop -->
<?php 
$depts_list = $conn->query("
    SELECT d1.*, d2.department_name as parent_department_name 
    FROM departments d1 
    LEFT JOIN departments d2 ON d1.parent_department_id = d2.id 
    ORDER BY d1.department_name
");
while ($department = $depts_list->fetch_assoc()): 
?>
<div class="modal fade" id="editDepartmentModal_<?= $department['id'] ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Department</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="id" value="<?= $department['id'] ?>">
                    <div class="mb-3">
                        <label for="department_name_<?= $department['id'] ?>" class="form-label">Department Name</label>
                        <input type="text" class="form-control" id="department_name_<?= $department['id'] ?>" name="department_name" value="<?= htmlspecialchars($department['department_name']) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="department_code_<?= $department['id'] ?>" class="form-label">Department Code</label>
                        <input type="text" class="form-control" id="department_code_<?= $department['id'] ?>" name="department_code" value="<?= htmlspecialchars($department['department_code']) ?>">
                    </div>
                    <div class="mb-3">
                        <label for="manager_id_<?= $department['id'] ?>" class="form-label">Manager</label>
                        <select class="form-select" id="manager_id_<?= $department['id'] ?>" name="manager_id">
                            <option value="">— No Manager —</option>
                            <option value="1" <?= $department['manager_id'] == 1 ? 'selected' : '' ?>>John Doe</option>
                            <option value="2" <?= $department['manager_id'] == 2 ? 'selected' : '' ?>>Jane Smith</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="parent_department_id_<?= $department['id'] ?>" class="form-label">Parent Department</label>
                        <select class="form-select" id="parent_department_id_<?= $department['id'] ?>" name="parent_department_id">
                            <option value="">— No Parent Department —</option>
                            <?php 
                            $parent_departments = $conn->query("SELECT id, department_name FROM departments WHERE id != {$department['id']} ORDER BY department_name");
                            while ($parent = $parent_departments->fetch_assoc()): 
                            ?>
                            <option value="<?= $parent['id'] ?>" <?= $parent['id'] == $department['parent_department_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($parent['department_name']) ?>
                            </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="cost_center_code_<?= $department['id'] ?>" class="form-label">Cost Center Code</label>
                        <input type="text" class="form-control" id="cost_center_code_<?= $department['id'] ?>" name="cost_center_code" value="<?= htmlspecialchars($department['cost_center_code']) ?>">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" name="update_department" class="btn btn-dashen">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endwhile; ?>

            </div><!-- /.container-fluid -->

        </div><!-- /.main-content -->
    </div><!-- /.container-fluid -->

</body>
</html>