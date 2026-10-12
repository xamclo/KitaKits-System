CREATE DATABASE IF NOT EXISTS kitakits_db;
USE kitakits_db;

CREATE TABLE users (
 user_id INT AUTO_INCREMENT PRIMARY KEY,
 student_number VARCHAR(50) UNIQUE,
 first_name VARCHAR(100),
 last_name VARCHAR(100),
 email VARCHAR(150) UNIQUE,
 password VARCHAR(255),
 status ENUM('active','inactive') DEFAULT 'active',
 program VARCHAR(100) DEFAULT 'BSIT',
 contact_number VARCHAR(50) DEFAULT '09123456789'
);

CREATE TABLE equipment (
 equipment_id INT AUTO_INCREMENT PRIMARY KEY,
 owner_id INT NOT NULL,
 equipment_name VARCHAR(150),
 category VARCHAR(100),
 description TEXT,
 rental_price DECIMAL(10,2),
 available_quantity INT,
 status VARCHAR(50) DEFAULT 'available',
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(owner_id) REFERENCES users(user_id)
);

CREATE TABLE reservations (
 reservation_id INT AUTO_INCREMENT PRIMARY KEY,
 user_id INT,
 equipment_id INT,
 quantity INT,
 start_date DATE,
 end_date DATE,
 total_price DECIMAL(10,2),
 purpose TEXT,
 status VARCHAR(50) DEFAULT 'pending',
  returned_at DATETIME,
  return_condition VARCHAR(50),
 admin_notes TEXT,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(user_id) REFERENCES users(user_id),
 FOREIGN KEY(equipment_id) REFERENCES equipment(equipment_id)
);

CREATE TABLE IF NOT EXISTS notifications (
 notification_id INT AUTO_INCREMENT PRIMARY KEY,
 user_id INT NOT NULL,
 message TEXT,
 is_read BOOLEAN DEFAULT FALSE,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(user_id) REFERENCES users(user_id)
);

CREATE TABLE IF NOT EXISTS payments (
 payment_id INT AUTO_INCREMENT PRIMARY KEY,
 reservation_id INT NOT NULL,
 amount DECIMAL(10,2),
 payment_status VARCHAR(50) DEFAULT 'pending',
 payment_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(reservation_id) REFERENCES reservations(reservation_id)
);

CREATE TABLE IF NOT EXISTS transactions (
 transaction_id INT AUTO_INCREMENT PRIMARY KEY,
 reservation_id INT NOT NULL,
 user_id INT,
 amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
 payment_status VARCHAR(50) DEFAULT 'pending',
 payment_method VARCHAR(50) DEFAULT 'cash',
 payment_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(reservation_id) REFERENCES reservations(reservation_id) ON DELETE CASCADE,
 FOREIGN KEY(user_id) REFERENCES users(user_id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS reports (
 report_id INT AUTO_INCREMENT PRIMARY KEY,
 user_id INT NOT NULL,
 reservation_id INT,
 subject VARCHAR(150),
 issue_type VARCHAR(100),
 description TEXT,
 attachment VARCHAR(255),
 status VARCHAR(50) DEFAULT 'pending',
  returned_at DATETIME,
  return_condition VARCHAR(50),
 admin_notes TEXT,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(user_id) REFERENCES users(user_id),
 FOREIGN KEY(reservation_id) REFERENCES reservations(reservation_id)
);
