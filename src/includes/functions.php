<?php
// Genel yardımcı fonksiyonlar
function clean($string) {
    return htmlspecialchars(trim($string), ENT_QUOTES, 'UTF-8');
}

function redirect($url) {
    header("Location: " . SITE_URL . $url);
    exit;
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        redirect('/modules/auth/login.php');
    }
}

// OpenAI API fonksiyonu
function askOpenAI($prompt) {
    try {
        $ch = curl_init(OPENAI_API_ENDPOINT);

        $headers = [
            'Authorization: Bearer ' . OPENAI_API_KEY,
            'Content-Type: application/json'
        ];

        $data = [
            'model' => OPENAI_MODEL,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'Sen bir beslenme uzmanısın. Yanıtlarını her zaman JSON formatında ve UTF-8 karakter kodlamasında ver.'
                ],
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
            'temperature' => 0.7,
            'max_tokens' => 12000,
            'response_format' => ['type' => 'json_object']
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 240 // Timeout süresini 60 saniyeye çıkardık
        ]);

        $response = curl_exec($ch);

        if(curl_errno($ch)) {
            throw new Exception('Curl error: ' . curl_error($ch));
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if($httpCode !== 200) {
            throw new Exception('API error: HTTP code ' . $httpCode);
        }

        curl_close($ch);

        $responseData = json_decode($response, true);

        if (!$responseData || !isset($responseData['choices'][0]['message']['content'])) {
            throw new Exception('Invalid API response format');
        }

        return $responseData;

    } catch (Exception $e) {
        error_log("OpenAI API Error: " . $e->getMessage());
        error_log("API Response: " . ($response ?? 'No response'));
        return null;
    }
}

// Kalori hesaplama fonksiyonu
function calculateCalories($age, $gender, $weight, $height, $activity_level) {
    $prompt = "Lütfen şu bilgilere göre günlük kalori ihtiyacını hesapla: 
               Yaş: $age, 
               Cinsiyet: $gender, 
               Kilo: $weight kg, 
               Boy: $height cm, 
               Aktivite seviyesi: $activity_level. 
               Sadece sayısal değeri ver.";

    $response = askOpenAI($prompt);
    return intval($response['choices'][0]['message']['content']);
}

// includes/functions.php içine eklenecek:

function checkProfileSetup() {
    if(isLoggedIn()) {
        try {
            $db = new Database();
            $conn = $db->getConnection();

            $stmt = $conn->prepare("
                SELECT id FROM user_profiles 
                WHERE user_id = ? AND 
                current_weight IS NOT NULL AND 
                target_weight IS NOT NULL AND 
                height IS NOT NULL AND 
                age IS NOT NULL AND 
                gender IS NOT NULL AND 
                activity_level IS NOT NULL
            ");
            $stmt->execute([$_SESSION['user_id']]);

            if(!$stmt->fetch()) {
                if(!in_array(basename($_SERVER['PHP_SELF']), ['setup.php', 'logout.php'])) {
                    redirect('/modules/profile/setup.php');
                }
            }
        } catch (PDOException $e) {
            error_log("Profile Check Error: " . $e->getMessage());
        }
    }
}
// functions.php içine eklenecek:
function getTurkishMonth($date) {
    $months = [
        '01' => 'Ocak',
        '02' => 'Şubat',
        '03' => 'Mart',
        '04' => 'Nisan',
        '05' => 'Mayıs',
        '06' => 'Haziran',
        '07' => 'Temmuz',
        '08' => 'Ağustos',
        '09' => 'Eylül',
        '10' => 'Ekim',
        '11' => 'Kasım',
        '12' => 'Aralık'
    ];
    return $months[date('m', strtotime($date))];
}
function getDietPlanForDate($planData, $date, $startDate) {
    if (!$planData || !isset($planData['meals'])) return null;

    $dayDiff = (new DateTime($date))->diff(new DateTime($startDate))->days;
    $totalDays = count($planData['meals']);
    $dayIndex = $dayDiff % $totalDays;

    return $planData['meals'][$dayIndex] ?? null;
}

function calculateDailyNutrients($meals) {
    $total = [
        'calories' => 0,
        'protein' => 0,
        'carbs' => 0,
        'fat' => 0
    ];

    foreach ($meals as $meal) {
        $total['calories'] += $meal['calories'] ?? 0;
        $total['protein'] += $meal['protein'] ?? 0;
        $total['carbs'] += $meal['carbs'] ?? 0;
        $total['fat'] += $meal['fat'] ?? 0;
    }

    return $total;
}

function getMealTypeLabel($type) {
    $labels = [
        'breakfast' => 'Kahvaltı',
        'morning_snack' => 'Kuşluk',
        'lunch' => 'Öğle',
        'afternoon_snack' => 'İkindi',
        'dinner' => 'Akşam',
        'evening_snack' => 'Gece'
    ];
    return $labels[$type] ?? $type;
}
function getPlannedMealsForDate($plannedMenu) {
    if (!$plannedMenu) return [];

    $meals = [];
    $mealTypes = [
        'breakfast' => 'Kahvaltı',
        'morning_snack' => 'Kuşluk',
        'lunch' => 'Öğle',
        'afternoon_snack' => 'İkindi',
        'dinner' => 'Akşam',
        'evening_snack' => 'Gece'
    ];

    foreach ($mealTypes as $type => $label) {
        if (isset($plannedMenu[$type])) {
            $meals[$type] = [
                'label' => $label,
                'meal' => $plannedMenu[$type]['meal'],
                'calories' => $plannedMenu[$type]['calories']
            ];
        }
    }

    return $meals;
}