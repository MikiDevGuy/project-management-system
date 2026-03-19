<?php
session_start();
include 'db.php';

if (!isset($_SESSION['user_id'])) {
    die("Access denied.");
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$role = $_SESSION['system_role'];

// Handle password update
$success = $error = '';
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $current_password = $_POST['current_password'];
    $new_password     = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    if ($new_password !== $confirm_password) {
        $error = "New passwords do not match.";
    } else {
        $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();

        if (!password_verify($current_password, $user['password'])) {
            $error = "Current password is incorrect.";
        } else {
            $hashed_new = password_hash($new_password, PASSWORD_DEFAULT);
            $update = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $update->bind_param("si", $hashed_new, $user_id);
            $update->execute();
            $success = "✅ Password updated successfully.";
        }
    }
}

//$active_dashboard = 'testcase_management';
$active_dashboard = 'project_management';
include 'sidebar.php';

?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>👤 My Profile</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
  <style>
    body {
      background: linear-gradient(to right, #f8f9fa, #e9ecef);
      font-family: 'Segoe UI', sans-serif;
    }
    .card-custom {
      box-shadow: 0 0 25px rgba(0,0,0,0.1);
      border: none;
      border-radius: 12px;
    }
    .profile-header {
      background: #0d6efd;
      color: white;
      padding: 25px;
      border-radius: 12px 12px 0 0;
    }
    .profile-header h4 {
      margin: 0;
    }
    .form-control:focus {
      box-shadow: none;
      border-color: #0d6efd;
    }
  </style>
</head>
<body>

<div class="container mt-5">
  <div class="row justify-content-center">
    <div class="col-lg-6">
      <div class="card card-custom">
        <div class="profile-header text-center">
          <i class="fas fa-user-circle fa-3x mb-2"></i>
          <h4><?= htmlspecialchars($username) ?> <span class="badge bg-light text-dark"><?= ucfirst($role) ?></span></h4>
          <small>Welcome to your profile dashboard</small>
        </div>
        <div class="card-body">

          <!-- Success/Error Messages -->
          <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
              <?= $success ?>
              <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
          <?php endif; ?>

          <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
              <?= $error ?>
              <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
          <?php endif; ?>

          <h5 class="mb-3"><i class="fas fa-key me-2"></i>Change Password</h5>
          <form method="post">
            <div class="mb-3">
              <label for="current_password" class="form-label">Current Password</label>
              <input type="password" class="form-control" name="current_password" required>
            </div>
            <div class="mb-3">
              <label for="new_password" class="form-label">New Password</label>
              <input type="password" class="form-control" name="new_password" required>
            </div>
            <div class="mb-3">
              <label for="confirm_password" class="form-label">Confirm New Password</label>
              <input type="password" class="form-control" name="confirm_password" required>
            </div>
            <button type="submit" class="btn btn-primary w-100"><i class="fas fa-save me-2"></i>Update Password</button>
          </form>

        </div>
      </div>
      <?php if ($role === 'pm_manager' || $role === 'pm_employee'): ?>
      <div class="text-center mt-3">
        <a href="dashboard_project_manager.php" class="btn btn-outline-secondary">
          <i class="fas fa-arrow-left me-1"></i>Back to PM Dashboard
        </a>
      </div>
      <?php elseif ($role === 'tester' || $role === 'test_viewer'): ?>
        <div class="text-center mt-3">
        <a href="dashboard_testcase.php" class="btn btn-outline-secondary">
          <i class="fas fa-arrow-left me-1"></i>Back to TC Dashboard
        </a>
      </div>
      <?php elseif ($role === 'super_admin' || $role === 'admin'): ?>
        <div class="text-center mt-3">
        <a href="dashboard.php" class="btn btn-outline-secondary">
          <i class="fas fa-arrow-left me-1"></i>Back to UNIF Dashboard
        </a>
      </div>
      <?php endif; ?>
      
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
