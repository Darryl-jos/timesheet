<?php
require_once 'config.php';

if (!isset($_SESSION['engineer_id']) || !isset($_SESSION['is_admin']) || ($_SESSION['is_admin'] != 1 && $_SESSION['is_admin'] != 2)) {
    header("Location: login.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Admin Workspace Dashboard</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 30px; background: #f4f7f6; color: #333; }
        .header { display: flex; justify-content: space-between; align-items: center; background: #343a40; padding: 15px 20px; border-radius: 8px; color: white; margin-bottom: 20px; flex-wrap: wrap; gap: 10px; }
        .header h2 { margin: 0; font-size: 18px; }
        .header-actions { display: flex; gap: 15px; align-items: center; flex-wrap: wrap; }
        .header-actions a { color: #ffc107; font-weight: bold; text-decoration: none; font-size: 13px; }
        .header-actions a:hover { color: #ffca2c; }
        
        .menu-grid { display: flex; gap: 15px; flex-direction: column; margin-top: 20px; }
        .menu-card { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); text-decoration: none; color: #333; display: flex; flex-direction: column; justify-content: space-between; border-top: 5px solid #007bff; }
        .menu-card.projects { border-top-color: #28a745; }
        .menu-card.timesheets { border-top-color: #ffc107; }
        .menu-title { font-size: 18px; font-weight: bold; margin-bottom: 8px; color: #111; }
        .menu-desc { font-size: 13px; color: #666; line-height: 1.5; margin-bottom: 15px; }
        .menu-btn { background: #007bff; color: white; padding: 12px; border-radius: 4px; text-align: center; font-weight: bold; font-size: 14px; }
        .projects .menu-btn { background: #28a745; }
        .timesheets .menu-btn { background: #ffc107; color: #333; }

        @media (min-width: 768px) {
            .header h2 { font-size: 20px; }
            .menu-grid { flex-direction: row; flex-wrap: wrap; }
            .menu-card { flex: 1; min-width: 280px; padding: 30px; }
            .menu-title { font-size: 20px; }
            .menu-desc { font-size: 14px; margin-bottom: 20px; }
        }
    </style>
</head>
<body>

<div class="header">
    <h2>Admin Management Workspace Dashboard</h2>
    <div class="header-actions">
        <?php if (isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 2): ?>
            <a href="index.php">📋 Timesheet Home</a>
        <?php endif; ?>
        <a href="profile.php">👤 Profile</a>
        <a href="login.php?action=logout">Logout</a>
    </div>
</div>

<div class="menu-grid">
    <a href="admin_engineers.php" class="menu-card engineers">
        <div>
            <div class="menu-title">👷 Engineer Accounts</div>
            <div class="menu-desc">Onboard new team members, manage active rosters, edit profiles, and view or safely terminate user credentials.</div>
        </div>
        <div class="menu-btn">Manage Engineers →</div>
    </a>

    <a href="admin_iips.php" class="menu-card projects">
        <div>
            <div class="menu-title">📂 IIPS List</div>
            <div class="menu-desc">Create and manage IIPS projects — track costing, timeline targets, billing status, and resource allocation linked to timesheet data.</div>
        </div>
        <div class="menu-btn">Manage IIPS List →</div>
    </a>

    <a href="admin_timesheets.php" class="menu-card timesheets">
        <div>
            <div class="menu-title">📊 Timesheet Logs & Audit</div>
            <div class="menu-desc">Review global working hour logs submitted across all departments. Access administrative data and export raw compliance data directly to Microsoft Excel.</div>
        </div>
        <div class="menu-btn">Audit Timesheets →</div>
    </a>
</div>

</body>
</html>