<?php

session_start();
include 'db.php';

// Get phase ID from query string
$phase_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Fetch phase details
$phase_stmt = $conn->prepare("SELECT * FROM phases WHERE id = ?");
$phase_stmt->bind_param("i", $phase_id);
$phase_stmt->execute();
$phase_result = $phase_stmt->get_result();
$phase = $phase_result->fetch_assoc();
$phase_stmt->close();

if (!$phase) {
    $_SESSION['error'] = "Phase not found.";
    header("Location: phases.php");
    exit();
}

// Fetch projects for dropdown
$projects = $conn->query("SELECT id, name FROM projects ORDER BY name");

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $project_id = filter_input(INPUT_POST, 'project_id', FILTER_VALIDATE_INT);
    $name = trim($_POST['name']);
    $description = trim($_POST['description']);
    $phaseOrder = filter_input(INPUT_POST, 'Phase_order', FILTER_VALIDATE_INT);
    $status = $_POST['status'];
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];

    if (!$project_id || empty($name) || empty($start_date) || empty($end_date)) {
        $error = "Required fields are missing";
    } else {
        $stmt = $conn->prepare("UPDATE phases SET project_id=?, name=?, description=?, Phase_order=?, status=?, start_date=?, end_date=? WHERE id=?");
        $stmt->bind_param("ississsi", $project_id, $name, $description, $phaseOrder, $status, $start_date, $end_date, $phase_id);
        if ($stmt->execute()) {
            $_SESSION['success'] = "Phase updated successfully!";
            header("Location: phases.php");
            exit();
        } else {
            $error = "Database error: " . $conn->error;
        }
        $stmt->close();
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Phase</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --dashen-primary: #273274;
            --dashen-accent: #3c4c9e;
            --dashen-light: #f8f9fa;
        }
        body {
            background-color: var(--dashen-light);
            padding-left: 280px;
            transition: padding-left 0.3s ease;
        }
        @media (max-width: 992px) {
            body {
                padding-left: 0;
            }
        }
        .card {
            border-radius: 16px;
            box-shadow: 0 4px 16px rgba(39, 50, 116, 0.10);
            border: none;
        }
        .card-header {
            background-color: var(--dashen-primary) !important;
            color: #fff !important;
            border-radius: 16px 16px 0 0 !important;
            border-bottom: none;
        }
        .btn-primary, .btn-outline-primary:hover {
            background-color: var(--dashen-primary) !important;
            border-color: var(--dashen-primary) !important;
        }
        .btn-outline-primary {
            color: var(--dashen-primary) !important;
            border-color: var(--dashen-primary) !important;
            background: #fff !important;
        }
        .form-label {
            color: var(--dashen-primary);
            font-weight: 500;
        }
        .alert-success {
            border-left: 6px solid var(--dashen-primary);
        }
        .alert-danger {
            border-left: 6px solid #dc3545;
        }
    </style>
</head>
<body>
    <div class="container py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="page-title" style="color:var(--dashen-primary);font-weight:700;">
                <i class="fas fa-edit me-2"></i>Edit Phase
            </h2>
            <a href="phases.php" class="btn btn-outline-primary">
                <i class="fas fa-arrow-left me-2"></i>Back to Phases
            </a>
        </div>

        <?php if (isset($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?= $error ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Edit Phase Modal (always open) -->
        <div class="modal-dialog modal-lg modal-dialog-centered" style="max-width:700px;">
            <div class="modal-content">
                <form method="POST" id="editPhaseForm">
                    <div class="modal-header" style="background-color:var(--dashen-primary); color:#fff;">
                        <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Phase</h5>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label for="project_id" class="form-label">Project <span class="text-danger">*</span></label>
                                <select name="project_id" class="form-select" required>
                                    <option value="">-- Select Project --</option>
                                    <?php
                                    $projects->data_seek(0);
                                    while ($p = $projects->fetch_assoc()): ?>
                                        <option value="<?= $p['id'] ?>" <?= ($phase['project_id'] == $p['id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($p['name']) ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="name" class="form-label">Phase Name <span class="text-danger">*</span></label>
                                <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($phase['name']) ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label for="Phase_order" class="form-label">Order <span class="text-danger">*</span></label>
                                <input type="number" name="Phase_order" class="form-control" value="<?= htmlspecialchars($phase['Phase_order']) ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label for="status" class="form-label">Status <span class="text-danger">*</span></label>
                                <select name="status" class="form-select" required>
                                    <option value="pending" <?= $phase['status'] == 'pending' ? 'selected' : '' ?>>Pending</option>
                                    <option value="in_progress" <?= $phase['status'] == 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                                    <option value="completed" <?= $phase['status'] == 'completed' ? 'selected' : '' ?>>Completed</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="start_date" class="form-label">Start Date <span class="text-danger">*</span></label>
                                <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($phase['start_date']) ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label for="end_date" class="form-label">End Date <span class="text-danger">*</span></label>
                                <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars($phase['end_date']) ?>" required>
                            </div>
                            <div class="col-12">
                                <label for="description" class="form-label">Description</label>
                                <textarea name="description" class="form-control" rows="3"><?= htmlspecialchars($phase['description']) ?></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer bg-light">
                        <button type="submit" class="btn btn-primary px-4 py-2">
                            <i class="fas fa-save me-2"></i>Update Phase
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('editPhaseForm')?.addEventListener('submit', function() {
            this.querySelector('button[type="submit"]').disabled = true;
        });
    </script>
</body>
</html>