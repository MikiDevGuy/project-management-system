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
    } elseif ($role === 'pm_manager') {
        return $user_role === 'pm_manager' || $user_role === 'super_admin';
    } elseif ($role === 'pm_employee') {
        return $user_role === 'pm_employee' || $user_role === 'pm_manager' || $user_role === 'super_admin';
    }
    return false;
}

// Function to check if user is assigned to project
function isUserAssignedToProject($conn, $user_id, $project_id) {
    $stmt = $conn->prepare("SELECT id FROM user_assignments WHERE user_id = ? AND project_id = ? AND is_active = 1");
    $stmt->bind_param("ii", $user_id, $project_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $assigned = $result->num_rows > 0;
    $stmt->close();
    return $assigned;
}

// Function to get user's assigned projects
function getUserProjects($conn, $user_id) {
    if (hasRole('super_admin')) {
        $sql = "SELECT id, name, status FROM projects ORDER BY name";
        $stmt = $conn->prepare($sql);
    } else {
        $sql = "SELECT p.id, p.name, p.status 
                FROM projects p
                INNER JOIN user_assignments ua ON p.id = ua.project_id
                WHERE ua.user_id = ? AND ua.is_active = 1
                ORDER BY p.name";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $projects = [];
    while ($row = $result->fetch_assoc()) {
        $projects[] = $row;
    }
    $stmt->close();
    return $projects;
}

// Helper function for time ago
function timeAgo($timestamp) {
    $time_ago = strtotime($timestamp);
    $current_time = time();
    $time_difference = $current_time - $time_ago;
    $seconds = $time_difference;
    
    $minutes = round($seconds / 60);
    $hours = round($seconds / 3600);
    $days = round($seconds / 86400);
    $weeks = round($seconds / 604800);
    $months = round($seconds / 2629440);
    $years = round($seconds / 31553280);
    
    if ($seconds <= 60) {
        return "Just Now";
    } else if ($minutes <= 60) {
        return ($minutes == 1) ? "1 minute ago" : "$minutes minutes ago";
    } else if ($hours <= 24) {
        return ($hours == 1) ? "1 hour ago" : "$hours hours ago";
    } else if ($days <= 7) {
        return ($days == 1) ? "yesterday" : "$days days ago";
    } else if ($weeks <= 4.3) {
        return ($weeks == 1) ? "1 week ago" : "$weeks weeks ago";
    } else if ($months <= 12) {
        return ($months == 1) ? "1 month ago" : "$months months ago";
    } else {
        return ($years == 1) ? "1 year ago" : "$years years ago";
    }
}

// Get user's assigned projects
$user_projects = getUserProjects($conn, $user_id);
$project_ids = array_column($user_projects, 'id');

// Build project condition for queries - FIXED: removed i. prefix
$project_condition = "";
if (!hasRole('super_admin')) {
    if (!empty($project_ids)) {
        $project_ids_str = implode(',', array_map('intval', $project_ids));
        $project_condition = " AND project_id IN ($project_ids_str)";
    } else {
        $project_condition = " AND 1=0"; // No projects assigned
    }
}

// Get statistics based on user role and assignments
if (hasRole('super_admin')) {
    // Super admin sees all issues
    $sql_stats = "
        SELECT 
            COUNT(*) as total_issues,
            SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open_issues,
            SUM(CASE WHEN status = 'assigned' THEN 1 ELSE 0 END) as assigned_issues,
            SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_issues,
            SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved_issues,
            SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed_issues,
            SUM(CASE WHEN approval_status = 'pending_approval' THEN 1 ELSE 0 END) as pending_approval,
            SUM(CASE WHEN approval_status = 'approved' THEN 1 ELSE 0 END) as approved_issues,
            SUM(CASE WHEN approval_status = 'rejected' THEN 1 ELSE 0 END) as rejected_issues
        FROM issues
    ";
    $stmt = $conn->prepare($sql_stats);
} elseif (hasRole('pm_manager')) {
    // PM Managers see issues from all projects they have access to
    $sql_stats = "
        SELECT 
            COUNT(*) as total_issues,
            SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open_issues,
            SUM(CASE WHEN status = 'assigned' THEN 1 ELSE 0 END) as assigned_issues,
            SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_issues,
            SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved_issues,
            SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed_issues,
            SUM(CASE WHEN approval_status = 'pending_approval' THEN 1 ELSE 0 END) as pending_approval,
            SUM(CASE WHEN approval_status = 'approved' THEN 1 ELSE 0 END) as approved_issues,
            SUM(CASE WHEN approval_status = 'rejected' THEN 1 ELSE 0 END) as rejected_issues
        FROM issues
        WHERE 1=1 $project_condition
    ";
    $stmt = $conn->prepare($sql_stats);
} else {
    // PM Employees see issues they created or are assigned to
    $sql_stats = "
        SELECT 
            COUNT(*) as total_issues,
            SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open_issues,
            SUM(CASE WHEN status = 'assigned' THEN 1 ELSE 0 END) as assigned_issues,
            SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_issues,
            SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved_issues,
            SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed_issues,
            SUM(CASE WHEN approval_status = 'pending_approval' THEN 1 ELSE 0 END) as pending_approval,
            SUM(CASE WHEN approval_status = 'approved' THEN 1 ELSE 0 END) as approved_issues,
            SUM(CASE WHEN approval_status = 'rejected' THEN 1 ELSE 0 END) as rejected_issues
        FROM issues
        WHERE (created_by = ? OR assigned_to = ?) $project_condition
    ";
    $stmt = $conn->prepare($sql_stats);
    $stmt->bind_param("ii", $user_id, $user_id);
}

$stmt->execute();
$result = $stmt->get_result();
$stats = $result->fetch_assoc();
$stmt->close();

// Get data for charts based on user permissions
$chart_data = [];

// Issues by Status
if (hasRole('super_admin')) {
    $sql_status_chart = "SELECT status, COUNT(*) as count FROM issues GROUP BY status";
    $stmt = $conn->prepare($sql_status_chart);
} elseif (hasRole('pm_manager')) {
    $sql_status_chart = "SELECT status, COUNT(*) as count FROM issues WHERE 1=1 $project_condition GROUP BY status";
    $stmt = $conn->prepare($sql_status_chart);
} else {
    $sql_status_chart = "
        SELECT status, COUNT(*) as count 
        FROM issues 
        WHERE (created_by = ? OR assigned_to = ?) $project_condition
        GROUP BY status
    ";
    $stmt = $conn->prepare($sql_status_chart);
    $stmt->bind_param("ii", $user_id, $user_id);
}
$stmt->execute();
$result = $stmt->get_result();
$issues_by_status = [];
while ($row = $result->fetch_assoc()) {
    $issues_by_status[$row['status']] = $row['count'];
}
$stmt->close();
$chart_data['issues_by_status'] = $issues_by_status;

// Issues by Priority
if (hasRole('super_admin')) {
    $sql_priority_chart = "SELECT priority, COUNT(*) as count FROM issues GROUP BY priority";
    $stmt = $conn->prepare($sql_priority_chart);
} elseif (hasRole('pm_manager')) {
    $sql_priority_chart = "SELECT priority, COUNT(*) as count FROM issues WHERE 1=1 $project_condition GROUP BY priority";
    $stmt = $conn->prepare($sql_priority_chart);
} else {
    $sql_priority_chart = "
        SELECT priority, COUNT(*) as count 
        FROM issues 
        WHERE (created_by = ? OR assigned_to = ?) $project_condition
        GROUP BY priority
    ";
    $stmt = $conn->prepare($sql_priority_chart);
    $stmt->bind_param("ii", $user_id, $user_id);
}
$stmt->execute();
$result = $stmt->get_result();
$issues_by_priority = [];
while ($row = $result->fetch_assoc()) {
    $issues_by_priority[$row['priority']] = $row['count'];
}
$stmt->close();
$chart_data['issues_by_priority'] = $issues_by_priority;

// Issues by Type
if (hasRole('super_admin')) {
    $sql_type_chart = "SELECT type, COUNT(*) as count FROM issues GROUP BY type";
    $stmt = $conn->prepare($sql_type_chart);
} elseif (hasRole('pm_manager')) {
    $sql_type_chart = "SELECT type, COUNT(*) as count FROM issues WHERE 1=1 $project_condition GROUP BY type";
    $stmt = $conn->prepare($sql_type_chart);
} else {
    $sql_type_chart = "
        SELECT type, COUNT(*) as count 
        FROM issues 
        WHERE (created_by = ? OR assigned_to = ?) $project_condition
        GROUP BY type
    ";
    $stmt = $conn->prepare($sql_type_chart);
    $stmt->bind_param("ii", $user_id, $user_id);
}
$stmt->execute();
$result = $stmt->get_result();
$issues_by_type = [];
while ($row = $result->fetch_assoc()) {
    $issues_by_type[$row['type']] = $row['count'];
}
$stmt->close();
$chart_data['issues_by_type'] = $issues_by_type;

// Issues by Approval Status
if (hasRole('super_admin')) {
    $sql_approval_chart = "SELECT approval_status, COUNT(*) as count FROM issues GROUP BY approval_status";
    $stmt = $conn->prepare($sql_approval_chart);
} elseif (hasRole('pm_manager')) {
    $sql_approval_chart = "SELECT approval_status, COUNT(*) as count FROM issues WHERE 1=1 $project_condition GROUP BY approval_status";
    $stmt = $conn->prepare($sql_approval_chart);
} else {
    $sql_approval_chart = "
        SELECT approval_status, COUNT(*) as count 
        FROM issues 
        WHERE (created_by = ? OR assigned_to = ?) $project_condition
        GROUP BY approval_status
    ";
    $stmt = $conn->prepare($sql_approval_chart);
    $stmt->bind_param("ii", $user_id, $user_id);
}
$stmt->execute();
$result = $stmt->get_result();
$issues_by_approval = [];
while ($row = $result->fetch_assoc()) {
    $issues_by_approval[$row['approval_status']] = $row['count'];
}
$stmt->close();
$chart_data['issues_by_approval'] = $issues_by_approval;

// Get recent issues based on permissions
if (hasRole('super_admin')) {
    $sql_recent_issues = "
        SELECT i.*, p.name as project_name, p.status as project_status,
               u.username as assigned_username, 
               creator.username as creator_username
        FROM issues i
        LEFT JOIN projects p ON i.project_id = p.id
        LEFT JOIN users u ON i.assigned_to = u.id
        LEFT JOIN users creator ON i.created_by = creator.id
        ORDER BY i.created_at DESC
        LIMIT 10
    ";
    $stmt = $conn->prepare($sql_recent_issues);
} elseif (hasRole('pm_manager')) {
    $sql_recent_issues = "
        SELECT i.*, p.name as project_name, p.status as project_status,
               u.username as assigned_username,
               creator.username as creator_username
        FROM issues i
        LEFT JOIN projects p ON i.project_id = p.id
        LEFT JOIN users u ON i.assigned_to = u.id
        LEFT JOIN users creator ON i.created_by = creator.id
        WHERE 1=1 $project_condition
        ORDER BY i.created_at DESC
        LIMIT 10
    ";
    $stmt = $conn->prepare($sql_recent_issues);
} else {
    $sql_recent_issues = "
        SELECT i.*, p.name as project_name, p.status as project_status,
               u.username as assigned_username,
               creator.username as creator_username
        FROM issues i
        LEFT JOIN projects p ON i.project_id = p.id
        LEFT JOIN users u ON i.assigned_to = u.id
        LEFT JOIN users creator ON i.created_by = creator.id
        WHERE (i.created_by = ? OR i.assigned_to = ?) $project_condition
        ORDER BY i.created_at DESC
        LIMIT 10
    ";
    $stmt = $conn->prepare($sql_recent_issues);
    $stmt->bind_param("ii", $user_id, $user_id);
}
$stmt->execute();
$result = $stmt->get_result();
$recent_issues = [];
while ($row = $result->fetch_assoc()) {
    $recent_issues[] = $row;
}
$stmt->close();

// Get pending approvals for PM Managers and Super Admin
$pending_approvals = [];
if (hasRole('pm_manager') || hasRole('super_admin')) {
    $sql_pending = "
        SELECT i.*, p.name as project_name, creator.username as creator_username
        FROM issues i
        LEFT JOIN projects p ON i.project_id = p.id
        LEFT JOIN users creator ON i.created_by = creator.id
        WHERE i.approval_status = 'pending_approval'
    ";
    
    if (!hasRole('super_admin')) {
        // For PM Managers, only show issues from their projects
        if (!empty($project_ids)) {
            $project_ids_str = implode(',', array_map('intval', $project_ids));
            $sql_pending .= " AND i.project_id IN ($project_ids_str)";
        } else {
            $sql_pending .= " AND 1=0"; // No projects assigned
        }
        
        // PM Managers cannot see their own pending issues
        $sql_pending .= " AND i.created_by != ?";
        $stmt = $conn->prepare($sql_pending);
        $stmt->bind_param("i", $user_id);
    } else {
        // Super admin sees all
        $stmt = $conn->prepare($sql_pending);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $pending_approvals[] = $row;
    }
    $stmt->close();
}

// Get projects based on permissions
$recent_projects = $user_projects; // Already have user's assigned projects

// Get unread notifications
$unread_count = 0;
$unread_notifications = [];
$stmt = $conn->prepare("
    SELECT n.*, u.username as related_username
    FROM notifications n
    LEFT JOIN users u ON n.related_user_id = u.id
    WHERE n.user_id = ? AND n.is_read = 0
    ORDER BY n.created_at DESC
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$unread_count = $result->num_rows;
while ($row = $result->fetch_assoc()) {
    $row['time_ago'] = timeAgo($row['created_at']);
    if (!empty($row['metadata'])) {
        $row['metadata'] = json_decode($row['metadata'], true);
    }
    $unread_notifications[] = $row;
}
$stmt->close();

include 'sidebar.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Dashen Bank Issue Tracker</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        :root {
            --dashen-primary: #273274;
            --dashen-secondary: #1e2559;
            --dashen-accent: #f58220;
            --dashen-light: #f5f7fb;
            --success-color: #2dce89;
            --info-color: #11cdef;
            --warning-color: #fb6340;
            --danger-color: #f5365c;
            --dark-color: #32325d;
            --light-color: #f8f9fa;
            --card-bg: #ffffff;
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
            color: var(--dark-color);
            min-height: 100vh;
            overflow-x: hidden;
        }
        
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
            color: var(--dark-color);
        }
        
        .user-name {
            font-weight: 600;
            color: var(--dashen-primary);
        }
        
        .notification-bell {
            position: relative;
            cursor: pointer;
            padding: 10px;
            border-radius: 50%;
            background: var(--light-color);
            transition: var(--transition);
        }
        
        .notification-bell:hover {
            background: #e9ecef;
            transform: scale(1.1);
        }
        
        .notification-badge {
            position: absolute;
            top: 0;
            right: 0;
            background: var(--danger-color);
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            font-size: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
        }
        
        .notification-dropdown {
            width: 380px;
            max-height: 500px;
            overflow-y: auto;
            padding: 0;
            border: none;
            box-shadow: var(--shadow-xl);
        }
        
        .notification-header {
            padding: 15px 20px;
            background: var(--dashen-primary);
            color: white;
            font-weight: 600;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .notification-item {
            padding: 15px 20px;
            border-bottom: 1px solid #e9ecef;
            transition: var(--transition);
        }
        
        .notification-item:hover {
            background: #f8f9fa;
        }
        
        .notification-item.unread {
            background: #e8f0fe;
        }
        
        .notification-time {
            font-size: 0.8rem;
            color: #6c757d;
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
            background: var(--light-color);
            color: var(--dark-color);
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
        
        .chart-container {
            position: relative;
            height: 320px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .chart-card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-lg);
            border: 1px solid rgba(255, 255, 255, 0.2);
            transition: var(--transition);
            height: 100%;
        }
        
        .chart-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-xl);
        }
        
        .chart-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding: 25px 25px 0;
        }
        
        .table-container {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-lg);
            overflow: hidden;
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
        
        .badge-status {
            font-size: 0.75rem;
            padding: 0.5em 1em;
            border-radius: 20px;
            font-weight: 600;
            letter-spacing: 0.3px;
        }
        
        .status-indicator {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 5px;
        }
        
        .status-pending_approval { background-color: var(--warning-color); }
        .status-approved { background-color: var(--success-color); }
        .status-rejected { background-color: var(--danger-color); }
        .status-open { background-color: var(--warning-color); }
        .status-assigned { background-color: var(--info-color); }
        .status-in_progress { background-color: var(--info-color); }
        .status-resolved { background-color: var(--success-color); }
        .status-closed { background-color: #6c757d; }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--dashen-primary), var(--dashen-secondary));
            border: none;
            border-radius: var(--border-radius-sm);
            padding: 12px 25px;
            font-weight: 600;
            transition: var(--transition);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
            background: linear-gradient(135deg, var(--dashen-secondary), var(--dashen-primary));
        }
        
        .btn-outline-primary {
            color: var(--dashen-primary);
            border-color: var(--dashen-primary);
            border-radius: var(--border-radius-sm);
            font-weight: 500;
            transition: var(--transition);
        }
        
        .btn-outline-primary:hover {
            background: var(--dashen-primary);
            border-color: var(--dashen-primary);
            transform: translateY(-2px);
        }
        
        .quick-action-btn {
            padding: 25px 15px;
            border-radius: var(--border-radius);
            text-align: center;
            background: white;
            border: 1px solid rgba(255, 255, 255, 0.2);
            transition: var(--transition);
            text-decoration: none;
            color: var(--dark-color);
            display: block;
            margin-bottom: 20px;
            box-shadow: var(--shadow-md);
            position: relative;
            overflow: hidden;
        }
        
        .quick-action-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.4), transparent);
            transition: var(--transition);
        }
        
        .quick-action-btn:hover {
            transform: translateY(-5px) scale(1.03);
            box-shadow: var(--shadow-xl);
            color: var(--dashen-primary);
            text-decoration: none;
        }
        
        .quick-action-btn:hover::before {
            left: 100%;
        }
        
        .quick-action-btn i {
            font-size: 2.5rem;
            margin-bottom: 15px;
            display: block;
            background: linear-gradient(135deg, var(--dashen-primary), var(--dashen-accent));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .quick-action-btn span {
            font-weight: 600;
            font-size: 0.9rem;
        }
        
        .list-group-item {
            border: none;
            padding: 20px;
            border-bottom: 1px solid #f8f9fa;
            transition: var(--transition);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .list-group-item:hover {
            background-color: rgba(39, 50, 116, 0.04);
            transform: translateX(5px);
        }
        
        .list-group-item:last-child {
            border-bottom: none;
        }
        
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
            
            .user-info {
                flex-wrap: wrap;
                justify-content: center;
            }
            
            .notification-dropdown {
                width: 300px;
            }
        }
        
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
        }
        
        .toast {
            background: white;
            border-radius: var(--border-radius-sm);
            box-shadow: var(--shadow-lg);
            margin-bottom: 10px;
            min-width: 300px;
            border-left: 4px solid;
        }
        
        .toast.success {
            border-left-color: var(--success-color);
        }
        
        .toast.error {
            border-left-color: var(--danger-color);
        }
        
        .toast.warning {
            border-left-color: var(--warning-color);
        }
        
        .toast.info {
            border-left-color: var(--info-color);
        }
        
        .toast .toast-header {
            background: transparent;
            border-bottom: none;
            padding: 12px 15px;
        }
        
        .toast .toast-body {
            padding: 0 15px 12px;
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    
    <div class="main-content" id="mainContent">
        <header class="dashboard-header fade-in">
            <div class="header-brand">
                <img src="../Images/DashenLogo1.png" alt="Dashen Bank" class="header-logo">
                <div class="brand-text">Issue Tracker Pro</div>
            </div>
            <div class="user-info">
                <div class="notification-bell" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-bell fs-5"></i>
                    <?php if ($unread_count > 0): ?>
                        <span class="notification-badge"><?php echo $unread_count; ?></span>
                    <?php endif; ?>
                </div>
                <div class="dropdown-menu notification-dropdown dropdown-menu-end">
                    <div class="notification-header">
                        <span>Notifications</span>
                        <?php if ($unread_count > 0): ?>
                            <button class="btn btn-sm btn-link text-white p-0 mark-all-read">
                                Mark all read
                            </button>
                        <?php endif; ?>
                    </div>
                    <div class="notification-list">
                        <?php if (empty($unread_notifications)): ?>
                            <div class="text-center py-4">
                                <i class="bi bi-bell-slash fs-1 text-muted"></i>
                                <p class="text-muted mt-2">No new notifications</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($unread_notifications as $notification): ?>
                                <div class="notification-item unread" data-id="<?php echo $notification['id']; ?>">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <strong><?php echo htmlspecialchars($notification['title']); ?></strong>
                                        <small class="notification-time"><?php echo $notification['time_ago']; ?></small>
                                    </div>
                                    <p class="mb-1 small"><?php echo htmlspecialchars($notification['message']); ?></p>
                                    <?php if (isset($notification['metadata']) && !empty($notification['metadata'])): ?>
                                        <?php if (isset($notification['metadata']['old_status'])): ?>
                                            <div class="small text-muted">
                                                Status: <?php echo ucfirst(str_replace('_', ' ', $notification['metadata']['old_status'])); ?> → 
                                                <?php echo ucfirst(str_replace('_', ' ', $notification['metadata']['new_status'])); ?>
                                                <br>By: <?php echo htmlspecialchars($notification['metadata']['changed_by']); ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php if ($notification['related_username']): ?>
                                        <small class="text-muted">By: <?php echo htmlspecialchars($notification['related_username']); ?></small>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <p class="user-greeting">Welcome back, <span class="user-name"><?php echo $_SESSION['username']; ?></span></p>
                <a href="../profile.php" class="header-btn profile-btn">
                    <i class="bi bi-person-circle"></i> My Profile
                </a>
                <a href="../logout.php" class="header-btn logout-btn">
                    <i class="bi bi-box-arrow-right"></i> Logout
                </a>
            </div>
        </header>
        
        <div class="dashboard-card slide-up">
            <span class="user-role-badge">
                <i class="bi bi-shield-check me-2"></i><?php echo ucfirst(str_replace('_', ' ', $user_role)); ?> Access Level
            </span>
            
            <h1 class="welcome-title">Issue Tracker Dashboard</h1>
            <p class="welcome-subtitle">Welcome back, <?php echo $_SESSION['username']; ?>! Here's a comprehensive overview of your issues, projects, and performance metrics.</p>
            
            <!-- Statistics Cards -->
            <div class="row mb-5">
                <div class="col-md-3">
                    <div class="stat-card" style="background: linear-gradient(135deg, var(--dashen-primary), #3a4a9a);">
                        <div class="d-flex align-items-center">
                            <div class="flex-grow-1">
                                <p class="card-title">Total Issues</p>
                                <h3 class="card-value"><?php echo $stats['total_issues'] ?? 0; ?></h3>
                            </div>
                            <div>
                                <i class="bi bi-clipboard-check"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card" style="background: linear-gradient(135deg, var(--warning-color), #fd7e5a);">
                        <div class="d-flex align-items-center">
                            <div class="flex-grow-1">
                                <p class="card-title">Pending Approval</p>
                                <h3 class="card-value"><?php echo $stats['pending_approval'] ?? 0; ?></h3>
                            </div>
                            <div>
                                <i class="bi bi-hourglass-split"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card" style="background: linear-gradient(135deg, var(--info-color), #2bd1f2);">
                        <div class="d-flex align-items-center">
                            <div class="flex-grow-1">
                                <p class="card-title">In Progress</p>
                                <h3 class="card-value"><?php echo ($stats['in_progress_issues'] ?? 0) + ($stats['assigned_issues'] ?? 0); ?></h3>
                            </div>
                            <div>
                                <i class="bi bi-gear"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card" style="background: linear-gradient(135deg, var(--success-color), #3ddc97);">
                        <div class="d-flex align-items-center">
                            <div class="flex-grow-1">
                                <p class="card-title">Resolved</p>
                                <h3 class="card-value"><?php echo $stats['resolved_issues'] ?? 0; ?></h3>
                            </div>
                            <div>
                                <i class="bi bi-check-circle"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Analytics Section -->
            <div class="row">
                <div class="col-12 mb-5">
                    <div class="dashboard-card">
                        <div class="chart-card-header">
                            <h4 class="mb-0" style="color: var(--dashen-primary);">
                                <i class="bi bi-graph-up me-2"></i>Issues Analytics
                            </h4>
                        </div>
                        <div class="row">
                            <div class="col-md-4 mb-4">
                                <div class="chart-card h-100">
                                    <div class="card-body">
                                        <h5 class="card-title text-center" style="color: var(--dashen-primary);">
                                            <i class="bi bi-pie-chart me-2"></i>By Status
                                        </h5>
                                        <div class="chart-container">
                                            <canvas id="statusChart"></canvas>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4 mb-4">
                                <div class="chart-card h-100">
                                    <div class="card-body">
                                        <h5 class="card-title text-center" style="color: var(--dashen-primary);">
                                            <i class="bi bi-bar-chart me-2"></i>By Priority
                                        </h5>
                                        <div class="chart-container">
                                            <canvas id="priorityChart"></canvas>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4 mb-4">
                                <div class="chart-card h-100">
                                    <div class="card-body">
                                        <h5 class="card-title text-center" style="color: var(--dashen-primary);">
                                            <i class="bi bi-tag me-2"></i>By Type
                                        </h5>
                                        <div class="chart-container">
                                            <canvas id="typeChart"></canvas>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-12 mb-4">
                                <div class="chart-card h-100">
                                    <div class="card-body">
                                        <h5 class="card-title text-center" style="color: var(--dashen-primary);">
                                            <i class="bi bi-check-circle me-2"></i>By Approval Status
                                        </h5>
                                        <div class="chart-container">
                                            <canvas id="approvalChart"></canvas>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Pending Approvals for PM Managers and Super Admin -->
            <?php if ((hasRole('pm_manager') || hasRole('super_admin')) && !empty($pending_approvals)): ?>
            <div class="row mb-4">
                <div class="col-12">
                    <div class="dashboard-card">
                        <h4 class="mb-4" style="color: var(--dashen-primary);">
                            <i class="bi bi-hourglass-split me-2"></i>Pending Approvals (<?php echo count($pending_approvals); ?>)
                        </h4>
                        <div class="table-container">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Title</th>
                                            <th>Project</th>
                                            <th>Created By</th>
                                            <th>Priority</th>
                                            <th>Type</th>
                                            <th>Created</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($pending_approvals as $issue): ?>
                                        <tr>
                                            <td><strong>#<?php echo $issue['id']; ?></strong></td>
                                            <td>
                                                <a href="issue_detail.php?id=<?php echo $issue['id']; ?>" class="text-decoration-none fw-bold">
                                                    <?php echo htmlspecialchars($issue['title']); ?>
                                                </a>
                                            </td>
                                            <td><?php echo htmlspecialchars($issue['project_name']); ?></td>
                                            <td><?php echo htmlspecialchars($issue['creator_username']); ?></td>
                                            <td>
                                                <span class="badge badge-status 
                                                    <?php 
                                                    switch($issue['priority']) {
                                                        case 'low': echo 'bg-secondary'; break;
                                                        case 'medium': echo 'bg-primary'; break;
                                                        case 'high': echo 'bg-warning text-dark'; break;
                                                        case 'critical': echo 'bg-danger'; break;
                                                        default: echo 'bg-primary';
                                                    }
                                                    ?>
                                                ">
                                                    <?php echo ucfirst($issue['priority']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge badge-status bg-light text-dark border">
                                                    <?php echo ucfirst($issue['type']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo date('M j, Y', strtotime($issue['created_at'])); ?></td>
                                            <td>
                                                <div class="btn-group">
                                                    <a href="issue_detail.php?id=<?php echo $issue['id']; ?>" class="btn btn-sm btn-outline-primary">
                                                        <i class="bi bi-eye"></i>
                                                    </a>
                                                    <button class="btn btn-sm btn-outline-success approve-btn" data-issue-id="<?php echo $issue['id']; ?>">
                                                        <i class="bi bi-check-circle"></i>
                                                    </button>
                                                    <button class="btn btn-sm btn-outline-danger reject-btn" data-issue-id="<?php echo $issue['id']; ?>">
                                                        <i class="bi bi-x-circle"></i>
                                                    </button>
                                                </div>
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
            <?php endif; ?>
            
            <!-- Recent Activity Section -->
            <div class="row">
                <div class="col-md-8">
                    <div class="dashboard-card">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h4 class="mb-0" style="color: var(--dashen-primary);">
                                <i class="bi bi-clock-history me-2"></i>Recent Issues
                            </h4>
                            <a href="issues.php" class="btn btn-primary">
                                <i class="bi bi-arrow-right me-2"></i> View All
                            </a>
                        </div>
                        <div class="table-container">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Title</th>
                                            <th>Project</th>
                                            <th>Status</th>
                                            <th>Priority</th>
                                            <th>Approval</th>
                                            <th>Created</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($recent_issues)): ?>
                                        <tr>
                                            <td colspan="7" class="text-center py-4">
                                                <p class="text-muted mb-0">No issues found</p>
                                            </td>
                                        </tr>
                                        <?php else: ?>
                                            <?php foreach ($recent_issues as $issue): ?>
                                            <tr>
                                                <td><strong>#<?php echo $issue['id']; ?></strong></td>
                                                <td>
                                                    <a href="issue_detail.php?id=<?php echo $issue['id']; ?>" class="text-decoration-none fw-bold">
                                                        <?php echo htmlspecialchars($issue['title']); ?>
                                                    </a>
                                                </td>
                                                <td><?php echo htmlspecialchars($issue['project_name']); ?></td>
                                                <td>
                                                    <span class="badge badge-status 
                                                        <?php 
                                                        switch($issue['status']) {
                                                            case 'open': echo 'bg-warning text-dark'; break;
                                                            case 'assigned': echo 'bg-info'; break;
                                                            case 'in_progress': echo 'bg-primary'; break;
                                                            case 'resolved': echo 'bg-success'; break;
                                                            case 'closed': echo 'bg-secondary'; break;
                                                            default: echo 'bg-secondary';
                                                        }
                                                        ?>
                                                    ">
                                                        <span class="status-indicator status-<?php echo $issue['status']; ?>"></span>
                                                        <?php echo ucfirst(str_replace('_', ' ', $issue['status'])); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge badge-status 
                                                        <?php 
                                                        switch($issue['priority']) {
                                                            case 'low': echo 'bg-secondary'; break;
                                                            case 'medium': echo 'bg-primary'; break;
                                                            case 'high': echo 'bg-warning text-dark'; break;
                                                            case 'critical': echo 'bg-danger'; break;
                                                            default: echo 'bg-primary';
                                                        }
                                                        ?>
                                                    ">
                                                        <?php echo ucfirst($issue['priority']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge badge-status 
                                                        <?php 
                                                        switch($issue['approval_status']) {
                                                            case 'pending_approval': echo 'bg-warning text-dark'; break;
                                                            case 'approved': echo 'bg-success'; break;
                                                            case 'rejected': echo 'bg-danger'; break;
                                                            default: echo 'bg-secondary';
                                                        }
                                                        ?>
                                                    ">
                                                        <span class="status-indicator status-<?php echo $issue['approval_status']; ?>"></span>
                                                        <?php 
                                                        if ($issue['approval_status'] == 'pending_approval') echo 'Pending';
                                                        elseif ($issue['approval_status'] == 'approved') echo 'Approved';
                                                        elseif ($issue['approval_status'] == 'rejected') echo 'Rejected';
                                                        else echo ucfirst($issue['approval_status']);
                                                        ?>
                                                    </span>
                                                </td>
                                                <td><?php echo date('M j, Y', strtotime($issue['created_at'])); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Sidebar Content -->
                <div class="col-md-4">
                    <!-- Recent Projects -->
                    <div class="dashboard-card mb-4">
                        <h4 class="mb-4" style="color: var(--dashen-primary);">
                            <i class="bi bi-folder me-2"></i>Your Projects
                        </h4>
                        <div class="list-group list-group-flush">
                            <?php if (empty($recent_projects)): ?>
                                <p class="text-muted text-center py-3">No projects assigned</p>
                            <?php else: ?>
                                <?php foreach (array_slice($recent_projects, 0, 5) as $project): ?>
                                <a href="issues.php?project_id=<?php echo $project['id']; ?>" class="list-group-item list-group-item-action">
                                    <div class="d-flex w-100 justify-content-between align-items-center">
                                        <span class="fw-bold"><?php echo htmlspecialchars($project['name']); ?></span>
                                        <span class="badge <?php echo $project['status'] === 'terminated' ? 'bg-danger' : 'bg-primary'; ?> rounded-pill">
                                            <?php echo ucfirst($project['status']); ?>
                                        </span>
                                    </div>
                                </a>
                                <?php endforeach; ?>
                                <?php if (count($recent_projects) > 5): ?>
                                <a href="projects.php" class="list-group-item list-group-item-action text-center text-primary">
                                    View all projects <i class="bi bi-arrow-right ms-1"></i>
                                </a>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div class="dashboard-card">
                        <h4 class="mb-4" style="color: var(--dashen-primary);">
                            <i class="bi bi-lightning me-2"></i>Quick Actions
                        </h4>
                        <div class="row">
                            <div class="col-6 mb-3">
                                <a href="create_issue.php" class="quick-action-btn">
                                    <i class="bi bi-plus-circle"></i>
                                    <span>Create Issue</span>
                                </a>
                            </div>
                            <div class="col-6 mb-3">
                                <a href="issues.php" class="quick-action-btn">
                                    <i class="bi bi-list-task"></i>
                                    <span>View Issues</span>
                                </a>
                            </div>
                            <div class="col-6 mb-3">
                                <a href="projects.php" class="quick-action-btn">
                                    <i class="bi bi-diagram-3"></i>
                                    <span>Projects</span>
                                </a>
                            </div>
                            <div class="col-6 mb-3">
                                <a href="../profile.php" class="quick-action-btn">
                                    <i class="bi bi-person"></i>
                                    <span>Profile</span>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Approve Issue Modal -->
    <div class="modal fade" id="approveIssueModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">Approve Issue</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to approve this issue?</p>
                    <p class="text-muted small">Once approved, the issue will be ready for assignment.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" id="confirmApproveBtn">Approve Issue</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Reject Issue Modal -->
    <div class="modal fade" id="rejectIssueModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">Reject Issue</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="rejectIssueForm">
                        <div class="mb-3">
                            <label for="rejection_reason" class="form-label">Reason for Rejection <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="rejection_reason" name="rejection_reason" rows="3" required></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="confirmRejectBtn">Reject Issue</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Toast Container -->
    <div class="toast-container" id="toastContainer"></div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script>
        // Data from PHP
        const chartData = <?php echo json_encode($chart_data); ?>;

        // Helper function to create a chart
        function createChart(chartId, type, labels, data, colors) {
            const ctx = document.getElementById(chartId).getContext('2d');
            return new Chart(ctx, {
                type: type,
                data: {
                    labels: labels,
                    datasets: [{
                        data: data,
                        backgroundColor: colors,
                        borderColor: type === 'doughnut' ? '#fff' : colors,
                        borderWidth: type === 'doughnut' ? 3 : 1,
                        borderRadius: type === 'bar' ? 8 : 0,
                        borderSkipped: false,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                font: {
                                    family: 'Poppins',
                                    size: 12
                                },
                                padding: 20
                            }
                        },
                        tooltip: {
                            backgroundColor: 'rgba(39, 50, 116, 0.9)',
                            titleFont: {
                                family: 'Poppins'
                            },
                            bodyFont: {
                                family: 'Poppins'
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

        // Create Issues by Status Chart
        const statusLabels = Object.keys(chartData.issues_by_status).map(label => 
            label.split('_').map(word => word.charAt(0).toUpperCase() + word.slice(1)).join(' ')
        );
        const statusValues = Object.values(chartData.issues_by_status);
        const statusColors = ['#f58220', '#11cdef', '#2dce89', '#adb5bd', '#273274', '#fb6340'];
        if (statusLabels.length > 0) {
            createChart('statusChart', 'doughnut', statusLabels, statusValues, statusColors.slice(0, statusLabels.length));
        }

        // Create Issues by Priority Chart
        const priorityLabels = Object.keys(chartData.issues_by_priority).map(label => 
            label.charAt(0).toUpperCase() + label.slice(1)
        );
        const priorityValues = Object.values(chartData.issues_by_priority);
        const priorityColors = priorityLabels.map(label => {
            switch(label.toLowerCase()) {
                case 'low': return '#adb5bd';
                case 'medium': return '#273274';
                case 'high': return '#f58220';
                case 'critical': return '#e54545';
                default: return '#adb5bd';
            }
        });
        if (priorityLabels.length > 0) {
            createChart('priorityChart', 'bar', priorityLabels, priorityValues, priorityColors);
        }

        // Create Issues by Type Chart
        const typeLabels = Object.keys(chartData.issues_by_type).map(label => 
            label.charAt(0).toUpperCase() + label.slice(1)
        );
        const typeValues = Object.values(chartData.issues_by_type);
        const typeColors = ['#273274', '#f58220', '#11cdef', '#2dce89', '#adb5bd'];
        if (typeLabels.length > 0) {
            createChart('typeChart', 'bar', typeLabels, typeValues, typeColors.slice(0, typeLabels.length));
        }

        // Create Issues by Approval Status Chart
        const approvalLabels = Object.keys(chartData.issues_by_approval).map(label => {
            switch(label) {
                case 'pending_approval': return 'Pending Approval';
                case 'approved': return 'Approved';
                case 'rejected': return 'Rejected';
                default: return label;
            }
        });
        const approvalValues = Object.values(chartData.issues_by_approval);
        const approvalColors = ['#fb6340', '#2dce89', '#f5365c'];
        if (approvalLabels.length > 0) {
            createChart('approvalChart', 'doughnut', approvalLabels, approvalValues, approvalColors.slice(0, approvalLabels.length));
        }

        // Sidebar toggle functionality
        document.addEventListener('DOMContentLoaded', function() {
            const sidebarToggleBtn = document.getElementById('sidebarToggleBtn');
            const mainContent = document.getElementById('mainContent');
            
            if (sidebarToggleBtn && mainContent) {
                sidebarToggleBtn.addEventListener('click', function() {
                    mainContent.classList.toggle('expanded');
                });
            }

            function checkScreenSize() {
                if (window.innerWidth <= 1200 && window.innerWidth > 992) {
                    mainContent.classList.add('expanded');
                } else if (window.innerWidth > 1200) {
                    mainContent.classList.remove('expanded');
                }
            }
            
            window.addEventListener('load', checkScreenSize);
            window.addEventListener('resize', checkScreenSize);
        });

        // Toast notification function
        function showToast(type, message) {
            const toastContainer = document.getElementById('toastContainer');
            const toastId = 'toast-' + Date.now();
            const icon = type === 'success' ? 'bi-check-circle-fill' : 
                        type === 'error' ? 'bi-exclamation-circle-fill' :
                        type === 'warning' ? 'bi-exclamation-triangle-fill' : 'bi-info-circle-fill';
            
            const toastHtml = `
                <div id="${toastId}" class="toast ${type}" role="alert" aria-live="assertive" aria-atomic="true">
                    <div class="toast-header">
                        <i class="bi ${icon} me-2 text-${type}"></i>
                        <strong class="me-auto">${type.charAt(0).toUpperCase() + type.slice(1)}</strong>
                        <small>just now</small>
                        <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>
                    </div>
                    <div class="toast-body">
                        ${message}
                    </div>
                </div>
            `;
            
            toastContainer.insertAdjacentHTML('beforeend', toastHtml);
            const toastElement = document.getElementById(toastId);
            const toast = new bootstrap.Toast(toastElement, { delay: 5000 });
            toast.show();
            
            toastElement.addEventListener('hidden.bs.toast', function() {
                this.remove();
            });
        }

        // Approve Issue
        let approveIssueId = null;
        
        $(document).on('click', '.approve-btn', function() {
            approveIssueId = $(this).data('issue-id');
            $('#approveIssueModal').modal('show');
        });
        
        $('#confirmApproveBtn').click(function() {
            if (!approveIssueId) return;
            
            $.ajax({
                url: 'issues.php',
                method: 'POST',
                data: {
                    action: 'approve_issue',
                    issue_id: approveIssueId
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        $('#approveIssueModal').modal('hide');
                        showToast('success', response.message);
                        setTimeout(() => {
                            location.reload();
                        }, 1500);
                    } else {
                        showToast('error', response.message);
                    }
                }
            });
        });

        // Reject Issue
        let rejectIssueId = null;
        
        $(document).on('click', '.reject-btn', function() {
            rejectIssueId = $(this).data('issue-id');
            $('#rejectIssueModal').modal('show');
        });
        
        $('#confirmRejectBtn').click(function() {
            if (!rejectIssueId) return;
            
            const reason = $('#rejection_reason').val();
            if (!reason) {
                showToast('error', 'Please provide a rejection reason');
                return;
            }
            
            $.ajax({
                url: 'issues.php',
                method: 'POST',
                data: {
                    action: 'reject_issue',
                    issue_id: rejectIssueId,
                    rejection_reason: reason
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        $('#rejectIssueModal').modal('hide');
                        $('#rejection_reason').val('');
                        showToast('success', response.message);
                        setTimeout(() => {
                            location.reload();
                        }, 1500);
                    } else {
                        showToast('error', response.message);
                    }
                }
            });
        });

        // Mark notification as read
        $('.notification-item').click(function() {
            const notificationId = $(this).data('id');
            markNotificationRead(notificationId);
        });

        function markNotificationRead(notificationId) {
            $.ajax({
                url: 'issues.php',
                method: 'POST',
                data: {
                    action: 'mark_notifications_read',
                    notification_id: notificationId
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        $(`.notification-item[data-id="${notificationId}"]`).removeClass('unread');
                        updateNotificationBadge();
                    }
                }
            });
        }

        $('.mark-all-read').click(function() {
            $.ajax({
                url: 'issues.php',
                method: 'POST',
                data: {
                    action: 'mark_notifications_read'
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        $('.notification-item').removeClass('unread');
                        updateNotificationBadge();
                        location.reload();
                    }
                }
            });
        });

        function updateNotificationBadge() {
            const unreadCount = $('.notification-item.unread').length;
            const badge = $('.notification-badge');
            
            if (unreadCount > 0) {
                if (badge.length) {
                    badge.text(unreadCount);
                } else {
                    $('.notification-bell').append(`<span class="notification-badge">${unreadCount}</span>`);
                }
            } else {
                badge.remove();
            }
        }

        // Reset reject form when modal closes
        $('#rejectIssueModal').on('hidden.bs.modal', function() {
            $('#rejection_reason').val('');
            rejectIssueId = null;
        });
    </script>
</body>
</html></style>
</body>
</html>