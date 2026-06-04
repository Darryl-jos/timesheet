<?php
require_once 'config.php';

if (!isset($_SESSION['engineer_id']) || !isset($_SESSION['is_admin']) || ($_SESSION['is_admin'] != 1 && $_SESSION['is_admin'] != 2)) {
    header("Location: login.php"); exit;
}

$edit_mode = false;
$edit_data = null;
$iips_data = null;

// Load existing data for edit
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

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old_pid    = trim($_POST['old_project_id'] ?? '');
    $p_id       = !empty(trim($_POST['project_id'])) ? trim($_POST['project_id']) : ('N/A_'.uniqid());
    $p_name     = trim($_POST['project_name']);
    $c_name     = trim($_POST['customer_name']);
    $est_days   = intval($_POST['estimate_days'] ?? 0);
    $est_hours  = $est_days * 8;
    $pricing    = !empty(trim($_POST['pricing'] ?? '')) ? floatval($_POST['pricing']) : null;

    // IIPS fields
    $selling    = !empty($_POST['selling_price'])  ? floatval($_POST['selling_price'])  : null;
    $partner    = !empty($_POST['partner_cost'])   ? floatval($_POST['partner_cost'])   : null;
    $gross      = ($selling !== null && $partner !== null) ? $selling - $partner : null;
    $has_pm     = isset($_POST['has_project_mgmt']) ? 1 : 0;
    $tgt_md     = !empty($_POST['target_mandays'])       ? floatval($_POST['target_mandays'])        : null;
    $tgt_sd     = !empty($_POST['target_start_date'])    ? $_POST['target_start_date']               : null;
    $tgt_ed     = !empty($_POST['target_end_date'])      ? $_POST['target_end_date']                 : null;
    $tgt_bd     = !empty($_POST['target_billing_date'])  ? $_POST['target_billing_date']             : null;
    $iips_stat  = $_POST['iips_status']   ?? 'Not Quoted';
    $bill_stat  = $_POST['billing_status']?? 'Not Forecasted';
    $acc_mgr    = trim($_POST['account_manager']  ?? '');
    $acc_ldr    = trim($_POST['account_leader']   ?? '');
    $presales   = trim($_POST['presales_sdm']     ?? '');
    $proj_mgr   = trim($_POST['project_manager']  ?? '');

    if (empty($p_name) || empty($c_name)) {
        $error = "Project Name and Customer Name are required.";
    } else {
        if ($edit_mode && !empty($old_pid)) {
            // Update project
            $conn->begin_transaction();
            try {
                $conn->query("SET FOREIGN_KEY_CHECKS=0");
                $s = $conn->prepare("UPDATE projects SET project_id=?,project_name=?,customer_name=?,estimate_time=?,pricing=? WHERE project_id=?");
                $s->bind_param("sssids", $p_id,$p_name,$c_name,$est_hours,$pricing,$old_pid); $s->execute(); $s->close();

                if ($p_id !== $old_pid) {
                    $conn->prepare("UPDATE timesheets SET project_id=? WHERE project_id=?")->execute() || true;
                    $u = $conn->prepare("UPDATE timesheets SET project_id=? WHERE project_id=?");
                    $u->bind_param("ss",$p_id,$old_pid); $u->execute(); $u->close();
                    $u2 = $conn->prepare("UPDATE iips_tracking SET project_id=? WHERE project_id=?");
                    $u2->bind_param("ss",$p_id,$old_pid); $u2->execute(); $u2->close();
                }
                $conn->query("SET FOREIGN_KEY_CHECKS=1");

                // Upsert iips_tracking
                $chk = $conn->prepare("SELECT id FROM iips_tracking WHERE project_id=?");
                $chk->bind_param("s",$p_id); $chk->execute();
                $exists = $chk->get_result()->num_rows > 0; $chk->close();

                if ($exists) {
                    $upd = $conn->prepare("UPDATE iips_tracking SET selling_price=?,partner_cost=?,gross_profit=?,has_project_mgmt=?,target_mandays=?,target_start_date=?,target_end_date=?,target_billing_date=?,iips_status=?,billing_status=?,account_manager=?,account_leader=?,presales_sdm=?,project_manager=? WHERE project_id=?");
                    $upd->bind_param("dddidsssssssss",$selling,$partner,$gross,$has_pm,$tgt_md,$tgt_sd,$tgt_ed,$tgt_bd,$iips_stat,$bill_stat,$acc_mgr,$acc_ldr,$presales,$proj_mgr,$p_id);
                    $upd->execute(); $upd->close();
                } else {
                    $ins = $conn->prepare("INSERT INTO iips_tracking (project_id,selling_price,partner_cost,gross_profit,has_project_mgmt,target_mandays,target_start_date,target_end_date,target_billing_date,iips_status,billing_status,account_manager,account_leader,presales_sdm,project_manager) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                    $ins->bind_param("sdddidsssssssss",$p_id,$selling,$partner,$gross,$has_pm,$tgt_md,$tgt_sd,$tgt_ed,$tgt_bd,$iips_stat,$bill_stat,$acc_mgr,$acc_ldr,$presales,$proj_mgr);
                    $ins->execute(); $ins->close();
                }
                $conn->commit();
                header("Location: admin_iips.php"); exit;
            } catch(Exception $e) {
                $conn->rollback();
                $error = "Error: ".$e->getMessage();
            }
        } else {
            // Insert new
            $s = $conn->prepare("INSERT INTO projects (project_id,project_name,customer_name,estimate_time,pricing) VALUES (?,?,?,?,?)");
            $s->bind_param("sssid",$p_id,$p_name,$c_name,$est_hours,$pricing); $s->execute(); $s->close();
            $ins = $conn->prepare("INSERT INTO iips_tracking (project_id,selling_price,partner_cost,gross_profit,has_project_mgmt,target_mandays,target_start_date,target_end_date,target_billing_date,iips_status,billing_status,account_manager,account_leader,presales_sdm,project_manager) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $ins->bind_param("sdddidsssssssss",$p_id,$selling,$partner,$gross,$has_pm,$tgt_md,$tgt_sd,$tgt_ed,$tgt_bd,$iips_stat,$bill_stat,$acc_mgr,$acc_ldr,$presales,$proj_mgr);
            $ins->execute(); $ins->close();
            header("Location: admin_iips.php"); exit;
        }
    }
}

// Pre-fill values
$v = [
    'project_id'          => $edit_data['project_id']    ?? '',
    'project_name'        => $edit_data['project_name']  ?? '',
    'customer_name'       => $edit_data['customer_name'] ?? '',
    'estimate_days'       => $edit_data ? intval($edit_data['estimate_time']/8) : '',
    'pricing'             => $edit_data['pricing']        ?? '',
    'selling_price'       => $iips_data['selling_price']  ?? '',
    'partner_cost'        => $iips_data['partner_cost']   ?? '',
    'has_project_mgmt'    => $iips_data['has_project_mgmt'] ?? 0,
    'target_mandays'      => $iips_data['target_mandays'] ?? '',
    'target_start_date'   => $iips_data['target_start_date'] ?? '',
    'target_end_date'     => $iips_data['target_end_date']   ?? '',
    'target_billing_date' => $iips_data['target_billing_date'] ?? '',
    'iips_status'         => $iips_data['iips_status']    ?? 'Not Quoted',
    'billing_status'      => $iips_data['billing_status'] ?? 'Not Forecasted',
    'account_manager'     => $iips_data['account_manager'] ?? '',
    'account_leader'      => $iips_data['account_leader']  ?? '',
    'presales_sdm'        => $iips_data['presales_sdm']    ?? '',
    'project_manager'     => $iips_data['project_manager'] ?? '',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title><?= $edit_mode ? 'Edit' : 'Create' ?> Project — IIPS</title>
<style>
* { box-sizing: border-box; }
body { font-family: Arial, sans-serif; margin: 30px; background: #f4f7f6; }

.topbar { background: #343a40; padding: 15px 20px; display: flex; align-items: center; justify-content: space-between; border-radius: 8px; flex-wrap: wrap; gap: 10px; }
.topbar h2 { color: white; margin: 0; font-size: 18px; }
.topbar a { color: #ffc107; font-weight: bold; text-decoration: none; font-size: 13px; }

.page { max-width: 900px; margin: 20px auto; padding: 0 20px 60px; }

.section { background: white; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.06); margin-bottom: 20px; overflow: hidden; }
.section-hdr { background: #343a40; color: white; padding: 10px 20px; font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; }
.section-hdr.green  { background: #155724; }
.section-hdr.blue   { background: #1a237e; }
.section-hdr.purple { background: #4a235a; }
.section-hdr.teal   { background: #6a1b4d; }
.section-body { padding: 20px; display: grid; grid-template-columns: 1fr 1fr; gap: 14px 20px; }
.section-body.one-col { grid-template-columns: 1fr; }
.section-body.three-col { grid-template-columns: 1fr 1fr 1fr; }

.form-group { display: flex; flex-direction: column; gap: 4px; }
.form-group label { font-size: 12px; font-weight: 700; color: #495057; }
.form-group input, .form-group select {
    height: 36px; padding: 0 10px; border: 1px solid #ced4da; border-radius: 4px;
    font-size: 13px; width: 100%;
}
.form-group input:focus, .form-group select:focus { border-color: #007bff; outline: none; box-shadow: 0 0 0 2px rgba(0,123,255,.15); }
.form-group .hint { font-size: 11px; color: #6c757d; }

/* Checkbox toggle */
.check-group { display: flex; align-items: center; gap: 10px; padding-top: 8px; }
.check-group input[type="checkbox"] { width: 18px; height: 18px; cursor: pointer; }
.check-group label { font-size: 13px; font-weight: 600; color: #333; margin: 0; }

/* Gross profit preview */
.gp-preview { background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 4px; padding: 8px 12px; font-size: 13px; font-weight: 700; margin-top: 2px; }

.alert-err { background:#f8d7da; color:#721c24; padding:12px; border-radius:4px; margin-bottom:15px; border:1px solid #f5c6cb; font-size:13px; }

.actions { display: flex; gap: 12px; margin-top: 24px; }
.btn-save { background: #28a745; color: white; border: none; padding: 0 28px; height: 40px; border-radius: 4px; font-size: 14px; font-weight: bold; cursor: pointer; }
.btn-save:hover { background: #218838; }
.btn-cancel { display: inline-flex; align-items: center; color: #6c757d; text-decoration: none; font-size: 13px; height: 40px; }

@media (max-width: 600px) {
    .section-body, .section-body.three-col { grid-template-columns: 1fr; }
    .page { padding: 0 12px 40px; }
}
</style>
</head>
<body>

<div class="topbar">
    <h2><?= $edit_mode ? '✏️ Edit Project' : '+ Create New Project' ?></h2>
    <a href="admin_iips.php">← Back to Project List</a>
</div>

<div class="page">
    <?php if ($error): ?>
        <div class="alert-err">⚠️ <?= $error ?></div>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="old_project_id" value="<?= htmlspecialchars($v['project_id']) ?>">

        <!-- Project Details -->
        <div class="section">
            <div class="section-hdr">📁 Project Details</div>
            <div class="section-body three-col">
                <div class="form-group">
                    <label>Project ID <span style="font-weight:400;color:#9ca3af;">(optional)</span></label>
                    <input type="text" name="project_id" value="<?= htmlspecialchars($v['project_id']) ?>" placeholder="e.g. SO-00123">
                    <span class="hint">Leave blank to auto-assign. N/A IDs will show as "—".</span>
                </div>
                <div class="form-group">
                    <label>Project Name <span style="color:#dc2626;">*</span></label>
                    <input type="text" name="project_name" value="<?= htmlspecialchars($v['project_name']) ?>" required placeholder="e.g. Network Office Setup">
                </div>
                <div class="form-group">
                    <label>Customer Name <span style="color:#dc2626;">*</span></label>
                    <input type="text" name="customer_name" value="<?= htmlspecialchars($v['customer_name']) ?>" required placeholder="e.g. Starhub Ltd">
                </div>
                <div class="form-group">
                    <label>Target Man-Days <span style="font-weight:400;color:#9ca3af;">(1 day = 8h)</span></label>
                    <input type="number" name="estimate_days" value="<?= htmlspecialchars($v['estimate_days']) ?>" min="0" placeholder="e.g. 5">
                </div>
                <div class="form-group">
                    <label>Pricing (RM) <span style="font-weight:400;color:#9ca3af;">(optional)</span></label>
                    <input type="number" name="pricing" step="0.01" min="0" value="<?= htmlspecialchars($v['pricing']) ?>" placeholder="e.g. 5000.00">
                </div>
            </div>
        </div>

        <!-- IIPS Costing -->
        <div class="section">
            <div class="section-hdr green">💰 IIPS Costing</div>
            <div class="section-body three-col">
                <div class="form-group">
                    <label>Selling Price (RM)</label>
                    <input type="number" name="selling_price" id="sp" step="0.01" min="0" value="<?= htmlspecialchars($v['selling_price']) ?>" placeholder="e.g. 10000.00" oninput="calcGP()">
                </div>
                <div class="form-group">
                    <label>Partner Cost (RM)</label>
                    <input type="number" name="partner_cost" id="pc" step="0.01" min="0" value="<?= htmlspecialchars($v['partner_cost']) ?>" placeholder="e.g. 3000.00" oninput="calcGP()">
                </div>
                <div class="form-group">
                    <label>Gross Profit (RM) — Auto Calculated</label>
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
                    <label>Target Man-Days (hr)</label>
                    <input type="number" name="target_mandays" step="0.5" min="0" value="<?= htmlspecialchars($v['target_mandays']) ?>" placeholder="e.g. 40">
                </div>
                <div class="form-group">
                    <label>Target Billing Date</label>
                    <input type="date" name="target_billing_date" value="<?= htmlspecialchars($v['target_billing_date']) ?>">
                </div>
                <div class="form-group">
                    <label>Target Start Date</label>
                    <input type="date" name="target_start_date" value="<?= htmlspecialchars($v['target_start_date']) ?>">
                </div>
                <div class="form-group">
                    <label>Target End Date</label>
                    <input type="date" name="target_end_date" value="<?= htmlspecialchars($v['target_end_date']) ?>">
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
                        <?php foreach (['Not Quoted','Quoted','Not Started','In Progress','Completed','Cancelled'] as $o): ?>
                        <option value="<?= $o ?>" <?= $v['iips_status']===$o?'selected':'' ?>><?= $o ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Billing Status</label>
                    <select name="billing_status">
                        <?php foreach (['Not Forecasted','Forecasted','Pending','Completed'] as $o): ?>
                        <option value="<?= $o ?>" <?= $v['billing_status']===$o?'selected':'' ?>><?= $o ?></option>
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
                    <label>Account Manager</label>
                    <input type="text" name="account_manager" value="<?= htmlspecialchars($v['account_manager']) ?>" placeholder="e.g. Liang Tian Yong">
                </div>
                <div class="form-group">
                    <label>Account Leader</label>
                    <input type="text" name="account_leader" value="<?= htmlspecialchars($v['account_leader']) ?>" placeholder="e.g. Lim Wee Peng">
                </div>
                <div class="form-group">
                    <label>Pre-Sales / SDM</label>
                    <input type="text" name="presales_sdm" value="<?= htmlspecialchars($v['presales_sdm']) ?>" placeholder="e.g. Goeh Sin Pei">
                </div>
                <div class="form-group">
                    <label>Project Manager</label>
                    <input type="text" name="project_manager" value="<?= htmlspecialchars($v['project_manager']) ?>" placeholder="e.g. Hew Koi Kan">
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
    const sp = parseFloat(document.getElementById('sp').value) || 0;
    const pc = parseFloat(document.getElementById('pc').value) || 0;
    const gp = sp - pc;
    const el = document.getElementById('gp-preview');
    if (!document.getElementById('sp').value && !document.getElementById('pc').value) {
        el.textContent = '—'; el.style.color = '#6b7280'; return;
    }
    el.textContent = 'RM ' + gp.toLocaleString('en-MY', {minimumFractionDigits:2, maximumFractionDigits:2});
    el.style.color = gp > 0 ? '#166534' : gp < 0 ? '#dc2626' : '#6b7280';
}
calcGP();
</script>
</body>
</html>