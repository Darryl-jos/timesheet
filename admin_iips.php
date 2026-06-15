<?php
require_once 'config.php';

if (!isset($_SESSION['engineer_id']) || !isset($_SESSION['is_admin']) || ($_SESSION['is_admin'] != 1 && $_SESSION['is_admin'] != 2)) {
    header("Location: login.php"); exit;
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['bulk_action']) && $_POST['bulk_action'] === 'delete') {
    if (isset($_POST['selected_iips']) && is_array($_POST['selected_iips'])) {
        $err_projs = [];
        foreach ($_POST['selected_iips'] as $del_id) {
            $chk = $conn->prepare("SELECT COUNT(*) as total FROM timesheets WHERE project_id=?");
            $chk->bind_param("s", $del_id); $chk->execute();
            $cnt = $chk->get_result()->fetch_assoc(); $chk->close();
            if ($cnt['total'] > 0) {
                $err_projs[] = $del_id;
            } else {
                $d1 = $conn->prepare("DELETE FROM iips_tracking WHERE project_id=?");
                $d1->bind_param("s", $del_id); $d1->execute(); $d1->close();
                $d2 = $conn->prepare("DELETE FROM projects WHERE project_id=?");
                $d2->bind_param("s", $del_id); $d2->execute(); $d2->close();
            }
        }
        if (!empty($err_projs)) {
            $error = "Could not delete: <code>" . implode(', ', array_map('htmlspecialchars', $err_projs)) . "</code> because they have linked timesheet logs. Other selected items were deleted.";
        } else {
            header("Location: admin_iips.php"); exit;
        }
    }
}

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
        i.selling_price, i.partner_cost, i.internal_cost, i.gross_profit,
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
    if (!$pid) return '<span class="dash">—</span>';
    if (preg_match('/^N[\/.\-]?A/i', $pid)) return '<span class="dash">—</span>';
    return htmlspecialchars($pid);
}
function cleanNames($raw) {
    // Split comma-separated names, drop blanks and any "N/A" variants
    $names = array_filter(array_map('trim', explode(',', $raw ?? '')), function($n) {
        return $n !== '' && !preg_match('/^N[\.\/\-]?A$/i', $n);
    });
    return $names;
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
    body { font-family: Arial, sans-serif; margin: 16px; background: #f4f7f6; color: #333; padding-bottom: 20px; }
    .header { display: flex; justify-content: space-between; align-items: center; background: #343a40; padding: 15px 20px; border-radius: 8px; color: white; flex-wrap: wrap; gap: 10px; margin-bottom: 16px; box-shadow: 0 2px 8px rgba(0,0,0,0.15); }
    .header h2 { margin: 0; font-size: 18px; }
    .header a { color: #ffc107; font-weight: bold; text-decoration: none; font-size: 13px; }
    .page { padding: 20px; }
    .card { background: white; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); margin-top: 0; }
    .card-hdr { padding: 16px 25px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center; background: white; border-top-left-radius: 8px; border-top-right-radius: 8px; }
    .card-hdr h3 { margin: 0; font-size: 16px; color: #1f2937; }
    @media (max-width: 768px) { body { margin: 0; } .page { padding: 10px; } .header { padding: 12px 14px; border-radius: 0; } .header h2 { font-size: 15px; } .card { padding: 0; } .card-hdr, .tbl-outer { padding: 12px; } }    .btn-create-iips { display: inline-flex; align-items: center; gap: 6px; background: #28a745; color: white; text-decoration: none; font-size: 13px; font-weight: 700; padding: 0 16px; height: 38px; border-radius: 6px; white-space: nowrap; border: none; cursor: pointer; }
    .btn-create-iips:hover { background: #218838; color: white; }

    /* ── Unified Filter Panel ── */
    .filter-panel { background: white; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); padding: 14px 16px; margin-bottom: 12px; }
    .filter-row { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
    .filter-row + .filter-row { margin-top: 10px; padding-top: 10px; border-top: 1px dashed #e5e7eb; }
    .filter-label { font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; white-space: nowrap; }
    .filter-input { flex: 1; min-width: 200px; height: 38px; padding: 0 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; background: #fff; color: #333; transition: border-color .15s; }
    .filter-input:focus { border-color: #007bff; outline: none; box-shadow: 0 0 0 2px rgba(0,123,255,.1); }
    .date-wrap { position: relative; height: 34px; display: flex; flex: 0 0 160px; width: 160px; }
    .iips-date-wrap { position: relative; height: 34px; display: flex; flex: 0 0 155px; width: 155px; }
    .iips-date-wrap input[type="text"] { width: 100%; height: 100%; padding: 0 32px 0 8px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 12px; text-transform: uppercase; background: #fff; color: #333; box-sizing: border-box; }
    .iips-date-wrap input[type="text"]:focus { border-color: #007bff; outline: none; }
    .iips-date-wrap input[type="date"] { position: absolute; top: 0; right: 0; width: 32px; height: 100%; opacity: 0; cursor: pointer; z-index: 5; }
    .iips-cal-btn { position: absolute; right: 0; top: 0; width: 32px; height: 100%; display: flex; align-items: center; justify-content: center; font-size: 13px; cursor: pointer; z-index: 4; }
    .date-wrap input[type="text"] { flex: 1; height: 100%; padding: 0 36px 0 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; text-transform: uppercase; background: #fff; color: #333; }
    .date-wrap input[type="text"]:focus { border-color: #007bff; outline: none; box-shadow: 0 0 0 2px rgba(0,123,255,.1); }
    .date-wrap .cal-btn { position: absolute; right: 0; top: 0; width: 36px; height: 100%; display: flex; align-items: center; justify-content: center; font-size: 14px; pointer-events: none; }
    .date-wrap input[type="date"] { position: absolute; top: 0; right: 0; width: 36px; height: 100%; opacity: 0; cursor: pointer; z-index: 5; }
    .date-sep { font-size: 12px; color: #94a3b8; font-weight: 600; white-space: nowrap; }
    .btn-clear-filter { background: #f1f5f9; color: #475569; border: 1px solid #d1d5db; height: 38px; padding: 0 14px; border-radius: 6px; font-size: 13px; font-weight: 700; cursor: pointer; white-space: nowrap; }
    .btn-clear-filter:hover { background: #e2e8f0; }
    .btn-adv-toggle { background: none; border: 1px solid #d1d5db; color: #475569; height: 34px; padding: 0 12px; border-radius: 6px; font-size: 12px; font-weight: 700; cursor: pointer; white-space: nowrap; display: flex; align-items: center; gap: 5px; }
    .btn-adv-toggle:hover { background: #f0f7ff; border-color: #007bff; color: #007bff; }
    .btn-adv-toggle .arr { font-size: 10px; transition: transform .2s; }
    .adv-filters { display: none; margin-top: 10px; padding-top: 10px; border-top: 1px dashed #e5e7eb; }
    .adv-filters.open { display: block; }
    .adv-group { margin-bottom: 10px; }
    .adv-group-label { font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; margin-bottom: 6px; display: block; }
    .filter-cats { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }
    .filter-cats label { display: flex; align-items: center; gap: 4px; font-size: 12px; color: #334155; cursor: pointer; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 3px 10px; user-select: none; }
    .filter-cats label:hover { background: #e0f2fe; border-color: #7dd3fc; }
    .filter-cats input[type="checkbox"] { width: 13px; height: 13px; cursor: pointer; }
    .filter-cats label.active-cat { background: #1d4ed8; color: white; border-color: #1d4ed8; }
    .active-filter-count { display: inline-flex; align-items: center; justify-content: center; background: #dc3545; color: white; border-radius: 10px; font-size: 10px; font-weight: 700; min-width: 17px; height: 17px; padding: 0 4px; margin-left: 2px; }
    .alert-err { background:#f8d7da; color:#721c24; padding:12px; border-radius:4px; margin-bottom:15px; border:1px solid #f5c6cb; font-size:13px; }

    @media (max-width: 600px) {
        body { margin: 10px; }
        .page { padding: 0; }
        .header { padding: 12px 14px; border-radius: 8px; }
        .header h2 { font-size: 15px; }
        .filter-panel { padding: 10px 12px; }
        .filter-row { flex-direction: column; align-items: stretch; gap: 6px; }
        .filter-row + .filter-row { margin-top: 8px; padding-top: 8px; }
        .filter-input { min-width: unset; width: 100%; }
        .btn-create-iips { width: 100%; justify-content: center; }
        .iips-date-wrap { flex: 1 1 auto; width: 100%; }
        .date-sep { text-align: center; }
        .filter-label { margin-left: 0 !important; }
        div[style*="margin-left:auto"] { margin-left: 0 !important; width: 100%; }
        .btn-adv-toggle, .btn-clear-filter { width: 100%; justify-content: center; height: 40px; font-size: 14px; }
        .adv-filters > div { grid-template-columns: 1fr !important; }
    }
    
    #bulk-toolbar { display: none; background: #e6f0ff; border: 1px solid #b8daff; border-radius: 6px; padding: 10px 15px; margin-bottom: 12px; align-items: center; gap: 10px; flex-wrap: wrap; position: sticky; top: 8px; z-index: 100; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
    #bulk-toolbar span { font-size: 13px; font-weight: 600; color: #1e40af; flex: 1; }
    .btn-bulk { border: none; padding: 7px 14px; border-radius: 4px; font-size: 12px; cursor: pointer; font-weight: bold; }
    .btn-export { background: #28a745; color: white; }
    .btn-bulk-del { background: #dc3545; color: white; }
    .btn-desel { background: #e2e8f0; color: #374151; }
    .btn-export-all { background: #6c757d; color: white; border: none; padding: 8px 16px; border-radius: 4px; font-size: 13px; font-weight: bold; cursor: pointer; transition: background 0.2s; }
    .btn-export-all.filtered { background: #17a2b8; box-shadow: 0 2px 5px rgba(23,162,184,0.3); }

    .tbl-outer { position: relative; padding: 0 25px 25px 25px; }
    .tbl-wrap { overflow-x: auto; overflow-y: auto; max-height: 70vh; -webkit-overflow-scrolling: touch; }
    .tbl-wrap::-webkit-scrollbar { width: 10px; height: 8px; }
    .tbl-wrap::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 5px; }
    .tbl-wrap::-webkit-scrollbar-thumb { background: #94a3b8; border-radius: 5px; }
    .tbl-wrap::-webkit-scrollbar-thumb:hover { background: #64748b; }
    table { width: 100%; border-collapse: collapse; min-width: 1850px; }
    th, td { padding: 10px 12px; border-bottom: 1px solid #dee2e6; text-align: left; font-size: 13px; white-space: nowrap; }
    th { font-weight: bold; color: #495057; background: #f8f9fa; }
    tbody tr:hover { background: #f8faff; }
    .sec-row th { text-align: center; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; padding: 5px 8px; color: white; border: 1px solid rgba(255,255,255,0.15); position: sticky; top: 0; z-index: 22; }
    thead tr:last-child th { position: sticky; top: 27px; z-index: 21; background: #f8f9fa; box-shadow: 0 2px 0 #dee2e6; }
    
    .chk-col { width: 36px; position: sticky; left: 0; z-index: 23; background: #ffffff; border-right: 1px solid #dee2e6; }
    tbody tr:hover td.chk-col { background: #f8faff; }
    tbody tr td.chk-col { background: #ffffff; }
    thead tr:last-child th.chk-col { z-index: 25; background: #f8f9fa; }
    thead tr.sec-row th.chk-col { z-index: 26; background: #343a40; }

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
    @media (max-width: 768px) { body { margin: 0; } .page { padding: 10px; } .header { padding: 10px; } .card { padding: 0; } .card-hdr, .tbl-outer { padding: 12px; } }
</style>
</head>
<body>

<div class="header">
    <h2>📋 IIPS List</h2>
    <a href="admin.php">← Back to Admin Home</a>
</div>

<div class="page">
    <?php if ($error): ?>
        <div class="alert-err">⚠️ <?= $error ?></div>
    <?php endif; ?>

    <!-- ── Unified Filter Panel ── -->
    <div class="filter-panel">
        <!-- Row 1: Search + Create button -->
        <div class="filter-row">
            <span class="filter-label">🔍</span>
            <input type="text" class="filter-input" id="search-input" placeholder="Search IIPS ID, name, customer, manager, partner..." oninput="applyFilters()">
            <a href="create_iips.php" class="btn-create-iips">+ Create IIPS</a>
        </div>
        <!-- Row 2: Date filters -->
        <div class="filter-row">
            <span class="filter-label">🎯 Target</span>
            <div class="iips-date-wrap">
                <input type="text" id="filter-target-start-display" placeholder="DD MMM YYYY" autocomplete="off"
                       oninput="this.value=this.value.toUpperCase()"
                       onblur="parseFilerDate('target-start')" onkeydown="if(event.key==='Enter'){this.blur()}">
                <div class="iips-cal-btn" onclick="document.getElementById('filter-target-start-hidden').showPicker()">📅</div>
                <input type="date" id="filter-target-start-hidden" onchange="syncFilterDate('target-start')">
            </div>
            <span class="date-sep">→</span>
            <div class="iips-date-wrap">
                <input type="text" id="filter-target-end-display" placeholder="DD MMM YYYY" autocomplete="off"
                       oninput="this.value=this.value.toUpperCase()"
                       onblur="parseFilerDate('target-end')" onkeydown="if(event.key==='Enter'){this.blur()}">
                <div class="iips-cal-btn" onclick="document.getElementById('filter-target-end-hidden').showPicker()">📅</div>
                <input type="date" id="filter-target-end-hidden" onchange="syncFilterDate('target-end')">
            </div>
            <span class="filter-label" style="margin-left:12px;">📌 Actual</span>
            <div class="iips-date-wrap">
                <input type="text" id="filter-actual-start-display" placeholder="DD MMM YYYY" autocomplete="off"
                       oninput="this.value=this.value.toUpperCase()"
                       onblur="parseFilerDate('actual-start')" onkeydown="if(event.key==='Enter'){this.blur()}">
                <div class="iips-cal-btn" onclick="document.getElementById('filter-actual-start-hidden').showPicker()">📅</div>
                <input type="date" id="filter-actual-start-hidden" onchange="syncFilterDate('actual-start')">
            </div>
            <span class="date-sep">→</span>
            <div class="iips-date-wrap">
                <input type="text" id="filter-actual-end-display" placeholder="DD MMM YYYY" autocomplete="off"
                       oninput="this.value=this.value.toUpperCase()"
                       onblur="parseFilerDate('actual-end')" onkeydown="if(event.key==='Enter'){this.blur()}">
                <div class="iips-cal-btn" onclick="document.getElementById('filter-actual-end-hidden').showPicker()">📅</div>
                <input type="date" id="filter-actual-end-hidden" onchange="syncFilterDate('actual-end')">
            </div>
            <div style="margin-left:auto; display:flex; gap:8px; align-items:center;">
                <button type="button" class="btn-adv-toggle" id="adv-toggle-btn" onclick="toggleAdvFilters()">
                    Advanced <span class="arr" id="adv-arr">▾</span><span class="active-filter-count" id="adv-count" style="display:none">0</span>
                </button>
                <button class="btn-clear-filter" onclick="clearAllFilters()">✕ Clear All</button>
            </div>
        </div>
        <!-- Advanced: Status / Billing / Timesheet / Costing / Timeline checkboxes -->
        <div class="adv-filters" id="adv-filters">
            <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 10px;">
                <div class="adv-group">
                    <span class="adv-group-label">IIPS Status</span>
                    <div class="filter-cats">
                        <label><input type="checkbox" value="not_quoted"  onchange="applyFilters()"> Not Quoted</label>
                        <label><input type="checkbox" value="quoted"      onchange="applyFilters()"> Quoted</label>
                        <label><input type="checkbox" value="not_started" onchange="applyFilters()"> Not Started</label>
                        <label><input type="checkbox" value="in_progress" onchange="applyFilters()"> In Progress</label>
                        <label><input type="checkbox" value="completed"   onchange="applyFilters()"> Completed</label>
                        <label><input type="checkbox" value="cancelled"   onchange="applyFilters()"> Cancelled</label>
                    </div>
                </div>
                <div class="adv-group">
                    <span class="adv-group-label">Billing Status</span>
                    <div class="filter-cats">
                        <label><input type="checkbox" value="billing_nf"      onchange="applyFilters()"> Not Forecasted</label>
                        <label><input type="checkbox" value="billing_fc"      onchange="applyFilters()"> Forecasted</label>
                        <label><input type="checkbox" value="billing_pending" onchange="applyFilters()"> Pending</label>
                        <label><input type="checkbox" value="billing_done"    onchange="applyFilters()"> Completed</label>
                    </div>
                </div>
                <div class="adv-group">
                    <span class="adv-group-label">Timesheet Data</span>
                    <div class="filter-cats">
                        <label><input type="checkbox" value="has_data" onchange="applyFilters()"> Has Data</label>
                        <label><input type="checkbox" value="no_data"  onchange="applyFilters()"> No Data</label>
                    </div>
                </div>
                <div class="adv-group">
                    <span class="adv-group-label">IIPS Costing</span>
                    <div class="filter-cats">
                        <label><input type="checkbox" value="cost_sp_only" onchange="applyFilters()"> Selling Price Only</label>
                        <label><input type="checkbox" value="cost_pc_only" onchange="applyFilters()"> Partner Cost Only</label>
                        <label><input type="checkbox" value="cost_empty"   onchange="applyFilters()"> Empty</label>
                        <label><input type="checkbox" value="has_ic" onchange="applyFilters()"> Has Internal Cost</label>
                        <label><input type="checkbox" value="no_ic"  onchange="applyFilters()"> No Internal Cost</label>
                    </div>
                </div>
                <div class="adv-group">
                    <span class="adv-group-label">Target Timeline Data</span>
                    <div class="filter-cats">
                        <label><input type="checkbox" value="tgt_yes"     onchange="applyFilters()"> Yes</label>
                        <label><input type="checkbox" value="tgt_partial" onchange="applyFilters()"> Partial</label>
                        <label><input type="checkbox" value="tgt_no"      onchange="applyFilters()"> No</label>
                    </div>
                </div>
                <div class="adv-group">
                    <span class="adv-group-label">Actual Timeline Data</span>
                    <div class="filter-cats">
                        <label><input type="checkbox" value="act_yes"     onchange="applyFilters()"> Yes</label>
                        <label><input type="checkbox" value="act_partial" onchange="applyFilters()"> Partial</label>
                        <label><input type="checkbox" value="act_no"      onchange="applyFilters()"> No</label>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- ── End Filter Panel ── -->

    <div id="bulk-toolbar">
        <span id="bulk-count">0 selected</span>
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <button type="button" class="btn-bulk btn-export" onclick="submitBulkExport()">📥 Export Selected</button>
            <button type="button" class="btn-bulk btn-bulk-del" onclick="submitBulkDelete()">🗑 Delete Selected</button>
            <button type="button" class="btn-bulk btn-desel" onclick="deselectAll()">✕ Deselect All</button>
        </div>
    </div>

    <form id="bulk-form" method="POST" action="admin_iips.php">
        <input type="hidden" name="bulk_action" id="bulk-action-field" value="">
        
        <div class="card">
            <div class="card-hdr">
                <h3>IIPS Database</h3>
                <button type="button" id="btn-export-all" class="btn-export-all" onclick="exportFilteredOrAll()">📥 Export All</button>
            </div>
            
            <div class="tbl-outer" id="tbl-outer">
                <div class="tbl-wrap" id="tbl-wrap">
                <table id="main-table">
                    <thead>
                        <tr class="sec-row">
                            <th class="chk-col s-base" rowspan="2" style="z-index:25;"><input type="checkbox" id="chk-all" onchange="toggleAll(this)"></th>
                            <th class="s-base"    colspan="3">IIPS Details</th>
                            <th class="s-costing" colspan="7">IIPS Costing</th>
                            <th class="s-timeline" colspan="3">Target Timeline</th>
                            <th class="s-actual"   colspan="3">Actual Timeline</th>
                            <th class="s-status"   colspan="3">Status</th>
                            <th class="s-res"      colspan="6">Resources</th>
                            <th class="s-act" rowspan="2">Actions</th>
                        </tr>
                        <tr>
                            <th><div class="sort-wrap">IIPS ID<button type="button" class="sort-btn" onclick="toggleSort(event,'s-pid')"></button><div id="s-pid" class="sort-menu"><a href="#" onclick="sortT(1,'alpha',0);return false;">Default</a><a href="#" onclick="sortT(1,'alpha',1);return false;">A → Z</a><a href="#" onclick="sortT(1,'alpha',2);return false;">Z → A</a></div></div></th>
                            <th><div class="sort-wrap">IIPS Name<button type="button" class="sort-btn" onclick="toggleSort(event,'s-name')"></button><div id="s-name" class="sort-menu"><a href="#" onclick="sortT(2,'alpha',0);return false;">Default</a><a href="#" onclick="sortT(2,'alpha',1);return false;">A → Z</a><a href="#" onclick="sortT(2,'alpha',2);return false;">Z → A</a></div></div></th>
                            <th><div class="sort-wrap">Customer Name<button type="button" class="sort-btn" onclick="toggleSort(event,'s-cust')"></button><div id="s-cust" class="sort-menu"><a href="#" onclick="sortT(3,'alpha',0);return false;">Default</a><a href="#" onclick="sortT(3,'alpha',1);return false;">A → Z</a><a href="#" onclick="sortT(3,'alpha',2);return false;">Z → A</a></div></div></th>
                            <th><div class="sort-wrap">Selling Price (RM)<button type="button" class="sort-btn" onclick="toggleSort(event,'s-sp')"></button><div id="s-sp" class="sort-menu"><a href="#" onclick="sortT(4,'num',0);return false;">Default</a><a href="#" onclick="sortT(4,'num',1);return false;">Low → High</a><a href="#" onclick="sortT(4,'num',2);return false;">High → Low</a></div></div></th>
                            <th><div class="sort-wrap">Partner Cost (RM)<button type="button" class="sort-btn" onclick="toggleSort(event,'s-pc')"></button><div id="s-pc" class="sort-menu"><a href="#" onclick="sortT(5,'num',0);return false;">Default</a><a href="#" onclick="sortT(5,'num',1);return false;">Low → High</a><a href="#" onclick="sortT(5,'num',2);return false;">High → Low</a></div></div></th>
                            <th><div class="sort-wrap">GP without Internal Cost (RM)<button type="button" class="sort-btn" onclick="toggleSort(event,'s-gpwo')"></button><div id="s-gpwo" class="sort-menu"><a href="#" onclick="sortT(6,'num',0);return false;">Default</a><a href="#" onclick="sortT(6,'num',1);return false;">Low → High</a><a href="#" onclick="sortT(6,'num',2);return false;">High → Low</a></div></div></th>
                            <th><div class="sort-wrap">Internal Cost (RM)<button type="button" class="sort-btn" onclick="toggleSort(event,'s-ic')"></button><div id="s-ic" class="sort-menu"><a href="#" onclick="sortT(7,'num',0);return false;">Default</a><a href="#" onclick="sortT(7,'num',1);return false;">Low → High</a><a href="#" onclick="sortT(7,'num',2);return false;">High → Low</a></div></div></th>
                            <th><div class="sort-wrap">Actual GP (RM)<button type="button" class="sort-btn" onclick="toggleSort(event,'s-gp')"></button><div id="s-gp" class="sort-menu"><a href="#" onclick="sortT(8,'num',0);return false;">Default</a><a href="#" onclick="sortT(8,'num',1);return false;">Low → High</a><a href="#" onclick="sortT(8,'num',2);return false;">High → Low</a></div></div></th>
                            <th><div class="sort-wrap">GP %<button type="button" class="sort-btn" onclick="toggleSort(event,'s-gppct')"></button><div id="s-gppct" class="sort-menu"><a href="#" onclick="sortT(9,'num',0);return false;">Default</a><a href="#" onclick="sortT(9,'num',1);return false;">Low → High</a><a href="#" onclick="sortT(9,'num',2);return false;">High → Low</a></div></div></th>
                            <th><div class="sort-wrap">Project Mgmt<button type="button" class="sort-btn" onclick="toggleSort(event,'s-pm')"></button><div id="s-pm" class="sort-menu"><a href="#" onclick="filterCol('pm','');return false;">Default (All)</a><a href="#" onclick="filterCol('pm','1');return false;">Yes</a><a href="#" onclick="filterCol('pm','0');return false;">No</a></div></div></th>
                            <th><div class="sort-wrap">Target Man-Days (hr)<button type="button" class="sort-btn" onclick="toggleSort(event,'s-tmd')"></button><div id="s-tmd" class="sort-menu"><a href="#" onclick="sortT(11,'num',0);return false;">Default</a><a href="#" onclick="sortT(11,'num',1);return false;">Low → High</a><a href="#" onclick="sortT(11,'num',2);return false;">High → Low</a></div></div></th>
                            <th><div class="sort-wrap">Target Start<button type="button" class="sort-btn" onclick="toggleSort(event,'s-tsd')"></button><div id="s-tsd" class="sort-menu"><a href="#" onclick="filterCol('tsd-year','');return false;">All Years</a><?php foreach ($target_start_years as $y): ?><a href="#" onclick="filterCol('tsd-year','<?= $y ?>');return false;"><?= $y ?></a><?php endforeach; ?></div></div></th>
                            <th><div class="sort-wrap">Target End<button type="button" class="sort-btn" onclick="toggleSort(event,'s-ted')"></button><div id="s-ted" class="sort-menu"><a href="#" onclick="filterCol('ted-year','');return false;">All Years</a><?php foreach ($target_end_years as $y): ?><a href="#" onclick="filterCol('ted-year','<?= $y ?>');return false;"><?= $y ?></a><?php endforeach; ?></div></div></th>
                            <th><div class="sort-wrap">Actual Man-Days (hr)<button type="button" class="sort-btn" onclick="toggleSort(event,'s-amd')"></button><div id="s-amd" class="sort-menu"><a href="#" onclick="sortT(14,'num',0);return false;">Default</a><a href="#" onclick="sortT(14,'num',1);return false;">Low → High</a><a href="#" onclick="sortT(14,'num',2);return false;">High → Low</a></div></div></th>
                            <th><div class="sort-wrap">Actual Start<button type="button" class="sort-btn" onclick="toggleSort(event,'s-asd')"></button><div id="s-asd" class="sort-menu"><a href="#" onclick="filterCol('asd-year','');return false;">All Years</a><?php foreach ($actual_start_years as $y): ?><a href="#" onclick="filterCol('asd-year','<?= $y ?>');return false;"><?= $y ?></a><?php endforeach; ?></div></div></th>
                            <th><div class="sort-wrap">Actual End<button type="button" class="sort-btn" onclick="toggleSort(event,'s-aed')"></button><div id="s-aed" class="sort-menu"><a href="#" onclick="filterCol('aed-year','');return false;">All Years</a><?php foreach ($actual_end_years as $y): ?><a href="#" onclick="filterCol('aed-year','<?= $y ?>');return false;"><?= $y ?></a><?php endforeach; ?></div></div></th>
                            <th><div class="sort-wrap">IIPS Status<button type="button" class="sort-btn" onclick="toggleSort(event,'s-ist')"></button><div id="s-ist" class="sort-menu"><a href="#" onclick="filterCol('iips','');return false;">Default (All)</a><a href="#" onclick="filterCol('iips','Not Quoted');return false;">Not Quoted</a><a href="#" onclick="filterCol('iips','Quoted');return false;">Quoted</a><a href="#" onclick="filterCol('iips','Not Started');return false;">Not Started</a><a href="#" onclick="filterCol('iips','In Progress');return false;">In Progress</a><a href="#" onclick="filterCol('iips','Completed');return false;">Completed</a><a href="#" onclick="filterCol('iips','Cancelled');return false;">Cancelled</a></div></div></th>
                            <th><div class="sort-wrap">Target Billing Date<button type="button" class="sort-btn" onclick="toggleSort(event,'s-tbd')"></button><div id="s-tbd" class="sort-menu"><a href="#" onclick="filterCol('tbd-year','');return false;">All Years</a><?php foreach ($billing_years as $by): ?><a href="#" onclick="filterCol('tbd-year','<?= $by ?>');return false;"><?= $by ?></a><?php endforeach; ?></div></div></th>
                            <th><div class="sort-wrap">Billing Status<button type="button" class="sort-btn" onclick="toggleSort(event,'s-bst')"></button><div id="s-bst" class="sort-menu"><a href="#" onclick="filterCol('billing','');return false;">Default (All)</a><a href="#" onclick="filterCol('billing','Not Forecasted');return false;">Not Forecasted</a><a href="#" onclick="filterCol('billing','Forecasted');return false;">Forecasted</a><a href="#" onclick="filterCol('billing','Pending');return false;">Pending</a><a href="#" onclick="filterCol('billing','Completed');return false;">Completed</a></div></div></th>
                            <th><div class="sort-wrap">Account Manager<button type="button" class="sort-btn" onclick="toggleSort(event,'s-am')"></button><div id="s-am" class="sort-menu"><a href="#" onclick="sortT(20,'alpha',0);return false;">Default</a><a href="#" onclick="sortT(20,'alpha',1);return false;">A → Z</a><a href="#" onclick="sortT(20,'alpha',2);return false;">Z → A</a></div></div></th>
                            <th><div class="sort-wrap">Account Leader<button type="button" class="sort-btn" onclick="toggleSort(event,'s-al')"></button><div id="s-al" class="sort-menu"><a href="#" onclick="sortT(21,'alpha',0);return false;">Default</a><a href="#" onclick="sortT(21,'alpha',1);return false;">A → Z</a><a href="#" onclick="sortT(21,'alpha',2);return false;">Z → A</a></div></div></th>
                            <th><div class="sort-wrap">Pre-Sales / SDM<button type="button" class="sort-btn" onclick="toggleSort(event,'s-ps')"></button><div id="s-ps" class="sort-menu"><a href="#" onclick="sortT(22,'alpha',0);return false;">Default</a><a href="#" onclick="sortT(22,'alpha',1);return false;">A → Z</a><a href="#" onclick="sortT(22,'alpha',2);return false;">Z → A</a></div></div></th>
                            <th><div class="sort-wrap">Project Manager<button type="button" class="sort-btn" onclick="toggleSort(event,'s-im')"></button><div id="s-im" class="sort-menu"><a href="#" onclick="sortT(23,'alpha',0);return false;">Default</a><a href="#" onclick="sortT(23,'alpha',1);return false;">A → Z</a><a href="#" onclick="sortT(23,'alpha',2);return false;">Z → A</a></div></div></th>
                            <th><div class="sort-wrap">Engineers<button type="button" class="sort-btn" onclick="toggleSort(event,'s-eng')"></button><div id="s-eng" class="sort-menu"><a href="#" onclick="sortT(24,'alpha',0);return false;">Default</a><a href="#" onclick="sortT(24,'alpha',1);return false;">A → Z</a><a href="#" onclick="sortT(24,'alpha',2);return false;">Z → A</a></div></div></th>
                            <th><div class="sort-wrap">Partner<button type="button" class="sort-btn" onclick="toggleSort(event,'s-par')"></button><div id="s-par" class="sort-menu"><a href="#" onclick="sortT(25,'alpha',0);return false;">Default</a><a href="#" onclick="sortT(25,'alpha',1);return false;">A → Z</a><a href="#" onclick="sortT(25,'alpha',2);return false;">Z → A</a></div></div></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="27" style="text-align:center;padding:40px;color:#9ca3af;">No projects yet. Click "+ Create IIPS" to add one.</td></tr>
                    <?php else: foreach ($rows as $r):
                        $pid_display = fmtPid($r['project_id']);
                        $sp_raw = $r['selling_price'];
                        $pc_raw = $r['partner_cost'];
                        $ic_raw = $r['internal_cost'];
                        $gp_wo  = ($sp_raw !== null && $pc_raw !== null) ? floatval($sp_raw) - floatval($pc_raw) : null;
                        $gp     = ($gp_wo !== null) ? $gp_wo - floatval($ic_raw ?? 0) : null;
                        $gp_pct = ($gp !== null && floatval($sp_raw) > 0) ? ($gp / floatval($sp_raw)) * 100 : null;
                        $gp_wo_color = ($gp_wo ?? 0) > 0 ? '#166534' : (($gp_wo ?? 0) < 0 ? '#dc2626' : '#6b7280');
                        $gp_color    = ($gp ?? 0) > 0 ? '#166534' : (($gp ?? 0) < 0 ? '#dc2626' : '#6b7280');

                        $status_badge = ['Not Quoted'=>'b-nq','Quoted'=>'b-q','Not Started'=>'b-ns','In Progress'=>'b-ip','Completed'=>'b-done','Cancelled'=>'b-can'][$r['iips_status'] ?? 'Not Quoted'] ?? 'b-nq';
                        $billing_badge = ['Not Forecasted'=>'b-nf','Forecasted'=>'b-fc','Pending'=>'b-pend','Completed'=>'b-bdc'][$r['billing_status'] ?? 'Not Forecasted'] ?? 'b-nf';

                        $pm_display = $r['project_manager'] ?: null;
                        $partner_display = $r['partner'] ?: null;

                        $sp = floatval($r['selling_price'] ?? 0);
                        $pc = floatval($r['partner_cost'] ?? 0);
                        $cost_state = 'both';
                        if ($sp > 0 && $pc <= 0) $cost_state = 'cost_sp_only';
                        elseif ($pc > 0 && $sp <= 0) $cost_state = 'cost_pc_only';
                        elseif ($sp <= 0 && $pc <= 0) $cost_state = 'cost_empty';

                        $has_t_start = !empty($r['target_start_date']);
                        $has_t_end = !empty($r['target_end_date']);
                        $tgt_state = 'tgt_no';
                        if ($has_t_start && $has_t_end) $tgt_state = 'tgt_yes';
                        elseif ($has_t_start || $has_t_end) $tgt_state = 'tgt_partial';

                        $has_a_start = !empty($r['ts_start']);
                        $has_a_end = !empty($r['ts_end']);
                        $act_state = 'act_no';
                        if ($has_a_start && $has_a_end) $act_state = 'act_yes';
                        elseif ($has_a_start || $has_a_end) $act_state = 'act_partial';
                    ?>
                    <tr data-pid="<?= htmlspecialchars($r['project_id']) ?>"
                        data-iips-status="<?= htmlspecialchars($r['iips_status'] ?? '') ?>"
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
                        data-cost-state="<?= $cost_state ?>"
                        data-has-ic="<?= ($ic_raw !== null && floatval($ic_raw) > 0) ? '1' : '0' ?>"
                        data-tgt-state="<?= $tgt_state ?>"
                        data-act-state="<?= $act_state ?>"
                        data-has-acc-mgr="<?= !empty($r['account_manager']) ? '1' : '0' ?>"
                        data-has-partner="<?= !empty($r['partner']) ? '1' : '0' ?>"
                        data-not-quoted="<?= ($r['iips_status'] ?? '') === 'Not Quoted' ? '1' : '0' ?>"
                        data-cancelled="<?= ($r['iips_status'] ?? '') === 'Cancelled' ? '1' : '0' ?>"
                        data-not-started="<?= ($r['iips_status'] ?? '') === 'Not Started' ? '1' : '0' ?>"
                        data-quoted="<?= ($r['iips_status'] ?? '') === 'Quoted' ? '1' : '0' ?>"
                        data-billing-fc="<?= ($r['billing_status'] ?? '') === 'Forecasted' ? '1' : '0' ?>"
                        data-billing-nf="<?= ($r['billing_status'] ?? '') === 'Not Forecasted' ? '1' : '0' ?>">
                        <td class="chk-col"><input type="checkbox" class="iips-chk" name="selected_iips[]" value="<?= htmlspecialchars($r['project_id']) ?>" onchange="onChkChange()"></td>
                        <td><code style="font-size:12px;"><?= $pid_display ?></code></td>
                        <td><strong><?= htmlspecialchars($r['project_name']) ?></strong></td>
                        <td style="font-size:12px;"><?= htmlspecialchars($r['customer_name']) ?></td>
                        <td class="bg-manual"><?= $r['selling_price'] !== null ? 'RM '.number_format($r['selling_price'],2) : '<span class="dash">—</span>' ?></td>
                        <td class="bg-manual"><?= $r['partner_cost']  !== null ? 'RM '.number_format($r['partner_cost'],2)  : '<span class="dash">—</span>' ?></td>
                        <td class="bg-calc" style="font-weight:700; color:<?= $gp_wo_color ?>"><?= $gp_wo !== null ? 'RM '.number_format($gp_wo,2) : '<span class="dash">—</span>' ?></td>
                        <td class="bg-manual"><?= $ic_raw !== null ? 'RM '.number_format($ic_raw,2) : '<span class="dash">—</span>' ?></td>
                        <td class="bg-calc" style="font-weight:700; color:<?= $gp_color ?>"><?= $gp !== null ? 'RM '.number_format($gp,2) : '<span class="dash">—</span>' ?></td>
                        <td class="bg-calc" style="font-weight:700; color:<?= $gp_color ?>"><?= $gp_pct !== null ? number_format($gp_pct,1).'%' : '<span class="dash">—</span>' ?></td>
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
                        <td><?php
                            $names = cleanNames($r['account_manager']);
                            if ($names): ?><ul style="margin:0;padding-left:16px;font-size:11px;line-height:1.8;"><?php foreach($names as $n): ?><li><?= htmlspecialchars($n) ?></li><?php endforeach; ?></ul><?php else: ?><span class="dash">—</span><?php endif; ?></td>
                        <td><?php
                            $names = cleanNames($r['account_leader']);
                            if ($names): ?><ul style="margin:0;padding-left:16px;font-size:11px;line-height:1.8;"><?php foreach($names as $n): ?><li><?= htmlspecialchars($n) ?></li><?php endforeach; ?></ul><?php else: ?><span class="dash">—</span><?php endif; ?></td>
                        <td><?php
                            $names = cleanNames($r['presales_sdm']);
                            if ($names): ?><ul style="margin:0;padding-left:16px;font-size:11px;line-height:1.8;"><?php foreach($names as $n): ?><li><?= htmlspecialchars($n) ?></li><?php endforeach; ?></ul><?php else: ?><span class="dash">—</span><?php endif; ?></td>
                        <td><?php
                            $names = cleanNames($pm_display);
                            if ($names): ?><ul style="margin:0;padding-left:16px;font-size:11px;line-height:1.8;"><?php foreach($names as $n): ?><li><?= htmlspecialchars($n) ?></li><?php endforeach; ?></ul><?php else: ?><span class="dash">—</span><?php endif; ?></td>
                        <td class="bg-auto"><?php if ($r['ts_engineers']): ?><ul style="margin:0; padding-left:16px; font-size:11px; color:#065f46; line-height:1.8;"><?php foreach (explode(', ', $r['ts_engineers']) as $eng): ?><li><?= htmlspecialchars(trim($eng)) ?></li><?php endforeach; ?></ul><?php else: ?><span class="dash">—</span><?php endif; ?></td>
                        <td><?php
                            $names = cleanNames($partner_display);
                            if ($names): ?><ul style="margin:0;padding-left:16px;font-size:11px;line-height:1.8;"><?php foreach($names as $n): ?><li><?= htmlspecialchars($n) ?></li><?php endforeach; ?></ul><?php else: ?><span class="dash">—</span><?php endif; ?></td>
                        <td><a href="admin_iips.php?edit_proj=<?= urlencode($r['project_id']) ?>" class="btn-edit">Edit</a><a href="admin_iips.php?delete_proj=<?= urlencode($r['project_id']) ?>" class="btn-del" onclick="return confirm('Delete project <?= htmlspecialchars(addslashes($r['project_id'])) ?>?\nThis cannot be undone.')">Delete</a></td>
                    </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
// ---------- bulk UI logic ----------
function onChkChange() {
    const checked = document.querySelectorAll('.iips-chk:checked').length;
    const toolbar = document.getElementById('bulk-toolbar');
    toolbar.style.display = checked > 0 ? 'flex' : 'none';
    document.getElementById('bulk-count').textContent = checked + ' selected';
    
    const visibleCheckboxes = document.querySelectorAll('#main-table tbody tr:not(.is-hidden) .iips-chk');
    let visibleCheckedCount = 0;
    visibleCheckboxes.forEach(c => { if(c.checked) visibleCheckedCount++; });
    
    document.getElementById('chk-all').indeterminate = visibleCheckedCount > 0 && visibleCheckedCount < visibleCheckboxes.length;
    document.getElementById('chk-all').checked = visibleCheckedCount > 0 && visibleCheckedCount === visibleCheckboxes.length;
}

function toggleAll(cb) {
    document.querySelectorAll('#main-table tbody tr:not(.is-hidden) .iips-chk').forEach(c => c.checked = cb.checked);
    onChkChange();
}

function deselectAll() {
    document.querySelectorAll('.iips-chk').forEach(c => c.checked = false);
    document.getElementById('chk-all').checked = false;
    document.getElementById('bulk-toolbar').style.display = 'none';
}

function submitBulkDelete() {
    if (confirm('⚠️ Permanently delete selected IIPS?\n(Projects with linked timesheets will be skipped automatically)')) {
        document.getElementById('bulk-action-field').value = 'delete';
        const form = document.getElementById('bulk-form');
        form.action = 'admin_iips.php';
        form.submit();
    }
}

function submitBulkExport() {
    const form = document.getElementById('bulk-form');
    form.action = 'export_iips.php';
    document.getElementById('bulk-action-field').value = 'export_selected';
    form.submit();
    form.action = 'admin_iips.php';
}

function exportFilteredOrAll() {
    const form = document.getElementById('bulk-form');
    form.action = 'export_iips.php';
    document.getElementById('bulk-action-field').value = 'export_filtered';
    
    document.querySelectorAll('.dyn-iips').forEach(e => e.remove());

    document.querySelectorAll('#main-table tbody tr:not(.is-hidden)').forEach(tr => {
        const pid = tr.dataset.pid;
        if (pid) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'selected_iips[]';
            input.value = pid;
            input.className = 'dyn-iips';
            form.appendChild(input);
        }
    });
    
    form.submit();
    form.action = 'admin_iips.php';
    document.querySelectorAll('.dyn-iips').forEach(e => e.remove());
}


// ---------- Table logic ----------
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

let colFilters = { pm: '', iips: '', billing: '', 'tbd-year': '', 'tsd-year': '', 'ted-year': '', 'asd-year': '', 'aed-year': '' };

function applyFilters() {
    const txt      = document.getElementById('search-input').value.toLowerCase();
    const tgtStart = document.getElementById('filter-target-start-hidden').value;
    const tgtEnd   = document.getElementById('filter-target-end-hidden').value;
    const actStart = document.getElementById('filter-actual-start-hidden').value;
    const actEnd   = document.getElementById('filter-actual-end-hidden').value;
    const checks   = Array.from(document.querySelectorAll('.filter-cats input[type="checkbox"]:checked')).map(c => c.value);

    const groups = {
        iips_status:     ['not_quoted','quoted','not_started','in_progress','completed','cancelled'],
        billing:         ['billing_nf','billing_fc','billing_pending','billing_done'],
        timesheet:       ['has_data','no_data'],
        costing:         ['cost_sp_only','cost_pc_only','cost_empty','has_ic','no_ic'],
        target_timeline: ['tgt_yes','tgt_partial','tgt_no'],
        actual_timeline: ['act_yes','act_partial','act_no']
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

                    if (chk === 'cost_sp_only'   && d.costState    === 'cost_sp_only') groupMatch = true;
                    if (chk === 'cost_pc_only'   && d.costState    === 'cost_pc_only') groupMatch = true;
                    if (chk === 'cost_empty'     && d.costState    === 'cost_empty')   groupMatch = true;
                    if (chk === 'has_ic'         && d.hasIc        === '1')            groupMatch = true;
                    if (chk === 'no_ic'          && d.hasIc        === '0')            groupMatch = true;
                    
                    if (chk === 'tgt_yes'        && d.tgtState     === 'tgt_yes')      groupMatch = true;
                    if (chk === 'tgt_partial'    && d.tgtState     === 'tgt_partial')  groupMatch = true;
                    if (chk === 'tgt_no'         && d.tgtState     === 'tgt_no')       groupMatch = true;
                    
                    if (chk === 'act_yes'        && d.actState     === 'act_yes')      groupMatch = true;
                    if (chk === 'act_partial'    && d.actState     === 'act_partial')  groupMatch = true;
                    if (chk === 'act_no'         && d.actState     === 'act_no')       groupMatch = true;
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

        if (ok) {
            if (colFilters.pm      !== '' && d.hasPm         !== colFilters.pm)                      ok = false;
            if (colFilters.iips    !== '' && (d.iipsStatus    || '') !== colFilters.iips)             ok = false;
            if (colFilters.billing !== '' && (d.billingStatus || '') !== colFilters.billing)          ok = false;
            if (colFilters['tbd-year'] !== '' && (d.tbdYear || '') !== colFilters['tbd-year'])        ok = false;
            if (colFilters['tsd-year'] !== '' && (d.tsdYear || '') !== colFilters['tsd-year'])        ok = false;
            if (colFilters['ted-year'] !== '' && (d.tedYear || '') !== colFilters['ted-year'])        ok = false;
            if (colFilters['asd-year'] !== '' && (d.asdYear || '') !== colFilters['asd-year'])        ok = false;
            if (colFilters['aed-year'] !== '' && (d.aedYear || '') !== colFilters['aed-year'])        ok = false;
        }

        tr.classList.toggle('is-hidden', !ok);
    });
    
    document.querySelectorAll('.filter-cats label').forEach(lbl => { lbl.classList.toggle('active-cat', lbl.querySelector('input').checked); });
    updateAdvCount();

    // Handle "Export Filtered" Button UI
    let hasFilter = (txt !== '' || tgtStart !== '' || tgtEnd !== '' || actStart !== '' || actEnd !== '' || checks.length > 0 || Object.values(colFilters).some(v => v !== ''));
    const btnExport = document.getElementById('btn-export-all');
    if (hasFilter) {
        btnExport.textContent = '📥 Export Filtered';
        btnExport.classList.add('filtered');
    } else {
        btnExport.textContent = '📥 Export All';
        btnExport.classList.remove('filtered');
    }

    document.getElementById('chk-all').checked = false;
    onChkChange();
}

function toggleAdvFilters() {
    const panel = document.getElementById('adv-filters');
    const arr   = document.getElementById('adv-arr');
    panel.classList.toggle('open');
    arr.textContent = panel.classList.contains('open') ? '▴' : '▾';
}

function updateAdvCount() {
    const count = document.querySelectorAll('.filter-cats input[type="checkbox"]:checked').length;
    const badge = document.getElementById('adv-count');
    badge.textContent = count;
    badge.style.display = count > 0 ? 'inline-flex' : 'none';
    if (count > 0 && !document.getElementById('adv-filters').classList.contains('open')) {
        document.getElementById('adv-filters').classList.add('open');
        document.getElementById('adv-arr').textContent = '▴';
    }
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
    updateAdvCount();
    applyFilters();
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

function filterCol(type, value) {
    colFilters[type] = value;
    document.querySelectorAll('.sort-menu').forEach(m => m.classList.remove('show-sort'));
    applyFilters(); 
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