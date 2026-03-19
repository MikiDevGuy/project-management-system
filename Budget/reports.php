<?php
// ==========================================================
// reports.php  — Full, corrected, MySQL 5.7–compatible
// - Keeps all original features (filters + summary cards)
// - Fixes column shifting by rendering columns in a strict map
// - Fixes "Budget duplicates" and "Actual miscalc" in detailed report
// - Adds robust Print & PDF export so last columns are visible
// - Excel export preserved
// - Adds professional header with company logo for print/PDF
// ==========================================================

// --- DATABASE CONNECTION ---
include '../db.php';
// Include header
include 'header.php';

// --- COMPANY INFORMATION (Customize these for your organization) ---
$company_name = "DASHEN BANK S.C.";
$company_logo_path = "../Images/DashenLogo1.png"; // Path to your logo
$company_logo_url = "https://your-domain.com/Images/DashenLogo1.png"; // Full URL for PDF export
$company_address = "123 $ KILLO, ADDIS ABEBA, HEAD OFFICE 12345";
$company_phone = "(555) 123-4567";
$company_email = "info@yourcompanyDashenbanksc.com";

// --- PREPARE DATA FOR FILTER DROPDOWNS ---
// Generate fiscal years from 2015-2016 to 2026-2027
$fiscal_years = [];
$start_year = 2015;
$end_year = 2026;

for ($year = $start_year; $year <= $end_year; $year++) {
    $fiscal_year = $year . "-" . ($year + 1);
    $fiscal_years[] = $fiscal_year;
}

// Also get existing fiscal years from database for validation
$db_fiscal_years = [];
$years_q = $conn->query("SELECT DISTINCT fiscal_year FROM budget_items ORDER BY fiscal_year DESC");
if ($years_q && $years_q->num_rows > 0) {
    while ($r = $years_q->fetch_assoc()) {
        $db_fiscal_years[] = $r['fiscal_year'];
    }
}

$projects = [];
$pq = $conn->query("SELECT id, name FROM projects ORDER BY name ASC");
if ($pq && $pq->num_rows > 0) {
    while ($r = $pq->fetch_assoc()) $projects[] = $r;
}

$departments = [];
$dq = $conn->query("SELECT id, department_name FROM departments ORDER BY department_name ASC");
if ($dq && $dq->num_rows > 0) {
    while ($r = $dq->fetch_assoc()) $departments[] = $r;
}

$cost_types = [];
$ctq = $conn->query("SELECT id, name FROM cost_types ORDER BY name ASC");
if ($ctq && $ctq->num_rows > 0) {
    while ($r = $ctq->fetch_assoc()) $cost_types[] = $r;
}

$parent_categories = [];
$pcq = $conn->query("SELECT id, parent_name FROM parent_budget_categories WHERE is_active = 1 ORDER BY parent_name ASC");
if ($pcq && $pcq->num_rows > 0) {
    while ($r = $pcq->fetch_assoc()) $parent_categories[] = $r;
}

$child_categories = [];
$ccq = $conn->query("
    SELECT bc.id, bc.category_name, pbc.parent_name 
    FROM budget_categories bc 
    LEFT JOIN parent_budget_categories pbc ON bc.parent_id = pbc.id 
    WHERE bc.is_active = 1 
    ORDER BY pbc.parent_name, bc.category_name ASC
");
if ($ccq && $ccq->num_rows > 0) {
    while ($r = $ccq->fetch_assoc()) $child_categories[] = $r;
}

$vendors = [];
$vq = $conn->query("SELECT id, vendor_name FROM vendors ORDER BY vendor_name ASC");
if ($vq && $vq->num_rows > 0) {
    while ($r = $vq->fetch_assoc()) $vendors[] = $r;
}

// --- PROCESS FILTERS ---
$selected_report_type = $_GET['report_type']        ?? 'budget_vs_actual';
$selected_year        = $_GET['fiscal_year']        ?? '';
$selected_project     = $_GET['project_id']         ?? '';
$selected_department  = $_GET['department_id']      ?? '';
$selected_cost_type   = $_GET['cost_type_id']       ?? '';
$selected_parent_cat  = $_GET['parent_category_id'] ?? '';
$selected_child_cat   = $_GET['child_category_id']  ?? '';
$selected_vendor      = $_GET['vendor_id']          ?? '';
$date_from            = $_GET['date_from']          ?? '';
$date_to              = $_GET['date_to']            ?? '';

// Initialize summary totals
$total_budget_summary = 0.0;
$total_actual_summary = 0.0;

// --- BUILD WHERE CLAUSES & PARAMS ---
$where_parts  = [];
$param_types  = '';
$params       = [];

// Filters on budget_items (and related lookup tables)
if ($selected_year !== '') { 
    $where_parts[] = "bi.fiscal_year = ?";   
    $param_types .= 's'; // Changed from 'i' to 's' for string
    $params[] = $selected_year; 
}

if ($selected_project !== '')     { $where_parts[] = "bi.project_id = ?";    $param_types .= 'i'; $params[] = $selected_project; }
if ($selected_department !== '')  { $where_parts[] = "bi.department_id = ?"; $param_types .= 'i'; $params[] = $selected_department; }
if ($selected_cost_type !== '')   { $where_parts[] = "bi.cost_type_id = ?";  $param_types .= 'i'; $params[] = $selected_cost_type; }
if ($selected_parent_cat !== '')  { $where_parts[] = "pbc.id = ?";           $param_types .= 'i'; $params[] = $selected_parent_cat; }
if ($selected_child_cat !== '')   { $where_parts[] = "bc.id = ?";            $param_types .= 'i'; $params[] = $selected_child_cat; }

// Filters on actual_expenses (raw rows)
$ae_filter_parts = [];
$ae_param_types  = '';
$ae_params       = [];
if ($selected_vendor !== '')                { $ae_filter_parts[] = "ae.vendor_id = ?"; $ae_param_types .= 'i';  $ae_params[] = $selected_vendor; }
if ($date_from !== '' && $date_to !== '')   { $ae_filter_parts[] = "ae.transaction_date BETWEEN ? AND ?"; $ae_param_types .= 'ss'; $ae_params[] = $date_from; $ae_params[] = $date_to; }

// Merge AE filters into where clause
$where_parts = array_merge($where_parts, $ae_filter_parts);
$param_types .= $ae_param_types;
$params = array_merge($params, $ae_params);

$where_clause = '';
if (!empty($where_parts)) {
    $where_clause = ' WHERE ' . implode(' AND ', $where_parts);
}

// --- QUERY BUILDING ---
$report_data    = [];
$report_columns = []; // array of [label, key, type] to control rendering order & format
$sql            = '';

// Correlated subquery for totals per budget item (respects vendor/date filters)
$ae_total_correlated = "(SELECT COALESCE(SUM(ae2.amount),0) 
    FROM actual_expenses ae2 
    WHERE ae2.budget_item_id = bi.id";
if ($selected_vendor !== '')                { $ae_total_correlated .= " AND ae2.vendor_id = ?"; }
if ($date_from !== '' && $date_to !== '')   { $ae_total_correlated .= " AND ae2.transaction_date BETWEEN ? AND ?"; }
$ae_total_correlated .= ")";

// Select & column map per report type
switch ($selected_report_type) {
    case 'department_expenses':
        $sql = "
            SELECT 
                bi.id AS budget_item_id,
                d.department_name,
                bi.item_name,
                bi.fiscal_year,
                bi.total_budget_amount AS total_budget,
                COALESCE(SUM(ae.amount),0) AS total_actual,
                (bi.total_budget_amount - COALESCE(SUM(ae.amount),0)) AS remaining_budget,
                CASE WHEN bi.total_budget_amount > 0 
                     THEN ROUND((COALESCE(SUM(ae.amount),0)/bi.total_budget_amount)*100,2) 
                     ELSE 0 END AS percent_used,
                bc.category_name,
                pbc.parent_name AS parent_category
            FROM budget_items bi
            LEFT JOIN actual_expenses ae ON bi.id = ae.budget_item_id
            LEFT JOIN departments d ON bi.department_id = d.id
            LEFT JOIN budget_categories bc ON bi.budget_category_id = bc.id
            LEFT JOIN parent_budget_categories pbc ON bc.parent_id = pbc.id
            $where_clause
            GROUP BY 
                bi.id, d.department_name, bi.item_name, bi.fiscal_year, bi.total_budget_amount,
                bc.category_name, pbc.parent_name
            ORDER BY d.department_name, pbc.parent_name, bc.category_name, bi.item_name
        ";
        $report_columns = [
            ['Department',      'department_name', 'text'],
            ['Budget Item',     'item_name',       'text'],
            ['Fiscal Year',     'fiscal_year',     'text'],
            ['Budget',          'total_budget',    'currency'],
            ['Actual',          'total_actual',    'currency'],
            ['Remaining',       'remaining_budget','currency'],
            ['% Used',          'percent_used',    'percent'],
            ['Category',        'category_name',   'text'],
            ['Parent Category', 'parent_category', 'text']
        ];
        break;

    case 'category_expenses':
        $sql = "
            SELECT 
                bi.id AS budget_item_id,
                pbc.parent_name AS parent_category,
                bc.category_name,
                bi.item_name,
                bi.fiscal_year,
                d.department_name,
                bi.total_budget_amount AS total_budget,
                COALESCE(SUM(ae.amount),0) AS total_actual,
                (bi.total_budget_amount - COALESCE(SUM(ae.amount),0)) AS remaining_budget,
                CASE WHEN bi.total_budget_amount > 0 
                     THEN ROUND((COALESCE(SUM(ae.amount),0)/bi.total_budget_amount)*100,2) 
                     ELSE 0 END AS percent_used
            FROM budget_items bi
            LEFT JOIN actual_expenses ae ON bi.id = ae.budget_item_id
            LEFT JOIN departments d ON bi.department_id = d.id
            LEFT JOIN budget_categories bc ON bi.budget_category_id = bc.id
            LEFT JOIN parent_budget_categories pbc ON bc.parent_id = pbc.id
            $where_clause
            GROUP BY 
                bi.id, pbc.parent_name, bc.category_name, bi.item_name, bi.fiscal_year, d.department_name, bi.total_budget_amount
            ORDER BY pbc.parent_name, bc.category_name, bi.item_name
        ";
        $report_columns = [
            ['Parent Category', 'parent_category', 'text'],
            ['Category',        'category_name',   'text'],
            ['Budget Item',     'item_name',       'text'],
            ['Fiscal Year',     'fiscal_year',     'text'],
            ['Department',      'department_name', 'text'],
            ['Budget',          'total_budget',    'currency'],
            ['Actual',          'total_actual',    'currency'],
            ['Remaining',       'remaining_budget','currency'],
            ['% Used',          'percent_used',    'percent']
        ];
        break;

    case 'vendor_expenses':
        $sql = "
            SELECT 
                bi.id AS budget_item_id,
                v.vendor_name,
                bi.item_name,
                bi.fiscal_year,
                d.department_name,
                p.name AS project_name,
                bc.category_name,
                pbc.parent_name AS parent_category,
                bi.total_budget_amount AS total_budget,
                COALESCE(SUM(ae.amount),0) AS total_actual,
                (bi.total_budget_amount - COALESCE(SUM(ae.amount),0)) AS remaining_budget,
                CASE WHEN bi.total_budget_amount > 0 
                     THEN ROUND((COALESCE(SUM(ae.amount),0)/bi.total_budget_amount)*100,2) 
                     ELSE 0 END AS percent_used,
                MIN(ae.transaction_date) AS first_txn_date,
                MAX(ae.transaction_date) AS last_txn_date
            FROM budget_items bi
            LEFT JOIN actual_expenses ae ON bi.id = ae.budget_item_id
            LEFT JOIN departments d ON bi.department_id = d.id
            LEFT JOIN projects p ON bi.project_id = p.id
            LEFT JOIN budget_categories bc ON bi.budget_category_id = bc.id
            LEFT JOIN parent_budget_categories pbc ON bc.parent_id = pbc.id
            LEFT JOIN vendors v ON ae.vendor_id = v.id
            $where_clause
            GROUP BY 
                bi.id, v.vendor_name, bi.item_name, bi.fiscal_year, d.department_name, p.name,
                bc.category_name, pbc.parent_name, bi.total_budget_amount
            ORDER BY v.vendor_name, bi.item_name
        ";
        $report_columns = [
            ['Vendor',          'vendor_name',     'text'],
            ['Budget Item',     'item_name',       'text'],
            ['Fiscal Year',     'fiscal_year',     'text'],
            ['Department',      'department_name', 'text'],
            ['Project',         'project_name',    'text'],
            ['Category',        'category_name',   'text'],
            ['Parent Category', 'parent_category', 'text'],
            ['Budget',          'total_budget',    'currency'],
            ['Actual',          'total_actual',    'currency'],
            ['Remaining',       'remaining_budget','currency'],
            ['% Used',          'percent_used',    'percent'],
            ['First Txn',       'first_txn_date',  'date'],
            ['Last Txn',        'last_txn_date',   'date']
        ];
        break;

    case 'project_expenses':
        $sql = "
            SELECT 
                bi.id AS budget_item_id,
                p.name AS project_name,
                bi.item_name,
                bi.fiscal_year,
                d.department_name,
                bc.category_name,
                pbc.parent_name AS parent_category,
                bi.total_budget_amount AS total_budget,
                COALESCE(SUM(ae.amount),0) AS total_actual,
                (bi.total_budget_amount - COALESCE(SUM(ae.amount),0)) AS remaining_budget,
                CASE WHEN bi.total_budget_amount > 0 
                     THEN ROUND((COALESCE(SUM(ae.amount),0)/bi.total_budget_amount)*100,2) 
                     ELSE 0 END AS percent_used
            FROM budget_items bi
            LEFT JOIN actual_expenses ae ON bi.id = ae.budget_item_id
            LEFT JOIN departments d ON bi.department_id = d.id
            LEFT JOIN projects p ON bi.project_id = p.id
            LEFT JOIN budget_categories bc ON bi.budget_category_id = bc.id
            LEFT JOIN parent_budget_categories pbc ON bc.parent_id = pbc.id
            $where_clause
            GROUP BY 
                bi.id, p.name, bi.item_name, bi.fiscal_year, d.department_name, bc.category_name, pbc.parent_name, bi.total_budget_amount
            ORDER BY p.name, bi.item_name
        ";
        $report_columns = [
            ['Project',         'project_name',    'text'],
            ['Budget Item',     'item_name',       'text'],
            ['Fiscal Year',     'fiscal_year',     'text'],
            ['Department',      'department_name', 'text'],
            ['Category',        'category_name',   'text'],
            ['Parent Category', 'parent_category', 'text'],
            ['Budget',          'total_budget',    'currency'],
            ['Actual',          'total_actual',    'currency'],
            ['Remaining',       'remaining_budget','currency'],
            ['% Used',          'percent_used',    'percent']
        ];
        break;

    default: // budget_vs_actual (with raw details) — FIXED
        // Show per-expense row amount as "Actual", but compute Remaining/% using item-level totals (correlated subquery)
        $sql = "
            SELECT
                bi.id AS budget_item_id,
                bi.fiscal_year,
                p.name AS project_name,
                d.department_name,
                bc.category_name,
                pbc.parent_name AS parent_category,
                bi.item_name,
                bi.total_budget_amount AS total_budget,
                COALESCE(ae.amount, 0) AS expense_amount,                        -- row-level actual
                (bi.total_budget_amount - {$ae_total_correlated}) AS remaining_budget, -- item-level remaining
                CASE WHEN bi.total_budget_amount > 0 
                     THEN ROUND(({$ae_total_correlated} / bi.total_budget_amount) * 100, 2)
                     ELSE 0 END AS percent_used,                                  -- item-level percent
                ae.reference_number,
                v.vendor_name,
                ae.description,
                ae.payment_method,
                ae.transaction_date
            FROM budget_items bi
            LEFT JOIN actual_expenses ae ON bi.id = ae.budget_item_id
            LEFT JOIN projects p ON bi.project_id = p.id
            LEFT JOIN departments d ON bi.department_id = d.id
            LEFT JOIN budget_categories bc ON bi.budget_category_id = bc.id
            LEFT JOIN parent_budget_categories pbc ON bc.parent_id = pbc.id
            LEFT JOIN vendors v ON ae.vendor_id = v.id
            $where_clause
            ORDER BY bi.fiscal_year DESC, p.name, d.department_name, bc.category_name, bi.item_name, ae.transaction_date
        ";
        $report_columns = [
            ['Fiscal Year',     'fiscal_year',     'text'],
            ['Project',         'project_name',    'text'],
            ['Department',      'department_name', 'text'],
            ['Category',        'category_name',   'text'],
            ['Parent Category', 'parent_category', 'text'],
            ['Budget Item',     'item_name',       'text'],
            ['Budget',          'total_budget',    'currency'],
            ['Actual',          'expense_amount',  'currency'], // row-level
            ['Remaining',       'remaining_budget','currency'], // item-level (not row repeated in total)
            ['% Used',          'percent_used',    'percent'],  // item-level
            ['Reference',       'reference_number','text'],
            ['Vendor',          'vendor_name',     'text'],
            ['Description',     'description',     'text'],
            ['Payment Method',  'payment_method',  'text'],
            ['Transaction Date','transaction_date','date']
        ];
        break;
}

// --- Prepare & Execute statement ---
$stmt = $conn->prepare($sql);
if (!$stmt) {
    die('Error preparing statement: ' . $conn->error . ' | SQL: ' . $sql);
}

// Build final params list, including duplicated AE filters for the correlated subquery in default report
$final_types  = $param_types;
$final_params = $params;

if ($selected_report_type === 'budget_vs_actual') {
    // We used the correlated subquery twice (remaining, percent)
    $repeat = 2;
    for ($i = 0; $i < $repeat; $i++) {
        if ($selected_vendor !== '')              { $final_types .= 'i';  $final_params[] = $selected_vendor; }
        if ($date_from !== '' && $date_to !== '') { $final_types .= 'ss'; $final_params[] = $date_from; $final_params[] = $date_to; }
    }
}

if ($final_types !== '') {
    // Debug: Show parameter info
    error_log("SQL Parameters - Types: $final_types, Count: " . count($final_params));
    $stmt->bind_param($final_types, ...$final_params);
}
$stmt->execute();
$result = $stmt->get_result();

// --- Pull results & compute summaries ---
$report_rows = [];
$seen_budget_items = [];

while ($row = $result->fetch_assoc()) {
    $report_rows[] = $row;

    // Budget vs Actual (details): sum Budget once per budget_item_id; Actual is per-row expense_amount
    if ($selected_report_type === 'budget_vs_actual') {
        $bid = isset($row['budget_item_id']) ? (int)$row['budget_item_id'] : null;
        if ($bid !== null && !isset($seen_budget_items[$bid])) {
            $total_budget_summary += isset($row['total_budget']) ? (float)$row['total_budget'] : 0.0;
            $seen_budget_items[$bid] = true;
        }
        $total_actual_summary += isset($row['expense_amount']) ? (float)$row['expense_amount'] : 0.0;
    } else {
        // Aggregated reports: each row is one item/group
        $total_budget_summary += isset($row['total_budget']) ? (float)$row['total_budget'] : 0.0;
        $total_actual_summary += isset($row['total_actual']) ? (float)$row['total_actual'] : 0.0;
    }
}

$remaining_budget_summary = $total_budget_summary - $total_actual_summary;
$percent_used_summary     = $total_budget_summary > 0 ? round(($total_actual_summary / $total_budget_summary) * 100, 2) : 0;

$conn->close();

// --- Helper to format values in table/PDF ---
function format_cell($type, $value) {
    if ($value === null || $value === '') return 'N/A';
    switch ($type) {
        case 'currency':
            return '$' . number_format((float)$value, 2);
        case 'percent':
            return number_format((float)$value, 2) . '%';
        case 'date':
            // assume YYYY-MM-DD or datetime; print as-is if not parseable
            $ts = strtotime($value);
            return $ts ? date('Y-m-d', $ts) : htmlspecialchars((string)$value);
        default:
            return htmlspecialchars((string)$value);
    }
}

// --- Determine grouping key (to keep your group headers/footers feature) ---
function group_key_for_row($report_type, $row) {
    switch ($report_type) {
        case 'department_expenses':
            return $row['department_name'] ?? '';
        case 'category_expenses':
            return trim(($row['parent_category'] ?? '') . ' - ' . ($row['category_name'] ?? ''));
        case 'vendor_expenses':
            return $row['vendor_name'] ?? '';
        case 'project_expenses':
            return $row['project_name'] ?? '';
        default:
            // budget_vs_actual: group by fiscal year
            return $row['fiscal_year'] ?? '';
    }
}
?>

            <!-- Page content will be inserted here -->
            <div class="container-fluid">

                <div class="container-fluid py-4">
                    <div class="row">
                        <div class="col-12">
                            <h1 class="page-title mb-4">Budget Reporting System</h1>

                            <!-- ========================= Summary Cards ========================= -->
                            <div class="row mb-4">
                                <div class="col-md-4">
                                    <div class="card kpi-card text-white" style="background-color: #4e73df;">
                                        <div class="card-body text-center">
                                            <h5><i class="fas fa-wallet me-2"></i>Total Budget</h5>
                                            <h3>$<?= number_format($total_budget_summary, 2) ?></h3>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="card kpi-card text-white" style="background-color: #1cc88a;">
                                        <div class="card-body text-center">
                                            <h5><i class="fas fa-money-bill-wave me-2"></i>Total Actual Expenses</h5>
                                            <h3>$<?= number_format($total_actual_summary, 2) ?></h3>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="card kpi-card text-white" style="background-color: #36b9cc;">
                                        <div class="card-body text-center">
                                            <h5><i class="fas fa-piggy-bank me-2"></i>Remaining Budget</h5>
                                            <h3>$<?= number_format($remaining_budget_summary, 2) ?></h3>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- ========================= Filter Form ========================= -->
                            <div class="card card-dashen mb-4">
                                <div class="card-header-dashen d-flex align-items-center">
                                    <h5 class="mb-0"><i class="fas fa-filter me-2"></i>Report Filters</h5>
                                </div>
                                <div class="card-body">
                                    <form action="" method="GET" id="reportForm">
                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label">Report Type</label>
                                                <select name="report_type" class="form-select" onchange="this.form.submit()">
                                                    <option value="budget_vs_actual"   <?= $selected_report_type=='budget_vs_actual'   ? 'selected' : '' ?>>Budget vs Actual (with details)</option>
                                                    <option value="department_expenses"<?= $selected_report_type=='department_expenses'? 'selected' : '' ?>>Department Expenses</option>
                                                    <option value="category_expenses"  <?= $selected_report_type=='category_expenses'  ? 'selected' : '' ?>>Category Expenses</option>
                                                    <option value="vendor_expenses"    <?= $selected_report_type=='vendor_expenses'    ? 'selected' : '' ?>>Vendor Expenses</option>
                                                    <option value="project_expenses"   <?= $selected_report_type=='project_expenses'   ? 'selected' : '' ?>>Project Expenses</option>
                                                </select>
                                            </div>
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label">Fiscal Year</label>
                                                <select name="fiscal_year" class="form-select">
                                                    <option value="">All Years</option>
                                                    <?php foreach ($fiscal_years as $year): ?>
                                                        <option value="<?= htmlspecialchars($year) ?>" <?= ($selected_year == $year)? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($year) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>

                                        <div class="row">
                                            <div class="col-md-4 mb-3">
                                                <label class="form-label">Project</label>
                                                <select name="project_id" class="form-select">
                                                    <option value="">All Projects</option>
                                                    <?php foreach ($projects as $pr): ?>
                                                        <option value="<?= htmlspecialchars($pr['id']) ?>" <?= ($selected_project == $pr['id'])? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($pr['name']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-4 mb-3">
                                                <label class="form-label">Department</label>
                                                <select name="department_id" class="form-select">
                                                    <option value="">All Departments</option>
                                                    <?php foreach ($departments as $dep): ?>
                                                        <option value="<?= htmlspecialchars($dep['id']) ?>" <?= ($selected_department == $dep['id'])? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($dep['department_name']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-4 mb-3">
                                                <label class="form-label">Cost Type</label>
                                                <select name="cost_type_id" class="form-select">
                                                    <option value="">All Cost Types</option>
                                                    <?php foreach ($cost_types as $ct): ?>
                                                        <option value="<?= htmlspecialchars($ct['id']) ?>" <?= ($selected_cost_type == $ct['id'])? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($ct['name']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>

                                        <div class="row">
                                            <div class="col-md-4 mb-3">
                                                <label class="form-label">Parent Category</label>
                                                <select name="parent_category_id" class="form-select">
                                                    <option value="">All Parent Categories</option>
                                                    <?php foreach ($parent_categories as $pc): ?>
                                                        <option value="<?= htmlspecialchars($pc['id']) ?>" <?= ($selected_parent_cat == $pc['id'])? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($pc['parent_name']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-4 mb-3">
                                                <label class="form-label">Child Category</label>
                                                <select name="child_category_id" class="form-select">
                                                    <option value="">All Child Categories</option>
                                                    <?php foreach ($child_categories as $cc): ?>
                                                        <option value="<?= htmlspecialchars($cc['id']) ?>" <?= ($selected_child_cat == $cc['id'])? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($cc['parent_name'].' - '.$cc['category_name']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-4 mb-3">
                                                <label class="form-label">Vendor</label>
                                                <select name="vendor_id" class="form-select">
                                                    <option value="">All Vendors</option>
                                                    <?php foreach ($vendors as $ven): ?>
                                                        <option value="<?= htmlspecialchars($ven['id']) ?>" <?= ($selected_vendor == $ven['id'])? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($ven['vendor_name']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>

                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label">Date From</label>
                                                <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($date_from) ?>">
                                            </div>
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label">Date To</label>
                                                <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($date_to) ?>">
                                            </div>
                                        </div>

                                        <div class="d-flex gap-2">
                                            <button type="submit" class="btn btn-dashen"><i class="fas fa-check me-1"></i> Apply Filters</button>
                                            <button type="button" onclick="resetForm()" class="btn btn-secondary"><i class="fas fa-undo me-1"></i> Reset</button>
                                            <button type="button" onclick="exportToExcel()" class="btn btn-success"><i class="fas fa-file-excel me-1"></i> Export to Excel</button>
                                            <button type="button" onclick="window.print()" class="btn btn-info"><i class="fas fa-print me-1"></i> Print</button>
                                            <button type="button" onclick="exportPDF()" class="btn btn-danger"><i class="fas fa-file-pdf me-1"></i> Export to PDF</button>
                                        </div>
                                    </form>
                                </div>
                            </div>

                            <!-- ========================= Report Table ========================= -->
                            <div class="card card-dashen">
                                <div class="card-header-dashen d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0"><i class="fas fa-table me-2"></i>Report Results</h5>
                                    <span class="badge bg-light text-dark"><?= count($report_rows) ?> records found</span>
                                </div>
                                <div class="card-body p-0">
                                <?php if (!empty($report_rows)): ?>
                                    <div class="table-responsive">
                                        <table class="table table-striped table-hover mb-0" id="reportTable">
                                            <thead class="table-dark sticky-top">
                                                <tr>
                                                    <?php foreach ($report_columns as $col): ?>
                                                        <th><?= htmlspecialchars($col[0]) ?></th>
                                                    <?php endforeach; ?>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php
                                                $current_group       = '';
                                                $group_total_budget  = 0.0;
                                                $group_total_actual  = 0.0;
                                                $rowcount            = count($report_rows);

                                                foreach ($report_rows as $index => $row):
                                                    $group_value = group_key_for_row($selected_report_type, $row);

                                                    // If entering a new group, show previous group total (if not first) and render a header row
                                                    if ($group_value !== $current_group && $group_value !== '') {
                                                        if ($current_group !== '') {
                                                            // Close previous group with a total row
                                                            echo '<tr class="table-group-divider">';
                                                            // "Grand total" layout uses last 6 numeric columns in most reports:
                                                            // We will align to have Budget, Actual, Remaining, %Used at the right (where present).
                                                            // Count numeric columns present in this report
                                                            $numericCols = 0;
                                                            $hasPercent  = false;
                                                            foreach ($report_columns as $cdef) {
                                                                if ($cdef[2] === 'currency') $numericCols++;
                                                                if ($cdef[2] === 'percent')  { $numericCols++; $hasPercent = true; }
                                                            }
                                                            $colspanLeft = max(1, count($report_columns) - $numericCols);
                                                            echo '<td colspan="'.$colspanLeft.'" class="text-end fw-bold">Group Total:</td>';

                                                            // For numeric columns, output values only for Budget/Actual/Remaining/% Used if they exist, else blanks
                                                            foreach ($report_columns as $cdef) {
                                                                $type = $cdef[2];
                                                                if ($type === 'currency') {
                                                                    if ($cdef[1] === 'total_budget') {
                                                                        echo '<td class="fw-bold">$'.number_format($group_total_budget,2).'</td>';
                                                                    } elseif ($cdef[1] === 'total_actual' || $cdef[1] === 'expense_amount') {
                                                                        echo '<td class="fw-bold">$'.number_format($group_total_actual,2).'</td>';
                                                                    } elseif ($cdef[1] === 'remaining_budget') {
                                                                        echo '<td class="fw-bold">$'.number_format($group_total_budget - $group_total_actual,2).'</td>';
                                                                    } else {
                                                                        echo '<td></td>';
                                                                    }
                                                                } elseif ($type === 'percent') {
                                                                    $p = ($group_total_budget > 0) ? ($group_total_actual / $group_total_budget * 100) : 0;
                                                                    echo '<td class="fw-bold">'.number_format($p,2).'%</td>';
                                                                }
                                                            }
                                                            echo "</tr>";
                                                        }

                                                        // Reset group totals
                                                        $current_group      = $group_value;
                                                        $group_total_budget = 0.0;
                                                        $group_total_actual = 0.0;

                                                        echo '<tr class="table-primary"><td colspan="'.count($report_columns).'" class="fw-bold">';
                                                        echo '<i class="fas fa-folder me-2"></i>'.htmlspecialchars($group_value);
                                                        echo '</td></tr>';
                                                    }

                                                    // Accumulate group totals
                                                    // For detailed report: budget counted once per budget_item_id *within the group*?
                                                    // Simpler: add per row but ensure not to double count within group: we'll track per-row logic here same as global
                                                    if ($selected_report_type === 'budget_vs_actual') {
                                                        // Count budget once per budget_item_id per group — use a static array keyed by current group
                                                        static $seen_bi_per_group = [];
                                                        $gkey = $current_group . '|' . ($row['budget_item_id'] ?? 'x');
                                                        if (!isset($seen_bi_per_group[$gkey])) {
                                                            $group_total_budget += isset($row['total_budget']) ? (float)$row['total_budget'] : 0.0;
                                                            $seen_bi_per_group[$gkey] = true;
                                                        }
                                                        $group_total_actual += isset($row['expense_amount']) ? (float)$row['expense_amount'] : 0.0;
                                                    } else {
                                                        $group_total_budget += isset($row['total_budget']) ? (float)$row['total_budget'] : 0.0;
                                                        $group_total_actual += isset($row['total_actual']) ? (float)$row['total_actual'] : 0.0;
                                                    }

                                                    // Render the actual data row in the strict column order
                                                    echo '<tr>';
                                                    foreach ($report_columns as $coldef) {
                                                        list($label, $key, $type) = $coldef;
                                                        $val = isset($row[$key]) ? $row[$key] : null;
                                                        if ($type === 'percent') {
                                                            $class = ((float)$val > 90) ? 'negative-variance' : (((float)$val > 75) ? 'text-warning' : 'positive-variance');
                                                            echo '<td class="'.$class.'">'.format_cell('percent', $val).'</td>';
                                                        } else {
                                                            echo '<td>'.format_cell($type, $val).'</td>';
                                                        }
                                                    }
                                                    echo '</tr>';

                                                    // After last row, close the final group total
                                                    if ($index === $rowcount - 1) {
                                                        echo '<tr class="table-group-divider">';
                                                        $numericCols = 0;
                                                        $hasPercent  = false;
                                                        foreach ($report_columns as $cdef) {
                                                            if ($cdef[2] === 'currency') $numericCols++;
                                                            if ($cdef[2] === 'percent')  { $numericCols++; $hasPercent = true; }
                                                        }
                                                        $colspanLeft = max(1, count($report_columns) - $numericCols);
                                                        echo '<td colspan="'.$colspanLeft.'" class="text-end fw-bold">Group Total:</td>';
                                                        foreach ($report_columns as $cdef) {
                                                            $type = $cdef[2];
                                                            if ($type === 'currency') {
                                                                if ($cdef[1] === 'total_budget') {
                                                                    echo '<td class="fw-bold">$'.number_format($group_total_budget,2).'</td>';
                                                                } elseif ($cdef[1] === 'total_actual' || $cdef[1] === 'expense_amount') {
                                                                    echo '<td class="fw-bold">$'.number_format($group_total_actual,2).'</td>';
                                                                } elseif ($cdef[1] === 'remaining_budget') {
                                                                    echo '<td class="fw-bold">$'.number_format($group_total_budget - $group_total_actual,2).'</td>';
                                                                } else {
                                                                    echo '<td></td>';
                                                                }
                                                            } elseif ($type === 'percent') {
                                                                $p = ($group_total_budget > 0) ? ($group_total_actual / $group_total_budget * 100) : 0;
                                                                echo '<td class="fw-bold">'.number_format($p,2).'%</td>';
                                                            }
                                                        }
                                                        echo '</tr>';
                                                    }
                                                endforeach;
                                                ?>

                                                <!-- Grand Total Summary -->
                                                <tr class="summary-row">
                                                    <?php
                                                    // Place grand totals aligned with currency/percent columns
                                                    $numericCols = 0;
                                                    $hasPercent  = false;
                                                    foreach ($report_columns as $cdef) {
                                                        if ($cdef[2] === 'currency') $numericCols++;
                                                        if ($cdef[2] === 'percent')  { $numericCols++; $hasPercent = true; }
                                                    }
                                                    $colspanLeft = max(1, count($report_columns) - $numericCols);
                                                    ?>
                                                    <td colspan="<?= $colspanLeft ?>" class="text-end fw-bold">GRAND TOTAL:</td>
                                                    <?php
                                                    foreach ($report_columns as $cdef) {
                                                        $type = $cdef[2];
                                                        if ($type === 'currency') {
                                                            if ($cdef[1] === 'total_budget') {
                                                                echo '<td class="fw-bold">$'.number_format($total_budget_summary,2).'</td>';
                                                            } elseif ($cdef[1] === 'total_actual' || $cdef[1] === 'expense_amount') {
                                                                echo '<td class="fw-bold">$'.number_format($total_actual_summary,2).'</td>';
                                                            } elseif ($cdef[1] === 'remaining_budget') {
                                                                echo '<td class="fw-bold">$'.number_format($remaining_budget_summary,2).'</td>';
                                                            } else {
                                                                echo '<td></td>';
                                                            }
                                                        } elseif ($type === 'percent') {
                                                            echo '<td class="fw-bold">'.number_format($percent_used_summary,2).'%</td>';
                                                        }
                                                    }
                                                    ?>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-5">
                                        <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                        <h5 class="text-muted">No data found for the selected filters</h5>
                                        <p class="text-muted">Try adjusting your filter criteria</p>
                                    </div>
                                <?php endif; ?>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>

            </div><!-- /.container-fluid -->

        </div><!-- /.main-content -->
    </div><!-- /.container-fluid -->

    <!-- jsPDF + AutoTable for PDF export -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js"></script>

    <style>
    /* --- Cards, table visuals --- */
    .card-summary { transition: transform 0.3s; }
    .card-summary:hover { transform: translateY(-5px); box-shadow: 0 4px 8px rgba(0,0,0,0.1); }

    .table-responsive { max-height: 70vh; overflow-y: auto; }
    .table thead th { white-space: nowrap; }

    /* --- Conditional formatting --- */
    .positive-variance { color: #28a745; font-weight: 700; }
    .negative-variance { color: #dc3545; font-weight: 700; }
    .summary-row { font-weight: 700; background-color: #e9ecef; }

    /* --- Professional Report Header (Hidden on screen, visible in print/PDF) --- */
    .report-header {
        display: none;
        margin-bottom: 20px;
        border-bottom: 2px solid #333;
        padding-bottom: 15px;
        position: relative;
    }
    
    .report-header .company-logo {
        max-height: 80px;
        position: absolute;
        right: 0;
        top: 0;
    }
    
    .report-header .company-info {
        text-align: left;
    }
    
    .report-header .company-info h1 {
        margin: 0;
        font-size: 24px;
        color: #333;
    }
    
    .report-header .company-info .contact {
        font-size: 12px;
        color: #666;
        margin-top: 5px;
    }
    
    .report-header .report-meta {
        margin-top: 15px;
        font-size: 14px;
        color: #555;
        text-align: left;
    }

    /* --- Printing fixes: ensure all columns appear --- */
    @media print {
        @page { 
            size: A4 landscape; 
            margin: 15mm;
        }
        
        html, body { 
            width: 100%; 
            margin: 0;
            padding: 0;
        }
        
        body { 
            -webkit-print-color-adjust: exact !important; 
            print-color-adjust: exact !important;
            font-size: 10pt;
        }
        
        /* Show report header in print */
        .report-header {
            display: block !important;
        }
        
        /* Hide navigation and filters in print */
        .no-print, 
        .sidebar, 
        .navbar,
        .card-header-dashen .btn,
        #reportForm,
        .filter-section {
            display: none !important;
        }
        
        /* Ensure table displays properly */
        .table-responsive { 
            overflow: visible !important; 
        }
        
        table { 
            width: 100% !important; 
            table-layout: auto !important;
            font-size: 9pt;
        }
        
        th, td { 
            white-space: normal !important;
            padding: 4px !important;
        }
        
        .table-dark th { 
            color: #fff !important; 
            background-color: #212529 !important; 
        }
        
        /* Keep cards intact */
        .card, .btn, .form-select, input, .badge { 
            break-inside: avoid; 
            page-break-inside: avoid; 
        }
        
        /* Ensure table doesn't break across pages awkwardly */
        table tbody tr {
            page-break-inside: avoid;
        }
        
        /* Summary cards in print */
        .card-body {
            padding: 10px !important;
        }
        
        .kpi-card h3 {
            font-size: 16px !important;
        }
        
        .kpi-card h5 {
            font-size: 12px !important;
        }
    }

    /* Keep the header sticky inside scroll, also nice on print */
    .table-dark.sticky-top { position: sticky; top: 0; z-index: 2; }
    </style>

    <!-- Report Header Template (Hidden on screen) -->
    <div class="report-header no-print">
        <?php if (file_exists($company_logo_path)): ?>
            <img src="<?= $company_logo_path ?>" alt="<?= $company_name ?>" class="company-logo">
        <?php endif; ?>
        <div class="company-info">
            <h1><?= $company_name ?></h1>
            <div class="contact">
                <?= $company_address ?> | <?= $company_phone ?> | <?= $company_email ?>
            </div>
        </div>
        <div class="report-meta">
            <strong>Budget Report</strong> | 
            Generated: <?= date('F j, Y g:i A') ?> | 
            Report Type: <?= ucfirst(str_replace('_', ' ', $selected_report_type)) ?>
            <?php if ($selected_year): ?> | Fiscal Year: <?= $selected_year ?><?php endif; ?>
        </div>
    </div>

    <script>
    // ==================== Helper actions ====================
    function resetForm() {
        document.getElementById('reportForm').reset();
        window.location.href = window.location.pathname;
    }

    function exportToExcel() {
        const table = document.getElementById('reportTable');
        const html  = table.outerHTML;
        const url   = 'data:application/vnd.ms-excel;charset=utf-8,' + encodeURIComponent(html);
        const a     = document.createElement('a');
        a.href = url;
        a.download = 'budget_report_<?= date('Y-m-d') ?>.xls';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
    }

    // ==================== PDF Export (all columns visible) ====================
    function exportPDF() {
        const { jsPDF } = window.jspdf;
        const doc = new jsPDF('l', 'pt', 'a4');

        // Add company header to PDF - logo on right side
        const pageWidth = doc.internal.pageSize.getWidth();
        
        // Company name and info on left
        doc.setFontSize(16);
        doc.setFont(undefined, 'bold');
        doc.text('<?= $company_name ?>', 40, 30);
        
        doc.setFontSize(10);
        doc.setFont(undefined, 'normal');
        doc.text('<?= $company_address ?>', 40, 45);
        doc.text('<?= $company_phone ?> | <?= $company_email ?>', 40, 55);
        
        // Report title and metadata
        doc.setFontSize(12);
        doc.setFont(undefined, 'bold');
        doc.text('Budget Report - <?= ucfirst(str_replace('_', ' ', $selected_report_type)) ?>', 40, 75);
        
        doc.setFontSize(10);
        doc.setFont(undefined, 'normal');
        doc.text('Generated: <?= date('F j, Y g:i A') ?>', 40, 90);
        <?php if ($selected_year): ?>
        doc.text('Fiscal Year: <?= $selected_year ?>', 40, 102);
        <?php endif; ?>

        // Try to add logo from base64 or URL
        addLogoToPDF(doc, pageWidth).then(() => {
            // Continue with table after logo is added (or if it fails)
            generateTable(doc);
        }).catch(() => {
            // If logo fails, just generate table without logo
            generateTable(doc);
        });
    }

    function generateTable(doc) {
        const table   = document.getElementById('reportTable');
        const headRow = Array.from(table.querySelectorAll('thead th')).map(th => th.innerText.trim());
        const bodyRows = Array.from(table.querySelectorAll('tbody tr'))
            .filter(tr => !tr.classList.contains('table-group-divider')) // skip group divider rows in PDF table
            .map(tr => Array.from(tr.querySelectorAll('td')).map(td => td.innerText));

        // AutoTable with wrapped cells
        doc.autoTable({
            head: [headRow],
            body: bodyRows,
            startY: 120,
            styles: { fontSize: 8, cellPadding: 3, overflow: 'linebreak' },
            headStyles: { fillColor: [33,37,41], textColor: 255 }, // Bootstrap table-dark
            theme: 'grid',
            tableWidth: 'auto'
        });

        // Add footer with page numbers
        const pageCount = doc.internal.getNumberOfPages();
        for (let i = 1; i <= pageCount; i++) {
            doc.setPage(i);
            doc.setFontSize(8);
            doc.text(`Page ${i} of ${pageCount}`, doc.internal.pageSize.width - 50, doc.internal.pageSize.height - 10);
        }

        doc.save('<?= $company_name ?>_Budget_Report_<?= date('Y-m-d') ?>.pdf');
    }

    // Function to add logo to PDF
    function addLogoToPDF(doc, pageWidth) {
        return new Promise((resolve, reject) => {
            // Try multiple methods to get the logo
            
            // Method 1: Try to convert canvas to base64
            const logoImg = document.querySelector('.company-logo');
            if (logoImg && logoImg.complete && logoImg.naturalWidth > 0) {
                try {
                    const canvas = document.createElement('canvas');
                    const ctx = canvas.getContext('2d');
                    canvas.width = logoImg.naturalWidth;
                    canvas.height = logoImg.naturalHeight;
                    ctx.drawImage(logoImg, 0, 0);
                    
                    const dataURL = canvas.toDataURL('image/png');
                    doc.addImage(dataURL, 'PNG', pageWidth - 100, 20, 80, 40);
                    resolve();
                    return;
                } catch (e) {
                    console.log('Canvas method failed:', e);
                }
            }
            
            // Method 2: If we have a URL, try to use it
            const logoUrl = '<?= $company_logo_url ?>';
            if (logoUrl && logoUrl.startsWith('http')) {
                try {
                    doc.addImage(logoUrl, 'PNG', pageWidth - 100, 20, 80, 40);
                    resolve();
                    return;
                } catch (e) {
                    console.log('URL method failed:', e);
                }
            }
            
            // Method 3: Create a simple text-based logo
            doc.setFontSize(10);
            doc.setFont(undefined, 'bold');
            doc.text('DASHEN BANK', pageWidth - 80, 40);
            doc.setFontSize(8);
            doc.text('S.C.', pageWidth - 40, 45);
            resolve();
        });
    }

    // Enhance print functionality
    window.onbeforeprint = function() {
        // Add report header to print
        const reportHeader = document.querySelector('.report-header');
        if (reportHeader) {
            reportHeader.style.display = 'block';
        }
    };

    window.onafterprint = function() {
        // Hide report header after printing
        const reportHeader = document.querySelector('.report-header');
        if (reportHeader) {
            reportHeader.style.display = 'none';
        }
    };
    </script>

</body>
</html>