<?php
// api/get_change_request_details.php
require_once '../db.php';

if (!isset($_GET['id'])) {
    echo json_encode(['status' => 'error', 'message' => 'ID parameter is required']);
    exit;
}

$id = (int)$_GET['id'];

$query = "SELECT cr.*, p.name as project_name, u.username as requester_name,
           u2.username as assigned_to_name, u2.id as assigned_to_id
          FROM change_requests cr
          JOIN projects p ON cr.project_id = p.id
          JOIN users u ON cr.requester_id = u.id
          LEFT JOIN users u2 ON cr.assigned_to_id = u2.id
          WHERE cr.change_request_id = ?";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$request = $result->fetch_assoc();

if ($request) {
    echo json_encode(['status' => 'success', 'request' => $request]);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Change request not found']);
}
?>