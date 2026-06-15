<?php
require_once 'config.php';

if (!isset($_SESSION['engineer_id'])) {
    header("Location: login.php");
    exit;
}

$current_user_id   = $_SESSION['engineer_id'];
$current_user_name = $_SESSION['engineer_name'];

if (isset($_GET['delete'])) {
    $delete_id = intval($_GET['delete']);
    $stmt = $conn->prepare("DELETE FROM timesheets WHERE id = ? AND engineer_id = ?");
    $stmt->bind_param("ii", $delete_id, $current_user_id);
    $stmt->execute();
    header("Location: index.php");
    exit;
}

$filter_start = isset($_GET['start']) ? trim($_GET['start']) : '';
$filter_end   = isset($_GET['end'])   ? trim($_GET['end'])   : '';

if (!empty($filter_start) && !empty($filter_end)) {
    $sql = "SELECT t.*, p.project_name, p.customer_name, p.estimate_time
            FROM timesheets t
            JOIN projects p ON t.project_id = p.project_id
            WHERE t.engineer_id = ? AND t.start_date >= ? AND t.start_date <= ?
            ORDER BY t.id DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iss", $current_user_id, $filter_start, $filter_end);
} elseif (!empty($filter_start)) {
    $sql = "SELECT t.*, p.project_name, p.customer_name, p.estimate_time
            FROM timesheets t
            JOIN projects p ON t.project_id = p.project_id
            WHERE t.engineer_id = ? AND t.start_date >= ?
            ORDER BY t.id DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("is", $current_user_id, $filter_start);
} elseif (!empty($filter_end)) {
    $sql = "SELECT t.*, p.project_name, p.customer_name, p.estimate_time
            FROM timesheets t
            JOIN projects p ON t.project_id = p.project_id
            WHERE t.engineer_id = ? AND t.start_date <= ?
            ORDER BY t.id DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("is", $current_user_id, $filter_end);
} else {
    $sql = "SELECT t.*, p.project_name, p.customer_name, p.estimate_time
            FROM timesheets t
            JOIN projects p ON t.project_id = p.project_id
            WHERE t.engineer_id = ?
            ORDER BY t.id DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $current_user_id);
}

$stmt->execute();
$result = $stmt->get_result();

$proj_list_res = $conn->query("SELECT project_id, project_name, customer_name FROM projects ORDER BY project_id ASC");

$total_records = 0;
$total_minutes = 0;
$rows_cache = [];
while ($row = $result->fetch_assoc()) {
    $start = new DateTime($row['start_date'] . ' ' . $row['start_time']);
    $end   = new DateTime($row['end_date']   . ' ' . $row['end_time']);
    if ($end <= $start) $end->modify('+1 day');
    $diff = $start->diff($end);
    $mins = ($diff->days * 24 * 60) + ($diff->h * 60) + $diff->i;
    
    $mb = isset($row['meal_breaks']) ? intval($row['meal_breaks']) : 0;
    $mins -= ($mb * 60);
    if ($mins < 0) {
        $mins = 0;
    }

    $row['_minutes'] = $mins;
    $total_minutes += $mins;
    $total_records++;
    $rows_cache[] = $row;
}

function fmtDate($d) {
    if (!$d) return '-';
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt ? $dt->format('d M Y') : $d;
}
function fmtDateDisplay($d) {
    if (!$d) return '';
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    if (!$dt) return '';
    $months = ['JAN','FEB','MAR','APR','MAY','JUN','JUL','AUG','SEP','OCT','NOV','DEC'];
    return intval($dt->format('d')) . ' ' . $months[intval($dt->format('m'))-1] . ' ' . $dt->format('Y');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>My Timesheet Dashboard</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, sans-serif; margin: 16px; background: #f4f7f6; color: #333; padding-bottom: 20px; }
        
        .topbar { background: #343a40; padding: 15px 20px; display: flex; border-radius: 8px; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; margin-bottom: 16px; box-shadow: 0 2px 8px rgba(0,0,0,0.15); }
        .topbar h2 { color: white; margin: 0; font-size: 18px; }
        .topbar .nav { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
        .topbar a { color: #ffc107; text-decoration: none; font-size: 13px; padding: 6px 12px; border-radius: 4px; font-weight: bold; transition: background 0.2s, color 0.2s; }
        .topbar a:hover { background: rgba(255, 193, 7, 0.15); color: #ffda6a; }
        .topbar a.logout-btn { color: #ef4444; }
        .topbar a.logout-btn:hover { background: rgba(239, 68, 68, 0.15); color: #f87171; }
        .page { padding: 20px; }
        .stats-bar { display: flex; gap: 12px; margin-bottom: 20px; flex-wrap: wrap; }
        .stat { background: white; border-radius: 8px; padding: 14px 18px; box-shadow: 0 1px 3px rgba(0,0,0,0.07); flex: 1; min-width: 130px; border-top: 3px solid #007bff; }
        .stat.green { border-top-color: #28a745; }
        .stat-label { font-size: 11px; color: #64748b; text-transform: uppercase; font-weight: 600; }
        .stat-value { font-size: 22px; font-weight: 700; margin-top: 2px; }
        .btn-create { display: inline-block; background: #007bff; color: white; text-decoration: none; padding: 11px 22px; border-radius: 5px; font-weight: bold; font-size: 15px; margin-bottom: 16px; width: 100%; text-align: center; }
        .btn-create:hover { background: #0056b3; }
        .date-range-bar { background: white; padding: 12px; border-radius: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); display: flex; gap: 8px; margin-bottom: 8px; flex-wrap: wrap; align-items: center; }
        .date-range-bar .dr-label { font-size: 12px; font-weight: 700; color: #475569; white-space: nowrap; }
        .date-range-bar .date-field-wrap { display: flex; align-items: center; gap: 6px; flex: 1; min-width: 160px; position: relative; height: 38px; }
        .date-field-wrap input[type="text"] { flex: 1; height: 100%; padding: 0 36px 0 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 13px; text-transform: uppercase; background: #fff; color: #333; }
        .date-field-wrap input[type="text"]:focus { border-color: #007bff; outline: none; }
        .date-field-wrap .cal-icon { position: absolute; right: 0; top: 0; width: 36px; height: 100%; display: flex; align-items: center; justify-content: center; pointer-events: none; font-size: 14px; }
        .date-field-wrap input[type="date"] { position: absolute; top: 0; right: 0; width: 36px; height: 100%; opacity: 0; cursor: pointer; z-index: 5; }
        .btn-apply { background: #007bff; color: white; border: none; padding: 0 16px; height: 38px; border-radius: 4px; font-size: 13px; cursor: pointer; font-weight: bold; white-space: nowrap; }
        .btn-apply:hover { background: #0056b3; }
        .search-bar-wrap { background: white; padding: 12px; border-radius: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); display: flex; gap: 8px; margin-bottom: 14px; flex-wrap: wrap; align-items: center; }
        .search-bar-wrap input[type="text"] { flex: 2; min-width: 160px; height: 38px; padding: 0 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 13px; }
        .summary-card { display: none; background: #f0f7ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 14px 18px; margin-bottom: 14px; }
        .summary-card h4 { margin: 0 0 10px; font-size: 13px; color: #1e40af; font-weight: 700; }
        .sum-grid { display: flex; gap: 10px; flex-wrap: wrap; }
        .sum-item { background: white; border-radius: 6px; padding: 8px 14px; min-width: 110px; flex: 1; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
        .sum-label { font-size: 10px; color: #64748b; text-transform: uppercase; font-weight: 600; display: block; }
        .sum-value { font-size: 16px; font-weight: 700; color: #1e293b; }
        .btn-clear { background: #6c757d; color: white; border: none; padding: 0 14px; height: 38px; border-radius: 4px; font-size: 13px; cursor: pointer; font-weight: bold; white-space: nowrap; }
        .sel-wrap { flex: 2; min-width: 180px; position: relative; }
        .sel-box { height: 38px; padding: 0 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 13px; background: white; cursor: pointer; display: flex; align-items: center; justify-content: space-between; gap: 6px; user-select: none; }
        .sel-box:hover { border-color: #007bff; }
        .sel-box span:first-child { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; flex: 1; color: #333; }
        .sel-arrow { color: #6c757d; font-size: 11px; flex-shrink: 0; transition: transform .2s; }
        .sel-wrap.open .sel-arrow { transform: rotate(180deg); }
        .sel-wrap.open .sel-box { border-color: #007bff; box-shadow: 0 0 0 2px rgba(0,123,255,.12); }
        .sel-panel { display: none; position: absolute; top: calc(100% + 3px); left: 0; width: 100%; min-width: 240px; background: white; border: 1px solid #007bff; border-radius: 4px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); z-index: 200; padding: 6px; }
        .sel-wrap.open .sel-panel { display: block; }
        .sel-panel input { width: 100%; height: 32px; padding: 0 8px; border: 1px solid #ddd; border-radius: 3px; font-size: 12px; margin-bottom: 5px; box-sizing: border-box; }
        .sel-panel input:focus { border-color: #007bff; outline: none; }
        .sel-list { max-height: 220px; overflow-y: auto; }
        .sel-item { padding: 7px 10px; cursor: pointer; font-size: 13px; border-radius: 3px; line-height: 1.3; }
        .sel-item:hover { background: #f0f7ff; }
        .sel-item.active { background: #e6f0ff; color: #1d4ed8; font-weight: 600; }
        .sel-item.hidden { display: none; }
        .card { background: white; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.07); overflow: hidden; }
        .card-hdr { padding: 14px 20px; border-bottom: 1px solid #e5e7eb; font-weight: bold; font-size: 15px; display: flex; justify-content: space-between; align-items: center; }
        .tbl-wrap { overflow-x: auto; overflow-y: auto; max-height: 70vh; -webkit-overflow-scrolling: touch; }
        table { width: 100%; border-collapse: collapse; min-width: 900px; }
        th, td { padding: 11px 12px; text-align: left; font-size: 13px; border-bottom: 1px solid #f1f5f9; }
        th { background: #f8fafc; font-weight: 600; color: #475569; white-space: nowrap; }
        tbody tr:hover { background: #f8faff; }
        .is-hidden { display: none !important; }
        .act-cell { overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; white-space: pre-line; cursor: pointer; font-size: 12px; color: #555; }
        .act-cell.expanded { display: block; max-height: none; }
        .date-range { font-size: 12px; line-height: 1.6; }
        .date-range .start { color: #1d4ed8; font-weight: 600; }
        .date-range .end { color: #7c3aed; font-weight: 600; }
        .date-range .time { color: #64748b; font-size: 11px; }
        .btn-edit { background: #ffc107; color: #333; padding: 4px 10px; text-decoration: none; border-radius: 3px; font-size: 12px; font-weight: bold; }
        .btn-delete { background: #dc3545; color: white; padding: 4px 10px; text-decoration: none; border-radius: 3px; font-size: 12px; font-weight: bold; }
        .sort-wrap { display: inline-flex; align-items: center; gap: 5px; }
        .sort-btn { background: none; border: none; width: 14px; height: 14px; cursor: pointer; position: relative; padding: 0; }
        .sort-btn::before { content:""; position:absolute; top:1px; left:2px; border-left:5px solid transparent; border-right:5px solid transparent; border-bottom:5px solid #888; }
        .sort-btn::after  { content:""; position:absolute; bottom:1px; left:2px; border-left:5px solid transparent; border-right:5px solid transparent; border-top:5px solid #888; }
        .sort-btn:hover::before { border-bottom-color:#007bff; }
        .sort-btn:hover::after  { border-top-color:#007bff; }
        .sort-menu { display:none; position:absolute; top:24px; left:0; background:#fff; min-width:130px; box-shadow:0 4px 12px rgba(0,0,0,0.1); border:1px solid #e2e8f0; border-radius:4px; z-index:99; }
        .sort-menu a { display:block; padding:7px 12px; font-size:12px; color:#333; text-decoration:none; }
        .sort-menu a:hover { background:#f8fafc; color:#007bff; }
        .show-sort { display:block !important; }
        .no-data { text-align: center; padding: 50px; color: #9ca3af; font-size: 15px; }
        .sec-row th { text-align: center; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; padding: 5px 8px; color: white; border: 1px solid rgba(255,255,255,0.15); }
        thead tr:last-child th { background: #f8fafc; box-shadow: 0 2px 0 #dee2e6; }
        .s-base     { background: #343a40; }
        .s-timeline { background: #1a237e; }
        .s-perf     { background: #004d40; }
        .error-border { border: 2px solid #dc2626 !important; }

        /* ── Unified Filter Panel ── */
        .filter-panel { background: white; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); padding: 14px 16px; margin-bottom: 14px; }
        .filter-row { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
        .filter-row + .filter-row { margin-top: 10px; padding-top: 10px; border-top: 1px dashed #e5e7eb; }
        .filter-label { font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; white-space: nowrap; min-width: 36px; }
        .filter-input { flex: 2; min-width: 160px; height: 38px; padding: 0 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; background: #fff; color: #333; transition: border-color .15s; }
        .filter-input:focus { border-color: #007bff; outline: none; box-shadow: 0 0 0 2px rgba(0,123,255,.1); }
        .date-wrap { position: relative; height: 38px; display: flex; flex: 0 0 155px; width: 155px; }
        .date-wrap input[type="text"] { width: 100%; height: 100%; padding: 0 32px 0 8px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 12px; text-transform: uppercase; background: #fff; color: #333; box-sizing: border-box; }
        .date-wrap input[type="text"]:focus { border-color: #007bff; outline: none; box-shadow: 0 0 0 2px rgba(0,123,255,.1); }
        .date-wrap .cal-btn { position: absolute; right: 0; top: 0; width: 32px; height: 100%; display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 13px; z-index: 4; }
        .date-wrap input[type="date"] { position: absolute; top: 0; right: 0; width: 32px; height: 100%; opacity: 0; cursor: pointer; z-index: 5; }
        .date-sep { font-size: 12px; color: #94a3b8; font-weight: 600; white-space: nowrap; }
        .btn-apply-filter { background: #007bff; color: white; border: none; height: 38px; padding: 0 18px; border-radius: 6px; font-size: 13px; font-weight: 700; cursor: pointer; white-space: nowrap; }
        .btn-apply-filter:hover { background: #0056b3; }
        .btn-clear-filter { background: #f1f5f9; color: #475569; border: 1px solid #d1d5db; height: 38px; padding: 0 14px; border-radius: 6px; font-size: 13px; font-weight: 700; cursor: pointer; white-space: nowrap; }
        .btn-clear-filter:hover { background: #e2e8f0; }
        .sel-wrap { flex: 2; min-width: 180px; position: relative; }
        .sel-box { height: 38px; padding: 0 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; background: white; cursor: pointer; display: flex; align-items: center; justify-content: space-between; gap: 6px; user-select: none; transition: border-color .15s; }
        .sel-box:hover { border-color: #007bff; }
        .sel-box span:first-child { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; flex: 1; color: #333; }
        .sel-arrow { color: #6c757d; font-size: 11px; flex-shrink: 0; transition: transform .2s; }
        .sel-wrap.open .sel-arrow { transform: rotate(180deg); }
        .sel-wrap.open .sel-box { border-color: #007bff; box-shadow: 0 0 0 2px rgba(0,123,255,.1); }
        .sel-panel { display: none; position: absolute; top: calc(100% + 4px); left: 0; width: 100%; min-width: 240px; background: white; border: 1px solid #007bff; border-radius: 6px; box-shadow: 0 6px 16px rgba(0,0,0,0.12); z-index: 200; padding: 8px; }
        .sel-wrap.open .sel-panel { display: block; }
        .sel-panel input { width: 100%; height: 32px; padding: 0 8px; border: 1px solid #ddd; border-radius: 4px; font-size: 12px; margin-bottom: 5px; box-sizing: border-box; }
        .sel-panel input:focus { border-color: #007bff; outline: none; }
        .sel-list { max-height: 220px; overflow-y: auto; }
        .sel-item { padding: 7px 10px; cursor: pointer; font-size: 13px; border-radius: 4px; line-height: 1.3; }
        .sel-item:hover { background: #f0f7ff; }
        .sel-item.active { background: #e6f0ff; color: #1d4ed8; font-weight: 600; }
        .sel-item.hidden { display: none; }
        .active-badge { display: inline-flex; align-items: center; gap: 5px; background: #dbeafe; color: #1e40af; border-radius: 20px; padding: 3px 10px; font-size: 11px; font-weight: 700; flex-wrap: wrap; }
        .active-badge .x-btn { cursor: pointer; font-weight: 900; margin-left: 2px; }
        .active-badge .x-btn:hover { color: #dc2626; }
        @media (max-width: 600px) {
            body { margin: 10px; }
            .page { padding: 0; }
            .topbar { border-radius: 8px; padding: 12px 14px; }
            .topbar h2 { font-size: 15px; }
            .stats-bar { gap: 8px; }
            .stat { min-width: 100px; padding: 10px 12px; }
            .btn-create { font-size: 14px; padding: 12px; }
            .filter-panel { padding: 10px 12px; }
            .filter-row { flex-direction: column; align-items: stretch; gap: 6px; }
            .filter-row + .filter-row { margin-top: 8px; padding-top: 8px; }
            .filter-input { min-width: unset; width: 100%; }
            .sel-wrap { min-width: unset; width: 100%; }
            .date-wrap { flex: 1 1 auto; width: 100%; }
            .date-sep { text-align: center; }
            .btn-apply-filter { width: 100%; height: 42px; font-size: 14px; }
            .btn-clear-filter { width: 100%; height: 42px; font-size: 14px; }
        }
        @media (max-width: 600px) { .page { padding: 12px; } .stats-bar { gap: 8px; } .stat { min-width: 100px; padding: 10px 12px; } .filter-row { gap: 6px; } }
    </style>
</head>
<body>

<div class="topbar">
    <h2>👷 <?= htmlspecialchars($current_user_name) ?></h2>
    <div class="nav">
        <?php if (isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 2): ?>
            <a href="admin.php">⚙️ Switch to Admin</a>
        <?php endif; ?>
        <a href="profile.php?from=index">👤 Profile</a>
        <a href="login.php?action=logout" class="logout-btn">Logout</a>
    </div>
</div>

<div class="page">
    <a href="create.php" class="btn-create">+ Create New Record</a>

    <!-- ── Unified Filter Panel ── -->
    <div class="filter-panel">
        <!-- Row 1: Search + IIPS dropdown -->
        <div class="filter-row">
            <span class="filter-label">🔍</span>
            <input type="text" class="filter-input" id="txt-search" placeholder="Search by activity, IIPS, customer..." oninput="doFilter()">
            <div class="sel-wrap" id="proj-wrap">
                <div class="sel-box" id="proj-box" onclick="toggleSel()">
                    <span id="proj-label">All IIPS</span>
                    <span class="sel-arrow">▾</span>
                </div>
                <div class="sel-panel" id="proj-panel">
                    <input type="text" id="proj-inner" placeholder="Type to search..." oninput="filterSel()" onclick="event.stopPropagation()">
                    <div class="sel-list" id="proj-list">
                        <div class="sel-item active" data-value="" onclick="pickProj(this, '', 'All IIPS')">All IIPS</div>
                        <?php if ($proj_list_res): while($p = $proj_list_res->fetch_assoc()):
                            $kw = strtolower("[".$p['project_id']."] ".$p['project_name']." ".$p['customer_name']);
                            $pid_show = preg_match('/^N[\/.\-]?A/i', $p['project_id']) ? '' : '['.$p['project_id'].'] ';
                        ?>
                            <div class="sel-item"
                                 data-value="<?= htmlspecialchars($p['project_id']) ?>"
                                 data-kw="<?= htmlspecialchars($kw) ?>"
                                 onclick="pickProj(this, '<?= htmlspecialchars(addslashes($p['project_id'])) ?>', '<?= htmlspecialchars(addslashes($pid_show.$p['project_name'])) ?>')">
                                <?= htmlspecialchars($pid_show.$p['project_name']) ?>
                                <span style="color:#9ca3af;font-size:11px;display:block;"><?= htmlspecialchars($p['customer_name']) ?></span>
                            </div>
                        <?php endwhile; endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <!-- Row 2: Date range + action buttons -->
        <div class="filter-row">
            <span class="filter-label">📅 Date</span>
            <div class="date-wrap">
                <input type="text" id="start-display" placeholder="DD MMM YYYY" autocomplete="off"
                       oninput="this.value=this.value.toUpperCase()"
                       onblur="syncTextToHidden('start')" onkeydown="if(event.key==='Enter')applyDateRange()">
                <div class="cal-btn" onclick="document.getElementById('start-picker').showPicker()">📅</div>
                <input type="date" id="start-picker" onchange="syncPickerToDisplay('start')">
            </div>
            <span class="date-sep">→</span>
            <div class="date-wrap">
                <input type="text" id="end-display" placeholder="DD MMM YYYY" autocomplete="off"
                       oninput="this.value=this.value.toUpperCase()"
                       onblur="syncTextToHidden('end')" onkeydown="if(event.key==='Enter')applyDateRange()">
                <div class="cal-btn" onclick="document.getElementById('end-picker').showPicker()">📅</div>
                <input type="date" id="end-picker" onchange="syncPickerToDisplay('end')">
            </div>
            <button class="btn-apply-filter" onclick="applyDateRange()">Apply Date</button>
            <button class="btn-clear-filter" onclick="clearAllFilters()">✕ Clear All</button>
        </div>
        <?php if (!empty($filter_start) || !empty($filter_end)): ?>
        <div style="margin-top:8px;">
            <span class="active-badge">
                📅 Date filter active:
                <?php if ($filter_start && $filter_end): ?>
                    <?= fmtDateDisplay($filter_start) ?> → <?= fmtDateDisplay($filter_end) ?>
                <?php elseif ($filter_start): ?>
                    From <?= fmtDateDisplay($filter_start) ?>
                <?php else: ?>
                    Until <?= fmtDateDisplay($filter_end) ?>
                <?php endif; ?>
                <span class="x-btn" onclick="clearDateRange()">✕</span>
            </span>
        </div>
        <?php endif; ?>
    </div>
    <!-- ── End Filter Panel ── -->

    <div class="card">
        <div class="summary-card" id="sum-card">
            <h4>📊 Filter Results</h4>
            <div class="sum-grid">
                <div class="sum-item"><span class="sum-label">Total Logs</span><span class="sum-value" id="sum-logs">-</span></div>
                <div class="sum-item"><span class="sum-label">Total Hours</span><span class="sum-value" id="sum-hours">-</span></div>
            </div>
        </div>

        <div class="card-hdr">
            <span>Timesheet Records</span>
            <span style="font-size:12px; color:#64748b; font-weight:400; display:flex; align-items:center; gap:8px;">
                <span><?= $total_records ?> records · </span>
                <?= calculateTotalProjectManDays($total_minutes) ?>
            </span>
        </div>
        <?php if (empty($rows_cache)): ?>
            <div class="no-data">No records found. Click "+ Create New Record" to add an entry.</div>
        <?php else: ?>
        <div class="tbl-wrap">
        <table id="main-table">
            <thead>
                <tr class="sec-row">
                    <th class="s-base" colspan="3">IIPS Details</th>
                    <th class="s-base" colspan="1">Activity</th>
                    <th class="s-timeline" colspan="2">Timeline</th>
                    <th class="s-perf" colspan="1">Performance</th>
                    <th class="s-base" rowspan="2">Actions</th>
                </tr>
                <tr>
                    <th style="width: 13%;">
                        <div class="sort-wrap" style="position:relative;">
                            <span>IIPS</span>
                            <button class="sort-btn" onclick="toggleSort(event,'drop-proj')"></button>
                            <div id="drop-proj" class="sort-menu">
                                <a href="#" onclick="sortTable(0,'alpha',0);return false;">Default</a>
                                <a href="#" onclick="sortTable(0,'alpha',1);return false;">A → Z</a>
                                <a href="#" onclick="sortTable(0,'alpha',2);return false;">Z → A</a>
                            </div>
                        </div>
                    </th>
                    <th style="width: 14%;">Customer</th>
                    <th style="width: 15%;">IIPS Name</th>
                    <th style="width: auto;">Activity</th>
                    <th style="width: 12%;">
                        <div class="sort-wrap" style="position:relative;">
                            <span>Start Date</span>
                            <button class="sort-btn" onclick="toggleSort(event,'drop-sd')"></button>
                            <div id="drop-sd" class="sort-menu">
                                <a href="#" onclick="sortTable(4,'date',0);return false;">Default</a>
                                <a href="#" onclick="sortTable(4,'date',1);return false;">Oldest First</a>
                                <a href="#" onclick="sortTable(4,'date',2);return false;">Newest First</a>
                            </div>
                        </div>
                    </th>
                    <th style="width: 12%;">End Date</th>
                    <th style="width: 10%;">Duration</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows_cache as $row): ?>
                <tr data-pid="<?= htmlspecialchars($row['project_id']) ?>"
                    data-sd="<?= htmlspecialchars($row['start_date']) ?>"
                    data-mins="<?= intval($row['_minutes'] ?? 0) ?>">
                    <td><code style="font-size:11px;"><?= preg_match('/^N[\/.\-]?A/i', $row['project_id']) ? '<span style=\'color:#9ca3af;\'>—</span>' : htmlspecialchars($row['project_id']) ?></code></td>
                    <td style="font-size:12px;"><?= htmlspecialchars($row['customer_name']) ?></td>
                    <td style="font-size:12px;"><?= htmlspecialchars($row['project_name']) ?></td>
                    <td>
                        <div class="act-cell"><?= htmlspecialchars($row['work_description'] ?: 'No description') ?></div>
                    </td>
                    <td>
                        <div class="date-range">
                            <div class="start"><?= fmtDate($row['start_date']) ?></div>
                            <div class="time"><?= htmlspecialchars(substr($row['start_time'],0,5)) ?></div>
                        </div>
                    </td>
                    <td>
                        <div class="date-range">
                            <div class="end"><?= fmtDate($row['end_date']) ?></div>
                            <div class="time"><?= htmlspecialchars(substr($row['end_time'],0,5)) ?></div>
                        </div>
                    </td>
                    <td>
                        <?= calculateDuration($row['start_date'], $row['start_time'], $row['end_time'], isset($row['meal_breaks']) ? intval($row['meal_breaks']) : 0) ?>
                    </td>
                    <td>
                        <div style="display: flex; gap: 8px;">
                            <a href="edit.php?edit=<?= $row['id'] ?>" class="btn-edit">Edit</a>
                            <a href="index.php?delete=<?= $row['id'] ?>" class="btn-delete" onclick="return confirm('Delete this record?')">Delete</a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
let origRows = null;
let activeProjFilter = '';

const MONTHS_D = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
const MONTHS_U = ['JAN','FEB','MAR','APR','MAY','JUN','JUL','AUG','SEP','OCT','NOV','DEC'];

document.addEventListener('DOMContentLoaded', function() {
    const params = new URLSearchParams(window.location.search);
    const startVal = params.get('start');
    const endVal   = params.get('end');
    if (startVal) {
        document.getElementById('start-picker').value = startVal;
        document.getElementById('start-display').value = formatDateDisplay(startVal);
    }
    if (endVal) {
        document.getElementById('end-picker').value = endVal;
        document.getElementById('end-display').value = formatDateDisplay(endVal);
    }
});

function formatDateDisplay(ymd) {
    if (!ymd) return '';
    const p = ymd.split('-');
    if (p.length !== 3) return '';
    return parseInt(p[2]) + ' ' + MONTHS_U[parseInt(p[1])-1] + ' ' + p[0];
}

function syncPickerToDisplay(type) {
    const picker  = document.getElementById(type + '-picker');
    const display = document.getElementById(type + '-display');
    display.value = formatDateDisplay(picker.value);
    display.classList.remove('error-border');
}

function syncTextToHidden(type) {
    const display = document.getElementById(type + '-display');
    const picker  = document.getElementById(type + '-picker');
    const parsed  = parseDateDisplay(display.value);
    if (parsed) {
        picker.value = parsed;
        display.value = formatDateDisplay(parsed);
        display.classList.remove('error-border');
    } else if (display.value.trim()) {
        display.classList.add('error-border');
    }
}

function parseDateDisplay(str) {
    str = str.trim().toUpperCase();
    if (!str) return '';
    let m = str.match(/^(\d{1,2})[\/\-\s]+([A-Z]+)[\/\-\s]+(\d{2,4})$/);
    if (m) {
        const d = m[1].padStart(2,'0');
        const mo = MONTHS_U.findIndex(x => x === m[2].substring(0,3)) + 1;
        if (mo < 1) return '';
        let y = m[3]; if (y.length===2) y='20'+y;
        return y+'-'+String(mo).padStart(2,'0')+'-'+d;
    }
    m = str.match(/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2,4})$/);
    if (m) {
        const d=m[1].padStart(2,'0'), mo=m[2].padStart(2,'0');
        let y=m[3]; if(y.length===2) y='20'+y;
        return y+'-'+mo+'-'+d;
    }
    return '';
}

function applyDateRange() {
    const startDisplay = document.getElementById('start-display').value.trim();
    const endDisplay   = document.getElementById('end-display').value.trim();
    let startVal = document.getElementById('start-picker').value;
    let endVal   = document.getElementById('end-picker').value;

    if (!startVal && startDisplay) {
        startVal = parseDateDisplay(startDisplay);
        if (!startVal) { document.getElementById('start-display').classList.add('error-border'); return; }
    }
    if (!endVal && endDisplay) {
        endVal = parseDateDisplay(endDisplay);
        if (!endVal) { document.getElementById('end-display').classList.add('error-border'); return; }
    }
    let url = 'index.php?';
    if (startVal) url += 'start=' + startVal + '&';
    if (endVal)   url += 'end='   + endVal   + '&';
    window.location.href = url.replace(/&$/, '');
}

function clearDateRange() {
    window.location.href = 'index.php';
}

function clearAllFilters() {
    document.getElementById('start-display').value = '';
    document.getElementById('end-display').value   = '';
    document.getElementById('start-picker').value  = '';
    document.getElementById('end-picker').value    = '';
    document.getElementById('txt-search').value    = '';
    activeProjFilter = '';
    document.getElementById('proj-label').textContent = 'All IIPS';
    document.querySelectorAll('#proj-list .sel-item').forEach(i => i.classList.remove('active'));
    const allItem = document.querySelector('#proj-list .sel-item[data-value=""]');
    if (allItem) allItem.classList.add('active');
    document.getElementById('sum-card').style.display = 'none';
    doFilter();
    // If date filter was active server-side, navigate to clear it
    const params = new URLSearchParams(window.location.search);
    if (params.get('start') || params.get('end')) window.location.href = 'index.php';
}

function toggleSel() {
    const wrap = document.getElementById('proj-wrap');
    const isOpen = wrap.classList.contains('open');
    wrap.classList.toggle('open', !isOpen);
    if (!isOpen) document.getElementById('proj-inner').focus();
}

function filterSel() {
    const val = document.getElementById('proj-inner').value.toLowerCase();
    document.querySelectorAll('#proj-list .sel-item').forEach(item => {
        if (!item.dataset.value) { item.classList.remove('hidden'); return; }
        item.classList.toggle('hidden', !!val && !(item.dataset.kw||'').includes(val));
    });
}

function pickProj(el, value, label) {
    activeProjFilter = value;
    document.getElementById('proj-label').textContent = label;
    document.querySelectorAll('#proj-list .sel-item').forEach(i => i.classList.remove('active'));
    el.classList.add('active');
    document.getElementById('proj-wrap').classList.remove('open');
    document.getElementById('proj-inner').value = '';
    filterSel();
    doFilter();
}

document.addEventListener('click', e => {
    if (!e.target.closest('#proj-wrap')) {
        const p = document.getElementById('proj-wrap');
        if (p) p.classList.remove('open');
    }
});

function doFilter() {
    const txt = document.getElementById('txt-search').value.toLowerCase();
    let visRows = [];
    document.querySelectorAll('#main-table tbody tr').forEach(tr => {
        const rowPid  = tr.dataset.pid  || '';
        const rowText = tr.textContent.toLowerCase();
        const ok = (!txt || rowText.includes(txt)) && (!activeProjFilter || rowPid === activeProjFilter);
        tr.classList.toggle('is-hidden', !ok);
        if (ok) visRows.push(tr);
    });
    updateSummary(visRows, txt);
}

function updateSummary(visRows, txt) {
    const card = document.getElementById('sum-card');
    const hasFilter = txt || activeProjFilter;
    if (!hasFilter || visRows.length === 0) { card.style.display = 'none'; return; }
    let totalMins = 0;
    visRows.forEach(tr => { totalMins += parseInt(tr.dataset.mins) || 0; });
    const h = Math.floor(totalMins/60), m = totalMins%60;
    document.getElementById('sum-logs').textContent  = visRows.length;
    document.getElementById('sum-hours').textContent = h+'h '+m+'m';
    card.style.display = 'block';
}

document.querySelectorAll('.act-cell').forEach(c => {
    let t;
    c.addEventListener('mouseenter', () => { t = setTimeout(() => c.classList.add('expanded'), 500); });
    c.addEventListener('mouseleave', () => { clearTimeout(t); c.classList.remove('expanded'); });
});

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

function toggleSort(e, id) {
    e.stopPropagation();
    document.querySelectorAll('.sort-menu').forEach(m => { if (m.id !== id) m.classList.remove('show-sort'); });
    document.getElementById(id).classList.toggle('show-sort');
}
window.addEventListener('click', () => document.querySelectorAll('.sort-menu').forEach(m => m.classList.remove('show-sort')));

function sortTable(col, type, dir) {
    const tbody = document.querySelector('#main-table tbody');
    if (!tbody) return;
    const rows = Array.from(tbody.querySelectorAll('tr'));
    if (!origRows) origRows = [...rows];
    if (dir === 0) { origRows.forEach(r => tbody.appendChild(r)); return; }
    rows.sort((a, b) => {
        const ca = a.cells[col].textContent.trim();
        const cb = b.cells[col].textContent.trim();
        if (type === 'alpha') return dir===1 ? ca.localeCompare(cb) : cb.localeCompare(ca);
        if (type === 'date') {
            const ta = new Date(ca.replace(/-/g,'/')).getTime()||0;
            const tb = new Date(cb.replace(/-/g,'/')).getTime()||0;
            return dir===1 ? ta-tb : tb-ta;
        }
        return 0;
    });
    rows.forEach(r => tbody.appendChild(r));
}
</script>
</body>
</html>