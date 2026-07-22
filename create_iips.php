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
$view_mode = isset($_GET['view']) && $_GET['view'] == 1;
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$view_mode) {
    $old_pid   = trim($_POST['old_project_id'] ?? '');
    $input_pid = trim($_POST['project_id'] ?? '');
    $p_id = empty($input_pid) ? (!empty($old_pid) ? $old_pid : ('NA-'.strtoupper(uniqid()))) : $input_pid;
    
    $p_name    = trim($_POST['project_name']);
    $c_name    = trim($_POST['customer_name']);

    $selling   = strlen(trim($_POST['selling_price'] ?? '')) > 0 ? floatval($_POST['selling_price']) : null;
    $partner   = strlen(trim($_POST['partner_cost']  ?? '')) > 0 ? floatval($_POST['partner_cost'])  : null;
    $internal  = strlen(trim($_POST['internal_cost'] ?? '')) > 0 ? floatval($_POST['internal_cost']) : null;
    $gross     = ($selling !== null && $partner !== null) ? $selling - $partner : null;
    $has_pm = isset($_POST['has_project_mgmt']) ? 1 : 0;

    $accrued = strlen(trim($_POST['accrued'] ?? '')) > 0 ? floatval($_POST['accrued']) : null;
    $remarks_status  = trim($_POST['remarks_status'] ?? '');

    $tgt_md    = strlen(trim($_POST['target_mandays']      ?? '')) > 0 ? floatval($_POST['target_mandays'])       : null;
    $tgt_sd    = !empty($_POST['target_start_date'])   ? $_POST['target_start_date']   : null;
    $tgt_ed    = !empty($_POST['target_end_date'])     ? $_POST['target_end_date']     : null;
    $tgt_bd    = !empty($_POST['target_billing_date']) ? $_POST['target_billing_date'] : null;

    $iips_stat = $_POST['iips_status']    ?? '';
    $bill_stat = $_POST['billing_status'] ?? '';
    $bill_on   = !empty($_POST['billing_on']) ? $_POST['billing_on'] : null;

    $acc_mgr   = implode(', ', array_filter(array_map('trim', $_POST['account_manager_multi']  ?? [])));
    $acc_ldr   = implode(', ', array_filter(array_map('trim', $_POST['account_leader_multi']   ?? [])));
    $presales  = implode(', ', array_filter(array_map('trim', $_POST['presales_sdm_multi']     ?? [])));
    $proj_mgr  = implode(', ', array_filter(array_map('trim', $_POST['project_manager_multi']  ?? [])));

    if (empty($p_name)) $errors['project_name'] = "⚠ IIPS Name is required.";
    if (empty($c_name)) $errors['customer_name'] = "⚠ Customer Name is required.";
    if ($selling !== null && $partner === null && $internal === null) {
        $errors['cost'] = "⚠ Please fill in either Partner Cost or Internal Cost (or both).";
    }

    if ($tgt_sd && $tgt_ed && $tgt_ed < $tgt_sd)  $errors['ted'] = "⚠ Target End Date must be on or after Target Start Date.";

    if (!$edit_mode && empty($errors)) {
        $chk_id = $conn->prepare("SELECT project_id FROM projects WHERE project_id = ?");
        $chk_id->bind_param("s", $p_id);
        $chk_id->execute();
        if ($chk_id->get_result()->num_rows > 0) {
            $errors['project_id'] = "⚠ IIPS ID (".$p_id.") already exists. Please use a different ID.";
        }
        $chk_id->close();
    }

    if (empty($errors)) {
        if ($edit_mode && !empty($old_pid)) {
            $conn->begin_transaction();
            try {
                $conn->query("SET FOREIGN_KEY_CHECKS=0");

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

                $chk = $conn->prepare("SELECT id FROM iips_tracking WHERE project_id=?");
                $chk->bind_param("s", $p_id); $chk->execute();
                $exists = $chk->get_result()->num_rows > 0; $chk->close();

                if ($exists) {
                    $upd = $conn->prepare("UPDATE iips_tracking SET selling_price=?,partner_cost=?,internal_cost=?,gross_profit=?,has_project_mgmt=?,target_mandays=?,target_start_date=?,target_end_date=?,target_billing_date=?,iips_status=?,billing_status=?,billing_on=?,accrued=?,remarks_status=?,account_manager=?,account_leader=?,presales_sdm=?,project_manager=? WHERE project_id=?");
                    $upd->bind_param("ddddidssssssdssssss", $selling,$partner,$internal,$gross,$has_pm,$tgt_md,$tgt_sd,$tgt_ed,$tgt_bd,$iips_stat,$bill_stat,$bill_on,$accrued,$remarks_status,$acc_mgr,$acc_ldr,$presales,$proj_mgr,$p_id);
                    $upd->execute(); $upd->close();
                } else {
                    $ins = $conn->prepare("INSERT INTO iips_tracking (project_id,selling_price,partner_cost,internal_cost,gross_profit,has_project_mgmt,target_mandays,target_start_date,target_end_date,target_billing_date,iips_status,billing_status,billing_on,accrued,remarks_status,account_manager,account_leader,presales_sdm,project_manager) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                    $ins->bind_param("sddddidssssssdsssss", $p_id,$selling,$partner,$internal,$gross,$has_pm,$tgt_md,$tgt_sd,$tgt_ed,$tgt_bd,$iips_stat,$bill_stat,$bill_on,$accrued,$remarks_status,$acc_mgr,$acc_ldr,$presales,$proj_mgr);
                    $ins->execute(); $ins->close();
                }
                $conn->commit();
                header("Location: admin_iips.php"); exit;
            } catch(Exception $e) {
                $conn->rollback();
                $errors['general'] = "⚠ Database error: ".$e->getMessage();
            }
        } else {
            $est_time = 0;
            $pricing = null;
            $s = $conn->prepare("INSERT INTO projects (project_id,project_name,customer_name,estimate_time,pricing) VALUES (?,?,?,?,?)");
            $s->bind_param("sssid", $p_id,$p_name,$c_name,$est_time,$pricing); $s->execute(); $s->close();
            $ins = $conn->prepare("INSERT INTO iips_tracking (project_id,selling_price,partner_cost,internal_cost,gross_profit,has_project_mgmt,target_mandays,target_start_date,target_end_date,target_billing_date,iips_status,billing_status,billing_on,accrued,remarks_status,account_manager,account_leader,presales_sdm,project_manager) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $ins->bind_param("sddddidssssssdsssss", $p_id,$selling,$partner,$internal,$gross,$has_pm,$tgt_md,$tgt_sd,$tgt_ed,$tgt_bd,$iips_stat,$bill_stat,$bill_on,$accrued,$remarks_status,$acc_mgr,$acc_ldr,$presales,$proj_mgr);
            $ins->execute(); $ins->close();
            header("Location: admin_iips.php"); exit;
        }
    }
}

$v = [
    'project_id'          => $_POST['project_id']          ?? ($edit_data['project_id']    ?? ''),
    'project_name'        => $_POST['project_name']        ?? ($edit_data['project_name']  ?? ''),
    'customer_name'       => $_POST['customer_name']       ?? ($edit_data['customer_name'] ?? ''),
    'selling_price'       => $_POST['selling_price']       ?? ($iips_data['selling_price']  ?? ''),
    'partner_cost'        => $_POST['partner_cost']        ?? ($iips_data['partner_cost']   ?? ''),
    'internal_cost'       => $_POST['internal_cost']       ?? ($iips_data['internal_cost']  ?? ''),
    'target_mandays'      => $_POST['target_mandays']      ?? ($iips_data['target_mandays']      ?? ''),
    'target_start_date'   => $_POST['target_start_date']   ?? ($iips_data['target_start_date']   ?? ''),
    'target_end_date'     => $_POST['target_end_date']     ?? ($iips_data['target_end_date']     ?? ''),
    'target_billing_date' => $_POST['target_billing_date'] ?? ($iips_data['target_billing_date'] ?? ''),
    'iips_status'         => $_POST['iips_status']         ?? ($edit_mode||$view_mode ? ($iips_data['iips_status']    ?? '') : ''),
    'billing_status'      => $_POST['billing_status']      ?? ($edit_mode||$view_mode ? ($iips_data['billing_status'] ?? '') : ''),
    'billing_on'          => $_POST['billing_on']          ?? ($iips_data['billing_on'] ?? ''),
    'accrued'             => $_POST['accrued']             ?? ($iips_data['accrued'] ?? ''),
    'remarks_status'      => $_POST['remarks_status']      ?? ($iips_data['remarks_status'] ?? ''),
    'account_manager'     => $_SERVER['REQUEST_METHOD']==='POST' && !$view_mode ? implode(', ', array_filter(array_map('trim', $_POST['account_manager_multi'] ?? []))) : ($iips_data['account_manager'] ?? ''),
    'account_leader'      => $_SERVER['REQUEST_METHOD']==='POST' && !$view_mode ? implode(', ', array_filter(array_map('trim', $_POST['account_leader_multi']  ?? []))) : ($iips_data['account_leader']  ?? ''),
    'presales_sdm'        => $_SERVER['REQUEST_METHOD']==='POST' && !$view_mode ? implode(', ', array_filter(array_map('trim', $_POST['presales_sdm_multi']    ?? []))) : ($iips_data['presales_sdm']    ?? ''),
    'has_project_mgmt' => ($_SERVER['REQUEST_METHOD'] === 'POST') ? (isset($_POST['has_project_mgmt']) ? 1 : 0) : ($iips_data['has_project_mgmt'] ?? 0),
];
$dAttr = $view_mode ? 'disabled' : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title><?= $view_mode ? 'View Details' : ($edit_mode ? 'Edit IIPS' : 'Create IIPS') ?> — IIPS</title>
<style>
* { box-sizing: border-box; }
body { font-family: Arial, sans-serif; margin: 30px; background: #f4f7f6; }

.topbar { position: sticky; top: 0; z-index: 500; background: #343a40; padding: 15px 20px; display: flex; align-items: center; justify-content: space-between; border-radius: 8px; flex-wrap: wrap; gap: 10px; }
.topbar h2 { color: white; margin: 0; font-size: 18px; }
.topbar a { color: #ffc107; font-weight: bold; text-decoration: none; font-size: 13px; padding: 6px 12px; border-radius: 4px; }

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
.form-group input, .form-group select, .form-group textarea {
    height: 38px; padding: 0 10px; border: 1px solid #ced4da; border-radius: 4px; font-size: 13px; width: 100%;
}
.form-group textarea { resize: vertical; padding: 8px 10px; min-height: 38px; font-family: Arial, sans-serif; }
.form-group input:disabled, .form-group select:disabled, .form-group textarea:disabled { background: #f8f9fa; color: #495057; cursor: not-allowed; border-color: #e9ecef; }
.form-group input.err, .form-group select.err, .form-group textarea.err { border-color: #dc2626; background: #fff5f5; }
.form-group input:focus:not(:disabled), .form-group select:focus:not(:disabled), .form-group textarea:focus:not(:disabled) { border-color: #007bff; outline: none; box-shadow: 0 0 0 2px rgba(0,123,255,.15); }

input[type="number"]::-webkit-outer-spin-button,
input[type="number"]::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
input[type="number"] { -moz-appearance: textfield; appearance: textfield; }

.check-group { display: flex; align-items: center; gap: 10px; padding-top: 8px; }
.check-group input[type="checkbox"] { width: 18px; height: 18px; cursor: pointer; }
.check-group label { font-size: 13px; font-weight: 600; color: #333; margin: 0; cursor: pointer; }

.iips-cal-btn { position: absolute; right: 0; top: 0; width: 32px; height: 100%; display: flex; align-items: center; justify-content: center; font-size: 13px; cursor: pointer; z-index: 4; }

.actions { display: flex; gap: 12px; margin-top: 4px; align-items: center; }
.btn-save { display: inline-flex; justify-content: center; align-items: center; background: #28a745; color: white; border: none; padding: 0 28px; height: 40px; border-radius: 4px; font-size: 14px; font-weight: bold; cursor: pointer; text-decoration: none; }
.btn-save:hover { background: #218838; }
.btn-edit-mode { background: #ffc107; color: #212529; }
.btn-edit-mode:hover { background: #e0a800; }
.btn-cancel { display: inline-flex; align-items: center; color: #6c757d; text-decoration: none; font-size: 13px; height: 40px; }

@media (max-width: 600px) {
    body { margin: 10px; }
    .section-body, .section-body.three-col { grid-template-columns: 1fr; }
}
</style>
</head>
<body>

<div class="topbar">
    <h2><?= $view_mode ? '👁️ View Details' : ($edit_mode ? '✏️ Edit Project' : '+ Create New IIPS') ?></h2>
    <a href="admin_iips.php">← Back to IIPS List</a>
</div>

<div class="page">
    <?php if (isset($errors['general'])): ?>
        <div style="background:#fef2f2; border:1px solid #fca5a5; border-radius:6px; padding:12px 16px; margin-bottom:20px; font-size:13px; font-weight:700; color:#991b1b;">
            <?= htmlspecialchars($errors['general']) ?>
        </div>
    <?php endif; ?>

    <form method="POST" id="iips-form">
        <input type="hidden" name="old_project_id" value="<?= htmlspecialchars($edit_data['project_id'] ?? '') ?>">

        <div class="section">
            <div class="section-hdr">📁 IIPS Details</div>
            <div class="section-body three-col">
                <div class="form-group">
                    <label>IIPS ID</label>
                    <input type="text" name="project_id" id="project_id" value="<?= htmlspecialchars(strpos($v['project_id'], 'NA-') === 0 ? '' : $v['project_id']) ?>" placeholder="e.g. SO-0000123" class="<?= isset($errors['project_id']) ? 'err' : '' ?>" oninput="clearErr('project_id')" <?= $dAttr ?>>
                    <?php if (isset($errors['project_id'])): ?><div id="err-project_id" style="color:#dc3545;font-size:11px;margin-top:4px;font-weight:600;"><?= $errors['project_id'] ?></div><?php endif; ?>
                </div>
                <div class="form-group">
                    <label>IIPS Name <?= !$view_mode?'<span class="req">*</span>':'' ?></label>
                    <input type="text" name="project_name" id="project_name" value="<?= htmlspecialchars($v['project_name']) ?>" class="<?= isset($errors['project_name']) ? 'err' : '' ?>" oninput="clearErr('project_name')" <?= $dAttr ?>>
                    <?php if (isset($errors['project_name'])): ?><div id="err-project_name" style="color:#dc3545;font-size:11px;margin-top:4px;font-weight:600;"><?= $errors['project_name'] ?></div><?php endif; ?>
                </div>
                <div class="form-group">
                    <label>Customer Name <?= !$view_mode?'<span class="req">*</span>':'' ?></label>
                    <input type="text" name="customer_name" id="customer_name" value="<?= htmlspecialchars($v['customer_name']) ?>" class="<?= isset($errors['customer_name']) ? 'err' : '' ?>" oninput="clearErr('customer_name')" <?= $dAttr ?>>
                    <?php if (isset($errors['customer_name'])): ?><div id="err-customer_name" style="color:#dc3545;font-size:11px;margin-top:4px;font-weight:600;"><?= $errors['customer_name'] ?></div><?php endif; ?>
                </div>
            </div>
        </div>

        <div class="section">
            <div class="section-hdr green">💰 IIPS Costing</div>
            <div class="section-body three-col">
                <div class="form-group">
                    <label>Selling Price (RM)</label>
                    <input type="number" name="selling_price" id="sp" step="0.01" min="0" value="<?= htmlspecialchars($v['selling_price']) ?>" oninput="clearCostErr()" <?= $dAttr ?>>
                </div>
                <div class="form-group">
                    <label>Partner Cost (RM)</label>
                    <input type="number" name="partner_cost" id="pc" step="0.01" min="0" value="<?= htmlspecialchars($v['partner_cost']) ?>" oninput="clearCostErr()" class="<?= isset($errors['cost']) ? 'err' : '' ?>" <?= $dAttr ?>>
                </div>
                <div class="form-group">
                    <label>Internal Cost (RM)</label>
                    <input type="number" name="internal_cost" id="ic" step="0.01" min="0" value="<?= htmlspecialchars($v['internal_cost']) ?>" oninput="clearCostErr()" class="<?= isset($errors['cost']) ? 'err' : '' ?>" <?= $dAttr ?>>
                    <?php if (isset($errors['cost'])): ?><div id="err-cost" style="color:#dc3545;font-size:11px;margin-top:4px;font-weight:600;"><?= $errors['cost'] ?></div><?php endif; ?>
                </div>
                <div class="check-group" style="align-self:center; padding-top:20px;">
                    <input type="checkbox" name="has_project_mgmt" id="has_pm" <?= $v['has_project_mgmt'] ? 'checked' : '' ?> <?= $dAttr ?>>
                    <label for="has_pm">Project Management Included</label>
                </div>
            </div>
        </div>

        <div class="section">
            <div class="section-hdr blue">📅 IIPS Timeline — Target</div>
            <div class="section-body">
                <div class="form-group">
                    <label>Target Man-Days (days)</label>
                    <input type="number" name="target_mandays" step="0.5" min="0" value="<?= htmlspecialchars($v['target_mandays']) ?>" <?= $dAttr ?>>
                </div>
                <div class="form-group">
                    <label>Target Billing Date</label>
                    <div style="position:relative; height:38px; width:100%; display:flex;">
                        <input type="text" id="tbd_display" placeholder="DD MMM YYYY" oninput="liveDate(this)" style="flex:1;height:100%;padding:8px 36px 8px 10px;border:1px solid <?= $view_mode ? '#e9ecef' : '#ced4da' ?>;border-radius:4px;font-size:13px;text-transform:uppercase;" autocomplete="off" value="<?= htmlspecialchars(fmtDateDisplay($v['target_billing_date'])) ?>" class="<?= isset($errors['tbd']) ? 'err' : '' ?>" <?= $dAttr ?>>
                        <?php if(!$view_mode): ?><div style="position:absolute;right:0;top:0;width:36px;height:100%;display:flex;align-items:center;justify-content:center;cursor:pointer;z-index:5;" onclick="document.getElementById('tbd_val').showPicker()">📅</div><?php endif; ?>
                        <input type="date" name="target_billing_date" id="tbd_val" value="<?= htmlspecialchars($v['target_billing_date']) ?>" style="position:absolute;top:0;right:0;width:36px;height:100%;opacity:0;z-index:5;<?= $view_mode ? 'display:none;' : 'cursor:pointer;' ?>" onchange="syncDate('tbd')" <?= $dAttr ?>>
                    </div>
                    <?php if (isset($errors['tbd'])): ?><div id="err-tbd" style="color:#dc3545;font-size:11px;margin-top:4px;font-weight:600;"><?= $errors['tbd'] ?></div><?php endif; ?>
                </div>
                <div class="form-group">
                    <label>Target Start Date</label>
                    <div style="position:relative; height:38px; width:100%; display:flex;">
                        <input type="text" id="tsd_display" placeholder="DD MMM YYYY" oninput="liveDate(this)" style="flex:1;height:100%;padding:8px 36px 8px 10px;border:1px solid <?= $view_mode ? '#e9ecef' : '#ced4da' ?>;border-radius:4px;font-size:13px;text-transform:uppercase;" autocomplete="off" value="<?= htmlspecialchars(fmtDateDisplay($v['target_start_date'])) ?>" <?= $dAttr ?>>
                        <?php if(!$view_mode): ?><div style="position:absolute;right:0;top:0;width:36px;height:100%;display:flex;align-items:center;justify-content:center;cursor:pointer;z-index:5;" onclick="document.getElementById('tsd_val').showPicker()">📅</div><?php endif; ?>
                        <input type="date" name="target_start_date" id="tsd_val" value="<?= htmlspecialchars($v['target_start_date']) ?>" style="position:absolute;top:0;right:0;width:36px;height:100%;opacity:0;z-index:5;<?= $view_mode ? 'display:none;' : 'cursor:pointer;' ?>" onchange="syncDate('tsd')" <?= $dAttr ?>>
                    </div>
                </div>
                <div class="form-group">
                    <label>Target End Date</label>
                    <div style="position:relative; height:38px; width:100%; display:flex;">
                        <input type="text" id="ted_display" placeholder="DD MMM YYYY" oninput="liveDate(this)" style="flex:1;height:100%;padding:8px 36px 8px 10px;border:1px solid <?= $view_mode ? '#e9ecef' : '#ced4da' ?>;border-radius:4px;font-size:13px;text-transform:uppercase;" autocomplete="off" value="<?= htmlspecialchars(fmtDateDisplay($v['target_end_date'])) ?>" class="<?= isset($errors['ted']) ? 'err' : '' ?>" <?= $dAttr ?>>
                        <?php if(!$view_mode): ?><div style="position:absolute;right:0;top:0;width:36px;height:100%;display:flex;align-items:center;justify-content:center;cursor:pointer;z-index:5;" onclick="document.getElementById('ted_val').showPicker()">📅</div><?php endif; ?>
                        <input type="date" name="target_end_date" id="ted_val" value="<?= htmlspecialchars($v['target_end_date']) ?>" style="position:absolute;top:0;right:0;width:36px;height:100%;opacity:0;z-index:5;<?= $view_mode ? 'display:none;' : 'cursor:pointer;' ?>" onchange="syncDate('ted')" <?= $dAttr ?>>
                    </div>
                    <?php if (isset($errors['ted'])): ?><div id="err-ted" style="color:#dc3545;font-size:11px;margin-top:4px;font-weight:600;"><?= $errors['ted'] ?></div><?php endif; ?>
                </div>
            </div>
        </div>

        <div class="section">
            <div class="section-hdr teal">📊 Status</div>
            <div class="section-body">
                <div class="form-group">
                    <label>IIPS Status</label>
                    <select name="iips_status" <?= $dAttr ?>>
                        <option value="" disabled <?= empty($v['iips_status']) || $v['iips_status']==='Not Quoted' && !$edit_mode ?'selected':'' ?>>-- Select IIPS Status --</option>
                        <?php foreach (['Not Quoted','Quoted','Not Started','In Progress','Completed','Cancelled'] as $o): ?>
                        <option value="<?= $o ?>" <?= $v['iips_status']===$o && ($edit_mode||$view_mode) ?'selected':'' ?>><?= $o ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Billing Status</label>
                    <select name="billing_status" <?= $dAttr ?>>
                        <option value="" disabled <?= empty($v['billing_status']) || $v['billing_status']==='Not Forecasted' && !$edit_mode ?'selected':'' ?>>-- Select Billing Status --</option>
                        <?php foreach (['Not Forecasted','Forecasted','Pending','Completed'] as $o): ?>
                        <option value="<?= $o ?>" <?= $v['billing_status']===$o && ($edit_mode||$view_mode) ?'selected':'' ?>><?= $o ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Billing On (Month/Year)</label>
                    <?php
                    $bo_display = '';
                    if (!empty($v['billing_on'])) {
                        $bdt = DateTime::createFromFormat('Y-m', $v['billing_on']);
                        if ($bdt) $bo_display = strtoupper($bdt->format('M Y'));
                    }
                    ?>
                    <div style="position:relative; height:38px; width:100%; display:flex;">
                        <input type="text" id="bo_display" value="<?= htmlspecialchars($bo_display) ?>" style="flex:1;height:100%;padding:0 32px 0 10px;border:1px solid <?= $view_mode ? '#e9ecef' : '#d1d5db' ?>;border-radius:6px;font-size:13px;text-transform:uppercase;" autocomplete="off" title="Type Month/Year (1/25) or just Year (2025)" <?= $dAttr ?>>
                        <?php if(!$view_mode): ?>
                        <div class="iips-cal-btn" onclick="document.getElementById('bo_picker').showPicker()" title="Select Billing Month">📅</div>
                        <input type="month" id="bo_picker" value="<?= htmlspecialchars($v['billing_on']) ?>" style="position:absolute;top:0;right:0;width:32px;height:100%;opacity:0;cursor:pointer;z-index:4;" title="Select Billing Month">
                        <?php endif; ?>
                        <input type="hidden" name="billing_on" id="bo_val" value="<?= htmlspecialchars($v['billing_on']) ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label>Accrued (RM)</label>
                    <input type="number" name="accrued" id="accrued" step="0.01" min="0" value="<?= htmlspecialchars($v['accrued']) ?>" <?= $dAttr ?>>
                </div>
                <div class="form-group" style="grid-column: span 2;">
                    <label>Remarks Status</label>
                    <textarea name="remarks_status" rows="1" <?= $dAttr ?>><?= htmlspecialchars($v['remarks_status']) ?></textarea>
                </div>
            </div>
        </div>

        <div class="section">
            <div class="section-hdr purple">👥 IIPS Management <span style="font-weight:400; font-size:11px; opacity:.7;">(optional)</span></div>
            <div class="section-body">
                <div class="form-group">
                    <label>Account Manager</label>
                    <div id="acc-mgr-list">
                        <?php $am_names = array_filter(array_map('trim', explode(',', $v['account_manager']))); if(empty($am_names)) $am_names = ['']; foreach($am_names as $i => $n): ?>
                        <div class="multi-name-row" style="display:flex;gap:8px;margin-bottom:6px;">
                            <input type="text" name="account_manager_multi[]" value="<?= htmlspecialchars($n) ?>" placeholder="Enter name" style="flex:1;height:36px;padding:0 10px;border:1px solid <?= $view_mode ? '#e9ecef' : '#ced4da' ?>;border-radius:4px;font-size:13px;" <?= $dAttr ?>>
                            <?php if(!$view_mode): ?><button type="button" onclick="removeName(this)" style="background:#dc3545;color:white;border:none;width:32px;border-radius:4px;cursor:pointer;font-size:16px;<?= $i===0?'visibility:hidden':''; ?>">×</button><?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if(!$view_mode): ?><button type="button" onclick="addName('acc-mgr-list')" style="background:none;border:1px dashed #94a3b8;color:#64748b;padding:5px 12px;border-radius:4px;font-size:12px;cursor:pointer;margin-top:2px;">+ Add another</button><?php endif; ?>
                </div>
                <div class="form-group">
                    <label>Account Leader</label>
                    <div id="acc-ldr-list">
                        <?php $al_names = array_filter(array_map('trim', explode(',', $v['account_leader']))); if(empty($al_names)) $al_names = ['']; foreach($al_names as $i => $n): ?>
                        <div class="multi-name-row" style="display:flex;gap:8px;margin-bottom:6px;">
                            <input type="text" name="account_leader_multi[]" value="<?= htmlspecialchars($n) ?>" placeholder="Enter name" style="flex:1;height:36px;padding:0 10px;border:1px solid <?= $view_mode ? '#e9ecef' : '#ced4da' ?>;border-radius:4px;font-size:13px;" <?= $dAttr ?>>
                            <?php if(!$view_mode): ?><button type="button" onclick="removeName(this)" style="background:#dc3545;color:white;border:none;width:32px;border-radius:4px;cursor:pointer;font-size:16px;<?= $i===0?'visibility:hidden':''; ?>">×</button><?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if(!$view_mode): ?><button type="button" onclick="addName('acc-ldr-list')" style="background:none;border:1px dashed #94a3b8;color:#64748b;padding:5px 12px;border-radius:4px;font-size:12px;cursor:pointer;margin-top:2px;">+ Add another</button><?php endif; ?>
                </div>
                <div class="form-group">
                    <label>Pre-Sales / SDM</label>
                    <div id="presales-list">
                        <?php $ps_names = array_filter(array_map('trim', explode(',', $v['presales_sdm']))); if(empty($ps_names)) $ps_names = ['']; foreach($ps_names as $i => $n): ?>
                        <div class="multi-name-row" style="display:flex;gap:8px;margin-bottom:6px;">
                            <input type="text" name="presales_sdm_multi[]" value="<?= htmlspecialchars($n) ?>" placeholder="Enter name" style="flex:1;height:36px;padding:0 10px;border:1px solid <?= $view_mode ? '#e9ecef' : '#ced4da' ?>;border-radius:4px;font-size:13px;" <?= $dAttr ?>>
                            <?php if(!$view_mode): ?><button type="button" onclick="removeName(this)" style="background:#dc3545;color:white;border:none;width:32px;border-radius:4px;cursor:pointer;font-size:16px;<?= $i===0?'visibility:hidden':''; ?>">×</button><?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if(!$view_mode): ?><button type="button" onclick="addName('presales-list')" style="background:none;border:1px dashed #94a3b8;color:#64748b;padding:5px 12px;border-radius:4px;font-size:12px;cursor:pointer;margin-top:2px;">+ Add another</button><?php endif; ?>
                </div>
            </div>
        </div>

        <?php if (!$view_mode): ?>
        <script>
        function addName(listId) {
            const list = document.getElementById(listId);
            const div = document.createElement('div');
            div.className = 'multi-name-row';
            div.style.cssText = 'display:flex;gap:8px;margin-bottom:6px;';
            const nameMap = { 
                'acc-mgr-list': 'account_manager_multi[]', 
                'acc-ldr-list': 'account_leader_multi[]', 
                'presales-list': 'presales_sdm_multi[]',
                'project-mgr-list': 'project_manager_multi[]'
            };
            div.innerHTML = `<input type="text" name="${nameMap[listId]}" placeholder="Enter name" style="flex:1;height:36px;padding:0 10px;border:1px solid #ced4da;border-radius:4px;font-size:13px;"><button type="button" onclick="removeName(this)" style="background:#dc3545;color:white;border:none;width:32px;border-radius:4px;cursor:pointer;font-size:16px;">×</button>`;
            list.appendChild(div);
            div.querySelector('input').focus();
        }
        function removeName(btn) {
            const row = btn.closest('.multi-name-row');
            const list = row.parentElement;
            row.remove();
            const rows = list.querySelectorAll('.multi-name-row');
            if (rows.length > 0) {
                rows[0].querySelector('button').style.visibility = rows.length === 1 ? 'hidden' : 'visible';
            }
        }
        </script>
        <?php endif; ?>

        <div class="actions">
            <?php if ($view_mode): ?>
                <a href="create_iips.php?edit=<?= urlencode($v['project_id']) ?>" class="btn-save btn-edit-mode">Edit Project</a>
            <?php else: ?>
                <button type="submit" class="btn-save"><?= $edit_mode ? 'Update Project' : 'Save Project' ?></button>
            <?php endif; ?>
            <a href="admin_iips.php" class="btn-cancel"><?= $view_mode ? 'Back to List' : 'Cancel' ?></a>
        </div>
    </form>
</div>

<?php if (!$view_mode): ?>
<script>
function clearErr(id) {
    const el = document.getElementById(id);
    if(el) { el.classList.remove('err'); el.style.background = ''; }
    const errNode = document.getElementById('err-' + id);
    if (errNode) errNode.remove();
}

function triggerErr(id, msg) {
    const el = document.getElementById(id);
    if(el) {
        el.classList.add('err');
        let errNode = document.getElementById('err-' + id);
        if (!errNode) {
            errNode = document.createElement('div');
            errNode.id = 'err-' + id;
            errNode.style.cssText = 'color:#dc3545;font-size:11px;margin-top:4px;font-weight:600;';
            el.parentNode.appendChild(errNode);
        }
        errNode.textContent = msg;
        return el;
    }
    return null;
}

function clearCostErr() {
    const sp = document.getElementById('sp');
    const pc = document.getElementById('pc');
    const ic = document.getElementById('ic');
    if (sp.value.trim() === '' || pc.value.trim() !== '' || ic.value.trim() !== '') {
        pc.classList.remove('err');
        ic.classList.remove('err');
        const err = document.getElementById('err-cost');
        if (err) err.remove();
    }
}

const MONTHS = ["JAN","FEB","MAR","APR","MAY","JUN","JUL","AUG","SEP","OCT","NOV","DEC"];
const MONTHS_SHORT = ["Jan","Feb","Mar","Apr","May","Jun","Jul","Aug","Sep","Oct","Nov","Dec"];

function liveDate(inp) { inp.value = inp.value.toUpperCase(); }

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
    const tedDisplay = document.getElementById('ted_display');
    if (sd && ed && ed < sd) {
        tedDisplay.style.borderColor = '#dc3545';
        tedDisplay.style.background  = '#fff5f5';
        showDateError('ted', '⚠ End Date must be on or after Start Date');
    } else {
        tedDisplay.style.borderColor = '';
        tedDisplay.style.background  = '';
        clearDateError('ted');
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
        } else { m = String(m).padStart(2,'0'); }
        if (d.length===2 && m.length===2 && y.length===4) return y+'-'+m+'-'+d;
    }
    return '';
}

function bindDateField(prefix) {
    const display = document.getElementById(prefix+'_display');
    const hidden  = document.getElementById(prefix+'_val');
    display.addEventListener('input', function() { this.value = this.value.toUpperCase(); });
    display.addEventListener('blur', function() {
        if (this.value.trim() === '') {
            hidden.value = '';
            syncDate(prefix);
        } else {
            const parsed = parseDateInput(this.value);
            if (parsed) hidden.value = parsed;
            syncDate(prefix);
        }
    });
    display.addEventListener('keydown', function(e) { if (e.key === 'Enter') { e.preventDefault(); this.blur(); } });
}

function parseBillingOn(str) {
    str = str.trim().toUpperCase();
    if (!str) return null;

    const mNames = ["JAN","FEB","MAR","APR","MAY","JUN","JUL","AUG","SEP","OCT","NOV","DEC"];
    let m = null, y = null;

    const numMatch = str.match(/^(\d{1,2})[\/\-\s]+(\d{2,4})$/);
    if (numMatch) {
        m = parseInt(numMatch[1], 10);
        y = numMatch[2];
    } else {
        const textMatch = str.match(/^([A-Z]{3,})[\/\-\s]*(\d{2,4})$/);
        if (textMatch) {
            const mStr = textMatch[1].substring(0,3);
            m = mNames.indexOf(mStr) + 1;
            y = textMatch[2];
        }
    }

    if (m && m >= 1 && m <= 12 && y) {
        if (y.length === 2) y = "20" + y;
        return { 
            val: y + "-" + String(m).padStart(2, '0'), 
            disp: mNames[m-1] + " " + y 
        };
    }
    return null;
}

document.addEventListener('DOMContentLoaded', function() {
    ['tbd','tsd','ted'].forEach(function(p) { syncDate(p); bindDateField(p); });

    const boDisp = document.getElementById('bo_display');
    const boVal = document.getElementById('bo_val');
    const boPicker = document.getElementById('bo_picker');

    if (boDisp && boVal) {
        boDisp.addEventListener('blur', function() {
            if (this.value.trim() === '') {
                boVal.value = '';
                this.value = '';
            } else {
                const parsed = parseBillingOn(this.value);
                if (parsed) {
                    boVal.value = parsed.val;
                    this.value = parsed.disp;
                } else if (!boVal.value) {
                    this.value = '';
                }
            }
        });
        boDisp.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') { e.preventDefault(); this.blur(); }
        });
    }

    if (boPicker && boDisp && boVal) {
        boPicker.addEventListener('change', function() {
            if (this.value) {
                const parts = this.value.split('-');
                const mNames = ["JAN","FEB","MAR","APR","MAY","JUN","JUL","AUG","SEP","OCT","NOV","DEC"];
                boVal.value = this.value;
                boDisp.value = mNames[parseInt(parts[1], 10) - 1] + " " + parts[0];
            } else {
                boVal.value = '';
                boDisp.value = '';
            }
        });
    }

    document.getElementById('iips-form').addEventListener('submit', function(e) {
        let firstErr = null;
        const pName = document.getElementById('project_name');
        if (pName.value.trim() === '') {
            e.preventDefault();
            const el = triggerErr('project_name', '⚠ IIPS Name is required.');
            if (!firstErr) firstErr = el;
        }

        const cName = document.getElementById('customer_name');
        if (cName.value.trim() === '') {
            e.preventDefault();
            const el = triggerErr('customer_name', '⚠ Customer Name is required.');
            if (!firstErr) firstErr = el;
        }

        const sp = document.getElementById('sp');
        const pc = document.getElementById('pc');
        const ic = document.getElementById('ic');
        if (sp.value.trim() !== '' && pc.value.trim() === '' && ic.value.trim() === '') {
            e.preventDefault();
            pc.classList.add('err');
            ic.classList.add('err');
            let errNode = document.getElementById('err-cost');
            if (!errNode) {
                errNode = document.createElement('div');
                errNode.id = 'err-cost';
                errNode.style.cssText = 'color:#dc3545;font-size:11px;margin-top:4px;font-weight:600;';
                ic.parentNode.appendChild(errNode);
            }
            errNode.textContent = '⚠ Please fill in either Partner Cost or Internal Cost (or both).';
            if (!firstErr) firstErr = pc;
        }

        const sd = document.getElementById('tsd_val').value;
        const ed = document.getElementById('ted_val').value;
        if (sd && ed && ed < sd) {
            e.preventDefault();
            showDateError('ted', '⚠ End Date must be on or after Start Date');
            document.getElementById('ted_display').classList.add('err');
            if (!firstErr) firstErr = document.getElementById('ted_display');
        }

        if (firstErr) {
            firstErr.scrollIntoView({behavior:'smooth', block:'center'});
        }
    });
});
</script>
<?php endif; ?>
</body>
</html>