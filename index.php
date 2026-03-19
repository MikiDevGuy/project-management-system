<?php
session_start();
// Auto-login if "remember me" cookies are set
if (!isset($_SESSION['user_id']) && isset($_COOKIE['user_id'])) {
    $_SESSION['user_id'] = $_COOKIE['user_id'];
    $_SESSION['username'] = $_COOKIE['username'];
    $_SESSION['system_role'] = $_COOKIE['system_role'];
}

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
} 
include 'db.php'; 

// --- START: New server-side action handling for Edit/Delete ---
// Handle DELETE action
if (isset($_GET['delete'])) {
    $project_id = $_GET['delete'];
    $stmt_delete = $conn->prepare("DELETE FROM projects WHERE id = ?");
    $stmt_delete->bind_param("i", $project_id);
    if ($stmt_delete->execute()) {
        header("Location: index.php?status=deleted");
    } else {
        header("Location: index.php?status=delete_failed");
    }
    $stmt_delete->close();
    exit;
}

// Handle EDIT action
if (isset($_POST['update_project'])) {
    $id = $_POST['id'];
    $name = $_POST['name'];
    $description = $_POST['description'];
    $status = $_POST['status'];
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $department_id = $_POST['department_id'];

    $stmt_update = $conn->prepare("UPDATE projects SET name = ?, description = ?, status = ?, start_date = ?, end_date = ?, department_id = ? WHERE id = ?");
    $stmt_update->bind_param("sssssii", $name, $description, $status, $start_date, $end_date, $department_id, $id);
    
    if ($stmt_update->execute()) {
        header("Location: index.php?status=updated");
    } else {
        header("Location: index.php?status=update_failed");
    }
    $stmt_update->close();
    exit;
}
// --- END: New server-side action handling ---

// Fetch Projects
$stmt = $conn->prepare("SELECT p.*, d.department_name FROM projects p JOIN departments d ON p.department_id = d.id");
$stmt->execute();
$result = $stmt->get_result();

if (!$result) {
    die("Query Failed: " . $conn->error);
}

// Fetch Departments for dropdown
$departments = $conn->query("SELECT id, department_name FROM departments ORDER BY department_name");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Project Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
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
            transition: transform 0.3s ease;
        }
        
        .card-custom:hover {
            transform: translateY(-5px);
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
        
        .project-list-item {
            transition: all 0.3s ease;
            border-left: 3px solid var(--primary-color);
        }
        
        .project-list-item:hover {
            background-color: rgba(78, 115, 223, 0.1);
            transform: translateX(5px);
        }
        
        .table-responsive {
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.1);
        }
        
        .table th {
            background-color: var(--primary-color);
            color: white;
        }
        
        .hero-section {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--accent-color) 100%);
            color: white;
            border-radius: 0.35rem;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
        }
        
        textarea.form-control {
            min-height: 100px;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark navbar-custom mb-4">
        <div class="container-fluid">
            <a class="navbar-brand" href="#">
                <i class="fas fa-clipboard-check me-2"></i>Project Management System
            </a>
            <div class="d-flex align-items-center">
                <span class="welcome-text me-3">
                    <i class="fas fa-user-circle me-2"></i>Welcome, <?= htmlspecialchars($_SESSION['username'] ?? 'User') ?>
                </span>
                <a href="logout.php" class="btn btn-outline-light">
                    <i class="fas fa-sign-out-alt me-1"></i> Logout
                </a>
            </div>
        </div>
    </nav>

    <div class="container">
        <a href="dashboard.php" class="back-link mb-3 d-inline-block" style="text-decoration:none;">
            <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
        </a>
        <div class="hero-section text-center">
            <h1 class="display-4"><i class="fas fa-chart-gantt me-2"></i> Project Management System</h1>
            <p class="lead">Efficiently manage your projects</p>
        </div>

        <div class="row mb-5">
            <div class="col-12">
                <div class="card card-custom mb-4">
                    <div class="card-header card-header-custom">
                        <h3 class="card-title mb-0"><i class="fas fa-project-diagram me-2"></i>Projects</h3>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover align-middle">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Project Name</th>
                                        <th>Department</th>
                                        <th>Status</th>
                                        <th>Start Date</th>
                                        <th>End Date</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($row = $result->fetch_assoc()): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($row['id']) ?></td>
                                        <td><!--<a href="view_phases.php?id=<= $row['id'] ?>">--><?= htmlspecialchars($row['name']) ?></td>
                                        <td><?= htmlspecialchars($row['department_name']) ?></td>
                                        <td>
                                            <?php
                                            $status_class = '';
                                            switch ($row['status']) {
                                                case 'pending': $status_class = 'bg-secondary'; break;
                                                case 'in_progress': $status_class = 'bg-info'; break;
                                                case 'completed': $status_class = 'bg-success'; break;
                                                default: $status_class = 'bg-light text-dark'; break;
                                            }
                                            ?>
                                            <span class="badge <?= $status_class ?>"><?= ucfirst(htmlspecialchars($row['status'])) ?></span>
                                        </td>
                                        <td><?= htmlspecialchars($row['start_date']) ?></td>
                                        <td><?= htmlspecialchars($row['end_date']) ?></td>
                                        <td>
                                        <button class="btn btn-sm btn-warning edit-btn"
                                            data-bs-toggle="modal"
                                            data-bs-target="#editProjectModal"
                                            data-id="<?= $row['id'] ?>"
                                            data-name="<?= htmlspecialchars($row['name']) ?>"
                                            data-description="<?= htmlspecialchars($row['description']) ?>"
                                            data-status="<?= htmlspecialchars($row['status']) ?>"
                                            data-start-date="<?= htmlspecialchars($row['start_date']) ?>"
                                            data-end-date="<?= htmlspecialchars($row['end_date']) ?>"
                                            data-department-id="<?= htmlspecialchars($row['department_id']) ?>">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn btn-sm btn-danger delete-btn" data-bs-toggle="modal" data-bs-target="#deleteProjectModal" data-id="<?= $row['id'] ?>" data-name="<?= htmlspecialchars($row['name']) ?>">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                      </td>
                                    </tr>
                                    <?php endwhile; ?>

                                    

                                  <!--  <php endwhile; ?> -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($_SESSION['system_role'] == 'super_admin'): ?>
            <div class="col-lg-4">
                <div class="card card-custom">
                    <div class="card-header card-header-custom">
                        <h3 class="card-title mb-0"><i class="fas fa-plus-circle me-2"></i>Add New Project</h3>
                    </div>
                    <div class="card-body">
                        <form action="add_project.php" method="post">
                            <div class="mb-3">
                                <label for="department_id" class="form-label">Project</label>
                                <select name="department_id" class="form-select" required>
                                    <option value="">-- Select Department --</option>
                                    <?php 
                                    // Reset pointer for departments result set
                                    if ($departments->num_rows > 0) {
                                        $departments->data_seek(0);
                                    }
                                    while ($d = $departments->fetch_assoc()): ?>
                                        <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['department_name']) ?></option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="projectName" class="form-label">Project Name</label>
                                <input type="text" class="form-control" id="projectName" name="name" placeholder="Project Name" required>
                            </div>
                            <div class="mb-3">
                                <label for="projectDescription" class="form-label">Description</label>
                                <textarea class="form-control" id="projectDescription" name="description" placeholder="Description" required></textarea>
                            </div>
                            <div class="mb-3">
                                <label for="status" class="form-label">Status</label>
                                <select name="status" class="form-select" required>
                                    <option value="pending">Pending</option>
                                    <option value="in_progress">In Progress</option>
                                    <option value="completed">Completed</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="start_date" class="form-label">Start Date</label>
                                <input type="date" name="start_date" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label for="end_date" class="form-label">End Date</label>
                                <input type="date" name="end_date" class="form-control" required>
                            </div>
                            <button type="submit" class="btn btn-primary-custom w-100">
                                <i class="fas fa-plus me-1"></i> Add Project
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

      <!--Start of single reusable edit and delete modals -->
     <div class="modal fade" id="editProjectModal" tabindex="-1" aria-labelledby="editProjectModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editProjectModalLabel">Edit Project</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="index.php" method="post">
                <div class="modal-body">
                    <input type="hidden" name="id" id="modal_id">
                    <input type="hidden" name="update_project" value="1">
                    <div class="mb-3">
                        <label for="modal_department_id" class="form-label">Department</label>
                        <select name="department_id" id="modal_department_id" class="form-select" required>
                            <?php 
                            // Re-fetch departments once for the single modal
                            $departments_edit = $conn->query("SELECT id, department_name FROM departments ORDER BY department_name");
                            if ($departments_edit) {
                                while ($d = $departments_edit->fetch_assoc()): ?>
                                    <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['department_name']) ?></option>
                                <?php endwhile; 
                                $departments_edit->data_seek(0); // Reset for the Add form
                            }
                            ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="modal_projectName" class="form-label">Project Name</label>
                        <input type="text" class="form-control" id="modal_projectName" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label for="modal_projectDescription" class="form-label">Description</label>
                        <textarea class="form-control" id="modal_projectDescription" name="description" required></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="modal_status" class="form-label">Status</label>
                        <select name="status" id="modal_status" class="form-select" required>
                            <option value="pending">Pending</option>
                            <option value="in_progress">In Progress</option>
                            <option value="completed">Completed</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="modal_start_date" class="form-label">Start Date</label>
                        <input type="date" name="start_date" id="modal_start_date" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label for="modal_end_date" class="form-label">End Date</label>
                        <input type="date" name="end_date" id="modal_end_date" class="form-control" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary-custom">Save changes</button>
                </div>
            </form>
        </div>
    </div>
</div>
<div class="modal fade" id="deleteProjectModal" tabindex="-1" aria-labelledby="deleteProjectModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteProjectModalLabel">Confirm Deletion</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                Are you sure you want to delete the project **<span id="delete_project_name"></span>**? This action cannot be undone.
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <a href="#" id="delete_confirm_btn" class="btn btn-danger">Delete</a>
            </div>
        </div>
    </div>
</div>
                                     <!--End of single reusable edit and delete modals -->
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
        <!--start of edit and delete modal script-->
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const editModal = document.getElementById('editProjectModal');
        const deleteModal = document.getElementById('deleteProjectModal');

        // Handle Edit Modal Population
        editModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const id = button.getAttribute('data-id');
            const name = button.getAttribute('data-name');
            const description = button.getAttribute('data-description');
            const status = button.getAttribute('data-status');
            const startDate = button.getAttribute('data-start-date');
            const endDate = button.getAttribute('data-end-date');
            const departmentId = button.getAttribute('data-department-id');

            const modalTitle = editModal.querySelector('.modal-title');
            const modalId = editModal.querySelector('#modal_id');
            const modalName = editModal.querySelector('#modal_projectName');
            const modalDescription = editModal.querySelector('#modal_projectDescription');
            const modalStatus = editModal.querySelector('#modal_status');
            const modalStartDate = editModal.querySelector('#modal_start_date');
            const modalEndDate = editModal.querySelector('#modal_end_date');
            const modalDepartmentId = editModal.querySelector('#modal_department_id');

            modalTitle.textContent = 'Edit Project: ' + name;
            modalId.value = id;
            modalName.value = name;
            modalDescription.value = description;
            modalStatus.value = status;
            modalStartDate.value = startDate;
            modalEndDate.value = endDate;
            modalDepartmentId.value = departmentId;
        });

        // Handle Delete Modal Population
        deleteModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const id = button.getAttribute('data-id');
            const name = button.getAttribute('data-name');

            const modalBodyName = deleteModal.querySelector('#delete_project_name');
            const deleteConfirmBtn = deleteModal.querySelector('#delete_confirm_btn');

            modalBodyName.textContent = name;
            deleteConfirmBtn.href = 'index.php?delete=' + id;
        });
    });
</script>
         <!--End of edit and delete modal script-->
</body>
</html>