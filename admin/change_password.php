<?php
require_once '../config.php';
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $new_pass = $_POST['new_password'];
    $hash = password_hash($new_pass, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("UPDATE admin SET password = ? WHERE id = ?");
    $stmt->execute([$hash, $_SESSION['admin_id']]);
    $msg = "Password berhasil diubah!";
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Ganti Password</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 flex items-center justify-center h-screen">
    <div class="bg-white p-8 rounded-lg shadow-md w-96">
        <h2 class="text-2xl font-bold mb-6">Ganti Password</h2>
        <?php if(isset($msg)): ?>
            <div class="bg-green-100 text-green-700 px-4 py-2 mb-4 rounded"><?= $msg ?></div>
        <?php endif; ?>
        <form method="POST" action="">
            <div class="mb-4">
                <label class="block text-sm font-medium mb-1">Password Baru</label>
                <input type="password" name="new_password" required class="w-full px-3 py-2 border rounded-md">
            </div>
            <button type="submit" class="w-full bg-blue-600 text-white py-2 rounded-md hover:bg-blue-700">Simpan</button>
            <a href="index.php" class="block text-center mt-4 text-blue-600">Kembali ke Dashboard</a>
        </form>
    </div>
</body>
</html>