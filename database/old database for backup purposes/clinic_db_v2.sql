-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Nov 17, 2025 at 01:51 PM
-- Server version: 9.1.0
-- PHP Version: 8.3.14

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
) ENGINE=InnoDB AUTO_INCREMENT=28 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `transactions`
--

INSERT INTO `transactions` (`transaction_id`, `patient_id`, `transaction_date`, `description`, `services`, `medicine_given`, `total_amount`, `created_at`) VALUES
(19, 8, '2025-09-01 00:00:00', 'Dental Cleaning', 'Blood Typing', '[{\"name\":\"Alcohol 70% Solution 500ml\",\"id\":\"6\",\"quantity\":1,\"unit\":\"bottle\",\"total\":80}]', 230.00, '2025-09-01 16:40:34'),
(20, 4, '2025-10-14 00:00:00', 'CBC, Urinalysis, Blood Typing [Discount: Student Promo (30%)]', 'Blood Typing, Complete Blood Count (CBC), Urinalysis', '[{\"name\":\"Normal Saline Solution 1L\",\"id\":\"19\",\"quantity\":1,\"unit\":\"bottle\",\"total\":85}]', 584.50, '2025-10-14 14:41:17'),
(21, 10, '2025-10-21 00:00:00', 'Laboratory', 'Urinalysis', '[{\"name\":\"Urine Specimen Cup\",\"id\":\"26\",\"quantity\":1,\"unit\":\"pcs\",\"total\":0}]', 250.00, '2025-10-21 04:16:55'),
(22, 11, '2025-11-16 00:00:00', '', 'Promo 1', '[]', 499.00, '2025-11-16 17:19:09'),
(23, 8, '2025-11-16 00:00:00', '', 'Promo 1', '[]', 499.00, '2025-11-16 17:23:18'),
(24, 11, '2025-11-17 00:00:00', '', 'Student Package', '[]', 699.00, '2025-11-17 13:05:11'),
(25, 11, '2025-11-17 00:00:00', '', 'Urinalysis', '[]', 250.00, '2025-11-17 13:05:29'),
(26, 11, '2025-11-17 00:00:00', '', 'Urinalysis', '[]', 250.00, '2025-11-17 13:20:50'),
(27, 11, '2025-11-17 00:00:00', '', 'Urinalysis', '[]', 250.00, '2025-11-17 13:44:53');

--
-- Constraints for dumped tables
--

--
-- Constraints for table `transactions`
--
ALTER TABLE `transactions`
  ADD CONSTRAINT `transactions_ibfk_1` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
