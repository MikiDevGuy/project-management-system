<?php

session_start();
if (!isset($_SESSION['user_id'])) {
    die('<div class="alert alert-danger">Access denied. Please log in.</div>');
}
include 'db.php';
$active_dashboard = 'testcase_management';
include 'sidebar.php';
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

$role = $_SESSION['system_role'];
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['username'];

if ($role === 'super_admin') {
    // Admins can view any project
    $stmt = $conn->prepare("SELECT * FROM projects WHERE id = ?");
    $stmt->bind_param("i", $project_id);
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
    die('<div class="alert alert-warning">You do not have access to this project.</div>');
}

$project = $result->fetch_assoc();

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

$highlight_id = isset($_GET['highlight_testcase']) ? intval($_GET['highlight_testcase']) : null;


//Featching Feature name using JOIN
$testCasesWith = $conn->query("
    SELECT 
        tc.id AS test_case_id,
        tc.title AS test_case_name,
        f.feature_name
    FROM 
        test_cases tc
    JOIN 
        features f ON tc.feature_id = f.id
");

$testCasesWithFeatures = $testCasesWith->fetch_assoc();
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
      --dashen-blue:#191970;
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

    /* Sidebar Styling */
    .sidebar {
      background-color: var(--dashen-blue) !important;
      min-height: 100vh;
      box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
    }

    .sidebar .logo-container {
      padding: 1.5rem 1rem;
      border-bottom: 1px solid rgba(255, 255, 255, 0.1);
      text-align: center;
    }

    .sidebar .logo-container img {
      max-width: 180px;
      height: auto;
    }

    .card-custom {
      border: none;
      border-radius: 0.5rem;
      box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.1);
      transition: transform 0.3s ease, box-shadow 0.3s ease;
    }

    .card-custom:hover {
      transform: translateY(-5px);
      box-shadow: 0 0.5rem 1.5rem rgba(58, 59, 69, 0.15);
    }

    .card-header-custom {
      background:#191970;
      color: white;
      border-bottom: none;
      border-radius: 0.5rem 0.5rem 0 0 !important;
      padding: 1rem 1.35rem;
    }

    .stat-card {
      border-left: 0.25rem solid;
      transition: all 0.3s ease;
      position: relative;
      overflow: hidden;
    }

    .stat-card::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 4px;
      background: linear-gradient(90deg, var(--primary-color), var(--accent-color));
      transform: scaleX(0);
      transform-origin: left;
      transition: transform 0.3s ease;
    }

    .stat-card:hover::before {
      transform: scaleX(1);
    }

    .stat-card:hover {
      box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
    }

    .stat-card-primary {
      border-left-color: var(--primary-color);
    }

    .stat-card-success {
      border-left-color: var(--success-color);
    }

    .stat-card-info {
      border-left-color: var(--info-color);
    }

    .stat-card-warning {
      border-left-color: var(--warning-color);
    }

    .stat-card-danger {
      border-left-color: var(--danger-color);
    }

    .stat-value {
      font-size: 1.75rem;
      font-weight: 700;
    }

    .stat-icon {
      font-size: 2rem;
      opacity: 0.7;
    }

    .activity-item {
      position: relative;
      padding-left: 2rem;
      border-left: 1px solid #e3e6f0;
      margin-bottom: 1.5rem;
    }

    .activity-item:last-child {
      margin-bottom: 0;
      border-left: 1px solid transparent;
    }

    .activity-badge {
      position: absolute;
      left: -0.5rem;
      width: 1rem;
      height: 1rem;
      border-radius: 50%;
    }

    .activity-badge-primary {
      background-color: var(--primary-color);
    }

    .activity-badge-success {
      background-color: var(--success-color);
    }

    .activity-badge-danger {
      background-color: var(--danger-color);
    }

    .activity-badge-warning {
      background-color: var(--warning-color);
    }

    .activity-time {
      font-size: 0.8rem;
      color: #b7b9cc;
    }

    .chart-area {
      position: relative;
      height: 15rem;
      width: 100%;
    }

    @media (min-width: 768px) {
      .chart-area {
        height: 20rem;
      }
    }

    .welcome-banner {
      background:#191970;
      color: white;
      border-radius: 0.5rem;
      padding: 1.5rem;
      margin-bottom: 2rem;
      box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
      position: relative;
      overflow: hidden;
    }

    .welcome-banner::before {
      content: '';
      position: absolute;
      top: -50%;
      right: -50%;
      width: 100%;
      height: 200%;
      background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 0%, rgba(255, 255, 255, 0) 70%);
    }

    .notification-description {
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      max-width: 100%;
    }

    .notification-badge {
      position: absolute;
      top: -5px;
      right: -5px;
    }

    .dropdown-notifications {
      width: 350px;
      max-width: 90vw;
    }

    .table-hover tbody tr:hover {
      background-color: rgba(78, 115, 223, 0.05);
    }

    .btn-outline-dashen {
      color: var(--dashen-blue);
      border-color: var(--dashen-blue);
    }

    .btn-outline-dashen:hover {
      background-color: var(--dashen-blue);
      color: white;
    }

    .status-badge {
      font-size: 0.75rem;
      padding: 0.35rem 0.75rem;
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

    .table-responsive {
      box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.1);
    }

    .table th {
      background:#191970;
      color: white;
      position: sticky;
      top: 0;
      border: none;
    }

    .table td {
      border: none;
      vertical-align: middle;
    }

    .table-striped tbody tr:nth-of-type(odd) {
      background-color: rgba(0, 0, 0, 0.02);
    }

    .hero-section {
      background:#191970;
      color: white;
      border-radius: 0.5rem;
      padding: 2rem;
      margin-bottom: 2rem;
      box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
      position: relative;
      overflow: hidden;
    }

    .hero-section::before {
      content: '';
      position: absolute;
      top: -50%;
      right: -50%;
      width: 100%;
      height: 200%;
      background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 0%, rgba(255, 255, 255, 0) 70%);
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

    .action-btn {
      transition: all 0.2s ease;
    }

    .action-btn:hover {
      transform: translateY(-2px);
    }

    .description-cell {
      max-width: 300px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .project-status {
      padding: 0.25rem 0.5rem;
      border-radius: 0.25rem;
      font-size: 0.875rem;
      font-weight: 500;
    }

    .status-active {
      background-color: rgba(28, 200, 138, 0.1);
      color: var(--success-color);
    }

    .status-inactive {
      background-color: rgba(231, 74, 59, 0.1);
      color: var(--danger-color);
    }

    .status-pending {
      background-color: rgba(246, 194, 62, 0.1);
      color: var(--warning-color);
    }

    .status-pass {
      background-color: rgba(28, 200, 138, 0.1);
      color: var(--success-color);
    }

    .status-fail {
      background-color: rgba(231, 74, 59, 0.1);
      color: var(--danger-color);
    }

    .status-pending {
      background-color: rgba(246, 194, 62, 0.1);
      color: var(--warning-color);
    }

    .status-high {
      background-color: rgba(231, 74, 59, 0.1);
      color: var(--danger-color);
    }

    .status-medium {
      background-color: rgba(246, 194, 62, 0.1);
      color: var(--warning-color);
    }

    .status-low {
      background-color: rgba(54, 185, 204, 0.1);
      color: var(--info-color);
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
  <div class="content">
    <div class="container-fluid mt-4">
    <!-- Back Link -->
    <a href="#" onclick="history.back(); return false;" class="back-link mb-3 d-inline-block">
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
        <h3 class="card-title mb-0"><i class="fas fa-list-check me-2"></i>Test Cases</h3>
        <div class="d-flex gap-2">
          <!-- Export Button -->
          <form method="post" action="export_csv.php" class="me-2">
            <input type="hidden" name="project_id" value="<?= $project_id ?>">
            <button type="submit" class="btn btn-sm btn-light">
              <i class="fas fa-file-export me-1"></i> Export CSV
            </button>
          </form>
          
          <!-- Add Test Case Button (for admins/testers) -->
          <?php if ($_SESSION['system_role'] == 'super_admin' || $_SESSION['system_role'] == 'tester' || $_SESSION['system_role'] == 'pm_manager'): ?>
            <a href="add_testcase.php?project_id=<?= $project_id ?>" class="btn btn-sm btn-light">
              <i class="fas fa-plus me-1"></i> Add Test Case
            </a>
            <a href="import_testcases.php?project_id=<?= $project_id ?>" class="btn btn-sm btn-light">
              <i class="fas fa-plus me-1"></i> Import Test cases
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
                <option value="Pass" <?= ($_GET['status'] ?? '') == 'Pass' ? 'selected' : '' ?>>Pass</option>
                <option value="Fail" <?= ($_GET['status'] ?? '') == 'Fail' ? 'selected' : '' ?>>Fail</option>
                <option value="Pending" <?= ($_GET['status'] ?? '') == 'Pending' ? 'selected' : '' ?>>Pending</option>
              </select>
            </div>
          </div>
        </form>

        <!-- Test Cases Table -->
        <div class="table-responsive">
          <table class="table table-striped table-hover align-middle">
            <thead>
              <tr>
                <th><i class="fas fa-hashtag me-2"></i>ID</th>
                <th><i class="fas fa-tag me-2"></i>Title</th>
                <th><i class="fas fa-list-ol me-2"></i>Steps</th>
                <th><i class="fas fa-check-circle me-2"></i>Expected Result</th>
                <th><i class="fas fa-circle me-2"></i>Status</th>
                <th><i class="fas fa-exclamation-triangle me-2"></i>Priority</th>
                <!--<th>frequency</th>
                <th>channel</th> -->
                <th><i class="fas fa-comment-dots me-2"></i>Tester Remark</th>
                <th><i class="fas fa-comment me-2"></i>Vendor Comment</th>
                <th><i class="fas fa-user me-2"></i>Created By</th>
                <th><i class="fas fa-calendar-plus me-2"></i>Created At</th>
                <th><i class="fas fa-calendar-check me-2"></i>Updated At</th>
                <th><i class="fas fa-puzzle-piece me-2"></i>Feature</th>

                <?php if($_SESSION['system_role'] == 'super_admin' || $_SESSION['system_role'] == 'tester'): ?>
                  <th class="text-center"><i class="fas fa-cogs me-2"></i>Actions</th>
                <?php endif; ?>
              </tr>
            </thead>
            <tbody>
              <?php while ($tc = $testcases->fetch_assoc()) : ?>
  <!--hilighting testcases from notification-->
                  <tr id="testcase-<?= $tc['id'] ?>" class="<?= ($highlight_id == $tc['id']) ? 'highlighted-row' : '' ?>">
                  <td><?= $tc['id'] ?></td>
                <!--  <tr class="testcase-row"> -->
                  <td class="fw-bold"><?= htmlspecialchars($tc['title']) ?></td>
                  <td><?= nl2br(htmlspecialchars($tc['steps'])) ?></td>
                  <td><?= nl2br(htmlspecialchars($tc['expected'])) ?></td>
                  <td>
                    <span class="status-badge status-<?= strtolower($tc['status']) ?>">
                      <i class="fas fa-<?= strtolower($tc['status']) == 'pass' ? 'check-circle' : (strtolower($tc['status']) == 'fail' ? 'times-circle' : 'clock') ?> me-1"></i>
                      <?= htmlspecialchars($tc['status']) ?>
                    </span>
                  </td>
                  <td>
                    <span class="status-badge status-<?= strtolower($tc['priority']) ?>">
                      <i class="fas fa-<?= strtolower($tc['priority']) == 'high' ? 'exclamation-triangle' : (strtolower($tc['priority']) == 'medium' ? 'exclamation-circle' : 'info-circle') ?> me-1"></i>
                      <?= htmlspecialchars($tc['priority']) ?>
                    </span>
                  </td>
                 <!-- <td class="fw"><= htmlspecialchars($tc['frequency']) ?></td>
                  <td class="fw"><= htmlspecialchars($tc['channel']) ?></td> -->

              <!-- Start of email modal for tester-remark -->
               <div id="emailConfirmModalTester" style="display:none; position:fixed; left:0; top:0; width:100%; height:100%; background-color: rgba(0,0,0,0.5); z-index:1000; justify-content:center; align-items:center;">
                 <div style="background-color:#fff; padding:20px; border-radius:8px; width:400px; max-width:90%; box-shadow:0 4px 8px rgba(0,0,0,0.1);">
                     <h3>Send Notification Emails?</h3>
                     <p>Do you want to send this comment update to the following users?</p>
                     <form id="emailRecipientsFormTester">
                         <div id="recipientCheckboxesTester">
                             </div>
                         <input type="hidden" name="modal_testcase_id" id="modal_testcase_id_tester">
                         <input type="hidden" name="modal_project_id" id="modal_project_id_tester">
                         <input type="hidden" name="modal_testcase_title" id="modal_testcase_title_tester">
                         <input type="hidden" name="modal_tester_username" id="modal_tester_username_tester">
                         <input type="hidden" name="modal_tester_remark_body" id="modal_tester_remark_body_tester">
                         <div style="margin-top:20px; text-align:right;">
                             <button type="button" class="btn btn-secondary" onclick="closeModalTester()">Cancel</button>
                             <button type="button" class="btn btn-primary" onclick="sendSelectedEmailsTester()">Send Email</button>
                         </div>
                     </form>
                 </div>
             </div>
              <!--End of email modal for tester-remark -->
              <!-- Start of sending and viewing tester_remark -->
               <?php if($_SESSION['system_role'] == 'tester'): ?>
                <td>
                <!--here remember the effect of class='tester-remark-form' if we do it ID it doesn't work correctly since our form is in the loop but the ID can't appear in the whole DOM more than one, we should use class instade of of ID, if we use ID only the first test case vendor comment will be repeatdly inserted on the log table.-->
                  <form class='tester-remark-form' method="post"> <input type="hidden" name="testcase_id" value="<?= $tc['id'] ?>">
                    <textarea name="tester_remark" class="form-control" rows="2"><?= htmlspecialchars($tc['tester_remark'] ?? '') ?></textarea>
                    <button type="button" class="btn btn-sm btn-primary mt-1" onclick="submitTesterRemark(this)">💬 Send</button> </form>
                </td>
                <?php endif; ?>
                <?php if($_SESSION['system_role'] == 'super_admin' || $_SESSION['system_role'] == 'test_viewer' || $_SESSION['system_role'] == 'pm_manager'): ?>
                <td><?= nl2br(htmlspecialchars($tc['tester_remark'] ?? '')) ?></td>
                <?php endif; ?>

          <!--End of sending and viewing vendor comment part-->

                  <!--<td><= nl2br(htmlspecialchars($tc['tester_remark'])) ?></td> -->
                  <!-- Start of vendor comment modal form -->
<div id="emailConfirmModalVendor" style="display:none; position:fixed; left:0; top:0; width:100%; height:100%; background-color: rgba(0,0,0,0.5); z-index:1000; justify-content:center; align-items:center;">
    <div style="background-color:#fff; padding:20px; border-radius:8px; width:400px; max-width:90%; box-shadow:0 4px 8px rgba(0,0,0,0.1);">
        <h3>Send Notification Emails?</h3>
        <p>Do you want to send this comment update to the following users?</p>
        <form id="emailRecipientsFormVendor">
            <div id="recipientCheckboxesVendor">
                </div>
            <input type="hidden" name="modal_testcase_id" id="modal_testcase_id_vendor">
            <input type="hidden" name="modal_project_id" id="modal_project_id_vendor">
            <input type="hidden" name="modal_testcase_title" id="modal_testcase_title_vendor">
            <input type="hidden" name="modal_commenting_username" id="modal_commenting_username_vendor">
            <input type="hidden" name="modal_vendor_comment_body" id="modal_vendor_comment_body_vendor">
            <div style="margin-top:20px; text-align:right;">
                <button type="button" class="btn btn-secondary" onclick="closeModalVendor()">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="sendSelectedEmailsVendor()">Send Email</button>
            </div>
        </form>
    </div>
</div>
<!--End of vendor comment modal form -->

<?php if($_SESSION['system_role'] == 'test_viewer'): ?>
<td>
<!--here remember the effect of class='vendor-comment-form' if we do it ID it doesn't work correctly since our form is in the loop but the ID can't appear in the whole DOM more than one, we should use class instade of of ID, if we use ID only the first test case vendor comment will be repeatdly inserted on the log table.-->
  <form class='vendor-comment-form' method="post"> <input type="hidden" name="testcase_id" value="<?= $tc['id'] ?>">
    <textarea name="vendor_comment" class="form-control" rows="2"><?= htmlspecialchars($tc['vendor_comment'] ?? '') ?></textarea>
    <button type="button" class="btn btn-sm btn-primary mt-1" onclick="submitVendorComment(this)">💬 Send</button> </form>
</td>
<?php endif; ?>
<?php if($_SESSION['system_role'] == 'super_admin' || $_SESSION['system_role'] == 'tester' ||$_SESSION['system_role'] == 'pm_manager'): ?>
<td><?= nl2br(htmlspecialchars($tc['vendor_comment'] ?? '')) ?></td>
<?php endif; ?>

<!--End of sending and viewing vendor comment part-->
                  <td class="fw"><?= htmlspecialchars($user_name) ?></td>
                  <td class="fw"><?= htmlspecialchars($tc['created_at']) ?></td>
                  <td class="fw"><?= htmlspecialchars($tc['updated_at']) ?></td>
                  <td class="fw"><?= htmlspecialchars($testCasesWithFeatures['feature_name']) ?></td>


                  <?php if($_SESSION['system_role'] == 'super_admin' || $_SESSION['system_role'] == 'tester'): ?>
                    <td class="text-center">
                      <div class="d-flex justify-content-center gap-2">
                        <a href="edit_testcase.php?id=<?= $tc['id'] ?>&project_id=<?= $project_id ?>" 
                           class="btn btn-sm btn-outline-primary action-btn" title="Edit">
                          <i class="fas fa-edit"></i>
                        </a>
                        <a href="delete_testcase.php?id=<?= $tc['id'] ?>&project_id=<?= $project_id ?>" 
                           class="btn btn-sm btn-outline-danger action-btn" title="Delete"
                           onclick="return confirm('Are you sure you want to delete this test case?');">
                          <i class="fas fa-trash-alt"></i>
                        </a>
                      </div>
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
  </div>
  <script>
  const targetId = "<?= $highlight_id ? "testcase-" . $highlight_id : "" ?>";
  if (targetId) {
    const el = document.getElementById(targetId);
    if (el) {
      setTimeout(() => el.scrollIntoView({ behavior: 'smooth', block: 'center' }), 500);
    }
  } 
  </script>

<!--Two send the vendor comment two both update_vendor_comment.php and send_email.php-->
<script>
let currentRecipientsVendor = []; // Store recipients retrieved from the first AJAX call
let currentTestCaseDataVendor = {}; // Store test case data from the first AJAX call

// CHANGE: Function now accepts a 'buttonElement' argument
function submitVendorComment(buttonElement) {
    // Find the closest parent form of the clicked button
    const form = buttonElement.closest('form');

    const formData = new FormData(form);
    formData.append('action', 'update_comment'); // Specify the action for the backend

    fetch('update_vendor_comment.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (!response.ok) {
            return response.text().then(text => { throw new Error('Network response was not ok: ' + text); });
        }
        return response.json(); // Expect JSON response
    })
    .then(data => {
        console.log("Phase 1 Response:", data);

        if (data.success) {
            currentRecipientsVendor = data.recipients;
            currentTestCaseDataVendor = {
                test_case_id: data.test_case_id,
                project_id: data.project_id,
                test_case_title: data.test_case_title,
                commenting_username: data.current_username,
                vendor_comment_body: data.new_vendor_comment
            };

            if (currentRecipientsVendor.length > 0) {
                populateAndShowModalVendor();
            } else {
                alert(data.message + "\nNo specific testers or admins found for notification.");
                window.location.reload(); // Reload if no notifications to send
            }
        } else {
            alert("Error updating comment: " + data.message);
            window.location.reload();
        }
    })
    .catch(error => {
        console.error('Error in Phase 1:', error);
        alert('An error occurred during comment update: ' + error.message);
        window.location.reload();
    });
}

function populateAndShowModalVendor() {
    const recipientCheckboxesDiv = document.getElementById('recipientCheckboxesVendor');
    recipientCheckboxesDiv.innerHTML = ''; // Clear previous checkboxes

    currentRecipientsVendor.forEach(recipient => {
        const div = document.createElement('div');
        div.innerHTML = `
            <input type="checkbox" id="user_${recipient.id}" name="selected_users[]" value="${recipient.id}" checked>
            <label for="user_${recipient.id}">${recipient.username} (${recipient.system_role})</label>
        `;
        recipientCheckboxesDiv.appendChild(div);
    });

    // Populate hidden fields for the second AJAX call
    document.getElementById('modal_testcase_id_vendor').value = currentTestCaseDataVendor.test_case_id;
    document.getElementById('modal_project_id_vendor').value = currentTestCaseDataVendor.project_id;
    document.getElementById('modal_testcase_title_vendor').value = currentTestCaseDataVendor.test_case_title;
    document.getElementById('modal_commenting_username_vendor').value = currentTestCaseDataVendor.commenting_username;
    document.getElementById('modal_vendor_comment_body_vendor').value = currentTestCaseDataVendor.vendor_comment_body;


    document.getElementById('emailConfirmModalVendor').style.display = 'flex'; // Show modal
}

function closeModalVendor() {
    document.getElementById('emailConfirmModalVendor').style.display = 'none'; // Hide modal
    window.location.reload(); // Reload the page after cancelling
}

function sendSelectedEmailsVendor() {
    const selectedUserIds = Array.from(document.querySelectorAll('#recipientCheckboxesVendor input[name="selected_users[]"]:checked'))
                                 .map(checkbox => checkbox.value);

    if (selectedUserIds.length === 0) {
        alert("No recipients selected. Email will not be sent.");
        closeModalVendor();
        return;
    }

    const formData = new FormData();
    formData.append('action', 'send_notification_emails'); // Specify the action
    formData.append('testcase_id', currentTestCaseDataVendor.test_case_id);
    formData.append('project_id', currentTestCaseDataVendor.project_id);
    formData.append('test_case_title', currentTestCaseDataVendor.test_case_title);
    formData.append('commenting_username', currentTestCaseDataVendor.commenting_username);
    formData.append('vendor_comment_body', currentTestCaseDataVendor.vendor_comment_body);
    formData.append('selected_recipient_ids', JSON.stringify(selectedUserIds)); // Send as JSON string

    fetch('update_vendor_comment.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (!response.ok) {
            return response.text().then(text => { throw new Error('Network response was not ok: ' + text); });
        }
        return response.json();
    })
    .then(data => {
        console.log("Phase 2 Response (Email Send):", data);
        alert(data.message);
        closeModalVendor(); // Close modal and reload page
    })
    .catch(error => {
        console.error('Error in Phase 2 (Email Send):', error);
        alert('An error occurred during email sending: ' + error.message);
        closeModalVendor();
    });
}
</script>






      <!-- Script to send the tester remark to both update_tester_remark.php and send_email.php-->
      <script>
      let currentRecipientsTester = []; // Store recipients retrieved from the first AJAX call
      let currentTestCaseDataTester = {}; // Store test case data from the first AJAX call

      // CHANGE: Function now accepts a 'buttonElement' argument
      function submitTesterRemark(buttonElement) {
          // Find the closest parent form of the clicked button
          const form = buttonElement.closest('form');

          const formData = new FormData(form);
          formData.append('action', 'update_remark'); // Specify the action for the backend

          fetch('update_tester_remark.php', {
              method: 'POST',
              body: formData
          })
          .then(response => {
              if (!response.ok) {
                  return response.text().then(text => { throw new Error('Network response was not ok: ' + text); });
              }
              return response.json(); // Expect JSON response
          })
          .then(data => {
              console.log("Phase 1 Response:", data);

              if (data.success) {
                  currentRecipientsTester = data.recipients;
                  currentTestCaseDataTester = {
                      test_case_id: data.test_case_id,
                      project_id: data.project_id,
                      test_case_title: data.test_case_title,
                      tester_username: data.current_username,
                      tester_remark_body: data.new_tester_remark
                  };

                  if (currentRecipientsTester.length > 0) {
                      populateAndShowModalTester();
                  } else {
                      alert(data.message + "\nNo specific testers or admins found for notification.");
                      window.location.reload(); // Reload if no notifications to send
                  }
              } else {
                  alert("Error updating remark: " + data.message);
                  window.location.reload();
              }
          })
          .catch(error => {
              console.error('Error in Phase 1:', error);
              alert('An error occurred during remark update: ' + error.message);
              window.location.reload();
          });
      }

      function populateAndShowModalTester() {
          const recipientCheckboxesDiv = document.getElementById('recipientCheckboxesTester');
          recipientCheckboxesDiv.innerHTML = ''; // Clear previous checkboxes

          currentRecipientsTester.forEach(recipient => {
              const div = document.createElement('div');
              div.innerHTML = `
                  <input type="checkbox" id="user_${recipient.id}" name="selected_users[]" value="${recipient.id}" checked>
                  <label for="user_${recipient.id}">${recipient.username} (${recipient.system_role})</label>
              `;
              recipientCheckboxesDiv.appendChild(div);
          });

          // Populate hidden fields for the second AJAX call
          document.getElementById('modal_testcase_id_tester').value = currentTestCaseDataTester.test_case_id;
          document.getElementById('modal_project_id_tester').value = currentTestCaseDataTester.project_id;
          document.getElementById('modal_testcase_title_tester').value = currentTestCaseDataTester.test_case_title;
          document.getElementById('modal_tester_username_tester').value = currentTestCaseDataTester.tester_username;
          document.getElementById('modal_tester_remark_body_tester').value = currentTestCaseDataTester.tester_remark_body;


          document.getElementById('emailConfirmModalTester').style.display = 'flex'; // Show modal
      }

      function closeModalTester() {
          document.getElementById('emailConfirmModalTester').style.display = 'none'; // Hide modal
          window.location.reload(); // Reload the page after cancelling
      }

      function sendSelectedEmailsTester() {
          const selectedUserIds = Array.from(document.querySelectorAll('#recipientCheckboxesTester input[name="selected_users[]"]:checked'))
                                      .map(checkbox => checkbox.value);

          if (selectedUserIds.length === 0) {
              alert("No recipients selected. Email will not be sent.");
              closeModalTester();
              return;
          }

          const formData = new FormData();
          formData.append('action', 'send_notification_emails'); // Specify the action
          formData.append('testcase_id', currentTestCaseDataTester.test_case_id);
          formData.append('project_id', currentTestCaseDataTester.project_id);
          formData.append('test_case_title', currentTestCaseDataTester.test_case_title);
          formData.append('tester_username', currentTestCaseDataTester.tester_username);
          formData.append('tester_remark_body', currentTestCaseDataTester.tester_remark_body);
          formData.append('selected_recipient_ids', JSON.stringify(selectedUserIds)); // Send as JSON string

          fetch('update_tester_remark.php', {
              method: 'POST',
              body: formData
          })
          .then(response => {
              if (!response.ok) {
                  return response.text().then(text => { throw new Error('Network response was not ok: ' + text); });
              }
              return response.json();
          })
          .then(data => {
              console.log("Phase 2 Response (Email Send):", data);
              alert(data.message);
              closeModalTester(); // Close modal and reload page
          })
          .catch(error => {
              console.error('Error in Phase 2 (Email Send):', error);
              alert('An error occurred during email sending: ' + error.message);
              closeModalTester();
          });
      }
      </script>




  <!-- Bootstrap JS Bundle with Popper -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
 
</body>
</html>

