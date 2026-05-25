<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once 'db.php';
require_once 'validation.php';

session_start();

// Функция для отправки JSON-ответа
function sendResponse($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Получаем метод и путь
$method = $_SERVER['REQUEST_METHOD'];
$path = $_SERVER['PATH_INFO'] ?? '';
$path = trim($path, '/');
$parts = explode('/', $path);
$resource = $parts[0] ?? '';
$id = isset($parts[1]) ? (int)$parts[1] : 0;

// Проверяем, что ресурс - application
if ($resource !== 'application') {
    sendResponse(['error' => 'Not found'], 404);
}

// Вспомогательная функция для получения текущего авторизованного пользователя (из сессии)
function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

// --- GET /api.php/application/{id} (опционально, можно не реализовывать, но по REST полезно) ---
if ($method === 'GET' && $id) {
    $userId = getCurrentUserId();
    if (!$userId || $userId != $id) {
        sendResponse(['error' => 'Unauthorized'], 401);
    }
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT id, full_name, phone, email, birth_date, gender, bio, agreement, login FROM vinokurov_applications WHERE id = ?");
        $stmt->execute([$id]);
        $app = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$app) sendResponse(['error' => 'Not found'], 404);
        // Получаем языки
        $langStmt = $pdo->prepare("SELECT language_id FROM vinokurov_application_languages WHERE application_id = ?");
        $langStmt->execute([$id]);
        $app['languages'] = $langStmt->fetchAll(PDO::FETCH_COLUMN);
        sendResponse($app);
    } catch (PDOException $e) {
        sendResponse(['error' => 'Database error: ' . $e->getMessage()], 500);
    }
}

// --- POST /api.php/application (создание новой анкеты) ---
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    // Валидация
    $errors = [];
    $valid = validateApplicationData($input, $errors);
    if (!$valid) {
        sendResponse(['errors' => $errors], 422);
    }
    
    // Генерация логина и пароля
    $login = 'user_' . time() . '_' . bin2hex(random_bytes(4));
    $plainPassword = bin2hex(random_bytes(5));
    $passwordHash = password_hash($plainPassword, PASSWORD_DEFAULT);
    
    try {
        $pdo = getDB();
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("INSERT INTO vinokurov_applications 
            (full_name, phone, email, birth_date, gender, bio, agreement, login, password_hash)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $input['full_name'], $input['phone'], $input['email'],
            $input['birth_date'], $input['gender'], $input['bio'],
            $input['agreement'], $login, $passwordHash
        ]);
        $appId = $pdo->lastInsertId();
        
        // Вставка языков
        $langStmt = $pdo->prepare("INSERT INTO vinokurov_application_languages (application_id, language_id) VALUES (?, ?)");
        foreach ($input['languages'] as $langId) {
            $langStmt->execute([$appId, $langId]);
        }
        
        $pdo->commit();
        
        $profileUrl = "http://u82089.kubsu-dev.ru/vinokurov_pm21_project/form.php?id=$appId";
        sendResponse([
            'id' => $appId,
            'login' => $login,
            'password' => $plainPassword,
            'profile_url' => $profileUrl
        ], 201);
        
    } catch (PDOException $e) {
        if (isset($pdo)) $pdo->rollBack();
        sendResponse(['error' => 'Database error: ' . $e->getMessage()], 500);
    }
}

if ($method === 'PUT' && $id) {
    $userId = getCurrentUserId();
    if (!$userId || $userId != $id) {
        sendResponse(['error' => 'Unauthorized'], 401);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        sendResponse(['error' => 'Invalid JSON'], 400);
    }
    
    // Валидация
    $errors = [];
    $valid = validateApplicationData($input, $errors);
    if (!$valid) {
        sendResponse(['errors' => $errors], 422);
    }
    
    try {
        $pdo = getDB();
        $pdo->beginTransaction();
        
        // Обновляем основную таблицу
        $stmt = $pdo->prepare("UPDATE vinokurov_applications SET 
            full_name = ?, phone = ?, email = ?, birth_date = ?, gender = ?, bio = ?, agreement = ?
            WHERE id = ?");
        $stmt->execute([
            $input['full_name'], $input['phone'], $input['email'],
            $input['birth_date'], $input['gender'], $input['bio'],
            $input['agreement'], $id
        ]);
        
        $pdo->prepare("DELETE FROM vinokurov_application_languages WHERE application_id = ?")->execute([$id]);
        $langStmt = $pdo->prepare("INSERT INTO vinokurov_application_languages (application_id, language_id) VALUES (?, ?)");
        foreach ($input['languages'] as $langId) {
            $langStmt->execute([$id, $langId]);
        }
        
        $pdo->commit();
        sendResponse(['message' => 'Updated successfully']);
        
    } catch (PDOException $e) {
        if (isset($pdo)) $pdo->rollBack();
        sendResponse(['error' => 'Database error: ' . $e->getMessage()], 500);
    }
}

// Если метод не поддерживается
sendResponse(['error' => 'Method not allowed'], 405);
?>