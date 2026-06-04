<?php
require_once 'config.php';

if (!isset($_SESSION['engineer_id']) || !isset($_SESSION['is_admin']) || ($_SESSION['is_admin'] != 1 && $_SESSION['is_admin'] != 2)) {
    header("Location: login.php");
    exit;
}

$current_admin_role = intval($_SESSION['is_admin']);
$current_admin_id = intval($_SESSION['engineer_id']);

// ✅ Reset Password to P@ssw0rd
if (isset($_GET['reset_pwd'])) {
    $reset_id = intval($_GET['reset_pwd']);
    $default_password = password_hash('P@ssw0rd', PASSWORD_DEFAULT);
    $stmt = $conn->prepare("UPDATE engineers SET password = ?, is_verified = 1 WHERE id = ? AND is_admin != 1");
    $stmt->bind_param("si", $default_password, $reset_id);
    $stmt->execute();
    $stmt->close();
    header("Location: admin_engineers.php");
    exit;
}

if (isset($_GET['delete_eng'])) {
    $del_eng_id = intval($_GET['delete_eng']);
    
    $target_stmt = $conn->prepare("SELECT is_admin FROM engineers WHERE id = ?");
    $target_stmt->bind_param("i", $del_eng_id);
    $target_stmt->execute();
    $target_res = $target_stmt->get_result()->fetch_assoc();
    $target_stmt->close();
    
    if ($target_res) {
        $target_role = intval($target_res['is_admin']);
        
        if ($target_role == 1) {
            header("Location: admin_engineers.php");
            exit;
        } elseif ($target_role == 2) {
            if ($current_admin_role == 1) {
                $stmt = $conn->prepare("DELETE FROM engineers WHERE id = ?");
                $stmt->bind_param("i", $del_eng_id);
                $stmt->execute();
                $stmt->close();
            }
        } else {
            $stmt = $conn->prepare("DELETE FROM engineers WHERE id = ?");
            $stmt->bind_param("i", $del_eng_id);
            $stmt->execute();
            $stmt->close();
        }
    }
    header("Location: admin_engineers.php");
    exit;
}

$edit_eng_data = null;
if (isset($_GET['edit_eng'])) {
    $edit_eng_id = intval($_GET['edit_eng']);
    
    if ($current_admin_role == 1) {
        $stmt = $conn->prepare("SELECT * FROM engineers WHERE id = ? AND is_admin != 1");
    } else {
        $stmt = $conn->prepare("SELECT * FROM engineers WHERE id = ? AND is_admin = 0");
    }
    $stmt->bind_param("i", $edit_eng_id);
    $stmt->execute();
    $edit_eng_data = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$eng_error = "";
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_engineer'])) {
    $username_id = strtolower(trim($_POST['username_id']));
    $name = trim($_POST['eng_name']);
    $target_eng_id = isset($_POST['target_eng_id']) ? intval($_POST['target_eng_id']) : 0;
    $assign_as_admin = ($current_admin_role == 1 && isset($_POST['grant_admin_role'])) ? 2 : 0;
    
    if (empty($username_id)) {
        $eng_error = "Please enter a valid Username ID!";
    } elseif (empty($name)) {
        $eng_error = "Engineer name cannot be empty!";
    } else {
        if ($target_eng_id > 0) {
            $chk_stmt = $conn->prepare("SELECT is_admin FROM engineers WHERE id = ?");
            $chk_stmt->bind_param("i", $target_eng_id);
            $chk_stmt->execute();
            $curr_target = $chk_stmt->get_result()->fetch_assoc();
            $chk_stmt->close();
            
            if ($curr_target && intval($curr_target['is_admin']) != 1) {
                if ($current_admin_role == 2 && intval($curr_target['is_admin']) != 0) {
                    header("Location: admin_engineers.php");
                    exit;
                }

                $check_stmt = $conn->prepare("SELECT id FROM engineers WHERE username_id = ? AND id != ?");
                $check_stmt->bind_param("si", $username_id, $target_eng_id);
                $check_stmt->execute();
                if ($check_stmt->get_result()->num_rows > 0) {
                    $eng_error = "The Username ID already exists. Update aborted!";
                    $check_stmt->close();
                } else {
                    $check_stmt->close();

                    if ($current_admin_role == 1) {
                        $stmt = $conn->prepare("UPDATE engineers   SET username_id = ?, engineer_name = ?, is_admin = ? WHERE id = ?");
                        $stmt->bind_param("ssii", $username_id, $name, $assign_as_admin, $target_eng_id);
                    } else {
                        $stmt = $conn->prepare("UPDATE engineers SET username_id = ?, engineer_name = ? WHERE id = ? AND is_admin = 0");
                        $stmt->bind_param("ssi", $username_id, $name, $target_eng_id);
                    }
                    $stmt->execute();
                    $stmt->close();
                    header("Location: admin_engineers.php");
                    exit;
                }
            }
        } else {
            $check_stmt = $conn->prepare("SELECT id FROM engineers WHERE username_id = ?");
            $check_stmt->bind_param("s", $username_id);
            $check_stmt->execute();
            if ($check_stmt->get_result()->num_rows > 0) {
                $eng_error = "This Username ID is already in the roster!";
                $check_stmt->close();
            } else {
                $check_stmt->close();
                // ✅ Default password is P@ssw0rd
$default_password = password_hash('P@ssw0rd', PASSWORD_DEFAULT);
$stmt = $conn->prepare("INSERT INTO engineers (username_id, engineer_name, password, is_admin, is_verified) VALUES (?, ?, ?, ?, 1)");
$stmt->bind_param("sssi", $username_id, $name, $default_password, $assign_as_admin);
                $stmt->execute();
                $stmt->close();
                header("Location: admin_engineers.php");
                exit;
            }
        }
    }
}

if ($current_admin_role == 1) {
    $eng_result = $conn->query("SELECT id, username_id, engineer_name, password, is_admin, created_at FROM engineers WHERE id != $current_admin_id ORDER BY is_admin DESC, id ASC");
} else {
    $eng_result = $conn->query("SELECT id, username_id, engineer_name, password, is_admin, created_at FROM engineers WHERE is_admin = 0 ORDER BY id ASC");
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['bulk_action'])) {
    if (!isset($_POST['selected_engs']) || !is_array($_POST['selected_engs'])) {
        $eng_error = "Please select at least one engineer!";
    } else {
        $selected_ids = array_map('intval', $_POST['selected_engs']);
        $action = $_POST['bulk_action'];

        if ($action === 'delete') {
            foreach ($selected_ids as $id) {
                $target_stmt = $conn->prepare("SELECT is_admin FROM engineers WHERE id = ?");
                $target_stmt->bind_param("i", $id);
                $target_stmt->execute();
                $target_res = $target_stmt->get_result()->fetch_assoc();
                $target_stmt->close();

                if ($target_res) {
                    $target_role = intval($target_res['is_admin']);
                    if ($target_role != 1) { // 不是 Admin 1 才可以继续
                        if ($target_role == 0 || ($target_role == 2 && $current_admin_role == 1)) {
                            $stmt = $conn->prepare("DELETE FROM engineers WHERE id = ?");
                            $stmt->bind_param("i", $id);
                            $stmt->execute();
                            $stmt->close();
                        }
                    }
                }
            }
            header("Location: admin_engineers.php");
            exit;

        } elseif ($action === 'grant_admin' && $current_admin_role == 1) {
            $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
            $types = str_repeat('i', count($selected_ids));

            $stmt = $conn->prepare("UPDATE engineers SET is_admin = 2 WHERE id IN ($placeholders) AND is_admin = 0");
            $stmt->bind_param($types, ...$selected_ids);
            $stmt->execute();
            $stmt->close();
            header("Location: admin_engineers.php");
            exit;
            
        } elseif ($action === 'revoke_admin' && $current_admin_role == 1) {
            $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
            $types = str_repeat('i', count($selected_ids));
            
            $stmt = $conn->prepare("UPDATE engineers SET is_admin = 0 WHERE id IN ($placeholders) AND is_admin = 2");
            $stmt->bind_param($types, ...$selected_ids);
            $stmt->execute();
            $stmt->close();
            header("Location: admin_engineers.php");
            exit;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Manage Engineers</title>
    <style>
        .is-hidden { display: none !important; }

        .search-filter-bar {
            display: flex;
            gap: 8px;
            margin-bottom: 15px;
            width: 100%;
        }

        .mobile-search-input {
            flex: 2;
            height: 36px;
            padding: 0 10px;
            font-size: 13px;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box;
        }

        .mobile-sort-select {
            display: none !important;
        }

        body { font-family: Arial, sans-serif; margin: 30px; background: #f4f7f6; color: #333; }
        .header { display: flex; justify-content: space-between; align-items: center; background: #343a40; padding: 15px 20px; border-radius: 8px; color: white; }
        .card { background: white; padding: 25px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); margin-top: 20px; }
        .form-inline { display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap; margin-top: 15px; }
        .form-inline div { flex: 1; min-width: 150px; }
        input { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        label { display: block; margin-bottom: 5px; font-weight: bold; font-size: 13px; }
        button { background: #007bff; color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer; font-size: 14px; font-weight: bold; height: 40px; }

        table { width: 100%; border-collapse: collapse; background: white; margin-top: 15px; }
        th, td { padding: 12px; border-bottom: 1px solid #ddd; text-align: left; font-size: 14px; }
        th { background: #f8f9fa; font-weight: bold; }
        .btn-del { background: #dc3545; color: white; padding: 4px 8px; text-decoration: none; border-radius: 3px; font-size: 12px; }
        .btn-edit { background: #ffc107; color: #333; padding: 4px 8px; text-decoration: none; border-radius: 3px; font-size: 12px; margin-right: 5px; }

        .status-badge { padding: 3px 8px; border-radius: 12px; font-size: 12px; font-weight: bold; display: inline-block; }
        .status-active { background: #d4edda; color: #155724; }
        .status-pending { background: #fff3cd; color: #856404; }
        .checkbox-container { display: flex; align-items: center; gap: 8px; height: 40px; padding-bottom: 2px; }
        .checkbox-container input { width: 18px; height: 18px; cursor: pointer; }
        .status-admin { background: #e6f0ff; color: #004085; border: 1px solid #b8daff; }
        .sort-dropdown-container { display: inline-flex; align-items: center; position: relative; gap: 6px; }
        .sort-dropbtn { background: none; border: none; position: relative; width: 14px; height: 14px; cursor: pointer; padding: 0; display: inline-block; }
        .sort-dropbtn::before { content: ""; position: absolute; top: 1px; left: 2px; border-left: 5px solid transparent; border-right: 5px solid transparent; border-bottom: 5px solid #444; }
        .sort-dropbtn::after { content: ""; position: absolute; bottom: 1px; left: 2px; border-left: 5px solid transparent; border-right: 5px solid transparent; border-top: 5px solid #444; }
        .sort-dropbtn:hover::before { border-bottom-color: #007bff; }
        .sort-dropbtn:hover::after { border-top-color: #007bff; }
        .sort-dropdown-content { display: none; position: absolute; top: 24px; left: 0; background-color: #ffffff; min-width: 145px; box-shadow: 0px 8px 16px 0px rgba(0,0,0,0.12); border: 1px solid #e2e8f0; border-radius: 4px; z-index: 99; }
        .sort-dropdown-content a { color: #333333 !important; padding: 8px 14px; text-decoration: none !important; display: block; font-size: 13px; font-weight: normal; text-align: left; line-height: 1.4; }
        .sort-dropdown-content a:hover { background-color: #f8fafc; color: #007bff !important; }
        .show-sort-menu { display: block !important; }
        #select-all-toolbar { display: none; }
        #select-all-toolbar[style*="display: flex"],
        #select-all-toolbar[style*="display:flex"] { display: flex !important; width: 100% !important; box-sizing: border-box !important; }
        .card { overflow-x: auto !important; -webkit-overflow-scrolling: touch !important; }

        @media screen and (max-width: 768px) {
            .search-filter-bar {
                gap: 4px;
                margin-bottom: 6px;
            }
            .mobile-search-input {
                height: 24px;
                padding: 0 4px;
                font-size: 10px;
                border-radius: 2px;
            }
            .mobile-sort-select {
                display: block !important;
                flex: 1;
                height: 24px;
                padding: 0 2px;
                font-size: 10px;
                border: 1px solid #ccc;
                border-radius: 2px;
                background: #fff;
                box-sizing: border-box;
            }
            
            body { margin: 4px; font-size: 10px; }
            
            .header { flex-direction: column; gap: 2px; text-align: center; padding: 6px; border-radius: 4px; }
            .header h2 { font-size: 12px; margin: 0; }
            .header a { font-size: 10px; }
            .card { padding: 6px; margin-top: 4px; border-radius: 4px; }
            .card h3 { font-size: 11px; margin: 2px 0 6px 0; }

            .form-inline { flex-direction: column; align-items: stretch !important; gap: 4px !important; padding: 6px !important; margin-bottom: 8px !important; }
            .form-inline div { width: 100% !important; min-width: 100% !important; }
            label { font-size: 10px; margin-bottom: 1px; }
            .form-inline input { padding: 4px; height: 24px; font-size: 10px; border-radius: 2px; }
            .form-inline span { height: 24px !important; padding: 4px !important; font-size: 10px; border-top-right-radius: 2px; border-bottom-right-radius: 2px; }
            button[name="add_engineer"], button[name="save_project"], .form-inline a { width: 100% !important; height: 26px !important; line-height: 26px !important; padding: 0 !important; font-size: 11px !important; }

            #select-all-toolbar { flex-direction: column; gap: 4px; text-align: center; padding: 6px; margin-bottom: 8px; border-radius: 3px; }
            #select-all-toolbar span { font-size: 10px !important; }
            #select-all-toolbar div { flex-wrap: wrap; justify-content: center; width: 100%; gap: 2px; }
            #select-all-toolbar button { flex: 1; min-width: 70px; padding: 1px 4px; height: 22px; font-size: 9px; }

            table, thead, tbody, th, tr { display: block !important; width: 100% !important; min-width: 100% !important; }
            thead tr { position: absolute; top: -9999px; left: -9999px; }     
            
            tr { 
                background: #fff; 
                border: 1px solid #e2e8f0; 
                border-radius: 4px; 
                margin-bottom: 6px; 
                padding: 0; 
                box-shadow: 0 1px 2px rgba(0,0,0,0.02);
                display: block !important;
                max-height: none !important; 
                overflow: visible !important; 
                overflow-y: visible !important;
            }

            td:first-child { 
                display: block !important;
                width: 100% !important;
                background: #f8fafc;
                border-bottom: 1px solid #edf2f7;
                padding: 3px 6px !important; 
                text-align: left !important; 
                position: static !important;
            }
            td:first-child::before { content: ""; font-weight: bold; color: #4a5568; font-size: 10px; }
            td:first-child input[type="checkbox"] { width: 12px !important; height: 12px !important; margin: 0; vertical-align: middle; }

            td { 
                display: block !important;
                width: 100% !important;
                padding: 3px 6px !important; 
                text-align: right !important; 
                white-space: normal !important; 
                box-sizing: border-box;
                font-size: 10px !important; 
                line-height: 1.2;
            }
            
            td::before { content: attr(data-label); float: left; font-weight: bold; color: #718096; font-size: 10px; }
            td strong, td code { font-size: 10px !important; }
            
            .status-badge { padding: 1px 4px; font-size: 9px; border-radius: 8px; }
            
            td div { max-width: 100% !important; margin-left: auto; text-align: left; font-size: 10px !important; padding: 1px 4px !important; }

            td:last-child { 
                display: flex !important;
                justify-content: flex-end;
                gap: 4px;
                border-top: 1px solid #edf2f7; 
                background: #f8fafc; 
                padding: 3px 6px !important; 
                margin-top: 0 !important;
                position: static !important;
            }
            .btn-edit, .btn-del { padding: 1px 4px; font-size: 9px; border-radius: 2px; margin: 0; line-height: 1.2; }
            
            td:not(:first-child):not(:last-child) { border-bottom: 1px dashed #f1f5f9; }
            #bulk-action-form table tbody tr { max-height: none !important; overflow: visible !important; }
        }

        #select-all-toolbar {
            position: -webkit-sticky !important;
            position: sticky !important;
            top: 10px !important;
            z-index: 9999 !important;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08) !important;
            box-sizing: border-box !important;
            width: 100% !important;
            margin-top: 10px !important;
            margin-bottom: 0 !important;
            background: #e6f0ff !important;
            border: 1px solid #b8daff !important;
            padding: 12px 15px !important;
            border-radius: 6px !important;
        }

        @media screen and (max-width: 768px) {
            #select-all-toolbar {
                top: 4px !important;
                padding: 6px !important;
                margin-top: 4px !important;
                border-radius: 3px !important;
            }
        }
    </style>
</head>
<body>

<div class="header">
    <h2>Registered Team Engineers Account Management</h2>
    <a href="admin.php" style="color: #ffc107; font-weight: bold; text-decoration: none;">← Back to Main Menu</a>
</div>

<div class="card">
    <h3><?php echo $edit_eng_data ? 'Edit Team Engineer Details' : 'Onboard & Register New Engineers'; ?></h3>
    <form method="POST" class="form-inline">
        <input type="hidden" name="target_eng_id" value="<?php echo $edit_eng_data ? $edit_eng_data['id'] : '0'; ?>">
        
        <div style="flex: 2; min-width: 200px;">
            <label>Engineer Username ID:</label>
            <div style="display: flex; align-items: center;">
                <input type="text" name="username_id" required placeholder="e.g., xx.xx" value="<?php echo $edit_eng_data ? htmlspecialchars($edit_eng_data['username_id']) : ''; ?>" style="border-top-right-radius: 0; border-bottom-right-radius: 0;">
                <span style="background: #e9ecef; border: 1px solid #ccc; border-left: none; padding: 10px; border-top-right-radius: 4px; border-bottom-right-radius: 4px; color: #495057; font-size: 14px; white-space: nowrap; height: 38px; box-sizing: border-box; display: flex; align-items: center;">@jos.com.my</span>
            </div>
        </div>
        <div style="flex: 2; min-width: 200px;">
            <label>Engineer Full Name (Real Name):</label>
            <input type="text" name="eng_name" required placeholder="e.g., Xx Xx Xx" value="<?php echo $edit_eng_data ? htmlspecialchars($edit_eng_data['engineer_name']) : ''; ?>">
        </div>
        <?php if ($current_admin_role == 1): ?>
            <div style="flex: 1; min-width: 160px; display: flex; align-items: center; gap: 8px; height: 40px; box-sizing: border-box;">
                <input type="checkbox" id="grant_admin_role" name="grant_admin_role" value="2" <?php echo ($edit_eng_data && intval($edit_eng_data['is_admin']) == 2) ? 'checked' : ''; ?> style="width: 18px; height: 18px; margin: 0; cursor: pointer;">
                <label for="grant_admin_role" style="display: inline; margin: 0; cursor: pointer; font-size: 14px; color: #004085; font-weight: bold; white-space: nowrap;">Grant Admin Access</label>
            </div>
        <?php endif; ?>
        <div style="display: flex; gap: 10px; min-width: 120px; height: 40px;">
            <button type="submit" name="add_engineer" style="height: 100%; width: 100%; white-space: nowrap;"><?php echo $edit_eng_data ? 'Update' : '+ Add Engineer'; ?></button>
            <?php if($edit_eng_data): ?>
                <a href="admin_engineers.php" style="background: #6c757d; color: white; text-decoration: none; padding: 0 15px; border-radius: 4px; font-size: 14px; font-weight: bold; text-align: center; line-height: 40px; height: 100%; white-space: nowrap;">Cancel</a>
            <?php endif; ?>
        </div>
    </form>

    <?php if (!empty($eng_error)): ?>
        <div style="color: #dc3545; font-weight: bold; margin-bottom: 15px; font-size: 13px;">⚠️ <?php echo $eng_error; ?></div>
    <?php endif; ?>

<form id="bulk-action-form" method="POST" onsubmit="return confirmBulkAction()">
    <div class="search-filter-bar">
        <input type="text" class="mobile-search-input" placeholder="Search logs, projects or engineers...">
        <select class="mobile-sort-select">
            <option value="">-- Sort By --</option>
            <option value="1-asc">Engineer (A-Z)</option>
            <option value="1-desc">Engineer (Z-A)</option>
            <option value="2-asc">Project ID (A-Z)</option>
            <option value="2-desc">Project ID (Z-A)</option>
            <option value="3-asc">Customer Name (A-Z)</option>
            <option value="3-desc">Customer Name (Z-A)</option>
            <option value="4-asc">Project Name (A-Z)</option>
            <option value="4-desc">Project Name (Z-A)</option>
            <option value="8-asc">Performance Gap (Low to High)</option>
            <option value="8-desc">Performance Gap (High to Low)</option>
        </select>
    </div>
    <input type="hidden" id="bulk-action-field" name="bulk_action" value="">

    <div id="select-all-toolbar" style="display: none; background: #e6f0ff; border: 1px solid #b8daff; padding: 12px 15px; border-radius: 6px; margin-bottom: 15px; align-items: center; justify-content: space-between;">
        <span style="color: #004085; font-size: 14px; font-weight: bold;">
            Selected engineers detected. Do you want to select all of them?
        </span>
        <div style="display: flex; gap: 8px; align-items: center;">
            <button type="button" onclick="selectAllCheckboxes(true)" style="background: #007bff; color: white; padding: 5px 12px; font-size: 12px; height: auto;">Select All</button>
            <button type="button" onclick="selectAllCheckboxes(false)" style="background: #6c757d; color: white; padding: 5px 12px; font-size: 12px; height: auto;">Clear</button>
            <span style="border-left: 1px solid #b8daff; height: 20px; margin: 0 5px;"></span>
            <button type="button" onclick="submitBulk('delete')" style="background: #dc3545; color: white; padding: 5px 12px; font-size: 12px; height: auto;">🗑️ Bulk Delete</button>
            <?php if ($current_admin_role == 1): ?>
                <button type="button" onclick="submitBulk('grant_admin')" style="background: #28a745; color: white; padding: 5px 12px; font-size: 12px; height: auto;">⭐ Promote to Admin</button>
                <button type="button" onclick="submitBulk('revoke_admin')" style="background: #6c757d; color: white; padding: 5px 12px; font-size: 12px; height: auto;">⬇️ Demote to Worker</button>
            <?php endif; ?>
        </div>
    </div>

    <div style="width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; margin-top: 15px;">
    <table>
        <thead>
            <tr>
                <th style="width: 40px;"></th>
                <th>
                    <div class="sort-dropdown-container">
                        <span>Username ID</span>
                        <button type="button" class="sort-dropbtn" onclick="toggleDropdown(event, 'drop-username')"></button>
                        <div id="drop-username" class="sort-dropdown-content">
                            <a href="#" onclick="sortTable(1, 'alpha', 0)">Sort by Default</a>
                            <a href="#" onclick="sortTable(1, 'alpha', 1)">Sort by A-Z</a>
                            <a href="#" onclick="sortTable(1, 'alpha', 2)">Sort by Z-A</a>
                        </div>
                    </div>
                </th>
                <th>
                    <div class="sort-dropdown-container">
                        <span>Assigned Name</span>
                        <button type="button" class="sort-dropbtn" onclick="toggleDropdown(event, 'drop-name')"></button>
                        <div id="drop-name" class="sort-dropdown-content">
                            <a href="#" onclick="sortTable(2, 'alpha', 0)">Sort by Default</a>
                            <a href="#" onclick="sortTable(2, 'alpha', 1)">Sort by A-Z</a>
                            <a href="#" onclick="sortTable(2, 'alpha', 2)">Sort by Z-A</a>
                        </div>
                    </div>
                </th>
                <th>
                    <div class="sort-dropdown-container">
                        <span>Account Status</span>
                        <button type="button" class="sort-dropbtn" onclick="toggleDropdown(event, 'drop-status')"></button>
                        <div id="drop-status" class="sort-dropdown-content">
                            <a href="#" onclick="sortTable(3, 'status', 0)">Sort by Default</a>
                            <a href="#" onclick="sortTable(3, 'status', 1)">Active First</a>
                            <a href="#" onclick="sortTable(3, 'status', 2)">Pending First</a>
                        </div>
                    </div>
                </th> 
                <th>
                    <div class="sort-dropdown-container">
                        <span>Role Rank</span>
                        <button type="button" class="sort-dropbtn" onclick="toggleDropdown(event, 'drop-role')"></button>
                        <div id="drop-role" class="sort-dropdown-content">
                            <a href="#" onclick="sortTable(4, 'role', 0)">Sort by Default</a>
                            <a href="#" onclick="sortTable(4, 'role', 1)">Admin First</a>
                            <a href="#" onclick="sortTable(4, 'role', 2)">Standard Worker First</a>
                        </div>
                    </div>
                </th>
                <th>
                    <div class="sort-dropdown-container">
                        <span>Registered Time</span>
                        <button type="button" class="sort-dropbtn" onclick="toggleDropdown(event, 'drop-time')"></button>
                        <div id="drop-time" class="sort-dropdown-content">
                            <a href="#" onclick="sortTable(5, 'date', 0)">Sort by Default</a>
                            <a href="#" onclick="sortTable(5, 'date', 1)">Oldest to Newest</a>
                            <a href="#" onclick="sortTable(5, 'date', 2)">Newest to Oldest</a>
                        </div>
                    </div>
                </th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($eng_result->num_rows == 0): ?>
                <tr><td colspan="7" style="text-align:center; color:#999;">No roster accounts registered yet.</td></tr>
            <?php else: ?>
                <?php while($e = $eng_result->fetch_assoc()): ?>
                <tr>
                    <td data-label="Select">
                        <input type="checkbox" class="eng-checkbox" name="selected_engs[]" value="<?php echo $e['id']; ?>" onchange="handleCheckboxChange()" style="width:16px; height:16px; cursor:pointer;">
                    </td>
                    <td data-label="Username"><code><?php echo htmlspecialchars($e['username_id']); ?></code></td>
                    <td data-label="Engineer Name"><strong><?php echo htmlspecialchars($e['engineer_name']); ?></strong></td>
                    <td data-label="Status">
                        <?php if (strpos($e['password'], 'NOT_ACTIVATED_') === false): ?>
                            <span class="status-badge status-active">Active</span>
                        <?php else: ?>
                            <span class="status-badge status-pending">Pending</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Role Rank">
                        <?php if (intval($e['is_admin']) == 2): ?>
                            <span class="status-badge status-admin">Secondary Admin</span>
                        <?php else: ?>
                            <span style="color:#6c757d;">Standard Worker</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Registered Time"><span style="color: #555; font-size: 13px;"><?php echo substr($e['created_at'], 0, 16); ?></span></td>
                    <td data-label="Actions">
                       <a href="admin_engineers.php?edit_eng=<?php echo $e['id']; ?>" class="btn-edit">Edit</a>
<a href="admin_engineers.php?reset_pwd=<?php echo $e['id']; ?>" class="btn-reset" onclick="return confirm('Reset this engineer password to P@ssw0rd?')">Reset Password</a>
<a href="admin_engineers.php?delete_eng=<?php echo $e['id']; ?>" class="btn-del" onclick="return confirm('WARNING! Deleting this engineer account will permanently erase all associated timesheet logs! Do you want to proceed?')">Delete</a>
                    </td>
                </tr>
                <?php endwhile; ?>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
</form>
</div>
<script>
function handleCheckboxChange() {
    const checkboxes = document.querySelectorAll('.eng-checkbox');
    const toolbar = document.getElementById('select-all-toolbar');
    let anyChecked = false;
    checkboxes.forEach(cb => { if (cb.checked) anyChecked = true; });

    if (anyChecked) {
        toolbar.style.display = 'flex';
        const card = toolbar.closest('.card');
        if (card && card.previousElementSibling !== toolbar) {
            card.parentNode.insertBefore(toolbar, card);
        }
    } else {
        toolbar.style.display = 'none';
    }
}

function selectAllCheckboxes(status) {
    const checkboxes = document.querySelectorAll('.eng-checkbox');
    checkboxes.forEach(cb => { cb.checked = status; });
    if (!status) {
        document.getElementById('select-all-toolbar').style.display = 'none';
    } else {
        handleCheckboxChange();
    }
}

function submitBulk(actionType) {
    const actionField = document.getElementById('bulk-action-field');
    const form = document.getElementById('bulk-action-form');
    actionField.value = actionType;
    form.submit();
}

function confirmBulkAction() {
    const actionField = document.getElementById('bulk-action-field').value;
    
    if (actionField === 'delete') {
        return confirm("⚠️ CRITICAL WARNING!\n\nAre you absolutely sure you want to BULK DELETE all selected engineer accounts?\nThis will permanently wipe their data and timesheet logs!");
    } else if (actionField === 'grant_admin') {
        return confirm("Are you sure you want to grant secondary administrator access to the selected users?");
    } else if (actionField === 'revoke_admin') {
        return confirm("Are you sure you want to demote the selected secondary administrators back to standard worker status?");
    }
    return true;
}

let originalRows = null;

function toggleDropdown(event, id) {
    event.stopPropagation();
    
    const dropdowns = document.getElementsByClassName("sort-dropdown-content");
    for (let i = 0; i < dropdowns.length; i++) {
        if (dropdowns[i].id !== id) {
            dropdowns[i].classList.remove("show-sort-menu");
        }
    }
    document.getElementById(id).classList.toggle("show-sort-menu");
}
window.onclick = function(event) {
    if (!event.target.matches('.sort-dropbtn')) {
        const dropdowns = document.getElementsByClassName("sort-dropdown-content");
        for (let i = 0; i < dropdowns.length; i++) {
            dropdowns[i].classList.remove("show-sort-menu");
        }
    }
}

function sortTable(colIndex, type, direction) {
    const table = document.querySelector("table");
    const tbody = table.querySelector("tbody");
    const rows = Array.from(tbody.querySelectorAll("tr"));
    
    if (!originalRows) {
        originalRows = [...rows];
    }

    if (direction === 0) {
        originalRows.forEach(row => tbody.appendChild(row));
        return;
    }
    rows.sort((rowA, rowB) => {
        let cellA = rowA.cells[colIndex].innerText.trim();
        let cellB = rowB.cells[colIndex].innerText.trim();

        if (type === 'alpha') {
            return direction === 1 ? cellA.localeCompare(cellB) : cellB.localeCompare(cellA);
        }
        
        if (type === 'date') {
            let timeA = new Date(cellA).getTime() || 0;
            let timeB = new Date(cellB).getTime() || 0;
            return direction === 1 ? timeA - timeB : timeB - timeA;
        }

        if (type === 'status') {
            let weightA = cellA.includes("Active") ? 1 : 2;
            let weightB = cellB.includes("Active") ? 1 : 2;
            return direction === 1 ? weightA - weightB : weightB - weightA;
        }

        if (type === 'role') {
            let weightA = cellA.includes("Admin") ? 1 : 2;
            let weightB = cellB.includes("Admin") ? 1 : 2;
            return direction === 1 ? weightA - weightB : weightB - weightA;
        }
        
        return 0;
    });
    rows.forEach(row => tbody.appendChild(row));
}

document.addEventListener("DOMContentLoaded", function () {
    const searchInput = document.querySelector(".mobile-search-input");
    const sortSelect = document.querySelector(".mobile-sort-select");
    const tbody = document.querySelector("table tbody");
    
    if (searchInput) {
        searchInput.addEventListener("input", function () {
            const filterValue = this.value.toLowerCase().trim();
            const rows = tbody.querySelectorAll("tr");
            
            rows.forEach(row => {
                let match = false;
                const cells = row.querySelectorAll("td");
                
                cells.forEach((cell, idx) => {
                    if (idx > 0 && idx < cells.length - 1) {
                        if (cell.innerText.toLowerCase().includes(filterValue)) {
                            match = true;
                        }
                    }
                });
                
                if (match || filterValue === "") {
                    row.classList.remove("is-hidden");
                } else {
                    row.classList.add("is-hidden");
                }
            });
        });
    }

    if (sortSelect) {
        sortSelect.addEventListener("change", function () {
            if (!this.value) return;
            
            const [colIdx, direction] = this.value.split("-");
            const index = parseInt(colIdx);
            const rows = Array.from(tbody.querySelectorAll("tr"));
            
            rows.sort((rowA, rowB) => {
                const cellA = rowA.cells[index] ? rowA.cells[index].innerText.trim() : "";
                const cellB = rowB.cells[index] ? rowB.cells[index].innerText.trim() : "";

                if (index === 8) {
                    const numA = parseFloat(cellA.replace(/[^0-9.-]/g, "")) || 0;
                    const numB = parseFloat(cellB.replace(/[^0-9.-]/g, "")) || 0;
                    return direction === "asc" ? numA - numB : numB - numA;
                }

                return direction === "asc" ? cellA.localeCompare(cellB) : cellB.localeCompare(cellA);
            });
            
            rows.forEach(row => tbody.appendChild(row));
        });
    }
});

</script>
</body>
</html>
