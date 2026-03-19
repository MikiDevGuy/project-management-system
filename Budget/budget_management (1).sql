-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Aug 08, 2025 at 09:32 AM
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
-- Database: `budget_management`
--

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
  `approved_by` int(11) DEFAULT NULL
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
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_forecasted` tinyint(1) DEFAULT 0,
  `fiscal_year` varchar(9) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `budget_items`
--

CREATE TABLE `budget_items` (
  `id` int(11) NOT NULL,
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
  `approved_by` int(11) DEFAULT NULL
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
(1, 1, 'vcn', '34', '2025-08-08', '2025-09-06', 23445.00, '12435', 'ertw', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `contract_staff_budgets`
--

CREATE TABLE `contract_staff_budgets` (
  `id` int(11) NOT NULL,
  `department_id` int(11) NOT NULL,
  `position` varchar(100) NOT NULL,
  `staff_count` int(11) DEFAULT 0,
  `contract_amount` decimal(15,2) DEFAULT 0.00,
  `contingency_percent` decimal(5,2) DEFAULT 0.00,
  `annual_cost` decimal(15,2) GENERATED ALWAYS AS (`contract_amount` * 12 * (1 + `contingency_percent` / 100)) STORED,
  `fiscal_year` varchar(9) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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

-- --------------------------------------------------------

--
-- Table structure for table `quarterly_budgets`
--

CREATE TABLE `quarterly_budgets` (
  `id` int(11) NOT NULL,
  `category_id` int(11) NOT NULL,
  `fiscal_year` varchar(9) NOT NULL,
  `q1_amount` decimal(15,2) DEFAULT 0.00,
  `q2_amount` decimal(15,2) DEFAULT 0.00,
  `q3_amount` decimal(15,2) DEFAULT 0.00,
  `q4_amount` decimal(15,2) DEFAULT 0.00,
  `total_amount` decimal(15,2) GENERATED ALWAYS AS (`q1_amount` + `q2_amount` + `q3_amount` + `q4_amount`) STORED,
  `remarks` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `staff_budgets`
--

CREATE TABLE `staff_budgets` (
  `id` int(11) NOT NULL,
  `department_id` int(11) NOT NULL,
  `position` varchar(100) NOT NULL,
  `staff_count` int(11) DEFAULT 0,
  `basic_salary` decimal(15,2) DEFAULT 0.00,
  `increment_percent` decimal(5,2) DEFAULT 0.00,
  `annual_salary` decimal(15,2) GENERATED ALWAYS AS (`basic_salary` * 12 * (1 + `increment_percent` / 100)) STORED,
  `pension_contribution` decimal(15,2) DEFAULT 0.00,
  `trust_fund` decimal(15,2) DEFAULT 0.00,
  `total_budget` decimal(15,2) GENERATED ALWAYS AS (`annual_salary` + `pension_contribution` + `trust_fund`) STORED,
  `fiscal_year` varchar(9) NOT NULL
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
(1, 'miki', 'software developer', '234', 'mule', 'negamuluken1@gmail.com', '0945424442', 'fixed'),
(2, 'miki', 'software developer', '234', 'mule', 'negamuluken1@gmail.com', '0945424442', '1234567890');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `actual_expenses`
--
ALTER TABLE `actual_expenses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `budget_item_id` (`budget_item_id`),
  ADD KEY `vendor_id` (`vendor_id`);

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
  ADD KEY `department_id` (`department_id`);

--
-- Indexes for table `contracts`
--
ALTER TABLE `contracts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `vendor_id` (`vendor_id`);

--
-- Indexes for table `contract_staff_budgets`
--
ALTER TABLE `contract_staff_budgets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `department_id` (`department_id`);

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
-- Indexes for table `quarterly_budgets`
--
ALTER TABLE `quarterly_budgets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `category_id` (`category_id`);

--
-- Indexes for table `staff_budgets`
--
ALTER TABLE `staff_budgets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `department_id` (`department_id`);

--
-- Indexes for table `vendors`
--
ALTER TABLE `vendors`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `actual_expenses`
--
ALTER TABLE `actual_expenses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `budget_categories`
--
ALTER TABLE `budget_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `budget_items`
--
ALTER TABLE `budget_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `contracts`
--
ALTER TABLE `contracts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `contract_staff_budgets`
--
ALTER TABLE `contract_staff_budgets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

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
-- AUTO_INCREMENT for table `quarterly_budgets`
--
ALTER TABLE `quarterly_budgets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `staff_budgets`
--
ALTER TABLE `staff_budgets`
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
-- Constraints for table `actual_expenses`
--
ALTER TABLE `actual_expenses`
  ADD CONSTRAINT `actual_expenses_ibfk_1` FOREIGN KEY (`budget_item_id`) REFERENCES `budget_items` (`id`),
  ADD CONSTRAINT `actual_expenses_ibfk_2` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`);

--
-- Constraints for table `budget_categories`
--
ALTER TABLE `budget_categories`
  ADD CONSTRAINT `budget_categories_ibfk_1` FOREIGN KEY (`parent_id`) REFERENCES `budget_categories` (`id`);

--
-- Constraints for table `budget_items`
--
ALTER TABLE `budget_items`
  ADD CONSTRAINT `budget_items_ibfk_1` FOREIGN KEY (`budget_category_id`) REFERENCES `budget_categories` (`id`),
  ADD CONSTRAINT `budget_items_ibfk_2` FOREIGN KEY (`cost_type_id`) REFERENCES `cost_types` (`id`),
  ADD CONSTRAINT `budget_items_ibfk_3` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`);

--
-- Constraints for table `contracts`
--
ALTER TABLE `contracts`
  ADD CONSTRAINT `contracts_ibfk_1` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`);

--
-- Constraints for table `contract_staff_budgets`
--
ALTER TABLE `contract_staff_budgets`
  ADD CONSTRAINT `contract_staff_budgets_ibfk_1` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`);

--
-- Constraints for table `quarterly_budgets`
--
ALTER TABLE `quarterly_budgets`
  ADD CONSTRAINT `quarterly_budgets_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `budget_categories` (`id`);

--
-- Constraints for table `staff_budgets`
--
ALTER TABLE `staff_budgets`
  ADD CONSTRAINT `staff_budgets_ibfk_1` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
