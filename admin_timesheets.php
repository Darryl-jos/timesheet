<?php
require_once 'config.php';

if (!isset($_SESSION['engineer_id']) || !isset($_SESSION['is_admin']) || ($_SESSION['is_admin'] != 1 && $_SESSION['is_admin'] != 2)) {
    header("Location: login.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['ajax_gantt']) && $_POST['ajax_gantt'] == '1') {
    header('Content-Type: application/json');
    $eng = $_POST['eng'] ?? '';
    $proj = $_POST['proj'] ?? '';
    
    $query = "SELECT t.*, p.project_name, i.target_start_date, i.target_end_date 
              FROM timesheets t 
              JOIN projects p ON t.project_id = p.project_id 
              LEFT JOIN (
                  SELECT project_id, MAX(target_start_date) as target_start_date, MAX(target_end_date) as target_end_date 
                  FROM iips_tracking 
                  GROUP BY project_id
              ) i ON p.project_id = i.project_id 
              WHERE 1=1";
    $params = [];
    $types = "";
    if ($eng !== '') { $query .= " AND t.engineer_name = ?"; $params[] = $eng; $types .= "s"; }
    if ($proj !== '') { $query .= " AND t.project_id = ?"; $params[] = $proj; $types .= "s"; }
    $query .= " ORDER BY t.start_date ASC, t.start_time ASC";
    
    $stmt = $conn->prepare($query);
    if ($types) { $stmt->bind_param($types, ...$params); }
    $stmt->execute();
    $res = $stmt->get_result();
    
    $data = [];
    
    while ($row = $res->fetch_assoc()) {
        $base_group = "";
        if ($eng !== '' && $proj !== '') { 
            $base_group = $row['work_description'] ?: 'Activity'; 
        } else if ($eng !== '') { 
            $base_group = $row['project_name']; 
        } else if ($proj !== '') { 
            $base_group = $row['engineer_name']; 
        } else {
            $base_group = $row['project_name'];
        }
        
        $actual_key = $base_group;

        if (!isset($data[$actual_key])) $data[$actual_key] = [];

        $data[$actual_key][] = [
            'id' => $row['id'],
            'start' => $row['start_date'] . ' ' . $row['start_time'],
            'end' => $row['end_date'] . ' ' . $row['end_time'],
            'type' => 'actual',
            'eng' => $row['engineer_name'],
            'desc' => $row['work_description'] ?: 'Activity'
        ];

        if (!empty($row['target_start_date']) && $row['target_start_date'] !== '0000-00-00') {
            $ts = substr(trim($row['target_start_date']), 0, 10);
            $te = substr(trim($row['target_end_date']), 0, 10);
            
            if ($ts !== '1970-01-01' && $te !== '1970-01-01') {
                $data[$actual_key][] = [
                    'start' => $ts . ' 00:00:00',
                    'end'   => $te . ' 23:59:59',
                    'type'  => 'target'
                ];
            }
        }
    }
    echo json_encode(['data' => $data]);
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
    ORDER BY t.engineer_name ASC, MONTH(t.start_date) ASC, DAY(t.start_date) ASC, YEAR(t.start_date) ASC, t.start_time ASC
");

$proj_list_result = $conn->query("
    SELECT p.project_id, p.project_name, p.customer_name, 
           i.iips_status, i.target_mandays, i.target_start_date, i.target_end_date
    FROM projects p
    LEFT JOIN iips_tracking i ON p.project_id = i.project_id
    ORDER BY p.project_name ASC
");

$years_result = $conn->query("SELECT DISTINCT YEAR(start_date) as ts_year FROM timesheets WHERE start_date IS NOT NULL AND start_date != '0000-00-00' ORDER BY ts_year DESC");
$years = [];
if ($years_result) {
    while ($y_row = $years_result->fetch_assoc()) {
        $years[] = $y_row['ts_year'];
    }
}
if (empty($years)) {
    $years[] = date('Y');
}

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
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
* { box-sizing: border-box; }
body { font-family: Arial, sans-serif; margin: 30px; background: #f4f7f6; color: #333; padding-bottom: 20px; }
.topbar { position: sticky !important; top: 0 !important; z-index: 500 !important; background: #343a40 !important; padding: 15px 20px !important; display: flex !important; border-radius: 8px !important; align-items: center !important; justify-content: space-between !important; gap: 10px !important; flex-wrap: wrap !important; margin-bottom: 16px !important; box-shadow: 0 1px 3px rgba(0,0,0,0.05) !important; }
.topbar h2 { color: white !important; margin: 0 !important; font-size: 18px !important; display: flex !important; align-items: center !important; gap: 8px !important; }
.topbar a { color: #ffc107 !important; text-decoration: none !important; font-size: 13px !important; padding: 6px 12px !important; border-radius: 4px !important; font-weight: bold !important; transition: background 0.2s, color 0.2s !important; }
.topbar a:hover { background: rgba(255, 193, 7, 0.15) !important; color: #ffda6a !important; }.live-dashboard { background: white; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.07); padding: 16px 20px; margin-bottom: 16px; border-left: 5px solid #007bff; display: none; }
.dash-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 10px; }
.dash-item { background: #f8fafc; padding: 10px 14px; border-radius: 6px; border: 1px solid #e2e8f0; }
.dash-label { font-size: 11px; color: #64748b; text-transform: uppercase; font-weight: 700; display: block; margin-bottom: 3px; }
.dash-value { font-size: 18px; font-weight: 700; color: #1e293b; }
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
.sel-wrap { flex: 2; min-width: 150px; position: relative; }
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

/* Changed to Match Scrollable UI */
.tbl-outer { position: relative; padding: 0 20px 20px 20px; }
.tbl-wrap { overflow-x: auto; overflow-y: auto; max-height: 70vh; -webkit-overflow-scrolling: touch; }
.tbl-wrap::-webkit-scrollbar { width: 10px; height: 8px; }
.tbl-wrap::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 5px; }
.tbl-wrap::-webkit-scrollbar-thumb { background: #94a3b8; border-radius: 5px; }
.tbl-wrap::-webkit-scrollbar-thumb:hover { background: #64748b; }
table { width: 100%; border-collapse: collapse; min-width: 1000px; }
th, td { padding: 10px 12px; text-align: left; font-size: 12px; border-bottom: 1px solid #f1f5f9; white-space: nowrap; }
th { background: #f8fafc; font-weight: 600; color: #475569; }

.is-hidden { display: none !important; }
.dr { line-height: 1.4; }
.dr .d-start { color: #1d4ed8; font-weight: 600; font-size: 12px; }
.dr .d-end   { color: #7c3aed; font-weight: 600; font-size: 12px; }
.dr .d-time  { color: #94a3b8; font-size: 11px; }
.dur { background: #d1fae5; color: #065f46; font-weight: bold; padding: 2px 8px; border-radius: 12px; font-size: 11px; white-space: nowrap; }
.dur.multi { background: #dbeafe; color: #1e40af; }
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

/* Sticky headers and columns */
.sec-row th { text-align: center; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; padding: 5px 8px; color: white; border: 1px solid rgba(255,255,255,0.15); position: sticky; top: 0; z-index: 22; }
thead tr:last-child th { position: sticky; top: 27px; z-index: 21; background: #f8fafc; box-shadow: 0 2px 0 #dee2e6; }
.chk-col { width: 36px; position: sticky; left: 0; z-index: 23; background: #ffffff; border-right: 1px solid #dee2e6; }
tbody tr td.chk-col { background: #ffffff; }
tbody tr:hover { background: #f8faff; }
tbody tr:hover td.chk-col { background: #f8faff; }
thead tr:last-child th.chk-col { z-index: 25; background: #f8fafc; }
thead tr.sec-row th.chk-col { z-index: 26; background: #343a40; }

.s-base     { background: #343a40; }
.s-timeline { background: #1a237e; }
.s-actual   { background: #004d40; }
.s-act      { background: #343a40; }

.gantt-section { margin-top:20px; display:none; background:white; border-radius:8px; box-shadow:0 1px 3px rgba(0,0,0,0.07); }
.gantt-header { padding: 12px 20px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; }
.gantt-header h3 { margin:0; font-size:15px; }
.gantt-container { overflow-x:auto; border:1px solid #e5e7eb; border-radius:6px; position:relative; scroll-behavior: smooth; }
.gantt-chart-inner { width:max-content; min-width:100%; display:flex; flex-direction:column; padding-bottom: 70px; position: relative; }
.gantt-row { display:flex; border-bottom:1px solid #e5e7eb; position:relative; min-height:52px; }
.gantt-row:last-child { border-bottom:none; }
.gantt-y-axis { width:150px; flex-shrink:0; background:#f8fafc; border-right:1px solid #e5e7eb; padding:10px; font-size:12px; font-weight:bold; color:#333; display:flex; align-items:center; z-index:12; position:sticky; left:0; transition: background 0.2s; cursor: pointer; user-select: none; }
.gantt-y-axis:hover { background: #e2e8f0; }
.gantt-x-axis { flex-grow:1; position:relative; display:flex; }
.gantt-grid { position:absolute; top:0; left:0; right:0; bottom:0; display:flex; z-index:0; pointer-events:none; }
.gantt-grid-line { flex-grow:1; border-right:1px dashed #e5e7eb; box-sizing:border-box; min-width: 120px; flex-shrink: 0; }
.gantt-grid-line:last-child { border-right:none; }
.gantt-bars { position:absolute; top:0; left:0; right:0; bottom:0; z-index:1; }
.gantt-bar { position:absolute; border-radius:4px; transition:opacity 0.2s; cursor:pointer; }
.gantt-bar.target { background: #3b82f6; height: 14px; z-index: 1; border: 1px solid #1d4ed8; box-shadow: 0 1px 3px rgba(0,0,0,0.2); }
.gantt-bar.target:hover { background:#2563eb; }
.gantt-bar.actual { background: #22c55e; height: 14px; z-index: 2; border: 1px solid #16a34a; box-shadow: 0 1px 3px rgba(0,0,0,0.2); }
.gantt-bar.actual:hover { background:#15803d; }
.gantt-header-row { background:#f1f5f9; font-weight:bold; min-height: 40px; }
.gantt-header-row .gantt-y-axis { background:#f1f5f9; z-index: 15; cursor: default; }
.gantt-header-row .gantt-y-axis:hover { background: #f1f5f9; }
.gantt-tick { flex-grow:1; border-right:1px solid #d1d5db; padding:4px 0px; text-align:center; color:#475569; overflow:hidden; box-sizing:border-box; min-width: 120px; font-size: 10px; white-space:nowrap; text-overflow:ellipsis; flex-shrink: 0; }
.gantt-tick:last-child { border-right:none; }
.gantt-tick.prev-year-end { background: #fef08a; border-right: 2px solid #eab308; }
.gantt-tooltip { position: absolute; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 12px; font-size: 11px; color: #334155; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15); transform: translateX(-50%); cursor: default; z-index: 999; }
.gantt-tooltip::before { content: ""; position: absolute; top: -6px; left: 50%; transform: translateX(-50%); border-width: 0 6px 6px 6px; border-style: solid; border-color: transparent transparent #cbd5e1 transparent; }
.gantt-tooltip::after { content: ""; position: absolute; top: -5px; left: 50%; transform: translateX(-50%); border-width: 0 5px 5px 5px; border-style: solid; border-color: transparent transparent #ffffff transparent; }
.charts-section { display:none; background:white; border-radius:8px; box-shadow:0 1px 3px rgba(0,0,0,0.07); margin-top:20px; }
.charts-grid { display:flex; flex-wrap:wrap; padding:20px; gap:20px; }
.chart-box { flex:1; min-width:300px; height:300px; }
.chart-summary { padding:20px; border-top:1px solid #e5e7eb; }
.chart-summary ul { list-style:none; padding:0; margin:0; font-size:13px; color:#475569; }
.chart-summary li { padding:6px 0; border-bottom:1px solid #f1f5f9; display:flex; justify-content:space-between; }
.chart-summary li:last-child { border-bottom:none; }
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
    .card { padding: 0; }
    .card-hdr { padding: 12px; }
    .tbl-outer { padding: 12px 12px 0 12px; }
}
</style>
<script>
const projData = {
<?php
if ($proj_list_result) {
    while ($p = $proj_list_result->fetch_assoc()) {
        $t_start_date = !empty($p['target_start_date']) ? $p['target_start_date'] : '';
        $t_end_date = !empty($p['target_end_date']) ? $p['target_end_date'] : '';
        $status = !empty($p['iips_status']) ? $p['iips_status'] : 'Not Quoted';
        $mandays = isset($p['target_mandays']) && $p['target_mandays'] !== '' ? $p['target_mandays'] : '';
        echo '"' . addslashes($p['project_id']) . '": { status: "' . addslashes($status) . '", target_start_date: "' . addslashes($t_start_date) . '", target_date: "' . addslashes($t_end_date) . '", target_mandays: "' . addslashes($mandays) . '" },';
    }
    $proj_list_result->data_seek(0);
}
?>
};
</script>
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

    <div class="filter-panel">
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
                    <span id="proj-label">All IIPS</span>
                    <span class="sel-arrow">▾</span>
                </div>
                <div class="sel-panel" id="proj-panel">
                    <input type="text" id="proj-inner" placeholder="Type to search..." oninput="filterSel('proj')" onclick="event.stopPropagation()">
                    <div class="sel-list" id="proj-list">
                        <div class="sel-item active" data-value="" onclick="pickSel('proj','','All IIPS',this)">All IIPS</div>
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

    <div class="live-dashboard" id="live-dashboard"></div>

    <form id="bulk-form" method="POST" action="admin_timesheets.php">
        <input type="hidden" name="bulk_action" id="bulk-action-field" value="">

        <div class="card">
            <div class="card-hdr">
                <h3>Employee Work Hour Compliance Logs</h3>
                <button type="button" id="btn-export-all" class="btn-export-all" onclick="exportFilteredOrAll()">📥 Export All</button>
            </div>
            <div class="tbl-outer" id="tbl-outer">
            <div class="tbl-wrap" id="tbl-wrap">
            <table id="main-table">
                <thead>
                    <tr class="sec-row">
                        <th class="chk-col s-base" rowspan="2"><input type="checkbox" id="chk-all" onchange="toggleAll(this)"></th>
                        <th class="s-base" colspan="2">Engineer & Project</th>
                        <th class="s-base" colspan="2">IIPS Details</th>
                        <th class="s-base" colspan="1">Activity</th>
                        <th class="s-timeline" colspan="2">Timeline</th>
                        <th class="s-actual" colspan="1">Performance</th>
                        <th class="s-act" rowspan="2">Actions</th>
                    </tr>
                    <tr>
                        <th><div class="sort-wrap">Engineer<button type="button" class="sort-btn" onclick="toggleSort(event,'s-eng')"></button><div id="s-eng" class="sort-menu"><a href="#" onclick="sortT(1,'alpha',0);return false;">Default</a><a href="#" onclick="sortT(1,'alpha',1);return false;">A → Z</a><a href="#" onclick="sortT(1,'alpha',2);return false;">Z → A</a></div></div></th>
                        <th><div class="sort-wrap">IIPS ID<button type="button" class="sort-btn" onclick="toggleSort(event,'s-pid')"></button><div id="s-pid" class="sort-menu"><a href="#" onclick="sortT(2,'alpha',0);return false;">Default</a><a href="#" onclick="sortT(2,'alpha',1);return false;">A → Z</a><a href="#" onclick="sortT(2,'alpha',2);return false;">Z → A</a></div></div></th>
                        <th>Customer</th>
                        <th>IIPS Name</th>
                        <th>Activity</th>
                        <th><div class="sort-wrap">Start Date<button type="button" class="sort-btn" onclick="toggleSort(event,'s-sd')"></button><div id="s-sd" class="sort-menu"><a href="#" onclick="sortT(6,'date',0);return false;">Default</a><a href="#" onclick="sortT(6,'date',1);return false;">Oldest First</a><a href="#" onclick="sortT(6,'date',2);return false;">Newest First</a></div></div></th>
                        <th>End Date</th>
                        <th><div class="sort-wrap">Duration<button type="button" class="sort-btn" onclick="toggleSort(event,'s-dur')"></button><div id="s-dur" class="sort-menu"><a href="#" onclick="sortT(8,'num',0);return false;">Default</a><a href="#" onclick="sortT(8,'num',1);return false;">Shortest First</a><a href="#" onclick="sortT(8,'num',2);return false;">Longest First</a></div></div></th>
                    </tr>
                </thead>
                <tbody>
                <tr id="empty-row" class="is-hidden"><td colspan="10" style="text-align:center;padding:40px;height:410px;vertical-align:middle;color:#9ca3af;">No matching logs found.</td></tr>
                <?php if (!empty($rows_cache)): foreach ($rows_cache as $row):
                    $mins  = $row['_minutes'];
                    $h     = floor($mins / 60);
                    $m     = $mins % 60;
                    $dur_text = $h . 'h ' . $m . 'm';
                    $is_multi = ($row['start_date'] !== $row['end_date']);
                ?>
                <tr data-pid="<?= htmlspecialchars($row['project_id']) ?>"
                    data-eng="<?= htmlspecialchars($row['engineer_name']) ?>"
                    data-sd="<?= htmlspecialchars($row['start_date']) ?>"
                    data-ed="<?= htmlspecialchars($row['end_date']) ?>"
                    data-mins="<?= $mins ?>">
                    <td class="chk-col"><input type="checkbox" class="ts-chk" name="selected_ts[]" value="<?= $row['id'] ?>" onchange="onChkChange()"></td>
                    <td><strong><?= htmlspecialchars($row['engineer_name']) ?></strong></td>
                    <td><code style="font-size:11px;"><?= preg_match('/^N[\/.\-]?A/i', $row['project_id']) ? '<span style=\'color:#9ca3af;\'>—</span>' : htmlspecialchars($row['project_id']) ?></code></td>
                    <td style="font-size:11px;"><?= htmlspecialchars($row['customer_name']) ?></td>
                    <td style="font-size:11px;"><?= htmlspecialchars($row['project_name']) ?></td>
                    <td><div class="act"><?= htmlspecialchars($row['work_description'] ?: 'No description') ?></div></td>
                    <td><span class="d-start"><?= fmtDate($row['start_date']) ?></span><span class="d-time"> <?= htmlspecialchars(substr($row['start_time'],0,5)) ?></span></td>
                    <td><span class="d-end"><?= fmtDate($row['end_date']) ?></span><span class="d-time"> <?= htmlspecialchars(substr($row['end_time'],0,5)) ?></span></td>
                    <td data-raw="<?= $mins ?>"><span class="dur <?= $is_multi ? 'multi' : '' ?>"><?= $dur_text ?></span></td>
                    <td><a href="edit.php?edit=<?= $row['id'] ?>" class="btn-edit">Edit</a><a href="admin_timesheets.php?delete_ts=<?= $row['id'] ?>" class="btn-del" onclick="return confirm('Delete this log?')">Delete</a></td>
                </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
            </div>
            </div>
        </div>
    </form>

    <section id="gantt-section" class="gantt-section card">
        <div class="gantt-header card-hdr">
            <div style="display: flex; flex-direction: column; gap: 4px;">
                <h3 id="gantt-title">Gantt Chart</h3>
                <div style="display:flex; gap:20px;">
                    <span style="font-size:12px; color:#475569; font-weight:bold;">
                        <span style="display:inline-block;width:14px;height:14px;background:#3b82f6;border:1px solid #1d4ed8;border-radius:3px;margin-right:6px;vertical-align:-2px;"></span>Target Date
                    </span>
                    <span style="font-size:12px; color:#475569; font-weight:bold;">
                        <span style="display:inline-block;width:14px;height:14px;background:#22c55e;border:1px solid #16a34a;border-radius:3px;margin-right:6px;vertical-align:-2px;"></span>Actual Work
                    </span>
                </div>
            </div>
            <div style="display:flex; align-items:center; gap:15px; flex-wrap:wrap;">
                <label style="font-size:12px; font-weight:bold; color:#475569; display:flex; align-items:center; gap:4px; cursor:pointer; user-select:none;">
                    <input type="checkbox" id="chk-show-target" onchange="toggleTargetDates()" style="cursor:pointer; width:14px; height:14px;">
                    Show Target Date
                </label>
                <div class="sel-wrap" id="year-wrap">
                    <div class="sel-box" id="year-box" onclick="toggleSel('year')">
                        <span id="year-label">Year: All</span>
                        <span class="sel-arrow">▾</span>
                    </div>
                    <div class="sel-panel" id="year-panel">
                        <div class="sel-list" id="year-list">
                            <div class="sel-item active" data-value="all" onclick="pickSel('year','all','Year: All',this)">All</div>
                            <?php foreach($years as $yr): ?>
                            <div class="sel-item" data-value="<?= $yr ?>" onclick="pickSel('year','<?= $yr ?>','Year: <?= $yr ?>',this)">
                                <?= $yr ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div style="padding: 20px;">
            <div class="gantt-container">
                <div id="gantt-chart-inner" class="gantt-chart-inner"></div>
            </div>
        </div>
    </section>

    <div id="charts-section" class="charts-section card">
        <div class="card-hdr">
            <h3 id="charts-title">Analysis</h3>
        </div>
        <div class="charts-grid" style="display:block;">
            <div class="chart-box" style="width:100%;"><canvas id="barChart"></canvas></div>
        </div>
        <div id="chart-summary" class="chart-summary"></div>
    </div>
</div>

<script>
let activeProjFilter = '';
let activeEngFilter  = '';
let activeYearFilter = 'all';
let showTargetDates  = false;
let origRows = null;
let ganttData = {};

window.ganttScrollMap = {};
window.ganttScrollIdx = {};
window.ganttScrollDir = {};
window.barChartInst = null;

window.cycleGroupScroll = function(groupId, viewMinT, totalRange) {
    let times = window.ganttScrollMap[groupId];
    if (!times || times.length === 0) return;
    
    if (times.length === 1) {
        let leftPerc = (times[0] - viewMinT) / totalRange;
        const xAxis = document.querySelector('.gantt-row .gantt-x-axis');
        const container = document.querySelector('.gantt-container');
        if (xAxis && container) {
            const offset = container.clientWidth / 2; 
            const targetX = (leftPerc * xAxis.offsetWidth) - offset; 
            container.scrollLeft = Math.max(0, targetX);
        }
        return;
    }

    if (!window.ganttScrollDir[groupId]) window.ganttScrollDir[groupId] = 1;
    let idx = window.ganttScrollIdx[groupId] || 0;
    
    idx += window.ganttScrollDir[groupId];
    
    if (idx >= times.length) {
        window.ganttScrollDir[groupId] = -1;
        idx = times.length - 2;
    } else if (idx < 0) {
        window.ganttScrollDir[groupId] = 1;
        idx = 1;
    }
    
    window.ganttScrollIdx[groupId] = idx;
    let t = times[idx];
    
    let leftPerc = (t - viewMinT) / totalRange;
    const xAxis = document.querySelector('.gantt-row .gantt-x-axis');
    const container = document.querySelector('.gantt-container');
    if (xAxis && container) {
        const offset = container.clientWidth / 2; 
        const targetX = (leftPerc * xAxis.offsetWidth) - offset; 
        container.scrollLeft = Math.max(0, targetX);
    }
};

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
    if (!isOpen) { wrap.classList.add('open'); if(document.getElementById(type+'-inner')) document.getElementById(type+'-inner').focus(); }
}
function filterSel(type) {
    if(!document.getElementById(type+'-inner')) return;
    const val = document.getElementById(type+'-inner').value.toLowerCase();
    document.querySelectorAll('#'+type+'-list .sel-item').forEach(item => {
        if (!item.dataset.value) { item.classList.remove('hidden'); return; }
        item.classList.toggle('hidden', !!val && !(item.dataset.kw||'').includes(val));
    });
}
function pickSel(type, value, label, el) {
    if (type === 'proj') { activeProjFilter = value; document.getElementById('proj-label').textContent = label; }
    else if (type === 'eng') { activeEngFilter = value; document.getElementById('eng-label').textContent = label; }
    else if (type === 'year') { activeYearFilter = value; document.getElementById('year-label').textContent = label; }
    document.querySelectorAll('#'+type+'-list .sel-item').forEach(i => i.classList.remove('active'));
    el.classList.add('active');
    document.getElementById(type+'-wrap').classList.remove('open');
    if(document.getElementById(type+'-inner')) {
        document.getElementById(type+'-inner').value = '';
        filterSel(type);
    }
    doFilter();
}
document.addEventListener('click', e => { if (!e.target.closest('.sel-wrap')) document.querySelectorAll('.sel-wrap').forEach(w => w.classList.remove('open')); });

function toggleTargetDates() {
    showTargetDates = document.getElementById('chk-show-target').checked;
    if (Object.keys(ganttData).length > 0) {
        renderGantt();
    }
}

function doFilter() {
    const txt       = document.getElementById('txt-search').value.toLowerCase();
    const dateStart = document.getElementById('date-start').value;
    const dateEnd   = document.getElementById('date-end').value;
    let visRows = [];

    document.querySelectorAll('#main-table tbody tr[data-pid]').forEach(tr => {
        const rPid = tr.dataset.pid || '';
        const rEng = tr.dataset.eng || '';
        const rSd  = tr.dataset.sd  || '';
        const rTxt = tr.textContent.toLowerCase();
        const ok = (!txt              || rTxt.includes(txt))
                && (!activeProjFilter || rPid === activeProjFilter)
                && (!activeEngFilter  || rEng === activeEngFilter)
                && (activeYearFilter === 'all' || rSd.startsWith(activeYearFilter))
                && (!dateStart        || rSd  >= dateStart)
                && (!dateEnd          || rSd  <= dateEnd);
        
        if (ok) {
            tr.classList.remove('is-hidden');
            visRows.push(tr);
        } else {
            tr.classList.add('is-hidden');
        }
    });

    let emptyTr = document.getElementById('empty-row');
    if (visRows.length === 0) {
        if (emptyTr) emptyTr.classList.remove('is-hidden');
    } else {
        if (emptyTr) emptyTr.classList.add('is-hidden');
    }

    updateLiveDashboard(visRows);
    
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

    if (activeEngFilter !== '' || activeProjFilter !== '') {
        fetchGanttData(activeEngFilter, activeProjFilter);
    } else {
        document.getElementById('gantt-section').style.display = 'none';
        document.getElementById('charts-section').style.display = 'none';
    }
}

function clearAllFilters() {
    document.getElementById('txt-search').value = '';
    document.getElementById('date-start').value = '';
    document.getElementById('date-end').value = '';
    document.getElementById('date-start-display').value = '';
    document.getElementById('date-end-display').value = '';
    
    activeProjFilter = '';
    document.getElementById('proj-label').textContent = 'All IIPS';
    document.querySelectorAll('#proj-list .sel-item').forEach(i => i.classList.remove('active'));
    document.querySelector('#proj-list .sel-item[data-value=""]').classList.add('active');
    
    activeEngFilter = '';
    document.getElementById('eng-label').textContent = 'All Engineers';
    document.querySelectorAll('#eng-list .sel-item').forEach(i => i.classList.remove('active'));
    document.querySelector('#eng-list .sel-item[data-value=""]').classList.add('active');

    activeYearFilter = 'all';
    document.getElementById('year-label').textContent = 'Year: All';
    document.querySelectorAll('#year-list .sel-item').forEach(i => i.classList.remove('active'));
    document.querySelector('#year-list .sel-item[data-value="all"]').classList.add('active');

    doFilter();
}

function fmtDateJS(ymd) {
    if (!ymd) return '';
    const dt = ymd.split(' ')[0];
    const p = dt.split('-');
    if(p.length !== 3) return ymd;
    const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return parseInt(p[2], 10) + ' ' + months[parseInt(p[1], 10)-1] + ' ' + p[0];
}

function updateLiveDashboard(visRows) {
    const dash = document.getElementById('live-dashboard');
    if (!activeProjFilter) { 
        dash.style.display = 'none'; 
        return; 
    }

    const pData = projData[activeProjFilter] || {status:'', target_start_date:'', target_date:'', target_mandays:''};
    let statusStr = pData.status || 'Not Quoted';

    let totalMins = 0;
    const engSet  = new Set();
    let actualMinDate = '';
    let actualMaxDate = '';

    visRows.forEach(tr => {
        totalMins += parseInt(tr.dataset.mins) || 0;
        if (tr.dataset.eng) engSet.add(tr.dataset.eng);
        const sd = tr.dataset.sd || '';
        const ed = tr.dataset.ed || '';
        if (sd && (!actualMinDate || sd < actualMinDate)) actualMinDate = sd;
        if (ed && (!actualMaxDate || ed > actualMaxDate)) actualMaxDate = ed;
    });

    const totalLogs = visRows.length;
    const engCount = engSet.size;

    let targetMins = (parseFloat(pData.target_mandays) || 0) * 8 * 60;
    let th = Math.floor(targetMins / 60);
    let tm_rem = Math.round(targetMins % 60);
    let ah = Math.floor(totalMins / 60);
    let am = totalMins % 60;

    let tsDate = pData.target_start_date ? fmtDateJS(pData.target_start_date) : '-';
    let teDate = pData.target_date ? fmtDateJS(pData.target_date) : '-';
    let targetDateStr = tsDate + ' &mdash; ' + teDate;

    let asDate = actualMinDate ? fmtDateJS(actualMinDate) : '-';
    let aeDate = '-';
    if (statusStr.toLowerCase() === 'completed') {
        aeDate = actualMaxDate ? fmtDateJS(actualMaxDate) : '-';
    }
    let actualDateStr = asDate + ' &mdash; ' + aeDate;

    let utilHtml = '';
    if (targetMins > 0) {
        let pct = (totalMins / targetMins) * 100;
        let fillCol = pct > 100 ? '#dc3545' : '#28a745';
        let dispPct = pct;
        let overText = '';
        if (pct > 100) {
            let overMins = totalMins - targetMins;
            overText = `<span style="color:#dc3545;font-size:11px;font-weight:bold;margin-top:4px;display:block;">▲ ${Math.floor(overMins/60)}h ${overMins%60}m Over</span>`;
        }
        utilHtml = `<div style="width:100%; margin-top:4px;"><div style="text-align:right; font-size:11px; font-weight:bold; color:#0f172a; margin-bottom:4px;">${parseFloat(pct.toFixed(1))}%</div><div style="width:100%; background:#e2e8f0; height:8px; border-radius:4px; overflow:visible; position:relative;"><div style="width:${dispPct}%; background:${fillCol}; height:100%; border-radius:4px; transition:width 0.3s; max-width:200%;"></div></div></div>${overText}`;
    } else {
        utilHtml = `<span style="color:#64748b;font-size:12px;">No Target</span>`;
    }

    let overdueHtml = '';
    if (pData.target_date && actualMaxDate && actualMaxDate > pData.target_date) {
        overdueHtml = `<span style="color:#dc3545;font-weight:bold;font-size:12px;margin-left:8px;">⚠ Overdue</span>`;
    }

    dash.innerHTML = `<div class="dash-grid" style="margin-bottom:12px;"><div class="dash-item"><span class="dash-label">Total Logs</span><div class="dash-value">${totalLogs}</div></div><div class="dash-item"><span class="dash-label">Engineers</span><div class="dash-value">${engCount}</div></div><div class="dash-item"><span class="dash-label">IIPS Status</span><div class="dash-value" style="color:#16a34a;">${statusStr}</div></div><div class="dash-item"><span class="dash-label">Utilization</span><div class="dash-value" style="font-size:13px;display:flex;flex-wrap:wrap;align-items:center;">${utilHtml}${overdueHtml}</div></div></div><div class="dash-grid"><div class="dash-item"><span class="dash-label">Target Date</span><div class="dash-value" style="font-size:14px;">${targetDateStr}</div></div><div class="dash-item"><span class="dash-label">Actual Date</span><div class="dash-value" style="font-size:14px;">${actualDateStr}</div></div><div class="dash-item"><span class="dash-label">Target Time</span><div class="dash-value" style="font-size:14px;">${targetMins > 0 ? th + 'h ' + tm_rem + 'm' : '—'}</div></div><div class="dash-item"><span class="dash-label">Actual Time</span><div class="dash-value" style="font-size:14px;">${ah}h ${am}m</div></div></div>`;
    dash.style.display = 'block';
}

function onChkChange() {
    const checked = document.querySelectorAll('.ts-chk:checked').length;
    const toolbar = document.getElementById('bulk-toolbar');
    toolbar.style.display = checked > 0 ? 'flex' : 'none';
    document.getElementById('bulk-count').textContent = checked + ' selected';
    
    const visibleCheckboxes = document.querySelectorAll('#main-table tbody tr:not(.is-hidden) .ts-chk');
    let visibleCheckedCount = 0;
    visibleCheckboxes.forEach(c => { if(c.checked) visibleCheckedCount++; });
    
    document.getElementById('chk-all').indeterminate = visibleCheckedCount > 0 && visibleCheckedCount < visibleCheckboxes.length;
    document.getElementById('chk-all').checked = visibleCheckedCount > 0 && visibleCheckedCount === visibleCheckboxes.length && visibleCheckboxes.length > 0;
}

function toggleAll(cb) { 
    document.querySelectorAll('#main-table tbody tr:not(.is-hidden) .ts-chk').forEach(c => {
        if(c.closest('tr').id !== 'empty-row') c.checked = cb.checked;
    }); 
    onChkChange(); 
}

function deselectAll() { 
    document.querySelectorAll('.ts-chk').forEach(c => c.checked = false); 
    document.getElementById('chk-all').checked = false; 
    document.getElementById('chk-all').indeterminate = false;
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
        document.querySelectorAll('#main-table tbody tr:not(.is-hidden)').forEach(tr => {
            const chk = tr.querySelector('.ts-chk');
            if (chk) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'selected_ts[]';
                input.value = chk.value;
                input.className = 'dyn-ts';
                form.appendChild(input);
            }
        });
    } else {
        document.querySelectorAll('#main-table tbody tr[data-pid]').forEach(tr => {
            const chk = tr.querySelector('.ts-chk');
            if (chk) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'selected_ts[]';
                input.value = chk.value;
                input.className = 'dyn-ts';
                form.appendChild(input);
            }
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
window.addEventListener('DOMContentLoaded', () => {
    fixStickyHeaders();
    doFilter();
});
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
    const rows  = Array.from(tbody.querySelectorAll('tr[data-pid]'));
    if (!origRows) origRows = [...rows];
    if (dir === 0) { 
        origRows.forEach(r => tbody.appendChild(r)); 
        doFilter();
        return; 
    }
    rows.sort((a, b) => {
        const ca = col === 8 ? parseInt(a.cells[col].dataset.raw||0) : a.cells[col].textContent.trim();
        const cb = col === 8 ? parseInt(b.cells[col].dataset.raw||0) : b.cells[col].textContent.trim();
        if (type === 'alpha') return dir===1 ? ca.localeCompare(cb) : cb.localeCompare(ca);
        if (type === 'date')  return dir===1 ? new Date(ca)-new Date(cb) : new Date(cb)-new Date(ca);
        if (type === 'num')   return dir===1 ? ca-cb : cb-ca;
        return 0;
    });
    rows.forEach(r => tbody.appendChild(r));
    doFilter();
}

function toggleGanttTooltip(e, el, stStr, etStr, mins, barId, type) {
    e.stopPropagation();
    const layer = document.getElementById('gantt-tooltip-layer');
    const existing = layer.querySelector(`.gantt-tooltip[data-bar-id="${barId}"]`);
    
    if (existing) {
        existing.remove();
        return;
    }

    let durText = '';
    if (type === 'target') {
        const sDate = new Date(stStr.replace(/-/g,'/'));
        const eDate = new Date(etStr.replace(/-/g,'/'));
        let d = Math.round(Math.abs(eDate - sDate) / 86400000);
        if (d === 0) d = 1;
        let th = d * 8; 
        durText = th + 'h 0m';
    } else {
        const h = Math.floor(mins / 60);
        const m = Math.floor(mins % 60);
        durText = h + 'h ' + m + 'm';
    }

    const titleText = type === 'target' ? '🎯 Target Date' : '⏱ Actual Work';

    const tt = document.createElement('div');
    tt.className = 'gantt-tooltip';
    tt.dataset.barId = barId;
    tt.style.pointerEvents = 'auto'; 
    tt.innerHTML = `<div style="font-weight:bold;color:#0f172a;margin-bottom:4px;white-space:nowrap;">${titleText} (${durText})</div><div style="color:#64748b;white-space:nowrap;">Start: ${stStr}</div><div style="color:#64748b;white-space:nowrap;">End: ${etStr}</div>`;
    layer.appendChild(tt);

    const barRect = el.getBoundingClientRect();
    const innerRect = document.getElementById('gantt-chart-inner').getBoundingClientRect();
    const scrollContainer = document.querySelector('.gantt-container');

    const topPos = barRect.bottom - innerRect.top;
    let leftPos = (barRect.left + barRect.width / 2) - innerRect.left;

    const scrollLeft = scrollContainer.scrollLeft;
    const scrollRight = scrollLeft + scrollContainer.clientWidth;
    const visibleLeft = scrollLeft + 150; 
    const visibleRight = scrollRight;

    let clampedLeft = leftPos;
    if (leftPos < visibleLeft + 80) clampedLeft = visibleLeft + 80;
    if (leftPos > visibleRight - 80) clampedLeft = visibleRight - 80;

    tt.style.top = (topPos + 6) + 'px';
    tt.style.left = clampedLeft + 'px';
}

function fetchGanttData(eng, proj) {
    const fd = new FormData();
    fd.append('ajax_gantt', '1');
    fd.append('eng', eng);
    fd.append('proj', proj);
    fetch('admin_timesheets.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
        ganttData = res.data;
        const sec = document.getElementById('gantt-section');
        const title = document.getElementById('gantt-title');
        sec.style.display = 'block';
        const engLabel = document.getElementById('eng-label').textContent;
        const projLabel = document.getElementById('proj-label').textContent;
        if (eng && proj) title.textContent = `Gantt Chart for "${engLabel}" working on "${projLabel}"`;
        else if (eng) title.textContent = `Gantt Chart for "${engLabel}"`;
        else title.textContent = `Gantt Chart for "${projLabel}"`;
        renderGantt();
        renderCharts();
    });
}

function renderCharts() {
    const sec = document.getElementById('charts-section');
    if (!activeProjFilter) { sec.style.display = 'none'; return; }
    
    const pData = projData[activeProjFilter];
    if (!pData || pData.status.toLowerCase() !== 'completed') { sec.style.display = 'none'; return; }

    sec.style.display = 'block';

    const isModeB = (activeEngFilter !== '');
    const titleText = isModeB ? 'Engineer Timesheet Analysis' : 'Project Engineer Contribution Analysis';
    document.getElementById('charts-title').textContent = titleText;

    let barLabels = [];
    let barData = [];
    let summaryHtml = '<ul>';
    let tooltipsRaw = [];

    if (!isModeB) {
        let engMins = {};
        let totalMins = 0;

        Object.values(ganttData).forEach(grp => {
            grp.forEach(s => {
                if (s.type === 'actual') {
                    let st = new Date(s.start.replace(/-/g,'/'));
                    let et = new Date(s.end.replace(/-/g,'/'));
                    let m = (et - st) / 60000;
                    if (m < 0) m = 0;
                    let eName = s.eng || 'Unknown';
                    if (!engMins[eName]) engMins[eName] = 0;
                    engMins[eName] += m;
                    totalMins += m;
                }
            });
        });

        for (let e in engMins) {
            let p = totalMins > 0 ? ((engMins[e] / totalMins) * 100).toFixed(2) : 0;
            let hStr = (engMins[e] / 60).toFixed(2);
            barLabels.push(e);
            barData.push(hStr);
            tooltipsRaw.push({ name: e, val: hStr, perc: p, mins: engMins[e] });
        }

        tooltipsRaw.sort((a, b) => b.mins - a.mins);
        
        tooltipsRaw.forEach(t => {
            let h = Math.floor(t.mins / 60);
            let rm = Math.round(t.mins % 60);
            summaryHtml += `<li><div><strong>${t.name}</strong></div><div>${h}h ${rm}m <span style="color:#2563eb;font-weight:bold;margin-left:10px;">${t.perc}%</span></div></li>`;
        });
        summaryHtml += '</ul>';

    } else {
        let tsList = [];
        let totalMins = 0;

        Object.values(ganttData).forEach(grp => {
            grp.forEach(s => {
                if (s.type === 'actual') {
                    let st = new Date(s.start.replace(/-/g,'/'));
                    let et = new Date(s.end.replace(/-/g,'/'));
                    let m = (et - st) / 60000;
                    if (m < 0) m = 0;
                    tsList.push({
                        date: s.start.substring(0, 10),
                        desc: s.desc,
                        mins: m
                    });
                    totalMins += m;
                }
            });
        });

        tsList.sort((a, b) => new Date(a.date.replace(/-/g,'/')) - new Date(b.date.replace(/-/g,'/')));

        tsList.forEach((t, idx) => {
            let lbl = t.date + ' (#' + (idx + 1) + ')';
            let p = totalMins > 0 ? ((t.mins / totalMins) * 100).toFixed(2) : 0;
            let hStr = (t.mins / 60).toFixed(2);
            
            barLabels.push(lbl);
            barData.push(hStr);
            tooltipsRaw.push({ name: lbl, act: t.desc, val: hStr, perc: p, mins: t.mins });
        });

        tooltipsRaw.forEach(t => {
            let h = Math.floor(t.mins / 60);
            let rm = Math.round(t.mins % 60);
            summaryHtml += `<li><div style="flex:1;"><strong>${t.name.split(' ')[0]}</strong> <span style="color:#64748b;font-size:11px;">- ${t.act}</span></div><div>${h}h ${rm}m <span style="color:#2563eb;font-weight:bold;margin-left:10px;">${t.perc}%</span></div></li>`;
        });
        summaryHtml += '</ul>';
    }

    document.getElementById('chart-summary').innerHTML = summaryHtml;

    if (window.barChartInst) window.barChartInst.destroy();

    const ctxBar = document.getElementById('barChart').getContext('2d');
    window.barChartInst = new Chart(ctxBar, {
        type: 'bar',
        data: {
            labels: barLabels,
            datasets: [{
                label: 'Hours',
                data: barData,
                backgroundColor: '#3b82f6',
                maxBarThickness: 40
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    border: {
                        display: true
                    },
                    grid: {
                        display: false
                    },
                    ticks: {
                        crossAlign: 'far'
                    },
                    afterFit: function(scale) {
                        scale.width = scale.chart.width / 3;
                    }
                }
            },
            plugins: {
                tooltip: {
                    callbacks: {
                        label: function(ctx) {
                            let idx = ctx.dataIndex;
                            return tooltipsRaw[idx].val + 'h (' + tooltipsRaw[idx].perc + '%)';
                        }
                    }
                }
            }
        }
    });
}

function renderGantt() {
    const container = document.getElementById('gantt-chart-inner');
    container.innerHTML = '';

    let workingData = {};
    let globalTargetSegment = null;

    Object.keys(ganttData).forEach(g => {
        workingData[g] = [];
        ganttData[g].forEach(s => {
            if (s.type === 'target') {
                if (activeProjFilter !== '') {
                    if (!globalTargetSegment) {
                        if (s.start && s.end && !s.start.startsWith('1970') && !s.end.startsWith('1970')) {
                            globalTargetSegment = {...s};
                        }
                    }
                } else {
                    workingData[g].push({...s});
                }
            } else {
                workingData[g].push({...s});
            }
        });
        if (workingData[g].length === 0) delete workingData[g];
    });

    if (showTargetDates && activeProjFilter !== '' && globalTargetSegment) {
        let sd = globalTargetSegment.start.substring(0,10).split('-');
        let ed = globalTargetSegment.end.substring(0,10).split('-');
        let label = `${sd[2]}/${sd[1]}/${sd[0]} &mdash; ${ed[2]}/${ed[1]}/${ed[0]}`;
        let tgKey = 'TARGET_ROW|' + label;
        workingData[tgKey] = [globalTargetSegment];
    }

    const rawGroups = Object.keys(workingData);
    if (rawGroups.length === 0) {
        container.innerHTML = '<div style="padding:40px;text-align:center;color:#64748b;font-weight:bold;">No data available</div>';
        return;
    }

    const dsVal = document.getElementById('date-start').value;
    const deVal = document.getElementById('date-end').value;

    let filterStartT = dsVal ? new Date(dsVal + 'T00:00:00').getTime() : -Infinity;
    let filterEndT = deVal ? new Date(deVal + 'T23:59:59').getTime() : Infinity;

    let filteredData = {};
    let activeMinT = Infinity;
    let activeMaxT = -Infinity;
    let currentYear = new Date().getFullYear();
    let selectedYear = activeYearFilter === 'all' ? currentYear : parseInt(activeYearFilter);

    rawGroups.forEach(g => {
        let keepGroup = false;

        workingData[g].forEach(s => {
            if (s.type === 'target' && !showTargetDates) return;

            let st = new Date(s.start.replace(/-/g,'/')).getTime();
            let et = new Date(s.end.replace(/-/g,'/')).getTime();
            if (st > et) { let tmp = st; st = et; et = tmp; }
            
            let stYr = new Date(st).getFullYear();
            let etYr = new Date(et).getFullYear();
            let yearMatch = (activeYearFilter === 'all' || (selectedYear >= stYr && selectedYear <= etYr));
            let overlap = (st <= filterEndT && et >= filterStartT);

            if (yearMatch && overlap) {
                keepGroup = true;
            }
        });

        if (keepGroup) {
            let validSegments = [];
            workingData[g].forEach(s => {
                if (s.type === 'target' && !showTargetDates) return;

                let stDate = s.start.substring(0, 10).replace(/-/g,'/');
                let etDate = s.end.substring(0, 10).replace(/-/g,'/');
                let st = new Date(stDate + ' 00:00:00').getTime();
                let et = new Date(etDate + ' 00:00:00').getTime();
                if (st > et) { let tmp = st; st = et; et = tmp; }
                
                let stYr = new Date(st).getFullYear();
                let etYr = new Date(et).getFullYear();
                let yearMatch = (activeYearFilter === 'all' || (selectedYear >= stYr && selectedYear <= etYr));

                if (yearMatch) {
                    validSegments.push(s);
                    if (st < activeMinT) activeMinT = st;
                    if (et > activeMaxT) activeMaxT = et;
                }
            });
            if(validSegments.length > 0) {
                filteredData[g] = validSegments;
            }
        }
    });

    const groups = Object.keys(filteredData).sort((a, b) => {
        if (a.startsWith('TARGET_ROW|')) return -1;
        if (b.startsWith('TARGET_ROW|')) return 1;

        const getActualMin = (groupKey) => {
            const actuals = filteredData[groupKey].filter(s => s.type === 'actual');
            if (actuals.length === 0) return Infinity;
            return Math.min(...actuals.map(s => new Date(s.start.replace(/-/g, '/')).getTime()));
        };

        let aMin = getActualMin(a);
        let bMin = getActualMin(b);
        
        return aMin - bMin;
    });
    
    if (groups.length === 0 || activeMinT === Infinity) {
        container.innerHTML = '<div style="padding:40px;text-align:center;color:#64748b;font-weight:bold;">No matching logs found for the selected date range / year.</div>';
        return;
    }

    let minD = new Date(activeMinT);
    minD.setHours(0, 0, 0, 0);
    let viewMinT = minD.getTime();

    let maxD = new Date(activeMaxT);
    let viewMaxD = new Date(maxD.getFullYear(), maxD.getMonth() + 1, 1, 0, 0, 0);
    let viewMaxT = viewMaxD.getTime();

    let totalRange = viewMaxT - viewMinT;
    if (totalRange <= 0) totalRange = 86400000;

    let ticks = [];
    let curr = new Date(viewMinT);
    while (curr.getTime() < viewMaxT) {
        let yr = curr.getFullYear();
        let dateStr = String(curr.getDate()).padStart(2, '0') + '/' + String(curr.getMonth() + 1).padStart(2, '0');
        let isFirstDayOfYear = (curr.getDate() === 1 && curr.getMonth() === 0);
        
        let topYear = '&nbsp;';
        if (curr.getTime() === viewMinT || isFirstDayOfYear) {
            topYear = yr;
        }

        let l = `<div style="font-size:9px; color:#1d4ed8; font-weight:bold; line-height:1;">${topYear}</div><div style="font-size:10px; line-height:1.2; margin-top:2px;">${dateStr}</div>`;
        
        ticks.push({ label: l, isPrevEnd: isFirstDayOfYear, time: curr.getTime() });
        curr.setDate(curr.getDate() + 1);
    }

    let yAxisTitle = 'Item';
    if (activeEngFilter !== '' && activeProjFilter !== '') {
        yAxisTitle = 'Activity';
    } else if (activeEngFilter !== '') {
        yAxisTitle = 'IIPS';
    } else if (activeProjFilter !== '') {
        yAxisTitle = 'Engineer';
    }

    let headerHtml = `<div class="gantt-row gantt-header-row"><div class="gantt-y-axis">${yAxisTitle}</div><div class="gantt-x-axis">`;
    ticks.forEach(tick => { 
        let extraClass = tick.isPrevEnd ? ' prev-year-end' : '';
        headerHtml += `<div class="gantt-tick${extraClass}">${tick.label}</div>`; 
    });
    headerHtml += `</div></div>`;
    let htmlContent = headerHtml;

    window.ganttScrollMap = {};
    window.ganttScrollIdx = {};
    window.ganttScrollDir = {};
    
    let barIdCounter = 0;
    let groupCounter = 0;

    groups.forEach(g => {
        if (g.startsWith('TARGET_ROW|') && !showTargetDates) return;

        let groupId = 'gantt-grp-' + groupCounter++;
        let yLabel = g;
        let yAxisStyle = '';
        
        if (g.startsWith('TARGET_ROW|')) {
            yLabel = `<span style="font-weight:bold; font-size:12px; color:#1e293b;">${g.split('|')[1]}</span>`;
            yAxisStyle = 'background-color: #ffffff;';
        } else if (g.includes('|')) {
            yLabel = g.split('|')[0];
        }
        
        let actualTimes = filteredData[g]
            .filter(s => s.type === 'actual')
            .map(s => new Date(s.start.substring(0, 10).replace(/-/g,'/') + ' 00:00:00').getTime());
            
        if (actualTimes.length === 0) {
            actualTimes = filteredData[g].map(s => new Date(s.start.substring(0, 10).replace(/-/g,'/') + ' 00:00:00').getTime());
        }
        
        let barTimes = [...new Set(actualTimes)].sort((a,b) => b - a);
        window.ganttScrollMap[groupId] = barTimes;
        window.ganttScrollIdx[groupId] = 0;
        window.ganttScrollDir[groupId] = 1;

        let rowHtml = `<div class="gantt-row"><div class="gantt-y-axis" style="${yAxisStyle}" title="Click to track logs" onclick="cycleGroupScroll('${groupId}', ${viewMinT}, ${totalRange})">${yLabel}</div><div class="gantt-x-axis"><div class="gantt-grid">`;
            
        ticks.forEach(() => { rowHtml += `<div class="gantt-grid-line"></div>`; });
        rowHtml += `</div><div class="gantt-bars">`;
        
        filteredData[g].forEach(s => {
            if (s.type === 'target' && !showTargetDates) return;

            const hasTarget = filteredData[g].some(item => item.type === 'target');
            const hasActual = filteredData[g].some(item => item.type === 'actual');
    
            let topPos = '50%';
            if (showTargetDates && hasTarget && hasActual) {
                topPos = (s.type === 'target') ? '30%' : '70%';
            }

            let stDate = s.start.substring(0, 10).replace(/-/g,'/');
            let etDate = s.end.substring(0, 10).replace(/-/g,'/');
            
            let st = new Date(stDate + ' 00:00:00').getTime();
            let et = new Date(etDate + ' 00:00:00').getTime() + 86400000;
            
            if (st > et) { let tmp = st; st = et; et = tmp; }
            
            let origSt = new Date(s.start.replace(/-/g,'/')).getTime();
            let origEt = new Date(s.end.replace(/-/g,'/')).getTime();
            if (origSt > origEt) { let tmp = origSt; origSt = origEt; origEt = tmp; }
            const mins = (origEt - origSt) / 60000;
            
            let leftPercent = ((st - viewMinT) / totalRange) * 100;
            let widthPercent = ((et - st) / totalRange) * 100;
            
            if (leftPercent > 100 || leftPercent + widthPercent < 0) return; 
            if (leftPercent < 0) { widthPercent += leftPercent; leftPercent = 0; }
            if (leftPercent + widthPercent > 100) widthPercent = 100 - leftPercent;
            
            if (widthPercent < 0.2) widthPercent = 0.2;
            
            rowHtml += `<div class="gantt-bar ${s.type}" id="bar-${barIdCounter}" style="left:${leftPercent}%; width:${widthPercent}%; top:${topPos}; transform:translateY(-50%);" onclick="toggleGanttTooltip(event, this, '${s.start}', '${s.end}', ${mins}, ${barIdCounter}, '${s.type}')"></div>`;  
            barIdCounter++;
        });
        
        rowHtml += `</div></div></div>`;
        htmlContent += rowHtml;
    });

    htmlContent += `<div id="gantt-tooltip-layer" style="position:absolute; top:0; left:0; width:100%; height:100%; pointer-events:none; z-index:10;"></div>`;
    container.innerHTML = htmlContent;

    setTimeout(() => {
        let scrollToTime = new Date().getTime(); 
        
        if (scrollToTime < viewMinT) scrollToTime = viewMinT;
        if (scrollToTime > viewMaxT) scrollToTime = viewMaxT;

        let leftPerc = (scrollToTime - viewMinT) / totalRange;
        const xAxis = document.querySelector('.gantt-row .gantt-x-axis');
        const scrollContainer = document.querySelector('.gantt-container');
        if (xAxis && scrollContainer) {
             const offset = scrollContainer.clientWidth / 2; 
             const targetX = (leftPerc * xAxis.offsetWidth) - offset; 
             scrollContainer.scrollLeft = Math.max(0, targetX);
        }
    }, 100);
}
</script>
</body>
</html>