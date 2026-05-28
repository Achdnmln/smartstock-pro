<?php
$pdo = new PDO("mysql:host=localhost;dbname=smartstock_pro;charset=utf8mb4", 'root', '');

// Data akun-akun yang akan dibuat
$users = [
    ['username' => 'manajer_gudang', 'password' => 'Manajer123!', 'role' => 'Manajer Gudang'],
    ['username' => 'staf_gudang', 'password' => 'Staf123!', 'role' => 'Staf Gudang'],
    ['username' => 'viewer_saja', 'password' => 'Viewer123!', 'role' => 'Viewer']
];

echo "<h2>Proses Pembuatan Akun Multi-Level:</h2>";

foreach ($users as $u) {
    $hashed_password = password_hash($u['password'], PASSWORD_BCRYPT);
    try {
        $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)");
        $stmt->execute([$u['username'], $hashed_password, $u['role']]);
        echo "Sukses membuat akun <b>{$u['username']}</b> dengan role: <b>{$u['role']}</b><br>";
    } catch (PDOException $e) {
        echo "Akun <b>{$u['username']}</b> gagal dibuat (mungkin sudah ada).<br>";
    }
}

echo "<br><a href='login.php'>Klik di sini untuk kembali ke halaman Login</a>";
?>