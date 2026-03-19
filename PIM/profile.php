<?php
require_once 'config.php';
requireLogin();

// Get user details
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Validate inputs
    if (empty($full_name) || empty($email)) {
    } else {
        // Check if email already exists (excluding current user)
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt->execute([$email, $_SESSION['user_id']]);
        if ($stmt->fetch()) {
            $error = "Email already exists";
        } else {
            // Update user details
            $update_data = [
                'full_name' => $full_name,
                'email' => $email,
                'id' => $_SESSION['user_id']
            ];
            
            // If password change is requested
            if (!empty($current_password) && !empty($new_password)) {
                if (!password_verify($current_password, $user['password'])) {
                    $error = "Current password is incorrect";
                } elseif ($new_password !== $confirm_password) {
                    $error = "New passwords do not match";
                } else {
                    $update_data['password'] = password_hash($new_password, PASSWORD_DEFAULT);
                }
            }
            
            if (empty($error)) {
                $set_clause = "full_name = :full_name, email = :email";
                if (isset($update_data['password'])) {
                    $set_clause .= ", password = :password";
                }
                
                $stmt = $pdo->prepare("UPDATE users SET $set_clause WHERE id = :id");
                $stmt->execute($update_data);
                
                $_SESSION['full_name'] = $full_name;
                $success = "Profile updated successfully";
                
                // Refresh user data
                $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
                $stmt->execute([$_SESSION['user_id']]);
                $user = $stmt->fetch();
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile - Issue Tracker</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    
    <link href="css/style.css" rel="stylesheet">
</head>
<body>
    <?php include 'sidebar.php'; ?>

    <div class="main-content">
        <header>
            <h2>User Profile</h2>
            <div class="user-info">
                <span>Hello, <?php echo $_SESSION['username']; ?></span>
                <a href="..logout.php" class="btn btn-sm btn-outline-danger ms-2">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </header>

        <div class="content">
            <div class="row justify-content-center">
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">Profile Information</h5>
                        </div>
                        <div class="card-body">
                            <?php if ($error): ?>
                                <div class="alert alert-danger"><?php echo $error; ?></div>
                            <?php endif; ?>
                            <?php if ($success): ?>
                                <div class="alert alert-success"><?php echo $success; ?></div>
                            <?php endif; ?>
                            
                            <form method="POST">
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label for="username" class="form-label">Username</label>
                                        <input type="text" class="form-control" id="username" value="<?php echo htmlspecialchars($user['username']); ?>" disabled>
                                        <div class="form-text">Username cannot be changed</div>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="role" class="form-label">Role</label>
                                        <input type="text" class="form-control" id="role" value="<?php echo ucfirst($user['role']); ?>" disabled>
                                    </div>
                                </div>
                                
                                
                                    <div class="col-md-6">
                                        <label for="email" class="form-label">Email</label>
                                        <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                                    </div>
                                </div>
                                
                                <hr>
                                
                                <h6 class="mb-3">Change Password</h6>
                                <div class="row mb-3">
                                    <div class="col-md-4">
                                        <label for="current_password" class="form-label">Current Password</label>
                                        <input type="password" class="form-control" id="current_password" name="current_password">
                                    </div>
                                    <div class="col-md-4">
                                        <label for="new_password" class="form-label">New Password</label>
                                        <input type="password" class="form-control" id="new_password" name="new_password">
                                    </div>
                                    <div class="col-md-4">
                                        <label for="confirm_password" class="form-label">Confirm Password</label>
                                        <input type="password" class="form-control" id="confirm_password" name="confirm_password">
                                    </div>
                                </div>
                                <div class="form-text">Leave password fields blank to keep current password</div>
                                
                                <div class="mt-4">
                                    <button type="submit" class="btn btn-primary">Update Profile</button>
                                    <a href="index.php" class="btn btn-secondary">Cancel</a>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>