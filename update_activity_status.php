<?php
session_start();
include 'db.php';
include 'status_recalculator.php'; // Include the new recalculator file

header('Content-Type: application/json');
$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_role = $_SESSION['system_role'] ?? 'guest';
    // Add your role check for activities here

    $activityId = filter_input(INPUT_POST, 'activity_id', FILTER_VALIDATE_INT);
    $newStatus = filter_input(INPUT_POST, 'new_status', FILTER_SANITIZE_STRING);

    // ... Validation for activityId and newStatus ...

    $conn->begin_transaction();

    try {
        // Get the parent phase_id
        $stmt_get_phase = $conn->prepare("SELECT phase_id FROM activities WHERE id = ?");
        $stmt_get_phase->bind_param("i", $activityId);
        $stmt_get_phase->execute();
        $activity_data = $stmt_get_phase->get_result()->fetch_assoc();
        $phaseId = $activity_data['phase_id'] ?? null;
        $stmt_get_phase->close();

        if (!$phaseId) {
            throw new Exception("Activity's Parent Phase ID not found. Cannot proceed.");
        }

        // Update the activity status
        $stmt_update_activity = $conn->prepare("UPDATE activities SET status = ? WHERE id = ?");
        $stmt_update_activity->bind_param("si", $newStatus, $activityId);
        if (!$stmt_update_activity->execute()) {
            throw new Exception("Failed to update activity status: " . $stmt_update_activity->error);
        }
        $stmt_update_activity->close();

        // --- Start cascading up from activity to phase to project ---
        $currentParentId = $phaseId; // Start with the immediate parent
        $level = 'phase';

        while ($currentParentId !== null) {
            $result = ['status_changed' => false];
            $nextParentId = null;

            if ($level === 'phase') {
                $result = recalculatePhaseStatus($conn, $currentParentId);
                $nextParentId = $result['parent_id'];
                $level = 'project';
            } elseif ($level === 'project') {
                $result = recalculateProjectStatus($conn, $currentParentId);
                $nextParentId = null;
            } else {
                $currentParentId = null;
                continue;
            }

            if (!$result['status_changed']) {
                $currentParentId = null; // Stop cascading if status didn't change
            } else {
                $currentParentId = $nextParentId;
            }
        }

        $conn->commit();
        $response['success'] = true;
        $response['message'] = 'Activity status and parent statuses updated successfully.';

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