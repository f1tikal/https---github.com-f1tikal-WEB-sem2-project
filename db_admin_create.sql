CREATE TABLE IF NOT EXISTS vinokurov_admin_users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    login VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL
);

-- Вставка администратора (логин: admin, пароль: admin123)
INSERT INTO admin_users (login, password_hash)
VALUES ('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi');
-- Хеш выше соответствует паролю "admin123" (стандартный для тестов)
