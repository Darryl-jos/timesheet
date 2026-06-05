<?php
require_once 'config.php';

if (!isset($_SESSION['engineer_id']) || !isset($_SESSION['is_admin']) || ($_SESSION['is_admin'] != 1 && $_SESSION['is_admin'] != 2)) {
    header("Location: login.php"); exit;
}

$error = "";

// ── Delete IIPS row ───────────────────────────────────────────────────────────
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

// ── Save project (from create_iips.php redirect) ──────────────────────────────
// Edit IIPS row — load data for edit page redirect
if (isset($_GET['edit_proj'])) {
    header("Location: create_iips.php?edit=".urlencode($_GET['edit_proj'])); exit;
}

// ── Fetch all projects joined with iips_tracking ──────────────────────────────
$result = $conn->query("
    SELECT 
        p.*,
        i.id            AS iips_id,
        i.selling_price, i.partner_cost, i.gross_profit,
        i.has_project_mgmt,
        i.target_mandays, i.target_start_date, i.target_end_date,
        i.target_billing_date,
        NULLIF(i.iips_status, 'Not Quoted')    AS iips_status,
        NULLIF(i.billing_status, 'Not Forecasted') AS billing_status,
        i.account_manager, i.account_leader, i.presales_sdm, i.project_manager,
        i.partner
    FROM projects p
    LEFT JOIN iips_tracking i ON p.project_id = i.project_id
    ORDER BY p.project_id ASC
");

// Pull timesheet data per project
function getTimesheetData($conn, $project_id) {
    $stmt = $conn->prepare("
        SELECT
            MIN(start_date) AS actual_start,
            MAX(end_date)   AS actual_end,
            SUM(TIMESTAMPDIFF(MINUTE,
                CONCAT(start_date,' ',start_time),
                CONCAT(end_date,' ',end_time)
            )) AS total_minutes,
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
    $row['ts_pm']       = null; // project_manager not in timesheets table
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
    $days = round($m/480, 1);
    return $h.'h '.$r.'m';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>IIPS List</title>
<style>
    .is-hidden { display: none !important; }

    body { font-family: Arial, sans-serif; margin: 30px; background: #f4f7f6; color: #333; padding-bottom: 20px; }

    .header { display: flex; justify-content: space-between; align-items: center; background: #343a40; padding: 15px 20px; border-radius: 8px; color: white; flex-wrap: wrap; gap: 10px; }
    .header h2 { margin: 0; font-size: 18px; }
    .header a { color: #ffc107; font-weight: bold; text-decoration: none; font-size: 13px; }

    .card { background: white; padding: 20px 25px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); margin-top: 20px; margin-bottom: 30px; }
    .tbl-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; scrollbar-width: none; -ms-overflow-style: none; }
    .tbl-wrap::-webkit-scrollbar { display: none; }
    .tbl-scroll-top { overflow-x: auto; overflow-y: hidden; height: 14px; margin-bottom: 2px; position: sticky; bottom: 0; background: #f4f7f6; border-top: 1px solid #e2e8f0; z-index: 10; }
    .tbl-scroll-top-inner { height: 1px; }

    .toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; gap: 10px; flex-wrap: wrap; }
    .search-input { height: 36px; padding: 0 12px; font-size: 13px; border: 1px solid #ccc; border-radius: 4px; min-width: 240px; flex: 1; }
    .btn-create { background: #28a745; color: white; border: none; padding: 0 20px; height: 36px; border-radius: 4px; font-size: 13px; font-weight: bold; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; white-space: nowrap; }
    .btn-create:hover { background: #218838; }

    /* Error */
    .alert-err { background:#f8d7da; color:#721c24; padding:12px; border-radius:4px; margin-bottom:15px; border:1px solid #f5c6cb; font-size:13px; }

    /* Table */
    table { width: 100%; border-collapse: collapse; min-width: 1600px; }
    th, td { padding: 10px 12px; border-bottom: 1px solid #dee2e6; text-align: left; font-size: 13px; }
    th { background: #f8f9fa; font-weight: bold; color: #495057; white-space: nowrap; }
    tbody tr:hover { background: #f8faff; }

    /* Section header rows */
    .sec-row th {
        text-align: center;
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .5px;
        padding: 5px 8px;
        color: white;
        border: 1px solid rgba(255,255,255,0.15);
    }
    .s-base    { background: #343a40; }
    .s-costing { background: #155724; }
    .s-timeline{ background: #1a237e; }
    .s-actual  { background: #004d40; }
    .s-status  { background: #6a1b4d; }
    .s-res     { background: #4a235a; }
    .s-act     { background: #343a40; width: 90px; }
    th.s-act-col, td.s-act-col { background: white; white-space: nowrap; }

    /* Column tints */
    .bg-manual   { background: #fffbf0; }
    .bg-auto     { background: #f0fdf4; color: #065f46; }
    .bg-calc     { background: #eff6ff; }
    .bg-dropdown { background: #fdf4ff; }

    /* Badges */
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

    /* PM toggle */
    .tog { display: inline-flex; align-items: center; gap: 5px; }
    .tog-track { width: 30px; height: 16px; background: #d1d5db; border-radius: 8px; position: relative; }
    .tog-track.on { background: #28a745; }
    .tog-thumb { position: absolute; top: 2px; left: 2px; width: 12px; height: 12px; background: white; border-radius: 50%; transition: transform .2s; box-shadow: 0 1px 2px rgba(0,0,0,.2); }
    .tog-track.on .tog-thumb { transform: translateX(14px); }
    .tog-lbl { font-size: 11px; font-weight: 700; color: #6b7280; }
    .tog-lbl.yes { color: #166534; }

    /* Action buttons — same style as admin_projects.php */
    td:last-child { white-space: nowrap; }
    .btn-edit { background: #ffc107; color: #333; padding: 4px 10px; text-decoration: none; border-radius: 4px; font-size: 12px; font-weight: bold; margin-right: 4px; }
    .btn-del  { background: #dc3545; color: white; padding: 4px 10px; text-decoration: none; border-radius: 4px; font-size: 12px; font-weight: bold; }
    .btn-edit:hover { background: #e0a800; }
    .btn-del:hover  { background: #c82333; }

    /* Sort */
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

    /* Auto cell */
    .auto-val { font-size: 12px; color: #065f46; }
    .dash { color: #9ca3af; }

    @media (max-width: 768px) {
        body { margin: 10px; }
        .header { padding: 10px; }
        .card { padding: 12px; }
        .toolbar { flex-direction: column; align-items: stretch; }
        .search-input { min-width: 0; width: 100%; }
    }
</style>
</head>
<body>

<div class="header">
    <h2>📋 IIPS List</h2>
    <a href="admin.php">← Back to Admin</a>
</div>

<div class="card">
    <?php if ($error): ?>
        <div class="alert-err">⚠️ <?= $error ?></div>
    <?php endif; ?>

    <!-- Toolbar: Search + Create only -->
    <div class="toolbar">
        <input type="text" class="search-input" id="search-input" placeholder="🔍 Search IIPS ID, name, customer...">
        <a href="create_iips.php" class="btn-create">+ Create IIPS</a>
    </div>

    <div class="tbl-wrap" id="tbl-wrap">
    <table id="main-table">
        <thead>
            <!-- Section header row -->
            <tr class="sec-row">
                <th class="s-base"  colspan="3">IIPS Details</th>
                <th class="s-costing" colspan="4">IIPS Costing</th>
                <th class="s-timeline" colspan="3">Timeline — Target</th>
                <th class="s-actual"   colspan="3">Timeline — Actual (Timesheet)</th>
                <th class="s-status"   colspan="3">Status</th>
                <th class="s-res"      colspan="7">IIPS Management</th>
            </tr>
            <!-- Column header row -->
            <tr>
                <th>
                    <div class="sort-wrap">IIPS ID
                        <button class="sort-btn" onclick="toggleSort(event,'s-pid')"></button>
                        <div id="s-pid" class="sort-menu">
                            <a href="#" onclick="sortT(0,'alpha',0);return false;">Default</a>
                            <a href="#" onclick="sortT(0,'alpha',1);return false;">A → Z</a>
                            <a href="#" onclick="sortT(0,'alpha',2);return false;">Z → A</a>
                        </div>
                    </div>
                </th>
                <th>IIPS Name</th>
                <th>Customer Name</th>
                <!-- Costing -->
                <th>Selling Price (RM)</th>
                <th>Partner Cost (RM)</th>
                <th>Gross Profit (RM)</th>
                <th>Project Mgmt</th>
                <!-- Target -->
                <th>Target Man-Days (hrs)</th>
                <th>Target Start</th>
                <th>Target End</th>
                <!-- Actual -->
                <th>Actual Start</th>
                <th>Actual End</th>
                <th>Actual Man-Days (hr)</th>
                <!-- Status -->
                <th>IIPS Status</th>
                <th>Target Billing Date</th>
                <th>Billing Status</th>
                <!-- Resources -->
                <th>Account Manager</th>
                <th>Account Leader</th>
                <th>Pre-Sales / SDM</th>
                <th>IIPS Manager</th>
                <th>Partner</th>
                <th>Engineers</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($rows)): ?>
            <tr><td colspan="24" style="text-align:center;padding:40px;color:#9ca3af;">No projects yet. Click "Create IIPS" to add one.</td></tr>
        <?php else: foreach ($rows as $r):
            $pid_display = fmtPid($r['project_id']);
            $gp  = floatval($r['gross_profit'] ?? 0);
            $gp_color = $gp > 0 ? '#166534' : ($gp < 0 ? '#dc2626' : '#6b7280');

            $status_badge = [
                'Not Quoted'=>'b-nq','Quoted'=>'b-q','Not Started'=>'b-ns',
                'In Progress'=>'b-ip','Completed'=>'b-done','Cancelled'=>'b-can',
            ][$r['iips_status'] ?? 'Not Quoted'] ?? 'b-nq';
            $billing_badge = [
                'Not Forecasted'=>'b-nf','Forecasted'=>'b-fc',
                'Pending'=>'b-pend','Completed'=>'b-bdc',
            ][$r['billing_status'] ?? 'Not Forecasted'] ?? 'b-nf';

            // Project Manager: from timesheet if available, else from iips_tracking
            $pm_display = $r['ts_pm'] ?: ($r['project_manager'] ?: null);
        ?>
        <tr>
            <td style="font-size:12px; font-weight:600; color:#1d4ed8;"><?= $pid_display === '-' ? '<span class="dash">—</span>' : htmlspecialchars($pid_display) ?></td>
            <td><strong><?= htmlspecialchars($r['project_name']) ?></strong></td>
            <td style="font-size:12px;"><?= htmlspecialchars($r['customer_name']) ?></td>
            <!-- Costing -->
            <td class="bg-manual"><?= $r['selling_price'] !== null ? 'RM '.number_format($r['selling_price'],2) : '<span class="dash">—</span>' ?></td>
            <td class="bg-manual"><?= $r['partner_cost']  !== null ? 'RM '.number_format($r['partner_cost'],2)  : '<span class="dash">—</span>' ?></td>
            <td class="bg-calc" style="font-weight:700; color:<?= $gp_color ?>">
                <?= ($r['selling_price'] !== null && $r['partner_cost'] !== null) ? 'RM '.number_format($gp,2) : '<span class="dash">—</span>' ?>
            </td>
            <td class="bg-manual">
                <?php $pm_val = intval($r['has_project_mgmt'] ?? 0); ?>
                <div class="tog">
                    <div class="tog-track <?= $pm_val ? 'on' : '' ?>">
                        <div class="tog-thumb"></div>
                    </div>
                    <span class="tog-lbl <?= $pm_val ? 'yes' : '' ?>"><?= $pm_val ? 'Yes' : 'No' ?></span>
                </div>
            </td>
            <!-- Target -->
            <td class="bg-manual"><?= $r['target_mandays'] ? htmlspecialchars($r['target_mandays']).' hrs' : '<span class="dash">—</span>' ?></td>
            <td class="bg-manual"><?= $r['target_start_date'] ? fmtDate($r['target_start_date']) : '<span class="dash">—</span>' ?></td>
            <td class="bg-manual"><?= $r['target_end_date']   ? fmtDate($r['target_end_date'])   : '<span class="dash">—</span>' ?></td>
            <!-- Actual from timesheet -->
            <td class="bg-auto"><span class="auto-val"><?= $r['ts_start'] ? fmtDate($r['ts_start']) : '<span class="dash">—</span>' ?></span></td>
            <td class="bg-auto"><span class="auto-val"><?= ($r['ts_end'] && $r['iips_status'] === 'Completed') ? fmtDate($r['ts_end']) : '<span class="dash">—</span>' ?></span></td>
            <td class="bg-auto"><span class="auto-val"><?= $r['ts_minutes'] > 0 ? fmtMins($r['ts_minutes']) : '<span class="dash">—</span>' ?></span></td>
            <!-- Status -->
            <td class="bg-dropdown">
                <?php if (!empty($r['iips_status']) && $r['iips_status'] === 'Completed'): ?>
                    <span class="badge <?= $status_badge ?>"><?= htmlspecialchars($r['iips_status']) ?></span>
                <?php else: ?><span class="dash">—</span><?php endif; ?>
            </td>
            <td class="bg-manual"><?= $r['target_billing_date'] ? fmtDate($r['target_billing_date']) : '<span class="dash">—</span>' ?></td>
            <td class="bg-dropdown">
                <?php if (!empty($r['billing_status']) && $r['billing_status'] !== 'Not Forecasted'): ?>
                    <span class="badge <?= $billing_badge ?>"><?= htmlspecialchars($r['billing_status']) ?></span>
                <?php else: ?><span class="dash">—</span><?php endif; ?>
            </td>
            <!-- Resources -->
            <td><?= $r['account_manager'] ? htmlspecialchars($r['account_manager']) : '<span class="dash">—</span>' ?></td>
            <td><?= $r['account_leader']  ? htmlspecialchars($r['account_leader'])  : '<span class="dash">—</span>' ?></td>
            <td><?= $r['presales_sdm']    ? htmlspecialchars($r['presales_sdm'])    : '<span class="dash">—</span>' ?></td>
            <td style="color:#4a235a;font-weight:600;"><?= $r['project_manager'] ? htmlspecialchars($r['project_manager']) : '<span class="dash">—</span>' ?></td>
            <td><?= !empty($r['partner']) ? htmlspecialchars($r['partner']) : '<span class="dash">—</span>' ?></td>
            <td style="font-size:12px;">
                <?php if (!empty($r['ts_engineers'])): ?>
                    <?php foreach(explode(', ', $r['ts_engineers']) as $eng): ?>
                        <div style="white-space:nowrap;">• <?= htmlspecialchars(trim($eng)) ?></div>
                    <?php endforeach; ?>
                <?php else: ?><span class="dash">—</span><?php endif; ?>
            </td>
            <!-- Actions -->
            <td class="s-act-col">
                <a href="admin_iips.php?edit_proj=<?= urlencode($r['project_id']) ?>" class="btn-edit">Edit</a>
                <a href="admin_iips.php?delete_proj=<?= urlencode($r['project_id']) ?>" class="btn-del"
                   onclick="return confirm('Delete project <?= htmlspecialchars(addslashes($r['project_id'])) ?>?\nThis cannot be undone.')">Delete</a>
            </td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    </div><!-- end tbl-wrap -->

    <div class="tbl-scroll-top" id="tbl-scroll-top">
        <div class="tbl-scroll-top-inner" id="tbl-scroll-inner"></div>
    </div>
</div>

<script>


// ── Top scroll mirror ─────────────────────────────────────────────────────────
window.addEventListener('DOMContentLoaded', function() {
    const top   = document.getElementById('tbl-scroll-top');
    const wrap  = document.getElementById('tbl-wrap');
    const inner = document.getElementById('tbl-scroll-inner');
    if (top && wrap && inner) {
        const tbl = wrap.querySelector('table');
        if (tbl) inner.style.width = tbl.offsetWidth + 'px';
        top.addEventListener('scroll',  function() { wrap.scrollLeft = top.scrollLeft; });
        wrap.addEventListener('scroll', function() { top.scrollLeft  = wrap.scrollLeft; });
    }
});

// ── Search ────────────────────────────────────────────────────────────────────
document.getElementById('search-input').addEventListener('input', function() {
    const f = this.value.toLowerCase();
    document.querySelectorAll('#main-table tbody tr').forEach(tr => {
        tr.classList.toggle('is-hidden', !!f && !tr.textContent.toLowerCase().includes(f));
    });
});

// ── Sort ──────────────────────────────────────────────────────────────────────
let origRows = null;
function toggleSort(e, id) {
    e.stopPropagation();
    document.querySelectorAll('.sort-menu').forEach(m => { if(m.id!==id) m.classList.remove('show-sort'); });
    document.getElementById(id).classList.toggle('show-sort');
}
window.addEventListener('click', () => document.querySelectorAll('.sort-menu').forEach(m => m.classList.remove('show-sort')));
function sortT(col, type, dir) {
    const tbody = document.querySelector('#main-table tbody');
    const rows  = Array.from(tbody.querySelectorAll('tr'));
    if (!origRows) origRows = [...rows];
    if (dir===0) { origRows.forEach(r => tbody.appendChild(r)); return; }
    rows.sort((a,b) => {
        const ca = a.cells[col].textContent.trim();
        const cb = b.cells[col].textContent.trim();
        if (type==='alpha') return dir===1 ? ca.localeCompare(cb) : cb.localeCompare(ca);
        return 0;
    });
    rows.forEach(r => tbody.appendChild(r));
}
</script>
</body>
</html>