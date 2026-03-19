[file name]: ajax_get_testcases.php
[file content begin]
<?php
session_start();
include 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Access denied. Please log in.']);
    exit;
}

$project_id = $_GET['project_id'] ?? null;
$status = $_GET['status'] ?? '';
$priority = $_GET['priority'] ?? '';

if (!$project_id) {
    echo json_encode(['success' => false, 'message' => 'Project ID is required.']);
    exit;
}

// Check if user has access to this project
$role = $_SESSION['system_role'];
$user_id = $_SESSION['user_id'];

if ($role !== 'super_admin') {
    $checkStmt = $conn->prepare("
        SELECT 1 FROM project_users 
        WHERE project_id = ? AND user_id = ?
    ");
    $checkStmt->bind_param("ii", $project_id, $user_id);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    
    if ($checkResult->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'You do not have access to this project.']);
        exit;
    }
}

// Build query with filters
$query = "SELECT * FROM test_cases WHERE project_id = ?";
$params = [$project_id];
$types = "i";

if ($status) {
    $query .= " AND status = ?";
    $params[] = $status;
    $types .= "s";
}

if ($priority) {
    $query .= " AND priority = ?";
    $params[] = $priority;
    $types .= "s";
}

$query .= " ORDER BY id DESC";

$stmt = $conn->prepare($query);

// Bind parameters dynamically
if (count($params) > 1) {
    $stmt->bind_param($types, ...$params);
} else {
    $stmt->bind_param($types, $project_id);
}

$stmt->execute();
$result = $stmt->get_result();

$testcases = [];
$stats = ['total' => 0, 'pass' => 0, 'fail' => 0, 'pending' => 0];

while ($row = $result->fetch_assoc()) {
    $testcases[] = [
        'id' => $row['id'],
        'title' => $row['title'],
        'steps' => $row['steps'],
        'expected' => $row['expected'],
        'status' => $row['status'],
        'priority' => $row['priority'],
        'tester_remark' => $row['tester_remark'],
        'vendor_comment' => $row['vendor_comment'],
        'created_at' => $row['created_at'],
        'project_id' => $row['project_id']
    ];
    
    // Update stats
    $stats['total']++;
    switch (strtolower($row['status'])) {
        case 'pass':
            $stats['pass']++;
            break;
        case 'fail':
            $stats['fail']++;
            break;
        case 'pending':
            $stats['pending']++;
            break;
    }
}

echo json_encode([
    'success' => true,
    'testcases' => $testcases,
    'stats' => $stats
]);
[file content end]