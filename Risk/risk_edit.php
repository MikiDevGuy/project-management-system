<?php
// risk_edit.php - Edit Risk Form with EXACT SRS Section 2.2 Role-Based Permissions
// Version 3.0 - Full SRS Implementation
// Last Updated: 2026-02-12

session_start();
require_once '../db.php';

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
// DATABASE INITIALIZATION - Ensure all tables/fields exist
// =============================================
function initialize_risk_edit_module($conn) {
    // Ensure risk_statuses has all required statuses (SRS 3.1.5)
    $required_statuses = [
        ['pending_review', 'Pending Review'],
        ['open', 'Open'],
        ['in_progress', 'In Progress'],
        ['mitigated', 'Mitigated'],
        ['closed', 'Closed'],
        ['rejected', 'Rejected']
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
}

initialize_risk_edit_module($conn);

// =============================================
// HELPER FUNCTIONS
// =============================================
function e($v) { 
    return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); 
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

function risk_level_from_score($score) {
    if ($score >= 13 && $score <= 25) return 'High';
    if ($score >= 6 && $score <= 12) return 'Medium';
    return 'Low';
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

// =============================================
// GET PROJECT USERS (Only assigned users - SRS Section 6.1 NB)
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

// =============================================
// GET ACCESSIBLE PROJECTS (SRS Section 2.2)
// =============================================
function get_accessible_projects($conn, $user_id, $user_role) {
    if ($user_role === 'super_admin') {
        $sql = "SELECT id, name FROM projects WHERE status != 'terminated' ORDER BY name";
        $result = $conn->query($sql);
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }
    
    $sql = "SELECT DISTINCT p.id, p.name 
            FROM projects p
            LEFT JOIN user_assignments ua ON p.id = ua.project_id AND ua.user_id = ? AND ua.is_active = 1
            LEFT JOIN project_users pu ON p.id = pu.project_id AND pu.user_id = ?
            WHERE p.status != 'terminated'
            AND (ua.user_id IS NOT NULL OR pu.user_id IS NOT NULL)
            ORDER BY p.name";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $user_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $projects = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $projects;
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

// Fetch risk details with all related information
$sql = "SELECT 
            r.*, 
            p.name AS project_name, 
            p.status AS project_status,
            d.department_name, 
            owner.id AS owner_id,
            owner.username AS owner_name,
            owner.email AS owner_email,
            creator.id AS creator_id,
            creator.username AS creator_name,
            rc.id AS category_id,
            rc.name AS category_name, 
            rs.id AS status_id,
            rs.label AS status_label,
            rs.status_key AS status_key
        FROM risks r
        LEFT JOIN projects p ON r.project_id = p.id
        LEFT JOIN departments d ON r.department_id = d.id
        LEFT JOIN users owner ON r.owner_user_id = owner.id
        LEFT JOIN users creator ON r.created_by = creator.id
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
    $_SESSION['error'] = 'This risk belongs to a terminated project and cannot be edited';
    header('Location: risks.php');
    exit;
}

// =============================================
// PROJECT ACCESS VERIFICATION
// =============================================
$project_access = has_project_access($conn, $current_user_id, $risk['project_id'], $user_role);

if (!$project_access) {
    $_SESSION['error'] = 'You do not have access to edit this risk';
    header('Location: risks.php');
    exit;
}

// =============================================
// EXACT SRS SECTION 2.2 - ROLE PERMISSIONS MATRIX
// =============================================

// =============================================
// SRS SECTION 2.2.1 - SUPER ADMIN PERMISSIONS
// =============================================
if ($user_role === 'super_admin') {
    $can_edit = true;
    $can_delete = true;
    $can_assess_risk = true;
    $can_assign_owner = true;
    $can_change_status = true;
    $can_change_project = true;
    $can_change_category = true;
    $can_change_department = true;
    $can_edit_title_description = true;
    $can_edit_trigger = true;
    $can_set_response_strategy = true;
    $can_set_target_date = true;
}

// =============================================
// SRS SECTION 2.2.2 - PROJECT MANAGER PERMISSIONS
// =============================================
elseif ($user_role === 'pm_manager') {
    $can_edit = true;
    $can_delete = false; // Only Super Admin can delete
    $can_assess_risk = true;
    $can_assign_owner = true;
    $can_change_status = true;
    $can_change_project = true;
    $can_change_category = true;
    $can_change_department = true;
    $can_edit_title_description = true;
    $can_edit_trigger = true;
    $can_set_response_strategy = true;
    $can_set_target_date = true;
}

// =============================================
// SRS SECTION 2.2.3 - PROJECT EMPLOYEE PERMISSIONS
// =============================================
elseif ($user_role === 'pm_employee') {
    $status_key = $risk['status_key'] ?? '';
    $created_by = (int)$risk['created_by'];
    
    // EDIT PERMISSION: Can edit risks they reported (BEFORE approval)
    if ($created_by === $current_user_id && $status_key === 'pending_review') {
        $can_edit = true;
        $can_delete = false;
        
        // PM Employee CANNOT:
        $can_assess_risk = false;        // Cannot set probability/impact
        $can_assign_owner = false;       // Cannot assign risk owner
        $can_change_status = false;      // Cannot change status (except to In Progress in view page)
        $can_change_project = false;     // Cannot change project
        $can_change_category = false;    // Cannot change category
        $can_change_department = false;  // Cannot change department
        $can_set_response_strategy = false;
        $can_set_target_date = false;
        
        // PM Employee CAN edit these basic fields ONLY:
        $can_edit_title_description = true;
        $can_edit_trigger = true;
    } else {
        // No edit permission
        $_SESSION['error'] = 'You do not have permission to edit this risk';
        header('Location: risk_view.php?id=' . $risk_id);
        exit;
    }
}

// =============================================
// DEFAULT PERMISSIONS FOR UNKNOWN ROLES
// =============================================
else {
    $_SESSION['error'] = 'Access denied';
    header('Location: risks.php');
    exit;
}

// =============================================
// LOAD DROPDOWN DATA WITH ROLE-BASED FILTERING
// =============================================
$dropdowns = [];

// Projects - Filtered by user access (SRS Section 2.2)
$dropdowns['projects'] = get_accessible_projects($conn, $current_user_id, $user_role);

// Departments - All active departments
$res = $conn->query("SELECT id, department_name FROM departments ORDER BY department_name");
$dropdowns['departments'] = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

// Users for assignment - Only users assigned to this project (SRS Section 6.1 NB)
$dropdowns['users'] = get_project_users($conn, $risk['project_id'], $user_role);

// Categories - All active categories
$res = $conn->query("SELECT id, name FROM risk_categories ORDER BY name");
$dropdowns['categories'] = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

// Statuses - Based on role permissions
if ($user_role === 'super_admin' || $user_role === 'pm_manager') {
    $res = $conn->query("SELECT id, status_key, label FROM risk_statuses WHERE is_active = 1 ORDER BY id");
    $dropdowns['statuses'] = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
} else {
    // PM Employee - Only see current status
    $stmt = $conn->prepare("SELECT id, status_key, label FROM risk_statuses WHERE id = ?");
    $stmt->bind_param('i', $risk['status_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $dropdowns['statuses'] = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
}

// Calculate risk score
$score = (int)$risk['likelihood'] * (int)$risk['impact'];
$risk_class = strtolower($risk['risk_level'] ?? 'low');

// Get status IDs
$pending_review_status_id = get_status_id_by_key($conn, 'pending_review');

// =============================================
// PAGE RENDERING
// =============================================
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Risk: <?= e($risk['title']) ?> - Dashen Bank Risk Management</title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    
    <!-- Select2 for enhanced dropdowns -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    
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
        
        /* Premium Card Design */
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
        
        .premium-card:hover::before {
            opacity: 1;
        }
        
        /* Form Sections */
        .form-section {
            background: white;
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
            border: 1px solid rgba(39, 50, 116, 0.05);
            transition: all 0.3s ease;
            position: relative;
        }
        
        .form-section:hover {
            border-color: rgba(39, 50, 116, 0.15);
            box-shadow: 0 8px 24px rgba(39, 50, 116, 0.05);
        }
        
        .section-title {
            color: var(--dashen-primary);
            font-weight: 700;
            font-size: 1.2rem;
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 3px solid var(--dashen-accent);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .section-title i {
            font-size: 1.4rem;
        }
        
        /* Form Controls */
        .form-label {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .form-control, .form-select {
            border-radius: 12px;
            border: 2px solid #e9ecef;
            padding: 0.75rem 1.25rem;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            background: white;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--dashen-primary);
            box-shadow: 0 0 0 0.2rem rgba(39, 50, 116, 0.1);
            transform: translateY(-1px);
        }
        
        .form-control-lg, .form-select-lg {
            padding: 1rem 1.5rem;
            font-size: 1rem;
        }
        
        .required-field::after {
            content: " *";
            color: #dc3545;
            font-weight: bold;
        }
        
        /* Risk Score Display */
        .risk-score-card {
            background: linear-gradient(145deg, #ffffff, #f8f9fa);
            border-radius: 16px;
            padding: 1.5rem;
            border: 2px solid transparent;
            transition: all 0.3s ease;
        }
        
        .risk-score-critical { border-color: var(--critical-color); background: linear-gradient(145deg, rgba(220,53,69,0.02), white); }
        .risk-score-high { border-color: var(--high-color); background: linear-gradient(145deg, rgba(253,126,20,0.02), white); }
        .risk-score-medium { border-color: var(--medium-color); background: linear-gradient(145deg, rgba(255,193,7,0.02), white); }
        .risk-score-low { border-color: var(--low-color); background: linear-gradient(145deg, rgba(25,135,84,0.02), white); }
        
        .risk-score-value {
            font-size: 2.5rem;
            font-weight: 800;
            line-height: 1;
            display: inline-block;
            margin-right: 0.5rem;
        }
        
        .risk-score-label {
            font-size: 1rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        /* Progress Bar */
        .progress-premium {
            height: 12px;
            border-radius: 12px;
            background: rgba(0,0,0,0.05);
            overflow: hidden;
        }
        
        .progress-bar-premium {
            background: linear-gradient(90deg, var(--dashen-primary), var(--dashen-accent));
            border-radius: 12px;
            position: relative;
            transition: width 0.6s ease;
        }
        
        /* Buttons */
        .btn-premium-primary {
            background: var(--gradient-primary);
            border: none;
            color: white;
            font-weight: 600;
            padding: 0.75rem 2rem;
            border-radius: 12px;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .btn-premium-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 24px rgba(39, 50, 116, 0.25);
            color: white;
        }
        
        .btn-premium-danger {
            background: var(--gradient-danger);
            border: none;
            color: white;
            font-weight: 600;
            padding: 0.75rem 2rem;
            border-radius: 12px;
            transition: all 0.3s ease;
        }
        
        .btn-premium-danger:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 24px rgba(220, 53, 69, 0.25);
            color: white;
        }
        
        .btn-outline-premium {
            border: 2px solid var(--dashen-primary);
            color: var(--dashen-primary);
            background: transparent;
            font-weight: 600;
            padding: 0.75rem 2rem;
            border-radius: 12px;
            transition: all 0.3s ease;
        }
        
        .btn-outline-premium:hover {
            background: var(--dashen-primary);
            color: white;
            transform: translateY(-3px);
        }
        
        /* Permission Badge */
        .permission-badge {
            background: rgba(39, 50, 116, 0.1);
            color: var(--dashen-primary);
            padding: 0.5rem 1.25rem;
            border-radius: 30px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        /* Disabled State */
        .form-control:disabled, .form-select:disabled {
            background: #f8f9fa;
            border-color: #dee2e6;
            color: #6c757d;
            cursor: not-allowed;
        }
        
        /* Animations */
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .animate-section {
            animation: slideIn 0.5s ease forwards;
        }
        
        .delay-1 { animation-delay: 0.1s; }
        .delay-2 { animation-delay: 0.2s; }
        .delay-3 { animation-delay: 0.3s; }
        .delay-4 { animation-delay: 0.4s; }
        
        /* Read-only Indicator */
        .readonly-indicator {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 1rem 1.5rem;
            border-radius: 12px;
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }
    </style>
</head>
<body>
    <!-- Include Sidebar -->
    <?php include 'sidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Top Navigation Bar -->
        <nav class="navbar navbar-expand-lg bg-white shadow-sm px-4 py-3 sticky-top" 
             style="backdrop-filter: blur(10px); background: rgba(255,255,255,0.95) !important;">
            <div class="container-fluid p-0">
                <div class="d-flex align-items-center">
                    <button class="btn btn-link d-lg-none me-3" type="button" onclick="toggleSidebar()">
                        <i class="bi bi-list fs-2" style="color: var(--dashen-primary);"></i>
                    </button>
                    <div>
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <span class="badge bg-dashen-primary px-3 py-2 rounded-pill">
                                <i class="bi bi-pencil-square me-1"></i>Editing Risk
                            </span>
                            <span class="permission-badge">
                                <i class="bi bi-person-badge me-1"></i>
                                <?= ucwords(str_replace('_', ' ', $user_role)) ?>
                            </span>
                        </div>
                        <h4 class="mb-0 fw-bold" style="color: var(--dashen-primary);">
                            <i class="bi bi-shield-shaded me-2"></i><?= e($risk['title']) ?>
                        </h4>
                        <span class="text-muted small">Risk ID: #<?= $risk_id ?> · Project: <?= e($risk['project_name'] ?? 'N/A') ?></span>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-3">
                    <a href="risk_view.php?id=<?= $risk_id ?>" class="btn btn-outline-premium">
                        <i class="bi bi-arrow-left me-2"></i>Cancel
                    </a>
                    <button type="submit" form="editRiskForm" class="btn btn-premium-primary">
                        <i class="bi bi-check-circle me-2"></i>Save Changes
                    </button>
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
            
            <!-- Read-only Warning for PM Employee -->
            <?php if ($user_role === 'pm_employee'): ?>
            <div class="readonly-indicator animate__animated animate__fadeInDown">
                <i class="bi bi-info-circle-fill fs-4" style="color: #ffc107;"></i>
                <div>
                    <strong class="text-dark">Limited Edit Mode</strong>
                    <p class="text-muted mb-0">You can only edit risks you reported while status is "Pending Review". You cannot change assessment scores, ownership, or project assignments.</p>
                </div>
            </div>
            <?php endif; ?>

            <!-- Main Edit Form -->
            <form method="post" action="risks.php" id="editRiskForm" class="premium-card p-4">
                <input type="hidden" name="action" value="update_risk">
                <input type="hidden" name="risk_id" value="<?= $risk_id ?>">
                
                <!-- ========================================= -->
                <!-- SECTION 1: BASIC INFORMATION -->
                <!-- ========================================= -->
                <div class="form-section animate-section delay-1">
                    <h5 class="section-title">
                        <i class="bi bi-info-circle"></i>Basic Information
                    </h5>
                    <div class="row g-4">
                        <!-- Risk Title - All roles can edit (with restrictions) -->
                        <div class="col-12">
                            <label class="form-label <?= $can_edit_title_description ? 'required-field' : '' ?>">
                                Risk Title
                            </label>
                            <input type="text" 
                                   name="title" 
                                   class="form-control form-control-lg" 
                                   value="<?= e($risk['title']) ?>" 
                                   <?= $can_edit_title_description ? 'required' : 'disabled' ?>
                                   placeholder="Enter a clear and concise risk title">
                            <?php if (!$can_edit_title_description): ?>
                                <small class="text-muted">You don't have permission to edit the title</small>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Project - Only Managers and Super Admin -->
                        <div class="col-md-6">
                            <label class="form-label">Project</label>
                            <select name="project_id" 
                                    class="form-select form-select-lg select2" 
                                    <?= $can_change_project ? '' : 'disabled' ?>>
                                <option value="">-- Select Project --</option>
                                <?php foreach ($dropdowns['projects'] as $p): ?>
                                    <option value="<?= (int)$p['id'] ?>" 
                                        <?= ((int)$risk['project_id'] === (int)$p['id']) ? 'selected' : '' ?>>
                                        <?= e($p['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (!$can_change_project): ?>
                                <small class="text-muted">Only managers can change project</small>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Department - Only Managers and Super Admin -->
                        <div class="col-md-6">
                            <label class="form-label">Department</label>
                            <select name="department_id" 
                                    class="form-select form-select-lg select2" 
                                    <?= $can_change_department ? '' : 'disabled' ?>>
                                <option value="">-- Select Department --</option>
                                <?php foreach ($dropdowns['departments'] as $d): ?>
                                    <option value="<?= (int)$d['id'] ?>" 
                                        <?= ((int)$risk['department_id'] === (int)$d['id']) ? 'selected' : '' ?>>
                                        <?= e($d['department_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (!$can_change_department): ?>
                                <small class="text-muted">Only managers can change department</small>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- ========================================= -->
                <!-- SECTION 2: RISK ASSESSMENT - SRS Section 3.1.3 -->
                <!-- Only PM Manager and Super Admin can edit -->
                <!-- ========================================= -->
                <div class="form-section animate-section delay-2">
                    <h5 class="section-title">
                        <i class="bi bi-clipboard-data"></i>Risk Assessment
                        <?php if (!$can_assess_risk): ?>
                            <span class="badge bg-warning text-dark ms-3">Read Only</span>
                        <?php endif; ?>
                    </h5>
                    <div class="row g-4">
                        <!-- Category - Only Managers and Super Admin -->
                        <div class="col-md-4">
                            <label class="form-label">Category</label>
                            <select name="category_id" 
                                    class="form-select form-select-lg select2" 
                                    <?= $can_change_category ? '' : 'disabled' ?>>
                                <option value="">-- Select Category --</option>
                                <?php foreach ($dropdowns['categories'] as $c): ?>
                                    <option value="<?= (int)$c['id'] ?>" 
                                        <?= ((int)$risk['category_id'] === (int)$c['id']) ? 'selected' : '' ?>>
                                        <?= e($c['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (!$can_change_category): ?>
                                <small class="text-muted">Only managers can change category</small>
                            <?php endif; ?>
                        </div>

                        <!-- Likelihood - Only Managers and Super Admin -->
                        <div class="col-md-4">
                            <label class="form-label <?= $can_assess_risk ? 'required-field' : '' ?>">
                                Likelihood (1-5)
                            </label>
                            <input type="number" 
                                   name="likelihood" 
                                   id="likelihoodInput"
                                   class="form-control form-control-lg" 
                                   min="1" max="5" 
                                   value="<?= (int)$risk['likelihood'] ?>" 
                                   <?= $can_assess_risk ? 'required' : 'disabled' ?>>
                            <small class="text-muted">1 = Very Unlikely, 5 = Almost Certain</small>
                            <?php if (!$can_assess_risk): ?>
                                <div class="text-muted small mt-1">
                                    <i class="bi bi-lock me-1"></i>Only managers can change risk scores
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Impact - Only Managers and Super Admin -->
                        <div class="col-md-4">
                            <label class="form-label <?= $can_assess_risk ? 'required-field' : '' ?>">
                                Impact (1-5)
                            </label>
                            <input type="number" 
                                   name="impact" 
                                   id="impactInput"
                                   class="form-control form-control-lg" 
                                   min="1" max="5" 
                                   value="<?= (int)$risk['impact'] ?>" 
                                   <?= $can_assess_risk ? 'required' : 'disabled' ?>>
                            <small class="text-muted">1 = Insignificant, 5 = Catastrophic</small>
                            <?php if (!$can_assess_risk): ?>
                                <div class="text-muted small mt-1">
                                    <i class="bi bi-lock me-1"></i>Only managers can change risk scores
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Risk Score Display - Dynamic -->
                        <div class="col-12 mt-3">
                            <div class="risk-score-card risk-score-<?= $risk_class ?>" id="riskScoreCard">
                                <div class="row align-items-center">
                                    <div class="col-md-6">
                                        <div class="d-flex align-items-center">
                                            <span class="risk-score-value" id="riskScore"><?= $score ?></span>
                                            <span class="risk-score-label" id="riskLevel"><?= e($risk['risk_level'] ?? 'Low') ?> RISK</span>
                                        </div>
                                        <div class="text-muted small mt-2">
                                            <i class="bi bi-calculator me-1"></i>
                                            Risk Score = Likelihood × Impact (<?= (int)$risk['likelihood'] ?> × <?= (int)$risk['impact'] ?> = <?= $score ?>)
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="d-flex align-items-center gap-3">
                                            <div class="flex-grow-1">
                                                <div class="progress-premium">
                                                    <div class="progress-bar-premium" id="riskProgress" 
                                                         style="width: <?= min(100, ($score / 25) * 100) ?>%;"></div>
                                                </div>
                                            </div>
                                            <span class="fw-bold"><?= $score ?>/25</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ========================================= -->
                <!-- SECTION 3: RISK DETAILS -->
                <!-- All roles can edit these fields (with restrictions) -->
                <!-- ========================================= -->
                <div class="form-section animate-section delay-3">
                    <h5 class="section-title">
                        <i class="bi bi-file-text"></i>Risk Details
                    </h5>
                    <div class="row g-4">
                        <!-- Trigger Description - All roles can edit -->
                        <div class="col-12">
                            <label class="form-label">Trigger / Cause</label>
                            <textarea name="trigger_description" 
                                      class="form-control" 
                                      rows="3" 
                                      <?= $can_edit_trigger ? '' : 'disabled' ?>
                                      placeholder="What event or condition would trigger this risk?"><?= e($risk['trigger_description']) ?></textarea>
                            <?php if (!$can_edit_trigger): ?>
                                <small class="text-muted">You don't have permission to edit this field</small>
                            <?php endif; ?>
                        </div>

                        <!-- Risk Description - All roles can edit -->
                        <div class="col-12">
                            <label class="form-label">Risk Description & Consequences</label>
                            <textarea name="description" 
                                      class="form-control" 
                                      rows="4" 
                                      <?= $can_edit_title_description ? '' : 'disabled' ?>
                                      placeholder="Describe the risk in detail, including potential consequences..."><?= e($risk['description']) ?></textarea>
                            <?php if (!$can_edit_title_description): ?>
                                <small class="text-muted">You don't have permission to edit this field</small>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- ========================================= -->
                <!-- SECTION 4: RESPONSE PLANNING - SRS Section 3.1.4 -->
                <!-- Only PM Manager and Super Admin can edit -->
                <!-- ========================================= -->
                <?php if ($can_set_response_strategy || $can_assign_owner || $can_set_target_date): ?>
                <div class="form-section animate-section delay-4">
                    <h5 class="section-title">
                        <i class="bi bi-shield-check"></i>Response Planning
                        <?php if (!$can_set_response_strategy): ?>
                            <span class="badge bg-warning text-dark ms-3">Read Only</span>
                        <?php endif; ?>
                    </h5>
                    <div class="row g-4">
                        <!-- Response Strategy - Only Managers and Super Admin -->
                        <?php if ($can_set_response_strategy): ?>
                        <div class="col-md-6">
                            <label class="form-label">Response Strategy</label>
                            <select name="response_strategy" class="form-select form-select-lg">
                                <option value="">-- Select Strategy --</option>
                                <option value="Avoid" <?= $risk['response_strategy'] == 'Avoid' ? 'selected' : '' ?>>Avoid</option>
                                <option value="Mitigate" <?= $risk['response_strategy'] == 'Mitigate' ? 'selected' : '' ?>>Mitigate</option>
                                <option value="Transfer" <?= $risk['response_strategy'] == 'Transfer' ? 'selected' : '' ?>>Transfer</option>
                                <option value="Accept" <?= $risk['response_strategy'] == 'Accept' ? 'selected' : '' ?>>Accept</option>
                            </select>
                            <small class="text-muted">Choose how to respond to this risk</small>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Target Resolution Date - Only Managers and Super Admin -->
                        <?php if ($can_set_target_date): ?>
                        <div class="col-md-6">
                            <label class="form-label">Target Resolution Date</label>
                            <input type="date" 
                                   name="target_resolution_date" 
                                   class="form-control form-control-lg" 
                                   value="<?= e($risk['target_resolution_date']) ?>" 
                                   min="<?= date('Y-m-d') ?>">
                            <small class="text-muted">Expected date when this risk will be resolved</small>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Risk Owner - Only Managers and Super Admin -->
                        <?php if ($can_assign_owner): ?>
                        <div class="col-12">
                            <label class="form-label">Risk Owner</label>
                            <select name="owner_user_id" class="form-select form-select-lg select2">
                                <option value="">-- Unassigned --</option>
                                <?php foreach ($dropdowns['users'] as $u): ?>
                                    <option value="<?= (int)$u['id'] ?>" 
                                        <?= ((int)$risk['owner_user_id'] === (int)$u['id']) ? 'selected' : '' ?>>
                                        <?= e($u['username']) ?> 
                                        (<?= ucwords(str_replace('_', ' ', $u['system_role'] ?? '')) ?>)
                                        <?php if ($u['email']): ?> - <?= e($u['email']) ?><?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Only users assigned to this project can be owners (SRS Section 6.1 NB)</small>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- ========================================= -->
                <!-- SECTION 5: STATUS - SRS Section 3.1.5 -->
                <!-- Only PM Manager and Super Admin can edit -->
                <!-- ========================================= -->
                <?php if ($can_change_status): ?>
                <div class="form-section animate-section delay-4">
                    <h5 class="section-title">
                        <i class="bi bi-arrow-repeat"></i>Status Management
                    </h5>
                    <div class="row">
                        <div class="col-md-6">
                            <label class="form-label">Risk Status</label>
                            <select name="status_id" class="form-select form-select-lg select2">
                                <option value="">-- No Status --</option>
                                <?php foreach ($dropdowns['statuses'] as $s): ?>
                                    <option value="<?= (int)$s['id'] ?>" 
                                        <?= ((int)$risk['status_id'] === (int)$s['id']) ? 'selected' : '' ?>>
                                        <?= e($s['label']) ?>
                                        <?php if ($s['status_key'] === 'pending_review'): ?>
                                            (Requires Approval)
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Update the current status of this risk</small>
                        </div>
                        <div class="col-md-6">
                            <div class="bg-light rounded-4 p-4 h-100 d-flex align-items-center">
                                <div class="d-flex align-items-center gap-3">
                                    <i class="bi bi-info-circle-fill fs-1 text-dashen-primary"></i>
                                    <div>
                                        <strong>Current Status:</strong>
                                        <span class="badge bg-dashen-primary ms-2 px-4 py-3">
                                            <?= e($risk['status_label'] ?? 'No Status') ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Hidden fields for disabled inputs -->
                <?php if (!$can_change_project): ?>
                    <input type="hidden" name="project_id" value="<?= $risk['project_id'] ?>">
                <?php endif; ?>
                <?php if (!$can_change_department): ?>
                    <input type="hidden" name="department_id" value="<?= $risk['department_id'] ?>">
                <?php endif; ?>
                <?php if (!$can_change_category): ?>
                    <input type="hidden" name="category_id" value="<?= $risk['category_id'] ?>">
                <?php endif; ?>
                <?php if (!$can_assess_risk): ?>
                    <input type="hidden" name="likelihood" value="<?= (int)$risk['likelihood'] ?>">
                    <input type="hidden" name="impact" value="<?= (int)$risk['impact'] ?>">
                <?php endif; ?>
                <?php if (!$can_assign_owner): ?>
                    <input type="hidden" name="owner_user_id" value="<?= $risk['owner_user_id'] ?>">
                <?php endif; ?>
                <?php if (!$can_set_response_strategy): ?>
                    <input type="hidden" name="response_strategy" value="<?= e($risk['response_strategy']) ?>">
                <?php endif; ?>
                <?php if (!$can_set_target_date): ?>
                    <input type="hidden" name="target_resolution_date" value="<?= e($risk['target_resolution_date']) ?>">
                <?php endif; ?>
                <?php if (!$can_change_status): ?>
                    <input type="hidden" name="status_id" value="<?= $risk['status_id'] ?>">
                <?php endif; ?>

                <!-- Form Actions -->
                <div class="d-flex justify-content-between align-items-center mt-5 pt-4 border-top">
                    <div>
                        <a href="risk_view.php?id=<?= $risk_id ?>" class="btn btn-outline-premium btn-lg">
                            <i class="bi bi-x-circle me-2"></i>Cancel
                        </a>
                    </div>
                    <div class="d-flex gap-3">
                        <?php if ($can_delete): ?>
                            <button type="button" class="btn btn-premium-danger btn-lg" 
                                    data-bs-toggle="modal" data-bs-target="#deleteRiskModal">
                                <i class="bi bi-trash3 me-2"></i>Delete Risk
                            </button>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-premium-primary btn-lg" id="saveButton">
                            <i class="bi bi-check-circle me-2"></i>Save Changes
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Risk Modal (Super Admin Only) -->
    <?php if ($can_delete): ?>
    <div class="modal fade" id="deleteRiskModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 rounded-5 shadow-lg">
                <div class="modal-header bg-danger text-white border-0 rounded-top-5">
                    <h5 class="modal-title fw-bold">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>Delete Risk
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4 text-center">
                    <i class="bi bi-shield-x text-danger" style="font-size: 4rem;"></i>
                    <h5 class="fw-bold mt-4 mb-3">Are you absolutely sure?</h5>
                    <p class="text-muted mb-2">You are about to delete the following risk:</p>
                    <div class="bg-light rounded-4 p-3 mb-4">
                        <strong class="fs-6">"<?= e($risk['title']) ?>"</strong>
                    </div>
                    <div class="alert alert-warning rounded-4">
                        <i class="bi bi-info-circle-fill me-2"></i>
                        <small>This action cannot be undone. All associated mitigations, comments, and history will be permanently deleted.</small>
                    </div>
                </div>
                <div class="modal-footer border-0 p-4">
                    <button type="button" class="btn btn-light rounded-pill px-5 py-3" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-2"></i>Cancel
                    </button>
                    <form method="post" action="risks.php">
                        <input type="hidden" name="action" value="delete_risk">
                        <input type="hidden" name="risk_id" value="<?= $risk_id ?>">
                        <button type="submit" class="btn btn-danger rounded-pill px-5 py-3">
                            <i class="bi bi-trash3 me-2"></i>Delete Permanently
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    
    <script>
        // =============================================
        // TOGGLE SIDEBAR
        // =============================================
        function toggleSidebar() {
            const sidebar = document.querySelector('.sidebar');
            const mainContent = document.querySelector('.main-content');
            sidebar.classList.toggle('expanded');
            mainContent.classList.toggle('expanded');
        }

        // =============================================
        // INITIALIZE SELECT2 DROPDOWNS
        // =============================================
        $(document).ready(function() {
            $('.select2').select2({
                theme: 'bootstrap-5',
                width: '100%',
                placeholder: 'Select an option',
                allowClear: true
            });
        });

        // =============================================
        // RISK SCORE CALCULATOR - SRS Section 3.1.3
        // =============================================
        function calculateRiskScore() {
            const likelihoodInput = document.getElementById('likelihoodInput');
            const impactInput = document.getElementById('impactInput');
            
            if (!likelihoodInput || !impactInput) return;
            
            const likelihood = parseInt(likelihoodInput.value) || 1;
            const impact = parseInt(impactInput.value) || 1;
            const score = likelihood * impact;
            
            let level = 'Low';
            let levelClass = 'risk-score-low';
            let progressColor = 'background: linear-gradient(90deg, #198754, #20c997);';
            
            if (score >= 13) {
                level = 'High';
                levelClass = 'risk-score-high';
                progressColor = 'background: linear-gradient(90deg, #fd7e14, #e55a00);';
            } else if (score >= 6) {
                level = 'Medium';
                levelClass = 'risk-score-medium';
                progressColor = 'background: linear-gradient(90deg, #ffc107, #e0a800);';
            }
            
            // Update display
            document.getElementById('riskScore').textContent = score;
            document.getElementById('riskLevel').textContent = level + ' RISK';
            
            // Update progress bar
            const progressBar = document.getElementById('riskProgress');
            const progressWidth = Math.min(100, (score / 25) * 100);
            progressBar.style.width = `${progressWidth}%`;
            progressBar.style.background = progressColor;
            
            // Update card class
            const scoreCard = document.getElementById('riskScoreCard');
            scoreCard.className = `risk-score-card ${levelClass}`;
        }

        // Initialize risk score calculation if user has permission
        <?php if ($can_assess_risk): ?>
        document.addEventListener('DOMContentLoaded', function() {
            calculateRiskScore();
            
            const likelihoodInput = document.getElementById('likelihoodInput');
            const impactInput = document.getElementById('impactInput');
            
            if (likelihoodInput) {
                likelihoodInput.addEventListener('input', calculateRiskScore);
            }
            if (impactInput) {
                impactInput.addEventListener('input', calculateRiskScore);
            }
        });
        <?php endif; ?>

        // =============================================
        // FORM VALIDATION
        // =============================================
        document.getElementById('editRiskForm')?.addEventListener('submit', function(e) {
            const title = document.querySelector('input[name="title"]')?.value.trim();
            
            <?php if ($can_edit_title_description): ?>
            if (!title) {
                e.preventDefault();
                Swal.fire({
                    icon: 'error',
                    title: 'Validation Error',
                    text: 'Please enter a risk title',
                    confirmButtonColor: '#273274'
                });
                return;
            }
            <?php endif; ?>
            
            <?php if ($can_assess_risk): ?>
            const likelihood = document.getElementById('likelihoodInput')?.value;
            const impact = document.getElementById('impactInput')?.value;
            
            if (!likelihood || !impact) {
                e.preventDefault();
                Swal.fire({
                    icon: 'error',
                    title: 'Validation Error',
                    text: 'Please enter both likelihood and impact values',
                    confirmButtonColor: '#273274'
                });
                return;
            }
            
            if (likelihood < 1 || likelihood > 5 || impact < 1 || impact > 5) {
                e.preventDefault();
                Swal.fire({
                    icon: 'error',
                    title: 'Validation Error',
                    text: 'Likelihood and impact must be between 1 and 5',
                    confirmButtonColor: '#273274'
                });
                return;
            }
            <?php endif; ?>
            
            // Show loading state
            const saveButton = document.getElementById('saveButton');
            const originalText = saveButton.innerHTML;
            saveButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving...';
            saveButton.disabled = true;
            
            return true;
        });

        // =============================================
        // ANIMATIONS
        // =============================================
        document.addEventListener('DOMContentLoaded', function() {
            const sections = document.querySelectorAll('.form-section');
            sections.forEach((section, index) => {
                section.style.opacity = '0';
                setTimeout(() => {
                    section.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                    section.style.opacity = '1';
                    section.style.transform = 'translateY(0)';
                }, index * 100);
            });
        });

        // =============================================
        // UNSAVED CHANGES WARNING
        // =============================================
        let formChanged = false;
        
        document.getElementById('editRiskForm')?.addEventListener('input', function() {
            formChanged = true;
        });
        
        window.addEventListener('beforeunload', function(e) {
            if (formChanged) {
                e.preventDefault();
                e.returnValue = 'You have unsaved changes. Are you sure you want to leave?';
                return e.returnValue;
            }
        });
        
        // Reset form changed flag on submit
        document.getElementById('editRiskForm')?.addEventListener('submit', function() {
            formChanged = false;
        });
    </script>
</body>
</html>
<?php
$conn->close();
?>