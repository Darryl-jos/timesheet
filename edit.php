<?php
require_once 'config.php';

if (!isset($_SESSION['engineer_id'])) { 
    header("Location: login.php"); 
    exit; 
}

$current_user_id = $_SESSION['engineer_id'];
if (!isset($_GET['edit'])) { header("Location: index.php"); exit; }
$edit_id = intval($_GET['edit']);

$is_admin = isset($_SESSION['is_admin']) && ($_SESSION['is_admin'] == 1 || $_SESSION['is_admin'] == 2);

$return_url = $is_admin ? 'admin_timesheets.php' : 'index.php';
if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_SERVER['HTTP_REFERER'])) {
    $referer = basename(parse_url($_SERVER['HTTP_REFERER'], PHP_URL_PATH));
    if ($referer == 'index.php' || strpos($referer, 'admin') !== false) {
        $return_url = $referer;
    }
}

if ($is_admin) {
    $stmt = $conn->prepare("SELECT * FROM timesheets WHERE id = ?");
    $stmt->bind_param("i", $edit_id);
} else {
    $stmt = $conn->prepare("SELECT * FROM timesheets WHERE id = ? AND engineer_id = ?");
    $stmt->bind_param("ii", $edit_id, $current_user_id);
}
$stmt->execute();
$edit_data = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$edit_data) { header("Location: index.php"); exit; }

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $proj_id = $_POST['project_id'];
    $date = $_POST['date'];
    $s_time = $_POST['start_time']; 
    $e_time = $_POST['end_time'];   
    $work_desc = trim($_POST['work_description']); 
    $meal_breaks = isset($_POST['meal_breaks']) ? (int)$_POST['meal_breaks'] : 0;
    
    $final_return_url = isset($_POST['return_url']) ? $_POST['return_url'] : ($is_admin ? "admin_timesheets.php" : "index.php");
    
    $start_dt = new DateTime("$date $s_time");
    $end_dt = new DateTime("$date $e_time");
    
    if ($end_dt <= $start_dt) {
        $end_dt->modify('+1 day');
    }
    
    $diff_hours = ($end_dt->getTimestamp() - $start_dt->getTimestamp()) / 3600;
    
    $max_breaks = 0;
    if ($diff_hours >= 24) {
        $max_breaks = 3;
    } elseif ($diff_hours > 16) {
        $max_breaks = 2;
    } elseif ($diff_hours > 8) {
        $max_breaks = 1;
    }
    
    if ($meal_breaks > $max_breaks) {
        $meal_breaks = $max_breaks;
    }
    
    if ($meal_breaks > 0) {
        $end_dt->modify("-{$meal_breaks} hours");
    }
    
    $final_start_date = $start_dt->format('Y-m-d');
    $final_start_time = $start_dt->format('H:i:s');
    $final_end_date = $end_dt->format('Y-m-d');
    $final_end_time = $end_dt->format('H:i:s');
    
    $stmt = $conn->prepare("UPDATE timesheets SET project_id=?, start_date=?, start_time=?, end_date=?, end_time=?, work_description=? WHERE id=?");
    $stmt->bind_param("ssssssi", $proj_id, $final_start_date, $final_start_time, $final_end_date, $final_end_time, $work_desc, $edit_id);
    $stmt->execute();
    $stmt->close();

    header("Location: " . $final_return_url);
    exit;
}

$projects_res = $conn->query("SELECT p.* FROM projects p ORDER BY p.project_id ASC");
$current_selected_text = "-- Select Project --";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Edit Record</title>
<style>
* { box-sizing: border-box; }
body { font-family: Arial, sans-serif; margin: 30px; background: #f4f7f6; }

.topbar { background: #ffffff; padding: 15px 20px; display: flex; align-items: center; justify-content: space-between; border-radius: 8px; flex-wrap: wrap; gap: 10px; }
.topbar h2 { color: #1f2937; margin: 0; font-size: 18px; }
.topbar a { color: #007bff; font-weight: bold; text-decoration: none; font-size: 13px; }

.page { max-width: 900px; margin: 20px auto; padding: 0 20px 60px; }

.section { background: white; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.06); margin-bottom: 20px; overflow: visible; }
.section-hdr { background: #343a40; color: white; padding: 10px 20px; font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; border-top-left-radius: 8px; border-top-right-radius: 8px; }
.section-hdr.green { background: #155724; }
.section-body { padding: 20px; display: grid; grid-template-columns: 1fr 1fr; gap: 14px 20px; }
.section-body.one-col { grid-template-columns: 1fr; }

.form-group { display: flex; flex-direction: column; gap: 4px; }
.form-group label { font-size: 12px; font-weight: 700; color: #495057; }
.form-group input:not([type="checkbox"]), .form-group select, .form-group textarea {
    padding: 8px 10px; border: 1px solid #ced4da; border-radius: 4px; font-size: 13px; width: 100%;
}
.form-group input:not([type="checkbox"]), .form-group select { height: 36px; }
.form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color: #007bff; outline: none; box-shadow: 0 0 0 2px rgba(0,123,255,.15); }
.form-group textarea { resize: vertical; min-height: 100px; font-family: Arial, sans-serif; }

.check-group { display: flex; align-items: center; gap: 10px; padding-top: 8px; }
.check-group input[type="checkbox"] { width: 18px; height: 18px; cursor: pointer; margin: 0; }
.check-group label { font-size: 13px; font-weight: 600; color: #333; margin: 0; }

.actions { display: flex; gap: 12px; margin-top: 24px; }
.btn-save { background: #ffc107; color: white; border: none; padding: 0 28px; height: 40px; border-radius: 4px; font-size: 14px; font-weight: bold; cursor: pointer; }
.btn-save:hover { background: #218838; }
.btn-cancel { display: inline-flex; align-items: center; color: #6c757d; text-decoration: none; font-size: 13px; height: 40px; }

.custom-select-trigger { height: 36px; padding: 0 10px; border: 1px solid #ced4da; border-radius: 4px; background: #fff; cursor: pointer; display: flex; justify-content: space-between; align-items: center; font-size: 13px; box-sizing: border-box; }
.custom-select-trigger::after { content: ""; border-left: 5px solid transparent; border-right: 5px solid transparent; border-top: 5px solid #666; flex-shrink: 0; margin-left: 8px; }
.custom-select-dropdown { display: none; position: absolute; top: 100%; left: 0; width: 100%; background: #fff; border: 1px solid #007bff; border-radius: 4px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); z-index: 999; margin-top: 2px; padding: 8px; box-sizing: border-box; }
.search-bar { margin-bottom: 8px; border: 1px solid #ddd; font-size: 13px; height: 32px !important; }
.options-list { max-height: 200px; overflow-y: auto; }
.custom-option { padding: 8px 10px; cursor: pointer; font-size: 13px; border-radius: 3px; }
.custom-option:hover { background: #f0f7ff; color: #007bff; }
.custom-option.selected { background: #e6f0ff; color: #007bff; font-weight: bold; }
.show-dropdown { display: block !important; }
#start-time-dropdown, #end-time-dropdown { width: 100%; max-height: 220px; overflow-y: auto; padding: 5px 0; }
.time-header { background: #f8f9fa; padding: 6px 10px; font-size: 11px; font-weight: bold; color: #495057; position: sticky; top: 0; z-index: 10; border-bottom: 1px solid #e9ecef; }
.duration-preview { display: none; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 4px; padding: 8px 12px; font-size: 13px; color: #1e40af; font-weight: bold; text-align: center; margin-top: 8px; }

@media (max-width: 600px) {
    body { margin: 15px; }
    .section-body { grid-template-columns: 1fr; }
    .page { padding: 0 0 40px; }
}
</style>
</head>
<body>

<div class="topbar">
    <h2>✏️ Edit Record</h2>
    <a href="<?php echo htmlspecialchars($return_url); ?>">← Back to Dashboard</a>
</div>

<div class="page">
    <form method="POST" id="record-form">
        <input type="hidden" name="return_url" value="<?php echo htmlspecialchars($return_url); ?>">
        
        <div class="section">
            <div class="section-hdr">📁 Project Details</div>
            <div class="section-body one-col">
                <div class="form-group" style="position: relative;">
                    <label>Select Project <span style="color:#dc2626;">*</span></label>
                    <div class="custom-select-trigger" id="select-trigger" onclick="toggleDropdown(event)">
                        <?php 
                        while($p = $projects_res->fetch_assoc()) {
                            if ($edit_data['project_id'] == $p['project_id']) {
                                $display_id = (strpos($p['project_id'], 'N/A') === 0) ? "[-]" : "[" . $p['project_id'] . "]";
                                $current_selected_text = $display_id . " " . $p['project_name'] . " (Client: " . $p['customer_name'] . ")";
                            }
                        }
                        echo htmlspecialchars($current_selected_text);
                        $projects_res->data_seek(0);
                        ?>
                    </div>
                    <div class="custom-select-dropdown" id="select-dropdown">
                        <input type="text" id="project-search" class="search-bar" onkeyup="filterProjects()" placeholder="🔍 Type ID, Name or Client to filter..." autocomplete="off">
                        <div class="options-list">
                            <?php while($p = $projects_res->fetch_assoc()): ?>
                                <?php 
                                $search_haystack = strtolower("[".$p['project_id']."] ".$p['project_name']." ".$p['customer_name']); 
                                $is_selected = ($edit_data['project_id'] == $p['project_id']) ? 'selected' : '';
                                ?>
                                <div class="custom-option <?php echo $is_selected; ?>" data-value="<?php echo htmlspecialchars($p['project_id']); ?>" data-keywords="<?php echo htmlspecialchars($search_haystack); ?>" onclick="selectOption(this)">
                                    <?php 
                                    $display_id = (strpos($p['project_id'], 'N/A') === 0) ? "[-]" : "[" . htmlspecialchars($p['project_id']) . "]";
                                    echo $display_id . " " . htmlspecialchars($p['project_name']) . " (Client: " . htmlspecialchars($p['customer_name']) . ")"; 
                                    ?>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    </div>
                    <input type="hidden" name="project_id" id="hidden-project-id" value="<?php echo htmlspecialchars($edit_data['project_id']); ?>" required>
                </div>

                <div class="form-group">
                    <label>Date <span style="color:#dc2626;">*</span></label>
                    <input type="date" name="date" id="date" value="<?php echo htmlspecialchars($edit_data['start_date']); ?>" required>
                </div>
            </div>
        </div>

        <div class="section">
            <div class="section-hdr green">⏱️ Time & Activity</div>
            <div class="section-body one-col">
                <div class="form-group">
                    <label>Time Range <span style="color:#dc2626;">*</span></label>
                    <div style="display: flex; gap: 10px; position: relative;">
                        <div style="flex: 1; position: relative;">
                            <div class="custom-select-trigger" id="start-time-trigger" onclick="toggleTimeDropdown('start', event)"></div>
                            <div class="custom-select-dropdown" id="start-time-dropdown"></div>
                            <input type="hidden" name="start_time" id="start-time-hidden" value="<?php echo htmlspecialchars(substr($edit_data['start_time'], 0, 5)); ?>" required>
                        </div>
                        <span style="align-self: center; font-size: 13px; color: #495057;">to</span>
                        <div style="flex: 1; position: relative;">
                            <div class="custom-select-trigger" id="end-time-trigger" onclick="toggleTimeDropdown('end', event)"></div>
                            <div class="custom-select-dropdown" id="end-time-dropdown"></div>
                            <input type="hidden" name="end_time" id="end-time-hidden" value="<?php echo htmlspecialchars(substr($edit_data['end_time'], 0, 5)); ?>" required>
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
                    <input type="hidden" name="meal_breaks" id="actual_meal_breaks" value="0">
                </div>

                <div class="form-group">
                    <label>Activity (Work Description) <span style="color:#dc2626;">*</span></label>
                    <textarea name="work_description" required placeholder="Detail exactly what steps or technical activities you conducted during this shift..."><?php echo htmlspecialchars($edit_data['work_description']); ?></textarea>
                </div>
            </div>
        </div>

        <div class="actions">
            <button type="submit" class="btn-save">Update Record</button>
            <a href="<?php echo htmlspecialchars($return_url); ?>" class="btn-cancel">Cancel</a>
        </div>
    </form>
</div>

<script>
window.onclick = function(event) {
    if (!event.target.closest('.form-group')) {
        document.getElementById('select-dropdown').classList.remove('show-dropdown');
        document.getElementById('start-time-dropdown').classList.remove('show-dropdown');
        document.getElementById('end-time-dropdown').classList.remove('show-dropdown');
    }
}

function toggleDropdown(event) {
    event.stopPropagation();
    document.getElementById('start-time-dropdown').classList.remove('show-dropdown');
    document.getElementById('end-time-dropdown').classList.remove('show-dropdown');
    
    const projDropdown = document.getElementById('select-dropdown');
    projDropdown.classList.toggle('show-dropdown');
    if (projDropdown.classList.contains('show-dropdown')) {
        document.getElementById('project-search').focus();
    }
}

function selectOption(element) {
    document.getElementById('hidden-project-id').value = element.getAttribute('data-value');
    document.getElementById('select-trigger').innerText = element.innerText;
    document.getElementById('select-dropdown').classList.remove('show-dropdown');

    document.querySelectorAll('#select-dropdown .custom-option').forEach(opt => opt.classList.remove('selected'));
    element.classList.add('selected');
}

function filterProjects() {
    const filterValue = document.getElementById('project-search').value.toLowerCase();
    document.querySelectorAll('#select-dropdown .custom-option').forEach(option => {
        const keywords = option.getAttribute('data-keywords') || "";
        option.style.display = keywords.indexOf(filterValue) > -1 ? "block" : "none";
    });
}

function toggleTimeDropdown(type, event) {
    event.stopPropagation();
    document.getElementById('select-dropdown').classList.remove('show-dropdown');

    const startDropdown = document.getElementById('start-time-dropdown');
    const endDropdown = document.getElementById('end-time-dropdown');

    if (type === 'start') {
        endDropdown.classList.remove('show-dropdown');
        startDropdown.classList.toggle('show-dropdown');
    } else {
        startDropdown.classList.remove('show-dropdown');
        endDropdown.classList.toggle('show-dropdown');
    }
}

function formatAMPM(hours, minutes) {
    const ampm = hours >= 12 ? 'PM' : 'AM';
    let displayHours = hours % 12;
    displayHours = displayHours ? displayHours : 12; 
    const displayMinutes = minutes < 10 ? '0' + minutes : minutes;
    return displayHours + ':' + displayMinutes + ' ' + ampm;
}

function getNearest15Min() {
    const now = new Date();
    let h = now.getHours();
    let m = now.getMinutes();
    let remainder = m % 15;

    if (remainder > 0) {
        m += (15 - remainder);
    }
    if (m === 60) {
        m = 0;
        h += 1;
    }
    if (h >= 24) h = 0;

    const valStr = (h < 10 ? '0'+h : h) + ':' + (m < 10 ? '0'+m : m);
    return { valStr: valStr, textStr: formatAMPM(h, m) };
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
        
        let oldVal = select.value;
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
    const currentVal = document.getElementById('start-time-hidden').value;

    for (let minutes = 0; minutes < 24 * 60; minutes += 15) {
        const h = Math.floor(minutes / 60);
        const m = minutes % 60;
        const valStr = (h < 10 ? '0'+h : h) + ':' + (m < 10 ? '0'+m : m);
        const textStr = formatAMPM(h, m);

        const opt = document.createElement('div');
        opt.className = 'custom-option' + (currentVal === valStr ? ' selected' : '');
        opt.innerText = textStr;
        opt.onclick = function() {
            document.getElementById('start-time-hidden').value = valStr;
            document.getElementById('start-time-trigger').innerText = textStr;
            document.getElementById('start-time-dropdown').classList.remove('show-dropdown');
            
            document.getElementById('end-time-hidden').value = "";
            document.getElementById('end-time-trigger').innerText = "-- Select End Time --";
            generateEndTimes();
            calculateMealBreaks();
        };
        container.appendChild(opt);
    }
}

function generateEndTimes() {
    const container = document.getElementById('end-time-dropdown');
    container.innerHTML = '';

    const dateInput = document.getElementById('date');
    let baseDateText = "Today", nextDateText = "Tomorrow";

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
        opt.onclick = function() {
            document.getElementById('end-time-hidden').value = valStr;
            document.getElementById('end-time-trigger').innerText = textStr;
            document.getElementById('end-time-dropdown').classList.remove('show-dropdown');
            calculateMealBreaks();
        };
        container.appendChild(opt);
    }
}

document.addEventListener("DOMContentLoaded", function() {
    const stVal = document.getElementById('start-time-hidden').value;
    if (stVal) {
        const [sh, sm] = stVal.split(':').map(Number);
        document.getElementById('start-time-trigger').innerText = formatAMPM(sh, sm);
    }

    const etVal = document.getElementById('end-time-hidden').value;
    if (etVal) {
        const [eh, em] = etVal.split(':').map(Number);
        document.getElementById('end-time-trigger').innerText = formatAMPM(eh, em);
    }

    generateStartTimes();
    generateEndTimes();
    calculateMealBreaks();
    updateDurationPreview();

    const dateInput = document.getElementById('date');
    if(dateInput) {
        dateInput.addEventListener('change', function() {
            generateEndTimes();
            calculateMealBreaks();
        });
    }

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
});

document.getElementById('record-form').addEventListener('submit', function(e) {
    if (!document.getElementById('hidden-project-id').value) {
        alert("Please select a Project.");
        e.preventDefault();
    } else if (!document.getElementById('end-time-hidden').value) {
        alert("Please select an End Time.");
        e.preventDefault();
    }
});
</script>
</body>
</html>