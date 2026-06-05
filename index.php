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

$filter_date = isset($_GET['date']) ? $_GET['date'] : '';

if (!empty($filter_date)) {
    $sql = "SELECT t.*, p.project_name, p.customer_name, p.estimate_time
            FROM timesheets t
            JOIN projects p ON t.project_id = p.project_id
            WHERE t.engineer_id = ? AND t.start_date = ?
            ORDER BY t.id DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("is", $current_user_id, $filter_date);
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
    $row['_minutes'] = $mins;
    $total_minutes += $mins;
    $total_records++;
    $rows_cache[] = $row;
}
$total_h = floor($total_minutes / 60);
$total_m = $total_minutes % 60;

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
    <title>My Timesheet Dashboard</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, sans-serif; margin: 0; background: #f4f7f6; color: #333; }

        .topbar { background: #1e2330; padding: 12px 20px; display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
        .topbar h2 { color: white; margin: 0; font-size: 16px; }
        .topbar .nav { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .topbar a { color: #94a3b8; text-decoration: none; font-size: 13px; padding: 5px 10px; border-radius: 4px; }
        .topbar a:hover { background: rgba(255,255,255,0.1); color: white; }
        .topbar a.admin-btn { background: #166534; color: #d1fae5; }
        .topbar a.logout-btn { color: #f87171; }

        .page { padding: 20px; }

        .stats-bar { display: flex; gap: 12px; margin-bottom: 20px; flex-wrap: wrap; }
        .stat { background: white; border-radius: 8px; padding: 14px 18px; box-shadow: 0 1px 3px rgba(0,0,0,0.07); flex: 1; min-width: 130px; border-top: 3px solid #007bff; }
        .stat.green { border-top-color: #28a745; }
        .stat-label { font-size: 11px; color: #64748b; text-transform: uppercase; font-weight: 600; }
        .stat-value { font-size: 22px; font-weight: 700; margin-top: 2px; }

        .btn-create { display: inline-block; background: #007bff; color: white; text-decoration: none; padding: 11px 22px; border-radius: 5px; font-weight: bold; font-size: 15px; margin-bottom: 16px; width: 100%; text-align: center; }
        .btn-create:hover { background: #0056b3; }

        .search-bar-wrap { background: white; padding: 12px; border-radius: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); display: flex; gap: 8px; margin-bottom: 14px; flex-wrap: wrap; align-items: center; }
        .search-bar-wrap input[type="text"] { flex: 2; min-width: 160px; height: 38px; padding: 0 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 13px; }
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
        .card-hdr { padding: 14px 20px; border-bottom: 1px solid #e5e7eb; font-weight: bold; font-size: 15px; }
        .tbl-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
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

        .dur-badge { background: #d1fae5; color: #065f46; font-weight: bold; padding: 2px 8px; border-radius: 12px; font-size: 12px; white-space: nowrap; }
        .dur-badge.multiday { background: #dbeafe; color: #1e40af; }

        .btn-edit { background: #ffc107; color: #333; padding: 4px 10px; text-decoration: none; border-radius: 3px; font-size: 12px; font-weight: bold; margin-right: 4px; }
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

        @media (max-width: 600px) {
            .page { padding: 12px; }
            .stats-bar { gap: 8px; }
            .stat { min-width: 100px; padding: 10px 12px; }
        }
    </style>
</head>
<body>

<div class="topbar">
    <h2>👷 <?= htmlspecialchars($current_user_name) ?></h2>
    <div class="nav">
        <?php if (isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 2): ?>
            <a href="admin.php" class="admin-btn">⚙️ Admin</a>
        <?php endif; ?>
        <a href="profile.php">👤 Profile</a>
        <a href="login.php?action=logout" class="logout-btn">Logout</a>
    </div>
</div>

<div class="page">
    <div class="stats-bar">
        <div class="stat">
            <div class="stat-label">Total Logs</div>
            <div class="stat-value"><?= $total_records ?></div>
        </div>
        <div class="stat green">
            <div class="stat-label">Total Hours</div>
            <div class="stat-value"><?= $total_h ?>h <?= $total_m ?>m</div>
        </div>
    </div>

    <a href="create.php" class="btn-create">+ Create New Record</a>

    <div class="search-bar-wrap">
        <input type="text" id="txt-search" placeholder="🔍 Search activity, project, customer..." oninput="doFilter()">
        <div class="sel-wrap" id="proj-wrap">
            <div class="sel-box" id="proj-box" onclick="toggleSel()">
                <span id="proj-label">All Projects</span>
                <span class="sel-arrow">▾</span>
            </div>
            <div class="sel-panel" id="proj-panel">
                <input type="text" id="proj-inner" placeholder="🔍 Type to filter..." oninput="filterSel()" onclick="event.stopPropagation()">
                <div class="sel-list" id="proj-list">
                    <div class="sel-item active" data-value="" onclick="pickProj(this, '', 'All Projects')">All Projects</div>
                    <?php if ($proj_list_res): while($p = $proj_list_res->fetch_assoc()):
                        $kw = strtolower("[".$p['project_id']."] ".$p['project_name']." ".$p['customer_name']);
                        $pid_show = preg_match('/^N\/A/i', $p['project_id']) ? '' : '['.$p['project_id'].'] ';
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

        <form id="date-form" method="GET" style="margin: 0; display: flex; flex: 1; min-width: 140px; height: 38px;">
            <div style="position: relative; width: 100%; height: 100%; display: flex;">
                <input type="text" id="date-display" placeholder="ALL TIME" readonly style="flex: 1; height: 100%; padding: 0 36px 0 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 13px; text-transform: uppercase; background-color: #fff; cursor: pointer; color: #333; box-sizing: border-box;">
                <div style="position: absolute; right: 0; top: 0; width: 36px; height: 100%; display: flex; align-items: center; justify-content: center; cursor: pointer; z-index: 5;" onclick="document.getElementById('date-filter').showPicker()">
                    📅
                </div>
                <input type="date" name="date" id="date-filter" value="<?= htmlspecialchars($filter_date) ?>" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; opacity: 0; cursor: pointer; z-index: 10;" onchange="document.getElementById('date-form').submit();">
            </div>
        </form>

        <button class="btn-clear" onclick="clearFilters()">Clear</button>
    </div>

    <div class="card">
        <div class="card-hdr">Timesheet Records</div>
        <?php if (empty($rows_cache)): ?>
            <div class="no-data">No records found. Click "Create New Record" to add an entry.</div>
        <?php else: ?>
        <div class="tbl-wrap">
        <table id="main-table">
            <thead>
                <tr>
                    <th style="width: 13%;">
                        <div class="sort-wrap" style="position:relative;">
                            <span>Project</span>
                            <button class="sort-btn" onclick="toggleSort(event,'drop-proj')"></button>
                            <div id="drop-proj" class="sort-menu">
                                <a href="#" onclick="sortTable(0,'alpha',0);return false;">Default</a>
                                <a href="#" onclick="sortTable(0,'alpha',1);return false;">A → Z</a>
                                <a href="#" onclick="sortTable(0,'alpha',2);return false;">Z → A</a>
                            </div>
                        </div>
                    </th>
                    <th style="width: 14%;">Customer</th>
                    <th style="width: 15%;">Project Name</th>
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
                    <th style="width: 110px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows_cache as $row):
                    $is_multiday = ($row['start_date'] !== $row['end_date']);
                    $h = floor($row['_minutes'] / 60);
                    $m = $row['_minutes'] % 60;
                    $days = floor($h / 24);
                    $dur_text = ($days > 0 ? $days.'d ' : '') . ($h%24) . 'h ' . $m . 'm';
                ?>
                <tr data-pid="<?= htmlspecialchars($row['project_id']) ?>">
                    <td><code style="font-size:11px;"><?= preg_match('/^N\/A/i', $row['project_id']) ? '-' : htmlspecialchars($row['project_id']) ?></code></td>
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
                        <span class="dur-badge <?= $is_multiday ? 'multiday' : '' ?>"><?= $dur_text ?></span>
                    </td>
                    <td>
                        <a href="edit.php?edit=<?= $row['id'] ?>" class="btn-edit">Edit</a>
                        <a href="index.php?delete=<?= $row['id'] ?>" class="btn-delete" onclick="return confirm('Delete this record?')">Delete</a>
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
document.addEventListener("DOMContentLoaded", function() {
    const val = document.getElementById('date-filter').value;
    const display = document.getElementById('date-display');
    if (val) {
        const parts = val.split('-');
        if (parts.length === 3) {
            const months = ["JAN", "FEB", "MAR", "APR", "MAY", "JUN", "JUL", "AUG", "SEP", "OCT", "NOV", "DEC"];
            display.value = parts[2] + '-' + months[parseInt(parts[1], 10) - 1] + '-' + parts[0];
        }
    } else {
        display.value = "";
        display.placeholder = "ALL TIME";
    }
});

let origRows = null;
let activeProjFilter = '';

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
    if (!e.target.closest('#proj-wrap')) document.getElementById('proj-wrap').classList.remove('open');
});

function doFilter() {
    const txt  = document.getElementById('txt-search').value.toLowerCase();
    document.querySelectorAll('#main-table tbody tr').forEach(tr => {
        const rowPid  = tr.dataset.pid  || '';
        const rowText = tr.textContent.toLowerCase();
        const ok = (!txt  || rowText.includes(txt))
                && (!activeProjFilter || rowPid === activeProjFilter);
        tr.classList.toggle('is-hidden', !ok);
    });
}

function clearFilters() {
    document.getElementById('txt-search').value  = '';
    activeProjFilter = '';
    document.getElementById('proj-label').textContent = 'All Projects';
    document.querySelectorAll('#proj-list .sel-item').forEach(i => i.classList.remove('active'));
    document.querySelector('#proj-list .sel-item[data-value=""]').classList.add('active');
    
    if (document.getElementById('date-filter').value !== '') {
        window.location.href = 'index.php';
    } else {
        doFilter();
    }
}

document.querySelectorAll('.act-cell').forEach(c => {
    let t;
    c.addEventListener('mouseenter', () => { t = setTimeout(() => c.classList.add('expanded'), 500); });
    c.addEventListener('mouseleave', () => { clearTimeout(t); c.classList.remove('expanded'); });
});

function toggleSort(e, id) {
    e.stopPropagation();
    document.querySelectorAll('.sort-menu').forEach(m => { if (m.id !== id) m.classList.remove('show-sort'); });
    document.getElementById(id).classList.toggle('show-sort');
}
window.addEventListener('click', () => document.querySelectorAll('.sort-menu').forEach(m => m.classList.remove('show-sort')));

function sortTable(col, type, dir) {
    const tbody = document.querySelector('#main-table tbody');
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