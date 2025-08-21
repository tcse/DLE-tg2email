<?php
// file.php — безопасная отдача файлов из Telegram
header('Content-Type: application/octet-stream');
header('Cache-Control: no-cache');

$dbFile = __DIR__ . '/media_db.json';
$fileId = $_GET['id'] ?? '';

if (empty($fileId)) {
    http_response_code(400);
    exit('No file ID provided');
}

if (!file_exists($dbFile)) {
    http_response_code(500);
    exit('Media database not found');
}

$db = json_decode(file_get_contents($dbFile), true);

if (!isset($db[$fileId])) {
    http_response_code(404);
    exit('File not found in database');
}

$record = $db[$fileId];
$filePath = $record['file_path'] ?? '';
$filename = $record['original_filename'] ?? 'file';

if (empty($filePath)) {
    http_response_code(500);
    exit('Invalid file path');
}

// Подключаем конфиг для получения токена
include_once($_SERVER['DOCUMENT_ROOT'] . '/engine/data/tg2email.php');
$botToken = $tg2emailConfig['tg2email_TOKEN'] ?? '';
if (!$botToken) {
    http_response_code(500);
    exit('Bot token not available');
}

// Формируем URL
$url = "https://api.telegram.org/file/bot{$botToken}/{$filePath}";

// Логируем (опционально)
file_put_contents(__DIR__ . '/file.log', date('Y-m-d H:i:s') . " - File access: $fileId -> $filename\n", FILE_APPEND);

// Перенаправляем на файл
header('Location: ' . $url);
exit;
