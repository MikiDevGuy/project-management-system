<?php
// sidebar.php - Modern collapsible sidebar for Dashen Bank Risk Management System
// FIXED: Role-based access control for Reports menu (SRS Section 2.2)
// Super Admin & PM Manager can see Reports, PM Employee cannot

// Get current user role from session
$user_role = $_SESSION['system_role'] ?? '';
$current_page = basename($_SERVER['PHP_SELF']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashen Bank Risk Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        /* Dashen Bank Brand Colors */
        :root {
            --dashen-primary: #273274;
            --dashen-secondary: #1e5af5;
            --dashen-accent: #f8a01c;
            --dashen-light: #f8f9fa;
        }

        .bg-dashen-primary { background-color: var(--dashen-primary) !important; }
        .bg-dashen-secondary { background-color: var(--dashen-secondary) !important; }
        .bg-dashen-accent { background-color: var(--dashen-accent) !important; }
        .bg-dashen-light { background-color: var(--dashen-light) !important; }

        .text-dashen-primary { color: var(--dashen-primary) !important; }
        .text-dashen-secondary { color: var(--dashen-secondary) !important; }
        .text-dashen-accent { color: var(--dashen-accent) !important; }

        .btn-dashen-primary {
            background-color: var(--dashen-primary);
            border-color: var(--dashen-primary);
            color: white;
        }

        .btn-dashen-primary:hover {
            background-color: #1e275a;
            border-color: #1e275a;
            color: white;
        }

        /* Sidebar Container */
        .sidebar-container {
            min-height: 100vh;
            width: 280px;
            position: fixed;
            left: 0;
            top: 0;
            z-index: 1000;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
        }

        .sidebar-container.collapsed {
            width: 70px;
        }

        /* Sidebar Content Wrapper */
        .sidebar-content-wrapper {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .sidebar-container.collapsed .sidebar-content {
            opacity: 0;
            visibility: hidden;
            width: 0;
            height: 0;
            overflow: hidden;
        }

        /* Header Section */
        .sidebar-header {
            border-bottom: 1px solid rgba(255,255,255,0.1);
            padding: 1.5rem 1rem;
            flex-shrink: 0;
            position: relative;
        }

        .sidebar-container.collapsed .sidebar-header {
            padding: 1rem 0.5rem;
        }

        .sidebar-container.collapsed .logo-img {
            max-width: 40px !important;
            transition: all 0.3s ease;
        }

        .sidebar-toggle-btn {
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .sidebar-container.collapsed .sidebar-toggle-btn .bi-chevron-left {
            transform: rotate(180deg);
        }

        /* User Profile */
        .user-profile {
            border-bottom: 1px solid rgba(255,255,255,0.1);
            padding: 1rem;
            flex-shrink: 0;
        }

        .sidebar-container.collapsed .user-profile {
            padding: 1rem 0.5rem;
            justify-content: center;
        }

        .sidebar-container.collapsed .user-info {
            display: none;
        }

        /* Navigation Menu - Scrollable Area */
        .sidebar-nav-container {
            flex: 1;
            overflow: hidden;
            position: relative;
        }

        .sidebar-nav {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            overflow-y: auto;
            overflow-x: hidden;
            padding: 1rem 0;
        }

        /* Custom Scrollbar */
        .sidebar-nav::-webkit-scrollbar {
            width: 6px;
        }

        .sidebar-nav::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 3px;
        }

        .sidebar-nav::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.2);
            border-radius: 3px;
        }

        .sidebar-nav::-webkit-scrollbar-thumb:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        /* Sidebar Link Styles */
        .sidebar-link {
            color: rgba(255, 255, 255, 0.8) !important;
            text-decoration: none;
            display: flex;
            align-items: center;
            margin: 0.25rem 0.75rem;
            border-radius: 8px;
            padding: 0.75rem 1rem;
            border-left: 3px solid transparent;
            transition: all 0.3s ease;
            position: relative;
        }

        .sidebar-link:hover {
            color: white !important;
            background-color: rgba(255, 255, 255, 0.1);
            border-left: 3px solid var(--dashen-accent) !important;
            transform: translateX(2px);
        }

        .sidebar-link.active {
            color: white !important;
            background-color: rgba(255, 255, 255, 0.15);
            border-left: 3px solid var(--dashen-accent) !important;
            font-weight: 500;
        }

        .sidebar-link i:first-child {
            transition: transform 0.2s ease;
            min-width: 24px;
            text-align: center;
            font-size: 1.1rem;
        }

        .sidebar-link:hover i:first-child {
            transform: scale(1.1);
            color: var(--dashen-accent);
        }

        /* Collapsed State Links */
        .sidebar-container.collapsed .sidebar-link {
            padding: 0.75rem;
            justify-content: center;
            margin: 0.25rem 0.5rem;
        }

        .sidebar-container.collapsed .sidebar-link span {
            display: none;
        }

        .sidebar-container.collapsed .sidebar-link .bi-chevron-right {
            display: none;
        }

        /* Tooltip for collapsed state */
        .sidebar-container.collapsed .sidebar-link::after {
            content: attr(data-tooltip);
            position: absolute;
            left: 100%;
            top: 50%;
            transform: translateY(-50%);
            background: rgba(0, 0, 0, 0.8);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 4px;
            font-size: 0.875rem;
            white-space: nowrap;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
            z-index: 1001;
            margin-left: 10px;
        }

        .sidebar-container.collapsed .sidebar-link:hover::after {
            opacity: 1;
            visibility: visible;
        }

        /* Sidebar Footer */
        .sidebar-footer {
            border-top: 1px solid rgba(255,255,255,0.1);
            padding: 1rem;
            flex-shrink: 0;
        }

        .sidebar-container.collapsed .sidebar-footer small {
            display: none;
        }

        /* Main Content Adjustment */
        .main-content {
            margin-left: 280px;
            transition: margin-left 0.3s ease;
            min-height: 100vh;
            background-color: #f8f9fa;
        }

        .main-content.expanded {
            margin-left: 70px;
        }

        /* Mobile Responsive */
        @media (max-width: 991.98px) {
            .sidebar-container {
                transform: translateX(-100%);
            }
            
            .sidebar-container.mobile-open {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0 !important;
            }
            
            .sidebar-backdrop {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background-color: rgba(0, 0, 0, 0.5);
                z-index: 999;
                display: none;
            }
            
            .sidebar-backdrop.show {
                display: block;
            }
        }

        /* Animation for sidebar items */
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(-20px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .sidebar-nav .nav-item {
            animation: slideIn 0.3s ease forwards;
        }

        .sidebar-nav .nav-item:nth-child(1) { animation-delay: 0.1s; }
        .sidebar-nav .nav-item:nth-child(2) { animation-delay: 0.15s; }
        .sidebar-nav .nav-item:nth-child(3) { animation-delay: 0.2s; }
        .sidebar-nav .nav-item:nth-child(4) { animation-delay: 0.25s; }
        .sidebar-nav .nav-item:nth-child(5) { animation-delay: 0.3s; }
        .sidebar-nav .nav-item:nth-child(6) { animation-delay: 0.35s; }
        .sidebar-nav .nav-item:nth-child(7) { animation-delay: 0.4s; }
        .sidebar-nav .nav-item:nth-child(8) { animation-delay: 0.45s; }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar-container bg-dashen-primary text-white">
        <div class="sidebar-content-wrapper">
            <!-- Sidebar Header with Toggle -->
            <div class="sidebar-header text-center">
                <div class="sidebar-toggle-btn position-absolute top-50 end-0 translate-middle-y me-3">
                    <i class="bi bi-chevron-left text-white" style="font-size: 1.2rem;"></i>
                </div>
                <div class="sidebar-content">
                    <div class="sidebar-logo">
                        <img src="../Images/DashenLogo12.png" alt="Dashen Bank Logo" class="logo-img" style="max-width: 180px; height: auto;">
                    </div>
                    <div class="mt-2">
                        <h6 class="mb-0" style="font-weight: 300; letter-spacing: 1px;">RISK MANAGEMENT</h6>
                    </div>
                </div>
            </div>

            <!-- Navigation Menu Container -->
            <div class="sidebar-nav-container">
                <nav class="sidebar-nav">
                    <ul class="nav flex-column" id="sidebarNav">
                        <!-- Dashboard -->
                        <li class="nav-item">
                            <a class="nav-link sidebar-link <?php echo $current_page == 'risk_dashboard.php' ? 'active' : ''; ?>" 
                               href="risk_dashboard.php"
                               data-tooltip="Dashboard">
                                <i class="bi bi-speedometer2 me-3"></i>
                                <span class="sidebar-content">Dashboard</span>
                                <i class="bi bi-chevron-right ms-auto sidebar-content"></i>
                            </a>
                        </li>
                        
                        <!-- Unified Dashboard -->
                        <li class="nav-item">
                            <a class="nav-link sidebar-link <?php echo $current_page == '../dashboard.php' ? 'active' : ''; ?>" 
                               href="../dashboard.php"
                               data-tooltip="Unified Dashboard">
                                <i class="bi bi-grid-3x3-gap me-3"></i>
                                <span class="sidebar-content">Unified Dashboard</span>
                                <i class="bi bi-chevron-right ms-auto sidebar-content"></i>
                            </a>
                        </li>

                        <!-- Risk Register -->
                        <li class="nav-item">
                            <a class="nav-link sidebar-link <?php echo $current_page == 'risks.php' ? 'active' : ''; ?>" 
                               href="risks.php"
                               data-tooltip="Risk Register">
                                <i class="bi bi-clipboard2-plus me-3"></i>
                                <span class="sidebar-content">Risk Register</span>
                                <i class="bi bi-chevron-right ms-auto sidebar-content"></i>
                            </a>
                        </li>
                         
                        <!-- ========================================= -->
                        <!-- FIXED: Role-based Reports Menu - SRS Section 2.2 -->
                        <!-- Only Super Admin and PM Manager can see Reports -->
                        <!-- PM Employee CANNOT see Reports -->
                        <!-- ========================================= -->
                        <?php if (in_array($user_role, ['super_admin', 'pm_manager'])): ?>
                        <!-- Reports -->
                        <li class="nav-item">
                            <a class="nav-link sidebar-link <?php echo $current_page == 'risk_report.php' ? 'active' : ''; ?>" 
                               href="risk_report.php"
                               data-tooltip="Reports">
                                <i class="bi bi-file-earmark-text me-3"></i>
                                <span class="sidebar-content">Reports</span>
                                <i class="bi bi-chevron-right ms-auto sidebar-content"></i>
                            </a>
                        </li>
                        <?php endif; ?>

                        <!-- Divider -->
                        <li class="nav-item my-2">
                            <hr style="border-color: rgba(255,255,255,0.1); margin: 10px 20px;" class="sidebar-content">
                        </li>

                        <!-- Logout -->
                        <li class="nav-item">
                            <a class="nav-link sidebar-link" 
                               href="../logout.php"
                               data-tooltip="Logout">
                                <i class="bi bi-box-arrow-right me-3"></i>
                                <span class="sidebar-content">Logout</span>
                            </a>
                        </li>

                    </ul>
                </nav>
            </div>

            <!-- Sidebar Footer -->
            <div class="sidebar-footer">
                <div class="text-center sidebar-content">
                    <small style="opacity: 0.7;">Dashen Bank &copy; <?php echo date('Y'); ?></small>
                </div>
            </div>
        </div>
    </div>

    <!-- Sidebar Backdrop for Mobile -->
    <div class="sidebar-backdrop"></div>

    <!-- Sidebar Toggle Button for Mobile -->
    <div class="sidebar-toggle d-lg-none" style="position: fixed; top: 15px; left: 15px; z-index: 1050;">
        <button class="btn btn-dashen-primary" id="sidebarToggle" style="width: 40px; height: 40px; border-radius: 50%;">
            <i class="bi bi-list"></i>
        </button>
    </div>

    <script>
        // Sidebar toggle functionality
        document.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.querySelector('.sidebar-container');
            const sidebarToggleBtn = document.querySelector('.sidebar-toggle-btn');
            const sidebarToggle = document.getElementById('sidebarToggle');
            const mainContent = document.querySelector('.main-content');
            
            // Initialize sidebar state
            function initSidebar() {
                const savedState = localStorage.getItem('sidebarCollapsed');
                if (savedState === 'true') {
                    sidebar.classList.add('collapsed');
                    if (mainContent) mainContent.classList.add('expanded');
                }
            }
            
            // Desktop toggle (collapse/expand)
            if (sidebarToggleBtn) {
                sidebarToggleBtn.addEventListener('click', function() {
                    sidebar.classList.toggle('collapsed');
                    
                    // Save state to localStorage
                    localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
                    
                    if (mainContent) {
                        if (sidebar.classList.contains('collapsed')) {
                            mainContent.classList.add('expanded');
                        } else {
                            mainContent.classList.remove('expanded');
                        }
                    }
                });
            }
            
            // Mobile toggle (show/hide)
            if (sidebarToggle && sidebar) {
                sidebarToggle.addEventListener('click', function() {
                    sidebar.classList.toggle('mobile-open');
                    
                    // Create backdrop for mobile
                    let backdrop = document.querySelector('.sidebar-backdrop');
                    if (!backdrop) {
                        backdrop = document.createElement('div');
                        backdrop.className = 'sidebar-backdrop';
                        document.body.appendChild(backdrop);
                    }
                    backdrop.classList.toggle('show');
                    
                    backdrop.addEventListener('click', function() {
                        sidebar.classList.remove('mobile-open');
                        backdrop.classList.remove('show');
                    });
                });
            }
            
            // Add active state management
            const currentPage = '<?php echo $current_page; ?>';
            const sidebarLinks = document.querySelectorAll('.sidebar-link');
            
            sidebarLinks.forEach(link => {
                const href = link.getAttribute('href');
                if (href && (href === currentPage || href.includes(currentPage))) {
                    link.classList.add('active');
                }
                
                link.addEventListener('click', function() {
                    sidebarLinks.forEach(l => l.classList.remove('active'));
                    this.classList.add('active');
                    
                    // Close sidebar on mobile after selection
                    if (window.innerWidth < 992) {
                        sidebar.classList.remove('mobile-open');
                        const backdrop = document.querySelector('.sidebar-backdrop');
                        if (backdrop) backdrop.classList.remove('show');
                    }
                });
            });
            
            // Adjust main content on resize
            window.addEventListener('resize', function() {
                if (window.innerWidth >= 992) {
                    sidebar.classList.remove('mobile-open');
                    const backdrop = document.querySelector('.sidebar-backdrop');
                    if (backdrop) backdrop.classList.remove('show');
                }
            });
            
            // Initialize sidebar
            initSidebar();
        });
    </script>
</body>
</html>