<?php
// project_intake_form.php
require_once 'includes/header.php';

// Define sanitize_input function if not exists
if (!function_exists('sanitize_input')) {
    function sanitize_input($data) {
        global $conn;
        if (isset($conn)) {
            $data = mysqli_real_escape_string($conn, trim($data));
        }
        $data = stripslashes($data);
        $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
        return $data;
    }
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'] ?? '';
$user_role = $_SESSION['system_role'] ?? '';

// Check permissions
if (!in_array($user_role, ['super_admin', 'pm_manager', 'pm_employee'])) {
    die("You don't have permission to access this page.");
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_intake'])) {
    // Collect form data
    $data = [
        'project_name' => sanitize_input($_POST['project_name'] ?? ''),
        'department_id' => intval($_POST['department_id'] ?? 0),
        'business_sponsor_name' => sanitize_input($_POST['business_sponsor_name'] ?? ''),
        'business_sponsor_role' => sanitize_input($_POST['business_sponsor_role'] ?? ''),
        'project_champion_name' => sanitize_input($_POST['project_champion_name'] ?? ''),
        'project_champion_role' => sanitize_input($_POST['project_champion_role'] ?? ''),
        'proposed_start_date' => sanitize_input($_POST['proposed_start_date'] ?? ''),
        'proposed_end_date' => sanitize_input($_POST['proposed_end_date'] ?? ''),
        'business_challenge' => sanitize_input($_POST['business_challenge'] ?? ''),
        'strategic_goals' => sanitize_input($_POST['strategic_goals'] ?? ''),
        'consequences_if_not_implemented' => sanitize_input($_POST['consequences_if_not_implemented'] ?? ''),
        'success_kpis' => sanitize_input($_POST['success_kpis'] ?? ''),
        'proposed_system_name' => sanitize_input($_POST['proposed_system_name'] ?? ''),
        'primary_business_capability' => sanitize_input($_POST['primary_business_capability'] ?? ''),
        'existing_systems_similar' => sanitize_input($_POST['existing_systems_similar'] ?? 'No'),
        'existing_systems_list' => sanitize_input($_POST['existing_systems_list'] ?? ''),
        'justification_new_system' => sanitize_input($_POST['justification_new_system'] ?? ''),
        'business_unit_owner' => sanitize_input($_POST['business_unit_owner'] ?? ''),
        'benefit_types' => isset($_POST['benefit_types']) ? implode(', ', $_POST['benefit_types']) : '',
        'expected_benefits' => sanitize_input($_POST['expected_benefits'] ?? ''),
        'quantifiable_benefits' => sanitize_input($_POST['quantifiable_benefits'] ?? ''),
        'benefit_responsible' => sanitize_input($_POST['benefit_responsible'] ?? ''),
        'requirements_document_attached' => sanitize_input($_POST['requirements_document_attached'] ?? 'No'),
        'key_functional_requirements' => sanitize_input($_POST['key_functional_requirements'] ?? ''),
        'impacted_processes' => sanitize_input($_POST['impacted_processes'] ?? ''),
        'out_of_scope' => sanitize_input($_POST['out_of_scope'] ?? ''),
        'dependencies_constraints' => sanitize_input($_POST['dependencies_constraints'] ?? ''),
        'estimated_total_budget' => floatval($_POST['estimated_total_budget'] ?? 0),
        'budget_approval_obtained' => sanitize_input($_POST['budget_approval_obtained'] ?? 'No'),
        'external_vendors_required' => sanitize_input($_POST['external_vendors_required'] ?? 'No'),
        'identified_risks' => sanitize_input($_POST['identified_risks'] ?? ''),
        'overall_risk_rating' => sanitize_input($_POST['overall_risk_rating'] ?? 'Low'),
        'compliance_regulatory_implications' => sanitize_input($_POST['compliance_regulatory_implications'] ?? ''),
        'cybersecurity_concerns' => sanitize_input($_POST['cybersecurity_concerns'] ?? ''),
        'team_ready_for_assessment' => sanitize_input($_POST['team_ready_for_assessment'] ?? 'No'),
        'internal_resources_required' => sanitize_input($_POST['internal_resources_required'] ?? ''),
        'execution_challenges' => sanitize_input($_POST['execution_challenges'] ?? ''),
        'signed_business_case' => isset($_POST['signed_business_case']) ? 'Yes' : 'No',
        'requirements_document' => isset($_POST['requirements_document']) ? 'Yes' : 'No',
        'benefit_management_plan' => isset($_POST['benefit_management_plan']) ? 'Yes' : 'No',
        'risk_assessment_matrix' => isset($_POST['risk_assessment_matrix']) ? 'Yes' : 'No',
        'budget_spreadsheet' => isset($_POST['budget_spreadsheet']) ? 'Yes' : 'No',
        'endorsement_letter' => isset($_POST['endorsement_letter']) ? 'Yes' : 'No',
        'diagrams_process_maps' => isset($_POST['diagrams_process_maps']) ? 'Yes' : 'No',
        'status' => 'Submitted',
        'submitted_by' => $user_id,
        'submitted_date' => date('Y-m-d H:i:s')
    ];
    
    // Insert into database
    $columns = implode(', ', array_keys($data));
    $placeholders = implode(', ', array_fill(0, count($data), '?'));
    
    $sql = "INSERT INTO project_intakes ($columns) VALUES ($placeholders)";
    $stmt = mysqli_prepare($conn, $sql);
    
    if ($stmt) {
        // Bind parameters
        $types = str_repeat('s', count($data));
        $params = array_values($data);
        mysqli_stmt_bind_param($stmt, $types, ...$params);
        
        if (mysqli_stmt_execute($stmt)) {
            $intake_id = mysqli_insert_id($conn);
            $_SESSION['success'] = "Project intake submitted successfully! Reference ID: PI-" . str_pad($intake_id, 6, '0', STR_PAD_LEFT);
            echo '<script>
                setTimeout(function() {
                    window.location.href = "project_intake_list.php";
                }, 2000);
            </script>';
        } else {
            $error = "Error submitting form: " . mysqli_error($conn);
        }
        
        mysqli_stmt_close($stmt);
    } else {
        $error = "Database error: " . mysqli_error($conn);
    }
}

// Fetch departments
$departments = [];
$dept_query = "SELECT id, department_name FROM departments ORDER BY department_name";
$dept_result = mysqli_query($conn, $dept_query);
if ($dept_result) {
    while ($row = mysqli_fetch_assoc($dept_result)) {
        $departments[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Project Intake Form - Dashen Bank BSPMD</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    
    <style>
        :root {
            --dashen-blue: #0033a0;
            --dashen-green: #00a859;
            --dashen-yellow: #ffd100;
            --dashen-red: #e4002b;
            --dashen-light: #f5f7fa;
            --dashen-dark: #001a4d;
        }
        
        body {
            background: linear-gradient(135deg, var(--dashen-light) 0%, #e3e9f7 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .navbar-brand {
            font-weight: 700;
            color: white !important;
        }
        
        .btn-dashen {
            background: linear-gradient(135deg, var(--dashen-blue), var(--dashen-dark));
            color: white;
            border: none;
            padding: 10px 25px;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-dashen:hover {
            background: linear-gradient(135deg, var(--dashen-dark), var(--dashen-blue));
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 51, 160, 0.3);
        }
        
        .btn-dashen-outline {
            border: 2px solid var(--dashen-blue);
            color: var(--dashen-blue);
            background: transparent;
            padding: 10px 25px;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-dashen-outline:hover {
            background: var(--dashen-blue);
            color: white;
            transform: translateY(-2px);
        }
        
        .form-wizard-container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            padding: 30px;
            margin-top: 30px;
        }
        
        .wizard-header {
            background: linear-gradient(135deg, var(--dashen-blue), var(--dashen-dark));
            color: white;
            padding: 25px;
            border-radius: 10px 10px 0 0;
            margin: -30px -30px 30px -30px;
        }
        
        .wizard-steps {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
            position: relative;
        }
        
        .wizard-steps::before {
            content: '';
            position: absolute;
            top: 20px;
            left: 0;
            right: 0;
            height: 3px;
            background: #e9ecef;
            z-index: 1;
        }
        
        .wizard-step {
            position: relative;
            z-index: 2;
            text-align: center;
            flex: 1;
        }
        
        .step-circle {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #e9ecef;
            color: #6c757d;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 10px;
            font-weight: 700;
            border: 3px solid white;
            transition: all 0.3s ease;
        }
        
        .wizard-step.active .step-circle {
            background: var(--dashen-green);
            color: white;
            transform: scale(1.1);
        }
        
        .wizard-step.completed .step-circle {
            background: var(--dashen-blue);
            color: white;
        }
        
        .step-label {
            font-size: 0.85rem;
            color: #6c757d;
            font-weight: 600;
        }
        
        .wizard-step.active .step-label {
            color: var(--dashen-green);
        }
        
        .wizard-step.completed .step-label {
            color: var(--dashen-blue);
        }
        
        .form-section {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 25px;
            margin-bottom: 25px;
            border-left: 4px solid var(--dashen-blue);
            transition: all 0.3s ease;
        }
        
        .form-section:hover {
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            transform: translateY(-2px);
        }
        
        .section-title {
            color: var(--dashen-blue);
            font-weight: 700;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            font-size: 1.2rem;
        }
        
        .section-title i {
            margin-right: 10px;
            background: rgba(0, 51, 160, 0.1);
            padding: 10px;
            border-radius: 50%;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--dashen-blue);
            box-shadow: 0 0 0 0.25rem rgba(0, 51, 160, 0.25);
        }
        
        .required::after {
            content: " *";
            color: var(--dashen-red);
        }
        
        .kpi-item {
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 10px;
        }
        
        .risk-matrix {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin: 15px 0;
        }
        
        .risk-cell {
            padding: 15px;
            text-align: center;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 600;
        }
        
        .risk-cell:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
        
        .risk-low {
            background: #d4edda;
            color: #155724;
            border: 2px solid transparent;
        }
        
        .risk-medium {
            background: #fff3cd;
            color: #856404;
            border: 2px solid transparent;
        }
        
        .risk-high {
            background: #f8d7da;
            color: #721c24;
            border: 2px solid transparent;
        }
        
        .risk-cell.active {
            border: 2px solid var(--dashen-blue);
            transform: scale(1.05);
        }
        
        .attachment-card {
            border: 2px dashed #dee2e6;
            border-radius: 10px;
            padding: 30px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            background: #f8f9fa;
        }
        
        .attachment-card:hover {
            border-color: var(--dashen-blue);
            background: rgba(0, 51, 160, 0.05);
        }
        
        .attachment-icon {
            font-size: 3rem;
            color: var(--dashen-blue);
            margin-bottom: 15px;
        }
        
        .attachment-list {
            list-style: none;
            padding: 0;
            margin-top: 15px;
        }
        
        .attachment-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 15px;
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            margin-bottom: 8px;
        }
        
        .preview-modal .modal-content {
            border-radius: 15px;
            overflow: hidden;
        }
        
        .preview-header {
            background: linear-gradient(135deg, var(--dashen-blue), var(--dashen-dark));
            color: white;
            padding: 20px;
        }
        
        .preview-section {
            margin-bottom: 25px;
            padding-bottom: 25px;
            border-bottom: 1px solid #dee2e6;
        }
        
        .preview-label {
            font-weight: 600;
            color: var(--dashen-blue);
            margin-bottom: 5px;
        }
        
        .preview-value {
            color: #495057;
        }
        
        .form-navigation {
            padding: 20px 0;
            border-top: 1px solid #dee2e6;
            margin-top: 20px;
        }
        
        /* Error styling */
        .error-message {
            color: var(--dashen-red);
            font-size: 0.875rem;
            margin-top: 5px;
            display: none;
        }
        
        .form-control.is-invalid {
            border-color: var(--dashen-red);
        }
        
        .form-control.is-invalid:focus {
            box-shadow: 0 0 0 0.25rem rgba(228, 0, 43, 0.25);
        }
        
        @media (max-width: 768px) {
            .wizard-steps {
                flex-direction: column;
                align-items: center;
            }
            
            .wizard-step {
                margin-bottom: 20px;
            }
            
            .wizard-steps::before {
                display: none;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-dark" style="background: linear-gradient(135deg, var(--dashen-blue), var(--dashen-dark));">
        <div class="container">
            <a class="navbar-brand" href="dashboard.php">
                <img src="https://www.dashenbanksc.com/sites/default/files/dashen-logo-white.png" height="30" class="d-inline-block align-top me-2" alt="Dashen Bank">
                BSPMD - Project Intake
            </a>
            <div class="d-flex align-items-center">
                <span class="text-white me-3">Welcome, <?php echo htmlspecialchars($username); ?></span>
                <a href="logout.php" class="btn btn-outline-light btn-sm">
                    <i class="fas fa-sign-out-alt me-1"></i>Logout
                </a>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="container">
        <div class="form-wizard-container">
            <div class="wizard-header">
                <h2 class="mb-2"><i class="fas fa-file-alt me-2"></i>Project Intake Form</h2>
                <p class="mb-0">Complete all sections to submit a new project initiative for BSPMD review</p>
            </div>
            
            <!-- Error/Success Messages -->
            <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i>
                <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <?php if (isset($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <!-- Wizard Steps -->
            <div class="wizard-steps">
                <div class="wizard-step active" data-step="1">
                    <div class="step-circle">1</div>
                    <div class="step-label">Basic Info</div>
                </div>
                <div class="wizard-step" data-step="2">
                    <div class="step-circle">2</div>
                    <div class="step-label">Strategy</div>
                </div>
                <div class="wizard-step" data-step="3">
                    <div class="step-circle">3</div>
                    <div class="step-label">System</div>
                </div>
                <div class="wizard-step" data-step="4">
                    <div class="step-circle">4</div>
                    <div class="step-label">Benefits</div>
                </div>
                <div class="wizard-step" data-step="5">
                    <div class="step-circle">5</div>
                    <div class="step-label">Requirements</div>
                </div>
                <div class="wizard-step" data-step="6">
                    <div class="step-circle">6</div>
                    <div class="step-label">Financial</div>
                </div>
                <div class="wizard-step" data-step="7">
                    <div class="step-circle">7</div>
                    <div class="step-label">Risk</div>
                </div>
                <div class="wizard-step" data-step="8">
                    <div class="step-circle">8</div>
                    <div class="step-label">Readiness</div>
                </div>
                <div class="wizard-step" data-step="9">
                    <div class="step-circle">9</div>
                    <div class="step-label">Attachments</div>
                </div>
            </div>
            
            <!-- Main Form -->
            <form id="projectIntakeForm" method="POST" action="">
                <input type="hidden" name="submit_intake" value="1">
                
                <!-- Step 1: Basic Information -->
                <div class="form-step active" id="step1">
                    <div class="form-section">
                        <h4 class="section-title"><i class="fas fa-info-circle"></i> Section I: Basic Project Information</h4>
                        
                        <div class="row g-3">
                            <div class="col-md-12">
                                <label class="form-label required">1. Project Name</label>
                                <input type="text" class="form-control" name="project_name" required 
                                       placeholder="Enter project name" maxlength="255">
                                <div class="error-message" id="error-project_name">Please enter a project name</div>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label required">2. Department Requesting</label>
                                <select class="form-select" name="department_id" required>
                                    <option value="">Select Department</option>
                                    <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo $dept['id']; ?>">
                                        <?php echo htmlspecialchars($dept['department_name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="error-message" id="error-department_id">Please select a department</div>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">3. Business Sponsor (Name)</label>
                                <input type="text" class="form-control" name="business_sponsor_name" 
                                       placeholder="Enter name">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Business Sponsor Role</label>
                                <input type="text" class="form-control" name="business_sponsor_role" 
                                       placeholder="Enter role">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">4. Project Champion (if different)</label>
                                <input type="text" class="form-control" name="project_champion_name" 
                                       placeholder="Enter name">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Project Champion Role</label>
                                <input type="text" class="form-control" name="project_champion_role" 
                                       placeholder="Enter role">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label required">5. Proposed Start Date</label>
                                <input type="date" class="form-control" name="proposed_start_date" required 
                                       min="<?php echo date('Y-m-d'); ?>">
                                <div class="error-message" id="error-proposed_start_date">Please select a start date</div>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label required">Proposed End Date</label>
                                <input type="date" class="form-control" name="proposed_end_date" required 
                                       min="<?php echo date('Y-m-d'); ?>">
                                <div class="error-message" id="error-proposed_end_date">Please select an end date</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-navigation">
                        <div class="d-flex justify-content-between">
                            <button type="button" class="btn btn-dashen-outline" id="prevBtn1" disabled>
                                <i class="fas fa-arrow-left me-1"></i> Previous
                            </button>
                            <button type="button" class="btn btn-dashen" id="nextBtn1" data-next="2">
                                Next <i class="fas fa-arrow-right ms-1"></i>
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Step 2: Strategic Alignment -->
                <div class="form-step" id="step2" style="display: none;">
                    <div class="form-section">
                        <h4 class="section-title"><i class="fas fa-bullseye"></i> Section II: Strategic Alignment</h4>
                        
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label required">6. What business challenge or opportunity does this project address?</label>
                                <textarea class="form-control" name="business_challenge" rows="4" required 
                                          placeholder="Describe the business challenge or opportunity"></textarea>
                                <div class="error-message" id="error-business_challenge">Please describe the business challenge</div>
                            </div>
                            
                            <div class="col-12">
                                <label class="form-label required">7. Which strategic goal(s) of the bank does it support?</label>
                                <textarea class="form-control" name="strategic_goals" rows="4" required 
                                          placeholder="List the strategic goals supported"></textarea>
                                <div class="error-message" id="error-strategic_goals">Please list strategic goals</div>
                            </div>
                            
                            <div class="col-12">
                                <label class="form-label required">8. What are the consequences if this project is not implemented?</label>
                                <textarea class="form-control" name="consequences_if_not_implemented" rows="4" required 
                                          placeholder="Describe the consequences"></textarea>
                                <div class="error-message" id="error-consequences_if_not_implemented">Please describe the consequences</div>
                            </div>
                            
                            <div class="col-12">
                                <label class="form-label required">9. What measurable KPIs will be used to track success?</label>
                                <div id="kpi-container">
                                    <div class="kpi-item">
                                        <div class="row g-2">
                                            <div class="col-md-6">
                                                <input type="text" class="form-control kpi-name" 
                                                       placeholder="KPI Name (e.g., Customer Satisfaction)">
                                            </div>
                                            <div class="col-md-4">
                                                <input type="text" class="form-control kpi-target" 
                                                       placeholder="Target (e.g., 95%)">
                                            </div>
                                            <div class="col-md-2">
                                                <button type="button" class="btn btn-danger btn-sm w-100 remove-kpi">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <button type="button" class="btn btn-outline-primary btn-sm mt-2" id="add-kpi">
                                    <i class="fas fa-plus me-1"></i> Add KPI
                                </button>
                                <textarea class="form-control d-none" name="success_kpis" rows="4"></textarea>
                                <div class="error-message" id="error-success_kpis">Please add at least one KPI</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-navigation">
                        <div class="d-flex justify-content-between">
                            <button type="button" class="btn btn-dashen-outline" id="prevBtn2" data-prev="1">
                                <i class="fas fa-arrow-left me-1"></i> Previous
                            </button>
                            <button type="button" class="btn btn-dashen" id="nextBtn2" data-next="3">
                                Next <i class="fas fa-arrow-right ms-1"></i>
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Step 3: System Alignment -->
                <div class="form-step" id="step3" style="display: none;">
                    <div class="form-section">
                        <h4 class="section-title"><i class="fas fa-server"></i> Section III: System Alignment & Duplication Check</h4>
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">1. System or Platform Being Proposed</label>
                                <input type="text" class="form-control" name="proposed_system_name" 
                                       placeholder="Name of system/platform">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">2. Primary Business Capability Addressed</label>
                                <input type="text" class="form-control" name="primary_business_capability" 
                                       placeholder="Core function or process">
                            </div>
                            
                            <div class="col-12">
                                <label class="form-label">3. Are there existing systems performing similar function?</label>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="existing_systems_similar" 
                                           id="existingYes" value="Yes">
                                    <label class="form-check-label" for="existingYes">Yes</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="existing_systems_similar" 
                                           id="existingNo" value="No" checked>
                                    <label class="form-check-label" for="existingNo">No</label>
                                </div>
                            </div>
                            
                            <div class="col-12">
                                <label class="form-label">5. If Yes, List Existing Systems/Platforms/Solutions</label>
                                <textarea class="form-control" name="existing_systems_list" rows="4" 
                                          placeholder="Include names, departments using them, and extent of overlap"></textarea>
                            </div>
                            
                            <div class="col-12">
                                <label class="form-label">6. Justification for New System</label>
                                <textarea class="form-control" name="justification_new_system" rows="4" 
                                          placeholder="Explain functional gaps, cost drivers, or usability issues"></textarea>
                            </div>
                            
                            <div class="col-12">
                                <label class="form-label">7. Business Unit Ownership</label>
                                <textarea class="form-control" name="business_unit_owner" rows="3" 
                                          placeholder="Who owns this request and who will manage the proposed system post-implementation?"></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-navigation">
                        <div class="d-flex justify-content-between">
                            <button type="button" class="btn btn-dashen-outline" id="prevBtn3" data-prev="2">
                                <i class="fas fa-arrow-left me-1"></i> Previous
                            </button>
                            <button type="button" class="btn btn-dashen" id="nextBtn3" data-next="4">
                                Next <i class="fas fa-arrow-right ms-1"></i>
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Step 4: Benefit Realization -->
                <div class="form-step" id="step4" style="display: none;">
                    <div class="form-section">
                        <h4 class="section-title"><i class="fas fa-chart-line"></i> Section IV: Benefit Realization</h4>
                        
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label">1. What type of benefits does the project offer?</label>
                                <div class="row">
                                    <?php 
                                    $benefit_types = ['Financial', 'Operational', 'Risk Mitigation', 'Compliance', 'Customer Experience', 'Strategic'];
                                    foreach ($benefit_types as $type): 
                                    ?>
                                    <div class="col-md-4 mb-2">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="benefit_types[]" 
                                                   value="<?php echo $type; ?>" id="benefit<?php echo $type; ?>">
                                            <label class="form-check-label" for="benefit<?php echo $type; ?>">
                                                <?php echo $type; ?>
                                            </label>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            
                            <div class="col-12">
                                <label class="form-label">2. Describe the expected benefits in detail</label>
                                <textarea class="form-control" name="expected_benefits" rows="4" 
                                          placeholder="Describe benefits in detail"></textarea>
                            </div>
                            
                            <div class="col-12">
                                <label class="form-label">3. Can these benefits be quantified? Provide estimates if available</label>
                                <textarea class="form-control" name="quantifiable_benefits" rows="4" 
                                          placeholder="Provide quantifiable estimates"></textarea>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">4. Who is responsible for tracking benefit realization?</label>
                                <input type="text" class="form-control" name="benefit_responsible" 
                                       placeholder="Enter responsible person/team">
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-navigation">
                        <div class="d-flex justify-content-between">
                            <button type="button" class="btn btn-dashen-outline" id="prevBtn4" data-prev="3">
                                <i class="fas fa-arrow-left me-1"></i> Previous
                            </button>
                            <button type="button" class="btn btn-dashen" id="nextBtn4" data-next="5">
                                Next <i class="fas fa-arrow-right ms-1"></i>
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Step 5: Requirements & Scope -->
                <div class="form-step" id="step5" style="display: none;">
                    <div class="form-section">
                        <h4 class="section-title"><i class="fas fa-tasks"></i> Section V: Requirements & Scope</h4>
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">1. Have you attached a detailed requirements document?</label>
                                <select class="form-select" name="requirements_document_attached">
                                    <option value="No" selected>No</option>
                                    <option value="Yes">Yes</option>
                                </select>
                            </div>
                            
                            <div class="col-12">
                                <label class="form-label">2. What are the key functional requirements?</label>
                                <textarea class="form-control" name="key_functional_requirements" rows="4" 
                                          placeholder="List key functional requirements"></textarea>
                            </div>
                            
                            <div class="col-12">
                                <label class="form-label">3. What processes will be impacted?</label>
                                <textarea class="form-control" name="impacted_processes" rows="4" 
                                          placeholder="Describe impacted processes"></textarea>
                            </div>
                            
                            <div class="col-12">
                                <label class="form-label">4. What is out of scope for this project?</label>
                                <textarea class="form-control" name="out_of_scope" rows="4" 
                                          placeholder="Define what is out of scope"></textarea>
                            </div>
                            
                            <div class="col-12">
                                <label class="form-label">5. Are there any dependencies or constraints?</label>
                                <textarea class="form-control" name="dependencies_constraints" rows="4" 
                                          placeholder="List dependencies and constraints"></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-navigation">
                        <div class="d-flex justify-content-between">
                            <button type="button" class="btn btn-dashen-outline" id="prevBtn5" data-prev="4">
                                <i class="fas fa-arrow-left me-1"></i> Previous
                            </button>
                            <button type="button" class="btn btn-dashen" id="nextBtn5" data-next="6">
                                Next <i class="fas fa-arrow-right ms-1"></i>
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Step 6: Financial Overview -->
                <div class="form-step" id="step6" style="display: none;">
                    <div class="form-section">
                        <h4 class="section-title"><i class="fas fa-money-bill-wave"></i> Section VI: Financial Overview</h4>
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">1. What is the estimated total budget for the project? (USD)</label>
                                <input type="number" class="form-control" name="estimated_total_budget" 
                                       step="0.01" min="0" placeholder="0.00" id="totalBudget">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">2. Has budget approval been obtained?</label>
                                <select class="form-select" name="budget_approval_obtained">
                                    <option value="No" selected>No</option>
                                    <option value="Yes">Yes</option>
                                </select>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">3. Are any external vendors required?</label>
                                <select class="form-select" name="external_vendors_required">
                                    <option value="No" selected>No</option>
                                    <option value="Yes">Yes</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-navigation">
                        <div class="d-flex justify-content-between">
                            <button type="button" class="btn btn-dashen-outline" id="prevBtn6" data-prev="5">
                                <i class="fas fa-arrow-left me-1"></i> Previous
                            </button>
                            <button type="button" class="btn btn-dashen" id="nextBtn6" data-next="7">
                                Next <i class="fas fa-arrow-right ms-1"></i>
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Step 7: Risk & Compliance -->
                <div class="form-step" id="step7" style="display: none;">
                    <div class="form-section">
                        <h4 class="section-title"><i class="fas fa-exclamation-triangle"></i> Section VII: Risk & Compliance</h4>
                        
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label">1. What risks have been identified at this stage?</label>
                                <textarea class="form-control" name="identified_risks" rows="4" 
                                          placeholder="Describe identified risks"></textarea>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">2. What is the overall risk rating?</label>
                                <select class="form-select" name="overall_risk_rating" id="riskRating">
                                    <option value="Low" selected>Low</option>
                                    <option value="Medium">Medium</option>
                                    <option value="High">High</option>
                                </select>
                            </div>
                            
                            <div class="col-12">
                                <label class="form-label mb-3">Risk Matrix Visualization</label>
                                <div class="risk-matrix">
                                    <div class="risk-cell risk-low" data-rating="Low">Low Risk</div>
                                    <div class="risk-cell risk-medium" data-rating="Medium">Medium Risk</div>
                                    <div class="risk-cell risk-high" data-rating="High">High Risk</div>
                                </div>
                            </div>
                            
                            <div class="col-12">
                                <label class="form-label">3. Does the project have any compliance or regulatory implications?</label>
                                <textarea class="form-control" name="compliance_regulatory_implications" rows="4" 
                                          placeholder="Describe compliance/regulatory implications"></textarea>
                            </div>
                            
                            <div class="col-12">
                                <label class="form-label">4. Are cybersecurity or data protection concerns anticipated?</label>
                                <textarea class="form-control" name="cybersecurity_concerns" rows="4" 
                                          placeholder="Describe cybersecurity/data protection concerns"></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-navigation">
                        <div class="d-flex justify-content-between">
                            <button type="button" class="btn btn-dashen-outline" id="prevBtn7" data-prev="6">
                                <i class="fas fa-arrow-left me-1"></i> Previous
                            </button>
                            <button type="button" class="btn btn-dashen" id="nextBtn7" data-next="8">
                                Next <i class="fas fa-arrow-right ms-1"></i>
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Step 8: Readiness & Implementation -->
                <div class="form-step" id="step8" style="display: none;">
                    <div class="form-section">
                        <h4 class="section-title"><i class="fas fa-rocket"></i> Section VIII: Readiness & Implementation</h4>
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">1. Is your team ready to hand over the project for BSPMD assessment?</label>
                                <select class="form-select" name="team_ready_for_assessment">
                                    <option value="No" selected>No</option>
                                    <option value="Yes">Yes</option>
                                </select>
                            </div>
                            
                            <div class="col-12">
                                <label class="form-label">2. What internal resources are required for implementation?</label>
                                <textarea class="form-control" name="internal_resources_required" rows="4" 
                                          placeholder="Describe required internal resources"></textarea>
                            </div>
                            
                            <div class="col-12">
                                <label class="form-label">3. What challenges do you foresee during execution?</label>
                                <textarea class="form-control" name="execution_challenges" rows="4" 
                                          placeholder="Describe execution challenges"></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-navigation">
                        <div class="d-flex justify-content-between">
                            <button type="button" class="btn btn-dashen-outline" id="prevBtn8" data-prev="7">
                                <i class="fas fa-arrow-left me-1"></i> Previous
                            </button>
                            <button type="button" class="btn btn-dashen" id="nextBtn8" data-next="9">
                                Next <i class="fas fa-arrow-right ms-1"></i>
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Step 9: Attachments -->
                <div class="form-step" id="step9" style="display: none;">
                    <div class="form-section">
                        <h4 class="section-title"><i class="fas fa-paperclip"></i> Section IX: Attachments Checklist</h4>
                        
                        <p class="text-muted mb-4">Please confirm the following documents are attached:</p>
                        
                        <div class="row">
                            <?php 
                            $attachments = [
                                'signed_business_case' => 'Signed Business Case',
                                'requirements_document' => 'Requirements Document',
                                'benefit_management_plan' => 'Benefit Management Plan',
                                'risk_assessment_matrix' => 'Risk Assessment Matrix',
                                'budget_spreadsheet' => 'Budget Spreadsheet',
                                'endorsement_letter' => 'Endorsement Letter from Department Chief',
                                'diagrams_process_maps' => 'Diagrams or Process Maps'
                            ];
                            $counter = 0;
                            foreach ($attachments as $key => $label): 
                                $counter++;
                            ?>
                            <div class="col-md-6 mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="<?php echo $key; ?>" 
                                           value="Yes" id="<?php echo $key; ?>">
                                    <label class="form-check-label" for="<?php echo $key; ?>">
                                        <?php echo $counter; ?>. <?php echo $label; ?>
                                    </label>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="mb-4">
                            <label class="form-label mb-3">Additional Attachments (Optional)</label>
                            <div class="attachment-card" onclick="document.getElementById('fileUpload').click()">
                                <i class="fas fa-cloud-upload-alt attachment-icon"></i>
                                <h5>Click to upload additional files</h5>
                                <p class="text-muted mb-0">Maximum file size: 10MB per file</p>
                                <p class="text-muted">Supported: PDF, DOC, DOCX, XLS, XLSX, JPG, PNG</p>
                            </div>
                            <input type="file" id="fileUpload" name="attachments[]" multiple class="d-none" 
                                   accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png">
                            <div class="attachment-list" id="fileList"></div>
                        </div>
                    </div>
                    
                    <div class="form-navigation">
                        <div class="d-flex justify-content-between">
                            <button type="button" class="btn btn-dashen-outline" id="prevBtn9" data-prev="8">
                                <i class="fas fa-arrow-left me-1"></i> Previous
                            </button>
                            <div>
                                <button type="button" class="btn btn-outline-info me-2" id="previewBtn">
                                    <i class="fas fa-eye me-1"></i> Preview
                                </button>
                                <button type="submit" class="btn btn-success" id="submitBtn">
                                    <i class="fas fa-paper-plane me-1"></i> Submit for Review
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Preview Modal -->
    <div class="modal fade preview-modal" id="previewModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="preview-header">
                    <h4 class="modal-title mb-0"><i class="fas fa-file-alt me-2"></i>Project Intake Preview</h4>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="preview-section">
                        <h5 class="text-dashen mb-3"><i class="fas fa-info-circle me-2"></i>Basic Information</h5>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="preview-label">Project Name</div>
                                <div class="preview-value" id="preview-project_name"></div>
                            </div>
                            <div class="col-md-6">
                                <div class="preview-label">Department</div>
                                <div class="preview-value" id="preview-department"></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="preview-section">
                        <h5 class="text-dashen mb-3"><i class="fas fa-bullseye me-2"></i>Strategic Alignment</h5>
                        <div class="preview-label">Business Challenge</div>
                        <div class="preview-value" id="preview-business_challenge"></div>
                    </div>
                    
                    <div class="preview-section">
                        <h5 class="text-dashen mb-3"><i class="fas fa-server me-2"></i>System Alignment</h5>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="preview-label">Proposed System</div>
                                <div class="preview-value" id="preview-proposed_system_name"></div>
                            </div>
                            <div class="col-md-6">
                                <div class="preview-label">Business Capability</div>
                                <div class="preview-value" id="preview-primary_business_capability"></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="preview-section">
                        <h5 class="text-dashen mb-3"><i class="fas fa-money-bill-wave me-2"></i>Financial Overview</h5>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="preview-label">Estimated Budget</div>
                                <div class="preview-value" id="preview-estimated_total_budget"></div>
                            </div>
                            <div class="col-md-6">
                                <div class="preview-label">Budget Approval</div>
                                <div class="preview-value" id="preview-budget_approval_obtained"></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="preview-section">
                        <h5 class="text-dashen mb-3"><i class="fas fa-exclamation-triangle me-2"></i>Risk & Compliance</h5>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="preview-label">Risk Rating</div>
                                <div class="preview-value" id="preview-overall_risk_rating"></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-dashen" onclick="submitForm()">
                        <i class="fas fa-paper-plane me-1"></i> Submit Now
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        $(document).ready(function() {
            let currentStep = 1;
            const totalSteps = 9;
            
            // Initialize form
            updateStepIndicator();
            
            // Next step buttons
            for (let i = 1; i <= totalSteps; i++) {
                $(`#nextBtn${i}`).click(function() {
                    const nextStep = $(this).data("next");
                    if (validateStep(currentStep)) {
                        currentStep = nextStep;
                        updateStepIndicator();
                        showStep(currentStep);
                        updateNavigationButtons();
                    }
                });
                
                if (i > 1) {
                    $(`#prevBtn${i}`).click(function() {
                        const prevStep = $(this).data("prev");
                        currentStep = prevStep;
                        updateStepIndicator();
                        showStep(currentStep);
                        updateNavigationButtons();
                    });
                }
            }
            
            // Step indicator click
            $(".wizard-step").click(function() {
                const step = $(this).data("step");
                // Allow clicking on completed steps or current step
                if (step <= currentStep || validateStep(currentStep)) {
                    currentStep = step;
                    updateStepIndicator();
                    showStep(currentStep);
                    updateNavigationButtons();
                }
            });
            
            // KPI management
            $("#add-kpi").click(function() {
                const kpiHtml = `
                    <div class="kpi-item">
                        <div class="row g-2">
                            <div class="col-md-6">
                                <input type="text" class="form-control kpi-name" placeholder="KPI Name">
                            </div>
                            <div class="col-md-4">
                                <input type="text" class="form-control kpi-target" placeholder="Target">
                            </div>
                            <div class="col-md-2">
                                <button type="button" class="btn btn-danger btn-sm w-100 remove-kpi">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                    </div>`;
                $("#kpi-container").append(kpiHtml);
            });
            
            $(document).on("click", ".remove-kpi", function() {
                $(this).closest(".kpi-item").remove();
                validateKPIs();
            });
            
            // Risk matrix click
            $(".risk-cell").click(function() {
                const rating = $(this).data("rating");
                $("#riskRating").val(rating);
                $(".risk-cell").removeClass("active");
                $(this).addClass("active");
            });
            
            // Preview button
            $("#previewBtn").click(function() {
                if (validateAllSteps()) {
                    updatePreview();
                    new bootstrap.Modal(document.getElementById('previewModal')).show();
                }
            });
            
            // Form submission
            $("#projectIntakeForm").submit(function(e) {
                e.preventDefault();
                
                if (!validateAllSteps()) {
                    return false;
                }
                
                // Collect KPIs
                const kpis = [];
                $(".kpi-item").each(function() {
                    const name = $(this).find(".kpi-name").val();
                    const target = $(this).find(".kpi-target").val();
                    if (name && target) {
                        kpis.push(`${name}: ${target}`);
                    }
                });
                
                if (kpis.length > 0) {
                    $("textarea[name='success_kpis']").val(kpis.join("\n"));
                }
                
                // Show loading
                $(this).find("button[type='submit']").prop("disabled", true).html('<i class="fas fa-spinner fa-spin me-1"></i>Submitting...');
                
                // Submit form
                this.submit();
            });
            
            // Date validation
            $("input[name='proposed_start_date']").change(function() {
                const endDate = $("input[name='proposed_end_date']");
                if (this.value && endDate.val() && this.value > endDate.val()) {
                    showError("Start date cannot be after end date");
                    this.value = "";
                }
                endDate.attr("min", this.value);
                validateField(this);
            });
            
            $("input[name='proposed_end_date']").change(function() {
                const startDate = $("input[name='proposed_start_date']");
                if (this.value && startDate.val() && this.value < startDate.val()) {
                    showError("End date cannot be before start date");
                    this.value = "";
                }
                validateField(this);
            });
            
            // Toggle existing systems list
            $("input[name='existing_systems_similar']").change(function() {
                const textarea = $("textarea[name='existing_systems_list']");
                textarea.prop("disabled", this.value === "No");
            });
            
            // File upload handling
            $("#fileUpload").change(function() {
                const files = this.files;
                let fileList = $("#fileList");
                fileList.empty();
                
                for (let i = 0; i < files.length; i++) {
                    const file = files[i];
                    const fileSize = (file.size / 1024 / 1024).toFixed(2);
                    
                    if (fileSize > 10) {
                        showError(`File "${file.name}" exceeds 10MB limit. Please upload a smaller file.`);
                        continue;
                    }
                    
                    const fileItem = `
                        <div class="attachment-item">
                            <div>
                                <i class="fas fa-file me-2 text-primary"></i>
                                <span class="fw-medium">${file.name}</span>
                                <small class="text-muted ms-2">(${fileSize} MB)</small>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-danger remove-file" data-index="${i}">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>`;
                    fileList.append(fileItem);
                }
            });
            
            $(document).on("click", ".remove-file", function() {
                const index = $(this).data("index");
                const dt = new DataTransfer();
                const input = document.getElementById("fileUpload");
                const { files } = input;
                
                for (let i = 0; i < files.length; i++) {
                    const file = files[i];
                    if (index !== i) dt.items.add(file);
                }
                
                input.files = dt.files;
                $(this).closest(".attachment-item").remove();
            });
            
            // Real-time validation on input change
            $("input[required], textarea[required], select[required]").on("input change", function() {
                validateField(this);
            });
            
            // Functions
            function validateField(field) {
                const $field = $(field);
                const fieldName = $field.attr("name");
                const errorId = `error-${fieldName}`;
                const $error = $(`#${errorId}`);
                
                if ($field.prop("required") && !$field.val().trim()) {
                    $field.addClass("is-invalid");
                    if ($error.length) $error.show();
                    return false;
                } else {
                    $field.removeClass("is-invalid");
                    if ($error.length) $error.hide();
                    return true;
                }
            }
            
            function validateKPIs() {
                const hasKPIs = $(".kpi-item").length > 0;
                const $error = $("#error-success_kpis");
                
                if (!hasKPIs) {
                    $error.show();
                    return false;
                } else {
                    $error.hide();
                    return true;
                }
            }
            
            function validateStep(step) {
                let isValid = true;
                const $step = $(`#step${step}`);
                
                // Validate all required fields in current step
                $step.find("[required]").each(function() {
                    if (!validateField(this)) {
                        isValid = false;
                    }
                });
                
                // Special validation for KPIs in step 2
                if (step === 2) {
                    if (!validateKPIs()) {
                        isValid = false;
                    }
                }
                
                if (!isValid) {
                    // Scroll to first error
                    const firstError = $step.find(".is-invalid").first();
                    if (firstError.length) {
                        $("html, body").animate({
                            scrollTop: firstError.offset().top - 100
                        }, 500);
                        firstError.focus();
                    }
                    showError("Please fill in all required fields in this section.");
                }
                
                return isValid;
            }
            
            function validateAllSteps() {
                for (let i = 1; i <= totalSteps; i++) {
                    if (!validateStep(i)) {
                        showStep(i);
                        updateStepIndicator();
                        updateNavigationButtons();
                        return false;
                    }
                }
                return true;
            }
            
            function showStep(step) {
                $(".form-step").hide();
                $(`#step${step}`).show();
                
                // Scroll to top of form
                $("html, body").animate({
                    scrollTop: $(".form-wizard-container").offset().top - 20
                }, 300);
            }
            
            function updateStepIndicator() {
                $(".wizard-step").removeClass("active completed");
                
                for (let i = 1; i <= totalSteps; i++) {
                    if (i < currentStep) {
                        $(`.wizard-step[data-step="${i}"]`).addClass("completed");
                    } else if (i === currentStep) {
                        $(`.wizard-step[data-step="${i}"]`).addClass("active");
                    }
                }
            }
            
            function updateNavigationButtons() {
                // Update previous buttons
                for (let i = 1; i <= totalSteps; i++) {
                    const $prevBtn = $(`#prevBtn${i}`);
                    if (i === 1) {
                        $prevBtn.prop("disabled", true);
                    } else {
                        $prevBtn.prop("disabled", false);
                    }
                }
                
                // Update next buttons visibility
                for (let i = 1; i < totalSteps; i++) {
                    $(`#nextBtn${i}`).show();
                }
                $(`#nextBtn${totalSteps}`).hide();
                
                // Show submit button on last step
                if (currentStep === totalSteps) {
                    $("#submitBtn").show();
                } else {
                    $("#submitBtn").hide();
                }
            }
            
            function updatePreview() {
                // Basic info
                $("#preview-project_name").text($("input[name='project_name']").val() || "Not provided");
                
                const deptId = $("select[name='department_id']").val();
                const deptName = $("select[name='department_id'] option:selected").text();
                $("#preview-department").text(deptName || "Not provided");
                
                // Strategic alignment
                $("#preview-business_challenge").text($("textarea[name='business_challenge']").val() || "Not provided");
                
                // System alignment
                $("#preview-proposed_system_name").text($("input[name='proposed_system_name']").val() || "Not provided");
                $("#preview-primary_business_capability").text($("input[name='primary_business_capability']").val() || "Not provided");
                
                // Financial
                const budget = $("input[name='estimated_total_budget']").val();
                $("#preview-estimated_total_budget").text(budget ? "$" + parseFloat(budget).toLocaleString('en-US', {minimumFractionDigits: 2}) : "Not provided");
                $("#preview-budget_approval_obtained").text($("select[name='budget_approval_obtained']").val());
                
                // Risk
                $("#preview-overall_risk_rating").text($("select[name='overall_risk_rating']").val());
            }
            
            function showError(message) {
                // Create toast notification
                const toastHtml = `
                    <div class="toast-container position-fixed top-0 end-0 p-3">
                        <div class="toast align-items-center text-white bg-danger border-0" role="alert">
                            <div class="d-flex">
                                <div class="toast-body">
                                    <i class="fas fa-exclamation-circle me-2"></i>
                                    ${message}
                                </div>
                                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                            </div>
                        </div>
                    </div>`;
                
                // Remove existing toast
                $(".toast-container").remove();
                
                // Add new toast
                $("body").append(toastHtml);
                
                // Show toast
                const toast = new bootstrap.Toast($(".toast")[0]);
                toast.show();
                
                // Auto remove after 5 seconds
                setTimeout(() => {
                    $(".toast-container").remove();
                }, 5000);
            }
            
            function submitForm() {
                $("#projectIntakeForm").submit();
            }
            
            // Initialize
            showStep(currentStep);
            updateNavigationButtons();
            
            // Set today as default for dates
            const today = new Date().toISOString().split('T')[0];
            $("input[name='proposed_start_date']").attr('min', today);
            $("input[name='proposed_end_date']").attr('min', today);
        });
    </script>
</body>
</html>