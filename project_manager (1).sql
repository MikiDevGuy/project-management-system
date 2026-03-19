-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Sep 26, 2025 at 08:38 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `project_manager`
--

-- --------------------------------------------------------

--
-- Table structure for table `activities`
--

CREATE TABLE `activities` (
  `id` int(11) NOT NULL,
  `phase_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `is_milestone` tinyint(1) DEFAULT 0,
  `priority` enum('high','medium','low') DEFAULT 'medium',
  `status` enum('pending','in_progress','completed') DEFAULT 'pending',
  `progress` int(11) DEFAULT 0,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `baseline_start_date` date DEFAULT NULL,
  `baseline_end_date` date DEFAULT NULL,
  `depends_on` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `project_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `activities`
--

INSERT INTO `activities` (`id`, `phase_id`, `name`, `description`, `is_milestone`, `priority`, `status`, `progress`, `start_date`, `end_date`, `baseline_start_date`, `baseline_end_date`, `depends_on`, `created_at`, `updated_at`, `project_id`) VALUES
(1, 1, 'Activity one', 'Activity one description', 1, 'medium', 'completed', 0, '2025-07-02', '2025-07-17', NULL, NULL, NULL, '2025-07-09 20:33:29', '2025-07-31 13:01:21', 2),
(2, 1, 'Activity Two', 'Activity Two description', 0, 'medium', 'pending', 0, '2025-07-10', '2025-07-13', NULL, NULL, NULL, '2025-07-09 20:44:50', '2025-09-08 10:26:34', 2),
(3, 1, 'Activity three', 'Activity 3 description', 0, 'medium', 'completed', 0, '2025-07-20', '2025-07-26', NULL, NULL, NULL, '2025-07-22 20:45:12', '2025-07-28 13:41:47', 2),
(4, 2, 'Activity one of Requirement Gathering', 'Activity one of Requirement Gathering Description', 0, 'medium', 'completed', 0, '2025-07-15', '2025-07-23', NULL, NULL, NULL, '2025-07-22 21:21:11', '2025-07-28 18:09:49', 2),
(6, 1, 'Activity 4 of planning', 'Activity 4 of planning description', 0, 'medium', 'completed', 0, '2025-07-28', '2025-07-31', NULL, NULL, NULL, '2025-07-28 18:41:55', '2025-07-30 13:46:16', 2),
(7, 1, 'Activity for testing after completed phase', 'to check whether it returns back to in progress or not', 0, 'medium', 'completed', 0, '2025-07-30', '2025-07-31', NULL, NULL, NULL, '2025-07-29 06:13:22', '2025-07-30 13:47:00', 2),
(8, 7, 'asdfghjkl', 'jkyytrazdnmp9', 0, 'medium', 'pending', 0, '2025-07-30', '2025-07-31', NULL, NULL, NULL, '2025-07-29 06:39:25', '2025-09-06 05:54:05', 2),
(9, 9, 'asdfghjkl', 'erdfghiukl', 0, 'medium', 'in_progress', 0, '2025-07-30', '2025-07-31', NULL, NULL, NULL, '2025-07-29 06:47:32', '2025-09-08 13:08:54', 3),
(10, 9, 'fafdsfdsa', 'erewaqwer', 0, 'medium', 'pending', 0, '2025-07-29', '2025-07-30', NULL, NULL, NULL, '2025-07-29 07:15:51', '2025-07-29 07:15:51', 3),
(11, 9, 'fjalkfjasdldkfdasd', 'dflajdklfalkdfakls', 0, 'medium', 'pending', 0, '2025-07-28', '2025-07-28', NULL, NULL, NULL, '2025-07-29 07:46:39', '2025-07-29 07:46:39', 3),
(25, 28, 'the analaysiss for the design', 'the analaysiss for the design must be done acordingly', 0, 'medium', 'in_progress', 0, '2025-09-19', '2025-09-19', NULL, NULL, NULL, '2025-09-18 13:27:31', '2025-09-18 13:27:31', 39),
(26, 28, 'the car design activity', 'the car design activity', 0, 'medium', 'in_progress', 0, '2025-09-19', '2025-09-19', NULL, NULL, NULL, '2025-09-18 13:48:06', '2025-09-18 13:48:06', 39),
(27, 29, 'wewrrdjkhgklbml;\',\'', '', 0, 'medium', 'pending', 0, '0000-00-00', '0000-00-00', NULL, NULL, NULL, '2025-09-19 07:16:53', '2025-09-19 07:16:53', 3),
(28, 29, 'wewrrdjkhgklbml;\',\'', '', 0, 'medium', 'completed', 0, '2025-09-12', '2025-09-20', NULL, NULL, NULL, '2025-09-19 07:17:18', '2025-09-19 07:17:18', 3),
(30, 33, 'tryfghkj', 'ftughkj', 0, 'medium', 'in_progress', 0, '0000-00-00', '0000-00-00', NULL, NULL, NULL, '2025-09-25 11:09:30', '2025-09-25 11:09:30', 36);

-- --------------------------------------------------------

--
-- Table structure for table `activity_log`
--

CREATE TABLE `activity_log` (
  `id` int(11) NOT NULL,
  `issue_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `action` varchar(100) NOT NULL,
  `old_value` text DEFAULT NULL,
  `new_value` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `activity_logs`
--

CREATE TABLE `activity_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `action` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `test_case_id` int(11) DEFAULT NULL,
  `project_id` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `is_read` tinyint(1) DEFAULT 0,
  `entity_type` enum('project','phase','activity','sub_activity','test_case','feature') DEFAULT NULL,
  `entity_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `activity_logs`
--

INSERT INTO `activity_logs` (`id`, `user_id`, `action`, `description`, `test_case_id`, `project_id`, `created_at`, `is_read`, `entity_type`, `entity_id`) VALUES
(1, 4, 'Vendor Comment Updated', 'Vendor updated comment: To fix this thing you should first input an integer not a string.', 2, 1, '2025-06-30 16:19:14', 1, NULL, NULL),
(2, 4, 'Vendor Comment Updated', 'Vendor updated comment: To fix this thing you should first input an integer not a string right.', 2, 1, '2025-06-30 23:41:59', 1, NULL, NULL),
(3, 4, 'Vendor Comment Updated', 'Vendor updated comment: You should use integer not string', 3, 1, '2025-07-01 16:42:23', 1, NULL, NULL),
(4, 4, 'Vendor Comment Updated', 'Vendor updated comment: To fix this thing you should first input an integer not a string right.', 2, 1, '2025-07-02 18:39:32', 0, NULL, NULL),
(5, 4, 'Vendor Comment Updated', 'Vendor updated comment: To fix this thing you should first input an integer not a string right.', 2, 1, '2025-07-02 22:22:37', 0, NULL, NULL),
(6, 4, 'Vendor Comment Updated', 'Vendor updated comment: To fix this thing you should first input an integer not a string right.', 2, 1, '2025-07-02 22:23:44', 0, NULL, NULL),
(18, 4, 'Vendor Comment Updated', 'Vendor updated comment: It should work!', 7, 1, '2025-07-03 15:21:24', 1, NULL, NULL),
(19, 4, 'Vendor Comment Updated', 'Vendor updated comment: To fix this thing you should first input an integer not a string right.', 2, 1, '2025-07-03 17:24:09', 1, NULL, NULL),
(20, 4, 'Vendor Comment Updated', 'Vendor updated comment: My first vendor comment at MP. ', 4, 2, '2025-07-03 23:30:03', 1, NULL, NULL),
(21, 4, 'Vendor Comment Updated', 'Vendor updated comment: My first vendor comment at MP. ', 4, 2, '2025-07-03 23:35:25', 1, NULL, NULL),
(22, 4, 'Vendor Comment Updated', 'Vendor updated comment: My first vendor comment at MP. ', 4, 2, '2025-07-03 23:50:22', 1, NULL, NULL),
(23, 4, 'Vendor Comment Updated', 'Vendor updated comment: God Permission', 6, 2, '2025-07-04 00:13:12', 1, NULL, NULL),
(24, 4, 'Vendor Comment Updated', 'Vendor updated comment: God God Permission 2', 5, 2, '2025-07-04 00:14:48', 1, NULL, NULL),
(25, 4, 'Vendor Comment Updated', 'Vendor updated comment: Everything is done with God Permission.', 6, 2, '2025-07-04 00:26:38', 1, NULL, NULL),
(26, 4, 'Vendor Comment Updated', 'Vendor updated comment: My second vendor comment at MP. ', 4, 2, '2025-07-04 10:50:46', 1, NULL, NULL),
(27, 4, 'Vendor Comment Updated', 'Vendor updated comment: Hello Muluken', 8, 1, '2025-07-04 14:35:13', 1, NULL, NULL),
(28, 4, 'Vendor Comment Updated', 'Vendor updated comment: You should use integer not string', 3, 1, '2025-07-09 09:02:38', 0, NULL, NULL),
(29, 4, 'Vendor Comment Updated', 'Vendor updated comment: To fix this thing you should first input an integer not a string right.', 2, 1, '2025-07-09 09:04:07', 1, NULL, NULL),
(30, 4, 'Vendor Comment Updated', 'Vendor updated comment: To fix this thing you should first input an integer not a string right.', 2, 1, '2025-07-09 09:56:58', 1, NULL, NULL),
(31, 4, 'Vendor Comment Updated', 'Vendor updated comment: you have to try it again ', 5, 2, '2025-07-16 10:58:31', 1, NULL, NULL),
(32, 4, 'Vendor Comment Updated', 'Vendor updated comment: you have to try it again ', 5, 2, '2025-07-16 11:26:23', 0, NULL, NULL),
(33, 4, 'Vendor Comment Updated', 'Vendor updated comment: we are updating the case please try now', 8, 1, '2025-09-05 09:42:19', 0, NULL, NULL),
(34, 4, 'Vendor Comment Updated', 'Vendor updated comment: we are updating the case please try now', 8, 1, '2025-09-05 09:44:13', 0, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `activity_users`
--

CREATE TABLE `activity_users` (
  `id` int(11) NOT NULL,
  `activity_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `activity_users`
--

INSERT INTO `activity_users` (`id`, `activity_id`, `user_id`) VALUES
(3, 1, 7),
(5, 1, 13),
(4, 1, 16),
(13, 8, 14),
(6, 9, 7),
(7, 9, 13);

-- --------------------------------------------------------

--
-- Table structure for table `actual_expenses`
--

CREATE TABLE `actual_expenses` (
  `id` int(11) NOT NULL,
  `budget_item_id` int(11) DEFAULT NULL,
  `transaction_date` date DEFAULT NULL,
  `amount` decimal(15,2) DEFAULT NULL,
  `currency` varchar(10) DEFAULT NULL,
  `vendor_id` int(11) DEFAULT NULL,
  `payment_method` varchar(50) DEFAULT NULL,
  `reference_number` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `supporting_docs` text DEFAULT NULL,
  `status` enum('pending','paid','rejected') DEFAULT 'pending',
  `approved_by` int(11) DEFAULT NULL,
  `project_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `actual_expenses`
--

INSERT INTO `actual_expenses` (`id`, `budget_item_id`, `transaction_date`, `amount`, `currency`, `vendor_id`, `payment_method`, `reference_number`, `description`, `supporting_docs`, `status`, `approved_by`, `project_id`) VALUES
(2, 3, '2025-08-13', 1500000.00, 'USD', NULL, 'bank_transfer', '123456789', 'Electric, water related payment', NULL, 'paid', 1, 2),
(3, 3, '2025-08-13', 10000.00, 'USD', NULL, 'bank_transfer', '24567890', 'I don\'t know', NULL, 'paid', 1, NULL),
(4, 5, '2025-08-13', 10000.00, 'USD', NULL, 'credit_card', '12345', 'Going to India', NULL, 'paid', 1, NULL),
(5, 5, '2025-07-06', 20000.00, 'USD', NULL, 'credit_card', '12345678', 'Plain ticket for Hungary ', NULL, 'paid', 1, NULL),
(6, 6, '2025-07-07', 1000000.00, 'USD', NULL, 'credit_card', '12340912', 'Utility payment for infrastructure department.', NULL, 'paid', 1, NULL),
(7, 6, '2025-08-15', 75000.00, 'USD', 2, 'bank_transfer', '00123456', 'Utility payment 2 for infra', NULL, 'paid', 1, NULL),
(8, 11, '2025-08-21', 1000000.00, 'USD', 1, 'bank_transfer', '12345678', 'ACI project staff salary', NULL, 'paid', 1, NULL),
(9, 14, '2025-08-22', 34567.00, 'USD', 1, 'bank_transfer', '9812345', 'Payment for ship travel', NULL, 'rejected', NULL, NULL),
(10, 15, '2025-08-10', 12345.00, 'USD', 2, 'cash', '2345677867', 'Salary budge for VCN emp', NULL, 'paid', 1, NULL),
(11, 15, '2025-08-22', 12436.00, 'USD', 2, 'credit_card', '4923749837598375', 'Salary budget for VCN 2', NULL, 'paid', 1, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `attachments`
--

CREATE TABLE `attachments` (
  `id` int(11) NOT NULL,
  `issue_id` int(11) NOT NULL,
  `filename` varchar(255) NOT NULL,
  `filepath` varchar(500) NOT NULL,
  `uploaded_by` int(11) NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `budget_categories`
--

CREATE TABLE `budget_categories` (
  `id` int(11) NOT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `category_name` varchar(255) DEFAULT NULL,
  `category_code` varchar(50) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `budget_categories`
--

INSERT INTO `budget_categories` (`id`, `parent_id`, `category_name`, `category_code`, `description`, `is_active`, `created_at`) VALUES
(13, 5, 'Utility Category', '1000', '', 1, '2025-08-13 06:38:25'),
(14, 5, 'Salary Category', '200', '', 1, '2025-08-13 06:38:25'),
(15, 6, 'Travel Category', '300', '', 1, '2025-08-13 06:38:25');

-- --------------------------------------------------------

--
-- Table structure for table `budget_items`
--

CREATE TABLE `budget_items` (
  `id` int(11) NOT NULL,
  `item_name` varchar(255) DEFAULT NULL,
  `budget_category_id` int(11) DEFAULT NULL,
  `cost_type_id` int(11) DEFAULT NULL,
  `department_id` int(11) DEFAULT NULL,
  `fiscal_year` year(4) DEFAULT NULL,
  `estimated_amount` decimal(15,2) DEFAULT NULL,
  `contingency_percentage` decimal(5,2) DEFAULT NULL,
  `contingency_amount` decimal(15,2) DEFAULT NULL,
  `total_budget_amount` decimal(15,2) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `status` enum('draft','submitted','approved','rejected') DEFAULT 'draft',
  `created_by` int(11) DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `project_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `budget_items`
--

INSERT INTO `budget_items` (`id`, `item_name`, `budget_category_id`, `cost_type_id`, `department_id`, `fiscal_year`, `estimated_amount`, `contingency_percentage`, `contingency_amount`, `total_budget_amount`, `remarks`, `status`, `created_by`, `approved_by`, `project_id`) VALUES
(3, 'Utility budget', 13, 1, 1, '2025', 20000000.00, 10.00, 2000000.00, 22000000.00, '', 'approved', 1, NULL, 2),
(5, 'Travel Budget', 15, 1, 1, '2025', 10000.00, 10.00, 1000.00, 11000.00, '', 'approved', 1, NULL, NULL),
(6, 'Utility budget', 13, 1, 2, '2025', 30000000.00, 10.00, 3000000.00, 33000000.00, '', 'approved', 1, NULL, 3),
(7, 'Travel Budget', 15, 1, 1, '2025', 5000000.00, 10.00, 500000.00, 5500000.00, '', 'approved', 1, NULL, 7),
(8, 'Contract Salary', 14, 1, 2, '2025', 6200000.00, 10.00, 620000.00, 6820000.00, '', 'approved', 1, NULL, NULL),
(9, 'Airplane Ticket ', 15, 1, 2, '2025', 6000000.00, 10.00, 600000.00, 6600000.00, '', 'approved', 1, NULL, 7),
(11, 'Staff Salary ', 14, 1, 2, '2025', 1230000.00, 10.00, 123000.00, 1353000.00, 'remark', 'approved', 1, NULL, 7),
(13, 'Train Ticket ', 15, 1, 1, '2025', 45000.00, 10.00, 4500.00, 49500.00, '', 'approved', 1, NULL, 3),
(14, 'Ship Travel budget ', 15, 2, 2, '2025', 900000.00, 10.00, 90000.00, 990000.00, '', 'approved', 1, NULL, 1),
(15, 'Salry Budget for VCN', 14, 2, 1, '2025', 76500.00, 10.00, 7650.00, 84150.00, '', 'approved', 1, NULL, 1);

-- --------------------------------------------------------

--
-- Table structure for table `change_logs`
--

CREATE TABLE `change_logs` (
  `log_id` int(11) NOT NULL,
  `change_request_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `action` varchar(100) NOT NULL,
  `details` text DEFAULT NULL,
  `log_date` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `change_logs`
--

INSERT INTO `change_logs` (`log_id`, `change_request_id`, `user_id`, `action`, `details`, `log_date`) VALUES
(1, 2, 3, 'Status Updated', 'Status changed to: Implemented', '2025-09-03 13:14:38');

-- --------------------------------------------------------

--
-- Table structure for table `change_requests`
--

CREATE TABLE `change_requests` (
  `change_request_id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `change_title` varchar(255) NOT NULL,
  `change_type` varchar(50) DEFAULT NULL,
  `change_description` text NOT NULL,
  `justification` text NOT NULL,
  `impact_analysis` text DEFAULT NULL,
  `area_of_impact` varchar(50) DEFAULT NULL,
  `resolution_expected` varchar(255) DEFAULT NULL,
  `date_resolved` date DEFAULT NULL,
  `action` text DEFAULT NULL,
  `priority` varchar(20) NOT NULL,
  `escalation_required` tinyint(1) DEFAULT 0,
  `status` varchar(20) DEFAULT 'Open',
  `requester_id` int(11) NOT NULL,
  `assigned_to_id` int(11) DEFAULT NULL,
  `request_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `viewed` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `change_requests`
--

INSERT INTO `change_requests` (`change_request_id`, `project_id`, `change_title`, `change_type`, `change_description`, `justification`, `impact_analysis`, `area_of_impact`, `resolution_expected`, `date_resolved`, `action`, `priority`, `escalation_required`, `status`, `requester_id`, `assigned_to_id`, `request_date`, `last_updated`, `viewed`) VALUES
(1, 1, 'Update UI for modern look', 'Design', 'Update UI for more intuitive and modern look.', 'Current UI looks outdated compared to competitors.', 'This change will require approximately 40 developer hours.', 'Budget', '1 Month', '2023-11-16', 'Implement UI changes based on new design guidelines.', 'Medium', 0, 'Approved', 3, NULL, '2025-09-02 12:10:25', '2025-09-03 14:16:40', 1),
(2, 2, 'Resolve UI display issue', 'Product', 'Resolve UI display issue on homepage.', 'Homepage UI is not displaying correctly on mobile devices.', 'Will require 8-10 hours of development time.', 'Schedule', '1 Week', NULL, 'Debug and deploy updated UI code.', 'High', 1, 'Implemented', 3, NULL, '2025-09-02 12:10:25', '2025-09-03 13:14:38', 0),
(3, 2, 'Revise user manual', 'Documentation', 'Revise user manual for version 2.0.', 'Current manual does not reflect new features in version 2.0.', 'Technical writer will need 2-3 days to complete updates.', 'Schedule', '2 Weeks', NULL, 'Review, edit, and publish updated documentation.', 'Medium', 0, 'Open', 3, NULL, '2025-09-02 12:10:25', '2025-09-02 12:10:25', 0);

-- --------------------------------------------------------

--
-- Table structure for table `change_request_comments`
--

CREATE TABLE `change_request_comments` (
  `comment_id` int(11) NOT NULL,
  `change_request_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `comment_text` text NOT NULL,
  `comment_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_internal` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `change_statuses`
--

CREATE TABLE `change_statuses` (
  `status_id` int(11) NOT NULL,
  `status_name` varchar(50) NOT NULL,
  `description` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `change_statuses`
--

INSERT INTO `change_statuses` (`status_id`, `status_name`, `description`) VALUES
(1, 'Open', 'Change request has been submitted but not yet reviewed'),
(2, 'In Progress', 'Change request is being worked on'),
(3, 'Approved', 'Change request has been approved'),
(4, 'Rejected', 'Change request has been rejected'),
(5, 'Implemented', 'Change has been implemented'),
(6, 'Open', 'Change request has been submitted but not yet reviewed'),
(7, 'In Progress', 'Change request is being worked on'),
(8, 'Approved', 'Change request has been approved'),
(9, 'Rejected', 'Change request has been rejected'),
(10, 'Implemented', 'Change has been implemented');

-- --------------------------------------------------------

--
-- Table structure for table `change_types`
--

CREATE TABLE `change_types` (
  `change_type_id` int(11) NOT NULL,
  `type_name` varchar(50) NOT NULL,
  `description` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `change_types`
--

INSERT INTO `change_types` (`change_type_id`, `type_name`, `description`) VALUES
(1, 'Design', 'Changes related to design elements'),
(2, 'Product', 'Changes to product features or functionality'),
(3, 'Documentation', 'Changes to project documentation'),
(4, 'Other', 'Other types of changes'),
(5, 'Design', 'Changes related to design elements'),
(6, 'Product', 'Changes to product features or functionality'),
(7, 'Documentation', 'Changes to project documentation'),
(8, 'Other', 'Other types of changes');

-- --------------------------------------------------------

--
-- Table structure for table `comments`
--

CREATE TABLE `comments` (
  `id` int(11) NOT NULL,
  `issue_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `comment` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `contracts`
--

CREATE TABLE `contracts` (
  `id` int(11) NOT NULL,
  `vendor_id` int(11) DEFAULT NULL,
  `contract_name` varchar(255) DEFAULT NULL,
  `contract_number` varchar(100) DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `total_value` decimal(15,2) DEFAULT NULL,
  `renewal_terms` text DEFAULT NULL,
  `payment_schedule` text DEFAULT NULL,
  `document_path` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `contracts`
--

INSERT INTO `contracts` (`id`, `vendor_id`, `contract_name`, `contract_number`, `start_date`, `end_date`, `total_value`, `renewal_terms`, `payment_schedule`, `document_path`) VALUES
(1, 1, 'mikiyas', '12345678909876543', '2025-09-06', '2025-09-12', 123455.99, 'ed', 'r4r', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `cost_types`
--

CREATE TABLE `cost_types` (
  `id` int(11) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `unit_of_measure` varchar(50) DEFAULT NULL,
  `is_recurring` tinyint(1) DEFAULT 0,
  `default_contingency_percentage` decimal(5,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `cost_types`
--

INSERT INTO `cost_types` (`id`, `name`, `description`, `unit_of_measure`, `is_recurring`, `default_contingency_percentage`) VALUES
(1, 'Fixed', 'costs that are not changed for long period of time.', 'month', 0, 10.00),
(2, 'Variable ', 'cost that happen at variable time. ', 'month', 0, 10.00);

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `id` int(11) NOT NULL,
  `department_name` varchar(255) DEFAULT NULL,
  `department_code` varchar(50) DEFAULT NULL,
  `manager_id` int(11) DEFAULT NULL,
  `parent_department_id` int(11) DEFAULT NULL,
  `cost_center_code` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `departments`
--

INSERT INTO `departments` (`id`, `department_name`, `department_code`, `manager_id`, `parent_department_id`, `cost_center_code`) VALUES
(1, 'BSMPD', '4000', 2, NULL, '5000'),
(2, 'Infrastructure ', '500', 1, NULL, '2234');

-- --------------------------------------------------------

--
-- Table structure for table `email_logs`
--

CREATE TABLE `email_logs` (
  `id` int(11) NOT NULL,
  `test_case_id` int(11) DEFAULT NULL,
  `project_id` int(11) DEFAULT NULL,
  `sender_id` int(11) DEFAULT NULL,
  `recipient_id` int(11) DEFAULT NULL,
  `recipient_email` varchar(255) DEFAULT NULL,
  `subject` varchar(255) DEFAULT NULL,
  `status` enum('sent','failed') DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `features`
--

CREATE TABLE `features` (
  `id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `feature_name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('Planned','In Progress','Completed','Deferred') DEFAULT 'Planned',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `features`
--

INSERT INTO `features` (`id`, `project_id`, `feature_name`, `description`, `status`, `created_at`) VALUES
(5, 1, 'Open Virtual Prepaid Card', 'Deliverable one', 'In Progress', '2025-06-29 22:29:09'),
(6, 2, 'Display cards details', 'Deliverable Two', 'In Progress', '2025-06-29 22:42:10'),
(7, 2, 'Wallet Settings', 'Deliverable Three', 'In Progress', '2025-06-29 22:59:27'),
(8, 2, 'Vocher', 'Card less transaction', 'In Progress', '2025-07-01 13:45:37');

-- --------------------------------------------------------

--
-- Table structure for table `impact_areas`
--

CREATE TABLE `impact_areas` (
  `impact_area_id` int(11) NOT NULL,
  `area_name` varchar(50) NOT NULL,
  `description` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `impact_areas`
--

INSERT INTO `impact_areas` (`impact_area_id`, `area_name`, `description`) VALUES
(1, 'Budget', 'Change impacts project budget'),
(2, 'Schedule', 'Change impacts project timeline'),
(3, 'Scope', 'Change impacts project scope'),
(4, 'Resources', 'Change impacts resource allocation'),
(5, 'Budget', 'Change impacts project budget'),
(6, 'Schedule', 'Change impacts project timeline'),
(7, 'Scope', 'Change impacts project scope'),
(8, 'Resources', 'Change impacts resource allocation');

-- --------------------------------------------------------

--
-- Table structure for table `issues`
--

CREATE TABLE `issues` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `summary` text DEFAULT NULL,
  `status` enum('open','in_progress','resolved','closed') DEFAULT 'open',
  `priority` enum('low','medium','high','critical') DEFAULT 'medium',
  `type` enum('bug','feature','task','improvement') DEFAULT 'bug',
  `project_id` int(11) DEFAULT NULL,
  `assigned_to` int(11) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `team` varchar(100) DEFAULT NULL,
  `sprint` varchar(100) DEFAULT NULL,
  `story_points` int(11) DEFAULT NULL,
  `labels` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `issues`
--

INSERT INTO `issues` (`id`, `title`, `description`, `summary`, `status`, `priority`, `type`, `project_id`, `assigned_to`, `created_by`, `team`, `sprint`, `story_points`, `labels`, `created_at`, `updated_at`) VALUES
(1, 'Test Issue Title', 'Test Issue Descriptionn', 'Test Issue Summary', 'open', 'high', 'bug', 1, 1, 3, '', '', NULL, '', '2025-09-04 11:13:11', '2025-09-04 11:15:18');

-- --------------------------------------------------------

--
-- Table structure for table `logs`
--

CREATE TABLE `logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `logs`
--

INSERT INTO `logs` (`id`, `user_id`, `action`, `created_at`) VALUES
(1, 3, 'Added test case \'For testing Recent activity is working or not\' to project ID 3', '2025-06-27 07:31:05'),
(2, 3, 'Added test case \'lkafdslkfjalkd\' to project ID 3', '2025-06-27 08:32:10'),
(3, 2, 'Added test case \'rtrtr\' to project ID 1', '2025-06-27 09:05:30'),
(4, 3, 'Added test case \'Tap and Pay\' to project ID 1', '2025-06-28 21:19:16'),
(5, 3, 'Added test case \'Tap and Pay\' to project ID 1', '2025-06-30 12:11:26'),
(6, 3, 'Added test case \'Get card\' to project ID 1', '2025-06-30 19:09:51'),
(7, 2, 'Added test case \'To test email\' to project ID 1', '2025-07-02 15:37:26'),
(8, 3, 'Added test case \'Second Email Test\' to project ID 1', '2025-07-03 14:23:42'),
(9, 3, 'Added test case \'Test case at 9-5-2025 title\' to project ID 1', '2025-09-05 06:21:22'),
(10, 2, 'Added test case \'Test case at 9-5-2025 title\' to project ID 1', '2025-09-05 06:28:47'),
(11, 2, 'Added test case \'Test case at 9-5-2025 title\' to project ID 1', '2025-09-05 06:29:21'),
(12, 2, 'Added test case \'Test case at 9-5-2025 title\' to project ID 1', '2025-09-05 06:31:06');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `parent_budget_categories`
--

CREATE TABLE `parent_budget_categories` (
  `id` int(11) NOT NULL,
  `parent_name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `parent_budget_categories`
--

INSERT INTO `parent_budget_categories` (`id`, `parent_name`, `description`, `is_active`, `created_at`) VALUES
(5, 'Operational Budget', NULL, 1, '2025-08-13 09:32:21'),
(6, 'Personal Budget', NULL, 1, '2025-08-13 09:32:21');

-- --------------------------------------------------------

--
-- Table structure for table `phases`
--

CREATE TABLE `phases` (
  `id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `Phase_order` int(11) DEFAULT 0,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `status` enum('pending','in_progress','completed') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `phases`
--

INSERT INTO `phases` (`id`, `project_id`, `name`, `description`, `Phase_order`, `start_date`, `end_date`, `status`, `created_at`, `updated_at`) VALUES
(1, 2, 'Planning', 'MP project planning description', 69, '2025-07-05', '2025-07-16', 'in_progress', '2025-07-09 19:11:02', '2025-09-08 12:37:32'),
(2, 2, 'Requirement Gathering', 'MP phase two description', 2, '2025-07-13', '2025-07-21', 'completed', '2025-07-09 19:34:49', '2025-09-03 14:46:03'),
(3, 2, 'Requirement Analysis', 'Requirement Analysis description', 3, '2025-07-14', '2025-07-19', 'completed', '2025-07-18 06:45:50', '2025-07-31 12:56:31'),
(7, 2, 'Implementation', 'Implementation description', 5, '2025-07-18', '2025-07-21', 'pending', '2025-07-18 07:23:16', '2025-09-06 05:54:05'),
(8, 2, 'Maintenance', 'Maintenance  phase description', 6, '2025-07-21', '2025-07-31', 'pending', '2025-07-22 12:10:58', '2025-09-08 13:23:39'),
(9, 3, 'Planning', 'Planning description', 1, '2025-07-23', '2025-07-31', 'in_progress', '2025-07-29 06:41:29', '2025-09-25 11:54:06'),
(18, 3, 'jjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjj', NULL, 0, NULL, NULL, 'pending', '2025-09-16 05:16:22', '2025-09-16 05:16:22'),
(24, 33, 'project one', 'rrfhk', 2, '2025-09-07', '2025-09-09', 'pending', '2025-09-17 07:46:19', '2025-09-17 07:46:19'),
(26, 3, 'project', '', 4, '2025-09-05', '2025-09-12', 'pending', '2025-09-17 11:45:26', '2025-09-17 11:45:26'),
(27, 2, 'project one', 'erdfghiukl', 8, '2025-09-06', '2025-09-12', 'pending', '2025-09-18 06:09:12', '2025-09-18 06:09:12'),
(28, 39, 'Car body design', 'the real car must be designed and ready for the future', 3, '2025-09-12', '2025-09-20', 'in_progress', '2025-09-18 13:26:24', '2025-09-18 13:26:24'),
(29, 3, 'Car body design', '', 3, '2025-09-12', '2025-09-20', 'pending', '2025-09-19 07:16:35', '2025-09-19 07:16:35'),
(31, 3, 'rtkjl', '', 567, '2025-09-05', '2025-09-20', 'in_progress', '2025-09-19 07:27:29', '2025-09-19 07:27:29'),
(32, 1, 'yfyug', '4re', 2324, '2025-09-06', '2025-09-13', 'pending', '2025-09-19 07:28:08', '2025-09-19 07:28:08'),
(33, 36, 'project one', 'uijkhoijkljiokl', 1, '2025-09-09', '2025-09-27', 'pending', '2025-09-24 05:40:33', '2025-09-24 05:40:33');

-- --------------------------------------------------------

--
-- Table structure for table `phase_users`
--

CREATE TABLE `phase_users` (
  `id` int(11) NOT NULL,
  `phase_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `phase_users`
--

INSERT INTO `phase_users` (`id`, `phase_id`, `user_id`) VALUES
(3, 1, 4),
(2, 1, 6),
(1, 1, 7),
(14, 7, 13),
(9, 8, 7),
(18, 9, 4),
(19, 9, 5),
(17, 9, 6),
(20, 9, 7);

-- --------------------------------------------------------

--
-- Table structure for table `priorities`
--

CREATE TABLE `priorities` (
  `priority_id` int(11) NOT NULL,
  `priority_name` varchar(50) NOT NULL,
  `description` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `priorities`
--

INSERT INTO `priorities` (`priority_id`, `priority_name`, `description`) VALUES
(1, 'Low', 'Low priority change'),
(2, 'Medium', 'Medium priority change'),
(3, 'High', 'High priority change'),
(4, 'Urgent', 'Urgent change requiring immediate attention'),
(5, 'Low', 'Low priority change'),
(6, 'Medium', 'Medium priority change'),
(7, 'High', 'High priority change'),
(8, 'Urgent', 'Urgent change requiring immediate attention');

-- --------------------------------------------------------

--
-- Table structure for table `projects`
--

CREATE TABLE `projects` (
  `id` int(11) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `project_type` enum('technical','business','hybrid') DEFAULT 'hybrid',
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `status` enum('pending','in_progress','completed') DEFAULT 'pending',
  `created_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `department_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `projects`
--

INSERT INTO `projects` (`id`, `name`, `description`, `created_at`, `project_type`, `start_date`, `end_date`, `status`, `created_by`, `updated_at`, `department_id`) VALUES
(1, 'VCN', 'Virtual Card Network', '2025-06-28 12:43:40', 'hybrid', NULL, NULL, 'in_progress', NULL, '2025-09-09 08:50:54', 1),
(2, 'MP', 'MasterCard Processing Network', '2025-06-28 12:43:40', 'hybrid', NULL, NULL, 'in_progress', NULL, '2025-08-14 06:02:53', 1),
(3, 'UPF', 'it is only Mikiys projects', '2025-06-28 12:43:40', 'hybrid', NULL, NULL, 'in_progress', NULL, '2025-08-15 07:37:38', 2),
(4, 'voucher ', 'second Mikis project', '2025-06-28 12:43:40', 'hybrid', NULL, NULL, '', NULL, '2025-07-08 06:14:21', NULL),
(7, 'Digital Pin', 'dkaljfalkfjlask;fjalkdfjlkadf', '2025-06-28 12:43:40', 'hybrid', NULL, NULL, '', NULL, '2025-07-08 06:14:21', NULL),
(26, 'fasika', 'manager', '2025-06-28 12:43:40', 'hybrid', NULL, NULL, 'pending', NULL, '2025-09-08 12:46:20', NULL),
(28, 'TestProject For Infra dep2', 'dfasd', '2025-08-14 10:32:48', 'hybrid', NULL, NULL, 'pending', NULL, '2025-08-14 07:32:48', 1),
(29, 'TestProject For Infra dep3', 'fada', '2025-08-14 10:33:42', 'hybrid', NULL, NULL, 'pending', NULL, '2025-08-14 07:33:42', 2),
(33, 'Project Alpha', 'Development of new web application', '2025-09-02 15:09:21', 'hybrid', '2023-01-01', '2023-12-31', 'pending', NULL, '2025-09-02 12:09:21', NULL),
(34, 'Project Beta', 'Mobile app for customer engagement', '2025-09-02 15:09:21', 'hybrid', '2023-02-15', '2023-10-30', 'pending', NULL, '2025-09-02 12:09:21', NULL),
(35, 'Project Gamma', 'Database migration project', '2025-09-02 15:09:21', 'hybrid', '2023-03-01', '2023-09-30', 'pending', NULL, '2025-09-02 12:09:21', NULL),
(36, 'Test Project for BSPMD Name', 'Test Project for BSPMD Description', '2025-09-05 09:09:14', 'hybrid', '2025-09-01', '2025-09-30', 'pending', 3, '2025-09-05 06:09:14', 2),
(37, 'gyyuu', NULL, '2025-09-13 10:16:44', 'hybrid', NULL, NULL, 'pending', NULL, '2025-09-13 07:16:44', NULL),
(38, 'popioo', NULL, '2025-09-17 14:49:57', 'hybrid', NULL, NULL, 'pending', NULL, '2025-09-17 11:49:57', NULL),
(39, 'carmanegment system', 'carmanegment system is the most important software', '2025-09-18 16:24:15', 'hybrid', '2025-09-20', '2025-09-19', 'pending', 3, '2025-09-18 13:24:15', 1);

-- --------------------------------------------------------

--
-- Table structure for table `project_users`
--

CREATE TABLE `project_users` (
  `id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `test_role` enum('none','viewer','tester','manager') DEFAULT 'none',
  `pm_role` enum('none','viewer','employee','manager') DEFAULT 'none'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `project_users`
--

INSERT INTO `project_users` (`id`, `project_id`, `user_id`, `test_role`, `pm_role`) VALUES
(63, 4, 1, 'none', 'none'),
(141, 3, 3, 'none', 'none'),
(143, 3, 1, 'none', 'none'),
(144, 3, 7, 'none', 'none'),
(145, 3, 13, 'none', 'none'),
(147, 29, 14, 'none', 'none'),
(148, 29, 3, 'none', 'none'),
(164, 36, 14, 'none', 'none'),
(165, 36, 3, 'none', 'none'),
(166, 36, 15, 'none', 'none'),
(167, 1, 14, 'none', 'none'),
(168, 1, 3, 'none', 'none'),
(169, 1, 15, 'none', 'none'),
(170, 1, 17, 'none', 'none'),
(171, 1, 20, 'none', 'none'),
(172, 7, 15, 'none', 'none'),
(173, 7, 17, 'none', 'none'),
(174, 2, 14, 'none', 'none'),
(175, 2, 3, 'none', 'none'),
(176, 2, 15, 'none', 'none'),
(177, 2, 17, 'none', 'none'),
(178, 2, 20, 'none', 'none');

-- --------------------------------------------------------

--
-- Table structure for table `risks`
--

CREATE TABLE `risks` (
  `id` int(11) NOT NULL,
  `project_id` int(11) DEFAULT NULL,
  `department_id` int(11) DEFAULT NULL,
  `category_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `trigger_description` text DEFAULT NULL,
  `likelihood` tinyint(1) DEFAULT 1,
  `impact` tinyint(1) DEFAULT 1,
  `score` int(11) GENERATED ALWAYS AS (`likelihood` * `impact`) VIRTUAL,
  `risk_score` int(11) GENERATED ALWAYS AS (`likelihood` * `impact`) VIRTUAL,
  `risk_level` varchar(20) DEFAULT NULL,
  `owner_user_id` int(11) DEFAULT NULL,
  `status_id` int(11) DEFAULT NULL,
  `probability_note` text DEFAULT NULL,
  `impact_note` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `risks`
--

INSERT INTO `risks` (`id`, `project_id`, `department_id`, `category_id`, `title`, `description`, `trigger_description`, `likelihood`, `impact`, `risk_level`, `owner_user_id`, `status_id`, `probability_note`, `impact_note`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 2, 1, 2, 'Over Cost risk ', 'Consequence one ', '0', 1, 1, 'Low', 1, 3, NULL, NULL, 1, '2025-08-26 11:48:26', '2025-08-27 13:55:12'),
(2, 7, NULL, 1, 'Time extension risk ', 'It might cost us an extra money because the the stretching of the time ', '0', 2, 3, 'Medium', 2, 1, NULL, NULL, 1, '2025-08-26 12:16:41', '2025-08-26 13:18:32'),
(3, 3, NULL, 3, 'Some risk ', 'Some description ', '0', 1, 1, 'Low', 2, 5, NULL, NULL, 1, '2025-08-26 13:38:57', '2025-08-27 12:35:00');

-- --------------------------------------------------------

--
-- Table structure for table `risk_attachments`
--

CREATE TABLE `risk_attachments` (
  `id` int(11) NOT NULL,
  `risk_id` int(11) NOT NULL,
  `filename` varchar(255) DEFAULT NULL,
  `file_path` text DEFAULT NULL,
  `uploaded_by` int(11) DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `risk_categories`
--

CREATE TABLE `risk_categories` (
  `id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `risk_categories`
--

INSERT INTO `risk_categories` (`id`, `name`, `description`, `is_active`, `created_at`) VALUES
(1, 'Schedule', 'Risks relating to delays and schedules', 1, '2025-08-26 11:21:57'),
(2, 'Cost', 'Budgetary risks', 1, '2025-08-26 11:21:57'),
(3, 'Quality', 'Quality or performance risks', 1, '2025-08-26 11:21:57'),
(4, 'Safety', 'Health & Safety related', 1, '2025-08-26 11:21:57'),
(5, 'External', 'External / vendor / regulatory', 1, '2025-08-26 11:21:57');

-- --------------------------------------------------------

--
-- Table structure for table `risk_history`
--

CREATE TABLE `risk_history` (
  `id` int(11) NOT NULL,
  `risk_id` int(11) NOT NULL,
  `changed_by` int(11) DEFAULT NULL,
  `change_type` varchar(50) DEFAULT NULL,
  `comment` text DEFAULT NULL,
  `old_values` text DEFAULT NULL,
  `new_values` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `risk_history`
--

INSERT INTO `risk_history` (`id`, `risk_id`, `changed_by`, `change_type`, `comment`, `old_values`, `new_values`, `created_at`) VALUES
(1, 1, 1, 'created', 'Risk created: Over Cost risk ', NULL, NULL, '2025-08-26 11:48:26'),
(2, 2, 1, 'created', 'Risk created: Time extension risk ', NULL, NULL, '2025-08-26 12:16:41'),
(3, 2, 1, 'updated', 'Risk updated', NULL, NULL, '2025-08-26 13:18:32'),
(4, 2, 1, 'mitigation_added', 'Mitigation added: Try to do everything within time bound', NULL, NULL, '2025-08-26 13:22:45'),
(5, 3, 1, 'created', 'Risk created: Some risk ', NULL, NULL, '2025-08-26 13:38:57'),
(6, 3, 1, 'updated', 'Risk updated', NULL, NULL, '2025-08-26 13:39:25'),
(7, 1, 1, 'mitigation_added', 'Mitigation added: Risk mitigation action title for Over Cost risk ', NULL, NULL, '2025-08-26 13:48:58'),
(8, 1, 1, 'mitigation_added', 'Mitigation added: Risk mitigation action title for Over Cost risk 2 ', NULL, NULL, '2025-08-26 13:50:28'),
(9, 2, 1, 'mitigation_added', 'Mitigation added: Risk mitigation action title for Over Cost risk 2 ', NULL, NULL, '2025-08-26 14:02:41'),
(10, 3, 1, 'updated', 'Risk updated', NULL, NULL, '2025-08-26 14:51:31'),
(13, 1, 1, 'updated', 'Risk updated', NULL, NULL, '2025-08-27 13:55:12'),
(14, 3, 1, 'mitigation_updated', 'Mitigation updated: Some Mitigation Action Title ', NULL, NULL, '2025-08-27 15:20:40'),
(15, 2, 1, 'mitigation_added', 'Mitigation added: RMA title for Time extension risk', NULL, NULL, '2025-08-28 06:43:12'),
(16, 3, 1, 'mitigation_updated', 'Mitigation updated: Some Mitigation Action Title ', NULL, NULL, '2025-09-03 09:00:46');

-- --------------------------------------------------------

--
-- Table structure for table `risk_mitigations`
--

CREATE TABLE `risk_mitigations` (
  `id` int(11) NOT NULL,
  `risk_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `owner_user_id` int(11) DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `status` enum('open','in_progress','done','cancelled') DEFAULT 'open',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `risk_mitigations`
--

INSERT INTO `risk_mitigations` (`id`, `risk_id`, `title`, `description`, `owner_user_id`, `due_date`, `status`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 2, 'Try to do everything within time bound', 'First we will carefully look the estimated time, and then we will try to provide the necessary resources to finish the project with the expected time line. ', 1, NULL, 'in_progress', 1, '2025-08-26 13:22:45', '2025-08-27 15:07:15'),
(2, 3, 'Some Mitigation Action Title ', 'Some mitigation description', 2, '2025-08-30', 'in_progress', 1, '2025-08-26 13:38:57', '2025-09-03 09:00:46'),
(3, 1, 'Risk mitigation action title for Over Cost risk ', 'Risk mitigation action description for Over Cost risk ', 2, '2025-08-25', 'open', 1, '2025-08-26 13:48:58', '2025-08-26 13:48:58'),
(4, 1, 'Risk mitigation action title for Over Cost risk 2 ', 'Risk mitigation action description for Over Cost risk 2', 1, '2025-08-31', 'done', 1, '2025-08-26 13:50:28', '2025-08-27 07:40:49'),
(5, 2, 'Risk mitigation action title for Over Cost risk 2 ', 'fadsklfjdlakflkaf', 16, '2025-08-31', 'done', 1, '2025-08-26 14:02:41', '2025-08-27 14:28:36'),
(6, 2, 'RMA title for Time extension risk', 'RMA Description for Time extension risk', 1, '2025-08-30', 'open', 1, '2025-08-28 06:43:12', '2025-08-28 06:43:12');

-- --------------------------------------------------------

--
-- Table structure for table `risk_statuses`
--

CREATE TABLE `risk_statuses` (
  `id` int(11) NOT NULL,
  `status_key` varchar(50) NOT NULL,
  `label` varchar(100) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `risk_statuses`
--

INSERT INTO `risk_statuses` (`id`, `status_key`, `label`, `is_active`, `created_at`) VALUES
(1, 'open', 'Open', 1, '2025-08-26 11:21:57'),
(2, 'mitigated', 'Mitigated', 1, '2025-08-26 11:21:57'),
(3, 'accepted', 'Accepted', 1, '2025-08-26 11:21:57'),
(4, 'closed', 'Closed', 1, '2025-08-26 11:21:57'),
(5, 'monitor', 'Monitor', 1, '2025-08-26 11:21:57');

-- --------------------------------------------------------

--
-- Table structure for table `sub_activities`
--

CREATE TABLE `sub_activities` (
  `id` int(11) NOT NULL,
  `activity_id` int(11) NOT NULL,
  `phase_id` int(11) DEFAULT NULL,
  `project_id` int(11) DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `assigned_to` int(11) NOT NULL,
  `status` enum('pending','in_progress','completed') DEFAULT 'pending',
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sub_activities`
--

INSERT INTO `sub_activities` (`id`, `activity_id`, `phase_id`, `project_id`, `name`, `description`, `assigned_to`, `status`, `start_date`, `end_date`, `completed_at`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 2, 'Ye planning sub activity', 'Ye planning sub activity description', 13, 'completed', '2025-07-16', '2025-07-19', NULL, '2025-07-16 19:19:50', '2025-07-31 12:27:23'),
(2, 1, 1, 2, 'Ye planning sub activity2', 'Ye planning sub activity2 description', 13, 'completed', '2025-07-16', '2025-07-19', NULL, '2025-07-16 19:20:36', '2025-07-30 06:46:11'),
(3, 1, 1, 2, 'Ye planning sub activity3', 'Ye planning sub activity3 description', 13, 'completed', '2025-07-16', '2025-07-19', NULL, '2025-07-16 19:21:04', '2025-07-30 11:26:54'),
(4, 2, 1, 2, 'Ye planning activity2 sub activity1', 'Ye planning activity2 sub activity1', 13, 'pending', '2025-07-16', '2025-07-19', NULL, '2025-07-21 09:10:31', '2025-07-29 12:11:20'),
(5, 2, 1, 2, 'sudkjlfsdfa', 'fyhgfghn', 13, 'pending', '2025-07-14', '2025-07-30', NULL, '2025-07-28 07:40:52', '2025-07-29 12:11:20'),
(8, 10, 9, 3, 'sub-activity for UPF', 'sub-activity for UPF description', 13, 'pending', '2025-07-16', '2025-08-01', NULL, '2025-07-29 08:37:54', '2025-07-29 12:11:20'),
(10, 9, 1, 2, 'Morning test', 'Morning test desc', 13, 'pending', '2025-07-30', '2025-07-31', NULL, '2025-07-29 13:56:52', '2025-07-30 12:01:54'),
(14, 1, 1, 2, 'bujimbra kura second', 'bujimbra kura second description', 13, 'completed', '2025-07-30', '2025-08-02', NULL, '2025-07-31 12:35:50', '2025-07-31 12:59:12'),
(16, 9, 9, 3, 'Afternoon test', 'fdgfsfdsfgdf', 13, 'in_progress', '2025-08-19', '2025-08-19', NULL, '2025-08-01 13:42:26', '2025-09-08 13:08:54'),
(17, 9, 9, 3, 'sub-activity test with hope for upf', 'sub-activity test with hope for upf', 13, 'pending', '2025-07-31', '2025-08-06', NULL, '2025-08-01 15:19:48', '2025-09-09 08:50:30'),
(23, 25, 28, 39, 'the working car', 'must be developed in the way we need', 5, 'in_progress', '2025-09-26', '2025-09-19', NULL, '2025-09-18 13:28:33', '2025-09-18 13:28:33'),
(24, 28, 29, 3, 'wasrtzhjxcvlkj;l', '', 2, 'in_progress', '2025-09-26', '2025-09-26', NULL, '2025-09-19 07:17:52', '2025-09-19 07:17:52'),
(25, 30, 33, 36, 'project one', '', 13, 'in_progress', '2025-09-26', '2025-09-25', NULL, '2025-09-25 11:10:28', '2025-09-25 11:10:28');

-- --------------------------------------------------------

--
-- Table structure for table `sub_activity_users`
--

CREATE TABLE `sub_activity_users` (
  `id` int(11) NOT NULL,
  `sub_activity_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `systems`
--

CREATE TABLE `systems` (
  `system_id` int(11) NOT NULL,
  `system_name` varchar(100) NOT NULL,
  `system_url` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `systems`
--

INSERT INTO `systems` (`system_id`, `system_name`, `system_url`) VALUES
(1, 'Budget Management', 'http://localhost/test-manager/Budget/dashboard.php'),
(2, 'Project Management', 'http://localhost/test-manager/dashboard_project_manager.php'),
(3, 'Test Case management', 'http://localhost/test-manager/dashboard_testcase.php'),
(4, 'Change Management', 'http://localhost/test-manager/change_management_system/change_management.php'),
(5, 'Risk Management', 'http://localhost/test-manager/Risk/risks.php'),
(6, 'Issue Management', 'http://localhost/test-manager/PIM/index.php');

-- --------------------------------------------------------

--
-- Table structure for table `test_cases`
--

CREATE TABLE `test_cases` (
  `id` int(11) NOT NULL,
  `project_id` int(11) DEFAULT NULL,
  `feature_id` int(11) DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `steps` text DEFAULT NULL,
  `expected` text DEFAULT NULL,
  `status` enum('Pending','Pass','Fail','Deferred') DEFAULT 'Pending',
  `priority` enum('High','Medium','Low') DEFAULT 'Medium',
  `frequency` varchar(100) DEFAULT NULL,
  `channel` varchar(50) DEFAULT NULL,
  `tester_remark` text DEFAULT NULL,
  `vendor_comment` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `test_cases`
--

INSERT INTO `test_cases` (`id`, `project_id`, `feature_id`, `title`, `steps`, `expected`, `status`, `priority`, `frequency`, `channel`, `tester_remark`, `vendor_comment`, `created_by`, `created_at`, `updated_at`) VALUES
(2, 1, 5, 'Tap and Pay', 'First login to the system. Next click tap and pay.', 'I expect good result.', 'Pass', 'Medium', 'once a week', 'Mobile', 'I don\'t expect such unexpected result.', 'To fix this thing you should first input an integer not a string right.', 3, '2025-06-30 15:11:26', '2025-07-09 09:56:58'),
(3, 1, 5, 'Get card', 'sdfghjkl;\'lkf lfdlf;ajdsflajfp;laf kpal;fasfa;lf.', 'ujoalskdf,nmalk,f.da', 'Pass', 'Medium', 'once a week', 'Mobile', 'fioalfkjdalfkda', 'You should use integer not string', 3, '2025-06-30 22:09:51', '2025-07-09 09:02:38'),
(4, 2, 6, 'First second third', 'I don\'t expect', 'pending', 'Fail', '', 'Web', 'No remark', NULL, 'My second vendor comment at MP. ', 3, '0000-00-00 00:00:00', '2025-07-07 10:02:21'),
(5, 2, 8, 'Sample TestCase', 'First second third', 'I don\'t expect', 'Fail', 'High', 'once a week', 'Web', 'No remark', 'you have to try it again ', 3, '2025-07-01 17:10:29', '2025-07-16 11:26:23'),
(6, 2, 7, 'Wallet activation', 'First second third', 'I don\'t expect', 'Pending', 'High', 'once a week', 'Web', NULL, 'Everything is done with God Permission.', 3, '2025-07-02 13:58:25', '2025-07-04 00:26:38'),
(7, 1, 5, 'To test email', 'Just testing Email is working or not', 'The vendor comment should be sent by email', 'Pending', 'High', 'twice a week', 'Mobile', 'I wan to check whether the email come to me or not.', 'It should work!', 2, '2025-07-02 18:37:26', '2025-07-03 15:21:24'),
(8, 1, 5, 'Second Email Test', 'Second Email Test Step', 'Second Email Test Result', 'Pending', 'Medium', 'once a week', 'Mobile', 'Second Email Test Tester Remark', 'we are updating the case please try now', 3, '2025-07-03 17:23:42', '2025-09-05 09:44:13');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) DEFAULT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) DEFAULT NULL,
  `role` varchar(20) DEFAULT 'viwer',
  `system_role` enum('super_admin','tester','test_viewer','admin','pm_manager','pm_employee','pm_viewer') DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `email`, `password`, `role`, `system_role`, `created_at`, `updated_at`) VALUES
(1, 'Mikiyas', 'temp-1@example.com', '$2y$10$wmnOD0uQZ5/EaD2pJo1EnekzdArqqIeDUxQ172lBfLj3gByVmgfeC', 'tester', 'pm_manager', '2025-07-08 06:18:41', '2025-09-04 12:31:54'),
(2, 'muluken', 'jhonmiki1757@gmail.com', '$2y$10$wmnOD0uQZ5/EaD2pJo1EnekzdArqqIeDUxQ172lBfLj3gByVmgfeC', 'tester', 'tester', '2025-07-08 06:18:41', '2025-09-05 06:24:23'),
(3, 'superAdmin', 'negamuluken1@gmail.com', '$2y$10$wmnOD0uQZ5/EaD2pJo1EnekzdArqqIeDUxQ172lBfLj3gByVmgfeC', 'admin', 'super_admin', '2025-07-08 06:18:41', '2025-09-04 12:31:39'),
(4, 'User1', 'Mikiyaszewdu1757@gmail.com', '$2y$10$wmnOD0uQZ5/EaD2pJo1EnekzdArqqIeDUxQ172lBfLj3gByVmgfeC', 'viewer', 'test_viewer', '2025-07-08 06:18:41', '2025-09-05 06:24:54'),
(5, 'Admin', 'temp-5@example.com', '$2y$10$4TJ/s5RSqsaXIrd79q2Fw.bkqoWS8YpB/bU.2D0W2MaGp/2KNJHSa', 'admin', 'admin', '2025-07-08 06:18:41', '2025-07-14 07:24:37'),
(6, 'mulu', 'temp-6@example.com', '$2y$10$JmzpwjaSvxNhMpgYTe.Cbegg3LDybYR.8EQNAfy7K5jSLWZwpJipu', 'tester', 'tester', '2025-07-08 06:18:41', '2025-07-08 08:46:42'),
(7, 'Abebe', 'temp-7@example.com', '$2y$10$DDhh16lYUbM20qRfY5C1heAhAoehBET5teLlktXUXLEaSwWHmYABu', 'tester', 'pm_employee', '2025-07-08 06:18:41', '2025-08-02 20:33:18'),
(13, 'Mesfin', 'temp-13@example.com', '$2y$10$wmnOD0uQZ5/EaD2pJo1EnekzdArqqIeDUxQ172lBfLj3gByVmgfeC', 'tester', 'pm_employee', '2025-07-08 06:18:41', '2025-09-17 11:02:44'),
(14, 'fasika', 'temp-14@example.com', '$2y$10$nS.Nm5.wTN6uTbIK8Anyzuq8pEzFlgv9JLd/tUBbFmV6RGHXFYZy2', 'admin', 'super_admin', '2025-07-08 06:18:41', '2025-07-08 08:46:42'),
(15, 'Adane', 'temp-15@example.com', '$2y$10$3ZJziS5E.Yo9r8.gy50JGuYy.uoe4Bn8M70b68xFEgOMc6Q6pRPBa', 'tester', 'tester', '2025-07-08 06:18:41', '2025-07-08 08:46:42'),
(16, 'GetnetM', 'temp-16@example.com', '$2y$10$Hv.LggR0nvdFKoaWX77u9Or6XfkJQG6ZKPqRXnzzwN9H7qtoFIxMm', 'viewer', 'pm_employee', '2025-07-08 06:18:41', '2025-08-02 20:36:18'),
(17, 'Biruk', 'buraman@gmail.com', '$2y$10$aj5TKEkIREUNJJvcu6tSbuG3X09yUQ74jLaSdwz5dqcW14t5bQfn.', 'viwer', 'tester', '2025-09-02 15:21:06', '2025-09-02 15:21:06'),
(19, 'Biruk1', 'biruk1@gmail.com', '$2y$10$wmnOD0uQZ5/EaD2pJo1EnekzdArqqIeDUxQ172lBfLj3gByVmgfeC', 'viwer', 'admin', '2025-09-02 15:38:24', '2025-09-02 15:38:24'),
(20, 'Biruk2', 'biruk2@gmail.com', '$2y$10$/eftwKePFVyaQiMM5dDvduPBUawoIlvZFDXpLC3BEgkWqLpu8uX6W', 'viwer', 'tester', '2025-09-02 15:41:42', '2025-09-02 15:41:42'),
(22, 'eret', 'wretryf@gmail.com', NULL, '', NULL, '2025-09-13 08:54:44', '2025-09-13 08:54:44'),
(23, 'mul', 'negamuluken4@gmail.com', NULL, 'viwer', 'pm_manager', '2025-09-18 13:31:23', '2025-09-18 13:31:23');

-- --------------------------------------------------------

--
-- Table structure for table `user_assignments`
--

CREATE TABLE `user_assignments` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `project_id` int(11) DEFAULT NULL,
  `phase_id` int(11) DEFAULT NULL,
  `activity_id` int(11) DEFAULT NULL,
  `subactivity_id` int(11) DEFAULT NULL,
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_assignments`
--

INSERT INTO `user_assignments` (`id`, `user_id`, `project_id`, `phase_id`, `activity_id`, `subactivity_id`, `assigned_at`) VALUES
(6, 14, 29, NULL, NULL, NULL, '2025-09-15 10:50:04'),
(33, 6, NULL, 1, NULL, NULL, '2025-09-15 10:50:04'),
(34, 7, NULL, 1, NULL, NULL, '2025-09-15 10:50:04'),
(35, 13, NULL, 7, NULL, NULL, '2025-09-15 10:50:04'),
(36, 7, NULL, 8, NULL, NULL, '2025-09-15 10:50:04'),
(37, 4, NULL, 9, NULL, NULL, '2025-09-15 10:50:04'),
(38, 5, NULL, 9, NULL, NULL, '2025-09-15 10:50:04'),
(39, 6, NULL, 9, NULL, NULL, '2025-09-15 10:50:04'),
(40, 7, NULL, 9, NULL, NULL, '2025-09-15 10:50:04'),
(47, 7, NULL, NULL, 1, NULL, '2025-09-15 10:50:04'),
(48, 13, NULL, NULL, 1, NULL, '2025-09-15 10:50:04'),
(49, 16, NULL, NULL, 1, NULL, '2025-09-15 10:50:04'),
(50, 14, NULL, NULL, 8, NULL, '2025-09-15 10:50:04'),
(51, 7, NULL, NULL, 9, NULL, '2025-09-15 10:50:04'),
(52, 13, NULL, NULL, 9, NULL, '2025-09-15 10:50:04'),
(53, 13, 28, NULL, NULL, NULL, '2025-09-16 05:10:53'),
(57, 13, 37, NULL, NULL, NULL, '2025-09-16 07:26:06'),
(61, 6, NULL, 1, NULL, NULL, '2025-09-16 07:35:09'),
(62, 7, NULL, 1, NULL, NULL, '2025-09-16 07:35:09'),
(63, 13, NULL, 7, NULL, NULL, '2025-09-16 07:35:09'),
(64, 7, NULL, 8, NULL, NULL, '2025-09-16 07:35:09'),
(65, 4, NULL, 9, NULL, NULL, '2025-09-16 07:35:09'),
(66, 5, NULL, 9, NULL, NULL, '2025-09-16 07:35:09'),
(67, 6, NULL, 9, NULL, NULL, '2025-09-16 07:35:09'),
(68, 7, NULL, 9, NULL, NULL, '2025-09-16 07:35:09'),
(75, 7, 4, NULL, NULL, NULL, '2025-09-16 10:51:38'),
(76, 3, 4, NULL, NULL, NULL, '2025-09-16 10:53:03'),
(82, 15, 4, NULL, NULL, NULL, '2025-09-16 10:58:06'),
(87, 1, 1, NULL, NULL, NULL, '2025-09-17 07:24:09'),
(88, 20, 3, NULL, NULL, NULL, '2025-09-17 11:46:48'),
(94, 7, 3, NULL, NULL, NULL, '2025-09-18 14:03:46'),
(95, 15, 2, NULL, NULL, NULL, '2025-09-19 05:47:59'),
(98, 13, 3, NULL, NULL, NULL, '2025-09-19 07:26:23'),
(100, 1, 36, NULL, NULL, NULL, '2025-09-24 05:37:35'),
(101, 7, 36, NULL, NULL, NULL, '2025-09-24 05:42:24'),
(102, 13, 1, NULL, NULL, NULL, '2025-09-25 11:05:50'),
(107, 13, 1, 32, NULL, NULL, '2025-09-25 12:05:59'),
(110, 13, 36, NULL, NULL, NULL, '2025-09-25 12:24:59'),
(111, 13, 36, 33, 30, 25, '2025-09-25 12:41:33');

-- --------------------------------------------------------

--
-- Table structure for table `user_systems`
--

CREATE TABLE `user_systems` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `system_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_systems`
--

INSERT INTO `user_systems` (`id`, `user_id`, `system_id`) VALUES
(2, 1, 2),
(3, 1, 3),
(17, 1, 5),
(19, 1, 6),
(20, 2, 3),
(12, 3, 1),
(13, 3, 2),
(15, 3, 3),
(14, 3, 4),
(16, 3, 5),
(18, 3, 6),
(1, 4, 3),
(21, 13, 2),
(22, 13, 3),
(4, 17, 1),
(5, 17, 2),
(6, 17, 3),
(9, 19, 1),
(10, 19, 2),
(11, 20, 1);

-- --------------------------------------------------------

--
-- Table structure for table `user_workload`
--

CREATE TABLE `user_workload` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `date` date NOT NULL,
  `hours_assigned` decimal(5,2) DEFAULT 0.00,
  `hours_worked` decimal(5,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `vendors`
--

CREATE TABLE `vendors` (
  `id` int(11) NOT NULL,
  `vendor_name` varchar(255) DEFAULT NULL,
  `vendor_type` varchar(100) DEFAULT NULL,
  `tax_id` varchar(100) DEFAULT NULL,
  `contact_person` varchar(255) DEFAULT NULL,
  `contact_email` varchar(255) DEFAULT NULL,
  `contact_phone` varchar(20) DEFAULT NULL,
  `payment_terms` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `vendors`
--

INSERT INTO `vendors` (`id`, `vendor_name`, `vendor_type`, `tax_id`, `contact_person`, `contact_email`, `contact_phone`, `payment_terms`) VALUES
(1, 'ACI', 'Software Company', '1234567890', 'Kebede ', 'kebede@gmail.com', '0913266066', 'written payment term for delivering ACI product '),
(2, 'CR2', 'Software Company', '123456789', 'Abebe', 'Abebe@gmail.com', '0912266066', 'No payment term is mentioned ');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activities`
--
ALTER TABLE `activities`
  ADD PRIMARY KEY (`id`),
  ADD KEY `depends_on` (`depends_on`),
  ADD KEY `idx_activities_phase` (`phase_id`),
  ADD KEY `idx_activities_project` (`project_id`),
  ADD KEY `fk_activity_phase_project` (`phase_id`,`project_id`);

--
-- Indexes for table `activity_log`
--
ALTER TABLE `activity_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `issue_id` (`issue_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `test_case_id` (`test_case_id`),
  ADD KEY `project_id` (`project_id`);

--
-- Indexes for table `activity_users`
--
ALTER TABLE `activity_users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_activity_user` (`activity_id`,`user_id`),
  ADD KEY `fk_activity_users_user` (`user_id`);

--
-- Indexes for table `actual_expenses`
--
ALTER TABLE `actual_expenses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `budget_item_id` (`budget_item_id`),
  ADD KEY `vendor_id` (`vendor_id`),
  ADD KEY `fk_actual_expense_project` (`project_id`);

--
-- Indexes for table `attachments`
--
ALTER TABLE `attachments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `issue_id` (`issue_id`),
  ADD KEY `uploaded_by` (`uploaded_by`);

--
-- Indexes for table `budget_categories`
--
ALTER TABLE `budget_categories`
  ADD PRIMARY KEY (`id`),
  ADD KEY `parent_id` (`parent_id`);

--
-- Indexes for table `budget_items`
--
ALTER TABLE `budget_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `budget_category_id` (`budget_category_id`),
  ADD KEY `cost_type_id` (`cost_type_id`),
  ADD KEY `department_id` (`department_id`),
  ADD KEY `fk_budget_item_project` (`project_id`);

--
-- Indexes for table `change_logs`
--
ALTER TABLE `change_logs`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `change_request_id` (`change_request_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `change_requests`
--
ALTER TABLE `change_requests`
  ADD PRIMARY KEY (`change_request_id`),
  ADD KEY `project_id` (`project_id`),
  ADD KEY `requester_id` (`requester_id`),
  ADD KEY `assigned_to_id` (`assigned_to_id`);

--
-- Indexes for table `change_request_comments`
--
ALTER TABLE `change_request_comments`
  ADD PRIMARY KEY (`comment_id`),
  ADD KEY `change_request_id` (`change_request_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `change_statuses`
--
ALTER TABLE `change_statuses`
  ADD PRIMARY KEY (`status_id`);

--
-- Indexes for table `change_types`
--
ALTER TABLE `change_types`
  ADD PRIMARY KEY (`change_type_id`);

--
-- Indexes for table `comments`
--
ALTER TABLE `comments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `issue_id` (`issue_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `contracts`
--
ALTER TABLE `contracts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `vendor_id` (`vendor_id`);

--
-- Indexes for table `cost_types`
--
ALTER TABLE `cost_types`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `email_logs`
--
ALTER TABLE `email_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `test_case_id` (`test_case_id`),
  ADD KEY `project_id` (`project_id`),
  ADD KEY `sender_id` (`sender_id`),
  ADD KEY `recipient_id` (`recipient_id`);

--
-- Indexes for table `features`
--
ALTER TABLE `features`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_feature_project` (`project_id`);

--
-- Indexes for table `impact_areas`
--
ALTER TABLE `impact_areas`
  ADD PRIMARY KEY (`impact_area_id`);

--
-- Indexes for table `issues`
--
ALTER TABLE `issues`
  ADD PRIMARY KEY (`id`),
  ADD KEY `project_id` (`project_id`),
  ADD KEY `assigned_to` (`assigned_to`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `logs`
--
ALTER TABLE `logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `parent_budget_categories`
--
ALTER TABLE `parent_budget_categories`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `phases`
--
ALTER TABLE `phases`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uc_phase_project` (`id`,`project_id`),
  ADD UNIQUE KEY `uc_project_phase_order` (`project_id`,`Phase_order`);

--
-- Indexes for table `phase_users`
--
ALTER TABLE `phase_users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_phase_user` (`phase_id`,`user_id`),
  ADD KEY `fk_phase_users_user` (`user_id`);

--
-- Indexes for table `priorities`
--
ALTER TABLE `priorities`
  ADD PRIMARY KEY (`priority_id`);

--
-- Indexes for table `projects`
--
ALTER TABLE `projects`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `fk_project_department` (`department_id`);

--
-- Indexes for table `project_users`
--
ALTER TABLE `project_users`
  ADD PRIMARY KEY (`id`),
  ADD KEY `project_id` (`project_id`),
  ADD KEY `idx_project_users_user` (`user_id`);

--
-- Indexes for table `risks`
--
ALTER TABLE `risks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_risks_project` (`project_id`),
  ADD KEY `idx_risks_department` (`department_id`),
  ADD KEY `idx_risks_category` (`category_id`),
  ADD KEY `idx_risks_owner` (`owner_user_id`),
  ADD KEY `fk_risks_status` (`status_id`);

--
-- Indexes for table `risk_attachments`
--
ALTER TABLE `risk_attachments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_attach_risk` (`risk_id`),
  ADD KEY `fk_attach_user` (`uploaded_by`);

--
-- Indexes for table `risk_categories`
--
ALTER TABLE `risk_categories`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `risk_history`
--
ALTER TABLE `risk_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_hist_risk` (`risk_id`),
  ADD KEY `fk_history_user` (`changed_by`);

--
-- Indexes for table `risk_mitigations`
--
ALTER TABLE `risk_mitigations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_mit_risk` (`risk_id`),
  ADD KEY `idx_mit_owner` (`owner_user_id`);

--
-- Indexes for table `risk_statuses`
--
ALTER TABLE `risk_statuses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `status_key` (`status_key`);

--
-- Indexes for table `sub_activities`
--
ALTER TABLE `sub_activities`
  ADD PRIMARY KEY (`id`),
  ADD KEY `activity_id` (`activity_id`),
  ADD KEY `idx_sub_activities_assigned` (`assigned_to`),
  ADD KEY `fk_sub_phase` (`phase_id`),
  ADD KEY `fk_sub_project` (`project_id`);

--
-- Indexes for table `sub_activity_users`
--
ALTER TABLE `sub_activity_users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_sub_activity_user` (`sub_activity_id`,`user_id`),
  ADD KEY `fk_sub_activity_users_user` (`user_id`);

--
-- Indexes for table `systems`
--
ALTER TABLE `systems`
  ADD PRIMARY KEY (`system_id`);

--
-- Indexes for table `test_cases`
--
ALTER TABLE `test_cases`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `fk_feature_id` (`feature_id`),
  ADD KEY `idx_test_cases_project` (`project_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `email_2` (`email`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `user_assignments`
--
ALTER TABLE `user_assignments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_user_assignment_user` (`user_id`),
  ADD KEY `fk_user_assignment_project` (`project_id`),
  ADD KEY `fk_user_assignment_phase` (`phase_id`),
  ADD KEY `fk_user_assignment_activity` (`activity_id`),
  ADD KEY `fk_user_assignment_subactivity` (`subactivity_id`);

--
-- Indexes for table `user_systems`
--
ALTER TABLE `user_systems`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`,`system_id`),
  ADD KEY `system_id` (`system_id`);

--
-- Indexes for table `user_workload`
--
ALTER TABLE `user_workload`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`,`date`);

--
-- Indexes for table `vendors`
--
ALTER TABLE `vendors`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `activities`
--
ALTER TABLE `activities`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT for table `activity_log`
--
ALTER TABLE `activity_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `activity_logs`
--
ALTER TABLE `activity_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=35;

--
-- AUTO_INCREMENT for table `activity_users`
--
ALTER TABLE `activity_users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `actual_expenses`
--
ALTER TABLE `actual_expenses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `attachments`
--
ALTER TABLE `attachments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `budget_categories`
--
ALTER TABLE `budget_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `budget_items`
--
ALTER TABLE `budget_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `change_logs`
--
ALTER TABLE `change_logs`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `change_requests`
--
ALTER TABLE `change_requests`
  MODIFY `change_request_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `change_request_comments`
--
ALTER TABLE `change_request_comments`
  MODIFY `comment_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `change_statuses`
--
ALTER TABLE `change_statuses`
  MODIFY `status_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `change_types`
--
ALTER TABLE `change_types`
  MODIFY `change_type_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `comments`
--
ALTER TABLE `comments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `contracts`
--
ALTER TABLE `contracts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `cost_types`
--
ALTER TABLE `cost_types`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `email_logs`
--
ALTER TABLE `email_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `features`
--
ALTER TABLE `features`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `impact_areas`
--
ALTER TABLE `impact_areas`
  MODIFY `impact_area_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `issues`
--
ALTER TABLE `issues`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `logs`
--
ALTER TABLE `logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `parent_budget_categories`
--
ALTER TABLE `parent_budget_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `phases`
--
ALTER TABLE `phases`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=34;

--
-- AUTO_INCREMENT for table `phase_users`
--
ALTER TABLE `phase_users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=40;

--
-- AUTO_INCREMENT for table `priorities`
--
ALTER TABLE `priorities`
  MODIFY `priority_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `projects`
--
ALTER TABLE `projects`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=40;

--
-- AUTO_INCREMENT for table `project_users`
--
ALTER TABLE `project_users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=179;

--
-- AUTO_INCREMENT for table `risks`
--
ALTER TABLE `risks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `risk_attachments`
--
ALTER TABLE `risk_attachments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `risk_categories`
--
ALTER TABLE `risk_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `risk_history`
--
ALTER TABLE `risk_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `risk_mitigations`
--
ALTER TABLE `risk_mitigations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `risk_statuses`
--
ALTER TABLE `risk_statuses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `sub_activities`
--
ALTER TABLE `sub_activities`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;

--
-- AUTO_INCREMENT for table `sub_activity_users`
--
ALTER TABLE `sub_activity_users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `systems`
--
ALTER TABLE `systems`
  MODIFY `system_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `test_cases`
--
ALTER TABLE `test_cases`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- AUTO_INCREMENT for table `user_assignments`
--
ALTER TABLE `user_assignments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=112;

--
-- AUTO_INCREMENT for table `user_systems`
--
ALTER TABLE `user_systems`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- AUTO_INCREMENT for table `user_workload`
--
ALTER TABLE `user_workload`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `vendors`
--
ALTER TABLE `vendors`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `activities`
--
ALTER TABLE `activities`
  ADD CONSTRAINT `activities_ibfk_1` FOREIGN KEY (`phase_id`) REFERENCES `phases` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `activities_ibfk_2` FOREIGN KEY (`depends_on`) REFERENCES `activities` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_activity_phase_project` FOREIGN KEY (`phase_id`,`project_id`) REFERENCES `phases` (`id`, `project_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `activity_log`
--
ALTER TABLE `activity_log`
  ADD CONSTRAINT `activity_log_ibfk_1` FOREIGN KEY (`issue_id`) REFERENCES `issues` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `activity_log_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD CONSTRAINT `activity_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `activity_logs_ibfk_2` FOREIGN KEY (`test_case_id`) REFERENCES `test_cases` (`id`),
  ADD CONSTRAINT `activity_logs_ibfk_3` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`);

--
-- Constraints for table `activity_users`
--
ALTER TABLE `activity_users`
  ADD CONSTRAINT `fk_activity_users_activity` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_activity_users_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `actual_expenses`
--
ALTER TABLE `actual_expenses`
  ADD CONSTRAINT `actual_expenses_ibfk_1` FOREIGN KEY (`budget_item_id`) REFERENCES `budget_items` (`id`),
  ADD CONSTRAINT `actual_expenses_ibfk_2` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`),
  ADD CONSTRAINT `fk_actual_expense_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `attachments`
--
ALTER TABLE `attachments`
  ADD CONSTRAINT `attachments_ibfk_1` FOREIGN KEY (`issue_id`) REFERENCES `issues` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `attachments_ibfk_2` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `budget_categories`
--
ALTER TABLE `budget_categories`
  ADD CONSTRAINT `fk_parent_category` FOREIGN KEY (`parent_id`) REFERENCES `parent_budget_categories` (`id`);

--
-- Constraints for table `budget_items`
--
ALTER TABLE `budget_items`
  ADD CONSTRAINT `budget_items_ibfk_1` FOREIGN KEY (`budget_category_id`) REFERENCES `budget_categories` (`id`),
  ADD CONSTRAINT `budget_items_ibfk_2` FOREIGN KEY (`cost_type_id`) REFERENCES `cost_types` (`id`),
  ADD CONSTRAINT `budget_items_ibfk_3` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`),
  ADD CONSTRAINT `fk_budget_item_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `change_logs`
--
ALTER TABLE `change_logs`
  ADD CONSTRAINT `fk_change_logs_request` FOREIGN KEY (`change_request_id`) REFERENCES `change_requests` (`change_request_id`),
  ADD CONSTRAINT `fk_change_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `change_requests`
--
ALTER TABLE `change_requests`
  ADD CONSTRAINT `fk_change_requests_assigned` FOREIGN KEY (`assigned_to_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_change_requests_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  ADD CONSTRAINT `fk_change_requests_requester` FOREIGN KEY (`requester_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `change_request_comments`
--
ALTER TABLE `change_request_comments`
  ADD CONSTRAINT `fk_comments_request` FOREIGN KEY (`change_request_id`) REFERENCES `change_requests` (`change_request_id`),
  ADD CONSTRAINT `fk_comments_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `comments`
--
ALTER TABLE `comments`
  ADD CONSTRAINT `comments_ibfk_1` FOREIGN KEY (`issue_id`) REFERENCES `issues` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `comments_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `contracts`
--
ALTER TABLE `contracts`
  ADD CONSTRAINT `contracts_ibfk_1` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`);

--
-- Constraints for table `email_logs`
--
ALTER TABLE `email_logs`
  ADD CONSTRAINT `email_logs_ibfk_1` FOREIGN KEY (`test_case_id`) REFERENCES `test_cases` (`id`),
  ADD CONSTRAINT `email_logs_ibfk_2` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  ADD CONSTRAINT `email_logs_ibfk_3` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `email_logs_ibfk_4` FOREIGN KEY (`recipient_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `features`
--
ALTER TABLE `features`
  ADD CONSTRAINT `fk_feature_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `issues`
--
ALTER TABLE `issues`
  ADD CONSTRAINT `issues_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `issues_ibfk_2` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `issues_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `logs`
--
ALTER TABLE `logs`
  ADD CONSTRAINT `logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `phases`
--
ALTER TABLE `phases`
  ADD CONSTRAINT `phases_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `phase_users`
--
ALTER TABLE `phase_users`
  ADD CONSTRAINT `fk_phase_users_phase` FOREIGN KEY (`phase_id`) REFERENCES `phases` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_phase_users_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `projects`
--
ALTER TABLE `projects`
  ADD CONSTRAINT `fk_project_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `projects_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `project_users`
--
ALTER TABLE `project_users`
  ADD CONSTRAINT `project_users_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `project_users_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `risks`
--
ALTER TABLE `risks`
  ADD CONSTRAINT `fk_risks_category` FOREIGN KEY (`category_id`) REFERENCES `risk_categories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_risks_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_risks_owner` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_risks_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_risks_status` FOREIGN KEY (`status_id`) REFERENCES `risk_statuses` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `risk_attachments`
--
ALTER TABLE `risk_attachments`
  ADD CONSTRAINT `fk_attach_risk` FOREIGN KEY (`risk_id`) REFERENCES `risks` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_attach_user` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `risk_history`
--
ALTER TABLE `risk_history`
  ADD CONSTRAINT `fk_history_risk` FOREIGN KEY (`risk_id`) REFERENCES `risks` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_history_user` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `risk_mitigations`
--
ALTER TABLE `risk_mitigations`
  ADD CONSTRAINT `fk_mitigation_owner` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_mitigation_risk` FOREIGN KEY (`risk_id`) REFERENCES `risks` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `sub_activities`
--
ALTER TABLE `sub_activities`
  ADD CONSTRAINT `fk_sub_phase` FOREIGN KEY (`phase_id`) REFERENCES `phases` (`id`),
  ADD CONSTRAINT `fk_sub_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  ADD CONSTRAINT `sub_activities_ibfk_1` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `sub_activities_ibfk_2` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`);

--
-- Constraints for table `sub_activity_users`
--
ALTER TABLE `sub_activity_users`
  ADD CONSTRAINT `fk_sub_activity_users_sub_activity` FOREIGN KEY (`sub_activity_id`) REFERENCES `sub_activities` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_sub_activity_users_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `test_cases`
--
ALTER TABLE `test_cases`
  ADD CONSTRAINT `fk_feature_id` FOREIGN KEY (`feature_id`) REFERENCES `features` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `test_cases_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  ADD CONSTRAINT `test_cases_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `user_assignments`
--
ALTER TABLE `user_assignments`
  ADD CONSTRAINT `fk_user_assignment_activity` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_user_assignment_phase` FOREIGN KEY (`phase_id`) REFERENCES `phases` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_user_assignment_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_user_assignment_subactivity` FOREIGN KEY (`subactivity_id`) REFERENCES `sub_activities` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_user_assignment_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `user_systems`
--
ALTER TABLE `user_systems`
  ADD CONSTRAINT `user_systems_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `user_systems_ibfk_2` FOREIGN KEY (`system_id`) REFERENCES `systems` (`system_id`) ON DELETE CASCADE;

--
-- Constraints for table `user_workload`
--
ALTER TABLE `user_workload`
  ADD CONSTRAINT `user_workload_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
