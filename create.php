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

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $proj_id = $_POST['project_id'];
    $date = $_POST['date'];
    $s_time = $_POST['start_time']; 
    $e_time = $_POST['end_time'];   
    $work_desc = trim($_POST['work_description']); 
    
    $stmt = $conn->prepare("INSERT INTO timesheets (engineer_id, engineer_name, project_id, date, start_time, end_time, work_description) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("issssss", $current_user_id, $current_user_name, $proj_id, $date, $s_time, $e_time, $work_desc);
    $stmt->execute();
    $stmt->close();

    header("Location: index.php");
    exit;
}

$projects_res = $conn->query("
    SELECT p.* FROM projects p 
    ORDER BY p.project_id ASC
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Create Record</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f7f6; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; padding: 20px 0; box-sizing: border-box; }
        .card { background: white; padding: 30px; border-radius: 8px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); width: 450px; }
        .form-group { margin-bottom: 15px; position: relative; }
        label { display: block; margin-bottom: 5px; font-weight: bold; font-size: 14px; }
        input, textarea { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        textarea { resize: vertical; min-height: 80px; font-family: Arial, sans-serif; }
        .custom-select-trigger {
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
            background: #fff;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 14px;
        }
        .custom-select-trigger::after {
            content: "";
            border-left: 5px solid transparent;
            border-right: 5px solid transparent;
            border-top: 5px solid #666;
        }
        .custom-select-dropdown {
            display: none;
            position: absolute;
            top: 100%;
            left: 0;
            width: 100%;
            background: #fff;
            border: 1px solid #007bff;
            border-radius: 4px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            z-index: 999;
            margin-top: 2px;
            padding: 8px;
            box-sizing: border-box;
        }
        .search-bar {
            margin-bottom: 8px;
            border: 1px solid #ddd;
            font-size: 13px;
        }
        .search-bar:focus {
            border-color: #007bff;
            outline: none;
        }
        .options-list {
            max-height: 200px;
            overflow-y: auto;
        }
        .custom-option {
            padding: 8px 10px;
            cursor: pointer;
            font-size: 13px;
            border-radius: 3px; 
        }
        .custom-option:hover {
            background-color: #f1f1f1;
            color: #007bff;
        }
        .custom-option.selected {
            background-color: #e6f0ff;
            color: #007bff;
            font-weight: bold;
        }
        .show-dropdown { display: block !important; }
        button[type="submit"] { background: #28a745; color: white; border: none; padding: 12px; border-radius: 4px; cursor: pointer; width: 100%; font-size: 16px; margin-top: 10px; font-weight: bold; }
        button[type="submit"]:hover { background: #218838; }
        .btn-cancel { display: block; text-align: center; margin-top: 15px; color: #6c757d; text-decoration: none; font-size: 14px; }
    
        #start-time-dropdown, #end-time-dropdown {
            width: 100%;
            max-height: 220px;
            overflow-y: auto;
            padding: 5px 0;
        }
        .time-header {
            background: #f1f1f1;
            padding: 6px 10px;
            font-size: 11px;
            font-weight: bold;
            color: #666;
            position: sticky;
            top: 0;
            z-index: 10;
        }
    </style>
</head>
<body>
<div class="card">
    <h2>Create New Record</h2>
    <form method="POST" id="record-form">
        <div class="form-group">
            <label>Select Project:</label>
            <div class="custom-select-trigger" id="select-trigger" onclick="toggleDropdown(event)">
                -- Select Project --
            </div>
            <div class="custom-select-dropdown" id="select-dropdown">
                <input type="text" id="project-search" class="search-bar" onkeyup="filterProjects()" placeholder="🔍 Type ID, Name or Client to filter..." autocomplete="off">
                <div class="options-list">
                    <?php while($p = $projects_res->fetch_assoc()): ?>
                        <?php $search_haystack = strtolower("[".$p['project_id']."] ".$p['project_name']." ".$p['customer_name']); ?>
                        <div class="custom-option" data-value="<?php echo htmlspecialchars($p['project_id']); ?>" data-keywords="<?php echo htmlspecialchars($search_haystack); ?>" onclick="selectOption(this)">
                            <?php echo "[".htmlspecialchars($p['project_id'])."] " . htmlspecialchars($p['project_name']) . " (Client: " . htmlspecialchars($p['customer_name']) . ")"; ?>
                        </div>
                    <?php endwhile; ?>
                </div>
            </div>
            <input type="hidden" name="project_id" id="hidden-project-id" required>
        </div>

        <div class="form-group">
            <label>Date:</label>
            <input type="date" name="date" id="date" value="<?php echo date('Y-m-d'); ?>" required>
        </div>

        <div class="form-group">
            <label>Time Range:</label>
            <div style="display: flex; gap: 10px; position: relative;">
                <div style="flex: 1; position: relative;">
                    <div class="custom-select-trigger" id="start-time-trigger" onclick="toggleTimeDropdown('start', event)"></div>
                    <div class="custom-select-dropdown" id="start-time-dropdown" style="max-height: 250px; overflow-y: auto;"></div>
                    <input type="hidden" name="start_time" id="start-time-hidden" required>
                </div>

                <span style="align-self: center;">to</span>

                <div style="flex: 1; position: relative;">
                    <div class="custom-select-trigger" id="end-time-trigger" onclick="toggleTimeDropdown('end', event)">
                        -- Select End Time --
                    </div>
                    <div class="custom-select-dropdown" id="end-time-dropdown" style="max-height: 250px; overflow-y: auto;"></div>
                    <input type="hidden" name="end_time" id="end-time-hidden" required>
                </div>
            </div>
        </div>

        <div class="form-group">
            <label>Activity (Work Description):</label>
            <textarea name="work_description" required placeholder="Detail exactly what steps or technical activities you conducted during this shift..."></textarea>
        </div>

        <button type="submit">Save Record</button>
        <a href="index.php" class="btn-cancel">Back to Dashboard</a>
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
    return `${displayHours}:${displayMinutes} ${ampm}`;
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

    const valStr = `${h < 10 ? '0'+h : h}:${m < 10 ? '0'+m : m}`;
    return { valStr, textStr: formatAMPM(h, m) };
}

function generateStartTimes() {
    const container = document.getElementById('start-time-dropdown');
    container.innerHTML = '';
    const currentVal = document.getElementById('start-time-hidden').value;

    for (let minutes = 0; minutes < 24 * 60; minutes += 15) {
        const h = Math.floor(minutes / 60);
        const m = minutes % 60;
        const valStr = `${h < 10 ? '0'+h : h}:${m < 10 ? '0'+m : m}`;
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
            baseDateText = `${d.getDate()} ${months[d.getMonth()]}`;
            const nextD = new Date(d);
            nextD.setDate(d.getDate() + 1);
            nextDateText = `${nextD.getDate()} ${months[nextD.getMonth()]}`;
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

        const valStr = `${h < 10 ? '0'+h : h}:${m < 10 ? '0'+m : m}`;
        const textStr = formatAMPM(h, m);

        const opt = document.createElement('div');
        opt.className = 'custom-option';
        opt.innerText = textStr;
        opt.onclick = function() {
            document.getElementById('end-time-hidden').value = valStr;
            document.getElementById('end-time-trigger').innerText = textStr;
            document.getElementById('end-time-dropdown').classList.remove('show-dropdown');
        };
        container.appendChild(opt);
    }
}

document.addEventListener("DOMContentLoaded", function() {
    const nearestTime = getNearest15Min();
    document.getElementById('start-time-hidden').value = nearestTime.valStr;
    document.getElementById('start-time-trigger').innerText = nearestTime.textStr;

    generateStartTimes();
    generateEndTimes();

    const dateInput = document.getElementById('date');
    if(dateInput) {
        dateInput.addEventListener('change', generateEndTimes);
    }
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