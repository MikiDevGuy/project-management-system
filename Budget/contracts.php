<?php
require_once '../db.php';
require_once 'header.php';

// Define contract types
$contract_types = [
    'support' => 'Support Contract',
    'implementation' => 'Implementation Contract'
];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_contract'])) {
        $vendor_id = $_POST['vendor_id'];
        $contract_name = $_POST['contract_name'];
        $contract_number = $_POST['contract_number'];
        $contract_type = $_POST['contract_type']; // NEW: Added contract type
        $start_date = $_POST['start_date'];
        $end_date = $_POST['end_date'];
        $total_value = $_POST['total_value'];
        $renewal_terms = $_POST['renewal_terms'];
        $payment_schedule = $_POST['payment_schedule'];
        
        $stmt = $conn->prepare("INSERT INTO contracts (vendor_id, contract_name, contract_number, contract_type, start_date, end_date, total_value, renewal_terms, payment_schedule) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("isssssdss", $vendor_id, $contract_name, $contract_number, $contract_type, $start_date, $end_date, $total_value, $renewal_terms, $payment_schedule);
        $stmt->execute();
        
        if ($stmt->affected_rows > 0) {
            $_SESSION['message'] = '<div class="alert alert-success">Contract added successfully!</div>';
        } else {
            $_SESSION['message'] = '<div class="alert alert-danger">Error adding contract: ' . $conn->error . '</div>';
        }
        //header("Location: contracts.php");
       // exit();
    } elseif (isset($_POST['update_contract'])) {
        $id = $_POST['id'];
        $vendor_id = $_POST['vendor_id'];
        $contract_name = $_POST['contract_name'];
        $contract_number = $_POST['contract_number'];
        $contract_type = $_POST['contract_type']; // NEW: Added contract type
        $start_date = $_POST['start_date'];
        $end_date = $_POST['end_date'];
        $total_value = $_POST['total_value'];
        $renewal_terms = $_POST['renewal_terms'];
        $payment_schedule = $_POST['payment_schedule'];
        
        $stmt = $conn->prepare("UPDATE contracts SET vendor_id=?, contract_name=?, contract_number=?, contract_type=?, start_date=?, end_date=?, total_value=?, renewal_terms=?, payment_schedule=? WHERE id=?");
        $stmt->bind_param("isssssdssi", $vendor_id, $contract_name, $contract_number, $contract_type, $start_date, $end_date, $total_value, $renewal_terms, $payment_schedule, $id);
        $stmt->execute();
        
        if ($stmt->affected_rows > 0) {
            $_SESSION['message'] = '<div class="alert alert-success">Contract updated successfully!</div>';
        } else {
            $_SESSION['message'] = '<div class="alert alert-danger">Error updating contract: ' . $conn->error . '</div>';
        }
        //header("Location: contracts.php");
        //exit();
    }
}

// Handle delete action with dependency check
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    
    // Check if contract is used in actual_expenses
    $check = $conn->query("SELECT COUNT(*) as count FROM actual_expenses WHERE id = $id");
    $result = $check->fetch_assoc();
    
    if ($result['count'] > 0) {
        $_SESSION['message'] = '<div class="alert alert-danger">Cannot delete contract! This contract is used in ' . $result['count'] . ' actual expense(s). Please remove or reassign the expenses first.</div>';
    } else {
        $stmt = $conn->prepare("DELETE FROM contracts WHERE id=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        
        if ($stmt->affected_rows > 0) {
            $_SESSION['message'] = '<div class="alert alert-success">Contract deleted successfully!</div>';
        } else {
            $_SESSION['message'] = '<div class="alert alert-danger">Error deleting contract: ' . $conn->error . '</div>';
        }
    }
  //  header("Location: contracts.php");
   // exit();
}

// Fetch all contracts with vendor names
$contracts = $conn->query("
    SELECT c.*, v.vendor_name 
    FROM contracts c
    LEFT JOIN vendors v ON c.vendor_id = v.id
    ORDER BY c.start_date DESC
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
    
    .badge-support {
        background-color: #0d6efd;
        color: white;
    }
    
    .badge-implementation {
        background-color: #198754;
        color: white;
    }
</style>

<?php
// Display messages
if (isset($_SESSION['message'])) {
    echo $_SESSION['message'];
    unset($_SESSION['message']);
}
?>

<div class="row mb-4">
    <div class="col-md-6">
        <h2 class="page-title">Contracts Management</h2>
        <p class="text-muted">Support Contract & Implementation Contract</p>
    </div>
    <div class="col-md-6 text-end">
        <button class="btn btn-dashen" data-bs-toggle="modal" data-bs-target="#addContractModal">
            <i class="fas fa-plus me-2"></i>Add Contract
        </button>
    </div>
</div>

<div class="card card-dashen">
    <div class="card-header-dashen">
        <h5 class="mb-0">Contracts List</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Contract Name</th>
                        <th>Contract #</th>
                        <th>Type</th>
                        <th>Vendor</th>
                        <th>Start Date</th>
                        <th>End Date</th>
                        <th>Total Value</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($contract = $contracts->fetch_assoc()): ?>
                    <tr>
                        <td><?= $contract['id'] ?></td>
                        <td class="fw-bold"><?= htmlspecialchars($contract['contract_name']) ?></td>
                        <td><?= htmlspecialchars($contract['contract_number']) ?></td>
                        <td>
                            <?php if ($contract['contract_type']): ?>
                                <span class="badge badge-<?= $contract['contract_type'] ?>">
                                    <?= $contract_types[$contract['contract_type']] ?? ucfirst($contract['contract_type']) ?>
                                </span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Not Specified</span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($contract['vendor_name']) ?></td>
                        <td><?= date('M d, Y', strtotime($contract['start_date'])) ?></td>
                        <td><?= date('M d, Y', strtotime($contract['end_date'])) ?></td>
                        <td>$<?= number_format($contract['total_value'], 2) ?></td>
                        <td>
                            <button class="btn btn-sm btn-warning me-1" data-bs-toggle="modal" data-bs-target="#editContractModal_<?= $contract['id'] ?>">
                                <i class="fas fa-edit"></i>
                            </button>
                            <a href="contracts.php?delete=<?= $contract['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete this contract?')">
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

<!-- Add Contract Modal -->
<div class="modal fade" id="addContractModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add New Contract</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="vendor_id" class="form-label">Vendor</label>
                        <select class="form-select" id="vendor_id" name="vendor_id" required>
                            <option value="">— Select Vendor —</option>
                            <?php 
                            $vendors = $conn->query("SELECT id, vendor_name FROM vendors ORDER BY vendor_name");
                            while ($vendor = $vendors->fetch_assoc()): 
                            ?>
                            <option value="<?= $vendor['id'] ?>"><?= htmlspecialchars($vendor['vendor_name']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="contract_name" class="form-label">Contract Name</label>
                        <input type="text" class="form-control" id="contract_name" name="contract_name" required>
                    </div>
                    <div class="mb-3">
                        <label for="contract_number" class="form-label">Contract Number</label>
                        <input type="text" class="form-control" id="contract_number" name="contract_number">
                    </div>
                    <!-- NEW: Type dropdown menu -->
                    <div class="mb-3">
                        <label for="contract_type" class="form-label">Contract Type</label>
                        <select class="form-select" id="contract_type" name="contract_type" required>
                            <option value="">— Select Contract Type —</option>
                            <?php foreach ($contract_types as $value => $label): ?>
                                <option value="<?= $value ?>"><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="start_date" class="form-label">Start Date</label>
                            <input type="date" class="form-control" id="start_date" name="start_date" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="end_date" class="form-label">End Date</label>
                            <input type="date" class="form-control" id="end_date" name="end_date" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="total_value" class="form-label">Total Value</label>
                        <input type="number" step="0.01" class="form-control" id="total_value" name="total_value" required>
                    </div>
                    <div class="mb-3">
                        <label for="renewal_terms" class="form-label">Renewal Terms</label>
                        <textarea class="form-control" id="renewal_terms" name="renewal_terms" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="payment_schedule" class="form-label">Payment Schedule</label>
                        <textarea class="form-control" id="payment_schedule" name="payment_schedule" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" name="add_contract" class="btn btn-dashen">Add Contract</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Contract Modals - Placed outside the loop -->
<?php 
$contracts_list = $conn->query("
    SELECT c.*, v.vendor_name 
    FROM contracts c
    LEFT JOIN vendors v ON c.vendor_id = v.id
    ORDER BY c.start_date DESC
");
while ($contract = $contracts_list->fetch_assoc()): 
?>
<div class="modal fade" id="editContractModal_<?= $contract['id'] ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Contract</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="id" value="<?= $contract['id'] ?>">
                    <div class="mb-3">
                        <label for="vendor_id_<?= $contract['id'] ?>" class="form-label">Vendor</label>
                        <select class="form-select" id="vendor_id_<?= $contract['id'] ?>" name="vendor_id" required>
                            <option value="">— Select Vendor —</option>
                            <?php 
                            $vendors = $conn->query("SELECT id, vendor_name FROM vendors ORDER BY vendor_name");
                            while ($vendor = $vendors->fetch_assoc()): 
                            ?>
                            <option value="<?= $vendor['id'] ?>" <?= $vendor['id'] == $contract['vendor_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($vendor['vendor_name']) ?>
                            </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="contract_name_<?= $contract['id'] ?>" class="form-label">Contract Name</label>
                        <input type="text" class="form-control" id="contract_name_<?= $contract['id'] ?>" name="contract_name" value="<?= htmlspecialchars($contract['contract_name']) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="contract_number_<?= $contract['id'] ?>" class="form-label">Contract Number</label>
                        <input type="text" class="form-control" id="contract_number_<?= $contract['id'] ?>" name="contract_number" value="<?= htmlspecialchars($contract['contract_number']) ?>">
                    </div>
                    <!-- NEW: Type dropdown menu for edit -->
                    <div class="mb-3">
                        <label for="contract_type_<?= $contract['id'] ?>" class="form-label">Contract Type</label>
                        <select class="form-select" id="contract_type_<?= $contract['id'] ?>" name="contract_type" required>
                            <option value="">— Select Contract Type —</option>
                            <?php foreach ($contract_types as $value => $label): ?>
                                <option value="<?= $value ?>" <?= $contract['contract_type'] == $value ? 'selected' : '' ?>>
                                    <?= $label ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="start_date_<?= $contract['id'] ?>" class="form-label">Start Date</label>
                            <input type="date" class="form-control" id="start_date_<?= $contract['id'] ?>" name="start_date" value="<?= $contract['start_date'] ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="end_date_<?= $contract['id'] ?>" class="form-label">End Date</label>
                            <input type="date" class="form-control" id="end_date_<?= $contract['id'] ?>" name="end_date" value="<?= $contract['end_date'] ?>" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="total_value_<?= $contract['id'] ?>" class="form-label">Total Value</label>
                        <input type="number" step="0.01" class="form-control" id="total_value_<?= $contract['id'] ?>" name="total_value" value="<?= $contract['total_value'] ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="renewal_terms_<?= $contract['id'] ?>" class="form-label">Renewal Terms</label>
                        <textarea class="form-control" id="renewal_terms_<?= $contract['id'] ?>" name="renewal_terms" rows="3"><?= htmlspecialchars($contract['renewal_terms']) ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="payment_schedule_<?= $contract['id'] ?>" class="form-label">Payment Schedule</label>
                        <textarea class="form-control" id="payment_schedule_<?= $contract['id'] ?>" name="payment_schedule" rows="3"><?= htmlspecialchars($contract['payment_schedule']) ?></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" name="update_contract" class="btn btn-dashen">Save Changes</button>
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