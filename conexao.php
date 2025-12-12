<?php
// Arquivo: conexao.php (na pasta raiz GESTAO_OBRAS)

$host = 'localhost';
$db   = 'sistema_gestao';
$user = 'root';
$pass = ''; // Geralmente vazio no XAMPP, se tiver senha, coloque aqui
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    throw new \PDOException($e->getMessage(), (int)$e->getCode());
}
?>