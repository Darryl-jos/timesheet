<?php
require_once 'config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['is_admin'])) {
    header("Location: login.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['selected_ts']) && is_array($_POST['selected_ts'])) {
    $selected_ids = array_map('intval', $_POST['selected_ts']);
    $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
    $types = str_repeat('i', count($selected_ids));
    
    $stmt = $conn->prepare("SELECT t.*, p.project_name, p.customer_name, p.estimate_time FROM timesheets t JOIN projects p ON t.project_id = p.project_id WHERE t.id IN ($placeholders) ORDER BY t.id DESC");
    $stmt->bind_param($types, ...$selected_ids);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $sql = "SELECT t.*, p.project_name, p.customer_name, p.estimate_time FROM timesheets t JOIN projects p ON t.project_id = p.project_id ORDER BY t.id DESC";
    $result = $conn->query($sql);
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
        table { border-collapse: collapse; width: 100%; font-family: Arial, sans-serif; }
        th { background-color: #343a40; color: #ffffff; font-weight: bold; text-align: left; padding: 10px; border: 1px solid #dee2e6; }
        td { padding: 10px; border: 1px solid #dee2e6; font-size: 13px; color: #333333; }
        .bg-stripe { background-color: #f8fafc; }
        .text-bold { font-weight: bold; }
        .text-blue { color: #007bff; font-weight: bold; }
        .text-green { color: #28a745; font-weight: bold; }
        .text-red { color: #dc3545; font-weight: bold; }
        .text-muted { color: #6c757d; font-weight: bold; }
        .desc-box { color: #555555; font-style: italic; background-color: #fdfdfd; }
    </style>
</head>
<body>
    <table>
        <thead>
            <tr>
                <th>Engineer</th>
                <th>Project ID</th>
                <th>Customer Name</th>
                <th>Project Name</th>
                <th>Activity (Work Log)</th>
                <th>Expected Time</th>
                <th>Actual Duration</th>
                <th>Performance Gap</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $is_stripe = false;
            if ($result->num_rows > 0): 
                while($row = $result->fetch_assoc()): 
                    $row_class = $is_stripe ? 'class="bg-stripe"' : '';
                    $is_stripe = !$is_stripe;

                    $start_dt = new DateTime($row['start_date'] . " " . $row['start_time']);
                    $end_dt = new DateTime($row['end_date'] . " " . $row['end_time']);
                    $date_start_only = new DateTime($row['start_date']);
                    $date_end_only = new DateTime($row['end_date']);
                    $date_diff = $date_start_only->diff($date_end_only);
                    $actual_days = $date_diff->days + 1;

                    $norm_start = clone $start_dt;
                    if ($norm_start->format('H:i') < '09:00') $norm_start->setTime(9, 0);
                    if ($norm_start->format('H:i') > '18:00') $norm_start->setTime(18, 0);

                    $norm_end = clone $end_dt;
                    if ($norm_end->format('H:i') < '09:00') $norm_end->setTime(9, 0);
                    if ($norm_end->format('H:i') > '18:00') $norm_end->setTime(18, 0);

                    if ($row['start_date'] === $row['end_date'] && $norm_start >= $norm_end) { 
                        $actual_minutes = 0; 
                    } else {
                        $physical_diff = $norm_start->diff($norm_end);
                        $actual_minutes = ($physical_diff->days * 24 * 60) + ($physical_diff->h * 60) + $physical_diff->i;

                        $current = new DateTime($row['start_date']);
                        $end_day = new DateTime($row['end_date']);
                        while ($current <= $end_day) {
                            $curr_date_str = $current->format('Y-m-d');
                            $include_lunch = true;
                            if ($curr_date_str === $row['start_date'] && $start_dt->format('H:i') > '12:30') $include_lunch = false;
                            if ($curr_date_str === $row['end_date'] && $end_dt->format('H:i') < '13:30') $include_lunch = false;
                            if ($include_lunch) { $actual_minutes -= 60; }
                            if ($curr_date_str !== $row['end_date']) { $actual_minutes -= (15 * 60); }
                            $current->modify('+1 day');
                        }
                        $actual_minutes = max(0, $actual_minutes);
                    }

                    $display_hours = floor($actual_minutes / 60);
                    $display_minutes = $actual_minutes % 60;
                    $actual_duration_text = $actual_days . " Days (" . $display_hours . "h " . $display_minutes . "m)";

                    $expected_hours = $row['estimate_time'];
                    $expected_minutes = intval($expected_hours) * 60;
                    $gap_minutes = $expected_minutes - $actual_minutes;

                    if ($gap_minutes === 0) {
                        $gap_text = "<span class='text-muted'>On Time</span>";
                    } else {
                        $expected_days = $expected_hours / 8;
                        $gap_days = abs($actual_days - $expected_days);

                        $abs_gap = abs($gap_minutes);
                        $total_gap_hours = floor($abs_gap / 60);
                        $gap_m = $abs_gap % 60;

                        $day_text = $gap_days . " Days";
                        $time_text = "(" . $total_gap_hours . "h " . $gap_m . "m)";

                        if ($gap_minutes > 0) {
                            $gap_text = "<span class='text-green'>Ahead: " . $day_text . " " . $time_text . "</span>";
                        } else {
                            $gap_text = "<span class='text-red'>Overdue: " . $day_text . " " . $time_text . "</span>";
                        }
                    }
            ?>
                <tr <?php echo $row_class; ?>>
                    <td class="text-bold"><?php echo htmlspecialchars($row['engineer_name']); ?></td>
                    <td><?php echo preg_match('/^N[\/\-]?A/i', $row['project_id']) ? '-' : htmlspecialchars($row['project_id']); ?></td>
                    <td><?php echo htmlspecialchars($row['customer_name']); ?></td>
                    <td><?php echo htmlspecialchars($row['project_name']); ?></td>
                    <td class="desc-box"><?php echo !empty($row['work_description']) ? htmlspecialchars($row['work_description']) : 'No description provided'; ?></td>
                    <td class="text-blue"><?php echo ($row['estimate_time'] / 8); ?> Days (<?php echo intval($row['estimate_time']); ?>h)</td>
                    <td class="text-green"><?php echo $actual_duration_text; ?></td>
                    <td><?php echo $gap_text; ?></td>
                </tr>
            <?php 
                endwhile; 
            endif; 
            ?>
        </tbody>
    </table>
</body>
</html>