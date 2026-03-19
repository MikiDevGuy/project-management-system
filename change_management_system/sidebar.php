<?php
// sidebar.php
if (!isset($_SESSION)) {
    session_start();
}
require_once '../db.php';

$current_page = basename($_SERVER['PHP_SELF']);
$user_id = $_SESSION['user_id'] ?? 0;
$user_role = $_SESSION['system_role'] ?? '';

// Get pending approvals count for admins/super_admins
$pending_approvals = 0;
if (in_array(strtolower($user_role), ['admin', 'super_admin'])) {
    $sql = "SELECT COUNT(*) as count FROM change_requests WHERE status = 'Open'";
    $result = $conn->query($sql);
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $pending_approvals = $row['count'];
    }
}
?>

<style>
    :root {
        --dashen-primary: #273274;
        --dashen-secondary: #1e2559;
        --dashen-accent: #f58220;
        --dashen-light: #f5f7fb;
        --sidebar-width: 280px;
        --sidebar-collapsed-width: 80px;
        --transition-speed: 0.3s;
    }
    
    .sidebar {
        height: 100vh;
        background: linear-gradient(180deg, var(--dashen-primary) 0%, var(--dashen-secondary) 100%);
        color: #fff;
        transition: all var(--transition-speed) ease;
        box-shadow: 3px 0 20px rgba(0, 0, 0, 0.15);
        position: fixed;
        z-index: 1000;
        width: var(--sidebar-width);
        display: flex;
        flex-direction: column;
        left: 0;
        top: 0;
    }
    
    .sidebar.collapsed {
        width: var(--sidebar-collapsed-width);
    }
    
    .sidebar-header {
        padding: 20px;
        background: var(--dashen-secondary);
        border-bottom: 1px solid rgba(255, 255, 255, 0.15);
        display: flex;
        justify-content: space-between;
        align-items: center;
        min-height: 80px;
        flex-shrink: 0;
    }
    
    .sidebar-logo {
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all var(--transition-speed) ease;
        overflow: hidden;
    }
    
    .sidebar-logo img {
        max-width: 160px;
        height: auto;
        transition: all var(--transition-speed) ease;
    }
    
    .sidebar.collapsed .sidebar-logo img {
        max-width: 40px;
        transform: scale(1.1);
    }
    
    .sidebar-toggle {
        background: rgba(255, 255, 255, 0.15);
        border: none;
        color: white;
        cursor: pointer;
        padding: 10px;
        border-radius: 8px;
        transition: all 0.3s ease;
        width: 40px;
        height: 40px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }
    
    .sidebar-toggle:hover {
        background: rgba(255, 255, 255, 0.25);
        transform: scale(1.05);
    }
    
    .sidebar-content {
        flex: 1;
        display: flex;
        flex-direction: column;
        overflow: hidden;
    }
    
    .sidebar-menu {
        flex: 1;
        padding: 10px 0;
        overflow-y: auto;
        overflow-x: hidden;
    }
    
    .sidebar-menu::-webkit-scrollbar {
        width: 6px;
    }
    
    .sidebar-menu::-webkit-scrollbar-track {
        background: rgba(255, 255, 255, 0.05);
        border-radius: 3px;
    }
    
    .sidebar-menu::-webkit-scrollbar-thumb {
        background: var(--dashen-accent);
        border-radius: 3px;
    }
    
    .sidebar-menu::-webkit-scrollbar-thumb:hover {
        background: #e67300;
    }
    
    .nav-item {
        position: relative;
        margin-bottom: 2px;
    }
    
    .nav-link {
        padding: 14px 20px;
        display: flex;
        align-items: center;
        color: rgba(255, 255, 255, 0.85);
        text-decoration: none;
        transition: all var(--transition-speed) ease;
        border-left: 4px solid transparent;
        position: relative;
        margin: 2px 10px;
        border-radius: 8px;
        overflow: hidden;
        min-height: 52px;
    }
    
    .nav-link::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.1), transparent);
        transition: left 0.5s ease;
    }
    
    .nav-link:hover::before {
        left: 100%;
    }
    
    .nav-link:hover {
        color: #fff;
        background: rgba(255, 255, 255, 0.1);
        border-left-color: var(--dashen-accent);
        transform: translateX(5px);
    }
    
    .nav-link.active {
        background: rgba(255, 255, 255, 0.15);
        color: white;
        border-left-color: var(--dashen-accent);
        box-shadow: 0 4px 12px rgba(245, 130, 32, 0.3);
    }
    
    .nav-link i {
        width: 24px;
        text-align: center;
        font-size: 1.2rem;
        transition: all var(--transition-speed) ease;
        flex-shrink: 0;
    }
    
    .nav-text {
        margin-left: 12px;
        white-space: nowrap;
        transition: all var(--transition-speed) ease;
        font-weight: 500;
        opacity: 1;
        transform: translateX(0);
        flex: 1;
    }
    
    .sidebar.collapsed .nav-text {
        opacity: 0;
        transform: translateX(-20px);
        width: 0;
        position: absolute;
    }
    
    .sidebar.collapsed .nav-link {
        justify-content: center;
        padding: 14px 10px;
        margin: 2px 5px;
    }
    
    .sidebar.collapsed .nav-link i {
        font-size: 1.4rem;
        margin: 0;
    }
    
    .badge-container {
        margin-left: auto;
        transition: all var(--transition-speed) ease;
        flex-shrink: 0;
    }
    
    .sidebar.collapsed .badge-container {
        position: absolute;
        top: 5px;
        right: 5px;
        transform: scale(0.8);
    }
    
    .badge.bg-accent {
        background: var(--dashen-accent) !important;
        color: white;
        font-size: 0.7rem;
        min-width: 20px;
        height: 20px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 10px;
        font-weight: 600;
    }
    
    .sidebar-footer {
        border-top: 1px solid rgba(255, 255, 255, 0.15);
        padding: 10px 0;
        background: rgba(0, 0, 0, 0.1);
        flex-shrink: 0;
    }
    
    .sidebar-footer .nav-link {
        border-left: none;
        margin: 1px 10px;
    }
    
    .sidebar.collapsed .sidebar-footer .nav-link {
        margin: 1px 5px;
    }
    
    .sidebar-footer .nav-link.text-danger:hover {
        background: rgba(220, 53, 69, 0.2);
    }
    
    /* Tooltip for collapsed state */
    .nav-link .tooltip-text {
        position: absolute;
        left: 100%;
        top: 50%;
        transform: translateY(-50%);
        background: var(--dashen-secondary);
        color: white;
        padding: 8px 12px;
        border-radius: 6px;
        font-size: 0.85rem;
        white-space: nowrap;
        opacity: 0;
        visibility: hidden;
        transition: all 0.3s ease;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        z-index: 1001;
        margin-left: 10px;
        pointer-events: none;
    }
    
    .nav-link .tooltip-text::before {
        content: '';
        position: absolute;
        right: 100%;
        top: 50%;
        transform: translateY(-50%);
        border: 6px solid transparent;
        border-right-color: var(--dashen-secondary);
    }
    
    .sidebar.collapsed .nav-link:hover .tooltip-text {
        opacity: 1;
        visibility: visible;
    }
    
    /* Mobile responsiveness */
    @media (max-width: 768px) {
        .sidebar {
            transform: translateX(-100%);
            width: var(--sidebar-width);
        }
        
        .sidebar.mobile-open {
            transform: translateX(0);
        }
        
        .sidebar.collapsed {
            width: var(--sidebar-width);
            transform: translateX(-100%);
        }
        
        .sidebar.collapsed.mobile-open {
            transform: translateX(0);
        }
        
        .mobile-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
        }
        
        .mobile-overlay.active {
            display: block;
        }
    }
</style>

<!-- Mobile Overlay -->
<div class="mobile-overlay" id="mobileOverlay"></div>

<!-- Sidebar -->
<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <!-- Dashen Bank Logo -->
        <div class="sidebar-logo">
            <img src="../Images/DashenLogo12.png" alt="Dashen Bank Logo" class="logo-img">
        </div>
        <button class="sidebar-toggle" id="sidebarToggle">
            <i class="fas fa-chevron-left" id="toggleIcon"></i>
        </button>
    </div>
    
    <div class="sidebar-content">
        <div class="sidebar-menu">
            <ul class="nav flex-column">
                <li class="nav-item">
                    <a class="nav-link <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>" href="dashboard.php">
                        <i class="fas fa-tachometer-alt"></i>
                        <span class="nav-text">Dashboard</span>
                        <span class="tooltip-text">Dashboard</span>
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link <?php echo $current_page == '../dashboard.php' ? 'active' : ''; ?>" href="../dashboard.php">
                        <i class="fas fa-chart-line"></i>
                        <span class="nav-text">Unified Dashboard</span>
                        <span class="tooltip-text">Unified Dashboard</span>
                    </a>
                </li>
                
                <!-- Show Change Requests link for pm_employee and pm_manager -->
                <?php if (in_array(strtolower($user_role), ['pm_employee', 'pm_manager'])): ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo $current_page == 'change_management.php' ? 'active' : ''; ?>" href="change_management.php">
                        <i class="fas fa-file-alt"></i>
                        <span class="nav-text">Change Requests</span>
                        <span class="tooltip-text">Change Requests</span>
                    </a>
                </li>
                <?php endif; ?>
                
                <!-- Show Approvals link for all roles -->
                <li class="nav-item">
                    <a class="nav-link <?php echo $current_page == 'approvals.php' ? 'active' : ''; ?>" href="approvals.php">
                        <i class="fas fa-check-circle"></i>
                        <span class="nav-text">Approvals</span>
                        <span class="tooltip-text">Approvals</span>
                        <?php if ($pending_approvals > 0): ?>
                            <div class="badge-container">
                                <span class="badge bg-accent rounded-pill"><?php echo $pending_approvals; ?></span>
                            </div>
                        <?php endif; ?>
                    </a>
                </li>
                
                <li class="nav-item">
                    <a class="nav-link <?php echo $current_page == 'reports.php' ? 'active' : ''; ?>" href="reports.php">
                        <i class="fas fa-chart-bar"></i>
                        <span class="nav-text">Reports & Analytics</span>
                        <span class="tooltip-text">Reports & Analytics</span>
                    </a>
                </li>
            </ul>
        </div>
        
        <div class="sidebar-footer">
            <ul class="nav flex-column">
                <li class="nav-item">
                    <a class="nav-link" href="#" onclick="openNotifications()">
                        <i class="fas fa-bell"></i>
                        <span class="nav-text">Notifications</span>
                        <span class="tooltip-text">Notifications</span>
                        <div class="badge-container">
                            <span class="badge bg-accent rounded-pill" id="notificationBadge">0</span>
                        </div>
                    </a>
                </li>
                
                <li class="nav-item">
                    <a class="nav-link <?php echo $current_page == 'profile.php' ? 'active' : ''; ?>" href="profile.php">
                        <i class="fas fa-user-cog"></i>
                        <span class="nav-text">Profile Settings</span>
                        <span class="tooltip-text">Profile Settings</span>
                    </a>
                </li>
                
                <li class="nav-item">
                    <a class="nav-link text-danger" href="../logout.php">
                        <i class="fas fa-sign-out-alt"></i>
                        <span class="nav-text">Logout</span>
                        <span class="tooltip-text">Logout</span>
                    </a>
                </li>
            </ul>
        </div>
    </div>
</div>

<!-- Notifications Modal -->
<div class="modal fade" id="notificationsModal" tabindex="-1" aria-labelledby="notificationsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="notificationsModalLabel">
                    <i class="fas fa-bell me-2"></i>Notifications
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <iframe id="notificationsFrame" src="about:blank" style="width: 100%; height: 500px; border: none;"></iframe>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="markAllAsRead()">
                    <i class="fas fa-check-double me-2"></i>Mark All as Read
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/js/all.min.js"></script>
<script>
// Enhanced Sidebar functionality
document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.getElementById('sidebar');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const toggleIcon = document.getElementById('toggleIcon');
    const mobileOverlay = document.getElementById('mobileOverlay');
    
    // Check if sidebar state is saved in localStorage
    const isCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
    if (isCollapsed) {
        sidebar.classList.add('collapsed');
        toggleIcon.className = 'fas fa-chevron-right';
    }
    
    // Toggle sidebar
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function(e) {
            e.stopPropagation();
            sidebar.classList.toggle('collapsed');
            
            // Update toggle icon
            if (sidebar.classList.contains('collapsed')) {
                toggleIcon.className = 'fas fa-chevron-right';
                localStorage.setItem('sidebarCollapsed', 'true');
            } else {
                toggleIcon.className = 'fas fa-chevron-left';
                localStorage.setItem('sidebarCollapsed', 'false');
            }
            
            // Dispatch custom event for other components to listen to
            window.dispatchEvent(new Event('sidebarToggle'));
        });
    }
    
    // Mobile menu functionality
    const mobileMenuBtn = document.getElementById('mobileMenuBtn');
    if (mobileMenuBtn) {
        mobileMenuBtn.addEventListener('click', function() {
            sidebar.classList.add('mobile-open');
            mobileOverlay.classList.add('active');
            document.body.style.overflow = 'hidden';
        });
    }
    
    // Close sidebar when clicking on overlay (mobile)
    if (mobileOverlay) {
        mobileOverlay.addEventListener('click', function() {
            sidebar.classList.remove('mobile-open');
            mobileOverlay.classList.remove('active');
            document.body.style.overflow = '';
        });
    }
    
    // Close sidebar when clicking on a link (mobile)
    const navLinks = document.querySelectorAll('.nav-link');
    navLinks.forEach(link => {
        link.addEventListener('click', function() {
            if (window.innerWidth <= 768) {
                sidebar.classList.remove('mobile-open');
                mobileOverlay.classList.remove('active');
                document.body.style.overflow = '';
            }
        });
    });
    
    // Handle window resize
    function handleResize() {
        if (window.innerWidth > 768) {
            sidebar.classList.remove('mobile-open');
            mobileOverlay.classList.remove('active');
            document.body.style.overflow = '';
        }
    }
    
    window.addEventListener('resize', handleResize);
    
    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Ctrl + B to toggle sidebar
        if (e.ctrlKey && e.key === 'b') {
            e.preventDefault();
            if (sidebarToggle) sidebarToggle.click();
        }
        
        // Escape to close sidebar on mobile
        if (e.key === 'Escape' && window.innerWidth <= 768) {
            sidebar.classList.remove('mobile-open');
            mobileOverlay.classList.remove('active');
            document.body.style.overflow = '';
        }
    });
    
    // Load notification count
    updateNotificationBadge();
    // Update notification badge every 30 seconds
    setInterval(updateNotificationBadge, 30000);
});

// Notifications functionality
function openNotifications() {
    const modal = new bootstrap.Modal(document.getElementById('notificationsModal'));
    const frame = document.getElementById('notificationsFrame');
    frame.src = 'notifications.php';
    modal.show();
}

// In sidebar.php, update the updateNotificationBadge function:
function updateNotificationBadge() {
    fetch('notifications.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=get_count'
    })
    .then(response => response.json())
    .then(data => {
        const badge = document.getElementById('notificationBadge');
        if (badge) {
            if (data.count > 0) {
                badge.textContent = data.count;
                badge.style.display = 'flex';
            } else {
                badge.style.display = 'none';
            }
        }
    })
    .catch(error => {
        console.error('Error loading notification count:', error);
        // Hide badge on error
        const badge = document.getElementById('notificationBadge');
        if (badge) {
            badge.style.display = 'none';
        }
    });
}

function markAllAsRead() {
    const frame = document.getElementById('notificationsFrame');
    if (frame.contentWindow.markAllAsRead) {
        frame.contentWindow.markAllAsRead();
    }
}

// Export functions for other scripts
window.updateNotificationBadge = updateNotificationBadge;
window.openNotifications = openNotifications;
window.markAllAsRead = markAllAsRead;

// Export sidebar toggle function for other scripts
window.toggleSidebar = function() {
    const sidebarToggle = document.getElementById('sidebarToggle');
    if (sidebarToggle) sidebarToggle.click();
};

// Function to check if sidebar is collapsed
window.isSidebarCollapsed = function() {
    const sidebar = document.getElementById('sidebar');
    return sidebar ? sidebar.classList.contains('collapsed') : false;
};
</script>