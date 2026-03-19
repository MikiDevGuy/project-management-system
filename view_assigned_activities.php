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
    die('<div class="alert alert-warning">Phase ID not provided.</div>');
}
$phase_id = $_GET['id'];

$user_role = $_SESSION['system_role'];
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['username'];

// First check phase access
if ($user_role === 'super_admin') {
    $stmt = $conn->prepare("SELECT p.*, ph.name as phase_name FROM phases ph JOIN projects p ON ph.project_id = p.id WHERE ph.id = ?");
    $stmt->bind_param("i", $phase_id);
} elseif ($user_role === 'pm_manager') {
    $stmt = $conn->prepare("
        SELECT p.*, ph.name as phase_name FROM phases ph 
        JOIN projects p ON ph.project_id = p.id
        JOIN project_users pu ON p.id = pu.project_id
        WHERE ph.id = ? AND pu.user_id = ?
    ");
    $stmt->bind_param("ii", $phase_id, $user_id);
} else {
    // For other roles, check if they're assigned to this phase
    $stmt = $conn->prepare("
        SELECT p.*, ph.name as phase_name FROM phases ph
        JOIN projects p ON ph.project_id = p.id
        JOIN phase_users pu ON ph.id = pu.phase_id
        WHERE ph.id = ? AND pu.user_id = ?
    ");
    $stmt->bind_param("ii", $phase_id, $user_id);
}

$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die('<div class="alert alert-warning">You do not have access to this phase.</div>');
}

$phase = $result->fetch_assoc();

$statusFilter = $_GET['status'] ?? '';

// Build the activities query based on user role
if ($user_role === 'super_admin' || $user_role === 'pm_manager') {
    // Admins and PM managers can see all activities in the phase
    $sql = "SELECT a.* FROM activities a WHERE a.phase_id = ?";
    if ($statusFilter) {
        $sql .= " AND a.status = ?";
    }
} else {
    // Other users can only see activities they're assigned to
    $sql = "SELECT DISTINCT a.* FROM activities a 
            JOIN activity_users au ON a.id = au.activity_id 
            WHERE a.phase_id = ? AND au.user_id = ?";
    if ($statusFilter) {
        $sql .= " AND a.status = ?";
    }
}

$sql .= " ORDER BY a.name";

$stmt = $conn->prepare($sql);

// Bind parameters based on query
if ($user_role === 'super_admin' || $user_role === 'pm_manager') {
    if ($statusFilter) {
        $stmt->bind_param("is", $phase_id, $statusFilter);
    } else {
        $stmt->bind_param("i", $phase_id);
    }
} else {
    if ($statusFilter) {
        $stmt->bind_param("iis", $phase_id, $user_id, $statusFilter);
    } else {
        $stmt->bind_param("ii", $phase_id, $user_id);
    }
}

$stmt->execute();
$activities = $stmt->get_result();

$highlight_id = isset($_GET['highlight_activity']) ? intval($_GET['highlight_activity']) : null;
$allowed_statuses = ['pending', 'in_progress', 'completed'];


//Status badge controller
function getStatusColor($status) {
    switch(strtolower($status)) {
        case 'pending':
            return '#fff3cd';
        case 'in_progress':
            return '#cce5ff';
        case 'completed':
            return '#d4edda';
        default:
            return '#ffffff';
    }
}

?>



<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($phase['phase_name']) ?></title>
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
        <i class="fas fa-flask me-2"></i>Project Manager
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
     <!-- Back Link - now points to view_activities.php -->
    <a href="view_phases.php?id=<?= $phase['id'];?>" class="back-link mb-3 d-inline-block">
      <i class="fas fa-arrow-left me-2"></i>Back to Phases
    </a>
 
    <!-- Hero Section-->
    <div class="hero-section">
      <div class="card card-custom mb-4">
      <div class="card-header card-header-custom d-flex justify-content-between align-items-center">
        <div class="d-flex gap-2">
      <h1><i class="fas fa-project-diagram me-2"></i><?= htmlspecialchars($phase['phase_name']) ?></h1> </div>
      <p class="lead mb-0"><?= htmlspecialchars($phase['description']) ?></p>

      <div class="d-flex gap-2">
        <?php if ($user_role === 'super_admin' || $user_role === 'pm_manager'): ?>
          <a href="activities.php?project_id=<?= $phase_id ?>" class="btn btn-sm btn-success">
            <i class="fas fa-plus me-1"></i> Add Acitvities
          </a>
        <?php endif; ?>
      </div>
      </div> 
        </div></div>
    <!-- activity Section -->
    <div class="card card-custom mb-4">
      <div class="card-header card-header-custom d-flex justify-content-between align-items-center">
        <h3 class="card-title mb-0"><i class="fas fa-list-check me-2"></i>Activities</h3>
        <div class="d-flex gap-2">
          <!-- Export Button -->
          <form method="post" action="export_csv.php" class="me-2">
            <input type="hidden" name="project_id" value="<?= $project_id ?>">
            <button type="submit" class="btn btn-sm btn-light">
              <i class="fas fa-file-export me-1"></i> Export CSV
            </button>
          </form>
          
         
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
            <div class="col-md-3">
              <select name="status" class="form-select" onchange="this.form.submit()">
                <option value="">All Statuses</option>
                <option value="completed" <?= ($_GET['status'] ?? '') == 'completed' ? 'selected' : '' ?>>Completed</option>
                <option value="in_progress" <?= ($_GET['status'] ?? '') == 'in_progress' ? 'selected' : '' ?>>In progress</option>
                <option value="pending" <?= ($_GET['status'] ?? '') == 'pending' ? 'selected' : '' ?>>Pending</option>
              </select>
            </div>
          </div>
        </form>

        <!-- Test Cases Table -->
        <div class="table-responsive">
          <table class="table table-hover align-middle">
            <thead>
              <tr>
                <th>Activity_ID</th> 
                <th>Project_ID</th>
                <th>Name</th>
                <th>Description</th>
                <th>is_milestone</th>
                <th>priority</th>
                <th>start_date</th>
                <th>end_date</th>
                <th>status</th>
                <th>created_at</th>
                <th>updated_at</th>
                <?php if($_SESSION['system_role'] == 'super_admin' || $_SESSION['system_role'] == 'pm_manager'): ?>
                  <th class="text-center">Actions</th>
                <?php endif; ?>
              </tr>
            </thead>
            <tbody>
              <?php while ($activity = $activities->fetch_assoc()) : ?>
  <!--hilighting phases from notification-->
                  <tr id="testcase-<?= $activity['id'] ?>" class="<?= ($highlight_id == $activity['id']) ? 'highlighted-row' : '' ?>">
                  <td><?= $activity['id'] ?></td>
                
                  <td><?= $activity['project_id'] ?></td> 
                <!--  <tr class="phases-row"> -->
                  <td class="fw-bold"><?= htmlspecialchars($activity['name']) ?></td>
                  <td><?= nl2br(htmlspecialchars($activity['description'])) ?></td>
                  <td><?= $activity['is_milestone'] ?></td>
                  <td><?= $activity['priority'] ?></td>
                  <td><?= nl2br(htmlspecialchars($activity['start_date'])) ?></td>
                  <td><?= nl2br(htmlspecialchars($activity['end_date'])) ?></td>
                  <td>
                      <?php if ($user_role === 'pm_employee' || $user_role === 'pm_manager'): ?>
                          <select class="form-select status-select <?= 'status-' . strtolower($activity['status']) ?>" 
                                  data-activity-id="<?= htmlspecialchars($activity['id']) ?>"
                                  style="min-width: 120px; background-color: <?= getStatusColor($activity['status']) ?>;">
                              <?php foreach ($allowed_statuses as $status_option): ?>
                                  <option value="<?= htmlspecialchars($status_option) ?>"
                                      <?= ($activity['status'] === $status_option) ? 'selected' : '' ?>>
                                      <?= htmlspecialchars(ucwords(str_replace('_', ' ', $status_option))) ?>
                                  </option>
                              <?php endforeach; ?>
                          </select>
                      <?php else: ?>
                          <span class="badge status-badge status-<?= strtolower($activity['status']) ?>">
                              <?= htmlspecialchars(ucwords(str_replace('_', ' ', $activity['status']))) ?>
                          </span>
                      <?php endif; ?>
                  </td>
                  <td class="fw"><?= htmlspecialchars($activity['created_at']) ?></td>
                  <td class="fw"><?= htmlspecialchars($activity['updated_at']) ?></td>
                
                
                  <?php if($_SESSION['system_role'] == 'super_admin' || $_SESSION['system_role'] == 'pm_manager'): ?>
                    <td class="text-center">
                      <div class="d-flex justify-content-center gap-2">
                        <a href="edit_activities.php?id=<?= $activity['id'] ?>&project_id=<?= $project_id ?>" 
                           class="btn btn-sm btn-outline-primary action-btn" title="Edit">
                          <i class="fas fa-edit"></i>
                        </a>
                        <a href="delete_activities.php?id=<?= $activity['id'] ?>&project_id=<?= $project_id ?>" 
                           class="btn btn-sm btn-outline-danger action-btn" title="Delete"
                           onclick="return confirm('Are you sure you want to delete this test case?');">
                          <i class="fas fa-trash-alt"></i>
                        </a>
                        <a href="view_assigned_sub_activities.php?id=<?= $activity['id'] ?>" class="btn btn-sm btn-success action-btn" title="View Sub-Activities">
                          <i class="fas fa-eye"></i>
                        </a>
                        <a href="assign_activity_to_user.php?id=<?= $activity['id'] ?>" class="btn btn-sm btn-success action-btn" title="Assign Users">
                          <i class="fas fa-user-plus"></i>
                        </a>
                      </div>
                    </td>
                  <?php endif; ?>
                  <?php if($user_role == 'pm_employee'): ?>
                    <td>
                      <a href="view_assigned_sub_activities.php?id=<?= $activity['id'] ?>" class="btn btn-sm btn-success action-btn" title="View Sub-Activities">
                          <i class="fas fa-eye"></i>
                        </a>
                    </td>
                  
                  <?php endif; ?>
                </tr>
              <?php endwhile; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
 <script>
  const targetId = "<?= $highlight_id ? "testcase-" . $highlight_id : "" ?>";
  if (targetId) {
    const el = document.getElementById(targetId);
    if (el) {
      setTimeout(() => el.scrollIntoView({ behavior: 'smooth', block: 'center' }), 500);
    }
  } 


    document.addEventListener('DOMContentLoaded', function() {
            // Select all dropdowns that have the class 'status-select'
            const statusSelects = document.querySelectorAll('.status-select');

            // Loop through each status select element
            statusSelects.forEach(selectElement => {
                // Add an event listener for the 'change' event on each select element
                selectElement.addEventListener('change', function() {
                    // 'this' refers to the <select> element that was changed
                    const newStatus = this.value; // Get the currently selected value (e.g., 'in_progress')
                    // Get the activity ID from the 'data-activity-id' attribute
                    const activityId = this.dataset.activityId; // dataset gives access to data-* attributes

                    console.log(`Activity ID: ${activityId}, New Status: ${newStatus}`);

                    // Create a new XMLHttpRequest object (used for AJAX requests)
                    const xhr = new XMLHttpRequest();
                    // Configure the request: POST method, URL of our update script, asynchronous
                    xhr.open('POST', 'update_activity_status.php', true);
                    // Set the content type header for POST requests
                    xhr.setRequestHeader('Content-type', 'application/x-www-form-urlencoded');

                    // Define what happens when the AJAX request completes
                    xhr.onload = function() {
                        if (xhr.status >= 200 && xhr.status < 300) { // Check if request was successful (2xx status codes)
                            const response = JSON.parse(xhr.responseText); // Parse the JSON response from PHP
                            if (response.success) {
                                console.log('Update successful:', response.message);
                                // Optional: Provide user feedback (e.g., a Bootstrap toast or alert)
                                alert('Status updated successfully!');
                            } else {
                                console.error('Update failed:', response.message);
                                alert('Failed to update status: ' + response.message);
                                // Optional: Revert the dropdown to its previous state if update failed
                                // This requires storing the original value, or fetching it again.
                                // For simplicity, we just alert.
                            }
                        } else { // Request failed (e.g., 404, 500 errors)
                            console.error('Request failed with status:', xhr.status, xhr.statusText);
                            alert('An error occurred during the request. Please try again.');
                        }
                    };

                    // Define what happens if there's a network error
                    xhr.onerror = function() {
                        console.error('Network error during status update.');
                        alert('Network error. Could not connect to the server.');
                    };

                    // Send the request with the data
                    // We send data in URL-encoded format: 'key1=value1&key2=value2'
                    xhr.send(`activity_id=${encodeURIComponent(activityId)}&new_status=${encodeURIComponent(newStatus)}`);
                });
            });
        });
  </script>
  <!-- Bootstrap JS Bundle with Popper -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
 
</body>
</html>

