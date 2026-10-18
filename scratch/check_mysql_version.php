<?php
$pdo = new PDO('mysql:host=127.0.0.1;port=3306', 'root', '1234');
echo "MySQL Version: " . $pdo->query('SELECT VERSION()')->fetchColumn() . "\n";
