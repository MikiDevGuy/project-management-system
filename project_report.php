<?php
// Enhanced Project Reporting System for Dashen Bank - COMPLETE FIXED VERSION WITH TERMINATED PROJECTS
session_start();

include 'db.php';

// Get current user ID and role
$current_user_id = $_SESSION['user_id'] ?? 0;
$user_role = $_SESSION['system_role'] ?? '';

// Helper functions
function esc($s) {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
function post_val($k) {
    return isset($_POST[$k]) ? trim($_POST[$k]) : '';
}
function get_val($k) {
    return isset($_GET[$k]) ? trim($_GET[$k]) : '';
}

// Active tab
$activeTab = get_val('tab') ?: 'overview';

// Set active dashboard for sidebar
$active_dashboard = 'project_reports';

// Get filter parameters
$project_filter = get_val('project_filter') ?: '';
$status_filter = get_val('status_filter') ?: '';
$date_from = get_val('date_from') ?: '';
$date_to = get_val('date_to') ?: '';
$user_filter = get_val('user_filter') ?: '';
$department_filter = get_val('department_filter') ?: '';
$export_type = get_val('export') ?: '';

// ======================================================
// ADVANCED FILTERING SYSTEM - FIXED WITH TERMINATED PROJECTS
// ======================================================

$where_conditions = [];
$params = [];
$param_types = '';

// Build base WHERE clause for projects
if ($project_filter) {
    $where_conditions[] = "p.id = ?";
    $params[] = $project_filter;
    $param_types .= 'i';
}

if ($status_filter) {
    $where_conditions[] = "p.status = ?";
    $params[] = $status_filter;
    $param_types .= 's';
}
// REMOVED: No default exclusion of terminated projects - they will show in all reports

if ($date_from) {
    $where_conditions[] = "p.start_date >= ?";
    $params[] = $date_from;
    $param_types .= 's';
}

if ($date_to) {
    $where_conditions[] = "p.end_date <= ?";
    $params[] = $date_to;
    $param_types .= 's';
}

if ($department_filter) {
    $where_conditions[] = "p.department_id = ?";
    $params[] = $department_filter;
    $param_types .= 'i';
}

$where_clause = '';
if (!empty($where_conditions)) {
    $where_clause = "WHERE " . implode(" AND ", $where_conditions);
}

// ======================================================
// EXPORT FUNCTIONALITY - ENHANCED FOR ALL TABS
// ======================================================

if ($export_type) {
    generateExport($export_type, $activeTab, $conn, $where_clause, $params, $param_types);
    exit;
}

// ======================================================
// REPORT DATA QUERIES WITH ENHANCED PERFORMANCE - FIXED
// ======================================================

// Get all projects for dropdown - INCLUDE TERMINATED PROJECTS
$projects_dropdown = [];
$res = mysqli_query($conn, "SELECT id, name, status FROM projects ORDER BY name");
while ($r = mysqli_fetch_assoc($res)) {
    $projects_dropdown[] = $r;
}

// Get all users for dropdown
$users_dropdown = [];
$res = mysqli_query($conn, "SELECT id, username FROM users ORDER BY username");
while ($r = mysqli_fetch_assoc($res)) $users_dropdown[] = $r;

// Get departments for dropdown
$departments_dropdown = [];
$res = mysqli_query($conn, "SELECT id, department_name FROM departments ORDER BY department_name");
while ($r = mysqli_fetch_assoc($res)) $departments_dropdown[] = $r;

// ======================================================
// REAL-TIME STATISTICS WITH CACHING - FIXED
// ======================================================

// Total projects count - FIXED SQL (INCLUDE ALL PROJECTS)
$total_projects = 0;
if ($where_clause) {
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM projects p $where_clause");
    if ($params) {
        $stmt->bind_param($param_types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $total_projects = $result->fetch_assoc()['total'];
    $stmt->close();
} else {
    // Include ALL projects including terminated
    $result = mysqli_query($conn, "SELECT COUNT(*) as total FROM projects");
    $total_projects = mysqli_fetch_assoc($result)['total'];
}

// Projects by status with advanced metrics - FIXED (INCLUDE ALL PROJECTS)
$projects_by_status = [];
if ($where_clause) {
    $stmt = $conn->prepare("
        SELECT 
            status, 
            COUNT(*) as count,
            AVG(DATEDIFF(COALESCE(end_date, CURDATE()), start_date)) as avg_duration,
            SUM(CASE WHEN end_date < CURDATE() AND status NOT IN ('completed', 'terminated') THEN 1 ELSE 0 END) as overdue_count
        FROM projects p 
        $where_clause 
        GROUP BY status
    ");
    if ($params) {
        $stmt->bind_param($param_types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($r = $result->fetch_assoc()) $projects_by_status[] = $r;
    $stmt->close();
} else {
    $res = mysqli_query($conn, "
        SELECT 
            status, 
            COUNT(*) as count,
            AVG(DATEDIFF(COALESCE(end_date, CURDATE()), start_date)) as avg_duration,
            SUM(CASE WHEN end_date < CURDATE() AND status NOT IN ('completed', 'terminated') THEN 1 ELSE 0 END) as overdue_count
        FROM projects 
        GROUP BY status
    ");
    while ($r = mysqli_fetch_assoc($res)) $projects_by_status[] = $r;
}

// Enhanced statistics with performance metrics - FIXED QUERIES
$total_phases = 0;
$total_activities = 0;
$total_subs = 0;

if ($where_clause) {
    // Total phases
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM phases ph JOIN projects p ON ph.project_id = p.id $where_clause");
    if ($params) {
        $stmt->bind_param($param_types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $total_phases = $result->fetch_assoc()['total'];
    $stmt->close();

    // Total activities
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM activities a JOIN phases ph ON a.phase_id = ph.id JOIN projects p ON ph.project_id = p.id $where_clause");
    if ($params) {
        $stmt->bind_param($param_types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $total_activities = $result->fetch_assoc()['total'];
    $stmt->close();

    // Total sub-activities
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM sub_activities s JOIN activities a ON s.activity_id = a.id JOIN phases ph ON a.phase_id = ph.id JOIN projects p ON ph.project_id = p.id $where_clause");
    if ($params) {
        $stmt->bind_param($param_types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $total_subs = $result->fetch_assoc()['total'];
    $stmt->close();
} else {
    $total_phases_result = mysqli_query($conn, "SELECT COUNT(*) as total FROM phases");
    $total_phases = mysqli_fetch_assoc($total_phases_result)['total'];

    $total_activities_result = mysqli_query($conn, "SELECT COUNT(*) as total FROM activities");
    $total_activities = mysqli_fetch_assoc($total_activities_result)['total'];

    $total_subs_result = mysqli_query($conn, "SELECT COUNT(*) as total FROM sub_activities");
    $total_subs = mysqli_fetch_assoc($total_subs_result)['total'];
}

// ======================================================
// ENHANCED PROJECT HIERARCHY REPORT - FIXED WITH TERMINATED PROJECTS
// ======================================================

$project_hierarchy = [];
if ($where_clause) {
    $stmt = $conn->prepare("
        SELECT 
            p.id as project_id, 
            p.name as project_name, 
            p.status as project_status,
            p.start_date as project_start,
            p.end_date as project_end,
            d.department_name,
            ph.id as phase_id,
            ph.name as phase_name,
            ph.status as phase_status,
            ph.start_date as phase_start,
            ph.end_date as phase_end,
            a.id as activity_id,
            a.name as activity_name,
            a.status as activity_status,
            a.start_date as activity_start,
            a.end_date as activity_end,
            s.id as sub_id,
            s.name as sub_name,
            s.status as sub_status,
            s.assigned_to as sub_assigned_to,
            s.start_date as sub_start,
            s.end_date as sub_end,
            u.username as assigned_user
        FROM projects p
        LEFT JOIN departments d ON p.department_id = d.id
        LEFT JOIN phases ph ON p.id = ph.project_id
        LEFT JOIN activities a ON ph.id = a.phase_id
        LEFT JOIN sub_activities s ON a.id = s.activity_id
        LEFT JOIN users u ON s.assigned_to = u.id
        $where_clause
        ORDER BY p.name, ph.Phase_order, a.id, s.id
    ");
    if ($params) {
        $stmt->bind_param($param_types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        processHierarchyRow($row, $project_hierarchy);
    }
    $stmt->close();
} else {
    $res = mysqli_query($conn, "
        SELECT 
            p.id as project_id, 
            p.name as project_name, 
            p.status as project_status,
            p.start_date as project_start,
            p.end_date as project_end,
            d.department_name,
            ph.id as phase_id,
            ph.name as phase_name,
            ph.status as phase_status,
            ph.start_date as phase_start,
            ph.end_date as phase_end,
            a.id as activity_id,
            a.name as activity_name,
            a.status as activity_status,
            a.start_date as activity_start,
            a.end_date as activity_end,
            s.id as sub_id,
            s.name as sub_name,
            s.status as sub_status,
            s.assigned_to as sub_assigned_to,
            s.start_date as sub_start,
            s.end_date as sub_end,
            u.username as assigned_user
        FROM projects p
        LEFT JOIN departments d ON p.department_id = d.id
        LEFT JOIN phases ph ON p.id = ph.project_id
        LEFT JOIN activities a ON ph.id = a.phase_id
        LEFT JOIN sub_activities s ON a.id = s.activity_id
        LEFT JOIN users u ON s.assigned_to = u.id
        ORDER BY p.name, ph.Phase_order, a.id, s.id
    ");
    while ($row = mysqli_fetch_assoc($res)) {
        processHierarchyRow($row, $project_hierarchy);
    }
}

function processHierarchyRow($row, &$project_hierarchy) {
    $project_id = $row['project_id'];
    
    if (!isset($project_hierarchy[$project_id])) {
        $project_hierarchy[$project_id] = [
            'name' => $row['project_name'],
            'status' => $row['project_status'],
            'start_date' => $row['project_start'],
            'end_date' => $row['project_end'],
            'department' => $row['department_name'],
            'phases' => []
        ];
    }
    
    $phase_id = $row['phase_id'];
    if ($phase_id && !isset($project_hierarchy[$project_id]['phases'][$phase_id])) {
        $project_hierarchy[$project_id]['phases'][$phase_id] = [
            'name' => $row['phase_name'],
            'status' => $row['phase_status'],
            'start_date' => $row['phase_start'],
            'end_date' => $row['phase_end'],
            'activities' => []
        ];
    }
    
    $activity_id = $row['activity_id'];
    if ($activity_id && $phase_id && !isset($project_hierarchy[$project_id]['phases'][$phase_id]['activities'][$activity_id])) {
        $project_hierarchy[$project_id]['phases'][$phase_id]['activities'][$activity_id] = [
            'name' => $row['activity_name'],
            'status' => $row['activity_status'],
            'start_date' => $row['activity_start'],
            'end_date' => $row['activity_end'],
            'sub_activities' => []
        ];
    }
    
    $sub_id = $row['sub_id'];
    if ($sub_id && $activity_id && $phase_id) {
        $project_hierarchy[$project_id]['phases'][$phase_id]['activities'][$activity_id]['sub_activities'][$sub_id] = [
            'name' => $row['sub_name'],
            'status' => $row['sub_status'],
            'assigned_to' => $row['assigned_user'],
            'start_date' => $row['sub_start'],
            'end_date' => $row['sub_end']
        ];
    }
}

// ======================================================
// ENHANCED USER ASSIGNMENT REPORT WITH WORKLOAD ANALYSIS - FIXED
// ======================================================

$user_assignments = [];
$res = mysqli_query($conn, "
    SELECT 
        u.id as user_id,
        u.username,
        u.email,
        u.system_role,
        COUNT(DISTINCT s.id) as total_sub_tasks,
        SUM(CASE WHEN s.status = 'completed' THEN 1 ELSE 0 END) as completed_sub_tasks,
        SUM(CASE WHEN s.end_date < CURDATE() AND s.status NOT IN ('completed', 'terminated') THEN 1 ELSE 0 END) as overdue_sub_tasks,
        GROUP_CONCAT(DISTINCT p.name SEPARATOR ', ') as project_names,
        GROUP_CONCAT(DISTINCT ph.name SEPARATOR ', ') as phase_names,
        GROUP_CONCAT(DISTINCT a.name SEPARATOR ', ') as activity_names
    FROM users u
    LEFT JOIN sub_activities s ON u.id = s.assigned_to
    LEFT JOIN activities a ON s.activity_id = a.id
    LEFT JOIN phases ph ON a.phase_id = ph.id
    LEFT JOIN projects p ON ph.project_id = p.id
    GROUP BY u.id, u.username, u.email, u.system_role
    HAVING total_sub_tasks > 0
    ORDER BY u.username
");

// Process user assignments with proper data structure
while ($row = mysqli_fetch_assoc($res)) {
    $user_id = $row['user_id'];
    
    $user_assignments[$user_id] = [
        'username' => $row['username'],
        'email' => $row['email'],
        'role' => $row['system_role'],
        'projects' => $row['project_names'] ? explode(', ', $row['project_names']) : [],
        'phases' => $row['phase_names'] ? explode(', ', $row['phase_names']) : [],
        'activities' => $row['activity_names'] ? explode(', ', $row['activity_names']) : [],
        'workload_metrics' => [
            'total_tasks' => (int)$row['total_sub_tasks'],
            'completed_tasks' => (int)$row['completed_sub_tasks'],
            'overdue_tasks' => (int)$row['overdue_sub_tasks'],
            'completion_rate' => $row['total_sub_tasks'] > 0 ? 
                round(($row['completed_sub_tasks'] / $row['total_sub_tasks']) * 100, 1) : 0
        ]
    ];
}

// ======================================================
// ADVANCED PROJECT PROGRESS DATA WITH RISK ASSESSMENT - FIXED WITH TERMINATED PROJECTS
// ======================================================

$project_progress = [];
if ($where_clause) {
    $stmt = $conn->prepare("
        SELECT 
            p.id,
            p.name,
            p.status,
            p.start_date,
            p.end_date,
            d.department_name,
            (SELECT COUNT(*) FROM phases WHERE project_id = p.id) as total_phases,
            (SELECT COUNT(*) FROM phases WHERE project_id = p.id AND status = 'completed') as completed_phases,
            (SELECT COUNT(*) FROM phases WHERE project_id = p.id AND status = 'terminated') as terminated_phases,
            (SELECT COUNT(*) FROM activities a JOIN phases ph ON a.phase_id = ph.id WHERE ph.project_id = p.id) as total_activities,
            (SELECT COUNT(*) FROM activities a JOIN phases ph ON a.phase_id = ph.id WHERE ph.project_id = p.id AND a.status = 'completed') as completed_activities,
            (SELECT COUNT(*) FROM activities a JOIN phases ph ON a.phase_id = ph.id WHERE ph.project_id = p.id AND a.status = 'terminated') as terminated_activities,
            (SELECT COUNT(*) FROM sub_activities s JOIN activities a ON s.activity_id = a.id JOIN phases ph ON a.phase_id = ph.id WHERE ph.project_id = p.id) as total_subs,
            (SELECT COUNT(*) FROM sub_activities s JOIN activities a ON s.activity_id = a.id JOIN phases ph ON a.phase_id = ph.id WHERE ph.project_id = p.id AND s.status = 'completed') as completed_subs,
            (SELECT COUNT(*) FROM sub_activities s JOIN activities a ON s.activity_id = a.id JOIN phases ph ON a.phase_id = ph.id WHERE ph.project_id = p.id AND s.status = 'terminated') as terminated_subs
        FROM projects p
        LEFT JOIN departments d ON p.department_id = d.id
        $where_clause
        ORDER BY p.name
    ");
    if ($params) {
        $stmt->bind_param($param_types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($r = $result->fetch_assoc()) {
        $project_progress[] = calculateProjectProgress($r);
    }
    $stmt->close();
} else {
    $res = mysqli_query($conn, "
        SELECT 
            p.id,
            p.name,
            p.status,
            p.start_date,
            p.end_date,
            d.department_name,
            (SELECT COUNT(*) FROM phases WHERE project_id = p.id) as total_phases,
            (SELECT COUNT(*) FROM phases WHERE project_id = p.id AND status = 'completed') as completed_phases,
            (SELECT COUNT(*) FROM phases WHERE project_id = p.id AND status = 'terminated') as terminated_phases,
            (SELECT COUNT(*) FROM activities a JOIN phases ph ON a.phase_id = ph.id WHERE ph.project_id = p.id) as total_activities,
            (SELECT COUNT(*) FROM activities a JOIN phases ph ON a.phase_id = ph.id WHERE ph.project_id = p.id AND a.status = 'completed') as completed_activities,
            (SELECT COUNT(*) FROM activities a JOIN phases ph ON a.phase_id = ph.id WHERE ph.project_id = p.id AND a.status = 'terminated') as terminated_activities,
            (SELECT COUNT(*) FROM sub_activities s JOIN activities a ON s.activity_id = a.id JOIN phases ph ON a.phase_id = ph.id WHERE ph.project_id = p.id) as total_subs,
            (SELECT COUNT(*) FROM sub_activities s JOIN activities a ON s.activity_id = a.id JOIN phases ph ON a.phase_id = ph.id WHERE ph.project_id = p.id AND s.status = 'completed') as completed_subs,
            (SELECT COUNT(*) FROM sub_activities s JOIN activities a ON s.activity_id = a.id JOIN phases ph ON a.phase_id = ph.id WHERE ph.project_id = p.id AND s.status = 'terminated') as terminated_subs
        FROM projects p
        LEFT JOIN departments d ON p.department_id = d.id
        ORDER BY p.name
    ");
    while ($r = mysqli_fetch_assoc($res)) {
        $project_progress[] = calculateProjectProgress($r);
    }
}

function calculateProjectProgress($r) {
    // Calculate progress percentages - handle terminated projects differently
    if ($r['status'] == 'terminated') {
        // For terminated projects, show actual terminated counts
        $phase_progress = $r['total_phases'] > 0 ? (($r['completed_phases'] + $r['terminated_phases']) / $r['total_phases']) * 100 : 0;
        $activity_progress = $r['total_activities'] > 0 ? (($r['completed_activities'] + $r['terminated_activities']) / $r['total_activities']) * 100 : 0;
        $sub_progress = $r['total_subs'] > 0 ? (($r['completed_subs'] + $r['terminated_subs']) / $r['total_subs']) * 100 : 0;
    } else {
        // For active projects, use normal calculation
        $phase_progress = $r['total_phases'] > 0 ? ($r['completed_phases'] / $r['total_phases']) * 100 : 0;
        $activity_progress = $r['total_activities'] > 0 ? ($r['completed_activities'] / $r['total_activities']) * 100 : 0;
        $sub_progress = $r['total_subs'] > 0 ? ($r['completed_subs'] / $r['total_subs']) * 100 : 0;
    }
    
    // Advanced progress calculation with weighting
    $overall_progress = (
        $phase_progress * 0.3 + 
        $activity_progress * 0.3 + 
        $sub_progress * 0.4
    );
    
    // Risk assessment - FIXED CALCULATION (for terminated projects, risk is none)
    if ($r['status'] == 'terminated') {
        $risk_level = 'terminated';
        $risk_color = 'dark';
        $time_progress = 100;
        $progress_variance = 0;
        $days_remaining = 0;
    } else {
        $total_days = $r['start_date'] && $r['end_date'] ? 
            max(1, (strtotime($r['end_date']) - strtotime($r['start_date'])) / (60 * 60 * 24)) : 0;
        $days_elapsed = $r['start_date'] ? 
            max(0, (time() - strtotime($r['start_date'])) / (60 * 60 * 24)) : 0;
        
        $time_progress = $total_days > 0 ? min(100, ($days_elapsed / $total_days) * 100) : 0;
        $progress_variance = $overall_progress - $time_progress;
        
        // Determine risk level
        $risk_level = 'low';
        $risk_color = 'success';
        
        if ($progress_variance < -20) {
            $risk_level = 'high';
            $risk_color = 'danger';
        } elseif ($progress_variance < -10) {
            $risk_level = 'medium';
            $risk_color = 'warning';
        } elseif ($r['end_date'] && strtotime($r['end_date']) < strtotime('+7 days')) {
            $risk_level = 'approaching';
            $risk_color = 'info';
        }
        
        // Calculate days remaining
        $days_remaining = null;
        if ($r['end_date']) {
            $remaining = (strtotime($r['end_date']) - time()) / (60 * 60 * 24);
            $days_remaining = max(0, ceil($remaining));
        }
    }
    
    return [
        'id' => $r['id'],
        'name' => $r['name'],
        'department' => $r['department_name'],
        'status' => $r['status'],
        'start_date' => $r['start_date'],
        'end_date' => $r['end_date'],
        'phase_progress' => round($phase_progress, 1),
        'activity_progress' => round($activity_progress, 1),
        'sub_progress' => round($sub_progress, 1),
        'overall_progress' => round($overall_progress, 1),
        'time_progress' => round($time_progress, 1),
        'progress_variance' => round($progress_variance, 1),
        'risk_level' => $risk_level,
        'risk_color' => $risk_color,
        'days_remaining' => $days_remaining
    ];
}

// ======================================================
// PERFORMANCE ANALYTICS DATA - FIXED
// ======================================================

$performance_metrics = [
    'department_performance' => getDepartmentPerformance($conn),
    'monthly_trends' => getMonthlyTrends($conn),
    'user_performance' => getUserPerformance($conn)
];

// ======================================================
// ENHANCED EXPORT FUNCTIONS - FIXED FOR ALL TABS
// ======================================================

function generateExport($type, $activeTab, $conn, $where_clause, $params, $param_types) {
    switch($type) {
        case 'pdf':
            exportEnhancedPDF($activeTab, $conn, $where_clause, $params, $param_types);
            break;
        case 'excel':
            exportExcel($activeTab, $conn, $where_clause, $params, $param_types);
            break;
        case 'csv':
            exportCSV($activeTab, $conn, $where_clause, $params, $param_types);
            break;
    }
}

function exportEnhancedPDF($activeTab, $conn, $where_clause, $params, $param_types) {
    // This will be handled by JavaScript
    header("Location: project_report.php?" . http_build_query($_GET));
    exit;
}

function exportCSV($activeTab, $conn, $where_clause, $params, $param_types) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . getExportFilename($activeTab, 'csv') . '"');
    
    $output = fopen('php://output', 'w');
    
    switch($activeTab) {
        case 'overview':
            fputcsv($output, ['Project Name', 'Status', 'Start Date', 'End Date', 'Department']);
            $data = getOverviewData($conn, $where_clause, $params, $param_types);
            foreach ($data as $row) {
                fputcsv($output, $row);
            }
            break;
            
        case 'hierarchy':
            fputcsv($output, ['Project', 'Department', 'Phase', 'Activity', 'Sub-Activity', 'Assigned To', 'Status', 'Start Date', 'End Date']);
            $data = getHierarchyData($conn, $where_clause, $params, $param_types);
            foreach ($data as $row) {
                fputcsv($output, $row);
            }
            break;
            
        case 'progress':
            // Removed Variance column from CSV export
            fputcsv($output, ['Project Name', 'Department', 'Status', 'Overall Progress', 'Risk Level', 'Days Remaining']);
            $data = getProgressData($conn, $where_clause, $params, $param_types);
            foreach ($data as $row) {
                fputcsv($output, $row);
            }
            break;
            
        case 'assignments':
            fputcsv($output, ['Username', 'Role', 'Email', 'Total Tasks', 'Completed Tasks', 'Overdue Tasks', 'Completion Rate']);
            $data = getAssignmentData($conn);
            foreach ($data as $row) {
                fputcsv($output, $row);
            }
            break;
            
        case 'analytics':
            fputcsv($output, ['Department', 'Total Projects', 'Completed Projects', 'Completion Rate']);
            $data = getAnalyticsData($conn);
            foreach ($data as $row) {
                fputcsv($output, $row);
            }
            break;
    }
    
    fclose($output);
    exit;
}

function getExportFilename($activeTab, $extension) {
    $tabNames = [
        'overview' => 'Project_Overview',
        'hierarchy' => 'Project_Hierarchy', 
        'progress' => 'Progress_Tracking',
        'assignments' => 'User_Assignments',
        'analytics' => 'Performance_Analytics'
    ];
    
    return $tabNames[$activeTab] . '_' . date('Y-m-d') . '.' . $extension;
}

// Helper functions for export data
function getOverviewData($conn, $where_clause, $params, $param_types) {
    $data = [];
    if ($where_clause) {
        $stmt = $conn->prepare("SELECT p.name, p.status, p.start_date, p.end_date, d.department_name FROM projects p LEFT JOIN departments d ON p.department_id = d.id $where_clause ORDER BY p.name");
        if ($params) {
            $stmt->bind_param($param_types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $data[] = [$row['name'], $row['status'], $row['start_date'], $row['end_date'], $row['department_name']];
        }
        $stmt->close();
    } else {
        // Include ALL projects including terminated
        $res = mysqli_query($conn, "SELECT p.name, p.status, p.start_date, p.end_date, d.department_name FROM projects p LEFT JOIN departments d ON p.department_id = d.id ORDER BY p.name");
        while ($row = mysqli_fetch_assoc($res)) {
            $data[] = [$row['name'], $row['status'], $row['start_date'], $row['end_date'], $row['department_name']];
        }
    }
    return $data;
}

function getHierarchyData($conn, $where_clause, $params, $param_types) {
    $data = [];
    $query = "
        SELECT 
            p.name as project_name,
            d.department_name,
            ph.name as phase_name,
            a.name as activity_name,
            s.name as sub_activity_name,
            u.username as assigned_to,
            s.status as sub_status,
            s.start_date as sub_start,
            s.end_date as sub_end
        FROM projects p
        LEFT JOIN departments d ON p.department_id = d.id
        LEFT JOIN phases ph ON p.id = ph.project_id
        LEFT JOIN activities a ON ph.id = a.phase_id
        LEFT JOIN sub_activities s ON a.id = s.activity_id
        LEFT JOIN users u ON s.assigned_to = u.id
    ";
    
    if ($where_clause) {
        $stmt = $conn->prepare($query . $where_clause . " ORDER BY p.name, ph.Phase_order, a.id, s.id");
        if ($params) {
            $stmt->bind_param($param_types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $data[] = [
                $row['project_name'] ?: 'N/A',
                $row['department_name'] ?: 'N/A',
                $row['phase_name'] ?: 'N/A',
                $row['activity_name'] ?: 'N/A',
                $row['sub_activity_name'] ?: 'N/A',
                $row['assigned_to'] ?: 'Unassigned',
                $row['sub_status'] ?: 'N/A',
                $row['sub_start'] ?: 'N/A',
                $row['sub_end'] ?: 'N/A'
            ];
        }
        $stmt->close();
    } else {
        // Include ALL projects including terminated
        $res = mysqli_query($conn, $query . " ORDER BY p.name, ph.Phase_order, a.id, s.id");
        while ($row = mysqli_fetch_assoc($res)) {
            $data[] = [
                $row['project_name'] ?: 'N/A',
                $row['department_name'] ?: 'N/A',
                $row['phase_name'] ?: 'N/A',
                $row['activity_name'] ?: 'N/A',
                $row['sub_activity_name'] ?: 'N/A',
                $row['assigned_to'] ?: 'Unassigned',
                $row['sub_status'] ?: 'N/A',
                $row['sub_start'] ?: 'N/A',
                $row['sub_end'] ?: 'N/A'
            ];
        }
    }
    return $data;
}

function getProgressData($conn, $where_clause, $params, $param_types) {
    $data = [];
    if ($where_clause) {
        $stmt = $conn->prepare("SELECT p.name, d.department_name, p.status FROM projects p LEFT JOIN departments d ON p.department_id = d.id $where_clause ORDER BY p.name");
        if ($params) {
            $stmt->bind_param($param_types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $data[] = [$row['name'], $row['department_name'], $row['status'], 'N/A', 'N/A', 'N/A'];
        }
        $stmt->close();
    }
    return $data;
}

function getAssignmentData($conn) {
    $data = [];
    $res = mysqli_query($conn, "
        SELECT u.username, u.system_role, u.email,
               COUNT(s.id) as total_tasks,
               SUM(CASE WHEN s.status = 'completed' THEN 1 ELSE 0 END) as completed_tasks,
               SUM(CASE WHEN s.end_date < CURDATE() AND s.status NOT IN ('completed', 'terminated') THEN 1 ELSE 0 END) as overdue_tasks
        FROM users u
        LEFT JOIN sub_activities s ON u.id = s.assigned_to
        WHERE u.system_role != 'admin' AND u.system_role != 'super_admin'
        GROUP BY u.id, u.username, u.system_role, u.email
        HAVING total_tasks > 0
        ORDER BY u.username
    ");
    
    while ($row = mysqli_fetch_assoc($res)) {
        $completion_rate = $row['total_tasks'] > 0 ? round(($row['completed_tasks'] / $row['total_tasks']) * 100, 1) : 0;
        $data[] = [
            $row['username'],
            $row['system_role'],
            $row['email'],
            $row['total_tasks'],
            $row['completed_tasks'],
            $row['overdue_tasks'],
            $completion_rate . '%'
        ];
    }
    return $data;
}

function getAnalyticsData($conn) {
    $data = [];
    $res = mysqli_query($conn, "
        SELECT d.department_name,
               COUNT(p.id) as total_projects,
               SUM(CASE WHEN p.status = 'completed' THEN 1 ELSE 0 END) as completed_projects
        FROM departments d
        LEFT JOIN projects p ON d.id = p.department_id
        GROUP BY d.id, d.department_name
        HAVING total_projects > 0
        ORDER BY completed_projects DESC
    ");
    
    while ($row = mysqli_fetch_assoc($res)) {
        $completion_rate = $row['total_projects'] > 0 ? round(($row['completed_projects'] / $row['total_projects']) * 100, 1) : 0;
        $data[] = [
            $row['department_name'],
            $row['total_projects'],
            $row['completed_projects'],
            $completion_rate . '%'
        ];
    }
    return $data;
}

// ======================================================
// HELPER FUNCTIONS - FIXED WITH TERMINATED STATUS
// ======================================================

function getEnhancedStatusData($conn, $table, $alias, $where_clause, $params = [], $param_types = '') {
    $data = [];
    $join_conditions = [
        'phases' => "JOIN projects p ON {$alias}.project_id = p.id",
        'activities' => "JOIN phases ph ON {$alias}.phase_id = ph.id JOIN projects p ON ph.project_id = p.id",
        'sub_activities' => "JOIN activities a ON {$alias}.activity_id = a.id JOIN phases ph ON a.phase_id = ph.id JOIN projects p ON ph.project_id = p.id"
    ];
    
    $join = $join_conditions[$table] ?? '';
    
    if ($where_clause) {
        $stmt = $conn->prepare("
            SELECT 
                {$alias}.status, 
                COUNT(*) as count,
                AVG(DATEDIFF(COALESCE({$alias}.end_date, CURDATE()), {$alias}.start_date)) as avg_duration
            FROM {$table} {$alias}
            {$join}
            {$where_clause}
            GROUP BY {$alias}.status
        ");
        if ($params) {
            $stmt->bind_param($param_types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        while ($r = $result->fetch_assoc()) {
            $data[] = [
                'status' => ucfirst(str_replace('_', ' ', $r['status'])),
                'count' => (int)$r['count'],
                'avg_duration' => round($r['avg_duration'], 1)
            ];
        }
        $stmt->close();
    } else {
        $res = mysqli_query($conn, "
            SELECT 
                {$alias}.status, 
                COUNT(*) as count,
                AVG(DATEDIFF(COALESCE({$alias}.end_date, CURDATE()), {$alias}.start_date)) as avg_duration
            FROM {$table} {$alias}
            {$join}
            GROUP BY {$alias}.status
        ");
        while ($r = mysqli_fetch_assoc($res)) {
            $data[] = [
                'status' => ucfirst(str_replace('_', ' ', $r['status'])),
                'count' => (int)$r['count'],
                'avg_duration' => round($r['avg_duration'], 1)
            ];
        }
    }
    
    return $data;
}

function getDepartmentPerformance($conn) {
    $data = [];
    $res = mysqli_query($conn, "
        SELECT 
            d.department_name,
            COUNT(p.id) as project_count,
            AVG(CASE 
                WHEN p.status = 'completed' THEN DATEDIFF(p.end_date, p.start_date)
                ELSE NULL 
            END) as avg_completion_time,
            SUM(CASE WHEN p.status = 'completed' THEN 1 ELSE 0 END) as completed_projects,
            COUNT(DISTINCT ua.user_id) as assigned_users
        FROM departments d
        LEFT JOIN projects p ON d.id = p.department_id
        LEFT JOIN user_assignments ua ON p.id = ua.project_id
        GROUP BY d.id, d.department_name
        HAVING project_count > 0
        ORDER BY completed_projects DESC
    ");
    
    while ($r = mysqli_fetch_assoc($res)) {
        $data[] = $r;
    }
    
    return $data;
}

function getMonthlyTrends($conn) {
    $data = [];
    $res = mysqli_query($conn, "
        SELECT 
            DATE_FORMAT(start_date, '%Y-%m') as month,
            COUNT(*) as projects_started,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as projects_completed
        FROM projects
        WHERE start_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(start_date, '%Y-%m')
        ORDER BY month DESC
        LIMIT 12
    ");
    
    while ($r = mysqli_fetch_assoc($res)) {
        $data[] = $r;
    }
    
    return array_reverse($data);
}

function getUserPerformance($conn) {
    $data = [];
    $res = mysqli_query($conn, "
        SELECT 
            u.username,
            u.system_role,
            COUNT(DISTINCT s.id) as total_tasks,
            SUM(CASE WHEN s.status = 'completed' THEN 1 ELSE 0 END) as completed_tasks,
            SUM(CASE WHEN s.end_date < CURDATE() AND s.status NOT IN ('completed', 'terminated') THEN 1 ELSE 0 END) as overdue_tasks,
            AVG(CASE 
                WHEN s.status = 'completed' THEN DATEDIFF(s.end_date, s.start_date)
                ELSE NULL 
            END) as avg_completion_time
        FROM users u
        LEFT JOIN sub_activities s ON u.id = s.assigned_to
        WHERE s.id IS NOT NULL
        GROUP BY u.id, u.username, u.system_role
        HAVING total_tasks > 0
        ORDER BY completed_tasks DESC
        LIMIT 10
    ");
    
    while ($r = mysqli_fetch_assoc($res)) {
        $completion_rate = $r['total_tasks'] > 0 ? round(($r['completed_tasks'] / $r['total_tasks']) * 100, 1) : 0;
        $data[] = array_merge($r, ['completion_rate' => $completion_rate]);
    }
    
    return $data;
}

function getStatusBadgeClass($status) {
    switch (strtolower($status)) {
        case 'completed':
            return 'bg-success';
        case 'in_progress':
            return 'bg-warning text-dark';
        case 'terminated':
            return 'bg-dark text-light';
        case 'pending':
        default:
            return 'bg-secondary';
    }
}

function getProgressBarClass($progress) {
    if ($progress >= 90) return 'bg-success';
    if ($progress >= 70) return 'bg-info';
    if ($progress >= 50) return 'bg-primary';
    if ($progress >= 30) return 'bg-warning';
    return 'bg-danger';
}

function getRiskBadgeClass($risk_level) {
    switch ($risk_level) {
        case 'high': return 'bg-danger';
        case 'medium': return 'bg-warning text-dark';
        case 'approaching': return 'bg-info';
        case 'terminated': return 'bg-dark text-light';
        case 'low': 
        default: return 'bg-success';
    }
}

// Get enhanced status data for charts
$project_status_data = getEnhancedStatusData($conn, 'projects', 'p', $where_clause, $params, $param_types);
$phase_status_data = getEnhancedStatusData($conn, 'phases', 'ph', $where_clause, $params, $param_types);
$activity_status_data = getEnhancedStatusData($conn, 'activities', 'a', $where_clause, $params, $param_types);
$sub_status_data = getEnhancedStatusData($conn, 'sub_activities', 's', $where_clause, $params, $param_types);

// Close database connection
mysqli_close($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Advanced Project Reports - Dashen Bank</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.0.0"></script>
  <!-- Add jsPDF for PDF export -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js"></script>
  <!-- Add html2canvas for logo rendering in PDF -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
  <style>
    :root {
      --primary-color: #273274;
      --primary-light: #3c4c9e;
      --primary-dark: #1a245a;
      --secondary-color: #f8f9fc;
      --accent-color: #36b9cc;
      --success-color: #1cc88a;
      --danger-color: #e74a3b;
      --warning-color: #f6c23e;
      --dark-color: #5a5c69;
      --light-color: #ffffff;
      --sidebar-width: 280px;
      --sidebar-collapsed-width: 80px;
    }
    
    body {
      background: #f8f9fc;
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
      min-height: 100vh;
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
    
    .navbar-brand {
      font-weight: 700;
      letter-spacing: 0.5px;
    }
    
    .brand-bg {
      background: var(--primary-color);
    }
    
    .nav-tabs .nav-link {
      color: var(--dark-color);
      font-weight: 500;
      border: none;
      padding: 0.75rem 1.25rem;
      transition: all 0.3s;
    }
    
    .nav-tabs .nav-link.active {
      color: var(--primary-color);
      border-bottom: 3px solid var(--primary-color);
      background: transparent;
    }
    
    .nav-tabs .nav-link:hover {
      color: var(--primary-color);
      background-color: rgba(39, 50, 116, 0.05);
    }
    
    .card {
      border: none;
      border-radius: 0.5rem;
      box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.1);
      transition: transform 0.2s;
    }
    
    .card:hover {
      transform: translateY(-5px);
    }
    
    .card-header {
      background: var(--primary-color);
      color: white;
      border-radius: 0.5rem 0.5rem 0 0 !important;
      padding: 1rem 1.5rem;
      font-weight: 600;
    }
    
    .btn-primary {
      background-color: var(--primary-color);
      border-color: var(--primary-color);
    }
    
    .btn-primary:hover {
      background-color: var(--primary-light);
      border-color: var(--primary-light);
    }
    
    .btn-outline-primary {
      color: var(--primary-color);
      border-color: var(--primary-color);
    }
    
    .btn-outline-primary:hover {
      background-color: var(--primary-color);
      color: white;
    }
    
    .table th {
      background-color: var(--primary-color);
      color: white;
    }
    
    .badge-primary {
      background-color: var(--primary-color);
    }
    
    .stat-card {
      border-left: 4px solid var(--primary-color);
    }
    
    .progress {
      height: 10px;
    }
    
    .hierarchy-item {
      border-left: 3px solid var(--primary-color);
      padding-left: 15px;
      margin-bottom: 10px;
    }
    
    .hierarchy-item .hierarchy-item {
      border-left-color: var(--accent-color);
    }
    
    .hierarchy-item .hierarchy-item .hierarchy-item {
      border-left-color: var(--success-color);
    }
    
    .chart-container {
      position: relative;
      height: 300px;
      width: 100%;
    }
    
    .filter-section {
      background: white;
      border-radius: 0.5rem;
      padding: 1rem;
      margin-bottom: 1.5rem;
      box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.1);
    }
    
    .main-content {
      padding: 20px;
      min-height: 100vh;
    }
    
    @media (max-width: 768px) {
      .main-content {
        padding: 15px;
      }
    }
    
    .table-responsive {
      border-radius: 0.5rem;
      overflow: hidden;
    }
    
    .status-badge {
      font-size: 0.75rem;
      padding: 0.25rem 0.5rem;
    }
    
    .user-assignment-card {
      border-left: 4px solid var(--primary-color);
      margin-bottom: 1rem;
      transition: all 0.3s;
    }
    
    .user-assignment-card:hover {
      transform: translateY(-3px);
      box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
    }
    
    .risk-indicator {
      width: 12px;
      height: 12px;
      border-radius: 50%;
      display: inline-block;
      margin-right: 5px;
    }
    
    .export-buttons .btn {
      margin-left: 5px;
    }
    
    .metric-card {
      text-align: center;
      padding: 1rem;
    }
    
    .metric-value {
      font-size: 2rem;
      font-weight: bold;
      color: var(--primary-color);
    }
    
    .metric-label {
      font-size: 0.875rem;
      color: var(--dark-color);
    }
    
    .workload-metrics {
      background: #f8f9fa;
      border-radius: 0.5rem;
      padding: 1rem;
      margin-bottom: 1rem;
    }
    
    /* Print Styles */
    @media print {
      .no-print, .filter-section, .export-buttons, .nav-tabs {
        display: none !important;
      }
      
      .main-content {
        margin-left: 0 !important;
        padding: 0 !important;
      }
      
      .card {
        box-shadow: none !important;
        border: 1px solid #ddd !important;
      }
      
      .table th {
        background-color: #273274 !important;
        color: white !important;
        -webkit-print-color-adjust: exact;
      }
    }
    
    /* Hidden logo for PDF export */
    .pdf-logo {
      display: none;
    }
    
    @media print {
      .pdf-logo {
        display: block !important;
        text-align: center;
        margin-bottom: 20px;
      }
      .pdf-logo img {
        max-height: 60px;
      }
    }
    
    /* Terminated project styling */
    .terminated-project {
      opacity: 0.8;
      background-color: #f8f9fa !important;
    }
    
    .terminated-project td {
      color: #6c757d !important;
    }
    
    .terminated-badge {
      background-color: #343a40 !important;
      color: white !important;
    }
    
    .terminated-icon {
      color: #6c757d;
    }
  </style>
</head>
<body>
  <!-- Include Sidebar -->
  <?php 
  // Set active dashboard for sidebar
  $active_dashboard = 'project_management';
  include 'sidebar.php'; 
  ?>

  <!-- Main Content -->
  <div class="main-content" id="mainContent">
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark brand-bg mb-4">
      <div class="container-fluid">
        <a class="navbar-brand" href="#">
          <img src="Images/DashenLogo1.png" alt="Dashen Bank Logo" class="me-2" height="30">
          Advanced Project Reporting System
        </a>
        <div class="d-flex align-items-center">
          <span class="navbar-text me-3">
            <i class="fas fa-user-circle me-1"></i> <?= htmlspecialchars($_SESSION['username'] ?? 'Admin User') ?> (<?= htmlspecialchars($user_role) ?>)
          </span>
        </div>
      </div>
    </nav>

    <div class="container-fluid">
      <!-- Enhanced Filters Section -->
      <div class="filter-section">
        <h5 class="mb-3"><i class="fas fa-filter me-2"></i>Advanced Report Filters</h5>
        <form method="GET" class="row g-3">
          <input type="hidden" name="tab" value="<?= $activeTab ?>">
          
          <div class="col-md-2">
            <label for="project_filter" class="form-label">Project</label>
            <select class="form-select" id="project_filter" name="project_filter">
              <option value="">All Projects</option>
              <?php foreach ($projects_dropdown as $p): ?>
                <option value="<?= $p['id'] ?>" <?= $project_filter == $p['id'] ? 'selected' : '' ?>>
                  <?= esc($p['name']) ?>
                  <?php if ($p['status'] == 'terminated'): ?>
                    (Terminated)
                  <?php endif; ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          
          <div class="col-md-2">
            <label for="status_filter" class="form-label">Status</label>
            <select class="form-select" id="status_filter" name="status_filter">
              <option value="">All Statuses</option>
              <option value="pending" <?= $status_filter == 'pending' ? 'selected' : '' ?>>Pending</option>
              <option value="in_progress" <?= $status_filter == 'in_progress' ? 'selected' : '' ?>>In Progress</option>
              <option value="completed" <?= $status_filter == 'completed' ? 'selected' : '' ?>>Completed</option>
              <option value="terminated" <?= $status_filter == 'terminated' ? 'selected' : '' ?>>Terminated</option>
            </select>
          </div>
          
          <div class="col-md-2">
            <label for="department_filter" class="form-label">Department</label>
            <select class="form-select" id="department_filter" name="department_filter">
              <option value="">All Departments</option>
              <?php foreach ($departments_dropdown as $dept): ?>
                <option value="<?= $dept['id'] ?>" <?= $department_filter == $dept['id'] ? 'selected' : '' ?>>
                  <?= esc($dept['department_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          
          <div class="col-md-2">
            <label for="date_from" class="form-label">From Date</label>
            <input type="date" class="form-control" id="date_from" name="date_from" value="<?= $date_from ?>">
          </div>
          
          <div class="col-md-2">
            <label for="date_to" class="form-label">To Date</label>
            <input type="date" class="form-control" id="date_to" name="date_to" value="<?= $date_to ?>">
          </div>
          
          <div class="col-md-2">
            <label for="user_filter" class="form-label">User</label>
            <select class="form-select" id="user_filter" name="user_filter">
              <option value="">All Users</option>
              <?php foreach ($users_dropdown as $u): ?>
                <option value="<?= $u['id'] ?>" <?= $user_filter == $u['id'] ? 'selected' : '' ?>>
                  <?= esc($u['username']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          
          <div class="col-12">
            <div class="d-flex justify-content-between">
              <button type="submit" class="btn btn-primary">
                <i class="fas fa-search me-1"></i> Apply Filters
              </button>
              <div class="export-buttons">
                <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn btn-success">
                  <i class="fas fa-file-csv me-1"></i> Export CSV
                </a>
                <button type="button" class="btn btn-danger" onclick="exportToPDF('<?= $activeTab ?>')">
                  <i class="fas fa-file-pdf me-1"></i> Export PDF
                </button>
                <button class="btn btn-info" onclick="window.print()">
                  <i class="fas fa-print me-1"></i> Print
                </button>
              </div>
            </div>
          </div>
        </form>
      </div>
      
      <!-- Enhanced Tabs Navigation -->
      <ul class="nav nav-tabs" id="reportTabs" role="tablist">
        <li class="nav-item" role="presentation">
          <a class="nav-link <?= $activeTab === 'overview' ? 'active' : '' ?>" href="?tab=overview">
            <i class="fas fa-tachometer-alt me-1"></i> Overview
          </a>
        </li>
        <li class="nav-item" role="presentation">
          <a class="nav-link <?= $activeTab === 'hierarchy' ? 'active' : '' ?>" href="?tab=hierarchy">
            <i class="fas fa-sitemap me-1"></i> Project Hierarchy
          </a>
        </li>
        <li class="nav-item" role="presentation">
          <a class="nav-link <?= $activeTab === 'assignments' ? 'active' : '' ?>" href="?tab=assignments">
            <i class="fas fa-users me-1"></i> User Assignments
          </a>
        </li>
        <li class="nav-item" role="presentation">
          <a class="nav-link <?= $activeTab === 'progress' ? 'active' : '' ?>" href="?tab=progress">
            <i class="fas fa-chart-line me-1"></i> Progress Tracking
          </a>
        </li>
        <li class="nav-item" role="presentation">
          <a class="nav-link <?= $activeTab === 'analytics' ? 'active' : '' ?>" href="?tab=analytics">
            <i class="fas fa-chart-bar me-1"></i> Performance Analytics
          </a>
        </li>
      </ul>

      <!-- Enhanced Tab Content -->
      <div class="tab-content border border-top-0 p-4 bg-white rounded-bottom">
        
        <!-- Overview Tab - Enhanced -->
        <div class="tab-pane fade <?= $activeTab === 'overview' ? 'show active' : '' ?>" id="overview">
          <!-- Enhanced Statistics Cards -->
          <div class="row mb-4">
            <div class="col-xl-2 col-md-4 mb-4">
              <div class="card border-0 shadow h-100 py-2 stat-card">
                <div class="card-body text-center">
                  <div class="metric-value"><?= $total_projects ?></div>
                  <div class="metric-label">Total Projects</div>
                </div>
              </div>
            </div>
            
            <div class="col-xl-2 col-md-4 mb-4">
              <div class="card border-0 shadow h-100 py-2 stat-card">
                <div class="card-body text-center">
                  <div class="metric-value"><?= $total_phases ?></div>
                  <div class="metric-label">Total Phases</div>
                </div>
              </div>
            </div>
            
            <div class="col-xl-2 col-md-4 mb-4">
              <div class="card border-0 shadow h-100 py-2 stat-card">
                <div class="card-body text-center">
                  <div class="metric-value"><?= $total_activities ?></div>
                  <div class="metric-label">Total Activities</div>
                </div>
              </div>
            </div>
            
            <div class="col-xl-2 col-md-4 mb-4">
              <div class="card border-0 shadow h-100 py-2 stat-card">
                <div class="card-body text-center">
                  <div class="metric-value"><?= $total_subs ?></div>
                  <div class="metric-label">Total Sub-Activities</div>
                </div>
              </div>
            </div>
            
            <div class="col-xl-2 col-md-4 mb-4">
              <div class="card border-0 shadow h-100 py-2 stat-card">
                <div class="card-body text-center">
                  <div class="metric-value text-success">
                    <?php 
                    $total_projects_count = array_sum(array_column($projects_by_status, 'count'));
                    $completed_projects = 0;
                    foreach ($projects_by_status as $status) {
                        if ($status['status'] == 'Completed' || $status['status'] == 'completed') {
                            $completed_projects = $status['count'];
                            break;
                        }
                    }
                    echo $total_projects_count > 0 ? round(($completed_projects / $total_projects_count) * 100, 1) : 0;
                    ?>%
                  </div>
                  <div class="metric-label">Completion Rate</div>
                </div>
              </div>
            </div>
            
            <div class="col-xl-2 col-md-4 mb-4">
              <div class="card border-0 shadow h-100 py-2 stat-card">
                <div class="card-body text-center">
                  <div class="metric-value text-info">
                    <?= count($users_dropdown) ?>
                  </div>
                  <div class="metric-label">Active Users</div>
                </div>
              </div>
            </div>
          </div>

          <!-- Enhanced Charts Row -->
          <div class="row mb-4">
            <div class="col-md-6 mb-4">
              <div class="card">
                <div class="card-header">
                  <i class="fas fa-chart-pie me-2"></i>Project Status Distribution
                </div>
                <div class="card-body">
                  <div class="chart-container">
                    <canvas id="projectStatusChart"></canvas>
                  </div>
                </div>
              </div>
            </div>
            
            <div class="col-md-6 mb-4">
              <div class="card">
                <div class="card-header">
                  <i class="fas fa-chart-bar me-2"></i>Entity Status Comparison
                </div>
                <div class="card-body">
                  <div class="chart-container">
                    <canvas id="statusComparisonChart"></canvas>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Enhanced Status Distribution Tables -->
          <div class="row">
            <?php 
            $status_tables = [
                'Projects' => $project_status_data,
                'Phases' => $phase_status_data,
                'Activities' => $activity_status_data,
                'Sub-Activities' => $sub_status_data
            ];
            
            foreach ($status_tables as $title => $data): 
            ?>
            <div class="col-md-3 mb-4">
              <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                  <span>
                    <i class="fas fa-<?= 
                      $title === 'Projects' ? 'project-diagram' : 
                      ($title === 'Phases' ? 'tasks' : 
                      ($title === 'Activities' ? 'list-check' : 'list-ol')) 
                    ?> me-2"></i><?= $title ?>
                  </span>
                  <span class="badge bg-primary"><?= array_sum(array_column($data, 'count')) ?></span>
                </div>
                <div class="card-body p-0">
                  <table class="table table-sm table-hover mb-0">
                    <thead>
                      <tr>
                        <th>Status</th>
                        <th>Count</th>
                        <th>Avg Days</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($data as $status): ?>
                        <tr class="<?= $status['status'] == 'Terminated' ? 'terminated-project' : '' ?>">
                          <td>
                            <span class="badge status-badge <?= getStatusBadgeClass(strtolower(str_replace(' ', '_', $status['status']))) ?>">
                              <?= esc($status['status']) ?>
                            </span>
                          </td>
                          <td><?= (int)$status['count'] ?></td>
                          <td><small class="text-muted"><?= $status['avg_duration'] ?? 'N/A' ?></small></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Project Hierarchy Tab - Enhanced -->
        <div class="tab-pane fade <?= $activeTab === 'hierarchy' ? 'show active' : '' ?>" id="hierarchy">
          <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="mb-0">Project Hierarchy Report</h4>
            <div>
              <button class="btn btn-outline-primary me-2" onclick="expandAll()">
                <i class="fas fa-expand me-1"></i> Expand All
              </button>
              <button class="btn btn-outline-secondary" onclick="collapseAll()">
                <i class="fas fa-compress me-1"></i> Collapse All
              </button>
            </div>
          </div>

          <?php if (empty($project_hierarchy)): ?>
            <div class="alert alert-info">
              <i class="fas fa-info-circle me-2"></i> No project data available.
            </div>
          <?php else: ?>
            <div class="accordion" id="hierarchyAccordion">
              <?php $project_index = 0; ?>
              <?php foreach ($project_hierarchy as $project_id => $project): ?>
                <div class="accordion-item <?= $project['status'] == 'terminated' ? 'terminated-project' : '' ?>">
                  <h2 class="accordion-header" id="heading<?= $project_id ?>">
                    <button class="accordion-button <?= $project_index > 0 ? 'collapsed' : '' ?> <?= $project['status'] == 'terminated' ? 'terminated-icon' : '' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#collapse<?= $project_id ?>" aria-expanded="<?= $project_index === 0 ? 'true' : 'false' ?>" aria-controls="collapse<?= $project_id ?>">
                      <div class="d-flex justify-content-between w-100 me-3 align-items-center">
                        <div>
                          <i class="fas fa-project-diagram me-2 <?= $project['status'] == 'terminated' ? 'terminated-icon' : '' ?>"></i>
                          <strong class="<?= $project['status'] == 'terminated' ? 'text-muted' : '' ?>"><?= esc($project['name']) ?></strong>
                          <?php if ($project['department']): ?>
                            <span class="badge bg-info ms-2"><?= esc($project['department']) ?></span>
                          <?php endif; ?>
                        </div>
                        <div>
                          <span class="badge <?= getStatusBadgeClass($project['status']) ?> me-2">
                            <?php if ($project['status'] == 'terminated'): ?>
                              <i class="fas fa-ban me-1"></i>
                            <?php endif; ?>
                            <?= esc(ucfirst(str_replace('_', ' ', $project['status']))) ?>
                          </span>
                          <small class="text-muted">
                            <?= $project['start_date'] ? esc($project['start_date']) : 'No start date' ?>
                            <?= $project['end_date'] ? ' - ' . esc($project['end_date']) : '' ?>
                          </small>
                        </div>
                      </div>
                    </button>
                  </h2>
                  <div id="collapse<?= $project_id ?>" class="accordion-collapse collapse <?= $project_index === 0 ? 'show' : '' ?>" aria-labelledby="heading<?= $project_id ?>" data-bs-parent="#hierarchyAccordion">
                    <div class="accordion-body">
                      <?php if (!empty($project['phases'])): ?>
                        <?php foreach ($project['phases'] as $phase_id => $phase): ?>
                          <div class="hierarchy-item mb-3">
                            <h5 class="d-flex justify-content-between align-items-center">
                              <span>
                                <i class="fas fa-tasks me-2"></i>
                                <?= esc($phase['name']) ?>
                                <span class="badge <?= getStatusBadgeClass($phase['status']) ?> ms-2">
                                  <?php if ($phase['status'] == 'terminated'): ?>
                                    <i class="fas fa-ban me-1"></i>
                                  <?php endif; ?>
                                  <?= esc(ucfirst(str_replace('_', ' ', $phase['status']))) ?>
                                </span>
                              </span>
                              <small class="text-muted">
                                <?= $phase['start_date'] ? esc($phase['start_date']) : '' ?>
                                <?= $phase['end_date'] ? ' - ' . esc($phase['end_date']) : '' ?>
                              </small>
                            </h5>
                            
                            <?php if (!empty($phase['activities'])): ?>
                              <?php foreach ($phase['activities'] as $activity_id => $activity): ?>
                                <div class="hierarchy-item mb-2">
                                  <h6 class="d-flex justify-content-between align-items-center">
                                    <span>
                                      <i class="fas fa-list-check me-2"></i>
                                      <?= esc($activity['name']) ?>
                                      <span class="badge <?= getStatusBadgeClass($activity['status']) ?> ms-2">
                                        <?php if ($activity['status'] == 'terminated'): ?>
                                          <i class="fas fa-ban me-1"></i>
                                        <?php endif; ?>
                                        <?= esc(ucfirst(str_replace('_', ' ', $activity['status']))) ?>
                                      </span>
                                    </span>
                                    <small class="text-muted">
                                      <?= $activity['start_date'] ? esc($activity['start_date']) : '' ?>
                                      <?= $activity['end_date'] ? ' - ' . esc($activity['end_date']) : '' ?>
                                    </small>
                                  </h6>
                                  
                                  <?php if (!empty($activity['sub_activities'])): ?>
                                    <div class="hierarchy-item">
                                      <?php foreach ($activity['sub_activities'] as $sub_id => $sub): ?>
                                        <div class="d-flex justify-content-between align-items-center mb-1 p-2 bg-light rounded">
                                          <div>
                                            <i class="fas fa-list-ol me-2"></i>
                                            <?= esc($sub['name']) ?>
                                          </div>
                                          <div>
                                            <span class="badge <?= getStatusBadgeClass($sub['status']) ?> me-2">
                                              <?php if ($sub['status'] == 'terminated'): ?>
                                                <i class="fas fa-ban me-1"></i>
                                              <?php endif; ?>
                                              <?= esc(ucfirst(str_replace('_', ' ', $sub['status']))) ?>
                                            </span>
                                            <?php if ($sub['assigned_to']): ?>
                                              <span class="badge bg-info text-dark me-2">
                                                <i class="fas fa-user me-1"></i><?= esc($sub['assigned_to']) ?>
                                              </span>
                                            <?php endif; ?>
                                            <small class="text-muted">
                                              <?= $sub['start_date'] ? esc($sub['start_date']) : '' ?>
                                              <?= $sub['end_date'] ? ' - ' . esc($sub['end_date']) : '' ?>
                                            </small>
                                          </div>
                                        </div>
                                      <?php endforeach; ?>
                                    </div>
                                  <?php else: ?>
                                    <p class="text-muted mb-0"><small>No sub-activities</small></p>
                                  <?php endif; ?>
                                </div>
                              <?php endforeach; ?>
                            <?php else: ?>
                              <p class="text-muted mb-0"><small>No activities</small></p>
                            <?php endif; ?>
                          </div>
                        <?php endforeach; ?>
                      <?php else: ?>
                        <p class="text-muted mb-0"><small>No phases</small></p>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
                <?php $project_index++; ?>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <!-- Enhanced User Assignments Tab - FIXED -->
        <div class="tab-pane fade <?= $activeTab === 'assignments' ? 'show active' : '' ?>" id="assignments">
          <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="mb-0">User Assignment & Workload Report</h4>
            <button class="btn btn-outline-primary" onclick="window.print()">
              <i class="fas fa-print me-1"></i> Print Report
            </button>
          </div>

          <?php if (empty($user_assignments)): ?>
            <div class="alert alert-info">
              <i class="fas fa-info-circle me-2"></i> No user assignment data available.
            </div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-striped table-hover" id="assignmentsTable">
                <thead>
                  <tr>
                    <th>Username</th>
                    <th>Role</th>
                    <th>Email</th>
                    <th>Total Tasks</th>
                    <th>Completed</th>
                    <th>Overdue</th>
                    <th>Completion Rate</th>
                    <th>Projects</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($user_assignments as $user_id => $user_data): 
                    $hasAssignments = !empty($user_data['projects']) || !empty($user_data['phases']) || 
                                     !empty($user_data['activities']) || $user_data['workload_metrics']['total_tasks'] > 0;
                  ?>
                    <tr>
                      <td>
                        <strong><?= esc($user_data['username']) ?></strong>
                      </td>
                      <td>
                        <span class="badge bg-info"><?= esc($user_data['role']) ?></span>
                      </td>
                      <td><?= esc($user_data['email']) ?></td>
                      <td>
                        <span class="badge bg-primary"><?= $user_data['workload_metrics']['total_tasks'] ?></span>
                      </td>
                      <td>
                        <span class="badge bg-success"><?= $user_data['workload_metrics']['completed_tasks'] ?></span>
                      </td>
                      <td>
                        <?php if ($user_data['workload_metrics']['overdue_tasks'] > 0): ?>
                          <span class="badge bg-danger"><?= $user_data['workload_metrics']['overdue_tasks'] ?></span>
                        <?php else: ?>
                          <span class="badge bg-secondary">0</span>
                        <?php endif; ?>
                      </td>
                      <td>
                        <div class="progress" style="height: 10px;">
                          <div class="progress-bar bg-success" style="width: <?= $user_data['workload_metrics']['completion_rate'] ?>%"></div>
                        </div>
                        <small><?= $user_data['workload_metrics']['completion_rate'] ?>%</small>
                      </td>
                      <td>
                        <?php if (!empty($user_data['projects'])): ?>
                          <small class="text-muted"><?= implode(', ', array_slice($user_data['projects'], 0, 3)) ?>
                            <?php if (count($user_data['projects']) > 3): ?>
                              ... +<?= count($user_data['projects']) - 3 ?> more
                            <?php endif; ?>
                          </small>
                        <?php else: ?>
                          <span class="badge bg-secondary">No projects</span>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>

        <!-- Enhanced Progress Tracking Tab - FIXED with Variance column removed and Terminated projects included -->
        <div class="tab-pane fade <?= $activeTab === 'progress' ? 'show active' : '' ?>" id="progress">
          <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="mb-0">Project Progress & Risk Assessment</h4>
            <button class="btn btn-outline-primary" onclick="window.print()">
              <i class="fas fa-print me-1"></i> Print Report
            </button>
          </div>

          <?php if (empty($project_progress)): ?>
            <div class="alert alert-info">
              <i class="fas fa-info-circle me-2"></i> No project progress data available.
            </div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-striped table-hover" id="progressTable">
                <thead>
                  <tr>
                    <th>Project Name</th>
                    <th>Department</th>
                    <th>Status</th>
                    <th>Risk</th>
                    <th>Phase Progress</th>
                    <th>Activity Progress</th>
                    <th>Sub Progress</th>
                    <th>Overall Progress</th>
                    <th>Days Left</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($project_progress as $project): ?>
                    <tr class="<?= $project['status'] == 'terminated' ? 'terminated-project' : '' ?>">
                      <td>
                        <strong class="<?= $project['status'] == 'terminated' ? 'text-muted' : '' ?>"><?= esc($project['name']) ?></strong>
                        <?php if ($project['department']): ?>
                          <br><small class="text-muted"><?= esc($project['department']) ?></small>
                        <?php endif; ?>
                      </td>
                      <td><?= esc($project['department'] ?? 'N/A') ?></td>
                      <td>
                        <span class="badge status-badge <?= getStatusBadgeClass($project['status']) ?>">
                          <?php if ($project['status'] == 'terminated'): ?>
                            <i class="fas fa-ban me-1"></i>
                          <?php endif; ?>
                          <?= esc(ucfirst(str_replace('_', ' ', $project['status']))) ?>
                        </span>
                      </td>
                      <td>
                        <span class="badge <?= getRiskBadgeClass($project['risk_level']) ?>">
                          <?php if ($project['risk_level'] == 'terminated'): ?>
                            <i class="fas fa-ban me-1"></i> Terminated
                          <?php else: ?>
                            <i class="fas fa-<?= 
                              $project['risk_level'] === 'high' ? 'exclamation-triangle' : 
                              ($project['risk_level'] === 'medium' ? 'exclamation-circle' : 'check-circle')
                            ?> me-1"></i>
                            <?= ucfirst($project['risk_level']) ?>
                          <?php endif; ?>
                        </span>
                      </td>
                      <td>
                        <div class="d-flex align-items-center">
                          <div class="progress flex-grow-1 me-2" style="height: 8px;">
                            <div class="progress-bar <?= getProgressBarClass($project['phase_progress']) ?>" 
                                 style="width: <?= $project['phase_progress'] ?>%">
                            </div>
                          </div>
                          <small class="text-nowrap <?= $project['status'] == 'terminated' ? 'text-muted' : '' ?>"><?= $project['phase_progress'] ?>%</small>
                        </div>
                      </td>
                      <td>
                        <div class="d-flex align-items-center">
                          <div class="progress flex-grow-1 me-2" style="height: 8px;">
                            <div class="progress-bar <?= getProgressBarClass($project['activity_progress']) ?>" 
                                 style="width: <?= $project['activity_progress'] ?>%">
                            </div>
                          </div>
                          <small class="text-nowrap <?= $project['status'] == 'terminated' ? 'text-muted' : '' ?>"><?= $project['activity_progress'] ?>%</small>
                        </div>
                      </td>
                      <td>
                        <div class="d-flex align-items-center">
                          <div class="progress flex-grow-1 me-2" style="height: 8px;">
                            <div class="progress-bar <?= getProgressBarClass($project['sub_progress']) ?>" 
                                 style="width: <?= $project['sub_progress'] ?>%">
                            </div>
                          </div>
                          <small class="text-nowrap <?= $project['status'] == 'terminated' ? 'text-muted' : '' ?>"><?= $project['sub_progress'] ?>%</small>
                        </div>
                      </td>
                      <td>
                        <div class="d-flex align-items-center">
                          <div class="progress flex-grow-1 me-2" style="height: 12px;">
                            <div class="progress-bar <?= getProgressBarClass($project['overall_progress']) ?>" 
                                 style="width: <?= $project['overall_progress'] ?>%">
                            </div>
                          </div>
                          <strong class="text-nowrap <?= $project['status'] == 'terminated' ? 'text-muted' : '' ?>"><?= $project['overall_progress'] ?>%</strong>
                        </div>
                      </td>
                      <td>
                        <?php if ($project['days_remaining'] !== null): ?>
                          <?php if ($project['status'] == 'terminated'): ?>
                            <span class="badge bg-dark">Terminated</span>
                          <?php else: ?>
                            <span class="badge <?= $project['days_remaining'] < 7 ? 'bg-danger' : ($project['days_remaining'] < 30 ? 'bg-warning' : 'bg-success') ?>">
                              <?= $project['days_remaining'] ?> days
                            </span>
                          <?php endif; ?>
                        <?php else: ?>
                          <span class="badge bg-secondary">N/A</span>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            
            <!-- Enhanced Progress Chart -->
            <div class="card mt-4">
              <div class="card-header">
                <i class="fas fa-chart-line me-2"></i>Project Progress & Risk Overview
              </div>
              <div class="card-body">
                <div class="chart-container">
                  <canvas id="progressChart"></canvas>
                </div>
              </div>
            </div>
          <?php endif; ?>
        </div>

        <!-- New Performance Analytics Tab -->
        <div class="tab-pane fade <?= $activeTab === 'analytics' ? 'show active' : '' ?>" id="analytics">
          <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="mb-0">Performance Analytics & Trends</h4>
            <button class="btn btn-outline-primary" onclick="window.print()">
              <i class="fas fa-print me-1"></i> Print Report
            </button>
          </div>

          <!-- Department Performance -->
          <div class="row mb-4">
            <div class="col-12">
              <div class="card">
                <div class="card-header">
                  <i class="fas fa-building me-2"></i>Department Performance
                </div>
                <div class="card-body">
                  <div class="chart-container">
                    <canvas id="departmentPerformanceChart"></canvas>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Monthly Trends -->
          <div class="row mb-4">
            <div class="col-12">
              <div class="card">
                <div class="card-header">
                  <i class="fas fa-chart-line me-2"></i>Monthly Project Trends
                </div>
                <div class="card-body">
                  <div class="chart-container">
                    <canvas id="monthlyTrendsChart"></canvas>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- User Performance -->
          <div class="row">
            <div class="col-12">
              <div class="card">
                <div class="card-header">
                  <i class="fas fa-users me-2"></i>Top Performers
                </div>
                <div class="card-body">
                  <div class="table-responsive">
                    <table class="table table-striped table-hover" id="analyticsTable">
                      <thead>
                        <tr>
                          <th>User</th>
                          <th>Role</th>
                          <th>Total Tasks</th>
                          <th>Completed</th>
                          <th>Overdue</th>
                          <th>Completion Rate</th>
                          <th>Avg. Completion Time</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($performance_metrics['user_performance'] as $user): ?>
                          <tr>
                            <td><?= esc($user['username']) ?></td>
                            <td><span class="badge bg-info"><?= esc($user['system_role']) ?></span></td>
                            <td><?= (int)$user['total_tasks'] ?></td>
                            <td>
                              <span class="badge bg-success"><?= (int)$user['completed_tasks'] ?></span>
                            </td>
                            <td>
                              <?php if ($user['overdue_tasks'] > 0): ?>
                                <span class="badge bg-danger"><?= (int)$user['overdue_tasks'] ?></span>
                              <?php else: ?>
                                <span class="badge bg-secondary">0</span>
                              <?php endif; ?>
                            </td>
                            <td>
                              <div class="progress" style="height: 10px;">
                                <div class="progress-bar bg-success" style="width: <?= $user['completion_rate'] ?>%"></div>
                              </div>
                              <small><?= $user['completion_rate'] ?>%</small>
                            </td>
                            <td>
                              <?= $user['avg_completion_time'] ? round($user['avg_completion_time']) . ' days' : 'N/A' ?>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Hidden logo container for PDF export -->
  <div id="pdfLogo" class="pdf-logo" style="display: none;">
    <img src="Images/DashenLogo1.png" alt="Dashen Bank Logo" style="max-height: 60px;">
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    // Enhanced Chart Configuration
    Chart.defaults.font.family = "'Segoe UI', Tahoma, Geneva, Verdana, sans-serif";
    
    document.addEventListener('DOMContentLoaded', function() {
      // Project Status Chart - Include terminated projects
      const projectStatusCtx = document.getElementById('projectStatusChart').getContext('2d');
      
      // Get status data from PHP
      const statusLabels = <?= json_encode(array_column($project_status_data, 'status')) ?>;
      const statusCounts = <?= json_encode(array_column($project_status_data, 'count')) ?>;
      
      // Define colors for all statuses including terminated
      const statusColors = {
        'Pending': '#36b9cc',
        'In Progress': '#f6c23e',
        'Completed': '#1cc88a',
        'Terminated': '#5a5c69'
      };
      
      const backgroundColor = statusLabels.map(label => statusColors[label] || '#cccccc');
      
      const projectStatusChart = new Chart(projectStatusCtx, {
        type: 'doughnut',
        data: {
          labels: statusLabels,
          datasets: [{
            data: statusCounts,
            backgroundColor: backgroundColor,
            borderWidth: 2,
            borderColor: '#fff'
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: {
              position: 'bottom'
            },
            tooltip: {
              callbacks: {
                label: function(context) {
                  const label = context.label || '';
                  const value = context.raw;
                  const total = context.dataset.data.reduce((a, b) => a + b, 0);
                  const percentage = Math.round((value / total) * 100);
                  return `${label}: ${value} (${percentage}%)`;
                }
              }
            }
          }
        }
      });

      // Status Comparison Chart
      const statusComparisonCtx = document.getElementById('statusComparisonChart').getContext('2d');
      
      // Prepare enhanced comparison data - include all 4 statuses
      const comparisonLabels = ['Pending', 'In Progress', 'Completed', 'Terminated'];
      const projectCounts = [0, 0, 0, 0];
      const phaseCounts = [0, 0, 0, 0];
      const activityCounts = [0, 0, 0, 0];
      const subCounts = [0, 0, 0, 0];
      
      // Helper function to get count for a specific status
      function getCountForStatus(data, status) {
        for (let i = 0; i < data.length; i++) {
          if (data[i].status === status) {
            return data[i].count;
          }
        }
        return 0;
      }
      
      // Get PHP data
      const phpProjectData = <?= json_encode($project_status_data) ?>;
      const phpPhaseData = <?= json_encode($phase_status_data) ?>;
      const phpActivityData = <?= json_encode($activity_status_data) ?>;
      const phpSubData = <?= json_encode($sub_status_data) ?>;
      
      projectCounts[0] = getCountForStatus(phpProjectData, 'Pending');
      projectCounts[1] = getCountForStatus(phpProjectData, 'In Progress');
      projectCounts[2] = getCountForStatus(phpProjectData, 'Completed');
      projectCounts[3] = getCountForStatus(phpProjectData, 'Terminated');
      
      phaseCounts[0] = getCountForStatus(phpPhaseData, 'Pending');
      phaseCounts[1] = getCountForStatus(phpPhaseData, 'In Progress');
      phaseCounts[2] = getCountForStatus(phpPhaseData, 'Completed');
      phaseCounts[3] = getCountForStatus(phpPhaseData, 'Terminated');
      
      activityCounts[0] = getCountForStatus(phpActivityData, 'Pending');
      activityCounts[1] = getCountForStatus(phpActivityData, 'In Progress');
      activityCounts[2] = getCountForStatus(phpActivityData, 'Completed');
      activityCounts[3] = getCountForStatus(phpActivityData, 'Terminated');
      
      subCounts[0] = getCountForStatus(phpSubData, 'Pending');
      subCounts[1] = getCountForStatus(phpSubData, 'In Progress');
      subCounts[2] = getCountForStatus(phpSubData, 'Completed');
      subCounts[3] = getCountForStatus(phpSubData, 'Terminated');
      
      const statusComparisonChart = new Chart(statusComparisonCtx, {
        type: 'bar',
        data: {
          labels: comparisonLabels,
          datasets: [
            {
              label: 'Projects',
              data: projectCounts,
              backgroundColor: '#273274'
            },
            {
              label: 'Phases',
              data: phaseCounts,
              backgroundColor: '#36b9cc'
            },
            {
              label: 'Activities',
              data: activityCounts,
              backgroundColor: '#f6c23e'
            },
            {
              label: 'Sub-Activities',
              data: subCounts,
              backgroundColor: '#1cc88a'
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          scales: {
            y: {
              beginAtZero: true,
              title: {
                display: true,
                text: 'Count'
              }
            }
          },
          plugins: {
            title: {
              display: true,
              text: 'Status Distribution by Entity Type'
            }
          }
        }
      });

      // Enhanced Progress Chart
      const progressCtx = document.getElementById('progressChart').getContext('2d');
      const progressChart = new Chart(progressCtx, {
        type: 'bar',
        data: {
          labels: <?= json_encode(array_column($project_progress, 'name')) ?>,
          datasets: [
            {
              label: 'Phase Progress',
              data: <?= json_encode(array_column($project_progress, 'phase_progress')) ?>,
              backgroundColor: '#36b9cc',
              order: 2
            },
            {
              label: 'Activity Progress',
              data: <?= json_encode(array_column($project_progress, 'activity_progress')) ?>,
              backgroundColor: '#f6c23e',
              order: 3
            },
            {
              label: 'Sub-Activity Progress',
              data: <?= json_encode(array_column($project_progress, 'sub_progress')) ?>,
              backgroundColor: '#1cc88a',
              order: 4
            },
            {
              label: 'Overall Progress',
              data: <?= json_encode(array_column($project_progress, 'overall_progress')) ?>,
              backgroundColor: '#273274',
              type: 'line',
              fill: false,
              borderColor: '#273274',
              borderWidth: 3,
              tension: 0.1,
              order: 1
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          scales: {
            y: {
              beginAtZero: true,
              max: 100,
              title: {
                display: true,
                text: 'Progress (%)'
              }
            }
          },
          plugins: {
            title: {
              display: true,
              text: 'Project Progress by Component'
            }
          }
        }
      });

      // Department Performance Chart
      const departmentCtx = document.getElementById('departmentPerformanceChart').getContext('2d');
      const departmentChart = new Chart(departmentCtx, {
        type: 'bar',
        data: {
          labels: <?= json_encode(array_column($performance_metrics['department_performance'], 'department_name')) ?>,
          datasets: [
            {
              label: 'Total Projects',
              data: <?= json_encode(array_column($performance_metrics['department_performance'], 'project_count')) ?>,
              backgroundColor: '#273274'
            },
            {
              label: 'Completed Projects',
              data: <?= json_encode(array_column($performance_metrics['department_performance'], 'completed_projects')) ?>,
              backgroundColor: '#1cc88a'
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          scales: {
            y: {
              beginAtZero: true,
              title: {
                display: true,
                text: 'Number of Projects'
              }
            }
          },
          plugins: {
            title: {
              display: true,
              text: 'Department Performance Overview'
            }
          }
        }
      });

      // Monthly Trends Chart
      const monthlyCtx = document.getElementById('monthlyTrendsChart').getContext('2d');
      const monthlyChart = new Chart(monthlyCtx, {
        type: 'line',
        data: {
          labels: <?= json_encode(array_column($performance_metrics['monthly_trends'], 'month')) ?>,
          datasets: [
            {
              label: 'Projects Started',
              data: <?= json_encode(array_column($performance_metrics['monthly_trends'], 'projects_started')) ?>,
              borderColor: '#273274',
              backgroundColor: 'rgba(39, 50, 116, 0.1)',
              fill: true,
              tension: 0.4
            },
            {
              label: 'Projects Completed',
              data: <?= json_encode(array_column($performance_metrics['monthly_trends'], 'projects_completed')) ?>,
              borderColor: '#1cc88a',
              backgroundColor: 'rgba(28, 200, 138, 0.1)',
              fill: true,
              tension: 0.4
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          scales: {
            y: {
              beginAtZero: true,
              title: {
                display: true,
                text: 'Number of Projects'
              }
            }
          },
          plugins: {
            title: {
              display: true,
              text: 'Monthly Project Trends'
            }
          }
        }
      });
    });

    // Accordion management functions
    function expandAll() {
      const accordions = document.querySelectorAll('.accordion-collapse');
      accordions.forEach(accordion => {
        new bootstrap.Collapse(accordion, { show: true });
      });
    }

    function collapseAll() {
      const accordions = document.querySelectorAll('.accordion-collapse');
      accordions.forEach(accordion => {
        new bootstrap.Collapse(accordion, { hide: true });
      });
    }

    // Enhanced PDF Export Function with Dashen Bank Logo for ALL tabs
    async function exportToPDF(activeTab) {
      const { jsPDF } = window.jspdf;
      const doc = new jsPDF('l', 'pt', 'a4');
      
      // Try to add Dashen Bank logo using html2canvas
      try {
        // Create a temporary element with the logo
        const logoElement = document.createElement('div');
        logoElement.innerHTML = `
          <div style="text-align: center; margin-bottom: 20px;">
            <img src="Images/DashenLogo1.png" alt="Dashen Bank Logo" style="max-height: 60px;">
          </div>
        `;
        document.body.appendChild(logoElement);
        
        // Use html2canvas to capture the logo
        const canvas = await html2canvas(logoElement, {
          scale: 2,
          useCORS: true,
          backgroundColor: null
        });
        
        const imgData = canvas.toDataURL('image/png');
        const imgWidth = 80; // Adjust width as needed
        const imgHeight = (canvas.height * imgWidth) / canvas.width;
        
        // Add logo to PDF
        doc.addImage(imgData, 'PNG', (doc.internal.pageSize.width - imgWidth) / 2, 20, imgWidth, imgHeight);
        
        // Clean up
        document.body.removeChild(logoElement);
        
        // Add header text below logo
        doc.setFontSize(16);
        doc.setFont(undefined, 'bold');
        doc.text('DASHEN BANK S.C.', doc.internal.pageSize.width / 2, 20 + imgHeight + 20, { align: 'center' });
        
        doc.setFontSize(10);
        doc.setFont(undefined, 'normal');
        doc.text('Advanced Project Reporting System', doc.internal.pageSize.width / 2, 20 + imgHeight + 40, { align: 'center' });
        doc.text('Generated on: ' + new Date().toLocaleDateString(), doc.internal.pageSize.width / 2, 20 + imgHeight + 55, { align: 'center' });
        
        var startY = 20 + imgHeight + 70;
      } catch (error) {
        console.error('Error adding logo:', error);
        // Fallback: Add text header if logo fails
        doc.setFontSize(16);
        doc.setFont(undefined, 'bold');
        doc.text('DASHEN BANK S.C.', doc.internal.pageSize.width / 2, 30, { align: 'center' });
        
        doc.setFontSize(10);
        doc.setFont(undefined, 'normal');
        doc.text('Advanced Project Reporting System', doc.internal.pageSize.width / 2, 50, { align: 'center' });
        doc.text('Generated on: ' + new Date().toLocaleDateString(), doc.internal.pageSize.width / 2, 65, { align: 'center' });
        
        var startY = 80;
      }
      
      // Report title based on active tab
      const reportTitles = {
        'overview': 'Project Overview Report',
        'hierarchy': 'Project Hierarchy Report', 
        'progress': 'Progress Tracking Report',
        'assignments': 'User Assignments Report',
        'analytics': 'Performance Analytics Report'
      };
      
      doc.setFontSize(14);
      doc.setFont(undefined, 'bold');
      doc.text(reportTitles[activeTab] || 'Project Report', doc.internal.pageSize.width / 2, startY, { align: 'center' });
      
      startY += 20;
      
      // Export data based on active tab
      switch(activeTab) {
        case 'overview':
          exportOverviewToPDF(doc, startY);
          break;
        case 'hierarchy':
          exportHierarchyToPDF(doc, startY);
          break;
        case 'progress':
          exportProgressToPDF(doc, startY);
          break;
        case 'assignments':
          exportAssignmentsToPDF(doc, startY);
          break;
        case 'analytics':
          exportAnalyticsToPDF(doc, startY);
          break;
        default:
          exportOverviewToPDF(doc, startY);
      }
      
      // Add page numbers
      const pageCount = doc.internal.getNumberOfPages();
      for (let i = 1; i <= pageCount; i++) {
        doc.setPage(i);
        doc.setFontSize(8);
        doc.text('Page ' + i + ' of ' + pageCount,
          doc.internal.pageSize.width - 60,
          doc.internal.pageSize.height - 10);
      }
      
      doc.save('Dashen_Bank_' + reportTitles[activeTab].replace(/ /g, '_') + '_' + new Date().toISOString().slice(0, 10) + '.pdf');
    }

    function exportOverviewToPDF(doc, startY) {
      // Overview data - projects list
      const overviewTable = document.querySelector('#overview .table');
      if (overviewTable) {
        const headRow = ['Project Name', 'Status', 'Start Date', 'End Date', 'Department'];
        const bodyRows = [];
        
        // Get project data from the hidden data
        const projects = <?= json_encode(getOverviewData($conn, $where_clause, $params, $param_types)) ?>;
        
        projects.forEach(project => {
          bodyRows.push(project);
        });
        
        if (bodyRows.length > 0) {
          doc.autoTable({
            startY: startY,
            head: [headRow],
            body: bodyRows,
            theme: 'grid',
            styles: { fontSize: 8, cellPadding: 3 },
            headStyles: { fillColor: [39, 50, 116] }
          });
        } else {
          doc.setFontSize(12);
          doc.text('No project data available.', 40, startY + 20);
        }
      } else {
        // Summary statistics
        doc.setFontSize(12);
        doc.setFont(undefined, 'bold');
        doc.text('Summary Statistics', 40, startY);
        
        const stats = [
          ['Total Projects', '<?= $total_projects ?>'],
          ['Total Phases', '<?= $total_phases ?>'],
          ['Total Activities', '<?= $total_activities ?>'],
          ['Total Sub-Activities', '<?= $total_subs ?>']
        ];
        
        doc.autoTable({
          startY: startY + 10,
          head: [['Metric', 'Count']],
          body: stats,
          theme: 'grid',
          styles: { fontSize: 10 },
          headStyles: { fillColor: [39, 50, 116] }
        });
      }
    }

    function exportHierarchyToPDF(doc, startY) {
      const hierarchyData = <?= json_encode(getHierarchyData($conn, $where_clause, $params, $param_types)) ?>;
      
      if (hierarchyData.length > 0) {
        const head = ['Project', 'Department', 'Phase', 'Activity', 'Sub-Activity', 'Assigned To', 'Status', 'Start Date', 'End Date'];
        
        doc.autoTable({
          startY: startY,
          head: [head],
          body: hierarchyData,
          theme: 'grid',
          styles: { fontSize: 6, cellPadding: 2 },
          headStyles: { fillColor: [39, 50, 116] }
        });
      } else {
        doc.setFontSize(12);
        doc.text('No hierarchy data available.', 40, startY + 20);
      }
    }

    function exportProgressToPDF(doc, startY) {
      const table = document.getElementById('progressTable');
      if (!table) {
        doc.setFontSize(12);
        doc.text('No progress data available.', 40, startY + 20);
        return;
      }
      
      // Updated headers without "Time Progress" and "Variance" columns
      const headRow = Array.from(table.querySelectorAll('thead th')).map(th => th.innerText.trim());
      const bodyRows = Array.from(table.querySelectorAll('tbody tr'))
        .map(tr => Array.from(tr.querySelectorAll('td')).map(td => {
          // Extract text content from progress bars and badges
          let text = td.innerText.trim();
          // Remove extra whitespace and newlines
          text = text.replace(/\s+/g, ' ').trim();
          return text;
        }));
      
      if (bodyRows.length > 0) {
        doc.autoTable({
          startY: startY,
          head: [headRow],
          body: bodyRows,
          theme: 'grid',
          styles: { fontSize: 7, cellPadding: 3 },
          headStyles: { fillColor: [39, 50, 116] }
        });
      } else {
        doc.setFontSize(12);
        doc.text('No progress data available.', 40, startY + 20);
      }
    }

    function exportAssignmentsToPDF(doc, startY) {
      const table = document.getElementById('assignmentsTable');
      if (table) {
        const headRow = Array.from(table.querySelectorAll('thead th')).map(th => th.innerText.trim());
        const bodyRows = Array.from(table.querySelectorAll('tbody tr'))
          .map(tr => Array.from(tr.querySelectorAll('td')).map(td => {
            let text = td.innerText.trim();
            text = text.replace(/\s+/g, ' ').trim();
            return text;
          }));
        
        if (bodyRows.length > 0) {
          doc.autoTable({
            startY: startY,
            head: [headRow],
            body: bodyRows,
            theme: 'grid',
            styles: { fontSize: 7, cellPadding: 3 },
            headStyles: { fillColor: [39, 50, 116] }
          });
        } else {
          // Fallback to data from PHP
          const assignments = <?= json_encode(getAssignmentData($conn)) ?>;
          const head = ['Username', 'Role', 'Email', 'Total Tasks', 'Completed', 'Overdue', 'Completion Rate'];
          
          if (assignments.length > 0) {
            doc.autoTable({
              startY: startY,
              head: [head],
              body: assignments,
              theme: 'grid',
              styles: { fontSize: 8, cellPadding: 3 },
              headStyles: { fillColor: [39, 50, 116] }
            });
          } else {
            doc.setFontSize(12);
            doc.text('No assignment data available.', 40, startY + 20);
          }
        }
      } else {
        doc.setFontSize(12);
        doc.text('No assignment data available.', 40, startY + 20);
      }
    }

    function exportAnalyticsToPDF(doc, startY) {
      const table = document.getElementById('analyticsTable');
      if (!table) {
        doc.setFontSize(12);
        doc.text('No analytics data available.', 40, startY + 20);
        return;
      }
      
      const headRow = Array.from(table.querySelectorAll('thead th')).map(th => th.innerText.trim());
      const bodyRows = Array.from(table.querySelectorAll('tbody tr'))
        .map(tr => Array.from(tr.querySelectorAll('td')).map(td => td.innerText.trim()));
      
      if (bodyRows.length > 0) {
        doc.autoTable({
          startY: startY,
          head: [headRow],
          body: bodyRows,
          theme: 'grid',
          styles: { fontSize: 8, cellPadding: 3 },
          headStyles: { fillColor: [39, 50, 116] }
        });
      } else {
        doc.setFontSize(12);
        doc.text('No analytics data available.', 40, startY + 20);
      }
    }

    // Handle sidebar state
    document.addEventListener('DOMContentLoaded', function() {
      const sidebarContainer = document.getElementById('sidebarContainer');
      const sidebarToggler = document.getElementById('sidebarToggler');
      const mainContent = document.getElementById('mainContent');
      
      // Toggle sidebar
      if (sidebarToggler) {
        sidebarToggler.addEventListener('click', function() {
          sidebarContainer.classList.toggle('sidebar-collapsed');
          document.body.classList.toggle('sidebar-collapsed');
          
          const isCollapsed = sidebarContainer.classList.contains('sidebar-collapsed');
          localStorage.setItem('sidebarCollapsed', isCollapsed);
        });
      }
      
      // Load saved sidebar state
      if (localStorage.getItem('sidebarCollapsed') === 'true') {
        sidebarContainer.classList.add('sidebar-collapsed');
        document.body.classList.add('sidebar-collapsed');
      }
    });
  </script>
</body>
</html>