-- Procurement & Logistics ERP Schema
-- Run via install.php

CREATE TABLE IF NOT EXISTS settings (
    `key` VARCHAR(100) PRIMARY KEY,
    value TEXT NOT NULL
);

INSERT INTO settings (`key`, value) VALUES ('usd_to_aed', '3.6725')
    ON DUPLICATE KEY UPDATE value = value;
INSERT INTO settings (`key`, value) VALUES ('sns_anchors', '[{"u":10,"p":60},{"u":12,"p":70},{"u":20,"p":110},{"u":30,"p":160},{"u":50,"p":232},{"u":58,"p":268},{"u":100,"p":412},{"u":150,"p":592},{"u":200,"p":772},{"u":300,"p":1132},{"u":400,"p":1492},{"u":500,"p":1852}]')
    ON DUPLICATE KEY UPDATE value = value;

CREATE TABLE IF NOT EXISTS brands (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    discount_percent DECIMAL(5,2) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin','employee') DEFAULT 'employee',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    brand_id INT NOT NULL,
    product_name VARCHAR(500),
    price_usd DECIMAL(10,2) NOT NULL,
    discount_percent DECIMAL(5,2) NOT NULL,
    discounted_price_aed DECIMAL(10,2) NOT NULL,
    weight_kg DECIMAL(8,2) NOT NULL,
    dim_length DECIMAL(8,2),
    dim_width DECIMAL(8,2),
    dim_height DECIMAL(8,2),
    carrier ENUM('SelfShip PRO','Shop&Ship') NOT NULL,
    shipping_aed DECIMAL(10,2) NOT NULL,
    vat_aed DECIMAL(10,2) NOT NULL,
    customs_aed DECIMAL(10,2) NOT NULL DEFAULT 0,
    total_aed DECIMAL(10,2) NOT NULL,
    status ENUM('Draft','Requested','Ordered','In Transit (USA)','At Forwarder','Ship-Out Requested') DEFAULT 'Draft',
    tracking_number VARCHAR(200),
    customer_po_path VARCHAR(500),
    supplier_invoice_path VARCHAR(500),
    forwarder_doc_path VARCHAR(500),
    notes TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (brand_id) REFERENCES brands(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
);
