<?php
require_once 'config.php';

if (!isset($_SESSION['engineer_id']) || !isset($_SESSION['is_admin']) || ($_SESSION['is_admin'] != 1 && $_SESSION['is_admin'] != 2)) {
    header("Location: login.php"); exit;
}

$error = "";

if (isset($_GET['delete_proj'])) {
    $del_id = $_GET['delete_proj'];
    $chk = $conn->prepare("SELECT COUNT(*) as total FROM timesheets WHERE project_id=?");
    $chk->bind_param("s", $del_id); $chk->execute();
    $cnt = $chk->get_result()->fetch_assoc(); $chk->close();
    if ($cnt['total'] > 0) {
        $error = "Cannot delete <code>".htmlspecialchars($del_id)."</code> — it has ".$cnt['total']." linked timesheet logs.";
    } else {
        $d1 = $conn->prepare("DELETE FROM iips_tracking WHERE project_id=?");
        $d1->bind_param("s", $del_id); $d1->execute(); $d1->close();
        $d2 = $conn->prepare("DELETE FROM projects WHERE project_id=?");
        $d2->bind_param("s", $del_id); $d2->execute(); $d2->close();
        header("Location: admin_iips.php"); exit;
    }
}

if (isset($_GET['edit_proj'])) {
    header("Location: create_iips.php?edit=".urlencode($_GET['edit_proj'])); exit;
}

$result = $conn->query("
    SELECT 
        p.*,
        i.id            AS iips_id,
        i.selling_price, i.partner_cost, i.gross_profit,
        i.has_project_mgmt,
        i.target_mandays, i.target_start_date, i.target_end_date,
        i.target_billing_date,
        i.iips_status, i.billing_status,
        i.account_manager, i.account_leader, i.presales_sdm, i.project_manager,
        i.partner
    FROM projects p
    LEFT JOIN iips_tracking i ON p.project_id = i.project_id
    ORDER BY p.project_id ASC
");

function getTimesheetData($conn, $project_id) {
    $stmt = $conn->prepare("
        SELECT
            MIN(start_date) AS actual_start,
            MAX(end_date)   AS actual_end,
            SUM(
                GREATEST(0, TIMESTAMPDIFF(MINUTE,
                    CONCAT(start_date,' ',start_time),
                    CONCAT(end_date,' ',end_time)
                ) - (COALESCE(meal_breaks, 0) * 60))
            ) AS total_minutes,
            GROUP_CONCAT(DISTINCT engineer_name ORDER BY engineer_name SEPARATOR ', ') AS engineers
        FROM timesheets WHERE project_id=?
    ");
    $stmt->bind_param("s", $project_id); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return $row;
}

$rows = [];
while ($row = $result->fetch_assoc()) {
    $ts = getTimesheetData($conn, $row['project_id']);
    $row['ts_start']    = $ts['actual_start'] ?? null;
    $row['ts_end']      = $ts['actual_end']   ?? null;
    $row['ts_minutes']  = intval($ts['total_minutes'] ?? 0);
    $row['ts_engineers']= $ts['engineers']    ?? null;
    $rows[] = $row;
}

function fmtDate($d) {
    if (!$d) return '-';
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt ? $dt->format('d-M-Y') : $d;
}
function fmtPid($pid) {
    if (!$pid) return '-';
    if (preg_match('/^N\/A/i', $pid)) return '-';
    return htmlspecialchars($pid);
}
function fmtMins($m) {
    if (!$m) return '-';
    $h = floor($m/60); $r = $m%60;
    return $h.'h '.$r.'m';
}

$billing_years = [];
$target_start_years = [];
$target_end_years = [];
$actual_start_years = [];
$actual_end_years = [];

foreach ($rows as $r) {
    foreach ([
        [$r['target_billing_date'], &$billing_years],
        [$r['target_start_date'],   &$target_start_years],
        [$r['target_end_date'],     &$target_end_years],
        [$r['ts_start'],            &$actual_start_years],
        [$r['ts_end'],              &$actual_end_years],
    ] as [$date, &$arr]) {
        if (!empty($date)) {
            $y = date('Y', strtotime($date));
            if ($y && !in_array($y, $arr)) $arr[] = $y;
        }
    }
}
sort($billing_years);
sort($target_start_years);
sort($target_end_years);
sort($actual_start_years);
sort($actual_end_years);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>IIPS List</title>
<style>
    .is-hidden { display: none !important; }
    * { box-sizing: border-box; }
    body { font-family: Arial, sans-serif; margin: 0; background: #f4f7f6; color: #333; }
    .header { display: flex; justify-content: space-between; align-items: center; background: #343a40; padding: 15px 20px; color: white; flex-wrap: wrap; gap: 10px; }
    .header h2 { margin: 0; font-size: 18px; }
    .header a { color: #ffc107; font-weight: bold; text-decoration: none; font-size: 13px; }
    .page { padding: 20px; }
    .card { background: white; padding: 20px 25px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); margin-top: 0; }
    .filter-bar { background: white; border-radius: 6px; padding: 12px 16px; margin-bottom: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
    .filter-bar-top { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 10px; }
    .filter-bar-top input[type="text"] { flex: 1; min-width: 200px; height: 36px; padding: 0 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 13px; }
    .btn-create-iips { display: inline-flex; align-items: center; gap: 6px; background: #28a745; color: white; text-decoration: none; font-size: 13px; font-weight: 700; padding: 0 16px; height: 36px; border-radius: 4px; white-space: nowrap; border: none; cursor: pointer; }
    .btn-create-iips:hover { background: #218838; color: white; }
    .filter-date-row { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 10px; }
    .filter-date-row label { font-size: 12px; font-weight: 600; color: #475569; white-space: nowrap; }
    .filter-date-row input[type="date"] { height: 34px; padding: 0 8px; border: 1px solid #ccc; border-radius: 4px; font-size: 13px; }
    .filter-cats { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
    .filter-cats label { display: flex; align-items: center; gap: 4px; font-size: 12px; color: #334155; cursor: pointer; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 3px 10px; user-select: none; }
    .filter-cats label:hover { background: #e0f2fe; border-color: #7dd3fc; }
    .filter-cats input[type="checkbox"] { width: 13px; height: 13px; cursor: pointer; }
    .filter-cats label.active-cat { background: #1d4ed8; color: white; border-color: #1d4ed8; }
    .btn-clear-filter { background: #6c757d; color: white; border: none; height: 34px; padding: 0 14px; border-radius: 4px; font-size: 12px; cursor: pointer; white-space: nowrap; }
    .alert-err { background:#f8d7da; color:#721c24; padding:12px; border-radius:4px; margin-bottom:15px; border:1px solid #f5c6cb; font-size:13px; }
    .tbl-outer { position: relative; }
    .tbl-wrap { overflow-x: auto; overflow-y: auto; max-height: 70vh; -webkit-overflow-scrolling: touch; }
    .tbl-wrap::-webkit-scrollbar { width: 10px; height: 8px; }
    .tbl-wrap::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 5px; }
    .tbl-wrap::-webkit-scrollbar-thumb { background: #94a3b8; border-radius: 5px; }
    .tbl-wrap::-webkit-scrollbar-thumb:hover { background: #64748b; }
    table { width: 100%; border-collapse: collapse; min-width: 1800px; }
    th, td { padding: 10px 12px; border-bottom: 1px solid #dee2e6; text-align: left; font-size: 13px; white-space: nowrap; }
    th { font-weight: bold; color: #495057; background: #f8f9fa; }
    tbody tr:hover { background: #f8faff; }
    .sec-row th { text-align: center; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; padding: 5px 8px; color: white; border: 1px solid rgba(255,255,255,0.15); position: sticky; top: 0; z-index: 22; }
    thead tr:last-child th { position: sticky; top: 27px; z-index: 21; background: #f8f9fa; box-shadow: 0 2px 0 #dee2e6; }
    .s-base    { background: #343a40; }
    .s-costing { background: #155724; }
    .s-timeline{ background: #1a237e; }
    .s-actual  { background: #004d40; }
    .s-status  { background: #6a1b4d; }
    .s-res     { background: #4a235a; }
    .s-act     { background: #343a40; width: 90px; }
    .bg-manual   { background: #fffbf0; }
    .bg-auto     { background: #f0fdf4; color: #065f46; }
    .bg-calc     { background: #eff6ff; }
    .bg-dropdown { background: #fdf4ff; }
    .badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: 700; }
    .b-nq   { background:#f1f5f9; color:#475569; }
    .b-q    { background:#dbeafe; color:#1e40af; }
    .b-ns   { background:#fef9c3; color:#854d0e; }
    .b-ip   { background:#dcfce7; color:#166534; }
    .b-done { background:#166534; color:white; }
    .b-can  { background:#fee2e2; color:#991b1b; }
    .b-nf   { background:#f1f5f9; color:#475569; }
    .b-fc   { background:#dbeafe; color:#1e40af; }
    .b-pend { background:#fef9c3; color:#854d0e; }
    .b-bdc  { background:#166534; color:white; }
    .tog { display: inline-flex; align-items: center; gap: 5px; }
    .tog-track { width: 30px; height: 16px; background: #d1d5db; border-radius: 8px; position: relative; }
    .tog-track.on { background: #28a745; }
    .tog-thumb { position: absolute; top: 2px; left: 2px; width: 12px; height: 12px; background: white; border-radius: 50%; transition: transform .2s; box-shadow: 0 1px 2px rgba(0,0,0,.2); }
    .tog-track.on .tog-thumb { transform: translateX(14px); }
    .tog-lbl { font-size: 11px; font-weight: 700; color: #6b7280; }
    .tog-lbl.yes { color: #166534; }
    td:last-child { white-space: nowrap; }
    .btn-edit { background: #ffc107; color: #333; padding: 4px 10px; text-decoration: none; border-radius: 4px; font-size: 12px; font-weight: bold; margin-right: 4px; }
    .btn-del  { background: #dc3545; color: white; padding: 4px 10px; text-decoration: none; border-radius: 4px; font-size: 12px; font-weight: bold; }
    .btn-edit:hover { background: #e0a800; }
    .btn-del:hover  { background: #c82333; }
    .sort-wrap { display: inline-flex; align-items: center; gap: 4px; position: relative; }
    .sort-btn { background:none; border:none; width:13px; height:13px; cursor:pointer; position:relative; padding:0; }
    .sort-btn::before { content:""; position:absolute; top:1px; left:1px; border-left:4px solid transparent; border-right:4px solid transparent; border-bottom:4px solid #888; }
    .sort-btn::after  { content:""; position:absolute; bottom:1px; left:1px; border-left:4px solid transparent; border-right:4px solid transparent; border-top:4px solid #888; }
    .sort-btn:hover::before { border-bottom-color:#007bff; }
    .sort-btn:hover::after  { border-top-color:#007bff; }
    .sort-menu { display:none; position:absolute; top:20px; left:0; background:#fff; min-width:130px; box-shadow:0 4px 12px rgba(0,0,0,.1); border:1px solid #e2e8f0; border-radius:4px; z-index:99; }
    .sort-menu a { display:block; padding:7px 12px; font-size:12px; color:#333; text-decoration:none; }
    .sort-menu a:hover { background:#f8fafc; color:#007bff; }
    .show-sort { display:block !important; }
    .auto-val { font-size: 12px; color: #065f46; white-space: nowrap; }
    .dash { color: #9ca3af; }
    @media (max-width: 768px) { body { margin: 0; } .page { padding: 10px; } .header { padding: 10px; } .card { padding: 12px; } }
</style>
</head>
<body>

<div class="header">
    <h2>📋 IIPS List</h2>
    <a href="admin.php">← Back to Admin</a>
</div>

<div class="page">
    <?php if ($error): ?>
        <div class="alert-err">⚠️ <?= $error ?></div>
    <?php endif; ?>

    <div class="filter-bar">
        <div class="filter-bar-top">
            <input type="text" id="search-input" placeholder="🔍 Search IIPS ID, name, customer, manager, partner...">
            <a href="create_iips.php" class="btn-create-iips">+ Create IIPS</a>
        </div>
        <div class="filter-date-row">
            <label>Target Start:</label>
            <div style="position:relative;height:34px;display:flex;min-width:150px;">
                <input type="text" id="filter-target-start-display" placeholder="DD MMM YYYY"
                       style="flex:1;height:100%;padding:0 36px 0 10px;border:1px solid #ccc;border-radius:4px;font-size:13px;text-transform:uppercase;"
                       oninput="this.value=this.value.toUpperCase()"
                       onblur="parseFilerDate('target-start')" onkeydown="if(event.key==='Enter'){this.blur()}"
                       autocomplete="off">
                <div style="position:absolute;right:0;top:0;width:36px;height:100%;display:flex;align-items:center;justify-content:center;cursor:pointer;" onclick="document.getElementById('filter-target-start-hidden').showPicker()">📅</div>
                <input type="date" id="filter-target-start-hidden" style="position:absolute;top:0;right:0;width:36px;height:100%;opacity:0;cursor:pointer;z-index:5;" onchange="syncFilterDate('target-start')">
            </div>
            <label style="margin-left:8px;">Target End:</label>
            <div style="position:relative;height:34px;display:flex;min-width:150px;">
                <input type="text" id="filter-target-end-display" placeholder="DD MMM YYYY"
                       style="flex:1;height:100%;padding:0 36px 0 10px;border:1px solid #ccc;border-radius:4px;font-size:13px;text-transform:uppercase;"
                       oninput="this.value=this.value.toUpperCase()"
                       onblur="parseFilerDate('target-end')" onkeydown="if(event.key==='Enter'){this.blur()}"
                       autocomplete="off">
                <div style="position:absolute;right:0;top:0;width:36px;height:100%;display:flex;align-items:center;justify-content:center;cursor:pointer;" onclick="document.getElementById('filter-target-end-hidden').showPicker()">📅</div>
                <input type="date" id="filter-target-end-hidden" style="position:absolute;top:0;right:0;width:36px;height:100%;opacity:0;cursor:pointer;z-index:5;" onchange="syncFilterDate('target-end')">
            </div>

            <label style="margin-left:8px;">Actual Start:</label>
            <div style="position:relative;height:34px;display:flex;min-width:150px;">
                <input type="text" id="filter-actual-start-display" placeholder="DD MMM YYYY"
                       style="flex:1;height:100%;padding:0 36px 0 10px;border:1px solid #ccc;border-radius:4px;font-size:13px;text-transform:uppercase;"
                       oninput="this.value=this.value.toUpperCase()"
                       onblur="parseFilerDate('actual-start')" onkeydown="if(event.key==='Enter'){this.blur()}"
                       autocomplete="off">
                <div style="position:absolute;right:0;top:0;width:36px;height:100%;display:flex;align-items:center;justify-content:center;cursor:pointer;" onclick="document.getElementById('filter-actual-start-hidden').showPicker()">📅</div>
                <input type="date" id="filter-actual-start-hidden" style="position:absolute;top:0;right:0;width:36px;height:100%;opacity:0;cursor:pointer;z-index:5;" onchange="syncFilterDate('actual-start')">
            </div>
            <label style="margin-left:8px;">Actual End:</label>
            <div style="position:relative;height:34px;display:flex;min-width:150px;">
                <input type="text" id="filter-actual-end-display" placeholder="DD MMM YYYY"
                       style="flex:1;height:100%;padding:0 36px 0 10px;border:1px solid #ccc;border-radius:4px;font-size:13px;text-transform:uppercase;"
                       oninput="this.value=this.value.toUpperCase()"
                       onblur="parseFilerDate('actual-end')" onkeydown="if(event.key==='Enter'){this.blur()}"
                       autocomplete="off">
                <div style="position:absolute;right:0;top:0;width:36px;height:100%;display:flex;align-items:center;justify-content:center;cursor:pointer;" onclick="document.getElementById('filter-actual-end-hidden').showPicker()">📅</div>
                <input type="date" id="filter-actual-end-hidden" style="position:absolute;top:0;right:0;width:36px;height:100%;opacity:0;cursor:pointer;z-index:5;" onchange="syncFilterDate('actual-end')">
            </div>
            <button class="btn-clear-filter" onclick="clearAllFilters()">✕ Clear</button>
        </div>
        <div class="filter-cats">
            <span style="font-size:12px;font-weight:700;color:#475569;margin-right:4px;">IIPS Status:</span>
            <label><input type="checkbox" value="not_quoted"   onchange="applyFilters()"> Not Quoted</label>
            <label><input type="checkbox" value="quoted"       onchange="applyFilters()"> Quoted</label>
            <label><input type="checkbox" value="not_started"  onchange="applyFilters()"> Not Started</label>
            <label><input type="checkbox" value="in_progress"  onchange="applyFilters()"> In Progress</label>
            <label><input type="checkbox" value="completed"    onchange="applyFilters()"> Completed</label>
            <label><input type="checkbox" value="cancelled"    onchange="applyFilters()"> Cancelled</label>
        </div>
        <div class="filter-cats" style="margin-top:6px;">
            <span style="font-size:12px;font-weight:700;color:#475569;margin-right:4px;">Billing Status:</span>
            <label><input type="checkbox" value="billing_nf"     onchange="applyFilters()"> Not Forecasted</label>
            <label><input type="checkbox" value="billing_fc"     onchange="applyFilters()"> Forecasted</label>
            <label><input type="checkbox" value="billing_pending" onchange="applyFilters()"> Pending</label>
            <label><input type="checkbox" value="billing_done"   onchange="applyFilters()"> Billing Completed</label>
        </div>
        <div class="filter-cats" style="margin-top:6px;">
            <span style="font-size:12px;font-weight:700;color:#475569;margin-right:4px;">Timesheet:</span>
            <label><input type="checkbox" value="has_data"  onchange="applyFilters()"> Has Timesheet Data</label>
            <label><input type="checkbox" value="no_data"   onchange="applyFilters()"> No Timesheet Data</label>
        </div>
        <div class="filter-cats" style="margin-top:6px;">
            <span style="font-size:12px;font-weight:700;color:#475569;margin-right:4px;">Costing:</span>
            <label><input type="checkbox" value="has_selling"  onchange="applyFilters()"> Has Selling Price</label>
            <label><input type="checkbox" value="has_gp"       onchange="applyFilters()"> Has GP</label>
        </div>
        <div class="filter-cats" style="margin-top:6px;">
            <span style="font-size:12px;font-weight:700;color:#475569;margin-right:4px;">Timeline:</span>
            <label><input type="checkbox" value="has_target"   onchange="applyFilters()"> Has Target Dates</label>
            <label><input type="checkbox" value="has_mandays"  onchange="applyFilters()"> Has Target Man-Days</label>
        </div>
        <div class="filter-cats" style="margin-top:6px;">
            <span style="font-size:12px;font-weight:700;color:#475569;margin-right:4px;">Resources:</span>
            <label><input type="checkbox" value="has_pm"       onchange="applyFilters()"> Project Mgmt: Yes</label>
            <label><input type="checkbox" value="has_acc_mgr"  onchange="applyFilters()"> Has Account Manager</label>
            <label><input type="checkbox" value="has_partner"  onchange="applyFilters()"> Has Partner</label>
        </div>
    </div>

    <div class="card">
    <div class="tbl-outer" id="tbl-outer">
        <div class="tbl-wrap" id="tbl-wrap">
        <table id="main-table">
            <thead>
                <tr class="sec-row">
                    <th class="s-base"    colspan="3">IIPS Details</th>
                    <th class="s-costing" colspan="4">IIPS Costing</th>
                    <th class="s-timeline" colspan="3">Timeline — Target</th>
                    <th class="s-actual"   colspan="3">Timeline — Actual (Timesheet)</th>
                    <th class="s-status"   colspan="3">Status</th>
                    <th class="s-res"      colspan="6">Resources</th>
                    <th class="s-act" rowspan="2">Actions</th>
                </tr>
                <tr>
                    <th><div class="sort-wrap">IIPS ID<button class="sort-btn" onclick="toggleSort(event,'s-pid')"></button><div id="s-pid" class="sort-menu"><a href="#" onclick="sortT(0,'alpha',0);return false;">Default</a><a href="#" onclick="sortT(0,'alpha',1);return false;">A → Z</a><a href="#" onclick="sortT(0,'alpha',2);return false;">Z → A</a></div></div></th>
                    <th><div class="sort-wrap">IIPS Name<button class="sort-btn" onclick="toggleSort(event,'s-name')"></button><div id="s-name" class="sort-menu"><a href="#" onclick="sortT(1,'alpha',0);return false;">Default</a><a href="#" onclick="sortT(1,'alpha',1);return false;">A → Z</a><a href="#" onclick="sortT(1,'alpha',2);return false;">Z → A</a></div></div></th>
                    <th><div class="sort-wrap">Customer Name<button class="sort-btn" onclick="toggleSort(event,'s-cust')"></button><div id="s-cust" class="sort-menu"><a href="#" onclick="sortT(2,'alpha',0);return false;">Default</a><a href="#" onclick="sortT(2,'alpha',1);return false;">A → Z</a><a href="#" onclick="sortT(2,'alpha',2);return false;">Z → A</a></div></div></th>
                    <th><div class="sort-wrap">Selling Price (RM)<button class="sort-btn" onclick="toggleSort(event,'s-sp')"></button><div id="s-sp" class="sort-menu"><a href="#" onclick="sortT(3,'num',0);return false;">Default</a><a href="#" onclick="sortT(3,'num',1);return false;">Low → High</a><a href="#" onclick="sortT(3,'num',2);return false;">High → Low</a></div></div></th>
                    <th><div class="sort-wrap">Partner Cost (RM)<button class="sort-btn" onclick="toggleSort(event,'s-pc')"></button><div id="s-pc" class="sort-menu"><a href="#" onclick="sortT(4,'num',0);return false;">Default</a><a href="#" onclick="sortT(4,'num',1);return false;">Low → High</a><a href="#" onclick="sortT(4,'num',2);return false;">High → Low</a></div></div></th>
                    <th><div class="sort-wrap">Gross Profit (RM)<button class="sort-btn" onclick="toggleSort(event,'s-gp')"></button><div id="s-gp" class="sort-menu"><a href="#" onclick="sortT(5,'num',0);return false;">Default</a><a href="#" onclick="sortT(5,'num',1);return false;">Low → High</a><a href="#" onclick="sortT(5,'num',2);return false;">High → Low</a></div></div></th>
                    <th><div class="sort-wrap">Project Mgmt<button class="sort-btn" onclick="toggleSort(event,'s-pm')"></button><div id="s-pm" class="sort-menu"><a href="#" onclick="filterCol('pm','');return false;">Default (All)</a><a href="#" onclick="filterCol('pm','1');return false;">Yes</a><a href="#" onclick="filterCol('pm','0');return false;">No</a></div></div></th>
                    <th><div class="sort-wrap">Target Man-Days (hr)<button class="sort-btn" onclick="toggleSort(event,'s-tmd')"></button><div id="s-tmd" class="sort-menu"><a href="#" onclick="sortT(7,'num',0);return false;">Default</a><a href="#" onclick="sortT(7,'num',1);return false;">Low → High</a><a href="#" onclick="sortT(7,'num',2);return false;">High → Low</a></div></div></th>
                    <th><div class="sort-wrap">Target Start<button class="sort-btn" onclick="toggleSort(event,'s-tsd')"></button><div id="s-tsd" class="sort-menu"><a href="#" onclick="filterCol('tsd-year','');return false;">All Years</a><?php foreach ($target_start_years as $y): ?><a href="#" onclick="filterCol('tsd-year','<?= $y ?>');return false;"><?= $y ?></a><?php endforeach; ?></div></div></th>
                    <th><div class="sort-wrap">Target End<button class="sort-btn" onclick="toggleSort(event,'s-ted')"></button><div id="s-ted" class="sort-menu"><a href="#" onclick="filterCol('ted-year','');return false;">All Years</a><?php foreach ($target_end_years as $y): ?><a href="#" onclick="filterCol('ted-year','<?= $y ?>');return false;"><?= $y ?></a><?php endforeach; ?></div></div></th>
                    <th><div class="sort-wrap">Actual Man-Days (hr)<button class="sort-btn" onclick="toggleSort(event,'s-amd')"></button><div id="s-amd" class="sort-menu"><a href="#" onclick="sortT(10,'num',0);return false;">Default</a><a href="#" onclick="sortT(10,'num',1);return false;">Low → High</a><a href="#" onclick="sortT(10,'num',2);return false;">High → Low</a></div></div></th>
                    <th><div class="sort-wrap">Actual Start<button class="sort-btn" onclick="toggleSort(event,'s-asd')"></button><div id="s-asd" class="sort-menu"><a href="#" onclick="filterCol('asd-year','');return false;">All Years</a><?php foreach ($actual_start_years as $y): ?><a href="#" onclick="filterCol('asd-year','<?= $y ?>');return false;"><?= $y ?></a><?php endforeach; ?></div></div></th>
                    <th><div class="sort-wrap">Actual End<button class="sort-btn" onclick="toggleSort(event,'s-aed')"></button><div id="s-aed" class="sort-menu"><a href="#" onclick="filterCol('aed-year','');return false;">All Years</a><?php foreach ($actual_end_years as $y): ?><a href="#" onclick="filterCol('aed-year','<?= $y ?>');return false;"><?= $y ?></a><?php endforeach; ?></div></div></th>
                    <th><div class="sort-wrap">IIPS Status<button class="sort-btn" onclick="toggleSort(event,'s-ist')"></button><div id="s-ist" class="sort-menu"><a href="#" onclick="filterCol('iips','');return false;">Default (All)</a><a href="#" onclick="filterCol('iips','Not Quoted');return false;">Not Quoted</a><a href="#" onclick="filterCol('iips','Quoted');return false;">Quoted</a><a href="#" onclick="filterCol('iips','Not Started');return false;">Not Started</a><a href="#" onclick="filterCol('iips','In Progress');return false;">In Progress</a><a href="#" onclick="filterCol('iips','Completed');return false;">Completed</a><a href="#" onclick="filterCol('iips','Cancelled');return false;">Cancelled</a></div></div></th>
                    <th><div class="sort-wrap">Target Billing Date<button class="sort-btn" onclick="toggleSort(event,'s-tbd')"></button><div id="s-tbd" class="sort-menu"><a href="#" onclick="filterCol('tbd-year','');return false;">All Years</a><?php foreach ($billing_years as $by): ?><a href="#" onclick="filterCol('tbd-year','<?= $by ?>');return false;"><?= $by ?></a><?php endforeach; ?></div></div></th>
                    <th><div class="sort-wrap">Billing Status<button class="sort-btn" onclick="toggleSort(event,'s-bst')"></button><div id="s-bst" class="sort-menu"><a href="#" onclick="filterCol('billing','');return false;">Default (All)</a><a href="#" onclick="filterCol('billing','Not Forecasted');return false;">Not Forecasted</a><a href="#" onclick="filterCol('billing','Forecasted');return false;">Forecasted</a><a href="#" onclick="filterCol('billing','Pending');return false;">Pending</a><a href="#" onclick="filterCol('billing','Completed');return false;">Completed</a></div></div></th>
                    <th><div class="sort-wrap">Account Manager<button class="sort-btn" onclick="toggleSort(event,'s-am')"></button><div id="s-am" class="sort-menu"><a href="#" onclick="sortT(16,'alpha',0);return false;">Default</a><a href="#" onclick="sortT(16,'alpha',1);return false;">A → Z</a><a href="#" onclick="sortT(16,'alpha',2);return false;">Z → A</a></div></div></th>
                    <th><div class="sort-wrap">Account Leader<button class="sort-btn" onclick="toggleSort(event,'s-al')"></button><div id="s-al" class="sort-menu"><a href="#" onclick="sortT(17,'alpha',0);return false;">Default</a><a href="#" onclick="sortT(17,'alpha',1);return false;">A → Z</a><a href="#" onclick="sortT(17,'alpha',2);return false;">Z → A</a></div></div></th>
                    <th><div class="sort-wrap">Pre-Sales / SDM<button class="sort-btn" onclick="toggleSort(event,'s-ps')"></button><div id="s-ps" class="sort-menu"><a href="#" onclick="sortT(18,'alpha',0);return false;">Default</a><a href="#" onclick="sortT(18,'alpha',1);return false;">A → Z</a><a href="#" onclick="sortT(18,'alpha',2);return false;">Z → A</a></div></div></th>
                    <th><div class="sort-wrap">IIPS Manager<button class="sort-btn" onclick="toggleSort(event,'s-im')"></button><div id="s-im" class="sort-menu"><a href="#" onclick="sortT(19,'alpha',0);return false;">Default</a><a href="#" onclick="sortT(19,'alpha',1);return false;">A → Z</a><a href="#" onclick="sortT(19,'alpha',2);return false;">Z → A</a></div></div></th>
                    <th><div class="sort-wrap">Engineers<button class="sort-btn" onclick="toggleSort(event,'s-eng')"></button><div id="s-eng" class="sort-menu"><a href="#" onclick="sortT(20,'alpha',0);return false;">Default</a><a href="#" onclick="sortT(20,'alpha',1);return false;">A → Z</a><a href="#" onclick="sortT(20,'alpha',2);return false;">Z → A</a></div></div></th>
                    <th><div class="sort-wrap">Partner<button class="sort-btn" onclick="toggleSort(event,'s-par')"></button><div id="s-par" class="sort-menu"><a href="#" onclick="sortT(21,'alpha',0);return false;">Default</a><a href="#" onclick="sortT(21,'alpha',1);return false;">A → Z</a><a href="#" onclick="sortT(21,'alpha',2);return false;">Z → A</a></div></div></th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="23" style="text-align:center;padding:40px;color:#9ca3af;">No projects yet. Click "+ Create IIPS" to add one.</td></tr>
            <?php else: foreach ($rows as $r):
                $pid_display = fmtPid($r['project_id']);
                $gp  = floatval($r['gross_profit'] ?? 0);
                $gp_color = $gp > 0 ? '#166534' : ($gp < 0 ? '#dc2626' : '#6b7280');

                $status_badge = ['Not Quoted'=>'b-nq','Quoted'=>'b-q','Not Started'=>'b-ns','In Progress'=>'b-ip','Completed'=>'b-done','Cancelled'=>'b-can'][$r['iips_status'] ?? 'Not Quoted'] ?? 'b-nq';
                $billing_badge = ['Not Forecasted'=>'b-nf','Forecasted'=>'b-fc','Pending'=>'b-pend','Completed'=>'b-bdc'][$r['billing_status'] ?? 'Not Forecasted'] ?? 'b-nf';

                $pm_display = $r['project_manager'] ?: null;
                $partner_display = $r['partner'] ?: null;
            ?>
            <tr data-iips-status="<?= htmlspecialchars($r['iips_status'] ?? '') ?>"
                data-billing-status="<?= htmlspecialchars($r['billing_status'] ?? '') ?>"
                data-has-pm="<?= intval($r['has_project_mgmt'] ?? 0) ?>"
                data-ts-start="<?= htmlspecialchars($r['ts_start'] ?? '') ?>"
                data-ts-end="<?= htmlspecialchars($r['ts_end'] ?? '') ?>"
                data-target-start="<?= htmlspecialchars($r['target_start_date'] ?? '') ?>"
                data-target-end="<?= htmlspecialchars($r['target_end_date'] ?? '') ?>"
                data-has-ts="<?= $r['ts_minutes'] > 0 ? '1' : '0' ?>"
                data-tbd-year="<?= $r['target_billing_date'] ? date('Y', strtotime($r['target_billing_date'])) : '' ?>"
                data-tsd-year="<?= $r['target_start_date']   ? date('Y', strtotime($r['target_start_date']))   : '' ?>"
                data-ted-year="<?= $r['target_end_date']     ? date('Y', strtotime($r['target_end_date']))     : '' ?>"
                data-asd-year="<?= $r['ts_start']            ? date('Y', strtotime($r['ts_start']))            : '' ?>"
                data-aed-year="<?= $r['ts_end']              ? date('Y', strtotime($r['ts_end']))              : '' ?>"
                data-has-selling="<?= ($r['selling_price'] !== null && $r['selling_price'] > 0) ? '1' : '0' ?>"
                data-has-target="<?= (!empty($r['target_start_date']) || !empty($r['target_end_date'])) ? '1' : '0' ?>"
                data-has-gp="<?= ($r['gross_profit'] !== null && floatval($r['gross_profit']) > 0) ? '1' : '0' ?>"
                data-has-acc-mgr="<?= !empty($r['account_manager']) ? '1' : '0' ?>"
                data-has-partner="<?= !empty($r['partner']) ? '1' : '0' ?>"
                data-has-mandays="<?= (!empty($r['target_mandays']) && $r['target_mandays'] > 0) ? '1' : '0' ?>"
                data-not-quoted="<?= ($r['iips_status'] ?? '') === 'Not Quoted' ? '1' : '0' ?>"
                data-cancelled="<?= ($r['iips_status'] ?? '') === 'Cancelled' ? '1' : '0' ?>"
                data-not-started="<?= ($r['iips_status'] ?? '') === 'Not Started' ? '1' : '0' ?>"
                data-quoted="<?= ($r['iips_status'] ?? '') === 'Quoted' ? '1' : '0' ?>"
                data-billing-fc="<?= ($r['billing_status'] ?? '') === 'Forecasted' ? '1' : '0' ?>"
                data-billing-nf="<?= ($r['billing_status'] ?? '') === 'Not Forecasted' ? '1' : '0' ?>">
                <td><code style="font-size:12px;"><?= $pid_display ?></code></td>
                <td><strong><?= htmlspecialchars($r['project_name']) ?></strong></td>
                <td style="font-size:12px;"><?= htmlspecialchars($r['customer_name']) ?></td>
                <td class="bg-manual"><?= $r['selling_price'] !== null ? 'RM '.number_format($r['selling_price'],2) : '<span class="dash">—</span>' ?></td>
                <td class="bg-manual"><?= $r['partner_cost']  !== null ? 'RM '.number_format($r['partner_cost'],2)  : '<span class="dash">—</span>' ?></td>
                <td class="bg-calc" style="font-weight:700; color:<?= $gp_color ?>"><?= ($r['selling_price'] !== null && $r['partner_cost'] !== null) ? 'RM '.number_format($gp,2) : '<span class="dash">—</span>' ?></td>
                <td class="bg-manual">
                    <?php $pm_val = intval($r['has_project_mgmt'] ?? 0); ?>
                    <div class="tog"><div class="tog-track <?= $pm_val ? 'on' : '' ?>"><div class="tog-thumb"></div></div><span class="tog-lbl <?= $pm_val ? 'yes' : '' ?>"><?= $pm_val ? 'Yes' : 'No' ?></span></div>
                </td>
                <td class="bg-manual"><?php if ($r['target_mandays']) { $td_total_mins = round(floatval($r['target_mandays']) * 60); $td_h = floor($td_total_mins / 60); $td_m = $td_total_mins % 60; echo $td_h.'h '.$td_m.'m'; } else { echo '<span class="dash">—</span>'; } ?></td>
                <td class="bg-auto" style="white-space:nowrap;"><span class="auto-val"><?= $r['target_start_date'] ? fmtDate($r['target_start_date']) : '<span class="dash">—</span>' ?></span></td>
                <td class="bg-auto" style="white-space:nowrap;"><span class="auto-val"><?= $r['target_end_date']   ? fmtDate($r['target_end_date'])   : '<span class="dash">—</span>' ?></span></td>
                <td class="bg-auto"><span class="auto-val"><?= $r['ts_minutes'] > 0 ? fmtMins($r['ts_minutes']) : '<span class="dash">—</span>' ?></span></td>
                <td class="bg-auto" style="white-space:nowrap;"><span class="auto-val"><?= $r['ts_start'] ? fmtDate($r['ts_start']) : '<span class="dash">—</span>' ?></span></td>
                <td class="bg-auto" style="white-space:nowrap;"><span class="auto-val"><?php if (($r['iips_status'] ?? '') === 'Completed'): ?><?= $r['ts_end'] ? fmtDate($r['ts_end']) : '<span class="dash">—</span>' ?><?php else: ?><span class="dash">—</span><?php endif; ?></span></td>
                <td class="bg-dropdown"><span class="badge <?= $status_badge ?>"><?= htmlspecialchars($r['iips_status'] ?? 'Not Quoted') ?></span></td>
                <td class="bg-manual"><?= $r['target_billing_date'] ? fmtDate($r['target_billing_date']) : '<span class="dash">—</span>' ?></td>
                <td class="bg-dropdown"><span class="badge <?= $billing_badge ?>"><?= htmlspecialchars($r['billing_status'] ?? 'Not Forecasted') ?></span></td>
                <td><?= $r['account_manager'] ? htmlspecialchars($r['account_manager']) : '<span class="dash">—</span>' ?></td>
                <td><?= $r['account_leader']  ? htmlspecialchars($r['account_leader'])  : '<span class="dash">—</span>' ?></td>
                <td><?= $r['presales_sdm']    ? htmlspecialchars($r['presales_sdm'])    : '<span class="dash">—</span>' ?></td>
                <td><?= $pm_display           ? htmlspecialchars($pm_display)           : '<span class="dash">—</span>' ?></td>
                <td class="bg-auto"><?php if ($r['ts_engineers']): ?><ul style="margin:0; padding-left:16px; font-size:11px; color:#065f46; line-height:1.8;"><?php foreach (explode(', ', $r['ts_engineers']) as $eng): ?><li><?= htmlspecialchars(trim($eng)) ?></li><?php endforeach; ?></ul><?php else: ?><span class="dash">—</span><?php endif; ?></td>
                <td><?= $partner_display      ? htmlspecialchars($partner_display)      : '<span class="dash">—</span>' ?></td>
                <td><a href="admin_iips.php?edit_proj=<?= urlencode($r['project_id']) ?>" class="btn-edit">Edit</a><a href="admin_iips.php?delete_proj=<?= urlencode($r['project_id']) ?>" class="btn-del" onclick="return confirm('Delete project <?= htmlspecialchars(addslashes($r['project_id'])) ?>?\nThis cannot be undone.')">Delete</a></td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    </div>
    </div>
</div>

<script>
function fixStickyHeaders() {
    const secRow = document.querySelector('#main-table thead tr.sec-row');
    const colRow = document.querySelector('#main-table thead tr:last-child');
    if (secRow && colRow) {
        const h = secRow.getBoundingClientRect().height;
        colRow.querySelectorAll('th').forEach(th => th.style.top = h + 'px');
    }
}
window.addEventListener('DOMContentLoaded', fixStickyHeaders);
window.addEventListener('resize', fixStickyHeaders);
document.getElementById('search-input').addEventListener('input', applyFilters);

function applyFilters() {
    const txt      = document.getElementById('search-input').value.toLowerCase();
    const tgtStart = document.getElementById('filter-target-start-hidden').value;
    const tgtEnd   = document.getElementById('filter-target-end-hidden').value;
    const actStart = document.getElementById('filter-actual-start-hidden').value;
    const actEnd   = document.getElementById('filter-actual-end-hidden').value;
    const checks   = Array.from(document.querySelectorAll('.filter-cats input[type="checkbox"]:checked')).map(c => c.value);

    const groups = {
        iips_status: ['not_quoted','quoted','not_started','in_progress','completed','cancelled'],
        billing:     ['billing_nf','billing_fc','billing_pending','billing_done'],
        timesheet:   ['has_data','no_data'],
        costing:     ['has_selling','has_gp','gp_neg'],
        timeline:    ['has_target','has_mandays'],
    };

    const activeGroups = {};
    Object.entries(groups).forEach(([grp, vals]) => {
        const active = vals.filter(v => checks.includes(v));
        if (active.length > 0) activeGroups[grp] = active;
    });

    const resFilters = ['has_pm','has_acc_mgr','has_partner'].filter(v => checks.includes(v));

    document.querySelectorAll('#main-table tbody tr').forEach(tr => {
        const d        = tr.dataset;
        const text     = tr.textContent.toLowerCase();
        const tsStart  = d.tsStart || '';
        const tsEnd    = d.tsEnd   || '';
        const tarStart = d.targetStart || '';
        const tarEnd   = d.targetEnd || '';

        let ok = true;
        if (txt      && !text.includes(txt))     ok = false;
        if (actStart && tsStart < actStart)      ok = false;
        if (actEnd   && tsEnd   > actEnd)        ok = false;
        if (tgtStart && tarStart < tgtStart)     ok = false;
        if (tgtEnd   && tarEnd   > tgtEnd)       ok = false;

        if (ok && Object.keys(activeGroups).length > 0) {
            Object.entries(activeGroups).forEach(([grp, active]) => {
                let groupMatch = false;
                active.forEach(chk => {
                    if (chk === 'not_quoted'     && d.notQuoted    === '1') groupMatch = true;
                    if (chk === 'quoted'         && d.quoted       === '1') groupMatch = true;
                    if (chk === 'not_started'    && d.notStarted   === '1') groupMatch = true;
                    if (chk === 'in_progress'    && (d.iipsStatus||'').toLowerCase() === 'in progress')  groupMatch = true;
                    if (chk === 'completed'      && (d.iipsStatus||'').toLowerCase() === 'completed')    groupMatch = true;
                    if (chk === 'cancelled'      && d.cancelled    === '1') groupMatch = true;
                    if (chk === 'billing_nf'     && d.billingNf    === '1') groupMatch = true;
                    if (chk === 'billing_fc'     && d.billingFc    === '1') groupMatch = true;
                    if (chk === 'billing_pending'&& (d.billingStatus||'').toLowerCase() === 'pending')   groupMatch = true;
                    if (chk === 'billing_done'   && (d.billingStatus||'').toLowerCase() === 'completed') groupMatch = true;
                    if (chk === 'has_data'       && d.hasTs        === '1') groupMatch = true;
                    if (chk === 'no_data'        && d.hasTs        === '0') groupMatch = true;
                    if (chk === 'has_selling'    && d.hasSelling   === '1') groupMatch = true;
                    if (chk === 'has_gp'         && d.hasGp        === '1') groupMatch = true;
                    if (chk === 'gp_neg'         && d.gpNeg        === '1') groupMatch = true;
                    if (chk === 'has_target'     && d.hasTarget    === '1') groupMatch = true;
                    if (chk === 'has_mandays'    && d.hasMandays   === '1') groupMatch = true;
                });
                if (!groupMatch) ok = false;
            });
        }
        if (ok && resFilters.length > 0) {
            resFilters.forEach(chk => {
                if (chk === 'has_pm'      && d.hasPm      !== '1') ok = false;
                if (chk === 'has_acc_mgr' && d.hasAccMgr  !== '1') ok = false;
                if (chk === 'has_partner' && d.hasPartner !== '1') ok = false;
            });
        }
        tr.classList.toggle('is-hidden', !ok);
    });
    document.querySelectorAll('.filter-cats label').forEach(lbl => { lbl.classList.toggle('active-cat', lbl.querySelector('input').checked); });
}

function clearAllFilters() {
    document.getElementById('search-input').value = '';
    document.getElementById('filter-target-start-display').value = '';
    document.getElementById('filter-target-start-hidden').value = '';
    document.getElementById('filter-target-end-display').value = '';
    document.getElementById('filter-target-end-hidden').value = '';
    document.getElementById('filter-actual-start-display').value = '';
    document.getElementById('filter-actual-start-hidden').value = '';
    document.getElementById('filter-actual-end-display').value = '';
    document.getElementById('filter-actual-end-hidden').value = '';
    document.querySelectorAll('.filter-cats input[type="checkbox"]').forEach(c => c.checked = false);
    document.querySelectorAll('.filter-cats label').forEach(l => l.classList.remove('active-cat'));
    colFilters = { pm: '', iips: '', billing: '', 'tbd-year': '', 'tsd-year': '', 'ted-year': '', 'asd-year': '', 'aed-year': '' };
    document.querySelectorAll('#main-table tbody tr').forEach(tr => tr.classList.remove('is-hidden'));
}

const F_MONTHS = ['JAN','FEB','MAR','APR','MAY','JUN','JUL','AUG','SEP','OCT','NOV','DEC'];
const F_MONTHS_SHORT = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

function syncFilterDate(type) {
    const hidden  = document.getElementById('filter-' + type + '-hidden');
    const display = document.getElementById('filter-' + type + '-display');
    if (hidden.value) {
        const p = hidden.value.split('-');
        display.value = p[2] + '-' + F_MONTHS_SHORT[parseInt(p[1],10)-1].toUpperCase() + '-' + p[0];
    } else { display.value = ''; }
    applyFilters();
}

function parseFilerDate(type) {
    const display = document.getElementById('filter-' + type + '-display');
    const hidden  = document.getElementById('filter-' + type + '-hidden');
    const str = display.value.trim().toUpperCase();
    if (!str) { hidden.value = ''; applyFilters(); return; }
    const parts = str.split(/[\-\/\. ]+/);
    if (parts.length === 3) {
        let d = parts[0].padStart(2,'0');
        let m = parts[1];
        let y = parts[2].length === 2 ? '20' + parts[2] : parts[2];
        if (isNaN(m)) {
            const mIdx = F_MONTHS.findIndex(x => m.startsWith(x.substring(0,3)));
            if (mIdx !== -1) m = String(mIdx+1).padStart(2,'0');
        } else { m = String(m).padStart(2,'0'); }
        if (d.length===2 && m.length===2 && y.length===4) {
            hidden.value = y + '-' + m + '-' + d;
            syncFilterDate(type);
            return;
        }
    }
    if (hidden.value) syncFilterDate(type); else display.value = '';
    applyFilters();
}

let colFilters = { pm: '', iips: '', billing: '', 'tbd-year': '', 'tsd-year': '', 'ted-year': '', 'asd-year': '', 'aed-year': '' };
function filterCol(type, value) {
    colFilters[type] = value;
    document.querySelectorAll('.sort-menu').forEach(m => m.classList.remove('show-sort'));
    applyColFilters();
}
function applyColFilters() {
    document.querySelectorAll('#main-table tbody tr').forEach(tr => {
        let ok = true;
        if (colFilters.pm      !== '' && tr.dataset.hasPm         !== colFilters.pm)           ok = false;
        if (colFilters.iips    !== '' && (tr.dataset.iipsStatus    || '') !== colFilters.iips)   ok = false;
        if (colFilters.billing !== '' && (tr.dataset.billingStatus || '') !== colFilters.billing) ok = false;
        if (colFilters['tbd-year'] !== '' && (tr.dataset.tbdYear || '') !== colFilters['tbd-year']) ok = false;
        if (colFilters['tsd-year'] !== '' && (tr.dataset.tsdYear || '') !== colFilters['tsd-year']) ok = false;
        if (colFilters['ted-year'] !== '' && (tr.dataset.tedYear || '') !== colFilters['ted-year']) ok = false;
        if (colFilters['asd-year'] !== '' && (tr.dataset.asdYear || '') !== colFilters['asd-year']) ok = false;
        if (colFilters['aed-year'] !== '' && (tr.dataset.aedYear || '') !== colFilters['aed-year']) ok = false;
        tr.classList.toggle('is-hidden', !ok);
    });
}

let origRows = null;
function toggleSort(e, id) { e.stopPropagation(); document.querySelectorAll('.sort-menu').forEach(m => { if(m.id!==id) m.classList.remove('show-sort'); }); document.getElementById(id).classList.toggle('show-sort'); }
window.addEventListener('click', () => document.querySelectorAll('.sort-menu').forEach(m => m.classList.remove('show-sort')));
function sortT(col, type, dir) {
    const tbody = document.querySelector('#main-table tbody');
    const rows  = Array.from(tbody.querySelectorAll('tr'));
    if (!origRows) origRows = [...rows];
    if (dir === 0) { origRows.forEach(r => tbody.appendChild(r)); return; }
    rows.sort((a, b) => {
        const ca = a.cells[col] ? a.cells[col].textContent.trim() : '';
        const cb = b.cells[col] ? b.cells[col].textContent.trim() : '';
        if (type === 'alpha') return dir===1 ? ca.localeCompare(cb) : cb.localeCompare(ca);
        if (type === 'num') {
            const na = parseFloat(ca.replace(/[^0-9.\-]/g,'')) || 0;
            const nb = parseFloat(cb.replace(/[^0-9.\-]/g,'')) || 0;
            return dir===1 ? na-nb : nb-na;
        }
        if (type === 'date') {
            const months = {Jan:1,Feb:2,Mar:3,Apr:4,May:5,Jun:6,Jul:7,Aug:8,Sep:9,Oct:10,Nov:11,Dec:12};
            function parseDate(s) {
                const m = s.match(/(\d+)-([A-Za-z]+)-(\d+)/);
                if (!m) return 0;
                return new Date(m[3], (months[m[2]]||1)-1, m[1]).getTime();
            }
            return dir===1 ? parseDate(ca)-parseDate(cb) : parseDate(cb)-parseDate(ca);
        }
        return 0;
    });
    rows.forEach(r => tbody.appendChild(r));
}
</script>
</body>
</html>