DROP DATABASE IF EXISTS timesheet;
CREATE DATABASE timesheet CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE timesheet;

CREATE TABLE engineers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username_id VARCHAR(100) NOT NULL UNIQUE,
    engineer_name VARCHAR(100) NOT NULL,
    password VARCHAR(255) NOT NULL,
    is_admin TINYINT(1) DEFAULT 0,
    is_verified TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE projects (
    project_id VARCHAR(50) PRIMARY KEY,
    customer_name VARCHAR(100) NOT NULL,
    project_name VARCHAR(200) NOT NULL,
    estimate_time INT NOT NULL,
    pricing DECIMAL(10,2) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE timesheets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    engineer_id INT NOT NULL,
    engineer_name VARCHAR(100) NOT NULL,
    project_id VARCHAR(50) NOT NULL,
    start_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_date DATE NOT NULL,
    end_time TIME NOT NULL,
    meal_breaks INT DEFAULT 0,
    work_description TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (engineer_id) REFERENCES engineers(id) ON DELETE CASCADE,
    FOREIGN KEY (project_id) REFERENCES projects(project_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE iips_tracking (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id          VARCHAR(50) NOT NULL UNIQUE,
    selling_price       DECIMAL(12,2) NULL DEFAULT NULL,
    partner_cost        DECIMAL(12,2) NULL DEFAULT NULL,
    internal_cost       DECIMAL(12,2) NULL DEFAULT NULL,
    gross_profit        DECIMAL(12,2) NULL DEFAULT NULL,
    accrued             DECIMAL(12,2) NULL DEFAULT NULL,
    remarks_status      TEXT NULL DEFAULT NULL,
    has_project_mgmt    TINYINT(1) NOT NULL DEFAULT 0,
    target_mandays      DECIMAL(8,2) NULL DEFAULT NULL,
    target_start_date   DATE NULL DEFAULT NULL,
    target_end_date     DATE NULL DEFAULT NULL,
    target_billing_date DATE NULL DEFAULT NULL,
    actual_start_date   DATE NULL DEFAULT NULL,
    actual_end_date     DATE NULL DEFAULT NULL,
    iips_status         ENUM('Not Quoted','Quoted','Not Started','In Progress','Completed','Cancelled') NOT NULL DEFAULT 'Not Quoted',
    billing_status      ENUM('Not Forecasted','Forecasted','Pending','Completed') NOT NULL DEFAULT 'Not Forecasted',
    account_manager     VARCHAR(150) NULL DEFAULT NULL,
    account_leader      VARCHAR(150) NULL DEFAULT NULL,
    presales_sdm        VARCHAR(150) NULL DEFAULT NULL,
    project_manager     VARCHAR(150) NULL DEFAULT NULL,
    partner             VARCHAR(150) NULL DEFAULT NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(project_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO engineers (username_id, engineer_name, password, is_admin, is_verified) VALUES
('admin', 'Administrator', '$2y$10$ipcXrrb9h6iBpLIrb5iIwuQfB1boJ.ZuiknZWwjpa6ZaWumXHipl6', 1, 1),
('basri.bashir','Basri Bin Bashir','$2y$10$dkQ8EFGawJj2jzaI2Q7VvOd8JTBN2jWZEen.hhj0v62PU/VPmQHf.',0,1),
('yochai','Chai Yew Onn','$2y$10$dkQ8EFGawJj2jzaI2Q7VvOd8JTBN2jWZEen.hhj0v62PU/VPmQHf.',0,1),
('enyee.cheah','Cheah En Yee','$2y$10$dkQ8EFGawJj2jzaI2Q7VvOd8JTBN2jWZEen.hhj0v62PU/VPmQHf.',0,1),
('bschoo','Choo Boon Sim','$2y$10$dkQ8EFGawJj2jzaI2Q7VvOd8JTBN2jWZEen.hhj0v62PU/VPmQHf.',0,1),
('chrisng','Chris Ng Peng Hun','$2y$10$dkQ8EFGawJj2jzaI2Q7VvOd8JTBN2jWZEen.hhj0v62PU/VPmQHf.',0,1),
('desmond.lee','Desmond Lee Ming Shien','$2y$10$dkQ8EFGawJj2jzaI2Q7VvOd8JTBN2jWZEen.hhj0v62PU/VPmQHf.',0,1),
('ghussain','Gulam Hussain','$2y$10$dkQ8EFGawJj2jzaI2Q7VvOd8JTBN2jWZEen.hhj0v62PU/VPmQHf.',0,1),
('bryant.kong','Kong Kah Chun, Bryant','$2y$10$dkQ8EFGawJj2jzaI2Q7VvOd8JTBN2jWZEen.hhj0v62PU/VPmQHf.',0,1),
('kumaraguru.s','Kumaraguru A/L Siva Piragasam','$2y$10$dkQ8EFGawJj2jzaI2Q7VvOd8JTBN2jWZEen.hhj0v62PU/VPmQHf.',0,1),
('cheliang.lee','Lee Che Liang','$2y$10$dkQ8EFGawJj2jzaI2Q7VvOd8JTBN2jWZEen.hhj0v62PU/VPmQHf.',0,1),
('mxlee','Lee Mao Xin, James','$2y$10$dkQ8EFGawJj2jzaI2Q7VvOd8JTBN2jWZEen.hhj0v62PU/VPmQHf.',0,1),
('jiacheng.loh','Loh Jia Cheng (JC)','$2y$10$dkQ8EFGawJj2jzaI2Q7VvOd8JTBN2jWZEen.hhj0v62PU/VPmQHf.',0,1),
('lohitt.p','Lohitt A/L Paramisvaran','$2y$10$dkQ8EFGawJj2jzaI2Q7VvOd8JTBN2jWZEen.hhj0v62PU/VPmQHf.',0,1),
('fadhli','Mohamad Fadhli Bin Makroof','$2y$10$dkQ8EFGawJj2jzaI2Q7VvOd8JTBN2jWZEen.hhj0v62PU/VPmQHf.',0,1),
('m.azran','Mohamed Azran Bin Mohamed Jamiathulla','$2y$10$dkQ8EFGawJj2jzaI2Q7VvOd8JTBN2jWZEen.hhj0v62PU/VPmQHf.',0,1),
('mohdadam','Mohd Adam bin Abdullah','$2y$10$dkQ8EFGawJj2jzaI2Q7VvOd8JTBN2jWZEen.hhj0v62PU/VPmQHf.',0,1),
('huzaifah.zianal','Mohd Huzaifah Bin Zianal','$2y$10$dkQ8EFGawJj2jzaI2Q7VvOd8JTBN2jWZEen.hhj0v62PU/VPmQHf.',0,1),
('nadzri','Muhammad Nadzri Bin Md Shukri','$2y$10$dkQ8EFGawJj2jzaI2Q7VvOd8JTBN2jWZEen.hhj0v62PU/VPmQHf.',0,1),
('ng.jason','Ng Jason','$2y$10$dkQ8EFGawJj2jzaI2Q7VvOd8JTBN2jWZEen.hhj0v62PU/VPmQHf.',0,1),
('cheewei.seah','Seah Che Wei','$2y$10$dkQ8EFGawJj2jzaI2Q7VvOd8JTBN2jWZEen.hhj0v62PU/VPmQHf.',0,1),
('taufiq.rahiman','Taufiq Ali Bin Pazur Rahiman','$2y$10$dkQ8EFGawJj2jzaI2Q7VvOd8JTBN2jWZEen.hhj0v62PU/VPmQHf.',0,1),
('vincent.choo','Vincent Choo Boon Kiat','$2y$10$dkQ8EFGawJj2jzaI2Q7VvOd8JTBN2jWZEen.hhj0v62PU/VPmQHf.',0,1),
('klyap','Yap Kian Lip, Kenny','$2y$10$dkQ8EFGawJj2jzaI2Q7VvOd8JTBN2jWZEen.hhj0v62PU/VPmQHf.',0,1),
('ckyo','Yo Choon Kit','$2y$10$dkQ8EFGawJj2jzaI2Q7VvOd8JTBN2jWZEen.hhj0v62PU/VPmQHf.',0,1);