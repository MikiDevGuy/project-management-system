<?php
session_start();
include 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Access denied. Please log in.']);
    exit;
}

$testcase_id = $_GET['id'] ?? null;
if (!$testcase_id) {
    echo json_encode(['success' => false, 'message' => 'Test case ID is required.']);
    exit;
}

// Get test case details
$stmt = $conn->prepare("
    SELECT tc.*, p.name as project_name, u.username as created_by_username
    FROM test_cases tc
    JOIN projects p ON tc.project_id = p.id
    JOIN users u ON tc.created_by = u.id
    WHERE tc.id = ?
");
$stmt->bind_param("i", $testcase_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Test case not found.']);
    exit;
}

$testcase = $result->fetch_assoc();

// Format dates if needed
$testcase['created_at'] = date('M d, Y H:i', strtotime($testcase['created_at']));
$testcase['updated_at'] = date('M d, Y H:i', strtotime($testcase['updated_at']));

echo json_encode([
    'success' => true,
    'title' => htmlspecialchars($testcase['title']),
    'steps' => nl2br(htmlspecialchars($testcase['steps'])),
    'expected' => htmlspecialchars($testcase['expected']),
    'status' => $testcase['status'],
    'priority' => $testcase['priority'],
    'tester_remark' => htmlspecialchars($testcase['tester_remark'] ?? ''),
    'vendor_comment' => htmlspecialchars($testcase['vendor_comment'] ?? ''),
    'created_at' => $testcase['created_at'],
    'updated_at' => $testcase['updated_at'],
    'project_name' => htmlspecialchars($testcase['project_name']),
    'created_by' => htmlspecialchars($testcase['created_by_username'])
]);
