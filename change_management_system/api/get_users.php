<?php
// api/get_users.php
require_once '../db.php';

session_start();
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

// Get users who can be assigned tasks (typically team members and above)
$query = "SELECT id as user_id, username FROM users WHERE system_role IN ('pm_employee', 'test_viewer', 'pm_manager', 'Admin') ORDER BY username";
$stmt = $conn->prepare($query);
$stmt->execute();
$result = $stmt->get_result();
$users = $result->fetch_all(MYSQLI_ASSOC);

header('Content-Type: application/json');
echo json_encode($users);
?>