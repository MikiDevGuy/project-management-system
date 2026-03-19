<?php 
session_start();
include 'db.php';
include 'sidebar.php';

// Fetch available systems for selection
$systems_result = $conn->query("SELECT system_id, system_name FROM systems ORDER BY system_name");

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $role = $_POST['role'];
    $selected_systems = $_POST['systems'] ?? [];

    // Check if username already exists
    $check_stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
    $check_stmt->bind_param("s", $username);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        $error = "Username already exists. Please choose another.";
    } else {
        // Insert user
        $stmt = $conn->prepare("INSERT INTO users (username, email, password, system_role) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssss", $username, $email, $password, $role);
        
        if ($stmt->execute()) {
            $user_id = $stmt->insert_id;

            // Insert into user_systems
            if (!empty($selected_systems)) {
                $sys_stmt = $conn->prepare("INSERT INTO user_systems (user_id, system_id) VALUES (?, ?)");
                foreach ($selected_systems as $system_id) {
                    $sys_stmt->bind_param("ii", $user_id, $system_id);
                    $sys_stmt->execute();
                }
            }
            $_SESSION['success_message'] = "User registered successfully!";
            
        } else {
            $error = "Registration failed. Please try again.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>User Registration - Dashen Bank</title>
  <!-- Bootstrap 5 CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <!-- Font Awesome -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    :root {
      --dashen-blue: #273274;
      --dashen-light-blue: #3c4c9e;
      --dashen-accent: #1a245a;
      --dashen-light: #f8f9fc;
      --dashen-white: #ffffff;
      --dashen-gray: #5a5c69;
      --dashen-success: #28a745;
      --dashen-warning: #ffc107;
      --dashen-danger: #dc3545;
    }
    
    body {
      background: linear-gradient(135deg, #f5f7fa 0%, #e3e8f5 100%);
      min-height: 100vh;
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
      margin: 0;
      padding: 0;
    }
    
    .navbar-custom { 
      background: var(--dashen-blue); 
      box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
    }
    
    .card-custom { 
      border: none; 
      border-radius: 0.5rem; 
      box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.1);
    }
    
    .card-header-custom { 
      background: var(--dashen-blue); 
      color: white; 
      border-bottom: none; 
      border-radius: 0.5rem 0.5rem 0 0 !important;
      padding: 1.25rem;
    }
    
    .btn-dashen-primary { 
      background-color: var(--dashen-blue); 
      border-color: var(--dashen-blue);
      font-weight: 600;
    }
    
    .btn-dashen-primary:hover { 
      background-color: var(--dashen-accent); 
      border-color: var(--dashen-accent);
      transform: translateY(-2px);
      box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    }
    
    .welcome-text { 
      color: var(--dashen-white); 
      font-weight: 600;
    }
    
    .hero-section { 
      background: linear-gradient(135deg, var(--dashen-blue) 0%, var(--dashen-light-blue) 100%); 
      color: white; 
      border-radius: 0.5rem; 
      padding: 2rem; 
      margin-bottom: 2rem; 
      box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
    }
    
    .back-link { 
      color: var(--dashen-blue); 
      text-decoration: none; 
      transition: all 0.3s ease;
      font-weight: 500;
    }
    
    .back-link:hover { 
      color: var(--dashen-accent); 
      transform: translateX(-3px);
    }
    
    .role-badge { 
      padding: 8px 15px; 
      border-radius: 50px; 
      font-size: 0.85rem; 
      font-weight: 600;
      display: inline-block;
      margin: 5px;
      transition: all 0.3s ease;
    }
    
    .role-badge:hover {
      transform: scale(1.05);
      cursor: pointer;
    }
    
    .role-super-admin { 
      background-color: #ffebee; 
      color: #c62828;
      border: 2px solid #c62828;
    }
    
    .role-admin { 
      background-color: #e3f2fd; 
      color: #1565c0;
      border: 2px solid #1565c0;
    }
    
    .role-tester { 
      background-color: #e8f5e8; 
      color: #2e7d32;
      border: 2px solid #2e7d32;
    }
    
    .role-test-viewer { 
      background-color: #fff3e0; 
      color: #ef6c00;
      border: 2px solid #ef6c00;
    }
    
    .role-pm-manager { 
      background-color: #f3e5f5; 
      color: #7b1fa2;
      border: 2px solid #7b1fa2;
    }
    
    .role-pm-employee { 
      background-color: #e0f2f1; 
      color: #00695c;
      border: 2px solid #00695c;
    }
    
    .role-pm-viewer { 
      background-color: #eceff1; 
      color: #37474f;
      border: 2px solid #37474f;
    }
    
    .password-toggle { 
      cursor: pointer; 
      position: absolute; 
      right: 15px; 
      top: 50%; 
      transform: translateY(-50%);
      color: var(--dashen-gray);
    }
    
    .form-floating label { 
      color: var(--dashen-gray);
    }
    
    .form-control:focus {
      border-color: var(--dashen-light-blue);
      box-shadow: 0 0 0 0.2rem rgba(60, 76, 158, 0.25);
    }
    
    .form-check-input:checked {
      background-color: var(--dashen-blue);
      border-color: var(--dashen-blue);
    }
    
    .content-area {
      margin-left: 280px;
      transition: margin-left 0.3s ease;
      padding: 20px;
    }
    
    .sidebar-collapsed ~ .content-area {
      margin-left: 80px;
    }
    
    @media (max-width: 768px) {
      .content-area {
        margin-left: 0;
      }
    }
  </style>
</head>
<body>
  <!-- Sidebar is included via sidebar.php -->
  
  <div class="content-area">
    <!-- Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-dark navbar-custom mb-4">
      <div class="container-fluid">
        <a class="navbar-brand" href="#">
          <i class="fas fa-user-shield me-2"></i>Dashen Bank - Admin Panel
        </a>
        <div class="d-flex align-items-center">
          <span class="welcome-text me-3">
            <i class="fas fa-user-circle me-2"></i><?= htmlspecialchars($_SESSION['username'] ?? 'Admin') ?>
          </span>
          <a href="logout.php" class="btn btn-outline-light">
            <i class="fas fa-sign-out-alt me-1"></i> Logout
          </a>
        </div>
      </div>
    </nav>

    <div class="container">
      <?php if ($_SESSION['system_role'] == 'super_admin'): ?>
        <!-- Back Link -->
        <a href="display_user.php" class="back-link mb-3 d-inline-block">
          <i class="fas fa-arrow-left me-2"></i>Back to Users
        </a>

        <!-- Hero Section -->
        <div class="hero-section">
          <h1><i class="fas fa-user-plus me-2"></i>Register New User</h1>
          <p class="lead mb-0">Create accounts for team members with appropriate access levels</p>
        </div>

        <!-- Error Message -->
        <?php if (isset($error)): ?>
          <div class="alert alert-danger mb-4">
            <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?>
          </div>
        <?php endif; ?>

        <!-- Registration Form -->
        <div class="card card-custom">
          <div class="card-header card-header-custom">
            <h3 class="card-title mb-0"><i class="fas fa-user-edit me-2"></i>User Details</h3>
          </div>
          <div class="card-body">
            <form method="post" id="registrationForm">
              <!-- Username -->
              <div class="form-floating mb-4">
                <input type="text" class="form-control" id="username" name="username" placeholder="Username" required>
                <label for="username">Username</label>
              </div>

              <!-- Email -->
              <div class="form-floating mb-4">
                <input type="email" class="form-control" id="email" name="email" placeholder="Email" required>
                <label for="email">Email</label>
              </div>

              <!-- Password -->
              <div class="mb-4 position-relative">
                <div class="form-floating">
                  <input type="password" class="form-control" id="password" name="password" placeholder="Password" required minlength="8">
                  <label for="password">Password</label>
                </div>
                <i class="fas fa-eye password-toggle" id="togglePassword"></i>
              </div>

              <!-- Role Selection -->
              <div class="mb-4">
                <label class="form-label fw-bold">User Role</label>
                <div class="row">
                  <!-- Super Admin -->
                  <div class="col-md-4 mb-3">
                    <div class="form-check">
                      <input class="form-check-input" type="radio" name="role" id="roleSuperAdmin" value="super_admin" required>
                      <label class="form-check-label" for="roleSuperAdmin">
                        <span class="role-badge role-super-admin"><i class="fas fa-crown me-1"></i>Super Admin</span>
                      </label>
                    </div>
                  </div>
                  
                  <!-- Admin -->
                  <div class="col-md-4 mb-3">
                    <div class="form-check">
                      <input class="form-check-input" type="radio" name="role" id="roleAdmin" value="admin">
                      <label class="form-check-label" for="roleAdmin">
                        <span class="role-badge role-admin"><i class="fas fa-user-shield me-1"></i>Admin</span>
                      </label>
                    </div>
                  </div>
                  
                  <!-- Tester -->
                  <div class="col-md-4 mb-3">
                    <div class="form-check">
                      <input class="form-check-input" type="radio" name="role" id="roleTester" value="tester">
                      <label class="form-check-label" for="roleTester">
                        <span class="role-badge role-tester"><i class="fas fa-flask me-1"></i>Tester</span>
                      </label>
                    </div>
                  </div>
                  
                  <!-- Test Viewer -->
                  <div class="col-md-4 mb-3">
                    <div class="form-check">
                      <input class="form-check-input" type="radio" name="role" id="roleTestViewer" value="test_viewer">
                      <label class="form-check-label" for="roleTestViewer">
                        <span class="role-badge role-test-viewer"><i class="fas fa-eye me-1"></i>Test Viewer</span>
                      </label>
                    </div>
                  </div>
                  
                  <!-- PM Manager -->
                  <div class="col-md-4 mb-3">
                    <div class="form-check">
                      <input class="form-check-input" type="radio" name="role" id="rolePmManager" value="pm_manager">
                      <label class="form-check-label" for="rolePmManager">
                        <span class="role-badge role-pm-manager"><i class="fas fa-tasks me-1"></i>PM Manager</span>
                      </label>
                    </div>
                  </div>
                  
                  <!-- PM Employee -->
                  <div class="col-md-4 mb-3">
                    <div class="form-check">
                      <input class="form-check-input" type="radio" name="role" id="rolePmEmployee" value="pm_employee">
                      <label class="form-check-label" for="rolePmEmployee">
                        <span class="role-badge role-pm-employee"><i class="fas fa-user-tie me-1"></i>PM Employee</span>
                      </label>
                    </div>
                  </div>
                  
                  <!-- PM Viewer -->
                  <div class="col-md-4 mb-3">
                    <div class="form-check">
                      <input class="form-check-input" type="radio" name="role" id="rolePmViewer" value="pm_viewer">
                      <label class="form-check-label" for="rolePmViewer">
                        <span class="role-badge role-pm-viewer"><i class="fas fa-clipboard-list me-1"></i>PM Viewer</span>
                      </label>
                    </div>
                  </div>
                </div>
              </div>

              <!-- System Access -->
              <div class="mb-4">
                <label for="systems" class="form-label fw-bold">System Access</label>
                <select multiple class="form-control" id="systems" name="systems[]" size="5">
                  <?php while ($row = $systems_result->fetch_assoc()): ?>
                    <option value="<?= $row['system_id'] ?>"><?= htmlspecialchars($row['system_name']) ?></option>
                  <?php endwhile; ?>
                </select>
                <div class="form-text">Hold CTRL (Windows) or CMD (Mac) to select multiple systems</div>
              </div>

              <!-- Submit -->
              <div class="d-grid gap-2">
                <button type="submit" class="btn btn-dashen-primary btn-lg py-3">
                  <i class="fas fa-user-plus me-2"></i>Register User
                </button>
              </div>
            </form>
          </div>
        </div>
      <?php else: ?>
        <div class="alert alert-danger mt-4">
          <i class="fas fa-ban me-2"></i>Access denied. Super Administrator privileges required.
        </div>
        <a href="dashboard.php" class="btn btn-dashen-primary mt-3">
          <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
        </a>
      <?php endif; ?>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      // Password toggle functionality
      const togglePassword = document.querySelector('#togglePassword');
      const password = document.querySelector('#password');
      togglePassword.addEventListener('click', function() {
        const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
        password.setAttribute('type', type);
        this.classList.toggle('fa-eye-slash');
      });
      
      // Role selection enhancement
      const roleBadges = document.querySelectorAll('.role-badge');
      roleBadges.forEach(badge => {
        badge.addEventListener('click', function() {
          const radioInput = this.closest('.form-check').querySelector('input[type="radio"]');
          radioInput.checked = true;
          
          // Remove active class from all badges
          roleBadges.forEach(b => b.classList.remove('active'));
          // Add active class to clicked badge
          this.classList.add('active');
        });
      });
      
      // Add active class based on initially checked radio
      document.querySelectorAll('input[name="role"]').forEach(radio => {
        if (radio.checked) {
          radio.closest('.form-check').querySelector('.role-badge').classList.add('active');
        }
      });
    });
  </script>
</body>
</html>