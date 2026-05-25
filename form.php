<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

require_once 'db.php';
require_once 'validation.php';

// ---------- Вспомогательные функции ----------
function redirect($url) {
    header('Location: ' . $url);
    exit;
}

function generateLogin() {
    return 'user_' . time() . '_' . bin2hex(random_bytes(4));
}

function generatePassword($length = 10) {
    return bin2hex(random_bytes($length));
}

// ---------- Обработка выхода ----------
if (isset($_GET['logout'])) {
    session_destroy();
    redirect('form.php');
}

// ---------- Обработка входа (POST) ----------
$auth_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_action'])) {
    $login = $_POST['login'] ?? '';
    $password = $_POST['password'] ?? '';
    
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT id, password_hash FROM vinokurov_applications WHERE login = ?");
        $stmt->execute([$login]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id'] = $user['id'];
            redirect('form.php?auth_success=1');
        } else {
            $_SESSION['auth_error'] = 'Неверный логин или пароль.';
            redirect('form.php');
        }
    } catch (PDOException $e) {
        $_SESSION['auth_error'] = 'Ошибка БД: ' . $e->getMessage();
        redirect('form.php');
    }
}

// ---------- Обработка отправки/редактирования анкеты ----------
$is_authenticated = isset($_SESSION['user_id']);
$current_user_id = $is_authenticated ? $_SESSION['user_id'] : null;

$user_data = null;
if ($is_authenticated) {
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT * FROM vinokurov_applications WHERE id = ?");
        $stmt->execute([$current_user_id]);
        $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_application'])) {
    $input = [
        'full_name'   => trim($_POST['full_name'] ?? ''),
        'phone'       => trim($_POST['phone'] ?? ''),
        'email'       => trim($_POST['email'] ?? ''),
        'birth_date'  => $_POST['birth_date'] ?? '',
        'gender'      => $_POST['gender'] ?? '',
        'languages'   => $_POST['languages'] ?? [],
        'bio'         => trim($_POST['bio'] ?? ''),
        'agreement'   => isset($_POST['agreement']) ? 1 : 0,
    ];
    
    $errors = [];
    $valid = validateApplicationData($input, $errors);
    
    if (!$valid) {
        setcookie('form_errors', json_encode($errors), 0, '/');
        setcookie('form_input', json_encode($input), 0, '/');
        redirect('form.php');
    }
    
    try {
        $pdo = getDB();
        $pdo->beginTransaction();
        
        if ($is_authenticated) {
            // Обновление
            $sql = "UPDATE vinokurov_applications 
                    SET full_name = ?, phone = ?, email = ?, birth_date = ?, gender = ?, bio = ?, agreement = ?
                    WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $input['full_name'], $input['phone'], $input['email'],
                $input['birth_date'], $input['gender'], $input['bio'],
                $input['agreement'], $current_user_id
            ]);
            $application_id = $current_user_id;
            
            $pdo->prepare("DELETE FROM vinokurov_application_languages WHERE application_id = ?")->execute([$application_id]);
            $stmt_lang = $pdo->prepare("INSERT INTO vinokurov_application_languages (application_id, language_id) VALUES (?, ?)");
            foreach ($input['languages'] as $lang_id) {
                $stmt_lang->execute([$application_id, $lang_id]);
            }
            
            $pdo->commit();
            setcookie('form_defaults', json_encode($input), time() + 365 * 86400, '/');
            setcookie('form_errors', '', 1, '/');
            setcookie('form_input', '', 1, '/');
            redirect('form.php?update_success=1');
        } else {
            // Новая запись
            $login = generateLogin();
            $plain_password = generatePassword();
            $password_hash = password_hash($plain_password, PASSWORD_DEFAULT);
            
            $sql = "INSERT INTO vinokurov_applications 
                    (full_name, phone, email, birth_date, gender, bio, agreement, login, password_hash)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $input['full_name'], $input['phone'], $input['email'],
                $input['birth_date'], $input['gender'], $input['bio'],
                $input['agreement'], $login, $password_hash
            ]);
            $application_id = $pdo->lastInsertId();
            
            $stmt_lang = $pdo->prepare("INSERT INTO vinokurov_application_languages (application_id, language_id) VALUES (?, ?)");
            foreach ($input['languages'] as $lang_id) {
                $stmt_lang->execute([$application_id, $lang_id]);
            }
            
            $pdo->commit();
            setcookie('form_defaults', json_encode($input), time() + 365 * 86400, '/');
            setcookie('form_errors', '', 1, '/');
            setcookie('form_input', '', 1, '/');
            
            $_SESSION['generated_credentials'] = ['login' => $login, 'password' => $plain_password];
            redirect('form.php?registered=1');
        }
    } catch (PDOException $e) {
        $pdo->rollBack();
        setcookie('form_errors', json_encode(['db' => 'Ошибка БД: ' . $e->getMessage()]), 0, '/');
        setcookie('form_input', json_encode($input), 0, '/');
        redirect('form.php');
    }
}

// ---------- Чтение Cookies и подготовка данных для формы ----------
$defaults = [];
$errors = [];
$input = [];

if (isset($_COOKIE['form_defaults'])) {
    $defaults = json_decode($_COOKIE['form_defaults'], true);
}
if (isset($_COOKIE['form_errors'])) {
    $errors = json_decode($_COOKIE['form_errors'], true);
    setcookie('form_errors', '', 1, '/');
}
if (isset($_COOKIE['form_input'])) {
    $input = json_decode($_COOKIE['form_input'], true);
    setcookie('form_input', '', 1, '/');
}

if ($is_authenticated && $user_data) {
    $defaults = [
        'full_name'   => $user_data['full_name'],
        'phone'       => $user_data['phone'],
        'email'       => $user_data['email'],
        'birth_date'  => $user_data['birth_date'],
        'gender'      => $user_data['gender'],
        'bio'         => $user_data['bio'],
        'agreement'   => $user_data['agreement'],
    ];
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT language_id FROM vinokurov_application_languages WHERE application_id = ?");
        $stmt->execute([$current_user_id]);
        $defaults['languages'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {}
}

function get_field_value($field, $input, $defaults) {
    if (isset($input[$field]) && $input[$field] !== '') {
        return htmlspecialchars($input[$field]);
    }
    if (isset($defaults[$field]) && $defaults[$field] !== '') {
        return htmlspecialchars($defaults[$field]);
    }
    return '';
}

function is_language_selected($lang_id, $input, $defaults) {
    $selected = [];
    if (isset($input['languages']) && is_array($input['languages'])) {
        $selected = $input['languages'];
    } elseif (isset($defaults['languages']) && is_array($defaults['languages'])) {
        $selected = $defaults['languages'];
    }
    return in_array($lang_id, $selected);
}

$success_msg = '';
if (isset($_GET['registered'])) {
    $creds = $_SESSION['generated_credentials'] ?? null;
    if ($creds) {
        $success_msg = '<div class="alert alert-success">✅ Анкета сохранена!<br>
        <strong>Ваш логин:</strong> ' . htmlspecialchars($creds['login']) . '<br>
        <strong>Ваш пароль:</strong> ' . htmlspecialchars($creds['password']) . '<br>
        Сохраните эти данные для последующего редактирования.</div>';
        unset($_SESSION['generated_credentials']);
    } else {
        $success_msg = '<div class="alert alert-success">✅ Анкета успешно сохранена!</div>';
    }
} elseif (isset($_GET['update_success'])) {
    $success_msg = '<div class="alert alert-success">✅ Данные успешно обновлены!</div>';
} elseif (isset($_GET['auth_success'])) {
    $success_msg = '<div class="alert alert-success">✅ Вы успешно вошли в систему. Теперь вы можете редактировать свои данные.</div>';
}
$auth_error = $_SESSION['auth_error'] ?? '';
unset($_SESSION['auth_error']);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Анкета разработчика – Drupal Coder Style</title>
    <link href="https://fonts.googleapis.com/css?family=Montserrat&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css?family=Ubuntu&display=swap" rel="stylesheet">
    <link href="style.css" rel="stylesheet">
    <style>
        /* Аналогично предыдущему – стили для формы, ошибок, сообщений */
        .anketa-container {
            max-width: 900px;
            margin: 80px auto;
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            padding: 40px;
        }
        .anketa-title {
            font-size: 36px;
            font-weight: 700;
            color: #050c33;
            margin-bottom: 10px;
        }
        .anketa-subtitle {
            color: #666;
            margin-bottom: 30px;
            border-bottom: 1px solid #eee;
            padding-bottom: 15px;
        }
        .form-group {
            margin-bottom: 22px;
        }
        .form-group label {
            font-weight: 600;
            margin-bottom: 8px;
            display: block;
        }
        .form-group input:not([type="radio"]),
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 15px;
            transition: 0.3s;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: #f14d34;
            outline: none;
            box-shadow: 0 0 0 3px rgba(241,77,52,0.1);
        }
        .radio-group {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            margin-top: 5px;
        }
        .radio-group label {
            font-weight: normal;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        select[multiple] {
            min-height: 130px;
        }
        .checkbox-group {
            margin: 20px 0;
            background: #f8f9ff;
            padding: 12px 15px;
            border-radius: 10px;
        }
        .btn-submit {
            background: #f14d34;
            color: white;
            border: none;
            padding: 14px 30px;
            font-size: 18px;
            border-radius: 8px;
            cursor: pointer;
            width: 100%;
            font-weight: 600;
            transition: background 0.3s;
        }
        .btn-submit:hover {
            background: #d9432d;
        }
        .input-error {
            border: 2px solid #e74c3c !important;
            background-color: #ffe6e6;
        }
        .error-message {
            color: #e74c3c;
            font-size: 13px;
            margin-top: 5px;
        }
        .alert {
            padding: 12px 20px;
            border-radius: 8px;
            margin-bottom: 25px;
        }
        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left: 5px solid #28a745;
        }
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left: 5px solid #dc3545;
        }
        .login-form {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
            border: 1px solid #ddd;
        }
        .logout-link {
            text-align: right;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
<section class="header-section" style="min-height: auto; padding-bottom: 0;">
    <div class="container">
        <header class="site-header">
            <a href="#" class="logo"><img src="drupal-coder.svg" alt="Drupal Coder Logo"></a>
            <nav class="main-nav"><ul><li><a href="#">Поддержка сайтов</a></li><li><a href="#">Тарифы</a></li><li><a href="#">Наши работы</a></li><li><a href="#">Отзывы</a></li><li><a href="#">Контакты</a></li></ul></nav>
            <div class="header-contact"><a href="tel:88002222673" class="phone-number">8 800 222-26-73</a><div class="lang-switcher"><span>RU</span><img src="down-arrow.png" alt="arrow"></div></div>
        </header>
    </div>
</section>

<div class="container">
    <div class="anketa-container">
        <div class="logout-link">
            <?php if ($is_authenticated): ?><a href="?logout=1">Выйти</a><?php endif; ?>
        </div>

        <?php if (!$is_authenticated): ?>
            <div class="login-form">
                <h3>Вход для редактирования</h3>
                <form method="POST">
                    <input type="hidden" name="login_action" value="1">
                    <div class="form-group"><label>Логин: <input type="text" name="login" required style="width:auto;"></label></div>
                    <div class="form-group"><label>Пароль: <input type="password" name="password" required style="width:auto;"></label></div>
                    <button type="submit" class="btn btn-primary" style="width:auto;">Войти</button>
                    <?php if ($auth_error): ?><div class="error-message"><?= htmlspecialchars($auth_error) ?></div><?php endif; ?>
                </form>
            </div>
        <?php endif; ?>

        <?= $success_msg ?>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">❌ При заполнении формы допущены ошибки. Исправьте их и отправьте снова.</div>
        <?php endif; ?>

        <form method="POST" id="anketa-form">
            <input type="hidden" name="save_application" value="1">

<!-- ФИО -->
<div class="form-group">
    <label for="full_name" class="required">ФИО</label>
    <input type="text" name="full_name" id="full_name" 
           value="<?= get_field_value('full_name', $input, $defaults) ?>" 
           class="<?= isset($errors['full_name']) ? 'input-error' : '' ?>">
    <?php if (isset($errors['full_name'])): ?>
        <div class="error-message"><?= htmlspecialchars($errors['full_name']) ?></div>
    <?php endif; ?>
</div>

<!-- Телефон -->
<div class="form-group">
    <label for="phone" class="required">Телефон</label>
    <input type="tel" name="phone" id="phone" 
           value="<?= get_field_value('phone', $input, $defaults) ?>" 
           class="<?= isset($errors['phone']) ? 'input-error' : '' ?>">
    <?php if (isset($errors['phone'])): ?>
        <div class="error-message"><?= htmlspecialchars($errors['phone']) ?></div>
    <?php endif; ?>
</div>

<!-- Email -->
<div class="form-group">
    <label for="email" class="required">E-mail</label>
    <input type="email" name="email" id="email" 
           value="<?= get_field_value('email', $input, $defaults) ?>" 
           class="<?= isset($errors['email']) ? 'input-error' : '' ?>">
    <?php if (isset($errors['email'])): ?>
        <div class="error-message"><?= htmlspecialchars($errors['email']) ?></div>
    <?php endif; ?>
</div>

<!-- Дата рождения -->
<div class="form-group">
    <label for="birth_date" class="required">Дата рождения</label>
    <input type="date" name="birth_date" id="birth_date" 
           value="<?= get_field_value('birth_date', $input, $defaults) ?>" 
           class="<?= isset($errors['birth_date']) ? 'input-error' : '' ?>">
    <?php if (isset($errors['birth_date'])): ?>
        <div class="error-message"><?= htmlspecialchars($errors['birth_date']) ?></div>
    <?php endif; ?>
</div>

<!-- Пол (радиокнопки) -->
<div class="form-group">
    <label class="required">Пол</label>
    <div class="radio-group">
        <label>
            <input type="radio" name="gender" value="male" <?= get_field_value('gender', $input, $defaults) == 'male' ? 'checked' : '' ?>> Мужской
        </label>
        <label>
            <input type="radio" name="gender" value="female" <?= get_field_value('gender', $input, $defaults) == 'female' ? 'checked' : '' ?>> Женский
        </label>
        <label>
            <input type="radio" name="gender" value="other" <?= get_field_value('gender', $input, $defaults) == 'other' ? 'checked' : '' ?>> Другой
        </label>
    </div>
    <?php if (isset($errors['gender'])): ?>
        <div class="error-message"><?= htmlspecialchars($errors['gender']) ?></div>
    <?php endif; ?>
</div>

<!-- Любимые языки (множественный выбор) -->
<div class="form-group">
    <label for="languages" class="required">Любимые языки программирования</label>
    <select name="languages[]" id="languages" multiple size="6" 
            class="<?= isset($errors['languages']) ? 'input-error' : '' ?>">
        <?php
        $lang_list = [
            1 => 'Pascal', 2 => 'C', 3 => 'C++', 4 => 'JavaScript',
            5 => 'PHP', 6 => 'Python', 7 => 'Java', 8 => 'Haskell',
            9 => 'Clojure', 10 => 'Prolog', 11 => 'Scala', 12 => 'Go'
        ];
        foreach ($lang_list as $id => $name):
            $selected = is_language_selected($id, $input, $defaults);
        ?>
            <option value="<?= $id ?>" <?= $selected ? 'selected' : '' ?>><?= $name ?></option>
        <?php endforeach; ?>
    </select>
    <small>Удерживайте Ctrl (Cmd на Mac) для выбора нескольких</small>
    <?php if (isset($errors['languages'])): ?>
        <div class="error-message"><?= htmlspecialchars($errors['languages']) ?></div>
    <?php endif; ?>
</div>

<!-- Биография -->
<div class="form-group">
    <label for="bio" class="required">Биография</label>
    <textarea name="bio" id="bio" rows="5" 
              class="<?= isset($errors['bio']) ? 'input-error' : '' ?>"><?= get_field_value('bio', $input, $defaults) ?></textarea>
    <?php if (isset($errors['bio'])): ?>
        <div class="error-message"><?= htmlspecialchars($errors['bio']) ?></div>
    <?php endif; ?>
</div>

<!-- Чекбокс согласия -->
<div class="checkbox-group">
    <label>
        <input type="checkbox" name="agreement" value="1" <?= get_field_value('agreement', $input, $defaults) == 1 ? 'checked' : '' ?>>
        Я ознакомлен(а) с контрактом и согласен(на)
    </label>
    <?php if (isset($errors['agreement'])): ?>
        <div class="error-message"><?= htmlspecialchars($errors['agreement']) ?></div>
    <?php endif; ?>
</div>

<!-- Кнопка отправки -->
<button type="submit" class="btn-submit"><?= $is_authenticated ? 'Обновить данные' : 'Сохранить' ?></button>
        </form>
    </div>
</div>

<footer class="footer-section" style="margin-top: 60px;">
    <div class="container">
        <div class="footer-bottom">
            <hr class="footer-divider">
            <div class="footer-copyright">
                <p>Проект ООО «Инитлаб», Краснодар, Россия.</p>
                <p>Drupal является зарегистрированной торговой маркой Dries Buytaert.</p>
            </div>
        </div>
    </div>
</footer>

<script>
    
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('anketa-form');
        if (!form) return;
        const isAuthenticated = <?= json_encode($is_authenticated) ?>;
        const userId = <?= json_encode($current_user_id) ?>;
        form.addEventListener('submit', async function(e) {
            if (window.fetch) e.preventDefault();
            else return;
            const formData = new FormData(form);
            let data = {};
            for (let [key, value] of formData.entries()) {
                if (key.endsWith('[]')) {
                    key = key.slice(0, -2);
                    if (!data[key]) data[key] = [];
                    data[key].push(value);
                } else {
                    data[key] = value;
                }
            }
            let method = 'POST';
            let url = '/task3/api.php/application';
            if (isAuthenticated && userId) {
                method = 'PUT';
                url = `/task3/api.php/application/${userId}`;
            }
            try {
                const response = await fetch(url, {
                    method: method,
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });
                const result = await response.json();
                if (response.ok) {
                    if (method === 'POST' && result.login) {
                        alert(`Анкета сохранена!\nЛогин: ${result.login}\nПароль: ${result.password}\nСсылка: ${result.profile_url}`);
                    } else {
                        alert('Данные обновлены!');
                    }
                    window.location.reload();
                } else {
                    let errorMsg = 'Ошибка:\n';
                    if (result.errors) {
                        for (let field in result.errors) {
                            errorMsg += `${field}: ${result.errors[field]}\n`;
                        }
                    } else {
                        errorMsg += result.error || 'Неизвестная ошибка';
                    }
                    alert(errorMsg);
                }
            } catch (err) {
                alert('Ошибка сети: ' + err.message);
            }
        });
    });
</script>
</body>
</html>