<?php
//session_start();
require_once '../db.php';
require_once 'header.php';

// Check user role
$current_user_role = $_SESSION['system_role'] ?? 'pm_employee';
$is_manager_or_admin = in_array($current_user_role, ['super_admin', 'pm_manager']);
$is_employee = ($current_user_role == 'pm_employee');

// Fetch projects once for use in all forms
$projects_query = $conn->query("SELECT id, name FROM projects ORDER BY name");
$projects = [];
if ($projects_query) {
    while ($row = $projects_query->fetch_assoc()) {
        $projects[] = $row;
    }
}

// Generate fiscal year options based on July-June fiscal year
$fiscal_year_options = [];
$current_year = date('Y');
$current_month = date('n');

// Calculate fiscal year start (July-June)
$fiscal_start_year = ($current_month >= 7) ? $current_year : $current_year - 1;
$start_year = 2015;
$end_year = $fiscal_start_year + 5; // Show 5 years into the future

for ($year = $start_year; $year <= $end_year; $year++) {
    $fiscal_year_options["$year-" . ($year + 1)] = "$year-" . ($year + 1);
}

// Default selected fiscal year (current fiscal year)
$default_fiscal_year = $fiscal_start_year . "-" . ($fiscal_start_year + 1);

// Handle form submissions
$has_message = false;
$message_type = '';
$message_text = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_budget_item'])) {
        $budget_category_id = intval($_POST['budget_category_id']);
        $item_name = $conn->real_escape_string($_POST['item_name']);
        $cost_type_id = intval($_POST['cost_type_id']);
        $department_id = intval($_POST['department_id']);
        $project_id = !empty($_POST['project_id']) ? intval($_POST['project_id']) : null;
        $fiscal_year = $conn->real_escape_string($_POST['fiscal_year']);
        $estimated_amount = floatval($_POST['estimated_amount']);
        $contingency_percentage = floatval($_POST['contingency_percentage']);
        $contingency_amount = $estimated_amount * $contingency_percentage / 100;
        $total_budget_amount = $estimated_amount + $contingency_amount;
        $remarks = $conn->real_escape_string($_POST['remarks']);
        
        // Set default status based on user role
        $status = 'requesting'; // All new items start as 'requesting'
        
        $created_by = intval($_SESSION['user_id'] ?? 0);
        
        $stmt = $conn->prepare("INSERT INTO budget_items (budget_category_id, cost_type_id, department_id, project_id, fiscal_year, estimated_amount, contingency_percentage, contingency_amount, total_budget_amount, remarks, status, created_by, item_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iiiisddddssis", $budget_category_id, $cost_type_id, $department_id, $project_id, $fiscal_year, $estimated_amount, $contingency_percentage, $contingency_amount, $total_budget_amount, $remarks, $status, $created_by, $item_name);
        
        if ($stmt->execute()) {
            $has_message = true;
            $message_type = 'success';
            $message_text = 'Budget item added successfully! Status set to "Requesting".';
        } else {
            $has_message = true;
            $message_type = 'danger';
            $message_text = 'Error adding budget item: ' . $conn->error;
        }
        
    } elseif (isset($_POST['update_budget_item'])) {
        $id = intval($_POST['id']);
        $budget_category_id = intval($_POST['budget_category_id']);
        $cost_type_id = intval($_POST['cost_type_id']);
        $item_name = $conn->real_escape_string($_POST['item_name']);
        $department_id = intval($_POST['department_id']);
        $project_id = !empty($_POST['project_id']) ? intval($_POST['project_id']) : null;
        $fiscal_year = $conn->real_escape_string($_POST['fiscal_year']);
        $estimated_amount = floatval($_POST['estimated_amount']);
        $contingency_percentage = floatval($_POST['contingency_percentage']);
        $contingency_amount = $estimated_amount * $contingency_percentage / 100;
        $total_budget_amount = $estimated_amount + $contingency_amount;
        $remarks = $conn->real_escape_string($_POST['remarks']);
        
        // Employees cannot update status - get current status
        $stmt = $conn->prepare("SELECT status FROM budget_items WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $current_item = $result->fetch_assoc();
        $status = $current_item['status'] ?? 'requesting';
        
        $stmt = $conn->prepare("UPDATE budget_items SET budget_category_id=?, cost_type_id=?, department_id=?, project_id=?, fiscal_year=?, estimated_amount=?, contingency_percentage=?, contingency_amount=?, total_budget_amount=?, remarks=?, item_name=? WHERE id=?");
        $stmt->bind_param("iiiisddddssi", $budget_category_id, $cost_type_id, $department_id, $project_id, $fiscal_year, $estimated_amount, $contingency_percentage, $contingency_amount, $total_budget_amount, $remarks, $item_name, $id);
        
        if ($stmt->execute()) {
            $has_message = true;
            $message_type = 'success';
            $message_text = 'Budget item updated successfully!';
        } else {
            $has_message = true;
            $message_type = 'danger';
            $message_text = 'Error updating budget item: ' . $conn->error;
        }
        
    } elseif (isset($_POST['update_status'])) {
        // Inline status update (only for managers/admins)
        if ($is_manager_or_admin) {
            $id = intval($_POST['id']);
            $status = trim($conn->real_escape_string($_POST['status']));

            // Validate allowed statuses
            $allowed_statuses = ['requesting', 'approved', 'rejected'];
            if (!in_array($status, $allowed_statuses)) {
                $has_message = true;
                $message_type = 'danger';
                $message_text = 'Invalid status value provided!';
            } else {
                $stmt = $conn->prepare("UPDATE budget_items SET status=? WHERE id=?");
                $stmt->bind_param("si", $status, $id);
                
                if ($stmt->execute()) {
                    $has_message = true;
                    $message_type = 'success';
                    $message_text = "Status updated successfully to '$status'!";
                } else {
                    $has_message = true;
                    $message_type = 'danger';
                    $message_text = 'Error updating status: ' . $conn->error;
                }
            }
        } else {
            $has_message = true;
            $message_type = 'danger';
            $message_text = 'You are not authorized to update status!';
        }
    }
}

// Handle delete action - Check for foreign key constraints
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    
    // Check for foreign key constraints in actual_expenses table
    $check_stmt = $conn->prepare("SELECT COUNT(*) as expense_count FROM actual_expenses WHERE budget_item_id = ?");
    $check_stmt->bind_param("i", $id);
    $check_stmt->execute();
    $expense_result = $check_stmt->get_result()->fetch_assoc();
    
    if ($expense_result['expense_count'] > 0) {
        $has_message = true;
        $message_type = 'danger';
        $message_text = "Cannot delete budget item! This item has " . $expense_result['expense_count'] . " associated actual expense(s). Please delete the expenses first.";
    } else {
        // No foreign key constraints - attempt to delete
        $stmt = $conn->prepare("DELETE FROM budget_items WHERE id=?");
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                $has_message = true;
                $message_type = 'success';
                $message_text = 'Budget item deleted successfully!';
            } else {
                $has_message = true;
                $message_type = 'danger';
                $message_text = 'Error deleting budget item: Item not found or already deleted.';
            }
        } else {
            // Catch any other database errors
            $has_message = true;
            $message_type = 'danger';
            $message_text = 'Cannot delete budget item! This item is referenced in other parts of the system.';
        }
    }
}

// Pagination setup
$items_per_page = 10;
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($current_page - 1) * $items_per_page;

// Get total number of items based on user role
if ($is_employee) {
    $user_id = intval($_SESSION['user_id'] ?? 0);
    $total_stmt = $conn->prepare("SELECT COUNT(*) as total FROM budget_items WHERE created_by = ?");
    $total_stmt->bind_param("i", $user_id);
    $total_stmt->execute();
    $total_result = $total_stmt->get_result();
} else {
    $total_result = $conn->query("SELECT COUNT(*) as total FROM budget_items");
}

$total_items = $total_result->fetch_assoc()['total'];
$total_pages = ceil($total_items / $items_per_page);

// Ensure current page is within valid range
if ($current_page < 1) $current_page = 1;
if ($total_pages > 0 && $current_page > $total_pages) $current_page = $total_pages;

// Build query based on user role
$base_query = "
    SELECT 
        bi.*, 
        bc.category_name, 
        ct.name as cost_type_name, 
        d.department_name, 
        p.name as project_name 
    FROM budget_items bi
    LEFT JOIN budget_categories bc ON bi.budget_category_id = bc.id
    LEFT JOIN cost_types ct ON bi.cost_type_id = ct.id
    LEFT JOIN departments d ON bi.department_id = d.id
    LEFT JOIN projects p ON bi.project_id = p.id
";

if ($is_employee) {
    $base_query .= " WHERE bi.created_by = " . intval($_SESSION['user_id'] ?? 0);
}

$base_query .= " ORDER BY bi.fiscal_year DESC, bi.id DESC LIMIT ? OFFSET ?";

// Fetch budget items with pagination
$stmt = $conn->prepare($base_query);
$stmt->bind_param("ii", $items_per_page, $offset);
$stmt->execute();
$budget_items_query = $stmt->get_result();
?>

<!-- Page content will be inserted here -->
<div class="container-fluid">

<div class="row mb-4 align-items-center">
    <div class="col-md-6">
        <h2 class="page-title mb-0"><i class="fas fa-calculator me-2"></i>Budget Items</h2>
        <p class="text-muted">Manage your budget items and allocations</p>
    </div>
    <div class="col-md-6 d-flex justify-content-end">
        <div class="me-3">
            <div class="input-group">
                <input type="text" class="form-control" placeholder="Search..." id="searchInput">
                <button class="btn btn-outline-secondary" type="button" id="searchButton">
                    <i class="fas fa-search"></i>
                </button>
            </div>
        </div>
        <button class="btn btn-dashen" data-bs-toggle="modal" data-bs-target="#addBudgetItemModal">
            <i class="fas fa-plus me-2"></i>Add Budget Item
        </button>
    </div>
</div>

<?php if ($has_message): ?>
<div class="alert alert-<?= $message_type ?> alert-dismissible fade show">
    <?= $message_text ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<div class="card card-dashen shadow-sm rounded-lg">
    <div class="card-header-dashen d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Budget Items List</h5>
        <div class="dropdown">
            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" id="filterDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="fas fa-filter me-1"></i> Filter
            </button>
            <ul class="dropdown-menu" aria-labelledby="filterDropdown">
                <li><a class="dropdown-item" href="#" data-filter="all">All Items</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="#" data-filter="requesting">Requesting</a></li>
                <li><a class="dropdown-item" href="#" data-filter="approved">Approved</a></li>
                <li><a class="dropdown-item" href="#" data-filter="rejected">Rejected</a></li>
            </ul>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle" id="budgetItemsTable">
                <thead class="table-light">
                    <tr>
                        <th>ID</th>
                        <th>Item Name</th>
                        <th>Category</th>
                        <th>Cost Type</th>
                        <th>Department | Project</th>
                        <th>Fiscal Year</th>
                        <th class="text-end">Amount Details</th>
                        <th>Status</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($budget_items_query->num_rows > 0): ?>
                        <?php while ($item = $budget_items_query->fetch_assoc()): ?>
                        <tr data-status="<?= $item['status'] ?>">
                            <td><?= $item['id'] ?></td>
                            <td>
                                <div class="d-flex align-items-center">
                                    <div class="icon-circle bg-light me-3">
                                        <i class="fas fa-file-invoice text-primary"></i>
                                    </div>
                                    <div>
                                        <h6 class="mb-0"><?= htmlspecialchars($item['item_name']) ?></h6>
                                        <small class="text-muted">Added: <?= date('M d, Y', strtotime($item['created_at'] ?? 'now')) ?></small>
                                    </div>
                                </div>
                            </td>
                            <td><?= htmlspecialchars($item['category_name']) ?></td>
                            <td><?= htmlspecialchars($item['cost_type_name']) ?></td>
                            <td>
                                <div class="d-flex flex-column">
                                    <span class="fw-semibold"><?= htmlspecialchars($item['department_name']) ?></span>
                                    <?php if ($item['project_name']): ?>
                                        <small class="text-muted"><i class="fas fa-cubes me-1"></i><?= htmlspecialchars($item['project_name']) ?></small>
                                    <?php else: ?>
                                        <small class="text-muted"><i class="fas fa-building me-1"></i>General Department</small>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark">
                                    <?= $item['fiscal_year'] ?>
                                </span>
                            </td>
                            <td class="text-end">
                                <div class="d-flex flex-column">
                                    <span>$<?= number_format($item['estimated_amount'], 2) ?> (Est.)</span>
                                    <small class="text-muted">+ $<?= number_format($item['contingency_amount'], 2) ?> (<?= $item['contingency_percentage'] ?>%)</small>
                                    <strong class="text-primary">$<?= number_format($item['total_budget_amount'], 2) ?></strong>
                                </div>
                            </td>
                            <td>
                                <?php if ($is_manager_or_admin): ?>
                                    <form method="POST" class="status-form">
                                        <input type="hidden" name="id" value="<?= $item['id'] ?>">
                                        <select name="status" class="form-select form-select-sm status-select" onchange="this.form.submit()">
                                            <option value="requesting" <?= ($item['status'] === 'requesting') ? 'selected' : '' ?>>Requesting</option>
                                            <option value="approved" <?= ($item['status'] === 'approved') ? 'selected' : '' ?>>Approved</option>
                                            <option value="rejected" <?= ($item['status'] === 'rejected') ? 'selected' : '' ?>>Rejected</option>
                                        </select>
                                        <input type="hidden" name="update_status" value="1">
                                    </form>
                                <?php else: ?>
                                    <?php 
                                    $status_badge = [
                                        'requesting' => 'warning',
                                        'approved' => 'success',
                                        'rejected' => 'danger'
                                    ];
                                    $badge_class = $status_badge[$item['status']] ?? 'secondary';
                                    ?>
                                    <span class="badge bg-<?= $badge_class ?>">
                                        <i class="fas fa-<?= 
                                            $item['status'] == 'requesting' ? 'clock' : 
                                            ($item['status'] == 'approved' ? 'check-circle' : 'times-circle') 
                                        ?> me-1"></i>
                                        <?= ucfirst($item['status']) ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <div class="btn-group btn-group-sm" role="group">
                                    <button class="btn btn-outline-primary me-1 edit-btn"
                                        data-bs-toggle="modal"
                                        data-bs-target="#editBudgetItemModal_<?= $item['id'] ?>">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <a href="?delete=<?= $item['id'] ?>&page=<?= $current_page ?>" class="btn btn-outline-danger delete-btn" 
                                        onclick="return confirm('Are you sure you want to delete this budget item?\n\nItem: <?= addslashes($item['item_name']) ?>\nAmount: $<?= number_format($item['total_budget_amount'], 2) ?>\n\nThis action cannot be undone!')">
                                        <i class="fas fa-trash-alt"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted py-5">
                                <i class="fas fa-inbox fa-3x mb-3 text-muted"></i>
                                <h5 class="text-muted">No budget items found</h5>
                                <p class="text-muted mb-0">Click the "Add Budget Item" button to create your first budget item.</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <nav aria-label="Budget items pagination" class="mt-4">
            <ul class="pagination pagination-dashen justify-content-center">
                <!-- Previous Page Link -->
                <li class="page-item <?= $current_page == 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="?page=<?= $current_page - 1 ?>" aria-label="Previous">
                        <span aria-hidden="true">&laquo;</span>
                    </a>
                </li>
                
                <!-- Page Numbers -->
                <?php for ($page = 1; $page <= $total_pages; $page++): ?>
                    <li class="page-item <?= $page == $current_page ? 'active' : '' ?>">
                        <a class="page-link" href="?page=<?= $page ?>"><?= $page ?></a>
                    </li>
                <?php endfor; ?>
                
                <!-- Next Page Link -->
                <li class="page-item <?= $current_page == $total_pages ? 'disabled' : '' ?>">
                    <a class="page-link" href="?page=<?= $current_page + 1 ?>" aria-label="Next">
                        <span aria-hidden="true">&raquo;</span>
                    </a>
                </li>
            </ul>
            
            <!-- Page Info -->
            <div class="text-center text-muted small mt-2">
                Showing <?= min(($offset + 1), $total_items) ?> to <?= min(($offset + $items_per_page), $total_items) ?> of <?= $total_items ?> entries
            </div>
        </nav>
        <?php endif; ?>
    </div>
</div>

<!-- Add Budget Item Modal -->
<div class="modal fade" id="addBudgetItemModal" tabindex="-1" aria-labelledby="addBudgetItemModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addBudgetItemModalLabel"><i class="fas fa-plus-circle me-2"></i>Add New Budget Item</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" id="addBudgetItemForm">
                <div class="modal-body">
                    <input type="hidden" name="add_budget_item" value="1">
                    <div class="row g-3">
                        <div class="col-md-12">
                            <label for="item_name" class="form-label">Budget Item Name *</label>
                            <input type="text" class="form-control" id="item_name" name="item_name" required placeholder="Enter descriptive budget item name">
                        </div>
                        <div class="col-md-6">
                            <label for="add_budget_category_id" class="form-label">Budget Category *</label>
                            <select class="form-select" id="add_budget_category_id" name="budget_category_id" required>
                                <option value="">— Select Category —</option>
                                <?php 
                                    $categories_query = $conn->query("SELECT id, category_name FROM budget_categories ORDER BY category_name");
                                    while ($category = $categories_query->fetch_assoc()): 
                                ?>
                                <option value="<?= $category['id'] ?>"><?= htmlspecialchars($category['category_name']) ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="add_cost_type_id" class="form-label">Cost Type *</label>
                            <select class="form-select" id="add_cost_type_id" name="cost_type_id" required>
                                <option value="">— Select Cost Type —</option>
                                <?php 
                                    $cost_types_query = $conn->query("SELECT id, name FROM cost_types ORDER BY name");
                                    while ($cost_type = $cost_types_query->fetch_assoc()): 
                                ?>
                                <option value="<?= $cost_type['id'] ?>"><?= htmlspecialchars($cost_type['name']) ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="add_department_id" class="form-label">Department *</label>
                            <select class="form-select" id="add_department_id" name="department_id" required>
                                <option value="">— Select Department —</option>
                                <?php 
                                    $departments_query = $conn->query("SELECT id, department_name FROM departments ORDER BY department_name");
                                    while ($department = $departments_query->fetch_assoc()): 
                                ?>
                                <option value="<?= $department['id'] ?>"><?= htmlspecialchars($department['department_name']) ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="add_project_id" class="form-label">Project (Optional)</label>
                            <select class="form-select" id="add_project_id" name="project_id">
                                <option value="">— Select Project (Optional) —</option>
                                <?php foreach ($projects as $project): ?>
                                    <option value="<?= $project['id'] ?>"><?= htmlspecialchars($project['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="add_fiscal_year" class="form-label">Fiscal Year *</label>
                            <select class="form-select" id="add_fiscal_year" name="fiscal_year" required>
                                <option value="">— Select Fiscal Year —</option>
                                <?php foreach ($fiscal_year_options as $value => $label): ?>
                                    <option value="<?= $value ?>" <?= $value == $default_fiscal_year ? 'selected' : '' ?>>
                                        <?= $label ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-12">
                            <div class="card border-0 shadow-sm mb-3">
                                <div class="card-header bg-light">
                                    <h6 class="mb-0"><i class="fas fa-calculator me-2"></i>Budget Amount Details</h6>
                                </div>
                                <div class="card-body">
                                    <div class="row g-3">
                                        <div class="col-md-4">
                                            <label for="add_estimated_amount" class="form-label">Estimated Amount *</label>
                                            <div class="input-group">
                                                <span class="input-group-text">$</span>
                                                <input type="number" step="0.01" min="0" class="form-control estimated-amount" id="add_estimated_amount" name="estimated_amount" required placeholder="0.00">
                                            </div>
                                            <small class="text-muted">Base amount before contingency</small>
                                        </div>
                                        <div class="col-md-4">
                                            <label for="add_contingency_percentage" class="form-label">Contingency (%) *</label>
                                            <div class="input-group">
                                                <input type="number" step="0.01" min="0" max="100" class="form-control contingency-percentage" id="add_contingency_percentage" name="contingency_percentage" value="10" required>
                                                <span class="input-group-text">%</span>
                                            </div>
                                            <small class="text-muted">Percentage for unexpected costs</small>
                                        </div>
                                        <div class="col-md-4">
                                            <label for="add_contingency_amount" class="form-label">Contingency Amount</label>
                                            <div class="input-group">
                                                <span class="input-group-text">$</span>
                                                <input type="number" step="0.01" class="form-control contingency-amount" id="add_contingency_amount" name="contingency_amount" readonly>
                                            </div>
                                            <small class="text-muted">Auto-calculated</small>
                                        </div>
                                        <div class="col-md-12">
                                            <label for="add_total_budget_amount" class="form-label">Total Budget Amount</label>
                                            <div class="input-group">
                                                <span class="input-group-text">$</span>
                                                <input type="number" step="0.01" class="form-control total-budget-amount fw-bold" id="add_total_budget_amount" name="total_budget_amount" readonly>
                                            </div>
                                            <small class="text-muted">Estimated + Contingency</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-12">
                            <label for="add_remarks" class="form-label">Remarks (Optional)</label>
                            <textarea class="form-control" id="add_remarks" name="remarks" rows="2" placeholder="Any additional notes, justifications, or comments about this budget item..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i> Cancel</button>
                    <button type="submit" class="btn btn-dashen"><i class="fas fa-save me-1"></i> Save Budget Item</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Budget Item Modals - Placed outside the loop -->
<?php 
// Re-fetch budget items for edit modals based on user role
$edit_query = "
    SELECT 
        bi.*, 
        bc.category_name, 
        ct.name as cost_type_name, 
        d.department_name, 
        p.name as project_name 
    FROM budget_items bi
    LEFT JOIN budget_categories bc ON bi.budget_category_id = bc.id
    LEFT JOIN cost_types ct ON bi.cost_type_id = ct.id
    LEFT JOIN departments d ON bi.department_id = d.id
    LEFT JOIN projects p ON bi.project_id = p.id
";

if ($is_employee) {
    $edit_query .= " WHERE bi.created_by = " . intval($_SESSION['user_id'] ?? 0);
}

$edit_query .= " ORDER BY bi.fiscal_year DESC, bi.id DESC";

$budget_items_list = $conn->query($edit_query);

if ($budget_items_list && $budget_items_list->num_rows > 0):
    while ($item = $budget_items_list->fetch_assoc()): 
?>
<div class="modal fade" id="editBudgetItemModal_<?= $item['id'] ?>" tabindex="-1" aria-labelledby="editBudgetItemModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editBudgetItemModalLabel"><i class="fas fa-edit me-2"></i>Edit Budget Item</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" id="editBudgetItemForm_<?= $item['id'] ?>">
                <div class="modal-body">
                    <input type="hidden" name="update_budget_item" value="1">
                    <input type="hidden" name="id" value="<?= $item['id'] ?>">
                    <div class="row g-3">
                        <div class="col-md-12">
                            <label for="edit_item_name_<?= $item['id'] ?>" class="form-label">Budget Item Name *</label>
                            <input type="text" class="form-control" id="edit_item_name_<?= $item['id'] ?>" name="item_name" value="<?= htmlspecialchars($item['item_name']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label for="edit_budget_category_id_<?= $item['id'] ?>" class="form-label">Budget Category *</label>
                            <select class="form-select" id="edit_budget_category_id_<?= $item['id'] ?>" name="budget_category_id" required>
                                <?php 
                                    $categories_query = $conn->query("SELECT id, category_name FROM budget_categories ORDER BY category_name");
                                    while ($category = $categories_query->fetch_assoc()): 
                                ?>
                                <option value="<?= $category['id'] ?>" <?= $category['id'] == $item['budget_category_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($category['category_name']) ?>
                                </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="edit_cost_type_id_<?= $item['id'] ?>" class="form-label">Cost Type *</label>
                            <select class="form-select" id="edit_cost_type_id_<?= $item['id'] ?>" name="cost_type_id" required>
                                <?php 
                                    $cost_types_query = $conn->query("SELECT id, name FROM cost_types ORDER BY name");
                                    while ($cost_type = $cost_types_query->fetch_assoc()): 
                                ?>
                                <option value="<?= $cost_type['id'] ?>" <?= $cost_type['id'] == $item['cost_type_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cost_type['name']) ?>
                                </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="edit_department_id_<?= $item['id'] ?>" class="form-label">Department *</label>
                            <select class="form-select" id="edit_department_id_<?= $item['id'] ?>" name="department_id" required>
                                <?php 
                                    $departments_query = $conn->query("SELECT id, department_name FROM departments ORDER BY department_name");
                                    while ($department = $departments_query->fetch_assoc()): 
                                ?>
                                <option value="<?= $department['id'] ?>" <?= $department['id'] == $item['department_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($department['department_name']) ?>
                                </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="edit_project_id_<?= $item['id'] ?>" class="form-label">Project (Optional)</label>
                            <select class="form-select" id="edit_project_id_<?= $item['id'] ?>" name="project_id">
                                <option value="">— Select Project (Optional) —</option>
                                <?php foreach ($projects as $project): ?>
                                    <option value="<?= $project['id'] ?>" <?= $project['id'] == $item['project_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($project['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="edit_fiscal_year_<?= $item['id'] ?>" class="form-label">Fiscal Year *</label>
                            <select class="form-select" id="edit_fiscal_year_<?= $item['id'] ?>" name="fiscal_year" required>
                                <option value="">— Select Fiscal Year —</option>
                                <?php foreach ($fiscal_year_options as $value => $label): ?>
                                    <option value="<?= $value ?>" <?= $value == $item['fiscal_year'] ? 'selected' : '' ?>>
                                        <?= $label ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-12">
                            <div class="card border-0 shadow-sm mb-3">
                                <div class="card-header bg-light">
                                    <h6 class="mb-0"><i class="fas fa-calculator me-2"></i>Budget Amount Details</h6>
                                </div>
                                <div class="card-body">
                                    <div class="row g-3">
                                        <div class="col-md-4">
                                            <label for="edit_estimated_amount_<?= $item['id'] ?>" class="form-label">Estimated Amount *</label>
                                            <div class="input-group">
                                                <span class="input-group-text">$</span>
                                                <input type="number" step="0.01" min="0" class="form-control estimated-amount" id="edit_estimated_amount_<?= $item['id'] ?>" name="estimated_amount" value="<?= $item['estimated_amount'] ?>" required>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <label for="edit_contingency_percentage_<?= $item['id'] ?>" class="form-label">Contingency (%) *</label>
                                            <div class="input-group">
                                                <input type="number" step="0.01" min="0" max="100" class="form-control contingency-percentage" id="edit_contingency_percentage_<?= $item['id'] ?>" name="contingency_percentage" value="<?= $item['contingency_percentage'] ?>" required>
                                                <span class="input-group-text">%</span>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <label for="edit_contingency_amount_<?= $item['id'] ?>" class="form-label">Contingency Amount</label>
                                            <div class="input-group">
                                                <span class="input-group-text">$</span>
                                                <input type="number" step="0.01" class="form-control contingency-amount" id="edit_contingency_amount_<?= $item['id'] ?>" name="contingency_amount" value="<?= $item['contingency_amount'] ?>" readonly>
                                            </div>
                                        </div>
                                        <div class="col-md-12">
                                            <label for="edit_total_budget_amount_<?= $item['id'] ?>" class="form-label">Total Budget Amount</label>
                                            <div class="input-group">
                                                <span class="input-group-text">$</span>
                                                <input type="number" step="0.01" class="form-control total-budget-amount fw-bold" id="edit_total_budget_amount_<?= $item['id'] ?>" name="total_budget_amount" value="<?= $item['total_budget_amount'] ?>" readonly>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-12">
                            <label for="edit_remarks_<?= $item['id'] ?>" class="form-label">Remarks (Optional)</label>
                            <textarea class="form-control" id="edit_remarks_<?= $item['id'] ?>" name="remarks" rows="2"><?= htmlspecialchars($item['remarks']) ?></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i> Cancel</button>
                    <button type="submit" class="btn btn-dashen"><i class="fas fa-save me-1"></i> Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php 
    endwhile;
endif; 
?>

</div><!-- /.container-fluid -->

<!-- JavaScript for enhanced functionality -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Setup calculation listeners for both modals
    const setupCalculationListeners = (prefix, id = '') => {
        const estimatedAmountInput = document.getElementById(`${prefix}estimated_amount${id}`);
        const contingencyPercentageInput = document.getElementById(`${prefix}contingency_percentage${id}`);
        const contingencyAmountInput = document.getElementById(`${prefix}contingency_amount${id}`);
        const totalBudgetAmountInput = document.getElementById(`${prefix}total_budget_amount${id}`);

        const calculateAmounts = () => {
            const estimated = parseFloat(estimatedAmountInput.value) || 0;
            const percentage = parseFloat(contingencyPercentageInput.value) || 0;
            const contingency = estimated * (percentage / 100);
            const total = estimated + contingency;

            if (contingencyAmountInput) contingencyAmountInput.value = contingency.toFixed(2);
            if (totalBudgetAmountInput) totalBudgetAmountInput.value = total.toFixed(2);
        };

        if (estimatedAmountInput && contingencyPercentageInput) {
            estimatedAmountInput.addEventListener('input', calculateAmounts);
            contingencyPercentageInput.addEventListener('input', calculateAmounts);
            calculateAmounts(); // Initial calculation
        }
    };

    // Initialize calculations for add modal
    setupCalculationListeners('add_');
    
    // Initialize calculations for each edit modal
    document.querySelectorAll('[id^="editBudgetItemModal_"]').forEach(modal => {
        const id = modal.id.split('_')[1];
        setupCalculationListeners('edit_', '_' + id);
    });

    // Auto-submit status forms when changed
    document.querySelectorAll('.status-select').forEach(select => {
        select.addEventListener('change', function() {
            this.closest('form').submit();
        });
    });

    // Search functionality
    const searchInput = document.getElementById('searchInput');
    const searchButton = document.getElementById('searchButton');
    const tableRows = document.querySelectorAll('#budgetItemsTable tbody tr');

    const performSearch = () => {
        const searchTerm = searchInput.value.toLowerCase();
        tableRows.forEach(row => {
            const rowText = row.textContent.toLowerCase();
            row.style.display = rowText.includes(searchTerm) ? '' : 'none';
        });
    };

    searchButton.addEventListener('click', performSearch);
    searchInput.addEventListener('keyup', performSearch);

    // Filter functionality
    const filterLinks = document.querySelectorAll('[data-filter]');
    filterLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            const filterValue = this.getAttribute('data-filter');
            
            tableRows.forEach(row => {
                if (filterValue === 'all') {
                    row.style.display = '';
                } else {
                    const rowStatus = row.getAttribute('data-status');
                    row.style.display = rowStatus === filterValue ? '' : 'none';
                }
            });
        });
    });
});
</script>

<style>
    .icon-circle {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    
    .table-hover tbody tr:hover {
        background-color: rgba(39, 50, 116, 0.05);
    }
    
    .badge {
        padding: 0.5em 0.75em;
        font-weight: 500;
    }
    
    .form-control:focus, .form-select:focus {
        border-color: var(--dashen-secondary);
        box-shadow: 0 0 0 0.25rem rgba(39, 50, 116, 0.25);
    }
    
    .status-select {
        width: auto !important;
        display: inline-block !important;
        min-width: 120px;
    }
    
    .status-form {
        display: inline;
    }
    
    .card-dashen {
        border: none;
        box-shadow: 0 0.125rem 0.5rem rgba(39, 50, 116, 0.1);
    }
    
    .card-header-dashen {
        background-color: #f0f2ff;
        border-bottom: 1px solid rgba(39, 50, 116, 0.1);
    }
    
    .pagination-dashen .page-item.active .page-link {
        background-color: var(--dashen-primary);
        border-color: var(--dashen-primary);
    }
    
    .pagination-dashen .page-link {
        color: var(--dashen-primary);
    }
    
    .pagination-dashen .page-link:hover {
        background-color: rgba(39, 50, 116, 0.1);
    }
</style>

</body>
</html>