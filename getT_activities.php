<?php
include 'db.php'; // Your database connection file

header('Content-Type: application/json'); // Tell the browser to expect JSON

$phaseId = filter_input(INPUT_GET, 'phase_id', FILTER_VALIDATE_INT);

$activities = [];

if ($phaseId) {
    // Prepare statement to fetch activities for the given phase ID
    $stmt = $conn->prepare("SELECT id, name FROM activities WHERE phase_id = ? ORDER BY name ASC");
    if ($stmt) {
        $stmt->bind_param("i", $phaseId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $activities[] = $row;
        }
        $stmt->close();
    }
}

echo json_encode($activities); // Output the activities as JSON
$conn->close();
?>