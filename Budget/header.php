<?php
// header.php - Dynamic header with collapsible sidebar
// Assuming the user's name is stored in a session variable, e.g., $_SESSION['username']
// For this example, I'll set a placeholder if it's not set. Replace this with your actual session logic.
session_start();
if (!isset($_SESSION['username'])) {
   // $_SESSION['username'] = "Dashen User"; // Placeholder if session isn't set
}
$username = htmlspecialchars($_SESSION['username']);
// Check user role for permission
// Check user role for permission
$show_reports = (isset($_SESSION['system_role']) && ($_SESSION['system_role'] == 'pm_manager' || $_SESSION['system_role'] == 'super_admin'));
$show_dashboard = (isset($_SESSION['system_role']) && ($_SESSION['system_role'] == 'super_admin' || $_SESSION['system_role'] == 'pm_manager' || $_SESSION['system_role'] == 'pm_employee'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Budget Management System - Dashen Bank</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
    <style>
        :root {
            --dashen-primary: #273274;
            --dashen-secondary: #f8f9fa;
            --dashen-accent: #4e73df;
            --dashen-text: #333333;
            --dashen-light: #ffffff;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f5f7fb;
            overflow-x: hidden;
        }
        
        /* FIX: Made sidebar scrolable and applied proper overflow behavior */
        .sidebar {
            min-height: 100vh;
            height: 100vh; /* Set explicit height to enable scrolling */
            background-color: var(--dashen-primary);
            color: white;
            position: fixed;
            top: 0;
            left: 0;
            width: 250px;
            z-index: 1000;
            transition: all 0.3s ease;
            box-shadow: 3px 0 10px rgba(0, 0, 0, 0.1);
            overflow-y: auto; /* Enable vertical scrolling */
            overflow-x: hidden; /* Hide horizontal scrollbar */
        }
        
        .sidebar.collapsed {
            width: 80px;
        }
        
        .sidebar-logo {
            padding: 15px 10px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            text-align: center;
            margin-bottom: 10px;
            /* FIX: Ensure logo area doesn't scroll with the links */
            position: sticky;
            top: 0;
            background-color: var(--dashen-primary);
            z-index: 100;
        }
        
        .sidebar-logo img {
            max-width: 80%;
            max-height: 150px;
            transition: all 0.3s ease;
        }
        
        .sidebar.collapsed .sidebar-logo img {
            transform: scale(0.8);
        }
        
        .sidebar .nav-link {
            color: rgba(255, 255, 255, 0.8);
            padding: 12px 20px;
            margin: 4px 10px;
            border-radius: 5px;
            transition: all 0.2s;
        }
        
        .sidebar .nav-link:hover {
            background-color: rgba(255, 255, 255, 0.1);
            color: white;
        }
        
        .sidebar .nav-link.active {
            background-color: var(--dashen-light);
            color: var(--dashen-primary);
            font-weight: 600;
        }
        
        .sidebar .nav-link i {
            width: 25px;
            text-align: center;
            margin-right: 10px;
            transition: margin 0.3s ease;
        }
        
        .sidebar.collapsed .nav-link i {
            margin-right: 0;
        }
        
        .sidebar .nav-text {
            transition: opacity 0.3s ease;
        }
        
        .sidebar.collapsed .nav-text {
            opacity: 0;
            display: none;
        }
        
        .main-content {
            margin-left: 250px;
            transition: all 0.3s ease;
            padding: 20px;
            min-height: 100vh;
        }
        
        .main-content.expanded {
            margin-left: 80px;
        }
        
        .navbar-dashen {
            background-color: var(--dashen-light);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            padding: 10px 20px;
        }
        
        .toggle-sidebar {
            background: none;
            border: none;
            color: var(--dashen-primary);
            font-size: 1.2rem;
            cursor: pointer;
            margin-right: 15px;
        }
        
        .user-dropdown .dropdown-toggle {
            color: var(--dashen-primary);
            font-weight: 500;
            text-decoration: none;
            cursor: pointer;
        }
        
        .user-dropdown .dropdown-toggle:hover {
            color: var(--dashen-primary);
        }
        
        .user-dropdown .dropdown-menu {
            border: none;
            box-shadow: 0 0 15px rgba(0, 0, 0, 0.1);
            border-radius: 8px;
            padding: 10px 0;
        }
        
        .user-dropdown .dropdown-item {
            padding: 8px 20px;
            color: var(--dashen-text);
            transition: all 0.2s;
        }
        
        .user-dropdown .dropdown-item:hover {
            background-color: var(--dashen-secondary);
            color: var(--dashen-primary);
        }
        
        .user-dropdown .dropdown-item i {
            width: 20px;
            text-align: center;
            margin-right: 10px;
        }
        
        .page-title {
            color: var(--dashen-primary);
            font-weight: 600;
            margin-bottom: 1.5rem;
        }
        
        .card-dashen {
            border: none;
            border-radius: 10px;
            box-shadow: 0 0 15px rgba(0, 0, 0, 0.05);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        
        .card-dashen:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
        }
        
        .card-header-dashen {
            background-color: var(--dashen-light);
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            padding: 15px 20px;
            font-weight: 600;
            color: var(--dashen-primary);
            border-radius: 10px 10px 0 0 !important;
        }
        
        .btn-dashen {
            background-color: var(--dashen-primary);
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 5px;
            transition: all 0.2s;
        }
        
        .btn-dashen:hover {
            background-color: #1a2450;
            color: white;
        }
        
        .table th {
            color: var(--dashen-primary);
            font-weight: 600;
        }
        
        .badge-paid {
            background-color: #28a745;
        }
        
        .badge-pending {
            background-color: #ffc107;
            color: #212529;
        }
        
        .badge-rejected {
            background-color: #dc3545;
        }
        
        .chart-container {
            position: relative;
            height: 350px;
            width: 100%;
        }
        
        .kpi-card {
            border-radius: 10px;
            overflow: hidden;
            color: white;
            position: relative;
            transition: all 0.3s ease;
        }
        
        .kpi-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2);
        }
        
        .kpi-card .icon {
            opacity: 0.15;
            font-size: 4rem;
            position: absolute;
            right: 1rem;
            bottom: 0.5rem;
            transition: all 0.3s ease;
        }
        
        .kpi-card:hover .icon {
            opacity: 0.25;
            transform: scale(1.1);
        }
        
        .nav-tabs .nav-link {
            color: var(--dashen-text);
            font-weight: 500;
            border: none;
            padding: 10px 20px;
            border-radius: 5px 5px 0 0;
        }
        
        .nav-tabs .nav-link.active {
            color: var(--dashen-primary);
            background-color: transparent;
            border-bottom: 3px solid var(--dashen-primary);
        }
        
        .filter-section {
            background-color: var(--dashen-light);
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.05);
        }
        
        .pagination-dashen .page-link {
            color: var(--dashen-primary);
        }
        
        .pagination-dashen .page-item.active .page-link {
            background-color: var(--dashen-primary);
            border-color: var(--dashen-primary);
        }
        
        @media (max-width: 768px) {
            .sidebar {
                width: 80px;
            }
            
            .sidebar .nav-text {
                opacity: 0;
                display: none;
            }
            
            .main-content {
                margin-left: 80px;
            }
            
            .main-content.expanded {
                margin-left: 250px;
            }
        }
    </style>
</head>
<body>
    <div class="container-fluid p-0">
        <div class="sidebar" id="sidebar">
            <div class="sidebar-logo">
                <img src="../Images/DashenLogo12.png" alt="Dashen Bank Logo" class="sidebar-logo">
            </div>
            
            <div class="pt-3">
                <ul class="nav flex-column">
                  <?php  if($show_dashboard): ?>
                    <li class="nav-item">
                        <a class="nav-link active" href="dashboard.php">
                            <i class="fas fa-tachometer-alt"></i>
                            <span class="nav-text">Dashboard</span>
                        </a>
                    </li>
                    <?php endif; ?>
                    <li class="nav-item">
                        <a class="nav-link" href="../dashboard.php">
                            <i class="fas fa-chart-line"></i>
                            <span class="nav-text">UNIFIED-DASHBO</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="budget_categories.php">
                            <i class="fas fa-list"></i>
                            <span class="nav-text">Budget Categories</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="cost_types.php">
                            <i class="fas fa-tags"></i>
                            <span class="nav-text">Cost Types</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="departments.php">
                            <i class="fas fa-building"></i>
                            <span class="nav-text">Departments</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="budget_items.php">
                            <i class="fas fa-money-bill-wave"></i>
                            <span class="nav-text">Budget Items</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="actual_expenses.php">
                            <i class="fas fa-receipt"></i>
                            <span class="nav-text">Actual Expenses</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="vendors.php">
                            <i class="fas fa-store"></i>
                            <span class="nav-text">Vendors</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="contracts.php">
                            <i class="fas fa-file-contract"></i>
                            <span class="nav-text">Contracts</span>
                        </a>
                    </li>
                    <?php if ($show_reports): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="reports.php">
                            <i class="fas fa-chart-bar"></i>
                            <span class="nav-text">Reports</span>
                        </a>
                    </li>
                    <?php endif; ?>
                    <li class="nav-item">
                        <a class="nav-link" href="../logout.php">
                            <i class="fas fa-sign-out-alt"></i>
                            <span class="nav-text">Logout</span>
                        </a>
                    </li>
                </ul>
            </div>
        </div>

        <div class="main-content" id="main-content">
            <nav class="navbar navbar-expand-lg navbar-dashen mb-4">
                <div class="container-fluid">
                    <button class="toggle-sidebar" id="toggle-sidebar">
                        <i class="fas fa-bars"></i>
                    </button>
                    
                    <div class="d-flex align-items-center">
                        <div class="user-dropdown dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="fas fa-user-circle me-1"></i> <?php echo $username; ?>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="navbarDropdown">
                                <li><a class="dropdown-item" href="../profile.php"><i class="fas fa-user me-2"></i>Profile</a></li>
                                <li><a class="dropdown-item" href="#"><i class="fas fa-cog me-2"></i>Settings</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="../logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </nav>

            <div class="container-fluid">

<script>
// Sidebar toggle functionality
document.addEventListener('DOMContentLoaded', function() {
    const toggleSidebar = document.getElementById('toggle-sidebar');
    const sidebar = document.getElementById('sidebar');
    const mainContent = document.getElementById('main-content');
    
    if (toggleSidebar && sidebar && mainContent) {
        // Apply collapsed state on mobile screens initially
        if (window.innerWidth <= 768) {
             sidebar.classList.add('collapsed');
             mainContent.classList.add('expanded');
             toggleSidebar.querySelector('i').classList.remove('fa-bars');
             toggleSidebar.querySelector('i').classList.add('fa-chevron-right');
        }

        toggleSidebar.addEventListener('click', function() {
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('expanded');
            
            // Update the icon
            const icon = this.querySelector('i');
            if (sidebar.classList.contains('collapsed')) {
                icon.classList.remove('fa-bars');
                icon.classList.add('fa-chevron-right');
            } else {
                icon.classList.remove('fa-chevron-right');
                icon.classList.add('fa-bars');
            }
        });
    }
});
</script>