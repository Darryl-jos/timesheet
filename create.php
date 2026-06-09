<?php
require_once 'config.php';
if (!isset($_SESSION['engineer_id'])) { 
    header("Location: login.php"); 
    exit; 
}
$current_user_id = $_SESSION['engineer_id'];
$name_stmt = $conn->prepare("SELECT engineer_name FROM engineers WHERE id = ?");
$name_stmt->bind_param("i", $current_user_id);
$name_stmt->execute();
$name_res = $name_stmt->get_result()->fetch_assoc();
$name_stmt->close();
$current_user_name = !empty($name_res['engineer_name']) ? $name_res['engineer_name'] : 'Unknown Engineer';

$sel_proj_id = '';
$sel_date = '';
$sel_start_time = '';
$sel_end_time = '';
$sel_work_desc = '';
$sel_meal_breaks = '0';
$conflict_error = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $sel_proj_id = $_POST['project_id'];
    $sel_date = $_POST['date'];
    $sel_start_time = $_POST['start_time']; 
    $sel_end_time = $_POST['end_time'];   
    $sel_work_desc = trim($_POST['work_description']); 
    $sel_meal_breaks = isset($_POST['meal_breaks']) ? (int)$_POST['meal_breaks'] : 0;
    
    $has_iips_mgr = (($_POST['has_iips_manager_radio'] ?? 'no') === 'yes') ? 1 : 0;
    $iips_mgr_name = implode(', ', array_filter(array_map('trim', $_POST['iips_manager_multi'] ?? [])));
    
    $has_partner = (($_POST['has_partner_radio'] ?? 'no') === 'yes') ? 1 : 0;
    $partner_name = implode(', ', array_filter(array_map('trim', $_POST['partner_multi'] ?? [])));
    
    if (!empty($sel_date) && !empty($sel_start_time) && !empty($sel_end_time)) {
        $start_dt = new DateTime("$sel_date $sel_start_time");
        $end_dt = new DateTime("$sel_date $sel_end_time");
        if ($end_dt <= $start_dt) {
            $end_dt->modify('+1 day');
        }
        
        $diff_hours = ($end_dt->getTimestamp() - $start_dt->getTimestamp()) / 3600;
        $max_breaks = 0;
        if ($diff_hours >= 24) $max_breaks = 3;
        elseif ($diff_hours > 16) $max_breaks = 2;
        elseif ($diff_hours > 8) $max_breaks = 1;
        
        if ($sel_meal_breaks > $max_breaks) $sel_meal_breaks = $max_breaks;
        
        $final_start_date = $start_dt->format('Y-m-d');
        $final_start_time = $start_dt->format('H:i:s');
        $final_end_date = $end_dt->format('Y-m-d');
        $final_end_time = $end_dt->format('H:i:s');

        $new_start_dt_str = $start_dt->format('Y-m-d H:i:s');
        $new_end_dt_str = $end_dt->format('Y-m-d H:i:s');

        $c_stmt = $conn->prepare("SELECT start_date, start_time, end_date, end_time FROM timesheets WHERE engineer_id = ? AND CONCAT(start_date, ' ', start_time) < ? AND CONCAT(end_date, ' ', end_time) > ? LIMIT 1");
        $c_stmt->bind_param("iss", $current_user_id, $new_end_dt_str, $new_start_dt_str);
        $c_stmt->execute();
        $c_res = $c_stmt->get_result();
        if ($c_res->num_rows > 0) {
            $c_row = $c_res->fetch_assoc();
            $c_s = strtoupper(date('d-M-Y h:i A', strtotime($c_row['start_date'] . ' ' . $c_row['start_time'])));
            $c_e = strtoupper(date('d-M-Y h:i A', strtotime($c_row['end_date'] . ' ' . $c_row['end_time'])));
            $conflict_error = "Time conflict detected! You already have a record from <b>$c_s</b> to <b>$c_e</b>. Please adjust your time range.";
        }
        $c_stmt->close();

        if (empty($conflict_error)) {
            $stmt = $conn->prepare("INSERT INTO timesheets (engineer_id, engineer_name, project_id, start_date, start_time, end_date, end_time, work_description, meal_breaks) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("isssssssi", $current_user_id, $current_user_name, $sel_proj_id, $final_start_date, $final_start_time, $final_end_date, $final_end_time, $sel_work_desc, $sel_meal_breaks);
            $stmt->execute();
            $stmt->close();

            if (($has_iips_mgr && !empty($iips_mgr_name)) || ($has_partner && !empty($partner_name))) {
                $chk = $conn->prepare("SELECT id FROM iips_tracking WHERE project_id=?");
                $chk->bind_param("s", $sel_proj_id); $chk->execute();
                $iips_exists = $chk->get_result()->num_rows > 0; $chk->close();

                if (!$iips_exists) {
                    $ins2 = $conn->prepare("INSERT INTO iips_tracking (project_id) VALUES (?)");
                    $ins2->bind_param("s", $sel_proj_id); $ins2->execute(); $ins2->close();
                }
                if ($has_iips_mgr && !empty($iips_mgr_name)) {
                    $upd = $conn->prepare("UPDATE iips_tracking SET project_manager=? WHERE project_id=? AND (project_manager IS NULL OR project_manager='')");
                    $upd->bind_param("ss", $iips_mgr_name, $sel_proj_id); $upd->execute(); $upd->close();
                }
                if ($has_partner && !empty($partner_name)) {
                    $upd2 = $conn->prepare("UPDATE iips_tracking SET partner=? WHERE project_id=? AND (partner IS NULL OR partner='')");
                    $upd2->bind_param("ss", $partner_name, $sel_proj_id); $upd2->execute(); $upd2->close();
                }
            }

            header("Location: index.php");
            exit;
        }
    }
}
$projects_res = $conn->query("SELECT p.*, i.project_manager AS iips_mgr, i.partner AS iips_partner FROM projects p LEFT JOIN iips_tracking i ON p.project_id = i.project_id ORDER BY p.project_id ASC");
$current_selected_label = "-- Select Project --";
while($p = $projects_res->fetch_assoc()) {
    if ($sel_proj_id == $p['project_id']) {
        $current_selected_label = ($p['project_id'] && !preg_match('/^N\/A/i', $p['project_id']) ? '['.$p['project_id'].'] ' : '') . $p['project_name'];
    }
}
$projects_res->data_seek(0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Create Record</title>
<style>
* { box-sizing: border-box; }
body { font-family: Arial, sans-serif; margin: 30px; background: #f4f7f6; }
.topbar { background: #ffffff; padding: 15px 20px; display: flex; align-items: center; justify-content: space-between; border-radius: 8px; flex-wrap: wrap; gap: 10px; }
.topbar h2 { color: #1f2937; margin: 0; font-size: 18px; }
.topbar a { color: #007bff; font-weight: bold; text-decoration: none; font-size: 13px; }
.page { max-width: 900px; margin: 20px auto; padding: 0 20px 60px; }
.conflict-alert { background-color: #fef2f2; border: 1px solid #f87171; color: #b91c1c; padding: 12px 16px; border-radius: 6px; margin-bottom: 20px; font-size: 14px; line-height: 1.5; }
.section { background: white; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.06); margin-bottom: 20px; overflow: visible; }
.section-hdr { background: #343a40; color: white; padding: 10px 20px; font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; border-top-left-radius: 8px; border-top-right-radius: 8px; }
.section-hdr.green { background: #155724; }
.section-body { padding: 20px; display: grid; grid-template-columns: 1fr 1fr; gap: 14px 20px; }
.section-body.one-col { grid-template-columns: 1fr; }
.form-group { display: flex; flex-direction: column; gap: 4px; }
.form-group label { font-size: 12px; font-weight: 700; color: #495057; }
.form-group input:not([type="checkbox"]), .form-group select, .form-group textarea { padding: 8px 10px; border: 1px solid #ced4da; border-radius: 4px; font-size: 13px; width: 100%; }
.form-group input:not([type="checkbox"]), .form-group select { height: 36px; }
.form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color: #007bff; outline: none; box-shadow: 0 0 0 2px rgba(0,123,255,.15); }
.form-group textarea { resize: vertical; min-height: 100px; font-family: Arial, sans-serif; }
.check-group { display: flex; align-items: center; gap: 10px; padding-top: 8px; }
.check-group input[type="checkbox"] { width: 18px; height: 18px; cursor: pointer; margin: 0; }
.check-group label { font-size: 13px; font-weight: 600; color: #333; margin: 0; }
.actions { display: flex; gap: 12px; margin-top: 24px; }
.btn-save { background: #28a745; color: white; border: none; padding: 0 28px; height: 40px; border-radius: 4px; font-size: 14px; font-weight: bold; cursor: pointer; }
.btn-save:hover { background: #218838; }
.btn-cancel { display: inline-flex; align-items: center; color: #6c757d; text-decoration: none; font-size: 13px; height: 40px; }
.sel-wrap { position: relative; width: 100%; }
.sel-box { height: 36px; padding: 0 10px; border: 1px solid #ced4da; border-radius: 4px; font-size: 13px; background: white; cursor: pointer; display: flex; align-items: center; justify-content: space-between; gap: 6px; user-select: none; }
.sel-box:hover { border-color: #007bff; }
.sel-box span:first-child { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; flex: 1; color: #333; }
.sel-arrow { color: #6c757d; font-size: 11px; flex-shrink: 0; transition: transform .2s; }
.sel-wrap.open .sel-arrow { transform: rotate(180deg); }
.sel-wrap.open .sel-box { border-color: #007bff; box-shadow: 0 0 0 2px rgba(0,123,255,.12); }
.sel-panel { display: none; position: absolute; top: calc(100% + 3px); left: 0; width: 100%; background: white; border: 1px solid #007bff; border-radius: 4px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); z-index: 200; padding: 6px; }
.sel-wrap.open .sel-panel { display: block; }
.sel-panel input { width: 100%; height: 32px; padding: 0 8px; border: 1px solid #ddd; border-radius: 3px; font-size: 12px; margin-bottom: 5px; box-sizing: border-box; }
.sel-panel input:focus { border-color: #007bff; outline: none; }
.sel-list { max-height: 200px; overflow-y: auto; }
.sel-item { padding: 7px 10px; cursor: pointer; font-size: 13px; border-radius: 3px; line-height: 1.3; }
.sel-item:hover { background: #f0f7ff; }
.sel-item.active { background: #e6f0ff; color: #1d4ed8; font-weight: 600; }
.sel-item.hidden { display: none; }
.custom-select-dropdown { display: none; position: absolute; top: 100%; left: 0; width: 100%; background: #fff; border: 1px solid #007bff; border-radius: 4px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); z-index: 999; margin-top: 2px; padding: 8px; box-sizing: border-box; }
.custom-option { padding: 8px 10px; cursor: pointer; font-size: 13px; border-radius: 3px; }
.custom-option:hover { background: #f0f7ff; color: #007bff; }
.custom-option.selected { background: #e6f0ff; color: #007bff; font-weight: bold; }
.show-dropdown { display: block !important; }
#start-time-dropdown, #end-time-dropdown { width: 100%; max-height: 220px; overflow-y: auto; padding: 5px 0; }
.time-header { background: #f8f9fa; padding: 6px 10px; font-size: 11px; font-weight: bold; color: #495057; position: sticky; top: 0; z-index: 10; border-bottom: 1px solid #e9ecef; }
.duration-preview { display: none; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 4px; padding: 8px 12px; font-size: 13px; color: #1e40af; font-weight: bold; text-align: center; margin-top: 8px; }
.error-border { border: 2px solid #dc2626 !important; }
.error-text { color: #dc2626; font-size: 11px; font-weight: bold; margin-top: 4px; display: none; }
.time-input-wrap { display: flex; position: relative; height: 36px; border-radius: 4px; }
.time-input-wrap input { padding-right: 30px; text-transform: uppercase; }
.time-input-wrap .dropdown-icon { position: absolute; right: 0; top: 0; width: 30px; height: 100%; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 11px; color: #666; }
@media (max-width: 600px) {
    body { margin: 15px; }
    .section-body { grid-template-columns: 1fr; }
    .page { padding: 0 0 40px; }
}
</style>
</head>
<body>
<div class="topbar">
    <h2>➕ Create New Record</h2>
    <a href="index.php">← Back to Dashboard</a>
</div>
<div class="page">
    <form method="POST" id="record-form">
        <div class="section">
            <div class="section-hdr">📁 IIPS Details</div>
            <div class="section-body one-col">
                <div class="form-group" style="position: relative;">
                    <label>Select IIPS <span style="color:#dc2626;">*</span></label>
                    <div class="sel-wrap" id="proj-wrap">
                        <div class="sel-box" id="proj-box" onclick="toggleSel('proj')">
                            <span id="proj-label"><?php echo htmlspecialchars($current_selected_label); ?></span>
                            <span class="sel-arrow">▾</span>
                        </div>
                        <div class="sel-panel" id="proj-panel">
                            <input type="text" id="proj-inner" placeholder="🔍 Type to filter..." oninput="filterSel('proj')" onclick="event.stopPropagation()">
                            <div class="sel-list" id="proj-list">
                                <?php while($p = $projects_res->fetch_assoc()): 
                                    $label = ($p['project_id'] && !preg_match('/^N\/A/i',$p['project_id']) ? '['.$p['project_id'].'] ' : '').$p['project_name'];
                                    $kw = strtolower($p['project_id'].' '.$p['project_name'].' '.$p['customer_name']);
                                    $is_selected = ($sel_proj_id == $p['project_id']) ? 'active' : '';
                                ?>
                                <div class="sel-item <?php echo $is_selected; ?>"
                                     data-value="<?php echo htmlspecialchars($p['project_id']); ?>"
                                     data-kw="<?php echo htmlspecialchars($kw); ?>"
                                     data-iips-mgr="<?php echo htmlspecialchars($p['iips_mgr'] ?? ''); ?>"
                                     data-iips-ptr="<?php echo htmlspecialchars($p['iips_partner'] ?? ''); ?>"
                                     onclick="pickSel('proj', '<?php echo htmlspecialchars(addslashes($p['project_id'])); ?>', '<?php echo htmlspecialchars(addslashes($label)); ?>', this)">
                                    <?php echo htmlspecialchars($label); ?>
                                    <span style="color:#9ca3af;font-size:11px;display:block;"><?php echo htmlspecialchars($p['customer_name']); ?></span>
                                </div>
                                <?php endwhile; ?>
                            </div>
                        </div>
                    </div>
                    <input type="hidden" name="project_id" id="hidden-project-id" value="<?php echo htmlspecialchars($sel_proj_id); ?>">
                    <div class="error-text" id="err-proj">Please select a IIPS.</div>
                </div>
                <div class="form-group">
                    <label>Date <span style="color:#dc2626;">*</span></label>
                    <div style="position: relative; height: 36px; width: 100%; display: flex;">
                        <input type="text" id="date-display" placeholder="DD MMM YYYY" style="flex: 1; height: 100%; padding: 8px 36px 8px 10px; border: 1px solid #ced4da; border-radius: 4px; font-size: 13px; text-transform: uppercase;" autocomplete="off">
                        <div style="position: absolute; right: 0; top: 0; width: 36px; height: 100%; display: flex; align-items: center; justify-content: center; cursor: pointer; z-index: 5;" onclick="document.getElementById('date').showPicker()">📅
                            <input type="date" name="date" id="date" value="<?php echo htmlspecialchars($sel_date); ?>" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; opacity: 0; cursor: pointer; z-index: 10;" onchange="syncDateFromPicker()">
                        </div>
                    </div>
                    <div class="error-text" id="err-date">Please provide a valid Date.</div>
                </div>
            </div>
        </div>

        <?php if (!empty($conflict_error)): ?>
            <div class="conflict-alert" id="conflict-alert">
                <strong>⚠️ Warning</strong><br>
                <?php echo $conflict_error; ?>
            </div>
        <?php endif; ?>

        <div class="section">
            <div class="section-hdr green">⏱️ Time & Activity</div>
            <div class="section-body one-col">
                <div class="form-group">
                    <label>Time Range <span style="color:#dc2626;">*</span></label>
                    <div style="display: flex; gap: 10px; position: relative;">
                        <div style="flex: 1; position: relative;">
                            <div class="time-input-wrap">
                                <input type="text" id="start-time-input" placeholder="Start Time" autocomplete="off">
                                <div class="dropdown-icon" onclick="toggleTimeDropdown('start', event)">▾</div>
                            </div>
                            <div class="custom-select-dropdown" id="start-time-dropdown"></div>
                            <input type="hidden" name="start_time" id="start-time-hidden" value="<?php echo htmlspecialchars($sel_start_time); ?>">
                            <div class="error-text" id="err-start">Please select a Start Time.</div>
                        </div>
                        <span style="align-self: center; font-size: 13px; color: #495057;">to</span>
                        <div style="flex: 1; position: relative;">
                            <div class="time-input-wrap">
                                <input type="text" id="end-time-input" placeholder="End Time" autocomplete="off">
                                <div class="dropdown-icon" onclick="toggleTimeDropdown('end', event)">▾</div>
                            </div>
                            <div class="custom-select-dropdown" id="end-time-dropdown"></div>
                            <input type="hidden" name="end_time" id="end-time-hidden" value="<?php echo htmlspecialchars($sel_end_time); ?>">
                            <div class="error-text" id="err-end">Please select an End Time.</div>
                        </div>
                    </div>
                    <div class="duration-preview" id="dur-preview"></div>
                </div>
                <div class="form-group" id="meal-break-container" style="display: none;">
                    <div class="check-group">
                        <input type="checkbox" id="meal_break_checkbox">
                        <label for="meal_break_checkbox">Meal Break</label>
                    </div>
                    <select id="meal_breaks_select" style="display: none; margin-top: 8px; width: 100px;"></select>
                    <input type="hidden" name="meal_breaks" id="actual_meal_breaks" value="<?php echo htmlspecialchars($sel_meal_breaks); ?>">
                </div>
                <div class="form-group">
                    <label>Activity (Work Description) <span style="color:#dc2626;">*</span></label>
                    <textarea name="work_description" id="desc-input" placeholder="Detail exactly what steps or technical activities you conducted during this shift..."><?php echo htmlspecialchars($sel_work_desc); ?></textarea>
                    <div class="error-text" id="err-desc">Please provide a Work Description.</div>
                </div>
            </div>
        </div>

        <div class="section">
            <div class="section-hdr" style="background:#4a235a;">👥 IIPS Management</div>
            <div class="section-body">
                <div class="form-group">
                    <label>Do you have an IIPS Manager?</label>
                    <div style="display:flex;gap:20px;margin-top:6px;">
                        <label style="display:flex;align-items:center;gap:6px;font-weight:400;font-size:14px;cursor:pointer;">
                            <input type="radio" name="has_iips_manager_radio" value="yes" onclick="document.getElementById('iips-mgr-field').style.display='block'"> Yes
                        </label>
                        <label style="display:flex;align-items:center;gap:6px;font-weight:400;font-size:14px;cursor:pointer;">
                            <input type="radio" name="has_iips_manager_radio" value="no" onclick="document.getElementById('iips-mgr-field').style.display='none'"> No
                        </label>
                    </div>
                    <div id="iips-mgr-field" style="display:none;margin-top:10px;">
                        <div id="iips-mgr-list">
                            <div class="multi-name-row" style="display:flex;gap:8px;margin-bottom:6px;">
                                <input type="text" name="iips_manager_multi[]" placeholder="Enter IIPS Manager name" style="flex:1;height:36px;padding:0 10px;border:1px solid #ced4da;border-radius:4px;font-size:13px;">
                                <button type="button" onclick="removeName(this)" style="background:#dc3545;color:white;border:none;width:32px;border-radius:4px;cursor:pointer;font-size:16px;visibility:hidden;">×</button>
                            </div>
                        </div>
                        <button type="button" onclick="addName('iips-mgr-list')" style="background:none;border:1px dashed #94a3b8;color:#64748b;padding:5px 12px;border-radius:4px;font-size:12px;cursor:pointer;margin-top:2px;width:100%;">+ Add another</button>
                    </div>
                </div>
                <div class="form-group">
                    <label>Do you have a Partner?</label>
                    <div style="display:flex;gap:20px;margin-top:6px;">
                        <label style="display:flex;align-items:center;gap:6px;font-weight:400;font-size:14px;cursor:pointer;">
                            <input type="radio" name="has_partner_radio" value="yes" onclick="document.getElementById('partner-field').style.display='block'"> Yes
                        </label>
                        <label style="display:flex;align-items:center;gap:6px;font-weight:400;font-size:14px;cursor:pointer;">
                            <input type="radio" name="has_partner_radio" value="no" onclick="document.getElementById('partner-field').style.display='none'"> No
                        </label>
                    </div>
                    <div id="partner-field" style="display:none;margin-top:10px;">
                        <div id="partner-list">
                            <div class="multi-name-row" style="display:flex;gap:8px;margin-bottom:6px;">
                                <input type="text" name="partner_multi[]" placeholder="Enter partner name" style="flex:1;height:36px;padding:0 10px;border:1px solid #ced4da;border-radius:4px;font-size:13px;">
                                <button type="button" onclick="removeName(this)" style="background:#dc3545;color:white;border:none;width:32px;border-radius:4px;cursor:pointer;font-size:16px;visibility:hidden;">×</button>
                            </div>
                        </div>
                        <button type="button" onclick="addName('partner-list')" style="background:none;border:1px dashed #94a3b8;color:#64748b;padding:5px 12px;border-radius:4px;font-size:12px;cursor:pointer;margin-top:2px;width:100%;">+ Add another</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="actions">
            <button type="submit" class="btn-save">Save Record</button>
            <a href="index.php" class="btn-cancel">Cancel</a>
        </div>
    </form>
</div>

<script>
function addName(listId) {
    const list = document.getElementById(listId);
    const div = document.createElement('div');
    div.className = 'multi-name-row';
    div.style.cssText = 'display:flex;gap:8px;margin-bottom:6px;';
    const nameMap = { 'iips-mgr-list':'iips_manager_multi[]', 'partner-list':'partner_multi[]' };
    div.innerHTML = `<input type="text" name="${nameMap[listId]}" placeholder="Enter name" style="flex:1;height:36px;padding:0 10px;border:1px solid #ced4da;border-radius:4px;font-size:13px;"><button type="button" onclick="removeName(this)" style="background:#dc3545;color:white;border:none;width:32px;border-radius:4px;cursor:pointer;font-size:16px;">×</button>`;
    list.appendChild(div);
    div.querySelector('input').focus();
    const rows = list.querySelectorAll('.multi-name-row');
    if (rows.length > 0) {
        rows[0].querySelector('button').style.visibility = rows.length === 1 ? 'hidden' : 'visible';
    }
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

let amPmErrorFlag = { start: false, end: false };

function clearError(visId, errId) {
    const vis = document.getElementById(visId);
    const err = document.getElementById(errId);
    if(vis) vis.classList.remove('error-border');
    if(err) err.style.display = 'none';
}

function parseDateInput(str) {
    str = str.trim();
    if (!str) return '';
    let d, m, y;
    const months = ["JAN","FEB","MAR","APR","MAY","JUN","JUL","AUG","SEP","OCT","NOV","DEC"];
    let textMatch = str.match(/^(\d{1,2})[\s\/\-]*([a-zA-Z]+)[\s\/\-]*(\d{2,4})$/);
    if (textMatch) {
        d = parseInt(textMatch[1], 10);
        let mStr = textMatch[2].toUpperCase().substring(0, 3);
        m = months.indexOf(mStr) + 1;
        if (m === 0) return '';
        y = parseInt(textMatch[3], 10);
    } else {
        let numMatch = str.match(/^(\d{1,2})[\s\/\-]+(\d{1,2})[\s\/\-]+(\d{2,4})$/);
        if (numMatch) {
            d = parseInt(numMatch[1], 10);
            m = parseInt(numMatch[2], 10);
            y = parseInt(numMatch[3], 10);
        } else {
            let digitMatch = str.match(/^(\d{2})(\d{2})(\d{4})$/);
            if (digitMatch) {
                d = parseInt(digitMatch[1], 10);
                m = parseInt(digitMatch[2], 10);
                y = parseInt(digitMatch[3], 10);
            } else {
                return '';
            }
        }
    }
    if (y < 100) y += 2000;
    if (m < 1 || m > 12) return '';
    let daysInMonth = new Date(y, m, 0).getDate();
    if (d < 1 || d > daysInMonth) return '';
    let mmStr = m < 10 ? '0' + m : m;
    let ddStr = d < 10 ? '0' + d : d;
    return y + '-' + mmStr + '-' + ddStr;
}

function updateDateDisplay() {
    const val = document.getElementById('date').value;
    const display = document.getElementById('date-display');
    if (val) {
        const parts = val.split('-');
        if (parts.length === 3) {
            const months = ["JAN", "FEB", "MAR", "APR", "MAY", "JUN", "JUL", "AUG", "SEP", "OCT", "NOV", "DEC"];
            let d = parseInt(parts[2], 10);
            let m = parseInt(parts[1], 10);
            display.value = d + ' ' + months[m - 1] + ' ' + parts[0];
            clearError('date-display', 'err-date');
        }
    } else {
        display.value = "";
    }
}

document.getElementById('date-display').addEventListener('blur', function() {
    if (!this.value.trim()) {
        document.getElementById('date').value = "";
        updateDateDisplay();
        return;
    }
    const parsed = parseDateInput(this.value);
    if (parsed) {
        document.getElementById('date').value = parsed;
        updateDateDisplay();
        generateEndTimes();
        calculateMealBreaks();
    } else {
        this.classList.add('error-border');
        const err = document.getElementById('err-date');
        err.innerText = "The date format is incorrect. You can enter formats such as DD-MM-YYYY, DD/MM/YYYY, or DD MONTH YYYY.";
        err.style.display = 'block';
    }
});

document.getElementById('date-display').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        this.blur();
    }
});

document.getElementById('date-display').addEventListener('input', function(e) {
    if (e.inputType === 'deleteContentBackward') return;
    let v = this.value;
    if (/^\d{2}$/.test(v)) this.value = v + '-';
    if (/^\d{2}-\d{2}$/.test(v)) this.value = v + '-';
    if (/^\d{8}$/.test(v)) {
        this.value = v.substring(0,2) + '-' + v.substring(2,4) + '-' + v.substring(4,8);
    }
});

function syncDateFromPicker() {
    updateDateDisplay();
    generateEndTimes();
    calculateMealBreaks();
}

function convertTo24Hour(time12h) {
    if (!time12h) return '';
    const parts = time12h.split(' ');
    if (parts.length !== 2) return '';
    let [hours, minutes] = parts[0].split(':');
    let modifier = parts[1];
    if (hours === '12') hours = '00';
    if (modifier === 'PM') hours = parseInt(hours, 10) + 12;
    return hours.toString().padStart(2, '0') + ':' + minutes;
}

function convertTo12Hour(time24h) {
    if (!time24h) return '';
    const [h, m] = time24h.split(':').map(Number);
    return formatAMPM(h, m);
}

function parseAndRoundTime(inputStr, inputEl, errId, type) {
    let str = inputStr.trim().toLowerCase();
    if (!str) {
        amPmErrorFlag[type] = false;
        return null;
    }
    if (!str.includes('am') && !str.includes('pm')) {
        inputEl.classList.add('error-border');
        const errObj = document.getElementById(errId);
        errObj.innerText = "Please specify AM or PM";
        errObj.style.display = 'block';
        amPmErrorFlag[type] = true;
        return false; 
    }
    amPmErrorFlag[type] = false;
    clearError(inputEl.id, errId);
    let timePart = str.replace(/[^\d:]/g, '');
    let isPM = str.includes('pm');
    let parts = timePart.split(':');
    let h = parseInt(parts[0], 10);
    let m = parts[1] ? parseInt(parts[1], 10) : 0;
    if (isNaN(h) || isNaN(m)) return null;
    let rem = m % 15;
    if (rem <= 7) {
        m -= rem;
    } else {
        m += (15 - rem);
    }
    if (m >= 60) {
        m = 0;
        h += 1;
        if (h === 12) {
            isPM = !isPM;
        } else if (h > 12) {
            h = 1;
        }
    }
    let ampm = isPM ? 'PM' : 'AM';
    let formattedH = h % 12;
    formattedH = formattedH ? formattedH : 12;
    return (formattedH < 10 ? '0' + formattedH : formattedH) + ':' + (m < 10 ? '0' + m : m) + ' ' + ampm;
}

function handleTimeInputBlur(type) {
    let inputEl = document.getElementById(type + '-time-input');
    let hiddenEl = document.getElementById(type + '-time-hidden');
    let errId = 'err-' + type;
    let res = parseAndRoundTime(inputEl.value, inputEl, errId, type);
    if (res === false) {
        hiddenEl.value = "";
    } else if (res) {
        inputEl.value = res;
        hiddenEl.value = convertTo24Hour(res);
        if (type === 'start') {
            generateEndTimes();
        }
        calculateMealBreaks();
    } else {
        inputEl.value = "";
        hiddenEl.value = "";
        calculateMealBreaks();
    }
}

document.getElementById('start-time-input').addEventListener('blur', () => handleTimeInputBlur('start'));
document.getElementById('start-time-input').addEventListener('keydown', (e) => {
    if (e.key === 'Enter') { e.preventDefault(); e.target.blur(); }
});

document.getElementById('end-time-input').addEventListener('blur', () => handleTimeInputBlur('end'));
document.getElementById('end-time-input').addEventListener('keydown', (e) => {
    if (e.key === 'Enter') { e.preventDefault(); e.target.blur(); }
});

function getRoundedCurrentTime() {
    let now = new Date();
    let h = now.getHours();
    let m = now.getMinutes();
    let rem = m % 15;
    if (rem <= 7) {
        m -= rem;
    } else {
        m += (15 - rem);
    }
    if (m >= 60) {
        m = 0;
        h += 1;
    }
    if (h >= 24) h = 0;
    return { text: formatAMPM(h, m), val: (h < 10 ? '0'+h : h) + ':' + (m < 10 ? '0'+m : m) };
}

document.addEventListener('click', function(event) {
    if (!event.target.closest('.sel-wrap')) {
        document.querySelectorAll('.sel-wrap').forEach(w => w.classList.remove('open'));
    }
    if (!event.target.closest('.form-group')) {
        const std = document.getElementById('start-time-dropdown');
        const etd = document.getElementById('end-time-dropdown');
        if(std) std.classList.remove('show-dropdown');
        if(etd) etd.classList.remove('show-dropdown');
    }
});

function toggleSel(type) {
    const std = document.getElementById('start-time-dropdown');
    const etd = document.getElementById('end-time-dropdown');
    if(std) std.classList.remove('show-dropdown');
    if(etd) etd.classList.remove('show-dropdown');
    const wrap = document.getElementById(type+'-wrap');
    const isOpen = wrap.classList.contains('open');
    document.querySelectorAll('.sel-wrap').forEach(w => w.classList.remove('open'));
    if (!isOpen) {
        wrap.classList.add('open');
        document.getElementById(type+'-inner').focus();
    }
}

function filterSel(type) {
    const val = document.getElementById(type+'-inner').value.toLowerCase();
    document.querySelectorAll('#'+type+'-list .sel-item').forEach(item => {
        const kw = item.getAttribute('data-kw') || '';
        item.classList.toggle('hidden', !!val && !kw.includes(val));
    });
}

function populateMulti(listId, valString) {
    const list = document.getElementById(listId);
    list.innerHTML = '';
    const names = valString.split(',').map(s => s.trim()).filter(s => s);
    if (names.length === 0) names.push('');
    const nameMap = { 'iips-mgr-list':'iips_manager_multi[]', 'partner-list':'partner_multi[]' };
    names.forEach((n, idx) => {
        const div = document.createElement('div');
        div.className = 'multi-name-row';
        div.style.cssText = 'display:flex;gap:8px;margin-bottom:6px;';
        div.innerHTML = `<input type="text" name="${nameMap[listId]}" value="${n.replace(/"/g, '&quot;')}" placeholder="Enter name" style="flex:1;height:36px;padding:0 10px;border:1px solid #ced4da;border-radius:4px;font-size:13px;"><button type="button" onclick="removeName(this)" style="background:#dc3545;color:white;border:none;width:32px;border-radius:4px;cursor:pointer;font-size:16px;${idx===0 && names.length===1 ? 'visibility:hidden' : ''}">×</button>`;
        list.appendChild(div);
    });
}

function pickSel(type, value, label, el) {
    document.getElementById('hidden-project-id').value = value;
    document.getElementById(type+'-label').textContent = label;
    document.querySelectorAll('#'+type+'-list .sel-item').forEach(i => i.classList.remove('active'));
    el.classList.add('active');
    document.getElementById(type+'-wrap').classList.remove('open');
    document.getElementById(type+'-inner').value = '';
    filterSel(type);
    clearError('proj-box', 'err-proj');

    if (type === 'proj') {
        const mgr = el.getAttribute('data-iips-mgr') || '';
        const ptr = el.getAttribute('data-iips-ptr') || '';
        
        const mgrRadioYes = document.querySelector('input[name="has_iips_manager_radio"][value="yes"]');
        const mgrRadioNo = document.querySelector('input[name="has_iips_manager_radio"][value="no"]');
        const mgrField = document.getElementById('iips-mgr-field');
        
        if (mgr !== '') {
            mgrRadioYes.checked = true;
            mgrField.style.display = 'block';
            populateMulti('iips-mgr-list', mgr);
        } else {
            if(mgrRadioNo) mgrRadioNo.checked = true;
            mgrField.style.display = 'none';
            populateMulti('iips-mgr-list', '');
        }

        const ptrRadioYes = document.querySelector('input[name="has_partner_radio"][value="yes"]');
        const ptrRadioNo = document.querySelector('input[name="has_partner_radio"][value="no"]');
        const ptrField = document.getElementById('partner-field');

        if (ptr !== '') {
            ptrRadioYes.checked = true;
            ptrField.style.display = 'block';
            populateMulti('partner-list', ptr);
        } else {
            if(ptrRadioNo) ptrRadioNo.checked = true;
            ptrField.style.display = 'none';
            populateMulti('partner-list', '');
        }
    }
}

function toggleTimeDropdown(type, event) {
    event.stopPropagation();
    document.querySelectorAll('.sel-wrap').forEach(w => w.classList.remove('open'));
    if (amPmErrorFlag[type]) return;
    const startDropdown = document.getElementById('start-time-dropdown');
    const endDropdown = document.getElementById('end-time-dropdown');
    let targetDrop = null;
    let hiddenVal = document.getElementById(type + '-time-hidden').value;
    if (type === 'start') {
        endDropdown.classList.remove('show-dropdown');
        startDropdown.classList.toggle('show-dropdown');
        if(startDropdown.classList.contains('show-dropdown')) targetDrop = startDropdown;
    } else {
        startDropdown.classList.remove('show-dropdown');
        endDropdown.classList.toggle('show-dropdown');
        if(endDropdown.classList.contains('show-dropdown')) targetDrop = endDropdown;
    }
    if (targetDrop && hiddenVal) {
        targetDrop.querySelectorAll('.custom-option').forEach(opt => {
            opt.classList.remove('selected');
            if (opt.getAttribute('data-val') === hiddenVal) {
                opt.classList.add('selected');
                setTimeout(() => {
                    opt.scrollIntoView({ block: 'nearest' });
                }, 10);
            }
        });
    }
}

function formatAMPM(hours, minutes) {
    const ampm = hours >= 12 ? 'PM' : 'AM';
    let displayHours = hours % 12;
    displayHours = displayHours ? displayHours : 12; 
    const displayMinutes = minutes < 10 ? '0' + minutes : minutes;
    return displayHours + ':' + displayMinutes + ' ' + ampm;
}

function updateDurationPreview() {
    const sd = document.getElementById('date').value;
    const st = document.getElementById('start-time-hidden').value;
    const et = document.getElementById('end-time-hidden').value;
    const prev = document.getElementById('dur-preview');
    if (!sd || !st || !et) { 
        prev.textContent = ''; 
        prev.style.display = 'none';
        return; 
    }
    const start = new Date(sd + 'T' + st + ':00');
    let end = new Date(sd + 'T' + et + ':00');
    if (end <= start) {
        end.setDate(end.getDate() + 1);
    }
    let mealBreaks = parseInt(document.getElementById('actual_meal_breaks').value) || 0;
    let diff = end - start;
    diff = diff - (mealBreaks * 3600000); 
    if (diff < 0) diff = 0;
    const h = Math.floor(diff / 3600000);
    const m = Math.floor((diff % 3600000) / 60000);
    const days = Math.floor(h / 24);
    let t = '⏱ Duration: ';
    if (days > 0) t += days + 'd ';
    t += (h % 24) + 'h ' + m + 'm';
    prev.textContent = t;
    prev.style.display = 'block';
}

let currentMaxBreaks = 0;
function calculateMealBreaks() {
    const st = document.getElementById('start-time-hidden').value;
    const et = document.getElementById('end-time-hidden').value;
    if (!st || !et) return;
    const start = new Date("1970-01-01T" + st + ":00");
    let end = new Date("1970-01-01T" + et + ":00");
    if (end <= start) end.setDate(end.getDate() + 1);
    const diffHours = (end - start) / (1000 * 60 * 60);
    let maxBreaks = 0;
    if (diffHours >= 24) maxBreaks = 3;
    else if (diffHours > 16) maxBreaks = 2;
    else if (diffHours > 8) maxBreaks = 1;
    const container = document.getElementById('meal-break-container');
    const select = document.getElementById('meal_breaks_select');
    const checkbox = document.getElementById('meal_break_checkbox');
    const hiddenInput = document.getElementById('actual_meal_breaks');
    currentMaxBreaks = maxBreaks;
    if (maxBreaks > 0) {
        container.style.display = 'block';
        let oldVal = document.getElementById('actual_meal_breaks').value || select.value;
        select.innerHTML = '';
        if (maxBreaks > 1) {
            for (let i = 1; i <= maxBreaks; i++) {
                select.innerHTML += `<option value="${i}">${i}</option>`;
            }
            if (oldVal && oldVal <= maxBreaks && oldVal > 0) {
                select.value = oldVal;
            }
        }
        if (checkbox.checked) {
            if (maxBreaks > 1) {
                select.style.display = 'block';
                hiddenInput.value = select.value;
            } else {
                select.style.display = 'none';
                hiddenInput.value = "1";
            }
        } else {
            select.style.display = 'none';
            hiddenInput.value = "0";
        }
    } else {
        container.style.display = 'none';
        checkbox.checked = false;
        select.style.display = 'none';
        hiddenInput.value = "0";
    }
    updateDurationPreview();
}

function generateStartTimes() {
    const container = document.getElementById('start-time-dropdown');
    container.innerHTML = '';
    for (let minutes = 0; minutes < 24 * 60; minutes += 15) {
        const h = Math.floor(minutes / 60);
        const m = minutes % 60;
        const valStr = (h < 10 ? '0'+h : h) + ':' + (m < 10 ? '0'+m : m);
        const textStr = formatAMPM(h, m);
        const opt = document.createElement('div');
        opt.className = 'custom-option';
        opt.innerText = textStr;
        opt.setAttribute('data-val', valStr);
        opt.onclick = function() {
            amPmErrorFlag['start'] = false;
            document.getElementById('start-time-hidden').value = valStr;
            document.getElementById('start-time-input').value = textStr;
            document.getElementById('start-time-dropdown').classList.remove('show-dropdown');
            document.getElementById('end-time-hidden').value = "";
            document.getElementById('end-time-input').value = "";
            generateEndTimes();
            calculateMealBreaks();
            clearError('start-time-input', 'err-start');
        };
        container.appendChild(opt);
    }
}

function generateEndTimes() {
    const container = document.getElementById('end-time-dropdown');
    container.innerHTML = '';
    const dateInput = document.getElementById('date');
    let baseDateText = "Selected Date", nextDateText = "Next Day";
    if (dateInput && dateInput.value) {
        const d = new Date(dateInput.value);
        if (!isNaN(d.getTime())) {
            const months = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
            baseDateText = d.getDate() + ' ' + months[d.getMonth()];
            const nextD = new Date(d);
            nextD.setDate(d.getDate() + 1);
            nextDateText = nextD.getDate() + ' ' + months[nextD.getMonth()];
        }
    }
    const startTimeStr = document.getElementById('start-time-hidden').value;
    if (!startTimeStr) return; 
    const [startH, startM] = startTimeStr.split(':').map(Number);
    const startMins = startH * 60 + startM;
    let hasAddedNextDayHeader = false;
    const todayHeader = document.createElement('div');
    todayHeader.className = 'time-header';
    todayHeader.innerText = baseDateText;
    container.appendChild(todayHeader);
    for (let loopMins = startMins + 15; loopMins <= startMins + 24 * 60; loopMins += 15) {
        let currentDayMins = loopMins % (24 * 60);
        let h = Math.floor(currentDayMins / 60);
        let m = currentDayMins % 60;
        if (loopMins >= 24 * 60 && !hasAddedNextDayHeader) {
            const nextHeader = document.createElement('div');
            nextHeader.className = 'time-header';
            nextHeader.innerText = nextDateText;
            container.appendChild(nextHeader);
            hasAddedNextDayHeader = true;
        }
        const valStr = (h < 10 ? '0'+h : h) + ':' + (m < 10 ? '0'+m : m);
        const textStr = formatAMPM(h, m);
        const opt = document.createElement('div');
        opt.className = 'custom-option';
        opt.innerText = textStr;
        opt.setAttribute('data-val', valStr);
        opt.onclick = function() {
            amPmErrorFlag['end'] = false;
            document.getElementById('end-time-hidden').value = valStr;
            document.getElementById('end-time-input').value = textStr;
            document.getElementById('end-time-dropdown').classList.remove('show-dropdown');
            calculateMealBreaks();
            clearError('end-time-input', 'err-end');
        };
        container.appendChild(opt);
    }
}

document.getElementById('desc-input').addEventListener('input', function() {
    if(this.value.trim() !== '') {
        clearError('desc-input', 'err-desc');
    }
});

document.addEventListener("DOMContentLoaded", function() {
    updateDateDisplay();
    let initStart24 = "<?php echo htmlspecialchars(substr($sel_start_time, 0, 5)); ?>";
    if (initStart24) {
        document.getElementById('start-time-input').value = convertTo12Hour(initStart24);
    } else {
        let cur = getRoundedCurrentTime();
        document.getElementById('start-time-input').value = cur.text;
        document.getElementById('start-time-hidden').value = cur.val;
    }
    let initEnd24 = "<?php echo htmlspecialchars(substr($sel_end_time, 0, 5)); ?>";
    if (initEnd24) {
        document.getElementById('end-time-input').value = convertTo12Hour(initEnd24);
    }
    generateStartTimes();
    generateEndTimes();
    let initialMealBreaks = parseInt(document.getElementById('actual_meal_breaks').value) || 0;
    if (initialMealBreaks > 0) {
        document.getElementById('meal_break_checkbox').checked = true;
    }
    calculateMealBreaks();
    updateDurationPreview();
    document.getElementById('meal_break_checkbox').addEventListener('change', function() {
        const select = document.getElementById('meal_breaks_select');
        const hiddenInput = document.getElementById('actual_meal_breaks');
        if (this.checked) {
            if (currentMaxBreaks > 1) {
                select.style.display = 'block';
                hiddenInput.value = select.value;
            } else {
                select.style.display = 'none';
                hiddenInput.value = "1";
            }
        } else {
            select.style.display = 'none';
            hiddenInput.value = "0";
        }
        updateDurationPreview();
    });
    document.getElementById('meal_breaks_select').addEventListener('change', function() {
        document.getElementById('actual_meal_breaks').value = this.value;
        updateDurationPreview();
    });
    const hasConflict = <?php echo !empty($conflict_error) ? 'true' : 'false'; ?>;
    if (hasConflict) {
        const sInput = document.getElementById('start-time-input');
        const eInput = document.getElementById('end-time-input');
        if (sInput) sInput.classList.add('error-border');
        if (eInput) eInput.classList.add('error-border');
        const alertBox = document.getElementById('conflict-alert');
        if (alertBox) {
            alertBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }
});

document.getElementById('record-form').addEventListener('submit', function(e) {
    if (amPmErrorFlag['start'] || amPmErrorFlag['end']) {
        e.preventDefault();
        const inputToScroll = amPmErrorFlag['start'] ? document.getElementById('start-time-input') : document.getElementById('end-time-input');
        if(inputToScroll) inputToScroll.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
    }
    let isValid = true;
    let firstErr = null;
    function check(valId, visId, errId, textOverride = null) {
        const val = document.getElementById(valId).value.trim();
        const vis = document.getElementById(visId);
        const err = document.getElementById(errId);
        if (!val) {
            vis.classList.add('error-border');
            if(err) {
                if(textOverride) err.innerText = textOverride;
                err.style.display = 'block';
            }
            if(!firstErr) firstErr = vis;
            isValid = false;
        } else {
            vis.classList.remove('error-border');
            if(err) err.style.display = 'none';
        }
    }
    check('hidden-project-id', 'proj-box', 'err-proj');
    check('date', 'date-display', 'err-date');
    check('start-time-hidden', 'start-time-input', 'err-start', 'Please select a Start Time.');
    check('end-time-hidden', 'end-time-input', 'err-end', 'Please select an End Time.');
    check('desc-input', 'desc-input', 'err-desc');

    const iipsMgrPicked = document.querySelector('input[name="has_iips_manager_radio"]:checked');
    const partnerPicked = document.querySelector('input[name="has_partner_radio"]:checked');
    if (!iipsMgrPicked) {
        let el = document.querySelector('input[name="has_iips_manager_radio"]');
        if (!document.getElementById('err-iips-mgr')) {
            const err = document.createElement('div');
            err.id = 'err-iips-mgr';
            err.style.cssText = 'color:#dc2626;font-size:11px;font-weight:bold;margin-top:4px;';
            err.textContent = 'Please select Yes or No.';
            el.closest('.form-group').appendChild(err);
        }
        if (!firstErr) firstErr = el;
        isValid = false;
    } else {
        const e = document.getElementById('err-iips-mgr');
        if (e) e.remove();
    }
    if (!partnerPicked) {
        let el = document.querySelector('input[name="has_partner_radio"]');
        if (!document.getElementById('err-partner')) {
            const err = document.createElement('div');
            err.id = 'err-partner';
            err.style.cssText = 'color:#dc2626;font-size:11px;font-weight:bold;margin-top:4px;';
            err.textContent = 'Please select Yes or No.';
            el.closest('.form-group').appendChild(err);
        }
        if (!firstErr) firstErr = el;
        isValid = false;
    } else {
        const e = document.getElementById('err-partner');
        if (e) e.remove();
    }
    if (!isValid) {
        e.preventDefault();
        firstErr.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
});
</script>
</body>
</html>