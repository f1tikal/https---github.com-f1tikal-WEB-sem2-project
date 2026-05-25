<?php
// api.php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *'); // для удобства тестирования
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once 'db.php';        // общее подключение к БД
require_once 'validation.php'; // общие функции валидации (вынесем)

$pdo = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$path = $_SERVER['PATH_INFO'] ?? '';
$path = trim($path, '/');
$parts = explode('/', $path);
$resource = $parts[0] ?? '';
$id = isset($parts[1]) ? (int)$parts[1] : 0;

// --- Функции ---
function sendResponse($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function getUserByToken() {
    // Сессионная авторизация из предыдущего задания (если залогинен через сессию)
    session_start();
    if (isset($_SESSION['user_id'])) {
        return $_SESSION['user_id'];
    }
    // Или через Bearer token (JWT – опционально)
    $headers = getallheaders();
    if (isset($headers['Authorization'])) {
        $token = str_replace('Bearer ', '', $headers['Authorization']);
        // Здесь можно проверить token в БД (если реализовано)
        // ... упростим: вернём null
    }
    return null;
}

// --- Обработка запросов ---
if ($resource === 'application') {
    // GET /api/application/{id} – получить данные анкеты (для авторизованного)
    if ($method === 'GET' && $id) {
        $user_id = getUserByToken();
        if (!$user_id || $user_id != $id) {
            sendResponse(['error' => 'Unauthorized'], 401);
        }
        $stmt = $pdo->prepare("SELECT * FROM my_applications WHERE id = ?");
        $stmt->execute([$id]);
        $app = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$app) sendResponse(['error' => 'Not found'], 404);
        // убрать пароль и логин из ответа? Лучше оставить логин, но скрыть пароль
        unset($app['password_hash']);
        // получить языки
        $langs = $pdo->prepare("SELECT language_id FROM my_application_languages WHERE application_id = ?");
        $langs->execute([$id]);
        $app['languages'] = $langs->fetchAll(PDO::FETCH_COLUMN);
        sendResponse($app);
    }
    // POST /api/application – создание новой анкеты
    elseif ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) $input = $_POST; // поддержка form-data
        // Валидация
        $errors = [];
        $valid = validateApplicationData($input, $errors);
        if (!$valid) {
            sendResponse(['errors' => $errors], 422);
        }
        // Генерация логина/пароля
        $login = 'user_' . time() . '_' . bin2hex(random_bytes(4));
        $plain_password = bin2hex(random_bytes(5));
        $password_hash = password_hash($plain_password, PASSWORD_DEFAULT);
        
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("INSERT INTO my_applications 
                (full_name, phone, email, birth_date, gender, bio, agreement, login, password_hash) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $input['full_name'], $input['phone'], $input['email'],
                $input['birth_date'], $input['gender'], $input['bio'],
                $input['agreement'], $login, $password_hash
            ]);
            $app_id = $pdo->lastInsertId();
            $lang_stmt = $pdo->prepare("INSERT INTO my_application_languages (application_id, language_id) VALUES (?, ?)");
            foreach ($input['languages'] as $lang_id) {
                $lang_stmt->execute([$app_id, $lang_id]);
            }
            $pdo->commit();
            sendResponse([
                'id' => $app_id,
                'login' => $login,
                'password' => $plain_password,
                'profile_url' => "http://u82089.kubsu-dev.ru/task3/form.php?id=$app_id"
            ], 201);
        } catch (Exception $e) {
            $pdo->rollBack();
            sendResponse(['error' => $e->getMessage()], 500);
        }
    }
    // PUT /api/application/{id} – обновление (требует авторизации)
    elseif ($method === 'PUT' && $id) {
        $user_id = getUserByToken();
        if (!$user_id || $user_id != $id) {
            sendResponse(['error' => 'Unauthorized'], 401);
        }
        $input = json_decode(file_get_contents('php://input'), true);
        $errors = [];
        if (!validateApplicationData($input, $errors)) {
            sendResponse(['errors' => $errors], 422);
        }
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("UPDATE my_applications SET 
                full_name=?, phone=?, email=?, birth_date=?, gender=?, bio=?, agreement=?
                WHERE id=?");
            $stmt->execute([
                $input['full_name'], $input['phone'], $input['email'],
                $input['birth_date'], $input['gender'], $input['bio'],
                $input['agreement'], $id
            ]);
            // обновить языки
            $pdo->prepare("DELETE FROM vinokurov_application_languages WHERE application_id = ?")->execute([$id]);
            $lang_stmt = $pdo->prepare("INSERT INTO vinokurov_application_languages (application_id, language_id) VALUES (?, ?)");
            foreach ($input['languages'] as $lang_id) {
                $lang_stmt->execute([$id, $lang_id]);
            }
            $pdo->commit();
            sendResponse(['message' => 'updated']);
        } catch (Exception $e) {
            $pdo->rollBack();
            sendResponse(['error' => $e->getMessage()], 500);
        }
    }
    else {
        sendResponse(['error' => 'Method not allowed'], 405);
    }
} else {
    sendResponse(['error' => 'Not found'], 404);
}