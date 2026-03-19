<?php
ob_start();
session_start();

// Check authentication
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

include 'db.php';

// Get current user's role and ID
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['system_role'];

// Handle add/edit phase
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    $project_id = filter_input(INPUT_POST, 'project_id', FILTER_VALIDATE_INT);
    $phaseOrder = filter_input(INPUT_POST, 'Phase_order', FILTER_VALIDATE_INT);
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $status = $_POST['status'] ?? '';
    $start_date = $_POST['start_date'] ?? '';
    $end_date = $_POST['end_date'] ?? '';

    // Check permissions based on role
    $can_edit = in_array($user_role, ['super_admin', 'admin', 'pm_manager', 'pm_employee']);
    
    if (!$can_edit) {
        $_SESSION['error'] = "You don't have permission to perform this action";
        header("Location: phases.php");
        exit();
    }

    // For pm_manager and pm_employee, verify they have access to the project
    if (in_array($user_role, ['pm_manager', 'pm_employee'])) {
        $access_check = $conn->prepare("
             SELECT COUNT(*) from user_assignments ua LEFT JOIN users u ON u.id = ua.user_id 
                WHERE u.id = ? AND project_id = ? AND (u.system_role = 'pm_manager' OR u.system_role = 'pm_employee');
        ");
        $access_check->bind_param("ii", $user_id, $project_id);
        $access_check->execute();
        $access_check->bind_result($has_access);
        $access_check->fetch();
        $access_check->close();
        
        if (!$has_access) {
            $_SESSION['error'] = "You don't have permission to modify phases for this project";
            header("Location: phases.php");
            exit();
        }
    }

    if (!$project_id || empty($name) || empty($start_date) || empty($end_date)) {
        $_SESSION['error'] = "Required fields are missing";
        header("Location: phases.php");
        exit();
    }

    try {
        $conn->begin_transaction();
        if ($action === 'add') {
            $stmt = $conn->prepare("INSERT INTO phases (project_id, name, description, Phase_order, status, start_date, end_date) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ississs", $project_id, $name, $description, $phaseOrder, $status, $start_date, $end_date);
            $stmt->execute();
            include_once 'status_recalculator.php';
            recalculateProjectStatus($conn, $project_id);
            $_SESSION['success'] = "Phase added successfully!";
        } elseif ($action === 'edit') {
            $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
            $stmt = $conn->prepare("UPDATE phases SET project_id=?, name=?, description=?, Phase_order=?, status=?, start_date=?, end_date=? WHERE id=?");
            $stmt->bind_param("ississsi", $project_id, $name, $description, $phaseOrder, $status, $start_date, $end_date, $id);
            $stmt->execute();
            include_once 'status_recalculator.php';
            recalculateProjectStatus($conn, $project_id);
            $_SESSION['success'] = "Phase updated successfully!";
        }
        $conn->commit();
        header("Location: phases.php");
        exit();
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = 'Transaction failed: ' . $e->getMessage();
        header("Location: phases.php");
        exit();
    }
}

// Handle delete phase
if (isset($_GET['delete_id']) && in_array($user_role, ['super_admin', 'admin', 'pm_manager'])) {
    $delete_id = (int)$_GET['delete_id'];
    
    // For pm_manager, verify they have access to the project containing this phase
    if ($user_role === 'pm_manager') {
        $access_check = $conn->prepare("
           
            SELECT ph.project_id 
            FROM phases ph
            LEFT JOIN user_assignments ua ON ph.project_id = ua.project_id
            LEFT JOIN users u ON ua.user_id = u.id
            WHERE ph.id = ? AND ua.user_id = ? AND u.system_role = 'pm_manager'

        ");
        $access_check->bind_param("ii", $delete_id, $user_id);
        $access_check->execute();
        $access_check->store_result();
        
        if ($access_check->num_rows === 0) {
            $_SESSION['error'] = "You don't have permission to delete this phase";
            header("Location: phases.php");
            exit();
        }
        $access_check->close();
    }
    
    try {
        $conn->begin_transaction();
        $stmt = $conn->prepare("DELETE FROM phases WHERE id=?");
        $stmt->bind_param("i", $delete_id);
        $stmt->execute();
        $stmt->close();
        $conn->commit();
        $_SESSION['success'] = "Phase deleted successfully!";
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = 'Delete failed: ' . $e->getMessage();
    }
    header("Location: phases.php");
    exit();
}

$active_dashboard = 'project_management';
include 'sidebar.php';

// Get filter value
$project_filter = isset($_GET['project_filter']) ? (int)$_GET['project_filter'] : null;

// Pagination setup
$phases_per_page = 5;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $phases_per_page;

// Fetch projects based on user role
if (in_array($user_role, ['super_admin', 'admin'])) {
    // Super admins and admins can see all projects
    $projects_query = "SELECT id, name FROM projects ORDER BY name";
    $projects = $conn->query($projects_query);
    
    // Base query for phases
    $base_query = "SELECT p.name AS project_name, ph.* 
                  FROM phases ph
                  JOIN projects p ON ph.project_id = p.id";
} else {
    // PM managers, employees, and viewers can only see assigned projects
    $projects_query = "
        SELECT p.id, p.name 
        FROM projects p
        JOIN user_assignments ua ON p.id = ua.project_id
        WHERE ua.user_id = ? 
        ORDER BY p.name
    ";
    $projects_stmt = $conn->prepare($projects_query);
    $projects_stmt->bind_param("i", $user_id);
    $projects_stmt->execute();
    $projects = $projects_stmt->get_result();
    
    // Base query for phases with user assignment restriction
    $base_query = "SELECT p.name AS project_name, ph.* 
                  FROM phases ph
                  JOIN projects p ON ph.project_id = p.id
                  JOIN user_assignments ua ON p.id = ua.project_id
                  WHERE ua.user_id = $user_id";
}

// Count total phases for pagination
$count_query = "SELECT COUNT(*) FROM ($base_query) AS count_table";
if ($project_filter) {
    $count_query .= " WHERE ph.project_id = ?";
    $count_stmt = $conn->prepare($count_query);
    $count_stmt->bind_param("i", $project_filter);
} else {
    $count_stmt = $conn->prepare($count_query);
}
$count_stmt->execute();
$count_stmt->bind_result($total_phases);
$count_stmt->fetch();
$count_stmt->close();

$total_pages = ceil($total_phases / $phases_per_page);

// Fetch phases with optional filter and pagination
$query = $base_query;
if ($project_filter) {
    $query .= " AND ph.project_id = ?";
}
$query .= " ORDER BY ph.start_date DESC LIMIT ? OFFSET ?";

if ($project_filter) {
    $stmt = $conn->prepare($query);
    $stmt->bind_param("iii", $project_filter, $phases_per_page, $offset);
} else {
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $phases_per_page, $offset);
}
$stmt->execute();
$phases = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$conn->close();

// Determine user permissions for UI elements
$can_add = in_array($user_role, ['super_admin', 'admin', 'pm_manager', 'pm_employee']);
$can_edit = in_array($user_role, ['super_admin', 'admin', 'pm_manager', 'pm_employee']);
$can_delete = in_array($user_role, ['super_admin', 'admin', 'pm_manager']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Phases Management - Dashen Bank</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #273274; /* Dashen Bank Blue */
            --secondary-color: #f8f9fc;
            --accent-color: #3c4c9e;
            --dark-color: #5a5c69;
            --light-color: #ffffff;
            --sidebar-width: 280px;
            --sidebar-collapsed-width: 80px;
        }
        
        body {
            background-color: var(--secondary-color);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            transition: margin-left 0.3s ease;
            margin-left: var(--sidebar-width);
        }
        
        body.sidebar-collapsed {
            margin-left: var(--sidebar-collapsed-width);
        }
        
        @media (max-width: 768px) {
            body {
                margin-left: 0;
            }
            
            body.sidebar-collapsed {
                margin-left: 0;
            }
        }
        
        .navbar-custom {
            background: var(--primary-color);
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
        }
        
        .card-custom {
            border: none;
            border-radius: 0.5rem;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.1);
            transition: transform 0.2s ease;
        }
        
        .card-custom:hover {
            transform: translateY(-5px);
        }
        
        .card-header-custom {
            background: var(--primary-color);
            color: white;
            border-bottom: none;
            border-radius: 0.5rem 0.5rem 0 0 !important;
            padding: 1rem 1.5rem;
        }
        
        .btn-primary-custom {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            color: white;
        }
        
        .btn-primary-custom:hover {
            background-color: var(--accent-color);
            border-color: var(--accent-color);
            color: white;
        }
        
        .btn-outline-primary-custom {
            color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .btn-outline-primary-custom:hover {
            background-color: var(--primary-color);
            color: white;
        }
        
        .welcome-text {
            color: var(--light-color);
            font-weight: 600;
        }
        
        .table-responsive {
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.1);
            border-radius: 0.5rem;
            overflow: hidden;
        }
        
        .table th {
            background-color: var(--primary-color);
            color: white;
            position: sticky;
            top: 0;
            padding: 1rem;
        }
        
        .table td {
            padding: 1rem;
            vertical-align: middle;
        }
        
        .hero-section {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--accent-color) 100%);
            color: white;
            border-radius: 0.5rem;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
        }
        
        .back-link {
            color: var(--primary-color);
            text-decoration: none;
            transition: all 0.3s ease;
            font-weight: 500;
        }
        
        .back-link:hover {
            color: var(--accent-color);
            transform: translateX(-3px);
        }
        
        .action-btn {
            transition: all 0.2s ease;
            border-radius: 0.375rem;
        }
        
        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
        }
        
        .description-cell {
            max-width: 300px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .pagination-container {
            display: flex;
            justify-content: center;
            margin-top: 2rem;
        }
        
        .page-item.active .page-link {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .page-link {
            color: var(--primary-color);
        }
        
        .page-link:hover {
            color: var(--accent-color);
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--dark-color);
        }
        
        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1rem;
            color: #dee2e6;
        }
        
        .status-badge {
            padding: 6px 18px;
            border-radius: 20px;
            font-size: 0.95rem;
            font-weight: 500;
            display: inline-block;
        }
        
        .status-pending {
            background-color: #fff3cd;
            color: #856404;
        }
        
        .status-in_progress {
            background-color: #cce5ff;
            color: #004085;
        }
        
        .status-completed {
            background-color: #d4edda;
            color: #155724;
        }
        
        .form-section {
            background-color: #fff;
            border-radius: 16px;
            padding: 32px;
            margin-bottom: 32px;
            box-shadow: 0 2px 8px rgba(39, 50, 116, 0.07);
        }
        
        .page-title {
            color: var(--primary-color);
            font-weight: 700;
            letter-spacing: 1px;
        }
        
        .form-label {
            color: var(--primary-color);
            font-weight: 500;
        }
        
        .alert-success {
            border-left: 6px solid var(--primary-color);
        }
        
        .alert-danger {
            border-left: 6px solid #dc3545;
        }
        
        .select-project {
            min-width: 180px;
        }
        
        .pagination .page-link {
            color: var(--primary-color);
            border-radius: 8px !important;
            border: 1px solid var(--accent-color);
        }
        
        .pagination .page-item.active .page-link {
            background-color: var(--primary-color);
            color: #fff;
            border-color: var(--primary-color);
        }
        
        .pagination .page-link:hover {
            background-color: var(--accent-color);
            color: #fff;
        }
        
        .role-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            background: rgba(255, 255, 255, 0.2);
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
        }
        
        .add-another-btn {
            position: absolute;
            right: 15px;
            top: 15px;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: var(--primary-color);
            color: white;
            border: none;
            transition: all 0.3s ease;
        }
        
        .add-another-btn:hover {
            background-color: var(--accent-color);
            transform: scale(1.1);
        }
        
        .modal-header {
            position: relative;
        }
        
        .custom-toast {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            min-width: 300px;
        }
    </style>
</head>
<body>
    <!-- Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-dark navbar-custom mb-4">
        <div class="container-fluid">
            <a class="navbar-brand" href="#">
                <img src="Images/DashenLogo1.png" alt="Dashen Bank Logo" height="30" class="me-2">
                Phase Management
            </a>
            <div class="d-flex align-items-center">
                <span class="welcome-text me-3">
                    <i class="fas fa-user-circle me-2"></i><?= htmlspecialchars($_SESSION['username']) ?>
                    <small>(<?= ucfirst(str_replace('_', ' ', $user_role)) ?>)</small>
                </span>
                <a href="logout.php" class="btn btn-outline-light">
                    <i class="fas fa-sign-out-alt me-1"></i> Logout
                </a>
            </div>
        </div>
    </nav>

    <div class="container py-4">
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?= $_SESSION['success'] ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?= $_SESSION['error'] ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <!-- Hero Section -->
        <div class="hero-section mb-4">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h1><i class="fas fa-project-diagram me-2"></i>Phase Management</h1>
                    <p class="lead mb-0">Create and manage project phases with detailed tracking</p>
                    <span class="role-badge">Role: <?= ucfirst(str_replace('_', ' ', $user_role)) ?></span>
                </div>
                <div class="col-md-4 text-md-end">
                    <?php if ($can_add): ?>
                    <button class="btn btn-light" data-bs-toggle="modal" data-bs-target="#addPhaseModal">
                        <i class="fas fa-plus-circle me-1"></i> Add New Phase
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Add Phase Modal -->
        <?php if ($can_add): ?>
        <div class="modal fade" id="addPhaseModal" tabindex="-1" aria-labelledby="addPhaseModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content">
                    <form method="POST" id="phaseForm">
                        <input type="hidden" name="action" value="add">
                        <div class="modal-header" style="background-color:var(--primary-color); color:#fff;">
                            <h5 class="modal-title" id="addPhaseModalLabel"><i class="fas fa-plus-circle me-2"></i>Add New Phase</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                            <button type="button" class="add-another-btn" id="addAnotherBtn" title="Add another phase">
                                <i class="fas fa-plus"></i>
                            </button>
                        </div>
                        <div class="modal-body">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label for="project_id" class="form-label">Project <span class="text-danger">*</span></label>
                                    <select name="project_id" class="form-select select-project" required>
                                        <option value="">-- Select Project --</option>
                                        <?php
                                        $projects->data_seek(0);
                                        while ($p = $projects->fetch_assoc()): ?>
                                            <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label for="name" class="form-label">Phase Name <span class="text-danger">*</span></label>
                                    <input type="text" name="name" class="form-control" required>
                                </div>
                                <div class="col-md-4">
                                    <label for="Phase_order" class="form-label">Order <span class="text-danger">*</span></label>
                                    <input type="number" name="Phase_order" class="form-control" required>
                                </div>
                                <div class="col-md-4">
                                    <label for="status" class="form-label">Status <span class="text-danger">*</span></label>
                                    <select name="status" class="form-select" required>
                                        <option value="pending">Pending</option>
                                        <option value="in_progress">In Progress</option>
                                        <option value="completed">Completed</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label for="start_date" class="form-label">Start Date <span class="text-danger">*</span></label>
                                    <input type="date" name="start_date" class="form-control" required>
                                </div>
                                <div class="col-md-4">
                                    <label for="end_date" class="form-label">End Date <span class="text-danger">*</span></label>
                                    <input type="date" name="end_date" class="form-control" required>
                                </div>
                                <div class="col-12">
                                    <label for="description" class="form-label">Description</label>
                                    <textarea name="description" class="form-control" rows="3"></textarea>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer bg-light">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary-custom px-4 py-2">
                                <i class="fas fa-save me-2"></i>Save Phase
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Phases Table -->
        <div class="card card-custom mb-5">
            <div class="card-header card-header-custom d-flex justify-content-between align-items-center">
                <h3 class="card-title mb-0"><i class="fas fa-list-check me-2"></i>Existing Phases</h3>
                <form method="GET" class="d-flex">
                    <select name="project_filter" class="form-select select-project me-2" onchange="this.form.submit()">
                        <option value="">All Projects</option>
                        <?php
                        $projects->data_seek(0);
                        while ($p = $projects->fetch_assoc()): ?>
                            <option value="<?= $p['id'] ?>" <?= $project_filter == $p['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($p['name']) ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </form>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Status</th>
                                <th>Start Date</th>
                                <th>End Date</th>
                                <th>Order</th>
                                <th>Project</th>
                                <?php if ($can_edit || $can_delete): ?>
                                <th>Actions</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($phases as $row): ?>
                                <tr>
                                    <td><?= htmlspecialchars($row['name']) ?></td>
                                    <td>
                                        <span class="status-badge status-<?= $row['status'] ?>">
                                            <?= ucfirst(str_replace('_', ' ', $row['status'])) ?>
                                        </span>
                                    </td>
                                    <td><?= date('M d, Y', strtotime($row['start_date'])) ?></td>
                                    <td><?= date('M d, Y', strtotime($row['end_date'])) ?></td>
                                    <td><?= $row['Phase_order'] ?></td>
                                    <td><?= htmlspecialchars($row['project_name']) ?></td>
                                    <?php if ($can_edit || $can_delete): ?>
                                    <td>
                                        <?php if ($can_edit): ?>
                                        <button 
                                            class="btn btn-sm btn-outline-primary-custom action-btn edit-btn"
                                            data-bs-toggle="modal"
                                            data-bs-target="#editPhaseModal"
                                            data-id="<?= $row['id'] ?>"
                                            data-project_id="<?= $row['project_id'] ?>"
                                            data-name="<?= htmlspecialchars($row['name'], ENT_QUOTES) ?>"
                                            data-description="<?= htmlspecialchars($row['description'], ENT_QUOTES) ?>"
                                            data-phase_order="<?= $row['Phase_order'] ?>"
                                            data-status="<?= $row['status'] ?>"
                                            data-start_date="<?= $row['start_date'] ?>"
                                            data-end_date="<?= $row['end_date'] ?>"
                                        >
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <?php endif; ?>
                                        <?php if ($can_delete): ?>
                                        <a href="?delete_id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-danger action-btn" onclick="return confirm('Are you sure you want to delete this phase?')">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                        <?php endif; ?>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($phases)): ?>
                                <tr>
                                    <td colspan="<?= ($can_edit || $can_delete) ? '7' : '6' ?>" class="text-center py-4">
                                        <div class="empty-state">
                                            <i class="fas fa-folder-open"></i>
                                            <h4>No Phases Found</h4>
                                            <p>There are no phases in the system yet.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <div class="pagination-container">
                    <nav aria-label="Phases pagination">
                        <ul class="pagination">
                            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" tabindex="-1">Previous</a>
                            </li>
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <li class="page-item <?= $page == $i ? 'active' : '' ?>">
                                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                                </li>
                            <?php endfor; ?>
                            <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">Next</a>
                            </li>
                        </ul>
                    </nav>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Edit Phase Modal -->
        <?php if ($can_edit): ?>
        <div class="modal fade" id="editPhaseModal" tabindex="-1" aria-labelledby="editPhaseModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content">
                    <form method="POST" id="editPhaseForm">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="id" id="edit_id">
                        <div class="modal-header" style="background-color:var(--primary-color); color:#fff;">
                            <h5 class="modal-title" id="editPhaseModalLabel"><i class="fas fa-edit me-2"></i>Edit Phase</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label for="edit_project_id" class="form-label">Project <span class="text-danger">*</span></label>
                                    <select name="project_id" id="edit_project_id" class="form-select select-project" required>
                                        <option value="">-- Select Project --</option>
                                        <?php
                                        $projects->data_seek(0);
                                        while ($p = $projects->fetch_assoc()): ?>
                                            <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label for="edit_name" class="form-label">Phase Name <span class="text-danger">*</span></label>
                                    <input type="text" name="name" id="edit_name" class="form-control" required>
                                </div>
                                <div class="col-md-4">
                                    <label for="edit_Phase_order" class="form-label">Order <span class="text-danger">*</span></label>
                                    <input type="number" name="Phase_order" id="edit_Phase_order" class="form-control" required>
                                </div>
                                <div class="col-md-4">
                                    <label for="edit_status" class="form-label">Status <span class="text-danger">*</span></label>
                                    <select name="status" id="edit_status" class="form-select" required>
                                        <option value="pending">Pending</option>
                                        <option value="in_progress">In Progress</option>
                                        <option value="completed">Completed</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label for="edit_start_date" class="form-label">Start Date <span class="text-danger">*</span></label>
                                    <input type="date" name="start_date" id="edit_start_date" class="form-control" required>
                                </div>
                                <div class="col-md-4">
                                    <label for="edit_end_date" class="form-label">End Date <span class="text-danger">*</span></label>
                                    <input type="date" name="end_date" id="edit_end_date" class="form-control" required>
                                </div>
                                <div class="col-12">
                                    <label for="edit_description" class="form-label">Description</label>
                                    <textarea name="description" id="edit_description" class="form-control" rows="3"></textarea>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer bg-light">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary-custom px-4 py-2">
                                <i class="fas fa-save me-2"></i>Update Phase
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Fill Edit Modal with phase data
        document.querySelectorAll('.edit-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                document.getElementById('edit_id').value = btn.getAttribute('data-id');
                document.getElementById('edit_project_id').value = btn.getAttribute('data-project_id');
                document.getElementById('edit_name').value = btn.getAttribute('data-name');
                document.getElementById('edit_description').value = btn.getAttribute('data-description');
                document.getElementById('edit_Phase_order').value = btn.getAttribute('data-phase_order');
                document.getElementById('edit_status').value = btn.getAttribute('data-status');
                document.getElementById('edit_start_date').value = btn.getAttribute('data-start_date');
                document.getElementById('edit_end_date').value = btn.getAttribute('data-end_date');
            });
        });

        // Add Another Button functionality
        document.getElementById('addAnotherBtn').addEventListener('click', function() {
            // Check if all required fields are filled
            const projectId = document.querySelector('select[name="project_id"]').value;
            const name = document.querySelector('input[name="name"]').value;
            const phaseOrder = document.querySelector('input[name="Phase_order"]').value;
            const startDate = document.querySelector('input[name="start_date"]').value;
            const endDate = document.querySelector('input[name="end_date"]').value;
            
            if (!projectId || !name || !phaseOrder || !startDate || !endDate) {
                alert('Please fill all required fields before adding another phase.');
                return;
            }
            
            // Submit the form via AJAX
            const formData = new FormData(document.getElementById('phaseForm'));
            
            fetch('phases.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (response.ok) {
                    // Clear only the form fields, keep project selection
                    document.querySelector('input[name="name"]').value = '';
                    document.querySelector('textarea[name="description"]').value = '';
                    document.querySelector('input[name="Phase_order"]').value = '';
                    document.querySelector('input[name="start_date"]').value = '';
                    document.querySelector('input[name="end_date"]').value = '';
                    document.querySelector('select[name="status"]').value = 'pending';
                    
                    // Show success message
                    showToast('Phase added successfully! You can now add another one.', 'success');
                    
                    // Focus on the name field for the next entry
                    document.querySelector('input[name="name"]').focus();
                } else {
                    throw new Error('Network response was not ok');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('Error adding phase. Please try again.', 'error');
            });
        });

        // Toast notification function
        function showToast(message, type) {
            // Remove existing toasts
            const existingToasts = document.querySelectorAll('.custom-toast');
            existingToasts.forEach(toast => toast.remove());
            
            const toast = document.createElement('div');
            toast.className = `custom-toast alert alert-${type === 'success' ? 'success' : 'danger'} alert-dismissible fade show`;
            toast.innerHTML = `
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            
            document.body.appendChild(toast);
            
            // Auto remove after 5 seconds
            setTimeout(() => {
                if (toast.parentNode) {
                    toast.remove();
                }
            }, 5000);
        }
        
        document.getElementById('phaseForm')?.addEventListener('submit', function() {
            this.querySelector('button[type="submit"]').disabled = true;
        });
        
        document.getElementById('editPhaseForm')?.addEventListener('submit', function() {
            this.querySelector('button[type="submit"]').disabled = true;
        });
        
        // Handle sidebar state
        document.addEventListener('DOMContentLoaded', function() {
            const sidebarContainer = document.getElementById('sidebarContainer');
            if (sidebarContainer && sidebarContainer.classList.contains('sidebar-collapsed')) {
                document.body.classList.add('sidebar-collapsed');
            } else {
                document.body.classList.remove('sidebar-collapsed');
            }
            
            // Listen for sidebar toggle events
            const sidebarToggler = document.getElementById('sidebarToggler');
            if (sidebarToggler) {
                sidebarToggler.addEventListener('click', function() {
                    document.body.classList.toggle('sidebar-collapsed');
                });
            }
        });
    </script>
</body>
</html>
<?php ob_end_flush(); ?>