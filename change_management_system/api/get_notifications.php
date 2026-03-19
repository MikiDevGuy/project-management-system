<?php
// api/get_notifications.php
require_once '../db.php';

session_start();
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['system_role'];
$details = isset($_GET['details']) ? true : false;

if ($user_role === 'Admin' || $user_role === 'Project Manager') {
    // For admins/managers: show pending approvals
    if ($details) {
        $query = "SELECT cr.change_request_id, cr.change_title, u.username as requester_name,
                         'Pending Approval' as title,
                         CONCAT('Change request from ', u.username, ' needs review') as message,
                         cr.request_date as date,
                         'alert' as type
                  FROM change_requests cr
                  JOIN users u ON cr.requester_id = u.user_id
                  WHERE cr.status = 'Open'
                  ORDER BY cr.request_date DESC";
        $stmt = $conn->prepare($query);
        $stmt->execute();
        $result = $stmt->get_result();
        $notifications = $result->fetch_all(MYSQLI_ASSOC);

        echo json_encode(['status' => 'success', 'count' => count($notifications), 'notifications' => $notifications]);
    } else {
        $query = "SELECT COUNT(*) as count FROM change_requests WHERE status = 'Open'";
        $stmt = $conn->prepare($query);
        $stmt->execute();
        $result = $stmt->get_result();
        $count = $result->fetch_assoc()['count'];

        echo json_encode(['status' => 'success', 'count' => $count]);
    }
} else {
    // For regular users: show status updates on their requests
    if ($details) {
        $query = "SELECT change_request_id, change_title,
                         CONCAT('Your change request has been ', status) as title,
                         CONCAT('Change request \"', change_title, '\" has been ', LOWER(status)) as message,
                         last_updated as date,
                         CASE
                             WHEN status = 'Approved' THEN 'success'
                             WHEN status = 'Rejected' THEN 'danger'
                             ELSE 'info'
                         END as type
                  FROM change_requests
                  WHERE requester_id = ? AND (status = 'Approved' OR status = 'Rejected') AND viewed = 0
                  ORDER BY last_updated DESC";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $notifications = $result->fetch_all(MYSQLI_ASSOC);

        // Mark as viewed
        $updateQuery = "UPDATE change_requests SET viewed = 1 WHERE requester_id = ? AND (status = 'Approved' OR status = 'Rejected') AND viewed = 0";
        $updateStmt = $conn->prepare($updateQuery);
        $updateStmt->bind_param("i", $user_id);
        $updateStmt->execute();

        echo json_encode(['status' => 'success', 'count' => count($notifications), 'notifications' => $notifications]);
    } else {
        $query = "SELECT COUNT(*) as count FROM change_requests
                  WHERE requester_id = ? AND (status = 'Approved' OR status = 'Rejected') AND viewed = 0";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $count = $result->fetch_assoc()['count'];

        echo json_encode(['status' => 'success', 'count' => $count]);
    }
}
?>