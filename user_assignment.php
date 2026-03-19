<?php
// User Assignment Management System for Dashen Bank with Enhanced Notifications
session_start();

include 'db.php';

// Check if user has permission
$user_role = $_SESSION['system_role'] ?? '';
$current_user_id = $_SESSION['user_id'] ?? 0;
$current_username = $_SESSION['username'] ?? '';

$allowed_roles = ['super_admin', 'admin', 'pm_manager'];

if (!in_array($user_role, $allowed_roles)) {
    header('Location: dashboard.php');
    exit();
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

function getRoleById($conn, $user_id) {
    $stmt = $conn->prepare("SELECT system_role FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    return $result['system_role'] ?? '';
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
    
    // Get assigned by user details for notification message
    $assigned_by_stmt = $conn->prepare("SELECT username, system_role FROM users WHERE id = ?");
    $assigned_by_stmt->bind_param("i", $assigned_by_id);
    $assigned_by_stmt->execute();
    $assigned_by_result = $assigned_by_stmt->get_result()->fetch_assoc();
    $assigned_by_name = $assigned_by_result['username'] ?? 'System';
    $assigned_by_role = $assigned_by_result['system_role'] ?? '';
    
    // Determine who is assigning
    $assigned_by_text = '';
    if ($assigned_by_role === 'super_admin') {
        $assigned_by_text = "Super Admin $assigned_by_name";
    } elseif ($assigned_by_role === 'pm_manager') {
        $assigned_by_text = "PM Manager $assigned_by_name";
    } else {
        $assigned_by_text = $assigned_by_name;
    }
    
    $title = "New Assignment";
    $message = "$assigned_by_text assigned you to $level_name: $item_name";
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
    
    // Get revoked by user details for notification message
    $revoked_by_stmt = $conn->prepare("SELECT username, system_role FROM users WHERE id = ?");
    $revoked_by_stmt->bind_param("i", $revoked_by_id);
    $revoked_by_stmt->execute();
    $revoked_by_result = $revoked_by_stmt->get_result()->fetch_assoc();
    $revoked_by_name = $revoked_by_result['username'] ?? 'System';
    $revoked_by_role = $revoked_by_result['system_role'] ?? '';
    
    // Determine who is revoking
    $revoked_by_text = '';
    if ($revoked_by_role === 'super_admin') {
        $revoked_by_text = "Super Admin $revoked_by_name";
    } elseif ($revoked_by_role === 'pm_manager') {
        $revoked_by_text = "PM Manager $revoked_by_name";
    } else {
        $revoked_by_text = $revoked_by_name;
    }
    
    $title = "Assignment Removed";
    $message = "$revoked_by_text unassigned you from $level_name: $item_name";
    return createNotification($conn, $revoked_from_id, $title, $message, 'warning', $item_type, $item_id);
}

// ========== CASCADE UNASSIGNMENT FUNCTION ==========
function cascadeUnassignProjectFromPMEmployees($conn, $project_id, $revoked_by_id) {
    // Get all PM Employees assigned to this project
    $stmt = $conn->prepare("SELECT DISTINCT ua.user_id, u.username, u.system_role, p.name as project_name 
                           FROM user_assignments ua 
                           JOIN users u ON ua.user_id = u.id 
                           JOIN projects p ON ua.project_id = p.id 
                           WHERE ua.project_id = ? AND u.system_role = 'pm_employee'");
    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $notified_count = 0;
    while ($employee = $result->fetch_assoc()) {
        // Unassign PM Employee from the project and all related items
        $delete_stmt = $conn->prepare("DELETE FROM user_assignments WHERE user_id = ? AND project_id = ?");
        $delete_stmt->bind_param("ii", $employee['user_id'], $project_id);
        
        if ($delete_stmt->execute()) {
            // Send notification to PM Employee
            $title = "Project Assignment Removed";
            $message = "Super Admin unassigned you from Project: " . $employee['project_name'] . ". All related assignments have been removed.";
            if (createNotification($conn, $employee['user_id'], $title, $message, 'warning', 'project', $project_id)) {
                $notified_count++;
            }
        }
    }
    
    return $notified_count;
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

// ========== USER ASSIGNMENT MANAGEMENT FUNCTIONS ==========
function assignUserToItem($conn, $assigned_by_id, $assigned_to_id, $item_type, $item_id, $project_id, $item_name) {
    // Check if user is already assigned
   // Handle special case for sub_activity vs subactivity column name
$column_name = $item_type;
if ($item_type === 'sub_activity') {
    $column_name = 'subactivity';
}
$check_stmt = $conn->prepare("SELECT id FROM user_assignments WHERE user_id = ? AND {$column_name}_id = ?");
    $check_stmt->bind_param("ii", $assigned_to_id, $item_id);
    $check_stmt->execute();
    
    if ($check_stmt->get_result()->num_rows > 0) {
        return ['success' => false, 'message' => 'User is already assigned to this item'];
    }
    
    // For project assignments, project_id = item_id
    if ($item_type === 'project') {
        $project_id = $item_id;
    }
    
  // Prepare the assignment data based on item type
$fields = ['user_id', 'project_id', 'assigned_by'];
$values = [$assigned_to_id, $project_id, $assigned_by_id];
$types = "iii";

// Add the specific item type field (except for project)
if ($item_type !== 'project') {
    // Handle special case for sub_activity vs subactivity column name
    $column_name = $item_type;
    if ($item_type === 'sub_activity') {
        $column_name = 'subactivity';
    }
    $fields[] = "{$column_name}_id";
    $values[] = $item_id;
    $types .= "i";
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
        return ['success' => false, 'message' => 'Failed to assign user: ' . $conn->error];
    }
}

function revokeUserFromItem($conn, $revoked_by_id, $revoked_from_id, $item_type, $item_id, $project_id, $item_name) {
    // Check if user is assigned
    // Check if user is assigned
$column_name = $item_type;
if ($item_type === 'sub_activity') {
    $column_name = 'subactivity';
}
$check_stmt = $conn->prepare("SELECT id FROM user_assignments WHERE user_id = ? AND {$column_name}_id = ?");
    $check_stmt->bind_param("ii", $revoked_from_id, $item_id);
    $check_stmt->execute();
    
    if ($check_stmt->get_result()->num_rows === 0) {
        return ['success' => false, 'message' => 'User is not assigned to this item'];
    }
    
    // Get user role before deletion for notification
    $user_role = getRoleById($conn, $revoked_from_id);
    
    // Revoke the assignment
    // Revoke the assignment
$delete_stmt = $conn->prepare("DELETE FROM user_assignments WHERE user_id = ? AND {$column_name}_id = ?");
    $delete_stmt->bind_param("ii", $revoked_from_id, $item_id);
    
    if ($delete_stmt->execute()) {
        // Send revocation notification
        notifyRevocation($conn, $revoked_by_id, $revoked_from_id, $item_type, $item_name, $item_id, $project_id);
        
        // If Super Admin is unassigning a project from PM Manager, cascade unassign PM Employees
        if ($item_type === 'project' && $user_role === 'pm_manager' && getRoleById($conn, $revoked_by_id) === 'super_admin') {
            $cascade_count = cascadeUnassignProjectFromPMEmployees($conn, $item_id, $revoked_by_id);
            return ['success' => true, 'message' => 'PM Manager unassigned successfully. ' . $cascade_count . ' PM Employees also unassigned and notified.'];
        }
        
        return ['success' => true, 'message' => 'User unassigned successfully'];
    } else {
        return ['success' => false, 'message' => 'Failed to unassign user'];
    }
}

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

// PAGINATION SETTINGS
$items_per_page = 10;
$current_page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($current_page - 1) * $items_per_page;

// Handle POST actions
$action = post_val('action');
$message = '';
$message_type = '';

// Handle User Assignment with Enhanced Notifications
if ($action === 'assign_user') {
    $user_id = (int)post_val('assign_user_id');
    $level_type = post_val('assign_level_type');
    
    // Get the correct level_id based on the selected type
    $level_id = 0;
    switch($level_type) {
        case 'project':
            $level_id = (int)post_val('assign_project_id');
            break;
        case 'phase':
            $level_id = (int)post_val('assign_phase_id');
            break;
        case 'activity':
            $level_id = (int)post_val('assign_activity_id');
            break;
        case 'sub_activity':
            $level_id = (int)post_val('assign_subactivity_id');
            break;
    }
    
    $allowed = ['project','phase','activity','sub_activity'];
    
    // Check if user is trying to assign themselves (only for super_admin)
    if ($user_role === 'super_admin' && $user_id == $current_user_id) {
        $message = "Error: Super Admin cannot assign themselves! You have access to everything by default.";
        $message_type = "danger";
    } elseif ($user_id && $level_id && $level_type && in_array($level_type, $allowed)) {
        // Check if project is terminated
        $project_id = null;
        $valid_assignment = true;
        $user_name = '';
        $project_name = '';
        $item_name = '';
        
        // Get user name and role for validation
        $user_result = mysqli_query($conn, "SELECT username, system_role FROM users WHERE id = {$user_id}");
        if ($user_row = mysqli_fetch_assoc($user_result)) {
            $user_name = $user_row['username'];
            $user_role_to_assign = $user_row['system_role'];
        }
        
        // Get project ID and name based on level type
        if ($level_type === 'project') {
            $project_id = $level_id;
            $result = mysqli_query($conn, "SELECT name FROM projects WHERE id = {$project_id}");
            if ($row = mysqli_fetch_assoc($result)) {
                $project_name = $row['name'];
                $item_name = $row['name'];
            }
        } elseif ($level_type === 'phase') {
            $result = mysqli_query($conn, "SELECT p.id as project_id, p.name as project_name, ph.name as phase_name FROM phases ph JOIN projects p ON ph.project_id = p.id WHERE ph.id = {$level_id}");
            if ($row = mysqli_fetch_assoc($result)) {
                $project_id = $row['project_id'];
                $project_name = $row['project_name'];
                $item_name = $row['phase_name'];
            }
        } elseif ($level_type === 'activity') {
            $result = mysqli_query($conn, 
                "SELECT p.id as project_id, p.name as project_name, a.name as activity_name
                 FROM activities a 
                 JOIN phases ph ON a.phase_id = ph.id 
                 JOIN projects p ON ph.project_id = p.id 
                 WHERE a.id = {$level_id}");
            if ($row = mysqli_fetch_assoc($result)) {
                $project_id = $row['project_id'];
                $project_name = $row['project_name'];
                $item_name = $row['activity_name'];
            }
        } elseif ($level_type === 'sub_activity') {
            $result = mysqli_query($conn, 
                "SELECT p.id as project_id, p.name as project_name, s.name as sub_name
                 FROM sub_activities s 
                 JOIN activities a ON s.activity_id = a.id 
                 JOIN phases ph ON a.phase_id = ph.id 
                 JOIN projects p ON ph.project_id = p.id 
                 WHERE s.id = {$level_id}");
            if ($row = mysqli_fetch_assoc($result)) {
                $project_id = $row['project_id'];
                $project_name = $row['project_name'];
                $item_name = $row['sub_name'];
            }
        }
        
        // Check if project exists
        if ($project_id) {
            $check_project = mysqli_query($conn, "SELECT status FROM projects WHERE id = {$project_id}");
            if ($check_project && mysqli_num_rows($check_project) > 0) {
                $project_data = mysqli_fetch_assoc($check_project);
                if ($project_data['status'] === 'terminated') {
                    $message = "Cannot assign user: Project '{$project_name}' is terminated!";
                    $message_type = "danger";
                    $valid_assignment = false;
                }
            } else {
                $message = "Error: Project not found!";
                $message_type = "danger";
                $valid_assignment = false;
            }
        } else {
            $message = "Error: Could not find project information!";
            $message_type = "danger";
            $valid_assignment = false;
        }
        
        // ROLE-BASED PERMISSION CHECKS
        if ($valid_assignment) {
            // Check if PM Manager is trying to assign to Super Admin
            if ($user_role === 'pm_manager' && $user_role_to_assign === 'super_admin') {
                $message = "Error: PM Managers cannot assign anything to Super Admin!";
                $message_type = "danger";
                $valid_assignment = false;
            }
            // Check if Super Admin is trying to assign phases/activities/sub-activities to PM Manager
            elseif ($user_role === 'super_admin' && $user_role_to_assign === 'pm_manager' && $level_type !== 'project') {
                $message = "Error: Super Admin can only assign PROJECTS to PM Managers, not phases, activities, or sub-activities!";
                $message_type = "danger";
                $valid_assignment = false;
            }
            // For PM Managers: Check if they have access to this project
            elseif ($user_role === 'pm_manager') {
                $check_manager_access = mysqli_query($conn, 
                    "SELECT id FROM user_assignments 
                     WHERE user_id = {$current_user_id} AND project_id = {$project_id}");
                
                if (mysqli_num_rows($check_manager_access) === 0) {
                    $message = "Error: You do not have access to assign users to project '{$project_name}'. You can only assign users to projects you are assigned to.";
                    $message_type = "danger";
                    $valid_assignment = false;
                }
            }
        }
        
        // For non-project assignments, verify user is assigned to the project
        if ($valid_assignment && $level_type !== 'project' && $project_id) {
            // Skip this check for PM Managers assigning to PM Employees (they might assign directly to phases)
            if ($user_role === 'pm_manager' && $user_role_to_assign === 'pm_employee') {
                // PM Managers can assign PM Employees directly to phases/activities without project assignment
                // This is allowed as per requirements
            } else {
                $check = mysqli_query($conn, 
                    "SELECT id FROM user_assignments 
                     WHERE user_id = {$user_id} AND project_id = {$project_id}");
                if (mysqli_num_rows($check) === 0) {
                    $message = "Error: User '{$user_name}' must first be assigned to the project '{$project_name}' before being assigned to its components!";
                    $message_type = "danger";
                    $valid_assignment = false;
                }
            }
        }
        
        // Check if user is already assigned at this level
        if ($valid_assignment) {
            // Build WHERE clause based on level type
            $where_clause = "";
            switch($level_type) {
                case 'project':
                    $where_clause = "user_id = {$user_id} AND project_id = {$level_id} AND phase_id IS NULL AND activity_id IS NULL AND subactivity_id IS NULL";
                    break;
                case 'phase':
                    $where_clause = "user_id = {$user_id} AND phase_id = {$level_id}";
                    break;
                case 'activity':
                    $where_clause = "user_id = {$user_id} AND activity_id = {$level_id}";
                    break;
                case 'sub_activity':
                    $where_clause = "user_id = {$user_id} AND subactivity_id = {$level_id}";
                    break;
            }
            
            $check_existing = mysqli_query($conn, 
                "SELECT id FROM user_assignments WHERE {$where_clause}");
            
            if (mysqli_num_rows($check_existing) > 0) {
                $message = "User '{$user_name}' is already assigned to this " . str_replace('_', ' ', $level_type) . "!";
                $message_type = "warning";
                $valid_assignment = false;
            }
        }
        
        if ($valid_assignment) {
            // Use the enhanced assignment function
            $result = assignUserToItem($conn, $current_user_id, $user_id, $level_type, $level_id, $project_id, $item_name);
            
            if ($result['success']) {
                $message = "User '{$user_name}' successfully assigned to " . str_replace('_', ' ', $level_type) . " '{$item_name}'!";
                $message_type = "success";
            } else {
                $message = $result['message'];
                $message_type = "danger";
            }
        }
    } else {
        // Only show error if it's actually missing required fields
        $missing_fields = [];
        if (!$user_id) $missing_fields[] = "User";
        if (!$level_type) $missing_fields[] = "Assignment Level";
        if (!$level_id) $missing_fields[] = "Assignment Target";
        
        if (!empty($missing_fields)) {
            $message = "Error: Please fill all required fields! Missing: " . implode(', ', $missing_fields);
            $message_type = "danger";
        }
    }
}

// Handle Bulk Assignment with role-based permissions and notifications
if ($action === 'bulk_assign') {
    $user_ids = isset($_POST['user_ids']) ? $_POST['user_ids'] : [];
    $level_type = post_val('bulk_level_type');
    $level_id = (int)post_val('bulk_level_id');

    $allowed = ['project','phase','activity','sub_activity'];
    
    // Check if all required fields are present
    if (!empty($user_ids) && $level_id && $level_type && in_array($level_type, $allowed)) {
        // Check if any user is trying to assign themselves (for super_admin)
        if ($user_role === 'super_admin') {
            foreach ($user_ids as $uid) {
                if ((int)$uid == $current_user_id) {
                    $message = "Error: Super Admin cannot assign themselves! You have access to everything by default.";
                    $message_type = "danger";
                    // Remove current user from the list
                    $user_ids = array_filter($user_ids, function($uid) use ($current_user_id) {
                        return (int)$uid != $current_user_id;
                    });
                    if (empty($user_ids)) {
                        break;
                    }
                }
            }
        }
        
        if (!empty($user_ids)) {
            // Check if project is terminated
            $project_id = null;
            $valid_assignment = true;
            $project_name = '';
            $target_name = '';
            
            // Get project ID and name based on level type
            if ($level_type === 'project') {
                $project_id = $level_id;
                $result = mysqli_query($conn, "SELECT name FROM projects WHERE id = {$project_id}");
                if ($row = mysqli_fetch_assoc($result)) {
                    $project_name = $row['name'];
                    $target_name = $row['name'];
                }
            } else {
                // Get project ID and name based on level type
                if ($level_type === 'phase') {
                    $result = mysqli_query($conn, 
                        "SELECT p.id as project_id, p.name as project_name, ph.name as target_name 
                         FROM phases ph 
                         JOIN projects p ON ph.project_id = p.id 
                         WHERE ph.id = {$level_id}");
                } elseif ($level_type === 'activity') {
                    $result = mysqli_query($conn, 
                        "SELECT p.id as project_id, p.name as project_name, a.name as target_name 
                         FROM activities a 
                         JOIN phases ph ON a.phase_id = ph.id 
                         JOIN projects p ON ph.project_id = p.id 
                         WHERE a.id = {$level_id}");
                } elseif ($level_type === 'sub_activity') {
                    $result = mysqli_query($conn, 
                        "SELECT p.id as project_id, p.name as project_name, s.name as target_name 
                         FROM sub_activities s 
                         JOIN activities a ON s.activity_id = a.id 
                         JOIN phases ph ON a.phase_id = ph.id 
                         JOIN projects p ON ph.project_id = p.id 
                         WHERE s.id = {$level_id}");
                }
                
                if ($row = mysqli_fetch_assoc($result)) {
                    $project_id = $row['project_id'];
                    $project_name = $row['project_name'];
                    $target_name = $row['target_name'];
                }
            }
            
            // Check if project exists and is not terminated
            if ($project_id) {
                $check_project = mysqli_query($conn, "SELECT status FROM projects WHERE id = {$project_id}");
                if ($check_project && mysqli_num_rows($check_project) > 0) {
                    $project_data = mysqli_fetch_assoc($check_project);
                    if ($project_data['status'] === 'terminated') {
                        $message = "Cannot assign users: Project '{$project_name}' is terminated!";
                        $message_type = "danger";
                        $valid_assignment = false;
                    }
                } else {
                    $message = "Error: Project not found!";
                    $message_type = "danger";
                    $valid_assignment = false;
                }
            } else {
                $message = "Error: Could not find project information!";
                $message_type = "danger";
                $valid_assignment = false;
            }
            
            // For PM Managers: Check if they have access to this project
            if ($valid_assignment && $user_role === 'pm_manager') {
                $check_manager_access = mysqli_query($conn, 
                    "SELECT id FROM user_assignments 
                     WHERE user_id = {$current_user_id} AND project_id = {$project_id}");
                
                if (mysqli_num_rows($check_manager_access) === 0) {
                    $message = "Error: You do not have access to assign users to project '{$project_name}'. You can only assign users to projects you are assigned to.";
                    $message_type = "danger";
                    $valid_assignment = false;
                }
            }
            
            if ($valid_assignment) {
                $success_count = 0;
                $error_count = 0;
                $already_assigned = 0;
                $not_in_project = 0;
                $role_violation = 0;
                $user_messages = [];
                
                foreach ($user_ids as $user_id) {
                    $user_id = (int)$user_id;
                    
                    // Get user name and role for validation
                    $user_result = mysqli_query($conn, "SELECT username, system_role FROM users WHERE id = {$user_id}");
                    $user_name = '';
                    $user_role_to_assign = '';
                    if ($user_row = mysqli_fetch_assoc($user_result)) {
                        $user_name = $user_row['username'];
                        $user_role_to_assign = $user_row['system_role'];
                    }
                    
                    // ROLE-BASED PERMISSION CHECKS
                    $can_assign = true;
                    
                    // Check if PM Manager is trying to assign to Super Admin
                    if ($user_role === 'pm_manager' && $user_role_to_assign === 'super_admin') {
                        $role_violation++;
                        $user_messages[] = "❌ Cannot assign to Super Admin '{$user_name}' (PM Managers cannot assign to Super Admin)";
                        $can_assign = false;
                    }
                    // Check if Super Admin is trying to assign phases/activities/sub-activities to PM Manager
                    elseif ($user_role === 'super_admin' && $user_role_to_assign === 'pm_manager' && $level_type !== 'project') {
                        $role_violation++;
                        $user_messages[] = "❌ Cannot assign {$level_type} to PM Manager '{$user_name}' (Super Admin can only assign projects to PM Managers)";
                        $can_assign = false;
                    }
                    
                    // For non-project assignments, verify user is assigned to the project
                    if ($can_assign && $level_type !== 'project' && $project_id) {
                        // Skip this check for PM Managers assigning to PM Employees
                        if (!($user_role === 'pm_manager' && $user_role_to_assign === 'pm_employee')) {
                            $check = mysqli_query($conn, 
                                "SELECT id FROM user_assignments 
                                 WHERE user_id = {$user_id} AND project_id = {$project_id}");
                            if (mysqli_num_rows($check) === 0) {
                                $not_in_project++;
                                $user_messages[] = "❌ User '{$user_name}' is not assigned to project '{$project_name}'";
                                $can_assign = false;
                            }
                        }
                    }
                    
                    // Check if user is already assigned at this level
                    if ($can_assign) {
                        // Build WHERE clause based on level type
                        $where_clause = "";
                        switch($level_type) {
                            case 'project':
                                $where_clause = "user_id = {$user_id} AND project_id = {$level_id} AND phase_id IS NULL AND activity_id IS NULL AND subactivity_id IS NULL";
                                break;
                            case 'phase':
                                $where_clause = "user_id = {$user_id} AND phase_id = {$level_id}";
                                break;
                            case 'activity':
                                $where_clause = "user_id = {$user_id} AND activity_id = {$level_id}";
                                break;
                            case 'sub_activity':
                                $where_clause = "user_id = {$user_id} AND subactivity_id = {$level_id}";
                                break;
                        }
                        
                        $check_existing = mysqli_query($conn, "SELECT id FROM user_assignments WHERE {$where_clause}");
                        
                        if (mysqli_num_rows($check_existing) > 0) {
                            $already_assigned++;
                            $user_messages[] = "⚠️ User '{$user_name}' is already assigned";
                            continue;
                        }
                        
                        // Use the enhanced assignment function
                        $result = assignUserToItem($conn, $current_user_id, $user_id, $level_type, $level_id, $project_id, $target_name);
                        
                        if ($result['success']) {
                            $success_count++;
                            $user_messages[] = "✅ User '{$user_name}' assigned successfully";
                        } else {
                            $error_count++;
                            $user_messages[] = "❌ Error assigning user '{$user_name}': " . $result['message'];
                        }
                    }
                }
                
                // Build final message
                $level_display = ucfirst(str_replace('_', ' ', $level_type));
                $message = "<strong>Bulk Assignment Results for {$level_display} '{$target_name}':</strong><br>";
                
                if ($success_count > 0) {
                    $message .= "✅ Successfully assigned <strong>{$success_count}</strong> user(s).<br>";
                    $message_type = "success";
                }
                
                if ($already_assigned > 0) {
                    $message .= "⚠️ <strong>{$already_assigned}</strong> user(s) were already assigned.<br>";
                    $message_type = $success_count > 0 ? "warning" : "info";
                }
                
                if ($not_in_project > 0) {
                    $message .= "❌ <strong>{$not_in_project}</strong> user(s) are not assigned to the project '{$project_name}'.<br>";
                    $message_type = "warning";
                }
                
                if ($role_violation > 0) {
                    $message .= "🚫 <strong>{$role_violation}</strong> user(s) skipped due to role hierarchy violations.<br>";
                    $message_type = "warning";
                }
                
                if ($error_count > 0) {
                    $message .= "❌ Failed to assign <strong>{$error_count}</strong> user(s).<br>";
                    $message_type = "danger";
                }
                
                // Add detailed user messages
                if (!empty($user_messages)) {
                    $message .= "<div class='mt-2 small'><strong>Detailed Results:</strong><br>";
                    foreach ($user_messages as $msg) {
                        $message .= $msg . "<br>";
                    }
                    $message .= "</div>";
                }
                
                if ($success_count == 0 && $already_assigned == 0 && $not_in_project == 0 && $role_violation == 0 && $error_count == 0) {
                    $message = "<strong>No users were assigned.</strong><br>";
                    $message .= "Please check your selection and try again.<br>";
                    $message_type = "warning";
                }
            }
        }
    } else {
        // Only show error if it's actually missing required fields
        $missing_fields = [];
        if (empty($user_ids)) $missing_fields[] = "Users selection";
        if (!$level_type) $missing_fields[] = "Assignment Level";
        if (!$level_id) $missing_fields[] = "Assignment Target";
        
        if (!empty($missing_fields)) {
            $message = "Error: Please fill all required fields! Missing: " . implode(', ', $missing_fields);
            $message_type = "danger";
        }
    }
}

// Handle User Unassignment WITH CASCADE RULES and Enhanced Notifications
if ($action === 'unassign_user') {
    $user_id = (int)post_val('unassign_user_id');
    $level_type = post_val('unassign_level_type');
    $level_id = (int)post_val('unassign_level_id');
    $allowed = ['project','phase','activity','sub_activity'];
    
    if ($user_id && $level_id && $level_type && in_array($level_type, $allowed)) {
        // Get user name and role for validation
        $user_result = mysqli_query($conn, "SELECT username, system_role FROM users WHERE id = {$user_id}");
        $user_name = '';
        $user_role_to_unassign = '';
        if ($user_row = mysqli_fetch_assoc($user_result)) {
            $user_name = $user_row['username'];
            $user_role_to_unassign = $user_row['system_role'];
        }
        
        // Check if project is terminated
        $project_id = null;
        $project_name = '';
        $phase_id = null;
        $activity_id = null;
        $subactivity_id = null;
        $item_name = '';
        
        // Get details based on level type
        if ($level_type === 'project') {
            $project_id = $level_id;
            $result = mysqli_query($conn, "SELECT name FROM projects WHERE id = {$project_id}");
            if ($row = mysqli_fetch_assoc($result)) {
                $project_name = $row['name'];
                $item_name = $row['name'];
            }
        } elseif ($level_type === 'phase') {
            $result = mysqli_query($conn, "SELECT p.id, p.name as project_name, ph.id as phase_id, ph.name as phase_name FROM phases ph JOIN projects p ON ph.project_id = p.id WHERE ph.id = {$level_id}");
            if ($row = mysqli_fetch_assoc($result)) {
                $project_id = $row['id'];
                $project_name = $row['project_name'];
                $phase_id = $row['phase_id'];
                $item_name = $row['phase_name'];
            }
        } elseif ($level_type === 'activity') {
            $result = mysqli_query($conn, 
                "SELECT p.id, p.name as project_name, a.id as activity_id, a.phase_id, a.name as activity_name
                 FROM activities a 
                 JOIN phases ph ON a.phase_id = ph.id 
                 JOIN projects p ON ph.project_id = p.id 
                 WHERE a.id = {$level_id}");
            if ($row = mysqli_fetch_assoc($result)) {
                $project_id = $row['id'];
                $project_name = $row['project_name'];
                $activity_id = $row['activity_id'];
                $phase_id = $row['phase_id'];
                $item_name = $row['activity_name'];
            }
        } elseif ($level_type === 'sub_activity') {
            $result = mysqli_query($conn, 
                "SELECT p.id, p.name as project_name, s.id as subactivity_id, s.activity_id, a.phase_id, s.name as sub_name
                 FROM sub_activities s 
                 JOIN activities a ON s.activity_id = a.id 
                 JOIN phases ph ON a.phase_id = ph.id 
                 JOIN projects p ON ph.project_id = p.id 
                 WHERE s.id = {$level_id}");
            if ($row = mysqli_fetch_assoc($result)) {
                $project_id = $row['id'];
                $project_name = $row['project_name'];
                $subactivity_id = $row['subactivity_id'];
                $activity_id = $row['activity_id'];
                $phase_id = $row['phase_id'];
                $item_name = $row['sub_name'];
            }
        }
        
        if ($project_id) {
            $check_project = mysqli_query($conn, "SELECT status FROM projects WHERE id = {$project_id}");
            if ($check_project && mysqli_num_rows($check_project) > 0) {
                $project_data = mysqli_fetch_assoc($check_project);
                if ($project_data['status'] === 'terminated') {
                    $message = "Cannot unassign user: Project '{$project_name}' is terminated!";
                    $message_type = "danger";
                } else {
                    // ROLE-BASED PERMISSION CHECKS
                    $can_unassign = true;
                    
                    // Check if PM Manager is trying to unassign Super Admin
                    if ($user_role === 'pm_manager' && $user_role_to_unassign === 'super_admin') {
                        $message = "Error: PM Managers cannot unassign Super Admin!";
                        $message_type = "danger";
                        $can_unassign = false;
                    }
                    
                    // For PM Managers: Check if they have access to this project
                    if ($can_unassign && $user_role === 'pm_manager') {
                        $check_manager_access = mysqli_query($conn, 
                            "SELECT id FROM user_assignments 
                             WHERE user_id = {$current_user_id} AND project_id = {$project_id}");
                        
                        if (mysqli_num_rows($check_manager_access) === 0) {
                            $message = "Error: You do not have access to unassign users from project '{$project_name}'. You can only manage users in projects you are assigned to.";
                            $message_type = "danger";
                            $can_unassign = false;
                        }
                    }
                    
                    if ($can_unassign) {
                        // Use the enhanced revocation function
                        $result = revokeUserFromItem($conn, $current_user_id, $user_id, $level_type, $level_id, $project_id, $item_name);
                        
                        if ($result['success']) {
                            $message = $result['message'];
                            $message_type = "success";
                        } else {
                            $message = $result['message'];
                            $message_type = "danger";
                        }
                    }
                }
            }
        }
    } else {
        // Only show error if it's actually missing required fields
        $missing_fields = [];
        if (!$user_id) $missing_fields[] = "User";
        if (!$level_type) $missing_fields[] = "Assignment Level";
        if (!$level_id) $missing_fields[] = "Assignment Target";
        
        if (!empty($missing_fields)) {
            $message = "Error: Please fill all required fields! Missing: " . implode(', ', $missing_fields);
            $message_type = "danger";
        }
    }
}

// Fetch data for display
// Get all users (except current user, and if super_admin, don't show themselves)
$users = [];
$users_count_result = mysqli_query($conn, "SELECT COUNT(*) as count FROM users WHERE id != {$current_user_id}");
$users_count_row = mysqli_fetch_assoc($users_count_result);
$total_users = $users_count_row['count'];

$res = mysqli_query($conn, "SELECT id, username, email, system_role as role FROM users WHERE id != {$current_user_id} ORDER BY username");
while ($r = mysqli_fetch_assoc($res)) $users[] = $r;

// Get all projects based on user role
$projects = [];
if ($user_role === 'super_admin' || $user_role === 'admin') {
    // Super Admin and Admin can see all non-terminated projects
    $res = mysqli_query($conn, "
        SELECT p.id, p.name, p.status, 
               COUNT(DISTINCT ua.user_id) as assigned_users
        FROM projects p
        LEFT JOIN user_assignments ua ON p.id = ua.project_id
        WHERE p.status != 'terminated'
        GROUP BY p.id, p.name, p.status
        ORDER BY p.name
    ");
} else {
    // PM Manager can only see projects they are assigned to
    $res = mysqli_query($conn, "
        SELECT p.id, p.name, p.status, 
               COUNT(DISTINCT ua.user_id) as assigned_users
        FROM projects p
        INNER JOIN user_assignments ua ON p.id = ua.project_id AND ua.user_id = {$current_user_id}
        LEFT JOIN user_assignments ua2 ON p.id = ua2.project_id
        WHERE p.status != 'terminated'
        GROUP BY p.id, p.name, p.status
        ORDER BY p.name
    ");
}
while ($r = mysqli_fetch_assoc($res)) $projects[] = $r;

// Get all phases with project info and assignment counts (filtered by user access)
$phases = [];
if ($user_role === 'super_admin' || $user_role === 'admin') {
    $res = mysqli_query($conn, "
        SELECT ph.id, ph.project_id, ph.name, ph.status, 
               pr.name as project_name, pr.status as project_status,
               COUNT(DISTINCT ua.user_id) as assigned_users
        FROM phases ph
        LEFT JOIN projects pr ON ph.project_id = pr.id
        LEFT JOIN user_assignments ua ON ph.id = ua.phase_id
        WHERE pr.status != 'terminated'
        GROUP BY ph.id, ph.project_id, ph.name, ph.status, pr.name, pr.status
        ORDER BY ph.project_id, ph.name
    ");
} else {
    $res = mysqli_query($conn, "
        SELECT ph.id, ph.project_id, ph.name, ph.status, 
               pr.name as project_name, pr.status as project_status,
               COUNT(DISTINCT ua.user_id) as assigned_users
        FROM phases ph
        INNER JOIN projects pr ON ph.project_id = pr.id
        INNER JOIN user_assignments ua_manager ON pr.id = ua_manager.project_id AND ua_manager.user_id = {$current_user_id}
        LEFT JOIN user_assignments ua ON ph.id = ua.phase_id
        WHERE pr.status != 'terminated'
        GROUP BY ph.id, ph.project_id, ph.name, ph.status, pr.name, pr.status
        ORDER BY ph.project_id, ph.name
    ");
}
while ($r = mysqli_fetch_assoc($res)) $phases[] = $r;

// Get all activities with project and phase info and assignment counts (filtered by user access)
$activities = [];
if ($user_role === 'super_admin' || $user_role === 'admin') {
    $res = mysqli_query($conn, "
        SELECT a.id, a.phase_id, a.name, a.status, 
               ph.name as phase_name, ph.project_id,
               pr.name as project_name, pr.status as project_status,
               COUNT(DISTINCT ua.user_id) as assigned_users
        FROM activities a
        LEFT JOIN phases ph ON a.phase_id = ph.id
        LEFT JOIN projects pr ON ph.project_id = pr.id
        LEFT JOIN user_assignments ua ON a.id = ua.activity_id
        WHERE pr.status != 'terminated'
        GROUP BY a.id, a.phase_id, a.name, a.status, ph.name, ph.project_id, pr.name, pr.status
        ORDER BY a.phase_id, a.name
    ");
} else {
    $res = mysqli_query($conn, "
        SELECT a.id, a.phase_id, a.name, a.status, 
               ph.name as phase_name, ph.project_id,
               pr.name as project_name, pr.status as project_status,
               COUNT(DISTINCT ua.user_id) as assigned_users
        FROM activities a
        INNER JOIN phases ph ON a.phase_id = ph.id
        INNER JOIN projects pr ON ph.project_id = pr.id
        INNER JOIN user_assignments ua_manager ON pr.id = ua_manager.project_id AND ua_manager.user_id = {$current_user_id}
        LEFT JOIN user_assignments ua ON a.id = ua.activity_id
        WHERE pr.status != 'terminated'
        GROUP BY a.id, a.phase_id, a.name, a.status, ph.name, ph.project_id, pr.name, pr.status
        ORDER BY a.phase_id, a.name
    ");
}
while ($r = mysqli_fetch_assoc($res)) $activities[] = $r;

// Get all sub-activities with project, phase, and activity info and assignment counts (filtered by user access)
$subs = [];
if ($user_role === 'super_admin' || $user_role === 'admin') {
    $res = mysqli_query($conn, "
        SELECT s.id, s.activity_id, s.name, s.status, s.assigned_to,
               a.name as activity_name, a.phase_id,
               ph.name as phase_name, ph.project_id,
               pr.name as project_name, pr.status as project_status,
               u.username as assigned_to_name,
               COUNT(DISTINCT ua.user_id) as assigned_users
        FROM sub_activities s
        LEFT JOIN activities a ON s.activity_id = a.id
        LEFT JOIN phases ph ON a.phase_id = ph.id
        LEFT JOIN projects pr ON ph.project_id = pr.id
        LEFT JOIN users u ON s.assigned_to = u.id
        LEFT JOIN user_assignments ua ON s.id = ua.subactivity_id
        WHERE pr.status != 'terminated'
        GROUP BY s.id, s.activity_id, s.name, s.status, s.assigned_to, a.name, a.phase_id, 
                 ph.name, ph.project_id, pr.name, pr.status, u.username
        ORDER BY s.activity_id, s.name
    ");
} else {
    $res = mysqli_query($conn, "
        SELECT s.id, s.activity_id, s.name, s.status, s.assigned_to,
               a.name as activity_name, a.phase_id,
               ph.name as phase_name, ph.project_id,
               pr.name as project_name, pr.status as project_status,
               u.username as assigned_to_name,
               COUNT(DISTINCT ua.user_id) as assigned_users
        FROM sub_activities s
        INNER JOIN activities a ON s.activity_id = a.id
        INNER JOIN phases ph ON a.phase_id = ph.id
        INNER JOIN projects pr ON ph.project_id = pr.id
        INNER JOIN user_assignments ua_manager ON pr.id = ua_manager.project_id AND ua_manager.user_id = {$current_user_id}
        LEFT JOIN users u ON s.assigned_to = u.id
        LEFT JOIN user_assignments ua ON s.id = ua.subactivity_id
        WHERE pr.status != 'terminated'
        GROUP BY s.id, s.activity_id, s.name, s.status, s.assigned_to, a.name, a.phase_id, 
                 ph.name, ph.project_id, pr.name, pr.status, u.username
        ORDER BY s.activity_id, s.name
    ");
}
while ($r = mysqli_fetch_assoc($res)) $subs[] = $r;

// Get all assignments grouped by project for Current Assignments tab
$assignments_by_project = [];
$projects_with_assignments = [];

// Get projects that have assignments (filtered by user access)
if ($user_role === 'super_admin' || $user_role === 'admin') {
    $project_assignments_result = mysqli_query($conn, "
        SELECT DISTINCT p.id as project_id, p.name as project_name
        FROM projects p
        INNER JOIN user_assignments ua ON p.id = ua.project_id
        WHERE ua.user_id != {$current_user_id}
        ORDER BY p.name
    ");
} else {
    $project_assignments_result = mysqli_query($conn, "
        SELECT DISTINCT p.id as project_id, p.name as project_name
        FROM projects p
        INNER JOIN user_assignments ua ON p.id = ua.project_id
        INNER JOIN user_assignments ua_manager ON p.id = ua_manager.project_id AND ua_manager.user_id = {$current_user_id}
        WHERE ua.user_id != {$current_user_id}
        ORDER BY p.name
    ");
}

while ($project_row = mysqli_fetch_assoc($project_assignments_result)) {
    $project_id = $project_row['project_id'];
    $project_name = $project_row['project_name'];
    
    $projects_with_assignments[] = [
        'id' => $project_id,
        'name' => $project_name
    ];
    
    // Get all assignments for this project
    $assignments_result = mysqli_query($conn, "
        SELECT 
            ua.id as assignment_id,
            ua.user_id,
            ua.project_id,
            ua.phase_id,
            ua.activity_id,
            ua.subactivity_id,
            u.username,
            u.email,
            u.system_role as user_role,
            p.name as project_name,
            ph.name as phase_name,
            a.name as activity_name,
            sa.name as subactivity_name,
            CASE 
                WHEN ua.subactivity_id IS NOT NULL THEN 'sub_activity'
                WHEN ua.activity_id IS NOT NULL THEN 'activity'
                WHEN ua.phase_id IS NOT NULL THEN 'phase'
                ELSE 'project'
            END as assignment_level
        FROM user_assignments ua
        JOIN users u ON ua.user_id = u.id
        LEFT JOIN projects p ON ua.project_id = p.id
        LEFT JOIN phases ph ON ua.phase_id = ph.id
        LEFT JOIN activities a ON ua.activity_id = a.id
        LEFT JOIN sub_activities sa ON ua.subactivity_id = sa.id
        WHERE ua.user_id != {$current_user_id} AND ua.project_id = {$project_id}
        ORDER BY u.username, ph.name, a.name, sa.name
    ");
    
    $project_assignments = [];
    while ($assignment_row = mysqli_fetch_assoc($assignments_result)) {
        $project_assignments[] = $assignment_row;
    }
    
    $assignments_by_project[$project_id] = [
        'project_name' => $project_name,
        'assignments' => $project_assignments
    ];
}

// Get total assignments count for pagination
$assignments_count_result = mysqli_query($conn, "
    SELECT COUNT(*) as count 
    FROM user_assignments ua
    JOIN users u ON ua.user_id = u.id
    WHERE ua.user_id != {$current_user_id}
");
$assignments_count_row = mysqli_fetch_assoc($assignments_count_result);
$total_assignments = $assignments_count_row['count'];

// Helper function to get assignment level badge class
function getAssignmentLevelBadgeClass($level) {
    switch ($level) {
        case 'project':
            return 'bg-primary';
        case 'phase':
            return 'bg-success';
        case 'activity':
            return 'bg-warning text-dark';
        case 'sub_activity':
            return 'bg-info';
        default:
            return 'bg-secondary';
    }
}

// Helper function to get assignment level display text
function getAssignmentLevelDisplayText($level) {
    switch ($level) {
        case 'sub_activity':
            return 'Sub-Activity';
        default:
            return ucfirst($level);
    }
}

// Helper function to get assignment level icon
function getAssignmentLevelIcon($level) {
    switch ($level) {
        case 'project':
            return 'fa-project-diagram';
        case 'phase':
            return 'fa-tasks';
        case 'activity':
            return 'fa-list-check';
        case 'sub_activity':
            return 'fa-list-ol';
        default:
            return 'fa-question';
    }
}

// Helper function to get pagination URL
function getPaginationUrl($page) {
    $params = $_GET;
    $params['page'] = $page;
    return '?' . http_build_query($params);
}

// Set active dashboard for sidebar
$active_dashboard = 'user_assignments';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>User Assignments - Dashen Bank</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
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
            --info-color: #17a2b8;
            --dark-color: #5a5c69;
            --light-color: #ffffff;
            --sidebar-width: 280px;
            --sidebar-collapsed-width: 80px;
        }
        
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e8f0 100%);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            min-height: 100vh;
            overflow-x: hidden;
        }
        
        /* Sidebar Styles */
        .sidebar-container {
            position: fixed;
            left: 0;
            top: 0;
            height: 100vh;
            z-index: 1000;
            transition: all 0.3s ease;
        }

        .sidebar {
            width: var(--sidebar-width);
            height: 100%;
            background: linear-gradient(180deg, var(--primary-dark) 0%, var(--primary-color) 100%);
            color: white;
            overflow-y: auto;
            transition: all 0.3s ease;
            box-shadow: 4px 0 20px rgba(0, 0, 0, 0.15);
        }

        .sidebar-collapsed .sidebar {
            width: var(--sidebar-collapsed-width);
            overflow: hidden;
        }

        .sidebar-logo {
            width: 160px;
            display: block;
            margin: 30px auto 20px auto;
            transition: all 0.3s ease;
        }

        .sidebar-collapsed .sidebar-logo {
            opacity: 0;
            width: 0;
            height: 0;
            margin: 0;
        }

        .sidebar-header {
            padding: 20px 20px;
            background: rgba(255, 255, 255, 0.08);
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            transition: all 0.3s ease;
        }

        .sidebar-collapsed .sidebar-header {
            padding: 15px 10px;
            justify-content: center;
        }

        .user-info {
            display: flex;
            flex-direction: column;
            transition: all 0.3s ease;
        }

        .sidebar-collapsed .user-info {
            display: none;
        }

        .username {
            font-weight: 600;
            font-size: 1.1rem;
            margin-bottom: 5px;
        }

        .user-role {
            font-size: 0.8rem;
            opacity: 0.9;
            background: rgba(255, 255, 255, 0.15);
            padding: 3px 10px;
            border-radius: 12px;
            align-self: flex-start;
        }

        .sidebar-menu {
            padding: 20px 0;
        }

        .menu-item {
            display: flex;
            align-items: center;
            padding: 14px 20px;
            color: rgba(255, 255, 255, 0.9);
            text-decoration: none;
            transition: all 0.2s ease;
            border-left: 3px solid transparent;
            white-space: nowrap;
            margin: 5px 10px;
            border-radius: 8px;
        }

        .menu-item:hover {
            background: rgba(255, 255, 255, 0.1);
            border-left: 3px solid var(--primary-light);
            color: white;
            transform: translateX(5px);
        }

        .menu-item.active {
            background: rgba(255, 255, 255, 0.15);
            border-left: 3px solid var(--primary-light);
            color: white;
            font-weight: 500;
        }

        .menu-icon {
            margin-right: 15px;
            font-size: 1.2rem;
            width: 24px;
            text-align: center;
            flex-shrink: 0;
        }

        .menu-text {
            transition: all 0.3s ease;
            opacity: 1;
            font-weight: 500;
        }

        .sidebar-collapsed .menu-text {
            opacity: 0;
            width: 0;
            display: none;
        }

        .sidebar-toggler {
            position: fixed;
            top: 25px;
            left: 25px;
            background: var(--primary-light);
            color: white;
            border: none;
            width: 45px;
            height: 45px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            z-index: 1100;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
            transition: all 0.3s ease;
        }

        .sidebar-collapsed .sidebar-toggler {
            left: calc(var(--sidebar-collapsed-width) - 10px);
        }

        /* Main Content Area */
        .main-content {
            transition: all 0.3s ease;
            margin-left: var(--sidebar-width);
            padding: 25px;
            min-height: 100vh;
            background: transparent;
        }

        .sidebar-collapsed ~ .main-content {
            margin-left: var(--sidebar-collapsed-width);
        }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            
            .sidebar-collapsed .sidebar {
                transform: translateX(0);
                width: var(--sidebar-width);
            }
            
            .sidebar-collapsed ~ .main-content {
                margin-left: 0;
            }
            
            .sidebar-toggler {
                left: 20px;
            }
            
            .sidebar-collapsed .sidebar-toggler {
                left: calc(var(--sidebar-width) - 30px);
            }

            .main-content {
                margin-left: 0 !important;
                padding: 20px;
            }
        }

        /* Navigation Bar */
        .navbar-brand {
            font-weight: 700;
            letter-spacing: 0.5px;
            font-size: 1.4rem;
        }
        
        .brand-bg {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
            border-radius: 12px;
            margin: 0 15px 25px 15px;
            padding: 15px 25px;
        }
        
        /* Cards */
        .card {
            border: none;
            border-radius: 16px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            overflow: hidden;
            margin-bottom: 25px;
            background: white;
        }
        
        .card:hover {
            transform: translateY(-8px);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.12);
        }
        
        .card-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-light) 100%);
            color: white;
            border-radius: 16px 16px 0 0 !important;
            padding: 1.5rem 2rem;
            font-weight: 600;
            border-bottom: none;
        }
        
        /* Buttons */
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-light) 100%);
            border: none;
            border-radius: 10px;
            padding: 0.75rem 2rem;
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(39, 50, 116, 0.2);
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 100%);
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(39, 50, 116, 0.3);
        }
        
        /* Tabs */
        .nav-tabs {
            border-bottom: 2px solid #e0e0e0;
            margin-bottom: 2rem;
        }
        
        .nav-tabs .nav-link {
            border: none;
            color: #6c757d;
            font-weight: 600;
            padding: 1rem 2rem;
            margin-right: 10px;
            border-radius: 10px 10px 0 0;
            transition: all 0.3s;
        }
        
        .nav-tabs .nav-link:hover {
            color: var(--primary-color);
            background: rgba(39, 50, 116, 0.05);
        }
        
        .nav-tabs .nav-link.active {
            color: var(--primary-color);
            background: white;
            border-bottom: 3px solid var(--primary-color);
        }
        
        /* Accordion Styles for Current Assignments */
        .accordion-button {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            font-weight: 600;
            padding: 1.25rem 1.5rem;
            border: none;
        }
        
        .accordion-button:not(.collapsed) {
            background: linear-gradient(135deg, #5a67d8 0%, #6b46c1 100%);
            color: white;
            box-shadow: none;
        }
        
        .accordion-button::after {
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16' fill='white'%3e%3cpath fill-rule='evenodd' d='M1.646 4.646a.5.5 0 0 1 .708 0L8 10.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708z'/%3e%3c/svg%3e");
        }
        
        .accordion-body {
            padding: 0;
        }
        
        /* Assignment List Styles */
        .assignment-item {
            border-bottom: 1px solid #f0f0f0;
            padding: 1.25rem 1.5rem;
            transition: all 0.2s;
        }
        
        .assignment-item:hover {
            background-color: rgba(39, 50, 116, 0.03);
        }
        
        .assignment-item:last-child {
            border-bottom: none;
        }
        
        /* Project Hierarchy Styles */
        .hierarchy-container {
            max-height: 600px;
            overflow-y: auto;
        }
        
        .hierarchy-project {
            background: white;
            border-radius: 12px;
            margin-bottom: 15px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }
        
        .hierarchy-project-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-light) 100%);
            color: white;
            padding: 1.25rem 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .hierarchy-project-header:hover {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 100%);
        }
        
        .hierarchy-project-body {
            padding: 0;
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease-out;
        }
        
        .hierarchy-project-body.show {
            max-height: 1000px;
        }
        
        .hierarchy-phase {
            background: #f8f9fa;
            margin: 10px;
            border-radius: 10px;
            overflow: hidden;
        }
        
        .hierarchy-phase-header {
            background: linear-gradient(135deg, #1cc88a 0%, #17a673 100%);
            color: white;
            padding: 1rem 1.25rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
        }
        
        .hierarchy-phase-body {
            padding: 0;
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease-out;
        }
        
        .hierarchy-phase-body.show {
            max-height: 1000px;
        }
        
        .hierarchy-activity {
            background: #fefefe;
            margin: 8px;
            border-radius: 8px;
            overflow: hidden;
        }
        
        .hierarchy-activity-header {
            background: linear-gradient(135deg, #f6c23e 0%, #dda20a 100%);
            color: white;
            padding: 0.75rem 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
        }
        
        .hierarchy-activity-body {
            padding: 0;
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease-out;
        }
        
        .hierarchy-activity-body.show {
            max-height: 1000px;
        }
        
        .hierarchy-subactivity {
            background: #f8f9fa;
            margin: 5px;
            padding: 0.75rem 1rem;
            border-radius: 6px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-left: 4px solid var(--info-color);
        }
        
        .assignment-count {
            background: rgba(255, 255, 255, 0.2);
            padding: 2px 10px;
            border-radius: 20px;
            font-size: 0.85rem;
        }
        
        /* Bulk Assignment Styles */
        .user-check-item {
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 0.75rem 1rem;
            margin-bottom: 0.5rem;
            transition: all 0.2s;
            cursor: pointer;
        }
        
        .user-check-item:hover {
            background-color: #f8f9fa;
            border-color: var(--primary-color);
        }
        
        .user-check-item.selected {
            background-color: rgba(39, 50, 116, 0.05);
            border-color: var(--primary-color);
        }
        
        /* Pagination Styles */
        .pagination {
            margin-top: 2rem;
        }
        
        .pagination .page-item.active .page-link {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-light) 100%);
            border-color: var(--primary-color);
            color: white;
            box-shadow: 0 4px 10px rgba(39, 50, 116, 0.2);
        }
        
        /* Alert Styles */
        .alert {
            border-radius: 12px;
            border: none;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            padding: 1.25rem 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        .alert ul {
            margin-bottom: 0;
            padding-left: 1.5rem;
        }
        
        .alert li {
            margin-bottom: 0.25rem;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: var(--dark-color);
        }
        
        .empty-state i {
            font-size: 4.5rem;
            margin-bottom: 1.5rem;
            color: #e0e0e0;
            opacity: 0.6;
        }
        
        /* Fix for modal display */
        .modal.show {
            display: block !important;
        }
        
        /* Message notification style */
        .message-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            width: 350px;
        }
        
        /* Form validation */
        .was-validated .form-control:invalid,
        .form-control.is-invalid {
            border-color: #dc3545;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 12' width='12' height='12' fill='none' stroke='%23dc3545'%3e%3ccircle cx='6' cy='6' r='4.5'/%3e%3cpath stroke-linejoin='round' d='M5.8 3.6h.4L6 6.5z'/%3e%3ccircle cx='6' cy='8.2' r='.6' fill='%23dc3545' stroke='none'/%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right calc(0.375em + 0.1875rem) center;
            background-size: calc(0.75em + 0.375rem) calc(0.75em + 0.375rem);
        }
        
        .was-validated .form-control:invalid:focus,
        .form-control.is-invalid:focus {
            border-color: #dc3545;
            box-shadow: 0 0 0 0.25rem rgba(220, 53, 69, 0.25);
        }
        
        /* Role-specific indicators */
        .super-admin-badge {
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a52 100%);
            color: white;
        }
        
        .pm-manager-badge {
            background: linear-gradient(135deg, #48dbfb 0%, #0abde3 100%);
            color: white;
        }
        
        .role-restriction-note {
            background: linear-gradient(135deg, #f9ca24 0%, #f0932b 100%);
            color: white;
            padding: 10px 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: 500;
        }
        
        /* Rules Info Box */
        .rules-info-box {
            background: linear-gradient(135deg, #36b9cc 0%, #17a2b8 100%);
            color: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
        }
        
        .rules-info-box h5 {
            font-weight: 600;
            margin-bottom: 15px;
            border-bottom: 2px solid rgba(255, 255, 255, 0.3);
            padding-bottom: 10px;
        }
        
        .rules-info-box .rule-section {
            margin-bottom: 15px;
        }
        
        .rules-info-box .rule-section:last-child {
            margin-bottom: 0;
        }
        
        .rules-info-box .rule-title {
            font-weight: 600;
            font-size: 1.1rem;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
        }
        
        .rules-info-box .rule-title i {
            margin-right: 10px;
        }
        
        .rules-info-box ul {
            margin-bottom: 0;
            padding-left: 25px;
        }
        
        .rules-info-box li {
            margin-bottom: 5px;
            font-size: 0.95rem;
        }
        
        /* Quick Actions Button - Hidden initially */
        .quick-actions-btn {
            position: fixed;
            bottom: 30px;
            right: 30px;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-light) 100%);
            color: white;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            box-shadow: 0 5px 20px rgba(39, 50, 116, 0.3);
            z-index: 1000;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .quick-actions-btn:hover {
            transform: scale(1.1);
            box-shadow: 0 8px 25px rgba(39, 50, 116, 0.4);
        }
        
        /* Quick Actions Modal */
        .quick-actions-modal .modal-dialog {
            max-width: 400px;
        }
        
        .quick-actions-modal .modal-content {
            border-radius: 16px;
            overflow: hidden;
            border: none;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
        }
        
        .quick-actions-modal .modal-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-light) 100%);
            color: white;
            border-bottom: none;
            padding: 1.5rem;
        }
        
        .quick-actions-modal .modal-body {
            padding: 2rem;
        }
        
        .quick-actions-modal .action-item {
            display: flex;
            align-items: center;
            padding: 1rem;
            margin-bottom: 10px;
            border-radius: 10px;
            background: #f8f9fa;
            border: 1px solid #e0e0e0;
            transition: all 0.2s;
            cursor: pointer;
            text-decoration: none;
            color: inherit;
        }
        
        .quick-actions-modal .action-item:hover {
            background: var(--primary-color);
            color: white;
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(39, 50, 116, 0.2);
            border-color: var(--primary-color);
        }
        
        .quick-actions-modal .action-item i {
            font-size: 1.5rem;
            margin-right: 15px;
            width: 40px;
            text-align: center;
        }
        
        .quick-actions-modal .action-item .action-text {
            flex-grow: 1;
        }
        
        .quick-actions-modal .action-item .action-text h6 {
            margin-bottom: 5px;
            font-weight: 600;
        }
        
        .quick-actions-modal .action-item .action-text p {
            margin-bottom: 0;
            font-size: 0.85rem;
            opacity: 0.8;
        }
    </style>
</head>
<body>
    <!-- Sidebar Structure -->
    <div class="sidebar-container" id="sidebarContainer">
        <button class="sidebar-toggler" id="sidebarToggler">
            <i class="fas fa-bars"></i>
        </button>

        <div class="sidebar" id="sidebar">
            <img src="Images/DashenLogo12.png" alt="Dashen Bank Logo" class="sidebar-logo">

            <div class="sidebar-header">
                <div class="user-info">
                    <span class="username"><?php echo htmlspecialchars($_SESSION['username'] ?? 'Guest'); ?></span>
                    <span class="user-role <?= $user_role === 'super_admin' ? 'super-admin-badge' : ($user_role === 'pm_manager' ? 'pm-manager-badge' : '') ?>">
                        <?php 
                        $role_display = ucfirst($user_role);
                        if ($user_role === 'super_admin') {
                            echo '<i class="fas fa-crown me-1"></i>' . $role_display;
                        } elseif ($user_role === 'pm_manager') {
                            echo '<i class="fas fa-user-tie me-1"></i>' . $role_display;
                        } else {
                            echo $role_display;
                        }
                        ?>
                    </span>
                </div>
            </div>

            <div class="sidebar-menu">
                <a href="dashboard.php" class="menu-item">
                    <span class="menu-icon"><i class="fas fa-tachometer-alt"></i></span>
                    <span class="menu-text">Dashboard</span>
                </a>
                
                <?php if (in_array($user_role, $allowed_roles)): ?>
                    <a href="pm_admin_projects.php" class="menu-item">
                        <span class="menu-icon"><i class="fas fa-project-diagram"></i></span>
                        <span class="menu-text">Project Management</span>
                    </a>
                    
                    <a href="user_assignment.php" class="menu-item active">
                        <span class="menu-icon"><i class="fas fa-user-plus"></i></span>
                        <span class="menu-text">User Assignments</span>
                    </a>
                <?php endif; ?>
            </div>

            <div class="sidebar-menu">
                <a href="logout.php" class="menu-item">
                    <span class="menu-icon"><i class="fas fa-sign-out-alt"></i></span>
                    <span class="menu-text">Logout</span>
                </a>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <!-- Navigation -->
        <nav class="navbar navbar-expand-lg navbar-dark brand-bg">
            <div class="container-fluid">
                <a class="navbar-brand" href="#">
                    <img src="Images/DashenLogo1.png" alt="Dashen Bank Logo" class="me-2" height="35">
                    <span class="align-middle">User Assignment Management</span>
                </a>
                <div class="d-flex align-items-center">
                    <div class="user-avatar me-3" style="width: 45px; height: 45px; border-radius: 50%; background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-light) 100%); color: white; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 1.1rem;">
                        <?= strtoupper(substr($_SESSION['username'] ?? 'A', 0, 1)) ?>
                    </div>
                    <div class="text-white">
                        <div class="fw-medium"><?= htmlspecialchars($_SESSION['username'] ?? 'Admin User') ?></div>
                        <div class="small opacity-75">
                            <?php 
                            if ($user_role === 'super_admin') {
                                echo '<i class="fas fa-crown me-1"></i>Super Admin (Full Access)';
                            } elseif ($user_role === 'pm_manager') {
                                echo '<i class="fas fa-user-tie me-1"></i>PM Manager (Assigned Projects Only)';
                            } else {
                                echo htmlspecialchars($user_role);
                            }
                            ?>
                        </div>
                    </div>
                </div>
            </div>
        </nav>

        <div class="container-fluid px-4">
            <!-- Rules Info Box -->
            <div class="rules-info-box">
                <h5><i class="fas fa-info-circle me-2"></i>Assignment Rules & Permissions</h5>
                
                <div class="rule-section">
                    <div class="rule-title"><i class="fas fa-user-shield"></i> PM Manager Assignment Rules</div>
                    <ul>
                        <li><strong>Super Admin:</strong> Can only assign <strong>PROJECTS</strong> to PM Managers</li>
                        <li><strong>PM Managers:</strong> Can assign phases/activities/sub-activities to users within their assigned projects</li>
                        <li><strong>PM Managers:</strong> Can assign PM Employees directly to phases/activities without project assignment</li>
                        <li><strong>PM Managers:</strong> <span class="fw-bold">Cannot assign anything to Super Admin</span></li>
                        <li><strong>Cascade Unassignment:</strong> When Super Admin unassigns a project from PM Manager, all PM Employees under that project are also unassigned and notified</li>
                    </ul>
                </div>
                
                <div class="rule-section">
                    <div class="rule-title"><i class="fas fa-sitemap"></i> Cascade Unassignment Rules</div>
                    <ul>
                        <li><strong>Project:</strong> Unassigns user from all related phases, activities, and sub-activities</li>
                        <li><strong>Phase:</strong> Only removes phase assignment - activities and sub-activities remain unchanged</li>
                        <li><strong>Activity:</strong> Unassigns user from the activity and all related sub-activities</li>
                        <li><strong>Sub-Activity:</strong> Only removes sub-activity assignment - higher levels remain unchanged</li>
                        <li><strong>Super Admin → PM Manager:</strong> When Super Admin unassigns a project from PM Manager, all PM Employees under that project are also unassigned</li>
                    </ul>
                </div>
                
                <?php if ($user_role === 'pm_manager'): ?>
                <div class="rule-section">
                    <div class="rule-title"><i class="fas fa-user-tie"></i> PM Manager Access</div>
                    <ul>
                        <li>You can only assign users to projects that have been assigned to you by Super Admin</li>
                        <li>You cannot assign anything to Super Admin users</li>
                        <li>You can only manage users within your assigned projects</li>
                        <li>You can assign PM Employees directly to phases/activities without requiring project assignment first</li>
                    </ul>
                </div>
                <?php endif; ?>
            </div>

            <!-- Role-based Access Note -->
            <?php if ($user_role === 'super_admin'): ?>
                <div class="role-restriction-note mb-4">
                    <i class="fas fa-crown me-2"></i>
                    <strong>Super Admin Access:</strong> You have full access to all projects. You cannot assign yourself as you already have access to everything. You can only assign <strong>PROJECTS</strong> to PM Managers (not phases/activities/sub-activities). When you unassign a project from a PM Manager, all PM Employees under that project will also be unassigned and notified.
                </div>
            <?php elseif ($user_role === 'pm_manager'): ?>
                <div class="role-restriction-note mb-4">
                    <i class="fas fa-user-tie me-2"></i>
                    <strong>PM Manager Access:</strong> You can only assign users to projects that have been assigned to you by Super Admin. You <strong>cannot assign anything to Super Admin users</strong>. You can assign PM Employees directly to phases/activities without requiring project assignment first.
                </div>
            <?php endif; ?>

            <!-- Status Message -->
            <?php if ($message): ?>
            <div class="alert alert-<?= $message_type ?> alert-dismissible fade show" role="alert">
                <div class="d-flex align-items-center">
                    <i class="fas fa-<?= $message_type === 'success' ? 'check-circle' : ($message_type === 'warning' ? 'exclamation-triangle' : 'exclamation-circle') ?> me-2 fs-4"></i>
                    <div><?= $message ?></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>

            <!-- Tabs Navigation -->
            <ul class="nav nav-tabs" id="mainTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="assignments-tab" data-bs-toggle="tab" data-bs-target="#assignments" type="button" role="tab">
                        <i class="fas fa-user-check me-2"></i>Current Assignments
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="hierarchy-tab" data-bs-toggle="tab" data-bs-target="#hierarchy" type="button" role="tab">
                        <i class="fas fa-sitemap me-2"></i>Project Hierarchy
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="bulk-tab" data-bs-toggle="tab" data-bs-target="#bulk" type="button" role="tab">
                        <i class="fas fa-users me-2"></i>Bulk Assignment
                    </button>
                </li>
            </ul>

            <!-- Tab Content -->
            <div class="tab-content mt-4">
                <!-- Current Assignments Tab -->
                <div class="tab-pane fade show active" id="assignments" role="tabpanel">
                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <div>
                                        <h5 class="mb-0"><i class="fas fa-user-check me-2"></i>Current User Assignments</h5>
                                        <p class="mb-0 text-white opacity-75">
                                            <?php if ($user_role === 'pm_manager'): ?>
                                                Viewing assignments for projects you manage
                                            <?php else: ?>
                                                View and manage all user assignments organized by project
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                    <button class="btn btn-light" data-bs-toggle="modal" data-bs-target="#assignUserModal">
                                        <i class="fas fa-plus me-1"></i> New Assignment
                                    </button>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($assignments_by_project)): ?>
                                        <div class="empty-state">
                                            <i class="fas fa-user-friends"></i>
                                            <h4>No Assignments Found</h4>
                                            <p>
                                                <?php if ($user_role === 'pm_manager'): ?>
                                                    No assignments found for projects you manage. Ask Super Admin to assign you to projects first.
                                                <?php else: ?>
                                                    Start by assigning users to projects, phases, or activities.
                                                <?php endif; ?>
                                            </p>
                                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#assignUserModal">
                                                <i class="fas fa-plus me-1"></i> Create First Assignment
                                            </button>
                                        </div>
                                    <?php else: ?>
                                        <div class="accordion" id="assignmentsAccordion">
                                            <?php foreach ($assignments_by_project as $project_id => $project_data): ?>
                                            <div class="accordion-item border-0 mb-3">
                                                <h2 class="accordion-header">
                                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#projectCollapse<?= $project_id ?>" aria-expanded="false">
                                                        <div class="d-flex justify-content-between align-items-center w-100">
                                                            <div>
                                                                <i class="fas fa-project-diagram me-2"></i>
                                                                <strong><?= esc($project_data['project_name']) ?></strong>
                                                                <span class="badge bg-light text-dark ms-2"><?= count($project_data['assignments']) ?> assignments</span>
                                                            </div>
                                                            <div class="text-white opacity-75">
                                                                <small>Click to expand</small>
                                                            </div>
                                                        </div>
                                                    </button>
                                                </h2>
                                                <div id="projectCollapse<?= $project_id ?>" class="accordion-collapse collapse" data-bs-parent="#assignmentsAccordion">
                                                    <div class="accordion-body p-0">
                                                        <div class="table-responsive">
                                                            <table class="table table-hover mb-0">
                                                                <thead>
                                                                    <tr>
                                                                        <th>User</th>
                                                                        <th>Role</th>
                                                                        <th>Assigned Level</th>
                                                                        <th>Target</th>
                                                                        <th>Actions</th>
                                                                    </tr>
                                                                </thead>
                                                                <tbody>
                                                                    <?php foreach ($project_data['assignments'] as $assignment): ?>
                                                                    <tr class="assignment-item">
                                                                        <td>
                                                                            <div class="d-flex align-items-center">
                                                                                <div class="user-avatar me-3" style="width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-light) 100%); color: white; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 1rem;">
                                                                                    <?= strtoupper(substr($assignment['username'], 0, 1)) ?>
                                                                                </div>
                                                                                <div>
                                                                                    <div class="fw-medium"><?= esc($assignment['username']) ?></div>
                                                                                    <div class="text-muted small"><?= esc($assignment['email']) ?></div>
                                                                                </div>
                                                                            </div>
                                                                        </td>
                                                                        <td>
                                                                            <span class="badge <?= $assignment['user_role'] === 'super_admin' ? 'super-admin-badge' : ($assignment['user_role'] === 'pm_manager' ? 'pm-manager-badge' : 'bg-secondary') ?>">
                                                                                <?= esc($assignment['user_role']) ?>
                                                                                <?php if ($assignment['user_role'] === 'super_admin'): ?>
                                                                                    <i class="fas fa-crown ms-1"></i>
                                                                                <?php elseif ($assignment['user_role'] === 'pm_manager'): ?>
                                                                                    <i class="fas fa-user-tie ms-1"></i>
                                                                                <?php endif; ?>
                                                                            </span>
                                                                        </td>
                                                                        <td>
                                                                            <span class="badge <?= getAssignmentLevelBadgeClass($assignment['assignment_level']) ?>">
                                                                                <i class="fas <?= getAssignmentLevelIcon($assignment['assignment_level']) ?> me-1"></i>
                                                                                <?= getAssignmentLevelDisplayText($assignment['assignment_level']) ?>
                                                                            </span>
                                                                        </td>
                                                                        <td>
                                                                            <div class="fw-medium">
                                                                                <?php 
                                                                                $display_text = '';
                                                                                switch($assignment['assignment_level']) {
                                                                                    case 'project':
                                                                                        $display_text = esc($assignment['project_name']);
                                                                                        break;
                                                                                    case 'phase':
                                                                                        $display_text = esc($assignment['project_name']) . ' → ' . esc($assignment['phase_name']);
                                                                                        break;
                                                                                    case 'activity':
                                                                                        $display_text = esc($assignment['project_name']) . ' → ' . esc($assignment['phase_name']) . ' → ' . esc($assignment['activity_name']);
                                                                                        break;
                                                                                    case 'sub_activity':
                                                                                        $display_text = esc($assignment['project_name']) . ' → ' . esc($assignment['phase_name']) . ' → ' . esc($assignment['activity_name']) . ' → ' . esc($assignment['subactivity_name']);
                                                                                        break;
                                                                                }
                                                                                echo $display_text;
                                                                                ?>
                                                                            </div>
                                                                        </td>
                                                                        <td>
                                                                            <?php 
                                                                            // Check if user has permission to unassign
                                                                            $can_unassign = false;
                                                                            if ($user_role === 'super_admin' || $user_role === 'admin') {
                                                                                $can_unassign = true;
                                                                            } elseif ($user_role === 'pm_manager' && in_array($project_id, array_column($projects, 'id'))) {
                                                                                // PM Manager can only unassign if they manage the project AND the user is not Super Admin
                                                                                if ($assignment['user_role'] !== 'super_admin') {
                                                                                    $can_unassign = true;
                                                                                }
                                                                            }
                                                                            ?>
                                                                            
                                                                            <?php if ($can_unassign): ?>
                                                                            <form method="post" onsubmit="return confirmUnassign('<?= esc($assignment['username']) ?>', '<?= $assignment['assignment_level'] ?>', '<?= addslashes($display_text) ?>', '<?= $assignment['user_role'] ?>');" class="d-inline">
                                                                                <input type="hidden" name="action" value="unassign_user">
                                                                                <input type="hidden" name="unassign_user_id" value="<?= (int)$assignment['user_id'] ?>">
                                                                                <input type="hidden" name="unassign_level_type" value="<?= $assignment['assignment_level'] ?>">
                                                                                <input type="hidden" name="unassign_level_id" value="<?= 
                                                                                    $assignment['assignment_level'] === 'project' ? (int)$assignment['project_id'] : 
                                                                                    ($assignment['assignment_level'] === 'phase' ? (int)$assignment['phase_id'] : 
                                                                                    ($assignment['assignment_level'] === 'activity' ? (int)$assignment['activity_id'] : (int)$assignment['subactivity_id'])) 
                                                                                ?>">
                                                                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                                                                    <i class="fas fa-times"></i> Remove
                                                                                </button>
                                                                            </form>
                                                                            <?php else: ?>
                                                                            <span class="text-muted small">No permission</span>
                                                                            <?php endif; ?>
                                                                        </td>
                                                                    </tr>
                                                                    <?php endforeach; ?>
                                                                </tbody>
                                                            </table>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                        
                                        <!-- Pagination for all assignments -->
                                        <?php 
                                        $total_assignment_pages = ceil($total_assignments / $items_per_page);
                                        if ($total_assignment_pages > 1): 
                                        ?>
                                        <nav aria-label="Assignments pagination" class="mt-4">
                                            <ul class="pagination justify-content-center">
                                                <li class="page-item <?= $current_page == 1 ? 'disabled' : '' ?>">
                                                    <a class="page-link" href="<?= getPaginationUrl(max(1, $current_page - 1)) ?>">
                                                        <i class="fas fa-chevron-left"></i>
                                                    </a>
                                                </li>
                                                
                                                <?php 
                                                $start_page = max(1, $current_page - 2);
                                                $end_page = min($total_assignment_pages, $current_page + 2);
                                                
                                                for ($i = $start_page; $i <= $end_page; $i++): 
                                                ?>
                                                    <li class="page-item <?= $i == $current_page ? 'active' : '' ?>">
                                                        <a class="page-link" href="<?= getPaginationUrl($i) ?>"><?= $i ?></a>
                                                    </li>
                                                <?php endfor; ?>
                                                
                                                <li class="page-item <?= $current_page == $total_assignment_pages ? 'disabled' : '' ?>">
                                                    <a class="page-link" href="<?= getPaginationUrl(min($total_assignment_pages, $current_page + 1)) ?>">
                                                        <i class="fas fa-chevron-right"></i>
                                                    </a>
                                                </li>
                                            </ul>
                                        </nav>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Project Hierarchy Tab -->
                <div class="tab-pane fade" id="hierarchy" role="tabpanel">
                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <div>
                                        <h5 class="mb-0"><i class="fas fa-sitemap me-2"></i>Project Hierarchy</h5>
                                        <p class="mb-0 text-white opacity-75">
                                            <?php if ($user_role === 'pm_manager'): ?>
                                                Expand projects you manage to view phases, activities
                                            <?php else: ?>
                                                Expand projects to view phases, activities
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                    <div class="d-flex align-items-center">
                                        <span class="text-white me-3">
                                            Showing <?= count($projects) ?> active projects
                                            <?php if ($user_role === 'pm_manager'): ?>
                                                (that you manage)
                                            <?php endif; ?>
                                        </span>
                                        <button class="btn btn-light" data-bs-toggle="modal" data-bs-target="#assignUserModal">
                                            <i class="fas fa-user-plus me-1"></i> Quick Assign
                                        </button>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <div class="hierarchy-container">
                                        <?php if (empty($projects)): ?>
                                            <div class="empty-state">
                                                <i class="fas fa-project-diagram"></i>
                                                <h4>
                                                    <?php if ($user_role === 'pm_manager'): ?>
                                                        No Projects Assigned to You
                                                    <?php else: ?>
                                                        No Active Projects
                                                    <?php endif; ?>
                                                </h4>
                                                <p>
                                                    <?php if ($user_role === 'pm_manager'): ?>
                                                        You haven't been assigned to any projects yet. Contact Super Admin for project assignments.
                                                    <?php else: ?>
                                                        There are no active projects to display
                                                    <?php endif; ?>
                                                </p>
                                            </div>
                                        <?php else: ?>
                                            <?php 
                                            // Pagination for projects in hierarchy
                                            $projects_per_page = 5;
                                            $total_project_pages = ceil(count($projects) / $projects_per_page);
                                            $project_page = isset($_GET['project_page']) ? max(1, (int)$_GET['project_page']) : 1;
                                            $project_offset = ($project_page - 1) * $projects_per_page;
                                            $paginated_projects = array_slice($projects, $project_offset, $projects_per_page);
                                            ?>
                                            
                                            <?php foreach ($paginated_projects as $project): ?>
                                            <div class="hierarchy-project">
                                                <div class="hierarchy-project-header" onclick="toggleProject(<?= $project['id'] ?>)">
                                                    <div>
                                                        <i class="fas fa-project-diagram me-2"></i>
                                                        <strong><?= esc($project['name']) ?></strong>
                                                        <span class="badge bg-light text-dark ms-2"><?= $project['assigned_users'] ?> users assigned</span>
                                                        <span class="badge bg-secondary ms-1"><?= $project['status'] ?></span>
                                                        <?php if ($user_role === 'pm_manager'): ?>
                                                            <span class="badge pm-manager-badge ms-1"><i class="fas fa-user-tie me-1"></i>You Manage</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div>
                                                        <i class="fas fa-chevron-down" id="projectIcon<?= $project['id'] ?>"></i>
                                                    </div>
                                                </div>
                                                <div class="hierarchy-project-body" id="projectBody<?= $project['id'] ?>">
                                                    <?php 
                                                    // Get phases for this project
                                                    $project_phases = array_filter($phases, function($phase) use ($project) {
                                                        return $phase['project_id'] == $project['id'];
                                                    });
                                                    ?>
                                                    
                                                    <?php if (empty($project_phases)): ?>
                                                        <div class="text-center py-3 text-muted">
                                                            <i class="fas fa-info-circle me-2"></i>
                                                            No phases defined for this project
                                                        </div>
                                                    <?php else: ?>
                                                        <?php foreach ($project_phases as $phase): ?>
                                                        <div class="hierarchy-phase">
                                                            <div class="hierarchy-phase-header" onclick="togglePhase(<?= $phase['id'] ?>)">
                                                                <div>
                                                                    <i class="fas fa-tasks me-2"></i>
                                                                    <?= esc($phase['name']) ?>
                                                                    <span class="assignment-count"><?= $phase['assigned_users'] ?> assigned</span>
                                                                    <span class="badge bg-secondary ms-1"><?= $phase['status'] ?></span>
                                                                </div>
                                                                <div>
                                                                    <i class="fas fa-chevron-down" id="phaseIcon<?= $phase['id'] ?>"></i>
                                                                </div>
                                                            </div>
                                                            <div class="hierarchy-phase-body" id="phaseBody<?= $phase['id'] ?>">
                                                                <?php 
                                                                // Get activities for this phase
                                                                $phase_activities = array_filter($activities, function($activity) use ($phase) {
                                                                    return $activity['phase_id'] == $phase['id'];
                                                                });
                                                                ?>
                                                                
                                                                <?php if (empty($phase_activities)): ?>
                                                                    <div class="text-center py-2 text-muted small">
                                                                        No activities defined for this phase
                                                                    </div>
                                                                <?php else: ?>
                                                                    <?php foreach ($phase_activities as $activity): ?>
                                                                    <div class="hierarchy-activity">
                                                                        <div class="hierarchy-activity-header" onclick="toggleActivity(<?= $activity['id'] ?>)">
                                                                            <div>
                                                                                <i class="fas fa-list-check me-2"></i>
                                                                                <?= esc($activity['name']) ?>
                                                                                <span class="assignment-count"><?= $activity['assigned_users'] ?> assigned</span>
                                                                                <span class="badge bg-secondary ms-1"><?= $activity['status'] ?></span>
                                                                            </div>
                                                                            <div>
                                                                                <i class="fas fa-chevron-down ms-2" id="activityIcon<?= $activity['id'] ?>"></i>
                                                                            </div>
                                                                        </div>
                                                                        <div class="hierarchy-activity-body" id="activityBody<?= $activity['id'] ?>">
                                                                            <?php 
                                                                            // Get sub-activities for this activity
                                                                            $activity_subs = array_filter($subs, function($sub) use ($activity) {
                                                                                return $sub['activity_id'] == $activity['id'];
                                                                            });
                                                                            ?>
                                                                            
                                                                            <?php if (empty($activity_subs)): ?>
                                                                                <div class="text-center py-2 text-muted small">
                                                                                    No sub-activities defined
                                                                                </div>
                                                                            <?php else: ?>
                                                                                <?php foreach ($activity_subs as $sub): ?>
                                                                                <div class="hierarchy-subactivity">
                                                                                    <div>
                                                                                        <i class="fas fa-list-ol me-2"></i>
                                                                                        <?= esc($sub['name']) ?>
                                                                                        <span class="badge bg-secondary ms-1"><?= $sub['status'] ?></span>
                                                                                        <?php if ($sub['assigned_to_name']): ?>
                                                                                            <span class="badge bg-light text-dark ms-1">
                                                                                                <i class="fas fa-user me-1"></i><?= esc($sub['assigned_to_name']) ?>
                                                                                            </span>
                                                                                        <?php endif; ?>
                                                                                        <span class="assignment-count"><?= $sub['assigned_users'] ?> assigned</span>
                                                                                    </div>
                                                                                </div>
                                                                                <?php endforeach; ?>
                                                                            <?php endif; ?>
                                                                        </div>
                                                                    </div>
                                                                    <?php endforeach; ?>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                            
                                            <!-- Pagination for projects -->
                                            <?php if ($total_project_pages > 1): ?>
                                            <nav aria-label="Project pagination" class="mt-4">
                                                <ul class="pagination justify-content-center">
                                                    <li class="page-item <?= $project_page == 1 ? 'disabled' : '' ?>">
                                                        <a class="page-link" href="?tab=hierarchy&project_page=<?= max(1, $project_page - 1) ?>#hierarchy">
                                                            <i class="fas fa-chevron-left"></i>
                                                        </a>
                                                    </li>
                                                    
                                                    <?php 
                                                    $start_page = max(1, $project_page - 2);
                                                    $end_page = min($total_project_pages, $project_page + 2);
                                                    
                                                    for ($i = $start_page; $i <= $end_page; $i++): 
                                                    ?>
                                                        <li class="page-item <?= $i == $project_page ? 'active' : '' ?>">
                                                            <a class="page-link" href="?tab=hierarchy&project_page=<?= $i ?>#hierarchy"><?= $i ?></a>
                                                        </li>
                                                    <?php endfor; ?>
                                                    
                                                    <li class="page-item <?= $project_page == $total_project_pages ? 'disabled' : '' ?>">
                                                        <a class="page-link" href="?tab=hierarchy&project_page=<?= min($total_project_pages, $project_page + 1) ?>#hierarchy">
                                                            <i class="fas fa-chevron-right"></i>
                                                        </a>
                                                    </li>
                                                </ul>
                                                <div class="text-center text-muted small mt-2">
                                                    Showing projects <?= ($project_offset + 1) ?>-<?= min($project_offset + $projects_per_page, count($projects)) ?> of <?= count($projects) ?>
                                                    <?php if ($user_role === 'pm_manager'): ?>
                                                        (that you manage)
                                                    <?php endif; ?>
                                                </div>
                                            </nav>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Bulk Assignment Tab -->
                <div class="tab-pane fade" id="bulk" role="tabpanel">
                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="mb-0"><i class="fas fa-users me-2"></i>Bulk User Assignment</h5>
                                    <p class="mb-0 text-white opacity-75">
                                        <?php if ($user_role === 'pm_manager'): ?>
                                            Assign multiple users to projects you manage, phases, or activities at once
                                        <?php else: ?>
                                            Assign multiple users to projects, phases, or activities at once
                                        <?php endif; ?>
                                    </p>
                                </div>
                                <div class="card-body">
                                    <form method="post" id="bulkAssignForm">
                                        <input type="hidden" name="action" value="bulk_assign">
                                        
                                        <div class="row mb-4">
                                            <div class="col-md-6">
                                                <div class="card h-100">
                                                    <div class="card-header bg-light">
                                                        <h6 class="mb-0"><i class="fas fa-user-friends me-2"></i>Select Users (<?= count($users) ?> available)</h6>
                                                    </div>
                                                    <div class="card-body">
                                                        <div class="mb-3">
                                                            <div class="d-flex justify-content-between align-items-center mb-3">
                                                                <button type="button" class="btn btn-sm btn-outline-primary" onclick="selectAllUsers()">
                                                                    <i class="fas fa-check-square me-1"></i> Select All
                                                                </button>
                                                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="deselectAllUsers()">
                                                                    <i class="fas fa-square me-1"></i> Deselect All
                                                                </button>
                                                            </div>
                                                            
                                                            <div style="max-height: 400px; overflow-y: auto;" class="border rounded p-3">
                                                                <?php if (empty($users)): ?>
                                                                    <div class="text-center text-muted py-3">
                                                                        <i class="fas fa-users fa-2x mb-2"></i>
                                                                        <p>No users available for assignment</p>
                                                                    </div>
                                                                <?php else: ?>
                                                                    <?php foreach ($users as $u): ?>
                                                                        <div class="user-check-item" onclick="toggleUserCheckbox(<?= $u['id'] ?>)">
                                                                            <div class="form-check">
                                                                                <input class="form-check-input user-checkbox" type="checkbox" name="user_ids[]" value="<?= (int)$u['id'] ?>" id="user_<?= (int)$u['id'] ?>">
                                                                                <label class="form-check-label d-flex align-items-center" for="user_<?= (int)$u['id'] ?>">
                                                                                    <div class="user-avatar me-3" style="width: 35px; height: 35px; border-radius: 50%; background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-light) 100%); color: white; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 0.9rem;">
                                                                                        <?= strtoupper(substr($u['username'], 0, 1)) ?>
                                                                                    </div>
                                                                                    <div>
                                                                                        <div class="fw-medium"><?= esc($u['username']) ?></div>
                                                                                        <div class="text-muted small"><?= esc($u['email']) ?> • 
                                                                                            <span class="badge <?= $u['role'] === 'super_admin' ? 'super-admin-badge' : ($u['role'] === 'pm_manager' ? 'pm-manager-badge' : 'bg-secondary') ?>">
                                                                                                <?= esc($u['role']) ?>
                                                                                                <?php if ($u['role'] === 'super_admin'): ?>
                                                                                                    <i class="fas fa-crown ms-1"></i>
                                                                                                <?php elseif ($u['role'] === 'pm_manager'): ?>
                                                                                                    <i class="fas fa-user-tie ms-1"></i>
                                                                                                <?php endif; ?>
                                                                                            </span>
                                                                                        </div>
                                                                                    </div>
                                                                                </label>
                                                                            </div>
                                                                        </div>
                                                                    <?php endforeach; ?>
                                                                <?php endif; ?>
                                                            </div>
                                                            <div class="mt-3">
                                                                <div class="alert alert-info small mb-0">
                                                                    <i class="fas fa-info-circle me-2"></i>
                                                                    <span id="selectedCountText">0 users selected</span>
                                                                    <?php if ($user_role === 'super_admin'): ?>
                                                                        <br><strong>Note:</strong> You cannot assign yourself (Super Admin has full access)
                                                                        <br><strong>Role Restriction:</strong> You can only assign <strong>PROJECTS</strong> to PM Managers
                                                                    <?php endif; ?>
                                                                    <?php if ($user_role === 'pm_manager'): ?>
                                                                        <br><strong>Role Restriction:</strong> You cannot assign anything to Super Admin users
                                                                    <?php endif; ?>
                                                                    <span id="bulkAssignmentRules" style="display: none;">
                                                                        <br><strong>Rules for this assignment:</strong>
                                                                        <span id="rulesText"></span>
                                                                    </span>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="col-md-6">
                                                <div class="card h-100">
                                                    <div class="card-header bg-light">
                                                        <h6 class="mb-0"><i class="fas fa-sitemap me-2"></i>Assignment Target</h6>
                                                    </div>
                                                    <div class="card-body">
                                                        <div class="mb-3">
                                                            <label for="bulkLevelType" class="form-label fw-bold">Assignment Level</label>
                                                            <select class="form-select" id="bulkLevelType" name="bulk_level_type" required onchange="updateBulkFields()">
                                                                <option value="">Select level...</option>
                                                                <option value="project">Project Level</option>
                                                                <option value="phase">Phase Level</option>
                                                                <option value="activity">Activity Level</option>
                                                                <option value="sub_activity">Sub-Activity Level</option>
                                                            </select>
                                                        </div>
                                                        
                                                        <div class="mb-3" id="bulkProjectSelection" style="display: none;">
                                                            <label for="bulkProject" class="form-label fw-bold">Select Project <span class="text-danger">*</span></label>
                                                            <select class="form-select" id="bulkProject" name="bulk_level_id" onchange="updateBulkPreview()">
                                                                <option value="">Choose a project...</option>
                                                                <?php foreach ($projects as $p): ?>
                                                                    <option value="<?= (int)$p['id'] ?>">
                                                                        <?= esc($p['name']) ?> (<?= $p['assigned_users'] ?> users, <?= $p['status'] ?>)
                                                                        <?php if ($user_role === 'pm_manager'): ?>
                                                                            (You Manage)
                                                                        <?php endif; ?>
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                        
                                                        <div class="mb-3" id="bulkPhaseSelection" style="display: none;">
                                                            <label for="bulkPhase" class="form-label fw-bold">Select Phase <span class="text-danger">*</span></label>
                                                            <select class="form-select" id="bulkPhase" name="bulk_level_id" onchange="updateBulkPreview()">
                                                                <option value="">Choose a phase...</option>
                                                                <?php foreach ($phases as $ph): ?>
                                                                    <option value="<?= (int)$ph['id'] ?>">
                                                                        <?= esc($ph['project_name']) ?> → <?= esc($ph['name']) ?> (<?= $ph['assigned_users'] ?> users)
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                        
                                                        <div class="mb-3" id="bulkActivitySelection" style="display: none;">
                                                            <label for="bulkActivity" class="form-label fw-bold">Select Activity <span class="text-danger">*</span></label>
                                                            <select class="form-select" id="bulkActivity" name="bulk_level_id" onchange="updateBulkPreview()">
                                                                <option value="">Choose an activity...</option>
                                                                <?php foreach ($activities as $act): ?>
                                                                    <option value="<?= (int)$act['id'] ?>">
                                                                        <?= esc($act['project_name']) ?> → <?= esc($act['phase_name']) ?> → <?= esc($act['name']) ?> (<?= $act['assigned_users'] ?> users)
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                        
                                                        <div class="mb-3" id="bulkSubActivitySelection" style="display: none;">
                                                            <label for="bulkSubActivity" class="form-label fw-bold">Select Sub-Activity <span class="text-danger">*</span></label>
                                                            <select class="form-select" id="bulkSubActivity" name="bulk_level_id" onchange="updateBulkPreview()">
                                                                <option value="">Choose a sub-activity...</option>
                                                                <?php foreach ($subs as $s): ?>
                                                                    <option value="<?= (int)$s['id'] ?>">
                                                                        <?= esc($s['project_name']) ?> → <?= esc($s['phase_name']) ?> → <?= esc($s['activity_name']) ?> → <?= esc($s['name']) ?> (<?= $s['assigned_users'] ?> users)
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                        
                                                        <div id="bulkPreview" class="alert alert-warning" style="display: none;">
                                                            <h6 class="alert-heading"><i class="fas fa-eye me-2"></i>Assignment Preview</h6>
                                                            <div id="previewDetails" class="small"></div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="row">
                                            <div class="col-12">
                                                <div class="card">
                                                    <div class="card-body text-center">
                                                        <button type="submit" class="btn btn-success btn-lg px-5" id="bulkSubmitBtn" disabled>
                                                            <i class="fas fa-users me-2"></i> Assign Selected Users
                                                        </button>
                                                        <div class="mt-3 text-muted small">
                                                            <i class="fas fa-info-circle me-1"></i>
                                                            Users already assigned will be skipped. Check preview for details.
                                                            <?php if ($user_role === 'super_admin'): ?>
                                                                <br><strong>Role Restriction:</strong> Super Admin can only assign <strong>PROJECTS</strong> to PM Managers
                                                            <?php endif; ?>
                                                            <?php if ($user_role === 'pm_manager'): ?>
                                                                <br><strong>Role Restriction:</strong> PM Managers cannot assign anything to Super Admin users
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Actions Button (Hidden initially) -->
    <button class="quick-actions-btn" id="quickActionsBtn" style="display: none;">
        <i class="fas fa-bolt"></i>
    </button>

    <!-- Quick Actions Modal -->
    <div class="modal fade quick-actions-modal" id="quickActionsModal" tabindex="-1" aria-labelledby="quickActionsModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="quickActionsModalLabel">
                        <i class="fas fa-bolt me-2"></i>Quick Actions
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <a href="#" class="action-item" data-bs-toggle="modal" data-bs-target="#assignUserModal" data-bs-dismiss="modal">
                        <i class="fas fa-user-plus text-primary"></i>
                        <div class="action-text">
                            <h6>Assign Single User</h6>
                            <p>Assign one user to a project, phase, activity, or sub-activity</p>
                        </div>
                        <i class="fas fa-chevron-right"></i>
                    </a>
                    
                    <a href="#bulk" class="action-item" onclick="switchToBulkTab()" data-bs-dismiss="modal">
                        <i class="fas fa-users text-success"></i>
                        <div class="action-text">
                            <h6>Bulk Assignment</h6>
                            <p>Assign multiple users at once to any level</p>
                        </div>
                        <i class="fas fa-chevron-right"></i>
                    </a>
                    
                    <a href="#assignments" class="action-item" onclick="switchToAssignmentsTab()" data-bs-dismiss="modal">
                        <i class="fas fa-user-check text-info"></i>
                        <div class="action-text">
                            <h6>View Current Assignments</h6>
                            <p>See all current user assignments organized by project</p>
                        </div>
                        <i class="fas fa-chevron-right"></i>
                    </a>
                    
                    <a href="#hierarchy" class="action-item" onclick="switchToHierarchyTab()" data-bs-dismiss="modal">
                        <i class="fas fa-sitemap text-warning"></i>
                        <div class="action-text">
                            <h6>Project Hierarchy</h6>
                            <p>View project structure with phases and activities</p>
                        </div>
                        <i class="fas fa-chevron-right"></i>
                    </a>
                    
                    <?php if ($user_role === 'super_admin' || $user_role === 'admin'): ?>
                    <div class="mt-3 p-3 bg-light rounded">
                        <h6 class="mb-2"><i class="fas fa-shield-alt me-2"></i>Super Admin/Admin Rules</h6>
                        <ul class="small mb-0">
                            <li>Can only assign <strong>PROJECTS</strong> to PM Managers</li>
                            <li>Cannot assign phases/activities/sub-activities to PM Managers</li>
                            <li>PM Managers cannot assign anything to Super Admin</li>
                            <li>When unassigning project from PM Manager, all PM Employees are also unassigned</li>
                        </ul>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($user_role === 'pm_manager'): ?>
                    <div class="mt-3 p-3 bg-light rounded">
                        <h6 class="mb-2"><i class="fas fa-user-tie me-2"></i>PM Manager Rules</h6>
                        <ul class="small mb-0">
                            <li>Can only assign users to projects assigned to you by Super Admin</li>
                            <li><strong>Cannot assign anything to Super Admin users</strong></li>
                            <li>Can assign phases/activities/sub-activities within your projects</li>
                            <li>Can assign PM Employees directly to phases/activities without project assignment</li>
                        </ul>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Single Assignment Modal -->
    <div class="modal fade" id="assignUserModal" tabindex="-1" aria-labelledby="assignUserModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="assignUserModalLabel">
                        <i class="fas fa-user-plus me-2"></i>Assign User
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" id="assignForm" class="needs-validation" novalidate>
                    <input type="hidden" name="action" value="assign_user">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="assignUser" class="form-label">Select User <span class="text-danger">*</span></label>
                                <select class="form-select" id="assignUser" name="assign_user_id" required onchange="checkUserRole()">
                                    <option value="">Choose a user...</option>
                                    <?php foreach ($users as $u): ?>
                                        <option value="<?= (int)$u['id'] ?>" data-role="<?= esc($u['role']) ?>">
                                            <?= esc($u['username']) ?> (<?= esc($u['email']) ?>) - <?= esc($u['role']) ?>
                                            <?php if ($user_role === 'super_admin' && $u['id'] == $current_user_id): ?>
                                                (Cannot assign yourself)
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="invalid-feedback">
                                    Please select a user.
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label for="assignLevelType" class="form-label">Assignment Level <span class="text-danger">*</span></label>
                                <select class="form-select" id="assignLevelType" name="assign_level_type" required onchange="updateAssignmentFields()">
                                    <option value="">Select level...</option>
                                    <option value="project">Project Level</option>
                                    <option value="phase">Phase Level</option>
                                    <option value="activity">Activity Level</option>
                                    <option value="sub_activity">Sub-Activity Level</option>
                                </select>
                                <div class="invalid-feedback">
                                    Please select an assignment level.
                                </div>
                            </div>
                            
                            <div class="col-12" id="projectSelection" style="display: none;">
                                <label for="assignProject" class="form-label">Select Project <span class="text-danger">*</span></label>
                                <select class="form-select" id="assignProject" name="assign_project_id" required>
                                    <option value="">Choose a project...</option>
                                    <?php foreach ($projects as $p): ?>
                                        <option value="<?= (int)$p['id'] ?>">
                                            <?= esc($p['name']) ?> (<?= $p['assigned_users'] ?> users, <?= $p['status'] ?>)
                                            <?php if ($user_role === 'pm_manager'): ?>
                                                (You Manage)
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="invalid-feedback">
                                    Please select a project.
                                </div>
                            </div>
                            
                            <div class="col-12" id="phaseSelection" style="display: none;">
                                <label for="assignPhase" class="form-label">Select Phase <span class="text-danger">*</span></label>
                                <select class="form-select" id="assignPhase" name="assign_phase_id" required>
                                    <option value="">Choose a phase...</option>
                                    <?php foreach ($phases as $ph): ?>
                                        <option value="<?= (int)$ph['id'] ?>">
                                            <?= esc($ph['project_name']) ?> → <?= esc($ph['name']) ?> (<?= $ph['assigned_users'] ?> users)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="invalid-feedback">
                                    Please select a phase.
                                </div>
                            </div>
                            
                            <div class="col-12" id="activitySelection" style="display: none;">
                                <label for="assignActivity" class="form-label">Select Activity <span class="text-danger">*</span></label>
                                <select class="form-select" id="assignActivity" name="assign_activity_id" required>
                                    <option value="">Choose an activity...</option>
                                    <?php foreach ($activities as $act): ?>
                                        <option value="<?= (int)$act['id'] ?>">
                                            <?= esc($act['project_name']) ?> → <?= esc($act['phase_name']) ?> → <?= esc($act['name']) ?> (<?= $act['assigned_users'] ?> users)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="invalid-feedback">
                                    Please select an activity.
                                </div>
                            </div>
                            
                            <div class="col-12" id="subActivitySelection" style="display: none;">
                                <label for="assignSubActivity" class="form-label">Select Sub-Activity <span class="text-danger">*</span></label>
                                <select class="form-select" id="assignSubActivity" name="assign_subactivity_id" required>
                                    <option value="">Choose a sub-activity...</option>
                                    <?php foreach ($subs as $s): ?>
                                        <option value="<?= (int)$s['id'] ?>">
                                            <?= esc($s['project_name']) ?> → <?= esc($s['phase_name']) ?> → <?= esc($s['activity_name']) ?> → <?= esc($s['name']) ?> (<?= $s['assigned_users'] ?> users)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="invalid-feedback">
                                    Please select a sub-activity.
                                </div>
                            </div>
                            
                            <div class="col-12">
                                <div class="alert alert-info small mb-0" id="assignmentRulesAlert">
                                    <i class="fas fa-info-circle me-2"></i>
                                    <strong>Assignment Rules:</strong><br>
                                    1. Users must be assigned to a project before being assigned to its phases/activities<br>
                                    2. PM Managers can assign PM Employees directly to phases/activities without project assignment<br>
                                    3. Each user can only be assigned once to any specific level<br>
                                    4. Projects marked as "terminated" cannot accept new assignments<br>
                                    5. <?php if ($user_role === 'super_admin'): ?>
                                        <span id="superAdminRule">Super Admin can only assign <strong>PROJECTS</strong> to PM Managers</span>
                                    <?php elseif ($user_role === 'pm_manager'): ?>
                                        <span id="pmManagerRule">PM Managers cannot assign anything to Super Admin users</span>
                                    <?php else: ?>
                                        Admin users have full assignment permissions
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-check me-1"></i> Assign User
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Sidebar functionality
            const sidebarContainer = document.getElementById('sidebarContainer');
            const sidebarToggler = document.getElementById('sidebarToggler');
            const mainContent = document.getElementById('mainContent');
            
            sidebarToggler.addEventListener('click', function() {
                sidebarContainer.classList.toggle('sidebar-collapsed');
                localStorage.setItem('sidebarCollapsed', sidebarContainer.classList.contains('sidebar-collapsed'));
                updateMainContentMargin();
            });
            
            if (localStorage.getItem('sidebarCollapsed') === 'true') {
                sidebarContainer.classList.add('sidebar-collapsed');
            }
            
            function updateMainContentMargin() {
                const isCollapsed = sidebarContainer.classList.contains('sidebar-collapsed');
                const sidebarWidth = isCollapsed ? 80 : 280;
                mainContent.style.marginLeft = sidebarWidth + 'px';
            }
            
            updateMainContentMargin();
            
            // Single Assignment Modal Logic
            const levelTypeSelect = document.getElementById('assignLevelType');
            const projectSelection = document.getElementById('projectSelection');
            const phaseSelection = document.getElementById('phaseSelection');
            const activitySelection = document.getElementById('activitySelection');
            const subActivitySelection = document.getElementById('subActivitySelection');
            const userSelect = document.getElementById('assignUser');
            
            window.updateAssignmentFields = function() {
                const level = levelTypeSelect.value;
                
                // Hide all selection divs
                [projectSelection, phaseSelection, activitySelection, subActivitySelection].forEach(el => {
                    el.style.display = 'none';
                    // Remove required attribute from all
                    const select = el.querySelector('select');
                    if (select) {
                        select.removeAttribute('required');
                        select.name = 'unused_' + select.name; // Change name to avoid conflict
                    }
                });
                
                // Show only the relevant selection
                let targetSelection = null;
                switch(level) {
                    case 'project':
                        targetSelection = projectSelection;
                        break;
                    case 'phase':
                        targetSelection = phaseSelection;
                        break;
                    case 'activity':
                        targetSelection = activitySelection;
                        break;
                    case 'sub_activity':
                        targetSelection = subActivitySelection;
                        break;
                }
                
                if (targetSelection) {
                    targetSelection.style.display = 'block';
                    const select = targetSelection.querySelector('select');
                    if (select) {
                        // Reset the name to the correct one
                        switch(level) {
                            case 'project':
                                select.name = 'assign_project_id';
                                break;
                            case 'phase':
                                select.name = 'assign_phase_id';
                                break;
                            case 'activity':
                                select.name = 'assign_activity_id';
                                break;
                            case 'sub_activity':
                                select.name = 'assign_subactivity_id';
                                break;
                        }
                        select.setAttribute('required', 'required');
                    }
                }
            };
            
            // Check user role for assignment restrictions
            window.checkUserRole = function() {
                const selectedOption = userSelect.options[userSelect.selectedIndex];
                const userRole = selectedOption ? selectedOption.getAttribute('data-role') : '';
                const level = levelTypeSelect.value;
                
                // Show warning if PM Manager is trying to assign to Super Admin
                if (userRole === 'super_admin' && '<?= $user_role ?>' === 'pm_manager') {
                    alert('Error: PM Managers cannot assign anything to Super Admin users!');
                    userSelect.value = '';
                }
                // Show warning if Super Admin is trying to assign non-project to PM Manager
                else if (userRole === 'pm_manager' && '<?= $user_role ?>' === 'super_admin' && level && level !== 'project') {
                    alert('Error: Super Admin can only assign PROJECTS to PM Managers, not phases, activities, or sub-activities!');
                    levelTypeSelect.value = '';
                    updateAssignmentFields();
                }
            };
            
            // Clear modal fields when hidden
            const assignModal = document.getElementById('assignUserModal');
            if (assignModal) {
                assignModal.addEventListener('hidden.bs.modal', function() {
                    document.getElementById('assignForm').reset();
                    // Reset all fields to hidden
                    [projectSelection, phaseSelection, activitySelection, subActivitySelection].forEach(el => {
                        el.style.display = 'none';
                        const select = el.querySelector('select');
                        if (select) {
                            select.removeAttribute('required');
                            select.name = 'unused_' + select.name;
                        }
                    });
                });
            }
            
            // Form validation
            const assignForm = document.getElementById('assignForm');
            assignForm.addEventListener('submit', function(event) {
                const selectedOption = userSelect.options[userSelect.selectedIndex];
                const userRole = selectedOption ? selectedOption.getAttribute('data-role') : '';
                const level = levelTypeSelect.value;
                
                // Validate role restrictions
                if (userRole === 'super_admin' && '<?= $user_role ?>' === 'pm_manager') {
                    event.preventDefault();
                    event.stopPropagation();
                    alert('Error: PM Managers cannot assign anything to Super Admin users!');
                    return false;
                }
                
                if (userRole === 'pm_manager' && '<?= $user_role ?>' === 'super_admin' && level && level !== 'project') {
                    event.preventDefault();
                    event.stopPropagation();
                    alert('Error: Super Admin can only assign PROJECTS to PM Managers, not phases, activities, or sub-activities!');
                    return false;
                }
                
                if (!assignForm.checkValidity()) {
                    event.preventDefault();
                    event.stopPropagation();
                }
                assignForm.classList.add('was-validated');
            }, false);
            
            // Project Hierarchy Toggle Functions
            window.toggleProject = function(projectId) {
                const body = document.getElementById('projectBody' + projectId);
                const icon = document.getElementById('projectIcon' + projectId);
                
                if (body.classList.contains('show')) {
                    body.classList.remove('show');
                    icon.classList.remove('fa-chevron-up');
                    icon.classList.add('fa-chevron-down');
                } else {
                    body.classList.add('show');
                    icon.classList.remove('fa-chevron-down');
                    icon.classList.add('fa-chevron-up');
                }
            };
            
            window.togglePhase = function(phaseId) {
                const body = document.getElementById('phaseBody' + phaseId);
                const icon = document.getElementById('phaseIcon' + phaseId);
                event.stopPropagation();
                
                if (body.classList.contains('show')) {
                    body.classList.remove('show');
                    icon.classList.remove('fa-chevron-up');
                    icon.classList.add('fa-chevron-down');
                } else {
                    body.classList.add('show');
                    icon.classList.remove('fa-chevron-down');
                    icon.classList.add('fa-chevron-up');
                }
            };
            
            window.toggleActivity = function(activityId) {
                const body = document.getElementById('activityBody' + activityId);
                const icon = document.getElementById('activityIcon' + activityId);
                event.stopPropagation();
                
                if (body.classList.contains('show')) {
                    body.classList.remove('show');
                    icon.classList.remove('fa-chevron-up');
                    icon.classList.add('fa-chevron-down');
                } else {
                    body.classList.add('show');
                    icon.classList.remove('fa-chevron-down');
                    icon.classList.add('fa-chevron-up');
                }
            };
            
            // Bulk Assignment Logic
            const bulkLevelType = document.getElementById('bulkLevelType');
            const bulkProjectSelection = document.getElementById('bulkProjectSelection');
            const bulkPhaseSelection = document.getElementById('bulkPhaseSelection');
            const bulkActivitySelection = document.getElementById('bulkActivitySelection');
            const bulkSubActivitySelection = document.getElementById('bulkSubActivitySelection');
            const selectedCountText = document.getElementById('selectedCountText');
            const bulkPreview = document.getElementById('bulkPreview');
            const previewDetails = document.getElementById('previewDetails');
            const bulkSubmitBtn = document.getElementById('bulkSubmitBtn');
            const bulkAssignmentRules = document.getElementById('bulkAssignmentRules');
            const rulesText = document.getElementById('rulesText');
            
            window.updateBulkFields = function() {
                const level = bulkLevelType.value;
                
                [bulkProjectSelection, bulkPhaseSelection, bulkActivitySelection, bulkSubActivitySelection].forEach(el => {
                    el.style.display = 'none';
                    const select = el.querySelector('select');
                    if (select) {
                        select.removeAttribute('required');
                        select.name = 'unused_bulk_' + select.name;
                    }
                });
                
                let targetSelection = null;
                switch(level) {
                    case 'project':
                        targetSelection = bulkProjectSelection;
                        break;
                    case 'phase':
                        targetSelection = bulkPhaseSelection;
                        break;
                    case 'activity':
                        targetSelection = bulkActivitySelection;
                        break;
                    case 'sub_activity':
                        targetSelection = bulkSubActivitySelection;
                        break;
                }
                
                if (targetSelection) {
                    targetSelection.style.display = 'block';
                    const select = targetSelection.querySelector('select');
                    if (select) {
                        select.name = 'bulk_level_id';
                        select.setAttribute('required', 'required');
                    }
                }
                
                updateBulkRules();
                updateBulkPreview();
            };
            
            // User selection functions
            window.selectAllUsers = function() {
                document.querySelectorAll('.user-checkbox').forEach(checkbox => {
                    // Skip Super Admin users if current user is PM Manager
                    if ('<?= $user_role ?>' === 'pm_manager') {
                        const userItem = checkbox.closest('.user-check-item');
                        const roleBadge = userItem.querySelector('.badge');
                        if (roleBadge && roleBadge.classList.contains('super-admin-badge')) {
                            return; // Skip Super Admin users
                        }
                    }
                    checkbox.checked = true;
                    checkbox.closest('.user-check-item').classList.add('selected');
                });
                updateSelectedCount();
                updateBulkPreview();
            };
            
            window.deselectAllUsers = function() {
                document.querySelectorAll('.user-checkbox').forEach(checkbox => {
                    checkbox.checked = false;
                    checkbox.closest('.user-check-item').classList.remove('selected');
                });
                updateSelectedCount();
                updateBulkPreview();
            };
            
            window.toggleUserCheckbox = function(userId) {
                const checkbox = document.getElementById('user_' + userId);
                const item = checkbox.closest('.user-check-item');
                
                // Check if PM Manager is trying to select Super Admin
                if ('<?= $user_role ?>' === 'pm_manager') {
                    const roleBadge = item.querySelector('.badge');
                    if (roleBadge && roleBadge.classList.contains('super-admin-badge')) {
                        alert('Error: PM Managers cannot assign anything to Super Admin users!');
                        return;
                    }
                }
                
                checkbox.checked = !checkbox.checked;
                
                if (checkbox.checked) {
                    item.classList.add('selected');
                } else {
                    item.classList.remove('selected');
                }
                
                updateSelectedCount();
                updateBulkPreview();
            };
            
            function updateSelectedCount() {
                const selected = document.querySelectorAll('.user-checkbox:checked').length;
                selectedCountText.textContent = selected + ' users selected';
                updateBulkSubmitBtn();
            }
            
            function updateBulkRules() {
                const level = bulkLevelType.value;
                let rules = '';
                
                switch(level) {
                    case 'project':
                        rules = 'All selected users will be assigned to the project.';
                        break;
                    case 'phase':
                        rules = 'Only users already assigned to the project will be assigned to the phase. Others will be skipped. PM Employees can be assigned directly by PM Managers.';
                        break;
                    case 'activity':
                        rules = 'Only users already assigned to the project will be assigned to the activity. Others will be skipped. PM Employees can be assigned directly by PM Managers.';
                        break;
                    case 'sub_activity':
                        rules = 'Only users already assigned to the project will be assigned to the sub-activity. Others will be skipped. PM Employees can be assigned directly by PM Managers.';
                        break;
                }
                
                // Add role restriction rules
                if ('<?= $user_role ?>' === 'super_admin') {
                    rules += ' Super Admin can only assign PROJECTS to PM Managers.';
                } else if ('<?= $user_role ?>' === 'pm_manager') {
                    rules += ' PM Managers cannot assign anything to Super Admin users.';
                }
                
                if (level) {
                    rulesText.textContent = rules;
                    bulkAssignmentRules.style.display = 'block';
                } else {
                    bulkAssignmentRules.style.display = 'none';
                }
            }
            
            function updateBulkPreview() {
                const selected = document.querySelectorAll('.user-checkbox:checked').length;
                const level = bulkLevelType.value;
                
                if (selected.length === 0 || !level) {
                    bulkPreview.style.display = 'none';
                    return;
                }
                
                let targetText = '';
                let targetSelect = null;
                
                switch(level) {
                    case 'project':
                        targetSelect = document.getElementById('bulkProject');
                        break;
                    case 'phase':
                        targetSelect = document.getElementById('bulkPhase');
                        break;
                    case 'activity':
                        targetSelect = document.getElementById('bulkActivity');
                        break;
                    case 'sub_activity':
                        targetSelect = document.getElementById('bulkSubActivity');
                        break;
                }
                
                if (targetSelect && targetSelect.value) {
                    targetText = targetSelect.options[targetSelect.selectedIndex].text;
                    
                    let preview = `<strong>${selected.length} users</strong> will be assigned to:<br>`;
                    preview += `<strong>${targetText}</strong><br><br>`;
                    
                    if (level !== 'project') {
                        preview += `<span class="text-danger"><i class="fas fa-exclamation-triangle me-1"></i>`;
                        preview += `Users not assigned to the project will be skipped.`;
                        if ('<?= $user_role ?>' === 'pm_manager') {
                            preview += ` PM Employees can be assigned directly by PM Managers.`;
                        }
                        preview += `</span><br>`;
                    }
                    
                    // Add role restriction warnings
                    if ('<?= $user_role ?>' === 'super_admin') {
                        preview += `<span class="text-warning"><i class="fas fa-exclamation-circle me-1"></i>`;
                        preview += `Super Admin can only assign PROJECTS to PM Managers.</span>`;
                    } else if ('<?= $user_role ?>' === 'pm_manager') {
                        preview += `<span class="text-warning"><i class="fas fa-exclamation-circle me-1"></i>`;
                        preview += `PM Managers cannot assign anything to Super Admin users.</span>`;
                    }
                    
                    previewDetails.innerHTML = preview;
                    bulkPreview.style.display = 'block';
                } else {
                    bulkPreview.style.display = 'none';
                }
                
                updateBulkSubmitBtn();
            }
            
            function updateBulkSubmitBtn() {
                const selected = document.querySelectorAll('.user-checkbox:checked').length;
                const level = bulkLevelType.value;
                let hasTarget = false;
                
                switch(level) {
                    case 'project':
                        hasTarget = document.getElementById('bulkProject')?.value ? true : false;
                        break;
                    case 'phase':
                        hasTarget = document.getElementById('bulkPhase')?.value ? true : false;
                        break;
                    case 'activity':
                        hasTarget = document.getElementById('bulkActivity')?.value ? true : false;
                        break;
                    case 'sub_activity':
                        hasTarget = document.getElementById('bulkSubActivity')?.value ? true : false;
                        break;
                }
                
                bulkSubmitBtn.disabled = !(selected > 0 && level && hasTarget);
            }
            
            // Initialize event listeners for bulk assignment
            document.querySelectorAll('.user-checkbox').forEach(checkbox => {
                checkbox.addEventListener('change', function() {
                    const item = this.closest('.user-check-item');
                    
                    // Check if PM Manager is trying to select Super Admin
                    if (this.checked && '<?= $user_role ?>' === 'pm_manager') {
                        const roleBadge = item.querySelector('.badge');
                        if (roleBadge && roleBadge.classList.contains('super-admin-badge')) {
                            alert('Error: PM Managers cannot assign anything to Super Admin users!');
                            this.checked = false;
                            item.classList.remove('selected');
                            updateSelectedCount();
                            updateBulkPreview();
                            return;
                        }
                    }
                    
                    if (this.checked) {
                        item.classList.add('selected');
                    } else {
                        item.classList.remove('selected');
                    }
                    updateSelectedCount();
                    updateBulkPreview();
                });
            });
            
            // Bulk form submission validation
            document.getElementById('bulkAssignForm').addEventListener('submit', function(e) {
                const selected = document.querySelectorAll('.user-checkbox:checked').length;
                const level = bulkLevelType.value;
                
                if (selected === 0) {
                    e.preventDefault();
                    alert('Error: Please select at least one user.');
                    return;
                }
                
                if (!level) {
                    e.preventDefault();
                    alert('Error: Please select an assignment level.');
                    return;
                }
                
                let targetSelected = false;
                switch(level) {
                    case 'project':
                        targetSelected = document.getElementById('bulkProject')?.value ? true : false;
                        break;
                    case 'phase':
                        targetSelected = document.getElementById('bulkPhase')?.value ? true : false;
                        break;
                    case 'activity':
                        targetSelected = document.getElementById('bulkActivity')?.value ? true : false;
                        break;
                    case 'sub_activity':
                        targetSelected = document.getElementById('bulkSubActivity')?.value ? true : false;
                        break;
                }
                
                if (!targetSelected) {
                    e.preventDefault();
                    alert('Error: Please select a target for assignment.');
                    return;
                }
                
                // Check for Super Admin assigning non-project to PM Manager
                if ('<?= $user_role ?>' === 'super_admin' && level !== 'project') {
                    let hasPmManager = false;
                    document.querySelectorAll('.user-checkbox:checked').forEach(checkbox => {
                        const userItem = checkbox.closest('.user-check-item');
                        const roleBadge = userItem.querySelector('.badge');
                        if (roleBadge && roleBadge.classList.contains('pm-manager-badge')) {
                            hasPmManager = true;
                        }
                    });
                    
                    if (hasPmManager) {
                        e.preventDefault();
                        alert('Error: Super Admin can only assign PROJECTS to PM Managers, not phases, activities, or sub-activities!');
                        return;
                    }
                }
                
                if (!confirm(`Are you sure you want to assign ${selected} user(s)?\n\nUsers already assigned will be skipped.\nUsers not in the project (for phase/activity assignments) will also be skipped.`)) {
                    e.preventDefault();
                }
            });
            
            // Add event listeners for bulk assignment fields
            document.getElementById('bulkProject')?.addEventListener('change', updateBulkPreview);
            document.getElementById('bulkPhase')?.addEventListener('change', updateBulkPreview);
            document.getElementById('bulkActivity')?.addEventListener('change', updateBulkPreview);
            document.getElementById('bulkSubActivity')?.addEventListener('change', updateBulkPreview);
            
            // Custom confirmation for unassignment with cascade rules
            window.confirmUnassign = function(username, level, target, userRole) {
                let message = `Are you sure you want to unassign user "${username}" (${userRole}) from ${level} "${target}"?\n\n`;
                
                // Check if PM Manager is trying to unassign Super Admin
                if ('<?= $user_role ?>' === 'pm_manager' && userRole === 'super_admin') {
                    alert('Error: PM Managers cannot unassign Super Admin!');
                    return false;
                }
                
                switch(level) {
                    case 'project':
                        message += "⚠️ **CASCADE EFFECT**: This will also remove the user from ALL related phases, activities, and sub-activities under this project!";
                        // Special message for Super Admin unassigning project from PM Manager
                        if ('<?= $user_role ?>' === 'super_admin' && userRole === 'pm_manager') {
                            message += "\n\n⚠️ **ADDITIONAL CASCADE**: All PM Employees assigned to this project will also be unassigned and notified!";
                        }
                        break;
                    case 'phase':
                        message += "Note: Only the phase assignment will be removed. Activity and sub-activity assignments will remain unchanged.";
                        break;
                    case 'activity':
                        message += "⚠️ **CASCADE EFFECT**: This will also remove the user from ALL related sub-activities under this activity!";
                        break;
                    case 'sub_activity':
                        message += "Note: Only the sub-activity assignment will be removed. Higher-level assignments (activity, phase, project) will remain unchanged.";
                        break;
                }
                
                return confirm(message);
            };
            
            // Quick Actions functionality
            const quickActionsBtn = document.getElementById('quickActionsBtn');
            const quickActionsModal = new bootstrap.Modal(document.getElementById('quickActionsModal'));
            
            // Show quick actions button after 2 seconds
            setTimeout(() => {
                quickActionsBtn.style.display = 'flex';
            }, 2000);
            
            // Quick actions button click
            quickActionsBtn.addEventListener('click', function() {
                quickActionsModal.show();
            });
            
            // Tab switching functions for quick actions
            window.switchToBulkTab = function() {
                const bulkTab = document.querySelector('#bulk-tab');
                if (bulkTab) {
                    new bootstrap.Tab(bulkTab).show();
                }
            };
            
            window.switchToAssignmentsTab = function() {
                const assignmentsTab = document.querySelector('#assignments-tab');
                if (assignmentsTab) {
                    new bootstrap.Tab(assignmentsTab).show();
                }
            };
            
            window.switchToHierarchyTab = function() {
                const hierarchyTab = document.querySelector('#hierarchy-tab');
                if (hierarchyTab) {
                    new bootstrap.Tab(hierarchyTab).show();
                }
            };
            
            // Auto-close alerts after 8 seconds
            setTimeout(() => {
                const alerts = document.querySelectorAll('.alert');
                alerts.forEach(alert => {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                });
            }, 8000);
            
            // Initialize tab functionality
            const tabTriggerList = [].slice.call(document.querySelectorAll('button[data-bs-toggle="tab"]'));
            tabTriggerList.map(function (tabTriggerEl) {
                return new bootstrap.Tab(tabTriggerEl);
            });
            
            // Preserve tab state on page reload
            const activeTab = localStorage.getItem('activeTab');
            if (activeTab) {
                const tab = document.querySelector(`[data-bs-target="${activeTab}"]`);
                if (tab) {
                    new bootstrap.Tab(tab).show();
                }
            }
            
            // Store active tab
            document.querySelectorAll('button[data-bs-toggle="tab"]').forEach(tab => {
                tab.addEventListener('shown.bs.tab', function (e) {
                    localStorage.setItem('activeTab', e.target.getAttribute('data-bs-target'));
                });
            });
            
            // Show alert if message exists
            <?php if ($message): ?>
            setTimeout(() => {
                const alertElement = document.querySelector('.alert');
                if (alertElement) {
                    const alert = new bootstrap.Alert(alertElement);
                    setTimeout(() => alert.close(), 8000);
                }
            }, 100);
            <?php endif; ?>
        });
    </script>
</body>
</html>