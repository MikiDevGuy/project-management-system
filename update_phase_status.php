<?php
session_start();
include 'db.php';
include 'status_recalculator.php'; // Include the new recalculator file

header('Content-Type: application/json');
$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_role = $_SESSION['system_role'] ?? 'guest';
    // Add your role check for phases here

    $phaseId = filter_input(INPUT_POST, 'phase_id', FILTER_VALIDATE_INT);
    $newStatus = filter_input(INPUT_POST, 'new_status', FILTER_SANITIZE_STRING);

    // ... Validation for phaseId and newStatus ...

    $conn->begin_transaction();

    try {
        // Get the parent project_id
        $stmt_get_project = $conn->prepare("SELECT project_id FROM phases WHERE id = ?");
        $stmt_get_project->bind_param("i", $phaseId);
        $stmt_get_project->execute();
        $phase_data = $stmt_get_project->get_result()->fetch_assoc();
        $projectId = $phase_data['project_id'] ?? null;
        $stmt_get_project->close();

        if (!$projectId) {
            throw new Exception("Phase's Parent Project ID not found. Cannot proceed.");
        }

        // Update the phase status
        $stmt_update_phase = $conn->prepare("UPDATE phases SET status = ? WHERE id = ?");
        $stmt_update_phase->bind_param("si", $newStatus, $phaseId);
        if (!$stmt_update_phase->execute()) {
            throw new Exception("Failed to update phase status: " . $stmt_update_phase->error);
        }
        $stmt_update_phase->close();

        // --- Start cascading up from phase to project ---
        $currentParentId = $projectId; // Start with the immediate parent
        $level = 'project';

        while ($currentParentId !== null) {
            $result = ['status_changed' => false];
            $nextParentId = null;

            if ($level === 'project') {
                $result = recalculateProjectStatus($conn, $currentParentId);
                $nextParentId = null; // Project is the top level
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
        $response['message'] = 'Phase status and parent project status updated successfully.';

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