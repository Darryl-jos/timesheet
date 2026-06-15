<?php
require_once 'config.php';

if (!isset($_SESSION['engineer_id']) || !isset($_SESSION['is_admin']) || ($_SESSION['is_admin'] != 1 && $_SESSION['is_admin'] != 2)) {
    header("Location: login.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['bulk_action']) && $_POST['bulk_action'] === 'delete') {
    if (isset($_POST['selected_ts']) && is_array($_POST['selected_ts'])) {
        $ids = array_map('intval', $_POST['selected_ts']);
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $conn->prepare("DELETE FROM timesheets WHERE id IN ($ph)");
        $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
        $stmt->execute();
        $stmt->close();
        header("Location: admin_timesheets.php");
        exit;
    }
}

if (isset($_GET['delete_ts'])) {
    $del_id = intval($_GET['delete_ts']);
    $stmt = $conn->prepare("DELETE FROM timesheets WHERE id = ?");
    $stmt->bind_param("i", $del_id);
    $stmt->execute();
    $stmt->close();
    header("Location: admin_timesheets.php");
    exit;
}

$ts_result = $conn->query("
    SELECT t.*, p.project_name, p.customer_name, p.estimate_time
    FROM timesheets t
    JOIN projects p ON t.project_id = p.project_id
    ORDER BY t.start_date DESC, t.start_time DESC
");
if (!$ts_result) die("DB Error: " . $conn->error);

$proj_list_result = $conn->query("SELECT project_id, project_name, customer_name FROM projects ORDER BY project_name ASC");

$rows_cache = [];
while ($row = $ts_result->fetch_assoc()) {
    $start = new DateTime($row['start_date'] . ' ' . $row['start_time']);
    $end   = new DateTime($row['end_date']   . ' ' . $row['end_time']);
    if ($end <= $start) $end->modify('+1 day');
    $diff  = $start->diff($end);
    $mins  = ($diff->days * 24 * 60) + ($diff->h * 60) + $diff->i;
    
    $mb = isset($row['meal_breaks']) ? intval($row['meal_breaks']) : 0;
    $mins -= ($mb * 60);
    if ($mins < 0) {
        $mins = 0;
    }
    
    $row['_minutes'] = $mins;
    $rows_cache[] = $row;
}

$proj_agg_json = json_encode([]);

function fmtDate($d) {
    if (!$d) return '-';
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt ? $dt->format('d M Y') : $d;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Audit Timesheets — Admin</title>
<style>
* { box-sizing: border-box; }
body { font-family: Arial, sans-serif; margin: 16px; background: #f4f7f6; color: #333; padding-bottom: 20px; }
.topbar { background: #343a40; padding: 15px 20px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; border-radius: 8px; margin-bottom: 16px; box-shadow: 0 2px 8px rgba(0,0,0,0.15); }
.topbar h2 { color: white; margin: 0; font-size: 18px; }
.topbar a { color: #ffc107; font-weight: bold; text-decoration: none; font-size: 13px; }
.topbar a:hover { color: #ffda6a; }
.page { padding: 20px; }
.live-dashboard { background: white; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.07); padding: 16px 20px; margin-bottom: 16px; border-left: 5px solid #007bff; display: none; }
.live-dashboard h4 { margin: 0 0 12px; font-size: 14px; color: #1e40af; font-weight: 700; display: flex; align-items: center; gap: 8px; }
.live-dashboard h4 .pulse { display: inline-block; width: 8px; height: 8px; background: #22c55e; border-radius: 50%; animation: pulse 1.5s infinite; }
@keyframes pulse { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.5;transform:scale(1.3)} }
.dash-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 10px; }
.dash-item { background: #f8fafc; padding: 10px 14px; border-radius: 6px; border: 1px solid #e2e8f0; }
.dash-label { font-size: 11px; color: #64748b; text-transform: uppercase; font-weight: 700; display: block; margin-bottom: 3px; }
.dash-value { font-size: 18px; font-weight: 700; color: #1e293b; }
.dash-sub { font-size: 11px; color: #94a3b8; margin-top: 2px; }
.filter-tags { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 10px; }
.ftag { display: inline-flex; align-items: center; gap: 4px; background: #dbeafe; color: #1e40af; border-radius: 20px; padding: 3px 10px; font-size: 11px; font-weight: 700; }
.ftag.eng  { background: #dcfce7; color: #166534; }
.ftag.date { background: #fef9c3; color: #854d0e; }
.filter-panel { background: white; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); padding: 14px 16px; margin-bottom: 12px; }
.filter-row { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.filter-row + .filter-row { margin-top: 10px; padding-top: 10px; border-top: 1px dashed #e5e7eb; }
.filter-label { font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; white-space: nowrap; }
.filter-input { flex: 2; min-width: 160px; height: 38px; padding: 0 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; background: #fff; color: #333; transition: border-color .15s; }
.filter-input:focus { border-color: #007bff; outline: none; box-shadow: 0 0 0 2px rgba(0,123,255,.1); }
.date-wrap { position: relative; height: 38px; display: flex; flex: 0 0 155px; width: 155px; }
.date-wrap input[type="text"] { width: 100%; height: 100%; padding: 0 32px 0 8px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 12px; text-transform: uppercase; background: #fff; color: #333; box-sizing: border-box; }
.date-wrap input[type="text"]:focus { border-color: #007bff; outline: none; box-shadow: 0 0 0 2px rgba(0,123,255,.1); }
.date-wrap .cal-btn { position: absolute; right: 0; top: 0; width: 32px; height: 100%; display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 13px; z-index: 4; }
.date-sep { font-size: 12px; color: #94a3b8; font-weight: 600; white-space: nowrap; }
.btn-clear-filter { background: #f1f5f9; color: #475569; border: 1px solid #d1d5db; height: 38px; padding: 0 14px; border-radius: 6px; font-size: 13px; font-weight: 700; cursor: pointer; white-space: nowrap; }
.btn-clear-filter:hover { background: #e2e8f0; }
@media (max-width: 600px) {
    body { margin: 10px; }
    .page { padding: 0; }
    .topbar { border-radius: 8px; padding: 12px 14px; }
    .topbar h2 { font-size: 15px; }
    .filter-panel { padding: 10px 12px; }
    .filter-row { flex-direction: column; align-items: stretch; gap: 6px; }
    .filter-row + .filter-row { margin-top: 8px; padding-top: 8px; }
    .filter-input { min-width: unset; width: 100%; }
    .sel-wrap { min-width: unset; width: 100%; }
    .date-wrap { flex: 1 1 auto; width: 100%; }
    .date-sep { text-align: center; }
    .btn-clear-filter { width: 100%; height: 40px; font-size: 14px; }
}
.sel-wrap { flex: 2; min-width: 180px; position: relative; }
.sel-box { height: 38px; padding: 0 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; background: white; cursor: pointer; display: flex; align-items: center; justify-content: space-between; gap: 6px; user-select: none; transition: border-color .15s; }
.sel-box:hover { border-color: #007bff; }
.sel-box span:first-child { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; flex: 1; color: #333; }
.sel-arrow { color: #6c757d; font-size: 11px; flex-shrink: 0; transition: transform .2s; }
.sel-wrap.open .sel-arrow { transform: rotate(180deg); }
.sel-wrap.open .sel-box { border-color: #007bff; box-shadow: 0 0 0 2px rgba(0,123,255,.12); }
.sel-panel { display: none; position: absolute; top: calc(100% + 3px); left: 0; width: 100%; min-width: 220px; background: white; border: 1px solid #007bff; border-radius: 6px; box-shadow: 0 6px 16px rgba(0,0,0,0.12); z-index: 200; padding: 6px; }
.sel-wrap.open .sel-panel { display: block; }
.sel-panel input { width: 100%; height: 32px; padding: 0 8px; border: 1px solid #ddd; border-radius: 3px; font-size: 12px; margin-bottom: 5px; box-sizing: border-box; }
.sel-panel input:focus { border-color: #007bff; outline: none; }
.sel-list { max-height: 200px; overflow-y: auto; }
.sel-item { padding: 7px 10px; cursor: pointer; font-size: 13px; border-radius: 3px; line-height: 1.3; }
.sel-item:hover { background: #f0f7ff; }
.sel-item.active { background: #e6f0ff; color: #1d4ed8; font-weight: 600; }
.sel-item.hidden { display: none; }
.btn-clear { background: #6c757d; color: white; border: none; padding: 0 14px; height: 38px; border-radius: 4px; font-size: 13px; cursor: pointer; font-weight: bold; white-space: nowrap; }
#bulk-toolbar { display: none; background: #e6f0ff; border: 1px solid #b8daff; border-radius: 6px; padding: 10px 15px; margin-bottom: 12px; align-items: center; gap: 10px; flex-wrap: wrap; position: sticky; top: 8px; z-index: 100; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
#bulk-toolbar span { font-size: 13px; font-weight: 600; color: #1e40af; flex: 1; }
.btn-bulk { border: none; padding: 7px 14px; border-radius: 4px; font-size: 12px; cursor: pointer; font-weight: bold; }
.btn-export { background: #28a745; color: white; }
.btn-bulk-del { background: #dc3545; color: white; }
.btn-desel { background: #e2e8f0; color: #374151; }
.card { background: white; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.07); }
.card-hdr { padding: 12px 20px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; }
.card-hdr h3 { margin: 0; font-size: 15px; }
.btn-export-all { background: #6c757d; color: white; border: none; padding: 8px 16px; border-radius: 4px; font-size: 13px; font-weight: bold; cursor: pointer; transition: background 0.2s; }
.btn-export-all.filtered { background: #17a2b8; box-shadow: 0 2px 5px rgba(23,162,184,0.3); }
.tbl-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
.tbl-scroll { overflow-y: auto; max-height: 70vh; }
table { width: 100%; border-collapse: collapse; min-width: 1000px; }
th, td { padding: 10px 12px; text-align: left; font-size: 12px; border-bottom: 1px solid #f1f5f9; white-space: nowrap; }
th { background: #f8fafc; font-weight: 600; color: #475569; }
tbody tr:hover { background: #f8faff; }
.is-hidden { display: none !important; }
.dr { line-height: 1.4; }
.dr .d-start { color: #1d4ed8; font-weight: 600; font-size: 12px; }
.dr .d-end   { color: #7c3aed; font-weight: 600; font-size: 12px; }
.dr .d-time  { color: #94a3b8; font-size: 11px; }
.dur { background: #d1fae5; color: #065f46; font-weight: bold; padding: 2px 8px; border-radius: 12px; font-size: 11px; white-space: nowrap; }
.dur.multi { background: #dbeafe; color: #1e40af; }
.gap-over  { color: #dc3545; font-weight: bold; font-size: 11px; }
.gap-under { color: #28a745; font-weight: bold; font-size: 11px; }
.gap-ok    { color: #6c757d; font-weight: bold; font-size: 11px; }
.act { max-width: 220px; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; white-space: pre-line; cursor: pointer; color: #555; font-size: 11px; }
.act.exp { display: block; max-height: none; }
.btn-edit { background: #ffc107; color: #333; padding: 4px 10px; text-decoration: none; border-radius: 4px; font-size: 12px; font-weight: bold; margin-right: 4px; }
.btn-edit:hover { background: #e0a800; }
.btn-del  { background: #dc3545; color: white; padding: 4px 10px; text-decoration: none; border-radius: 4px; font-size: 12px; font-weight: bold; }
.btn-del:hover  { background: #c82333; }
.sort-wrap { display: inline-flex; align-items: center; gap: 4px; position: relative; }
.sort-btn { background:none; border:none; width:12px; height:12px; cursor:pointer; position:relative; padding:0; }
.sort-btn::before { content:""; position:absolute; top:1px; left:1px; border-left:4px solid transparent; border-right:4px solid transparent; border-bottom:4px solid #888; }
.sort-btn::after  { content:""; position:absolute; bottom:1px; left:1px; border-left:4px solid transparent; border-right:4px solid transparent; border-top:4px solid #888; }
.sort-btn:hover::before { border-bottom-color:#007bff; }
.sort-btn:hover::after  { border-top-color:#007bff; }
.sort-menu { display:none; position:absolute; top:20px; left:0; background:#fff; min-width:120px; box-shadow:0 4px 12px rgba(0,0,0,0.1); border:1px solid #e2e8f0; border-radius:4px; z-index:99; }
.sort-menu a { display:block; padding:6px 10px; font-size:12px; color:#333; text-decoration:none; }
.sort-menu a:hover { background:#f8fafc; color:#007bff; }
.show-sort { display:block !important; }
.sec-row th { text-align: center; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; padding: 5px 8px; color: white; border: 1px solid rgba(255,255,255,0.15); position: sticky; top: 0; z-index: 22; }
thead tr:last-child th { position: sticky; top: 27px; z-index: 21; background: #f8fafc; box-shadow: 0 2px 0 #dee2e6; }
thead tr.sec-row th.chk-col { z-index: 26; }
.s-base     { background: #343a40; }
.s-timeline { background: #1a237e; }
.s-actual   { background: #004d40; }
.s-act      { background: #343a40; }
.chk-col { width: 36px; position: sticky; left: 0; z-index: 23; background: #343a40; border-right: 1px solid #dee2e6; }
@media (max-width: 600px) { .page { padding: 10px; } }
</style>
</head>
<body>

<div class="topbar">
    <h2>📊 Timesheet Audit — All Engineers</h2>
    <a href="admin.php">← Back to Admin Home</a>
</div>

<div class="page">
    <div id="bulk-toolbar">
        <span id="bulk-count">0 selected</span>
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <button class="btn-bulk btn-export" onclick="submitBulkExport()">📥 Export Selected</button>
            <button class="btn-bulk btn-bulk-del" onclick="submitBulkDelete()">🗑 Delete Selected</button>
            <button class="btn-bulk btn-desel" onclick="deselectAll()">✕ Deselect All</button>
        </div>
    </div>

    <!-- ── Unified Filter Panel ── -->
    <div class="filter-panel">
        <!-- Row 1: Search + Engineer + Project dropdowns -->
        <div class="filter-row">
            <span class="filter-label">🔍</span>
            <input type="text" class="filter-input" id="txt-search" placeholder="Search by activity, keyword..." oninput="doFilter()">

            <div class="sel-wrap" id="eng-wrap">
                <div class="sel-box" id="eng-box" onclick="toggleSel('eng')">
                    <span id="eng-label">All Engineers</span>
                    <span class="sel-arrow">▾</span>
                </div>
                <div class="sel-panel" id="eng-panel">
                    <input type="text" id="eng-inner" placeholder="Type to search..." oninput="filterSel('eng')" onclick="event.stopPropagation()">
                    <div class="sel-list" id="eng-list">
                        <div class="sel-item active" data-value="" onclick="pickSel('eng','','All Engineers',this)">All Engineers</div>
                        <?php
                        $all_engs = $conn->query("SELECT engineer_name FROM engineers WHERE engineer_name != '' ORDER BY engineer_name ASC");
                        while ($eng_row = $all_engs->fetch_assoc()):
                            $eng = $eng_row['engineer_name'];
                        ?>
                        <div class="sel-item"
                             data-value="<?= htmlspecialchars($eng) ?>"
                             data-kw="<?= strtolower(htmlspecialchars($eng)) ?>"
                             onclick="pickSel('eng','<?= htmlspecialchars(addslashes($eng)) ?>','<?= htmlspecialchars(addslashes($eng)) ?>',this)">
                            <?= htmlspecialchars($eng) ?>
                        </div>
                        <?php endwhile; ?>
                    </div>
                </div>
            </div>

            <div class="sel-wrap" id="proj-wrap">
                <div class="sel-box" id="proj-box" onclick="toggleSel('proj')">
                    <span id="proj-label">All Projects</span>
                    <span class="sel-arrow">▾</span>
                </div>
                <div class="sel-panel" id="proj-panel">
                    <input type="text" id="proj-inner" placeholder="Type to search..." oninput="filterSel('proj')" onclick="event.stopPropagation()">
                    <div class="sel-list" id="proj-list">
                        <div class="sel-item active" data-value="" onclick="pickSel('proj','','All Projects',this)">All Projects</div>
                        <?php if ($proj_list_result): while($p = $proj_list_result->fetch_assoc()):
                            $label = ($p['project_id'] && !preg_match('/^N[\/.\-]?A/i',$p['project_id']) ? '['.$p['project_id'].'] ' : '').$p['project_name'];
                        ?>
                        <div class="sel-item"
                             data-value="<?= htmlspecialchars($p['project_id']) ?>"
                             data-kw="<?= strtolower(htmlspecialchars($p['project_id'].' '.$p['project_name'].' '.$p['customer_name'])) ?>"
                             onclick="pickSel('proj','<?= htmlspecialchars(addslashes($p['project_id'])) ?>','<?= htmlspecialchars(addslashes($label)) ?>',this)">
                            <?= htmlspecialchars($label) ?>
                            <span style="color:#9ca3af;font-size:11px;display:block;"><?= htmlspecialchars($p['customer_name']) ?></span>
                        </div>
                        <?php endwhile; endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <!-- Row 2: Date range + Clear -->
        <div class="filter-row">
            <span class="filter-label">📅 Date</span>
            <div class="date-wrap">
                <input type="text" id="date-start-display" placeholder="DD MMM YYYY" autocomplete="off"
                       oninput="this.value=this.value.toUpperCase()"
                       onblur="parseTsDate('start')" onkeydown="if(event.key==='Enter')this.blur()">
                <div class="cal-btn" onclick="document.getElementById('date-start').showPicker()">📅</div>
                <input type="date" id="date-start" style="position:absolute;top:0;right:0;width:32px;height:100%;opacity:0;cursor:pointer;z-index:5;" onchange="syncTsDate('start')">
            </div>
            <span class="date-sep">→</span>
            <div class="date-wrap">
                <input type="text" id="date-end-display" placeholder="DD MMM YYYY" autocomplete="off"
                       oninput="this.value=this.value.toUpperCase()"
                       onblur="parseTsDate('end')" onkeydown="if(event.key==='Enter')this.blur()">
                <div class="cal-btn" onclick="document.getElementById('date-end').showPicker()">📅</div>
                <input type="date" id="date-end" style="position:absolute;top:0;right:0;width:32px;height:100%;opacity:0;cursor:pointer;z-index:5;" onchange="syncTsDate('end')">
            </div>
            <button class="btn-clear-filter" onclick="clearAllFilters()">✕ Clear All</button>
        </div>
    </div>
    <!-- ── End Filter Panel ── -->

    <div class="live-dashboard" id="live-dashboard">
        <div class="dash-grid">
            <div class="dash-item">
                <span class="dash-label">Matching Logs</span>
                <div class="dash-value" id="dash-logs">—</div>
            </div>
            <div class="dash-item">
                <span class="dash-label">Total Hours</span>
                <div class="dash-value" id="dash-hours">—</div>
            </div>
            <div class="dash-item">
                <span class="dash-label">Engineers</span>
                <div class="dash-value" id="dash-engs">—</div>
            </div>
            <div class="dash-item">
                <span class="dash-label">Date Range</span>
                <div class="dash-value" style="font-size:13px;" id="dash-dates">—</div>
                <div class="dash-sub" id="dash-days"></div>
            </div>
        </div>
    </div>

    <form id="bulk-form" method="POST" action="admin_timesheets.php">
        <input type="hidden" name="bulk_action" id="bulk-action-field" value="">

        <div class="card">
            <div class="card-hdr">
                <h3>Employee Work Hour Compliance Logs</h3>
                <button type="button" id="btn-export-all" class="btn-export-all" onclick="exportFilteredOrAll()">📥 Export All</button>
            </div>
            <div class="tbl-scroll">
            <div class="tbl-wrap">
            <table id="main-table">
                <thead>
                    <tr class="sec-row">
                        <th class="s-base" rowspan="2" style="width:36px;"><input type="checkbox" id="chk-all" onchange="toggleAll(this)"></th>
                        <th class="s-base" colspan="2">Engineer & Project</th>
                        <th class="s-base" colspan="2">IIPS Details</th>
                        <th class="s-base" colspan="1">Activity</th>
                        <th class="s-timeline" colspan="2">Timeline</th>
                        <th class="s-actual" colspan="3">Performance</th>
                        <th class="s-act" rowspan="2">Actions</th>
                    </tr>
                    <tr>
                        <th><div class="sort-wrap">Engineer<button type="button" class="sort-btn" onclick="toggleSort(event,'s-eng')"></button><div id="s-eng" class="sort-menu"><a href="#" onclick="sortT(1,'alpha',0);return false;">Default</a><a href="#" onclick="sortT(1,'alpha',1);return false;">A → Z</a><a href="#" onclick="sortT(1,'alpha',2);return false;">Z → A</a></div></div></th>
                        <th><div class="sort-wrap">Project ID<button type="button" class="sort-btn" onclick="toggleSort(event,'s-pid')"></button><div id="s-pid" class="sort-menu"><a href="#" onclick="sortT(2,'alpha',0);return false;">Default</a><a href="#" onclick="sortT(2,'alpha',1);return false;">A → Z</a><a href="#" onclick="sortT(2,'alpha',2);return false;">Z → A</a></div></div></th>
                        <th>Customer</th>
                        <th>Project Name</th>
                        <th>Activity</th>
                        <th><div class="sort-wrap">Start Date<button type="button" class="sort-btn" onclick="toggleSort(event,'s-sd')"></button><div id="s-sd" class="sort-menu"><a href="#" onclick="sortT(6,'date',0);return false;">Default</a><a href="#" onclick="sortT(6,'date',1);return false;">Oldest First</a><a href="#" onclick="sortT(6,'date',2);return false;">Newest First</a></div></div></th>
                        <th>End Date</th>
                        <th><div class="sort-wrap">Duration<button type="button" class="sort-btn" onclick="toggleSort(event,'s-dur')"></button><div id="s-dur" class="sort-menu"><a href="#" onclick="sortT(8,'num',0);return false;">Default</a><a href="#" onclick="sortT(8,'num',1);return false;">Shortest First</a><a href="#" onclick="sortT(8,'num',2);return false;">Longest First</a></div></div></th>
                        <th>Target Mandays</th>
                        <th>Gap</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($rows_cache)): ?>
                    <tr><td colspan="12" style="text-align:center; padding:40px; color:#9ca3af;">No timesheets submitted yet.</td></tr>
                <?php else: foreach ($rows_cache as $row):
                    $mins  = $row['_minutes'];
                    $h     = floor($mins / 60);
                    $m     = $mins % 60;
                    $days  = floor($h / 24);
                    $dur_text = ($days > 0 ? $days.'d ' : '') . ($h%24) . 'h ' . $m . 'm';
                    $is_multi = ($row['start_date'] !== $row['end_date']);

                    $target_mins = intval($row['estimate_time']) * 60;
                    $gap_mins = $mins - $target_mins;
                    if ($target_mins == 0) {
                        $gap_html = '<span class="gap-ok">No Target</span>';
                    } elseif ($gap_mins > 0) {
                        $gh = floor(abs($gap_mins)/60); $gm = abs($gap_mins)%60;
                        $gap_html = '<span class="gap-over">▲ '.$gh.'h '.$gm.'m Over</span>';
                    } elseif ($gap_mins < 0) {
                        $gh = floor(abs($gap_mins)/60); $gm = abs($gap_mins)%60;
                        $gap_html = '<span class="gap-under">▼ '.$gh.'h '.$gm.'m Saved</span>';
                    } else {
                        $gap_html = '<span class="gap-ok">✓ On Track</span>';
                    }
                ?>
                <tr data-pid="<?= htmlspecialchars($row['project_id']) ?>"
                    data-eng="<?= htmlspecialchars($row['engineer_name']) ?>"
                    data-sd="<?= htmlspecialchars($row['start_date']) ?>"
                    data-ed="<?= htmlspecialchars($row['end_date']) ?>"
                    data-mins="<?= $mins ?>">
                    <td><input type="checkbox" class="ts-chk" name="selected_ts[]" value="<?= $row['id'] ?>" onchange="onChkChange()"></td>
                    <td><strong><?= htmlspecialchars($row['engineer_name']) ?></strong></td>
                    <td><code style="font-size:11px;"><?= preg_match('/^N[\/.\-]?A/i', $row['project_id']) ? '<span style=\'color:#9ca3af;\'>—</span>' : htmlspecialchars($row['project_id']) ?></code></td>
                    <td style="font-size:11px;"><?= htmlspecialchars($row['customer_name']) ?></td>
                    <td style="font-size:11px;"><?= htmlspecialchars($row['project_name']) ?></td>
                    <td><div class="act"><?= htmlspecialchars($row['work_description'] ?: 'No description') ?></div></td>
                    <td><span class="d-start"><?= fmtDate($row['start_date']) ?></span><span class="d-time"> <?= htmlspecialchars(substr($row['start_time'],0,5)) ?></span></td>
                    <td><span class="d-end"><?= fmtDate($row['end_date']) ?></span><span class="d-time"> <?= htmlspecialchars(substr($row['end_time'],0,5)) ?></span></td>
                    <td data-raw="<?= $mins ?>"><span class="dur <?= $is_multi ? 'multi' : '' ?>"><?= $dur_text ?></span></td>
                    <td><span style="font-weight:700;color:#1e40af;"><?= intval($row['estimate_time']) ?>h (<?= round($row['estimate_time']/8, 1) ?> days)</span></td>
                    <td><?= $gap_html ?></td>
                    <td><a href="edit.php?edit=<?= $row['id'] ?>" class="btn-edit">Edit</a><a href="admin_timesheets.php?delete_ts=<?= $row['id'] ?>" class="btn-del" onclick="return confirm('Delete this log?')">Delete</a></td>
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
let activeProjFilter = '';
let activeEngFilter  = '';
let origRows = null;

const TS_MONTHS_SHORT = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
function syncTsDate(type) {
    const hidden  = document.getElementById('date-' + type);
    const display = document.getElementById('date-' + type + '-display');
    if (hidden.value) {
        const p = hidden.value.split('-');
        display.value = p[2] + '-' + TS_MONTHS_SHORT[parseInt(p[1],10)-1].toUpperCase() + '-' + p[0];
    } else { display.value = ''; }
    doFilter();
}
function parseTsDate(type) {
    const display = document.getElementById('date-' + type + '-display');
    const hidden  = document.getElementById('date-' + type);
    const str = display.value.trim().toUpperCase();
    if (!str) { hidden.value = ''; doFilter(); return; }
    const parts = str.split(/[\-\/\. ]+/);
    if (parts.length === 3) {
        let d = parts[0].padStart(2,'0');
        let m = parts[1];
        let y = parts[2].length === 2 ? '20'+parts[2] : parts[2];
        if (isNaN(m)) {
            const mIdx = TS_MONTHS_SHORT.findIndex(x => x.toUpperCase().startsWith(m.substring(0,3)));
            if (mIdx !== -1) m = String(mIdx+1).padStart(2,'0');
        } else { m = String(parseInt(m)).padStart(2,'0'); }
        if (d.length===2 && m.length===2 && y.length===4) {
            hidden.value = y+'-'+m+'-'+d;
            syncTsDate(type);
            return;
        }
    }
    if (hidden.value) syncTsDate(type); else display.value = '';
    doFilter();
}

function toggleSel(type) {
    const wrap = document.getElementById(type+'-wrap');
    const isOpen = wrap.classList.contains('open');
    document.querySelectorAll('.sel-wrap').forEach(w => w.classList.remove('open'));
    if (!isOpen) { wrap.classList.add('open'); document.getElementById(type+'-inner').focus(); }
}
function filterSel(type) {
    const val = document.getElementById(type+'-inner').value.toLowerCase();
    document.querySelectorAll('#'+type+'-list .sel-item').forEach(item => {
        if (!item.dataset.value) { item.classList.remove('hidden'); return; }
        item.classList.toggle('hidden', !!val && !(item.dataset.kw||'').includes(val));
    });
}
function pickSel(type, value, label, el) {
    if (type === 'proj') { activeProjFilter = value; document.getElementById('proj-label').textContent = label; }
    else                 { activeEngFilter  = value; document.getElementById('eng-label').textContent  = label; }
    document.querySelectorAll('#'+type+'-list .sel-item').forEach(i => i.classList.remove('active'));
    el.classList.add('active');
    document.getElementById(type+'-wrap').classList.remove('open');
    document.getElementById(type+'-inner').value = '';
    filterSel(type);
    doFilter();
}
document.addEventListener('click', e => { if (!e.target.closest('.sel-wrap')) document.querySelectorAll('.sel-wrap').forEach(w => w.classList.remove('open')); });

function doFilter() {
    const txt       = document.getElementById('txt-search').value.toLowerCase();
    const dateStart = document.getElementById('date-start').value;
    const dateEnd   = document.getElementById('date-end').value;
    let visRows = [];

    document.querySelectorAll('#main-table tbody tr').forEach(tr => {
        const rPid = tr.dataset.pid || '';
        const rEng = tr.dataset.eng || '';
        const rSd  = tr.dataset.sd  || '';
        const rTxt = tr.textContent.toLowerCase();
        const ok = (!txt              || rTxt.includes(txt))
                && (!activeProjFilter || rPid === activeProjFilter)
                && (!activeEngFilter  || rEng === activeEngFilter)
                && (!dateStart        || rSd  >= dateStart)
                && (!dateEnd          || rSd  <= dateEnd);
        tr.classList.toggle('is-hidden', !ok);
        if (ok) visRows.push(tr);
    });
    updateLiveDashboard(visRows, txt, dateStart, dateEnd);

    // Export Button UI Update logic
    const hasFilter = (txt !== '' || activeProjFilter !== '' || activeEngFilter !== '' || dateStart !== '' || dateEnd !== '');
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

function fmtDateJS(ymd) {
    if (!ymd) return '';
    const p = ymd.split('-');
    const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return parseInt(p[2]) + ' ' + months[parseInt(p[1])-1] + ' ' + p[0];
}

function updateLiveDashboard(visRows, txt, dateStart, dateEnd) {
    const dash = document.getElementById('live-dashboard');
    const hasFilter = txt || activeProjFilter || activeEngFilter || dateStart || dateEnd;
    if (!hasFilter) { dash.style.display = 'none'; return; }

    let totalMins = 0;
    const engSet  = new Set();
    let minDate = '', maxDate = '';

    visRows.forEach(tr => {
        totalMins += parseInt(tr.dataset.mins) || 0;
        if (tr.dataset.eng) engSet.add(tr.dataset.eng);
        const sd = tr.dataset.sd || '';
        if (sd) {
            if (!minDate || sd < minDate) minDate = sd;
            if (!maxDate || sd > maxDate) maxDate = sd;
        }
    });

    const h = Math.floor(totalMins/60), m = totalMins%60;
    document.getElementById('dash-logs').textContent  = visRows.length;
    document.getElementById('dash-hours').textContent = h+'h '+m+'m';
    document.getElementById('dash-engs').textContent  = engSet.size;

    if (minDate && maxDate) {
        document.getElementById('dash-dates').textContent = minDate === maxDate ? fmtDateJS(minDate) : fmtDateJS(minDate) + ' → ' + fmtDateJS(maxDate);
        const diff = Math.round((new Date(maxDate) - new Date(minDate)) / 86400000) + 1;
        document.getElementById('dash-days').textContent = diff + ' day' + (diff !== 1 ? 's' : '') + ' span';
    } else {
        document.getElementById('dash-dates').textContent = '—';
        document.getElementById('dash-days').textContent  = '';
    }
    dash.style.display = 'block';
}

function clearAllFilters() {
    document.getElementById('txt-search').value  = '';
    document.getElementById('date-start').value  = '';
    document.getElementById('date-end').value    = '';
    document.getElementById('date-start-display').value = '';
    document.getElementById('date-end-display').value   = '';
    activeProjFilter = ''; activeEngFilter  = '';
    document.getElementById('proj-label').textContent = 'All Projects';
    document.getElementById('eng-label').textContent  = 'All Engineers';
    document.querySelectorAll('.sel-item').forEach(i => i.classList.remove('active'));
    document.querySelectorAll('.sel-item[data-value=""]').forEach(i => i.classList.add('active'));
    document.getElementById('live-dashboard').style.display = 'none';
    doFilter();
}

function onChkChange() {
    const checked = document.querySelectorAll('.ts-chk:checked').length;
    const toolbar = document.getElementById('bulk-toolbar');
    toolbar.style.display = checked > 0 ? 'flex' : 'none';
    document.getElementById('bulk-count').textContent = checked + ' selected';
    
    // 只计算肉眼可见的checkbox
    const visibleCheckboxes = document.querySelectorAll('#main-table tbody tr:not(.is-hidden) .ts-chk');
    let visibleCheckedCount = 0;
    visibleCheckboxes.forEach(c => { if(c.checked) visibleCheckedCount++; });
    
    document.getElementById('chk-all').indeterminate = visibleCheckedCount > 0 && visibleCheckedCount < visibleCheckboxes.length;
    document.getElementById('chk-all').checked = visibleCheckedCount > 0 && visibleCheckedCount === visibleCheckboxes.length;
}

function toggleAll(cb) { 
    document.querySelectorAll('#main-table tbody tr:not(.is-hidden) .ts-chk').forEach(c => c.checked = cb.checked); 
    onChkChange(); 
}

function deselectAll() { 
    document.querySelectorAll('.ts-chk').forEach(c => c.checked = false); 
    document.getElementById('chk-all').checked = false; 
    document.getElementById('bulk-toolbar').style.display = 'none'; 
}

function submitBulkExport() { 
    const form = document.getElementById('bulk-form'); 
    form.action = 'export.php'; 
    form.submit(); 
    form.action = 'admin_timesheets.php'; 
}

function submitBulkDelete() { 
    if (confirm('⚠️ Permanently delete all selected records?')) { 
        document.getElementById('bulk-action-field').value = 'delete'; 
        document.getElementById('bulk-form').action = 'admin_timesheets.php'; 
        document.getElementById('bulk-form').submit(); 
    } 
}

function exportFilteredOrAll() {
    const form = document.getElementById('bulk-form');
    form.action = 'export.php';
    
    const checkboxes = document.querySelectorAll('.ts-chk');
    checkboxes.forEach(c => c.disabled = true);

    document.querySelectorAll('.dyn-ts').forEach(e => e.remove());

    const btnExport = document.getElementById('btn-export-all');
    if (btnExport.classList.contains('filtered')) {
        document.querySelectorAll('#main-table tbody tr:not(.is-hidden) .ts-chk').forEach(chk => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'selected_ts[]';
            input.value = chk.value;
            input.className = 'dyn-ts';
            form.appendChild(input);
        });
    }
    
    form.submit();

    form.action = 'admin_timesheets.php'; 
    document.querySelectorAll('.dyn-ts').forEach(e => e.remove()); 
    checkboxes.forEach(c => c.disabled = false);
}

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

document.querySelectorAll('.act').forEach(c => {
    let t;
    c.addEventListener('mouseenter', () => { t = setTimeout(() => c.classList.add('exp'), 500); });
    c.addEventListener('mouseleave', () => { clearTimeout(t); c.classList.remove('exp'); });
});

function toggleSort(e, id) { e.stopPropagation(); document.querySelectorAll('.sort-menu').forEach(m => { if (m.id!==id) m.classList.remove('show-sort'); }); document.getElementById(id).classList.toggle('show-sort'); }
window.addEventListener('click', () => document.querySelectorAll('.sort-menu').forEach(m => m.classList.remove('show-sort')));
function sortT(col, type, dir) {
    const tbody = document.querySelector('#main-table tbody');
    const rows  = Array.from(tbody.querySelectorAll('tr'));
    if (!origRows) origRows = [...rows];
    if (dir === 0) { origRows.forEach(r => tbody.appendChild(r)); return; }
    rows.sort((a, b) => {
        const ca = col === 8 ? parseInt(a.cells[col].dataset.raw||0) : a.cells[col].textContent.trim();
        const cb = col === 8 ? parseInt(b.cells[col].dataset.raw||0) : b.cells[col].textContent.trim();
        if (type === 'alpha') return dir===1 ? ca.localeCompare(cb) : cb.localeCompare(ca);
        if (type === 'date')  return dir===1 ? new Date(ca)-new Date(cb) : new Date(cb)-new Date(ca);
        if (type === 'num')   return dir===1 ? ca-cb : cb-ca;
        return 0;
    });
    rows.forEach(r => tbody.appendChild(r));
}
</script>
</body>
</html>