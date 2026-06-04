<?php
require_once 'config.php';

if (!isset($_SESSION['engineer_id']) || !isset($_SESSION['is_admin']) || ($_SESSION['is_admin'] != 1 && $_SESSION['is_admin'] != 2)) {
    header("Location: login.php");
    exit;
}

// ── Helper: pull live timesheet data per project ─────────────────────────────
function getTimesheetData($conn, $project_id) {
    $stmt = $conn->prepare("
        SELECT
            MIN(start_date) AS actual_start,
            MAX(end_date)   AS actual_end,
            SUM(TIMESTAMPDIFF(MINUTE,
                CONCAT(start_date,' ',start_time),
                CONCAT(end_date,' ',end_time)
            )) AS total_minutes,
            GROUP_CONCAT(DISTINCT engineer_name ORDER BY engineer_name SEPARATOR '\n') AS engineers_list
        FROM timesheets WHERE project_id = ?
    ");
    $stmt->bind_param("s", $project_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row;
}

// ── AJAX save ─────────────────────────────────────────────────────────────────
if (isset($_POST['ajax_save'])) {
    header('Content-Type: application/json');
    $iips_id = intval($_POST['iips_id']);
    $field   = $_POST['field'];
    $value   = trim($_POST['value']);

    $allowed = [
        'selling_price','partner_cost','has_project_mgmt',
        'target_mandays','target_start_date','target_end_date','target_billing_date',
        'iips_status','billing_status',
        'account_manager','account_leader','presales_sdm','project_manager'
    ];
    if (!in_array($field, $allowed)) { echo json_encode(['ok'=>false,'msg'=>'Invalid field']); exit; }

    if ($field === 'selling_price' || $field === 'partner_cost') {
        $stmt = $conn->prepare("SELECT selling_price, partner_cost FROM iips_tracking WHERE id=?");
        $stmt->bind_param("i", $iips_id); $stmt->execute();
        $cur = $stmt->get_result()->fetch_assoc(); $stmt->close();
        $sp = ($field==='selling_price') ? floatval($value) : floatval($cur['selling_price']);
        $pc = ($field==='partner_cost')  ? floatval($value) : floatval($cur['partner_cost']);
        $gp = $sp - $pc;
        $stmt2 = $conn->prepare("UPDATE iips_tracking SET `$field`=?, gross_profit=? WHERE id=?");
        $stmt2->bind_param("ddi", $value, $gp, $iips_id); $stmt2->execute(); $stmt2->close();
        echo json_encode(['ok'=>true, 'gross_profit'=>number_format($gp,2)]);
        exit;
    }
    $stmt = $conn->prepare("UPDATE iips_tracking SET `$field`=? WHERE id=?");
    $stmt->bind_param("si", $value, $iips_id); $stmt->execute(); $stmt->close();
    echo json_encode(['ok'=>true]);
    exit;
}

// ── AJAX save project name / customer name ────────────────────────────────────
if (isset($_POST['ajax_save_project'])) {
    header('Content-Type: application/json');
    $pid   = trim($_POST['project_id']);
    $field = $_POST['field'];
    $value = trim($_POST['value']);
    $allowed_proj = ['project_name','customer_name','project_id_rename'];
    if (!in_array($field, $allowed_proj) || empty($pid) || empty($value)) {
        echo json_encode(['ok'=>false]); exit;
    }
    // Rename project_id — update projects PK and iips_tracking FK
    if ($field === 'project_id_rename') {
        $conn->begin_transaction();
        try {
            $conn->query("SET FOREIGN_KEY_CHECKS=0");
            $s1 = $conn->prepare("UPDATE projects SET project_id=? WHERE project_id=?");
            $s1->bind_param("ss", $value, $pid); $s1->execute(); $s1->close();
            $s2 = $conn->prepare("UPDATE iips_tracking SET project_id=? WHERE project_id=?");
            $s2->bind_param("ss", $value, $pid); $s2->execute(); $s2->close();
            $s3 = $conn->prepare("UPDATE timesheets SET project_id=? WHERE project_id=?");
            $s3->bind_param("ss", $value, $pid); $s3->execute(); $s3->close();
            $conn->query("SET FOREIGN_KEY_CHECKS=1");
            $conn->commit();
            echo json_encode(['ok'=>true]);
        } catch(Exception $e) {
            $conn->rollback();
            echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
        }
        exit;
    }
    $stmt = $conn->prepare("UPDATE projects SET `$field`=? WHERE project_id=?");
    $stmt->bind_param("ss", $value, $pid); $stmt->execute(); $stmt->close();
    echo json_encode(['ok'=>true]);
    exit;
}

// ── Add project ───────────────────────────────────────────────────────────────
if (isset($_POST['add_iips'])) {
    $pid = trim($_POST['new_project_id']);
    $chk = $conn->prepare("SELECT id FROM iips_tracking WHERE project_id=?");
    $chk->bind_param("s", $pid); $chk->execute();
    if ($chk->get_result()->num_rows === 0) {
        $ins = $conn->prepare("INSERT INTO iips_tracking (project_id) VALUES (?)");
        $ins->bind_param("s", $pid); $ins->execute(); $ins->close();
    }
    $chk->close();
    header("Location: admin_iips.php"); exit;
}

// ── Delete ────────────────────────────────────────────────────────────────────
if (isset($_GET['delete_id'])) {
    $del = intval($_GET['delete_id']);
    $stmt = $conn->prepare("DELETE FROM iips_tracking WHERE id=?");
    $stmt->bind_param("i", $del); $stmt->execute(); $stmt->close();
    header("Location: admin_iips.php"); exit;
}

// ── Sync actuals from timesheets ──────────────────────────────────────────────
if (isset($_GET['sync'])) {
    $rows = $conn->query("SELECT id, project_id FROM iips_tracking");
    while ($r = $rows->fetch_assoc()) {
        $ts = getTimesheetData($conn, $r['project_id']);
        $stmt = $conn->prepare("UPDATE iips_tracking SET actual_start_date=?, actual_end_date=? WHERE id=?");
        $stmt->bind_param("ssi", $ts['actual_start'], $ts['actual_end'], $r['id']);
        $stmt->execute(); $stmt->close();
    }
    header("Location: admin_iips.php"); exit;
}

// ── Fetch all IIPS rows ───────────────────────────────────────────────────────
$iips_rows = $conn->query("
    SELECT i.*, p.project_name, p.customer_name, p.estimate_time
    FROM iips_tracking i
    JOIN projects p ON i.project_id = p.project_id
    ORDER BY i.id ASC
");
$available_projects = $conn->query("
    SELECT p.project_id, p.project_name, p.customer_name
    FROM projects p
    WHERE p.project_id NOT IN (SELECT project_id FROM iips_tracking)
    ORDER BY p.project_id ASC
");

$iips_data = [];
if ($iips_rows) {
    while ($row = $iips_rows->fetch_assoc()) {
        $ts = getTimesheetData($conn, $row['project_id']);
        $row['ts_actual_start']   = $ts['actual_start']   ?? null;
        $row['ts_actual_end']     = $ts['actual_end']     ?? null;
        $row['ts_total_minutes']  = intval($ts['total_minutes']  ?? 0);
        $row['ts_engineers_list'] = $ts['engineers_list'] ?? null;
        $iips_data[] = $row;
    }
}

function fmtDate($d) {
    if (!$d) return null;
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt ? $dt->format('d-M-Y') : $d;
}
function minToHours($m) {
    if (!$m) return null;
    $h = floor($m/60); $r = $m%60;
    $days = round($m/60/8, 1);
    return $h.'h '.$r.'m ('.$days.' days)';
}

$cnt_total     = count($iips_data);
$cnt_progress  = count(array_filter($iips_data, fn($r) => $r['iips_status']==='In Progress'));
$cnt_completed = count(array_filter($iips_data, fn($r) => $r['iips_status']==='Completed'));
$cnt_pending   = count(array_filter($iips_data, fn($r) => in_array($r['iips_status'],['Not Quoted','Quoted','Not Started'])));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>IIPS Tracking — JOS</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Segoe UI', Arial, sans-serif; background: #f0f2f5; color: #1e2330; font-size: 13px; }

/* ── Topbar ── */
.topbar { background: #1e2330; height: 54px; padding: 0 20px; display: flex; align-items: center; gap: 14px; position: sticky; top: 0; z-index: 300; box-shadow: 0 2px 8px rgba(0,0,0,0.18); }
.logo { background: #1a7a4a; color: white; font-weight: 800; font-size: 14px; letter-spacing: 1px; padding: 3px 9px; border-radius: 4px; }
.topbar h1 { color: white; font-size: 15px; font-weight: 600; flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.topbar a { color: #94a3b8; text-decoration: none; font-size: 12px; padding: 5px 10px; border-radius: 4px; transition: all .15s; white-space: nowrap; }
.topbar a:hover { background: rgba(255,255,255,0.08); color: white; }
.btn-sync { background: #1a7a4a !important; color: white !important; font-weight: 600; }

/* ── Page ── */
.page { padding: 18px 18px 60px; }

/* ── Stats ── */
.stats-row { display: flex; gap: 10px; margin-bottom: 14px; flex-wrap: wrap; }
.stat-card { background: white; border-radius: 7px; padding: 10px 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.07); min-width: 100px; flex: 1; border-left: 3px solid #1a7a4a; }
.stat-card.orange { border-left-color: #f59e0b; }
.stat-card.blue   { border-left-color: #3b82f6; }
.stat-lbl { font-size: 10px; text-transform: uppercase; font-weight: 700; color: #64748b; letter-spacing: .5px; }
.stat-val { font-size: 20px; font-weight: 700; margin-top: 2px; }

/* ── Add panel ── */
.add-panel { background: white; border-radius: 7px; padding: 12px 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.07); margin-bottom: 14px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
.add-panel label { font-weight: 600; font-size: 13px; white-space: nowrap; }
.add-panel select { flex: 1; min-width: 220px; height: 34px; padding: 0 10px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 12px; }
.add-panel button { background: #1a7a4a; color: white; border: none; padding: 0 16px; height: 34px; border-radius: 4px; font-weight: 600; font-size: 12px; cursor: pointer; white-space: nowrap; }

/* ── Legend ── */
.legend { display: flex; gap: 14px; margin-bottom: 10px; flex-wrap: wrap; align-items: center; font-size: 11px; color: #64748b; }
.leg-item { display: flex; align-items: center; gap: 5px; }
.leg-dot { width: 12px; height: 12px; border-radius: 2px; border: 1px solid #e5e7eb; }
.leg-tip { color: #94a3b8; font-size: 11px; margin-left: 6px; }

/* ── Table Wrapper ── */
.tbl-outer { background: white; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); overflow: auto; max-width: 100%; }

/* ── Main Table ── */
table {
    border-collapse: collapse;
    white-space: nowrap;
    font-size: 12px;
    /* min-width drives horizontal scroll */
    min-width: 2200px;
}

/* ── Section header row ── */
.sec-hdr th {
    padding: 7px 10px;
    text-align: center;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .6px;
    border: 1px solid rgba(255,255,255,0.15);
    color: white;
}
.s-act      { background: #374151; width: 50px; }
.s-details  { background: #1e3a5f; }
.s-costing  { background: #14532d; }
.s-tgt      { background: #4c1d95; }
.s-actual   { background: #065f46; }
.s-status   { background: #7c2d12; }
.s-manual   { background: #1e3a5f; }
.s-ts       { background: #065f46; }

/* ── Column header row ── */
.col-hdr th {
    background: #f8fafc;
    padding: 8px 10px;
    font-size: 11px;
    font-weight: 700;
    color: #475569;
    border: 1px solid #e2e8f0;
    text-align: center;
    position: sticky;
    top: 0;
    z-index: 10;
    white-space: normal;
    min-width: 0;
    line-height: 1.3;
}

/* Column widths — explicit so nothing squishes */
.w-act    { width: 50px;  min-width: 50px; }
.w-pid    { width: 130px; min-width: 130px; }
.w-pname  { width: 200px; min-width: 200px; white-space: normal; }
.w-cname  { width: 180px; min-width: 180px; white-space: normal; }
.w-price  { width: 130px; min-width: 130px; }
.w-gp     { width: 140px; min-width: 140px; }
.w-pm     { width: 90px;  min-width: 90px; }
.w-md     { width: 110px; min-width: 110px; }
.w-date   { width: 120px; min-width: 120px; }
.w-actual { width: 160px; min-width: 160px; }
.w-status { width: 140px; min-width: 140px; }
.w-res    { width: 140px; min-width: 140px; }
.w-eng    { width: 180px; min-width: 180px; white-space: normal; }

/* ── Data rows ── */
tbody tr { transition: background .1s; }
tbody tr:hover { background: #f7faff; }
tbody td {
    padding: 7px 10px;
    border: 1px solid #e5e7eb;
    vertical-align: middle;
    font-size: 12px;
    text-align: center;
}
/* left-align text cells */
td.left { text-align: left; }

/* ── Column tint by type ── */
.bg-manual   { background: #fffbf0; }
.bg-auto     { background: #f0fdf4; }
.bg-calc     { background: #eff6ff; }
.bg-dropdown { background: #fdf4ff; }

/* ── Editable cell ── */
td.editable { cursor: pointer; position: relative; }
td.editable:hover { outline: 2px solid #93c5fd; outline-offset: -2px; }
td.editable:hover::after { content: '✏'; position: absolute; top: 3px; right: 4px; font-size: 9px; color: #93c5fd; }

/* Inline input/select */
.cell-input, .cell-select {
    display: none;
    width: 100%;
    border: 1px solid #3b82f6;
    border-radius: 3px;
    padding: 3px 6px;
    font-size: 12px;
    outline: none;
    background: white;
    box-sizing: border-box;
}
.cell-input.active, .cell-select.active { display: block; }
.cell-display.hidden { display: none; }

/* ── Money display ── */
.money { font-weight: 700; }
.money::before { content: 'RM '; font-size: 10px; color: #94a3b8; font-weight: 400; }
.gp-pos { color: #166534; font-weight: 700; }
.gp-neg { color: #dc2626; font-weight: 700; }
.gp-zero { color: #94a3b8; }

/* ── Toggle switch ── */
.tog-wrap { display: inline-flex; align-items: center; gap: 6px; cursor: pointer; }
.tog-track { width: 34px; height: 18px; background: #d1d5db; border-radius: 9px; position: relative; transition: background .2s; flex-shrink: 0; }
.tog-track.on { background: #1a7a4a; }
.tog-thumb { position: absolute; top: 2px; left: 2px; width: 14px; height: 14px; background: white; border-radius: 50%; transition: transform .2s; box-shadow: 0 1px 3px rgba(0,0,0,.25); }
.tog-track.on .tog-thumb { transform: translateX(16px); }
.tog-lbl { font-size: 11px; font-weight: 700; }
.tog-lbl.yes { color: #166534; }
.tog-lbl.no  { color: #94a3b8; }

/* ── Status badges ── */
.badge { display: inline-block; padding: 2px 9px; border-radius: 20px; font-size: 11px; font-weight: 700; white-space: nowrap; }
.b-nq   { background: #f1f5f9; color: #475569; }
.b-q    { background: #dbeafe; color: #1e40af; }
.b-ns   { background: #fef9c3; color: #854d0e; }
.b-ip   { background: #dcfce7; color: #166534; }
.b-done { background: #166534; color: white; }
.b-can  { background: #fee2e2; color: #991b1b; }
.b-nf   { background: #f1f5f9; color: #475569; }
.b-fc   { background: #dbeafe; color: #1e40af; }
.b-pend { background: #fef9c3; color: #854d0e; }
.b-bdc  { background: #166534; color: white; }

/* ── Auto cell ── */
.auto-cell { background: #f0fdf4; color: #166534; font-size: 11px; font-weight: 600; }
.eng-line { font-size: 11px; color: #0369a1; line-height: 1.7; text-align: left; white-space: pre-line; }

/* ── Delete button ── */
.btn-del { background: #fee2e2; color: #991b1b; border: none; padding: 3px 9px; border-radius: 4px; font-size: 11px; font-weight: 700; cursor: pointer; }
.btn-del:hover { background: #fca5a5; }

/* ── Empty dash ── */
.dash { color: #d1d5db; }

/* ── Toast ── */
#toast { position: fixed; bottom: 20px; right: 20px; background: #1e2330; color: white; padding: 8px 16px; border-radius: 6px; font-size: 12px; z-index: 9999; opacity: 0; transform: translateY(6px); transition: all .2s; pointer-events: none; }
#toast.show { opacity: 1; transform: translateY(0); }
</style>
</head>
<body>

<div class="topbar">
    <span class="logo">jos</span>
    <h1>Professional Service (IIPS) Tracking</h1>
    <a href="admin_iips.php?sync=1" class="btn-sync" onclick="return confirm('Sync actual dates & engineers from timesheets?')">⟳ Sync Timesheets</a>
    <a href="admin.php">← Admin</a>
    <a href="login.php?action=logout">Logout</a>
</div>

<div class="page">

    <!-- Stats -->
    <div class="stats-row">
        <div class="stat-card"><div class="stat-lbl">Total IIPS</div><div class="stat-val"><?= $cnt_total ?></div></div>
        <div class="stat-card orange"><div class="stat-lbl">In Progress</div><div class="stat-val"><?= $cnt_progress ?></div></div>
        <div class="stat-card" style="border-left-color:#166534;"><div class="stat-lbl">Completed</div><div class="stat-val"><?= $cnt_completed ?></div></div>
        <div class="stat-card blue"><div class="stat-lbl">Pending / Quoted</div><div class="stat-val"><?= $cnt_pending ?></div></div>
    </div>

    <!-- Add project -->
    <div class="add-panel">
        <label>+ Add Project to IIPS Tracker:</label>
        <form method="POST" style="display:flex;gap:8px;flex:1;flex-wrap:wrap;">
            <select name="new_project_id" required>
                <option value="">-- Select project not yet tracked --</option>
                <?php while($ap = $available_projects->fetch_assoc()): ?>
                <option value="<?= htmlspecialchars($ap['project_id']) ?>">
                    [<?= htmlspecialchars($ap['project_id']) ?>] <?= htmlspecialchars($ap['project_name']) ?> — <?= htmlspecialchars($ap['customer_name']) ?>
                </option>
                <?php endwhile; ?>
            </select>
            <button type="submit" name="add_iips">Add to Tracker</button>
        </form>
    </div>

    <!-- Legend -->
    <div class="legend">
        <div class="leg-item"><div class="leg-dot" style="background:#fffbf0;"></div> Manual Input</div>
        <div class="leg-item"><div class="leg-dot" style="background:#f0fdf4;"></div> Auto from Timesheet</div>
        <div class="leg-item"><div class="leg-dot" style="background:#eff6ff;"></div> Auto Calculated</div>
        <div class="leg-item"><div class="leg-dot" style="background:#fdf4ff;"></div> Dropdown Select</div>
        <span class="leg-tip">💡 Click any highlighted cell to edit inline. Changes save automatically.</span>
    </div>

    <!-- Table -->
    <div class="tbl-outer">
    <table>
        <thead>
            <!-- Row 1: Section headers -->
            <tr class="sec-hdr">
                <th class="s-act w-act"   rowspan="2">ACT</th>
                <th class="s-details"     colspan="3">IIPS Details</th>
                <th class="s-costing"     colspan="4">IIPS Costing</th>
                <th class="s-tgt"         colspan="3">IIPS Timeline — Target</th>
                <th class="s-actual"      colspan="3">IIPS Timeline — Actual (from Timesheet)</th>
                <th class="s-status"      colspan="2">IIPS Status</th>
                <th class="s-manual"      colspan="4">IIPS Resources — Manual</th>
                <th class="s-ts"          colspan="2">IIPS Resources — Timesheet</th>
            </tr>
            <!-- Row 2: Column headers -->
            <tr class="col-hdr">
                <!-- Details -->
                <th class="w-pid">Project ID</th>
                <th class="w-pname">Project Name</th>
                <th class="w-cname">Customer Name</th>
                <!-- Costing -->
                <th class="w-price">Selling Price (RM)</th>
                <th class="w-price">Partner Cost (RM)</th>
                <th class="w-gp">Gross Profit (RM)</th>
                <th class="w-pm">Project Management</th>
                <!-- Target -->
                <th class="w-md">Target Man-Days (hr)</th>
                <th class="w-date">Target Start Date</th>
                <th class="w-date">Target End Date</th>
                <!-- Actual -->
                <th class="w-date">Actual Start Date</th>
                <th class="w-date">Actual End Date</th>
                <th class="w-actual">Actual Man-Days (hr)</th>
                <!-- Status -->
                <th class="w-status">IIPS Status</th>
                <th class="w-date">Target Billing Date</th>
                <th class="w-status">Billing Status</th>
                <!-- Resources manual -->
                <th class="w-res">Account Manager</th>
                <th class="w-res">Account Leader</th>
                <th class="w-res">Pre-Sales / SDM</th>
                <th class="w-res">Project Manager</th>
                <!-- Resources timesheet -->
                <th class="w-eng">Engineer(s)</th>
                <th class="w-res">Partner</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($iips_data)): ?>
            <tr><td colspan="24" style="text-align:center;padding:50px;color:#9ca3af;font-size:14px;">
                No IIPS records yet. Add a project above to get started.
            </td></tr>
        <?php else: ?>
        <?php foreach ($iips_data as $row):
            $gp     = floatval($row['gross_profit'] ?? 0);
            $gp_cls = $gp > 0 ? 'gp-pos' : ($gp < 0 ? 'gp-neg' : 'gp-zero');
            $has_sp = ($row['selling_price'] !== null && $row['selling_price'] !== '');
            $has_pc = ($row['partner_cost']  !== null && $row['partner_cost']  !== '');

            $pm_on  = $row['has_project_mgmt'] ? 'on' : '';
            $pm_lbl = $row['has_project_mgmt'] ? 'Yes' : 'No';
            $pm_cls = $row['has_project_mgmt'] ? 'yes' : 'no';

            $status_badge = [
                'Not Quoted'  => 'b-nq',  'Quoted'      => 'b-q',
                'Not Started' => 'b-ns',  'In Progress' => 'b-ip',
                'Completed'   => 'b-done','Cancelled'   => 'b-can',
            ][$row['iips_status']] ?? 'b-nq';

            $billing_badge = [
                'Not Forecasted' => 'b-nf', 'Forecasted' => 'b-fc',
                'Pending'        => 'b-pend','Completed'  => 'b-bdc',
            ][$row['billing_status']] ?? 'b-nf';

            $engs = $row['ts_engineers_list']
                ? array_filter(array_map('trim', explode("\n", $row['ts_engineers_list'])))
                : [];
        ?>
        <tr data-id="<?= $row['id'] ?>">
            <!-- ACT -->
            <td class="w-act">
                <button class="btn-del" onclick="delRow(<?= $row['id'] ?>, '<?= htmlspecialchars($row['project_id']) ?>')">✕</button>
            </td>

            <!-- Project ID — editable (also updates iips_tracking FK + projects key) -->
            <td class="w-pid left bg-manual editable" onclick="startEdit(this,'project_id_display','text')">
                <span class="cell-display"><strong style="color:#1d4ed8;font-size:11px;"><?= htmlspecialchars($row['project_id']) ?></strong></span>
                <input class="cell-input" type="text" value="<?= htmlspecialchars($row['project_id']) ?>">
                <input type="hidden" class="real-pid" value="<?= htmlspecialchars($row['project_id']) ?>">
            </td>

            <!-- Project Name — editable -->
            <td class="w-pname left bg-manual editable" onclick="startEdit(this,'project_name_display','text')" style="white-space:normal;">
                <span class="cell-display"><?= htmlspecialchars($row['project_name']) ?: '<span class="dash">—</span>' ?></span>
                <input class="cell-input" type="text" value="<?= htmlspecialchars($row['project_name']) ?>">
            </td>

            <!-- Customer Name — editable -->
            <td class="w-cname left bg-manual editable" onclick="startEdit(this,'customer_name_display','text')" style="white-space:normal;">
                <span class="cell-display"><?= htmlspecialchars($row['customer_name']) ?: '<span class="dash">—</span>' ?></span>
                <input class="cell-input" type="text" value="<?= htmlspecialchars($row['customer_name']) ?>">
            </td>

            <!-- Selling Price -->
            <td class="w-price bg-manual editable" onclick="startEdit(this,'selling_price','number')">
                <span class="cell-display">
                    <?= $has_sp ? '<span class="money">'.number_format($row['selling_price'],2).'</span>' : '<span class="dash">—</span>' ?>
                </span>
                <input class="cell-input" type="number" step="0.01" min="0" value="<?= htmlspecialchars($row['selling_price'] ?? '') ?>">
            </td>

            <!-- Partner Cost -->
            <td class="w-price bg-manual editable" onclick="startEdit(this,'partner_cost','number')">
                <span class="cell-display">
                    <?= $has_pc ? '<span class="money">'.number_format($row['partner_cost'],2).'</span>' : '<span class="dash">—</span>' ?>
                </span>
                <input class="cell-input" type="number" step="0.01" min="0" value="<?= htmlspecialchars($row['partner_cost'] ?? '') ?>">
            </td>

            <!-- Gross Profit — auto calculated -->
            <td class="w-gp bg-calc">
                <span class="gp-cell <?= $gp_cls ?>">
                    <?= ($has_sp && $has_pc) ? '<span class="money">'  .number_format($gp,2).'</span>' : '<span class="dash" style="font-size:10px;">auto-fill</span>' ?>
                </span>
            </td>

            <!-- Project Management toggle -->
            <td class="w-pm bg-manual">
                <div class="tog-wrap" onclick="togglePM(this, <?= $row['id'] ?>)" data-state="<?= $row['has_project_mgmt'] ?>">
                    <div class="tog-track <?= $pm_on ?>"><div class="tog-thumb"></div></div>
                    <span class="tog-lbl <?= $pm_cls ?>"><?= $pm_lbl ?></span>
                </div>
            </td>

            <!-- Target Man-Days -->
            <td class="w-md bg-manual editable" onclick="startEdit(this,'target_mandays','number')">
                <span class="cell-display">
                    <?= $row['target_mandays'] !== null ? htmlspecialchars($row['target_mandays']).' hrs' : '<span class="dash">—</span>' ?>
                </span>
                <input class="cell-input" type="number" step="0.5" min="0" value="<?= htmlspecialchars($row['target_mandays'] ?? '') ?>">
            </td>

            <!-- Target Start Date -->
            <td class="w-date bg-manual editable" onclick="startEdit(this,'target_start_date','date')">
                <span class="cell-display">
                    <?= $row['target_start_date'] ? fmtDate($row['target_start_date']) : '<span class="dash">—</span>' ?>
                </span>
                <input class="cell-input" type="date" value="<?= htmlspecialchars($row['target_start_date'] ?? '') ?>">
            </td>

            <!-- Target End Date -->
            <td class="w-date bg-manual editable" onclick="startEdit(this,'target_end_date','date')">
                <span class="cell-display">
                    <?= $row['target_end_date'] ? fmtDate($row['target_end_date']) : '<span class="dash">—</span>' ?>
                </span>
                <input class="cell-input" type="date" value="<?= htmlspecialchars($row['target_end_date'] ?? '') ?>">
            </td>

            <!-- Actual Start — from timesheet -->
            <td class="w-date auto-cell">
                <?= $row['ts_actual_start'] ? fmtDate($row['ts_actual_start']) : '<span class="dash">—</span>' ?>
            </td>

            <!-- Actual End — from timesheet -->
            <td class="w-date auto-cell">
                <?= $row['ts_actual_end'] ? fmtDate($row['ts_actual_end']) : '<span class="dash">—</span>' ?>
            </td>

            <!-- Actual Man-Days — from timesheet -->
            <td class="w-actual auto-cell">
                <?= $row['ts_total_minutes'] > 0 ? minToHours($row['ts_total_minutes']) : '<span class="dash">—</span>' ?>
            </td>

            <!-- IIPS Status dropdown -->
            <td class="w-status bg-dropdown editable" onclick="startSelect(this,'iips_status')">
                <span class="cell-display">
                    <span class="badge <?= $status_badge ?>"><?= htmlspecialchars($row['iips_status']) ?></span>
                </span>
                <select class="cell-select" onchange="saveSelect(this,'iips_status')" onblur="closeSelect(this)">
                    <?php foreach (['Not Quoted','Quoted','Not Started','In Progress','Completed','Cancelled'] as $o): ?>
                    <option value="<?= $o ?>" <?= $row['iips_status']===$o?'selected':'' ?>><?= $o ?></option>
                    <?php endforeach; ?>
                </select>
            </td>

            <!-- Target Billing Date -->
            <td class="w-date bg-manual editable" onclick="startEdit(this,'target_billing_date','date')">
                <span class="cell-display">
                    <?= $row['target_billing_date'] ? fmtDate($row['target_billing_date']) : '<span class="dash">—</span>' ?>
                </span>
                <input class="cell-input" type="date" value="<?= htmlspecialchars($row['target_billing_date'] ?? '') ?>">
            </td>

            <!-- Billing Status dropdown -->
            <td class="w-status bg-dropdown editable" onclick="startSelect(this,'billing_status')">
                <span class="cell-display">
                    <span class="badge <?= $billing_badge ?>"><?= htmlspecialchars($row['billing_status']) ?></span>
                </span>
                <select class="cell-select" onchange="saveSelect(this,'billing_status')" onblur="closeSelect(this)">
                    <?php foreach (['Not Forecasted','Forecasted','Pending','Completed'] as $o): ?>
                    <option value="<?= $o ?>" <?= $row['billing_status']===$o?'selected':'' ?>><?= $o ?></option>
                    <?php endforeach; ?>
                </select>
            </td>

            <!-- Account Manager -->
            <td class="w-res left bg-manual editable" onclick="startEdit(this,'account_manager','text')">
                <span class="cell-display"><?= $row['account_manager'] ? htmlspecialchars($row['account_manager']) : '<span class="dash">—</span>' ?></span>
                <input class="cell-input" type="text" value="<?= htmlspecialchars($row['account_manager'] ?? '') ?>">
            </td>

            <!-- Account Leader -->
            <td class="w-res left bg-manual editable" onclick="startEdit(this,'account_leader','text')">
                <span class="cell-display"><?= $row['account_leader'] ? htmlspecialchars($row['account_leader']) : '<span class="dash">—</span>' ?></span>
                <input class="cell-input" type="text" value="<?= htmlspecialchars($row['account_leader'] ?? '') ?>">
            </td>

            <!-- Pre-Sales / SDM -->
            <td class="w-res left bg-manual editable" onclick="startEdit(this,'presales_sdm','text')">
                <span class="cell-display"><?= $row['presales_sdm'] ? htmlspecialchars($row['presales_sdm']) : '<span class="dash">—</span>' ?></span>
                <input class="cell-input" type="text" value="<?= htmlspecialchars($row['presales_sdm'] ?? '') ?>">
            </td>

            <!-- Project Manager -->
            <td class="w-res left bg-manual editable" onclick="startEdit(this,'project_manager','text')">
                <span class="cell-display"><?= !empty($row['project_manager']) ? htmlspecialchars($row['project_manager']) : '<span class="dash">—</span>' ?></span>
                <input class="cell-input" type="text" value="<?= htmlspecialchars($row['project_manager'] ?? '') ?>">
            </td>

            <!-- Engineers — from timesheet -->
            <td class="w-eng auto-cell" style="text-align:left;">
                <?php if ($engs): ?>
                    <div class="eng-line"><?php foreach($engs as $e): ?>• <?= htmlspecialchars(trim($e)) ?><?= "\n" ?><?php endforeach; ?></div>
                <?php else: ?>
                    <span class="dash">—</span>
                <?php endif; ?>
            </td>

            <!-- Partner — from timesheet (placeholder) -->
            <td class="w-res auto-cell"><span class="dash">—</span></td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
    </div><!-- end tbl-outer -->
</div>

<div id="toast"></div>

<script>
// ── Toast ─────────────────────────────────────────────────────────────────────
function showToast(msg, ok=true) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.style.background = ok ? '#1e2330' : '#991b1b';
    t.classList.add('show');
    clearTimeout(t._timer);
    t._timer = setTimeout(() => t.classList.remove('show'), 2000);
}

// ── AJAX save ─────────────────────────────────────────────────────────────────
async function saveField(iips_id, field, value) {
    const fd = new FormData();
    fd.append('ajax_save','1');
    fd.append('iips_id', iips_id);
    fd.append('field', field);
    fd.append('value', value);
    try {
        const res  = await fetch('admin_iips.php', {method:'POST', body:fd});
        const data = await res.json();
        if (data.ok) {
            showToast('✓ Saved');
            if ((field==='selling_price'||field==='partner_cost') && data.gross_profit !== undefined) {
                const row = document.querySelector(`tr[data-id="${iips_id}"]`);
                if (row) {
                    const gpCell = row.querySelector('.gp-cell');
                    if (gpCell) {
                        const gp = parseFloat(data.gross_profit.replace(/,/g,''));
                        gpCell.className = 'gp-cell ' + (gp>0?'gp-pos':gp<0?'gp-neg':'gp-zero');
                        gpCell.innerHTML = '<span class="money">'+data.gross_profit+'</span>';
                    }
                }
            }
        } else {
            showToast('✗ Save failed', false);
        }
    } catch(e) { showToast('✗ Network error', false); }
}

// ── Save Project Name / Customer Name directly to projects table ──────────────
async function saveProjectField(field_col, project_id, value) {
    const fd = new FormData();
    fd.append('ajax_save_project','1');
    fd.append('project_id', project_id);
    fd.append('field', field_col);
    fd.append('value', value);
    try {
        const res  = await fetch('admin_iips.php', {method:'POST', body:fd});
        const data = await res.json();
        if (data.ok) showToast('✓ Saved');
        else showToast('✗ Save failed', false);
    } catch(e) { showToast('✗ Network error', false); }
}

// ── Close all open editors ────────────────────────────────────────────────────
function closeAll() {
    document.querySelectorAll('.cell-input.active').forEach(i => i.blur());
    document.querySelectorAll('.cell-select.active').forEach(s => {
        s.classList.remove('active');
        s.closest('td').querySelector('.cell-display').classList.remove('hidden');
    });
}

// ── Inline text/number/date edit ─────────────────────────────────────────────
function startEdit(td, field, type) {
    closeAll();
    const row    = td.closest('tr');
    const iipsId = row.dataset.id;
    const disp   = td.querySelector('.cell-display');
    const inp    = td.querySelector('.cell-input');

    disp.classList.add('hidden');
    inp.classList.add('active');
    inp.focus();
    if (type !== 'date') inp.select();

    const commit = async () => {
        const val = inp.value.trim();
        inp.classList.remove('active');
        disp.classList.remove('hidden');

        // Project ID rename
        if (field === 'project_id_display') {
            const oldPid = td.querySelector('.real-pid').value;
            if (val && val !== oldPid) {
                disp.innerHTML = '<strong style="color:#1d4ed8;font-size:11px;">'+val+'</strong>';
                td.querySelector('.real-pid').value = val;
                await saveProjectField('project_id_rename', oldPid, val);
            }
            return;
        }

        // Project Name / Customer Name — save to projects table
        if (field === 'project_name_display') {
            const pid = row.querySelector('.real-pid').value;
            disp.textContent = val || '—';
            if (val) await saveProjectField('project_name', pid, val);
            return;
        }
        if (field === 'customer_name_display') {
            const pid = row.querySelector('.real-pid').value;
            disp.textContent = val || '—';
            if (val) await saveProjectField('customer_name', pid, val);
            return;
        }

        if (val) {
            if (field.includes('date')) {
                const d = new Date(val+'T00:00:00');
                const mo = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
                disp.textContent = d.getDate()+'-'+mo[d.getMonth()]+'-'+d.getFullYear();
            } else if (field==='selling_price'||field==='partner_cost') {
                disp.innerHTML = '<span class="money">'+parseFloat(val).toLocaleString('en-MY',{minimumFractionDigits:2})+'</span>';
            } else if (field==='target_mandays') {
                disp.textContent = val+' hrs';
            } else {
                disp.textContent = val;
            }
        } else {
            disp.innerHTML = '<span class="dash">—</span>';
        }

        await saveField(iipsId, field, val);
    };

    inp.onblur  = commit;
    inp.onkeydown = e => {
        if (e.key==='Enter') inp.blur();
        if (e.key==='Escape') { inp.classList.remove('active'); disp.classList.remove('hidden'); }
    };
}

// ── Dropdown select ───────────────────────────────────────────────────────────
function startSelect(td, field) {
    closeAll();
    const disp = td.querySelector('.cell-display');
    const sel  = td.querySelector('.cell-select');
    disp.classList.add('hidden');
    sel.classList.add('active');
    sel.focus();
}
async function saveSelect(sel, field) {
    const td   = sel.closest('td');
    const row  = td.closest('tr');
    const id   = row.dataset.id;
    const val  = sel.value;

    sel.classList.remove('active');
    const disp = td.querySelector('.cell-display');
    disp.classList.remove('hidden');

    // Rebuild badge
    const statusMap = {
        'Not Quoted':'b-nq','Quoted':'b-q','Not Started':'b-ns',
        'In Progress':'b-ip','Completed':'b-done','Cancelled':'b-can',
        'Not Forecasted':'b-nf','Forecasted':'b-fc','Pending':'b-pend'
    };
    const cls = (val==='Completed' && field==='billing_status') ? 'b-bdc' : (statusMap[val]||'b-nq');
    disp.innerHTML = `<span class="badge ${cls}">${val}</span>`;

    await saveField(id, field, val);
}
function closeSelect(sel) {
    sel.classList.remove('active');
    sel.closest('td').querySelector('.cell-display').classList.remove('hidden');
}

// ── PM Toggle ─────────────────────────────────────────────────────────────────
async function togglePM(div, id) {
    const cur  = parseInt(div.dataset.state);
    const newV = cur ? 0 : 1;
    div.dataset.state = newV;
    const track = div.querySelector('.tog-track');
    const lbl   = div.querySelector('.tog-lbl');
    track.classList.toggle('on', !!newV);
    lbl.textContent = newV ? 'Yes' : 'No';
    lbl.className   = 'tog-lbl ' + (newV ? 'yes' : 'no');
    await saveField(id, 'has_project_mgmt', newV);
}

// ── Delete row ────────────────────────────────────────────────────────────────
function delRow(id, pid) {
    if (confirm(`Remove "${pid}" from IIPS Tracker?\n(Does NOT delete the project or timesheets.)`))
        window.location = `admin_iips.php?delete_id=${id}`;
}

// ── Close editors on outside click ───────────────────────────────────────────
document.addEventListener('click', e => {
    if (!e.target.closest('td.editable')) closeAll();
});
</script>
</body>
</html>