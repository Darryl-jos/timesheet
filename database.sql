SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS iips_tracking;
DROP TABLE IF EXISTS timesheets;
DROP TABLE IF EXISTS engineers;
DROP TABLE IF EXISTS projects;

SET FOREIGN_KEY_CHECKS = 1;

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
    project_name VARCHAR(100) NOT NULL,
    estimate_time INT NOT NULL,
    pricing DECIMAL(10, 2) NULL,
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
    work_description TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (engineer_id) REFERENCES engineers(id) ON DELETE CASCADE,
    FOREIGN KEY (project_id) REFERENCES projects(project_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE iips_tracking (
    id INT AUTO_INCREMENT PRIMARY KEY,

    project_id          VARCHAR(50) NOT NULL,

    selling_price       DECIMAL(12,2) NULL DEFAULT NULL,
    partner_cost        DECIMAL(12,2) NULL DEFAULT NULL,
    gross_profit        DECIMAL(12,2) NULL DEFAULT NULL,
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

    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (project_id) REFERENCES projects(project_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO engineers (username_id, engineer_name, password, is_admin, is_verified) 
VALUES (
  'admin', 
  'Administrator', 
  '$2y$10$ipcXrrb9h6iBpLIrb5iIwuQfB1boJ.ZuiknZWwjpa6ZaWumXHipl6', //12345678
  1, 
  1
);

INSERT INTO engineers (username_id, engineer_name, password, is_admin, is_verified) VALUES 
('basri.bashir', 'Basri Bin Bashir', '$2y$10$dkQ8EFGawJj2jzaI2Q7VvOd8JTBN2jWZEen.hhj0v62PU/VPmQHf.', 0, 1), //password: Password123
('yochai', 'Chai Yew Onn', '$2y$10$dkQ8EFGawJj2jzaI2Q7VvOd8JTBN2jWZEen.hhj0v62PU/VPmQHf.', 0, 1),
('enyee.cheah', 'Cheah En Yee', '$2y$10$dkQ8EFGawJj2jzaI2Q7VvOd8JTBN2jWZEen.hhj0v62PU/VPmQHf.', 0, 1),
('bschoo', 'Choo Boon Sim', '$2y$10$dkQ8EFGawJj2jzaI2Q7VvOd8JTBN2jWZEen.hhj0v62PU/VPmQHf.', 0, 1),
('chrisng', 'Chris Ng Peng Hun', '$2y$10$dkQ8EFGawJj2jzaI2Q7VvOd8JTBN2jWZEen.hhj0v62PU/VPmQHf.', 0, 1),
('desmond.lee', 'Desmond Lee Ming Shien', '$2y$10$dkQ8EFGawJj2jzaI2Q7VvOd8JTBN2jWZEen.hhj0v62PU/VPmQHf.', 0, 1),
('ghussain', 'Gulam Hussain', '$2y$10$dkQ8EFGawJj2jzaI2Q7VvOd8JTBN2jWZEen.hhj0v62PU/VPmQHf.', 0, 1),
('bryant.kong', 'Kong Kah Chun, Bryant', '$2y$10$dkQ8EFGawJj2jzaI2Q7VvOd8JTBN2jWZEen.hhj0v62PU/VPmQHf.', 0, 1),
('kumaraguru.s', 'Kumaraguru A/L Siva Piragasam', '$2y$10$dkQ8EFGawJj2jzaI2Q7VvOd8JTBN2jWZEen.hhj0v62PU/VPmQHf.', 0, 1),
('cheliang.lee', 'Lee Che Liang', '$2y$10$dkQ8EFGawJj2jzaI2Q7VvOd8JTBN2jWZEen.hhj0v62PU/VPmQHf.', 0, 1),
('mxlee', 'Lee Mao Xin, James', '$2y$10$dkQ8EFGawJj2jzaI2Q7VvOd8JTBN2jWZEen.hhj0v62PU/VPmQHf.', 0, 1),
('jiacheng.loh', 'Loh Jia Cheng (JC)', '$2y$10$dkQ8EFGawJj2jzaI2Q7VvOd8JTBN2jWZEen.hhj0v62PU/VPmQHf.', 0, 1),
('lohitt.p', 'Lohitt A/L Paramisvaran', '$2y$10$dkQ8EFGawJj2jzaI2Q7VvOd8JTBN2jWZEen.hhj0v62PU/VPmQHf.', 0, 1),
('fadhli', 'Mohamad Fadhli Bin Makroof', '$2y$10$dkQ8EFGawJj2jzaI2Q7VvOd8JTBN2jWZEen.hhj0v62PU/VPmQHf.', 0, 1),
('m.azran', 'Mohamed Azran Bin Mohamed Jamiathulla', '$2y$10$dkQ8EFGawJj2jzaI2Q7VvOd8JTBN2jWZEen.hhj0v62PU/VPmQHf.', 0, 1),
('mohdadam', 'Mohd Adam bin Abdullah', '$2y$10$dkQ8EFGawJj2jzaI2Q7VvOd8JTBN2jWZEen.hhj0v62PU/VPmQHf.', 0, 1),
('huzaifah.zianal', 'Mohd Huzaifah Bin Zianal', '$2y$10$dkQ8EFGawJj2jzaI2Q7VvOd8JTBN2jWZEen.hhj0v62PU/VPmQHf.', 0, 1),
('nadzri', 'Muhammad Nadzri Bin Md Shukri', '$2y$10$dkQ8EFGawJj2jzaI2Q7VvOd8JTBN2jWZEen.hhj0v62PU/VPmQHf.', 0, 1),
('ng.jason', 'Ng Jason', '$2y$10$dkQ8EFGawJj2jzaI2Q7VvOd8JTBN2jWZEen.hhj0v62PU/VPmQHf.', 0, 1),
('cheewei.seah', 'Seah Che Wei', '$2y$10$dkQ8EFGawJj2jzaI2Q7VvOd8JTBN2jWZEen.hhj0v62PU/VPmQHf.', 0, 1),
('taufiq.rahiman', 'Taufiq Ali Bin Pazur Rahiman', '$2y$10$dkQ8EFGawJj2jzaI2Q7VvOd8JTBN2jWZEen.hhj0v62PU/VPmQHf.', 0, 1),
('vincent.choo', 'Vincent Choo Boon Kiat', '$2y$10$dkQ8EFGawJj2jzaI2Q7VvOd8JTBN2jWZEen.hhj0v62PU/VPmQHf.', 0, 1),
('klyap', 'Yap Kian Lip, Kenny', '$2y$10$dkQ8EFGawJj2jzaI2Q7VvOd8JTBN2jWZEen.hhj0v62PU/VPmQHf.', 0, 1),
('ckyo', 'Yo Choon Kit', '$2y$10$dkQ8EFGawJj2jzaI2Q7VvOd8JTBN2jWZEen.hhj0v62PU/VPmQHf.', 0, 1);

INSERT INTO projects (project_id, customer_name, project_name, estimate_time, pricing) VALUES 
('SO-0015417', 'Tec D Distribution (Malaysia) Sdn Bhd', 'AG Backup Tech Refresh', 0, NULL),
('SO-0013072', 'Starhub Ltd', 'Huawei Centralised Platform - NCE Campus Implementation', 0, NULL),
('SO-0016317', 'TECH-STORE Malaysia Sdn. Bhd.', 'Network Office Setup', 0, NULL),
('SO-0015924', 'MSIG Insurance (Malaysia) Bhd', 'Nutanix HCI Tech Refresh (G6 EOL) - 2 Nodes', 0, NULL),
('SO-0016639', 'Asahi Holding Southeast Asia', 'Microsoft Tenant Configuration', 0, NULL),
('SO-0016860', 'QL Corporate Services Sdn Bhd', 'Trend Micro Vision One Endpoint Deployment', 0, NULL),
('SO-0017087', 'Tec D Distribution (Malaysia) Sdn Bhd', 'NIISE Backup Project', 0, NULL),
('SO-0014162', 'Bank Negara Malaysia', 'Cisco Network & FortiGate Firewall Tech Refresh', 0, NULL),
('SO-0014850', 'Pantai Medical Centre Sdn Bhd (Gleneagles Hospital Johor)', 'Hyper-V Implementation', 0, NULL),
('SO-0015309', 'SATO MALAYSIA ELECTRONICS MANUFACTURING SDN. BHD.', 'HPE SimpliVity Tech Refresh', 0, NULL),
('SO-0015486', 'Kenanga Investment Bank Berhad', 'SAN Switch Tech Refresh', 0, NULL),
('SO-0016717', 'TOUCH\'N GO SDN. BHD.', 'VNX5700 Disk Replacement', 0, NULL),
('SO-0016767', 'MNRB Holdings', 'Veeam Standby DR Server Implementation', 0, NULL),
('SO-0017234', 'TECH-STORE Malaysia Sdn. Bhd.', 'RTS Huawei Project', 0, NULL),
('SO-0015558', 'WIN MODERN DESIGN SDN. BHD.', 'Fourpoint 300 APs', 0, NULL),
('SO-0015560', 'WIN MODERN DESIGN SDN. BHD.', 'Aruba Switch and AP installation', 0, NULL),
('SO-0017227', 'TNG Digital Sdn Bhd', 'TNGD New Office Set up for 2 new floors - Tower 8, Level 3 & 7', 0, NULL),
('SO-0016803', 'SD Guthrie International Carey Island KCP Sdn Bhd', 'Huawei WIFI AP Implementation', 0, NULL),
('SO-0017199', 'BURSA MALAYSIA BERHAD', 'VMWARE SERVERS HARDWARE REFRESHMENT', 0, NULL),
('SO-0018384', 'UNITED OVERSEAS BANK (MALAYSIA) BHD (UOB)', 'Citrix XenApp and XenDesktop Implementation', 0, NULL),
('SO-0018211', 'FWD Technology and Innovation Malaysia Sdn. Bhd.', 'Aruba Network POE Switch Implementation', 0, NULL),
('SO-0016040', 'Favelle Favco Cranes Pty Ltd', 'Sangfor Implementation Australia', 0, NULL),
('SO-0017303', 'Aida Manufacturing (Asia) Sdn. Bhd', 'System Infra Deployment', 0, NULL),
('SO-0014078', 'MBSB BANK BERHAD', 'Huawei Network Tech Refresh', 0, NULL),
('SO-0017016', 'Asahi Holding Southeast Asia', 'Network Infra Implementation Phase 1', 0, NULL),
('SO-0018405', 'RHB Bank Berhad', 'Commvault Implementation', 0, NULL),
('N/A_1', 'TNG Digital Sdn Bhd', 'Aruba Clearpass Deployment Phase 1', 0, NULL),
('SO-0018238', 'FGV IFFCO SDN BHD', 'AP Installation', 0, NULL),
('SO-0016832', 'THC Sdn Bhd', 'Compute & Storage Expansion for VMware Infra Opt2', 0, NULL),
('N/A_2', 'Kalsec Asia Pacific Pte Ltd', 'Cisco Meraki MX65W-HW Replacement', 0, NULL),
('N/A_3', 'Marubun Arrow (M) Sdn Bhd', 'Network & Firewall Installation', 0, NULL),
('N/A_4', 'NIC COMPONENTS ASIA PTE LTD', 'Firewall Installation', 0, NULL),
('SO-0019687', 'QL PKT Kuantan', 'UPS and Switch Installation', 0, NULL),
('SO-0018381', 'PAPAMY', 'Cisco Switch Installation', 0, NULL),
('N/A_5', 'TecD', 'MCMC', 0, NULL),
('SO-0016819', 'Syarikat Takaful Malaysia Keluarga Berhad', 'Commvault & Tape Library Deployment', 0, NULL),
('SO-0018840', 'Syarikat Takaful Malaysia', 'Commvault M365 and AD Deployment', 0, NULL),
('SO-0017958', 'Tech Store Malaysia', 'DELL Server and OS Installation', 0, NULL),
('SO-0018966', 'APM Corporate Services Sdn Bhd', 'MSSQL Server Performance Tuning (Remediation & Optimization)', 0, NULL),
('N/A_6', 'Comet Technologies Malaysia Sdn Bhd', 'Rackmount Switch and Cabling Works', 0, NULL),
('SO-0019171', 'SEM Matic Sdn. Bhd.', 'OS Reinstallation & Data Restoration (Post-Ransomware Recovery)', 0, NULL),
('N/A_7', 'Islamic Arts Museum Malaysia', 'FortiGate Firewall Refresh', 0, NULL),
('N/A_8', 'NS BLUESCOPE MALAYSIA SDN BHD', 'HPE Server Installation', 0, NULL),
('N/A_9', 'Leviat Sdn Bhd', 'Hyper-V Server Tech Refresh', 0, NULL),
('N/A_10', 'MUFG', 'vCenter Upgrade for VxRail', 0, NULL),
('SO-0017582', 'Rumal Reading Sdn Bhd', 'Juniper Switch Implementation', 0, NULL),
('SO-0014552', 'Starhub Ltd', 'MSI RTS Project', 0, NULL),
('N/A_11', 'Bank Negara Malaysia', 'PAM Tech Refresh Project', 0, NULL),
('SO-0016643', 'Mitsubishi', 'FortiGate Firewall Migration - Segmentation', 0, NULL),
('SO-0014608', 'Insulet Malaysia Sdn. Bhd.', 'SQL Server Installation', 0, NULL),
('N/A_12', 'Panasonic Appliances Air-Conditioning Malaysia Sdn. Bhd.', 'Onsite Support for HPE Primera 600', 0, NULL);