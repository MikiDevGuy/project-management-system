<?php
include 'db.php'; // Your database connection file

header('Content-Type: application/json'); // Tell the browser to expect JSON

$projectId = filter_input(INPUT_GET, 'project_id', FILTER_VALIDATE_INT);

$phases = [];

if ($projectId) {
    // Prepare statement to fetch phases for the given project ID
    $stmt = $conn->prepare("SELECT id, name FROM phases WHERE project_id = ? ORDER BY name ASC");
    if ($stmt) {
        $stmt->bind_param("i", $projectId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $phases[] = $row;
        }
        $stmt->close();
    }
}

echo json_encode($phases); // Output the phases as JSON
$conn->close();
?>