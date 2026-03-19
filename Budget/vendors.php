<?php
require_once '../db.php';
require_once 'header.php';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_vendor'])) {
        $vendor_name = $_POST['vendor_name'];
        $vendor_type = $_POST['vendor_type'];
        $tax_id = $_POST['tax_id'];
        $contact_person = $_POST['contact_person'];
        $contact_email = $_POST['contact_email'];
        $contact_phone = $_POST['contact_phone'];
        $payment_terms = $_POST['payment_terms'];
        
        $stmt = $conn->prepare("INSERT INTO vendors (vendor_name, vendor_type, tax_id, contact_person, contact_email, contact_phone, payment_terms) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssss", $vendor_name, $vendor_type, $tax_id, $contact_person, $contact_email, $contact_phone, $payment_terms);
        $stmt->execute();
        
        if ($stmt->affected_rows > 0) {
            $_SESSION['message'] = '<div class="alert alert-success alert-dismissible fade show">Vendor added successfully!
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>';
        } else {
            $_SESSION['message'] = '<div class="alert alert-danger alert-dismissible fade show">Error adding vendor: ' . $conn->error . '
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>';
        }
      //  header("Location: vendors.php");
        //exit();
    } elseif (isset($_POST['update_vendor'])) {
        $id = $_POST['id'];
        $vendor_name = $_POST['vendor_name'];
        $vendor_type = $_POST['vendor_type'];
        $tax_id = $_POST['tax_id'];
        $contact_person = $_POST['contact_person'];
        $contact_email = $_POST['contact_email'];
        $contact_phone = $_POST['contact_phone'];
        $payment_terms = $_POST['payment_terms'];
        
        $stmt = $conn->prepare("UPDATE vendors SET vendor_name=?, vendor_type=?, tax_id=?, contact_person=?, contact_email=?, contact_phone=?, payment_terms=? WHERE id=?");
        $stmt->bind_param("sssssssi", $vendor_name, $vendor_type, $tax_id, $contact_person, $contact_email, $contact_phone, $payment_terms, $id);
        $stmt->execute();
        
        if ($stmt->affected_rows > 0) {
            $_SESSION['message'] = '<div class="alert alert-success alert-dismissible fade show">Vendor updated successfully!
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>';
        } else {
            $_SESSION['message'] = '<div class="alert alert-danger alert-dismissible fade show">Error updating vendor: ' . $conn->error . '
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>';
        }
        header("Location: vendors.php");
        exit();
    }
}

// Handle delete action with dependency check
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    
    // Check for associated actual expenses
    $check = $conn->query("SELECT COUNT(*) as count FROM actual_expenses WHERE vendor_id = $id");
    $result = $check->fetch_assoc();
    
    if ($result['count'] > 0) {
        $_SESSION['message'] = '<div class="alert alert-danger alert-dismissible fade show">Cannot delete vendor! This vendor is associated with ' . $result['count'] . ' actual expense(s). Please reassign or delete the expenses first.
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>';
    } else {
        $stmt = $conn->prepare("DELETE FROM vendors WHERE id=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        
        if ($stmt->affected_rows > 0) {
            $_SESSION['message'] = '<div class="alert alert-success alert-dismissible fade show">Vendor deleted successfully!
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>';
        } else {
            $_SESSION['message'] = '<div class="alert alert-danger alert-dismissible fade show">Error deleting vendor: ' . $conn->error . '
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>';
        }
    }
   // header("Location: vendors.php");
    //exit();
}

// Pagination setup
$items_per_page = 10;
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($current_page - 1) * $items_per_page;

// Get total number of vendors
$total_items_query = $conn->query("SELECT COUNT(*) as total FROM vendors");
$total_items = $total_items_query->fetch_assoc()['total'];
$total_pages = ceil($total_items / $items_per_page);

// Ensure current page is within valid range
if ($current_page < 1) $current_page = 1;
if ($current_page > $total_pages && $total_pages > 0) $current_page = $total_pages;

// Fetch all vendors with pagination
$vendors = $conn->query("SELECT * FROM vendors ORDER BY vendor_name LIMIT $items_per_page OFFSET $offset");
?>

            <!-- Page content will be inserted here -->
            <div class="container-fluid">

<style>
    .btn-primary {
        background-color: #273274;
        border-color: #273274;
    }
    
    .btn-primary:hover {
        background-color: #1e2660;
        border-color: #1e2660;
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
    
    a.text-decoration-none:hover {
        color: #273274 !important;
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
        <h2 class="page-title mb-0"><i class="fas fa-store me-2"></i>Vendors</h2>
        <p class="text-muted">Manage your vendor information and contacts</p>
    </div>
    <div class="col-md-6 d-flex justify-content-end">
        <div class="me-3">
            <div class="input-group">
                <input type="text" class="form-control" placeholder="Search vendors..." id="searchInput">
                <button class="btn btn-outline-secondary" type="button" id="searchButton">
                    <i class="fas fa-search"></i>
                </button>
            </div>
        </div>
        <button class="btn btn-dashen" data-bs-toggle="modal" data-bs-target="#addVendorModal">
            <i class="fas fa-plus me-2"></i>Add Vendor
        </button>
    </div>
</div>

<div class="card card-dashen">
    <div class="card-header-dashen d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Vendors List</h5>
        <div class="dropdown">
            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" id="filterDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="fas fa-filter me-1"></i> Filter
            </button>
            <ul class="dropdown-menu" aria-labelledby="filterDropdown">
                <li><a class="dropdown-item" href="#" data-filter="all">All Vendors</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="#" data-filter="supplier">Suppliers</a></li>
                <li><a class="dropdown-item" href="#" data-filter="service">Service Providers</a></li>
                <li><a class="dropdown-item" href="#" data-filter="contractor">Contractors</a></li>
            </ul>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle" id="vendorsTable">
                <thead class="table-light">
                    <tr>
                        <th>ID</th>
                        <th>Vendor Name</th>
                        <th>Type</th>
                        <th>Contact Person</th>
                        <th>Contact Email</th>
                        <th>Contact Phone</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($vendor = $vendors->fetch_assoc()): ?>
                    <tr data-type="<?= strtolower($vendor['vendor_type']) ?>">
                        <td><?= $vendor['id'] ?></td>
                        <td>
                            <div class="d-flex align-items-center">
                                <div class="icon-circle bg-light me-3">
                                    <i class="fas fa-building text-primary"></i>
                                </div>
                                <div>
                                    <h6 class="mb-0"><?= htmlspecialchars($vendor['vendor_name']) ?></h6>
                                    <?php if ($vendor['tax_id']): ?>
                                        <small class="text-muted">Tax ID: <?= htmlspecialchars($vendor['tax_id']) ?></small>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="badge bg-light text-dark">
                                <i class="fas fa-tag me-1"></i><?= htmlspecialchars($vendor['vendor_type']) ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($vendor['contact_person']): ?>
                                <div class="d-flex align-items-center">
                                    <i class="fas fa-user me-2 text-muted"></i>
                                    <?= htmlspecialchars($vendor['contact_person']) ?>
                                </div>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($vendor['contact_email']): ?>
                                <div class="d-flex align-items-center">
                                    <i class="fas fa-envelope me-2 text-muted"></i>
                                    <a href="mailto:<?= htmlspecialchars($vendor['contact_email']) ?>" class="text-decoration-none">
                                        <?= htmlspecialchars($vendor['contact_email']) ?>
                                    </a>
                                </div>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($vendor['contact_phone']): ?>
                                <div class="d-flex align-items-center">
                                    <i class="fas fa-phone me-2 text-muted"></i>
                                    <a href="tel:<?= htmlspecialchars($vendor['contact_phone']) ?>" class="text-decoration-none">
                                        <?= htmlspecialchars($vendor['contact_phone']) ?>
                                    </a>
                                </div>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <div class="btn-group btn-group-sm" role="group">
                                <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#viewVendorModal_<?= $vendor['id'] ?>">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <button class="btn btn-outline-warning" data-bs-toggle="modal" data-bs-target="#editVendorModal_<?= $vendor['id'] ?>">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <a href="vendors.php?delete=<?= $vendor['id'] ?>" class="btn btn-outline-danger" onclick="return confirm('Are you sure you want to delete this vendor?')">
                                    <i class="fas fa-trash-alt"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <nav aria-label="Vendors pagination" class="mt-4">
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

<!-- Add Vendor Modal -->
<div class="modal fade" id="addVendorModal" tabindex="-1" aria-labelledby="addVendorModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addVendorModalLabel"><i class="fas fa-plus-circle me-2"></i>Add New Vendor</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="vendor_name" class="form-label">Vendor Name</label>
                        <input type="text" class="form-control" id="vendor_name" name="vendor_name" required>
                    </div>
                    <div class="mb-3">
                        <label for="vendor_type" class="form-label">Vendor Type</label>
                        <select class="form-select" id="vendor_type" name="vendor_type">
                            <option value="">— Select Type —</option>
                            <option value="Supplier">Supplier</option>
                            <option value="Service Provider">Service Provider</option>
                            <option value="Contractor">Contractor</option>
                            <option value="Consultant">Consultant</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="tax_id" class="form-label">Tax ID</label>
                        <input type="text" class="form-control" id="tax_id" name="tax_id">
                    </div>
                    <div class="mb-3">
                        <label for="contact_person" class="form-label">Contact Person</label>
                        <input type="text" class="form-control" id="contact_person" name="contact_person">
                    </div>
                    <div class="mb-3">
                        <label for="contact_email" class="form-label">Contact Email</label>
                        <input type="email" class="form-control" id="contact_email" name="contact_email">
                    </div>
                    <div class="mb-3">
                        <label for="contact_phone" class="form-label">Contact Phone</label>
                        <input type="tel" class="form-control" id="contact_phone" name="contact_phone">
                    </div>
                    <div class="mb-3">
                        <label for="payment_terms" class="form-label">Payment Terms</label>
                        <textarea class="form-control" id="payment_terms" name="payment_terms" rows="3" placeholder="Enter payment terms and conditions..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i> Cancel</button>
                    <button type="submit" name="add_vendor" class="btn btn-dashen"><i class="fas fa-save me-1"></i> Add Vendor</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Vendor Modals - Placed outside the loop -->
<?php 
$vendors_list = $conn->query("SELECT * FROM vendors ORDER BY vendor_name LIMIT $items_per_page OFFSET $offset");
while ($vendor = $vendors_list->fetch_assoc()): 
?>
<div class="modal fade" id="viewVendorModal_<?= $vendor['id'] ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-eye me-2"></i>Vendor Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row mb-3">
                    <div class="col-md-4 fw-semibold">Vendor Name:</div>
                    <div class="col-md-8"><?= htmlspecialchars($vendor['vendor_name']) ?></div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-4 fw-semibold">Vendor Type:</div>
                    <div class="col-md-8"><?= htmlspecialchars($vendor['vendor_type']) ?></div>
                </div>
                <?php if ($vendor['tax_id']): ?>
                <div class="row mb-3">
                    <div class="col-md-4 fw-semibold">Tax ID:</div>
                    <div class="col-md-8"><?= htmlspecialchars($vendor['tax_id']) ?></div>
                </div>
                <?php endif; ?>
                <?php if ($vendor['contact_person']): ?>
                <div class="row mb-3">
                    <div class="col-md-4 fw-semibold">Contact Person:</div>
                    <div class="col-md-8"><?= htmlspecialchars($vendor['contact_person']) ?></div>
                </div>
                <?php endif; ?>
                <?php if ($vendor['contact_email']): ?>
                <div class="row mb-3">
                    <div class="col-md-4 fw-semibold">Contact Email:</div>
                    <div class="col-md-8">
                        <a href="mailto:<?= htmlspecialchars($vendor['contact_email']) ?>" class="text-decoration-none">
                            <?= htmlspecialchars($vendor['contact_email']) ?>
                        </a>
                    </div>
                </div>
                <?php endif; ?>
                <?php if ($vendor['contact_phone']): ?>
                <div class="row mb-3">
                    <div class="col-md-4 fw-semibold">Contact Phone:</div>
                    <div class="col-md-8">
                        <a href="tel:<?= htmlspecialchars($vendor['contact_phone']) ?>" class="text-decoration-none">
                            <?= htmlspecialchars($vendor['contact_phone']) ?>
                        </a>
                    </div>
                </div>
                <?php endif; ?>
                <?php if ($vendor['payment_terms']): ?>
                <div class="row mb-3">
                    <div class="col-md-4 fw-semibold">Payment Terms:</div>
                    <div class="col-md-8"><?= nl2br(htmlspecialchars($vendor['payment_terms'])) ?></div>
                </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-dashen" data-bs-dismiss="modal" data-bs-toggle="modal" data-bs-target="#editVendorModal_<?= $vendor['id'] ?>">
                    <i class="fas fa-edit me-1"></i> Edit Vendor
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Vendor Modals - Placed outside the loop -->
<div class="modal fade" id="editVendorModal_<?= $vendor['id'] ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Vendor</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="id" value="<?= $vendor['id'] ?>">
                    <div class="mb-3">
                        <label for="vendor_name_<?= $vendor['id'] ?>" class="form-label">Vendor Name</label>
                        <input type="text" class="form-control" id="vendor_name_<?= $vendor['id'] ?>" name="vendor_name" value="<?= htmlspecialchars($vendor['vendor_name']) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="vendor_type_<?= $vendor['id'] ?>" class="form-label">Vendor Type</label>
                        <select class="form-select" id="vendor_type_<?= $vendor['id'] ?>" name="vendor_type">
                            <option value="">— Select Type —</option>
                            <option value="Supplier" <?= $vendor['vendor_type'] == 'Supplier' ? 'selected' : '' ?>>Supplier</option>
                            <option value="Service Provider" <?= $vendor['vendor_type'] == 'Service Provider' ? 'selected' : '' ?>>Service Provider</option>
                            <option value="Contractor" <?= $vendor['vendor_type'] == 'Contractor' ? 'selected' : '' ?>>Contractor</option>
                            <option value="Consultant" <?= $vendor['vendor_type'] == 'Consultant' ? 'selected' : '' ?>>Consultant</option>
                            <option value="Other" <?= $vendor['vendor_type'] == 'Other' ? 'selected' : '' ?>>Other</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="tax_id_<?= $vendor['id'] ?>" class="form-label">Tax ID</label>
                        <input type="text" class="form-control" id="tax_id_<?= $vendor['id'] ?>" name="tax_id" value="<?= htmlspecialchars($vendor['tax_id']) ?>">
                    </div>
                    <div class="mb-3">
                        <label for="contact_person_<?= $vendor['id'] ?>" class="form-label">Contact Person</label>
                        <input type="text" class="form-control" id="contact_person_<?= $vendor['id'] ?>" name="contact_person" value="<?= htmlspecialchars($vendor['contact_person']) ?>">
                    </div>
                    <div class="mb-3">
                        <label for="contact_email_<?= $vendor['id'] ?>" class="form-label">Contact Email</label>
                        <input type="email" class="form-control" id="contact_email_<?= $vendor['id'] ?>" name="contact_email" value="<?= htmlspecialchars($vendor['contact_email']) ?>">
                    </div>
                    <div class="mb-3">
                        <label for="contact_phone_<?= $vendor['id'] ?>" class="form-label">Contact Phone</label>
                        <input type="tel" class="form-control" id="contact_phone_<?= $vendor['id'] ?>" name="contact_phone" value="<?= htmlspecialchars($vendor['contact_phone']) ?>">
                    </div>
                    <div class="mb-3">
                        <label for="payment_terms_<?= $vendor['id'] ?>" class="form-label">Payment Terms</label>
                        <textarea class="form-control" id="payment_terms_<?= $vendor['id'] ?>" name="payment_terms" rows="3"><?= htmlspecialchars($vendor['payment_terms']) ?></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i> Cancel</button>
                    <button type="submit" name="update_vendor" class="btn btn-dashen"><i class="fas fa-save me-1"></i> Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endwhile; ?>

            </div><!-- /.container-fluid -->

        </div><!-- /.main-content -->
    </div><!-- /.container-fluid -->

<!-- JavaScript for enhanced functionality -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Search functionality
    const searchInput = document.getElementById('searchInput');
    const searchButton = document.getElementById('searchButton');
    const tableRows = document.querySelectorAll('#vendorsTable tbody tr');

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
                    const rowType = row.getAttribute('data-type');
                    row.style.display = rowType.includes(filterValue) ? '' : 'none';
                }
            });
        });
    });
});
</script>

</body>
</html>