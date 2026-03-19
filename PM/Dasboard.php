<?php
session_start();
$user_role = $_SESSION['system_role'] ?? 'viewer'; // here the system_role is not there the role will be viewer by default I think.
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unified Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        :root {
            --primary-color: #4361ee;
            --secondary-color: #3f37c9;
            --success-color: #4cc9f0;
            --dark-color: #212529;
            --light-color: #f8f9fa;
            --card-bg: #ffffff;
            --shadow: 0 10px 30px -15px rgba(0, 0, 0, 0.1);
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e8f0 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            margin: 0;
            padding: 20px;
            color: var(--dark-color);
        }
        
        .dashboard-card {
            background: var(--card-bg);
            border-radius: 16px;
            box-shadow: var(--shadow);
            padding: 40px;
            width: 100%;
            max-width: 800px;
            border: none;
            overflow: hidden;
            position: relative;
        }
        
        .dashboard-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 8px;
            background: linear-gradient(90deg, var(--primary-color), var(--success-color));
        }
        
        .welcome-title {
            font-weight: 700;
            font-size: 2.2rem;
            margin-bottom: 1rem;
            color: var(--dark-color);
            position: relative;
            display: inline-block;
        }
        
        .welcome-subtitle {
            color: #6c757d;
            font-weight: 400;
            margin-bottom: 2.5rem;
            font-size: 1.1rem;
        }
        
        .btn-modern {
            padding: 15px 30px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 1rem;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            border: none;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            min-width: 250px;
        }
        
        .btn-primary-modern {
            background: var(--primary-color);
            background: linear-gradient(45deg, var(--primary-color), var(--secondary-color));
        }
        
        .btn-primary-modern:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(67, 97, 238, 0.3);
        }
        
        .btn-success-modern {
            background: var(--success-color);
            background: linear-gradient(45deg, #4cc9f0, #4895ef);
        }
        
        .btn-success-modern:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(76, 201, 240, 0.3);
        }
        
        .dashboard-icon {
            font-size: 1.5rem;
        }
        
        .action-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            justify-content: center;
            margin-top: 40px;
        }
        
        @media (max-width: 768px) {
            .dashboard-card {
                padding: 30px 20px;
            }
            
            .welcome-title {
                font-size: 1.8rem;
            }
            
            .action-buttons {
                flex-direction: column;
                align-items: center;
            }
            
            .btn-modern {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-card">
        <h1 class="welcome-title">Welcome to Unified Dashboard</h1>
        <p class="welcome-subtitle">Access your tools and manage your work efficiently</p>
        
        <div class="action-buttons">
            <?php if ($user_role === 'super_admin' || $user_role === 'admin'): ?>
                <a href="dashboard_project_manager.php" class="btn btn-primary-modern btn-modern">
                    <i class="bi bi-kanban-fill dashboard-icon"></i>
                    Project Management Dashboard
                </a>
                <a href="dashboard_testcase.php" class="btn btn-success-modern btn-modern">
                    <i class="bi bi-bug-fill dashboard-icon"></i>
                    Test Case Dashboard
                </a>
              

                <a href="add_project.php" class="btn btn-primary-modern btn-modern">
                    <i class="bi bi-kanban-fill dashboard-icon"></i>
                    Add Projects
                </a>
                <a href="users.php" class="btn btn-success-modern btn-modern">
                    <i class="bi bi-bug-fill dashboard-icon"></i>
                    Manage Users
                </a>
            <?php elseif ($user_role === 'tester'): ?>
                <a href="dashboard_testcase.php" class="btn btn-success-modern btn-modern">
                    <i class="bi bi-bug-fill dashboard-icon"></i>
                    Go to Test Case Dashboard
                </a>
            <?php elseif ($user_role === 'pm_manager' || $user_role === 'pm_employee'): ?>
                <a href="dashboard_project_manager.php" class="btn btn-primary-modern btn-modern">
                    <i class="bi bi-kanban-fill dashboard-icon"></i>
                    Go to Project Dashboard
                </a>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>