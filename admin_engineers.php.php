<?php
require_once 'config.php';

if (!isset($_SESSION['engineer_id']) || !isset($_SESSION['is_admin']) || ($_SESSION['is_admin'] != 1 && $_SESSION['is_admin'] != 2)) {
    header("Location: login.php");
    exit;
}

// ✅ RESET PASSWORD FUNCTION
if (isset($_GET['reset_id'])) {
    $reset_id = intval($_GET['reset_id']);

    if ($reset_id != $_SESSION['engineer_id']) {
        $defaultPassword = "Password@123";
        $hashedPassword = password_hash($defaultPassword, PASSWORD_DEFAULT);

        $stmt = $conn->prepare("UPDATE engineers SET password = ? WHERE id = ?");
        $stmt->bind_param("si", $hashedPassword, $reset_id);

        if ($stmt->execute()) {
            echo "<script>alert('✅ Password reset to: Password@123'); window.location='admin_engineers.php';</script>";
        } else {
            echo "<script>alert('❌ Failed to reset password');</script>";
        }
        $stmt->close();
        exit;
    } else {
        echo "<script>alert('⚠️ You cannot reset your own password'); window.location='admin_engineers.php';</script>";
        exit;
    }
}

$current_admin_role = intval($_SESSION['is_admin']);
$current_admin_id = intval($_SESSION['engineer_id']);

// DELETE FUNCTION (unchanged)
if (isset($_GET['delete_eng'])) {
    $del_eng_id = intval($_GET['delete_eng']);

    $stmt = $conn->prepare("DELETE FROM engineers WHERE id = ?");
    $stmt->bind_param("i", $del_eng_id);
    $stmt->execute();
    $stmt->close();

    header("Location: admin_engineers.php");
    exit;
}

// FETCH ENGINEERS
$eng_result = $conn->query("SELECT * FROM engineers ORDER BY id ASC");
?>

<!DOCTYPE html>
<html>
<head>
    <title>Manage Engineers</title>
    <style>
        body { font-family: Arial; background:#f4f7f6; }
        table { width:100%; background:white; border-collapse: collapse; }
        th, td { padding:10px; border:1px solid #ddd; }
        a { padding:5px 8px; text-decoration:none; border-radius:4px; font-size:12px; }
        .btn-edit { background:#ffc107; }
        .btn-reset { background:#17a2b8; color:white; }
        .btn-del { background:#dc3545; color:white; }
    </style>
</head>
<body>

<h2>Engineer Management</h2>

<table>
<tr>
    <th>Username</th>
    <th>Name</th>
    <th>Role</th>
    <th>Actions</th>
</tr>

<?php while ($e = $eng_result->fetch_assoc()): ?>
<tr>
    <td><?php echo $e['username_id']; ?></td>
    <td><?php echo $e['engineer_name']; ?></td>
    <td><?php echo ($e['is_admin'] == 2) ? "Admin" : "User"; ?></td>

    <td>
        <a href="admin_engineers.php?edit_eng=<?php echo $e['id']; ?>" class="btn-edit">Edit</a>

        <a href="admin_engineers.php?reset_id=<?php echo $e['id']; ?>"
           class="btn-reset"
           onclick="return confirm('Reset password to default (Password@123)?')">
           Reset
        </a>

        <a href="admin_engineers.php?delete_eng=<?php echo $e['id']; ?>"
           class="btn-del"
           onclick="return confirm('Delete this user?')">
           Delete
        </a>
    </td>
</tr>
<?php endwhile; ?>

</table>

</body>
</html>
``