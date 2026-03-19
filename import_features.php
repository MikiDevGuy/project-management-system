<?php
session_start();
require 'db.php';
$active_dashboard = 'testcase_management';
include 'sidebar.php';

//I use this for separating back to link as per the role 
$role = $_SESSION['system_role'];

// Include PhpSpreadsheet
//require 'vendor/autoload.php'; // this feature must be uncomment I just comment for now till I download the composer
use PhpOffice\PhpSpreadsheet\IOFactory;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['excel_file'])) {
    $file = $_FILES['excel_file']['tmp_name'];

    try {
        $spreadsheet = IOFactory::load($file);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray();

        $successCount = 0;
        $skippedRows = [];

        // Skip header row (assuming row 0)
        for ($i = 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            $projectName = trim($row[0]);
            $featureName = trim($row[1]);
            $description = trim($row[2]);
            $status = trim($row[3]) ?: 'Pending';

            if (empty($projectName) || empty($featureName)) {
                $skippedRows[] = $i + 1;
                continue;
            }

            // Get project_id from project name
            $stmt = $conn->prepare("SELECT id FROM projects WHERE name = ?");
            $stmt->bind_param("s", $projectName);
            $stmt->execute();
            $result = $stmt->get_result();
            $project = $result->fetch_assoc();

            if (!$project) {
                $skippedRows[] = $i + 1;
                continue;
            }

            $project_id = $project['id'];

            // Insert into features
            $stmt = $conn->prepare("
                INSERT INTO features (project_id, feature_name, description, status, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt->bind_param("isss", $project_id, $featureName, $description, $status);
            $stmt->execute();

            $successCount++;
        }

        echo "<h3>✅ Import Complete</h3>";
        echo "<p><strong>Inserted:</strong> $successCount features.</p>";
        if ($skippedRows) {
            echo "<p style='color:red;'>⚠️ Skipped rows: " . implode(', ', $skippedRows) . " (missing data or invalid project)</p>";
        }

    } catch (Exception $e) {
        echo "❌ Error reading file: " . $e->getMessage();
    }

    echo "<p><a href='features.php'>⬅️ Back to Features</a></p>";
    exit;
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>📥 Import Features</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="p-4">
    <div class="container">
        <h2 class="mb-4">📤 Import Features from Excel</h2>
        <form method="POST" enctype="multipart/form-data">
            <div class="mb-3">
                <label for="excel_file" class="form-label">Upload Excel File (.xlsx)</label>
                <input type="file" class="form-control" name="excel_file" required accept=".xlsx, .xls">
            </div>
            <button type="submit" class="btn btn-primary">📥 Import Features</button>
        </form>
        <p class="mt-3 text-muted">
            Expected columns: <strong>Project Name, Feature Name, Description, Status</strong><br>
            Example: <code>My Project</code>, <code>Login Feature</code>, <code>Handles login flow</code>, <code>Pending</code>
        </p>
    </div>
    
</body>
</html>
