<?php
require_once 'config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['is_admin'])) {
    header("Location: login.php");
    exit;
}

$sql = "SELECT t.*, p.project_name, p.customer_name, p.estimate_time, 
               i.iips_status, i.target_mandays, i.target_start_date, i.target_end_date 
        FROM timesheets t 
        JOIN projects p ON t.project_id = p.project_id 
        LEFT JOIN iips_tracking i ON p.project_id = i.project_id";

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['selected_ts']) && is_array($_POST['selected_ts'])) {
    $selected_ids = array_map('intval', $_POST['selected_ts']);
    $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
    $sql .= " WHERE t.id IN ($placeholders) ORDER BY t.start_date ASC, t.start_time ASC";
    $stmt = $conn->prepare($sql);
    $types = str_repeat('i', count($selected_ids));
    $stmt->bind_param($types, ...$selected_ids);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $sql .= " ORDER BY t.start_date ASC, t.start_time ASC";
    $result = $conn->query($sql);
}

$rows = [];
$eng_summary = [];
$proj_summary = [];

if ($result && $result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $start = new DateTime($row['start_date'] . ' ' . $row['start_time']);
        $end   = new DateTime($row['end_date']   . ' ' . $row['end_time']);
        if ($end <= $start) {
            $end->modify('+1 day');
        }
        
        $diff  = $start->diff($end);
        $mins  = ($diff->days * 24 * 60) + ($diff->h * 60) + $diff->i;
        
        $mb = isset($row['meal_breaks']) ? intval($row['meal_breaks']) : 0;
        $mins -= ($mb * 60);
        if ($mins < 0) {
            $mins = 0;
        }
        
        $row['calc_mins'] = $mins;
        $rows[] = $row;

        $eng = !empty($row['engineer_name']) ? $row['engineer_name'] : 'Unknown';
        $proj = !empty($row['project_name']) ? $row['project_name'] : 'Unknown';

        if (!isset($eng_summary[$eng])) {
            $eng_summary[$eng] = 0;
        }
        $eng_summary[$eng] += $mins;

        if (!isset($proj_summary[$proj])) {
            $proj_summary[$proj] = 0;
        }
        $proj_summary[$proj] += $mins;
    }
}

$filename = "Timesheet_Export_" . date('Ymd_His') . ".xls";

header("Content-Type: application/vnd.ms-excel; charset=utf-8");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Expires: 0");
header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
header("Pragma: public");

echo "\xEF\xBB\xBF";
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <style>
        table { border-collapse: collapse; width: 100%; font-family: Arial, sans-serif; margin-bottom: 30px; }
        th { background-color: #343a40; color: #ffffff; font-weight: bold; text-align: left; padding: 10px; border: 1px solid #dee2e6; }
        td { padding: 10px; border: 1px solid #dee2e6; font-size: 13px; color: #333333; }
        .bg-stripe { background-color: #f8fafc; }
        .title { font-size: 18px; font-weight: bold; font-family: Arial, sans-serif; margin-bottom: 10px; color: #1e293b; }
    </style>
</head>
<body>
    <div class="title">Chart Data: Engineer Contribution (Total Hours)</div>
    <table style="width: 400px;">
        <thead>
            <tr>
                <th>Engineer Name</th>
                <th>Total Work Hours</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            arsort($eng_summary); 
            foreach($eng_summary as $e => $m): 
            ?>
            <tr>
                <td><?php echo htmlspecialchars($e); ?></td>
                <td><?php echo round($m / 60, 2); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="title">Chart Data: Project Analysis (Total Hours)</div>
    <table style="width: 400px;">
        <thead>
            <tr>
                <th>IIPS Name</th>
                <th>Total Work Hours</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            arsort($proj_summary); 
            foreach($proj_summary as $p => $m): 
            ?>
            <tr>
                <td><?php echo htmlspecialchars($p); ?></td>
                <td><?php echo round($m / 60, 2); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="title">Detailed Timesheet Logs</div>
    <table>
        <thead>
            <tr>
                <th>Engineer</th>
                <th>IIPS ID</th>
                <th>Customer Name</th>
                <th>IIPS Name</th>
                <th>IIPS Status</th>
                <th>Activity Description</th>
                <th>Start Date</th>
                <th>Start Time</th>
                <th>End Date</th>
                <th>End Time</th>
                <th>Meal Breaks (Hrs)</th>
                <th>Actual Duration</th>
                <th>Target Mandays</th>
                <th>Target Start Date</th>
                <th>Target End Date</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $is_stripe = false;
            foreach ($rows as $row): 
                $is_stripe = !$is_stripe;
                $row_class = $is_stripe ? 'class="bg-stripe"' : '';
                
                $h = floor($row['calc_mins'] / 60);
                $m = $row['calc_mins'] % 60;
                $dur_text = $h . 'h ' . $m . 'm';
                
                $pid = preg_match('/^N[\/\-]?A/i', $row['project_id']) ? '-' : $row['project_id'];
                $status = !empty($row['iips_status']) ? $row['iips_status'] : 'Not Quoted';
                $ts = (!empty($row['target_start_date']) && $row['target_start_date'] !== '0000-00-00') ? substr($row['target_start_date'], 0, 10) : '-';
                $te = (!empty($row['target_end_date']) && $row['target_end_date'] !== '0000-00-00') ? substr($row['target_end_date'], 0, 10) : '-';
            ?>
                <tr <?php echo $row_class; ?>>
                    <td><b><?php echo htmlspecialchars($row['engineer_name']); ?></b></td>
                    <td><?php echo htmlspecialchars($pid); ?></td>
                    <td><?php echo htmlspecialchars($row['customer_name']); ?></td>
                    <td><?php echo htmlspecialchars($row['project_name']); ?></td>
                    <td><?php echo htmlspecialchars($status); ?></td>
                    <td><?php echo !empty($row['work_description']) ? htmlspecialchars($row['work_description']) : 'No description'; ?></td>
                    <td><?php echo htmlspecialchars($row['start_date']); ?></td>
                    <td><?php echo htmlspecialchars(substr($row['start_time'], 0, 5)); ?></td>
                    <td><?php echo htmlspecialchars($row['end_date']); ?></td>
                    <td><?php echo htmlspecialchars(substr($row['end_time'], 0, 5)); ?></td>
                    <td><?php echo isset($row['meal_breaks']) ? htmlspecialchars($row['meal_breaks']) : '0'; ?></td>
                    <td style="color:#16a34a; font-weight:bold;"><?php echo $dur_text; ?></td>
                    <td><?php echo htmlspecialchars($row['target_mandays'] ?? '-'); ?></td>
                    <td><?php echo htmlspecialchars($ts); ?></td>
                    <td><?php echo htmlspecialchars($te); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html>