<?php
session_start();
require 'db.php';
$active_dashboard = 'testcase_management';
include 'sidebar.php';
//I use this for separating back to link as per the role 
$role = $_SESSION['system_role'];
$user_id = $_SESSION['user_id'];//it will help us to detect who does the import action

require 'vendor/autoload.php'; //here you should install the composer

use PhpOffice\PhpSpreadsheet\IOFactory;

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['excel_file'])) {
    $file = $_FILES['excel_file']['tmp_name'];

    $spreadsheet = IOFactory::load($file);
    $sheet = $spreadsheet->getActiveSheet();
    $rows = $sheet->toArray();

    // Assuming the first row is header
    unset($rows[0]);

    $inserted = 0;
    $skipped = 0;

    foreach ($rows as $row) {
        list(
            $projectName, $featureName,  $title, $steps, $expected, $status,$priority,
            $frequency, $channel, $tester_remark, $vendor_comment, $created_by,$created_at
        ) = array_pad($row, 13, '');

// Normalize ENUM values
    $status = ucfirst(strtolower($status));
    $priority = ucfirst(strtolower($priority));
    // Validate ENUMs
    if (!in_array($status, ['Pending', 'Pass', 'Fail', 'Deferred']) || 
        !in_array($priority, ['High', 'Medium', 'Low'])) {
        $skipped++;
        continue;
    }

    
        // 🔍 Find project ID
        //$project_stmt = $conn->prepare("SELECT id FROM projects WHERE name = ?");
        //$project_stmt->bind_param("s", $projectName);
        // For project lookup
$projectName = trim($projectName);
$project_stmt = $conn->prepare("SELECT id FROM projects WHERE LOWER(name) = LOWER(?)");
$project_stmt->bind_param("s", $projectName);
        $project_stmt->execute();
        $project_result = $project_stmt->get_result();
        $project = $project_result->fetch_assoc();

        if (!$project) {
    echo "<p class='text-danger'>⚠️ Skipped: Project '$projectName' not found in database</p>";
    $skipped++;
    continue;
            }


        $project_id = $project['id'];

        // 🔍 Find feature ID under this project
        //$feature_stmt = $conn->prepare("SELECT id FROM features WHERE feature_name = ? AND project_id = ?");
        //$feature_stmt->bind_param("si", $featureName, $project_id);
        $featureName = trim($featureName);
$feature_stmt = $conn->prepare("SELECT id FROM features WHERE LOWER(feature_name) = LOWER(?) AND project_id = ?");
$feature_stmt->bind_param("si", $featureName, $project_id);
        $feature_stmt->execute();
        $feature_result = $feature_stmt->get_result();
        $feature = $feature_result->fetch_assoc();

        if (!$feature) {
    echo "<p class='text-danger'>⚠️ Skipped: Feature '$featureName' not found in project '$projectName' (ID: $project_id)</p>";
    $skipped++;
    continue;
        }
        $feature_id = $feature['id'];
        // Handle created_at
    if (empty($created_at) || !strtotime($created_at)) {
        $created_at = date('Y-m-d H:i:s');
    }

        // Insert into test_cases table
        $insert_stmt = $conn->prepare("INSERT INTO test_cases (
            project_id, feature_id, title, steps, expected, status,
            priority, frequency, channel, tester_remark, vendor_comment,
            created_by, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $insert_stmt->bind_param(
            "iisssssssssss",
            $project_id, $feature_id, $title, $steps, $expected, $status,
            $priority, $frequency, $channel, $tester_remark, $vendor_comment,
            $user_id, $created_at
        );

        if ($insert_stmt->execute()) {
            $inserted++;
        } else {
            $skipped++;
             error_log("Failed to insert row: " . $insert_stmt->error);
            // Optionally, for debugging during development, you could echo it:
             echo "<p>Error inserting row: " . $insert_stmt->error . "</p>";
        }
    }
   

    echo "<h4>✅ Import Finished</h4>";
    echo "<p>✅ $inserted test cases inserted</p>";
    echo "<p>⚠️ $skipped rows skipped (missing project or feature)</p>";
    error_log("Import Error - Project '$projectName' or Feature '$featureName' not found");
    echo '<a href="dashboard.php">← Back to Dashboard</a>';
    exit;
}
?>



<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>📥 Import Test Cases</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    .upload-card {
      max-width: 600px;
      margin: 0 auto;
      box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
      border-radius: 10px;
    }
    .upload-header {
      background-color: #f8f9fa;
      border-bottom: 1px solid #eee;
      border-radius: 10px 10px 0 0 !important;
    }
    .btn-import {
      transition: all 0.3s;
    }
    .btn-import:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 8px rgba(0, 123, 255, 0.3);
    }
  </style>
</head>
<body class="bg-light">
  <div class="container py-5">
    <div class="upload-card card">
      <div class="upload-header card-header">
        <h3 class="mb-0 text-center"><i class="fas fa-file-import me-2"></i>Import Test Cases from Excel</h3>
      </div>
      <div class="card-body p-4">
        <form method="post" enctype="multipart/form-data">
          <div class="mb-4">
            <label class="form-label fw-bold">Upload Excel File</label>
            <div class="input-group">
              <input type="file" name="excel_file" class="form-control" accept=".xlsx,.xls" required>
              <span class="input-group-text"><i class="fas fa-file-excel"></i></span>
            </div>
            <div class="form-text">Supported formats: .xlsx, .xls</div>
          </div>
          <div class="d-grid gap-2">
            <button type="submit" class="btn btn-primary btn-import py-2">
              <i class="fas fa-upload me-2"></i>Import
            </button>
          </div>
        </form>
        <p class="mt-3 text-muted">
            Expected columns: <strong>projectName, featureName, Testcase_title, steps, expected, status, priority,	frequency, channel, tester_remark, vendor_comment, created by, created_at
                              </strong><br>
            Example: <code>My Project</code>, <code>Login Feature</code>, <code>Handles login flow</code>, <code>First login to the mobile app ...</code>, <code>It should logged in</code>, <code>Pending</code>, <code>High</code>,
            <code>Once a week</code>, <code>Mobile</code>, <code>forget password part doesn't work</code>, <code>we have fixed the the forget password case. Try it now</code>, <code>Abebe</code>, <code>current date</code>
        </p>
        </p>
        </p>
        </p>
        </p>
        </p>
      </div>
      <div class="card-footer bg-white text-center">
        <?php if($role == 'tester' || $role == 'viewer'): ?>
          <a href="assigned_projects.php" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-1"></i> Back to Project
          </a>
        <?php else: ?>
          <a href="admin_projects.php?id" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-1"></i> Back to Project
          </a>
        <?php endif; ?>
      </div>
    </div>
  </div>
  
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>