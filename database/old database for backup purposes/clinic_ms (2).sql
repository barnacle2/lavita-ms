-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Nov 14, 2025 at 11:28 AM
-- Server version: 8.3.0
-- PHP Version: 8.2.18

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `clinic_ms`
--

-- --------------------------------------------------------

--
-- Table structure for table `inventory`
--

DROP TABLE IF EXISTS `inventory`;
CREATE TABLE IF NOT EXISTS `inventory` (
  `id` int NOT NULL AUTO_INCREMENT,
  `item_name` varchar(255) NOT NULL,
  `stock_quantity` int NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `last_updated` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `unit_type` varchar(50) NOT NULL DEFAULT 'units',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `inventory`
--

INSERT INTO `inventory` (`id`, `item_name`, `stock_quantity`, `unit_price`, `last_updated`, `unit_type`) VALUES
(1, 'Aspirin', 430, 2.50, '2025-08-12 20:28:54', 'units'),
(2, 'Bandages', 965, 1.25, '2025-09-26 20:27:14', 'units'),
(3, 'Syringes', 295, 5.00, '2025-08-12 20:13:52', 'units'),
(4, 'Alcohol 70% Solution', 3, 250.00, '2025-10-12 20:37:20', 'L'),
(5, 'Urine Collection Cup', 100, 0.00, '2025-10-13 16:14:24', 'pcs');

-- --------------------------------------------------------

--
-- Table structure for table `patients`
--

DROP TABLE IF EXISTS `patients`;
CREATE TABLE IF NOT EXISTS `patients` (
  `id` int NOT NULL AUTO_INCREMENT,
  `patient_code` varchar(20) NOT NULL,
  `fullname` varchar(255) NOT NULL,
  `date_of_birth` date NOT NULL,
  `sex` enum('Male','Female','Prefer not to say') DEFAULT 'Prefer not to say',
  `contact_number` varchar(20) DEFAULT NULL,
  `address` text,
  `date_registered` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `patient_code` (`patient_code`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `patients`
--

INSERT INTO `patients` (`id`, `patient_code`, `fullname`, `date_of_birth`, `sex`, `contact_number`, `address`, `date_registered`) VALUES
(4, 'PAT-105', 'Tim Perandos', '2000-02-03', 'Prefer not to say', '09517955211', 'Surigao City', '2025-08-09 14:15:50'),
(8, 'PAT-188', 'Mark Hoppus', '2001-04-02', 'Male', '09603303547', 'San Jose, California', '2025-09-01 15:21:16');

-- --------------------------------------------------------

--
-- Table structure for table `services`
--

DROP TABLE IF EXISTS `services`;
CREATE TABLE IF NOT EXISTS `services` (
  `id` int NOT NULL AUTO_INCREMENT,
  `service_name` varchar(255) NOT NULL,
  `description` text,
  `service_price` decimal(10,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=31 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `services`
--

INSERT INTO `services` (`id`, `service_name`, `description`, `service_price`, `created_at`) VALUES
(1, 'General Check-up', 'A standard health consultation and physical examination.', 500.00, '2025-08-09 11:46:38'),
(2, 'Dental Cleaning', 'Routine dental hygiene and plaque removal.', 800.00, '2025-08-09 11:46:38'),
(3, 'Tooth Extraction', 'Removal of a single tooth.', 1200.00, '2025-08-09 11:46:38'),
(18, 'CBC (Complete Blood Count)', 'Laboratory request for CBC to assess overall health and detect disorders such as anemia or infection.', 150.00, '2025-10-13 06:57:56'),
(19, 'Urine Analysis (Urinalysis)', 'Diagnostic urine test to check for signs of urinary infection or metabolic conditions.', 100.00, '2025-10-13 06:57:56'),
(20, 'Stool Examination', 'Laboratory analysis of stool sample to detect digestive tract issues or parasites.', 120.00, '2025-10-13 06:57:56'),
(21, 'Blood Sugar Test (RBS/FBS)', 'Finger-prick glucose test to check for abnormal sugar levels.', 80.00, '2025-10-13 06:57:56'),
(22, 'Laboratory Request Form Processing', 'Processing and issuance of lab request forms for partner health facilities.', 50.00, '2025-10-13 06:57:56'),
(23, 'Physical Health Check-Up', 'Basic physical examination to assess student health and fitness status.', 50.00, '2025-10-13 06:57:56'),
(24, 'Medical Assessment for Activity Clearance', 'Evaluation for participation in sports, PE, or school activities.', 80.00, '2025-10-13 06:57:56'),
(25, 'OJT / Internship Medical Evaluation', 'Medical evaluation required for internship or field training.', 100.00, '2025-10-13 06:57:56'),
(26, 'Blood Pressure Check', 'Routine blood pressure monitoring.', 20.00, '2025-10-13 06:57:56'),
(27, 'Vital Sign Follow-Up Monitoring', 'Scheduled follow-up for students under observation.', 20.00, '2025-10-13 06:57:56'),
(28, 'Post-Treatment Health Check', 'Follow-up evaluation after medication or treatment.', 30.00, '2025-10-13 06:57:56'),
(29, 'Health Certificate Issuance', 'Issuance of official health clearance or consultation certificate.', 50.00, '2025-10-13 06:57:56'),
(30, 'Medical Referral Endorsement', 'Referral documentation for external hospital or lab processing.', 50.00, '2025-10-13 06:57:56');

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

DROP TABLE IF EXISTS `settings`;
CREATE TABLE IF NOT EXISTS `settings` (
  `setting_name` varchar(255) NOT NULL,
  `setting_value` text NOT NULL,
  PRIMARY KEY (`setting_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `settings`
--

INSERT INTO `settings` (`setting_name`, `setting_value`) VALUES
('clinic_address', 'Mendoza Bldg, Amat Street, Surigao City, Philippines'),
('clinic_business_hours', 'Mon-Fri, 9am-5pm'),
('clinic_email', 'lavitacarelab@gmail.com'),
('clinic_name', 'La Vita Care Diagnostics, Medicine & Medical Supplies, Inc. '),
('clinic_phone_number', '0951 686 5350'),
('system_tax_rate', '0'),
('system_theme_color', 'dark');

-- --------------------------------------------------------

--
-- Table structure for table `transactions`
--

DROP TABLE IF EXISTS `transactions`;
CREATE TABLE IF NOT EXISTS `transactions` (
  `transaction_id` int NOT NULL AUTO_INCREMENT,
  `patient_id` int NOT NULL,
  `transaction_date` datetime NOT NULL,
  `description` text,
  `services` text,
  `medicine_given` text,
  `total_amount` decimal(10,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`transaction_id`),
  KEY `patient_id` (`patient_id`)
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `transactions`
--

INSERT INTO `transactions` (`transaction_id`, `patient_id`, `transaction_date`, `description`, `services`, `medicine_given`, `total_amount`, `created_at`) VALUES
(19, 4, '2025-09-26 00:00:00', 'Anual Check Up', 'Dental Cleaning, Tooth Extraction', '[{\"name\":\"Bandages\",\"id\":\"2\",\"quantity\":15,\"unit\":\"units\",\"total\":18.75}]', 2018.75, '2025-09-26 20:27:14'),
(21, 8, '2025-10-12 00:00:00', 'Anual Check Up [Discount: Senior Citizen (5%)]', 'Dental Cleaning', '[{\"name\":\"Alcohol 70% Solution\",\"id\":\"4\",\"quantity\":1,\"unit\":\"L\",\"total\":250}]', 997.50, '2025-10-12 20:37:05');

-- --------------------------------------------------------

--
-- Table structure for table `transaction_services`
--

DROP TABLE IF EXISTS `transaction_services`;
CREATE TABLE IF NOT EXISTS `transaction_services` (
  `id` int NOT NULL AUTO_INCREMENT,
  `transaction_id` int NOT NULL,
  `service_id` int NOT NULL,
  `price_at_transaction` decimal(10,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `transaction_id` (`transaction_id`),
  KEY `service_id` (`service_id`)
) ENGINE=InnoDB AUTO_INCREMENT=27 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `transaction_services`
--

INSERT INTO `transaction_services` (`id`, `transaction_id`, `service_id`, `price_at_transaction`) VALUES
(19, 19, 2, 800.00),
(20, 19, 3, 1200.00),
(22, 21, 2, 800.00);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
CREATE TABLE IF NOT EXISTS `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` varchar(50) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `role`, `created_at`) VALUES
(1, 'admin', 'password123', 'admin', '2025-08-12 14:10:40'),
(2, 'Administrator', '$2y$10$R9FsafDXC3cp2qxEqaDidup3jfi8hDzev6zjZlXJpQKupEOA8yuY2', 'admin', '2025-08-12 14:10:40'),
(3, 'cashier', '$2y$10$odiMNLIAZGDcno3KtYSXiuGhqaKH2T/k742951hQt5sRKmHAetey6', 'staff', '2025-08-12 14:35:19');

--
-- Constraints for dumped tables
--

--
-- Constraints for table `transactions`
--
ALTER TABLE `transactions`
  ADD CONSTRAINT `transactions_ibfk_1` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `transaction_services`
--
ALTER TABLE `transaction_services`
  ADD CONSTRAINT `transaction_services_ibfk_1` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`transaction_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `transaction_services_ibfk_2` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
