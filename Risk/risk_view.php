<?php
// risk_view.php - Risk Details View with EXACT SRS Workflow Implementation
// Version 3.0 - FIXED: All AJAX actions, project validation, enhanced UI
// Last Updated: 2026-02-12

session_start();
require_once '../db.php';

// =============================================
// DATABASE INITIALIZATION - Ensure all tables/fields exist
// =============================================
// =============================================
// DATABASE INITIALIZATION - FIXED for MariaDB/MySQL
// =============================================
function initialize_risk_module($conn) {
    // 1. Ensure risk_statuses has all required statuses (SRS 3.1.5)
    $required_statuses = [
        ['pending_review', 'Pending Review', 1],
        ['open', 'Open', 2],
        ['in_progress', 'In Progress', 3],
        ['mitigated', 'Mitigated', 4],
        ['closed', 'Closed', 5],
        ['rejected', 'Rejected', 6]
    ];
    
    foreach ($required_statuses as $status) {
        $check = $conn->prepare("SELECT id FROM risk_statuses WHERE status_key = ?");
        $check->bind_param('s', $status[0]);
        $check->execute();
        $result = $check->get_result();
        
        if ($result->num_rows === 0) {
            $insert = $conn->prepare("INSERT INTO risk_statuses (status_key, label, is_active, created_at) VALUES (?, ?, 1, NOW())");
            $insert->bind_param('ss', $status[0], $status[1]);
            $insert->execute();
            $insert->close();
        }
        $check->close();
    }
    
    // 2. Ensure risk_comments table exists (SRS 4.2)
    $conn->query("CREATE TABLE IF NOT EXISTS `risk_comments` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `risk_id` int(11) NOT NULL,
        `user_id` int(11) NOT NULL,
        `comment_text` text NOT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `idx_risk_comments_risk` (`risk_id`),
        KEY `idx_risk_comments_user` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    
    // Add foreign key constraints for risk_comments if they don't exist
    $fk_check = $conn->query("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS 
                              WHERE CONSTRAINT_NAME = 'fk_risk_comments_risk' 
                              AND TABLE_NAME = 'risk_comments'");
    $fk_exists = $fk_check->fetch_array()[0] > 0;
    
    if (!$fk_exists) {
        $conn->query("ALTER TABLE `risk_comments` 
                      ADD CONSTRAINT `fk_risk_comments_risk` 
                      FOREIGN KEY (`risk_id`) REFERENCES `risks` (`id`) ON DELETE CASCADE");
    }
    
    $fk_check = $conn->query("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS 
                              WHERE CONSTRAINT_NAME = 'fk_risk_comments_user' 
                              AND TABLE_NAME = 'risk_comments'");
    $fk_exists = $fk_check->fetch_array()[0] > 0;
    
    if (!$fk_exists) {
        $conn->query("ALTER TABLE `risk_comments` 
                      ADD CONSTRAINT `fk_risk_comments_user` 
                      FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE");
    }
    
    // 3. Add missing fields to risks table if not exist (SRS 4.1)
    $fields = [
        'response_strategy' => "ALTER TABLE risks ADD COLUMN IF NOT EXISTS `response_strategy` enum('Avoid','Mitigate','Transfer','Accept') DEFAULT NULL AFTER `risk_level`",
        'identified_by' => "ALTER TABLE risks ADD COLUMN IF NOT EXISTS `identified_by` int(11) DEFAULT NULL AFTER `owner_user_id`",
        'approved_by' => "ALTER TABLE risks ADD COLUMN IF NOT EXISTS `approved_by` int(11) DEFAULT NULL AFTER `identified_by`",
        'approved_at' => "ALTER TABLE risks ADD COLUMN IF NOT EXISTS `approved_at` datetime DEFAULT NULL AFTER `approved_by`",
        'rejection_reason' => "ALTER TABLE risks ADD COLUMN IF NOT EXISTS `rejection_reason` text DEFAULT NULL AFTER `approved_at`",
        'target_resolution_date' => "ALTER TABLE risks ADD COLUMN IF NOT EXISTS `target_resolution_date` date DEFAULT NULL AFTER `risk_date`"
    ];
    
    foreach ($fields as $field => $sql) {
        $result = $conn->query("SHOW COLUMNS FROM risks LIKE '$field'");
        if ($result->num_rows === 0) {
            $conn->query($sql);
        }
    }
    
    // 4. Add foreign key constraints for risks table if they don't exist
    // Check and add identified_by foreign key
    $fk_check = $conn->query("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE 
                              WHERE CONSTRAINT_NAME = 'fk_risks_identified_by' 
                              AND TABLE_NAME = 'risks'");
    $fk_exists = $fk_check->fetch_array()[0] > 0;
    
    if (!$fk_exists) {
        // First check if the column exists and has any invalid references
        $conn->query("UPDATE risks SET identified_by = NULL WHERE identified_by IS NOT NULL AND NOT EXISTS (SELECT 1 FROM users WHERE id = identified_by)");
        $conn->query("ALTER TABLE risks ADD CONSTRAINT `fk_risks_identified_by` FOREIGN KEY (`identified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL");
    }
    
    // Check and add approved_by foreign key
    $fk_check = $conn->query("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE 
                              WHERE CONSTRAINT_NAME = 'fk_risks_approved_by' 
                              AND TABLE_NAME = 'risks'");
    $fk_exists = $fk_check->fetch_array()[0] > 0;
    
    if (!$fk_exists) {
        // First check if the column exists and has any invalid references
        $conn->query("UPDATE risks SET approved_by = NULL WHERE approved_by IS NOT NULL AND NOT EXISTS (SELECT 1 FROM users WHERE id = approved_by)");
        $conn->query("ALTER TABLE risks ADD CONSTRAINT `fk_risks_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL");
    }
    
    // 5. Add response_strategy to risk_mitigations if not exist
    $result = $conn->query("SHOW COLUMNS FROM risk_mitigations LIKE 'response_strategy'");
    if ($result->num_rows === 0) {
        $conn->query("ALTER TABLE risk_mitigations ADD COLUMN `response_strategy` enum('Avoid','Mitigate','Transfer','Accept') DEFAULT 'Mitigate' AFTER `title`");
    }
    
    // 6. Add updated_by column to risk_mitigations if not exist
    $result = $conn->query("SHOW COLUMNS FROM risk_mitigations LIKE 'updated_by'");
    if ($result->num_rows === 0) {
        $conn->query("ALTER TABLE risk_mitigations ADD COLUMN `updated_by` int(11) DEFAULT NULL AFTER `created_by`");
        
        // Add foreign key constraint
        $fk_check = $conn->query("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE 
                                  WHERE CONSTRAINT_NAME = 'fk_risk_mitigations_updated_by' 
                                  AND TABLE_NAME = 'risk_mitigations'");
        $fk_exists = $fk_check->fetch_array()[0] > 0;
        
        if (!$fk_exists) {
            $conn->query("ALTER TABLE risk_mitigations ADD CONSTRAINT `fk_risk_mitigations_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL");
        }
    }
}

// Initialize database schema
initialize_risk_module($conn);

// =============================================
// ROLE-BASED ACCESS CONTROL (SRS Section 2.2)
// =============================================
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$current_user_id = $_SESSION['user_id'];

// Get current user role and details
$user_sql = "SELECT id, username, email, system_role FROM users WHERE id = ?";
$stmt = $conn->prepare($user_sql);
$stmt->bind_param("i", $current_user_id);
$stmt->execute();
$current_user = $stmt->get_result()->fetch_assoc();
$stmt->close();

$user_role = $current_user['system_role'] ?? '';
$username = $current_user['username'] ?? 'User';

// =============================================
// HELPER FUNCTIONS
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

// Compute risk level from score (SRS Section 3.1.3)
function risk_level_from_score($score) {
    if ($score >= 13 && $score <= 25) return 'High';
    if ($score >= 6 && $score <= 12) return 'Medium';
    return 'Low'; // 1-5
}

// Get status ID by key (SRS Section 3.1.5)
function get_status_id_by_key($conn, $status_key) {
    $stmt = $conn->prepare("SELECT id FROM risk_statuses WHERE status_key = ? LIMIT 1");
    $stmt->bind_param('s', $status_key);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row ? (int)$row['id'] : null;
}

// Get status key by ID
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
// CHECK IF RISK EXISTS AND FETCH DETAILS
// =============================================
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['error'] = 'Invalid risk ID';
    header('Location: risks.php');
    exit;
}

$risk_id = (int)$_GET['id'];

// Fetch risk details with all related information - ENHANCED with more details
$sql = "SELECT 
            r.*, 
            p.name AS project_name, 
            p.status AS project_status,
            p.start_date AS project_start_date,
            p.end_date AS project_end_date,
            d.department_name, 
            owner.id AS owner_id,
            owner.username AS owner_name,
            owner.email AS owner_email,
            creator.id AS creator_id,
            creator.username AS creator_name,
            creator.email AS creator_email,
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

// Check if project is terminated - SRS Section 6.1 NB
if ($risk['project_status'] === 'terminated') {
    $_SESSION['error'] = 'This risk belongs to a terminated project and cannot be accessed';
    header('Location: risks.php');
    exit;
}

// =============================================
// CALCULATE RISK SCORE AND LEVEL
// =============================================
$score = (int)$risk['likelihood'] * (int)$risk['impact'];
$risk_class = strtolower($risk['risk_level'] ?? 'low');
$status_key = $risk['status_key'] ?? '';

// Calculate days since creation
$days_since_creation = floor((time() - strtotime($risk['created_at'])) / (60 * 60 * 24));

// Calculate days until target resolution
$days_until_target = null;
if ($risk['target_resolution_date']) {
    $days_until_target = floor((strtotime($risk['target_resolution_date']) - time()) / (60 * 60 * 24));
}

// =============================================
// USER PROJECT ACCESS VERIFICATION (SRS Section 6.1 NB)
// =============================================
function has_project_access($conn, $user_id, $project_id, $user_role) {
    if ($user_role === 'super_admin') {
        return true;
    }
    
    // Check user_assignments table
    $sql1 = "SELECT id FROM user_assignments WHERE user_id = ? AND project_id = ? AND is_active = 1 LIMIT 1";
    $stmt1 = $conn->prepare($sql1);
    $stmt1->bind_param('ii', $user_id, $project_id);
    $stmt1->execute();
    $result1 = $stmt1->get_result();
    $has_access = $result1->num_rows > 0;
    $stmt1->close();
    
    if ($has_access) return true;
    
    // Check project_users table
    $sql2 = "SELECT id FROM project_users WHERE user_id = ? AND project_id = ? LIMIT 1";
    $stmt2 = $conn->prepare($sql2);
    $stmt2->bind_param('ii', $user_id, $project_id);
    $stmt2->execute();
    $result2 = $stmt2->get_result();
    $has_access = $result2->num_rows > 0;
    $stmt2->close();
    
    return $has_access;
}

$project_access = has_project_access($conn, $current_user_id, $risk['project_id'], $user_role);

if (!$project_access) {
    $_SESSION['error'] = 'You do not have access to this risk';
    header('Location: risks.php');
    exit;
}

// =============================================
// EXACT SRS SECTION 2.2 - ROLE PERMISSIONS MATRIX
// =============================================

// Initialize all permissions to false
$permissions = [
    'super_admin' => false,
    'pm_manager' => false,
    'pm_employee' => false
];

// =============================================
// SRS SECTION 2.2.1 - SUPER ADMIN PERMISSIONS
// =============================================
if ($user_role === 'super_admin') {
    $permissions['super_admin'] = true;
    
    $can_view = true;
    $can_create = true;
    $can_edit = true;
    $can_delete = true;
    $can_override_status = true;
    $can_view_reports = true;
    $can_manage_categories = true;
    $can_export = true;
    $can_approve_risk = true;
    $can_assess_risk = true;
    $can_assign_owner = true;
    $can_set_mitigation = true;
    $can_update_status = true;
    $can_close_risk = true;
    $can_add_mitigation = true;
    $can_edit_mitigation = true;
    $can_delete_mitigation = true;
    $can_update_mitigation_status = true;
    $can_add_comment = true;
}

// =============================================
// SRS SECTION 2.2.2 - PROJECT MANAGER PERMISSIONS
// =============================================
elseif ($user_role === 'pm_manager') {
    $permissions['pm_manager'] = true;
    
    $can_view = true;
    $can_create = true;
    $can_delete = false;
    $can_override_status = false;
    $can_view_reports = true;
    $can_manage_categories = false;
    $can_export = true;
    $can_approve_risk = true;
    $can_assess_risk = true;
    $can_assign_owner = true;
    $can_set_mitigation = true;
    $can_update_status = true;
    $can_close_risk = true;
    $can_add_mitigation = true;
    $can_edit_mitigation = true;
    $can_delete_mitigation = false;
    $can_update_mitigation_status = true;
    $can_add_comment = true;
    $can_edit = true;
}

// =============================================
// SRS SECTION 2.2.3 - PROJECT EMPLOYEE PERMISSIONS
// =============================================
elseif ($user_role === 'pm_employee') {
    $permissions['pm_employee'] = true;
    
    $can_view = true;
    $can_create = true;
    $can_delete = false;
    $can_override_status = false;
    $can_view_reports = false;
    $can_manage_categories = false;
    $can_export = false;
    $can_approve_risk = false;
    $can_assess_risk = false;
    $can_assign_owner = false;
    $can_set_mitigation = false;
    $can_close_risk = false;
    
    // EDIT PERMISSION - Edit risks they reported (BEFORE approval)
    $can_edit = false;
    if ($risk['created_by'] == $current_user_id && $status_key === 'pending_review') {
        $can_edit = true;
    }
    
    // STATUS UPDATE - Update risks assigned to them
    $can_update_status = false;
    if ($risk['owner_user_id'] == $current_user_id) {
        $can_update_status = true;
    }
    
    // MITIGATION PERMISSIONS - Update risks assigned to them
    $can_add_mitigation = false;
    $can_edit_mitigation = false;
    $can_delete_mitigation = false;
    $can_update_mitigation_status = false;
    
    if ($risk['owner_user_id'] == $current_user_id) {
        $can_add_mitigation = true;
        $can_edit_mitigation = true;
        $can_update_mitigation_status = true;
    }
    
    // COMMENT PERMISSIONS
    $can_add_comment = true;
}

// =============================================
// DEFAULT PERMISSIONS FOR UNKNOWN ROLES
// =============================================
else {
    $can_view = false;
    $can_create = false;
    $can_edit = false;
    $can_delete = false;
    $can_override_status = false;
    $can_view_reports = false;
    $can_manage_categories = false;
    $can_export = false;
    $can_approve_risk = false;
    $can_assess_risk = false;
    $can_assign_owner = false;
    $can_set_mitigation = false;
    $can_update_status = false;
    $can_close_risk = false;
    $can_add_mitigation = false;
    $can_edit_mitigation = false;
    $can_delete_mitigation = false;
    $can_update_mitigation_status = false;
    $can_add_comment = false;
}

// =============================================
// FETCH ALL STATUSES FOR WORKFLOW
// =============================================
$statuses_res = $conn->query("SELECT id, status_key, label FROM risk_statuses WHERE is_active = 1 ORDER BY id");
$all_statuses = $statuses_res ? $statuses_res->fetch_all(MYSQLI_ASSOC) : [];

// =============================================
// FETCH PROJECT USERS (Only assigned users - SRS Section 6.1 NB)
// =============================================
function get_project_users($conn, $project_id, $user_role) {
    if ($user_role === 'super_admin') {
        $sql = "SELECT u.id, u.username, u.email, u.system_role
                FROM users u
                WHERE u.system_role IN ('pm_manager', 'pm_employee') 
                AND EXISTS (
                    SELECT 1 FROM user_assignments ua WHERE ua.user_id = u.id AND ua.is_active = 1
                    UNION
                    SELECT 1 FROM project_users pu WHERE pu.user_id = u.id
                )
                ORDER BY u.username";
        $result = $conn->query($sql);
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }
    
    $sql = "SELECT DISTINCT u.id, u.username, u.email, u.system_role
            FROM users u
            LEFT JOIN user_assignments ua ON u.id = ua.user_id AND ua.project_id = ? AND ua.is_active = 1
            LEFT JOIN project_users pu ON u.id = pu.user_id AND pu.project_id = ?
            WHERE (ua.user_id IS NOT NULL OR pu.user_id IS NOT NULL)
            AND u.system_role IN ('pm_manager', 'pm_employee')
            ORDER BY u.username";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $project_id, $project_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $users = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $users;
}

$project_users = get_project_users($conn, $risk['project_id'], $user_role);

// =============================================
// FETCH MITIGATIONS - ENHANCED with more details
// =============================================
$mit_stmt = $conn->prepare("SELECT 
                                m.*, 
                                u.username as owner_name,
                                u.email as owner_email,
                                u.system_role as owner_role,
                                creator.username as created_by_name,
                                creator.id as created_by_id,
                                updater.username as updated_by_name,
                                (SELECT COUNT(*) FROM risk_history rh WHERE rh.risk_id = m.risk_id AND rh.comment LIKE CONCAT('%Mitigation updated: ', m.title, '%')) AS update_count
                            FROM risk_mitigations m 
                            LEFT JOIN users u ON m.owner_user_id = u.id 
                            LEFT JOIN users creator ON m.created_by = creator.id
                            LEFT JOIN users updater ON m.updated_by = updater.id
                            WHERE m.risk_id = ? 
                            ORDER BY 
                                CASE 
                                    WHEN m.status = 'open' THEN 1
                                    WHEN m.status = 'in_progress' THEN 2
                                    WHEN m.status = 'done' THEN 3
                                    ELSE 4
                                END, 
                                m.due_date ASC, 
                                m.id DESC");
$mit_stmt->bind_param('i', $risk_id);
$mit_stmt->execute();
$mitigations = $mit_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$mit_stmt->close();

// Calculate mitigation statistics
$total_mitigations = count($mitigations);
$completed_mitigations = 0;
$in_progress_mitigations = 0;
$open_mitigations = 0;

foreach ($mitigations as $m) {
    if ($m['status'] == 'done') $completed_mitigations++;
    elseif ($m['status'] == 'in_progress') $in_progress_mitigations++;
    elseif ($m['status'] == 'open') $open_mitigations++;
}

$mitigation_progress = $total_mitigations > 0 ? round(($completed_mitigations / $total_mitigations) * 100) : 0;

// =============================================
// FETCH COMMENTS (SRS Section 3.1.6) - ENHANCED
// =============================================
$comment_stmt = $conn->prepare("SELECT 
                                    c.*, 
                                    u.username as user_name,
                                    u.email as user_email,
                                    u.system_role as user_role,
                                    u.profile_picture
                                FROM risk_comments c
                                LEFT JOIN users u ON c.user_id = u.id
                                WHERE c.risk_id = ? 
                                ORDER BY c.created_at DESC");
$comment_stmt->bind_param('i', $risk_id);
$comment_stmt->execute();
$comments = $comment_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$comment_stmt->close();

// =============================================
// FETCH HISTORY - ENHANCED
// =============================================
$hist_stmt = $conn->prepare("SELECT 
                                h.*, 
                                u.username as changed_by_name,
                                u.email as changed_by_email,
                                u.system_role as changed_by_role
                            FROM risk_history h 
                            LEFT JOIN users u ON h.changed_by = u.id 
                            WHERE h.risk_id = ? 
                            ORDER BY h.created_at DESC 
                            LIMIT 50");
$hist_stmt->bind_param('i', $risk_id);
$hist_stmt->execute();
$history = $hist_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$hist_stmt->close();

// =============================================
// FETCH RISK CATEGORIES FOR REFERENCE
// =============================================
$cat_stmt = $conn->prepare("SELECT id, name, description FROM risk_categories WHERE id = ?");
$cat_stmt->bind_param('i', $risk['category_id']);
$cat_stmt->execute();
$category_detail = $cat_stmt->get_result()->fetch_assoc();
$cat_stmt->close();

// =============================================
// HANDLE POST REQUESTS (AJAX) - FIXED with proper URL handling
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];
    
    // Get the current script name for proper form action
    $current_script = basename($_SERVER['PHP_SELF']);
    
    // =========================================
    // SRS SECTION 3.1.2 - RISK REVIEW AND APPROVAL
    // =========================================
    if ($_POST['action'] === 'approve_risk') {
        if (!$can_approve_risk) {
            $response['message'] = 'Permission denied: Only Project Managers and Super Admins can approve risks';
            echo json_encode($response);
            exit;
        }
        
        $approve = isset($_POST['approve']) && $_POST['approve'] == '1';
        $rejection_reason = trim($_POST['rejection_reason'] ?? '');
        
        $conn->begin_transaction();
        try {
            if ($approve) {
                $status_id = get_status_id_by_key($conn, 'open');
                $sql = "UPDATE risks SET status_id = ?, approved_by = ?, approved_at = NOW(), updated_at = NOW() WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('iii', $status_id, $current_user_id, $risk_id);
                $comment_text = "Risk approved by " . $username;
                $success_message = 'Risk approved successfully';
            } else {
                $status_id = get_status_id_by_key($conn, 'rejected');
                $sql = "UPDATE risks SET status_id = ?, rejection_reason = ?, updated_at = NOW() WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('isi', $status_id, $rejection_reason, $risk_id);
                $comment_text = "Risk rejected: " . $rejection_reason;
                $success_message = 'Risk rejected successfully';
            }
            
            $stmt->execute();
            $stmt->close();
            
            // Add to history
            $hist_sql = "INSERT INTO risk_history (risk_id, changed_by, change_type, comment, created_at) 
                        VALUES (?, ?, 'status_changed', ?, NOW())";
            $hstmt = $conn->prepare($hist_sql);
            $hstmt->bind_param('iis', $risk_id, $current_user_id, $comment_text);
            $hstmt->execute();
            $hstmt->close();
            
            $conn->commit();
            $response['success'] = true;
            $response['message'] = $success_message;
        } catch (Exception $e) {
            $conn->rollback();
            $response['message'] = 'Error: ' . $e->getMessage();
        }
        
        echo json_encode($response);
        exit;
    }
    
    // =========================================
    // SRS SECTION 3.1.3 - RISK ASSESSMENT - FIXED
    // =========================================
    if ($_POST['action'] === 'assess_risk') {
        if (!$can_assess_risk) {
            $response['message'] = 'Permission denied: Only Project Managers and Super Admins can assess risks';
            echo json_encode($response);
            exit;
        }
        
        $likelihood = isset($_POST['likelihood']) ? max(1, min(5, (int)$_POST['likelihood'])) : 3;
        $impact = isset($_POST['impact']) ? max(1, min(5, (int)$_POST['impact'])) : 3;
        $score = $likelihood * $impact;
        $risk_level = risk_level_from_score($score);
        
        $conn->begin_transaction();
        try {
            $sql = "UPDATE risks SET likelihood = ?, impact = ?, risk_score = ?, risk_level = ?, updated_at = NOW() WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('iiisi', $likelihood, $impact, $score, $risk_level, $risk_id);
            $stmt->execute();
            $stmt->close();
            
            // Add to history
            $hist_sql = "INSERT INTO risk_history (risk_id, changed_by, change_type, comment, created_at) 
                        VALUES (?, ?, 'assessed', ?, NOW())";
            $hstmt = $conn->prepare($hist_sql);
            $comment = "Risk assessed: Likelihood={$likelihood} (" . get_likelihood_label($likelihood) . "), Impact={$impact} (" . get_impact_label($impact) . "), Score={$score}, Level={$risk_level}";
            $hstmt->bind_param('iis', $risk_id, $current_user_id, $comment);
            $hstmt->execute();
            $hstmt->close();
            
            $conn->commit();
            $response['success'] = true;
            $response['message'] = 'Risk assessment updated successfully';
            $response['score'] = $score;
            $response['risk_level'] = $risk_level;
        } catch (Exception $e) {
            $conn->rollback();
            $response['message'] = 'Error: ' . $e->getMessage();
        }
        
        echo json_encode($response);
        exit;
    }
    
    // =========================================
    // SRS SECTION 3.1.4 - RISK RESPONSE PLANNING - FIXED
    // =========================================
    if ($_POST['action'] === 'update_response_plan') {
        if (!$can_set_mitigation) {
            $response['message'] = 'Permission denied: Only Project Managers and Super Admins can define response plans';
            echo json_encode($response);
            exit;
        }
        
        $response_strategy = $_POST['response_strategy'] ?? null;
        $target_resolution_date = !empty($_POST['target_resolution_date']) ? $_POST['target_resolution_date'] : null;
        
        $conn->begin_transaction();
        try {
            $sql = "UPDATE risks SET response_strategy = ?, target_resolution_date = ?, updated_at = NOW() WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ssi', $response_strategy, $target_resolution_date, $risk_id);
            $stmt->execute();
            $stmt->close();
            
            // Add to history
            $hist_sql = "INSERT INTO risk_history (risk_id, changed_by, change_type, comment, created_at) 
                        VALUES (?, ?, 'response_plan_updated', ?, NOW())";
            $hstmt = $conn->prepare($hist_sql);
            $comment = "Response strategy set to: " . ($response_strategy ?: 'None') . ", Target date: " . ($target_resolution_date ?: 'None');
            $hstmt->bind_param('iis', $risk_id, $current_user_id, $comment);
            $hstmt->execute();
            $hstmt->close();
            
            $conn->commit();
            $response['success'] = true;
            $response['message'] = 'Response plan updated successfully';
        } catch (Exception $e) {
            $conn->rollback();
            $response['message'] = 'Error: ' . $e->getMessage();
        }
        
        echo json_encode($response);
        exit;
    }
    
    // =========================================
    // SRS SECTION 3.1.4 - ASSIGN RISK OWNER - FIXED
    // =========================================
    if ($_POST['action'] === 'assign_owner') {
        if (!$can_assign_owner) {
            $response['message'] = 'Permission denied: Only Project Managers and Super Admins can assign risk owners';
            echo json_encode($response);
            exit;
        }
        
        $owner_user_id = !empty($_POST['owner_user_id']) ? (int)$_POST['owner_user_id'] : null;
        
        $conn->begin_transaction();
        try {
            $sql = "UPDATE risks SET owner_user_id = ?, updated_at = NOW() WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ii', $owner_user_id, $risk_id);
            $stmt->execute();
            $stmt->close();
            
            // Get owner name for history
            $owner_name = 'Unassigned';
            if ($owner_user_id) {
                $name_stmt = $conn->prepare("SELECT username FROM users WHERE id = ?");
                $name_stmt->bind_param('i', $owner_user_id);
                $name_stmt->execute();
                $name_result = $name_stmt->get_result()->fetch_assoc();
                $owner_name = $name_result['username'] ?? 'Unknown';
                $name_stmt->close();
            }
            
            // Add to history
            $hist_sql = "INSERT INTO risk_history (risk_id, changed_by, change_type, comment, created_at) 
                        VALUES (?, ?, 'owner_assigned', ?, NOW())";
            $hstmt = $conn->prepare($hist_sql);
            $comment = "Risk owner assigned to: " . $owner_name;
            $hstmt->bind_param('iis', $risk_id, $current_user_id, $comment);
            $hstmt->execute();
            $hstmt->close();
            
            $conn->commit();
            $response['success'] = true;
            $response['message'] = 'Risk owner assigned successfully';
            $response['owner_name'] = $owner_name;
        } catch (Exception $e) {
            $conn->rollback();
            $response['message'] = 'Error: ' . $e->getMessage();
        }
        
        echo json_encode($response);
        exit;
    }
    
    // =========================================
    // SRS SECTION 3.1.5 - UPDATE RISK STATUS - FIXED
    // =========================================
    if ($_POST['action'] === 'update_risk_status') {
        $new_status_id = isset($_POST['status_id']) && $_POST['status_id'] !== '' ? (int)$_POST['status_id'] : null;
        
        if (!$new_status_id) {
            $response['message'] = 'Invalid status selected';
            echo json_encode($response);
            exit;
        }
        
        // Get status key for permission checking
        $status_key = get_status_key_by_id($conn, $new_status_id);
        
        // PM EMPLOYEE PERMISSION CHECK
        if ($user_role === 'pm_employee') {
            if ($risk['owner_user_id'] != $current_user_id) {
                $response['message'] = 'Permission denied: You can only update risks assigned to you';
                echo json_encode($response);
                exit;
            }
            
            if ($status_key !== 'in_progress') {
                $response['message'] = 'Permission denied: You can only change status to In Progress';
                echo json_encode($response);
                exit;
            }
        }
        
        // CLOSE RISK PERMISSION CHECK
        if ($status_key === 'closed' && !$can_close_risk) {
            $response['message'] = 'Permission denied: Only Project Managers and Super Admins can close risks';
            echo json_encode($response);
            exit;
        }
        
        // Get current status for history
        $current_status_key = get_status_key_by_id($conn, $risk['status_id']);
        
        $conn->begin_transaction();
        try {
            $sql = "UPDATE risks SET status_id = ?, updated_at = NOW() WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ii', $new_status_id, $risk_id);
            $stmt->execute();
            $stmt->close();
            
            // Get status label
            $label_stmt = $conn->prepare("SELECT label FROM risk_statuses WHERE id = ?");
            $label_stmt->bind_param('i', $new_status_id);
            $label_stmt->execute();
            $label_result = $label_stmt->get_result()->fetch_assoc();
            $status_label = $label_result['label'] ?? $status_key;
            $label_stmt->close();
            
            // Add to history
            $hist_sql = "INSERT INTO risk_history (risk_id, changed_by, change_type, comment, created_at) 
                        VALUES (?, ?, 'status_changed', ?, NOW())";
            $hstmt = $conn->prepare($hist_sql);
            $comment = "Risk status changed from '{$current_status_key}' to '{$status_key}' ({$status_label})";
            $hstmt->bind_param('iis', $risk_id, $current_user_id, $comment);
            $hstmt->execute();
            $hstmt->close();
            
            $conn->commit();
            $response['success'] = true;
            $response['message'] = 'Risk status updated successfully';
        } catch (Exception $e) {
            $conn->rollback();
            $response['message'] = 'Error: ' . $e->getMessage();
        }
        
        echo json_encode($response);
        exit;
    }
    
    // =========================================
    // SRS SECTION 3.1.5 - CLOSE RISK - FIXED
    // =========================================
    if ($_POST['action'] === 'close_risk') {
        if (!$can_close_risk) {
            $response['message'] = 'Permission denied: Only Project Managers and Super Admins can close risks';
            echo json_encode($response);
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
            
            // Add to history
            $hist_sql = "INSERT INTO risk_history (risk_id, changed_by, change_type, comment, created_at) 
                        VALUES (?, ?, 'closed', ?, NOW())";
            $hstmt = $conn->prepare($hist_sql);
            $comment = "Risk closed by " . $username;
            $hstmt->bind_param('iis', $risk_id, $current_user_id, $comment);
            $hstmt->execute();
            $hstmt->close();
            
            $conn->commit();
            $response['success'] = true;
            $response['message'] = 'Risk closed successfully';
        } catch (Exception $e) {
            $conn->rollback();
            $response['message'] = 'Error: ' . $e->getMessage();
        }
        
        echo json_encode($response);
        exit;
    }
    
    // =========================================
    // SRS SECTION 3.1.4 - ADD MITIGATION ACTION - FIXED
    // =========================================
    if ($_POST['action'] === 'add_mitigation') {
        if (!$can_add_mitigation) {
            $response['message'] = 'Permission denied: You do not have permission to add mitigation actions';
            echo json_encode($response);
            exit;
        }
        
        $mit_title = trim($_POST['mit_title'] ?? '');
        $mit_desc = trim($_POST['mit_description'] ?? '');
        $mit_owner = !empty($_POST['mit_owner_user_id']) ? (int)$_POST['mit_owner_user_id'] : null;
        $mit_due_date = !empty($_POST['mit_due_date']) ? $_POST['mit_due_date'] : null;
        $mit_response_strategy = !empty($_POST['mit_response_strategy']) ? $_POST['mit_response_strategy'] : 'Mitigate';
        
        if (empty($mit_title)) {
            $response['message'] = 'Mitigation title is required';
            echo json_encode($response);
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
            
            // Get owner name for history
            $owner_name = 'Unassigned';
            if ($mit_owner) {
                $name_stmt = $conn->prepare("SELECT username FROM users WHERE id = ?");
                $name_stmt->bind_param('i', $mit_owner);
                $name_stmt->execute();
                $name_result = $name_stmt->get_result()->fetch_assoc();
                $owner_name = $name_result['username'] ?? 'Unknown';
                $name_stmt->close();
            }
            
            // Add to history
            $hist_sql = "INSERT INTO risk_history (risk_id, changed_by, change_type, comment, created_at) 
                        VALUES (?, ?, 'mitigation_added', ?, NOW())";
            $hstmt = $conn->prepare($hist_sql);
            $comment = "Mitigation added: {$mit_title}, Owner: {$owner_name}, Due: " . ($mit_due_date ?: 'None');
            $hstmt->bind_param('iis', $risk_id, $current_user_id, $comment);
            $hstmt->execute();
            $hstmt->close();
            
            $conn->commit();
            $response['success'] = true;
            $response['message'] = 'Mitigation action added successfully';
            $response['mitigation_id'] = $mitigation_id;
        } catch (Exception $e) {
            $conn->rollback();
            $response['message'] = 'Error: ' . $e->getMessage();
        }
        
        echo json_encode($response);
        exit;
    }
    
    // =========================================
    // UPDATE MITIGATION STATUS - FIXED
    // =========================================
    if ($_POST['action'] === 'update_mitigation_status') {
        $mitigation_id = (int)($_POST['mitigation_id'] ?? 0);
        $status = $_POST['status'] ?? 'open';
        
        $valid_statuses = ['open', 'in_progress', 'done', 'cancelled'];
        if (!in_array($status, $valid_statuses)) {
            $status = 'open';
        }
        
        // Check if user can update this mitigation
        $perm_check = $conn->prepare("SELECT owner_user_id, created_by FROM risk_mitigations WHERE id = ?");
        $perm_check->bind_param('i', $mitigation_id);
        $perm_check->execute();
        $perm_result = $perm_check->get_result()->fetch_assoc();
        $perm_check->close();
        
        $can_update = false;
        if ($user_role === 'super_admin') $can_update = true;
        elseif ($user_role === 'pm_manager') $can_update = true;
        elseif ($perm_result && ($perm_result['owner_user_id'] == $current_user_id || $perm_result['created_by'] == $current_user_id)) {
            $can_update = true;
        }
        
        if (!$can_update) {
            $response['message'] = 'Permission denied: You cannot update this mitigation action';
            echo json_encode($response);
            exit;
        }
        
        $conn->begin_transaction();
        try {
            $sql = "UPDATE risk_mitigations SET status = ?, updated_at = NOW(), updated_by = ? WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('sii', $status, $current_user_id, $mitigation_id);
            $stmt->execute();
            $stmt->close();
            
            // Add to history
            $hist_sql = "INSERT INTO risk_history (risk_id, changed_by, change_type, comment, created_at) 
                        VALUES (?, ?, 'mitigation_status_changed', ?, NOW())";
            $hstmt = $conn->prepare($hist_sql);
            $status_label = ucfirst(str_replace('_', ' ', $status));
            $comment = "Mitigation #{$mitigation_id} status changed to: {$status_label}";
            $hstmt->bind_param('iis', $risk_id, $current_user_id, $comment);
            $hstmt->execute();
            $hstmt->close();
            
            $conn->commit();
            $response['success'] = true;
            $response['message'] = 'Mitigation status updated successfully';
        } catch (Exception $e) {
            $conn->rollback();
            $response['message'] = 'Error: ' . $e->getMessage();
        }
        
        echo json_encode($response);
        exit;
    }
    
    // =========================================
    // EDIT MITIGATION - FIXED
    // =========================================
    if ($_POST['action'] === 'update_mitigation') {
        $mitigation_id = (int)($_POST['mitigation_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $owner_user_id = !empty($_POST['owner_user_id']) ? (int)$_POST['owner_user_id'] : null;
        $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
        $status = $_POST['status'] ?? 'open';
        $response_strategy = $_POST['response_strategy'] ?? 'Mitigate';
        
        if (empty($title)) {
            $response['message'] = 'Mitigation title is required';
            echo json_encode($response);
            exit;
        }
        
        // Check permission
        $perm_check = $conn->prepare("SELECT owner_user_id, created_by FROM risk_mitigations WHERE id = ?");
        $perm_check->bind_param('i', $mitigation_id);
        $perm_check->execute();
        $perm_result = $perm_check->get_result()->fetch_assoc();
        $perm_check->close();
        
        $can_edit = false;
        if ($user_role === 'super_admin') $can_edit = true;
        elseif ($user_role === 'pm_manager') $can_edit = true;
        elseif ($perm_result && ($perm_result['owner_user_id'] == $current_user_id || $perm_result['created_by'] == $current_user_id)) {
            $can_edit = true;
        }
        
        if (!$can_edit) {
            $response['message'] = 'Permission denied: You cannot edit this mitigation action';
            echo json_encode($response);
            exit;
        }
        
        $conn->begin_transaction();
        try {
            $sql = "UPDATE risk_mitigations SET 
                    title = ?, 
                    description = ?, 
                    owner_user_id = ?, 
                    due_date = ?, 
                    status = ?,
                    response_strategy = ?,
                    updated_at = NOW(),
                    updated_by = ?
                    WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ssisssii', $title, $description, $owner_user_id, $due_date, $status, $response_strategy, $current_user_id, $mitigation_id);
            $stmt->execute();
            $stmt->close();
            
            // Add to history
            $hist_sql = "INSERT INTO risk_history (risk_id, changed_by, change_type, comment, created_at) 
                        VALUES (?, ?, 'mitigation_updated', ?, NOW())";
            $hstmt = $conn->prepare($hist_sql);
            $comment = "Mitigation updated: " . $title;
            $hstmt->bind_param('iis', $risk_id, $current_user_id, $comment);
            $hstmt->execute();
            $hstmt->close();
            
            $conn->commit();
            $response['success'] = true;
            $response['message'] = 'Mitigation updated successfully';
        } catch (Exception $e) {
            $conn->rollback();
            $response['message'] = 'Error: ' . $e->getMessage();
        }
        
        echo json_encode($response);
        exit;
    }
    
    // =========================================
    // SRS SECTION 3.1.6 - ADD COMMENT - FIXED
    // =========================================
    if ($_POST['action'] === 'add_comment') {
        if (!$can_add_comment) {
            $response['message'] = 'Permission denied: You cannot add comments';
            echo json_encode($response);
            exit;
        }
        
        $comment_text = trim($_POST['comment_text'] ?? '');
        
        if (empty($comment_text)) {
            $response['message'] = 'Comment cannot be empty';
            echo json_encode($response);
            exit;
        }
        
        $conn->begin_transaction();
        try {
            $sql = "INSERT INTO risk_comments (risk_id, user_id, comment_text, created_at) VALUES (?, ?, ?, NOW())";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('iis', $risk_id, $current_user_id, $comment_text);
            $stmt->execute();
            $comment_id = $stmt->insert_id;
            $stmt->close();
            
            // Add to history
            $hist_sql = "INSERT INTO risk_history (risk_id, changed_by, change_type, comment, created_at) 
                        VALUES (?, ?, 'comment_added', ?, NOW())";
            $hstmt = $conn->prepare($hist_sql);
            $comment_log = "Comment added: " . substr($comment_text, 0, 50) . (strlen($comment_text) > 50 ? '...' : '');
            $hstmt->bind_param('iis', $risk_id, $current_user_id, $comment_log);
            $hstmt->execute();
            $hstmt->close();
            
            $conn->commit();
            
            $response['success'] = true;
            $response['message'] = 'Comment added successfully';
            $response['comment'] = [
                'id' => $comment_id,
                'comment_text' => nl2br(e($comment_text)),
                'user_name' => $username,
                'user_role' => $user_role,
                'created_at' => date('Y-m-d H:i:s'),
                'time_ago' => 'just now'
            ];
        } catch (Exception $e) {
            $conn->rollback();
            $response['message'] = 'Error: ' . $e->getMessage();
        }
        
        echo json_encode($response);
        exit;
    }
}

// Get status IDs for filters
$pending_review_status_id = get_status_id_by_key($conn, 'pending_review');
$open_status_id = get_status_id_by_key($conn, 'open');
$in_progress_status_id = get_status_id_by_key($conn, 'in_progress');
$closed_status_id = get_status_id_by_key($conn, 'closed');
$rejected_status_id = get_status_id_by_key($conn, 'rejected');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Risk Details: <?= e($risk['title']) ?> - Dashen Bank Risk Management</title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <!-- Animate.css -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    
    <style>
        :root {
            --dashen-primary: #273274;
            --dashen-secondary: #1e5af5;
            --dashen-accent: #f8a01c;
            --critical-color: #dc3545;
            --high-color: #fd7e14;
            --medium-color: #ffc107;
            --low-color: #198754;
            --sidebar-width: 280px;
            --gradient-primary: linear-gradient(135deg, #273274 0%, #1e5af5 100%);
            --gradient-success: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            --gradient-warning: linear-gradient(135deg, #ffc107 0%, #fd7e14 100%);
            --gradient-danger: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            --gradient-info: linear-gradient(135deg, #17a2b8 0%, #0dcaf0 100%);
        }
        
        body {
            font-family: 'Inter', 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(145deg, #f8faff 0%, #f0f5fe 100%);
            overflow-x: hidden;
        }
        
        .main-content {
            margin-left: var(--sidebar-width);
            transition: margin-left 0.3s ease;
            min-height: 100vh;
            position: relative;
        }
        
        @media (max-width: 991.98px) {
            .main-content {
                margin-left: 0;
            }
        }
        
        /* Premium Card Styles */
        .premium-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 24px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.03), 0 8px 16px rgba(39, 50, 116, 0.05);
            transition: all 0.4s cubic-bezier(0.165, 0.84, 0.44, 1);
            position: relative;
            overflow: hidden;
        }
        
        .premium-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--dashen-primary), var(--dashen-accent));
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        
        .premium-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 30px 60px rgba(39, 50, 116, 0.12);
            border-color: rgba(39, 50, 116, 0.1);
        }
        
        .premium-card:hover::before {
            opacity: 1;
        }
        
        /* Risk Score Circle */
        .risk-score-premium {
            width: 120px;
            height: 120px;
            border-radius: 30px;
            background: linear-gradient(145deg, var(--dashen-primary), #1a1f4a);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            color: white;
            box-shadow: 0 20px 40px rgba(39, 50, 116, 0.3);
            position: relative;
            overflow: hidden;
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
            font-size: 3rem;
            font-weight: 800;
            line-height: 1;
            margin-bottom: 0;
        }
        
        .risk-score-label {
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 3px;
            opacity: 0.9;
        }
        
        /* Status Badges */
        .status-badge-premium {
            padding: 8px 20px;
            border-radius: 50px;
            font-size: 0.85rem;
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
        
        /* Metric Cards */
        .metric-card {
            background: white;
            border-radius: 20px;
            padding: 20px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.02);
            border: 1px solid rgba(39, 50, 116, 0.05);
            transition: all 0.3s ease;
        }
        
        .metric-card:hover {
            border-color: var(--dashen-primary);
            box-shadow: 0 12px 28px rgba(39, 50, 116, 0.08);
        }
        
        .metric-icon {
            width: 48px;
            height: 48px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            background: linear-gradient(145deg, var(--dashen-primary), #1e275a);
            color: white;
            box-shadow: 0 8px 16px rgba(39, 50, 116, 0.15);
        }
        
        .metric-value {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--dashen-primary);
            line-height: 1;
        }
        
        .metric-label {
            font-size: 0.85rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        /* Timeline */
        .timeline {
            position: relative;
            padding-left: 30px;
        }
        
        .timeline::before {
            content: '';
            position: absolute;
            left: 8px;
            top: 8px;
            bottom: 8px;
            width: 2px;
            background: linear-gradient(to bottom, var(--dashen-primary), var(--dashen-accent));
            opacity: 0.3;
        }
        
        .timeline-item {
            position: relative;
            padding-bottom: 24px;
        }
        
        .timeline-marker {
            position: absolute;
            left: -30px;
            top: 4px;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            background: var(--dashen-primary);
            border: 3px solid white;
            box-shadow: 0 0 0 2px rgba(39, 50, 116, 0.2);
        }
        
        .timeline-content {
            background: white;
            border-radius: 16px;
            padding: 16px;
            border: 1px solid rgba(0,0,0,0.03);
            transition: all 0.3s ease;
        }
        
        .timeline-content:hover {
            transform: translateX(5px);
            border-color: var(--dashen-primary);
            box-shadow: 0 8px 20px rgba(39, 50, 116, 0.08);
        }
        
        /* Comment Styles */
        .comment-avatar-premium {
            width: 48px;
            height: 48px;
            border-radius: 16px;
            background: linear-gradient(145deg, var(--dashen-primary), #1e275a);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            font-weight: 600;
            box-shadow: 0 8px 16px rgba(39, 50, 116, 0.2);
        }
        
        .comment-bubble {
            background: #f8fafd;
            border-radius: 20px 20px 20px 4px;
            padding: 16px 20px;
            position: relative;
            border: 1px solid rgba(39, 50, 116, 0.05);
        }
        
        .comment-bubble::before {
            content: '';
            position: absolute;
            left: -10px;
            top: 16px;
            width: 20px;
            height: 20px;
            background: #f8fafd;
            border-left: 1px solid rgba(39, 50, 116, 0.05);
            border-bottom: 1px solid rgba(39, 50, 116, 0.05);
            transform: rotate(45deg);
        }
        
        /* Progress Bar */
        .progress-premium {
            height: 10px;
            border-radius: 10px;
            background: rgba(0,0,0,0.05);
            overflow: hidden;
        }
        
        .progress-bar-premium {
            background: linear-gradient(90deg, var(--dashen-primary), var(--dashen-accent));
            border-radius: 10px;
            position: relative;
            animation: shimmer 2s infinite;
        }
        
        @keyframes shimmer {
            0% { background-position: 0% 0%; }
            100% { background-position: 200% 0%; }
        }
        
        /* Quick Action Buttons */
        .quick-action-btn {
            padding: 12px 24px;
            border-radius: 12px;
            font-weight: 600;
            transition: all 0.3s ease;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .quick-action-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 24px rgba(0,0,0,0.15);
        }
        
        .btn-premium-primary {
            background: linear-gradient(145deg, var(--dashen-primary), #1e275a);
            color: white;
        }
        
        .btn-premium-success {
            background: linear-gradient(145deg, #28a745, #20c997);
            color: white;
        }
        
        .btn-premium-danger {
            background: linear-gradient(145deg, #dc3545, #c82333);
            color: white;
        }
        
        .btn-premium-warning {
            background: linear-gradient(145deg, #ffc107, #fd7e14);
            color: white;
        }
        
        .btn-premium-info {
            background: linear-gradient(145deg, #17a2b8, #0dcaf0);
            color: white;
        }
        
        /* Heat Map Grid */
        .heat-map {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 4px;
            padding: 16px;
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.02);
        }
        
        .heat-cell {
            aspect-ratio: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: 600;
            border-radius: 8px;
            background: #f8f9fa;
            transition: all 0.3s ease;
        }
        
        .heat-cell.active {
            background: linear-gradient(145deg, var(--dashen-primary), #1e275a);
            color: white;
            transform: scale(0.95);
            box-shadow: 0 4px 8px rgba(39, 50, 116, 0.3);
        }
        
        .heat-cell.low { background: #28a74520; }
        .heat-cell.medium { background: #ffc10720; }
        .heat-cell.high { background: #fd7e1420; }
        .heat-cell.critical { background: #dc354520; }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            .risk-score-premium {
                width: 100px;
                height: 100px;
            }
            
            .risk-score-number {
                font-size: 2.2rem;
            }
            
            .metric-value {
                font-size: 1.4rem;
            }
        }
    </style>
</head>
<body>
    <!-- Include Sidebar -->
    <?php include 'sidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Top Navigation Bar - Enhanced -->
        <nav class="navbar navbar-expand-lg bg-white shadow-sm px-4 py-3 sticky-top" style="backdrop-filter: blur(10px); background: rgba(255,255,255,0.95) !important;">
            <div class="container-fluid p-0">
                <div class="d-flex align-items-center">
                    <button class="btn btn-link d-lg-none me-3" type="button" onclick="toggleSidebar()">
                        <i class="bi bi-list fs-2" style="color: var(--dashen-primary);"></i>
                    </button>
                    <div>
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <span class="badge bg-dashen-primary px-3 py-2 rounded-pill">
                                <i class="bi bi-shield-shaded me-1"></i>Risk ID: #<?= $risk_id ?>
                            </span>
                            <span class="badge bg-light text-dark px-3 py-2 rounded-pill">
                                <i class="bi bi-folder me-1"></i><?= e($risk['project_name'] ?? 'No Project') ?>
                            </span>
                        </div>
                        <h4 class="mb-0 fw-bold" style="color: var(--dashen-primary);">
                            <?= e($risk['title']) ?>
                        </h4>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-3">
                    <div class="d-none d-md-flex align-items-center gap-2">
                        <div class="bg-light rounded-pill px-4 py-2">
                            <i class="bi bi-person-circle me-2 text-dashen-primary"></i>
                            <span class="fw-medium"><?= e($username) ?></span>
                            <span class="badge bg-dashen-primary bg-opacity-10 text-dashen-primary ms-2 px-3 py-1 rounded-pill">
                                <?= ucwords(str_replace('_', ' ', $user_role)) ?>
                            </span>
                        </div>
                    </div>
                    <a href="risks.php" class="btn btn-outline-dashen rounded-pill px-4">
                        <i class="bi bi-arrow-left me-2"></i>Back to Risks
                    </a>
                    
                    <?php if ($can_edit): ?>
                    <a href="risk_edit.php?id=<?= $risk_id ?>" class="btn btn-dashen-primary rounded-pill px-4">
                        <i class="bi bi-pencil-square me-2"></i>Edit Risk
                    </a>
                    <?php endif; ?>
                    
                    <?php if ($can_delete): ?>
                    <button class="btn btn-outline-danger rounded-pill px-4" onclick="deleteRisk(<?= $risk_id ?>, '<?= e(addslashes($risk['title'])) ?>')">
                        <i class="bi bi-trash3 me-2"></i>Delete
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </nav>

        <div class="container-fluid py-4 px-4">
            <!-- Status Messages -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show animate__animated animate__fadeInDown rounded-4 shadow-sm" role="alert">
                    <div class="d-flex align-items-center">
                        <i class="bi bi-check-circle-fill fs-4 me-3"></i>
                        <div>
                            <strong>Success!</strong> <?= e($_SESSION['success']) ?>
                        </div>
                        <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
                    </div>
                </div>
                <?php unset($_SESSION['success']); ?>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show animate__animated animate__fadeInDown rounded-4 shadow-sm" role="alert">
                    <div class="d-flex align-items-center">
                        <i class="bi bi-exclamation-triangle-fill fs-4 me-3"></i>
                        <div>
                            <strong>Error!</strong> <?= e($_SESSION['error']) ?>
                        </div>
                        <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
                    </div>
                </div>
                <?php unset($_SESSION['error']); ?>
            <?php endif; ?>

            <!-- Risk Header Section - Enhanced Premium Design -->
            <div class="row g-4 mb-4">
                <div class="col-lg-8">
                    <div class="premium-card p-4">
                        <div class="row g-4">
                            <div class="col-md-4 text-center">
                                <div class="risk-score-premium mx-auto">
                                    <span class="risk-score-number"><?= $score ?></span>
                                    <span class="risk-score-label">RISK SCORE</span>
                                </div>
                                <div class="mt-3">
                                    <span class="risk-level-badge badge-<?= $risk_class ?> px-4 py-3">
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
                                    </div>
                                    
                                    <div class="row g-3">
                                        <div class="col-sm-6">
                                            <div class="d-flex align-items-center gap-2">
                                                <i class="bi bi-tag-fill text-dashen-primary"></i>
                                                <span class="text-muted">Category:</span>
                                                <span class="fw-medium"><?= e($risk['category_name'] ?? 'Uncategorized') ?></span>
                                            </div>
                                            <?php if ($category_detail && $category_detail['description']): ?>
                                                <small class="text-muted d-block mt-1 ms-4"><?= e($category_detail['description']) ?></small>
                                            <?php endif; ?>
                                        </div>
                                        <div class="col-sm-6">
                                            <div class="d-flex align-items-center gap-2">
                                                <i class="bi bi-person-fill text-dashen-primary"></i>
                                                <span class="text-muted">Owner:</span>
                                                <?php if ($risk['owner_name']): ?>
                                                    <span class="fw-medium"><?= e($risk['owner_name']) ?></span>
                                                    <?php if ($risk['owner_email']): ?>
                                                        <a href="mailto:<?= e($risk['owner_email']) ?>" class="ms-1 text-decoration-none">
                                                            <i class="bi bi-envelope"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="text-muted fst-italic">Unassigned</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="col-sm-6">
                                            <div class="d-flex align-items-center gap-2">
                                                <i class="bi bi-person-badge-fill text-dashen-primary"></i>
                                                <span class="text-muted">Identified:</span>
                                                <span class="fw-medium"><?= e($risk['identified_by_name'] ?? $risk['creator_name'] ?? 'System') ?></span>
                                            </div>
                                        </div>
                                        <div class="col-sm-6">
                                            <div class="d-flex align-items-center gap-2">
                                                <i class="bi bi-calendar-fill text-dashen-primary"></i>
                                                <span class="text-muted">Created:</span>
                                                <span class="fw-medium"><?= date('M j, Y', strtotime($risk['created_at'])) ?></span>
                                                <span class="badge bg-light text-dark ms-2"><?= $days_since_creation ?> days ago</span>
                                            </div>
                                        </div>
                                        <?php if ($risk['approved_at']): ?>
                                        <div class="col-sm-6">
                                            <div class="d-flex align-items-center gap-2">
                                                <i class="bi bi-check-circle-fill text-success"></i>
                                                <span class="text-muted">Approved:</span>
                                                <span class="fw-medium"><?= date('M j, Y', strtotime($risk['approved_at'])) ?></span>
                                                <span class="badge bg-success bg-opacity-10 text-success ms-2">By: <?= e($risk['approved_by_name'] ?? 'System') ?></span>
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
                            <div class="metric-card">
                                <div class="d-flex align-items-center gap-3 mb-2">
                                    <div class="metric-icon">
                                        <i class="bi bi-shield-check"></i>
                                    </div>
                                    <div>
                                        <div class="metric-value"><?= $total_mitigations ?></div>
                                        <div class="metric-label">Total Actions</div>
                                    </div>
                                </div>
                                <div class="progress-premium mt-2">
                                    <div class="progress-bar-premium" style="width: <?= $mitigation_progress ?>%;"></div>
                                </div>
                                <div class="d-flex justify-content-between mt-2">
                                    <small class="text-muted"><?= $completed_mitigations ?> Completed</small>
                                    <small class="text-muted"><?= $mitigation_progress ?>%</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="metric-card">
                                <div class="d-flex align-items-center gap-3 mb-2">
                                    <div class="metric-icon" style="background: linear-gradient(145deg, #17a2b8, #0dcaf0);">
                                        <i class="bi bi-chat-dots"></i>
                                    </div>
                                    <div>
                                        <div class="metric-value"><?= count($comments) ?></div>
                                        <div class="metric-label">Comments</div>
                                    </div>
                                </div>
                                <div class="mt-3">
                                    <div class="d-flex align-items-center">
                                        <i class="bi bi-clock-history text-muted me-2"></i>
                                        <small class="text-muted">Last activity: <?= !empty($history) ? time_elapsed_string($history[0]['created_at']) : 'None' ?></small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php if ($risk['target_resolution_date']): ?>
                        <div class="col-12">
                            <div class="metric-card">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="metric-icon" style="background: linear-gradient(145deg, #6f42c1, #6610f2);">
                                        <i class="bi bi-calendar-check"></i>
                                    </div>
                                    <div>
                                        <div class="metric-value"><?= date('M j, Y', strtotime($risk['target_resolution_date'])) ?></div>
                                        <div class="metric-label">Target Resolution Date</div>
                                        <?php if ($days_until_target !== null): ?>
                                            <?php if ($days_until_target > 0): ?>
                                                <span class="badge bg-info mt-2"><?= $days_until_target ?> days remaining</span>
                                            <?php elseif ($days_until_target == 0): ?>
                                                <span class="badge bg-warning mt-2">Due today</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger mt-2"><?= abs($days_until_target) ?> days overdue</span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ========================================= -->
            <!-- SRS SECTION 3.1.2 - RISK REVIEW AND APPROVAL -->
            <!-- Only visible to pm_manager and super_admin when status is Pending Review -->
            <!-- ========================================= -->
            <?php if ($status_key == 'pending_review' && $can_approve_risk): ?>
            <div class="premium-card p-4 mb-4" style="background: linear-gradient(145deg, rgba(255,243,205,0.5), rgba(255,230,156,0.3)); border-left: 6px solid #ffc107;">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <div class="d-flex align-items-center gap-4">
                            <div class="bg-warning rounded-3 p-3" style="box-shadow: 0 10px 20px rgba(255,193,7,0.3);">
                                <i class="bi bi-hourglass-split text-white fs-1"></i>
                            </div>
                            <div>
                                <h4 class="fw-bold mb-2" style="color: #856404;">Risk Pending Review</h4>
                                <p class="text-muted mb-1">This risk requires your approval before it can be actively managed.</p>
                                <div class="d-flex align-items-center gap-3 mt-2">
                                    <span class="badge bg-light text-dark px-3 py-2">
                                        <i class="bi bi-person me-1"></i>Reported by: <?= e($risk['identified_by_name'] ?? $risk['creator_name'] ?? 'Unknown') ?>
                                    </span>
                                    <span class="badge bg-light text-dark px-3 py-2">
                                        <i class="bi bi-calendar me-1"></i><?= date('M j, Y', strtotime($risk['created_at'])) ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="d-flex gap-3 justify-content-md-end mt-4 mt-md-0">
                            <button class="btn btn-premium-success quick-action-btn" onclick="approveRisk(true)">
                                <i class="bi bi-check-circle-fill me-2"></i>Approve
                            </button>
                            <button class="btn btn-premium-danger quick-action-btn" onclick="approveRisk(false)">
                                <i class="bi bi-x-circle-fill me-2"></i>Reject
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Risk Assessment Matrix - Enhanced -->
            <div class="row g-4 mb-4">
                <div class="col-lg-7">
                    <div class="premium-card p-4 h-100">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h5 class="fw-bold mb-0" style="color: var(--dashen-primary);">
                                <i class="bi bi-grid-3x3-gap-fill me-2"></i>Risk Assessment Matrix
                            </h5>
                            <?php if ($can_assess_risk && $status_key != 'closed' && $status_key != 'rejected'): ?>
                            <button class="btn btn-outline-dashen rounded-pill px-4" onclick="showRiskAssessmentModal()">
                                <i class="bi bi-pencil-square me-2"></i>Update Assessment
                            </button>
                            <?php endif; ?>
                        </div>
                        
                        <div class="row g-4">
                            <div class="col-md-6">
                                <div class="bg-light rounded-4 p-4">
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <span class="fw-semibold">Likelihood</span>
                                        <span class="badge px-3 py-2" style="background: <?= get_likelihood_color((int)$risk['likelihood']) ?>; color: white;">
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
                                        <span class="badge px-3 py-2" style="background: <?= get_impact_color((int)$risk['impact']) ?>; color: white;">
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
                    <div class="premium-card p-4 h-100">
                        <h5 class="fw-bold mb-4" style="color: var(--dashen-primary);">
                            <i class="bi bi-shield-fill me-2"></i>Response Strategy
                        </h5>
                        
                        <?php if ($can_set_mitigation && $status_key != 'closed' && $status_key != 'rejected'): ?>
                        <div class="d-flex justify-content-end mb-3">
                            <button class="btn btn-outline-dashen rounded-pill px-4 btn-sm" onclick="showResponsePlanModal()">
                                <i class="bi bi-gear me-2"></i>Configure Strategy
                            </button>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($risk['response_strategy']): ?>
                            <div class="text-center py-3">
                                <div class="bg-success bg-opacity-10 rounded-4 p-4">
                                    <i class="bi bi-shield-check text-success display-4"></i>
                                    <h3 class="fw-bold mt-3" style="color: #28a745;"><?= e($risk['response_strategy']) ?></h3>
                                    <p class="text-muted mb-0">Current Response Strategy</p>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5">
                                <div class="bg-light rounded-4 p-5">
                                    <i class="bi bi-shield text-muted display-3"></i>
                                    <h6 class="text-muted mt-4 fw-bold">No Response Strategy Defined</h6>
                                    <p class="text-muted small mb-0">Set a strategy to manage this risk</p>
                                    <?php if ($can_set_mitigation && $status_key != 'closed' && $status_key != 'rejected'): ?>
                                    <button class="btn btn-dashen-primary rounded-pill px-5 mt-4" onclick="showResponsePlanModal()">
                                        <i class="bi bi-plus-circle me-2"></i>Add Strategy
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($can_assign_owner && $status_key != 'closed' && $status_key != 'rejected'): ?>
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
                                <button class="btn btn-sm btn-outline-dashen rounded-pill px-4" onclick="showAssignOwnerModal()">
                                    <i class="bi bi-person-plus me-2"></i>Change
                                </button>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ========================================= -->
            <!-- SRS SECTION 3.1.5 - RISK STATUS MANAGEMENT -->
            <!-- ========================================= -->
            <?php if ($can_update_status || $can_close_risk): ?>
            <div class="premium-card p-4 mb-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h5 class="fw-bold mb-0" style="color: var(--dashen-primary);">
                        <i class="bi bi-arrow-repeat me-2"></i>Status Management
                    </h5>
                    <?php if ($can_close_risk && $status_key != 'closed' && $status_key != 'rejected'): ?>
                    <button class="btn btn-premium-success quick-action-btn" onclick="closeRisk()">
                        <i class="bi bi-check2-circle me-2"></i>Close Risk
                    </button>
                    <?php endif; ?>
                </div>
                
                <div class="row">
                    <div class="col-lg-8">
                        <div class="d-flex flex-wrap align-items-center gap-3">
                            <span class="fw-semibold text-muted me-3">Quick Actions:</span>
                            
                            <?php foreach ($all_statuses as $status): 
                                if ($status['status_key'] == 'closed' && !$can_close_risk) continue;
                                if ($status['status_key'] == 'rejected' && !$can_approve_risk) continue;
                                if ($status['status_key'] == 'pending_review' && $user_role == 'pm_employee') continue;
                                if ($status['status_key'] == $status_key) continue;
                                
                                $btn_class = '';
                                if ($status['status_key'] == 'open') $btn_class = 'btn-outline-info';
                                elseif ($status['status_key'] == 'in_progress') $btn_class = 'btn-outline-primary';
                                elseif ($status['status_key'] == 'mitigated') $btn_class = 'btn-outline-success';
                                elseif ($status['status_key'] == 'closed') $btn_class = 'btn-outline-secondary';
                                elseif ($status['status_key'] == 'rejected') $btn_class = 'btn-outline-danger';
                                else $btn_class = 'btn-outline-dashen';
                            ?>
                                <button class="btn <?= $btn_class ?> rounded-pill px-4 py-2" 
                                        onclick="updateRiskStatus(<?= $status['id'] ?>, '<?= $status['status_key'] ?>')">
                                    <i class="bi <?php
                                        if ($status['status_key'] == 'open') echo 'bi-check-circle';
                                        elseif ($status['status_key'] == 'in_progress') echo 'bi-arrow-repeat';
                                        elseif ($status['status_key'] == 'mitigated') echo 'bi-shield-check';
                                        elseif ($status['status_key'] == 'closed') echo 'bi-check2-circle';
                                        elseif ($status['status_key'] == 'rejected') echo 'bi-x-circle';
                                    ?> me-2"></i>
                                    <?= e($status['label']) ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="mt-4 p-3 bg-light rounded-4">
                            <i class="bi bi-info-circle-fill text-dashen-primary me-2"></i>
                            <?php if ($user_role == 'pm_employee'): ?>
                                <span class="text-muted">You can only update status to <strong>In Progress</strong> for risks assigned to you.</span>
                            <?php elseif ($user_role == 'pm_manager'): ?>
                                <span class="text-muted">You can update any status. Only managers and admins can close risks.</span>
                            <?php elseif ($user_role == 'super_admin'): ?>
                                <span class="text-muted">You have full control over risk status.</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <div class="bg-gradient rounded-4 p-4 h-100 d-flex align-items-center justify-content-center" 
                             style="background: linear-gradient(145deg, var(--dashen-primary), #1e275a);">
                            <div class="text-center text-white">
                                <i class="bi bi-info-circle-fill display-6 mb-3"></i>
                                <h6 class="fw-bold mb-2">Current Status</h6>
                                <span class="badge bg-white text-dashen-primary px-4 py-3 rounded-pill fs-6">
                                    <?= e($risk['status_label'] ?? 'No Status') ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Risk Description and Trigger - Enhanced -->
            <div class="row g-4 mb-4">
                <div class="col-md-6">
                    <div class="premium-card p-4 h-100">
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
                    <div class="premium-card p-4 h-100">
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

            <!-- Mitigation Actions Section (SRS Section 3.1.4) - Enhanced -->
            <div class="premium-card p-4 mb-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h5 class="fw-bold mb-1" style="color: var(--dashen-primary);">
                            <i class="bi bi-shield-check me-2"></i>Mitigation Actions
                        </h5>
                        <p class="text-muted small mb-0"><?= $total_mitigations ?> total actions, <?= $completed_mitigations ?> completed</p>
                    </div>
                    <?php if ($can_add_mitigation): ?>
                    <button class="btn btn-premium-primary quick-action-btn" data-bs-toggle="collapse" data-bs-target="#addMitigationForm">
                        <i class="bi bi-plus-circle me-2"></i>Add Action
                    </button>
                    <?php endif; ?>
                </div>

                <!-- Add Mitigation Form -->
                <?php if ($can_add_mitigation): ?>
                <div class="collapse mb-4" id="addMitigationForm">
                    <div class="bg-light rounded-4 p-4">
                        <h6 class="fw-semibold text-dashen-primary mb-4">
                            <i class="bi bi-plus-circle me-2"></i>New Mitigation Action
                        </h6>
                        <form id="addMitigationFormElement">
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
                                            <option value="<?= $u['id'] ?>"><?= e($u['username']) ?> (<?= ucwords(str_replace('_', ' ', $u['system_role'] ?? '')) ?>)</option>
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
                                    <button type="submit" class="btn btn-premium-primary rounded-pill px-5 py-3">
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

                <!-- Mitigations List - Enhanced Card Grid -->
                <?php if (!empty($mitigations)): ?>
                    <div class="row g-4" id="mitigationsContainer">
                        <?php foreach ($mitigations as $index => $m): 
                            $is_overdue = $m['due_date'] && strtotime($m['due_date']) < time() && !in_array($m['status'], ['done', 'cancelled']);
                            $status_class = '';
                            $status_icon = '';
                            $status_text = ucfirst(str_replace('_', ' ', $m['status']));
                            
                            if ($m['status'] == 'open') { 
                                $status_class = 'status-open-premium'; 
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
                            
                            // Check if user can edit this mitigation
                            $can_edit_this_mitigation = false;
                            if ($user_role == 'super_admin') $can_edit_this_mitigation = true;
                            elseif ($user_role == 'pm_manager') $can_edit_this_mitigation = true;
                            elseif ($m['owner_user_id'] == $current_user_id || $m['created_by'] == $current_user_id) {
                                $can_edit_this_mitigation = true;
                            }
                        ?>
                            <div class="col-xl-4 col-lg-6" id="mitigation-<?= $m['id'] ?>">
                                <div class="premium-card p-4 h-100 <?= $is_overdue ? 'border-danger' : '' ?>" 
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
                                        <p class="text-muted fst-italic mb-3">No description provided</p>
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
                                    
                                    <div class="d-flex gap-2 mt-3">
                                        <?php if ($can_edit_this_mitigation): ?>
                                            <?php if ($m['status'] == 'open'): ?>
                                                <button class="btn btn-outline-success rounded-pill flex-grow-1 py-2" 
                                                        onclick="updateMitigationStatus(<?= $m['id'] ?>, 'in_progress')">
                                                    <i class="bi bi-play-circle me-2"></i>Start
                                                </button>
                                            <?php elseif ($m['status'] == 'in_progress'): ?>
                                                <button class="btn btn-outline-success rounded-pill flex-grow-1 py-2" 
                                                        onclick="updateMitigationStatus(<?= $m['id'] ?>, 'done')">
                                                    <i class="bi bi-check-circle me-2"></i>Complete
                                                </button>
                                            <?php elseif ($m['status'] == 'done'): ?>
                                                <button class="btn btn-outline-warning rounded-pill flex-grow-1 py-2" 
                                                        onclick="updateMitigationStatus(<?= $m['id'] ?>, 'in_progress')">
                                                    <i class="bi bi-arrow-counterclockwise me-2"></i>Reopen
                                                </button>
                                            <?php elseif ($m['status'] == 'cancelled'): ?>
                                                <button class="btn btn-outline-primary rounded-pill flex-grow-1 py-2" 
                                                        onclick="updateMitigationStatus(<?= $m['id'] ?>, 'open')">
                                                    <i class="bi bi-arrow-counterclockwise me-2"></i>Reopen
                                                </button>
                                            <?php endif; ?>
                                            
                                            <button class="btn btn-outline-dashen rounded-pill px-4 py-2" 
                                                    onclick="editMitigation(<?= htmlspecialchars(json_encode($m), ENT_QUOTES, 'UTF-8') ?>)">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <?php if ($m['response_strategy']): ?>
                                        <div class="mt-3 pt-3 border-top small">
                                            <span class="badge bg-light text-dark rounded-pill px-4 py-2">
                                                <i class="bi bi-shield me-1"></i>Strategy: <?= e($m['response_strategy']) ?>
                                            </span>
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
                        <?php if ($can_add_mitigation): ?>
                            <button class="btn btn-dashen-primary rounded-pill px-5 py-3" data-bs-toggle="collapse" data-bs-target="#addMitigationForm">
                                <i class="bi bi-plus-circle me-2"></i>Add First Action
                            </button>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Comments Section (SRS Section 3.1.6) - Enhanced -->
            <div class="premium-card p-4 mb-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h5 class="fw-bold mb-1" style="color: var(--dashen-primary);">
                            <i class="bi bi-chat-dots-fill me-2"></i>Comments & Discussion
                        </h5>
                        <p class="text-muted small mb-0"><?= count($comments) ?> total comments</p>
                    </div>
                </div>
                
                <!-- Add Comment Form -->
                <?php if ($can_add_comment): ?>
                <div class="mb-5">
                    <form id="addCommentForm">
                        <div class="d-flex gap-4">
                            <div class="comment-avatar-premium">
                                <?= strtoupper(substr($username, 0, 1)) ?>
                            </div>
                            <div class="flex-grow-1">
                                <div class="comment-bubble">
                                    <textarea id="commentText" class="form-control border-0 bg-transparent p-0" 
                                              rows="3" placeholder="Add a comment..."></textarea>
                                    <div class="d-flex justify-content-end mt-3">
                                        <button type="submit" class="btn btn-premium-primary rounded-pill px-5 py-2">
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
                            <div class="d-flex gap-4 mb-4 animate-card">
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

            <!-- Activity History - Enhanced Timeline -->
            <div class="premium-card p-4">
                <h5 class="fw-bold mb-4" style="color: var(--dashen-primary);">
                    <i class="bi bi-clock-history me-2"></i>Activity History
                    <span class="badge bg-dashen-primary bg-opacity-10 text-dashen-primary ms-2 rounded-pill px-3">
                        <?= count($history) ?> events
                    </span>
                </h5>
                
                <?php if (!empty($history)): ?>
                    <div class="timeline">
                        <?php foreach ($history as $index => $h): 
                            $time_ago = time_elapsed_string($h['created_at']);
                            $is_status_change = $h['change_type'] === 'status_changed';
                        ?>
                            <div class="timeline-item">
                                <div class="timeline-marker"></div>
                                <div class="timeline-content">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div class="flex-grow-1">
                                            <div class="d-flex align-items-center gap-2 mb-2">
                                                <span class="badge px-3 py-2 <?= $is_status_change ? 'bg-warning text-dark' : 'bg-dashen-primary' ?> rounded-pill">
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
        </div>
    </div>

    <!-- ========================================= -->
    <!-- MODALS FOR WORKFLOW ACTIONS -->
    <!-- ========================================= -->

    <!-- Risk Assessment Modal (SRS 3.1.3) -->
    <div class="modal fade" id="riskAssessmentModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content border-0 rounded-5 shadow-lg">
                <div class="modal-header bg-dashen-primary text-white border-0 rounded-top-5">
                    <h5 class="modal-title fw-bold"><i class="bi bi-calculator me-2"></i>Update Risk Assessment</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-5">
                        <label class="form-label fw-bold fs-6 mb-3">Likelihood (1-5)</label>
                        <div class="row g-3">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                            <div class="col">
                                <div class="form-check card-radio">
                                    <input class="form-check-input" type="radio" name="likelihood" id="like<?= $i ?>" value="<?= $i ?>" <?= $risk['likelihood'] == $i ? 'checked' : '' ?>>
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
                                <div class="form-check card-radio">
                                    <input class="form-check-input" type="radio" name="impact" id="impact<?= $i ?>" value="<?= $i ?>" <?= $risk['impact'] == $i ? 'checked' : '' ?>>
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
                                <span class="risk-score-number" id="previewScore" style="font-size: 2rem;"><?= $score ?></span>
                            </div>
                            <div>
                                <span class="badge px-4 py-3 fs-6" id="previewLevel" class="badge-<?= $risk_class ?>">
                                    <?= e($risk['risk_level'] ?? 'Medium') ?> Risk
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 p-4">
                    <button type="button" class="btn btn-light rounded-pill px-5 py-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-premium-primary rounded-pill px-5 py-3" onclick="submitRiskAssessment()">
                        <i class="bi bi-save me-2"></i>Save Assessment
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Response Plan Modal (SRS 3.1.4) -->
    <div class="modal fade" id="responsePlanModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content border-0 rounded-5 shadow-lg">
                <div class="modal-header bg-dashen-primary text-white border-0 rounded-top-5">
                    <h5 class="modal-title fw-bold"><i class="bi bi-shield me-2"></i>Set Response Strategy</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-4">
                        <label class="form-label fw-bold fs-6 mb-3">Response Strategy</label>
                        <select id="responseStrategy" class="form-select form-select-lg rounded-pill">
                            <option value="">-- Select Strategy --</option>
                            <option value="Avoid" <?= $risk['response_strategy'] == 'Avoid' ? 'selected' : '' ?>>Avoid</option>
                            <option value="Mitigate" <?= $risk['response_strategy'] == 'Mitigate' ? 'selected' : '' ?>>Mitigate</option>
                            <option value="Transfer" <?= $risk['response_strategy'] == 'Transfer' ? 'selected' : '' ?>>Transfer</option>
                            <option value="Accept" <?= $risk['response_strategy'] == 'Accept' ? 'selected' : '' ?>>Accept</option>
                        </select>
                        <small class="text-muted d-block mt-3">Choose how to respond to this risk</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold fs-6 mb-3">Target Resolution Date</label>
                        <input type="date" id="targetDate" class="form-control form-control-lg rounded-pill" 
                               value="<?= $risk['target_resolution_date'] ?? '' ?>" 
                               min="<?= date('Y-m-d') ?>">
                        <small class="text-muted d-block mt-3">Expected date when this risk will be resolved</small>
                    </div>
                </div>
                <div class="modal-footer border-0 p-4">
                    <button type="button" class="btn btn-light rounded-pill px-5 py-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-premium-primary rounded-pill px-5 py-3" onclick="submitResponsePlan()">
                        <i class="bi bi-save me-2"></i>Save Strategy
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Assign Owner Modal (SRS 3.1.4) -->
    <div class="modal fade" id="assignOwnerModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content border-0 rounded-5 shadow-lg">
                <div class="modal-header bg-dashen-primary text-white border-0 rounded-top-5">
                    <h5 class="modal-title fw-bold"><i class="bi bi-person-plus me-2"></i>Assign Risk Owner</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold fs-6 mb-3">Select Owner</label>
                        <select id="riskOwnerSelect" class="form-select form-select-lg rounded-pill">
                            <option value="">-- Unassigned --</option>
                            <?php foreach ($project_users as $u): ?>
                                <option value="<?= $u['id'] ?>" <?= $risk['owner_user_id'] == $u['id'] ? 'selected' : '' ?>>
                                    <?= e($u['username']) ?> (<?= ucwords(str_replace('_', ' ', $u['system_role'] ?? '')) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted d-block mt-3">Only users assigned to this project can be owners</small>
                    </div>
                </div>
                <div class="modal-footer border-0 p-4">
                    <button type="button" class="btn btn-light rounded-pill px-5 py-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-premium-primary rounded-pill px-5 py-3" onclick="submitAssignOwner()">
                        <i class="bi bi-check-circle me-2"></i>Assign Owner
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Mitigation Modal -->
    <div class="modal fade" id="editMitigationModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content border-0 rounded-5 shadow-lg">
                <div class="modal-header bg-dashen-primary text-white border-0 rounded-top-5">
                    <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square me-2"></i>Edit Mitigation Action</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <input type="hidden" id="editMitigationId">
                    <div class="row g-4">
                        <div class="col-12">
                            <label class="form-label fw-semibold">Title <span class="text-danger">*</span></label>
                            <input type="text" id="editMitigationTitle" class="form-control form-control-lg rounded-pill">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Owner</label>
                            <select id="editMitigationOwner" class="form-select form-select-lg rounded-pill">
                                <option value="">Unassigned</option>
                                <?php foreach ($project_users as $u): ?>
                                    <option value="<?= $u['id'] ?>"><?= e($u['username']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Due Date</label>
                            <input type="date" id="editMitigationDueDate" class="form-control form-control-lg rounded-pill">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Status</label>
                            <select id="editMitigationStatus" class="form-select form-select-lg rounded-pill">
                                <option value="open">Open</option>
                                <option value="in_progress">In Progress</option>
                                <option value="done">Done</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Response Strategy</label>
                            <select id="editMitigationStrategy" class="form-select form-select-lg rounded-pill">
                                <option value="Mitigate">Mitigate</option>
                                <option value="Avoid">Avoid</option>
                                <option value="Transfer">Transfer</option>
                                <option value="Accept">Accept</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Description</label>
                            <textarea id="editMitigationDescription" class="form-control" rows="5"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 p-4">
                    <button type="button" class="btn btn-light rounded-pill px-5 py-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-premium-primary rounded-pill px-5 py-3" onclick="submitMitigationEdit()">
                        <i class="bi bi-save me-2"></i>Save Changes
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    
    <script>
        // =============================================
        // TOOLTIP INITIALIZATION
        // =============================================
        document.addEventListener('DOMContentLoaded', function() {
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            tooltipTriggerList.map(function(tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
            
            // Initialize risk score preview in assessment modal
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
                document.getElementById('previewLevel').className = 'badge px-4 py-3 fs-6 ' + levelClass;
            }
            
            likeInputs.forEach(input => input.addEventListener('change', updateScorePreview));
            impactInputs.forEach(input => input.addEventListener('change', updateScorePreview));
            
            // Initialize with current values
            updateScorePreview();
        });

        // =============================================
        // SIDEBAR TOGGLE
        // =============================================
        function toggleSidebar() {
            const sidebar = document.querySelector('.sidebar');
            const mainContent = document.querySelector('.main-content');
            sidebar.classList.toggle('expanded');
            mainContent.classList.toggle('expanded');
        }

        // =============================================
        // SRS SECTION 3.1.2 - APPROVE/REJECT RISK - FIXED
        // =============================================
        function approveRisk(approve) {
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
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    const formData = new FormData();
                    formData.append('action', 'approve_risk');
                    formData.append('risk_id', <?= $risk_id ?>);
                    formData.append('approve', approve ? '1' : '0');
                    if (!approve) {
                        formData.append('rejection_reason', result.value);
                    }
                    
                    fetch(window.location.href, {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Success',
                                text: data.message,
                                timer: 1500,
                                showConfirmButton: false
                            }).then(() => {
                                location.reload();
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: data.message
                            });
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'An error occurred while processing your request.'
                        });
                    });
                }
            });
        }

        // =============================================
        // SRS SECTION 3.1.3 - SHOW RISK ASSESSMENT MODAL
        // =============================================
        function showRiskAssessmentModal() {
            const modal = new bootstrap.Modal(document.getElementById('riskAssessmentModal'));
            modal.show();
        }

        function submitRiskAssessment() {
            const likelihood = document.querySelector('input[name="likelihood"]:checked')?.value;
            const impact = document.querySelector('input[name="impact"]:checked')?.value;
            
            if (!likelihood || !impact) {
                Swal.fire({
                    icon: 'error',
                    title: 'Validation Error',
                    text: 'Please select both likelihood and impact'
                });
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'assess_risk');
            formData.append('risk_id', <?= $risk_id ?>);
            formData.append('likelihood', likelihood);
            formData.append('impact', impact);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const modal = bootstrap.Modal.getInstance(document.getElementById('riskAssessmentModal'));
                    modal.hide();
                    
                    Swal.fire({
                        icon: 'success',
                        title: 'Success',
                        text: data.message,
                        timer: 1500,
                        showConfirmButton: false
                    }).then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: data.message
                    });
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'An error occurred while processing your request.'
                });
            });
        }

        // =============================================
        // SRS SECTION 3.1.4 - SHOW RESPONSE PLAN MODAL
        // =============================================
        function showResponsePlanModal() {
            const modal = new bootstrap.Modal(document.getElementById('responsePlanModal'));
            modal.show();
        }

        function submitResponsePlan() {
            const strategy = document.getElementById('responseStrategy').value;
            const targetDate = document.getElementById('targetDate').value;
            
            const formData = new FormData();
            formData.append('action', 'update_response_plan');
            formData.append('risk_id', <?= $risk_id ?>);
            formData.append('response_strategy', strategy);
            formData.append('target_resolution_date', targetDate);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const modal = bootstrap.Modal.getInstance(document.getElementById('responsePlanModal'));
                    modal.hide();
                    
                    Swal.fire({
                        icon: 'success',
                        title: 'Success',
                        text: data.message,
                        timer: 1500,
                        showConfirmButton: false
                    }).then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: data.message
                    });
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'An error occurred while processing your request.'
                });
            });
        }

        // =============================================
        // SRS SECTION 3.1.4 - SHOW ASSIGN OWNER MODAL
        // =============================================
        function showAssignOwnerModal() {
            const modal = new bootstrap.Modal(document.getElementById('assignOwnerModal'));
            modal.show();
        }

        function submitAssignOwner() {
            const ownerId = document.getElementById('riskOwnerSelect').value;
            
            const formData = new FormData();
            formData.append('action', 'assign_owner');
            formData.append('risk_id', <?= $risk_id ?>);
            formData.append('owner_user_id', ownerId);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const modal = bootstrap.Modal.getInstance(document.getElementById('assignOwnerModal'));
                    modal.hide();
                    
                    Swal.fire({
                        icon: 'success',
                        title: 'Success',
                        text: data.message,
                        timer: 1500,
                        showConfirmButton: false
                    }).then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: data.message
                    });
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'An error occurred while processing your request.'
                });
            });
        }

        // =============================================
        // SRS SECTION 3.1.5 - UPDATE RISK STATUS - FIXED
        // =============================================
        function updateRiskStatus(statusId, statusKey) {
            // PM Employee permission check
            <?php if ($user_role == 'pm_employee'): ?>
            if (statusKey !== 'in_progress') {
                Swal.fire({
                    icon: 'error',
                    title: 'Permission Denied',
                    text: 'You can only change status to In Progress'
                });
                return;
            }
            <?php endif; ?>
            
            // Close risk permission check
            <?php if (!$can_close_risk): ?>
            if (statusKey === 'closed') {
                Swal.fire({
                    icon: 'error',
                    title: 'Permission Denied',
                    text: 'Only Project Managers and Super Admins can close risks'
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
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    const formData = new FormData();
                    formData.append('action', 'update_risk_status');
                    formData.append('risk_id', <?= $risk_id ?>);
                    formData.append('status_id', statusId);
                    
                    fetch(window.location.href, {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Success',
                                text: data.message,
                                timer: 1500,
                                showConfirmButton: false
                            }).then(() => {
                                location.reload();
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: data.message
                            });
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'An error occurred while processing your request.'
                        });
                    });
                }
            });
        }

        // =============================================
        // SRS SECTION 3.1.5 - CLOSE RISK - FIXED
        // =============================================
        function closeRisk() {
            <?php if (!$can_close_risk): ?>
            Swal.fire({
                icon: 'error',
                title: 'Permission Denied',
                text: 'Only Project Managers and Super Admins can close risks'
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
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    const formData = new FormData();
                    formData.append('action', 'close_risk');
                    formData.append('risk_id', <?= $risk_id ?>);
                    
                    fetch(window.location.href, {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Success',
                                text: data.message,
                                timer: 1500,
                                showConfirmButton: false
                            }).then(() => {
                                location.reload();
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: data.message
                            });
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'An error occurred while processing your request.'
                        });
                    });
                }
            });
        }

        // =============================================
        // ADD MITIGATION FORM SUBMIT - FIXED
        // =============================================
        document.getElementById('addMitigationFormElement')?.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const title = this.querySelector('input[name="mit_title"]').value.trim();
            if (!title) {
                Swal.fire({
                    icon: 'error',
                    title: 'Validation Error',
                    text: 'Mitigation title is required'
                });
                return;
            }
            
            const submitButton = this.querySelector('button[type="submit"]');
            const originalText = submitButton.innerHTML;
            submitButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Adding...';
            submitButton.disabled = true;
            
            const formData = new FormData(this);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const collapse = bootstrap.Collapse.getInstance(document.getElementById('addMitigationForm'));
                    collapse.hide();
                    
                    Swal.fire({
                        icon: 'success',
                        title: 'Success',
                        text: data.message,
                        timer: 1500,
                        showConfirmButton: false
                    }).then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: data.message
                    });
                }
                
                submitButton.innerHTML = originalText;
                submitButton.disabled = false;
            })
            .catch(error => {
                console.error('Error:', error);
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'An error occurred while processing your request.'
                });
                submitButton.innerHTML = originalText;
                submitButton.disabled = false;
            });
        });

        // =============================================
        // UPDATE MITIGATION STATUS - FIXED
        // =============================================
        function updateMitigationStatus(mitigationId, status) {
            Swal.fire({
                title: 'Update Mitigation Status',
                text: 'Are you sure you want to update this mitigation action?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#273274',
                confirmButtonText: 'Yes, update',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    const formData = new FormData();
                    formData.append('action', 'update_mitigation_status');
                    formData.append('mitigation_id', mitigationId);
                    formData.append('risk_id', <?= $risk_id ?>);
                    formData.append('status', status);
                    
                    fetch(window.location.href, {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Success',
                                text: data.message,
                                timer: 1500,
                                showConfirmButton: false
                            }).then(() => {
                                location.reload();
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: data.message
                            });
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'An error occurred while processing your request.'
                        });
                    });
                }
            });
        }

        // =============================================
        // EDIT MITIGATION - FIXED
        // =============================================
        function editMitigation(mitigation) {
            document.getElementById('editMitigationId').value = mitigation.id;
            document.getElementById('editMitigationTitle').value = mitigation.title || '';
            document.getElementById('editMitigationOwner').value = mitigation.owner_user_id || '';
            document.getElementById('editMitigationDueDate').value = mitigation.due_date || '';
            document.getElementById('editMitigationStatus').value = mitigation.status || 'open';
            document.getElementById('editMitigationStrategy').value = mitigation.response_strategy || 'Mitigate';
            document.getElementById('editMitigationDescription').value = mitigation.description || '';
            
            const modal = new bootstrap.Modal(document.getElementById('editMitigationModal'));
            modal.show();
        }

        function submitMitigationEdit() {
            const mitigationId = document.getElementById('editMitigationId').value;
            const title = document.getElementById('editMitigationTitle').value.trim();
            
            if (!title) {
                Swal.fire({
                    icon: 'error',
                    title: 'Validation Error',
                    text: 'Mitigation title is required'
                });
                return;
            }
            
            Swal.fire({
                title: 'Save Changes',
                text: 'Are you sure you want to update this mitigation action?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#273274',
                confirmButtonText: 'Yes, save',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    const formData = new FormData();
                    formData.append('action', 'update_mitigation');
                    formData.append('mitigation_id', mitigationId);
                    formData.append('risk_id', <?= $risk_id ?>);
                    formData.append('title', title);
                    formData.append('description', document.getElementById('editMitigationDescription').value);
                    formData.append('owner_user_id', document.getElementById('editMitigationOwner').value);
                    formData.append('due_date', document.getElementById('editMitigationDueDate').value);
                    formData.append('status', document.getElementById('editMitigationStatus').value);
                    formData.append('response_strategy', document.getElementById('editMitigationStrategy').value);
                    
                    fetch(window.location.href, {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            const modal = bootstrap.Modal.getInstance(document.getElementById('editMitigationModal'));
                            modal.hide();
                            
                            Swal.fire({
                                icon: 'success',
                                title: 'Success',
                                text: data.message,
                                timer: 1500,
                                showConfirmButton: false
                            }).then(() => {
                                location.reload();
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: data.message
                            });
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'An error occurred while processing your request.'
                        });
                    });
                }
            });
        }

        // =============================================
        // SRS SECTION 3.1.6 - ADD COMMENT - FIXED
        // =============================================
        document.getElementById('addCommentForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const commentText = document.getElementById('commentText').value.trim();
            if (!commentText) {
                Swal.fire({
                    icon: 'error',
                    title: 'Validation Error',
                    text: 'Comment cannot be empty'
                });
                return;
            }
            
            const submitButton = this.querySelector('button[type="submit"]');
            const originalText = submitButton.innerHTML;
            submitButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Posting...';
            submitButton.disabled = true;
            
            const formData = new FormData();
            formData.append('action', 'add_comment');
            formData.append('risk_id', <?= $risk_id ?>);
            formData.append('comment_text', commentText);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('commentText').value = '';
                    
                    // Add new comment to the top of the list
                    const commentsContainer = document.getElementById('commentsContainer');
                    const noComments = commentsContainer.querySelector('.text-center');
                    if (noComments) {
                        noComments.remove();
                    }
                    
                    const newComment = document.createElement('div');
                    newComment.className = 'd-flex gap-4 mb-4 animate-card';
                    newComment.innerHTML = `
                        <div class="comment-avatar-premium">
                            ${data.comment.user_name.charAt(0).toUpperCase()}
                        </div>
                        <div class="flex-grow-1">
                            <div class="comment-bubble">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <div>
                                        <span class="fw-bold" style="color: #273274;">${data.comment.user_name}</span>
                                        <span class="badge bg-light text-dark ms-2 rounded-pill px-3">
                                            ${data.comment.user_role.replace('_', ' ').toUpperCase()}
                                        </span>
                                    </div>
                                    <small class="text-muted">
                                        <i class="bi bi-clock me-1"></i>${data.comment.time_ago}
                                    </small>
                                </div>
                                <div style="white-space: pre-line;">${data.comment.comment_text}</div>
                            </div>
                        </div>
                    `;
                    
                    commentsContainer.insertBefore(newComment, commentsContainer.firstChild);
                    
                    // Update comment count
                    const commentBadge = document.querySelector('.section-title .badge');
                    if (commentBadge) {
                        const currentCount = parseInt(commentBadge.textContent) || 0;
                        commentBadge.textContent = currentCount + 1;
                    }
                    
                    Swal.fire({
                        icon: 'success',
                        title: 'Success',
                        text: data.message,
                        timer: 1500,
                        showConfirmButton: false
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: data.message
                    });
                }
                
                submitButton.innerHTML = originalText;
                submitButton.disabled = false;
            })
            .catch(error => {
                console.error('Error:', error);
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'An error occurred while processing your request.'
                });
                submitButton.innerHTML = originalText;
                submitButton.disabled = false;
            });
        });

        // =============================================
        // DELETE RISK (Super Admin Only)
        // =============================================
        function deleteRisk(riskId, riskTitle) {
            <?php if (!$can_delete): ?>
            Swal.fire({
                icon: 'error',
                title: 'Permission Denied',
                text: 'Only Super Admins can delete risks'
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
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    const formData = new FormData();
                    formData.append('action', 'delete_risk');
                    formData.append('risk_id', riskId);
                    
                    fetch('risks.php', {
                        method: 'POST',
                        body: formData
                    }).then(() => {
                        window.location.href = 'risks.php';
                    });
                }
            });
        }

        // =============================================
        // ANIMATIONS
        // =============================================
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.animate-card');
            cards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.03}s`;
            });
        });
    </script>
</body>
</html>
<?php
$conn->close();
?>