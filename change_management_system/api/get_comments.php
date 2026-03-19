<?php
// api/get_comments.php
require_once '../db.php';

session_start();
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

if (!isset($_GET['change_request_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Change request ID is required']);
    exit;
}

$change_request_id = (int)$_GET['change_request_id'];
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['system_role'];

// Get comments for this change request
// Regular users can't see internal comments
if ($user_role === 'Admin' || $user_role === 'pm_manager'|| $user_role === 'super_admin') {
    $query = "SELECT c.*, u.username
              FROM change_request_comments c
              JOIN users u ON c.user_id = u.id
              WHERE c.change_request_id = ?
              ORDER BY c.comment_date ASC";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, 'i', $change_request_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $comments = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $comments[] = $row;
    }
    mysqli_stmt_close($stmt);
} else {
    $query = "SELECT c.*, u.username
              FROM change_request_comments c
              JOIN users u ON c.user_id = u.id
              WHERE c.change_request_id = ? AND c.is_internal = FALSE
              ORDER BY c.comment_date ASC";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, 'i', $change_request_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $comments = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $comments[] = $row;
    }
    mysqli_stmt_close($stmt);
}

echo json_encode(['status' => 'success', 'comments' => $comments]);
?>