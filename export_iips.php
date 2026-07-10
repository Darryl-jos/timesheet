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
            ) AS total_minutes,
            GROUP_CONCAT(DISTINCT engineer_name ORDER BY engineer_name SEPARATOR ', ') AS engineers
        FROM timesheets WHERE project_id=?
    ");
    $stmt->bind_param("s", $project_id); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return $row;
}

$base_sql = "
    SELECT 
        p.*,
        i.selling_price, i.partner_cost, i.internal_cost, i.gross_profit,
        i.accrued, i.remarks_status,
        i.has_project_mgmt,
        i.target_mandays, i.target_start_date, i.target_end_date,
        i.target_billing_date,
        i.iips_status, i.billing_status, i.billing_on,
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

$total_sp = 0;
$total_pc = 0;
$total_actual_gp = 0;
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        table { border-collapse: collapse; font-family: Arial, sans-serif; }
        th { font-weight: bold; border: 1px solid #dee2e6; padding: 8px; text-align: left; color: #ffffff; }
        td { border: 1px solid #dee2e6; padding: 8px; font-size: 13px; color: #333; }
        
        .s-base    { background-color: #343a40; }
        .s-costing { background-color: #155724; }
        .s-timeline{ background-color: #1a237e; }
        .s-actual  { background-color: #004d40; }
        .s-status  { background-color: #6a1b4d; }
        .s-res     { background-color: #4a235a; }

        .bg-c-base { background-color: #f8f9fa; }
        .bg-c-cost { background-color: #eafaf1; } 
        .bg-c-tgt  { background-color: #e8eaf6; }  
        .bg-c-act  { background-color: #e0f2f1; }  
        .bg-c-stat { background-color: #fce4ec; } 
        .bg-c-res  { background-color: #f3e5f5; }

        .text-bold { font-weight: bold; }
        .text-green { color: #166534; font-weight: bold; }
        .text-red { color: #dc2626; font-weight: bold; }
        .text-muted { color: #6b7280; }
        .total-row td { background-color: #f8f9fa; font-weight: bold; border-top: 2px solid #343a40; }
    </style>
</head>
<body>
    <table>
        <thead>
            <tr>
                <th class="s-base">IIPS ID</th>
                <th class="s-base">IIPS Name</th>
                <th class="s-base">Customer Name</th>
                <th class="s-costing">Selling Price (RM)</th>
                <th class="s-costing">Partner Cost (RM)</th>
                <th class="s-costing">Internal Cost (RM)</th>
                <th class="s-costing">Actual GP (RM)</th>
                <th class="s-costing">GP %</th>
                <th class="s-timeline">Target Man-Days (hr)</th>
                <th class="s-timeline">Target Start Date</th>
                <th class="s-timeline">Target End Date</th>
                <th class="s-actual">Actual Man-Days (hr)</th>
                <th class="s-actual">Actual Start Date</th>
                <th class="s-actual">Actual End Date</th>
                <th class="s-status">IIPS Status</th>
                <th class="s-status">Target Billing Date</th>
                <th class="s-status">Billing Status</th>
                <th class="s-status">Billing On</th>
                <th class="s-status">Accrued (RM)</th>
                <th class="s-status">Remarks</th>
                <th class="s-res">Account Manager</th>
                <th class="s-res">Account Leader</th>
                <th class="s-res">Pre-Sales / SDM</th>
                <th class="s-res">Project Manager</th>
                <th class="s-res">Engineers</th>
                <th class="s-res">Partner</th>
            </tr>
        </thead>
        <tbody>
            <?php
            if ($result && $result->num_rows > 0):
                while($row = $result->fetch_assoc()):
                    $ts = getTimesheetData($conn, $row['project_id']);
                    
                    $sp = $row['selling_price'] !== null ? floatval($row['selling_price']) : null;
                    $pc = $row['partner_cost'] !== null ? floatval($row['partner_cost']) : null;
                    $ic = $row['internal_cost'] !== null ? floatval($row['internal_cost']) : null;
                    
                    $actual_gp = null;
                    $gp_pct = null;
                    
                    if ($sp !== null && strlen(trim((string)$row['selling_price'])) > 0) {
                        $pc_val = $pc ?? 0;
                        $ic_val = $ic ?? 0;
                        
                        $actual_gp = $sp - $pc_val - $ic_val;
                        
                        if ($sp > 0) {
                            $gp_pct = ($actual_gp / $sp) * 100;
                        }

                        $total_sp += $sp;
                        $total_pc += $pc_val;
                        $total_actual_gp += $actual_gp;
                    }
                    
                    $gp_class = "";
                    if ($actual_gp !== null) {
                        $gp_class = $actual_gp > 0 ? "text-green" : ($actual_gp < 0 ? "text-red" : "text-muted");
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
                    $d_bill_on  = !empty($row['billing_on']) ? date('M Y', strtotime($row['billing_on'])) : '-';
            ?>
                <tr>
                    <td class="bg-c-base text-bold"><?php echo preg_match('/^N[\/\-]?A/i', trim($row['project_id'])) ? '-' : htmlspecialchars($row['project_id']); ?></td>
                    <td class="bg-c-base text-bold"><?php echo htmlspecialchars($row['project_name']); ?></td>
                    <td class="bg-c-base"><?php echo htmlspecialchars($row['customer_name']); ?></td>
                    <td class="bg-c-cost"><?php echo $sp !== null ? 'RM ' . number_format($sp, 2) : '-'; ?></td>
                    <td class="bg-c-cost"><?php echo $pc !== null ? 'RM ' . number_format($pc, 2) : '-'; ?></td>
                    <td class="bg-c-cost"><?php echo $ic !== null ? 'RM ' . number_format($ic, 2) : '-'; ?></td>
                    <td class="bg-c-cost <?php echo $gp_class; ?>"><?php echo $actual_gp !== null ? 'RM ' . number_format($actual_gp, 2) : '-'; ?></td>
                    <td class="bg-c-cost <?php echo $gp_class; ?>"><?php echo $gp_pct !== null ? number_format($gp_pct, 1) . '%' : '-'; ?></td>
                    <td class="bg-c-tgt"><?php echo $tgt_md_str; ?></td>
                    <td class="bg-c-tgt"><?php echo $d_ts_start; ?></td>
                    <td class="bg-c-tgt"><?php echo $d_ts_end; ?></td>
                    <td class="bg-c-act"><?php echo $actual_md_str; ?></td>
                    <td class="bg-c-act"><?php echo $d_act_start; ?></td>
                    <td class="bg-c-act"><?php echo $d_act_end; ?></td>
                    <td class="bg-c-stat"><?php echo htmlspecialchars($row['iips_status'] ?? 'Not Quoted'); ?></td>
                    <td class="bg-c-stat"><?php echo $d_bill; ?></td>
                    <td class="bg-c-stat"><?php echo htmlspecialchars($row['billing_status'] ?? 'Not Forecasted'); ?></td>
                    <td class="bg-c-stat"><?php echo $d_bill_on; ?></td>
                    <td class="bg-c-stat"><?php echo $row['accrued'] !== null ? 'RM ' . number_format($row['accrued'], 2) : '-'; ?></td>
                    <td class="bg-c-stat"><?php echo htmlspecialchars($row['remarks_status'] ?? '-'); ?></td>
                    <td class="bg-c-res"><?php echo htmlspecialchars($row['account_manager'] ?? '-'); ?></td>
                    <td class="bg-c-res"><?php echo htmlspecialchars($row['account_leader'] ?? '-'); ?></td>
                    <td class="bg-c-res"><?php echo htmlspecialchars($row['presales_sdm'] ?? '-'); ?></td>
                    <td class="bg-c-res"><?php echo htmlspecialchars($row['project_manager'] ?? '-'); ?></td>
                    <td class="bg-c-res"><?php echo htmlspecialchars($ts['engineers'] ?? '-'); ?></td>
                    <td class="bg-c-res"><?php echo htmlspecialchars($row['partner'] ?? '-'); ?></td>
                </tr>
            <?php 
                endwhile;
            endif;
            ?>
            <tr class="total-row">
                <td class="bg-c-base" colspan="3" style="text-align: right;">TOTAL:</td>
                <td class="bg-c-cost"><?php echo 'RM ' . number_format($total_sp, 2); ?></td>
                <td class="bg-c-cost"><?php echo 'RM ' . number_format($total_pc, 2); ?></td>
                <td class="bg-c-cost"></td>
                <td class="bg-c-cost"><?php echo 'RM ' . number_format($total_actual_gp, 2); ?></td>
                <td class="bg-c-cost"></td>
                <td class="bg-c-tgt" colspan="3"></td>
                <td class="bg-c-act" colspan="3"></td>
                <td class="bg-c-stat" colspan="6"></td>
                <td class="bg-c-res" colspan="6"></td>
            </tr>
        </tbody>
    </table>
</body>
</html>