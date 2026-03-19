<?php
session_start();
require_once 'config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['system_role'] ?? 'viewer';

// Function to check user roles
function hasRole($role) {
    global $user_role;
    
    if ($role === 'admin') {
        return in_array($user_role, ['admin', 'super_admin']);
    } elseif ($role === 'super_admin') {
        return $user_role === 'super_admin';
    }
    return false;
}

// Only allow admin and project managers to access reports
if (!hasRole('admin') && $user_role !== 'pm_manager') {
    header("Location: issues.php");
    exit();
}

// Default date range (last 30 days)
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-30 days'));
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

// Get filter parameters
$status_filter = isset($_GET['status_filter']) ? $_GET['status_filter'] : '';
$priority_filter = isset($_GET['priority_filter']) ? $_GET['priority_filter'] : '';
$type_filter = isset($_GET['type_filter']) ? $_GET['type_filter'] : '';
$project_filter = isset($_GET['project_filter']) ? $_GET['project_filter'] : '';
$assignee_filter = isset($_GET['assignee_filter']) ? $_GET['assignee_filter'] : '';
$group_by = isset($_GET['group_by']) ? $_GET['group_by'] : 'status';

// Build WHERE conditions for filtered queries
$where_conditions = ["i.created_at BETWEEN ? AND ? + INTERVAL 1 DAY"];
$params = [$start_date, $end_date];
$param_types = 'ss';

// Add filter conditions if provided
if (!empty($status_filter)) {
    $where_conditions[] = "i.status = ?";
    $params[] = $status_filter;
    $param_types .= 's';
}

if (!empty($priority_filter)) {
    $where_conditions[] = "i.priority = ?";
    $params[] = $priority_filter;
    $param_types .= 's';
}

if (!empty($type_filter)) {
    $where_conditions[] = "i.type = ?";
    $params[] = $type_filter;
    $param_types .= 's';
}

if (!empty($project_filter)) {
    $where_conditions[] = "i.project_id = ?";
    $params[] = $project_filter;
    $param_types .= 'i';
}

if (!empty($assignee_filter)) {
    $where_conditions[] = "i.assigned_to = ?";
    $params[] = $assignee_filter;
    $param_types .= 'i';
}

// Prepare WHERE clause
$where_clause = "";
if (!empty($where_conditions)) {
    $where_clause = "WHERE " . implode(" AND ", $where_conditions);
}

// Get consolidated report data based on grouping
$consolidated_data = [];
$group_by_field = '';

switch($group_by) {
    case 'status':
        $group_by_field = 'i.status';
        $query = "SELECT i.status as group_field, COUNT(*) as count FROM issues i $where_clause GROUP BY i.status ORDER BY count DESC";
        break;
    case 'priority':
        $group_by_field = 'i.priority';
        $query = "SELECT i.priority as group_field, COUNT(*) as count FROM issues i $where_clause GROUP BY i.priority ORDER BY FIELD(i.priority, 'critical', 'high', 'medium', 'low')";
        break;
    case 'type':
        $group_by_field = 'i.type';
        $query = "SELECT i.type as group_field, COUNT(*) as count FROM issues i $where_clause GROUP BY i.type ORDER BY count DESC";
        break;
    case 'project':
        $group_by_field = 'p.name';
        $query = "SELECT p.name as group_field, COUNT(i.id) as count FROM issues i LEFT JOIN projects p ON i.project_id = p.id $where_clause GROUP BY i.project_id, p.name ORDER BY count DESC";
        break;
    case 'assignee':
        $group_by_field = 'u.username';
        $query = "SELECT u.username as group_field, COUNT(i.id) as count FROM issues i LEFT JOIN users u ON i.assigned_to = u.id $where_clause GROUP BY i.assigned_to, u.username ORDER BY count DESC";
        break;
    default:
        $group_by_field = 'i.status';
        $query = "SELECT i.status as group_field, COUNT(*) as count FROM issues i $where_clause GROUP BY i.status ORDER BY count DESC";
}

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($param_types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $consolidated_data[] = $row;
}
$stmt->close();

// Get detailed issues data for the table
$detailed_issues_query = "
    SELECT 
        i.id,
        i.title as issue_name,
        i.description,
        i.status,
        i.priority,
        i.type,
        i.created_at,
        i.updated_at,
        p.name as project_name,
        u.username as assigned_to,
        creator.username as created_by
    FROM issues i
    LEFT JOIN projects p ON i.project_id = p.id
    LEFT JOIN users u ON i.assigned_to = u.id
    LEFT JOIN users creator ON i.created_by = creator.id
    $where_clause
    ORDER BY i.created_at DESC
    LIMIT 100
";

$stmt = $conn->prepare($detailed_issues_query);
if (!empty($params)) {
    $stmt->bind_param($param_types, ...$params);
}
$stmt->execute();
$detailed_issues_result = $stmt->get_result();
$detailed_issues = [];

while ($row = $detailed_issues_result->fetch_assoc()) {
    $detailed_issues[] = $row;
}
$stmt->close();

// Get additional stats for the dashboard
$total_issues = 0;
foreach ($consolidated_data as $item) {
    $total_issues += $item['count'];
}

// Get trend data for line chart (last 7 days)
$trend_data = [];
$trend_query = "
    SELECT 
        DATE(created_at) as date,
        COUNT(*) as count,
        SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open_count,
        SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved_count
    FROM issues 
    WHERE created_at BETWEEN ? AND ? + INTERVAL 1 DAY
    GROUP BY DATE(created_at)
    ORDER BY date DESC
    LIMIT 7
";

$stmt = $conn->prepare($trend_query);
$stmt->bind_param("ss", $start_date, $end_date);
$stmt->execute();
$trend_result = $stmt->get_result();

while ($row = $trend_result->fetch_assoc()) {
    $trend_data[] = $row;
}
$stmt->close();

// Get unread notification count
$unread_count = 0;
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$unread_count = $result->fetch_assoc()['count'];
$stmt->close();

// Get data for filter dropdowns
$projects = [];
$project_result = $conn->query("SELECT * FROM projects ORDER BY name");
if ($project_result) {
    while ($row = $project_result->fetch_assoc()) {
        $projects[] = $row;
    }
}

$users = [];
$user_result = $conn->query("SELECT * FROM users ORDER BY username");
if ($user_result) {
    while ($row = $user_result->fetch_assoc()) {
        $users[] = $row;
    }
}

// Include the sidebar
include 'sidebar.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Advanced Reports - Dashen Bank Issue Tracker</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- Include jsPDF library -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js"></script>
    <!-- Include SheetJS library -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <style>
        :root {
            --dashen-primary: #273274;
            --dashen-secondary: #1e2559;
            --dashen-accent: #f58220;
            --dashen-light: #f5f7fb;
            --dashen-success: #2dce89;
            --dashen-warning: #fb6340;
            --dashen-info: #11cdef;
            --dashen-dark: #32325d;
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.1);
            --shadow-md: 0 4px 6px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 15px rgba(0, 0, 0, 0.1);
            --shadow-xl: 0 20px 25px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
            --border-radius: 16px;
            --border-radius-sm: 12px;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #f5f7fb 0%, #e4e8f0 100%);
            color: var(--dashen-dark);
            min-height: 100vh;
            overflow-x: hidden;
        }
        
        /* Main Content Styles - Perfectly Aligned with Sidebar */
        .main-content {
            margin-left: 280px;
            width: calc(100% - 280px);
            padding: 30px;
            min-height: 100vh;
            transition: var(--transition);
        }
        
        .main-content.expanded {
            margin-left: 80px;
            width: calc(100% - 80px);
        }
        
        /* Premium Header */
        .dashboard-header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: var(--shadow-sm);
            position: sticky;
            top: 0;
            z-index: 100;
            border-radius: var(--border-radius);
            margin-bottom: 30px;
        }
        
        .header-brand {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .header-logo {
            height: 40px;
            width: auto;
        }
        
        .brand-text {
            font-weight: 700;
            color: var(--dashen-primary);
            font-size: 1.4rem;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .user-greeting {
            margin: 0;
            font-size: 0.9rem;
            color: var(--dashen-dark);
        }
        
        .user-name {
            font-weight: 600;
            color: var(--dashen-primary);
        }
        
        .header-btn {
            padding: 10px 20px;
            border-radius: var(--border-radius-sm);
            text-decoration: none;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: var(--transition);
            font-weight: 500;
        }
        
        .header-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        
        .profile-btn {
            background: var(--dashen-light);
            color: var(--dashen-dark);
            border: 1px solid #e9ecef;
        }
        
        .profile-btn:hover {
            background: white;
            color: var(--dashen-primary);
        }
        
        .logout-btn {
            background: linear-gradient(135deg, var(--dashen-primary), var(--dashen-secondary));
            color: white;
            border: none;
        }
        
        .logout-btn:hover {
            background: linear-gradient(135deg, var(--dashen-secondary), var(--dashen-primary));
            color: white;
        }
        
        /* Enhanced Dashboard Card */
        .dashboard-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-xl);
            padding: 40px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            position: relative;
            overflow: hidden;
            margin-bottom: 30px;
            transition: var(--transition);
        }
        
        .dashboard-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-xl);
        }
        
        .dashboard-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 6px;
            background: linear-gradient(90deg, var(--dashen-primary), var(--dashen-accent));
        }
        
        .welcome-title {
            font-weight: 800;
            font-size: 3rem;
            margin-bottom: 1rem;
            background: linear-gradient(135deg, var(--dashen-primary), var(--dashen-accent));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            position: relative;
        }
        
        .welcome-subtitle {
            color: #6c757d;
            font-weight: 400;
            margin-bottom: 2.5rem;
            font-size: 1.2rem;
            max-width: 600px;
            line-height: 1.6;
        }
        
        /* Enhanced Form Styles */
        .form-label {
            font-weight: 700;
            margin-bottom: 10px;
            color: var(--dashen-primary);
            font-size: 0.95rem;
        }
        
        .required-field::after {
            content: " *";
            color: #e53e3e;
        }
        
        .form-control, .form-select {
            border-radius: var(--border-radius-sm);
            padding: 14px 16px;
            border: 2px solid #e2e8f0;
            transition: var(--transition);
            font-size: 0.95rem;
            background: rgba(255, 255, 255, 0.9);
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--dashen-primary);
            box-shadow: 0 0 0 3px rgba(39, 50, 116, 0.15);
            background: white;
            transform: translateY(-2px);
        }
        
        /* Enhanced Button Styles */
        .btn-modern {
            padding: 14px 28px;
            border-radius: var(--border-radius-sm);
            font-weight: 700;
            font-size: 1rem;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            border: none;
            box-shadow: var(--shadow-md);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .btn-primary-modern {
            background: linear-gradient(135deg, var(--dashen-primary), var(--dashen-secondary));
            color: white;
        }
        
        .btn-primary-modern:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
            background: linear-gradient(135deg, var(--dashen-secondary), var(--dashen-primary));
        }
        
        .btn-outline-secondary {
            border: 2px solid #6c757d;
            color: #6c757d;
            background: transparent;
            font-weight: 600;
        }
        
        .btn-outline-secondary:hover {
            background-color: #6c757d;
            border-color: #6c757d;
            color: white;
            transform: translateY(-2px);
        }
        
        .user-role-badge {
            background: linear-gradient(135deg, var(--dashen-primary), var(--dashen-secondary));
            color: white;
            padding: 8px 20px;
            border-radius: 25px;
            font-size: 0.9rem;
            font-weight: 600;
            display: inline-block;
            margin-bottom: 25px;
            box-shadow: var(--shadow-md);
        }
        
        /* Premium Stat Cards */
        .stat-card {
            border-radius: var(--border-radius);
            padding: 25px;
            color: white;
            margin-bottom: 25px;
            box-shadow: var(--shadow-lg);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
            border: none;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, rgba(255,255,255,0.1) 0%, rgba(255,255,255,0) 100%);
            opacity: 0;
            transition: var(--transition);
        }
        
        .stat-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: var(--shadow-xl);
        }
        
        .stat-card:hover::before {
            opacity: 1;
        }
        
        .stat-card i {
            font-size: 3rem;
            opacity: 0.9;
            margin-bottom: 15px;
        }
        
        .stat-card .card-title {
            font-size: 0.9rem;
            font-weight: 500;
            margin-bottom: 8px;
            opacity: 0.9;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .stat-card .card-value {
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 0;
            text-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        /* Enhanced Filter Section */
        .filter-section {
            background: rgba(248, 249, 250, 0.8);
            backdrop-filter: blur(10px);
            border-radius: var(--border-radius);
            padding: 25px;
            margin-bottom: 30px;
            border: 1px solid rgba(255, 255, 255, 0.3);
            box-shadow: var(--shadow-md);
        }
        
        .filter-section .form-label {
            font-weight: 600;
            color: var(--dashen-primary);
            margin-bottom: 8px;
        }
        
        .filter-toggle {
            cursor: pointer;
            padding: 15px;
            background: linear-gradient(135deg, var(--dashen-light), #e8ecf4);
            border-radius: var(--border-radius-sm);
            margin-bottom: 20px;
            font-weight: 700;
            color: var(--dashen-primary);
            display: flex;
            align-items: center;
            justify-content: space-between;
            transition: var(--transition);
        }
        
        .filter-toggle:hover {
            background: linear-gradient(135deg, #e8ecf4, var(--dashen-light));
        }
        
        .advanced-filters {
            display: none;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.5);
        }
        
        /* Enhanced Table Styles */
        .table-container {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-lg);
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .table-header {
            background: linear-gradient(135deg, var(--dashen-primary), var(--dashen-secondary));
            color: white;
            padding: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .table-title {
            margin: 0;
            font-weight: 700;
            font-size: 1.3rem;
        }
        
        .table-responsive {
            border-radius: 0 0 var(--border-radius) var(--border-radius);
        }
        
        .table th {
            font-weight: 700;
            border-top: none;
            border-bottom: 2px solid #e9ecef;
            padding: 20px;
            background: var(--dashen-light);
            color: var(--dashen-primary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-size: 0.85rem;
        }
        
        .table td {
            padding: 18px 20px;
            vertical-align: middle;
            border-color: #f8f9fa;
        }
        
        .table-hover tbody tr:hover {
            background-color: rgba(39, 50, 116, 0.04);
            transform: scale(1.01);
            transition: var(--transition);
        }
        
        /* Badge Styles */
        .badge-status {
            font-size: 0.75rem;
            padding: 0.5em 1em;
            border-radius: 20px;
            font-weight: 600;
            letter-spacing: 0.3px;
        }
        
        .badge-open {
            background-color: #e9ecef;
            color: #6c757d;
        }
        
        .badge-in-progress {
            background-color: #fff3cd;
            color: #856404;
        }
        
        .badge-resolved {
            background-color: #d1ecf1;
            color: #0c5460;
        }
        
        .badge-closed {
            background-color: #d4edda;
            color: #155724;
        }
        
        .badge-critical {
            background-color: #f8d7da;
            color: #721c24;
        }
        
        .badge-high {
            background-color: #ffeaa7;
            color: #8d7100;
        }
        
        .badge-medium {
            background-color: #d1ecf1;
            color: #0c5460;
        }
        
        .badge-low {
            background-color: #d1f0d9;
            color: #155724;
        }
        
        .issue-name {
            font-weight: 600;
            color: var(--dashen-primary);
            max-width: 250px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        .issue-description {
            color: #6c757d;
            font-size: 0.9rem;
            max-width: 300px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        /* Enhanced Chart Container */
        .chart-container {
            background: white;
            border-radius: var(--border-radius);
            padding: 30px;
            box-shadow: var(--shadow-lg);
            margin-bottom: 30px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            height: 400px;
        }
        
        .chart-title {
            font-weight: 700;
            color: var(--dashen-primary);
            margin-bottom: 20px;
            font-size: 1.2rem;
        }
        
        /* Export buttons */
        .export-buttons {
            display: flex;
            gap: 12px;
            margin-top: 20px;
        }
        
        .export-btn {
            padding: 12px 20px;
            border-radius: var(--border-radius-sm);
            font-weight: 600;
            font-size: 0.9rem;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: none;
            box-shadow: var(--shadow-sm);
        }
        
        .export-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        
        .btn-pdf {
            background: linear-gradient(135deg, #e53e3e, #c53030);
            color: white;
        }
        
        .btn-excel {
            background: linear-gradient(135deg, var(--dashen-success), #24b47e);
            color: white;
        }
        
        /* Loading indicator */
        .export-loading {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 2000;
            justify-content: center;
            align-items: center;
            backdrop-filter: blur(5px);
        }
        
        .loading-spinner {
            background: white;
            padding: 40px;
            border-radius: var(--border-radius);
            text-align: center;
            box-shadow: var(--shadow-xl);
            max-width: 400px;
            width: 90%;
        }
        
        /* Responsive Styles */
        @media (max-width: 1200px) {
            .main-content {
                margin-left: 80px;
                width: calc(100% - 80px);
            }
            
            .main-content.expanded {
                margin-left: 80px;
                width: calc(100% - 80px);
            }
        }
        
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                width: 100%;
                padding: 20px 15px;
            }
            
            .main-content.expanded {
                margin-left: 0;
                width: 100%;
            }
            
            .dashboard-header {
                padding: 15px 20px;
                flex-direction: column;
                gap: 15px;
            }
            
            .dashboard-card {
                padding: 25px 20px;
            }
            
            .welcome-title {
                font-size: 2.2rem;
            }
            
            .header-brand .brand-text {
                font-size: 1.1rem;
            }
            
            .user-info {
                flex-wrap: wrap;
                justify-content: center;
            }
            
            .table-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .export-buttons {
                width: 100%;
                justify-content: flex-start;
                flex-wrap: wrap;
            }
            
            .chart-container {
                height: 300px;
                padding: 20px;
            }
        }

        /* Animation Classes */
        .fade-in {
            animation: fadeIn 0.6s ease-in-out;
        }
        
        .slide-up {
            animation: slideUp 0.6s ease-out;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes slideUp {
            from { 
                opacity: 0;
                transform: translateY(30px);
            }
            to { 
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* Insight Cards */
        .insight-card {
            background: white;
            border-radius: var(--border-radius-sm);
            padding: 20px;
            margin-bottom: 15px;
            box-shadow: var(--shadow-sm);
            border-left: 4px solid var(--dashen-accent);
            transition: var(--transition);
        }
        
        .insight-card:hover {
            transform: translateX(5px);
            box-shadow: var(--shadow-md);
        }
        
        .insight-icon {
            font-size: 1.5rem;
            margin-bottom: 10px;
            color: var(--dashen-primary);
        }
        
        .insight-text {
            font-size: 0.9rem;
            color: var(--dashen-dark);
            line-height: 1.4;
        }
    </style>
</head>
<body>
    <!-- Loading Indicator -->
    <div class="export-loading" id="exportLoading">
        <div class="loading-spinner">
            <div class="spinner-border text-primary mb-3" role="status" style="width: 3rem; height: 3rem;">
                <span class="visually-hidden">Loading...</span>
            </div>
            <h5 id="loadingText">Preparing your export...</h5>
            <p class="text-muted mt-2">This may take a few moments</p>
        </div>
    </div>

    <!-- Include Sidebar -->
    <?php include 'sidebar.php'; ?>
    
    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <!-- Premium Header with Dashen Logo -->
        <header class="dashboard-header fade-in">
            <div class="header-brand">
                <img src="../Images/DashenLogo1.png" alt="Dashen Bank" class="header-logo">
                <div class="brand-text">Advanced Analytics & Reports</div>
            </div>
            <div class="user-info">
                <p class="user-greeting">Welcome back, <span class="user-name"><?php echo $_SESSION['username']; ?></span></p>
                <a href="../profile.php" class="header-btn profile-btn">
                    <i class="bi bi-person-circle"></i> My Profile
                </a>
                <a href="../logout.php" class="header-btn logout-btn">
                    <i class="bi bi-box-arrow-right"></i> Logout
                </a>
            </div>
        </header>
        
        <!-- Reports Content -->
        <div class="dashboard-card slide-up">
            <span class="user-role-badge">
                <i class="bi bi-shield-check me-2"></i><?php echo ucfirst(str_replace('_', ' ', $user_role)); ?> Access Level
            </span>
            
            <h1 class="welcome-title">Advanced Analytics Dashboard</h1>
            <p class="welcome-subtitle">Comprehensive insights and detailed analytics for issue tracking, team performance, and project metrics with interactive visualizations and export capabilities.</p>
            
            <!-- Summary Stats -->
            <div class="row mb-5">
                <div class="col-md-3">
                    <div class="stat-card" style="background: linear-gradient(135deg, var(--dashen-primary), #3a4a9a);">
                        <div class="d-flex align-items-center">
                            <div class="flex-grow-1">
                                <p class="card-title">Total Issues</p>
                                <h3 class="card-value"><?php echo $total_issues; ?></h3>
                            </div>
                            <div>
                                <i class="bi bi-clipboard-check"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card" style="background: linear-gradient(135deg, var(--dashen-warning), #fd7e5a);">
                        <div class="d-flex align-items-center">
                            <div class="flex-grow-1">
                                <p class="card-title">Categories</p>
                                <h3 class="card-value"><?php echo count($consolidated_data); ?></h3>
                            </div>
                            <div>
                                <i class="bi bi-diagram-3"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card" style="background: linear-gradient(135deg, var(--dashen-accent), #e6731a);">
                        <div class="d-flex align-items-center">
                            <div class="flex-grow-1">
                                <p class="card-title">Highest</p>
                                <h3 class="card-value">
                                    <?php 
                                    $max_count = 0;
                                    foreach ($consolidated_data as $item) {
                                        if ($item['count'] > $max_count) {
                                            $max_count = $item['count'];
                                        }
                                    }
                                    echo $max_count;
                                    ?>
                                </h3>
                            </div>
                            <div>
                                <i class="bi bi-graph-up-arrow"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card" style="background: linear-gradient(135deg, var(--dashen-success), #3ddc97);">
                        <div class="d-flex align-items-center">
                            <div class="flex-grow-1">
                                <p class="card-title">Average</p>
                                <h3 class="card-value">
                                    <?php 
                                    $avg_count = $total_issues > 0 ? round($total_issues / count($consolidated_data), 1) : 0;
                                    echo $avg_count;
                                    ?>
                                </h3>
                            </div>
                            <div>
                                <i class="bi bi-calculator"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Date Range Filter -->
            <div class="filter-section">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label for="start_date" class="form-label">Start Date</label>
                        <input type="date" class="form-control" id="start_date" name="start_date" value="<?php echo $start_date; ?>">
                    </div>
                    <div class="col-md-3">
                        <label for="end_date" class="form-label">End Date</label>
                        <input type="date" class="form-control" id="end_date" name="end_date" value="<?php echo $end_date; ?>">
                    </div>
                    <div class="col-md-3">
                        <label for="group_by" class="form-label">Group By</label>
                        <select class="form-select" id="group_by" name="group_by">
                            <option value="status" <?php echo $group_by == 'status' ? 'selected' : ''; ?>>Status</option>
                            <option value="priority" <?php echo $group_by == 'priority' ? 'selected' : ''; ?>>Priority</option>
                            <option value="type" <?php echo $group_by == 'type' ? 'selected' : ''; ?>>Type</option>
                            <option value="project" <?php echo $group_by == 'project' ? 'selected' : ''; ?>>Project</option>
                            <option value="assignee" <?php echo $group_by == 'assignee' ? 'selected' : ''; ?>>Assignee</option>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary-modern w-100">
                            <i class="bi bi-filter me-2"></i> Apply Filters
                        </button>
                    </div>
                    
                    <div class="col-12">
                        <div class="filter-toggle" onclick="toggleAdvancedFilters()">
                            <span><i class="bi bi-funnel me-2"></i> Advanced Filters</span>
                            <i class="bi bi-chevron-down" id="filterToggleIcon"></i>
                        </div>
                        
                        <!-- Advanced Filters -->
                        <div id="advancedFilters" class="advanced-filters">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label for="status_filter" class="form-label">Status</label>
                                    <select class="form-select" id="status_filter" name="status_filter">
                                        <option value="">All Statuses</option>
                                        <option value="open" <?php echo $status_filter == 'open' ? 'selected' : ''; ?>>Open</option>
                                        <option value="in_progress" <?php echo $status_filter == 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                        <option value="resolved" <?php echo $status_filter == 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                                        <option value="closed" <?php echo $status_filter == 'closed' ? 'selected' : ''; ?>>Closed</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label for="priority_filter" class="form-label">Priority</label>
                                    <select class="form-select" id="priority_filter" name="priority_filter">
                                        <option value="">All Priorities</option>
                                        <option value="low" <?php echo $priority_filter == 'low' ? 'selected' : ''; ?>>Low</option>
                                        <option value="medium" <?php echo $priority_filter == 'medium' ? 'selected' : ''; ?>>Medium</option>
                                        <option value="high" <?php echo $priority_filter == 'high' ? 'selected' : ''; ?>>High</option>
                                        <option value="critical" <?php echo $priority_filter == 'critical' ? 'selected' : ''; ?>>Critical</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label for="type_filter" class="form-label">Type</label>
                                    <select class="form-select" id="type_filter" name="type_filter">
                                        <option value="">All Types</option>
                                        <option value="bug" <?php echo $type_filter == 'bug' ? 'selected' : ''; ?>>Bug</option>
                                        <option value="feature" <?php echo $type_filter == 'feature' ? 'selected' : ''; ?>>Feature</option>
                                        <option value="task" <?php echo $type_filter == 'task' ? 'selected' : ''; ?>>Task</option>
                                        <option value="improvement" <?php echo $type_filter == 'improvement' ? 'selected' : ''; ?>>Improvement</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label for="project_filter" class="form-label">Project</label>
                                    <select class="form-select" id="project_filter" name="project_filter">
                                        <option value="">All Projects</option>
                                        <?php foreach ($projects as $project): ?>
                                        <option value="<?php echo $project['id']; ?>" <?php echo $project_filter == $project['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($project['name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label for="assignee_filter" class="form-label">Assignee</label>
                                    <select class="form-select" id="assignee_filter" name="assignee_filter">
                                        <option value="">All Assignees</option>
                                        <?php foreach ($users as $user): ?>
                                        <option value="<?php echo $user['id']; ?>" <?php echo $assignee_filter == $user['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($user['username']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <a href="reports.php" class="btn btn-outline-secondary">
                                        <i class="bi bi-arrow-clockwise me-2"></i> Reset Filters
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Charts Section -->
            <div class="row mb-5">
                <div class="col-md-8">
                    <div class="chart-container">
                        <h5 class="chart-title">Issues by <?php echo ucfirst($group_by); ?> (Bar Chart)</h5>
                        <canvas id="reportChart"></canvas>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="chart-container">
                        <h5 class="chart-title">Distribution (Pie Chart)</h5>
                        <canvas id="pieChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Trend Chart -->
            <?php if (!empty($trend_data)): ?>
            <div class="chart-container mb-5">
                <h5 class="chart-title">Issue Trends (Last 7 Days)</h5>
                <canvas id="trendChart"></canvas>
            </div>
            <?php endif; ?>

            <!-- Consolidated Report Table -->
            <div class="table-container">
                <div class="table-header">
                    <h4 class="table-title">
                        <i class="bi bi-table me-2"></i>Detailed Issues Report
                        <small class="d-block mt-1 opacity-75">Showing <?php echo count($detailed_issues); ?> of <?php echo $total_issues; ?> issues</small>
                    </h4>
                    <div class="export-buttons">
                        <button class="export-btn btn-pdf" onclick="exportToPDF()">
                            <i class="bi bi-file-pdf me-2"></i> Export PDF
                        </button>
                        <button class="export-btn btn-excel" onclick="exportToExcel()">
                            <i class="bi bi-file-spreadsheet me-2"></i> Export Excel
                        </button>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover" id="issuesTable">
                        <thead>
                            <tr>
                                <th>Issue Name</th>
                                <th>Description</th>
                                <th>Status</th>
                                <th>Priority</th>
                                <th>Type</th>
                                <th>Project</th>
                                <th>Assignee</th>
                                <th>Created</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($detailed_issues)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-5">
                                    <i class="bi bi-inbox display-4 text-muted d-block mb-3"></i>
                                    <h5 class="text-muted">No issues found</h5>
                                    <p class="text-muted">Try adjusting your filters to see more results</p>
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($detailed_issues as $issue): 
                                // Determine badge classes
                                $status_badge_class = '';
                                switch($issue['status']) {
                                    case 'open': $status_badge_class = 'badge-open'; break;
                                    case 'in_progress': $status_badge_class = 'badge-in-progress'; break;
                                    case 'resolved': $status_badge_class = 'badge-resolved'; break;
                                    case 'closed': $status_badge_class = 'badge-closed'; break;
                                    default: $status_badge_class = 'badge-open';
                                }
                                
                                $priority_badge_class = '';
                                switch($issue['priority']) {
                                    case 'critical': $priority_badge_class = 'badge-critical'; break;
                                    case 'high': $priority_badge_class = 'badge-high'; break;
                                    case 'medium': $priority_badge_class = 'badge-medium'; break;
                                    case 'low': $priority_badge_class = 'badge-low'; break;
                                    default: $priority_badge_class = 'badge-medium';
                                }
                            ?>
                            <tr>
                                <td>
                                    <div class="issue-name" title="<?php echo htmlspecialchars($issue['issue_name']); ?>">
                                        <?php echo htmlspecialchars($issue['issue_name']); ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="issue-description" title="<?php echo htmlspecialchars($issue['description']); ?>">
                                        <?php echo htmlspecialchars($issue['description']); ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge-status <?php echo $status_badge_class; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $issue['status'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge-status <?php echo $priority_badge_class; ?>">
                                        <?php echo ucfirst($issue['priority']); ?>
                                    </span>
                                </td>
                                <td><?php echo ucfirst($issue['type']); ?></td>
                                <td><?php echo htmlspecialchars($issue['project_name']); ?></td>
                                <td><?php echo htmlspecialchars($issue['assigned_to']); ?></td>
                                <td><?php echo date('M j, Y', strtotime($issue['created_at'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Insights and Summary -->
            <div class="row mt-5">
                <div class="col-md-6">
                    <div class="dashboard-card">
                        <h5 class="mb-4">
                            <i class="bi bi-graph-up me-2"></i>Report Summary
                        </h5>
                        <div class="row">
                            <div class="col-6">
                                <p class="mb-2"><strong>Date Range:</strong></p>
                                <p class="mb-2"><strong>Total Issues:</strong></p>
                                <p class="mb-2"><strong>Categories:</strong></p>
                                <p class="mb-2"><strong>Active Filters:</strong></p>
                                <p class="mb-0"><strong>Period:</strong></p>
                            </div>
                            <div class="col-6">
                                <p class="mb-2"><?php echo date('M j, Y', strtotime($start_date)); ?> - <?php echo date('M j, Y', strtotime($end_date)); ?></p>
                                <p class="mb-2"><?php echo $total_issues; ?> issues</p>
                                <p class="mb-2"><?php echo count($consolidated_data); ?> <?php echo $group_by; ?> categories</p>
                                <p class="mb-2"><?php echo count($params) - 2; ?> filters applied</p>
                                <p class="mb-0"><?php echo round((strtotime($end_date) - strtotime($start_date)) / (60 * 60 * 24)); ?> days</p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="dashboard-card">
                        <h5 class="mb-4">
                            <i class="bi bi-lightbulb me-2"></i>Key Insights
                        </h5>
                        <div class="insight-card">
                            <div class="insight-icon">
                                <i class="bi bi-trophy"></i>
                            </div>
                            <div class="insight-text">
                                <strong>Top Category:</strong> 
                                <?php 
                                $top_category = '';
                                $top_count = 0;
                                foreach ($consolidated_data as $item) {
                                    if ($item['count'] > $top_count) {
                                        $top_count = $item['count'];
                                        $top_category = $item['group_field'];
                                    }
                                }
                                echo ucfirst(str_replace('_', ' ', $top_category)) . " with {$top_count} issues";
                                ?>
                            </div>
                        </div>
                        <div class="insight-card">
                            <div class="insight-icon">
                                <i class="bi bi-speedometer2"></i>
                            </div>
                            <div class="insight-text">
                                <strong>Distribution:</strong> Average of <?php echo $avg_count; ?> issues per category with <?php echo $max_count; ?> as the highest
                            </div>
                        </div>
                        <div class="insight-card">
                            <div class="insight-icon">
                                <i class="bi bi-funnel"></i>
                            </div>
                            <div class="insight-text">
                                <strong>Data Quality:</strong> Report covers <?php echo count($detailed_issues); ?> detailed records from <?php echo $total_issues; ?> total issues
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Initialize jsPDF
        const { jsPDF } = window.jspdf;

        // Sidebar toggle functionality
        document.addEventListener('DOMContentLoaded', function() {
            const sidebarToggleBtn = document.getElementById('sidebarToggleBtn');
            const mainContent = document.getElementById('mainContent');
            
            if (sidebarToggleBtn && mainContent) {
                sidebarToggleBtn.addEventListener('click', function() {
                    mainContent.classList.toggle('expanded');
                });
            }

            // Auto-collapse on medium screens
            function checkScreenSize() {
                if (window.innerWidth <= 1200 && window.innerWidth > 992) {
                    mainContent.classList.add('expanded');
                } else if (window.innerWidth > 1200) {
                    mainContent.classList.remove('expanded');
                }
            }
            
            window.addEventListener('load', checkScreenSize);
            window.addEventListener('resize', checkScreenSize);
            
            // Initialize charts
            createBarChart();
            createPieChart();
            <?php if (!empty($trend_data)): ?>
            createTrendChart();
            <?php endif; ?>
        });

        // Toggle advanced filters
        function toggleAdvancedFilters() {
            const advancedFilters = document.getElementById('advancedFilters');
            const toggleIcon = document.getElementById('filterToggleIcon');
            
            if (advancedFilters.style.display === 'none' || advancedFilters.style.display === '') {
                advancedFilters.style.display = 'block';
                toggleIcon.classList.remove('bi-chevron-down');
                toggleIcon.classList.add('bi-chevron-up');
            } else {
                advancedFilters.style.display = 'none';
                toggleIcon.classList.remove('bi-chevron-up');
                toggleIcon.classList.add('bi-chevron-down');
            }
        }
        
        // Check if advanced filters should be shown (if any filter is selected)
        <?php if (!empty($status_filter) || !empty($priority_filter) || !empty($type_filter) || !empty($project_filter) || !empty($assignee_filter)): ?>
        document.addEventListener('DOMContentLoaded', function() {
            toggleAdvancedFilters();
        });
        <?php endif; ?>
        
        // Create Bar Chart
        function createBarChart() {
            const ctx = document.getElementById('reportChart').getContext('2d');
            
            // Prepare data for chart
            const labels = [];
            const data = [];
            const backgroundColors = [];
            
            <?php 
            $chartColors = [
                '#273274', '#1e2559', '#f58220', '#2dce89', '#fb6340', 
                '#11cdef', '#5e72e4', '#825ee4', '#2d3748', '#4a5568'
            ];
            
            foreach ($consolidated_data as $index => $item): 
                $colorIndex = $index % count($chartColors);
            ?>
                labels.push('<?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $item['group_field']))); ?>');
                data.push(<?php echo $item['count']; ?>);
                backgroundColors.push('<?php echo $chartColors[$colorIndex]; ?>');
            <?php endforeach; ?>
            
            const reportChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Number of Issues',
                        data: data,
                        backgroundColor: backgroundColors,
                        borderColor: backgroundColors.map(color => color),
                        borderWidth: 2,
                        borderRadius: 8,
                        borderSkipped: false,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        title: {
                            display: true,
                            text: 'Issues by <?php echo ucfirst($group_by); ?>',
                            font: {
                                size: 16,
                                weight: 'bold'
                            },
                            padding: 20
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const total = <?php echo $total_issues; ?>;
                                    const value = context.raw;
                                    const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                    return `${value} issues (${percentage}%)`;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Number of Issues',
                                font: {
                                    weight: 'bold'
                                }
                            },
                            grid: {
                                color: 'rgba(0, 0, 0, 0.1)'
                            }
                        },
                        x: {
                            title: {
                                display: true,
                                text: '<?php echo ucfirst($group_by); ?>',
                                font: {
                                    weight: 'bold'
                                }
                            },
                            grid: {
                                display: false
                            }
                        }
                    },
                    animation: {
                        duration: 2000,
                        easing: 'easeOutQuart'
                    }
                }
            });
        }
        
        // Create Pie Chart
        function createPieChart() {
            const ctx = document.getElementById('pieChart').getContext('2d');
            
            // Prepare data for chart
            const labels = [];
            const data = [];
            const backgroundColors = [];
            
            <?php 
            $pieColors = [
                '#273274', '#1e2559', '#f58220', '#2dce89', '#fb6340', 
                '#11cdef', '#5e72e4', '#825ee4', '#2d3748', '#4a5568'
            ];
            
            foreach ($consolidated_data as $index => $item): 
                $colorIndex = $index % count($pieColors);
            ?>
                labels.push('<?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $item['group_field']))); ?>');
                data.push(<?php echo $item['count']; ?>);
                backgroundColors.push('<?php echo $pieColors[$colorIndex]; ?>');
            <?php endforeach; ?>
            
            const pieChart = new Chart(ctx, {
                type: 'pie',
                data: {
                    labels: labels,
                    datasets: [{
                        data: data,
                        backgroundColor: backgroundColors,
                        borderColor: 'white',
                        borderWidth: 2,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 20,
                                usePointStyle: true,
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const total = <?php echo $total_issues; ?>;
                                    const value = context.raw;
                                    const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                    return `${context.label}: ${value} (${percentage}%)`;
                                }
                            }
                        }
                    },
                    animation: {
                        animateScale: true,
                        animateRotate: true
                    }
                }
            });
        }
        
        // Create Trend Chart
        function createTrendChart() {
            const ctx = document.getElementById('trendChart').getContext('2d');
            
            // Prepare data for trend chart
            const labels = [];
            const totalData = [];
            const openData = [];
            const resolvedData = [];
            
            <?php 
            $trend_data = array_reverse($trend_data); // Reverse to show chronological order
            foreach ($trend_data as $trend): 
            ?>
                labels.push('<?php echo date('M j', strtotime($trend['date'])); ?>');
                totalData.push(<?php echo $trend['count']; ?>);
                openData.push(<?php echo $trend['open_count']; ?>);
                resolvedData.push(<?php echo $trend['resolved_count']; ?>);
            <?php endforeach; ?>
            
            const trendChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: 'Total Issues',
                            data: totalData,
                            borderColor: '#273274',
                            backgroundColor: 'rgba(39, 50, 116, 0.1)',
                            borderWidth: 3,
                            fill: true,
                            tension: 0.4
                        },
                        {
                            label: 'Open Issues',
                            data: openData,
                            borderColor: '#f58220',
                            backgroundColor: 'rgba(245, 130, 32, 0.1)',
                            borderWidth: 2,
                            fill: true,
                            tension: 0.4
                        },
                        {
                            label: 'Resolved Issues',
                            data: resolvedData,
                            borderColor: '#2dce89',
                            backgroundColor: 'rgba(45, 206, 137, 0.1)',
                            borderWidth: 2,
                            fill: true,
                            tension: 0.4
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        title: {
                            display: true,
                            text: 'Issue Trends Over Time',
                            font: {
                                size: 16,
                                weight: 'bold'
                            },
                            padding: 20
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Number of Issues'
                            }
                        },
                        x: {
                            title: {
                                display: true,
                                text: 'Date'
                            }
                        }
                    }
                }
            });
        }
        
        // Show loading indicator
        function showLoading(message = 'Preparing your export...') {
            document.getElementById('loadingText').textContent = message;
            document.getElementById('exportLoading').style.display = 'flex';
        }
        
        // Hide loading indicator
        function hideLoading() {
            document.getElementById('exportLoading').style.display = 'none';
        }
        
        // Export to PDF function
        function exportToPDF() {
            showLoading('Generating PDF report...');
            
            // Use setTimeout to allow the UI to update
            setTimeout(() => {
                try {
                    const doc = new jsPDF('landscape');
                    
                    // Add header with Dashen Bank logo (text-based)
                    doc.setFontSize(24);
                    doc.setTextColor(39, 50, 116); // Dashen primary color
                    doc.text('DASHEN BANK', 105, 25, { align: 'center' });
                    
                    doc.setFontSize(16);
                    doc.setTextColor(100, 100, 100);
                    doc.text('Issue Tracker Analytics Report', 105, 35, { align: 'center' });
                    
                    // Add date range and summary
                    doc.setFontSize(12);
                    doc.setTextColor(80, 80, 80);
                    const startDate = '<?php echo date("M j, Y", strtotime($start_date)); ?>';
                    const endDate = '<?php echo date("M j, Y", strtotime($end_date)); ?>';
                    doc.text(`Report Period: ${startDate} to ${endDate}`, 20, 50);
                    doc.text(`Total Issues: <?php echo $total_issues; ?>`, 20, 58);
                    doc.text(`Grouped By: <?php echo ucfirst($group_by); ?>`, 20, 66);
                    doc.text(`Generated: ${new Date().toLocaleDateString()}`, 20, 74);
                    
                    // Add summary table
                    doc.autoTable({
                        startY: 85,
                        head: [['Category', 'Count', 'Percentage']],
                        body: getSummaryData(),
                        styles: {
                            fontSize: 10,
                            cellPadding: 5,
                        },
                        headStyles: {
                            fillColor: [39, 50, 116], // Dashen primary color
                            textColor: 255,
                            fontStyle: 'bold'
                        },
                        alternateRowStyles: {
                            fillColor: [245, 247, 251] // Dashen light color
                        },
                        margin: { top: 10 }
                    });
                    
                    // Add detailed issues table on a new page
                    doc.addPage();
                    doc.setFontSize(16);
                    doc.setTextColor(39, 50, 116);
                    doc.text('Detailed Issues Report', 20, 25);
                    
                    doc.autoTable({
                        startY: 35,
                        head: [['Issue Name', 'Status', 'Priority', 'Type', 'Project', 'Assignee', 'Created']],
                        body: getTableData(),
                        styles: {
                            fontSize: 8,
                            cellPadding: 3,
                        },
                        headStyles: {
                            fillColor: [39, 50, 116],
                            textColor: 255,
                            fontStyle: 'bold'
                        },
                        alternateRowStyles: {
                            fillColor: [245, 247, 251]
                        },
                        margin: { top: 10 },
                        pageBreak: 'auto'
                    });
                    
                    // Add footer to all pages
                    const pageCount = doc.internal.getNumberOfPages();
                    for (let i = 1; i <= pageCount; i++) {
                        doc.setPage(i);
                        doc.setFontSize(8);
                        doc.setTextColor(150, 150, 150);
                        doc.text(`Page ${i} of ${pageCount}`, 105, doc.internal.pageSize.height - 10, { align: 'center' });
                        doc.text('Confidential - Dashen Bank Internal Use Only', 105, doc.internal.pageSize.height - 5, { align: 'center' });
                    }
                    
                    // Save the PDF
                    doc.save(`Dashen_Issue_Report_${startDate.replace(/ /g, '_')}_to_${endDate.replace(/ /g, '_')}.pdf`);
                    
                    hideLoading();
                } catch (error) {
                    console.error('Error generating PDF:', error);
                    hideLoading();
                    alert('Error generating PDF. Please try again.');
                }
            }, 500);
        }
        
        // Export to Excel function
        function exportToExcel() {
            showLoading('Generating Excel report...');
            
            // Use setTimeout to allow the UI to update
            setTimeout(() => {
                try {
                    // Create workbook
                    const wb = XLSX.utils.book_new();
                    
                    // Add summary sheet
                    const summaryData = [
                        ['DASHEN BANK - ISSUE TRACKER ANALYTICS REPORT'],
                        [''],
                        ['Report Period', '<?php echo date("M j, Y", strtotime($start_date)); ?> to <?php echo date("M j, Y", strtotime($end_date)); ?>'],
                        ['Total Issues', <?php echo $total_issues; ?>],
                        ['Categories', <?php echo count($consolidated_data); ?>],
                        ['Grouped By', '<?php echo ucfirst($group_by); ?>'],
                        ['Highest Category Count', <?php echo $max_count; ?>],
                        ['Average per Category', <?php echo $avg_count; ?>],
                        [''],
                        ['Generated on', new Date().toLocaleDateString()],
                        [''],
                        ['CATEGORY BREAKDOWN'],
                        ['Category', 'Count', 'Percentage']
                    ];
                    
                    <?php 
                    foreach ($consolidated_data as $item): 
                        $percentage = $total_issues > 0 ? round(($item['count'] / $total_issues) * 100, 1) : 0;
                    ?>
                        summaryData.push([
                            '<?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $item['group_field']))); ?>',
                            <?php echo $item['count']; ?>,
                            <?php echo $percentage; ?>
                        ]);
                    <?php endforeach; ?>
                    
                    const summaryWs = XLSX.utils.aoa_to_sheet(summaryData);
                    XLSX.utils.book_append_sheet(wb, summaryWs, 'Summary');
                    
                    // Add issues data sheet
                    const issuesData = getTableDataForExcel();
                    const issuesWs = XLSX.utils.json_to_sheet(issuesData);
                    XLSX.utils.book_append_sheet(wb, issuesWs, 'Detailed Issues');
                    
                    // Add trend data sheet if available
                    <?php if (!empty($trend_data)): ?>
                    const trendData = [
                        ['DATE', 'TOTAL ISSUES', 'OPEN ISSUES', 'RESOLVED ISSUES']
                    ];
                    
                    <?php foreach ($trend_data as $trend): ?>
                        trendData.push([
                            '<?php echo $trend['date']; ?>',
                            <?php echo $trend['count']; ?>,
                            <?php echo $trend['open_count']; ?>,
                            <?php echo $trend['resolved_count']; ?>
                        ]);
                    <?php endforeach; ?>
                    
                    const trendWs = XLSX.utils.aoa_to_sheet(trendData);
                    XLSX.utils.book_append_sheet(wb, trendWs, 'Trend Data');
                    <?php endif; ?>
                    
                    // Generate Excel file and download
                    const startDate = '<?php echo date("M_j_Y", strtotime($start_date)); ?>';
                    const endDate = '<?php echo date("M_j_Y", strtotime($end_date)); ?>';
                    XLSX.writeFile(wb, `Dashen_Issue_Analytics_${startDate}_to_${endDate}.xlsx`);
                    
                    hideLoading();
                } catch (error) {
                    console.error('Error generating Excel:', error);
                    hideLoading();
                    alert('Error generating Excel file. Please try again.');
                }
            }, 500);
        }
        
        // Helper function to get summary data for PDF
        function getSummaryData() {
            const data = [];
            
            <?php 
            foreach ($consolidated_data as $item): 
                $percentage = $total_issues > 0 ? round(($item['count'] / $total_issues) * 100, 1) : 0;
            ?>
                data.push([
                    '<?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $item['group_field']))); ?>',
                    <?php echo $item['count']; ?>,
                    '<?php echo $percentage; ?>%'
                ]);
            <?php endforeach; ?>
            
            return data;
        }
        
        // Helper function to get table data for PDF
        function getTableData() {
            const table = document.getElementById('issuesTable');
            const rows = table.querySelectorAll('tbody tr');
            const data = [];
            
            rows.forEach(row => {
                const cells = row.querySelectorAll('td');
                // Skip empty state row
                if (cells.length > 1 && cells[0].querySelector('.issue-name')) {
                    const rowData = [
                        cells[0].textContent.trim(),
                        cells[2].textContent.trim(),
                        cells[3].textContent.trim(),
                        cells[4].textContent.trim(),
                        cells[5].textContent.trim(),
                        cells[6].textContent.trim(),
                        cells[7].textContent.trim()
                    ];
                    data.push(rowData);
                }
            });
            
            return data;
        }
        
        // Helper function to get table data for Excel
        function getTableDataForExcel() {
            const table = document.getElementById('issuesTable');
            const rows = table.querySelectorAll('tbody tr');
            const data = [];
            
            rows.forEach(row => {
                const cells = row.querySelectorAll('td');
                // Skip empty state row
                if (cells.length > 1 && cells[0].querySelector('.issue-name')) {
                    data.push({
                        'Issue Name': cells[0].textContent.trim(),
                        'Description': cells[1].textContent.trim(),
                        'Status': cells[2].textContent.trim(),
                        'Priority': cells[3].textContent.trim(),
                        'Type': cells[4].textContent.trim(),
                        'Project': cells[5].textContent.trim(),
                        'Assignee': cells[6].textContent.trim(),
                        'Created Date': cells[7].textContent.trim()
                    });
                }
            });
            
            return data;
        }
    </script>
</body>
</html>