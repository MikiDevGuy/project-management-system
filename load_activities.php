<?php
// load_activities.php
session_start(); // Ensure session is started for security checks
if (!isset($_SESSION['user_id'])) {
    http_response_code(401); // Unauthorized
    echo json_encode(['error' => 'Unauthorized access.']);
    exit();
}

include 'db.php'; // Your database connection file

header('Content-Type: application/json'); // Ensure JSON header is sent

$phase_id = $_GET['phase_id'] ?? null;

if (!$phase_id) {
    http_response_code(400); // Bad Request
    echo json_encode(['error' => 'Phase ID is required.']);
    exit();
}

// Determine if this is for Gantt (needs minimal data) or hierarchy table (needs full data)
// The 'full_data' parameter from the JS `fetchHierarchyActivities` will be present.
$isForGantt = !isset($_GET['full_data']); // If 'full_data' is NOT set, it's for Gantt.

$activities = [];

if ($isForGantt) {
    // Original Gantt-compatible format - minimal data for performance
    $stmt = $conn->prepare("
        SELECT id, name, start_date, end_date, status 
        FROM activities 
        WHERE phase_id = ?
        ORDER BY start_date, name
    ");
    $stmt->bind_param("i", $phase_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $row['progress'] = ($row['status'] === 'completed') ? 100 : 
                           ($row['status'] === 'in_progress' ? 50 : 0);
        // Ensure that these activities can be properly linked in the Gantt chart
        // For Gantt, 'dependencies' and 'type' might be handled on JS side or pulled here if needed.
        // For now, only providing core data.
        $activities[] = $row;
    }
} else {
    // Enhanced format for hierarchy table - includes assigned users
    // Assuming 'activity_assignments' is the junction table linking 'activities' to 'users'.
    $stmt = $conn->prepare("
        SELECT 
            a.id,
            a.name,
            a.start_date,
            a.end_date,
            a.status,
            a.priority,
            GROUP_CONCAT(DISTINCT u.name ORDER BY u.name SEPARATOR ', ') AS assigned_users
        FROM activities a
        LEFT JOIN activity_assignments aa ON a.id = aa.activity_id
        LEFT JOIN users u ON aa.user_id = u.id
        WHERE a.phase_id = ?
        GROUP BY a.id, a.name, a.start_date, a.end_date, a.status, a.priority
        ORDER BY a.name
    ");
    $stmt->bind_param("i", $phase_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        // Ensure consistent field names that frontend expects
        // 'people' in JS should map to 'assigned_users' from DB
        $row['assigned_users'] = $row['assigned_users'] ?? 'Unassigned'; // Default if no users found
        $activities[] = $row;
    }
}

echo json_encode($activities);
$conn->close();
?>