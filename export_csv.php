<?php
include 'db.php';

$project_id = $_POST['project_id'];

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="test_cases.csv"');

$output = fopen("php://output", "w");
fputcsv($output, ['Title', 'Steps', 'Expected', 'Status']);

$result = $conn->query("SELECT title, steps, expected, status FROM test_cases WHERE project_id=$project_id");
while ($row = $result->fetch_assoc()) {
    fputcsv($output, $row);
}
fclose($output);
exit;
