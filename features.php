<?php
session_start();
include 'db.php';
$active_dashboard = 'testcase_management';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Check permissions
$allowed_roles = ['super_admin', 'tester', 'admin'];
if (!in_array($_SESSION['system_role'], $allowed_roles)) {
    die('<div class="container mt-5">
        <div class="alert alert-danger">
            <i class="fas fa-ban me-2"></i>Access denied. You must be an admin or tester to manage features.
        </div>
        <a href="dashboard.php" class="btn btn-primary">Return to Dashboard</a>
    </div>');
}

$role = $_SESSION['system_role'];
$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];

// ========== HANDLE ADD FEATURE ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_feature'])) {
    $project_id = intval($_POST['project_id']);
    $feature_name = trim($_POST['feature_name']);
    $description = trim($_POST['description']);
    $status = $_POST['status'] ?? 'Planned';

    if (empty($feature_name) || empty($project_id)) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => 'Please fill in all required fields.'
        ];
        header("Location: features.php");
        exit;
    }

    $stmt = $conn->prepare("INSERT INTO features (project_id, feature_name, description, status, created_at) 
                           VALUES (?, ?, ?, ?, NOW())");
    $stmt->bind_param("isss", $project_id, $feature_name, $description, $status);
    
    if ($stmt->execute()) {
        $feature_id = $stmt->insert_id;
        
        $_SESSION['message'] = [
            'type' => 'success',
            'text' => 'Feature added successfully!'
        ];
    } else {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => 'Failed to add feature. Please try again.'
        ];
    }
    
    header("Location: features.php");
    exit;
}

// ========== HANDLE EDIT FEATURE ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_feature'])) {
    $feature_id = intval($_POST['feature_id']);
    $feature_name = trim($_POST['feature_name']);
    $description = trim($_POST['description']);
    $status = $_POST['status'];
    $project_id = intval($_POST['project_id']);

    if (empty($feature_name) || empty($project_id)) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => 'Please fill in all required fields.'
        ];
        header("Location: features.php");
        exit;
    }

    // Check if user has permission to edit this feature
    if ($role === 'super_admin') {
        // Super admin can edit any feature
        $check_stmt = $conn->prepare("SELECT id FROM features WHERE id = ?");
        $check_stmt->bind_param("i", $feature_id);
    } else {
        // Regular users can only edit features from projects they're assigned to
        $check_stmt = $conn->prepare("SELECT f.id FROM features f 
                                     JOIN user_assignments pu ON f.project_id = pu.project_id 
                                     WHERE f.id = ? AND pu.user_id = ?");
        $check_stmt->bind_param("ii", $feature_id, $user_id);
    }
    
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows === 0) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => 'You do not have permission to edit this feature.'
        ];
        header("Location: features.php");
        exit;
    }

    // Check if updated_at column exists
    $stmt = $conn->prepare("UPDATE features SET feature_name = ?, description = ?, status = ?, 
                           project_id = ?, updated_at = NOW() WHERE id = ?");
    $stmt->bind_param("sssii", $feature_name, $description, $status, $project_id, $feature_id);
    
    if ($stmt->execute()) {
        $_SESSION['message'] = [
            'type' => 'success',
            'text' => 'Feature updated successfully!'
        ];
    } else {
        // Try without updated_at if the column doesn't exist
        $stmt = $conn->prepare("UPDATE features SET feature_name = ?, description = ?, status = ?, 
                               project_id = ? WHERE id = ?");
        $stmt->bind_param("sssii", $feature_name, $description, $status, $project_id, $feature_id);
        
        if ($stmt->execute()) {
            $_SESSION['message'] = [
                'type' => 'success',
                'text' => 'Feature updated successfully!'
            ];
        } else {
            $_SESSION['message'] = [
                'type' => 'error',
                'text' => 'Failed to update feature. Please try again.'
            ];
        }
    }
    
    header("Location: features.php");
    exit;
}

// ========== HANDLE DELETE FEATURE ==========
if (isset($_GET['delete'])) {
    $delete_id = intval($_GET['delete']);
    
    // Check if feature exists and user has permission
    if ($role === 'super_admin') {
        $check_stmt = $conn->prepare("SELECT id, feature_name FROM features WHERE id = ?");
        $check_stmt->bind_param("i", $delete_id);
    } else {
        $check_stmt = $conn->prepare("SELECT f.id, f.feature_name FROM features f 
                                     JOIN user_assignments pu ON f.project_id = pu.project_id 
                                     WHERE f.id = ? AND pu.user_id = ?");
        $check_stmt->bind_param("ii", $delete_id, $user_id);
    }
    
    $check_stmt->execute();
    $feature = $check_stmt->get_result()->fetch_assoc();
    
    if (!$feature) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => 'Feature not found or you do not have permission to delete it.'
        ];
        header("Location: features.php");
        exit;
    }
    
    // Check if feature has test cases
    $testcase_check = $conn->prepare("SELECT COUNT(*) as count FROM test_cases WHERE feature_id = ?");
    $testcase_check->bind_param("i", $delete_id);
    $testcase_check->execute();
    $testcase_result = $testcase_check->get_result()->fetch_assoc();
    
    if ($testcase_result['count'] > 0) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => 'Cannot delete feature. There are ' . $testcase_result['count'] . ' test cases associated with it.'
        ];
        header("Location: features.php");
        exit;
    }
    
    // Perform deletion
    $delete_stmt = $conn->prepare("DELETE FROM features WHERE id = ?");
    $delete_stmt->bind_param("i", $delete_id);
    
    if ($delete_stmt->execute()) {
        $_SESSION['message'] = [
            'type' => 'success',
            'text' => 'Feature deleted successfully!'
        ];
    } else {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => 'Failed to delete feature. Please try again.'
        ];
    }
    
    header("Location: features.php");
    exit;
}

// ========== FETCH FEATURES ==========
if ($role === 'super_admin' || $role === 'admin') {
    // Admins can see all features
    $features_query = "
        SELECT f.*, p.name AS project_name, p.id as project_id
        FROM features f
        JOIN projects p ON f.project_id = p.id
        ORDER BY f.created_at DESC
    ";
    $features = $conn->query($features_query);
} else {
    // Testers can only see features from projects they're assigned to
    $features_query = "
        SELECT f.*, p.name AS project_name, p.id as project_id
        FROM features f
        JOIN projects p ON f.project_id = p.id
        JOIN user_assignments pu ON p.id = pu.project_id
        WHERE pu.user_id = ?
        ORDER BY f.created_at DESC
    ";
    $stmt = $conn->prepare($features_query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $features = $stmt->get_result();
}

// ========== FETCH PROJECTS FOR DROPDOWN ==========
if ($role === 'super_admin' || $role === 'admin') {
    $projects_query = "SELECT id, name FROM projects ORDER BY name";
    $projects_result = $conn->query($projects_query);
} else {
    $projects_query = "
        SELECT p.id, p.name 
        FROM projects p
        JOIN user_assignments pu ON p.id = pu.project_id
        WHERE pu.user_id = ?
        ORDER BY p.name
    ";
    $stmt = $conn->prepare($projects_query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $projects_result = $stmt->get_result();
}

// Store projects for dropdown in array
$projects = [];
while ($project = $projects_result->fetch_assoc()) {
    $projects[] = $project;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Features Management - Test Manager</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    
    <style>
        /* Dashen Bank Color Scheme */
        :root {
            --dashen-primary: #273274;    /* Main deep blue */
            --dashen-secondary: #012169;   /* Alternative dark blue */
            --dashen-accent: #e41e26;     /* Red accent */
            --dashen-light: #f8f9fa;      /* Light gray */
            --dashen-dark: #2c3e50;       /* Dark gray */
            --dashen-success: #28a745;    /* Green for success */
            --dashen-warning: #ffc107;    /* Yellow for warning */
            --dashen-danger: #dc3545;     /* Red for danger */
            --dashen-info: #17a2b8;       /* Blue for info */
        }
        
        body {
            background-color: #f5f7fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        /* Main Content Area */
        .content {
            margin-left: 250px;
            padding: 20px;
            transition: margin-left 0.3s ease;
        }
        
        @media (max-width: 768px) {
            .content {
                margin-left: 0;
            }
        }
        
        /* Hero Section */
        .hero-section {
            background: linear-gradient(135deg, var(--dashen-primary) 0%, var(--dashen-secondary) 100%);
            color: white;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 8px 25px rgba(39, 50, 116, 0.2);
        }
        
        .hero-icon {
            font-size: 2.5rem;
            margin-right: 1rem;
            opacity: 0.9;
        }
        
        /* Custom Cards */
        .card-dashen {
            border: none;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            overflow: hidden;
        }
        
        .card-dashen:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
        }
        
        .card-header-dashen {
            background: linear-gradient(135deg, var(--dashen-primary) 0%, var(--dashen-secondary) 100%);
            color: white;
            border-bottom: none;
            padding: 1rem 1.5rem;
        }
        
        /* Status Badges */
        .status-badge {
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .badge-planned { background-color: rgba(23, 162, 184, 0.15); color: var(--dashen-info); }
        .badge-inprogress { background-color: rgba(255, 193, 7, 0.15); color: var(--dashen-warning); }
        .badge-completed { background-color: rgba(40, 167, 69, 0.15); color: var(--dashen-success); }
        .badge-blocked { background-color: rgba(220, 53, 69, 0.15); color: var(--dashen-danger); }
        
        /* Action Buttons */
        .btn-dashen {
            background: linear-gradient(135deg, var(--dashen-primary) 0%, var(--dashen-secondary) 100%);
            color: white;
            border: none;
            border-radius: 6px;
            padding: 0.6rem 1.5rem;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-dashen:hover {
            background: linear-gradient(135deg, var(--dashen-secondary) 0%, var(--dashen-primary) 100%);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(39, 50, 116, 0.3);
        }
        
        .btn-outline-dashen {
            border: 2px solid var(--dashen-primary);
            color: var(--dashen-primary);
            background: transparent;
        }
        
        .btn-outline-dashen:hover {
            background: var(--dashen-primary);
            color: white;
        }
        
        /* Action Buttons in Table */
        .action-btn {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin: 0 3px;
            transition: all 0.2s ease;
        }
        
        .action-btn:hover {
            transform: scale(1.1);
        }
        
        /* Table Styling */
        .table-dashen {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.05);
        }
        
        .table-dashen thead th {
            background: linear-gradient(135deg, var(--dashen-primary) 0%, var(--dashen-secondary) 100%);
            color: white;
            border: none;
            font-weight: 600;
            padding: 1rem;
        }
        
        .table-dashen tbody tr {
            transition: background-color 0.2s ease;
        }
        
        .table-dashen tbody tr:hover {
            background-color: rgba(39, 50, 116, 0.05);
        }
        
        /* Table cell content */
        .table-cell-content {
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        .table-cell-content.expandable:hover {
            white-space: normal;
            overflow: visible;
            position: relative;
            z-index: 5;
            background: white;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            padding: 5px;
            border-radius: 4px;
            max-width: none;
        }
        
        /* Modal Styling */
        .modal-header-dashen {
            background: linear-gradient(135deg, var(--dashen-primary) 0%, var(--dashen-secondary) 100%);
            color: white;
            border-bottom: none;
        }
        
        .modal-header-dashen .btn-close {
            filter: invert(1) brightness(200%);
        }
        
        /* Feature Icon */
        .feature-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--dashen-primary) 0%, var(--dashen-secondary) 100%);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.2rem;
        }
        
        /* Responsive Adjustments */
        @media (max-width: 768px) {
            .content {
                padding: 15px;
            }
            
            .hero-section {
                padding: 1.5rem;
            }
            
            .action-btn {
                width: 32px;
                height: 32px;
                font-size: 0.9rem;
            }
        }
        
        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: var(--dashen-light);
        }
        
        ::-webkit-scrollbar-thumb {
            background: var(--dashen-primary);
            border-radius: 4px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: var(--dashen-secondary);
        }
        
        /* Edit Form in Table */
        .edit-form-row {
            background-color: #f8f9fa !important;
        }
        
        .edit-form-row:hover {
            background-color: #f8f9fa !important;
        }
        
        .form-control-sm {
            padding: 0.25rem 0.5rem;
            font-size: 0.875rem;
        }
        
        .form-select-sm {
            padding: 0.25rem 2.25rem 0.25rem 0.5rem;
            font-size: 0.875rem;
        }
    </style>
</head>
<body>
    <!-- Include Sidebar -->
    <?php include 'sidebar.php'; ?>
    
    <!-- Main Content Area -->
    <div class="content">
        <!-- Hero Section -->
        <div class="hero-section">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h1 class="display-6 fw-bold">
                        <i class="fas fa-cubes me-2"></i>Features Management
                    </h1>
                    <p class="lead mb-0">Manage project features and their deliverables</p>
                </div>
                <div class="col-md-4 text-md-end mt-3 mt-md-0">
                    <button type="button" class="btn btn-light btn-lg" data-bs-toggle="modal" data-bs-target="#addFeatureModal">
                        <i class="fas fa-plus-circle me-2"></i>Add New Feature
                    </button>
                </div>
            </div>
        </div>

        <!-- Messages -->
        <?php if (isset($_SESSION['message'])): ?>
            <div class="alert alert-<?= $_SESSION['message']['type'] == 'success' ? 'success' : 'danger' ?> alert-dismissible fade show mb-4" role="alert">
                <i class="fas fa-<?= $_SESSION['message']['type'] == 'success' ? 'check-circle' : 'exclamation-circle' ?> me-2"></i>
                <?= htmlspecialchars($_SESSION['message']['text']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['message']); ?>
        <?php endif; ?>

        <!-- Quick Actions -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card card-dashen">
                    <div class="card-header card-header-dashen">
                        <h5 class="card-title mb-0"><i class="fas fa-bolt me-2"></i>Quick Actions</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-3 col-6">
                                <button type="button" class="btn btn-outline-dashen w-100" data-bs-toggle="modal" data-bs-target="#addFeatureModal">
                                    <i class="fas fa-plus-circle me-1"></i> Add Feature
                                </button>
                            </div>
                            <?php if ($role == 'tester' || $role == 'super_admin'): ?>
                            <div class="col-md-3 col-6">
                                <a href="import_features.php" class="btn btn-outline-dashen w-100">
                                    <i class="fas fa-file-import me-1"></i> Import Features
                                </a>
                            </div>
                            <?php endif; ?>
                            <div class="col-md-3 col-6">
                                <a href="dashboard_testcase.php" class="btn btn-outline-dashen w-100">
                                    <i class="fas fa-tachometer-alt me-1"></i> Dashboard
                                </a>
                            </div>
                            <div class="col-md-3 col-6">
                                <a href="projects.php" class="btn btn-outline-dashen w-100">
                                    <i class="fas fa-project-diagram me-1"></i> View Projects
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Features List -->
        <div class="row">
            <div class="col-12">
                <div class="card card-dashen">
                    <div class="card-header card-header-dashen d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-list-check me-2"></i>
                            All Features <span class="badge bg-light text-dark ms-2"><?= $features->num_rows ?? 0 ?></span>
                        </h5>
                        <div class="btn-group">
                            <button type="button" class="btn btn-light btn-sm" onclick="refreshTable()">
                                <i class="fas fa-sync-alt me-1"></i> Refresh
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if ($features && $features->num_rows > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-dashen table-hover" id="featuresTable">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Feature</th>
                                            <th>Project</th>
                                            <th>Deliverables</th>
                                            <th>Status</th>
                                            <th>Created At</th>
                                            <th class="text-center">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        // Reset pointer to iterate through features
                                        $features->data_seek(0);
                                        while ($feature = $features->fetch_assoc()): 
                                            $status_class = strtolower(str_replace(' ', '', $feature['status']));
                                        ?>
                                        <tr>
                                            <td class="fw-bold">#<?= $feature['id'] ?></td>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div class="feature-icon me-3">
                                                        <i class="fas fa-cube"></i>
                                                    </div>
                                                    <div>
                                                        <strong><?= htmlspecialchars($feature['feature_name']) ?></strong>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="badge bg-secondary"><?= htmlspecialchars($feature['project_name']) ?></span>
                                            </td>
                                            <td>
                                                <?php if (!empty($feature['description'])): ?>
                                                    <div class="table-cell-content expandable" title="<?= htmlspecialchars($feature['description']) ?>">
                                                        <?= htmlspecialchars($feature['description']) ?>
                                                    </div>
                                                <?php else: ?>
                                                    <small class="text-muted"><i>No deliverables</i></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="status-badge badge-<?= $status_class ?>">
                                                    <?= htmlspecialchars($feature['status']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <small><?= date('M d, Y', strtotime($feature['created_at'])) ?></small>
                                            </td>
                                            <td class="text-center">
                                                <div class="btn-group" role="group">
                                                    <!-- Edit Button (inline form trigger) -->
                                                    <button type="button" class="btn btn-outline-warning action-btn edit-feature-btn"
                                                            data-feature-id="<?= $feature['id'] ?>"
                                                            data-feature-name="<?= htmlspecialchars($feature['feature_name']) ?>"
                                                            data-description="<?= htmlspecialchars($feature['description']) ?>"
                                                            data-status="<?= htmlspecialchars($feature['status']) ?>"
                                                            data-project-id="<?= $feature['project_id'] ?>"
                                                            title="Edit Feature">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    
                                                    <!-- Delete Button -->
                                                    <button type="button" class="btn btn-outline-danger action-btn delete-feature"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#deleteFeatureModal"
                                                            data-feature-id="<?= $feature['id'] ?>"
                                                            data-feature-name="<?= htmlspecialchars($feature['feature_name']) ?>"
                                                            data-project-name="<?= htmlspecialchars($feature['project_name']) ?>"
                                                            title="Delete Feature">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="fas fa-cubes fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">No Features Found</h5>
                                <p class="text-muted">Add your first feature using the button above.</p>
                                <button type="button" class="btn btn-dashen" data-bs-toggle="modal" data-bs-target="#addFeatureModal">
                                    <i class="fas fa-plus-circle me-1"></i> Add First Feature
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ========== MODALS ========== -->

    <!-- Add Feature Modal -->
    <div class="modal fade" id="addFeatureModal" tabindex="-1" aria-labelledby="addFeatureModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header modal-header-dashen">
                    <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Add New Feature</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" id="addFeatureForm">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="feature_name" class="form-label">Feature Name <span class="text-danger">*</span></label>
                                    <input type="text" name="feature_name" class="form-control" placeholder="Enter feature name" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="project_id" class="form-label">Project <span class="text-danger">*</span></label>
                                    <select name="project_id" class="form-select" required>
                                        <option value="">-- Select Project --</option>
                                        <?php foreach ($projects as $project): ?>
                                            <option value="<?= $project['id'] ?>"><?= htmlspecialchars($project['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="description" class="form-label">Deliverables</label>
                            <textarea name="description" class="form-control" rows="3" placeholder="Describe the feature deliverables"></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label for="status" class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="Planned">Planned</option>
                                <option value="In Progress" selected>In Progress</option>
                                <option value="Completed">Completed</option>
                                <option value="Blocked">Blocked</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_feature" class="btn btn-dashen">
                            <i class="fas fa-plus me-1"></i> Add Feature
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Feature Modal -->
    <div class="modal fade" id="editFeatureModal" tabindex="-1" aria-labelledby="editFeatureModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header modal-header-dashen">
                    <h5 class="modal-title">Edit Feature</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" id="editFeatureForm">
                    <div class="modal-body">
                        <input type="hidden" name="feature_id" id="edit_feature_id">
                        <input type="hidden" name="edit_feature" value="1">
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="edit_feature_name" class="form-label">Feature Name <span class="text-danger">*</span></label>
                                    <input type="text" name="feature_name" id="edit_feature_name" class="form-control" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="edit_project_id" class="form-label">Project <span class="text-danger">*</span></label>
                                    <select name="project_id" id="edit_project_id" class="form-select" required>
                                        <option value="">-- Select Project --</option>
                                        <?php foreach ($projects as $project): ?>
                                            <option value="<?= $project['id'] ?>"><?= htmlspecialchars($project['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_description" class="form-label">Deliverables</label>
                            <textarea name="description" id="edit_description" class="form-control" rows="3"></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_status" class="form-label">Status</label>
                            <select name="status" id="edit_status" class="form-select">
                                <option value="Planned">Planned</option>
                                <option value="In Progress">In Progress</option>
                                <option value="Completed">Completed</option>
                                <option value="Blocked">Blocked</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-dashen">
                            <i class="fas fa-save me-1"></i> Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Feature Modal -->
    <div class="modal fade" id="deleteFeatureModal" tabindex="-1" aria-labelledby="deleteFeatureModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header modal-header-dashen">
                    <h5 class="modal-title">Confirm Deletion</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-4">
                        <i class="fas fa-exclamation-triangle fa-3x text-warning"></i>
                    </div>
                    <h5 class="text-center mb-3">Are you sure you want to delete this feature?</h5>
                    <p class="text-center">
                        Feature: <strong id="delete_feature_name_display"></strong><br>
                        Project: <span id="delete_project_name_display" class="text-muted"></span><br>
                        <span class="text-danger fw-bold">This action cannot be undone!</span>
                    </p>
                    <div class="alert alert-warning">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Note:</strong> You can only delete features that have no test cases associated with them.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <a href="#" id="delete_feature_confirm" class="btn btn-danger">
                        <i class="fas fa-trash me-1"></i> Delete Feature
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript Libraries -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

    <script>
    // Initialize DataTable
    $(document).ready(function() {
        $('#featuresTable').DataTable({
            pageLength: 10,
            responsive: true,
            order: [[0, 'desc']],
            language: {
                search: "Search features:",
                lengthMenu: "Show _MENU_ entries",
                info: "Showing _START_ to _END_ of _TOTAL_ features",
                paginate: {
                    first: "First",
                    last: "Last",
                    next: "Next",
                    previous: "Previous"
                }
            }
        });
        
        // Make table cells expandable on hover
        document.querySelectorAll('.table-cell-content.expandable').forEach(cell => {
            cell.addEventListener('mouseenter', function() {
                if (this.scrollWidth > this.clientWidth) {
                    this.title = this.textContent;
                }
            });
        });
    });

    // Edit Feature Modal Population
    const editModal = document.getElementById('editFeatureModal');
    
    // Add click event listeners to all edit buttons
    document.querySelectorAll('.edit-feature-btn').forEach(button => {
        button.addEventListener('click', function() {
            const featureId = this.dataset.featureId;
            const featureName = this.dataset.featureName;
            const description = this.dataset.description || '';
            const status = this.dataset.status;
            const projectId = this.dataset.projectId;
            
            document.getElementById('edit_feature_id').value = featureId;
            document.getElementById('edit_feature_name').value = featureName;
            document.getElementById('edit_description').value = description;
            document.getElementById('edit_status').value = status;
            document.getElementById('edit_project_id').value = projectId;
            
            // Update modal title
            editModal.querySelector('.modal-title').textContent = 'Edit Feature: ' + featureName;
            
            // Show modal
            const modal = new bootstrap.Modal(editModal);
            modal.show();
        });
    });
    
    // Delete Feature Modal Population
    const deleteFeatureModal = document.getElementById('deleteFeatureModal');
    deleteFeatureModal.addEventListener('show.bs.modal', function(event) {
        const button = event.relatedTarget;
        const featureId = button.dataset.featureId;
        const featureName = button.dataset.featureName;
        const projectName = button.dataset.projectName;
        
        document.getElementById('delete_feature_name_display').textContent = featureName;
        document.getElementById('delete_project_name_display').textContent = projectName;
        document.getElementById('delete_feature_confirm').href = 'features.php?delete=' + featureId;
    });
    
    // Form validation
    document.getElementById('addFeatureForm').addEventListener('submit', function(e) {
        const featureName = this.querySelector('[name="feature_name"]').value.trim();
        const projectId = this.querySelector('[name="project_id"]').value;
        
        if (!featureName || !projectId) {
            e.preventDefault();
            alert('Please fill in all required fields (marked with *).');
            return false;
        }
    });
    
    document.getElementById('editFeatureForm').addEventListener('submit', function(e) {
        const featureName = this.querySelector('[name="feature_name"]').value.trim();
        const projectId = this.querySelector('[name="project_id"]').value;
        
        if (!featureName || !projectId) {
            e.preventDefault();
            alert('Please fill in all required fields (marked with *).');
            return false;
        }
    });
    
    // Refresh table function
    function refreshTable() {
        window.location.reload();
    }
    
    // Add character counters to textareas in modals
    document.addEventListener('DOMContentLoaded', function() {
        // Add character counters to textareas in modals
        const textareas = document.querySelectorAll('#addFeatureModal textarea, #editFeatureModal textarea');
        textareas.forEach(textarea => {
            const maxLength = 1000;
            const counterId = 'counter-' + (textarea.id || 'textarea-' + Math.random().toString(36).substr(2, 9));
            
            const counter = document.createElement('div');
            counter.className = 'form-text text-end mt-1';
            counter.id = counterId;
            counter.innerHTML = `<span class="char-count">0</span> / ${maxLength} characters`;
            
            textarea.parentNode.appendChild(counter);
            
            textarea.addEventListener('input', function() {
                const count = this.value.length;
                const counterEl = document.getElementById(counterId);
                const charCount = counterEl.querySelector('.char-count');
                
                charCount.textContent = count;
                if (count > maxLength * 0.9) {
                    charCount.className = 'char-count text-danger';
                } else if (count > maxLength * 0.75) {
                    charCount.className = 'char-count text-warning';
                } else {
                    charCount.className = 'char-count text-success';
                }
            });
            
            // Trigger initial count
            if (textarea.value) {
                textarea.dispatchEvent(new Event('input'));
            }
        });
    });
    </script>
</body>
</html>