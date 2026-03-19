<?php
session_start();
include 'db.php';
$active_dashboard = 'project_management';
ob_start();
include 'sidebar.php';

// Get current user's role and ID
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['system_role'];

// Handle Add/Edit/Delete operations
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    
    // Check permissions based on role
    $can_edit = in_array($user_role, ['super_admin', 'admin', 'pm_manager', 'pm_employee']);
    
    if ($action === 'add' && $can_edit) {
        // Handle add activity
        $project_id = filter_input(INPUT_POST, 'project_id', FILTER_VALIDATE_INT);
        $phase_id = filter_input(INPUT_POST, 'phase_id', FILTER_VALIDATE_INT);
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $status = $_POST['status'] ?? '';
        $start_date = $_POST['start_date'] ?? '';
        $end_date = $_POST['end_date'] ?? '';
        
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
                $_SESSION['error'] = "You don't have permission to add activities to this project";
                header("Location: activities.php");
                exit();
            }
        }
        
        if (!$project_id || !$phase_id || empty($name)) {
            $_SESSION['error'] = "Required fields are missing";
        } else {
            try {
                $stmt = $conn->prepare("INSERT INTO activities (project_id, phase_id, name, description, status, start_date, end_date) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("iisssss", $project_id, $phase_id, $name, $description, $status, $start_date, $end_date);
                $stmt->execute();
                $_SESSION['success'] = "Activity added successfully!";
            } catch (Exception $e) {
                $_SESSION['error'] = 'Failed to add activity: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'edit' && $can_edit) {
        // Handle edit activity
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        $project_id = filter_input(INPUT_POST, 'project_id', FILTER_VALIDATE_INT);
        $phase_id = filter_input(INPUT_POST, 'phase_id', FILTER_VALIDATE_INT);
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $status = $_POST['status'] ?? '';
        $start_date = $_POST['start_date'] ?? '';
        $end_date = $_POST['end_date'] ?? '';
        
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
                $_SESSION['error'] = "You don't have permission to edit activities in this project";
                header("Location: activities.php");
                exit();
            }
        }
        
        if (!$id || !$project_id || !$phase_id || empty($name)) {
            $_SESSION['error'] = "Required fields are missing";
        } else {
            try {
                $stmt = $conn->prepare("UPDATE activities SET project_id=?, phase_id=?, name=?, description=?, status=?, start_date=?, end_date=? WHERE id=?");
                $stmt->bind_param("iisssssi", $project_id, $phase_id, $name, $description, $status, $start_date, $end_date, $id);
                $stmt->execute();
                
                // If user is pm_employee and status is completed, check if project can be finalized
                if ($user_role === 'pm_employee' && $status === 'completed') {
                    // Check if all activities in the project are completed
                    $completion_check = $conn->prepare("
                        SELECT COUNT(*) FROM activities 
                        WHERE project_id = ? AND status != 'completed'
                    ");
                    $completion_check->bind_param("i", $project_id);
                    $completion_check->execute();
                    $completion_check->bind_result($incomplete_count);
                    $completion_check->fetch();
                    $completion_check->close();
                    
                    if ($incomplete_count === 0) {
                        // Update project status to completed
                        $update_project = $conn->prepare("UPDATE projects SET status = 'completed' WHERE id = ?");
                        $update_project->bind_param("i", $project_id);
                        $update_project->execute();
                        $update_project->close();
                        
                        $_SESSION['success'] = "Activity updated successfully and project finalized!";
                    } else {
                        $_SESSION['success'] = "Activity updated successfully!";
                    }
                } else {
                    $_SESSION['success'] = "Activity updated successfully!";
                }
            } catch (Exception $e) {
                $_SESSION['error'] = 'Failed to update activity: ' . $e->getMessage();
            }
        }
    }
    
    header("Location: activities.php");
    exit();
}

// Handle delete activity
if (isset($_GET['delete_id']) && in_array($user_role, ['super_admin', 'admin', 'pm_manager'])) {
    $delete_id = (int)$_GET['delete_id'];
    
    // For pm_manager, verify they have access to the project containing this activity
    if ($user_role === 'pm_manager') {
        $access_check = $conn->prepare("
            SELECT a.project_id 
            FROM activities a
            LEFT JOIN user_assignments ua ON a.project_id = ua.project_id
            LEFT JOIN users u ON ua.user_id = u.id
            WHERE a.id = ? AND ua.user_id = ? AND u.system_role = 'pm_manager'

            
        ");
        $access_check->bind_param("ii", $delete_id, $user_id);
        $access_check->execute();
        $access_check->store_result();
        
        if ($access_check->num_rows === 0) {
            $_SESSION['error'] = "You don't have permission to delete this activity";
            header("Location: activities.php");
            exit();
        }
        $access_check->close();
    }
    
    try {
        $stmt = $conn->prepare("DELETE FROM activities WHERE id=?");
        $stmt->bind_param("i", $delete_id);
        $stmt->execute();
        $_SESSION['success'] = "Activity deleted successfully!";
    } catch (Exception $e) {
        $_SESSION['error'] = 'Delete failed: ' . $e->getMessage();
    }
    header("Location: activities.php");
    exit();
}

// Get projects based on user role
if (in_array($user_role, ['super_admin', 'admin'])) {
    // Super admins and admins can see all projects
    $projects_query = "SELECT id, name FROM projects ORDER BY name";
    $projects = $conn->query($projects_query);
    
    // Base query for activities
    $base_query = "
        SELECT a.*, p.name AS project_name, ph.name AS phase_name 
        FROM activities a
        JOIN projects p ON a.project_id = p.id
        JOIN phases ph ON a.phase_id = ph.id AND a.project_id = ph.project_id
    ";
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
    
    // Base query for activities with user assignment restriction
    $base_query = "
        SELECT a.*, p.name AS project_name, ph.name AS phase_name 
        FROM activities a
        JOIN projects p ON a.project_id = p.id
        JOIN phases ph ON a.phase_id = ph.id AND a.project_id = ph.project_id
        JOIN user_assignments ua ON p.id = ua.project_id
        WHERE ua.user_id = $user_id
    ";
}

// Pagination setup
$activities_per_page = 5;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $activities_per_page;

// Count total activities for pagination
$count_query = "SELECT COUNT(*) FROM ($base_query) AS count_table";
$count_stmt = $conn->prepare($count_query);
$count_stmt->execute();
$count_stmt->bind_result($total_activities);
$count_stmt->fetch();
$count_stmt->close();
$total_pages = ceil($total_activities / $activities_per_page);

// Get all activities with their project and phase names
$query = "$base_query ORDER BY a.id DESC LIMIT ? OFFSET ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("ii", $activities_per_page, $offset);
$stmt->execute();
$activities = $stmt->get_result();
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
    <title>Task Management - Dashen Bank</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
                Task Management
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
                    <h1><i class="fas fa-tasks me-2"></i>Task Management</h1>
                    <p class="lead mb-0">Create and manage project activities with detailed tracking</p>
                    <span class="role-badge">Role: <?= ucfirst(str_replace('_', ' ', $user_role)) ?></span>
                </div>
                <div class="col-md-4 text-md-end">
                    <?php if ($can_add): ?>
                    <button class="btn btn-light" data-bs-toggle="modal" data-bs-target="#addActivityModal">
                        <i class="fas fa-plus-circle me-1"></i> Add New Task
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Add Activity Modal -->
        <?php if ($can_add): ?>
        <div class="modal fade" id="addActivityModal" tabindex="-1" aria-labelledby="addActivityModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content">
                    <form method="POST" id="activityForm">
                        <input type="hidden" name="action" value="add">
                        <div class="modal-header" style="background-color:var(--primary-color); color:#fff;">
                            <h5 class="modal-title" id="addActivityModalLabel"><i class="fas fa-plus-circle me-2"></i>Add New Task</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                            <button type="button" class="add-another-btn" id="addAnotherBtn" title="Add another task">
                                <i class="fas fa-plus"></i>
                            </button>
                        </div>
                        <div class="modal-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="project_id" class="form-label">Project <span class="text-danger">*</span></label>
                                    <select class="form-select" id="project_id" name="project_id" required>
                                        <option value="">-- Select Project --</option>
                                        <?php
                                        $projects->data_seek(0);
                                        while ($project = $projects->fetch_assoc()): ?>
                                            <option value="<?= $project['id'] ?>"><?= htmlspecialchars($project['name']) ?></option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label for="phase_id" class="form-label">Phase <span class="text-danger">*</span></label>
                                    <select class="form-select" id="phase_id" name="phase_id" required disabled>
                                        <option value="">-- Select Phase --</option>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label for="name" class="form-label">Task Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="name" name="name" required>
                                </div>
                                <div class="col-12">
                                    <label for="description" class="form-label">Description</label>
                                    <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                                </div>
                                <div class="col-md-6">
                                    <label for="start_date" class="form-label">Start Date</label>
                                    <input type="date" class="form-control" id="start_date" name="start_date">
                                </div>
                                <div class="col-md-6">
                                    <label for="end_date" class="form-label">End Date</label>
                                    <input type="date" class="form-control" id="end_date" name="end_date">
                                </div>
                                <div class="col-md-6">
                                    <label for="status" class="form-label">Status <span class="text-danger">*</span></label>
                                    <select class="form-select" id="status" name="status" required>
                                        <option value="pending">Pending</option>
                                        <option value="in_progress">In Progress</option>
                                        <option value="completed">Completed</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer bg-light">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary-custom px-4 py-2">
                                <i class="fas fa-save me-2"></i>Save Task
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Edit Activity Modal -->
        <?php if ($can_edit): ?>
        <div class="modal fade" id="editActivityModal" tabindex="-1" aria-labelledby="editActivityModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content">
                    <form method="POST" id="editActivityForm">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="id" id="edit_id">
                        <div class="modal-header" style="background-color:var(--primary-color); color:#fff;">
                            <h5 class="modal-title" id="editActivityModalLabel"><i class="fas fa-edit me-2"></i>Edit Task</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="edit_project_id" class="form-label">Project <span class="text-danger">*</span></label>
                                    <select class="form-select" id="edit_project_id" name="project_id" required>
                                        <option value="">-- Select Project --</option>
                                        <?php
                                        $projects->data_seek(0);
                                        while ($project = $projects->fetch_assoc()): ?>
                                            <option value="<?= $project['id'] ?>"><?= htmlspecialchars($project['name']) ?></option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label for="edit_phase_id" class="form-label">Phase <span class="text-danger">*</span></label>
                                    <select class="form-select" id="edit_phase_id" name="phase_id" required>
                                        <option value="">-- Select Phase --</option>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label for="edit_name" class="form-label">Task Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="edit_name" name="name" required>
                                </div>
                                <div class="col-12">
                                    <label for="edit_description" class="form-label">Description</label>
                                    <textarea class="form-control" id="edit_description" name="description" rows="3"></textarea>
                                </div>
                                <div class="col-md-6">
                                    <label for="edit_start_date" class="form-label">Start Date</label>
                                    <input type="date" class="form-control" id="edit_start_date" name="start_date">
                                </div>
                                <div class="col-md-6">
                                    <label for="edit_end_date" class="form-label">End Date</label>
                                    <input type="date" class="form-control" id="edit_end_date" name="end_date">
                                </div>
                                <div class="col-md-6">
                                    <label for="edit_status" class="form-label">Status <span class="text-danger">*</span></label>
                                    <select class="form-select" id="edit_status" name="status" required>
                                        <option value="pending">Pending</option>
                                        <option value="in_progress">In Progress</option>
                                        <option value="completed">Completed</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer bg-light">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary-custom px-4 py-2">
                                <i class="fas fa-save me-2"></i>Update Task
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Activities Table -->
        <div class="card card-custom mt-4">
            <div class="card-header card-header-custom d-flex justify-content-between align-items-center">
                <h3 class="card-title mb-0"><i class="fas fa-list-check me-2"></i>Existing Tasks</h3>
                <span class="badge bg-light text-dark">Total: <?= $total_activities ?> Tasks</span>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Task</th>
                                <th>Project</th>
                                <th>Phase</th>
                                <th>Status</th>
                                <th>Timeline</th>
                                <?php if ($can_edit || $can_delete): ?>
                                <th>Actions</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($activities->num_rows > 0): ?>
                                <?php while ($row = $activities->fetch_assoc()): ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($row['name']) ?></strong>
                                            <?php if ($row['description']): ?>
                                                <p class="text-muted small mb-0"><?= htmlspecialchars($row['description']) ?></p>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($row['project_name']) ?></td>
                                        <td><?= htmlspecialchars($row['phase_name']) ?></td>
                                        <td>
                                            <span class="status-badge status-<?= $row['status'] ?>">
                                                <?= ucfirst(str_replace('_', ' ', $row['status'])) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="d-flex flex-column">
                                                <small>Start: <?= $row['start_date'] ? date('M d, Y', strtotime($row['start_date'])) : 'N/A' ?></small>
                                                <small>End: <?= $row['end_date'] ? date('M d, Y', strtotime($row['end_date'])) : 'N/A' ?></small>
                                            </div>
                                        </td>
                                        <?php if ($can_edit || $can_delete): ?>
                                        <td>
                                            <?php if ($can_edit): ?>
                                            <button class="btn btn-sm btn-outline-primary-custom action-btn edit-activity-btn"
                                                data-bs-toggle="modal"
                                                data-bs-target="#editActivityModal"
                                                data-id="<?= $row['id'] ?>"
                                                data-project_id="<?= $row['project_id'] ?>"
                                                data-phase_id="<?= $row['phase_id'] ?>"
                                                data-name="<?= htmlspecialchars($row['name'], ENT_QUOTES) ?>"
                                                data-description="<?= htmlspecialchars($row['description'], ENT_QUOTES) ?>"
                                                data-start_date="<?= $row['start_date'] ?>"
                                                data-end_date="<?= $row['end_date'] ?>"
                                                data-status="<?= $row['status'] ?>"
                                            >
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <?php endif; ?>
                                            <?php if ($can_delete): ?>
                                            <a href="?delete_id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-danger action-btn" onclick="return confirm('Are you sure you want to delete this activity?')">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                            <?php endif; ?>
                                        </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="<?= ($can_edit || $can_delete) ? '6' : '5' ?>" class="text-center py-4">
                                        <div class="empty-state">
                                            <i class="fas fa-folder-open"></i>
                                            <h4>No Activities Found</h4>
                                            <p>There are no activities in the system yet.</p>
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
                    <nav aria-label="Activities pagination">
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
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
    // Dynamic phase dropdown for Add Activity
    $('#project_id').change(function() {
        var projectId = $(this).val();
        var phaseSelect = $('#phase_id');
        phaseSelect.prop('disabled', true).html('<option value="">Loading phases...</option>');
        if (projectId) {
            $.get('get_phases.php', { project_id: projectId }, function(data) {
                phaseSelect.empty();
                if (data && data.length > 0) {
                    phaseSelect.append('<option value="">-- Select Phase --</option>');
                    $.each(data, function(i, phase) {
                        phaseSelect.append('<option value="' + phase.id + '">' + phase.name + '</option>');
                    });
                } else {
                    phaseSelect.append('<option value="">No phases available</option>');
                }
                phaseSelect.prop('disabled', false);
            }, 'json');
        } else {
            phaseSelect.html('<option value="">-- Select Phase --</option>').prop('disabled', true);
        }
    });

    // Fill Edit Modal with activity data
    document.querySelectorAll('.edit-activity-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            document.getElementById('edit_id').value = btn.getAttribute('data-id');
            document.getElementById('edit_project_id').value = btn.getAttribute('data-project_id');
            document.getElementById('edit_name').value = btn.getAttribute('data-name');
            document.getElementById('edit_description').value = btn.getAttribute('data-description');
            document.getElementById('edit_start_date').value = btn.getAttribute('data-start_date');
            document.getElementById('edit_end_date').value = btn.getAttribute('data-end_date');
            document.getElementById('edit_status').value = btn.getAttribute('data-status');
            
            // Load phases for selected project
            var projectId = btn.getAttribute('data-project_id');
            var phaseId = btn.getAttribute('data-phase_id');
            var phaseSelect = document.getElementById('edit_phase_id');
            phaseSelect.innerHTML = '<option value="">Loading phases...</option>';
            phaseSelect.disabled = true;
            
            $.get('get_phases.php', { project_id: projectId }, function(data) {
                phaseSelect.innerHTML = '';
                if (data && data.length > 0) {
                    phaseSelect.innerHTML += '<option value="">-- Select Phase --</option>';
                    data.forEach(function(phase) {
                        var selected = phase.id == phaseId ? 'selected' : '';
                        phaseSelect.innerHTML += '<option value="' + phase.id + '" ' + selected + '>' + phase.name + '</option>';
                    });
                } else {
                    phaseSelect.innerHTML = '<option value="">No phases available</option>';
                }
                phaseSelect.disabled = false;
                phaseSelect.value = phaseId;
            }, 'json');
        });
    });

    // Add Another Button functionality
    document.getElementById('addAnotherBtn').addEventListener('click', function() {
        // Check if all required fields are filled
        const projectId = document.getElementById('project_id').value;
        const phaseId = document.getElementById('phase_id').value;
        const name = document.getElementById('name').value;
        
        if (!projectId || !phaseId || !name) {
            alert('Please fill all required fields before adding another task.');
            return;
        }
        
        // Submit the form via AJAX
        const formData = new FormData(document.getElementById('activityForm'));
        
        fetch('activities.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            if (response.ok) {
                // Clear only the form fields, keep project and phase selection
                document.getElementById('name').value = '';
                document.getElementById('description').value = '';
                document.getElementById('start_date').value = '';
                document.getElementById('end_date').value = '';
                document.getElementById('status').value = 'pending';
                
                // Show success message
                showToast('Task added successfully! You can now add another one.', 'success');
                
                // Focus on the name field for the next entry
                document.getElementById('name').focus();
            } else {
                throw new Error('Network response was not ok');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('Error adding task. Please try again.', 'error');
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

    // Prevent double submit
    document.getElementById('activityForm')?.addEventListener('submit', function() {
        this.querySelector('button[type="submit"]').disabled = true;
    });
    
    document.getElementById('editActivityForm')?.addEventListener('submit', function() {
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
<?php ob_end_flush();?>