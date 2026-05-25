<?php
$db_host = 'localhost';
$db_name = 'u82089';
$db_user = 'u82089';
$db_pass = '4044723';

function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        global $db_host, $db_name, $db_user, $db_pass;
        try {
            $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            die('Ошибка подключения к БД: ' . $e->getMessage());
        }
    }
    return $pdo;
}
?>