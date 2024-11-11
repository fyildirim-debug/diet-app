<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Giriş kontrolü
requireLogin();

$error = '';
$success = '';
$date = $_GET['date'] ?? date('Y-m-d');
$weeklyData = [];
$avgSleep = 0;
$sleepLog = null;
$qualityStats = [
    'poor' => 0,
    'fair' => 0,
    'good' => 0,
    'excellent' => 0
];

try {
    $db = new Database();
    $conn = $db->getConnection();

    // Yeni uyku kaydı ekleme
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_sleep'])) {
        $sleep_time = clean($_POST['sleep_time']);
        $wake_time = clean($_POST['wake_time']);
        $quality = clean($_POST['quality']);
        $notes = clean($_POST['notes']);

        // Uyku ve uyanma zamanlarını datetime formatına çevir
        $sleep_datetime = date('Y-m-d H:i:s', strtotime($date . ' ' . $sleep_time));
        $wake_datetime = date('Y-m-d H:i:s', strtotime($date . ' ' . $wake_time));

        // Eğer uyanma zamanı uyku zamanından önceyse, bir sonraki güne ayarla
        if (strtotime($wake_datetime) <= strtotime($sleep_datetime)) {
            $wake_datetime = date('Y-m-d H:i:s', strtotime($wake_datetime . ' +1 day'));
        }

        // Uyku süresini hesapla (dakika cinsinden)
        $duration = (strtotime($wake_datetime) - strtotime($sleep_datetime)) / 60;

        if($duration <= 0 || $duration > 24*60) {
            $error = 'Geçersiz uyku süresi.';
        } else {
            try {
                // Transaction başlat
                $conn->beginTransaction();

                // Önce varolan kaydı sil (günde bir kayıt olması için)
                $stmt = $conn->prepare("DELETE FROM sleep_tracking WHERE user_id = ? AND date = ?");
                $stmt->execute([$_SESSION['user_id'], $date]);

                // Yeni kaydı ekle
                $stmt = $conn->prepare("
                    INSERT INTO sleep_tracking (
                        user_id, date, sleep_time, wake_time, 
                        duration, quality, notes
                    ) VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $_SESSION['user_id'],
                    $date,
                    $sleep_time,
                    $wake_time,
                    $duration,
                    $quality,
                    $notes
                ]);

                // Transaction'ı tamamla
                $conn->commit();
                $success = 'Uyku kaydı başarıyla eklendi.';

                // Sayfayı yenile
                header("Location: " . $_SERVER['PHP_SELF'] . "?date=" . $date);
                exit;
            } catch (PDOException $e) {
                // Hata durumunda transaction'ı geri al
                $conn->rollBack();
                throw $e;
            }
        }
    }

    // Seçili tarihteki uyku kaydını çek
    $stmt = $conn->prepare("
        SELECT * FROM sleep_tracking 
        WHERE user_id = ? AND date = ?
    ");
    $stmt->execute([$_SESSION['user_id'], $date]);
    $sleepLog = $stmt->fetch(PDO::FETCH_ASSOC);

    // Son 7 günlük uyku verilerini çek
    $stmt = $conn->prepare("
        SELECT date, duration, quality, sleep_time, wake_time, notes
        FROM sleep_tracking 
        WHERE user_id = ? 
        AND date >= DATE_SUB(?, INTERVAL 7 DAY)
        AND date <= ?
        ORDER BY date DESC
    ");
    $stmt->execute([$_SESSION['user_id'], $date, $date]);
    $weeklyData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Ortalama uyku süresini ve kalite istatistiklerini hesapla
    if (!empty($weeklyData)) {
        $totalDuration = 0;
        foreach ($weeklyData as $data) {
            $totalDuration += floatval($data['duration']);
            if (isset($data['quality']) && isset($qualityStats[$data['quality']])) {
                $qualityStats[$data['quality']]++;
            }
        }
        $avgSleep = $totalDuration / count($weeklyData);
    }

} catch (PDOException $e) {
    error_log("Sleep Tracking Error: " . $e->getMessage());
    $error = 'Veritabanı hatası oluştu. Lütfen daha sonra tekrar deneyin.';
} catch (Exception $e) {
    error_log("General Error: " . $e->getMessage());
    $error = 'Bir hata oluştu. Lütfen daha sonra tekrar deneyin.';
}

// Sayfa başlığı
$pageTitle = "Uyku Takibi";
require_once '../../includes/header.php';
?>

    <div class="container py-4">
        <?php if($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-circle me-2"></i><?= $error ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle me-2"></i><?= $success ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row">
            <!-- Sol Kolon - Uyku Kaydı ve İstatistikler -->
            <div class="col-lg-4">
                <!-- Uyku Kaydı Formu -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-bed me-2"></i>Uyku Kaydı
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="" class="needs-validation" novalidate>
                            <div class="mb-3">
                                <label class="form-label">Tarih</label>
                                <input type="date"
                                       class="form-control"
                                       value="<?= $date ?>"
                                       max="<?= date('Y-m-d') ?>"
                                       onchange="location.href='?date='+this.value">
                            </div>

                            <div class="row mb-3">
                                <div class="col-6">
                                    <label class="form-label">Uyku Saati</label>
                                    <input type="time"
                                           name="sleep_time"
                                           class="form-control"
                                           required
                                           value="<?= $sleepLog['sleep_time'] ?? '23:00' ?>">
                                    <div class="invalid-feedback">Uyku saati gerekli</div>
                                </div>
                                <div class="col-6">
                                    <label class="form-label">Uyanma Saati</label>
                                    <input type="time"
                                           name="wake_time"
                                           class="form-control"
                                           required
                                           value="<?= $sleepLog['wake_time'] ?? '07:00' ?>">
                                    <div class="invalid-feedback">Uyanma saati gerekli</div>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Uyku Kalitesi</label>
                                <select name="quality" class="form-select" required>
                                    <option value="">Seçiniz</option>
                                    <option value="poor" <?= ($sleepLog['quality'] ?? '') == 'poor' ? 'selected' : '' ?>>
                                        😴 Kötü
                                    </option>
                                    <option value="fair" <?= ($sleepLog['quality'] ?? '') == 'fair' ? 'selected' : '' ?>>
                                        🛏️ Orta
                                    </option>
                                    <option value="good" <?= ($sleepLog['quality'] ?? '') == 'good' ? 'selected' : '' ?>>
                                        😊 İyi
                                    </option>
                                    <option value="excellent" <?= ($sleepLog['quality'] ?? '') == 'excellent' ? 'selected' : '' ?>>
                                        🌟 Mükemmel
                                    </option>
                                </select>
                                <div class="invalid-feedback">Uyku kalitesi seçiniz</div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Notlar</label>
                                <textarea name="notes"
                                          class="form-control"
                                          rows="2"
                                          placeholder="Uykunuzla ilgili notlar..."><?= $sleepLog['notes'] ?? '' ?></textarea>
                            </div>

                            <div class="d-grid">
                                <button type="submit" name="add_sleep" class="btn btn-primary">
                                    <i class="fas fa-save me-2"></i>Kaydet
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Uyku İstatistikleri -->
                <div class="card shadow-sm">
                    <div class="card-header bg-info text-white">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-chart-pie me-2"></i>Haftalık İstatistikler
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-4">
                            <h6 class="text-muted mb-2">Ortalama Uyku Süresi</h6>
                            <h3 class="mb-0"><?= number_format($avgSleep/60, 1) ?> saat</h3>
                            <div class="progress mt-2" style="height: 8px;">
                                <div class="progress-bar bg-info"
                                     style="width: <?= min(($avgSleep/480) * 100, 100) ?>%">
                                </div>
                            </div>
                        </div>

                        <div class="mb-4">
                            <h6 class="text-muted mb-2">Uyku Kalitesi Dağılımı</h6>
                            <div class="d-flex justify-content-between mb-2">
                                <span>😴 Kötü</span>
                                <span class="badge bg-danger"><?= $qualityStats['poor'] ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>🛏️ Orta</span>
                                <span class="badge bg-warning"><?= $qualityStats['fair'] ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>😊 İyi</span>
                                <span class="badge bg-success"><?= $qualityStats['good'] ?></span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span>🌟 Mükemmel</span>
                                <span class="badge bg-primary"><?= $qualityStats['excellent'] ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sağ Kolon - Grafikler ve Geçmiş -->
            <div class="col-lg-8">
                <!-- Haftalık Uyku Grafiği -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-chart-line me-2"></i>Haftalık Uyku Takibi
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if(empty($weeklyData)): ?>
                            <p class="text-center text-muted my-4">Henüz uyku verisi bulunmuyor.</p>
                        <?php else: ?>
                            <canvas id="sleepChart" height="300"></canvas>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Uyku Geçmişi -->
                <div class="card shadow-sm">
                    <div class="card-header bg-primary text-white">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-history me-2"></i>Uyku Geçmişi
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if(empty($weeklyData)): ?>
                            <p class="text-center text-muted my-4">
                                Henüz uyku kaydı bulunmuyor.
                            </p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                    <tr>
                                        <th>Tarih</th>
                                        <th>Uyku Saati</th>
                                        <th>Uyanma Saati</th>
                                        <th>Süre</th>
                                        <th>Kalite</th>
                                        <th>Notlar</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach($weeklyData as $data): ?>
                                        <tr>
                                            <td><?= date('d.m.Y', strtotime($data['date'])) ?></td>
                                            <td><?= date('H:i', strtotime($data['sleep_time'])) ?></td>
                                            <td><?= date('H:i', strtotime($data['wake_time'])) ?></td>
                                            <td><?= number_format($data['duration']/60, 1) ?> saat</td>
                                            <td>
                                                <?php
                                                $qualityIcons = [
                                                    'poor' => '😴',
                                                    'fair' => '🛏️',
                                                    'good' => '😊',
                                                    'excellent' => '🌟'
                                                ];
                                                echo $qualityIcons[$data['quality']] ?? $data['quality'];
                                                ?>
                                            </td>
                                            <td><?= htmlspecialchars($data['notes'] ?? '') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

<?php if(!empty($weeklyData)): ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Haftalık uyku grafiği
        const weeklyData = <?= json_encode($weeklyData) ?>;
        const dates = weeklyData.map(item => item.date);
        const durations = weeklyData.map(item => item.duration/60);
        const qualities = weeklyData.map(item => item.quality);

        new Chart(document.getElementById('sleepChart'), {
            type: 'bar',
            data: {
                labels: dates.map(date => new Date(date).toLocaleDateString('tr-TR', {weekday: 'short', day: 'numeric'})),
                datasets: [{
                    label: 'Uyku Süresi (Saat)',
                    data: durations,
                    backgroundColor: qualities.map(quality => {
                        switch(quality) {
                            case 'poor': return '#dc3545';
                            case 'fair': return '#ffc107';
                            case 'good': return '#28a745';
                            case 'excellent': return '#007bff';
                            default: return '#6c757d';
                        }
                    }),
                    borderRadius: 5
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return value + ' saat';
                            }
                        }
                    }
                }
            }
        });
    </script>
<?php endif; ?>

    <script>
        // Form doğrulama
        (function () {
            'use strict'
            var forms = document.querySelectorAll('.needs-validation')
            Array.prototype.slice.call(forms).forEach(function (form) {
                form.addEventListener('submit', function (event) {
                    if (!form.checkValidity()) {
                        event.preventDefault()
                        event.stopPropagation()
                    }
                    form.classList.add('was-validated')
                }, false)
            })
        })()
    </script>

<?php require_once '../../includes/footer.php'; ?>