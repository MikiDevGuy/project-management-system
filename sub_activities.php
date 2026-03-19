<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

include 'db.php';

// Get current user's role and ID
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['system_role'];

// Handle Add/Edit/Delete logic
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    
    // Check permissions based on role
    $can_edit = in_array($user_role, ['super_admin', 'admin', 'pm_manager', 'pm_employee']);
    
    if (!$can_edit) {
        $_SESSION['error'] = "You don't have permission to perform this action";
        header("Location: sub_activities.php");
        exit();
    }
    
    if ($action === 'add' || $action === 'edit') {
        $project_id = filter_input(INPUT_POST, 'projectId', FILTER_VALIDATE_INT);
        $phase_id = filter_input(INPUT_POST, 'phaseId', FILTER_VALIDATE_INT);
        $activity_id = filter_input(INPUT_POST, 'activity_id', FILTER_VALIDATE_INT);
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $assigned_to = filter_input(INPUT_POST, 'assigned_to', FILTER_VALIDATE_INT);
        $status = $_POST['status'] ?? '';
        $start_date = $_POST['start_date'] ?? '';
        $end_date = $_POST['end_date'] ?? '';

        // For pm_manager and pm_employee, verify they have access to the project
        if (in_array($user_role, ['pm_manager', 'pm_employee'])) {
            $access_check = $conn->prepare("
                SELECT COUNT(*) FROM user_assignments ua
                    LEFT JOIN users u ON u.id = ua.user_id
                WHERE u.id = ? AND project_id = ? AND (u.system_role = 'pm_manager' OR u.system_role = 'pm_employee')

                 
            ");
            $access_check->bind_param("ii", $user_id, $project_id);
            $access_check->execute();
            $access_check->bind_result($has_access);
            $access_check->fetch();
            $access_check->close();
            
            if (!$has_access) {
                $_SESSION['error'] = "You don't have permission to modify sub-activities for this project";
                header("Location: sub_activities.php");
                exit();
            }
        }

        if (!$project_id || !$phase_id || !$activity_id || empty($name) || !$assigned_to || empty($start_date) || empty($end_date)) {
            $_SESSION['error'] = "Required fields are missing";
            header("Location: sub_activities.php");
            exit();
        }

        try {
            $conn->begin_transaction();
            if ($action === 'add') {
                $stmt = $conn->prepare("INSERT INTO sub_activities (project_id, phase_id, activity_id, name, description, assigned_to, status, start_date, end_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("iiissssss", $project_id, $phase_id, $activity_id, $name, $description, $assigned_to, $status, $start_date, $end_date);
                $stmt->execute();
                $_SESSION['success'] = "Sub-activity added successfully!";
            } elseif ($action === 'edit') {
                $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
                $stmt = $conn->prepare("UPDATE sub_activities SET project_id=?, phase_id=?, activity_id=?, name=?, description=?, assigned_to=?, status=?, start_date=?, end_date=? WHERE id=?");
                $stmt->bind_param("iiissssssi", $project_id, $phase_id, $activity_id, $name, $description, $assigned_to, $status, $start_date, $end_date, $id);
                $stmt->execute();
                $_SESSION['success'] = "Sub-activity updated successfully!";
            }
            $conn->commit();
            header("Location: sub_activities.php");
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error'] = 'Transaction failed: ' . $e->getMessage();
            header("Location: sub_activities.php");
            exit();
        }
    }
}

// Handle delete sub-activity
if (isset($_GET['delete_id']) && in_array($user_role, ['super_admin', 'admin', 'pm_manager'])) {
    $delete_id = (int)$_GET['delete_id'];
    
    // For pm_manager, verify they have access to the project containing this sub-activity
    if ($user_role === 'pm_manager') {
        $access_check = $conn->prepare("
            SELECT s.project_id FROM sub_activities s
            LEFT JOIN  user_assignments ua ON s.project_id = ua.project_id
            LEFT JOIN users u ON ua.user_id = u.id
            WHERE s.id = ? AND ua.user_id = ? AND u.system_role = 'pm_manager'

        ");
        $access_check->bind_param("ii", $delete_id, $user_id);
        $access_check->execute();
        $access_check->store_result();
        
        if ($access_check->num_rows === 0) {
            $_SESSION['error'] = "You don't have permission to delete this sub-activity";
            header("Location: sub_activities.php");
            exit();
        }
        $access_check->close();
    }
    
    try {
        $conn->begin_transaction();
        $stmt = $conn->prepare("DELETE FROM sub_activities WHERE id=?");
        $stmt->bind_param("i", $delete_id);
        $stmt->execute();
        $stmt->close();
        $conn->commit();
        $_SESSION['success'] = "Sub-activity deleted successfully!";
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = 'Delete failed: ' . $e->getMessage();
    }
    header("Location: sub_activities.php");
    exit();
}

// Get filter values from GET request
$project_filter = filter_input(INPUT_GET, 'project_filter', FILTER_VALIDATE_INT);
$phase_filter = filter_input(INPUT_GET, 'phase_filter', FILTER_VALIDATE_INT);
$activity_filter = filter_input(INPUT_GET, 'activity_filter', FILTER_VALIDATE_INT);

// Pagination setup
$subs_per_page = 5;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $subs_per_page;

// Fetch projects based on user role
if (in_array($user_role, ['super_admin', 'admin'])) {
    // Super admins and admins can see all projects
    $projects_query = "SELECT id, name FROM projects ORDER BY name";
    $projects_stmt = $conn->prepare($projects_query);
    $projects_stmt->execute();
    $projects = $projects_stmt->get_result();
    
    // Base query for sub-activities
    $base_query = "SELECT s.*, a.name AS activity_name, p.name AS phase_name, pr.name AS project_name, 
                  u.username AS assigned_username
                  FROM sub_activities s
                  JOIN activities a ON s.activity_id = a.id
                  JOIN phases p ON s.phase_id = p.id
                  JOIN projects pr ON s.project_id = pr.id
                  LEFT JOIN users u ON s.assigned_to = u.id";
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
    
    // Base query for sub-activities with user assignment restriction
    $base_query = "SELECT s.*, a.name AS activity_name, p.name AS phase_name, pr.name AS project_name, 
                  u.username AS assigned_username
                  FROM sub_activities s
                  JOIN activities a ON s.activity_id = a.id
                  JOIN phases p ON s.phase_id = p.id
                  JOIN projects pr ON s.project_id = pr.id
                  LEFT JOIN users u ON s.assigned_to = u.id
                  JOIN user_assignments ua ON pr.id = ua.project_id
                  WHERE ua.user_id = $user_id";
}

// Fetch phases for the selected project (if any)
$phases = [];
if ($project_filter) {
    $stmt = $conn->prepare("SELECT id, name FROM phases WHERE project_id = ? ORDER BY name");
    $stmt->bind_param("i", $project_filter);
    $stmt->execute();
    $phases = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Fetch activities for the selected phase (if any)
$activities = [];
if ($phase_filter) {
    $stmt = $conn->prepare("SELECT id, name FROM activities WHERE phase_id = ? ORDER BY name");
    $stmt->bind_param("i", $phase_filter);
    $stmt->execute();
    $activities = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Count total sub-activities for pagination
$count_query = "SELECT COUNT(*) FROM ($base_query) AS count_table";
$where = [];
$params = [];
$types = '';

if ($project_filter) {
    $where[] = "s.project_id = ?";
    $params[] = $project_filter;
    $types .= 'i';
}
if ($phase_filter) {
    $where[] = "s.phase_id = ?";
    $params[] = $phase_filter;
    $types .= 'i';
}
if ($activity_filter) {
    $where[] = "s.activity_id = ?";
    $params[] = $activity_filter;
    $types .= 'i';
}
if (!empty($where)) {
    $count_query .= " WHERE " . implode(" AND ", $where);
}
$count_stmt = $conn->prepare($count_query);
if (!empty($params)) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$count_stmt->bind_result($total_subs);
$count_stmt->fetch();
$count_stmt->close();

$total_pages = ceil($total_subs / $subs_per_page);

// Build query for sub-activities with filters and pagination
$query = $base_query;
if (!empty($where)) {
    $query .= " AND " . implode(" AND ", $where);
}
$query .= " ORDER BY s.id DESC LIMIT ? OFFSET ?";
$params[] = $subs_per_page;
$params[] = $offset;
$types .= 'ii';

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$subs = $stmt->get_result();

// Fetch users for assignment dropdown
$users_query = "SELECT id, username FROM users ORDER BY username";
$users_stmt = $conn->prepare($users_query);
$users_stmt->execute();
$users = $users_stmt->get_result();

$conn->close();
$active_dashboard = 'project_management';
include 'sidebar.php';

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
    <title>Sub-Activities Management - Dashen Bank</title>
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
        
        .user-badge {
            display: inline-flex;
            align-items: center;
            background-color: #e9ecef;
            padding: 0.35rem 0.75rem;
            border-radius: 50px;
            font-size: 0.85rem;
        }
        
        .user-avatar {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background-color: var(--primary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 0.5rem;
            font-size: 0.7rem;
            font-weight: bold;
        }
        
        .filter-section {
            background-color: var(--secondary-color);
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .filter-label {
            font-weight: 500;
            margin-bottom: 5px;
        }
        
        .filter-btn {
            height: 42px;
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
    </style>
</head>
<body>
    <!-- Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-dark navbar-custom mb-4">
        <div class="container-fluid">
            <a class="navbar-brand" href="#">
                <img src="Images/DashenLogo1.png" alt="Dashen Bank Logo" height="30" class="me-2">
                Sub-Activity Management
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
                    <h1><i class="fas fa-tasks me-2"></i>Sub-Activities Management</h1>
                    <p class="lead mb-0">Create and manage detailed sub-activities with assignments and tracking</p>
                    <span class="role-badge">Role: <?= ucfirst(str_replace('_', ' ', $user_role)) ?></span>
                </div>
                <div class="col-md-4 text-md-end">
                    <?php if ($can_add): ?>
                    <button class="btn btn-light" data-bs-toggle="modal" data-bs-target="#addSubActivityModal">
                        <i class="fas fa-plus-circle me-1"></i> Add Sub-Activity
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Add Sub-Activity Modal -->
        <?php if ($can_add): ?>
        <div class="modal fade" id="addSubActivityModal" tabindex="-1" aria-labelledby="addSubActivityModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content">
                    <form method="POST" class="modal-content" id="addSubActivityForm">
                        <input type="hidden" name="action" value="add">
                        <div class="modal-header" style="background-color:var(--primary-color); color:#fff;">
                            <h5 class="modal-title" id="addSubActivityModalLabel"><i class="fas fa-plus-circle me-2"></i>Add New Sub-Activity</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                            <button type="button" class="add-another-btn" id="addAnotherBtn" title="Add another sub-activity">
                                <i class="fas fa-plus"></i>
                            </button>
                        </div>
                        <div class="modal-body">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label for="project_select" class="form-label">Project <span class="text-danger">*</span></label>
                                    <select class="form-select select-project" id="project_select" name="projectId" required>
                                        <option value="">-- Select Project --</option>
                                        <?php $projects->data_seek(0); while ($p = $projects->fetch_assoc()): ?>
                                            <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label for="phase_select" class="form-label">Phase <span class="text-danger">*</span></label>
                                    <select class="form-select" id="phase_select" name="phaseId" required>
                                        <option value="">-- Select Phase --</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label for="activity_select" class="form-label">Activity <span class="text-danger">*</span></label>
                                    <select class="form-select" id="activity_select" required>
                                        <option value="">-- Select Activity --</option>
                                    </select>
                                </div>
                            </div>
                            <input type="hidden" id="hidden_activity_id" name="activity_id" value="">
                            <div id="sub_activity_fields" class="row g-3 mt-4" style="display: none;">
                                <div class="col-md-6">
                                    <label for="name" class="form-label">Sub-Activity Name <span class="text-danger">*</span></label>
                                    <input type="text" name="name" class="form-control" id="name" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="assigned_to" class="form-label">Assigned To <span class="text-danger">*</span></label>
                                    <select name="assigned_to" class="form-select" id="assigned_to" required>
                                        <option value="">-- Select User --</option>
                                        <?php $users->data_seek(0); while ($u = $users->fetch_assoc()): ?>
                                            <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['username']) ?></option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label for="description" class="form-label">Description</label>
                                    <textarea name="description" class="form-control" id="description" rows="3"></textarea>
                                </div>
                                <div class="col-md-4">
                                    <label for="start_date" class="form-label">Start Date <span class="text-danger">*</span></label>
                                    <input type="date" name="start_date" class="form-control" id="start_date" required>
                                </div>
                                <div class="col-md-4">
                                    <label for="end_date" class="form-label">End Date <span class="text-danger">*</span></label>
                                    <input type="date" name="end_date" class="form-control" id="end_date" required>
                                </div>
                                <div class="col-md-4">
                                    <label for="status" class="form-label">Status <span class="text-danger">*</span></label>
                                    <select name="status" class="form-select" id="status" required>
                                        <option value="pending">Pending</option>
                                        <option value="in_progress">In Progress</option>
                                        <option value="completed">Completed</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer bg-light">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary-custom" id="save_button">
                                <i class="fas fa-save me-2"></i> Save Sub-Activity
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Edit Sub-Activity Modal -->
        <?php if ($can_edit): ?>
        <div class="modal fade" id="editSubActivityModal" tabindex="-1" aria-labelledby="editSubActivityModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content">
                    <form method="POST" class="modal-content" id="editSubActivityForm">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="id" id="edit_id">
                        <div class="modal-header" style="background-color:var(--primary-color); color:#fff;">
                            <h5 class="modal-title" id="editSubActivityModalLabel"><i class="fas fa-edit me-2"></i>Edit Sub-Activity</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label for="edit_project_id" class="form-label">Project <span class="text-danger">*</span></label>
                                    <select name="projectId" id="edit_project_id" class="form-select select-project" required>
                                        <option value="">-- Select Project --</option>
                                        <?php $projects->data_seek(0); while ($p = $projects->fetch_assoc()): ?>
                                            <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label for="edit_phase_id" class="form-label">Phase <span class="text-danger">*</span></label>
                                    <select name="phaseId" id="edit_phase_id" class="form-select" required>
                                        <option value="">-- Select Phase --</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label for="edit_activity_id_select" class="form-label">Activity <span class="text-danger">*</span></label>
                                    <select id="edit_activity_id_select" class="form-select" required>
                                        <option value="">-- Select Activity --</option>
                                    </select>
                                </div>
                            </div>
                            <input type="hidden" id="edit_activity_id" name="activity_id" value="">
                            <div id="edit_sub_activity_fields" class="row g-3 mt-4">
                                <div class="col-md-6">
                                    <label for="edit_name" class="form-label">Sub-Activity Name <span class="text-danger">*</span></label>
                                    <input type="text" name="name" class="form-control" id="edit_name" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="edit_assigned_to" class="form-label">Assigned To <span class="text-danger">*</span></label>
                                    <select name="assigned_to" class="form-select" id="edit_assigned_to" required>
                                        <option value="">-- Select User --</option>
                                        <?php $users->data_seek(0); while ($u = $users->fetch_assoc()): ?>
                                            <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['username']) ?></option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label for="edit_description" class="form-label">Description</label>
                                    <textarea name="description" class="form-control" id="edit_description" rows="3"></textarea>
                                </div>
                                <div class="col-md-4">
                                    <label for="edit_start_date" class="form-label">Start Date <span class="text-danger">*</span></label>
                                    <input type="date" name="start_date" class="form-control" id="edit_start_date" required>
                                </div>
                                <div class="col-md-4">
                                    <label for="edit_end_date" class="form-label">End Date <span class="text-danger">*</span></label>
                                    <input type="date" name="end_date" class="form-control" id="edit_end_date" required>
                                </div>
                                <div class="col-md-4">
                                    <label for="edit_status" class="form-label">Status <span class="text-danger">*</span></label>
                                    <select name="status" class="form-select" id="edit_status" required>
                                        <option value="pending">Pending</option>
                                        <option value="in_progress">In Progress</option>
                                        <option value="completed">Completed</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer bg-light">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary-custom">
                                <i class="fas fa-save me-2"></i> Update Sub-Activity
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Sub-Activities Table -->
        <div class="card card-custom mb-5">
            <div class="card-header card-header-custom d-flex justify-content-between align-items-center">
                <h3 class="card-title mb-0"><i class="fas fa-list-check me-2"></i>Existing Sub-Activities</h3>
            </div>
            <div class="card-body">
                <div class="filter-section mb-4">
                    <form method="GET" class="filter-form">
                        <div class="row g-3 align-items-end">
                            <div class="col-md-3">
                                <label for="project_filter" class="form-label filter-label">Project</label>
                                <select name="project_filter" id="project_filter" class="form-select" onchange="this.form.submit()">
                                    <option value="">All Projects</option>
                                    <?php $projects->data_seek(0); while ($p = $projects->fetch_assoc()): ?>
                                        <option value="<?= $p['id'] ?>" <?= $project_filter == $p['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($p['name']) ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="phase_filter" class="form-label filter-label">Phase</label>
                                <select name="phase_filter" id="phase_filter" class="form-select" <?= !$project_filter ? 'disabled' : '' ?> onchange="this.form.submit()">
                                    <option value="">All Phases</option>
                                    <?php if ($project_filter): ?>
                                        <?php foreach ($phases as $phase): ?>
                                            <option value="<?= $phase['id'] ?>" <?= $phase_filter == $phase['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($phase['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="activity_filter" class="form-label filter-label">Activity</label>
                                <select name="activity_filter" id="activity_filter" class="form-select" <?= !$phase_filter ? 'disabled' : '' ?> onchange="this.form.submit()">
                                    <option value="">All Activities</option>
                                    <?php if ($phase_filter): ?>
                                        <?php foreach ($activities as $activity): ?>
                                            <option value="<?= $activity['id'] ?>" <?= $activity_filter == $activity['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($activity['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <button type="button" class="btn btn-outline-secondary filter-btn w-100" onclick="window.location.href='sub_activities.php'">
                                    <i class="fas fa-times me-2"></i>Clear Filters
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Sub-Activity</th>
                                <th>Activity</th>
                                <th>Phase</th>
                                <th>Project</th>
                                <th>Status</th>
                                <th>Assigned To</th>
                                <th>Timeline</th>
                                <?php if ($can_edit || $can_delete): ?>
                                <th>Actions</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($subs && $subs->num_rows > 0): ?>
                                <?php while ($row = $subs->fetch_assoc()): ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($row['name']) ?></strong>
                                            <?php if (!empty($row['description'])): ?>
                                                <p class="text-muted mb-0 small"><?= htmlspecialchars($row['description']) ?></p>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($row['activity_name']) ?></td>
                                        <td><?= htmlspecialchars($row['phase_name']) ?></td>
                                        <td><?= htmlspecialchars($row['project_name']) ?></td>
                                        <td>
                                            <span class="status-badge status-<?= $row['status'] ?>">
                                                <?= ucfirst(str_replace('_', ' ', $row['status'])) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($row['assigned_username']): ?>
                                                <span class="user-badge">
                                                    <span class="user-avatar"><?= substr($row['assigned_username'], 0, 1) ?></span>
                                                    <?= htmlspecialchars($row['assigned_username']) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">Not assigned</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($row['start_date'] && $row['end_date']): ?>
                                                <?= date('M d, Y', strtotime($row['start_date'])) ?> - 
                                                <?= date('M d, Y', strtotime($row['end_date'])) ?>
                                            <?php else: ?>
                                                <span class="text-muted">Not set</span>
                                            <?php endif; ?>
                                        </td>
                                        <?php if ($can_edit || $can_delete): ?>
                                        <td>
                                            <?php if ($can_edit): ?>
                                            <button 
                                                class="btn btn-sm btn-outline-primary-custom action-btn edit-btn"
                                                data-bs-toggle="modal"
                                                data-bs-target="#editSubActivityModal"
                                                data-id="<?= $row['id'] ?>"
                                                data-project_id="<?= $row['project_id'] ?>"
                                                data-phase_id="<?= $row['phase_id'] ?>"
                                                data-activity_id="<?= $row['activity_id'] ?>"
                                                data-name="<?= htmlspecialchars($row['name'], ENT_QUOTES) ?>"
                                                data-description="<?= htmlspecialchars($row['description'], ENT_QUOTES) ?>"
                                                data-assigned_to="<?= $row['assigned_to'] ?>"
                                                data-status="<?= $row['status'] ?>"
                                                data-start_date="<?= $row['start_date'] ?>"
                                                data-end_date="<?= $row['end_date'] ?>"
                                            >
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <?php endif; ?>
                                            <?php if ($can_delete): ?>
                                            <a href="sub_activities.php?delete_id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-danger action-btn" onclick="return confirm('Are you sure you want to delete this sub-activity?')">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                            <?php endif; ?>
                                        </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="<?= ($can_edit || $can_delete) ? '8' : '7' ?>" class="text-center py-4">
                                        <div class="empty-state">
                                            <i class="fas fa-folder-open"></i>
                                            <h4>No Sub-Activities Found</h4>
                                            <p><?= ($project_filter || $phase_filter || $activity_filter) ? 'Try adjusting your filters.' : 'There are no sub-activities in the system yet.' ?></p>
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
                    <nav aria-label="Sub-Activities pagination">
                        <ul class="pagination">
                            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" tabindex="-1">Previous</a>
                            </li>
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <li class="page_item <?= $page == $i ? 'active' : '' ?>">
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
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Handle sidebar state
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
            
            // Add Modal dynamic dropdowns
            const projectSelect = document.getElementById('project_select');
            const phaseSelect = document.getElementById('phase_select');
            const activitySelect = document.getElementById('activity_select');
            const hiddenActivityId = document.getElementById('hidden_activity_id');
            const subActivityFields = document.getElementById('sub_activity_fields');
            const saveButton = document.getElementById('save_button');
            const formInputs = subActivityFields.querySelectorAll('input, select, textarea');
            const addAnotherBtn = document.getElementById('addAnotherBtn');
            const addSubActivityForm = document.getElementById('addSubActivityForm');

            function toggleFormFields(enable) {
                subActivityFields.style.display = enable ? 'block' : 'none';
                formInputs.forEach(input => {
                    input.disabled = !enable;
                });
                if (saveButton) {
                    saveButton.disabled = !enable;
                }
            }
            toggleFormFields(false);

            function updateDropdowns(target, options) {
                target.innerHTML = '<option value="">-- Select ' + target.name.replace('Id', '').replace('_', ' ') + ' --</option>';
                options.forEach(option => {
                    const opt = document.createElement('option');
                    opt.value = option.id;
                    opt.textContent = option.name;
                    target.appendChild(opt);
                });
                target.disabled = false;
            }

            projectSelect.addEventListener('change', function() {
                const projectId = this.value;
                phaseSelect.innerHTML = '<option value="">-- Select Phase --</option>';
                activitySelect.innerHTML = '<option value="">-- Select Activity --</option>';
                phaseSelect.disabled = !projectId;
                activitySelect.disabled = true;
                toggleFormFields(false);
                hiddenActivityId.value = '';
                if (projectId) {
                    fetch(`get_phases.php?project_id=${projectId}`)
                        .then(response => response.json())
                        .then(phases => {
                            updateDropdowns(phaseSelect, phases);
                        });
                }
            });

            phaseSelect.addEventListener('change', function() {
                const phaseId = this.value;
                activitySelect.innerHTML = '<option value="">-- Select Activity --</option>';
                activitySelect.disabled = !phaseId;
                toggleFormFields(false);
                hiddenActivityId.value = '';
                if (phaseId) {
                    fetch(`get_activities.php?phase_id=${phaseId}`)
                        .then(response => response.json())
                        .then(activities => {
                            updateDropdowns(activitySelect, activities);
                        });
                }
            });

            activitySelect.addEventListener('change', function() {
                const activityId = this.value;
                hiddenActivityId.value = activityId;
                toggleFormFields(!!activityId);
            });

            // Add Another Button functionality
            addAnotherBtn.addEventListener('click', function() {
                // Check if all required fields are filled
                const projectId = projectSelect.value;
                const phaseId = phaseSelect.value;
                const activityId = activitySelect.value;
                const name = document.getElementById('name').value;
                const assignedTo = document.getElementById('assigned_to').value;
                const startDate = document.getElementById('start_date').value;
                const endDate = document.getElementById('end_date').value;
                
                if (!projectId || !phaseId || !activityId || !name || !assignedTo || !startDate || !endDate) {
                    alert('Please fill all required fields before adding another sub-activity.');
                    return;
                }
                
                // Submit the form via AJAX
                const formData = new FormData(addSubActivityForm);
                
                fetch('sub_activities.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    if (response.ok) {
                        // Clear only the form fields, keep project, phase, and activity selection
                        document.getElementById('name').value = '';
                        document.getElementById('description').value = '';
                        document.getElementById('assigned_to').value = '';
                        document.getElementById('start_date').value = '';
                        document.getElementById('end_date').value = '';
                        document.getElementById('status').value = 'pending';
                        
                        // Show success message
                        showToast('Sub-activity added successfully! You can now add another one.', 'success');
                        
                        // Focus on the name field for the next entry
                        document.getElementById('name').focus();
                    } else {
                        throw new Error('Network response was not ok');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showToast('Error adding sub-activity. Please try again.', 'error');
                });
            });

            // Toast notification function
            function showToast(message, type) {
                // Remove existing toasts
                const existingToasts = document.querySelectorAll('.custom-toast');
                existingToasts.forEach(toast => toast.remove());
                
                const toast = document.createElement('div');
                toast.className = `custom-toast alert alert-${type === 'success' ? 'success' : 'danger'} position-fixed`;
                toast.style.top = '20px';
                toast.style.right = '20px';
                toast.style.zIndex = '9999';
                toast.style.minWidth = '300px';
                toast.innerHTML = `
                    ${message}
                    <button type="button" class="btn-close float-end" data-bs-dismiss="alert"></button>
                `;
                
                document.body.appendChild(toast);
                
                // Auto remove after 5 seconds
                setTimeout(() => {
                    if (toast.parentNode) {
                        toast.remove();
                    }
                }, 5000);
            }

            // Edit Modal dynamic dropdowns and fill fields
            document.querySelectorAll('.edit-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    document.getElementById('edit_id').value = btn.getAttribute('data-id');
                    document.getElementById('edit_name').value = btn.getAttribute('data-name');
                    document.getElementById('edit_description').value = btn.getAttribute('data-description');
                    document.getElementById('edit_assigned_to').value = btn.getAttribute('data-assigned_to');
                    document.getElementById('edit_status').value = btn.getAttribute('data-status');
                    document.getElementById('edit_start_date').value = btn.getAttribute('data-start_date');
                    document.getElementById('edit_end_date').value = btn.getAttribute('data-end_date');
                    document.getElementById('edit_project_id').value = btn.getAttribute('data-project_id');
                    // Fetch phases for project
                    fetch(`get_phases.php?project_id=${btn.getAttribute('data-project_id')}`)
                        .then(response => response.json())
                        .then(phases => {
                            const phaseSelect = document.getElementById('edit_phase_id');
                            updateDropdowns(phaseSelect, phases);
                            phaseSelect.value = btn.getAttribute('data-phase_id');
                            // Fetch activities for phase
                            fetch(`get_activities.php?phase_id=${btn.getAttribute('data-phase_id')}`)
                                .then(response => response.json())
                                .then(activities => {
                                    const activitySelect = document.getElementById('edit_activity_id_select');
                                    updateDropdowns(activitySelect, activities);
                                    activitySelect.value = btn.getAttribute('data-activity_id');
                                    document.getElementById('edit_activity_id').value = btn.getAttribute('data-activity_id');
                                });
                        });
                });
            });

            document.getElementById('edit_phase_id').addEventListener('change', function() {
                const phaseId = this.value;
                fetch(`get_activities.php?phase_id=${phaseId}`)
                    .then(response => response.json())
                    .then(activities => {
                        const activitySelect = document.getElementById('edit_activity_id_select');
                        updateDropdowns(activitySelect, activities);
                        document.getElementById('edit_activity_id').value = '';
                    });
            });

            document.getElementById('edit_activity_id_select').addEventListener('change', function() {
                document.getElementById('edit_activity_id').value = this.value;
            });
        });
    </script>
</body>
</html>