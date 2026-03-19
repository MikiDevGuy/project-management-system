<?php
// api/update_change_request_status.php
require_once '../db.php';

session_start();
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

// Get and validate form data
$change_request_id = isset($_POST['change_request_id']) ? (int)$_POST['change_request_id'] : null;
$status = trim($_POST['status'] ?? '');
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['system_role'];

// Basic validation
if (!$change_request_id || empty($status)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid input parameters']);
    exit;
}

// Check if user has permission to update status
// Allow Admin, super_admin, or pm_manager roles
if ($user_role !== 'Admin' && $user_role !== 'super_admin' && $user_role !== 'pm_manager') {
    echo json_encode(['status' => 'error', 'message' => 'You do not have permission to update status']);
    exit;
}

// Update status using prepared statement
$query = "UPDATE change_requests SET status = ?, last_updated = NOW() WHERE change_request_id = ?";
$stmt = mysqli_prepare($conn, $query);
if (!$stmt) {
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . mysqli_error($conn)]);
    exit;
}

mysqli_stmt_bind_param($stmt, 'si', $status, $change_request_id);
$success = mysqli_stmt_execute($stmt);

if ($success) {
    // Log the action (if table exists)
    $logQuery = "INSERT INTO change_logs (change_request_id, user_id, action, details)
                 VALUES (?, ?, 'Status Updated', ?)";
    $logStmt = mysqli_prepare($conn, $logQuery);
    if ($logStmt) {
        $details = "Status changed to: $status";
        mysqli_stmt_bind_param($logStmt, 'iis', $change_request_id, $user_id, $details);
        mysqli_stmt_execute($logStmt);
        mysqli_stmt_close($logStmt);
    }

    mysqli_stmt_close($stmt);
    echo json_encode(['status' => 'success', 'message' => 'Status updated successfully']);
} else {
    mysqli_stmt_close($stmt);
    echo json_encode(['status' => 'error', 'message' => 'Failed to update status: ' . mysqli_error($conn)]);
}
?>