<?php
session_start();
include 'db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$username = $_SESSION['username'];
$role = $_SESSION['system_role'];
$user_id = $_SESSION['user_id'];
$today = date("F j, Y");

// Define active dashboard for sidebar
$active_dashboard = 'testcase_management';

// Initialize statistics
$stats = [
    'total_projects' => 0,
    'assigned_projects' => 0,
    'total_testcases' => 0,
    'total_users' => 0,
    'passed_cases' => 0,
    'failed_cases' => 0,
    'pending_cases' => 0,
    'deferred_cases' => 0
];

// Initialize arrays for charts
$project_names = [];
$project_totals = [];
$project_ids = [];

// Function to send notifications to all relevant users except the one performing the action
function sendNotificationToRelevantUsers($conn, $action, $description, $test_case_id = null, $project_id = null, $current_user_id = null) {
    // Determine which roles should receive notifications based on the action
    $relevant_roles = [];
    
    switch($action) {
        case 'Vendor Comment Updated':
        case 'Test Case Created':
        case 'Test Case Updated':
        case 'Test Case Status Changed':
            // Notify testers, test_viewers, pm_employees, pm_managers
            $relevant_roles = ['tester', 'test_viewer', 'pm_employee', 'pm_manager'];
            break;
            
        case 'Test Case Assigned':
            // Notify the assigned user and pm_managers
            $relevant_roles = ['tester', 'test_viewer', 'pm_employee', 'pm_manager'];
            break;
            
        case 'Tester Comment Updated':
        case 'Test Case Reviewed':
        case 'Test Case Rejected':
            // Notify vendors and pm_managers
            $relevant_roles = ['vendor', 'pm_manager'];
            break;
            
        default:
            $relevant_roles = ['tester', 'test_viewer', 'pm_employee', 'pm_manager', 'vendor'];
    }
    
    // Get current user role
    $current_user_role = '';
    if ($current_user_id) {
        $stmt = $conn->prepare("SELECT system_role FROM users WHERE id = ?");
        $stmt->bind_param("i", $current_user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $current_user_role = $row['system_role'];
        }
    }
    
    // If current user is super_admin, they should not receive notifications
    // Also, any user should not receive notifications about their own actions
    $excluded_user_id = $current_user_id;
    
    // Get all users with relevant roles
    $role_placeholders = str_repeat('?,', count($relevant_roles) - 1) . '?';
    $sql = "SELECT id FROM users WHERE system_role IN ($role_placeholders)";
    
    if ($stmt = $conn->prepare($sql)) {
        $types = str_repeat('s', count($relevant_roles));
        $stmt->bind_param($types, ...$relevant_roles);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $recipient_ids = [];
        while ($row = $result->fetch_assoc()) {
            $recipient_ids[] = $row['id'];
        }
        
        // Remove current user from recipients if provided
        if ($excluded_user_id !== null) {
            $recipient_ids = array_filter($recipient_ids, function($id) use ($excluded_user_id) {
                return $id != $excluded_user_id;
            });
        }
        
        // If project_id is provided, filter users who have access to the project
        $final_recipient_ids = [];
        if ($project_id) {
            foreach ($recipient_ids as $recipient_id) {
                $access_stmt = $conn->prepare("SELECT id FROM user_assignments WHERE user_id = ? AND project_id = ?");
                $access_stmt->bind_param("ii", $recipient_id, $project_id);
                $access_stmt->execute();
                $access_result = $access_stmt->get_result();
                
                // If user has access to this project, add to final recipients
                if ($access_result->num_rows > 0) {
                    $final_recipient_ids[] = $recipient_id;
                }
            }
        } else {
            $final_recipient_ids = $recipient_ids;
        }
        
        // Send notification to each recipient
        foreach ($final_recipient_ids as $recipient_id) {
            // Insert into activity_logs or tester_remark_logs based on action type
            if (strpos($action, 'Tester') === 0 || $action === 'Test Case Reviewed' || $action === 'Test Case Rejected') {
                $insert_sql = "INSERT INTO tester_remark_logs (user_id, action, description, test_case_id, project_id, created_at) 
                              VALUES (?, ?, ?, ?, ?, NOW())";
                $table = 'tester_remark_logs';
            } else {
                $insert_sql = "INSERT INTO activity_logs (user_id, action, description, test_case_id, project_id, created_at) 
                              VALUES (?, ?, ?, ?, ?, NOW())";
                $table = 'activity_logs';
            }
            
            $insert_stmt = $conn->prepare($insert_sql);
            if ($insert_stmt) {
                $insert_stmt->bind_param("issii", $recipient_id, $action, $description, $test_case_id, $project_id);
                $insert_stmt->execute();
            }
        }
    }
}

// Handle AJAX requests for notifications
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    $response = ['success' => false, 'message' => 'Invalid action'];
    
    try {
        $action = $_POST['action'];
        
        switch ($action) {
            case 'mark_notification_read':
                $notification_id = intval($_POST['notification_id']);
                $notification_type = $_POST['notification_type'] ?? 'activity';
                
                if ($notification_type === 'activity') {
                    $stmt = $conn->prepare("UPDATE activity_logs SET is_read = 1 WHERE id = ?");
                    $stmt->bind_param("i", $notification_id);
                } else {
                    $stmt = $conn->prepare("UPDATE tester_remark_logs SET is_read = 1 WHERE id = ?");
                    $stmt->bind_param("i", $notification_id);
                }
                
                if ($stmt->execute()) {
                    $response = ['success' => true, 'message' => 'Notification marked as read'];
                } else {
                    $response = ['success' => false, 'message' => 'Failed to mark notification as read'];
                }
                break;
                
            case 'mark_all_notifications_read':
                if ($role === 'super_admin' || $role === 'pm_manager') {
                    $stmt1 = $conn->prepare("UPDATE activity_logs SET is_read = 1 WHERE is_read = 0");
                    $stmt2 = $conn->prepare("UPDATE tester_remark_logs SET is_read = 1 WHERE is_read = 0");
                } else {
                    $stmt1 = $conn->prepare("UPDATE activity_logs al 
                                           JOIN test_cases tc ON al.test_case_id = tc.id 
                                           JOIN user_assignments pu ON tc.project_id = pu.project_id 
                                           SET al.is_read = 1 
                                           WHERE al.is_read = 0 AND pu.user_id = ?");
                    $stmt1->bind_param("i", $user_id);
                    
                    $stmt2 = $conn->prepare("UPDATE tester_remark_logs trl 
                                           JOIN test_cases tc ON trl.test_case_id = tc.id 
                                           JOIN user_assignments pu ON tc.project_id = pu.project_id 
                                           SET trl.is_read = 1 
                                           WHERE trl.is_read = 0 AND pu.user_id = ?");
                    $stmt2->bind_param("i", $user_id);
                }
                
                $success1 = $stmt1->execute();
                $success2 = $stmt2->execute();
                
                if ($success1 && $success2) {
                    $response = ['success' => true, 'message' => 'All notifications marked as read'];
                } else {
                    $response = ['success' => false, 'message' => 'Failed to mark notifications as read'];
                }
                break;
                
            case 'get_notifications':
                // Fetch notifications based on role
                $notifications = getNotifications($conn, $user_id, $role, true);
                $response = ['success' => true, 'notifications' => $notifications];
                break;
                
            case 'get_notification_details':
                $notification_id = intval($_POST['notification_id']);
                $notification_type = $_POST['notification_type'] ?? 'activity';
                
                if ($notification_type === 'activity') {
                    $query = "SELECT al.*, u.username, tc.title, p.name as project_name
                             FROM activity_logs al
                             JOIN users u ON al.user_id = u.id
                             LEFT JOIN test_cases tc ON tc.id = al.test_case_id
                             LEFT JOIN projects p ON p.id = al.project_id
                             WHERE al.id = ?";
                } else {
                    $query = "SELECT trl.*, u.username, tc.title, p.name as project_name
                             FROM tester_remark_logs trl
                             JOIN users u ON trl.user_id = u.id
                             LEFT JOIN test_cases tc ON tc.id = trl.test_case_id
                             LEFT JOIN projects p ON p.id = trl.project_id
                             WHERE trl.id = ?";
                }
                
                $stmt = $conn->prepare($query);
                $stmt->bind_param("i", $notification_id);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($row = $result->fetch_assoc()) {
                    $response = ['success' => true, 'notification' => $row];
                } else {
                    $response = ['success' => false, 'message' => 'Notification not found'];
                }
                break;
                
            case 'get_all_notifications':
                $notifications = getNotifications($conn, $user_id, $role, false);
                $response = ['success' => true, 'notifications' => $notifications];
                break;
                
            default:
                $response = ['success' => false, 'message' => 'Unknown action'];
        }
    } catch (Exception $e) {
        $response = ['success' => false, 'message' => 'Server error: ' . $e->getMessage()];
    }
    
    echo json_encode($response);
    exit;
}

// Function to get notifications
function getNotifications($conn, $user_id, $role, $unread_only = false) {
    $notifications = [];
    
    if ($role === 'super_admin' || $role === 'pm_manager') {
        // Admin sees all notifications
        $query = "
            (
                SELECT al.id, al.user_id, al.action, al.description, al.test_case_id, al.project_id, 
                       al.created_at, u.username, tc.title, 'vendor' as type, al.is_read,
                       p.name as project_name, 'activity' as log_type
                FROM activity_logs al
                JOIN users u ON al.user_id = u.id
                LEFT JOIN test_cases tc ON tc.id = al.test_case_id
                LEFT JOIN projects p ON p.id = al.project_id
                WHERE al.action IN ('Vendor Comment Updated', 'Test Case Created', 'Test Case Updated', 'Test Case Status Changed', 'Test Case Assigned')
                  " . ($unread_only ? "AND al.is_read = 0" : "") . "
            )
            UNION
            (
                SELECT trl.id, trl.user_id, trl.action, trl.description, trl.test_case_id, trl.project_id, 
                       trl.created_at, u.username, tc.title, 'tester' as type, trl.is_read,
                       p.name as project_name, 'tester_remark' as log_type
                FROM tester_remark_logs trl
                JOIN users u ON trl.user_id = u.id
                LEFT JOIN test_cases tc ON tc.id = trl.test_case_id
                LEFT JOIN projects p ON p.id = trl.project_id
                WHERE trl.action IN ('Tester Comment Updated', 'Test Case Reviewed', 'Test Case Rejected')
                  " . ($unread_only ? "AND trl.is_read = 0" : "") . "
            )
            ORDER BY created_at DESC
            " . ($unread_only ? "LIMIT 20" : "");
        $stmt = $conn->prepare($query);
    } else {
        // Regular users see only their project notifications
        $query = "
            (
                SELECT al.id, al.user_id, al.action, al.description, al.test_case_id, al.project_id, 
                       al.created_at, u.username, tc.title, 'vendor' as type, al.is_read,
                       p.name as project_name, 'activity' as log_type
                FROM activity_logs al
                JOIN users u ON al.user_id = u.id
                LEFT JOIN test_cases tc ON al.test_case_id = tc.id
                LEFT JOIN projects p ON p.id = al.project_id
                JOIN user_assignments pu ON pu.project_id = tc.project_id
                WHERE al.action IN ('Vendor Comment Updated', 'Test Case Created', 'Test Case Updated', 'Test Case Status Changed', 'Test Case Assigned')
                  AND pu.user_id = ?
                  " . ($unread_only ? "AND al.is_read = 0" : "") . "
            )
            UNION
            (
                SELECT trl.id, trl.user_id, trl.action, trl.description, trl.test_case_id, trl.project_id, 
                       trl.created_at, u.username, tc.title, 'tester' as type, trl.is_read,
                       p.name as project_name, 'tester_remark' as log_type
                FROM tester_remark_logs trl
                JOIN users u ON trl.user_id = u.id
                LEFT JOIN test_cases tc ON tc.id = trl.test_case_id
                LEFT JOIN projects p ON p.id = trl.project_id
                JOIN user_assignments pu ON pu.project_id = tc.project_id
                WHERE trl.action IN ('Tester Comment Updated', 'Test Case Reviewed', 'Test Case Rejected')
                  AND pu.user_id = ?
                  " . ($unread_only ? "AND trl.is_read = 0" : "") . "
            )
            ORDER BY created_at DESC
            " . ($unread_only ? "LIMIT 20" : "");
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ii", $user_id, $user_id);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $notifications[] = $row;
    }
    
    return $notifications;
}

// Get notifications count
$unread_notifications = getNotifications($conn, $user_id, $role, true);
$unread_count = count($unread_notifications);

// Admin statistics
if ($role === 'super_admin' || $role === 'pm_manager') {
    // Get total projects
    $result = $conn->query("SELECT COUNT(*) AS count FROM projects");
    $stats['total_projects'] = $result ? $result->fetch_assoc()['count'] : 0;
    
    // Get total users
    $result = $conn->query("SELECT COUNT(*) AS count FROM users");
    $stats['total_users'] = $result ? $result->fetch_assoc()['count'] : 0;
    
    // Get total test cases
    $result = $conn->query("SELECT COUNT(*) AS count FROM test_cases");
    $stats['total_testcases'] = $result ? $result->fetch_assoc()['count'] : 0;
    
    // Status counts
    $status_result = $conn->query("SELECT status, COUNT(*) as count FROM test_cases GROUP BY status");
    if ($status_result) {
        while ($row = $status_result->fetch_assoc()) {
            $status = strtolower($row['status']);
            switch ($status) {
                case 'pass':
                    $stats['passed_cases'] = $row['count'];
                    break;
                case 'fail':
                    $stats['failed_cases'] = $row['count'];
                    break;
                case 'pending':
                    $stats['pending_cases'] = $row['count'];
                    break;
                case 'deferred':
                    $stats['deferred_cases'] = $row['count'];
                    break;
            }
        }
    }

    // Test Cases per Project Data
    $project_counts = $conn->query("
        SELECT p.id, p.name, COUNT(tc.id) as total
        FROM projects p
        LEFT JOIN test_cases tc ON p.id = tc.project_id
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

// Tester/viewer stats
if ($role === 'tester' || $role === 'test_viewer' || $role === 'pm_employee') {
    // Get assigned projects count - FIXED: Use DISTINCT to count unique projects
    $result = $conn->query("SELECT COUNT(DISTINCT project_id) AS count FROM user_assignments WHERE user_id = $user_id");
    $stats['assigned_projects'] = $result ? $result->fetch_assoc()['count'] : 0;
    
    // Get total test cases for assigned projects - FIXED: Use DISTINCT in subquery
    $result = $conn->query("
        SELECT COUNT(*) AS count 
        FROM test_cases 
        WHERE project_id IN (
            SELECT DISTINCT project_id 
            FROM user_assignments 
            WHERE user_id = $user_id
        )
    ");
    $stats['total_testcases'] = $result ? $result->fetch_assoc()['count'] : 0;
    
    // Get status counts for assigned projects - FIXED: Use DISTINCT in subquery
    $status_counts = $conn->query("
        SELECT status, COUNT(*) AS count 
        FROM test_cases 
        WHERE project_id IN (
            SELECT DISTINCT project_id 
            FROM user_assignments 
            WHERE user_id = $user_id
        )
        GROUP BY status
    ");
    if ($status_counts) {
        while ($row = $status_counts->fetch_assoc()) {
            $status = strtolower($row['status']);
            switch ($status) {
                case 'pass':
                    $stats['passed_cases'] = $row['count'];
                    break;
                case 'fail':
                    $stats['failed_cases'] = $row['count'];
                    break;
                case 'pending':
                    $stats['pending_cases'] = $row['count'];
                    break;
                case 'deferred':
                    $stats['deferred_cases'] = $row['count'];
                    break;
            }
        }
    }
}

// Recent activities (last 5 test case updates)
$recent_activities = [];
$activities_query = ($role === 'super_admin' || $role === 'pm_manager')
    ? "SELECT tc.id, tc.title, tc.status, p.name as project_name, u.username, tc.updated_at
       FROM test_cases tc
       JOIN projects p ON tc.project_id = p.id
       JOIN users u ON tc.created_by = u.id
       ORDER BY tc.updated_at DESC LIMIT 5"
    : "SELECT tc.id, tc.title, tc.status, p.name as project_name, u.username, tc.updated_at
       FROM test_cases tc
       JOIN projects p ON tc.project_id = p.id
       JOIN users u ON tc.created_by = u.id
       WHERE tc.project_id IN (SELECT project_id FROM user_assignments WHERE user_id = $user_id)
       ORDER BY tc.updated_at DESC LIMIT 5";

$result = $conn->query($activities_query);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $recent_activities[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dashboard - Test Manager</title>
  <!-- Bootstrap 5 CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <!-- Font Awesome -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <!-- Chart.js -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <!-- Custom Styles -->
  <style>
    :root {
      --dashen-primary: #273274;
      --dashen-secondary: #012169;
      --dashen-accent: #e41e26;
      --dashen-light: #f8f9fa;
      --dashen-dark: #2c3e50;
      --dashen-success: #28a745;
      --dashen-warning: #ffc107;
      --dashen-danger: #dc3545;
      --dashen-info: #17a2b8;
      --dashen-gradient: linear-gradient(135deg, #273274 0%, #012169 100%);
    }
    
    body {
      background-color: #f8fafc;
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      margin: 0;
      padding: 0;
      overflow-x: hidden;
    }
    
    .content {
      margin-left: 280px;
      padding: 20px;
      transition: margin-left 0.3s ease;
      min-height: 100vh;
    }
    
    @media (max-width: 992px) {
      .content {
        margin-left: 0;
        padding: 15px;
      }
    }
    
    /* Welcome Banner */
    .welcome-banner {
      background: var(--dashen-gradient);
      color: white;
      border-radius: 12px;
      padding: 2rem 2.5rem;
      margin-bottom: 2rem;
      box-shadow: 0 4px 20px rgba(39, 50, 116, 0.15);
      position: relative;
      overflow: hidden;
    }
    
    .welcome-banner::before {
      content: '';
      position: absolute;
      top: -50%;
      right: -30%;
      width: 300px;
      height: 300px;
      background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 0%, rgba(255, 255, 255, 0) 70%);
      border-radius: 50%;
    }
    
    /* Stat Cards */
    .stat-card {
      border: none;
      border-radius: 12px;
      box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
      transition: all 0.3s ease;
      margin-bottom: 1.5rem;
      overflow: hidden;
      position: relative;
    }
    
    .stat-card:hover {
      transform: translateY(-5px);
      box-shadow: 0 8px 25px rgba(0, 0, 0, 0.12);
    }
    
    .stat-card::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 4px;
      background: var(--dashen-gradient);
    }
    
    .stat-card-body {
      padding: 1.75rem;
    }
    
    .stat-value {
      font-size: 2.5rem;
      font-weight: 800;
      margin: 0.5rem 0;
      color: var(--dashen-primary);
    }
    
    .stat-label {
      color: #6c757d;
      font-size: 0.9rem;
      text-transform: uppercase;
      font-weight: 600;
      letter-spacing: 0.5px;
    }
    
    .stat-icon {
      font-size: 2.5rem;
      color: var(--dashen-primary);
      opacity: 0.8;
    }
    
    /* Chart Cards */
    .chart-card {
      border: none;
      border-radius: 12px;
      box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
      margin-bottom: 1.5rem;
      overflow: hidden;
    }
    
    .chart-header {
      background: var(--dashen-gradient);
      color: white;
      padding: 1.25rem 1.75rem;
      border-bottom: none;
      font-size: 1.1rem;
      font-weight: 600;
    }
    
    .chart-body {
      padding: 1.5rem;
    }
    
    .chart-area {
      position: relative;
      height: 300px;
      width: 100%;
    }
    
    /* Activity Card */
    .activity-card {
      border: none;
      border-radius: 12px;
      box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
      margin-bottom: 1.5rem;
      overflow: hidden;
      height: 100%;
    }
    
    .activity-item {
      position: relative;
      padding-left: 2rem;
      margin-bottom: 1.25rem;
    }
    
    .activity-item:last-child {
      margin-bottom: 0;
    }
    
    .activity-badge {
      position: absolute;
      left: 0;
      top: 0.25rem;
      width: 12px;
      height: 12px;
      border-radius: 50%;
    }
    
    .activity-time {
      font-size: 0.8rem;
      color: #6c757d;
    }
    
    /* Table Improvements */
    .data-table {
      width: 100%;
    }
    
    .data-table th {
      background: #f8f9fa;
      font-weight: 600;
      color: #495057;
      border-bottom: 2px solid #dee2e6;
      padding: 1rem;
    }
    
    .data-table td {
      padding: 1rem;
      vertical-align: middle;
    }
    
    .data-table tbody tr:hover {
      background-color: rgba(39, 50, 116, 0.03);
    }
    
    /* FIXED Notification System - Positioned above everything */
    .notification-wrapper {
      position: relative;
      display: inline-block;
    }
    
    .notification-bell {
      position: relative;
      font-size: 1.5rem;
      cursor: pointer;
      color: white;
      background: rgba(255, 255, 255, 0.2);
      width: 50px;
      height: 50px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: all 0.3s ease;
      z-index: 1000;
    }
    
    .notification-bell:hover {
      background: rgba(255, 255, 255, 0.3);
      transform: scale(1.05);
    }
    
    .notification-count {
      position: absolute;
      top: 0;
      right: 0;
      background: var(--dashen-accent);
      color: white;
      border-radius: 50%;
      width: 22px;
      height: 22px;
      font-size: 0.8rem;
      font-weight: bold;
      display: flex;
      align-items: center;
      justify-content: center;
      animation: pulse 2s infinite;
      border: 2px solid var(--dashen-secondary);
      box-shadow: 0 0 10px rgba(228, 30, 38, 0.5);
      z-index: 1001;
    }
    
    @keyframes pulse {
      0% { transform: scale(1); box-shadow: 0 0 0 0 rgba(228, 30, 38, 0.7); }
      70% { transform: scale(1.1); box-shadow: 0 0 0 10px rgba(228, 30, 38, 0); }
      100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(228, 30, 38, 0); }
    }
    
    /* FIXED: Notification Dropdown - Positioned fixed above everything */
    .notification-dropdown {
      position: fixed !important;
      top: 80px !important;
      right: 30px !important;
      min-width: 450px;
      max-width: 450px;
      max-height: 600px;
      overflow-y: auto;
      border: none;
      border-radius: 12px;
      box-shadow: 0 10px 40px rgba(0, 0, 0, 0.25);
      z-index: 9999 !important;
      transform: none !important;
      margin-top: 0 !important;
    }
    
    .notification-header {
      background: var(--dashen-gradient);
      color: white;
      padding: 1.25rem 1.5rem;
      border-bottom: none;
      border-radius: 12px 12px 0 0;
      position: sticky;
      top: 0;
      z-index: 1;
    }
    
    .notification-header h6 {
      margin: 0;
      font-weight: 600;
    }
    
    .notification-body {
      padding: 0;
      max-height: 450px;
      overflow-y: auto;
    }
    
    .notification-item {
      padding: 1rem 1.25rem;
      border-bottom: 1px solid #f0f0f0;
      transition: all 0.2s ease;
      cursor: pointer;
      position: relative;
      display: block;
      text-decoration: none;
      color: inherit;
      background: white;
    }
    
    .notification-item:last-child {
      border-bottom: none;
    }
    
    .notification-item:hover {
      background: #f8f9ff;
      text-decoration: none;
      color: inherit;
    }
    
    .notification-item.unread {
      background: linear-gradient(90deg, rgba(39, 50, 116, 0.05) 0%, rgba(255, 255, 255, 1) 100%);
      border-left: 4px solid var(--dashen-primary);
    }
    
    .notification-icon {
      width: 36px;
      height: 36px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      margin-right: 12px;
      flex-shrink: 0;
      background: var(--dashen-primary);
      color: white;
    }
    
    .notification-content {
      flex: 1;
      min-width: 0;
    }
    
    .notification-title {
      font-weight: 600;
      margin-bottom: 4px;
      color: #2c3e50;
      font-size: 0.95rem;
    }
    
    .notification-message {
      color: #6c757d;
      font-size: 0.85rem;
      margin-bottom: 4px;
      line-height: 1.4;
    }
    
    .notification-meta {
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 0.75rem;
      color: #adb5bd;
    }
    
    .notification-project {
      background: #e9ecef;
      color: #495057;
      padding: 2px 6px;
      border-radius: 4px;
      font-weight: 500;
    }
    
    .notification-time {
      white-space: nowrap;
    }
    
    .notification-actions {
      position: absolute;
      top: 10px;
      right: 10px;
      opacity: 0;
      transition: opacity 0.2s ease;
      z-index: 2;
    }
    
    .notification-item:hover .notification-actions {
      opacity: 1;
    }
    
    .notification-action-btn {
      width: 28px;
      height: 28px;
      border-radius: 6px;
      border: none;
      background: #f8f9fa;
      color: #6c757d;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      transition: all 0.2s ease;
      font-size: 0.875rem;
      margin-left: 4px;
    }
    
    .notification-action-btn:hover {
      background: var(--dashen-primary);
      color: white;
      transform: translateY(-1px);
    }
    
    .notification-footer {
      background: #f8f9fa;
      padding: 1rem 1.25rem;
      border-top: 1px solid #e9ecef;
      border-radius: 0 0 12px 12px;
      position: sticky;
      bottom: 0;
    }
    
    .no-notifications {
      text-align: center;
      padding: 3rem 1.5rem;
      color: #6c757d;
    }
    
    .no-notifications i {
      font-size: 3rem;
      margin-bottom: 1rem;
      color: #dee2e6;
    }
    
    .loading-notifications {
      text-align: center;
      padding: 2rem;
    }
    
    /* Status Badges */
    .badge-sm {
      padding: 0.25rem 0.5rem;
      font-size: 0.75rem;
      font-weight: 600;
      border-radius: 4px;
    }
    
    .badge-status-pass { background: #d4edda; color: #155724; }
    .badge-status-fail { background: #f8d7da; color: #721c24; }
    .badge-status-pending { background: #fff3cd; color: #856404; }
    .badge-status-deferred { background: #e2e3e5; color: #383d41; }
    
    /* Buttons */
    .btn-dashen {
      background: var(--dashen-gradient);
      color: white;
      border: none;
      border-radius: 6px;
      padding: 0.5rem 1.25rem;
      font-weight: 600;
      transition: all 0.3s ease;
    }
    
    .btn-dashen:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 15px rgba(39, 50, 116, 0.3);
      color: white;
    }
    
    .btn-outline-dashen {
      color: var(--dashen-primary);
      border: 1px solid var(--dashen-primary);
      background: transparent;
      border-radius: 6px;
      padding: 0.5rem 1.25rem;
      font-weight: 600;
      transition: all 0.3s ease;
    }
    
    .btn-outline-dashen:hover {
      background: var(--dashen-primary);
      color: white;
    }
    
    /* Custom Toast */
    .custom-toast {
      position: fixed;
      top: 20px;
      right: 20px;
      z-index: 99999;
      min-width: 300px;
    }
    
    .toast-success {
      border-left: 4px solid #28a745;
    }
    
    .toast-error {
      border-left: 4px solid #dc3545;
    }
    
    .toast-info {
      border-left: 4px solid #17a2b8;
    }
    
    .toast-warning {
      border-left: 4px solid #ffc107;
    }
    
    /* Empty State */
    .empty-state {
      text-align: center;
      padding: 3rem 1rem;
    }
    
    .empty-state-icon {
      font-size: 3rem;
      color: #dee2e6;
      margin-bottom: 1rem;
    }
    
    /* Modal Styles */
    .notification-modal .modal-dialog {
      max-width: 600px;
    }
    
    .notification-modal .modal-header {
      background: var(--dashen-gradient);
      color: white;
      border-bottom: none;
    }
    
    .notification-modal .modal-body {
      max-height: 70vh;
      overflow-y: auto;
    }
    
    .notification-detail {
      padding: 1rem;
      border-radius: 8px;
      background: #f8f9fa;
      margin-bottom: 1rem;
    }
    
    .notification-detail-item {
      margin-bottom: 0.5rem;
    }
    
    .notification-detail-label {
      font-weight: 600;
      color: #495057;
      min-width: 120px;
      display: inline-block;
    }
    
    .notification-detail-value {
      color: #6c757d;
    }
    
    /* Responsive */
    @media (max-width: 768px) {
      .welcome-banner {
        padding: 1.5rem;
      }
      
      .stat-value {
        font-size: 2rem;
      }
      
      .notification-dropdown {
        min-width: 320px;
        max-width: 320px;
        max-height: 500px;
        position: fixed !important;
        top: 70px !important;
        right: 10px !important;
        left: auto !important;
      }
      
      .chart-area {
        height: 250px;
      }
      
      .notification-modal .modal-dialog {
        max-width: 95%;
        margin: 10px auto;
      }
    }
    
    /* Ensure dropdown is above everything */
    .dropdown-menu.show {
      display: block !important;
      pointer-events: auto;
    }
  </style>
</head>
<body>
  <!-- Include Sidebar -->
  <?php 
  $current_page = 'dashboard_testcase.php';
  include 'sidebar.php'; 
  ?>
  
  <!-- Main Content -->
  <div class="content">
    <!-- Welcome Banner -->
    <div class="welcome-banner">
      <div class="row align-items-center">
        <div class="col-lg-8">
          <h1 class="display-6 fw-bold"><i class="fas fa-tachometer-alt me-2"></i>Test Case Dashboard</h1>
          <p class="lead mb-0">Welcome back, <?= htmlspecialchars($username) ?>! Today is <?= $today ?></p>
        </div>
        <div class="col-lg-4 text-lg-end">
          <!-- FIXED: Notification Bell -->
          <div class="notification-wrapper d-inline-block">
            <div class="dropdown">
              <button class="notification-bell dropdown-toggle" 
                      id="notificationDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="fas fa-bell"></i>
                <?php if ($unread_count > 0): ?>
                <span class="notification-count" id="notificationCount"><?= $unread_count ?></span>
                <?php endif; ?>
              </button>
              <div class="dropdown-menu dropdown-menu-end notification-dropdown" id="notificationDropdownMenu" aria-labelledby="notificationDropdown">
                <div class="notification-header d-flex justify-content-between align-items-center">
                  <h6 class="mb-0">
                    <i class="fas fa-bell me-2"></i>Notifications
                    <?php if ($unread_count > 0): ?>
                    <span class="badge bg-light text-dark ms-2" id="notificationBadge"><?= $unread_count ?> new</span>
                    <?php endif; ?>
                  </h6>
                  <div class="d-flex gap-2">
                    <button class="btn btn-sm btn-outline-light p-1" onclick="markAllNotificationsRead()" title="Mark all as read">
                      <i class="fas fa-check-double"></i>
                    </button>
                    <button class="btn btn-sm btn-outline-light p-1" onclick="refreshNotifications()" title="Refresh">
                      <i class="fas fa-sync-alt"></i>
                    </button>
                  </div>
                </div>
                <div class="notification-body" id="notificationList">
                  <!-- Notifications will be loaded via AJAX -->
                  <div class="loading-notifications">
                    <div class="spinner-border spinner-border-sm text-primary" role="status">
                      <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="text-muted small mt-2">Loading notifications...</p>
                  </div>
                </div>
                <div class="notification-footer text-center">
                  <button class="btn btn-outline-dashen btn-sm w-100" onclick="viewAllNotifications()">
                    <i class="fas fa-list me-1"></i> View All Notifications
                  </button>
                </div>
              </div>
            </div>
          </div>
          
          <span class="badge bg-light text-dark ms-2 p-2">
            <i class="fas fa-user me-1"></i> <?= ucfirst(str_replace('_', ' ', $role)) ?>
          </span>
        </div>
      </div>
    </div>

   <!-- Statistics Cards -->
<div class="row mb-4">
  <?php if ($role === 'super_admin' || $role === 'pm_manager'): ?>
    <!-- Admin Stats -->
    <div class="col-xl-3 col-md-6">
      <div class="stat-card">
        <div class="stat-card-body">
          <div class="d-flex justify-content-between align-items-center">
            <div>
              <div class="stat-label">Total Projects</div>
              <div class="stat-value"><?= $stats['total_projects'] ?></div>
            </div>
            <div class="stat-icon">
              <i class="fas fa-project-diagram"></i>
            </div>
          </div>
          <?php if ($role === 'super_admin'): ?>
            <a href="admin_projects.php" class="stretched-link"></a>
          <?php elseif ($role === 'pm_manager'): ?>
            <a href="TC_assigned_projects.php" class="stretched-link"></a>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="col-xl-3 col-md-6">
      <div class="stat-card">
        <div class="stat-card-body">
          <div class="d-flex justify-content-between align-items-center">
            <div>
              <div class="stat-label">Total Users</div>
              <div class="stat-value"><?= $stats['total_users'] ?></div>
            </div>
            <div class="stat-icon">
              <i class="fas fa-users"></i>
            </div>
          </div>
          <a href="dashboard_testcase.php" class="stretched-link"></a>
        </div>
      </div>
    </div>

    <div class="col-xl-3 col-md-6">
      <div class="stat-card">
        <div class="stat-card-body">
          <div class="d-flex justify-content-between align-items-center">
            <div>
              <div class="stat-label">Total Test Cases</div>
              <div class="stat-value"><?= $stats['total_testcases'] ?></div>
            </div>
            <div class="stat-icon">
              <i class="fas fa-list-check"></i>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="col-xl-3 col-md-6">
      <div class="stat-card">
        <div class="stat-card-body">
          <div class="d-flex justify-content-between align-items-center">
            <div>
              <div class="stat-label">Pending Cases</div>
              <div class="stat-value"><?= $stats['pending_cases'] ?></div>
            </div>
            <div class="stat-icon">
              <i class="fas fa-clock"></i>
            </div>
          </div>
        </div>
      </div>
    </div>

      <?php elseif ($role === 'tester'): ?>
        <!-- Tester Stats -->
        <div class="col-xl-4 col-md-6">
          <div class="stat-card">
            <div class="stat-card-body">
              <div class="d-flex justify-content-between align-items-center">
                <div>
                  <div class="stat-label">My Projects</div>
                  <div class="stat-value"><?= $stats['assigned_projects'] ?></div>
                </div>
                <div class="stat-icon">
                  <i class="fas fa-project-diagram"></i>
                </div>
              </div>
              <a href="TC_assigned_projects.php" class="stretched-link"></a>
            </div>
          </div>
        </div>

        <div class="col-xl-4 col-md-6">
          <div class="stat-card">
            <div class="stat-card-body">
              <div class="d-flex justify-content-between align-items-center">
                <div>
                  <div class="stat-label">Test Cases</div>
                  <div class="stat-value"><?= $stats['total_testcases'] ?></div>
                </div>
                <div class="stat-icon">
                  <i class="fas fa-list-check"></i>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="col-xl-4 col-md-6">
          <div class="stat-card">
            <div class="stat-card-body">
              <div class="d-flex justify-content-between align-items-center">
                <div>
                  <div class="stat-label">Passed Cases</div>
                  <div class="stat-value"><?= $stats['passed_cases'] ?></div>
                </div>
                <div class="stat-icon">
                  <i class="fas fa-check-circle"></i>
                </div>
              </div>
            </div>
          </div>
        </div>

      <?php elseif ($role === 'test_viewer' || $role === 'pm_employee'): ?>
        <!-- Viewer/Employee Stats -->
        <div class="col-md-6">
          <div class="stat-card">
            <div class="stat-card-body">
              <div class="d-flex justify-content-between align-items-center">
                <div>
                  <div class="stat-label">Assigned Projects</div>
                  <div class="stat-value"><?= $stats['assigned_projects'] ?></div>
                </div>
                <div class="stat-icon">
                  <i class="fas fa-project-diagram"></i>
                </div>
              </div>
              <a href="TC_assigned_projects.php" class="stretched-link"></a>
            </div>
          </div>
        </div>

        <div class="col-md-6">
          <div class="stat-card">
            <div class="stat-card-body">
              <div class="d-flex justify-content-between align-items-center">
                <div>
                  <div class="stat-label">Test Cases</div>
                  <div class="stat-value"><?= $stats['total_testcases'] ?></div>
                </div>
                <div class="stat-icon">
                  <i class="fas fa-list-check"></i>
                </div>
              </div>
            </div>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <!-- Charts and Recent Activity -->
    <div class="row">
      <?php if ($role === 'super_admin' || $role === 'pm_manager'): ?>
        <!-- Admin Charts -->
        <div class="col-lg-8 mb-4">
          <div class="chart-card">
            <div class="chart-header">
              <i class="fas fa-chart-pie me-2"></i>Test Case Status Distribution
            </div>
            <div class="chart-body">
              <div class="chart-area">
                <canvas id="statusChart"></canvas>
              </div>
            </div>
          </div>
        </div>

        <div class="col-lg-4 mb-4">
          <div class="activity-card">
            <div class="chart-header">
              <i class="fas fa-history me-2"></i>Recent Activity
            </div>
            <div class="chart-body">
              <?php if (!empty($recent_activities)): ?>
                <div class="mt-2">
                  <?php foreach ($recent_activities as $activity): 
                    $badge_class = '';
                    if ($activity['status'] == 'Pass') $badge_class = 'badge-status-pass';
                    elseif ($activity['status'] == 'Fail') $badge_class = 'badge-status-fail';
                    elseif ($activity['status'] == 'Pending') $badge_class = 'badge-status-pending';
                    else $badge_class = 'badge-status-deferred';
                  ?>
                    <div class="activity-item">
                      <div class="d-flex justify-content-between align-items-start">
                        <div class="flex-grow-1 me-2">
                          <h6 class="mb-1" style="font-size: 0.9rem;"><?= htmlspecialchars($activity['title']) ?></h6>
                          <p class="small mb-0">
                            <span class="badge badge-sm <?= $badge_class ?>">
                              <?= htmlspecialchars($activity['status']) ?>
                            </span>
                            in <?= htmlspecialchars($activity['project_name']) ?>
                          </p>
                          <p class="small text-muted mb-0">by <?= htmlspecialchars($activity['username']) ?></p>
                        </div>
                        <small class="activity-time"><?= date('M j, g:i a', strtotime($activity['updated_at'])) ?></small>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <div class="empty-state">
                  <div class="empty-state-icon">
                    <i class="fas fa-info-circle"></i>
                  </div>
                  <p class="text-muted">No recent activity found</p>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <?php if (!empty($project_names)): ?>
        <div class="col-lg-12 mb-4">
          <div class="chart-card">
            <div class="chart-header">
              <i class="fas fa-chart-bar me-2"></i>Test Cases by Project (Top 5)
            </div>
            <div class="chart-body">
              <div class="chart-area">
                <canvas id="projectChart"></canvas>
              </div>
            </div>
          </div>
        </div>
        <?php endif; ?>

      <?php else: ?>
        <!-- Tester/Viewer Recent Activity -->
        <div class="col-lg-12 mb-4">
          <div class="chart-card">
            <div class="chart-header">
              <i class="fas fa-history me-2"></i>
              <?= $role === 'tester' ? 'My Recent Activities' : 'Recent Project Activities' ?>
            </div>
            <div class="chart-body">
              <?php if (!empty($recent_activities)): ?>
                <div class="table-responsive">
                  <table class="table data-table">
                    <thead>
                      <tr>
                        <th>Test Case</th>
                        <th>Project</th>
                        <th>Status</th>
                        <th>Updated By</th>
                        <th>Last Updated</th>
                        <th class="text-end">Actions</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($recent_activities as $activity): ?>
                        <tr>
                          <td><?= htmlspecialchars($activity['title']) ?></td>
                          <td><?= htmlspecialchars($activity['project_name']) ?></td>
                          <td>
                            <span class="badge badge-sm <?= strtolower($activity['status']) == 'pass' ? 'badge-status-pass' : 
                                                           (strtolower($activity['status']) == 'fail' ? 'badge-status-fail' : 
                                                           (strtolower($activity['status']) == 'pending' ? 'badge-status-pending' : 'badge-status-deferred')) ?>">
                              <?= htmlspecialchars($activity['status']) ?>
                            </span>
                          </td>
                          <td><?= htmlspecialchars($activity['username']) ?></td>
                          <td><?= date('M j, Y g:i a', strtotime($activity['updated_at'])) ?></td>
                          <td class="text-end">
                            <a href="TC_assigned_projects.php?id=<?= $activity['id'] ?>" class="btn btn-sm btn-outline-primary" title="View Test Case">
                              <i class="fas fa-eye"></i>
                            </a>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php else: ?>
                <div class="empty-state">
                  <div class="empty-state-icon">
                    <i class="fas fa-info-circle"></i>
                  </div>
                  <p class="text-muted">No recent activity found</p>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Toast Container -->
  <div id="toastContainer" class="custom-toast"></div>

  <!-- Notification Detail Modal -->
  <div class="modal fade notification-modal" id="notificationDetailModal" tabindex="-1" aria-labelledby="notificationDetailModalLabel" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="notificationDetailModalLabel">
            <i class="fas fa-bell me-2"></i>Notification Details
          </h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body" id="notificationDetailContent">
          <!-- Content will be loaded via AJAX -->
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          <button type="button" class="btn btn-dashen" id="markDetailReadBtn" onclick="markCurrentNotificationRead()">
            <i class="fas fa-check me-1"></i> Mark as Read
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- All Notifications Modal -->
  <div class="modal fade notification-modal" id="allNotificationsModal" tabindex="-1" aria-labelledby="allNotificationsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="allNotificationsModalLabel">
            <i class="fas fa-list me-2"></i>All Notifications
          </h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
              <button class="btn btn-outline-primary btn-sm" onclick="markAllNotificationsReadInModal()">
                <i class="fas fa-check-double me-1"></i> Mark All as Read
              </button>
            </div>
            <div class="d-flex gap-2">
              <button class="btn btn-outline-secondary btn-sm" onclick="refreshAllNotifications()">
                <i class="fas fa-sync-alt"></i>
              </button>
              <div class="dropdown">
                <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown">
                  <i class="fas fa-filter me-1"></i> Filter
                </button>
                <ul class="dropdown-menu">
                  <li><a class="dropdown-item" href="#" onclick="filterNotifications('all')">All Notifications</a></li>
                  <li><a class="dropdown-item" href="#" onclick="filterNotifications('unread')">Unread Only</a></li>
                  <li><a class="dropdown-item" href="#" onclick="filterNotifications('read')">Read Only</a></li>
                </ul>
              </div>
            </div>
          </div>
          <div class="notification-list" id="allNotificationsList" style="max-height: 60vh; overflow-y: auto;">
            <!-- All notifications will be loaded here -->
            <div class="text-center py-5">
              <div class="spinner-border spinner-border-sm text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
              </div>
              <p class="text-muted small mt-2">Loading notifications...</p>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        </div>
      </div>
    </div>
  </div>

  <!-- JavaScript Libraries -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
  
  <script>
  // Global variables for current notification
  let currentNotificationId = null;
  let currentNotificationType = null;
  let currentFilter = 'all';

  $(document).ready(function() {
    // Initialize dropdown
    const notificationDropdown = new bootstrap.Dropdown(document.getElementById('notificationDropdown'));
    
    // Load notifications initially
    loadNotifications();
    
    // Close dropdown when clicking outside
    $(document).on('click', function(e) {
      if (!$(e.target).closest('.notification-wrapper').length) {
        const dropdown = document.getElementById('notificationDropdownMenu');
        if (dropdown.classList.contains('show')) {
          notificationDropdown.hide();
        }
      }
    });
    
    // Refresh notifications every 30 seconds
    setInterval(loadNotifications, 30000);
    
    // Show notification count in title
    updateTitleNotificationCount();
  });
  
  // Function to load notifications
  function loadNotifications() {
    $.ajax({
      url: 'dashboard_testcase.php',
      method: 'POST',
      data: {
        action: 'get_notifications'
      },
      beforeSend: function() {
        // Show loading state
        $('#notificationList').html(`
          <div class="loading-notifications">
            <div class="spinner-border spinner-border-sm text-primary" role="status">
              <span class="visually-hidden">Loading...</span>
            </div>
            <p class="text-muted small mt-2">Loading notifications...</p>
          </div>
        `);
      },
      success: function(response) {
        if (response.success) {
          const notifications = response.notifications;
          const notificationList = $('#notificationList');
          
          if (notifications.length > 0) {
            let html = '';
            let unreadCount = 0;
            
            notifications.forEach(notification => {
              const timeAgo = getTimeAgo(notification.created_at);
              const isUnread = notification.is_read == 0;
              
              if (isUnread) unreadCount++;
              
              // Determine icon based on type
              let iconClass = 'fas fa-bell';
              if (notification.type === 'vendor') {
                iconClass = 'fas fa-user-tie';
              } else if (notification.type === 'tester') {
                iconClass = 'fas fa-user-check';
              }
              
              // Get action icon
              let actionIcon = 'fas fa-info-circle';
              if (notification.action.includes('Comment')) {
                actionIcon = 'fas fa-comment';
              } else if (notification.action.includes('Created')) {
                actionIcon = 'fas fa-plus-circle';
              } else if (notification.action.includes('Updated')) {
                actionIcon = 'fas fa-edit';
              } else if (notification.action.includes('Status')) {
                actionIcon = 'fas fa-exchange-alt';
              } else if (notification.action.includes('Assigned')) {
                actionIcon = 'fas fa-user-tag';
              } else if (notification.action.includes('Reviewed')) {
                actionIcon = 'fas fa-check-circle';
              } else if (notification.action.includes('Rejected')) {
                actionIcon = 'fas fa-times-circle';
              }
              
              html += `
                <div class="notification-item ${isUnread ? 'unread' : ''}" 
                     data-notification-id="${notification.id}"
                     data-notification-type="${notification.log_type}"
                     onclick="viewNotificationDetails(${notification.id}, '${notification.log_type}')">
                  <div class="d-flex align-items-start">
                    <div class="notification-icon">
                      <i class="${iconClass}"></i>
                    </div>
                    <div class="notification-content">
                      <div class="notification-title">
                        <i class="${actionIcon} me-1"></i>
                        ${notification.action}
                      </div>
                      <div class="notification-message">
                        <strong>${notification.username || 'System'}</strong>
                        ${notification.description ? ': ' + notification.description.substring(0, 80) + (notification.description.length > 80 ? '...' : '') : ''}
                      </div>
                      <div class="notification-meta">
                        ${notification.project_name ? `<span class="notification-project">${notification.project_name}</span>` : ''}
                        ${notification.title ? `<span class="text-truncate">${notification.title}</span>` : ''}
                        <span class="notification-time">${timeAgo}</span>
                      </div>
                    </div>
                    <div class="notification-actions">
                      ${isUnread ? `
                      <button class="notification-action-btn" onclick="event.stopPropagation(); markNotificationRead(${notification.id}, '${notification.log_type}', this)" title="Mark as read">
                        <i class="fas fa-check"></i>
                      </button>
                      ` : ''}
                      <button class="notification-action-btn" onclick="event.stopPropagation(); viewNotificationDetails(${notification.id}, '${notification.log_type}')" title="View Details">
                        <i class="fas fa-eye"></i>
                      </button>
                    </div>
                  </div>
                </div>
              `;
            });
            
            notificationList.html(html);
          } else {
            notificationList.html(`
              <div class="no-notifications">
                <i class="far fa-bell-slash"></i>
                <p class="mb-0">No notifications yet</p>
                <small class="text-muted">You're all caught up!</small>
              </div>
            `);
          }
          
          // Update notification count
          updateNotificationCount(unreadCount);
        }
      },
      error: function() {
        console.error('Failed to load notifications');
        $('#notificationList').html(`
          <div class="no-notifications">
            <i class="fas fa-exclamation-triangle text-danger"></i>
            <p class="mb-0">Failed to load notifications</p>
            <small class="text-muted">Please try again later</small>
          </div>
        `);
      }
    });
  }
  
  // Function to view notification details in modal
  function viewNotificationDetails(notificationId, notificationType) {
    currentNotificationId = notificationId;
    currentNotificationType = notificationType;
    
    $.ajax({
      url: 'dashboard_testcase.php',
      method: 'POST',
      data: {
        action: 'get_notification_details',
        notification_id: notificationId,
        notification_type: notificationType
      },
      beforeSend: function() {
        $('#notificationDetailContent').html(`
          <div class="text-center py-5">
            <div class="spinner-border spinner-border-sm text-primary" role="status">
              <span class="visually-hidden">Loading...</span>
            </div>
            <p class="text-muted small mt-2">Loading notification details...</p>
          </div>
        `);
        
        // Hide mark as read button initially
        $('#markDetailReadBtn').hide();
      },
      success: function(response) {
        if (response.success) {
          const notification = response.notification;
          const formattedDate = new Date(notification.created_at).toLocaleString();
          
          let html = `
            <div class="notification-detail">
              <div class="notification-detail-item">
                <span class="notification-detail-label">Action:</span>
                <span class="notification-detail-value">${notification.action}</span>
              </div>
              <div class="notification-detail-item">
                <span class="notification-detail-label">User:</span>
                <span class="notification-detail-value">${notification.username}</span>
              </div>
              ${notification.project_name ? `
              <div class="notification-detail-item">
                <span class="notification-detail-label">Project:</span>
                <span class="notification-detail-value">${notification.project_name}</span>
              </div>
              ` : ''}
              ${notification.title ? `
              <div class="notification-detail-item">
                <span class="notification-detail-label">Test Case:</span>
                <span class="notification-detail-value">${notification.title}</span>
              </div>
              ` : ''}
              <div class="notification-detail-item">
                <span class="notification-detail-label">Description:</span>
                <span class="notification-detail-value">${notification.description || 'No description provided'}</span>
              </div>
              <div class="notification-detail-item">
                <span class="notification-detail-label">Time:</span>
                <span class="notification-detail-value">${formattedDate}</span>
              </div>
              <div class="notification-detail-item">
                <span class="notification-detail-label">Status:</span>
                <span class="notification-detail-value">
                  <span class="badge ${notification.is_read == 0 ? 'bg-warning' : 'bg-success'}">
                    ${notification.is_read == 0 ? 'Unread' : 'Read'}
                  </span>
                </span>
              </div>
            </div>
          `;
          
          $('#notificationDetailContent').html(html);
          
          // Show mark as read button only if notification is unread
          if (notification.is_read == 0) {
            $('#markDetailReadBtn').show();
          } else {
            $('#markDetailReadBtn').hide();
          }
        } else {
          $('#notificationDetailContent').html(`
            <div class="alert alert-danger">
              <i class="fas fa-exclamation-triangle me-2"></i>
              ${response.message}
            </div>
          `);
          $('#markDetailReadBtn').hide();
        }
        
        // Show the modal
        const modal = new bootstrap.Modal(document.getElementById('notificationDetailModal'));
        modal.show();
      },
      error: function() {
        $('#notificationDetailContent').html(`
          <div class="alert alert-danger">
            <i class="fas fa-exclamation-triangle me-2"></i>
            Failed to load notification details. Please try again.
          </div>
        `);
        $('#markDetailReadBtn').hide();
        
        const modal = new bootstrap.Modal(document.getElementById('notificationDetailModal'));
        modal.show();
      }
    });
  }
  
  // Function to mark current notification as read
  function markCurrentNotificationRead() {
    if (currentNotificationId && currentNotificationType) {
      markNotificationRead(currentNotificationId, currentNotificationType, null, true);
    }
  }
  
  // Function to mark notification as read
  function markNotificationRead(notificationId, notificationType, buttonElement, closeModal = false) {
    $.ajax({
      url: 'dashboard_testcase.php',
      method: 'POST',
      data: {
        action: 'mark_notification_read',
        notification_id: notificationId,
        notification_type: notificationType
      },
      success: function(response) {
        if (response.success) {
          if (buttonElement) {
            const notificationItem = $(buttonElement).closest('.notification-item');
            notificationItem.removeClass('unread');
            $(buttonElement).remove();
          }
          
          if (closeModal) {
            $('#notificationDetailModal').modal('hide');
            currentNotificationId = null;
            currentNotificationType = null;
          }
          
          // Update notification count
          updateNotificationCount();
          
          // Reload all notifications in modal if open
          if ($('#allNotificationsModal').hasClass('show')) {
            loadAllNotifications();
          }
          
          showToast('Success', 'Notification marked as read', 'success');
        } else {
          showToast('Error', 'Failed to mark notification as read', 'error');
        }
      },
      error: function() {
        console.error('Failed to mark notification as read');
        showToast('Error', 'Failed to mark notification as read', 'error');
      }
    });
  }
  
  // Function to mark all notifications as read
  function markAllNotificationsRead() {
    $.ajax({
      url: 'dashboard_testcase.php',
      method: 'POST',
      data: {
        action: 'mark_all_notifications_read'
      },
      beforeSend: function() {
        $('#notificationList').html(`
          <div class="loading-notifications">
            <div class="spinner-border spinner-border-sm text-primary" role="status">
              <span class="visually-hidden">Loading...</span>
            </div>
            <p class="text-muted small mt-2">Marking all as read...</p>
          </div>
        `);
      },
      success: function(response) {
        if (response.success) {
          // Reload notifications to reflect changes
          loadNotifications();
          showToast('Success', 'All notifications marked as read', 'success');
        } else {
          showToast('Error', 'Failed to mark all notifications as read', 'error');
          loadNotifications();
        }
      },
      error: function() {
        console.error('Failed to mark all notifications as read');
        showToast('Error', 'Failed to mark all notifications as read', 'error');
        loadNotifications();
      }
    });
  }
  
  // Function to mark all notifications as read in modal
  function markAllNotificationsReadInModal() {
    $.ajax({
      url: 'dashboard_testcase.php',
      method: 'POST',
      data: {
        action: 'mark_all_notifications_read'
      },
      success: function(response) {
        if (response.success) {
          loadAllNotifications();
          loadNotifications(); // Also reload dropdown notifications
          showToast('Success', 'All notifications marked as read', 'success');
        } else {
          showToast('Error', 'Failed to mark all notifications as read', 'error');
        }
      },
      error: function() {
        console.error('Failed to mark all notifications as read');
        showToast('Error', 'Failed to mark all notifications as read', 'error');
      }
    });
  }
  
  // Function to view all notifications in modal
  function viewAllNotifications() {
    // Close dropdown if open
    const dropdown = document.getElementById('notificationDropdownMenu');
    if (dropdown.classList.contains('show')) {
      const notificationDropdown = new bootstrap.Dropdown(document.getElementById('notificationDropdown'));
      notificationDropdown.hide();
    }
    
    // Load all notifications
    loadAllNotifications();
    
    // Show the modal
    const modal = new bootstrap.Modal(document.getElementById('allNotificationsModal'));
    modal.show();
  }
  
  // Function to load all notifications
  function loadAllNotifications() {
    $.ajax({
      url: 'dashboard_testcase.php',
      method: 'POST',
      data: {
        action: 'get_all_notifications'
      },
      beforeSend: function() {
        $('#allNotificationsList').html(`
          <div class="text-center py-5">
            <div class="spinner-border spinner-border-sm text-primary" role="status">
              <span class="visually-hidden">Loading...</span>
            </div>
            <p class="text-muted small mt-2">Loading all notifications...</p>
          </div>
        `);
      },
      success: function(response) {
        if (response.success) {
          const notifications = response.notifications;
          const allNotificationsList = $('#allNotificationsList');
          
          if (notifications.length > 0) {
            let html = '';
            let unreadCount = 0;
            
            notifications.forEach(notification => {
              const timeAgo = getTimeAgo(notification.created_at);
              const isUnread = notification.is_read == 0;
              
              if (isUnread) unreadCount++;
              
              // Apply filter
              if (currentFilter === 'unread' && !isUnread) return;
              if (currentFilter === 'read' && isUnread) return;
              
              // Determine icon based on type
              let iconClass = 'fas fa-bell';
              if (notification.type === 'vendor') {
                iconClass = 'fas fa-user-tie';
              } else if (notification.type === 'tester') {
                iconClass = 'fas fa-user-check';
              }
              
              // Get action icon
              let actionIcon = 'fas fa-info-circle';
              if (notification.action.includes('Comment')) {
                actionIcon = 'fas fa-comment';
              } else if (notification.action.includes('Created')) {
                actionIcon = 'fas fa-plus-circle';
              } else if (notification.action.includes('Updated')) {
                actionIcon = 'fas fa-edit';
              } else if (notification.action.includes('Status')) {
                actionIcon = 'fas fa-exchange-alt';
              } else if (notification.action.includes('Assigned')) {
                actionIcon = 'fas fa-user-tag';
              } else if (notification.action.includes('Reviewed')) {
                actionIcon = 'fas fa-check-circle';
              } else if (notification.action.includes('Rejected')) {
                actionIcon = 'fas fa-times-circle';
              }
              
              html += `
                <div class="notification-item ${isUnread ? 'unread' : ''}" 
                     onclick="viewNotificationDetails(${notification.id}, '${notification.log_type}')">
                  <div class="d-flex align-items-start">
                    <div class="notification-icon">
                      <i class="${iconClass}"></i>
                    </div>
                    <div class="notification-content">
                      <div class="notification-title">
                        <i class="${actionIcon} me-1"></i>
                        ${notification.action}
                      </div>
                      <div class="notification-message">
                        <strong>${notification.username || 'System'}</strong>
                        ${notification.description ? ': ' + notification.description.substring(0, 80) + (notification.description.length > 80 ? '...' : '') : ''}
                      </div>
                      <div class="notification-meta">
                        ${notification.project_name ? `<span class="notification-project">${notification.project_name}</span>` : ''}
                        ${notification.title ? `<span class="text-truncate">${notification.title}</span>` : ''}
                        <span class="notification-time">${timeAgo}</span>
                        <span class="badge ${isUnread ? 'bg-warning' : 'bg-success'}">
                          ${isUnread ? 'Unread' : 'Read'}
                        </span>
                      </div>
                    </div>
                    <div class="notification-actions">
                      ${isUnread ? `
                      <button class="notification-action-btn" onclick="event.stopPropagation(); markNotificationRead(${notification.id}, '${notification.log_type}', this)" title="Mark as read">
                        <i class="fas fa-check"></i>
                      </button>
                      ` : ''}
                      <button class="notification-action-btn" onclick="event.stopPropagation(); viewNotificationDetails(${notification.id}, '${notification.log_type}')" title="View Details">
                        <i class="fas fa-eye"></i>
                      </button>
                    </div>
                  </div>
                </div>
              `;
            });
            
            allNotificationsList.html(html);
            
            // Add count header
            const header = `
              <div class="alert alert-info mb-3">
                <i class="fas fa-info-circle me-2"></i>
                Showing ${notifications.filter(n => {
                  if (currentFilter === 'all') return true;
                  if (currentFilter === 'unread') return n.is_read == 0;
                  if (currentFilter === 'read') return n.is_read == 1;
                  return true;
                }).length} notifications (${unreadCount} unread)
              </div>
            `;
            allNotificationsList.prepend(header);
          } else {
            allNotificationsList.html(`
              <div class="no-notifications">
                <i class="far fa-bell-slash"></i>
                <p class="mb-0">No notifications found</p>
                <small class="text-muted">You're all caught up!</small>
              </div>
            `);
          }
        }
      },
      error: function() {
        console.error('Failed to load all notifications');
        $('#allNotificationsList').html(`
          <div class="no-notifications">
            <i class="fas fa-exclamation-triangle text-danger"></i>
            <p class="mb-0">Failed to load notifications</p>
            <small class="text-muted">Please try again later</small>
          </div>
        `);
      }
    });
  }
  
  // Function to refresh all notifications in modal
  function refreshAllNotifications() {
    loadAllNotifications();
    showToast('Info', 'Notifications refreshed', 'info');
  }
  
  // Function to filter notifications
  function filterNotifications(filter) {
    currentFilter = filter;
    loadAllNotifications();
  }
  
  // Function to refresh notifications
  function refreshNotifications() {
    loadNotifications();
    showToast('Info', 'Notifications refreshed', 'info');
  }
  
  // Function to update notification count
  function updateNotificationCount(count = null) {
    const notificationCount = $('#notificationCount');
    const notificationBadge = $('#notificationBadge');
    
    if (count !== null) {
      if (count > 0) {
        if (notificationCount.length === 0) {
          $('.notification-bell').append(`<span class="notification-count" id="notificationCount">${count}</span>`);
        } else {
          notificationCount.text(count).show();
        }
        if (notificationBadge.length) {
          notificationBadge.text(count + ' new').show();
        }
        // Restart pulse animation
        notificationCount.css('animation', 'none');
        setTimeout(() => {
          notificationCount.css('animation', 'pulse 2s infinite');
        }, 10);
      } else {
        notificationCount.hide();
        if (notificationBadge.length) {
          notificationBadge.hide();
        }
      }
    } else {
      // Count remaining unread notifications
      const unreadCount = $('.notification-item.unread').length;
      if (unreadCount > 0) {
        notificationCount.text(unreadCount).show();
        if (notificationBadge.length) {
          notificationBadge.text(unreadCount + ' new').show();
        }
      } else {
        notificationCount.hide();
        if (notificationBadge.length) {
          notificationBadge.hide();
        }
      }
    }
    
    // Update title count
    updateTitleNotificationCount();
  }
  
  // Function to update title notification count
  function updateTitleNotificationCount() {
    const count = $('#notificationCount').text();
    if (count && parseInt(count) > 0) {
      document.title = `(${count}) Dashboard - Test Manager`;
    } else {
      document.title = 'Dashboard - Test Manager';
    }
  }
  
  // Function to show toast notification
  function showToast(title, message, type = 'info') {
    const toastId = 'toast-' + Date.now();
    const toastHtml = `
      <div id="${toastId}" class="toast show ${'toast-' + type}" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="toast-header">
          <strong class="me-auto">${title}</strong>
          <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
        <div class="toast-body">
          ${message}
        </div>
      </div>
    `;
    
    $('#toastContainer').append(toastHtml);
    
    // Auto remove toast after 5 seconds
    setTimeout(() => {
      $('#' + toastId).remove();
    }, 5000);
  }
  
  // Function to get time ago
  function getTimeAgo(dateString) {
    const date = new Date(dateString);
    const now = new Date();
    const seconds = Math.floor((now - date) / 1000);
    
    if (seconds < 60) return 'just now';
    if (seconds < 3600) {
      const mins = Math.floor(seconds / 60);
      return mins === 1 ? '1 min ago' : `${mins} mins ago`;
    }
    if (seconds < 86400) {
      const hours = Math.floor(seconds / 3600);
      return hours === 1 ? '1 hour ago' : `${hours} hours ago`;
    }
    if (seconds < 604800) {
      const days = Math.floor(seconds / 86400);
      return days === 1 ? 'yesterday' : `${days} days ago`;
    }
    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
  }
  
  <?php if ($role === 'super_admin' || $role === 'pm_manager'): ?>
    // Status Chart
    const statusCtx = document.getElementById('statusChart').getContext('2d');
    const statusChart = new Chart(statusCtx, {
      type: 'doughnut',
      data: {
        labels: ['Passed', 'Failed', 'Pending', 'Deferred'],
        datasets: [{
          data: [
            <?= $stats['passed_cases'] ?>,
            <?= $stats['failed_cases'] ?>,
            <?= $stats['pending_cases'] ?>,
            <?= $stats['deferred_cases'] ?>
          ],
          backgroundColor: ['#28a745', '#dc3545', '#ffc107', '#6c757d'],
          hoverBackgroundColor: ['#218838', '#c82333', '#e0a800', '#545b62'],
          borderWidth: 2,
          borderColor: '#fff'
        }],
      },
      options: {
        maintainAspectRatio: false,
        responsive: true,
        plugins: {
          legend: {
            position: 'bottom',
            labels: {
              padding: 20,
              usePointStyle: true,
              font: {
                size: 12
              }
            }
          },
          tooltip: {
            backgroundColor: '#fff',
            titleColor: '#273274',
            bodyColor: '#495057',
            borderColor: '#dee2e6',
            borderWidth: 1,
            padding: 12,
            callbacks: {
              label: function(context) {
                const label = context.label || '';
                const value = context.raw || 0;
                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                const percentage = Math.round((value / total) * 100);
                return `${label}: ${value} (${percentage}%)`;
              }
            }
          }
        },
        cutout: '65%',
      },
    });
    
    <?php if (!empty($project_names)): ?>
    // Project Chart
    const projectCtx = document.getElementById('projectChart').getContext('2d');
    const projectChart = new Chart(projectCtx, {
      type: 'bar',
      data: {
        labels: <?= json_encode($project_names) ?>,
        datasets: [{
          label: 'Test Cases',
          data: <?= json_encode($project_totals) ?>,
          backgroundColor: '#273274',
          hoverBackgroundColor: '#012169',
          borderRadius: 4,
          borderSkipped: false,
        }]
      },
      options: {
        maintainAspectRatio: false,
        responsive: true,
        scales: {
          x: {
            grid: {
              display: false,
              drawBorder: false
            },
            ticks: {
              font: {
                size: 12
              }
            }
          },
          y: {
            beginAtZero: true,
            grid: {
              color: '#e9ecef',
              drawBorder: false
            },
            ticks: {
              precision: 0,
              font: {
                size: 12
              }
            }
          }
        },
        plugins: {
          legend: {
            display: false
          },
          tooltip: {
            backgroundColor: '#fff',
            titleColor: '#273274',
            bodyColor: '#495057',
            borderColor: '#dee2e6',
            borderWidth: 1,
            padding: 12,
            callbacks: {
              label: function(context) {
                return `Test Cases: ${context.raw}`;
              }
            }
          }
        },
        onClick: function(evt, elements) {
          if (elements.length > 0) {
            const projectId = <?= json_encode($project_ids) ?>[elements[0].index];
            window.location.href = `TC_assigned_projects.php?id=${projectId}`;
          }
        }
      }
    });
    <?php endif; ?>
  <?php endif; ?>
  </script>
</body>
</html>