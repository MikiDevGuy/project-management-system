<?php
session_start();
include 'db.php';
$active_dashboard = 'testcase_management';


if (!isset($_SESSION['user_id'])) {
    die('<div class="alert alert-danger">Access denied. Please log in.</div>');
}

// Get the project ID from the URL (used to prefill hidden input)
$project_id = $_GET['project_id'] ?? $_POST['project_id'] ?? null;

if (!$project_id) {
    die('<div class="alert alert-warning">Project ID is required.</div>');
}

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $title = trim($_POST['title']);
    $steps = trim($_POST['steps']);
    $expected = trim($_POST['expected']); 
    $status = $_POST['status'];
    //$priority = $_POST['priority'];
    //$frequency = trim($_POST['frequency']); 
    $channel = trim($_POST['channel']); 
    $tester_remark = trim($_POST['tester_remark']);  
    $vendor_comment = trim($_POST['vendor_comment']);
    $created_by = $_SESSION['user_id'];
    $feature_id = $_POST['feature_id'];


    //$stmt = $conn->prepare("INSERT INTO test_cases (project_id, title, steps, expected, status) VALUES (?, ?, ?, ?, ?)");
    //$stmt->bind_param("issss", $project_id, $title, $steps, $expected, $status);

    $stmt = $conn->prepare("INSERT INTO test_cases (project_id, title, steps, expected, status, priority, tester_remark, vendor_comment, created_by, feature_id) 
    VALUES (?, ?, ?, ?, ?, ?,?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("issssssssssi", $project_id, $title, $steps, $expected, $status,$priority,$tester_remark,$vendor_comment,$created_by, $feature_id);

    
    if ($stmt->execute()) {
        // After inserting a test case successfully, create a log
$log_stmt = $conn->prepare("INSERT INTO logs (user_id, action) VALUES (?, ?)");
$action = "Added test case '$title' to project ID $project_id";
$log_stmt->bind_param("is", $_SESSION['user_id'], $action);
$log_stmt->execute();

       
    } else {
        $error = "Failed to add test case. Please try again.";
    }
     header("Location: view_project.php?id=$project_id");
        exit;
}
include 'sidebar.php';
?>

<?php
// Fetch latest 5 logs
$logs = $conn->query("
  SELECT l.action, l.created_at, u.username 
  FROM logs l 
  JOIN users u ON u.id = l.user_id 
  ORDER BY l.created_at DESC 
  LIMIT 5
");


//fetch features from features table
$features = $conn->query("SELECT id, feature_name FROM features where project_id = $project_id ORDER BY feature_name");

?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Add Test Case</title>
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
    
    .back-link {
      color: var(--primary-color);
      text-decoration: none;
      transition: all 0.3s ease;
    }
    
    .back-link:hover {
      color: var(--accent-color);
      transform: translateX(-3px);
    }
    
    .form-label {
      font-weight: 600;
      color: var(--dark-color);
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
    <?php if ($_SESSION['system_role'] === 'super_admin' || $_SESSION['system_role'] === 'tester'): ?>
      <!-- Back Link -->
      <a href="view_project.php?id=<?= $project_id ?>" class="back-link mb-3 d-inline-block">
        <i class="fas fa-arrow-left me-2"></i>Back to Project
      </a>

      <!-- Hero Section -->
      <div class="hero-section">
        <h1><i class="fas fa-plus-circle me-2"></i>Add New Test Case</h1>
        <p class="lead mb-0">Project ID: <?= htmlspecialchars($project_id) ?></p><!--I think here it is better to display project name instade of the id-->
      </div>

      <!-- Error Message -->
      <?php if (isset($error)): ?>
        <div class="alert alert-danger mb-4">
          <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?>
        </div>
      <?php endif; ?>

      <!-- Add Test Case Form -->
      <div class="card card-custom">
        <div class="card-header card-header-custom">
          <h3 class="card-title mb-0"><i class="fas fa-edit me-2"></i>Test Case Details</h3>
        </div>
        <div class="card-body">
          <form action="add_testcase.php" method="post">
            <input type="hidden" name="project_id" value="<?= htmlspecialchars($project_id) ?>"> 
            
            <!-- Title Field -->
            <div class="mb-4">
              <label for="title" class="form-label">Test Case Title</label>
              <input type="text" class="form-control form-control-lg" id="title" name="title" 
                     placeholder="Enter test case title" required>
              <div class="form-text">A clear, descriptive title for the test case</div>
            </div>
            
            <!-- Steps Field -->
            <div class="mb-4">
              <label for="steps" class="form-label">Test Steps</label>
              <textarea class="form-control" id="steps" name="steps" rows="5" 
                        placeholder="Enter the steps to execute the test (one per line)" required></textarea>
              <div class="form-text">List each step on a new line</div>
            </div>
            
            <!-- Expected Result Field -->
            <div class="mb-4">
              <label for="expected" class="form-label">Expected Result</label>
              <textarea class="form-control" id="expected" name="expected" rows="3" 
                        placeholder="Describe the expected outcome" required></textarea>
              <div class="form-text">What should happen when the test passes</div>
            </div>

              <!-- Status Field -->
            <div class="mb-4">
              <label for="status" class="form-label">Initial Status</label>
              <select class="form-select" id="status" name="status" required>
                <option value="Pending" selected>Pending</option>
                <option value="Pass">Pass</option>
                <option value="Fail">Fail</option>
              </select>
              <div class="form-text">Set the initial status of this test case</div>
            </div> 
            <!-- priority  Field -->
            <div class="mb-4">
              <label for="priority" class="form-label">priority</label>
              <select class="form-select" id="priority" name="priority" required>
                <option value="medium" selected>Medium</option>
                <option value="high">High</option>
                <option value="low">Low</option>
              </select>
              <div class="form-text">Set the priority of this test case</div>
            </div>
            <!-- frequency Field 
            <div class="mb-4">
              <label for="frequency" class="form-label">Frequency</label>
              <input type="text" class="form-control" id="frequency" name="frequency" 
                     placeholder="Enter the frequency of the test case" required>
              <div class="form-text">A clear, descriptive title for the test case</div> ->
            </div>  -->
            <!-- channel Field 
            <div class="mb-4">
              <label for="channel" class="form-label">Channel</label>
              <input type="text" class="form-control" id="channel" name="channel" 
                     placeholder="Enter the channel of the test case" required>
              <!<div class="form-text">A clear, descriptive title for the test case</div>  ->
            </div> -->
            <!-- tester remark Field -->
            <div class="mb-4">
              <label for="tester_remark" class="form-label">Tester remark</label>
              <textarea class="form-control" id="tester_remark" name="tester_remark" rows="3" 
                        placeholder="Please put your comment here!" required></textarea>
              <div class="form-text">What happen when you test</div>
            </div>
            <!-- vendor remark or comment Field -->
             <?php if($_SESSION['system_role'] == 'viewer'):?>
            <div class="mb-4">
              <label for="vendor_comment" class="form-label">Vendor remark</label>
              <textarea class="form-control" id="vendor_comment" name="vendor_comment" rows="3" 
                        placeholder="Please put your comment here!" required></textarea>
              <div class="form-text">What is your recommendation for this case!</div>
            </div> 
            <?php endif; ?>
       <!--Features Field-->
       <div class="mb-3">
  <label>Feature</label>
  <select name="feature_id" class="form-select" required>
    <option value="">-- Select Feature --</option>
    <?php while ($f = $features->fetch_assoc()): ?>
      <option value="<?= $f['id'] ?>"><?= htmlspecialchars($f['feature_name']) ?></option>
    <?php endwhile; ?>
  </select>
    </div>

            
          
            
            <!-- Submit Button -->
            <div class="d-grid gap-2">
              <button type="submit" class="btn btn-primary-custom btn-lg">
                <i class="fas fa-plus-circle me-2"></i>Add Test Case
              </button>
            </div>
          </form>
        </div>
      </div>
    <?php else: ?>
      <!-- Access Denied Message -->
      <div class="alert alert-danger mt-4">
        <i class="fas fa-ban me-2"></i>Access denied. You must be an admin or tester to create test cases.
      </div>
      <a href="view_project.php?id=<?= $project_id ?>" class="btn btn-primary-custom mt-3">
        <i class="fas fa-arrow-left me-2"></i>Back to Project
      </a>
    <?php endif; ?>
  </div>

  <!-- Bootstrap JS Bundle with Popper -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>