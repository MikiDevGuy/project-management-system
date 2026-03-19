<?php
session_start();
include 'db.php';

if ($_SESSION['system_role'] !== 'super_admin' && $_SESSION['system_role'] !== 'pm_manager') {
    die('<div class="alert alert-danger">Access denied. Admins only.</div>');
}

$project_id = $_GET['id'] ?? null;

if (!$project_id) {
    die('<div class="alert alert-warning">Project ID not provided.</div>');
}

// Check if the project exists
$project = $conn->prepare("SELECT * FROM projects WHERE id=?");
$project->bind_param("i", $project_id);
$project->execute();
$project_result = $project->get_result();

if ($project_result->num_rows === 0) {
    die('<div class="alert alert-warning">Project not found.</div>');
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
$newly_assigned = [];
$already_assigned = [];
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['assigned_users'])) {
    $assigned_users = $_POST['assigned_users'];
    
    // Clear all existing assignments first
    $clear_stmt = $conn->prepare("DELETE FROM project_users WHERE project_id = ?");
    $clear_stmt->bind_param("i", $project_id);
    $clear_stmt->execute();
    
    // Step 1: Fetch all user IDs and their usernames for lookup
    $user_map = [];
    $user_result = $conn->query("SELECT id, username FROM users");
    while ($u = $user_result->fetch_assoc()) {
        $user_map[$u['id']] = $u['username'];
    }

    // Step 2: Assign all selected users
    foreach ($assigned_users as $user_id) {
        $stmt = $conn->prepare("INSERT INTO project_users (project_id, user_id) VALUES (?, ?)");
        $stmt->bind_param("ii", $project_id, $user_id);
        if ($stmt->execute()) {
            $newly_assigned[] = $user_map[$user_id];
        }
    }
    
    // Update assigned_ids with new selections
    $assigned_ids = $assigned_users;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Assign Users to Project</title>
  <!-- Bootstrap 5 CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <!-- Font Awesome -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    :root {
      --primary-color: #4e73df;
      --secondary-color: #f8f9fc;
      --accent-color: #2e59d9;
      --dark-color: #5a5c69;
      --light-color: #ffffff;
    }
    
    body {
      background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
      min-height: 100vh;
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }
    
    .navbar-custom {
      background: var(--primary-color);
      box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
    }
    
    .card-custom {
      border: none;
      border-radius: 0.35rem;
      box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.1);
    }
    
    .card-header-custom {
      background: var(--primary-color);
      color: white;
      border-bottom: none;
      border-radius: 0.35rem 0.35rem 0 0 !important;
    }
    
    .btn-primary-custom {
      background-color: var(--primary-color);
      border-color: var(--primary-color);
    }
    
    .btn-primary-custom:hover {
      background-color: var(--accent-color);
      border-color: var(--accent-color);
    }
    
    .welcome-text {
      color: var(--light-color);
      font-weight: 600;
    }
    
    .hero-section {
      background: linear-gradient(135deg, var(--primary-color) 0%, var(--accent-color) 100%);
      color: white;
      border-radius: 0.35rem;
      padding: 1.5rem;
      margin-bottom: 2rem;
      box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
    }
    
    .user-badge {
      border-radius: 50px;
      padding: 5px 12px;
      font-size: 0.85rem;
      margin-right: 8px;
      margin-bottom: 8px;
      display: inline-block;
    }
    
    .tester-badge {
      background-color: #e3f2fd;
      color: #1976d2;
    }
    
    .viewer-badge {
      background-color: #e8f5e9;
      color: #388e3c;
    }
    
    .user-select-container {
      max-height: 300px;
      overflow-y: auto;
      border: 1px solid #dee2e6;
      border-radius: 0.25rem;
      padding: 15px;
      background-color: white;
    }
    
    .form-check-label {
      display: flex;
      align-items: center;
    }
    
    .user-avatar {
      width: 30px;
      height: 30px;
      border-radius: 50%;
      background-color: var(--primary-color);
      color: white;
      display: flex;
      align-items: center;
      justify-content: center;
      margin-right: 10px;
      font-size: 0.8rem;
    }
    
    .back-link {
      color: var(--primary-color);
      text-decoration: none;
      transition: all 0.3s ease;
    }
    
    .back-link:hover {
      color: var(--accent-color);
      transform: translateX(-3px);
    }
  </style>
</head>
<body>
  <!-- Navigation Bar -->
  <nav class="navbar navbar-expand-lg navbar-dark navbar-custom mb-4">
    <div class="container-fluid">
      <a class="navbar-brand" href="#">
        <i class="fas fa-user-shield me-2"></i>Admin Panel
      </a>
      <div class="d-flex align-items-center">
        <span class="welcome-text me-3">
          <i class="fas fa-user-circle me-2"></i><?= htmlspecialchars($_SESSION['username']) ?>
        </span>
        <a href="logout.php" class="btn btn-outline-light">
          <i class="fas fa-sign-out-alt me-1"></i> Logout
        </a>
      </div>
    </div>
  </nav>

  <div class="container">
    <!-- Back Link -->
    <a href="admin_projects.php" class="back-link mb-3 d-inline-block">
      <i class="fas fa-arrow-left me-2"></i>Back to All Projects
    </a>

    <!-- Hero Section -->
    <div class="hero-section">
      <h1><i class="fas fa-user-plus me-2"></i>Assign Users</h1>
      <p class="lead mb-0">Project: <?= htmlspecialchars($project['name']) ?></p>
    </div>

    <!-- Feedback Messages -->
    <?php if (!empty($newly_assigned)): ?>
      <div class="alert alert-success">
        <i class="fas fa-check-circle me-2"></i>
        Successfully assigned: <?= implode(", ", $newly_assigned) ?>
      </div>
    <?php endif; ?>

    <div class="row">
      <div class="col-lg-6">
        <!-- Currently Assigned Users -->
        <div class="card card-custom mb-4">
          <div class="card-header card-header-custom">
            <h3 class="card-title mb-0"><i class="fas fa-users me-2"></i>Currently Assigned</h3>
          </div>
          <div class="card-body">
            <?php
            $assigned = $conn->query("
                SELECT u.id, u.username, u.system_role FROM users u
                JOIN project_users pu ON u.id = pu.user_id
                WHERE pu.project_id = $project_id
                ORDER BY u.system_role, u.username
            ");

            if ($assigned->num_rows === 0): ?>
              <div class="text-muted"><i class="fas fa-info-circle me-2"></i>No users assigned yet</div>
            <?php else: ?>
              <div class="d-flex flex-wrap">
                <?php while ($row = $assigned->fetch_assoc()): ?>
                  <span class="user-badge <?= $row['system_role'] === 'tester' ? 'tester-badge' : 'viewer-badge' ?>">
                    <i class="fas fa-<?= $row['system_role'] === 'tester' ? 'flask' : 'eye' ?> me-1"></i>
                    <?= htmlspecialchars($row['username']) ?>
                  </span>
                <?php endwhile; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="col-lg-6">
        <!-- Assign Users Form -->
        <div class="card card-custom">
          <div class="card-header card-header-custom">
            <h3 class="card-title mb-0"><i class="fas fa-user-edit me-2"></i>Manage Assignments</h3>
          </div>
          <div class="card-body">
            <form method="post">
              <div class="mb-3">
                <label class="form-label">Select Users to Assign:</label>
                <div class="user-select-container">
                  <?php
                  $users = $conn->query("
                      SELECT id, username, system_role 
                      FROM users 
                      
                      ORDER BY system_role, username
                  ");
                  
                  while ($u = $users->fetch_assoc()): ?>
                    <div class="form-check mb-2">
                      <input class="form-check-input" 
                             type="checkbox" 
                             name="assigned_users[]" 
                             value="<?= $u['id'] ?>" 
                             id="user_<?= $u['id'] ?>"
                             <?= in_array($u['id'], $assigned_ids) ? 'checked' : '' ?>>
                      <label class="form-check-label" for="user_<?= $u['id'] ?>">
                        <span class="user-avatar">
                          <?= strtoupper(substr($u['username'], 0, 1)) ?>
                        </span>
                        <div>
                          <strong><?= htmlspecialchars($u['username']) ?></strong>
                          <div class="text-muted small"><?= ucfirst($u['system_role']) ?></div>
                        </div>
                      </label>
                    </div>
                  <?php endwhile; ?>
                </div>
              </div>
              <button type="submit" class="btn btn-primary-custom w-100">
                <i class="fas fa-save me-2"></i>Save Assignments
              </button>
            </form>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Bootstrap JS Bundle with Popper -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>