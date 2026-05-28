<?php
require_once 'auth.php'; // auth.php sudah otomatis menyalakan session_start(), jadi aman tanpa double session

// Proteksi Tingkat Tinggi: Hanya Admin dan Manajer Gudang yang boleh masuk ke halaman log ini
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'Admin' && $_SESSION['role'] !== 'Manajer Gudang')) {
    die("<h2>Akses Ditolak! Anda tidak memiliki izin untuk melihat log keamanan sistem ini.</h2><a href='dashboard.php'>Kembali ke Dashboard</a>");
}

// Ambil riwayat aktivitas dari database dengan teknik JOIN untuk memunculkan nama usernya
$stmt = $pdo->prepare("SELECT audit_logs.id, users.username, users.role, audit_logs.action, audit_logs.timestamp 
                       FROM audit_logs 
                       JOIN users ON audit_logs.user_id = users.id 
                       ORDER BY audit_logs.timestamp DESC");
$stmt->execute();
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Audit Logs Keamanan - SmartStock Pro</title>
    <style>
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            margin: 0; 
            padding: 40px; 
            background-color: #0A2D6D; /* Warna abu-abu segar senada dengan Dashboard */
            display: flex;
            justify-content: center;
            align-items: flex-start;
            min-height: 100vh;
            box-sizing: border-box;
        }
        
        .container { 
            background: white; 
            padding: 40px; 
            border-radius: 16px; 
            box-shadow: 0 4px 20px rgba(15, 23, 42, 0.08); 
            width: 100%;
            max-width: 1000px; /* Diperlebar agar muat tabel dengan lega */
        }

        .header-section {
            margin-bottom: 30px;
        }

        h2 {
            color: #0f172a;
            margin-top: 0;
            font-size: 26px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .subtitle {
            color: #64748b;
            font-size: 14px;
            margin-top: 5px;
            margin-bottom: 20px;
        }

        .back-link {
            display: inline-block;
            color: #2563eb;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            transition: color 0.2s;
        }

        .back-link:hover {
            color: #1d4ed8;
            text-decoration: underline;
        }

        /* Desain Tabel Premium */
        .table-responsive {
            overflow-x: auto;
            margin-top: 20px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }

        table { 
            width: 100%; 
            border-collapse: collapse; 
            background: white; 
            font-size: 14px;
            text-align: left;
        }

        th { 
            background-color: #f8fafc; 
            color: #475569; 
            font-weight: 600;
            padding: 14px 16px; 
            border-bottom: 2px solid #e2e8f0;
        }

        td { 
            padding: 14px 16px; 
            border-bottom: 1px solid #e2e8f0;
            color: #334155;
        }

        tr:last-child td {
            border-bottom: none;
        }

        tr:hover { 
            background-color: #f8fafc; /* Efek highlight saat kursor melewati baris tabel */
        }

        /* Desain badge status aktivitas */
        .action-badge {
            font-weight: 600;
            font-size: 13px;
        }
        .text-login { color: #16a34a; }
        .text-logout { color: #dc2626; }
        
        .role-text {
            background-color: #f1f5f9;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
            color: #475569;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="header-section">
        <h2>🛡️ Audit Log & Riwayat Keamanan Sistem</h2>
        <p class="subtitle">Merekam jejak aktivitas digital pengguna secara real-time untuk kebutuhan kepatuhan forensik IT.</p>
        <a href="dashboard.php" class="back-link">← Kembali ke Dashboard</a>
    </div>

    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>ID Log</th>
                    <th>Waktu Kejadian</th>
                    <th>Username</th>
                    <th>Role / Peran</th>
                    <th>Aktivitas yang Dilakukan</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log): ?>
                <tr>
                    <td><span style="color: #94a3b8; font-weight: 500;"><?= $log['id'] ?></span></td>
                    <td style="color: #64748b;"><?= htmlspecialchars($log['timestamp']) ?></td>
                    <td><strong><?= htmlspecialchars($log['username']) ?></strong></td>
                    <td><span class="role-text"><?= htmlspecialchars($log['role']) ?></span></td>
                    <td>
                        <?php if (strpos($log['action'], 'login') !== false): ?>
                            <span class="action-badge text-login">✓ <?= htmlspecialchars($log['action']) ?></span>
                        <?php else: ?>
                            <span class="action-badge text-logout">✕ <?= htmlspecialchars($log['action']) ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

</body>
</html>