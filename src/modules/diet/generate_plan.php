<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Giriş kontrolü
requireLogin();

$error = '';
$success = '';

try {
    $db = new Database();
    $conn = $db->getConnection();

    // Kullanıcı profilini çek
    $stmt = $conn->prepare("
        SELECT up.*, u.name 
        FROM user_profiles up 
        JOIN users u ON u.id = up.user_id 
        WHERE up.user_id = ?
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $userProfile = $stmt->fetch(PDO::FETCH_ASSOC);

    // Sağlık durumlarını çek
    $stmt = $conn->prepare("SELECT condition_name FROM health_conditions WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $healthConditions = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Mevcut programları çek
    $stmt = $conn->prepare("
        SELECT * FROM diet_programs 
        WHERE user_id = ? 
        AND end_date >= CURRENT_DATE
        ORDER BY created_at DESC
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $activePrograms = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        $program_type = clean($_POST['program_type']);
        $preferences = isset($_POST['preferences']) ? $_POST['preferences'] : [];

        try {
            $mealCount = $program_type == 'daily' ? 1 : ($program_type == 'weekly' ? 7 : 30);

            $prompt = "Bir diyet programı oluştur. Yanıtını kesinlikle aşağıdaki JSON formatında ver:
            {
              \"meals\": [
                {
                  \"day\": 1,
                  \"breakfast\": {
                    \"meal\": \"2 yumurta, 1 dilim tam buğday ekmeği, 5 zeytin\",
                    \"calories\": 300,
                    \"time\": \"08:00\"
                  },
                  \"morning_snack\": {
                    \"meal\": \"1 elma, 5 badem\",
                    \"calories\": 150,
                    \"time\": \"10:30\"
                  },
                  \"lunch\": {
                    \"meal\": \"120g ızgara tavuk, 1 kase bulgur pilavı, salata\",
                    \"calories\": 450,
                    \"time\": \"13:00\"
                  },
                  \"afternoon_snack\": {
                    \"meal\": \"1 kase yoğurt, 1 avuç üzüm\",
                    \"calories\": 200,
                    \"time\": \"16:00\"
                  },
                  \"dinner\": {
                    \"meal\": \"1 porsiyon mercimek çorbası, 1 dilim tam buğday ekmeği\",
                    \"calories\": 350,
                    \"time\": \"19:00\"
                  },
                  \"evening_snack\": {
                    \"meal\": \"1 bardak süt\",
                    \"calories\": 150,
                    \"time\": \"21:00\"
                  }
                }
              ],
              \"notes\": [
                \"Günde en az 2 litre su için\",
                \"Öğünleri belirtilen saatlerde tüketmeye çalışın\",
                \"Sebze tüketimine özen gösterin\"
              ]
            }

            Kriterler:
            - {$mealCount} günlük program oluştur
            - Günlük kalori hedefi: {$userProfile['daily_calorie_limit']} kcal
            - Diyet tipi: {$userProfile['diet_type']}
            - Sağlık durumları: " . implode(', ', $healthConditions) . "
            - Tercihler: " . implode(', ', $preferences) . "

            Önemli notlar:
            - Sadece Türk mutfağından yemekler kullan
            - Kolay bulunabilir malzemeler seç
            - Pratik tarifler ver
            - Her öğünün kalori değerlerini belirt
            - Yanıtı sadece JSON formatında ver, başka açıklama ekleme";

            $response = askOpenAI($prompt);

            if (!$response || !isset($response['choices'][0]['message']['content'])) {
                throw new Exception('OpenAI API yanıtı alınamadı.');
            }

            // JSON yanıtını temizle ve parse et
            $content = trim($response['choices'][0]['message']['content']);

            // JSON başlangıç ve bitiş karakterlerini kontrol et
            if (substr($content, 0, 1) !== '{' || substr($content, -1) !== '}') {
                throw new Exception('Geçersiz JSON formatı');
            }

            $dietPlan = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("JSON Error: " . json_last_error_msg());
                error_log("Raw Response: " . $content);
                throw new Exception('JSON parse hatası: ' . json_last_error_msg());
            }

            // JSON yapısını kontrol et
            if (!isset($dietPlan['meals']) || !is_array($dietPlan['meals'])) {
                throw new Exception('Geçersiz diyet planı formatı: meals dizisi bulunamadı');
            }

            if (!isset($dietPlan['notes']) || !is_array($dietPlan['notes'])) {
                throw new Exception('Geçersiz diyet planı formatı: notes dizisi bulunamadı');
            }

            // Veritabanına kaydet
            $stmt = $conn->prepare("
                INSERT INTO diet_programs (
                    user_id, 
                    program_type, 
                    start_date, 
                    end_date, 
                    program_data,
                    created_at
                ) VALUES (
                    ?, 
                    ?, 
                    CURRENT_DATE, 
                    DATE_ADD(CURRENT_DATE, INTERVAL ? DAY),
                    ?,
                    NOW()
                )
            ");

            $stmt->execute([
                $_SESSION['user_id'],
                $program_type,
                $mealCount - 1, // -1 çünkü başlangıç günü dahil
                json_encode($dietPlan, JSON_UNESCAPED_UNICODE)
            ]);

            $success = 'Diyet programınız başarıyla oluşturuldu.';

            // Sayfayı yenile
            header("Location: " . $_SERVER['PHP_SELF'] . "?success=1");
            exit;

        } catch (Exception $e) {
            error_log("Diet Plan Generation Error: " . $e->getMessage());
            $error = 'Diyet programı oluşturulurken bir hata oluştu: ' . $e->getMessage();
        }
    }

} catch (PDOException $e) {
    error_log("Database Error: " . $e->getMessage());
    $error = 'Bir hata oluştu, lütfen tekrar deneyin.';
}

require_once '../../includes/header.php';
?>

    <div class="container py-4">
        <div class="row">
            <!-- Sol Kolon - Program Oluşturma Formu -->
            <div class="col-md-4 mb-4">
                <div class="card shadow-sm">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-magic text-primary"></i> Diyet Programı Oluştur
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if($error): ?>
                            <div class="alert alert-danger alert-dismissible fade show">
                                <i class="fas fa-exclamation-circle me-2"></i><?= $error ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <?php if($success || isset($_GET['success'])): ?>
                            <div class="alert alert-success alert-dismissible fade show">
                                <i class="fas fa-check-circle me-2"></i>Diyet programınız başarıyla oluşturuldu.
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <form method="POST" action="" id="dietForm" class="needs-validation" novalidate>
                            <!-- Program Tipi -->
                            <div class="mb-3">
                                <label class="form-label">Program Tipi</label>
                                <select name="program_type" class="form-select" required>
                                    <option value="daily">Günlük Program</option>
                                    <option value="weekly">Haftalık Program</option>
                                    <option value="monthly">Aylık Program</option>
                                </select>
                            </div>

                            <!-- Tercihler -->
                            <div class="mb-3">
                                <label class="form-label">Tercihler</label>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="preferences[]" value="easy_cooking">
                                    <label class="form-check-label">Kolay Hazırlanan</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="preferences[]" value="budget_friendly">
                                    <label class="form-check-label">Ekonomik</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="preferences[]" value="high_protein">
                                    <label class="form-check-label">Protein Ağırlıklı</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="preferences[]" value="low_carb">
                                    <label class="form-check-label">Düşük Karbonhidrat</label>
                                </div>
                            </div>

                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary" id="submitBtn">
                                    <i class="fas fa-magic me-2"></i>Program Oluştur
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Profil Özeti -->
                <div class="card shadow-sm mt-4">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-user text-primary"></i> Profil Bilgileri
                        </h5>
                    </div>
                    <div class="card-body">
                        <ul class="list-unstyled mb-0">
                            <li class="mb-2">
                                <small class="text-secondary">Günlük Kalori:</small>
                                <span class="float-end"><?= number_format($userProfile['daily_calorie_limit']) ?> kcal</span>
                            </li>
                            <li class="mb-2">
                                <small class="text-secondary">Diyet Tipi:</small>
                                <span class="float-end">
                                <?php
                                $diet_types = [
                                    'normal' => 'Normal',
                                    'vegetarian' => 'Vejetaryen',
                                    'vegan' => 'Vegan',
                                    'gluten_free' => 'Glutensiz'
                                ];
                                echo $diet_types[$userProfile['diet_type']] ?? 'Normal';
                                ?>
                            </span>
                            </li>
                            <?php if(!empty($healthConditions)): ?>
                                <li>
                                    <small class="text-secondary">Sağlık Durumları:</small>
                                    <div class="mt-1">
                                        <?php foreach($healthConditions as $condition): ?>
                                            <span class="badge bg-info"><?= htmlspecialchars($condition) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Sağ Kolon - Mevcut Programlar -->
            <div class="col-md-8">
                <div class="card shadow-sm">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-calendar-alt text-primary"></i> Mevcut Programlar
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if(empty($activePrograms)): ?>
                            <div class="text-center py-4">
                                <i class="fas fa-calendar-times fa-3x text-secondary mb-3"></i>
                                <h5>Aktif Program Bulunamadı</h5>
                                <p class="text-muted">Yeni bir diyet programı oluşturmak için sol taraftaki formu kullanabilirsiniz.</p>
                            </div>
                        <?php else: ?>
                            <div class="accordion" id="programAccordion">
                                <?php foreach($activePrograms as $index => $program): ?>
                                    <div class="accordion-item">
                                        <h2 class="accordion-header">
                                            <button class="accordion-button <?= $index > 0 ? 'collapsed' : '' ?>"
                                                    type="button"
                                                    data-bs-toggle="collapse"
                                                    data-bs-target="#program<?= $program['id'] ?>">
                                                <?php
                                                $programTypes = [
                                                    'daily' => 'Günlük Program',
                                                    'weekly' => 'Haftalık Program',
                                                    'monthly' => 'Aylık Program'
                                                ];
                                                ?>
                                                <div class="d-flex align-items-center w-100">
                                                    <div>
                                                        <strong><?= $programTypes[$program['program_type']] ?></strong>
                                                        <br>
                                                        <small class="text-muted">
                                                            Oluşturulma: <?= date('d.m.Y H:i', strtotime($program['created_at'])) ?>
                                                        </small>
                                                    </div>
                                                    <div class="ms-auto">
                                                        <?php
                                                        $daysLeft = ceil((strtotime($program['end_date']) - time()) / (60 * 60 * 24));
                                                        ?>
                                                        <span class="badge bg-<?= $daysLeft < 3 ? 'danger' : 'success' ?>">
                                                        <?= $daysLeft ?> gün kaldı
                                                    </span>
                                                    </div>
                                                </div>
                                            </button>
                                        </h2>
                                        <div id="program<?= $program['id'] ?>"
                                             class="accordion-collapse collapse <?= $index === 0 ? 'show' : '' ?>"
                                             data-bs-parent="#programAccordion">
                                            <div class="accordion-body">
                                                <div class="row mb-3">
                                                    <div class="col-md-6">
                                                        <small class="text-muted">Başlangıç Tarihi</small>
                                                        <div><?= date('d.m.Y', strtotime($program['start_date'])) ?></div>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <small class="text-muted">Bitiş Tarihi</small>
                                                        <div><?= date('d.m.Y', strtotime($program['end_date'])) ?></div>
                                                    </div>
                                                </div>

                                                <div class="d-flex gap-2">
                                                    <?php if($program['program_type'] == 'daily'): ?>
                                                        <a href="daily_plan.php" class="btn btn-primary btn-sm">
                                                            <i class="fas fa-calendar-day me-1"></i>Programı Görüntüle
                                                        </a>
                                                    <?php elseif($program['program_type'] == 'weekly'): ?>
                                                        <a href="weekly_plan.php" class="btn btn-primary btn-sm">
                                                            <i class="fas fa-calendar-week me-1"></i>Programı Görüntüle
                                                        </a>
                                                    <?php else: ?>
                                                        <a href="monthly_plan.php" class="btn btn-primary btn-sm">
                                                            <i class="fas fa-calendar-alt me-1"></i>Programı Görüntüle
                                                        </a>
                                                    <?php endif; ?>

                                                    <button type="button"
                                                            class="btn btn-outline-danger btn-sm"
                                                            onclick="deleteProgramConfirm(<?= $program['id'] ?>)">
                                                        <i class="fas fa-trash me-1"></i>Programı Sil
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Program Silme Modal -->
    <div class="modal fade" id="deleteProgramModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Programı Sil</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Bu diyet programını silmek istediğinizden emin misiniz?</p>
                </div>
                <div class="modal-footer">
                    <form method="POST" action="delete_program.php">
                        <input type="hidden" name="program_id" id="deleteProgramId">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-danger">Programı Sil</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Loading Modal -->
    <div class="modal fade" id="loadingModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-body text-center py-4">
                    <div class="spinner-border text-primary mb-3" role="status">
                        <span class="visually-hidden">Yükleniyor...</span>
                    </div>
                    <h5 class="mb-2">Diyet Programınız Oluşturuluyor</h5>
                    <p class="text-muted mb-0">Lütfen bekleyin, bu işlem birkaç dakika sürebilir...</p>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.getElementById('dietForm').addEventListener('submit', function() {
            // Modal'ı göster
            var loadingModal = new bootstrap.Modal(document.getElementById('loadingModal'));
            loadingModal.show();

            // Submit butonunu devre dışı bırak
            document.getElementById('submitBtn').disabled = true;
        });

        function deleteProgramConfirm(programId) {
            document.getElementById('deleteProgramId').value = programId;
            var modal = new bootstrap.Modal(document.getElementById('deleteProgramModal'));
            modal.show();
        }
    </script>

<?php require_once '../../includes/footer.php'; ?>