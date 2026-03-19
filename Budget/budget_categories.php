<?php
//session_start();
require_once '../db.php';
require_once 'header.php';

// Check user role for permission
$show_buttons = ($_SESSION['system_role'] == 'pm_manager' || $_SESSION['system_role'] == 'super_admin');

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_parent_category'])) {
        $parent_name = $_POST['parent_name'];
        $description = $_POST['description'];
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        // Check for duplicate parent name
        $check = $conn->prepare("SELECT id FROM parent_budget_categories WHERE parent_name = ?");
        $check->bind_param("s", $parent_name);
        $check->execute();
        $check->store_result();
        
        if ($check->num_rows > 0) {
            $_SESSION['message'] = '<div class="alert alert-danger">Parent category with this name already exists!</div>';
        } else {
            $stmt = $conn->prepare("INSERT INTO parent_budget_categories (parent_name, description, is_active) VALUES (?, ?, ?)");
            $stmt->bind_param("ssi", $parent_name, $description, $is_active);
            $stmt->execute();
            
            if ($stmt->affected_rows > 0) {
                $_SESSION['message'] = '<div class="alert alert-success">Parent category added successfully!</div>';
            } else {
                $_SESSION['message'] = '<div class="alert alert-danger">Error adding parent category: ' . $conn->error . '</div>';
            }
        }
      //  header("Location: budget_categories.php");
       // exit();
    } elseif (isset($_POST['add_child_category'])) {
        $parent_id = $_POST['parent_id'];
        $category_name = $_POST['category_name'];
        $category_code = $_POST['category_code'];
        $description = $_POST['description'];
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        // Validate parent_id exists
        $check = $conn->prepare("SELECT id FROM parent_budget_categories WHERE id = ?");
        $check->bind_param("i", $parent_id);
        $check->execute();
        $check->store_result();
        
        if ($check->num_rows == 0) {
            $_SESSION['message'] = '<div class="alert alert-danger">Invalid parent category selected!</div>';
        } else {
            $stmt = $conn->prepare("INSERT INTO budget_categories (parent_id, category_name, category_code, description, is_active) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("isssi", $parent_id, $category_name, $category_code, $description, $is_active);
            $stmt->execute();
            
            if ($stmt->affected_rows > 0) {
                $_SESSION['message'] = '<div class="alert alert-success">Child category added successfully!</div>';
            } else {
                $_SESSION['message'] = '<div class="alert alert-danger">Error adding child category: ' . $conn->error . '</div>';
            }
        }
       // header("Location: budget_categories.php");
        //exit();
    } elseif (isset($_POST['update_category'])) {
        $id = $_POST['id'];
        $parent_id = $_POST['parent_id'] ?: NULL;
        $category_name = $_POST['category_name'];
        $category_code = $_POST['category_code'];
        $description = $_POST['description'];
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        $stmt = $conn->prepare("UPDATE budget_categories SET parent_id=?, category_name=?, category_code=?, description=?, is_active=? WHERE id=?");
        $stmt->bind_param("isssii", $parent_id, $category_name, $category_code, $description, $is_active, $id);
        $stmt->execute();
        
        if ($stmt->affected_rows > 0) {
            $_SESSION['message'] = '<div class="alert alert-success">Category updated successfully!</div>';
        } else {
            $_SESSION['message'] = '<div class="alert alert-danger">Error updating category: ' . $conn->error . '</div>';
        }
       // header("Location: budget_categories.php");
       // exit();
    }
}

// Handle delete action
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    
    // Check if it's a parent category
    if (isset($_GET['type']) && $_GET['type'] == 'parent') {
        // Check for child categories
        $check = $conn->query("SELECT COUNT(*) as count FROM budget_categories WHERE parent_id = $id");
        $result = $check->fetch_assoc();
        
        if ($result['count'] > 0) {
            $_SESSION['message'] = '<div class="alert alert-warning">This parent category has '.$result['count'].' child categories. Please delete or reassign them first.</div>';
        } else {
            $stmt = $conn->prepare("DELETE FROM parent_budget_categories WHERE id=?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            
            if ($stmt->affected_rows > 0) {
                $_SESSION['message'] = '<div class="alert alert-success">Parent category deleted successfully!</div>';
            } else {
                $_SESSION['message'] = '<div class="alert alert-danger">Error deleting parent category: ' . $conn->error . '</div>';
            }
        }
    } else {
        // Child category deletion (existing logic)
        $check = $conn->query("SELECT COUNT(*) as count FROM budget_items WHERE budget_category_id = $id");
        $result = $check->fetch_assoc();
        
        if ($result['count'] > 0) {
            $_SESSION['reassign_category_id'] = $id;
            $_SESSION['reassign_count'] = $result['count'];
        } else {
            deleteCategory($id);
        }
    }
   // header("Location: budget_categories.php");
    //exit();
}

// Handle reassignment
if (isset($_POST['reassign_items'])) {
    $old_id = $_POST['old_category_id'];
    $new_id = $_POST['new_category_id'];
    
    // Reassign items
    $conn->query("UPDATE budget_items SET budget_category_id = $new_id WHERE budget_category_id = $old_id");
    
    // Now delete the category
    deleteCategory($old_id);
    unset($_SESSION['reassign_category_id']);
    unset($_SESSION['reassign_count']);
    header("Location: budget_categories.php");
    exit();
}

function deleteCategory($id) {
    global $conn;
    $stmt = $conn->prepare("DELETE FROM budget_categories WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    
    if ($stmt->affected_rows > 0) {
        $_SESSION['message'] = '<div class="alert alert-success">Category deleted successfully!</div>';
    } else {
        $_SESSION['message'] = '<div class="alert alert-danger">Error deleting category: ' . $conn->error . '</div>';
    }
}

// Fetch all parent categories
$parent_result = $conn->query("SELECT * FROM parent_budget_categories ORDER BY parent_name");
$parents = $parent_result->fetch_all(MYSQLI_ASSOC);

// Fetch all child categories
$children_result = $conn->query("SELECT * FROM budget_categories ORDER BY category_name");
$all_children = $children_result->fetch_all(MYSQLI_ASSOC);

// Group children by parent_id for efficient lookup
$children_by_parent = [];
foreach ($all_children as $child) {
    $children_by_parent[$child['parent_id']][] = $child;
}
?>

            <!-- Page content will be inserted here -->
            <div class="container-fluid">

<style>
    .card {
        border: none;
        border-radius: 0.5rem;
        box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
    }
    
    .btn-primary {
        background-color: #273274;
        border-color: #273274;
    }
    
    .btn-primary:hover {
        background-color: #1e2660;
        border-color: #1e2660;
    }
    
    .list-group-item-primary {
        background-color: rgba(39, 50, 116, 0.1);
        border-color: rgba(39, 50, 116, 0.2);
        color: #273274;
    }
    
    .modal-header {
        background-color: #273274;
        color: white;
    }
    
    .modal-header .btn-close {
        filter: invert(1);
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
if (isset($_SESSION['reassign_category_id'])) {
    echo '<div class="alert alert-warning">';
    echo '<h5>This category has '.$_SESSION['reassign_count'].' budget item(s).</h5>';
    echo '<form method="POST" class="mt-3">';
    echo '<input type="hidden" name="old_category_id" value="'.$_SESSION['reassign_category_id'].'">';
    echo '<div class="row g-3 align-items-center">';
    echo '<div class="col-auto">';
    echo '<label class="col-form-label">Move items to:</label>';
    echo '</div>';
    echo '<div class="col-auto">';
    echo '<select name="new_category_id" class="form-select" required>';
    
    // Get other categories
    $categories = $conn->query("SELECT id, category_name FROM budget_categories WHERE id != ".$_SESSION['reassign_category_id']);
    while ($cat = $categories->fetch_assoc()) {
        echo '<option value="'.$cat['id'].'">'.htmlspecialchars($cat['category_name']).'</option>';
    }
    
    echo '</select>';
    echo '</div>';
    echo '<div class="col-auto">';
    echo '<button type="submit" name="reassign_items" class="btn btn-dashen">Reassign and Delete</button>';
    echo '</div>';
    echo '</div>';
    echo '</form>';
    echo '</div>';
}
?>

<div class="row mb-4">
    <div class="col-md-6">
        <h2 class="page-title">Budget Categories</h2>
    </div>
    <?php if ($show_buttons): ?>
    <div class="col-md-6 text-end">
        <button class="btn btn-dashen me-2" data-bs-toggle="modal" data-bs-target="#addParentCategoryModal">
            <i class="fas fa-plus me-2"></i>Add Parent Category
        </button>
        <button class="btn btn-dashen" data-bs-toggle="modal" data-bs-target="#addChildCategoryModal">
            <i class="fas fa-plus me-2"></i>Add Child Category
        </button>
    </div>
    <?php endif; ?>
</div>

<div class="card card-dashen mb-4">
    <div class="card-header-dashen">
        <h5 class="mb-0">Budget Category Structure</h5>
    </div>
    <div class="card-body">
        <div class="list-group">
            <?php foreach ($parents as $parent): ?>
                <div class="list-group-item list-group-item-action list-group-item-primary d-flex justify-content-between align-items-center mb-1">
                    <div>
                        <h5 class="mb-0"><?= htmlspecialchars($parent['parent_name']) ?></h5>
                        <small class="text-muted">ID: <?= $parent['id'] ?> | Type: Parent</small>
                        <?php if ($parent['description']): ?>
                            <div class="mt-1 small"><?= htmlspecialchars($parent['description']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div>
                        <span class="badge bg-<?= $parent['is_active'] ? 'success' : 'secondary' ?> me-2">
                            <?= $parent['is_active'] ? 'Active' : 'Inactive' ?>
                        </span>
                        <?php if ($show_buttons): ?>
                            <a href="budget_categories.php?delete=<?= $parent['id'] ?>&type=parent" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete this parent category? This will fail if it has children.')">
                                <i class="fas fa-trash"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
                
                <?php
                // Display children for this parent using the pre-processed array
                if (isset($children_by_parent[$parent['id']])) {
                    echo '<ul class="list-group list-group-flush ms-4 mb-3">';
                    foreach ($children_by_parent[$parent['id']] as $child) {
                        ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <div>
                                <div class="fw-bold"><?= htmlspecialchars($child['category_name']) ?></div>
                                <small class="text-muted">ID: <?= $child['id'] ?> | Code: <?= htmlspecialchars($child['category_code']) ?> | Type: Child</small>
                                <?php if ($child['description']): ?>
                                    <div class="mt-1 small"><?= htmlspecialchars($child['description']) ?></div>
                                <?php endif; ?>
                            </div>
                            <div>
                                <span class="badge bg-<?= $child['is_active'] ? 'success' : 'secondary' ?> me-2">
                                    <?= $child['is_active'] ? 'Active' : 'Inactive' ?>
                                </span>
                                <?php if ($show_buttons): ?>
                                    <button class="btn btn-sm btn-warning me-1" data-bs-toggle="modal" data-bs-target="#editCategoryModal_<?= $child['id'] ?>">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <a href="budget_categories.php?delete=<?= $child['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete this category?')">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php
                    }
                    echo '</ul>';
                }
                ?>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Add Parent Category Modal -->
<div class="modal fade" id="addParentCategoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add New Parent Budget Category</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="parent_name" class="form-label">Parent Category Name</label>
                        <input type="text" class="form-control" id="parent_name" name="parent_name" required>
                    </div>
                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                    </div>
                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" id="is_active" name="is_active" checked>
                        <label class="form-check-label" for="is_active">Active</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" name="add_parent_category" class="btn btn-dashen">Add Parent Category</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Child Category Modal -->
<div class="modal fade" id="addChildCategoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add New Child Budget Category</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="parent_id" class="form-label">Parent Category</label>
                        <select class="form-select" id="parent_id" name="parent_id" required>
                            <option value="">— Select Parent —</option>
                            <?php 
                            $parent_options = $conn->query("SELECT id, parent_name FROM parent_budget_categories ORDER BY parent_name");
                            while ($option = $parent_options->fetch_assoc()): 
                            ?>
                            <option value="<?= $option['id'] ?>"><?= htmlspecialchars($option['parent_name']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="category_name" class="form-label">Category Name</label>
                        <input type="text" class="form-control" id="category_name" name="category_name" required>
                    </div>
                    <div class="mb-3">
                        <label for="category_code" class="form-label">Category Code</label>
                        <input type="text" class="form-control" id="category_code" name="category_code">
                    </div>
                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                    </div>
                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" id="is_active" name="is_active" checked>
                        <label class="form-check-label" for="is_active">Active</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" name="add_child_category" class="btn btn-dashen">Add Child Category</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Category Modals - Placed outside the loop to avoid duplication -->
<?php foreach ($all_children as $child): ?>
<div class="modal fade" id="editCategoryModal_<?= $child['id'] ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Budget Category</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="id" value="<?= $child['id'] ?>">
                    <div class="mb-3">
                        <label for="parent_id_<?= $child['id'] ?>" class="form-label">Parent Category</label>
                        <select class="form-select" id="parent_id_<?= $child['id'] ?>" name="parent_id" required>
                            <?php 
                            $parent_options = $conn->query("SELECT id, parent_name FROM parent_budget_categories ORDER BY parent_name");
                            while ($option = $parent_options->fetch_assoc()): 
                            ?>
                            <option value="<?= $option['id'] ?>" <?= $option['id'] == $child['parent_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($option['parent_name']) ?>
                            </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="category_name_<?= $child['id'] ?>" class="form-label">Category Name</label>
                        <input type="text" class="form-control" id="category_name_<?= $child['id'] ?>" name="category_name" value="<?= htmlspecialchars($child['category_name']) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="category_code_<?= $child['id'] ?>" class="form-label">Category Code</label>
                        <input type="text" class="form-control" id="category_code_<?= $child['id'] ?>" name="category_code" value="<?= htmlspecialchars($child['category_code']) ?>">
                    </div>
                    <div class="mb-3">
                        <label for="description_<?= $child['id'] ?>" class="form-label">Description</label>
                        <textarea class="form-control" id="description_<?= $child['id'] ?>" name="description" rows="3"><?= htmlspecialchars($child['description']) ?></textarea>
                    </div>
                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" id="is_active_<?= $child['id'] ?>" name="is_active" <?= $child['is_active'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_active_<?= $child['id'] ?>">Active</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" name="update_category" class="btn btn-dashen">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

            </div><!-- /.container-fluid -->

        </div><!-- /.main-content -->
    </div><!-- /.container-fluid -->

</body>
</html>