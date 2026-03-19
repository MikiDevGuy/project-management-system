<?php
// sidebar.php
// This file should be included in pages that need the sidebar navigation

// Get unread notification count (if not already set)
if (!isset($unread_count)) {
    $unread_count = 0;
    if (isset($_SESSION['user_id'])) {
        $user_id = $_SESSION['user_id'];
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $unread_count = $result->fetch_assoc()['count'];
        $stmt->close();
    }
}

// Define sidebar menu items dynamically
$menu_items = [
    [
        'title' => 'Dashboard',
        'icon' => 'fas fa-home',
        'link' => 'index.php',
        'active' => basename($_SERVER['PHP_SELF']) == 'index.php'
    ],
    [
        'title' => 'Unified Dashboard',
        'icon' => 'fas fa-th-large',
        'link' => '../dashboard.php',
        'active' => basename($_SERVER['PHP_SELF']) == 'dashboard.php'
    ],
    [
        'title' => 'Issues',
        'icon' => 'fas fa-tasks',
        'link' => 'issues.php',
        'active' => basename($_SERVER['PHP_SELF']) == 'issues.php'
    ],
];

// Add reports menu item only for superadmin users
if (function_exists('hasRole') && hasRole('super_admin')) {
    $menu_items[] = [
        'title' => 'Reports',
        'icon' => 'fas fa-chart-bar',
        'link' => 'reports.php',
        'active' => basename($_SERVER['PHP_SELF']) == 'reports.php'
    ];
}


// Add profile and logout items
$menu_items[] = [
    'title' => 'Profile',
    'icon' => 'fas fa-user',
    'link' => '../profile.php',
    'active' => basename($_SERVER['PHP_SELF']) == 'profile.php'
];

$menu_items[] = [
    'title' => 'Logout',
    'icon' => 'fas fa-sign-out-alt',
    'link' => '../logout.php',
    'active' => false
];

// Get current user info for sidebar footer
$current_user_name = $_SESSION['username'] ?? $_SESSION['user_name'] ?? 'User';
$current_user_role = ucfirst(str_replace('_', ' ', $_SESSION['system_role'] ?? 'user'));
?>

<!-- Sidebar Styles -->
<style>
    :root {
        --dashen-primary: #273274;
        --dashen-secondary: #1e2559;
        --dashen-accent: #f58220;
        --dashen-light: #f5f7fb;
    }
    
    .sidebar {
        min-height: 100vh;
        background: linear-gradient(180deg, var(--dashen-primary) 0%, var(--dashen-secondary) 100%);
        color: #fff;
        transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
        box-shadow: 4px 0 20px rgba(0, 0, 0, 0.1);
        position: fixed;
        z-index: 1000;
        width: 280px;
        display: flex;
        flex-direction: column;
        border-right: 1px solid rgba(255, 255, 255, 0.1);
    }
    
    .sidebar.collapsed {
        width: 80px;
    }
    
    .sidebar-header {
        padding: 25px 20px;
        background: rgba(255, 255, 255, 0.05);
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        text-align: center;
        position: relative;
    }
    
    .sidebar-logo {
        display: flex;
        justify-content: center;
        margin-bottom: 15px;
        transition: all 0.3s;
    }
    
    .sidebar-logo img {
        max-width: 120px;
        height: auto;
        transition: all 0.3s;
    }
    
    .sidebar.collapsed .sidebar-logo img {
        max-width: 40px;
    }
    
    .sidebar-title {
        color: white;
        font-weight: 700;
        margin: 0;
        font-size: 1.1rem;
        transition: all 0.3s;
        opacity: 1;
    }
    
    .sidebar.collapsed .sidebar-title {
        opacity: 0;
        height: 0;
        overflow: hidden;
        margin: 0;
    }
    
    .sidebar-menu {
        padding: 20px 0;
        flex-grow: 1;
        overflow-y: auto;
    }
    
    .sidebar-menu ul {
        list-style: none;
        padding: 0;
        margin: 0;
    }
    
    .sidebar-menu li {
        margin: 5px 0;
    }
    
    .sidebar-menu a {
        padding: 15px 20px;
        display: flex;
        align-items: center;
        color: rgba(255, 255, 255, 0.85);
        text-decoration: none;
        transition: all 0.3s;
        border-left: 4px solid transparent;
        position: relative;
        font-weight: 500;
    }
    
    .sidebar-menu a:hover {
        color: #fff;
        background: rgba(255, 255, 255, 0.1);
        border-left-color: var(--dashen-accent);
        transform: translateX(5px);
    }
    
    .sidebar-menu a.active {
        background: rgba(255, 255, 255, 0.15);
        color: white;
        border-left-color: var(--dashen-accent);
        box-shadow: inset 0 0 20px rgba(255, 255, 255, 0.05);
    }
    
    .sidebar-menu a i {
        margin-right: 15px;
        width: 20px;
        text-align: center;
        font-size: 1.2rem;
        transition: all 0.3s;
    }
    
    .sidebar.collapsed .sidebar-menu a span {
        opacity: 0;
        width: 0;
        height: 0;
        overflow: hidden;
        transition: all 0.3s;
    }
    
    .sidebar.collapsed .sidebar-menu a i {
        margin-right: 0;
        font-size: 1.3rem;
    }
    
    .badge {
        margin-left: auto;
        background: var(--dashen-accent);
        color: white;
        border-radius: 12px;
        min-width: 22px;
        height: 22px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.7rem;
        font-weight: 700;
        transition: all 0.3s;
    }
    
    .sidebar.collapsed .badge {
        position: absolute;
        top: 8px;
        right: 8px;
        width: 18px;
        height: 18px;
        font-size: 0.6rem;
    }
    
    .sidebar-footer {
        padding: 20px;
        border-top: 1px solid rgba(255, 255, 255, 0.1);
        background: rgba(255, 255, 255, 0.05);
    }
    
    .user-info {
        display: flex;
        align-items: center;
        color: rgba(255, 255, 255, 0.9);
        transition: all 0.3s;
    }
    
    .sidebar.collapsed .user-info {
        justify-content: center;
    }
    
    .user-info i {
        margin-right: 12px;
        font-size: 1.4rem;
        transition: all 0.3s;
        color: var(--dashen-accent);
    }
    
    .sidebar.collapsed .user-info i {
        margin-right: 0;
        font-size: 1.6rem;
    }
    
    .user-details {
        flex-grow: 1;
        transition: all 0.3s;
        overflow: hidden;
    }
    
    .sidebar.collapsed .user-details {
        opacity: 0;
        width: 0;
        height: 0;
        overflow: hidden;
    }
    
    .user-name {
        font-weight: 600;
        font-size: 0.9rem;
        white-space: nowrap;
    }
    
    .user-role {
        font-size: 0.75rem;
        color: var(--dashen-accent);
        margin-top: 2px;
        font-weight: 500;
        white-space: nowrap;
    }
    
    .sidebar-toggle-btn {
        position: absolute;
        top: 25px;
        right: -15px;
        width: 30px;
        height: 30px;
        border-radius: 50%;
        background: var(--dashen-primary);
        color: white;
        border: 2px solid white;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        z-index: 1001;
        box-shadow: 0 3px 10px rgba(0, 0, 0, 0.2);
        transition: all 0.3s;
        font-size: 0.9rem;
    }
    
    .sidebar-toggle-btn:hover {
        background: var(--dashen-accent);
        transform: scale(1.1);
    }
    
    .sidebar.collapsed .sidebar-toggle-btn i {
        transform: rotate(180deg);
    }
    
    /* Main Content Adjustment - EXACTLY like issues.php */
    .main-content {
        margin-left: 280px;
        width: calc(100% - 280px);
        padding: 30px;
        min-height: 100vh;
        transition: margin-left 0.3s cubic-bezier(0.25, 0.8, 0.25, 1), width 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
    }
    
    .main-content.expanded {
        margin-left: 80px;
        width: calc(100% - 80px);
    }
    
    /* Mobile responsive */
    @media (max-width: 1200px) {
        .main-content {
            margin-left: 80px;
            width: calc(100% - 80px);
        }
        
        .main-content.expanded {
            margin-left: 80px;
            width: calc(100% - 80px);
        }
    }
    
    @media (max-width: 992px) {
        .sidebar {
            transform: translateX(-100%);
            width: 280px !important;
        }
        
        .sidebar.active {
            transform: translateX(0);
        }
        
        .sidebar.collapsed {
            width: 280px !important;
        }
        
        .sidebar.collapsed .sidebar-logo img {
            max-width: 120px;
        }
        
        .sidebar.collapsed .sidebar-title {
            opacity: 1;
            height: auto;
            overflow: visible;
        }
        
        .sidebar.collapsed .sidebar-menu a span {
            opacity: 1;
            width: auto;
            height: auto;
            overflow: visible;
        }
        
        .sidebar.collapsed .sidebar-menu a i {
            margin-right: 15px;
            font-size: 1.2rem;
        }
        
        .sidebar.collapsed .badge {
            position: static;
            margin-left: auto;
            width: auto;
            height: 22px;
            font-size: 0.7rem;
        }
        
        .sidebar.collapsed .user-info {
            justify-content: flex-start;
        }
        
        .sidebar.collapsed .user-info i {
            margin-right: 12px;
            font-size: 1.4rem;
        }
        
        .sidebar.collapsed .user-details {
            opacity: 1;
            width: auto;
            height: auto;
            overflow: visible;
        }
        
        .main-content {
            margin-left: 0;
            width: 100%;
            padding: 20px 15px;
        }
        
        .main-content.expanded {
            margin-left: 0;
            width: 100%;
        }
        
        .sidebar-toggle-btn {
            display: none;
        }
    }
    
    .mobile-sidebar-toggle {
        display: none;
        position: fixed;
        top: 20px;
        left: 20px;
        z-index: 1100;
        background: var(--dashen-primary);
        color: white;
        border: none;
        width: 45px;
        height: 45px;
        border-radius: 12px;
        font-size: 1.3rem;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        transition: all 0.3s;
        cursor: pointer;
    }
    
    .mobile-sidebar-toggle:hover {
        background: var(--dashen-accent);
        transform: scale(1.05);
    }
    
    @media (max-width: 992px) {
        .mobile-sidebar-toggle {
            display: flex;
            align-items: center;
            justify-content: center;
        }
    }
    
    /* Overlay for mobile */
    .sidebar-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.5);
        z-index: 999;
        backdrop-filter: blur(3px);
        transition: all 0.3s;
    }
    
    .sidebar-overlay.active {
        display: block;
    }
    
    @media (max-width: 992px) {
        .sidebar-overlay.active {
            display: block;
        }
    }
    
    /* Custom scrollbar for sidebar */
    .sidebar-menu::-webkit-scrollbar {
        width: 4px;
    }
    
    .sidebar-menu::-webkit-scrollbar-track {
        background: rgba(255, 255, 255, 0.1);
    }
    
    .sidebar-menu::-webkit-scrollbar-thumb {
        background: rgba(255, 255, 255, 0.3);
        border-radius: 2px;
    }
    
    .sidebar-menu::-webkit-scrollbar-thumb:hover {
        background: rgba(255, 255, 255, 0.5);
    }
    
    /* Ensure body has no overflow issues */
    body {
        overflow-x: hidden;
        position: relative;
        min-height: 100vh;
    }
</style>

<!-- Mobile Toggle Button -->
<button class="mobile-sidebar-toggle" id="mobileSidebarToggle">
    <i class="fas fa-bars"></i>
</button>

<!-- Sidebar Overlay for Mobile -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- Sidebar Navigation -->
<nav id="sidebar" class="sidebar">
    <!-- Sidebar Toggle Button for Desktop -->
    <button class="sidebar-toggle-btn" id="sidebarToggleBtn">
        <i class="fas fa-chevron-left"></i>
    </button>
    
    <div class="sidebar-header">
        <!-- Dashen Bank Logo -->
        <div class="sidebar-logo">
            <img src="../Images/DashenLogo12.png" alt="Dashen Bank Logo">
        </div>
        <h3 class="sidebar-title">Issue Tracker</h3>
    </div>
    
    <div class="sidebar-menu">
        <ul class="list-unstyled">
            <?php foreach ($menu_items as $item): ?>
            <li>
                <a href="<?php echo $item['link']; ?>" class="<?php echo $item['active'] ? 'active' : ''; ?>">
                    <i class="<?php echo $item['icon']; ?>"></i> 
                    <span><?php echo $item['title']; ?></span>
                    <?php if ($item['title'] === 'Notifications' && $unread_count > 0): ?>
                        <span class="badge"><?php echo $unread_count; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    
    <div class="sidebar-footer">
        <div class="user-info">
            <i class="fas fa-user-circle"></i>
            <div class="user-details">
                <div class="user-name"><?php echo htmlspecialchars($current_user_name); ?></div>
                <div class="user-role"><?php echo htmlspecialchars($current_user_role); ?></div>
            </div>
        </div>
    </div>
</nav>

<!-- Sidebar JavaScript - EXACTLY like issues.php -->
<?php
// sidebar.php
// This file should be included in pages that need the sidebar navigation

// Get unread notification count (if not already set)
if (!isset($unread_count)) {
    $unread_count = 0;
    if (isset($_SESSION['user_id'])) {
        $user_id = $_SESSION['user_id'];
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $unread_count = $result->fetch_assoc()['count'];
        $stmt->close();
    }
}

// Define sidebar menu items dynamically
$menu_items = [
    [
        'title' => 'Dashboard',
        'icon' => 'fas fa-home',
        'link' => 'index.php',
        'active' => basename($_SERVER['PHP_SELF']) == 'index.php'
    ],
    [
        'title' => 'Unified Dashboard',
        'icon' => 'fas fa-th-large',
        'link' => '../dashboard.php',
        'active' => basename($_SERVER['PHP_SELF']) == 'dashboard.php'
    ],
    [
        'title' => 'Issues',
        'icon' => 'fas fa-tasks',
        'link' => 'issues.php',
        'active' => basename($_SERVER['PHP_SELF']) == 'issues.php'
    ],
];

// Add reports menu item only for superadmin users
if (function_exists('hasRole') && hasRole('super_admin')) {
    $menu_items[] = [
        'title' => 'Reports',
        'icon' => 'fas fa-chart-bar',
        'link' => 'reports.php',
        'active' => basename($_SERVER['PHP_SELF']) == 'reports.php'
    ];
}


// Add profile and logout items
$menu_items[] = [
    'title' => 'Profile',
    'icon' => 'fas fa-user',
    'link' => '../profile.php',
    'active' => basename($_SERVER['PHP_SELF']) == 'profile.php'
];

$menu_items[] = [
    'title' => 'Logout',
    'icon' => 'fas fa-sign-out-alt',
    'link' => '../logout.php',
    'active' => false
];

// Get current user info for sidebar footer
$current_user_name = $_SESSION['username'] ?? $_SESSION['user_name'] ?? 'User';
$current_user_role = ucfirst(str_replace('_', ' ', $_SESSION['system_role'] ?? 'user'));
?>

<!-- Sidebar Styles -->
<style>
    :root {
        --dashen-primary: #273274;
        --dashen-secondary: #1e2559;
        --dashen-accent: #f58220;
        --dashen-light: #f5f7fb;
    }
    
    .sidebar {
        min-height: 100vh;
        background: linear-gradient(180deg, var(--dashen-primary) 0%, var(--dashen-secondary) 100%);
        color: #fff;
        transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
        box-shadow: 4px 0 20px rgba(0, 0, 0, 0.1);
        position: fixed;
        z-index: 1000;
        width: 280px;
        display: flex;
        flex-direction: column;
        border-right: 1px solid rgba(255, 255, 255, 0.1);
    }
    
    .sidebar.collapsed {
        width: 80px;
    }
    
    .sidebar-header {
        padding: 25px 20px;
        background: rgba(255, 255, 255, 0.05);
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        text-align: center;
        position: relative;
    }
    
    .sidebar-logo {
        display: flex;
        justify-content: center;
        margin-bottom: 15px;
        transition: all 0.3s;
    }
    
    .sidebar-logo img {
        max-width: 120px;
        height: auto;
        transition: all 0.3s;
    }
    
    .sidebar.collapsed .sidebar-logo img {
        max-width: 40px;
    }
    
    .sidebar-title {
        color: white;
        font-weight: 700;
        margin: 0;
        font-size: 1.1rem;
        transition: all 0.3s;
        opacity: 1;
    }
    
    .sidebar.collapsed .sidebar-title {
        opacity: 0;
        height: 0;
        overflow: hidden;
        margin: 0;
    }
    
    .sidebar-menu {
        padding: 20px 0;
        flex-grow: 1;
        overflow-y: auto;
    }
    
    .sidebar-menu ul {
        list-style: none;
        padding: 0;
        margin: 0;
    }
    
    .sidebar-menu li {
        margin: 5px 0;
    }
    
    .sidebar-menu a {
        padding: 15px 20px;
        display: flex;
        align-items: center;
        color: rgba(255, 255, 255, 0.85);
        text-decoration: none;
        transition: all 0.3s;
        border-left: 4px solid transparent;
        position: relative;
        font-weight: 500;
    }
    
    .sidebar-menu a:hover {
        color: #fff;
        background: rgba(255, 255, 255, 0.1);
        border-left-color: var(--dashen-accent);
        transform: translateX(5px);
    }
    
    .sidebar-menu a.active {
        background: rgba(255, 255, 255, 0.15);
        color: white;
        border-left-color: var(--dashen-accent);
        box-shadow: inset 0 0 20px rgba(255, 255, 255, 0.05);
    }
    
    .sidebar-menu a i {
        margin-right: 15px;
        width: 20px;
        text-align: center;
        font-size: 1.2rem;
        transition: all 0.3s;
    }
    
    .sidebar.collapsed .sidebar-menu a span {
        opacity: 0;
        width: 0;
        height: 0;
        overflow: hidden;
        transition: all 0.3s;
    }
    
    .sidebar.collapsed .sidebar-menu a i {
        margin-right: 0;
        font-size: 1.3rem;
    }
    
    .badge {
        margin-left: auto;
        background: var(--dashen-accent);
        color: white;
        border-radius: 12px;
        min-width: 22px;
        height: 22px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.7rem;
        font-weight: 700;
        transition: all 0.3s;
    }
    
    .sidebar.collapsed .badge {
        position: absolute;
        top: 8px;
        right: 8px;
        width: 18px;
        height: 18px;
        font-size: 0.6rem;
    }
    
    .sidebar-footer {
        padding: 20px;
        border-top: 1px solid rgba(255, 255, 255, 0.1);
        background: rgba(255, 255, 255, 0.05);
    }
    
    .user-info {
        display: flex;
        align-items: center;
        color: rgba(255, 255, 255, 0.9);
        transition: all 0.3s;
    }
    
    .sidebar.collapsed .user-info {
        justify-content: center;
    }
    
    .user-info i {
        margin-right: 12px;
        font-size: 1.4rem;
        transition: all 0.3s;
        color: var(--dashen-accent);
    }
    
    .sidebar.collapsed .user-info i {
        margin-right: 0;
        font-size: 1.6rem;
    }
    
    .user-details {
        flex-grow: 1;
        transition: all 0.3s;
        overflow: hidden;
    }
    
    .sidebar.collapsed .user-details {
        opacity: 0;
        width: 0;
        height: 0;
        overflow: hidden;
    }
    
    .user-name {
        font-weight: 600;
        font-size: 0.9rem;
        white-space: nowrap;
    }
    
    .user-role {
        font-size: 0.75rem;
        color: var(--dashen-accent);
        margin-top: 2px;
        font-weight: 500;
        white-space: nowrap;
    }
    
    .sidebar-toggle-btn {
        position: absolute;
        top: 25px;
        right: -15px;
        width: 30px;
        height: 30px;
        border-radius: 50%;
        background: var(--dashen-primary);
        color: white;
        border: 2px solid white;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        z-index: 1001;
        box-shadow: 0 3px 10px rgba(0, 0, 0, 0.2);
        transition: all 0.3s;
        font-size: 0.9rem;
    }
    
    .sidebar-toggle-btn:hover {
        background: var(--dashen-accent);
        transform: scale(1.1);
    }
    
    .sidebar.collapsed .sidebar-toggle-btn i {
        transform: rotate(180deg);
    }
    
    /* Main Content Adjustment - EXACTLY like issues.php */
    .main-content {
        margin-left: 280px;
        width: calc(100% - 280px);
        padding: 30px;
        min-height: 100vh;
        transition: margin-left 0.3s cubic-bezier(0.25, 0.8, 0.25, 1), width 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
    }
    
    .main-content.expanded {
        margin-left: 80px;
        width: calc(100% - 80px);
    }
    
    /* Mobile responsive */
    @media (max-width: 1200px) {
        .main-content {
            margin-left: 80px;
            width: calc(100% - 80px);
        }
        
        .main-content.expanded {
            margin-left: 80px;
            width: calc(100% - 80px);
        }
    }
    
    @media (max-width: 992px) {
        .sidebar {
            transform: translateX(-100%);
            width: 280px !important;
        }
        
        .sidebar.active {
            transform: translateX(0);
        }
        
        .sidebar.collapsed {
            width: 280px !important;
        }
        
        .sidebar.collapsed .sidebar-logo img {
            max-width: 120px;
        }
        
        .sidebar.collapsed .sidebar-title {
            opacity: 1;
            height: auto;
            overflow: visible;
        }
        
        .sidebar.collapsed .sidebar-menu a span {
            opacity: 1;
            width: auto;
            height: auto;
            overflow: visible;
        }
        
        .sidebar.collapsed .sidebar-menu a i {
            margin-right: 15px;
            font-size: 1.2rem;
        }
        
        .sidebar.collapsed .badge {
            position: static;
            margin-left: auto;
            width: auto;
            height: 22px;
            font-size: 0.7rem;
        }
        
        .sidebar.collapsed .user-info {
            justify-content: flex-start;
        }
        
        .sidebar.collapsed .user-info i {
            margin-right: 12px;
            font-size: 1.4rem;
        }
        
        .sidebar.collapsed .user-details {
            opacity: 1;
            width: auto;
            height: auto;
            overflow: visible;
        }
        
        .main-content {
            margin-left: 0;
            width: 100%;
            padding: 20px 15px;
        }
        
        .main-content.expanded {
            margin-left: 0;
            width: 100%;
        }
        
        .sidebar-toggle-btn {
            display: none;
        }
    }
    
    .mobile-sidebar-toggle {
        display: none;
        position: fixed;
        top: 20px;
        left: 20px;
        z-index: 1100;
        background: var(--dashen-primary);
        color: white;
        border: none;
        width: 45px;
        height: 45px;
        border-radius: 12px;
        font-size: 1.3rem;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        transition: all 0.3s;
        cursor: pointer;
    }
    
    .mobile-sidebar-toggle:hover {
        background: var(--dashen-accent);
        transform: scale(1.05);
    }
    
    @media (max-width: 992px) {
        .mobile-sidebar-toggle {
            display: flex;
            align-items: center;
            justify-content: center;
        }
    }
    
    /* Overlay for mobile */
    .sidebar-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.5);
        z-index: 999;
        backdrop-filter: blur(3px);
        transition: all 0.3s;
    }
    
    .sidebar-overlay.active {
        display: block;
    }
    
    @media (max-width: 992px) {
        .sidebar-overlay.active {
            display: block;
        }
    }
    
    /* Custom scrollbar for sidebar */
    .sidebar-menu::-webkit-scrollbar {
        width: 4px;
    }
    
    .sidebar-menu::-webkit-scrollbar-track {
        background: rgba(255, 255, 255, 0.1);
    }
    
    .sidebar-menu::-webkit-scrollbar-thumb {
        background: rgba(255, 255, 255, 0.3);
        border-radius: 2px;
    }
    
    .sidebar-menu::-webkit-scrollbar-thumb:hover {
        background: rgba(255, 255, 255, 0.5);
    }
    
    /* Ensure body has no overflow issues */
    body {
        overflow-x: hidden;
        position: relative;
        min-height: 100vh;
    }
</style>

<!-- Mobile Toggle Button -->
<button class="mobile-sidebar-toggle" id="mobileSidebarToggle">
    <i class="fas fa-bars"></i>
</button>

<!-- Sidebar Overlay for Mobile -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- Sidebar Navigation -->
<nav id="sidebar" class="sidebar">
    <!-- Sidebar Toggle Button for Desktop -->
    <button class="sidebar-toggle-btn" id="sidebarToggleBtn">
        <i class="fas fa-chevron-left"></i>
    </button>
    
    <div class="sidebar-header">
        <!-- Dashen Bank Logo -->
        <div class="sidebar-logo">
            <img src="../Images/DashenLogo12.png" alt="Dashen Bank Logo">
        </div>
        <h3 class="sidebar-title">Issue Tracker</h3>
    </div>
    
    <div class="sidebar-menu">
        <ul class="list-unstyled">
            <?php foreach ($menu_items as $item): ?>
            <li>
                <a href="<?php echo $item['link']; ?>" class="<?php echo $item['active'] ? 'active' : ''; ?>">
                    <i class="<?php echo $item['icon']; ?>"></i> 
                    <span><?php echo $item['title']; ?></span>
                    <?php if ($item['title'] === 'Notifications' && $unread_count > 0): ?>
                        <span class="badge"><?php echo $unread_count; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    
    <div class="sidebar-footer">
        <div class="user-info">
            <i class="fas fa-user-circle"></i>
            <div class="user-details">
                <div class="user-name"><?php echo htmlspecialchars($current_user_name); ?></div>
                <div class="user-role"><?php echo htmlspecialchars($current_user_role); ?></div>
            </div>
        </div>
    </div>
</nav>

<!-- Sidebar JavaScript - FIXED -->
<script>
    // Wait for DOM to load
    document.addEventListener('DOMContentLoaded', function() {
        const sidebarToggleBtn = document.getElementById('sidebarToggleBtn');
        const mainContent = document.getElementById('mainContent');
        const sidebar = document.getElementById('sidebar');
        const mobileToggle = document.getElementById('mobileSidebarToggle');
        const overlay = document.getElementById('sidebarOverlay');
        
        // Toggle sidebar collapse/expand - FIXED
        if (sidebarToggleBtn && mainContent && sidebar) {
            sidebarToggleBtn.addEventListener('click', function() {
                sidebar.classList.toggle('collapsed');
                mainContent.classList.toggle('expanded');
                
                // Update toggle button icon
                const icon = this.querySelector('i');
                if (sidebar.classList.contains('collapsed')) {
                    icon.className = 'fas fa-chevron-right';
                } else {
                    icon.className = 'fas fa-chevron-left';
                }
                
                // Save preference to localStorage
                localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
            });
        }

        // Load saved preference
        function loadSidebarPreference() {
            if (!sidebar || !mainContent || !sidebarToggleBtn) return;
            
            const savedState = localStorage.getItem('sidebarCollapsed');
            
            // Check screen size first
            if (window.innerWidth <= 1200) {
                // On smaller screens, always collapse
                sidebar.classList.add('collapsed');
                mainContent.classList.add('expanded');
                if (sidebarToggleBtn) {
                    sidebarToggleBtn.querySelector('i').className = 'fas fa-chevron-right';
                }
            } else if (savedState !== null) {
                // Apply saved preference on larger screens
                if (savedState === 'true') {
                    sidebar.classList.add('collapsed');
                    mainContent.classList.add('expanded');
                    sidebarToggleBtn.querySelector('i').className = 'fas fa-chevron-right';
                } else {
                    sidebar.classList.remove('collapsed');
                    mainContent.classList.remove('expanded');
                    sidebarToggleBtn.querySelector('i').className = 'fas fa-chevron-left';
                }
            }
        }

        // Check screen size function - FIXED
        function checkScreenSize() {
            if (!sidebar || !mainContent) return;
            
            if (window.innerWidth <= 1200) {
                // Force collapsed on smaller screens
                sidebar.classList.add('collapsed');
                mainContent.classList.add('expanded');
                if (sidebarToggleBtn) {
                    sidebarToggleBtn.querySelector('i').className = 'fas fa-chevron-right';
                }
            } else {
                // On larger screens, respect saved preference
                const savedState = localStorage.getItem('sidebarCollapsed');
                if (savedState === 'true') {
                    sidebar.classList.add('collapsed');
                    mainContent.classList.add('expanded');
                    if (sidebarToggleBtn) {
                        sidebarToggleBtn.querySelector('i').className = 'fas fa-chevron-right';
                    }
                } else if (savedState === 'false') {
                    sidebar.classList.remove('collapsed');
                    mainContent.classList.remove('expanded');
                    if (sidebarToggleBtn) {
                        sidebarToggleBtn.querySelector('i').className = 'fas fa-chevron-left';
                    }
                }
            }
        }
        
        // Run on load
        window.addEventListener('load', loadSidebarPreference);
        window.addEventListener('resize', checkScreenSize);

        // Mobile toggle functionality
        if (mobileToggle && sidebar && overlay) {
            mobileToggle.addEventListener('click', function() {
                sidebar.classList.toggle('active');
                overlay.classList.toggle('active');
                
                if (sidebar.classList.contains('active')) {
                    document.body.style.overflow = 'hidden';
                } else {
                    document.body.style.overflow = '';
                }
            });
        }

        // Close sidebar when clicking overlay
        if (overlay && sidebar) {
            overlay.addEventListener('click', function() {
                sidebar.classList.remove('active');
                overlay.classList.remove('active');
                document.body.style.overflow = '';
            });
        }

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            if (window.innerWidth <= 992 && sidebar && mobileToggle) {
                const isClickInside = sidebar.contains(event.target);
                const isClickOnToggle = mobileToggle.contains(event.target);
                
                if (!isClickInside && !isClickOnToggle && sidebar.classList.contains('active')) {
                    sidebar.classList.remove('active');
                    if (overlay) overlay.classList.remove('active');
                    document.body.style.overflow = '';
                }
            }
        });

        // Auto-close sidebar when navigating on mobile
        if (sidebar) {
            document.querySelectorAll('.sidebar-menu a').forEach(link => {
                link.addEventListener('click', function() {
                    if (window.innerWidth <= 992) {
                        sidebar.classList.remove('active');
                        if (overlay) overlay.classList.remove('active');
                        document.body.style.overflow = '';
                    }
                });
            });
        }

        // Escape key to close mobile menu
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && window.innerWidth <= 992 && sidebar && sidebar.classList.contains('active')) {
                sidebar.classList.remove('active');
                if (overlay) overlay.classList.remove('active');
                document.body.style.overflow = '';
            }
        });
    });
</script>