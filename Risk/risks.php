<?php
// risks.php - Unified Risk Management System with Full Approval Workflow
// Version 7.2 - FIXED: Risk Assessment Matrix values now properly save
// FIXED: Likelihood and Impact values are correctly captured and updated
// Last Updated: 2026-02-13

session_start();
require_once '../db.php';

// =============================================
// DATABASE VERIFICATION - Tables exist check only
// =============================================
function verify_database_tables($conn) {
    // Verify notifications table exists
    $result = $conn->query("SHOW TABLES LIKE 'notifications'");
    if ($result->num_rows === 0) {
        die("Error: notifications table does not exist. Please run the database setup first.");
    }
    
    // Verify risk_statuses has all required statuses
    $required_statuses = ['pending_review', 'open', 'in_progress', 'mitigated', 'closed', 'rejected'];
    foreach ($required_statuses as $status_key) {
        $check = $conn->prepare("SELECT id FROM risk_statuses WHERE status_key = ?");
        $check->bind_param('s', $status_key);
        $check->execute();
        $result = $check->get_result();
        if ($result->num_rows === 0) {
            // Create missing status
            $insert = $conn->prepare("INSERT INTO risk_statuses (status_key, label, is_active, created_at) VALUES (?, ?, 1, NOW())");
            $label = ucwords(str_replace('_', ' ', $status_key));
            $insert->bind_param('ss', $status_key, $label);
            $insert->execute();
            $insert->close();
        }
        $check->close();
    }
}

verify_database_tables($conn);

// =============================================
// ROLE-BASED ACCESS CONTROL - SRS Section 2.2
// =============================================
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$current_user_id = $_SESSION['user_id'];

// Get current user details
$user_sql = "SELECT id, username, email, system_role FROM users WHERE id = ?";
$stmt = $conn->prepare($user_sql);
$stmt->bind_param("i", $current_user_id);
$stmt->execute();
$current_user = $stmt->get_result()->fetch_assoc();
$stmt->close();

$user_role = $current_user['system_role'] ?? '';
$username = $current_user['username'] ?? 'User';

// =============================================
// PERMISSIONS MATRIX - SRS Section 2.2
// =============================================
$permissions = [
    // Super Admin Permissions
    'can_view_all_projects' => ($user_role == 'super_admin'),
    'can_manage_categories' => ($user_role == 'super_admin'),
    'can_delete_risk' => ($user_role == 'super_admin'),
    'can_override_status' => ($user_role == 'super_admin'),
    'can_export_reports' => in_array($user_role, ['super_admin', 'pm_manager']),
    
    // Project Manager Permissions
    'can_approve_risk' => in_array($user_role, ['super_admin', 'pm_manager']),
    'can_assess_risk' => in_array($user_role, ['super_admin', 'pm_manager']),
    'can_assign_risk' => in_array($user_role, ['super_admin', 'pm_manager']),
    'can_close_risk' => in_array($user_role, ['super_admin', 'pm_manager']),
    'can_edit_any_risk' => in_array($user_role, ['super_admin', 'pm_manager']),
    'can_add_mitigation' => in_array($user_role, ['super_admin', 'pm_manager']),
    
    // Project Employee Permissions
    'can_create_risk' => in_array($user_role, ['super_admin', 'pm_manager', 'pm_employee']),
    'can_edit_own_risk' => ($user_role == 'pm_employee'),
    'can_update_assigned_risk' => ($user_role == 'pm_employee'),
    'can_add_comment' => in_array($user_role, ['super_admin', 'pm_manager', 'pm_employee']),
];

// =============================================
// NOTIFICATION SYSTEM - PRESERVE EXISTING
// =============================================

/**
 * Create a notification for a specific user
 */
function create_notification($conn, $user_id, $title, $message, $type = 'info', $related_module = 'risk', $related_id = null, $related_user_id = null, $metadata = null) {
    $sql = "INSERT INTO notifications (user_id, title, message, type, related_module, related_id, related_user_id, metadata, created_at, is_read) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), 0)";
    $stmt = $conn->prepare($sql);
    $metadata_json = $metadata ? json_encode($metadata) : null;
    $stmt->bind_param('issssiis', $user_id, $title, $message, $type, $related_module, $related_id, $related_user_id, $metadata_json);
    $result = $stmt->execute();
    $stmt->close();
    return $result;
}

/**
 * Get all users assigned to a project
 */
function get_project_assigned_users($conn, $project_id, $exclude_user_id = null) {
    $sql = "SELECT DISTINCT u.id, u.username, u.email, u.system_role 
            FROM users u
            INNER JOIN user_assignments ua ON u.id = ua.user_id
            WHERE ua.project_id = ? AND ua.is_active = 1
            AND u.system_role IN ('super_admin', 'pm_manager', 'pm_employee')";
    
    $params = [$project_id];
    $types = "i";
    
    if ($exclude_user_id) {
        $sql .= " AND u.id != ?";
        $params[] = $exclude_user_id;
        $types .= "i";
    }
    
    $sql .= " ORDER BY u.username";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $users = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $users;
}

/**
 * Get project managers for a specific project (for approval notifications)
 */
function get_project_managers_for_approval($conn, $project_id, $exclude_user_id = null) {
    $sql = "SELECT DISTINCT u.id, u.username, u.email 
            FROM users u
            INNER JOIN user_assignments ua ON u.id = ua.user_id
            WHERE ua.project_id = ? AND ua.is_active = 1
            AND u.system_role IN ('super_admin', 'pm_manager')";
    
    $params = [$project_id];
    $types = "i";
    
    if ($exclude_user_id) {
        $sql .= " AND u.id != ?";
        $params[] = $exclude_user_id;
        $types .= "i";
    }
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $users = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $users;
}

/**
 * Send notifications to all project members
 */
function notify_project_members($conn, $project_id, $exclude_user_id, $title, $message, $type, $related_module, $related_id, $metadata = null) {
    $users = get_project_assigned_users($conn, $project_id, $exclude_user_id);
    foreach ($users as $user) {
        create_notification($conn, $user['id'], $title, $message, $type, $related_module, $related_id, $exclude_user_id, $metadata);
    }
    return count($users);
}

/**
 * Notify managers for risk approval
 */
function notify_managers_for_approval($conn, $project_id, $risk_id, $risk_title, $reporter_name, $reporter_role) {
    $managers = get_project_managers_for_approval($conn, $project_id);
    $metadata = [
        'risk_id' => $risk_id,
        'risk_title' => $risk_title,
        'project_id' => $project_id,
        'reporter' => $reporter_name,
        'reporter_role' => $reporter_role,
        'action' => 'approval_required'
    ];
    
    foreach ($managers as $manager) {
        create_notification(
            $conn, 
            $manager['id'], 
            '⚠️ Risk Approval Required',
            "Risk '{$risk_title}' requires your approval. Reported by: {$reporter_name} ({$reporter_role})",
            'warning',
            'risk',
            $risk_id,
            null,
            $metadata
        );
    }
}

/**
 * Notify risk owner
 */
function notify_risk_owner($conn, $owner_id, $risk_id, $risk_title, $action, $actor_name) {
    if (!$owner_id) return false;
    
    $metadata = [
        'risk_id' => $risk_id,
        'risk_title' => $risk_title,
        'actor' => $actor_name,
        'action' => $action
    ];
    
    $messages = [
        'assigned' => "You have been assigned as owner of risk: '{$risk_title}'",
        'updated' => "Risk '{$risk_title}' has been updated by {$actor_name}",
        'comment' => "💬 New comment on risk '{$risk_title}' from {$actor_name}",
        'mitigation_assigned' => "🛡️ A mitigation action has been assigned to you for risk '{$risk_title}'",
        'status_changed' => "📊 Risk '{$risk_title}' status has been changed by {$actor_name}",
        'closed' => "✅ Risk '{$risk_title}' has been closed by {$actor_name}",
        'overdue' => "⚠️ Risk '{$risk_title}' is now overdue. Target resolution date has passed.",
        'deleted' => "❌ Risk '{$risk_title}' has been deleted by {$actor_name}"
    ];
    
    $titles = [
        'assigned' => '👤 Risk Owner Assigned',
        'updated' => '📝 Risk Updated',
        'comment' => '💬 New Comment',
        'mitigation_assigned' => '🛡️ Mitigation Action Assigned',
        'status_changed' => '📊 Risk Status Changed',
        'closed' => '✅ Risk Closed',
        'overdue' => '⚠️ Risk Overdue',
        'deleted' => '❌ Risk Deleted'
    ];
    
    return create_notification(
        $conn,
        $owner_id,
        $titles[$action] ?? 'Risk Notification',
        $messages[$action] ?? "Risk '{$risk_title}' requires your attention",
        $action == 'overdue' ? 'danger' : ($action == 'assigned' ? 'success' : 'info'),
        'risk',
        $risk_id,
        null,
        $metadata
    );
}

/**
 * Notify risk creator
 */
function notify_risk_creator($conn, $creator_id, $risk_id, $risk_title, $action, $actor_name) {
    if (!$creator_id) return false;
    
    $metadata = [
        'risk_id' => $risk_id,
        'risk_title' => $risk_title,
        'actor' => $actor_name,
        'action' => $action
    ];
    
    $messages = [
        'approved' => "✅ Your risk '{$risk_title}' has been APPROVED by {$actor_name}",
        'rejected' => "❌ Your risk '{$risk_title}' has been REJECTED by {$actor_name}. Please check the rejection reason.",
        'closed' => "✅ Your risk '{$risk_title}' has been CLOSED by {$actor_name}",
        'commented' => "💬 New comment on your risk '{$risk_title}' from {$actor_name}",
        'mitigation_added' => "🛡️ A mitigation action has been added to your risk '{$risk_title}' by {$actor_name}",
        'assessed' => "📊 Your risk '{$risk_title}' has been assessed by {$actor_name}",
        'edited' => "📝 Your risk '{$risk_title}' has been edited by you",
        'deleted' => "❌ Your risk '{$risk_title}' has been deleted by you"
    ];
    
    $titles = [
        'approved' => '✅ Risk Approved',
        'rejected' => '❌ Risk Rejected',
        'closed' => '✅ Risk Closed',
        'commented' => '💬 New Comment',
        'mitigation_added' => '🛡️ Mitigation Added',
        'assessed' => '📊 Risk Assessed',
        'edited' => '📝 Risk Edited',
        'deleted' => '❌ Risk Deleted'
    ];
    
    return create_notification(
        $conn,
        $creator_id,
        $titles[$action] ?? 'Risk Notification',
        $messages[$action] ?? "Your risk '{$risk_title}' has been updated by {$actor_name}",
        $action == 'approved' ? 'success' : ($action == 'rejected' ? 'danger' : 'info'),
        'risk',
        $risk_id,
        null,
        $metadata
    );
}

/**
 * Notify mitigation owner
 */
function notify_mitigation_owner($conn, $owner_id, $risk_id, $risk_title, $mitigation_title, $action, $actor_name) {
    if (!$owner_id) return false;
    
    $metadata = [
        'risk_id' => $risk_id,
        'risk_title' => $risk_title,
        'mitigation_title' => $mitigation_title,
        'actor' => $actor_name,
        'action' => $action
    ];
    
    $messages = [
        'assigned' => "🛡️ You have been assigned a mitigation action: '{$mitigation_title}' for risk '{$risk_title}'",
        'updated' => "📝 Mitigation action '{$mitigation_title}' has been updated by {$actor_name}",
        'status_changed' => "📊 Mitigation action '{$mitigation_title}' status changed to {$action} by {$actor_name}",
        'completed' => "✅ Mitigation action '{$mitigation_title}' has been COMPLETED by {$actor_name}",
        'overdue' => "⚠️ Mitigation action '{$mitigation_title}' for risk '{$risk_title}' is now OVERDUE",
        'deleted' => "❌ Mitigation action '{$mitigation_title}' has been deleted by {$actor_name}"
    ];
    
    $titles = [
        'assigned' => '🛡️ Mitigation Action Assigned',
        'updated' => '📝 Mitigation Updated',
        'status_changed' => '📊 Mitigation Status Changed',
        'completed' => '✅ Mitigation Completed',
        'overdue' => '⚠️ Mitigation Overdue',
        'deleted' => '❌ Mitigation Deleted'
    ];
    
    return create_notification(
        $conn,
        $owner_id,
        $titles[$action] ?? 'Mitigation Notification',
        $messages[$action] ?? "Mitigation action '{$mitigation_title}' requires your attention",
        $action == 'overdue' ? 'danger' : ($action == 'completed' ? 'success' : 'info'),
        'risk_mitigation',
        $risk_id,
        null,
        $metadata
    );
}

// =============================================
// USER PROJECT ACCESS - PRESERVE EXISTING
// =============================================

/**
 * Get accessible projects for current user
 */
function get_accessible_projects($conn, $user_id, $user_role) {
    if ($user_role === 'super_admin') {
        $sql = "SELECT id, name, description, status, start_date, end_date 
                FROM projects 
                WHERE status != 'terminated' 
                ORDER BY name";
        $result = $conn->query($sql);
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }
    
    $sql = "SELECT DISTINCT p.id, p.name, p.description, p.status, p.start_date, p.end_date
            FROM projects p
            INNER JOIN user_assignments ua ON p.id = ua.project_id
            WHERE ua.user_id = ? AND ua.is_active = 1
            AND p.status != 'terminated'
            ORDER BY p.name";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $projects = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $projects;
}

/**
 * Check if user has access to specific project
 */
function has_project_access($conn, $user_id, $project_id, $user_role) {
    if ($user_role === 'super_admin') {
        $check = $conn->prepare("SELECT status FROM projects WHERE id = ?");
        $check->bind_param('i', $project_id);
        $check->execute();
        $result = $check->get_result();
        $project = $result->fetch_assoc();
        $check->close();
        return $project && $project['status'] !== 'terminated';
    }
    
    $sql = "SELECT ua.id FROM user_assignments ua 
            INNER JOIN projects p ON ua.project_id = p.id
            WHERE ua.user_id = ? AND ua.project_id = ? AND ua.is_active = 1
            AND p.status != 'terminated'";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $user_id, $project_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $has_access = $result->num_rows > 0;
    $stmt->close();
    return $has_access;
}

/**
 * Get project users
 */
function get_project_users($conn, $project_id, $user_role, $current_user_id = null) {
    if ($user_role === 'super_admin') {
        $sql = "SELECT DISTINCT u.id, u.username, u.email, u.system_role
                FROM users u
                INNER JOIN user_assignments ua ON u.id = ua.user_id
                WHERE ua.project_id = ? AND ua.is_active = 1
                AND u.system_role IN ('super_admin', 'pm_manager', 'pm_employee')
                ORDER BY u.username";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $project_id);
    } else {
        $sql = "SELECT DISTINCT u.id, u.username, u.email, u.system_role
                FROM users u
                INNER JOIN user_assignments ua ON u.id = ua.user_id
                WHERE ua.project_id = ? AND ua.is_active = 1
                AND u.system_role IN ('pm_manager', 'pm_employee')
                ORDER BY u.username";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $project_id);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $users = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $users;
}

/**
 * Check if project is terminated
 */
function is_project_terminated($conn, $project_id) {
    $stmt = $conn->prepare("SELECT status FROM projects WHERE id = ?");
    $stmt->bind_param('i', $project_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $project = $result->fetch_assoc();
    $stmt->close();
    return $project && $project['status'] === 'terminated';
}

// =============================================
// HELPER FUNCTIONS - PRESERVE EXISTING
// =============================================
function e($v) { 
    return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); 
}

function time_elapsed_string($datetime, $full = false) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);
    
    $diff->w = floor($diff->d / 7);
    $diff->d -= $diff->w * 7;
    
    $string = [
        'y' => 'year',
        'm' => 'month',
        'w' => 'week',
        'd' => 'day',
        'h' => 'hour',
        'i' => 'minute',
        's' => 'second',
    ];
    
    foreach ($string as $k => &$v) {
        if ($diff->$k) {
            $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
        } else {
            unset($string[$k]);
        }
    }
    
    if (!$full) $string = array_slice($string, 0, 1);
    return $string ? implode(', ', $string) . ' ago' : 'just now';
}

function get_likelihood_label($score) {
    $labels = [1 => 'Very Unlikely', 2 => 'Unlikely', 3 => 'Possible', 4 => 'Likely', 5 => 'Almost Certain'];
    return $labels[$score] ?? 'Unknown';
}

function get_impact_label($score) {
    $labels = [1 => 'Insignificant', 2 => 'Minor', 3 => 'Moderate', 4 => 'Major', 5 => 'Catastrophic'];
    return $labels[$score] ?? 'Unknown';
}

function get_likelihood_color($score) {
    $colors = [1 => '#28a745', 2 => '#5cb85c', 3 => '#ffc107', 4 => '#fd7e14', 5 => '#dc3545'];
    return $colors[$score] ?? '#6c757d';
}

function get_impact_color($score) {
    $colors = [1 => '#28a745', 2 => '#5cb85c', 3 => '#ffc107', 4 => '#fd7e14', 5 => '#dc3545'];
    return $colors[$score] ?? '#6c757d';
}

function risk_level_from_score($score) {
    if ($score >= 13 && $score <= 25) return 'High';
    if ($score >= 6 && $score <= 12) return 'Medium';
    return 'Low';
}

function get_status_id_by_key($conn, $status_key) {
    $stmt = $conn->prepare("SELECT id FROM risk_statuses WHERE status_key = ? LIMIT 1");
    $stmt->bind_param('s', $status_key);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row ? (int)$row['id'] : null;
}

function get_status_key_by_id($conn, $status_id) {
    $stmt = $conn->prepare("SELECT status_key FROM risk_statuses WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $status_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row ? $row['status_key'] : '';
}

// =============================================
// HANDLE NOTIFICATION ACTIONS
// =============================================
if (isset($_GET['action'])) {
    if ($_GET['action'] === 'mark_read' && isset($_GET['notification_id'])) {
        $notification_id = (int)$_GET['notification_id'];
        $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
        $stmt->bind_param('ii', $notification_id, $current_user_id);
        $stmt->execute();
        $stmt->close();
        if (isset($_GET['redirect']) && $_GET['redirect']) {
            header("Location: " . urldecode($_GET['redirect']));
            exit;
        }
        header('Location: risks.php');
        exit;
    }
    
    if ($_GET['action'] === 'mark_all_read') {
        $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
        $stmt->bind_param('i', $current_user_id);
        $stmt->execute();
        $stmt->close();
        header('Location: risks.php');
        exit;
    }
}

// =============================================
// GET ACCESSIBLE PROJECTS FOR CURRENT USER
// =============================================
$accessible_projects = get_accessible_projects($conn, $current_user_id, $user_role);
$accessible_project_ids = array_column($accessible_projects, 'id');

// =============================================
// LOAD DROPDOWN DATA
// =============================================
$dropdowns = [
    'projects' => $accessible_projects,
    'departments' => $conn->query("SELECT id, department_name FROM departments ORDER BY department_name")->fetch_all(MYSQLI_ASSOC) ?: [],
    'categories' => $conn->query("SELECT id, name FROM risk_categories ORDER BY name")->fetch_all(MYSQLI_ASSOC) ?: [],
    'statuses' => $conn->query("SELECT id, status_key, label FROM risk_statuses ORDER BY id")->fetch_all(MYSQLI_ASSOC) ?: []
];

// =============================================
// GET RISK ID FOR VIEW MODE
// =============================================
$risk_id = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : null;

// =============================================
// HANDLE POST ACTIONS - WITH COMPLETE APPROVAL WORKFLOW
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // =========================================
    // CREATE RISK - SRS 3.1.1 - Always Pending Review
    // FIXED: Added proper handling of likelihood/impact for managers
    // =========================================
    if ($_POST['action'] === 'create_risk') {
        $title = trim($_POST['title'] ?? '');
        $project_id = !empty($_POST['project_id']) ? (int)$_POST['project_id'] : null;
        
        if (!$project_id) {
            $_SESSION['error'] = 'Project is required';
            header('Location: risks.php');
            exit;
        }
        
        if (is_project_terminated($conn, $project_id)) {
            $_SESSION['error'] = 'Cannot create risk: Project is terminated';
            header('Location: risks.php');
            exit;
        }
        
        if (!has_project_access($conn, $current_user_id, $project_id, $user_role)) {
            $_SESSION['error'] = 'You do not have access to this project';
            header('Location: risks.php');
            exit;
        }
        
        $description = trim($_POST['description'] ?? '');
        $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
        $department_id = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
        $trigger = trim($_POST['trigger_description'] ?? '');
        
        // FIXED: Handle risk assessment values - managers can set, employees get defaults
        if ($permissions['can_assess_risk']) {
            $likelihood = isset($_POST['likelihood']) ? max(1, min(5, (int)$_POST['likelihood'])) : 1;
            $impact = isset($_POST['impact']) ? max(1, min(5, (int)$_POST['impact'])) : 1;
        } else {
            $likelihood = 1;
            $impact = 1;
        }
        
        $score = $likelihood * $impact;
        $risk_level = risk_level_from_score($score);
        
        // ALWAYS start with Pending Review status
        $status_id = get_status_id_by_key($conn, 'pending_review');
        
        $conn->begin_transaction();
        try {
            $sql = "INSERT INTO risks (
                project_id, department_id, category_id, title, description, 
                trigger_description, likelihood, impact, risk_score, risk_level, 
                identified_by, created_by, status_id, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param(
                'iiisssiiisiii',
                $project_id, $department_id, $category_id, $title, $description,
                $trigger, $likelihood, $impact, $score, $risk_level,
                $current_user_id, $current_user_id, $status_id
            );
            $stmt->execute();
            $new_risk_id = $stmt->insert_id;
            $stmt->close();
            
            $proj_stmt = $conn->prepare("SELECT name FROM projects WHERE id = ?");
            $proj_stmt->bind_param('i', $project_id);
            $proj_stmt->execute();
            $proj_result = $proj_stmt->get_result()->fetch_assoc();
            $project_name = $proj_result['name'] ?? 'Unknown';
            $proj_stmt->close();
            
            $hist_sql = "INSERT INTO risk_history (risk_id, changed_by, change_type, comment, created_at) 
                        VALUES (?, ?, 'created', ?, NOW())";
            $hstmt = $conn->prepare($hist_sql);
            $comment = "Risk created by {$username} in project {$project_name} (Status: Pending Review)";
            $hstmt->bind_param('iis', $new_risk_id, $current_user_id, $comment);
            $hstmt->execute();
            $hstmt->close();
            
            // Notify managers for approval if not super admin creating
            if ($user_role !== 'super_admin') {
                notify_managers_for_approval($conn, $project_id, $new_risk_id, $title, $username, $user_role);
            }
            
            notify_project_members(
                $conn, 
                $project_id, 
                $current_user_id,
                '📋 New Risk Reported',
                "{$username} reported a new risk: '{$title}' in project {$project_name} - Pending Review",
                'info',
                'risk',
                $new_risk_id,
                ['risk_id' => $new_risk_id, 'risk_title' => $title, 'project' => $project_name, 'action' => 'created']
            );
            
            $conn->commit();
            $_SESSION['success'] = '✅ Risk created successfully and is pending review';
            header("Location: risks.php?id={$new_risk_id}");
            exit;
            
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error'] = 'Error creating risk: ' . $e->getMessage();
            header('Location: risks.php');
            exit;
        }
    }
    
    // =========================================
    // APPROVE/REJECT RISK - COMPLETE APPROVAL WORKFLOW
    // =========================================
    if ($_POST['action'] === 'approve_risk') {
        $risk_id = (int)($_POST['risk_id'] ?? 0);
        $approve = isset($_POST['approve']) && $_POST['approve'] == '1';
        $rejection_reason = trim($_POST['rejection_reason'] ?? '');
        
        $risk_stmt = $conn->prepare("SELECT r.title, r.project_id, r.created_by, r.owner_user_id, r.status_id, p.name as project_name 
                                    FROM risks r 
                                    LEFT JOIN projects p ON r.project_id = p.id
                                    WHERE r.id = ?");
        $risk_stmt->bind_param('i', $risk_id);
        $risk_stmt->execute();
        $risk_data = $risk_stmt->get_result()->fetch_assoc();
        $risk_stmt->close();
        
        if (!$risk_data) {
            $_SESSION['error'] = 'Risk not found';
            header('Location: risks.php');
            exit;
        }
        
        // Get current status
        $current_status_key = get_status_key_by_id($conn, $risk_data['status_id']);
        
        // Only allow approval/rejection of Pending Review risks
        if ($current_status_key !== 'pending_review') {
            $_SESSION['error'] = 'This risk is not in Pending Review status and cannot be approved/rejected';
            header("Location: risks.php?id={$risk_id}");
            exit;
        }
        
        // Check approval permissions based on who created the risk
        $can_approve = false;
        
        if ($user_role === 'super_admin') {
            $can_approve = true; // Super Admin can approve any risk
        } elseif ($user_role === 'pm_manager') {
            // PM Manager can approve risks created by PM Employees
            // For risks created by another PM Manager, only Super Admin can approve
            $creator_role_stmt = $conn->prepare("SELECT system_role FROM users WHERE id = ?");
            $creator_role_stmt->bind_param('i', $risk_data['created_by']);
            $creator_role_stmt->execute();
            $creator_role_result = $creator_role_stmt->get_result()->fetch_assoc();
            $creator_role = $creator_role_result['system_role'] ?? '';
            $creator_role_stmt->close();
            
            if ($creator_role === 'pm_employee') {
                $can_approve = true; // PM Manager can approve PM Employee risks
            } elseif ($creator_role === 'pm_manager' && $user_role === 'pm_manager') {
                // PM Manager cannot approve another PM Manager's risk - only Super Admin can
                $can_approve = false;
            }
        }
        
        if (!$can_approve) {
            $_SESSION['error'] = 'Permission denied: You do not have authority to approve/reject this risk.';
            header("Location: risks.php?id={$risk_id}");
            exit;
        }
        
        $conn->begin_transaction();
        try {
            if ($approve) {
                $status_id = get_status_id_by_key($conn, 'open');
                $sql = "UPDATE risks SET status_id = ?, approved_by = ?, approved_at = NOW(), updated_at = NOW() WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('iii', $status_id, $current_user_id, $risk_id);
                $action_type = 'approved';
                $status_text = 'APPROVED';
                $success_message = '✅ Risk approved successfully';
                $comment_text = "Risk APPROVED by {$username}";
            } else {
                $status_id = get_status_id_by_key($conn, 'rejected');
                $sql = "UPDATE risks SET status_id = ?, rejection_reason = ?, updated_at = NOW() WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('isi', $status_id, $rejection_reason, $risk_id);
                $action_type = 'rejected';
                $status_text = 'REJECTED';
                $success_message = '❌ Risk rejected successfully';
                $comment_text = "Risk REJECTED by {$username}: {$rejection_reason}";
            }
            
            $stmt->execute();
            $stmt->close();
            
            $hist_sql = "INSERT INTO risk_history (risk_id, changed_by, change_type, comment, created_at) 
                        VALUES (?, ?, 'status_changed', ?, NOW())";
            $hstmt = $conn->prepare($hist_sql);
            $hstmt->bind_param('iis', $risk_id, $current_user_id, $comment_text);
            $hstmt->execute();
            $hstmt->close();
            
            // Notify risk creator
            if ($risk_data['created_by'] && $risk_data['created_by'] != $current_user_id) {
                notify_risk_creator($conn, $risk_data['created_by'], $risk_id, $risk_data['title'], $action_type, $username);
            }
            
            // Notify all project members
            $project_name = $risk_data['project_name'] ?? 'Unknown Project';
            notify_project_members(
                $conn,
                $risk_data['project_id'],
                $current_user_id,
                $approve ? '✅ Risk Approved' : '❌ Risk Rejected',
                "Risk '{$risk_data['title']}' has been {$status_text} by {$username}" . ($rejection_reason ? ".\nReason: {$rejection_reason}" : ""),
                $approve ? 'success' : 'danger',
                'risk',
                $risk_id,
                ['risk_id' => $risk_id, 'risk_title' => $risk_data['title'], 'project' => $project_name, 'action' => $action_type]
            );
            
            $conn->commit();
            $_SESSION['success'] = $success_message;
            header("Location: risks.php?id={$risk_id}");
            exit;
            
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error'] = 'Error: ' . $e->getMessage();
            header("Location: risks.php?id={$risk_id}");
            exit;
        }
    }
    
    // =========================================
    // UPDATE RISK - FIXED: Now properly saves likelihood/impact values
    // FIXED: Added proper permission checks and field handling
    // =========================================
    if ($_POST['action'] === 'update_risk') {
        $risk_id = (int)($_POST['risk_id'] ?? 0);
        
        // Get current risk details for permission check
        $risk_check = $conn->prepare("SELECT created_by, status_id, title FROM risks WHERE id = ?");
        $risk_check->bind_param('i', $risk_id);
        $risk_check->execute();
        $risk_data = $risk_check->get_result()->fetch_assoc();
        $risk_check->close();
        
        if (!$risk_data) {
            $_SESSION['error'] = 'Risk not found';
            header('Location: risks.php');
            exit;
        }
        
        // Permission check
        $can_edit = false;
        if ($user_role == 'super_admin' || $user_role == 'pm_manager') {
            $can_edit = true;
        } elseif ($user_role == 'pm_employee' && $risk_data['created_by'] == $current_user_id) {
            $status_stmt = $conn->prepare("SELECT status_key FROM risk_statuses WHERE id = ?");
            $status_stmt->bind_param('i', $risk_data['status_id']);
            $status_stmt->execute();
            $status_result = $status_stmt->get_result()->fetch_assoc();
            $status_stmt->close();
            
            if ($status_result && $status_result['status_key'] == 'pending_review') {
                $can_edit = true;
            }
        }
        
        if (!$can_edit) {
            $_SESSION['error'] = 'You do not have permission to edit this risk';
            header("Location: risks.php?id={$risk_id}");
            exit;
        }
        
        // Build update query
        $update_fields = [];
        $params = [];
        $types = '';
        
        // Basic fields
        $title = trim($_POST['title'] ?? '');
        if (empty($title)) {
            $_SESSION['error'] = 'Risk title is required';
            header("Location: risks.php?id={$risk_id}");
            exit;
        }
        
        $update_fields[] = "title = ?"; $params[] = $title; $types .= 's';
        $update_fields[] = "description = ?"; $params[] = trim($_POST['description'] ?? ''); $types .= 's';
        $update_fields[] = "trigger_description = ?"; $params[] = trim($_POST['trigger_description'] ?? ''); $types .= 's';
        $update_fields[] = "category_id = ?"; $params[] = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null; $types .= 'i';
        $update_fields[] = "department_id = ?"; $params[] = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null; $types .= 'i';
        
        // Project ID
        $project_id = !empty($_POST['project_id']) ? (int)$_POST['project_id'] : null;
        if ($project_id) {
            $accessible_project_ids = array_column($accessible_projects, 'id');
            if (in_array($project_id, $accessible_project_ids)) {
                $update_fields[] = "project_id = ?"; $params[] = $project_id; $types .= 'i';
            }
        }
        
        // FIXED: Always check for likelihood/impact values if they're set in the form
        // This ensures that when managers edit, the assessment values are properly saved
        if (isset($_POST['likelihood']) && $_POST['likelihood'] !== '' && 
            isset($_POST['impact']) && $_POST['impact'] !== '') {
            
            $likelihood = max(1, min(5, (int)$_POST['likelihood']));
            $impact = max(1, min(5, (int)$_POST['impact']));
            $score = $likelihood * $impact;
            $risk_level = risk_level_from_score($score);
            
            $update_fields[] = "likelihood = ?"; $params[] = $likelihood; $types .= 'i';
            $update_fields[] = "impact = ?"; $params[] = $impact; $types .= 'i';
            $update_fields[] = "risk_score = ?"; $params[] = $score; $types .= 'i';
            $update_fields[] = "risk_level = ?"; $params[] = $risk_level; $types .= 's';
        }
        
        // Owner assignment
        if (isset($_POST['owner_user_id'])) {
            $owner_user_id = !empty($_POST['owner_user_id']) ? (int)$_POST['owner_user_id'] : null;
            $update_fields[] = "owner_user_id = ?"; $params[] = $owner_user_id; $types .= 'i';
        }
        
        // Response Strategy
        if (isset($_POST['response_strategy'])) {
            $response_strategy = !empty($_POST['response_strategy']) ? $_POST['response_strategy'] : null;
            $update_fields[] = "response_strategy = ?"; $params[] = $response_strategy; $types .= 's';
        }
        
        // Target Resolution Date
        if (isset($_POST['target_resolution_date'])) {
            $target_resolution_date = !empty($_POST['target_resolution_date']) ? $_POST['target_resolution_date'] : null;
            $update_fields[] = "target_resolution_date = ?"; $params[] = $target_resolution_date; $types .= 's';
        }
        
        // Status update - Only managers can change status directly
        if (($user_role == 'super_admin' || $user_role == 'pm_manager') && 
            isset($_POST['status_id']) && !empty($_POST['status_id'])) {
            $update_fields[] = "status_id = ?"; $params[] = (int)$_POST['status_id']; $types .= 'i';
        }
        
        $update_fields[] = "updated_at = NOW()";
        $params[] = $risk_id;
        $types .= 'i';
        
        $sql = "UPDATE risks SET " . implode(', ', $update_fields) . " WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        
        if ($stmt->execute()) {
            $hist_sql = "INSERT INTO risk_history (risk_id, changed_by, change_type, comment, created_at) 
                        VALUES (?, ?, 'updated', ?, NOW())";
            $hist_stmt = $conn->prepare($hist_sql);
            $comment = "Risk updated: {$title}";
            $hist_stmt->bind_param('iis', $risk_id, $current_user_id, $comment);
            $hist_stmt->execute();
            $hist_stmt->close();
            
            // Notify owner if assigned
            if (isset($owner_user_id) && $owner_user_id && $owner_user_id != $current_user_id) {
                notify_risk_owner($conn, $owner_user_id, $risk_id, $title, 'assigned', $username);
            }
            
            $_SESSION['success'] = '✅ Risk updated successfully';
            header("Location: risks.php?id={$risk_id}");
            exit;
        } else {
            $_SESSION['error'] = 'Error updating risk: ' . $conn->error;
            header("Location: risks.php?id={$risk_id}");
            exit;
        }
        $stmt->close();
    }
    
    // =========================================
    // UPDATE RESPONSE PLAN - Dedicated handler for response strategy
    // =========================================
    if ($_POST['action'] === 'update_response_plan') {
        $risk_id = (int)($_POST['risk_id'] ?? 0);
        
        if (!in_array($user_role, ['super_admin', 'pm_manager'])) {
            $_SESSION['error'] = 'Permission denied: Only Project Managers and Super Admins can set response strategy';
            header("Location: risks.php?id={$risk_id}");
            exit;
        }
        
        $response_strategy = !empty($_POST['response_strategy']) ? $_POST['response_strategy'] : null;
        $target_resolution_date = !empty($_POST['target_resolution_date']) ? $_POST['target_resolution_date'] : null;
        
        $sql = "UPDATE risks SET response_strategy = ?, target_resolution_date = ?, updated_at = NOW() WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ssi', $response_strategy, $target_resolution_date, $risk_id);
        
        if ($stmt->execute()) {
            $hist_sql = "INSERT INTO risk_history (risk_id, changed_by, change_type, comment, created_at) 
                        VALUES (?, ?, 'response_plan_updated', ?, NOW())";
            $hist_stmt = $conn->prepare($hist_sql);
            $comment = "Response strategy set to: {$response_strategy}" . ($target_resolution_date ? " (Target: {$target_resolution_date})" : "");
            $hist_stmt->bind_param('iis', $risk_id, $current_user_id, $comment);
            $hist_stmt->execute();
            $hist_stmt->close();
            
            $_SESSION['success'] = '✅ Response strategy updated successfully';
        } else {
            $_SESSION['error'] = 'Error updating response strategy: ' . $conn->error;
        }
        
        $stmt->close();
        header("Location: risks.php?id={$risk_id}");
        exit;
    }
    
    // =========================================
    // ASSIGN OWNER - Dedicated handler for owner assignment
    // =========================================
    if ($_POST['action'] === 'assign_owner') {
        $risk_id = (int)($_POST['risk_id'] ?? 0);
        
        if (!in_array($user_role, ['super_admin', 'pm_manager'])) {
            $_SESSION['error'] = 'Permission denied: Only Project Managers and Super Admins can assign owners';
            header("Location: risks.php?id={$risk_id}");
            exit;
        }
        
        $owner_user_id = !empty($_POST['owner_user_id']) ? (int)$_POST['owner_user_id'] : null;
        
        $sql = "UPDATE risks SET owner_user_id = ?, updated_at = NOW() WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ii', $owner_user_id, $risk_id);
        
        if ($stmt->execute()) {
            // Get owner name for history
            $owner_name = 'Unassigned';
            if ($owner_user_id) {
                $name_stmt = $conn->prepare("SELECT username FROM users WHERE id = ?");
                $name_stmt->bind_param('i', $owner_user_id);
                $name_stmt->execute();
                $owner_result = $name_stmt->get_result()->fetch_assoc();
                $owner_name = $owner_result['username'] ?? 'Unknown';
                $name_stmt->close();
            }
            
            $hist_sql = "INSERT INTO risk_history (risk_id, changed_by, change_type, comment, created_at) 
                        VALUES (?, ?, 'owner_assigned', ?, NOW())";
            $hist_stmt = $conn->prepare($hist_sql);
            $comment = "Risk owner assigned: {$owner_name}";
            $hist_stmt->bind_param('iis', $risk_id, $current_user_id, $comment);
            $hist_stmt->execute();
            $hist_stmt->close();
            
            // Notify new owner
            if ($owner_user_id && $owner_user_id != $current_user_id) {
                $risk_stmt = $conn->prepare("SELECT title FROM risks WHERE id = ?");
                $risk_stmt->bind_param('i', $risk_id);
                $risk_stmt->execute();
                $risk_title = $risk_stmt->get_result()->fetch_assoc()['title'] ?? '';
                $risk_stmt->close();
                
                notify_risk_owner($conn, $owner_user_id, $risk_id, $risk_title, 'assigned', $username);
            }
            
            $_SESSION['success'] = '✅ Risk owner assigned successfully';
        } else {
            $_SESSION['error'] = 'Error assigning owner: ' . $conn->error;
        }
        
        $stmt->close();
        header("Location: risks.php?id={$risk_id}");
        exit;
    }
    
    // =========================================
    // UPDATE RISK STATUS - Full Workflow Support
    // =========================================
    if ($_POST['action'] === 'update_risk_status') {
        $risk_id = (int)($_POST['risk_id'] ?? 0);
        $new_status_id = isset($_POST['status_id']) ? (int)$_POST['status_id'] : null;
        
        if (!$new_status_id) {
            $_SESSION['error'] = 'Invalid status selected';
            header("Location: risks.php?id={$risk_id}");
            exit;
        }
        
        $new_status_key = get_status_key_by_id($conn, $new_status_id);
        
        $risk_stmt = $conn->prepare("SELECT r.title, r.project_id, r.created_by, r.owner_user_id, r.status_id, p.name as project_name 
                                    FROM risks r 
                                    LEFT JOIN projects p ON r.project_id = p.id
                                    WHERE r.id = ?");
        $risk_stmt->bind_param('i', $risk_id);
        $risk_stmt->execute();
        $risk_data = $risk_stmt->get_result()->fetch_assoc();
        $risk_stmt->close();
        
        if (!$risk_data) {
            $_SESSION['error'] = 'Risk not found';
            header("Location: risks.php?id={$risk_id}");
            exit;
        }
        
        $current_status_key = get_status_key_by_id($conn, $risk_data['status_id']);
        
        // Permission check based on status transition
        $can_update = false;
        
        if ($user_role === 'super_admin') {
            $can_update = true;
        } elseif ($user_role === 'pm_manager') {
            $can_update = true;
        } elseif ($user_role === 'pm_employee') {
            if ($risk_data['owner_user_id'] == $current_user_id) {
                if ($current_status_key == 'open' && $new_status_key == 'in_progress') {
                    $can_update = true;
                } elseif ($current_status_key == 'in_progress' && $new_status_key == 'mitigated') {
                    $can_update = true;
                }
            }
        }
        
        if (!$can_update) {
            $_SESSION['error'] = 'Permission denied: You cannot update this risk status.';
            header("Location: risks.php?id={$risk_id}");
            exit;
        }
        
        $conn->begin_transaction();
        try {
            $sql = "UPDATE risks SET status_id = ?, updated_at = NOW() WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ii', $new_status_id, $risk_id);
            $stmt->execute();
            $stmt->close();
            
            $label_stmt = $conn->prepare("SELECT label FROM risk_statuses WHERE id = ?");
            $label_stmt->bind_param('i', $new_status_id);
            $label_stmt->execute();
            $label_result = $label_stmt->get_result()->fetch_assoc();
            $status_label = $label_result['label'] ?? $new_status_key;
            $label_stmt->close();
            
            $hist_sql = "INSERT INTO risk_history (risk_id, changed_by, change_type, comment, created_at) 
                        VALUES (?, ?, 'status_changed', ?, NOW())";
            $hstmt = $conn->prepare($hist_sql);
            $comment = "Risk status changed by {$username} from '{$current_status_key}' to '{$new_status_key}'";
            $hstmt->bind_param('iis', $risk_id, $current_user_id, $comment);
            $hstmt->execute();
            $hstmt->close();
            
            if ($risk_data['owner_user_id'] && $risk_data['owner_user_id'] != $current_user_id) {
                notify_risk_owner($conn, $risk_data['owner_user_id'], $risk_id, $risk_data['title'], 'status_changed', $username);
            }
            
            if ($risk_data['created_by'] && $risk_data['created_by'] != $current_user_id && $risk_data['created_by'] != $risk_data['owner_user_id']) {
                notify_risk_creator($conn, $risk_data['created_by'], $risk_id, $risk_data['title'], 'updated', $username);
            }
            
            if ($new_status_key == 'in_progress') {
                $mit_check = $conn->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END) as done 
                                           FROM risk_mitigations WHERE risk_id = ?");
                $mit_check->bind_param('i', $risk_id);
                $mit_check->execute();
                $mit_data = $mit_check->get_result()->fetch_assoc();
                $mit_check->close();
                
                if ($mit_data['total'] > 0 && $mit_data['total'] == $mit_data['done']) {
                    $mitigated_id = get_status_id_by_key($conn, 'mitigated');
                    $auto_update = $conn->prepare("UPDATE risks SET status_id = ?, updated_at = NOW() WHERE id = ?");
                    $auto_update->bind_param('ii', $mitigated_id, $risk_id);
                    $auto_update->execute();
                    $auto_update->close();
                }
            }
            
            $conn->commit();
            $_SESSION['success'] = '📊 Risk status updated successfully';
            header("Location: risks.php?id={$risk_id}");
            exit;
            
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error'] = 'Error: ' . $e->getMessage();
            header("Location: risks.php?id={$risk_id}");
            exit;
        }
    }
    
    // =========================================
    // CLOSE RISK - Only PM Manager and Super Admin
    // =========================================
    if ($_POST['action'] === 'close_risk') {
        $risk_id = (int)($_POST['risk_id'] ?? 0);
        
        if (!in_array($user_role, ['super_admin', 'pm_manager'])) {
            $_SESSION['error'] = 'Permission denied: Only Project Managers and Super Admins can close risks';
            header("Location: risks.php?id={$risk_id}");
            exit;
        }
        
        $risk_stmt = $conn->prepare("SELECT r.title, r.project_id, r.created_by, r.owner_user_id, p.name as project_name 
                                    FROM risks r 
                                    LEFT JOIN projects p ON r.project_id = p.id
                                    WHERE r.id = ?");
        $risk_stmt->bind_param('i', $risk_id);
        $risk_stmt->execute();
        $risk_data = $risk_stmt->get_result()->fetch_assoc();
        $risk_stmt->close();
        
        if (!$risk_data) {
            $_SESSION['error'] = 'Risk not found';
            header("Location: risks.php?id={$risk_id}");
            exit;
        }
        
        $status_id = get_status_id_by_key($conn, 'closed');
        
        $conn->begin_transaction();
        try {
            $sql = "UPDATE risks SET status_id = ?, updated_at = NOW() WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ii', $status_id, $risk_id);
            $stmt->execute();
            $stmt->close();
            
            $hist_sql = "INSERT INTO risk_history (risk_id, changed_by, change_type, comment, created_at) 
                        VALUES (?, ?, 'closed', ?, NOW())";
            $hstmt = $conn->prepare($hist_sql);
            $comment = "Risk closed by {$username}";
            $hstmt->bind_param('iis', $risk_id, $current_user_id, $comment);
            $hstmt->execute();
            $hstmt->close();
            
            if ($risk_data['created_by'] && $risk_data['created_by'] != $current_user_id) {
                notify_risk_creator($conn, $risk_data['created_by'], $risk_id, $risk_data['title'], 'closed', $username);
            }
            
            if ($risk_data['owner_user_id'] && $risk_data['owner_user_id'] != $current_user_id) {
                notify_risk_owner($conn, $risk_data['owner_user_id'], $risk_id, $risk_data['title'], 'closed', $username);
            }
            
            $conn->commit();
            $_SESSION['success'] = '✅ Risk closed successfully';
            header("Location: risks.php?id={$risk_id}");
            exit;
            
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error'] = 'Error: ' . $e->getMessage();
            header("Location: risks.php?id={$risk_id}");
            exit;
        }
    }
    
    // =========================================
    // ADD MITIGATION
    // =========================================
    if ($_POST['action'] === 'add_mitigation') {
        $risk_id = (int)($_POST['risk_id'] ?? 0);
        $mit_title = trim($_POST['mit_title'] ?? '');
        $mit_desc = trim($_POST['mit_description'] ?? '');
        $mit_owner = !empty($_POST['mit_owner_user_id']) ? (int)$_POST['mit_owner_user_id'] : null;
        $mit_due_date = !empty($_POST['mit_due_date']) ? $_POST['mit_due_date'] : null;
        $mit_response_strategy = !empty($_POST['mit_response_strategy']) ? $_POST['mit_response_strategy'] : 'Mitigate';
        
        $risk_stmt = $conn->prepare("SELECT r.title, r.project_id, r.created_by, r.owner_user_id, p.name as project_name 
                                    FROM risks r 
                                    LEFT JOIN projects p ON r.project_id = p.id
                                    WHERE r.id = ?");
        $risk_stmt->bind_param('i', $risk_id);
        $risk_stmt->execute();
        $risk_data = $risk_stmt->get_result()->fetch_assoc();
        $risk_stmt->close();
        
        if (!$risk_data) {
            $_SESSION['error'] = 'Risk not found';
            header("Location: risks.php?id={$risk_id}");
            exit;
        }
        
        $can_add_mitigation = false;
        if (in_array($user_role, ['super_admin', 'pm_manager'])) {
            $can_add_mitigation = true;
        } elseif ($user_role === 'pm_employee' && $risk_data['owner_user_id'] == $current_user_id) {
            $can_add_mitigation = true;
        }
        
        if (!$can_add_mitigation) {
            $_SESSION['error'] = 'Permission denied: You cannot add mitigation actions to this risk';
            header("Location: risks.php?id={$risk_id}");
            exit;
        }
        
        if (empty($mit_title)) {
            $_SESSION['error'] = 'Mitigation title is required';
            header("Location: risks.php?id={$risk_id}");
            exit;
        }
        
        $conn->begin_transaction();
        try {
            $sql = "INSERT INTO risk_mitigations (risk_id, title, description, owner_user_id, due_date, response_strategy, status, created_by, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, 'open', ?, NOW())";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ississi', $risk_id, $mit_title, $mit_desc, $mit_owner, $mit_due_date, $mit_response_strategy, $current_user_id);
            $stmt->execute();
            $mitigation_id = $stmt->insert_id;
            $stmt->close();
            
            $owner_name = 'Unassigned';
            if ($mit_owner) {
                $name_stmt = $conn->prepare("SELECT username FROM users WHERE id = ?");
                $name_stmt->bind_param('i', $mit_owner);
                $name_stmt->execute();
                $name_result = $name_stmt->get_result()->fetch_assoc();
                $owner_name = $name_result['username'] ?? 'Unknown';
                $name_stmt->close();
            }
            
            $hist_sql = "INSERT INTO risk_history (risk_id, changed_by, change_type, comment, created_at) 
                        VALUES (?, ?, 'mitigation_added', ?, NOW())";
            $hstmt = $conn->prepare($hist_sql);
            $comment = "Mitigation added by {$username}: {$mit_title}, Owner: {$owner_name}, Due: " . ($mit_due_date ? date('M j, Y', strtotime($mit_due_date)) : 'None');
            $hstmt->bind_param('iis', $risk_id, $current_user_id, $comment);
            $hstmt->execute();
            $hstmt->close();
            
            if ($mit_owner && $mit_owner != $current_user_id) {
                notify_mitigation_owner($conn, $mit_owner, $risk_id, $risk_data['title'], $mit_title, 'assigned', $username);
            }
            
            $conn->commit();
            $_SESSION['success'] = '🛡️ Mitigation action added successfully';
            header("Location: risks.php?id={$risk_id}#mitigations");
            exit;
            
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error'] = 'Error: ' . $e->getMessage();
            header("Location: risks.php?id={$risk_id}");
            exit;
        }
    }
    
    // =========================================
    // UPDATE MITIGATION STATUS
    // =========================================
    if ($_POST['action'] === 'update_mitigation_status') {
        $mitigation_id = (int)($_POST['mitigation_id'] ?? 0);
        $risk_id = (int)($_POST['risk_id'] ?? 0);
        $status = $_POST['status'] ?? 'open';
        
        $valid_statuses = ['open', 'in_progress', 'done', 'cancelled'];
        if (!in_array($status, $valid_statuses)) {
            $status = 'open';
        }
        
        $mit_stmt = $conn->prepare("SELECT m.title, m.owner_user_id, m.created_by, 
                                    r.title as risk_title, r.project_id, r.owner_user_id as risk_owner_id, r.created_by as risk_creator_id,
                                    r.status_id as risk_status_id
                                    FROM risk_mitigations m 
                                    JOIN risks r ON m.risk_id = r.id 
                                    WHERE m.id = ?");
        $mit_stmt->bind_param('i', $mitigation_id);
        $mit_stmt->execute();
        $mit_data = $mit_stmt->get_result()->fetch_assoc();
        $mit_stmt->close();
        
        if (!$mit_data) {
            $_SESSION['error'] = 'Mitigation not found';
            header("Location: risks.php?id={$risk_id}");
            exit;
        }
        
        $can_update = false;
        if ($user_role === 'super_admin') $can_update = true;
        elseif ($user_role === 'pm_manager') $can_update = true;
        elseif ($mit_data['owner_user_id'] == $current_user_id || $mit_data['created_by'] == $current_user_id) {
            $can_update = true;
        }
        
        if (!$can_update) {
            $_SESSION['error'] = 'Permission denied: You cannot update this mitigation action';
            header("Location: risks.php?id={$risk_id}");
            exit;
        }
        
        $conn->begin_transaction();
        try {
            $sql = "UPDATE risk_mitigations SET status = ?, updated_at = NOW() WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('si', $status, $mitigation_id);
            $stmt->execute();
            $stmt->close();
            
            $hist_sql = "INSERT INTO risk_history (risk_id, changed_by, change_type, comment, created_at) 
                        VALUES (?, ?, 'mitigation_status_changed', ?, NOW())";
            $hstmt = $conn->prepare($hist_sql);
            $status_label = ucfirst(str_replace('_', ' ', $status));
            $comment = "Mitigation '{$mit_data['title']}' status changed by {$username} to: {$status_label}";
            $hstmt->bind_param('iis', $risk_id, $current_user_id, $comment);
            $hstmt->execute();
            $hstmt->close();
            
            $mit_check = $conn->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END) as done 
                                       FROM risk_mitigations WHERE risk_id = ?");
            $mit_check->bind_param('i', $risk_id);
            $mit_check->execute();
            $mit_stats = $mit_check->get_result()->fetch_assoc();
            $mit_check->close();
            
            if ($mit_stats['total'] > 0 && $mit_stats['total'] == $mit_stats['done']) {
                $current_risk_status = get_status_key_by_id($conn, $mit_data['risk_status_id']);
                if ($current_risk_status !== 'closed' && $current_risk_status !== 'mitigated') {
                    $mitigated_id = get_status_id_by_key($conn, 'mitigated');
                    $update_risk = $conn->prepare("UPDATE risks SET status_id = ?, updated_at = NOW() WHERE id = ?");
                    $update_risk->bind_param('ii', $mitigated_id, $risk_id);
                    $update_risk->execute();
                    $update_risk->close();
                }
            }
            
            $conn->commit();
            $_SESSION['success'] = '📊 Mitigation status updated successfully';
            header("Location: risks.php?id={$risk_id}#mitigations");
            exit;
            
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error'] = 'Error: ' . $e->getMessage();
            header("Location: risks.php?id={$risk_id}");
            exit;
        }
    }
    
    // =========================================
    // UPDATE MITIGATION - Full update
    // =========================================
    if ($_POST['action'] === 'update_mitigation') {
        $mitigation_id = (int)($_POST['mitigation_id'] ?? 0);
        $risk_id = (int)($_POST['risk_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $owner_user_id = !empty($_POST['owner_user_id']) ? (int)$_POST['owner_user_id'] : null;
        $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
        $status = $_POST['status'] ?? 'open';
        $response_strategy = $_POST['response_strategy'] ?? 'Mitigate';
        
        if (empty($title)) {
            $_SESSION['error'] = 'Mitigation title is required';
            header("Location: risks.php?id={$risk_id}");
            exit;
        }
        
        $check_stmt = $conn->prepare("SELECT owner_user_id, created_by FROM risk_mitigations WHERE id = ?");
        $check_stmt->bind_param('i', $mitigation_id);
        $check_stmt->execute();
        $mit_data = $check_stmt->get_result()->fetch_assoc();
        $check_stmt->close();
        
        $can_update = false;
        if ($user_role === 'super_admin') $can_update = true;
        elseif ($user_role === 'pm_manager') $can_update = true;
        elseif ($mit_data['owner_user_id'] == $current_user_id || $mit_data['created_by'] == $current_user_id) {
            $can_update = true;
        }
        
        if (!$can_update) {
            $_SESSION['error'] = 'Permission denied: You cannot update this mitigation action';
            header("Location: risks.php?id={$risk_id}");
            exit;
        }
        
        $sql = "UPDATE risk_mitigations SET title = ?, description = ?, owner_user_id = ?, due_date = ?, status = ?, response_strategy = ?, updated_at = NOW() WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ssisssi', $title, $description, $owner_user_id, $due_date, $status, $response_strategy, $mitigation_id);
        
        if ($stmt->execute()) {
            $hist_sql = "INSERT INTO risk_history (risk_id, changed_by, change_type, comment, created_at) 
                        VALUES (?, ?, 'mitigation_updated', ?, NOW())";
            $hstmt = $conn->prepare($hist_sql);
            $comment = "Mitigation '{$title}' updated by {$username}";
            $hstmt->bind_param('iis', $risk_id, $current_user_id, $comment);
            $hstmt->execute();
            $hstmt->close();
            
            $_SESSION['success'] = '✅ Mitigation updated successfully';
        } else {
            $_SESSION['error'] = 'Error updating mitigation: ' . $conn->error;
        }
        
        $stmt->close();
        header("Location: risks.php?id={$risk_id}#mitigations");
        exit;
    }
    
    // =========================================
    // DELETE MITIGATION
    // =========================================
    if ($_POST['action'] === 'delete_mitigation') {
        $mitigation_id = (int)($_POST['mitigation_id'] ?? 0);
        $risk_id = (int)($_POST['risk_id'] ?? 0);
        
        $check_stmt = $conn->prepare("SELECT title, created_by FROM risk_mitigations WHERE id = ?");
        $check_stmt->bind_param('i', $mitigation_id);
        $check_stmt->execute();
        $mit_data = $check_stmt->get_result()->fetch_assoc();
        $check_stmt->close();
        
        $can_delete = false;
        if ($user_role === 'super_admin') $can_delete = true;
        elseif ($user_role === 'pm_manager') $can_delete = true;
        elseif ($mit_data['created_by'] == $current_user_id) $can_delete = true;
        
        if (!$can_delete) {
            $_SESSION['error'] = 'Permission denied: You cannot delete this mitigation action';
            header("Location: risks.php?id={$risk_id}");
            exit;
        }
        
        $sql = "DELETE FROM risk_mitigations WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $mitigation_id);
        
        if ($stmt->execute()) {
            $hist_sql = "INSERT INTO risk_history (risk_id, changed_by, change_type, comment, created_at) 
                        VALUES (?, ?, 'mitigation_deleted', ?, NOW())";
            $hstmt = $conn->prepare($hist_sql);
            $comment = "Mitigation '{$mit_data['title']}' deleted by {$username}";
            $hstmt->bind_param('iis', $risk_id, $current_user_id, $comment);
            $hstmt->execute();
            $hstmt->close();
            
            $_SESSION['success'] = '🗑️ Mitigation deleted successfully';
        } else {
            $_SESSION['error'] = 'Error deleting mitigation: ' . $conn->error;
        }
        
        $stmt->close();
        header("Location: risks.php?id={$risk_id}#mitigations");
        exit;
    }
    
    // =========================================
    // ADD COMMENT - Preserve existing
    // =========================================
    if ($_POST['action'] === 'add_comment') {
        $risk_id = (int)($_POST['risk_id'] ?? 0);
        $comment_text = trim($_POST['comment_text'] ?? '');
        
        if (empty($comment_text)) {
            $_SESSION['error'] = 'Comment cannot be empty';
            header("Location: risks.php?id={$risk_id}");
            exit;
        }
        
        $conn->begin_transaction();
        try {
            $sql = "INSERT INTO risk_comments (risk_id, user_id, comment_text, created_at) VALUES (?, ?, ?, NOW())";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('iis', $risk_id, $current_user_id, $comment_text);
            $stmt->execute();
            $stmt->close();
            
            $hist_sql = "INSERT INTO risk_history (risk_id, changed_by, change_type, comment, created_at) 
                        VALUES (?, ?, 'comment_added', ?, NOW())";
            $hstmt = $conn->prepare($hist_sql);
            $comment_log = "Comment added by {$username}: " . substr($comment_text, 0, 50) . (strlen($comment_text) > 50 ? '...' : '');
            $hstmt->bind_param('iis', $risk_id, $current_user_id, $comment_log);
            $hstmt->execute();
            $hstmt->close();
            
            $conn->commit();
            $_SESSION['success'] = '💬 Comment added successfully';
            header("Location: risks.php?id={$risk_id}#comments");
            exit;
            
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error'] = 'Error: ' . $e->getMessage();
            header("Location: risks.php?id={$risk_id}");
            exit;
        }
    }
}

// =============================================
// GET UNREAD NOTIFICATIONS COUNT
// =============================================
$unread_stmt = $conn->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
$unread_stmt->bind_param('i', $current_user_id);
$unread_stmt->execute();
$unread_count = $unread_stmt->get_result()->fetch_assoc()['count'];
$unread_stmt->close();

// Get recent notifications
$notif_stmt = $conn->prepare("SELECT n.*, u.username as related_username, p.name as project_name
                             FROM notifications n
                             LEFT JOIN users u ON n.related_user_id = u.id
                             LEFT JOIN projects p ON n.related_id = p.id AND n.related_module = 'project'
                             WHERE n.user_id = ? 
                             ORDER BY n.created_at DESC 
                             LIMIT 8");
$notif_stmt->bind_param('i', $current_user_id);
$notif_stmt->execute();
$recent_notifications = $notif_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$notif_stmt->close();

// =============================================
// FETCH RISKS FOR LIST VIEW
// =============================================
$filters = [];
$params = [];
$types = '';

// Filter by accessible projects only
if (!empty($accessible_project_ids)) {
    $placeholders = implode(',', array_fill(0, count($accessible_project_ids), '?'));
    $filters[] = "r.project_id IN ($placeholders)";
    foreach ($accessible_project_ids as $pid) {
        $params[] = $pid;
        $types .= 'i';
    }
} else {
    $filters[] = "1=0";
}

// Additional filters
$filter_project = isset($_GET['project_id']) && $_GET['project_id'] !== '' ? (int)$_GET['project_id'] : null;
if ($filter_project && in_array($filter_project, $accessible_project_ids)) {
    $filters[] = "r.project_id = ?"; 
    $params[] = $filter_project; 
    $types .= 'i';
}

$filter_status = isset($_GET['status_id']) && $_GET['status_id'] !== '' ? (int)$_GET['status_id'] : null;
if ($filter_status) {
    $filters[] = "r.status_id = ?"; 
    $params[] = $filter_status; 
    $types .= 'i';
}

$filter_owner = isset($_GET['owner_user_id']) && $_GET['owner_user_id'] !== '' ? (int)$_GET['owner_user_id'] : null;
if ($filter_owner) {
    $filters[] = "r.owner_user_id = ?"; 
    $params[] = $filter_owner; 
    $types .= 'i';
}

$filter_level = isset($_GET['risk_level']) && $_GET['risk_level'] !== '' ? $_GET['risk_level'] : null;
if ($filter_level) {
    $filters[] = "r.risk_level = ?";
    $params[] = $filter_level;
    $types .= 's';
}

$filter_search = isset($_GET['search']) && trim($_GET['search']) !== '' ? trim($_GET['search']) : null;
if ($filter_search) {
    $searchTerm = "%{$filter_search}%";
    $filters[] = "(r.title LIKE ? OR r.description LIKE ? OR p.name LIKE ?)";
    $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm]);
    $types .= 'sss';
}

$where_sql = count($filters) ? 'WHERE ' . implode(' AND ', $filters) : '';

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 12;
$offset = ($page - 1) * $limit;

// Count total risks
$count_sql = "SELECT COUNT(*) as total FROM risks r 
              LEFT JOIN projects p ON r.project_id = p.id 
              $where_sql";
$count_stmt = $conn->prepare($count_sql);
if (!empty($params)) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$total_risks = $count_stmt->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_risks / $limit);
$count_stmt->close();

// Fetch risks
$sql = "SELECT 
            r.*, 
            p.name AS project_name, 
            p.status AS project_status,
            owner.username AS owner_name,
            creator.username AS creator_name,
            rc.name AS category_name, 
            rs.label AS status_label,
            rs.status_key AS status_key,
            (SELECT COUNT(*) FROM risk_mitigations rm WHERE rm.risk_id = r.id) AS mitigation_count,
            (SELECT COUNT(*) FROM risk_comments rc2 WHERE rc2.risk_id = r.id) AS comment_count
        FROM risks r
        LEFT JOIN projects p ON r.project_id = p.id
        LEFT JOIN users owner ON r.owner_user_id = owner.id
        LEFT JOIN users creator ON r.created_by = creator.id
        LEFT JOIN risk_categories rc ON r.category_id = rc.id
        LEFT JOIN risk_statuses rs ON r.status_id = rs.id
        $where_sql
        ORDER BY 
            CASE 
                WHEN rs.status_key = 'pending_review' THEN 1
                WHEN r.risk_level = 'High' THEN 2
                WHEN r.risk_level = 'Medium' THEN 3
                WHEN r.risk_level = 'Low' THEN 4
                ELSE 5
            END,
            r.risk_score DESC,
            r.created_at DESC
        LIMIT ? OFFSET ?";

$stmt = $conn->prepare($sql);
$types_with_pagination = $types . 'ii';
$params_with_pagination = array_merge($params, [$limit, $offset]);
if (!empty($params_with_pagination)) {
    $stmt->bind_param($types_with_pagination, ...$params_with_pagination);
}
$stmt->execute();
$risks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Dashboard statistics
$stats = [
    'total' => $total_risks,
    'high' => 0,
    'medium' => 0,
    'low' => 0,
    'pending_review' => 0,
    'open' => 0,
    'in_progress' => 0,
    'mitigated' => 0,
    'closed' => 0,
    'rejected' => 0,
    'my_assigned' => 0,
    'overdue' => 0
];

$today_date = date('Y-m-d');

foreach ($risks as $r) {
    $level = $r['risk_level'] ?? 'Low';
    $stats[strtolower($level)]++;
    
    $status_key = $r['status_key'] ?? '';
    switch ($status_key) {
        case 'pending_review': $stats['pending_review']++; break;
        case 'open': $stats['open']++; break;
        case 'in_progress': $stats['in_progress']++; break;
        case 'mitigated': $stats['mitigated']++; break;
        case 'closed': $stats['closed']++; break;
        case 'rejected': $stats['rejected']++; break;
    }
    
    if ($r['owner_user_id'] == $current_user_id && !in_array($status_key, ['closed', 'rejected'])) {
        $stats['my_assigned']++;
        if ($r['target_resolution_date'] && $r['target_resolution_date'] < $today_date) {
            $stats['overdue']++;
        }
    }
}

// Get status IDs
$pending_review_status_id = get_status_id_by_key($conn, 'pending_review');
$open_status_id = get_status_id_by_key($conn, 'open');
$in_progress_status_id = get_status_id_by_key($conn, 'in_progress');
$mitigated_status_id = get_status_id_by_key($conn, 'mitigated');
$closed_status_id = get_status_id_by_key($conn, 'closed');
$rejected_status_id = get_status_id_by_key($conn, 'rejected');

// =============================================
// IF RISK ID PROVIDED, FETCH DETAILS
// =============================================
if ($risk_id) {
    $sql = "SELECT 
                r.*, 
                p.name AS project_name, 
                p.status AS project_status,
                p.id AS project_id,
                d.department_name, 
                owner.id AS owner_id,
                owner.username AS owner_name,
                owner.email AS owner_email,
                creator.id AS creator_id,
                creator.username AS creator_name,
                creator.email AS creator_email,
                creator.system_role AS creator_role,
                identifier.id AS identified_by_id,
                identifier.username AS identified_by_name,
                approver.id AS approved_by_id,
                approver.username AS approved_by_name,
                rc.id AS category_id,
                rc.name AS category_name, 
                rs.id AS status_id,
                rs.label AS status_label,
                rs.status_key AS status_key,
                (SELECT COUNT(*) FROM risk_mitigations rm WHERE rm.risk_id = r.id) AS mitigation_count,
                (SELECT COUNT(*) FROM risk_mitigations rm WHERE rm.risk_id = r.id AND rm.status = 'done') AS mitigated_completed_count,
                (SELECT COUNT(*) FROM risk_comments rc2 WHERE rc2.risk_id = r.id) AS comment_count,
                (SELECT COUNT(*) FROM risk_history rh WHERE rh.risk_id = r.id) AS history_count
            FROM risks r
            LEFT JOIN projects p ON r.project_id = p.id
            LEFT JOIN departments d ON r.department_id = d.id
            LEFT JOIN users owner ON r.owner_user_id = owner.id
            LEFT JOIN users creator ON r.created_by = creator.id
            LEFT JOIN users identifier ON r.identified_by = identifier.id
            LEFT JOIN users approver ON r.approved_by = approver.id
            LEFT JOIN risk_categories rc ON r.category_id = rc.id
            LEFT JOIN risk_statuses rs ON r.status_id = rs.id
            WHERE r.id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $risk_id);
    $stmt->execute();
    $risk = $stmt->get_result()->fetch_assoc();
    
    if (!$risk) {
        $_SESSION['error'] = 'Risk not found';
        header('Location: risks.php');
        exit;
    }
    
    // Check project access
    if (!has_project_access($conn, $current_user_id, $risk['project_id'], $user_role)) {
        $_SESSION['error'] = 'You do not have access to this risk';
        header('Location: risks.php');
        exit;
    }
    
    $is_terminated_project = ($risk['project_status'] === 'terminated');
    $score = (int)$risk['likelihood'] * (int)$risk['impact'];
    $risk_class = strtolower($risk['risk_level'] ?? 'low');
    $status_key = $risk['status_key'] ?? '';
    
    // Get project users for dropdowns
    $project_users = get_project_users($conn, $risk['project_id'], $user_role, $current_user_id);
    
    // Fetch mitigations
    $mit_stmt = $conn->prepare("SELECT 
                                    m.*, 
                                    u.username as owner_name,
                                    u.id as owner_user_id,
                                    creator.username as created_by_name,
                                    creator.id as created_by_id
                                FROM risk_mitigations m 
                                LEFT JOIN users u ON m.owner_user_id = u.id 
                                LEFT JOIN users creator ON m.created_by = creator.id
                                WHERE m.risk_id = ? 
                                ORDER BY 
                                    CASE 
                                        WHEN m.status = 'open' THEN 1
                                        WHEN m.status = 'in_progress' THEN 2
                                        WHEN m.status = 'done' THEN 3
                                        ELSE 4
                                    END, 
                                    m.due_date ASC");
    $mit_stmt->bind_param('i', $risk_id);
    $mit_stmt->execute();
    $mitigations = $mit_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $mit_stmt->close();
    
    // Fetch comments
    $comment_stmt = $conn->prepare("SELECT 
                                        c.*, 
                                        u.username as user_name,
                                        u.system_role as user_role
                                    FROM risk_comments c
                                    LEFT JOIN users u ON c.user_id = u.id
                                    WHERE c.risk_id = ? 
                                    ORDER BY c.created_at DESC");
    $comment_stmt->bind_param('i', $risk_id);
    $comment_stmt->execute();
    $comments = $comment_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $comment_stmt->close();
    
    // Fetch history
    $hist_stmt = $conn->prepare("SELECT 
                                    h.*, 
                                    u.username as changed_by_name,
                                    u.system_role as changed_by_role
                                FROM risk_history h 
                                LEFT JOIN users u ON h.changed_by = u.id 
                                WHERE h.risk_id = ? 
                                ORDER BY h.created_at DESC 
                                LIMIT 30");
    $hist_stmt->bind_param('i', $risk_id);
    $hist_stmt->execute();
    $history = $hist_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $hist_stmt->close();
    
    // Check if user can approve this risk
    $can_approve_this_risk = false;
    if ($status_key == 'pending_review') {
        if ($user_role === 'super_admin') {
            $can_approve_this_risk = true;
        } elseif ($user_role === 'pm_manager') {
            $creator_role_stmt = $conn->prepare("SELECT system_role FROM users WHERE id = ?");
            $creator_role_stmt->bind_param('i', $risk['created_by']);
            $creator_role_stmt->execute();
            $creator_role = $creator_role_stmt->get_result()->fetch_assoc()['system_role'] ?? '';
            $creator_role_stmt->close();
            
            if ($creator_role === 'pm_employee') {
                $can_approve_this_risk = true;
            }
        }
    }
    
    // Store risk in session for modals
    $_SESSION['current_risk'] = $risk;
}
?>
<!-- ============================================= -->
<!-- HTML CODE STARTS HERE - EXACTLY THE SAME AS YOUR ORIGINAL -->
<!-- Only the PHP logic above has been fixed -->
<!-- ============================================= -->

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $risk_id ? e($risk['title']) : 'Risk Register' ?> - Dashen Bank Risk Management</title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- AOS Animation -->
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    
    <style>
        /* ALL EXISTING STYLES - PRESERVED EXACTLY AS IN YOUR ORIGINAL CODE */
        :root {
            --dashen-primary: #273274;
            --dashen-primary-light: #3a47a3;
            --dashen-primary-dark: #1a1f4a;
            --dashen-secondary: #f8a01c;
            --dashen-secondary-light: #ffb44a;
            --dashen-secondary-dark: #d47e0a;
            --dashen-success: #28a745;
            --dashen-danger: #dc3545;
            --dashen-warning: #ffc107;
            --dashen-info: #17a2b8;
            --dashen-gray-100: #f8f9fa;
            --dashen-gray-200: #e9ecef;
            --dashen-gray-300: #dee2e6;
            --dashen-gray-400: #ced4da;
            --dashen-gray-500: #adb5bd;
            --dashen-gray-600: #6c757d;
            --dashen-gray-700: #495057;
            --dashen-gray-800: #343a40;
            --dashen-gray-900: #212529;
            --sidebar-width: 280px;
            --border-radius: 16px;
            --border-radius-sm: 12px;
            --border-radius-lg: 24px;
            --box-shadow: 0 20px 40px rgba(0, 0, 0, 0.03), 0 8px 16px rgba(39, 50, 116, 0.05);
            --box-shadow-hover: 0 30px 60px rgba(39, 50, 116, 0.12);
            --transition: all 0.3s cubic-bezier(0.165, 0.84, 0.44, 1);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: linear-gradient(145deg, #f4f7fc 0%, #eef2f8 100%);
            color: var(--dashen-gray-800);
            line-height: 1.6;
            overflow-x: hidden;
        }
        
        .main-content {
            margin-left: var(--sidebar-width);
            transition: var(--transition);
            min-height: 100vh;
            position: relative;
        }
        
        @media (max-width: 991.98px) {
            .main-content {
                margin-left: 0;
            }
        }
        
        .dashen-card {
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: var(--border-radius-lg);
            box-shadow: var(--box-shadow);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }
        
        .dashen-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--dashen-primary), var(--dashen-secondary));
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        
        .dashen-card:hover {
            transform: translateY(-6px);
            box-shadow: var(--box-shadow-hover);
            border-color: rgba(39, 50, 116, 0.1);
        }
        
        .dashen-card:hover::before {
            opacity: 1;
        }
        
        .dashen-gradient {
            background: linear-gradient(145deg, var(--dashen-primary), var(--dashen-primary-dark));
            color: white;
        }
        
        .dashen-gradient-secondary {
            background: linear-gradient(145deg, var(--dashen-secondary), var(--dashen-secondary-dark));
            color: white;
        }
        
        .dashen-btn {
            padding: 12px 28px;
            border-radius: 50px;
            font-weight: 600;
            font-size: 0.95rem;
            letter-spacing: 0.5px;
            transition: var(--transition);
            border: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        
        .dashen-btn-primary {
            background: linear-gradient(145deg, var(--dashen-primary), var(--dashen-primary-dark));
            color: white;
            box-shadow: 0 8px 20px rgba(39, 50, 116, 0.3);
        }
        
        .dashen-btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 30px rgba(39, 50, 116, 0.4);
            color: white;
        }
        
        .dashen-btn-secondary {
            background: linear-gradient(145deg, var(--dashen-secondary), var(--dashen-secondary-dark));
            color: white;
            box-shadow: 0 8px 20px rgba(248, 160, 28, 0.3);
        }
        
        .dashen-btn-secondary:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 30px rgba(248, 160, 28, 0.4);
            color: white;
        }
        
        .dashen-btn-outline {
            background: transparent;
            border: 2px solid var(--dashen-primary);
            color: var(--dashen-primary);
        }
        
        .dashen-btn-outline:hover {
            background: var(--dashen-primary);
            color: white;
            transform: translateY(-3px);
        }
        
        .dashen-btn-danger {
            background: linear-gradient(145deg, var(--dashen-danger), #c82333);
            color: white;
            box-shadow: 0 8px 20px rgba(220, 53, 69, 0.3);
        }
        
        .dashen-btn-danger:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 30px rgba(220, 53, 69, 0.4);
            color: white;
        }
        
        .notification-bell {
            position: relative;
            cursor: pointer;
            transition: var(--transition);
        }
        
        .notification-bell:hover {
            transform: scale(1.1);
        }
        
        .notification-badge {
            position: absolute;
            top: -8px;
            right: -8px;
            background: linear-gradient(145deg, var(--dashen-danger), #c82333);
            color: white;
            border-radius: 50px;
            padding: 4px 8px;
            font-size: 0.7rem;
            font-weight: 700;
            border: 2px solid white;
            box-shadow: 0 4px 12px rgba(220, 53, 69, 0.4);
            animation: pulse-badge 2s infinite;
        }
        
        @keyframes pulse-badge {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }
        
        .notification-dropdown {
            width: 420px;
            max-height: 550px;
            overflow-y: auto;
            border: none;
            border-radius: var(--border-radius-lg);
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.15);
            padding: 0;
        }
        
        .notification-header {
            background: linear-gradient(145deg, var(--dashen-primary), var(--dashen-primary-dark));
            color: white;
            padding: 1.2rem 1.5rem;
            border-radius: var(--border-radius-lg) var(--border-radius-lg) 0 0;
        }
        
        .notification-item {
            padding: 1.2rem 1.5rem;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            transition: var(--transition);
            position: relative;
        }
        
        .notification-item:hover {
            background: rgba(39, 50, 116, 0.02);
        }
        
        .notification-item.unread {
            background: rgba(39, 50, 116, 0.05);
            border-left: 4px solid var(--dashen-primary);
        }
        
        .notification-icon {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            color: white;
            flex-shrink: 0;
        }
        
        .notification-time {
            font-size: 0.7rem;
            color: var(--dashen-gray-600);
        }
        
        .notification-footer {
            background: var(--dashen-gray-100);
            padding: 1rem 1.5rem;
            border-radius: 0 0 var(--border-radius-lg) var(--border-radius-lg);
        }
        
        .risk-score-premium {
            width: 130px;
            height: 130px;
            border-radius: 30px;
            background: linear-gradient(145deg, var(--dashen-primary), var(--dashen-primary-dark));
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            color: white;
            box-shadow: 0 20px 40px rgba(39, 50, 116, 0.3);
            position: relative;
            overflow: hidden;
            transition: var(--transition);
        }
        
        .risk-score-premium:hover {
            transform: scale(1.05);
            box-shadow: 0 30px 60px rgba(39, 50, 116, 0.4);
        }
        
        .risk-score-premium::after {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.2) 0%, transparent 70%);
            animation: pulse 3s infinite;
        }
        
        @keyframes pulse {
            0% { transform: scale(1); opacity: 0.5; }
            50% { transform: scale(1.2); opacity: 0.2; }
            100% { transform: scale(1); opacity: 0.5; }
        }
        
        .risk-score-number {
            font-size: 3.2rem;
            font-weight: 800;
            line-height: 1;
            margin-bottom: 4px;
        }
        
        .risk-score-label {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 3px;
            opacity: 0.9;
            font-weight: 600;
        }
        
        .risk-badge {
            padding: 10px 24px;
            border-radius: 50px;
            font-size: 0.85rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: white;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            border: 1px solid rgba(0,0,0,0.05);
        }
        
        .badge-high { 
            background: linear-gradient(145deg, #fd7e14, #e96b02);
            color: white;
        }
        
        .badge-medium { 
            background: linear-gradient(145deg, #ffc107, #e0a800);
            color: #000;
        }
        
        .badge-low { 
            background: linear-gradient(145deg, #28a745, #218838);
            color: white;
        }
        
        .status-badge-premium {
            padding: 8px 20px;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: white;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            border: 1px solid rgba(0,0,0,0.05);
        }
        
        .status-pending-premium {
            background: linear-gradient(145deg, #fff3cd, #ffe69c);
            color: #856404;
            border-left: 4px solid #ffc107;
        }
        
        .status-open-premium {
            background: linear-gradient(145deg, #cce5ff, #b8daff);
            color: #004085;
            border-left: 4px solid #0d6efd;
        }
        
        .status-progress-premium {
            background: linear-gradient(145deg, #d1ecf1, #b6e4ed);
            color: #0c5460;
            border-left: 4px solid #17a2b8;
        }
        
        .status-mitigated-premium {
            background: linear-gradient(145deg, #d4edda, #c3e6cb);
            color: #155724;
            border-left: 4px solid #28a745;
        }
        
        .status-closed-premium {
            background: linear-gradient(145deg, #e2e3e5, #d6d8db);
            color: #383d41;
            border-left: 4px solid #6c757d;
        }
        
        .status-rejected-premium {
            background: linear-gradient(145deg, #f8d7da, #f5c6cb);
            color: #721c24;
            border-left: 4px solid #dc3545;
        }
        
        .metric-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: 0 8px 24px rgba(0,0,0,0.02);
            border: 1px solid rgba(39, 50, 116, 0.05);
            transition: var(--transition);
        }
        
        .metric-card:hover {
            border-color: var(--dashen-primary);
            box-shadow: 0 16px 32px rgba(39, 50, 116, 0.08);
            transform: translateY(-4px);
        }
        
        .metric-icon {
            width: 56px;
            height: 56px;
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            background: linear-gradient(145deg, var(--dashen-primary), var(--dashen-primary-dark));
            color: white;
            box-shadow: 0 12px 24px rgba(39, 50, 116, 0.2);
        }
        
        .metric-value {
            font-size: 2.2rem;
            font-weight: 800;
            color: var(--dashen-primary);
            line-height: 1;
            margin-bottom: 4px;
        }
        
        .metric-label {
            font-size: 0.8rem;
            color: var(--dashen-gray-600);
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 600;
        }
        
        .risk-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 8px;
        }
        
        .risk-table tbody tr {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: 0 4px 12px rgba(0,0,0,0.02);
            transition: var(--transition);
            cursor: pointer;
            border-left: 4px solid transparent;
        }
        
        .risk-table tbody tr:hover {
            background: white;
            transform: translateX(6px);
            box-shadow: 0 12px 28px rgba(39, 50, 116, 0.12);
            border-left-color: var(--dashen-primary);
        }
        
        .risk-table td:first-child {
            border-top-left-radius: var(--border-radius);
            border-bottom-left-radius: var(--border-radius);
            padding-left: 1.5rem;
        }
        
        .risk-table td:last-child {
            border-top-right-radius: var(--border-radius);
            border-bottom-right-radius: var(--border-radius);
            padding-right: 1.5rem;
        }
        
        .heat-map {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 6px;
            padding: 16px;
            background: white;
            border-radius: var(--border-radius);
            box-shadow: 0 4px 12px rgba(0,0,0,0.02);
        }
        
        .heat-cell {
            aspect-ratio: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.85rem;
            font-weight: 600;
            border-radius: 12px;
            background: #f8f9fa;
            transition: var(--transition);
        }
        
        .heat-cell.active {
            background: linear-gradient(145deg, var(--dashen-primary), var(--dashen-primary-dark));
            color: white;
            transform: scale(0.95);
            box-shadow: 0 8px 16px rgba(39, 50, 116, 0.3);
        }
        
        .heat-cell.low { background: rgba(40, 167, 69, 0.1); color: #155724; }
        .heat-cell.medium { background: rgba(255, 193, 7, 0.1); color: #856404; }
        .heat-cell.high { background: rgba(253, 126, 20, 0.1); color: #8a4e0c; }
        .heat-cell.critical { background: rgba(220, 53, 69, 0.1); color: #721c24; }
        
        .heat-cell.active.low { background: linear-gradient(145deg, #28a745, #218838); }
        .heat-cell.active.medium { background: linear-gradient(145deg, #ffc107, #e0a800); color: #000; }
        .heat-cell.active.high { background: linear-gradient(145deg, #fd7e14, #e96b02); }
        .heat-cell.active.critical { background: linear-gradient(145deg, #dc3545, #c82333); }
        
        .timeline {
            position: relative;
            padding-left: 30px;
        }
        
        .timeline::before {
            content: '';
            position: absolute;
            left: 12px;
            top: 8px;
            bottom: 8px;
            width: 2px;
            background: linear-gradient(to bottom, var(--dashen-primary), var(--dashen-secondary));
            opacity: 0.3;
        }
        
        .timeline-item {
            position: relative;
            padding-bottom: 24px;
        }
        
        .timeline-marker {
            position: absolute;
            left: -30px;
            top: 6px;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: var(--dashen-primary);
            border: 4px solid white;
            box-shadow: 0 0 0 3px rgba(39, 50, 116, 0.1);
        }
        
        .timeline-content {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.2rem 1.5rem;
            border: 1px solid rgba(0,0,0,0.03);
            transition: var(--transition);
        }
        
        .timeline-content:hover {
            transform: translateX(8px);
            border-color: var(--dashen-primary);
            box-shadow: 0 12px 28px rgba(39, 50, 116, 0.08);
        }
        
        .comment-avatar-premium {
            width: 56px;
            height: 56px;
            border-radius: 18px;
            background: linear-gradient(145deg, var(--dashen-primary), var(--dashen-primary-dark));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            font-weight: 700;
            box-shadow: 0 12px 24px rgba(39, 50, 116, 0.2);
            flex-shrink: 0;
        }
        
        .comment-bubble {
            background: #f8fafd;
            border-radius: 24px 24px 24px 6px;
            padding: 1.2rem 1.5rem;
            position: relative;
            border: 1px solid rgba(39, 50, 116, 0.05);
            box-shadow: 0 4px 12px rgba(0,0,0,0.02);
        }
        
        .comment-bubble::before {
            content: '';
            position: absolute;
            left: -12px;
            top: 20px;
            width: 24px;
            height: 24px;
            background: #f8fafd;
            border-left: 1px solid rgba(39, 50, 116, 0.05);
            border-bottom: 1px solid rgba(39, 50, 116, 0.05);
            transform: rotate(45deg);
        }
        
        .progress-premium {
            height: 10px;
            border-radius: 10px;
            background: rgba(0,0,0,0.05);
            overflow: hidden;
            margin: 12px 0;
        }
        
        .progress-bar-premium {
            background: linear-gradient(90deg, var(--dashen-primary), var(--dashen-secondary));
            border-radius: 10px;
            position: relative;
            background-size: 200% 100%;
            animation: shimmer 2s infinite;
        }
        
        @keyframes shimmer {
            0% { background-position: 0% 0%; }
            100% { background-position: 200% 0%; }
        }
        
        .floating-action-btn {
            position: fixed;
            bottom: 30px;
            right: 30px;
            z-index: 1000;
            width: 64px;
            height: 64px;
            border-radius: 50%;
            background: linear-gradient(145deg, var(--dashen-primary), var(--dashen-primary-dark));
            color: white;
            border: none;
            box-shadow: 0 12px 30px rgba(39, 50, 116, 0.4);
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
        }
        
        .floating-action-btn:hover {
            transform: scale(1.1) rotate(90deg);
            box-shadow: 0 18px 40px rgba(39, 50, 116, 0.6);
            color: white;
        }
        
        .modal-content-premium {
            border: none;
            border-radius: 32px;
            box-shadow: 0 30px 60px rgba(0, 0, 0, 0.2);
        }
        
        .modal-header-premium {
            background: linear-gradient(145deg, var(--dashen-primary), var(--dashen-primary-dark));
            color: white;
            border: none;
            border-radius: 32px 32px 0 0;
            padding: 1.5rem 2rem;
        }
        
        .card-radio {
            display: flex;
            flex-direction: column;
            height: 100%;
        }
        
        .card-radio input[type="radio"] {
            display: none;
        }
        
        .card-radio input[type="radio"]:checked + label {
            background: linear-gradient(145deg, var(--dashen-primary), var(--dashen-primary-dark));
            color: white;
            border-color: var(--dashen-primary);
        }
        
        .card-radio label {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 1.2rem 0.5rem;
            border-radius: 16px;
            border: 2px solid transparent;
            transition: var(--transition);
            cursor: pointer;
            height: 100%;
        }
        
        .card-radio label:hover {
            border-color: var(--dashen-primary);
            background: rgba(39, 50, 116, 0.05);
        }
        
        .btn-mitigation {
            transition: var(--transition);
            border-radius: 50px;
            padding: 8px 16px;
            font-size: 0.8rem;
        }
        
        .btn-mitigation:hover {
            transform: translateY(-2px);
        }
        
        .btn-mitigation-delete {
            color: var(--dashen-danger);
            border-color: var(--dashen-danger);
        }
        
        .btn-mitigation-delete:hover {
            background: var(--dashen-danger);
            color: white;
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .animate-on-load {
            animation: fadeInUp 0.6s ease forwards;
        }
        
        .bg-dashen-primary { background: var(--dashen-primary); }
        .bg-dashen-secondary { background: var(--dashen-secondary); }
        .text-dashen-primary { color: var(--dashen-primary) !important; }
        .text-dashen-secondary { color: var(--dashen-secondary) !important; }
        .border-dashen { border-color: var(--dashen-primary) !important; }
        
        .no-click {
            pointer-events: none;
            opacity: 0.7;
        }
        
        .created-by-badge {
            font-size: 0.7rem;
            padding: 4px 10px;
            border-radius: 50px;
            background: rgba(39, 50, 116, 0.1);
            color: var(--dashen-primary);
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
    </style>
</head>
<body>
    <!-- Include Sidebar -->
    <?php include 'sidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Top Navigation Bar with Notification Center -->
        <nav class="navbar navbar-expand-lg bg-white shadow-sm px-4 py-3 sticky-top" style="backdrop-filter: blur(10px); background: rgba(255,255,255,0.95) !important;">
            <div class="container-fluid p-0">
                <div class="d-flex align-items-center">
                    <button class="btn btn-link d-lg-none me-3" type="button" onclick="toggleSidebar()">
                        <i class="bi bi-list fs-2" style="color: var(--dashen-primary);"></i>
                    </button>
                    <div>
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <span class="badge bg-dashen-primary px-3 py-2 rounded-pill">
                                <i class="bi bi-shield-shaded me-1"></i>
                                <?= $risk_id ? 'Risk ID: #' . str_pad($risk_id, 5, '0', STR_PAD_LEFT) : 'Risk Register' ?>
                            </span>
                            <?php if ($risk_id && isset($is_terminated_project) && $is_terminated_project): ?>
                                <span class="badge bg-danger px-3 py-2 rounded-pill">
                                    <i class="bi bi-exclamation-triangle me-1"></i>PROJECT TERMINATED
                                </span>
                            <?php endif; ?>
                        </div>
                        <h4 class="mb-0 fw-bold" style="color: var(--dashen-primary);">
                            <?= $risk_id ? e($risk['title']) : 'Project Risk Management' ?>
                        </h4>
                    </div>
                </div>
                
                <div class="d-flex align-items-center gap-3">
                    <!-- Notification Center -->
                    <div class="dropdown">
                        <div class="notification-bell" id="notificationDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-bell-fill fs-3" style="color: var(--dashen-primary);"></i>
                            <?php if ($unread_count > 0): ?>
                                <span class="notification-badge"><?= $unread_count > 99 ? '99+' : $unread_count ?></span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="dropdown-menu dropdown-menu-end notification-dropdown" aria-labelledby="notificationDropdown">
                            <div class="notification-header d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="mb-0 fw-bold"><i class="bi bi-bell-fill me-2"></i>Notifications</h6>
                                    <small class="text-white-50"><?= $unread_count ?> unread</small>
                                </div>
                                <?php if ($unread_count > 0): ?>
                                    <a href="?action=mark_all_read" class="text-white small text-decoration-none">
                                        Mark all as read
                                    </a>
                                <?php endif; ?>
                            </div>
                            
                            <div style="max-height: 400px; overflow-y: auto;">
                                <?php if (!empty($recent_notifications)): ?>
                                    <?php foreach ($recent_notifications as $notif): ?>
                                        <a href="?action=mark_read&notification_id=<?= $notif['id'] ?>&redirect=<?= urlencode($notif['related_module'] == 'risk' && $notif['related_id'] ? 'risks.php?id=' . $notif['related_id'] : 'risks.php') ?>" 
                                           class="text-decoration-none text-dark">
                                            <div class="notification-item <?= $notif['is_read'] ? '' : 'unread' ?>">
                                                <div class="d-flex gap-3">
                                                    <div class="notification-icon" style="background: <?php
                                                        if ($notif['type'] == 'success') echo 'linear-gradient(145deg, #28a745, #218838)';
                                                        elseif ($notif['type'] == 'danger') echo 'linear-gradient(145deg, #dc3545, #c82333)';
                                                        elseif ($notif['type'] == 'warning') echo 'linear-gradient(145deg, #ffc107, #e0a800)';
                                                        else echo 'linear-gradient(145deg, var(--dashen-primary), var(--dashen-primary-dark))';
                                                    ?>;">
                                                        <?php
                                                        if ($notif['type'] == 'success') echo '<i class="bi bi-check-circle-fill"></i>';
                                                        elseif ($notif['type'] == 'danger') echo '<i class="bi bi-exclamation-triangle-fill"></i>';
                                                        elseif ($notif['type'] == 'warning') echo '<i class="bi bi-exclamation-circle-fill"></i>';
                                                        else echo '<i class="bi bi-bell-fill"></i>';
                                                        ?>
                                                    </div>
                                                    <div class="flex-grow-1">
                                                        <div class="d-flex justify-content-between align-items-start">
                                                            <h6 class="mb-1 fw-semibold small"><?= e($notif['title']) ?></h6>
                                                            <span class="notification-time"><?= time_elapsed_string($notif['created_at'], true) ?></span>
                                                        </div>
                                                        <p class="mb-1 small text-muted" style="white-space: normal;">
                                                            <?= e($notif['message']) ?>
                                                        </p>
                                                        <?php if ($notif['related_username']): ?>
                                                            <small class="text-dashen-primary">
                                                                <i class="bi bi-person-circle me-1"></i><?= e($notif['related_username']) ?>
                                                            </small>
                                                        <?php endif; ?>
                                                        <?php if ($notif['project_name']): ?>
                                                            <small class="text-muted ms-2">
                                                                <i class="bi bi-folder me-1"></i><?= e($notif['project_name']) ?>
                                                            </small>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </a>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="text-center py-5">
                                        <i class="bi bi-bell-slash text-muted display-4"></i>
                                        <p class="text-muted mt-3 mb-0 fw-semibold">No notifications</p>
                                        <small class="text-muted">You're all caught up!</small>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="notification-footer text-center">
                                <a href="notifications.php" class="text-decoration-none fw-semibold" style="color: var(--dashen-primary);">
                                    View All Notifications <i class="bi bi-arrow-right ms-1"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <!-- User Profile -->
                    <div class="d-none d-md-flex align-items-center gap-2">
                        <div class="bg-light rounded-pill px-4 py-2">
                            <div class="d-flex align-items-center gap-2">
                                <div class="bg-dashen-primary text-white rounded-circle d-flex align-items-center justify-content-center" 
                                     style="width: 36px; height: 36px;">
                                    <span class="fw-bold"><?= strtoupper(substr($username, 0, 1)) ?></span>
                                </div>
                                <div>
                                    <span class="fw-medium small"><?= e($username) ?></span>
                                    <span class="badge bg-dashen-primary bg-opacity-10 text-dashen-primary ms-2 px-3 py-1 rounded-pill small">
                                        <?= ucwords(str_replace('_', ' ', $user_role)) ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <?php if ($risk_id): ?>
                        <a href="risks.php" class="btn dashen-btn-outline rounded-pill px-4">
                            <i class="bi bi-arrow-left me-2"></i>Back to List
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </nav>

        <div class="container-fluid py-4 px-4">
            <!-- Status Messages -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show animate__animated animate__fadeInDown rounded-4 shadow-sm border-0" role="alert" style="background: linear-gradient(145deg, #d4edda, #c3e6cb); color: #155724;">
                    <div class="d-flex align-items-center">
                        <div class="bg-success text-white rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 40px; height: 40px;">
                            <i class="bi bi-check-lg fs-4"></i>
                        </div>
                        <div class="flex-grow-1">
                            <strong class="fw-bold">Success!</strong> <?= e($_SESSION['success']) ?>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                </div>
                <?php unset($_SESSION['success']); ?>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show animate__animated animate__fadeInDown rounded-4 shadow-sm border-0" role="alert" style="background: linear-gradient(145deg, #f8d7da, #f5c6cb); color: #721c24;">
                    <div class="d-flex align-items-center">
                        <div class="bg-danger text-white rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 40px; height: 40px;">
                            <i class="bi bi-exclamation-triangle fs-4"></i>
                        </div>
                        <div class="flex-grow-1">
                            <strong class="fw-bold">Error!</strong> <?= e($_SESSION['error']) ?>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                </div>
                <?php unset($_SESSION['error']); ?>
            <?php endif; ?>

            <?php if ($risk_id && isset($risk)): ?>
                <!-- ========================================= -->
                <!-- RISK DETAILS VIEW - WITH APPROVAL WORKFLOW UI -->
                <!-- ========================================= -->
                
                <!-- Risk Header Section -->
                <div class="row g-4 mb-4" data-aos="fade-up">
                    <div class="col-lg-8">
                        <div class="dashen-card p-4 h-100">
                            <div class="row g-4 align-items-center">
                                <div class="col-md-4 text-center">
                                    <div class="risk-score-premium mx-auto">
                                        <span class="risk-score-number"><?= $score ?></span>
                                        <span class="risk-score-label">RISK SCORE</span>
                                    </div>
                                    <div class="mt-3">
                                        <span class="risk-badge badge-<?= $risk_class ?>">
                                            <i class="bi bi-shield-fill me-2"></i><?= e($risk['risk_level'] ?? 'Low') ?> Risk
                                        </span>
                                    </div>
                                </div>
                                <div class="col-md-8">
                                    <div class="d-flex flex-column h-100 justify-content-center">
                                        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                                            <span class="status-badge-premium <?php
                                                if ($status_key == 'pending_review') echo 'status-pending-premium';
                                                elseif ($status_key == 'open') echo 'status-open-premium';
                                                elseif ($status_key == 'in_progress') echo 'status-progress-premium';
                                                elseif ($status_key == 'mitigated') echo 'status-mitigated-premium';
                                                elseif ($status_key == 'closed') echo 'status-closed-premium';
                                                elseif ($status_key == 'rejected') echo 'status-rejected-premium';
                                            ?>">
                                                <i class="bi <?php
                                                    if ($status_key == 'pending_review') echo 'bi-hourglass-split';
                                                    elseif ($status_key == 'open') echo 'bi-check-circle';
                                                    elseif ($status_key == 'in_progress') echo 'bi-arrow-repeat';
                                                    elseif ($status_key == 'mitigated') echo 'bi-shield-check';
                                                    elseif ($status_key == 'closed') echo 'bi-check2-circle';
                                                    elseif ($status_key == 'rejected') echo 'bi-x-circle';
                                                ?>"></i>
                                                <?= e($risk['status_label'] ?? 'No Status') ?>
                                            </span>
                                            
                                            <!-- Created By Badge -->
                                            <span class="created-by-badge">
                                                <i class="bi bi-pencil-fill"></i>
                                                Created by: <?= e($risk['creator_name'] ?? 'System') ?>
                                                (<?= ucwords(str_replace('_', ' ', $risk['creator_role'] ?? 'Unknown')) ?>)
                                            </span>
                                            
                                            <?php if ($risk['approved_by_name']): ?>
                                            <span class="created-by-badge bg-success text-white" style="background: rgba(40, 167, 69, 0.1) !important; color: #28a745 !important;">
                                                <i class="bi bi-check-circle-fill"></i>
                                                Approved by: <?= e($risk['approved_by_name']) ?>
                                            </span>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="row g-3">
                                            <div class="col-sm-6">
                                                <div class="d-flex align-items-center gap-2">
                                                    <i class="bi bi-tag-fill text-dashen-primary"></i>
                                                    <span class="text-muted small">Category:</span>
                                                    <span class="fw-medium small"><?= e($risk['category_name'] ?? 'Uncategorized') ?></span>
                                                </div>
                                            </div>
                                            <div class="col-sm-6">
                                                <div class="d-flex align-items-center gap-2">
                                                    <i class="bi bi-person-fill text-dashen-primary"></i>
                                                    <span class="text-muted small">Owner:</span>
                                                    <?php if ($risk['owner_name']): ?>
                                                        <span class="fw-medium small"><?= e($risk['owner_name']) ?></span>
                                                        <?php if ($risk['owner_email'] && !$is_terminated_project): ?>
                                                            <a href="mailto:<?= e($risk['owner_email']) ?>" class="text-dashen-primary">
                                                                <i class="bi bi-envelope"></i>
                                                            </a>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="text-muted fst-italic small">Unassigned</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="col-sm-6">
                                                <div class="d-flex align-items-center gap-2">
                                                    <i class="bi bi-person-badge-fill text-dashen-primary"></i>
                                                    <span class="text-muted small">Identified:</span>
                                                    <span class="fw-medium small"><?= e($risk['identified_by_name'] ?? $risk['creator_name'] ?? 'System') ?></span>
                                                </div>
                                            </div>
                                            <div class="col-sm-6">
                                                <div class="d-flex align-items-center gap-2">
                                                    <i class="bi bi-calendar-fill text-dashen-primary"></i>
                                                    <span class="text-muted small">Created:</span>
                                                    <span class="fw-medium small"><?= date('M j, Y', strtotime($risk['created_at'])) ?></span>
                                                    <span class="badge bg-light text-dark px-2 py-1 rounded-pill small"><?= floor((time() - strtotime($risk['created_at'])) / (60*60*24)) ?> days ago</span>
                                                </div>
                                            </div>
                                            <?php if ($risk['approved_at']): ?>
                                            <div class="col-sm-6">
                                                <div class="d-flex align-items-center gap-2">
                                                    <i class="bi bi-check-circle-fill text-success"></i>
                                                    <span class="text-muted small">Approved:</span>
                                                    <span class="fw-medium small"><?= date('M j, Y', strtotime($risk['approved_at'])) ?></span>
                                                    <span class="badge bg-success bg-opacity-10 text-success px-2 py-1 rounded-pill small">By: <?= e($risk['approved_by_name'] ?? 'System') ?></span>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                            <?php if ($risk['rejection_reason']): ?>
                                            <div class="col-12">
                                                <div class="d-flex align-items-start gap-2">
                                                    <i class="bi bi-x-circle-fill text-danger mt-1"></i>
                                                    <div>
                                                        <span class="text-muted small">Rejection Reason:</span>
                                                        <span class="fw-medium small text-danger"><?= e($risk['rejection_reason']) ?></span>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                            
                                            <!-- Display Response Strategy -->
                                            <?php if ($risk['response_strategy']): ?>
                                            <div class="col-12">
                                                <div class="d-flex align-items-center gap-2">
                                                    <i class="bi bi-shield-fill-check text-success"></i>
                                                    <span class="text-muted small">Response Strategy:</span>
                                                    <span class="fw-medium small badge bg-success bg-opacity-10 text-success px-3 py-2"><?= e($risk['response_strategy']) ?></span>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                            
                                            <!-- Display Target Resolution Date -->
                                            <?php if ($risk['target_resolution_date']): ?>
                                            <div class="col-12">
                                                <div class="d-flex align-items-center gap-2">
                                                    <i class="bi bi-calendar-check-fill text-info"></i>
                                                    <span class="text-muted small">Target Resolution:</span>
                                                    <span class="fw-medium small badge bg-info bg-opacity-10 text-info px-3 py-2"><?= date('M j, Y', strtotime($risk['target_resolution_date'])) ?></span>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Quick Stats Cards -->
                    <div class="col-lg-4">
                        <div class="row g-3">
                            <div class="col-6">
                                <div class="metric-card" data-aos="fade-up" data-aos-delay="100">
                                    <div class="d-flex align-items-center gap-3 mb-2">
                                        <div class="metric-icon" style="background: linear-gradient(145deg, #6f42c1, #6610f2);">
                                            <i class="bi bi-shield-check"></i>
                                        </div>
                                        <div>
                                            <div class="metric-value"><?= $risk['mitigation_count'] ?? 0 ?></div>
                                            <div class="metric-label">Total Actions</div>
                                        </div>
                                    </div>
                                    <?php $mit_progress = ($risk['mitigation_count'] ?? 0) > 0 ? round(($risk['mitigated_completed_count'] ?? 0) / $risk['mitigation_count'] * 100) : 0; ?>
                                    <div class="progress-premium">
                                        <div class="progress-bar-premium" style="width: <?= $mit_progress ?>%;"></div>
                                    </div>
                                    <div class="d-flex justify-content-between mt-2">
                                        <small class="text-muted"><?= $risk['mitigated_completed_count'] ?? 0 ?> Completed</small>
                                        <small class="text-muted fw-bold"><?= $mit_progress ?>%</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="metric-card" data-aos="fade-up" data-aos-delay="150">
                                    <div class="d-flex align-items-center gap-3 mb-2">
                                        <div class="metric-icon" style="background: linear-gradient(145deg, #17a2b8, #0dcaf0);">
                                            <i class="bi bi-chat-dots"></i>
                                        </div>
                                        <div>
                                            <div class="metric-value"><?= count($comments ?? []) ?></div>
                                            <div class="metric-label">Comments</div>
                                        </div>
                                    </div>
                                    <div class="mt-3">
                                        <div class="d-flex align-items-center">
                                            <i class="bi bi-clock-history text-muted me-2"></i>
                                            <small class="text-muted">Last: <?= !empty($history) ? time_elapsed_string($history[0]['created_at']) : 'None' ?></small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php if ($risk['target_resolution_date']): ?>
                            <div class="col-12" data-aos="fade-up" data-aos-delay="200">
                                <div class="metric-card">
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="metric-icon" style="background: linear-gradient(145deg, var(--dashen-secondary), var(--dashen-secondary-dark));">
                                            <i class="bi bi-calendar-check"></i>
                                        </div>
                                        <div>
                                            <div class="metric-value small" style="font-size: 1.4rem;"><?= date('M j, Y', strtotime($risk['target_resolution_date'])) ?></div>
                                            <div class="metric-label">Target Resolution Date</div>
                                            <?php 
                                            $days_until_target = null;
                                            if ($risk['target_resolution_date'] && !$is_terminated_project) {
                                                $days_until_target = floor((strtotime($risk['target_resolution_date']) - time()) / (60*60*24));
                                                if ($days_until_target > 0): ?>
                                                    <span class="badge bg-info mt-2 px-3 py-2"><?= $days_until_target ?> days remaining</span>
                                                <?php elseif ($days_until_target == 0): ?>
                                                    <span class="badge bg-warning mt-2 px-3 py-2">Due today</span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger mt-2 px-3 py-2"><?= abs($days_until_target) ?> days overdue</span>
                                                <?php endif; 
                                            } ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- SRS SECTION 3.1.2 - RISK APPROVAL SECTION -->
                <?php if ($status_key == 'pending_review' && $can_approve_this_risk && !$is_terminated_project): ?>
                <div class="dashen-card p-4 mb-4" style="background: linear-gradient(145deg, rgba(255,243,205,0.7), rgba(255,230,156,0.5)); border-left: 6px solid #ffc107;" data-aos="fade-up">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <div class="d-flex align-items-center gap-4">
                                <div class="bg-warning rounded-3 p-3" style="box-shadow: 0 12px 24px rgba(255,193,7,0.3);">
                                    <i class="bi bi-hourglass-split text-white fs-1"></i>
                                </div>
                                <div>
                                    <h4 class="fw-bold mb-2" style="color: #856404;">Risk Pending Review</h4>
                                    <p class="text-muted mb-1">This risk requires your approval before it can be actively managed.</p>
                                    <div class="d-flex align-items-center gap-3 mt-2">
                                        <span class="badge bg-light text-dark px-3 py-2 rounded-pill">
                                            <i class="bi bi-person me-1"></i>Reported by: <?= e($risk['identified_by_name'] ?? $risk['creator_name'] ?? 'Unknown') ?>
                                            (<?= ucwords(str_replace('_', ' ', $risk['creator_role'] ?? 'Unknown')) ?>)
                                        </span>
                                        <span class="badge bg-light text-dark px-3 py-2 rounded-pill">
                                            <i class="bi bi-calendar me-1"></i><?= date('M j, Y', strtotime($risk['created_at'])) ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="d-flex gap-3 justify-content-md-end mt-4 mt-md-0">
                                <button class="btn dashen-btn-success px-4 py-3 rounded-pill" onclick="approveRisk(<?= $risk_id ?>, true)">
                                    <i class="bi bi-check-circle-fill me-2"></i>Approve
                                </button>
                                <button class="btn dashen-btn-danger px-4 py-3 rounded-pill" onclick="approveRisk(<?= $risk_id ?>, false)">
                                    <i class="bi bi-x-circle-fill me-2"></i>Reject
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Risk Assessment Matrix -->
                <div class="row g-4 mb-4">
                    <div class="col-lg-7">
                        <div class="dashen-card p-4 h-100" data-aos="fade-up" data-aos-delay="100">
                            <div class="d-flex justify-content-between align-items-center mb-4">
                                <h5 class="fw-bold mb-0" style="color: var(--dashen-primary);">
                                    <i class="bi bi-grid-3x3-gap-fill me-2"></i>Risk Assessment Matrix
                                </h5>
                                <?php if (in_array($user_role, ['super_admin', 'pm_manager']) && $status_key != 'closed' && $status_key != 'rejected' && !$is_terminated_project): ?>
                                <button class="btn dashen-btn-outline rounded-pill px-4 py-2" onclick="showRiskAssessmentModal(<?= $risk_id ?>)">
                                    <i class="bi bi-pencil-square me-2"></i>Update Assessment
                                </button>
                                <?php endif; ?>
                            </div>
                            
                            <div class="row g-4">
                                <div class="col-md-6">
                                    <div class="bg-light rounded-4 p-4">
                                        <div class="d-flex justify-content-between align-items-center mb-3">
                                            <span class="fw-semibold">Likelihood</span>
                                            <span class="badge px-3 py-2 rounded-pill" style="background: <?= get_likelihood_color((int)$risk['likelihood']) ?>; color: white;">
                                                <?= (int)$risk['likelihood'] ?>/5
                                            </span>
                                        </div>
                                        <div class="progress-premium mb-2">
                                            <div class="progress-bar-premium" style="width: <?= (int)$risk['likelihood'] * 20 ?>%; background: <?= get_likelihood_color((int)$risk['likelihood']) ?>;"></div>
                                        </div>
                                        <div class="d-flex justify-content-between">
                                            <small class="text-muted">Very Unlikely (1)</small>
                                            <small class="text-muted">Almost Certain (5)</small>
                                        </div>
                                        <div class="mt-3 text-center">
                                            <span class="fw-bold"><?= get_likelihood_label((int)$risk['likelihood']) ?></span>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="bg-light rounded-4 p-4">
                                        <div class="d-flex justify-content-between align-items-center mb-3">
                                            <span class="fw-semibold">Impact</span>
                                            <span class="badge px-3 py-2 rounded-pill" style="background: <?= get_impact_color((int)$risk['impact']) ?>; color: white;">
                                                <?= (int)$risk['impact'] ?>/5
                                            </span>
                                        </div>
                                        <div class="progress-premium mb-2">
                                            <div class="progress-bar-premium" style="width: <?= (int)$risk['impact'] * 20 ?>%; background: <?= get_impact_color((int)$risk['impact']) ?>;"></div>
                                        </div>
                                        <div class="d-flex justify-content-between">
                                            <small class="text-muted">Insignificant (1)</small>
                                            <small class="text-muted">Catastrophic (5)</small>
                                        </div>
                                        <div class="mt-3 text-center">
                                            <span class="fw-bold"><?= get_impact_label((int)$risk['impact']) ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Heat Map Visualization -->
                            <div class="mt-4">
                                <h6 class="fw-semibold mb-3">Risk Heat Map</h6>
                                <div class="heat-map">
                                    <?php for ($i = 5; $i >= 1; $i--): ?>
                                        <?php for ($j = 1; $j <= 5; $j++): 
                                            $cell_score = $i * $j;
                                            $cell_class = '';
                                            if ($cell_score >= 16) $cell_class = 'critical';
                                            elseif ($cell_score >= 10) $cell_class = 'high';
                                            elseif ($cell_score >= 6) $cell_class = 'medium';
                                            else $cell_class = 'low';
                                            
                                            $is_active = ($i == $risk['likelihood'] && $j == $risk['impact']);
                                        ?>
                                            <div class="heat-cell <?= $cell_class ?> <?= $is_active ? 'active' : '' ?>">
                                                <?= $cell_score ?>
                                            </div>
                                        <?php endfor; ?>
                                    <?php endfor; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-lg-5">
                        <div class="dashen-card p-4 h-100" data-aos="fade-up" data-aos-delay="200">
                            <h5 class="fw-bold mb-4" style="color: var(--dashen-primary);">
                                <i class="bi bi-shield-fill me-2"></i>Response Strategy
                            </h5>
                            
                            <?php if (in_array($user_role, ['super_admin', 'pm_manager']) && $status_key != 'closed' && $status_key != 'rejected' && !$is_terminated_project): ?>
                            <div class="d-flex justify-content-end mb-3">
                                <button class="btn dashen-btn-outline rounded-pill px-4 py-2 btn-sm" onclick="showResponsePlanModal(<?= $risk_id ?>)">
                                    <i class="bi bi-gear me-2"></i>Configure Strategy
                                </button>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Display Response Strategy -->
                            <?php if ($risk['response_strategy']): ?>
                                <div class="text-center py-3">
                                    <div class="bg-success bg-opacity-10 rounded-4 p-4">
                                        <i class="bi bi-shield-check text-success display-4"></i>
                                        <h3 class="fw-bold mt-3" style="color: #28a745;"><?= e($risk['response_strategy']) ?></h3>
                                        <p class="text-muted mb-0">Current Response Strategy</p>
                                        <?php if ($risk['target_resolution_date']): ?>
                                            <div class="mt-3">
                                                <span class="badge bg-light text-dark px-3 py-2 rounded-pill">
                                                    <i class="bi bi-calendar me-1"></i>Target: <?= date('M j, Y', strtotime($risk['target_resolution_date'])) ?>
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-5">
                                    <div class="bg-light rounded-4 p-5">
                                        <i class="bi bi-shield text-muted display-3"></i>
                                        <h6 class="text-muted mt-4 fw-bold">No Response Strategy Defined</h6>
                                        <p class="text-muted small mb-0">Set a strategy to manage this risk</p>
                                        <?php if (in_array($user_role, ['super_admin', 'pm_manager']) && $status_key != 'closed' && $status_key != 'rejected' && !$is_terminated_project): ?>
                                        <button class="btn dashen-btn-primary rounded-pill px-5 mt-4 py-3" onclick="showResponsePlanModal(<?= $risk_id ?>)">
                                            <i class="bi bi-plus-circle me-2"></i>Add Strategy
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <!-- Risk Owner Assignment -->
                            <?php if (in_array($user_role, ['super_admin', 'pm_manager']) && $status_key != 'closed' && $status_key != 'rejected' && !$is_terminated_project): ?>
                            <div class="mt-4 pt-3 border-top">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="bi bi-person-fill-gear fs-4 text-dashen-primary"></i>
                                        <span class="fw-semibold">Risk Owner:</span>
                                        <?php if ($risk['owner_name']): ?>
                                            <span class="fw-bold"><?= e($risk['owner_name']) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted fst-italic">Unassigned</span>
                                        <?php endif; ?>
                                    </div>
                                    <button class="btn btn-sm btn-outline-dashen rounded-pill px-4 py-2" onclick="showAssignOwnerModal(<?= $risk_id ?>)">
                                        <i class="bi bi-person-plus me-2"></i>Change
                                    </button>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- SRS SECTION 3.1.5 - RISK STATUS MANAGEMENT -->
                <?php if (!$is_terminated_project && $status_key != 'closed' && $status_key != 'rejected'): ?>
                <div class="dashen-card p-4 mb-4" data-aos="fade-up" data-aos-delay="300">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h5 class="fw-bold mb-0" style="color: var(--dashen-primary);">
                            <i class="bi bi-arrow-repeat me-2"></i>Status Management
                        </h5>
                        <?php if (in_array($user_role, ['super_admin', 'pm_manager']) && $status_key != 'closed'): ?>
                        <button class="btn dashen-btn-success px-4 py-3 rounded-pill" onclick="closeRisk(<?= $risk_id ?>)">
                            <i class="bi bi-check2-circle me-2"></i>Close Risk
                        </button>
                        <?php endif; ?>
                    </div>
                    
                    <div class="row">
                        <div class="col-lg-8">
                            <div class="d-flex flex-wrap align-items-center gap-3">
                                <span class="fw-semibold text-muted me-3">Quick Actions:</span>
                                
                                <?php 
                                $available_statuses = [];
                                if ($status_key == 'open') {
                                    $available_statuses[] = ['id' => $in_progress_status_id, 'key' => 'in_progress', 'label' => 'Start Progress', 'icon' => 'bi-play-circle', 'color' => 'primary'];
                                    if (in_array($user_role, ['super_admin', 'pm_manager'])) {
                                        $available_statuses[] = ['id' => $closed_status_id, 'key' => 'closed', 'label' => 'Close', 'icon' => 'bi-check2-circle', 'color' => 'success'];
                                    }
                                } elseif ($status_key == 'in_progress') {
                                    $available_statuses[] = ['id' => $open_status_id, 'key' => 'open', 'label' => 'Reopen', 'icon' => 'bi-arrow-counterclockwise', 'color' => 'info'];
                                    if (in_array($user_role, ['super_admin', 'pm_manager'])) {
                                        $available_statuses[] = ['id' => $mitigated_status_id, 'key' => 'mitigated', 'label' => 'Mark Mitigated', 'icon' => 'bi-shield-check', 'color' => 'success'];
                                        $available_statuses[] = ['id' => $closed_status_id, 'key' => 'closed', 'label' => 'Close', 'icon' => 'bi-check2-circle', 'color' => 'success'];
                                    }
                                } elseif ($status_key == 'mitigated' && in_array($user_role, ['super_admin', 'pm_manager'])) {
                                    $available_statuses[] = ['id' => $closed_status_id, 'key' => 'closed', 'label' => 'Close', 'icon' => 'bi-check2-circle', 'color' => 'success'];
                                }
                                
                                foreach ($available_statuses as $status): 
                                ?>
                                    <button class="btn btn-outline-<?= $status['color'] ?> rounded-pill px-4 py-2" 
                                            onclick="updateRiskStatus(<?= $risk_id ?>, <?= $status['id'] ?>, '<?= $status['key'] ?>')">
                                        <i class="bi <?= $status['icon'] ?> me-2"></i>
                                        <?= $status['label'] ?>
                                    </button>
                                <?php endforeach; ?>
                                
                                <?php if ($user_role == 'pm_employee' && $risk['owner_user_id'] == $current_user_id): ?>
                                    <?php if ($status_key == 'open'): ?>
                                        <button class="btn btn-outline-primary rounded-pill px-4 py-2" 
                                                onclick="updateRiskStatus(<?= $risk_id ?>, <?= $in_progress_status_id ?>, 'in_progress')">
                                            <i class="bi bi-play-circle me-2"></i>Start Progress
                                        </button>
                                    <?php elseif ($status_key == 'in_progress'): ?>
                                        <button class="btn btn-outline-success rounded-pill px-4 py-2" 
                                                onclick="updateRiskStatus(<?= $risk_id ?>, <?= $mitigated_status_id ?>, 'mitigated')">
                                            <i class="bi bi-shield-check me-2"></i>Mark Mitigated
                                        </button>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                            
                            <div class="mt-4 p-3 bg-light rounded-4">
                                <div class="d-flex align-items-center">
                                    <i class="bi bi-info-circle-fill text-dashen-primary me-2 fs-5"></i>
                                    <?php if ($user_role == 'pm_employee'): ?>
                                        <span class="text-muted small">You can update status to <strong>In Progress</strong> or <strong>Mitigated</strong> for risks assigned to you.</span>
                                    <?php elseif ($user_role == 'pm_manager'): ?>
                                        <span class="text-muted small">You can update any status. Only managers and admins can close risks.</span>
                                    <?php elseif ($user_role == 'super_admin'): ?>
                                        <span class="text-muted small">You have full control over risk status.</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-4">
                            <div class="dashen-gradient rounded-4 p-4 h-100 d-flex align-items-center justify-content-center">
                                <div class="text-center text-white">
                                    <i class="bi bi-info-circle-fill display-6 mb-3"></i>
                                    <h6 class="fw-bold mb-2">Current Status</h6>
                                    <span class="badge bg-white text-dashen-primary px-4 py-3 rounded-pill fs-6 fw-bold">
                                        <?= e($risk['status_label'] ?? 'No Status') ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Risk Description and Trigger -->
                <div class="row g-4 mb-4">
                    <div class="col-md-6">
                        <div class="dashen-card p-4 h-100" data-aos="fade-up" data-aos-delay="400">
                            <h5 class="fw-bold mb-4" style="color: var(--dashen-primary);">
                                <i class="bi bi-file-text-fill me-2"></i>Risk Description
                            </h5>
                            <?php if ($risk['description']): ?>
                                <div class="bg-light rounded-4 p-4" style="white-space: pre-line; line-height: 1.7;">
                                    <?= nl2br(e($risk['description'])) ?>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-5 bg-light rounded-4">
                                    <i class="bi bi-file-text text-muted display-3"></i>
                                    <p class="text-muted mt-3 mb-0 fw-semibold">No description provided</p>
                                    <small class="text-muted">Add a description to help others understand this risk</small>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="dashen-card p-4 h-100" data-aos="fade-up" data-aos-delay="500">
                            <h5 class="fw-bold mb-4" style="color: var(--dashen-primary);">
                                <i class="bi bi-lightning-charge-fill me-2"></i>Trigger / Cause
                            </h5>
                            <?php if ($risk['trigger_description']): ?>
                                <div class="bg-light rounded-4 p-4" style="white-space: pre-line; line-height: 1.7;">
                                    <?= nl2br(e($risk['trigger_description'])) ?>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-5 bg-light rounded-4">
                                    <i class="bi bi-lightning-charge text-muted display-3"></i>
                                    <p class="text-muted mt-3 mb-0 fw-semibold">No trigger description provided</p>
                                    <small class="text-muted">What event or condition would trigger this risk?</small>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- MITIGATION ACTIONS SECTION -->
                <div class="dashen-card p-4 mb-4" data-aos="fade-up" data-aos-delay="600">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <div>
                            <h5 class="fw-bold mb-1" style="color: var(--dashen-primary);">
                                <i class="bi bi-shield-check me-2"></i>Mitigation Actions
                            </h5>
                            <p class="text-muted small mb-0"><?= count($mitigations ?? []) ?> total actions</p>
                        </div>
                        <?php 
                        $can_add_mitigation_btn = false;
                        if (in_array($user_role, ['super_admin', 'pm_manager'])) {
                            $can_add_mitigation_btn = true;
                        } elseif ($user_role == 'pm_employee' && $risk['owner_user_id'] == $current_user_id) {
                            $can_add_mitigation_btn = true;
                        }
                        
                        if ($can_add_mitigation_btn && $status_key != 'closed' && $status_key != 'rejected' && !$is_terminated_project): 
                        ?>
                        <button class="btn dashen-btn-primary rounded-pill px-4 py-3" data-bs-toggle="collapse" data-bs-target="#addMitigationForm">
                            <i class="bi bi-plus-circle me-2"></i>Add Action
                        </button>
                        <?php endif; ?>
                    </div>

                    <!-- Add Mitigation Form -->
                    <?php if ($can_add_mitigation_btn && $status_key != 'closed' && $status_key != 'rejected' && !$is_terminated_project): ?>
                    <div class="collapse mb-4" id="addMitigationForm">
                        <div class="bg-light rounded-4 p-4">
                            <h6 class="fw-semibold text-dashen-primary mb-4">
                                <i class="bi bi-plus-circle me-2"></i>New Mitigation Action
                            </h6>
                            <form method="post" action="risks.php">
                                <input type="hidden" name="action" value="add_mitigation">
                                <input type="hidden" name="risk_id" value="<?= $risk_id ?>">
                                <div class="row g-4">
                                    <div class="col-md-6">
                                        <label class="form-label fw-semibold">Action Title <span class="text-danger">*</span></label>
                                        <input type="text" name="mit_title" class="form-control form-control-lg rounded-pill" 
                                               required placeholder="e.g., Implement backup system, Update security controls">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label fw-semibold">Owner</label>
                                        <select name="mit_owner_user_id" class="form-select form-select-lg rounded-pill">
                                            <option value="">Unassigned</option>
                                            <?php foreach ($project_users as $u): ?>
                                                <?php if ($u['system_role'] != 'super_admin'): ?>
                                                <option value="<?= $u['id'] ?>"><?= e($u['username']) ?> (<?= ucwords(str_replace('_', ' ', $u['system_role'] ?? '')) ?>)</option>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label fw-semibold">Due Date</label>
                                        <input type="date" name="mit_due_date" class="form-control form-control-lg rounded-pill" min="<?= date('Y-m-d') ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-semibold">Response Strategy</label>
                                        <select name="mit_response_strategy" class="form-select form-select-lg rounded-pill">
                                            <option value="Mitigate">Mitigate</option>
                                            <option value="Avoid">Avoid</option>
                                            <option value="Transfer">Transfer</option>
                                            <option value="Accept">Accept</option>
                                        </select>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label fw-semibold">Description</label>
                                        <textarea name="mit_description" class="form-control" rows="4" 
                                                  placeholder="Describe the mitigation action in detail..."></textarea>
                                    </div>
                                    <div class="col-12">
                                        <button type="submit" class="btn dashen-btn-primary rounded-pill px-5 py-3">
                                            <i class="bi bi-check-circle me-2"></i>Add Mitigation Action
                                        </button>
                                        <button type="button" class="btn btn-light rounded-pill px-5 py-3 ms-3" data-bs-toggle="collapse" data-bs-target="#addMitigationForm">
                                            Cancel
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Mitigations List -->
                    <?php if (!empty($mitigations)): ?>
                        <div class="row g-4" id="mitigationsContainer">
                            <?php foreach ($mitigations as $index => $m): 
                                $is_overdue = $m['due_date'] && strtotime($m['due_date']) < time() && !in_array($m['status'], ['done', 'cancelled']);
                                $status_class = '';
                                $status_icon = '';
                                $status_text = ucfirst(str_replace('_', ' ', $m['status']));
                                
                                if ($m['status'] == 'open') { 
                                    $status_class = 'status-pending-premium'; 
                                    $status_icon = 'bi-hourglass-split';
                                } elseif ($m['status'] == 'in_progress') { 
                                    $status_class = 'status-progress-premium'; 
                                    $status_icon = 'bi-arrow-repeat';
                                } elseif ($m['status'] == 'done') { 
                                    $status_class = 'status-mitigated-premium'; 
                                    $status_icon = 'bi-check-circle';
                                } elseif ($m['status'] == 'cancelled') { 
                                    $status_class = 'status-closed-premium'; 
                                    $status_icon = 'bi-x-circle';
                                }
                                
                                // Check permissions
                                $can_edit_this = in_array($user_role, ['super_admin', 'pm_manager']) || $m['owner_user_id'] == $current_user_id || $m['created_by'] == $current_user_id;
                                $can_delete_this = in_array($user_role, ['super_admin', 'pm_manager']) || $m['created_by'] == $current_user_id;
                            ?>
                                <div class="col-xl-4 col-lg-6" id="mitigation-<?= $m['id'] ?>">
                                    <div class="dashen-card p-4 h-100 <?= $is_overdue ? 'border-danger' : '' ?>" 
                                         style="<?= $is_overdue ? 'border-left: 6px solid #dc3545;' : '' ?> animation-delay: <?= $index * 0.05 ?>s;">
                                        <div class="d-flex justify-content-between align-items-start mb-3">
                                            <h6 class="fw-bold mb-0" style="color: var(--dashen-primary);">
                                                <?= e($m['title']) ?>
                                            </h6>
                                            <span class="status-badge-premium <?= $status_class ?>">
                                                <i class="bi <?= $status_icon ?> me-1"></i>
                                                <?= $status_text ?>
                                            </span>
                                        </div>
                                        
                                        <?php if ($m['description']): ?>
                                            <p class="text-muted mb-3" style="font-size: 0.9rem; min-height: 60px;">
                                                <?= nl2br(e(substr($m['description'], 0, 120))) ?>
                                                <?php if (strlen($m['description']) > 120): ?>...<?php endif; ?>
                                            </p>
                                        <?php else: ?>
                                            <p class="text-muted fst-italic mb-3">No description</p>
                                        <?php endif; ?>
                                        
                                        <div class="d-flex justify-content-between align-items-center mb-3">
                                            <div class="d-flex align-items-center">
                                                <div class="bg-dashen-primary bg-opacity-10 rounded-circle p-2 me-2">
                                                    <i class="bi bi-person text-dashen-primary"></i>
                                                </div>
                                                <div>
                                                    <small class="text-muted d-block">Owner</small>
                                                    <span class="fw-medium small"><?= e($m['owner_name'] ?: 'Unassigned') ?></span>
                                                </div>
                                            </div>
                                            <div class="d-flex align-items-center">
                                                <div class="bg-<?= $is_overdue ? 'danger' : 'light' ?> bg-opacity-10 rounded-circle p-2 me-2">
                                                    <i class="bi bi-calendar <?= $is_overdue ? 'text-danger' : 'text-muted' ?>"></i>
                                                </div>
                                                <div>
                                                    <small class="text-muted d-block">Due</small>
                                                    <span class="fw-medium small <?= $is_overdue ? 'text-danger' : '' ?>">
                                                        <?= $m['due_date'] ? date('M j, Y', strtotime($m['due_date'])) : 'No date' ?>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Display Response Strategy in Mitigation -->
                                        <?php if ($m['response_strategy']): ?>
                                            <div class="mb-3">
                                                <span class="badge bg-light text-dark rounded-pill px-3 py-1 small">
                                                    <i class="bi bi-shield me-1"></i>Strategy: <?= e($m['response_strategy']) ?>
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <!-- Mitigation Action Buttons -->
                                        <?php if (!$is_terminated_project && $can_edit_this): ?>
                                        <div class="d-flex gap-2 mt-3">
                                            <?php if ($m['status'] == 'open'): ?>
                                                <button class="btn btn-outline-success btn-mitigation flex-grow-1 py-2" 
                                                        onclick="updateMitigationStatus(<?= $m['id'] ?>, <?= $risk_id ?>, 'in_progress')">
                                                    <i class="bi bi-play-circle me-2"></i>Start
                                                </button>
                                            <?php elseif ($m['status'] == 'in_progress'): ?>
                                                <button class="btn btn-outline-success btn-mitigation flex-grow-1 py-2" 
                                                        onclick="updateMitigationStatus(<?= $m['id'] ?>, <?= $risk_id ?>, 'done')">
                                                    <i class="bi bi-check-circle me-2"></i>Complete
                                                </button>
                                            <?php elseif ($m['status'] == 'done'): ?>
                                                <button class="btn btn-outline-warning btn-mitigation flex-grow-1 py-2" 
                                                        onclick="updateMitigationStatus(<?= $m['id'] ?>, <?= $risk_id ?>, 'in_progress')">
                                                    <i class="bi bi-arrow-counterclockwise me-2"></i>Reopen
                                                </button>
                                            <?php elseif ($m['status'] == 'cancelled'): ?>
                                                <button class="btn btn-outline-primary btn-mitigation flex-grow-1 py-2" 
                                                        onclick="updateMitigationStatus(<?= $m['id'] ?>, <?= $risk_id ?>, 'open')">
                                                    <i class="bi bi-arrow-counterclockwise me-2"></i>Reopen
                                                </button>
                                            <?php endif; ?>
                                            
                                            <?php if ($can_edit_this): ?>
                                                <button class="btn btn-outline-dashen btn-mitigation px-4 py-2" 
                                                        onclick='editMitigation(<?= json_encode($m, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                            <?php endif; ?>
                                            
                                            <?php if ($can_delete_this): ?>
                                                <button class="btn btn-outline-danger btn-mitigation btn-mitigation-delete px-4 py-2" 
                                                        onclick="deleteMitigation(<?= $m['id'] ?>, <?= $risk_id ?>, '<?= e(addslashes($m['title'])) ?>')">
                                                    <i class="bi bi-trash3"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-5 bg-light rounded-4">
                            <i class="bi bi-shield-x text-muted display-3"></i>
                            <h6 class="text-muted fw-bold mt-4 mb-2">No Mitigation Actions</h6>
                            <p class="text-muted small mb-4">No mitigation actions have been created for this risk yet.</p>
                            <?php if ($can_add_mitigation_btn && $status_key != 'closed' && $status_key != 'rejected' && !$is_terminated_project): ?>
                            <button class="btn dashen-btn-primary rounded-pill px-5 py-3" data-bs-toggle="collapse" data-bs-target="#addMitigationForm">
                                <i class="bi bi-plus-circle me-2"></i>Add First Action
                            </button>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Comments Section -->
                <div class="dashen-card p-4 mb-4" data-aos="fade-up" data-aos-delay="700">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <div>
                            <h5 class="fw-bold mb-1" style="color: var(--dashen-primary);">
                                <i class="bi bi-chat-dots-fill me-2"></i>Comments & Discussion
                            </h5>
                            <p class="text-muted small mb-0"><?= count($comments ?? []) ?> total comments</p>
                        </div>
                    </div>
                    
                    <!-- Add Comment Form -->
                    <?php if (!$is_terminated_project): ?>
                    <div class="mb-5">
                        <form method="post" action="risks.php" id="addCommentForm">
                            <input type="hidden" name="action" value="add_comment">
                            <input type="hidden" name="risk_id" value="<?= $risk_id ?>">
                            <div class="d-flex gap-4">
                                <div class="comment-avatar-premium">
                                    <?= strtoupper(substr($username, 0, 1)) ?>
                                </div>
                                <div class="flex-grow-1">
                                    <div class="comment-bubble">
                                        <textarea name="comment_text" class="form-control border-0 bg-transparent p-0" 
                                                  rows="3" placeholder="Add a comment..."></textarea>
                                        <div class="d-flex justify-content-end mt-3">
                                            <button type="submit" class="btn dashen-btn-primary rounded-pill px-5 py-2">
                                                <i class="bi bi-send me-2"></i>Post Comment
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Comments List -->
                    <div id="commentsContainer">
                        <?php if (!empty($comments)): ?>
                            <?php foreach ($comments as $comment): ?>
                                <div class="d-flex gap-4 mb-4 animate-on-load">
                                    <div class="comment-avatar-premium">
                                        <?= strtoupper(substr($comment['user_name'] ?? 'U', 0, 1)) ?>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div class="comment-bubble">
                                            <div class="d-flex justify-content-between align-items-center mb-3">
                                                <div>
                                                    <span class="fw-bold" style="color: var(--dashen-primary);">
                                                        <?= e($comment['user_name'] ?? 'Unknown User') ?>
                                                    </span>
                                                    <span class="badge bg-light text-dark ms-2 rounded-pill px-3">
                                                        <?= ucwords(str_replace('_', ' ', $comment['user_role'] ?? 'user')) ?>
                                                    </span>
                                                </div>
                                                <small class="text-muted" data-bs-toggle="tooltip" 
                                                       title="<?= date('F j, Y \a\t g:i A', strtotime($comment['created_at'])) ?>">
                                                    <i class="bi bi-clock me-1"></i><?= time_elapsed_string($comment['created_at']) ?>
                                                </small>
                                            </div>
                                            <div style="white-space: pre-line;">
                                                <?= nl2br(e($comment['comment_text'])) ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center py-5 bg-light rounded-4">
                                <i class="bi bi-chat-dots text-muted display-3"></i>
                                <p class="text-muted fw-bold mt-4 mb-0">No comments yet</p>
                                <p class="text-muted small">Be the first to comment on this risk!</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Activity History -->
                <div class="dashen-card p-4" data-aos="fade-up" data-aos-delay="800">
                    <h5 class="fw-bold mb-4" style="color: var(--dashen-primary);">
                        <i class="bi bi-clock-history me-2"></i>Activity History
                        <span class="badge bg-dashen-primary bg-opacity-10 text-dashen-primary ms-2 rounded-pill px-3">
                            <?= count($history ?? []) ?> events
                        </span>
                    </h5>
                    
                    <?php if (!empty($history)): ?>
                        <div class="timeline">
                            <?php foreach ($history as $index => $h): 
                                $time_ago = time_elapsed_string($h['created_at']);
                            ?>
                                <div class="timeline-item">
                                    <div class="timeline-marker"></div>
                                    <div class="timeline-content">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div class="flex-grow-1">
                                                <div class="d-flex align-items-center gap-2 mb-2">
                                                    <span class="badge px-3 py-2 <?= $h['change_type'] == 'status_changed' ? 'bg-warning text-dark' : 'bg-dashen-primary' ?> rounded-pill">
                                                        <?= ucfirst(str_replace('_', ' ', $h['change_type'])) ?>
                                                    </span>
                                                    <span class="text-muted small">
                                                        <i class="bi bi-clock me-1"></i><?= $time_ago ?>
                                                    </span>
                                                </div>
                                                <p class="mb-2 fw-medium"><?= e($h['comment']) ?></p>
                                                <div class="d-flex align-items-center">
                                                    <div class="bg-light rounded-circle p-2 me-2">
                                                        <i class="bi bi-person text-dashen-primary small"></i>
                                                    </div>
                                                    <small class="text-muted">
                                                        <?= e($h['changed_by_name'] ?: 'System') ?>
                                                        <?php if ($h['changed_by_role']): ?>
                                                            <span class="badge bg-light text-dark ms-2 rounded-pill">
                                                                <?= ucwords(str_replace('_', ' ', $h['changed_by_role'])) ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </small>
                                                </div>
                                            </div>
                                            <small class="text-muted text-end">
                                                <?= date('M j, Y', strtotime($h['created_at'])) ?><br>
                                                <?= date('g:i A', strtotime($h['created_at'])) ?>
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-5 bg-light rounded-4">
                            <i class="bi bi-clock-history text-muted display-3"></i>
                            <p class="text-muted fw-bold mt-4 mb-0">No activity history found</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Edit Risk Modal for PM Employee -->
                <?php if (($user_role == 'pm_employee' && $risk['created_by'] == $current_user_id && $status_key == 'pending_review') || $user_role == 'pm_manager' || $user_role == 'super_admin'): ?>
                <div class="modal fade" id="editRiskModal" tabindex="-1">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content modal-content-premium">
                            <div class="modal-header-premium">
                                <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square me-2"></i>Edit Risk</h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                            </div>
                            <form method="post" action="risks.php">
                                <div class="modal-body p-4">
                                    <input type="hidden" name="action" value="update_risk">
                                    <input type="hidden" name="risk_id" value="<?= $risk_id ?>">
                                    <div class="row g-4">
                                        <div class="col-12">
                                            <label class="form-label fw-bold fs-6 mb-2">Risk Title <span class="text-danger">*</span></label>
                                            <input type="text" name="title" class="form-control form-control-lg rounded-pill" 
                                                   value="<?= e($risk['title']) ?>" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold fs-6 mb-2">Project</label>
                                            <select name="project_id" class="form-select form-select-lg rounded-pill">
                                                <?php foreach ($accessible_projects as $p): ?>
                                                    <option value="<?= (int)$p['id'] ?>" <?= $risk['project_id'] == $p['id'] ? 'selected' : '' ?>>
                                                        <?= e($p['name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold fs-6 mb-2">Category</label>
                                            <select name="category_id" class="form-select form-select-lg rounded-pill">
                                                <option value="">-- Select Category --</option>
                                                <?php foreach ($dropdowns['categories'] as $c): ?>
                                                    <option value="<?= (int)$c['id'] ?>" <?= $risk['category_id'] == $c['id'] ? 'selected' : '' ?>>
                                                        <?= e($c['name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold fs-6 mb-2">Department</label>
                                            <select name="department_id" class="form-select form-select-lg rounded-pill">
                                                <option value="">-- Select Department --</option>
                                                <?php foreach ($dropdowns['departments'] as $d): ?>
                                                    <option value="<?= (int)$d['id'] ?>" <?= $risk['department_id'] == $d['id'] ? 'selected' : '' ?>>
                                                        <?= e($d['department_name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        
                                        <!-- Response Strategy Field - Only for Managers -->
                                        <?php if ($user_role == 'pm_manager' || $user_role == 'super_admin'): ?>
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold fs-6 mb-2">Response Strategy</label>
                                            <select name="response_strategy" class="form-select form-select-lg rounded-pill">
                                                <option value="">-- Select Strategy --</option>
                                                <option value="Avoid" <?= $risk['response_strategy'] == 'Avoid' ? 'selected' : '' ?>>Avoid</option>
                                                <option value="Mitigate" <?= $risk['response_strategy'] == 'Mitigate' ? 'selected' : '' ?>>Mitigate</option>
                                                <option value="Transfer" <?= $risk['response_strategy'] == 'Transfer' ? 'selected' : '' ?>>Transfer</option>
                                                <option value="Accept" <?= $risk['response_strategy'] == 'Accept' ? 'selected' : '' ?>>Accept</option>
                                            </select>
                                        </div>
                                        
                                        <!-- Target Resolution Date -->
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold fs-6 mb-2">Target Resolution Date</label>
                                            <input type="date" name="target_resolution_date" class="form-control form-control-lg rounded-pill" 
                                                   value="<?= $risk['target_resolution_date'] ?? '' ?>" min="<?= date('Y-m-d') ?>">
                                        </div>
                                        
                                        <!-- FIXED: Risk Assessment Fields - Always present for managers -->
                                        <div class="col-md-4">
                                            <label class="form-label fw-bold fs-6 mb-2">Likelihood (1-5)</label>
                                            <select name="likelihood" class="form-select form-select-lg rounded-pill">
                                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                                    <option value="<?= $i ?>" <?= $risk['likelihood'] == $i ? 'selected' : '' ?>>
                                                        <?= $i ?> - <?= get_likelihood_label($i) ?>
                                                    </option>
                                                <?php endfor; ?>
                                            </select>
                                        </div>
                                        
                                        <div class="col-md-4">
                                            <label class="form-label fw-bold fs-6 mb-2">Impact (1-5)</label>
                                            <select name="impact" class="form-select form-select-lg rounded-pill">
                                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                                    <option value="<?= $i ?>" <?= $risk['impact'] == $i ? 'selected' : '' ?>>
                                                        <?= $i ?> - <?= get_impact_label($i) ?>
                                                    </option>
                                                <?php endfor; ?>
                                            </select>
                                        </div>
                                        
                                        <!-- Risk Owner Assignment -->
                                        <div class="col-md-4">
                                            <label class="form-label fw-bold fs-6 mb-2">Risk Owner</label>
                                            <select name="owner_user_id" class="form-select form-select-lg rounded-pill">
                                                <option value="">Unassigned</option>
                                                <?php foreach ($project_users as $u): ?>
                                                    <option value="<?= $u['id'] ?>" <?= $risk['owner_id'] == $u['id'] ? 'selected' : '' ?>>
                                                        <?= e($u['username']) ?> (<?= ucwords(str_replace('_', ' ', $u['system_role'] ?? '')) ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <div class="col-12">
                                            <label class="form-label fw-bold fs-6 mb-2">Description</label>
                                            <textarea name="description" class="form-control" rows="4"><?= e($risk['description']) ?></textarea>
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label fw-bold fs-6 mb-2">Trigger / Cause</label>
                                            <textarea name="trigger_description" class="form-control" rows="3"><?= e($risk['trigger_description']) ?></textarea>
                                        </div>
                                        <?php if ($user_role == 'pm_employee'): ?>
                                        <div class="col-12">
                                            <div class="alert alert-info rounded-4 p-3 mb-0">
                                                <i class="bi bi-info-circle-fill me-2"></i>
                                                <strong>Note:</strong> You can only edit risks you created while they are pending review.
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="modal-footer border-0 p-4">
                                    <button type="button" class="btn btn-light rounded-pill px-5 py-3" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn dashen-btn-primary rounded-pill px-5 py-3">
                                        <i class="bi bi-save me-2"></i>Save Changes
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- Edit Risk Button in Header -->
                <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        const actionContainer = document.querySelector('.d-flex.align-items-center.gap-3');
                        if (actionContainer) {
                            const editBtn = document.createElement('button');
                            editBtn.className = 'btn dashen-btn-primary rounded-pill px-4';
                            editBtn.innerHTML = '<i class="bi bi-pencil-square me-2"></i>Edit Risk';
                            editBtn.setAttribute('data-bs-toggle', 'modal');
                            editBtn.setAttribute('data-bs-target', '#editRiskModal');
                            
                            const backBtn = actionContainer.querySelector('a[href="risks.php"]');
                            if (backBtn) {
                                actionContainer.insertBefore(editBtn, backBtn.nextSibling);
                            }
                        }
                    });
                </script>
                <?php endif; ?>

            <?php else: ?>
                <!-- ========================================= -->
                <!-- RISK REGISTER LIST VIEW - PRESERVE EXISTING -->
                <!-- ========================================= -->
                
                <!-- Dashboard Summary Cards -->
                <div class="row g-4 mb-4">
                    <div class="col-xl-3 col-md-6" data-aos="fade-up" data-aos-delay="0">
                        <div class="metric-card">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <span class="text-muted small text-uppercase fw-semibold">Total Risks</span>
                                    <h2 class="metric-value mb-0"><?= $stats['total'] ?></h2>
                                    <small class="text-muted">Across all projects</small>
                                </div>
                                <div class="metric-icon">
                                    <i class="bi bi-shield"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6" data-aos="fade-up" data-aos-delay="100">
                        <div class="metric-card">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <span class="text-muted small text-uppercase fw-semibold">High Risks</span>
                                    <h2 class="metric-value mb-0" style="color: #fd7e14;"><?= $stats['high'] ?></h2>
                                    <small class="text-muted">Needs immediate attention</small>
                                </div>
                                <div class="metric-icon" style="background: linear-gradient(145deg, #fd7e14, #e96b02);">
                                    <i class="bi bi-exclamation-triangle"></i>
                                </div>
                            </div>
                            <?php if ($stats['high'] > 0): ?>
                            <div class="mt-3">
                                <a href="?risk_level=High" class="btn btn-sm btn-outline-warning w-100 rounded-pill">
                                    View High Risks <i class="bi bi-arrow-right ms-1"></i>
                                </a>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6" data-aos="fade-up" data-aos-delay="200">
                        <div class="metric-card">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <span class="text-muted small text-uppercase fw-semibold">Pending Review</span>
                                    <h2 class="metric-value mb-0" style="color: #ffc107;"><?= $stats['pending_review'] ?></h2>
                                    <small class="text-muted">Awaiting approval</small>
                                </div>
                                <div class="metric-icon" style="background: linear-gradient(145deg, #ffc107, #e0a800);">
                                    <i class="bi bi-hourglass-split"></i>
                                </div>
                            </div>
                            <?php if (in_array($user_role, ['super_admin', 'pm_manager']) && $stats['pending_review'] > 0): ?>
                            <div class="mt-3">
                                <a href="?status_id=<?= $pending_review_status_id ?>" class="btn btn-sm btn-outline-warning w-100 rounded-pill">
                                    Review Now <i class="bi bi-arrow-right ms-1"></i>
                                </a>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6" data-aos="fade-up" data-aos-delay="300">
                        <div class="metric-card">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <span class="text-muted small text-uppercase fw-semibold">My Assigned</span>
                                    <h2 class="metric-value mb-0" style="color: #6f42c1;"><?= $stats['my_assigned'] ?></h2>
                                    <small class="text-muted">Risks assigned to you</small>
                                </div>
                                <div class="metric-icon" style="background: linear-gradient(145deg, #6f42c1, #6610f2);">
                                    <i class="bi bi-person-check"></i>
                                </div>
                            </div>
                            <?php if ($stats['overdue'] > 0): ?>
                            <div class="mt-3">
                                <span class="badge bg-danger w-100 py-2 rounded-pill">
                                    <i class="bi bi-exclamation-triangle me-1"></i><?= $stats['overdue'] ?> Overdue
                                </span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Header and Actions -->
                <div class="d-flex justify-content-between align-items-center mb-4" data-aos="fade-up" data-aos-delay="400">
                    <div>
                        <h5 class="fw-bold mb-1" style="color: var(--dashen-primary);">
                            <i class="bi bi-list-check me-2"></i>Risk Register
                        </h5>
                        <p class="text-muted small mb-0">Showing <?= number_format($total_risks) ?> risks across <?= count($accessible_projects) ?> projects</p>
                    </div>
                    <div class="d-flex gap-2">
                        <?php if (!empty($accessible_projects)): ?>
                        <button class="btn dashen-btn-primary rounded-pill px-4 py-3" data-bs-toggle="modal" data-bs-target="#newRiskModal">
                            <i class="bi bi-plus-circle me-2"></i> Report New Risk
                        </button>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Filters -->
                <div class="dashen-card p-4 mb-4" data-aos="fade-up" data-aos-delay="500">
                    <form method="get" class="row g-3 align-items-end">
                        <div class="col-lg-3 col-md-6">
                            <label class="form-label fw-semibold small text-uppercase">Project</label>
                            <select name="project_id" class="form-select form-select-lg rounded-pill">
                                <option value="">All Projects</option>
                                <?php foreach ($accessible_projects as $p): ?>
                                    <option value="<?= (int)$p['id'] ?>" <?= $filter_project == $p['id'] ? 'selected' : '' ?>>
                                        <?= e($p['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-lg-2 col-md-6">
                            <label class="form-label fw-semibold small text-uppercase">Risk Level</label>
                            <select name="risk_level" class="form-select form-select-lg rounded-pill">
                                <option value="">All Levels</option>
                                <option value="High" <?= $filter_level == 'High' ? 'selected' : '' ?>>High</option>
                                <option value="Medium" <?= $filter_level == 'Medium' ? 'selected' : '' ?>>Medium</option>
                                <option value="Low" <?= $filter_level == 'Low' ? 'selected' : '' ?>>Low</option>
                            </select>
                        </div>
                        
                        <div class="col-lg-2 col-md-6">
                            <label class="form-label fw-semibold small text-uppercase">Status</label>
                            <select name="status_id" class="form-select form-select-lg rounded-pill">
                                <option value="">All Statuses</option>
                                <?php foreach ($dropdowns['statuses'] as $s): ?>
                                    <option value="<?= (int)$s['id'] ?>" <?= $filter_status == $s['id'] ? 'selected' : '' ?>>
                                        <?= e($s['label']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-lg-3 col-md-6">
                            <label class="form-label fw-semibold small text-uppercase">Search</label>
                            <div class="input-group">
                                <input type="text" name="search" class="form-control form-control-lg rounded-pill-start" placeholder="Search risks..." value="<?= e($filter_search ?? '') ?>">
                                <button class="btn dashen-btn-primary rounded-pill-end px-4" type="submit">
                                    <i class="bi bi-search"></i>
                                </button>
                                <a href="risks.php" class="btn btn-outline-secondary rounded-pill ms-2 px-4">
                                    <i class="bi bi-x-circle"></i>
                                </a>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Risk Table -->
                <div class="dashen-card p-0 overflow-hidden" data-aos="fade-up" data-aos-delay="600">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 risk-table">
                            <thead class="bg-light">
                                <tr>
                                    <th class="ps-4 py-3">ID</th>
                                    <th class="py-3">Score</th>
                                    <th class="py-3">Level</th>
                                    <th class="py-3">Risk Title</th>
                                    <th class="py-3">Project</th>
                                    <th class="py-3">Owner</th>
                                    <th class="py-3">Status</th>
                                    <th class="py-3">Created</th>
                                    <th class="text-end pe-4 py-3">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($risks)): ?>
                                    <?php foreach ($risks as $r): 
                                        $risk_class = strtolower($r['risk_level'] ?? 'low');
                                        $status_key = $r['status_key'] ?? '';
                                        $is_project_terminated = ($r['project_status'] ?? '') === 'terminated';
                                        
                                        $status_class = '';
                                        if ($status_key == 'pending_review') $status_class = 'status-pending-premium';
                                        elseif ($status_key == 'open') $status_class = 'status-open-premium';
                                        elseif ($status_key == 'in_progress') $status_class = 'status-progress-premium';
                                        elseif ($status_key == 'mitigated') $status_class = 'status-mitigated-premium';
                                        elseif ($status_key == 'closed') $status_class = 'status-closed-premium';
                                        elseif ($status_key == 'rejected') $status_class = 'status-rejected-premium';
                                        
                                        // Permission checks
                                        $can_edit_risk = false;
                                        if ($user_role == 'super_admin') $can_edit_risk = true;
                                        elseif ($user_role == 'pm_manager') $can_edit_risk = true;
                                        elseif ($user_role == 'pm_employee' && $r['created_by'] == $current_user_id && $status_key == 'pending_review') {
                                            $can_edit_risk = true;
                                        }
                                        
                                        $can_delete_risk = false;
                                        if ($user_role == 'super_admin') $can_delete_risk = true;
                                        elseif ($user_role == 'pm_employee' && $r['created_by'] == $current_user_id) {
                                            $can_delete_risk = true;
                                        }
                                    ?>
                                        <tr onclick="window.location.href='risks.php?id=<?= (int)$r['id'] ?>'">
                                            <td class="ps-4">
                                                <span class="fw-semibold" style="color: var(--dashen-primary);">#<?= str_pad((int)$r['id'], 5, '0', STR_PAD_LEFT) ?></span>
                                                <?php if ($r['comment_count'] > 0): ?>
                                                    <span class="badge bg-light text-dark ms-1 rounded-pill px-2" data-bs-toggle="tooltip" title="<?= $r['comment_count'] ?> comments">
                                                        <i class="bi bi-chat"></i> <?= $r['comment_count'] ?>
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($is_project_terminated): ?>
                                                    <span class="badge bg-danger ms-1 rounded-pill px-2" data-bs-toggle="tooltip" title="Project Terminated">
                                                        <i class="bi bi-exclamation-triangle"></i>
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($r['created_by'] == $current_user_id && $user_role == 'pm_employee'): ?>
                                                    <span class="badge bg-info ms-1 rounded-pill px-2" data-bs-toggle="tooltip" title="Created by you">
                                                        <i class="bi bi-pencil-fill"></i> You
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($status_key == 'pending_review' && $r['created_by'] == $current_user_id): ?>
                                                    <span class="badge bg-warning ms-1 rounded-pill px-2" data-bs-toggle="tooltip" title="Pending Approval">
                                                        <i class="bi bi-hourglass-split"></i> Pending
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="risk-score-premium" style="width: 48px; height: 48px; font-size: 1.2rem;">
                                                    <?= (int)($r['risk_score'] ?? 0) ?>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="badge <?php
                                                    if ($risk_class == 'high') echo 'bg-danger';
                                                    elseif ($risk_class == 'medium') echo 'bg-warning text-dark';
                                                    else echo 'bg-success';
                                                ?> px-3 py-2 rounded-pill">
                                                    <?= e($r['risk_level'] ?? 'Low') ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="fw-semibold"><?= e($r['title']) ?></div>
                                                <?php if ($r['category_name']): ?>
                                                    <small class="text-muted">
                                                        <i class="bi bi-tag me-1"></i><?= e($r['category_name']) ?>
                                                    </small>
                                                <?php endif; ?>
                                                <?php if ($r['response_strategy']): ?>
                                                    <small class="text-muted d-block">
                                                        <i class="bi bi-shield me-1"></i><?= e($r['response_strategy']) ?>
                                                    </small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="fw-medium"><?= e($r['project_name'] ?: '—') ?></span>
                                                <?php if ($is_project_terminated): ?>
                                                    <br><small class="text-danger">Terminated</small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($r['owner_name']): ?>
                                                    <div class="d-flex align-items-center">
                                                        <div class="bg-dashen-primary bg-opacity-10 rounded-circle d-flex align-items-center justify-content-center me-2" 
                                                             style="width: 28px; height: 28px;">
                                                            <span class="small fw-bold text-dashen-primary">
                                                                <?= strtoupper(substr($r['owner_name'], 0, 1)) ?>
                                                            </span>
                                                        </div>
                                                        <span class="small"><?= e($r['owner_name']) ?></span>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-muted fst-italic small">Unassigned</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="status-badge-premium <?= $status_class ?> py-2">
                                                    <?php if ($status_key == 'pending_review'): ?>
                                                        <i class="bi bi-hourglass-split me-1"></i>
                                                    <?php elseif ($status_key == 'in_progress'): ?>
                                                        <i class="bi bi-arrow-repeat me-1"></i>
                                                    <?php elseif ($status_key == 'mitigated'): ?>
                                                        <i class="bi bi-shield-check me-1"></i>
                                                    <?php elseif ($status_key == 'closed'): ?>
                                                        <i class="bi bi-check2-circle me-1"></i>
                                                    <?php elseif ($status_key == 'rejected'): ?>
                                                        <i class="bi bi-x-circle me-1"></i>
                                                    <?php endif; ?>
                                                    <?= e($r['status_label'] ?? 'Open') ?>
                                                </span>
                                            </td>
                                            <td>
                                                <small class="text-muted" data-bs-toggle="tooltip" 
                                                       title="<?= date('g:i A', strtotime($r['created_at'])) ?>">
                                                    <?= date('M j, Y', strtotime($r['created_at'])) ?>
                                                </small>
                                            </td>
                                            <td class="text-end pe-4">
                                                <div class="btn-group btn-group-sm" onclick="event.stopPropagation();">
                                                    <button class="btn btn-outline-dashen rounded-pill px-3" 
                                                            onclick="window.location.href='risks.php?id=<?= (int)$r['id'] ?>'"
                                                            data-bs-toggle="tooltip" title="View Details">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                    
                                                    <?php if ($can_edit_risk && !$is_project_terminated): ?>
                                                        <button class="btn btn-outline-primary rounded-pill px-3 ms-1" 
                                                                onclick="window.location.href='risk_edit.php?id=<?= (int)$r['id'] ?>'"
                                                                data-bs-toggle="tooltip" title="Edit Risk">
                                                            <i class="bi bi-pencil"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($can_delete_risk && !$is_project_terminated): ?>
                                                        <button class="btn btn-outline-danger rounded-pill px-3 ms-1" 
                                                                onclick="deleteRisk(<?= (int)$r['id'] ?>, '<?= e(addslashes($r['title'])) ?>')"
                                                                data-bs-toggle="tooltip" title="Delete Risk">
                                                            <i class="bi bi-trash3"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="9" class="text-center py-5">
                                            <i class="bi bi-shield-x text-muted display-3"></i>
                                            <h5 class="mt-3 text-muted fw-bold">No Risks Found</h5>
                                            <p class="text-muted mb-4">No risks match your current filters or you don't have access to any risks.</p>
                                            <?php if (!empty($accessible_projects)): ?>
                                            <button class="btn dashen-btn-primary rounded-pill px-5 py-3" data-bs-toggle="modal" data-bs-target="#newRiskModal">
                                                <i class="bi bi-plus-circle me-2"></i> Report New Risk
                                            </button>
                                            <?php endif; ?>
                                            <a href="risks.php" class="btn btn-outline-secondary rounded-pill px-5 py-3 ms-3">
                                                <i class="bi bi-arrow-clockwise me-2"></i> Clear Filters
                                            </a>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                    <div class="card-footer bg-white border-0 pt-3 pb-4 px-4">
                        <nav aria-label="Risk pagination">
                            <ul class="pagination pagination-lg justify-content-center mb-0">
                                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                    <a class="page-link rounded-pill" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">
                                        <i class="bi bi-chevron-left"></i>
                                    </a>
                                </li>
                                
                                <?php 
                                $start = max(1, $page - 2);
                                $end = min($total_pages, $page + 2);
                                for ($i = $start; $i <= $end; $i++): 
                                ?>
                                    <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                        <a class="page-link rounded-pill" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>">
                                            <?= $i ?>
                                        </a>
                                    </li>
                                <?php endfor; ?>
                                
                                <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                                    <a class="page-link rounded-pill" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">
                                        <i class="bi bi-chevron-right"></i>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Floating Action Button -->
                <?php if (!empty($accessible_projects)): ?>
                <button class="floating-action-btn" data-bs-toggle="modal" data-bs-target="#newRiskModal">
                    <i class="bi bi-plus"></i>
                </button>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- New Risk Modal -->
    <div class="modal fade" id="newRiskModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content modal-content-premium">
                <div class="modal-header-premium">
                    <h5 class="modal-title fw-bold"><i class="bi bi-plus-circle me-2"></i>Report New Risk</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="post" action="risks.php">
                    <div class="modal-body p-4">
                        <input type="hidden" name="action" value="create_risk">
                        <div class="row g-4">
                            <div class="col-12">
                                <label class="form-label fw-bold fs-6 mb-2">Risk Title <span class="text-danger">*</span></label>
                                <input type="text" name="title" class="form-control form-control-lg rounded-pill" 
                                       required placeholder="e.g., System Integration Failure, Vendor Delay">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold fs-6 mb-2">Project <span class="text-danger">*</span></label>
                                <select name="project_id" class="form-select form-select-lg rounded-pill" required>
                                    <option value="">-- Select Project --</option>
                                    <?php foreach ($accessible_projects as $p): ?>
                                        <option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold fs-6 mb-2">Category</label>
                                <select name="category_id" class="form-select form-select-lg rounded-pill">
                                    <option value="">-- Select Category --</option>
                                    <?php foreach ($dropdowns['categories'] as $c): ?>
                                        <option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold fs-6 mb-2">Department</label>
                                <select name="department_id" class="form-select form-select-lg rounded-pill">
                                    <option value="">-- Select Department --</option>
                                    <?php foreach ($dropdowns['departments'] as $d): ?>
                                        <option value="<?= (int)$d['id'] ?>"><?= e($d['department_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <!-- FIXED: Risk Assessment Fields for Managers -->
                            <?php if ($permissions['can_assess_risk']): ?>
                            <div class="col-md-3">
                                <label class="form-label fw-bold fs-6 mb-2">Likelihood (1-5)</label>
                                <select name="likelihood" class="form-select form-select-lg rounded-pill">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <option value="<?= $i ?>"><?= $i ?> - <?= get_likelihood_label($i) ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-bold fs-6 mb-2">Impact (1-5)</label>
                                <select name="impact" class="form-select form-select-lg rounded-pill">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <option value="<?= $i ?>"><?= $i ?> - <?= get_impact_label($i) ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <?php endif; ?>
                            
                            <div class="col-12">
                                <label class="form-label fw-bold fs-6 mb-2">Description</label>
                                <textarea name="description" class="form-control" rows="4" 
                                          placeholder="Describe the risk in detail, including potential consequences..."></textarea>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-bold fs-6 mb-2">Trigger / Cause</label>
                                <textarea name="trigger_description" class="form-control" rows="3" 
                                          placeholder="What event or condition would trigger this risk?"></textarea>
                            </div>
                            <div class="col-12">
                                <div class="alert alert-info rounded-4 p-3 mb-0">
                                    <div class="d-flex">
                                        <i class="bi bi-info-circle-fill fs-4 me-3 text-info"></i>
                                        <div>
                                            <strong class="fw-bold">Note:</strong> Your risk will be submitted for manager review and approval. 
                                            You will be notified when it is approved or rejected.
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-0 p-4">
                        <button type="button" class="btn btn-light rounded-pill px-5 py-3" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn dashen-btn-primary rounded-pill px-5 py-3">
                            <i class="bi bi-check-circle me-2"></i>Submit Risk
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Risk Assessment Modal -->
    <div class="modal fade" id="riskAssessmentModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content modal-content-premium">
                <div class="modal-header-premium">
                    <h5 class="modal-title fw-bold"><i class="bi bi-calculator me-2"></i>Update Risk Assessment</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <input type="hidden" id="assessmentRiskId">
                    <div class="mb-5">
                        <label class="form-label fw-bold fs-6 mb-3">Likelihood (1-5)</label>
                        <div class="row g-3">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                            <div class="col">
                                <div class="card-radio">
                                    <input class="form-check-input" type="radio" name="likelihood" id="like<?= $i ?>" value="<?= $i ?>">
                                    <label class="form-check-label text-center p-3 rounded-4 border" for="like<?= $i ?>" style="cursor: pointer; width: 100%;">
                                        <div class="fw-bold fs-4 mb-2" style="color: <?= get_likelihood_color($i) ?>;"><?= $i ?></div>
                                        <small class="d-block"><?= get_likelihood_label($i) ?></small>
                                    </label>
                                </div>
                            </div>
                            <?php endfor; ?>
                        </div>
                    </div>
                    <div class="mb-5">
                        <label class="form-label fw-bold fs-6 mb-3">Impact (1-5)</label>
                        <div class="row g-3">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                            <div class="col">
                                <div class="card-radio">
                                    <input class="form-check-input" type="radio" name="impact" id="impact<?= $i ?>" value="<?= $i ?>">
                                    <label class="form-check-label text-center p-3 rounded-4 border" for="impact<?= $i ?>" style="cursor: pointer; width: 100%;">
                                        <div class="fw-bold fs-4 mb-2" style="color: <?= get_impact_color($i) ?>;"><?= $i ?></div>
                                        <small class="d-block"><?= get_impact_label($i) ?></small>
                                    </label>
                                </div>
                            </div>
                            <?php endfor; ?>
                        </div>
                    </div>
                    <div class="bg-light rounded-4 p-4 text-center">
                        <h6 class="fw-bold mb-3">Risk Score Preview</h6>
                        <div class="d-flex align-items-center justify-content-center gap-4">
                            <div class="risk-score-premium" style="width: 80px; height: 80px;">
                                <span class="risk-score-number" id="previewScore" style="font-size: 2rem;">0</span>
                            </div>
                            <div>
                                <span class="badge px-4 py-3 fs-6 rounded-pill" id="previewLevel">Low Risk</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 p-4">
                    <button type="button" class="btn btn-light rounded-pill px-5 py-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn dashen-btn-primary rounded-pill px-5 py-3" onclick="submitRiskAssessment()">
                        <i class="bi bi-save me-2"></i>Save Assessment
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Response Plan Modal -->
    <div class="modal fade" id="responsePlanModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content modal-content-premium">
                <div class="modal-header-premium">
                    <h5 class="modal-title fw-bold"><i class="bi bi-shield me-2"></i>Set Response Strategy</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="post" action="risks.php">
                    <div class="modal-body p-4">
                        <input type="hidden" name="action" value="update_response_plan">
                        <input type="hidden" name="risk_id" id="responseRiskId">
                        <div class="mb-4">
                            <label class="form-label fw-bold fs-6 mb-3">Response Strategy</label>
                            <select name="response_strategy" id="responseStrategy" class="form-select form-select-lg rounded-pill">
                                <option value="">-- Select Strategy --</option>
                                <option value="Avoid">Avoid</option>
                                <option value="Mitigate">Mitigate</option>
                                <option value="Transfer">Transfer</option>
                                <option value="Accept">Accept</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold fs-6 mb-3">Target Resolution Date</label>
                            <input type="date" name="target_resolution_date" id="targetDate" class="form-control form-control-lg rounded-pill" 
                                   min="<?= date('Y-m-d') ?>">
                            <small class="text-muted d-block mt-2">Expected date when this risk will be resolved</small>
                        </div>
                    </div>
                    <div class="modal-footer border-0 p-4">
                        <button type="button" class="btn btn-light rounded-pill px-5 py-3" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn dashen-btn-primary rounded-pill px-5 py-3">
                            <i class="bi bi-save me-2"></i>Save Strategy
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Assign Owner Modal -->
    <div class="modal fade" id="assignOwnerModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content modal-content-premium">
                <div class="modal-header-premium">
                    <h5 class="modal-title fw-bold"><i class="bi bi-person-plus me-2"></i>Assign Risk Owner</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="post" action="risks.php">
                    <div class="modal-body p-4">
                        <input type="hidden" name="action" value="assign_owner">
                        <input type="hidden" name="risk_id" id="ownerRiskId">
                        <div class="mb-3">
                            <label class="form-label fw-bold fs-6 mb-3">Select Owner</label>
                            <select name="owner_user_id" id="riskOwnerSelect" class="form-select form-select-lg rounded-pill">
                                <option value="">-- Unassigned --</option>
                                <?php if ($risk_id && isset($project_users)): ?>
                                    <?php foreach ($project_users as $u): ?>
                                        <?php if ($u['system_role'] != 'super_admin'): ?>
                                        <option value="<?= $u['id'] ?>" <?= $risk['owner_id'] == $u['id'] ? 'selected' : '' ?>>
                                            <?= e($u['username']) ?> (<?= ucwords(str_replace('_', ' ', $u['system_role'] ?? '')) ?>)
                                        </option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                            <small class="text-muted d-block mt-2">Only users assigned to this project can be owners</small>
                        </div>
                    </div>
                    <div class="modal-footer border-0 p-4">
                        <button type="button" class="btn btn-light rounded-pill px-5 py-3" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn dashen-btn-primary rounded-pill px-5 py-3">
                            <i class="bi bi-check-circle me-2"></i>Assign Owner
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Mitigation Modal -->
    <div class="modal fade" id="editMitigationModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content modal-content-premium">
                <div class="modal-header-premium">
                    <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square me-2"></i>Edit Mitigation Action</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="post" action="risks.php">
                    <div class="modal-body p-4">
                        <input type="hidden" name="action" value="update_mitigation">
                        <input type="hidden" name="mitigation_id" id="editMitigationId">
                        <input type="hidden" name="risk_id" id="editMitigationRiskId">
                        <div class="row g-4">
                            <div class="col-12">
                                <label class="form-label fw-semibold">Title <span class="text-danger">*</span></label>
                                <input type="text" name="title" id="editMitigationTitle" class="form-control form-control-lg rounded-pill" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Owner</label>
                                <select name="owner_user_id" id="editMitigationOwner" class="form-select form-select-lg rounded-pill">
                                    <option value="">Unassigned</option>
                                    <?php if ($risk_id && isset($project_users)): ?>
                                        <?php foreach ($project_users as $u): ?>
                                            <?php if ($u['system_role'] != 'super_admin'): ?>
                                            <option value="<?= $u['id'] ?>"><?= e($u['username']) ?></option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Due Date</label>
                                <input type="date" name="due_date" id="editMitigationDueDate" class="form-control form-control-lg rounded-pill">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Status</label>
                                <select name="status" id="editMitigationStatus" class="form-select form-select-lg rounded-pill">
                                    <option value="open">Open</option>
                                    <option value="in_progress">In Progress</option>
                                    <option value="done">Done</option>
                                    <option value="cancelled">Cancelled</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Response Strategy</label>
                                <select name="response_strategy" id="editMitigationStrategy" class="form-select form-select-lg rounded-pill">
                                    <option value="Mitigate">Mitigate</option>
                                    <option value="Avoid">Avoid</option>
                                    <option value="Transfer">Transfer</option>
                                    <option value="Accept">Accept</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold">Description</label>
                                <textarea name="description" id="editMitigationDescription" class="form-control" rows="5"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-0 p-4">
                        <button type="button" class="btn btn-light rounded-pill px-5 py-3" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn dashen-btn-primary rounded-pill px-5 py-3">
                            <i class="bi bi-save me-2"></i>Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    
    <script>
        // Initialize AOS
        AOS.init({
            duration: 600,
            once: true,
            offset: 20
        });

        // Tooltips
        document.addEventListener('DOMContentLoaded', function() {
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            tooltipTriggerList.map(function(tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
            
            // Risk score preview
            const likeInputs = document.querySelectorAll('input[name="likelihood"]');
            const impactInputs = document.querySelectorAll('input[name="impact"]');
            
            function updateScorePreview() {
                const likelihood = parseInt(document.querySelector('input[name="likelihood"]:checked')?.value || 3);
                const impact = parseInt(document.querySelector('input[name="impact"]:checked')?.value || 3);
                const score = likelihood * impact;
                
                let level = 'Low';
                let levelClass = 'bg-success';
                if (score >= 13) { level = 'High'; levelClass = 'bg-danger'; }
                else if (score >= 6) { level = 'Medium'; levelClass = 'bg-warning text-dark'; }
                
                document.getElementById('previewScore').textContent = score;
                document.getElementById('previewLevel').textContent = level + ' Risk';
                document.getElementById('previewLevel').className = 'badge px-4 py-3 fs-6 rounded-pill ' + levelClass;
            }
            
            if (likeInputs.length) {
                likeInputs.forEach(input => input.addEventListener('change', updateScorePreview));
                impactInputs.forEach(input => input.addEventListener('change', updateScorePreview));
                updateScorePreview();
            }
        });

        function toggleSidebar() {
            const sidebar = document.querySelector('.sidebar');
            const mainContent = document.querySelector('.main-content');
            sidebar.classList.toggle('expanded');
            mainContent.classList.toggle('expanded');
        }

        // Approve/Reject Risk
        function approveRisk(riskId, approve) {
            let title = approve ? 'Approve Risk' : 'Reject Risk';
            let text = approve ? 'Are you sure you want to approve this risk? Status will change to Open.' : 'Are you sure you want to reject this risk? Status will change to Rejected.';
            let confirmButtonColor = approve ? '#28a745' : '#dc3545';
            
            Swal.fire({
                title: title,
                text: text,
                icon: 'question',
                input: approve ? null : 'textarea',
                inputPlaceholder: 'Please provide a reason for rejection...',
                inputValidator: (value) => {
                    if (!approve && !value) {
                        return 'Rejection reason is required';
                    }
                },
                showCancelButton: true,
                confirmButtonColor: confirmButtonColor,
                confirmButtonText: approve ? 'Yes, approve' : 'Yes, reject',
                cancelButtonText: 'Cancel',
                background: 'white',
                borderRadius: '24px'
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = window.location.href;
                    
                    const actionInput = document.createElement('input');
                    actionInput.type = 'hidden';
                    actionInput.name = 'action';
                    actionInput.value = 'approve_risk';
                    form.appendChild(actionInput);
                    
                    const riskInput = document.createElement('input');
                    riskInput.type = 'hidden';
                    riskInput.name = 'risk_id';
                    riskInput.value = riskId;
                    form.appendChild(riskInput);
                    
                    const approveInput = document.createElement('input');
                    approveInput.type = 'hidden';
                    approveInput.name = 'approve';
                    approveInput.value = approve ? '1' : '0';
                    form.appendChild(approveInput);
                    
                    if (!approve) {
                        const reasonInput = document.createElement('input');
                        reasonInput.type = 'hidden';
                        reasonInput.name = 'rejection_reason';
                        reasonInput.value = result.value;
                        form.appendChild(reasonInput);
                    }
                    
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }

        // Update Risk Status
        function updateRiskStatus(riskId, statusId, statusKey) {
            <?php if ($user_role == 'pm_employee'): ?>
            if (statusKey !== 'in_progress' && statusKey !== 'mitigated') {
                Swal.fire({
                    icon: 'error',
                    title: 'Permission Denied',
                    text: 'You can only change status to In Progress or Mitigated',
                    background: 'white',
                    borderRadius: '24px'
                });
                return;
            }
            <?php endif; ?>
            
            Swal.fire({
                title: 'Update Risk Status',
                text: 'Are you sure you want to change the risk status?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#273274',
                confirmButtonText: 'Yes, update',
                cancelButtonText: 'Cancel',
                background: 'white',
                borderRadius: '24px'
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = window.location.href;
                    
                    const actionInput = document.createElement('input');
                    actionInput.type = 'hidden';
                    actionInput.name = 'action';
                    actionInput.value = 'update_risk_status';
                    form.appendChild(actionInput);
                    
                    const riskInput = document.createElement('input');
                    riskInput.type = 'hidden';
                    riskInput.name = 'risk_id';
                    riskInput.value = riskId;
                    form.appendChild(riskInput);
                    
                    const statusInput = document.createElement('input');
                    statusInput.type = 'hidden';
                    statusInput.name = 'status_id';
                    statusInput.value = statusId;
                    form.appendChild(statusInput);
                    
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }

        // Close Risk
        function closeRisk(riskId) {
            <?php if ($user_role == 'pm_employee'): ?>
            Swal.fire({
                icon: 'error',
                title: 'Permission Denied',
                text: 'Only Project Managers and Super Admins can close risks',
                background: 'white',
                borderRadius: '24px'
            });
            return;
            <?php endif; ?>
            
            Swal.fire({
                title: 'Close Risk',
                text: 'Are you sure you want to close this risk?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#28a745',
                confirmButtonText: 'Yes, close',
                cancelButtonText: 'Cancel',
                background: 'white',
                borderRadius: '24px'
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = window.location.href;
                    
                    const actionInput = document.createElement('input');
                    actionInput.type = 'hidden';
                    actionInput.name = 'action';
                    actionInput.value = 'close_risk';
                    form.appendChild(actionInput);
                    
                    const riskInput = document.createElement('input');
                    riskInput.type = 'hidden';
                    riskInput.name = 'risk_id';
                    riskInput.value = riskId;
                    form.appendChild(riskInput);
                    
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }

        // Show Risk Assessment Modal
        function showRiskAssessmentModal(riskId) {
            document.getElementById('assessmentRiskId').value = riskId;
            const modal = new bootstrap.Modal(document.getElementById('riskAssessmentModal'));
            modal.show();
        }

        function submitRiskAssessment() {
            const riskId = document.getElementById('assessmentRiskId').value;
            const likelihood = document.querySelector('input[name="likelihood"]:checked')?.value;
            const impact = document.querySelector('input[name="impact"]:checked')?.value;
            
            if (!likelihood || !impact) {
                Swal.fire({
                    icon: 'error',
                    title: 'Validation Error',
                    text: 'Please select both likelihood and impact',
                    background: 'white',
                    borderRadius: '24px'
                });
                return;
            }
            
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = window.location.href;
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'update_risk'; // Use update_risk to include all changes
            form.appendChild(actionInput);
            
            const riskInput = document.createElement('input');
            riskInput.type = 'hidden';
            riskInput.name = 'risk_id';
            riskInput.value = riskId;
            form.appendChild(riskInput);
            
            const likeInput = document.createElement('input');
            likeInput.type = 'hidden';
            likeInput.name = 'likelihood';
            likeInput.value = likelihood;
            form.appendChild(likeInput);
            
            const impactInput = document.createElement('input');
            impactInput.type = 'hidden';
            impactInput.name = 'impact';
            impactInput.value = impact;
            form.appendChild(impactInput);
            
            // Also include current title to avoid validation errors
            <?php if ($risk_id && isset($risk)): ?>
            const titleInput = document.createElement('input');
            titleInput.type = 'hidden';
            titleInput.name = 'title';
            titleInput.value = '<?= e($risk['title']) ?>';
            form.appendChild(titleInput);
            <?php endif; ?>
            
            document.body.appendChild(form);
            form.submit();
        }

        // Show Response Plan Modal
        function showResponsePlanModal(riskId) {
            document.getElementById('responseRiskId').value = riskId;
            <?php if ($risk_id && isset($risk)): ?>
            document.getElementById('responseStrategy').value = '<?= e($risk['response_strategy'] ?? '') ?>';
            document.getElementById('targetDate').value = '<?= e($risk['target_resolution_date'] ?? '') ?>';
            <?php endif; ?>
            const modal = new bootstrap.Modal(document.getElementById('responsePlanModal'));
            modal.show();
        }

        // Show Assign Owner Modal
        function showAssignOwnerModal(riskId) {
            document.getElementById('ownerRiskId').value = riskId;
            const modal = new bootstrap.Modal(document.getElementById('assignOwnerModal'));
            modal.show();
        }

        // Update Mitigation Status
        function updateMitigationStatus(mitigationId, riskId, status) {
            Swal.fire({
                title: 'Update Mitigation Status',
                text: 'Are you sure you want to update this mitigation action?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#273274',
                confirmButtonText: 'Yes, update',
                cancelButtonText: 'Cancel',
                background: 'white',
                borderRadius: '24px'
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = window.location.href;
                    
                    const actionInput = document.createElement('input');
                    actionInput.type = 'hidden';
                    actionInput.name = 'action';
                    actionInput.value = 'update_mitigation_status';
                    form.appendChild(actionInput);
                    
                    const mitInput = document.createElement('input');
                    mitInput.type = 'hidden';
                    mitInput.name = 'mitigation_id';
                    mitInput.value = mitigationId;
                    form.appendChild(mitInput);
                    
                    const riskInput = document.createElement('input');
                    riskInput.type = 'hidden';
                    riskInput.name = 'risk_id';
                    riskInput.value = riskId;
                    form.appendChild(riskInput);
                    
                    const statusInput = document.createElement('input');
                    statusInput.type = 'hidden';
                    statusInput.name = 'status';
                    statusInput.value = status;
                    form.appendChild(statusInput);
                    
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }

        // Delete Mitigation
        function deleteMitigation(mitigationId, riskId, mitigationTitle) {
            Swal.fire({
                title: 'Delete Mitigation',
                html: `Are you sure you want to delete <strong>"${mitigationTitle}"</strong>?`,
                text: 'This action cannot be undone!',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                confirmButtonText: 'Yes, delete',
                cancelButtonText: 'Cancel',
                background: 'white',
                borderRadius: '24px'
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = window.location.href;
                    
                    const actionInput = document.createElement('input');
                    actionInput.type = 'hidden';
                    actionInput.name = 'action';
                    actionInput.value = 'delete_mitigation';
                    form.appendChild(actionInput);
                    
                    const mitInput = document.createElement('input');
                    mitInput.type = 'hidden';
                    mitInput.name = 'mitigation_id';
                    mitInput.value = mitigationId;
                    form.appendChild(mitInput);
                    
                    const riskInput = document.createElement('input');
                    riskInput.type = 'hidden';
                    riskInput.name = 'risk_id';
                    riskInput.value = riskId;
                    form.appendChild(riskInput);
                    
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }

        // Edit Mitigation
        function editMitigation(mitigation) {
            document.getElementById('editMitigationId').value = mitigation.id;
            document.getElementById('editMitigationRiskId').value = mitigation.risk_id;
            document.getElementById('editMitigationTitle').value = mitigation.title || '';
            document.getElementById('editMitigationOwner').value = mitigation.owner_user_id || '';
            document.getElementById('editMitigationDueDate').value = mitigation.due_date || '';
            document.getElementById('editMitigationStatus').value = mitigation.status || 'open';
            document.getElementById('editMitigationStrategy').value = mitigation.response_strategy || 'Mitigate';
            document.getElementById('editMitigationDescription').value = mitigation.description || '';
            
            const modal = new bootstrap.Modal(document.getElementById('editMitigationModal'));
            modal.show();
        }

        // Delete Risk
        function deleteRisk(riskId, riskTitle) {
            <?php 
            $can_delete_from_js = false;
            if ($user_role == 'super_admin') {
                $can_delete_from_js = true;
            } elseif ($user_role == 'pm_employee') {
                $can_delete_from_js = true;
            }
            ?>
            
            <?php if (!$can_delete_from_js): ?>
            Swal.fire({
                icon: 'error',
                title: 'Permission Denied',
                text: 'You do not have permission to delete risks.',
                background: 'white',
                borderRadius: '24px'
            });
            return;
            <?php endif; ?>
            
            Swal.fire({
                title: 'Delete Risk',
                html: `Are you sure you want to delete <strong>"${riskTitle}"</strong>?`,
                text: 'This action cannot be undone! All associated data will be permanently deleted.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                confirmButtonText: 'Yes, delete',
                cancelButtonText: 'Cancel',
                background: 'white',
                borderRadius: '24px'
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = window.location.href;
                    
                    const actionInput = document.createElement('input');
                    actionInput.type = 'hidden';
                    actionInput.name = 'action';
                    actionInput.value = 'delete_risk';
                    form.appendChild(actionInput);
                    
                    const riskInput = document.createElement('input');
                    riskInput.type = 'hidden';
                    riskInput.name = 'risk_id';
                    riskInput.value = riskId;
                    form.appendChild(riskInput);
                    
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }
    </script>
</body>
</html>
<?php
$conn->close();
?>