<?php
// mitigation_edit.php - Edit Mitigation Action
session_start();
require_once '../db.php';

// Check authentication
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

// Function to safely output values
function e($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

// Check user role and permissions
function check_permission($conn, $user_id, $permission_type, $risk_id = null, $mitigation_id = null) {
    $permissions = [];
    
    // Get user role
    $role_query = $conn->prepare("SELECT system_role FROM users WHERE id = ?");
    $role_query->bind_param('i', $user_id);
    $role_query->execute();
    $role_result = $role_query->get_result();
    $user_role = $role_result->fetch_assoc()['system_role'] ?? 'user';
    
    // Define role-based permissions
    $role_permissions = [
        'super_admin' => ['view_all', 'edit_all', 'delete_all', 'create_risk', 'edit_risk', 'delete_risk', 
                   'create_mitigation', 'edit_mitigation', 'delete_mitigation', 'change_status'],
        'manager' => ['view_all', 'edit_all', 'create_risk', 'edit_risk', 'create_mitigation', 
                     'edit_mitigation', 'change_status'],
        'user' => ['view_own', 'edit_own', 'create_risk', 'create_mitigation_own'],
    ];
    
    // Check if user has the permission type
    if (isset($role_permissions[$user_role]) && in_array($permission_type, $role_permissions[$user_role])) {
        return true;
    }
    
    // Special checks for ownership
    if ($mitigation_id) {
        // Check if user owns the mitigation
        $mit_ownership_query = $conn->prepare("SELECT created_by, owner_user_id FROM risk_mitigations WHERE id = ?");
        $mit_ownership_query->bind_param('i', $mitigation_id);
        $mit_ownership_query->execute();
        $mit_ownership_result = $mit_ownership_query->get_result()->fetch_assoc();
        
        if ($mit_ownership_result && ($mit_ownership_result['created_by'] == $user_id || $mit_ownership_result['owner_user_id'] == $user_id)) {
            return true;
        }
    }
    
    return false;
}

if (isset($_GET['id'])) {
    $mitigation_id = (int)$_GET['id'];
    $current_user_id = $_SESSION['user_id'];
    
    // Fetch mitigation details
    $stmt = $conn->prepare("SELECT m.*, r.id as risk_id, r.title as risk_title FROM risk_mitigations m 
                           LEFT JOIN risks r ON m.risk_id = r.id 
                           WHERE m.id = ?");
    $stmt->bind_param('i', $mitigation_id);
    $stmt->execute();
    $mitigation = $stmt->get_result()->fetch_assoc();
    
    if ($mitigation) {
        // Check permission
        $can_edit_mitigation = check_permission($conn, $current_user_id, 'edit_mitigation', $mitigation['risk_id'], $mitigation_id);
        
        if (!$can_edit_mitigation) {
            $_SESSION['error'] = 'You do not have permission to edit this mitigation';
            header("Location: risk_view.php?id=" . $mitigation['risk_id']);
            exit;
        }
        
        // Fetch users for dropdown
        $users_res = $conn->query("SELECT id, username, email FROM users ORDER BY username");
        $users = $users_res ? $users_res->fetch_all(MYSQLI_ASSOC) : [];
        
        // Status options
        $status_options = [
            'open' => 'Open',
            'in_progress' => 'In Progress', 
            'done' => 'Done',
            'closed' => 'Closed'
        ];
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Edit Mitigation - Dashen Bank</title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
            <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
            <style>
                :root {
                    --dashen-primary: #273274;
                    --dashen-secondary: #1e5af5;
                    --dashen-accent: #f8a01c;
                }
                
                .bg-dashen-primary { background-color: var(--dashen-primary) !important; }
                .text-dashen-primary { color: var(--dashen-primary) !important; }
                
                .main-content {
                    margin-left: 280px;
                    transition: margin-left 0.3s ease;
                    min-height: 100vh;
                    background-color: #f8f9fa;
                }
                
                @media (max-width: 991.98px) {
                    .main-content {
                        margin-left: 0 !important;
                    }
                }
                
                .card {
                    border: none;
                    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
                    border-radius: 10px;
                }
                
                .card-header {
                    background-color: var(--dashen-primary);
                    color: white;
                    border-radius: 10px 10px 0 0 !important;
                    font-weight: 500;
                }
                
                .btn-dashen-primary {
                    background-color: var(--dashen-primary);
                    border-color: var(--dashen-primary);
                    color: white;
                }
                
                .btn-dashen-primary:hover {
                    background-color: #1e275a;
                    border-color: #1e275a;
                    color: white;
                }
                
                .form-label {
                    font-weight: 500;
                    color: #495057;
                }
                
                .status-badge {
                    padding: 0.4em 0.8em;
                    border-radius: 0.5rem;
                    font-size: 0.8em;
                    font-weight: 600;
                }
                
                .status-open { background-color: #d1ecf1; color: #0c5460; }
                .status-in_progress { background-color: #d1e7dd; color: #0f5132; }
                .status-done { background-color: #d4edda; color: #155724; }
                .status-closed { background-color: #e2e3e5; color: #383d41; }
                
                .info-card {
                    background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
                    border-left: 4px solid var(--dashen-primary);
                }
            </style>
        </head>
        <body>
            <!-- Include Sidebar -->
            <?php include 'sidebar.php'; ?>

            <!-- Main Content -->
            <div class="main-content">
                <!-- Top Navigation -->
                <nav class="navbar navbar-light bg-white border-bottom">
                    <div class="container-fluid">
                        <div class="d-flex align-items-center">
                            <h4 class="mb-0 text-dashen-primary">
                                <i class="bi bi-pencil-square me-2"></i>Edit Mitigation Action
                            </h4>
                        </div>
                        <div class="d-flex align-items-center">
                            <a href="risk_view.php?id=<?= (int)$mitigation['risk_id'] ?>" class="btn btn-outline-secondary me-2">
                                <i class="bi bi-arrow-left me-1"></i>Back to Risk
                            </a>
                            <span class="text-muted">
                                Mitigation ID: <?= $mitigation_id ?>
                            </span>
                        </div>
                    </div>
                </nav>

                <div class="container-fluid py-4">
                    <div class="row justify-content-center">
                        <div class="col-lg-8">
                            <!-- Status Messages -->
                            <?php if (isset($_SESSION['success'])): ?>
                                <div class="alert alert-success alert-dismissible fade show" role="alert">
                                    <i class="bi bi-check-circle-fill me-2"></i><?= e($_SESSION['success']) ?>
                                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                                </div>
                                <?php unset($_SESSION['success']); ?>
                            <?php endif; ?>
                            
                            <?php if (isset($_SESSION['error'])): ?>
                                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                    <i class="bi bi-exclamation-triangle-fill me-2"></i><?= e($_SESSION['error']) ?>
                                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                                </div>
                                <?php unset($_SESSION['error']); ?>
                            <?php endif; ?>

                            <!-- Risk Information -->
                            <div class="card info-card mb-4">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="text-dashen-primary mb-1">Associated Risk</h6>
                                            <h5 class="mb-0"><?= e($mitigation['risk_title']) ?></h5>
                                            <small class="text-muted">Risk ID: #<?= (int)$mitigation['risk_id'] ?></small>
                                        </div>
                                        <div class="text-end">
                                            <span class="status-badge status-<?= $mitigation['status'] ?>">
                                                <?= e(ucfirst(str_replace('_', ' ', $mitigation['status']))) ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Edit Mitigation Form -->
                            <div class="card">
                                <div class="card-header bg-dashen-primary text-white">
                                    <h6 class="mb-0"><i class="bi bi-shield-check me-2"></i>Edit Mitigation Action</h6>
                                </div>
                                <div class="card-body">
                                    <form method="post" action="risks.php" id="editMitigationForm">
                                        <input type="hidden" name="action" value="update_mitigation">
                                        <input type="hidden" name="mitigation_id" value="<?= $mitigation_id ?>">
                                        <input type="hidden" name="risk_id" value="<?= (int)$mitigation['risk_id'] ?>">
                                        
                                        <div class="row g-4">
                                            <!-- Basic Information -->
                                            <div class="col-12">
                                                <h6 class="text-dashen-primary mb-3"><i class="bi bi-info-circle me-2"></i>Basic Information</h6>
                                            </div>
                                            
                                            <div class="col-md-12">
                                                <label class="form-label">Action Title <span class="text-danger">*</span></label>
                                                <input type="text" name="title" class="form-control form-control-lg" 
                                                       value="<?= e($mitigation['title']) ?>" 
                                                       placeholder="Enter mitigation action title..." required>
                                            </div>
                                            
                                            <div class="col-12">
                                                <label class="form-label">Description</label>
                                                <textarea name="description" class="form-control" rows="4" 
                                                          placeholder="Describe the mitigation action in detail..."><?= e($mitigation['description']) ?></textarea>
                                            </div>

                                            <!-- Action Details -->
                                            <div class="col-12 mt-4">
                                                <h6 class="text-dashen-primary mb-3"><i class="bi bi-gear me-2"></i>Action Details</h6>
                                            </div>

                                            <div class="col-md-6">
                                                <label class="form-label">Owner</label>
                                                <select name="owner_user_id" class="form-select">
                                                    <option value="">-- Select Owner --</option>
                                                    <?php foreach ($users as $u): ?>
                                                        <option value="<?= (int)$u['id'] ?>" 
                                                                <?= ((int)$mitigation['owner_user_id'] === (int)$u['id']) ? 'selected' : '' ?>>
                                                            <?= e($u['username']) ?> (<?= e($u['email']) ?>)
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <small class="form-text text-muted">Assign a user responsible for this action</small>
                                            </div>
                                            
                                            <div class="col-md-6">
                                                <label class="form-label">Status</label>
                                                <select name="status" class="form-select">
                                                    <?php foreach ($status_options as $value => $label): ?>
                                                        <option value="<?= $value ?>" 
                                                                <?= $mitigation['status'] === $value ? 'selected' : '' ?>
                                                                data-badge="status-<?= $value ?>">
                                                            <?= e($label) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <small class="form-text text-muted">Current status of the mitigation action</small>
                                            </div>

                                            <div class="col-md-6">
                                                <label class="form-label">Due Date</label>
                                                <input type="date" name="due_date" class="form-control" 
                                                       value="<?= e($mitigation['due_date']) ?>"
                                                       min="<?= date('Y-m-d') ?>">
                                                <small class="form-text text-muted">Target completion date for this action</small>
                                            </div>

                                            <div class="col-md-6">
                                                <label class="form-label">Last Updated</label>
                                                <input type="text" class="form-control" 
                                                       value="<?= e($mitigation['updated_at'] ? date('M j, Y g:i A', strtotime($mitigation['updated_at'])) : 'Never') ?>" 
                                                       readonly style="background-color: #f8f9fa;">
                                                <small class="form-text text-muted">When this action was last modified</small>
                                            </div>

                                            <!-- Action Buttons -->
                                            <div class="col-12 mt-4 pt-4 border-top">
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <div>
                                                        <a href="risk_view.php?id=<?= (int)$mitigation['risk_id'] ?>" class="btn btn-outline-secondary">
                                                            <i class="bi bi-x-circle me-1"></i>Cancel
                                                        </a>
                                                    </div>
                                                    <div>
                                                        <button type="button" class="btn btn-outline-danger me-2" data-bs-toggle="modal" data-bs-target="#deleteMitigationModal">
                                                            <i class="bi bi-trash me-1"></i>Delete Action
                                                        </button>
                                                        <button type="submit" class="btn btn-dashen-primary">
                                                            <i class="bi bi-check-circle me-1"></i>Update Mitigation
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>

                            <!-- Quick Status Update -->
                            <div class="card mt-4">
                                <div class="card-header bg-light">
                                    <h6 class="mb-0"><i class="bi bi-lightning me-2"></i>Quick Status Update</h6>
                                </div>
                                <div class="card-body">
                                    <div class="row g-2">
                                        <div class="col-md-3">
                                            <a href="risks.php?action=mit_toggle&id=<?= $mitigation_id ?>&s=open&r=<?= (int)$mitigation['risk_id'] ?>" 
                                               class="btn btn-outline-info w-100 btn-sm <?= $mitigation['status'] === 'open' ? 'active' : '' ?>">
                                                <i class="bi bi-circle me-1"></i>Open
                                            </a>
                                        </div>
                                        <div class="col-md-3">
                                            <a href="risks.php?action=mit_toggle&id=<?= $mitigation_id ?>&s=in_progress&r=<?= (int)$mitigation['risk_id'] ?>" 
                                               class="btn btn-outline-primary w-100 btn-sm <?= $mitigation['status'] === 'in_progress' ? 'active' : '' ?>">
                                                <i class="bi bi-arrow-repeat me-1"></i>In Progress
                                            </a>
                                        </div>
                                        <div class="col-md-3">
                                            <a href="risks.php?action=mit_toggle&id=<?= $mitigation_id ?>&s=done&r=<?= (int)$mitigation['risk_id'] ?>" 
                                               class="btn btn-outline-success w-100 btn-sm <?= $mitigation['status'] === 'done' ? 'active' : '' ?>">
                                                <i class="bi bi-check-circle me-1"></i>Done
                                            </a>
                                        </div>
                                        <div class="col-md-3">
                                            <a href="risks.php?action=mit_toggle&id=<?= $mitigation_id ?>&s=closed&r=<?= (int)$mitigation['risk_id'] ?>" 
                                               class="btn btn-outline-secondary w-100 btn-sm <?= $mitigation['status'] === 'closed' ? 'active' : '' ?>">
                                                <i class="bi bi-archive me-1"></i>Closed
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Delete Mitigation Modal -->
            <div class="modal fade" id="deleteMitigationModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header bg-danger text-white">
                            <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>Confirm Delete</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="text-center mb-3">
                                <i class="bi bi-trash text-danger" style="font-size: 3rem;"></i>
                            </div>
                            <h6 class="text-center">Are you sure you want to delete this mitigation action?</h6>
                            <p class="text-center text-muted">"<strong><?= e($mitigation['title']) ?></strong>"</p>
                            <div class="alert alert-warning">
                                <small>
                                    <i class="bi bi-info-circle me-1"></i>
                                    This action cannot be undone. The mitigation action will be permanently removed from the system.
                                </small>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <a href="risks.php?action=delete_mitigation&id=<?= $mitigation_id ?>&r=<?= (int)$mitigation['risk_id'] ?>" class="btn btn-danger">
                                <i class="bi bi-trash me-1"></i>Delete Action
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
            <script>
                // Form validation and enhancement
                document.addEventListener('DOMContentLoaded', function() {
                    const form = document.getElementById('editMitigationForm');
                    const statusSelect = document.querySelector('select[name="status"]');
                    
                    // Form validation
                    form.addEventListener('submit', function(e) {
                        const title = document.querySelector('input[name="title"]').value.trim();
                        
                        if (!title) {
                            e.preventDefault();
                            alert('Please enter a mitigation action title');
                            document.querySelector('input[name="title"]').focus();
                            return;
                        }
                    });
                    
                    // Status change confirmation for quick actions
                    const quickActionButtons = document.querySelectorAll('.card .btn');
                    quickActionButtons.forEach(btn => {
                        btn.addEventListener('click', function(e) {
                            if (this.classList.contains('active')) {
                                e.preventDefault();
                                return;
                            }
                            
                            if (!confirm('Are you sure you want to change the status of this mitigation action?')) {
                                e.preventDefault();
                            }
                        });
                    });
                    
                    // Delete confirmation
                    const deleteModal = document.getElementById('deleteMitigationModal');
                    if (deleteModal) {
                        deleteModal.addEventListener('show.bs.modal', function() {
                            // Any additional setup for delete modal
                        });
                    }
                    
                    // Add real-time status badge preview
                    statusSelect.addEventListener('change', function() {
                        const selectedOption = this.options[this.selectedIndex];
                        const badgeClass = selectedOption.getAttribute('data-badge');
                        // You could add a live preview of the status badge here
                    });
                    
                    // Auto-focus on title field
                    document.querySelector('input[name="title"]').focus();
                    
                    // Add character counter for description
                    const descriptionTextarea = document.querySelector('textarea[name="description"]');
                    if (descriptionTextarea) {
                        const counter = document.createElement('div');
                        counter.className = 'form-text text-end';
                        descriptionTextarea.parentNode.appendChild(counter);
                        
                        function updateCounter() {
                            const length = descriptionTextarea.value.length;
                            counter.textContent = `${length} characters`;
                            
                            if (length > 1000) {
                                counter.className = 'form-text text-end text-danger';
                            } else if (length > 500) {
                                counter.className = 'form-text text-end text-warning';
                            } else {
                                counter.className = 'form-text text-end text-muted';
                            }
                        }
                        
                        descriptionTextarea.addEventListener('input', updateCounter);
                        updateCounter(); // Initial count
                    }
                });
                
                // Add animation to form elements
                document.addEventListener('DOMContentLoaded', function() {
                    const formElements = document.querySelectorAll('.form-control, .form-select');
                    formElements.forEach((element, index) => {
                        element.style.opacity = '0';
                        element.style.transform = 'translateY(10px)';
                        
                        setTimeout(() => {
                            element.style.transition = 'all 0.3s ease';
                            element.style.opacity = '1';
                            element.style.transform = 'translateY(0)';
                        }, index * 50);
                    });
                });
            </script>
        </body>
        </html>
        <?php
    } else {
        // Mitigation not found
        $_SESSION['error'] = 'Mitigation action not found';
        header('Location: risks.php');
        exit;
    }
} else {
    // Invalid request
    $_SESSION['error'] = 'Invalid request';
    header('Location: risks.php');
    exit;
}
?>