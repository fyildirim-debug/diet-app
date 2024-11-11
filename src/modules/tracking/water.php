<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Giriş kontrolü
requireLogin();

$error = '';
$success = '';
$date = $_GET['date'] ?? date('Y-m-d');

try {
    $db = new Database();
    $conn = $db->getConnection();

    // Yeni su kaydı ekleme
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_water'])) {
        $amount = clean($_POST['amount']);
        $time = clean($_POST['time']);

        if(empty($amount) || $amount <= 0) {
            $error = 'Geçerli bir miktar girin.';
        } else {
            $stmt = $conn->prepare("
                INSERT INTO water_tracking (user_id, amount, date, time) 
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$_SESSION['user_id'], $amount, $date, $time]);
            $success = 'Su tüketimi başarıyla kaydedildi.';
        }
    }

    // Su kaydı silme
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_water'])) {
        $id = clean($_POST['water_id']);
        $stmt = $conn->prepare("DELETE FROM water_tracking WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $_SESSION['user_id']]);
        $success = 'Kayıt başarıyla silindi.';
    }

    // Günlük su tüketimini çek
    $stmt = $conn->prepare("
        SELECT id, amount, time 
        FROM water_tracking 
        WHERE user_id = ? AND DATE(date) = ? 
        ORDER BY time ASC
    ");
    $stmt->execute([$_SESSION['user_id'], $date]);
    $waterLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Toplam su tüketimini hesapla
    $totalWater = array_sum(array_column($waterLogs, 'amount'));

    // Son 7 günlük su tüketimi
    $stmt = $conn->prepare("
        SELECT DATE(date) as date, SUM(amount) as total 
        FROM water_tracking 
        WHERE user_id = ? 
        AND date >= DATE_SUB(CURRENT_DATE, INTERVAL 7 DAY)
        GROUP BY DATE(date) 
        ORDER BY date ASC
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $weeklyData = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Water Tracking Error: " . $e->getMessage());
    $error = 'Bir hata oluştu, lütfen tekrar deneyin.';
}

require_once '../../includes/header.php';
?>

    <div class="container py-4">
        <div class="row">
            <!-- Sol Kolon - Su Ekleme ve Özet -->
            <div class="col-md-4 mb-4">
                <!-- Hızlı Su Ekleme -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-tint text-primary"></i> Hızlı Su Ekle
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if($error): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <i class="fas fa-exclamation-circle me-2"></i><?= $error ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <?php if($success): ?>
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <i class="fas fa-check-circle me-2"></i><?= $success ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <div class="row g-2 mb-3">
                            <?php
                            $quickAmounts = [200, 300, 500];
                            foreach($quickAmounts as $amount):
                                ?>
                                <div class="col-4">
                                    <form method="POST" action="">
                                        <input type="hidden" name="amount" value="<?= $amount ?>">
                                        <input type="hidden" name="time" value="<?= date('H:i') ?>">
                                        <button type="submit" name="add_water" class="btn btn-outline-primary w-100">
                                            <?= $amount ?>ml
                                        </button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <form method="POST" action="" class="needs-validation" novalidate>
                            <div class="mb-3">
                                <label class="form-label">Özel Miktar (ml)</label>
                                <div class="input-group">
                                    <input type="number"
                                           name="amount"
                                           class="form-control"
                                           required
                                           min="1"
                                           step="1">
                                    <input type="time"
                                           name="time"
                                           class="form-control"
                                           required
                                           value="<?= date('H:i') ?>">
                                    <button type="submit" name="add_water" class="btn btn-primary">
                                        <i class="fas fa-plus"></i>
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Günlük Özet -->
                <div class="card shadow-sm">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-chart-pie text-primary"></i> Günlük Özet
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="text-center mb-3">
                            <div class="display-4 text-primary mb-0"><?= number_format($totalWater/1000, 1) ?>L</div>
                            <small class="text-secondary">/ 3L hedef</small>
                        </div>

                        <div class="progress mb-3" style="height: 10px;">
                            <div class="progress-bar bg-info"
                                 style="width: <?= min(($totalWater/3000) * 100, 100) ?>%">
                            </div>
                        </div>

                        <div class="text-center">
                            <?php
                            $remaining = max(3000 - $totalWater, 0);
                            if($remaining > 0):
                                ?>
                                <small class="text-secondary">
                                    Hedefe ulaşmak için <?= number_format($remaining) ?>ml daha için
                                </small>
                            <?php else: ?>
                                <small class="text-success">
                                    <i class="fas fa-check-circle"></i> Günlük hedefinize ulaştınız!
                                </small>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sağ Kolon - Günlük Kayıtlar ve Grafik -->
            <div class="col-md-8">
                <!-- Haftalık Grafik -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-chart-line text-primary"></i> Haftalık Takip
                        </h5>
                    </div>
                    <div class="card-body">
                        <canvas id="waterChart" height="150"></canvas>
                    </div>
                </div>

                <!-- Günlük Kayıtlar -->
                <div class="card shadow-sm">
                    <div class="card-header bg-light">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-list text-primary"></i> Günlük Kayıtlar
                            </h5>
                            <input type="date"
                                   class="form-control form-control-sm"
                                   style="width: auto;"
                                   value="<?= $date ?>"
                                   max="<?= date('Y-m-d') ?>"
                                   onchange="location.href='?date='+this.value">
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if(empty($waterLogs)): ?>
                            <p class="text-center text-muted my-4">
                                Bu tarihte su tüketimi kaydı bulunmuyor.
                            </p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                    <tr>
                                        <th>Saat</th>
                                        <th>Miktar</th>
                                        <th></th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach($waterLogs as $log): ?>
                                        <tr>
                                            <td><?= date('H:i', strtotime($log['time'])) ?></td>
                                            <td><?= number_format($log['amount']) ?> ml</td>
                                            <td class="text-end">
                                                <form method="POST" class="d-inline"
                                                      onsubmit="return confirm('Bu kaydı silmek istediğinizden emin misiniz?')">
                                                    <input type="hidden" name="water_id" value="<?= $log['id'] ?>">
                                                    <button type="submit"
                                                            name="delete_water"
                                                            class="btn btn-sm btn-outline-danger">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <tr class="table-light">
                                        <td><strong>Toplam</strong></td>
                                        <td colspan="2">
                                            <strong><?= number_format($totalWater) ?> ml</strong>
                                        </td>
                                    </tr>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Grafik stilleri
        Chart.defaults.font.family = "'Inter', sans-serif";
        Chart.defaults.color = '#6e7687';
        Chart.defaults.plugins.tooltip.backgroundColor = 'rgba(0, 0, 0, 0.8)';
        Chart.defaults.plugins.tooltip.padding = 12;
        Chart.defaults.plugins.tooltip.cornerRadius = 8;
        Chart.defaults.plugins.tooltip.titleFont.size = 14;
        Chart.defaults.plugins.tooltip.bodyFont.size = 13;
        Chart.defaults.elements.point.radius = 4;
        Chart.defaults.elements.point.hoverRadius = 6;
        Chart.defaults.elements.line.borderWidth = 2;

        // Haftalık su tüketimi grafiği
        const weeklyData = <?= json_encode($weeklyData) ?>;
        const dates = weeklyData.map(item => {
            const date = new Date(item.date);
            return date.toLocaleDateString('tr-TR', { weekday: 'short', day: 'numeric' });
        });
        const amounts = weeklyData.map(item => item.total/1000); // Litreye çevir

        new Chart(document.getElementById('waterChart'), {
            type: 'line',
            data: {
                labels: dates,
                datasets: [{
                    label: 'Su Tüketimi (L)',
                    data: amounts,
                    borderColor: '#36b9cc',
                    backgroundColor: 'rgba(54, 185, 204, 0.1)',
                    tension: 0.1,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return `${context.parsed.y} L`;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return value + 'L';
                            }
                        },
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });

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