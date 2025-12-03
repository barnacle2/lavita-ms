-- SQL Migration Script: Add Expenses Table
-- This script adds the expenses table to track clinic expenses
-- Run this script on your existing clinic_ms database

-- Create expenses table
DROP TABLE IF EXISTS `expenses`;
CREATE TABLE IF NOT EXISTS `expenses` (
  `id` int NOT NULL AUTO_INCREMENT,
  `expense_name` varchar(255) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `expense_date` datetime NOT NULL,
  `description` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Success message
SELECT 'Expenses table created successfully!' AS message;
