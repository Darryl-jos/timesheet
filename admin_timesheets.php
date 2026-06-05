<?php
require_once 'config.php';

if (!isset($_SESSION['engineer_id']) || !isset($_SESSION['is_admin']) || ($_SESSION['is_admin'] != 1 && $_SESSION['is_admin'] != 2)) {
    header("Location: login.php");
    exit;
}

// ── Bulk delete ───────────────────────────────────────────────────────────────
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

// ── Single delete ─────────────────────────────────────────────────────────────
if (isset($_GET['delete_ts'])) {
    $del_id = intval($_GET['delete_ts']);
    $stmt = $conn->prepare("DELETE FROM timesheets WHERE id = ?");
    $stmt->bind_param("i", $del_id);
    $stmt->execute();
    $stmt->close();
    header("Location: admin_timesheets.php");
    exit;
}

// ── Fetch all timesheets ──────────────────────────────────────────────────────
$ts_result = $conn->query("
    SELECT t.*, p.project_name, p.customer_name, p.estimate_time
    FROM timesheets t
    JOIN projects p ON t.project_id = p.project_id
    ORDER BY t.start_date DESC, t.start_time DESC
");
if (!$ts_result) die("DB Error: " . $conn->error);

$proj_list_result = $conn->query("SELECT project_id, project_name, customer_name FROM projects ORDER BY project_name ASC");

// Cache rows + compute minutes
$rows_cache = [];
while ($row = $ts_result->fetch_assoc()) {
    $start = new DateTime($row['start_date'] . ' ' . $row['start_time']);
    $end   = new DateTime($row['end_date']   . ' ' . $row['end_time']);
    if ($end <= $start) $end->modify('+1 day');
    $diff  = $start->diff($end);
    $mins  = ($diff->days * 24 * 60) + ($diff->h * 60) + $diff->i;
    $row['_minutes'] = $mins;
    $rows_cache[] = $row;
}

// Per-project aggregates (for summary card)
$proj_agg = [];
foreach ($rows_cache as $r) {
    $pid = $r['project_id'];
    if (!isset($proj_agg[$pid])) {
        $proj_agg[$pid] = [
            'project_name'  => $r['project_name'],
            'customer_name' => $r['customer_name'],
            'estimate_time' => $r['estimate_time'],
            'total_minutes' => 0,
            'engineers'     => [],
            'min_start'     => $r['start_date'],
            'max_end'       => $r['end_date'],
        ];
    }
    $proj_agg[$pid]['total_minutes'] += $r['_minutes'];
    $proj_agg[$pid]['engineers'][$r['engineer_name']] = true;
    if ($r['start_date'] < $proj_agg[$pid]['min_start']) $proj_agg[$pid]['min_start'] = $r['start_date'];
    if ($r['end_date']   > $proj_agg[$pid]['max_end'])   $proj_agg[$pid]['max_end']   = $r['end_date'];
}
$proj_agg_json = json_encode($proj_agg);

function fmtDate($d) {
    if (!$d) return '-';
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt ? $dt->format('d-M-Y') : $d;
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
body { font-family: Arial, sans-serif; margin: 0; background: #f4f7f6; color: #333; font-size: 13px; }

/* ── Top bar ── */
.topbar { background: #343a40; padding: 14px 20px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; }
.topbar h2 { color: white; margin: 0; font-size: 16px; }
.topbar a { color: #ffc107; font-weight: bold; text-decoration: none; font-size: 13px; }
.topbar a:hover { color: #ffda6a; }

/* ── Page ── */
.page { padding: 20px; }

/* ── Stats ── */
.stats-bar { display: flex; gap: 12px; margin-bottom: 16px; flex-wrap: wrap; }
.stat { background: white; border-radius: 8px; padding: 12px 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.07); flex: 1; min-width: 120px; border-top: 3px solid #007bff; }
.stat.green { border-top-color: #28a745; }
.stat.orange{ border-top-color: #fd7e14; }
.stat-label { font-size: 11px; color: #64748b; text-transform: uppercase; font-weight: 600; }
.stat-value { font-size: 20px; font-weight: 700; margin-top: 2px; }

/* ── Search bar ── */
.search-wrap { background: white; padding: 12px; border-radius: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 12px; align-items: center; }
.search-wrap input[type="text"] { flex: 2; min-width: 160px; height: 38px; padding: 0 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 13px; }
.search-wrap input[type="date"] { flex: 1; min-width: 130px; height: 38px; padding: 0 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 13px; }
/* Searchable select */
.sel-wrap { flex: 2; min-width: 180px; position: relative; }
.sel-box { height: 38px; padding: 0 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 13px; background: white; cursor: pointer; display: flex; align-items: center; justify-content: space-between; gap: 6px; user-select: none; }
.sel-box:hover { border-color: #007bff; }
.sel-box span:first-child { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; flex: 1; color: #333; }
.sel-arrow { color: #6c757d; font-size: 11px; flex-shrink: 0; transition: transform .2s; }
.sel-wrap.open .sel-arrow { transform: rotate(180deg); }
.sel-wrap.open .sel-box { border-color: #007bff; box-shadow: 0 0 0 2px rgba(0,123,255,.12); }
.sel-panel { display: none; position: absolute; top: calc(100% + 3px); left: 0; width: 100%; min-width: 220px; background: white; border: 1px solid #007bff; border-radius: 4px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); z-index: 200; padding: 6px; }
.sel-wrap.open .sel-panel { display: block; }
.sel-panel input { width: 100%; height: 32px; padding: 0 8px; border: 1px solid #ddd; border-radius: 3px; font-size: 12px; margin-bottom: 5px; box-sizing: border-box; }
.sel-panel input:focus { border-color: #007bff; outline: none; }
.sel-list { max-height: 200px; overflow-y: auto; }
.sel-item { padding: 7px 10px; cursor: pointer; font-size: 13px; border-radius: 3px; line-height: 1.3; }
.sel-item:hover { background: #f0f7ff; }
.sel-item.active { background: #e6f0ff; color: #1d4ed8; font-weight: 600; }
.sel-item.hidden { display: none; }
.btn-clear { background: #6c757d; color: white; border: none; padding: 0 14px; height: 38px; border-radius: 4px; font-size: 13px; cursor: pointer; font-weight: bold; }
.proj-wrap { flex: 3; min-width: 140px; position: relative; }
.sel-trigger { height: 38px; padding: 0 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 13px; background: #fff; cursor: pointer; display: flex; align-items: center; justify-content: space-between; }
.sel-trigger::after { content:""; border-left:4px solid transparent; border-right:4px solid transparent; border-top:4px solid #666; }
.sel-drop { display: none; position: absolute; top: 100%; left: 0; width: 100%; background: #fff; border: 1px solid #007bff; border-radius: 4px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); z-index: 999; margin-top: 2px; padding: 6px; }
.sel-drop input { height: 30px; margin-bottom: 5px; font-size: 12px; width: 100%; padding: 0 8px; border: 1px solid #ddd; border-radius: 3px; }
.sel-opts { max-height: 160px; overflow-y: auto; }
.sel-opt { padding: 6px 8px; cursor: pointer; font-size: 12px; border-radius: 3px; }
.sel-opt:hover { background: #f0f7ff; color: #007bff; }
.sel-opt.active { background: #e6f0ff; color: #007bff; font-weight: bold; }
.show-drop { display: block !important; }

/* Engineer filter */
.btn-clear { background: #6c757d; color: white; border: none; padding: 0 14px; height: 38px; border-radius: 4px; font-size: 13px; font-weight: bold; cursor: pointer; white-space: nowrap; }

/* ── Project Summary Card ── */
.summary-card { display: none; background: white; border-radius: 8px; padding: 16px 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.07); border-left: 5px solid #007bff; margin-bottom: 14px; }
.summary-card h4 { margin: 0 0 12px 0; color: #007bff; font-size: 15px; }
.sum-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; }
.sum-item { background: #f8fafc; padding: 10px 12px; border-radius: 5px; border: 1px solid #e2e8f0; }
.sum-label { font-size: 11px; color: #64748b; text-transform: uppercase; font-weight: 600; display: block; margin-bottom: 3px; }
.sum-value { font-size: 14px; font-weight: 700; color: #1e293b; }

/* ── Bulk Toolbar ── */
#bulk-toolbar { display: none; background: #e6f0ff; border: 1px solid #b8daff; border-radius: 6px; padding: 10px 15px; margin-bottom: 12px; align-items: center; gap: 10px; flex-wrap: wrap; position: sticky; top: 8px; z-index: 100; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
#bulk-toolbar span { font-size: 13px; font-weight: 600; color: #1e40af; flex: 1; }
.btn-bulk { border: none; padding: 7px 14px; border-radius: 4px; font-size: 12px; cursor: pointer; font-weight: bold; }
.btn-export { background: #28a745; color: white; }
.btn-bulk-del { background: #dc3545; color: white; }
.btn-desel { background: #e2e8f0; color: #374151; }

/* ── Card ── */
.card { background: white; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.07); overflow: hidden; }
.card-hdr { padding: 12px 20px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; }
.card-hdr h3 { margin: 0; font-size: 15px; }
.btn-export-all { background: #6c757d; color: white; border: none; padding: 7px 14px; border-radius: 4px; font-size: 13px; font-weight: bold; cursor: pointer; }

/* ── Table ── */
.tbl-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
table { width: 100%; border-collapse: collapse; min-width: 1000px; }
th, td { padding: 10px 12px; text-align: left; font-size: 12px; border-bottom: 1px solid #f1f5f9; }
th { background: #f8fafc; font-weight: 600; color: #475569; white-space: nowrap; }
tbody tr:hover { background: #f8faff; }
.is-hidden { display: none !important; }

/* Date-range cell */
.dr { line-height: 1.6; }
.dr .d-start { color: #1d4ed8; font-weight: 600; font-size: 12px; }
.dr .d-end   { color: #7c3aed; font-weight: 600; font-size: 12px; }
.dr .d-time  { color: #94a3b8; font-size: 11px; }

/* Duration badge */
.dur { background: #d1fae5; color: #065f46; font-weight: bold; padding: 2px 7px; border-radius: 10px; font-size: 11px; white-space: nowrap; }
.dur.multi { background: #dbeafe; color: #1e40af; }

/* Mandays badge */
.md-badge { font-size: 11px; font-weight: bold; }
.md-target { color: #1e40af; }
.md-actual { color: #065f46; }

/* Gap badge */
.gap-over { color: #dc3545; font-weight: bold; font-size: 11px; }
.gap-under { color: #28a745; font-weight: bold; font-size: 11px; }
.gap-ok    { color: #6c757d; font-weight: bold; font-size: 11px; }

/* Activity */
.act { max-width: 200px; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; white-space: pre-line; cursor: pointer; color: #555; font-size: 11px; }
.act.exp { display: block; max-height: none; }

/* Actions */
.btn-edit { background: #ffc107; color: #333; padding: 3px 8px; text-decoration: none; border-radius: 3px; font-size: 11px; font-weight: bold; margin-right: 3px; }
.btn-del  { background: #dc3545; color: white; padding: 3px 8px; text-decoration: none; border-radius: 3px; font-size: 11px; font-weight: bold; }

/* Sort */
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

@media (max-width: 600px) {
    .page { padding: 10px; }
    .stats-bar { gap: 8px; }
    .stat { min-width: 100px; }
}
</style>
</head>
<body>

<div class="topbar">
    <h2>📊 Timesheet Audit — All Engineers</h2>
    <a href="admin.php">← Back to Admin</a>
</div>

<div class="page">

    <!-- Stats -->
    <?php
    $total_recs = count($rows_cache);
    $total_mins_all = array_sum(array_column($rows_cache, '_minutes'));
    ?>

    <!-- Bulk Toolbar -->
    <div id="bulk-toolbar">
        <span id="bulk-count">0 selected</span>
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <button class="btn-bulk btn-export" onclick="submitBulkExport()">📥 Export Selected</button>
            <button class="btn-bulk btn-bulk-del" onclick="submitBulkDelete()">🗑 Delete Selected</button>
            <button class="btn-bulk btn-desel" onclick="deselectAll()">✕ Deselect All</button>
        </div>
    </div>

    <!-- Search -->
    <div class="search-wrap">
        <input type="text" id="txt-search" placeholder="🔍 Search activity / keyword..." oninput="doFilter()">

        <!-- Project searchable select -->
        <div class="sel-wrap" id="proj-wrap">
            <div class="sel-box" id="proj-box" onclick="toggleSel('proj')">
                <span id="proj-label">All Projects</span>
                <span class="sel-arrow">▾</span>
            </div>
            <div class="sel-panel" id="proj-panel">
                <input type="text" id="proj-inner" placeholder="🔍 Type to filter..." oninput="filterSel('proj')" onclick="event.stopPropagation()">
                <div class="sel-list" id="proj-list">
                    <div class="sel-item active" data-value="" onclick="pickSel('proj','','All Projects',this)">All Projects</div>
                    <?php if ($proj_list_result): while($p = $proj_list_result->fetch_assoc()):
                        $label = ($p['project_id'] && !preg_match('/^N\/A/i',$p['project_id']) ? '['.$p['project_id'].'] ' : '').$p['project_name'];
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

        <!-- Engineer searchable select -->
        <div class="sel-wrap" id="eng-wrap">
            <div class="sel-box" id="eng-box" onclick="toggleSel('eng')">
                <span id="eng-label">All Engineers</span>
                <span class="sel-arrow">▾</span>
            </div>
            <div class="sel-panel" id="eng-panel">
                <input type="text" id="eng-inner" placeholder="🔍 Type to filter..." oninput="filterSel('eng')" onclick="event.stopPropagation()">
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

        <div style="position:relative; flex:1; min-width:130px; height:38px; display:flex;">
            <input type="text" id="date-display" placeholder="DD-MM-YYYY"
                style="flex:1;height:100%;padding:0 36px 0 10px;border:1px solid #ccc;border-radius:4px;font-size:13px;"
                autocomplete="off"
                oninput="liveDateSearch(this)"
                onblur="parseDateSearch()"
                onkeydown="if(event.key==='Enter'){event.preventDefault();this.blur();}">
            <div style="position:absolute;right:0;top:0;width:36px;height:100%;display:flex;align-items:center;justify-content:center;cursor:pointer;z-index:5;"
                onclick="document.getElementById('date-search').showPicker()">📅</div>
            <input type="date" id="date-search"
                style="position:absolute;top:0;right:0;width:36px;height:100%;opacity:0;cursor:pointer;z-index:10;"
                onchange="syncDateSearch()">
        </div>
        <button class="btn-clear" onclick="clearAllFilters()">Clear</button>
    </div>

    <!-- Live Filter Dashboard — only shows when filtering -->
    <div class="summary-card" id="sum-card">
        <h4>📊 Filter Results Dashboard</h4>
        <div class="sum-grid">
            <div class="sum-item"><span class="sum-label">Total Logs</span><span class="sum-value" id="sum-logs">-</span></div>
            <div class="sum-item"><span class="sum-label">Total Hours</span><span class="sum-value" id="sum-hours">-</span></div>
            <div class="sum-item"><span class="sum-label">Engineers</span><span class="sum-value" id="sum-engs">-</span></div>
            <div class="sum-item"><span class="sum-label">Date Range</span><span class="sum-value" id="sum-dates">-</span></div>
        </div>
    </div>

    <!-- Table -->
    <form id="bulk-form" method="POST" action="admin_timesheets.php">
        <input type="hidden" name="bulk_action" id="bulk-action-field" value="">

        <div class="card">
            <div class="card-hdr">
                <h3>Employee Work Hour Compliance Logs</h3>
                <button type="button" class="btn-export-all" onclick="exportAll()">📥 Export All</button>
            </div>
            <div class="tbl-wrap">
            <table id="main-table">
                <thead>
                    <tr>
                        <th style="width:36px;"><input type="checkbox" id="chk-all" onchange="toggleAll(this)"></th>
                        <th>
                            <div class="sort-wrap">Engineer
                                <button class="sort-btn" onclick="toggleSort(event,'s-eng')"></button>
                                <div id="s-eng" class="sort-menu">
                                    <a href="#" onclick="sortT(1,'alpha',0);return false;">Default</a>
                                    <a href="#" onclick="sortT(1,'alpha',1);return false;">A → Z</a>
                                    <a href="#" onclick="sortT(1,'alpha',2);return false;">Z → A</a>
                                </div>
                            </div>
                        </th>
                        <th>
                            <div class="sort-wrap">Project ID
                                <button class="sort-btn" onclick="toggleSort(event,'s-pid')"></button>
                                <div id="s-pid" class="sort-menu">
                                    <a href="#" onclick="sortT(2,'alpha',0);return false;">Default</a>
                                    <a href="#" onclick="sortT(2,'alpha',1);return false;">A → Z</a>
                                    <a href="#" onclick="sortT(2,'alpha',2);return false;">Z → A</a>
                                </div>
                            </div>
                        </th>
                        <th>Customer</th>
                        <th>Project Name</th>
                        <th>Activity</th>
                        <th>
                            <div class="sort-wrap">Start Date
                                <button class="sort-btn" onclick="toggleSort(event,'s-sd')"></button>
                                <div id="s-sd" class="sort-menu">
                                    <a href="#" onclick="sortT(6,'date',0);return false;">Default</a>
                                    <a href="#" onclick="sortT(6,'date',1);return false;">Oldest First</a>
                                    <a href="#" onclick="sortT(6,'date',2);return false;">Newest First</a>
                                </div>
                            </div>
                        </th>
                        <th>End Date</th>
                        <th>
                            <div class="sort-wrap">Duration
                                <button class="sort-btn" onclick="toggleSort(event,'s-dur')"></button>
                                <div id="s-dur" class="sort-menu">
                                    <a href="#" onclick="sortT(8,'num',0);return false;">Default</a>
                                    <a href="#" onclick="sortT(8,'num',1);return false;">Shortest First</a>
                                    <a href="#" onclick="sortT(8,'num',2);return false;">Longest First</a>
                                </div>
                            </div>
                        </th>
                        <th>Target Mandays</th>
                        <th>Gap</th>
                        <th>Actions</th>
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
                    data-mins="<?= $mins ?>">
                    <td><input type="checkbox" class="ts-chk" name="selected_ts[]" value="<?= $row['id'] ?>" onchange="onChkChange()"></td>
                    <td><strong><?= htmlspecialchars($row['engineer_name']) ?></strong></td>
                    <td><code style="font-size:11px;"><?= preg_match('/^N\/A/i', $row['project_id']) ? '-' : htmlspecialchars($row['project_id']) ?></code></td>
                    <td style="font-size:11px;"><?= htmlspecialchars($row['customer_name']) ?></td>
                    <td style="font-size:11px;"><?= htmlspecialchars($row['project_name']) ?></td>
                    <td>
                        <div class="act"><?= htmlspecialchars($row['work_description'] ?: 'No description') ?></div>
                    </td>
                    <td>
                        <div class="dr">
                            <div class="d-start"><?= fmtDate($row['start_date']) ?></div>
                            <div class="d-time"><?= htmlspecialchars(substr($row['start_time'],0,5)) ?></div>
                        </div>
                    </td>
                    <td>
                        <div class="dr">
                            <div class="d-end"><?= fmtDate($row['end_date']) ?></div>
                            <div class="d-time"><?= htmlspecialchars(substr($row['end_time'],0,5)) ?></div>
                        </div>
                    </td>
                    <td data-raw="<?= $mins ?>">
                        <span class="dur <?= $is_multi ? 'multi' : '' ?>"><?= $dur_text ?></span>
                    </td>
                    <td>
                        <span class="md-target"><?= intval($row['estimate_time']) ?>h</span><br>
                        <span style="font-size:10px; color:#94a3b8;">(<?= round($row['estimate_time']/8, 1) ?> days)</span>
                    </td>
                    <td><?= $gap_html ?></td>
                    <td>
                        <a href="edit.php?edit=<?= $row['id'] ?>" class="btn-edit">Edit</a>
                        <a href="admin_timesheets.php?delete_ts=<?= $row['id'] ?>" class="btn-del" onclick="return confirm('Delete this log?')">Del</a>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
            </div>
        </div>
    </form>
</div>

<script>
const projAgg = <?= $proj_agg_json ?>;
let activeProjFilter = '';
let activeEngFilter  = '';
let origRows = null;

const MON = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

function liveDateSearch(inp) {
    let digits = inp.value.replace(/\D/g,'');
    let result = '';
    if (digits.length > 0) {
        let d = digits.substring(0,2);
        if (digits.length >= 2) { let n=parseInt(d); if(n<1)d='01'; if(n>31)d='31'; }
        result += d;
    }
    if (digits.length >= 3) {
        let m = digits.substring(2,4);
        if (digits.length >= 4) { let n=parseInt(m); if(n<1)m='01'; if(n>12)m='12'; }
        result += '-' + m;
    }
    if (digits.length >= 5) result += '-' + digits.substring(4,8);
    inp.value = result;
}

function parseDateSearch() {
    const disp = document.getElementById('date-display');
    const hidden = document.getElementById('date-search');
    const str = disp.value.trim();
    if (!str) { hidden.value = ''; doFilter(); return; }
    const parts = str.split('-');
    if (parts.length === 3 && parts[2].length === 4) {
        const d = parts[0].padStart(2,'0');
        const m = parts[1].padStart(2,'0');
        const y = parts[2];
        hidden.value = y+'-'+m+'-'+d;
        disp.value = parseInt(d)+'-'+MON[parseInt(m)-1]+'-'+y;
    }
    doFilter();
}

function syncDateSearch() {
    const val = document.getElementById('date-search').value;
    const disp = document.getElementById('date-display');
    if (val) {
        const p = val.split('-');
        disp.value = parseInt(p[2])+'-'+MON[parseInt(p[1])-1]+'-'+p[0];
    } else {
        disp.value = '';
    }
    doFilter();
}

// ── Searchable select ─────────────────────────────────────────────────────────
function toggleSel(type) {
    const wrap = document.getElementById(type+'-wrap');
    const isOpen = wrap.classList.contains('open');
    // close all
    document.querySelectorAll('.sel-wrap').forEach(w => w.classList.remove('open'));
    if (!isOpen) {
        wrap.classList.add('open');
        document.getElementById(type+'-inner').focus();
    }
}
function filterSel(type) {
    const val = document.getElementById(type+'-inner').value.toLowerCase();
    document.querySelectorAll('#'+type+'-list .sel-item').forEach(item => {
        if (!item.dataset.value) { item.classList.remove('hidden'); return; } // always show "All"
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
    filterSel(type); // reset filter display
    doFilter();
}
// Close on outside click
document.addEventListener('click', e => {
    if (!e.target.closest('.sel-wrap')) {
        document.querySelectorAll('.sel-wrap').forEach(w => w.classList.remove('open'));
    }
});

// ── Filter ────────────────────────────────────────────────────────────────────
function doFilter() {
    const txt  = document.getElementById('txt-search').value.toLowerCase();
    const date = document.getElementById('date-search').value;
    let visRows = [];
    document.querySelectorAll('#main-table tbody tr').forEach(tr => {
        const rPid = tr.dataset.pid || '';
        const rEng = tr.dataset.eng || '';
        const rSd  = tr.dataset.sd  || '';
        const rTxt = tr.textContent.toLowerCase();
        const ok = (!txt              || rTxt.includes(txt))
                && (!activeProjFilter || rPid === activeProjFilter)
                && (!activeEngFilter  || rEng === activeEngFilter)
                && (!date             || rSd  === date);
        tr.classList.toggle('is-hidden', !ok);
        if (ok) visRows.push(tr);
    });
    updateSummary(visRows, txt, date);
}

function updateSummary(visRows, txt, date) {
    const card = document.getElementById('sum-card');
    const hasFilter = txt || activeProjFilter || activeEngFilter || date;

    // Hide when no filter active
    if (!hasFilter || visRows.length === 0) { card.style.display = 'none'; return; }

    // Calculate stats from visible rows only
    let totalMins = 0;
    const engSet  = new Set();
    const projSet = new Set();
    let minDate = '', maxDate = '';

    visRows.forEach(tr => {
        totalMins += parseInt(tr.dataset.mins) || 0;
        if (tr.dataset.eng)  engSet.add(tr.dataset.eng);
        if (tr.dataset.pid)  projSet.add(tr.dataset.pid);
        const sd = tr.dataset.sd || '';
        if (sd) {
            if (!minDate || sd < minDate) minDate = sd;
            if (!maxDate || sd > maxDate) maxDate = sd;
        }
    });

    const h   = Math.floor(totalMins / 60);
    const m   = totalMins % 60;
    const avg = visRows.length > 0 ? Math.round(totalMins / visRows.length) : 0;
    const avgH = Math.floor(avg/60), avgM = avg%60;

    const MONTHS_JS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    function fmtDateJS(ymd) {
        if (!ymd) return '-';
        const p = ymd.split('-');
        if (p.length !== 3) return ymd;
        return parseInt(p[2]) + '-' + MONTHS_JS[parseInt(p[1])-1] + '-' + p[0];
    }

    document.getElementById('sum-logs').textContent  = visRows.length;
    document.getElementById('sum-hours').textContent = h + 'h ' + m + 'm';
    document.getElementById('sum-engs').textContent  = engSet.size;
    document.getElementById('sum-dates').textContent = minDate && maxDate
        ? (minDate === maxDate ? fmtDateJS(minDate) : fmtDateJS(minDate) + ' → ' + fmtDateJS(maxDate)) : '-';

    card.style.display = 'block';
}

function clearAllFilters() {
    document.getElementById('txt-search').value   = '';
    document.getElementById('date-search').value  = '';
    document.getElementById('date-display').value = '';
    activeProjFilter = '';
    activeEngFilter  = '';
    document.getElementById('proj-label').textContent = 'All Projects';
    document.getElementById('eng-label').textContent  = 'All Engineers';
    document.querySelectorAll('.sel-item').forEach(i => i.classList.remove('active'));
    document.querySelectorAll('.sel-item[data-value=""]').forEach(i => i.classList.add('active'));
    doFilter();
}

// ── Checkbox / bulk ───────────────────────────────────────────────────────────
function onChkChange() {
    const checked = document.querySelectorAll('.ts-chk:checked').length;
    const toolbar = document.getElementById('bulk-toolbar');
    toolbar.style.display = checked > 0 ? 'flex' : 'none';
    document.getElementById('bulk-count').textContent = checked + ' selected';
    document.getElementById('chk-all').indeterminate = checked > 0 && checked < document.querySelectorAll('.ts-chk').length;
    document.getElementById('chk-all').checked = checked === document.querySelectorAll('.ts-chk').length;
}
function toggleAll(cb) {
    document.querySelectorAll('.ts-chk').forEach(c => c.checked = cb.checked);
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
function exportAll() { window.location.href = 'export.php'; }

// ── Activity expand ───────────────────────────────────────────────────────────
document.querySelectorAll('.act').forEach(c => {
    let t;
    c.addEventListener('mouseenter', () => { t = setTimeout(() => c.classList.add('exp'), 500); });
    c.addEventListener('mouseleave', () => { clearTimeout(t); c.classList.remove('exp'); });
});

// ── Sort ──────────────────────────────────────────────────────────────────────
function toggleSort(e, id) {
    e.stopPropagation();
    document.querySelectorAll('.sort-menu').forEach(m => { if (m.id!==id) m.classList.remove('show-sort'); });
    document.getElementById(id).classList.toggle('show-sort');
}
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