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
    <title>Admin Home</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, sans-serif; margin: 30px; background: #f4f7f6; color: #333; padding-bottom: 20px; }
        
        .topbar { position: sticky; top: 0; z-index: 500; background: #343a40; padding: 15px 20px; display: flex; border-radius: 8px; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .topbar h2 { color: white; margin: 0; font-size: 18px; display: flex; align-items: center; gap: 8px; }
        .topbar .nav { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
        
        .topbar a { color: #ffc107; text-decoration: none; font-size: 13px; padding: 6px 12px; border-radius: 4px; font-weight: bold; transition: background 0.2s, color 0.2s; }
        .topbar a:hover { background: rgba(255, 193, 7, 0.15); color: #ffda6a; }
        
        .topbar a.logout-btn { color: #ef4444; }
        .topbar a.logout-btn:hover { background: rgba(239, 68, 68, 0.15); color: #f87171; }

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
            .menu-grid { flex-direction: row; flex-wrap: wrap; }
            .menu-card { flex: 1; min-width: 280px; padding: 30px; }
            .menu-title { font-size: 20px; }
            .menu-desc { font-size: 14px; margin-bottom: 20px; }
        }
    </style>
</head>
<body>

<div class="topbar">
    <h2>⚙️ Admin Home</h2>
    <div class="nav">
        <?php if (isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 2): ?>
            <a href="index.php">📋 Switch to Timesheet</a>
        <?php endif; ?>
        <a href="profile.php?from=admin">👤 Profile</a>
        <a href="login.php?action=logout" class="logout-btn">Logout</a>
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