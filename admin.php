<?php
// admin.php – панель администратора (задание 6)
require_once 'db.php';
require_once 'validation.php';

session_start();

// ----- HTTP Basic Auth -----
$auth_login = $_SERVER['PHP_AUTH_USER'] ?? '';
$auth_pass = $_SERVER['PHP_AUTH_PW'] ?? '';

if (empty($auth_login) || empty($auth_pass)) {
    header('WWW-Authenticate: Basic realm="Admin Area"');
    header('HTTP/1.0 401 Unauthorized');
    echo 'Доступ запрещён. Введите логин и пароль.';
    exit;
}

$pdo = getDB();
$stmt = $pdo->prepare("SELECT password_hash FROM vinokurov_admin_users WHERE login = ?");
$stmt->execute([$auth_login]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$admin || !password_verify($auth_pass, $admin['password_hash'])) {
    header('WWW-Authenticate: Basic realm="Admin Area"');
    header('HTTP/1.0 401 Unauthorized');
    echo 'Неверный логин или пароль.';
    exit;
}

// ----- Обработка действий -----
$message = '';
$action = $_GET['action'] ?? '';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Удаление
if ($action === 'delete' && $id) {
    try {
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM vinokurov_application_languages WHERE application_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM vinokurov_applications WHERE id = ?")->execute([$id]);
        $pdo->commit();
        $message = "Анкета #$id удалена.";
    } catch (Exception $e) {
        $pdo->rollBack();
        $message = "Ошибка удаления: " . $e->getMessage();
    }
}

// Редактирование (POST)
$edit_user = null;
if ($action === 'edit' && $id) {
    // Загружаем текущие данные
    $stmt = $pdo->prepare("SELECT * FROM vinokurov_applications WHERE id = ?");
    $stmt->execute([$id]);
    $edit_user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($edit_user) {
        $stmtLang = $pdo->prepare("SELECT language_id FROM vinokurov_application_languages WHERE application_id = ?");
        $stmtLang->execute([$id]);
        $edit_user['languages'] = $stmtLang->fetchAll(PDO::FETCH_COLUMN);
    }
}

// Обновление после отправки формы
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_application'])) {
    $input = [
        'full_name' => trim($_POST['full_name'] ?? ''),
        'phone' => trim($_POST['phone'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'birth_date' => $_POST['birth_date'] ?? '',
        'gender' => $_POST['gender'] ?? '',
        'languages' => $_POST['languages'] ?? [],
        'bio' => trim($_POST['bio'] ?? ''),
        'agreement' => isset($_POST['agreement']) ? 1 : 0,
    ];
    $errors = [];
    if (validateApplicationData($input, $errors)) {
        try {
            $pdo->beginTransaction();
            // Обновление основной таблицы
            $sql = "UPDATE vinokurov_applications SET 
                    full_name = ?, phone = ?, email = ?, birth_date = ?, gender = ?, bio = ?, agreement = ?
                    WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $input['full_name'], $input['phone'], $input['email'],
                $input['birth_date'], $input['gender'], $input['bio'],
                $input['agreement'], $id
            ]);
            // Обновление языков
            $pdo->prepare("DELETE FROM vinokurov_application_languages WHERE application_id = ?")->execute([$id]);
            $stmtLang = $pdo->prepare("INSERT INTO vinokurov_application_languages (application_id, language_id) VALUES (?, ?)");
            foreach ($input['languages'] as $lang_id) {
                $stmtLang->execute([$id, $lang_id]);
            }
            $pdo->commit();
            $message = "Анкета #$id обновлена.";
            // Перенаправление, чтобы избежать повторной отправки
            header("Location: admin.php?message=updated");
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "Ошибка обновления: " . $e->getMessage();
        }
    } else {
        $message = "Ошибки валидации: " . implode(', ', $errors);
        // В случае ошибки показываем форму заново с введёнными данными
        $edit_user = $input;
        $edit_user['id'] = $id;
        $edit_user['languages'] = $input['languages'];
    }
}

// Получение всех анкет (для списка)
$applications = $pdo->query("SELECT * FROM vinokurov_applications ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

// Статистика по языкам
$lang_stats = $pdo->query("
    SELECT pl.language_name, COUNT(lal.application_id) as count
    FROM vinokurov_programming_languages pl
    LEFT JOIN vinokurov_application_languages lal ON pl.language_id = lal.language_id
    GROUP BY pl.language_id
    ORDER BY count DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Сообщение из перенаправления
if (isset($_GET['message']) && $_GET['message'] === 'updated') {
    $message = "Анкета обновлена.";
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Панель администратора</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f2f2f2; margin: 0; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 10px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        h1, h2 { color: #333; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 30px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; vertical-align: top; }
        th { background: #3498db; color: white; }
        .btn { display: inline-block; padding: 5px 10px; background: #007bff; color: white; text-decoration: none; border-radius: 3px; margin-right: 5px; }
        .btn-danger { background: #dc3545; }
        .btn-warning { background: #ffc107; color: #333; }
        .form-group { margin-bottom: 15px; }
        label { font-weight: bold; display: block; margin-bottom: 5px; }
        input, select, textarea { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        select[multiple] { height: 120px; }
        .error { color: red; }
        .alert { padding: 10px; margin-bottom: 20px; border-radius: 4px; background: #d4edda; color: #155724; }
        .stats { background: #e9ecef; padding: 15px; border-radius: 8px; margin-bottom: 30px; }
    </style>
</head>
<body>
<div class="container">
    <h1> Панель администратора</h1>
    <?php if ($message): ?>
        <div class="alert"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <div class="stats">
        <h2>Статистика по языкам программирования</h2>
        <ul>
            <?php foreach ($lang_stats as $stat): ?>
                <li><strong><?= htmlspecialchars($stat['language_name']) ?>:</strong> <?= $stat['count'] ?> пользователей</li>
            <?php endforeach; ?>
        </ul>
    </div>

    <h2>Список анкет</h2>
    <table>
        <thead>
            <tr><th>ID</th><th>ФИО</th><th>Телефон</th><th>Email</th><th>Действия</th></tr>
        </thead>
        <tbody>
            <?php foreach ($applications as $app): ?>
            <tr>
                <td><?= $app['id'] ?></td>
                <td><?= htmlspecialchars($app['full_name']) ?></td>
                <td><?= htmlspecialchars($app['phone']) ?></td>
                <td><?= htmlspecialchars($app['email']) ?></td>
                <td>
                    <a href="?action=edit&id=<?= $app['id'] ?>" class="btn btn-warning"> Редактировать</a>
                    <a href="?action=delete&id=<?= $app['id'] ?>" class="btn btn-danger" onclick="return confirm('Удалить анкету?')"> Удалить</a>
                 </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($action === 'edit' && $edit_user): ?>
        <h2>Редактирование анкеты #<?= $edit_user['id'] ?></h2>
        <form method="POST">
            <input type="hidden" name="update_application" value="1">
            <div class="form-group">
                <label>ФИО</label>
                <input type="text" name="full_name" value="<?= htmlspecialchars($edit_user['full_name'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label>Телефон</label>
                <input type="tel" name="phone" value="<?= htmlspecialchars($edit_user['phone'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label>Email</label>
                <input type="email" name="email" value="<?= htmlspecialchars($edit_user['email'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label>Дата рождения</label>
                <input type="date" name="birth_date" value="<?= htmlspecialchars($edit_user['birth_date'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label>Пол</label>
                <select name="gender" required>
                    <option value="male" <?= ($edit_user['gender'] ?? '') == 'male' ? 'selected' : '' ?>>Мужской</option>
                    <option value="female" <?= ($edit_user['gender'] ?? '') == 'female' ? 'selected' : '' ?>>Женский</option>
                    <option value="other" <?= ($edit_user['gender'] ?? '') == 'other' ? 'selected' : '' ?>>Другой</option>
                </select>
            </div>
            <div class="form-group">
                <label>Любимые языки (множественный выбор)</label>
                <select name="languages[]" multiple required>
                    <?php
                    $langs = [1=>'Pascal',2=>'C',3=>'C++',4=>'JavaScript',5=>'PHP',6=>'Python',7=>'Java',8=>'Haskell',9=>'Clojure',10=>'Prolog',11=>'Scala',12=>'Go'];
                    $selected = $edit_user['languages'] ?? [];
                    foreach ($langs as $id => $name): ?>
                        <option value="<?= $id ?>" <?= in_array($id, $selected) ? 'selected' : '' ?>><?= $name ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Биография</label>
                <textarea name="bio" rows="5" required><?= htmlspecialchars($edit_user['bio'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
                <label><input type="checkbox" name="agreement" value="1" <?= isset($edit_user['agreement']) && $edit_user['agreement'] ? 'checked' : '' ?>> Согласие с контрактом</label>
            </div>
            <button type="submit" class="btn">Сохранить</button>
            <a href="admin.php" class="btn">Отмена</a>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
