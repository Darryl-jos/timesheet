<?php
require_once 'config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['is_admin'])) {
    header("Location: login.php");
    exit;
}

function getTimesheetData($conn, $project_id) {
    $stmt = $conn->prepare("
        SELECT
            MIN(start_date) AS actual_start,
            MAX(end_date)   AS actual_end,
            SUM(
                GREATEST(0, TIMESTAMPDIFF(MINUTE,
                    CONCAT(start_date,' ',start_time),
                    CONCAT(end_date,' ',end_time)
                ) - (COALESCE(meal_breaks, 0) * 60))
            ) AS total_minutes
        FROM timesheets WHERE project_id=?
    ");
    $stmt->bind_param("s", $project_id); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return $row;
}

$base_sql = "
    SELECT 
        p.*,
        i.selling_price, i.partner_cost, i.gross_profit,
        i.has_project_mgmt,
        i.target_mandays, i.target_start_date, i.target_end_date,
        i.target_billing_date,
        i.iips_status, i.billing_status,
        i.account_manager, i.account_leader, i.presales_sdm, i.project_manager,
        i.partner
    FROM projects p
    LEFT JOIN iips_tracking i ON p.project_id = i.project_id
";

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['selected_iips']) && is_array($_POST['selected_iips']) && !empty($_POST['selected_iips'])) {
    $selected_ids = $_POST['selected_iips'];
    $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
    $types = str_repeat('s', count($selected_ids));
    
    $stmt = $conn->prepare($base_sql . " WHERE p.project_id IN ($placeholders) ORDER BY p.project_id ASC");
    $stmt->bind_param($types, ...$selected_ids);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($base_sql . " ORDER BY p.project_id ASC");
}

$filename = "IIPS_Export_" . date('Ymd_His') . ".xls";

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
    <style>
        table { border-collapse: collapse; font-family: Arial, sans-serif; }
        th { background-color: #343a40; color: #ffffff; font-weight: bold; border: 1px solid #dee2e6; padding: 8px; text-align: left; }
        td { border: 1px solid #dee2e6; padding: 8px; font-size: 13px; color: #333; }
        .bg-calc { background-color: #eff6ff; }
        .text-bold { font-weight: bold; }
        .text-green { color: #166534; font-weight: bold; }
        .text-red { color: #dc2626; font-weight: bold; }
        .text-muted { color: #6b7280; }
    </style>
</head>
<body>
    <table>
        <thead>
            <tr>
                <th>IIPS ID</th>
                <th>IIPS Name</th>
                <th>Customer Name</th>
                <th>Selling Price (RM)</th>
                <th>Partner Cost (RM)</th>
                <th>Gross Profit (RM)</th>
                <th>Project Mgmt</th>
                <th>Target Man-Days (hr)</th>
                <th>Target Start Date</th>
                <th>Target End Date</th>
                <th>Actual Man-Days (hr)</th>
                <th>Actual Start Date</th>
                <th>Actual End Date</th>
                <th>IIPS Status</th>
                <th>Target Billing Date</th>
                <th>Billing Status</th>
                <th>Account Manager</th>
                <th>Account Leader</th>
                <th>Pre-Sales / SDM</th>
                <th>IIPS Manager</th>
                <th>Partner</th>
            </tr>
        </thead>
        <tbody>
            <?php
            if ($result && $result->num_rows > 0):
                while($row = $result->fetch_assoc()):
                    $ts = getTimesheetData($conn, $row['project_id']);
                    
                    $gp = ($row['selling_price'] !== null && $row['partner_cost'] !== null) ? floatval($row['selling_price']) - floatval($row['partner_cost']) : null;
                    if ($row['gross_profit'] !== null) { $gp = floatval($row['gross_profit']); }
                    
                    $gp_class = "";
                    if ($gp !== null) {
                        $gp_class = $gp > 0 ? "text-green" : ($gp < 0 ? "text-red" : "text-muted");
                    }

                    $ts_mins = intval($ts['total_minutes'] ?? 0);
                    $actual_md_str = '-';
                    if ($ts_mins > 0) {
                        $h = floor($ts_mins / 60);
                        $m = $ts_mins % 60;
                        $actual_md_str = $h . 'h ' . $m . 'm';
                    }
                    
                    $tgt_md_str = '-';
                    if ($row['target_mandays']) {
                        $tmins = round(floatval($row['target_mandays']) * 60);
                        $th = floor($tmins / 60);
                        $tm = $tmins % 60;
                        $tgt_md_str = $th . 'h ' . $tm . 'm';
                    }

                    $d_ts_start = !empty($row['target_start_date']) ? date('d-M-Y', strtotime($row['target_start_date'])) : '-';
                    $d_ts_end   = !empty($row['target_end_date']) ? date('d-M-Y', strtotime($row['target_end_date'])) : '-';
                    $d_act_start= !empty($ts['actual_start']) ? date('d-M-Y', strtotime($ts['actual_start'])) : '-';
                    
                    $d_act_end  = '-';
                    if (!empty($ts['actual_end']) && ($row['iips_status'] === 'Completed')) {
                        $d_act_end = date('d-M-Y', strtotime($ts['actual_end']));
                    }

                    $d_bill     = !empty($row['target_billing_date']) ? date('d-M-Y', strtotime($row['target_billing_date'])) : '-';
            ?>
                <tr>
                    <td class="text-bold"><?php echo preg_match('/^N\/?A/i', trim($row['project_id'])) ? '-' : htmlspecialchars($row['project_id']); ?></td>
                    <td class="text-bold"><?php echo htmlspecialchars($row['project_name']); ?></td>
                    <td><?php echo htmlspecialchars($row['customer_name']); ?></td>
                    <td><?php echo $row['selling_price'] !== null ? 'RM ' . number_format($row['selling_price'], 2) : '-'; ?></td>
                    <td><?php echo $row['partner_cost'] !== null ? 'RM ' . number_format($row['partner_cost'], 2) : '-'; ?></td>
                    <td class="bg-calc <?php echo $gp_class; ?>"><?php echo $gp !== null ? 'RM ' . number_format($gp, 2) : '-'; ?></td>
                    <td><?php echo intval($row['has_project_mgmt']) ? 'Yes' : 'No'; ?></td>
                    <td><?php echo $tgt_md_str; ?></td>
                    <td><?php echo $d_ts_start; ?></td>
                    <td><?php echo $d_ts_end; ?></td>
                    <td><?php echo $actual_md_str; ?></td>
                    <td><?php echo $d_act_start; ?></td>
                    <td><?php echo $d_act_end; ?></td>
                    <td><?php echo htmlspecialchars($row['iips_status'] ?? 'Not Quoted'); ?></td>
                    <td><?php echo $d_bill; ?></td>
                    <td><?php echo htmlspecialchars($row['billing_status'] ?? 'Not Forecasted'); ?></td>
                    <td><?php echo htmlspecialchars($row['account_manager'] ?? '-'); ?></td>
                    <td><?php echo htmlspecialchars($row['account_leader'] ?? '-'); ?></td>
                    <td><?php echo htmlspecialchars($row['presales_sdm'] ?? '-'); ?></td>
                    <td><?php echo htmlspecialchars($row['project_manager'] ?? '-'); ?></td>
                    <td><?php echo htmlspecialchars($row['partner'] ?? '-'); ?></td>
                </tr>
            <?php 
                endwhile;
            endif;
            ?>
        </tbody>
    </table>
</body>
</html>