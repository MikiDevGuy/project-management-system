FROM index.php 
<!-- User creating USING ADMIN PRIVILAG -->

<?php if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = $_POST['username'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $roles = $_POST['role'];
    $stmt = $conn->prepare("INSERT INTO users (username, password, role) VALUES (?, ?,?)");
    $stmt->bind_param("sss", $username, $password, $roles);
    $stmt->execute();
    header("Location: index.php");
}?>


<?php if ($_SESSION['role'] == 'admin'): ?> <!--here the user registration form is displayed olny for admin role-->
<form method="post">
  <h2>Register</h2>
  <input type="text" name="username" required><br>
  <input type="password" name="password" required><br>
  <!--<input type="email" name="email" required> -->
  <select name="role" required>
    <option value="admin">Admin</option>
    <option value="tester">Tester</option>
    <option value="viewer">Viewer</option>
  </select><br>
  <button type="submit">Register</button>
</form>
<?php endif; ?>


<!--Login page bk-->

<?php
session_start();
include 'db.php';

/* Auto-login using cookies (Remember Me)
if (!isset($_SESSION['user_id']) && isset($_COOKIE['user_id'])) {
    $_SESSION['user_id'] = $_COOKIE['user_id'];
    $_SESSION['username'] = $_COOKIE['username'];
    $_SESSION['role'] = $_COOKIE['role'];
    header("Location: index.php");
    exit;
} */

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = $_POST['username'];
    $password = $_POST['password'];
    $stmt = $conn->prepare("SELECT * FROM users WHERE username=?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    

    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['username'] = $user['username'];// WE need it for displying the user name in the lgoout feature.
  /* Remember Me cookies (7 days)
        if (isset($_POST['remember'])) {
            setcookie('user_id', $user['id'], time() + (86400 * 7), "/");
            setcookie('username', $user['username'], time() + (86400 * 7), "/");
            setcookie('role', $user['role'], time() + (86400 * 7), "/");
        } */

        header("Location: dashboard.php");
        exit;
    } else {
        echo "Login failed.";
    }
}


?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <form method="post" autocomplete="on" class="container mt-5" style="max-width: 400px;">
    <div class="mb-3">
        <label for="username" class="form-label">Username</label>
        <input type="text" class="form-control" id="username" name="username" 
               placeholder="Enter your username" autocomplete="username" required>
    </div>
    
    <div class="mb-3">
        <label for="password" class="form-label">Password</label>
        <input type="password" class="form-control" id="password" name="password" 
               placeholder="Enter your password" autocomplete="current-password" required>
    </div>
    
    <!-- Uncomment if you want Remember Me functionality -->
    <!--
    <div class="mb-3 form-check">
        <input type="checkbox" class="form-check-input" id="remember" name="remember">
        <label class="form-check-label" for="remember">Remember me</label>
    </div>
    -->
    
    <div class="d-grid gap-2">
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-box-arrow-in-right"></i> Login
        </button>
    </div>
</form>
</body>
</html>





<!--Index.php backup-->

<?php
session_start();
// Auto-login if "remember me" cookies are set
if (!isset($_SESSION['user_id']) && isset($_COOKIE['user_id'])) {
    $_SESSION['user_id'] = $_COOKIE['user_id'];
    $_SESSION['username'] = $_COOKIE['username'];
    $_SESSION['role'] = $_COOKIE['role'];
}


if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
} 
include 'db.php'; ?>
<!DOCTYPE html>
<html>
<head>
  <title>Test Manager</title>
  <link rel="stylesheet" href="style.css">
  <style>
    .title{
      text-align:center;
    }
  </style>
</head>
<body>
  <p style="text-align:right;">
  👋 Welcome, <?= $_SESSION['username'] ?? 'User' ?> |
  <a href="logout.php">🚪 Logout</a>
</p>

<h1 class="title">🧪 Test Case Manager</h1>

<h2>Projects</h2>
<ul> <!--I don't think this ul should be here--> 
  <?php
 //$result = $conn->query("SELECT * FROM projects");
 //$user_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT * FROM projects");
$stmt->execute();
$result = $stmt->get_result();

if (!$result) {
    die("Query Failed: " . $conn->error);
}
?>

<ul>
<?php while ($row = $result->fetch_assoc()): ?>
  <li><a href="view_project.php?id=<?= $row['id'] ?>"><?= $row['name'] ?></a></li>
<?php endwhile; ?>
</ul>

<?php if ($_SESSION['role'] == 'admin'): ?>
<h3>Add New Project</h3>
<form action="add_project.php" method="post">
  <input type="text" name="name" placeholder="Project Name" required><br>
  <textarea name="description" placeholder="Description" required></textarea><br>
  <button type="submit">Add Project</button>
</form>
<?php endif; ?>





<!-- ASSING PROJECT FOR USERS M TO M
<form action="add_project.php" method="post">
  <input type="text" name="name" placeholder="Project Name" required><br>
  <textarea name="description" placeholder="Description" required></textarea><br>

  <label>Assign to users:</label><br>
  <select name="assigned_users[]" multiple size="5" required>
    <?php /*
    $users = $conn->query("SELECT id, username, role FROM users WHERE role IN ('tester', 'viewer')");
    while ($u = $users->fetch_assoc()) {
      echo "<option value='{$u['id']}'>{$u['username']} ({$u['role']})</option>";
    }
    */?>
  </select><br><br>

  <button type="submit">Create Project</button>
</form> -->















<!--display All created users on the ADMIN PAGE-->
<?php $stmt = $conn->prepare("SELECT * FROM users");
    $stmt->execute();
    $users = $stmt->get_result();
    ?>
<?php if ($_SESSION['role'] == 'admin'): ?> <!-- -->
<table border="1">
  <tr>
    <th>ID</th><th>UserName</th><th>Role</th><th>Action</th>
  </tr>
  <?php while ($user = $users->fetch_assoc()) : ?> <!--$testcases->fetch_assoc(): Fetches one test case record from the database as an associative array

while loop: Continues looping as long as there are more records to fetch

$tc: Contains the current test case's data (columns: title, steps, expected, status, id)-->
    <tr>
      <td><?= $user['id'] ?></td>
      <td><?= $user['username'] ?></td>
      <td><?= $user['role'] ?></td>
      <td>
            <a href="edit_user.php?id=<?= $user['id'] ?>">Edit</a> |
            <a href="delete_user.php?id=<?= $user['id'] ?>">Delete</a>
            <!--<a href="Assign_project.php?id=<?= $user['id'] ?>">Assign_projects</a>-->
  <!--The delete link includes: The test case ID to delete and The project ID for context/redirection-->

      </td>
    </tr>
  <?php endwhile; ?>
</table>
<?php endif?>


<?php if ($_SESSION['role'] == 'admin'): ?>
  <a href="admin_projects.php">🔧 Manage Projects & Assign Users</a>
<?php endif; ?>

</body>
</html>




<!--Admin_projects.php-->

<?php
session_start();
include 'db.php';

if ($_SESSION['role'] !== 'admin') {
    die("Access denied. Admins only.");
}

// Get all projects
$result = $conn->query("SELECT * FROM projects");

?>

<!DOCTYPE html>
<html>
<head>
  <title>Admin Project Manager</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>
<!-- <a href="index.php">← Back to Dashboard</a> -->
 <a href="dashboard.php">Back to Dashboard</a>
<h1>📁 All Projects (Admin View)</h1>

<table border="1" cellpadding="8">
  <tr>
    <th>ID</th>
    <th>Name</th>
    <th>Description</th>
    <th>Actions</th>
  </tr>
  <?php while ($row = $result->fetch_assoc()): ?>
  <tr>
    <td><?= $row['id'] ?></td>
    <td><?= htmlspecialchars($row['name']) ?></td>
    <td><?= htmlspecialchars($row['description']) ?></td>
    <td>
      <a href="view_project.php?id=<?= $row['id'] ?>">View</a> |
      <a href="assign_users.php?id=<?= $row['id'] ?>">Assign Users</a>
    </td>
  </tr>
  <?php endwhile; ?>
</table>

</body>
</html>




  <!--assign_user.php-->

  <?php
session_start();
include 'db.php';

if ($_SESSION['role'] !== 'admin') {
    echo "Access denied.";
    exit;
}

$project_id = $_GET['id'] ?? null;

if (!$project_id) {
    echo "Project ID not provided.";
    exit;
}

// Check if the project exists
$project = $conn->prepare("SELECT * FROM projects WHERE id=?");
$project->bind_param("i", $project_id);
$project->execute();
$project_result = $project->get_result();

if ($project_result->num_rows === 0) {
    echo "Project not found.";
    exit;
}

$project = $project_result->fetch_assoc();

// Fetch already assigned user IDs
$assigned_ids = [];
$res = $conn->prepare("SELECT user_id FROM project_users WHERE project_id = ?");
$res->bind_param("i", $project_id);
$res->execute();
$result = $res->get_result();
while ($row = $result->fetch_assoc()) {
    $assigned_ids[] = $row['user_id'];
}

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['assigned_users'])) {
    $assigned_users = $_POST['assigned_users'];
    $already_assigned = [];
    $newly_assigned = [];

    // Step 1: Fetch all user IDs and their usernames for lookup
    $user_map = [];
    $user_result = $conn->query("SELECT id, username FROM users");
    while ($u = $user_result->fetch_assoc()) {
        $user_map[$u['id']] = $u['username'];
    }

    // Step 2: Loop through selected users and assign conditionally
    foreach ($assigned_users as $user_id) {
        if (in_array($user_id, $assigned_ids)) {
            $already_assigned[] = $user_map[$user_id]; // store username
        } else {
            $stmt = $conn->prepare("INSERT INTO project_users (project_id, user_id) VALUES (?, ?)");
            $stmt->bind_param("ii", $project_id, $user_id);
            $stmt->execute();
            $newly_assigned[] = $user_map[$user_id]; // store username
        }
    }

    // Step 3: Feedback messages
    if (count($newly_assigned) > 0) {
        echo "<p style='color:green;'>✅ Assigned: " . implode(", ", $newly_assigned) . "</p>";
    }

    if (count($already_assigned) > 0) {
        echo "<p style='color:orange;'>⚠️ Already Assigned: " . implode(", ", $already_assigned) . "</p>";
    }
}
?>















<!DOCTYPE html>
<html>
<head><title>Assign Users to Project</title></head>
<body>
<h2>Assign Users to Project: <?= htmlspecialchars($project['name']) ?></h2>

<!-- Show already assigned users -->
<h3>👥 Currently Assigned Users:</h3>
<ul>
<?php
$assigned = $conn->query("
    SELECT u.username, u.role FROM users u
    JOIN project_users pu ON u.id = pu.user_id
    WHERE pu.project_id = $project_id
");

if ($assigned->num_rows === 0) {
    echo "<li><i>No users assigned yet.</i></li>";
} else {
    while ($row = $assigned->fetch_assoc()) {
        echo "<li>{$row['username']} ({$row['role']})</li>";
    }
}
?>
</ul>

<!-- Form to assign users -->
<form method="post">
    <label>Select Users to Assign:</label><br>
    <select name="assigned_users[]" multiple size="5" required>
        <?php
        $users = $conn->query("SELECT id, username, role FROM users WHERE role IN ('tester', 'viewer')");

        // get IDs of already assigned users
        $current = $conn->query("SELECT user_id FROM project_users WHERE project_id = $project_id");
        $assigned_ids = [];
        while ($row = $current->fetch_assoc()) {
            $assigned_ids[] = $row['user_id']; 
        }

        while ($u = $users->fetch_assoc()) {
            $selected = in_array($u['id'], $assigned_ids) ? "selected" : "";
            echo "<option value='{$u['id']}' $selected>{$u['username']} ({$u['role']})</option>";
        }
        ?>
    </select><br><br>
    <button type="submit">💾 Save Assignments</button>
</form>

<p><a href="admin_projects.php">⬅️ Back to All Projects</a></p>
</body>
</html>






      <!--view_projects.php-->



<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    die("Access denied. Please log in.");
}
include 'db.php';

if (!isset($_GET['id'])) {
    die("Project ID not provided.");
}
$project_id = $_GET['id'];

//$project = $conn->query("SELECT * FROM projects WHERE id=$project_id")->fetch_assoc();//fetch_assoc() is a PHP function used to get one row of results from a database query as an associative array, where you can access data using column names as keys.
$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

if ($role === 'admin') {
    // Admins can view any project
    $stmt = $conn->prepare("SELECT * FROM projects WHERE id = ?");
    $stmt->bind_param("i", $project_id);//
} else {
    // Testers and Viewers: Can only view projects assigned to them
    $stmt = $conn->prepare("
        SELECT p.* FROM projects p
        JOIN project_users pu ON p.id = pu.project_id
        WHERE p.id = ? AND pu.user_id = ?
    ");
    $stmt->bind_param("ii", $project_id, $user_id);
}

$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo "You do not have access to this project.";
    exit;
}

$project = $result->fetch_assoc();


$testcases = $conn->query("SELECT * FROM test_cases WHERE project_id=$project_id");
?>
<!DOCTYPE html>
<html>
<head>
  <title><?= $project['name'] ?></title>
  <link rel="stylesheet" href="style.css">
</head>
<body>
<!--<a href="index.php">← Back to Projects</a> -->
 <a href="dashboard.php">Back to Dashboard</a>
<h1><?= $project['name'] ?></h1>
<p><?= $project['description'] ?></p>

<h2>Test Cases</h2>
<form method="post" action="export_csv.php">
  <input type="hidden" name="project_id" value="<?= $project_id ?>">
  <button type="submit">📄 Export to CSV</button>
</form>

<form method="get">
  <input type="hidden" name="id" value="<?= $project_id ?>">
  <label>Filter by Status:</label>
  <select name="status" onchange="this.form.submit()">
    <option value="">All</option>
    <option value="Pass" <?= ($_GET['status'] ?? '') == 'Pass' ? 'selected' : '' ?>>Pass</option>
    <option value="Fail" <?= ($_GET['status'] ?? '') == 'Fail' ? 'selected' : '' ?>>Fail</option>
    <option value="Pending" <?= ($_GET['status'] ?? '') == 'Pending' ? 'selected' : '' ?>>Pending</option>
  </select>
</form>
<?php 
$statusFilter = $_GET['status'] ?? '';

if ($statusFilter) {
    $stmt = $conn->prepare("SELECT * FROM test_cases WHERE project_id=? AND status=?");
    $stmt->bind_param("is", $project_id, $statusFilter);
} else {
    $stmt = $conn->prepare("SELECT * FROM test_cases WHERE project_id=?");
    $stmt->bind_param("i", $project_id);
}
$stmt->execute();
$testcases = $stmt->get_result();

?>
<table border="1">
  <tr>
    <th>Title</th><th>Steps</th><th>Expected</th><th>Status</th><th>Action</th>
  </tr>
  <?php while ($tc = $testcases->fetch_assoc()) : ?> <!--$testcases->fetch_assoc(): Fetches one test case record from the database as an associative array

while loop: Continues looping as long as there are more records to fetch

$tc: Contains the current test case's data (columns: title, steps, expected, status, id)-->
    <tr>
      <td><?= $tc['title'] ?></td>
      <td><?= $tc['steps'] ?></td>
      <td><?= $tc['expected'] ?></td>
      <td><?= $tc['status'] ?></td>
      <?php if($_SESSION['role']== 'admin' || $_SESSION['role']== 'tester'): ?>
      <td> 
            <a href="edit_testcase.php?id=<?= $tc['id'] ?>&project_id=<?= $project_id ?>">Edit</a> |
            <a href="delete_testcase.php?id=<?= $tc['id'] ?>&project_id=<?= $project_id ?>">Delete</a>
  <!--The delete link includes: The test case ID to delete and The project ID for context/redirection-->
      </td>
     <?php endif?>
    </tr>
  <?php endwhile; ?>
</table>

</body>
</html>







      <!--add_testcases.php bk-->
      <?php
session_start();
include 'db.php';

if (!isset($_SESSION['user_id'])) {
    die("Access denied.");
}

// STEP 1: GET the project ID from the URL (used to prefill hidden input)
$project_id = $_GET['project_id'] ?? $_POST['project_id'] ?? null;

if (!$project_id) {
    die("Project ID is required.");
}

// STEP 2: Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $title = $_POST['title'];
    $steps = $_POST['steps'];
    $expected = $_POST['expected'];
    $status = $_POST['status'];

    $stmt = $conn->prepare("INSERT INTO test_cases (project_id, title, steps, expected, status) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("issss", $project_id, $title, $steps, $expected, $status);
    $stmt->execute();

    // After insert, redirect back to view_project page
    header("Location: view_project.php?id=$project_id");
    exit;
}
?>

<?php if ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'tester'): ?>
<!DOCTYPE html>
<html>
<head><title>Add Test Case</title></head>
<body>
  <!-- Back Link -->
    <a href="dashboard.php" class="back-link mb-3 d-inline-block">
      <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
    </a>
<h3>Add Test Case to Project #<?= htmlspecialchars($project_id) ?></h3>
<form action="add_testcase.php" method="post">
  <input type="hidden" name="project_id" value="<?= htmlspecialchars($project_id) ?>">
  <input type="text" name="title" placeholder="Title" required><br>
  <textarea name="steps" placeholder="Steps" required></textarea><br>
  <textarea name="expected" placeholder="Expected Result" required></textarea><br>
  <select name="status" required>
    <option value="Pending">Pending</option>
    <option value="Pass">Pass</option>
    <option value="Fail">Fail</option>
  </select><br>
  <button type="submit">➕ Add Test Case</button>
</form>
</body>
</html>
<?php else: ?>
  <p>Access denied. You must be an admin or tester to create test cases.</p>
<?php endif; ?>





<!--user.php-->
<?php 
session_start();
include 'db.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = $_POST['username'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $roles = $_POST['role'];
    $stmt = $conn->prepare("INSERT INTO users (username, password, role) VALUES (?, ?,?)");
    $stmt->bind_param("sss", $username, $password, $roles);
    $stmt->execute();
    header("Location: dashboard.php");
}?>


<?php if ($_SESSION['role'] == 'admin'): ?> <!--here the user registration form is displayed olny for admin role-->
  <!-- Back Link -->
    <a href="dashboard.php" class="back-link mb-3 d-inline-block">
      <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
    </a>
<form method="post">
  <h2>Register</h2>
  <input type="text" name="username" required><br>
  <input type="password" name="password" required><br>
  <!--<input type="email" name="email" required> -->
  <select name="role" required>
    <option value="admin">Admin</option>
    <option value="tester">Tester</option>
    <option value="viewer">Viewer</option>
  </select><br>
  <button type="submit">Register</button>
</form>
<?php endif; ?>








<!--sidebar.php bk-->
<?php
if (!isset($_SESSION)) session_start();
$username = $_SESSION['username'] ?? 'Guest';
$role = $_SESSION['role'] ?? 'viewer';
?>
<!-- Sidebar styles -->
<style>
body {
  margin: 0;
  padding: 0;
}
.sidebar {
  height: 100vh;
  width: 250px;
  position: fixed;
  z-index: 1000;
  top: 0;
  left: -250px;
  background-color: #343a40;
  color: white;
  transition: left 0.3s ease-in-out;
}
.sidebar a {
  display: block;
  padding: 15px;
  color: white;
  text-decoration: none;
}
.sidebar a:hover {
  background-color: #495057;
}
.sidebar .user-info {
  padding: 15px;
  background-color: #212529;
  font-weight: bold;
}
.sidebar-toggler {
  position: fixed;
  top: 10px;
  left: 10px;
  background-color: #343a40;
  color: white;
  border: none;
  padding: 10px 12px;
  z-index: 1100;
  border-radius: 4px;
}
.content {
  margin-left: 0;
  padding: 20px;
  transition: margin-left 0.3s ease-in-out;
}
.sidebar-open .sidebar {
  left: 0;
}
.sidebar-open .content {
  margin-left: 250px;
}
</style>

<!-- Toggle button -->
<button class="sidebar-toggler" onclick="toggleSidebar()">☰ Menu</button>

<!-- Sidebar -->
<div class="sidebar" id="sidebar">
  <div class="user-info">
    👋 <?= $username ?> (<?= $role ?>)
  </div>
  <a href="dashboard.php">📊 Dashboard</a>

  <?php if ($role === 'admin'): ?>
    <a href="add_project.php">➕ Add Project</a>
    <a href="admin_projects.php">📂 Manage Projects</a>
    <a href="users.php">👥 Manage Users</a>
    <a href="reports.php">📈 Reports</a>
  <?php elseif ($role === 'tester'): ?>
    <a href="assigned_projects.php">📁 My Projects</a>
    <a href="add_testcase.php">➕ Add Test Case</a>
  <?php elseif ($role === 'viewer'): ?>
    <a href="assigned_projects.php">👁 View Projects</a>
  <?php endif; ?>

  <a href="profile.php">👤 My Profile</a>
  <a href="logout.php">🚪 Logout</a>
</div>

<!-- Sidebar Toggle Script -->
<script>
function toggleSidebar() {
  document.body.classList.toggle('sidebar-open');
}
</script>







<!--edit testcase.php-->


<?php
include 'db.php';

$id = $_GET['id'];
$project_id = $_GET['project_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') { /* check is crucial for distinguishing between page loading and form submission in PHP.
    e.g Think of it like a restaurant:

GET request = Looking at the menu (you see information)

POST request = Submitting your order (action happens in the kitchen)

Without this check, it would be like the kitchen preparing food every time you just look at the menu! */
    $title = $_POST['title'];
    $steps = $_POST['steps'];
    $expected = $_POST['expected'];
    $status = $_POST['status'];

    $stmt = $conn->prepare("UPDATE test_cases SET title=?, steps=?, expected=?, status=? WHERE id=?");
    $stmt->bind_param("ssssi", $title, $steps, $expected, $status, $id);
    $stmt->execute();

    header("Location: view_project.php?id=$project_id");
    exit;
}

$tc = $conn->query("SELECT * FROM test_cases WHERE id=$id")->fetch_assoc();
?>
<!DOCTYPE html>
<html>
<head><title>Edit Test Case</title></head>
<body>
<h2>Edit Test Case</h2>
<form method="post">
  <input type="text" name="title" value="<?= $tc['title'] ?>" required><br>
  <textarea name="steps" required><?= $tc['steps'] ?></textarea><br>
  <textarea name="expected" required><?= $tc['expected'] ?></textarea><br>
  <select name="status">
    <option value="Pass" <?= $tc['status']=='Pass'?'selected':'' ?>>Pass</option>
    <option value="Fail" <?= $tc['status']=='Fail'?'selected':'' ?>>Fail</option>
    <option value="Pending" <?= $tc['status']=='Pending'?'selected':'' ?>>Pending</option>
  </select><br>
  <button type="submit">Update</button>
</form>
</body>
</html>

