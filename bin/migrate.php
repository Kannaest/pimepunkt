<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$pdo = db();
$pdo->exec('CREATE TABLE IF NOT EXISTS migrations (name VARCHAR(190) PRIMARY KEY, ran_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)');

$ran = $pdo->query('SELECT name FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
$ran = array_flip($ran);
$files = glob(__DIR__ . '/../migrations/*.sql') ?: [];
sort($files);

foreach ($files as $file) {
    $name = basename($file);
    if (isset($ran[$name])) {
        echo "skip {$name}\n";
        continue;
    }
    $sql = file_get_contents($file);
    if ($sql === false) {
        throw new RuntimeException("Cannot read {$name}");
    }
    $pdo->exec($sql);
    $stmt = $pdo->prepare('INSERT INTO migrations (name) VALUES (?)');
    $stmt->execute([$name]);
    echo "ran {$name}\n";
}

echo "migrations ok\n";
