<?php
session_start();
include 'db.php'; // Your database connection file
include 'status_recalculator.php'; // Include the new recalculator file

header('Content-Type: application/json');
$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_role = $_SESSION['system_role'] ?? 'guest';
    if ($user_role !== 'pm_employee' && $user_role !== 'super_admin' && $user_role !== 'pm_manager') {
        $response['message'] = 'Unauthorized: Insufficient permissions to update sub-activity status.';
        echo json_encode($response);
        exit();
    }

    $sub_activityId = filter_input(INPUT_POST, 'sub_activity_id', FILTER_VALIDATE_INT);
    $newStatus = filter_input(INPUT_POST, 'new_status', FILTER_SANITIZE_STRING);

    if ($sub_activityId === null || $sub_activityId === false || empty($newStatus)) {
        $response['message'] = 'Invalid Sub-Activity ID or New Status provided.';
        echo json_encode($response);
        exit();
    }

    $allowed_sub_activity_statuses = ['pending', 'in_progress', 'completed', 'cancelled'];
    if (!in_array($newStatus, $allowed_sub_activity_statuses)) {
        $response['message'] = 'Invalid sub-activity status value provided.';
        echo json_encode($response);
        exit();
    }

    $conn->begin_transaction();

    try {
        // Get the parent activity_id
        $stmt_get_activity = $conn->prepare("SELECT activity_id FROM sub_activities WHERE id = ?");
        $stmt_get_activity->bind_param("i", $sub_activityId);
        $stmt_get_activity->execute();
        $sub_activity_data = $stmt_get_activity->get_result()->fetch_assoc();
        $activityId = $sub_activity_data['activity_id'] ?? null;
        $stmt_get_activity->close();

        if (!$activityId) {
            throw new Exception("Sub-activity's Parent Activity ID not found. Cannot proceed.");
        }

        // Update the sub-activity status
        $stmt_update_sub_activity = $conn->prepare("UPDATE sub_activities SET status = ? WHERE id = ?");
        $stmt_update_sub_activity->bind_param("si", $newStatus, $sub_activityId);
        if (!$stmt_update_sub_activity->execute()) {
            throw new Exception("Failed to update sub-activity status: " . $stmt_update_sub_activity->error);
        }
        $stmt_update_sub_activity->close();

        // --- Start cascading up ---
        $currentParentId = $activityId; // Start with the immediate parent
        $level = 'activity';

        while ($currentParentId !== null) {
            $result = ['status_changed' => false];
            $nextParentId = null;

            if ($level === 'activity') {
                $result = recalculateActivityStatus($conn, $currentParentId);
                $nextParentId = $result['parent_id'];
                $level = 'phase'; // Move up to the next level
            } elseif ($level === 'phase') {
                $result = recalculatePhaseStatus($conn, $currentParentId);
                $nextParentId = $result['parent_id'];
                $level = 'project'; // Move up to the next level
            } elseif ($level === 'project') {
                // This is the top level, recalculate project status
                $result = recalculateProjectStatus($conn, $currentParentId);
                $nextParentId = null; // No parent above project
            } else {
                // Should not happen, break loop
                $currentParentId = null;
                continue;
            }

            // If the status of the current parent (activity, phase, or project) didn't change,
            // or if it's the project level, we can stop the cascade.
            // However, we want to ensure *all* levels are re-evaluated if a child causes a change.
            // The logic `if ($result['status_changed'])` here would stop the cascade if a parent status
            // does *not* change. If you want it to always check all the way up, even if
            // an intermediate status doesn't change, then remove this `if` block.
            // For the "revert grandparent" scenario, we need it to continue if a status DID change.
            if (!$result['status_changed'] && $level !== 'project') {
                // If a parent's status didn't change, and it's not the project,
                // there's no need to propagate further up this specific chain.
                // However, for *newly added items* that cause a revert, this might still be needed.
                // Let's assume we want to propagate changes only. If a status change
                // happened, we continue. If not, we stop.
                // But for the 'add new pending sub-activity, revert grandparent' scenario,
                // the phase's status might not change if it was already in_progress,
                // but if it was 'completed', it *must* revert.
                // The `recalculateXStatus` functions already handle checking `newStatus !== currentStatus`.
                // So, if `status_changed` is false, it means the new calculated status is the same
                // as the current status, so no further updates are needed *up this specific branch*.
                // This check is fine.
                $currentParentId = null; // Stop cascading
            } else {
                $currentParentId = $nextParentId; // Move to the next parent up
            }
        }


        $conn->commit();
        $response['success'] = true;
        $response['message'] = 'Sub-activity status and parent statuses updated successfully.';

    } catch (Exception $e) {
        $conn->rollback();
        $response['message'] = 'Transaction failed: ' . $e->getMessage();
    }

} else {
    $response['message'] = 'Invalid request method. Only POST requests are allowed.';
}

echo json_encode($response);
$conn->close();
?>