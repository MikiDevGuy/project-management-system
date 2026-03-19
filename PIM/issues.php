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

// Function to check if project is active
function isProjectActive($conn, $project_id) {
    $stmt = $conn->prepare("SELECT status FROM projects WHERE id = ?");
    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        return $row['status'] !== 'terminated';
    }
    return false;
}

// Helper function for file size
function formatFileSize($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } elseif ($bytes > 1) {
        return $bytes . ' bytes';
    } elseif ($bytes == 1) {
        return '1 byte';
    } else {
        return '0 bytes';
    }
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

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];
    
    // CREATE ISSUE
    if ($_POST['action'] === 'create_issue') {
        $project_id = intval($_POST['project_id']);
        $title = trim($_POST['title']);
        $description = trim($_POST['description'] ?? '');
        $summary = trim($_POST['summary'] ?? '');
        $priority = $_POST['priority'];
        $type = $_POST['type'];
        $labels = trim($_POST['labels'] ?? '');
        
        // Validation
        if (empty($project_id)) {
            $response['message'] = 'Please select a project';
            echo json_encode($response);
            exit();
        }
        
        if (empty($title)) {
            $response['message'] = 'Please enter a title';
            echo json_encode($response);
            exit();
        }
        
        if (empty($priority)) {
            $response['message'] = 'Please select a priority';
            echo json_encode($response);
            exit();
        }
        
        if (empty($type)) {
            $response['message'] = 'Please select a type';
            echo json_encode($response);
            exit();
        }
        
        // Check if project is active
        if (!isProjectActive($conn, $project_id)) {
            $response['message'] = 'Cannot create issues for terminated projects';
            echo json_encode($response);
            exit();
        }
        
        // Check if user can create issue for this project
        if (!hasRole('super_admin') && !hasRole('pm_manager')) {
            if (!isUserAssignedToProject($conn, $user_id, $project_id)) {
                $response['message'] = 'You are not assigned to this project';
                echo json_encode($response);
                exit();
            }
        }
        
        // Determine approval status based on role
        $approval_status = 'pending_approval';
        if (hasRole('super_admin')) {
            $approval_status = 'approved';
        }
        
        // Get project status and name at creation
        $stmt = $conn->prepare("SELECT status, name FROM projects WHERE id = ?");
        $stmt->bind_param("i", $project_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $project_data = $result->fetch_assoc();
        $project_status = $project_data['status'];
        $project_name = $project_data['name'];
        $stmt->close();
        
        // Insert issue
        $stmt = $conn->prepare("
            INSERT INTO issues (
                project_id, title, description, summary, priority, type, 
                status, approval_status, labels, 
                created_by, project_status_at_creation, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, 'open', ?, ?, ?, ?, NOW(), NOW())
        ");
        
        if (!$stmt) {
            error_log("Prepare failed: " . $conn->error);
            $response['message'] = 'Database error: ' . $conn->error;
            echo json_encode($response);
            exit();
        }
        
        $stmt->bind_param(
            "issssssssi",
            $project_id, $title, $description, $summary, $priority, $type,
            $approval_status, $labels,
            $user_id, $project_status
        );
        
        if ($stmt->execute()) {
            $issue_id = $stmt->insert_id;
            error_log("Issue created successfully with ID: $issue_id");
            
            // Log activity
            $action = "Issue Created";
            $description_log = "Issue #$issue_id created";
            $stmt_log = $conn->prepare("
                INSERT INTO activity_logs (user_id, action, description, entity_type, entity_id, created_at) 
                VALUES (?, ?, ?, 'issue', ?, NOW())
            ");
            $stmt_log->bind_param("issi", $user_id, $action, $description_log, $issue_id);
            $stmt_log->execute();
            $stmt_log->close();
            
            // Create notifications based on role
            if (!hasRole('super_admin')) {
                // Notify PM Managers and Super Admin for approval
                $notify_sql = "
                    SELECT id FROM users 
                    WHERE system_role IN ('pm_manager', 'super_admin')
                ";
                $notify_result = $conn->query($notify_sql);
                
                while ($notify_user = $notify_result->fetch_assoc()) {
                    $notify_stmt = $conn->prepare("
                        INSERT INTO notifications (
                            user_id, title, message, type, related_module, related_id, related_user_id, created_at
                        ) VALUES (?, 'Issue Pending Approval', ?, 'warning', 'issue', ?, ?, NOW())
                    ");
                    
                    $message = "Issue '$title' created for project '$project_name' by " . $_SESSION['username'] . " requires approval";
                    $notify_stmt->bind_param("isii", $notify_user['id'], $message, $issue_id, $user_id);
                    $notify_stmt->execute();
                    $notify_stmt->close();
                }
            }
            
            $response['success'] = true;
            $response['message'] = 'Issue created successfully';
            $response['issue_id'] = $issue_id;
        } else {
            error_log("Execute failed: " . $stmt->error);
            $response['message'] = 'Error creating issue: ' . $stmt->error;
        }
        $stmt->close();
        echo json_encode($response);
        exit();
    }
    
    // EDIT ISSUE
    if ($_POST['action'] === 'edit_issue') {
        $issue_id = intval($_POST['issue_id']);
        $title = trim($_POST['title']);
        $description = trim($_POST['description'] ?? '');
        $summary = trim($_POST['summary'] ?? '');
        $priority = $_POST['priority'];
        $type = $_POST['type'];
        $labels = trim($_POST['labels'] ?? '');
        
        // Validation
        if (empty($title) || empty($priority) || empty($type)) {
            $response['message'] = 'Please fill all required fields';
            echo json_encode($response);
            exit();
        }
        
        // Check if user can edit this issue
        $stmt = $conn->prepare("SELECT created_by, approval_status, project_id FROM issues WHERE id = ?");
        $stmt->bind_param("i", $issue_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $issue = $result->fetch_assoc();
        $stmt->close();
        
        if (!$issue) {
            $response['message'] = 'Issue not found';
            echo json_encode($response);
            exit();
        }
        
        $can_edit = hasRole('super_admin') || ($issue['created_by'] == $user_id && $issue['approval_status'] === 'pending_approval');
        
        if (!$can_edit) {
            $response['message'] = 'You do not have permission to edit this issue';
            echo json_encode($response);
            exit();
        }
        
        // Check if project is still active
        if (!isProjectActive($conn, $issue['project_id'])) {
            $response['message'] = 'Cannot edit issues for terminated projects';
            echo json_encode($response);
            exit();
        }
        
        // Update issue
        $update_stmt = $conn->prepare("
            UPDATE issues 
            SET title = ?, description = ?, summary = ?, priority = ?, type = ?, 
                labels = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $update_stmt->bind_param(
            "ssssssi",
            $title, $description, $summary, $priority, $type,
            $labels, $issue_id
        );
        
        if ($update_stmt->execute()) {
            // Log activity
            $action = "Issue Updated";
            $description_log = "Issue #$issue_id updated";
            $stmt_log = $conn->prepare("
                INSERT INTO activity_logs (user_id, action, description, entity_type, entity_id, created_at) 
                VALUES (?, ?, ?, 'issue', ?, NOW())
            ");
            $stmt_log->bind_param("issi", $user_id, $action, $description_log, $issue_id);
            $stmt_log->execute();
            $stmt_log->close();
            
            $response['success'] = true;
            $response['message'] = 'Issue updated successfully';
        } else {
            $response['message'] = 'Error updating issue: ' . $conn->error;
        }
        $update_stmt->close();
        echo json_encode($response);
        exit();
    }
    
    // APPROVE ISSUE
    if ($_POST['action'] === 'approve_issue') {
        $issue_id = intval($_POST['issue_id']);
        
        // Check if user can approve
        if (!hasRole('pm_manager') && !hasRole('super_admin')) {
            $response['message'] = 'You do not have permission to approve issues';
            echo json_encode($response);
            exit();
        }
        
        // Get issue details
        $stmt = $conn->prepare("
            SELECT i.*, p.name as project_name, u.username as creator_name 
            FROM issues i
            LEFT JOIN projects p ON i.project_id = p.id
            LEFT JOIN users u ON i.created_by = u.id
            WHERE i.id = ?
        ");
        $stmt->bind_param("i", $issue_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $issue = $result->fetch_assoc();
        $stmt->close();
        
        if (!$issue) {
            $response['message'] = 'Issue not found';
            echo json_encode($response);
            exit();
        }
        
        // Special rule: PM Manager cannot approve their own issues
        if (hasRole('pm_manager') && !hasRole('super_admin') && $issue['created_by'] == $user_id) {
            $response['message'] = 'PM Managers cannot approve their own issues';
            echo json_encode($response);
            exit();
        }
        
        // Approve the issue
        $update_stmt = $conn->prepare("
            UPDATE issues 
            SET approval_status = 'approved', approved_by = ?, approved_at = NOW(), updated_at = NOW()
            WHERE id = ?
        ");
        $update_stmt->bind_param("ii", $user_id, $issue_id);
        
        if ($update_stmt->execute()) {
            // Log activity
            $action = "Issue Approved";
            $description_log = "Issue #$issue_id approved";
            $stmt_log = $conn->prepare("
                INSERT INTO activity_logs (user_id, action, description, entity_type, entity_id, created_at) 
                VALUES (?, ?, ?, 'issue', ?, NOW())
            ");
            $stmt_log->bind_param("issi", $user_id, $action, $description_log, $issue_id);
            $stmt_log->execute();
            $stmt_log->close();
            
            // Notify creator
            if ($issue['created_by']) {
                $notify_stmt = $conn->prepare("
                    INSERT INTO notifications (
                        user_id, title, message, type, related_module, related_id, related_user_id, created_at
                    ) VALUES (?, 'Issue Approved', ?, 'success', 'issue', ?, ?, NOW())
                ");
                
                $message = "Issue #$issue_id: '{$issue['title']}' has been approved";
                $notify_stmt->bind_param("isii", $issue['created_by'], $message, $issue_id, $user_id);
                $notify_stmt->execute();
                $notify_stmt->close();
            }
            
            $response['success'] = true;
            $response['message'] = 'Issue approved successfully';
        } else {
            $response['message'] = 'Error approving issue: ' . $conn->error;
        }
        $update_stmt->close();
        echo json_encode($response);
        exit();
    }
    
    // REJECT ISSUE
    if ($_POST['action'] === 'reject_issue') {
        $issue_id = intval($_POST['issue_id']);
        $rejection_reason = trim($_POST['rejection_reason'] ?? '');
        
        // Check if user can reject
        if (!hasRole('pm_manager') && !hasRole('super_admin')) {
            $response['message'] = 'You do not have permission to reject issues';
            echo json_encode($response);
            exit();
        }
        
        if (empty($rejection_reason)) {
            $response['message'] = 'Rejection reason is required';
            echo json_encode($response);
            exit();
        }
        
        // Get issue details
        $stmt = $conn->prepare("
            SELECT i.*, p.name as project_name, u.username as creator_name 
            FROM issues i
            LEFT JOIN projects p ON i.project_id = p.id
            LEFT JOIN users u ON i.created_by = u.id
            WHERE i.id = ?
        ");
        $stmt->bind_param("i", $issue_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $issue = $result->fetch_assoc();
        $stmt->close();
        
        if (!$issue) {
            $response['message'] = 'Issue not found';
            echo json_encode($response);
            exit();
        }
        
        // Reject the issue
        $update_stmt = $conn->prepare("
            UPDATE issues 
            SET approval_status = 'rejected', rejection_reason = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $update_stmt->bind_param("si", $rejection_reason, $issue_id);
        
        if ($update_stmt->execute()) {
            // Log activity
            $action = "Issue Rejected";
            $description_log = "Issue #$issue_id rejected. Reason: $rejection_reason";
            $stmt_log = $conn->prepare("
                INSERT INTO activity_logs (user_id, action, description, entity_type, entity_id, created_at) 
                VALUES (?, ?, ?, 'issue', ?, NOW())
            ");
            $stmt_log->bind_param("issi", $user_id, $action, $description_log, $issue_id);
            $stmt_log->execute();
            $stmt_log->close();
            
            // Notify creator
            if ($issue['created_by']) {
                $notify_stmt = $conn->prepare("
                    INSERT INTO notifications (
                        user_id, title, message, type, related_module, related_id, related_user_id, created_at
                    ) VALUES (?, 'Issue Rejected', ?, 'danger', 'issue', ?, ?, NOW())
                ");
                
                $message = "Issue #$issue_id: '{$issue['title']}' has been rejected. Reason: $rejection_reason";
                $notify_stmt->bind_param("isii", $issue['created_by'], $message, $issue_id, $user_id);
                $notify_stmt->execute();
                $notify_stmt->close();
            }
            
            $response['success'] = true;
            $response['message'] = 'Issue rejected successfully';
        } else {
            $response['message'] = 'Error rejecting issue: ' . $conn->error;
        }
        $update_stmt->close();
        echo json_encode($response);
        exit();
    }
    
    // ASSIGN ISSUE
    if ($_POST['action'] === 'assign_issue') {
        $issue_id = intval($_POST['issue_id']);
        $assigned_to = !empty($_POST['assigned_to']) ? intval($_POST['assigned_to']) : null;
        
        // Check if user can assign (only PM Manager and Super Admin)
        if (!hasRole('pm_manager') && !hasRole('super_admin')) {
            $response['message'] = 'You do not have permission to assign issues';
            echo json_encode($response);
            exit();
        }
        
        // Get issue details
        $stmt = $conn->prepare("
            SELECT i.*, p.name as project_name, u.username as creator_name
            FROM issues i
            LEFT JOIN projects p ON i.project_id = p.id
            LEFT JOIN users u ON i.created_by = u.id
            WHERE i.id = ?
        ");
        $stmt->bind_param("i", $issue_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $issue = $result->fetch_assoc();
        $stmt->close();
        
        if (!$issue) {
            $response['message'] = 'Issue not found';
            echo json_encode($response);
            exit();
        }
        
        // Check if issue is approved
        if ($issue['approval_status'] !== 'approved') {
            $response['message'] = 'Only approved issues can be assigned';
            echo json_encode($response);
            exit();
        }
        
        // If assigning to someone, check if they are assigned to the project
        if ($assigned_to) {
            if (!isUserAssignedToProject($conn, $assigned_to, $issue['project_id'])) {
                $response['message'] = 'Selected user is not assigned to this project';
                echo json_encode($response);
                exit();
            }
        }
        
        // Update assignment
        $update_stmt = $conn->prepare("
            UPDATE issues 
            SET assigned_to = ?, status = 'assigned', updated_at = NOW()
            WHERE id = ?
        ");
        $update_stmt->bind_param("ii", $assigned_to, $issue_id);
        
        if ($update_stmt->execute()) {
            // Log activity
            $assignee_name = 'Unassigned';
            if ($assigned_to) {
                $name_stmt = $conn->prepare("SELECT username FROM users WHERE id = ?");
                $name_stmt->bind_param("i", $assigned_to);
                $name_stmt->execute();
                $name_result = $name_stmt->get_result();
                if ($name_row = $name_result->fetch_assoc()) {
                    $assignee_name = $name_row['username'];
                }
                $name_stmt->close();
            }
            
            $action = "Issue Assigned";
            $description_log = "Issue #$issue_id assigned to " . ($assigned_to ? $assignee_name : 'Unassigned');
            $stmt_log = $conn->prepare("
                INSERT INTO activity_logs (user_id, action, description, entity_type, entity_id, created_at) 
                VALUES (?, ?, ?, 'issue', ?, NOW())
            ");
            $stmt_log->bind_param("issi", $user_id, $action, $description_log, $issue_id);
            $stmt_log->execute();
            $stmt_log->close();
            
            // Notify assigned user
            if ($assigned_to) {
                $notify_stmt = $conn->prepare("
                    INSERT INTO notifications (
                        user_id, title, message, type, related_module, related_id, related_user_id, created_at
                    ) VALUES (?, 'Issue Assigned to You', ?, 'info', 'issue', ?, ?, NOW())
                ");
                
                $message = "Issue #$issue_id: '{$issue['title']}' has been assigned to you";
                $notify_stmt->bind_param("isii", $assigned_to, $message, $issue_id, $user_id);
                $notify_stmt->execute();
                $notify_stmt->close();
            }
            
            // Notify creator
            if ($issue['created_by'] && $issue['created_by'] != $assigned_to) {
                $notify_stmt = $conn->prepare("
                    INSERT INTO notifications (
                        user_id, title, message, type, related_module, related_id, related_user_id, created_at
                    ) VALUES (?, 'Issue Assigned', ?, 'info', 'issue', ?, ?, NOW())
                ");
                
                $message = "Issue #$issue_id: '{$issue['title']}' has been assigned to " . ($assigned_to ? $assignee_name : 'Unassigned');
                $notify_stmt->bind_param("isii", $issue['created_by'], $message, $issue_id, $user_id);
                $notify_stmt->execute();
                $notify_stmt->close();
            }
            
            $response['success'] = true;
            $response['message'] = 'Issue assigned successfully';
        } else {
            $response['message'] = 'Error assigning issue: ' . $conn->error;
        }
        $update_stmt->close();
        echo json_encode($response);
        exit();
    }
    
    // UPDATE STATUS - COMPLETELY FIXED
    if ($_POST['action'] === 'update_status') {
        $issue_id = intval($_POST['issue_id']);
        $new_status = $_POST['status'];
        $status_comment = trim($_POST['status_comment'] ?? '');
        
        // Debug log
        error_log("=== STATUS UPDATE REQUEST ===");
        error_log("Issue ID: $issue_id");
        error_log("New Status: $new_status");
        error_log("Comment: $status_comment");
        error_log("User ID: $user_id");
        error_log("User Role: $user_role");
        
        // Validate input
        if (empty($issue_id) || $issue_id <= 0) {
            error_log("ERROR: Invalid issue ID");
            $response['message'] = 'Invalid issue ID';
            echo json_encode($response);
            exit();
        }
        
        if (empty($new_status)) {
            error_log("ERROR: Status is empty");
            $response['message'] = 'Status is required';
            echo json_encode($response);
            exit();
        }
        
        // Get issue details - FIXED: Use correct column names
        $stmt = $conn->prepare("
            SELECT i.*, p.name as project_name, u.username as creator_name, a.username as assigned_name
            FROM issues i
            LEFT JOIN projects p ON i.project_id = p.id
            LEFT JOIN users u ON i.created_by = u.id
            LEFT JOIN users a ON i.assigned_to = a.id
            WHERE i.id = ?
        ");
        
        if (!$stmt) {
            error_log("Prepare failed: " . $conn->error);
            $response['message'] = 'Database error: ' . $conn->error;
            echo json_encode($response);
            exit();
        }
        
        $stmt->bind_param("i", $issue_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $issue = $result->fetch_assoc();
        $stmt->close();
        
        if (!$issue) {
            error_log("ERROR: Issue not found with ID: $issue_id");
            $response['message'] = 'Issue not found';
            echo json_encode($response);
            exit();
        }
        
        error_log("Issue found - Current Status: " . $issue['status']);
        error_log("Issue Approval Status: " . $issue['approval_status']);
        error_log("Issue Assigned To: " . ($issue['assigned_to'] ?? 'NULL'));
        error_log("Issue Created By: " . $issue['created_by']);
        
        // Check permissions
        $can_update = false;
        
        // Super Admin can update any status
        if (hasRole('super_admin')) {
            $can_update = true;
            error_log("Permission granted: Super Admin");
        }
        // PM Manager can update any status
        elseif (hasRole('pm_manager')) {
            $can_update = true;
            error_log("Permission granted: PM Manager");
        }
        // PM Employee can only update if they are assigned to the issue
        elseif (hasRole('pm_employee') && $issue['assigned_to'] == $user_id) {
            $can_update = true;
            error_log("Permission granted: Assigned PM Employee");
        }
        
        if (!$can_update) {
            error_log("ERROR: No permission to update status");
            $response['message'] = 'You do not have permission to update this issue status';
            echo json_encode($response);
            exit();
        }
        
        // Check if issue is approved (only approved issues can have status updates)
        if ($issue['approval_status'] !== 'approved') {
            error_log("ERROR: Issue not approved. Current approval status: " . $issue['approval_status']);
            $response['message'] = 'Only approved issues can have status updates';
            echo json_encode($response);
            exit();
        }
        
        // Validate status workflow
        $old_status = $issue['status'];
        
        // Define valid transitions
        $valid_transitions = [
            'assigned' => ['in_progress'],
            'in_progress' => ['resolved'],
            'resolved' => ['closed']
        ];
        
        // Allow updates if status is the same (no change) or if it's a valid transition
        if ($old_status !== $new_status) {
            if (!isset($valid_transitions[$old_status])) {
                error_log("ERROR: Invalid current status for transition: $old_status");
                $response['message'] = 'Current status does not allow any transitions';
                echo json_encode($response);
                exit();
            }
            
            if (!in_array($new_status, $valid_transitions[$old_status])) {
                error_log("ERROR: Invalid transition from $old_status to $new_status");
                $response['message'] = 'Invalid status transition from ' . $old_status . ' to ' . $new_status;
                echo json_encode($response);
                exit();
            }
        }
        
        error_log("Status transition validated: $old_status -> $new_status");
        
        // Update status - FIXED: Use correct column name
        $update_stmt = $conn->prepare("
            UPDATE issues 
            SET status = ?, updated_at = NOW()
            WHERE id = ?
        ");
        
        if (!$update_stmt) {
            error_log("Update prepare failed: " . $conn->error);
            $response['message'] = 'Database error: ' . $conn->error;
            echo json_encode($response);
            exit();
        }
        
        $update_stmt->bind_param("si", $new_status, $issue_id);
        
        if ($update_stmt->execute()) {
            error_log("Status updated successfully in database");
            
            // Add comment if provided
            if (!empty($status_comment)) {
                $comment_text = "Status changed to " . ucfirst(str_replace('_', ' ', $new_status)) . ": " . $status_comment;
                $stmt_comment = $conn->prepare("
                    INSERT INTO comments (issue_id, user_id, comment, created_at) 
                    VALUES (?, ?, ?, NOW())
                ");
                
                if ($stmt_comment) {
                    $stmt_comment->bind_param("iis", $issue_id, $user_id, $comment_text);
                    $stmt_comment->execute();
                    $stmt_comment->close();
                    error_log("Comment added for status change");
                }
            }
            
            // Log activity
            $action = "Status Updated";
            $description_log = "Issue #$issue_id status changed from $old_status to $new_status";
            $stmt_log = $conn->prepare("
                INSERT INTO activity_logs (user_id, action, description, entity_type, entity_id, created_at) 
                VALUES (?, ?, ?, 'issue', ?, NOW())
            ");
            
            if ($stmt_log) {
                $stmt_log->bind_param("issi", $user_id, $action, $description_log, $issue_id);
                $stmt_log->execute();
                $stmt_log->close();
                error_log("Activity log created");
            }
            
            // Notify relevant users
            $notify_users = [];
            if ($issue['created_by']) $notify_users[] = $issue['created_by'];
            if ($issue['assigned_to'] && $issue['assigned_to'] != $user_id) $notify_users[] = $issue['assigned_to'];
            $notify_users = array_unique($notify_users);
            
            foreach ($notify_users as $notify_user_id) {
                if ($notify_user_id == $user_id) continue;
                
                $notify_stmt = $conn->prepare("
                    INSERT INTO notifications (
                        user_id, title, message, type, related_module, related_id, related_user_id, created_at,
                        metadata
                    ) VALUES (?, 'Issue Status Updated', ?, 'info', 'issue', ?, ?, NOW(), ?)
                ");
                
                if ($notify_stmt) {
                    $message = "Issue #$issue_id: '{$issue['title']}' status changed from " . 
                              ucfirst(str_replace('_', ' ', $old_status)) . " to " . 
                              ucfirst(str_replace('_', ' ', $new_status));
                    
                    $metadata = json_encode([
                        'old_status' => $old_status,
                        'new_status' => $new_status,
                        'changed_by' => $_SESSION['username'],
                        'changed_at' => date('Y-m-d H:i:s')
                    ]);
                    
                    $notify_stmt->bind_param("isiiis", $notify_user_id, $message, $issue_id, $user_id, $metadata);
                    $notify_stmt->execute();
                    $notify_stmt->close();
                }
            }
            
            error_log("Notifications sent to " . count($notify_users) . " users");
            
            $response['success'] = true;
            $response['message'] = 'Status updated successfully';
        } else {
            error_log("ERROR: Update execution failed: " . $update_stmt->error);
            $response['message'] = 'Error updating status: ' . $update_stmt->error;
        }
        $update_stmt->close();
        
        error_log("=== STATUS UPDATE COMPLETE === Success: " . ($response['success'] ? 'Yes' : 'No'));
        echo json_encode($response);
        exit();
    }
    
    // DELETE ISSUE
    if ($_POST['action'] === 'delete_issue') {
        // Check if user is super admin
        if (!hasRole('super_admin')) {
            $response['message'] = 'Only Super Admin can delete issues';
            echo json_encode($response);
            exit();
        }
        
        $issue_id = intval($_POST['issue_id']);
        
        // Get issue details for logging
        $stmt = $conn->prepare("SELECT title FROM issues WHERE id = ?");
        $stmt->bind_param("i", $issue_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $issue = $result->fetch_assoc();
        $stmt->close();
        
        if (!$issue) {
            $response['message'] = 'Issue not found';
            echo json_encode($response);
            exit();
        }
        
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Delete related records
            $delete_comments = $conn->prepare("DELETE FROM comments WHERE issue_id = ?");
            $delete_comments->bind_param("i", $issue_id);
            $delete_comments->execute();
            $delete_comments->close();
            
            $delete_attachments = $conn->prepare("DELETE FROM attachments WHERE issue_id = ?");
            $delete_attachments->bind_param("i", $issue_id);
            $delete_attachments->execute();
            $delete_attachments->close();
            
            $delete_logs = $conn->prepare("DELETE FROM activity_logs WHERE entity_type = 'issue' AND entity_id = ?");
            $delete_logs->bind_param("i", $issue_id);
            $delete_logs->execute();
            $delete_logs->close();
            
            $delete_notifications = $conn->prepare("DELETE FROM notifications WHERE related_module = 'issue' AND related_id = ?");
            $delete_notifications->bind_param("i", $issue_id);
            $delete_notifications->execute();
            $delete_notifications->close();
            
            $delete_issue = $conn->prepare("DELETE FROM issues WHERE id = ?");
            $delete_issue->bind_param("i", $issue_id);
            $delete_issue->execute();
            $delete_issue->close();
            
            $conn->commit();
            
            $response['success'] = true;
            $response['message'] = 'Issue deleted successfully';
            
        } catch (Exception $e) {
            $conn->rollback();
            error_log("Delete transaction failed: " . $e->getMessage());
            $response['message'] = 'Error deleting issue: ' . $e->getMessage();
        }
        
        echo json_encode($response);
        exit();
    }
    
    // ADD COMMENT
    if ($_POST['action'] === 'add_comment') {
        $issue_id = intval($_POST['issue_id']);
        $comment = trim($_POST['comment']);
        
        error_log("Adding comment - Issue ID: $issue_id, Comment: $comment");
        
        if (empty($comment)) {
            $response['message'] = 'Comment cannot be empty';
            echo json_encode($response);
            exit();
        }
        
        // Insert comment
        $stmt = $conn->prepare("
            INSERT INTO comments (issue_id, user_id, comment, created_at) 
            VALUES (?, ?, ?, NOW())
        ");
        
        if (!$stmt) {
            error_log("Comment prepare failed: " . $conn->error);
            $response['message'] = 'Database error: ' . $conn->error;
            echo json_encode($response);
            exit();
        }
        
        $stmt->bind_param("iis", $issue_id, $user_id, $comment);
        
        if ($stmt->execute()) {
            $comment_id = $stmt->insert_id;
            error_log("Comment added successfully with ID: $comment_id");
            
            // Log activity
            $action = "Comment Added";
            $description_log = "User added a comment to issue #$issue_id";
            $stmt_log = $conn->prepare("
                INSERT INTO activity_logs (user_id, action, description, entity_type, entity_id, created_at) 
                VALUES (?, ?, ?, 'issue', ?, NOW())
            ");
            $stmt_log->bind_param("issi", $user_id, $action, $description_log, $issue_id);
            $stmt_log->execute();
            $stmt_log->close();
            
            // Get username for response
            $user_stmt = $conn->prepare("SELECT username FROM users WHERE id = ?");
            $user_stmt->bind_param("i", $user_id);
            $user_stmt->execute();
            $user_result = $user_stmt->get_result();
            $username = $user_result->fetch_assoc()['username'];
            $user_stmt->close();
            
            // Get comment data for response
            $comment_data = [
                'id' => $comment_id,
                'comment' => $comment,
                'username' => $username,
                'created_at' => date('Y-m-d H:i:s'),
                'formatted_date' => date('M j, Y g:i A')
            ];
            
            $response['success'] = true;
            $response['message'] = 'Comment added successfully';
            $response['comment'] = $comment_data;
        } else {
            error_log("Comment execute failed: " . $stmt->error);
            $response['message'] = 'Error adding comment: ' . $stmt->error;
        }
        $stmt->close();
        echo json_encode($response);
        exit();
    }
    
    // UPLOAD ATTACHMENT
    if ($_POST['action'] === 'upload_attachment') {
        $issue_id = intval($_POST['issue_id']);
        
        if (!isset($_FILES['attachment']) || $_FILES['attachment']['error'] !== UPLOAD_ERR_OK) {
            $response['message'] = 'No file uploaded or upload error';
            echo json_encode($response);
            exit();
        }
        
        $file = $_FILES['attachment'];
        $filename = $file['name'];
        $file_tmp = $file['tmp_name'];
        $file_size = $file['size'];
        
        // Validate file type
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf', 'application/msword', 
                          'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                          'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                          'text/plain', 'application/zip'];
        
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $file_type = finfo_file($finfo, $file_tmp);
        finfo_close($finfo);
        
        if (!in_array($file_type, $allowed_types)) {
            $response['message'] = 'File type not allowed';
            echo json_encode($response);
            exit();
        }
        
        // Create upload directory if not exists
        $upload_dir = '../uploads/issues/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        // Generate unique filename
        $file_ext = pathinfo($filename, PATHINFO_EXTENSION);
        $new_filename = uniqid() . '_' . time() . '.' . $file_ext;
        $filepath = $upload_dir . $new_filename;
        
        if (move_uploaded_file($file_tmp, $filepath)) {
            // Insert into database
            $db_filepath = 'uploads/issues/' . $new_filename;
            $stmt = $conn->prepare("
                INSERT INTO attachments (issue_id, filename, filepath, uploaded_by, uploaded_at) 
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt->bind_param("issi", $issue_id, $filename, $db_filepath, $user_id);
            
            if ($stmt->execute()) {
                $attachment_id = $stmt->insert_id;
                
                // Log activity
                $action = "Attachment Uploaded";
                $description_log = "File '$filename' uploaded to issue #$issue_id";
                $stmt_log = $conn->prepare("
                    INSERT INTO activity_logs (user_id, action, description, entity_type, entity_id, created_at) 
                    VALUES (?, ?, ?, 'issue', ?, NOW())
                ");
                $stmt_log->bind_param("issi", $user_id, $action, $description_log, $issue_id);
                $stmt_log->execute();
                $stmt_log->close();
                
                // Get username
                $user_stmt = $conn->prepare("SELECT username FROM users WHERE id = ?");
                $user_stmt->bind_param("i", $user_id);
                $user_stmt->execute();
                $user_result = $user_stmt->get_result();
                $username = $user_result->fetch_assoc()['username'];
                $user_stmt->close();
                
                $attachment_data = [
                    'id' => $attachment_id,
                    'filename' => $filename,
                    'filepath' => $db_filepath,
                    'username' => $username,
                    'uploaded_at' => date('Y-m-d H:i:s'),
                    'formatted_date' => date('M j, Y g:i A'),
                    'file_size' => formatFileSize($file_size)
                ];
                
                $response['success'] = true;
                $response['message'] = 'File uploaded successfully';
                $response['attachment'] = $attachment_data;
            } else {
                unlink($filepath);
                $response['message'] = 'Error saving attachment: ' . $conn->error;
            }
            $stmt->close();
        } else {
            $response['message'] = 'Error moving uploaded file';
        }
        
        echo json_encode($response);
        exit();
    }
    
    // DOWNLOAD ATTACHMENT
    if ($_POST['action'] === 'download_attachment') {
        $attachment_id = intval($_POST['attachment_id']);
        
        $stmt = $conn->prepare("SELECT filename, filepath FROM attachments WHERE id = ?");
        $stmt->bind_param("i", $attachment_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $attachment = $result->fetch_assoc();
        $stmt->close();
        
        if (!$attachment) {
            $response['message'] = 'Attachment not found';
            echo json_encode($response);
            exit();
        }
        
        $file_path = $_SERVER['DOCUMENT_ROOT'] . '/' . $attachment['filepath'];
        
        if (!file_exists($file_path)) {
            $response['message'] = 'File not found on server';
            echo json_encode($response);
            exit();
        }
        
        $response['success'] = true;
        $response['filepath'] = $attachment['filepath'];
        $response['filename'] = $attachment['filename'];
        echo json_encode($response);
        exit();
    }
    
    // GET ISSUE DETAILS FOR MODAL
    if ($_POST['action'] === 'get_issue_details') {
        $issue_id = intval($_POST['issue_id']);
        
        // Get issue details
        $stmt = $conn->prepare("
            SELECT i.*, p.name as project_name, p.status as project_status,
                   u.username as assigned_username, u.email as assigned_email,
                   creator.username as creator_username, creator.email as creator_email,
                   approver.username as approver_username
            FROM issues i 
            LEFT JOIN projects p ON i.project_id = p.id 
            LEFT JOIN users u ON i.assigned_to = u.id 
            LEFT JOIN users creator ON i.created_by = creator.id 
            LEFT JOIN users approver ON i.approved_by = approver.id
            WHERE i.id = ?
        ");
        $stmt->bind_param("i", $issue_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $issue = $result->fetch_assoc();
        $stmt->close();
        
        if (!$issue) {
            $response['message'] = 'Issue not found';
            echo json_encode($response);
            exit();
        }
        
        // Check if user has access to this issue
        $has_access = false;
        if (hasRole('super_admin') || hasRole('pm_manager')) {
            $has_access = true;
        } elseif ($issue['assigned_to'] == $user_id || $issue['created_by'] == $user_id) {
            $has_access = true;
        } elseif (hasRole('pm_employee') && isUserAssignedToProject($conn, $user_id, $issue['project_id'])) {
            $has_access = true;
        }
        
        if (!$has_access) {
            $response['message'] = 'You do not have access to this issue';
            echo json_encode($response);
            exit();
        }
        
        // Get comments
        $comments = [];
        $stmt = $conn->prepare("
            SELECT c.*, u.username, u.email
            FROM comments c 
            LEFT JOIN users u ON c.user_id = u.id 
            WHERE c.issue_id = ? 
            ORDER BY c.created_at ASC
        ");
        $stmt->bind_param("i", $issue_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $row['formatted_date'] = date('M j, Y g:i A', strtotime($row['created_at']));
            $comments[] = $row;
        }
        $stmt->close();
        
        // Get attachments
        $attachments = [];
        $stmt = $conn->prepare("
            SELECT a.*, u.username
            FROM attachments a 
            LEFT JOIN users u ON a.uploaded_by = u.id 
            WHERE a.issue_id = ? 
            ORDER BY a.uploaded_at DESC
        ");
        $stmt->bind_param("i", $issue_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $row['formatted_date'] = date('M j, Y g:i A', strtotime($row['uploaded_at']));
            $attachments[] = $row;
        }
        $stmt->close();
        
        // Get activity logs
        $activity_logs = [];
        $stmt = $conn->prepare("
            SELECT al.*, u.username 
            FROM activity_logs al 
            LEFT JOIN users u ON al.user_id = u.id 
            WHERE al.entity_type = 'issue' AND al.entity_id = ? 
            ORDER BY al.created_at DESC
            LIMIT 50
        ");
        $stmt->bind_param("i", $issue_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $row['formatted_date'] = date('M j, Y g:i A', strtotime($row['created_at']));
            $activity_logs[] = $row;
        }
        $stmt->close();
        
        // Get users for assignment (only those assigned to the project)
        $assignable_users = [];
        if ($issue['project_id']) {
            $stmt = $conn->prepare("
                SELECT u.id, u.username, u.email
                FROM users u
                INNER JOIN user_assignments ua ON u.id = ua.user_id
                WHERE ua.project_id = ? AND ua.is_active = 1
                ORDER BY u.username
            ");
            $stmt->bind_param("i", $issue['project_id']);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $assignable_users[] = $row;
            }
            $stmt->close();
        }
        
        $response['success'] = true;
        $response['issue'] = $issue;
        $response['comments'] = $comments;
        $response['attachments'] = $attachments;
        $response['activity_logs'] = $activity_logs;
        $response['assignable_users'] = $assignable_users;
        $response['user_role'] = $user_role;
        $response['user_id'] = $user_id;
        $response['can_approve'] = (hasRole('pm_manager') || hasRole('super_admin')) && 
                                   !($issue['created_by'] == $user_id && hasRole('pm_manager') && !hasRole('super_admin')) && 
                                   $issue['approval_status'] === 'pending_approval';
        $response['can_assign'] = (hasRole('pm_manager') || hasRole('super_admin')) && 
                                  $issue['approval_status'] === 'approved';
        $response['can_update_status'] = (hasRole('super_admin') || hasRole('pm_manager') || 
                                          ($issue['assigned_to'] == $user_id && hasRole('pm_employee'))) &&
                                          $issue['approval_status'] === 'approved' && 
                                          $issue['status'] !== 'closed';
        $response['can_edit'] = hasRole('super_admin') || 
                               ($issue['created_by'] == $user_id && $issue['approval_status'] === 'pending_approval');
        $response['can_delete'] = hasRole('super_admin');
        
        echo json_encode($response);
        exit();
    }
    
    // GET ISSUE FOR EDIT MODAL
    if ($_POST['action'] === 'get_issue_for_edit') {
        $issue_id = intval($_POST['issue_id']);
        
        // Get issue details
        $stmt = $conn->prepare("
            SELECT i.*, p.name as project_name
            FROM issues i
            LEFT JOIN projects p ON i.project_id = p.id
            WHERE i.id = ?
        ");
        $stmt->bind_param("i", $issue_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $issue = $result->fetch_assoc();
        $stmt->close();
        
        if (!$issue) {
            $response['message'] = 'Issue not found';
            echo json_encode($response);
            exit();
        }
        
        // Check if user can edit
        $can_edit = hasRole('super_admin') || ($issue['created_by'] == $user_id && $issue['approval_status'] === 'pending_approval');
        
        if (!$can_edit) {
            $response['message'] = 'You do not have permission to edit this issue';
            echo json_encode($response);
            exit();
        }
        
        $response['success'] = true;
        $response['issue'] = $issue;
        echo json_encode($response);
        exit();
    }
    
    // MARK NOTIFICATIONS AS READ
    if ($_POST['action'] === 'mark_notifications_read') {
        $notification_id = isset($_POST['notification_id']) ? intval($_POST['notification_id']) : null;
        
        if ($notification_id) {
            $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
            $stmt->bind_param("ii", $notification_id, $user_id);
        } else {
            $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
            $stmt->bind_param("i", $user_id);
        }
        
        if ($stmt->execute()) {
            $response['success'] = true;
            $response['message'] = 'Notifications marked as read';
        } else {
            $response['message'] = 'Error updating notifications';
        }
        $stmt->close();
        echo json_encode($response);
        exit();
    }
}

// Handle filters for main page
$whereClauses = [];
$params = [];
$param_types = '';

// Base query for issues user has access to
if (!hasRole('super_admin') && !hasRole('pm_manager')) {
    $whereClauses[] = "(i.created_by = ? OR i.assigned_to = ? OR i.project_id IN (
        SELECT project_id FROM user_assignments WHERE user_id = ? AND is_active = 1
    ))";
    $params[] = $user_id;
    $params[] = $user_id;
    $params[] = $user_id;
    $param_types .= 'iii';
}

// Apply filters
if (isset($_GET['status']) && !empty($_GET['status'])) {
    $whereClauses[] = "i.status = ?";
    $params[] = $_GET['status'];
    $param_types .= 's';
}

if (isset($_GET['approval_status']) && !empty($_GET['approval_status'])) {
    $whereClauses[] = "i.approval_status = ?";
    $params[] = $_GET['approval_status'];
    $param_types .= 's';
}

if (isset($_GET['priority']) && !empty($_GET['priority'])) {
    $whereClauses[] = "i.priority = ?";
    $params[] = $_GET['priority'];
    $param_types .= 's';
}

if (isset($_GET['project_id']) && !empty($_GET['project_id'])) {
    $whereClauses[] = "i.project_id = ?";
    $params[] = $_GET['project_id'];
    $param_types .= 'i';
}

if (isset($_GET['type']) && !empty($_GET['type'])) {
    $whereClauses[] = "i.type = ?";
    $params[] = $_GET['type'];
    $param_types .= 's';
}

if (isset($_GET['assigned_to']) && !empty($_GET['assigned_to'])) {
    if ($_GET['assigned_to'] === 'unassigned') {
        $whereClauses[] = "i.assigned_to IS NULL";
    } else {
        $whereClauses[] = "i.assigned_to = ?";
        $params[] = $_GET['assigned_to'];
        $param_types .= 'i';
    }
}

if (isset($_GET['search']) && !empty($_GET['search'])) {
    $search = '%' . $_GET['search'] . '%';
    $whereClauses[] = "(i.title LIKE ? OR i.description LIKE ? OR i.summary LIKE ?)";
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
    $param_types .= 'sss';
}

$where = '';
if (!empty($whereClauses)) {
    $where = "WHERE " . implode(" AND ", $whereClauses);
}

// Pagination
$items_per_page = isset($_GET['items_per_page']) ? intval($_GET['items_per_page']) : 10;
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($current_page - 1) * $items_per_page;

// Get total count
$count_sql = "
    SELECT COUNT(*) as total 
    FROM issues i 
    LEFT JOIN projects p ON i.project_id = p.id 
    $where
";

$count_stmt = $conn->prepare($count_sql);
if (!empty($params)) {
    $count_stmt->bind_param($param_types, ...$params);
}
$count_stmt->execute();
$result = $count_stmt->get_result();
$total_items = $result->fetch_assoc()['total'];
$total_pages = ceil($total_items / $items_per_page);
$count_stmt->close();

// Ensure current page is valid
if ($current_page > $total_pages && $total_pages > 0) {
    $current_page = $total_pages;
    $offset = ($current_page - 1) * $items_per_page;
}

// Get issues
$sql = "
    SELECT i.*, p.name as project_name, p.status as project_status,
           u.username as assigned_username,
           creator.username as creator_username
    FROM issues i 
    LEFT JOIN projects p ON i.project_id = p.id 
    LEFT JOIN users u ON i.assigned_to = u.id 
    LEFT JOIN users creator ON i.created_by = creator.id 
    $where 
    ORDER BY 
        CASE i.approval_status 
            WHEN 'pending_approval' THEN 1 
            WHEN 'approved' THEN 2 
            WHEN 'rejected' THEN 3 
        END,
        i.created_at DESC
    LIMIT ? OFFSET ?
";

$stmt = $conn->prepare($sql);
$limit_param = $items_per_page;
$offset_param = $offset;

if (!empty($params)) {
    $stmt->bind_param($param_types . 'ii', ...array_merge($params, [$limit_param, $offset_param]));
} else {
    $stmt->bind_param('ii', $limit_param, $offset_param);
}

$stmt->execute();
$result = $stmt->get_result();
$issues = [];
while ($row = $result->fetch_assoc()) {
    $issues[] = $row;
}
$stmt->close();

// Get projects for dropdown
$projects = [];
$project_query = "
    SELECT DISTINCT p.id, p.name 
    FROM projects p
";
if (!hasRole('super_admin') && !hasRole('pm_manager')) {
    $project_query .= "
        INNER JOIN user_assignments ua ON p.id = ua.project_id
        WHERE ua.user_id = ? AND ua.is_active = 1
    ";
    $stmt = $conn->prepare($project_query . " ORDER BY p.name");
    $stmt->bind_param("i", $user_id);
} else {
    $stmt = $conn->prepare($project_query . " ORDER BY p.name");
}
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $projects[$row['id']] = $row['name'];
}
$stmt->close();

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
    <title>Issues - Dashen Bank Issue Tracker</title>
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
        
        .badge-status {
            font-size: 0.75rem;
            padding: 0.5em 1em;
            border-radius: 20px;
            font-weight: 600;
            letter-spacing: 0.3px;
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
        
        .filter-section .form-select,
        .filter-section .form-control {
            border-radius: var(--border-radius-sm);
            border: 1px solid #e9ecef;
            padding: 10px 15px;
            transition: var(--transition);
        }
        
        .filter-section .form-select:focus,
        .filter-section .form-control:focus {
            border-color: var(--dashen-primary);
            box-shadow: 0 0 0 0.2rem rgba(39, 50, 116, 0.1);
        }
        
        .table-container {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-lg);
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.2);
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
        
        .btn-success {
            background: linear-gradient(135deg, #2dce89, #26af73);
            border: none;
        }
        
        .btn-danger {
            background: linear-gradient(135deg, #f5365c, #d32f4f);
            border: none;
        }
        
        .btn-warning {
            background: linear-gradient(135deg, #fb6340, #e85533);
            border: none;
            color: white;
        }
        
        .pagination .page-item.active .page-link {
            background: linear-gradient(135deg, var(--dashen-primary), var(--dashen-secondary));
            border-color: var(--dashen-primary);
        }
        
        .pagination .page-link {
            color: var(--dashen-primary);
            border-radius: var(--border-radius-sm);
            margin: 0 3px;
            border: 1px solid #e9ecef;
            font-weight: 500;
            transition: var(--transition);
        }
        
        .pagination .page-link:hover {
            background-color: #e9ecef;
            transform: translateY(-1px);
        }
        
        .btn-group .btn {
            border-radius: var(--border-radius-sm);
            margin-right: 5px;
        }
        
        .empty-state {
            padding: 60px 20px;
            text-align: center;
        }
        
        .empty-state i {
            font-size: 4rem;
            color: #6c757d;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        .modal-content {
            border-radius: var(--border-radius);
            border: none;
            box-shadow: var(--shadow-xl);
        }
        
        .modal-header {
            background: linear-gradient(135deg, var(--dashen-primary), var(--dashen-secondary));
            color: white;
            border-radius: var(--border-radius) var(--border-radius) 0 0;
            padding: 20px 25px;
        }
        
        .modal-header .btn-close {
            filter: brightness(0) invert(1);
        }
        
        .modal-header.bg-success {
            background: linear-gradient(135deg, #2dce89, #26af73) !important;
        }
        
        .modal-header.bg-danger {
            background: linear-gradient(135deg, #f5365c, #d32f4f) !important;
        }
        
        .modal-header.bg-primary {
            background: linear-gradient(135deg, var(--dashen-primary), var(--dashen-secondary)) !important;
        }
        
        .modal-header.bg-warning {
            background: linear-gradient(135deg, #fb6340, #e85533) !important;
        }
        
        .modal-body {
            padding: 25px;
        }
        
        .modal-footer {
            padding: 20px 25px;
            border-top: 1px solid #e9ecef;
        }
        
        .modal-title {
            font-weight: 700;
        }
        
        .nav-tabs .nav-link {
            color: var(--dark-color);
            font-weight: 600;
            border: none;
            padding: 12px 20px;
        }
        
        .nav-tabs .nav-link.active {
            color: var(--dashen-primary);
            border-bottom: 3px solid var(--dashen-primary);
            background: transparent;
        }
        
        .nav-tabs .nav-link:hover:not(.active) {
            border-bottom: 3px solid #e9ecef;
        }
        
        .tab-pane {
            padding: 20px 0;
        }
        
        .detail-label {
            font-weight: 600;
            color: var(--dashen-primary);
            font-size: 0.9rem;
        }
        
        .detail-value {
            color: var(--dark-color);
            margin-bottom: 15px;
        }
        
        .comment-box {
            background: #f8f9fa;
            border-radius: var(--border-radius-sm);
            padding: 15px;
            margin-bottom: 15px;
        }
        
        .comment-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            font-size: 0.9rem;
        }
        
        .comment-author {
            font-weight: 600;
            color: var(--dashen-primary);
        }
        
        .comment-time {
            color: #6c757d;
        }
        
        .attachment-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 15px;
            background: #f8f9fa;
            border-radius: var(--border-radius-sm);
            margin-bottom: 10px;
        }
        
        .attachment-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .attachment-info i {
            font-size: 1.5rem;
            color: var(--dashen-primary);
        }
        
        .attachment-meta {
            font-size: 0.8rem;
            color: #6c757d;
        }
        
        .activity-item {
            padding: 10px 0;
            border-bottom: 1px solid #f1f3f4;
        }
        
        .activity-header {
            display: flex;
            justify-content: space-between;
            font-size: 0.9rem;
        }
        
        .activity-user {
            font-weight: 600;
            color: var(--dashen-primary);
        }
        
        .activity-time {
            color: #6c757d;
        }
        
        .activity-description {
            font-size: 0.9rem;
            color: var(--dark-color);
            margin-top: 5px;
        }
        
        .quick-action-btn {
            padding: 8px 15px;
            border-radius: var(--border-radius-sm);
            font-weight: 500;
            transition: var(--transition);
        }
        
        .quick-action-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        
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
            
            .filter-section .row > div {
                margin-bottom: 15px;
            }
            
            .header-brand .brand-text {
                font-size: 1.1rem;
            }
            
            .user-info {
                flex-wrap: wrap;
                justify-content: center;
            }
            
            .notification-dropdown {
                width: 300px;
            }
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
                    <i class="bi bi-person-circle"></i> Profile
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
            
            <div class="d-flex justify-content-between align-items-start mb-4">
                <div>
                    <h1 class="welcome-title">Issues Management</h1>
                    <p class="welcome-subtitle">Track, manage, and resolve issues efficiently with comprehensive filtering and search capabilities.</p>
                </div>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createIssueModal">
                    <i class="bi bi-plus-circle me-2"></i> Create New Issue
                </button>
            </div>
            
            <div class="filter-section">
                <form method="GET" class="row g-3" id="filterForm">
                    <div class="col-md-3">
                        <label for="status" class="form-label">Status</label>
                        <select class="form-select" id="status" name="status">
                            <option value="">All Statuses</option>
                            <option value="open" <?php echo (isset($_GET['status']) && $_GET['status'] == 'open') ? 'selected' : ''; ?>>Open</option>
                            <option value="assigned" <?php echo (isset($_GET['status']) && $_GET['status'] == 'assigned') ? 'selected' : ''; ?>>Assigned</option>
                            <option value="in_progress" <?php echo (isset($_GET['status']) && $_GET['status'] == 'in_progress') ? 'selected' : ''; ?>>In Progress</option>
                            <option value="resolved" <?php echo (isset($_GET['status']) && $_GET['status'] == 'resolved') ? 'selected' : ''; ?>>Resolved</option>
                            <option value="closed" <?php echo (isset($_GET['status']) && $_GET['status'] == 'closed') ? 'selected' : ''; ?>>Closed</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="approval_status" class="form-label">Approval Status</label>
                        <select class="form-select" id="approval_status" name="approval_status">
                            <option value="">All</option>
                            <option value="pending_approval" <?php echo (isset($_GET['approval_status']) && $_GET['approval_status'] == 'pending_approval') ? 'selected' : ''; ?>>Pending Approval</option>
                            <option value="approved" <?php echo (isset($_GET['approval_status']) && $_GET['approval_status'] == 'approved') ? 'selected' : ''; ?>>Approved</option>
                            <option value="rejected" <?php echo (isset($_GET['approval_status']) && $_GET['approval_status'] == 'rejected') ? 'selected' : ''; ?>>Rejected</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="priority" class="form-label">Priority</label>
                        <select class="form-select" id="priority" name="priority">
                            <option value="">All Priorities</option>
                            <option value="low" <?php echo (isset($_GET['priority']) && $_GET['priority'] == 'low') ? 'selected' : ''; ?>>Low</option>
                            <option value="medium" <?php echo (isset($_GET['priority']) && $_GET['priority'] == 'medium') ? 'selected' : ''; ?>>Medium</option>
                            <option value="high" <?php echo (isset($_GET['priority']) && $_GET['priority'] == 'high') ? 'selected' : ''; ?>>High</option>
                            <option value="critical" <?php echo (isset($_GET['priority']) && $_GET['priority'] == 'critical') ? 'selected' : ''; ?>>Critical</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="project_id" class="form-label">Project</label>
                        <select class="form-select" id="project_id" name="project_id">
                            <option value="">All Projects</option>
                            <?php foreach ($projects as $id => $name): ?>
                                <option value="<?php echo $id; ?>" <?php echo (isset($_GET['project_id']) && $_GET['project_id'] == $id) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="type" class="form-label">Type</label>
                        <select class="form-select" id="type" name="type">
                            <option value="">All Types</option>
                            <option value="bug" <?php echo (isset($_GET['type']) && $_GET['type'] == 'bug') ? 'selected' : ''; ?>>Bug</option>
                            <option value="feature" <?php echo (isset($_GET['type']) && $_GET['type'] == 'feature') ? 'selected' : ''; ?>>Feature</option>
                            <option value="task" <?php echo (isset($_GET['type']) && $_GET['type'] == 'task') ? 'selected' : ''; ?>>Task</option>
                            <option value="improvement" <?php echo (isset($_GET['type']) && $_GET['type'] == 'improvement') ? 'selected' : ''; ?>>Improvement</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="assigned_to" class="form-label">Assigned To</label>
                        <select class="form-select" id="assigned_to" name="assigned_to">
                            <option value="">All</option>
                            <option value="unassigned" <?php echo (isset($_GET['assigned_to']) && $_GET['assigned_to'] == 'unassigned') ? 'selected' : ''; ?>>Unassigned</option>
                            <?php
                            $assignee_query = "SELECT DISTINCT u.id, u.username FROM users u 
                                              INNER JOIN issues i ON u.id = i.assigned_to 
                                              WHERE i.assigned_to IS NOT NULL ORDER BY u.username";
                            $assignee_result = $conn->query($assignee_query);
                            if ($assignee_result) {
                                while ($assignee = $assignee_result->fetch_assoc()) {
                                    $selected = (isset($_GET['assigned_to']) && $_GET['assigned_to'] == $assignee['id']) ? 'selected' : '';
                                    echo '<option value="' . $assignee['id'] . '" ' . $selected . '>' . htmlspecialchars($assignee['username']) . '</option>';
                                }
                            }
                            ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="items_per_page" class="form-label">Items per page</label>
                        <select class="form-select" id="items_per_page" name="items_per_page" onchange="this.form.submit()">
                            <option value="10" <?php echo $items_per_page == 10 ? 'selected' : ''; ?>>10</option>
                            <option value="25" <?php echo $items_per_page == 25 ? 'selected' : ''; ?>>25</option>
                            <option value="50" <?php echo $items_per_page == 50 ? 'selected' : ''; ?>>50</option>
                            <option value="100" <?php echo $items_per_page == 100 ? 'selected' : ''; ?>>100</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="search" class="form-label">Search</label>
                        <input type="text" class="form-control" id="search" name="search" 
                               value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>" 
                               placeholder="Search issues...">
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary me-2">
                            <i class="bi bi-filter me-2"></i> Apply Filters
                        </button>
                        <a href="issues.php" class="btn btn-outline-secondary">
                            <i class="bi bi-x-circle me-2"></i> Clear
                        </a>
                    </div>
                </form>
            </div>
            
            <div class="table-container">
                <?php if ($total_items > 0): ?>
                <div class="d-flex justify-content-between align-items-center p-4 border-bottom">
                    <div class="page-info fw-bold text-dark">
                        <i class="bi bi-list-check me-2"></i>
                        Showing <?php echo $offset + 1; ?> to <?php echo min($offset + $items_per_page, $total_items); ?> of <?php echo $total_items; ?> issues
                    </div>
                </div>
                <?php endif; ?>
                
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Title</th>
                                <th>Project</th>
                                <th>Approval Status</th>
                                <th>Status</th>
                                <th>Priority</th>
                                <th>Type</th>
                                <th>Assigned To</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($issues)): ?>
                            <tr>
                                <td colspan="10" class="text-center py-5 empty-state">
                                    <i class="bi bi-inbox display-4 text-muted d-block mb-3"></i>
                                    <h5 class="text-muted mb-3">No issues found</h5>
                                    <p class="text-muted mb-4">Try adjusting your filters or create a new issue to get started.</p>
                                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createIssueModal">
                                        <i class="bi bi-plus-circle me-2"></i> Create Your First Issue
                                    </button>
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($issues as $issue): ?>
                            <tr>
                                <td><strong class="text-dark">#<?php echo $issue['id']; ?></strong></td>
                                <td>
                                    <a href="#" class="text-decoration-none text-dark fw-bold view-issue-link" 
                                       data-issue-id="<?php echo $issue['id']; ?>">
                                        <?php echo htmlspecialchars($issue['title']); ?>
                                    </a>
                                    <?php if (!empty($issue['summary'])): ?>
                                    <br>
                                    <small class="text-muted">
                                        <?php echo strlen($issue['summary']) > 60 ? htmlspecialchars(substr($issue['summary'], 0, 60)) . '...' : htmlspecialchars($issue['summary']); ?>
                                    </small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="fw-medium"><?php echo htmlspecialchars($issue['project_name']); ?></span>
                                    <?php if ($issue['project_status'] === 'terminated'): ?>
                                        <br><small class="text-danger">(Terminated)</small>
                                    <?php endif; ?>
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
                                        if ($issue['approval_status'] == 'pending_approval') echo 'Pending Approval';
                                        elseif ($issue['approval_status'] == 'approved') echo 'Approved';
                                        elseif ($issue['approval_status'] == 'rejected') echo 'Rejected';
                                        else echo ucfirst($issue['approval_status']);
                                        ?>
                                    </span>
                                </td>
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
                                    <span class="badge badge-status bg-light text-dark border">
                                        <i class="bi bi-tag me-1"></i><?php echo ucfirst($issue['type']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($issue['assigned_username'])): ?>
                                        <span class="fw-medium"><?php echo htmlspecialchars($issue['assigned_username']); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted fst-italic">Unassigned</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <small class="text-muted"><?php echo date('M j, Y', strtotime($issue['created_at'])); ?></small>
                                </td>
                                <td>
                                    <div class="btn-group">
                                        <button class="btn btn-sm btn-outline-primary view-issue-btn" 
                                                data-issue-id="<?php echo $issue['id']; ?>" title="View Details">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                        
                                        <?php 
                                        $can_edit_issue = hasRole('super_admin') || 
                                                         ($issue['created_by'] == $user_id && $issue['approval_status'] === 'pending_approval');
                                        if ($can_edit_issue): 
                                        ?>
                                            <button class="btn btn-sm btn-outline-secondary edit-issue-btn" 
                                                    data-issue-id="<?php echo $issue['id']; ?>" title="Edit Issue">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                        <?php endif; ?>
                                        
                                        <?php if ($issue['approval_status'] === 'pending_approval' && 
                                                  (hasRole('pm_manager') || hasRole('super_admin')) && 
                                                  !($issue['created_by'] == $user_id && hasRole('pm_manager') && !hasRole('super_admin'))): ?>
                                            <button class="btn btn-sm btn-outline-success approve-issue-btn" 
                                                    data-issue-id="<?php echo $issue['id']; ?>" title="Approve Issue">
                                                <i class="bi bi-check-circle"></i>
                                            </button>
                                            <button class="btn btn-sm btn-outline-danger reject-issue-btn" 
                                                    data-issue-id="<?php echo $issue['id']; ?>" title="Reject Issue">
                                                <i class="bi bi-x-circle"></i>
                                            </button>
                                        <?php endif; ?>
                                        
                                        <?php if (hasRole('super_admin')): ?>
                                            <button class="btn btn-sm btn-outline-danger delete-issue-btn" 
                                                    data-issue-id="<?php echo $issue['id']; ?>" 
                                                    data-issue-title="<?php echo htmlspecialchars($issue['title']); ?>" 
                                                    title="Delete Issue">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <?php if ($total_pages > 1): ?>
                <div class="d-flex justify-content-between align-items-center p-4 border-top">
                    <div class="text-muted">
                        Page <?php echo $current_page; ?> of <?php echo $total_pages; ?>
                    </div>
                    <nav aria-label="Page navigation">
                        <ul class="pagination justify-content-center mb-0">
                            <li class="page-item <?php echo $current_page <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?<?php 
                                    $query = $_GET;
                                    $query['page'] = $current_page - 1;
                                    echo http_build_query($query);
                                ?>">
                                    <i class="bi bi-chevron-left me-1"></i> Previous
                                </a>
                            </li>
                            
                            <?php
                            $start_page = max(1, $current_page - 2);
                            $end_page = min($total_pages, $start_page + 4);
                            
                            if ($end_page - $start_page < 4) {
                                $start_page = max(1, $end_page - 4);
                            }
                
                            for ($i = $start_page; $i <= $end_page; $i++): ?>
                                <li class="page-item <?php echo $i == $current_page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?<?php 
                                        $query = $_GET;
                                        $query['page'] = $i;
                                        echo http_build_query($query);
                                    ?>"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>
                            
                            <li class="page-item <?php echo $current_page >= $total_pages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?<?php 
                                    $query = $_GET;
                                    $query['page'] = $current_page + 1;
                                    echo http_build_query($query);
                                ?>">
                                    Next <i class="bi bi-chevron-right ms-1"></i>
                                </a>
                            </li>
                        </ul>
                    </nav>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Create Issue Modal -->
    <div class="modal fade" id="createIssueModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Create New Issue</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="createIssueForm">
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <label for="issue_project_id" class="form-label">Project <span class="text-danger">*</span></label>
                                <select class="form-select" id="issue_project_id" name="project_id" required>
                                    <option value="">Select Project</option>
                                    <?php foreach ($projects as $id => $name): ?>
                                        <option value="<?php echo $id; ?>"><?php echo htmlspecialchars($name); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <label for="issue_title" class="form-label">Title <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="issue_title" name="title" required maxlength="255">
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <label for="issue_summary" class="form-label">Summary</label>
                                <textarea class="form-control" id="issue_summary" name="summary" rows="2"></textarea>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <label for="issue_description" class="form-label">Description</label>
                                <textarea class="form-control" id="issue_description" name="description" rows="4"></textarea>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="issue_priority" class="form-label">Priority <span class="text-danger">*</span></label>
                                <select class="form-select" id="issue_priority" name="priority" required>
                                    <option value="">Select Priority</option>
                                    <option value="low">Low</option>
                                    <option value="medium">Medium</option>
                                    <option value="high">High</option>
                                    <option value="critical">Critical</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="issue_type" class="form-label">Type <span class="text-danger">*</span></label>
                                <select class="form-select" id="issue_type" name="type" required>
                                    <option value="">Select Type</option>
                                    <option value="bug">Bug</option>
                                    <option value="feature">Feature</option>
                                    <option value="task">Task</option>
                                    <option value="improvement">Improvement</option>
                                </select>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <label for="issue_labels" class="form-label">Labels (comma separated)</label>
                                <input type="text" class="form-control" id="issue_labels" name="labels" placeholder="e.g., frontend, backend, urgent">
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="saveIssueBtn">Create Issue</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Edit Issue Modal -->
    <div class="modal fade" id="editIssueModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Edit Issue</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="editIssueForm">
                        <input type="hidden" id="edit_issue_id" name="issue_id">
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <label for="edit_issue_title" class="form-label">Title <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="edit_issue_title" name="title" required maxlength="255">
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <label for="edit_issue_summary" class="form-label">Summary</label>
                                <textarea class="form-control" id="edit_issue_summary" name="summary" rows="2"></textarea>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <label for="edit_issue_description" class="form-label">Description</label>
                                <textarea class="form-control" id="edit_issue_description" name="description" rows="4"></textarea>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="edit_issue_priority" class="form-label">Priority <span class="text-danger">*</span></label>
                                <select class="form-select" id="edit_issue_priority" name="priority" required>
                                    <option value="">Select Priority</option>
                                    <option value="low">Low</option>
                                    <option value="medium">Medium</option>
                                    <option value="high">High</option>
                                    <option value="critical">Critical</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="edit_issue_type" class="form-label">Type <span class="text-danger">*</span></label>
                                <select class="form-select" id="edit_issue_type" name="type" required>
                                    <option value="">Select Type</option>
                                    <option value="bug">Bug</option>
                                    <option value="feature">Feature</option>
                                    <option value="task">Task</option>
                                    <option value="improvement">Improvement</option>
                                </select>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <label for="edit_issue_labels" class="form-label">Labels (comma separated)</label>
                                <input type="text" class="form-control" id="edit_issue_labels" name="labels" placeholder="e.g., frontend, backend, urgent">
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="updateIssueBtn">Update Issue</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Issue Details Modal -->
    <div class="modal fade" id="issueDetailModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="issueDetailModalTitle">Issue Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="issueDetailModalBody">
                    <div class="text-center py-5">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="mt-2">Loading issue details...</p>
                    </div>
                </div>
                <div class="modal-footer" id="issueDetailModalFooter">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
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
    
    <!-- Assign Issue Modal -->
    <div class="modal fade" id="assignIssueModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Assign Issue</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="assignIssueForm">
                        <input type="hidden" id="assign_issue_id" name="issue_id">
                        <div class="mb-3">
                            <label for="assign_user_id" class="form-label">Assign To</label>
                            <select class="form-select" id="assign_user_id" name="assigned_to">
                                <option value="">Unassigned</option>
                            </select>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="confirmAssignBtn">Assign Issue</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Update Status Modal -->
    <div class="modal fade" id="updateStatusModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-warning text-white">
                    <h5 class="modal-title">Update Status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="updateStatusForm">
                        <input type="hidden" id="status_issue_id" name="issue_id">
                        <div class="mb-3">
                            <label for="status_select" class="form-label">New Status <span class="text-danger">*</span></label>
                            <select class="form-select" id="status_select" name="status" required>
                                <option value="">Select Status</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="status_comment" class="form-label">Comment (Optional)</label>
                            <textarea class="form-control" id="status_comment" name="status_comment" rows="2"></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="confirmStatusBtn">Update Status</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Delete Issue Modal -->
    <div class="modal fade" id="deleteIssueModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">Delete Issue</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete this issue?</p>
                    <p class="fw-bold" id="deleteIssueTitle"></p>
                    <p class="text-danger small">This action cannot be undone. All comments, attachments, and activity logs will be permanently deleted.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="confirmDeleteBtn">Delete Issue</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Toast Container for Notifications -->
    <div class="toast-container" id="toastContainer"></div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script>
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

        // Current issue ID for actions
        let currentIssueId = null;

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

        // View Issue Details
        $(document).on('click', '.view-issue-btn, .view-issue-link', function(e) {
            e.preventDefault();
            const issueId = $(this).data('issue-id');
            currentIssueId = issueId;
            
            $('#issueDetailModalBody').html(`
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2">Loading issue details...</p>
                </div>
            `);
            $('#issueDetailModalFooter').html('<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>');
            
            $('#issueDetailModal').modal('show');
            
            $.ajax({
                url: window.location.href,
                method: 'POST',
                data: {
                    action: 'get_issue_details',
                    issue_id: issueId
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        renderIssueDetails(response);
                    } else {
                        $('#issueDetailModalBody').html(`
                            <div class="alert alert-danger">
                                ${response.message || 'Error loading issue details'}
                            </div>
                        `);
                    }
                },
                error: function() {
                    $('#issueDetailModalBody').html(`
                        <div class="alert alert-danger">
                            Error loading issue details. Please try again.
                        </div>
                    `);
                }
            });
        });

        // Edit Issue
        $(document).on('click', '.edit-issue-btn', function(e) {
            e.preventDefault();
            const issueId = $(this).data('issue-id');
            
            $.ajax({
                url: window.location.href,
                method: 'POST',
                data: {
                    action: 'get_issue_for_edit',
                    issue_id: issueId
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        const issue = response.issue;
                        
                        $('#edit_issue_id').val(issue.id);
                        $('#edit_issue_title').val(issue.title);
                        $('#edit_issue_summary').val(issue.summary || '');
                        $('#edit_issue_description').val(issue.description || '');
                        $('#edit_issue_priority').val(issue.priority);
                        $('#edit_issue_type').val(issue.type);
                        $('#edit_issue_labels').val(issue.labels || '');
                        
                        $('#editIssueModal').modal('show');
                    } else {
                        showToast('error', response.message || 'Error loading issue for edit');
                    }
                },
                error: function() {
                    showToast('error', 'Error loading issue for edit');
                }
            });
        });

        // Update Issue
        $('#updateIssueBtn').click(function() {
            const formData = $('#editIssueForm').serialize();
            
            $.ajax({
                url: window.location.href,
                method: 'POST',
                data: formData + '&action=edit_issue',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        $('#editIssueModal').modal('hide');
                        showToast('success', response.message);
                        setTimeout(() => {
                            location.reload();
                        }, 1500);
                    } else {
                        showToast('error', response.message);
                    }
                },
                error: function() {
                    showToast('error', 'Error updating issue');
                }
            });
        });

        // Create Issue
        $('#saveIssueBtn').click(function() {
            // Validate form
            const projectId = $('#issue_project_id').val();
            const title = $('#issue_title').val().trim();
            const priority = $('#issue_priority').val();
            const type = $('#issue_type').val();
            
            if (!projectId) {
                showToast('error', 'Please select a project');
                return;
            }
            if (!title) {
                showToast('error', 'Please enter a title');
                return;
            }
            if (!priority) {
                showToast('error', 'Please select a priority');
                return;
            }
            if (!type) {
                showToast('error', 'Please select a type');
                return;
            }
            
            const formData = $('#createIssueForm').serialize();
            
            $.ajax({
                url: window.location.href,
                method: 'POST',
                data: formData + '&action=create_issue',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        $('#createIssueModal').modal('hide');
                        $('#createIssueForm')[0].reset();
                        showToast('success', response.message);
                        setTimeout(() => {
                            location.reload();
                        }, 1500);
                    } else {
                        showToast('error', response.message);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', error);
                    console.error('Response:', xhr.responseText);
                    showToast('error', 'Error creating issue. Please check console for details.');
                }
            });
        });

        // Approve Issue
        let approveIssueId = null;
        
        $(document).on('click', '.approve-issue-btn, .approve-from-detail', function() {
            approveIssueId = $(this).data('issue-id');
            $('#approveIssueModal').modal('show');
        });
        
        $('#confirmApproveBtn').click(function() {
            if (!approveIssueId) return;
            
            $.ajax({
                url: window.location.href,
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
        
        $(document).on('click', '.reject-issue-btn, .reject-from-detail', function() {
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
                url: window.location.href,
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

        // Assign Issue
        $('#confirmAssignBtn').click(function() {
            const issueId = $('#assign_issue_id').val();
            if (!issueId) return;
            
            const assignedTo = $('#assign_user_id').val();
            
            $.ajax({
                url: window.location.href,
                method: 'POST',
                data: {
                    action: 'assign_issue',
                    issue_id: issueId,
                    assigned_to: assignedTo
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        $('#assignIssueModal').modal('hide');
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

        // Delete Issue
        let deleteIssueId = null;
        
        $(document).on('click', '.delete-issue-btn', function() {
            deleteIssueId = $(this).data('issue-id');
            const issueTitle = $(this).data('issue-title');
            $('#deleteIssueTitle').text(issueTitle);
            $('#deleteIssueModal').modal('show');
        });
        
        $('#confirmDeleteBtn').click(function() {
            if (!deleteIssueId) return;
            
            $.ajax({
                url: window.location.href,
                method: 'POST',
                data: {
                    action: 'delete_issue',
                    issue_id: deleteIssueId
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        $('#deleteIssueModal').modal('hide');
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

        // Update Status - FIXED with better error handling
        $('#confirmStatusBtn').click(function() {
            const issueId = $('#status_issue_id').val();
            if (!issueId) {
                showToast('error', 'Issue ID not found');
                return;
            }
            
            const status = $('#status_select').val();
            const comment = $('#status_comment').val();
            
            if (!status) {
                showToast('error', 'Please select a status');
                return;
            }
            
            // Show loading state
            const btn = $(this);
            const originalText = btn.text();
            btn.prop('disabled', true).text('Updating...');
            
            console.log('Sending status update:', {
                action: 'update_status',
                issue_id: issueId,
                status: status,
                comment: comment
            });
            
            $.ajax({
                url: window.location.href,
                method: 'POST',
                data: {
                    action: 'update_status',
                    issue_id: issueId,
                    status: status,
                    status_comment: comment
                },
                dataType: 'json',
                timeout: 30000, // 30 second timeout
                success: function(response) {
                    console.log('Status update response:', response);
                    
                    if (response.success) {
                        // Hide the modal
                        $('#updateStatusModal').modal('hide');
                        
                        // Clear form
                        $('#status_comment').val('');
                        $('#status_select').val('');
                        
                        // Show success message
                        showToast('success', response.message);
                        
                        // Reload the page after a short delay
                        setTimeout(() => {
                            location.reload();
                        }, 1500);
                    } else {
                        showToast('error', response.message || 'Error updating status');
                        btn.prop('disabled', false).text(originalText);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Status Update Error:', error);
                    console.error('Status:', status);
                    console.error('Response Text:', xhr.responseText);
                    
                    let errorMessage = 'Error updating status. Please try again.';
                    
                    // Try to parse error response
                    if (xhr.responseText) {
                        try {
                            const response = JSON.parse(xhr.responseText);
                            if (response.message) {
                                errorMessage = response.message;
                            }
                        } catch (e) {
                            console.error('Could not parse error response');
                        }
                    }
                    
                    showToast('error', errorMessage);
                    btn.prop('disabled', false).text(originalText);
                }
            });
        });

        // Global function to submit comment
        window.submitComment = function(issueId) {
            const commentText = $('#comment_text').val().trim();
            
            if (!commentText) {
                showToast('error', 'Please enter a comment');
                return;
            }
            
            // Show loading state
            const btn = $('button[onclick^="submitComment"]');
            const originalText = btn.text();
            btn.prop('disabled', true).text('Posting...');
            
            $.ajax({
                url: window.location.href,
                method: 'POST',
                data: {
                    action: 'add_comment',
                    issue_id: issueId,
                    comment: commentText
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Clear the comment textarea
                        $('#comment_text').val('');
                        
                        // Show success message
                        showToast('success', response.message);
                        
                        // Refresh the issue details
                        $(document).trigger('click', ['.view-issue-btn[data-issue-id="' + issueId + '"]']);
                        
                        btn.prop('disabled', false).text(originalText);
                    } else {
                        showToast('error', response.message);
                        btn.prop('disabled', false).text(originalText);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Comment Error:', error);
                    showToast('error', 'Error adding comment. Please try again.');
                    btn.prop('disabled', false).text(originalText);
                }
            });
        };

        // Global function to upload attachment
        window.uploadAttachment = function(issueId) {
            const fileInput = document.getElementById('attachment_file');
            if (!fileInput.files || fileInput.files.length === 0) {
                showToast('error', 'Please select a file');
                return;
            }
            
            // Show loading state
            const btn = $('button[onclick^="uploadAttachment"]');
            const originalText = btn.text();
            btn.prop('disabled', true).text('Uploading...');
            
            const formData = new FormData();
            formData.append('action', 'upload_attachment');
            formData.append('issue_id', issueId);
            formData.append('attachment', fileInput.files[0]);
            
            $.ajax({
                url: window.location.href,
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Clear file input
                        fileInput.value = '';
                        
                        // Show success message
                        showToast('success', response.message);
                        
                        // Refresh the issue details
                        $(document).trigger('click', ['.view-issue-btn[data-issue-id="' + issueId + '"]']);
                        
                        btn.prop('disabled', false).text(originalText);
                    } else {
                        showToast('error', response.message);
                        btn.prop('disabled', false).text(originalText);
                    }
                },
                error: function() {
                    showToast('error', 'Error uploading file');
                    btn.prop('disabled', false).text(originalText);
                }
            });
        };

        // Global function to download attachment
        window.downloadAttachment = function(attachmentId, filename) {
            $.ajax({
                url: window.location.href,
                method: 'POST',
                data: {
                    action: 'download_attachment',
                    attachment_id: attachmentId
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Create a temporary link and click it to download
                        const link = document.createElement('a');
                        link.href = response.filepath;
                        link.download = response.filename;
                        document.body.appendChild(link);
                        link.click();
                        document.body.removeChild(link);
                    } else {
                        showToast('error', response.message || 'Error downloading file');
                    }
                },
                error: function() {
                    showToast('error', 'Error downloading file');
                }
            });
        };

        // Mark notification as read
        $('.notification-item').click(function() {
            const notificationId = $(this).data('id');
            markNotificationRead(notificationId);
        });

        function markNotificationRead(notificationId) {
            $.ajax({
                url: window.location.href,
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
                url: window.location.href,
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

        // Render Issue Details function
        function renderIssueDetails(data) {
            const issue = data.issue;
            const comments = data.comments || [];
            const attachments = data.attachments || [];
            const activityLogs = data.activity_logs || [];
            const assignableUsers = data.assignable_users || [];
            const canApprove = data.can_approve;
            const canAssign = data.can_assign;
            const canUpdateStatus = data.can_update_status;
            const canEdit = data.can_edit;
            const canDelete = data.can_delete;

            const getApprovalStatusClass = (status) => {
                switch(status) {
                    case 'pending_approval': return 'bg-warning text-dark';
                    case 'approved': return 'bg-success';
                    case 'rejected': return 'bg-danger';
                    default: return 'bg-secondary';
                }
            };

            const getStatusClass = (status) => {
                switch(status) {
                    case 'open': return 'bg-warning text-dark';
                    case 'assigned': return 'bg-info';
                    case 'in_progress': return 'bg-primary';
                    case 'resolved': return 'bg-success';
                    case 'closed': return 'bg-secondary';
                    default: return 'bg-secondary';
                }
            };

            const getPriorityClass = (priority) => {
                switch(priority) {
                    case 'low': return 'bg-secondary';
                    case 'medium': return 'bg-primary';
                    case 'high': return 'bg-warning text-dark';
                    case 'critical': return 'bg-danger';
                    default: return 'bg-primary';
                }
            };

            let html = `
                <ul class="nav nav-tabs" id="issueDetailTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="details-tab" data-bs-toggle="tab" data-bs-target="#details" type="button" role="tab">
                            <i class="bi bi-info-circle me-1"></i> Details
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="comments-tab" data-bs-toggle="tab" data-bs-target="#comments" type="button" role="tab">
                            <i class="bi bi-chat me-1"></i> Comments (${comments.length})
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="attachments-tab" data-bs-toggle="tab" data-bs-target="#attachments" type="button" role="tab">
                            <i class="bi bi-paperclip me-1"></i> Attachments (${attachments.length})
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="activity-tab" data-bs-toggle="tab" data-bs-target="#activity" type="button" role="tab">
                            <i class="bi bi-clock-history me-1"></i> Activity Log (${activityLogs.length})
                        </button>
                    </li>
                </ul>
                
                <div class="tab-content" id="issueDetailTabContent">
                    <!-- Details Tab -->
                    <div class="tab-pane fade show active" id="details" role="tabpanel">
                        <div class="row">
                            <div class="col-md-8">
                                <h5 class="mb-3">${escapeHtml(issue.title)}</h5>
                                
                                <div class="d-flex flex-wrap gap-2 mb-4">
                                    <span class="badge ${getApprovalStatusClass(issue.approval_status)}">
                                        Approval: ${formatStatus(issue.approval_status)}
                                    </span>
                                    <span class="badge ${getStatusClass(issue.status)}">
                                        Status: ${formatStatus(issue.status)}
                                    </span>
                                    <span class="badge ${getPriorityClass(issue.priority)}">
                                        Priority: ${ucfirst(issue.priority)}
                                    </span>
                                    <span class="badge bg-light text-dark border">
                                        Type: ${ucfirst(issue.type)}
                                    </span>
                                    <span class="badge bg-primary">
                                        Project: ${escapeHtml(issue.project_name)}
                                    </span>
                                </div>
                                
                                ${issue.summary ? `
                                    <div class="mb-4">
                                        <strong class="detail-label">Summary</strong>
                                        <p class="detail-value">${escapeHtml(issue.summary)}</p>
                                    </div>
                                ` : ''}
                                
                                ${issue.description ? `
                                    <div class="mb-4">
                                        <strong class="detail-label">Description</strong>
                                        <p class="detail-value">${escapeHtml(issue.description).replace(/\n/g, '<br>')}</p>
                                    </div>
                                ` : ''}
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-2">
                                            <strong class="detail-label">Created by:</strong>
                                            <span class="detail-value">${escapeHtml(issue.creator_username || 'Unknown')}</span>
                                        </div>
                                        <div class="mb-2">
                                            <strong class="detail-label">Created at:</strong>
                                            <span class="detail-value">${formatDate(issue.created_at)}</span>
                                        </div>
                                        ${issue.approver_username ? `
                                            <div class="mb-2">
                                                <strong class="detail-label">Approved by:</strong>
                                                <span class="detail-value">${escapeHtml(issue.approver_username)}</span>
                                            </div>
                                        ` : ''}
                                        ${issue.approved_at ? `
                                            <div class="mb-2">
                                                <strong class="detail-label">Approved at:</strong>
                                                <span class="detail-value">${formatDate(issue.approved_at)}</span>
                                            </div>
                                        ` : ''}
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-2">
                                            <strong class="detail-label">Assigned to:</strong>
                                            <span class="detail-value">
                                                ${issue.assigned_username ? escapeHtml(issue.assigned_username) : '<span class="text-muted">Unassigned</span>'}
                                            </span>
                                        </div>
                                        <div class="mb-2">
                                            <strong class="detail-label">Last updated:</strong>
                                            <span class="detail-value">${formatDate(issue.updated_at)}</span>
                                        </div>
                                    </div>
                                </div>
                                
                                ${issue.labels ? `
                                    <div class="mt-3">
                                        <strong class="detail-label">Labels:</strong>
                                        <div class="d-flex flex-wrap gap-1 mt-1">
                                            ${issue.labels.split(',').map(label => {
                                                label = label.trim();
                                                return label ? `<span class="badge bg-light text-dark border">${escapeHtml(label)}</span>` : '';
                                            }).join('')}
                                        </div>
                                    </div>
                                ` : ''}
                                
                                ${issue.rejection_reason ? `
                                    <div class="mt-3 alert alert-danger">
                                        <strong>Rejection Reason:</strong><br>
                                        ${escapeHtml(issue.rejection_reason)}
                                    </div>
                                ` : ''}
                            </div>
                            
                            <div class="col-md-4">
                                <div class="card">
                                    <div class="card-header bg-light">
                                        <strong>Quick Actions</strong>
                                    </div>
                                    <div class="card-body">
                                        ${canApprove ? `
                                            <button class="btn btn-success w-100 mb-2 quick-action-btn approve-from-detail" data-issue-id="${issue.id}">
                                                <i class="bi bi-check-circle me-1"></i> Approve Issue
                                            </button>
                                        ` : ''}
                                        
                                        ${canApprove ? `
                                            <button class="btn btn-danger w-100 mb-2 quick-action-btn reject-from-detail" data-issue-id="${issue.id}">
                                                <i class="bi bi-x-circle me-1"></i> Reject Issue
                                            </button>
                                        ` : ''}
                                        
                                        ${canAssign ? `
                                            <button class="btn btn-primary w-100 mb-2 quick-action-btn assign-from-detail" data-issue-id="${issue.id}">
                                                <i class="bi bi-person-plus me-1"></i> Assign Issue
                                            </button>
                                        ` : ''}
                                        
                                        ${canUpdateStatus ? `
                                            <button class="btn btn-warning w-100 mb-2 quick-action-btn status-from-detail" data-issue-id="${issue.id}" data-current-status="${issue.status}">
                                                <i class="bi bi-arrow-repeat me-1"></i> Update Status
                                            </button>
                                        ` : ''}
                                        
                                        ${canEdit ? `
                                            <button class="btn btn-outline-primary w-100 mb-2 quick-action-btn edit-from-detail" data-issue-id="${issue.id}">
                                                <i class="bi bi-pencil me-1"></i> Edit Issue
                                            </button>
                                        ` : ''}
                                        
                                        ${canDelete ? `
                                            <button class="btn btn-outline-danger w-100 mb-2 quick-action-btn delete-from-detail" data-issue-id="${issue.id}" data-issue-title="${escapeHtml(issue.title)}">
                                                <i class="bi bi-trash me-1"></i> Delete Issue
                                            </button>
                                        ` : ''}
                                    </div>
                                </div>
                                
                                <div class="card mt-3">
                                    <div class="card-header bg-light">
                                        <strong>Issue Info</strong>
                                    </div>
                                    <div class="card-body">
                                        <div><strong>ID:</strong> #${issue.id}</div>
                                        <div class="mt-2"><strong>Project Status:</strong> 
                                            <span class="badge ${issue.project_status === 'terminated' ? 'bg-danger' : 'bg-success'}">
                                                ${ucfirst(issue.project_status)}
                                            </span>
                                        </div>
                                        <div class="mt-2"><strong>Approval Status:</strong> 
                                            <span class="badge ${getApprovalStatusClass(issue.approval_status)}">
                                                ${formatStatus(issue.approval_status)}
                                            </span>
                                        </div>
                                        <div class="mt-2"><strong>Current Status:</strong> 
                                            <span class="badge ${getStatusClass(issue.status)}">
                                                ${formatStatus(issue.status)}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Comments Tab -->
                    <div class="tab-pane fade" id="comments" role="tabpanel">
                        <div class="row">
                            <div class="col-md-12">
                                <h5 class="mb-3">Comments</h5>
                                
                                ${comments.length === 0 ? `
                                    <p class="text-muted">No comments yet.</p>
                                ` : comments.map(comment => `
                                    <div class="comment-box">
                                        <div class="comment-header">
                                            <span class="comment-author">${escapeHtml(comment.username)}</span>
                                            <span class="comment-time">${comment.formatted_date}</span>
                                        </div>
                                        <p class="mb-0">${escapeHtml(comment.comment).replace(/\n/g, '<br>')}</p>
                                    </div>
                                `).join('')}
                                
                                <hr>
                                
                                <h5 class="mb-3">Add Comment</h5>
                                <div class="mb-3">
                                    <textarea class="form-control" id="comment_text" rows="3" placeholder="Add your comment here..."></textarea>
                                </div>
                                <button type="button" class="btn btn-primary" onclick="submitComment(${issue.id})">
                                    <i class="bi bi-chat me-1"></i> Post Comment
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Attachments Tab -->
                    <div class="tab-pane fade" id="attachments" role="tabpanel">
                        <div class="row">
                            <div class="col-md-12">
                                <h5 class="mb-3">Attachments</h5>
                                
                                ${attachments.length === 0 ? `
                                    <p class="text-muted">No attachments yet.</p>
                                ` : attachments.map(attachment => `
                                    <div class="attachment-item">
                                        <div class="attachment-info">
                                            <i class="bi bi-file-earmark"></i>
                                            <div>
                                                <div>${escapeHtml(attachment.filename)}</div>
                                                <div class="attachment-meta">
                                                    Uploaded by ${escapeHtml(attachment.username)} • ${attachment.formatted_date} • ${attachment.file_size || ''}
                                                </div>
                                            </div>
                                        </div>
                                        <button class="btn btn-sm btn-outline-primary" onclick="downloadAttachment(${attachment.id}, '${escapeHtml(attachment.filename)}')">
                                            <i class="bi bi-download"></i>
                                        </button>
                                    </div>
                                `).join('')}
                                
                                <hr>
                                
                                <h5 class="mb-3">Upload File</h5>
                                <div class="mb-3">
                                    <input type="file" class="form-control" id="attachment_file">
                                </div>
                                <button type="button" class="btn btn-primary" onclick="uploadAttachment(${issue.id})">
                                    <i class="bi bi-upload me-1"></i> Upload
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Activity Log Tab -->
                    <div class="tab-pane fade" id="activity" role="tabpanel">
                        <div class="row">
                            <div class="col-md-12">
                                <h5 class="mb-3">Activity Log</h5>
                                
                                ${activityLogs.length === 0 ? `
                                    <p class="text-muted">No activity recorded yet.</p>
                                ` : activityLogs.map(log => `
                                    <div class="activity-item">
                                        <div class="activity-header">
                                            <span class="activity-user">${escapeHtml(log.username || 'System')}</span>
                                            <span class="activity-time">${log.formatted_date}</span>
                                        </div>
                                        <div class="activity-description">
                                            <strong>${escapeHtml(log.action)}</strong>
                                            ${log.description ? `: ${escapeHtml(log.description)}` : ''}
                                        </div>
                                    </div>
                                `).join('')}
                            </div>
                        </div>
                    </div>
                </div>
            `;

            $('#issueDetailModalBody').html(html);
            
            let footerHtml = '<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>';
            $('#issueDetailModalFooter').html(footerHtml);
            
            const tabEl = document.querySelector('#issueDetailTabs');
            if (tabEl) {
                new bootstrap.Tab(tabEl);
            }
            
            $('.approve-from-detail').click(function() {
                approveIssueId = $(this).data('issue-id');
                $('#issueDetailModal').modal('hide');
                $('#approveIssueModal').modal('show');
            });
            
            $('.reject-from-detail').click(function() {
                rejectIssueId = $(this).data('issue-id');
                $('#issueDetailModal').modal('hide');
                $('#rejectIssueModal').modal('show');
            });
            
            $('.edit-from-detail').click(function() {
                const issueId = $(this).data('issue-id');
                $('#issueDetailModal').modal('hide');
                // Trigger edit for this issue
                $(document).trigger('click', ['.edit-issue-btn[data-issue-id="' + issueId + '"]']);
            });
            
            $('.delete-from-detail').click(function() {
                const issueId = $(this).data('issue-id');
                const issueTitle = $(this).data('issue-title');
                $('#issueDetailModal').modal('hide');
                deleteIssueId = issueId;
                $('#deleteIssueTitle').text(issueTitle);
                $('#deleteIssueModal').modal('show');
            });
            
            $('.assign-from-detail').click(function() {
                const issueId = $(this).data('issue-id');
                currentIssueId = issueId;
                $('#assign_issue_id').val(issueId);
                
                const select = $('#assign_user_id');
                select.empty().append('<option value="">Unassigned</option>');
                
                if (assignableUsers.length > 0) {
                    assignableUsers.forEach(user => {
                        select.append(`<option value="${user.id}" ${user.id == issue.assigned_to ? 'selected' : ''}>${escapeHtml(user.username)}</option>`);
                    });
                }
                
                $('#issueDetailModal').modal('hide');
                $('#assignIssueModal').modal('show');
            });
            
            $('.status-from-detail').click(function() {
                const issueId = $(this).data('issue-id');
                const currentStatus = $(this).data('current-status');
                currentIssueId = issueId;
                $('#status_issue_id').val(issueId);
                
                // Populate status options based on current status
                const select = $('#status_select');
                select.empty().append('<option value="">Select Status</option>');
                
                // Define valid status transitions
                const statusOptions = {
                    'assigned': [{value: 'in_progress', label: 'In Progress'}],
                    'in_progress': [{value: 'resolved', label: 'Resolved'}],
                    'resolved': [{value: 'closed', label: 'Closed'}]
                };
                
                if (statusOptions[currentStatus]) {
                    statusOptions[currentStatus].forEach(status => {
                        select.append(`<option value="${status.value}">${status.label}</option>`);
                    });
                } else {
                    // If no valid transitions, show message
                    select.append('<option value="" disabled>No status updates available</option>');
                }
                
                $('#issueDetailModal').modal('hide');
                $('#updateStatusModal').modal('show');
            });
        }

        // Helper functions
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function formatDate(dateString) {
            if (!dateString) return '';
            const date = new Date(dateString);
            return date.toLocaleString('en-US', { 
                month: 'short', 
                day: 'numeric', 
                year: 'numeric',
                hour: 'numeric',
                minute: '2-digit',
                hour12: true
            });
        }

        function formatStatus(status) {
            if (!status) return '';
            return status.split('_').map(word => 
                word.charAt(0).toUpperCase() + word.slice(1)
            ).join(' ');
        }

        function ucfirst(string) {
            if (!string) return '';
            return string.charAt(0).toUpperCase() + string.slice(1);
        }

        // Reset forms when modals are closed
        $('#createIssueModal').on('hidden.bs.modal', function() {
            $('#createIssueForm')[0].reset();
        });

        $('#editIssueModal').on('hidden.bs.modal', function() {
            $('#editIssueForm')[0].reset();
        });

        $('#rejectIssueModal').on('hidden.bs.modal', function() {
            $('#rejection_reason').val('');
            rejectIssueId = null;
        });

        $('#assignIssueModal').on('hidden.bs.modal', function() {
            $('#assign_issue_id').val('');
        });

        $('#updateStatusModal').on('hidden.bs.modal', function() {
            $('#status_comment').val('');
            $('#status_issue_id').val('');
            $('#status_select').val('');
        });

        $('#deleteIssueModal').on('hidden.bs.modal', function() {
            deleteIssueId = null;
        });
    </script>
</body>
</html>