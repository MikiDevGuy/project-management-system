<?php
// includes/auth_check.php

if (!isset($_SESSION)) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

// Get current user info
$user_id = $_SESSION['user_id'] ?? null;
$username = $_SESSION['username'] ?? 'Guest';
$user_role = $_SESSION['system_role'] ?? '';
$user_email = $_SESSION['email'] ?? '';

// Function to check if user has required role
function has_role($required_roles) {
    if (!is_array($required_roles)) {
        $required_roles = [$required_roles];
    }
    
    return in_array($_SESSION['system_role'] ?? '', $required_roles);
}

// Function to check if user can access module
function can_access_module($module) {
    $user_role = $_SESSION['system_role'] ?? '';
    
    // Define module permissions for each role
    $permissions = [
        'super_admin' => ['project_intake', 'checkpoint', 'gate_review', 'reports', 'settings', 'all'],
        'pm_manager' => ['project_intake', 'checkpoint', 'gate_review', 'reports', 'settings'],
        'pm_employee' => ['project_intake', 'view_intakes'],
        'pm_viewer' => ['view_intakes'],
        'tester' => ['view_intakes'],
        'test_viewer' => ['view_intakes'],
        'admin' => ['settings', 'reports']
    ];
    
    // Check if user role exists and has permission
    if (!isset($permissions[$user_role])) {
        return false;
    }
    
    return in_array('all', $permissions[$user_role]) || in_array($module, $permissions[$user_role]);
}

// Function to check project access
function can_access_project($project_id) {
    global $conn;
    
    if (has_role(['super_admin', 'pm_manager', 'admin'])) {
        return true;
    }
    
    // Check if user is assigned to the project
    $check_sql = "SELECT COUNT(*) as count FROM user_assignments 
                  WHERE user_id = ? AND project_id = ? AND is_active = 1";
    $stmt = mysqli_prepare($conn, $check_sql);
    mysqli_stmt_bind_param($stmt, "ii", $_SESSION['user_id'], $project_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $count);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);
    
    return $count > 0;
}

// Function to log user activity
function log_activity($action, $details = '', $entity_type = null, $entity_id = null) {
    global $conn;
    
    $user_id = $_SESSION['user_id'] ?? null;
    
    if ($user_id) {
        $sql = "INSERT INTO activity_logs (user_id, action, description, entity_type, entity_id) 
                VALUES (?, ?, ?, ?, ?)";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "isssi", $user_id, $action, $details, $entity_type, $entity_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}

// Function to send notification
function send_notification($user_id, $title, $message, $type = 'info', $related_module = null, $related_id = null) {
    global $conn;
    
    $sql = "INSERT INTO notifications (user_id, title, message, type, related_module, related_id) 
            VALUES (?, ?, ?, ?, ?, ?)";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "issssi", $user_id, $title, $message, $type, $related_module, $related_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

// Function to check session timeout
function check_session_timeout() {
    $timeout = 3600; // 1 hour in seconds
    
    if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY'] > $timeout)) {
        session_unset();
        session_destroy();
        header("Location: ../login.php?timeout=1");
        exit();
    }
    
    $_SESSION['LAST_ACTIVITY'] = time();
}

// Check session timeout on each request
check_session_timeout();
?>