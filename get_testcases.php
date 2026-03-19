<!-- <php 
session_start();
include 'db.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$feature_id = $_GET['feature_id'] ?? 0;
$user_id = $_SESSION['user_id'];
$role = $_SESSION['system_role'];

if (!$feature_id) {
    echo json_encode(['success' => false, 'message' => 'Feature ID is required']);
    exit;
}

// Build query based on user role
if ($role === 'super_admin' || $role === 'admin') {
    $query = "
        SELECT tc.id, tc.title, tc.status, tc.priority, tc.created_at
        FROM test_cases tc
        WHERE tc.feature_id = ?
        ORDER BY tc.created_at DESC
    ";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $feature_id);
} else {
    // Regular users can only see test cases from projects they're assigned to
    $query = "
        SELECT tc.id, tc.title, tc.status, tc.priority, tc.created_at
        FROM test_cases tc
        JOIN project_users pu ON tc.project_id = pu.project_id
        WHERE tc.feature_id = ? AND pu.user_id = ?
        ORDER BY tc.created_at DESC
    ";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $feature_id, $user_id);
}

$stmt->execute();
$result = $stmt->get_result();

$testcases = [];
while ($row = $result->fetch_assoc()) {
    $testcases[] = $row;
}

echo json_encode(['success' => true, 'testcases' => $testcases]); -->
[file name]: get_testcases.php
[file content begin]
<?php
session_start();
include 'db.php';

if (!isset($_SESSION['user_id'])) {
    die('<div class="alert alert-danger">Access denied. Please log in.</div>');
}

$project_id = $_GET['project_id'] ?? null;
if (!$project_id) {
    die('<div class="alert alert-warning">Project ID is required.</div>');
}

$role = $_SESSION['system_role'];
$user_id = $_SESSION['user_id'];

// Check if user has access to this project
if ($role !== 'super_admin') {
    $checkStmt = $conn->prepare("
        SELECT 1 FROM project_users 
        WHERE project_id = ? AND user_id = ?
    ");
    $checkStmt->bind_param("ii", $project_id, $user_id);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    
    if ($checkResult->num_rows === 0) {
        die('<div class="alert alert-warning">You do not have access to this project.</div>');
    }
}

// Get test cases
$statusFilter = $_GET['status'] ?? '';
if ($statusFilter) {
    $stmt = $conn->prepare("SELECT * FROM test_cases WHERE project_id=? AND status=? ORDER BY id DESC");
    $stmt->bind_param("is", $project_id, $statusFilter);
} else {
    $stmt = $conn->prepare("SELECT * FROM test_cases WHERE project_id=? ORDER BY id DESC");
    $stmt->bind_param("i", $project_id);
}
$stmt->execute();
$testcases = $stmt->get_result();

if ($testcases->num_rows === 0): ?>
    <div class="alert alert-info">
        <i class="fas fa-info-circle me-2"></i>No test cases found for this project.
    </div>
<?php else: ?>
    <div class="row">
        <?php while ($tc = $testcases->fetch_assoc()): ?>
            <div class="col-md-6">
                <div class="testcase-card <?= strtolower($tc['status']) ?>">
                    <div class="testcase-header d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-1 fw-bold"><?= htmlspecialchars($tc['title']) ?></h6>
                            <small class="text-muted">ID: <?= $tc['id'] ?></small>
                        </div>
                        <div>
                            <span class="status-badge status-<?= strtolower($tc['status']) ?>">
                                <?= htmlspecialchars($tc['status']) ?>
                            </span>
                        </div>
                    </div>
                    <div class="testcase-body">
                        <div class="mb-3">
                            <small class="text-muted d-block mb-1">Priority</small>
                            <span class="priority-badge priority-<?= $tc['priority'] ?>">
                                <?= htmlspecialchars(ucfirst($tc['priority'])) ?>
                            </span>
                        </div>
                        
                        <div class="mb-3">
                            <small class="text-muted d-block mb-1">Steps Preview</small>
                            <p class="mb-0 text-truncate">
                                <?= htmlspecialchars(substr($tc['steps'], 0, 100)) ?>...
                            </p>
                        </div>
                        
                        <div class="mb-2">
                            <small class="text-muted d-block mb-1">Expected Result</small>
                            <p class="mb-0 text-truncate">
                                <?= htmlspecialchars(substr($tc['expected'], 0, 80)) ?>...
                            </p>
                        </div>
                        
                        <?php if ($tc['tester_remark']): ?>
                            <div class="mb-2">
                                <small class="text-muted d-block mb-1">Tester Remark</small>
                                <p class="mb-0 text-truncate">
                                    <?= htmlspecialchars(substr($tc['tester_remark'], 0, 60)) ?>...
                                </p>
                            </div>
                        <?php endif; ?>
                        
                        <div class="testcase-actions d-flex justify-content-between">
                            <button class="btn btn-sm btn-outline-primary view-details-btn"
                                    data-testcase-id="<?= $tc['id'] ?>">
                                <i class="fas fa-eye me-1"></i> View
                            </button>
                            
                            <?php if ($_SESSION['system_role'] == 'super_admin' || $_SESSION['system_role'] == 'tester'): ?>
                                <div class="btn-group">
                                    <button class="btn btn-sm btn-outline-warning edit-testcase-btn"
                                            data-testcase-id="<?= $tc['id'] ?>"
                                            data-project-id="<?= $project_id ?>">
                                        <i class="fas fa-edit me-1"></i> Edit
                                    </button>
                                    <button class="btn btn-sm btn-outline-danger delete-testcase-btn"
                                            data-testcase-id="<?= $tc['id'] ?>"
                                            data-project-id="<?= $project_id ?>">
                                        <i class="fas fa-trash me-1"></i> Delete
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endwhile; ?>
    </div>
    
    <div class="mt-4">
        <div class="alert alert-light border">
            <i class="fas fa-chart-bar me-2"></i>
            <strong>Summary:</strong> <?= $testcases->num_rows ?> test case(s) found
        </div>
    </div>
<?php endif; ?>
[file content end]