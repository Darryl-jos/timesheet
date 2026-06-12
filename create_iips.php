<?php
require_once 'config.php';

function fmtDateDisplay($d) {
    if (!$d) return '';
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt ? $dt->format('d-M-Y') : '';
}

if (!isset($_SESSION['engineer_id']) || !isset($_SESSION['is_admin']) || ($_SESSION['is_admin'] != 1 && $_SESSION['is_admin'] != 2)) {
    header("Location: login.php"); exit;
}

$edit_mode = false;
$edit_data = null;
$iips_data = null;

if (isset($_GET['edit'])) {
    $edit_mode = true;
    $pid = trim($_GET['edit']);
    $stmt = $conn->prepare("SELECT * FROM projects WHERE project_id=?");
    $stmt->bind_param("s", $pid); $stmt->execute();
    $edit_data = $stmt->get_result()->fetch_assoc(); $stmt->close();
    $stmt2 = $conn->prepare("SELECT * FROM iips_tracking WHERE project_id=?");
    $stmt2->bind_param("s", $pid); $stmt2->execute();
    $iips_data = $stmt2->get_result()->fetch_assoc(); $stmt2->close();
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old_pid   = trim($_POST['old_project_id'] ?? '');
    $p_id      = !empty(trim($_POST['project_id'])) ? trim($_POST['project_id']) : ('N/A_'.uniqid());
    $p_name    = trim($_POST['project_name']);
    $c_name    = trim($_POST['customer_name']);

    // IIPS Costing
    $selling   = strlen(trim($_POST['selling_price'] ?? '')) > 0 ? floatval($_POST['selling_price']) : null;
    $partner   = strlen(trim($_POST['partner_cost']  ?? '')) > 0 ? floatval($_POST['partner_cost'])  : null;
    $internal  = strlen(trim($_POST['internal_cost'] ?? '')) > 0 ? floatval($_POST['internal_cost']) : 0;
    $gross     = ($selling !== null && $partner !== null) ? $selling - $partner : null;
    $has_pm    = isset($_POST['has_project_mgmt']) ? 1 : 0;

    // Timeline
    $tgt_md    = strlen(trim($_POST['target_mandays']      ?? '')) > 0 ? floatval($_POST['target_mandays'])       : null;
    $tgt_sd    = !empty($_POST['target_start_date'])   ? $_POST['target_start_date']   : null;
    $tgt_ed    = !empty($_POST['target_end_date'])     ? $_POST['target_end_date']     : null;
    $tgt_bd    = !empty($_POST['target_billing_date']) ? $_POST['target_billing_date'] : null;

    // Status
    $iips_stat = $_POST['iips_status']    ?? '';
    $bill_stat = $_POST['billing_status'] ?? '';

    // Resources
    $acc_mgr   = implode(', ', array_filter(array_map('trim', $_POST['account_manager_multi']  ?? [])));
    $acc_ldr   = implode(', ', array_filter(array_map('trim', $_POST['account_leader_multi']   ?? [])));
    $presales  = implode(', ', array_filter(array_map('trim', $_POST['presales_sdm_multi']     ?? [])));
    $proj_mgr  = $iips_data['project_manager'] ?? '';

    // ── Validation ────────────────────────────────────────────────────────────
    if (empty($p_name))    $errors[] = "IIPS Name is required.";
    if (empty($c_name))    $errors[] = "Customer Name is required.";
    if ($selling === null) $errors[] = "Selling Price is required.";
    if ($partner === null) $errors[] = "Partner Cost is required.";
    if ($tgt_md  === null) $errors[] = "Target Man-Days is required.";
    if (!$tgt_sd)          $errors[] = "Target Start Date is required.";
    if (!$tgt_ed)          $errors[] = "Target End Date is required.";
    if (!$tgt_bd)          $errors[] = "Target Billing Date is required.";
    if ($tgt_sd && $tgt_ed && $tgt_ed < $tgt_sd)  $errors[] = "Target End Date must be on or after Target Start Date.";
    if ($tgt_ed && $tgt_bd && $tgt_bd < $tgt_ed)  $errors[] = "Target Billing Date must be on or after Target End Date.";


    if (empty($errors)) {
        if ($edit_mode && !empty($old_pid)) {
            $conn->begin_transaction();
            try {
                $conn->query("SET FOREIGN_KEY_CHECKS=0");

                // Update projects table (no estimate_time/pricing here)
                $s = $conn->prepare("UPDATE projects SET project_id=?, project_name=?, customer_name=? WHERE project_id=?");
                $s->bind_param("ssss", $p_id, $p_name, $c_name, $old_pid);
                $s->execute(); $s->close();

                if ($p_id !== $old_pid) {
                    $u = $conn->prepare("UPDATE timesheets SET project_id=? WHERE project_id=?");
                    $u->bind_param("ss", $p_id, $old_pid); $u->execute(); $u->close();
                    $u2 = $conn->prepare("UPDATE iips_tracking SET project_id=? WHERE project_id=?");
                    $u2->bind_param("ss", $p_id, $old_pid); $u2->execute(); $u2->close();
                }
                $conn->query("SET FOREIGN_KEY_CHECKS=1");

                // Upsert iips_tracking
                $chk = $conn->prepare("SELECT id FROM iips_tracking WHERE project_id=?");
                $chk->bind_param("s", $p_id); $chk->execute();
                $exists = $chk->get_result()->num_rows > 0; $chk->close();

                if ($exists) {
                    $upd = $conn->prepare("UPDATE iips_tracking SET selling_price=?,partner_cost=?,internal_cost=?,gross_profit=?,has_project_mgmt=?,target_mandays=?,target_start_date=?,target_end_date=?,target_billing_date=?,iips_status=?,billing_status=?,account_manager=?,account_leader=?,presales_sdm=?,project_manager=? WHERE project_id=?");
                    $upd->bind_param("ddddidssssssssss", $selling,$partner,$internal,$gross,$has_pm,$tgt_md,$tgt_sd,$tgt_ed,$tgt_bd,$iips_stat,$bill_stat,$acc_mgr,$acc_ldr,$presales,$proj_mgr,$p_id);
                    $upd->execute(); $upd->close();
                } else {
                    $ins = $conn->prepare("INSERT INTO iips_tracking (project_id,selling_price,partner_cost,internal_cost,gross_profit,has_project_mgmt,target_mandays,target_start_date,target_end_date,target_billing_date,iips_status,billing_status,account_manager,account_leader,presales_sdm,project_manager) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                    $ins->bind_param("sddddidsssssssss", $p_id,$selling,$partner,$internal,$gross,$has_pm,$tgt_md,$tgt_sd,$tgt_ed,$tgt_bd,$iips_stat,$bill_stat,$acc_mgr,$acc_ldr,$presales,$proj_mgr);
                    $ins->execute(); $ins->close();
                }
                $conn->commit();
                header("Location: admin_iips.php"); exit;
            } catch(Exception $e) {
                $conn->rollback();
                $errors[] = "Database error: ".$e->getMessage();
            }
        } else {
            // Insert new
            $est_time = 0;
            $pricing = null;
            $s = $conn->prepare("INSERT INTO projects (project_id,project_name,customer_name,estimate_time,pricing) VALUES (?,?,?,?,?)");
            $s->bind_param("sssid", $p_id,$p_name,$c_name,$est_time,$pricing); $s->execute(); $s->close();
            $ins = $conn->prepare("INSERT INTO iips_tracking (project_id,selling_price,partner_cost,internal_cost,gross_profit,has_project_mgmt,target_mandays,target_start_date,target_end_date,target_billing_date,iips_status,billing_status,account_manager,account_leader,presales_sdm,project_manager) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $ins->bind_param("sddddidsssssssss", $p_id,$selling,$partner,$internal,$gross,$has_pm,$tgt_md,$tgt_sd,$tgt_ed,$tgt_bd,$iips_stat,$bill_stat,$acc_mgr,$acc_ldr,$presales,$proj_mgr);
            $ins->execute(); $ins->close();
            header("Location: admin_iips.php"); exit;
        }
    }
}

// Pre-fill
$v = [
    'project_id'          => $_POST['project_id']          ?? ($edit_data['project_id']    ?? ''),
    'project_name'        => $_POST['project_name']        ?? ($edit_data['project_name']  ?? ''),
    'customer_name'       => $_POST['customer_name']       ?? ($edit_data['customer_name'] ?? ''),
    'selling_price'       => $_POST['selling_price']       ?? ($iips_data['selling_price']  ?? ''),
    'partner_cost'        => $_POST['partner_cost']        ?? ($iips_data['partner_cost']   ?? ''),
    'internal_cost'       => $_POST['internal_cost']       ?? ($iips_data['internal_cost']  ?? ''),
    'has_project_mgmt'    => ($_SERVER['REQUEST_METHOD'] === 'POST') ? (isset($_POST['has_project_mgmt']) ? 1 : 0) : ($iips_data['has_project_mgmt'] ?? 0),
    'target_mandays'      => $_POST['target_mandays']      ?? ($iips_data['target_mandays']      ?? ''),
    'target_start_date'   => $_POST['target_start_date']   ?? ($iips_data['target_start_date']   ?? ''),
    'target_end_date'     => $_POST['target_end_date']     ?? ($iips_data['target_end_date']     ?? ''),
    'target_billing_date' => $_POST['target_billing_date'] ?? ($iips_data['target_billing_date'] ?? ''),
    'iips_status'         => $_POST['iips_status']         ?? ($edit_mode ? ($iips_data['iips_status']    ?? '') : ''),
    'billing_status'      => $_POST['billing_status']      ?? ($edit_mode ? ($iips_data['billing_status'] ?? '') : ''),
    'account_manager'     => $_SERVER['REQUEST_METHOD']==='POST' ? implode(', ', array_filter(array_map('trim', $_POST['account_manager_multi'] ?? []))) : ($iips_data['account_manager'] ?? ''),
    'account_leader'      => $_SERVER['REQUEST_METHOD']==='POST' ? implode(', ', array_filter(array_map('trim', $_POST['account_leader_multi']  ?? []))) : ($iips_data['account_leader']  ?? ''),
    'presales_sdm'        => $_SERVER['REQUEST_METHOD']==='POST' ? implode(', ', array_filter(array_map('trim', $_POST['presales_sdm_multi']    ?? []))) : ($iips_data['presales_sdm']    ?? ''),
    'project_manager'     => $_POST['project_manager']     ?? ($iips_data['project_manager'] ?? ''),
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title><?= $edit_mode ? 'Edit' : 'Create' ?> IIPS — IIPS</title>
<style>
* { box-sizing: border-box; }
body { font-family: Arial, sans-serif; margin: 30px; background: #f4f7f6; }

.topbar { background: #343a40; padding: 15px 20px; display: flex; align-items: center; justify-content: space-between; border-radius: 8px; flex-wrap: wrap; gap: 10px; }
.topbar h2 { color: white; margin: 0; font-size: 18px; }
.topbar a { color: #ffc107; font-weight: bold; text-decoration: none; font-size: 13px; }

.page { max-width: 900px; margin: 20px auto; padding: 0 0 60px; }

.section { background: white; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.06); margin-bottom: 20px; overflow: hidden; }
.section-hdr { background: #343a40; color: white; padding: 10px 20px; font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; }
.section-hdr.green  { background: #155724; }
.section-hdr.blue   { background: #1a237e; }
.section-hdr.purple { background: #4a235a; }
.section-hdr.teal   { background: #6a1b4d; }
.section-body { padding: 20px; display: grid; grid-template-columns: 1fr 1fr; gap: 14px 20px; }
.section-body.three-col { grid-template-columns: 1fr 1fr 1fr; }

.form-group { display: flex; flex-direction: column; gap: 4px; }
.form-group label { font-size: 12px; font-weight: 700; color: #495057; }
.req { color: #dc2626; }
.form-group input, .form-group select {
    height: 38px; padding: 0 10px; border: 1px solid #ced4da; border-radius: 4px; font-size: 13px; width: 100%;
}
.form-group input.err, .form-group select.err { border-color: #dc2626; }
.form-group input:focus, .form-group select:focus { border-color: #007bff; outline: none; box-shadow: 0 0 0 2px rgba(0,123,255,.15); }
.hint { font-size: 11px; color: #6c757d; }

.check-group { display: flex; align-items: center; gap: 10px; padding-top: 8px; }
.check-group input[type="checkbox"] { width: 18px; height: 18px; cursor: pointer; }
.check-group label { font-size: 13px; font-weight: 600; color: #333; margin: 0; cursor: pointer; }

.gp-preview { background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 4px; padding: 0 10px; height: 38px; display: flex; align-items: center; font-size: 13px; font-weight: 700; }

/* Errors */
.error-box { background: #fef2f2; border: 1px solid #fca5a5; border-radius: 6px; padding: 12px 16px; margin-bottom: 20px; }
.error-box p { margin: 0 0 6px; font-size: 13px; font-weight: 700; color: #991b1b; }
.error-box ul { margin: 0; padding-left: 18px; }
.error-box ul li { font-size: 12px; color: #b91c1c; margin-bottom: 2px; }

.actions { display: flex; gap: 12px; margin-top: 4px; align-items: center; }
.btn-save { background: #28a745; color: white; border: none; padding: 0 28px; height: 40px; border-radius: 4px; font-size: 14px; font-weight: bold; cursor: pointer; }
.btn-save:hover { background: #218838; }
.btn-cancel { display: inline-flex; align-items: center; color: #6c757d; text-decoration: none; font-size: 13px; height: 40px; }

@media (max-width: 600px) {
    body { margin: 10px; }
    .section-body, .section-body.three-col { grid-template-columns: 1fr; }
}
</style>
<style>.fp-date { cursor:pointer; }</style>
</head>
<body>

<div class="topbar">
    <h2><?= $edit_mode ? '✏️ Edit Project' : '+ Create New IIPS' ?></h2>
    <a href="admin_iips.php">← Back to IIPS List</a>
</div>

<div class="page">

    <?php if (!empty($errors)): ?>
    <div class="error-box">
        <p>⚠️ Please fix the following before saving:</p>
        <ul><?php foreach($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
    </div>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="old_project_id" value="<?= htmlspecialchars($edit_data['project_id'] ?? '') ?>">

        <!-- Project Details -->
        <div class="section">
            <div class="section-hdr">📁 IIPS Details</div>
            <div class="section-body three-col">
                <div class="form-group">
                    <label>IIPS ID</label>
                    <input type="text" name="project_id" value="<?= htmlspecialchars($v['project_id']) ?>" placeholder="e.g. SO-0000123">
                </div>
                <div class="form-group">
                    <label>IIPS Name <span class="req">*</span></label>
                    <input type="text" name="project_name" value="<?= htmlspecialchars($v['project_name']) ?>" class="<?= in_array('Project Name is required.',$errors)?'err':'' ?>">
                </div>
                <div class="form-group">
                    <label>Customer Name <span class="req">*</span></label>
                    <input type="text" name="customer_name" value="<?= htmlspecialchars($v['customer_name']) ?>" class="<?= in_array('Customer Name is required.',$errors)?'err':'' ?>">
                </div>
            </div>
        </div>

        <!-- IIPS Costing -->
        <div class="section">
            <div class="section-hdr green">💰 IIPS Costing</div>
            <div class="section-body three-col">
                <div class="form-group">
                    <label>Selling Price (RM) <span class="req">*</span></label>
                    <input type="number" name="selling_price" id="sp" step="0.01" min="0" value="<?= htmlspecialchars($v['selling_price']) ?>" oninput="calcGP()" class="<?= in_array('Selling Price is required.',$errors)?'err':'' ?>">
                </div>
                <div class="form-group">
                    <label>Partner Cost (RM) <span class="req">*</span></label>
                    <input type="number" name="partner_cost" id="pc" step="0.01" min="0" value="<?= htmlspecialchars($v['partner_cost']) ?>" oninput="calcGP()" class="<?= in_array('Partner Cost is required.',$errors)?'err':'' ?>">
                </div>
                <div class="form-group">
                    <label>Internal Cost (RM)</label>
                    <input type="number" name="internal_cost" id="ic" step="0.01" min="0" value="<?= htmlspecialchars($v['internal_cost']) ?>">
                </div>
                <div class="check-group" style="align-self:center; padding-top:20px;">
                    <input type="checkbox" name="has_project_mgmt" id="has_pm" <?= $v['has_project_mgmt'] ? 'checked' : '' ?>>
                    <label for="has_pm">Project Management Included</label>
                </div>
            </div>
        </div>

        <!-- IIPS Timeline -->
        <div class="section">
            <div class="section-hdr blue">📅 IIPS Timeline — Target</div>
            <div class="section-body">
                <div class="form-group">
                    <label>Target Man-Days (hrs) <span class="req">*</span></label>
                    <input type="number" name="target_mandays" step="0.5" min="0" value="<?= htmlspecialchars($v['target_mandays']) ?>" class="<?= in_array('Target Man-Days is required.',$errors)?'err':'' ?>">
                </div>
                <div class="form-group">
                    <label>Target Billing Date <span class="req">*</span></label>
                    <div style="position:relative; height:38px; width:100%; display:flex;">
                        <input type="text" id="tbd_display" placeholder="DD MMM YYYY" oninput="liveDate(this)" style="flex:1;height:100%;padding:8px 36px 8px 10px;border:1px solid #ced4da;border-radius:4px;font-size:13px;text-transform:uppercase;" autocomplete="off" value="<?= htmlspecialchars(fmtDateDisplay($v['target_billing_date'])) ?>" class="<?= in_array('Target Billing Date is required.',$errors)?'err':'' ?>">
                        <div style="position:absolute;right:0;top:0;width:36px;height:100%;display:flex;align-items:center;justify-content:center;cursor:pointer;z-index:5;" onclick="document.getElementById('tbd_val').showPicker()">📅</div>
                        <input type="date" name="target_billing_date" id="tbd_val" value="<?= htmlspecialchars($v['target_billing_date']) ?>" style="position:absolute;top:0;right:0;width:36px;height:100%;opacity:0;cursor:pointer;z-index:5;" onchange="syncDate('tbd')">
                    </div>
                </div>
                <div class="form-group">
                    <label>Target Start Date <span class="req">*</span></label>
                    <div style="position:relative; height:38px; width:100%; display:flex;">
                        <input type="text" id="tsd_display" placeholder="DD MMM YYYY" oninput="liveDate(this)" style="flex:1;height:100%;padding:8px 36px 8px 10px;border:1px solid #ced4da;border-radius:4px;font-size:13px;text-transform:uppercase;" autocomplete="off" value="<?= htmlspecialchars(fmtDateDisplay($v['target_start_date'])) ?>" class="<?= in_array('Target Start Date is required.',$errors)?'err':'' ?>">
                        <div style="position:absolute;right:0;top:0;width:36px;height:100%;display:flex;align-items:center;justify-content:center;cursor:pointer;z-index:5;" onclick="document.getElementById('tsd_val').showPicker()">📅</div>
                        <input type="date" name="target_start_date" id="tsd_val" value="<?= htmlspecialchars($v['target_start_date']) ?>" style="position:absolute;top:0;right:0;width:36px;height:100%;opacity:0;cursor:pointer;z-index:5;" onchange="syncDate('tsd')">
                    </div>
                </div>
                <div class="form-group">
                    <label>Target End Date <span class="req">*</span></label>
                    <div style="position:relative; height:38px; width:100%; display:flex;">
                        <input type="text" id="ted_display" placeholder="DD MMM YYYY" oninput="liveDate(this)" style="flex:1;height:100%;padding:8px 36px 8px 10px;border:1px solid #ced4da;border-radius:4px;font-size:13px;text-transform:uppercase;" autocomplete="off" value="<?= htmlspecialchars(fmtDateDisplay($v['target_end_date'])) ?>" class="<?= in_array('Target End Date is required.',$errors)?'err':'' ?>">
                        <div style="position:absolute;right:0;top:0;width:36px;height:100%;display:flex;align-items:center;justify-content:center;cursor:pointer;z-index:5;" onclick="document.getElementById('ted_val').showPicker()">📅</div>
                        <input type="date" name="target_end_date" id="ted_val" value="<?= htmlspecialchars($v['target_end_date']) ?>" style="position:absolute;top:0;right:0;width:36px;height:100%;opacity:0;cursor:pointer;z-index:5;" onchange="syncDate('ted')">
                    </div>
                </div>
            </div>
        </div>

        <!-- Status -->
        <div class="section">
            <div class="section-hdr teal">📊 Status</div>
            <div class="section-body">
                <div class="form-group">
                    <label>IIPS Status</label>
                    <select name="iips_status">
                        <option value="" disabled <?= empty($v['iips_status']) || $v['iips_status']==='Not Quoted' && !$edit_mode ?'selected':'' ?>>-- Select IIPS Status --</option>
                        <?php foreach (['Not Quoted','Quoted','Not Started','In Progress','Completed','Cancelled'] as $o): ?>
                        <option value="<?= $o ?>" <?= $v['iips_status']===$o && $edit_mode ?'selected':'' ?>><?= $o ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Billing Status</label>
                    <select name="billing_status">
                        <option value="" disabled <?= empty($v['billing_status']) || $v['billing_status']==='Not Forecasted' && !$edit_mode ?'selected':'' ?>>-- Select Billing Status --</option>
                        <?php foreach (['Not Forecasted','Forecasted','Pending','Completed'] as $o): ?>
                        <option value="<?= $o ?>" <?= $v['billing_status']===$o && $edit_mode ?'selected':'' ?>><?= $o ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <!-- Resources -->
        <div class="section">
            <div class="section-hdr purple">👥 IIPS Management <span style="font-weight:400; font-size:11px; opacity:.7;">(optional)</span></div>
            <div class="section-body">
                <div class="form-group">
                    <label>Account Manager</label>
                    <div id="acc-mgr-list">
                        <?php $am_names = array_filter(array_map('trim', explode(',', $v['account_manager']))); if(empty($am_names)) $am_names = ['']; foreach($am_names as $i => $n): ?>
                        <div class="multi-name-row" style="display:flex;gap:8px;margin-bottom:6px;">
                            <input type="text" name="account_manager_multi[]" value="<?= htmlspecialchars($n) ?>" placeholder="Enter name" style="flex:1;height:36px;padding:0 10px;border:1px solid #ced4da;border-radius:4px;font-size:13px;">
                            <button type="button" onclick="removeName(this)" style="background:#dc3545;color:white;border:none;width:32px;border-radius:4px;cursor:pointer;font-size:16px;<?= $i===0?'visibility:hidden':''; ?>">×</button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" onclick="addName('acc-mgr-list')" style="background:none;border:1px dashed #94a3b8;color:#64748b;padding:5px 12px;border-radius:4px;font-size:12px;cursor:pointer;margin-top:2px;">+ Add another</button>
                </div>
                <div class="form-group">
                    <label>Account Leader</label>
                    <div id="acc-ldr-list">
                        <?php $al_names = array_filter(array_map('trim', explode(',', $v['account_leader']))); if(empty($al_names)) $al_names = ['']; foreach($al_names as $i => $n): ?>
                        <div class="multi-name-row" style="display:flex;gap:8px;margin-bottom:6px;">
                            <input type="text" name="account_leader_multi[]" value="<?= htmlspecialchars($n) ?>" placeholder="Enter name" style="flex:1;height:36px;padding:0 10px;border:1px solid #ced4da;border-radius:4px;font-size:13px;">
                            <button type="button" onclick="removeName(this)" style="background:#dc3545;color:white;border:none;width:32px;border-radius:4px;cursor:pointer;font-size:16px;<?= $i===0?'visibility:hidden':''; ?>">×</button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" onclick="addName('acc-ldr-list')" style="background:none;border:1px dashed #94a3b8;color:#64748b;padding:5px 12px;border-radius:4px;font-size:12px;cursor:pointer;margin-top:2px;">+ Add another</button>
                </div>
                <div class="form-group">
                    <label>Pre-Sales / SDM</label>
                    <div id="presales-list">
                        <?php $ps_names = array_filter(array_map('trim', explode(',', $v['presales_sdm']))); if(empty($ps_names)) $ps_names = ['']; foreach($ps_names as $i => $n): ?>
                        <div class="multi-name-row" style="display:flex;gap:8px;margin-bottom:6px;">
                            <input type="text" name="presales_sdm_multi[]" value="<?= htmlspecialchars($n) ?>" placeholder="Enter name" style="flex:1;height:36px;padding:0 10px;border:1px solid #ced4da;border-radius:4px;font-size:13px;">
                            <button type="button" onclick="removeName(this)" style="background:#dc3545;color:white;border:none;width:32px;border-radius:4px;cursor:pointer;font-size:16px;<?= $i===0?'visibility:hidden':''; ?>">×</button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" onclick="addName('presales-list')" style="background:none;border:1px dashed #94a3b8;color:#64748b;padding:5px 12px;border-radius:4px;font-size:12px;cursor:pointer;margin-top:2px;">+ Add another</button>
                </div>
            </div>
        </div>

        <script>
        function addName(listId) {
            const list = document.getElementById(listId);
            const div = document.createElement('div');
            div.className = 'multi-name-row';
            div.style.cssText = 'display:flex;gap:8px;margin-bottom:6px;';
            const nameMap = { 'acc-mgr-list':'account_manager_multi[]', 'acc-ldr-list':'account_leader_multi[]', 'presales-list':'presales_sdm_multi[]' };
            div.innerHTML = `<input type="text" name="${nameMap[listId]}" placeholder="Enter name" style="flex:1;height:36px;padding:0 10px;border:1px solid #ced4da;border-radius:4px;font-size:13px;"><button type="button" onclick="removeName(this)" style="background:#dc3545;color:white;border:none;width:32px;border-radius:4px;cursor:pointer;font-size:16px;">×</button>`;
            list.appendChild(div);
            div.querySelector('input').focus();
        }
        function removeName(btn) {
            const row = btn.closest('.multi-name-row');
            const list = row.parentElement;
            row.remove();
            // Ensure first row delete button is hidden
            const rows = list.querySelectorAll('.multi-name-row');
            if (rows.length > 0) {
                rows[0].querySelector('button').style.visibility = rows.length === 1 ? 'hidden' : 'visible';
            }
        }
        </script>

        <div class="actions">
            <button type="submit" class="btn-save"><?= $edit_mode ? 'Update Project' : 'Save Project' ?></button>
            <a href="admin_iips.php" class="btn-cancel">Cancel</a>
        </div>
    </form>
</div>

<script>
function calcGP() {
    // GP is calculated automatically in the IIPS List table — nothing shown in this form
}
</script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://cdn.jsdelivr.net/npm/imask@7/dist/imask.min.js"></script>
<script>
const MONTHS = ["JAN","FEB","MAR","APR","MAY","JUN","JUL","AUG","SEP","OCT","NOV","DEC"];
const MONTHS_SHORT = ["Jan","Feb","Mar","Apr","May","Jun","Jul","Aug","Sep","Oct","Nov","Dec"];

function liveDate(inp) {
    // Just uppercase — parsing happens on blur
    inp.value = inp.value.toUpperCase();
}

function syncDate(prefix) {
    const val = document.getElementById(prefix+'_val').value;
    const display = document.getElementById(prefix+'_display');
    if (val) {
        const parts = val.split('-');
        if (parts.length === 3) {
            display.value = parts[2] + '-' + MONTHS_SHORT[parseInt(parts[1],10)-1].toUpperCase() + '-' + parts[0];
        }
    } else {
        display.value = '';
    }
    validateDateOrder();
}

function validateDateOrder() {
    const sd  = document.getElementById('tsd_val').value;
    const ed  = document.getElementById('ted_val').value;
    const bd  = document.getElementById('tbd_val').value;

    const tedDisplay = document.getElementById('ted_display');
    const tbdDisplay = document.getElementById('tbd_display');

    // Target End must be >= Target Start
    if (sd && ed && ed < sd) {
        tedDisplay.style.borderColor = '#dc3545';
        tedDisplay.style.background  = '#fff5f5';
        showDateError('ted', '⚠ End Date must be on or after Start Date');
    } else {
        tedDisplay.style.borderColor = '';
        tedDisplay.style.background  = '';
        clearDateError('ted');
    }

    // Target Billing must be >= Target End
    if (ed && bd && bd < ed) {
        tbdDisplay.style.borderColor = '#dc3545';
        tbdDisplay.style.background  = '#fff5f5';
        showDateError('tbd', '⚠ Billing Date must be on or after End Date');
    } else {
        tbdDisplay.style.borderColor = '';
        tbdDisplay.style.background  = '';
        clearDateError('tbd');
    }
}

function showDateError(prefix, msg) {
    let err = document.getElementById('err-' + prefix);
    if (!err) {
        err = document.createElement('div');
        err.id = 'err-' + prefix;
        err.style.cssText = 'color:#dc3545;font-size:11px;margin-top:4px;font-weight:600;';
        const field = document.getElementById(prefix + '_val');
        field.parentNode.parentNode.appendChild(err);
    }
    err.textContent = msg;
}

function clearDateError(prefix) {
    const err = document.getElementById('err-' + prefix);
    if (err) err.textContent = '';
}

function parseDateInput(str) {
    str = str.trim().toUpperCase();
    if (!str) return '';
    const parts = str.split(/[\/\-\. ]+/);
    if (parts.length === 3) {
        let d = parts[0], m = parts[1], y = parts[2];
        if (d.length === 1) d = '0'+d;
        if (y.length === 2) y = '20'+y;
        if (isNaN(m)) {
            const mIdx = MONTHS.findIndex(x => m.startsWith(x.substring(0,3)));
            if (mIdx !== -1) m = String(mIdx+1).padStart(2,'0');
        } else {
            m = String(m).padStart(2,'0');
        }
        if (d.length===2 && m.length===2 && y.length===4) return y+'-'+m+'-'+d;
    }
    return '';
}

function bindDateField(prefix) {
    const display = document.getElementById(prefix+'_display');
    const hidden  = document.getElementById(prefix+'_val');

    display.addEventListener('input', function() {
        this.value = this.value.toUpperCase();
    });

    display.addEventListener('blur', function() {
        const parsed = parseDateInput(this.value);
        if (parsed) {
            hidden.value = parsed;
            syncDate(prefix);
        } else if (!hidden.value) {
            this.value = '';
        } else {
            syncDate(prefix);
        }
    });
    display.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') { e.preventDefault(); this.blur(); }
    });
}

document.addEventListener('DOMContentLoaded', function() {
    ['tbd','tsd','ted'].forEach(function(p) {
        syncDate(p);
        bindDateField(p);
    });

    document.querySelector('form').addEventListener('submit', function(e) {
        const sd = document.getElementById('tsd_val').value;
        const ed = document.getElementById('ted_val').value;
        const bd = document.getElementById('tbd_val').value;
        if (sd && ed && ed < sd) {
            e.preventDefault();
            showDateError('ted', '⚠ End Date must be on or after Start Date');
            document.getElementById('ted_display').scrollIntoView({behavior:'smooth', block:'center'});
            return;
        }
        if (ed && bd && bd < ed) {
            e.preventDefault();
            showDateError('tbd', '⚠ Billing Date must be on or after End Date');
            document.getElementById('tbd_display').scrollIntoView({behavior:'smooth', block:'center'});
            return;
        }
    });
});
</script>
</body>
</html>