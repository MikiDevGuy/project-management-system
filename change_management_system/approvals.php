<?php
// approvals.php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

require_once 'db.php';

// Get user role
$user_role = strtolower($_SESSION['system_role'] ?? '');
$user_roles_with_access = ['admin', 'super_admin', 'pm_manager', 'pm_employee'];

// Check if user has access to approvals page
if (!in_array($user_role, $user_roles_with_access)) {
    header("Location: change_management.php");
    exit();
}

// Updated sendNotification function with metadata support - FIXED VERSION
function sendNotification($conn, $user_id, $title, $message, $type = 'info', $related_module = 'change_request', $related_id = null, $metadata = null) {
    // Convert metadata to JSON if it's an array
    $metadata_json = null;
    if ($metadata && is_array($metadata)) {
        $metadata_json = json_encode($metadata);
    }
    
    $query = "INSERT INTO notifications (user_id, title, message, type, related_module, related_id, metadata, is_read, created_at)
              VALUES (?, ?, ?, ?, ?, ?, ?, FALSE, NOW())";
    $stmt = $conn->prepare($query);
    if ($stmt) {
        // We have 7 parameters to bind, but SQL has 8 placeholders
        // The 8th placeholder (FALSE) is not a parameter, 9th (NOW()) is not a parameter
        // Parameters: user_id, title, message, type, related_module, related_id, metadata_json
        // That's 7 parameters: i (int), s (string), s (string), s (string), s (string), i (int), s (string)
        $stmt->bind_param("issssis", $user_id, $title, $message, $type, $related_module, $related_id, $metadata_json);
        $result = $stmt->execute();
        $stmt->close();
        return $result;
    }
    return false;
}

// Function to get all Super Admins
function getSuperAdmins($conn) {
    $query = "SELECT id FROM users WHERE system_role IN ('super_admin', 'admin')";
    $stmt = $conn->prepare($query);
    $super_admins = [];
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $super_admins[] = $row['id'];
        }
        $stmt->close();
    }
    return $super_admins;
}

// Function to get all PM Managers and PM Employees
function getAllPMUsers($conn, $exclude_user_id = null) {
    $query = "SELECT id FROM users WHERE system_role IN ('pm_manager', 'pm_employee')";
    if ($exclude_user_id) {
        $query .= " AND id != ?";
    }
    
    $stmt = $conn->prepare($query);
    $pm_users = [];
    if ($stmt) {
        if ($exclude_user_id) {
            $stmt->bind_param("i", $exclude_user_id);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $pm_users[] = $row['id'];
        }
        $stmt->close();
    }
    return $pm_users;
}

// Get pending change requests for approval (only for admins)
$pending_requests = [];
if (in_array($user_role, ['admin', 'super_admin'])) {
    $query = "SELECT cr.*, p.name as project_name, u.username as requester_name, u.system_role as requester_role, u.id as requester_id
              FROM change_requests cr
              JOIN projects p ON cr.project_id = p.id
              JOIN users u ON cr.requester_id = u.id
              WHERE cr.status = 'Open'
              ORDER BY cr.request_date DESC";
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        $pending_requests = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
}

// Get approved requests (for implementation start) - for all roles
$query = "SELECT cr.*, p.name as project_name, u.username as requester_name, u.system_role as requester_role, u.id as requester_id
          FROM change_requests cr
          JOIN projects p ON cr.project_id = p.id
          JOIN users u ON cr.requester_id = u.id
          WHERE cr.status = 'Approved'
          ORDER BY cr.request_date DESC";
$stmt = $conn->prepare($query);
if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    $approved_requests = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $approved_requests = [];
}

// Get in-progress requests (for completion) - for all roles
$query = "SELECT cr.*, p.name as project_name, u.username as requester_name, u.system_role as requester_role, u.id as requester_id
          FROM change_requests cr
          JOIN projects p ON cr.project_id = p.id
          JOIN users u ON cr.requester_id = u.id
          WHERE cr.status = 'In Progress'
          ORDER BY cr.request_date DESC";
$stmt = $conn->prepare($query);
if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    $in_progress_requests = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $in_progress_requests = [];
}

// Handle approval/rejection/implementation actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate required fields
    $action = $_POST['action'] ?? '';
    $change_request_id = $_POST['change_request_id'] ?? '';
    $comments = $_POST['comments'] ?? '';
    $user_id = $_SESSION['user_id'];
    $username = $_SESSION['username'] ?? 'User';
    
    // Get requester details for notifications
    $requester_query = "SELECT u.id as requester_id, u.username, u.system_role as requester_role
                       FROM change_requests cr
                       JOIN users u ON cr.requester_id = u.id
                       WHERE cr.change_request_id = ?";
    $requester_stmt = $conn->prepare($requester_query);
    $requester_id = null;
    $requester_role = null;
    $requester_name = null;
    
    if ($requester_stmt) {
        $requester_stmt->bind_param("i", $change_request_id);
        $requester_stmt->execute();
        $requester_result = $requester_stmt->get_result();
        if ($requester_row = $requester_result->fetch_assoc()) {
            $requester_id = $requester_row['requester_id'];
            $requester_role = $requester_row['requester_role'];
            $requester_name = $requester_row['username'];
        }
        $requester_stmt->close();
    }
    
    // Debug: Check what's being submitted
    error_log("Action: " . $action);
    error_log("Change Request ID: " . $change_request_id);
    error_log("Comments: " . $comments);
    
    // Validate required fields
    if (empty($action) || empty($change_request_id)) {
        $_SESSION['error_message'] = "Missing required fields. Please try again.";
        header("Location: approvals.php");
        exit();
    }
    
    // Validate action based on user role
    $valid_actions = [];
    if (in_array($user_role, ['admin', 'super_admin'])) {
        $valid_actions = ['approve', 'reject'];
    } else if (in_array($user_role, ['pm_manager', 'pm_employee'])) {
        $valid_actions = ['implement', 'terminated', 'implemented'];
    }
    
    if (!in_array($action, $valid_actions)) {
        $_SESSION['error_message'] = "You are not authorized to perform this action.";
        header("Location: approvals.php");
        exit();
    }
    
    // Validate change request ID
    if (!is_numeric($change_request_id)) {
        $_SESSION['error_message'] = "Invalid change request ID.";
        header("Location: approvals.php");
        exit();
    }
    
    // Map actions to statuses
    $status_map = [
        'approve' => 'Approved',
        'reject' => 'Rejected', 
        'implement' => 'In Progress',
        'terminated' => 'Terminated',
        'implemented' => 'Implemented'
    ];
    
    $new_status = $status_map[$action] ?? 'Open';
    
    // Update request status
    $query = "UPDATE change_requests SET status = ?, last_updated = NOW() WHERE change_request_id = ?";
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param("si", $new_status, $change_request_id);
        $update_result = $stmt->execute();
        $stmt->close();
        
        if (!$update_result) {
            $_SESSION['error_message'] = "Failed to update change request status.";
            header("Location: approvals.php");
            exit();
        }
    } else {
        $_SESSION['error_message'] = "Database error occurred.";
        header("Location: approvals.php");
        exit();
    }
    
    // approvals.php - Updated notification section (replace lines around where notifications are sent)

// Send notifications based on the workflow
if ($action === 'approve') {
    // When Super Admin approves a request
    if ($requester_id) {
        // Get current status before approval
        $status_query = "SELECT status FROM change_requests WHERE change_request_id = ?";
        $status_stmt = $conn->prepare($status_query);
        $old_status = 'Open';
        if ($status_stmt) {
            $status_stmt->bind_param("i", $change_request_id);
            $status_stmt->execute();
            $status_result = $status_stmt->get_result();
            if ($status_row = $status_result->fetch_assoc()) {
                $old_status = $status_row['status'];
            }
            $status_stmt->close();
        }
        
        // Notification 1: Notify the requester (PM Manager/PM Employee)
        $title = "Change Request Approved";
        $message = "Your change request #{$change_request_id} has been approved by the Super Admin. You can now proceed with implementation.";
        
        // Metadata for requester
        $requester_metadata = [
            'old_status' => $old_status,
            'new_status' => 'Approved',
            'action' => 'Approved',
            'performed_by' => $username,
            'performer_role' => $user_role,
            'change_request_id' => $change_request_id,
            'comments' => !empty($comments) ? substr($comments, 0, 200) : null,
            'change_title' => !empty($pending_requests) ? $pending_requests[0]['change_title'] : 'Change Request',
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        sendNotification($conn, $requester_id, $title, $message, 'success', 'change_request', $change_request_id, $requester_metadata);
        
        // Notification 2: Notify all PM Managers and PM Employees (except requester)
        $pm_users = getAllPMUsers($conn, $requester_id);
        $pm_title = "New Approved Change Request";
        $pm_message = "Change request #{$change_request_id} has been approved by the Super Admin and is ready for implementation.";
        
        // Metadata for PM users
        $pm_metadata = [
            'old_status' => $old_status,
            'new_status' => 'Approved',
            'action' => 'Approved',
            'performed_by' => $username,
            'performer_role' => $user_role,
            'change_request_id' => $change_request_id,
            'requester_name' => $requester_name,
            'requester_role' => $requester_role,
            'change_title' => !empty($pending_requests) ? $pending_requests[0]['change_title'] : 'Change Request',
            'priority' => !empty($pending_requests) ? $pending_requests[0]['priority'] : 'Medium',
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        foreach ($pm_users as $pm_user_id) {
            sendNotification($conn, $pm_user_id, $pm_title, $pm_message, 'info', 'change_request', $change_request_id, $pm_metadata);
        }
    }
} 
elseif ($action === 'implement') {
    // When PM Manager/PM Employee starts implementation
    $super_admins = getSuperAdmins($conn);
    
    // Get change request details for metadata
    $cr_query = "SELECT change_title, status, priority FROM change_requests WHERE change_request_id = ?";
    $cr_stmt = $conn->prepare($cr_query);
    $change_title = '';
    $old_status = '';
    $priority = '';
    if ($cr_stmt) {
        $cr_stmt->bind_param("i", $change_request_id);
        $cr_stmt->execute();
        $cr_result = $cr_stmt->get_result();
        if ($cr_row = $cr_result->fetch_assoc()) {
            $change_title = $cr_row['change_title'];
            $old_status = $cr_row['status'];
            $priority = $cr_row['priority'];
        }
        $cr_stmt->close();
    }
    
    $title = "Implementation Started";
    $message = "Implementation has started for change request #{$change_request_id} by {$username}";
    
    // Metadata for implementation start
    $implement_metadata = [
        'old_status' => $old_status,
        'new_status' => 'In Progress',
        'action' => 'Implementation Started',
        'performed_by' => $username,
        'performer_role' => $user_role,
        'change_request_id' => $change_request_id,
        'change_title' => $change_title,
        'requester_name' => $requester_name,
        'requester_role' => $requester_role,
        'priority' => $priority,
        'comments' => !empty($comments) ? substr($comments, 0, 200) : null,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    foreach ($super_admins as $admin_id) {
        sendNotification($conn, $admin_id, $title, $message, 'warning', 'change_request', $change_request_id, $implement_metadata);
    }
}
elseif ($action === 'implemented') {
    // When PM Manager/PM Employee marks as implemented
    $super_admins = getSuperAdmins($conn);
    
    // Get change request details for metadata
    $cr_query = "SELECT change_title, status, priority FROM change_requests WHERE change_request_id = ?";
    $cr_stmt = $conn->prepare($cr_query);
    $change_title = '';
    $old_status = '';
    $priority = '';
    if ($cr_stmt) {
        $cr_stmt->bind_param("i", $change_request_id);
        $cr_stmt->execute();
        $cr_result = $cr_stmt->get_result();
        if ($cr_row = $cr_result->fetch_assoc()) {
            $change_title = $cr_row['change_title'];
            $old_status = $cr_row['status'];
            $priority = $cr_row['priority'];
        }
        $cr_stmt->close();
    }
    
    $title = "Implementation Completed";
    $message = "Implementation has been completed for change request #{$change_request_id} by {$username}";
    
    // Metadata for implementation completion
    $completed_metadata = [
        'old_status' => $old_status,
        'new_status' => 'Implemented',
        'action' => 'Implemented',
        'performed_by' => $username,
        'performer_role' => $user_role,
        'change_request_id' => $change_request_id,
        'change_title' => $change_title,
        'requester_name' => $requester_name,
        'requester_role' => $requester_role,
        'priority' => $priority,
        'comments' => !empty($comments) ? substr($comments, 0, 200) : null,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    foreach ($super_admins as $admin_id) {
        sendNotification($conn, $admin_id, $title, $message, 'success', 'change_request', $change_request_id, $completed_metadata);
    }
    
    // Also notify the original requester if different from current user
    if ($requester_id && $requester_id != $_SESSION['user_id']) {
        $requester_title = "Change Request Implemented";
        $requester_message = "Your change request #{$change_request_id} has been marked as implemented.";
        
        // Metadata for requester notification
        $requester_complete_metadata = [
            'old_status' => $old_status,
            'new_status' => 'Implemented',
            'action' => 'Implemented',
            'performed_by' => $username,
            'performer_role' => $user_role,
            'change_request_id' => $change_request_id,
            'change_title' => $change_title,
            'priority' => $priority,
            'comments' => !empty($comments) ? substr($comments, 0, 200) : null,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        sendNotification($conn, $requester_id, $requester_title, $requester_message, 'success', 'change_request', $change_request_id, $requester_complete_metadata);
    }
}
elseif ($action === 'reject') {
    // When Super Admin rejects a request
    if ($requester_id) {
        // Get current status before rejection
        $status_query = "SELECT status FROM change_requests WHERE change_request_id = ?";
        $status_stmt = $conn->prepare($status_query);
        $old_status = 'Open';
        if ($status_stmt) {
            $status_stmt->bind_param("i", $change_request_id);
            $status_stmt->execute();
            $status_result = $status_stmt->get_result();
            if ($status_row = $status_result->fetch_assoc()) {
                $old_status = $status_row['status'];
            }
            $status_stmt->close();
        }
        
        $title = "Change Request Rejected";
        $message = "Your change request #{$change_request_id} has been rejected by the Super Admin. Please check comments for details.";
        
        // Metadata for rejection
        $reject_metadata = [
            'old_status' => $old_status,
            'new_status' => 'Rejected',
            'action' => 'Rejected',
            'performed_by' => $username,
            'performer_role' => $user_role,
            'change_request_id' => $change_request_id,
            'change_title' => !empty($pending_requests) ? $pending_requests[0]['change_title'] : 'Change Request',
            'priority' => !empty($pending_requests) ? $pending_requests[0]['priority'] : 'Medium',
            'comments' => !empty($comments) ? substr($comments, 0, 200) : null,
            'timestamp' => date('Y-m-d H:i:s'),
            'reason' => !empty($comments) ? 'See comments for details' : 'No reason provided'
        ];
        
        sendNotification($conn, $requester_id, $title, $message, 'danger', 'change_request', $change_request_id, $reject_metadata);
    }
}
elseif ($action === 'terminated') {
    // When PM terminates a request
    $super_admins = getSuperAdmins($conn);
    
    // Get change request details for metadata
    $cr_query = "SELECT change_title, status, priority FROM change_requests WHERE change_request_id = ?";
    $cr_stmt = $conn->prepare($cr_query);
    $change_title = '';
    $old_status = '';
    $priority = '';
    if ($cr_stmt) {
        $cr_stmt->bind_param("i", $change_request_id);
        $cr_stmt->execute();
        $cr_result = $cr_stmt->get_result();
        if ($cr_row = $cr_result->fetch_assoc()) {
            $change_title = $cr_row['change_title'];
            $old_status = $cr_row['status'];
            $priority = $cr_row['priority'];
        }
        $cr_stmt->close();
    }
    
    $title = "Change Request Terminated";
    $message = "Change request #{$change_request_id} has been terminated by {$username}";
    
    // Metadata for termination
    $terminated_metadata = [
        'old_status' => $old_status,
        'new_status' => 'Terminated',
        'action' => 'Terminated',
        'performed_by' => $username,
        'performer_role' => $user_role,
        'change_request_id' => $change_request_id,
        'change_title' => $change_title,
        'requester_name' => $requester_name,
        'requester_role' => $requester_role,
        'priority' => $priority,
        'comments' => !empty($comments) ? substr($comments, 0, 200) : null,
        'timestamp' => date('Y-m-d H:i:s'),
        'reason' => !empty($comments) ? 'See comments for details' : 'No reason provided'
    ];
    
    foreach ($super_admins as $admin_id) {
        sendNotification($conn, $admin_id, $title, $message, 'danger', 'change_request', $change_request_id, $terminated_metadata);
    }
    
    // Also notify the original requester
    if ($requester_id && $requester_id != $_SESSION['user_id']) {
        $requester_title = "Change Request Terminated";
        $requester_message = "Your change request #{$change_request_id} has been terminated.";
        
        sendNotification($conn, $requester_id, $requester_title, $requester_message, 'danger', 'change_request', $change_request_id, $terminated_metadata);
    }
}

// Also send comment notifications with metadata
if (!empty($comments) && $action !== 'reject' && $requester_id && $action !== 'terminated') {
    $notify_title = "New Comment on Change Request";
    $notify_message = "A new comment has been added to your change request #{$change_request_id}";
    
    // Get change request details for comment metadata
    $cr_query = "SELECT change_title, status, priority FROM change_requests WHERE change_request_id = ?";
    $cr_stmt = $conn->prepare($cr_query);
    $change_title = '';
    $current_status = '';
    $priority = '';
    if ($cr_stmt) {
        $cr_stmt->bind_param("i", $change_request_id);
        $cr_stmt->execute();
        $cr_result = $cr_stmt->get_result();
        if ($cr_row = $cr_result->fetch_assoc()) {
            $change_title = $cr_row['change_title'];
            $current_status = $cr_row['status'];
            $priority = $cr_row['priority'];
        }
        $cr_stmt->close();
    }
    
    // Metadata for comment notification
    $comment_metadata = [
        'action' => 'Comment Added',
        'performed_by' => $username,
        'performer_role' => $user_role,
        'change_request_id' => $change_request_id,
        'change_title' => $change_title,
        'current_status' => $current_status,
        'priority' => $priority,
        'comments' => substr($comments, 0, 200),
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    sendNotification($conn, $requester_id, $notify_title, $notify_message, 'info', 'change_request', $change_request_id, $comment_metadata);
}
    
    // Log the action
    $log_action_map = [
        'approve' => 'Approved',
        'reject' => 'Rejected',
        'implement' => 'Implementation Started',
        'terminated' => 'Terminated',
        'implemented' => 'Implemented'
    ];
    
    $log_action = $log_action_map[$action] ?? 'Unknown Action';
    $log_details = $comments ? "With comments: " . substr($comments, 0, 200) : "No comments provided";
    
    $log_query = "INSERT INTO change_logs (change_request_id, user_id, action, details, log_date)
                  VALUES (?, ?, ?, ?, NOW())";
    $log_stmt = $conn->prepare($log_query);
    if ($log_stmt) {
        $log_stmt->bind_param("iiss", $change_request_id, $user_id, $log_action, $log_details);
        $log_stmt->execute();
        $log_stmt->close();
    }
    
    // Notify requester if comments were added (except for internal/rejection comments)
    if (!empty($comments) && $action !== 'reject' && $requester_id) {
        $notify_title = "New Comment on Change Request";
        $notify_message = "A new comment has been added to your change request #{$change_request_id}: " . substr($comments, 0, 100) . "...";
        sendNotification($conn, $requester_id, $notify_title, $notify_message, 'info', 'change_request', $change_request_id);
    }
    
    $_SESSION['success_message'] = "Change request has been {$log_action} successfully!";
    header("Location: approvals.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Approvals - Dashen Bank Change Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/particles.js@2.0.0/particles.min.js"></script>
    <style>
        :root {
            --dashen-primary: #273274;
            --dashen-secondary: #1e2559;
            --dashen-accent: #f58220;
            --dashen-success: #00d4aa;
            --dashen-warning: #ffb800;
            --dashen-danger: #ff4757;
            --dashen-info: #2e86de;
            --dashen-light: #f8fafc;
            --dashen-dark: #1e293b;
            --text-dark: #2c3e50;
            --text-light: #64748b;
            --border-color: #e2e8f0;
            --gradient-primary: linear-gradient(135deg, #273274 0%, #1e2559 100%);
            --gradient-success: linear-gradient(135deg, #00d4aa 0%, #00b894 100%);
            --gradient-warning: linear-gradient(135deg, #ffb800 0%, #f39c12 100%);
            --gradient-danger: linear-gradient(135deg, #ff4757 0%, #e74c3c 100%);
            --gradient-info: linear-gradient(135deg, #2e86de 0%, #3498db 100%);
            --shadow-soft: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-medium: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --shadow-large: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            --shadow-glow: 0 0 20px rgba(39, 50, 116, 0.15);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            min-height: 100vh;
            color: var(--text-dark);
            overflow-x: hidden;
        }

        #particles-js {
            position: fixed;
            width: 100%;
            height: 100%;
            top: 0;
            left: 0;
            z-index: -1;
        }
        
        .main-content {
            margin-left: 280px;
            padding: 20px;
            min-height: 100vh;
            transition: all 0.3s;
            position: relative;
            z-index: 1;
        }
        
        .sidebar.collapsed ~ .main-content {
            margin-left: 80px;
        }
        
        /* Enhanced Dashboard Header */
        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            background: white;
            padding: 2rem;
            border-radius: 1.5rem;
            box-shadow: var(--shadow-soft);
            border: 1px solid var(--border-color);
            position: relative;
            overflow: hidden;
        }
        
        .dashboard-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--gradient-primary);
        }
        
        .page-title {
            font-size: 2.5rem;
            font-weight: 700;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin: 0;
            position: relative;
        }
        
        .page-title::after {
            content: '';
            position: absolute;
            bottom: -10px;
            left: 0;
            width: 60px;
            height: 4px;
            background: var(--gradient-primary);
            border-radius: 2px;
        }
        
        /* Enhanced Tab Styles */
        .nav-pills {
            background: white;
            padding: 1rem;
            border-radius: 1.5rem;
            box-shadow: var(--shadow-soft);
            border: 1px solid var(--border-color);
            margin-bottom: 2rem;
        }
        
        .nav-pills .nav-link {
            color: var(--text-dark);
            font-weight: 600;
            padding: 1rem 2rem;
            border-radius: 1rem;
            margin-right: 0.5rem;
            border: 2px solid transparent;
            background: white;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .nav-pills .nav-link::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: var(--gradient-primary);
            transition: all 0.3s ease;
            z-index: -1;
        }
        
        .nav-pills .nav-link:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-medium);
            border-color: var(--dashen-primary);
        }
        
        .nav-pills .nav-link.active {
            background: var(--gradient-primary);
            border-color: var(--dashen-primary);
            color: white;
            transform: translateY(-2px);
            box-shadow: var(--shadow-glow);
        }
        
        .nav-pills .nav-link.active::before {
            left: 0;
        }
        
        .nav-pills .nav-link .badge {
            margin-left: 0.5rem;
            font-size: 0.75rem;
            padding: 0.35rem 0.65rem;
            border-radius: 1rem;
        }
        
        /* Ultra Modern Cards */
        .approval-card {
            background: white;
            border-radius: 1.5rem;
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow-soft);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            height: 100%;
            position: relative;
            overflow: hidden;
            backdrop-filter: blur(10px);
        }
        
        .approval-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--gradient-primary);
            transform: scaleX(0);
            transition: transform 0.3s ease;
        }
        
        .approval-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: var(--shadow-large);
        }
        
        .approval-card:hover::before {
            transform: scaleX(1);
        }
        
        .approval-card.pending:hover::before {
            background: var(--gradient-warning);
        }
        
        .approval-card.approved:hover::before {
            background: var(--gradient-success);
        }
        
        .approval-card.in-progress:hover::before {
            background: var(--gradient-info);
        }
        
        .card-header {
            background: transparent;
            border-bottom: 1px solid var(--border-color);
            padding: 1.5rem 2rem;
            position: relative;
        }
        
        .card-title {
            font-weight: 700;
            color: var(--text-dark);
            margin: 0;
            font-size: 1.25rem;
            line-height: 1.4;
        }
        
        .status-badge {
            padding: 0.75rem 1.25rem;
            border-radius: 2rem;
            font-weight: 600;
            font-size: 0.875rem;
            border: 2px solid transparent;
        }
        
        .priority-badge {
            padding: 0.5rem 1rem;
            border-radius: 1rem;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        /* Enhanced Card Body */
        .card-body {
            padding: 2rem;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .info-item {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }
        
        .info-label {
            font-size: 0.875rem;
            color: var(--text-light);
            font-weight: 500;
        }
        
        .info-value {
            font-size: 1rem;
            color: var(--text-dark);
            font-weight: 600;
        }
        
        /* Enhanced Comments Section */
        .comment-section {
            max-height: 300px;
            overflow-y: auto;
            border: 1px solid var(--border-color);
            border-radius: 1rem;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            background: var(--dashen-light);
        }
        
        .comment {
            padding: 1rem;
            margin-bottom: 1rem;
            border-radius: 0.75rem;
            background: white;
            border-left: 4px solid var(--dashen-primary);
            box-shadow: var(--shadow-soft);
            transition: all 0.3s ease;
        }
        
        .comment:hover {
            transform: translateX(5px);
            box-shadow: var(--shadow-medium);
        }
        
        .comment-header {
            font-size: 0.85rem;
            color: var(--text-light);
            margin-bottom: 0.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .internal-comment {
            background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
            border-left: 4px solid var(--dashen-warning);
        }
        
        /* Enhanced Form Styles */
        .form-label {
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 0.75rem;
        }
        
        .form-control, .form-select {
            border: 2px solid var(--border-color);
            border-radius: 0.75rem;
            padding: 0.875rem 1rem;
            font-size: 0.95rem;
            transition: all 0.3s ease;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--dashen-primary);
            box-shadow: 0 0 0 3px rgba(39, 50, 116, 0.1);
            transform: translateY(-2px);
        }
        
        /* Enhanced Button Styles */
        .btn {
            padding: 0.875rem 1.5rem;
            border-radius: 0.75rem;
            font-weight: 600;
            font-size: 0.95rem;
            border: 2px solid transparent;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: all 0.5s ease;
        }
        
        .btn:hover::before {
            left: 100%;
        }
        
        .btn-primary {
            background: var(--gradient-primary);
            border-color: var(--dashen-primary);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-glow);
        }
        
        .btn-success {
            background: var(--gradient-success);
            border-color: var(--dashen-success);
        }
        
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 0 20px rgba(0, 212, 170, 0.3);
        }
        
        .btn-danger {
            background: var(--gradient-danger);
            border-color: var(--dashen-danger);
        }
        
        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 0 20px rgba(255, 71, 87, 0.3);
        }
        
        .btn-warning {
            background: var(--gradient-warning);
            border-color: var(--dashen-warning);
            color: white;
        }
        
        /* Action Buttons Grid */
        .action-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-top: 1.5rem;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            background: white;
            border-radius: 1.5rem;
            box-shadow: var(--shadow-soft);
            border: 2px dashed var(--border-color);
        }
        
        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1.5rem;
            opacity: 0.5;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        /* Alert Styles */
        .alert {
            border-radius: 1rem;
            border: none;
            box-shadow: var(--shadow-soft);
            margin-bottom: 2rem;
        }
        
        .alert-success {
            background: var(--gradient-success);
            color: white;
        }
        
        .alert-danger {
            background: var(--gradient-danger);
            color: white;
        }
        
        /* Animation Classes */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .fade-in-up {
            animation: fadeInUp 0.6s ease-out;
        }
        
        @keyframes pulseGlow {
            0%, 100% {
                box-shadow: var(--shadow-medium);
            }
            50% {
                box-shadow: 0 0 30px rgba(39, 50, 116, 0.3);
            }
        }
        
        .pulse-glow {
            animation: pulseGlow 2s infinite;
        }
        
        /* Mobile Responsive */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            
            .sidebar.mobile-open {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
                padding: 1rem;
            }
            
            .mobile-menu-btn {
                display: block;
            }
            
            .nav-pills .nav-link {
                padding: 0.75rem 1rem;
                font-size: 0.875rem;
                margin-bottom: 0.5rem;
            }
            
            .action-grid {
                grid-template-columns: 1fr;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .dashboard-header {
                padding: 1.5rem;
            }
            
            .page-title {
                font-size: 2rem;
            }
        }
        
        .mobile-menu-btn {
            display: none;
            background: var(--gradient-primary);
            border: none;
            color: white;
            padding: 0.75rem;
            border-radius: 0.75rem;
            margin-bottom: 1rem;
            font-weight: 600;
            box-shadow: var(--shadow-medium);
        }
        
        @media (max-width: 768px) {
            .mobile-menu-btn {
                display: block;
            }
        }
        
        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 6px;
        }
        
        ::-webkit-scrollbar-track {
            background: var(--dashen-light);
            border-radius: 3px;
        }
        
        ::-webkit-scrollbar-thumb {
            background: var(--dashen-primary);
            border-radius: 3px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: var(--dashen-secondary);
        }
        
        /* Loading spinner */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.8);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 9999;
        }
        
        .spinner {
            width: 50px;
            height: 50px;
            border: 5px solid #f3f3f3;
            border-top: 5px solid var(--dashen-primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Toast notification */
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
        }
        
        .toast {
            background: white;
            border-radius: 0.75rem;
            padding: 1rem;
            margin-bottom: 1rem;
            box-shadow: var(--shadow-large);
            border-left: 4px solid;
            animation: slideInRight 0.3s ease;
        }
        
        .toast-success { border-left-color: var(--dashen-success); }
        .toast-error { border-left-color: var(--dashen-danger); }
        .toast-warning { border-left-color: var(--dashen-warning); }
        .toast-info { border-left-color: var(--dashen-primary); }
        
        @keyframes slideInRight {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
    </style>
</head>
<body>
    <!-- Particles Background -->
    <div id="particles-js"></div>
    
    <!-- Loading overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner"></div>
    </div>
    
    <!-- Toast container -->
    <div class="toast-container" id="toastContainer"></div>
    
    <!-- Include Sidebar -->
    <?php 
    $_SESSION['current_page'] = 'approvals.php';
    include 'sidebar.php'; 
    ?>
    
    <!-- Main Content -->
    <div class="main-content">
        <!-- Mobile Menu Button -->
        <button class="mobile-menu-btn" id="mobileMenuBtn">
            <i class="fas fa-bars me-2"></i> Menu
        </button>
        
        <!-- Success/Error Messages -->
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i>
                <?php echo $_SESSION['success_message']; ?>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?php echo $_SESSION['error_message']; ?>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>
        
        <!-- Enhanced Dashboard Header -->
        <div class="dashboard-header fade-in-up">
            <div>
                <h1 class="page-title">
                    <i class="fas fa-shield-check me-3"></i>
                    <?php 
                    if (in_array($user_role, ['admin', 'super_admin'])) {
                        echo "Approval Center";
                    } else if (in_array($user_role, ['pm_manager', 'pm_employee'])) {
                        echo "Implementation Center";
                    } else {
                        echo "Approvals & Implementation";
                    }
                    ?>
                </h1>
                <p class="welcome-text text-muted mt-2">
                    <i class="fas fa-star text-warning me-1"></i>
                    <?php 
                    if (in_array($user_role, ['admin', 'super_admin'])) {
                        echo "Review and approve pending change requests";
                    } else if (in_array($user_role, ['pm_manager', 'pm_employee'])) {
                        echo "Manage implementation of approved change requests";
                    } else {
                        echo "Review, approve, and manage change requests with precision";
                    }
                    ?>
                </p>
            </div>
            <div class="text-end">
                <div class="stat-badge bg-light rounded-pill px-3 py-2">
                    <small class="text-muted">Total Actions Today</small>
                    <div class="h4 mb-0 text-primary fw-bold">
                        <?php 
                        if (in_array($user_role, ['admin', 'super_admin'])) {
                            echo count($pending_requests);
                        } else {
                            echo count($approved_requests) + count($in_progress_requests);
                        }
                        ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Enhanced Approval Tabs -->
        <ul class="nav nav-pills mb-4" id="approvalTabs" role="tablist">
            <?php if (in_array($user_role, ['admin', 'super_admin'])): ?>
            <li class="nav-item" role="presentation">
                <button class="nav-link active pulse-glow" id="pending-tab" data-bs-toggle="pill" data-bs-target="#pending" type="button" role="tab">
                    <i class="fas fa-clock me-2"></i>
                    Pending Approval 
                    <span class="badge bg-danger ms-2"><?php echo count($pending_requests); ?></span>
                </button>
            </li>
            <?php endif; ?>
            
            <?php if (in_array($user_role, ['pm_manager', 'pm_employee'])): ?>
            <li class="nav-item" role="presentation">
                <button class="nav-link <?php echo in_array($user_role, ['admin', 'super_admin']) ? '' : 'active'; ?>" id="approved-tab" data-bs-toggle="pill" data-bs-target="#approved" type="button" role="tab">
                    <i class="fas fa-play-circle me-2"></i>
                    Ready for Implementation 
                    <span class="badge bg-success ms-2"><?php echo count($approved_requests); ?></span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="in-progress-tab" data-bs-toggle="pill" data-bs-target="#in-progress" type="button" role="tab">
                    <i class="fas fa-sync-alt me-2"></i>
                    In Progress 
                    <span class="badge bg-warning ms-2"><?php echo count($in_progress_requests); ?></span>
                </button>
            </li>
            <?php endif; ?>
        </ul>
        
        <div class="tab-content" id="approvalTabsContent">
            <!-- Pending Approval Tab (Only for Admins) -->
            <?php if (in_array($user_role, ['admin', 'super_admin'])): ?>
            <div class="tab-pane fade show active" id="pending" role="tabpanel">
                <?php if (count($pending_requests) > 0): ?>
                    <div class="row g-4 justify-content-center">
                        <?php foreach ($pending_requests as $request): 
                            $comment_query = "SELECT c.*, u.username
                                            FROM change_request_comments c
                                            JOIN users u ON c.user_id = u.id
                                            WHERE c.change_request_id = ?
                                            ORDER BY c.comment_date DESC";
                            $comment_stmt = $conn->prepare($comment_query);
                            if ($comment_stmt) {
                                $comment_stmt->bind_param("i", $request['change_request_id']);
                                $comment_stmt->execute();
                                $result = $comment_stmt->get_result();
                                $comments = $result->fetch_all(MYSQLI_ASSOC);
                                $comment_stmt->close();
                            } else {
                                $comments = [];
                            }
                        ?>
                            <div class="col-xl-6 col-lg-8 col-md-10">
                                <div class="approval-card pending fade-in-up">
                                    <div class="card-header d-flex justify-content-between align-items-center">
                                        <h5 class="card-title">
                                            <i class="fas fa-file-alt text-warning me-2"></i>
                                            <?php echo htmlspecialchars($request['change_title']); ?>
                                        </h5>
                                        <span class="status-badge bg-warning text-dark">
                                            <i class="fas fa-clock me-1"></i>
                                            Pending
                                        </span>
                                    </div>
                                    <div class="card-body">
                                        <div class="info-grid">
                                            <div class="info-item">
                                                <span class="info-label">Project</span>
                                                <span class="info-value">
                                                    <i class="fas fa-project-diagram text-primary me-2"></i>
                                                    <?php echo htmlspecialchars($request['project_name']); ?>
                                                </span>
                                            </div>
                                            <div class="info-item">
                                                <span class="info-label">Requester</span>
                                                <span class="info-value">
                                                    <i class="fas fa-user text-info me-2"></i>
                                                    <?php echo htmlspecialchars($request['requester_name']); ?>
                                                    <span class="badge bg-secondary ms-2"><?php echo ucfirst($request['requester_role']); ?></span>
                                                </span>
                                            </div>
                                            <div class="info-item">
                                                <span class="info-label">Priority</span>
                                                <span class="priority-badge 
                                                    <?php 
                                                    switch($request['priority']) {
                                                        case 'High': echo 'bg-danger text-white'; break;
                                                        case 'Medium': echo 'bg-warning text-dark'; break;
                                                        case 'Low': echo 'bg-secondary text-white'; break;
                                                        case 'Urgent': echo 'bg-danger text-white pulse-glow'; break;
                                                        default: echo 'bg-secondary text-white';
                                                    }
                                                    ?>
                                                ">
                                                    <i class="fas fa-flag me-1"></i>
                                                    <?php echo htmlspecialchars($request['priority']); ?>
                                                </span>
                                            </div>
                                            <div class="info-item">
                                                <span class="info-label">Submitted</span>
                                                <span class="info-value">
                                                    <i class="far fa-calendar text-muted me-2"></i>
                                                    <?php echo date('M j, Y', strtotime($request['request_date'])); ?>
                                                </span>
                                            </div>
                                        </div>
                                        
                                        <!-- Comments Section -->
                                        <?php if (!empty($comments)): ?>
                                            <div class="comment-section">
                                                <h6 class="text-primary mb-3">
                                                    <i class="fas fa-comments me-2"></i>
                                                    Previous Comments
                                                </h6>
                                                <?php foreach ($comments as $comment): ?>
                                                    <div class="comment <?php echo $comment['is_internal'] ? 'internal-comment' : ''; ?>">
                                                        <div class="comment-header">
                                                            <strong>
                                                                <i class="fas fa-user-circle me-1"></i>
                                                                <?php echo htmlspecialchars($comment['username']); ?>
                                                            </strong> 
                                                            <span>
                                                                <i class="far fa-clock me-1"></i>
                                                                <?php echo date('M j, Y g:i A', strtotime($comment['comment_date'])); ?>
                                                            </span>
                                                            <?php if ($comment['is_internal']): ?>
                                                                <span class="badge bg-warning">
                                                                    <i class="fas fa-lock me-1"></i>
                                                                    Internal
                                                                </span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="comment-text"><?php echo nl2br(htmlspecialchars($comment['comment_text'])); ?></div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <form method="POST" class="mt-4" onsubmit="return handleFormSubmission(this, event)">
                                            <input type="hidden" name="change_request_id" value="<?php echo $request['change_request_id']; ?>">
                                            <input type="hidden" name="action" id="action_<?php echo $request['change_request_id']; ?>">
                                            <div class="mb-3">
                                                <label for="comments_<?php echo $request['change_request_id']; ?>" class="form-label">
                                                    <i class="fas fa-edit me-2"></i>
                                                    Review Comments
                                                </label>
                                                <textarea class="form-control" id="comments_<?php echo $request['change_request_id']; ?>" name="comments" rows="3" placeholder="Share your feedback and decision rationale..."></textarea>
                                                <div class="form-text">
                                                    <i class="fas fa-info-circle me-1"></i>
                                                    Comments will be visible to the requester and other approvers
                                                </div>
                                            </div>
                                            <div class="action-grid">
                                                <button type="button" onclick="setActionAndSubmit(this, 'approve')" class="btn btn-success">
                                                    <i class="fas fa-check-circle me-2"></i>
                                                    Approve Request
                                                </button>
                                                <button type="button" onclick="setActionAndSubmit(this, 'reject')" class="btn btn-danger">
                                                    <i class="fas fa-times-circle me-2"></i>
                                                    Reject Request
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state fade-in-up">
                        <i class="fas fa-check-circle"></i>
                        <h4 class="text-muted mb-3">All Caught Up!</h4>
                        <p class="text-muted mb-4">No pending approvals requiring your attention at this moment.</p>
                        <div class="text-success">
                            <i class="fas fa-trophy me-2"></i>
                            You're doing great! Keep up the excellent work.
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <!-- Approved/Implementation Tab (For PM roles) -->
            <?php if (in_array($user_role, ['pm_manager', 'pm_employee'])): ?>
            <div class="tab-pane fade <?php echo in_array($user_role, ['admin', 'super_admin']) ? '' : 'show active'; ?>" id="approved" role="tabpanel">
                <?php if (count($approved_requests) > 0): ?>
                    <div class="row g-4 justify-content-center">
                        <?php foreach ($approved_requests as $request):
                            $comment_query = "SELECT c.*, u.username
                                            FROM change_request_comments c
                                            JOIN users u ON c.user_id = u.id
                                            WHERE c.change_request_id = ?
                                            ORDER BY c.comment_date DESC";
                            $comment_stmt = $conn->prepare($comment_query);
                            if ($comment_stmt) {
                                $comment_stmt->bind_param("i", $request['change_request_id']);
                                $comment_stmt->execute();
                                $result = $comment_stmt->get_result();
                                $comments = $result->fetch_all(MYSQLI_ASSOC);
                                $comment_stmt->close();
                            } else {
                                $comments = [];
                            }
                        ?>
                            <div class="col-xl-6 col-lg-8 col-md-10">
                                <div class="approval-card approved fade-in-up">
                                    <div class="card-header d-flex justify-content-between align-items-center">
                                        <h5 class="card-title">
                                            <i class="fas fa-check-circle text-success me-2"></i>
                                            <?php echo htmlspecialchars($request['change_title']); ?>
                                        </h5>
                                        <span class="status-badge bg-success text-white">
                                            <i class="fas fa-check me-1"></i>
                                            Approved
                                        </span>
                                    </div>
                                    <div class="card-body">
                                        <div class="info-grid">
                                            <div class="info-item">
                                                <span class="info-label">Project</span>
                                                <span class="info-value">
                                                    <i class="fas fa-project-diagram text-primary me-2"></i>
                                                    <?php echo htmlspecialchars($request['project_name']); ?>
                                                </span>
                                            </div>
                                            <div class="info-item">
                                                <span class="info-label">Requester</span>
                                                <span class="info-value">
                                                    <i class="fas fa-user text-info me-2"></i>
                                                    <?php echo htmlspecialchars($request['requester_name']); ?>
                                                    <span class="badge bg-secondary ms-2"><?php echo ucfirst($request['requester_role']); ?></span>
                                                </span>
                                            </div>
                                            <div class="info-item">
                                                <span class="info-label">Priority</span>
                                                <span class="priority-badge 
                                                    <?php 
                                                    switch($request['priority']) {
                                                        case 'High': echo 'bg-danger text-white'; break;
                                                        case 'Medium': echo 'bg-warning text-dark'; break;
                                                        case 'Low': echo 'bg-secondary text-white'; break;
                                                        case 'Urgent': echo 'bg-danger text-white pulse-glow'; break;
                                                        default: echo 'bg-secondary text-white';
                                                    }
                                                    ?>
                                                ">
                                                    <i class="fas fa-flag me-1"></i>
                                                    <?php echo htmlspecialchars($request['priority']); ?>
                                                </span>
                                            </div>
                                            <div class="info-item">
                                                <span class="info-label">Approved On</span>
                                                <span class="info-value">
                                                    <i class="far fa-calendar-check text-success me-2"></i>
                                                    <?php echo date('M j, Y', strtotime($request['last_updated'])); ?>
                                                </span>
                                            </div>
                                        </div>

                                        <!-- Comments Section -->
                                        <?php if (!empty($comments)): ?>
                                            <div class="comment-section">
                                                <h6 class="text-primary mb-3">
                                                    <i class="fas fa-comments me-2"></i>
                                                    Review Comments
                                                </h6>
                                                <?php foreach ($comments as $comment): ?>
                                                    <div class="comment <?php echo $comment['is_internal'] ? 'internal-comment' : ''; ?>">
                                                        <div class="comment-header">
                                                            <strong>
                                                                <i class="fas fa-user-circle me-1"></i>
                                                                <?php echo htmlspecialchars($comment['username']); ?>
                                                            </strong>
                                                            <span>
                                                                <i class="far fa-clock me-1"></i>
                                                                <?php echo date('M j, Y g:i A', strtotime($comment['comment_date'])); ?>
                                                            </span>
                                                            <?php if ($comment['is_internal']): ?>
                                                                <span class="badge bg-warning">
                                                                    <i class="fas fa-lock me-1"></i>
                                                                    Internal
                                                                </span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="comment-text"><?php echo nl2br(htmlspecialchars($comment['comment_text'])); ?></div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>

                                        <form method="POST" class="mt-4" onsubmit="return handleFormSubmission(this, event)">
                                            <input type="hidden" name="change_request_id" value="<?php echo $request['change_request_id']; ?>">
                                            <input type="hidden" name="action" id="action_impl_<?php echo $request['change_request_id']; ?>">
                                            <div class="mb-3">
                                                <label for="impl_comments_<?php echo $request['change_request_id']; ?>" class="form-label">
                                                    <i class="fas fa-rocket me-2"></i>
                                                    Implementation Notes
                                                </label>
                                                <textarea class="form-control" id="impl_comments_<?php echo $request['change_request_id']; ?>" name="comments" rows="2" placeholder="Add implementation instructions or notes..."></textarea>
                                            </div>
                                            <div class="action-grid">
                                                <button type="button" onclick="setActionAndSubmit(this, 'implement')" class="btn btn-primary">
                                                    <i class="fas fa-play-circle me-2"></i>
                                                    Start Implementation
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state fade-in-up">
                        <i class="fas fa-hourglass-half"></i>
                        <h4 class="text-muted mb-3">No Approved Requests</h4>
                        <p class="text-muted">There are no approved requests ready for implementation at this time.</p>
                    </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- In Progress Tab (For PM roles) -->
            <?php if (in_array($user_role, ['pm_manager', 'pm_employee'])): ?>
            <div class="tab-pane fade" id="in-progress" role="tabpanel">
                <?php if (count($in_progress_requests) > 0): ?>
                    <div class="row g-4 justify-content-center">
                        <?php foreach ($in_progress_requests as $request):
                            $comment_query = "SELECT c.*, u.username
                                            FROM change_request_comments c
                                            JOIN users u ON c.user_id = u.id
                                            WHERE c.change_request_id = ?
                                            ORDER BY c.comment_date DESC";
                            $comment_stmt = $conn->prepare($comment_query);
                            if ($comment_stmt) {
                                $comment_stmt->bind_param("i", $request['change_request_id']);
                                $comment_stmt->execute();
                                $result = $comment_stmt->get_result();
                                $comments = $result->fetch_all(MYSQLI_ASSOC);
                                $comment_stmt->close();
                            } else {
                                $comments = [];
                            }
                        ?>
                            <div class="col-xl-6 col-lg-8 col-md-10">
                                <div class="approval-card in-progress fade-in-up">
                                    <div class="card-header d-flex justify-content-between align-items-center">
                                        <h5 class="card-title">
                                            <i class="fas fa-sync-alt text-info me-2"></i>
                                            <?php echo htmlspecialchars($request['change_title']); ?>
                                        </h5>
                                        <span class="status-badge bg-info text-white">
                                            <i class="fas fa-sync me-1"></i>
                                            In Progress
                                        </span>
                                    </div>
                                    <div class="card-body">
                                        <div class="info-grid">
                                            <div class="info-item">
                                                <span class="info-label">Project</span>
                                                <span class="info-value">
                                                    <i class="fas fa-project-diagram text-primary me-2"></i>
                                                    <?php echo htmlspecialchars($request['project_name']); ?>
                                                </span>
                                            </div>
                                            <div class="info-item">
                                                <span class="info-label">Requester</span>
                                                <span class="info-value">
                                                    <i class="fas fa-user text-info me-2"></i>
                                                    <?php echo htmlspecialchars($request['requester_name']); ?>
                                                    <span class="badge bg-secondary ms-2"><?php echo ucfirst($request['requester_role']); ?></span>
                                                </span>
                                            </div>
                                            <div class="info-item">
                                                <span class="info-label">Priority</span>
                                                <span class="priority-badge 
                                                    <?php 
                                                    switch($request['priority']) {
                                                        case 'High': echo 'bg-danger text-white'; break;
                                                        case 'Medium': echo 'bg-warning text-dark'; break;
                                                        case 'Low': echo 'bg-secondary text-white'; break;
                                                        case 'Urgent': echo 'bg-danger text-white pulse-glow'; break;
                                                        default: echo 'bg-secondary text-white';
                                                    }
                                                    ?>
                                                ">
                                                    <i class="fas fa-flag me-1"></i>
                                                    <?php echo htmlspecialchars($request['priority']); ?>
                                                </span>
                                            </div>
                                            <div class="info-item">
                                                <span class="info-label">Started On</span>
                                                <span class="info-value">
                                                    <i class="far fa-calendar text-info me-2"></i>
                                                    <?php echo date('M j, Y', strtotime($request['last_updated'])); ?>
                                                </span>
                                            </div>
                                        </div>

                                        <!-- Comments Section -->
                                        <?php if (!empty($comments)): ?>
                                            <div class="comment-section">
                                                <h6 class="text-primary mb-3">
                                                    <i class="fas fa-comments me-2"></i>
                                                    Implementation Notes
                                                </h6>
                                                <?php foreach ($comments as $comment): ?>
                                                    <div class="comment <?php echo $comment['is_internal'] ? 'internal-comment' : ''; ?>">
                                                        <div class="comment-header">
                                                            <strong>
                                                                <i class="fas fa-user-circle me-1"></i>
                                                                <?php echo htmlspecialchars($comment['username']); ?>
                                                            </strong>
                                                            <span>
                                                                <i class="far fa-clock me-1"></i>
                                                                <?php echo date('M j, Y g:i A', strtotime($comment['comment_date'])); ?>
                                                            </span>
                                                            <?php if ($comment['is_internal']): ?>
                                                                <span class="badge bg-warning">
                                                                    <i class="fas fa-lock me-1"></i>
                                                                    Internal
                                                                </span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="comment-text"><?php echo nl2br(htmlspecialchars($comment['comment_text'])); ?></div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>

                                        <form method="POST" class="mt-4" onsubmit="return handleFormSubmission(this, event)">
                                            <input type="hidden" name="change_request_id" value="<?php echo $request['change_request_id']; ?>">
                                            <input type="hidden" name="action" id="action_final_<?php echo $request['change_request_id']; ?>">
                                            <div class="mb-3">
                                                <label for="final_comments_<?php echo $request['change_request_id']; ?>" class="form-label">
                                                    <i class="fas fa-flag-checkered me-2"></i>
                                                    Final Notes
                                                </label>
                                                <textarea class="form-control" id="final_comments_<?php echo $request['change_request_id']; ?>" name="comments" rows="2" placeholder="Add completion notes or final remarks..."></textarea>
                                            </div>
                                            <div class="action-grid">
                                                <button type="button" onclick="setActionAndSubmit(this, 'implemented')" class="btn btn-success">
                                                    <i class="fas fa-check-circle me-2"></i>
                                                    Mark as Implemented
                                                </button>
                                                <button type="button" onclick="setActionAndSubmit(this, 'terminated')" class="btn btn-danger">
                                                    <i class="fas fa-times-circle me-2"></i>
                                                    Terminate Request
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state fade-in-up">
                        <i class="fas fa-check-double"></i>
                        <h4 class="text-muted mb-3">All Implementations Complete</h4>
                        <p class="text-muted">No requests are currently in progress. Great job managing the workflow!</p>
                    </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Particles.js Configuration
    particlesJS('particles-js', {
        particles: {
            number: { value: 80, density: { enable: true, value_area: 800 } },
            color: { value: "#273274" },
            shape: { type: "circle" },
            opacity: { value: 0.1, random: true },
            size: { value: 3, random: true },
            line_linked: {
                enable: true,
                distance: 150,
                color: "#273274",
                opacity: 0.1,
                width: 1
            },
            move: {
                enable: true,
                speed: 2,
                direction: "none",
                random: true,
                straight: false,
                out_mode: "out",
                bounce: false
            }
        },
        interactivity: {
            detect_on: "canvas",
            events: {
                onhover: { enable: true, mode: "repulse" },
                onclick: { enable: true, mode: "push" },
                resize: true
            }
        },
        retina_detect: true
    });

    // Show loading spinner
    function showLoading() {
        document.getElementById('loadingOverlay').style.display = 'flex';
    }

    // Hide loading spinner
    function hideLoading() {
        document.getElementById('loadingOverlay').style.display = 'none';
    }

    // Show toast notification
    function showToast(message, type = 'info') {
        const toastContainer = document.getElementById('toastContainer');
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.innerHTML = `
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <strong>${type.charAt(0).toUpperCase() + type.slice(1)}</strong>
                    <p class="mb-0">${message}</p>
                </div>
                <button type="button" class="btn-close btn-close-sm" onclick="this.parentElement.parentElement.remove()"></button>
            </div>
        `;
        
        toastContainer.appendChild(toast);
        
        // Auto remove after 5 seconds
        setTimeout(() => {
            if (toast.parentNode) {
                toast.remove();
            }
        }, 5000);
    }

    // Set action and submit form
    function setActionAndSubmit(button, action) {
        const form = button.closest('form');
        const actionInput = form.querySelector('input[name="action"]');
        const changeRequestIdInput = form.querySelector('input[name="change_request_id"]');
        
        if (!actionInput || !changeRequestIdInput) {
            showToast('Form elements not found. Please refresh the page.', 'error');
            return false;
        }
        
        // Set the action value
        actionInput.value = action;
        
        // Validate required fields
        if (!changeRequestIdInput.value) {
            showToast('Change request ID is missing.', 'error');
            return false;
        }
        
        // Submit the form
        form.submit();
        return true;
    }

    // Handle form submission
    function handleFormSubmission(form, event) {
        event.preventDefault();
        
        const actionInput = form.querySelector('input[name="action"]');
        const changeRequestIdInput = form.querySelector('input[name="change_request_id"]');
        
        if (!actionInput || !actionInput.value) {
            showToast('Please select an action before submitting.', 'error');
            return false;
        }
        
        if (!changeRequestIdInput || !changeRequestIdInput.value) {
            showToast('Change request ID is missing.', 'error');
            return false;
        }
        
        // Show loading
        showLoading();
        
        // Get all form data
        const formData = new FormData(form);
        
        // Submit via fetch API for better user experience
        fetch('approvals.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            if (response.redirected) {
                // If server redirects, follow the redirect
                window.location.href = response.url;
            } else {
                return response.text();
            }
        })
        .then(data => {
            // If we get here, something went wrong
            hideLoading();
            showToast('Unexpected response from server. Please try again.', 'error');
        })
        .catch(error => {
            hideLoading();
            showToast('Network error. Please check your connection and try again.', 'error');
            console.error('Error:', error);
        });
        
        return false;
    }

    // Enhanced animations and interactions
    document.addEventListener('DOMContentLoaded', function() {
        // Mobile menu functionality
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        const sidebar = document.querySelector('.sidebar');
        
        if (mobileMenuBtn) {
            mobileMenuBtn.addEventListener('click', function() {
                sidebar.classList.toggle('mobile-open');
            });
        }

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(e) {
            if (window.innerWidth <= 768 && 
                !sidebar.contains(e.target) && 
                !mobileMenuBtn.contains(e.target)) {
                sidebar.classList.remove('mobile-open');
            }
        });

        // Add hover effects to cards
        const cards = document.querySelectorAll('.approval-card');
        cards.forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-8px) scale(1.02)';
            });
            
            card.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0) scale(1)';
            });
        });

        // Tab switching animation
        const tabLinks = document.querySelectorAll('.nav-link');
        tabLinks.forEach(link => {
            link.addEventListener('click', function() {
                // Remove pulse animation from all tabs
                tabLinks.forEach(tab => tab.classList.remove('pulse-glow'));
                
                // Add pulse to active tab if it has notifications
                const badge = this.querySelector('.badge');
                if (badge && parseInt(badge.textContent) > 0) {
                    this.classList.add('pulse-glow');
                }
            });
        });

        // Auto-add pulse to tabs with notifications
        tabLinks.forEach(tab => {
            const badge = tab.querySelector('.badge');
            if (badge && parseInt(badge.textContent) > 0) {
                tab.classList.add('pulse-glow');
            }
        });

        // Add smooth scrolling to comments section
        const commentSections = document.querySelectorAll('.comment-section');
        commentSections.forEach(section => {
            if (section.scrollHeight > section.clientHeight) {
                section.style.cursor = 'grab';
                
                let isDown = false;
                let startY;
                let scrollTop;

                section.addEventListener('mousedown', (e) => {
                    isDown = true;
                    section.style.cursor = 'grabbing';
                    startY = e.pageY - section.offsetTop;
                    scrollTop = section.scrollTop;
                });

                section.addEventListener('mouseleave', () => {
                    isDown = false;
                    section.style.cursor = 'grab';
                });

                section.addEventListener('mouseup', () => {
                    isDown = false;
                    section.style.cursor = 'grab';
                });

                section.addEventListener('mousemove', (e) => {
                    if (!isDown) return;
                    e.preventDefault();
                    const y = e.pageY - section.offsetTop;
                    const walk = (y - startY) * 2;
                    section.scrollTop = scrollTop - walk;
                });
            }
        });

        // Add confirmation for critical actions
        const dangerousButtons = document.querySelectorAll('.btn-danger');
        dangerousButtons.forEach(button => {
            button.addEventListener('click', function(e) {
                const action = this.getAttribute('onclick');
                if (action && (action.includes('reject') || action.includes('terminated'))) {
                    if (!confirm('Are you sure you want to perform this action? This cannot be undone.')) {
                        e.preventDefault();
                        return false;
                    }
                }
            });
        });
    });

    // Add confetti effect on approval (for demo)
    function celebrateApproval() {
        if (typeof confetti === 'function') {
            confetti({
                particleCount: 100,
                spread: 70,
                origin: { y: 0.6 }
            });
        }
    }
</script>

<!-- Confetti library for celebrations -->
<script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.5.1/dist/confetti.browser.min.js"></script>

</body>
</html>