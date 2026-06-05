<?php
require_once 'config.php';

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
    $acc_mgr   = trim($_POST['account_manager']  ?? '');
    $acc_ldr   = trim($_POST['account_leader']   ?? '');
    $presales  = trim($_POST['presales_sdm']     ?? '');
    $proj_mgr  = trim($_POST['project_manager']  ?? '');

    // ── Validation ────────────────────────────────────────────────────────────
    if (empty($p_name))    $errors[] = "Project Name is required.";
    if (empty($c_name))    $errors[] = "Customer Name is required.";
    if ($selling === null) $errors[] = "Selling Price is required.";
    if ($partner === null) $errors[] = "Partner Cost is required.";
    if ($tgt_md  === null) $errors[] = "Target Man-Days is required.";
    if (!$tgt_sd)          $errors[] = "Target Start Date is required.";
    if (!$tgt_ed)          $errors[] = "Target End Date is required.";
    if (!$tgt_bd)          $errors[] = "Target Billing Date is required.";
    if (empty($acc_mgr))   $errors[] = "Account Manager is required.";
    if (empty($acc_ldr))   $errors[] = "Account Leader is required.";
    if (empty($presales))  $errors[] = "Pre-Sales / SDM is required.";
    if (empty($proj_mgr))  $errors[] = "Project Manager is required.";

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
                    $upd = $conn->prepare("UPDATE iips_tracking SET selling_price=?,partner_cost=?,gross_profit=?,has_project_mgmt=?,target_mandays=?,target_start_date=?,target_end_date=?,target_billing_date=?,iips_status=?,billing_status=?,account_manager=?,account_leader=?,presales_sdm=?,project_manager=? WHERE project_id=?");
                    $upd->bind_param("dddidssssssssss", $selling,$partner,$gross,$has_pm,$tgt_md,$tgt_sd,$tgt_ed,$tgt_bd,$iips_stat,$bill_stat,$acc_mgr,$acc_ldr,$presales,$proj_mgr,$p_id);
                    $upd->execute(); $upd->close();
                } else {
                    $ins = $conn->prepare("INSERT INTO iips_tracking (project_id,selling_price,partner_cost,gross_profit,has_project_mgmt,target_mandays,target_start_date,target_end_date,target_billing_date,iips_status,billing_status,account_manager,account_leader,presales_sdm,project_manager) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                    $ins->bind_param("sdddidssssssssss", $p_id,$selling,$partner,$gross,$has_pm,$tgt_md,$tgt_sd,$tgt_ed,$tgt_bd,$iips_stat,$bill_stat,$acc_mgr,$acc_ldr,$presales,$proj_mgr);
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
            $s = $conn->prepare("INSERT INTO projects (project_id,project_name,customer_name,estimate_time,pricing) VALUES (?,?,?,?,?)");
            $s->bind_param("sssid", $p_id,$p_name,$c_name,0,null); $s->execute(); $s->close();
            $ins = $conn->prepare("INSERT INTO iips_tracking (project_id,selling_price,partner_cost,gross_profit,has_project_mgmt,target_mandays,target_start_date,target_end_date,target_billing_date,iips_status,billing_status,account_manager,account_leader,presales_sdm,project_manager) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $ins->bind_param("sdddidssssssssss", $p_id,$selling,$partner,$gross,$has_pm,$tgt_md,$tgt_sd,$tgt_ed,$tgt_bd,$iips_stat,$bill_stat,$acc_mgr,$acc_ldr,$presales,$proj_mgr);
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
    'has_project_mgmt'    => isset($_POST['has_project_mgmt']) ? 1 : ($iips_data['has_project_mgmt'] ?? 0),
    'target_mandays'      => $_POST['target_mandays']      ?? ($iips_data['target_mandays']      ?? ''),
    'target_start_date'   => $_POST['target_start_date']   ?? ($iips_data['target_start_date']   ?? ''),
    'target_end_date'     => $_POST['target_end_date']     ?? ($iips_data['target_end_date']     ?? ''),
    'target_billing_date' => $_POST['target_billing_date'] ?? ($iips_data['target_billing_date'] ?? ''),
    'iips_status'         => $_POST['iips_status']         ?? ($edit_mode ? ($iips_data['iips_status']    ?? '') : ''),
    'billing_status'      => $_POST['billing_status']      ?? ($edit_mode ? ($iips_data['billing_status'] ?? '') : ''),
    'account_manager'     => $_POST['account_manager']     ?? ($iips_data['account_manager'] ?? ''),
    'account_leader'      => $_POST['account_leader']      ?? ($iips_data['account_leader']  ?? ''),
    'presales_sdm'        => $_POST['presales_sdm']        ?? ($iips_data['presales_sdm']    ?? ''),
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
                    <input type="number" name="selling_price" id="sp" step="0.01" min="0" value="<?= htmlspecialchars($v['selling_price']) ?>" placeholder="e.g. 10000.00" oninput="calcGP()" class="<?= in_array('Selling Price is required.',$errors)?'err':'' ?>">
                </div>
                <div class="form-group">
                    <label>Partner Cost (RM) <span class="req">*</span></label>
                    <input type="number" name="partner_cost" id="pc" step="0.01" min="0" value="<?= htmlspecialchars($v['partner_cost']) ?>" placeholder="e.g. 3000.00" oninput="calcGP()" class="<?= in_array('Partner Cost is required.',$errors)?'err':'' ?>">
                </div>
                <div class="form-group">
                    <label>Gross Profit (RM)</label>
                    <div class="gp-preview" id="gp-preview">—</div>
                </div>
                <div class="check-group">
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
                    <label>Target Man-Days (hr) <span class="req">*</span></label>
                    <input type="number" name="target_mandays" step="0.5" min="0" value="<?= htmlspecialchars($v['target_mandays']) ?>" class="<?= in_array('Target Man-Days is required.',$errors)?'err':'' ?>">
                </div>
                <div class="form-group">
                    <label>Target Billing Date <span class="req">*</span></label>
                    <input type="date" name="target_billing_date" value="<?= htmlspecialchars($v['target_billing_date']) ?>" class="<?= in_array('Target Billing Date is required.',$errors)?'err':'' ?>">
                </div>
                <div class="form-group">
                    <label>Target Start Date <span class="req">*</span></label>
                    <input type="date" name="target_start_date" value="<?= htmlspecialchars($v['target_start_date']) ?>" class="<?= in_array('Target Start Date is required.',$errors)?'err':'' ?>">
                </div>
                <div class="form-group">
                    <label>Target End Date <span class="req">*</span></label>
                    <input type="date" name="target_end_date" value="<?= htmlspecialchars($v['target_end_date']) ?>" class="<?= in_array('Target End Date is required.',$errors)?'err':'' ?>">
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
            <div class="section-hdr purple">👥 IIPS Resources</div>
            <div class="section-body">
                <div class="form-group">
                    <label>Account Manager <span class="req">*</span></label>
                    <input type="text" name="account_manager" value="<?= htmlspecialchars($v['account_manager']) ?>" class="<?= in_array('Account Manager is required.',$errors)?'err':'' ?>">
                </div>
                <div class="form-group">
                    <label>Account Leader <span class="req">*</span></label>
                    <input type="text" name="account_leader" value="<?= htmlspecialchars($v['account_leader']) ?>" class="<?= in_array('Account Leader is required.',$errors)?'err':'' ?>">
                </div>
                <div class="form-group">
                    <label>Pre-Sales / SDM <span class="req">*</span></label>
                    <input type="text" name="presales_sdm" value="<?= htmlspecialchars($v['presales_sdm']) ?>" class="<?= in_array('Pre-Sales / SDM is required.',$errors)?'err':'' ?>">
                </div>
                <div class="form-group">
                    <label>Project Manager <span class="req">*</span></label>
                    <input type="text" name="project_manager" value="<?= htmlspecialchars($v['project_manager']) ?>" class="<?= in_array('Project Manager is required.',$errors)?'err':'' ?>">
                </div>
            </div>
        </div>

        <div class="actions">
            <button type="submit" class="btn-save"><?= $edit_mode ? 'Update Project' : 'Save Project' ?></button>
            <a href="admin_iips.php" class="btn-cancel">Cancel</a>
        </div>
    </form>
</div>

<script>
function calcGP() {
    const sp = parseFloat(document.getElementById('sp').value);
    const pc = parseFloat(document.getElementById('pc').value);
    const el = document.getElementById('gp-preview');
    if (isNaN(sp) && isNaN(pc)) { el.textContent = '—'; el.style.color = '#6b7280'; return; }
    const gp = (isNaN(sp) ? 0 : sp) - (isNaN(pc) ? 0 : pc);
    el.textContent = 'RM ' + gp.toLocaleString('en-MY', {minimumFractionDigits:2, maximumFractionDigits:2});
    el.style.color = gp > 0 ? '#166534' : gp < 0 ? '#dc2626' : '#6b7280';
}
calcGP();
</script>
</body>
</html>