<?php
// Check which path works
$config_path = file_exists('../config/functions.php') ? '../config/functions.php' : 'config/functions.php';
require_once $config_path;

checkAuth();

$username = $_SESSION['username'] ?? 'User';
$user_email = $_SESSION['email'] ?? '';
$role = $_SESSION['system_role'] ?? 'User';
$initials = strtoupper(substr($username, 0, 2));

// Get profile picture from session (updated from unified dashboard)
$profile_picture = $_SESSION['profile_picture'] ?? null;
$profile_image = $profile_picture ? "../uploads/profile/" . $profile_picture : null;

// Also try to get from user data if session not set
if (!$profile_picture) {
    $conn = getDBConnection();
    $stmt = mysqli_prepare($conn, "SELECT profile_picture FROM users WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $_SESSION['user_id']);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if ($row = mysqli_fetch_assoc($result)) {
        $profile_picture = $row['profile_picture'];
        $profile_image = $profile_picture ? "../uploads/profile/" . $profile_picture : null;
        $_SESSION['profile_picture'] = $profile_picture; // Update session
    }
}

// Page titles mapping
$pageTitles = [
    'dashboard.php' => 'Dashboard Overview',
    'events.php' => 'Event Management',
    'attendees.php' => 'Attendee Management',
    'tasks.php' => 'Task Management',
    'resources.php' => 'Resource Management',
    'reports.php' => 'Reports & Analytics',
    'users.php' => 'User Management',
    'projects.php' => 'Project Management',
    'profile.php' => 'My Profile',
    'settings.php' => 'System Settings',
    'notifications.php' => 'Notifications'
];

$currentPage = basename($_SERVER['PHP_SELF']);
$pageTitle = $pageTitles[$currentPage] ?? 'Dashen Bank PEMS';

// Get custom colors from session - exactly like main dashboard
$custom_colors = $_SESSION['custom_colors'] ?? [
    'primary' => '#273274',
    'secondary' => '#3c4c9e',
    'accent' => '#fff'
];

// Dark mode check
$dark_mode = $_SESSION['dark_mode'] ?? false;
?>

<!-- Modern Header - Styled exactly like main dashboard -->
<style>
    :root {
        --dashen-primary: <?php echo $custom_colors['primary']; ?>;
        --dashen-secondary: <?php echo $custom_colors['secondary']; ?>;
        --dashen-accent: <?php echo $custom_colors['accent']; ?>;
        --dashen-white: #ffffff;
        --dashen-dark: #202124;
        --dashen-gray: #5f6368;
        --shadow-sm: 0 1px 2px rgba(0,0,0,0.05);
        --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.1);
        --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.1);
        --shadow-xl: 0 20px 25px -5px rgba(0,0,0,0.1);
        --transition: all 0.2s ease;
        --header-height: 80px;
        --border-radius: 12px;
        --sidebar-width: 280px;
        --sidebar-collapsed-width: 80px;
    }

    /* Dark Mode Variables */
    body.dark-mode {
        --dashen-white: #2d2d2d;
        --dashen-dark: #e0e0e0;
        --dashen-gray: #b0b0b0;
        --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.3);
    }

    /* Header Container - Exactly matches main dashboard header */
    .main-header {
        background: linear-gradient(135deg, var(--dashen-primary) 0%, var(--dashen-secondary) 100%);
        padding: 0 30px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-bottom: 1px solid rgba(255,255,255,0.1);
        position: fixed;
        top: 0;
        right: 0;
        left: var(--sidebar-width);
        height: var(--header-height);
        z-index: 999;
        transition: var(--transition);
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    }

    /* When sidebar is collapsed */
    .main-header.expanded {
        left: var(--sidebar-collapsed-width);
    }

    /* Header Left Section */
    .header-left {
        animation: slideInUp 0.5s ease-out forwards;
        opacity: 0;
        animation-delay: 0.1s;
    }

    .header-left h1 {
        color: white;
        font-weight: 600;
        font-size: 1.5rem;
        margin: 0 0 4px 0;
        letter-spacing: -0.5px;
    }

    .header-left p {
        color: rgba(255,255,255,0.8);
        font-size: 0.85rem;
        margin: 0;
    }

    /* Header Right Section */
    .header-right {
        display: flex;
        align-items: center;
        gap: 20px;
        animation: slideInUp 0.5s ease-out forwards;
        opacity: 0;
        animation-delay: 0.2s;
    }

    /* Search Box - Matches dashboard style */
    .search-box {
        position: relative;
        width: 300px;
    }

    .search-box i {
        position: absolute;
        left: 15px;
        top: 50%;
        transform: translateY(-50%);
        color: rgba(255,255,255,0.7);
        font-size: 1rem;
        transition: var(--transition);
        z-index: 1;
    }

    .search-box input {
        width: 100%;
        padding: 12px 20px 12px 45px;
        border: 2px solid rgba(255,255,255,0.2);
        border-radius: 30px;
        background: rgba(255,255,255,0.15);
        color: white;
        font-size: 0.95rem;
        transition: var(--transition);
    }

    .search-box input::placeholder {
        color: rgba(255,255,255,0.6);
    }

    .search-box input:focus {
        outline: none;
        border-color: rgba(255,255,255,0.5);
        background: rgba(255,255,255,0.2);
        box-shadow: 0 0 0 4px rgba(255,255,255,0.1);
    }

    .search-box input:focus + i {
        color: white;
    }

    /* Notification Button */
    .notification-btn {
        position: relative;
        width: 45px;
        height: 45px;
        border-radius: 50%;
        background: rgba(255,255,255,0.15);
        border: 2px solid rgba(255,255,255,0.3);
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: var(--transition);
        font-size: 1.2rem;
    }

    .notification-btn:hover {
        background: rgba(255,255,255,0.25);
        transform: scale(1.05);
    }

    .notification-badge {
        position: absolute;
        top: -5px;
        right: -5px;
        background: #ff5252;
        color: white;
        font-size: 0.7rem;
        font-weight: 600;
        min-width: 20px;
        height: 20px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        border: 2px solid var(--dashen-primary);
    }

    /* User Menu - Matches dashboard profile trigger exactly */
    .profile-dropdown {
        position: relative;
    }

    .profile-trigger {
        width: 45px;
        height: 45px;
        border-radius: 50%;
        background: rgba(255,255,255,0.2);
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: var(--transition);
        border: 2px solid rgba(255,255,255,0.5);
        overflow: hidden;
        box-shadow: var(--shadow-md);
    }

    .profile-trigger:hover {
        transform: scale(1.1);
        box-shadow: var(--shadow-lg);
        border-color: white;
        background: rgba(255,255,255,0.3);
    }

    .profile-trigger i {
        font-size: 26px;
        color: white;
    }

    .profile-trigger img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    /* User Dropdown Menu - Exactly matches dashboard dropdown */
    .profile-dropdown-menu {
        position: absolute;
        top: 55px;
        right: 0;
        width: 320px;
        background: white;
        border-radius: 16px;
        box-shadow: 0 8px 30px rgba(0,0,0,0.2);
        padding: 0;
        margin-top: 8px;
        display: none;
        z-index: 1000;
        border: 1px solid #e8eaed;
        overflow: hidden;
    }

    body.dark-mode .profile-dropdown-menu {
        background: #2d2d2d;
        border-color: #404040;
    }

    .profile-dropdown-menu.show {
        display: block;
        animation: chromeSlideDown 0.2s ease;
    }

    @keyframes chromeSlideDown {
        from {
            opacity: 0;
            transform: translateY(-8px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .profile-menu-header {
        padding: 20px;
        background: linear-gradient(135deg, var(--dashen-primary), var(--dashen-secondary));
        color: white;
        display: flex;
        align-items: center;
        gap: 16px;
    }

    .profile-menu-avatar {
        width: 60px;
        height: 60px;
        border-radius: 50%;
        background: rgba(255,255,255,0.2);
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        border: 2px solid white;
        box-shadow: var(--shadow-md);
    }

    .profile-menu-avatar i {
        font-size: 36px;
        color: white;
    }

    .profile-menu-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .profile-menu-info {
        flex: 1;
    }

    .profile-menu-info h4 {
        font-weight: 600;
        margin: 0 0 4px 0;
        color: white;
        font-size: 16px;
    }

    .profile-menu-info p {
        margin: 0;
        color: rgba(255,255,255,0.9);
        font-size: 13px;
    }

    .profile-menu-body {
        padding: 8px 0;
    }

    .profile-menu-item {
        padding: 12px 20px;
        display: flex;
        align-items: center;
        gap: 16px;
        color: #202124;
        text-decoration: none;
        transition: background 0.1s ease;
        cursor: pointer;
        border: none;
        background: none;
        width: 100%;
        text-align: left;
        font-size: 14px;
        font-weight: 400;
    }

    body.dark-mode .profile-menu-item {
        color: #e0e0e0;
    }

    .profile-menu-item:hover {
        background: #f1f3f4;
    }

    body.dark-mode .profile-menu-item:hover {
        background: #3a3a3a;
    }

    .profile-menu-item i {
        width: 20px;
        color: var(--dashen-primary);
        font-size: 16px;
    }

    .profile-menu-item span {
        flex: 1;
    }

    .profile-menu-item .badge {
        background: #ff5252;
        color: white;
        padding: 2px 8px;
        border-radius: 12px;
        font-size: 0.7rem;
        margin-left: auto;
    }

    .profile-menu-item.logout {
        color: #d93025;
    }

    .profile-menu-item.logout i {
        color: #d93025;
    }

    .profile-menu-divider {
        height: 1px;
        background: #e8eaed;
        margin: 8px 0;
    }

    body.dark-mode .profile-menu-divider {
        background: #404040;
    }

    .profile-menu-footer {
        padding: 8px 0;
        border-top: 1px solid #e8eaed;
        background: #f8f9fa;
    }

    body.dark-mode .profile-menu-footer {
        background: #333333;
        border-top-color: #404040;
    }

    /* Dark Mode Toggle in Dropdown - Exactly matches dashboard */
    .dark-mode-toggle {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 12px 20px;
        cursor: pointer;
    }

    .dark-mode-toggle .toggle-switch {
        width: 44px;
        height: 22px;
        background: #e0e0e0;
        border-radius: 22px;
        position: relative;
        transition: var(--transition);
    }

    body.dark-mode .dark-mode-toggle .toggle-switch {
        background: var(--dashen-primary);
    }

    .dark-mode-toggle .toggle-switch::after {
        content: '';
        position: absolute;
        width: 18px;
        height: 18px;
        background: white;
        border-radius: 50%;
        top: 2px;
        left: 2px;
        transition: var(--transition);
        box-shadow: 0 1px 3px rgba(0,0,0,0.2);
    }

    body.dark-mode .dark-mode-toggle .toggle-switch::after {
        left: 24px;
    }

    /* Toast Notification */
    .toast-container {
        position: fixed;
        top: 100px;
        right: 30px;
        z-index: 99999;
    }

    .toast {
        background: white;
        border-radius: 12px;
        box-shadow: 0 8px 30px rgba(0,0,0,0.3);
        padding: 16px 20px;
        margin-bottom: 12px;
        display: flex;
        align-items: center;
        gap: 15px;
        min-width: 380px;
        max-width: 420px;
        animation: slideInRight 0.3s ease;
        border: 1px solid #e8eaed;
        position: relative;
        z-index: 100000;
        pointer-events: auto;
    }

    body.dark-mode .toast {
        background: #2d2d2d;
        border-color: #404040;
    }

    .toast-success {
        border-left: 6px solid #137333;
    }

    .toast-success i {
        color: #137333;
        font-size: 24px;
    }

    .toast-error {
        border-left: 6px solid #c5221f;
    }

    .toast-error i {
        color: #c5221f;
        font-size: 24px;
    }

    .toast-info {
        border-left: 6px solid #1a73e8;
    }

    .toast-info i {
        color: #1a73e8;
        font-size: 24px;
    }

    .toast-content {
        flex: 1;
    }

    .toast-content h5 {
        margin: 0 0 5px 0;
        font-weight: 600;
        font-size: 1rem;
        color: #202124;
    }

    body.dark-mode .toast-content h5 {
        color: #e0e0e0;
    }

    .toast-content p {
        margin: 0;
        color: #5f6368;
        font-size: 0.9rem;
        line-height: 1.4;
    }

    body.dark-mode .toast-content p {
        color: #b0b0b0;
    }

    @keyframes slideInRight {
        from {
            transform: translateX(100%);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }

    @keyframes fadeOut {
        from {
            opacity: 1;
        }
        to {
            opacity: 0;
        }
    }

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

    /* Mobile Responsive */
    @media (max-width: 992px) {
        .main-header {
            left: 0 !important;
        }

        .search-box {
            width: 200px;
        }
    }

    @media (max-width: 768px) {
        .main-header {
            padding: 0 20px;
        }

        .header-left h1 {
            font-size: 1.2rem;
        }

        .header-left p {
            display: none;
        }

        .search-box {
            display: none;
        }

        .profile-dropdown-menu {
            width: 300px;
            right: 10px;
        }

        .toast {
            min-width: 300px;
            max-width: 320px;
            right: 10px;
        }
    }
</style>

<!-- Main Header -->
<header class="main-header <?php echo isset($_SESSION['sidebar_collapsed']) && $_SESSION['sidebar_collapsed'] ? 'expanded' : ''; ?>" id="mainHeader">
    <div class="header-left">
        <h1><?php echo $pageTitle; ?></h1>
        <p>Project Event Management System</p>
    </div>
    
    <div class="header-right">
        <!-- Search Box -->
        <div class="search-box">
            <i class="fas fa-search"></i>
            <input type="text" placeholder="Search events, tasks, users..." id="globalSearch">
        </div>
        
        <!-- Notification Button -->
        <div class="notification-btn" id="notificationBtn">
            <i class="fas fa-bell"></i>
            <span class="notification-badge">3</span>
        </div>
        
        <!-- Profile Dropdown - Exactly like main dashboard -->
        <div class="profile-dropdown">
            <div class="profile-trigger" onclick="toggleProfileDropdown()">
                <?php if ($profile_image && file_exists($profile_image)): ?>
                    <img src="<?php echo $profile_image; ?>?v=<?php echo time(); ?>" alt="Profile" id="headerProfileImage">
                <?php else: ?>
                    <i class="fas fa-user-circle"></i>
                <?php endif; ?>
            </div>

            <div class="profile-dropdown-menu" id="profileDropdown">
                <div class="profile-menu-header">
                    <div class="profile-menu-avatar">
                        <?php if ($profile_image && file_exists($profile_image)): ?>
                            <img src="<?php echo $profile_image; ?>?v=<?php echo time(); ?>" alt="Profile" id="dropdownProfileImage">
                        <?php else: ?>
                            <i class="fas fa-user-circle"></i>
                        <?php endif; ?>
                    </div>
                    <div class="profile-menu-info">
                        <h4><?php echo htmlspecialchars($username); ?></h4>
                        <p><?php echo htmlspecialchars($user_email); ?></p>
                    </div>
                </div>

                <div class="profile-menu-body">
                    <button class="profile-menu-item" onclick="window.location.href='profile.php'">
                        <i class="fas fa-user"></i>
                        <span>Your profile</span>
                    </button>

                    <button class="profile-menu-item" onclick="window.location.href='settings.php'">
                        <i class="fas fa-cog"></i>
                        <span>Settings</span>
                    </button>

                    <button class="profile-menu-item" onclick="window.location.href='notifications.php'">
                        <i class="fas fa-bell"></i>
                        <span>Notifications</span>
                        <span class="badge">3</span>
                    </button>

                    <!-- Dark Mode Toggle -->
                    <div class="dark-mode-toggle profile-menu-item" id="darkModeToggleBtn">
                        <div style="display: flex; align-items: center; gap: 16px;">
                            <i class="fas <?php echo $dark_mode ? 'fa-sun' : 'fa-moon'; ?>" id="darkModeIcon"></i>
                            <span>Dark Mode</span>
                        </div>
                        <div class="toggle-switch" id="darkModeSwitch"></div>
                    </div>
                </div>

                <div class="profile-menu-footer">
                    <button class="profile-menu-item logout" onclick="window.location.href='../logout.php'">
                        <i class="fas fa-sign-out-alt"></i>
                        <span>Sign out</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</header>

<!-- Toast Container -->
<div class="toast-container" id="toastContainer"></div>

<script>
// Profile Dropdown Toggle
function toggleProfileDropdown() {
    document.getElementById('profileDropdown').classList.toggle('show');
}

// Close dropdown when clicking outside
document.addEventListener('click', function(event) {
    const dropdown = document.getElementById('profileDropdown');
    const trigger = document.querySelector('.profile-trigger');
    
    if (dropdown && trigger && !trigger.contains(event.target) && !dropdown.contains(event.target)) {
        dropdown.classList.remove('show');
    }
});

// Function to update profile picture across all modules
function updateProfilePicture(imageUrl) {
    const headerImage = document.getElementById('headerProfileImage');
    const dropdownImage = document.getElementById('dropdownProfileImage');
    
    if (headerImage) {
        if (headerImage.tagName === 'IMG') {
            headerImage.src = imageUrl + '?v=' + new Date().getTime();
        } else {
            // Replace icon with image
            const trigger = document.querySelector('.profile-trigger');
            if (trigger) {
                trigger.innerHTML = `<img src="${imageUrl}?v=${new Date().getTime()}" alt="Profile" id="headerProfileImage">`;
            }
        }
    }
    
    if (dropdownImage) {
        if (dropdownImage.tagName === 'IMG') {
            dropdownImage.src = imageUrl + '?v=' + new Date().getTime();
        } else {
            const avatar = document.querySelector('.profile-menu-avatar');
            if (avatar) {
                avatar.innerHTML = `<img src="${imageUrl}?v=${new Date().getTime()}" alt="Profile" id="dropdownProfileImage">`;
            }
        }
    }
}

// Function to check for profile picture updates
function checkProfileUpdate() {
    fetch('api/get_profile_picture.php')
        .then(response => response.json())
        .then(data => {
            if (data.success && data.profile_picture) {
                const currentSrc = document.getElementById('headerProfileImage')?.src || '';
                const newImageUrl = '../uploads/profile/' + data.profile_picture;
                
                if (!currentSrc.includes(newImageUrl)) {
                    updateProfilePicture(newImageUrl);
                }
            }
        })
        .catch(error => console.error('Error checking profile update:', error));
}

// Check for profile updates every 5 seconds
setInterval(checkProfileUpdate, 5000);

document.addEventListener('DOMContentLoaded', function() {
    const darkModeToggle = document.getElementById('darkModeToggleBtn');
    const darkModeSwitch = document.getElementById('darkModeSwitch');
    const darkModeIcon = document.getElementById('darkModeIcon');
    const notificationBtn = document.getElementById('notificationBtn');
    const globalSearch = document.getElementById('globalSearch');
    
    // Dark mode toggle functionality
    if (darkModeToggle) {
        darkModeToggle.addEventListener('click', function(e) {
            e.stopPropagation();
            const isDarkMode = document.body.classList.contains('dark-mode');
            
            if (isDarkMode) {
                document.body.classList.remove('dark-mode');
                localStorage.setItem('darkMode', 'disabled');
                darkModeIcon.className = 'fas fa-moon';
                showToast('Success', 'Light mode enabled', 'success');
            } else {
                document.body.classList.add('dark-mode');
                localStorage.setItem('darkMode', 'enabled');
                darkModeIcon.className = 'fas fa-sun';
                showToast('Success', 'Dark mode enabled', 'success');
            }
            
            // Update session via AJAX
            const formData = new FormData();
            formData.append('action', 'toggle_dark_mode');
            
            fetch('', {
                method: 'POST',
                body: formData
            }).catch(error => console.error('Error saving dark mode state:', error));
        });
    }
    
    // Check for saved dark mode preference
    if (localStorage.getItem('darkMode') === 'enabled') {
        document.body.classList.add('dark-mode');
        if (darkModeIcon) darkModeIcon.className = 'fas fa-sun';
    }
    
    // Notification button
    if (notificationBtn) {
        notificationBtn.addEventListener('click', function() {
            window.location.href = 'notifications.php';
        });
    }
    
    // Global search with debounce
    let searchTimeout;
    if (globalSearch) {
        globalSearch.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            const query = this.value.trim();
            
            if (query.length >= 2) {
                searchTimeout = setTimeout(() => {
                    showToast('Info', `Searching for "${query}"...`, 'info');
                }, 300);
            }
        });
        
        // Enter key to search
        globalSearch.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                const query = this.value.trim();
                if (query) {
                    showToast('Info', `Searching for "${query}"...`, 'info');
                }
            }
        });
    }
    
    // Handle sidebar toggle to adjust header position
    const sidebar = document.getElementById('sidebar');
    const sidebarToggle = document.getElementById('sidebarToggleBtn');
    const mainHeader = document.getElementById('mainHeader');
    
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function() {
            setTimeout(() => {
                if (sidebar.classList.contains('collapsed')) {
                    mainHeader.classList.add('expanded');
                } else {
                    mainHeader.classList.remove('expanded');
                }
            }, 50);
        });
    }
    
    // Handle window resize
    window.addEventListener('resize', function() {
        if (window.innerWidth <= 992) {
            mainHeader.classList.remove('expanded');
        } else {
            if (sidebar && sidebar.classList.contains('collapsed')) {
                mainHeader.classList.add('expanded');
            } else {
                mainHeader.classList.remove('expanded');
            }
        }
    });

    // Listen for profile update events from unified dashboard
    window.addEventListener('profileUpdated', function(e) {
        if (e.detail && e.detail.imageUrl) {
            updateProfilePicture(e.detail.imageUrl);
        }
    });
});

// Show Toast function - exactly like main dashboard
function showToast(title, message, type = 'info') {
    const toastContainer = document.getElementById('toastContainer');
    
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    
    let icon = 'fa-info-circle';
    if (type === 'success') icon = 'fa-check-circle';
    if (type === 'error') icon = 'fa-exclamation-circle';
    
    toast.innerHTML = `
        <i class="fas ${icon}"></i>
        <div class="toast-content">
            <h5>${title}</h5>
            <p>${message}</p>
        </div>
    `;
    
    toastContainer.appendChild(toast);
    
    // Auto remove after 3 seconds
    setTimeout(() => {
        if (toast && toast.parentNode) {
            toast.style.animation = 'fadeOut 0.3s ease';
            setTimeout(() => {
                if (toast && toast.parentNode) {
                    toast.remove();
                }
            }, 300);
        }
    }, 3000);
}

// Add animation to header elements
document.addEventListener('DOMContentLoaded', function() {
    const headerElements = document.querySelectorAll('.header-left, .header-right');
    headerElements.forEach((el, index) => {
        el.style.animationDelay = `${index * 0.1}s`;
    });

    // Initial profile picture check
    setTimeout(checkProfileUpdate, 1000);
});

// Function to manually trigger profile update from unified dashboard
window.triggerProfileUpdate = function(imagePath) {
    updateProfilePicture(imagePath);
};
</script>