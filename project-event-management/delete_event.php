<?php
require_once 'config/database.php';
require_once 'config/functions.php';
checkAuth();

if (isset($_GET['id'])) {
    $eventId = intval($_GET['id']);
    
    // Check if user has permission to delete
    $sql = "SELECT organizer_id FROM events WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $eventId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $event = mysqli_fetch_assoc($result);
    
    if ($event && ($_SESSION['user_id'] == $event['organizer_id'] || hasRole('super_admin') || hasRole('admin'))) {
        // Delete the event (cascade will delete related records)
        $deleteSql = "DELETE FROM events WHERE id = ?";
        $deleteStmt = mysqli_prepare($conn, $deleteSql);
        mysqli_stmt_bind_param($deleteStmt, "i", $eventId);
        
        if (mysqli_stmt_execute($deleteStmt)) {
            $_SESSION['message'] = 'Event deleted successfully';
        } else {
            $_SESSION['error'] = 'Error deleting event: ' . mysqli_error($conn);
        }
    } else {
        $_SESSION['error'] = 'You do not have permission to delete this event';
    }
}

header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'dashboard.php'));
exit();
?>