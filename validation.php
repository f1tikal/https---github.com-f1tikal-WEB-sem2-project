<?php
// validation.php
function validateApplicationData($input, &$errors) {
    // ФИО
    if (!preg_match('/^[a-zA-Zа-яА-ЯёЁ\s\-]+$/u', $input['full_name'] ?? '') || strlen($input['full_name'] ?? '') > 150) {
        $errors['full_name'] = 'ФИО должно содержать только буквы, пробелы и дефисы (не более 150 символов).';
    }
    // Телефон
    if (!preg_match('/^[\+\d\s\-\(\)]{5,20}$/', $input['phone'] ?? '')) {
        $errors['phone'] = 'Телефон должен содержать только цифры, +, -, пробелы и скобки (5-20 символов).';
    }
    // Email
    if (!filter_var($input['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Введите корректный email адрес.';
    }
    // Дата рождения
    $date = DateTime::createFromFormat('Y-m-d', $input['birth_date'] ?? '');
    if (!$date || $date->format('Y-m-d') !== ($input['birth_date'] ?? '')) {
        $errors['birth_date'] = 'Введите корректную дату рождения в формате ГГГГ-ММ-ДД.';
    }
    // Пол
    if (!in_array($input['gender'] ?? '', ['male', 'female', 'other'])) {
        $errors['gender'] = 'Выберите пол из предложенных вариантов.';
    }
    // Языки
    $allowed_lang_ids = range(1, 12);
    if (empty($input['languages'] ?? [])) {
        $errors['languages'] = 'Выберите хотя бы один язык программирования.';
    } else {
        foreach ($input['languages'] as $lang_id) {
            if (!in_array((int)$lang_id, $allowed_lang_ids)) {
                $errors['languages'] = 'Выбран недопустимый язык программирования.';
                break;
            }
        }
    }
    // Биография
    if (strlen($input['bio'] ?? '') < 10) {
        $errors['bio'] = 'Биография должна содержать не менее 10 символов.';
    }
    // Согласие
    if (empty($input['agreement'])) {
        $errors['agreement'] = 'Вы должны подтвердить согласие с контрактом.';
    }
    return empty($errors);
}
?>