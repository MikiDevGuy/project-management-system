<?php
//session_start();
require_once '../db.php';
require_once 'header.php';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_expense'])) {
        $budget_item_id = $_POST['budget_item_id'];
        $transaction_date = $_POST['transaction_date'];
        $amount = $_POST['amount'];
        $currency = $_POST['currency'];
        $vendor_id = $_POST['vendor_id'] ?: NULL;
        $payment_method = $_POST['payment_method'];
        $reference_number = $_POST['reference_number'];
        $description = $_POST['description'];
        $status = $_POST['status'];
        $approved_by = $_POST['status'] == 'paid' ? 1 : NULL;
        
        $stmt = $conn->prepare("INSERT INTO actual_expenses (budget_item_id, transaction_date, amount, currency, vendor_id, payment_method, reference_number, description, status, approved_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("isdsissssi", $budget_item_id, $transaction_date, $amount, $currency, $vendor_id, $payment_method, $reference_number, $description, $status, $approved_by);
        $stmt->execute();
        
        if ($stmt->affected_rows > 0) {
            $_SESSION['message'] = '<div class="alert alert-success alert-dismissible fade show">Expense added successfully!
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>';
        } else {
            $_SESSION['message'] = '<div class="alert alert-danger alert-dismissible fade show">Error adding expense: ' . $conn->error . '
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>';
        }
        //header("Location: actual_expenses.php");
        //exit();
    } elseif (isset($_POST['update_expense'])) {
        $id = $_POST['id'];
        $budget_item_id = $_POST['budget_item_id'];
        $transaction_date = $_POST['transaction_date'];
        $amount = $_POST['amount'];
        $currency = $_POST['currency'];
        $vendor_id = $_POST['vendor_id'] ?: NULL;
        $payment_method = $_POST['payment_method'];
        $reference_number = $_POST['reference_number'];
        $description = $_POST['description'];
        $status = $_POST['status'];
        $approved_by = $_POST['status'] == 'paid' ? 1 : NULL;
        
        $stmt = $conn->prepare("UPDATE actual_expenses SET budget_item_id=?, transaction_date=?, amount=?, currency=?, vendor_id=?, payment_method=?, reference_number=?, description=?, status=?, approved_by=? WHERE id=?");
        $stmt->bind_param("isdsisssssi", $budget_item_id, $transaction_date, $amount, $currency, $vendor_id, $payment_method, $reference_number, $description, $status, $approved_by, $id);
        $stmt->execute();
        
        if ($stmt->affected_rows > 0) {
            $_SESSION['message'] = '<div class="alert alert-success alert-dismissible fade show">Expense updated successfully!
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>';
        } else {
            $_SESSION['message'] = '<div class="alert alert-danger alert-dismissible fade show">Error updating expense: ' . $conn->error . '
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>';
        }
       // header("Location: actual_expenses.php");
      //  exit();
    }
}

// Handle delete action with validation
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    
    // Check if expense exists and get its details
    $check_query = $conn->query("
        SELECT ae.*, bi.item_name, d.department_name, v.vendor_name
        FROM actual_expenses ae
        LEFT JOIN budget_items bi ON ae.budget_item_id = bi.id
        LEFT JOIN departments d ON bi.department_id = d.id
        LEFT JOIN vendors v ON ae.vendor_id = v.id
        WHERE ae.id = $id
    ");
    
    if ($check_query->num_rows == 0) {
        $_SESSION['message'] = '<div class="alert alert-danger alert-dismissible fade show">Expense not found!
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>';
    } else {
        $expense = $check_query->fetch_assoc();
        
        // Check for dependencies in existing tables only
        $dependencies = [];
        
        // Check if this expense is part of any budget calculations
        $budget_check = $conn->query("
            SELECT COUNT(*) as count FROM budget_items 
            WHERE id = {$expense['budget_item_id']}
        ");
        if ($budget_check) {
            $budget_result = $budget_check->fetch_assoc();
            if ($budget_result['count'] > 0) {
                $dependencies[] = "budget calculations";
            }
        }
        
        if (count($dependencies) > 0) {
            $_SESSION['message'] = '<div class="alert alert-danger alert-dismissible fade show">
                <h5><i class="fas fa-exclamation-triangle me-2"></i>Cannot Delete Expense</h5>
                <p>This expense cannot be deleted because it is referenced in the following system records:</p>
                <ul class="mb-2">';
            foreach ($dependencies as $dependency) {
                $_SESSION['message'] .= '<li>' . ucfirst($dependency) . '</li>';
            }
            $_SESSION['message'] .= '</ul>
                <p class="mb-0"><strong>Expense Details:</strong><br>
                Budget Item: ' . htmlspecialchars($expense['item_name']) . '<br>
                Department: ' . htmlspecialchars($expense['department_name']) . '<br>
                Amount: ' . $expense['currency'] . ' ' . number_format($expense['amount'], 2) . '<br>
                Date: ' . date('M d, Y', strtotime($expense['transaction_date'])) . '</p>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>';
        } else {
            // No dependencies - safe to delete
            $stmt = $conn->prepare("DELETE FROM actual_expenses WHERE id=?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            
            if ($stmt->affected_rows > 0) {
                $_SESSION['message'] = '<div class="alert alert-success alert-dismissible fade show">
                    <h5><i class="fas fa-check-circle me-2"></i>Expense Deleted Successfully</h5>
                    <p class="mb-0">The expense record has been permanently removed from the system.</p>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>';
            } else {
                $_SESSION['message'] = '<div class="alert alert-danger alert-dismissible fade show">Error deleting expense: ' . $conn->error . '
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>';
            }
        }
    }
    //header("Location: actual_expenses.php");
    //exit();
}

// Pagination setup
$items_per_page = 10;
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($current_page - 1) * $items_per_page;

// Get total number of expenses
$total_items_query = $conn->query("SELECT COUNT(*) as total FROM actual_expenses");
$total_items = $total_items_query->fetch_assoc()['total'];
$total_pages = ceil($total_items / $items_per_page);

// Ensure current page is within valid range
if ($current_page < 1) $current_page = 1;
if ($current_page > $total_pages && $total_pages > 0) $current_page = $total_pages;

// Fetch all expenses with related data
$expenses = $conn->query("
    SELECT 
        ae.*, 
        bi.id as budget_item_id, 
        CONCAT(bi.item_name, ' (', bc.category_name, ')') as budget_item_name, 
        v.vendor_name,
        d.department_name as department_name, 
        CASE 
            WHEN ae.approved_by = 1 THEN 'Admin'
            ELSE 'Not Approved'
        END as approved_by_name
    FROM actual_expenses ae
    LEFT JOIN budget_items bi ON ae.budget_item_id = bi.id
    LEFT JOIN budget_categories bc ON bi.budget_category_id = bc.id
    LEFT JOIN departments d ON bi.department_id = d.id
    LEFT JOIN vendors v ON ae.vendor_id = v.id
    ORDER BY ae.transaction_date DESC
    LIMIT $items_per_page OFFSET $offset
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
        border-color: #4a5cb6;
        box-shadow: 0 0 0 0.25rem rgba(39, 50, 116, 0.25);
    }
    
    .card {
        border: none;
        box-shadow: 0 0.125rem 0.5rem rgba(39, 50, 116, 0.1);
    }
    
    .modal-header {
        background-color: #f0f2ff;
        border-bottom: 1px solid rgba(39, 50, 116, 0.1);
    }
    
    .alert-success {
        background-color: #d4edda;
        border-color: #c3e6cb;
        color: #155724;
    }
    
    .alert-danger {
        background-color: #f8d7da;
        border-color: #f5c6cb;
        color: #721c24;
    }
</style>

<?php
// Display messages
if (isset($_SESSION['message'])) {
    echo $_SESSION['message'];
    unset($_SESSION['message']);
}
?>

<div class="row mb-4 align-items-center">
    <div class="col-md-6">
        <h2 class="page-title mb-0"><i class="fas fa-receipt me-2"></i>Actual Expenses</h2>
        <p class="text-muted">Track and manage your actual expenses</p>
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
        <button class="btn btn-dashen" data-bs-toggle="modal" data-bs-target="#addExpenseModal">
            <i class="fas fa-plus me-2"></i>Add Expense
        </button>
    </div>
</div>

<div class="card card-dashen">
    <div class="card-header-dashen d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Expenses List</h5>
        <div class="dropdown">
            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" id="filterDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="fas fa-filter me-1"></i> Filter
            </button>
            <ul class="dropdown-menu" aria-labelledby="filterDropdown">
                <li><a class="dropdown-item" href="#" data-filter="all">All Expenses</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="#" data-filter="pending">Pending</a></li>
                <li><a class="dropdown-item" href="#" data-filter="paid">Paid</a></li>
                <li><a class="dropdown-item" href="#" data-filter="rejected">Rejected</a></li>
            </ul>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover" id="expensesTable">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Budget Item</th>
                        <th>Department</th>
                        <th>Description</th>
                        <th class="text-end">Amount</th>
                        <th>Vendor</th>
                        <th>Status</th>
                        <th>Approved By</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($expenses->num_rows > 0): ?>
                        <?php while ($expense = $expenses->fetch_assoc()): ?>
                        <tr data-status="<?= $expense['status'] ?>">
                            <td>
                                <div class="d-flex flex-column">
                                    <span class="fw-semibold"><?= date('M d, Y', strtotime($expense['transaction_date'])) ?></span>
                                    <small class="text-muted"><?= date('h:i A', strtotime($expense['transaction_date'])) ?></small>
                                </div>
                            </td>
                            <td>
                                <div class="d-flex align-items-center">
                                    <div class="icon-circle bg-light me-3">
                                        <i class="fas fa-file-invoice text-primary"></i>
                                    </div>
                                    <div>
                                        <h6 class="mb-0"><?= htmlspecialchars($expense['budget_item_name']) ?></h6>
                                    </div>
                                </div>
                            </td>
                            <td><?= htmlspecialchars($expense['department_name']) ?></td>
                            <td><?= htmlspecialchars($expense['description']) ?></td>
                            <td class="text-end">
                                <div class="d-flex flex-column">
                                    <span class="fw-semibold"><?= $expense['currency'] ?> <?= number_format($expense['amount'], 2) ?></span>
                                    <small class="text-muted"><?= $expense['payment_method'] ?></small>
                                </div>
                            </td>
                            <td>
                                <?php if ($expense['vendor_name']): ?>
                                    <span class="badge bg-light text-dark">
                                        <i class="fas fa-store me-1"></i><?= htmlspecialchars($expense['vendor_name']) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php 
                                $status_badge = [
                                    'pending' => 'warning',
                                    'paid' => 'success',
                                    'rejected' => 'danger'
                                ];
                                $badge_class = $status_badge[$expense['status']] ?? 'secondary';
                                ?>
                                <span class="badge rounded-pill bg-<?= $badge_class ?>">
                                    <i class="fas fa-<?= 
                                        $expense['status'] == 'pending' ? 'clock' : 
                                        ($expense['status'] == 'paid' ? 'check-circle' : 'times-circle') 
                                    ?> me-1"></i>
                                    <?= ucfirst($expense['status']) ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark">
                                    <i class="fas fa-user-check me-1"></i><?= $expense['approved_by_name'] ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <div class="btn-group btn-group-sm" role="group">
                                    <button class="btn btn-outline-primary me-1" data-bs-toggle="modal" data-bs-target="#editExpenseModal_<?= $expense['id'] ?>">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <a href="actual_expenses.php?delete=<?= $expense['id'] ?>" class="btn btn-outline-danger" onclick="return confirm('Are you sure you want to delete this expense? This action cannot be undone.')">
                                        <i class="fas fa-trash-alt"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="9" class="text-center text-muted py-4">No expenses found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <nav aria-label="Expenses pagination" class="mt-4">
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

<!-- Add Expense Modal -->
<div class="modal fade" id="addExpenseModal" tabindex="-1" aria-labelledby="addExpenseModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addExpenseModalLabel"><i class="fas fa-plus-circle me-2"></i>Add New Expense</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="budget_item_id" class="form-label">Budget Item</label>
                        <select class="form-select" id="budget_item_id" name="budget_item_id" required>
                            <option value="">— Select Budget Item —</option>
                            <?php 
                            $budget_items = $conn->query("
                                SELECT bi.id, CONCAT(bi.item_name, ' (', bc.category_name, ' - ', d.department_name, ')') as item_name 
                                FROM budget_items bi
                                JOIN budget_categories bc ON bi.budget_category_id = bc.id
                                JOIN departments d ON bi.department_id = d.id
                                WHERE bi.status = 'approved'
                                ORDER BY bc.category_name, d.department_name
                            ");
                            while ($item = $budget_items->fetch_assoc()): 
                            ?>
                            <option value="<?= $item['id'] ?>"><?= htmlspecialchars($item['item_name']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="transaction_date" class="form-label">Transaction Date</label>
                        <input type="date" class="form-control" id="transaction_date" name="transaction_date" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="amount" class="form-label">Amount</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="number" step="0.01" class="form-control" id="amount" name="amount" required>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="currency" class="form-label">Currency</label>
                            <select class="form-select" id="currency" name="currency" required>
                                <option value="USD" selected>USD</option>
                                <option value="EUR">EUR</option>
                                <option value="GBP">GBP</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="vendor_id" class="form-label">Vendor</label>
                        <select class="form-select" id="vendor_id" name="vendor_id">
                            <option value="">— No Vendor —</option>
                            <?php 
                            $vendors = $conn->query("SELECT id, vendor_name FROM vendors ORDER BY vendor_name");
                            while ($vendor = $vendors->fetch_assoc()): 
                            ?>
                            <option value="<?= $vendor['id'] ?>"><?= htmlspecialchars($vendor['vendor_name']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="payment_method" class="form-label">Payment Method</label>
                        <select class="form-select" id="payment_method" name="payment_method" required>
                            <option value="cash">Cash</option>
                            <option value="check">Check</option>
                            <option value="credit_card" selected>Credit Card</option>
                            <option value="bank_transfer">Bank Transfer</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="reference_number" class="form-label">Reference Number</label>
                        <input type="text" class="form-control" id="reference_number" name="reference_number">
                    </div>
                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="3" required></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="status" class="form-label">Status</label>
                        <select class="form-select" id="status" name="status" required>
                            <option value="pending" selected>Pending</option>
                            <option value="paid">Paid</option>
                            <option value="rejected">Rejected</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i> Cancel</button>
                    <button type="submit" name="add_expense" class="btn btn-dashen"><i class="fas fa-save me-1"></i> Add Expense</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Expense Modals - Placed outside the loop -->
<?php 
$expenses_list = $conn->query("
    SELECT 
        ae.*, 
        bi.id as budget_item_id, 
        CONCAT(bi.item_name, ' (', bc.category_name, ')') as budget_item_name, 
        v.vendor_name,
        d.department_name as department_name
    FROM actual_expenses ae
    LEFT JOIN budget_items bi ON ae.budget_item_id = bi.id
    LEFT JOIN budget_categories bc ON bi.budget_category_id = bc.id
    LEFT JOIN departments d ON bi.department_id = d.id
    LEFT JOIN vendors v ON ae.vendor_id = v.id
    ORDER BY ae.transaction_date DESC
    LIMIT $items_per_page OFFSET $offset
");
while ($expense = $expenses_list->fetch_assoc()): 
?>
<div class="modal fade" id="editExpenseModal_<?= $expense['id'] ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Expense</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="id" value="<?= $expense['id'] ?>">
                    <div class="mb-3">
                        <label for="budget_item_id_<?= $expense['id'] ?>" class="form-label">Budget Item</label>
                        <select class="form-select" id="budget_item_id_<?= $expense['id'] ?>" name="budget_item_id" required>
                            <?php 
                            $budget_items = $conn->query("
                                SELECT bi.id, CONCAT(bi.item_name, ' (', bc.category_name, ' - ', d.department_name, ')') as item_name 
                                FROM budget_items bi
                                JOIN budget_categories bc ON bi.budget_category_id = bc.id
                                JOIN departments d ON bi.department_id = d.id
                                WHERE bi.status = 'approved'
                                ORDER BY bc.category_name, d.department_name
                            ");
                            while ($item = $budget_items->fetch_assoc()): 
                            ?>
                            <option value="<?= $item['id'] ?>" <?= $item['id'] == $expense['budget_item_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($item['item_name']) ?>
                            </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="transaction_date_<?= $expense['id'] ?>" class="form-label">Transaction Date</label>
                        <input type="date" class="form-control" id="transaction_date_<?= $expense['id'] ?>" name="transaction_date" value="<?= $expense['transaction_date'] ?>" required>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="amount_<?= $expense['id'] ?>" class="form-label">Amount</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="number" step="0.01" class="form-control" id="amount_<?= $expense['id'] ?>" name="amount" value="<?= $expense['amount'] ?>" required>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="currency_<?= $expense['id'] ?>" class="form-label">Currency</label>
                            <select class="form-select" id="currency_<?= $expense['id'] ?>" name="currency" required>
                                <option value="USD" <?= $expense['currency'] == 'USD' ? 'selected' : '' ?>>USD</option>
                                <option value="EUR" <?= $expense['currency'] == 'EUR' ? 'selected' : '' ?>>EUR</option>
                                <option value="GBP" <?= $expense['currency'] == 'GBP' ? 'selected' : '' ?>>GBP</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="vendor_id_<?= $expense['id'] ?>" class="form-label">Vendor</label>
                        <select class="form-select" id="vendor_id_<?= $expense['id'] ?>" name="vendor_id">
                            <option value="">— No Vendor —</option>
                            <?php 
                            $vendors = $conn->query("SELECT id, vendor_name FROM vendors ORDER BY vendor_name");
                            while ($vendor = $vendors->fetch_assoc()): 
                            ?>
                            <option value="<?= $vendor['id'] ?>" <?= $vendor['id'] == $expense['vendor_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($vendor['vendor_name']) ?>
                            </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="payment_method_<?= $expense['id'] ?>" class="form-label">Payment Method</label>
                        <select class="form-select" id="payment_method_<?= $expense['id'] ?>" name="payment_method" required>
                            <option value="cash" <?= $expense['payment_method'] == 'cash' ? 'selected' : '' ?>>Cash</option>
                            <option value="check" <?= $expense['payment_method'] == 'check' ? 'selected' : '' ?>>Check</option>
                            <option value="credit_card" <?= $expense['payment_method'] == 'credit_card' ? 'selected' : '' ?>>Credit Card</option>
                            <option value="bank_transfer" <?= $expense['payment_method'] == 'bank_transfer' ? 'selected' : '' ?>>Bank Transfer</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="reference_number_<?= $expense['id'] ?>" class="form-label">Reference Number</label>
                        <input type="text" class="form-control" id="reference_number_<?= $expense['id'] ?>" name="reference_number" value="<?= htmlspecialchars($expense['reference_number']) ?>">
                    </div>
                    <div class="mb-3">
                        <label for="description_<?= $expense['id'] ?>" class="form-label">Description</label>
                        <textarea class="form-control" id="description_<?= $expense['id'] ?>" name="description" rows="3" required><?= htmlspecialchars($expense['description']) ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="status_<?= $expense['id'] ?>" class="form-label">Status</label>
                        <select class="form-select" id="status_<?= $expense['id'] ?>" name="status" required>
                            <option value="pending" <?= $expense['status'] == 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="paid" <?= $expense['status'] == 'paid' ? 'selected' : '' ?>>Paid</option>
                            <option value="rejected" <?= $expense['status'] == 'rejected' ? 'selected' : '' ?>>Rejected</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i> Cancel</button>
                    <button type="submit" name="update_expense" class="btn btn-dashen"><i class="fas fa-save me-1"></i> Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endwhile; ?>

</div><!-- /.container-fluid -->

<!-- JavaScript for enhanced functionality -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Search functionality
    const searchInput = document.getElementById('searchInput');
    const searchButton = document.getElementById('searchButton');
    const tableRows = document.querySelectorAll('#expensesTable tbody tr');

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