<?php
// view_evaluation.php
$page_title = "Evaluation Details";

// Include header
require_once 'includes/header.php';

// Check if evaluation ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo '<div class="content-body">';
    echo '<div class="alert alert-danger">No evaluation ID provided.</div>';
    echo '</div>';
    require_once 'includes/footer.php';
    exit();
}

$evaluation_id = intval($_GET['id']);

// Get evaluation details
$eval_query = "SELECT ce.*, pi.*, d.department_name, 
               evaluator.username as evaluator_name, evaluator.email as evaluator_email,
               submitter.username as submitter_name, submitter.email as submitter_email
               FROM checkpoint_evaluations ce
               JOIN project_intakes pi ON ce.project_intake_id = pi.id
               LEFT JOIN departments d ON pi.department_id = d.id
               LEFT JOIN users evaluator ON ce.review_board_member_id = evaluator.id
               LEFT JOIN users submitter ON pi.submitted_by = submitter.id
               WHERE ce.id = ?";
$stmt = mysqli_prepare($conn, $eval_query);
mysqli_stmt_bind_param($stmt, "i", $evaluation_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if (mysqli_num_rows($result) == 0) {
    echo '<div class="content-body">';
    echo '<div class="alert alert-danger">Evaluation not found.</div>';
    echo '</div>';
    require_once 'includes/footer.php';
    exit();
}

$evaluation = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

// Check permissions - Only evaluator, submitter, or admin can view
$can_view = false;
if (has_role(['super_admin', 'pm_manager'])) {
    $can_view = true;
} else if ($evaluation['review_board_member_id'] == $user_id) {
    $can_view = true;
} else if ($evaluation['submitted_by'] == $user_id) {
    $can_view = true;
}

if (!$can_view) {
    echo '<div class="content-body">';
    echo '<div class="alert alert-danger">You do not have permission to view this evaluation.</div>';
    echo '</div>';
    require_once 'includes/footer.php';
    exit();
}

// Get evaluation logs
$logs_query = "SELECT cl.*, u.username 
               FROM checkpoint_logs cl
               LEFT JOIN users u ON cl.user_id = u.id
               WHERE cl.project_intake_id = ?
               ORDER BY cl.created_at DESC";
$logs_stmt = mysqli_prepare($conn, $logs_query);
mysqli_stmt_bind_param($logs_stmt, "i", $evaluation['project_intake_id']);
mysqli_stmt_execute($logs_stmt);
$logs_result = mysqli_stmt_get_result($logs_stmt);
$logs = [];
while ($row = mysqli_fetch_assoc($logs_result)) {
    $logs[] = $row;
}
mysqli_stmt_close($logs_stmt);

// Determine score colors and status
$score_class = $evaluation['total_score'] >= 70 ? 'text-success' : 
              ($evaluation['total_score'] >= 50 ? 'text-warning' : 'text-danger');
$decision_class = $evaluation['gate_decision'] == 'Accept' ? 'badge-success' :
                 ($evaluation['gate_decision'] == 'Reject' ? 'badge-danger' :
                 ($evaluation['gate_decision'] == 'Defer' ? 'badge-info' : 'badge-warning'));
?>

<div class="content-body">
    <div class="content-header">
        <h1><i class="fas fa-file-alt me-2"></i>Evaluation Details</h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="checkpoint_evaluations.php">Checkpoint Evaluations</a></li>
                <li class="breadcrumb-item active" aria-current="page">Evaluation Details</li>
            </ol>
        </nav>
    </div>
    
    <!-- Evaluation Summary -->
    <div class="row mb-4">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>Evaluation Summary</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <table class="table table-sm">
                                <tr>
                                    <th width="40%">Project:</th>
                                    <td><?php echo htmlspecialchars($evaluation['project_name']); ?></td>
                                </tr>
                                <tr>
                                    <th>Reference ID:</th>
                                    <td>PI-<?php echo str_pad($evaluation['project_intake_id'], 6, '0', STR_PAD_LEFT); ?></td>
                                </tr>
                                <tr>
                                    <th>Department:</th>
                                    <td><?php echo htmlspecialchars($evaluation['department_name'] ?? 'N/A'); ?></td>
                                </tr>
                                <tr>
                                    <th>Submitter:</th>
                                    <td><?php echo htmlspecialchars($evaluation['submitter_name']); ?></td>
                                </tr>
                                <tr>
                                    <th>Evaluator:</th>
                                    <td><?php echo htmlspecialchars($evaluation['evaluator_name']); ?></td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <table class="table table-sm">
                                <tr>
                                    <th width="40%">Review Date:</th>
                                    <td><?php echo date('F d, Y H:i', strtotime($evaluation['gate_review_date'])); ?></td>
                                </tr>
                                <tr>
                                    <th>Total Score:</th>
                                    <td>
                                        <span class="fw-bold <?php echo $score_class; ?>">
                                            <?php echo number_format($evaluation['total_score'], 2); ?>%
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Gate Decision:</th>
                                    <td>
                                        <span class="badge <?php echo $decision_class; ?>">
                                            <?php echo $evaluation['gate_decision']; ?>
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Threshold:</th>
                                    <td>70%</td>
                                </tr>
                                <tr>
                                    <th>Status:</th>
                                    <td>
                                        <span class="badge badge-<?php echo strtolower(str_replace(' ', '-', $evaluation['status'])); ?>">
                                            <?php echo $evaluation['status']; ?>
                                        </span>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-chart-pie me-2"></i>Score Breakdown</h5>
                </div>
                <div class="card-body">
                    <div class="progress mb-3" style="height: 30px;">
                        <div class="progress-bar bg-success" 
                             style="width: <?php echo min($evaluation['total_score'], 100); ?>%"
                             role="progressbar">
                            <?php echo number_format($evaluation['total_score'], 1); ?>%
                        </div>
                    </div>
                    
                    <div class="row small">
                        <div class="col-6 mb-2">
                            <span class="badge bg-light text-dark w-100">Strategic Alignment</span>
                            <div class="mt-1">
                                <strong><?php echo $evaluation['strategic_alignment_score']; ?>/5</strong>
                                <small class="text-muted">(<?php echo number_format($evaluation['strategic_alignment_weighted'], 1); ?>%)</small>
                            </div>
                        </div>
                        <div class="col-6 mb-2">
                            <span class="badge bg-light text-dark w-100">Financial Viability</span>
                            <div class="mt-1">
                                <strong><?php echo $evaluation['financial_viability_score']; ?>/5</strong>
                                <small class="text-muted">(<?php echo number_format($evaluation['financial_viability_weighted'], 1); ?>%)</small>
                            </div>
                        </div>
                        <div class="col-6 mb-2">
                            <span class="badge bg-light text-dark w-100">Operational Readiness</span>
                            <div class="mt-1">
                                <strong><?php echo $evaluation['operational_readiness_score']; ?>/5</strong>
                                <small class="text-muted">(<?php echo number_format($evaluation['operational_readiness_weighted'], 1); ?>%)</small>
                            </div>
                        </div>
                        <div class="col-6 mb-2">
                            <span class="badge bg-light text-dark w-100">Technical Feasibility</span>
                            <div class="mt-1">
                                <strong><?php echo $evaluation['technical_feasibility_score']; ?>/5</strong>
                                <small class="text-muted">(<?php echo number_format($evaluation['technical_feasibility_weighted'], 1); ?>%)</small>
                            </div>
                        </div>
                        <div class="col-6 mb-2">
                            <span class="badge bg-light text-dark w-100">Risk & Compliance</span>
                            <div class="mt-1">
                                <strong><?php echo $evaluation['risk_compliance_score']; ?>/5</strong>
                                <small class="text-muted">(<?php echo number_format($evaluation['risk_compliance_weighted'], 1); ?>%)</small>
                            </div>
                        </div>
                        <div class="col-6 mb-2">
                            <span class="badge bg-light text-dark w-100">Urgency</span>
                            <div class="mt-1">
                                <strong><?php echo $evaluation['urgency_score']; ?>/5</strong>
                                <small class="text-muted">(<?php echo number_format($evaluation['urgency_weighted'], 1); ?>%)</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Evaluation Details -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-clipboard-list me-2"></i>Evaluation Details</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <h6><i class="fas fa-gavel me-2 text-primary"></i>Decision Justification</h6>
                            <div class="border rounded p-3 bg-light">
                                <?php echo nl2br(htmlspecialchars($evaluation['decision_justification'])); ?>
                            </div>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <h6><i class="fas fa-comment me-2 text-primary"></i>Feedback to Submitter</h6>
                            <div class="border rounded p-3 bg-light">
                                <?php echo nl2br(htmlspecialchars($evaluation['feedback_to_submitter'])); ?>
                            </div>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <h6><i class="fas fa-lightbulb me-2 text-primary"></i>Recommendations</h6>
                            <div class="border rounded p-3 bg-light">
                                <?php echo nl2br(htmlspecialchars($evaluation['recommendations'])); ?>
                            </div>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <h6><i class="fas fa-sticky-note me-2 text-primary"></i>Review Notes</h6>
                            <div class="border rounded p-3 bg-light">
                                <?php echo nl2br(htmlspecialchars($evaluation['review_notes'])); ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Activity Log -->
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-history me-2"></i>Activity Log</h5>
        </div>
        <div class="card-body p-0">
            <?php if (empty($logs)): ?>
            <div class="text-center py-4">
                <i class="fas fa-history fa-3x text-muted mb-3"></i>
                <p class="text-muted">No activity logs found.</p>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Date & Time</th>
                            <th>User</th>
                            <th>Action</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?php echo date('M d, Y H:i', strtotime($log['created_at'])); ?></td>
                            <td><?php echo htmlspecialchars($log['username'] ?? 'System'); ?></td>
                            <td>
                                <span class="badge bg-info"><?php echo htmlspecialchars($log['action']); ?></span>
                            </td>
                            <td><?php echo htmlspecialchars($log['details']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Action Buttons -->
    <div class="d-flex justify-content-between mt-4">
        <div>
            <a href="checkpoint_evaluations.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-1"></i>Back to Evaluations
            </a>
            <a href="view_intake.php?id=<?php echo $evaluation['project_intake_id']; ?>" class="btn btn-outline-primary">
                <i class="fas fa-file-alt me-1"></i>View Project Intake
            </a>
        </div>
        
        <div>
            <?php if (has_role(['super_admin'])): ?>
            <a href="checkpoint_evaluations.php?delete_evaluation=<?php echo $evaluation_id; ?>" 
               class="btn btn-outline-danger"
               onclick="return confirm('Are you sure you want to delete this evaluation? This action cannot be undone.');">
                <i class="fas fa-trash me-1"></i>Delete Evaluation
            </a>
            <?php endif; ?>
            
            <button onclick="window.print()" class="btn btn-outline-success">
                <i class="fas fa-print me-1"></i>Print Report
            </button>
        </div>
    </div>
</div>

<?php
$page_css = '
<style>
    @media print {
        .no-print {
            display: none !important;
        }
        
        .content-header, .card-header {
            background: white !important;
            color: black !important;
            border-bottom: 2px solid #000;
        }
        
        .btn {
            display: none !important;
        }
        
        .badge {
            border: 1px solid #000;
            color: black !important;
            background: white !important;
        }
        
        .progress-bar {
            border: 1px solid #000;
        }
    }
    
    .bg-light {
        background-color: #f8f9fa !important;
    }
</style>
';

$page_js = '
<script>
    $(document).ready(function() {
        // Print styling
        window.addEventListener("beforeprint", function() {
            $(".content-header h1").text("Checkpoint Evaluation Report");
            $(".breadcrumb").addClass("no-print");
        });
    });
</script>
';

require_once 'includes/footer.php';
?>