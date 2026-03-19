<?php
// Check which path works
$config_path = file_exists('../config/functions.php') ? '../config/functions.php' : 'config/functions.php';
require_once $config_path;

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

checkAuth();

// Get user initials for avatar
$username = $_SESSION['username'] ?? 'User';
$initials = strtoupper(substr($username, 0, 2));
$role = $_SESSION['system_role'] ?? 'User';

// Get profile picture
$profile_picture = $_SESSION['profile_picture'] ?? null;
$profile_image = $profile_picture ? "../uploads/profile/" . $profile_picture : null;

// Get custom colors from session
$custom_colors = $_SESSION['custom_colors'] ?? [
    'primary' => '#273274',
    'secondary' => '#3c4c9e',
    'accent' => '#fff'
];

// Dark mode check
$dark_mode = $_SESSION['dark_mode'] ?? false;
?>

<!-- Modern Sidebar - Styled exactly like main dashboard -->
<style>
    :root {
        --dashen-primary: <?php echo $custom_colors['primary']; ?>;
        --dashen-secondary: <?php echo $custom_colors['secondary']; ?>;
        --dashen-accent: <?php echo $custom_colors['accent']; ?>;
        --sidebar-width: 280px;
        --sidebar-collapsed-width: 80px;
        --header-height: 80px;
        --shadow-sm: 0 1px 2px rgba(0,0,0,0.05);
        --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.1);
        --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.1);
        --shadow-xl: 0 20px 25px -5px rgba(0,0,0,0.1);
        --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    /* Dark Mode Support */
    body.dark-mode {
        --dashen-primary: #5c6bc0;
        --dashen-secondary: #3949ab;
    }

    /* Sidebar Container - Exact same as main dashboard */
    .sidebar {
        width: var(--sidebar-width);
        background: linear-gradient(180deg, var(--dashen-primary) 0%, var(--dashen-secondary) 100%);
        color: white;
        padding: 0;
        box-shadow: var(--shadow-xl);
        transition: var(--transition);
        position: fixed;
        z-index: 1000;
        height: 100vh;
        overflow-y: auto;
        display: flex;
        flex-direction: column;
        top: 0;
        left: 0;
    }

    .sidebar.collapsed {
        width: var(--sidebar-collapsed-width);
    }

    /* Custom scrollbar - matches main dashboard */
    .sidebar::-webkit-scrollbar {
        width: 6px;
    }

    .sidebar::-webkit-scrollbar-track {
        background: rgba(255, 255, 255, 0.1);
    }

    .sidebar::-webkit-scrollbar-thumb {
        background: rgba(255, 255, 255, 0.3);
        border-radius: 3px;
    }

    .sidebar::-webkit-scrollbar-thumb:hover {
        background: rgba(255, 255, 255, 0.5);
    }

    /* Sidebar Header - matches main dashboard */
    .sidebar-header {
        padding: 30px 20px;
        background: rgba(255, 255, 255, 0.1);
        border-bottom: 1px solid rgba(255, 255, 255, 0.2);
        text-align: center;
        transition: var(--transition);
        position: relative;
    }

    .sidebar-logo {
        display: flex;
        justify-content: center;
        margin-bottom: 20px;
        transition: var(--transition);
    }

    .sidebar-logo img {
        width: 140px;
        height: auto;
        transition: var(--transition);
    }

    .sidebar.collapsed .sidebar-logo img {
        width: 45px;
    }

    .sidebar-title {
        color: white;
        font-weight: 700;
        margin: 0;
        font-size: 1.4rem;
        transition: var(--transition);
    }

    .sidebar.collapsed .sidebar-title {
        opacity: 0;
        height: 0;
        overflow: hidden;
    }

    /* Toggle Button - matches main dashboard */
    .sidebar-toggle-btn {
        position: absolute;
        top: 30px;
        right: -15px;
        width: 32px;
        height: 32px;
        border-radius: 50%;
        background: var(--dashen-primary);
        color: white;
        border: 2px solid white;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        z-index: 1001;
        box-shadow: var(--shadow-xl);
        transition: var(--transition);
    }

    .sidebar-toggle-btn:hover {
        background: var(--dashen-secondary);
        transform: scale(1.15);
    }

    .sidebar.collapsed .sidebar-toggle-btn i {
        transform: rotate(180deg);
    }

    /* Sidebar Menu - matches main dashboard */
    .sidebar-menu {
        padding: 25px 0;
        flex-grow: 1;
    }

    .sidebar-menu ul {
        list-style: none;
        padding: 0;
        margin: 0;
    }

    .sidebar-menu li {
        margin: 0;
        animation: slideInUp 0.5s ease-out forwards;
        opacity: 0;
        position: relative;
    }

    .sidebar-menu li:nth-child(1) { animation-delay: 0.1s; }
    .sidebar-menu li:nth-child(2) { animation-delay: 0.15s; }
    .sidebar-menu li:nth-child(3) { animation-delay: 0.2s; }
    .sidebar-menu li:nth-child(4) { animation-delay: 0.25s; }
    .sidebar-menu li:nth-child(5) { animation-delay: 0.3s; }
    .sidebar-menu li:nth-child(6) { animation-delay: 0.35s; }
    .sidebar-menu li:nth-child(7) { animation-delay: 0.4s; }
    .sidebar-menu li:nth-child(8) { animation-delay: 0.45s; }
    .sidebar-menu li:nth-child(9) { animation-delay: 0.5s; }
    .sidebar-menu li:nth-child(10) { animation-delay: 0.55s; }

    @keyframes slideInUp {
        from {
            opacity: 0;
            transform: translateY(20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .sidebar-menu a {
        padding: 18px 25px;
        display: flex;
        align-items: center;
        color: rgba(255, 255, 255, 0.9);
        text-decoration: none;
        transition: var(--transition);
        border-left: 4px solid transparent;
        position: relative;
        margin: 8px 15px;
        border-radius: 12px;
    }

    .sidebar-menu a:hover {
        color: #fff;
        background: rgba(255, 255, 255, 0.2);
        border-left-color: var(--dashen-accent);
        transform: translateX(8px);
    }

    .sidebar-menu a.active {
        background: rgba(255, 255, 255, 0.25);
        color: white;
        border-left-color: var(--dashen-accent);
        font-weight: 600;
        box-shadow: 0 4px 12px rgba(0,0,0,0.2);
    }

    .sidebar-menu a i {
        margin-right: 15px;
        width: 24px;
        text-align: center;
        font-size: 1.3rem;
        transition: var(--transition);
    }

    .sidebar.collapsed .sidebar-menu a span {
        opacity: 0;
        width: 0;
        height: 0;
        overflow: hidden;
    }

    .sidebar.collapsed .sidebar-menu a i {
        margin-right: 0;
        font-size: 1.5rem;
    }

    /* Badge styling */
    .badge {
        margin-left: auto;
        padding: 3px 8px;
        background: rgba(255, 255, 255, 0.2);
        border-radius: 20px;
        font-size: 0.7rem;
        font-weight: 600;
        transition: var(--transition);
    }

    .sidebar.collapsed .badge {
        opacity: 0;
        width: 0;
        overflow: hidden;
    }

    /* Tooltip for collapsed mode */
    .tooltip {
        position: absolute;
        left: 100%;
        top: 50%;
        transform: translateY(-50%);
        background: var(--dashen-primary);
        color: white;
        padding: 8px 12px;
        border-radius: 6px;
        font-size: 0.85rem;
        font-weight: 500;
        white-space: nowrap;
        box-shadow: var(--shadow-xl);
        opacity: 0;
        visibility: hidden;
        transition: all 0.2s ease;
        margin-left: 10px;
        z-index: 1002;
        pointer-events: none;
    }

    .tooltip::before {
        content: '';
        position: absolute;
        left: -5px;
        top: 50%;
        transform: translateY(-50%);
        border-width: 5px 5px 5px 0;
        border-style: solid;
        border-color: transparent var(--dashen-primary) transparent transparent;
    }

    .sidebar.collapsed .sidebar-menu li:hover .tooltip {
        opacity: 1;
        visibility: visible;
    }

    /* Sidebar Footer - matches main dashboard */
    .sidebar-footer {
        padding: 25px;
        border-top: 1px solid rgba(255, 255, 255, 0.2);
        background: rgba(255, 255, 255, 0.1);
        transition: var(--transition);
    }

    .user-info {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 15px;
        transition: var(--transition);
    }

    .sidebar.collapsed .user-info {
        justify-content: center;
    }

    .user-avatar {
        width: 45px;
        height: 45px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.2);
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 1.2rem;
        color: white;
        border: 2px solid rgba(255, 255, 255, 0.5);
        transition: var(--transition);
        overflow: hidden;
    }

    .user-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .user-details {
        flex: 1;
        transition: var(--transition);
    }

    .sidebar.collapsed .user-details {
        opacity: 0;
        width: 0;
        overflow: hidden;
    }

    .user-name {
        font-weight: 600;
        color: white;
        margin-bottom: 4px;
        font-size: 0.95rem;
    }

    .user-role {
        font-size: 0.8rem;
        color: rgba(255, 255, 255, 0.7);
    }

    .logout-btn {
        width: 100%;
        padding: 12px;
        background: rgba(255, 255, 255, 0.2);
        color: white;
        border: 2px solid rgba(255, 255, 255, 0.3);
        border-radius: 10px;
        font-weight: 600;
        transition: var(--transition);
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        font-size: 0.9rem;
        cursor: pointer;
        border: none;
    }

    .logout-btn:hover {
        background: rgba(255, 255, 255, 0.3);
        transform: translateY(-2px);
    }

    .sidebar.collapsed .logout-btn span {
        display: none;
    }

    /* Mobile Menu Toggle */
    .mobile-sidebar-toggle {
        display: none;
        position: fixed;
        top: 20px;
        left: 20px;
        z-index: 1100;
        background: var(--dashen-primary);
        color: white;
        border: none;
        width: 50px;
        height: 50px;
        border-radius: 12px;
        font-size: 1.4rem;
        box-shadow: var(--shadow-xl);
        transition: var(--transition);
        align-items: center;
        justify-content: center;
        cursor: pointer;
    }

    .mobile-sidebar-toggle:hover {
        background: var(--dashen-secondary);
        transform: scale(1.05);
    }

    /* Mobile Responsive */
    @media (max-width: 992px) {
        .sidebar {
            transform: translateX(-100%);
            width: var(--sidebar-width) !important;
        }

        .sidebar.active {
            transform: translateX(0);
        }

        .sidebar.collapsed {
            width: var(--sidebar-width) !important;
        }

        .sidebar.collapsed .sidebar-menu a span {
            opacity: 1;
            width: auto;
            height: auto;
            overflow: visible;
        }

        .sidebar.collapsed .sidebar-menu a i {
            margin-right: 15px;
            font-size: 1.3rem;
        }

        .sidebar.collapsed .user-details {
            opacity: 1;
            width: auto;
            overflow: visible;
        }

        .sidebar.collapsed .logout-btn span {
            display: inline;
        }

        .sidebar-toggle-btn {
            display: none;
        }

        .mobile-sidebar-toggle {
            display: flex;
        }
    }

    /* Main Content Adjustment */
    .main-content {
        margin-left: var(--sidebar-width);
        transition: var(--transition);
        min-height: 100vh;
    }

    .main-content.expanded {
        margin-left: var(--sidebar-collapsed-width);
    }

    @media (max-width: 992px) {
        .main-content {
            margin-left: 0;
        }

        .main-content.expanded {
            margin-left: 0;
        }
    }
</style>

<!-- Mobile Toggle Button -->
<button class="mobile-sidebar-toggle" id="mobileSidebarToggle">
    <i class="fas fa-bars"></i>
</button>

<!-- Sidebar Navigation -->
<div class="sidebar <?php echo isset($_SESSION['sidebar_collapsed']) && $_SESSION['sidebar_collapsed'] ? 'collapsed' : ''; ?>" id="sidebar">
    <!-- Sidebar Toggle Button (Desktop only) -->
    <button class="sidebar-toggle-btn" id="sidebarToggleBtn">
        <i class="fas fa-chevron-left"></i>
    </button>

    <div class="sidebar-header">
        <div class="sidebar-logo">
            <img src="../Images/DashenLogo12.png" alt="Dashen Bank Logo" onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTQwIiBoZWlnaHQ9IjQwIiB2aWV3Qm94PSIwIDAgMTQwIDQwIiBmaWxsPSJub25lIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPjxwYXRoIGQ9Ik0yMCAxMEg0MFYzMEgyMFYxMFoiIGZpbGw9IndoaXRlIi8+PHNwYW4geD0iNTAiIHk9IjIwIiBmaWxsPSJ3aGl0ZSIgZm9udC1mYW1pbHk9IkFyaWFsIiBmb250LXNpemU9IjE0Ij5EYXNoZW4gQmFuazwvc3Bhbj48L3N2Zz4='">
        </div>
        <h3 class="sidebar-title">Dashen Bank</h3>
    </div>

    <div class="sidebar-menu">
        <ul class="list-unstyled">
            <!-- Main Navigation Items -->
            <li>
                <a class="<?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>" href="dashboard.php">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Dashboard</span>
                    <span class="badge"></span>
                </a>
                <div class="tooltip">Dashboard</div>
            </li>
            
            <li>
                <a class="<?php echo basename($_SERVER['PHP_SELF']) == '../dashboard.php' ? 'active' : ''; ?>" href="../dashboard.php">
                    <i class="fas fa-chart-line"></i>
                    <span>Unified Dashboard</span>
                    <span class="badge">New</span>
                </a>
                <div class="tooltip">Unified Dashboard</div>
            </li>

            <li>
                <a class="<?php echo basename($_SERVER['PHP_SELF']) == 'events.php' ? 'active' : ''; ?>" href="events.php">
                    <i class="fas fa-calendar-alt"></i>
                    <span>Events</span>
                    <span class="badge"></span>
                </a>
                <div class="tooltip">Events</div>
            </li>
            
            <li>
                <a class="<?php echo basename($_SERVER['PHP_SELF']) == 'attendees.php' ? 'active' : ''; ?>" href="attendees.php">
                    <i class="fas fa-users"></i>
                    <span>Attendees</span>
                    <span class="badge"></span>
                </a>
                <div class="tooltip">Attendees</div>
            </li>
            
            <li>
                <a class="<?php echo basename($_SERVER['PHP_SELF']) == 'tasks.php' ? 'active' : ''; ?>" href="tasks.php">
                    <i class="fas fa-tasks"></i>
                    <span>Tasks</span>
                    <span class="badge">3</span>
                </a>
                <div class="tooltip">Tasks</div>
            </li>
            
            <li>
                <a class="<?php echo basename($_SERVER['PHP_SELF']) == 'resources.php' ? 'active' : ''; ?>" href="resources.php">
                    <i class="fas fa-box"></i>
                    <span>Resources</span>
                    <span class="badge"></span>
                </a>
                <div class="tooltip">Resources</div>
            </li>
            
            <li>
                <a class="<?php echo basename($_SERVER['PHP_SELF']) == 'reports.php' ? 'active' : ''; ?>" href="reports.php">
                    <i class="fas fa-chart-bar"></i>
                    <span>Reports</span>
                    <span class="badge"></span>
                </a>
                <div class="tooltip">Reports</div>
            </li>

            <!-- Additional System Items -->
            <li>
                <a href="settings.php">
                    <i class="fas fa-cog"></i>
                    <span>Settings</span>
                    <span class="badge"></span>
                </a>
                <div class="tooltip">Settings</div>
            </li>
            
            <li>
                <a href="help.php">
                    <i class="fas fa-question-circle"></i>
                    <span>Help</span>
                    <span class="badge"></span>
                </a>
                <div class="tooltip">Help</div>
            </li>
        </ul>
    </div>

    <div class="sidebar-footer">
        <div class="user-info">
            <div class="user-avatar">
                <?php if ($profile_image && file_exists($profile_image)): ?>
                    <img src="<?php echo $profile_image; ?>" alt="Profile">
                <?php else: ?>
                    <?php echo $initials; ?>
                <?php endif; ?>
            </div>
            <div class="user-details">
                <div class="user-name"><?php echo htmlspecialchars($username); ?></div>
                <div class="user-role"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $role))); ?></div>
            </div>
        </div>
        <button class="logout-btn" onclick="window.location.href='../logout.php'">
            <i class="fas fa-sign-out-alt"></i>
            <span>Logout</span>
        </button>
    </div>
</div>

<script>
// Sidebar Functionality - Exactly like main dashboard
document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.getElementById('sidebar');
    const sidebarToggle = document.getElementById('sidebarToggleBtn');
    const mainContent = document.getElementById('mainContent');
    const mobileToggle = document.getElementById('mobileSidebarToggle');
    const mainHeader = document.getElementById('mainHeader');
    
    // Initialize sidebar state from session
    <?php if (isset($_SESSION['sidebar_collapsed'])): ?>
        if (<?php echo $_SESSION['sidebar_collapsed'] ? 'true' : 'false'; ?>) {
            sidebar.classList.add('collapsed');
            if (mainContent) mainContent.classList.add('expanded');
            if (mainHeader) mainHeader.classList.add('expanded');
            if (sidebarToggle) sidebarToggle.querySelector('i').className = 'fas fa-chevron-right';
        }
    <?php endif; ?>
    
    // Desktop toggle functionality
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function() {
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('expanded');
            if (mainHeader) mainHeader.classList.toggle('expanded');
            
            const icon = this.querySelector('i');
            if (sidebar.classList.contains('collapsed')) {
                icon.className = 'fas fa-chevron-right';
                // Save state via AJAX
                saveSidebarState(true);
            } else {
                icon.className = 'fas fa-chevron-left';
                saveSidebarState(false);
            }
        });
    }
    
    // Function to save sidebar state
    function saveSidebarState(isCollapsed) {
        const formData = new FormData();
        formData.append('action', 'toggle_sidebar');
        formData.append('collapsed', isCollapsed ? '1' : '0');
        
        fetch('', {
            method: 'POST',
            body: formData
        }).catch(error => console.error('Error saving sidebar state:', error));
    }
    
    // Mobile toggle functionality
    if (mobileToggle) {
        mobileToggle.addEventListener('click', function() {
            sidebar.classList.toggle('active');
        });
        
        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            if (window.innerWidth <= 992) {
                if (!sidebar.contains(event.target) && !mobileToggle.contains(event.target)) {
                    sidebar.classList.remove('active');
                }
            }
        });
    }
    
    // Handle window resize
    window.addEventListener('resize', function() {
        if (window.innerWidth <= 992) {
            // Mobile view
            sidebar.classList.remove('collapsed');
            if (mainContent) mainContent.classList.remove('expanded');
            if (mainHeader) mainHeader.classList.remove('expanded');
            if (sidebarToggle) sidebarToggle.querySelector('i').className = 'fas fa-chevron-left';
        } else {
            // Desktop view - restore saved state from session
            <?php if (isset($_SESSION['sidebar_collapsed'])): ?>
                if (<?php echo $_SESSION['sidebar_collapsed'] ? 'true' : 'false'; ?>) {
                    sidebar.classList.add('collapsed');
                    if (mainContent) mainContent.classList.add('expanded');
                    if (mainHeader) mainHeader.classList.add('expanded');
                    if (sidebarToggle) sidebarToggle.querySelector('i').className = 'fas fa-chevron-right';
                } else {
                    sidebar.classList.remove('collapsed');
                    if (mainContent) mainContent.classList.remove('expanded');
                    if (mainHeader) mainHeader.classList.remove('expanded');
                    if (sidebarToggle) sidebarToggle.querySelector('i').className = 'fas fa-chevron-left';
                }
            <?php endif; ?>
        }
    });
    
    // Tooltip functionality for collapsed mode
    const navLinks = document.querySelectorAll('.sidebar-menu a');
    navLinks.forEach(link => {
        const tooltip = link.parentElement.querySelector('.tooltip');
        if (tooltip) {
            link.addEventListener('mouseenter', function() {
                if (sidebar.classList.contains('collapsed') && window.innerWidth > 992) {
                    tooltip.style.opacity = '1';
                    tooltip.style.visibility = 'visible';
                }
            });
            
            link.addEventListener('mouseleave', function() {
                tooltip.style.opacity = '0';
                tooltip.style.visibility = 'hidden';
            });
        }
    });
    
    // Keyboard shortcut (Ctrl+B) to toggle sidebar
    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey && e.key === 'b') {
            e.preventDefault();
            if (sidebarToggle) sidebarToggle.click();
        }
        
        // Escape to close mobile sidebar
        if (e.key === 'Escape' && window.innerWidth <= 992) {
            sidebar.classList.remove('active');
        }
    });
    
    // Add custom styles for animations
    const style = document.createElement('style');
    style.textContent = `
        .sidebar.collapsing,
        .sidebar.expanding {
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.1);
        }
    `;
    document.head.appendChild(style);
});
</script>