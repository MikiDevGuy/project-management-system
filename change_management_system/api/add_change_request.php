<?php
// api/add_change_request.php
require_once '../db.php';

session_start();
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

// Get and validate form data
$project_id = isset($_POST['project_id']) ? (int)$_POST['project_id'] : null;
$change_title = trim($_POST['change_title'] ?? '');
$change_type = trim($_POST['change_type'] ?? '');
$change_description = trim($_POST['change_description'] ?? '');
$justification = trim($_POST['justification'] ?? '');
$impact_analysis = trim($_POST['impact_analysis'] ?? '');
$area_of_impact = trim($_POST['area_of_impact'] ?? '');
$resolution_expected = trim($_POST['resolution_expected'] ?? '');
$date_resolved = !empty($_POST['date_resolved']) ? $_POST['date_resolved'] : null;
$action = trim($_POST['action'] ?? '');
$priority = trim($_POST['priority'] ?? '');
$assigned_to_id = isset($_POST['assigned_to_id']) && !empty($_POST['assigned_to_id']) ? (int)$_POST['assigned_to_id'] : null;
$escalation_required = isset($_POST['escalation_required']) ? (int)$_POST['escalation_required'] : 0;
$requester_id = $_SESSION['user_id'];

// Basic validation
if (empty($change_title) || empty($change_description) || empty($justification) || !$project_id) {
    echo json_encode(['status' => 'error', 'message' => 'Required fields are missing']);
    exit;
}

// Insert into database using prepared statement
$query = "INSERT INTO change_requests
          (project_id, change_title, change_type, change_description, justification,
           impact_analysis, area_of_impact, resolution_expected, date_resolved,
           action, priority, assigned_to_id, escalation_required, requester_id, status)
          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Open')";

$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, 'issssssssssiii',
    $project_id, $change_title, $change_type, $change_description, $justification,
    $impact_analysis, $area_of_impact, $resolution_expected, $date_resolved,
    $action, $priority, $assigned_to_id, $escalation_required, $requester_id
);

$success = mysqli_stmt_execute($stmt);

if ($success) {
    $change_request_id = mysqli_insert_id($conn);

    // Log the change (if table exists)
    $logQuery = "INSERT INTO change_logs (change_request_id, user_id, action, details)
                 VALUES (?, ?, 'Created', 'Change request created')";
    $logStmt = mysqli_prepare($conn, $logQuery);
    if ($logStmt) {
        mysqli_stmt_bind_param($logStmt, 'ii', $change_request_id, $requester_id);
        mysqli_stmt_execute($logStmt);
        mysqli_stmt_close($logStmt);
    }

    echo json_encode(['status' => 'success', 'message' => 'Change request added successfully']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Failed to add change request: ' . mysqli_error($conn)]);
}

mysqli_stmt_close($stmt);
?>