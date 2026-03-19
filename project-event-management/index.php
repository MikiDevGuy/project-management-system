<?php
session_start();

// Check if user is already logged in
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Project Event Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .landing-container {
            max-width: 800px;
            margin: 0 auto;
        }
        
        .landing-card {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.2);
            overflow: hidden;
        }
        
        .landing-header {
            background: linear-gradient(135deg, #273274 0%, #1a237e 100%);
            color: white;
            padding: 50px 30px;
            text-align: center;
        }
        
        .landing-header h1 {
            font-weight: 700;
            margin-bottom: 15px;
            font-size: 3rem;
        }
        
        .landing-header p {
            font-size: 1.2rem;
            opacity: 0.9;
        }
        
        .landing-body {
            padding: 40px;
        }
        
        .features {
            margin: 40px 0;
        }
        
        .feature-item {
            text-align: center;
            padding: 20px;
            transition: transform 0.3s;
        }
        
        .feature-item:hover {
            transform: translateY(-5px);
        }
        
        .feature-icon {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, #273274 0%, #1a237e 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            color: white;
            font-size: 1.8rem;
        }
        
        .btn-get-started {
            background: linear-gradient(135deg, #273274 0%, #1a237e 100%);
            color: white;
            border: none;
            padding: 15px 40px;
            font-size: 1.2rem;
            font-weight: 600;
            border-radius: 10px;
            transition: all 0.3s;
        }
        
        .btn-get-started:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2);
            color: white;
        }
        
        .footer {
            text-align: center;
            padding: 20px;
            background: #f8f9fa;
            border-top: 1px solid #dee2e6;
            color: #666;
        }
    </style>
</head>
<body>
    <div class="landing-container">
        <div class="landing-card">
            <div class="landing-header">
                <h1>PEMS</h1>
                <p>Project Event Management System</p>
            </div>
            
            <div class="landing-body">
                <h2 class="text-center mb-5">Streamline Your Event Management</h2>
                
                <div class="features row">
                    <div class="col-md-4 feature-item">
                        <div class="feature-icon">
                            <i class="fas fa-calendar-alt"></i>
                        </div>
                        <h4>Event Planning</h4>
                        <p>Plan and organize events efficiently with our comprehensive tools</p>
                    </div>
                    
                    <div class="col-md-4 feature-item">
                        <div class="feature-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <h4>Attendee Management</h4>
                        <p>Manage attendees, track RSVPs, and handle registrations seamlessly</p>
                    </div>
                    
                    <div class="col-md-4 feature-item">
                        <div class="feature-icon">
                            <i class="fas fa-tasks"></i>
                        </div>
                        <h4>Task Coordination</h4>
                        <p>Assign and track tasks to ensure smooth event execution</p>
                    </div>
                </div>
                
                <div class="text-center mt-5">
                    <a href="login.php" class="btn btn-get-started">
                        <i class="fas fa-sign-in-alt me-2"></i> Get Started
                    </a>
                </div>
            </div>
            
            <div class="footer">
                <p>&copy; <?php echo date('Y'); ?> Project Event Management System. All rights reserved.</p>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>