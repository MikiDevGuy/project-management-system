<?php
require_once '../db.php';
require_once 'header.php';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_cost_type'])) {
        $name = $_POST['name'];
        $description = $_POST['description'];
        $unit_of_measure = $_POST['unit_of_measure'];
        $is_recurring = isset($_POST['is_recurring']) ? 1 : 0;
        $default_contingency_percentage = $_POST['default_contingency_percentage'];
        
        $stmt = $conn->prepare("INSERT INTO cost_types (name, description, unit_of_measure, is_recurring, default_contingency_percentage) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("sssid", $name, $description, $unit_of_measure, $is_recurring, $default_contingency_percentage);
        $stmt->execute();
        
        if ($stmt->affected_rows > 0) {
            $_SESSION['message'] = '<div class="alert alert-success">Cost type added successfully!</div>';
        } else {
            $_SESSION['message'] = '<div class="alert alert-danger">Error adding cost type: ' . $conn->error . '</div>';
        }
        //header("Location: cost_types.php");
        //exit();
    } elseif (isset($_POST['update_cost_type'])) {
        $id = $_POST['id'];
        $name = $_POST['name'];
        $description = $_POST['description'];
        $unit_of_measure = $_POST['unit_of_measure'];
        $is_recurring = isset($_POST['is_recurring']) ? 1 : 0;
        $default_contingency_percentage = $_POST['default_contingency_percentage'];
        
        $stmt = $conn->prepare("UPDATE cost_types SET name=?, description=?, unit_of_measure=?, is_recurring=?, default_contingency_percentage=? WHERE id=?");
        $stmt->bind_param("sssidi", $name, $description, $unit_of_measure, $is_recurring, $default_contingency_percentage, $id);
        $stmt->execute();
        
        if ($stmt->affected_rows > 0) {
            $_SESSION['message'] = '<div class="alert alert-success">Cost type updated successfully!</div>';
        } else {
            $_SESSION['message'] = '<div class="alert alert-danger">Error updating cost type: ' . $conn->error . '</div>';
        }
     //   header("Location: cost_types.php");
      //  exit();
    }
}

// Handle delete action with reassignment option
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    
    // Check for associated budget items
    $check = $conn->query("SELECT COUNT(*) as count FROM budget_items WHERE cost_type_id = $id");
    $result = $check->fetch_assoc();
    
    if ($result['count'] > 0) {
        $_SESSION['reassign_cost_type_id'] = $id;
        $_SESSION['reassign_count'] = $result['count'];
    } else {
        deleteCostType($id);
    }
  //  header("Location: cost_types.php");
   // exit();
}

// Handle reassignment request
if (isset($_POST['reassign_cost_type'])) {
    $old_id = $_POST['old_cost_type_id'];
    $new_id = $_POST['new_cost_type_id'];
    
    // Reassign budget items
    $update = $conn->query("UPDATE budget_items SET cost_type_id = $new_id WHERE cost_type_id = $old_id");
    
    if ($update) {
        // Now delete the cost type
        deleteCostType($old_id);
    } else {
        $_SESSION['message'] = '<div class="alert alert-danger">Error reassigning budget items: ' . $conn->error . '</div>';
    }
    unset($_SESSION['reassign_cost_type_id']);
    unset($_SESSION['reassign_count']);
    header("Location: cost_types.php");
    exit();
}

function deleteCostType($id) {
    global $conn;
    $stmt = $conn->prepare("DELETE FROM cost_types WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    
    if ($stmt->affected_rows > 0) {
        $_SESSION['message'] = '<div class="alert alert-success">Cost type deleted successfully!</div>';
    } else {
        $_SESSION['message'] = '<div class="alert alert-danger">Error deleting cost type: ' . $conn->error . '</div>';
    }
}

// Fetch all cost types
$cost_types = $conn->query("SELECT * FROM cost_types ORDER BY name");
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
if (isset($_SESSION['reassign_cost_type_id'])) {
    echo '<div class="alert alert-warning">';
    echo '<h5>This cost type is used in '.$_SESSION['reassign_count'].' budget item(s)</h5>';
    echo '<form method="POST" class="mt-3">';
    echo '<input type="hidden" name="old_cost_type_id" value="'.$_SESSION['reassign_cost_type_id'].'">';
    echo '<div class="row g-3 align-items-center">';
    echo '<div class="col-md-5">';
    echo '<label class="form-label">Reassign items to:</label>';
    echo '<select name="new_cost_type_id" class="form-select" required>';
    
    // Get other cost types
    $cost_types_list = $conn->query("SELECT id, name FROM cost_types WHERE id != ".$_SESSION['reassign_cost_type_id']." ORDER BY name");
    while ($type = $cost_types_list->fetch_assoc()) {
        echo '<option value="'.$type['id'].'">'.htmlspecialchars($type['name']).'</option>';
    }
    
    echo '</select>';
    echo '</div>';
    echo '<div class="col-md-4">';
    echo '<button type="submit" name="reassign_cost_type" class="btn btn-dashen">';
    echo '<i class="fas fa-exchange-alt me-2"></i>Reassign and Delete';
    echo '</button>';
    echo '</div>';
    echo '</div>';
    echo '</form>';
    echo '</div>';
}
?>

<div class="row mb-4">
    <div class="col-md-6">
        <h2 class="page-title">Cost Types</h2>
    </div>
    <div class="col-md-6 text-end">
        <button class="btn btn-dashen" data-bs-toggle="modal" data-bs-target="#addCostTypeModal">
            <i class="fas fa-plus me-2"></i>Add Cost Type
        </button>
    </div>
</div>

<div class="card card-dashen">
    <div class="card-header-dashen">
        <h5 class="mb-0">Cost Types Management</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Description</th>
                        <th>Unit of Measure</th>
                        <th>Recurring</th>
                        <th>Default Contingency</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($cost_type = $cost_types->fetch_assoc()): ?>
                    <tr>
                        <td><?= $cost_type['id'] ?></td>
                        <td class="fw-bold"><?= htmlspecialchars($cost_type['name']) ?></td>
                        <td><?= htmlspecialchars($cost_type['description']) ?></td>
                        <td><?= htmlspecialchars($cost_type['unit_of_measure']) ?></td>
                        <td>
                            <span class="badge bg-<?= $cost_type['is_recurring'] ? 'success' : 'secondary' ?>">
                                <?= $cost_type['is_recurring'] ? 'Yes' : 'No' ?>
                            </span>
                        </td>
                        <td><?= $cost_type['default_contingency_percentage'] ?>%</td>
                        <td>
                            <button class="btn btn-sm btn-warning me-1" data-bs-toggle="modal" data-bs-target="#editCostTypeModal_<?= $cost_type['id'] ?>">
                                <i class="fas fa-edit"></i>
                            </button>
                            <a href="cost_types.php?delete=<?= $cost_type['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete this cost type?')">
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

<!-- Add Cost Type Modal -->
<div class="modal fade" id="addCostTypeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add New Cost Type</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="name" class="form-label">Name</label>
                        <input type="text" class="form-control" id="name" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="unit_of_measure" class="form-label">Unit of Measure</label>
                        <input type="text" class="form-control" id="unit_of_measure" name="unit_of_measure">
                    </div>
                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" id="is_recurring" name="is_recurring">
                        <label class="form-check-label" for="is_recurring">Recurring Expense</label>
                    </div>
                    <div class="mb-3">
                        <label for="default_contingency_percentage" class="form-label">Default Contingency Percentage</label>
                        <input type="number" step="0.01" class="form-control" id="default_contingency_percentage" name="default_contingency_percentage" value="10.00">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" name="add_cost_type" class="btn btn-dashen">Add Cost Type</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Cost Type Modals - Placed outside the loop -->
<?php 
$cost_types_list = $conn->query("SELECT * FROM cost_types ORDER BY name");
while ($cost_type = $cost_types_list->fetch_assoc()): 
?>
<div class="modal fade" id="editCostTypeModal_<?= $cost_type['id'] ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Cost Type</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="id" value="<?= $cost_type['id'] ?>">
                    <div class="mb-3">
                        <label for="name_<?= $cost_type['id'] ?>" class="form-label">Name</label>
                        <input type="text" class="form-control" id="name_<?= $cost_type['id'] ?>" name="name" value="<?= htmlspecialchars($cost_type['name']) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="description_<?= $cost_type['id'] ?>" class="form-label">Description</label>
                        <textarea class="form-control" id="description_<?= $cost_type['id'] ?>" name="description" rows="3"><?= htmlspecialchars($cost_type['description']) ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="unit_of_measure_<?= $cost_type['id'] ?>" class="form-label">Unit of Measure</label>
                        <input type="text" class="form-control" id="unit_of_measure_<?= $cost_type['id'] ?>" name="unit_of_measure" value="<?= htmlspecialchars($cost_type['unit_of_measure']) ?>">
                    </div>
                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" id="is_recurring_<?= $cost_type['id'] ?>" name="is_recurring" <?= $cost_type['is_recurring'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_recurring_<?= $cost_type['id'] ?>">Recurring Expense</label>
                    </div>
                    <div class="mb-3">
                        <label for="default_contingency_percentage_<?= $cost_type['id'] ?>" class="form-label">Default Contingency Percentage</label>
                        <input type="number" step="0.01" class="form-control" id="default_contingency_percentage_<?= $cost_type['id'] ?>" name="default_contingency_percentage" value="<?= $cost_type['default_contingency_percentage'] ?>">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" name="update_cost_type" class="btn btn-dashen">Save Changes</button>
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