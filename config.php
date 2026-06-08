<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('DB_HOST', '127.0.0.1');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'timesheet');

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($conn->connect_error) {
    die("Database Connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

function calculateDuration($date, $s_time, $e_time, $meal_breaks = 0) {
    if (empty($s_time) || empty($e_time)) {
        return "<span style='color:#aaa;'>Missing Time</span>";
    }

    $start = new DateTime($date . " " . $s_time);
    $end = new DateTime($date . " " . $e_time);

    if ($end <= $start) { 
        $end->modify('+1 day');
    }

    $diff = $start->diff($end);
    $total_minutes = ($diff->days * 24 * 60) + ($diff->h * 60) + $diff->i;
    $total_minutes -= ($meal_breaks * 60);

    if ($total_minutes < 0) {
        $total_minutes = 0;
    }

    $hours = floor($total_minutes / 60);
    $minutes = $total_minutes % 60;

    return "<span style='font-weight:bold; color:#28a745;'>" . $hours . "h " . $minutes . "m</span>";
}

function calculateTotalProjectManDays($total_minutes_spent) {
    if (empty($total_minutes_spent) || $total_minutes_spent <= 0) {
        return "<span style='color:#6c757d; font-weight:bold;'>0 Man-days</span>";
    }

    $total_hours = $total_minutes_spent / 60;
    $man_days = round($total_hours / 8, 2);

    return "<span style='font-weight:bold; color:#28a745;'>" . $man_days . " Man-days</span>" .
           "<span style='font-size:11px; color:#aaa; display:block;'>(Total: " . floor($total_hours) . "h " . ($total_minutes_spent % 60) . "m)</span>";
}

function calculateProjectGap($date, $s_time, $e_time, $expected_hours, $meal_breaks = 0) {
    if (empty($s_time) || empty($e_time)) { 
        return "-"; 
    }

    $start = new DateTime($date . " " . $s_time);
    $end = new DateTime($date . " " . $e_time);
    
    if ($end <= $start) { 
        $end->modify('+1 day');
    }

    $diff = $start->diff($end);
    $actual_minutes = ($diff->days * 24 * 60) + ($diff->h * 60) + $diff->i;
    $actual_minutes -= ($meal_breaks * 60);

    if ($actual_minutes < 0) {
        $actual_minutes = 0;
    }

    $expected_minutes = intval($expected_hours) * 60;
    $gap_minutes = $expected_minutes - $actual_minutes;

    if ($gap_minutes === 0) {
        return "<span style='color:#6c757d; font-weight:bold;'>On Time</span>";
    }

    $abs_gap = abs($gap_minutes);
    $gap_hours = floor($abs_gap / 60);
    $gap_m = $abs_gap % 60;

    $time_text = $gap_hours . "h " . $gap_m . "m";

    if ($gap_minutes > 0) {
        return "<span style='font-weight:bold; color:#28a745; display:block;'>Ahead</span>" .
               "<span style='font-size:11px; color:#aaa; display:block;'>($time_text)</span>";
    } else {
        return "<span style='font-weight:bold; color:#dc3545; display:block;'>Overdue</span>" .
               "<span style='font-size:11px; color:#aaa; display:block;'>($time_text)</span>";
    }
}
?>