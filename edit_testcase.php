<?php
include 'db.php';

$id = $_GET['id'];
$project_id = $_GET['project_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = $_POST['title'];
    $steps = $_POST['steps'];
    $expected = $_POST['expected'];
    $status = $_POST['status'];

    $stmt = $conn->prepare("UPDATE test_cases SET title=?, steps=?, expected=?, status=? WHERE id=?");
    $stmt->bind_param("ssssi", $title, $steps, $expected, $status, $id);
    $stmt->execute();

    header("Location: view_project.php?id=$project_id");
    exit;
}

$tc = $conn->query("SELECT * FROM test_cases WHERE id=$id")->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Test Case - Test Manager</title>
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

        .form-container {
            background-color: white;
            border-radius: 0.5rem;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.1);
            padding: 2rem;
            margin-top: 2rem;
        }

        .form-header {
            color: var(--primary-color);
            border-bottom: 1px solid #e3e6f0;
            padding-bottom: 1rem;
            margin-bottom: 2rem;
        }

        .form-label {
            font-weight: 600;
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

        textarea.form-control {
            min-height: 120px;
        }

        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            padding: 0.75rem 1.5rem;
            font-weight: 600;
            border-radius: 0.35rem;
        }

        .btn-primary:hover {
            background-color: var(--accent-color);
            border-color: var(--accent-color);
        }

        .status-badge {
            padding: 0.35rem 0.65rem;
            border-radius: 0.25rem;
            font-weight: 600;
        }

        .badge-pass {
            background-color: rgba(28, 200, 138, 0.1);
            color: var(--success-color);
        }

        .badge-fail {
            background-color: rgba(231, 74, 59, 0.1);
            color: var(--danger-color);
        }

        .badge-pending {
            background-color: rgba(246, 194, 62, 0.1);
            color: var(--warning-color);
        }

        .bg-gradient-primary {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--accent-color) 100%);
        }
    </style>
</head>
<body>
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <!-- Header Card -->
                <div class="card bg-gradient-primary text-white mb-4 border-0 shadow">
                    <div class="card-body py-4">
                        <div class="row align-items-center">
                            <div class="col">
                                <h2 class="mb-0"><i class="fas fa-edit me-2"></i>Edit Test Case</h2>
                            </div>
                            <div class="col-auto">
                                <span class="badge bg-light text-dark fs-6 p-2">
                                    ID: <?= htmlspecialchars($id) ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Edit Form -->
                <div class="form-container shadow-sm">
                    <div class="form-header">
                        <h4><i class="fas fa-file-alt me-2"></i>Test Case Details</h4>
                    </div>
                    
                    <form method="post">
                        <!-- Title Field -->
                        <div class="mb-4">
                            <label for="title" class="form-label">Test Case Title</label>
                            <input type="text" class="form-control" id="title" name="title" 
                                   value="<?= htmlspecialchars($tc['title']) ?>" required>
                        </div>

                        <!-- Steps Field -->
                        <div class="mb-4">
                            <label for="steps" class="form-label">Test Steps</label>
                            <textarea class="form-control" id="steps" name="steps" rows="5" required><?= htmlspecialchars($tc['steps']) ?></textarea>
                            <small class="text-muted">Enter each step on a new line or separate with bullet points</small>
                        </div>

                        <!-- Expected Result Field -->
                        <div class="mb-4">
                            <label for="expected" class="form-label">Expected Result</label>
                            <textarea class="form-control" id="expected" name="expected" rows="5" required><?= htmlspecialchars($tc['expected']) ?></textarea>
                        </div>
                        <!-- Status Field -->
                        <div class="mb-4">
                            <label for="status" class="form-label">Current Status</label>
                            <select class="form-select" id="status" name="status" required>
                                <option value="Pass" <?= $tc['status']=='Pass'?'selected':'' ?>>Pass</option>
                                <option value="Fail" <?= $tc['status']=='Fail'?'selected':'' ?>>Fail</option>
                                <option value="Pending" <?= $tc['status']=='Pending'?'selected':'' ?>>Pending</option>
                            </select>
                            <div class="mt-2">
                                <span class="status-badge 
                                    <?= $tc['status'] == 'Pass' ? 'badge-pass' : 
                                       ($tc['status'] == 'Fail' ? 'badge-fail' : 'badge-pending') ?>">
                                    <i class="fas <?= $tc['status'] == 'Pass' ? 'fa-check-circle' : 
                                                   ($tc['status'] == 'Fail' ? 'fa-times-circle' : 'fa-clock') ?> me-1"></i>
                                    Current Status: <?= htmlspecialchars($tc['status']) ?>
                                </span>
                            </div>
                        </div>

                        <!-- Form Buttons -->
                        <div class="d-flex justify-content-between align-items-center mt-4 pt-3 border-top">
                            <a href="view_project.php?id=<?= $project_id ?>" class="btn btn-outline-secondary">
                                <i class="fas fa-arrow-left me-1"></i> Back to Project
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-1"></i> Update Test Case
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>