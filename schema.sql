-- Special Order Management System — Database Schema
-- Run via install.php (automatically) or manually via MySQL CLI

CREATE DATABASE IF NOT EXISTS `order_management` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `order_management`;

CREATE TABLE IF NOT EXISTS brands (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  name             VARCHAR(255) NOT NULL,
  discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS orders (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  brand_id      INT NOT NULL,
  part_number   VARCHAR(255) NOT NULL,
  link          TEXT,
  price_usd     DECIMAL(10,2) NOT NULL,
  weight        DECIMAL(8,3) NOT NULL,
  l             DECIMAL(8,2) NOT NULL,
  w             DECIMAL(8,2) NOT NULL,
  h             DECIMAL(8,2) NOT NULL,
  cost_aed      DECIMAL(10,2),
  agreed_price  DECIMAL(10,2),
  po_path       VARCHAR(500),
  tracking_no   VARCHAR(255),
  status        ENUM('Pending','Ordered') NOT NULL DEFAULT 'Pending',
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (brand_id) REFERENCES brands(id)
);

CREATE TABLE IF NOT EXISTS users (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(100) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('admin','employee') NOT NULL DEFAULT 'employee',
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Seed brands
INSERT INTO brands (name, discount_percent) VALUES
  ('Apple',   5.00),
  ('Samsung', 8.00),
  ('Sony',   10.00),
  ('LG',      7.50),
  ('Dell',    6.00);
