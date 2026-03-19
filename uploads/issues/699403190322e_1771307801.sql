-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Feb 16, 2026 at 07:01 AM
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
(40, 48, 'Business Requirements Gathering', 'Collect functional and technical needs from stakeholders.', 0, 'medium', '', 0, '2026-02-21', '2026-03-01', NULL, NULL, NULL, '2026-02-10 08:52:04', '2026-02-14 07:19:58', 43),
(41, 48, 'Stakeholder Alignment', 'Align IT, Operations, Digital Banking, Vendors.', 0, 'medium', '', 0, '2026-02-20', '2026-05-02', NULL, NULL, NULL, '2026-02-10 08:52:04', '2026-02-14 07:19:58', 43),
(42, 49, 'Solution Architecture Design', 'Design technical architecture of UPF.', 0, 'medium', '', 0, '2026-02-22', '2026-09-25', NULL, NULL, NULL, '2026-02-10 09:05:28', '2026-02-14 07:19:58', 43),
(43, 49, 'Integration Design', 'Plan integrations with external systems.', 0, 'medium', '', 0, '2026-02-15', '2026-08-11', NULL, NULL, NULL, '2026-02-10 09:05:28', '2026-02-14 07:19:58', 43),
(44, 50, 'System Configuration', '', 0, 'medium', '', 0, '2026-02-21', '2026-06-28', NULL, NULL, NULL, '2026-02-10 09:06:40', '2026-02-14 07:19:58', 43),
(45, 50, 'Integration Development', '', 0, 'medium', '', 0, '2026-02-14', '2026-02-22', NULL, NULL, NULL, '2026-02-10 09:06:40', '2026-02-14 07:19:58', 43),
(46, 51, 'Functional Testing', '', 0, 'medium', '', 0, '2026-02-13', '2026-05-31', NULL, NULL, NULL, '2026-02-10 09:07:38', '2026-02-14 07:19:58', 43),
(47, 51, 'Performance & Security Testing', '', 0, 'medium', '', 0, '2026-02-12', '2026-05-10', NULL, NULL, NULL, '2026-02-10 09:07:38', '2026-02-14 07:19:58', 43),
(48, 52, 'Business & Functional Requirement Gathering', 'Identify business needs, customer expectations, compliance requirements, and operational processes for Universal payment fream worke services.', 0, 'medium', 'pending', 0, '2026-01-31', '2026-03-01', NULL, NULL, NULL, '2026-02-10 10:30:06', '2026-02-10 10:30:06', 42),
(49, 52, 'Regulatory & Risk Assessment', 'Assess regulatory compliance and potential risks related to UPF   usage.', 0, 'medium', 'pending', 0, '2026-01-30', '2026-06-25', NULL, NULL, NULL, '2026-02-10 10:30:06', '2026-02-10 10:30:06', 42),
(50, 54, 'Business Requirement Definition', 'Identify mobile payment use cases and business goals.', 0, 'medium', '', 0, '2026-02-12', '2026-04-23', NULL, NULL, NULL, '2026-02-10 10:37:18', '2026-02-14 07:12:19', 44),
(51, 54, 'Regulatory & Partner Alignment', 'Align with regulators and payment partners.', 0, 'medium', '', 0, '2026-02-05', '2026-06-10', NULL, NULL, NULL, '2026-02-10 10:37:18', '2026-02-14 07:12:19', 44),
(52, 56, 'Requirement Definition', 'Identify business and technical requirements for PIN delivery.', 0, 'medium', 'pending', 0, '2026-02-13', '2026-03-06', NULL, NULL, NULL, '2026-02-10 10:44:26', '2026-02-10 10:44:26', 45),
(53, 56, 'Security & Compliance Review', 'Security & Compliance Review', 0, 'medium', 'pending', 0, '2026-02-11', '2026-05-16', NULL, NULL, NULL, '2026-02-10 10:44:26', '2026-02-10 10:44:26', 45),
(54, 59, 'Act 1', '', 0, 'medium', '', 0, '2026-02-12', '2026-02-21', NULL, NULL, NULL, '2026-02-14 07:10:52', '2026-02-14 07:12:19', 44);

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
(309, 3, 'Project Created', 'New project \'UPF\' has been created', NULL, 42, '2026-02-10 11:40:54', 0, NULL, NULL),
(310, 3, 'Project Created', 'New project \'VCN\' has been created', NULL, 43, '2026-02-10 11:40:54', 0, NULL, NULL),
(311, 3, 'Project Created', 'New project \'MP\' has been created', NULL, 44, '2026-02-10 11:40:54', 0, NULL, NULL),
(312, 3, 'Project Created', 'New project \'Digital PIN\' has been created', NULL, 45, '2026-02-10 11:40:54', 0, NULL, NULL),
(313, 3, 'Phase Added', 'New phase \'Project Initiation & Planning\' has been added to project', NULL, 43, '2026-02-10 11:49:48', 0, NULL, NULL),
(314, 3, 'Phase Added', 'New phase \'Design\' has been added to project', NULL, 43, '2026-02-10 11:49:48', 0, NULL, NULL),
(315, 3, 'Phase Added', 'New phase \'Development & Configuration\' has been added to project', NULL, 43, '2026-02-10 11:49:48', 0, NULL, NULL),
(316, 3, 'Phase Added', 'New phase \'Testing\' has been added to project', NULL, 43, '2026-02-10 11:49:48', 0, NULL, NULL),
(317, 3, 'Activity Added', 'New activity \'Business Requirements Gathering\' has been added', NULL, 43, '2026-02-10 11:52:04', 0, NULL, NULL),
(318, 3, 'Activity Added', 'New activity \'Stakeholder Alignment\' has been added', NULL, 43, '2026-02-10 11:52:04', 0, NULL, NULL),
(319, 3, 'Sub-Activity Added', 'New sub-activity \'Identify payment channels (ATM, Mobile, POS, Switch)\' has been added', NULL, 43, '2026-02-10 12:01:07', 0, NULL, NULL),
(320, 3, 'Sub-Activity Added', 'New sub-activity \'Define transaction types and volumes\' has been added', NULL, 43, '2026-02-10 12:01:07', 0, NULL, NULL),
(321, 3, 'Sub-Activity Added', 'New sub-activity \'Document security & compliance requirements\' has been added', NULL, 43, '2026-02-10 12:01:07', 0, NULL, NULL),
(322, 3, 'Sub-Activity Added', 'New sub-activity \'Conduct stakeholder workshops\' has been added', NULL, 43, '2026-02-10 12:02:47', 0, NULL, NULL),
(323, 3, 'Sub-Activity Added', 'New sub-activity \'Define roles & responsibilities\' has been added', NULL, 43, '2026-02-10 12:02:47', 0, NULL, NULL),
(324, 3, 'Sub-Activity Added', 'New sub-activity \'Approve project charter\' has been added', NULL, 43, '2026-02-10 12:02:47', 0, NULL, NULL),
(325, 3, 'Activity Added', 'New activity \'Solution Architecture Design\' has been added', NULL, 43, '2026-02-10 12:05:28', 0, NULL, NULL),
(326, 3, 'Activity Added', 'New activity \'Integration Design\' has been added', NULL, 43, '2026-02-10 12:05:28', 0, NULL, NULL),
(327, 3, 'Activity Added', 'New activity \'System Configuration\' has been added', NULL, 43, '2026-02-10 12:06:40', 0, NULL, NULL),
(328, 3, 'Activity Added', 'New activity \'Integration Development\' has been added', NULL, 43, '2026-02-10 12:06:40', 0, NULL, NULL),
(329, 3, 'Activity Added', 'New activity \'Functional Testing\' has been added', NULL, 43, '2026-02-10 12:07:38', 0, NULL, NULL),
(330, 3, 'Activity Added', 'New activity \'Performance & Security Testing\' has been added', NULL, 43, '2026-02-10 12:07:38', 0, NULL, NULL),
(331, 3, 'Sub-Activity Added', 'New sub-activity \'Define API & message formats\' has been added', NULL, 43, '2026-02-10 13:16:30', 0, NULL, NULL),
(332, 3, 'Sub-Activity Added', 'New sub-activity \'Design EthioSwitch & Telebirr integration\' has been added', NULL, 43, '2026-02-10 13:16:30', 0, NULL, NULL),
(333, 3, 'Sub-Activity Added', 'New sub-activity \'Define security and encryption models\' has been added', NULL, 43, '2026-02-10 13:16:30', 0, NULL, NULL),
(334, 3, 'Sub-Activity Added', 'New sub-activity \'Configure routing rules\' has been added', NULL, 43, '2026-02-10 13:18:25', 0, NULL, NULL),
(335, 3, 'Sub-Activity Added', 'New sub-activity \'Setup security policies\' has been added', NULL, 43, '2026-02-10 13:18:25', 0, NULL, NULL),
(336, 3, 'Sub-Activity Added', 'New sub-activity \'Configure monitoring dashboards\' has been added', NULL, 43, '2026-02-10 13:18:25', 0, NULL, NULL),
(337, 3, 'Sub-Activity Added', 'New sub-activity \'Develop APIs\' has been added', NULL, 43, '2026-02-10 13:19:46', 0, NULL, NULL),
(338, 3, 'Sub-Activity Added', 'New sub-activity \'Implement message transformation\' has been added', NULL, 43, '2026-02-10 13:19:46', 0, NULL, NULL),
(339, 3, 'Sub-Activity Added', 'New sub-activity \'Integrate third-party services\' has been added', NULL, 43, '2026-02-10 13:19:46', 0, NULL, NULL),
(340, 3, 'Phase Added', 'New phase \'Project Initiation & Requirement Analysis\' has been added to project', NULL, 42, '2026-02-10 13:27:08', 0, NULL, NULL),
(341, 3, 'Phase Added', 'New phase \'Solution Design & Architecture\' has been added to project', NULL, 42, '2026-02-10 13:27:08', 0, NULL, NULL),
(342, 3, 'Activity Added', 'New activity \'Business & Functional Requirement Gathering\' has been added', NULL, 42, '2026-02-10 13:30:06', 0, NULL, NULL),
(343, 3, 'Activity Added', 'New activity \'Regulatory & Risk Assessment\' has been added', NULL, 42, '2026-02-10 13:30:06', 0, NULL, NULL),
(344, 3, 'Sub-Activity Added', 'New sub-activity \'Stakeholder Consultation\' has been added', NULL, 42, '2026-02-10 13:32:34', 0, NULL, NULL),
(345, 3, 'Sub-Activity Added', 'New sub-activity \'Customer Use-Case Definition\' has been added', NULL, 42, '2026-02-10 13:32:34', 0, NULL, NULL),
(346, 3, 'Sub-Activity Added', 'New sub-activity \'Requirement Documentation & Sign-off\' has been added', NULL, 42, '2026-02-10 13:32:34', 0, NULL, NULL),
(347, 3, 'Project Updated', 'Project \'MP\' has been updated by superAdmin', NULL, 44, '2026-02-10 13:33:49', 0, NULL, NULL),
(348, 3, 'Phase Added', 'New phase \'Initiation & Requirement Analysis\' has been added to project', NULL, 44, '2026-02-10 13:35:28', 0, NULL, NULL),
(349, 3, 'Phase Added', 'New phase \'Design & Architecture\' has been added to project', NULL, 44, '2026-02-10 13:35:28', 0, NULL, NULL),
(350, 3, 'Activity Added', 'New activity \'Business Requirement Definition\' has been added', NULL, 44, '2026-02-10 13:37:18', 0, NULL, NULL),
(351, 3, 'Activity Added', 'New activity \'Regulatory & Partner Alignment\' has been added', NULL, 44, '2026-02-10 13:37:18', 0, NULL, NULL),
(352, 3, 'Sub-Activity Added', 'New sub-activity \'Stakeholder Workshops\' has been added', NULL, 44, '2026-02-10 13:39:31', 0, NULL, NULL),
(353, 3, 'Sub-Activity Added', 'New sub-activity \'Customer Use-Case Analysis\' has been added', NULL, 44, '2026-02-10 13:39:31', 0, NULL, NULL),
(354, 3, 'Sub-Activity Added', 'New sub-activity \'Requirement Approval\' has been added', NULL, 44, '2026-02-10 13:39:31', 0, NULL, NULL),
(356, 3, 'Project Updated', 'Project \'UPF\' has been updated by superAdmin', NULL, 42, '2026-02-10 13:41:07', 0, NULL, NULL),
(357, 3, 'Project Updated', 'Project \'Digital PIN\' has been updated by superAdmin', NULL, 45, '2026-02-10 13:41:49', 0, NULL, NULL),
(358, 3, 'Phase Added', 'New phase \'Initiation & Compliance\' has been added to project', NULL, 45, '2026-02-10 13:43:22', 0, NULL, NULL),
(359, 3, 'Phase Added', 'New phase \'Design & Development\' has been added to project', NULL, 45, '2026-02-10 13:43:22', 0, NULL, NULL),
(360, 3, 'Activity Added', 'New activity \'Requirement Definition\' has been added', NULL, 45, '2026-02-10 13:44:26', 0, NULL, NULL),
(361, 3, 'Activity Added', 'New activity \'Security & Compliance Review\' has been added', NULL, 45, '2026-02-10 13:44:26', 0, NULL, NULL),
(362, 3, 'Sub-Activity Added', 'New sub-activity \'Stakeholder Requirement Gathering\' has been added', NULL, 45, '2026-02-10 13:45:49', 1, NULL, NULL),
(363, 3, 'Sub-Activity Added', 'New sub-activity \'Process Mapping\' has been added', NULL, 45, '2026-02-10 13:45:49', 0, NULL, NULL),
(364, 3, 'Attachment Uploaded', 'File \'Dashen_Bank_Risk_Report_2026-02-12 (1).pdf\' uploaded to issue #2', NULL, NULL, '2026-02-13 16:14:03', 0, '', 2),
(365, 3, 'Attachment Uploaded', 'File \'Dashen_Bank_Risk_Report_2026-02-12 (1).pdf\' uploaded to issue #2', NULL, NULL, '2026-02-13 16:14:04', 0, '', 2),
(366, 3, 'Issue Approved', 'Issue #2 approved', NULL, NULL, '2026-02-13 16:14:23', 0, '', 2),
(367, 3, 'Issue Assigned', 'Issue #2 assigned to Mikiyas', NULL, NULL, '2026-02-13 16:14:51', 0, '', 2),
(368, 3, 'Issue Assigned', 'Issue #2 assigned to Mikiyas', NULL, NULL, '2026-02-13 16:21:27', 0, '', 2),
(369, 3, 'Attachment Uploaded', 'File \'CertificateOfCompletion_Node.js Essential Training.pdf\' uploaded to issue #2', NULL, NULL, '2026-02-13 16:22:04', 0, '', 2),
(370, 3, 'Attachment Uploaded', 'File \'CertificateOfCompletion_Node.js Essential Training.pdf\' uploaded to issue #2', NULL, NULL, '2026-02-13 16:22:10', 0, '', 2),
(371, 3, 'Attachment Uploaded', 'File \'CertificateOfCompletion_Node.js Essential Training.pdf\' uploaded to issue #2', NULL, NULL, '2026-02-13 16:22:10', 0, '', 2),
(372, 3, 'Attachment Uploaded', 'File \'CertificateOfCompletion_Node.js Essential Training.pdf\' uploaded to issue #2', NULL, NULL, '2026-02-13 16:22:12', 0, '', 2),
(373, 3, 'Attachment Uploaded', 'File \'CertificateOfCompletion_Node.js Essential Training.pdf\' uploaded to issue #2', NULL, NULL, '2026-02-13 16:22:13', 0, '', 2),
(374, 3, 'Attachment Uploaded', 'File \'CertificateOfCompletion_Node.js Essential Training.pdf\' uploaded to issue #2', NULL, NULL, '2026-02-13 16:22:13', 0, '', 2),
(375, 3, 'Attachment Uploaded', 'File \'CertificateOfCompletion_Node.js Essential Training.pdf\' uploaded to issue #2', NULL, NULL, '2026-02-13 16:22:14', 0, '', 2),
(376, 3, 'Attachment Uploaded', 'File \'CertificateOfCompletion_Node.js Essential Training.pdf\' uploaded to issue #2', NULL, NULL, '2026-02-13 16:22:15', 0, '', 2),
(377, 3, 'Issue Updated', 'Issue #2 updated', NULL, NULL, '2026-02-13 16:39:44', 0, '', 2),
(378, 3, 'Issue Assigned', 'Issue #2 assigned to Unassigned', NULL, NULL, '2026-02-13 16:41:23', 0, '', 2),
(379, 3, 'Issue Created', 'Issue #3 created', NULL, NULL, '2026-02-13 16:50:26', 0, '', 3),
(380, 13, 'Issue Created', 'Issue #4 created', NULL, NULL, '2026-02-13 16:51:42', 0, '', 4),
(381, 5, 'Issue Approved', 'Issue #4 approved', NULL, NULL, '2026-02-13 16:53:08', 0, '', 4),
(382, 5, 'Issue Assigned', 'Issue #4 assigned to Mesfin', NULL, NULL, '2026-02-13 16:53:26', 0, '', 4),
(383, 3, 'Issue Assigned', 'Issue #3 assigned to Mikiyas', NULL, NULL, '2026-02-13 17:07:56', 0, '', 3),
(384, 3, 'Issue Assigned', 'Issue #2 assigned to Mikiyas', NULL, NULL, '2026-02-14 08:00:37', 0, '', 2),
(385, 3, 'Issue Assigned', 'Issue #4 assigned to Mikiyas', NULL, NULL, '2026-02-14 08:01:08', 0, '', 4),
(386, 3, 'Status Updated', 'Issue #4 status changed from assigned to in_progress', NULL, NULL, '2026-02-14 08:01:22', 0, '', 4),
(387, 3, 'Status Updated', 'Issue #4 status changed from in_progress to in_progress', NULL, NULL, '2026-02-14 08:01:25', 0, '', 4),
(388, 3, 'Status Updated', 'Issue #4 status changed from in_progress to in_progress', NULL, NULL, '2026-02-14 08:01:32', 0, '', 4),
(389, 3, 'Status Updated', 'Issue #4 status changed from in_progress to in_progress', NULL, NULL, '2026-02-14 08:01:35', 0, '', 4),
(390, 3, 'Status Updated', 'Issue #4 status changed from in_progress to in_progress', NULL, NULL, '2026-02-14 08:01:35', 0, '', 4),
(391, 3, 'Status Updated', 'Issue #4 status changed from in_progress to resolved', NULL, NULL, '2026-02-14 08:02:13', 0, '', 4),
(392, 3, 'Status Updated', 'Issue #4 status changed from resolved to resolved', NULL, NULL, '2026-02-14 08:02:16', 0, '', 4),
(393, 3, 'Status Updated', 'Issue #4 status changed from resolved to resolved', NULL, NULL, '2026-02-14 08:02:16', 0, '', 4),
(394, 3, 'Status Updated', 'Issue #4 status changed from resolved to resolved', NULL, NULL, '2026-02-14 08:02:18', 0, '', 4),
(395, 3, 'Status Updated', 'Issue #4 status changed from resolved to resolved', NULL, NULL, '2026-02-14 08:02:18', 0, '', 4),
(396, 3, 'Status Updated', 'Issue #4 status changed from resolved to resolved', NULL, NULL, '2026-02-14 08:02:18', 0, '', 4),
(397, 3, 'Status Updated', 'Issue #4 status changed from resolved to closed', NULL, NULL, '2026-02-14 08:02:36', 0, '', 4),
(398, 3, 'Issue Assigned', 'Issue #4 assigned to Mikiyas', NULL, NULL, '2026-02-14 08:02:48', 0, '', 4),
(399, 3, 'Issue Updated', 'Issue #4 updated', NULL, NULL, '2026-02-14 08:03:03', 0, '', 4),
(400, 13, 'Issue Created', 'Issue #5 created', NULL, NULL, '2026-02-14 08:05:53', 0, '', 5),
(401, 5, 'Issue Approved', 'Issue #5 approved', NULL, NULL, '2026-02-14 08:07:38', 0, '', 5),
(402, 5, 'Issue Assigned', 'Issue #5 assigned to GetnetM', NULL, NULL, '2026-02-14 08:08:30', 0, '', 5),
(403, 5, 'Issue Assigned', 'Issue #5 assigned to GetnetM', NULL, NULL, '2026-02-14 08:09:05', 0, '', 5),
(404, 16, 'Status Updated', 'Issue #5 status changed from assigned to in_progress', NULL, NULL, '2026-02-14 08:10:18', 0, '', 5),
(405, 16, 'Status Updated', 'Issue #5 status changed from in_progress to resolved', NULL, NULL, '2026-02-14 08:11:10', 0, '', 5),
(406, 13, 'Issue Created', 'Issue #6 created', NULL, NULL, '2026-02-14 08:13:58', 0, '', 6),
(407, 5, 'Issue Approved', 'Issue #6 approved', NULL, NULL, '2026-02-14 08:15:02', 0, '', 6),
(408, 5, 'Issue Assigned', 'Issue #6 assigned to GetnetM', NULL, NULL, '2026-02-14 08:15:14', 0, '', 6),
(409, 16, 'Status Updated', 'Issue #6 status changed from assigned to in_progress', NULL, NULL, '2026-02-14 08:15:58', 0, '', 6),
(410, 16, 'Status Updated', 'Issue #6 status changed from in_progress to in_progress', NULL, NULL, '2026-02-14 08:16:05', 0, '', 6),
(411, 16, 'Status Updated', 'Issue #6 status changed from in_progress to in_progress', NULL, NULL, '2026-02-14 08:16:08', 0, '', 6),
(412, 16, 'Status Updated', 'Issue #6 status changed from in_progress to in_progress', NULL, NULL, '2026-02-14 08:16:10', 0, '', 6),
(413, 13, 'Comment Added', 'User added a comment to issue #6', NULL, NULL, '2026-02-14 08:17:35', 0, '', 6),
(414, 5, 'Status Updated', 'Issue #6 status changed from in_progress to resolved', NULL, NULL, '2026-02-14 08:18:27', 0, '', 6),
(415, 5, 'Status Updated', 'Issue #6 status changed from resolved to resolved', NULL, NULL, '2026-02-14 08:18:31', 0, '', 6),
(416, 5, 'Status Updated', 'Issue #6 status changed from resolved to resolved', NULL, NULL, '2026-02-14 08:18:31', 0, '', 6),
(417, 16, 'Status Updated', 'Issue #6 status changed from resolved to closed', NULL, NULL, '2026-02-14 08:19:13', 0, '', 6),
(418, 16, 'Status Updated', 'Issue #6 status changed from closed to closed', NULL, NULL, '2026-02-14 08:19:16', 0, '', 6),
(419, 16, 'Issue Created', 'Issue #7 created', NULL, NULL, '2026-02-14 08:39:39', 0, '', 7),
(420, 5, 'Issue Approved', 'Issue #7 approved', NULL, NULL, '2026-02-14 08:41:50', 0, '', 7),
(421, 5, 'Issue Assigned', 'Issue #7 assigned to Mesfin', NULL, NULL, '2026-02-14 08:42:10', 0, '', 7),
(422, 13, 'Status Updated', 'Issue #7 status changed from assigned to in_progress', NULL, NULL, '2026-02-14 08:42:54', 0, '', 7),
(423, 13, 'Status Updated', 'Issue #7 status changed from in_progress to in_progress', NULL, NULL, '2026-02-14 08:43:01', 0, '', 7),
(424, 13, 'Status Updated', 'Issue #7 status changed from in_progress to in_progress', NULL, NULL, '2026-02-14 08:43:24', 0, '', 7),
(425, 13, 'Status Updated', 'Issue #7 status changed from in_progress to resolved', NULL, NULL, '2026-02-14 08:46:26', 0, '', 7),
(426, 5, 'Status Updated', 'Issue #7 status changed from resolved to closed', NULL, NULL, '2026-02-14 08:47:07', 0, '', 7),
(427, 13, 'Issue Created', 'Issue #8 created', NULL, NULL, '2026-02-14 09:03:09', 0, '', 8),
(428, 5, 'Issue Approved', 'Issue #8 approved', NULL, NULL, '2026-02-14 09:03:46', 0, '', 8),
(429, 5, 'Issue Assigned', 'Issue #8 assigned to GetnetM', NULL, NULL, '2026-02-14 09:03:57', 0, '', 8),
(430, 16, 'Status Updated', 'Issue #8 status changed from assigned to in_progress', NULL, NULL, '2026-02-14 09:04:33', 0, '', 8),
(431, 16, 'Status Updated', 'Issue #8 status changed from in_progress to in_progress', NULL, NULL, '2026-02-14 09:04:39', 0, '', 8),
(432, 16, 'Status Updated', 'Issue #8 status changed from in_progress to in_progress', NULL, NULL, '2026-02-14 09:04:55', 0, '', 8),
(433, 16, 'Status Updated', 'Issue #8 status changed from in_progress to in_progress', NULL, NULL, '2026-02-14 09:06:32', 0, '', 8),
(434, 16, 'Issue Created', 'Issue #9 created', NULL, NULL, '2026-02-14 09:18:29', 0, '', 9),
(435, 5, 'Issue Approved', 'Issue #9 approved', NULL, NULL, '2026-02-14 09:19:05', 0, '', 9),
(436, 5, 'Issue Assigned', 'Issue #9 assigned to GetnetM', NULL, NULL, '2026-02-14 09:19:20', 0, '', 9),
(437, 16, 'Status Updated', 'Issue #9 status changed from assigned to in_progress', NULL, NULL, '2026-02-14 09:19:51', 0, '', 9),
(438, 16, 'Status Updated', 'Issue #9 status changed from in_progress to resolved', NULL, NULL, '2026-02-14 09:20:40', 0, '', 9),
(439, 3, 'Project Status Updated', 'project #45 status changed from pending to in_progress by superAdmin', NULL, 45, '2026-02-14 10:08:56', 0, NULL, NULL),
(440, 3, 'Phase Added', 'New phase \'phases 1\' has been added to project', NULL, 44, '2026-02-14 10:09:56', 0, NULL, NULL),
(441, 3, 'Activity Added', 'New activity \'Act 1\' has been added', NULL, 44, '2026-02-14 10:10:52', 0, NULL, NULL),
(442, 3, 'Sub-Activity Added', 'New sub-activity \'weafdgh\' has been added', NULL, 44, '2026-02-14 10:11:21', 0, NULL, NULL),
(443, 3, 'Project Status Updated', 'project #44 status changed from pending to terminated by superAdmin', NULL, 44, '2026-02-14 10:12:19', 0, NULL, NULL),
(444, 3, 'Project Status Updated', 'project #44 status changed from terminated to in_progress by superAdmin', NULL, 44, '2026-02-14 10:17:58', 0, NULL, NULL),
(445, 3, 'Phase Status Updated', 'phase #59 status changed from  to in_progress by superAdmin', NULL, 44, '2026-02-14 10:18:06', 0, NULL, NULL),
(446, 3, 'Project Status Updated', 'project #43 status changed from pending to terminated by superAdmin', NULL, 43, '2026-02-14 10:19:58', 0, NULL, NULL),
(447, 5, 'Test Case Created', 'New test case \'ehwrjufhh\' has been created', 60, 42, '2026-02-14 11:15:45', 0, NULL, NULL),
(448, 3, 'Test Case Created', 'New test case \'rwetr\' has been created', 61, 42, '2026-02-14 11:21:11', 0, NULL, NULL),
(449, 5, 'Test Case Created', 'New test case \'rwetr\' has been created', 61, 42, '2026-02-14 11:21:11', 0, NULL, NULL),
(450, 3, 'Test Case Created', 'New test case \'s\' has been created', 62, 42, '2026-02-14 11:21:11', 0, NULL, NULL),
(451, 5, 'Test Case Created', 'New test case \'s\' has been created', 62, 42, '2026-02-14 11:21:11', 0, NULL, NULL),
(452, 4, 'Vendor Comment Updated', 'Vendor updated comment: tetst case', 61, 42, '2026-02-14 11:25:37', 0, NULL, NULL),
(453, 5, 'Issue Created', 'Issue #10 created', NULL, NULL, '2026-02-14 12:00:47', 0, '', 10),
(454, 3, 'Issue Approved', 'Issue #10 approved', NULL, NULL, '2026-02-14 12:01:45', 0, '', 10),
(455, 3, 'Issue Assigned', 'Issue #10 assigned to GetnetM', NULL, NULL, '2026-02-14 12:02:14', 0, '', 10),
(456, 16, 'Status Updated', 'Issue #10 status changed from assigned to in_progress', NULL, NULL, '2026-02-14 12:03:15', 0, '', 10),
(457, 16, 'Status Updated', 'Issue #10 status changed from in_progress to in_progress', NULL, NULL, '2026-02-14 12:03:19', 0, '', 10),
(458, 16, 'Status Updated', 'Issue #10 status changed from in_progress to resolved', NULL, NULL, '2026-02-14 12:03:56', 0, '', 10),
(459, 3, 'Comment Added', 'User added a comment to issue #10', NULL, NULL, '2026-02-14 12:06:57', 0, '', 10),
(460, 3, 'Comment Added', 'User added a comment to issue #10', NULL, NULL, '2026-02-14 13:33:36', 0, '', 10),
(461, 3, 'Status Updated', 'Issue #10 status changed from resolved to closed', NULL, NULL, '2026-02-14 13:33:48', 0, '', 10);

-- --------------------------------------------------------

--
-- Table structure for table `activity_users`
--

CREATE TABLE `activity_users` (
  `id` int(11) NOT NULL,
  `activity_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
(21, 42, '2026-02-10', 1520614.00, 'USD', NULL, 'bank_transfer', '1234567', 'Regular salaries for administrative and support staff positions', NULL, 'paid', 1, NULL),
(22, 43, '2026-02-10', 55765.00, 'USD', NULL, 'credit_card', '1234568', 'Housing subsidy provided to employees as part of compensation package', NULL, 'paid', 1, NULL),
(23, 44, '2026-02-10', 0.00, 'USD', NULL, 'credit_card', '123456789', 'Paid maternity leave benefits for pregnant employees', NULL, 'pending', NULL, NULL),
(24, 46, '2026-02-10', 29298.00, 'USD', NULL, 'credit_card', '1234567890', 'Additional pay for work beyond regular hours', NULL, 'paid', 1, NULL),
(25, 45, '2026-02-10', 151882.00, 'USD', NULL, 'bank_transfer', '12345', 'Medical expenses and health benefits for staff', NULL, 'paid', 1, NULL),
(26, 48, '2026-02-10', 9287.00, 'USD', NULL, 'credit_card', '12345678', 'Water and electricity utility bills', NULL, 'paid', 1, NULL),
(27, 49, '2026-02-10', 0.00, 'USD', NULL, 'credit_card', '123456856', 'Printer cartridges, cables, IT accessories', NULL, 'paid', 1, NULL),
(28, 47, '2026-02-10', 0.00, 'USD', NULL, 'credit_card', '3456787', 'Office supplies, paper, and writing materials', NULL, 'paid', 1, NULL),
(29, 50, '2026-02-10', 150000.00, 'USD', 5, 'bank_transfer', '54321', 'the vindors has get the expacted amounts for this integration', NULL, 'paid', 1, NULL),
(30, 42, '2026-02-14', 12223.00, 'USD', NULL, 'cash', '1234567', 'fsdolkfjaskl', NULL, 'paid', 1, NULL);

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

--
-- Dumping data for table `attachments`
--

INSERT INTO `attachments` (`id`, `issue_id`, `filename`, `filepath`, `uploaded_by`, `uploaded_at`) VALUES
(1, 2, 'Dashen_Bank_Risk_Report_2026-02-12 (1).pdf', 'uploads/issues/698f239b1acc3_1770988443.pdf', 3, '2026-02-13 13:14:03'),
(2, 2, 'Dashen_Bank_Risk_Report_2026-02-12 (1).pdf', 'uploads/issues/698f239cee36f_1770988444.pdf', 3, '2026-02-13 13:14:04'),
(3, 2, 'CertificateOfCompletion_Node.js Essential Training.pdf', 'uploads/issues/698f257c4b779_1770988924.pdf', 3, '2026-02-13 13:22:04'),
(4, 2, 'CertificateOfCompletion_Node.js Essential Training.pdf', 'uploads/issues/698f25827bab0_1770988930.pdf', 3, '2026-02-13 13:22:10'),
(5, 2, 'CertificateOfCompletion_Node.js Essential Training.pdf', 'uploads/issues/698f2582b90c3_1770988930.pdf', 3, '2026-02-13 13:22:10'),
(6, 2, 'CertificateOfCompletion_Node.js Essential Training.pdf', 'uploads/issues/698f258436f80_1770988932.pdf', 3, '2026-02-13 13:22:12'),
(7, 2, 'CertificateOfCompletion_Node.js Essential Training.pdf', 'uploads/issues/698f25858b531_1770988933.pdf', 3, '2026-02-13 13:22:13'),
(8, 2, 'CertificateOfCompletion_Node.js Essential Training.pdf', 'uploads/issues/698f2585ee951_1770988933.pdf', 3, '2026-02-13 13:22:13'),
(9, 2, 'CertificateOfCompletion_Node.js Essential Training.pdf', 'uploads/issues/698f2586305c1_1770988934.pdf', 3, '2026-02-13 13:22:14'),
(10, 2, 'CertificateOfCompletion_Node.js Essential Training.pdf', 'uploads/issues/698f2587640fa_1770988935.pdf', 3, '2026-02-13 13:22:15');

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
(14, 5, 'Salary and benefit Category', '200', '', 1, '2025-08-13 06:38:25'),
(15, 6, 'Travel Category', '300', '', 1, '2025-08-13 06:38:25'),
(17, 8, 'PROFESSIONAL & IMPLEMENTATION SERVICES', '200', 'The projects cost ', 1, '2026-02-10 12:25:29');

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
(42, 'CLERICAL STAFF SALARY', 14, 5, 7, '2026', 19392458.00, 10.00, 1939245.80, 21331703.80, '', 'approved', 3, NULL, NULL),
(43, 'HOUSING ALLOWANCE', 14, 5, 7, '2026', 786617.00, 10.00, 78661.70, 865278.70, '', 'approved', 3, NULL, NULL),
(44, 'MATERNITY PAY', 14, 5, 7, '2025', 1330.00, 10.00, 133.00, 1463.00, '', 'approved', 3, NULL, NULL),
(45, 'MEDICAL', 14, 5, 7, '2026', 601823.00, 10.00, 60182.30, 662005.30, '', 'approved', 3, NULL, NULL),
(46, 'OVERTIME PAYMENTS', 14, 5, 7, '2025', 130298.00, 10.00, 13029.80, 143327.80, '', 'approved', 3, NULL, NULL),
(47, 'STATIONERY', 13, 3, 7, '2026', 387172.32, 10.00, 38717.23, 425889.55, '', 'approved', 3, NULL, NULL),
(48, 'WATER AND LIGHT', 13, 3, 7, '2025', 0.00, 10.00, 0.00, 0.00, '', 'approved', 3, NULL, NULL),
(49, 'COMPUTER SUPPLIES', 13, 3, 7, '2025', 96789.69, 10.00, 9678.97, 106468.66, '', 'approved', 3, NULL, NULL),
(50, 'System Integration Services', 17, 3, 7, '2025', 200000.00, 10.00, 20000.00, 220000.00, '', 'approved', 3, NULL, 42),
(51, 'MATERNITY PAY', 17, 5, 7, '2025', 40000.00, 10.00, 4000.00, 44000.00, '', 'approved', 13, NULL, NULL),
(52, 'fjaslflkas.', 14, 3, 7, '2025', 100000.00, 10.00, 10000.00, 110000.00, '', '', 3, NULL, 43);

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
(41, 23, 13, 'Created', 'Change request created', '2026-02-10 13:59:29'),
(42, 23, 3, 'Approved', 'No comments provided', '2026-02-10 14:00:06'),
(43, 23, 3, 'Implementation Started', 'No comments provided', '2026-02-10 14:44:39'),
(44, 23, 13, 'Implemented', 'No comments provided', '2026-02-10 14:45:22'),
(45, 24, 13, 'Created', 'Change request created', '2026-02-10 14:46:27'),
(46, 24, 3, 'Approved', 'No comments provided', '2026-02-10 14:47:50'),
(47, 24, 13, 'Implementation Started', 'No comments provided', '2026-02-10 14:49:09'),
(48, 24, 13, 'Implemented', 'No comments provided', '2026-02-10 14:49:59'),
(49, 25, 5, 'Created', 'Change request created', '2026-02-11 05:54:58'),
(50, 25, 3, 'Approved', 'No comments provided', '2026-02-11 05:57:10'),
(51, 25, 5, 'Implementation Started', 'No comments provided', '2026-02-11 05:58:17'),
(52, 26, 13, 'Created', 'Change request created', '2026-02-11 06:09:50'),
(53, 26, 3, 'Approved', 'With comments: this implimantatiuon must be fast ', '2026-02-11 06:11:18'),
(54, 26, 5, 'Implementation Started', 'No comments provided', '2026-02-11 06:18:12'),
(55, 26, 5, 'Implemented', 'No comments provided', '2026-02-11 06:18:28'),
(56, 27, 5, 'Created', 'Change request created', '2026-02-11 06:20:35'),
(57, 27, 3, 'Approved', 'No comments provided', '2026-02-11 06:21:33'),
(58, 25, 5, 'Implemented', 'No comments provided', '2026-02-11 06:34:55'),
(59, 28, 5, 'Created', 'Change request created', '2026-02-11 06:53:04'),
(60, 29, 5, 'Created', 'Change request created', '2026-02-11 07:10:03'),
(63, 29, 13, 'Implemented', 'No comments provided', '2026-02-11 07:30:27'),
(65, 29, 5, 'Updated', 'Change request details updated', '2026-02-11 08:12:56'),
(66, 27, 5, 'Implemented', 'No comments provided', '2026-02-11 08:23:20'),
(67, 32, 5, 'Created', 'Change request created', '2026-02-11 08:34:33'),
(68, 32, 3, 'Approved', 'No comments provided', '2026-02-11 08:35:26'),
(69, 32, 5, 'Implementation Started', 'No comments provided', '2026-02-11 08:36:14'),
(70, 32, 5, 'Implemented', 'No comments provided', '2026-02-11 08:36:52'),
(71, 33, 5, 'Created', 'Change request created', '2026-02-11 08:38:10'),
(72, 33, 3, 'Approved', 'No comments provided', '2026-02-11 08:38:29'),
(73, 34, 5, 'Created', 'Change request created', '2026-02-11 08:46:32'),
(74, 34, 3, 'Approved', 'With comments: hello every one ', '2026-02-11 08:50:13'),
(75, 34, 5, 'Implementation Started', 'No comments provided', '2026-02-11 08:52:46'),
(76, 35, 13, 'Created', 'Change request created', '2026-02-14 08:34:23'),
(77, 35, 3, 'Approved', 'No comments provided', '2026-02-14 08:36:01'),
(78, 35, 13, 'Implementation Started', 'No comments provided', '2026-02-14 08:39:10'),
(79, 33, 13, 'Implementation Started', 'No comments provided', '2026-02-14 08:39:46'),
(80, 35, 13, 'Implemented', 'No comments provided', '2026-02-14 08:40:10');

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
(23, 42, 'Add SMS delivery retry for PIN failures', NULL, 'PIN SMS delivery fails when SMS gateway times out.', 'Customers unable to activate cards due to missing PIN.', 'Slight increase in SMS cost.', 'Budget', 'xtend expiry window from 5 minutes to 15 minutes.', NULL, 'create_change_request', 'Medium', 0, 'Implemented', 13, NULL, '2026-02-10 13:59:29', '2026-02-10 14:45:22', 1),
(24, 45, 'Digital PIN Scop change', NULL, 'New change request for Digital PIN', 'FKJSDOFLSD', 'FILSDJKFLASD', 'Budget', '', NULL, 'create_change_request', 'Medium', 0, 'Implemented', 13, 13, '2026-02-10 14:46:27', '2026-02-10 14:49:59', 0),
(25, 45, 'Mask PIN in audit logs', NULL, 'PIN digits partially visible in audit logs.', 'ompliance and security audit requirement.', 'No functional impact; improves compliance.', '', 'Mask sensitive fields in all logs. date_resolved: 2025-12-19', NULL, 'create_change_request', 'Medium', 0, 'Implemented', 5, NULL, '2026-02-11 05:54:58', '2026-02-11 06:34:55', 0),
(26, 45, 'SMS delivery retry for PIN failures', NULL, 'PIN SMS delivery fails when SMS gateway times out.', 'Customers unable to activate cards due to missing PIN.', 'Slight increase in SMS cost.', 'Schedule', 'xtend expiry window from 5 minutes to 15 minutes.', NULL, 'create_change_request', 'Medium', 0, 'Implemented', 13, NULL, '2026-02-11 06:09:50', '2026-02-11 06:18:28', 0),
(27, 44, 'Enable detailed transaction logs for dispute investigation', NULL, 'ssome thing', 'so;me one', 'some where', 'Schedule', '1 week', NULL, 'create_change_request', 'Medium', 0, 'Implemented', 5, NULL, '2026-02-11 06:20:35', '2026-02-11 08:23:20', 0),
(28, 45, 'Add SMS delivery retry for PIN failures', NULL, 'wasdfg', 'asdzfx', 'sdzfxcv', 'Schedule', '4', NULL, 'create_change_request', 'High', 1, 'Implemented', 5, 7, '2026-02-11 06:53:04', '2026-02-11 08:09:11', 0),
(29, 42, 'Increase VCN expiry time for failed merchant retries', NULL, 'vbhb', 'fdvfd', 'ffvd', 'Scope', '6', NULL, 'update_change_request', 'Urgent', 1, 'Implemented', 5, 14, '2026-02-11 07:10:03', '2026-02-11 08:12:56', 0),
(32, 44, 'dsf', NULL, 'sd', 'dfsv', 'vf', 'Schedule', '3 weeks', NULL, 'create_change_request', 'Medium', 0, 'Implemented', 5, NULL, '2026-02-11 08:34:33', '2026-02-11 08:36:52', 0),
(33, 45, 'Increase VCN expiry time for failed merchant retries', NULL, 'dfs', 'czx', 'dczx', '', '', NULL, 'create_change_request', 'Medium', 0, 'In Progress', 5, NULL, '2026-02-11 08:38:10', '2026-02-14 08:39:46', 0),
(34, 44, 'Enable detailed transaction logs for dispute investigation', NULL, 'dfsa', 'xzc', 'cxz', '', 'vcx', NULL, 'create_change_request', 'Medium', 0, 'In Progress', 5, NULL, '2026-02-11 08:46:32', '2026-02-11 08:52:46', 0),
(35, 45, 'Add SMS delivery retry for PIN failures', NULL, 'euihfih', 'hfejii', 'hruifhi', 'Quality', '4', NULL, 'create_change_request', 'Medium', 0, 'Implemented', 13, NULL, '2026-02-14 08:34:23', '2026-02-14 08:40:10', 0);

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

--
-- Dumping data for table `change_request_comments`
--

INSERT INTO `change_request_comments` (`comment_id`, `change_request_id`, `user_id`, `comment_text`, `comment_date`, `is_internal`) VALUES
(2, 26, 3, 'this implimantatiuon must be fast ', '2026-02-11 06:11:18', 0);

-- --------------------------------------------------------

--
-- Table structure for table `change_statuses`
--

CREATE TABLE `change_statuses` (
  `status_id` int(11) NOT NULL,
  `status_name` varchar(50) NOT NULL,
  `description` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `change_types`
--

CREATE TABLE `change_types` (
  `change_type_id` int(11) NOT NULL,
  `type_name` varchar(50) NOT NULL,
  `description` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `checkpoint_documents`
--

CREATE TABLE `checkpoint_documents` (
  `id` int(11) NOT NULL,
  `project_intake_id` int(11) NOT NULL,
  `document_type` enum('Executive Summary','Strategic Alignment','Feasibility Study','Business Case','Budget Resource','Risk Assessment','Timeline Milestones','Stakeholder Analysis','Other') DEFAULT 'Other',
  `document_name` varchar(255) NOT NULL,
  `file_path` varchar(500) DEFAULT NULL,
  `file_size` int(11) DEFAULT NULL,
  `uploaded_by` int(11) DEFAULT NULL,
  `upload_date` datetime DEFAULT current_timestamp(),
  `status` enum('Pending','Reviewed','Approved','Rejected') DEFAULT 'Pending'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `checkpoint_evaluations`
--

CREATE TABLE `checkpoint_evaluations` (
  `id` int(11) NOT NULL,
  `project_intake_id` int(11) NOT NULL,
  `review_board_member_id` int(11) DEFAULT NULL,
  `strategic_alignment_score` int(11) DEFAULT NULL,
  `financial_viability_score` int(11) DEFAULT NULL,
  `operational_readiness_score` int(11) DEFAULT NULL,
  `technical_feasibility_score` int(11) DEFAULT NULL,
  `risk_compliance_score` int(11) DEFAULT NULL,
  `urgency_score` int(11) DEFAULT NULL,
  `strategic_alignment_weighted` decimal(5,2) DEFAULT NULL,
  `financial_viability_weighted` decimal(5,2) DEFAULT NULL,
  `operational_readiness_weighted` decimal(5,2) DEFAULT NULL,
  `technical_feasibility_weighted` decimal(5,2) DEFAULT NULL,
  `risk_compliance_weighted` decimal(5,2) DEFAULT NULL,
  `urgency_weighted` decimal(5,2) DEFAULT NULL,
  `total_score` decimal(5,2) DEFAULT NULL,
  `gate_decision` enum('Accept','Revise','Reject','Defer') DEFAULT NULL,
  `decision_justification` text DEFAULT NULL,
  `feedback_to_submitter` text DEFAULT NULL,
  `recommendations` text DEFAULT NULL,
  `gate_review_date` datetime DEFAULT NULL,
  `next_review_date` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `review_notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `checkpoint_logs`
--

CREATE TABLE `checkpoint_logs` (
  `id` int(11) NOT NULL,
  `project_intake_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `details` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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

--
-- Dumping data for table `comments`
--

INSERT INTO `comments` (`id`, `issue_id`, `user_id`, `comment`, `created_at`) VALUES
(1, 6, 13, 'wdsf', '2026-02-14 05:17:35'),
(2, 7, 13, 'Status changed to In progress: dxdf', '2026-02-14 05:43:01'),
(3, 7, 13, 'Status changed to In progress: dxdf', '2026-02-14 05:43:24'),
(4, 8, 16, 'Status changed to In progress: Error updating status. Please try again.', '2026-02-14 06:04:55'),
(5, 8, 16, 'Status changed to In progress: Error updating status. Please try again.', '2026-02-14 06:06:32'),
(6, 9, 16, 'Status changed to Resolved: the project', '2026-02-14 06:20:40'),
(7, 10, 3, 'juokgdhihif', '2026-02-14 09:06:57'),
(8, 10, 3, 'hjg', '2026-02-14 10:33:36');

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
  `document_path` text DEFAULT NULL,
  `contract_type` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `contracts`
--

INSERT INTO `contracts` (`id`, `vendor_id`, `contract_name`, `contract_number`, `start_date`, `end_date`, `total_value`, `renewal_terms`, `payment_schedule`, `document_path`, `contract_type`) VALUES
(5, 5, 'UPF integration with base24', '23456', '2026-02-04', '2026-02-08', 45678.00, 'per year ', 'the new phase pass with the deliverable ', NULL, 'implementation');

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
(3, 'variable  cost', 'fixed cost ', 'Birr', 0, 10.00),
(5, 'fixed cost ', '', 'Birr', 0, 10.00);

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
  `cost_center_code` varchar(50) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `departments`
--

INSERT INTO `departments` (`id`, `department_name`, `department_code`, `manager_id`, `parent_department_id`, `cost_center_code`, `is_active`) VALUES
(7, 'Business Solution Program Management(BSPM)', '1000', NULL, NULL, '10', 1);

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
-- Table structure for table `events`
--

CREATE TABLE `events` (
  `id` int(11) NOT NULL,
  `event_name` varchar(255) NOT NULL,
  `project_id` int(11) DEFAULT NULL,
  `event_type` varchar(50) NOT NULL,
  `organizer_id` int(11) NOT NULL,
  `start_datetime` datetime NOT NULL,
  `end_datetime` datetime DEFAULT NULL,
  `location` varchar(500) NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('Planning','Upcoming','Ongoing','Completed','Cancelled') DEFAULT 'Planning',
  `priority` enum('Low','Medium','High') DEFAULT 'Medium',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `events`
--

INSERT INTO `events` (`id`, `event_name`, `project_id`, `event_type`, `organizer_id`, `start_datetime`, `end_datetime`, `location`, `description`, `status`, `priority`, `created_at`, `updated_at`) VALUES
(8, 'project kick', 44, 'Presentation', 26, '0000-00-00 00:00:00', '2026-02-28 12:13:00', 'head office', 'urtigi', 'Upcoming', 'Medium', '2026-02-14 09:13:56', '2026-02-14 09:13:56');

-- --------------------------------------------------------

--
-- Table structure for table `event_attendees`
--

CREATE TABLE `event_attendees` (
  `id` int(11) NOT NULL,
  `event_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `role_in_event` varchar(100) DEFAULT 'Participant',
  `rsvp_status` enum('Pending','Confirmed','Declined','Maybe') DEFAULT 'Pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `event_attendees`
--

INSERT INTO `event_attendees` (`id`, `event_id`, `user_id`, `role_in_event`, `rsvp_status`, `created_at`, `updated_at`) VALUES
(7, 8, 5, 'Staff', 'Pending', '2026-02-14 09:16:51', '2026-02-14 09:16:51');

-- --------------------------------------------------------

--
-- Table structure for table `event_resources`
--

CREATE TABLE `event_resources` (
  `id` int(11) NOT NULL,
  `event_id` int(11) NOT NULL,
  `resource_name` varchar(255) NOT NULL,
  `resource_type` varchar(100) DEFAULT NULL,
  `quantity` int(11) DEFAULT 1,
  `status` enum('Requested','Ordered','Delivered','In Use','Returned') DEFAULT 'Requested',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `event_resources`
--

INSERT INTO `event_resources` (`id`, `event_id`, `resource_name`, `resource_type`, `quantity`, `status`, `notes`, `created_at`, `updated_at`) VALUES
(6, 8, 'hyu', 'Equipment', 1, 'Requested', '', '2026-02-14 09:18:08', '2026-02-14 09:18:08');

-- --------------------------------------------------------

--
-- Table structure for table `event_tasks`
--

CREATE TABLE `event_tasks` (
  `id` int(11) NOT NULL,
  `event_id` int(11) NOT NULL,
  `task_name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `assigned_to` int(11) DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `status` enum('Pending','In Progress','Completed','Cancelled') DEFAULT 'Pending',
  `priority` enum('Low','Medium','High') DEFAULT 'Medium',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
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
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `features`
--

INSERT INTO `features` (`id`, `project_id`, `feature_name`, `description`, `status`, `created_at`, `updated_at`) VALUES
(30, 42, 'Transaction Routing Engine', 'Central engine that routes all incoming transactions to the correct processing system based on transaction type, channel, and business rules', 'In Progress', '2026-02-10 12:09:43', '2026-02-10 12:09:43'),
(31, 42, 'Integration with Payment Channels', 'Integrate UPF with ATM, Mobile Banking, POS, Internet Banking, EthioSwitch, Visa, and Telebirr.', 'In Progress', '2026-02-10 12:10:20', '2026-02-10 12:10:20'),
(32, 42, 'Fraud Detection & Security', 'Monitor transactions in real-time, apply fraud rules, and prevent unauthorized access.', 'In Progress', '2026-02-10 12:10:52', '2026-02-10 12:10:52'),
(33, 43, 'Virtual Card Generation', 'Allows customers to generate secure virtual card numbers linked to their main account for online transactions.', 'In Progress', '2026-02-10 12:11:37', '2026-02-10 12:11:37'),
(34, 43, 'Card Lifecycle Management', 'Activate, suspend, expire, or delete virtual cards.', 'In Progress', '2026-02-10 12:12:02', '2026-02-10 12:12:02'),
(35, 43, 'ntegration with Mobile & Internet Banking', 'nable VCN creation and management through mobile and web banking apps.', 'In Progress', '2026-02-10 12:12:31', '2026-02-10 12:12:31'),
(36, 44, 'P2P Transfers', 'Allows customers to send money to other accounts within Dashen Bank or external banks using mobile app.', 'In Progress', '2026-02-10 12:13:01', '2026-02-10 12:13:01'),
(37, 44, 'Bill Payments & Merchant Integration', 'Facilitate utility, merchant, and subscription payments directly from mobile app.', 'In Progress', '2026-02-10 12:13:40', '2026-02-10 12:13:40'),
(38, 45, 'SMS-Based PIN Delivery', 'Securely send PINs via SMS to the customer’s registered mobile number.', 'In Progress', '2026-02-10 12:14:16', '2026-02-10 12:14:16'),
(39, 45, 'PIN Reset & Retrieval', 'Allow customers to securely reset or retrieve their card PINs via mobile app or SMS.', 'In Progress', '2026-02-10 12:14:49', '2026-02-10 12:14:49'),
(40, 45, 'Open Virtual Prepaid', 'tkhh', 'In Progress', '2026-02-14 08:14:56', '2026-02-14 08:14:56');

-- --------------------------------------------------------

--
-- Table structure for table `gate_review_actions`
--

CREATE TABLE `gate_review_actions` (
  `id` int(11) NOT NULL,
  `meeting_id` int(11) DEFAULT NULL,
  `item_id` int(11) DEFAULT NULL,
  `action_description` text NOT NULL,
  `assigned_to` int(11) DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `priority` enum('Low','Medium','High','Critical') DEFAULT 'Medium',
  `status` enum('Pending','In Progress','Completed','Cancelled','Overdue') DEFAULT 'Pending',
  `completion_date` date DEFAULT NULL,
  `completion_notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `gate_review_attendees`
--

CREATE TABLE `gate_review_attendees` (
  `id` int(11) NOT NULL,
  `meeting_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `role` enum('Reviewer','Presenter','Observer','Facilitator') DEFAULT 'Observer',
  `attendance_status` enum('Invited','Confirmed','Declined','Attended','Absent') DEFAULT 'Invited'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `gate_review_documents`
--

CREATE TABLE `gate_review_documents` (
  `id` int(11) NOT NULL,
  `meeting_id` int(11) DEFAULT NULL,
  `item_id` int(11) DEFAULT NULL,
  `document_type` enum('Agenda','Presentation','Minutes','Decision','Supporting','Other') DEFAULT 'Other',
  `document_name` varchar(255) NOT NULL,
  `file_path` varchar(500) DEFAULT NULL,
  `file_size` int(11) DEFAULT NULL,
  `uploaded_by` int(11) DEFAULT NULL,
  `upload_date` datetime DEFAULT current_timestamp(),
  `version` varchar(20) DEFAULT NULL,
  `status` enum('Draft','Final','Archived') DEFAULT 'Draft'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `gate_review_items`
--

CREATE TABLE `gate_review_items` (
  `id` int(11) NOT NULL,
  `meeting_id` int(11) NOT NULL,
  `project_intake_id` int(11) NOT NULL,
  `presentation_order` int(11) DEFAULT 0,
  `presentation_time` time DEFAULT NULL,
  `decision` enum('Pending','Accept','Revise','Reject','Defer') DEFAULT 'Pending',
  `decision_notes` text DEFAULT NULL,
  `presenter_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `gate_review_meetings`
--
-- Error reading structure for table project_manager.gate_review_meetings: #1932 - Table &#039;project_manager.gate_review_meetings&#039; doesn&#039;t exist in engine
-- Error reading data for table project_manager.gate_review_meetings: #1064 - You have an error in your SQL syntax; check the manual that corresponds to your MariaDB server version for the right syntax to use near &#039;FROM `project_manager`.`gate_review_meetings`&#039; at line 1

-- --------------------------------------------------------

--
-- Table structure for table `impact_areas`
--

CREATE TABLE `impact_areas` (
  `impact_area_id` int(11) NOT NULL,
  `area_name` varchar(50) NOT NULL,
  `description` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `issues`
--

CREATE TABLE `issues` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `summary` text DEFAULT NULL,
  `status` enum('open','assigned','in_progress','resolved','closed','pending_approval','approved','rejected') DEFAULT 'open',
  `approval_status` enum('pending_approval','approved','rejected') DEFAULT 'pending_approval',
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `project_status_at_creation` varchar(50) DEFAULT NULL,
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

INSERT INTO `issues` (`id`, `title`, `description`, `summary`, `status`, `approval_status`, `approved_by`, `approved_at`, `rejection_reason`, `project_status_at_creation`, `priority`, `type`, `project_id`, `assigned_to`, `created_by`, `team`, `sprint`, `story_points`, `labels`, `created_at`, `updated_at`) VALUES
(2, 'ATM cash withdrawal transactions failing intermittently', 'ATM withdrawal transactions fail randomly with timeout error during peak hours. Steps to reproduce: perform multiple withdrawals between 12:00–1:00 PM. Error message: “UPF Gateway Timeout.”', 'Intermittent UPF timeout affecting ATM withdrawals.', 'assigned', 'approved', 3, '2026-02-13 16:14:23', NULL, NULL, 'high', 'bug', 42, 5, 13, '', '', NULL, '56', '2026-02-13 11:51:59', '2026-02-14 05:00:37'),
(3, 'rgdfc', 'wsrdfgc', 'wesrdfg', '', 'approved', NULL, NULL, NULL, '0', 'medium', 'task', 44, 5, 3, NULL, NULL, NULL, 'sdfx', '2026-02-13 13:50:26', '2026-02-13 14:07:56'),
(4, 'wqaesr', 'aszdx', 'awesrdfx', 'assigned', 'approved', 5, '2026-02-13 16:53:08', NULL, '0', 'medium', 'task', 45, 5, 13, NULL, NULL, NULL, 'asdxf', '2026-02-13 13:51:42', '2026-02-14 05:03:03'),
(5, 'PIN not delivered to customer via SMS', 'Customer requests PIN reset but does not receive SMS. SMS gateway logs show message sent but customer did not receive it.', 'PIN SMS delivery failure for some customers.', 'resolved', 'approved', 5, '2026-02-14 08:07:38', NULL, '0', 'medium', 'feature', 45, 16, 13, NULL, NULL, NULL, 'sms, pin, delivery', '2026-02-14 05:05:53', '2026-02-14 05:11:10'),
(6, 'Add retry mechanism for PIN SMS delivery', 'Add SMS retry & fallback for Digital PIN delivery.', 'Implement automatic retry when SMS delivery fails and provide fallback notification through mobile app.', 'closed', 'approved', 5, '2026-02-14 08:15:02', NULL, '0', 'medium', 'task', 45, 16, 13, NULL, NULL, NULL, 'sms, pin, delivery', '2026-02-14 05:13:58', '2026-02-14 05:19:16'),
(7, 'eerf', 'sdfsdfa', 'dfsaafds', 'closed', 'approved', 5, '2026-02-14 08:41:50', NULL, '0', 'low', 'bug', 45, 13, 16, NULL, NULL, NULL, 'dsff', '2026-02-14 05:39:39', '2026-02-14 05:47:07'),
(8, 'wwwwwwwwwwwwwwwww', 'wwwwwwwwwww', 'wwwwwwwwww', 'in_progress', 'approved', 5, '2026-02-14 09:03:46', NULL, '0', 'low', 'feature', 45, 16, 13, NULL, NULL, NULL, 'frontend', '2026-02-14 06:03:09', '2026-02-14 06:06:32'),
(9, 'ew', 'sda', 'sda', 'resolved', 'approved', 5, '2026-02-14 09:19:05', NULL, '0', 'high', 'improvement', 45, 16, 16, NULL, NULL, NULL, 're', '2026-02-14 06:18:29', '2026-02-14 06:20:40'),
(10, 'issue', 'xcvvcx', 'dsffg', 'closed', 'approved', 3, '2026-02-14 12:01:45', NULL, '0', 'medium', 'bug', 45, 16, 5, NULL, NULL, NULL, 'sfdfds', '2026-02-14 09:00:47', '2026-02-14 10:33:48');

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

-- --------------------------------------------------------

--
-- Table structure for table `milestones`
--

CREATE TABLE `milestones` (
  `id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `phase_id` int(11) DEFAULT NULL,
  `activity_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `achieved_date` date DEFAULT NULL,
  `target_date` date DEFAULT NULL,
  `is_milestone` tinyint(1) NOT NULL DEFAULT 1,
  `display_order` int(11) NOT NULL DEFAULT 0,
  `status` enum('pending','inprogress','completed') NOT NULL DEFAULT 'pending',
  `color` varchar(7) DEFAULT '#273274',
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `milestones`
--

INSERT INTO `milestones` (`id`, `project_id`, `phase_id`, `activity_id`, `title`, `name`, `description`, `start_date`, `end_date`, `achieved_date`, `target_date`, `is_milestone`, `display_order`, `status`, `color`, `created_by`, `updated_by`, `created_at`, `updated_at`, `meta`) VALUES
(44, 43, 48, 40, '', 'VCN Project Approved', 'Project charter approved by PMO and stakeholders. Budget, scope, and timeline confirmed.', '0000-00-00', NULL, NULL, '2026-02-07', 1, 0, '', '#273274', NULL, NULL, '2026-02-14 06:24:41', '2026-02-14 07:30:49', NULL),
(45, 43, 49, 41, '', 'VCN', 'Project charter approved by PMO and stakeholders. Budget, scope, and timeline confirmed.', '0000-00-00', NULL, '2026-02-14', '2026-02-19', 1, 0, '', '#273274', NULL, NULL, '2026-02-14 07:28:57', '2026-02-14 07:30:31', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `type` enum('info','success','warning','danger','primary') DEFAULT 'info',
  `related_module` varchar(100) DEFAULT NULL,
  `related_id` int(11) DEFAULT NULL,
  `related_user_id` int(11) DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `is_archived` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `priority` enum('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  `action_by` int(11) DEFAULT NULL,
  `action_by_name` varchar(100) DEFAULT NULL,
  `metadata` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `user_id`, `title`, `message`, `type`, `related_module`, `related_id`, `related_user_id`, `is_read`, `is_archived`, `created_at`, `priority`, `action_by`, `action_by_name`, `metadata`) VALUES
(191, 5, 'Change Request Approved', 'Your change request #30 has been approved by the Super Admin. You can now proceed with implementation.', 'success', 'change_request', 30, NULL, 1, 0, '2026-02-11 07:29:42', 'normal', NULL, NULL, NULL),
(192, 7, 'New Approved Change Request', 'Change request #30 has been approved by the Super Admin and is ready for implementation.', 'info', 'change_request', 30, NULL, 0, 0, '2026-02-11 07:29:42', 'normal', NULL, NULL, NULL),
(193, 13, 'New Approved Change Request', 'Change request #30 has been approved by the Super Admin and is ready for implementation.', 'info', 'change_request', 30, NULL, 1, 0, '2026-02-11 07:29:42', 'normal', NULL, NULL, NULL),
(194, 16, 'New Approved Change Request', 'Change request #30 has been approved by the Super Admin and is ready for implementation.', 'info', 'change_request', 30, NULL, 1, 0, '2026-02-11 07:29:42', 'normal', NULL, NULL, NULL),
(195, 27, 'New Approved Change Request', 'Change request #30 has been approved by the Super Admin and is ready for implementation.', 'info', 'change_request', 30, NULL, 0, 0, '2026-02-11 07:29:42', 'normal', NULL, NULL, NULL),
(196, 3, 'Implementation Completed', 'Implementation has been completed for change request #29 by Mesfin', 'success', 'change_request', 29, NULL, 1, 0, '2026-02-11 07:30:27', 'normal', NULL, NULL, NULL),
(197, 14, 'Implementation Completed', 'Implementation has been completed for change request #29 by Mesfin', 'success', 'change_request', 29, NULL, 0, 0, '2026-02-11 07:30:27', 'normal', NULL, NULL, NULL),
(198, 26, 'Implementation Completed', 'Implementation has been completed for change request #29 by Mesfin', 'success', 'change_request', 29, NULL, 0, 0, '2026-02-11 07:30:27', 'normal', NULL, NULL, NULL),
(199, 5, 'Change Request Implemented', 'Your change request #29 has been marked as implemented.', 'success', 'change_request', 29, NULL, 1, 0, '2026-02-11 07:30:27', 'normal', NULL, NULL, NULL),
(200, 3, 'Implementation Completed', 'Implementation has been completed for change request #27 by Mikiyas', 'success', 'change_request', 27, NULL, 1, 0, '2026-02-11 08:23:20', 'normal', NULL, NULL, NULL),
(201, 14, 'Implementation Completed', 'Implementation has been completed for change request #27 by Mikiyas', 'success', 'change_request', 27, NULL, 0, 0, '2026-02-11 08:23:20', 'normal', NULL, NULL, NULL),
(202, 26, 'Implementation Completed', 'Implementation has been completed for change request #27 by Mikiyas', 'success', 'change_request', 27, NULL, 0, 0, '2026-02-11 08:23:20', 'normal', NULL, NULL, NULL),
(203, 5, 'Change Request Approved', 'Your change request #32 has been approved by the Super Admin. You can now proceed with implementation.', 'success', 'change_request', 32, NULL, 1, 0, '2026-02-11 08:35:26', 'normal', NULL, NULL, NULL),
(204, 7, 'New Approved Change Request', 'Change request #32 has been approved by the Super Admin and is ready for implementation.', 'info', 'change_request', 32, NULL, 0, 0, '2026-02-11 08:35:26', 'normal', NULL, NULL, NULL),
(205, 13, 'New Approved Change Request', 'Change request #32 has been approved by the Super Admin and is ready for implementation.', 'info', 'change_request', 32, NULL, 1, 0, '2026-02-11 08:35:26', 'normal', NULL, NULL, NULL),
(206, 16, 'New Approved Change Request', 'Change request #32 has been approved by the Super Admin and is ready for implementation.', 'info', 'change_request', 32, NULL, 1, 0, '2026-02-11 08:35:26', 'normal', NULL, NULL, NULL),
(207, 27, 'New Approved Change Request', 'Change request #32 has been approved by the Super Admin and is ready for implementation.', 'info', 'change_request', 32, NULL, 0, 0, '2026-02-11 08:35:26', 'normal', NULL, NULL, NULL),
(209, 14, 'Implementation Started', 'Implementation has started for change request #32 by Mikiyas', 'warning', 'change_request', 32, NULL, 0, 0, '2026-02-11 08:36:14', 'normal', NULL, NULL, NULL),
(210, 26, 'Implementation Started', 'Implementation has started for change request #32 by Mikiyas', 'warning', 'change_request', 32, NULL, 0, 0, '2026-02-11 08:36:14', 'normal', NULL, NULL, NULL),
(211, 3, 'Implementation Completed', 'Implementation has been completed for change request #32 by Mikiyas', 'success', 'change_request', 32, NULL, 1, 0, '2026-02-11 08:36:52', 'normal', NULL, NULL, NULL),
(212, 14, 'Implementation Completed', 'Implementation has been completed for change request #32 by Mikiyas', 'success', 'change_request', 32, NULL, 0, 0, '2026-02-11 08:36:52', 'normal', NULL, NULL, NULL),
(213, 26, 'Implementation Completed', 'Implementation has been completed for change request #32 by Mikiyas', 'success', 'change_request', 32, NULL, 0, 0, '2026-02-11 08:36:52', 'normal', NULL, NULL, NULL),
(214, 5, 'Change Request Approved', 'Your change request #33 has been approved by the Super Admin. You can now proceed with implementation.', 'success', 'change_request', 33, NULL, 1, 0, '2026-02-11 08:38:29', 'normal', NULL, NULL, NULL),
(215, 7, 'New Approved Change Request', 'Change request #33 has been approved by the Super Admin and is ready for implementation.', 'info', 'change_request', 33, NULL, 0, 0, '2026-02-11 08:38:29', 'normal', NULL, NULL, NULL),
(216, 13, 'New Approved Change Request', 'Change request #33 has been approved by the Super Admin and is ready for implementation.', 'info', 'change_request', 33, NULL, 1, 0, '2026-02-11 08:38:29', 'normal', NULL, NULL, NULL),
(217, 16, 'New Approved Change Request', 'Change request #33 has been approved by the Super Admin and is ready for implementation.', 'info', 'change_request', 33, NULL, 1, 0, '2026-02-11 08:38:29', 'normal', NULL, NULL, NULL),
(218, 27, 'New Approved Change Request', 'Change request #33 has been approved by the Super Admin and is ready for implementation.', 'info', 'change_request', 33, NULL, 0, 0, '2026-02-11 08:38:29', 'normal', NULL, NULL, NULL),
(219, 5, 'Change Request Approved', 'Your change request #34 has been approved by the Super Admin. You can now proceed with implementation.', 'success', 'change_request', 34, NULL, 1, 0, '2026-02-11 08:50:13', 'normal', NULL, NULL, '{\"old_status\":\"Approved\",\"new_status\":\"Approved\",\"action\":\"Approved\",\"performed_by\":\"superAdmin\",\"performer_role\":\"super_admin\",\"change_request_id\":\"34\",\"comments\":\"hello every one \",\"change_title\":\"Change Request\",\"timestamp\":\"2026-02-11 09:50:13\"}'),
(220, 7, 'New Approved Change Request', 'Change request #34 has been approved by the Super Admin and is ready for implementation.', 'info', 'change_request', 34, NULL, 0, 0, '2026-02-11 08:50:13', 'normal', NULL, NULL, '{\"old_status\":\"Approved\",\"new_status\":\"Approved\",\"action\":\"Approved\",\"performed_by\":\"superAdmin\",\"performer_role\":\"super_admin\",\"change_request_id\":\"34\",\"requester_name\":\"Mikiyas\",\"requester_role\":\"pm_manager\",\"change_title\":\"Change Request\",\"priority\":\"Medium\",\"timestamp\":\"2026-02-11 09:50:13\"}'),
(221, 13, 'New Approved Change Request', 'Change request #34 has been approved by the Super Admin and is ready for implementation.', 'info', 'change_request', 34, NULL, 1, 0, '2026-02-11 08:50:13', 'normal', NULL, NULL, '{\"old_status\":\"Approved\",\"new_status\":\"Approved\",\"action\":\"Approved\",\"performed_by\":\"superAdmin\",\"performer_role\":\"super_admin\",\"change_request_id\":\"34\",\"requester_name\":\"Mikiyas\",\"requester_role\":\"pm_manager\",\"change_title\":\"Change Request\",\"priority\":\"Medium\",\"timestamp\":\"2026-02-11 09:50:13\"}'),
(222, 16, 'New Approved Change Request', 'Change request #34 has been approved by the Super Admin and is ready for implementation.', 'info', 'change_request', 34, NULL, 1, 0, '2026-02-11 08:50:13', 'normal', NULL, NULL, '{\"old_status\":\"Approved\",\"new_status\":\"Approved\",\"action\":\"Approved\",\"performed_by\":\"superAdmin\",\"performer_role\":\"super_admin\",\"change_request_id\":\"34\",\"requester_name\":\"Mikiyas\",\"requester_role\":\"pm_manager\",\"change_title\":\"Change Request\",\"priority\":\"Medium\",\"timestamp\":\"2026-02-11 09:50:13\"}'),
(223, 27, 'New Approved Change Request', 'Change request #34 has been approved by the Super Admin and is ready for implementation.', 'info', 'change_request', 34, NULL, 0, 0, '2026-02-11 08:50:13', 'normal', NULL, NULL, '{\"old_status\":\"Approved\",\"new_status\":\"Approved\",\"action\":\"Approved\",\"performed_by\":\"superAdmin\",\"performer_role\":\"super_admin\",\"change_request_id\":\"34\",\"requester_name\":\"Mikiyas\",\"requester_role\":\"pm_manager\",\"change_title\":\"Change Request\",\"priority\":\"Medium\",\"timestamp\":\"2026-02-11 09:50:13\"}'),
(224, 5, 'New Comment on Change Request', 'A new comment has been added to your change request #34', 'info', 'change_request', 34, NULL, 1, 0, '2026-02-11 08:50:13', 'normal', NULL, NULL, '{\"action\":\"Comment Added\",\"performed_by\":\"superAdmin\",\"performer_role\":\"super_admin\",\"change_request_id\":\"34\",\"change_title\":\"Enable detailed transaction logs for dispute investigation\",\"current_status\":\"Approved\",\"priority\":\"Medium\",\"comments\":\"hello every one \",\"timestamp\":\"2026-02-11 09:50:13\"}'),
(225, 5, 'New Comment on Change Request', 'A new comment has been added to your change request #34: hello every one ...', 'info', 'change_request', 34, NULL, 1, 0, '2026-02-11 08:50:13', 'normal', NULL, NULL, NULL),
(226, 3, 'Implementation Started', 'Implementation has started for change request #34 by Mikiyas', 'warning', 'change_request', 34, NULL, 1, 0, '2026-02-11 08:52:46', 'normal', NULL, NULL, '{\"old_status\":\"In Progress\",\"new_status\":\"In Progress\",\"action\":\"Implementation Started\",\"performed_by\":\"Mikiyas\",\"performer_role\":\"pm_manager\",\"change_request_id\":\"34\",\"change_title\":\"Enable detailed transaction logs for dispute investigation\",\"requester_name\":\"Mikiyas\",\"requester_role\":\"pm_manager\",\"priority\":\"Medium\",\"comments\":null,\"timestamp\":\"2026-02-11 09:52:46\"}'),
(227, 14, 'Implementation Started', 'Implementation has started for change request #34 by Mikiyas', 'warning', 'change_request', 34, NULL, 0, 0, '2026-02-11 08:52:46', 'normal', NULL, NULL, '{\"old_status\":\"In Progress\",\"new_status\":\"In Progress\",\"action\":\"Implementation Started\",\"performed_by\":\"Mikiyas\",\"performer_role\":\"pm_manager\",\"change_request_id\":\"34\",\"change_title\":\"Enable detailed transaction logs for dispute investigation\",\"requester_name\":\"Mikiyas\",\"requester_role\":\"pm_manager\",\"priority\":\"Medium\",\"comments\":null,\"timestamp\":\"2026-02-11 09:52:46\"}'),
(228, 26, 'Implementation Started', 'Implementation has started for change request #34 by Mikiyas', 'warning', 'change_request', 34, NULL, 0, 0, '2026-02-11 08:52:46', 'normal', NULL, NULL, '{\"old_status\":\"In Progress\",\"new_status\":\"In Progress\",\"action\":\"Implementation Started\",\"performed_by\":\"Mikiyas\",\"performer_role\":\"pm_manager\",\"change_request_id\":\"34\",\"change_title\":\"Enable detailed transaction logs for dispute investigation\",\"requester_name\":\"Mikiyas\",\"requester_role\":\"pm_manager\",\"priority\":\"Medium\",\"comments\":null,\"timestamp\":\"2026-02-11 09:52:46\"}'),
(229, 5, 'New Assignment', 'Super Admin superAdmin assigned you to Project: Digital PIN', 'info', 'project', 45, NULL, 1, 0, '2026-02-11 13:28:21', 'normal', NULL, NULL, NULL),
(230, 5, 'New Assignment', 'Super Admin superAdmin assigned you to Project: MP', 'info', 'project', 44, NULL, 1, 0, '2026-02-11 13:28:33', 'normal', NULL, NULL, NULL),
(231, 5, 'New Assignment', 'Super Admin superAdmin assigned you to Project: UPF', 'info', 'project', 42, NULL, 1, 0, '2026-02-11 13:28:46', 'normal', NULL, NULL, NULL),
(232, 13, 'New Assignment', 'PM Manager Mikiyas assigned you to Project: Digital PIN', 'info', 'project', 45, NULL, 1, 0, '2026-02-11 13:30:15', 'normal', NULL, NULL, NULL),
(233, 16, 'New Assignment', 'Super Admin superAdmin assigned you to Project: Digital PIN', 'info', 'project', 45, NULL, 1, 0, '2026-02-12 10:15:44', 'normal', NULL, NULL, NULL),
(234, 16, 'Risk Approved', 'superAdmin approved your risk: \'two\'', 'success', 'risk', 11, 3, 1, 0, '2026-02-12 12:53:10', 'normal', NULL, NULL, '{\"risk_id\":11,\"risk_title\":\"two\",\"approved\":true,\"approver_id\":3,\"approver_name\":\"superAdmin\",\"rejection_reason\":\"\",\"project_id\":45,\"project_name\":\"Digital PIN\",\"action\":\"risk_approved\",\"timestamp\":\"2026-02-12 13:53:10\"}'),
(235, 16, 'Risk Assessment Updated', 'superAdmin updated risk assessment for \'two\': Likelihood 1→1, Impact 1→4, Score 1→4 (Low→Low)', 'info', 'risk', 11, 3, 1, 0, '2026-02-12 12:53:29', 'normal', NULL, NULL, '{\"risk_id\":11,\"project_id\":45,\"project_name\":\"Digital PIN\",\"old_likelihood\":1,\"new_likelihood\":1,\"old_impact\":1,\"new_impact\":4,\"old_score\":1,\"new_score\":4,\"old_level\":\"Low\",\"new_level\":\"Low\",\"assessed_by\":3,\"assessor_name\":\"superAdmin\",\"risk_title\":\"two\",\"action\":\"risk_assessed\",\"timestamp\":\"2026-02-12 13:53:29\"}'),
(236, 16, 'Response Plan Updated', 'superAdmin updated response plan for risk \'two\': Strategy: Mitigate, Target: Feb 13, 2026', 'info', 'risk', 11, 3, 1, 0, '2026-02-12 12:53:40', 'normal', NULL, NULL, '{\"risk_id\":11,\"risk_title\":\"two\",\"response_strategy\":\"Mitigate\",\"target_resolution_date\":\"2026-02-13\",\"updated_by\":3,\"updater_name\":\"superAdmin\",\"project_id\":45,\"project_name\":\"Digital PIN\",\"action\":\"response_plan_updated\",\"timestamp\":\"2026-02-12 13:53:40\"}'),
(237, 13, 'Risk Owner Assignment', 'You have been assigned as the owner for risk: \'two\' in project \'Digital PIN\'', 'primary', 'risk', 11, 3, 1, 0, '2026-02-12 12:53:58', 'normal', NULL, NULL, '{\"risk_id\":11,\"project_id\":45,\"project_name\":\"Digital PIN\",\"owner_user_id\":13,\"assigned_by\":3,\"assigner_name\":\"superAdmin\",\"risk_title\":\"two\",\"action\":\"owner_assigned\",\"timestamp\":\"2026-02-12 13:53:58\"}'),
(238, 16, 'Risk Owner Assigned', 'superAdmin assigned Mesfin as owner for risk: \'two\'', 'info', 'risk', 11, 3, 1, 0, '2026-02-12 12:53:58', 'normal', NULL, NULL, '{\"risk_id\":11,\"project_id\":45,\"project_name\":\"Digital PIN\",\"owner_user_id\":13,\"assigned_by\":3,\"assigner_name\":\"superAdmin\",\"risk_title\":\"two\",\"action\":\"owner_assigned\",\"timestamp\":\"2026-02-12 13:53:58\"}'),
(239, 16, 'Risk Status Changed', 'Risk \'ab\' status has been changed by superAdmin', 'info', 'risk', 9, NULL, 1, 0, '2026-02-12 13:50:39', 'normal', NULL, NULL, '{\"risk_id\":9,\"risk_title\":\"ab\",\"actor\":\"superAdmin\",\"action\":\"status_changed\"}'),
(240, 13, 'Risk Notification', 'Your risk \'ab\' has been updated by superAdmin', 'info', 'risk', 9, NULL, 1, 0, '2026-02-12 13:50:39', 'normal', NULL, NULL, '{\"risk_id\":9,\"risk_title\":\"ab\",\"actor\":\"superAdmin\",\"action\":\"updated\"}'),
(241, 16, 'Risk Closed', 'Risk \'ab\' has been closed by superAdmin', 'success', 'risk', 9, 3, 1, 0, '2026-02-12 13:50:39', 'normal', NULL, NULL, '{\"risk_id\":9,\"risk_title\":\"ab\",\"action\":\"closed\"}'),
(242, 13, 'Risk Closed', 'Risk \'ab\' has been closed by superAdmin', 'success', 'risk', 9, 3, 1, 0, '2026-02-12 13:50:39', 'normal', NULL, NULL, '{\"risk_id\":9,\"risk_title\":\"ab\",\"action\":\"closed\"}'),
(243, 5, 'Risk Closed', 'Risk \'ab\' has been closed by superAdmin', 'success', 'risk', 9, 3, 1, 0, '2026-02-12 13:50:39', 'normal', NULL, NULL, '{\"risk_id\":9,\"risk_title\":\"ab\",\"action\":\"closed\"}'),
(244, 16, 'Risk Updated', 'Risk \'ab\' has been updated by Mikiyas', 'info', 'risk', 9, NULL, 1, 0, '2026-02-12 13:55:20', 'normal', NULL, NULL, '{\"risk_id\":9,\"risk_title\":\"ab\",\"actor\":\"Mikiyas\",\"action\":\"updated\"}'),
(245, 13, 'Risk Notification', 'Your risk \'ab\' has been updated by Mikiyas', 'info', 'risk', 9, NULL, 1, 0, '2026-02-12 13:55:20', 'normal', NULL, NULL, '{\"risk_id\":9,\"risk_title\":\"ab\",\"actor\":\"Mikiyas\",\"action\":\"updated\"}'),
(246, 16, 'Risk Updated', 'Risk \'ab\' has been updated by Mikiyas', 'info', 'risk', 9, NULL, 1, 0, '2026-02-12 13:55:29', 'normal', NULL, NULL, '{\"risk_id\":9,\"risk_title\":\"ab\",\"actor\":\"Mikiyas\",\"action\":\"updated\"}'),
(247, 13, 'Risk Owner Assigned', 'You have been assigned as owner of risk: \'PIN Compromise During Digital Delivery\'', 'success', 'risk', 8, NULL, 1, 0, '2026-02-12 13:57:44', 'normal', NULL, NULL, '{\"risk_id\":8,\"risk_title\":\"PIN Compromise During Digital Delivery\",\"actor\":\"Mikiyas\",\"action\":\"assigned\"}'),
(248, 13, '📝 Risk Updated', 'Risk \'PIN Compromise During Digital Delivery\' has been updated by superAdmin', 'info', 'risk', 8, NULL, 1, 0, '2026-02-13 05:55:34', 'normal', NULL, NULL, '{\"risk_id\":8,\"risk_title\":\"PIN Compromise During Digital Delivery\",\"actor\":\"superAdmin\",\"action\":\"updated\"}'),
(249, 13, '📝 Risk Updated', 'Risk \'PIN Compromise During Digital Delivery\' has been updated by superAdmin', 'info', 'risk', 8, NULL, 1, 0, '2026-02-13 05:55:47', 'normal', NULL, NULL, '{\"risk_id\":8,\"risk_title\":\"PIN Compromise During Digital Delivery\",\"actor\":\"superAdmin\",\"action\":\"updated\"}'),
(250, 16, '👤 Risk Owner Assigned', 'You have been assigned as owner of risk: \'PIN Compromise During Digital Delivery\'', 'success', 'risk', 8, NULL, 1, 0, '2026-02-13 05:56:08', 'normal', NULL, NULL, '{\"risk_id\":8,\"risk_title\":\"PIN Compromise During Digital Delivery\",\"actor\":\"superAdmin\",\"action\":\"assigned\"}'),
(251, 13, '👤 Risk Owner Removed', 'You are no longer the owner of risk \'PIN Compromise During Digital Delivery\'. New owner: GetnetM', 'info', 'risk', 8, 3, 1, 0, '2026-02-13 05:56:08', 'normal', NULL, NULL, '{\"risk_id\":8,\"risk_title\":\"PIN Compromise During Digital Delivery\",\"action\":\"owner_removed\",\"new_owner\":\"GetnetM\"}'),
(252, 16, '📊 Risk Status Changed', '📊 Risk \'PIN Compromise During Digital Delivery\' status has been changed by superAdmin', 'info', 'risk', 8, NULL, 1, 0, '2026-02-13 05:56:16', 'normal', NULL, NULL, '{\"risk_id\":8,\"risk_title\":\"PIN Compromise During Digital Delivery\",\"actor\":\"superAdmin\",\"action\":\"status_changed\"}'),
(253, 13, 'Risk Notification', 'Your risk \'PIN Compromise During Digital Delivery\' has been updated by superAdmin', 'info', 'risk', 8, NULL, 1, 0, '2026-02-13 05:56:16', 'normal', NULL, NULL, '{\"risk_id\":8,\"risk_title\":\"PIN Compromise During Digital Delivery\",\"actor\":\"superAdmin\",\"action\":\"updated\"}'),
(254, 13, '🛡️ Mitigation Action Assigned', '🛡️ You have been assigned a mitigation action: \'dsafzx\' for risk \'PIN Compromise During Digital Delivery\'', 'info', 'risk_mitigation', 8, NULL, 1, 0, '2026-02-13 05:56:35', 'normal', NULL, NULL, '{\"risk_id\":8,\"risk_title\":\"PIN Compromise During Digital Delivery\",\"mitigation_title\":\"dsafzx\",\"actor\":\"superAdmin\",\"action\":\"assigned\"}'),
(255, 16, '🛡️ Mitigation Added', 'A new mitigation action \'dsafzx\' has been added to risk \'PIN Compromise During Digital Delivery\' by superAdmin', 'info', 'risk_mitigation', 8, 3, 1, 0, '2026-02-13 05:56:35', 'normal', NULL, NULL, '{\"risk_id\":8,\"risk_title\":\"PIN Compromise During Digital Delivery\",\"mitigation_title\":\"dsafzx\",\"project\":\"Digital PIN\",\"action\":\"mitigation_added\"}'),
(256, 3, '⚠️ Risk Approval Required', 'Risk \'only one\' requires your approval. Reported by: Mesfin', 'warning', 'risk', 12, NULL, 1, 0, '2026-02-13 06:44:53', 'normal', NULL, NULL, '{\"risk_id\":12,\"risk_title\":\"only one\",\"project_id\":45,\"reporter\":\"Mesfin\",\"action\":\"approval_required\"}'),
(257, 5, '⚠️ Risk Approval Required', 'Risk \'only one\' requires your approval. Reported by: Mesfin', 'warning', 'risk', 12, NULL, 1, 0, '2026-02-13 06:44:53', 'normal', NULL, NULL, '{\"risk_id\":12,\"risk_title\":\"only one\",\"project_id\":45,\"reporter\":\"Mesfin\",\"action\":\"approval_required\"}'),
(258, 16, '📋 New Risk Reported', 'Mesfin reported a new risk: \'only one\' in project Digital PIN - Pending Review', 'info', 'risk', 12, 13, 1, 0, '2026-02-13 06:44:53', 'normal', NULL, NULL, '{\"risk_id\":12,\"risk_title\":\"only one\",\"project\":\"Digital PIN\",\"action\":\"created\"}'),
(259, 5, '📋 New Risk Reported', 'Mesfin reported a new risk: \'only one\' in project Digital PIN - Pending Review', 'info', 'risk', 12, 13, 1, 0, '2026-02-13 06:44:53', 'normal', NULL, NULL, '{\"risk_id\":12,\"risk_title\":\"only one\",\"project\":\"Digital PIN\",\"action\":\"created\"}'),
(260, 3, '📋 New Risk Reported', 'Mesfin reported a new risk: \'only one\' in project Digital PIN - Pending Review', 'info', 'risk', 12, 13, 1, 0, '2026-02-13 06:44:53', 'normal', NULL, NULL, '{\"risk_id\":12,\"risk_title\":\"only one\",\"project\":\"Digital PIN\",\"action\":\"created\"}'),
(261, 16, '📊 Risk Status Changed', '📊 Risk \'ab\' status has been changed by Mikiyas', 'info', 'risk', 9, NULL, 1, 0, '2026-02-13 06:49:48', 'normal', NULL, NULL, '{\"risk_id\":9,\"risk_title\":\"ab\",\"actor\":\"Mikiyas\",\"action\":\"status_changed\"}'),
(262, 13, 'Risk Notification', 'Your risk \'ab\' has been updated by Mikiyas', 'info', 'risk', 9, NULL, 1, 0, '2026-02-13 06:49:48', 'normal', NULL, NULL, '{\"risk_id\":9,\"risk_title\":\"ab\",\"actor\":\"Mikiyas\",\"action\":\"updated\"}'),
(263, 16, '🗑️ Risk Deleted', 'Risk \'only one\' has been DELETED by superAdmin in project Digital PIN', 'danger', 'risk', 12, 3, 1, 0, '2026-02-13 07:28:48', 'normal', NULL, NULL, '{\"risk_id\":12,\"risk_title\":\"only one\",\"project\":\"Digital PIN\",\"action\":\"deleted\",\"deleter\":\"superAdmin\"}'),
(264, 13, '🗑️ Risk Deleted', 'Risk \'only one\' has been DELETED by superAdmin in project Digital PIN', 'danger', 'risk', 12, 3, 1, 0, '2026-02-13 07:28:48', 'normal', NULL, NULL, '{\"risk_id\":12,\"risk_title\":\"only one\",\"project\":\"Digital PIN\",\"action\":\"deleted\",\"deleter\":\"superAdmin\"}'),
(265, 5, '🗑️ Risk Deleted', 'Risk \'only one\' has been DELETED by superAdmin in project Digital PIN', 'danger', 'risk', 12, 3, 1, 0, '2026-02-13 07:28:48', 'normal', NULL, NULL, '{\"risk_id\":12,\"risk_title\":\"only one\",\"project\":\"Digital PIN\",\"action\":\"deleted\",\"deleter\":\"superAdmin\"}'),
(266, 16, '📝 Risk Updated', 'Risk \'PIN Compromise During Digital Delivery\' has been updated by superAdmin', 'info', 'risk', 8, NULL, 1, 0, '2026-02-13 07:29:05', 'normal', NULL, NULL, '{\"risk_id\":8,\"risk_title\":\"PIN Compromise During Digital Delivery\",\"actor\":\"superAdmin\",\"action\":\"updated\"}'),
(267, 16, '📝 Risk Updated', 'Risk \'PIN Compromise During Digital Delivery\' has been updated by superAdmin', 'info', 'risk', 8, NULL, 1, 0, '2026-02-13 07:29:17', 'normal', NULL, NULL, '{\"risk_id\":8,\"risk_title\":\"PIN Compromise During Digital Delivery\",\"actor\":\"superAdmin\",\"action\":\"updated\"}'),
(268, 16, '🗑️ Risk Deleted', 'Risk \'PIN Delivery Failure via SMS\' has been DELETED by Mesfin in project Digital PIN', 'danger', 'risk', 6, 13, 1, 0, '2026-02-13 07:31:03', 'normal', NULL, NULL, '{\"risk_id\":6,\"risk_title\":\"PIN Delivery Failure via SMS\",\"project\":\"Digital PIN\",\"action\":\"deleted\",\"deleter\":\"Mesfin\"}'),
(269, 5, '🗑️ Risk Deleted', 'Risk \'PIN Delivery Failure via SMS\' has been DELETED by Mesfin in project Digital PIN', 'danger', 'risk', 6, 13, 1, 0, '2026-02-13 07:31:03', 'normal', NULL, NULL, '{\"risk_id\":6,\"risk_title\":\"PIN Delivery Failure via SMS\",\"project\":\"Digital PIN\",\"action\":\"deleted\",\"deleter\":\"Mesfin\"}'),
(270, 3, '🗑️ Risk Deleted', 'Risk \'PIN Delivery Failure via SMS\' has been DELETED by Mesfin in project Digital PIN', 'danger', 'risk', 6, 13, 1, 0, '2026-02-13 07:31:03', 'normal', NULL, NULL, '{\"risk_id\":6,\"risk_title\":\"PIN Delivery Failure via SMS\",\"project\":\"Digital PIN\",\"action\":\"deleted\",\"deleter\":\"Mesfin\"}'),
(271, 3, '⚠️ Risk Approval Required', 'Risk \'rt\' requires your approval. Reported by: Mikiyas', 'warning', 'risk', 13, NULL, 1, 0, '2026-02-13 07:38:35', 'normal', NULL, NULL, '{\"risk_id\":13,\"risk_title\":\"rt\",\"project_id\":45,\"reporter\":\"Mikiyas\",\"action\":\"approval_required\"}'),
(272, 5, '⚠️ Risk Approval Required', 'Risk \'rt\' requires your approval. Reported by: Mikiyas', 'warning', 'risk', 13, NULL, 1, 0, '2026-02-13 07:38:35', 'normal', NULL, NULL, '{\"risk_id\":13,\"risk_title\":\"rt\",\"project_id\":45,\"reporter\":\"Mikiyas\",\"action\":\"approval_required\"}'),
(273, 16, '📋 New Risk Reported', 'Mikiyas reported a new risk: \'rt\' in project Digital PIN - Pending Review', 'info', 'risk', 13, 5, 1, 0, '2026-02-13 07:38:35', 'normal', NULL, NULL, '{\"risk_id\":13,\"risk_title\":\"rt\",\"project\":\"Digital PIN\",\"action\":\"created\"}'),
(274, 13, '📋 New Risk Reported', 'Mikiyas reported a new risk: \'rt\' in project Digital PIN - Pending Review', 'info', 'risk', 13, 5, 1, 0, '2026-02-13 07:38:35', 'normal', NULL, NULL, '{\"risk_id\":13,\"risk_title\":\"rt\",\"project\":\"Digital PIN\",\"action\":\"created\"}'),
(275, 3, '📋 New Risk Reported', 'Mikiyas reported a new risk: \'rt\' in project Digital PIN - Pending Review', 'info', 'risk', 13, 5, 1, 0, '2026-02-13 07:38:35', 'normal', NULL, NULL, '{\"risk_id\":13,\"risk_title\":\"rt\",\"project\":\"Digital PIN\",\"action\":\"created\"}'),
(276, 5, '📊 Risk Assessed', '📊 Your risk \'rt\' has been assessed by superAdmin', 'info', 'risk', 13, NULL, 1, 0, '2026-02-13 07:39:59', 'normal', NULL, NULL, '{\"risk_id\":13,\"risk_title\":\"rt\",\"actor\":\"superAdmin\",\"action\":\"assessed\"}'),
(277, 13, '👤 Risk Owner Assigned', 'You have been assigned as owner of risk: \'rt\'', 'success', 'risk', 13, NULL, 1, 0, '2026-02-13 07:40:09', 'normal', NULL, NULL, '{\"risk_id\":13,\"risk_title\":\"rt\",\"actor\":\"superAdmin\",\"action\":\"assigned\"}'),
(278, 13, '📊 Risk Status Changed', '📊 Risk \'rt\' status has been changed by Mikiyas', 'info', 'risk', 13, NULL, 1, 0, '2026-02-13 08:13:33', 'normal', NULL, NULL, '{\"risk_id\":13,\"risk_title\":\"rt\",\"actor\":\"Mikiyas\",\"action\":\"status_changed\"}'),
(279, 13, '📊 Risk Status Changed', '📊 Risk \'rt\' status has been changed by Mikiyas', 'info', 'risk', 13, NULL, 1, 0, '2026-02-13 08:13:51', 'normal', NULL, NULL, '{\"risk_id\":13,\"risk_title\":\"rt\",\"actor\":\"Mikiyas\",\"action\":\"status_changed\"}'),
(280, 13, '📝 Risk Updated', 'Risk \'rt\' has been updated by Mikiyas', 'info', 'risk', 13, NULL, 1, 0, '2026-02-13 08:14:07', 'normal', NULL, NULL, '{\"risk_id\":13,\"risk_title\":\"rt\",\"actor\":\"Mikiyas\",\"action\":\"updated\"}'),
(281, 13, '📊 Risk Status Changed', '📊 Risk \'rt\' status has been changed by Mikiyas', 'info', 'risk', 13, NULL, 1, 0, '2026-02-13 08:14:36', 'normal', NULL, NULL, '{\"risk_id\":13,\"risk_title\":\"rt\",\"actor\":\"Mikiyas\",\"action\":\"status_changed\"}'),
(282, 13, '📊 Risk Status Changed', '📊 Risk \'rt\' status has been changed by Mikiyas', 'info', 'risk', 13, NULL, 1, 0, '2026-02-13 08:25:15', 'normal', NULL, NULL, '{\"risk_id\":13,\"risk_title\":\"rt\",\"actor\":\"Mikiyas\",\"action\":\"status_changed\"}'),
(283, 16, '🛡️ Mitigation Action Assigned', '🛡️ You have been assigned a mitigation action: \'grds\' for risk \'rt\'', 'info', 'risk_mitigation', 13, NULL, 1, 0, '2026-02-13 08:25:51', 'normal', NULL, NULL, '{\"risk_id\":13,\"risk_title\":\"rt\",\"mitigation_title\":\"grds\",\"actor\":\"Mikiyas\",\"action\":\"assigned\"}'),
(284, 13, '🛡️ Mitigation Added', 'A new mitigation action \'grds\' has been added to risk \'rt\' by Mikiyas', 'info', 'risk_mitigation', 13, 5, 1, 0, '2026-02-13 08:25:51', 'normal', NULL, NULL, '{\"risk_id\":13,\"risk_title\":\"rt\",\"mitigation_title\":\"grds\",\"project\":\"Digital PIN\",\"action\":\"mitigation_added\"}'),
(285, 13, '📊 Risk Status Changed', '📊 Risk \'rt\' status has been changed by superAdmin', 'info', 'risk', 13, NULL, 1, 0, '2026-02-13 08:26:52', 'normal', NULL, NULL, '{\"risk_id\":13,\"risk_title\":\"rt\",\"actor\":\"superAdmin\",\"action\":\"status_changed\"}'),
(286, 5, 'Risk Notification', 'Your risk \'rt\' has been updated by superAdmin', 'info', 'risk', 13, NULL, 1, 0, '2026-02-13 08:26:52', 'normal', NULL, NULL, '{\"risk_id\":13,\"risk_title\":\"rt\",\"actor\":\"superAdmin\",\"action\":\"updated\"}'),
(287, 13, '📊 Risk Status Changed', '📊 Risk \'rt\' status has been changed by superAdmin', 'info', 'risk', 13, NULL, 1, 0, '2026-02-13 08:29:24', 'normal', NULL, NULL, '{\"risk_id\":13,\"risk_title\":\"rt\",\"actor\":\"superAdmin\",\"action\":\"status_changed\"}'),
(288, 5, 'Risk Notification', 'Your risk \'rt\' has been updated by superAdmin', 'info', 'risk', 13, NULL, 1, 0, '2026-02-13 08:29:24', 'normal', NULL, NULL, '{\"risk_id\":13,\"risk_title\":\"rt\",\"actor\":\"superAdmin\",\"action\":\"updated\"}'),
(289, 16, '✅ Risk Closed', 'Risk \'rt\' has been CLOSED by superAdmin in project Digital PIN', 'success', 'risk', 13, 3, 1, 0, '2026-02-13 08:29:24', 'normal', NULL, NULL, '{\"risk_id\":13,\"risk_title\":\"rt\",\"project\":\"Digital PIN\",\"action\":\"closed\"}'),
(290, 13, '✅ Risk Closed', 'Risk \'rt\' has been CLOSED by superAdmin in project Digital PIN', 'success', 'risk', 13, 3, 1, 0, '2026-02-13 08:29:24', 'normal', NULL, NULL, '{\"risk_id\":13,\"risk_title\":\"rt\",\"project\":\"Digital PIN\",\"action\":\"closed\"}'),
(291, 5, '✅ Risk Closed', 'Risk \'rt\' has been CLOSED by superAdmin in project Digital PIN', 'success', 'risk', 13, 3, 1, 0, '2026-02-13 08:29:24', 'normal', NULL, NULL, '{\"risk_id\":13,\"risk_title\":\"rt\",\"project\":\"Digital PIN\",\"action\":\"closed\"}'),
(292, 3, '⚠️ Risk Approval Required', 'Risk \'muluk\' requires your approval. Reported by: Mesfin', 'warning', 'risk', 14, NULL, 1, 0, '2026-02-13 08:33:19', 'normal', NULL, NULL, '{\"risk_id\":14,\"risk_title\":\"muluk\",\"project_id\":45,\"reporter\":\"Mesfin\",\"action\":\"approval_required\"}'),
(293, 5, '⚠️ Risk Approval Required', 'Risk \'muluk\' requires your approval. Reported by: Mesfin', 'warning', 'risk', 14, NULL, 1, 0, '2026-02-13 08:33:19', 'normal', NULL, NULL, '{\"risk_id\":14,\"risk_title\":\"muluk\",\"project_id\":45,\"reporter\":\"Mesfin\",\"action\":\"approval_required\"}'),
(294, 16, '📋 New Risk Reported', 'Mesfin reported a new risk: \'muluk\' in project Digital PIN - Pending Review', 'info', 'risk', 14, 13, 1, 0, '2026-02-13 08:33:19', 'normal', NULL, NULL, '{\"risk_id\":14,\"risk_title\":\"muluk\",\"project\":\"Digital PIN\",\"action\":\"created\"}'),
(295, 5, '📋 New Risk Reported', 'Mesfin reported a new risk: \'muluk\' in project Digital PIN - Pending Review', 'info', 'risk', 14, 13, 1, 0, '2026-02-13 08:33:19', 'normal', NULL, NULL, '{\"risk_id\":14,\"risk_title\":\"muluk\",\"project\":\"Digital PIN\",\"action\":\"created\"}'),
(296, 3, '📋 New Risk Reported', 'Mesfin reported a new risk: \'muluk\' in project Digital PIN - Pending Review', 'info', 'risk', 14, 13, 1, 0, '2026-02-13 08:33:19', 'normal', NULL, NULL, '{\"risk_id\":14,\"risk_title\":\"muluk\",\"project\":\"Digital PIN\",\"action\":\"created\"}'),
(297, 13, '👤 Risk Owner Assigned', 'You have been assigned as owner of risk: \'muluk\'', 'success', 'risk', 14, NULL, 1, 0, '2026-02-13 08:34:29', 'normal', NULL, NULL, '{\"risk_id\":14,\"risk_title\":\"muluk\",\"actor\":\"Mikiyas\",\"action\":\"assigned\"}'),
(298, 13, '📝 Risk Updated', 'Risk \'muluk\' has been updated by Mikiyas', 'info', 'risk', 14, NULL, 1, 0, '2026-02-13 08:34:41', 'normal', NULL, NULL, '{\"risk_id\":14,\"risk_title\":\"muluk\",\"actor\":\"Mikiyas\",\"action\":\"updated\"}'),
(299, 13, '📝 Risk Updated', 'Risk \'muluk\' has been updated by Mikiyas', 'info', 'risk', 14, NULL, 1, 0, '2026-02-13 08:34:55', 'normal', NULL, NULL, '{\"risk_id\":14,\"risk_title\":\"muluk\",\"actor\":\"Mikiyas\",\"action\":\"updated\"}'),
(300, 13, '📊 Risk Status Changed', '📊 Risk \'muluk\' status has been changed by Mikiyas', 'info', 'risk', 14, NULL, 1, 0, '2026-02-13 08:35:10', 'normal', NULL, NULL, '{\"risk_id\":14,\"risk_title\":\"muluk\",\"actor\":\"Mikiyas\",\"action\":\"status_changed\"}'),
(301, 16, '👤 Risk Owner Assigned', 'You have been assigned as owner of risk: \'muluk\'', 'success', 'risk', 14, NULL, 1, 0, '2026-02-13 08:38:47', 'normal', NULL, NULL, '{\"risk_id\":14,\"risk_title\":\"muluk\",\"actor\":\"Mikiyas\",\"action\":\"assigned\"}'),
(302, 13, '👤 Risk Owner Removed', 'You are no longer the owner of risk \'muluk\'. New owner: GetnetM', 'info', 'risk', 14, 5, 1, 0, '2026-02-13 08:38:47', 'normal', NULL, NULL, '{\"risk_id\":14,\"risk_title\":\"muluk\",\"action\":\"owner_removed\",\"new_owner\":\"GetnetM\"}'),
(303, 16, '📝 Risk Edited', 'Risk \'muluk\' has been edited by Mikiyas', 'info', 'risk', 14, 5, 1, 0, '2026-02-13 08:51:21', 'normal', NULL, NULL, '{\"risk_id\":14,\"risk_title\":\"muluk\",\"project_id\":45,\"editor\":\"Mikiyas\",\"action\":\"edited\"}'),
(304, 13, '📝 Risk Edited', 'Risk \'muluk\' has been edited by Mikiyas', 'info', 'risk', 14, 5, 1, 0, '2026-02-13 08:51:21', 'normal', NULL, NULL, '{\"risk_id\":14,\"risk_title\":\"muluk\",\"project_id\":45,\"editor\":\"Mikiyas\",\"action\":\"edited\"}'),
(305, 3, '📝 Risk Edited', 'Risk \'muluk\' has been edited by Mikiyas', 'info', 'risk', 14, 5, 1, 0, '2026-02-13 08:51:21', 'normal', NULL, NULL, '{\"risk_id\":14,\"risk_title\":\"muluk\",\"project_id\":45,\"editor\":\"Mikiyas\",\"action\":\"edited\"}'),
(306, 16, '❌ Risk Deleted', 'Risk \'ab\' has been deleted by superAdmin', 'danger', 'risk', NULL, 3, 1, 0, '2026-02-13 08:52:00', 'normal', NULL, NULL, '{\"risk_title\":\"ab\",\"project_id\":45,\"deleter\":\"superAdmin\",\"action\":\"deleted\"}'),
(307, 13, '❌ Risk Deleted', 'Risk \'ab\' has been deleted by superAdmin', 'danger', 'risk', NULL, 3, 1, 0, '2026-02-13 08:52:00', 'normal', NULL, NULL, '{\"risk_title\":\"ab\",\"project_id\":45,\"deleter\":\"superAdmin\",\"action\":\"deleted\"}'),
(308, 5, '❌ Risk Deleted', 'Risk \'ab\' has been deleted by superAdmin', 'danger', 'risk', NULL, 3, 1, 0, '2026-02-13 08:52:00', 'normal', NULL, NULL, '{\"risk_title\":\"ab\",\"project_id\":45,\"deleter\":\"superAdmin\",\"action\":\"deleted\"}'),
(309, 13, '📊 Risk Status Changed', '📊 Risk \'two\' status has been changed by superAdmin', 'info', 'risk', 11, NULL, 1, 0, '2026-02-13 08:55:26', 'normal', NULL, NULL, '{\"risk_id\":11,\"risk_title\":\"two\",\"actor\":\"superAdmin\",\"action\":\"status_changed\"}'),
(310, 16, 'Risk Notification', 'Your risk \'two\' has been updated by superAdmin', 'info', 'risk', 11, NULL, 1, 0, '2026-02-13 08:55:26', 'normal', NULL, NULL, '{\"risk_id\":11,\"risk_title\":\"two\",\"actor\":\"superAdmin\",\"action\":\"updated\"}'),
(311, 13, '📊 Risk Status Changed', '📊 Risk \'two\' status has been changed by superAdmin', 'info', 'risk', 11, NULL, 1, 0, '2026-02-13 08:55:31', 'normal', NULL, NULL, '{\"risk_id\":11,\"risk_title\":\"two\",\"actor\":\"superAdmin\",\"action\":\"status_changed\"}'),
(312, 16, 'Risk Notification', 'Your risk \'two\' has been updated by superAdmin', 'info', 'risk', 11, NULL, 1, 0, '2026-02-13 08:55:31', 'normal', NULL, NULL, '{\"risk_id\":11,\"risk_title\":\"two\",\"actor\":\"superAdmin\",\"action\":\"updated\"}'),
(313, 16, '✅ Risk Closed', 'Risk \'two\' has been CLOSED by superAdmin in project Digital PIN', 'success', 'risk', 11, 3, 1, 0, '2026-02-13 08:55:31', 'normal', NULL, NULL, '{\"risk_id\":11,\"risk_title\":\"two\",\"project\":\"Digital PIN\",\"action\":\"closed\"}'),
(314, 13, '✅ Risk Closed', 'Risk \'two\' has been CLOSED by superAdmin in project Digital PIN', 'success', 'risk', 11, 3, 1, 0, '2026-02-13 08:55:31', 'normal', NULL, NULL, '{\"risk_id\":11,\"risk_title\":\"two\",\"project\":\"Digital PIN\",\"action\":\"closed\"}'),
(315, 5, '✅ Risk Closed', 'Risk \'two\' has been CLOSED by superAdmin in project Digital PIN', 'success', 'risk', 11, 3, 1, 0, '2026-02-13 08:55:31', 'normal', NULL, NULL, '{\"risk_id\":11,\"risk_title\":\"two\",\"project\":\"Digital PIN\",\"action\":\"closed\"}'),
(316, 5, '⚠️ Risk Approval Required', 'Risk \'esdfgx\' requires your approval. Reported by: Mesfin', 'warning', 'risk', 15, NULL, 1, 0, '2026-02-13 09:10:36', 'normal', NULL, NULL, '{\"risk_id\":15,\"risk_title\":\"esdfgx\",\"project_id\":45,\"reporter\":\"Mesfin\",\"action\":\"approval_required\"}'),
(317, 16, '✅ Mitigation Completed', '✅ Mitigation action \'Strengthen Integration Testing and Monitoring\' has been COMPLETED by superAdmin', 'success', 'risk_mitigation', 15, NULL, 1, 0, '2026-02-13 09:17:22', 'normal', NULL, NULL, '{\"risk_id\":15,\"risk_title\":\"esdfgx\",\"mitigation_title\":\"Strengthen Integration Testing and Monitoring\",\"actor\":\"superAdmin\",\"action\":\"completed\"}'),
(318, 13, '💬 New Comment', '💬 New comment on your risk \'esdfgx\' from GetnetM', 'info', 'risk', 15, NULL, 1, 0, '2026-02-13 09:18:23', 'normal', NULL, NULL, '{\"risk_id\":15,\"risk_title\":\"esdfgx\",\"actor\":\"GetnetM\",\"action\":\"commented\"}'),
(319, 13, 'Risk Notification', 'Your risk \'esdfgx\' has been updated by GetnetM', 'info', 'risk', 15, NULL, 1, 0, '2026-02-13 10:21:26', 'normal', NULL, NULL, '{\"risk_id\":15,\"risk_title\":\"esdfgx\",\"actor\":\"GetnetM\",\"action\":\"updated\"}'),
(320, 3, '⚠️ Risk Approval Required', 'Risk \'tr\' requires your approval. Reported by: GetnetM (pm_employee)', 'warning', 'risk', 16, NULL, 1, 0, '2026-02-13 10:34:08', 'normal', NULL, NULL, '{\"risk_id\":16,\"risk_title\":\"tr\",\"project_id\":45,\"reporter\":\"GetnetM\",\"reporter_role\":\"pm_employee\",\"action\":\"approval_required\"}'),
(321, 5, '⚠️ Risk Approval Required', 'Risk \'tr\' requires your approval. Reported by: GetnetM (pm_employee)', 'warning', 'risk', 16, NULL, 1, 0, '2026-02-13 10:34:08', 'normal', NULL, NULL, '{\"risk_id\":16,\"risk_title\":\"tr\",\"project_id\":45,\"reporter\":\"GetnetM\",\"reporter_role\":\"pm_employee\",\"action\":\"approval_required\"}'),
(322, 13, '📋 New Risk Reported', 'GetnetM reported a new risk: \'tr\' in project Digital PIN - Pending Review', 'info', 'risk', 16, 16, 1, 0, '2026-02-13 10:34:08', 'normal', NULL, NULL, '{\"risk_id\":16,\"risk_title\":\"tr\",\"project\":\"Digital PIN\",\"action\":\"created\"}'),
(323, 5, '📋 New Risk Reported', 'GetnetM reported a new risk: \'tr\' in project Digital PIN - Pending Review', 'info', 'risk', 16, 16, 1, 0, '2026-02-13 10:34:08', 'normal', NULL, NULL, '{\"risk_id\":16,\"risk_title\":\"tr\",\"project\":\"Digital PIN\",\"action\":\"created\"}'),
(324, 3, '📋 New Risk Reported', 'GetnetM reported a new risk: \'tr\' in project Digital PIN - Pending Review', 'info', 'risk', 16, 16, 1, 0, '2026-02-13 10:34:08', 'normal', NULL, NULL, '{\"risk_id\":16,\"risk_title\":\"tr\",\"project\":\"Digital PIN\",\"action\":\"created\"}'),
(325, 16, '✅ Risk Approved', '✅ Your risk \'tr\' has been APPROVED by Mikiyas', 'success', 'risk', 16, NULL, 1, 0, '2026-02-13 10:36:37', 'normal', NULL, NULL, '{\"risk_id\":16,\"risk_title\":\"tr\",\"actor\":\"Mikiyas\",\"action\":\"approved\"}'),
(326, 16, '✅ Risk Approved', 'Risk \'tr\' has been APPROVED by Mikiyas', 'success', 'risk', 16, 5, 1, 0, '2026-02-13 10:36:37', 'normal', NULL, NULL, '{\"risk_id\":16,\"risk_title\":\"tr\",\"project\":\"Digital PIN\",\"action\":\"approved\"}'),
(327, 13, '✅ Risk Approved', 'Risk \'tr\' has been APPROVED by Mikiyas', 'success', 'risk', 16, 5, 1, 0, '2026-02-13 10:36:37', 'normal', NULL, NULL, '{\"risk_id\":16,\"risk_title\":\"tr\",\"project\":\"Digital PIN\",\"action\":\"approved\"}'),
(328, 3, '✅ Risk Approved', 'Risk \'tr\' has been APPROVED by Mikiyas', 'success', 'risk', 16, 5, 1, 0, '2026-02-13 10:36:37', 'normal', NULL, NULL, '{\"risk_id\":16,\"risk_title\":\"tr\",\"project\":\"Digital PIN\",\"action\":\"approved\"}'),
(329, 16, 'Risk Notification', 'Your risk \'tr\' has been updated by Mikiyas', 'info', 'risk', 16, NULL, 1, 0, '2026-02-13 10:45:04', 'normal', NULL, NULL, '{\"risk_id\":16,\"risk_title\":\"tr\",\"actor\":\"Mikiyas\",\"action\":\"updated\"}'),
(330, 13, '🛡️ Mitigation Action Assigned', '🛡️ You have been assigned a mitigation action: \'edfg\' for risk \'tr\'', 'info', 'risk_mitigation', 16, NULL, 1, 0, '2026-02-13 10:45:33', 'normal', NULL, NULL, '{\"risk_id\":16,\"risk_title\":\"tr\",\"mitigation_title\":\"edfg\",\"actor\":\"Mikiyas\",\"action\":\"assigned\"}'),
(331, 3, '⚠️ Risk Approval Required', 'Risk \'fesfds\' requires your approval. Reported by: Mesfin (pm_employee)', 'warning', 'risk', 17, NULL, 1, 0, '2026-02-13 11:03:09', 'normal', NULL, NULL, '{\"risk_id\":17,\"risk_title\":\"fesfds\",\"project_id\":45,\"reporter\":\"Mesfin\",\"reporter_role\":\"pm_employee\",\"action\":\"approval_required\"}'),
(332, 5, '⚠️ Risk Approval Required', 'Risk \'fesfds\' requires your approval. Reported by: Mesfin (pm_employee)', 'warning', 'risk', 17, NULL, 1, 0, '2026-02-13 11:03:09', 'normal', NULL, NULL, '{\"risk_id\":17,\"risk_title\":\"fesfds\",\"project_id\":45,\"reporter\":\"Mesfin\",\"reporter_role\":\"pm_employee\",\"action\":\"approval_required\"}'),
(333, 16, '📋 New Risk Reported', 'Mesfin reported a new risk: \'fesfds\' in project Digital PIN - Pending Review', 'info', 'risk', 17, 13, 1, 0, '2026-02-13 11:03:09', 'normal', NULL, NULL, '{\"risk_id\":17,\"risk_title\":\"fesfds\",\"project\":\"Digital PIN\",\"action\":\"created\"}'),
(334, 5, '📋 New Risk Reported', 'Mesfin reported a new risk: \'fesfds\' in project Digital PIN - Pending Review', 'info', 'risk', 17, 13, 1, 0, '2026-02-13 11:03:09', 'normal', NULL, NULL, '{\"risk_id\":17,\"risk_title\":\"fesfds\",\"project\":\"Digital PIN\",\"action\":\"created\"}'),
(335, 3, '📋 New Risk Reported', 'Mesfin reported a new risk: \'fesfds\' in project Digital PIN - Pending Review', 'info', 'risk', 17, 13, 1, 0, '2026-02-13 11:03:09', 'normal', NULL, NULL, '{\"risk_id\":17,\"risk_title\":\"fesfds\",\"project\":\"Digital PIN\",\"action\":\"created\"}'),
(336, 13, '✅ Risk Approved', '✅ Your risk \'fesfds\' has been APPROVED by Mikiyas', 'success', 'risk', 17, NULL, 1, 0, '2026-02-13 11:04:24', 'normal', NULL, NULL, '{\"risk_id\":17,\"risk_title\":\"fesfds\",\"actor\":\"Mikiyas\",\"action\":\"approved\"}'),
(337, 16, '✅ Risk Approved', 'Risk \'fesfds\' has been APPROVED by Mikiyas', 'success', 'risk', 17, 5, 1, 0, '2026-02-13 11:04:24', 'normal', NULL, NULL, '{\"risk_id\":17,\"risk_title\":\"fesfds\",\"project\":\"Digital PIN\",\"action\":\"approved\"}'),
(338, 13, '✅ Risk Approved', 'Risk \'fesfds\' has been APPROVED by Mikiyas', 'success', 'risk', 17, 5, 1, 0, '2026-02-13 11:04:24', 'normal', NULL, NULL, '{\"risk_id\":17,\"risk_title\":\"fesfds\",\"project\":\"Digital PIN\",\"action\":\"approved\"}'),
(339, 3, '✅ Risk Approved', 'Risk \'fesfds\' has been APPROVED by Mikiyas', 'success', 'risk', 17, 5, 1, 0, '2026-02-13 11:04:24', 'normal', NULL, NULL, '{\"risk_id\":17,\"risk_title\":\"fesfds\",\"project\":\"Digital PIN\",\"action\":\"approved\"}'),
(340, 16, '👤 Risk Owner Assigned', 'You have been assigned as owner of risk: \'fesfds\'', 'success', 'risk', 17, NULL, 1, 0, '2026-02-13 11:04:32', 'normal', NULL, NULL, '{\"risk_id\":17,\"risk_title\":\"fesfds\",\"actor\":\"Mikiyas\",\"action\":\"assigned\"}'),
(341, 16, '🛡️ Mitigation Action Assigned', '🛡️ You have been assigned a mitigation action: \'dsfdsfa\' for risk \'fesfds\'', 'info', 'risk_mitigation', 17, NULL, 1, 0, '2026-02-13 11:05:11', 'normal', NULL, NULL, '{\"risk_id\":17,\"risk_title\":\"fesfds\",\"mitigation_title\":\"dsfdsfa\",\"actor\":\"Mikiyas\",\"action\":\"assigned\"}'),
(342, 16, '👤 Risk Owner Assigned', 'You have been assigned as owner of risk: \'fesfds\'', 'success', 'risk', 17, NULL, 1, 0, '2026-02-13 11:16:57', 'normal', NULL, NULL, '{\"risk_id\":17,\"risk_title\":\"fesfds\",\"actor\":\"superAdmin\",\"action\":\"assigned\"}'),
(343, 3, '⚠️ Risk Approval Required', 'Risk \'fg\' requires your approval. Reported by: Mesfin (pm_employee)', 'warning', 'risk', 18, NULL, 1, 0, '2026-02-13 11:21:35', 'normal', NULL, NULL, '{\"risk_id\":18,\"risk_title\":\"fg\",\"project_id\":45,\"reporter\":\"Mesfin\",\"reporter_role\":\"pm_employee\",\"action\":\"approval_required\"}'),
(344, 5, '⚠️ Risk Approval Required', 'Risk \'fg\' requires your approval. Reported by: Mesfin (pm_employee)', 'warning', 'risk', 18, NULL, 1, 0, '2026-02-13 11:21:35', 'normal', NULL, NULL, '{\"risk_id\":18,\"risk_title\":\"fg\",\"project_id\":45,\"reporter\":\"Mesfin\",\"reporter_role\":\"pm_employee\",\"action\":\"approval_required\"}'),
(345, 16, '📋 New Risk Reported', 'Mesfin reported a new risk: \'fg\' in project Digital PIN - Pending Review', 'info', 'risk', 18, 13, 1, 0, '2026-02-13 11:21:35', 'normal', NULL, NULL, '{\"risk_id\":18,\"risk_title\":\"fg\",\"project\":\"Digital PIN\",\"action\":\"created\"}'),
(346, 5, '📋 New Risk Reported', 'Mesfin reported a new risk: \'fg\' in project Digital PIN - Pending Review', 'info', 'risk', 18, 13, 1, 0, '2026-02-13 11:21:35', 'normal', NULL, NULL, '{\"risk_id\":18,\"risk_title\":\"fg\",\"project\":\"Digital PIN\",\"action\":\"created\"}'),
(347, 3, '📋 New Risk Reported', 'Mesfin reported a new risk: \'fg\' in project Digital PIN - Pending Review', 'info', 'risk', 18, 13, 1, 0, '2026-02-13 11:21:35', 'normal', NULL, NULL, '{\"risk_id\":18,\"risk_title\":\"fg\",\"project\":\"Digital PIN\",\"action\":\"created\"}'),
(348, 13, '✅ Risk Approved', '✅ Your risk \'fg\' has been APPROVED by superAdmin', 'success', 'risk', 18, NULL, 1, 0, '2026-02-13 11:44:54', 'normal', NULL, NULL, '{\"risk_id\":18,\"risk_title\":\"fg\",\"actor\":\"superAdmin\",\"action\":\"approved\"}'),
(349, 16, '✅ Risk Approved', 'Risk \'fg\' has been APPROVED by superAdmin', 'success', 'risk', 18, 3, 1, 0, '2026-02-13 11:44:54', 'normal', NULL, NULL, '{\"risk_id\":18,\"risk_title\":\"fg\",\"project\":\"Digital PIN\",\"action\":\"approved\"}'),
(350, 13, '✅ Risk Approved', 'Risk \'fg\' has been APPROVED by superAdmin', 'success', 'risk', 18, 3, 1, 0, '2026-02-13 11:44:54', 'normal', NULL, NULL, '{\"risk_id\":18,\"risk_title\":\"fg\",\"project\":\"Digital PIN\",\"action\":\"approved\"}'),
(351, 5, '✅ Risk Approved', 'Risk \'fg\' has been APPROVED by superAdmin', 'success', 'risk', 18, 3, 1, 0, '2026-02-13 11:44:54', 'normal', NULL, NULL, '{\"risk_id\":18,\"risk_title\":\"fg\",\"project\":\"Digital PIN\",\"action\":\"approved\"}'),
(352, 13, '👤 Risk Owner Assigned', 'You have been assigned as owner of risk: \'fg\'', 'success', 'risk', 18, NULL, 1, 0, '2026-02-13 11:45:20', 'normal', NULL, NULL, '{\"risk_id\":18,\"risk_title\":\"fg\",\"actor\":\"superAdmin\",\"action\":\"assigned\"}'),
(353, 13, 'Issue Approved', 'Issue #2: \'ATM cash withdrawal transactions failing intermittently\' has been approved', 'success', 'issue', 2, 3, 1, 0, '2026-02-13 13:14:23', 'normal', NULL, NULL, NULL),
(354, 5, 'Issue Assigned to You', 'Issue #2: \'ATM cash withdrawal transactions failing intermittently\' has been assigned to you', 'info', 'issue', 2, 3, 1, 0, '2026-02-13 13:14:51', 'normal', NULL, NULL, NULL),
(355, 13, 'Issue Assigned', 'Issue #2: \'ATM cash withdrawal transactions failing intermittently\' has been assigned to Mikiyas', 'info', 'issue', 2, 3, 1, 0, '2026-02-13 13:14:51', 'normal', NULL, NULL, NULL),
(356, 5, 'Issue Assigned to You', 'Issue #2: \'ATM cash withdrawal transactions failing intermittently\' has been assigned to you', 'info', 'issue', 2, 3, 1, 0, '2026-02-13 13:21:27', 'normal', NULL, NULL, NULL),
(357, 13, 'Issue Assigned', 'Issue #2: \'ATM cash withdrawal transactions failing intermittently\' has been assigned to Mikiyas', 'info', 'issue', 2, 3, 1, 0, '2026-02-13 13:21:27', 'normal', NULL, NULL, NULL),
(358, 13, 'Issue Assigned', 'Issue #2: \'ATM cash withdrawal transactions failing intermittently\' has been assigned to Unassigned', 'info', 'issue', 2, 3, 1, 0, '2026-02-13 13:41:23', 'normal', NULL, NULL, NULL),
(359, 3, 'Issue Pending Approval', 'Issue \'wqaesr\' created for project \'Digital PIN\' by Mesfin requires approval', 'warning', 'issue', 4, 13, 1, 0, '2026-02-13 13:51:42', 'normal', NULL, NULL, NULL),
(360, 5, 'Issue Pending Approval', 'Issue \'wqaesr\' created for project \'Digital PIN\' by Mesfin requires approval', 'warning', 'issue', 4, 13, 1, 0, '2026-02-13 13:51:42', 'normal', NULL, NULL, NULL),
(361, 14, 'Issue Pending Approval', 'Issue \'wqaesr\' created for project \'Digital PIN\' by Mesfin requires approval', 'warning', 'issue', 4, 13, 0, 0, '2026-02-13 13:51:42', 'normal', NULL, NULL, NULL),
(362, 13, 'Issue Approved', 'Issue #4: \'wqaesr\' has been approved', 'success', 'issue', 4, 5, 1, 0, '2026-02-13 13:53:08', 'normal', NULL, NULL, NULL),
(363, 13, 'Issue Assigned to You', 'Issue #4: \'wqaesr\' has been assigned to you', 'info', 'issue', 4, 5, 1, 0, '2026-02-13 13:53:26', 'normal', NULL, NULL, NULL),
(364, 5, 'Issue Assigned to You', 'Issue #3: \'rgdfc\' has been assigned to you', 'info', 'issue', 3, 3, 1, 0, '2026-02-13 14:07:56', 'normal', NULL, NULL, NULL),
(365, 3, 'Issue Assigned', 'Issue #3: \'rgdfc\' has been assigned to Mikiyas', 'info', 'issue', 3, 3, 1, 0, '2026-02-13 14:07:56', 'normal', NULL, NULL, NULL),
(366, 5, 'Issue Assigned to You', 'Issue #2: \'ATM cash withdrawal transactions failing intermittently\' has been assigned to you', 'info', 'issue', 2, 3, 1, 0, '2026-02-14 05:00:37', 'normal', NULL, NULL, NULL),
(367, 13, 'Issue Assigned', 'Issue #2: \'ATM cash withdrawal transactions failing intermittently\' has been assigned to Mikiyas', 'info', 'issue', 2, 3, 1, 0, '2026-02-14 05:00:37', 'normal', NULL, NULL, NULL),
(368, 5, 'Issue Assigned to You', 'Issue #4: \'wqaesr\' has been assigned to you', 'info', 'issue', 4, 3, 1, 0, '2026-02-14 05:01:08', 'normal', NULL, NULL, NULL),
(369, 13, 'Issue Assigned', 'Issue #4: \'wqaesr\' has been assigned to Mikiyas', 'info', 'issue', 4, 3, 1, 0, '2026-02-14 05:01:08', 'normal', NULL, NULL, NULL),
(370, 5, 'Issue Assigned to You', 'Issue #4: \'wqaesr\' has been assigned to you', 'info', 'issue', 4, 3, 1, 0, '2026-02-14 05:02:48', 'normal', NULL, NULL, NULL),
(371, 13, 'Issue Assigned', 'Issue #4: \'wqaesr\' has been assigned to Mikiyas', 'info', 'issue', 4, 3, 1, 0, '2026-02-14 05:02:48', 'normal', NULL, NULL, NULL);
INSERT INTO `notifications` (`id`, `user_id`, `title`, `message`, `type`, `related_module`, `related_id`, `related_user_id`, `is_read`, `is_archived`, `created_at`, `priority`, `action_by`, `action_by_name`, `metadata`) VALUES
(372, 3, 'Issue Pending Approval', 'Issue \'PIN not delivered to customer via SMS\' created for project \'Digital PIN\' by Mesfin requires approval', 'warning', 'issue', 5, 13, 1, 0, '2026-02-14 05:05:53', 'normal', NULL, NULL, NULL),
(373, 5, 'Issue Pending Approval', 'Issue \'PIN not delivered to customer via SMS\' created for project \'Digital PIN\' by Mesfin requires approval', 'warning', 'issue', 5, 13, 1, 0, '2026-02-14 05:05:53', 'normal', NULL, NULL, NULL),
(374, 14, 'Issue Pending Approval', 'Issue \'PIN not delivered to customer via SMS\' created for project \'Digital PIN\' by Mesfin requires approval', 'warning', 'issue', 5, 13, 0, 0, '2026-02-14 05:05:53', 'normal', NULL, NULL, NULL),
(375, 13, 'Issue Approved', 'Issue #5: \'PIN not delivered to customer via SMS\' has been approved', 'success', 'issue', 5, 5, 1, 0, '2026-02-14 05:07:38', 'normal', NULL, NULL, NULL),
(376, 16, 'Issue Assigned to You', 'Issue #5: \'PIN not delivered to customer via SMS\' has been assigned to you', 'info', 'issue', 5, 5, 1, 0, '2026-02-14 05:08:30', 'normal', NULL, NULL, NULL),
(377, 13, 'Issue Assigned', 'Issue #5: \'PIN not delivered to customer via SMS\' has been assigned to GetnetM', 'info', 'issue', 5, 5, 1, 0, '2026-02-14 05:08:30', 'normal', NULL, NULL, NULL),
(378, 16, 'Issue Assigned to You', 'Issue #5: \'PIN not delivered to customer via SMS\' has been assigned to you', 'info', 'issue', 5, 5, 1, 0, '2026-02-14 05:09:05', 'normal', NULL, NULL, NULL),
(379, 13, 'Issue Assigned', 'Issue #5: \'PIN not delivered to customer via SMS\' has been assigned to GetnetM', 'info', 'issue', 5, 5, 1, 0, '2026-02-14 05:09:05', 'normal', NULL, NULL, NULL),
(380, 3, 'Issue Pending Approval', 'Issue \'Add retry mechanism for PIN SMS delivery\' created for project \'Digital PIN\' by Mesfin requires approval', 'warning', 'issue', 6, 13, 1, 0, '2026-02-14 05:13:58', 'normal', NULL, NULL, NULL),
(381, 5, 'Issue Pending Approval', 'Issue \'Add retry mechanism for PIN SMS delivery\' created for project \'Digital PIN\' by Mesfin requires approval', 'warning', 'issue', 6, 13, 1, 0, '2026-02-14 05:13:58', 'normal', NULL, NULL, NULL),
(382, 14, 'Issue Pending Approval', 'Issue \'Add retry mechanism for PIN SMS delivery\' created for project \'Digital PIN\' by Mesfin requires approval', 'warning', 'issue', 6, 13, 0, 0, '2026-02-14 05:13:58', 'normal', NULL, NULL, NULL),
(383, 13, 'Issue Approved', 'Issue #6: \'Add retry mechanism for PIN SMS delivery\' has been approved', 'success', 'issue', 6, 5, 1, 0, '2026-02-14 05:15:02', 'normal', NULL, NULL, NULL),
(384, 16, 'Issue Assigned to You', 'Issue #6: \'Add retry mechanism for PIN SMS delivery\' has been assigned to you', 'info', 'issue', 6, 5, 1, 0, '2026-02-14 05:15:14', 'normal', NULL, NULL, NULL),
(385, 13, 'Issue Assigned', 'Issue #6: \'Add retry mechanism for PIN SMS delivery\' has been assigned to GetnetM', 'info', 'issue', 6, 5, 1, 0, '2026-02-14 05:15:14', 'normal', NULL, NULL, NULL),
(386, 3, 'Issue Pending Approval', 'Issue \'eerf\' created for project \'Digital PIN\' by GetnetM requires approval', 'warning', 'issue', 7, 16, 1, 0, '2026-02-14 05:39:39', 'normal', NULL, NULL, NULL),
(387, 5, 'Issue Pending Approval', 'Issue \'eerf\' created for project \'Digital PIN\' by GetnetM requires approval', 'warning', 'issue', 7, 16, 1, 0, '2026-02-14 05:39:39', 'normal', NULL, NULL, NULL),
(388, 14, 'Issue Pending Approval', 'Issue \'eerf\' created for project \'Digital PIN\' by GetnetM requires approval', 'warning', 'issue', 7, 16, 0, 0, '2026-02-14 05:39:39', 'normal', NULL, NULL, NULL),
(389, 16, 'Issue Approved', 'Issue #7: \'eerf\' has been approved', 'success', 'issue', 7, 5, 1, 0, '2026-02-14 05:41:50', 'normal', NULL, NULL, NULL),
(390, 13, 'Issue Assigned to You', 'Issue #7: \'eerf\' has been assigned to you', 'info', 'issue', 7, 5, 1, 0, '2026-02-14 05:42:10', 'normal', NULL, NULL, NULL),
(391, 16, 'Issue Assigned', 'Issue #7: \'eerf\' has been assigned to Mesfin', 'info', 'issue', 7, 5, 1, 0, '2026-02-14 05:42:10', 'normal', NULL, NULL, NULL),
(392, 3, 'Issue Pending Approval', 'Issue \'wwwwwwwwwwwwwwwww\' created for project \'Digital PIN\' by Mesfin requires approval', 'warning', 'issue', 8, 13, 1, 0, '2026-02-14 06:03:09', 'normal', NULL, NULL, NULL),
(393, 5, 'Issue Pending Approval', 'Issue \'wwwwwwwwwwwwwwwww\' created for project \'Digital PIN\' by Mesfin requires approval', 'warning', 'issue', 8, 13, 1, 0, '2026-02-14 06:03:09', 'normal', NULL, NULL, NULL),
(394, 14, 'Issue Pending Approval', 'Issue \'wwwwwwwwwwwwwwwww\' created for project \'Digital PIN\' by Mesfin requires approval', 'warning', 'issue', 8, 13, 0, 0, '2026-02-14 06:03:09', 'normal', NULL, NULL, NULL),
(395, 13, 'Issue Approved', 'Issue #8: \'wwwwwwwwwwwwwwwww\' has been approved', 'success', 'issue', 8, 5, 0, 0, '2026-02-14 06:03:46', 'normal', NULL, NULL, NULL),
(396, 16, 'Issue Assigned to You', 'Issue #8: \'wwwwwwwwwwwwwwwww\' has been assigned to you', 'info', 'issue', 8, 5, 0, 0, '2026-02-14 06:03:57', 'normal', NULL, NULL, NULL),
(397, 13, 'Issue Assigned', 'Issue #8: \'wwwwwwwwwwwwwwwww\' has been assigned to GetnetM', 'info', 'issue', 8, 5, 0, 0, '2026-02-14 06:03:57', 'normal', NULL, NULL, NULL),
(398, 3, 'Issue Pending Approval', 'Issue \'ew\' created for project \'Digital PIN\' by GetnetM requires approval', 'warning', 'issue', 9, 16, 1, 0, '2026-02-14 06:18:29', 'normal', NULL, NULL, NULL),
(399, 5, 'Issue Pending Approval', 'Issue \'ew\' created for project \'Digital PIN\' by GetnetM requires approval', 'warning', 'issue', 9, 16, 0, 0, '2026-02-14 06:18:29', 'normal', NULL, NULL, NULL),
(400, 14, 'Issue Pending Approval', 'Issue \'ew\' created for project \'Digital PIN\' by GetnetM requires approval', 'warning', 'issue', 9, 16, 0, 0, '2026-02-14 06:18:29', 'normal', NULL, NULL, NULL),
(401, 16, 'Issue Approved', 'Issue #9: \'ew\' has been approved', 'success', 'issue', 9, 5, 0, 0, '2026-02-14 06:19:05', 'normal', NULL, NULL, NULL),
(402, 16, 'Issue Assigned to You', 'Issue #9: \'ew\' has been assigned to you', 'info', 'issue', 9, 5, 1, 0, '2026-02-14 06:19:20', 'normal', NULL, NULL, NULL),
(403, 5, 'Project Status Updated by Super Admin', 'Super Admin updated project: Digital PIN from pending to in_progress', 'info', 'project', 45, NULL, 0, 0, '2026-02-14 07:08:56', 'normal', NULL, NULL, NULL),
(404, 13, 'Project Status Updated by Super Admin', 'Super Admin updated project: Digital PIN from pending to in_progress', 'info', 'project', 45, NULL, 0, 0, '2026-02-14 07:08:56', 'normal', NULL, NULL, NULL),
(405, 16, 'Project Status Updated by Super Admin', 'Super Admin updated project: Digital PIN from pending to in_progress', 'info', 'project', 45, NULL, 0, 0, '2026-02-14 07:08:56', 'normal', NULL, NULL, NULL),
(406, 5, 'Phase Status Updated by Super Admin', 'Super Admin updated phase: phases 1 from pending to pending', 'info', 'phase', 59, NULL, 0, 0, '2026-02-14 07:09:56', 'normal', NULL, NULL, NULL),
(407, 5, 'Activity Status Updated by Super Admin', 'Super Admin updated activity: Act 1 from pending to pending', 'info', 'activity', 54, NULL, 0, 0, '2026-02-14 07:10:52', 'normal', NULL, NULL, NULL),
(408, 5, 'Sub_activity Status Updated by Super Admin', 'Super Admin updated sub_activity: weafdgh from pending to pending', 'info', 'sub_activity', 65, NULL, 0, 0, '2026-02-14 07:11:21', 'normal', NULL, NULL, NULL),
(409, 5, 'Project Status Updated by Super Admin', 'Super Admin updated project: MP from pending to terminated', 'info', 'project', 44, NULL, 0, 0, '2026-02-14 07:12:19', 'normal', NULL, NULL, NULL),
(410, 5, 'Project Terminated', 'Project \'MP\' has been terminated by superAdmin', 'warning', 'project', 44, NULL, 0, 0, '2026-02-14 07:12:19', 'normal', NULL, NULL, NULL),
(411, 5, 'Project Status Updated by Super Admin', 'Super Admin updated project: MP from terminated to in_progress', 'info', 'project', 44, NULL, 0, 0, '2026-02-14 07:17:58', 'normal', NULL, NULL, NULL),
(412, 5, 'Phase Status Updated by Super Admin', 'Super Admin updated phase: phases 1 from  to in_progress', 'info', 'phase', 59, NULL, 0, 0, '2026-02-14 07:18:06', 'normal', NULL, NULL, NULL),
(413, 2, 'New Assignment', 'Super Admin superAdmin assigned you to Project: UPF', 'info', 'project', 42, NULL, 0, 0, '2026-02-14 08:17:20', 'normal', NULL, NULL, NULL),
(414, 4, 'New Assignment', 'Super Admin superAdmin assigned you to Project: UPF', 'info', 'project', 42, NULL, 0, 0, '2026-02-14 08:24:37', 'normal', NULL, NULL, NULL),
(415, 13, 'Change Request Approved', 'Your change request #35 has been approved by the Super Admin. You can now proceed with implementation.', 'success', 'change_request', 35, NULL, 0, 0, '2026-02-14 08:36:01', 'normal', NULL, NULL, '{\"old_status\":\"Approved\",\"new_status\":\"Approved\",\"action\":\"Approved\",\"performed_by\":\"superAdmin\",\"performer_role\":\"super_admin\",\"change_request_id\":\"35\",\"comments\":null,\"change_title\":\"Add SMS delivery retry for PIN failures\",\"timestamp\":\"2026-02-14 09:36:01\"}'),
(416, 5, 'New Approved Change Request', 'Change request #35 has been approved by the Super Admin and is ready for implementation.', 'info', 'change_request', 35, NULL, 0, 0, '2026-02-14 08:36:01', 'normal', NULL, NULL, '{\"old_status\":\"Approved\",\"new_status\":\"Approved\",\"action\":\"Approved\",\"performed_by\":\"superAdmin\",\"performer_role\":\"super_admin\",\"change_request_id\":\"35\",\"requester_name\":\"Mesfin\",\"requester_role\":\"pm_employee\",\"change_title\":\"Add SMS delivery retry for PIN failures\",\"priority\":\"Medium\",\"timestamp\":\"2026-02-14 09:36:01\"}'),
(417, 7, 'New Approved Change Request', 'Change request #35 has been approved by the Super Admin and is ready for implementation.', 'info', 'change_request', 35, NULL, 0, 0, '2026-02-14 08:36:01', 'normal', NULL, NULL, '{\"old_status\":\"Approved\",\"new_status\":\"Approved\",\"action\":\"Approved\",\"performed_by\":\"superAdmin\",\"performer_role\":\"super_admin\",\"change_request_id\":\"35\",\"requester_name\":\"Mesfin\",\"requester_role\":\"pm_employee\",\"change_title\":\"Add SMS delivery retry for PIN failures\",\"priority\":\"Medium\",\"timestamp\":\"2026-02-14 09:36:01\"}'),
(418, 16, 'New Approved Change Request', 'Change request #35 has been approved by the Super Admin and is ready for implementation.', 'info', 'change_request', 35, NULL, 0, 0, '2026-02-14 08:36:01', 'normal', NULL, NULL, '{\"old_status\":\"Approved\",\"new_status\":\"Approved\",\"action\":\"Approved\",\"performed_by\":\"superAdmin\",\"performer_role\":\"super_admin\",\"change_request_id\":\"35\",\"requester_name\":\"Mesfin\",\"requester_role\":\"pm_employee\",\"change_title\":\"Add SMS delivery retry for PIN failures\",\"priority\":\"Medium\",\"timestamp\":\"2026-02-14 09:36:01\"}'),
(419, 27, 'New Approved Change Request', 'Change request #35 has been approved by the Super Admin and is ready for implementation.', 'info', 'change_request', 35, NULL, 0, 0, '2026-02-14 08:36:01', 'normal', NULL, NULL, '{\"old_status\":\"Approved\",\"new_status\":\"Approved\",\"action\":\"Approved\",\"performed_by\":\"superAdmin\",\"performer_role\":\"super_admin\",\"change_request_id\":\"35\",\"requester_name\":\"Mesfin\",\"requester_role\":\"pm_employee\",\"change_title\":\"Add SMS delivery retry for PIN failures\",\"priority\":\"Medium\",\"timestamp\":\"2026-02-14 09:36:01\"}'),
(420, 3, 'Implementation Started', 'Implementation has started for change request #35 by Mesfin', 'warning', 'change_request', 35, NULL, 1, 0, '2026-02-14 08:39:10', 'normal', NULL, NULL, '{\"old_status\":\"In Progress\",\"new_status\":\"In Progress\",\"action\":\"Implementation Started\",\"performed_by\":\"Mesfin\",\"performer_role\":\"pm_employee\",\"change_request_id\":\"35\",\"change_title\":\"Add SMS delivery retry for PIN failures\",\"requester_name\":\"Mesfin\",\"requester_role\":\"pm_employee\",\"priority\":\"Medium\",\"comments\":null,\"timestamp\":\"2026-02-14 09:39:10\"}'),
(421, 14, 'Implementation Started', 'Implementation has started for change request #35 by Mesfin', 'warning', 'change_request', 35, NULL, 0, 0, '2026-02-14 08:39:10', 'normal', NULL, NULL, '{\"old_status\":\"In Progress\",\"new_status\":\"In Progress\",\"action\":\"Implementation Started\",\"performed_by\":\"Mesfin\",\"performer_role\":\"pm_employee\",\"change_request_id\":\"35\",\"change_title\":\"Add SMS delivery retry for PIN failures\",\"requester_name\":\"Mesfin\",\"requester_role\":\"pm_employee\",\"priority\":\"Medium\",\"comments\":null,\"timestamp\":\"2026-02-14 09:39:10\"}'),
(422, 26, 'Implementation Started', 'Implementation has started for change request #35 by Mesfin', 'warning', 'change_request', 35, NULL, 0, 0, '2026-02-14 08:39:10', 'normal', NULL, NULL, '{\"old_status\":\"In Progress\",\"new_status\":\"In Progress\",\"action\":\"Implementation Started\",\"performed_by\":\"Mesfin\",\"performer_role\":\"pm_employee\",\"change_request_id\":\"35\",\"change_title\":\"Add SMS delivery retry for PIN failures\",\"requester_name\":\"Mesfin\",\"requester_role\":\"pm_employee\",\"priority\":\"Medium\",\"comments\":null,\"timestamp\":\"2026-02-14 09:39:10\"}'),
(423, 3, 'Implementation Started', 'Implementation has started for change request #33 by Mesfin', 'warning', 'change_request', 33, NULL, 1, 0, '2026-02-14 08:39:46', 'normal', NULL, NULL, '{\"old_status\":\"In Progress\",\"new_status\":\"In Progress\",\"action\":\"Implementation Started\",\"performed_by\":\"Mesfin\",\"performer_role\":\"pm_employee\",\"change_request_id\":\"33\",\"change_title\":\"Increase VCN expiry time for failed merchant retries\",\"requester_name\":\"Mikiyas\",\"requester_role\":\"pm_manager\",\"priority\":\"Medium\",\"comments\":null,\"timestamp\":\"2026-02-14 09:39:46\"}'),
(424, 14, 'Implementation Started', 'Implementation has started for change request #33 by Mesfin', 'warning', 'change_request', 33, NULL, 0, 0, '2026-02-14 08:39:46', 'normal', NULL, NULL, '{\"old_status\":\"In Progress\",\"new_status\":\"In Progress\",\"action\":\"Implementation Started\",\"performed_by\":\"Mesfin\",\"performer_role\":\"pm_employee\",\"change_request_id\":\"33\",\"change_title\":\"Increase VCN expiry time for failed merchant retries\",\"requester_name\":\"Mikiyas\",\"requester_role\":\"pm_manager\",\"priority\":\"Medium\",\"comments\":null,\"timestamp\":\"2026-02-14 09:39:46\"}'),
(425, 26, 'Implementation Started', 'Implementation has started for change request #33 by Mesfin', 'warning', 'change_request', 33, NULL, 0, 0, '2026-02-14 08:39:46', 'normal', NULL, NULL, '{\"old_status\":\"In Progress\",\"new_status\":\"In Progress\",\"action\":\"Implementation Started\",\"performed_by\":\"Mesfin\",\"performer_role\":\"pm_employee\",\"change_request_id\":\"33\",\"change_title\":\"Increase VCN expiry time for failed merchant retries\",\"requester_name\":\"Mikiyas\",\"requester_role\":\"pm_manager\",\"priority\":\"Medium\",\"comments\":null,\"timestamp\":\"2026-02-14 09:39:46\"}'),
(426, 3, 'Implementation Completed', 'Implementation has been completed for change request #35 by Mesfin', 'success', 'change_request', 35, NULL, 1, 0, '2026-02-14 08:40:10', 'normal', NULL, NULL, '{\"old_status\":\"Implemented\",\"new_status\":\"Implemented\",\"action\":\"Implemented\",\"performed_by\":\"Mesfin\",\"performer_role\":\"pm_employee\",\"change_request_id\":\"35\",\"change_title\":\"Add SMS delivery retry for PIN failures\",\"requester_name\":\"Mesfin\",\"requester_role\":\"pm_employee\",\"priority\":\"Medium\",\"comments\":null,\"timestamp\":\"2026-02-14 09:40:10\"}'),
(427, 14, 'Implementation Completed', 'Implementation has been completed for change request #35 by Mesfin', 'success', 'change_request', 35, NULL, 0, 0, '2026-02-14 08:40:10', 'normal', NULL, NULL, '{\"old_status\":\"Implemented\",\"new_status\":\"Implemented\",\"action\":\"Implemented\",\"performed_by\":\"Mesfin\",\"performer_role\":\"pm_employee\",\"change_request_id\":\"35\",\"change_title\":\"Add SMS delivery retry for PIN failures\",\"requester_name\":\"Mesfin\",\"requester_role\":\"pm_employee\",\"priority\":\"Medium\",\"comments\":null,\"timestamp\":\"2026-02-14 09:40:10\"}'),
(428, 26, 'Implementation Completed', 'Implementation has been completed for change request #35 by Mesfin', 'success', 'change_request', 35, NULL, 0, 0, '2026-02-14 08:40:10', 'normal', NULL, NULL, '{\"old_status\":\"Implemented\",\"new_status\":\"Implemented\",\"action\":\"Implemented\",\"performed_by\":\"Mesfin\",\"performer_role\":\"pm_employee\",\"change_request_id\":\"35\",\"change_title\":\"Add SMS delivery retry for PIN failures\",\"requester_name\":\"Mesfin\",\"requester_role\":\"pm_employee\",\"priority\":\"Medium\",\"comments\":null,\"timestamp\":\"2026-02-14 09:40:10\"}'),
(429, 16, '📋 New Risk Reported', 'superAdmin reported a new risk: \'you will\' in project Digital PIN - Pending Review', 'info', 'risk', 19, 3, 0, 0, '2026-02-14 08:51:20', 'normal', NULL, NULL, '{\"risk_id\":19,\"risk_title\":\"you will\",\"project\":\"Digital PIN\",\"action\":\"created\"}'),
(430, 13, '📋 New Risk Reported', 'superAdmin reported a new risk: \'you will\' in project Digital PIN - Pending Review', 'info', 'risk', 19, 3, 0, 0, '2026-02-14 08:51:20', 'normal', NULL, NULL, '{\"risk_id\":19,\"risk_title\":\"you will\",\"project\":\"Digital PIN\",\"action\":\"created\"}'),
(431, 5, '📋 New Risk Reported', 'superAdmin reported a new risk: \'you will\' in project Digital PIN - Pending Review', 'info', 'risk', 19, 3, 0, 0, '2026-02-14 08:51:20', 'normal', NULL, NULL, '{\"risk_id\":19,\"risk_title\":\"you will\",\"project\":\"Digital PIN\",\"action\":\"created\"}'),
(432, 3, '⚠️ Risk Approval Required', 'Risk \'tyy\' requires your approval. Reported by: Mesfin (pm_employee)', 'warning', 'risk', 20, NULL, 0, 0, '2026-02-14 08:53:35', 'normal', NULL, NULL, '{\"risk_id\":20,\"risk_title\":\"tyy\",\"project_id\":45,\"reporter\":\"Mesfin\",\"reporter_role\":\"pm_employee\",\"action\":\"approval_required\"}'),
(433, 5, '⚠️ Risk Approval Required', 'Risk \'tyy\' requires your approval. Reported by: Mesfin (pm_employee)', 'warning', 'risk', 20, NULL, 0, 0, '2026-02-14 08:53:35', 'normal', NULL, NULL, '{\"risk_id\":20,\"risk_title\":\"tyy\",\"project_id\":45,\"reporter\":\"Mesfin\",\"reporter_role\":\"pm_employee\",\"action\":\"approval_required\"}'),
(434, 16, '📋 New Risk Reported', 'Mesfin reported a new risk: \'tyy\' in project Digital PIN - Pending Review', 'info', 'risk', 20, 13, 0, 0, '2026-02-14 08:53:36', 'normal', NULL, NULL, '{\"risk_id\":20,\"risk_title\":\"tyy\",\"project\":\"Digital PIN\",\"action\":\"created\"}'),
(435, 5, '📋 New Risk Reported', 'Mesfin reported a new risk: \'tyy\' in project Digital PIN - Pending Review', 'info', 'risk', 20, 13, 1, 0, '2026-02-14 08:53:36', 'normal', NULL, NULL, '{\"risk_id\":20,\"risk_title\":\"tyy\",\"project\":\"Digital PIN\",\"action\":\"created\"}'),
(436, 3, '📋 New Risk Reported', 'Mesfin reported a new risk: \'tyy\' in project Digital PIN - Pending Review', 'info', 'risk', 20, 13, 0, 0, '2026-02-14 08:53:36', 'normal', NULL, NULL, '{\"risk_id\":20,\"risk_title\":\"tyy\",\"project\":\"Digital PIN\",\"action\":\"created\"}'),
(437, 13, '✅ Risk Approved', '✅ Your risk \'tyy\' has been APPROVED by Mikiyas', 'success', 'risk', 20, NULL, 0, 0, '2026-02-14 08:54:15', 'normal', NULL, NULL, '{\"risk_id\":20,\"risk_title\":\"tyy\",\"actor\":\"Mikiyas\",\"action\":\"approved\"}'),
(438, 16, '✅ Risk Approved', 'Risk \'tyy\' has been APPROVED by Mikiyas', 'success', 'risk', 20, 5, 0, 0, '2026-02-14 08:54:15', 'normal', NULL, NULL, '{\"risk_id\":20,\"risk_title\":\"tyy\",\"project\":\"Digital PIN\",\"action\":\"approved\"}'),
(439, 13, '✅ Risk Approved', 'Risk \'tyy\' has been APPROVED by Mikiyas', 'success', 'risk', 20, 5, 0, 0, '2026-02-14 08:54:15', 'normal', NULL, NULL, '{\"risk_id\":20,\"risk_title\":\"tyy\",\"project\":\"Digital PIN\",\"action\":\"approved\"}'),
(440, 3, '✅ Risk Approved', 'Risk \'tyy\' has been APPROVED by Mikiyas', 'success', 'risk', 20, 5, 0, 0, '2026-02-14 08:54:15', 'normal', NULL, NULL, '{\"risk_id\":20,\"risk_title\":\"tyy\",\"project\":\"Digital PIN\",\"action\":\"approved\"}'),
(441, 16, '👤 Risk Owner Assigned', 'You have been assigned as owner of risk: \'tyy\'', 'success', 'risk', 20, NULL, 0, 0, '2026-02-14 08:57:56', 'normal', NULL, NULL, '{\"risk_id\":20,\"risk_title\":\"tyy\",\"actor\":\"Mikiyas\",\"action\":\"assigned\"}'),
(442, 3, 'Issue Pending Approval', 'Issue \'issue\' created for project \'Digital PIN\' by Mikiyas requires approval', 'warning', 'issue', 10, 5, 1, 0, '2026-02-14 09:00:47', 'normal', NULL, NULL, NULL),
(443, 5, 'Issue Pending Approval', 'Issue \'issue\' created for project \'Digital PIN\' by Mikiyas requires approval', 'warning', 'issue', 10, 5, 0, 0, '2026-02-14 09:00:47', 'normal', NULL, NULL, NULL),
(444, 14, 'Issue Pending Approval', 'Issue \'issue\' created for project \'Digital PIN\' by Mikiyas requires approval', 'warning', 'issue', 10, 5, 0, 0, '2026-02-14 09:00:47', 'normal', NULL, NULL, NULL),
(445, 5, 'Issue Approved', 'Issue #10: \'issue\' has been approved', 'success', 'issue', 10, 3, 0, 0, '2026-02-14 09:01:45', 'normal', NULL, NULL, NULL),
(446, 16, 'Issue Assigned to You', 'Issue #10: \'issue\' has been assigned to you', 'info', 'issue', 10, 3, 1, 0, '2026-02-14 09:02:14', 'normal', NULL, NULL, NULL),
(447, 5, 'Issue Assigned', 'Issue #10: \'issue\' has been assigned to GetnetM', 'info', 'issue', 10, 3, 0, 0, '2026-02-14 09:02:14', 'normal', NULL, NULL, NULL);

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
(6, 'Personal Budget', NULL, 1, '2025-08-13 09:32:21'),
(8, 'DIRECT PROJECT COSTS', 'The over all projects cost', 1, '2026-02-10 15:23:54');

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
(48, 43, 'Project Initiation & Planning', 'Define business needs, scope, stakeholders, and project governance.', 1, '2026-02-12', '2026-03-29', '', '2026-02-10 08:49:48', '2026-02-14 07:30:24'),
(49, 43, 'Design', 'Define system architecture and integration design.', 2, '2026-02-12', '2026-05-17', '', '2026-02-10 08:49:48', '2026-02-14 07:19:58'),
(50, 43, 'Development & Configuration', 'Build and configure UPF components.', 3, '2026-02-13', '2026-05-31', '', '2026-02-10 08:49:48', '2026-02-14 07:19:58'),
(51, 43, 'Testing', '', 4, '2026-02-19', '2026-06-13', '', '2026-02-10 08:49:48', '2026-02-14 07:19:58'),
(52, 42, 'Project Initiation & Requirement Analysis', 'This phase defines the business objectives, functional scope, regulatory requirements, and technical expectations of the UPF solution.', 1, '2026-02-10', '2026-04-10', 'pending', '2026-02-10 10:27:08', '2026-02-10 10:27:08'),
(53, 42, 'Solution Design & Architecture', 'Design the technical architecture, security framework, and integration model for the UPF solution.', 2, '2026-02-05', '2026-05-10', 'pending', '2026-02-10 10:27:08', '2026-02-10 10:27:08'),
(54, 44, 'Initiation & Requirement Analysis', 'Define scope, objectives, regulatory compliance, and user requirements for mobile payment services.', 1, '2026-02-05', '2026-06-10', '', '2026-02-10 10:35:28', '2026-02-14 07:12:19'),
(55, 44, 'Design & Architecture', 'Design system architecture, integrations, and customer experience.', 2, '2026-02-03', '2026-06-10', '', '2026-02-10 10:35:28', '2026-02-14 07:12:19'),
(56, 45, 'Initiation & Compliance', 'Define scope, security requirements, and regulatory compliance for digital PIN delivery.', 1, '2026-02-06', '2026-01-31', 'pending', '2026-02-10 10:43:22', '2026-02-10 10:43:22'),
(57, 45, 'Design & Development', 'Design and implement digital PIN delivery solution.', 2, '2026-02-06', '2026-05-15', 'pending', '2026-02-10 10:43:22', '2026-02-10 10:43:22'),
(59, 44, 'phases 1', '', 12, '2026-02-19', '2026-02-20', 'in_progress', '2026-02-14 07:09:56', '2026-02-14 07:18:06');

-- --------------------------------------------------------

--
-- Table structure for table `phase_users`
--

CREATE TABLE `phase_users` (
  `id` int(11) NOT NULL,
  `phase_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pm_notifications`
--

CREATE TABLE `pm_notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `notification_type` enum('assignment','status_update') NOT NULL,
  `entity_type` enum('project','phase','activity','sub_activity') NOT NULL,
  `entity_id` int(11) NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pm_notification_settings`
--

CREATE TABLE `pm_notification_settings` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `email_notifications` tinyint(1) DEFAULT 1,
  `in_app_notifications` tinyint(1) DEFAULT 1,
  `assignment_notifications` tinyint(1) DEFAULT 1,
  `status_update_notifications` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `priorities`
--

CREATE TABLE `priorities` (
  `priority_id` int(11) NOT NULL,
  `priority_name` varchar(50) NOT NULL,
  `description` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
  `status` enum('pending','in_progress','completed','terminated') DEFAULT 'pending',
  `created_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `department_id` int(11) DEFAULT NULL,
  `priority` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `projects`
--

INSERT INTO `projects` (`id`, `name`, `description`, `created_at`, `project_type`, `start_date`, `end_date`, `status`, `created_by`, `updated_at`, `department_id`, `priority`) VALUES
(42, 'UPF', 'The Unified Payment Framework (UPF) project aims to build a centralized and scalable payment processing platform that integrates all payment channels and external payment partners into a single framework. This project improves transaction reliability, simplifies integrations, enhances security, and supports future digital payment innovations for Dashen Bank.', '2026-02-10 11:40:54', 'hybrid', '2026-02-10', '2026-11-11', 'in_progress', 3, '2026-02-10 10:41:07', 7, NULL),
(43, 'VCN', 'The Virtual Card Number (VCN) project provides customers with secure, temporary or permanent virtual card numbers for online and e-commerce transactions. The project aims to reduce card fraud, improve security of online payments, and promote digital card usage by protecting customers’ real card details.', '2026-02-10 11:40:54', 'hybrid', '2026-02-10', '2026-12-10', 'terminated', 3, '2026-02-14 07:19:58', 7, NULL),
(44, 'MP', 'The Mobile Payment (MP) project enables customers to make payments, transfers, and merchant transactions using mobile devices. It promotes cashless transactions, improves financial inclusion, and enhances convenience through QR payments, P2P transfers, and bill payments.', '2026-02-10 11:40:54', 'hybrid', '2026-02-12', '2026-05-24', 'in_progress', 3, '2026-02-14 07:17:58', 7, NULL),
(45, 'Digital PIN', 'The Digital PIN project enables secure electronic delivery and management of card PINs via SMS and mobile banking, replacing paper PIN mailers. It improves security, reduces operational costs, and enhances customer experience.', '2026-02-10 11:40:54', 'hybrid', '2026-02-22', '2026-05-10', 'in_progress', 3, '2026-02-14 07:08:56', 7, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `project_intakes`
--

CREATE TABLE `project_intakes` (
  `id` int(11) NOT NULL,
  `project_name` varchar(255) NOT NULL,
  `department_id` int(11) DEFAULT NULL,
  `business_sponsor_name` varchar(255) DEFAULT NULL,
  `business_sponsor_role` varchar(255) DEFAULT NULL,
  `project_champion_name` varchar(255) DEFAULT NULL,
  `project_champion_role` varchar(255) DEFAULT NULL,
  `proposed_start_date` date DEFAULT NULL,
  `proposed_end_date` date DEFAULT NULL,
  `business_challenge` text DEFAULT NULL,
  `strategic_goals` text DEFAULT NULL,
  `consequences_if_not_implemented` text DEFAULT NULL,
  `success_kpis` text DEFAULT NULL,
  `proposed_system_name` varchar(255) DEFAULT NULL,
  `primary_business_capability` text DEFAULT NULL,
  `existing_systems_similar` enum('Yes','No') DEFAULT 'No',
  `existing_systems_list` text DEFAULT NULL,
  `justification_new_system` text DEFAULT NULL,
  `business_unit_owner` varchar(255) DEFAULT NULL,
  `benefit_types` text DEFAULT NULL,
  `expected_benefits` text DEFAULT NULL,
  `quantifiable_benefits` text DEFAULT NULL,
  `benefit_responsible` varchar(255) DEFAULT NULL,
  `requirements_document_attached` enum('Yes','No') DEFAULT 'No',
  `key_functional_requirements` text DEFAULT NULL,
  `impacted_processes` text DEFAULT NULL,
  `out_of_scope` text DEFAULT NULL,
  `dependencies_constraints` text DEFAULT NULL,
  `estimated_total_budget` decimal(15,2) DEFAULT NULL,
  `budget_approval_obtained` enum('Yes','No') DEFAULT 'No',
  `external_vendors_required` enum('Yes','No') DEFAULT 'No',
  `identified_risks` text DEFAULT NULL,
  `overall_risk_rating` enum('Low','Medium','High') DEFAULT 'Low',
  `compliance_regulatory_implications` text DEFAULT NULL,
  `cybersecurity_concerns` text DEFAULT NULL,
  `team_ready_for_assessment` enum('Yes','No') DEFAULT 'No',
  `internal_resources_required` text DEFAULT NULL,
  `execution_challenges` text DEFAULT NULL,
  `signed_business_case` enum('Yes','No') DEFAULT 'No',
  `requirements_document` enum('Yes','No') DEFAULT 'No',
  `benefit_management_plan` enum('Yes','No') DEFAULT 'No',
  `risk_assessment_matrix` enum('Yes','No') DEFAULT 'No',
  `budget_spreadsheet` enum('Yes','No') DEFAULT 'No',
  `endorsement_letter` enum('Yes','No') DEFAULT 'No',
  `diagrams_process_maps` enum('Yes','No') DEFAULT 'No',
  `status` enum('Draft','Submitted','Under Review','Approved','Rejected','Deferred') DEFAULT 'Draft',
  `submitted_by` int(11) DEFAULT NULL,
  `submitted_date` datetime DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `review_date` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
(141, 3, 3, 'none', 'none'),
(144, 3, 7, 'none', 'none'),
(145, 3, 13, 'none', 'none'),
(147, 29, 14, 'none', 'none'),
(148, 29, 3, 'none', 'none'),
(164, 36, 14, 'none', 'none'),
(165, 36, 3, 'none', 'none'),
(166, 36, 15, 'none', 'none'),
(172, 7, 15, 'none', 'none'),
(174, 2, 14, 'none', 'none'),
(175, 2, 3, 'none', 'none'),
(176, 2, 15, 'none', 'none'),
(179, 1, 14, 'none', 'none'),
(180, 1, 3, 'none', 'none'),
(181, 1, 15, 'none', 'none'),
(182, 1, 6, 'none', 'none'),
(183, 1, 7, 'none', 'viewer'),
(184, 1, 2, 'none', 'none');

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
  `score` int(11) GENERATED ALWAYS AS (`likelihood` * `impact`) STORED,
  `risk_score` int(11) GENERATED ALWAYS AS (`likelihood` * `impact`) STORED,
  `risk_level` varchar(20) DEFAULT NULL,
  `response_strategy` enum('Avoid','Mitigate','Transfer','Accept') DEFAULT NULL,
  `owner_user_id` int(11) DEFAULT NULL,
  `identified_by` int(11) DEFAULT NULL,
  `date_identified` date DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `is_deleted` tinyint(1) DEFAULT 0,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` int(11) DEFAULT NULL,
  `status_id` int(11) DEFAULT NULL,
  `probability_note` text DEFAULT NULL,
  `impact_note` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `risk_date` date DEFAULT NULL,
  `target_resolution_date` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `risks`
--

INSERT INTO `risks` (`id`, `project_id`, `department_id`, `category_id`, `title`, `description`, `trigger_description`, `likelihood`, `impact`, `risk_level`, `response_strategy`, `owner_user_id`, `identified_by`, `date_identified`, `approved_by`, `approved_at`, `rejection_reason`, `is_deleted`, `deleted_at`, `deleted_by`, `status_id`, `probability_note`, `impact_note`, `created_by`, `created_at`, `updated_at`, `risk_date`, `target_resolution_date`) VALUES
(7, 45, 7, 13, 'sad', 'sad', 'sda', 1, 1, 'Low', NULL, NULL, 13, '2026-02-12', 3, '2026-02-12 16:09:54', NULL, 0, NULL, NULL, 6, NULL, NULL, 13, '2026-02-12 08:12:50', '2026-02-12 13:09:54', NULL, NULL),
(8, 45, 7, 7, 'PIN Compromise During Digital Delivery', 'PIN leakage could lead to unauthorized card usage, financial losses, regulatory penalties, and serious reputational damage.', 'Weak encryption or improper handling of PIN data during transmission.', 3, 3, 'Medium', 'Mitigate', 16, 13, '2026-02-12', 5, '2026-02-12 11:28:53', NULL, 0, NULL, NULL, 8, NULL, NULL, 13, '2026-02-12 08:23:31', '2026-02-13 07:29:17', NULL, '2026-02-14'),
(11, 45, 7, 8, 'two', 'wefiuhihaih', 'yriuh', 1, 4, 'Low', 'Mitigate', 13, 16, '2026-02-12', 3, '2026-02-12 15:53:10', NULL, 0, NULL, NULL, 10, NULL, NULL, 16, '2026-02-12 10:33:35', '2026-02-13 08:55:31', NULL, '2026-02-13'),
(13, 45, 7, 13, 'rt', 'retgh', 'esrdfg', 3, 4, 'Medium', 'Mitigate', 13, 5, NULL, NULL, NULL, NULL, 0, NULL, NULL, 10, NULL, NULL, 5, '2026-02-13 07:38:35', '2026-02-13 08:29:24', NULL, NULL),
(14, 45, 7, 10, 'muluk', 'm', 'm', 3, 3, 'Medium', 'Mitigate', 16, 13, NULL, NULL, NULL, NULL, 0, NULL, NULL, 8, NULL, NULL, 13, '2026-02-13 08:33:19', '2026-02-13 08:51:21', NULL, '2026-02-18'),
(15, 45, 7, 10, 'esdfgx', 'sdfx', 'asdf', 3, 4, 'Medium', 'Transfer', 16, 13, NULL, NULL, NULL, NULL, 0, NULL, NULL, 8, NULL, NULL, 13, '2026-02-13 09:10:36', '2026-02-13 11:02:34', NULL, '2026-02-20'),
(16, 45, 7, 9, 'tr', 'rrr', 'rr', 1, 1, 'Low', NULL, NULL, 16, NULL, 5, '2026-02-13 13:36:37', NULL, 0, NULL, NULL, 8, NULL, NULL, 16, '2026-02-13 10:34:08', '2026-02-13 10:45:04', NULL, NULL),
(17, 45, 7, 13, 'fesfds', 'dsffsda', 'sdfafsda', 1, 1, 'Low', 'Mitigate', 16, 13, NULL, 5, '2026-02-13 14:04:24', NULL, 0, NULL, NULL, 9, NULL, NULL, 13, '2026-02-13 11:03:09', '2026-02-13 11:16:57', NULL, '2026-02-22'),
(18, 45, NULL, NULL, 'fg', '', '', 2, 3, 'Medium', 'Mitigate', 13, 13, NULL, 3, '2026-02-13 14:44:54', NULL, 0, NULL, NULL, 6, NULL, NULL, 13, '2026-02-13 11:21:35', '2026-02-13 11:45:20', NULL, '2026-02-21'),
(19, 45, NULL, NULL, 'you will', '', '', 5, 5, 'High', NULL, NULL, 3, NULL, NULL, NULL, NULL, 0, NULL, NULL, 7, NULL, NULL, 3, '2026-02-14 08:51:20', '2026-02-14 08:59:25', NULL, NULL),
(20, 45, 7, 13, 'tyy', 'hgfihlg', 'fhgyu', 1, 1, 'Low', 'Mitigate', 16, 13, NULL, 5, '2026-02-14 11:54:15', NULL, 0, NULL, NULL, 6, NULL, NULL, 13, '2026-02-14 08:53:35', '2026-02-14 08:57:56', NULL, '2026-02-27');

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
(6, 'Technical', 'System, integration, performance and infrastructure related risks', 1, '2026-02-11 07:27:26'),
(7, 'Security', 'Cybersecurity, fraud, data protection and access control risks', 1, '2026-02-11 07:27:26'),
(8, 'Operational', 'Process failures, manual errors and operational disruptions', 1, '2026-02-11 07:27:26'),
(9, 'Regulatory', 'Compliance with NBE, card schemes and legal requirements', 1, '2026-02-11 07:27:26'),
(10, 'Business', 'Customer adoption, revenue impact and strategic alignment risks', 1, '2026-02-11 07:27:26'),
(11, 'Vendor', 'Third-party dependency, SLA issues and vendor performance risks', 1, '2026-02-11 07:27:26'),
(12, 'Financial', 'Cost overruns, budget constraints and financial losses', 1, '2026-02-11 07:27:26'),
(13, 'Change Management', 'User resistance, training gaps and process adoption risks', 1, '2026-02-11 07:27:26');

-- --------------------------------------------------------

--
-- Table structure for table `risk_comments`
--

CREATE TABLE `risk_comments` (
  `id` int(11) NOT NULL,
  `risk_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `comment_text` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `risk_comments`
--

INSERT INTO `risk_comments` (`id`, `risk_id`, `user_id`, `comment_text`, `created_at`) VALUES
(4, 7, 13, 'dsa', '2026-02-12 08:12:50'),
(5, 8, 13, 'Strengthen PIN Encryption and HSM Controls', '2026-02-12 08:23:31'),
(6, 8, 13, 'I am already look this metigation', '2026-02-12 10:04:46'),
(9, 11, 16, 'fdshoy', '2026-02-12 10:33:35'),
(10, 15, 16, 'gfdtr', '2026-02-13 09:18:23'),
(11, 18, 13, 'rdtfgh', '2026-02-13 11:46:47');

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
(85, 7, 13, 'created', 'Risk created by Mesfin (Status: Pending Review)', NULL, NULL, '2026-02-12 08:12:50'),
(86, 8, 13, 'created', 'Risk created by Mesfin (Status: Pending Review)', NULL, NULL, '2026-02-12 08:23:31'),
(87, 8, 5, 'assessed', 'Risk assessed: Likelihood=4 (Likely), Impact=5 (Catastrophic), Score=20, Level=High', NULL, NULL, '2026-02-12 08:26:49'),
(88, 8, 5, 'assessed', 'Risk assessed: Likelihood=4 (Likely), Impact=3 (Moderate), Score=12, Level=Medium', NULL, NULL, '2026-02-12 08:27:11'),
(89, 8, 5, 'status_changed', 'Risk approved by Mikiyas', NULL, NULL, '2026-02-12 08:28:53'),
(90, 8, 5, 'response_plan_updated', 'Response strategy set to: Mitigate, Target date: None', NULL, NULL, '2026-02-12 08:29:32'),
(91, 8, 5, 'owner_assigned', 'Risk owner assigned to: Mesfin', NULL, NULL, '2026-02-12 08:29:58'),
(92, 8, 5, 'mitigation_added', 'Mitigation added: ntegrate multiple SMS gateways, implement delivery tracking, and provide alternative PIN delivery channels via mobile app., Owner: Mesfin, Due: 2026-02-22', NULL, NULL, '2026-02-12 09:59:35'),
(93, 8, 13, 'mitigation_updated', 'Mitigation updated: ntegrate multiple SMS gateways, implement delivery tracking, and provide alternative PIN delivery channels via mobile app.', NULL, NULL, '2026-02-12 10:04:02'),
(94, 8, 13, 'mitigation_status_changed', 'Mitigation #17 status changed to: In progress', NULL, NULL, '2026-02-12 10:04:14'),
(95, 8, 13, 'comment_added', 'Comment added: I am already look this metigation', NULL, NULL, '2026-02-12 10:04:46'),
(96, 8, 3, 'mitigation_status_changed', 'Mitigation #17 status changed to: Done', NULL, NULL, '2026-02-12 10:05:37'),
(97, 8, 3, 'updated', 'Risk updated: PIN Compromise During Digital Delivery', NULL, NULL, '2026-02-12 10:07:12'),
(114, 11, 16, 'created', 'Risk created by GetnetM (Status: Pending Review)', NULL, NULL, '2026-02-12 10:33:35'),
(118, 7, 5, 'updated', 'Risk updated: sad', NULL, NULL, '2026-02-12 11:45:45'),
(127, 11, 3, 'status_changed', 'Risk approved by superAdmin', NULL, NULL, '2026-02-12 12:53:10'),
(128, 11, 3, 'assessed', 'Risk assessed: Likelihood=1 (Very Unlikely), Impact=4 (Major), Score=4, Level=Low', NULL, NULL, '2026-02-12 12:53:29'),
(129, 11, 3, 'response_plan_updated', 'Response strategy set to: Mitigate, Target date: 2026-02-13', NULL, NULL, '2026-02-12 12:53:40'),
(130, 11, 3, 'owner_assigned', 'Risk owner assigned to: Mesfin', NULL, NULL, '2026-02-12 12:53:58'),
(131, 7, 3, 'status_changed', 'Risk approved by superAdmin', NULL, NULL, '2026-02-12 13:09:54'),
(137, 8, 5, 'owner_assigned', 'Risk owner assigned to: Mesfin', NULL, NULL, '2026-02-12 13:57:44'),
(138, 8, 13, 'status_changed', 'Risk status changed from \'open\' to \'in_progress\'', NULL, NULL, '2026-02-12 13:59:34'),
(139, 8, 3, 'response_plan_updated', 'Response plan updated by superAdmin: Strategy=Mitigate, Target Date=Feb 13, 2026', NULL, NULL, '2026-02-13 05:55:34'),
(140, 8, 3, 'assessed', 'Risk assessed by superAdmin: Likelihood=3 (Possible), Impact=3 (Moderate), Score=9, Level=Medium', NULL, NULL, '2026-02-13 05:55:47'),
(141, 8, 3, 'owner_assigned', 'Risk owner assigned by superAdmin to: GetnetM', NULL, NULL, '2026-02-13 05:56:08'),
(142, 8, 3, 'status_changed', 'Risk status changed by superAdmin from \'in_progress\' to \'in_progress\'', NULL, NULL, '2026-02-13 05:56:16'),
(143, 8, 3, 'mitigation_added', 'Mitigation added by superAdmin: dsafzx, Owner: Mesfin, Due: Mar 1, 2026', NULL, NULL, '2026-02-13 05:56:35'),
(164, 8, 3, 'response_plan_updated', 'Response plan updated by superAdmin: Strategy=None, Target Date=None', NULL, NULL, '2026-02-13 07:29:05'),
(165, 8, 3, 'response_plan_updated', 'Response plan updated by superAdmin: Strategy=Mitigate, Target Date=Feb 14, 2026', NULL, NULL, '2026-02-13 07:29:17'),
(167, 13, 5, 'created', 'Risk created by Mikiyas in project Digital PIN', NULL, NULL, '2026-02-13 07:38:35'),
(168, 13, 3, 'response_plan_updated', 'Response plan updated by superAdmin: Strategy=Mitigate, Target Date=None', NULL, NULL, '2026-02-13 07:39:49'),
(169, 13, 3, 'assessed', 'Risk assessed by superAdmin: Likelihood=3 (Possible), Impact=2 (Minor), Score=6, Level=Medium', NULL, NULL, '2026-02-13 07:39:59'),
(170, 13, 3, 'owner_assigned', 'Risk owner assigned by superAdmin to: Mesfin', NULL, NULL, '2026-02-13 07:40:09'),
(171, 13, 5, 'status_changed', 'Risk status changed by Mikiyas from \'pending_review\' to \'in_progress\'', NULL, NULL, '2026-02-13 08:13:33'),
(172, 13, 5, 'status_changed', 'Risk status changed by Mikiyas from \'in_progress\' to \'in_progress\'', NULL, NULL, '2026-02-13 08:13:51'),
(173, 13, 5, 'assessed', 'Risk assessed by Mikiyas: Likelihood=3 (Possible), Impact=4 (Major), Score=12, Level=Medium', NULL, NULL, '2026-02-13 08:14:07'),
(174, 13, 5, 'status_changed', 'Risk status changed by Mikiyas from \'in_progress\' to \'in_progress\'', NULL, NULL, '2026-02-13 08:14:36'),
(175, 13, 5, 'status_changed', 'Risk status changed by Mikiyas from \'in_progress\' to \'in_progress\'', NULL, NULL, '2026-02-13 08:25:15'),
(176, 13, 5, 'mitigation_added', 'Mitigation added by Mikiyas: grds, Owner: GetnetM, Due: Feb 14, 2026', NULL, NULL, '2026-02-13 08:25:51'),
(177, 13, 3, 'status_changed', 'Risk status changed by superAdmin from \'in_progress\' to \'in_progress\'', NULL, NULL, '2026-02-13 08:26:52'),
(178, 13, 3, 'status_changed', 'Risk status changed by superAdmin from \'in_progress\' to \'closed\'', NULL, NULL, '2026-02-13 08:29:24'),
(179, 14, 13, 'created', 'Risk created by Mesfin in project Digital PIN', NULL, NULL, '2026-02-13 08:33:19'),
(180, 14, 5, 'owner_assigned', 'Risk owner assigned by Mikiyas to: Mesfin', NULL, NULL, '2026-02-13 08:34:29'),
(181, 14, 5, 'response_plan_updated', 'Response plan updated by Mikiyas: Strategy=Mitigate, Target Date=Feb 18, 2026', NULL, NULL, '2026-02-13 08:34:41'),
(182, 14, 5, 'assessed', 'Risk assessed by Mikiyas: Likelihood=3 (Possible), Impact=3 (Moderate), Score=9, Level=Medium', NULL, NULL, '2026-02-13 08:34:55'),
(183, 14, 5, 'status_changed', 'Risk status changed by Mikiyas from \'pending_review\' to \'in_progress\'', NULL, NULL, '2026-02-13 08:35:10'),
(184, 14, 5, 'owner_assigned', 'Risk owner assigned by Mikiyas to: GetnetM', NULL, NULL, '2026-02-13 08:38:47'),
(185, 14, 5, 'updated', 'Risk edited by Mikiyas: Title updated to \'muluk\'', NULL, NULL, '2026-02-13 08:51:21'),
(186, 11, 3, 'status_changed', 'Risk status changed by superAdmin from \'open\' to \'in_progress\'', NULL, NULL, '2026-02-13 08:55:26'),
(187, 11, 3, 'status_changed', 'Risk status changed by superAdmin from \'in_progress\' to \'closed\'', NULL, NULL, '2026-02-13 08:55:31'),
(188, 15, 13, 'created', 'Risk created by Mesfin in project Digital PIN - Status: Pending Review', NULL, NULL, '2026-02-13 09:10:36'),
(189, 15, 5, 'response_plan_updated', 'Response plan updated by Mikiyas: Strategy=Mitigate, Target Date=Feb 20, 2026', NULL, NULL, '2026-02-13 09:11:19'),
(190, 15, 5, 'assessed', 'Risk assessed by Mikiyas: Likelihood=3, Impact=4, Score=12, Level=Medium', NULL, NULL, '2026-02-13 09:11:36'),
(191, 15, 5, 'owner_assigned', 'Risk owner assigned by Mikiyas to: GetnetM', NULL, NULL, '2026-02-13 09:11:47'),
(192, 15, 5, 'mitigation_added', 'Mitigation added by Mikiyas: Strengthen Integration Testing and Monitoring, Owner: GetnetM, Due: None', NULL, NULL, '2026-02-13 09:12:23'),
(193, 15, 3, 'mitigation_status_changed', 'Mitigation \'Strengthen Integration Testing and Monitoring\' status changed by superAdmin to: In progress', NULL, NULL, '2026-02-13 09:13:05'),
(194, 15, 3, 'mitigation_status_changed', 'Mitigation \'Strengthen Integration Testing and Monitoring\' status changed by superAdmin to: Done', NULL, NULL, '2026-02-13 09:17:22'),
(195, 15, 16, 'mitigation_status_changed', 'Mitigation \'Strengthen Integration Testing and Monitoring\' status changed by GetnetM to: In progress', NULL, NULL, '2026-02-13 09:18:07'),
(196, 15, 16, 'comment_added', 'Comment added by GetnetM: gfdtr', NULL, NULL, '2026-02-13 09:18:23'),
(197, 15, 16, 'mitigation_status_changed', 'Mitigation \'Strengthen Integration Testing and Monitoring\' status changed by GetnetM to: Done', NULL, NULL, '2026-02-13 10:21:09'),
(198, 15, 16, 'status_changed', 'Risk status changed by GetnetM from \'pending_review\' to \'in_progress\'', NULL, NULL, '2026-02-13 10:21:26'),
(199, 15, 16, 'mitigation_status_changed', 'Mitigation \'Strengthen Integration Testing and Monitoring\' status changed by GetnetM to: In progress', NULL, NULL, '2026-02-13 10:21:37'),
(200, 15, 16, 'mitigation_status_changed', 'Mitigation \'Strengthen Integration Testing and Monitoring\' status changed by GetnetM to: Done', NULL, NULL, '2026-02-13 10:21:43'),
(201, 16, 16, 'created', 'Risk created by GetnetM in project Digital PIN (Status: Pending Review)', NULL, NULL, '2026-02-13 10:34:08'),
(202, 16, 5, 'status_changed', 'Risk APPROVED by Mikiyas', NULL, NULL, '2026-02-13 10:36:37'),
(203, 16, 5, 'status_changed', 'Risk status changed by Mikiyas from \'open\' to \'in_progress\'', NULL, NULL, '2026-02-13 10:45:04'),
(204, 16, 5, 'mitigation_added', 'Mitigation added by Mikiyas: edfg, Owner: Mesfin, Due: Feb 19, 2026', NULL, NULL, '2026-02-13 10:45:33'),
(205, 16, 13, 'mitigation_status_changed', 'Mitigation \'edfg\' status changed by Mesfin to: In progress', NULL, NULL, '2026-02-13 10:46:29'),
(206, 15, 3, 'response_plan_updated', 'Response strategy set to: Transfer (Target: 2026-02-20)', NULL, NULL, '2026-02-13 11:02:34'),
(207, 17, 13, 'created', 'Risk created by Mesfin in project Digital PIN (Status: Pending Review)', NULL, NULL, '2026-02-13 11:03:09'),
(208, 17, 5, 'response_plan_updated', 'Response strategy set to: Mitigate (Target: 2026-02-22)', NULL, NULL, '2026-02-13 11:04:15'),
(209, 17, 5, 'status_changed', 'Risk APPROVED by Mikiyas', NULL, NULL, '2026-02-13 11:04:24'),
(210, 17, 5, 'owner_assigned', 'Risk owner assigned: GetnetM', NULL, NULL, '2026-02-13 11:04:32'),
(211, 17, 5, 'mitigation_added', 'Mitigation added by Mikiyas: dsfdsfa, Owner: GetnetM, Due: Feb 14, 2026', NULL, NULL, '2026-02-13 11:05:11'),
(212, 17, 16, 'mitigation_status_changed', 'Mitigation \'dsfdsfa\' status changed by GetnetM to: In progress', NULL, NULL, '2026-02-13 11:07:06'),
(213, 17, 16, 'mitigation_status_changed', 'Mitigation \'dsfdsfa\' status changed by GetnetM to: Done', NULL, NULL, '2026-02-13 11:07:25'),
(214, 17, 3, 'response_plan_updated', 'Response strategy set to: Mitigate (Target: 2026-02-22)', NULL, NULL, '2026-02-13 11:16:47'),
(215, 17, 3, 'owner_assigned', 'Risk owner assigned: GetnetM', NULL, NULL, '2026-02-13 11:16:57'),
(216, 17, 3, 'mitigation_status_changed', 'Mitigation \'dsfdsfa\' status changed by superAdmin to: In progress', NULL, NULL, '2026-02-13 11:17:15'),
(217, 18, 13, 'created', 'Risk created by Mesfin in project Digital PIN (Status: Pending Review)', NULL, NULL, '2026-02-13 11:21:35'),
(218, 18, 3, 'status_changed', 'Risk APPROVED by superAdmin', NULL, NULL, '2026-02-13 11:44:54'),
(219, 18, 3, 'updated', 'Risk updated: fg', NULL, NULL, '2026-02-13 11:45:03'),
(220, 18, 3, 'response_plan_updated', 'Response strategy set to: Mitigate (Target: 2026-02-21)', NULL, NULL, '2026-02-13 11:45:13'),
(221, 18, 3, 'owner_assigned', 'Risk owner assigned: Mesfin', NULL, NULL, '2026-02-13 11:45:20'),
(222, 18, 13, 'comment_added', 'Comment added by Mesfin: rdtfgh', NULL, NULL, '2026-02-13 11:46:47'),
(223, 19, 3, 'created', 'Risk created by superAdmin in project Digital PIN (Status: Pending Review)', NULL, NULL, '2026-02-14 08:51:20'),
(224, 20, 13, 'created', 'Risk created by Mesfin in project Digital PIN (Status: Pending Review)', NULL, NULL, '2026-02-14 08:53:35'),
(225, 20, 5, 'status_changed', 'Risk APPROVED by Mikiyas', NULL, NULL, '2026-02-14 08:54:15'),
(226, 20, 5, 'response_plan_updated', 'Response strategy set to: Mitigate (Target: 2026-02-27)', NULL, NULL, '2026-02-14 08:54:41'),
(227, 20, 5, 'owner_assigned', 'Risk owner assigned: GetnetM', NULL, NULL, '2026-02-14 08:57:56'),
(228, 19, 5, 'updated', 'Risk updated: you will', NULL, NULL, '2026-02-14 08:59:25');

-- --------------------------------------------------------

--
-- Table structure for table `risk_mitigations`
--

CREATE TABLE `risk_mitigations` (
  `id` int(11) NOT NULL,
  `risk_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `response_strategy` enum('Avoid','Mitigate','Transfer','Accept') DEFAULT 'Mitigate',
  `description` text DEFAULT NULL,
  `owner_user_id` int(11) DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `status` enum('open','in_progress','done','cancelled') DEFAULT 'open',
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `risk_mitigations`
--

INSERT INTO `risk_mitigations` (`id`, `risk_id`, `title`, `response_strategy`, `description`, `owner_user_id`, `due_date`, `status`, `created_by`, `updated_by`, `created_at`, `updated_at`) VALUES
(17, 8, 'ntegrate multiple SMS gateways, implement delivery tracking, and provide alternative PIN delivery channels via mobile app.', 'Mitigate', 'Ensure end-to-end encryption, HSM usage, secure key management, and compliance audits.', 13, '2026-02-22', 'done', 5, 3, '2026-02-12 09:59:35', '2026-02-12 10:05:37'),
(21, 8, 'dsafzx', 'Mitigate', '', 13, '2026-03-01', 'open', 3, NULL, '2026-02-13 05:56:35', '2026-02-13 05:56:35'),
(23, 13, 'grds', 'Transfer', '', 16, '2026-02-14', 'open', 5, NULL, '2026-02-13 08:25:51', '2026-02-13 08:25:51'),
(24, 15, 'Strengthen Integration Testing and Monitoring', 'Mitigate', '', 16, NULL, 'done', 5, 16, '2026-02-13 09:12:23', '2026-02-13 10:21:43'),
(25, 16, 'edfg', 'Mitigate', '', 13, '2026-02-19', 'in_progress', 5, NULL, '2026-02-13 10:45:33', '2026-02-13 10:46:29'),
(26, 17, 'dsfdsfa', 'Mitigate', '', 16, '2026-02-14', 'in_progress', 5, NULL, '2026-02-13 11:05:11', '2026-02-13 11:17:15');

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
(6, 'open', 'Open', 1, '2026-02-11 07:19:40'),
(7, 'pending_review', 'Pending Review', 1, '2026-02-11 12:22:37'),
(8, 'in_progress', 'In Progress', 1, '2026-02-11 12:22:37'),
(9, 'mitigated', 'Mitigated', 1, '2026-02-11 12:22:37'),
(10, 'closed', 'Closed', 1, '2026-02-11 12:22:37'),
(11, 'rejected', 'Rejected', 1, '2026-02-11 12:22:37');

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
  `assigned_to` int(11) DEFAULT NULL,
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
(42, 40, 48, 43, 'Identify payment channels (ATM, Mobile, POS, Switch)', '', NULL, '', '2026-02-27', '2026-05-16', NULL, '2026-02-10 09:01:07', '2026-02-14 07:19:58'),
(43, 40, 48, 43, 'Define transaction types and volumes', '', NULL, '', '2026-02-14', '2026-05-24', NULL, '2026-02-10 09:01:07', '2026-02-14 07:19:58'),
(44, 40, 48, 43, 'Document security & compliance requirements', '', NULL, '', '2026-02-20', '2026-02-07', NULL, '2026-02-10 09:01:07', '2026-02-14 07:19:58'),
(45, 41, 48, 43, 'Conduct stakeholder workshops', '', NULL, '', '2026-02-07', '2026-03-01', NULL, '2026-02-10 09:02:47', '2026-02-14 07:19:58'),
(46, 41, 48, 43, 'Define roles & responsibilities', '', NULL, '', '2026-02-22', '2026-05-30', NULL, '2026-02-10 09:02:47', '2026-02-14 07:19:58'),
(47, 41, 48, 43, 'Approve project charter', '', NULL, '', '2026-02-22', '2026-02-22', NULL, '2026-02-10 09:02:47', '2026-02-14 07:19:58'),
(48, 43, 49, 43, 'Define API & message formats', '', NULL, '', '2026-01-27', '2026-02-13', NULL, '2026-02-10 10:16:30', '2026-02-14 07:19:58'),
(49, 43, 49, 43, 'Design EthioSwitch & Telebirr integration', '', NULL, '', '2026-02-05', '2026-03-07', NULL, '2026-02-10 10:16:30', '2026-02-14 07:19:58'),
(50, 43, 49, 43, 'Define security and encryption models', '', NULL, '', '2026-02-12', '2026-04-17', NULL, '2026-02-10 10:16:30', '2026-02-14 07:19:58'),
(51, 44, 50, 43, 'Configure routing rules', '', NULL, '', '2026-02-12', '2026-02-28', NULL, '2026-02-10 10:18:25', '2026-02-14 07:19:58'),
(52, 44, 50, 43, 'Setup security policies', '', NULL, '', '2026-02-13', '2026-05-10', NULL, '2026-02-10 10:18:25', '2026-02-14 07:19:58'),
(53, 44, 50, 43, 'Configure monitoring dashboards', '', NULL, '', '2026-02-12', '2026-04-10', NULL, '2026-02-10 10:18:25', '2026-02-14 07:19:58'),
(54, 45, 50, 43, 'Develop APIs', '', NULL, '', '2026-02-12', '2026-05-10', NULL, '2026-02-10 10:19:46', '2026-02-14 07:19:58'),
(55, 45, 50, 43, 'Implement message transformation', '', NULL, '', '2026-02-13', '2026-02-21', NULL, '2026-02-10 10:19:46', '2026-02-14 07:19:58'),
(56, 45, 50, 43, 'Integrate third-party services', '', NULL, '', '2026-02-12', '2026-05-16', NULL, '2026-02-10 10:19:46', '2026-02-14 07:19:58'),
(57, 48, 52, 42, 'Stakeholder Consultation', 'Conduct workshops with Card Banking, Digital Channels, Risk, Compliance, and IT teams to gather requirements.', NULL, 'pending', '2026-02-05', '2026-05-10', NULL, '2026-02-10 10:32:34', '2026-02-10 10:32:34'),
(58, 48, 52, 42, 'Customer Use-Case Definition', 'Identify customer scenarios such as online shopping, subscriptions, and merchant payments.', NULL, 'pending', '2026-02-14', '2026-05-10', NULL, '2026-02-10 10:32:34', '2026-02-10 10:32:34'),
(59, 48, 52, 42, 'Requirement Documentation & Sign-off', 'Prepare formal requirement documents and obtain approval from business owners.', NULL, 'pending', '2026-02-11', '2026-04-10', NULL, '2026-02-10 10:32:34', '2026-02-10 10:32:34'),
(60, 50, 54, 44, 'Stakeholder Workshops', 'Gather requirements from Digital Banking, Retail, IT, and Compliance teams.', NULL, '', '2026-02-11', '2026-04-10', NULL, '2026-02-10 10:39:31', '2026-02-14 07:12:19'),
(61, 50, 54, 44, 'Customer Use-Case Analysis', 'Define P2P, merchant payments, QR payments, bill payments.', NULL, '', '2026-02-22', '2026-04-24', NULL, '2026-02-10 10:39:31', '2026-02-14 07:12:19'),
(62, 50, 54, 44, 'Requirement Approval', 'Validate and approve documented requirements.', NULL, '', '2026-02-10', '2026-02-13', NULL, '2026-02-10 10:39:31', '2026-02-14 07:12:19'),
(63, 52, 56, 45, 'Stakeholder Requirement Gathering', 'Collect requirements from Card Ops, IT, Security.', NULL, 'pending', '2026-02-20', '2026-04-19', NULL, '2026-02-10 10:45:49', '2026-02-10 10:45:49'),
(64, 52, 56, 45, 'Process Mapping', 'Map current PIN distribution processes.', NULL, 'pending', '2026-02-18', '2026-05-22', NULL, '2026-02-10 10:45:49', '2026-02-10 10:45:49'),
(65, 54, 59, 44, 'weafdgh', '', NULL, '', '2026-02-13', '2026-02-20', NULL, '2026-02-14 07:11:21', '2026-02-14 07:12:19');

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
(2, 'Project Scheduler\r\n', 'http://localhost/test-manager/dashboard_project_manager.php'),
(3, 'Test Case management', 'http://localhost/test-manager/dashboard_testcase.php'),
(4, 'Change Control', 'http://localhost/test-manager/change_management_system/dashboard.php'),
(5, 'Risk Management', 'http://localhost/test-manager/Risk/risk_dashboard.php'),
(6, 'Issue Management', 'http://localhost/test-manager/PIM/index.php'),
(7, 'Event Managment', 'http://localhost/test-manager/project-event-management/dashboard.php'),
(8, 'Project Intake Form ', 'http://localhost/test-manager/project_intake_form/dashboard.php');

-- --------------------------------------------------------

--
-- Table structure for table `task_dependencies`
--

CREATE TABLE `task_dependencies` (
  `id` int(11) UNSIGNED NOT NULL,
  `project_id` int(11) NOT NULL,
  `task_type` varchar(50) NOT NULL COMMENT 'e.g., phase, activity, sub_activity',
  `task_id` int(11) NOT NULL COMMENT 'ID of the task/phase that has the dependency',
  `dependency_type` varchar(50) NOT NULL COMMENT 'e.g., phase, milestone',
  `depends_on_id` int(11) NOT NULL COMMENT 'ID of the task/milestone it depends on',
  `depends_on_parent_type` varchar(50) DEFAULT NULL COMMENT 'Used for milestones: project or phase',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tester_remark_logs`
--

CREATE TABLE `tester_remark_logs` (
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
-- Dumping data for table `tester_remark_logs`
--

INSERT INTO `tester_remark_logs` (`id`, `user_id`, `action`, `description`, `test_case_id`, `project_id`, `created_at`, `is_read`, `entity_type`, `entity_id`) VALUES
(74, 2, 'Tester Comment Updated', 'Tester updated remark: the injlkj', 60, 42, '2026-02-14 11:18:06', 0, NULL, NULL);

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
(54, 42, 30, 'Verify transaction routing for ATM transaction', '1. Initiate ATM transaction → 2. Check routing logic → 3. Verify transaction reaches the correct processing system', 'Transaction should be correctly routed to UPF processing engine and completed successfully', 'Pending', 'High', 'Every transaction', 'Web', '', '', 3, '2026-02-10 15:37:23', '2026-02-10 15:37:23'),
(55, 42, 32, 'Fraud detection triggers on suspicious transaction', '1. Simulate a transaction exceeding risk threshold → 2. Verify fraud alert → 3. Confirm transaction is blocked', 'Fraud system should detect, alert, and block transaction', 'Pending', 'Medium', 'Every transaction', 'Web', '', '', 3, '2026-02-10 15:39:19', '2026-02-10 15:39:19'),
(56, 43, 33, 'Verify virtual card creation', 'Login → 2. Select “Generate Virtual Card” → 3. Complete request → 4. Verify card generated', 'Virtual card number is generated and linked to customer account', 'Pending', 'Medium', 'On request', 'Web', '', '', 3, '2026-02-10 15:41:36', '2026-02-10 15:41:36'),
(57, 43, 34, 'Verify virtual card suspension', 'Select active virtual card → 2. Suspend card → 3. Attempt transaction → 4. Verify failure', 'Suspended card cannot be used for transactions', 'Pending', 'Medium', 'On request', 'web', '', '', 3, '2026-02-10 15:44:21', '2026-02-10 15:44:21'),
(58, 44, 36, 'Verify P2P transfer functionality', 'Login → 2. Select P2P → 3. Enter beneficiary and amount → 4. Confirm transaction', 'Funds are transferred and notifications sent', 'Pending', 'Medium', 'Daily', 'Mobile', '', '', 3, '2026-02-10 15:45:59', '2026-02-10 15:45:59'),
(59, 44, 37, 'Verify QR payment at merchant', '1. Generate QR → 2. Scan QR → 3. Confirm amount → 4. Verify success', 'Payment successfully processed and notification received', 'Pending', 'Medium', 'Daily', 'POS', '', '', 3, '2026-02-10 15:47:02', '2026-02-10 15:47:02'),
(60, 42, 32, 'ehwrjufhh', 'dekjhh', 'fewm,nk', 'Pending', 'Medium', 'fnjj', 'fjkjk', 'the injlkj', '', 3, '2026-02-14 11:15:45', '2026-02-14 11:18:06'),
(61, 42, 30, 'rwetr', 'trf', 'fg', 'Pending', 'Medium', 'g', 'fg', 'fg', 'tetst case', 2, '2026-02-14 11:21:11', '2026-02-14 11:25:37'),
(62, 42, 30, 's', 'f', 'fff', 'Pending', 'Medium', 'ff', 'hh', 'dd', '', 2, '2026-02-14 11:21:11', '2026-02-14 11:21:11');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) DEFAULT NULL,
  `profile_picture` varchar(255) DEFAULT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) DEFAULT NULL,
  `system_role` enum('super_admin','tester','test_viewer','admin','pm_manager','pm_employee','pm_viewer') DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `reset_token` varchar(100) DEFAULT NULL,
  `reset_expiry` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `profile_picture`, `email`, `password`, `system_role`, `created_at`, `updated_at`, `reset_token`, `reset_expiry`) VALUES
(2, 'muluken', NULL, 'jhonmiki1757@gmail.com', '$2y$10$wmnOD0uQZ5/EaD2pJo1EnekzdArqqIeDUxQ172lBfLj3gByVmgfeC', 'tester', '2025-07-08 06:18:41', '2025-09-30 13:54:46', 'ac00ce490f3138a1f0006b821836c620c212043d18b84bc588886fb190939f02', '2025-09-30 16:54:46'),
(3, 'superAdmin', '1759910490_mn.jpg', 'negamuluken1@gmail.com', '$2y$10$wmnOD0uQZ5/EaD2pJo1EnekzdArqqIeDUxQ172lBfLj3gByVmgfeC', 'super_admin', '2025-07-08 06:18:41', '2025-10-08 08:01:30', 'd29bb91a5d216c8791ada04aa3f31958c56f52ccb3c08f3107d2110139433a0a', '2025-10-03 14:23:04'),
(4, 'User1', NULL, 'Mikiyaszewdu1757@gmail.com', '$2y$10$wmnOD0uQZ5/EaD2pJo1EnekzdArqqIeDUxQ172lBfLj3gByVmgfeC', 'test_viewer', '2025-07-08 06:18:41', '2025-09-05 06:24:54', NULL, NULL),
(5, 'Mikiyas', NULL, 'temp-5@example.com', '$2y$10$wmnOD0uQZ5/EaD2pJo1EnekzdArqqIeDUxQ172lBfLj3gByVmgfeC', 'pm_manager', '2025-07-08 06:18:41', '2025-10-07 12:20:47', NULL, NULL),
(6, 'mulu', NULL, 'temp-6@example.com', '$2y$10$JmzpwjaSvxNhMpgYTe.Cbegg3LDybYR.8EQNAfy7K5jSLWZwpJipu', 'tester', '2025-07-08 06:18:41', '2025-07-08 08:46:42', NULL, NULL),
(7, 'Abebe', NULL, 'temp-7@example.com', '$2y$10$DDhh16lYUbM20qRfY5C1heAhAoehBET5teLlktXUXLEaSwWHmYABu', 'pm_employee', '2025-07-08 06:18:41', '2025-08-02 20:33:18', NULL, NULL),
(13, 'Mesfin', '1759996416_Haile_Selassie_in_full_dress_(3x4_cropped).jpg', 'temp-13@example.com', '$2y$10$wmnOD0uQZ5/EaD2pJo1EnekzdArqqIeDUxQ172lBfLj3gByVmgfeC', 'pm_employee', '2025-07-08 06:18:41', '2025-10-09 07:53:36', NULL, NULL),
(14, 'fasika', NULL, 'temp-14@example.com', '$2y$10$nS.Nm5.wTN6uTbIK8Anyzuq8pEzFlgv9JLd/tUBbFmV6RGHXFYZy2', 'super_admin', '2025-07-08 06:18:41', '2025-07-08 08:46:42', NULL, NULL),
(15, 'Adane', NULL, 'temp-15@example.com', '$2y$10$3ZJziS5E.Yo9r8.gy50JGuYy.uoe4Bn8M70b68xFEgOMc6Q6pRPBa', 'tester', '2025-07-08 06:18:41', '2025-07-08 08:46:42', NULL, NULL),
(16, 'GetnetM', NULL, 'temp-16@example.com', '$2y$10$Hv.LggR0nvdFKoaWX77u9Or6XfkJQG6ZKPqRXnzzwN9H7qtoFIxMm', 'pm_employee', '2025-07-08 06:18:41', '2025-08-02 20:36:18', NULL, NULL),
(26, 'admin', NULL, 'admin@gmail.com', '$2y$10$6aYGkEmwUajqhd.4KBgxxeHwqJyL8xxCp61cnlZeFC84X4DN89rpG', 'admin', '2025-11-06 11:11:14', '2025-11-06 11:11:14', NULL, NULL),
(27, 'ReportTester', NULL, 'ReportTester@gmail.com', '$2y$10$nTv0lf/KC8dhFAtv4SAAWOyA2G9R/hIqLWdXn/z7VBR7k/Irj6odC', 'pm_employee', '2025-11-12 06:27:21', '2025-11-12 06:27:21', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `user_assignments`
--

CREATE TABLE `user_assignments` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `assigned_by` int(11) NOT NULL,
  `project_id` int(11) DEFAULT NULL,
  `phase_id` int(11) DEFAULT NULL,
  `activity_id` int(11) DEFAULT NULL,
  `subactivity_id` int(11) DEFAULT NULL,
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_assignments`
--

INSERT INTO `user_assignments` (`id`, `user_id`, `assigned_by`, `project_id`, `phase_id`, `activity_id`, `subactivity_id`, `assigned_at`, `is_active`) VALUES
(208, 3, 3, 42, NULL, NULL, NULL, '2026-02-10 08:40:54', 1),
(209, 3, 3, 43, NULL, NULL, NULL, '2026-02-10 08:40:54', 1),
(210, 3, 3, 44, NULL, NULL, NULL, '2026-02-10 08:40:54', 1),
(211, 3, 3, 45, NULL, NULL, NULL, '2026-02-10 08:40:54', 1),
(212, 5, 3, 45, NULL, NULL, NULL, '2026-02-11 13:28:21', 1),
(213, 5, 3, 44, NULL, NULL, NULL, '2026-02-11 13:28:33', 1),
(214, 5, 3, 42, NULL, NULL, NULL, '2026-02-11 13:28:46', 1),
(215, 13, 5, 45, NULL, NULL, NULL, '2026-02-11 13:30:15', 1),
(216, 16, 3, 45, NULL, NULL, NULL, '2026-02-12 10:15:44', 1),
(217, 2, 3, 42, NULL, NULL, NULL, '2026-02-14 08:17:20', 1),
(218, 4, 3, 42, NULL, NULL, NULL, '2026-02-14 08:24:37', 1);

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
(20, 2, 3),
(12, 3, 1),
(13, 3, 2),
(15, 3, 3),
(14, 3, 4),
(16, 3, 5),
(18, 3, 6),
(7, 3, 7),
(8, 3, 8),
(1, 4, 3),
(83, 5, 1),
(87, 5, 2),
(89, 5, 3),
(84, 5, 4),
(88, 5, 5),
(85, 5, 6),
(86, 5, 8),
(92, 7, 1),
(77, 13, 1),
(80, 13, 2),
(82, 13, 3),
(78, 13, 4),
(81, 13, 5),
(79, 13, 6),
(60, 15, 5),
(58, 15, 7),
(59, 15, 8),
(91, 16, 5),
(90, 16, 6),
(43, 26, 1),
(44, 26, 4),
(45, 26, 6),
(46, 27, 1),
(49, 27, 2),
(51, 27, 3),
(47, 27, 4),
(50, 27, 5),
(48, 27, 6);

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
(5, 'ACI ', 'Service Provider', '234567', 'Fasika yemane ', 'fasika.yemane@dashnbanksc.com', '0945424442', 'due date ');

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
  ADD KEY `fk_budget_item_project` (`project_id`),
  ADD KEY `fk_created_by` (`created_by`);

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
-- Indexes for table `checkpoint_documents`
--
ALTER TABLE `checkpoint_documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `project_intake_id` (`project_intake_id`),
  ADD KEY `uploaded_by` (`uploaded_by`);

--
-- Indexes for table `checkpoint_evaluations`
--
ALTER TABLE `checkpoint_evaluations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `project_intake_id` (`project_intake_id`),
  ADD KEY `review_board_member_id` (`review_board_member_id`);

--
-- Indexes for table `checkpoint_logs`
--
ALTER TABLE `checkpoint_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `project_intake_id` (`project_intake_id`),
  ADD KEY `user_id` (`user_id`);

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
-- Indexes for table `events`
--
ALTER TABLE `events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `project_id` (`project_id`),
  ADD KEY `organizer_id` (`organizer_id`);

--
-- Indexes for table `event_attendees`
--
ALTER TABLE `event_attendees`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_event_user` (`event_id`,`user_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `event_resources`
--
ALTER TABLE `event_resources`
  ADD PRIMARY KEY (`id`),
  ADD KEY `event_id` (`event_id`);

--
-- Indexes for table `event_tasks`
--
ALTER TABLE `event_tasks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `event_id` (`event_id`),
  ADD KEY `assigned_to` (`assigned_to`),
  ADD KEY `fk_event_tasks_created_by` (`created_by`);

--
-- Indexes for table `features`
--
ALTER TABLE `features`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_feature_project` (`project_id`);

--
-- Indexes for table `gate_review_actions`
--
ALTER TABLE `gate_review_actions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `meeting_id` (`meeting_id`),
  ADD KEY `item_id` (`item_id`),
  ADD KEY `assigned_to` (`assigned_to`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `gate_review_attendees`
--
ALTER TABLE `gate_review_attendees`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_meeting_user` (`meeting_id`,`user_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `gate_review_documents`
--
ALTER TABLE `gate_review_documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `meeting_id` (`meeting_id`),
  ADD KEY `item_id` (`item_id`),
  ADD KEY `uploaded_by` (`uploaded_by`);

--
-- Indexes for table `gate_review_items`
--
ALTER TABLE `gate_review_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `meeting_id` (`meeting_id`),
  ADD KEY `project_intake_id` (`project_intake_id`),
  ADD KEY `presenter_id` (`presenter_id`);

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
  ADD KEY `created_by` (`created_by`),
  ADD KEY `fk_issues_approved_by` (`approved_by`);

--
-- Indexes for table `logs`
--
ALTER TABLE `logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `milestones`
--
ALTER TABLE `milestones`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_project` (`project_id`),
  ADD KEY `idx_phase` (`phase_id`),
  ADD KEY `idx_activity` (`activity_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_read` (`user_id`,`is_read`),
  ADD KEY `idx_created` (`created_at`),
  ADD KEY `idx_action_by` (`action_by`),
  ADD KEY `idx_archived` (`is_archived`);

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
-- Indexes for table `pm_notifications`
--
ALTER TABLE `pm_notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_notifications_user_read` (`user_id`,`is_read`),
  ADD KEY `idx_notifications_entity` (`entity_type`,`entity_id`),
  ADD KEY `idx_notifications_created_at` (`created_at`);

--
-- Indexes for table `pm_notification_settings`
--
ALTER TABLE `pm_notification_settings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

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
-- Indexes for table `project_intakes`
--
ALTER TABLE `project_intakes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `department_id` (`department_id`),
  ADD KEY `submitted_by` (`submitted_by`),
  ADD KEY `reviewed_by` (`reviewed_by`);

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
  ADD KEY `idx_risks_status` (`status_id`),
  ADD KEY `fk_risks_identified_by` (`identified_by`),
  ADD KEY `fk_risks_approved_by` (`approved_by`);

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
-- Indexes for table `risk_comments`
--
ALTER TABLE `risk_comments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_risk_comments_risk` (`risk_id`),
  ADD KEY `idx_risk_comments_user` (`user_id`);

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
  ADD KEY `idx_mit_owner` (`owner_user_id`),
  ADD KEY `fk_risk_mitigations_updated_by` (`updated_by`);

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
-- Indexes for table `task_dependencies`
--
ALTER TABLE `task_dependencies`
  ADD PRIMARY KEY (`id`),
  ADD KEY `project_id` (`project_id`);

--
-- Indexes for table `tester_remark_logs`
--
ALTER TABLE `tester_remark_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `test_case_id` (`test_case_id`),
  ADD KEY `project_id` (`project_id`);

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=55;

--
-- AUTO_INCREMENT for table `activity_log`
--
ALTER TABLE `activity_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `activity_logs`
--
ALTER TABLE `activity_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=462;

--
-- AUTO_INCREMENT for table `activity_users`
--
ALTER TABLE `activity_users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `actual_expenses`
--
ALTER TABLE `actual_expenses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT for table `attachments`
--
ALTER TABLE `attachments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `budget_categories`
--
ALTER TABLE `budget_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `budget_items`
--
ALTER TABLE `budget_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=53;

--
-- AUTO_INCREMENT for table `change_logs`
--
ALTER TABLE `change_logs`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=81;

--
-- AUTO_INCREMENT for table `change_requests`
--
ALTER TABLE `change_requests`
  MODIFY `change_request_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=36;

--
-- AUTO_INCREMENT for table `change_request_comments`
--
ALTER TABLE `change_request_comments`
  MODIFY `comment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

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
-- AUTO_INCREMENT for table `checkpoint_documents`
--
ALTER TABLE `checkpoint_documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `checkpoint_evaluations`
--
ALTER TABLE `checkpoint_evaluations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `checkpoint_logs`
--
ALTER TABLE `checkpoint_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `comments`
--
ALTER TABLE `comments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `contracts`
--
ALTER TABLE `contracts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `cost_types`
--
ALTER TABLE `cost_types`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `email_logs`
--
ALTER TABLE `email_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `events`
--
ALTER TABLE `events`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `event_attendees`
--
ALTER TABLE `event_attendees`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `event_resources`
--
ALTER TABLE `event_resources`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `event_tasks`
--
ALTER TABLE `event_tasks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `features`
--
ALTER TABLE `features`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=41;

--
-- AUTO_INCREMENT for table `gate_review_actions`
--
ALTER TABLE `gate_review_actions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `gate_review_attendees`
--
ALTER TABLE `gate_review_attendees`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `gate_review_documents`
--
ALTER TABLE `gate_review_documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `gate_review_items`
--
ALTER TABLE `gate_review_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `impact_areas`
--
ALTER TABLE `impact_areas`
  MODIFY `impact_area_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `issues`
--
ALTER TABLE `issues`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `logs`
--
ALTER TABLE `logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

--
-- AUTO_INCREMENT for table `milestones`
--
ALTER TABLE `milestones`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=46;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=448;

--
-- AUTO_INCREMENT for table `parent_budget_categories`
--
ALTER TABLE `parent_budget_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `phases`
--
ALTER TABLE `phases`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=60;

--
-- AUTO_INCREMENT for table `phase_users`
--
ALTER TABLE `phase_users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=40;

--
-- AUTO_INCREMENT for table `pm_notifications`
--
ALTER TABLE `pm_notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pm_notification_settings`
--
ALTER TABLE `pm_notification_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `priorities`
--
ALTER TABLE `priorities`
  MODIFY `priority_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `projects`
--
ALTER TABLE `projects`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=46;

--
-- AUTO_INCREMENT for table `project_intakes`
--
ALTER TABLE `project_intakes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `project_users`
--
ALTER TABLE `project_users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=185;

--
-- AUTO_INCREMENT for table `risks`
--
ALTER TABLE `risks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `risk_attachments`
--
ALTER TABLE `risk_attachments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `risk_categories`
--
ALTER TABLE `risk_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `risk_comments`
--
ALTER TABLE `risk_comments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `risk_history`
--
ALTER TABLE `risk_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=229;

--
-- AUTO_INCREMENT for table `risk_mitigations`
--
ALTER TABLE `risk_mitigations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

--
-- AUTO_INCREMENT for table `risk_statuses`
--
ALTER TABLE `risk_statuses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `sub_activities`
--
ALTER TABLE `sub_activities`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=66;

--
-- AUTO_INCREMENT for table `sub_activity_users`
--
ALTER TABLE `sub_activity_users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `systems`
--
ALTER TABLE `systems`
  MODIFY `system_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `task_dependencies`
--
ALTER TABLE `task_dependencies`
  MODIFY `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tester_remark_logs`
--
ALTER TABLE `tester_remark_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=75;

--
-- AUTO_INCREMENT for table `test_cases`
--
ALTER TABLE `test_cases`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=63;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=28;

--
-- AUTO_INCREMENT for table `user_assignments`
--
ALTER TABLE `user_assignments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=219;

--
-- AUTO_INCREMENT for table `user_systems`
--
ALTER TABLE `user_systems`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=93;

--
-- AUTO_INCREMENT for table `user_workload`
--
ALTER TABLE `user_workload`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `vendors`
--
ALTER TABLE `vendors`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

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
  ADD CONSTRAINT `fk_budget_item_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

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
-- Constraints for table `checkpoint_documents`
--
ALTER TABLE `checkpoint_documents`
  ADD CONSTRAINT `checkpoint_documents_ibfk_1` FOREIGN KEY (`project_intake_id`) REFERENCES `project_intakes` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `checkpoint_documents_ibfk_2` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `checkpoint_evaluations`
--
ALTER TABLE `checkpoint_evaluations`
  ADD CONSTRAINT `checkpoint_evaluations_ibfk_1` FOREIGN KEY (`project_intake_id`) REFERENCES `project_intakes` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `checkpoint_evaluations_ibfk_2` FOREIGN KEY (`review_board_member_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `checkpoint_logs`
--
ALTER TABLE `checkpoint_logs`
  ADD CONSTRAINT `checkpoint_logs_ibfk_1` FOREIGN KEY (`project_intake_id`) REFERENCES `project_intakes` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `checkpoint_logs_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

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
-- Constraints for table `events`
--
ALTER TABLE `events`
  ADD CONSTRAINT `events_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `events_ibfk_2` FOREIGN KEY (`organizer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `event_attendees`
--
ALTER TABLE `event_attendees`
  ADD CONSTRAINT `event_attendees_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `event_attendees_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `event_resources`
--
ALTER TABLE `event_resources`
  ADD CONSTRAINT `event_resources_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `event_tasks`
--
ALTER TABLE `event_tasks`
  ADD CONSTRAINT `event_tasks_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `event_tasks_ibfk_2` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_event_tasks_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `features`
--
ALTER TABLE `features`
  ADD CONSTRAINT `fk_feature_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `gate_review_actions`
--
ALTER TABLE `gate_review_actions`
  ADD CONSTRAINT `gate_review_actions_ibfk_1` FOREIGN KEY (`meeting_id`) REFERENCES `gate_review_meetings` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `gate_review_actions_ibfk_2` FOREIGN KEY (`item_id`) REFERENCES `gate_review_items` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `gate_review_actions_ibfk_3` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `gate_review_actions_ibfk_4` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `gate_review_attendees`
--
ALTER TABLE `gate_review_attendees`
  ADD CONSTRAINT `gate_review_attendees_ibfk_1` FOREIGN KEY (`meeting_id`) REFERENCES `gate_review_meetings` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `gate_review_attendees_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `gate_review_documents`
--
ALTER TABLE `gate_review_documents`
  ADD CONSTRAINT `gate_review_documents_ibfk_1` FOREIGN KEY (`meeting_id`) REFERENCES `gate_review_meetings` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `gate_review_documents_ibfk_2` FOREIGN KEY (`item_id`) REFERENCES `gate_review_items` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `gate_review_documents_ibfk_3` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `gate_review_items`
--
ALTER TABLE `gate_review_items`
  ADD CONSTRAINT `gate_review_items_ibfk_1` FOREIGN KEY (`meeting_id`) REFERENCES `gate_review_meetings` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `gate_review_items_ibfk_2` FOREIGN KEY (`project_intake_id`) REFERENCES `project_intakes` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `gate_review_items_ibfk_3` FOREIGN KEY (`presenter_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `issues`
--
ALTER TABLE `issues`
  ADD CONSTRAINT `fk_issues_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `issues_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `issues_ibfk_2` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `issues_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `logs`
--
ALTER TABLE `logs`
  ADD CONSTRAINT `logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `milestones`
--
ALTER TABLE `milestones`
  ADD CONSTRAINT `fk_milestones_activity` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_milestones_phase` FOREIGN KEY (`phase_id`) REFERENCES `phases` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_milestones_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `fk_notifications_action_by` FOREIGN KEY (`action_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
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
-- Constraints for table `pm_notifications`
--
ALTER TABLE `pm_notifications`
  ADD CONSTRAINT `pm_notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `pm_notifications_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `pm_notification_settings`
--
ALTER TABLE `pm_notification_settings`
  ADD CONSTRAINT `pm_notification_settings_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `projects`
--
ALTER TABLE `projects`
  ADD CONSTRAINT `fk_project_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `projects_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `project_intakes`
--
ALTER TABLE `project_intakes`
  ADD CONSTRAINT `project_intakes_ibfk_1` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `project_intakes_ibfk_2` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `project_intakes_ibfk_3` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

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
  ADD CONSTRAINT `fk_risks_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_risks_category` FOREIGN KEY (`category_id`) REFERENCES `risk_categories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_risks_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_risks_identified_by` FOREIGN KEY (`identified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
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
-- Constraints for table `risk_comments`
--
ALTER TABLE `risk_comments`
  ADD CONSTRAINT `fk_risk_comments_risk` FOREIGN KEY (`risk_id`) REFERENCES `risks` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_risk_comments_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

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
  ADD CONSTRAINT `fk_mitigation_risk` FOREIGN KEY (`risk_id`) REFERENCES `risks` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_risk_mitigations_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

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
