<?php
require_once 'config.php';

if (!isset($_SESSION['engineer_id'])) { 
    header("Location: login.php"); 
    exit; 
}

$current_user_id = $_SESSION['engineer_id'];
if (!isset($_GET['edit'])) { header("Location: index.php"); exit; }
$edit_id = intval($_GET['edit']);

// Admin can edit any row; engineers can only edit their own
$is_admin = isset($_SESSION['is_admin']) && ($_SESSION['is_admin'] == 1 || $_SESSION['is_admin'] == 2);

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
    $proj_id    = $_POST['project_id'];
    $start_date = $_POST['start_date'];
    $s_time     = $_POST['start_time'];
    $end_date   = $_POST['end_date'];
    $e_time     = $_POST['end_time'];
    $work_desc  = trim($_POST['work_description']);

    $stmt = $conn->prepare("UPDATE timesheets SET project_id=?, start_date=?, start_time=?, end_date=?, end_time=?, work_description=? WHERE id=?");
    $stmt->bind_param("ssssssi", $proj_id, $start_date, $s_time, $end_date, $e_time, $work_desc, $edit_id);
    $stmt->execute();
    $stmt->close();

    header("Location: " . ($is_admin ? "admin_timesheets.php" : "index.php"));
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
    <title>Edit Timesheet Record</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f7f6; display: flex; justify-content: center; align-items: flex-start; min-height: 100vh; margin: 0; padding: 30px 15px; box-sizing: border-box; }
        .card { background: white; padding: 30px; border-radius: 8px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); width: 100%; max-width: 500px; }
        .form-group { margin-bottom: 16px; position: relative; }
        label { display: block; margin-bottom: 5px; font-weight: bold; font-size: 14px; }
        input, textarea { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; font-size: 14px; }
        textarea { resize: vertical; min-height: 80px; font-family: Arial, sans-serif; }
        .datetime-row { display: flex; gap: 10px; }
        .datetime-row .dt-group { flex: 1; }
        .datetime-row .dt-group label { font-size: 12px; color: #555; margin-bottom: 3px; }
        .custom-select-trigger { padding: 10px; border: 1px solid #ccc; border-radius: 4px; background: #fff; cursor: pointer; display: flex; justify-content: space-between; align-items: center; font-size: 13px; min-height: 42px; }
        .custom-select-trigger::after { content: ""; border-left: 5px solid transparent; border-right: 5px solid transparent; border-top: 5px solid #666; flex-shrink: 0; margin-left: 8px; }
        .custom-select-dropdown { display: none; position: absolute; top: 100%; left: 0; width: 100%; background: #fff; border: 1px solid #ffc107; border-radius: 4px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); z-index: 999; margin-top: 2px; padding: 8px; box-sizing: border-box; }
        .search-bar { margin-bottom: 8px; border: 1px solid #ddd; font-size: 13px; }
        .options-list { max-height: 200px; overflow-y: auto; }
        .custom-option { padding: 8px 10px; cursor: pointer; font-size: 13px; border-radius: 3px; }
        .custom-option:hover { background: #fff9e6; color: #b45309; }
        .custom-option.selected { background: #fff9e6; color: #b45309; font-weight: bold; }
        .show-dropdown { display: block !important; }
        button[type="submit"] { background: #ffc107; color: #333; border: none; padding: 12px; border-radius: 4px; cursor: pointer; width: 100%; font-size: 16px; font-weight: bold; margin-top: 10px; }
        button[type="submit"]:hover { background: #e0a800; }
        .btn-cancel { display: block; text-align: center; margin-top: 15px; color: #6c757d; text-decoration: none; font-size: 14px; }
        .duration-preview { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 4px; padding: 8px 12px; font-size: 13px; color: #166534; font-weight: bold; text-align: center; margin-top: 8px; }
    </style>
</head>
<body>
<div class="card">
    <h2 style="margin-top:0; margin-bottom:20px; color:#b45309;">✏️ Edit Timesheet Record</h2>
    <form method="POST">

        <div class="form-group">
            <label>Select Project:</label>
            <div class="custom-select-trigger" id="select-trigger" onclick="toggleDropdown(event)">
                <span id="trigger-text">-- Select Project --</span>
            </div>
            <div class="custom-select-dropdown" id="select-dropdown">
                <input type="text" id="project-search" class="search-bar" onkeyup="filterProjects()" placeholder="🔍 Type ID, Name or Client..." autocomplete="off">
                <div class="options-list">
                    <?php while($p = $projects_res->fetch_assoc()):
                        $kw = strtolower("[".$p['project_id']."] ".$p['project_name']." ".$p['customer_name']);
                        $sel = ($edit_data['project_id'] == $p['project_id']);
                        if ($sel) $current_selected_text = "[".$p['project_id']."] ".$p['project_name']." (".$p['customer_name'].")";
                    ?>
                        <div class="custom-option <?= $sel ? 'selected' : '' ?>"
                             data-value="<?= htmlspecialchars($p['project_id']) ?>"
                             data-keywords="<?= htmlspecialchars($kw) ?>"
                             onclick="selectOption(this)">
                            [<?= htmlspecialchars($p['project_id']) ?>] <?= htmlspecialchars($p['project_name']) ?> <span style="color:#888;">(<?= htmlspecialchars($p['customer_name']) ?>)</span>
                        </div>
                    <?php endwhile; ?>
                </div>
            </div>
            <input type="hidden" name="project_id" id="hidden-project-id" value="<?= htmlspecialchars($edit_data['project_id']) ?>" required>
        </div>

        <!-- Start -->
        <div class="form-group">
            <label>Start:</label>
            <div class="datetime-row">
                <div class="dt-group">
                    <label>Date</label>
                    <input type="date" name="start_date" id="start_date" value="<?= htmlspecialchars($edit_data['start_date']) ?>" required onchange="updatePreview()">
                </div>
                <div class="dt-group">
                    <label>Time</label>
                    <input type="time" name="start_time" id="start_time" value="<?= htmlspecialchars(substr($edit_data['start_time'],0,5)) ?>" required onchange="updatePreview()">
                </div>
            </div>
        </div>

        <!-- End -->
        <div class="form-group">
            <label>End:</label>
            <div class="datetime-row">
                <div class="dt-group">
                    <label>Date</label>
                    <input type="date" name="end_date" id="end_date" value="<?= htmlspecialchars($edit_data['end_date']) ?>" required onchange="updatePreview()">
                </div>
                <div class="dt-group">
                    <label>Time</label>
                    <input type="time" name="end_time" id="end_time" value="<?= htmlspecialchars(substr($edit_data['end_time'],0,5)) ?>" required onchange="updatePreview()">
                </div>
            </div>
        </div>

        <div class="duration-preview" id="dur-preview"></div>

        <div class="form-group">
            <label>Activity (Work Description):</label>
            <textarea name="work_description" required><?= htmlspecialchars($edit_data['work_description']) ?></textarea>
        </div>

        <button type="submit">Update Record</button>
        <a href="<?= $is_admin ? 'admin_timesheets.php' : 'index.php' ?>" class="btn-cancel">Cancel</a>
    </form>
</div>
<script>
document.getElementById('trigger-text').textContent = <?= json_encode($current_selected_text) ?>;

function toggleDropdown(e) {
    e.stopPropagation();
    document.getElementById('select-dropdown').classList.toggle('show-dropdown');
    if (document.getElementById('select-dropdown').classList.contains('show-dropdown'))
        document.getElementById('project-search').focus();
}
function selectOption(el) {
    document.getElementById('hidden-project-id').value = el.dataset.value;
    document.getElementById('trigger-text').textContent = el.textContent.trim();
    document.querySelectorAll('.custom-option').forEach(o => o.classList.remove('selected'));
    el.classList.add('selected');
    document.getElementById('select-dropdown').classList.remove('show-dropdown');
}
function filterProjects() {
    const f = document.getElementById('project-search').value.toLowerCase();
    document.querySelectorAll('.custom-option').forEach(o => {
        o.style.display = (o.dataset.keywords || '').includes(f) ? '' : 'none';
    });
}
window.onclick = e => {
    if (!e.target.closest('.form-group')) document.getElementById('select-dropdown').classList.remove('show-dropdown');
};

function updatePreview() {
    const sd = document.getElementById('start_date').value;
    const st = document.getElementById('start_time').value;
    const ed = document.getElementById('end_date').value;
    const et = document.getElementById('end_time').value;
    const prev = document.getElementById('dur-preview');
    if (!sd||!st||!ed||!et) { prev.textContent = ''; return; }
    const diff = new Date(ed+'T'+et) - new Date(sd+'T'+st);
    if (diff <= 0) { prev.textContent = '⚠️ End must be after Start'; prev.style.color='#991b1b'; return; }
    const h = Math.floor(diff/3600000);
    const m = Math.floor((diff%3600000)/60000);
    const days = Math.floor(h/24);
    let t = '⏱ Duration: ';
    if (days>0) t += days+'d ';
    t += (h%24)+'h '+m+'m';
    prev.textContent = t;
    prev.style.color = '#166534';
}
updatePreview();
</script>
</body>
</html>