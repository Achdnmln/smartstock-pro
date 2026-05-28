<?php
// Mengaktifkan proteksi cookie session tingkat lanjut sebelum session dimulai
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1); // Mencegah pencurian session lewat XSS
    ini_set('session.use_only_cookies', 1);
    session_start();
}

// Koneksi Database menggunakan PDO
$host = 'localhost';
$db   = 'smartstock_pro';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
     $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
     throw new \PDOException($e->getMessage(), (int)$e->getCode());
}

// =======================================================
// [TAMBAHAN] FITUR PROTEKSI CSRF TOKEN
// =======================================================
function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validasiCSRFToken($token) {
    if (!isset($_SESSION['csrf_token']) || $token !== $_SESSION['csrf_token']) {
        return false;
    }
    return true;
}

// =======================================================
// [TAMBAHAN] FITUR SESSION TIMEOUT OTOMATIS (5 Menit)
// =======================================================
function cekSessionTimeout() {
    $timeout_durasi = 300; // 300 detik = 5 menit

    if (isset($_SESSION['user_id'])) {
        if (isset($_SESSION['terakhir_aktivitas'])) {
            $durasi_nganggur = time() - $_SESSION['terakhir_aktivitas'];
            
            if ($durasi_nganggur > $timeout_durasi) {
                global $pdo;
                createAuditLog($pdo, $_SESSION['user_id'], "Session habis (Timeout) karena tidak aktif selama 5 menit.");
                
                // Hancurkan session
                $_SESSION = array();
                session_destroy();
                header("Location: login.php?pesan=timeout");
                exit;
            }
        }
        // Jika ada aktivitas, perbarui waktunya
        $_SESSION['terakhir_aktivitas'] = time();
    }
}

// Jalankan fungsi timeout secara otomatis di setiap halaman
cekSessionTimeout();

// FUNGSI AUDIT LOG KEAMANAN FORENSIK
function createAuditLog($pdo, $userId, $action) {
    try {
        $ip_address = $_SERVER['REMOTE_ADDR'];
        $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, aktivitas, ip_address) VALUES (?, ?, ?)");
        $stmt->execute([$userId, $action, $ip_address]);
    } catch (PDOException $e) {
        // Abaikan jika log gagal demi kelancaran aplikasi utama
    }
}
?>