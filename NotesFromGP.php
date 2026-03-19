<!--Notes-->

<!--Notes about report.php-->
✅ 1. Session and Role Check
php
Copy
Edit
session_start();
if ($_SESSION['role'] !== 'admin') {
    die("Access denied.");
}
🔍 Explanation:

session_start() starts a session so we can access session variables (like logged-in user info).

Then it checks if the current user's role is 'admin'. If not, it stops the page and says “Access denied.”

✅ 2. Database Connection and Sidebar
php
Copy
Edit
include 'db.php';
include 'sidebar.php';
🔍 Explanation:

db.php connects to the database.

sidebar.php contains reusable sidebar navigation (likely for your dashboard layout).

✅ 3. Get Total Counts
php
Copy
Edit
$total_projects = $conn->query("SELECT COUNT(*) AS c FROM projects")->fetch_assoc()['c'];
$total_users = $conn->query("SELECT COUNT(*) AS c FROM users")->fetch_assoc()['c'];
$total_cases = $conn->query("SELECT COUNT(*) AS c FROM test_cases")->fetch_assoc()['c'];
🔍 Explanation:

These 3 lines run SQL COUNT(*) queries to:

Count total projects

Count total users

Count total test cases

fetch_assoc()['c'] gets the value of the count from the result.

✅ 4. Test Case Status Breakdown
php
Copy
Edit
$status_counts = $conn->query("
  SELECT status, COUNT(*) AS count 
  FROM test_cases GROUP BY status
");
$status_data = [];
while ($row = $status_counts->fetch_assoc()) {
  $status_data[$row['status']] = $row['count'];
}
🔍 Explanation:

This groups test cases by status (e.g., "Passed", "Failed", "Pending").

For each status type, it counts how many test cases have that status.

It stores the results in an associative array like:

php
Copy
Edit
$status_data = [
  'Passed' => 10,
  'Failed' => 5,
  'Pending' => 3
];
✅ 5. Test Cases per Project
php
Copy
Edit
$project_counts = $conn->query("
  SELECT p.name, COUNT(tc.id) as total
  FROM projects p
  LEFT JOIN test_cases tc ON p.id = tc.project_id
  GROUP BY p.id
");
$project_names = [];
$project_totals = [];
while ($row = $project_counts->fetch_assoc()) {
  $project_names[] = $row['name'];
  $project_totals[] = $row['total'];
}
🔍 Explanation:

This SQL joins the projects table with test_cases using a LEFT JOIN, meaning:

Every project shows up, even if it has zero test cases.

It counts how many test cases are linked to each project.

Then two arrays are created:

$project_names = ['Project A', 'Project B']

$project_totals = [7, 3]

✅ 6. HTML Structure (Bootstrap)
The HTML part contains:

A header with a title

A stats section with cards showing total projects, users, test cases

Two charts:

Left chart: Pie chart of test case status

Right chart: Bar chart of test cases per project

✅ 7. Chart.js - Pie Chart for Status
javascript
Copy
Edit
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
🔍 Explanation:

Uses Chart.js to show a pie chart.

labels = the status names (Passed, Failed, etc.).

data = how many test cases each status has.

backgroundColor = chart colors.

✅ 8. Chart.js - Bar Chart for Projects
javascript
Copy
Edit
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
🔍 Explanation:

A bar chart where each bar is a project.

labels = project names.

data = number of test cases per project.

Chart is responsive and starts the y-axis at 0.

🧠 Summary of the Logic
Section	Logic
Session + Role Check	Only allow admins to view the report
Count Stats	Get counts of all users, projects, and test cases
Group by Status	Count how many test cases are in each status
Group by Project	Count how many test cases belong to each project
Display Charts	Use Chart.js to visually represent data in pie and bar charts
Styling with Bootstrap	Use modern cards, responsive layout, spacing, and professional look






<!--The whole project Structure Note-->
<!--The whole project Structure Note-->
<!--The whole project Structure Note-->
<!--The whole project Structure Note-->
Yes Nati, your thinking is 💯 spot on — and it's actually best practice in software QA/test management systems. Let's break this down clearly and structure it in a professional way:

✅ Your Idea in a Real-World Hierarchy:
scss
Copy
Edit
Project (1) ─┬─> Feature (many)
             └─> Feature ─┬─> Test Case (many)
                          └─> Test Case ...
🧠 Logic:
One Project contains many Features.

Each Feature contains many Test Cases.

If all test cases in a feature are marked Pass, then the feature is complete.

If all features in a project are complete, then the project is complete ✅.

🔧 Suggested Database Relationships
Here’s what you should now implement:

🗃️ features table – ADD project_id field:
sql
Copy
Edit
ALTER TABLE features 
ADD COLUMN project_id INT AFTER id,
ADD CONSTRAINT fk_feature_project 
  FOREIGN KEY (project_id) REFERENCES projects(id) 
  ON DELETE CASCADE;
✅ This links every feature to a project.

🗃️ test_cases table – Already linked to features:
You've already added feature_id in test_cases — so this part is good.

🧩 Workflow Example
Create a project (e.g., "E-Commerce App").

Assign features to that project (e.g., "Login", "Cart", "Checkout").

Under each feature, create test cases.

Use your dashboard to calculate progress:

Feature progress = % of test cases passed.

Project progress = % of features marked complete.

📊 Bonus Suggestion: Auto Feature/Project Completion
You can use a simple SQL trigger or status updater script to automatically:

Set feature.status = 'Completed' when all test cases inside it are Pass.

Set project.status = 'Completed' when all features are Completed.

Would you like me to implement:
✅ A script to calculate this?
✅ A progress bar for projects and features in the UI?

✅ Summary
Table	Relationship	Notes
projects	1 → many features	Top-level entity
features	1 → many test_cases	Now linked via project_id
test_cases	belongs to features	Already has feature_id

Nati, you’re designing this like a true test manager 👏 — Let me know if you want help:

Updating your add/edit_feature.php form to allow selecting project

Displaying features grouped by project

Adding a dashboard progress tracker




























































<!--Report.php bk-->
<?php
session_start();
if ($_SESSION['role'] !== 'admin') {
    die("Access denied.");
}
include 'db.php';
include 'sidebar.php';

// Getting Total count of the project, user and test cases
$total_projects = $conn->query("SELECT COUNT(*) AS c FROM projects")->fetch_assoc()['c'];
$total_users = $conn->query("SELECT COUNT(*) AS c FROM users")->fetch_assoc()['c'];
$total_cases = $conn->query("SELECT COUNT(*) AS c FROM test_cases")->fetch_assoc()['c'];

/*The below code groups test cases by status (e.g., "Passed", "Failed", "Pending").
 For each status type, it counts how many test cases have that status.
 It stores the results in an associative array like: $status_data = [
  'Passed' => 10,
  'Failed' => 5,
  'Pending' => 3
];
*/
$status_counts = $conn->query("
  SELECT status, COUNT(*) AS count 
  FROM test_cases GROUP BY status
");
$status_data = [];
while ($row = $status_counts->fetch_assoc()) {
  $status_data[$row['status']] = $row['count'];
}

/*Chart: Test Cases per Project
This SQL joins the projects table with test_cases using a LEFT JOIN, meaning:

Every project shows up, even if it has zero test cases.

It counts how many test cases are linked to each project.

Then two arrays are created:

$project_names = ['Project A', 'Project B']

$project_totals = [7, 3]
*/
$project_counts = $conn->query("
  SELECT p.name, COUNT(tc.id) as total
  FROM projects p
  LEFT JOIN test_cases tc ON p.id = tc.project_id
  GROUP BY p.id
");
$project_names = [];
$project_totals = [];
while ($row = $project_counts->fetch_assoc()) {
  $project_names[] = $row['name'];
  $project_totals[] = $row['total'];
}
?>



<!DOCTYPE html>
<html>
<head>
  <title>📈 Admin Reports</title>
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
  <a href="dashboard.php" class="back-link"><i class="fas fa-arrow-left me-2"></i>← Back to Dashboard</a>

  <div class="header">
    <h2>📊 Admin Reports & Statistics</h2>
    <p>Overview of projects, users, and test cases</p>
  </div>
  
  <!--filter for will be here-->

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

  <div class="row mb-5">
    <div class="col-md-6 mb-4">
      <div class="card p-4">
        <h5 class="mb-3">📌 Test Case Status</h5>
        <canvas id="statusChart" height="250"></canvas>
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
