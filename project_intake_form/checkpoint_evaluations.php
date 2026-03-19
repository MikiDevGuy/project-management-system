<?php
// checkpoint_evaluations.php
$page_title = "Checkpoint Evaluations";
$page_subtitle = "Review and evaluate project submissions";

// Include header
require_once 'includes/header.php';

// Define sanitize_input function if not already defined
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

// Check permissions - Only Review Board members (super_admin, pm_manager) can access
if (!has_role(['super_admin', 'pm_manager'])) {
    echo '<div class="content-wrapper">';
    echo '<div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-ban me-2"></i>
            <strong>Access Denied!</strong> You do not have permission to access checkpoint evaluations.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>';
    echo '</div>';
    require_once 'includes/footer.php';
    exit();
}

// Handle GET parameters for view and evaluate
$view_id = isset($_GET['view']) ? intval($_GET['view']) : 0;
$evaluate_id = isset($_GET['evaluate']) ? intval($_GET['evaluate']) : 0;
$delete_evaluation = isset($_GET['delete_evaluation']) ? intval($_GET['delete_evaluation']) : 0;

// Handle evaluation submission
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
    $review_notes = sanitize_input($_POST['review_notes'] ?? '');
    
    // Calculate weighted scores based on BSPMD scoring matrix
    $weights = [
        'strategic_alignment' => 0.25,      // 25%
        'financial_viability' => 0.20,      // 20%
        'operational_readiness' => 0.15,    // 15%
        'technical_feasibility' => 0.15,    // 15%
        'risk_compliance' => 0.15,          // 15%
        'urgency' => 0.10                   // 10%
    ];
    
    // Calculate weighted scores (score * weight * 100)
    $strategic_alignment_weighted = ($strategic_alignment_score * $weights['strategic_alignment'] * 100);
    $financial_viability_weighted = ($financial_viability_score * $weights['financial_viability'] * 100);
    $operational_readiness_weighted = ($operational_readiness_score * $weights['operational_readiness'] * 100);
    $technical_feasibility_weighted = ($technical_feasibility_score * $weights['technical_feasibility'] * 100);
    $risk_compliance_weighted = ($risk_compliance_score * $weights['risk_compliance'] * 100);
    $urgency_weighted = ($urgency_score * $weights['urgency'] * 100);
    
    // Calculate total score
    $total_score = $strategic_alignment_weighted + $financial_viability_weighted + 
                   $operational_readiness_weighted + $technical_feasibility_weighted + 
                   $risk_compliance_weighted + $urgency_weighted;
    
    // Insert evaluation into database
    $sql = "INSERT INTO checkpoint_evaluations (
        project_intake_id, review_board_member_id,
        strategic_alignment_score, financial_viability_score,
        operational_readiness_score, technical_feasibility_score,
        risk_compliance_score, urgency_score,
        strategic_alignment_weighted, financial_viability_weighted,
        operational_readiness_weighted, technical_feasibility_weighted,
        risk_compliance_weighted, urgency_weighted,
        total_score, gate_decision, decision_justification,
        feedback_to_submitter, recommendations, review_notes, gate_review_date
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
    
    $stmt = mysqli_prepare($conn, $sql);
    
    mysqli_stmt_bind_param($stmt, "iiiiiiiiiddddddsssss",
        $project_intake_id, $user_id,
        $strategic_alignment_score, $financial_viability_score,
        $operational_readiness_score, $technical_feasibility_score,
        $risk_compliance_score, $urgency_score,
        $strategic_alignment_weighted, $financial_viability_weighted,
        $operational_readiness_weighted, $technical_feasibility_weighted,
        $risk_compliance_weighted, $urgency_weighted,
        $total_score, $gate_decision, $decision_justification,
        $feedback_to_submitter, $recommendations, $review_notes
    );
    
    if (mysqli_stmt_execute($stmt)) {
        $evaluation_id = mysqli_insert_id($conn);
        
        // Update project intake status based on decision
        $new_status = '';
        switch($gate_decision) {
            case 'Accept': $new_status = 'Approved'; break;
            case 'Reject': $new_status = 'Rejected'; break;
            case 'Defer': $new_status = 'Deferred'; break;
            case 'Revise': $new_status = 'Under Review'; break;
            default: $new_status = 'Under Review';
        }
        
        $update_sql = "UPDATE project_intakes SET 
                      status = ?, 
                      reviewed_by = ?, 
                      review_date = NOW() 
                      WHERE id = ?";
        $update_stmt = mysqli_prepare($conn, $update_sql);
        mysqli_stmt_bind_param($update_stmt, "sii", $new_status, $user_id, $project_intake_id);
        mysqli_stmt_execute($update_stmt);
        mysqli_stmt_close($update_stmt);
        
        // Log the evaluation
        $log_sql = "INSERT INTO checkpoint_logs (project_intake_id, user_id, action, details) 
                   VALUES (?, ?, 'Evaluated', ?)";
        $log_stmt = mysqli_prepare($conn, $log_sql);
        $details = "Checkpoint evaluation completed. Score: " . number_format($total_score, 2) . "%, Decision: $gate_decision";
        mysqli_stmt_bind_param($log_stmt, "iis", $project_intake_id, $user_id, $details);
        mysqli_stmt_execute($log_stmt);
        mysqli_stmt_close($log_stmt);
        
        // Create notification for submitter
        $submitter_query = "SELECT submitted_by FROM project_intakes WHERE id = ?";
        $submitter_stmt = mysqli_prepare($conn, $submitter_query);
        mysqli_stmt_bind_param($submitter_stmt, "i", $project_intake_id);
        mysqli_stmt_execute($submitter_stmt);
        mysqli_stmt_bind_result($submitter_stmt, $submitter_id);
        mysqli_stmt_fetch($submitter_stmt);
        mysqli_stmt_close($submitter_stmt);
        
        if ($submitter_id) {
            $notif_sql = "INSERT INTO notifications (user_id, title, message, type, related_module, related_id) 
                         VALUES (?, ?, ?, ?, ?, ?)";
            $notif_stmt = mysqli_prepare($conn, $notif_sql);
            $title = "Checkpoint Evaluation Completed";
            $message = "Your project intake has been evaluated with score: " . number_format($total_score, 2) . "%. Decision: $gate_decision";
            $type = "info";
            $module = "project_intake";
            mysqli_stmt_bind_param($notif_stmt, "issssi", $submitter_id, $title, $message, $type, $module, $project_intake_id);
            mysqli_stmt_execute($notif_stmt);
            mysqli_stmt_close($notif_stmt);
        }
        
        $_SESSION['success'] = "Evaluation submitted successfully! Total Score: " . number_format($total_score, 2) . "%";
        echo '<script>window.location.href = "checkpoint_evaluations.php";</script>';
        exit();
    } else {
        $error = "Error submitting evaluation: " . mysqli_error($conn);
    }
    
    mysqli_stmt_close($stmt);
}

// Handle delete evaluation
if ($delete_evaluation > 0) {
    // Get evaluation details before deleting
    $get_eval_sql = "SELECT project_intake_id FROM checkpoint_evaluations WHERE id = ?";
    $get_eval_stmt = mysqli_prepare($conn, $get_eval_sql);
    mysqli_stmt_bind_param($get_eval_stmt, "i", $delete_evaluation);
    mysqli_stmt_execute($get_eval_stmt);
    mysqli_stmt_bind_result($get_eval_stmt, $project_intake_id);
    mysqli_stmt_fetch($get_eval_stmt);
    mysqli_stmt_close($get_eval_stmt);
    
    // Delete evaluation
    $delete_sql = "DELETE FROM checkpoint_evaluations WHERE id = ?";
    $delete_stmt = mysqli_prepare($conn, $delete_sql);
    mysqli_stmt_bind_param($delete_stmt, "i", $delete_evaluation);
    
    if (mysqli_stmt_execute($delete_stmt)) {
        // Update project intake status back to Submitted
        $update_sql = "UPDATE project_intakes SET status = 'Submitted', reviewed_by = NULL, review_date = NULL WHERE id = ?";
        $update_stmt = mysqli_prepare($conn, $update_sql);
        mysqli_stmt_bind_param($update_stmt, "i", $project_intake_id);
        mysqli_stmt_execute($update_stmt);
        mysqli_stmt_close($update_stmt);
        
        $_SESSION['success'] = "Evaluation deleted successfully!";
    } else {
        $_SESSION['error'] = "Error deleting evaluation: " . mysqli_error($conn);
    }
    
    mysqli_stmt_close($delete_stmt);
    echo '<script>window.location.href = "checkpoint_evaluations.php";</script>';
    exit();
}

// Get project intake details for view/evaluate
$project_details = null;
if ($view_id > 0 || $evaluate_id > 0) {
    $project_id = $view_id > 0 ? $view_id : $evaluate_id;
    $query = "SELECT pi.*, d.department_name, u.username as submitter_name, 
                     u.email as submitter_email
              FROM project_intakes pi 
              LEFT JOIN departments d ON pi.department_id = d.id 
              LEFT JOIN users u ON pi.submitted_by = u.id 
              WHERE pi.id = ?";
    
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $project_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $project_details = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
}

// Get all project intakes pending evaluation
$pending_intakes = [];
$pending_query = "SELECT pi.*, d.department_name, u.username as submitter_name 
                  FROM project_intakes pi 
                  LEFT JOIN departments d ON pi.department_id = d.id 
                  LEFT JOIN users u ON pi.submitted_by = u.id 
                  WHERE pi.status IN ('Submitted', 'Under Review') 
                  ORDER BY pi.submitted_date DESC";
$pending_result = mysqli_query($conn, $pending_query);
while ($row = mysqli_fetch_assoc($pending_result)) {
    $pending_intakes[] = $row;
}

// Get completed evaluations
$completed_evaluations = [];
$completed_query = "SELECT ce.*, pi.project_name, d.department_name, u.username as evaluator_name, 
                    pi.submitted_by as submitter_id, subu.username as submitter_name
                    FROM checkpoint_evaluations ce 
                    JOIN project_intakes pi ON ce.project_intake_id = pi.id 
                    LEFT JOIN departments d ON pi.department_id = d.id 
                    LEFT JOIN users u ON ce.review_board_member_id = u.id 
                    LEFT JOIN users subu ON pi.submitted_by = subu.id 
                    ORDER BY ce.gate_review_date DESC";
$completed_result = mysqli_query($conn, $completed_query);
while ($row = mysqli_fetch_assoc($completed_result)) {
    $completed_evaluations[] = $row;
}

// Get evaluation statistics
$stats = [
    'pending' => count($pending_intakes),
    'completed' => count($completed_evaluations),
    'average_score' => 0,
    'accept_count' => 0,
    'reject_count' => 0,
    'defer_count' => 0,
    'revise_count' => 0
];

if ($stats['completed'] > 0) {
    $total_score = 0;
    foreach ($completed_evaluations as $eval) {
        $total_score += $eval['total_score'];
        switch($eval['gate_decision']) {
            case 'Accept': $stats['accept_count']++; break;
            case 'Reject': $stats['reject_count']++; break;
            case 'Defer': $stats['defer_count']++; break;
            case 'Revise': $stats['revise_count']++; break;
        }
    }
    $stats['average_score'] = $total_score / $stats['completed'];
}

// Scoring criteria for reference
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
            '5' => 'Budget secured; strong, well-supported financial case with realistic ROI and measurable benefits',
            '4' => 'Budget secured; solid case with credible ROI, minor assumptions to validate',
            '3' => 'Budget secured; reasonable case but benefits moderately defined',
            '2' => 'Budget secured; weak justification, limited clarity on benefits',
            '1' => 'Budget secured; unconvincing case with unrealistic or unsubstantiated assumptions'
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
            '5' => 'Low risk, robust mitigation, full compliance with banking regulations',
            '4' => 'Low-to-moderate risk, strong mitigation',
            '3' => 'Moderate risk, partial mitigation',
            '2' => 'High risk, limited mitigation',
            '1' => 'Severe risk, inadequate mitigation or non-compliance'
        ]
    ],
    'urgency' => [
        'weight' => '10%',
        'scores' => [
            '5' => 'Immediate action required to capture critical market opportunity or meet regulatory deadline',
            '4' => 'High urgency, delay would cause significant impact',
            '3' => 'Action beneficial in near term',
            '2' => 'Some urgency, but delay has limited impact',
            '1' => 'Low urgency, minimal impact'
        ]
    ]
];
?>

<style>
    /* Modern Checkpoint Evaluations Styling */
    :root {
        --stat-card-pending: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        --stat-card-completed: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        --stat-card-average: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        --stat-card-acceptance: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
    }
    
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }
    
    .stat-card {
        background: white;
        border-radius: 12px;
        padding: 25px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        transition: all 0.3s ease;
        border: 1px solid rgba(0, 0, 0, 0.05);
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
    }
    
    .stat-card.pending::before {
        background: linear-gradient(90deg, var(--dashen-blue), var(--dashen-cyan));
    }
    
    .stat-card.completed::before {
        background: linear-gradient(90deg, #4facfe, #00f2fe);
    }
    
    .stat-card.average::before {
        background: linear-gradient(90deg, #f093fb, #f5576c);
    }
    
    .stat-card.acceptance::before {
        background: linear-gradient(90deg, #43e97b, #38f9d7);
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
    
    .stat-card.pending .stat-icon {
        background: linear-gradient(135deg, var(--dashen-blue), var(--dashen-cyan));
        color: white;
    }
    
    .stat-card.completed .stat-icon {
        background: linear-gradient(135deg, #4facfe, #00f2fe);
        color: white;
    }
    
    .stat-card.average .stat-icon {
        background: linear-gradient(135deg, #f093fb, #f5576c);
        color: white;
    }
    
    .stat-card.acceptance .stat-icon {
        background: linear-gradient(135deg, #43e97b, #38f9d7);
        color: white;
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
    
    /* Project Details View */
    .project-view-container {
        background: white;
        border-radius: 12px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        margin-bottom: 30px;
        border: 1px solid rgba(0, 0, 0, 0.05);
        overflow: hidden;
    }
    
    .project-view-header {
        background: linear-gradient(135deg, var(--dashen-blue), var(--dashen-cyan));
        color: white;
        padding: 25px 30px;
        position: relative;
        overflow: hidden;
    }
    
    .project-view-header::before {
        content: '';
        position: absolute;
        top: -50%;
        right: -50%;
        width: 200px;
        height: 200px;
        background: rgba(255, 255, 255, 0.1);
        border-radius: 50%;
    }
    
    .project-view-header::after {
        content: '';
        position: absolute;
        bottom: -30%;
        left: -30%;
        width: 150px;
        height: 150px;
        background: rgba(255, 255, 255, 0.05);
        border-radius: 50%;
    }
    
    .project-view-title {
        position: relative;
        z-index: 1;
        margin: 0;
        font-weight: 700;
        font-size: 1.75rem;
    }
    
    .project-view-subtitle {
        position: relative;
        z-index: 1;
        margin: 10px 0 0 0;
        opacity: 0.9;
        font-size: 1rem;
    }
    
    .project-view-body {
        padding: 30px;
    }
    
    .info-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }
    
    .info-section {
        background: var(--dashen-gray-50);
        border-radius: 8px;
        padding: 20px;
        border: 1px solid var(--dashen-gray-200);
    }
    
    .info-section-title {
        color: var(--dashen-blue);
        font-weight: 600;
        margin-bottom: 15px;
        font-size: 1.1rem;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .info-item {
        margin-bottom: 12px;
    }
    
    .info-label {
        font-weight: 600;
        color: var(--dashen-gray-700);
        font-size: 0.9rem;
        margin-bottom: 4px;
    }
    
    .info-value {
        color: var(--dashen-gray-900);
        font-size: 0.95rem;
        padding: 8px 12px;
        background: white;
        border-radius: 6px;
        border: 1px solid var(--dashen-gray-200);
    }
    
    .view-actions {
        display: flex;
        gap: 15px;
        padding-top: 20px;
        border-top: 1px solid var(--dashen-gray-200);
        margin-top: 20px;
    }
    
    /* Evaluation Modal Styling */
    .evaluation-modal .modal-content {
        border-radius: 16px;
        overflow: hidden;
        border: none;
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
    }
    
    .evaluation-modal .modal-header {
        background: linear-gradient(135deg, var(--dashen-blue), var(--dashen-cyan));
        color: white;
        padding: 25px 30px;
        border: none;
        position: relative;
        overflow: hidden;
    }
    
    .evaluation-modal .modal-header::before {
        content: '';
        position: absolute;
        top: -50%;
        right: -50%;
        width: 150px;
        height: 150px;
        background: rgba(255, 255, 255, 0.1);
        border-radius: 50%;
    }
    
    .evaluation-modal .modal-title {
        position: relative;
        z-index: 1;
        font-weight: 700;
        font-size: 1.5rem;
        margin: 0;
    }
    
    .evaluation-modal .modal-body {
        padding: 30px;
        max-height: 70vh;
        overflow-y: auto;
    }
    
    .project-info-card {
        background: linear-gradient(135deg, #f8f9fa, #e9ecef);
        border: 2px solid var(--dashen-gray-300);
        border-radius: 12px;
        padding: 25px;
        margin-bottom: 30px;
        position: relative;
        overflow: hidden;
    }
    
    .project-info-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 5px;
        height: 100%;
        background: linear-gradient(to bottom, var(--dashen-blue), var(--dashen-cyan));
    }
    
    .project-info-title {
        color: var(--dashen-blue);
        font-weight: 700;
        margin-bottom: 10px;
        font-size: 1.25rem;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .dimension-card {
        background: white;
        border-radius: 12px;
        padding: 25px;
        margin-bottom: 20px;
        border: 1px solid var(--dashen-gray-200);
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
    }
    
    .dimension-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
        border-color: var(--dashen-blue);
    }
    
    .dimension-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 4px;
        height: 100%;
        background: linear-gradient(to bottom, var(--dashen-blue), var(--dashen-cyan));
    }
    
    .dimension-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
    }
    
    .dimension-title {
        color: var(--dashen-gray-900);
        font-weight: 700;
        font-size: 1.1rem;
        margin: 0;
    }
    
    .dimension-weight {
        background: linear-gradient(135deg, var(--dashen-blue), var(--dashen-cyan));
        color: white;
        padding: 6px 15px;
        border-radius: 20px;
        font-weight: 600;
        font-size: 0.9rem;
    }
    
    .score-display {
        text-align: center;
        padding: 20px;
        background: linear-gradient(135deg, #f8f9fa, #e9ecef);
        border-radius: 10px;
        border: 2px solid var(--dashen-gray-300);
    }
    
    .score-value {
        font-size: 3rem;
        font-weight: 800;
        color: var(--dashen-blue);
        line-height: 1;
    }
    
    .score-weighted {
        font-size: 1.1rem;
        color: var(--dashen-gray-700);
        font-weight: 600;
        margin-top: 5px;
    }
    
    .score-threshold {
        font-size: 0.85rem;
        color: var(--dashen-gray-600);
        margin-top: 10px;
    }
    
    .total-score-card {
        background: linear-gradient(135deg, var(--dashen-blue), var(--dashen-cyan));
        color: white;
        border-radius: 12px;
        padding: 30px;
        margin: 30px 0;
        text-align: center;
        position: relative;
        overflow: hidden;
    }
    
    .total-score-card::before {
        content: '';
        position: absolute;
        top: -50%;
        right: -50%;
        width: 200px;
        height: 200px;
        background: rgba(255, 255, 255, 0.1);
        border-radius: 50%;
    }
    
    .total-score-value {
        font-size: 4rem;
        font-weight: 800;
        line-height: 1;
        margin-bottom: 10px;
        position: relative;
        z-index: 1;
    }
    
    .total-score-label {
        font-size: 1rem;
        opacity: 0.9;
        position: relative;
        z-index: 1;
    }
    
    .score-status {
        font-size: 1.1rem;
        font-weight: 600;
        margin-top: 15px;
        position: relative;
        z-index: 1;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 20px;
        border-radius: 25px;
        background: rgba(255, 255, 255, 0.2);
    }
    
    .score-status.pass {
        background: rgba(40, 167, 69, 0.2);
        color: #d4edda;
    }
    
    .score-status.fail {
        background: rgba(220, 53, 69, 0.2);
        color: #f8d7da;
    }
    
    .score-status.warning {
        background: rgba(255, 193, 7, 0.2);
        color: #fff3cd;
    }
    
    .score-select {
        border: 2px solid var(--dashen-gray-300);
        border-radius: 8px;
        padding: 12px;
        font-size: 1rem;
        transition: all 0.3s ease;
        width: 100%;
    }
    
    .score-select:focus {
        border-color: var(--dashen-blue);
        box-shadow: 0 0 0 3px rgba(0, 82, 204, 0.1);
        outline: none;
    }
    
    .score-select option {
        padding: 10px;
    }
    
    .dimension-description {
        color: var(--dashen-gray-600);
        font-size: 0.9rem;
        margin-bottom: 15px;
        line-height: 1.5;
    }
    
    .decision-card {
        background: white;
        border-radius: 12px;
        padding: 25px;
        margin-bottom: 20px;
        border: 1px solid var(--dashen-gray-200);
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
    }
    
    .decision-header {
        color: var(--dashen-blue);
        font-weight: 700;
        margin-bottom: 20px;
        font-size: 1.25rem;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .decision-options {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px;
        margin-bottom: 20px;
    }
    
    .decision-option {
        text-align: center;
        padding: 20px;
        border: 2px solid var(--dashen-gray-300);
        border-radius: 10px;
        cursor: pointer;
        transition: all 0.3s ease;
        background: white;
    }
    
    .decision-option:hover {
        border-color: var(--dashen-blue);
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
    }
    
    .decision-option.selected {
        border-color: var(--dashen-blue);
        background: linear-gradient(135deg, rgba(0, 82, 204, 0.1), rgba(0, 82, 204, 0.05));
        box-shadow: 0 5px 15px rgba(0, 82, 204, 0.2);
    }
    
    .decision-option.accept.selected {
        border-color: #28a745;
        background: linear-gradient(135deg, rgba(40, 167, 69, 0.1), rgba(40, 167, 69, 0.05));
    }
    
    .decision-option.reject.selected {
        border-color: #dc3545;
        background: linear-gradient(135deg, rgba(220, 53, 69, 0.1), rgba(220, 53, 69, 0.05));
    }
    
    .decision-option.defer.selected {
        border-color: #17a2b8;
        background: linear-gradient(135deg, rgba(23, 162, 184, 0.1), rgba(23, 162, 184, 0.05));
    }
    
    .decision-option.revise.selected {
        border-color: #ffc107;
        background: linear-gradient(135deg, rgba(255, 193, 7, 0.1), rgba(255, 193, 7, 0.05));
    }
    
    .decision-icon {
        font-size: 2.5rem;
        margin-bottom: 10px;
    }
    
    .decision-label {
        font-weight: 600;
        margin-bottom: 5px;
        font-size: 1.1rem;
    }
    
    .decision-desc {
        font-size: 0.85rem;
        color: var(--dashen-gray-600);
    }
    
    .form-textarea {
        border: 2px solid var(--dashen-gray-300);
        border-radius: 8px;
        padding: 15px;
        font-size: 0.95rem;
        transition: all 0.3s ease;
        width: 100%;
        min-height: 120px;
        resize: vertical;
    }
    
    .form-textarea:focus {
        border-color: var(--dashen-blue);
        box-shadow: 0 0 0 3px rgba(0, 82, 204, 0.1);
        outline: none;
    }
    
    .form-label {
        font-weight: 600;
        color: var(--dashen-gray-800);
        margin-bottom: 8px;
        display: block;
    }
    
    .required::after {
        content: " *";
        color: var(--dashen-red);
    }
    
    .modal-actions {
        display: flex;
        gap: 15px;
        justify-content: flex-end;
        padding-top: 20px;
        border-top: 1px solid var(--dashen-gray-200);
        margin-top: 20px;
    }
    
    /* Tables Styling */
    .table-container {
        background: white;
        border-radius: 12px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        margin-bottom: 30px;
        border: 1px solid rgba(0, 0, 0, 0.05);
        overflow: hidden;
    }
    
    .table-header {
        padding: 25px 30px;
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
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .empty-state {
        text-align: center;
        padding: 60px 20px;
    }
    
    .empty-icon {
        font-size: 4rem;
        color: var(--dashen-gray-300);
        margin-bottom: 20px;
    }
    
    .empty-title {
        font-size: 1.5rem;
        color: var(--dashen-gray-600);
        margin-bottom: 10px;
        font-weight: 600;
    }
    
    .empty-message {
        color: var(--dashen-gray-500);
        margin-bottom: 30px;
        max-width: 500px;
        margin-left: auto;
        margin-right: auto;
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
    
    .intake-table tr:last-child td {
        border-bottom: none;
    }
    
    .ref-id {
        font-family: 'Monaco', 'Courier New', monospace;
        font-weight: 600;
        color: var(--dashen-blue);
    }
    
    .project-name {
        font-weight: 600;
        color: var(--dashen-gray-900);
        max-width: 250px;
    }
    
    .project-description {
        font-size: 0.9rem;
        color: var(--dashen-gray-600);
        margin-top: 4px;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }
    
    .status-badge {
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .status-pending {
        background: linear-gradient(135deg, #ffecd2, #fcb69f);
        color: #e65100;
    }
    
    .status-submitted {
        background: linear-gradient(135deg, #a1c4fd, #c2e9fb);
        color: #1565c0;
    }
    
    .budget-amount {
        font-weight: 600;
        color: var(--dashen-gray-900);
        font-family: 'Monaco', 'Courier New', monospace;
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
        background: linear-gradient(135deg, #d4edda, #c3e6cb);
        color: #155724;
    }
    
    .risk-medium {
        background: linear-gradient(135deg, #fff3cd, #ffeaa7);
        color: #856404;
    }
    
    .risk-high {
        background: linear-gradient(135deg, #f8d7da, #f5c6cb);
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
        position: relative;
    }
    
    .action-btn-sm:hover {
        background: var(--dashen-gray-100);
        color: var(--dashen-blue);
        transform: translateY(-1px);
    }
    
    .action-btn-sm.view:hover {
        background: rgba(0, 82, 204, 0.1);
        color: var(--dashen-blue);
    }
    
    .action-btn-sm.evaluate:hover {
        background: rgba(40, 167, 69, 0.1);
        color: #28a745;
    }
    
    .action-btn-sm.delete:hover {
        background: rgba(220, 53, 69, 0.1);
        color: #dc3545;
    }
    
    .action-tooltip {
        position: absolute;
        bottom: 100%;
        left: 50%;
        transform: translateX(-50%);
        background: var(--dashen-gray-900);
        color: white;
        padding: 6px 12px;
        border-radius: 6px;
        font-size: 0.8rem;
        white-space: nowrap;
        opacity: 0;
        visibility: hidden;
        transition: all 0.2s ease;
        margin-bottom: 8px;
        z-index: 100;
    }
    
    .action-tooltip::after {
        content: '';
        position: absolute;
        top: 100%;
        left: 50%;
        transform: translateX(-50%);
        border: 6px solid transparent;
        border-top-color: var(--dashen-gray-900);
    }
    
    .action-btn-sm:hover .action-tooltip {
        opacity: 1;
        visibility: visible;
    }
    
    .score-bar-container {
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .score-bar {
        flex: 1;
        height: 20px;
        background: var(--dashen-gray-200);
        border-radius: 10px;
        overflow: hidden;
        position: relative;
    }
    
    .score-fill {
        height: 100%;
        border-radius: 10px;
        position: relative;
        transition: width 0.3s ease;
    }
    
    .score-fill::after {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: linear-gradient(90deg, 
            transparent 0%, 
            rgba(255, 255, 255, 0.4) 50%, 
            transparent 100%);
        animation: shimmer 2s infinite;
    }
    
    .score-text {
        font-weight: 600;
        font-size: 0.9rem;
        min-width: 45px;
        text-align: right;
    }
    
    @keyframes shimmer {
        0% { transform: translateX(-100%); }
        100% { transform: translateX(100%); }
    }
    
    .decision-badge {
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .decision-accept {
        background: linear-gradient(135deg, #c2e9fb, #a1c4fd);
        color: #00695c;
    }
    
    .decision-reject {
        background: linear-gradient(135deg, #ff9a9e, #fecfef);
        color: #c62828;
    }
    
    .decision-defer {
        background: linear-gradient(135deg, #d4fc79, #96e6a1);
        color: #2e7d32;
    }
    
    .decision-revise {
        background: linear-gradient(135deg, #ffecd2, #fcb69f);
        color: #e65100;
    }
    
    @media (max-width: 768px) {
        .stats-grid {
            grid-template-columns: 1fr;
        }
        
        .info-grid {
            grid-template-columns: 1fr;
        }
        
        .table-header {
            flex-direction: column;
            align-items: stretch;
        }
        
        .intake-table {
            display: block;
            overflow-x: auto;
        }
        
        .dimension-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 10px;
        }
        
        .decision-options {
            grid-template-columns: 1fr;
        }
        
        .modal-actions {
            flex-direction: column;
        }
    }
</style>

<div class="content-wrapper">
    <!-- Messages -->
    <?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <div class="d-flex align-items-center">
            <i class="fas fa-check-circle me-3" style="font-size: 1.5rem;"></i>
            <div>
                <h5 class="mb-1">Success!</h5>
                <p class="mb-0"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></p>
            </div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <div class="d-flex align-items-center">
            <i class="fas fa-exclamation-circle me-3" style="font-size: 1.5rem;"></i>
            <div>
                <h5 class="mb-1">Error!</h5>
                <p class="mb-0"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></p>
            </div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    
    <?php if (isset($error)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <div class="d-flex align-items-center">
            <i class="fas fa-exclamation-circle me-3" style="font-size: 1.5rem;"></i>
            <div>
                <h5 class="mb-1">Error!</h5>
                <p class="mb-0"><?php echo $error; ?></p>
            </div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    
    <!-- Project Details View -->
    <?php if ($view_id > 0 && $project_details): ?>
    <div class="project-view-container">
        <div class="project-view-header">
            <h3 class="project-view-title">
                <i class="fas fa-file-alt me-2"></i>
                <?php echo htmlspecialchars($project_details['project_name']); ?>
            </h3>
            <p class="project-view-subtitle">
                Project Intake Reference: PI-<?php echo str_pad($project_details['id'], 6, '0', STR_PAD_LEFT); ?>
            </p>
        </div>
        
        <div class="project-view-body">
            <div class="info-grid">
                <div class="info-section">
                    <h5 class="info-section-title"><i class="fas fa-info-circle"></i> Basic Information</h5>
                    <div class="info-item">
                        <div class="info-label">Project Name</div>
                        <div class="info-value"><?php echo htmlspecialchars($project_details['project_name']); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Department</div>
                        <div class="info-value"><?php echo htmlspecialchars($project_details['department_name'] ?? 'N/A'); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Submitted By</div>
                        <div class="info-value"><?php echo htmlspecialchars($project_details['submitter_name']); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Submission Date</div>
                        <div class="info-value"><?php echo date('F j, Y, g:i a', strtotime($project_details['submitted_date'] ?? $project_details['created_at'])); ?></div>
                    </div>
                </div>
                
                <div class="info-section">
                    <h5 class="info-section-title"><i class="fas fa-bullseye"></i> Strategic Alignment</h5>
                    <div class="info-item">
                        <div class="info-label">Business Challenge</div>
                        <div class="info-value"><?php echo htmlspecialchars($project_details['business_challenge'] ?? 'Not specified'); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Strategic Goals</div>
                        <div class="info-value"><?php echo htmlspecialchars($project_details['strategic_goals'] ?? 'Not specified'); ?></div>
                    </div>
                </div>
                
                <div class="info-section">
                    <h5 class="info-section-title"><i class="fas fa-server"></i> System Information</h5>
                    <div class="info-item">
                        <div class="info-label">Proposed System</div>
                        <div class="info-value"><?php echo htmlspecialchars($project_details['proposed_system_name'] ?? 'Not specified'); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Business Capability</div>
                        <div class="info-value"><?php echo htmlspecialchars($project_details['primary_business_capability'] ?? 'Not specified'); ?></div>
                    </div>
                </div>
                
                <div class="info-section">
                    <h5 class="info-section-title"><i class="fas fa-money-bill-wave"></i> Financial Information</h5>
                    <div class="info-item">
                        <div class="info-label">Estimated Budget</div>
                        <div class="info-value">
                            <?php echo $project_details['estimated_total_budget'] ? 
                                  '$' . number_format($project_details['estimated_total_budget'], 2) : 'Not specified'; ?>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Budget Approval</div>
                        <div class="info-value"><?php echo $project_details['budget_approval_obtained'] ?? 'Not specified'; ?></div>
                    </div>
                </div>
                
                <div class="info-section">
                    <h5 class="info-section-title"><i class="fas fa-exclamation-triangle"></i> Risk Assessment</h5>
                    <div class="info-item">
                        <div class="info-label">Overall Risk Rating</div>
                        <div class="info-value">
                            <span class="risk-badge risk-<?php echo strtolower($project_details['overall_risk_rating'] ?? 'low'); ?>">
                                <i class="fas fa-<?php 
                                    echo ($project_details['overall_risk_rating'] ?? 'low') == 'high' ? 'exclamation-triangle' : 
                                         (($project_details['overall_risk_rating'] ?? 'low') == 'medium' ? 'exclamation-circle' : 'check-circle'); 
                                ?>"></i>
                                <?php echo $project_details['overall_risk_rating'] ?? 'Low'; ?>
                            </span>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Cybersecurity Concerns</div>
                        <div class="info-value"><?php echo htmlspecialchars($project_details['cybersecurity_concerns'] ?? 'None identified'); ?></div>
                    </div>
                </div>
                
                <div class="info-section">
                    <h5 class="info-section-title"><i class="fas fa-rocket"></i> Readiness Assessment</h5>
                    <div class="info-item">
                        <div class="info-label">Team Readiness</div>
                        <div class="info-value"><?php echo $project_details['team_ready_for_assessment'] ?? 'Not specified'; ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Internal Resources</div>
                        <div class="info-value"><?php echo htmlspecialchars($project_details['internal_resources_required'] ?? 'Not specified'); ?></div>
                    </div>
                </div>
            </div>
            
            <div class="view-actions">
                <a href="checkpoint_evaluations.php" class="action-btn outline">
                    <i class="fas fa-arrow-left me-1"></i> Back to Evaluations
                </a>
                <a href="checkpoint_evaluations.php?evaluate=<?php echo $view_id; ?>" class="action-btn primary">
                    <i class="fas fa-clipboard-check me-1"></i> Evaluate This Project
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Main Dashboard View (when not viewing details) -->
    <?php if ($view_id == 0 && $evaluate_id == 0): ?>
    <!-- Statistics Cards -->
    <div class="stats-grid">
        <div class="stat-card pending">
            <div class="stat-icon">
                <i class="fas fa-clock"></i>
            </div>
            <div class="stat-value"><?php echo $stats['pending']; ?></div>
            <div class="stat-label">Pending Evaluation</div>
            <div class="stat-trend">
                <?php if ($stats['pending'] > 0): ?>
                <span class="text-warning"><i class="fas fa-exclamation-circle me-1"></i>Requires attention</span>
                <?php else: ?>
                <span class="text-success"><i class="fas fa-check-circle me-1"></i>All caught up</span>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="stat-card completed">
            <div class="stat-icon">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="stat-value"><?php echo $stats['completed']; ?></div>
            <div class="stat-label">Completed Evaluations</div>
            <div class="stat-trend">
                <span class="text-success"><?php echo $stats['completed']; ?> total reviews</span>
            </div>
        </div>
        
        <div class="stat-card average">
            <div class="stat-icon">
                <i class="fas fa-chart-line"></i>
            </div>
            <div class="stat-value"><?php echo number_format($stats['average_score'], 1); ?>%</div>
            <div class="stat-label">Average Score</div>
            <div class="stat-trend">
                <?php if ($stats['average_score'] >= 70): ?>
                <span class="text-success"><i class="fas fa-thumbs-up me-1"></i>Above threshold</span>
                <?php else: ?>
                <span class="text-warning"><i class="fas fa-exclamation-triangle me-1"></i>Below threshold</span>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="stat-card acceptance">
            <div class="stat-icon">
                <i class="fas fa-thumbs-up"></i>
            </div>
            <div class="stat-value">
                <?php echo $stats['completed'] > 0 ? 
                      number_format(($stats['accept_count'] / $stats['completed']) * 100, 1) : 0; ?>%
            </div>
            <div class="stat-label">Acceptance Rate</div>
            <div class="stat-trend">
                <span class="text-info">
                    <?php echo $stats['accept_count']; ?> accepted / <?php echo $stats['completed']; ?> total
                </span>
            </div>
        </div>
    </div>
    
    <!-- Pending Evaluations Section -->
    <div class="table-container">
        <div class="table-header">
            <h3 class="table-title"><i class="fas fa-clock me-2"></i>Pending Evaluations</h3>
            <button type="button" class="action-btn outline" id="showScoringCriteria">
                <i class="fas fa-question-circle me-1"></i> View Scoring Criteria
            </button>
        </div>
        
        <?php if (empty($pending_intakes)): ?>
        <div class="empty-state">
            <div class="empty-icon">
                <i class="fas fa-check-circle"></i>
            </div>
            <h3 class="empty-title">All Caught Up!</h3>
            <p class="empty-message">No pending evaluations at this time. All submitted projects have been reviewed.</p>
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
                    <?php foreach ($pending_intakes as $intake): ?>
                    <tr>
                        <td>
                            <span class="ref-id">PI-<?php echo str_pad($intake['id'], 6, '0', STR_PAD_LEFT); ?></span>
                        </td>
                        <td>
                            <div class="project-name"><?php echo htmlspecialchars($intake['project_name']); ?></div>
                            <div class="project-description">
                                <?php echo htmlspecialchars(substr($intake['business_challenge'] ?? '', 0, 100)); ?>
                                <?php echo strlen($intake['business_challenge'] ?? '') > 100 ? '...' : ''; ?>
                            </div>
                        </td>
                        <td><?php echo htmlspecialchars($intake['department_name'] ?? 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars($intake['submitter_name']); ?></td>
                        <td><?php echo date('M d, Y', strtotime($intake['submitted_date'] ?? $intake['created_at'])); ?></td>
                        <td>
                            <?php if ($intake['estimated_total_budget']): ?>
                            <span class="budget-amount">$<?php echo number_format($intake['estimated_total_budget'], 2); ?></span>
                            <?php else: ?>
                            <span class="text-muted">Not specified</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="risk-badge risk-<?php echo strtolower($intake['overall_risk_rating'] ?? 'low'); ?>">
                                <i class="fas fa-<?php 
                                    echo ($intake['overall_risk_rating'] ?? 'low') == 'high' ? 'exclamation-triangle' : 
                                         (($intake['overall_risk_rating'] ?? 'low') == 'medium' ? 'exclamation-circle' : 'check-circle'); 
                                ?>"></i>
                                <?php echo $intake['overall_risk_rating'] ?? 'Low'; ?>
                            </span>
                        </td>
                        <td>
                            <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $intake['status'])); ?>">
                                <i class="fas fa-<?php 
                                    switch($intake['status']) {
                                        case 'Draft': echo 'pencil-alt'; break;
                                        case 'Submitted': echo 'paper-plane'; break;
                                        case 'Under Review': echo 'search'; break;
                                        default: echo 'circle';
                                    }
                                ?>"></i>
                                <?php echo $intake['status']; ?>
                            </span>
                        </td>
                        <td>
                            <div class="action-menu">
                                <a href="checkpoint_evaluations.php?view=<?php echo $intake['id']; ?>" 
                                   class="action-btn-sm view" title="View Details">
                                    <i class="fas fa-eye"></i>
                                    <span class="action-tooltip">View Details</span>
                                </a>
                                <a href="checkpoint_evaluations.php?evaluate=<?php echo $intake['id']; ?>" 
                                   class="action-btn-sm evaluate" title="Evaluate">
                                    <i class="fas fa-clipboard-check"></i>
                                    <span class="action-tooltip">Evaluate</span>
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Completed Evaluations Section -->
    <div class="table-container">
        <div class="table-header">
            <h3 class="table-title"><i class="fas fa-history me-2"></i>Evaluation History</h3>
        </div>
        
        <?php if (empty($completed_evaluations)): ?>
        <div class="empty-state">
            <div class="empty-icon">
                <i class="fas fa-clipboard-list"></i>
            </div>
            <h3 class="empty-title">No Evaluation History</h3>
            <p class="empty-message">Complete your first evaluation to see history here.</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="intake-table">
                <thead>
                    <tr>
                        <th>Project</th>
                        <th>Evaluator</th>
                        <th>Date</th>
                        <th>Dimensions</th>
                        <th>Score</th>
                        <th>Decision</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($completed_evaluations as $eval): 
                        $score_class = $eval['total_score'] >= 70 ? 'text-success' : 
                                     ($eval['total_score'] >= 50 ? 'text-warning' : 'text-danger');
                        $decision_class = strtolower($eval['gate_decision']);
                    ?>
                    <tr>
                        <td>
                            <div class="project-name"><?php echo htmlspecialchars($eval['project_name']); ?></div>
                            <div class="project-description">
                                Ref: PI-<?php echo str_pad($eval['project_intake_id'], 6, '0', STR_PAD_LEFT); ?>
                            </div>
                        </td>
                        <td><?php echo htmlspecialchars($eval['evaluator_name']); ?></td>
                        <td><?php echo date('M d, Y', strtotime($eval['gate_review_date'])); ?></td>
                        <td>
                            <div class="d-flex gap-1 flex-wrap">
                                <span class="badge bg-light text-dark" title="Strategic Alignment">S:<?php echo $eval['strategic_alignment_score']; ?></span>
                                <span class="badge bg-light text-dark" title="Financial Viability">F:<?php echo $eval['financial_viability_score']; ?></span>
                                <span class="badge bg-light text-dark" title="Operational Readiness">O:<?php echo $eval['operational_readiness_score']; ?></span>
                                <span class="badge bg-light text-dark" title="Technical Feasibility">T:<?php echo $eval['technical_feasibility_score']; ?></span>
                                <span class="badge bg-light text-dark" title="Risk & Compliance">R:<?php echo $eval['risk_compliance_score']; ?></span>
                                <span class="badge bg-light text-dark" title="Urgency">U:<?php echo $eval['urgency_score']; ?></span>
                            </div>
                        </td>
                        <td>
                            <div class="score-bar-container">
                                <div class="score-bar">
                                    <div class="score-fill bg-<?php echo $eval['total_score'] >= 70 ? 'success' : 
                                                              ($eval['total_score'] >= 50 ? 'warning' : 'danger'); ?>" 
                                         style="width: <?php echo min($eval['total_score'], 100); ?>%">
                                    </div>
                                </div>
                                <div class="score-text <?php echo $score_class; ?>">
                                    <?php echo number_format($eval['total_score'], 1); ?>%
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="decision-badge decision-<?php echo $decision_class; ?>">
                                <i class="fas fa-<?php 
                                    switch($eval['gate_decision']) {
                                        case 'Accept': echo 'check'; break;
                                        case 'Reject': echo 'times'; break;
                                        case 'Defer': echo 'pause'; break;
                                        case 'Revise': echo 'edit'; break;
                                        default: echo 'circle';
                                    }
                                ?>"></i>
                                <?php echo $eval['gate_decision']; ?>
                            </span>
                        </td>
                        <td>
                            <div class="action-menu">
                                <a href="checkpoint_evaluations.php?view=<?php echo $eval['project_intake_id']; ?>" 
                                   class="action-btn-sm view" title="View Project">
                                    <i class="fas fa-eye"></i>
                                    <span class="action-tooltip">View Project</span>
                                </a>
                                <?php if (has_role(['super_admin'])): ?>
                                <a href="checkpoint_evaluations.php?delete_evaluation=<?php echo $eval['id']; ?>" 
                                   class="action-btn-sm delete" 
                                   title="Delete Evaluation"
                                   onclick="return confirmDeleteEvaluation(<?php echo $eval['id']; ?>, '<?php echo addslashes($eval['project_name']); ?>');">
                                    <i class="fas fa-trash"></i>
                                    <span class="action-tooltip">Delete Evaluation</span>
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
    <?php endif; ?>
</div>

<!-- Evaluation Modal (if evaluate_id is set) -->
<?php if ($evaluate_id > 0 && $project_details): ?>
<div class="modal fade evaluation-modal show" tabindex="-1" style="display: block; background: rgba(0,0,0,0.5);">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="" id="evaluationForm">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-clipboard-check me-2"></i>
                        Checkpoint Evaluation
                    </h5>
                    <a href="checkpoint_evaluations.php" class="btn-close" aria-label="Close"></a>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="project_intake_id" value="<?php echo $evaluate_id; ?>">
                    <input type="hidden" name="submit_evaluation" value="1">
                    
                    <!-- Project Information -->
                    <div class="project-info-card">
                        <h4 class="project-info-title">
                            <i class="fas fa-file-alt"></i>
                            Project Information
                        </h4>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="info-item">
                                    <div class="info-label">Project Name</div>
                                    <div class="info-value"><?php echo htmlspecialchars($project_details['project_name']); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Reference ID</div>
                                    <div class="info-value">PI-<?php echo str_pad($project_details['id'], 6, '0', STR_PAD_LEFT); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Submitted By</div>
                                    <div class="info-value"><?php echo htmlspecialchars($project_details['submitter_name']); ?></div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="info-item">
                                    <div class="info-label">Department</div>
                                    <div class="info-value"><?php echo htmlspecialchars($project_details['department_name'] ?? 'N/A'); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Budget</div>
                                    <div class="info-value">
                                        <?php echo $project_details['estimated_total_budget'] ? 
                                              '$' . number_format($project_details['estimated_total_budget'], 2) : 'Not specified'; ?>
                                    </div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Risk Rating</div>
                                    <div class="info-value">
                                        <span class="risk-badge risk-<?php echo strtolower($project_details['overall_risk_rating'] ?? 'low'); ?>">
                                            <?php echo $project_details['overall_risk_rating'] ?? 'Low'; ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Scoring Matrix -->
                    <h4 class="border-bottom pb-2 mb-4"><i class="fas fa-table me-2"></i>Scoring Matrix</h4>
                    
                    <!-- Strategic Alignment (25%) -->
                    <div class="dimension-card">
                        <div class="dimension-header">
                            <h5 class="dimension-title">Strategic Alignment</h5>
                            <span class="dimension-weight">25% Weight</span>
                        </div>
                        <p class="dimension-description">
                            Degree to which the initiative supports approved strategic priorities
                        </p>
                        <div class="row">
                            <div class="col-md-8">
                                <label class="form-label required">Select Score (1-5)</label>
                                <select class="score-select" name="strategic_alignment_score" data-weight="0.25" required>
                                    <option value="">Select Score</option>
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <option value="<?php echo $i; ?>">
                                        <?php echo $i; ?> - <?php echo $scoring_criteria['strategic_alignment']['scores'][$i]; ?>
                                    </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <div class="score-display">
                                    <div class="score-value" id="strategicScore">0</div>
                                    <div class="score-weighted" id="strategicWeighted">0.00%</div>
                                    <div class="score-threshold">Max: 25.00%</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Financial Viability (20%) -->
                    <div class="dimension-card">
                        <div class="dimension-header">
                            <h5 class="dimension-title">Financial Viability</h5>
                            <span class="dimension-weight">20% Weight</span>
                        </div>
                        <p class="dimension-description">
                            Strength of business case, cost realism, funding availability, and projected ROI
                        </p>
                        <div class="row">
                            <div class="col-md-8">
                                <label class="form-label required">Select Score (1-5)</label>
                                <select class="score-select" name="financial_viability_score" data-weight="0.20" required>
                                    <option value="">Select Score</option>
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <option value="<?php echo $i; ?>">
                                        <?php echo $i; ?> - <?php echo $scoring_criteria['financial_viability']['scores'][$i]; ?>
                                    </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <div class="score-display">
                                    <div class="score-value" id="financialScore">0</div>
                                    <div class="score-weighted" id="financialWeighted">0.00%</div>
                                    <div class="score-threshold">Max: 20.00%</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Operational Readiness (15%) -->
                    <div class="dimension-card">
                        <div class="dimension-header">
                            <h5 class="dimension-title">Operational Readiness</h5>
                            <span class="dimension-weight">15% Weight</span>
                        </div>
                        <p class="dimension-description">
                            Availability of internal capacity, skills, and infrastructure
                        </p>
                        <div class="row">
                            <div class="col-md-8">
                                <label class="form-label required">Select Score (1-5)</label>
                                <select class="score-select" name="operational_readiness_score" data-weight="0.15" required>
                                    <option value="">Select Score</option>
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <option value="<?php echo $i; ?>">
                                        <?php echo $i; ?> - <?php echo $scoring_criteria['operational_readiness']['scores'][$i]; ?>
                                    </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <div class="score-display">
                                    <div class="score-value" id="operationalScore">0</div>
                                    <div class="score-weighted" id="operationalWeighted">0.00%</div>
                                    <div class="score-threshold">Max: 15.00%</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Technical Feasibility (15%) -->
                    <div class="dimension-card">
                        <div class="dimension-header">
                            <h5 class="dimension-title">Technical Feasibility</h5>
                            <span class="dimension-weight">15% Weight</span>
                        </div>
                        <p class="dimension-description">
                            Practicality and reliability of proposed technical solution
                        </p>
                        <div class="row">
                            <div class="col-md-8">
                                <label class="form-label required">Select Score (1-5)</label>
                                <select class="score-select" name="technical_feasibility_score" data-weight="0.15" required>
                                    <option value="">Select Score</option>
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <option value="<?php echo $i; ?>">
                                        <?php echo $i; ?> - <?php echo $scoring_criteria['technical_feasibility']['scores'][$i]; ?>
                                    </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <div class="score-display">
                                    <div class="score-value" id="technicalScore">0</div>
                                    <div class="score-weighted" id="technicalWeighted">0.00%</div>
                                    <div class="score-threshold">Max: 15.00%</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Risk & Compliance (15%) -->
                    <div class="dimension-card">
                        <div class="dimension-header">
                            <h5 class="dimension-title">Risk & Compliance</h5>
                            <span class="dimension-weight">15% Weight</span>
                        </div>
                        <p class="dimension-description">
                            Identification, assessment, and mitigation of operational, legal, and regulatory risks
                        </p>
                        <div class="row">
                            <div class="col-md-8">
                                <label class="form-label required">Select Score (1-5)</label>
                                <select class="score-select" name="risk_compliance_score" data-weight="0.15" required>
                                    <option value="">Select Score</option>
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <option value="<?php echo $i; ?>">
                                        <?php echo $i; ?> - <?php echo $scoring_criteria['risk_compliance']['scores'][$i]; ?>
                                    </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <div class="score-display">
                                    <div class="score-value" id="riskScore">0</div>
                                    <div class="score-weighted" id="riskWeighted">0.00%</div>
                                    <div class="score-threshold">Max: 15.00%</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Urgency (10%) -->
                    <div class="dimension-card">
                        <div class="dimension-header">
                            <h5 class="dimension-title">Urgency / Time Sensitivity</h5>
                            <span class="dimension-weight">10% Weight</span>
                        </div>
                        <p class="dimension-description">
                            Time sensitivity and potential impact of delay on organizational objectives
                        </p>
                        <div class="row">
                            <div class="col-md-8">
                                <label class="form-label required">Select Score (1-5)</label>
                                <select class="score-select" name="urgency_score" data-weight="0.10" required>
                                    <option value="">Select Score</option>
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <option value="<?php echo $i; ?>">
                                        <?php echo $i; ?> - <?php echo $scoring_criteria['urgency']['scores'][$i]; ?>
                                    </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <div class="score-display">
                                    <div class="score-value" id="urgencyScore">0</div>
                                    <div class="score-weighted" id="urgencyWeighted">0.00%</div>
                                    <div class="score-threshold">Max: 10.00%</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Total Score Summary -->
                    <div class="total-score-card">
                        <div class="total-score-value" id="totalScore">0.00%</div>
                        <div class="total-score-label">Total Score</div>
                        <div class="score-status" id="scoreStatus">Not Calculated</div>
                    </div>
                    
                    <!-- Decision Section -->
                    <div class="decision-card">
                        <h5 class="decision-header"><i class="fas fa-gavel me-2"></i>Gate Decision</h5>
                        
                        <input type="hidden" name="gate_decision" id="gateDecisionInput" required>
                        
                        <div class="decision-options">
                            <div class="decision-option accept" data-decision="Accept">
                                <div class="decision-icon">
                                    <i class="fas fa-check-circle text-success"></i>
                                </div>
                                <div class="decision-label">Accept</div>
                                <div class="decision-desc">Score ≥ 70%</div>
                            </div>
                            
                            <div class="decision-option revise" data-decision="Revise">
                                <div class="decision-icon">
                                    <i class="fas fa-edit text-warning"></i>
                                </div>
                                <div class="decision-label">Revise</div>
                                <div class="decision-desc">Needs modifications</div>
                            </div>
                            
                            <div class="decision-option reject" data-decision="Reject">
                                <div class="decision-icon">
                                    <i class="fas fa-times-circle text-danger"></i>
                                </div>
                                <div class="decision-label">Reject</div>
                                <div class="decision-desc">Does not meet criteria</div>
                            </div>
                            
                            <div class="decision-option defer" data-decision="Defer">
                                <div class="decision-icon">
                                    <i class="fas fa-pause-circle text-info"></i>
                                </div>
                                <div class="decision-label">Defer</div>
                                <div class="decision-desc">Review at later date</div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label required">Decision Justification</label>
                            <textarea class="form-textarea" name="decision_justification" required
                                      placeholder="Provide detailed justification for your decision..."></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Feedback to Submitter</label>
                            <textarea class="form-textarea" name="feedback_to_submitter"
                                      placeholder="Provide constructive feedback for the submitting team..."></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Recommendations</label>
                            <textarea class="form-textarea" name="recommendations"
                                      placeholder="Provide recommendations for improvement or next steps..."></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Review Notes</label>
                            <textarea class="form-textarea" name="review_notes" rows="2"
                                      placeholder="Additional notes for internal review..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-actions">
                    <a href="checkpoint_evaluations.php" class="action-btn secondary">
                        <i class="fas fa-times me-1"></i> Cancel
                    </a>
                    <button type="submit" class="action-btn primary" id="submitEvaluationBtn">
                        <i class="fas fa-paper-plane me-1"></i> Submit Evaluation
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Scoring Criteria Modal -->
<div class="modal fade" id="scoringCriteriaModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="fas fa-table me-2"></i>Scoring Criteria Reference</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="table-responsive">
                    <table class="table table-bordered">
                        <thead class="table-dark">
                            <tr>
                                <th>Dimension</th>
                                <th>Weight</th>
                                <th>Score 5 (Excellent)</th>
                                <th>Score 4 (Good)</th>
                                <th>Score 3 (Moderate)</th>
                                <th>Score 2 (Poor)</th>
                                <th>Score 1 (Unacceptable)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($scoring_criteria as $dimension => $criteria): ?>
                            <tr>
                                <td><strong><?php echo ucfirst(str_replace('_', ' ', $dimension)); ?></strong></td>
                                <td><span class="badge bg-primary"><?php echo $criteria['weight']; ?></span></td>
                                <?php for ($i = 5; $i >= 1; $i--): ?>
                                <td><small><?php echo $criteria['scores'][$i]; ?></small></td>
                                <?php endfor; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="alert alert-warning">
                    <h6><i class="fas fa-exclamation-triangle me-2"></i>Scoring Methodology</h6>
                    <p class="mb-0">
                        <strong>Threshold: 70%</strong> - Projects must score ≥70% to proceed to Gate Review.<br>
                        <strong>Calculation:</strong> (Score × Weight) for each dimension, then sum all weighted scores.
                    </p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
    $(document).ready(function() {
        // Show scoring criteria modal
        $('#showScoringCriteria').click(function() {
            new bootstrap.Modal(document.getElementById('scoringCriteriaModal')).show();
        });
        
        // Score calculation for evaluation form
        function updateScores() {
            let totalScore = 0;
            let allScoresSelected = true;
            
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
                
                // Check if score is selected
                if (!score) allScoresSelected = false;
            });
            
            // Update total score
            $('#totalScore').text(totalScore.toFixed(2) + '%');
            
            // Update score status
            const scoreStatus = $('#scoreStatus');
            scoreStatus.removeClass('pass fail warning');
            
            if (totalScore >= 70) {
                scoreStatus.addClass('pass');
                scoreStatus.html('<i class="fas fa-check-circle me-1"></i>Meets threshold (≥70%) - Eligible for Acceptance');
            } else if (totalScore >= 50) {
                scoreStatus.addClass('warning');
                scoreStatus.html('<i class="fas fa-exclamation-triangle me-1"></i>Below threshold - Consider Revise or Reject');
            } else {
                scoreStatus.addClass('fail');
                scoreStatus.html('<i class="fas fa-times-circle me-1"></i>Significantly below threshold - Consider Reject');
            }
            
            // Auto-select decision option based on score
            if (allScoresSelected) {
                if (totalScore >= 70) {
                    selectDecisionOption('Accept');
                } else if (totalScore >= 50) {
                    selectDecisionOption('Revise');
                } else {
                    selectDecisionOption('Reject');
                }
            }
        }
        
        // Decision option selection
        function selectDecisionOption(decision) {
            $('.decision-option').removeClass('selected');
            $(`.decision-option.${decision.toLowerCase()}`).addClass('selected');
            $('#gateDecisionInput').val(decision);
        }
        
        // Initialize decision option click handlers
        $('.decision-option').click(function() {
            const decision = $(this).data('decision');
            selectDecisionOption(decision);
        });
        
        // Initialize score calculation
        $('.score-select').change(updateScores);
        
        // Form submission validation
        $('#evaluationForm').submit(function(e) {
            // Check if all scores are selected
            let allScoresSelected = true;
            $('.score-select').each(function() {
                if (!$(this).val()) {
                    allScoresSelected = false;
                    $(this).addClass('is-invalid');
                } else {
                    $(this).removeClass('is-invalid');
                }
            });
            
            // Check if decision is selected
            if (!$('#gateDecisionInput').val()) {
                alert('Please select a gate decision.');
                e.preventDefault();
                return false;
            }
            
            // Check if justification is provided
            if (!$('textarea[name="decision_justification"]').val().trim()) {
                alert('Please provide decision justification.');
                e.preventDefault();
                return false;
            }
            
            if (!allScoresSelected) {
                alert('Please select scores for all dimensions.');
                e.preventDefault();
                return false;
            }
            
            // Show loading state
            $('#submitEvaluationBtn').prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i>Submitting...');
            
            return true;
        });
        
        // Confirm delete evaluation
        window.confirmDeleteEvaluation = function(id, projectName) {
            Swal.fire({
                title: 'Delete Evaluation?',
                html: `Are you sure you want to delete the evaluation for <strong>"${projectName}"</strong>?<br><br>
                       <span class="text-danger"><i class="fas fa-exclamation-triangle me-1"></i>This will reset the project status to "Submitted".</span>`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Yes, delete it!',
                cancelButtonText: 'Cancel',
                reverseButtons: true
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = "checkpoint_evaluations.php?delete_evaluation=" + id;
                }
            });
            
            return false;
        };
        
        // Initialize scores on page load
        <?php if ($evaluate_id > 0): ?>
        updateScores();
        <?php endif; ?>
        
        // Add smooth scrolling for modal
        <?php if ($evaluate_id > 0): ?>
        $('html, body').animate({
            scrollTop: 0
        }, 500);
        <?php endif; ?>
    });
</script>

<?php require_once 'includes/footer.php'; ?>