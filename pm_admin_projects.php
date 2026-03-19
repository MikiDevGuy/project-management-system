<?php
session_start();
include 'db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Check permissions
$allowed_roles = ['super_admin', 'pm_manager', 'pm_employee'];
if (!in_array($_SESSION['system_role'], $allowed_roles)) {
    die('<div class="container mt-5">
        <div class="alert alert-danger">
            <i class="fas fa-ban me-2"></i>Access denied. You must have appropriate permissions.
        </div>
        <a href="login.php" class="btn btn-primary">Login</a>
    </div>');
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$role = $_SESSION['system_role'];
$user_email = $_SESSION['email'] ?? '';

// If email not in session, fetch from database
if (empty($user_email) && isset($user_id)) {
    $email_query = "SELECT email FROM users WHERE id = ?";
    $stmt = $conn->prepare($email_query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $user_email = $row['email'];
        $_SESSION['email'] = $user_email;
    }
    $stmt->close();
}

// ========== ENHANCED NOTIFICATION HELPER FUNCTIONS ==========
function createNotification($conn, $user_id, $title, $message, $type = 'info', $related_module = '', $related_id = 0) {
    // Don't create self-notifications
    if ($user_id == $_SESSION['user_id']) {
        return false;
    }
    
    $stmt = $conn->prepare("INSERT INTO notifications (user_id, title, message, type, related_module, related_id, created_at, is_read) 
                           VALUES (?, ?, ?, ?, ?, ?, NOW(), 0)");
    $stmt->bind_param("issssi", $user_id, $title, $message, $type, $related_module, $related_id);
    return $stmt->execute();
}

// ========== ENHANCED STATUS UPDATE NOTIFICATION FUNCTIONS ==========
function notifyPMEmployeeUpdate($conn, $updater_id, $item_type, $item_name, $item_id, $project_id, $old_status, $new_status) {
    // Notify PM Manager when PM Employee updates activities/sub-activities
    $updater_role = getRoleById($conn, $updater_id);
    
    if ($updater_role === 'pm_employee') {
        // Get all PM Managers assigned to this project
        $stmt = $conn->prepare("SELECT DISTINCT ua.user_id 
                               FROM user_assignments ua 
                               JOIN users u ON ua.user_id = u.id 
                               WHERE ua.project_id = ? AND u.system_role = 'pm_manager'");
        $stmt->bind_param("i", $project_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $notified = false;
        while ($manager = $result->fetch_assoc()) {
            // Don't notify the updater
            if ($manager['user_id'] != $updater_id) {
                $title = ucfirst($item_type) . " Status Updated";
                $message = "PM Employee updated $item_type: $item_name from $old_status to $new_status";
                createNotification($conn, $manager['user_id'], $title, $message, 'info', $item_type, $item_id);
                $notified = true;
            }
        }
        return $notified;
    }
    return false;
}

function notifyPMManagerUpdateToSuperAdminAndEmployees($conn, $updater_id, $updater_username, $item_type, $item_name, $item_id, $project_id, $old_status, $new_status) {
    // Notify Super Admin and PM Employees when PM Manager updates items
    $updater_role = getRoleById($conn, $updater_id);
    
    if ($updater_role === 'pm_manager') {
        $notified_count = 0;
        
        // Notify Super Admin
        $super_admin_stmt = $conn->prepare("SELECT id FROM users WHERE system_role = 'super_admin' AND id != ?");
        $super_admin_stmt->bind_param("i", $updater_id);
        $super_admin_stmt->execute();
        $super_admins = $super_admin_stmt->get_result();
        
        while ($admin = $super_admins->fetch_assoc()) {
            $title = ucfirst($item_type) . " Status Updated by PM Manager";
            $message = "PM Manager $updater_username updated $item_type: $item_name from $old_status to $new_status";
            if (createNotification($conn, $admin['id'], $title, $message, 'info', $item_type, $item_id)) {
                $notified_count++;
            }
        }
        
        // Notify assigned PM Employees for this project
        $employee_stmt = $conn->prepare("SELECT DISTINCT ua.user_id 
                                        FROM user_assignments ua 
                                        JOIN users u ON ua.user_id = u.id 
                                        WHERE ua.project_id = ? AND u.system_role = 'pm_employee' AND ua.user_id != ?");
        $employee_stmt->bind_param("ii", $project_id, $updater_id);
        $employee_stmt->execute();
        $employees = $employee_stmt->get_result();
        
        while ($employee = $employees->fetch_assoc()) {
            $title = ucfirst($item_type) . " Status Updated";
            $message = "PM Manager $updater_username updated $item_type: $item_name from $old_status to $new_status";
            if (createNotification($conn, $employee['user_id'], $title, $message, 'info', $item_type, $item_id)) {
                $notified_count++;
            }
        }
        
        return $notified_count;
    }
    return 0;
}

function notifySuperAdminUpdateToManagersAndEmployees($conn, $updater_id, $item_type, $item_name, $item_id, $project_id, $old_status, $new_status) {
    // Notify PM Managers and Employees when Super Admin updates items
    $updater_role = getRoleById($conn, $updater_id);
    
    if ($updater_role === 'super_admin') {
        $notified_count = 0;
        
        // Notify assigned PM Managers and Employees for this project
        $assign_stmt = $conn->prepare("SELECT DISTINCT ua.user_id, u.system_role 
                                      FROM user_assignments ua 
                                      JOIN users u ON ua.user_id = u.id 
                                      WHERE ua.project_id = ? AND u.system_role IN ('pm_manager', 'pm_employee') AND ua.user_id != ?");
        $assign_stmt->bind_param("ii", $project_id, $updater_id);
        $assign_stmt->execute();
        $assignees = $assign_stmt->get_result();
        
        while ($assignee = $assignees->fetch_assoc()) {
            $role_name = ($assignee['system_role'] === 'pm_manager') ? 'PM Manager' : 'PM Employee';
            $title = ucfirst($item_type) . " Status Updated by Super Admin";
            $message = "Super Admin updated $item_type: $item_name from $old_status to $new_status";
            if (createNotification($conn, $assignee['user_id'], $title, $message, 'info', $item_type, $item_id)) {
                $notified_count++;
            }
        }
        
        return $notified_count;
    }
    return 0;
}

// ========== ASSIGNMENT & REVOCATION NOTIFICATION FUNCTIONS ==========
function notifyAssignment($conn, $assigned_by_id, $assigned_to_id, $item_type, $item_name, $item_id, $project_id) {
    // Get item level name for notification
    $level_map = [
        'project' => 'Project',
        'phase' => 'Phase',
        'activity' => 'Activity',
        'sub_activity' => 'Sub-Activity'
    ];
    $level_name = $level_map[$item_type] ?? ucfirst(str_replace('_', ' ', $item_type));
    
    // Get assigned user role
    $assigned_user_role = getRoleById($conn, $assigned_to_id);
    
    // Check if assignment is allowed based on role
    if ($item_type === 'project' && $assigned_user_role !== 'pm_manager') {
        // Only PM Managers can be assigned at project level
        return false;
    }
    
    $title = "New Assignment";
    $message = "You have been assigned to $level_name: $item_name";
    return createNotification($conn, $assigned_to_id, $title, $message, 'info', $item_type, $item_id);
}

function notifyRevocation($conn, $revoked_by_id, $revoked_from_id, $item_type, $item_name, $item_id, $project_id) {
    // Get item level name for notification
    $level_map = [
        'project' => 'Project',
        'phase' => 'Phase',
        'activity' => 'Activity',
        'sub_activity' => 'Sub-Activity'
    ];
    $level_name = $level_map[$item_type] ?? ucfirst(str_replace('_', ' ', $item_type));
    
    // Get revoked user role
    $revoked_user_role = getRoleById($conn, $revoked_from_id);
    
    // Check if revocation is allowed based on role
    if ($item_type === 'project' && $revoked_user_role !== 'pm_manager') {
        // Only PM Managers can be revoked at project level
        return false;
    }
    
    $title = "Assignment Removed";
    $message = "You have been unassigned from $level_name: $item_name";
    return createNotification($conn, $revoked_from_id, $title, $message, 'warning', $item_type, $item_id);
}

// ========== USER ASSIGNMENT MANAGEMENT FUNCTIONS ==========
function assignUserToItem($conn, $assigned_by_id, $assigned_to_id, $item_type, $item_id, $project_id, $item_name) {
    // Check if user is already assigned
    $check_stmt = $conn->prepare("SELECT id FROM user_assignments WHERE user_id = ? AND {$item_type}_id = ?");
    $check_stmt->bind_param("ii", $assigned_to_id, $item_id);
    $check_stmt->execute();
    
    if ($check_stmt->get_result()->num_rows > 0) {
        return ['success' => false, 'message' => 'User is already assigned to this item'];
    }
    
    // Prepare the assignment data based on item type
    $fields = ['user_id', 'project_id', 'assigned_by'];
    $values = [$assigned_to_id, $project_id, $assigned_by_id];
    $types = "iii";
    
    // Add the specific item type field
    switch ($item_type) {
        case 'project':
            $fields[] = 'project_id';
            $values[] = $item_id;
            $types .= "i";
            break;
        case 'phase':
            $fields[] = 'phase_id';
            $values[] = $item_id;
            $types .= "i";
            break;
        case 'activity':
            $fields[] = 'activity_id';
            $values[] = $item_id;
            $types .= "i";
            break;
        case 'sub_activity':
            $fields[] = 'subactivity_id';
            $values[] = $item_id;
            $types .= "i";
            break;
        default:
            return ['success' => false, 'message' => 'Invalid item type'];
    }
    
    // Build the query
    $field_list = implode(', ', $fields);
    $placeholders = implode(', ', array_fill(0, count($fields), '?'));
    $query = "INSERT INTO user_assignments ({$field_list}, assigned_at) VALUES ({$placeholders}, NOW())";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$values);
    
    if ($stmt->execute()) {
        // Send assignment notification
        notifyAssignment($conn, $assigned_by_id, $assigned_to_id, $item_type, $item_name, $item_id, $project_id);
        return ['success' => true, 'message' => 'User assigned successfully'];
    } else {
        return ['success' => false, 'message' => 'Failed to assign user'];
    }
}

function revokeUserFromItem($conn, $revoked_by_id, $revoked_from_id, $item_type, $item_id, $project_id, $item_name) {
    // Check if user is assigned
    $check_stmt = $conn->prepare("SELECT id FROM user_assignments WHERE user_id = ? AND {$item_type}_id = ?");
    $check_stmt->bind_param("ii", $revoked_from_id, $item_id);
    $check_stmt->execute();
    
    if ($check_stmt->get_result()->num_rows === 0) {
        return ['success' => false, 'message' => 'User is not assigned to this item'];
    }
    
    // Revoke the assignment
    $delete_stmt = $conn->prepare("DELETE FROM user_assignments WHERE user_id = ? AND {$item_type}_id = ?");
    $delete_stmt->bind_param("ii", $revoked_from_id, $item_id);
    
    if ($delete_stmt->execute()) {
        // Send revocation notification
        notifyRevocation($conn, $revoked_by_id, $revoked_from_id, $item_type, $item_name, $item_id, $project_id);
        return ['success' => true, 'message' => 'User unassigned successfully'];
    } else {
        return ['success' => false, 'message' => 'Failed to unassign user'];
    }
}

// ========== GET ASSIGNED USERS ==========
function getAssignedUsers($conn, $item_type, $item_id) {
    $query = "SELECT u.id, u.username, u.system_role, ua.assigned_at 
              FROM user_assignments ua 
              JOIN users u ON ua.user_id = u.id 
              WHERE ua.{$item_type}_id = ?";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $item_id);
    $stmt->execute();
    return $stmt->get_result();
}

function getRoleById($conn, $user_id) {
    $stmt = $conn->prepare("SELECT system_role FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    return $result['system_role'] ?? '';
}

// ========== GET UNREAD NOTIFICATIONS COUNT ==========
function getUnreadNotificationCount($conn, $user_id) {
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    return $result['count'];
}

// ========== ROLE-BASED ACCESS CONTROL ==========
// Super Admin: Can access all projects, create projects, terminate projects, update all statuses
// PM Manager: Can access assigned projects, create phases/activities/sub-activities for assigned projects, terminate projects, update all statuses
// PM Employee: Can only view assigned projects, update activity and sub-activity statuses only

// Get selected project from session or request
$selected_project_id = $_SESSION['selected_project_id'] ?? 0;
if (isset($_GET['project_id']) && is_numeric($_GET['project_id'])) {
    $selected_project_id = intval($_GET['project_id']);
    $_SESSION['selected_project_id'] = $selected_project_id;
}

// Determine active dashboard for sidebar
$current_page = 'unified_project_management.php'; 
$active_dashboard = 'project_management';

// ========== HANDLE AJAX REQUESTS ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    
    $response = ['success' => false, 'message' => 'Invalid action'];
    
    try {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'add_project_bulk':
                // Only Super Admin can create projects
                if ($role !== 'super_admin') {
                    $response = ['success' => false, 'message' => 'Permission denied. Only Super Admins can create projects.'];
                    break;
                }
                
                // Add multiple projects at once
                $names = $_POST['names'] ?? [];
                $descriptions = $_POST['descriptions'] ?? [];
                $statuses = $_POST['statuses'] ?? [];
                $start_dates = $_POST['start_dates'] ?? [];
                $end_dates = $_POST['end_dates'] ?? [];
                $department_ids = $_POST['department_ids'] ?? [];
                
                if (empty($names)) {
                    $response = ['success' => false, 'message' => 'Please fill in all required fields'];
                    break;
                }
                
                $conn->begin_transaction();
                $success_count = 0;
                $project_ids = [];
                
                for ($i = 0; $i < count($names); $i++) {
                    $name = trim($names[$i]);
                    $description = trim($descriptions[$i] ?? '');
                    $status = $statuses[$i] ?? 'pending'; // Default to pending
                    $start_date = !empty($start_dates[$i]) ? $start_dates[$i] : null;
                    $end_date = !empty($end_dates[$i]) ? $end_dates[$i] : null;
                    $department_id = !empty($department_ids[$i]) ? intval($department_ids[$i]) : null;
                    
                    if (!empty($name)) {
                        $stmt = $conn->prepare("INSERT INTO projects (name, description, status, start_date, end_date, department_id, created_by, created_at) 
                                               VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
                        $stmt->bind_param("sssssii", $name, $description, $status, $start_date, $end_date, $department_id, $user_id);
                        
                        if ($stmt->execute()) {
                            $project_id = $stmt->insert_id;
                            $project_ids[] = $project_id;
                            $success_count++;
                            
                            // Auto-assign creator to project
                            $assign_stmt = $conn->prepare("INSERT INTO user_assignments (user_id, project_id, assigned_by, assigned_at) 
                                                          VALUES (?, ?, ?, NOW())");
                            $assign_stmt->bind_param("iii", $user_id, $project_id, $user_id);
                            $assign_stmt->execute();
                            
                            // Log activity
                            $activity_log = $conn->prepare("
                                INSERT INTO activity_logs (user_id, action, description, project_id, created_at) 
                                VALUES (?, ?, ?, ?, NOW())
                            ");
                            $activity_action = "Project Created";
                            $activity_desc = "New project '$name' has been created";
                            $activity_log->bind_param("issi", $user_id, $activity_action, $activity_desc, $project_id);
                            $activity_log->execute();
                            
                            // Create notification for Super Admin (self-notification skipped by function)
                            createNotification($conn, $user_id, "Project Created", 
                                "You have created project: $name", 'success', 'project', $project_id);
                        }
                    }
                }
                
                if ($success_count > 0) {
                    $conn->commit();
                    $response = [
                        'success' => true, 
                        'message' => $success_count . ' project(s) added successfully!',
                        'project_ids' => $project_ids
                    ];
                } else {
                    $conn->rollback();
                    $response = ['success' => false, 'message' => 'Failed to add projects'];
                }
                break;
                
            case 'add_phases_bulk':
                // Check permission - only Super Admin and PM Managers can add phases
                if (!in_array($role, ['super_admin', 'pm_manager'])) {
                    $response = ['success' => false, 'message' => 'Permission denied. Only Super Admins and PM Managers can add phases.'];
                    break;
                }
                
                // Add multiple phases at once
                $project_id = intval($_POST['project_id']);
                $names = $_POST['names'] ?? [];
                $descriptions = $_POST['descriptions'] ?? [];
                $statuses = $_POST['statuses'] ?? [];
                $phase_orders = $_POST['phase_orders'] ?? [];
                $start_dates = $_POST['start_dates'] ?? [];
                $end_dates = $_POST['end_dates'] ?? [];
                
                if (empty($names) || empty($project_id)) {
                    $response = ['success' => false, 'message' => 'Please fill in all required fields'];
                    break;
                }
                
                // Check if user has access to this project
                if ($role !== 'super_admin') {
                    $check_access = $conn->prepare("SELECT id FROM user_assignments WHERE user_id = ? AND project_id = ?");
                    $check_access->bind_param("ii", $user_id, $project_id);
                    $check_access->execute();
                    if ($check_access->get_result()->num_rows === 0) {
                        $response = ['success' => false, 'message' => 'Access denied. You are not assigned to this project.'];
                        break;
                    }
                }
                
                // Check if project is terminated
                $check_stmt = $conn->prepare("SELECT status FROM projects WHERE id = ?");
                $check_stmt->bind_param("i", $project_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result()->fetch_assoc();
                
                if ($check_result && $check_result['status'] === 'terminated') {
                    $response = ['success' => false, 'message' => 'Cannot add phases: Project is terminated!'];
                    break;
                }
                
                $conn->begin_transaction();
                $success_count = 0;
                $phase_ids = [];
                
                for ($i = 0; $i < count($names); $i++) {
                    $name = trim($names[$i]);
                    $description = trim($descriptions[$i] ?? '');
                    $status = $statuses[$i] ?? 'pending'; // Default to pending
                    $phase_order = !empty($phase_orders[$i]) ? intval($phase_orders[$i]) : 1;
                    $start_date = !empty($start_dates[$i]) ? $start_dates[$i] : null;
                    $end_date = !empty($end_dates[$i]) ? $end_dates[$i] : null;
                    
                    if (!empty($name)) {
                        $stmt = $conn->prepare("INSERT INTO phases (project_id, name, description, status, phase_order, start_date, end_date, created_at) 
                                               VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
                        $stmt->bind_param("isssiss", $project_id, $name, $description, $status, $phase_order, $start_date, $end_date);
                        
                        if ($stmt->execute()) {
                            $phase_id = $stmt->insert_id;
                            $phase_ids[] = $phase_id;
                            $success_count++;
                            
                            // Log activity
                            $activity_log = $conn->prepare("
                                INSERT INTO activity_logs (user_id, action, description, project_id, created_at) 
                                VALUES (?, ?, ?, ?, NOW())
                            ");
                            $activity_action = "Phase Added";
                            $activity_desc = "New phase '$name' has been added to project";
                            $activity_log->bind_param("issi", $user_id, $activity_action, $activity_desc, $project_id);
                            $activity_log->execute();
                            
                            // Create notification based on role
                            if ($role === 'pm_manager') {
                                // Notify Super Admin and PM Employees using enhanced function
                                $notified = notifyPMManagerUpdateToSuperAdminAndEmployees($conn, $user_id, $username, 'phase', $name, $phase_id, $project_id, 'pending', 'pending');
                            } else if ($role === 'super_admin') {
                                // Notify assigned PM Managers and Employees for this project
                                notifySuperAdminUpdateToManagersAndEmployees($conn, $user_id, 'phase', $name, $phase_id, $project_id, 'pending', 'pending');
                            }
                        }
                    }
                }
                
                if ($success_count > 0) {
                    $conn->commit();
                    $response = [
                        'success' => true, 
                        'message' => $success_count . ' phase(s) added successfully!',
                        'phase_ids' => $phase_ids
                    ];
                } else {
                    $conn->rollback();
                    $response = ['success' => false, 'message' => 'Failed to add phases'];
                }
                break;
                
            case 'add_activities_bulk':
                // Check permission - only Super Admin and PM Managers can add activities
                if (!in_array($role, ['super_admin', 'pm_manager'])) {
                    $response = ['success' => false, 'message' => 'Permission denied. Only Super Admins and PM Managers can add activities.'];
                    break;
                }
                
                // Add multiple activities at once
                $project_id = intval($_POST['project_id']);
                $phase_id = intval($_POST['phase_id']);
                $names = $_POST['names'] ?? [];
                $descriptions = $_POST['descriptions'] ?? [];
                $statuses = $_POST['statuses'] ?? [];
                $start_dates = $_POST['start_dates'] ?? [];
                $end_dates = $_POST['end_dates'] ?? [];
                
                if (empty($names) || empty($phase_id) || empty($project_id)) {
                    $response = ['success' => false, 'message' => 'Please fill in all required fields'];
                    break;
                }
                
                // Check if user has access to this project
                if ($role !== 'super_admin') {
                    $check_access = $conn->prepare("SELECT id FROM user_assignments WHERE user_id = ? AND project_id = ?");
                    $check_access->bind_param("ii", $user_id, $project_id);
                    $check_access->execute();
                    if ($check_access->get_result()->num_rows === 0) {
                        $response = ['success'=> false, 'message' => 'Access denied. You are not assigned to this project.'];
                        break;
                    }
                }
                
                // Check if project is terminated
                $check_stmt = $conn->prepare("SELECT status FROM projects WHERE id = ?");
                $check_stmt->bind_param("i", $project_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result()->fetch_assoc();
                
                if ($check_result && $check_result['status'] === 'terminated') {
                    $response = ['success' => false, 'message' => 'Cannot add activities: Project is terminated!'];
                    break;
                }
                
                // Get phase details for notification
                $phase_stmt = $conn->prepare("SELECT name FROM phases WHERE id = ?");
                $phase_stmt->bind_param("i", $phase_id);
                $phase_stmt->execute();
                $phase_result = $phase_stmt->get_result()->fetch_assoc();
                $phase_name = $phase_result['name'] ?? 'Unknown Phase';
                
                $conn->begin_transaction();
                $success_count = 0;
                $activity_ids = [];
                
                for ($i = 0; $i < count($names); $i++) {
                    $name = trim($names[$i]);
                    $description = trim($descriptions[$i] ?? '');
                    $status = $statuses[$i] ?? 'pending'; // Default to pending
                    $start_date = !empty($start_dates[$i]) ? $start_dates[$i] : null;
                    $end_date = !empty($end_dates[$i]) ? $end_dates[$i] : null;
                    
                    if (!empty($name)) {
                        $stmt = $conn->prepare("INSERT INTO activities (project_id, phase_id, name, description, status, start_date, end_date, created_at) 
                                               VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
                        $stmt->bind_param("iisssss", $project_id, $phase_id, $name, $description, $status, $start_date, $end_date);
                        
                        if ($stmt->execute()) {
                            $activity_id = $stmt->insert_id;
                            $activity_ids[] = $activity_id;
                            $success_count++;
                            
                            // Log activity
                            $activity_log = $conn->prepare("
                                INSERT INTO activity_logs (user_id, action, description, project_id, created_at) 
                                VALUES (?, ?, ?, ?, NOW())
                            ");
                            $activity_action = "Activity Added";
                            $activity_desc = "New activity '$name' has been added";
                            $activity_log->bind_param("issi", $user_id, $activity_action, $activity_desc, $project_id);
                            $activity_log->execute();
                            
                            // Create notification based on role
                            if ($role === 'pm_manager') {
                                // Notify Super Admin and PM Employees using enhanced function
                                notifyPMManagerUpdateToSuperAdminAndEmployees($conn, $user_id, $username, 'activity', $name, $activity_id, $project_id, 'pending', 'pending');
                            } else if ($role === 'super_admin') {
                                // Notify assigned PM Managers and Employees for this project
                                notifySuperAdminUpdateToManagersAndEmployees($conn, $user_id, 'activity', $name, $activity_id, $project_id, 'pending', 'pending');
                            }
                        }
                    }
                }
                
                if ($success_count > 0) {
                    $conn->commit();
                    $response = [
                        'success' => true, 
                        'message' => $success_count . ' activity(s) added successfully!',
                        'activity_ids' => $activity_ids
                    ];
                } else {
                    $conn->rollback();
                    $response = ['success' => false, 'message' => 'Failed to add activities'];
                }
                break;
                
            case 'add_sub_activities_bulk':
                // Check permission - only Super Admin and PM Managers can add sub-activities
                if (!in_array($role, ['super_admin', 'pm_manager'])) {
                    $response = ['success' => false, 'message' => 'Permission denied. Only Super Admins and PM Managers can add sub-activities.'];
                    break;
                }
                
                // Add multiple sub-activities at once
                $project_id = intval($_POST['project_id']);
                $phase_id = intval($_POST['phase_id']);
                $activity_id = intval($_POST['activity_id']);
                $names = $_POST['names'] ?? [];
                $descriptions = $_POST['descriptions'] ?? [];
                $statuses = $_POST['statuses'] ?? [];
                $start_dates = $_POST['start_dates'] ?? [];
                $end_dates = $_POST['end_dates'] ?? [];
                
                if (empty($names) || empty($activity_id) || empty($project_id) || empty($phase_id)) {
                    $response = ['success' => false, 'message' => 'Please fill in all required fields'];
                    break;
                }
                
                // Check if user has access to this project
                if ($role !== 'super_admin') {
                    $check_access = $conn->prepare("SELECT id FROM user_assignments WHERE user_id = ? AND project_id = ?");
                    $check_access->bind_param("ii", $user_id, $project_id);
                    $check_access->execute();
                    if ($check_access->get_result()->num_rows === 0) {
                        $response = ['success' => false, 'message' => 'Access denied. You are not assigned to this project.'];
                        break;
                    }
                }
                
                // Check if project is terminated
                $check_stmt = $conn->prepare("SELECT status FROM projects WHERE id = ?");
                $check_stmt->bind_param("i", $project_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result()->fetch_assoc();
                
                if ($check_result && $check_result['status'] === 'terminated') {
                    $response = ['success' => false, 'message' => 'Cannot add sub-activities: Project is terminated!'];
                    break;
                }
                
                // Get activity and phase details for notification
                $activity_stmt = $conn->prepare("SELECT a.name as activity_name, p.name as phase_name 
                                               FROM activities a 
                                               JOIN phases p ON a.phase_id = p.id 
                                               WHERE a.id = ?");
                $activity_stmt->bind_param("i", $activity_id);
                $activity_stmt->execute();
                $activity_result = $activity_stmt->get_result()->fetch_assoc();
                $activity_name = $activity_result['activity_name'] ?? 'Unknown Activity';
                $phase_name = $activity_result['phase_name'] ?? 'Unknown Phase';
                
                $conn->begin_transaction();
                $success_count = 0;
                $sub_activity_ids = [];
                
                for ($i = 0; $i < count($names); $i++) {
                    $name = trim($names[$i]);
                    $description = trim($descriptions[$i] ?? '');
                    $status = $statuses[$i] ?? 'pending'; // Default to pending
                    $start_date = !empty($start_dates[$i]) ? $start_dates[$i] : null;
                    $end_date = !empty($end_dates[$i]) ? $end_dates[$i] : null;
                    
                    if (!empty($name)) {
                        $stmt = $conn->prepare("INSERT INTO sub_activities (project_id, phase_id, activity_id, name, description, status, start_date, end_date, created_at) 
                                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                        $stmt->bind_param("iiisssss", $project_id, $phase_id, $activity_id, $name, $description, $status, $start_date, $end_date);
                        
                        if ($stmt->execute()) {
                            $sub_activity_id = $stmt->insert_id;
                            $sub_activity_ids[] = $sub_activity_id;
                            $success_count++;
                            
                            // Log activity
                            $activity_log = $conn->prepare("
                                INSERT INTO activity_logs (user_id, action, description, project_id, created_at) 
                                VALUES (?, ?, ?, ?, NOW())
                            ");
                            $activity_action = "Sub-Activity Added";
                            $activity_desc = "New sub-activity '$name' has been added";
                            $activity_log->bind_param("issi", $user_id, $activity_action, $activity_desc, $project_id);
                            $activity_log->execute();
                            
                            // Create notification based on role
                            if ($role === 'pm_manager') {
                                // Notify Super Admin and PM Employees using enhanced function
                                notifyPMManagerUpdateToSuperAdminAndEmployees($conn, $user_id, $username, 'sub_activity', $name, $sub_activity_id, $project_id, 'pending', 'pending');
                            } else if ($role === 'super_admin') {
                                // Notify assigned PM Managers and Employees for this project
                                notifySuperAdminUpdateToManagersAndEmployees($conn, $user_id, 'sub_activity', $name, $sub_activity_id, $project_id, 'pending', 'pending');
                            }
                        }
                    }
                }
                
                if ($success_count > 0) {
                    $conn->commit();
                    $response = [
                        'success' => true, 
                        'message' => $success_count . ' sub-activity(s) added successfully!',
                        'sub_activity_ids' => $sub_activity_ids
                    ];
                } else {
                    $conn->rollback();
                    $response = ['success' => false, 'message' => 'Failed to add sub-activities'];
                }
                break;
                
            case 'edit_project':
                // Only Super Admin can edit projects
                if ($role !== 'super_admin') {
                    $response = ['success' => false, 'message' => 'Permission denied. Only Super Admins can edit projects.'];
                    break;
                }
                
                // Edit existing project
                $project_id = intval($_POST['project_id']);
                $name = trim($_POST['name']);
                $description = trim($_POST['description']);
                $status = $_POST['status'];
                $start_date = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
                $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
                $department_id = !empty($_POST['department_id']) ? intval($_POST['department_id']) : null;
                
                if (empty($name)) {
                    $response = ['success' => false, 'message' => 'Please fill in all required fields'];
                    break;
                }
                
                // Get old status for notification
                $check_old_status = $conn->prepare("SELECT status, name FROM projects WHERE id = ?");
                $check_old_status->bind_param("i", $project_id);
                $check_old_status->execute();
                $old_status_result = $check_old_status->get_result()->fetch_assoc();
                $old_status = $old_status_result['status'] ?? '';
                $project_name = $old_status_result['name'] ?? '';
                
                $stmt = $conn->prepare("UPDATE projects SET name = ?, description = ?, status = ?, 
                                       start_date = ?, end_date = ?, department_id = ?, updated_at = NOW() 
                                       WHERE id = ?");
                $stmt->bind_param("sssssii", $name, $description, $status, $start_date, $end_date, $department_id, $project_id);
                
                if ($stmt->execute()) {
                    // Log activity
                    $activity_log = $conn->prepare("
                        INSERT INTO activity_logs (user_id, action, description, project_id, created_at) 
                        VALUES (?, ?, ?, ?, NOW())
                    ");
                    $activity_action = "Project Updated";
                    $activity_desc = "Project '$name' has been updated by $username";
                    $activity_log->bind_param("issi", $user_id, $activity_action, $activity_desc, $project_id);
                    $activity_log->execute();
                    
                    // Notify assigned PM Managers and Employees about project update with old/new status
                    if ($old_status !== $status) {
                        notifySuperAdminUpdateToManagersAndEmployees($conn, $user_id, 'project', $name, $project_id, $project_id, $old_status, $status);
                    }
                    
                    // If project is being terminated, notify all users
                    if ($old_status !== 'terminated' && $status === 'terminated') {
                        // Notify all assigned users about project termination
                        $all_assign_stmt = $conn->prepare("SELECT DISTINCT ua.user_id FROM user_assignments ua 
                                                         WHERE ua.project_id = ? AND ua.user_id != ?");
                        $all_assign_stmt->bind_param("ii", $project_id, $user_id);
                        $all_assign_stmt->execute();
                        $all_assignees = $all_assign_stmt->get_result();
                        
                        while ($assignee = $all_assignees->fetch_assoc()) {
                            createNotification($conn, $assignee['user_id'], "Project Terminated", 
                                "Project '$project_name' has been terminated by Super Admin", 'warning', 'project', $project_id);
                        }
                    }
                    
                    $response = [
                        'success' => true, 
                        'message' => 'Project updated successfully!',
                        'project_id' => $project_id
                    ];
                } else {
                    $response = ['success' => false, 'message' => 'Failed to update project'];
                }
                break;
                
            case 'edit_phase':
                // Check permission - only Super Admin and PM Managers can edit phases
                if (!in_array($role, ['super_admin', 'pm_manager'])) {
                    $response = ['success' => false, 'message' => 'Permission denied. Only Super Admins and PM Managers can edit phases.'];
                    break;
                }
                
                // Edit existing phase
                $phase_id = intval($_POST['phase_id']);
                $name = trim($_POST['name']);
                $description = trim($_POST['description']);
                $status = $_POST['status'];
                $phase_order = isset($_POST['phase_order']) ? intval($_POST['phase_order']) : 1;
                $start_date = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
                $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
                $project_id = intval($_POST['project_id']);
                
                if (empty($name) || empty($project_id)) {
                    $response = ['success' => false, 'message' => 'Please fill in all required fields'];
                    break;
                }
                
                // Check if project is terminated (lock check)
                $check_project = $conn->prepare("SELECT status FROM projects WHERE id = ?");
                $check_project->bind_param("i", $project_id);
                $check_project->execute();
                $project_result = $check_project->get_result()->fetch_assoc();
                
                if ($project_result && $project_result['status'] === 'terminated' && $role !== 'super_admin') {
                    $response = ['success' => false, 'message' => 'Cannot edit phase: Project is terminated!'];
                    break;
                }
                
                // Check if user has access to this project
                if ($role !== 'super_admin') {
                    $check_access = $conn->prepare("SELECT id FROM user_assignments WHERE user_id = ? AND project_id = ?");
                    $check_access->bind_param("ii", $user_id, $project_id);
                    $check_access->execute();
                    if ($check_access->get_result()->num_rows === 0) {
                        $response = ['success' => false, 'message' => 'Access denied. You are not assigned to this project.'];
                        break;
                    }
                }
                
                // Get old status for notification
                $check_old_status = $conn->prepare("SELECT status FROM phases WHERE id = ?");
                $check_old_status->bind_param("i", $phase_id);
                $check_old_status->execute();
                $old_status_result = $check_old_status->get_result()->fetch_assoc();
                $old_status = $old_status_result['status'] ?? '';
                
                $stmt = $conn->prepare("UPDATE phases SET name = ?, description = ?, status = ?, 
                                       phase_order = ?, start_date = ?, end_date = ?, 
                                       project_id = ?, updated_at = NOW() WHERE id = ?");
                $stmt->bind_param("sssissii", $name, $description, $status, $phase_order, $start_date, $end_date, $project_id, $phase_id);
                
                if ($stmt->execute()) {
                    // Log activity
                    $activity_log = $conn->prepare("
                        INSERT INTO activity_logs (user_id, action, description, project_id, created_at) 
                        VALUES (?, ?, ?, ?, NOW())
                    ");
                    $activity_action = "Phase Updated";
                    $activity_desc = "Phase '$name' has been updated by $username";
                    $activity_log->bind_param("issi", $user_id, $activity_action, $activity_desc, $project_id);
                    $activity_log->execute();
                    
                    // Create notifications based on role with old/new status
                    if ($role === 'pm_manager') {
                        // Notify Super Admin and PM Employees using enhanced function
                        notifyPMManagerUpdateToSuperAdminAndEmployees($conn, $user_id, $username, 'phase', $name, $phase_id, $project_id, $old_status, $status);
                    } else if ($role === 'super_admin') {
                        // Notify assigned PM Managers and Employees for this project
                        notifySuperAdminUpdateToManagersAndEmployees($conn, $user_id, 'phase', $name, $phase_id, $project_id, $old_status, $status);
                    }
                    
                    $response = [
                        'success' => true, 
                        'message' => 'Phase updated successfully!',
                        'phase_id' => $phase_id
                    ];
                } else {
                    $response = ['success' => false, 'message' => 'Failed to update phase'];
                }
                break;
                
            case 'edit_activity':
                // Check permission - only Super Admin and PM Managers can edit activities
                if (!in_array($role, ['super_admin', 'pm_manager'])) {
                    $response = ['success' => false, 'message' => 'Permission denied. Only Super Admins and PM Managers can edit activities.'];
                    break;
                }
                
                // Edit existing activity
                $activity_id = intval($_POST['activity_id']);
                $name = trim($_POST['name']);
                $description = trim($_POST['description']);
                $status = $_POST['status'];
                $start_date = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
                $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
                $phase_id = intval($_POST['phase_id']);
                
                // Get project_id for checking termination
                $get_project_stmt = $conn->prepare("SELECT a.project_id, p.status as project_status FROM activities a 
                                                   JOIN projects p ON a.project_id = p.id 
                                                   WHERE a.id = ?");
                $get_project_stmt->bind_param("i", $activity_id);
                $get_project_stmt->execute();
                $project_result = $get_project_stmt->get_result()->fetch_assoc();
                $project_id = $project_result['project_id'];
                $project_status = $project_result['project_status'] ?? '';
                
                if (empty($name) || empty($phase_id)) {
                    $response = ['success' => false, 'message' => 'Please fill in all required fields'];
                    break;
                }
                
                // Check if project is terminated (lock check)
                if ($project_status === 'terminated' && $role !== 'super_admin') {
                    $response = ['success' => false, 'message' => 'Cannot edit activity: Project is terminated!'];
                    break;
                }
                
                // Check if user has access to this project
                if ($role !== 'super_admin') {
                    $check_access = $conn->prepare("SELECT id FROM user_assignments WHERE user_id = ? AND project_id = ?");
                    $check_access->bind_param("ii", $user_id, $project_id);
                    $check_access->execute();
                    if ($check_access->get_result()->num_rows === 0) {
                        $response = ['success' => false, 'message' => 'Access denied. You are not assigned to this project.'];
                        break;
                    }
                }
                
                // Get old status for notification
                $check_old_status = $conn->prepare("SELECT status FROM activities WHERE id = ?");
                $check_old_status->bind_param("i", $activity_id);
                $check_old_status->execute();
                $old_status_result = $check_old_status->get_result()->fetch_assoc();
                $old_status = $old_status_result['status'] ?? '';
                
                $stmt = $conn->prepare("UPDATE activities SET name = ?, description = ?, status = ?, 
                                       start_date = ?, end_date = ?, phase_id = ?, updated_at = NOW() 
                                       WHERE id = ?");
                $stmt->bind_param("sssssii", $name, $description, $status, $start_date, $end_date, $phase_id, $activity_id);
                
                if ($stmt->execute()) {
                    // Log activity
                    $activity_log = $conn->prepare("
                        INSERT INTO activity_logs (user_id, action, description, project_id, created_at) 
                        VALUES (?, ?, ?, ?, NOW())
                    ");
                    $activity_action = "Activity Updated";
                    $activity_desc = "Activity '$name' has been updated by $username";
                    $activity_log->bind_param("issi", $user_id, $activity_action, $activity_desc, $project_id);
                    $activity_log->execute();
                    
                    // Create notifications based on role with old/new status
                    if ($role === 'pm_manager') {
                        // Notify Super Admin and PM Employees using enhanced function
                        notifyPMManagerUpdateToSuperAdminAndEmployees($conn, $user_id, $username, 'activity', $name, $activity_id, $project_id, $old_status, $status);
                    } else if ($role === 'super_admin') {
                        // Notify assigned PM Managers and Employees for this project
                        notifySuperAdminUpdateToManagersAndEmployees($conn, $user_id, 'activity', $name, $activity_id, $project_id, $old_status, $status);
                    }
                    
                    $response = ['success' => true, 'message' => 'Activity updated successfully!'];
                } else {
                    $response = ['success' => false, 'message' => 'Failed to update activity'];
                }
                break;
                
            case 'edit_sub_activity':
                // Check permission - only Super Admin and PM Managers can edit sub-activities
                if (!in_array($role, ['super_admin', 'pm_manager'])) {
                    $response = ['success' => false, 'message' => 'Permission denied. Only Super Admins and PM Managers can edit sub-activities.'];
                    break;
                }
                
                // Edit existing sub-activity
                $sub_activity_id = intval($_POST['sub_activity_id']);
                $name = trim($_POST['name']);
                $description = trim($_POST['description']);
                $status = $_POST['status'];
                $start_date = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
                $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
                $activity_id = intval($_POST['activity_id']);
                
                // Get project_id for checking termination
                $get_project_stmt = $conn->prepare("SELECT s.project_id, p.status as project_status FROM sub_activities s 
                                                   JOIN projects p ON s.project_id = p.id 
                                                   WHERE s.id = ?");
                $get_project_stmt->bind_param("i", $sub_activity_id);
                $get_project_stmt->execute();
                $project_result = $get_project_stmt->get_result()->fetch_assoc();
                $project_id = $project_result['project_id'];
                $project_status = $project_result['project_status'] ?? '';
                
                if (empty($name) || empty($activity_id)) {
                    $response = ['success' => false, 'message' => 'Please fill in all required fields'];
                    break;
                }
                
                // Check if project is terminated (lock check)
                if ($project_status === 'terminated' && $role !== 'super_admin') {
                    $response = ['success' => false, 'message' => 'Cannot edit sub-activity: Project is terminated!'];
                    break;
                }
                
                // Check if user has access to this project
                if ($role !== 'super_admin') {
                    $check_access = $conn->prepare("SELECT id FROM user_assignments WHERE user_id = ? AND project_id = ?");
                    $check_access->bind_param("ii", $user_id, $project_id);
                    $check_access->execute();
                    if ($check_access->get_result()->num_rows === 0) {
                        $response = ['success' => false, 'message' => 'Access denied. You are not assigned to this project.'];
                        break;
                    }
                }
                
                // Get old status for notification
                $check_old_status = $conn->prepare("SELECT status FROM sub_activities WHERE id = ?");
                $check_old_status->bind_param("i", $sub_activity_id);
                $check_old_status->execute();
                $old_status_result = $check_old_status->get_result()->fetch_assoc();
                $old_status = $old_status_result['status'] ?? '';
                
                $stmt = $conn->prepare("UPDATE sub_activities SET name = ?, description = ?, status = ?, 
                                       start_date = ?, end_date = ?, activity_id = ?, updated_at = NOW() 
                                       WHERE id = ?");
                $stmt->bind_param("sssssii", $name, $description, $status, $start_date, $end_date, $activity_id, $sub_activity_id);
                
                if ($stmt->execute()) {
                    // Log activity
                    $activity_log = $conn->prepare("
                        INSERT INTO activity_logs (user_id, action, description, project_id, created_at) 
                        VALUES (?, ?, ?, ?, NOW())
                    ");
                    $activity_action = "Sub-Activity Updated";
                    $activity_desc = "Sub-activity '$name' has been updated by $username";
                    $activity_log->bind_param("issi", $user_id, $activity_action, $activity_desc, $project_id);
                    $activity_log->execute();
                    
                    // Create notifications based on role with old/new status
                    if ($role === 'pm_manager') {
                        // Notify Super Admin and PM Employees using enhanced function
                        notifyPMManagerUpdateToSuperAdminAndEmployees($conn, $user_id, $username, 'sub_activity', $name, $sub_activity_id, $project_id, $old_status, $status);
                    } else if ($role === 'super_admin') {
                        // Notify assigned PM Managers and Employees for this project
                        notifySuperAdminUpdateToManagersAndEmployees($conn, $user_id, 'sub_activity', $name, $sub_activity_id, $project_id, $old_status, $status);
                    }
                    
                    $response = ['success' => true, 'message' => 'Sub-activity updated successfully!'];
                } else {
                    $response = ['success' => false, 'message' => 'Failed to update sub-activity'];
                }
                break;
                
        case 'delete_project':
    // Only Super Admin can delete projects
    if ($role !== 'super_admin') {
        $response = ['success' => false, 'message' => 'Permission denied. Only Super Admins can delete projects.'];
        break;
    }
    
    // Delete project
    $project_id = intval($_POST['project_id']);
    
    // Get project name for messages
    $get_project_name = $conn->prepare("SELECT name FROM projects WHERE id = ?");
    $get_project_name->bind_param("i", $project_id);
    $get_project_name->execute();
    $project_name_result = $get_project_name->get_result()->fetch_assoc();
    $project_name = $project_name_result['name'] ?? 'Unknown Project';
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Option 1: Temporarily disable foreign key checks (CAREFUL WITH THIS!)
        $conn->query("SET FOREIGN_KEY_CHECKS = 0");
        
        // Delete all related records
        $tables = [
            'sub_activities',
            'activities', 
            'phases',
            'change_requests',
            'user_assignments',
            'activity_logs'
        ];
        
        foreach ($tables as $table) {
            $delete_stmt = $conn->prepare("DELETE FROM {$table} WHERE project_id = ?");
            $delete_stmt->bind_param("i", $project_id);
            $delete_stmt->execute();
        }
        
        // Delete notifications
        $delete_notifications = $conn->prepare("DELETE FROM notifications WHERE related_module = 'project' AND related_id = ?");
        $delete_notifications->bind_param("i", $project_id);
        $delete_notifications->execute();
        
        // Delete project itself
        $delete_project = $conn->prepare("DELETE FROM projects WHERE id = ?");
        $delete_project->bind_param("i", $project_id);
        
        if ($delete_project->execute()) {
            // Re-enable foreign key checks
            $conn->query("SET FOREIGN_KEY_CHECKS = 1");
            
            $conn->commit();
            $response = ['success' => true, 'message' => "Project '{$project_name}' deleted successfully!"];
        } else {
            $conn->query("SET FOREIGN_KEY_CHECKS = 1");
            $conn->rollback();
            $response = ['success' => false, 'message' => 'Failed to delete project: ' . $conn->error];
        }
        
    } catch (Exception $e) {
        // Ensure foreign key checks are re-enabled
        $conn->query("SET FOREIGN_KEY_CHECKS = 1");
        $conn->rollback();
        
        $response = ['success' => false, 'message' => 'Failed to delete project: ' . $e->getMessage()];
    }
    break;
                
            case 'delete_phase':
    // Check permission - only Super Admin and PM Managers can delete phases
    if (!in_array($role, ['super_admin', 'pm_manager'])) {
        $response = ['success' => false, 'message' => 'Permission denied. Only Super Admins and PM Managers can delete phases.'];
        break;
    }
    
    // Delete phase
    $phase_id = intval($_POST['phase_id']);
    
    // Get project_id for access check
    $get_project_stmt = $conn->prepare("SELECT ph.project_id, p.status as project_status FROM phases ph 
                                       JOIN projects p ON ph.project_id = p.id 
                                       WHERE ph.id = ?");
    $get_project_stmt->bind_param("i", $phase_id);
    $get_project_stmt->execute();
    $project_result = $get_project_stmt->get_result()->fetch_assoc();
    $project_id = $project_result['project_id'];
    $project_status = $project_result['project_status'] ?? '';
    
    // Check if project is terminated (lock check)
    if ($project_status === 'terminated' && $role !== 'super_admin') {
        $response = ['success' => false, 'message' => 'Cannot delete phase: Project is terminated!'];
        break;
    }
    
    // Check if user has access to this project
    if ($role !== 'super_admin') {
        $check_access = $conn->prepare("SELECT id FROM user_assignments WHERE user_id = ? AND project_id = ?");
        $check_access->bind_param("ii", $user_id, $project_id);
        $check_access->execute();
        if ($check_access->get_result()->num_rows === 0) {
            $response = ['success' => false, 'message' => 'Access denied. You are not assigned to this project.'];
            break;
        }
    }
    
    // Check if phase has activities
    $check_stmt = $conn->prepare("SELECT COUNT(*) as count FROM activities WHERE phase_id = ?");
    $check_stmt->bind_param("i", $phase_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result()->fetch_assoc();
    
    if ($check_result['count'] > 0) {
        $response = ['success' => false, 'message' => 'Cannot delete phase. There are ' . $check_result['count'] . ' activities associated with it.'];
        break;
    }
    
    // Delete phase
    $delete_stmt = $conn->prepare("DELETE FROM phases WHERE id = ?");
    $delete_stmt->bind_param("i", $phase_id);
    
    if ($delete_stmt->execute()) {
        $response = ['success' => true, 'message' => 'Phase deleted successfully!'];
    } else {
        $response = ['success' => false, 'message' => 'Failed to delete phase'];
    }
    break;
                
            case 'delete_activity':
                // Check permission - only Super Admin and PM Managers can delete activities
                if (!in_array($role, ['super_admin', 'pm_manager'])) {
                    $response = ['success' => false, 'message' => 'Permission denied. Only Super Admins and PM Managers can delete activities.'];
                    break;
                }
                
                // Delete activity
                $activity_id = intval($_POST['activity_id']);
                
                // Get project_id for access check
                $get_project_stmt = $conn->prepare("SELECT a.project_id, p.status as project_status FROM activities a 
                                                   JOIN projects p ON a.project_id = p.id 
                                                   WHERE a.id = ?");
                $get_project_stmt->bind_param("i", $activity_id);
                $get_project_stmt->execute();
                $project_result = $get_project_stmt->get_result()->fetch_assoc();
                $project_id = $project_result['project_id'];
                $project_status = $project_result['project_status'] ?? '';
                
                // Check if project is terminated (lock check)
                if ($project_status === 'terminated' && $role !== 'super_admin') {
                    $response = ['success' => false, 'message' => 'Cannot delete activity: Project is terminated!'];
                    break;
                }
                
                // Check if user has access to this project
                if ($role !== 'super_admin') {
                    $check_access = $conn->prepare("SELECT id FROM user_assignments WHERE user_id = ? AND project_id = ?");
                    $check_access->bind_param("ii", $user_id, $project_id);
                    $check_access->execute();
                    if ($check_access->get_result()->num_rows === 0) {
                        $response = ['success' => false, 'message' => 'Access denied. You are not assigned to this project.'];
                        break;
                    }
                }
                
                // Check if activity has sub-activities
                $check_stmt = $conn->prepare("SELECT COUNT(*) as count FROM sub_activities WHERE activity_id = ?");
                $check_stmt->bind_param("i", $activity_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result()->fetch_assoc();
                
                if ($check_result['count'] > 0) {
                    $response = ['success' => false, 'message' => 'Cannot delete activity. There are ' . $check_result['count'] . ' sub-activities associated with it.'];
                    break;
                }
                
                // Delete activity
                $delete_stmt = $conn->prepare("DELETE FROM activities WHERE id = ?");
                $delete_stmt->bind_param("i", $activity_id);
                
                if ($delete_stmt->execute()) {
                    $response = ['success' => true, 'message' => 'Activity deleted successfully!'];
                } else {
                    $response = ['success' => false, 'message' => 'Failed to delete activity'];
                }
                break;
                
            case 'delete_sub_activity':
                // Check permission - only Super Admin and PM Managers can delete sub-activities
                if (!in_array($role, ['super_admin', 'pm_manager'])) {
                    $response = ['success' => false, 'message' => 'Permission denied. Only Super Admins and PM Managers can delete sub-activities.'];
                    break;
                }
                
                // Delete sub-activity
                $sub_activity_id = intval($_POST['sub_activity_id']);
                
                // Get project_id for access check
                $get_project_stmt = $conn->prepare("SELECT s.project_id, p.status as project_status FROM sub_activities s 
                                                   JOIN projects p ON s.project_id = p.id 
                                                   WHERE s.id = ?");
                $get_project_stmt->bind_param("i", $sub_activity_id);
                $get_project_stmt->execute();
                $project_result = $get_project_stmt->get_result()->fetch_assoc();
                $project_id = $project_result['project_id'];
                $project_status = $project_result['project_status'] ?? '';
                
                // Check if project is terminated (lock check)
                if ($project_status === 'terminated' && $role !== 'super_admin') {
                    $response = ['success' => false, 'message' => 'Cannot delete sub-activity: Project is terminated!'];
                    break;
                }
                
                // Check if user has access to this project
                if ($role !== 'super_admin') {
                    $check_access = $conn->prepare("SELECT id FROM user_assignments WHERE user_id = ? AND project_id = ?");
                    $check_access->bind_param("ii", $user_id, $project_id);
                    $check_access->execute();
                    if ($check_access->get_result()->num_rows === 0) {
                        $response = ['success' => false, 'message' => 'Access denied. You are not assigned to this project.'];
                        break;
                    }
                }
                
                // Delete sub-activity
                $delete_stmt = $conn->prepare("DELETE FROM sub_activities WHERE id = ?");
                $delete_stmt->bind_param("i", $sub_activity_id);
                
                if ($delete_stmt->execute()) {
                    $response = ['success' => true, 'message' => 'Sub-activity deleted successfully!'];
                } else {
                    $response = ['success' => false, 'message' => 'Failed to delete sub-activity'];
                }
                break;
                
            case 'get_phases_by_project':
                // Get phases for a specific project
                $project_id = intval($_POST['project_id']);
                $stmt = $conn->prepare("SELECT id, name FROM phases WHERE project_id = ? AND status != 'terminated' ORDER BY phase_order, name");
                $stmt->bind_param("i", $project_id);
                $stmt->execute();
                $result = $stmt->get_result();
                
                $phases = [];
                while ($row = $result->fetch_assoc()) {
                    $phases[] = $row;
                }
                
                $response = ['success' => true, 'phases' => $phases];
                break;
                
            case 'get_activities_by_phase':
                // Get activities for a specific phase
                $phase_id = intval($_POST['phase_id']);
                $stmt = $conn->prepare("SELECT id, name FROM activities WHERE phase_id = ? ORDER BY name");
                $stmt->bind_param("i", $phase_id);
                $stmt->execute();
                $result = $stmt->get_result();
                
                $activities = [];
                while ($row = $result->fetch_assoc()) {
                    $activities[] = $row;
                }
                
                $response = ['success' => true, 'activities' => $activities];
                break;
                
            case 'get_project_details':
                // Get project details for editing
                $project_id = intval($_POST['project_id']);
                $stmt = $conn->prepare("SELECT p.*, d.department_name
                                       FROM projects p
                                       LEFT JOIN departments d ON p.department_id = d.id
                                       WHERE p.id = ?");
                $stmt->bind_param("i", $project_id);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($row = $result->fetch_assoc()) {
                    $response = ['success' => true, 'project' => $row];
                } else {
                    $response = ['success' => false, 'message' => 'Project not found'];
                }
                break;
                
            case 'get_phase_details':
                // Get phase details for editing
                $phase_id = intval($_POST['phase_id']);
                $stmt = $conn->prepare("SELECT ph.*, p.name as project_name
                                       FROM phases ph
                                       JOIN projects p ON ph.project_id = p.id
                                       WHERE ph.id = ?");
                $stmt->bind_param("i", $phase_id);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($row = $result->fetch_assoc()) {
                    $response = ['success' => true, 'phase' => $row];
                } else {
                    $response = ['success' => false, 'message' => 'Phase not found'];
                }
                break;
                
            case 'get_activity_details':
                // Get activity details for editing
                $activity_id = intval($_POST['activity_id']);
                $stmt = $conn->prepare("SELECT a.*, ph.name as phase_name, p.name as project_name
                                       FROM activities a
                                       JOIN phases ph ON a.phase_id = ph.id
                                       JOIN projects p ON a.project_id = p.id
                                       WHERE a.id = ?");
                $stmt->bind_param("i", $activity_id);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($row = $result->fetch_assoc()) {
                    $response = ['success' => true, 'activity' => $row];
                } else {
                    $response = ['success' => false, 'message' => 'Activity not found'];
                }
                break;
                
            case 'get_sub_activity_details':
                // Get sub-activity details for editing
                $sub_activity_id = intval($_POST['sub_activity_id']);
                $stmt = $conn->prepare("SELECT s.*, a.name as activity_name, ph.name as phase_name, p.name as project_name
                                       FROM sub_activities s
                                       JOIN activities a ON s.activity_id = a.id
                                       JOIN phases ph ON a.phase_id = ph.id
                                       JOIN projects p ON s.project_id = p.id
                                       WHERE s.id = ?");
                $stmt->bind_param("i", $sub_activity_id);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($row = $result->fetch_assoc()) {
                    $response = ['success' => true, 'sub_activity' => $row];
                } else {
                    $response = ['success' => false, 'message' => 'Sub-activity not found'];
                }
                break;
                
            case 'get_phase_activities':
                // Get activities for a specific phase
                $phase_id = intval($_POST['phase_id']);
                $stmt = $conn->prepare("SELECT a.*, p.name as project_name
                                       FROM activities a
                                       JOIN projects p ON a.project_id = p.id
                                       WHERE a.phase_id = ?
                                       ORDER BY a.created_at DESC");
                $stmt->bind_param("i", $phase_id);
                $stmt->execute();
                $result = $stmt->get_result();
                
                $activities = [];
                while ($row = $result->fetch_assoc()) {
                    $activities[] = $row;
                }
                
                // Get phase details
                $phase_stmt = $conn->prepare("SELECT ph.*, p.name as project_name FROM phases ph 
                                           JOIN projects p ON ph.project_id = p.id 
                                           WHERE ph.id = ?");
                $phase_stmt->bind_param("i", $phase_id);
                $phase_stmt->execute();
                $phase_result = $phase_stmt->get_result();
                $phase = $phase_result->fetch_assoc();
                
                $response = ['success' => true, 'activities' => $activities, 'phase' => $phase];
                break;
                
            case 'get_activity_sub_activities':
                // Get sub-activities for a specific activity
                $activity_id = intval($_POST['activity_id']);
                $stmt = $conn->prepare("SELECT s.*, a.name as activity_name, p.name as project_name
                                       FROM sub_activities s
                                       JOIN activities a ON s.activity_id = a.id
                                       JOIN projects p ON s.project_id = p.id
                                       WHERE s.activity_id = ?
                                       ORDER BY s.created_at DESC");
                $stmt->bind_param("i", $activity_id);
                $stmt->execute();
                $result = $stmt->get_result();
                
                $sub_activities = [];
                while ($row = $result->fetch_assoc()) {
                    $sub_activities[] = $row;
                }
                
                // Get activity details
                $activity_stmt = $conn->prepare("SELECT a.*, p.name as project_name FROM activities a 
                                              JOIN projects p ON a.project_id = p.id 
                                              WHERE a.id = ?");
                $activity_stmt->bind_param("i", $activity_id);
                $activity_stmt->execute();
                $activity_result = $activity_stmt->get_result();
                $activity = $activity_result->fetch_assoc();
                
                $response = ['success' => true, 'sub_activities' => $sub_activities, 'activity' => $activity];
                break;
                
            case 'update_status':
                // Update status for any level with role-based permissions and automatic completion logic
                $level = $_POST['level'];
                $id = intval($_POST['id']);
                $status = $_POST['status'];
                
                $table_map = [
                    'project' => 'projects',
                    'phase' => 'phases',
                    'activity' => 'activities',
                    'sub_activity' => 'sub_activities'
                ];
                
                if (!isset($table_map[$level])) {
                    $response = ['success' => false, 'message' => 'Invalid level'];
                    break;
                }
                
                $table = $table_map[$level];
                
                // ========== ROLE-BASED STATUS UPDATE PERMISSIONS ==========
                // PM Employees can only update activity and sub-activity statuses
                if ($role === 'pm_employee' && ($level === 'project' || $level === 'phase')) {
                    $response = ['success' => false, 'message' => 'Permission denied. PM Employees can only update activity and sub-activity statuses.'];
                    break;
                }
                
                // Only Super Admin and PM Managers can terminate projects
                if ($level === 'project' && $status === 'terminated' && !in_array($role, ['super_admin', 'pm_manager'])) {
                    $response = ['success' => false, 'message' => 'Permission denied. Only Super Admins and PM Managers can terminate projects.'];
                    break;
                }
                
                // Check if user has access to this item
                if ($role !== 'super_admin') {
                    $check_access = false;
                    
                    if ($level === 'project') {
                        // Check if user is assigned to this project
                        $check_stmt = $conn->prepare("SELECT id FROM user_assignments WHERE user_id = ? AND project_id = ?");
                        $check_stmt->bind_param("ii", $user_id, $id);
                        $check_stmt->execute();
                        $check_access = $check_stmt->get_result()->num_rows > 0;
                    } else {
                        // For phases, activities, sub-activities - get project_id first
                        $get_project_stmt = $conn->prepare("SELECT project_id FROM $table WHERE id = ?");
                        $get_project_stmt->bind_param("i", $id);
                        $get_project_stmt->execute();
                        $project_result = $get_project_stmt->get_result()->fetch_assoc();
                        
                        if ($project_result) {
                            $project_id = $project_result['project_id'];
                            $check_stmt = $conn->prepare("SELECT id FROM user_assignments WHERE user_id = ? AND project_id = ?");
                            $check_stmt->bind_param("ii", $user_id, $project_id);
                            $check_stmt->execute();
                            $check_access = $check_stmt->get_result()->num_rows > 0;
                        }
                    }
                    
                    if (!$check_access) {
                        $response = ['success' => false, 'message' => 'Access denied. You are not assigned to this project.'];
                        break;
                    }
                }
                
                // ========== TERMINATED PROJECT LOCK CHECK ==========
                // Check if the item belongs to a terminated project (lock all items except for Super Admin)
                if ($role !== 'super_admin') {
                    $project_id_to_check = $id;
                    
                    if ($level !== 'project') {
                        // Get project_id for non-project items
                        $get_project_stmt = $conn->prepare("SELECT project_id FROM $table WHERE id = ?");
                        $get_project_stmt->bind_param("i", $id);
                        $get_project_stmt->execute();
                        $project_result = $get_project_stmt->get_result()->fetch_assoc();
                        $project_id_to_check = $project_result['project_id'] ?? 0;
                    }
                    
                    // Check if project is terminated
                    $check_terminated = $conn->prepare("SELECT status FROM projects WHERE id = ?");
                    $check_terminated->bind_param("i", $project_id_to_check);
                    $check_terminated->execute();
                    $terminated_result = $check_terminated->get_result()->fetch_assoc();
                    
                    if ($terminated_result && $terminated_result['status'] === 'terminated') {
                        $response = ['success' => false, 'message' => 'Cannot update status: Project is terminated!'];
                        break;
                    }
                }
                
                $conn->begin_transaction();
                
                try {
                    // Get item name and old status for notifications
                    $get_details_stmt = $conn->prepare("SELECT name, status FROM $table WHERE id = ?");
                    $get_details_stmt->bind_param("i", $id);
                    $get_details_stmt->execute();
                    $details_result = $get_details_stmt->get_result()->fetch_assoc();
                    $item_name = $details_result['name'] ?? '';
                    $old_status = $details_result['status'] ?? '';
                    
                    // Get project_id for notifications
                    $project_id = $id;
                    if ($level !== 'project') {
                        $get_project_stmt = $conn->prepare("SELECT project_id FROM $table WHERE id = ?");
                        $get_project_stmt->bind_param("i", $id);
                        $get_project_stmt->execute();
                        $project_result = $get_project_stmt->get_result()->fetch_assoc();
                        $project_id = $project_result['project_id'] ?? 0;
                    }
                    
                    // ========== REVERSE VALIDATION ==========
                    // Check if project can be marked complete (all children must be completed)
                    if ($level === 'project' && $status === 'completed') {
                        // Check all phases are completed
                        $check_phases = $conn->prepare("SELECT COUNT(*) as count FROM phases WHERE project_id = ? AND status != 'completed'");
                        $check_phases->bind_param("i", $id);
                        $check_phases->execute();
                        $phase_result = $check_phases->get_result()->fetch_assoc();
                        
                        if ($phase_result['count'] > 0) {
                            throw new Exception("Cannot complete project. There are phases that are not completed.");
                        }
                        
                        // Check all activities are completed
                        $check_activities = $conn->prepare("
                            SELECT COUNT(*) as count FROM activities a 
                            JOIN phases p ON a.phase_id = p.id 
                            WHERE p.project_id = ? AND a.status != 'completed'
                        ");
                        $check_activities->bind_param("i", $id);
                        $check_activities->execute();
                        $activity_result = $check_activities->get_result()->fetch_assoc();
                        
                        if ($activity_result['count'] > 0) {
                            throw new Exception("Cannot complete project. There are activities that are not completed.");
                        }
                        
                        // Check all sub-activities are completed
                        $check_sub_activities = $conn->prepare("
                            SELECT COUNT(*) as count FROM sub_activities s 
                            JOIN activities a ON s.activity_id = a.id 
                            JOIN phases p ON a.phase_id = p.id 
                            WHERE p.project_id = ? AND s.status != 'completed'
                        ");
                        $check_sub_activities->bind_param("i", $id);
                        $check_sub_activities->execute();
                        $sub_activity_result = $check_sub_activities->get_result()->fetch_assoc();
                        
                        if ($sub_activity_result['count'] > 0) {
                            throw new Exception("Cannot complete project. There are sub-activities that are not completed.");
                        }
                    }
                    
                    // Check if phase can be marked complete (all activities must be completed)
                    if ($level === 'phase' && $status === 'completed') {
                        $check_activities = $conn->prepare("SELECT COUNT(*) as count FROM activities WHERE phase_id = ? AND status != 'completed'");
                        $check_activities->bind_param("i", $id);
                        $check_activities->execute();
                        $activity_result = $check_activities->get_result()->fetch_assoc();
                        
                        if ($activity_result['count'] > 0) {
                            throw new Exception("Cannot complete phase. There are activities that are not completed.");
                        }
                    }
                    
                    // Check if activity can be marked complete (all sub-activities must be completed)
                    if ($level === 'activity' && $status === 'completed') {
                        $check_sub_activities = $conn->prepare("SELECT COUNT(*) as count FROM sub_activities WHERE activity_id = ? AND status != 'completed'");
                        $check_sub_activities->bind_param("i", $id);
                        $check_sub_activities->execute();
                        $sub_activity_result = $check_sub_activities->get_result()->fetch_assoc();
                        
                        if ($sub_activity_result['count'] > 0) {
                            throw new Exception("Cannot complete activity. There are sub-activities that are not completed.");
                        }
                    }
                    
                    // Update the main status
                    $stmt = $conn->prepare("UPDATE $table SET status = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->bind_param("si", $status, $id);
                    
                    if (!$stmt->execute()) {
                        throw new Exception("Failed to update $level status");
                    }
                    
                    // ========== ENHANCED NOTIFICATIONS FOR STATUS UPDATES ==========
                    $notification_message = ucfirst(str_replace('_', ' ', $level)) . " '$item_name' status changed to $status by $username";
                    
                    if ($role === 'pm_employee' && ($level === 'activity' || $level === 'sub_activity')) {
                        // PM Employee updated activity/sub-activity - notify PM Manager
                        notifyPMEmployeeUpdate($conn, $user_id, $level, $item_name, $id, $project_id, $old_status, $status);
                    } else if ($role === 'pm_manager') {
                        // PM Manager updated something - notify Super Admin and assigned PM Employees
                        notifyPMManagerUpdateToSuperAdminAndEmployees($conn, $user_id, $username, $level, $item_name, $id, $project_id, $old_status, $status);
                    } else if ($role === 'super_admin') {
                        // Super Admin updated something - notify assigned PM Managers and Employees
                        notifySuperAdminUpdateToManagersAndEmployees($conn, $user_id, $level, $item_name, $id, $project_id, $old_status, $status);
                    }
                    
                    // ========== AUTOMATIC COMPLETION CASCADING ==========
                    if ($level === 'sub_activity' && $status === 'completed') {
                        // Check if all sub-activities under the activity are completed
                        $get_activity_id = $conn->prepare("SELECT activity_id FROM sub_activities WHERE id = ?");
                        $get_activity_id->bind_param("i", $id);
                        $get_activity_id->execute();
                        $activity_result = $get_activity_id->get_result()->fetch_assoc();
                        $activity_id = $activity_result['activity_id'];
                        
                        $check_all_completed = $conn->prepare("
                            SELECT COUNT(*) as total, 
                                   SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed 
                            FROM sub_activities 
                            WHERE activity_id = ?
                        ");
                        $check_all_completed->bind_param("i", $activity_id);
                        $check_all_completed->execute();
                        $result = $check_all_completed->get_result()->fetch_assoc();
                        
                        if ($result['total'] > 0 && $result['completed'] == $result['total']) {
                            // All sub-activities completed, mark activity as completed
                            $update_activity = $conn->prepare("UPDATE activities SET status = 'completed', updated_at = NOW() WHERE id = ?");
                            $update_activity->bind_param("i", $activity_id);
                            $update_activity->execute();
                            
                            // Now check if all activities under the phase are completed
                            $get_phase_id = $conn->prepare("SELECT phase_id FROM activities WHERE id = ?");
                            $get_phase_id->bind_param("i", $activity_id);
                            $get_phase_id->execute();
                            $phase_result = $get_phase_id->get_result()->fetch_assoc();
                            $phase_id = $phase_result['phase_id'];
                            
                            $check_phase_completed = $conn->prepare("
                                SELECT COUNT(*) as total, 
                                       SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed 
                                FROM activities 
                                WHERE phase_id = ?
                            ");
                            $check_phase_completed->bind_param("i", $phase_id);
                            $check_phase_completed->execute();
                            $phase_result = $check_phase_completed->get_result()->fetch_assoc();
                            
                            if ($phase_result['total'] > 0 && $phase_result['completed'] == $phase_result['total']) {
                                // All activities completed, mark phase as completed
                                $update_phase = $conn->prepare("UPDATE phases SET status = 'completed', updated_at = NOW() WHERE id = ?");
                                $update_phase->bind_param("i", $phase_id);
                                $update_phase->execute();
                                
                                // Now check if all phases under the project are completed
                                $get_project_id = $conn->prepare("SELECT project_id FROM phases WHERE id = ?");
                                $get_project_id->bind_param("i", $phase_id);
                                $get_project_id->execute();
                                $project_result = $get_project_id->get_result()->fetch_assoc();
                                $check_project_id = $project_result['project_id'];
                                
                                $check_project_completed = $conn->prepare("
                                    SELECT COUNT(*) as total, 
                                           SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed 
                                    FROM phases 
                                    WHERE project_id = ?
                                ");
                                $check_project_completed->bind_param("i", $check_project_id);
                                $check_project_completed->execute();
                                $project_result = $check_project_completed->get_result()->fetch_assoc();
                                
                                if ($project_result['total'] > 0 && $project_result['completed'] == $project_result['total']) {
                                    // All phases completed, mark project as completed
                                    $update_project = $conn->prepare("UPDATE projects SET status = 'completed', updated_at = NOW() WHERE id = ?");
                                    $update_project->bind_param("i", $check_project_id);
                                    $update_project->execute();
                                }
                            }
                        }
                    } elseif ($level === 'activity' && $status === 'completed') {
                        // Check if all activities under the phase are completed
                        $get_phase_id = $conn->prepare("SELECT phase_id FROM activities WHERE id = ?");
                        $get_phase_id->bind_param("i", $id);
                        $get_phase_id->execute();
                        $phase_result = $get_phase_id->get_result()->fetch_assoc();
                        $phase_id = $phase_result['phase_id'];
                        
                        $check_phase_completed = $conn->prepare("
                            SELECT COUNT(*) as total, 
                                   SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed 
                            FROM activities 
                            WHERE phase_id = ?
                        ");
                        $check_phase_completed->bind_param("i", $phase_id);
                        $check_phase_completed->execute();
                        $phase_result = $check_phase_completed->get_result()->fetch_assoc();
                        
                        if ($phase_result['total'] > 0 && $phase_result['completed'] == $phase_result['total']) {
                            // All activities completed, mark phase as completed
                            $update_phase = $conn->prepare("UPDATE phases SET status = 'completed', updated_at = NOW() WHERE id = ?");
                            $update_phase->bind_param("i", $phase_id);
                            $update_phase->execute();
                            
                            // Now check if all phases under the project are completed
                            $get_project_id = $conn->prepare("SELECT project_id FROM phases WHERE id = ?");
                            $get_project_id->bind_param("i", $phase_id);
                            $get_project_id->execute();
                            $project_result = $get_project_id->get_result()->fetch_assoc();
                            $check_project_id = $project_result['project_id'];
                            
                            $check_project_completed = $conn->prepare("
                                SELECT COUNT(*) as total, 
                                       SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed 
                                FROM phases 
                                WHERE project_id = ?
                            ");
                            $check_project_completed->bind_param("i", $check_project_id);
                            $check_project_completed->execute();
                            $project_result = $check_project_completed->get_result()->fetch_assoc();
                            
                            if ($project_result['total'] > 0 && $project_result['completed'] == $project_result['total']) {
                                // All phases completed, mark project as completed
                                $update_project = $conn->prepare("UPDATE projects SET status = 'completed', updated_at = NOW() WHERE id = ?");
                                $update_project->bind_param("i", $check_project_id);
                                $update_project->execute();
                            }
                        }
                    } elseif ($level === 'phase' && $status === 'completed') {
                        // Check if all phases under the project are completed
                        $get_project_id = $conn->prepare("SELECT project_id FROM phases WHERE id = ?");
                        $get_project_id->bind_param("i", $id);
                        $get_project_id->execute();
                        $project_result = $get_project_id->get_result()->fetch_assoc();
                        $check_project_id = $project_result['project_id'];
                        
                        $check_project_completed = $conn->prepare("
                            SELECT COUNT(*) as total, 
                                   SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed 
                            FROM phases 
                            WHERE project_id = ?
                        ");
                        $check_project_completed->bind_param("i", $check_project_id);
                        $check_project_completed->execute();
                        $project_result = $check_project_completed->get_result()->fetch_assoc();
                        
                        if ($project_result['total'] > 0 && $project_result['completed'] == $project_result['total']) {
                            // All phases completed, mark project as completed
                            $update_project = $conn->prepare("UPDATE projects SET status = 'completed', updated_at = NOW() WHERE id = ?");
                            $update_project->bind_param("i", $check_project_id);
                            $update_project->execute();
                        }
                    }
                    
                    // If project is terminated, freeze all children
                    if ($level === 'project' && $status === 'terminated') {
                        // Update phases
                        $phase_stmt = $conn->prepare("UPDATE phases SET status = 'frozen' WHERE project_id = ?");
                        $phase_stmt->bind_param("i", $id);
                        $phase_stmt->execute();
                        
                        // Update activities
                        $activity_stmt = $conn->prepare("UPDATE activities a 
                                                         JOIN phases p ON a.phase_id = p.id 
                                                         SET a.status = 'frozen' 
                                                         WHERE p.project_id = ?");
                        $activity_stmt->bind_param("i", $id);
                        $activity_stmt->execute();
                        
                        // Update sub-activities
                        $sub_stmt = $conn->prepare("UPDATE sub_activities s 
                                                   JOIN activities a ON s.activity_id = a.id 
                                                   JOIN phases p ON a.phase_id = p.id 
                                                   SET s.status = 'frozen' 
                                                   WHERE p.project_id = ?");
                        $sub_stmt->bind_param("i", $id);
                        $sub_stmt->execute();
                        
                        // Send notifications to all assigned users about termination
                        $all_assign_stmt = $conn->prepare("SELECT DISTINCT ua.user_id FROM user_assignments ua 
                                                         WHERE ua.project_id = ? AND ua.user_id != ?");
                        $all_assign_stmt->bind_param("ii", $id, $user_id);
                        $all_assign_stmt->execute();
                        $all_assignees = $all_assign_stmt->get_result();
                        
                        while ($assignee = $all_assignees->fetch_assoc()) {
                            createNotification($conn, $assignee['user_id'], "Project Terminated", 
                                "Project '$item_name' has been terminated by $username", 'warning', 'project', $id);
                        }
                    }
                    
                    // If project is no longer terminated, reset children
                    if ($level === 'project' && $status !== 'terminated') {
                        // Reset phases
                        $phase_stmt = $conn->prepare("UPDATE phases SET status = 'pending' WHERE project_id = ? AND status = 'frozen'");
                        $phase_stmt->bind_param("i", $id);
                        $phase_stmt->execute();
                        
                        // Reset activities
                        $activity_stmt = $conn->prepare("UPDATE activities a 
                                                         JOIN phases p ON a.phase_id = p.id 
                                                         SET a.status = 'pending' 
                                                         WHERE p.project_id = ? AND a.status = 'frozen'");
                        $activity_stmt->bind_param("i", $id);
                        $activity_stmt->execute();
                        
                        // Reset sub-activities
                        $sub_stmt = $conn->prepare("UPDATE sub_activities s 
                                                   JOIN activities a ON s.activity_id = a.id 
                                                   JOIN phases p ON a.phase_id = p.id 
                                                   SET s.status = 'pending' 
                                                   WHERE p.project_id = ? AND s.status = 'frozen'");
                        $sub_stmt->bind_param("i", $id);
                        $sub_stmt->execute();
                    }
                    
                    // Log activity
                    $activity_log = $conn->prepare("
                        INSERT INTO activity_logs (user_id, action, description, project_id, created_at) 
                        VALUES (?, ?, ?, ?, NOW())
                    ");
                    
                    $action_text = ucfirst(str_replace('_', ' ', $level)) . " Status Updated";
                    $desc = "$level #$id status changed from $old_status to $status by $username";
                    $activity_log->bind_param("issi", $user_id, $action_text, $desc, $project_id);
                    $activity_log->execute();
                    
                    $conn->commit();
                    $response = ['success' => true, 'message' => 'Status updated successfully!'];
                    
                } catch (Exception $e) {
                    $conn->rollback();
                    $response = ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
                }
                break;
                
            case 'get_notifications':
                // Get notifications for current user
                $stmt = $conn->prepare("SELECT * FROM notifications WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC LIMIT 20");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $result = $stmt->get_result();
                
                $notifications = [];
                while ($row = $result->fetch_assoc()) {
                    $notifications[] = $row;
                }
                
                $response = ['success' => true, 'notifications' => $notifications];
                break;
                
            case 'mark_notification_read':
                // Mark a single notification as read
                $notification_id = intval($_POST['notification_id']);
                $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
                $stmt->bind_param("ii", $notification_id, $user_id);
                $stmt->execute();
                
                $response = ['success' => true, 'message' => 'Notification marked as read'];
                break;
                
            case 'mark_all_notifications_read':
                // Mark all notifications as read for current user
                $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                
                $response = ['success' => true, 'message' => 'All notifications marked as read'];
                break;
                
            case 'get_unread_count':
                // Get unread notification count
                $count = getUnreadNotificationCount($conn, $user_id);
                $response = ['success' => true, 'count' => $count];
                break;
                
            case 'assign_user_to_item':
                // Handle user assignment to any item with notifications
                $assign_user_id = intval($_POST['user_id']);
                $item_type = $_POST['item_type'];
                $item_id = intval($_POST['item_id']);
                $project_id = intval($_POST['project_id']);
                $item_name = $_POST['item_name'];
                
                // Check permissions
                if ($role === 'pm_employee') {
                    $response = ['success' => false, 'message' => 'Permission denied. PM Employees cannot assign users.'];
                    break;
                }
                
                // Check role-based assignment rules
                $assigned_user_role = getRoleById($conn, $assign_user_id);
                
                if ($item_type === 'project' && $assigned_user_role !== 'pm_manager') {
                    $response = ['success' => false, 'message' => 'Only PM Managers can be assigned at project level.'];
                    break;
                }
                
                if ($assigned_user_role === 'pm_manager' && $item_type !== 'project') {
                    $response = ['success' => false, 'message' => 'PM Managers can only be assigned at project level.'];
                    break;
                }
                
                // Use the new assignment function
                $result = assignUserToItem($conn, $user_id, $assign_user_id, $item_type, $item_id, $project_id, $item_name);
                $response = $result;
                break;
                
            case 'revoke_user_from_item':
                // Handle user revocation from any item with notifications
                $revoke_user_id = intval($_POST['user_id']);
                $item_type = $_POST['item_type'];
                $item_id = intval($_POST['item_id']);
                $project_id = intval($_POST['project_id']);
                $item_name = $_POST['item_name'];
                
                // Check permissions
                if ($role === 'pm_employee') {
                    $response = ['success' => false, 'message' => 'Permission denied. PM Employees cannot revoke users.'];
                    break;
                }
                
                // Use the new revocation function
                $result = revokeUserFromItem($conn, $user_id, $revoke_user_id, $item_type, $item_id, $project_id, $item_name);
                $response = $result;
                break;
                
            case 'get_assigned_users':
                // Get users assigned to a specific item
                $item_type = $_POST['item_type'];
                $item_id = intval($_POST['item_id']);
                
                $result = getAssignedUsers($conn, $item_type, $item_id);
                $assigned_users = [];
                while ($row = $result->fetch_assoc()) {
                    $assigned_users[] = $row;
                }
                
                $response = ['success' => true, 'assigned_users' => $assigned_users];
                break;
                
            case 'get_users_by_role':
                // Get users by role for assignment
                $target_role = $_POST['role'];
                
                $stmt = $conn->prepare("SELECT id, username, system_role FROM users WHERE system_role = ? AND id != ? ORDER BY username");
                $stmt->bind_param("si", $target_role, $user_id);
                $stmt->execute();
                $result = $stmt->get_result();
                
                $users = [];
                while ($row = $result->fetch_assoc()) {
                    $users[] = $row;
                }
                
                $response = ['success' => true, 'users' => $users];
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

// ========== FETCH DATA FOR DISPLAY WITH ROLE-BASED ACCESS ==========

// Fetch departments
$departments = [];
$dept_result = $conn->query("SELECT id, department_name FROM departments ORDER BY department_name");
while ($dept = $dept_result->fetch_assoc()) {
    $departments[] = $dept;
}

// Fetch projects based on role using user_assignments table
if ($role === 'super_admin') {
    // Super Admin sees all projects
    $projects_stmt = $conn->prepare("SELECT id, name, description, status FROM projects ORDER BY name");
    $projects_stmt->execute();
    $all_projects = $projects_stmt->get_result();
} else {
    // PM Managers and PM Employees see only assigned projects
    $projects_stmt = $conn->prepare("
        SELECT DISTINCT p.id, p.name, p.description, p.status 
        FROM projects p
        INNER JOIN user_assignments ua ON p.id = ua.project_id
        WHERE ua.user_id = ?
        ORDER BY p.name
    ");
    $projects_stmt->bind_param("i", $user_id);
    $projects_stmt->execute();
    $all_projects = $projects_stmt->get_result();
}

// Store projects in array for dropdowns
$projects_array = [];
$selected_project_name = 'All Projects';
$selected_project_data = null;

while ($project = $all_projects->fetch_assoc()) {
    $projects_array[] = $project;
    if ($selected_project_id == $project['id']) {
        $selected_project_name = $project['name'];
        $selected_project_data = $project;
    }
}

// SET DEFAULT TO "ALL PROJECTS" (0) INSTEAD OF FIRST PROJECT
if ($selected_project_id == 0) {
    $selected_project_name = 'All Projects';
    $selected_project_data = null;
} else if ($selected_project_data) {
    $selected_project_name = $selected_project_data['name'];
}

// Build project access condition for queries
$project_access_condition = "";
$project_access_params = [];
$project_access_types = "";

if ($role === 'super_admin') {
    // Super Admin has access to all projects
    $project_access_condition = "1=1";
} else {
    // Others only have access to assigned projects
    $project_access_condition = "p.id IN (
        SELECT project_id FROM user_assignments WHERE user_id = ?
    )";
    $project_access_params[] = $user_id;
    $project_access_types = "i";
}

// Fetch phases based on selected project with access control
if ($selected_project_id > 0) {
    // Check if user has access to the selected project
    if ($role !== 'super_admin') {
        $check_access = $conn->prepare("SELECT id FROM user_assignments WHERE user_id = ? AND project_id = ?");
        $check_access->bind_param("ii", $user_id, $selected_project_id);
        $check_access->execute();
        if ($check_access->get_result()->num_rows === 0) {
            // User doesn't have access, redirect to all projects
            $selected_project_id = 0;
            $selected_project_name = 'All Projects';
            $selected_project_data = null;
        }
    }
    
    if ($selected_project_id > 0) {
        $phases_query = "SELECT ph.*, p.name AS project_name 
                        FROM phases ph 
                        JOIN projects p ON ph.project_id = p.id 
                        WHERE ph.project_id = ? 
                        ORDER BY ph.phase_order, ph.created_at DESC";
        $stmt = $conn->prepare($phases_query);
        $stmt->bind_param("i", $selected_project_id);
        $stmt->execute();
        $phases = $stmt->get_result();
    } else {
        // When no access to selected project, show all accessible phases
        $phases_query = "SELECT ph.*, p.name AS project_name 
                        FROM phases ph 
                        JOIN projects p ON ph.project_id = p.id 
                        WHERE $project_access_condition 
                        ORDER BY p.name, ph.phase_order, ph.name";
        
        if ($role === 'super_admin') {
            $stmt = $conn->prepare($phases_query);
        } else {
            $stmt = $conn->prepare($phases_query);
            $stmt->bind_param($project_access_types, ...$project_access_params);
        }
        $stmt->execute();
        $phases = $stmt->get_result();
    }
} else {
    // When "All Projects" is selected, show all phases user has access to
    $phases_query = "SELECT ph.*, p.name AS project_name 
                    FROM phases ph 
                    JOIN projects p ON ph.project_id = p.id 
                    WHERE $project_access_condition 
                    ORDER BY p.name, ph.phase_order, ph.name";
    
    if ($role === 'super_admin') {
        $stmt = $conn->prepare($phases_query);
    } else {
        $stmt = $conn->prepare($phases_query);
        $stmt->bind_param($project_access_types, ...$project_access_params);
    }
    $stmt->execute();
    $phases = $stmt->get_result();
}

// Fetch activities based on selected project with access control
if ($selected_project_id > 0) {
    $activities_query = "SELECT a.*, ph.name as phase_name, p.name as project_name
                       FROM activities a
                       JOIN phases ph ON a.phase_id = ph.id
                       JOIN projects p ON a.project_id = p.id
                       WHERE a.project_id = ?
                       ORDER BY a.created_at DESC";
    $stmt = $conn->prepare($activities_query);
    $stmt->bind_param("i", $selected_project_id);
    $stmt->execute();
    $activities = $stmt->get_result();
} else {
    $activities_query = "SELECT a.*, ph.name as phase_name, p.name as project_name
                       FROM activities a
                       JOIN phases ph ON a.phase_id = ph.id
                       JOIN projects p ON a.project_id = p.id
                       WHERE $project_access_condition
                       ORDER BY p.name, a.created_at DESC";
    
    if ($role === 'super_admin') {
        $stmt = $conn->prepare($activities_query);
    } else {
        $stmt = $conn->prepare($activities_query);
        $stmt->bind_param($project_access_types, ...$project_access_params);
    }
    $stmt->execute();
    $activities = $stmt->get_result();
}

// Fetch sub-activities based on selected project with access control
if ($selected_project_id > 0) {
    $sub_activities_query = "SELECT s.*, a.name as activity_name, ph.name as phase_name, p.name as project_name
                           FROM sub_activities s
                           JOIN activities a ON s.activity_id = a.id
                           JOIN phases ph ON a.phase_id = ph.id
                           JOIN projects p ON s.project_id = p.id
                           WHERE s.project_id = ?
                           ORDER BY s.created_at DESC";
    $stmt = $conn->prepare($sub_activities_query);
    $stmt->bind_param("i", $selected_project_id);
    $stmt->execute();
    $sub_activities = $stmt->get_result();
} else {
    $sub_activities_query = "SELECT s.*, a.name as activity_name, ph.name as phase_name, p.name as project_name
                           FROM sub_activities s
                           JOIN activities a ON s.activity_id = a.id
                           JOIN phases ph ON a.phase_id = ph.id
                           JOIN projects p ON s.project_id = p.id
                           WHERE $project_access_condition
                           ORDER BY p.name, s.created_at DESC";
    
    if ($role === 'super_admin') {
        $stmt = $conn->prepare($sub_activities_query);
    } else {
        $stmt = $conn->prepare($sub_activities_query);
        $stmt->bind_param($project_access_types, ...$project_access_params);
    }
    $stmt->execute();
    $sub_activities = $stmt->get_result();
}

// Count statistics
$total_projects = count($projects_array);
$total_phases = $phases->num_rows;
$total_activities = $activities->num_rows;
$total_sub_activities = $sub_activities->num_rows;

// Get unread notification count
$unread_notification_count = getUnreadNotificationCount($conn, $user_id);

// Reset pointers
$phases->data_seek(0);
$activities->data_seek(0);
$sub_activities->data_seek(0);
$all_projects->data_seek(0);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unified Project Management - Dashen Bank</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css">
    
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --dashen-primary: #273274;
            --dashen-secondary: #1e5af5;
            --dashen-accent: #f8a01c;
            --dashen-success: #2dce89;
            --dashen-warning: #fb6340;
            --dashen-danger: #f5365c;
            --dashen-info: #11cdef;
            --dashen-dark: #32325d;
            --dashen-light: #f8f9fe;
            --sidebar-width: 280px;
            --sidebar-collapsed-width: 80px;
            --border-radius: 20px;
            --box-shadow: 0 20px 40px rgba(0, 0, 0, 0.08);
            --box-shadow-hover: 0 30px 60px rgba(39, 50, 116, 0.12);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f8f9fe 0%, #eef2f9 100%);
            color: var(--dashen-dark);
            min-height: 100vh;
            overflow-x: hidden;
        }
        
        /* Static Header */
        .static-header {
            position: fixed;
            top: 0;
            left: var(--sidebar-width);
            right: 0;
            height: 80px;
            background: linear-gradient(135deg, var(--dashen-primary) 0%, #1e275a 100%);
            color: white;
            padding: 0 30px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            z-index: 999;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            border-bottom: 1px solid rgba(255,255,255,0.1);
            transition: left 0.3s ease;
        }
        
        .static-header.sidebar-collapsed {
            left: var(--sidebar-collapsed-width);
        }
        
        .static-header h1 {
            font-size: 1.6rem;
            font-weight: 700;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .static-header h1 i {
            font-size: 2rem;
            color: var(--dashen-accent);
        }
        
        /* User Profile Dropdown Styles */
        .user-profile {
            position: relative;
            cursor: pointer;
        }
        
        .user-profile-trigger {
            display: flex;
            align-items: center;
            gap: 12px;
            background: rgba(255,255,255,0.1);
            padding: 8px 16px;
            border-radius: 40px;
            border: 1px solid rgba(255,255,255,0.2);
            transition: all 0.3s ease;
        }
        
        .user-profile-trigger:hover {
            background: rgba(255,255,255,0.2);
            transform: translateY(-2px);
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--dashen-accent), #ffb347);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.2rem;
            color: var(--dashen-dark);
            border: 2px solid white;
        }
        
        .user-info-compact {
            display: flex;
            flex-direction: column;
        }
        
        .user-name-compact {
            font-weight: 600;
            font-size: 0.95rem;
            line-height: 1.3;
        }
        
        .user-role-compact {
            font-size: 0.75rem;
            opacity: 0.8;
        }
        
        .profile-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            margin-top: 10px;
            width: 260px;
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.15);
            overflow: hidden;
            display: none;
            z-index: 1100;
            animation: slideDown 0.3s ease;
        }
        
        .profile-dropdown.show {
            display: block;
        }
        
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .dropdown-header {
            padding: 20px;
            background: linear-gradient(135deg, #f8f9fa, #ffffff);
            border-bottom: 1px solid #e9ecef;
        }
        
        .dropdown-user-name {
            font-weight: 700;
            color: var(--dashen-dark);
            margin-bottom: 4px;
        }
        
        .dropdown-user-email {
            font-size: 0.85rem;
            color: #6c757d;
        }
        
        .dropdown-menu-items {
            padding: 10px 0;
        }
        
        .dropdown-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 20px;
            color: var(--dashen-dark);
            text-decoration: none;
            transition: all 0.2s ease;
        }
        
        .dropdown-item:hover {
            background: #f8f9fa;
        }
        
        .dropdown-item i {
            width: 20px;
            color: var(--dashen-primary);
        }
        
        .dropdown-item.logout {
            color: var(--dashen-danger);
        }
        
        .dropdown-item.logout i {
            color: var(--dashen-danger);
        }
        
        .dropdown-divider {
            height: 1px;
            background: #e9ecef;
            margin: 8px 0;
        }
        
        /* Content Area */
        .content {
            margin-left: var(--sidebar-width);
            margin-top: 80px;
            padding: 30px;
            min-height: calc(100vh - 80px);
            transition: margin-left 0.3s ease;
            width: calc(100% - var(--sidebar-width));
        }
        
        .content.sidebar-collapsed {
            margin-left: var(--sidebar-collapsed-width);
            width: calc(100% - var(--sidebar-collapsed-width));
        }
        
        @media (max-width: 992px) {
            .static-header {
                left: 0;
            }
            .content {
                margin-left: 0;
                width: 100%;
            }
            .static-header.sidebar-collapsed,
            .content.sidebar-collapsed {
                left: 0;
                margin-left: 0;
            }
        }
        
        /* Notification Bell */
        .notification-bell {
            position: relative;
            cursor: pointer;
            padding: 0.5rem;
            border-radius: 50%;
            transition: var(--dashen-transition);
            display: inline-block;
        }
        
        .notification-bell:hover {
            background: rgba(255,255,255,0.1);
            transform: scale(1.1);
        }
        
        .notification-count {
            position: absolute;
            top: -5px;
            right: -5px;
            background: var(--dashen-accent);
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            font-size: 0.7rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }
        
        /* Notification Dropdown */
        .notification-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            width: 400px;
            max-height: 500px;
            overflow-y: auto;
            background: white;
            border: 2px solid rgba(39, 50, 116, 0.1);
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            z-index: 1000;
            display: none;
        }
        
        .notification-header {
            padding: 1rem 1.5rem;
            background: linear-gradient(135deg, var(--dashen-primary), #1e275a);
            color: white;
            font-weight: 600;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .notification-item {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid rgba(39, 50, 116, 0.05);
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .notification-item:hover {
            background: rgba(39, 50, 116, 0.03);
        }
        
        .notification-item.unread {
            background: rgba(33, 150, 243, 0.05);
            border-left: 3px solid var(--dashen-accent);
        }
        
        .notification-title {
            font-weight: 600;
            color: var(--dashen-primary);
            margin-bottom: 0.25rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .notification-message {
            font-size: 0.85rem;
            color: #666;
            margin-bottom: 0.5rem;
        }
        
        .notification-time {
            font-size: 0.75rem;
            color: #999;
        }
        
        .notification-footer {
            padding: 0.75rem 1.5rem;
            text-align: center;
            border-top: 1px solid rgba(39, 50, 116, 0.1);
            background: #f8f9fa;
        }
        
        /* Project Selection Bar */
        .project-selection-bar {
            background: linear-gradient(135deg, #273274 0%, #1a245a 30%, #321e8c 30.1%, #ffffff 100%);
            border-radius: var(--border-radius);
            padding: 1.5rem 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--box-shadow);
            border-left: 5px solid var(--dashen-accent);
            position: relative;
            overflow: hidden;
        }
        
        .project-select-wrapper {
            max-width: 400px;
        }
        
        .project-select-wrapper .form-label {
            font-weight: 600;
            color: white;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.5rem;
        }
        
        .badge.bg-light {
            background: rgba(255,255,255,0.2) !important;
            color: white !important;
            padding: 0.5rem 1rem;
            font-size: 0.9rem;
        }
        
        /* Page Header */
        .page-header {
            background: white;
            border-radius: var(--border-radius);
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--box-shadow);
            border-left: 5px solid var(--dashen-accent);
            position: relative;
            overflow: hidden;
        }
        
        .page-header h5 {
            font-weight: 700;
            color: var(--dashen-primary);
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
        }
        
        .page-header h5 i {
            color: var(--dashen-accent);
            margin-right: 0.75rem;
        }
        
        /* Stat Cards */
        .stat-card {
            text-align: center;
            padding: 2rem 1.5rem;
            border-radius: var(--border-radius);
            background: white;
            box-shadow: var(--box-shadow);
            margin-bottom: 1.5rem;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--dashen-primary), var(--dashen-accent));
        }
        
        .stat-card:hover {
            transform: translateY(-8px);
            box-shadow: var(--box-shadow-hover);
        }
        
        .stat-number {
            font-size: 3rem;
            font-weight: 800;
            margin: 1rem 0;
            color: var(--dashen-primary);
            line-height: 1;
        }
        
        .stat-label {
            color: #6c757d;
            font-size: 0.9rem;
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: 1px;
        }
        
        .stat-icon {
            font-size: 2.5rem;
            color: var(--dashen-primary);
            opacity: 0.9;
            margin-bottom: 1rem;
            background: rgba(39, 50, 116, 0.05);
            width: 70px;
            height: 70px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            margin: 0 auto 1rem;
        }
        
        /* Quick Actions */
        .quick-actions {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }
        
        .quick-action-btn {
            background: white;
            border: 2px solid rgba(39, 50, 116, 0.1);
            border-radius: 8px;
            padding: 0.75rem 1.5rem;
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--dashen-primary);
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            cursor: pointer;
        }
        
        .quick-action-btn:hover {
            background: linear-gradient(135deg, var(--dashen-primary), #1e275a);
            color: white;
            border-color: var(--dashen-primary);
            transform: translateY(-3px);
            box-shadow: var(--box-shadow);
        }
        
        .quick-action-btn i {
            margin-right: 0.5rem;
            font-size: 1rem;
        }
        
        /* Tab Navigation */
        .tab-nav {
            background: white;
            border-radius: var(--border-radius);
            padding: 0;
            margin-bottom: 2rem;
            box-shadow: var(--box-shadow);
            overflow: hidden;
        }
        
        .nav-tabs-custom {
            border-bottom: none;
            background: #f8f9fa;
            padding: 0.5rem 0.5rem 0;
        }
        
        .nav-tabs-custom .nav-link {
            border: none;
            border-radius: 10px 10px 0 0;
            padding: 1rem 2rem;
            color: #6c757d;
            font-weight: 600;
            margin-right: 0.5rem;
            transition: all 0.3s ease;
            background: transparent;
        }
        
        .nav-tabs-custom .nav-link:hover {
            color: var(--dashen-primary);
            background: rgba(255,255,255,0.9);
        }
        
        .nav-tabs-custom .nav-link.active {
            color: var(--dashen-primary);
            background: white;
            box-shadow: 0 -2px 10px rgba(0,0,0,0.1);
        }
        
        .tab-count {
            background: linear-gradient(135deg, var(--dashen-primary), #1e275a);
            color: white;
            border-radius: 50%;
            width: 26px;
            height: 26px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
            font-weight: 700;
            margin-left: 0.5rem;
        }
        
        /* Data Tables */
        .data-table-wrapper {
            background: white;
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--box-shadow);
            margin-bottom: 2rem;
        }
        
        .table-header {
            background: linear-gradient(135deg, var(--dashen-primary), #1e275a);
            color: white;
            padding: 1.25rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .table-container {
            padding: 0;
            overflow: hidden;
        }
        
        .table-sm {
            margin: 0;
        }
        
        .table-sm th, .table-sm td {
            padding: 1rem 1.25rem;
            font-size: 0.9rem;
            border-bottom: 1px solid rgba(39, 50, 116, 0.05);
            vertical-align: middle;
        }
        
        .table-sm th {
            background: rgba(39, 50, 116, 0.03);
            font-weight: 700;
            color: var(--dashen-primary);
            border-bottom: 2px solid var(--dashen-primary);
            text-transform: uppercase;
            font-size: 0.85rem;
        }
        
        .table-sm tbody tr:hover {
            background-color: rgba(39, 50, 116, 0.02);
        }
        
        /* Action Buttons */
        .action-btn-sm {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin: 0 3px;
            font-size: 0.9rem;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        
        .action-btn-sm:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 10px rgba(0,0,0,0.15);
        }
        
        .btn-view-sm { 
            background: linear-gradient(135deg, var(--dashen-success), #2dce89);
            color: white;
        }
        
        .btn-edit-sm { 
            background: linear-gradient(135deg, var(--dashen-warning), #fb6340);
            color: white;
        }
        
        .btn-delete-sm { 
            background: linear-gradient(135deg, var(--dashen-danger), #f5365c);
            color: white;
        }
        
        /* Status Badges */
        .badge-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.8rem;
            font-weight: 700;
            border-radius: 20px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border: 2px solid transparent;
            min-width: 100px;
            text-align: center;
            display: inline-block;
        }
        
        .badge-sm:hover:not(.readonly) {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .badge-status-pending { 
            background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
            color: #856404;
            border-color: #ffc107;
        }
        
        .badge-status-in_progress { 
            background: linear-gradient(135deg, #d1ecf1 0%, #a6e0e9 100%);
            color: #0c5460;
            border-color: #17a2b8;
        }
        
        .badge-status-completed { 
            background: linear-gradient(135deg, #d4edda 0%, #b1dfbb 100%);
            color: #155724;
            border-color: #28a745;
        }
        
        .badge-status-terminated { 
            background: linear-gradient(135deg, #f8d7da 0%, #f1b0b7 100%);
            color: #721c24;
            border-color: #dc3545;
        }
        
        .badge-status-frozen { 
            background: linear-gradient(135deg, #e2e3e5 0%, #c8cbcf 100%);
            color: #383d41;
            border-color: #6c757d;
        }
        
        /* Status Dropdown */
        .status-dropdown {
            position: absolute;
            background: white;
            border: 2px solid rgba(39, 50, 116, 0.1);
            border-radius: 8px;
            box-shadow: var(--box-shadow);
            z-index: 1000;
            min-width: 140px;
            display: none;
            overflow: hidden;
        }
        
        .status-option {
            padding: 0.75rem 1rem;
            cursor: pointer;
            border-bottom: 1px solid rgba(39, 50, 116, 0.05);
            transition: all 0.3s ease;
            font-weight: 500;
        }
        
        .status-option:hover {
            background: rgba(39, 50, 116, 0.05);
            color: var(--dashen-primary);
        }
        
        .status-container {
            position: relative;
            display: inline-block;
        }
        
        /* Dynamic Field Groups */
        .dynamic-field-group {
            border: 2px solid rgba(39, 50, 116, 0.1);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            background: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: all 0.3s ease;
            position: relative;
        }
        
        .dynamic-field-group:hover {
            border-color: var(--dashen-primary);
            box-shadow: var(--box-shadow);
        }
        
        .dynamic-field-group .field-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid rgba(39, 50, 116, 0.1);
        }
        
        .dynamic-field-group .field-count {
            font-size: 0.9rem;
            color: var(--dashen-primary);
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            background: rgba(39, 50, 116, 0.05);
            padding: 0.25rem 1rem;
            border-radius: 20px;
        }
        
        .add-field-btn, .remove-field-btn {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: white;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            margin: 0 2px;
        }
        
        .add-field-btn {
            background: linear-gradient(135deg, var(--dashen-success), #2dce89);
        }
        
        .remove-field-btn {
            background: linear-gradient(135deg, var(--dashen-danger), #f5365c);
        }
        
        .add-field-btn:hover, .remove-field-btn:hover {
            transform: scale(1.1);
            box-shadow: 0 5px 10px rgba(0,0,0,0.15);
        }
        
        /* Loading Overlay */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(39, 50, 116, 0.9);
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            display: none;
            backdrop-filter: blur(5px);
        }
        
        .spinner {
            width: 70px;
            height: 70px;
            border: 4px solid rgba(255,255,255,0.1);
            border-top: 4px solid white;
            border-radius: 50%;
            animation: spin 1s cubic-bezier(0.68, -0.55, 0.27, 1.55) infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Toast Notification */
        .toast-notification {
            position: fixed;
            top: 30px;
            right: 30px;
            z-index: 10000;
            min-width: 350px;
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow-hover);
            overflow: hidden;
            transform: translateX(400px);
            transition: transform 0.4s cubic-bezier(0.68, -0.55, 0.27, 1.55);
            border-left: 5px solid var(--dashen-primary);
        }
        
        .toast-notification.show {
            transform: translateX(0);
        }
        
        .toast-header {
            background: linear-gradient(135deg, var(--dashen-primary), #1e275a);
            color: white;
            padding: 1rem 1.5rem;
            border-bottom: none;
            font-weight: 600;
        }
        
        .toast-body {
            padding: 1.5rem;
            font-size: 0.95rem;
        }
        
        /* Modal Styles */
        .modal-content {
            border: none;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow-hover);
            overflow: hidden;
        }
        
        .modal-header {
            background: linear-gradient(135deg, var(--dashen-primary), #1e275a);
            color: white;
            padding: 1.5rem 2rem;
            border-bottom: none;
        }
        
        .modal-title {
            font-size: 1.25rem;
            font-weight: 700;
        }
        
        .modal-title i {
            margin-right: 0.75rem;
            color: var(--dashen-accent);
        }
        
        .modal-body {
            padding: 2rem;
        }
        
        .modal-footer {
            padding: 1.5rem 2rem;
            border-top: 1px solid rgba(39, 50, 116, 0.1);
            background: #f8f9fa;
        }
        
        /* Form Controls */
        .form-control-sm, .form-select-sm {
            border: 2px solid rgba(39, 50, 116, 0.1);
            border-radius: 8px;
            padding: 0.75rem 1rem;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }
        
        .form-control-sm:focus, .form-select-sm:focus {
            border-color: var(--dashen-primary);
            box-shadow: 0 0 0 3px rgba(39, 50, 116, 0.1);
        }
        
        .form-label {
            font-weight: 600;
            color: var(--dashen-primary);
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .static-header h1 {
                font-size: 1.2rem;
            }
            .static-header h1 i {
                font-size: 1.5rem;
            }
            .user-profile-trigger {
                padding: 4px 8px;
            }
            .user-avatar {
                width: 32px;
                height: 32px;
                font-size: 1rem;
            }
            .user-name-compact {
                font-size: 0.85rem;
            }
            .user-role-compact {
                display: none;
            }
            .notification-dropdown {
                width: 300px;
            }
            .quick-actions {
                flex-direction: column;
            }
            .quick-action-btn {
                width: 100%;
                justify-content: center;
            }
            .stat-number {
                font-size: 2rem;
            }
        }
    </style>
</head>
<body>
    <!-- Static Header with Dynamic User Profile Dropdown -->
    <header class="static-header" id="staticHeader">
        <h1>
            <i class="fas fa-project-diagram"></i>
            Unified Project Management
        </h1>
        
        <!-- Dynamic User Profile Dropdown -->
        <div class="user-profile" id="userProfile">
            <div class="user-profile-trigger" id="profileTrigger">
                <div class="user-avatar">
                    <?= strtoupper(substr($username, 0, 1)) ?>
                </div>
                <div class="user-info-compact">
                    <span class="user-name-compact"><?= htmlspecialchars($username) ?></span>
                    <span class="user-role-compact"><?= ucfirst(str_replace('_', ' ', $role)) ?></span>
                </div>
                <i class="fas fa-chevron-down" style="font-size: 0.8rem; opacity: 0.8;"></i>
            </div>
            
            <!-- Profile Dropdown Menu -->
            <div class="profile-dropdown" id="profileDropdown">
                <div class="dropdown-header">
                    <div class="dropdown-user-name"><?= htmlspecialchars($username) ?></div>
                    <div class="dropdown-user-email"><?= htmlspecialchars($user_email) ?></div>
                    <div style="margin-top: 8px;">
                        <span class="badge bg-primary"><?= ucfirst(str_replace('_', ' ', $role)) ?></span>
                    </div>
                </div>
                <div class="dropdown-menu-items">
                    <a href="profile.php" class="dropdown-item">
                        <i class="fas fa-user-circle"></i>
                        My Profile
                    </a>
                    <a href="change_password.php" class="dropdown-item">
                        <i class="fas fa-key"></i>
                        Change Password
                    </a>
                    <div class="dropdown-divider"></div>
                    <a href="logout.php" class="dropdown-item logout">
                        <i class="fas fa-sign-out-alt"></i>
                        Logout
                    </a>
                </div>
            </div>
        </div>
    </header>

    <!-- Include Sidebar -->
    <?php include 'sidebar.php'; ?>
    
    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner"></div>
    </div>
    
    <!-- Toast Notification -->
    <div class="toast-notification" id="toastNotification">
        <div class="toast-header">
            <strong class="me-auto">Notification</strong>
            <button type="button" class="btn-close btn-close-white" onclick="hideToast()"></button>
        </div>
        <div class="toast-body" id="toastMessage"></div>
    </div>
    
    <!-- Main Content Area -->
    <div class="content" id="mainContent">
        <!-- Notification Container -->
        <div class="notification-container" style="position: fixed; top: 100px; right: 30px; z-index: 1000;">
            <div class="notification-bell" onclick="toggleNotifications()" id="notificationBell">
                <i class="fas fa-bell fa-lg" style="color: white;"></i>
                <?php if ($unread_notification_count > 0): ?>
                <span class="notification-count" id="notificationCount"><?= $unread_notification_count ?></span>
                <?php endif; ?>
            </div>
            <div class="notification-dropdown" id="notificationDropdown">
                <div class="notification-header">
                    <span>Notifications</span>
                    <button class="btn btn-sm btn-outline-light" onclick="markAllNotificationsAsRead()">
                        <i class="fas fa-check-double me-1"></i> Mark All Read
                    </button>
                </div>
                <div id="notificationList">
                    <!-- Notifications will be loaded here -->
                </div>
                <div class="notification-footer">
                    <small class="text-muted">Click to mark as read</small>
                </div>
            </div>
        </div>

        <!-- Project Selection Bar -->
        <div class="project-selection-bar">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <div class="project-select-wrapper">
                        <label class="form-label mb-1"><i class="fas fa-project-diagram me-2"></i>Select Project</label>
                        <div class="input-group">
                            <select class="form-select form-select-sm" id="projectSelector" onchange="changeProject(this.value)">
                                <option value="0" <?= $selected_project_id == 0 ? 'selected' : '' ?>>All Projects</option>
                                <?php foreach ($projects_array as $project): ?>
                                    <option value="<?= $project['id'] ?>" <?= $selected_project_id == $project['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($project['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn-sm btn-outline-primary" type="button" onclick="refreshProjectData()" id="refreshProjectBtn">
                                <i class="fas fa-sync-alt"></i>
                            </button>
                        </div>
                        <?php if ($selected_project_id > 0 && $selected_project_data): ?>
                        <div class="mt-2">
                            <span class="badge bg-primary">Selected: <?= htmlspecialchars($selected_project_name) ?></span>
                            <?php if ($selected_project_data['description']): ?>
                            <small class="text-white-50 ms-2"><?= htmlspecialchars(substr($selected_project_data['description'], 0, 100)) ?></small>
                            <?php endif; ?>
                        </div>
                        <?php elseif ($selected_project_id == 0): ?>
                        <div class="mt-2">
                            <span class="badge bg-light">Selected: All Projects</span>
                            <small class="text-white-50 ms-2">Viewing all accessible projects</small>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-md-4 text-end">
                    <span class="badge bg-light text-dark ms-2">
                        <i class="fas fa-user me-1"></i> <?= htmlspecialchars($username) ?> (<?= ucfirst(str_replace('_', ' ', $role)) ?>)
                    </span>
                </div>
            </div>
        </div>

        <!-- Page Header -->
        <div class="page-header">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <h5 class="mb-0">
                        <i class="fas fa-project-diagram me-2"></i>Project Management
                        <small class="text-muted"> - <?= htmlspecialchars($selected_project_name) ?></small>
                    </h5>
                    <p class="text-muted mb-0 small">Manage projects, phases, activities, and sub-activities</p>
                </div>
                <div class="col-md-6 text-end">
                    <!-- Quick Actions -->
                </div>
            </div>
        </div>

        <!-- Quick Stats -->
        <div class="row mb-3">
            <div class="col-xl-3 col-md-6">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-cubes"></i>
                    </div>
                    <div class="stat-number" id="statProjects"><?= $total_projects ?></div>
                    <div class="stat-label">Projects</div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-tasks"></i>
                    </div>
                    <div class="stat-number" id="statPhases"><?= $total_phases ?></div>
                    <div class="stat-label">Phases</div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-list-check"></i>
                    </div>
                    <div class="stat-number" id="statActivities"><?= $total_activities ?></div>
                    <div class="stat-label">Activities</div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-list-ol"></i>
                    </div>
                    <div class="stat-number" id="statSubActivities"><?= $total_sub_activities ?></div>
                    <div class="stat-label">Sub-Activities</div>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="row mb-3">
            <div class="col-12">
                <div class="compact-card">
                    <div class="compact-card-header">
                        <i class="fas fa-bolt me-2"></i>Quick Actions for <?= htmlspecialchars($selected_project_name) ?>
                    </div>
                    <div class="compact-card-body">
                        <div class="quick-actions">
                            <?php if ($role === 'super_admin'): ?>
                            <button type="button" class="quick-action-btn" data-bs-toggle="modal" data-bs-target="#addProjectModal">
                                <i class="fas fa-plus-circle me-1"></i> Add Project
                            </button>
                            <?php endif; ?>
                            
                            <?php if (in_array($role, ['super_admin', 'pm_manager'])): ?>
                            <button type="button" class="quick-action-btn" data-bs-toggle="modal" data-bs-target="#addPhaseModal">
                                <i class="fas fa-plus-circle me-1"></i> Add Phase
                            </button>
                            <?php endif; ?>
                            
                            <?php if (in_array($role, ['super_admin', 'pm_manager'])): ?>
                            <button type="button" class="quick-action-btn" data-bs-toggle="modal" data-bs-target="#addActivityModal">
                                <i class="fas fa-plus-circle me-1"></i> Add Activity
                            </button>
                            <?php endif; ?>
                            
                            <?php if (in_array($role, ['super_admin', 'pm_manager'])): ?>
                            <button type="button" class="quick-action-btn" data-bs-toggle="modal" data-bs-target="#addSubActivityModal">
                                <i class="fas fa-plus-circle me-1"></i> Add Sub-Activity
                            </button>
                            <?php endif; ?>
                            
                            <button type="button" class="quick-action-btn" onclick="refreshData()" id="refreshDataBtn">
                                <i class="fas fa-sync-alt me-1"></i> Refresh
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tab Navigation -->
        <div class="tab-nav">
            <ul class="nav nav-tabs-custom" id="managementTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="projects-tab" data-bs-toggle="tab" data-bs-target="#projects-tab-pane" type="button" role="tab">
                        <i class="fas fa-project-diagram me-1"></i>Projects
                        <span class="tab-count" id="tabProjectsCount"><?= $total_projects ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="phases-tab" data-bs-toggle="tab" data-bs-target="#phases-tab-pane" type="button" role="tab">
                        <i class="fas fa-tasks me-1"></i>Phases
                        <span class="tab-count" id="tabPhasesCount"><?= $total_phases ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="activities-tab" data-bs-toggle="tab" data-bs-target="#activities-tab-pane" type="button" role="tab">
                        <i class="fas fa-list-check me-1"></i>Activities
                        <span class="tab-count" id="tabActivitiesCount"><?= $total_activities ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="sub-activities-tab" data-bs-toggle="tab" data-bs-target="#sub-activities-tab-pane" type="button" role="tab">
                        <i class="fas fa-list-ol me-1"></i>Sub-Activities
                        <span class="tab-count" id="tabSubActivitiesCount"><?= $total_sub_activities ?></span>
                    </button>
                </li>
            </ul>
            
            <div class="tab-content" id="managementTabsContent">
                <!-- Projects Tab -->
                <div class="tab-pane fade show active" id="projects-tab-pane" role="tabpanel">
                    <div class="data-table-wrapper">
                        <div class="table-header">
                            <div>
                                <strong>Projects</strong>
                                <small class="ms-2">Total: <span id="tableProjectsCount"><?= $total_projects ?></span></small>
                            </div>
                            <div class="d-flex gap-2">
                                <div class="input-group input-group-sm" style="width: 250px;">
                                    <input type="text" class="form-control form-control-sm" id="searchProjects" placeholder="Search projects...">
                                    <button class="btn btn-outline-secondary" type="button" onclick="searchProjects()">
                                        <i class="fas fa-search"></i>
                                    </button>
                                </div>
                                <div>
                                    <?php if ($role === 'super_admin'): ?>
                                    <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addProjectModal">
                                        <i class="fas fa-plus me-1"></i> Add Project
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="table-container">
                            <?php if ($total_projects > 0): ?>
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover" id="projectsTable">
                                        <thead>
                                            <tr>
                                                <th width="50">ID</th>
                                                <th>Project Name</th>
                                                <th>Description</th>
                                                <th>Status</th>
                                                <th width="150" class="text-center">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody id="projectsTableBody">
                                            <?php foreach ($projects_array as $project): ?>
                                            <?php 
                                            // Check if project is terminated - lock all actions except for Super Admin
                                            $is_terminated = ($project['status'] === 'terminated');
                                            $can_edit_status = true;
                                            
                                            if ($role === 'pm_employee') {
                                                // PM Employees cannot edit project status at all
                                                $can_edit_status = false;
                                            } elseif ($role === 'pm_manager' && $is_terminated) {
                                                // PM Managers cannot change terminated status (only Super Admin can)
                                                $can_edit_status = false;
                                            }
                                            ?>
                                            <tr>
                                                <td class="fw-bold">#<?= $project['id'] ?></td>
                                                <td>
                                                    <div class="fw-medium"><?= htmlspecialchars($project['name']) ?></div>
                                                </td>
                                                <td>
                                                    <div class="small text-truncate" style="max-width: 200px;">
                                                        <?= !empty($project['description']) ? htmlspecialchars($project['description']) : '<span class="text-muted">No description</span>' ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="status-container">
                                                        <?php if (!$can_edit_status): ?>
                                                            <!-- Read-only status -->
                                                            <span class="badge badge-sm <?= 'badge-status-' . $project['status'] ?> readonly">
                                                                <?= ucfirst(str_replace('_', ' ', $project['status'])) ?>
                                                            </span>
                                                        <?php else: ?>
                                                            <!-- Editable status -->
                                                            <span class="badge badge-sm <?= 'badge-status-' . $project['status'] ?>" 
                                                                  onclick="showStatusOptions(this, 'project', <?= $project['id'] ?>, '<?= $project['status'] ?>')">
                                                                <?= ucfirst(str_replace('_', ' ', $project['status'])) ?>
                                                                <i class="fas fa-caret-down ms-1"></i>
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td class="text-center">
                                                    <button type="button" class="action-btn-sm btn-view-sm view-project-details"
                                                            data-project-id="<?= $project['id'] ?>"
                                                            title="View Details">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    
                                                    <?php if ($role === 'super_admin' && !$is_terminated): ?>
                                                    <button type="button" class="action-btn-sm btn-edit-sm edit-project-btn"
                                                            data-project-id="<?= $project['id'] ?>"
                                                            title="Edit Project">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    
                                                    <button type="button" class="action-btn-sm btn-delete-sm delete-project-btn"
                                                            data-project-id="<?= $project['id'] ?>"
                                                            data-project-name="<?= htmlspecialchars($project['name']) ?>"
                                                            title="Delete Project">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                    <?php elseif ($role === 'super_admin' && $is_terminated): ?>
                                                    <!-- Super Admin can still edit terminated projects -->
                                                    <button type="button" class="action-btn-sm btn-edit-sm edit-project-btn"
                                                            data-project-id="<?= $project['id'] ?>"
                                                            title="Edit Project">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-5">
                                    <i class="fas fa-project-diagram fa-2x text-muted mb-3"></i>
                                    <p class="text-muted">No projects found</p>
                                    <?php if ($role === 'super_admin'): ?>
                                    <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addProjectModal">
                                        <i class="fas fa-plus me-1"></i> Create Project
                                    </button>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Phases Tab -->
                <div class="tab-pane fade" id="phases-tab-pane" role="tabpanel">
                    <div class="data-table-wrapper">
                        <div class="table-header">
                            <div>
                                <strong>Phases for <?= htmlspecialchars($selected_project_name) ?></strong>
                                <small class="ms-2">Total: <span id="tablePhasesCount"><?= $total_phases ?></span></small>
                            </div>
                            <div class="d-flex gap-2">
                                <div class="input-group input-group-sm" style="width: 250px;">
                                    <input type="text" class="form-control form-control-sm" id="searchPhases" placeholder="Search phases...">
                                    <button class="btn btn-outline-secondary" type="button" onclick="searchPhases()">
                                        <i class="fas fa-search"></i>
                                    </button>
                                </div>
                                <div>
                                    <?php if (in_array($role, ['super_admin', 'pm_manager'])): ?>
                                    <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addPhaseModal">
                                        <i class="fas fa-plus me-1"></i> Add Phase
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="table-container">
                            <?php if ($total_phases > 0): ?>
                                <?php $phases->data_seek(0); ?>
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover" id="phasesTable">
                                        <thead>
                                            <tr>
                                                <th width="50">ID</th>
                                                <th>Phase Name</th>
                                                <?php if ($selected_project_id == 0): ?>
                                                <th>Project</th>
                                                <?php endif; ?>
                                                <th>Description</th>
                                                <th>Order</th>
                                                <th>Status</th>
                                                <th width="150" class="text-center">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody id="phasesTableBody">
                                            <?php while ($phase = $phases->fetch_assoc()): ?>
                                            <?php 
                                            // Check if the phase's project is terminated
                                            $project_terminated = ($phase['status'] === 'frozen');
                                            $can_edit_status = true;
                                            $can_edit_item = true;
                                            
                                            if ($role === 'pm_employee') {
                                                $can_edit_status = false;
                                                $can_edit_item = false;
                                            } elseif ($role !== 'super_admin' && $project_terminated) {
                                                $can_edit_status = false;
                                                $can_edit_item = false;
                                            }
                                            ?>
                                            <tr>
                                                <td class="fw-bold">#<?= $phase['id'] ?></td>
                                                <td>
                                                    <div class="fw-medium"><?= htmlspecialchars($phase['name']) ?></div>
                                                </td>
                                                <?php if ($selected_project_id == 0): ?>
                                                <td>
                                                    <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25">
                                                        <?= htmlspecialchars($phase['project_name']) ?>
                                                    </span>
                                                </td>
                                                <?php endif; ?>
                                                <td>
                                                    <div class="small text-truncate" style="max-width: 200px;">
                                                        <?= !empty($phase['description']) ? htmlspecialchars($phase['description']) : '<span class="text-muted">No description</span>' ?>
                                                    </div>
                                                </td>
                                                <td><?= isset($phase['phase_order']) ? $phase['phase_order'] : 1 ?></td>
                                                <td>
                                                    <div class="status-container">
                                                        <?php if (!$can_edit_status): ?>
                                                            <!-- Read-only status -->
                                                            <span class="badge badge-sm <?= 'badge-status-' . $phase['status'] ?> readonly">
                                                                <?= ucfirst(str_replace('_', ' ', $phase['status'])) ?>
                                                            </span>
                                                        <?php else: ?>
                                                            <!-- Editable status -->
                                                            <span class="badge badge-sm <?= 'badge-status-' . $phase['status'] ?>" 
                                                                  onclick="showStatusOptions(this, 'phase', <?= $phase['id'] ?>, '<?= $phase['status'] ?>')">
                                                                <?= ucfirst(str_replace('_', ' ', $phase['status'])) ?>
                                                                <i class="fas fa-caret-down ms-1"></i>
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td class="text-center">
                                                    <button type="button" class="action-btn-sm btn-view-sm view-phase-activities"
                                                            data-phase-id="<?= $phase['id'] ?>"
                                                            data-phase-name="<?= htmlspecialchars($phase['name']) ?>"
                                                            title="View Activities">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    
                                                    <?php if (in_array($role, ['super_admin', 'pm_manager']) && $can_edit_item): ?>
                                                    <button type="button" class="action-btn-sm btn-edit-sm edit-phase-btn"
                                                            data-phase-id="<?= $phase['id'] ?>"
                                                            title="Edit Phase">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    
                                                    <button type="button" class="action-btn-sm btn-delete-sm delete-phase-btn"
                                                            data-phase-id="<?= $phase['id'] ?>"
                                                            data-phase-name="<?= htmlspecialchars($phase['name']) ?>"
                                                            title="Delete Phase">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endwhile; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php elseif ($selected_project_id == 0): ?>
                                <div class="text-center py-5">
                                    <i class="fas fa-exclamation-circle fa-2x text-warning mb-3"></i>
                                    <p class="text-muted">No phases found across all projects</p>
                                    <p class="small text-muted">Select a specific project to create phases</p>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-5">
                                    <i class="fas fa-tasks fa-2x text-muted mb-3"></i>
                                    <p class="text-muted">No phases found for <?= htmlspecialchars($selected_project_name) ?></p>
                                    <?php if (in_array($role, ['super_admin', 'pm_manager'])): ?>
                                    <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addPhaseModal">
                                        <i class="fas fa-plus me-1"></i> Create Phase
                                    </button>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Activities Tab -->
                <div class="tab-pane fade" id="activities-tab-pane" role="tabpanel">
                    <div class="data-table-wrapper">
                        <div class="table-header">
                            <div>
                                <strong>Activities for <?= htmlspecialchars($selected_project_name) ?></strong>
                                <small class="ms-2">Total: <span id="tableActivitiesCount"><?= $total_activities ?></span></small>
                            </div>
                            <div class="d-flex gap-2">
                                <div class="input-group input-group-sm" style="width: 250px;">
                                    <input type="text" class="form-control form-control-sm" id="searchActivities" placeholder="Search activities...">
                                    <button class="btn btn-outline-secondary" type="button" onclick="searchActivities()">
                                        <i class="fas fa-search"></i>
                                    </button>
                                </div>
                                <div>
                                    <?php if (in_array($role, ['super_admin', 'pm_manager'])): ?>
                                    <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addActivityModal">
                                        <i class="fas fa-plus me-1"></i> Add Activity
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="table-container">
                            <?php if ($total_activities > 0): ?>
                                <?php $activities->data_seek(0); ?>
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover" id="activitiesTable">
                                        <thead>
                                            <tr>
                                                <th width="50">ID</th>
                                                <th>Activity Name</th>
                                                <?php if ($selected_project_id == 0): ?>
                                                <th>Project</th>
                                                <?php endif; ?>
                                                <th>Phase</th>
                                                <th>Description</th>
                                                <th>Status</th>
                                                <th width="150" class="text-center">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody id="activitiesTableBody">
                                            <?php while ($activity = $activities->fetch_assoc()): ?>
                                            <?php 
                                            // Check if the activity's project is terminated
                                            $project_terminated = ($activity['status'] === 'frozen');
                                            $can_edit_item = true;
                                            
                                            if ($role !== 'super_admin' && $project_terminated) {
                                                $can_edit_item = false;
                                            }
                                            ?>
                                            <tr>
                                                <td class="fw-bold">#<?= $activity['id'] ?></td>
                                                <td>
                                                    <div class="fw-medium"><?= htmlspecialchars($activity['name']) ?></div>
                                                </td>
                                                <?php if ($selected_project_id == 0): ?>
                                                <td>
                                                    <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25">
                                                        <?= htmlspecialchars($activity['project_name']) ?>
                                                    </span>
                                                </td>
                                                <?php endif; ?>
                                                <td>
                                                    <span class="badge bg-info bg-opacity-10 text-info border border-info border-opacity-25">
                                                        <?= htmlspecialchars($activity['phase_name']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="small text-truncate" style="max-width: 200px;">
                                                        <?= !empty($activity['description']) ? htmlspecialchars($activity['description']) : '<span class="text-muted">No description</span>' ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="status-container">
                                                        <?php if (!$can_edit_item && $project_terminated): ?>
                                                            <!-- Read-only status for terminated projects -->
                                                            <span class="badge badge-sm <?= 'badge-status-' . $activity['status'] ?> readonly">
                                                                <?= ucfirst(str_replace('_', ' ', $activity['status'])) ?>
                                                            </span>
                                                        <?php else: ?>
                                                            <!-- Editable status for non-terminated projects -->
                                                            <span class="badge badge-sm <?= 'badge-status-' . $activity['status'] ?>" 
                                                                  onclick="showStatusOptions(this, 'activity', <?= $activity['id'] ?>, '<?= $activity['status'] ?>')">
                                                                <?= ucfirst(str_replace('_', ' ', $activity['status'])) ?>
                                                                <i class="fas fa-caret-down ms-1"></i>
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td class="text-center">
                                                    <button type="button" class="action-btn-sm btn-view-sm view-activity-sub-activities"
                                                            data-activity-id="<?= $activity['id'] ?>"
                                                            data-activity-name="<?= htmlspecialchars($activity['name']) ?>"
                                                            title="View Sub-Activities">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    
                                                    <?php if (in_array($role, ['super_admin', 'pm_manager']) && $can_edit_item): ?>
                                                    <button type="button" class="action-btn-sm btn-edit-sm edit-activity-btn"
                                                            data-activity-id="<?= $activity['id'] ?>"
                                                            title="Edit Activity">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    
                                                    <button type="button" class="action-btn-sm btn-delete-sm delete-activity-btn"
                                                            data-activity-id="<?= $activity['id'] ?>"
                                                            data-activity-name="<?= htmlspecialchars($activity['name']) ?>"
                                                            title="Delete Activity">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endwhile; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php elseif ($selected_project_id == 0): ?>
                                <div class="text-center py-5">
                                    <i class="fas fa-exclamation-circle fa-2x text-warning mb-3"></i>
                                    <p class="text-muted">No activities found across all projects</p>
                                    <p class="small text-muted">Select a specific project to create activities</p>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-5">
                                    <i class="fas fa-list-check fa-2x text-muted mb-3"></i>
                                    <p class="text-muted">No activities found for <?= htmlspecialchars($selected_project_name) ?></p>
                                    <?php if (in_array($role, ['super_admin', 'pm_manager'])): ?>
                                    <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addActivityModal">
                                        <i class="fas fa-plus me-1"></i> Create Activity
                                    </button>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Sub-Activities Tab -->
                <div class="tab-pane fade" id="sub-activities-tab-pane" role="tabpanel">
                    <div class="data-table-wrapper">
                        <div class="table-header">
                            <div>
                                <strong>Sub-Activities for <?= htmlspecialchars($selected_project_name) ?></strong>
                                <small class="ms-2">Total: <span id="tableSubActivitiesCount"><?= $total_sub_activities ?></span></small>
                            </div>
                            <div class="d-flex gap-2">
                                <div class="input-group input-group-sm" style="width: 250px;">
                                    <input type="text" class="form-control form-control-sm" id="searchSubActivities" placeholder="Search sub-activities...">
                                    <button class="btn btn-outline-secondary" type="button" onclick="searchSubActivities()">
                                        <i class="fas fa-search"></i>
                                    </button>
                                </div>
                                <div>
                                    <?php if (in_array($role, ['super_admin', 'pm_manager'])): ?>
                                    <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addSubActivityModal">
                                        <i class="fas fa-plus me-1"></i> Add Sub-Activity
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="table-container">
                            <?php if ($total_sub_activities > 0): ?>
                                <?php $sub_activities->data_seek(0); ?>
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover" id="subActivitiesTable">
                                        <thead>
                                            <tr>
                                                <th width="50">ID</th>
                                                <th>Sub-Activity Name</th>
                                                <?php if ($selected_project_id == 0): ?>
                                                <th>Project</th>
                                                <?php endif; ?>
                                                <th>Phase</th>
                                                <th>Activity</th>
                                                <th>Description</th>
                                                <th>Status</th>
                                                <th width="150" class="text-center">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody id="subActivitiesTableBody">
                                            <?php while ($sub_activity = $sub_activities->fetch_assoc()): ?>
                                            <?php 
                                            // Check if the sub-activity's project is terminated
                                            $project_terminated = ($sub_activity['status'] === 'frozen');
                                            $can_edit_item = true;
                                            
                                            if ($role !== 'super_admin' && $project_terminated) {
                                                $can_edit_item = false;
                                            }
                                            ?>
                                            <tr>
                                                <td class="fw-bold">#<?= $sub_activity['id'] ?></td>
                                                <td>
                                                    <div class="fw-medium"><?= htmlspecialchars($sub_activity['name']) ?></div>
                                                </td>
                                                <?php if ($selected_project_id == 0): ?>
                                                <td>
                                                    <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25">
                                                        <?= htmlspecialchars($sub_activity['project_name']) ?>
                                                    </span>
                                                </td>
                                                <?php endif; ?>
                                                <td>
                                                    <span class="badge bg-info bg-opacity-10 text-info border border-info border-opacity-25">
                                                        <?= htmlspecialchars($sub_activity['phase_name']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25">
                                                        <?= htmlspecialchars($sub_activity['activity_name']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="small text-truncate" style="max-width: 200px;">
                                                        <?= !empty($sub_activity['description']) ? htmlspecialchars($sub_activity['description']) : '<span class="text-muted">No description</span>' ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="status-container">
                                                        <?php if (!$can_edit_item && $project_terminated): ?>
                                                            <!-- Read-only status for terminated projects -->
                                                            <span class="badge badge-sm <?= 'badge-status-' . $sub_activity['status'] ?> readonly">
                                                                <?= ucfirst(str_replace('_', ' ', $sub_activity['status'])) ?>
                                                            </span>
                                                        <?php else: ?>
                                                            <!-- Editable status for non-terminated projects -->
                                                            <span class="badge badge-sm <?= 'badge-status-' . $sub_activity['status'] ?>" 
                                                                  onclick="showStatusOptions(this, 'sub_activity', <?= $sub_activity['id'] ?>, '<?= $sub_activity['status'] ?>')">
                                                                <?= ucfirst(str_replace('_', ' ', $sub_activity['status'])) ?>
                                                                <i class="fas fa-caret-down ms-1"></i>
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td class="text-center">
                                                    <?php if (in_array($role, ['super_admin', 'pm_manager']) && $can_edit_item): ?>
                                                    <button type="button" class="action-btn-sm btn-edit-sm edit-sub-activity-btn"
                                                            data-sub-activity-id="<?= $sub_activity['id'] ?>"
                                                            title="Edit Sub-Activity">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    
                                                    <button type="button" class="action-btn-sm btn-delete-sm delete-sub-activity-btn"
                                                            data-sub-activity-id="<?= $sub_activity['id'] ?>"
                                                            data-sub-activity-name="<?= htmlspecialchars($sub_activity['name']) ?>"
                                                            title="Delete Sub-Activity">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endwhile; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php elseif ($selected_project_id == 0): ?>
                                <div class="text-center py-5">
                                    <i class="fas fa-exclamation-circle fa-2x text-warning mb-3"></i>
                                    <p class="text-muted">No sub-activities found across all projects</p>
                                    <p class="small text-muted">Select a specific project to create sub-activities</p>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-5">
                                    <i class="fas fa-list-ol fa-2x text-muted mb-3"></i>
                                    <p class="text-muted">No sub-activities found for <?= htmlspecialchars($selected_project_name) ?></p>
                                    <?php if (in_array($role, ['super_admin', 'pm_manager'])): ?>
                                    <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addSubActivityModal">
                                        <i class="fas fa-plus me-1"></i> Create Sub-Activity
                                    </button>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ========== MODALS ========== -->
    <!-- Add Project Modal (Dynamic) -->
    <div class="modal fade" id="addProjectModal" tabindex="-1" aria-labelledby="addProjectModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Add New Projects</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="addProjectForm">
                    <div class="modal-body">
                        <div id="projectFieldsContainer">
                            <!-- Initial project field -->
                            <div class="dynamic-field-group" id="projectGroup_1">
                                <div class="field-header">
                                    <div class="field-count">Project #1</div>
                                    <div>
                                        <button type="button" class="add-field-btn" onclick="addProjectField()" title="Add another project">
                                            <i class="fas fa-plus"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">Project Name <span class="text-danger">*</span></label>
                                            <input type="text" name="names[]" class="form-control form-control-sm" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">Status</label>
                                            <select name="statuses[]" class="form-select form-select-sm">
                                                <option value="pending" selected>Pending</option>
                                                <option value="in_progress">In Progress</option>
                                                <option value="completed">Completed</option>
                                                <option value="terminated">Terminated</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">Start Date</label>
                                            <input type="date" name="start_dates[]" class="form-control form-control-sm">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">End Date</label>
                                            <input type="date" name="end_dates[]" class="form-control form-control-sm">
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">Department</label>
                                            <select name="department_ids[]" class="form-select form-select-sm">
                                                <option value="">-- Select Department --</option>
                                                <?php foreach ($departments as $dept): ?>
                                                    <option value="<?= $dept['id'] ?>"><?= htmlspecialchars($dept['department_name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Description</label>
                                    <textarea name="descriptions[]" class="form-control form-control-sm" rows="2"></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-sm btn-primary">
                            <i class="fas fa-plus me-1"></i> Add Projects
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Project Modal -->
    <div class="modal fade" id="editProjectModal" tabindex="-1" aria-labelledby="editProjectModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Project</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="editProjectForm">
                    <div class="modal-body">
                        <input type="hidden" name="project_id" id="edit_project_id">
                        
                        <div class="mb-3">
                            <label for="edit_project_name" class="form-label">Project Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" id="edit_project_name" class="form-control form-control-sm" required>
                        </div>
                        <div class="mb-3">
                            <label for="edit_project_description" class="form-label">Description</label>
                            <textarea name="description" id="edit_project_description" class="form-control form-control-sm" rows="3"></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="edit_project_status" class="form-label">Status</label>
                                    <select name="status" id="edit_project_status" class="form-select form-select-sm">
                                        <option value="pending">Pending</option>
                                        <option value="in_progress">In Progress</option>
                                        <option value="completed">Completed</option>
                                        <option value="terminated">Terminated</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="edit_project_department" class="form-label">Department</label>
                                    <select name="department_id" id="edit_project_department" class="form-select form-select-sm">
                                        <option value="">-- Select Department --</option>
                                        <?php foreach ($departments as $dept): ?>
                                            <option value="<?= $dept['id'] ?>"><?= htmlspecialchars($dept['department_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="edit_project_start_date" class="form-label">Start Date</label>
                                    <input type="date" name="start_date" id="edit_project_start_date" class="form-control form-control-sm">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="edit_project_end_date" class="form-label">End Date</label>
                                    <input type="date" name="end_date" id="edit_project_end_date" class="form-control form-control-sm">
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-sm btn-primary">
                            <i class="fas fa-save me-1"></i> Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Phase Modal (Dynamic) -->
    <div class="modal fade" id="addPhaseModal" tabindex="-1" aria-labelledby="addPhaseModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Add New Phases for <?= htmlspecialchars($selected_project_name) ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="addPhaseForm">
                    <div class="modal-body">
                        <?php if ($selected_project_id == 0): ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            Please select a specific project from the dropdown above before adding phases.
                        </div>
                        <?php else: ?>
                        <input type="hidden" name="project_id" value="<?= $selected_project_id ?>">
                        
                        <div id="phaseFieldsContainer">
                            <!-- Initial phase field -->
                            <div class="dynamic-field-group" id="phaseGroup_1">
                                <div class="field-header">
                                    <div class="field-count">Phase #1</div>
                                    <div>
                                        <button type="button" class="add-field-btn" onclick="addPhaseField()" title="Add another phase">
                                            <i class="fas fa-plus"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">Phase Name <span class="text-danger">*</span></label>
                                            <input type="text" name="names[]" class="form-control form-control-sm" required>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <label class="form-label">Order <span class="text-danger">*</span></label>
                                            <input type="number" name="phase_orders[]" class="form-control form-control-sm" min="1" value="1" required>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <label class="form-label">Status</label>
                                            <select name="statuses[]" class="form-select form-select-sm">
                                                <option value="pending" selected>Pending</option>
                                                <option value="in_progress">In Progress</option>
                                                <option value="completed">Completed</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">Start Date</label>
                                            <input type="date" name="start_dates[]" class="form-control form-control-sm">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">End Date</label>
                                            <input type="date" name="end_dates[]" class="form-control form-control-sm">
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Description</label>
                                    <textarea name="descriptions[]" class="form-control form-control-sm" rows="2"></textarea>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <?php if ($selected_project_id > 0): ?>
                        <button type="submit" class="btn btn-sm btn-primary">
                            <i class="fas fa-plus me-1"></i> Add Phases
                        </button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Phase Modal -->
    <div class="modal fade" id="editPhaseModal" tabindex="-1" aria-labelledby="editPhaseModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Phase</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="editPhaseForm">
                    <div class="modal-body">
                        <input type="hidden" name="phase_id" id="edit_phase_id">
                        <input type="hidden" name="project_id" id="edit_phase_project_id">
                        
                        <div class="mb-3">
                            <label for="edit_phase_name" class="form-label">Phase Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" id="edit_phase_name" class="form-control form-control-sm" required>
                        </div>
                        <div class="mb-3">
                            <label for="edit_phase_description" class="form-label">Description</label>
                            <textarea name="description" id="edit_phase_description" class="form-control form-control-sm" rows="3"></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="edit_phase_order" class="form-label">Order <span class="text-danger">*</span></label>
                                    <input type="number" name="phase_order" id="edit_phase_order" class="form-control form-control-sm" min="1" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="edit_phase_status" class="form-label">Status</label>
                                    <select name="status" id="edit_phase_status" class="form-select form-select-sm">
                                        <option value="pending">Pending</option>
                                        <option value="in_progress">In Progress</option>
                                        <option value="completed">Completed</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="edit_phase_start_date" class="form-label">Start Date</label>
                                    <input type="date" name="start_date" id="edit_phase_start_date" class="form-control form-control-sm">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="edit_phase_end_date" class="form-label">End Date</label>
                                    <input type="date" name="end_date" id="edit_phase_end_date" class="form-control form-control-sm">
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-sm btn-primary">
                            <i class="fas fa-save me-1"></i> Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Activity Modal (Dynamic) -->
    <div class="modal fade" id="addActivityModal" tabindex="-1" aria-labelledby="addActivityModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Add New Activities for <?= htmlspecialchars($selected_project_name) ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="addActivityForm">
                    <div class="modal-body">
                        <?php if ($selected_project_id == 0): ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            Please select a specific project from the dropdown above before adding activities.
                        </div>
                        <?php else: ?>
                        <input type="hidden" name="project_id" value="<?= $selected_project_id ?>">
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="activity_phase_id" class="form-label">Phase <span class="text-danger">*</span></label>
                                    <select name="phase_id" id="activity_phase_id" class="form-select form-select-sm" required>
                                        <option value="">-- Select Phase --</option>
                                        <?php 
                                        $phases->data_seek(0);
                                        while ($phase = $phases->fetch_assoc()): 
                                        ?>
                                            <option value="<?= $phase['id'] ?>"><?= htmlspecialchars($phase['name']) ?></option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div id="activityFieldsContainer">
                            <!-- Initial activity field -->
                            <div class="dynamic-field-group" id="activityGroup_1">
                                <div class="field-header">
                                    <div class="field-count">Activity #1</div>
                                    <div>
                                        <button type="button" class="add-field-btn" onclick="addActivityField()" title="Add another activity">
                                            <i class="fas fa-plus"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">Activity Name <span class="text-danger">*</span></label>
                                            <input type="text" name="names[]" class="form-control form-control-sm" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">Status</label>
                                            <select name="statuses[]" class="form-select form-select-sm">
                                                <option value="pending" selected>Pending</option>
                                                <option value="in_progress">In Progress</option>
                                                <option value="completed">Completed</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">Start Date</label>
                                            <input type="date" name="start_dates[]" class="form-control form-control-sm">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">End Date</label>
                                            <input type="date" name="end_dates[]" class="form-control form-control-sm">
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Description</label>
                                    <textarea name="descriptions[]" class="form-control form-control-sm" rows="2"></textarea>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <?php if ($selected_project_id > 0): ?>
                        <button type="submit" class="btn btn-sm btn-primary">
                            <i class="fas fa-plus me-1"></i> Add Activities
                        </button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Activity Modal -->
    <div class="modal fade" id="editActivityModal" tabindex="-1" aria-labelledby="editActivityModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Activity</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="editActivityForm">
                    <div class="modal-body">
                        <input type="hidden" name="activity_id" id="edit_activity_id">
                        <input type="hidden" name="phase_id" id="edit_activity_phase_id">
                        
                        <div class="mb-3">
                            <label for="edit_activity_name" class="form-label">Activity Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" id="edit_activity_name" class="form-control form-control-sm" required>
                        </div>
                        <div class="mb-3">
                            <label for="edit_activity_description" class="form-label">Description</label>
                            <textarea name="description" id="edit_activity_description" class="form-control form-control-sm" rows="3"></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="edit_activity_status" class="form-label">Status</label>
                                    <select name="status" id="edit_activity_status" class="form-select form-select-sm">
                                        <option value="pending">Pending</option>
                                        <option value="in_progress">In Progress</option>
                                        <option value="completed">Completed</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="edit_activity_start_date" class="form-label">Start Date</label>
                                    <input type="date" name="start_date" id="edit_activity_start_date" class="form-control form-control-sm">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="edit_activity_end_date" class="form-label">End Date</label>
                                    <input type="date" name="end_date" id="edit_activity_end_date" class="form-control form-control-sm">
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-sm btn-primary">
                            <i class="fas fa-save me-1"></i> Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Sub-Activity Modal (Dynamic) -->
    <div class="modal fade" id="addSubActivityModal" tabindex="-1" aria-labelledby="addSubActivityModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Add New Sub-Activities for <?= htmlspecialchars($selected_project_name) ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="addSubActivityForm">
                    <div class="modal-body">
                        <?php if ($selected_project_id == 0): ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            Please select a specific project from the dropdown above before adding sub-activities.
                        </div>
                        <?php else: ?>
                        <input type="hidden" name="project_id" value="<?= $selected_project_id ?>">
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="sub_activity_phase_id" class="form-label">Phase <span class="text-danger">*</span></label>
                                    <select name="phase_id" id="sub_activity_phase_id" class="form-select form-select-sm" required>
                                        <option value="">-- Select Phase --</option>
                                        <?php 
                                        $phases->data_seek(0);
                                        while ($phase = $phases->fetch_assoc()): 
                                        ?>
                                            <option value="<?= $phase['id'] ?>"><?= htmlspecialchars($phase['name']) ?></option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="sub_activity_activity_id" class="form-label">Activity <span class="text-danger">*</span></label>
                                    <select name="activity_id" id="sub_activity_activity_id" class="form-select form-control-sm" required disabled>
                                        <option value="">-- Select Activity --</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div id="subActivityFieldsContainer">
                            <!-- Initial sub-activity field -->
                            <div class="dynamic-field-group" id="subActivityGroup_1">
                                <div class="field-header">
                                    <div class="field-count">Sub-Activity #1</div>
                                    <div>
                                        <button type="button" class="add-field-btn" onclick="addSubActivityField()" title="Add another sub-activity">
                                            <i class="fas fa-plus"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">Sub-Activity Name <span class="text-danger">*</span></label>
                                            <input type="text" name="names[]" class="form-control form-control-sm" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">Status</label>
                                            <select name="statuses[]" class="form-select form-select-sm">
                                                <option value="pending" selected>Pending</option>
                                                <option value="in_progress">In Progress</option>
                                                <option value="completed">Completed</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">Start Date</label>
                                            <input type="date" name="start_dates[]" class="form-control form-control-sm">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">End Date</label>
                                            <input type="date" name="end_dates[]" class="form-control form-control-sm">
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Description</label>
                                    <textarea name="descriptions[]" class="form-control form-control-sm" rows="2"></textarea>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <?php if ($selected_project_id > 0): ?>
                        <button type="submit" class="btn btn-sm btn-primary">
                            <i class="fas fa-plus me-1"></i> Add Sub-Activities
                        </button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Sub-Activity Modal -->
    <div class="modal fade" id="editSubActivityModal" tabindex="-1" aria-labelledby="editSubActivityModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Sub-Activity</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="editSubActivityForm">
                    <div class="modal-body">
                        <input type="hidden" name="sub_activity_id" id="edit_sub_activity_id">
                        <input type="hidden" name="activity_id" id="edit_sub_activity_activity_id">
                        
                        <div class="mb-3">
                            <label for="edit_sub_activity_name" class="form-label">Sub-Activity Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" id="edit_sub_activity_name" class="form-control form-control-sm" required>
                        </div>
                        <div class="mb-3">
                            <label for="edit_sub_activity_description" class="form-label">Description</label>
                            <textarea name="description" id="edit_sub_activity_description" class="form-control form-control-sm" rows="3"></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="edit_sub_activity_status" class="form-label">Status</label>
                                    <select name="status" id="edit_sub_activity_status" class="form-select form-select-sm">
                                        <option value="pending">Pending</option>
                                        <option value="in_progress">In Progress</option>
                                        <option value="completed">Completed</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="edit_sub_activity_start_date" class="form-label">Start Date</label>
                                    <input type="date" name="start_date" id="edit_sub_activity_start_date" class="form-control form-control-sm">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="edit_sub_activity_end_date" class="form-label">End Date</label>
                                    <input type="date" name="end_date" id="edit_sub_activity_end_date" class="form-control form-control-sm">
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-sm btn-primary">
                            <i class="fas fa-save me-1"></i> Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Project Details Modal -->
    <div class="modal fade" id="viewProjectModal" tabindex="-1" aria-labelledby="viewProjectModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Project Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="projectDetailsContent">
                    <!-- Content will be loaded via AJAX -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- View Phase Activities Modal -->
    <div class="modal fade" id="viewPhaseActivitiesModal" tabindex="-1" aria-labelledby="viewPhaseActivitiesModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="viewPhaseActivitiesModalLabel">Phase Activities</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="phaseActivitiesContent">
                    <!-- Content will be loaded via AJAX -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- View Activity Sub-Activities Modal -->
    <div class="modal fade" id="viewActivitySubActivitiesModal" tabindex="-1" aria-labelledby="viewActivitySubActivitiesModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="viewActivitySubActivitiesModalLabel">Activity Sub-Activities</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="activitySubActivitiesContent">
                    <!-- Content will be loaded via AJAX -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- User Assignment Modal -->
    <div class="modal fade" id="userAssignmentModal" tabindex="-1" aria-labelledby="userAssignmentModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="userAssignmentModalTitle">Assign User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="userAssignmentForm">
                    <div class="modal-body">
                        <input type="hidden" name="item_type" id="assign_item_type">
                        <input type="hidden" name="item_id" id="assign_item_id">
                        <input type="hidden" name="project_id" id="assign_project_id">
                        <input type="hidden" name="item_name" id="assign_item_name">
                        
                        <div class="mb-3">
                            <label for="assign_user_role" class="form-label">User Role <span class="text-danger">*</span></label>
                            <select name="role" id="assign_user_role" class="form-select form-select-sm" required onchange="loadUsersByRole(this.value)">
                                <option value="">-- Select Role --</option>
                                <option value="pm_manager">PM Manager</option>
                                <option value="pm_employee">PM Employee</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="assign_user_id" class="form-label">Select User <span class="text-danger">*</span></label>
                            <select name="user_id" id="assign_user_id" class="form-select form-select-sm" required disabled>
                                <option value="">-- Select User --</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-sm btn-primary">
                            <i class="fas fa-user-plus me-1"></i> Assign User
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- User Revocation Modal -->
    <div class="modal fade" id="userRevocationModal" tabindex="-1" aria-labelledby="userRevocationModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="userRevocationModalTitle">Unassign User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="userRevocationForm">
                    <div class="modal-body">
                        <input type="hidden" name="item_type" id="revoke_item_type">
                        <input type="hidden" name="item_id" id="revoke_item_id">
                        <input type="hidden" name="project_id" id="revoke_project_id">
                        <input type="hidden" name="item_name" id="revoke_item_name">
                        
                        <div class="mb-3">
                            <label for="revoke_user_id" class="form-label">Select User to Unassign <span class="text-danger">*</span></label>
                            <select name="user_id" id="revoke_user_id" class="form-select form-select-sm" required>
                                <option value="">-- Select User --</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-sm btn-danger">
                            <i class="fas fa-user-minus me-1"></i> Unassign User
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- JavaScript Libraries -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
    // Global variables
    let projectsTable = null;
    let phasesTable = null;
    let activitiesTable = null;
    let subActivitiesTable = null;
    let projectFieldCount = 1;
    let phaseFieldCount = 1;
    let activityFieldCount = 1;
    let subActivityFieldCount = 1;
    let currentStatusDropdown = null;
    const userRole = '<?= $role ?>';
    let notificationCheckInterval = null;
    
    // Profile Dropdown Toggle
    document.addEventListener('DOMContentLoaded', function() {
        const profileTrigger = document.getElementById('profileTrigger');
        const profileDropdown = document.getElementById('profileDropdown');
        
        if (profileTrigger && profileDropdown) {
            profileTrigger.addEventListener('click', function(e) {
                e.stopPropagation();
                profileDropdown.classList.toggle('show');
            });
            
            // Close dropdown when clicking outside
            document.addEventListener('click', function(e) {
                if (!profileTrigger.contains(e.target) && !profileDropdown.contains(e.target)) {
                    profileDropdown.classList.remove('show');
                }
            });
        }
        
        // Handle sidebar toggle synchronization
        const sidebarContainer = document.getElementById('sidebarContainer');
        const staticHeader = document.getElementById('staticHeader');
        const mainContent = document.getElementById('mainContent');
        
        if (sidebarContainer && staticHeader && mainContent) {
            // Check initial state
            if (sidebarContainer.classList.contains('sidebar-collapsed')) {
                staticHeader.classList.add('sidebar-collapsed');
                mainContent.classList.add('sidebar-collapsed');
            }
            
            // Observe changes to sidebar container
            const observer = new MutationObserver(function(mutations) {
                mutations.forEach(function(mutation) {
                    if (mutation.attributeName === 'class') {
                        if (sidebarContainer.classList.contains('sidebar-collapsed')) {
                            staticHeader.classList.add('sidebar-collapsed');
                            mainContent.classList.add('sidebar-collapsed');
                        } else {
                            staticHeader.classList.remove('sidebar-collapsed');
                            mainContent.classList.remove('sidebar-collapsed');
                        }
                    }
                });
            });
            
            observer.observe(sidebarContainer, {
                attributes: true,
                attributeFilter: ['class']
            });
        }
    });
    
    $(document).ready(function() {
        // Initialize DataTables only if data exists
        if ($('#projectsTable tbody tr').length > 0) {
            projectsTable = $('#projectsTable').DataTable({
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
                responsive: true,
                order: [[0, 'desc']],
                language: {
                    search: "",
                    searchPlaceholder: "Search projects..."
                }
            });
        }
        
        if ($('#phasesTable tbody tr').length > 0) {
            phasesTable = $('#phasesTable').DataTable({
                pageLength: 25,
                responsive: true,
                order: [[0, 'desc']],
                language: {
                    search: "",
                    searchPlaceholder: "Search phases..."
                }
            });
        }
        
        if ($('#activitiesTable tbody tr').length > 0) {
            activitiesTable = $('#activitiesTable').DataTable({
                pageLength: 25,
                responsive: true,
                order: [[0, 'desc']],
                language: {
                    search: "",
                    searchPlaceholder: "Search activities..."
                }
            });
        }
        
        if ($('#subActivitiesTable tbody tr').length > 0) {
            subActivitiesTable = $('#subActivitiesTable').DataTable({
                pageLength: 25,
                responsive: true,
                order: [[0, 'desc']],
                language: {
                    search: "",
                    searchPlaceholder: "Search sub-activities..."
                }
            });
        }
        
        // Load notifications on page load
        loadNotifications();
        
        // Set up notification polling (check every 30 seconds)
        notificationCheckInterval = setInterval(loadNotifications, 30000);
        
        // Edit Project button click - Only for Super Admin
        $(document).on('click', '.edit-project-btn', function() {
            if (userRole !== 'super_admin') {
                Swal.fire('Permission Denied', 'Only Super Admins can edit projects.', 'error');
                return;
            }
            
            const projectId = $(this).data('project-id');
            
            showLoading();
            $.ajax({
                url: '<?= basename($_SERVER['PHP_SELF']) ?>',
                method: 'POST',
                data: {
                    ajax: true,
                    action: 'get_project_details',
                    project_id: projectId
                },
                success: function(response) {
                    hideLoading();
                    if (response.success) {
                        const project = response.project;
                        $('#edit_project_id').val(project.id);
                        $('#edit_project_name').val(project.name);
                        $('#edit_project_description').val(project.description || '');
                        $('#edit_project_status').val(project.status);
                        $('#edit_project_department').val(project.department_id || '');
                        $('#edit_project_start_date').val(project.start_date || '');
                        $('#edit_project_end_date').val(project.end_date || '');
                        
                        $('#editProjectModal').modal('show');
                    } else {
                        Swal.fire('Error!', response.message, 'error');
                    }
                },
                error: function() {
                    hideLoading();
                    Swal.fire('Error!', 'Failed to load project details', 'error');
                }
            });
        });
        
        // Delete Project button click - Only for Super Admin
        $(document).on('click', '.delete-project-btn', function() {
            if (userRole !== 'super_admin') {
                Swal.fire('Permission Denied', 'Only Super Admins can delete projects.', 'error');
                return;
            }
            
            const projectId = $(this).data('project-id');
            const projectName = $(this).data('project-name');
            
            Swal.fire({
                title: 'Delete Project?',
                html: `<p>Are you sure you want to delete project: <strong>${projectName}</strong>?</p>
                      <p class="text-danger small">This action will delete all related phases, activities, and sub-activities!</p>
                      <p class="text-danger small">This action cannot be undone!</p>`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Yes, delete it!',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    showLoading();
                    $.ajax({
                        url: '<?= basename($_SERVER['PHP_SELF']) ?>',
                        method: 'POST',
                        data: {
                            ajax: true,
                            action: 'delete_project',
                            project_id: projectId
                        },
                        success: function(response) {
                            hideLoading();
                            if (response.success) {
                                showToast('Success', response.message, 'success');
                                setTimeout(() => location.reload(), 1500);
                            } else {
                                Swal.fire('Error!', response.message, 'error');
                            }
                        },
                        error: function(xhr, status, error) {
                            hideLoading();
                            Swal.fire('Error!', 'Failed to delete project: ' + error, 'error');
                        }
                    });
                }
            });
        });
        
        // Edit Phase button click - For Super Admin and PM Managers
        $(document).on('click', '.edit-phase-btn', function() {
            if (!['super_admin', 'pm_manager'].includes(userRole)) {
                Swal.fire('Permission Denied', 'Only Super Admins and PM Managers can edit phases.', 'error');
                return;
            }
            
            const phaseId = $(this).data('phase-id');
            
            showLoading();
            $.ajax({
                url: '<?= basename($_SERVER['PHP_SELF']) ?>',
                method: 'POST',
                data: {
                    ajax: true,
                    action: 'get_phase_details',
                    phase_id: phaseId
                },
                success: function(response) {
                    hideLoading();
                    if (response.success) {
                        const phase = response.phase;
                        $('#edit_phase_id').val(phase.id);
                        $('#edit_phase_project_id').val(phase.project_id);
                        $('#edit_phase_name').val(phase.name);
                        $('#edit_phase_description').val(phase.description || '');
                        $('#edit_phase_order').val(phase.phase_order);
                        $('#edit_phase_status').val(phase.status);
                        $('#edit_phase_start_date').val(phase.start_date || '');
                        $('#edit_phase_end_date').val(phase.end_date || '');
                        
                        $('#editPhaseModal').modal('show');
                    } else {
                        Swal.fire('Error!', response.message, 'error');
                    }
                },
                error: function() {
                    hideLoading();
                    Swal.fire('Error!', 'Failed to load phase details', 'error');
                }
            });
        });
        
        // Delete Phase button click - For Super Admin and PM Managers
        $(document).on('click', '.delete-phase-btn', function() {
            if (!['super_admin', 'pm_manager'].includes(userRole)) {
                Swal.fire('Permission Denied', 'Only Super Admins and PM Managers can delete phases.', 'error');
                return;
            }
            
            const phaseId = $(this).data('phase-id');
            const phaseName = $(this).data('phase-name');
            
            Swal.fire({
                title: 'Delete Phase?',
                html: `<p>Are you sure you want to delete phase: <strong>${phaseName}</strong>?</p>
                      <p class="text-danger small">This action cannot be undone!</p>`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Yes, delete it!',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    showLoading();
                    $.ajax({
                        url: '<?= basename($_SERVER['PHP_SELF']) ?>',
                        method: 'POST',
                        data: {
                            ajax: true,
                            action: 'delete_phase',
                            phase_id: phaseId
                        },
                        success: function(response) {
                            hideLoading();
                            if (response.success) {
                                showToast('Success', response.message, 'success');
                                setTimeout(() => location.reload(), 1500);
                            } else {
                                Swal.fire('Error!', response.message, 'error');
                            }
                        },
                        error: function(xhr, status, error) {
                            hideLoading();
                            Swal.fire('Error!', 'Failed to delete phase: ' + error, 'error');
                        }
                    });
                }
            });
        });
        
        // Edit Activity button click - For Super Admin and PM Managers
        $(document).on('click', '.edit-activity-btn', function() {
            if (!['super_admin', 'pm_manager'].includes(userRole)) {
                Swal.fire('Permission Denied', 'Only Super Admins and PM Managers can edit activities.', 'error');
                return;
            }
            
            const activityId = $(this).data('activity-id');
            
            showLoading();
            $.ajax({
                url: '<?= basename($_SERVER['PHP_SELF']) ?>',
                method: 'POST',
                data: {
                    ajax: true,
                    action: 'get_activity_details',
                    activity_id: activityId
                },
                success: function(response) {
                    hideLoading();
                    if (response.success) {
                        const activity = response.activity;
                        $('#edit_activity_id').val(activity.id);
                        $('#edit_activity_phase_id').val(activity.phase_id);
                        $('#edit_activity_name').val(activity.name);
                        $('#edit_activity_description').val(activity.description || '');
                        $('#edit_activity_status').val(activity.status);
                        $('#edit_activity_start_date').val(activity.start_date || '');
                        $('#edit_activity_end_date').val(activity.end_date || '');
                        
                        $('#editActivityModal').modal('show');
                    } else {
                        Swal.fire('Error!', response.message, 'error');
                    }
                },
                error: function() {
                    hideLoading();
                    Swal.fire('Error!', 'Failed to load activity details', 'error');
                }
            });
        });
        
        // Delete Activity button click - For Super Admin and PM Managers
        $(document).on('click', '.delete-activity-btn', function() {
            if (!['super_admin', 'pm_manager'].includes(userRole)) {
                Swal.fire('Permission Denied', 'Only Super Admins and PM Managers can delete activities.', 'error');
                return;
            }
            
            const activityId = $(this).data('activity-id');
            const activityName = $(this).data('activity-name');
            
            Swal.fire({
                title: 'Delete Activity?',
                html: `<p>Are you sure you want to delete activity: <strong>${activityName}</strong>?</p>
                      <p class="text-danger small">This action cannot be undone!</p>`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Yes, delete it!',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    showLoading();
                    $.ajax({
                        url: '<?= basename($_SERVER['PHP_SELF']) ?>',
                        method: 'POST',
                        data: {
                            ajax: true,
                            action: 'delete_activity',
                            activity_id: activityId
                        },
                        success: function(response) {
                            hideLoading();
                            if (response.success) {
                                showToast('Success', response.message, 'success');
                                setTimeout(() => location.reload(), 1500);
                            } else {
                                Swal.fire('Error!', response.message, 'error');
                            }
                        },
                        error: function(xhr, status, error) {
                            hideLoading();
                            Swal.fire('Error!', 'Failed to delete activity: ' + error, 'error');
                        }
                    });
                }
            });
        });
        
        // Edit Sub-Activity button click - For Super Admin and PM Managers
        $(document).on('click', '.edit-sub-activity-btn', function() {
            if (!['super_admin', 'pm_manager'].includes(userRole)) {
                Swal.fire('Permission Denied', 'Only Super Admins and PM Managers can edit sub-activities.', 'error');
                return;
            }
            
            const subActivityId = $(this).data('sub-activity-id');
            
            showLoading();
            $.ajax({
                url: '<?= basename($_SERVER['PHP_SELF']) ?>',
                method: 'POST',
                data: {
                    ajax: true,
                    action: 'get_sub_activity_details',
                    sub_activity_id: subActivityId
                },
                success: function(response) {
                    hideLoading();
                    if (response.success) {
                        const subActivity = response.sub_activity;
                        $('#edit_sub_activity_id').val(subActivity.id);
                        $('#edit_sub_activity_activity_id').val(subActivity.activity_id);
                        $('#edit_sub_activity_name').val(subActivity.name);
                        $('#edit_sub_activity_description').val(subActivity.description || '');
                        $('#edit_sub_activity_status').val(subActivity.status);
                        $('#edit_sub_activity_start_date').val(subActivity.start_date || '');
                        $('#edit_sub_activity_end_date').val(subActivity.end_date || '');
                        
                        $('#editSubActivityModal').modal('show');
                    } else {
                        Swal.fire('Error!', response.message, 'error');
                    }
                },
                error: function() {
                    hideLoading();
                    Swal.fire('Error!', 'Failed to load sub-activity details', 'error');
                }
            });
        });
        
        // Delete Sub-Activity button click - For Super Admin and PM Managers
        $(document).on('click', '.delete-sub-activity-btn', function() {
            if (!['super_admin', 'pm_manager'].includes(userRole)) {
                Swal.fire('Permission Denied', 'Only Super Admins and PM Managers can delete sub-activities.', 'error');
                return;
            }
            
            const subActivityId = $(this).data('sub-activity-id');
            const subActivityName = $(this).data('sub-activity-name');
            
            Swal.fire({
                title: 'Delete Sub-Activity?',
                html: `<p>Are you sure you want to delete sub-activity: <strong>${subActivityName}</strong>?</p>
                      <p class="text-danger small">This action cannot be undone!</p>`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Yes, delete it!',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    showLoading();
                    $.ajax({
                        url: '<?= basename($_SERVER['PHP_SELF']) ?>',
                        method: 'POST',
                        data: {
                            ajax: true,
                            action: 'delete_sub_activity',
                            sub_activity_id: subActivityId
                        },
                        success: function(response) {
                            hideLoading();
                            if (response.success) {
                                showToast('Success', response.message, 'success');
                                setTimeout(() => location.reload(), 1500);
                            } else {
                                Swal.fire('Error!', response.message, 'error');
                            }
                        },
                        error: function(xhr, status, error) {
                            hideLoading();
                            Swal.fire('Error!', 'Failed to delete sub-activity: ' + error, 'error');
                        }
                    });
                }
            });
        });
        
        // View Project Details button click
        $(document).on('click', '.view-project-details', function() {
            const projectId = $(this).data('project-id');
            
            showLoading();
            $.ajax({
                url: '<?= basename($_SERVER['PHP_SELF']) ?>',
                method: 'POST',
                data: {
                    ajax: true,
                    action: 'get_project_details',
                    project_id: projectId
                },
                success: function(response) {
                    hideLoading();
                    if (response.success) {
                        const project = response.project;
                        const detailsHtml = `
                            <div class="row">
                                <div class="col-md-6">
                                    <table class="table table-sm">
                                        <tr><th width="40%">ID</th><td>#${project.id}</td></tr>
                                        <tr><th>Name</th><td>${project.name}</td></tr>
                                        <tr><th>Status</th>
                                            <td><span class="badge badge-sm ${project.status === 'pending' ? 'bg-warning' : project.status === 'in_progress' ? 'bg-info' : project.status === 'completed' ? 'bg-success' : 'bg-danger'}">
                                                ${project.status.charAt(0).toUpperCase() + project.status.slice(1).replace('_', ' ')}
                                            </span></td></tr>
                                        <tr><th>Department</th><td>${project.department_name || 'Not assigned'}</td></tr>
                                    </table>
                                </div>
                                <div class="col-md-6">
                                    <table class="table table-sm">
                                        <tr><th width="40%">Start Date</th><td>${project.start_date || 'Not set'}</td></tr>
                                        <tr><th>End Date</th><td>${project.end_date || 'Not set'}</td></tr>
                                        <tr><th>Created</th><td>${new Date(project.created_at).toLocaleDateString()}</td></tr>
                                        <tr><th>Updated</th><td>${project.updated_at ? new Date(project.updated_at).toLocaleDateString() : 'Never'}</td></tr>
                                    </table>
                                </div>
                            </div>
                            <div class="row mt-3">
                                <div class="col-12">
                                    <div class="card">
                                        <div class="card-header bg-light">
                                            <h6 class="mb-0">Description</h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="bg-light p-3 rounded small" style="white-space: pre-wrap;">${project.description || 'No description available'}</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        `;
                        
                        $('#projectDetailsContent').html(detailsHtml);
                        $('#viewProjectModal').modal('show');
                    } else {
                        Swal.fire('Error!', response.message, 'error');
                    }
                },
                error: function() {
                    hideLoading();
                    Swal.fire('Error!', 'Failed to load project details', 'error');
                }
            });
        });
        
        // View Phase Activities button click
        $(document).on('click', '.view-phase-activities', function() {
            const phaseId = $(this).data('phase-id');
            const phaseName = $(this).data('phase-name');
            
            showLoading();
            $.ajax({
                url: '<?= basename($_SERVER['PHP_SELF']) ?>',
                method: 'POST',
                data: {
                    ajax: true,
                    action: 'get_phase_activities',
                    phase_id: phaseId
                },
                success: function(response) {
                    hideLoading();
                    if (response.success) {
                        const activities = response.activities;
                        const phase = response.phase;
                        
                        let html = `
                            <div class="row mb-4">
                                <div class="col-md-6">
                                    <h6>Phase Details</h6>
                                    <table class="table table-sm">
                                        <tr><th width="40%">Phase Name:</th><td>${phase.name}</td></tr>
                                        <tr><th>Project:</th><td>${phase.project_name}</td></tr>
                                        <tr><th>Order:</th><td>${phase.phase_order}</td></tr>
                                        <tr><th>Status:</th><td><span class="badge ${phase.status === 'pending' ? 'bg-warning' : phase.status === 'in_progress' ? 'bg-info' : 'bg-success'}">${phase.status.charAt(0).toUpperCase() + phase.status.slice(1).replace('_', ' ')}</span></td></tr>
                                        <tr><th>Description:</th><td>${phase.description || 'No description'}</td></tr>
                                    </table>
                                </div>
                                <div class="col-md-6">
                                    <h6>Statistics</h6>
                                    <table class="table table-sm">
                                        <tr><th width="60%">Total Activities:</th><td><span class="badge bg-primary">${activities.length}</span></td></tr>
                                        <tr><th>Created:</th><td>${new Date(phase.created_at).toLocaleDateString()}</td></tr>
                                    </table>
                                </div>
                            </div>
                        `;
                        
                        if (activities.length > 0) {
                            html += `
                                <h6 class="mb-3">Associated Activities</h6>
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover">
                                        <thead>
                                            <tr>
                                                <th>ID</th>
                                                <th>Activity Name</th>
                                                <th>Status</th>
                                                <th>Description</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                            `;
                            
                            activities.forEach(activity => {
                                const statusBadge = activity.status === 'pending' ? 'warning' : 
                                                   activity.status === 'in_progress' ? 'info' : 'success';
                                
                                html += `
                                    <tr>
                                        <td>#${activity.id}</td>
                                        <td>${activity.name}</td>
                                        <td><span class="badge bg-${statusBadge}">${activity.status.charAt(0).toUpperCase() + activity.status.slice(1).replace('_', ' ')}</span></td>
                                        <td>${activity.description || 'No description'}</td>
                                    </tr>
                                `;
                            });
                            
                            html += `
                                        </tbody>
                                    </table>
                                </div>
                            `;
                        } else {
                            html += `
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle me-2"></i>
                                    No activities found for this phase.
                                </div>
                            `;
                        }
                        
                        $('#viewPhaseActivitiesModalLabel').text(`Activities: ${phaseName}`);
                        $('#phaseActivitiesContent').html(html);
                        $('#viewPhaseActivitiesModal').modal('show');
                    } else {
                        Swal.fire('Error!', response.message, 'error');
                    }
                },
                error: function() {
                    hideLoading();
                    Swal.fire('Error!', 'Failed to load phase activities', 'error');
                }
            });
        });
        
        // View Activity Sub-Activities button click
        $(document).on('click', '.view-activity-sub-activities', function() {
            const activityId = $(this).data('activity-id');
            const activityName = $(this).data('activity-name');
            
            showLoading();
            $.ajax({
                url: '<?= basename($_SERVER['PHP_SELF']) ?>',
                method: 'POST',
                data: {
                    ajax: true,
                    action: 'get_activity_sub_activities',
                    activity_id: activityId
                },
                success: function(response) {
                    hideLoading();
                    if (response.success) {
                        const subActivities = response.sub_activities;
                        const activity = response.activity;
                        
                        let html = `
                            <div class="row mb-4">
                                <div class="col-md-6">
                                    <h6>Activity Details</h6>
                                    <table class="table table-sm">
                                        <tr><th width="40%">Activity Name:</th><td>${activity.name}</td></tr>
                                        <tr><th>Project:</th><td>${activity.project_name}</td></tr>
                                        <tr><th>Status:</th><td><span class="badge ${activity.status === 'pending' ? 'bg-warning' : activity.status === 'in_progress' ? 'bg-info' : 'bg-success'}">${activity.status.charAt(0).toUpperCase() + activity.status.slice(1).replace('_', ' ')}</span></td></tr>
                                        <tr><th>Description:</th><td>${activity.description || 'No description'}</td></tr>
                                    </table>
                                </div>
                                <div class="col-md-6">
                                    <h6>Statistics</h6>
                                    <table class="table table-sm">
                                        <tr><th width="60%">Total Sub-Activities:</th><td><span class="badge bg-primary">${subActivities.length}</span></td></tr>
                                        <tr><th>Created:</th><td>${new Date(activity.created_at).toLocaleDateString()}</td></tr>
                                    </table>
                                </div>
                            </div>
                        `;
                        
                        if (subActivities.length > 0) {
                            html += `
                                <h6 class="mb-3">Associated Sub-Activities</h6>
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover">
                                        <thead>
                                            <tr>
                                                <th>ID</th>
                                                <th>Sub-Activity Name</th>
                                                <th>Status</th>
                                                <th>Description</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                            `;
                            
                            subActivities.forEach(subActivity => {
                                const statusBadge = subActivity.status === 'pending' ? 'warning' : 
                                                   subActivity.status === 'in_progress' ? 'info' : 'success';
                                
                                html += `
                                    <tr>
                                        <td>#${subActivity.id}</td>
                                        <td>${subActivity.name}</td>
                                        <td><span class="badge bg-${statusBadge}">${subActivity.status.charAt(0).toUpperCase() + subActivity.status.slice(1).replace('_', ' ')}</span></td>
                                        <td>${subActivity.description || 'No description'}</td>
                                    </tr>
                                `;
                            });
                            
                            html += `
                                        </tbody>
                                    </table>
                                </div>
                            `;
                        } else {
                            html += `
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle me-2"></i>
                                    No sub-activities found for this activity.
                                </div>
                            `;
                        }
                        
                        $('#viewActivitySubActivitiesModalLabel').text(`Sub-Activities: ${activityName}`);
                        $('#activitySubActivitiesContent').html(html);
                        $('#viewActivitySubActivitiesModal').modal('show');
                    } else {
                        Swal.fire('Error!', response.message, 'error');
                    }
                },
                error: function() {
                    hideLoading();
                    Swal.fire('Error!', 'Failed to load activity sub-activities', 'error');
                }
            });
        });
        
        // Form submission handlers
        $('#addProjectForm').submit(function(e) {
            e.preventDefault();
            submitForm('add_project_bulk', $(this).serialize());
        });
        
        $('#editProjectForm').submit(function(e) {
            e.preventDefault();
            submitForm('edit_project', $(this).serialize());
        });
        
        $('#addPhaseForm').submit(function(e) {
            e.preventDefault();
            submitForm('add_phases_bulk', $(this).serialize());
        });
        
        $('#editPhaseForm').submit(function(e) {
            e.preventDefault();
            submitForm('edit_phase', $(this).serialize());
        });
        
        $('#addActivityForm').submit(function(e) {
            e.preventDefault();
            submitForm('add_activities_bulk', $(this).serialize());
        });
        
        $('#editActivityForm').submit(function(e) {
            e.preventDefault();
            submitForm('edit_activity', $(this).serialize());
        });
        
        $('#addSubActivityForm').submit(function(e) {
            e.preventDefault();
            submitForm('add_sub_activities_bulk', $(this).serialize());
        });
        
        $('#editSubActivityForm').submit(function(e) {
            e.preventDefault();
            submitForm('edit_sub_activity', $(this).serialize());
        });
        
        // User assignment form handler
        $('#userAssignmentForm').submit(function(e) {
            e.preventDefault();
            submitForm('assign_user_to_item', $(this).serialize());
        });
        
        // User revocation form handler
        $('#userRevocationForm').submit(function(e) {
            e.preventDefault();
            submitForm('revoke_user_from_item', $(this).serialize());
        });
        
        // Initialize search functionality
        $('#searchProjects').on('keyup', function() {
            if (projectsTable) {
                projectsTable.search(this.value).draw();
            }
        });
        
        $('#searchPhases').on('keyup', function() {
            if (phasesTable) {
                phasesTable.search(this.value).draw();
            }
        });
        
        $('#searchActivities').on('keyup', function() {
            if (activitiesTable) {
                activitiesTable.search(this.value).draw();
            }
        });
        
        $('#searchSubActivities').on('keyup', function() {
            if (subActivitiesTable) {
                subActivitiesTable.search(this.value).draw();
            }
        });
        
        // Load activities when phase is selected in sub-activity modal
        $('#sub_activity_phase_id').change(function() {
            const phaseId = $(this).val();
            const activitySelect = $('#sub_activity_activity_id');
            
            if (phaseId) {
                showLoading();
                $.ajax({
                    url: '<?= basename($_SERVER['PHP_SELF']) ?>',
                    method: 'POST',
                    data: {
                        ajax: true,
                        action: 'get_activities_by_phase',
                        phase_id: phaseId
                    },
                    success: function(response) {
                        hideLoading();
                        if (response.success) {
                            activitySelect.html('<option value="">-- Select Activity --</option>');
                            response.activities.forEach(activity => {
                                activitySelect.append(`<option value="${activity.id}">${activity.name}</option>`);
                            });
                            activitySelect.prop('disabled', false);
                        }
                    },
                    error: function() {
                        hideLoading();
                        activitySelect.html('<option value="">-- Error loading activities --</option>');
                        activitySelect.prop('disabled', false);
                    }
                });
            } else {
                activitySelect.html('<option value="">-- Select Activity --</option>');
                activitySelect.prop('disabled', true);
            }
        });
        
        // Reset field counts when modals are hidden
        $('#addProjectModal').on('hidden.bs.modal', function() {
            projectFieldCount = 1;
        });
        
        $('#addPhaseModal').on('hidden.bs.modal', function() {
            phaseFieldCount = 1;
        });
        
        $('#addActivityModal').on('hidden.bs.modal', function() {
            activityFieldCount = 1;
        });
        
        $('#addSubActivityModal').on('hidden.bs.modal', function() {
            subActivityFieldCount = 1;
        });
    });
    
    // ========== UTILITY FUNCTIONS ==========
    
    function showLoading() {
        $('#loadingOverlay').fadeIn();
    }
    
    function hideLoading() {
        $('#loadingOverlay').fadeOut();
    }
    
    function showToast(title, message, type = 'info') {
        const toast = $('#toastNotification');
        const toastMessage = $('#toastMessage');
        
        const toastHeader = toast.find('.toast-header');
        toastHeader.removeClass('bg-success bg-danger bg-warning bg-info');
        
        switch(type) {
            case 'success':
                toastHeader.addClass('bg-success');
                break;
            case 'error':
                toastHeader.addClass('bg-danger');
                break;
            case 'warning':
                toastHeader.addClass('bg-warning');
                break;
            default:
                toastHeader.addClass('bg-info');
        }
        
        toastMessage.html(`<strong>${title}</strong><br>${message}`);
        toast.addClass('show');
        
        setTimeout(() => {
            hideToast();
        }, 5000);
    }
    
    function hideToast() {
        $('#toastNotification').removeClass('show');
    }
    
    // Notification functions
    function loadNotifications() {
        $.ajax({
            url: '<?= basename($_SERVER['PHP_SELF']) ?>',
            method: 'POST',
            data: {
                ajax: true,
                action: 'get_notifications'
            },
            success: function(response) {
                if (response.success) {
                    updateNotificationList(response.notifications);
                    updateNotificationCount();
                }
            },
            error: function() {
                // Silently fail
            }
        });
    }
    
    function updateNotificationList(notifications) {
        const notificationList = $('#notificationList');
        
        if (notifications.length === 0) {
            notificationList.html(`
                <div class="notification-item text-center p-4">
                    <i class="fas fa-bell-slash fa-2x text-muted mb-2"></i>
                    <p class="text-muted mb-0">No unread notifications</p>
                </div>
            `);
            return;
        }
        
        let html = '';
        notifications.forEach(notification => {
            const isUnread = notification.is_read === 0;
            const timeAgo = getTimeAgo(notification.created_at);
            const typeClass = notification.type || 'info';
            
            html += `
                <div class="notification-item ${isUnread ? 'unread' : ''}" onclick="markNotificationAsRead(${notification.id})">
                    <div class="notification-title">
                        <span>${notification.title}</span>
                        <span class="notification-type-indicator notification-type-${typeClass}"></span>
                    </div>
                    <div class="notification-message">
                        ${notification.message}
                    </div>
                    <div class="notification-time">
                        <i class="far fa-clock me-1"></i> ${timeAgo}
                    </div>
                </div>
            `;
        });
        
        notificationList.html(html);
    }
    
    function updateNotificationCount() {
        $.ajax({
            url: '<?= basename($_SERVER['PHP_SELF']) ?>',
            method: 'POST',
            data: {
                ajax: true,
                action: 'get_unread_count'
            },
            success: function(response) {
                if (response.success) {
                    const count = response.count;
                    const notificationCount = $('#notificationCount');
                    
                    if (count > 0) {
                        if (notificationCount.length === 0) {
                            $('#notificationBell').append(`<span class="notification-count" id="notificationCount">${count}</span>`);
                        } else {
                            notificationCount.text(count);
                        }
                    } else {
                        notificationCount.remove();
                    }
                }
            },
            error: function() {
                // Silently fail
            }
        });
    }
    
    function getTimeAgo(dateString) {
        const date = new Date(dateString);
        const now = new Date();
        const diffInSeconds = Math.floor((now - date) / 1000);
        
        if (diffInSeconds < 60) {
            return 'just now';
        } else if (diffInSeconds < 3600) {
            const minutes = Math.floor(diffInSeconds / 60);
            return `${minutes} minute${minutes !== 1 ? 's' : ''} ago`;
        } else if (diffInSeconds < 86400) {
            const hours = Math.floor(diffInSeconds / 3600);
            return `${hours} hour${hours !== 1 ? 's' : ''} ago`;
        } else if (diffInSeconds < 604800) {
            const days = Math.floor(diffInSeconds / 86400);
            return `${days} day${days !== 1 ? 's' : ''} ago`;
        } else {
            return date.toLocaleDateString();
        }
    }
    
    function markNotificationAsRead(notificationId) {
        $.ajax({
            url: '<?= basename($_SERVER['PHP_SELF']) ?>',
            method: 'POST',
            data: {
                ajax: true,
                action: 'mark_notification_read',
                notification_id: notificationId
            },
            success: function(response) {
                if (response.success) {
                    loadNotifications(); // Reload notifications
                }
            }
        });
    }
    
    function markAllNotificationsAsRead() {
        $.ajax({
            url: '<?= basename($_SERVER['PHP_SELF']) ?>',
            method: 'POST',
            data: {
                ajax: true,
                action: 'mark_all_notifications_read'
            },
            success: function(response) {
                if (response.success) {
                    loadNotifications(); // Reload notifications
                }
            }
        });
    }
    
    function toggleNotifications() {
        const dropdown = $('#notificationDropdown');
        const isVisible = dropdown.is(':visible');
        
        // Toggle dropdown
        if (isVisible) {
            dropdown.hide();
        } else {
            dropdown.show();
            // Load fresh notifications when opening
            loadNotifications();
        }
        
        // Close dropdown when clicking outside
        if (!isVisible) {
            setTimeout(() => {
                $(document).on('click.notification', function(e) {
                    if (!$(e.target).closest('.notification-container').length) {
                        dropdown.hide();
                        $(document).off('click.notification');
                    }
                });
            }, 100);
        }
    }
    
    // Dynamic field management functions
    function addProjectField() {
        projectFieldCount++;
        const newField = `
            <div class="dynamic-field-group" id="projectGroup_${projectFieldCount}">
                <div class="field-header">
                    <div class="field-count">Project #${projectFieldCount}</div>
                    <div>
                        <button type="button" class="remove-field-btn" onclick="removeField('projectGroup_${projectFieldCount}')" title="Remove this field">
                            <i class="fas fa-minus"></i>
                        </button>
                        <button type="button" class="add-field-btn ms-1" onclick="addProjectField()" title="Add another project">
                            <i class="fas fa-plus"></i>
                        </button>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Project Name <span class="text-danger">*</span></label>
                            <input type="text" name="names[]" class="form-control form-control-sm" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select name="statuses[]" class="form-select form-select-sm">
                                <option value="pending" selected>Pending</option>
                                <option value="in_progress">In Progress</option>
                                <option value="completed">Completed</option>
                                <option value="terminated">Terminated</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Start Date</label>
                            <input type="date" name="start_dates[]" class="form-control form-control-sm">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">End Date</label>
                            <input type="date" name="end_dates[]" class="form-control form-control-sm">
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Department</label>
                            <select name="department_ids[]" class="form-select form-select-sm">
                                <option value="">-- Select Department --</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?= $dept['id'] ?>"><?= htmlspecialchars($dept['department_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Description</label>
                    <textarea name="descriptions[]" class="form-control form-control-sm" rows="2"></textarea>
                </div>
            </div>
        `;
        $('#projectFieldsContainer').append(newField);
    }
    
    function addPhaseField() {
        phaseFieldCount++;
        const newField = `
            <div class="dynamic-field-group" id="phaseGroup_${phaseFieldCount}">
                <div class="field-header">
                    <div class="field-count">Phase #${phaseFieldCount}</div>
                    <div>
                        <button type="button" class="remove-field-btn" onclick="removeField('phaseGroup_${phaseFieldCount}')" title="Remove this field">
                            <i class="fas fa-minus"></i>
                        </button>
                        <button type="button" class="add-field-btn ms-1" onclick="addPhaseField()" title="Add another phase">
                            <i class="fas fa-plus"></i>
                        </button>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Phase Name <span class="text-danger">*</span></label>
                            <input type="text" name="names[]" class="form-control form-control-sm" required>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label class="form-label">Order <span class="text-danger">*</span></label>
                            <input type="number" name="phase_orders[]" class="form-control form-control-sm" min="1" value="${phaseFieldCount}" required>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select name="statuses[]" class="form-select form-select-sm">
                                <option value="pending" selected>Pending</option>
                                <option value="in_progress">In Progress</option>
                                <option value="completed">Completed</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Start Date</label>
                            <input type="date" name="start_dates[]" class="form-control form-control-sm">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">End Date</label>
                            <input type="date" name="end_dates[]" class="form-control form-control-sm">
                        </div>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Description</label>
                    <textarea name="descriptions[]" class="form-control form-control-sm" rows="2"></textarea>
                </div>
            </div>
        `;
        $('#phaseFieldsContainer').append(newField);
    }
    
    function addActivityField() {
        activityFieldCount++;
        const newField = `
            <div class="dynamic-field-group" id="activityGroup_${activityFieldCount}">
                <div class="field-header">
                    <div class="field-count">Activity #${activityFieldCount}</div>
                    <div>
                        <button type="button" class="remove-field-btn" onclick="removeField('activityGroup_${activityFieldCount}')" title="Remove this field">
                            <i class="fas fa-minus"></i>
                        </button>
                        <button type="button" class="add-field-btn ms-1" onclick="addActivityField()" title="Add another activity">
                            <i class="fas fa-plus"></i>
                        </button>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Activity Name <span class="text-danger">*</span></label>
                            <input type="text" name="names[]" class="form-control form-control-sm" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select name="statuses[]" class="form-select form-select-sm">
                                <option value="pending" selected>Pending</option>
                                <option value="in_progress">In Progress</option>
                                <option value="completed">Completed</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Start Date</label>
                            <input type="date" name="start_dates[]" class="form-control form-control-sm">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">End Date</label>
                            <input type="date" name="end_dates[]" class="form-control form-control-sm">
                        </div>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Description</label>
                    <textarea name="descriptions[]" class="form-control form-control-sm" rows="2"></textarea>
                </div>
            </div>
        `;
        $('#activityFieldsContainer').append(newField);
    }
    
    function addSubActivityField() {
        subActivityFieldCount++;
        const newField = `
            <div class="dynamic-field-group" id="subActivityGroup_${subActivityFieldCount}">
                <div class="field-header">
                    <div class="field-count">Sub-Activity #${subActivityFieldCount}</div>
                    <div>
                        <button type="button" class="remove-field-btn" onclick="removeField('subActivityGroup_${subActivityFieldCount}')" title="Remove this field">
                            <i class="fas fa-minus"></i>
                        </button>
                        <button type="button" class="add-field-btn ms-1" onclick="addSubActivityField()" title="Add another sub-activity">
                            <i class="fas fa-plus"></i>
                        </button>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Sub-Activity Name <span class="text-danger">*</span></label>
                            <input type="text" name="names[]" class="form-control form-control-sm" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select name="statuses[]" class="form-select form-select-sm">
                                <option value="pending" selected>Pending</option>
                                <option value="in_progress">In Progress</option>
                                <option value="completed">Completed</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Start Date</label>
                            <input type="date" name="start_dates[]" class="form-control form-control-sm">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">End Date</label>
                            <input type="date" name="end_dates[]" class="form-control form-control-sm">
                        </div>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Description</label>
                    <textarea name="descriptions[]" class="form-control form-control-sm" rows="2"></textarea>
                </div>
            </div>
        `;
        $('#subActivityFieldsContainer').append(newField);
    }
    
    function removeField(fieldId) {
        $('#' + fieldId).remove();
    }
    
    function changeProject(projectId) {
        window.location.href = '<?= basename($_SERVER['PHP_SELF']) ?>?project_id=' + projectId;
    }
    
    function refreshProjectData() {
        $('#refreshProjectBtn').addClass('fa-spin');
        setTimeout(() => {
            location.reload();
        }, 500);
    }
    
    function refreshData() {
        $('#refreshDataBtn').addClass('fa-spin');
        setTimeout(() => {
            location.reload();
        }, 500);
    }
    
    function searchProjects() {
        const searchTerm = $('#searchProjects').val();
        if (projectsTable) {
            projectsTable.search(searchTerm).draw();
        }
    }
    
    function searchPhases() {
        const searchTerm = $('#searchPhases').val();
        if (phasesTable) {
            phasesTable.search(searchTerm).draw();
        }
    }
    
    function searchActivities() {
        const searchTerm = $('#searchActivities').val();
        if (activitiesTable) {
            activitiesTable.search(searchTerm).draw();
        }
    }
    
    function searchSubActivities() {
        const searchTerm = $('#searchSubActivities').val();
        if (subActivitiesTable) {
            subActivitiesTable.search(searchTerm).draw();
        }
    }
    
    function showStatusOptions(element, level, id, currentStatus) {
        // Close any existing dropdown
        if (currentStatusDropdown) {
            currentStatusDropdown.remove();
            currentStatusDropdown = null;
        }
        
        // ========== ROLE-BASED PERMISSION CHECK ==========
        // PM Employees can only update activity and sub-activity statuses
        if (userRole === 'pm_employee' && (level === 'project' || level === 'phase')) {
            return; // Don't show dropdown for pm_employee on project/phase
        }
        
        // Only Super Admin and PM Managers can terminate projects
        if (level === 'project' && currentStatus === 'terminated' && !['super_admin', 'pm_manager'].includes(userRole)) {
            return; // Don't show dropdown for non-authorized users on terminated projects
        }
        
        // PM Managers cannot change terminated project status (only Super Admin can)
        if (level === 'project' && currentStatus === 'terminated' && userRole === 'pm_manager') {
            return; // Don't show dropdown for PM Managers on terminated projects
        }
        
        // Create dropdown
        const dropdown = document.createElement('div');
        dropdown.className = 'status-dropdown';
        
        let options = '';
        if (level === 'project') {
            options = `
                <div class="status-option" onclick="updateStatus('${level}', ${id}, 'pending')">Pending</div>
                <div class="status-option" onclick="updateStatus('${level}', ${id}, 'in_progress')">In Progress</div>
                <div class="status-option" onclick="updateStatus('${level}', ${id}, 'completed')">Completed</div>`;
            
            // Only Super Admin and PM Managers can terminate projects
            if (['super_admin', 'pm_manager'].includes(userRole)) {
                options += `<div class="status-option" onclick="updateStatus('${level}', ${id}, 'terminated')">Terminated</div>`;
            }
        } else {
            options = `
                <div class="status-option" onclick="updateStatus('${level}', ${id}, 'pending')">Pending</div>
                <div class="status-option" onclick="updateStatus('${level}', ${id}, 'in_progress')">In Progress</div>
                <div class="status-option" onclick="updateStatus('${level}', ${id}, 'completed')">Completed</div>
            `;
        }
        
        dropdown.innerHTML = options;
        
        // Position dropdown
        const rect = element.getBoundingClientRect();
        dropdown.style.top = (rect.bottom + window.scrollY) + 'px';
        dropdown.style.left = (rect.left + window.scrollX) + 'px';
        
        document.body.appendChild(dropdown);
        currentStatusDropdown = dropdown;
        dropdown.style.display = 'block';
        
        // Close dropdown when clicking outside
        setTimeout(() => {
            document.addEventListener('click', function closeDropdown(e) {
                if (!dropdown.contains(e.target) && e.target !== element) {
                    dropdown.remove();
                    document.removeEventListener('click', closeDropdown);
                    currentStatusDropdown = null;
                }
            });
        }, 100);
    }
    
    function updateStatus(level, id, status) {
        showLoading();
        
        $.ajax({
            url: '<?= basename($_SERVER['PHP_SELF']) ?>',
            method: 'POST',
            data: {
                ajax: true,
                action: 'update_status',
                level: level,
                id: id,
                status: status
            },
            success: function(response) {
                hideLoading();
                if (response.success) {
                    showToast('Success', response.message, 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    Swal.fire('Error!', response.message, 'error');
                }
            },
            error: function(xhr, status, error) {
                hideLoading();
                Swal.fire('Error!', 'Failed to update status: ' + error, 'error');
            }
        });
    }
    
    // User assignment functions
    function showUserAssignmentModal(itemType, itemId, itemName, projectId) {
        // Set modal title based on item type
        const levelMap = {
            'project': 'Project',
            'phase': 'Phase',
            'activity': 'Activity',
            'sub_activity': 'Sub-Activity'
        };
        const levelName = levelMap[itemType] || 'Item';
        
        $('#userAssignmentModalTitle').text(`Assign User to ${levelName}: ${itemName}`);
        
        // Set form values
        $('#assign_item_type').val(itemType);
        $('#assign_item_id').val(itemId);
        $('#assign_project_id').val(projectId);
        $('#assign_item_name').val(itemName);
        
        // Reset form
        $('#assign_user_role').val('');
        $('#assign_user_id').html('<option value="">-- Select User --</option>');
        $('#assign_user_id').prop('disabled', true);
        
        // Show modal
        $('#userAssignmentModal').modal('show');
    }
    
    function loadUsersByRole(role) {
        const userSelect = $('#assign_user_id');
        
        if (!role) {
            userSelect.html('<option value="">-- Select User --</option>');
            userSelect.prop('disabled', true);
            return;
        }
        
        showLoading();
        $.ajax({
            url: '<?= basename($_SERVER['PHP_SELF']) ?>',
            method: 'POST',
            data: {
                ajax: true,
                action: 'get_users_by_role',
                role: role
            },
            success: function(response) {
                hideLoading();
                if (response.success) {
                    userSelect.html('<option value="">-- Select User --</option>');
                    response.users.forEach(user => {
                        userSelect.append(`<option value="${user.id}">${user.username} (${user.system_role})</option>`);
                    });
                    userSelect.prop('disabled', false);
                }
            },
            error: function() {
                hideLoading();
                userSelect.html('<option value="">-- Error loading users --</option>');
                userSelect.prop('disabled', false);
            }
        });
    }
    
    function showUserRevocationModal(itemType, itemId, itemName, projectId) {
        // Set modal title based on item type
        const levelMap = {
            'project': 'Project',
            'phase': 'Phase',
            'activity': 'Activity',
            'sub_activity': 'Sub-Activity'
        };
        const levelName = levelMap[itemType] || 'Item';
        
        $('#userRevocationModalTitle').text(`Unassign User from ${levelName}: ${itemName}`);
        
        // Set form values
        $('#revoke_item_type').val(itemType);
        $('#revoke_item_id').val(itemId);
        $('#revoke_project_id').val(projectId);
        $('#revoke_item_name').val(itemName);
        
        // Load assigned users
        loadAssignedUsers(itemType, itemId, 'revoke_user_id');
        
        // Show modal
        $('#userRevocationModal').modal('show');
    }
    
    function loadAssignedUsers(itemType, itemId, targetSelectId) {
        const userSelect = $('#' + targetSelectId);
        
        showLoading();
        $.ajax({
            url: '<?= basename($_SERVER['PHP_SELF']) ?>',
            method: 'POST',
            data: {
                ajax: true,
                action: 'get_assigned_users',
                item_type: itemType,
                item_id: itemId
            },
            success: function(response) {
                hideLoading();
                if (response.success) {
                    userSelect.html('<option value="">-- Select User --</option>');
                    if (response.assigned_users.length === 0) {
                        userSelect.append('<option value="" disabled>No users assigned</option>');
                    } else {
                        response.assigned_users.forEach(user => {
                            userSelect.append(`<option value="${user.id}">${user.username} (${user.system_role}) - Assigned: ${new Date(user.assigned_at).toLocaleDateString()}</option>`);
                        });
                    }
                }
            },
            error: function() {
                hideLoading();
                userSelect.html('<option value="">-- Error loading users --</option>');
            }
        });
    }
    
    // Generic form submission function
    function submitForm(action, formData) {
        showLoading();
        
        $.ajax({
            url: '<?= basename($_SERVER['PHP_SELF']) ?>',
            method: 'POST',
            data: {
                ajax: true,
                action: action,
                ...serializeToObject(formData)
            },
            success: function(response) {
                hideLoading();
                if (response.success) {
                    showToast('Success', response.message, 'success');
                    $('.modal').modal('hide');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    Swal.fire('Error!', response.message, 'error');
                }
            },
            error: function(xhr, status, error) {
                hideLoading();
                Swal.fire('Error!', 'Failed to process request: ' + error, 'error');
            }
        });
    }
    
    // Helper function to convert serialized form to object
    function serializeToObject(serializedString) {
        const obj = {};
        const pairs = serializedString.split('&');
        
        for (let i = 0; i < pairs.length; i++) {
            const pair = pairs[i].split('=');
            const key = decodeURIComponent(pair[0]);
            const value = decodeURIComponent(pair[1] || '');
            
            if (key.endsWith('[]')) {
                const arrayKey = key.slice(0, -2);
                if (!obj[arrayKey]) {
                    obj[arrayKey] = [];
                }
                obj[arrayKey].push(value);
            } else {
                obj[key] = value;
            }
        }
        
        return obj;
    }
    
    // Clean up notification polling when leaving page
    window.addEventListener('beforeunload', function() {
        if (notificationCheckInterval) {
            clearInterval(notificationCheckInterval);
        }
    });
    </script>
</body>
</html>
<?php $conn->close(); ?>