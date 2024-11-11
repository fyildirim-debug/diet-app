<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Oturum açmanız gerekiyor']);
    exit;
}

try {
    $meal_type = clean($_POST['meal_type']);
    $food_name = clean($_POST['food_name']);
    $serving_amount = clean($_POST['serving_amount']);
    $serving_type = clean($_POST['serving_type']);
    $date = clean($_POST['date']);
    $time = clean($_POST['time']);

    if(empty($food_name) || empty($serving_amount) || empty($serving_type)) {
        echo json_encode(['success' => false, 'message' => 'Lütfen tüm alanları doldurun']);
        exit;
    }

    // OpenAI'dan besin değerlerini al
    $prompt = "Sen bir diyetisyensin. $food_name için 100 gramlık porsiyondaki besin değerlerini hesapla ve sadece aşağıdaki JSON formatında döndür:
    {
        \"calories\": [kalori değeri],
        \"protein\": [protein gram],
        \"carbs\": [karbonhidrat gram],
        \"fat\": [yağ gram],
        \"fiber\": [lif gram]
    }
    Lütfen sadece JSON döndür, başka açıklama ekleme.";

    $response = askOpenAI($prompt);
    $content = $response['choices'][0]['message']['content'];

    // JSON formatını temizle
    $content = trim($content);
    if (strpos($content, '```json') !== false) {
        $content = str_replace('```json', '', $content);
        $content = str_replace('```', '', $content);
    }
    $content = trim($content);

    $nutritionData = json_decode($content, true);

    if($nutritionData && isset($nutritionData['calories'])) {
        $db = new Database();
        $conn = $db->getConnection();

        // Besin veritabanına ekle veya güncelle
        $stmt = $conn->prepare("
            INSERT INTO foods (name, calories, protein, carbs, fat, fiber, serving_type)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
            calories = VALUES(calories),
            protein = VALUES(protein),
            carbs = VALUES(carbs),
            fat = VALUES(fat),
            fiber = VALUES(fiber)
        ");
        $stmt->execute([
            $food_name,
            $nutritionData['calories'],
            $nutritionData['protein'],
            $nutritionData['carbs'],
            $nutritionData['fat'],
            $nutritionData['fiber'] ?? 0,
            $serving_type
        ]);

        $food_id = $conn->lastInsertId() ?: $conn->query("SELECT id FROM foods WHERE name = '$food_name'")->fetch(PDO::FETCH_COLUMN);

        // Öğün kaydını ekle
        $stmt = $conn->prepare("
            INSERT INTO meal_logs (user_id, food_id, meal_type, serving_amount, date, time)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $_SESSION['user_id'],
            $food_id,
            $meal_type,
            $serving_amount,
            $date,
            $time
        ]);

        // Besin değerlerini porsiyon miktarına göre ayarla
        foreach ($nutritionData as $key => $value) {
            $nutritionData[$key] = round($value * ($serving_amount / 100), 1);
        }

        echo json_encode([
            'success' => true,
            'message' => 'Öğün başarıyla eklendi',
            'nutrition' => $nutritionData,
            'debug' => [
                'original_response' => $content,
                'parsed_data' => $nutritionData
            ]
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Besin değerleri alınamadı',
            'debug' => [
                'original_response' => $content,
                'json_error' => json_last_error_msg()
            ]
        ]);
    }

} catch (Exception $e) {
    error_log("Meal Add Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Bir hata oluştu: ' . $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}