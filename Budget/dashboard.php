<?php
/*******************************************************
 * Dashboard (Tabbed) — Budget Management System
 * -----------------------------------------------------
 * - Bootstrap 5 tabbed interface
 * - Department → Project → Month filters
 * - KPIs and 7+ charts (Chart.js 4.x)
 * - Aligned with your DB schema
 * - Auto-updating fiscal year (July-June)
 *******************************************************/

// Include database connection and header
require_once '../db.php';
require_once 'header.php';

// --- SETTINGS ---
date_default_timezone_set('UTC');
$current_year = (int)date('Y');
$current_month = (int)date('n');

// Calculate fiscal year based on July start (July 1 to June 30)
$fiscal_year_start = ($current_month >= 7) ? $current_year : $current_year - 1;
$fiscal_year_end = $fiscal_year_start + 1;
$fiscal_year_display = "FY {$fiscal_year_start}/{$fiscal_year_end}";
$fiscal_year_db = "$fiscal_year_start-$fiscal_year_end"; // Format for database

// Safe read helpers
function get_int_or_null($name) {
    if (!isset($_GET[$name]) || $_GET[$name] === '') return null;
    return (int)$_GET[$name];
}
function get_str_or_null($name) {
    if (!isset($_GET[$name]) || $_GET[$name] === '') return null;
    return trim($_GET[$name]);
}

// Filters (URL params)
$selected_department_id = get_int_or_null('department_id');
$selected_project_id    = get_int_or_null('project_id');
$selected_month         = get_int_or_null('month'); // 1-12, null for all months

// Build reusable filter predicates & param list
$biPredicates = ["bi.fiscal_year = ?"];    // budget_items alias
$aePredicates = ["bi.fiscal_year = ?"];    // actual_expenses join -> filter on bi.fiscal_year

// Use fiscal year in database format
$biParams = [$fiscal_year_db];
$aeParams = [$fiscal_year_db];

if ($selected_department_id) {
    $biPredicates[] = "bi.department_id = ?";
    $biParams[]     = $selected_department_id;

    $aePredicates[] = "bi.department_id = ?";
    $aeParams[]     = $selected_department_id;
}
if ($selected_project_id) {
    $biPredicates[] = "bi.project_id = ?";
    $biParams[]     = $selected_project_id;

    $aePredicates[] = "bi.project_id = ?";
    $aeParams[]     = $selected_project_id;
}

// Month filter ONLY applies to actual expenses, NOT to budget
if ($selected_month) {
    $aePredicates[] = "MONTH(ae.transaction_date) = ?";
    $aeParams[]     = $selected_month;
}

$biWhere = "WHERE " . implode(" AND ", $biPredicates);
$aeWhere = "WHERE " . implode(" AND ", $aePredicates);

// --- UTILITY FUNCTIONS ---
function fetch_all_assoc(mysqli $conn, string $sql, array $params = [], string $types = '') {
    $rows = [];
    
    // If no parameters, execute simple query
    if (empty($params)) {
        $res = $conn->query($sql);
        if ($res) $rows = $res->fetch_all(MYSQLI_ASSOC);
        return $rows;
    }
    
    // Determine types if not provided
    if ($types === '') {
        $types = '';
        foreach ($params as $p) {
            if (is_int($p))        $types .= 'i';
            else if (is_float($p)) $types .= 'd';
            else                   $types .= 's';
        }
    }
    
    // Prepare statement
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("SQL Prepare Error: " . $conn->error . " | SQL: " . $sql);
        return $rows;
    }
    
    // Bind parameters if we have any
    if (!empty($params)) {
        // Debug: Check parameter count
        if (count($params) !== strlen($types)) {
            error_log("Parameter mismatch! Params: " . count($params) . ", Types length: " . strlen($types));
            error_log("SQL: " . $sql);
            error_log("Params: " . print_r($params, true));
            error_log("Types: " . $types);
        }
        
        // Bind parameters properly
        $stmt->bind_param($types, ...$params);
    }
    
    if (!$stmt->execute()) {
        error_log("SQL Execute Error: " . $stmt->error);
        return $rows;
    }
    
    $res = $stmt->get_result();
    if ($res) $rows = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function fetch_scalar(mysqli $conn, string $sql, array $params = [], string $types = '') {
    $rows = fetch_all_assoc($conn, $sql, $params, $types);
    if (!$rows) return 0;
    $firstRow = $rows[0];
    return (float)array_shift($firstRow);
}

// --- DROPDOWN DATA ---
$departments = fetch_all_assoc($conn, "SELECT id, department_name FROM departments ORDER BY department_name ASC");

// Get projects based on selected department
if ($selected_department_id) {
    $projects = fetch_all_assoc(
        $conn,
        "SELECT id, name FROM projects WHERE department_id = ? ORDER BY name ASC",
        [$selected_department_id]
    );
} else {
    $projects = fetch_all_assoc($conn, "SELECT id, name FROM projects ORDER BY name ASC");
}

// Month options
$month_options = [
    '' => 'All Months',
    1 => 'January',
    2 => 'February', 
    3 => 'March',
    4 => 'April',
    5 => 'May',
    6 => 'June',
    7 => 'July',
    8 => 'August',
    9 => 'September',
    10 => 'October',
    11 => 'November',
    12 => 'December'
];

// --- KPIs (Totals) ---
// Query for total budget - include 'approved' AND 'requesting' status
$total_budget_query = "SELECT COALESCE(SUM(bi.total_budget_amount), 0) 
     FROM budget_items bi 
     $biWhere AND bi.status IN ('approved', 'requesting')";

$total_budget = fetch_scalar($conn, $total_budget_query, $biParams);

// Query for total actual expenses
$total_actual_query = "SELECT COALESCE(SUM(ae.amount), 0)
     FROM actual_expenses ae
     JOIN budget_items bi ON ae.budget_item_id = bi.id
     $aeWhere AND ae.status = 'paid'";

$total_actual = fetch_scalar($conn, $total_actual_query, $aeParams);

$remaining_budget = $total_budget - $total_actual;
$utilization_pct  = ($total_budget > 0) ? round(($total_actual / $total_budget) * 100, 2) : 0;

// --- MONTHLY ACTUAL (for bar in Overview) ---
$monthly_actual_query = "SELECT 
        MONTH(ae.transaction_date) AS m,
        COALESCE(SUM(ae.amount), 0) AS total
     FROM actual_expenses ae
     JOIN budget_items bi ON ae.budget_item_id = bi.id
     $aeWhere AND ae.status = 'paid'
     GROUP BY m ORDER BY m";

$monthly_actual_rows = fetch_all_assoc($conn, $monthly_actual_query, $aeParams);

// Prepare arrays for 12 months
$monthlyActual = array_fill(1, 12, 0.0);
foreach ($monthly_actual_rows as $r) {
    $m = (int)$r['m'];
    $monthlyActual[$m] = (float)$r['total'];
}

// Even monthly budget (no schedule in schema)
$monthlyBudget = array_fill(1, 12, ($total_budget / 12.0));
$monthLabels   = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

// --- CATEGORY PIE (Overview) ---
$cat_query = "SELECT bc.category_name, COALESCE(SUM(ae.amount), 0) AS total
     FROM actual_expenses ae
     JOIN budget_items bi ON ae.budget_item_id = bi.id
     LEFT JOIN budget_categories bc ON bi.budget_category_id = bc.id
     $aeWhere AND ae.status = 'paid'
     GROUP BY bc.category_name
     ORDER BY total DESC";

$cat_rows = fetch_all_assoc($conn, $cat_query, $aeParams);

$categoryLabels = [];
$categoryValues = [];
foreach ($cat_rows as $r) {
    $categoryLabels[] = $r['category_name'] ?: 'Uncategorized';
    $categoryValues[] = (float)$r['total'];
}

// --- DEPARTMENT BAR (Budget vs Actual by Dept) ---
// Build department query parameters
$dept_params = [$fiscal_year_db];
$dept_conditions = [];

// Start with basic query
$dept_query = "SELECT 
    d.id,
    d.department_name, 
    COALESCE(SUM(CASE WHEN bi.status IN ('approved', 'requesting') THEN bi.total_budget_amount ELSE 0 END), 0) AS budget_total,
    COALESCE(SUM(CASE WHEN ae.status = 'paid' THEN ae.amount ELSE 0 END), 0) AS actual_total
 FROM departments d
 LEFT JOIN budget_items bi ON bi.department_id = d.id AND bi.fiscal_year = ?
 LEFT JOIN actual_expenses ae ON ae.budget_item_id = bi.id";

if ($selected_department_id) {
    $dept_conditions[] = "d.id = ?";
    $dept_params[] = $selected_department_id;
}

if ($selected_project_id) {
    $dept_conditions[] = "bi.project_id = ?";
    $dept_params[] = $selected_project_id;
}

if ($selected_month) {
    $dept_query = "SELECT 
        d.id,
        d.department_name, 
        COALESCE(SUM(CASE WHEN bi.status IN ('approved', 'requesting') THEN bi.total_budget_amount ELSE 0 END), 0) AS budget_total,
        COALESCE(SUM(CASE WHEN ae.status = 'paid' AND MONTH(ae.transaction_date) = ? THEN ae.amount ELSE 0 END), 0) AS actual_total
     FROM departments d
     LEFT JOIN budget_items bi ON bi.department_id = d.id AND bi.fiscal_year = ?
     LEFT JOIN actual_expenses ae ON ae.budget_item_id = bi.id";
    
    // Rebuild params array for month filter
    $dept_params = [$selected_month, $fiscal_year_db];
    if ($selected_department_id) $dept_params[] = $selected_department_id;
    if ($selected_project_id) $dept_params[] = $selected_project_id;
}

if (!empty($dept_conditions)) {
    $dept_query .= " WHERE " . implode(" AND ", $dept_conditions);
}

$dept_query .= " GROUP BY d.id, d.department_name
                 HAVING budget_total > 0 OR actual_total > 0
                 ORDER BY actual_total DESC";

$dept_rows = fetch_all_assoc($conn, $dept_query, $dept_params);
$deptLabels = array_map(fn($r) => $r['department_name'] ?: '—', $dept_rows);
$deptBudget = array_map(fn($r) => (float)$r['budget_total'], $dept_rows);
$deptActual = array_map(fn($r) => (float)$r['actual_total'], $dept_rows);

// --- PROJECT BAR (Top N by Actual) ---
// Build project query parameters
$proj_params = [$fiscal_year_db];
$proj_conditions = [];

// Start with basic query
$proj_query = "SELECT 
    p.id,
    p.name AS project_name, 
    COALESCE(SUM(CASE WHEN bi.status IN ('approved', 'requesting') THEN bi.total_budget_amount ELSE 0 END), 0) AS budget_total,
    COALESCE(SUM(CASE WHEN ae.status = 'paid' THEN ae.amount ELSE 0 END), 0) AS actual_total
 FROM projects p
 LEFT JOIN budget_items bi ON bi.project_id = p.id AND bi.fiscal_year = ?
 LEFT JOIN actual_expenses ae ON ae.budget_item_id = bi.id";

if ($selected_department_id) {
    $proj_conditions[] = "p.department_id = ?";
    $proj_params[] = $selected_department_id;
}

if ($selected_month) {
    $proj_query = "SELECT 
        p.id,
        p.name AS project_name, 
        COALESCE(SUM(CASE WHEN bi.status IN ('approved', 'requesting') THEN bi.total_budget_amount ELSE 0 END), 0) AS budget_total,
        COALESCE(SUM(CASE WHEN ae.status = 'paid' AND MONTH(ae.transaction_date) = ? THEN ae.amount ELSE 0 END), 0) AS actual_total
     FROM projects p
     LEFT JOIN budget_items bi ON bi.project_id = p.id AND bi.fiscal_year = ?
     LEFT JOIN actual_expenses ae ON ae.budget_item_id = bi.id";
    
    // Rebuild params array for month filter
    $proj_params = [$selected_month, $fiscal_year_db];
    if ($selected_department_id) $proj_params[] = $selected_department_id;
}

if (!empty($proj_conditions)) {
    $proj_query .= " WHERE " . implode(" AND ", $proj_conditions);
}

$proj_query .= " GROUP BY p.id, p.name
                 HAVING budget_total > 0 OR actual_total > 0
                 ORDER BY actual_total DESC
                 LIMIT 10";

$proj_rows = fetch_all_assoc($conn, $proj_query, $proj_params);
$projectLabels = array_map(fn($r) => $r['project_name'] ?: '—', $proj_rows);
$projectBudget = array_map(fn($r) => (float)$r['budget_total'], $proj_rows);
$projectActual = array_map(fn($r) => (float)$r['actual_total'], $proj_rows);

// --- VENDOR BAR (Top 5 by Actual) ---
$vendor_query = "SELECT v.vendor_name, COALESCE(SUM(ae.amount), 0) AS total
     FROM actual_expenses ae
     JOIN budget_items bi ON ae.budget_item_id = bi.id
     LEFT JOIN vendors v ON ae.vendor_id = v.id
     $aeWhere AND ae.status = 'paid' AND v.vendor_name IS NOT NULL AND v.vendor_name != ''
     GROUP BY v.vendor_name
     ORDER BY total DESC
     LIMIT 5";

$vendor_rows = fetch_all_assoc($conn, $vendor_query, $aeParams);
$vendorLabels = array_map(fn($r) => $r['vendor_name'] ?: '—', $vendor_rows);
$vendorValues = array_map(fn($r) => (float)$r['total'], $vendor_rows);

// --- PAYMENT METHOD PIE ---
$pm_query = "SELECT ae.payment_method, COALESCE(SUM(ae.amount), 0) AS total
     FROM actual_expenses ae
     JOIN budget_items bi ON ae.budget_item_id = bi.id
     $aeWhere AND ae.status = 'paid'
     GROUP BY ae.payment_method
     ORDER BY total DESC";

$pm_rows = fetch_all_assoc($conn, $pm_query, $aeParams);
$pmLabels = array_map(fn($r) => $r['payment_method'] ?: 'Unknown', $pm_rows);
$pmValues = array_map(fn($r) => (float)$r['total'], $pm_rows);

// --- TRENDS (CUMULATIVE LINES: Actual vs Linear Budget) ---
if ($selected_month) {
    // If a specific month is selected, show data only up to that month
    $cumActual = [];
    $cumBudget = [];
    $runningActual = 0.0;
    $runningBudget = 0.0;
    for ($m = 1; $m <= 12; $m++) {
        if ($m <= $selected_month) {
            $runningActual += $monthlyActual[$m] ?? 0;
            $runningBudget += $monthlyBudget[$m] ?? 0;
        }
        $cumActual[] = $runningActual;
        $cumBudget[] = $runningBudget;
    }
} else {
    // No month selected, show full year cumulative
    $cumActual = [];
    $cumBudget = [];
    $runningActual = 0.0;
    $runningBudget = 0.0;
    for ($m = 1; $m <= 12; $m++) {
        $runningActual += $monthlyActual[$m] ?? 0;
        $runningBudget += $monthlyBudget[$m] ?? 0;
        $cumActual[] = $runningActual;
        $cumBudget[] = $runningBudget;
    }
}

// --- CATEGORIES (STACKED: Parent Budget vs Actual) ---
// Build parent categories query
$parent_params = [$fiscal_year_db];
$parent_conditions = [];

// Start with basic query
$parent_query = "SELECT 
    pbc.parent_name, 
    COALESCE(SUM(CASE WHEN bi.status IN ('approved', 'requesting') THEN bi.total_budget_amount ELSE 0 END), 0) AS budget_total,
    COALESCE(SUM(CASE WHEN ae.status = 'paid' THEN ae.amount ELSE 0 END), 0) AS actual_total
 FROM parent_budget_categories pbc 
 LEFT JOIN budget_categories bc ON bc.parent_id = pbc.id 
 LEFT JOIN budget_items bi ON bi.budget_category_id = bc.id AND bi.fiscal_year = ?
 LEFT JOIN actual_expenses ae ON ae.budget_item_id = bi.id";

if ($selected_department_id) {
    $parent_conditions[] = "bi.department_id = ?";
    $parent_params[] = $selected_department_id;
}
if ($selected_project_id) {
    $parent_conditions[] = "bi.project_id = ?";
    $parent_params[] = $selected_project_id;
}

if ($selected_month) {
    $parent_query = "SELECT 
        pbc.parent_name, 
        COALESCE(SUM(CASE WHEN bi.status IN ('approved', 'requesting') THEN bi.total_budget_amount ELSE 0 END), 0) AS budget_total,
        COALESCE(SUM(CASE WHEN ae.status = 'paid' AND MONTH(ae.transaction_date) = ? THEN ae.amount ELSE 0 END), 0) AS actual_total
     FROM parent_budget_categories pbc 
     LEFT JOIN budget_categories bc ON bc.parent_id = pbc.id 
     LEFT JOIN budget_items bi ON bi.budget_category_id = bc.id AND bi.fiscal_year = ?
     LEFT JOIN actual_expenses ae ON ae.budget_item_id = bi.id";
    
    // Rebuild params array for month filter
    $parent_params = [$selected_month, $fiscal_year_db];
    if ($selected_department_id) $parent_params[] = $selected_department_id;
    if ($selected_project_id) $parent_params[] = $selected_project_id;
}

if (!empty($parent_conditions)) {
    $parent_query .= " WHERE " . implode(" AND ", $parent_conditions);
}

$parent_query .= " GROUP BY pbc.id, pbc.parent_name
                   HAVING budget_total > 0 OR actual_total > 0
                   ORDER BY pbc.parent_name ASC";

$parent_rows = fetch_all_assoc($conn, $parent_query, $parent_params);
$parentLabels  = array_map(fn($r) => $r['parent_name'] ?: '—', $parent_rows);
$parentBudget  = array_map(fn($r) => (float)$r['budget_total'], $parent_rows);
$parentActual  = array_map(fn($r) => (float)$r['actual_total'], $parent_rows);

// --- RECENT EXPENSES (Transactions tab) ---
$transactions_per_page = 10;
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($current_page - 1) * $transactions_per_page;

// Get total count for pagination
$total_transactions_query = "SELECT COUNT(*)
     FROM actual_expenses ae
     JOIN budget_items bi ON ae.budget_item_id = bi.id
     $aeWhere AND ae.status IN ('paid','pending')";

$total_transactions = fetch_scalar($conn, $total_transactions_query, $aeParams);

$total_pages = ceil($total_transactions / $transactions_per_page);

$recent_query = "SELECT ae.transaction_date, ae.description, ae.amount, ae.status, v.vendor_name, ae.payment_method,
            bi.item_name, bc.category_name, d.department_name, p.name AS project_name
     FROM actual_expenses ae
     LEFT JOIN vendors v ON ae.vendor_id = v.id
     LEFT JOIN budget_items bi ON ae.budget_item_id = bi.id
     LEFT JOIN budget_categories bc ON bi.budget_category_id = bc.id
     LEFT JOIN departments d ON bi.department_id = d.id
     LEFT JOIN projects p ON bi.project_id = p.id
     $aeWhere AND ae.status IN ('paid','pending')
     ORDER BY ae.transaction_date DESC, ae.id DESC
     LIMIT $offset, $transactions_per_page";

$recent_rows = fetch_all_assoc($conn, $recent_query, $aeParams);

// Encode to JSON for Chart.js
$js_month_labels  = json_encode($monthLabels);
$js_month_budget  = json_encode(array_values($monthlyBudget));
$js_month_actual  = json_encode(array_values($monthlyActual));

$js_cat_labels    = json_encode($categoryLabels);
$js_cat_values    = json_encode($categoryValues);

$js_dept_labels   = json_encode($deptLabels);
$js_dept_budget   = json_encode($deptBudget);
$js_dept_actual   = json_encode($deptActual);

$js_proj_labels   = json_encode($projectLabels);
$js_proj_budget   = json_encode($projectBudget);
$js_proj_actual   = json_encode($projectActual);

$js_vendor_labels = json_encode($vendorLabels);
$js_vendor_values = json_encode($vendorValues);

$js_pm_labels     = json_encode($pmLabels);
$js_pm_values     = json_encode($pmValues);

$js_cum_actual    = json_encode($cumActual);
$js_cum_budget    = json_encode($cumBudget);

$js_parent_labels = json_encode($parentLabels);
$js_parent_budget = json_encode($parentBudget);
$js_parent_actual = json_encode($parentActual);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Budget Dashboard</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <style>
        :root {
            --dashen-primary: #273274;
            --dashen-secondary: #5a67d8;
            --dashen-success: #10b981;
            --dashen-info: #3b82f6;
            --dashen-warning: #f59e0b;
            --dashen-danger: #ef4444;
        }
        
        .kpi-card {
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s;
        }
        
        .kpi-card:hover {
            transform: translateY(-5px);
        }
        
        .kpi-card .icon {
            position: absolute;
            top: 20px;
            right: 20px;
            font-size: 2rem;
            opacity: 0.3;
        }
        
        .card-dashen {
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }
        
        .card-header-dashen {
            background-color: #f9fafb;
            border-bottom: 1px solid #e5e7eb;
            padding: 1rem 1.5rem;
            border-radius: 10px 10px 0 0 !important;
        }
        
        .page-title {
            color: var(--dashen-primary);
            font-weight: 700;
        }
        
        .btn-dashen {
            background-color: var(--dashen-primary);
            color: white;
            border: none;
        }
        
        .btn-dashen:hover {
            background-color: #1e264d;
            color: white;
        }
        
        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
        }
        
        .filter-section {
            background-color: #f8f9fa;
            padding: 1.5rem;
            border-radius: 10px;
            margin-bottom: 1.5rem;
        }
        
        .nav-tabs .nav-link {
            color: #6b7280;
            font-weight: 500;
            border: none;
            padding: 0.75rem 1.5rem;
        }
        
        .nav-tabs .nav-link.active {
            color: var(--dashen-primary);
            border-bottom: 3px solid var(--dashen-primary);
            background-color: transparent;
        }
        
        .badge-pending {
            background-color: #fbbf24;
            color: #92400e;
        }
        
        .badge-paid {
            background-color: #10b981;
            color: #064e3b;
        }
        
        .pagination-dashen .page-link {
            color: var(--dashen-primary);
        }
        
        .pagination-dashen .page-item.active .page-link {
            background-color: var(--dashen-primary);
            border-color: var(--dashen-primary);
        }
        
        .table-hover tbody tr:hover {
            background-color: #f8fafc;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar would be here if needed -->
            <div class="col-12">
                <!-- Page content will be inserted here -->
                <div class="container-fluid mt-4">

                    <!-- FILTERS -->
                    <div class="filter-section">
                        <div class="row g-3 align-items-end">
                            <div class="col-md-4">
                                <h2 class="page-title mb-0">Dashboard <small class="text-muted"><?= htmlspecialchars($fiscal_year_display) ?></small></h2>
                                <div class="text-muted small">Scope filters apply across tabs and charts.</div>
                            </div>
                            <div class="col-md-2">
                                <label for="department-select" class="form-label mb-1">Department</label>
                                <select id="department-select" class="form-select" onchange="onFilterChange('department', this.value)">
                                    <option value="">All Departments</option>
                                    <?php foreach ($departments as $d): ?>
                                        <option value="<?= (int)$d['id'] ?>" <?= $selected_department_id == (int)$d['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($d['department_name'] ?: '—') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label for="project-select" class="form-label mb-1">Project</label>
                                <select id="project-select" class="form-select" onchange="onFilterChange('project', this.value)">
                                    <option value="">All Projects</option>
                                    <?php foreach ($projects as $p): ?>
                                        <option value="<?= (int)$p['id'] ?>" <?= $selected_project_id == (int)$p['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($p['name'] ?: '—') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label for="month-select" class="form-label mb-1">Month</label>
                                <select id="month-select" class="form-select" onchange="onFilterChange('month', this.value)">
                                    <?php foreach ($month_options as $value => $label): ?>
                                        <option value="<?= $value ?>" <?= $selected_month == $value ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($label) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- KPI CARDS -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <div class="card kpi-card text-white" style="background-color: #4e73df;">
                                <div class="card-body">
                                    <h6 class="mb-1">Total Budget (Approved + Requesting)</h6>
                                    <h3 class="mb-0">$<?= number_format($total_budget, 2) ?></h3>
                                    <div class="icon"><i class="fas fa-money-bill-wave"></i></div>
                                    <div class="mt-2 small">All filters applied</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card kpi-card text-white" style="background-color: #1cc88a;">
                                <div class="card-body">
                                    <h6 class="mb-1">Actual Expenses (Paid)</h6>
                                    <h3 class="mb-0">$<?= number_format($total_actual, 2) ?></h3>
                                    <div class="icon"><i class="fas fa-receipt"></i></div>
                                    <div class="mt-2 small">
                                        <?php if ($selected_month): ?>
                                            <?= $month_options[$selected_month] ?> only
                                        <?php else: ?>
                                            Year-to-date
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card kpi-card text-white" style="background-color: #36b9cc;">
                                <div class="card-body">
                                    <h6 class="mb-1">Remaining Budget</h6>
                                    <h3 class="mb-0">$<?= number_format($remaining_budget, 2) ?></h3>
                                    <div class="icon"><i class="fas fa-wallet"></i></div>
                                    <div class="mt-2 small"><?= $utilization_pct ?>% used</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card kpi-card text-white" style="background-color: #5a5c69;">
                                <div class="card-body">
                                    <h6 class="mb-1">Utilization</h6>
                                    <h3 class="mb-0"><?= number_format($utilization_pct, 2) ?>%</h3>
                                    <div class="icon"><i class="fas fa-chart-line"></i></div>
                                    <div class="mt-2 small">Actual / Budget</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- TABS -->
                    <ul class="nav nav-tabs" id="dashTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="overview-tab" data-bs-toggle="tab" data-bs-target="#overview" type="button" role="tab">Overview</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="departments-tab" data-bs-toggle="tab" data-bs-target="#departments" type="button" role="tab">Departments</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="projects-tab" data-bs-toggle="tab" data-bs-target="#projects" type="button" role="tab">Projects</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="vendors-tab" data-bs-toggle="tab" data-bs-target="#vendors" type="button" role="tab">Vendors</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="payments-tab" data-bs-toggle="tab" data-bs-target="#payments" type="button" role="tab">Payment Methods</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="trends-tab" data-bs-toggle="tab" data-bs-target="#trends" type="button" role="tab">Trends</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="categories-tab" data-bs-toggle="tab" data-bs-target="#categories" type="button" role="tab">Categories</button>
                        </li>
                        <li class="nav-item ms-auto" role="presentation">
                            <button class="nav-link" id="transactions-tab" data-bs-toggle="tab" data-bs-target="#transactions" type="button" role="tab">Transactions</button>
                        </li>
                    </ul>

                    <div class="tab-content mt-3" id="dashTabsContent">
                        <!-- OVERVIEW TAB -->
                        <div class="tab-pane fade show active" id="overview" role="tabpanel" aria-labelledby="overview-tab">
                            <div class="row g-3">
                                <div class="col-lg-8">
                                    <div class="card card-dashen">
                                        <div class="card-header-dashen d-flex align-items-center justify-content-between">
                                            <h6 class="mb-0">Monthly Budget vs Actual</h6>
                                            <span class="text-muted small">
                                                <?php if ($selected_month): ?>
                                                    Showing actual for <?= $month_options[$selected_month] ?> only (budget shows full year)
                                                <?php else: ?>
                                                    Linear budget split
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                        <div class="card-body">
                                            <div class="chart-container">
                                                <canvas id="chartBudgetActual"></canvas>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-lg-4">
                                    <div class="card card-dashen">
                                        <div class="card-header-dashen"><h6 class="mb-0">Expenses by Category</h6></div>
                                        <div class="card-body">
                                            <div class="chart-container">
                                                <canvas id="chartCategoryPie"></canvas>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- DEPARTMENTS TAB -->
                        <div class="tab-pane fade" id="departments" role="tabpanel" aria-labelledby="departments-tab">
                            <div class="row g-3">
                                <div class="col-12">
                                    <div class="card card-dashen">
                                        <div class="card-header-dashen d-flex align-items-center justify-content-between">
                                            <h6 class="mb-0">Budget vs Actual by Department</h6>
                                            <span class="text-muted small">
                                                <?php if ($selected_month): ?>
                                                    <?= $month_options[$selected_month] ?> actual only
                                                <?php else: ?>
                                                    Budget vs Actual comparison
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                        <div class="card-body">
                                            <div class="chart-container">
                                                <canvas id="chartDepartmentBar"></canvas>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- PROJECTS TAB -->
                        <div class="tab-pane fade" id="projects" role="tabpanel" aria-labelledby="projects-tab">
                            <div class="row g-3">
                                <div class="col-12">
                                    <div class="card card-dashen">
                                        <div class="card-header-dashen d-flex align-items-center justify-content-between">
                                            <h6 class="mb-0">Top Projects - Budget vs Actual</h6>
                                            <span class="text-muted small">
                                                <?php if ($selected_month): ?>
                                                    <?= $month_options[$selected_month] ?> actual only
                                                <?php else: ?>
                                                    Top 10 projects
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                        <div class="card-body">
                                            <div class="chart-container">
                                                <canvas id="chartProjectBar"></canvas>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- VENDORS TAB -->
                        <div class="tab-pane fade" id="vendors" role="tabpanel" aria-labelledby="vendors-tab">
                            <div class="row g-3">
                                <div class="col-12">
                                    <div class="card card-dashen">
                                        <div class="card-header-dashen d-flex align-items-center justify-content-between">
                                            <h6 class="mb-0">Top Vendors by Actual Spend</h6>
                                            <span class="text-muted small">
                                                <?php if ($selected_month): ?>
                                                    <?= $month_options[$selected_month] ?> only
                                                <?php else: ?>
                                                    Top 5
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                        <div class="card-body">
                                            <div class="chart-container">
                                                <canvas id="chartVendors"></canvas>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- PAYMENT METHODS TAB -->
                        <div class="tab-pane fade" id="payments" role="tabpanel" aria-labelledby="payments-tab">
                            <div class="row g-3">
                                <div class="col-12">
                                    <div class="card card-dashen">
                                        <div class="card-header-dashen"><h6 class="mb-0">Spending by Payment Method</h6></div>
                                        <div class="card-body">
                                            <div class="chart-container">
                                                <canvas id="chartPaymentMethods"></canvas>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- TRENDS TAB -->
                        <div class="tab-pane fade" id="trends" role="tabpanel" aria-labelledby="trends-tab">
                            <div class="row g-3">
                                <div class="col-12">
                                    <div class="card card-dashen">
                                        <div class="card-header-dashen d-flex align-items-center justify-content-between">
                                            <h6 class="mb-0">Cumulative Actual vs Budget</h6>
                                            <span class="text-muted small">
                                                <?php if ($selected_month): ?>
                                                    Up to <?= $month_options[$selected_month] ?>
                                                <?php else: ?>
                                                    Year-to-date trend
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                        <div class="card-body">
                                            <div class="chart-container">
                                                <canvas id="chartCumulative"></canvas>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- CATEGORIES TAB -->
                        <div class="tab-pane fade" id="categories" role="tabpanel" aria-labelledby="categories-tab">
                            <div class="row g-3">
                                <div class="col-12">
                                    <div class="card card-dashen">
                                        <div class="card-header-dashen d-flex align-items-center justify-content-between">
                                            <h6 class="mb-0">Parent Categories — Budget vs Actual</h6>
                                            <span class="text-muted small">
                                                <?php if ($selected_month): ?>
                                                    <?= $month_options[$selected_month] ?> actual only
                                                <?php else: ?>
                                                    Stacked comparison
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                        <div class="card-body">
                                            <div class="chart-container">
                                                <canvas id="chartParentCategories"></canvas>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- TRANSACTIONS TAB -->
                        <div class="tab-pane fade" id="transactions" role="tabpanel" aria-labelledby="transactions-tab">
                            <div class="row g-3">
                                <div class="col-12">
                                    <div class="card card-dashen">
                                        <div class="card-header-dashen d-flex align-items-center justify-content-between">
                                            <h6 class="mb-0">Recent Transactions</h6>
                                            <span class="text-muted small"><?= $total_transactions ?> total transactions
                                                <?php if ($selected_month): ?>
                                                    in <?= $month_options[$selected_month] ?>
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                        <div class="card-body">
                                            <div class="table-responsive">
                                                <table class="table table-hover align-middle">
                                                    <thead class="table-light">
                                                        <tr>
                                                            <th>Date</th>
                                                            <th>Project</th>
                                                            <th>Department</th>
                                                            <th>Category</th>
                                                            <th>Item</th>
                                                            <th>Description</th>
                                                            <th>Vendor</th>
                                                            <th>Payment</th>
                                                            <th class="text-end">Amount</th>
                                                            <th>Status</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                    <?php if ($recent_rows): ?>
                                                        <?php foreach ($recent_rows as $rx): ?>
                                                            <tr>
                                                                <td><?= $rx['transaction_date'] ? date('M d, Y', strtotime($rx['transaction_date'])) : '—' ?></td>
                                                                <td><?= htmlspecialchars($rx['project_name'] ?: '—') ?></td>
                                                                <td><?= htmlspecialchars($rx['department_name'] ?: '—') ?></td>
                                                                <td><?= htmlspecialchars($rx['category_name'] ?: '—') ?></td>
                                                                <td><?= htmlspecialchars($rx['item_name'] ?: '—') ?></td>
                                                                <td><?= htmlspecialchars($rx['description'] ?: '—') ?></td>
                                                                <td><?= htmlspecialchars($rx['vendor_name'] ?: '—') ?></td>
                                                                <td><?= htmlspecialchars($rx['payment_method'] ?: '—') ?></td>
                                                                <td class="text-end">$<?= number_format((float)$rx['amount'], 2) ?></td>
                                                                <td>
                                                                    <span class="badge badge-<?= $rx['status'] ?>">
                                                                        <?= ucfirst($rx['status']) ?>
                                                                    </span>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    <?php else: ?>
                                                        <tr><td colspan="10" class="text-center text-muted">No transactions found.</td></tr>
                                                    <?php endif; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                            
                                            <!-- Pagination -->
                                            <?php if ($total_pages > 1): ?>
                                            <nav aria-label="Transaction pagination">
                                                <ul class="pagination pagination-dashen justify-content-center mt-4">
                                                    <?php if ($current_page > 1): ?>
                                                        <li class="page-item">
                                                            <a class="page-link" href="?<?php 
                                                                $params = $_GET;
                                                                $params['page'] = $current_page - 1;
                                                                echo http_build_query($params);
                                                            ?>">Previous</a>
                                                        </li>
                                                    <?php endif; ?>
                                                    
                                                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                                        <li class="page-item <?= $i == $current_page ? 'active' : '' ?>">
                                                            <a class="page-link" href="?<?php 
                                                                $params = $_GET;
                                                                $params['page'] = $i;
                                                                echo http_build_query($params);
                                                            ?>"><?= $i ?></a>
                                                        </li>
                                                    <?php endfor; ?>
                                                    
                                                    <?php if ($current_page < $total_pages): ?>
                                                        <li class="page-item">
                                                            <a class="page-link" href="?<?php 
                                                                $params = $_GET;
                                                                $params['page'] = $current_page + 1;
                                                                echo http_build_query($params);
                                                            ?>">Next</a>
                                                        </li>
                                                    <?php endif; ?>
                                                </ul>
                                            </nav>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div><!-- /.tab-content -->
                </div><!-- /.container-fluid -->

            </div><!-- /.col-12 -->
        </div><!-- /.row -->
    </div><!-- /.container-fluid -->

    <!-- Bootstrap JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    /* ---------------------------
       FILTER HANDLERS
    ---------------------------- */
    function onFilterChange(type, id) {
        const params = new URLSearchParams(window.location.search);
        if (type === 'department') {
            if (id) {
                params.set('department_id', id);
                params.delete('project_id');
            } else {
                params.delete('department_id');
                params.delete('project_id');
            }
        } else if (type === 'project') {
            if (id) params.set('project_id', id);
            else params.delete('project_id');
        } else if (type === 'month') {
            if (id) params.set('month', id);
            else params.delete('month');
        }
        params.set('page', '1');
        window.location.search = params.toString();
    }

    /* ---------------------------
       CHART DATA (from PHP)
    ---------------------------- */
    const monthLabels   = <?= $js_month_labels ?>;
    const monthBudget   = <?= $js_month_budget ?>;
    const monthActual   = <?= $js_month_actual ?>;

    const categoryLabels = <?= $js_cat_labels ?>;
    const categoryValues = <?= $js_cat_values ?>;

    const deptLabels  = <?= $js_dept_labels ?>;
    const deptBudget  = <?= $js_dept_budget ?>;
    const deptActual  = <?= $js_dept_actual ?>;

    const projectLabels = <?= $js_proj_labels ?>;
    const projectBudget = <?= $js_proj_budget ?>;
    const projectActual = <?= $js_proj_actual ?>;

    const vendorLabels  = <?= $js_vendor_labels ?>;
    const vendorValues  = <?= $js_vendor_values ?>;

    const pmLabels = <?= $js_pm_labels ?>;
    const pmValues = <?= $js_pm_values ?>;

    const cumBudget = <?= $js_cum_budget ?>;
    const cumActual = <?= $js_cum_actual ?>;

    const parentLabels = <?= $js_parent_labels ?>;
    const parentBudget = <?= $js_parent_budget ?>;
    const parentActual = <?= $js_parent_actual ?>;

    /* ---------------------------
       CHART HELPERS
    ---------------------------- */
    function currencyFormatter(value) {
        try {
            return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'USD', maximumFractionDigits: 0 }).format(value);
        } catch (e) {
            return '$' + (value || 0).toLocaleString();
        }
    }
    function axisCurrencyTicks(value, index, ticks) {
        return currencyFormatter(value);
    }

    /* ---------------------------
       OVERVIEW: Budget vs Actual (combo)
    ---------------------------- */
    const ctxBudgetActual = document.getElementById('chartBudgetActual').getContext('2d');
    const chartBudgetActual = new Chart(ctxBudgetActual, {
        type: 'bar',
        data: {
            labels: monthLabels,
            datasets: [
                {
                    label: 'Budget',
                    data: monthBudget,
                    type: 'line',
                    tension: 0.3,
                    borderWidth: 2,
                    pointRadius: 3,
                    borderColor: 'rgba(54, 162, 235, 1)',
                    backgroundColor: 'rgba(54, 162, 235, 0.15)',
                    fill: true
                },
                {
                    label: 'Actual',
                    data: monthActual,
                    backgroundColor: 'rgba(75, 192, 192, 0.65)',
                    borderColor: 'rgba(75, 192, 192, 1)',
                    borderWidth: 1,
                    borderSkipped: false
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { callback: axisCurrencyTicks }
                }
            },
            plugins: {
                legend: { position: 'top' },
                tooltip: {
                    callbacks: {
                        label: (ctx) => `${ctx.dataset.label}: ${currencyFormatter(ctx.parsed.y)}`
                    }
                }
            }
        }
    });

    /* ---------------------------
       OVERVIEW: Category Pie
    ---------------------------- */
    const ctxCategoryPie = document.getElementById('chartCategoryPie').getContext('2d');
    const chartCategoryPie = new Chart(ctxCategoryPie, {
        type: 'doughnut',
        data: {
            labels: categoryLabels,
            datasets: [{
                data: categoryValues,
                borderWidth: 1,
                backgroundColor: [
                    'rgba(78, 115, 223, 0.8)',
                    'rgba(28, 200, 138, 0.8)',
                    'rgba(54, 185, 204, 0.8)',
                    'rgba(246, 194, 62, 0.8)',
                    'rgba(231, 74, 59, 0.8)',
                    'rgba(133, 135, 150, 0.8)',
                    'rgba(105, 0, 132, 0.8)',
                    'rgba(0, 128, 128, 0.8)',
                    'rgba(255, 159, 64, 0.8)',
                    'rgba(153, 102, 255, 0.8)'
                ]
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'right' },
                tooltip: {
                    callbacks: {
                        label: (ctx) => {
                            const label = ctx.label || '';
                            const val = ctx.raw || 0;
                            return `${label}: ${currencyFormatter(val)}`;
                        }
                    }
                }
            }
        }
    });

    /* ---------------------------
       DEPARTMENTS: Bar (Budget vs Actual)
    ---------------------------- */
    const ctxDept = document.getElementById('chartDepartmentBar').getContext('2d');
    const chartDepartmentBar = new Chart(ctxDept, {
        type: 'bar',
        data: {
            labels: deptLabels,
            datasets: [
                {
                    label: 'Budget',
                    data: deptBudget,
                    backgroundColor: 'rgba(54, 162, 235, 0.7)',
                    borderColor: 'rgba(54, 162, 235, 1)',
                    borderWidth: 1
                },
                {
                    label: 'Actual',
                    data: deptActual,
                    backgroundColor: 'rgba(255, 159, 64, 0.7)',
                    borderColor: 'rgba(255, 159, 64, 1)',
                    borderWidth: 1
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            indexAxis: 'x',
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { callback: axisCurrencyTicks }
                },
                x: { ticks: { autoSkip: false } }
            },
            plugins: {
                legend: { display: true, position: 'top' },
                tooltip: {
                    callbacks: {
                        label: (ctx) => `${ctx.dataset.label}: ${currencyFormatter(ctx.parsed.y)}`
                    }
                }
            }
        }
    });

    /* ---------------------------
       PROJECTS: Bar (Budget vs Actual)
    ---------------------------- */
    const ctxProj = document.getElementById('chartProjectBar').getContext('2d');
    const chartProjectBar = new Chart(ctxProj, {
        type: 'bar',
        data: {
            labels: projectLabels,
            datasets: [
                {
                    label: 'Budget',
                    data: projectBudget,
                    backgroundColor: 'rgba(153, 102, 255, 0.7)',
                    borderColor: 'rgba(153, 102, 255, 1)',
                    borderWidth: 1
                },
                {
                    label: 'Actual',
                    data: projectActual,
                    backgroundColor: 'rgba(255, 99, 132, 0.7)',
                    borderColor: 'rgba(255, 99, 132, 1)',
                    borderWidth: 1
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            indexAxis: 'x',
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { callback: axisCurrencyTicks }
                },
                x: { ticks: { autoSkip: false } }
            },
            plugins: {
                legend: { position: 'top' },
                tooltip: {
                    callbacks: {
                        label: (ctx) => `${ctx.dataset.label}: ${currencyFormatter(ctx.parsed.y)}`
                    }
                }
            }
        }
    });

    /* ---------------------------
       VENDORS: Bar (Top 5)
    ---------------------------- */
    const ctxVendors = document.getElementById('chartVendors').getContext('2d');
    const chartVendors = new Chart(ctxVendors, {
        type: 'bar',
        data: {
            labels: vendorLabels,
            datasets: [{
                label: 'Actual',
                data: vendorValues,
                borderWidth: 1,
                backgroundColor: 'rgba(255, 99, 132, 0.65)',
                borderColor: 'rgba(255, 99, 132, 1)'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            indexAxis: 'y',
            scales: {
                x: {
                    beginAtZero: true,
                    ticks: { callback: axisCurrencyTicks }
                }
            },
            plugins: {
                legend: { position: 'top' },
                tooltip: {
                    callbacks: {
                        label: (ctx) => `${ctx.dataset.label}: ${currencyFormatter(ctx.parsed.x)}`
                    }
                }
            }
        }
    });

    /* ---------------------------
       PAYMENT METHODS: Doughnut
    ---------------------------- */
    const ctxPM = document.getElementById('chartPaymentMethods').getContext('2d');
    const chartPaymentMethods = new Chart(ctxPM, {
        type: 'doughnut',
        data: {
            labels: pmLabels,
            datasets: [{
                data: pmValues,
                borderWidth: 1,
                backgroundColor: [
                    'rgba(78, 115, 223, 0.8)',
                    'rgba(28, 200, 138, 0.8)',
                    'rgba(54, 185, 204, 0.8)',
                    'rgba(246, 194, 62, 0.8)',
                    'rgba(231, 74, 59, 0.8)'
                ]
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'right' },
                tooltip: {
                    callbacks: {
                        label: (ctx) => {
                            const label = ctx.label || '';
                            const val = ctx.raw || 0;
                            return `${label}: ${currencyFormatter(val)}`;
                        }
                    }
                }
            }
        }
    });

    /* ---------------------------
       TRENDS: Cumulative Lines
    ---------------------------- */
    const ctxCum = document.getElementById('chartCumulative').getContext('2d');
    const chartCumulative = new Chart(ctxCum, {
        type: 'line',
        data: {
            labels: monthLabels,
            datasets: [
                {
                    label: 'Cumulative Budget',
                    data: cumBudget,
                    tension: 0.25,
                    borderWidth: 2,
                    pointRadius: 2,
                    borderColor: 'rgba(54, 162, 235, 1)',
                    backgroundColor: 'rgba(54, 162, 235, 0.15)',
                    fill: true
                },
                {
                    label: 'Cumulative Actual',
                    data: cumActual,
                    tension: 0.25,
                    borderWidth: 2,
                    pointRadius: 2,
                    borderColor: 'rgba(75, 192, 192, 1)',
                    backgroundColor: 'rgba(75, 192, 192, 0.1)',
                    fill: true
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { callback: axisCurrencyTicks }
                }
            },
            plugins: {
                legend: { position: 'top' },
                tooltip: {
                    callbacks: {
                        label: (ctx) => `${ctx.dataset.label}: ${currencyFormatter(ctx.parsed.y)}`
                    }
                }
            }
        }
    });

    /* ---------------------------
       CATEGORIES: Stacked (Parent)
    ---------------------------- */
    const ctxParent = document.getElementById('chartParentCategories').getContext('2d');
    const chartParentCategories = new Chart(ctxParent, {
        type: 'bar',
        data: {
            labels: parentLabels,
            datasets: [
                {
                    label: 'Budget',
                    data: parentBudget,
                    backgroundColor: 'rgba(13, 110, 253, 0.7)',
                    borderColor: 'rgba(13, 110, 253, 1)',
                    borderWidth: 1
                },
                {
                    label: 'Actual',
                    data: parentActual,
                    backgroundColor: 'rgba(25, 135, 84, 0.7)',
                    borderColor: 'rgba(25, 135, 84, 1)',
                    borderWidth: 1
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                x: { ticks: { autoSkip: false } },
                y: { beginAtZero: true, ticks: { callback: axisCurrencyTicks } }
            },
            plugins: {
                legend: { position: 'top' },
                tooltip: {
                    callbacks: {
                        label: (ctx) => `${ctx.dataset.label}: ${currencyFormatter(ctx.parsed.y)}`
                    }
                }
            }
        }
    });
    </script>
</body>
</html>