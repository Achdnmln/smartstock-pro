<?php
require_once 'auth.php';

// Proteksi Halaman: Jika belum login, tendang balik ke login.php
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$sukses = "";
$error = "";

// ==========================================
// PROSES LOGIKA HAPUS PRODUK (BARU!)
// ==========================================
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    $id_hapus = intval($_GET['id']);
    
    // 1. Cari tahu nama file gambar & nama produk sebelum datanya dihapus dari DB
    $stmt_cari = $pdo->prepare("SELECT nama_produk, gambar FROM produk WHERE id = ?");
    $stmt_cari->execute([$id_hapus]);
    $produk_target = $stmt_cari->fetch(PDO::FETCH_ASSOC);
    
    if ($produk_target) {
        $nama_file_gambar = $produk_target['gambar'];
        $nama_produk_hapus = $produk_target['nama_produk'];
        $target_file_komputer = "uploads/" . $nama_file_gambar;
        
        // 2. Hapus file gambar fisik dari folder komputer jika file-nya ada
        if (file_exists($target_file_komputer)) {
            unlink($target_file_komputer); 
        }
        
        // 3. Hapus data baris produk dari tabel database
        $stmt_hapus = $pdo->prepare("DELETE FROM produk WHERE id = ?");
        $stmt_hapus->execute([$id_hapus]);
        
        // 4. Catat ke Audit Log Keamanan
        createAuditLog($pdo, $_SESSION['user_id'], "User BERHASIL MENGHAPUS produk: " . $nama_produk_hapus);
        
        $sukses = "Produk '" . $nama_produk_hapus . "' dan gambarnya telah dihapus secara permanen!";
    } else {
        $error = "Data produk tidak ditemukan atau sudah dihapus.";
    }
}

// ==========================================
// PROSES UPLOAD GAMBAR BARU
// ==========================================
if (isset($_POST['upload'])) {
    $nama_produk = trim($_POST['nama_produk']);
    
    if (empty($nama_produk)) {
        $error = "Nama produk tidak boleh kosong!";
    } else {
        $file_name = $_FILES['gambar']['name'];
        $file_size = $_FILES['gambar']['size'];
        $file_tmp  = $_FILES['gambar']['tmp_name'];
        
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        $ekstensi_diizinkan = array("jpeg", "jpg", "png");

        if (in_array($file_ext, $ekstensi_diizinkan) === false) {
            $error = "Ekstensi file tidak diizinkan! Hanya boleh JPG, JPEG, atau PNG.";
        } elseif ($file_size > 2097152) {
            $error = "Ukuran file terlalu besar! Maksimal adalah 2 MB.";
        } else {
            $nama_gambar_baru = time() . '_' . uniqid() . '.' . $file_ext;
            $target_dir = "uploads/" . $nama_gambar_baru;

            if (move_uploaded_file($file_tmp, $target_dir)) {
                $stmt = $pdo->prepare("INSERT INTO produk (nama_produk, gambar) VALUES (?, ?)");
                $stmt->execute([$nama_produk, $nama_gambar_baru]);
                
                createAuditLog($pdo, $_SESSION['user_id'], "User berhasil mengunggah produk: " . $nama_produk);
                $sukses = "Produk berhasil ditambahkan ke galeri!";
            } else {
                $error = "Gagal mengunggah file ke server.";
            }
        }
    }
}

// AMBIL SEMUA DATA PRODUK DARI DATABASE (Ambil kolom ID juga untuk link hapus)
$stmt = $pdo->query("SELECT id, nama_produk, gambar FROM produk ORDER BY created_at DESC");
$daftar_produk = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Galeri Produk - SmartStock Pro</title>
    <style>
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            margin: 0; 
            padding: 40px; 
            background-color: #f1f5f9; 
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
            max-width: 900px;
        }

        h2 { color: #0f172a; margin-top: 0; font-size: 26px; font-weight: 700; }
        .subtitle { color: #64748b; font-size: 14px; margin-bottom: 30px; }
        
        .back-link { display: inline-block; color: #2563eb; text-decoration: none; font-weight: 600; font-size: 14px; margin-bottom: 20px; }
        .back-link:hover { text-decoration: underline; }

        .upload-form {
            background: #f8fafc;
            padding: 24px;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            margin-bottom: 40px;
        }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 8px; color: #334155; font-weight: 600; font-size: 14px; }
        .form-group input[type="text"], .form-group input[type="file"] {
            width: 100%; padding: 10px; box-sizing: border-box; border: 1px solid #cbd5e1; border-radius: 6px; background: white;
        }
        button {
            background: #10b981; color: white; border: none; padding: 10px 20px; border-radius: 6px; font-weight: 600; cursor: pointer; font-size: 14px;
        }
        button:hover { background: #059669; }

        /* Grid Galeri Produk ala Figma */
        .product-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 20px;
        }
        .product-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.02);
            text-align: center;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .product-card img {
            width: 100%;
            height: 180px;
            object-fit: cover;
            background-color: #f8fafc;
        }
        .product-info { padding: 15px; flex-grow: 1; display: flex; flex-direction: column; justify-content: space-between; gap: 12px; }
        .product-name { font-weight: 600; color: #1e293b; font-size: 15px; margin: 0; }

        /* Desain Tombol Hapus Merah (BARU!) */
        .btn-delete {
            display: block;
            color: #dc2626;
            background-color: #fef2f2;
            border: 1px solid #fee2e2;
            padding: 6px 12px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            border-radius: 6px;
            transition: all 0.2s;
        }
        .btn-delete:hover {
            background-color: #fee2e2;
        }

        .alert { padding: 12px; border-radius: 6px; font-size: 14px; margin-bottom: 20px; }
        .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
    </style>
</head>
<body>

<div class="container">
    <a href="dashboard.php" class="back-link">← Kembali ke Dashboard</a>
    <h2>📦 Galeri Produk Multimedia</h2>
    <p class="subtitle">Kelola dan pantau aset fisik barang elektronik dengan dukungan visual gambar produk.</p>

    <?php if(!empty($sukses)): ?>
        <div class="alert alert-success"><?= $sukses ?></div>
    <?php endif; ?>
    <?php if(!empty($error)): ?>
        <div class="alert alert-error"><?= $error ?></div>
    <?php endif; ?>

    <div class="upload-form">
        <form method="POST" action="" enctype="multipart/form-data">
            <div class="form-group">
                <label>Nama Produk Elektronik</label>
                <input type="text" name="nama_produk" placeholder="Contoh: Laptop ASUS ROG Strix" required>
            </div>
            <div class="form-group">
                <label>Pilih Gambar Produk (JPG, JPEG, PNG - Maks 2MB)</label>
                <input type="file" name="gambar" accept=".jpg, .jpeg, .png" required>
            </div>
            <button type="submit" name="upload">+ Simpan ke Galeri</button>
        </form>
    </div>

    <h3>Daftar Inventaris Visual:</h3>
    <?php if (count($daftar_produk) === 0): ?>
        <p style="color: #94a3b8; font-style: italic;">Belum ada gambar produk yang diunggah.</p>
    <?php else: ?>
        <div class="product-grid">
            <?php foreach ($daftar_produk as $produk): ?>
                <div class="product-card">
                    <img src="uploads/<?= htmlspecialchars($produk['gambar']) ?>" alt="Gambar Produk">
                    <div class="product-info">
                        <p class="product-name"><?= htmlspecialchars($produk['nama_produk']) ?></p>
                        <a href="?action=delete&id=<?= $produk['id'] ?>" 
                           class="btn-delete" 
                           onclick="return confirm('Apakah Anda yakin ingin menghapus produk ini secara permanen?')">
                           🗑️ Hapus Produk
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

</body>
</html>