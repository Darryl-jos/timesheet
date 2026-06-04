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

$sql = "SELECT t.*, p.project_name, p.customer_name, p.estimate_time
        FROM timesheets t
        JOIN projects p ON t.project_id = p.project_id
        WHERE t.engineer_id = ?
        ORDER BY t.id DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $current_user_id);
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

        .page { padding: 20px; max-width: 1400px; margin: 0 auto; }

        .page-header { display: flex; justify-content: space-between; align-items: center; background: white; padding: 15px 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); margin-bottom: 20px; flex-wrap: wrap; gap: 10px; }
        .page-header h2 { margin: 0; color: #1f2937; font-size: 18px; }
        .header-actions { display: flex; gap: 15px; align-items: center; flex-wrap: wrap; }
        .header-actions a { font-weight: bold; text-decoration: none; font-size: 13px; color: #007bff; transition: 0.3s; }
        .header-actions a:hover { color: #0056b3; text-decoration: underline; }
        .header-actions a.btn-admin { color: #166534; }
        .header-actions a.btn-admin:hover { color: #0f4a25; }
        .header-actions a.btn-logout { color: #dc3545; }
        .header-actions a.btn-logout:hover { color: #a71d2a; }

        .stats-bar { display: flex; gap: 12px; margin-bottom: 20px; flex-wrap: wrap; }
        .stat { background: white; border-radius: 8px; padding: 14px 18px; box-shadow: 0 1px 3px rgba(0,0,0,0.07); flex: 1; min-width: 130px; border-top: 3px solid #007bff; }
        .stat.green { border-top-color: #28a745; }
        .stat-label { font-size: 11px; color: #64748b; text-transform: uppercase; font-weight: 600; }
        .stat-value { font-size: 22px; font-weight: 700; margin-top: 2px; }

        .btn-create { display: inline-block; background: #007bff; color: white; text-decoration: none; padding: 11px 22px; border-radius: 5px; font-weight: bold; font-size: 15px; margin-bottom: 16px; width: 100%; text-align: center; }
        .btn-create:hover { background: #0056b3; }

        .search-bar-wrap { background: white; padding: 12px; border-radius: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); display: flex; gap: 8px; margin-bottom: 14px; flex-wrap: wrap; }
        .search-bar-wrap input { flex: 2; min-width: 120px; height: 38px; padding: 0 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 13px; }
        .search-bar-wrap input[type="date"] { flex: 1.2; min-width: 110px; }
        .btn-clear { background: #6c757d; color: white; border: none; padding: 0 14px; height: 38px; border-radius: 4px; font-size: 13px; cursor: pointer; font-weight: bold; white-space: nowrap; }
        .proj-select-wrap { flex: 2; min-width: 140px; position: relative; }
        .sel-trigger { height: 38px; padding: 0 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 13px; background: #fff; cursor: pointer; display: flex; align-items: center; justify-content: space-between; }
        .sel-trigger::after { content: ""; border-left: 4px solid transparent; border-right: 4px solid transparent; border-top: 4px solid #666; }
        .sel-dropdown { display: none; position: absolute; top: 100%; left: 0; width: 100%; background: #fff; border: 1px solid #007bff; border-radius: 4px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); z-index: 999; margin-top: 2px; padding: 6px; }
        .sel-dropdown input { height: 30px; margin-bottom: 5px; font-size: 12px; }
        .sel-opts { max-height: 150px; overflow-y: auto; }
        .sel-opt { padding: 6px 8px; cursor: pointer; font-size: 12px; border-radius: 3px; }
        .sel-opt:hover { background: #f0f7ff; color: #007bff; }
        .sel-opt.active { background: #e6f0ff; color: #007bff; font-weight: bold; }
        .show { display: block !important; }

        .card { background: white; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.07); overflow: hidden; }
        .card-hdr { padding: 14px 20px; border-bottom: 1px solid #e5e7eb; font-weight: bold; font-size: 15px; }
        .tbl-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        table { width: 100%; border-collapse: collapse; min-width: 900px; }
        th, td { padding: 11px 12px; text-align: left; font-size: 13px; border-bottom: 1px solid #f1f5f9; }
        th { background: #f8fafc; font-weight: 600; color: #475569; white-space: nowrap; }
        tbody tr:hover { background: #f8faff; }

        .is-hidden { display: none !important; }

        .act-cell { max-width: 220px; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; white-space: pre-line; cursor: pointer; font-size: 12px; color: #555; }
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

        @media (min-width: 768px) {
            .page-header h2 { font-size: 20px; }
        }

        @media (max-width: 600px) {
            .page { padding: 12px; }
            .stats-bar { gap: 8px; }
            .stat { min-width: 100px; padding: 10px 12px; }
        }
    </style>
</head>
<body>

<div class="page">
    <div class="page-header">
        <h2>👷 <?php echo htmlspecialchars($current_user_name); ?>'s Dashboard</h2>
        <div class="header-actions">
            <?php if (isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 2): ?>
                <a href="admin.php" class="btn-admin">⚙️ Admin</a>
            <?php endif; ?>
            <a href="profile.php">👤 Profile</a>
            <a href="login.php?action=logout" class="btn-logout">Logout</a>
        </div>
    </div>

    <div class="stats-bar">
        <div class="stat">
            <div class="stat-label">Total Records</div>
            <div class="stat-value"><?= $total_records ?></div>
        </div>
        <div class="stat green">
            <div class="stat-label">Total Hours Logged</div>
            <div class="stat-value"><?= $total_h ?>h <?= $total_m ?>m</div>
        </div>
    </div>

    <a href="create.php" class="btn-create">+ Create New Record</a>

    <div class="search-bar-wrap">
        <input type="text" id="txt-search" placeholder="🔍 Search activity, project, customer..." oninput="doFilter()">
        <div class="proj-select-wrap">
            <div class="sel-trigger" id="proj-trigger" onclick="toggleProjDrop(event)">
                <span id="proj-label">All Projects</span>
            </div>
            <div class="sel-dropdown" id="proj-drop">
                <input type="text" id="proj-inner-search" placeholder="🔍 Search..." onkeyup="filterProjOpts()">
                <div class="sel-opts">
                    <div class="sel-opt active" data-value="" onclick="pickProj(this)">All Projects</div>
                    <?php if ($proj_list_res): while($p = $proj_list_res->fetch_assoc()):
                        $kw = strtolower("[".$p['project_id']."] ".$p['project_name']." ".$p['customer_name']); ?>
                        <div class="sel-opt" data-value="<?= htmlspecialchars($p['project_id']) ?>" data-kw="<?= htmlspecialchars($kw) ?>" onclick="pickProj(this)">
                            [<?= htmlspecialchars($p['project_id']) ?>] <?= htmlspecialchars($p['project_name']) ?>
                        </div>
                    <?php endwhile; endif; ?>
                </div>
            </div>
        </div>
        <input type="date" id="date-search" onchange="doFilter()">
        <button class="btn-clear" onclick="clearFilters()">Clear</button>
    </div>

    <div class="card">
        <div class="card-hdr">My Timesheet Records</div>
        <?php if (empty($rows_cache)): ?>
            <div class="no-data">No records yet. Click "Create New Record" to add your first entry.</div>
        <?php else: ?>
        <div class="tbl-wrap">
        <table id="main-table">
            <thead>
                <tr>
                    <th>
                        <div class="sort-wrap" style="position:relative;">
                            <span>Engineer</span>
                            <button class="sort-btn" onclick="toggleSort(event,'drop-eng')"></button>
                            <div id="drop-eng" class="sort-menu">
                                <a href="#" onclick="sortTable(0,'alpha',0);return false;">Default</a>
                                <a href="#" onclick="sortTable(0,'alpha',1);return false;">A → Z</a>
                                <a href="#" onclick="sortTable(0,'alpha',2);return false;">Z → A</a>
                            </div>
                        </div>
                    </th>
                    <th>
                        <div class="sort-wrap" style="position:relative;">
                            <span>Project</span>
                            <button class="sort-btn" onclick="toggleSort(event,'drop-proj')"></button>
                            <div id="drop-proj" class="sort-menu">
                                <a href="#" onclick="sortTable(1,'alpha',0);return false;">Default</a>
                                <a href="#" onclick="sortTable(1,'alpha',1);return false;">A → Z</a>
                                <a href="#" onclick="sortTable(1,'alpha',2);return false;">Z → A</a>
                            </div>
                        </div>
                    </th>
                    <th>Customer</th>
                    <th>Project Name</th>
                    <th>Activity</th>
                    <th>
                        <div class="sort-wrap" style="position:relative;">
                            <span>Start Date</span>
                            <button class="sort-btn" onclick="toggleSort(event,'drop-sd')"></button>
                            <div id="drop-sd" class="sort-menu">
                                <a href="#" onclick="sortTable(5,'date',0);return false;">Default</a>
                                <a href="#" onclick="sortTable(5,'date',1);return false;">Oldest First</a>
                                <a href="#" onclick="sortTable(5,'date',2);return false;">Newest First</a>
                            </div>
                        </div>
                    </th>
                    <th>End Date</th>
                    <th>Duration</th>
                    <th>Actions</th>
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
                <tr data-pid="<?= htmlspecialchars($row['project_id']) ?>"
                    data-sd="<?= htmlspecialchars($row['start_date']) ?>">
                    <td><strong><?= htmlspecialchars($row['engineer_name']) ?></strong></td>
                    <td><?= (strpos($row['project_id'], 'N/A') === 0) ? '' : '<code style="font-size:11px;">' . htmlspecialchars($row['project_id']) . '</code>' ?></td>
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
let origRows = null;
let activeProjFilter = '';

function doFilter() {
    const txt = document.getElementById('txt-search').value.toLowerCase();
    const pid = activeProjFilter;
    const date = document.getElementById('date-search').value;
    document.querySelectorAll('#main-table tbody tr').forEach(tr => {
        const rowPid  = tr.dataset.pid  || '';
        const rowDate = tr.dataset.sd   || '';
        const rowText = tr.textContent.toLowerCase();
        const ok = (!txt || rowText.includes(txt))
                && (!pid  || rowPid  === pid)
                && (!date || rowDate === date);
        tr.classList.toggle('is-hidden', !ok);
    });
}

function clearFilters() {
    document.getElementById('txt-search').value = '';
    document.getElementById('date-search').value = '';
    activeProjFilter = '';
    document.getElementById('proj-label').textContent = 'All Projects';
    document.querySelectorAll('.sel-opt').forEach(o => o.classList.remove('active'));
    document.querySelector('.sel-opt[data-value=""]').classList.add('active');
    doFilter();
}

function toggleProjDrop(e) {
    e.stopPropagation();
    document.getElementById('proj-drop').classList.toggle('show');
    if (document.getElementById('proj-drop').classList.contains('show'))
        document.getElementById('proj-inner-search').focus();
}
function filterProjOpts() {
    const f = document.getElementById('proj-inner-search').value.toLowerCase();
    document.querySelectorAll('.sel-opt').forEach(o => {
        o.style.display = (!o.dataset.value || (o.dataset.kw||'').includes(f)) ? '' : 'none';
    });
}
function pickProj(el) {
    activeProjFilter = el.dataset.value || '';
    document.getElementById('proj-label').textContent = el.textContent.trim();
    document.querySelectorAll('.sel-opt').forEach(o => o.classList.remove('active'));
    el.classList.add('active');
    document.getElementById('proj-drop').classList.remove('show');
    doFilter();
}
window.addEventListener('click', e => {
    if (!e.target.closest('.proj-select-wrap')) document.getElementById('proj-drop').classList.remove('show');
});

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
<?php $conn->close(); ?>