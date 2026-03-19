<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    die('<div class="alert alert-danger">Access denied. Please log in.</div>');
}
include 'db.php';

// Display status message if exists
if (isset($_SESSION['form_status'])) {
    $status = $_SESSION['form_status'];
    unset($_SESSION['form_status']);
    $alertClass = $status['success'] ? 'alert-success' : 'alert-danger';
    echo '<div class="alert '.$alertClass.' mb-3">'.$status['message'].'</div>';
}

if (!isset($_GET['id'])) {
    die('<div class="alert alert-warning">Project ID not provided.</div>');
}
$project_id = $_GET['id'];
if(!$project_id){
    echo "<script> alert('project id not found') </script>";
}

$user_role = $_SESSION['system_role'];
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['username'];

// First check project access
if ($user_role === 'super_admin') {
    $stmt = $conn->prepare("SELECT * FROM projects WHERE id = ?");
    $stmt->bind_param("i", $project_id);
} else {
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
    die('<div class="alert alert-warning">You do not have access to this project.</div>');
}

$project = $result->fetch_assoc();

$statusFilter = $_GET['status'] ?? '';

// Build the phase query based on user role
if ($user_role === 'super_admin' || $user_role === 'pm_manager') {
    // Admins and PM managers can see all phases in the project
    $sql = "SELECT p.* FROM phases p WHERE p.project_id = ?";
    if ($statusFilter) {
        $sql .= " AND p.status = ?";
    }
} else {
    // Other users can only see phases they're assigned to
    $sql = "SELECT DISTINCT p.* FROM phases p 
            JOIN phase_users pu ON p.id = pu.phase_id 
            WHERE p.project_id = ? AND pu.user_id = ?";
    if ($statusFilter) {
        $sql .= " AND p.status = ?";
    }
}

$sql .= " ORDER BY p.name";

$stmt = $conn->prepare($sql);

// Bind parameters based on query
if ($user_role === 'super_admin' || $user_role === 'pm_manager') {
    if ($statusFilter) {
        $stmt->bind_param("is", $project_id, $statusFilter);
    } else {
        $stmt->bind_param("i", $project_id);
    }
} else {
    if ($statusFilter) {
        $stmt->bind_param("iis", $project_id, $user_id, $statusFilter);
    } else {
        $stmt->bind_param("ii", $project_id, $user_id);
    }
}

$stmt->execute();
$phases = $stmt->get_result();

$highlight_id = isset($_GET['highlight_testcase']) ? intval($_GET['highlight_testcase']) : null;
$allowed_statuses = ['pending', 'in_progress', 'completed'];

 ?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($project['name']) ?></title>
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
    
    .status-badge {
      padding: 5px 10px;
      border-radius: 50px;
      font-size: 0.85rem;
      font-weight: 600;
    }
    
    .status-pass {
      background-color: #d4edda;
      color: #155724;
    }
    
    .status-fail {
      background-color: #f8d7da;
      color: #721c24;
    }
    
    .status-pending {
      background-color: #fff3cd;
      color: #856404;
    }
    
    .testcase-row:hover {
      background-color: rgba(78, 115, 223, 0.05);
    }
    
    .action-btn {
      transition: all 0.2s ease;
    }
    
    .action-btn:hover {
      transform: translateY(-2px);
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
    
    .table-responsive {
      box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.1);
    }
    
    .table th {
      background-color: var(--primary-color);
      color: white;
      position: sticky;
      top: 0;
    }

  /* hilight test cases based on clicked notification */
  
.highlighted-row {
    background-color: #fff3cd !important;
    animation: flash 1.5s ease-in-out;
}
@keyframes flash {
    0% { background-color: #fff3cd; }
    50% { background-color: #ffeeba; }
    100% { background-color: #fff3cd; }
}


  </style>
</head>
<body>
  <!-- Navigation Bar -->
  <nav class="navbar navbar-expand-lg navbar-dark navbar-custom mb-4">
    <div class="container-fluid">
      <a class="navbar-brand" href="#">
        <i class="fas fa-flask me-2"></i>Test Manager
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
    <!--<a href="#" onclick="history.back(); return false;" class="back-link mb-3 d-inline-block"> -->
        <a href="view_phase_for_index.php" class="back-link mb-3 d-inline-block">
         <i class="fas fa-arrow-left me-2"></i>Back to Projects
        </a>
    <!-- Hero Section -->
    <div class="hero-section">
      <h1><i class="fas fa-project-diagram me-2"></i><?= htmlspecialchars($project['name']) ?></h1>
      <p class="lead mb-0"><?= htmlspecialchars($project['description']) ?></p>
    </div>

    <!-- Test Cases Section -->
  <div class="card card-custom mb-4">
    <div class="card-header card-header-custom d-flex justify-content-between align-items-center">
      <h3 class="card-title mb-0"><i class="fas fa-list-check me-2"></i>Phases</h3>
      <div class="d-flex gap-2">
        <?php if ($user_role === 'super_admin' || $user_role === 'pm_manager'): ?>
          <a href="phases.php?project_id=<?= $project_id ?>" class="btn btn-sm btn-success">
            <i class="fas fa-plus me-1"></i> Add Phase
          </a>
        <?php endif; ?>
      </div>
    </div>
    <div class="card-body">
      <!-- Filter Form -->
      <form method="get" class="mb-4">
        <input type="hidden" name="id" value="<?= $project_id ?>">
        <div class="row g-3 align-items-center">
          <div class="col-auto">
            <label class="col-form-label">Filter by Status:</label>
          </div>
          <div class="col-auto">
            <select name="status" class="form-select" onchange="this.form.submit()">
              <option value="">All Statuses</option>
              <option value="completed" <?= ($_GET['status'] ?? '') == 'completed' ? 'selected' : '' ?>>Completed</option>
              <option value="in_progress" <?= ($_GET['status'] ?? '') == 'in_progress' ? 'selected' : '' ?>>In progress</option>
              <option value="pending" <?= ($_GET['status'] ?? '') == 'pending' ? 'selected' : '' ?>>Pending</option>
            </select>
          </div>
        </div>
      </form>

      <!-- Phases Table -->
      <div class="table-responsive">
        <table class="table table-hover align-middle">
          <thead>
            <tr>
              <th>Name</th>
              <th>Description</th>
              <th>Start Date</th>
              <th>End Date</th>
              <th>Status</th>
              <?php if($user_role == 'super_admin' || $user_role == 'pm_manager'): ?>
                <th class="text-center">Actions</th>
              <?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php if ($phases->num_rows > 0): ?>
              <?php while ($phase = $phases->fetch_assoc()) : ?>
                <tr id="phase-<?= $phase['id'] ?>" class="<?= ($highlight_id == $phase['id']) ? 'highlighted-row' : '' ?>">
                  <td class="fw-bold"><?= htmlspecialchars($phase['name']) ?></td>
                  <td><?= nl2br(htmlspecialchars($phase['description'])) ?></td>
                  <td><?= $phase['start_date'] ? date('Y-m-d', strtotime($phase['start_date'])) : 'Not set' ?></td>
                  <td><?= $phase['end_date'] ? date('Y-m-d', strtotime($phase['end_date'])) : 'Not set' ?></td>
                  <td>
                    <?php if ($user_role === 'pm_employee' || $user_role === 'pm_manager'): ?>
                      <select class="form-select status-select" data-phase-id="<?= htmlspecialchars($phase['id']) ?>">
                        <?php foreach ($allowed_statuses as $status_option): ?>
                          <option value="<?= htmlspecialchars($status_option) ?>"
                            <?= ($phase['status'] === $status_option) ? 'selected' : '' ?>>
                            <?= htmlspecialchars(ucwords(str_replace('_', ' ', $status_option))) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    <?php else: ?>
                      <?= htmlspecialchars(ucwords(str_replace('_', ' ', $phase['status']))) ?>
                    <?php endif; ?>
                  </td>
                  
                  <?php if($user_role == 'super_admin' || $user_role == 'pm_manager'): ?>
                    <td class="text-center">
                      <div class="d-flex justify-content-center gap-2">
                        <a href="edit_phases.php?id=<?= $phase['id'] ?>&project_id=<?= $project_id ?>" 
                           class="btn btn-sm btn-outline-primary action-btn" title="Edit">
                          <i class="fas fa-edit"></i>
                        </a>
                        <a href="delete_phase.php?id=<?= $phase['id'] ?>&project_id=<?= $project_id ?>" 
                           class="btn btn-sm btn-outline-danger action-btn" title="Delete"
                           onclick="return confirm('Are you sure you want to delete this phase?');">
                          <i class="fas fa-trash-alt"></i>
                        </a>
                        <?php if($user_role == 'super_admin' || $user_role == 'pm_manager' || $user_role == 'pm_manager'): ?>
                        <a href="view_assigned_activities.php?id=<?= $phase['id'] ?>" class="btn btn-sm btn-success action-btn" title="View Activities">
                          <i class="fas fa-eye"></i>
                        </a>
                        <?php endif; ?>
                        <a href="assign_phase_to_user.php?id=<?= $phase['id'] ?>" class="btn btn-sm btn-success action-btn" title="Assign Users">
                          <i class="fas fa-user-plus"></i>
                        </a>
                      </div>
                    </td>
                  <?php endif; ?>
                  <td>
                    <?php if($user_role == 'pm_employee'): ?>
                        <a href="view_assigned_activities.php?id=<?= $phase['id'] ?>" class="btn btn-sm btn-success action-btn" title="View Activities">
                          <i class="fas fa-eye"></i>
                        </a>
                        <?php endif; ?>
                  </td>
                </tr>
              <?php endwhile; ?>
            <?php else: ?>
              <tr>
                <td colspan="<?= ($user_role == 'super_admin' || $user_role == 'pm_manager') ? 6 : 5 ?>" class="text-center text-muted py-4">
                  No phases found. <?= $statusFilter ? 'Try adjusting your filters.' : '' ?>
                </td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <script>
    const targetId = "<?= $highlight_id ? "phase-" . $highlight_id : "" ?>";
    if (targetId) {
      const el = document.getElementById(targetId);
      if (el) {
        setTimeout(() => el.scrollIntoView({ behavior: 'smooth', block: 'center' }), 500);
      }
    } 
  </script>

  <script>
    document.addEventListener('DOMContentLoaded', function() {
      const statusSelects = document.querySelectorAll('.status-select');
      
      statusSelects.forEach(selectElement => {
        selectElement.addEventListener('change', function() {
          const newStatus = this.value;
          const phaseId = this.dataset.phaseId;
          
          fetch('update_phase_status.php', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `phase_id=${encodeURIComponent(phaseId)}&new_status=${encodeURIComponent(newStatus)}`
          })
          .then(response => response.json())
          .then(data => {
            if (data.success) {
              alert('Status updated successfully!');
            } else {
              alert('Error: ' + data.message);
              // Optionally revert the select to its previous value
            }
          })
          .catch(error => {
            console.error('Error:', error);
            alert('An error occurred while updating the status');
          });
        });
      });
    });
  </script>

  <!-- Bootstrap JS Bundle with Popper -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

