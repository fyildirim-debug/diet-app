<?php
// modules/diet/get_nutrition.php

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Giriş kontrolü
requireLogin();

header('Content-Type: application/json');

try {
    // POST verilerini al
    $data = json_decode(file_get_contents('php://input'), true);
    $meal = $data['meal'] ?? '';

    if (empty($meal)) {
        throw new Exception('Yemek adı gerekli.');
    }

    // Veritabanı bağlantısı
    $db = new Database();
    $conn = $db->getConnection();

    // Önce veritabanında bu yemek var mı diye kontrol et
    $stmt = $conn->prepare("SELECT * FROM foods WHERE name = ?");
    $stmt->execute([$meal]);
    $existingFood = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existingFood) {
        // Veritabanında varsa direkt onu döndür
        echo json_encode([
            'success' => true,
            'nutrition' => [
                'calories' => $existingFood['calories'],
                'protein' => $existingFood['protein'],
                'carbs' => $existingFood['carbs'],
                'fat' => $existingFood['fat'],
                'fiber' => $existingFood['fiber']
            ]
        ]);
        exit;
    }

    // OpenAI'dan besin değerlerini al
    $prompt = "Lütfen şu yemeğin 1 porsiyonluk besin değerlerini JSON formatında ver (sadece JSON döndür): 
              Yemek: $meal
              Format:
              {
                'calories': '300',
                'protein': '15',
                'carbs': '40',
                'fat': '10',
                'fiber': '5'
              }
              Not: Sadece sayısal değerler olsun, birim yazma.";

    $response = askOpenAI($prompt);
    $nutritionData = json_decode($response['choices'][0]['message']['content'], true);

    if (!$nutritionData || !isset($nutritionData['calories'])) {
        throw new Exception('Besin değerleri alınamadı.');
    }

    // Yeni besin değerlerini veritabanına kaydet
    $stmt = $conn->prepare("
        INSERT INTO foods (name, calories, protein, carbs, fat, fiber, serving_type)
        VALUES (?, ?, ?, ?, ?, ?, 'portion')
        ON DUPLICATE KEY UPDATE
        calories = VALUES(calories),
        protein = VALUES(protein),
        carbs = VALUES(carbs),
        fat = VALUES(fat),
        fiber = VALUES(fiber)
    ");

    $stmt->execute([
        $meal,
        $nutritionData['calories'],
        $nutritionData['protein'],
        $nutritionData['carbs'],
        $nutritionData['fat'],
        $nutritionData['fiber']
    ]);

    // Başarılı yanıt
    echo json_encode([
        'success' => true,
        'nutrition' => $nutritionData
    ]);

} catch (Exception $e) {
    // Hata durumunda
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}