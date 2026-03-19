<?php
// project_intake_list.php
//session_start();
require_once 'includes/header.php';
//require_once '../auth_check.php';

// Define sanitize_input function if not exists
if (!function_exists('sanitize_input')) {
    function sanitize_input($data) {
        global $conn;
        if (isset($conn)) {
            $data = mysqli_real_escape_string($conn, trim($data));
        }
        $data = stripslashes($data);
        $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
        return $data;
    }
}

// Get current user info
$user_id = $_SESSION['user_id'] ?? null;
$username = $_SESSION['username'] ?? 'Guest';
$user_role = $_SESSION['system_role'] ?? '';

// Set page title
$page_title = "Project Intake List";
$page_subtitle = "View and manage all project intake submissions";

// Check permissions
if (!in_array($user_role, ['super_admin', 'pm_manager', 'pm_employee', 'pm_viewer'])) {
    die("You don't have permission to access this page.");
}

// Handle filters
$filter_status = $_GET['status'] ?? '';
$filter_department = $_GET['department'] ?? '';
$filter_date_from = $_GET['date_from'] ?? '';
$filter_date_to = $_GET['date_from'] ?? '';
$filter_search = $_GET['search'] ?? '';

// Build query with filters
$query = "SELECT pi.*, d.department_name, u.username as submitter_name 
          FROM project_intakes pi 
          LEFT JOIN departments d ON pi.department_id = d.id 
          LEFT JOIN users u ON pi.submitted_by = u.id 
          WHERE 1=1";
$params = [];
$types = "";

// Add permission filter
if (!in_array($user_role, ['super_admin', 'pm_manager'])) {
    $query .= " AND pi.submitted_by = ?";
    $params[] = $user_id;
    $types .= "i";
}

// Add status filter
if ($filter_status) {
    $query .= " AND pi.status = ?";
    $params[] = $filter_status;
    $types .= "s";
}

// Add department filter
if ($filter_department) {
    $query .= " AND pi.department_id = ?";
    $params[] = $filter_department;
    $types .= "i";
}

// Add date filters
if ($filter_date_from) {
    $query .= " AND DATE(pi.submitted_date) >= ?";
    $params[] = $filter_date_from;
    $types .= "s";
}

if ($filter_date_to) {
    $query .= " AND DATE(pi.submitted_date) <= ?";
    $params[] = $filter_date_to;
    $types .= "s";
}

// Add search filter
if ($filter_search) {
    $query .= " AND (pi.project_name LIKE ? OR pi.business_sponsor_name LIKE ? OR pi.proposed_system_name LIKE ?)";
    $search_term = "%$filter_search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= "sss";
}

$query .= " ORDER BY pi.submitted_date DESC";

// Execute query
if ($params) {
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
} else {
    $result = mysqli_query($conn, $query);
}

$intakes = [];
while ($row = mysqli_fetch_assoc($result)) {
    $intakes[] = $row;
}

// Get departments for filter
$departments = [];
$dept_query = "SELECT id, department_name FROM departments ORDER BY department_name";
$dept_result = mysqli_query($conn, $dept_query);
if ($dept_result) {
    while ($row = mysqli_fetch_assoc($dept_result)) {
        $departments[] = $row;
    }
}

// Handle delete request
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $delete_id = intval($_GET['delete']);
    
    // Check permissions
    $check_sql = "SELECT submitted_by, status FROM project_intakes WHERE id = ?";
    $check_stmt = mysqli_prepare($conn, $check_sql);
    mysqli_stmt_bind_param($check_stmt, "i", $delete_id);
    mysqli_stmt_execute($check_stmt);
    mysqli_stmt_bind_result($check_stmt, $submitted_by, $status);
    mysqli_stmt_fetch($check_stmt);
    mysqli_stmt_close($check_stmt);
    
    // Allow delete only if user submitted it or is admin, and status is Draft
    if (($submitted_by == $user_id || in_array($user_role, ['super_admin', 'pm_manager'])) 
        && $status == 'Draft') {
        
        $delete_sql = "DELETE FROM project_intakes WHERE id = ?";
        $delete_stmt = mysqli_prepare($conn, $delete_sql);
        mysqli_stmt_bind_param($delete_stmt, "i", $delete_id);
        
        if (mysqli_stmt_execute($delete_stmt)) {
            $_SESSION['success'] = "Intake deleted successfully!";
        } else {
            $_SESSION['error'] = "Error deleting intake: " . mysqli_error($conn);
        }
        
        mysqli_stmt_close($delete_stmt);
        echo '<script>window.location.href = "project_intake_list.php";</script>';
        exit();
    } else {
        $_SESSION['error'] = "You cannot delete this intake. Only drafts can be deleted by the submitter or admin.";
        echo '<script>window.location.href = "project_intake_list.php";</script>';
        exit();
    }
}

// Get statistics for cards
$stats_query = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status IN ('Submitted', 'Under Review') THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN status = 'Rejected' THEN 1 ELSE 0 END) as rejected
                FROM project_intakes";
                
if (!in_array($user_role, ['super_admin', 'pm_manager'])) {
    $stats_query .= " WHERE submitted_by = $user_id";
}

$stats_result = mysqli_query($conn, $stats_query);
$stats = mysqli_fetch_assoc($stats_result);

// Handle View Details request
if (isset($_GET['view']) && is_numeric($_GET['view'])) {
    $view_id = intval($_GET['view']);
    $view_sql = "SELECT pi.*, d.department_name, u.username as submitter_name 
                 FROM project_intakes pi 
                 LEFT JOIN departments d ON pi.department_id = d.id 
                 LEFT JOIN users u ON pi.submitted_by = u.id 
                 WHERE pi.id = ?";
    $view_stmt = mysqli_prepare($conn, $view_sql);
    mysqli_stmt_bind_param($view_stmt, "i", $view_id);
    mysqli_stmt_execute($view_stmt);
    $view_result = mysqli_stmt_get_result($view_stmt);
    $intake_details = mysqli_fetch_assoc($view_result);
    mysqli_stmt_close($view_stmt);
}

// Handle Evaluation request
if (isset($_GET['evaluate']) && is_numeric($_GET['evaluate'])) {
    $evaluate_id = intval($_GET['evaluate']);
    $evaluate_sql = "SELECT pi.*, d.department_name, u.username as submitter_name 
                     FROM project_intakes pi 
                     LEFT JOIN departments d ON pi.department_id = d.id 
                     LEFT JOIN users u ON pi.submitted_by = u.id 
                     WHERE pi.id = ? AND pi.status IN ('Submitted', 'Under Review')";
    $evaluate_stmt = mysqli_prepare($conn, $evaluate_sql);
    mysqli_stmt_bind_param($evaluate_stmt, "i", $evaluate_id);
    mysqli_stmt_execute($evaluate_stmt);
    $evaluate_result = mysqli_stmt_get_result($evaluate_stmt);
    $evaluate_details = mysqli_fetch_assoc($evaluate_result);
    mysqli_stmt_close($evaluate_stmt);
}

// Handle Evaluation submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_evaluation'])) {
    $project_intake_id = intval($_POST['project_intake_id']);
    $strategic_alignment_score = intval($_POST['strategic_alignment_score']);
    $financial_viability_score = intval($_POST['financial_viability_score']);
    $operational_readiness_score = intval($_POST['operational_readiness_score']);
    $technical_feasibility_score = intval($_POST['technical_feasibility_score']);
    $risk_compliance_score = intval($_POST['risk_compliance_score']);
    $urgency_score = intval($_POST['urgency_score']);
    $gate_decision = sanitize_input($_POST['gate_decision']);
    $decision_justification = sanitize_input($_POST['decision_justification']);
    $feedback_to_submitter = sanitize_input($_POST['feedback_to_submitter']);
    $recommendations = sanitize_input($_POST['recommendations']);
    
    // Calculate weighted scores
    $weights = [
        'strategic_alignment' => 0.25,
        'financial_viability' => 0.20,
        'operational_readiness' => 0.15,
        'technical_feasibility' => 0.15,
        'risk_compliance' => 0.15,
        'urgency' => 0.10
    ];
    
    $strategic_alignment_weighted = ($strategic_alignment_score * $weights['strategic_alignment'] * 100);
    $financial_viability_weighted = ($financial_viability_score * $weights['financial_viability'] * 100);
    $operational_readiness_weighted = ($operational_readiness_score * $weights['operational_readiness'] * 100);
    $technical_feasibility_weighted = ($technical_feasibility_score * $weights['technical_feasibility'] * 100);
    $risk_compliance_weighted = ($risk_compliance_score * $weights['risk_compliance'] * 100);
    $urgency_weighted = ($urgency_score * $weights['urgency'] * 100);
    
    $total_score = $strategic_alignment_weighted + $financial_viability_weighted + 
                   $operational_readiness_weighted + $technical_feasibility_weighted + 
                   $risk_compliance_weighted + $urgency_weighted;
    
    // Insert evaluation
    $eval_sql = "INSERT INTO checkpoint_evaluations (
        project_intake_id, review_board_member_id,
        strategic_alignment_score, financial_viability_score,
        operational_readiness_score, technical_feasibility_score,
        risk_compliance_score, urgency_score,
        strategic_alignment_weighted, financial_viability_weighted,
        operational_readiness_weighted, technical_feasibility_weighted,
        risk_compliance_weighted, urgency_weighted,
        total_score, gate_decision, decision_justification,
        feedback_to_submitter, recommendations, gate_review_date
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
    
    $eval_stmt = mysqli_prepare($conn, $eval_sql);
    mysqli_stmt_bind_param($eval_stmt, "iiiiiiiiiddddddssss",
        $project_intake_id, $user_id,
        $strategic_alignment_score, $financial_viability_score,
        $operational_readiness_score, $technical_feasibility_score,
        $risk_compliance_score, $urgency_score,
        $strategic_alignment_weighted, $financial_viability_weighted,
        $operational_readiness_weighted, $technical_feasibility_weighted,
        $risk_compliance_weighted, $urgency_weighted,
        $total_score, $gate_decision, $decision_justification,
        $feedback_to_submitter, $recommendations
    );
    
    if (mysqli_stmt_execute($eval_stmt)) {
        // Update project intake status
        $new_status = '';
        switch($gate_decision) {
            case 'Accept': $new_status = 'Approved'; break;
            case 'Reject': $new_status = 'Rejected'; break;
            case 'Defer': $new_status = 'Deferred'; break;
            case 'Revise': $new_status = 'Under Review'; break;
        }
        
        $update_sql = "UPDATE project_intakes SET status = ? WHERE id = ?";
        $update_stmt = mysqli_prepare($conn, $update_sql);
        mysqli_stmt_bind_param($update_stmt, "si", $new_status, $project_intake_id);
        mysqli_stmt_execute($update_stmt);
        mysqli_stmt_close($update_stmt);
        
        $_SESSION['success'] = "Evaluation submitted successfully! Score: " . number_format($total_score, 2) . "%";
        echo '<script>window.location.href = "project_intake_list.php";</script>';
        exit();
    }
    
    mysqli_stmt_close($eval_stmt);
}

// Scoring criteria
$scoring_criteria = [
    'strategic_alignment' => [
        'weight' => '25%',
        'scores' => [
            '5' => 'Fully aligned with all bank strategic objectives',
            '4' => 'Strong alignment, only minor gaps',
            '3' => 'Partial alignment with some objectives',
            '2' => 'Weak alignment, limited contribution',
            '1' => 'No alignment'
        ]
    ],
    'financial_viability' => [
        'weight' => '20%',
        'scores' => [
            '5' => 'Budget secured; strong, well-supported financial case',
            '4' => 'Budget secured; solid case with credible ROI',
            '3' => 'Budget secured; reasonable case but benefits moderately defined',
            '2' => 'Budget secured; weak justification',
            '1' => 'Budget secured; unconvincing case'
        ]
    ],
    'operational_readiness' => [
        'weight' => '15%',
        'scores' => [
            '5' => 'All required staff, systems, and capacity available',
            '4' => 'Most resources available, minor gaps',
            '3' => 'Adequate resources, some gaps',
            '2' => 'Significant gaps in staff or systems',
            '1' => 'Major deficiencies, not feasible'
        ]
    ],
    'technical_feasibility' => [
        'weight' => '15%',
        'scores' => [
            '5' => 'Proven, scalable banking technology with minimal integration risk',
            '4' => 'Mature technology, minor integration challenges',
            '3' => 'Viable technology, moderate integration challenges',
            '2' => 'Unproven technology, significant risks',
            '1' => 'High-risk or impractical technology'
        ]
    ],
    'risk_compliance' => [
        'weight' => '15%',
        'scores' => [
            '5' => 'Low risk, robust mitigation, full compliance',
            '4' => 'Low-to-moderate risk, strong mitigation',
            '3' => 'Moderate risk, partial mitigation',
            '2' => 'High risk, limited mitigation',
            '1' => 'Severe risk, inadequate mitigation'
        ]
    ],
    'urgency' => [
        'weight' => '10%',
        'scores' => [
            '5' => 'Immediate action required for critical opportunity',
            '4' => 'High urgency, delay would cause significant impact',
            '3' => 'Action beneficial in near term',
            '2' => 'Some urgency, but delay has limited impact',
            '1' => 'Low urgency, minimal impact'
        ]
    ]
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - BSPMD</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    
    <style>
        :root {
            --dashen-blue: #1a237e;
            --dashen-green: #00c853;
            --dashen-orange: #ff6b00;
            --dashen-red: #de350b;
            --dashen-purple: #6554c0;
            --dashen-cyan: #00b8d9;
            --dashen-gray-50: #fafbfc;
            --dashen-gray-100: #f4f5f7;
            --dashen-gray-200: #ebecf0;
            --dashen-gray-300: #dfe1e6;
            --dashen-gray-400: #c1c7d0;
            --dashen-gray-500: #8993a4;
            --dashen-gray-600: #6b778c;
            --dashen-gray-700: #42526e;
            --dashen-gray-800: #253858;
            --dashen-gray-900: #172b4d;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, var(--dashen-gray-50) 0%, #e3e9f7 100%);
            min-height: 100vh;
        }
        
        .navbar-brand {
            font-weight: 700;
            color: white !important;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            border: 1px solid rgba(0, 0, 0, 0.05);
            height: 100%;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.12);
        }
        
        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 15px;
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 5px;
            color: var(--dashen-gray-900);
        }
        
        .stat-label {
            font-size: 0.9rem;
            color: var(--dashen-gray-600);
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 600;
        }
        
        .filters-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            margin-bottom: 30px;
            border: 1px solid rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }
        
        .table-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            overflow: hidden;
            border: 1px solid rgba(0, 0, 0, 0.05);
        }
        
        .table-header {
            padding: 25px;
            border-bottom: 1px solid var(--dashen-gray-200);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .table-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--dashen-gray-900);
            margin: 0;
        }
        
        .action-btn {
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 500;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            text-decoration: none;
        }
        
        .action-btn.primary {
            background: linear-gradient(135deg, var(--dashen-blue), var(--dashen-cyan));
            color: white;
        }
        
        .action-btn.primary:hover {
            background: linear-gradient(135deg, var(--dashen-cyan), var(--dashen-blue));
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0, 82, 204, 0.3);
            color: white;
        }
        
        .action-btn.outline {
            background: transparent;
            border: 2px solid var(--dashen-blue);
            color: var(--dashen-blue);
        }
        
        .action-btn.outline:hover {
            background: var(--dashen-blue);
            color: white;
        }
        
        .intake-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .intake-table th {
            background: var(--dashen-gray-50);
            padding: 16px;
            font-weight: 600;
            color: var(--dashen-gray-700);
            text-align: left;
            border-bottom: 2px solid var(--dashen-gray-200);
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .intake-table td {
            padding: 16px;
            border-bottom: 1px solid var(--dashen-gray-200);
            vertical-align: middle;
        }
        
        .intake-table tr:hover {
            background: var(--dashen-gray-50);
        }
        
        .ref-id {
            font-family: 'Monaco', 'Courier New', monospace;
            font-weight: 600;
            color: var(--dashen-blue);
        }
        
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .status-draft {
            background: linear-gradient(135deg, #fdfcfb 0%, #e2d1c3 100%);
            color: #795548;
        }
        
        .status-submitted {
            background: linear-gradient(135deg, #a1c4fd 0%, #c2e9fb 100%);
            color: #1565c0;
        }
        
        .status-review {
            background: linear-gradient(135deg, #ffecd2 0%, #fcb69f 100%);
            color: #e65100;
        }
        
        .status-approved {
            background: linear-gradient(135deg, #c2e9fb 0%, #a1c4fd 100%);
            color: #00695c;
        }
        
        .status-rejected {
            background: linear-gradient(135deg, #ff9a9e 0%, #fecfef 100%);
            color: #c62828;
        }
        
        .risk-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .risk-low {
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            color: #155724;
        }
        
        .risk-medium {
            background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
            color: #856404;
        }
        
        .risk-high {
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
            color: #721c24;
        }
        
        .action-menu {
            display: flex;
            gap: 8px;
        }
        
        .action-btn-sm {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: none;
            background: transparent;
            color: var(--dashen-gray-600);
            transition: all 0.2s ease;
            cursor: pointer;
        }
        
        .action-btn-sm:hover {
            background: var(--dashen-gray-100);
            color: var(--dashen-blue);
            transform: translateY(-1px);
        }
        
        .modal-xl {
            max-width: 1200px;
        }
        
        .score-display {
            background: #f8f9fa;
            border: 2px solid #dee2e6;
            border-radius: 10px;
            padding: 15px;
            text-align: center;
        }
        
        .score-value {
            font-size: 2.5rem;
            font-weight: bold;
            color: var(--dashen-blue);
        }
        
        .score-weighted {
            font-size: 0.9rem;
            color: #6c757d;
        }
        
        .total-score-display {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border: 3px solid #dee2e6;
            border-radius: 15px;
            padding: 20px;
            text-align: center;
        }
        
        @media (max-width: 768px) {
            .stat-card {
                padding: 20px;
            }
            
            .table-header {
                flex-direction: column;
                align-items: stretch;
            }
            
            .action-menu {
                flex-wrap: wrap;
            }
            
            .action-btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
 
    <!-- Main Content -->
    <div class="container-fluid py-4">
        <!-- Messages -->
        <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show mb-4" role="alert">
            <i class="fas fa-check-circle me-2"></i>
            <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <!-- Page Header -->
        <div class="page-header mb-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h1 class="mb-1"><i class="fas fa-list me-2"></i>Project Intake List</h1>
                    <p class="text-muted mb-0">View and manage all project intake submissions</p>
                </div>
                <div>
                    <a href="project_intake_form.php" class="action-btn primary">
                        <i class="fas fa-plus-circle me-1"></i>New Intake
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-md-3 col-sm-6 mb-3">
                <div class="stat-card">
                    <div class="stat-icon" style="background: linear-gradient(135deg, var(--dashen-blue), var(--dashen-cyan)); color: white;">
                        <i class="fas fa-file-alt"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['total'] ?? 0; ?></div>
                    <div class="stat-label">Total Intakes</div>
                </div>
            </div>
            
            <div class="col-md-3 col-sm-6 mb-3">
                <div class="stat-card">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #ff9a9e, #fad0c4); color: #d63031;">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['pending'] ?? 0; ?></div>
                    <div class="stat-label">Pending Review</div>
                </div>
            </div>
            
            <div class="col-md-3 col-sm-6 mb-3">
                <div class="stat-card">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #a1c4fd, #c2e9fb); color: #0984e3;">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['approved'] ?? 0; ?></div>
                    <div class="stat-label">Approved</div>
                </div>
            </div>
            
            <div class="col-md-3 col-sm-6 mb-3">
                <div class="stat-card">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #ff9a9e, #fecfef); color: #e84393;">
                        <i class="fas fa-times-circle"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['rejected'] ?? 0; ?></div>
                    <div class="stat-label">Rejected</div>
                </div>
            </div>
        </div>
        
        <!-- Filters Card -->
        <div class="filters-card">
            <div class="card-body">
                <form method="GET" action="" id="filterForm">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label small text-muted mb-1">Status</label>
                            <select class="form-select" name="status">
                                <option value="">All Statuses</option>
                                <option value="Draft" <?php echo $filter_status == 'Draft' ? 'selected' : ''; ?>>Draft</option>
                                <option value="Submitted" <?php echo $filter_status == 'Submitted' ? 'selected' : ''; ?>>Submitted</option>
                                <option value="Under Review" <?php echo $filter_status == 'Under Review' ? 'selected' : ''; ?>>Under Review</option>
                                <option value="Approved" <?php echo $filter_status == 'Approved' ? 'selected' : ''; ?>>Approved</option>
                                <option value="Rejected" <?php echo $filter_status == 'Rejected' ? 'selected' : ''; ?>>Rejected</option>
                                <option value="Deferred" <?php echo $filter_status == 'Deferred' ? 'selected' : ''; ?>>Deferred</option>
                            </select>
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label small text-muted mb-1">Department</label>
                            <select class="form-select" name="department">
                                <option value="">All Departments</option>
                                <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo $dept['id']; ?>" 
                                    <?php echo $filter_department == $dept['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($dept['department_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-2">
                            <label class="form-label small text-muted mb-1">From Date</label>
                            <input type="date" class="form-control" name="date_from" value="<?php echo $filter_date_from; ?>">
                        </div>
                        
                        <div class="col-md-2">
                            <label class="form-label small text-muted mb-1">To Date</label>
                            <input type="date" class="form-control" name="date_to" value="<?php echo $filter_date_to; ?>">
                        </div>
                        
                        <div class="col-md-2">
                            <label class="form-label small text-muted mb-1">Search</label>
                            <input type="text" class="form-control" name="search" value="<?php echo htmlspecialchars($filter_search); ?>" 
                                   placeholder="Search...">
                        </div>
                    </div>
                    
                    <div class="row mt-3">
                        <div class="col-md-12">
                            <div class="d-flex justify-content-end gap-2">
                                <button type="submit" class="action-btn primary">
                                    <i class="fas fa-filter me-1"></i>Apply Filters
                                </button>
                                <a href="project_intake_list.php" class="action-btn outline">
                                    <i class="fas fa-redo me-1"></i>Clear All
                                </a>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Intakes Table -->
        <div class="table-container">
            <div class="table-header">
                <h3 class="table-title">Project Intakes (<?php echo count($intakes); ?>)</h3>
                <div class="table-actions">
                    <button type="button" class="action-btn outline" onclick="exportToExcel()">
                        <i class="fas fa-file-excel me-1"></i>Export
                    </button>
                    <button type="button" class="action-btn outline" onclick="window.print()">
                        <i class="fas fa-print me-1"></i>Print
                    </button>
                </div>
            </div>
            
            <?php if (empty($intakes)): ?>
            <div class="text-center py-5">
                <i class="fas fa-file-alt fa-4x text-muted mb-3"></i>
                <h4 class="text-muted">No Project Intakes Found</h4>
                <p class="text-muted mb-4">
                    <?php if ($filter_status || $filter_department || $filter_date_from || $filter_date_to || $filter_search): ?>
                    Try adjusting your filters or search criteria to see more results.
                    <?php else: ?>
                    Create your first project intake to get started.
                    <?php endif; ?>
                </p>
                <a href="project_intake_form.php" class="action-btn primary">
                    <i class="fas fa-plus-circle me-1"></i>Create New Intake
                </a>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="intake-table">
                    <thead>
                        <tr>
                            <th>Ref ID</th>
                            <th>Project Details</th>
                            <th>Department</th>
                            <th>Submitted By</th>
                            <th>Date</th>
                            <th>Budget</th>
                            <th>Risk</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($intakes as $intake): 
                            $status_class = strtolower(str_replace(' ', '-', $intake['status']));
                            $risk_class = strtolower($intake['overall_risk_rating']);
                        ?>
                        <tr>
                            <td>
                                <span class="ref-id">PI-<?php echo str_pad($intake['id'], 6, '0', STR_PAD_LEFT); ?></span>
                            </td>
                            <td>
                                <div class="fw-semibold"><?php echo htmlspecialchars($intake['project_name']); ?></div>
                                <div class="small text-muted">
                                    <?php if ($intake['business_sponsor_name']): ?>
                                    <i class="fas fa-user-tie me-1"></i><?php echo htmlspecialchars($intake['business_sponsor_name']); ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($intake['department_name'] ?? 'N/A'); ?></td>
                            <td>
                                <div><?php echo htmlspecialchars($intake['submitter_name']); ?></div>
                                <small class="text-muted">ID: <?php echo $intake['submitted_by']; ?></small>
                            </td>
                            <td>
                                <div><?php echo date('M d, Y', strtotime($intake['submitted_date'] ?? $intake['created_at'])); ?></div>
                                <small class="text-muted"><?php echo date('h:i A', strtotime($intake['submitted_date'] ?? $intake['created_at'])); ?></small>
                            </td>
                            <td>
                                <?php if ($intake['estimated_total_budget']): ?>
                                <span class="fw-semibold">$<?php echo number_format($intake['estimated_total_budget'], 0); ?></span>
                                <?php else: ?>
                                <span class="text-muted small">N/A</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="risk-badge <?php echo $risk_class; ?>">
                                    <i class="fas fa-<?php echo $risk_class == 'high' ? 'exclamation-triangle' : ($risk_class == 'medium' ? 'exclamation-circle' : 'check-circle'); ?>"></i>
                                    <?php echo $intake['overall_risk_rating']; ?>
                                </span>
                            </td>
                            <td>
                                <span class="status-badge status-<?php echo $status_class; ?>">
                                    <i class="fas fa-<?php 
                                        switch($intake['status']) {
                                            case 'Draft': echo 'pencil-alt'; break;
                                            case 'Submitted': echo 'paper-plane'; break;
                                            case 'Under Review': echo 'search'; break;
                                            case 'Approved': echo 'check'; break;
                                            case 'Rejected': echo 'times'; break;
                                            case 'Deferred': echo 'pause'; break;
                                            default: echo 'circle';
                                        }
                                    ?>"></i>
                                    <?php echo $intake['status']; ?>
                                </span>
                            </td>
                            <td>
                                <div class="action-menu">
                                    <button type="button" class="action-btn-sm view-btn" 
                                            data-intake-id="<?php echo $intake['id']; ?>"
                                            title="View Details">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    
                                    <?php if ($intake['status'] == 'Draft' && ($intake['submitted_by'] == $user_id || in_array($user_role, ['super_admin', 'pm_manager']))): ?>
                                    <a href="project_intake_form.php?edit=<?php echo $intake['id']; ?>" 
                                       class="action-btn-sm" title="Edit Draft">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <button type="button" class="action-btn-sm delete-btn" 
                                            data-intake-id="<?php echo $intake['id']; ?>"
                                            data-project-name="<?php echo htmlspecialchars($intake['project_name']); ?>"
                                            title="Delete Draft">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                    <?php endif; ?>
                                    
                                    <?php if ($intake['status'] == 'Submitted' && in_array($user_role, ['super_admin', 'pm_manager'])): ?>
                                    <button type="button" class="action-btn-sm evaluate-btn" 
                                            data-intake-id="<?php echo $intake['id']; ?>"
                                            data-project-name="<?php echo htmlspecialchars($intake['project_name']); ?>"
                                            title="Evaluate">
                                        <i class="fas fa-clipboard-check"></i>
                                    </button>
                                    <?php endif; ?>
                                    
                                    <?php if ($intake['status'] == 'Approved' && in_array($user_role, ['super_admin', 'pm_manager'])): ?>
                                    <a href="../dashboard_project_manager.php?create_from_intake=<?php echo $intake['id']; ?>" 
                                       class="action-btn-sm" title="Create Project">
                                        <i class="fas fa-project-diagram"></i>
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- View Details Modal -->
    <div class="modal fade" id="viewModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-eye me-2"></i>Project Intake Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php if (isset($intake_details)): ?>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Project Name</label>
                            <div class="form-control-plaintext"><?php echo htmlspecialchars($intake_details['project_name']); ?></div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Reference ID</label>
                            <div class="form-control-plaintext">PI-<?php echo str_pad($intake_details['id'], 6, '0', STR_PAD_LEFT); ?></div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Department</label>
                            <div class="form-control-plaintext"><?php echo htmlspecialchars($intake_details['department_name'] ?? 'N/A'); ?></div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Status</label>
                            <div class="form-control-plaintext">
                                <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $intake_details['status'])); ?>">
                                    <?php echo $intake_details['status']; ?>
                                </span>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Business Sponsor</label>
                            <div class="form-control-plaintext"><?php echo htmlspecialchars($intake_details['business_sponsor_name'] ?? 'N/A'); ?></div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Project Champion</label>
                            <div class="form-control-plaintext"><?php echo htmlspecialchars($intake_details['project_champion_name'] ?? 'N/A'); ?></div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Proposed Start Date</label>
                            <div class="form-control-plaintext"><?php echo date('M d, Y', strtotime($intake_details['proposed_start_date'])); ?></div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Proposed End Date</label>
                            <div class="form-control-plaintext"><?php echo date('M d, Y', strtotime($intake_details['proposed_end_date'])); ?></div>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label">Business Challenge</label>
                            <div class="form-control-plaintext border rounded p-3"><?php echo nl2br(htmlspecialchars($intake_details['business_challenge'])); ?></div>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label">Strategic Goals</label>
                            <div class="form-control-plaintext border rounded p-3"><?php echo nl2br(htmlspecialchars($intake_details['strategic_goals'])); ?></div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Estimated Budget</label>
                            <div class="form-control-plaintext">$<?php echo number_format($intake_details['estimated_total_budget'], 2); ?></div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Risk Rating</label>
                            <div class="form-control-plaintext">
                                <span class="risk-badge <?php echo strtolower($intake_details['overall_risk_rating']); ?>">
                                    <?php echo $intake_details['overall_risk_rating']; ?>
                                </span>
                            </div>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label">Submitted By</label>
                            <div class="form-control-plaintext"><?php echo htmlspecialchars($intake_details['submitter_name']); ?> on <?php echo date('M d, Y H:i', strtotime($intake_details['submitted_date'])); ?></div>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-4">
                        <i class="fas fa-spinner fa-spin fa-2x text-muted mb-3"></i>
                        <p class="text-muted">Loading details...</p>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <?php if (isset($intake_details) && $intake_details['status'] == 'Submitted' && in_array($user_role, ['super_admin', 'pm_manager'])): ?>
                    <button type="button" class="btn btn-primary evaluate-btn" 
                            data-intake-id="<?php echo $intake_details['id']; ?>"
                            data-project-name="<?php echo htmlspecialchars($intake_details['project_name']); ?>">
                        <i class="fas fa-clipboard-check me-1"></i>Evaluate
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Evaluation Modal -->
    <div class="modal fade" id="evaluateModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <form method="POST" action="" id="evaluationForm">
                    <div class="modal-header bg-success text-white">
                        <h5 class="modal-title"><i class="fas fa-clipboard-check me-2"></i>Checkpoint Evaluation</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="project_intake_id" id="projectIntakeId">
                        <input type="hidden" name="submit_evaluation" value="1">
                        
                        <!-- Project Information -->
                        <div class="alert alert-info mb-4">
                            <h6 class="alert-heading"><i class="fas fa-info-circle me-2"></i>Project Information</h6>
                            <p class="mb-0" id="projectInfo">Loading project information...</p>
                        </div>
                        
                        <!-- Scoring Matrix -->
                        <div class="row mb-4">
                            <div class="col-12">
                                <h5 class="border-bottom pb-2 mb-3"><i class="fas fa-table me-2"></i>Scoring Matrix</h5>
                                
                                <!-- Strategic Alignment (25%) -->
                                <div class="card mb-3">
                                    <div class="card-header">
                                        <h6 class="mb-0">Strategic Alignment (25%)</h6>
                                        <small class="text-muted">Degree to which the initiative supports approved strategic priorities</small>
                                    </div>
                                    <div class="card-body">
                                        <div class="row">
                                            <div class="col-md-8">
                                                <label class="form-label required">Select Score (1-5)</label>
                                                <select class="form-select score-select" name="strategic_alignment_score" data-weight="0.25" required>
                                                    <option value="">Select Score</option>
                                                    <?php foreach ($scoring_criteria['strategic_alignment']['scores'] as $score => $description): ?>
                                                    <option value="<?php echo $score; ?>">
                                                        <?php echo $score; ?> - <?php echo $description; ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="score-display">
                                                    <div class="score-value" id="strategicScore">0</div>
                                                    <div class="score-weighted" id="strategicWeighted">0.00%</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Financial Viability (20%) -->
                                <div class="card mb-3">
                                    <div class="card-header">
                                        <h6 class="mb-0">Financial Viability (20%)</h6>
                                        <small class="text-muted">Strength of business case, cost realism, funding availability, and projected ROI</small>
                                    </div>
                                    <div class="card-body">
                                        <div class="row">
                                            <div class="col-md-8">
                                                <label class="form-label required">Select Score (1-5)</label>
                                                <select class="form-select score-select" name="financial_viability_score" data-weight="0.20" required>
                                                    <option value="">Select Score</option>
                                                    <?php foreach ($scoring_criteria['financial_viability']['scores'] as $score => $description): ?>
                                                    <option value="<?php echo $score; ?>">
                                                        <?php echo $score; ?> - <?php echo $description; ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="score-display">
                                                    <div class="score-value" id="financialScore">0</div>
                                                    <div class="score-weighted" id="financialWeighted">0.00%</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Operational Readiness (15%) -->
                                <div class="card mb-3">
                                    <div class="card-header">
                                        <h6 class="mb-0">Operational Readiness (15%)</h6>
                                        <small class="text-muted">Availability of internal capacity, skills, and infrastructure</small>
                                    </div>
                                    <div class="card-body">
                                        <div class="row">
                                            <div class="col-md-8">
                                                <label class="form-label required">Select Score (1-5)</label>
                                                <select class="form-select score-select" name="operational_readiness_score" data-weight="0.15" required>
                                                    <option value="">Select Score</option>
                                                    <?php foreach ($scoring_criteria['operational_readiness']['scores'] as $score => $description): ?>
                                                    <option value="<?php echo $score; ?>">
                                                        <?php echo $score; ?> - <?php echo $description; ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="score-display">
                                                    <div class="score-value" id="operationalScore">0</div>
                                                    <div class="score-weighted" id="operationalWeighted">0.00%</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Technical Feasibility (15%) -->
                                <div class="card mb-3">
                                    <div class="card-header">
                                        <h6 class="mb-0">Technical Feasibility (15%)</h6>
                                        <small class="text-muted">Practicality and reliability of proposed technical solution</small>
                                    </div>
                                    <div class="card-body">
                                        <div class="row">
                                            <div class="col-md-8">
                                                <label class="form-label required">Select Score (1-5)</label>
                                                <select class="form-select score-select" name="technical_feasibility_score" data-weight="0.15" required>
                                                    <option value="">Select Score</option>
                                                    <?php foreach ($scoring_criteria['technical_feasibility']['scores'] as $score => $description): ?>
                                                    <option value="<?php echo $score; ?>">
                                                        <?php echo $score; ?> - <?php echo $description; ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="score-display">
                                                    <div class="score-value" id="technicalScore">0</div>
                                                    <div class="score-weighted" id="technicalWeighted">0.00%</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Risk & Compliance (15%) -->
                                <div class="card mb-3">
                                    <div class="card-header">
                                        <h6 class="mb-0">Risk & Compliance (15%)</h6>
                                        <small class="text-muted">Identification, assessment, and mitigation of operational, legal, and regulatory risks</small>
                                    </div>
                                    <div class="card-body">
                                        <div class="row">
                                            <div class="col-md-8">
                                                <label class="form-label required">Select Score (1-5)</label>
                                                <select class="form-select score-select" name="risk_compliance_score" data-weight="0.15" required>
                                                    <option value="">Select Score</option>
                                                    <?php foreach ($scoring_criteria['risk_compliance']['scores'] as $score => $description): ?>
                                                    <option value="<?php echo $score; ?>">
                                                        <?php echo $score; ?> - <?php echo $description; ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="score-display">
                                                    <div class="score-value" id="riskScore">0</div>
                                                    <div class="score-weighted" id="riskWeighted">0.00%</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Urgency (10%) -->
                                <div class="card mb-3">
                                    <div class="card-header">
                                        <h6 class="mb-0">Urgency / Time Sensitivity (10%)</h6>
                                        <small class="text-muted">Time sensitivity and potential impact of delay on organizational objectives</small>
                                    </div>
                                    <div class="card-body">
                                        <div class="row">
                                            <div class="col-md-8">
                                                <label class="form-label required">Select Score (1-5)</label>
                                                <select class="form-select score-select" name="urgency_score" data-weight="0.10" required>
                                                    <option value="">Select Score</option>
                                                    <?php foreach ($scoring_criteria['urgency']['scores'] as $score => $description): ?>
                                                    <option value="<?php echo $score; ?>">
                                                        <?php echo $score; ?> - <?php echo $description; ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="score-display">
                                                    <div class="score-value" id="urgencyScore">0</div>
                                                    <div class="score-weighted" id="urgencyWeighted">0.00%</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Total Score Summary -->
                                <div class="card bg-light">
                                    <div class="card-body">
                                        <div class="row align-items-center">
                                            <div class="col-md-8">
                                                <h5 class="mb-2">Total Score Summary</h5>
                                                <div class="progress" style="height: 30px;">
                                                    <div class="progress-bar bg-success" id="totalScoreBar" style="width: 0%"></div>
                                                </div>
                                                <small class="text-muted">Threshold for approval: 70%</small>
                                            </div>
                                            <div class="col-md-4 text-center">
                                                <div class="total-score-display">
                                                    <h2 class="mb-0" id="totalScore">0.00%</h2>
                                                    <div id="scoreStatus" class="text-muted">Not Calculated</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Decision Section -->
                        <div class="row mb-4">
                            <div class="col-12">
                                <h5 class="border-bottom pb-2 mb-3"><i class="fas fa-gavel me-2"></i>Gate Decision</h5>
                                
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label required">Gate Decision</label>
                                        <select class="form-select" name="gate_decision" id="gateDecision" required>
                                            <option value="">Select Decision</option>
                                            <option value="Accept">Accept - Proceed to Gate Review (Score ≥ 70%)</option>
                                            <option value="Revise">Revise - Needs modifications before reconsideration</option>
                                            <option value="Reject">Reject - Does not meet minimum criteria</option>
                                            <option value="Defer">Defer - Review at later date</option>
                                        </select>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Review Notes</label>
                                        <textarea class="form-control" name="review_notes" rows="2" 
                                                  placeholder="Additional notes for internal review..."></textarea>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label required">Decision Justification</label>
                                    <textarea class="form-control" name="decision_justification" rows="3" required
                                              placeholder="Provide detailed justification for your decision..."></textarea>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Feedback to Submitter</label>
                                    <textarea class="form-control" name="feedback_to_submitter" rows="3"
                                              placeholder="Provide constructive feedback for the submitting team..."></textarea>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Recommendations</label>
                                    <textarea class="form-control" name="recommendations" rows="3"
                                              placeholder="Provide recommendations for improvement or next steps..."></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success" id="submitEvaluationBtn">
                            <i class="fas fa-paper-plane me-1"></i>Submit Evaluation
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        $(document).ready(function() {
            // View Details button click
            $('.view-btn').click(function() {
                const intakeId = $(this).data('intake-id');
                window.location.href = 'project_intake_list.php?view=' + intakeId;
            });
            
            // Delete button click
            $('.delete-btn').click(function() {
                const intakeId = $(this).data('intake-id');
                const projectName = $(this).data('project-name');
                
                Swal.fire({
                    title: 'Delete Draft?',
                    html: `Are you sure you want to delete <strong>"${projectName}"</strong>?<br><br>
                           <span class="text-danger"><i class="fas fa-exclamation-triangle me-1"></i>This action cannot be undone.</span>`,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#d33',
                    cancelButtonColor: '#3085d6',
                    confirmButtonText: 'Yes, delete it!',
                    cancelButtonText: 'Cancel',
                    reverseButtons: true
                }).then((result) => {
                    if (result.isConfirmed) {
                        window.location.href = 'project_intake_list.php?delete=' + intakeId;
                    }
                });
            });
            
            // Evaluate button click
            $('.evaluate-btn').click(function() {
                const intakeId = $(this).data('intake-id');
                const projectName = $(this).data('project-name');
                
                $('#projectIntakeId').val(intakeId);
                $('#projectInfo').html(`<strong>${projectName}</strong> (ID: PI-${intakeId.toString().padStart(6, "0")})`);
                
                // Reset form
                $('#evaluationForm')[0].reset();
                updateScores();
                
                // Show modal
                const evaluateModal = new bootstrap.Modal(document.getElementById('evaluateModal'));
                evaluateModal.show();
            });
            
            // Score calculation
            $('.score-select').change(function() {
                updateScores();
            });
            
            // Gate decision validation
            $('#gateDecision').change(function() {
                validateDecision();
            });
            
            // Form submission validation
            $('#evaluationForm').submit(function(e) {
                if (!validateForm()) {
                    e.preventDefault();
                    Swal.fire({
                        icon: 'error',
                        title: 'Incomplete Form',
                        text: 'Please complete all required fields and ensure scores are selected.'
                    });
                }
            });
            
            // Show view modal if view parameter exists
            <?php if (isset($_GET['view'])): ?>
            const viewModal = new bootstrap.Modal(document.getElementById('viewModal'));
            viewModal.show();
            <?php endif; ?>
            
            // Show evaluate modal if evaluate parameter exists
            <?php if (isset($_GET['evaluate'])): ?>
            const evaluateModal = new bootstrap.Modal(document.getElementById('evaluateModal'));
            $('#projectIntakeId').val(<?php echo $_GET['evaluate']; ?>);
            $('#projectInfo').html('<strong><?php echo htmlspecialchars($evaluate_details['project_name'] ?? ''); ?></strong> (ID: PI-<?php echo str_pad($_GET['evaluate'], 6, '0', STR_PAD_LEFT); ?>)');
            updateScores();
            evaluateModal.show();
            <?php endif; ?>
        });
        
        // Score calculation function
        function updateScores() {
            let totalScore = 0;
            
            // Calculate each dimension
            $('.score-select').each(function() {
                const score = parseInt($(this).val()) || 0;
                const weight = parseFloat($(this).data('weight'));
                const dimension = $(this).attr('name').replace('_score', '');
                
                const weightedScore = score * weight * 100;
                totalScore += weightedScore;
                
                // Update display
                $(`#${dimension}Score`).text(score);
                $(`#${dimension}Weighted`).text(weightedScore.toFixed(2) + '%');
            });
            
            // Update total score
            $('#totalScore').text(totalScore.toFixed(2) + '%');
            $('#totalScoreBar').css('width', Math.min(totalScore, 100) + '%');
            $('#totalScoreBar').attr('aria-valuenow', totalScore.toFixed(2));
            
            // Update score status
            const scoreStatus = $('#scoreStatus');
            if (totalScore >= 70) {
                scoreStatus.removeClass('text-danger text-warning').addClass('text-success');
                scoreStatus.html('<i class="fas fa-check-circle me-1"></i>Meets threshold (≥70%)');
                $('#totalScoreBar').removeClass('bg-warning bg-danger').addClass('bg-success');
            } else if (totalScore >= 50) {
                scoreStatus.removeClass('text-success text-danger').addClass('text-warning');
                scoreStatus.html('<i class="fas fa-exclamation-triangle me-1"></i>Below threshold (<70%)');
                $('#totalScoreBar').removeClass('bg-success bg-danger').addClass('bg-warning');
            } else {
                scoreStatus.removeClass('text-success text-warning').addClass('text-danger');
                scoreStatus.html('<i class="fas fa-times-circle me-1"></i>Significantly below threshold');
                $('#totalScoreBar').removeClass('bg-success bg-warning').addClass('bg-danger');
            }
            
            // Auto-suggest decision based on score
            const decisionSelect = $('#gateDecision');
            if (totalScore >= 70) {
                if (decisionSelect.val() === '' || decisionSelect.val() === 'Reject') {
                    decisionSelect.val('Accept');
                }
            } else if (totalScore >= 50) {
                if (decisionSelect.val() === '' || decisionSelect.val() === 'Reject') {
                    decisionSelect.val('Revise');
                }
            }
            
            validateDecision();
        }
        
        // Validate decision function
        function validateDecision() {
            const decision = $('#gateDecision').val();
            const totalScore = parseFloat($('#totalScore').text());
            const submitBtn = $('#submitEvaluationBtn');
            
            if (decision === 'Accept' && totalScore < 70) {
                submitBtn.prop('disabled', true);
                submitBtn.html('<i class="fas fa-exclamation-triangle me-1"></i>Cannot Accept (Score < 70%)');
            } else {
                submitBtn.prop('disabled', false);
                submitBtn.html('<i class="fas fa-paper-plane me-1"></i>Submit Evaluation');
            }
        }
        
        // Validate form function
        function validateForm() {
            let isValid = true;
            
            // Check all required fields
            $('#evaluationForm [required]').each(function() {
                if (!$(this).val()) {
                    $(this).addClass('is-invalid');
                    isValid = false;
                } else {
                    $(this).removeClass('is-invalid');
                }
            });
            
            // Check if at least one score is selected
            const hasScores = $('.score-select').filter(function() {
                return $(this).val() !== '';
            }).length > 0;
            
            if (!hasScores) {
                isValid = false;
            }
            
            return isValid;
        }
        
        // Export to Excel function
        function exportToExcel() {
            // Create a temporary table without action buttons
            let table = $('.intake-table').clone();
            table.find('td:last-child, th:last-child').remove(); // Remove actions column
            
            // Create workbook
            let html = '<table>' + table.html() + '</table>';
            let blob = new Blob([
                '<html>' +
                '<head>' +
                '<meta charset="UTF-8">' +
                '<style>' +
                'table { border-collapse: collapse; width: 100%; }' +
                'th { background-color: #f8f9fa; font-weight: bold; padding: 10px; border: 1px solid #dee2e6; }' +
                'td { padding: 8px; border: 1px solid #dee2e6; }' +
                'tr:nth-child(even) { background-color: #f8f9fa; }' +
                '</style>' +
                '</head>' +
                '<body>' + html + '</body>' +
                '</html>'
            ], {
                type: "application/vnd.ms-excel"
            });
            
            // Create download link
            let link = document.createElement("a");
            link.href = URL.createObjectURL(blob);
            link.download = "project_intakes_" + new Date().toISOString().slice(0,10) + ".xls";
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            
            // Show success message
            Swal.fire({
                icon: 'success',
                title: 'Export Started',
                text: 'File will download shortly.',
                timer: 2000,
                showConfirmButton: false
            });
        }
    </script>
</body>
</html>