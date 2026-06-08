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
    $iips_mgr_name = trim($_POST['iips_manager_name'] ?? '');
    
    $has_partner = (($_POST['has_partner_radio'] ?? 'no') === 'yes') ? 1 : 0;
    $partner_name = trim($_POST['partner_name'] ?? '');
    
    if (!empty($sel_date) && !empty($sel_start_time) && !empty($sel_end_time)) {
        $start_dt = new DateTime("$sel_date $sel_start_time");
        $end_dt = new DateTime("$sel_date $sel_end_time");
        if ($end_dt <= $start_dt) {
            $end_dt->modify('+1 day');
        }
        
        $diff = $start_dt->diff($end_dt);
        $diff_hours = ($diff->days * 24) + $diff->h + ($diff->i / 60);

        $max_breaks = 0;
        if ($diff_hours >= 24) $max_breaks = 3;
        elseif ($diff_hours > 16) $max_breaks = 2;
        elseif ($diff_hours > 8) $max_breaks = 1;
        
        if ($sel_meal_breaks > $max_breaks) $sel_meal_breaks = $max_breaks;
        if ($sel_meal_breaks > 0) $end_dt->modify("-{$sel_meal_breaks} hours");
        
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
            $stmt = $conn->prepare("INSERT INTO timesheets (engineer_id, engineer_name, project_id, start_date, start_time, end_date, end_time, work_description) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("isssssss", $current_user_id, $current_user_name, $sel_proj_id, $final_start_date, $final_start_time, $final_end_date, $final_end_time, $sel_work_desc);
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
                    $upd2 = $conn->prepare("UPDATE iips_tracking SET partner=? WHERE project_id=?");
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
                            <input type="radio" id="mgr-yes" name="has_iips_manager_radio" value="yes" onclick="document.getElementById('iips-mgr-field').style.display='block'"> Yes
                        </label>
                        <label style="display:flex;align-items:center;gap:6px;font-weight:400;font-size:14px;cursor:pointer;">
                            <input type="radio" id="mgr-no" name="has_iips_manager_radio" value="no" onclick="document.getElementById('iips-mgr-field').style.display='none'" checked> No
                        </label>
                    </div>
                    <div id="iips-mgr-field" style="display:none;margin-top:10px;">
                        <input type="text" id="mgr-input" name="iips_manager_name" placeholder="Enter IIPS Manager name" style="width:100%;height:38px;padding:0 10px;border:1px solid #ced4da;border-radius:4px;font-size:13px;">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Do you have a Partner?</label>
                    <div style="display:flex;gap:20px;margin-top:6px;">
                        <label style="display:flex;align-items:center;gap:6px;font-weight:400;font-size:14px;cursor:pointer;">
                            <input type="radio" id="ptr-yes" name="has_partner_radio" value="yes" onclick="document.getElementById('partner-field').style.display='block'"> Yes
                        </label>
                        <label style="display:flex;align-items:center;gap:6px;font-weight:400;font-size:14px;cursor:pointer;">
                            <input type="radio" id="ptr-no" name="has_partner_radio" value="no" onclick="document.getElementById('partner-field').style.display='none'" checked> No
                        </label>
                    </div>
                    <div id="partner-field" style="display:none;margin-top:10px;">
                        <input type="text" id="ptr-input" name="partner_name" placeholder="Enter Partner name" style="width:100%;height:38px;padding:0 10px;border:1px solid #ced4da;border-radius:4px;font-size:13px;">
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
function toggleSel(type) {
    const wrap = document.getElementById(type + '-wrap');
    const isOpen = wrap.classList.contains('open');
    wrap.classList.toggle('open', !isOpen);
    if (!isOpen) document.getElementById(type + '-inner').focus();
}

function filterSel(type) {
    const val = document.getElementById(type + '-inner').value.toLowerCase();
    document.querySelectorAll('#' + type + '-list .sel-item').forEach(item => {
        item.classList.toggle('hidden', val && !(item.dataset.kw || '').includes(val));
    });
}

function syncDateFromPicker() {
    const dateInput = document.getElementById('date').value;
    if (dateInput) {
        const parts = dateInput.split('-');
        const months = ['JAN','FEB','MAR','APR','MAY','JUN','JUL','AUG','SEP','OCT','NOV','DEC'];
        document.getElementById('date-display').value = parseInt(parts[2]) + ' ' + months[parseInt(parts[1])-1] + ' ' + parts[0];
        document.getElementById('err-date').style.display = 'none';
        document.getElementById('date-display').classList.remove('error-border');
    }
}

function pickSel(type, value, label, el) {
    if (type === 'proj') {
        document.getElementById('proj-label').textContent = label;
        document.getElementById('hidden-project-id').value = value;
        document.querySelectorAll('#proj-list .sel-item').forEach(i => i.classList.remove('active'));
        el.classList.add('active');
        document.getElementById('proj-wrap').classList.remove('open');
        document.getElementById('proj-inner').value = '';
        filterSel('proj');
        document.getElementById('err-proj').style.display = 'none';
        document.getElementById('proj-box').classList.remove('error-border');

        let mgr = el.getAttribute('data-iips-mgr') || '';
        let radYes = document.getElementById('mgr-yes');
        let radNo = document.getElementById('mgr-no');
        let mgrInput = document.getElementById('mgr-input');
        let mgrField = document.getElementById('iips-mgr-field');

        if (mgr.trim() !== '') {
            radYes.checked = true;
            mgrField.style.display = 'block';
            mgrInput.value = mgr;
            radYes.disabled = true;
            radNo.disabled = true;
            mgrInput.readOnly = true;
            mgrInput.style.backgroundColor = '#e9ecef';
            mgrInput.style.color = '#6c757d';
            mgrInput.style.pointerEvents = 'none';
        } else {
            radYes.checked = false;
            radNo.checked = true;
            mgrField.style.display = 'none';
            mgrInput.value = '';
            radYes.disabled = false;
            radNo.disabled = false;
            mgrInput.readOnly = false;
            mgrInput.style.backgroundColor = '';
            mgrInput.style.color = '';
            mgrInput.style.pointerEvents = 'auto';
        }

        let ptrYes = document.getElementById('ptr-yes');
        let ptrNo = document.getElementById('ptr-no');
        let ptrInput = document.getElementById('ptr-input');
        let ptrField = document.getElementById('partner-field');
        
        ptrYes.checked = false;
        ptrNo.checked = true;
        ptrField.style.display = 'none';
        ptrInput.value = '';
    }
}

document.addEventListener('click', function(e) {
    if (!e.target.closest('.sel-wrap')) {
        document.querySelectorAll('.sel-wrap').forEach(w => w.classList.remove('open'));
    }
    if (!e.target.closest('.time-input-wrap') && !e.target.closest('.custom-select-dropdown')) {
        document.querySelectorAll('.custom-select-dropdown').forEach(d => d.classList.remove('show-dropdown'));
    }
});

function toggleTimeDropdown(type, event) {
    event.stopPropagation();
    const dd = document.getElementById(type + '-time-dropdown');
    const isOpen = dd.classList.contains('show-dropdown');
    
    document.querySelectorAll('.custom-select-dropdown').forEach(d => d.classList.remove('show-dropdown'));
    
    if (!isOpen) {
        dd.classList.add('show-dropdown');
        if (!dd.innerHTML) buildTimeDropdowns();
    }
}

function selectTime(type, time24, labelStr) {
    document.getElementById(type + '-time-input').value = labelStr;
    document.getElementById(type + '-time-hidden').value = time24;
    document.getElementById(type + '-time-dropdown').classList.remove('show-dropdown');
    document.getElementById('err-' + type).style.display = 'none';
    document.getElementById(type + '-time-input').classList.remove('error-border');
    calcDuration();
}

function buildTimeDropdowns() {
    const html = [];
    for (let h = 0; h < 24; h++) {
        let period = h < 12 ? 'AM' : 'PM';
        let displayH = h % 12 || 12;
        let pLabel = (h === 0 ? "Midnight" : (h === 12 ? "Noon" : ""));
        if(pLabel) html.push(`<div class="time-header">${pLabel}</div>`);
        
        ['00', '30'].forEach(m => {
            let t24 = h.toString().padStart(2, '0') + ':' + m;
            let display = `${displayH}:${m} ${period}`;
            html.push(`<div class="custom-option" onclick="selectTime('START', '${t24}', '${display}')">${display}</div>`);
        });
    }
    let stHtml = html.join('').replace(/START/g, 'start');
    let enHtml = html.join('').replace(/START/g, 'end');
    document.getElementById('start-time-dropdown').innerHTML = stHtml;
    document.getElementById('end-time-dropdown').innerHTML = enHtml;
}

function calcDuration() {
    let st = document.getElementById('start-time-hidden').value;
    let et = document.getElementById('end-time-hidden').value;
    if(!st || !et) return;
    
    let ds = new Date("2000-01-01T" + st + ":00");
    let de = new Date("2000-01-01T" + et + ":00");
    if(de <= ds) de.setDate(de.getDate() + 1);
    
    let diff = (de - ds) / 3600000;
    let text = diff + " Hours";
    
    document.getElementById('dur-preview').textContent = "Duration: " + text;
    document.getElementById('dur-preview').style.display = "block";
}

document.getElementById('record-form').addEventListener('submit', function(e) {
    let valid = true;
    let proj = document.getElementById('hidden-project-id').value;
    let date = document.getElementById('date').value;
    let start = document.getElementById('start-time-hidden').value;
    let end = document.getElementById('end-time-hidden').value;
    let desc = document.getElementById('desc-input').value.trim();

    if(!proj) { document.getElementById('err-proj').style.display='block'; document.getElementById('proj-box').classList.add('error-border'); valid=false; }
    if(!date) { document.getElementById('err-date').style.display='block'; document.getElementById('date-display').classList.add('error-border'); valid=false; }
    if(!start) { document.getElementById('err-start').style.display='block'; document.getElementById('start-time-input').classList.add('error-border'); valid=false; }
    if(!end) { document.getElementById('err-end').style.display='block'; document.getElementById('end-time-input').classList.add('error-border'); valid=false; }
    if(!desc) { document.getElementById('err-desc').style.display='block'; document.getElementById('desc-input').classList.add('error-border'); valid=false; }

    if(!valid) e.preventDefault();
});
</script>
</body>
</html>