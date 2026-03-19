<?php
// risk_dashboard.php - Enhanced Risk Management Dashboard with Working Modals
// Version 4.0 - Complete Fix: All redirects fixed, modal popups working, role-based views
// Last Updated: 2026-02-12

session_start();
require_once "../db.php";

// =============================================
// ROLE-BASED ACCESS CONTROL (SRS Section 2.2)
// =============================================
$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
    header("Location: ../login.php");
    exit;
}

// Get current user role and details
$user_sql = "SELECT id, username, email, system_role, profile_picture FROM users WHERE id = ?";
$stmt = $conn->prepare($user_sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$current_user = $stmt->get_result()->fetch_assoc();
$stmt->close();

$user_role = $current_user['system_role'] ?? '';
$username = $current_user['username'] ?? 'User';
$profile_pic = $current_user['profile_picture'] ?? null;

// =============================================
// DATABASE INITIALIZATION - Ensure all tables exist
// =============================================
function initialize_dashboard_tables($conn) {
    // Ensure risk_statuses has all required statuses
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

initialize_dashboard_tables($conn);

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

function get_status_id_by_key($conn, $status_key) {
    $stmt = $conn->prepare("SELECT id FROM risk_statuses WHERE status_key = ? LIMIT 1");
    $stmt->bind_param('s', $status_key);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row ? (int)$row['id'] : null;
}

// =============================================
// =============================================
// DEFINE ROLE-BASED PERMISSIONS (SRS Section 2.2)
// =============================================
$can_create_risk = in_array($user_role, ['super_admin', 'pm_manager', 'pm_employee']);
$can_assess_risk = in_array($user_role, ['super_admin', 'pm_manager']);
$can_approve_risk = in_array($user_role, ['super_admin', 'pm_manager']);
$can_assign_risk = in_array($user_role, ['super_admin', 'pm_manager']);
$can_close_risk = in_array($user_role, ['super_admin', 'pm_manager']);
$can_manage_categories = in_array($user_role, ['super_admin']);
$can_view_all_projects = in_array($user_role, ['super_admin']);
$can_export_reports = in_array($user_role, ['super_admin', 'pm_manager']);
$can_view_all_risks = in_array($user_role, ['super_admin', 'pm_manager']);

// =============================================
// SRS 3.1.4 - MITIGATION PLANNING PERMISSIONS
// =============================================
$can_set_mitigation = in_array($user_role, ['super_admin', 'pm_manager']); // Can define mitigation plans
$can_add_mitigation = in_array($user_role, ['super_admin', 'pm_manager']); // Can add mitigation actions
$can_edit_mitigation = in_array($user_role, ['super_admin', 'pm_manager']); // Can edit mitigations
$can_delete_mitigation = in_array($user_role, ['super_admin']); // Only super admin can delete mitigations

// =============================================
// ADDITIONAL PERMISSIONS
// =============================================
$can_delete_risk = in_array($user_role, ['super_admin']); // Only super_admin can delete risks
$can_override_status = in_array($user_role, ['super_admin']); // Super admin can override any status

// =============================================
// FETCH PROJECTS BASED ON USER ROLE (SRS Section 6.1 NB)
// =============================================
function get_accessible_projects($conn, $user_id, $user_role) {
    if ($user_role === 'super_admin') {
        $sql = "SELECT id, name, description, status, start_date, end_date 
                FROM projects WHERE status != 'terminated' ORDER BY name";
        $result = $conn->query($sql);
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }
    
    $sql = "SELECT DISTINCT p.id, p.name, p.description, p.status, p.start_date, p.end_date 
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

$accessible_projects = get_accessible_projects($conn, $user_id, $user_role);
$project_ids = array_column($accessible_projects, 'id');

// =============================================
// BUILD PROJECT FILTER CONDITION
// =============================================
$project_condition = "";
$project_params = [];
$project_types = "";

if (!$can_view_all_projects && !empty($project_ids)) {
    $placeholders = implode(',', array_fill(0, count($project_ids), '?'));
    $project_condition = "AND r.project_id IN ($placeholders)";
    $project_params = $project_ids;
    $project_types = str_repeat('i', count($project_ids));
}

// =============================================
// FETCH ALL RISKS WITH DETAILS
// =============================================
$sql = "SELECT 
            r.*, 
            owner.id as owner_id,
            owner.username as owner_name,
            owner.email as owner_email,
            creator.id as creator_id,
            creator.username as creator_name,
            identifier.id as identified_by_id,
            identifier.username as identified_by_name,
            approver.id as approved_by_id,
            approver.username as approved_by_name,
            p.id as project_id,
            p.name AS project_name, 
            p.status AS project_status,
            rc.id as category_id,
            rc.name as category_name,
            rc.description as category_description,
            rs.id as status_id,
            rs.label as status_label,
            rs.status_key as status_key,
            (SELECT COUNT(*) FROM risk_mitigations rm WHERE rm.risk_id = r.id) as mitigation_count,
            (SELECT COUNT(*) FROM risk_mitigations rm WHERE rm.risk_id = r.id AND rm.status = 'done') as mitigation_completed_count,
            (SELECT COUNT(*) FROM risk_comments rc2 WHERE rc2.risk_id = r.id) as comment_count,
            (SELECT COUNT(*) FROM risk_history rh WHERE rh.risk_id = r.id) as history_count
        FROM risks r 
        LEFT JOIN users owner ON r.owner_user_id = owner.id
        LEFT JOIN users creator ON r.created_by = creator.id
        LEFT JOIN users identifier ON r.identified_by = identifier.id
        LEFT JOIN users approver ON r.approved_by = approver.id
        LEFT JOIN projects p ON r.project_id = p.id
        LEFT JOIN risk_categories rc ON rc.id = r.category_id
        LEFT JOIN risk_statuses rs ON rs.id = r.status_id
        WHERE 1=1 
        AND (p.status != 'terminated' OR p.status IS NULL)";

// Add project filtering
$sql .= " " . $project_condition;

// For PM Employee, also filter by assigned/created risks
if ($user_role === 'pm_employee' && !$can_view_all_risks) {
    $sql .= " AND (r.owner_user_id = ? OR r.created_by = ? OR r.identified_by = ?)";
    $project_params[] = $user_id;
    $project_params[] = $user_id;
    $project_params[] = $user_id;
    $project_types .= "iii";
}

$sql .= " ORDER BY 
            CASE 
                WHEN rs.status_key = 'pending_review' THEN 1
                WHEN r.risk_level = 'High' THEN 2
                WHEN r.risk_level = 'Medium' THEN 3
                ELSE 4
            END,
            r.risk_score DESC,
            r.created_at DESC";

$stmt = $conn->prepare($sql);
if (!empty($project_params)) {
    $stmt->bind_param($project_types, ...$project_params);
}
$stmt->execute();
$result = $stmt->get_result();

$risks = [];
$project_risk_map = [];
$category_risk_map = [];
$status_risk_map = [];
$owner_risk_map = [];

while ($row = $result->fetch_assoc()) {
    $risks[] = $row;
    $project_risk_map[$row['project_id']][] = $row;
    $category_risk_map[$row['category_name'] ?? 'Uncategorized'][] = $row;
    $status_risk_map[$row['status_label'] ?? 'Unknown'][] = $row;
    $owner_risk_map[$row['owner_id'] ?? 0][] = $row;
}
$stmt->close();

// =============================================
// CALCULATE COMPREHENSIVE RISK STATISTICS
// =============================================
$total_risks = count($risks);
$critical_risks = 0;
$high_risks = 0;
$medium_risks = 0;
$low_risks = 0;
$pending_review_risks = 0;
$open_risks = 0;
$in_progress_risks = 0;
$mitigated_risks = 0;
$closed_risks = 0;
$rejected_risks = 0;
$overdue_risks = 0;
$my_assigned_risks = 0;
$my_created_risks = 0;
$risks_without_owner = 0;
$risks_without_mitigation = 0;

$today = date('Y-m-d');

foreach ($risks as $risk) {
    $score = intval($risk['likelihood']) * intval($risk['impact']);
    if ($score >= 16) $critical_risks++;
    elseif ($score >= 10) $high_risks++;
    elseif ($score >= 5) $medium_risks++;
    else $low_risks++;
    
    // Count by status
    $status_key = $risk['status_key'] ?? '';
    if ($status_key == 'pending_review') $pending_review_risks++;
    elseif ($status_key == 'open') $open_risks++;
    elseif ($status_key == 'in_progress') $in_progress_risks++;
    elseif ($status_key == 'mitigated') $mitigated_risks++;
    elseif ($status_key == 'closed') $closed_risks++;
    elseif ($status_key == 'rejected') $rejected_risks++;
    
    // Risks without owner
    if (empty($risk['owner_id'])) {
        $risks_without_owner++;
    }
    
    // Risks without mitigation
    if ($risk['mitigation_count'] == 0 && $status_key != 'closed' && $status_key != 'rejected') {
        $risks_without_mitigation++;
    }
    
    // My assigned risks
    if ($risk['owner_id'] == $user_id && !in_array($status_key, ['closed', 'rejected'])) {
        $my_assigned_risks++;
        
        // Check if overdue (target date from risk_mitigations)
        $target_date_sql = "SELECT due_date FROM risk_mitigations WHERE risk_id = ? AND status != 'done' ORDER BY due_date ASC LIMIT 1";
        $stmt2 = $conn->prepare($target_date_sql);
        $stmt2->bind_param("i", $risk['id']);
        $stmt2->execute();
        $target_result = $stmt2->get_result();
        if ($target_row = $target_result->fetch_assoc()) {
            if ($target_row['due_date'] && $target_row['due_date'] < $today) {
                $overdue_risks++;
            }
        }
        $stmt2->close();
    }
    
    // My created risks
    if ($risk['created_by'] == $user_id) {
        $my_created_risks++;
    }
}

// =============================================
// FETCH RISK CATEGORIES FOR FILTER
// =============================================
$cat_sql = "SELECT * FROM risk_categories WHERE is_active = 1 ORDER BY name";
$cat_result = $conn->query($cat_sql);
$categories = [];
while ($cat = $cat_result->fetch_assoc()) {
    $categories[] = $cat;
}

// =============================================
// FETCH RECENT ACTIVITIES
// =============================================
$activity_sql = "SELECT 
                    h.*, 
                    u.username as user_name,
                    u.system_role as user_role,
                    r.title as risk_title,
                    r.id as risk_id
                FROM risk_history h
                LEFT JOIN users u ON h.changed_by = u.id
                LEFT JOIN risks r ON h.risk_id = r.id
                WHERE r.id IS NOT NULL
                ORDER BY h.created_at DESC
                LIMIT 20";
$activity_result = $conn->query($activity_sql);
$recent_activities = [];
while ($act = $activity_result->fetch_assoc()) {
    $recent_activities[] = $act;
}

// =============================================
// PREPARE CHART DATA
// =============================================
$heatmapData = [];
$categoryData = [];
$statusData = [];
$monthlyData = [];
$riskLevelDistribution = [
    'Critical' => $critical_risks,
    'High' => $high_risks,
    'Medium' => $medium_risks,
    'Low' => $low_risks
];

foreach ($risks as $risk) {
    $score = intval($risk['likelihood']) * intval($risk['impact']);
    $risk_level_text = '';
    if ($score >= 16) $risk_level_text = 'Critical';
    elseif ($score >= 10) $risk_level_text = 'High';
    elseif ($score >= 5) $risk_level_text = 'Medium';
    else $risk_level_text = 'Low';
    
    // Heatmap data
    if ($risk['likelihood'] && $risk['impact']) {
        $heatmapData[] = [
            "x" => intval($risk['likelihood']),
            "y" => intval($risk['impact']),
            "r" => 10,
            "label" => $risk['title'],
            "risk_level" => $risk_level_text,
            "id" => $risk['id']
        ];
    }

    // Category count
    $category = $risk['category_name'] ?? "Uncategorized";
    $categoryData[$category] = ($categoryData[$category] ?? 0) + 1;

    // Status count
    $status = $risk['status_label'] ?? "Unknown";
    $statusData[$status] = ($statusData[$status] ?? 0) + 1;

    // Monthly data
    $month = date('M Y', strtotime($risk['created_at']));
    $monthlyData[$month] = ($monthlyData[$month] ?? 0) + 1;
}

// Sort monthly data chronologically
ksort($monthlyData);

// Get status IDs for filtering
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
    <title>Risk Management Dashboard - Dashen Bank</title>
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    
    <!-- DataTables -->
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/responsive/2.4.1/css/responsive.bootstrap5.min.css" rel="stylesheet">
    
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
            --gradient-purple: linear-gradient(135deg, #6f42c1 0%, #6610f2 100%);
        }
        
        body {
            font-family: 'Inter', 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(145deg, #f8faff 0%, #f0f5fe 100%);
            overflow-x: hidden;
        }
        
        /* Main Content Layout */
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
            height: 100%;
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
        }
        
        .premium-card:hover::before {
            opacity: 1;
        }
        
        /* Stat Cards */
        .stat-card {
            position: relative;
            overflow: hidden;
            border-radius: 20px;
            padding: 1.5rem;
            background: white;
            box-shadow: 0 8px 24px rgba(0,0,0,0.02);
            transition: all 0.3s ease;
            border-left: 6px solid;
            height: 100%;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 16px 32px rgba(39, 50, 116, 0.1);
        }
        
        .stat-critical { border-left-color: var(--critical-color); }
        .stat-high { border-left-color: var(--high-color); }
        .stat-medium { border-left-color: var(--medium-color); }
        .stat-low { border-left-color: var(--low-color); }
        .stat-pending { border-left-color: #17a2b8; }
        .stat-assigned { border-left-color: #6f42c1; }
        .stat-overdue { border-left-color: #dc3545; }
        
        .stat-icon {
            width: 54px;
            height: 54px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            color: white;
        }
        
        .stat-number {
            font-size: 2.5rem;
            font-weight: 800;
            line-height: 1;
            margin-bottom: 0.25rem;
        }
        
        .stat-label {
            font-size: 0.85rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }
        
        /* Chart Containers */
        .chart-container {
            position: relative;
            height: 280px;
            width: 100%;
            margin: 0 auto;
        }
        
        /* Risk Badges */
        .risk-badge {
            padding: 0.5em 1.2em;
            border-radius: 30px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: inline-block;
        }
        
        .badge-critical { 
            background: linear-gradient(135deg, #dc3545, #c82333);
            color: white;
        }
        .badge-high { 
            background: linear-gradient(135deg, #fd7e14, #e96b02);
            color: white;
        }
        .badge-medium { 
            background: linear-gradient(135deg, #ffc107, #e0a800);
            color: #000;
        }
        .badge-low { 
            background: linear-gradient(135deg, #198754, #146c43);
            color: white;
        }
        
        /* Status Badges */
        .status-badge {
            padding: 0.5em 1.2em;
            border-radius: 30px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .status-pending { background: #fff3cd; color: #856404; }
        .status-open { background: #cce5ff; color: #004085; }
        .status-progress { background: #d1ecf1; color: #0c5460; }
        .status-mitigated { background: #d4edda; color: #155724; }
        .status-closed { background: #e2e3e5; color: #383d41; }
        .status-rejected { background: #f8d7da; color: #721c24; }
        
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
        
        .stat-clickable {
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .stat-clickable:hover {
            transform: translateY(-8px);
            box-shadow: 0 20px 40px rgba(39, 50, 116, 0.15);
        }
        
        /* Modal Styles */
        .modal-premium .modal-content {
            border-radius: 24px;
            border: none;
            box-shadow: 0 30px 60px rgba(0,0,0,0.2);
            overflow: hidden;
        }
        
        .modal-premium .modal-header {
            background: var(--gradient-primary);
            color: white;
            border: none;
            padding: 1.5rem;
        }
        
        .modal-premium .modal-body {
            padding: 2rem;
        }
        
        .modal-premium .modal-footer {
            border-top: 1px solid rgba(0,0,0,0.05);
            padding: 1.5rem;
        }
        
        .risk-detail-item {
            background: #f8fafd;
            border-radius: 16px;
            padding: 1.25rem;
            border-left: 4px solid var(--dashen-primary);
            transition: all 0.3s ease;
            margin-bottom: 1rem;
        }
        
        .risk-detail-item:hover {
            background: white;
            box-shadow: 0 8px 20px rgba(39, 50, 116, 0.08);
            transform: translateX(5px);
        }
        
        .role-badge {
            background: rgba(39, 50, 116, 0.1);
            color: var(--dashen-primary);
            padding: 0.5rem 1.25rem;
            border-radius: 30px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .welcome-banner {
            background: var(--gradient-primary);
            border-radius: 24px;
            padding: 2rem;
            color: white;
            position: relative;
            overflow: hidden;
        }
        
        .welcome-banner::after {
            content: '';
            position: absolute;
            top: -50%;
            right: -10%;
            width: 300px;
            height: 300px;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            border-radius: 50%;
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
        
        .animate-card {
            animation: slideIn 0.5s ease forwards;
        }
        
        .delay-1 { animation-delay: 0.1s; }
        .delay-2 { animation-delay: 0.2s; }
        .delay-3 { animation-delay: 0.3s; }
        .delay-4 { animation-delay: 0.4s; }
        .delay-5 { animation-delay: 0.5s; }
        
        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }
        
        ::-webkit-scrollbar-thumb {
            background: var(--dashen-primary);
            border-radius: 4px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: #1e275a;
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
                                <i class="bi bi-shield-shaded me-1"></i>Risk Management
                            </span>
                            <span class="role-badge">
                                <i class="bi bi-person-badge me-1"></i>
                                <?= ucwords(str_replace('_', ' ', $user_role)) ?>
                            </span>
                        </div>
                        <h4 class="mb-0 fw-bold" style="color: var(--dashen-primary);">
                            <i class="bi bi-speedometer2 me-2"></i>Dashboard
                        </h4>
                    </div>
                </div>
                
                <div class="d-flex align-items-center gap-3">
                    <!-- Quick Actions -->
                    <?php if ($can_create_risk): ?>
                    <a href="risk_edit.php" class="btn btn-premium-primary">
                        <i class="bi bi-plus-circle me-2"></i>New Risk
                    </a>
                    <?php endif; ?>
                    
                    <?php if ($can_export_reports): ?>
                    <div class="dropdown">
                        <button class="btn btn-outline-premium dropdown-toggle" type="button" data-bs-toggle="dropdown">
                            <i class="bi bi-download me-2"></i>Export
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm rounded-3">
                            <li><a class="dropdown-item" href="#" onclick="exportDashboardData('csv')"><i class="bi bi-file-earmark-spreadsheet me-2 text-success"></i>CSV Report</a></li>
                            <li><a class="dropdown-item" href="#" onclick="exportDashboardData('pdf')"><i class="bi bi-file-earmark-pdf me-2 text-danger"></i>PDF Report</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="#" onclick="exportDashboardData('excel')"><i class="bi bi-file-earmark-excel me-2 text-success"></i>Excel Full Report</a></li>
                        </ul>
                    </div>
                    <?php endif; ?>
                    
                    <!-- User Menu -->
                    <div class="dropdown">
                        <button class="btn btn-light d-flex align-items-center gap-2 rounded-pill shadow-sm" type="button" data-bs-toggle="dropdown">
                            <div class="bg-dashen-primary text-white rounded-circle d-flex align-items-center justify-content-center" 
                                 style="width: 40px; height: 40px;">
                                <span class="fw-bold fs-6"><?= strtoupper(substr($username, 0, 1)) ?></span>
                            </div>
                            <div class="text-start d-none d-md-block">
                                <span class="d-block small fw-bold"><?= e($username) ?></span>
                                <span class="d-block small text-muted"><?= ucwords(str_replace('_', ' ', $user_role)) ?></span>
                            </div>
                            <i class="bi bi-chevron-down ms-1"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm rounded-3 py-2">
                            <li><a class="dropdown-item py-2" href="../profile.php"><i class="bi bi-person me-2"></i>My Profile</a></li>
                            <li><a class="dropdown-item py-2" href="#"><i class="bi bi-gear me-2"></i>Settings</a></li>
                            <?php if ($can_manage_categories): ?>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item py-2" href="risk_categories.php"><i class="bi bi-tags me-2"></i>Manage Categories</a></li>
                            <?php endif; ?>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item py-2 text-danger" href="../logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                        </ul>
                    </div>
                </div>
            </div>
        </nav>

        <div class="container-fluid p-4">
            <!-- Welcome Banner -->
            <div class="welcome-banner mb-4 animate__animated animate__fadeInDown">
                <div class="row align-items-center">
                    <div class="col-lg-8">
                        <div class="d-flex align-items-center gap-4">
                            <div class="bg-white bg-opacity-20 rounded-4 p-4">
                                <i class="bi bi-shield-check display-4"></i>
                            </div>
                            <div>
                                <h3 class="fw-bold mb-2">Welcome back, <?= e($username) ?>!</h3>
                                <p class="mb-0 opacity-90">
                                    <?php if ($user_role == 'super_admin'): ?>
                                        You have full visibility across <strong><?= count($accessible_projects) ?> projects</strong> and <strong><?= $total_risks ?> risks</strong>.
                                    <?php elseif ($user_role == 'pm_manager'): ?>
                                        You are managing <strong><?= count($accessible_projects) ?> projects</strong> with <strong><?= $total_risks ?> risks</strong>.
                                    <?php else: ?>
                                        You have <strong><?= $my_assigned_risks ?> assigned risks</strong> and <strong><?= $my_created_risks ?> reported risks</strong>.
                                    <?php endif; ?>
                                </p>
                                <?php if ($pending_review_risks > 0 && $can_approve_risk): ?>
                                <div class="mt-3">
                                    <span class="badge bg-warning text-dark px-4 py-3 rounded-pill">
                                        <i class="bi bi-hourglass-split me-2"></i><?= $pending_review_risks ?> risk(s) pending your approval
                                    </span>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4 d-none d-lg-block text-end">
                        <div class="bg-white bg-opacity-10 rounded-4 p-4 d-inline-block">
                            <div class="small text-uppercase opacity-75">Today</div>
                            <div class="fs-3 fw-bold"><?= date('M j, Y') ?></div>
                            <div class="small opacity-75"><?= date('l') ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Statistics Cards Row - ALL CLICKABLE WITH MODALS -->
            <div class="row g-4 mb-4">
                <!-- Critical Risks Card -->
                <div class="col-xl-3 col-md-6">
                    <div class="stat-card stat-critical stat-clickable animate-card delay-1" 
                         onclick="showRiskModal('critical', 'Critical Risks', <?= $critical_risks ?>)">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <span class="stat-label">CRITICAL RISKS</span>
                                <div class="stat-number"><?= $critical_risks ?></div>
                                <span class="text-muted small">Score ≥ 16</span>
                            </div>
                            <div class="stat-icon" style="background: linear-gradient(135deg, #dc3545, #c82333);">
                                <i class="bi bi-exclamation-triangle-fill"></i>
                            </div>
                        </div>
                        <div class="mt-3 d-flex justify-content-between align-items-center">
                            <span class="badge bg-danger bg-opacity-10 text-danger px-3 py-2">
                                <i class="bi bi-arrow-up me-1"></i>Immediate Action
                            </span>
                            <small class="text-primary"><i class="bi bi-eye me-1"></i>Click to view</small>
                        </div>
                    </div>
                </div>
                
                <!-- High Risks Card -->
                <div class="col-xl-3 col-md-6">
                    <div class="stat-card stat-high stat-clickable animate-card delay-2" 
                         onclick="showRiskModal('high', 'High Risks', <?= $high_risks ?>)">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <span class="stat-label">HIGH RISKS</span>
                                <div class="stat-number"><?= $high_risks ?></div>
                                <span class="text-muted small">Score 10-15</span>
                            </div>
                            <div class="stat-icon" style="background: linear-gradient(135deg, #fd7e14, #e96b02);">
                                <i class="bi bi-exclamation-circle-fill"></i>
                            </div>
                        </div>
                        <div class="mt-3 d-flex justify-content-between align-items-center">
                            <span class="badge bg-warning bg-opacity-10 text-warning px-3 py-2">
                                <i class="bi bi-clock me-1"></i>Needs Attention
                            </span>
                            <small class="text-primary"><i class="bi bi-eye me-1"></i>Click to view</small>
                        </div>
                    </div>
                </div>
                
                <!-- Pending Review Card -->
                <div class="col-xl-3 col-md-6">
                    <div class="stat-card stat-pending stat-clickable animate-card delay-3" 
                         onclick="showRiskModal('pending_review', 'Pending Review', <?= $pending_review_risks ?>)">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <span class="stat-label">PENDING REVIEW</span>
                                <div class="stat-number"><?= $pending_review_risks ?></div>
                                <span class="text-muted small">Awaiting approval</span>
                            </div>
                            <div class="stat-icon" style="background: linear-gradient(135deg, #17a2b8, #0dcaf0);">
                                <i class="bi bi-hourglass-split"></i>
                            </div>
                        </div>
                        <div class="mt-3 d-flex justify-content-between align-items-center">
                            <?php if ($can_approve_risk && $pending_review_risks > 0): ?>
                                <span class="badge bg-info bg-opacity-10 text-info px-3 py-2">
                                    <i class="bi bi-check2-circle me-1"></i>Requires Approval
                                </span>
                            <?php else: ?>
                                <span class="badge bg-secondary bg-opacity-10 text-secondary px-3 py-2">
                                    <i class="bi bi-info-circle me-1"></i>In Queue
                                </span>
                            <?php endif; ?>
                            <small class="text-primary"><i class="bi bi-eye me-1"></i>Click to view</small>
                        </div>
                    </div>
                </div>
                
                <!-- My Assigned Risks Card -->
                <div class="col-xl-3 col-md-6">
                    <div class="stat-card stat-assigned stat-clickable animate-card delay-4" 
                         onclick="showRiskModal('my_assigned', 'My Assigned Risks', <?= $my_assigned_risks ?>)">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <span class="stat-label">MY ASSIGNED</span>
                                <div class="stat-number"><?= $my_assigned_risks ?></div>
                                <span class="text-muted small">Assigned to you</span>
                            </div>
                            <div class="stat-icon" style="background: linear-gradient(135deg, #6f42c1, #6610f2);">
                                <i class="bi bi-person-check-fill"></i>
                            </div>
                        </div>
                        <div class="mt-3 d-flex justify-content-between align-items-center">
                            <?php if ($overdue_risks > 0): ?>
                                <span class="badge bg-danger px-3 py-2">
                                    <i class="bi bi-exclamation-triangle me-1"></i><?= $overdue_risks ?> Overdue
                                </span>
                            <?php else: ?>
                                <span class="badge bg-success bg-opacity-10 text-success px-3 py-2">
                                    <i class="bi bi-check-circle me-1"></i>Up to Date
                                </span>
                            <?php endif; ?>
                            <small class="text-primary"><i class="bi bi-eye me-1"></i>Click to view</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Second Row Stats -->
            <div class="row g-4 mb-4">
                <div class="col-xl-3 col-md-6">
                    <div class="stat-card dashboard-card animate-card delay-1" 
                         onclick="showRiskModal('open', 'Open Risks', <?= $open_risks ?>)">
                        <div class="d-flex justify-content-between">
                            <div>
                                <span class="stat-label">OPEN RISKS</span>
                                <div class="stat-number"><?= $open_risks ?></div>
                            </div>
                            <div class="bg-primary bg-opacity-10 p-3 rounded-3">
                                <i class="bi bi-check-circle fs-2 text-primary"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="stat-card dashboard-card animate-card delay-2" 
                         onclick="showRiskModal('in_progress', 'In Progress', <?= $in_progress_risks ?>)">
                        <div class="d-flex justify-content-between">
                            <div>
                                <span class="stat-label">IN PROGRESS</span>
                                <div class="stat-number"><?= $in_progress_risks ?></div>
                            </div>
                            <div class="bg-info bg-opacity-10 p-3 rounded-3">
                                <i class="bi bi-arrow-repeat fs-2 text-info"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="stat-card dashboard-card animate-card delay-3" 
                         onclick="showRiskModal('mitigated', 'Mitigated', <?= $mitigated_risks ?>)">
                        <div class="d-flex justify-content-between">
                            <div>
                                <span class="stat-label">MITIGATED</span>
                                <div class="stat-number"><?= $mitigated_risks ?></div>
                            </div>
                            <div class="bg-success bg-opacity-10 p-3 rounded-3">
                                <i class="bi bi-shield-check fs-2 text-success"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="stat-card dashboard-card animate-card delay-4" 
                         onclick="showRiskModal('closed', 'Closed', <?= $closed_risks ?>)">
                        <div class="d-flex justify-content-between">
                            <div>
                                <span class="stat-label">CLOSED</span>
                                <div class="stat-number"><?= $closed_risks ?></div>
                            </div>
                            <div class="bg-secondary bg-opacity-10 p-3 rounded-3">
                                <i class="bi bi-check2-circle fs-2 text-secondary"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Charts Row -->
            <div class="row g-4 mb-4">
                <!-- Risk Heatmap -->
                <div class="col-xl-7">
                    <div class="premium-card p-4 animate-card delay-2">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h5 class="fw-bold mb-0" style="color: var(--dashen-primary);">
                                <i class="bi bi-grid-3x3-gap-fill me-2"></i>Risk Heatmap
                            </h5>
                            <span class="badge bg-dashen-primary bg-opacity-10 text-dashen-primary px-3 py-2">
                                Likelihood vs Impact
                            </span>
                        </div>
                        <div class="chart-container">
                            <canvas id="heatmapChart"></canvas>
                        </div>
                        <div class="mt-4 d-flex justify-content-center gap-4">
                            <div class="d-flex align-items-center">
                                <span class="d-inline-block w-3 h-3 rounded-circle bg-danger me-2"></span>
                                <span class="small">Critical (16-25)</span>
                            </div>
                            <div class="d-flex align-items-center">
                                <span class="d-inline-block w-3 h-3 rounded-circle bg-warning me-2"></span>
                                <span class="small">High (10-15)</span>
                            </div>
                            <div class="d-flex align-items-center">
                                <span class="d-inline-block w-3 h-3 rounded-circle bg-info me-2"></span>
                                <span class="small">Medium (5-9)</span>
                            </div>
                            <div class="d-flex align-items-center">
                                <span class="d-inline-block w-3 h-3 rounded-circle bg-success me-2"></span>
                                <span class="small">Low (1-4)</span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Risk by Category -->
                <div class="col-xl-5">
                    <div class="premium-card p-4 animate-card delay-3">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h5 class="fw-bold mb-0" style="color: var(--dashen-primary);">
                                <i class="bi bi-pie-chart-fill me-2"></i>Risk by Category
                            </h5>
                            <span class="badge bg-dashen-primary bg-opacity-10 text-dashen-primary px-3 py-2">
                                <?= count($categoryData) ?> Categories
                            </span>
                        </div>
                        <div class="chart-container">
                            <canvas id="categoryChart"></canvas>
                        </div>
                        <div class="mt-3 text-center">
                            <a href="#" class="text-decoration-none small" onclick="showAllCategoriesModal()">
                                View All Categories <i class="bi bi-arrow-right ms-1"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Second Row Charts -->
            <div class="row g-4 mb-4">
                <div class="col-md-6">
                    <div class="premium-card p-4 animate-card delay-3">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h5 class="fw-bold mb-0" style="color: var(--dashen-primary);">
                                <i class="bi bi-bar-chart-fill me-2"></i>Risk Level Distribution
                            </h5>
                            <span class="badge bg-dashen-primary bg-opacity-10 text-dashen-primary px-3 py-2">
                                <?= $total_risks ?> Total
                            </span>
                        </div>
                        <div class="chart-container">
                            <canvas id="riskLevelChart"></canvas>
                        </div>
                        <div class="mt-3 d-flex justify-content-center gap-3">
                            <div class="d-flex align-items-center">
                                <span class="d-inline-block w-3 h-3 rounded-circle bg-danger me-2"></span>
                                <span class="small">Critical (<?= $critical_risks ?>)</span>
                            </div>
                            <div class="d-flex align-items-center">
                                <span class="d-inline-block w-3 h-3 rounded-circle bg-warning me-2"></span>
                                <span class="small">High (<?= $high_risks ?>)</span>
                            </div>
                            <div class="d-flex align-items-center">
                                <span class="d-inline-block w-3 h-3 rounded-circle bg-info me-2"></span>
                                <span class="small">Medium (<?= $medium_risks ?>)</span>
                            </div>
                            <div class="d-flex align-items-center">
                                <span class="d-inline-block w-3 h-3 rounded-circle bg-success me-2"></span>
                                <span class="small">Low (<?= $low_risks ?>)</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="premium-card p-4 animate-card delay-4">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h5 class="fw-bold mb-0" style="color: var(--dashen-primary);">
                                <i class="bi bi-check2-circle me-2"></i>Risk Status Overview
                            </h5>
                            <span class="badge bg-dashen-primary bg-opacity-10 text-dashen-primary px-3 py-2">
                                <?= $total_risks ?> Total
                            </span>
                        </div>
                        <div class="chart-container">
                            <canvas id="statusChart"></canvas>
                        </div>
                        <div class="mt-3 text-center">
                            <a href="#" class="text-decoration-none small" onclick="showAllStatusesModal()">
                                View All Statuses <i class="bi bi-arrow-right ms-1"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Monthly Trend Chart -->
            <div class="row g-4 mb-4">
                <div class="col-12">
                    <div class="premium-card p-4 animate-card delay-4">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h5 class="fw-bold mb-0" style="color: var(--dashen-primary);">
                                <i class="bi bi-graph-up me-2"></i>Monthly Risk Trend
                            </h5>
                            <span class="badge bg-dashen-primary bg-opacity-10 text-dashen-primary px-3 py-2">
                                <?= array_sum($monthlyData) ?> Created
                            </span>
                        </div>
                        <div class="chart-container">
                            <canvas id="monthlyChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Activities & Quick Actions -->
            <div class="row g-4 mb-4">
                <div class="col-lg-7">
                    <div class="premium-card p-4 animate-card delay-5">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h5 class="fw-bold mb-0" style="color: var(--dashen-primary);">
                                <i class="bi bi-clock-history me-2"></i>Recent Activities
                            </h5>
                            <a href="risk_history.php" class="btn btn-sm btn-outline-premium rounded-pill px-4">
                                View All <i class="bi bi-arrow-right ms-1"></i>
                            </a>
                        </div>
                        <div style="max-height: 350px; overflow-y: auto;">
                            <?php if (!empty($recent_activities)): ?>
                                <?php foreach ($recent_activities as $index => $act): ?>
                                    <div class="d-flex align-items-start gap-3 mb-3 p-3 rounded-3" 
                                         style="background: <?= $index % 2 == 0 ? '#f8fafd' : 'white' ?>;">
                                        <div class="bg-dashen-primary bg-opacity-10 rounded-circle d-flex align-items-center justify-content-center" 
                                             style="width: 40px; height: 40px;">
                                            <i class="bi bi-<?= $act['change_type'] == 'created' ? 'plus-circle' : ($act['change_type'] == 'status_changed' ? 'arrow-repeat' : 'pencil') ?> text-dashen-primary"></i>
                                        </div>
                                        <div class="flex-grow-1">
                                            <div class="d-flex justify-content-between">
                                                <span class="fw-semibold"><?= e($act['user_name'] ?? 'System') ?></span>
                                                <small class="text-muted"><?= time_elapsed_string($act['created_at']) ?></small>
                                            </div>
                                            <p class="mb-0 small">
                                                <a href="risk_view.php?id=<?= $act['risk_id'] ?>" class="text-decoration-none fw-medium">
                                                    <?= e($act['risk_title'] ?? 'Risk #' . $act['risk_id']) ?>
                                                </a>
                                                <span class="text-muted"> — <?= e($act['comment']) ?></span>
                                            </p>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center py-5">
                                    <i class="bi bi-clock-history text-muted display-4"></i>
                                    <p class="text-muted mt-3 mb-0">No recent activities found</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="premium-card p-4 animate-card delay-5">
                        <h5 class="fw-bold mb-4" style="color: var(--dashen-primary);">
                            <i class="bi bi-lightning-charge-fill me-2"></i>Quick Actions
                        </h5>
                        
                        <div class="d-grid gap-3">
                            <?php if ($can_create_risk): ?>
                            <a href="risk_edit.php" class="btn btn-premium-primary py-3">
                                <i class="bi bi-plus-circle me-2"></i>Report New Risk
                            </a>
                            <?php endif; ?>
                            
                            <?php if ($can_approve_risk && $pending_review_risks > 0): ?>
                            <a href="#" onclick="showRiskModal('pending_review', 'Pending Review', <?= $pending_review_risks ?>)" class="btn btn-outline-warning py-3">
                                <i class="bi bi-hourglass-split me-2"></i>Review Pending Risks (<?= $pending_review_risks ?>)
                            </a>
                            <?php endif; ?>
                            
                            <?php if ($my_assigned_risks > 0): ?>
                            <a href="#" onclick="showRiskModal('my_assigned', 'My Assigned Risks', <?= $my_assigned_risks ?>)" class="btn btn-outline-info py-3">
                                <i class="bi bi-person-check me-2"></i>View My Assigned Risks (<?= $my_assigned_risks ?>)
                            </a>
                            <?php endif; ?>
                            
                            <?php if ($risks_without_owner > 0 && $can_assign_risk): ?>
                            <a href="#" onclick="showRiskModal('unassigned', 'Unassigned Risks', <?= $risks_without_owner ?>)" class="btn btn-outline-secondary py-3">
                                <i class="bi bi-person-dash me-2"></i>Assign Risk Owners (<?= $risks_without_owner ?>)
                            </a>
                            <?php endif; ?>
                            
                            <?php if ($risks_without_mitigation > 0 && $can_set_mitigation): ?>
                            <a href="#" onclick="showRiskModal('no_mitigation', 'Risks Without Mitigation', <?= $risks_without_mitigation ?>)" class="btn btn-outline-danger py-3">
                                <i class="bi bi-shield-x me-2"></i>Define Mitigations (<?= $risks_without_mitigation ?>)
                            </a>
                            <?php endif; ?>
                            
                            <a href="risks.php" class="btn btn-outline-premium py-3">
                                <i class="bi bi-list-check me-2"></i>View All Risks
                            </a>
                            
                            <?php if ($can_manage_categories): ?>
                            <a href="risk_categories.php" class="btn btn-outline-premium py-3">
                                <i class="bi bi-tags me-2"></i>Manage Categories
                            </a>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Summary Stats -->
                        <div class="mt-4 pt-3 border-top">
                            <div class="row g-2">
                                <div class="col-6">
                                    <div class="bg-light rounded-3 p-3 text-center">
                                        <span class="d-block fs-4 fw-bold text-dashen-primary"><?= $total_risks ?></span>
                                        <span class="small text-muted">Total Risks</span>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="bg-light rounded-3 p-3 text-center">
                                        <span class="d-block fs-4 fw-bold text-success"><?= $closed_risks ?></span>
                                        <span class="small text-muted">Closed</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Risks Table -->
            <div class="premium-card p-4 animate-card delay-5">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h5 class="fw-bold mb-0" style="color: var(--dashen-primary);">
                        <i class="bi bi-table me-2"></i>Recent Risks
                    </h5>
                    <div>
                        <a href="risks.php" class="btn btn-sm btn-outline-premium rounded-pill px-4">
                            View Full Register <i class="bi bi-arrow-right ms-1"></i>
                        </a>
                    </div>
                </div>
                <div class="table-responsive">
                    <table id="recentRisksTable" class="table table-hover align-middle">
                        <thead class="bg-light">
                            <tr>
                                <th>ID</th>
                                <th>Project</th>
                                <th>Risk Title</th>
                                <th>Category</th>
                                <th>Level</th>
                                <th>Status</th>
                                <th>Owner</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $recent_risks = array_slice($risks, 0, 10);
                            foreach ($recent_risks as $r): 
                                $score = intval($r['likelihood']) * intval($r['impact']);
                                $risk_level_text = '';
                                $badge_class = '';
                                if ($score >= 16) {
                                    $risk_level_text = 'Critical';
                                    $badge_class = 'badge-critical';
                                } elseif ($score >= 10) {
                                    $risk_level_text = 'High';
                                    $badge_class = 'badge-high';
                                } elseif ($score >= 5) {
                                    $risk_level_text = 'Medium';
                                    $badge_class = 'badge-medium';
                                } else {
                                    $risk_level_text = 'Low';
                                    $badge_class = 'badge-low';
                                }
                                
                                $status_class = '';
                                $status_key = $r['status_key'] ?? '';
                                if ($status_key == 'pending_review') $status_class = 'status-pending';
                                elseif ($status_key == 'open') $status_class = 'status-open';
                                elseif ($status_key == 'in_progress') $status_class = 'status-progress';
                                elseif ($status_key == 'mitigated') $status_class = 'status-mitigated';
                                elseif ($status_key == 'closed') $status_class = 'status-closed';
                                elseif ($status_key == 'rejected') $status_class = 'status-rejected';
                            ?>
                                <tr>
                                    <td>
                                        <span class="fw-bold text-dashen-primary">#<?= $r['id'] ?></span>
                                    </td>
                                    <td>
                                        <span class="fw-medium"><?= e($r['project_name'] ?? 'N/A') ?></span>
                                    </td>
                                    <td>
                                        <a href="risk_view.php?id=<?= $r['id'] ?>" class="text-decoration-none fw-medium">
                                            <?= e($r['title']) ?>
                                        </a>
                                        <?php if ($r['comment_count'] > 0): ?>
                                            <span class="badge bg-light text-dark ms-2">
                                                <i class="bi bi-chat"></i> <?= $r['comment_count'] ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark px-3 py-2">
                                            <?= e($r['category_name'] ?? 'Uncategorized') ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="risk-badge <?= $badge_class ?> px-3 py-2">
                                            <?= $risk_level_text ?>
                                        </span>
                                        <small class="d-block text-muted mt-1">
                                            <?= $r['likelihood'] ?>x<?= $r['impact'] ?> = <?= $score ?>
                                        </small>
                                    </td>
                                    <td>
                                        <span class="status-badge <?= $status_class ?> px-3 py-2">
                                            <?= e($r['status_label'] ?? 'Unknown') ?>
                                        </span>
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
                                                <span><?= e($r['owner_name']) ?></span>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted fst-italic">Unassigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="small fw-medium"><?= date('M d, Y', strtotime($r['created_at'])) ?></span>
                                        <span class="d-block text-muted small"><?= time_elapsed_string($r['created_at']) ?></span>
                                    </td>
                                    <td>
                                        <div class="btn-group">
                                            <a href="risk_view.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-dashen" title="View Details">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <?php if ($user_role == 'super_admin' || $user_role == 'pm_manager' || $r['created_by'] == $user_id): ?>
                                            <a href="risk_edit.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                            <?php endif; ?>
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

    <!-- ========================================= -->
    <!-- RISK DETAILS MODAL - FIXED WORKING VERSION -->
    <!-- ========================================= -->
    <div class="modal fade modal-premium" id="riskDetailsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="riskModalTitle">
                        <i class="bi bi-shield-shaded me-2"></i>
                        <span id="modalTitleText">Risk Details</span>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="riskModalBody">
                    <!-- Content will be loaded dynamically via JavaScript -->
                    <div class="text-center py-5">
                        <div class="spinner-border text-dashen-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="mt-3 text-muted">Loading risks...</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-2"></i>Close
                    </button>
                    <a href="risks.php" id="viewAllRisksBtn" class="btn btn-premium-primary rounded-pill px-4">
                        <i class="bi bi-list-check me-2"></i>View All Risks
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- ========================================= -->
    <!-- CATEGORY DETAILS MODAL -->
    <!-- ========================================= -->
    <div class="modal fade modal-premium" id="categoryDetailsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #6f42c1, #6610f2);">
                    <h5 class="modal-title fw-bold text-white">
                        <i class="bi bi-tags-fill me-2"></i>
                        <span id="categoryModalTitle">Risk Categories</span>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="categoryModalBody">
                    <!-- Content will be loaded dynamically -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ========================================= -->
    <!-- STATUS DETAILS MODAL -->
    <!-- ========================================= -->
    <div class="modal fade modal-premium" id="statusDetailsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #17a2b8, #0dcaf0);">
                    <h5 class="modal-title fw-bold text-white">
                        <i class="bi bi-check2-circle me-2"></i>
                        <span id="statusModalTitle">Risk Statuses</span>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="statusModalBody">
                    <!-- Content will be loaded dynamically -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.4.1/js/dataTables.responsive.min.js"></script>
    
    <script>
        // =============================================
        // GLOBAL VARIABLES
        // =============================================
        const risksData = <?= json_encode($risks) ?>;
        const categoriesData = <?= json_encode($categoryData) ?>;
        const statusData = <?= json_encode($statusData) ?>;
        const currentUserId = <?= $user_id ?>;
        const userRole = '<?= $user_role ?>';
        
        // =============================================
        // INITIALIZE DATATABLES
        // =============================================
        $(document).ready(function() {
            $('#recentRisksTable').DataTable({
                responsive: true,
                pageLength: 10,
                order: [[7, 'desc']],
                language: {
                    search: "_INPUT_",
                    searchPlaceholder: "Search risks...",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ risks",
                    infoEmpty: "Showing 0 to 0 of 0 risks",
                    infoFiltered: "(filtered from _MAX_ total risks)"
                },
                columnDefs: [
                    { orderable: false, targets: [8] }
                ]
            });
        });

        // =============================================
        // HEATMAP CHART - FIXED
        // =============================================
        const heatmapCtx = document.getElementById('heatmapChart')?.getContext('2d');
        
        function getRiskColor(riskLevel) {
            switch(riskLevel) {
                case 'Critical': return 'rgba(220, 53, 69, 0.8)';
                case 'High': return 'rgba(253, 126, 20, 0.8)';
                case 'Medium': return 'rgba(255, 193, 7, 0.8)';
                case 'Low': return 'rgba(25, 135, 84, 0.8)';
                default: return 'rgba(108, 117, 125, 0.8)';
            }
        }

        const heatmapData = <?= json_encode($heatmapData) ?>;
        
        if (heatmapCtx) {
            new Chart(heatmapCtx, {
                type: 'bubble',
                data: {
                    datasets: [{
                        label: 'Risks',
                        data: heatmapData,
                        backgroundColor: heatmapData.map(item => getRiskColor(item.risk_level)),
                        borderColor: heatmapData.map(item => getRiskColor(item.risk_level).replace('0.8', '1')),
                        borderWidth: 2,
                        hoverRadius: 14,
                        pointStyle: 'circle',
                        rotation: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: {
                            title: { 
                                display: true, 
                                text: 'Likelihood',
                                font: { 
                                    weight: 'bold',
                                    size: 12
                                }
                            },
                            min: 0.5, 
                            max: 5.5, 
                            ticks: { 
                                stepSize: 1,
                                callback: function(value) {
                                    const labels = ['', 'Very Low', 'Low', 'Medium', 'High', 'Very High'];
                                    return labels[value] || value;
                                }
                            },
                            grid: {
                                display: true,
                                color: 'rgba(0,0,0,0.05)'
                            }
                        },
                        y: {
                            title: { 
                                display: true, 
                                text: 'Impact',
                                font: { 
                                    weight: 'bold',
                                    size: 12
                                }
                            },
                            min: 0.5, 
                            max: 5.5, 
                            ticks: { 
                                stepSize: 1,
                                callback: function(value) {
                                    const labels = ['', 'Very Low', 'Low', 'Medium', 'High', 'Very High'];
                                    return labels[value] || value;
                                }
                            },
                            grid: {
                                display: true,
                                color: 'rgba(0,0,0,0.05)'
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: function(ctx) {
                                    const risk = ctx.raw;
                                    return [
                                        `Risk: ${risk.label}`,
                                        `Likelihood: ${risk.x}/5 - ${getLikelihoodText(risk.x)}`,
                                        `Impact: ${risk.y}/5 - ${getImpactText(risk.y)}`,
                                        `Risk Level: ${risk.risk_level}`,
                                        `Score: ${risk.x * risk.y}/25`
                                    ];
                                }
                            }
                        }
                    },
                    onClick: function(event, elements) {
                        if (elements && elements.length > 0) {
                            const index = elements[0].index;
                            const risk = heatmapData[index];
                            window.location.href = `risk_view.php?id=${risk.id}`;
                        }
                    }
                }
            });
        }

        function getLikelihoodText(value) {
            const labels = {1: 'Very Unlikely', 2: 'Unlikely', 3: 'Possible', 4: 'Likely', 5: 'Almost Certain'};
            return labels[value] || value;
        }

        function getImpactText(value) {
            const labels = {1: 'Insignificant', 2: 'Minor', 3: 'Moderate', 4: 'Major', 5: 'Catastrophic'};
            return labels[value] || value;
        }

        // =============================================
        // CATEGORY CHART
        // =============================================
        const categoryCtx = document.getElementById('categoryChart')?.getContext('2d');
        if (categoryCtx) {
            const categoryLabels = <?= json_encode(array_keys($categoryData)) ?>;
            const categoryValues = <?= json_encode(array_values($categoryData)) ?>;
            
            new Chart(categoryCtx, {
                type: 'doughnut',
                data: {
                    labels: categoryLabels,
                    datasets: [{
                        data: categoryValues,
                        backgroundColor: [
                            '#273274', '#1e5af5', '#f8a01c', '#dc3545',
                            '#17a2b8', '#6f42c1', '#fd7e14', '#20c997',
                            '#e83e8c', '#6610f2', '#007bff', '#28a745'
                        ],
                        borderWidth: 0,
                        hoverOffset: 10
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '65%',
                    plugins: {
                        legend: {
                            position: 'right',
                            labels: {
                                boxWidth: 12,
                                padding: 15,
                                font: { size: 11 }
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.raw || 0;
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = ((value / total) * 100).toFixed(1);
                                    return `${label}: ${value} risks (${percentage}%)`;
                                }
                            }
                        }
                    },
                    onClick: function(event, elements) {
                        if (elements && elements.length > 0) {
                            const index = elements[0].index;
                            const category = categoryLabels[index];
                            showCategoryRisksModal(category);
                        }
                    }
                }
            });
        }

        // =============================================
        // RISK LEVEL CHART
        // =============================================
        const riskLevelCtx = document.getElementById('riskLevelChart')?.getContext('2d');
        if (riskLevelCtx) {
            new Chart(riskLevelCtx, {
                type: 'bar',
                data: {
                    labels: ['Critical', 'High', 'Medium', 'Low'],
                    datasets: [{
                        label: 'Number of Risks',
                        data: [
                            <?= $riskLevelDistribution['Critical'] ?>,
                            <?= $riskLevelDistribution['High'] ?>,
                            <?= $riskLevelDistribution['Medium'] ?>,
                            <?= $riskLevelDistribution['Low'] ?>
                        ],
                        backgroundColor: [
                            'rgba(220, 53, 69, 0.8)',
                            'rgba(253, 126, 20, 0.8)',
                            'rgba(255, 193, 7, 0.8)',
                            'rgba(25, 135, 84, 0.8)'
                        ],
                        borderColor: [
                            '#dc3545',
                            '#fd7e14',
                            '#ffc107',
                            '#198754'
                        ],
                        borderWidth: 2,
                        borderRadius: 8,
                        hoverBackgroundColor: [
                            '#dc3545',
                            '#fd7e14',
                            '#ffc107',
                            '#198754'
                        ]
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return `${context.raw} risks`;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Number of Risks',
                                font: { weight: 'bold' }
                            },
                            grid: {
                                drawBorder: false,
                                color: 'rgba(0,0,0,0.05)'
                            },
                            ticks: {
                                stepSize: 1
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            }
                        }
                    },
                    onClick: function(event, elements) {
                        if (elements && elements.length > 0) {
                            const index = elements[0].index;
                            let level = '';
                            if (index === 0) level = 'Critical';
                            else if (index === 1) level = 'High';
                            else if (index === 2) level = 'Medium';
                            else level = 'Low';
                            showRiskModal(level.toLowerCase(), `${level} Risks`, getRiskCountByLevel(level));
                        }
                    }
                }
            });
        }

        // =============================================
        // STATUS CHART
        // =============================================
        const statusCtx = document.getElementById('statusChart')?.getContext('2d');
        if (statusCtx) {
            const statusLabels = <?= json_encode(array_keys($statusData)) ?>;
            const statusValues = <?= json_encode(array_values($statusData)) ?>;
            
            new Chart(statusCtx, {
                type: 'pie',
                data: {
                    labels: statusLabels,
                    datasets: [{
                        data: statusValues,
                        backgroundColor: [
                            '#ffc107', // Pending
                            '#0d6efd', // Open
                            '#17a2b8', // In Progress
                            '#28a745', // Mitigated
                            '#6c757d', // Closed
                            '#dc3545'  // Rejected
                        ],
                        borderWidth: 0,
                        hoverOffset: 10
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right',
                            labels: {
                                boxWidth: 12,
                                padding: 15,
                                font: { size: 11 }
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.raw || 0;
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = ((value / total) * 100).toFixed(1);
                                    return `${label}: ${value} risks (${percentage}%)`;
                                }
                            }
                        }
                    },
                    onClick: function(event, elements) {
                        if (elements && elements.length > 0) {
                            const index = elements[0].index;
                            const status = statusLabels[index];
                            const statusKey = getStatusKeyFromLabel(status);
                            showRiskModal(statusKey, `${status} Risks`, statusValues[index]);
                        }
                    }
                }
            });
        }

        // =============================================
        // MONTHLY TREND CHART
        // =============================================
        const monthlyCtx = document.getElementById('monthlyChart')?.getContext('2d');
        if (monthlyCtx) {
            const monthlyLabels = <?= json_encode(array_keys($monthlyData)) ?>;
            const monthlyValues = <?= json_encode(array_values($monthlyData)) ?>;
            
            new Chart(monthlyCtx, {
                type: 'line',
                data: {
                    labels: monthlyLabels,
                    datasets: [{
                        label: 'Risks Created',
                        data: monthlyValues,
                        borderColor: '#273274',
                        backgroundColor: 'rgba(39, 50, 116, 0.05)',
                        borderWidth: 3,
                        pointBackgroundColor: '#273274',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        pointRadius: 5,
                        pointHoverRadius: 8,
                        tension: 0.3,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return `${context.raw} risks created`;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Number of Risks',
                                font: { weight: 'bold' }
                            },
                            grid: {
                                drawBorder: false,
                                color: 'rgba(0,0,0,0.05)'
                            },
                            ticks: {
                                stepSize: 1
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            }
                        }
                    }
                }
            });
        }

        // =============================================
        // HELPER FUNCTIONS
        // =============================================
        function getRiskCountByLevel(level) {
            const count = {
                'Critical': <?= $critical_risks ?>,
                'High': <?= $high_risks ?>,
                'Medium': <?= $medium_risks ?>,
                'Low': <?= $low_risks ?>
            };
            return count[level] || 0;
        }

        function getStatusKeyFromLabel(statusLabel) {
            const map = {
                'Pending Review': 'pending_review',
                'Open': 'open',
                'In Progress': 'in_progress',
                'Mitigated': 'mitigated',
                'Closed': 'closed',
                'Rejected': 'rejected'
            };
            return map[statusLabel] || statusLabel.toLowerCase().replace(' ', '_');
        }

        // =============================================
        // SHOW RISK MODAL - FIXED WORKING VERSION
        // =============================================
        function showRiskModal(filterType, titleText, count) {
            if (count === 0) {
                Swal.fire({
                    icon: 'info',
                    title: 'No Risks Found',
                    text: `There are no ${titleText.toLowerCase()} at the moment.`,
                    confirmButtonColor: '#273274'
                });
                return;
            }
            
            document.getElementById('modalTitleText').textContent = `${titleText} (${count})`;
            
            // Filter risks based on type
            let filteredRisks = [];
            
            switch(filterType) {
                case 'critical':
                    filteredRisks = risksData.filter(r => (parseInt(r.likelihood) * parseInt(r.impact)) >= 16);
                    break;
                case 'high':
                    filteredRisks = risksData.filter(r => {
                        const score = parseInt(r.likelihood) * parseInt(r.impact);
                        return score >= 10 && score <= 15;
                    });
                    break;
                case 'medium':
                    filteredRisks = risksData.filter(r => {
                        const score = parseInt(r.likelihood) * parseInt(r.impact);
                        return score >= 5 && score <= 9;
                    });
                    break;
                case 'low':
                    filteredRisks = risksData.filter(r => {
                        const score = parseInt(r.likelihood) * parseInt(r.impact);
                        return score <= 4;
                    });
                    break;
                case 'pending_review':
                    filteredRisks = risksData.filter(r => r.status_key === 'pending_review');
                    break;
                case 'open':
                    filteredRisks = risksData.filter(r => r.status_key === 'open');
                    break;
                case 'in_progress':
                    filteredRisks = risksData.filter(r => r.status_key === 'in_progress');
                    break;
                case 'mitigated':
                    filteredRisks = risksData.filter(r => r.status_key === 'mitigated');
                    break;
                case 'closed':
                    filteredRisks = risksData.filter(r => r.status_key === 'closed');
                    break;
                case 'rejected':
                    filteredRisks = risksData.filter(r => r.status_key === 'rejected');
                    break;
                case 'my_assigned':
                    filteredRisks = risksData.filter(r => r.owner_id == currentUserId && r.status_key !== 'closed' && r.status_key !== 'rejected');
                    break;
                case 'unassigned':
                    filteredRisks = risksData.filter(r => !r.owner_id && r.status_key !== 'closed' && r.status_key !== 'rejected');
                    break;
                case 'no_mitigation':
                    filteredRisks = risksData.filter(r => r.mitigation_count == 0 && r.status_key !== 'closed' && r.status_key !== 'rejected');
                    break;
                default:
                    filteredRisks = risksData;
            }
            
            // Generate HTML for modal body
            let html = `
                <div class="mb-4">
                    <div class="d-flex justify-content-between align-items-center">
                        <h6 class="fw-bold text-dashen-primary">Found ${filteredRisks.length} Risks</h6>
                        <span class="badge bg-dashen-primary px-3 py-2 rounded-pill">
                            ${titleText}
                        </span>
                    </div>
                </div>
            `;
            
            if (filteredRisks.length === 0) {
                html += `
                    <div class="text-center py-5">
                        <i class="bi bi-shield-x text-muted display-1"></i>
                        <h5 class="mt-4 text-muted">No Risks Found</h5>
                        <p class="text-muted">There are no risks matching this filter.</p>
                    </div>
                `;
            } else {
                html += `<div style="max-height: 500px; overflow-y: auto;">`;
                
                filteredRisks.forEach(risk => {
                    const score = parseInt(risk.likelihood) * parseInt(risk.impact);
                    let riskLevelClass = '';
                    let riskLevelText = '';
                    
                    if (score >= 16) { riskLevelClass = 'badge-critical'; riskLevelText = 'Critical'; }
                    else if (score >= 10) { riskLevelClass = 'badge-high'; riskLevelText = 'High'; }
                    else if (score >= 5) { riskLevelClass = 'badge-medium'; riskLevelText = 'Medium'; }
                    else { riskLevelClass = 'badge-low'; riskLevelText = 'Low'; }
                    
                    let statusClass = '';
                    if (risk.status_key === 'pending_review') statusClass = 'status-pending';
                    else if (risk.status_key === 'open') statusClass = 'status-open';
                    else if (risk.status_key === 'in_progress') statusClass = 'status-progress';
                    else if (risk.status_key === 'mitigated') statusClass = 'status-mitigated';
                    else if (risk.status_key === 'closed') statusClass = 'status-closed';
                    else if (risk.status_key === 'rejected') statusClass = 'status-rejected';
                    else statusClass = 'bg-secondary text-white';
                    
                    html += `
                        <div class="risk-detail-item">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <h6 class="fw-bold mb-2">
                                        <a href="risk_view.php?id=${risk.id}" class="text-decoration-none text-dashen-primary">
                                            #${risk.id} - ${escapeHtml(risk.title)}
                                        </a>
                                    </h6>
                                    <div class="d-flex flex-wrap gap-2 mb-2">
                                        <span class="badge bg-light text-dark px-3 py-2">
                                            <i class="bi bi-folder me-1"></i>${escapeHtml(risk.project_name || 'N/A')}
                                        </span>
                                        <span class="risk-badge ${riskLevelClass} px-3 py-2">
                                            ${riskLevelText} (${score})
                                        </span>
                                        <span class="status-badge ${statusClass} px-3 py-2">
                                            ${escapeHtml(risk.status_label || 'Unknown')}
                                        </span>
                                        ${risk.owner_name ? `
                                            <span class="badge bg-light text-dark px-3 py-2">
                                                <i class="bi bi-person me-1"></i>${escapeHtml(risk.owner_name)}
                                            </span>
                                        ` : ''}
                                    </div>
                                    ${risk.description ? `
                                        <p class="small text-muted mb-0">${escapeHtml(risk.description.substring(0, 100))}...</p>
                                    ` : ''}
                                </div>
                                <div class="btn-group">
                                    <a href="risk_view.php?id=${risk.id}" class="btn btn-sm btn-outline-dashen" title="View">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    ${userRole === 'super_admin' || userRole === 'pm_manager' || risk.created_by == currentUserId ? `
                                        <a href="risk_edit.php?id=${risk.id}" class="btn btn-sm btn-outline-primary" title="Edit">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                    ` : ''}
                                </div>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mt-2 pt-2 border-top small">
                                <span class="text-muted">
                                    <i class="bi bi-calendar me-1"></i>Created: ${new Date(risk.created_at).toLocaleDateString()}
                                </span>
                                <span class="text-muted">
                                    <i class="bi bi-chat me-1"></i>${risk.comment_count || 0} comments
                                </span>
                            </div>
                        </div>
                    `;
                });
                
                html += `</div>`;
            }
            
            document.getElementById('riskModalBody').innerHTML = html;
            
            // Update view all button
            const viewAllBtn = document.getElementById('viewAllRisksBtn');
            if (filterType === 'my_assigned') {
                viewAllBtn.href = 'risks.php?assigned_to=me';
            } else if (filterType === 'pending_review') {
                viewAllBtn.href = 'risks.php?status=pending_review';
            } else if (filterType === 'unassigned') {
                viewAllBtn.href = 'risks.php?owner=unassigned';
            } else if (filterType === 'no_mitigation') {
                viewAllBtn.href = 'risks.php?mitigation=0';
            } else if (['critical', 'high', 'medium', 'low'].includes(filterType)) {
                viewAllBtn.href = `risks.php?level=${filterType.charAt(0).toUpperCase() + filterType.slice(1)}`;
            } else {
                viewAllBtn.href = 'risks.php';
            }
            
            // Show modal
            const modal = new bootstrap.Modal(document.getElementById('riskDetailsModal'));
            modal.show();
        }

        // =============================================
        // SHOW CATEGORY RISKS MODAL
        // =============================================
        function showCategoryRisksModal(category) {
            const filteredRisks = risksData.filter(r => (r.category_name || 'Uncategorized') === category);
            
            if (filteredRisks.length === 0) {
                Swal.fire({
                    icon: 'info',
                    title: 'No Risks Found',
                    text: `There are no risks in the "${category}" category.`,
                    confirmButtonColor: '#273274'
                });
                return;
            }
            
            document.getElementById('categoryModalTitle').textContent = `${category} (${filteredRisks.length} risks)`;
            
            let html = `
                <div class="mb-4">
                    <div class="d-flex justify-content-between align-items-center">
                        <h6 class="fw-bold text-dashen-primary">Risks in ${category}</h6>
                        <span class="badge bg-dashen-primary px-3 py-2 rounded-pill">
                            ${filteredRisks.length} Risks
                        </span>
                    </div>
                </div>
                <div style="max-height: 500px; overflow-y: auto;">
            `;
            
            filteredRisks.forEach(risk => {
                const score = parseInt(risk.likelihood) * parseInt(risk.impact);
                let riskLevelClass = '';
                let riskLevelText = '';
                
                if (score >= 16) { riskLevelClass = 'badge-critical'; riskLevelText = 'Critical'; }
                else if (score >= 10) { riskLevelClass = 'badge-high'; riskLevelText = 'High'; }
                else if (score >= 5) { riskLevelClass = 'badge-medium'; riskLevelText = 'Medium'; }
                else { riskLevelClass = 'badge-low'; riskLevelText = 'Low'; }
                
                html += `
                    <div class="risk-detail-item">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h6 class="fw-bold mb-2">
                                    <a href="risk_view.php?id=${risk.id}" class="text-decoration-none text-dashen-primary">
                                        #${risk.id} - ${escapeHtml(risk.title)}
                                    </a>
                                </h6>
                                <div class="d-flex flex-wrap gap-2 mb-2">
                                    <span class="badge bg-light text-dark px-3 py-2">
                                        <i class="bi bi-folder me-1"></i>${escapeHtml(risk.project_name || 'N/A')}
                                    </span>
                                    <span class="risk-badge ${riskLevelClass} px-3 py-2">
                                        ${riskLevelText} (${score})
                                    </span>
                                </div>
                            </div>
                            <a href="risk_view.php?id=${risk.id}" class="btn btn-sm btn-outline-dashen">
                                <i class="bi bi-eye"></i>
                            </a>
                        </div>
                    </div>
                `;
            });
            
            html += '</div>';
            
            document.getElementById('categoryModalBody').innerHTML = html;
            
            const modal = new bootstrap.Modal(document.getElementById('categoryDetailsModal'));
            modal.show();
        }

        // =============================================
        // SHOW ALL CATEGORIES MODAL
        // =============================================
        function showAllCategoriesModal() {
            const categories = <?= json_encode($categoryData) ?>;
            const categoryNames = Object.keys(categories);
            
            let html = `
                <div class="row g-4">
            `;
            
            categoryNames.forEach(cat => {
                const count = categories[cat];
                html += `
                    <div class="col-md-6">
                        <div class="bg-light rounded-4 p-4 stat-clickable" onclick="showCategoryRisksModal('${escapeHtml(cat)}')">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="fw-bold mb-1">${escapeHtml(cat)}</h6>
                                    <span class="text-muted small">${count} risks</span>
                                </div>
                                <span class="badge bg-dashen-primary rounded-pill px-3 py-2">${count}</span>
                            </div>
                            <div class="mt-2">
                                <div class="progress-premium">
                                    <div class="progress-bar-premium" style="width: ${(count / <?= $total_risks ?> * 100)}%"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            });
            
            html += '</div>';
            
            document.getElementById('categoryModalBody').innerHTML = html;
            document.getElementById('categoryModalTitle').textContent = 'All Risk Categories';
            
            const modal = new bootstrap.Modal(document.getElementById('categoryDetailsModal'));
            modal.show();
        }

        // =============================================
        // SHOW ALL STATUSES MODAL
        // =============================================
        function showAllStatusesModal() {
            const statuses = <?= json_encode($statusData) ?>;
            const statusNames = Object.keys(statuses);
            
            let html = `
                <div class="row g-4">
            `;
            
            statusNames.forEach(status => {
                const count = statuses[status];
                let statusClass = '';
                let statusIcon = '';
                
                if (status === 'Pending Review') { statusClass = 'status-pending'; statusIcon = 'hourglass-split'; }
                else if (status === 'Open') { statusClass = 'status-open'; statusIcon = 'check-circle'; }
                else if (status === 'In Progress') { statusClass = 'status-progress'; statusIcon = 'arrow-repeat'; }
                else if (status === 'Mitigated') { statusClass = 'status-mitigated'; statusIcon = 'shield-check'; }
                else if (status === 'Closed') { statusClass = 'status-closed'; statusIcon = 'check2-circle'; }
                else if (status === 'Rejected') { statusClass = 'status-rejected'; statusIcon = 'x-circle'; }
                
                const statusKey = getStatusKeyFromLabel(status);
                
                html += `
                    <div class="col-md-6">
                        <div class="bg-light rounded-4 p-4 stat-clickable" onclick="showRiskModal('${statusKey}', '${status}', ${count})">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <span class="status-badge ${statusClass} px-3 py-2 mb-2 d-inline-block">
                                        <i class="bi bi-${statusIcon} me-1"></i>${status}
                                    </span>
                                    <h6 class="fw-bold mb-1 mt-2">${count} risks</h6>
                                </div>
                                <span class="badge bg-dashen-primary rounded-pill px-3 py-2">${count}</span>
                            </div>
                        </div>
                    </div>
                `;
            });
            
            html += '</div>';
            
            document.getElementById('statusModalBody').innerHTML = html;
            document.getElementById('statusModalTitle').textContent = 'All Risk Statuses';
            
            const modal = new bootstrap.Modal(document.getElementById('statusDetailsModal'));
            modal.show();
        }

        // =============================================
        // ESCAPE HTML TO PREVENT XSS
        // =============================================
        function escapeHtml(text) {
            if (!text) return '';
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.toString().replace(/[&<>"']/g, function(m) { return map[m]; });
        }

        // =============================================
        // EXPORT DASHBOARD DATA
        // =============================================
        function exportDashboardData(format) {
            Swal.fire({
                title: 'Export Dashboard Data',
                text: `Preparing your ${format.toUpperCase()} report...`,
                icon: 'info',
                showConfirmButton: false,
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            setTimeout(() => {
                window.location.href = `risk_export.php?format=${format}&dashboard=1`;
                Swal.close();
            }, 1500);
        }

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
        // CHECK FOR NOTIFICATIONS
        // =============================================
        $(document).ready(function() {
            <?php if ($pending_review_risks > 0 && $can_approve_risk): ?>
            // Show notification badge
            $('.notification-badge').text(<?= $pending_review_risks ?>).show();
            
            // Only show popup once per session
            if (!sessionStorage.getItem('pendingReviewNotified')) {
                setTimeout(function() {
                    Swal.fire({
                        icon: 'info',
                        title: 'Risks Pending Review',
                        html: `<span class="fw-bold">${<?= $pending_review_risks ?>} risk(s)</span> require your approval.`,
                        showConfirmButton: true,
                        confirmButtonText: 'Review Now',
                        confirmButtonColor: '#273274',
                        showCancelButton: true,
                        cancelButtonText: 'Later'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            showRiskModal('pending_review', 'Pending Review', <?= $pending_review_risks ?>);
                        }
                    });
                    sessionStorage.setItem('pendingReviewNotified', 'true');
                }, 1000);
            }
            <?php endif; ?>
            
            <?php if ($overdue_risks > 0): ?>
            if (!sessionStorage.getItem('overdueNotified')) {
                setTimeout(function() {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Overdue Risks',
                        html: `<span class="fw-bold">${<?= $overdue_risks ?>} risk(s)</span> are past their target resolution date.`,
                        showConfirmButton: true,
                        confirmButtonText: 'View Overdue',
                        confirmButtonColor: '#dc3545',
                        showCancelButton: true,
                        cancelButtonText: 'Later'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            showRiskModal('my_assigned', 'My Overdue Risks', <?= $overdue_risks ?>);
                        }
                    });
                    sessionStorage.setItem('overdueNotified', 'true');
                }, 2000);
            }
            <?php endif; ?>
        });
    </script>
</body>
</html>
<?php
$conn->close();
?>