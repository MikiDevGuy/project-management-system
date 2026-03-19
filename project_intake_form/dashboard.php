<?php
// dashboard.php
$page_title = "Dashboard";

// Include header
require_once 'includes/header.php';

// Get statistics based on user role
$stats = [];

// Total project intakes
$total_query = "SELECT COUNT(*) as count FROM project_intakes";
if (!has_role(['super_admin', 'pm_manager'])) {
    $total_query .= " WHERE submitted_by = $user_id";
}
$stats['total_intakes'] = mysqli_fetch_assoc(mysqli_query($conn, $total_query))['count'];

// Pending review
$pending_query = "SELECT COUNT(*) as count FROM project_intakes WHERE status IN ('Submitted', 'Under Review')";
if (!has_role(['super_admin', 'pm_manager'])) {
    $pending_query .= " AND submitted_by = $user_id";
}
$stats['pending_review'] = mysqli_fetch_assoc(mysqli_query($conn, $pending_query))['count'];

// Approved
$approved_query = "SELECT COUNT(*) as count FROM project_intakes WHERE status = 'Approved'";
if (!has_role(['super_admin', 'pm_manager'])) {
    $approved_query .= " AND submitted_by = $user_id";
}
$stats['approved'] = mysqli_fetch_assoc(mysqli_query($conn, $approved_query))['count'];

// Rejected
$rejected_query = "SELECT COUNT(*) as count FROM project_intakes WHERE status = 'Rejected'";
if (!has_role(['super_admin', 'pm_manager'])) {
    $rejected_query .= " AND submitted_by = $user_id";
}
$stats['rejected'] = mysqli_fetch_assoc(mysqli_query($conn, $rejected_query))['count'];

// Deferred
$deferred_query = "SELECT COUNT(*) as count FROM project_intakes WHERE status = 'Deferred'";
if (!has_role(['super_admin', 'pm_manager'])) {
    $deferred_query .= " AND submitted_by = $user_id";
}
$stats['deferred'] = mysqli_fetch_assoc(mysqli_query($conn, $deferred_query))['count'];

// Get recent intakes
$recent_query = "SELECT pi.*, d.department_name 
                 FROM project_intakes pi 
                 LEFT JOIN departments d ON pi.department_id = d.id 
                 WHERE 1=1";
if (!has_role(['super_admin', 'pm_manager'])) {
    $recent_query .= " AND pi.submitted_by = $user_id";
}
$recent_query .= " ORDER BY pi.created_at DESC LIMIT 5";
$recent_result = mysqli_query($conn, $recent_query);
$recent_intakes = [];
while ($row = mysqli_fetch_assoc($recent_result)) {
    $recent_intakes[] = $row;
}

// Get recent evaluations (for admins/managers)
$recent_evals = [];
if (has_role(['super_admin', 'pm_manager'])) {
    $eval_query = "SELECT ce.*, pi.project_name, u.username as evaluator 
                   FROM checkpoint_evaluations ce 
                   JOIN project_intakes pi ON ce.project_intake_id = pi.id 
                   JOIN users u ON ce.review_board_member_id = u.id 
                   ORDER BY ce.created_at DESC LIMIT 5";
    $eval_result = mysqli_query($conn, $eval_query);
    while ($row = mysqli_fetch_assoc($eval_result)) {
        $recent_evals[] = $row;
    }
}

// Get status distribution for chart
$status_data = [];
if (has_role(['super_admin', 'pm_manager'])) {
    $status_query = "SELECT status, COUNT(*) as count FROM project_intakes GROUP BY status";
    $status_result = mysqli_query($conn, $status_query);
    while ($row = mysqli_fetch_assoc($status_result)) {
        $status_data[] = $row;
    }
}
?>

<div class="content-body">
    <!-- Welcome Section -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h3 class="mb-2">Welcome back, <?php echo htmlspecialchars($username); ?>!</h3>
                            <p class="text-muted mb-0">Here's what's happening with your project intakes today.</p>
                        </div>
                        <div class="col-md-4 text-end">
                            <a href="project_intake_form.php" class="btn btn-primary btn-lg">
                                <i class="fas fa-plus-circle me-2"></i>New Project Intake
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card bg-primary text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-uppercase mb-0">Total Intakes</h6>
                            <h2 class="mb-0"><?php echo $stats['total_intakes']; ?></h2>
                        </div>
                        <div class="icon">
                            <i class="fas fa-file-alt fa-3x opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card bg-warning text-dark">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-uppercase mb-0">Pending Review</h6>
                            <h2 class="mb-0"><?php echo $stats['pending_review']; ?></h2>
                        </div>
                        <div class="icon">
                            <i class="fas fa-clock fa-3x opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card bg-success text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-uppercase mb-0">Approved</h6>
                            <h2 class="mb-0"><?php echo $stats['approved']; ?></h2>
                        </div>
                        <div class="icon">
                            <i class="fas fa-check-circle fa-3x opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card bg-danger text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-uppercase mb-0">Rejected</h6>
                            <h2 class="mb-0"><?php echo $stats['rejected']; ?></h2>
                        </div>
                        <div class="icon">
                            <i class="fas fa-times-circle fa-3x opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts and Recent Activity -->
    <div class="row">
        <?php if (has_role(['super_admin', 'pm_manager'])): ?>
        <!-- Admin Dashboard -->
        <div class="col-lg-8 mb-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Project Intakes Overview</h5>
                </div>
                <div class="card-body">
                    <div id="statusChart" style="height: 300px;"></div>
                </div>
            </div>
        </div>
        
        <div class="col-lg-4 mb-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-history me-2"></i>Recent Evaluations</h5>
                </div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush">
                        <?php if (empty($recent_evals)): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-clipboard-check fa-3x text-muted mb-3"></i>
                            <p class="text-muted">No recent evaluations</p>
                        </div>
                        <?php else: ?>
                        <?php foreach ($recent_evals as $eval): ?>
                        <a href="view_evaluation.php?id=<?php echo $eval['id']; ?>" class="list-group-item list-group-item-action">
                            <div class="d-flex w-100 justify-content-between">
                                <h6 class="mb-1"><?php echo htmlspecialchars($eval['project_name']); ?></h6>
                                <small class="text-<?php echo $eval['total_score'] >= 70 ? 'success' : 'danger'; ?>">
                                    <?php echo number_format($eval['total_score'], 1); ?>%
                                </small>
                            </div>
                            <p class="mb-1 small">By: <?php echo htmlspecialchars($eval['evaluator']); ?></p>
                            <small class="text-muted">
                                <?php echo date('M d, H:i', strtotime($eval['created_at'])); ?>
                            </small>
                        </a>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Recent Intakes -->
        <div class="col-12 mb-4">
            <div class="card">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-clock me-2"></i>Recent Project Intakes</h5>
                        <a href="project_intake_list.php" class="btn btn-sm btn-outline-primary">View All</a>
                    </div>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($recent_intakes)): ?>
                    <div class="text-center py-4">
                        <i class="fas fa-file-alt fa-3x text-muted mb-3"></i>
                        <p class="text-muted">No project intakes found</p>
                        <a href="project_intake_form.php" class="btn btn-primary">Create First Intake</a>
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Project Name</th>
                                    <th>Department</th>
                                    <th>Submitted</th>
                                    <th>Budget</th>
                                    <th>Risk</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_intakes as $intake): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($intake['project_name']); ?></strong>
                                        <?php if ($intake['status'] == 'Draft'): ?>
                                        <br><small class="text-muted"><i class="fas fa-pencil-alt me-1"></i>Draft</small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($intake['department_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($intake['created_at'])); ?></td>
                                    <td>
                                        <?php if ($intake['estimated_total_budget']): ?>
                                        $<?php echo number_format($intake['estimated_total_budget'], 2); ?>
                                        <?php else: ?>
                                        <span class="text-muted">Not specified</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php 
                                        echo $intake['overall_risk_rating'] == 'High' ? 'danger' : 
                                             ($intake['overall_risk_rating'] == 'Medium' ? 'warning' : 'success'); 
                                        ?>">
                                            <?php echo $intake['overall_risk_rating']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?php echo strtolower(str_replace(' ', '-', $intake['status'])); ?>">
                                            <?php echo $intake['status']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="view_intake.php?id=<?php echo $intake['id']; ?>" 
                                           class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="col-12 mb-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-bolt me-2"></i>Quick Actions</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <a href="project_intake_form.php" class="btn btn-outline-primary w-100 py-3">
                                <i class="fas fa-plus-circle fa-2x mb-2"></i>
                                <div>New Intake</div>
                            </a>
                        </div>
                        <div class="col-md-3 mb-3">
                            <a href="project_intake_list.php" class="btn btn-outline-success w-100 py-3">
                                <i class="fas fa-list fa-2x mb-2"></i>
                                <div>View Intakes</div>
                            </a>
                        </div>
                        <div class="col-md-3 mb-3">
                            <a href="checkpoint_evaluations.php" class="btn btn-outline-warning w-100 py-3">
                                <i class="fas fa-clipboard-check fa-2x mb-2"></i>
                                <div>Evaluations</div>
                            </a>
                        </div>
                        <div class="col-md-3 mb-3">
                            <a href="reports.php" class="btn btn-outline-info w-100 py-3">
                                <i class="fas fa-chart-bar fa-2x mb-2"></i>
                                <div>Reports</div>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- System Status -->
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>System Information</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <ul class="list-unstyled">
                                <li class="mb-2"><i class="fas fa-user me-2 text-primary"></i>Logged in as: <strong><?php echo htmlspecialchars($username); ?></strong></li>
                                <li class="mb-2"><i class="fas fa-user-tag me-2 text-primary"></i>Role: <strong><?php echo htmlspecialchars($user_role); ?></strong></li>
                                <li class="mb-2"><i class="fas fa-calendar me-2 text-primary"></i>Current Date: <strong><?php echo date('F d, Y'); ?></strong></li>
                                <li class="mb-2"><i class="fas fa-database me-2 text-primary"></i>Database: <strong><?php echo mysqli_get_host_info($conn); ?></strong></li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <div class="alert alert-info">
                                <h6><i class="fas fa-lightbulb me-2"></i>Quick Tips</h6>
                                <ul class="mb-0">
                                    <li>Ensure all required fields are completed when submitting intake forms</li>
                                    <li>Attach all necessary documents before submission</li>
                                    <li>Check your intake status regularly for updates</li>
                                    <li>Contact BSPMD support for any technical issues</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$page_js = '
<script>
    // Status Chart for Admin
    ' . (has_role(['super_admin', 'pm_manager']) ? '
    document.addEventListener("DOMContentLoaded", function() {
        var statusData = ' . json_encode($status_data) . ';
        
        var labels = [];
        var data = [];
        var colors = [];
        
        statusData.forEach(function(item) {
            labels.push(item.status);
            data.push(item.count);
            
            // Assign colors based on status
            switch(item.status.toLowerCase()) {
                case "draft": colors.push("#6c757d"); break;
                case "submitted": colors.push("#17a2b8"); break;
                case "under review": colors.push("#ffc107"); break;
                case "approved": colors.push("#28a745"); break;
                case "rejected": colors.push("#dc3545"); break;
                case "deferred": colors.push("#6f42c1"); break;
                default: colors.push("#adb5bd");
            }
        });
        
        var options = {
            series: [{
                data: data
            }],
            chart: {
                type: "bar",
                height: 300,
                toolbar: {
                    show: false
                }
            },
            plotOptions: {
                bar: {
                    distributed: true,
                    borderRadius: 4,
                    columnWidth: "60%",
                }
            },
            dataLabels: {
                enabled: true,
                formatter: function(val) {
                    return val;
                }
            },
            xaxis: {
                categories: labels
            },
            yaxis: {
                title: {
                    text: "Number of Intakes"
                }
            },
            colors: colors,
            tooltip: {
                y: {
                    formatter: function(val) {
                        return val + " intakes";
                    }
                }
            }
        };
        
        var chart = new ApexCharts(document.querySelector("#statusChart"), options);
        chart.render();
    });
    ' : '') . '
    
    // Auto-refresh dashboard every 2 minutes
    setTimeout(function() {
        window.location.reload();
    }, 120000);
</script>
';

require_once 'includes/footer.php';
?>