<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

include 'db.php';
$active_dashboard = 'project_management';
include 'sidebar.php';

$username = $_SESSION['username'] ?? 'Guest';
$role = $_SESSION['system_role'] ?? 'guest';
$logged_in_user_id = $_SESSION['user_id'];
$today = date("F j, Y");

// Get allowed projects
if ($role === 'super_admin') {
    $stmt = $conn->prepare("SELECT * FROM projects");
    $stmt->execute();
    $projects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} elseif ($role === 'pm_manager' || $role === 'pm_employee') {
    $stmt = $conn->prepare("SELECT distinct p.* FROM projects p JOIN user_assignments ua ON p.id = ua.project_id WHERE ua.user_id = ?");
    $stmt->bind_param("i", $logged_in_user_id);
    $stmt->execute();
    $projects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    die("Access Denied");
}

$selected_project_id = $_GET['project_id'] ?? null;

// Load milestones for selected project
$milestones = [];
$milestone_stats = [
    'total' => 0,
    'achieved' => 0,
    'pending' => 0,
    'delayed' => 0,
    'upcoming' => 0
];

// Load phases and activities for modal dropdowns
$phases = [];
$activities = [];

if ($selected_project_id) {
    // Load phases for selected project
    $stmt = $conn->prepare("SELECT * FROM phases WHERE project_id = ?");
    $stmt->bind_param("i", $selected_project_id);
    $stmt->execute();
    $phases = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Load activities for selected project
    $stmt = $conn->prepare("SELECT a.*, p.name as phase_name FROM activities a JOIN phases p ON a.phase_id = p.id WHERE p.project_id = ?");
    $stmt->bind_param("i", $selected_project_id);
    $stmt->execute();
    $activities = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($phases as $phase) {
        if (!$phase['start_date'] || !$phase['end_date']) continue;

        // Determine status class for the phase
        $status_class = 'status-pending';
        if ($phase['status'] === 'completed') {
            $status_class = 'status-completed';
        } elseif ($phase['status'] === 'in_progress') {
            $status_class = 'status-inprogress';
        }
        
        $tasks[] = [
            'id' => 'phase_' . $phase['id'],
            'real_id' => $phase['id'],
            'name' => $phase['name'],
            'start' => $phase['start_date'],
            'end' => $phase['end_date'],
            'progress' => $phase['status'] === 'completed' ? 100 : ($phase['status'] === 'in_progress' ? 50 : 0),
            'type' => 'phase',
            'custom_class' => $status_class
        ];
    }

    // Load milestones for the project
    $stmt = $conn->prepare("SELECT m.*, ph.name as phase_name
                           FROM milestones m 
                           LEFT JOIN phases ph ON m.phase_id = ph.id 
                           WHERE m.project_id = ? 
                           ORDER BY m.target_date ASC");
    $stmt->bind_param("i", $selected_project_id);
    $stmt->execute();
    $milestones = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Calculate milestone statistics
    foreach ($milestones as $milestone) {
        $milestone_stats['total']++;
        if ($milestone['status'] === 'achieved') {
            $milestone_stats['achieved']++;
        } elseif ($milestone['status'] === 'pending') {
            $milestone_stats['pending']++;
            // Check if milestone is upcoming (within next 7 days)
            $target_date = strtotime($milestone['target_date']);
            $today_time = strtotime('today');
            $next_week = strtotime('+7 days');
            
            if ($target_date >= $today_time && $target_date <= $next_week) {
                $milestone_stats['upcoming']++;
            }
        } elseif ($milestone['status'] === 'delayed') {
            $milestone_stats['delayed']++;
        }
    }
}

// Initialize statistics
$stats = [
    'total_projects' => 0,
    'assigned_projects' => 0,
    'total_phases' => 0,
    'total_users' => 0,
    'completed_phases' => 0,
    'in_progress_phases' => 0,
    'pending_phases' => 0,
];

// Initialize arrays for charts
$project_names = [];
$project_totals = [];
$project_ids = [];

// Admin statistics
if ($role === 'super_admin') {
    // Get total projects
    $result = $conn->query("SELECT COUNT(*) AS count FROM projects");
    $stats['total_projects'] = $result ? $result->fetch_assoc()['count'] : 0;
    
    // Get total users
    $result = $conn->query("SELECT COUNT(*) AS count FROM users");
    $stats['total_users'] = $result ? $result->fetch_assoc()['count'] : 0;
    
    // Get total phases
    $result = $conn->query("SELECT COUNT(*) AS count FROM phases");
    $stats['total_phases'] = $result ? $result->fetch_assoc()['count'] : 0;
    
    // Get phase status counts
    $status_result = $conn->query("SELECT status, COUNT(*) as count FROM phases GROUP BY status");
    while ($row = $status_result->fetch_assoc()) {
        $status = strtolower($row['status']);
        switch ($status) {
            case 'completed':
                $stats['completed_phases'] = $row['count'];
                break;
            case 'in_progress':
                $stats['in_progress_phases'] = $row['count'];
                break;
            case 'pending':
                $stats['pending_phases'] = $row['count'];
                break;
        }
    }

    // Phases per Project Data
    $project_counts = $conn->query("
        SELECT p.id, p.name, COUNT(ph.id) as total
        FROM projects p
        LEFT JOIN phases ph ON p.id = ph.project_id
        GROUP BY p.id, p.name
        ORDER BY total DESC
        LIMIT 5
    ");
    if ($project_counts) {
        while ($row = $project_counts->fetch_assoc()) {
            $project_names[] = $row['name'];
            $project_totals[] = $row['total'];
            $project_ids[] = $row['id'];
        }
    }
}

if($role === 'pm_manager' || $role === 'pm_employee') {
//Get count of assigned projects
$query = $conn->prepare("
    SELECT COUNT(DISTINCT project_id) AS count
FROM user_assignments
WHERE user_id = ?

");
$query->bind_param("i", $logged_in_user_id);
$query->execute();
$result = $query->get_result();
$stats['total_projects'] = $result ? $result->fetch_assoc()['count'] : 0;
// Get total users
    $result = $conn->query("SELECT COUNT(*) AS count FROM users where system_role = 'pm_manager' || system_role = 'pm_employee' ");
    $stats['total_users'] = $result ? $result->fetch_assoc()['count'] : 0;
// Get total count of phases of the assigned projects 
$stmt = $conn->prepare("SELECT COUNT(ph.id) AS count 
FROM phases ph
JOIN projects p ON ph.project_id = p.id
JOIN project_users pu ON p.id = pu.project_id
WHERE pu.user_id = ?");
$stmt->bind_param("i", $logged_in_user_id);
$stmt->execute();
$result = $stmt->get_result();
$stats['total_phases'] = $result ? $result->fetch_assoc()['count'] : 0;
//Get total count of pending phases 
$stmt = $conn->prepare("SELECT COUNT(ph.id) AS pending_phases_count
FROM phases ph
JOIN projects p ON ph.project_id = p.id
JOIN project_users pu ON p.id = pu.project_id
WHERE pu.user_id = ?
AND ph.status = 'pending'");
$stmt->bind_param("i", $logged_in_user_id);
$stmt->execute();
$result = $stmt->get_result(); 
$stats['pending_phases'] = $result ? $result->fetch_assoc()['pending_phases_count'] : 0;

}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Project Management Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="frappe-gantt.css" rel="stylesheet">
    <script src="frappe-gantt.min.js"></script>
    <style>
        .bar-wrapper.status-completed .bar,
        .bar-wrapper.status-completed .bar-progress,
        .bar-wrapper.status-completed:hover .bar,
        .bar-wrapper.status-completed:hover .bar-progress {
            fill: #28a745 !important;
            stroke: #28a745 !important;
        }

        .gantt .bar-wrapper.status-inprogress .bar {
            fill: #ffc107 !important;
            stroke: #ffc107 !important;
        }

        .gantt .bar-wrapper.status-pending .bar {
            fill: #007bff !important;
            stroke: #007bff !important;
        }
        
         /* Milestone specific styles in Gantt chart */
        .bar-wrapper.milestone-achieved .bar {
            fill: #28a745 !important;
            stroke: #28a745 !important;
        }

        .bar-wrapper.milestone-delayed .bar {
            fill: #dc3545 !important;
            stroke: #dc3545 !important;
        }

        .bar-wrapper.milestone-pending .bar {
            fill: #007bff !important;
            stroke: #007bff !important;
        }
        
        .status-legend {
            display: flex;
            gap: 20px;
            margin-bottom: 15px;
        }
        .status-item {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .status-color {
            width: 15px;
            height: 15px;
            border-radius: 3px;
        }
        .status-pending-color {
            background-color: #007bff;
        }
        .status-inprogress-color {
            background-color: #ffc107;
        }
        .status-completed-color {
            background-color: #28a745;
        }

        .navigation-controls {
            margin-bottom: 15px;
            display: flex;
            gap: 10px;
        }
        .btn-back {
            background-color: #6c757d;
            color: white;
        }
        .breadcrumb {
            background: #f8f9fa;
            padding: 8px 15px;
            border-radius: 4px;
        }
        
        .stat-card {
            transition: transform 0.3s ease;
        }
        .stat-card:hover {
            transform: translateY(-5px);
        }
        .stat-icon {
            font-size: 1.5rem;
        }
        
        .card-custom {
            border-left: 4px solid #4e73df;
        }
        .card-header-custom {
            background-color: #4e73df;
            color: white;
            padding: 0.75rem 1.25rem;
            border-bottom: 1px solid rgba(0, 0, 0, 0.125);
        }

        /* Milestone specific styles */
        .milestone-diamond {
            width: 12px;
            height: 12px;
            background: #e83e8c;
            transform: rotate(45deg);
            display: inline-block;
            margin-right: 8px;
        }
        
        .milestone-achieved {
            color: #28a745;
        }
        
        .milestone-delayed {
            color: #dc3545;
        }
        
        .milestone-pending {
            color: #007bff;
        }
        
        .milestone-item {
            border-left: 3px solid #e83e8c;
            padding-left: 10px;
            margin-bottom: 10px;
        }

        .milestone-badge {
            font-size: 0.75em;
        }

        .welcome-banner {
            background:#191970;
            color: white;
            border-radius: 0.5rem;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
            position: relative;
            overflow: hidden;
        }

        .welcome-banner::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 100%;
            height: 200%;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 0%, rgba(255, 255, 255, 0) 70%);
        }
    </style>
</head>
<body class="bg-light">
<div class="content">
<!-- Welcome Banner -->
<div class="container-fluid mt-4">
  <div class="welcome-banner">
    <div class="row align-items-center">
      <div class="col-lg-8">
        <h1 class="display-6 fw-bold"><i class="fas fa-tachometer-alt me-2"></i>Dashboard</h1>
        <p class="lead mb-0">Welcome back, <?= htmlspecialchars($username) ?>! Today is <?= $today ?></p>
      </div>
      <div class="col-lg-4 text-lg-end">
        <span class="badge bg-light text-dark fs-6 p-2 shadow-sm">
          <i class="fas fa-user-shield me-1"></i> <?= ucfirst($role) ?>
        </span>
      </div>
    </div>
  </div>
</div>

<div class="container py-5">
    <div class="row">
        <?php if ($role === 'super_admin'|| $role === 'pm_manager'): ?>
        <!-- Admin Stats -->
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card stat-card stat-card-primary h-100">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col">
                            <h6 class="text-uppercase mb-0">Total Projects</h6>
                            <div class="stat-value text-gray-800"><?= $stats['total_projects'] ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-project-diagram stat-icon text-primary"></i>
                        </div>
                        <a href="dashboard_project_manager.php" class="stretched-link"></a>
                    </div>
                  
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card stat-card stat-card-success h-100">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col">
                            <h6 class="text-uppercase mb-0">Total Users</h6>
                            <div class="stat-value text-gray-800"><?= $stats['total_users'] ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-users stat-icon text-success"></i>
                        </div>
                    </div>
                    <a href="dashboard_project_manager.php" class="stretched-link"></a>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card stat-card stat-card-info h-100">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col">
                            <h6 class="text-uppercase mb-0">Total Phases</h6>
                            <div class="stat-value text-gray-800"><?= $stats['total_phases'] ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-flask stat-icon text-info"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card stat-card stat-card-warning h-100">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col">
                            <h6 class="text-uppercase mb-0">Pending Phases</h6>
                            <div class="stat-value text-gray-800"><?= $stats['pending_phases'] ?? 0 ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-clock stat-icon text-warning"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- End of admin stat -->
        <?php endif; ?>

        <p class="text-muted">Logged in as: <strong><?= htmlspecialchars($role) ?></strong></p>

        <form method="GET" class="mb-4">
            <label for="project_id" class="form-label">Select Project:</label>
            <select name="project_id" id="project_id" class="form-select w-50" onchange="this.form.submit()">
                <option value="">-- Select Project --</option>
                <?php foreach ($projects as $p): ?>
                    <option value="<?= $p['id'] ?>" <?= $selected_project_id == $p['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($p['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>

        <?php if ($selected_project_id): ?>
             <!-- Milestones Summary Section -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card shadow-sm">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="fas fa-flag me-2"></i>Milestones Summary</h5>
                            <div>
                                <button class="btn btn-success btn-sm me-2" onclick="updateAllMilestoneStatuses()">
                                    <i class="fas fa-sync me-1"></i>Update Statuses
                                </button>
                                <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addMilestoneModal">
                                    <i class="fas fa-plus me-1"></i>Add Milestone
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <div class="card bg-primary text-white">
                                        <div class="card-body text-center">
                                            <h4><?= $milestone_stats['total'] ?></h4>
                                            <p class="mb-0">Total Milestones</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <div class="card bg-success text-white">
                                        <div class="card-body text-center">
                                            <h4><?= $milestone_stats['achieved'] ?></h4>
                                            <p class="mb-0">Achieved</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <div class="card bg-warning text-white">
                                        <div class="card-body text-center">
                                            <h4><?= $milestone_stats['pending'] ?></h4>
                                            <p class="mb-0">Pending</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <div class="card bg-danger text-white">
                                        <div class="card-body text-center">
                                            <h4><?= $milestone_stats['delayed'] ?></h4>
                                            <p class="mb-0">Delayed</p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- All Milestones List -->
                            <div class="mt-4">
                            <h6>All Milestones</h6>
                        
                        <!-- Pagination Controls -->
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <div class="text-muted" id="milestonePaginationInfo"></div>
                                    <nav>
                                        <ul class="pagination pagination-sm mb-0" id="milestonePagination">
                                            <!-- Pagination will be generated by JavaScript -->
                                        </ul>
                                    </nav>
                                </div>
                        
                            <div class="list-group" id="milestonesList">
                                <!-- Milestones will be loaded by JavaScript -->
                            </div>
                        </div>

            <!-- Rem I just stop here -->


            <?php if (count($tasks) === 0): ?>
                <div class="alert alert-warning">No valid phases found for this project.</div>
            <?php else: ?>
                <div class="card shadow-sm">
                    <div class="card-header">
                        <h5 id="gantt_title">Phases Gantt Chart</h5>
                        <div id="breadcrumb" class="breadcrumb"></div>
                    </div>
                    <div class="card-body">
                        <div class="status-legend">
                            <div class="status-item">
                                <div class="status-color status-pending-color"></div>
                                <span>Pending</span>
                            </div>
                            <div class="status-item">
                                <div class="status-color status-inprogress-color"></div>
                                <span>In Progress</span>
                            </div>
                            <div class="status-item">
                                <div class="status-color status-completed-color"></div>
                                <span>Completed</span>
                            </div>
                            <div class="status-item">
                                <div class="milestone-diamond" style="background: #e83e8c; margin-right: 5px;"></div>
                                <span>Milestone</span>
                            </div>
                        </div>
                        <div id="navigation_controls" class="navigation-controls"></div>
                        <div id="frappe_gantt" style="min-height: 500px; border: 1px solid #ccc;"></div>
                    </div>

                    <div class="card-body">
                        <h5 class="mt-5 mb-3">Overall Status Overview</h5>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="card shadow-sm mb-3">
                                    <div class="card-header">Phase Status</div>
                                    <div class="card-body">
                                        <canvas id="phaseStatusChart"></canvas>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card shadow-sm mb-3">
                                    <div class="card-header">Activity Status</div>
                                    <div class="card-body">
                                        <canvas id="activityStatusChart"></canvas>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card shadow-sm mb-3">
                                    <div class="card-header">Sub-Activity Status</div>
                                    <div class="card-body">
                                        <canvas id="subActivityStatusChart"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($project_names) && $role === 'super_admin'): ?>
                    <div class="card-body">
                        <div class="card card-custom h-100">
                            <div class="card-header-custom">
                                <h6 class="m-0 font-weight-bold text-white"><i class="fas fa-chart-bar me-2"></i>Phases by Project (Top 5)</h6>
                            </div>
                            <div class="card-body">
                                <div class="chart-area">
                                    <canvas id="projectChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    </div>
                    </div>
                    </div>
                    </div>
                    </div>
                    <?php endif; ?>
                    <?php else: ?>
                    <div class="alert alert-info">Please select a project to view details.</div>
                    <?php endif; ?>
                    </div>
                    </div>

                    <!-- Add Milestone Modal -->
<div class="modal fade" id="addMilestoneModal" tabindex="-1" aria-labelledby="addMilestoneModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addMilestoneModalLabel">Add New Milestone</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="milestoneForm">
                    <input type="hidden" id="milestone_id" name="id">
                    <input type="hidden" id="project_id" name="project_id" value="<?= $selected_project_id ?>">
                    
                    <div class="mb-3">
                        <label for="milestone_name" class="form-label">Milestone Name *</label>
                        <input type="text" class="form-control" id="milestone_name" name="name" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="milestone_description" class="form-label">Description</label>
                        <textarea class="form-control" id="milestone_description" name="description" rows="3"></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="milestone_target_date" class="form-label">Target Date *</label>
                            <input type="date" class="form-control" id="milestone_target_date" name="target_date" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="milestone_status" class="form-label">Status</label>
                            <select class="form-select" id="milestone_status" name="status">
                                <option value="pending">Pending</option>
                                <option value="achieved">Achieved</option>
                                <option value="delayed">Delayed</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="milestone_phase" class="form-label">Link to Phase (Optional)</label>
                            <select class="form-select" id="milestone_phase" name="phase_id">
                                <option value="">-- Select Phase --</option>
                                <?php foreach ($phases as $phase): ?>
                                    <option value="<?= $phase['id'] ?>"><?= htmlspecialchars($phase['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="milestone_activity" class="form-label">Link to Activity (Optional)</label>
                            <select class="form-select" id="milestone_activity" name="activity_id">
                                <option value="">-- Select Activity --</option>
                                <?php foreach ($activities as $activity): ?>
                                    <option value="<?= $activity['id'] ?>" data-phase="<?= $activity['phase_id'] ?>">
                                        <?= htmlspecialchars($activity['name']) ?> (<?= htmlspecialchars($activity['phase_name']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveMilestone()">Save Milestone</button>
            </div>
        </div>
    </div>
</div>  

                <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

                <script>
        //JS for pagination and dynamic loading
        let currentPage = 1;
        const milestonesPerPage = 5;
        let allMilestones = <?= json_encode($milestones) ?>;

        function displayMilestonesPage(page) {
            const startIndex = (page - 1) * milestonesPerPage;
            const endIndex = startIndex + milestonesPerPage;
            const pageMilestones = allMilestones.slice(startIndex, endIndex);
            
            const milestonesList = document.getElementById('milestonesList');
            milestonesList.innerHTML = '';
            
            pageMilestones.forEach(milestone => {
                const status_class = 'milestone-' + milestone.status;
                const status_badge_class = {
                    'achieved': 'bg-success',
                    'pending': 'bg-warning',
                    'delayed': 'bg-danger'
                }[milestone.status] || 'bg-secondary';
                
                const milestoneItem = document.createElement('div');
                milestoneItem.className = 'list-group-item milestone-item';
                milestoneItem.innerHTML = `
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="flex-grow-1">
                            <span class="milestone-diamond"></span>
                            <strong class="${status_class}">${escapeHtml(milestone.name)}</strong>
                            ${milestone.description ? `<br><small class="text-muted">${escapeHtml(milestone.description)}</small>` : ''}
                            <div class="mt-1">
                                ${milestone.phase_name ? `<span class="badge bg-info me-1">Phase: ${escapeHtml(milestone.phase_name)}</span>` : ''}
                                ${milestone.activity_name ? `<span class="badge bg-info me-1">Activity: ${escapeHtml(milestone.activity_name)}</span>` : ''}
                                <span class="badge bg-secondary">Due: ${formatDate(milestone.target_date)}</span>
                                ${milestone.achieved_date ? `<span class="badge bg-success">Achieved: ${formatDate(milestone.achieved_date)}</span>` : ''}
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge ${status_badge_class} milestone-badge">${ucfirst(milestone.status)}</span>
                            <div class="btn-group">
                                ${milestone.status !== 'achieved' ? `
                                    <button class="btn btn-success btn-sm" onclick="markMilestoneAchieved(${milestone.id})">
                                        <i class="fas fa-check"></i>
                                    </button>
                                ` : ''}
                                <button class="btn btn-warning btn-sm" onclick="editMilestone(${milestone.id})">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="btn btn-danger btn-sm" onclick="deleteMilestone(${milestone.id})">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                `;
                milestonesList.appendChild(milestoneItem);
            });
            
            updatePaginationControls();
        }

                function updatePaginationControls() {
                    const totalPages = Math.ceil(allMilestones.length / milestonesPerPage);
                    const paginationInfo = document.getElementById('milestonePaginationInfo');
                    const pagination = document.getElementById('milestonePagination');
                    
                    paginationInfo.textContent = `Showing ${Math.min((currentPage - 1) * milestonesPerPage + 1, allMilestones.length)}-${Math.min(currentPage * milestonesPerPage, allMilestones.length)} of ${allMilestones.length} milestones`;
                    
                    pagination.innerHTML = '';
                    
                    // Previous button
                    const prevLi = document.createElement('li');
                    prevLi.className = `page-item ${currentPage === 1 ? 'disabled' : ''}`;
                    prevLi.innerHTML = `<a class="page-link" href="#" onclick="changePage(${currentPage - 1})">Previous</a>`;
                    pagination.appendChild(prevLi);
                    
                    // Page numbers
                    for (let i = 1; i <= totalPages; i++) {
                        const pageLi = document.createElement('li');
                        pageLi.className = `page-item ${currentPage === i ? 'active' : ''}`;
                        pageLi.innerHTML = `<a class="page-link" href="#" onclick="changePage(${i})">${i}</a>`;
                        pagination.appendChild(pageLi);
                    }
                    
                    // Next button
                    const nextLi = document.createElement('li');
                    nextLi.className = `page-item ${currentPage === totalPages ? 'disabled' : ''}`;
                    nextLi.innerHTML = `<a class="page-link" href="#" onclick="changePage(${currentPage + 1})">Next</a>`;
                    pagination.appendChild(nextLi);
                }

                function changePage(page) {
                    const totalPages = Math.ceil(allMilestones.length / milestonesPerPage);
                    if (page >= 1 && page <= totalPages) {
                        currentPage = page;
                        displayMilestonesPage(currentPage);
                    }
                }

                // Utility functions
                function escapeHtml(unsafe) {
                    return unsafe
                        .replace(/&/g, "&amp;")
                        .replace(/</g, "&lt;")
                        .replace(/>/g, "&gt;")
                        .replace(/"/g, "&quot;")
                        .replace(/'/g, "&#039;");
                }

                function formatDate(dateString) {
                    const date = new Date(dateString);
                    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
                }

                function ucfirst(str) {
                    return str.charAt(0).toUpperCase() + str.slice(1);
                }

                // Initialize pagination on page load
                document.addEventListener('DOMContentLoaded', function() {
                    if (allMilestones.length > 0) {
                        displayMilestonesPage(1);
                    }
                });



                      // Milestone Management Functions
            let currentEditingMilestone = null;

          // Also update the showAddMilestoneModal function to properly reset the form:
                function showAddMilestoneModal() {
                    currentEditingMilestone = null;
                    document.getElementById('milestoneForm').reset();
                    document.getElementById('milestone_id').value = '';
                    document.getElementById('milestone_status').value = 'pending';
                    document.getElementById('milestone_phase').value = '';
                    document.getElementById('milestone_activity').value = '';
                    document.getElementById('addMilestoneModalLabel').textContent = 'Add New Milestone';
                    
                    const modal = new bootstrap.Modal(document.getElementById('addMilestoneModal'));
                    modal.show();
                }

            function editMilestone(milestoneId) {
                    // Reset form first to clear any previous data
                    document.getElementById('milestoneForm').reset();
                    
                    fetch(`milestones_api.php?project_id=<?= $selected_project_id ?>`)
                        .then(response => response.json())
                        .then(result => {
                            if (result.success && result.data) {
                                const milestone = result.data.find(m => m.id == milestoneId);
                                if (milestone) {
                                    currentEditingMilestone = milestoneId;
                                    
                                    // Populate form with milestone data
                                    document.getElementById('milestone_id').value = milestone.id;
                                    document.getElementById('milestone_name').value = milestone.name || '';
                                    document.getElementById('milestone_description').value = milestone.description || '';
                                    document.getElementById('milestone_target_date').value = milestone.target_date || '';
                                    document.getElementById('milestone_status').value = milestone.status || 'pending';
                                    document.getElementById('milestone_phase').value = milestone.phase_id || '';
                                    document.getElementById('milestone_activity').value = milestone.activity_id || '';
                                    
                                    document.getElementById('addMilestoneModalLabel').textContent = 'Edit Milestone';
                                    
                                    const modal = new bootstrap.Modal(document.getElementById('addMilestoneModal'));
                                    modal.show();
                                } else {
                                    alert('Milestone not found');
                                }
                            } else {
                                alert('Error loading milestone data: ' + (result.error || 'Unknown error'));
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            alert('Error loading milestone data');
                        });
                }
            function saveMilestone() {
                const form = document.getElementById('milestoneForm');
                const formData = new FormData(form);
                const data = Object.fromEntries(formData.entries());
                
                // Convert empty strings to null for optional fields
                if (!data.phase_id) data.phase_id = null;
                if (!data.activity_id) data.activity_id = null;
                if (!data.description) data.description = '';
                
                const url = 'milestones_api.php';
                const method = currentEditingMilestone ? 'PUT' : 'POST';
                
                fetch(url, {
                    method: method,
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(data)
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        alert(result.message || 'Milestone saved successfully!');
                        const modal = bootstrap.Modal.getInstance(document.getElementById('addMilestoneModal'));
                        modal.hide();
                        location.reload();
                    } else {
                        alert('Error saving milestone: ' + result.error);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error saving milestone');
                });
            }

            function markMilestoneAchieved(milestoneId) {
                if (confirm('Mark this milestone as achieved?')) {
                    updateMilestoneStatus(milestoneId, 'achieved');
                }
            }

            function updateMilestoneStatus(milestoneId, status) {
                const updateData = {
                    id: milestoneId,
                    status: status
                };
                
                if (status === 'achieved') {
                    updateData.achieved_date = new Date().toISOString().split('T')[0];
                }
                
                fetch('milestones_api.php', {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(updateData)
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        alert('Milestone status updated successfully!');
                        location.reload();
                    } else {
                        alert('Error updating milestone: ' + result.error);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error updating milestone');
                });
            }

            function deleteMilestone(milestoneId) {
                if (confirm('Are you sure you want to delete this milestone?')) {
                    fetch(`milestones_api.php?id=${milestoneId}`, {
                        method: 'DELETE'
                    })
                    .then(response => response.json())
                    .then(result => {
                        if (result.success) {
                            alert('Milestone deleted successfully!');
                            location.reload();
                        } else {
                            alert('Error deleting milestone: ' + result.error);
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('Error deleting milestone');
                    });
                }
            }

            function updateAllMilestoneStatuses() {
                if (confirm('Update all milestone statuses automatically? This will check for delayed milestones and auto-achieve completed ones.')) {
                    fetch('update_milestone_statuses.php')
                        .then(response => response.json())
                        .then(result => {
                            if (result.success) {
                                alert(`Milestone statuses updated successfully!\n\nUpdates made:\n- Delayed: ${result.updates.delayed}\n- From Activities: ${result.updates.from_activities}\n- From Phases: ${result.updates.from_phases}\n- From Projects: ${result.updates.from_projects}`);
                                location.reload();
                            } else {
                                alert('Error updating milestone statuses: ' + result.error);
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            alert('Error updating milestone statuses');
                        });
                }
            }
                   // Enhanced Gantt Chart to include milestones
    const navigationHistory = [];
    let currentLevel = 'phases';
    let currentParentId = <?= $selected_project_id ?>;
    const phases = <?= json_encode($tasks) ?>;
    const milestones = <?= json_encode($milestones) ?>;

    function updateBreadcrumb() {
        const breadcrumb = document.getElementById('breadcrumb');
        if (navigationHistory.length === 0) {
            breadcrumb.innerHTML = 'Project Phases';
            return;
        }
        
        let html = '<a href="#" onclick="navigateToLevel(\'phases\')">Phases</a>';
        navigationHistory.forEach((item, index) => {
            html += ` &raquo; `;
            if (index === navigationHistory.length - 1) {
                html += `<span>${item.name}</span>`;
            } else {
                html += `<a href="#" onclick="navigateToLevel('${item.type}', ${item.id})">${item.name}</a>`;
            }
        });
        breadcrumb.innerHTML = html;
    }

    function updateNavigationControls() {
        const controls = document.getElementById('navigation_controls');
        controls.innerHTML = '';
        if (navigationHistory.length > 0) {
            const backButton = document.createElement('button');
            backButton.className = 'btn btn-back';
            backButton.innerHTML = '&larr; Back';
            backButton.onclick = navigateBack;
            controls.appendChild(backButton);
        }
    }

    function navigateBack() {
        if (navigationHistory.length > 0) {
            navigationHistory.pop();
            const prevItem = navigationHistory[navigationHistory.length - 1];
            
            if (!prevItem) {
                currentLevel = 'phases';
                currentParentId = <?= $selected_project_id ?>;
                drawGantt(phases, "Phases Timeline");
            } else {
                currentLevel = prevItem.type + 's';
                currentParentId = prevItem.id;
                
                if (prevItem.type === 'phase') {
                    loadActivities(prevItem.id);
                } else if (prevItem.type === 'activity') {
                    loadSubActivities(prevItem.id);
                }
            }
            updateBreadcrumb();
            updateNavigationControls();
        }
    }

    function navigateToLevel(level, id) {
        const index = navigationHistory.findIndex(item => 
            item.type === level.replace('s', '') && item.id === id
        );
        navigationHistory.length = index + 1;
        
        if (level === 'phases') {
            currentLevel = 'phases';
            currentParentId = <?= $selected_project_id ?>;
            drawGantt(phases, "Phases Timeline");
        } else if (level === 'activities') {
            currentLevel = 'activities';
            currentParentId = id;
            loadActivities(id);
        }
        updateBreadcrumb();
        updateNavigationControls();
    }

        // Replace the entire drawGantt function with this corrected version
function drawGantt(tasks, title) {
    const container = document.getElementById('frappe_gantt');
    container.innerHTML = "";
    
    // Create a clean copy of tasks to avoid modifying the original array
    const ganttTasks = [...tasks];
    
    // Add milestones to the Gantt chart ONLY if we're at the project level (phases view)
    // and ONLY if we haven't added them already
    if (currentLevel === 'phases' && milestones.length > 0) {
        // Check if milestones are already in the tasks array to avoid duplicates
        const existingMilestoneIds = new Set();
        ganttTasks.forEach(task => {
            if (task.type === 'milestone') {
                existingMilestoneIds.add(task.real_id);
            }
        });
        
        // Only add milestones that aren't already in the tasks array
        milestones.forEach(milestone => {
            if (!existingMilestoneIds.has(milestone.id)) {
                const milestoneClass = `milestone-${milestone.status}`;
                
                ganttTasks.push({
                    id: 'milestone_' + milestone.id,
                    real_id: milestone.id,
                    name: '🔶' + milestone.name,
                    start: milestone.target_date,
                    end: milestone.target_date,
                    progress: milestone.status === 'achieved' ? 100 : 0,
                    dependencies: '',
                    type: 'milestone',
                    custom_class: milestoneClass
                });
            }
        });
    }
    
    // Clear any existing Gantt chart before creating a new one
    while (container.firstChild) {
        container.removeChild(container.firstChild);
    }

    try {
        const gantt = new Gantt(container, ganttTasks, {
            view_mode: 'Day',
            on_click: (task) => {
                console.log("Clicked:", task);
                if (task.type === 'phase') {
                    loadActivities(task.real_id);
                } else if (task.type === 'activity') {
                    loadSubActivities(task.real_id);
                } else if (task.type === 'milestone') {
                    // Show milestone details in a modal
                    const milestone = milestones.find(m => m.id === task.real_id);
                    if (milestone) {
                        alert(`Milestone: ${milestone.name}\nStatus: ${milestone.status}\nTarget Date: ${milestone.target_date}\nDescription: ${milestone.description || 'No description'}`);
                    }
                }
            },
            on_date_change: (task, start, end) => {
                console.log("Changed:", task);
                if (task.type !== 'milestone') {
                    updateDates(task, start, end);
                }
            }
        });
        
        document.getElementById('gantt_title').innerText = title;
    } catch (error) {
        console.error('Error creating Gantt chart:', error);
        container.innerHTML = '<div class="alert alert-danger">Error loading Gantt chart. Please refresh the page.</div>';
    }
}

// Also update the navigateBack function to ensure proper state management
function navigateBack() {
    if (navigationHistory.length > 0) {
        navigationHistory.pop();
        const prevItem = navigationHistory[navigationHistory.length - 1];
        
        if (!prevItem) {
            currentLevel = 'phases';
            currentParentId = <?= $selected_project_id ?>;
            // Force a clean redraw of phases without duplicate milestones
            drawGantt([...phases], "Phases Timeline");
        } else {
            currentLevel = prevItem.type + 's';
            currentParentId = prevItem.id;
            
            if (prevItem.type === 'phase') {
                loadActivities(prevItem.id);
            } else if (prevItem.type === 'activity') {
                loadSubActivities(prevItem.id);
            }
        }
        updateBreadcrumb();
        updateNavigationControls();
    }
}
                    function loadActivities(phaseId) {
                        fetch('load_activities.php?phase_id=' + phaseId)
                            .then(res => res.json())
                            .then(data => {
                                const phase = phases.find(p => p.real_id === phaseId);
                                if (phase && !navigationHistory.some(item => item.id === phaseId)) {
                                    navigationHistory.push({
                                        type: 'phase',
                                        id: phaseId,
                                        name: phase.name
                                    });
                                }
                                
                                updateBreadcrumb();
                                updateNavigationControls();
                                
                                const acts = data.map(act => {
                                    let status_class = 'status-pending';
                                    if (act.status === 'completed') {
                                        status_class = 'status-completed';
                                    } else if (act.status === 'in_progress') {
                                        status_class = 'status-inprogress';
                                    }

                                    return {
                                        id: 'activity_' + act.id,
                                        real_id: act.id,
                                        name: act.name,
                                        start: act.start_date,
                                        end: act.end_date,
                                        progress: act.status === 'completed' ? 100 : (act.status === 'in_progress' ? 50 : 0),
                                        dependencies: act.depends_on ? 'activity_' + act.depends_on : '',
                                        type: 'activity',
                                        custom_class: status_class
                                    };
                                });
                                drawGantt(acts, `Activities for ${phase.name}`);
                            })
                            .catch(error => {
                                console.error('Error loading activities:', error);
                                alert('Failed to load activities. Please try again.');
                            });
                    }

                    function loadSubActivities(activityId) {
                        fetch('load_subactivities.php?activity_id=' + activityId)
                            .then(res => res.json())
                            .then(data => {
                                const activityName = data[0]?.parent_name || `Activity #${activityId}`;
                                const lastHistoryItem = navigationHistory[navigationHistory.length - 1];
                                if (!lastHistoryItem || lastHistoryItem.type !== 'activity' || lastHistoryItem.id !== activityId) {
                                    navigationHistory.push({
                                        type: 'activity',
                                        id: activityId,
                                        name: activityName
                                    });
                                }
                                
                                updateBreadcrumb();
                                updateNavigationControls();
                                
                                const subs = data.map(sub => {
                                    let status_class = 'status-pending';
                                    if (sub.status === 'completed') {
                                        status_class = 'status-completed';
                                    } else if (sub.status === 'in_progress') {
                                        status_class = 'status-inprogress';
                                    }

                                    return {
                                        id: 'sub_' + sub.id,
                                        real_id: sub.id,
                                        name: sub.name,
                                        start: sub.start_date,
                                        end: sub.end_date,
                                        progress: sub.status === 'completed' ? 100 : (sub.status === 'in_progress' ? 50 : 0),
                                        dependencies: '',
                                        type: 'sub-activity',
                                        custom_class: status_class
                                    };
                                });
                                drawGantt(subs, `Sub-Activities for ${activityName}`);
                            })
                            .catch(error => {
                                console.error('Error loading sub-activities:', error);
                                alert('Failed to load sub-activities. Please try again.');
                            });
                    }

                    function updateDates(task, start, end) {
                        const formData = new FormData();
                        formData.append('id', task.real_id);
                        formData.append('type', task.type);
                        formData.append('start_date', start.toISOString().split('T')[0]);
                        formData.append('end_date', end.toISOString().split('T')[0]);

                        fetch('update_dates.php', {
                            method: 'POST',
                            body: formData
                        })
                        .then(res => res.text())
                        .then(data => {
                            console.log("Update:", data);
                            // Optionally show a success message
                        })
                        .catch(error => {
                            console.error('Error updating dates:', error);
                            alert('Failed to update dates. Please try again.');
                        });
                    }

                    // Chart Functions
                    const chartColors = {
                        completed: '#28a745',
                        in_progress: '#ffc107',
                        pending: '#007bff'
                    };

                    function createStatusChart(chartId, title, data) {
                        const ctx = document.getElementById(chartId).getContext('2d');
                        new Chart(ctx, {
                            type: 'doughnut',
                            data: {
                                labels: ['Completed', 'In Progress', 'Pending'],
                                datasets: [{
                                    data: [data.completed || 0, data.in_progress || 0, data.pending || 0],
                                    backgroundColor: [
                                        chartColors.completed,
                                        chartColors.in_progress,
                                        chartColors.pending
                                    ],
                                    hoverOffset: 4
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                plugins: {
                                    title: {
                                        display: true,
                                        text: title,
                                        font: {
                                            size: 16
                                        }
                                    },
                                    tooltip: {
                                        callbacks: {
                                            label: function(context) {
                                                const label = context.label || '';
                                                const value = context.raw;
                                                const total = context.dataset.data.reduce((acc, val) => acc + val, 0);
                                                const percentage = total > 0 ? ((value / total) * 100).toFixed(1) + '%' : '0%';
                                                return `${label}: ${value} (${percentage})`;
                                            }
                                        }
                                    }
                                }
                            }
                        });
                    }

                    async function loadAndRenderStatusCharts(projectId) {
                        try {
                            const response = await fetch(`load_status_summary.php?project_id=${projectId}`);
                            if (!response.ok) {
                                throw new Error(`HTTP error! status: ${response.status}`);
                            }
                            const summaryData = await response.json();

                            if (summaryData.error) {
                                console.error("Error loading status summary:", summaryData.error);
                                return;
                            }

                            createStatusChart('phaseStatusChart', 'Phases', summaryData.phases || {});
                            createStatusChart('activityStatusChart', 'Activities', summaryData.activities || {});
                            createStatusChart('subActivityStatusChart', 'Sub-Activities', summaryData.sub_activities || {});

                        } catch (error) {
                            console.error("Failed to load status summary for charts:", error);
                            // Display error message on the dashboard
                            const chartsContainer = document.querySelector('.card-body');
                            if (chartsContainer) {
                                chartsContainer.insertAdjacentHTML('beforeend', 
                                    '<div class="alert alert-danger">Failed to load status charts. Please try again later.</div>');
                            }
                        }
                    }

                    // Bar Chart for Projects
                    <?php if (!empty($project_names) && $role === 'super_admin'): ?>
                    function renderProjectBarChart() {
                        const projectCtx = document.getElementById('projectChart').getContext('2d');
                        new Chart(projectCtx, {
                            type: 'bar',
                            data: {
                                labels: <?= json_encode($project_names) ?>,
                                datasets: [{
                                    label: 'Phases Count',
                                    data: <?= json_encode($project_totals) ?>,
                                    backgroundColor: '#4e73df',
                                    hoverBackgroundColor: '#2e59d9',
                                    hoverBorderColor: "rgba(234, 236, 244, 1)",
                                }]
                            },
                            options: {
                                maintainAspectRatio: false,
                                scales: {
                                    x: {
                                        grid: {
                                            display: false,
                                            drawBorder: false
                                        },
                                        ticks: {
                                            maxRotation: 45,
                                            minRotation: 45
                                        }
                                    },
                                    y: {
                                        beginAtZero: true,
                                        grid: {
                                            color: "rgb(234, 236, 244)",
                                            drawBorder: false
                                        },
                                        ticks: {
                                            precision: 0
                                        }
                                    }
                                },
                                plugins: {
                                    legend: {
                                        display: false
                                    },
                                    tooltip: {
                                        backgroundColor: "rgb(255,255,255)",
                                        bodyColor: "#858796",
                                        borderColor: '#dddfeb',
                                        borderWidth: 1,
                                        padding: 15,
                                        callbacks: {
                                            afterLabel: function(context) {
                                                const projectId = <?= json_encode($project_ids) ?>[context.dataIndex];
                                                return 'Click to view project';
                                            }
                                        }
                                    }
                                }
                            }
                        });
                    }
                    <?php endif; ?>

                    // Initialize components
            document.addEventListener('DOMContentLoaded', function() {
            if (typeof phases !== 'undefined') {
                drawGantt(phases, "Phases Timeline");
                updateBreadcrumb();
                updateNavigationControls();
            }
                       <?php if ($selected_project_id): ?>
            loadAndRenderStatusCharts(<?= $selected_project_id ?>);
        <?php endif; ?>
        
        <?php if (!empty($project_names) && $role === 'super_admin'): ?>
            renderProjectBarChart();
        <?php endif; ?>
    });
                </script>
</div>

</body>
</html>