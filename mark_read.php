<?php
/*session_start();
require 'db.php'; // Your database connection

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['log_id'])) {
    $log_id = intval($_POST['log_id']);
    $stmt = $conn->prepare("UPDATE activity_logs SET is_read = 1 WHERE id = ?");
    $stmt->bind_param("i", $log_id);
    $stmt->execute();
}
header("Location: " . $_SERVER['HTTP_REFERER']);
exit;
?> 
*/



session_start();
require 'db.php'; // Your database connection

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['log_id'])) {
    $log_id = intval($_POST['log_id']);
    $user_id = $_SESSION['user_id']; // Assuming you have user_id in session

    // Prepare statement to update the notification status
    $stmt = $conn->prepare("UPDATE activity_logs SET is_read = 1 WHERE id = ?"); // Add user_id for security but I don't think it is necessary so I cut it out
    $stmt->bind_param("i", $log_id);

    if ($stmt->execute()) {
        // Successfully marked as read
        // Redirect back to the page where the notification was clicked, or dashboard
        header('Location: ' . $_SERVER['HTTP_REFERER'] ?? 'dashboard.php');
        exit;
    } else {
        // Handle error, e.g., log it or show a message
        error_log("Failed to mark notification as read: " . $stmt->error);
        // Optionally redirect with an error message
        header('Location: ' . $_SERVER['HTTP_REFERER'] . '?error=mark_read_failed');
        exit;
    }
} else {
    // Invalid request
    header('Location: dashboard.php');
    exit;
}
?>