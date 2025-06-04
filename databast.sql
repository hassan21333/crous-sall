-- Create database
CREATE DATABASE IF NOT EXISTS pdf_store_v2;
USE pdf_store_v2;

-- Users table
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('user', 'admin') NOT NULL DEFAULT 'user',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Transactions table
CREATE TABLE transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    email VARCHAR(100) NOT NULL,
    transaction_id VARCHAR(255) NOT NULL,
    courses_purchased TEXT NOT NULL,
    total_amount DECIMAL(10,2) NOT NULL,
    order_status ENUM('Pending', 'Confirmed') NOT NULL DEFAULT 'Pending',
    purchase_timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);