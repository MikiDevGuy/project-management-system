<?php
include 'db.php';
if (!isset($_SESSION)) session_start();
$username = $_SESSION['username'] ?? 'Guest';
$role = $_SESSION['system_role'] ?? 'viewer';
$user_id = $_SESSION['user_id'] ?? null;

// Determine active dashboard based on current page
$current_page = basename($_SERVER['PHP_SELF']);

// Pages that belong to project management DASHBOARD
$pm_dashboard_pages = [
    'dashboard_project_manager.php', 'Phases.php', 'activities.php', 
    'sub_activities.php', 'project_report.php'
];

// Pages that belong to project management PROFILE/ADMIN
$pm_profile_pages = [
    'pm_admin_projects.php'
];

// Pages that belong to user management
$user_management_pages = [
    'display_user.php'
];

// Pages that belong to user assignment
$user_assignment_pages = [
    'user_assignment.php'
];

// Pages that belong to testcase management  
$tc_pages = [
    'dashboard_testcase.php', 'import_testcases.php', 'features.php',
    'TC_assigned_projects.php', 'Test_profile.php', 'testcase_management.php', 
    'admin_projects.php', 'Tc_reports.php'
];

// NEW: Module Assignment page
$module_assignment_pages = [
    'module_assignment.php'
];

// NEW: Consolidated Reports page
$consolidated_reports_pages = [
    'consolidated_reports.php'
];

// Set active dashboard
if ($current_page === 'dashboard.php') {
    $active_dashboard = 'none';
} elseif (in_array($current_page, $pm_dashboard_pages)) {
    $active_dashboard = 'pm_dashboard';
} elseif (in_array($current_page, $pm_profile_pages)) {
    $active_dashboard = 'pm_profile';
} elseif (in_array($current_page, $user_management_pages)) {
    $active_dashboard = 'user_management';
} elseif (in_array($current_page, $user_assignment_pages)) {
    $active_dashboard = 'user_assignment';
} elseif (in_array($current_page, $tc_pages)) {
    $active_dashboard = 'testcase_management';
} elseif (in_array($current_page, $module_assignment_pages)) {
    $active_dashboard = 'module_assignment';
} elseif (in_array($current_page, $consolidated_reports_pages)) {
    $active_dashboard = 'consolidated_reports';
} else {
    $active_dashboard = 'none';
}
?>
<!-- Sidebar Styles -->
<style>
:root {
  --sidebar-bg: #273274;
  --sidebar-accent: #3c4c9e;
  --sidebar-text: #fff;
  --sidebar-hover: #1a245a;
  --sidebar-header: #1a245a;
  --sidebar-width: 280px;
  --sidebar-collapsed-width: 80px;
}

.sidebar-container {
  position: fixed;
  left: 0;
  top: 0;
  height: 100vh;
  z-index: 1000;
  transition: all 0.3s ease;
  font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}

.sidebar {
  width: var(--sidebar-width);
  height: 100%;
  background: var(--sidebar-bg);
  color: var(--sidebar-text);
  overflow-y: auto;
  transition: all 0.3s ease;
  box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
}

.sidebar-collapsed .sidebar {
  width: var(--sidebar-collapsed-width);
  overflow: hidden;
}

.sidebar-logo {
  width: 120px;
  display: block;
  margin: 25px auto 10px auto;
}

.sidebar-header {
  padding: 20px 15px;
  background: var(--sidebar-header);
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-bottom: 1px solid rgba(255, 255, 255, 0.1);
}

.user-info {
  display: flex;
  flex-direction: column;
}

.username {
  font-weight: 600;
  font-size: 1.1rem;
  margin-bottom: 3px;
}

.user-role {
  font-size: 0.8rem;
  opacity: 0.8;
  background: var(--sidebar-accent);
  padding: 2px 8px;
  border-radius: 10px;
  align-self: flex-start;
}

.sidebar-menu {
  padding: 15px 0;
}

.menu-item {
  display: flex;
  align-items: center;
  padding: 12px 20px;
  color: var(--sidebar-text);
  text-decoration: none;
  transition: all 0.2s ease;
  border-left: 3px solid transparent;
}

.menu-item:hover {
  background: var(--sidebar-hover);
  border-left: 3px solid var(--sidebar-accent);
  color: white;
}

.menu-item.active {
  background: var(--sidebar-hover);
  border-left: 3px solid var(--sidebar-accent);
}

.menu-icon {
  margin-right: 15px;
  font-size: 1.1rem;
  width: 20px;
  text-align: center;
}

.menu-text {
  transition: opacity 0.3s ease;
}

.sidebar-collapsed .menu-text {
  opacity: 0;
  width: 0;
  display: none;
}

.sidebar-collapsed .username,
.sidebar-collapsed .user-role {
  display: none;
}

.sidebar-logo.sidebar-collapsed {
  display: none;
}

.sidebar-toggler {
  position: fixed;
  top: 20px;
  left: 20px;
  background: var(--sidebar-accent);
  color: white;
  border: none;
  width: 40px;
  height: 40px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  z-index: 1100;
  box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
  transition: all 0.3s ease;
}

.sidebar-collapsed .sidebar-toggler {
  left: calc(var(--sidebar-collapsed-width) + 20px);
}

.content {
  transition: margin-left 0.3s ease;
  margin-left: var(--sidebar-width);
  padding: 20px;
  min-height: 100vh;
}

.sidebar-collapsed ~ .content {
  margin-left: var(--sidebar-collapsed-width);
}

@media (max-width: 768px) {
  .sidebar {
    transform: translateX(-100%);
  }
  
  .sidebar-collapsed .sidebar {
    transform: translateX(0);
    width: var(--sidebar-width);
  }
  
  .sidebar-collapsed ~ .content {
    margin-left: 0;
  }
  
  .sidebar-toggler {
    left: 20px;
  }
  
  .sidebar-collapsed .sidebar-toggler {
    left: calc(var(--sidebar-width) - 30px);
  }
}

.sidebar::-webkit-scrollbar {
  width: 5px;
}

.sidebar::-webkit-scrollbar-track {
  background: rgba(255, 255, 255, 0.1);
}

.sidebar::-webkit-scrollbar-thumb {
  background: var(--sidebar-accent);
  border-radius: 10px;
}

/* Submenu Styles */
.menu-item.has-submenu {
  position: relative;
}

.submenu-icon {
  margin-left: auto;
  transition: transform 0.3s ease;
}

.menu-item.has-submenu.active .submenu-icon {
  transform: rotate(90deg);
}

.submenu {
  max-height: 0;
  overflow: hidden;
  transition: max-height 0.3s ease;
  background: rgba(0, 0, 0, 0.1);
}

.submenu.open {
  max-height: 500px;
}

.submenu-item {
  padding: 10px 20px 10px 50px;
  display: flex;
  align-items: center;
  color: var(--sidebar-text);
  text-decoration: none;
  transition: all 0.2s ease;
  border-left: 3px solid transparent;
}

.submenu-item:hover {
  background: rgba(255, 255, 255, 0.1);
  border-left: 3px solid var(--sidebar-accent);
}

.submenu-item.active {
  background: rgba(255, 255, 255, 0.1);
  border-left: 3px solid var(--sidebar-accent);
}

.submenu-icon-small {
  margin-right: 10px;
  font-size: 0.9rem;
  width: 16px;
  text-align: center;
}
</style>

<!-- Sidebar Structure -->
<div class="sidebar-container" id="sidebarContainer">
  <button class="sidebar-toggler" id="sidebarToggler">
    <i class="fas fa-bars"></i>
  </button>

  <div class="sidebar" id="sidebar">
    <!-- Dashen Bank Logo -->
    <img src="Images/DashenLogo12.png" alt="Dashen Bank Logo" class="sidebar-logo">

    <div class="sidebar-header">
      <div class="user-info">
        <span class="username"><?php echo htmlspecialchars($username); ?></span>
        <span class="user-role"><?php echo ucfirst($role); ?></span>
      </div>
    </div>

    <div class="sidebar-menu">
      <?php if ($active_dashboard === 'user_assignment'): ?>
        <!-- USER ASSIGNMENT MENU (user_assignment.php) - ONLY 3 ITEMS -->
        
        <a href="dashboard.php" class="menu-item <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>">
          <span class="menu-icon"><i class="fas fa-tachometer-alt"></i></span>
          <span class="menu-text">Unified Dashboard</span>
        </a>
        
        <?php if($role === 'super_admin' || $role === 'admin' || $role === 'pm_manager'): ?>
          <a href="user_assignment.php" class="menu-item <?php echo $current_page == 'user_assignment.php' ? 'active' : ''; ?>">
            <span class="menu-icon"><i class="fas fa-user-check"></i></span>
            <span class="menu-text">User Assignment</span>
          </a>
        <?php endif; ?>
        
        <a href="profile.php" class="menu-item <?php echo $current_page == 'profile.php' ? 'active' : ''; ?>">
          <span class="menu-icon"><i class="fas fa-user-cog"></i></span>
          <span class="menu-text">My Profile</span>
        </a>
      
      <?php elseif($active_dashboard === 'pm_dashboard'): ?>
        <!-- PM DASHBOARD MENU (dashboard_project_manager.php) -->
        
        <?php if($role == 'pm_employee' || $role == 'pm_manager' || $role == 'super_admin'): ?>
          <a href="dashboard_project_manager.php" class="menu-item <?php echo $current_page == 'dashboard_project_manager.php' ? 'active' : ''; ?>">
            <span class="menu-icon"><i class="fas fa-tachometer-alt"></i></span>
            <span class="menu-text">PM-Dashboard</span>
          </a>
        <?php endif; ?>
        
        <?php if($role === 'super_admin' || $role === 'admin' || $role === 'pm_manager' || $role === 'pm_employee'): ?>
          <a href="dashboard.php" class="menu-item <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>">
            <span class="menu-icon"><i class="fas fa-tachometer-alt"></i></span>
            <span class="menu-text">UNIFIED-Dashboard</span>
          </a>
        <?php endif; ?>
        
        <!-- Project Management Submenu -->
        <?php if ($role === 'super_admin' || $role === 'pm_manager' || $role === 'admin' ): ?>
          <div class="menu-item has-submenu <?php echo in_array($current_page, ['Phases.php', 'activities.php', 'sub_activities.php']) ? 'active' : ''; ?>" id="pmSubmenu">
            <span class="menu-icon"><i class="fas fa-project-diagram"></i></span>
            <span class="menu-text">Project Profile creation</span>
            <span class="submenu-icon"><i class="fas fa-chevron-right"></i></span>
          </div>
          <div class="submenu" id="pmSubmenuContent">
            <a href="Phases.php" class="submenu-item <?php echo $current_page == 'Phases.php' ? 'active' : ''; ?>">
              <span class="submenu-icon-small"><i class="fas fa-layer-group"></i></span>
              <span class="menu-text">Phases</span>
            </a>
            <a href="activities.php" class="submenu-item <?php echo $current_page == 'activities.php' ? 'active' : ''; ?>">
              <span class="submenu-icon-small"><i class="fas fa-tasks"></i></span>
              <span class="menu-text">Activities</span>
            </a>
            <a href="sub_activities.php" class="submenu-item <?php echo $current_page == 'sub_activities.php' ? 'active' : ''; ?>">
              <span class="submenu-icon-small"><i class="fas fa-list-ul"></i></span>
              <span class="menu-text">Sub Activities</span>
            </a>
          </div>
        <?php endif; ?>
        
        <?php if ($role === 'super_admin' || $role === 'pm_manager' || $role === 'admin'): ?>
          <a href="project_report.php" class="menu-item <?php echo $current_page == 'project_report.php' ? 'active' : ''; ?>">
            <span class="menu-icon"><i class="fas fa-chart-bar"></i></span>
            <span class="menu-text">Project Reports</span>
          </a>
        <?php endif; ?>
        
        <a href="profile.php" class="menu-item <?php echo $current_page == 'profile.php' ? 'active' : ''; ?>">
          <span class="menu-icon"><i class="fas fa-user-cog"></i></span>
          <span class="menu-text">My Profile</span>
        </a>
      
      <?php elseif($active_dashboard === 'pm_profile'): ?>
        <!-- PROJECT PROFILE MENU (pm_admin_projects.php) -->
        
        <a href="dashboard.php" class="menu-item <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>">
          <span class="menu-icon"><i class="fas fa-tachometer-alt"></i></span>
          <span class="menu-text">Unified Dashboard</span>
        </a>
        
        <?php if($role === 'super_admin' || $role === 'admin' || $role === 'pm_manager' || $role === 'Pm_employee'): ?>
          <a href="pm_admin_projects.php" class="menu-item <?php echo $current_page == 'pm_admin_projects.php' ? 'active' : ''; ?>">
            <span class="menu-icon"><i class="fas fa-tasks"></i></span>
            <span class="menu-text">Project Profile</span>
          </a>
        <?php endif; ?>
        
        <a href="profile.php" class="menu-item <?php echo $current_page == 'profile.php' ? 'active' : ''; ?>">
          <span class="menu-icon"><i class="fas fa-user-cog"></i></span>
          <span class="menu-text">My Profile</span>
        </a>
      
      <?php elseif($active_dashboard === 'user_management'): ?>
        <!-- USER MANAGEMENT MENU (display_user.php only) -->
        
        <a href="dashboard.php" class="menu-item <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>">
          <span class="menu-icon"><i class="fas fa-tachometer-alt"></i></span>
          <span class="menu-text">Unified Dashboard</span>
        </a>
        
        <?php if($role === 'super_admin' || $role === 'admin'): ?>
          <a href="display_user.php" class="menu-item <?php echo $current_page == 'display_user.php' ? 'active' : ''; ?>">
            <span class="menu-icon"><i class="fas fa-users-cog"></i></span>
            <span class="menu-text">Manage Users</span>
          </a>
        <?php endif; ?>
        
        <a href="profile.php" class="menu-item <?php echo $current_page == 'profile.php' ? 'active' : ''; ?>">
          <span class="menu-icon"><i class="fas fa-user-cog"></i></span>
          <span class="menu-text">My Profile</span>
        </a>
      
      <?php elseif($active_dashboard === 'testcase_management'): ?>
        <!-- TESTCASE MANAGEMENT MENU - COMPLETE VERSION -->
        
        <!-- TC Dashboard -->
        <?php if ($role === 'super_admin' || $role === 'tester'|| $role === 'pm_manager' || $role === 'test_viewer'): ?>
          <a href="dashboard_testcase.php" class="menu-item <?php echo $current_page == 'dashboard_testcase.php' ? 'active' : ''; ?>">
            <span class="menu-icon"><i class="fas fa-tachometer-alt"></i></span>
            <span class="menu-text">TC-Dashboard</span>
          </a>
        <?php endif; ?>
         
        <!-- Unified Dashboard -->
        <?php if($role === 'super_admin' || $role === 'tester'|| $role === 'pm_manager' || $role === 'test_viewer'): ?>
          <a href="dashboard.php" class="menu-item <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>">
            <span class="menu-icon"><i class="fas fa-tachometer-alt"></i></span>
            <span class="menu-text">UNIFIED Dashboard</span>
          </a>
        <?php endif; ?>
        
        <!-- Import Test Cases -->
        <?php if($role === 'super_admin'|| $role === 'tester' || $role === 'pm_manager'): ?>
          <a href="import_testcases.php" class="menu-item <?php echo $current_page == 'import_testcases.php' ? 'active' : ''; ?>">
            <span class="menu-icon"><i class="fas fa-file-import"></i></span>
            <span class="menu-text">Import Test cases</span>
          </a>
        <?php endif; ?>
       
        <!-- Features Management -->
        <?php if($role === 'tester' ||  $role === 'pm_manager'||  $role === 'super_admin'): ?>
          <a href="features.php" class="menu-item <?php echo $current_page == 'features.php' ? 'active' : ''; ?>">
            <span class="menu-icon"><i class="fas fa-plus-circle"></i></span>
            <span class="menu-text">Add Features</span>
          </a>
        <?php endif; ?>
        
        <!-- Test Case Management Submenu -->
        <?php if( $role === 'tester' || $role === 'pm_manager'|| $role === 'test_viewer' ): ?>
          <div class="menu-item has-submenu <?php echo in_array($current_page, ['testcase_management.php', 'TC_assigned_projects.php']) ? 'active' : ''; ?>" id="tcSubmenu">
            <span class="menu-icon"><i class="fas fa-list-check"></i></span>
            <span class="menu-text">Test Cases</span>
            <span class="submenu-icon"><i class="fas fa-chevron-right"></i></span>
          </div>
          <div class="submenu" id="tcSubmenuContent">
            <?php if($role === 'super_admin'): ?>
              <a href="testcase_management.php" class="submenu-item <?php echo $current_page == 'testcase_management.php' ? 'active' : ''; ?>">
                <span class="submenu-icon-small"><i class="fas fa-cogs"></i></span>
                <span class="menu-text">Manage Test Cases</span>
              </a>
            <?php endif; ?>
            
            <?php if($role === 'tester'|| $role === 'pm_manager'|| $role === 'pm_employee'): ?>
              <a href="TC_assigned_projects.php" class="submenu-item <?php echo $current_page == 'TC_assigned_projects.php' ? 'active' : ''; ?>">
                <span class="submenu-icon-small"><i class="fas fa-project-diagram"></i></span>
                <span class="menu-text">My Assigned Projects</span>
              </a>
            <?php endif; ?>
            
            <?php if ($role === 'test_viewer'): ?>
              <a href="TC_assigned_projects.php" class="submenu-item <?php echo $current_page == 'TC_assigned_projects.php' ? 'active' : ''; ?>">
                <span class="submenu-icon-small"><i class="fas fa-eye"></i></span>
                <span class="menu-text">View My Projects</span>
              </a>
            <?php endif; ?>
          </div>
        <?php endif; ?>
        
        <!-- Admin Projects -->
        <?php if($role === 'super_admin'): ?>
          <a href="admin_projects.php" class="menu-item <?php echo $current_page == 'admin_projects.php' ? 'active' : ''; ?>">
            <span class="menu-icon"><i class="fas fa-project-diagram"></i></span>
            <span class="menu-text">Project Test Case and features</span>
          </a>
        <?php endif; ?>
        <?php if($role === 'super_admin'|| $role === 'pm_manager'): ?>
        <a href="Tc_reports.php" class="menu-item <?php echo $current_page == 'Tc_reports.php' ? 'active' : ''; ?>">
            <span class="menu-icon"><i class="fas fa-project-diagram"></i></span>
            <span class="menu-text"> Test Case Tc_reports</span>
          </a>
          <?php endif; ?>
        
        <!-- Profile Links -->
        <?php if($role === 'tester' || $role === 'test_viewer' || $role === 'pm_manager' || $role === 'super_admin'): ?>
          <a href="Test_profile.php" class="menu-item <?php echo $current_page == 'Test_profile.php' ? 'active' : ''; ?>">
            <span class="menu-icon"><i class="fas fa-user-cog"></i></span>
            <span class="menu-text">My Profile</span>
          </a>
        <?php else: ?>
          <a href="profile.php" class="menu-item <?php echo $current_page == 'profile.php' ? 'active' : ''; ?>">
            <span class="menu-icon"><i class="fas fa-user-cog"></i></span>
            <span class="menu-text">My Profile</span>
          </a>
        <?php endif; ?>
        
      <?php elseif($active_dashboard === 'module_assignment'): ?>
        <!-- MODULE ASSIGNMENT MENU - CLEAN VERSION (only 4 items) -->
        
        <!-- Module Assignment (primary) -->
        <a href="module_assignment.php" class="menu-item <?php echo $current_page == 'module_assignment.php' ? 'active' : ''; ?>">
          <span class="menu-icon"><i class="fas fa-puzzle-piece"></i></span>
          <span class="menu-text">Module Assignment</span>
        </a>
        
        <!-- Unified Dashboard (secondary) -->
        <a href="dashboard.php" class="menu-item <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>">
          <span class="menu-icon"><i class="fas fa-tachometer-alt"></i></span>
          <span class="menu-text">Unified Dashboard</span>
        </a>
        
        <!-- My Profile -->
        <a href="profile.php" class="menu-item <?php echo $current_page == 'profile.php' ? 'active' : ''; ?>">
          <span class="menu-icon"><i class="fas fa-user-cog"></i></span>
          <span class="menu-text">My Profile</span>
        </a>
        
      <?php elseif($active_dashboard === 'consolidated_reports'): ?>
        <!-- CONSOLIDATED REPORTS MENU - CLEAN VERSION (only 4 items) -->
        
        <!-- Consolidated Reports (primary) -->
        <a href="consolidated_reports.php" class="menu-item <?php echo $current_page == 'consolidated_reports.php' ? 'active' : ''; ?>">
          <span class="menu-icon"><i class="fas fa-chart-pie"></i></span>
          <span class="menu-text">Consolidated Reports</span>
        </a>
        
        <!-- Unified Dashboard (secondary) -->
        <a href="dashboard.php" class="menu-item <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>">
          <span class="menu-icon"><i class="fas fa-tachometer-alt"></i></span>
          <span class="menu-text">Unified Dashboard</span>
        </a>
        
        <!-- My Profile -->
        <a href="profile.php" class="menu-item <?php echo $current_page == 'profile.php' ? 'active' : ''; ?>">
          <span class="menu-icon"><i class="fas fa-user-cog"></i></span>
          <span class="menu-text">My Profile</span>
        </a>
        
      <?php else: ?>
        <!-- DEFAULT MENU (for dashboard.php and other admin pages) -->
        <a href="dashboard.php" class="menu-item <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>">
          <span class="menu-icon"><i class="fas fa-tachometer-alt"></i></span>
          <span class="menu-text">Unified Dashboard</span>
        </a>
        
        <?php if ($role === 'super_admin' || $role === 'admin'): ?>
          <!-- Admin Submenu -->
          <div class="menu-item has-submenu <?php echo in_array($current_page, ['display_user.php', 'pm_admin_projects.php', 'user_assignment.php']) ? 'active' : ''; ?>" id="adminSubmenu">
            <span class="menu-icon"><i class="fas fa-user-shield"></i></span>
            <span class="menu-text">Administration</span>
            <span class="submenu-icon"><i class="fas fa-chevron-right"></i></span>
          </div>
          <div class="submenu" id="adminSubmenuContent">
            <a href="display_user.php" class="submenu-item <?php echo $current_page == 'display_user.php' ? 'active' : ''; ?>">
              <span class="submenu-icon-small"><i class="fas fa-users-cog"></i></span>
              <span class="menu-text">Manage Users</span>
            </a>
            
            <a href="pm_admin_projects.php" class="submenu-item <?php echo $current_page == 'pm_admin_projects.php' ? 'active' : ''; ?>">
              <span class="submenu-icon-small"><i class="fas fa-tasks"></i></span>
              <span class="menu-text">Project Profile</span>
            </a>
            
            <a href="user_assignment.php" class="submenu-item <?php echo $current_page == 'user_assignment.php' ? 'active' : ''; ?>">
              <span class="submenu-icon-small"><i class="fas fa-user-check"></i></span>
              <span class="menu-text">User Assignment</span>
            </a>
          </div>
        <?php endif; ?>
        
        <!-- Dashboard Submenu -->
        <div class="menu-item has-submenu <?php echo in_array($current_page, ['dashboard_project_manager.php', 'dashboard_testcase.php']) ? 'active' : ''; ?>" id="dashboardSubmenu">
          <span class="menu-icon"><i class="fas fa-tachometer-alt"></i></span>
          <span class="menu-text">Specialized Dashboards</span>
          <span class="submenu-icon"><i class="fas fa-chevron-right"></i></span>
        </div>
        <div class="submenu" id="dashboardSubmenuContent">
          <?php if($role === 'pm_employee' || $role === 'pm_manager' || $role === 'super_admin'): ?>
            <a href="dashboard_project_manager.php" class="submenu-item <?php echo $current_page == 'dashboard_project_manager.php' ? 'active' : ''; ?>">
              <span class="submenu-icon-small"><i class="fas fa-project-diagram"></i></span>
              <span class="menu-text">PM-Dashboard</span>
            </a>
          <?php endif; ?>
          
          <?php if($role === 'tester' || $role === 'test_viewer' || $role === 'super_admin' || $role === 'pm_manager'): ?>
            <a href="dashboard_testcase.php" class="submenu-item <?php echo $current_page == 'dashboard_testcase.php' ? 'active' : ''; ?>">
              <span class="submenu-icon-small"><i class="fas fa-list-check"></i></span>
              <span class="menu-text">TC-Dashboard</span>
            </a>
          <?php endif; ?>
        </div>
        
        <!-- Test Case Management -->
        <?php if($role === 'tester' || $role === 'test_viewer' || $role === 'pm_manager' || $role === 'super_admin'): ?>
          <div class="menu-item has-submenu <?php echo in_array($current_page, ['TC_assigned_projects.php', 'import_testcases.php', 'features.php']) ? 'active' : ''; ?>" id="tcMainSubmenu">
            <span class="menu-icon"><i class="fas fa-vial"></i></span>
            <span class="menu-text">Test Management</span>
            <span class="submenu-icon"><i class="fas fa-chevron-right"></i></span>
          </div>
          <div class="submenu" id="tcMainSubmenuContent">
            <?php if($role === 'tester'|| $role === 'pm_manager'|| $role === 'pm_employee'): ?>
              <a href="TC_assigned_projects.php" class="submenu-item <?php echo $current_page == 'TC_assigned_projects.php' ? 'active' : ''; ?>">
                <span class="submenu-icon-small"><i class="fas fa-project-diagram"></i></span>
                <span class="menu-text">My Projects</span>
              </a>
            <?php endif; ?>
            
            <?php if ($role === 'test_viewer'): ?>
              <a href="TC_assigned_projects.php" class="submenu-item <?php echo $current_page == 'TC_assigned_projects.php' ? 'active' : ''; ?>">
                <span class="submenu-icon-small"><i class="fas fa-eye"></i></span>
                <span class="menu-text">View My Projects</span>
              </a>
            <?php endif; ?>
            
            <?php if($role === 'tester' || $role === 'super_admin' || $role === 'pm_manager'): ?>
              <a href="import_testcases.php" class="submenu-item <?php echo $current_page == 'import_testcases.php' ? 'active' : ''; ?>">
                <span class="submenu-icon-small"><i class="fas fa-file-import"></i></span>
                <span class="menu-text">Import Test Cases</span>
              </a>
              
              <a href="features.php" class="submenu-item <?php echo $current_page == 'features.php' ? 'active' : ''; ?>">
                <span class="submenu-icon-small"><i class="fas fa-plus-circle"></i></span>
                <span class="menu-text">Add Features</span>
              </a>
            <?php endif; ?>
          </div>
        <?php endif; ?>
        
        <!-- Project Management -->
        <?php if($role === 'pm_employee' || $role === 'pm_manager' || $role === 'super_admin'): ?>
          <div class="menu-item has-submenu <?php echo in_array($current_page, ['Phases.php', 'activities.php', 'sub_activities.php', 'project_report.php']) ? 'active' : ''; ?>" id="pmMainSubmenu">
            <span class="menu-icon"><i class="fas fa-project-diagram"></i></span>
            <span class="menu-text">Project Management</span>
            <span class="submenu-icon"><i class="fas fa-chevron-right"></i></span>
          </div>
          <div class="submenu" id="pmMainSubmenuContent">
            <a href="Phases.php" class="submenu-item <?php echo $current_page == 'Phases.php' ? 'active' : ''; ?>">
              <span class="submenu-icon-small"><i class="fas fa-layer-group"></i></span>
              <span class="menu-text">Phases</span>
            </a>
            <a href="activities.php" class="submenu-item <?php echo $current_page == 'activities.php' ? 'active' : ''; ?>">
              <span class="submenu-icon-small"><i class="fas fa-tasks"></i></span>
              <span class="menu-text">Activities</span>
            </a>
            <a href="sub_activities.php" class="submenu-item <?php echo $current_page == 'sub_activities.php' ? 'active' : ''; ?>">
              <span class="submenu-icon-small"><i class="fas fa-list-ul"></i></span>
              <span class="menu-text">Sub Activities</span>
            </a>
            
            <?php if ($role === 'super_admin' || $role === 'pm_manager' || $role === 'admin'): ?>
              <a href="project_report.php" class="submenu-item <?php echo $current_page == 'project_report.php' ? 'active' : ''; ?>">
                <span class="submenu-icon-small"><i class="fas fa-chart-bar"></i></span>
                <span class="menu-text">Project Reports</span>
              </a>
            <?php endif; ?>
          </div>
        <?php endif; ?>
        
        <!-- Profile Link -->
        <a href="profile.php" class="menu-item <?php echo $current_page == 'profile.php' ? 'active' : ''; ?>">
          <span class="menu-icon"><i class="fas fa-user-cog"></i></span>
          <span class="menu-text">My Profile</span>
        </a>
      <?php endif; ?>
    </div>

    <!-- Always visible menu items - LOGOUT -->
    <div class="sidebar-menu">
      <a href="logout.php" class="menu-item">
        <span class="menu-icon"><i class="fas fa-sign-out-alt"></i></span>
        <span class="menu-text">Logout</span>
      </a>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const sidebarContainer = document.getElementById('sidebarContainer');
  const sidebarToggler = document.getElementById('sidebarToggler');
  
  // Toggle sidebar
  sidebarToggler.addEventListener('click', function() {
    sidebarContainer.classList.toggle('sidebar-collapsed');
    
    const isCollapsed = sidebarContainer.classList.contains('sidebar-collapsed');
    localStorage.setItem('sidebarCollapsed', isCollapsed);
  });
  
  // Load saved sidebar state
  if (localStorage.getItem('sidebarCollapsed') === 'true') {
    sidebarContainer.classList.add('sidebar-collapsed');
  }
  
  // Set active menu item based on current page
  const currentPage = window.location.pathname.split('/').pop() || 'dashboard.php';
  const menuItems = document.querySelectorAll('.menu-item');
  
  menuItems.forEach(item => {
    const link = item.getAttribute('href');
    if (currentPage === link) {
      item.classList.add('active');
    }
  });
  
  // Mobile menu behavior
  if (window.innerWidth <= 768) {
    menuItems.forEach(item => {
      item.addEventListener('click', () => {
        sidebarContainer.classList.remove('sidebar-collapsed');
        localStorage.setItem('sidebarCollapsed', 'false');
      });
    });
  }
  
  // Submenu functionality
  const submenuItems = document.querySelectorAll('.menu-item.has-submenu');
  submenuItems.forEach(item => {
    item.addEventListener('click', function(e) {
      if (!sidebarContainer.classList.contains('sidebar-collapsed')) {
        e.preventDefault();
        const submenuId = this.id.replace('Submenu', 'SubmenuContent');
        const submenu = document.getElementById(submenuId);
        
        if (submenu) {
          // Close other open submenus
          document.querySelectorAll('.submenu.open').forEach(openSubmenu => {
            if (openSubmenu.id !== submenuId) {
              openSubmenu.classList.remove('open');
              const parentItem = openSubmenu.previousElementSibling;
              if (parentItem && parentItem.classList.contains('has-submenu')) {
                parentItem.classList.remove('active');
              }
            }
          });
          
          // Toggle current submenu
          submenu.classList.toggle('open');
          this.classList.toggle('active');
        }
      }
    });
  });
  
  // Close submenus when clicking outside (for mobile)
  document.addEventListener('click', function(e) {
    if (window.innerWidth <= 768) {
      const isClickInsideSubmenu = e.target.closest('.sidebar-menu');
      if (!isClickInsideSubmenu) {
        document.querySelectorAll('.submenu.open').forEach(submenu => {
          submenu.classList.remove('open');
          const parentItem = submenu.previousElementSibling;
          if (parentItem && parentItem.classList.contains('has-submenu')) {
            parentItem.classList.remove('active');
          }
        });
      }
    }
  });
  
  // Auto-open submenu if current page is in submenu
  const activeSubmenuItem = document.querySelector('.submenu-item.active');
  if (activeSubmenuItem) {
    const submenu = activeSubmenuItem.closest('.submenu');
    if (submenu) {
      submenu.classList.add('open');
      const parentItem = submenu.previousElementSibling;
      if (parentItem && parentItem.classList.contains('has-submenu')) {
        parentItem.classList.add('active');
      }
    }
  }
});
</script>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">