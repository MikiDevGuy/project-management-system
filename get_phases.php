





<?php
session_start();
include 'db.php';

header('Content-Type: application/json');

$project_id = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;

if ($project_id > 0) {
    $query = "SELECT id, name FROM phases WHERE project_id = ? ORDER BY name";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $phases = [];
    while ($row = $result->fetch_assoc()) {
        $phases[] = $row;
    }
    
    echo json_encode($phases);
} else {
    echo json_encode([]);
}
?>





























<?php include 'db.php'; 
/*
include 'db.php';

header('Content-Type: application/json');

$project_id = $_GET['project_id'] ?? 0;
$phases = [];

if ($project_id) {
    $stmt = $conn->prepare("SELECT id, name FROM phases WHERE project_id = ? ORDER BY name");
    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $phases = $result->fetch_all(MYSQLI_ASSOC);
}

echo json_encode($phases); it was the first code ????  

header('Content-Type: application/json');

if (!isset($_GET['project_id'])) {
    die(json_encode(['error' => 'Project ID required']));
}

$project_id = (int)$_GET['project_id'];
$phases = [];

$sql = "SELECT id, name, start_date, end_date FROM phases WHERE project_id = ? ORDER BY name";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $project_id);
$stmt->execute();
$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) {
    $phases[] = [
        'id' => $row['id'], // Return raw ID without 'phase_' prefix
        'name' => $row['name'],
        'start_date' => $row['start_date'],
        'end_date' => $row['end_date']
    ];
}

echo json_encode($phases);
?>
*/

