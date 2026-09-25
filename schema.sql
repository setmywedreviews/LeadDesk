CREATE TABLE IF NOT EXISTS users(
 id INT AUTO_INCREMENT PRIMARY KEY,name VARCHAR(120) NOT NULL,username VARCHAR(80) UNIQUE NOT NULL,
 password_hash VARCHAR(255) NOT NULL,role ENUM('admin','sales') DEFAULT 'sales',category VARCHAR(80) NOT NULL,
 active TINYINT(1) DEFAULT 1,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS leads(
 id BIGINT AUTO_INCREMENT PRIMARY KEY,business_name VARCHAR(190) NOT NULL,category VARCHAR(80) NOT NULL,
 city VARCHAR(120),phone VARCHAR(40),instagram VARCHAR(255),facebook VARCHAR(255),website VARCHAR(255),
 source VARCHAR(80),source_url VARCHAR(500),lead_score INT DEFAULT 50,
 status ENUM('New','Follow-up','Interested','Not Interested','Converted','Wrong Number','DNC') DEFAULT 'New',
 assigned_to INT NULL,remark TEXT,next_followup DATETIME NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uniq_phone_cat(phone,category),INDEX idx_assigned(assigned_to),INDEX idx_followup(next_followup)
);
CREATE TABLE IF NOT EXISTS activities(
 id BIGINT AUTO_INCREMENT PRIMARY KEY,lead_id BIGINT NOT NULL,user_id INT NULL,type VARCHAR(40),note TEXT,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
