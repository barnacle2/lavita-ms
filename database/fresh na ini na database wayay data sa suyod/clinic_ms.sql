-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Nov 25, 2025 at 05:47 AM
-- Server version: 8.3.0
-- PHP Version: 8.3.6

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
  `expiration_date` date DEFAULT NULL,
  `category` varchar(50) NOT NULL DEFAULT 'Other',
  `low_stock_threshold` int DEFAULT NULL,
  `last_updated` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `unit_type` varchar(50) NOT NULL DEFAULT 'units',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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
  `category` varchar(100) DEFAULT NULL,
  `service_type` enum('individual','package') NOT NULL DEFAULT 'individual',
  `inventory_id` int DEFAULT NULL,
  `quantity_needed` int NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `inventory_id` (`inventory_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `service_inventory_bindings`
--

DROP TABLE IF EXISTS `service_inventory_bindings`;
CREATE TABLE IF NOT EXISTS `service_inventory_bindings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `service_id` int NOT NULL,
  `inventory_id` int NOT NULL,
  `quantity_needed` int NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  KEY `fk_sib_service` (`service_id`),
  KEY `fk_sib_inventory` (`inventory_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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
('system_theme_color', 'default');

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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
-- Constraints for table `services`
--
ALTER TABLE `services`
  ADD CONSTRAINT `services_ibfk_1` FOREIGN KEY (`inventory_id`) REFERENCES `inventory` (`id`) ON DELETE SET NULL;

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
