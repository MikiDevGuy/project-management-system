<?php
session_start();
include 'db.php';
$active_dashboard = 'project_management';
include 'sidebar.php';

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$username = htmlspecialchars($_SESSION['username']);
$role = $_SESSION['system_role'] ?? 'pm_employee';

// Simplified query to avoid complex joins that might fail
$query = "
    SELECT p.id, p.name, p.description
    FROM projects p
    JOIN user_assignments ua ON p.id = ua.project_id
    WHERE ua.user_id = ?
";

$stmt = $conn->prepare($query);
if (!$stmt) {
    die("Error preparing query: " . $conn->error);
}

if (!$stmt->bind_param("i", $user_id)) {
    die("Error binding parameters: " . $stmt->error);
}

if (!$stmt->execute()) {
    die("Error executing query: " . $stmt->error);
}

$result = $stmt->get_result();
$projects = $result->fetch_all(MYSQLI_ASSOC);

// Now get test case counts separately to avoid complex join issues
$projectIds = array_column($projects, 'id');
//$testCaseCounts = [];
$phaseCounts = [];

if (!empty($projectIds)) {
    $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
    $types = str_repeat('i', count($projectIds));
    
    $countQuery = "
        SELECT project_id, 
               COUNT(*) as total,
               SUM(status = 'completed') as passed_count,
               SUM(status = 'in_progress') as failed_count,
               SUM(status = 'pending') as pending_count
        FROM phases
        WHERE project_id IN ($placeholders)
        GROUP BY project_id
    ";
    
    $countStmt = $conn->prepare($countQuery);
    if ($countStmt) {
        $countStmt->bind_param($types, ...$projectIds);
        $countStmt->execute();
        $countResult = $countStmt->get_result();
        
        while ($row = $countResult->fetch_assoc()) {
            $phaseCounts[$row['project_id']] = $row;
        }
    }
}

// Merge phase counts with projects
foreach ($projects as &$project) {
    $projectId = $project['id'];
    $project['testcase_count'] = $phaseCounts[$projectId]['total'] ?? 0;
    $project['passed_count'] = $phaseCounts[$projectId]['passed_count'] ?? 0;
   // $project['failed_count'] = $testCounts[$projectId]['failed_count'] ?? 0;
    $project['failed_count'] = $testCounts[$projectId]['failed_count'] ?? 0; 
    $project['pending_count'] = $phaseCounts[$projectId]['pending_count'] ?? 0;
}
unset($project); // Break the reference
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Assigned Projects - Test Manager</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #4e73df;
            --accent-color: #2e59d9;
            --success-color: #1cc88a;
            --danger-color: #e74a3b;
            --warning-color: #f6c23e;
        }
        
        .project-card {
            transition: transform 0.3s ease;
        }
        
        .project-card:hover {
            transform: translateY(-5px);
        }
        
        .progress {
            height: 8px;
        }
        
        .empty-state {
            max-width: 500px;
            margin: 0 auto;
        }
    </style>
</head>
<body>
    <!-- Navigation and header code remains the same as before -->
    
    <div class="container py-4">
        <?php if (!empty($projects)): ?>
            <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-4">
                <?php foreach ($projects as $project): 
                    $total = $project['testcase_count'];
                    $passed = $project['passed_count'];
                    $failed = $project['failed_count'];
                    $pending = $project['pending_count'];
                   // $created = date('M j, Y', strtotime($project['created_at']));
                ?>
                    <div class="col">
                        <div class="card project-card h-100 shadow-sm">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0"><?= htmlspecialchars($project['name']) ?></h5>
                            </div>
                            <div class="card-body">
                                <p class="card-text"><?= htmlspecialchars($project['description']) ?></p>
                                <!--<small class="text-muted d-block mb-2">
                                    <i class="far fa-calendar me-1"></i> Created
                                </small> -->
                                
                                <?php if ($total > 0): ?>
                                    <div class="mb-3">
                                        <div class="d-flex justify-content-between small mb-2">
                                            <span class="badge bg-success">
                                                <i class="fas fa-check"></i> <?= $passed ?> Passed
                                            </span>
                                            <span class="badge bg-danger">
                                                <i class="fas fa-times"></i> <?= $failed ?> In_progress
                                            </span>
                                            <span class="badge bg-warning text-dark">
                                                <i class="fas fa-clock"></i> <?= $pending ?> Pending
                                            </span>
                                        </div>
                                        <div class="progress">
                                            <div class="progress-bar bg-success" style="width: <?= $total ? ($passed/$total)*100 : 0 ?>%"></div>
                                            <div class="progress-bar bg-danger" style="width: <?= $total ? ($failed/$total)*100 : 0 ?>%"></div>
                                            <div class="progress-bar bg-warning" style="width: <?= $total ? ($pending/$total)*100 : 0 ?>%"></div>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-info small mb-3">
                                        No Phases yet
                                    </div>
                                <?php endif; ?>
                                
                                <div class="d-flex justify-content-between">
                                    <a href="view_phases.php?id=<?= $project['id'] ?>" class="btn btn-sm btn-primary">
                                        <i class="fas fa-eye"></i> View Phases
                                    </a>
                                    <!-- <php if ($role === 'pm_employee'): ?>
                                    --<a href="view_assigned_activities.php?id=<= $project['id'] ?>" class="btn btn-sm btn-primary">
                                        <i class="fas fa-eye"></i> View activities
                                    </a><br>
                                    <br>
                                    <a href="view_assigned_sub_activities.php?id=<= $project['id'] ?>" class="btn btn-sm btn-primary" >
                                        <i class="fas fa-eye"></i> View sub-activities
                                    </a> 
                                    <php endif; ?> -->
                                    <?php if ($role === 'tester'): ?>
                                        <a href="add_testcase.php?project_id=<?= $project['id'] ?>" class="btn btn-sm btn-success">
                                            <i class="fas fa-plus"></i> Add Test
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state text-center py-5">
                <i class="fas fa-folder-open fa-4x text-muted mb-4"></i>
                <h3>No Projects Assigned</h3>
                <p class="text-muted">You don't have any projects assigned to you yet.</p>
                <a href="dashboard.php" class="btn btn-primary mt-3">
                    <i class="fas fa-arrow-left me-1"></i> Back to Dashboard
                </a>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>