<?php
require_once 'config.php';

if (!isset($_SESSION['engineer_id']) || !isset($_SESSION['is_admin']) || ($_SESSION['is_admin'] != 1 && $_SESSION['is_admin'] != 2)) {
    header("Location: login.php");
    exit;
}

$proj_error = "";
if (isset($_GET['delete_proj']) && ($_SESSION['is_admin'] == 1 || $_SESSION['is_admin'] == 2)) {
    $del_proj_id = $_GET['delete_proj'];
    
    $check_stmt = $conn->prepare("SELECT COUNT(*) as total FROM timesheets WHERE project_id = ?");
    $check_stmt->bind_param("s", $del_proj_id);
    $check_stmt->execute();
    $count_res = $check_stmt->get_result()->fetch_assoc();
    $check_stmt->close();
    
    if ($count_res['total'] > 0) {
        $proj_error = "Cannot delete project <code>" . htmlspecialchars($del_proj_id) . "</code> because it contains " . $count_res['total'] . " linked timesheet logs. Please delete or reassign those logs first.";
    } else {
        $stmt = $conn->prepare("DELETE FROM projects WHERE project_id = ?");
        $stmt->bind_param("s", $del_proj_id);
        $stmt->execute();
        $stmt->close();
        header("Location: admin_projects.php");
        exit;
    }
}

$edit_proj = null;
if (isset($_GET['edit_proj']) && ($_SESSION['is_admin'] == 1 || $_SESSION['is_admin'] == 2)) {
    $edit_proj_id = $_GET['edit_proj'];
    $stmt = $conn->prepare("SELECT * FROM projects WHERE project_id = ?");
    $stmt->bind_param("s", $edit_proj_id);
    $stmt->execute();
    $edit_proj = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['save_project'])) {
    if ($_SESSION['is_admin'] == 1 || $_SESSION['is_admin'] == 2) {
        $p_id = !empty(trim($_POST['project_id'])) ? trim($_POST['project_id']) : 'N/A';
        $p_name = trim($_POST['project_name']);
        $c_name = trim($_POST['customer_name']);
        
        $input_days = intval($_POST['estimate_days']);
        $est_hours = $input_days * 8; 
        
        $pricing = !empty(trim($_POST['pricing'])) ? floatval($_POST['pricing']) : null;
        
        $old_p_id = isset($_POST['old_project_id']) ? trim($_POST['old_project_id']) : '';

        if (!empty($old_p_id)) {
            $stmt = $conn->prepare("UPDATE projects SET project_id=?, project_name=?, customer_name=?, estimate_time=?, pricing=? WHERE project_id=?");
            $stmt->bind_param("sssids", $p_id, $p_name, $c_name, $est_hours, $pricing, $old_p_id);
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $conn->prepare("INSERT INTO projects (project_id, project_name, customer_name, estimate_time, pricing) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("sssid", $p_id, $p_name, $c_name, $est_hours, $pricing);
            $stmt->execute();
            $stmt->close();
        }
        
        header("Location: admin_projects.php");
        exit;
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['bulk_action']) && $_POST['bulk_action'] === 'delete') {
    if (!isset($_POST['selected_projs']) || !is_array($_POST['selected_projs'])) {
        $proj_error = "Please select at least one project for bulk deletion!";
    } else {
        $has_linked_logs = false;
        $linked_projects = [];
        
        foreach ($_POST['selected_projs'] as $p_id) {
            $check_stmt = $conn->prepare("SELECT COUNT(*) as total FROM timesheets WHERE project_id = ?");
            $check_stmt->bind_param("s", $p_id);
            $check_stmt->execute();
            $count_res = $check_stmt->get_result()->fetch_assoc();
            $check_stmt->close();
            
            if ($count_res['total'] > 0) {
                $has_linked_logs = true;
                $linked_projects[] = htmlspecialchars($p_id);
            }
        }

        if ($has_linked_logs) {
            $proj_error = "Bulk deletion aborted! The following projects contain active timesheet logs: <code>" . implode(', ', $linked_projects) . "</code>. Please clean or reassign those logs first.";
        } else {
            foreach ($_POST['selected_projs'] as $p_id) {
                $stmt = $conn->prepare("DELETE FROM projects WHERE project_id = ?");
                $stmt->bind_param("s", $p_id);
                $stmt->execute();
                $stmt->close();
            }
            header("Location: admin_projects.php");
            exit;
        }
    }
}

$proj_result = $conn->query("SELECT * FROM projects ORDER BY project_id ASC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Manage Projects</title>
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
        button { background: #28a745; color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer; font-size: 14px; font-weight: bold; height: 40px; }

        table { width: 100%; border-collapse: collapse; background: white; margin-top: 15px; }
        th, td { padding: 12px; border-bottom: 1px solid #ddd; text-align: left; font-size: 14px; }
        th { background: #f8f9fa; font-weight: bold; }

        td:last-child {
            display: flex !important;
            gap: 8px !important;
            justify-content: flex-start !important;
            align-items: center !important;
            white-space: nowrap !important;
        }

        .btn-edit { 
            background: #ffc107; 
            color: #333; 
            padding: 5px 12px; 
            text-decoration: none; 
            border-radius: 4px; 
            font-size: 12px; 
            font-weight: bold;
            display: inline-flex !important; 
            align-items: center;
            justify-content: center;
        }

        .btn-del { 
            background: #dc3545; 
            color: white; 
            padding: 5px 12px; 
            text-decoration: none; 
            border-radius: 4px; 
            font-size: 12px; 
            font-weight: bold;
            display: inline-flex !important; 
            align-items: center;
            justify-content: center;
        }

        .sort-dropdown-container { display: inline-flex; align-items: center; position: relative; gap: 6px; }
        .sort-dropbtn { background: none; border: none; position: relative; width: 14px; height: 14px; cursor: pointer; padding: 0; display: inline-block; }
        .sort-dropbtn::before { content: ""; position: absolute; top: 1px; left: 2px; border-left: 5px solid transparent; border-right: 5px solid transparent; border-bottom: 5px solid #444; }
        .sort-dropbtn::after { content: ""; position: absolute; bottom: 1px; left: 2px; border-left: 5px solid transparent; border-right: 5px solid transparent; border-top: 5px solid #444; }
        .sort-dropbtn:hover::before { border-bottom-color: #007bff; }
        .sort-dropbtn:hover::after { border-top-color: #007bff; }
        .sort-dropdown-content { display: none; position: absolute; top: 24px; left: 0; background-color: #ffffff; min-width: 140px; box-shadow: 0px 8px 16px 0px rgba(0,0,0,0.12); border: 1px solid #e2e8f0; border-radius: 4px; z-index: 99; }
        .sort-dropdown-content a { color: #333333 !important; padding: 8px 14px; text-decoration: none !important; display: block; font-size: 13px; font-weight: normal; text-align: left; line-height: 1.4; }
        .sort-dropdown-content a:hover { background-color: #f8fafc; color: #007bff !important; }
        .show-sort-menu { display: block !important; }
        #select-all-toolbar { display: none; }
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
                justify-content: flex-end !important;
                gap: 4px !important;
                border-top: 1px solid #edf2f7; 
                background: #f8fafc; 
                padding: 3px 6px !important; 
                margin-top: 0 !important;
                position: static !important;
            }
            .btn-edit, .btn-del { padding: 2px 6px !important; font-size: 9px !important; border-radius: 3px !important; margin: 0 !important; line-height: 1.2 !important; height: auto !important; }
            
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
    <h2>Manage & Add New Projects</h2>
    <a href="admin.php" style="color: #ffc107; font-weight: bold; text-decoration: none;">← Back to Main Menu</a>
</div>

<div class="card">
    <h3><?php echo $edit_proj ? 'Edit Project Details' : 'Create & Register New Corporate Projects'; ?></h3>
    <?php if (!empty($proj_error)): ?>
        <div style="background: #f8d7da; color: #721c24; padding: 12px; border-radius: 4px; margin-bottom: 15px; border: 1px solid #f5c6cb; font-size: 14px;">
            ⚠️ <?php echo $proj_error; ?>
        </div>
    <?php endif; ?>
    <form method="POST" class="form-inline" style="background: #f8f9fa; padding: 15px; border-radius: 6px; border: 1px solid #e9ecef; margin-bottom: 15px;">
        <input type="hidden" name="old_project_id" value="<?php echo $edit_proj ? htmlspecialchars($edit_proj['project_id']) : ''; ?>">
        <div>
            <label>Project ID (Optional):</label>
            <input type="text" name="project_id" value="<?php echo $edit_proj ? htmlspecialchars($edit_proj['project_id']) : ''; ?>" placeholder="e.g., SO-xxxx">
        </div>
        <div>
            <label>Project Name:</label>
            <input type="text" name="project_name" value="<?php echo $edit_proj ? htmlspecialchars($edit_proj['project_name']) : ''; ?>" required placeholder="e.g., App Development">
        </div>
        <div>
            <label>Customer Name:</label>
            <input type="text" name="customer_name" value="<?php echo $edit_proj ? htmlspecialchars($edit_proj['customer_name']) : ''; ?>" required placeholder="e.g., Apple Inc.">
        </div>
        <div>
            <label>Target Maindays (1 Day = 8h):</label>
            <input type="number" name="estimate_days" min="1" value="<?php echo $edit_proj ? intval($edit_proj['estimate_time'] / 8) : ''; ?>" required placeholder="e.g., 10">
        </div>
        <div>
            <label>Pricing (Optional):</label>
            <input type="number" name="pricing" step="0.01" min="0" value="<?php echo $edit_proj ? htmlspecialchars($edit_proj['pricing']) : ''; ?>" placeholder="e.g., 5000.00">
        </div>
        <button type="submit" name="save_project">Save Project</button>
        <?php if($edit_proj): ?><a href="admin_projects.php" style="margin-left:10px; color:#6c757d; line-height:40px; text-decoration:none;">Cancel</a><?php endif; ?>
    </form>
    <form id="bulk-action-form" method="POST" onsubmit="return confirmBulkDelete()">
    <div class="search-filter-bar">
        <input type="text" class="mobile-search-input" placeholder="Search keywords...">
        <select class="mobile-sort-select">
            <option value="">-- Sort By --</option>
            <option value="1-asc">Project ID (A-Z)</option>
            <option value="1-desc">Project ID (Z-A)</option>
            <option value="2-asc">Project Name (A-Z)</option>
            <option value="2-desc">Project Name (Z-A)</option>
            <option value="5-asc">Pricing (Lowest Price)</option>
            <option value="5-desc">Pricing (Highest Price)</option>
        </select>
    </div>
        <input type="hidden" id="bulk-action-field" name="bulk_action" value="">

        <div id="select-all-toolbar" style="display: none; background: #e6f0ff; border: 1px solid #b8daff; padding: 12px 15px; border-radius: 6px; margin-bottom: 15px; align-items: center; justify-content: space-between;">
            <span style="color: #004085; font-size: 14px; font-weight: bold;">
                Selected projects detected. Batch actions:
            </span>
            <div style="display: flex; gap: 8px; align-items: center;">
                <button type="button" onclick="selectAllCheckboxes(true)" style="background: #007bff; color: white; padding: 5px 12px; font-size: 12px; height: auto;">Select All</button>
                <button type="button" onclick="selectAllCheckboxes(false)" style="background: #6c757d; color: white; padding: 5px 12px; font-size: 12px; height: auto;">Clear</button>
                <span style="border-left: 1px solid #b8daff; height: 20px; margin: 0 5px;"></span>
                <button type="submit" style="background: #dc3545; color: white; padding: 5px 12px; font-size: 12px; height: auto;">🗑️ Bulk Delete Selected</button>
            </div>
        </div>
        <div style="width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; margin-top: 15px;">
        <table>
            <thead>
                <tr>
                    <th style="width: 40px;"></th>
                    <th>
                        <div class="sort-dropdown-container">
                            <span>Project ID</span>
                            <button type="button" class="sort-dropbtn" onclick="toggleDropdown(event, 'drop-pid')"></button>
                            <div id="drop-pid" class="sort-dropdown-content">
                                <a href="#" onclick="sortTable(1, 'alpha', 0)">Sort by Default</a>
                                <a href="#" onclick="sortTable(1, 'alpha', 1)">Sort by A-Z</a>
                                <a href="#" onclick="sortTable(1, 'alpha', 2)">Sort by Z-A</a>
                            </div>
                        </div>
                    </th>
                    <th>
                        <div class="sort-dropdown-container">
                            <span>Project Name</span>
                            <button type="button" class="sort-dropbtn" onclick="toggleDropdown(event, 'drop-pname')"></button>
                            <div id="drop-pname" class="sort-dropdown-content">
                                <a href="#" onclick="sortTable(2, 'alpha', 0)">Sort by Default</a>
                                <a href="#" onclick="sortTable(2, 'alpha', 1)">Sort by A-Z</a>
                                <a href="#" onclick="sortTable(2, 'alpha', 2)">Sort by Z-A</a>
                            </div>
                        </div>
                    </th>
                    <th>
                        <div class="sort-dropdown-container">
                            <span>Customer Name</span>
                            <button type="button" class="sort-dropbtn" onclick="toggleDropdown(event, 'drop-cname')"></button>
                            <div id="drop-cname" class="sort-dropdown-content">
                                <a href="#" onclick="sortTable(3, 'alpha', 0)">Sort by Default</a>
                                <a href="#" onclick="sortTable(3, 'alpha', 1)">Sort by A-Z</a>
                                <a href="#" onclick="sortTable(3, 'alpha', 2)">Sort by Z-A</a>
                            </div>
                        </div>
                    </th>
                    <th>
                        <div class="sort-dropdown-container">
                            <span>Target Maindays</span>
                            <button type="button" class="sort-dropbtn" onclick="toggleDropdown(event, 'drop-esthours')"></button>
                            <div id="drop-esthours" class="sort-dropdown-content">
                                <a href="#" onclick="sortTable(4, 'num', 0)">Sort by Default</a>
                                <a href="#" onclick="sortTable(4, 'num', 1)">Lowest to Highest</a>
                                <a href="#" onclick="sortTable(4, 'num', 2)">Highest to Lowest</a>
                            </div>
                        </div>
                    </th>
                    <th>
                        <div class="sort-dropdown-container">
                            <span>Pricing</span>
                            <button type="button" class="sort-dropbtn" onclick="toggleDropdown(event, 'drop-pricing')"></button>
                            <div id="drop-pricing" class="sort-dropdown-content">
                                <a href="#" onclick="sortTable(5, 'num', 0)">Sort by Default</a>
                                <a href="#" onclick="sortTable(5, 'num', 1)">Lowest Price</a>
                                <a href="#" onclick="sortTable(5, 'num', 2)">Highest Price</a>
                            </div>
                        </div>
                    </th>
                    <th>
                        <div class="sort-dropdown-container">
                            <span>Created Time</span>
                            <button type="button" class="sort-dropbtn" onclick="toggleDropdown(event, 'drop-time')"></button>
                            <div id="drop-time" class="sort-dropdown-content">
                                <a href="#" onclick="sortTable(6, 'date', 0)">Sort by Default</a>
                                <a href="#" onclick="sortTable(6, 'date', 1)">Oldest First</a>
                                <a href="#" onclick="sortTable(6, 'date', 2)">Newest First</a>
                            </div>
                        </div>
                    </th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($proj_result->num_rows == 0) { ?>
                    <tr><td colspan="8" style="text-align:center; color:#999;">No projects added yet.</td></tr>
                <?php } else { ?>
                    <?php while($p = $proj_result->fetch_assoc()): ?>
                    <tr>
                        <td>
                            <input type="checkbox" class="proj-checkbox" name="selected_projs[]" value="<?php echo htmlspecialchars($p['project_id']); ?>" onchange="handleCheckboxChange()" style="width:16px; height:16px; cursor:pointer;">
                        </td>
                        <td data-label="Project ID"><code><?php echo htmlspecialchars($p['project_id']); ?></code></td>
                        <td data-label="Project Name"><strong><?php echo htmlspecialchars($p['project_name']); ?></strong></td>
                        <td data-label="Customer Name"><?php echo htmlspecialchars($p['customer_name']); ?></td>
                        <td data-label="Target Maindays">
                            <span style="font-weight:bold; color:#28a745;"><?php echo ($p['estimate_time'] / 8); ?> Days</span>
                            <span style="font-size:11px; color:#aaa; display:block;">(<?php echo intval($p['estimate_time']); ?> Total Hours)</span>
                        </td>
                        <td data-label="Pricing">
                            <span style="font-weight:bold; color:#ff9f43;">
                                <?php echo !empty($p['pricing']) ? '$' . number_format($p['pricing'], 2) : '<span style="color:#ccc;">-</span>'; ?>
                            </span>
                        </td>
                        <td data-label="Created Time"><span style="color:#555; font-size:13px;"><?php echo substr($p['created_at'], 0, 16); ?></span></td>
                        <td>
                            <a href="admin_projects.php?edit_proj=<?php echo urlencode($p['project_id']); ?>" class="btn-edit">Edit</a>
                            <a href="admin_projects.php?delete_proj=<?php echo urlencode($p['project_id']); ?>" class="btn-del" onclick="return confirm('WARNING! Deleting this project will affect linked timesheets. Do you want to continue?')">Delete</a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                <?php } ?>
            </tbody>
    </table>
    </div>
</form>
</div>
<script>
let originalRows = null;

function toggleDropdown(event, id) {
    event.stopPropagation();
    const dropdowns = document.getElementsByClassName("sort-dropdown-content");
    for (let i = 0; i < dropdowns.length; i++) {
        if (dropdowns[i].id !== id) { dropdowns[i].classList.remove("show-sort-menu"); }
    }
    document.getElementById(id).classList.toggle("show-sort-menu");
}

window.onclick = function(event) {
    if (!event.target.matches('.sort-dropbtn')) {
        const dropdowns = document.getElementsByClassName("sort-dropdown-content");
        for (let i = 0; i < dropdowns.length; i++) { dropdowns[i].classList.remove("show-sort-menu"); }
    }
}

function sortTable(colIndex, type, direction) {
    const table = document.querySelector("table");
    const tbody = table.querySelector("tbody");
    const rows = Array.from(tbody.querySelectorAll("tr"));
    
    if (!originalRows) { originalRows = [...rows]; }
    if (direction === 0) { originalRows.forEach(row => tbody.appendChild(row)); return; }

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
        if (type === 'num') {
            let numA = parseFloat(cellA.replace(/[^0-9.-]/g, '')) || 0;
            let numB = parseFloat(cellB.replace(/[^0-9.-]/g, '')) || 0;
            return direction === 1 ? numA - numB : numB - numA;
        }
        return 0;
    });
    rows.forEach(row => tbody.appendChild(row));
}

function confirmBulkDelete() {
    document.getElementById('bulk-action-field').value = 'delete';
    return confirm("⚠️ CRITICAL SECURITY WARNING!\n\nAre you absolutely sure you want to BULK DELETE all selected corporate projects?\nThis action cannot be undone!");
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
                
                let valA = cellA;
                let valB = cellB;
                
                const numA = parseFloat(cellA.replace(/[^0-9.-]/g, ""));
                const numB = parseFloat(cellB.replace(/[^0-9.-]/g, ""));
                
                if (!isNaN(numA) && !isNaN(numB)) {
                    return direction === "asc" ? numA - numB : numB - numA;
                }
                
                return direction === "asc" ? valA.localeCompare(valB) : valB.localeCompare(valA);
            });
            
            rows.forEach(row => tbody.appendChild(row));
        });
    }
});

function handleCheckboxChange() {
    const checkboxes = document.querySelectorAll('.proj-checkbox');
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
    const checkboxes = document.querySelectorAll('.proj-checkbox');
    checkboxes.forEach(cb => { cb.checked = status; });
    if (!status) { 
        document.getElementById('select-all-toolbar').style.display = 'none'; 
    } else {
        handleCheckboxChange();
    }
}

</script>
</body>
</html>
