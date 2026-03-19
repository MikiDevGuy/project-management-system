<?php
// api/update_change_request.php
require_once '../db.php';

session_start();
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

// Get form data
$change_request_id = (int)$_POST['change_request_id'];
$project_id = (int)$_POST['project_id'];
$change_title = trim($_POST['change_title']);
$change_type = trim($_POST['change_type']);
$change_description = trim($_POST['change_description']);
$justification = trim($_POST['justification']);
$impact_analysis = trim($_POST['impact_analysis']);
$area_of_impact = trim($_POST['area_of_impact']);
$resolution_expected = trim($_POST['resolution_expected']);
$date_resolved = !empty($_POST['date_resolved']) ? $_POST['date_resolved'] : null;
$action = trim($_POST['action']);
$priority = trim($_POST['priority']);
$assigned_to_id = isset($_POST['assigned_to_id']) && !empty($_POST['assigned_to_id']) ? (int)$_POST['assigned_to_id'] : null;
$escalation_required = isset($_POST['escalation_required']) ? (int)$_POST['escalation_required'] : 0;
$user_id = $_SESSION['user_id'];

// Check if user has permission to edit this request
$checkQuery = "SELECT requester_id FROM change_requests WHERE change_request_id = ?";
$checkStmt = $conn->prepare($checkQuery);
$checkStmt->bind_param("i", $change_request_id);
$checkStmt->execute();
$result = $checkStmt->get_result();
$request = $result->fetch_assoc();

if (!$request) {
    echo json_encode(['status' => 'error', 'message' => 'Change request not found']);
    exit;
}

// Allow edit if user is admin/manager OR if they created the request and it's still open
$user_role = $_SESSION['system_role'];
if ($user_role !== 'Admin' && $user_role !== 'pm_manager' && $request['requester_id'] != $user_id) {
    echo json_encode(['status' => 'error', 'message' => 'You do not have permission to edit this request']);
    exit;
}

// Update database
$query = "UPDATE change_requests SET
          project_id = ?, change_title = ?, change_type = ?, change_description = ?,
          justification = ?, impact_analysis = ?, area_of_impact = ?,
          resolution_expected = ?, date_resolved = ?, action = ?, priority = ?,
          assigned_to_id = ?, escalation_required = ?
          WHERE change_request_id = ?";

$stmt = $conn->prepare($query);
$stmt->bind_param("issssssssssiii", $project_id, $change_title, $change_type, $change_description, $justification,
    $impact_analysis, $area_of_impact, $resolution_expected, $date_resolved,
    $action, $priority, $assigned_to_id, $escalation_required, $change_request_id);
$success = $stmt->execute();

if ($success) {
    // Log the change
    $logQuery = "INSERT INTO change_logs (change_request_id, user_id, action, details)
                 VALUES (?, ?, ?, ?)";
    $logStmt = $conn->prepare($logQuery);
    $logAction = 'Updated';
    $details = 'Change request details updated';
    $logStmt->bind_param("iiss", $change_request_id, $user_id, $logAction, $details);
    $logStmt->execute();

    echo json_encode(['status' => 'success', 'message' => 'Change request updated successfully']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Failed to update change request']);
}
?>