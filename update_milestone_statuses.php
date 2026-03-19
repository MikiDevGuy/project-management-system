<?php
// update_milestone_statuses.php
include 'db.php';

try {
    // Update delayed milestones
    $delayed_result = $conn->query("UPDATE milestones SET status = 'delayed' 
                                  WHERE status = 'pending' AND target_date < CURDATE()");
    
    if (!$delayed_result) {
        throw new Exception("Failed to update delayed milestones: " . $conn->error);
    }
    
    $delayed_count = $conn->affected_rows;

    // Auto-achieve milestones when linked activities are completed
    $activity_result = $conn->query("UPDATE milestones m 
                                   JOIN activities a ON m.activity_id = a.id 
                                   SET m.status = 'achieved', m.achieved_date = CURDATE()
                                   WHERE m.status IN ('pending', 'delayed') AND a.status = 'completed'");
    
    if (!$activity_result) {
        throw new Exception("Failed to update activity-based milestones: " . $conn->error);
    }
    
    $activity_count = $conn->affected_rows;

    // Auto-achieve milestones when linked phases are completed
    $phase_result = $conn->query("UPDATE milestones m 
                                JOIN phases p ON m.phase_id = p.id 
                                SET m.status = 'achieved', m.achieved_date = CURDATE()
                                WHERE m.status IN ('pending', 'delayed') AND p.status = 'completed'");
    
    if (!$phase_result) {
        throw new Exception("Failed to update phase-based milestones: " . $conn->error);
    }
    
    $phase_count = $conn->affected_rows;

    // Auto-achieve project-level milestones when all phases are completed
    $project_result = $conn->query("UPDATE milestones m 
                                  SET m.status = 'achieved', m.achieved_date = CURDATE()
                                  WHERE m.phase_id IS NULL AND m.activity_id IS NULL 
                                  AND m.status IN ('pending', 'delayed')
                                  AND NOT EXISTS (
                                      SELECT 1 FROM phases p 
                                      WHERE p.project_id = m.project_id 
                                      AND p.status != 'completed'
                                  )");
    
    if (!$project_result) {
        throw new Exception("Failed to update project-based milestones: " . $conn->error);
    }
    
    $project_count = $conn->affected_rows;

    echo json_encode([
        'success' => true,
        'message' => 'Milestone statuses updated successfully',
        'updates' => [
            'delayed' => $delayed_count,
            'from_activities' => $activity_count,
            'from_phases' => $phase_count,
            'from_projects' => $project_count
        ]
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

$conn->close();
?>