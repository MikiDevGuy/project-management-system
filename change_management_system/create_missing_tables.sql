-- Create missing tables for change management system
-- Run this script in your test_manager database

-- Change types lookup table
CREATE TABLE IF NOT EXISTS change_types (
    change_type_id INT AUTO_INCREMENT PRIMARY KEY,
    type_name VARCHAR(50) NOT NULL,
    description TEXT
);

-- Insert default change types
INSERT INTO change_types (type_name, description) VALUES
('Design', 'Changes related to design elements'),
('Product', 'Changes to product features or functionality'),
('Documentation', 'Changes to project documentation'),
('Other', 'Other types of changes');

-- Status lookup table
CREATE TABLE IF NOT EXISTS change_statuses (
    status_id INT AUTO_INCREMENT PRIMARY KEY,
    status_name VARCHAR(50) NOT NULL,
    description TEXT
);

-- Insert default statuses
INSERT INTO change_statuses (status_name, description) VALUES
('Open', 'Change request has been submitted but not yet reviewed'),
('In Progress', 'Change request is being worked on'),
('Approved', 'Change request has been approved'),
('Rejected', 'Change request has been rejected'),
('Implemented', 'Change has been implemented');

-- Priorities lookup table
CREATE TABLE IF NOT EXISTS priorities (
    priority_id INT AUTO_INCREMENT PRIMARY KEY,
    priority_name VARCHAR(50) NOT NULL,
    description TEXT
);

-- Insert default priorities
INSERT INTO priorities (priority_name, description) VALUES
('Low', 'Low priority change'),
('Medium', 'Medium priority change'),
('High', 'High priority change'),
('Urgent', 'Urgent change requiring immediate attention');

-- Impact areas lookup table
CREATE TABLE IF NOT EXISTS impact_areas (
    impact_area_id INT AUTO_INCREMENT PRIMARY KEY,
    area_name VARCHAR(50) NOT NULL,
    description TEXT
);

-- Insert default impact areas
INSERT INTO impact_areas (area_name, description) VALUES
('Budget', 'Change impacts project budget'),
('Schedule', 'Change impacts project timeline'),
('Scope', 'Change impacts project scope'),
('Resources', 'Change impacts resource allocation');

-- Main change requests table
CREATE TABLE IF NOT EXISTS change_requests (
    change_request_id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    change_title VARCHAR(255) NOT NULL,
    change_type VARCHAR(50),
    change_description TEXT NOT NULL,
    justification TEXT NOT NULL,
    impact_analysis TEXT,
    area_of_impact VARCHAR(50),
    resolution_expected VARCHAR(255),
    date_resolved DATE,
    action TEXT,
    priority VARCHAR(20) NOT NULL,
    escalation_required BOOLEAN DEFAULT FALSE,
    status VARCHAR(20) DEFAULT 'Open',
    requester_id INT NOT NULL,
    assigned_to_id INT,
    request_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    viewed BOOLEAN DEFAULT FALSE,
    FOREIGN KEY (project_id) REFERENCES projects(id),
    FOREIGN KEY (requester_id) REFERENCES users(id),
    FOREIGN KEY (assigned_to_id) REFERENCES users(id)
);

-- Change logs table
CREATE TABLE IF NOT EXISTS change_logs (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    change_request_id INT NOT NULL,
    user_id INT NOT NULL,
    action VARCHAR(100) NOT NULL,
    details TEXT,
    log_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (change_request_id) REFERENCES change_requests(change_request_id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Change request comments table
CREATE TABLE IF NOT EXISTS change_request_comments (
    comment_id INT AUTO_INCREMENT PRIMARY KEY,
    change_request_id INT NOT NULL,
    user_id INT NOT NULL,
    comment_text TEXT NOT NULL,
    is_internal BOOLEAN DEFAULT FALSE,
    comment_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (change_request_id) REFERENCES change_requests(change_request_id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Notifications table
CREATE TABLE IF NOT EXISTS notifications (
    notification_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    change_request_id INT,
    message TEXT NOT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    date_created TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (change_request_id) REFERENCES change_requests(change_request_id)
);

-- Insert sample data (optional - remove if you don't want sample data)
INSERT INTO change_requests
(project_id, change_title, change_type, change_description, justification, impact_analysis, area_of_impact, resolution_expected, date_resolved, action, priority, escalation_required, status, requester_id)
VALUES
(1, 'Update UI for modern look', 'Design', 'Update UI for more intuitive and modern look.', 'Current UI looks outdated compared to competitors.', 'This change will require approximately 40 developer hours.', 'Budget', '1 Month', NULL, 'Implement UI changes based on new design guidelines.', 'Medium', FALSE, 'Approved', 1),
(1, 'Resolve UI display issue', 'Product', 'Resolve UI display issue on homepage.', 'Homepage UI is not displaying correctly on mobile devices.', 'Will require 8-10 hours of development time.', 'Schedule', '1 Week', NULL, 'Debug and deploy updated UI code.', 'High', TRUE, 'In Progress', 1),
(1, 'Revise user manual', 'Documentation', 'Revise user manual for version 2.0.', 'Current manual does not reflect new features in version 2.0.', 'Technical writer will need 2-3 days to complete updates.', 'Schedule', '2 Weeks', NULL, 'Review, edit, and publish updated documentation.', 'Medium', FALSE, 'Open', 1);