<?php
session_start();
if ($_SESSION['system_role'] !== 'super_admin') {
    die("Access denied.");
}
include 'db.php';
include 'sidebar.php';

// --- Filters ---
$where = [];

if (!empty($_GET['project_id'])) {
  $project_id = intval($_GET['project_id']);
  $where[] = "test_cases.project_id = $project_id";
}

if (!empty($_GET['status'])) {
  $status = strtolower($conn->real_escape_string($_GET['status']));
  $where[] = "LOWER(test_cases.status) = '$status'";
}

if (!empty($_GET['from'])) {
  $from = $_GET['from'] . " 00:00:00";
  $where[] = "test_cases.created_at >= '$from'";
}

if (!empty($_GET['to'])) {
  $to = $_GET['to'] . " 23:59:59";
  $where[] = "test_cases.created_at <= '$to'";
}

$where_sql = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

// Total Stats (unfiltered)
$total_projects = $conn->query("SELECT COUNT(*) AS c FROM projects")->fetch_assoc()['c'];
$total_users = $conn->query("SELECT COUNT(*) AS c FROM users")->fetch_assoc()['c'];
$total_cases = $conn->query("SELECT COUNT(*) AS c FROM test_cases")->fetch_assoc()['c'];

// Chart: Test Case Status with percentages
$status_counts_query = "SELECT status, COUNT(*) AS count FROM test_cases $where_sql GROUP BY status";
$status_counts = $conn->query($status_counts_query);
$status_data = [];
$total_filtered_cases = 0;
if ($status_counts) {
  while ($row = $status_counts->fetch_assoc()) {
    $status_data[$row['status']] = $row['count'];
    $total_filtered_cases += $row['count'];
  }
}
$status_percentages = [];
foreach ($status_data as $status => $count) {
  $percentage = $total_filtered_cases > 0 ? round(($count / $total_filtered_cases) * 100, 2) : 0;
  $status_percentages[$status] = $percentage;
}

// Chart: Test Cases per Project
$project_names = [];
$project_totals = [];

$project_counts_query = "SELECT projects.name, COUNT(test_cases.id) as total FROM projects LEFT JOIN test_cases ON projects.id = test_cases.project_id ";
$filters = [];

if (!empty($_GET['status'])) {
  $status = strtolower($conn->real_escape_string($_GET['status']));
  $filters[] = "LOWER(test_cases.status) = '$status'";
}
if (!empty($_GET['from'])) {
  $from = $_GET['from'] . " 00:00:00";
  $filters[] = "test_cases.created_at >= '$from'";
}
if (!empty($_GET['to'])) {
  $to = $_GET['to'] . " 23:59:59";
  $filters[] = "test_cases.created_at <= '$to'";
}
if (!empty($_GET['project_id'])) {
  $project_id = intval($_GET['project_id']);
  $filters[] = "projects.id = $project_id";
}

if (count($filters) > 0) {
  $project_counts_query .= "WHERE " . implode(" AND ", $filters);
}
$project_counts_query .= " GROUP BY projects.id";

$project_counts = $conn->query($project_counts_query);
if ($project_counts) {
  while ($row = $project_counts->fetch_assoc()) {
    $project_names[] = $row['name'];
    $project_totals[] = $row['total'];
  }
}
?>
<!DOCTYPE html>
<html>
<head>
  <title>Admin Reports</title>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body {
      background-color: #f5f7fa;
      font-family: 'Segoe UI', sans-serif;
    }
    .card {
      border: none;
      border-radius: 1rem;
      box-shadow: 0 4px 12px rgba(0,0,0,0.05);
    }
    .header {
      background-color: #007bff;
      color: white;
      border-radius: 1rem;
      padding: 1.5rem;
      text-align: center;
      margin-bottom: 30px;
    }
    .back-link {
      margin-top: 1rem;
      display: inline-block;
      color: #007bff;
      text-decoration: none;
    }
    .back-link:hover {
      text-decoration: underline;
    }
  </style>
</head>
<body>
<div class="container mt-4">
  <a href="dashboard.php" class="back-link">← Back to Dashboard</a>

  <div class="header">
    <h2>📊 Admin Reports & Filters</h2>
    <p>Overview of projects, users, and test cases with filters</p>
  </div>

  <!-- Filter Form -->
  <form method="GET" class="row mb-4">
    <div class="col-md-3">
      <label class="form-label">Project</label>
      <select name="project_id" class="form-select">
        <option value="">All Projects</option>
        <?php
          $projects = $conn->query("SELECT id, name FROM projects");
          while ($p = $projects->fetch_assoc()) {
            $selected = ($_GET['project_id'] ?? '') == $p['id'] ? 'selected' : '';
            echo "<option value='{$p['id']}' $selected>{$p['name']}</option>";
          }
        ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">Status</label>
      <select name="status" class="form-select">
        <option value="">All Statuses</option>
        <option value="Pass" <?= ($_GET['status'] ?? '') == 'Pass' ? 'selected' : '' ?>>Passed</option>
        <option value="Fail" <?= ($_GET['status'] ?? '') == 'Fail' ? 'selected' : '' ?>>Failed</option>
        <option value="Pending" <?= ($_GET['status'] ?? '') == 'Pending' ? 'selected' : '' ?>>Pending</option>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">From Date</label>
      <input type="date" name="from" class="form-control" value="<?= $_GET['from'] ?? '' ?>">
    </div>
    <div class="col-md-3">
      <label class="form-label">To Date</label>
      <input type="date" name="to" class="form-control" value="<?= $_GET['to'] ?? '' ?>">
    </div>
    <div class="col-12 mt-3">
      <button class="btn btn-primary">Filter</button>
      <a href="report.php" class="btn btn-secondary">Reset</a>
    </div>
  </form>

  <!-- Summary Cards -->
  <div class="row mb-4 text-center">
    <div class="col-md-4 mb-3">
      <div class="card bg-primary text-white p-4">
        <h5>Total Projects</h5>
        <h2><?= $total_projects ?></h2>
      </div>
    </div>
    <div class="col-md-4 mb-3">
      <div class="card bg-success text-white p-4">
        <h5>Total Users</h5>
        <h2><?= $total_users ?></h2>
      </div>
    </div>
    <div class="col-md-4 mb-3">
      <div class="card bg-info text-white p-4">
        <h5>Total Test Cases</h5>
        <h2><?= $total_cases ?></h2>
      </div>
    </div>
  </div>

  <!-- Charts -->
  <div class="row mb-5">
    <div class="col-md-6 mb-4">
      <div class="card p-4">
        <h5 class="mb-3">📌 Test Case Status</h5>
        <canvas id="statusChart" height="250"></canvas>
        <ul class="mt-3">
          <?php foreach ($status_percentages as $status => $percent): ?>
            <li><strong><?= $status ?>:</strong> <?= $percent ?>%</li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
    <div class="col-md-6 mb-4">
      <div class="card p-4">
        <h5 class="mb-3">📂 Test Cases Per Project</h5>
        <canvas id="projectChart" height="250"></canvas>
      </div>
    </div>
  </div>
</div>

<script>
const statusChart = new Chart(document.getElementById('statusChart'), {
  type: 'pie',
  data: {
    labels: <?= json_encode(array_keys($status_data)) ?>,
    datasets: [{
      label: 'Status',
      data: <?= json_encode(array_values($status_data)) ?>,
      backgroundColor: ['#28a745', '#dc3545', '#ffc107', '#17a2b8', '#6f42c1']
    }]
  }
});

const projectChart = new Chart(document.getElementById('projectChart'), {
  type: 'bar',
  data: {
    labels: <?= json_encode($project_names) ?>,
    datasets: [{
      label: 'Test Cases',
      data: <?= json_encode($project_totals) ?>,
      backgroundColor: '#007bff'
    }]
  },
  options: {
    responsive: true,
    plugins: {
      legend: { display: false }
    },
    scales: {
      y: {
        beginAtZero: true,
        ticks: { stepSize: 1 }
      }
    }
  }
});
</script>

</body>
</html>
