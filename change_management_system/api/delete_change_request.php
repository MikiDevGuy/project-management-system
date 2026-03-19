<?php
// api/delete_change_request.php
require_once '../db_connection.php';

session_start();
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$id = $data['id'];
$user_id = $_SESSION['user_id'];

// First, delete related logs
$logQuery = "DELETE FROM change_logs WHERE change_request_id = ?";
$logStmt = $pdo->prepare($logQuery);
$logStmt->execute([$id]);

// Then delete the change request
$query = "DELETE FROM change_requests WHERE change_request_id = ?";
$stmt = $pdo->prepare($query);
$success = $stmt->execute([$id]);

if ($success) {
    echo json_encode(['status' => 'success', 'message' => 'Change request deleted successfully']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Failed to delete change request']);
}
?>