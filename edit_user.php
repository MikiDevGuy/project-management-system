<?php
session_start();
include 'db.php';

// Check if user is admin
if ($_SESSION['system_role'] !== 'super_admin') {
    die('<div class="alert alert-danger">Access denied. Admins only.</div>');
}

$user_id = $_GET['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $uname = trim($_POST['username']);
    $role = $_POST['system_role'];

    $stmt = $conn->prepare("UPDATE users SET username=?, system_role=? WHERE id=?");
    $stmt->bind_param("ssi", $uname, $role, $user_id);
    
    if ($stmt->execute()) {
        $_SESSION['success'] = "User updated successfully!";
        header("Location: display_user.php");
        exit;
    } else {
        $error = "Failed to update user. Please try again.";
    }
}

$user = $conn->query("SELECT * FROM users WHERE id=$user_id")->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit User - Test Manager</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Custom Styles -->
    <style>
        :root {
            --primary-color: #4e73df;
            --secondary-color: #f8f9fc;
            --accent-color: #2e59d9;
            --dark-color: #5a5c69;
            --light-color: #ffffff;
            --success-color: #1cc88a;
            --danger-color: #e74a3b;
            --warning-color: #f6c23e;
            --info-color: #36b9cc;
        }

        body {
            background-color: #f8f9fa;
            font-family: 'Nunito', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
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
            padding: 1rem 1.35rem;
        }

        .welcome-banner {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--accent-color) 100%);
            color: white;
            border-radius: 0.35rem;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
        }

        .role-badge {
            padding: 0.35rem 0.65rem;
            border-radius: 0.25rem;
            font-weight: 600;
        }

        .badge-admin {
            background-color: rgba(78, 115, 223, 0.1);
            color: var(--primary-color);
        }

        .badge-tester {
            background-color: rgba(28, 200, 138, 0.1);
            color: var(--success-color);
        }

        .badge-viewer {
            background-color: rgba(108, 117, 125, 0.1);
            color: #6c757d;
        }

        .btn-primary-custom {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            padding: 0.75rem 1.5rem;
            font-weight: 600;
            border-radius: 0.35rem;
        }

        .btn-primary-custom:hover {
            background-color: var(--accent-color);
            border-color: var(--accent-color);
        }

        .form-floating label {
            color: var(--dark-color);
        }

        .form-control, .form-select {
            border-radius: 0.35rem;
            padding: 0.75rem 1rem;
            border: 1px solid #d1d3e2;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(78, 115, 223, 0.25);
        }

        .password-toggle {
            cursor: pointer;
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            z-index: 5;
        }
    </style>
</head>
<body>
    <!-- Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-dark navbar-custom mb-4">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">
                <i class="fas fa-tachometer-alt me-2"></i>Test Manager
            </a>
            <div class="d-flex align-items-center">
                <span class="text-white me-3">
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
        <a href="display_user.php" class="btn btn-outline-secondary mb-3">
            <i class="fas fa-arrow-left me-2"></i>Back to Users
        </a>

        <!-- Welcome Banner -->
        <div class="welcome-banner">
            <h1><i class="fas fa-user-edit me-2"></i>Edit User</h1>
            <p class="lead mb-0">Update user details and access level</p>
        </div>

        <!-- Error Message -->
        <?php if (isset($error)): ?>
            <div class="alert alert-danger mb-4">
                <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <!-- Edit Form -->
        <div class="card card-custom">
            <div class="card-header card-header-custom">
                <h3 class="card-title mb-0"><i class="fas fa-user-edit me-2"></i>User Details</h3>
            </div>
            <div class="card-body">
                <form method="post" id="editUserForm">
                    <!-- Username Field -->
                    <div class="form-floating mb-4">
                        <input type="text" class="form-control" id="username" name="username" 
                               value="<?= htmlspecialchars($user['username']) ?>" placeholder="Username" required>
                        <label for="username">Username</label>
                        <div class="form-text">Edit the username as needed</div>
                    </div>
                    
                    <!-- Role Selection -->
                    <div class="mb-4">
                        <label class="form-label">User Role</label>
                        <div class="d-flex gap-3">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="role" id="roleAdmin" 
                                       value="admin" <?= $user['role'] == 'super_admin' ? 'checked' : '' ?> required>
                                <label class="form-check-label" for="roleAdmin">
                                    <span class="role-badge badge-admin">
                                        <i class="fas fa-crown me-1"></i>Admin
                                    </span>
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="role" id="roleTester" 
                                       value="tester" <?= $user['system_role'] == 'tester' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="roleTester">
                                    <span class="role-badge badge-tester">
                                        <i class="fas fa-flask me-1"></i>Tester
                                    </span>
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="role" id="roleViewer" 
                                       value="viewer" <?= $user['system_role'] == 'viewer' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="roleViewer">
                                    <span class="role-badge badge-viewer">
                                        <i class="fas fa-eye me-1"></i>Viewer
                                    </span>
                                </label>
                            </div>
                        </div>
                        <div class="form-text mt-2">Select the appropriate access level</div>
                    </div>
                    
                    <!-- Submit Button -->
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary-custom btn-lg">
                            <i class="fas fa-save me-2"></i>Update User
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Form validation
            const form = document.getElementById('editUserForm');
            form.addEventListener('submit', function(e) {
                const username = document.getElementById('username').value.trim();
                if (username.length < 3) {
                    e.preventDefault();
                    alert('Username must be at least 3 characters long.');
                }
            });
        });
    </script>
</body>
</html>