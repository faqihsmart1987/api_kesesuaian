<?php
session_start();

include __DIR__ . '/../config/koneksi.php';

// ⛔ Cek login user
if (!isset($_SESSION['login']) || !isset($_SESSION['id'])) {
    header("Location: ../controller/login.php");
    exit;
}

$login_user_id = $_SESSION['id']; // pengguna.id

// 🧩 Ambil data alumni berdasarkan user yang login
$q_alumni = mysqli_query($koneksi, "
    SELECT * FROM alumni WHERE user_id = '$login_user_id' LIMIT 1
");
$alumni = mysqli_fetch_assoc($q_alumni);

// Default jika tidak ada data alumni
$default = [
    'nama' => $alumni['nama'] ?? '',
    'jurusan' => $alumni['jurusan'] ?? '',
    'nilai_ujikom' => $alumni['nilai_ujikom'] ?? 80,
    'nilai_kejuruan' => $alumni['nilai_kejuruan'] ?? 75,
    'tempat_pkl_relevan' => $alumni['tempat_pkl_relevan'] ?? 1,
    'ekskul_aktif' => $alumni['ekskul_aktif'] ?? 0,
    'status_tracer' => $alumni['status_tracer'] ?? 'Bekerja',
    'bidang_pekerjaan' => $alumni['bidang_pekerjaan'] ?? '',
    'jabatan_pekerjaan' => $alumni['jabatan_pekerjaan'] ?? '',
    'pendapatan' => $alumni['pendapatan'] ?? 3000000
];

// 🧠 Cek apakah Flask (model_kesesuaian) sedang berjalan (port 5002)
$flask_alive = false;
$socket = @fsockopen('127.0.0.1', 5002, $errno, $errstr, 0.5);
if ($socket) {
    fclose($socket);
    $flask_alive = true;
}

$result_html = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Ambil input POST (pakai data dari hidden input yang sudah diisi otomatis)
    $nama = trim($_POST['nama'] ?? $default['nama']);
    $jurusan = trim($_POST['jurusan'] ?? $default['jurusan']);
    $nilai_ujikom = (float)($_POST['nilai_ujikom'] ?? $default['nilai_ujikom']);
    $nilai_kejuruan = (float)($_POST['nilai_kejuruan'] ?? $default['nilai_kejuruan']);
    $tempat_pkl_relevan = (int)($_POST['tempat_pkl_relevan'] ?? $default['tempat_pkl_relevan']);
    $ekskul_aktif = (int)($_POST['ekskul_aktif'] ?? $default['ekskul_aktif']);
    $status_tracer = trim($_POST['status_tracer'] ?? $default['status_tracer']);
    $bidang_pekerjaan = trim($_POST['bidang_pekerjaan'] ?? $default['bidang_pekerjaan']);
    $jabatan_pekerjaan = trim($_POST['jabatan_pekerjaan'] ?? $default['jabatan_pekerjaan']);
    $pendapatan = (int)($_POST['pendapatan'] ?? $default['pendapatan']);

    // Validasi minimal
    $required = ['jurusan','nilai_ujikom','nilai_kejuruan','status_tracer','pendapatan'];
    $missing = [];
    foreach ($required as $r) {
        if (($r === 'jurusan' || $r === 'status_tracer') && ($$r === '' || $$r === null)) {
            $missing[] = $r;
        }
        if ($r === 'pendapatan' && ($pendapatan === null || $pendapatan === '')) {
            $missing[] = $r;
        }
    }

    if (!empty($missing)) {
        $result_html = "<div class='result error'>Input belum lengkap. Lengkapi field: " . implode(", ", $missing) . "</div>";
    } elseif (!$flask_alive) {
        $result_html = "<div class='result error'>AI Engine (Flask) tidak aktif. Nyalakan Flask dulu lewat tombol AI di atas.</div>";
    } else {
        // Siapkan payload JSON untuk API Flask
        $payload_arr = [
            'jurusan' => $jurusan,
            'nilai_ujikom' => $nilai_ujikom,
            'nilai_kejuruan' => $nilai_kejuruan,
            'tempat_pkl_relevan' => $tempat_pkl_relevan,
            'ekskul_aktif' => $ekskul_aktif,
            'status_tracer' => $status_tracer,
            'bidang_pekerjaan' => $bidang_pekerjaan,
            'jabatan_pekerjaan' => $jabatan_pekerjaan,
            'pendapatan' => $pendapatan
        ];

        $payload = json_encode($payload_arr);

        // Curl ke Flask (port 5002)
        $ch = curl_init('http://localhost:5002/predict_kesesuaian');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        $response = curl_exec($ch);
        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err = curl_error($ch);
        curl_close($ch);

        if ($response === false || $http_status !== 200) {
            $msg = $curl_err ?: "HTTP $http_status";
            $result_html = "<div class='result error'>Gagal memanggil model AI: $msg</div>";
        } else {
            $hasil = json_decode($response, true);
            if (!$hasil) {
                $result_html = "<div class='result error'>Respon AI bukan JSON atau kosong. Raw: " . htmlspecialchars($response) . "</div>";
            } else {
                // --- Support 2 format response (baru: grouped_reasons + suggestions, lama: reasons)
                $prediksi = isset($hasil['prediksi']) ? htmlspecialchars($hasil['prediksi']) : 'N/A';
                $prob_sesuai = isset($hasil['probability']['sesuai']) ? round(floatval($hasil['probability']['sesuai']) * 100, 2) : null;
                $prob_tidak = isset($hasil['probability']['tidak_sesuai']) ? round(floatval($hasil['probability']['tidak_sesuai']) * 100, 2) : null;

                // Build HTML
                $result_html = "<div class='result'>
                    <strong>🎯 HASIL ANALIS PEKERJAAN ANDA :</strong>
                    <div class='prediksi'>{$prediksi}</div>
                    <div style='margin-top:10px;'>
                        <b>Persentase Keyakinan:</b><br>
                        Sesuai: " . ($prob_sesuai !== null ? $prob_sesuai . "%" : "N/A") . "<br>
                        Tidak Sesuai: " . ($prob_tidak !== null ? $prob_tidak . "%" : "N/A") . "
                    </div>";

                // NEW format: grouped_reasons
                if (isset($hasil['grouped_reasons']) && is_array($hasil['grouped_reasons'])) {
                    $result_html .= "<div style='margin-top:12px;'><b>Alasan Hasil Analisis AI :</b>";
                    foreach ($hasil['grouped_reasons'] as $group => $items) {
                        if (!is_array($items) || count($items) === 0) continue;
                        $result_html .= "<div style='margin-top:8px;'><b style='text-transform:uppercase;'>" . htmlspecialchars($group) . "</b><ul>";
                        foreach ($items as $it) {
                            // handle object shape {message,label,severity,effect}
                            if (is_array($it) && isset($it['message'])) {
                                $lbl = htmlspecialchars($it['label'] ?? $it['feature'] ?? '');
                                $msg = htmlspecialchars($it['message']);
                                $sev = htmlspecialchars($it['severity'] ?? '');
                                $result_html .= "<li><strong>{$lbl}:</strong> {$msg} <small style='color:#666;'>({$sev})</small></li>";
                            } elseif (is_string($it)) {
                                $result_html .= "<li>" . htmlspecialchars($it) . "</li>";
                            }
                        }
                        $result_html .= "</ul></div>";
                    }
                    $result_html .= "</div>";
                }
                // OLD format: reasons (fallback)
                elseif (isset($hasil['reasons']) && is_array($hasil['reasons'])) {
                    $result_html .= "<div style='margin-top:12px;'><b>Alasan Hasil Analisis AI :</b><ul>";
                    foreach ($hasil['reasons'] as $r) {
                        $result_html .= "<li>" . htmlspecialchars($r) . "</li>";
                    }
                    $result_html .= "</ul></div>";
                }

                // Suggestions (either new field 'suggestions' or none)
                if (isset($hasil['suggestions']) && is_array($hasil['suggestions']) && count($hasil['suggestions'])>0) {
                    $result_html .= "<div style='margin-top:12px; background:#f6fffa; padding:10px; border-radius:6px; border:1px solid #e6ffee;'>
                        <b>SARAN UNTUK ANDA :</b><ul style='margin-top:8px;'>";
                    foreach ($hasil['suggestions'] as $s) {
                        $result_html .= "<li>" . htmlspecialchars($s) . "</li>";
                    }
                    $result_html .= "</ul></div>";
                }

                $result_html .= "<div style='margin-top:8px; color:#555; font-size:13px;'>Input diuji untuk : " . ($nama !== '' ? htmlspecialchars($nama) . " — " : "") . htmlspecialchars($jurusan) . "</div>";

                $result_html .= "</div>"; // close result
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Analisa Kesesuaian Pekerjaan</title>
<style>
    body {
        font-family: 'Segoe UI', sans-serif;
        background:
            linear-gradient(rgba(0, 0, 0, 0.55), rgba(0, 0, 0, 0.55)),
            url('../assets/img/sekolah.jpg') no-repeat center center fixed;
        background-size: cover;
        margin: 0;
        padding: 0;
        min-height: 100vh;
        display: flex;
        flex-direction: column;
        align-items: center;
    }

    /* === AI Glass Button Card (sama seperti contohmu) === */
    .ai-toggle-container {
        margin-top: 90px;
        display: flex;
        justify-content: center;
        width: 100%;
    }

header.navbar-blur {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    background: rgba(255,255,255,0.1);
    backdrop-filter: blur(12px);
    box-shadow: 0 4px 10px rgba(0,0,0,0.2);
    z-index: 1000;
}

    .ai-card {
        background: rgba(255, 255, 255, 0.12);
        border: 1px solid rgba(255, 255, 255, 0.3);
        backdrop-filter: blur(14px);
        -webkit-backdrop-filter: blur(14px);
        box-shadow: 0 0 25px rgba(0, 255, 204, 0.25);
        border-radius: 18px;
        padding: 18px 30px;
        text-align: center;
        max-width: 300px;
        transition: all 0.4s ease;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 6px;
    }

    .ai-card.on {
        background: rgba(0, 255, 153, 0.15);
        border-color: rgba(0, 255, 180, 0.6);
        box-shadow: 0 0 30px rgba(0, 255, 150, 0.45);
        animation: glowPulse 2.5s infinite;
    }

    .ai-card.off {
        background: rgba(255, 255, 255, 0.1);
        border-color: rgba(255, 255, 255, 0.25);
        box-shadow: 0 0 10px rgba(80, 80, 80, 0.3);
    }

    @keyframes glowPulse {
        0% { box-shadow: 0 0 10px rgba(0,255,180,0.4); }
        50% { box-shadow: 0 0 35px rgba(0,255,180,0.7); }
        100% { box-shadow: 0 0 10px rgba(0,255,180,0.4); }
    }

    .ai-btn {
        background: transparent;
        color: white;
        font-size: 17px;
        font-weight: bold;
        border: none;
        cursor: pointer;
        transition: 0.3s ease;
        padding: 10px 25px;
        border-radius: 30px;
        backdrop-filter: blur(6px);
        letter-spacing: 0.5px;
    }

    .ai-card.on .ai-btn:hover {
        color: #00ffcc;
        text-shadow: 0 0 10px #00ffcc;
    }

    .ai-card.off .ai-btn:hover {
        color: #ccc;
        text-shadow: 0 0 5px #aaa;
    }

    .ai-status-text {
        font-size: 14px;
        color: #aefcff;
        text-shadow: 0 0 6px rgba(0,255,255,0.6);
        font-weight: 500;
    }

    .gear {
        display: inline-block;
        animation: spin 1s linear infinite;
    }

    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }

    /* === Box utama (judul dan form) === */
    .prediksi-box {
        background: rgba(255, 255, 255, 0.18);
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
        padding: 30px;
        max-width: 900px;
        width: 92%;
        border-radius: 16px;
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.35);
        animation: fadeIn 0.8s ease;
        border: 1px solid rgba(255, 255, 255, 0.3);
        margin: 40px 0 80px 0;
    }

    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(20px); }
        to { opacity: 1; transform: translateY(0); }
    }

    /* judul style sama seperti contohmu */
    h2 { text-align: center; color: #00bfff; font-weight: bold; margin-bottom: 20px; }

    .form-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px 40px;
    }

    @media (max-width: 900px) {
        .form-grid { grid-template-columns: 1fr; }
    }

    .input-group label {
        display: block;
        margin-bottom: 6px;
        color: #f0f0f0;
        font-weight: bold;
    }

    .input-group input {
        display: block;
        width: 90%;
        padding: 10px 12px;
        border-radius: 6px;
        border: 1px solid #ccc;
        background: rgba(255, 255, 255, 0.95);
        font-size: 15px;
    }

    button[type="submit"] {
        width: 100%;
        padding: 12px;
        margin-top: 15px;
        background: #27ae60;
        border: none;
        color: white;
        font-size: 16px;
        font-weight: bold;
        border-radius: 6px;
        cursor: pointer;
        transition: 0.3s;
    }

    button[type="submit"]:hover { background: #219150; }

    .result {
        margin-top: 25px;
        padding: 18px;
        background: rgba(240, 249, 240, 0.95);
        border-left: 8px solid #27ae60;
        border-radius: 8px;
        color: #2c3e50;
    }

    .result.error {
        background: rgba(255, 240, 240, 0.95);
        border-left: 8px solid #e74c3c;
        color: #9b2b2b;
    }

    .prediksi { font-size: 26px; color: #27ae60; font-weight: bold; margin-top:8px; }

    .link-kembali {
        display: block;
        margin-top: 20px;
        text-align: center;
        color: #fff;
        font-weight: bold;
        text-decoration: none;
    }
    .link-kembali:hover { color: #27ae60; }
</style>
</head>
<body>

<header class="navbar-blur">
    <?php include '../includes/navbar.php'; ?>
</header>

<!-- 🧠 Tombol AI Predictor (Glass Card) -->
<div class="ai-toggle-container">
    <div class="ai-card <?php echo $flask_alive ? 'on' : 'off'; ?>">
        <button type="button" id="btnFlask" class="ai-btn">
            <?php echo $flask_alive ? '🟢 AI Aktif' : '⚫ AI Mati'; ?>
        </button>
        <div id="aiStatus" class="ai-status-text">
            <?php echo $flask_alive 
                ? 'Flask Predictor sedang berjalan' 
                : 'Klik tombol untuk menyalakan AI Predictor.'; ?>
        </div>
    </div>
</div>

<!-- 🧮 Form Prediksi (data otomatis dari DB, readonly) -->
<div class="prediksi-box">
    <h2>Analisis Kesesuaian Pekerjaan</h2>

    <form method="POST">
        <div class="form-grid">
            <div class="input-group">
                <label>Nama Alumni :</label>
                <input type="text" value="<?= htmlspecialchars($default['nama']); ?>" readonly>
                <input type="hidden" name="nama" value="<?= htmlspecialchars($default['nama']); ?>">
            </div>

            <div class="input-group">
                <label>Jurusan :</label>
                <input type="text" value="<?= htmlspecialchars($default['jurusan']); ?>" readonly>
                <input type="hidden" name="jurusan" value="<?= htmlspecialchars($default['jurusan']); ?>">
            </div>

            <div class="input-group">
                <label>Nilai Uji Kompetensi :</label>
                <input type="text" value="<?= htmlspecialchars($default['nilai_ujikom']); ?>" readonly>
                <input type="hidden" name="nilai_ujikom" value="<?= htmlspecialchars($default['nilai_ujikom']); ?>">
            </div>

            <div class="input-group">
                <label>Nilai Kejuruan :</label>
                <input type="text" value="<?= htmlspecialchars($default['nilai_kejuruan']); ?>" readonly>
                <input type="hidden" name="nilai_kejuruan" value="<?= htmlspecialchars($default['nilai_kejuruan']); ?>">
            </div>

            <div class="input-group">
                <label>Tempat PKL Relevan :</label>
                <input type="text" value="<?= $default['tempat_pkl_relevan'] == 1 ? 'Ya' : 'Tidak'; ?>" readonly>
                <input type="hidden" name="tempat_pkl_relevan" value="<?= $default['tempat_pkl_relevan']; ?>">
            </div>

            <div class="input-group">
                <label>Ekskul Aktif :</label>
                <input type="text" value="<?= $default['ekskul_aktif'] == 1 ? 'Ya' : 'Tidak'; ?>" readonly>
                <input type="hidden" name="ekskul_aktif" value="<?= $default['ekskul_aktif']; ?>">
            </div>

            <div class="input-group">
                <label>Status Tracer :</label>
                <input type="text" value="<?= htmlspecialchars($default['status_tracer']); ?>" readonly>
                <input type="hidden" name="status_tracer" value="<?= htmlspecialchars($default['status_tracer']); ?>">
            </div>

            <div class="input-group">
                <label>Bidang Pekerjaan :</label>
                <input type="text" value="<?= htmlspecialchars($default['bidang_pekerjaan']); ?>" readonly>
                <input type="hidden" name="bidang_pekerjaan" value="<?= htmlspecialchars($default['bidang_pekerjaan']); ?>">
            </div>

            <div class="input-group">
                <label>Jabatan Pekerjaan :</label>
                <input type="text" value="<?= htmlspecialchars($default['jabatan_pekerjaan']); ?>" readonly>
                <input type="hidden" name="jabatan_pekerjaan" value="<?= htmlspecialchars($default['jabatan_pekerjaan']); ?>">
            </div>

            <div class="input-group">
                <label>Pendapatan (Rp):</label>
                <input type="text" value="<?= number_format($default['pendapatan']); ?>" readonly>
                <input type="hidden" name="pendapatan" value="<?= htmlspecialchars($default['pendapatan']); ?>">
            </div>
        </div>

        <br>

        <!-- 🔥 Bagian Konfirmasi Data -->
        <div style="margin-top:20px; padding:15px; background:rgba(255,255,255,0.15); border-radius:10px;">
            <p style="color:white; font-weight:bold; margin-bottom:10px;">
                Apakah data di atas sudah sesuai?
            </p>

            <a href="../controller/profil.php"
                style="
                display:inline-block; 
                padding:10px 15px; 
                background:#ff5722; 
                color:white; 
                font-weight:bold; 
                border-radius:6px; 
                margin-right:10px;">
                ❌ Belum, saya ingin ubah data
            </a>

            <p style="color:#ddd; margin:10px 0;">Jika sudah benar, klik tombol Prediksi:</p>
        </div>

        <button type="submit">🔍 Prediksi Kesesuaian</button>
    </form>

    <?php
    // Tampilkan hasil jika ada
    if ($result_html !== '') {
        echo $result_html;
    }
    ?>

    <a href="../index.php" class="link-kembali">🔙 Kembali ke Beranda</a>
</div>

<script>
const btn = document.getElementById('btnFlask');
const statusText = document.getElementById('aiStatus');

btn.addEventListener('click', () => {
    if (btn.innerText.includes('Mati')) {
        btn.disabled = true;
        btn.innerHTML = '<span class="gear">⚙️</span> Menyalakan...';
        statusText.innerHTML = "⚙️ Menyiapkan server Flask...";
        fetch('start_flask.php')
            .then(r => r.text())
            .then(t => {
                btn.disabled = false;
                if (t.includes("sukses") || t.toLowerCase().includes("started")) {
                    btn.innerText = "🟢 AI Aktif";
                    btn.closest('.ai-card').classList.replace('off', 'on');
                    statusText.innerHTML = "✅ Flask berhasil dijalankan!";
                } else {
                    btn.innerText = "⚫ AI Mati";
                    statusText.innerHTML = "⚠️ Gagal menjalankan Flask.";
                }
            }).catch(()=> {
                btn.disabled = false;
                btn.innerText = "⚫ AI Mati";
                statusText.innerHTML = "⚠️ Gagal menjalankan Flask.";
            });
    } else {
        btn.disabled = true;
        btn.innerHTML = '<span class="gear">⚙️</span> Mematikan...';
        statusText.innerHTML = "🛑 Menonaktifkan Flask...";
        fetch('stop_flask.php')
            .then(r => r.text())
            .then(t => {
                btn.disabled = false;
                if (t.includes("stopped") || t.toLowerCase().includes("stopped")) {
                    btn.innerText = "⚫ AI Mati";
                    btn.closest('.ai-card').classList.replace('on', 'off');
                    statusText.innerHTML = "🧊 Flask berhasil dimatikan.";
                } else {
                    btn.innerText = "🟢 AI Aktif";
                    statusText.innerHTML = "⚠️ Gagal mematikan Flask.";
                }
            }).catch(()=> {
                btn.disabled = false;
                btn.innerText = "🟢 AI Aktif";
                statusText.innerHTML = "⚠️ Gagal mematikan Flask.";
            });
    }
});
</script>

<?php include '../includes/footer.php'; ?>
</body>
</html>
