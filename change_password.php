<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['engineer_id'])) {
    header("Location: login.php");
    exit;
}

$error = "";
$success = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $currentPassword = $_POST['current_password'];
    $newPassword = $_POST['new_password'];
    $confirmPassword = $_POST['confirm_password'];

    // Get current password
    $stmt = $conn->prepare("SELECT password FROM engineers WHERE id = ?");
    $stmt->bind_param("i", $_SESSION['engineer_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();

    if (!password_verify($currentPassword, $row['password'])) {
        $error = "⚠️ Current password is incorrect.";
    } elseif ($newPassword !== $confirmPassword) {
        $error = "⚠️ New passwords do not match.";
    } elseif (strlen($newPassword) < 6) {
        $error = "⚠️ Password must be at least 6 characters.";
    } else {
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

        $stmt = $conn->prepare("UPDATE engineers SET password = ? WHERE id = ?");
        $stmt->bind_param("si", $hashedPassword, $_SESSION['engineer_id']);
        $stmt->execute();

        $success = "✅ Password updated successfully!";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Change Password</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body {
            font-family: Arial;
            background: #f4f7f6;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
        }
        .card {
            background: white;
            padding: 25px;
            border-radius: 8px;
            width: 350px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }
        input {
            width: 100%;
            padding: 12px;
            margin: 8px 0;
        }
        button {
            width: 100%;
            padding: 12px;
            background: #007bff;
            color: white;
            border: none;
        }
    </style>
</head>

<body>
<div class="card">
    <h2>Change Password</h2>

    <?php if($error): ?>
        <div style="color:red;"><?php echo $error; ?></div>
    <?php endif; ?>

    <?php if($success): ?>
        <div style="color:green;"><?php echo $success; ?></div>
    <?php endif; ?>

    <form method="POST">
        <input type="password" name="current_password" placeholder="Current Password" required>
        <input type="password" name="new_password" placeholder="New Password" required>
        <input type="password" name="confirm_password" placeholder="Confirm Password" required>
        <button type="submit">Update Password</button>
    </form>
</div>
</body>
</html>
