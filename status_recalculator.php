<?php
// status_recalculator.php

// This file contains functions to recalculate parent statuses based on child statuses.
// It assumes $conn is available (from db.php) and a transaction has been started in the calling script.

/**
 * Recalculates and updates the status of a parent activity based on its sub-activities.
 *
 * @param mysqli $conn The database connection.
 * @param int $activityId The ID of the activity to recalculate.
 * @return array An array containing 'status_changed' (boolean) and 'new_status' (string).
 * @throws Exception If a database error occurs.
 */
function recalculateActivityStatus($conn, $activityId) {
    // Count total sub-activities for this activity
    $stmt_total_sub = $conn->prepare("SELECT COUNT(*) AS total FROM sub_activities WHERE activity_id = ?");
    if (!$stmt_total_sub) {
        throw new Exception("Failed to prepare total sub-activities statement: " . $conn->error);
    }
    $stmt_total_sub->bind_param("i", $activityId);
    $stmt_total_sub->execute();
    $result_total_sub = $stmt_total_sub->get_result();
    $total_sub_activities_row = $result_total_sub->fetch_assoc();
    $totalSubActivities = $total_sub_activities_row['total'];
    $stmt_total_sub->close();

    // Determine new status based on children
    $newActivityStatus = 'pending'; // Default if no sub-activities or none started

    if ($totalSubActivities > 0) {
        // Count completed sub-activities
        $stmt_completed_sub = $conn->prepare("SELECT COUNT(*) AS completed FROM sub_activities WHERE activity_id = ? AND status = 'completed'");
        if (!$stmt_completed_sub) {
            throw new Exception("Failed to prepare completed sub-activities statement: " . $conn->error);
        }
        $stmt_completed_sub->bind_param("i", $activityId);
        $stmt_completed_sub->execute();
        $result_completed_sub = $stmt_completed_sub->get_result();
        $completed_sub_activities_row = $result_completed_sub->fetch_assoc();
        $completedSubActivities = $completed_sub_activities_row['completed'];
        $stmt_completed_sub->close();

        if ($totalSubActivities == $completedSubActivities) {
            $newActivityStatus = 'completed';
        } elseif ($completedSubActivities > 0 || $completedSubActivities < $totalSubActivities) {
            // Check if any sub-activities are in_progress or pending
            $stmt_in_progress_sub = $conn->prepare("SELECT COUNT(*) AS in_progress FROM sub_activities WHERE activity_id = ? AND status = 'in_progress'");
            $stmt_in_progress_sub->bind_param("i", $activityId);
            $stmt_in_progress_sub->execute();
            $result_in_progress_sub = $stmt_in_progress_sub->get_result();
            $in_progress_sub_row = $result_in_progress_sub->fetch_assoc();
            $inProgressSubActivities = $in_progress_sub_row['in_progress'];
            $stmt_in_progress_sub->close();

            if ($inProgressSubActivities > 0 || $completedSubActivities > 0) {
                $newActivityStatus = 'in_progress';
            } else {
                // All are pending or cancelled
                $newActivityStatus = 'pending'; // Or 'on_hold' etc. if your activities have more statuses
            }
        }
    }

    // Get current activity status
    $stmt_current_status = $conn->prepare("SELECT status, phase_id FROM activities WHERE id = ?");
    if (!$stmt_current_status) {
        throw new Exception("Failed to prepare current activity status statement: " . $conn->error);
    }
    $stmt_current_status->bind_param("i", $activityId);
    $stmt_current_status->execute();
    $result_current_status = $stmt_current_status->get_result();
    $activity_data = $result_current_status->fetch_assoc();
    $currentActivityStatus = $activity_data['status'] ?? '';
    $phaseId = $activity_data['phase_id'] ?? null;
    $stmt_current_status->close();

    $status_changed = false;
    if ($newActivityStatus !== $currentActivityStatus) {
        $stmt_update = $conn->prepare("UPDATE activities SET status = ? WHERE id = ?");
        if (!$stmt_update) {
            throw new Exception("Failed to prepare activity update statement: " . $conn->error);
        }
        $stmt_update->bind_param("si", $newActivityStatus, $activityId);
        if (!$stmt_update->execute()) {
            throw new Exception("Failed to update activity status: " . $stmt_update->error);
        }
        $stmt_update->close();
        $status_changed = true;
    }

    return ['status_changed' => $status_changed, 'new_status' => $newActivityStatus, 'parent_id' => $phaseId];
}

/**
 * Recalculates and updates the status of a parent phase based on its activities.
 *
 * @param mysqli $conn The database connection.
 * @param int $phaseId The ID of the phase to recalculate.
 * @return array An array containing 'status_changed' (boolean) and 'new_status' (string).
 * @throws Exception If a database error occurs.
 */
function recalculatePhaseStatus($conn, $phaseId) {
    // Count total activities for this phase
    $stmt_total_act = $conn->prepare("SELECT COUNT(*) AS total FROM activities WHERE phase_id = ?");
    if (!$stmt_total_act) {
        throw new Exception("Failed to prepare total activities statement: " . $conn->error);
    }
    $stmt_total_act->bind_param("i", $phaseId);
    $stmt_total_act->execute();
    $result_total_act = $stmt_total_act->get_result();
    $total_activities_row = $result_total_act->fetch_assoc();
    $totalActivities = $total_activities_row['total'];
    $stmt_total_act->close();

    // Determine new status based on children
    $newPhaseStatus = 'pending'; // Default if no activities or none started

    if ($totalActivities > 0) {
        // Count completed activities
        $stmt_completed_act = $conn->prepare("SELECT COUNT(*) AS completed FROM activities WHERE phase_id = ? AND status = 'completed'");
        if (!$stmt_completed_act) {
            throw new Exception("Failed to prepare completed activities statement: " . $conn->error);
        }
        $stmt_completed_act->bind_param("i", $phaseId);
        $stmt_completed_act->execute();
        $result_completed_act = $stmt_completed_act->get_result();
        $completed_activities_row = $result_completed_act->fetch_assoc();
        $completedActivities = $completed_activities_row['completed'];
        $stmt_completed_act->close();

        if ($totalActivities == $completedActivities) {
            $newPhaseStatus = 'completed';
        } elseif ($completedActivities > 0 || $completedActivities < $totalActivities) {
             // Check if any activities are in_progress or pending
            $stmt_in_progress_act = $conn->prepare("SELECT COUNT(*) AS in_progress FROM activities WHERE phase_id = ? AND status = 'in_progress'");
            $stmt_in_progress_act->bind_param("i", $phaseId);
            $stmt_in_progress_act->execute();
            $result_in_progress_act = $stmt_in_progress_act->get_result();
            $in_progress_act_row = $result_in_progress_act->fetch_assoc();
            $inProgressActivities = $in_progress_act_row['in_progress'];
            $stmt_in_progress_act->close();

            if ($inProgressActivities > 0 || $completedActivities > 0) {
                $newPhaseStatus = 'in_progress';
            } else {
                $newPhaseStatus = 'pending';
            }
        }
    }

    // Get current phase status
    $stmt_current_status = $conn->prepare("SELECT status, project_id FROM phases WHERE id = ?");
    if (!$stmt_current_status) {
        throw new Exception("Failed to prepare current phase status statement: " . $conn->error);
    }
    $stmt_current_status->bind_param("i", $phaseId);
    $stmt_current_status->execute();
    $result_current_status = $stmt_current_status->get_result();
    $phase_data = $result_current_status->fetch_assoc();
    $currentPhaseStatus = $phase_data['status'] ?? '';
    $projectId = $phase_data['project_id'] ?? null;
    $stmt_current_status->close();

    $status_changed = false;
    if ($newPhaseStatus !== $currentPhaseStatus) {
        $stmt_update = $conn->prepare("UPDATE phases SET status = ? WHERE id = ?");
        if (!$stmt_update) {
            throw new Exception("Failed to prepare phase update statement: " . $conn->error);
        }
        $stmt_update->bind_param("si", $newPhaseStatus, $phaseId);
        if (!$stmt_update->execute()) {
            throw new Exception("Failed to update phase status: " . $stmt_update->error);
        }
        $stmt_update->close();
        $status_changed = true;
    }

    return ['status_changed' => $status_changed, 'new_status' => $newPhaseStatus, 'parent_id' => $projectId];
}

/**
 * Recalculates and updates the status of a parent project based on its phases.
 *
 * @param mysqli $conn The database connection.
 * @param int $projectId The ID of the project to recalculate.
 * @return array An array containing 'status_changed' (boolean) and 'new_status' (string).
 * @throws Exception If a database error occurs.
 */
function recalculateProjectStatus($conn, $projectId) {
    // Count total phases for this project
    $stmt_total_phase = $conn->prepare("SELECT COUNT(*) AS total FROM phases WHERE project_id = ?");
    if (!$stmt_total_phase) {
        throw new Exception("Failed to prepare total phases statement: " . $conn->error);
    }
    $stmt_total_phase->bind_param("i", $projectId);
    $stmt_total_phase->execute();
    $result_total_phase = $stmt_total_phase->get_result();
    $total_phases_row = $result_total_phase->fetch_assoc();
    $totalPhases = $total_phases_row['total'];
    $stmt_total_phase->close();

    // Determine new status based on children
    $newProjectStatus = 'pending'; // Default if no phases or none started

    if ($totalPhases > 0) {
        // Count completed phases
        $stmt_completed_phase = $conn->prepare("SELECT COUNT(*) AS completed FROM phases WHERE project_id = ? AND status = 'completed'");
        if (!$stmt_completed_phase) {
            throw new Exception("Failed to prepare completed phases statement: " . $conn->error);
        }
        $stmt_completed_phase->bind_param("i", $projectId);
        $stmt_completed_phase->execute();
        $result_completed_phase = $stmt_completed_phase->get_result();
        $completed_phases_row = $result_completed_phase->fetch_assoc();
        $completedPhases = $completed_phases_row['completed'];
        $stmt_completed_phase->close();

        if ($totalPhases == $completedPhases) {
            $newProjectStatus = 'completed';
        } elseif ($completedPhases > 0 || $completedPhases < $totalPhases) {
            // Check if any phases are in_progress or pending
            $stmt_in_progress_phase = $conn->prepare("SELECT COUNT(*) AS in_progress FROM phases WHERE project_id = ? AND status = 'in_progress'");
            $stmt_in_progress_phase->bind_param("i", $projectId);
            $stmt_in_progress_phase->execute();
            $result_in_progress_phase = $stmt_in_progress_phase->get_result();
            $in_progress_phase_row = $result_in_progress_phase->fetch_assoc();
            $inProgressPhases = $in_progress_phase_row['in_progress'];
            $stmt_in_progress_phase->close();

            if ($inProgressPhases > 0 || $completedPhases > 0) {
                $newProjectStatus = 'in_progress';
            } else {
                $newProjectStatus = 'pending';
            }
        }
    }

    // Get current project status (projects can also have manual overrides)
    $stmt_current_status = $conn->prepare("SELECT status FROM projects WHERE id = ?");
    if (!$stmt_current_status) {
        throw new Exception("Failed to prepare current project status statement: " . $conn->error);
    }
    $stmt_current_status->bind_param("i", $projectId);
    $stmt_current_status->execute();
    $result_current_status = $stmt_current_status->get_result();
    $project_data = $result_current_status->fetch_assoc();
    $currentProjectStatus = $project_data['status'] ?? '';
    $stmt_current_status->close();

    $status_changed = false;
    if ($newProjectStatus !== $currentProjectStatus) {
        $stmt_update = $conn->prepare("UPDATE projects SET status = ? WHERE id = ?");
        if (!$stmt_update) {
            throw new Exception("Failed to prepare project update statement: " . $conn->error);
        }
        $stmt_update->bind_param("si", $newProjectStatus, $projectId);
        if (!$stmt_update->execute()) {
            throw new Exception("Failed to update project status: " . $stmt_update->error);
        }
        $stmt_update->close();
        $status_changed = true;
    }

    return ['status_changed' => $status_changed, 'new_status' => $newProjectStatus, 'parent_id' => null]; // Project has no parent
}

?>