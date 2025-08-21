<?php
/*
=====================================================
 Telegram to Email Bot - TCSE-cms.com
-----------------------------------------------------
 Version: 0.8.5.2 (Stable)
 Release: 20.08.2025
-----------------------------------------------------
 https://tcse-cms.com/   
 https://deepseek.com/  
 https://chat.qwen.ai  
-----------------------------------------------------
 Copyright (c) 2025 Vitaly V Chuyakov
 MIT License
=====================================================
 File: /plugins/tcse/tg2email/bot.php
-----------------------------------------------------
 Purpose: Пересылка сообщений Telegram на email с 
          гибким управлением вложениями и безопасными ссылками
-----------------------------------------------------
 Features:
 ✔ Буферизация (1+ сообщений в одном письме)
 ✔ Поддержка чатов, каналов, ЛС
 ✔ Вложения фото < 5 МБ (опционально)
 ✔ Безопасные ссылки на медиа (file.php)
 ✔ Настраиваемый срок хранения ссылок (media_ttl)
 ✔ Управление вложениями через админку
 ✔ Команда /send для мгновенной отправки
 ✔ Полная идентификация: @username | ID
 ✔ Поддержка нескольких email
 ✔ HTML и текстовый формат письма
 ✔ Логирование и защита
=====================================================
*/

// Подключаем конфиг плагина из DLE 
include_once($_SERVER['DOCUMENT_ROOT'] . '/engine/data/tg2email.php');

// Теперь используем значения из конфига
$botToken = $tg2emailConfig['tg2email_TOKEN'];
$adminEmail = $tg2emailConfig['tg2email_adminEmail'];
$adminChatId = $tg2emailConfig['tg2email_CHATID'];
$bufferTime = (int)$tg2emailConfig['tg2email_bufferTime'];
$emailFormat = $tg2emailConfig['tg2email_formatEmail'] ?? '0'; // 0 = text, 1 = html
$mediaTtl = (int)$tg2emailConfig['tg2email_media_ttl'] ?? 365; // 0 = вечно
$embedPhotos = $tg2emailConfig['tg2email_embedPhotos'] ?? '1'; // 1 = включено

// Логирование
file_put_contents('bot_log.txt', date('[Y-m-d H:i:s]')." Input: ".file_get_contents('php://input')."\n", FILE_APPEND);
$update = json_decode(file_get_contents('php://input'), true);

// === 🔐 Проверка авторизации пользователя ===
if (isset($update['message'])) {
    $message = $update['message'];
    $chatId = $message['chat']['id'];
    $userId = $message['from']['id'] ?? null;

    if (!$userId) {
        exit;
    }

    // Если Chat ID = 0 — разрешено всем
    if ($adminChatId === '0') {
        // разрешено
    } else {
        $allowedIds = array_map('trim', explode(',', $adminChatId));
        $allowedIds = array_filter($allowedIds, 'is_numeric');

        if (!in_array($userId, $allowedIds)) {
            // ❌ Отправляем отказ
            $blockedMessage = "❌ Вам запрещена пересылка сообщений через этого бота.\n\n";
            $blockedMessage .= "Для связи с администратором напишите: @TCSEcmscom";

            sendTelegramMessage($chatId, $blockedMessage);
            logMessage("Доступ запрещён: пользователь $userId");
            exit;
        }
    }
} else {
    exit;
}
// === ✅ Конец проверки авторизации ===

// Обработка входящего сообщения
// === Обработка входящего сообщения ===
if (isset($update['message'])) {
    $message = $update['message'];
    $chatId = $message['chat']['id'];
    $text = isset($message['text']) ? trim($message['text']) : '';
    $userId = $message['from']['id'] ?? null;

    // === 🤖 Команды бота ===
    if ($text === '/start') {
        $welcome = "👋 Добро пожаловать в <b>tg2email</b>!\n\n";
        $welcome .= "📩 Этот бот пересылает сообщения из Telegram на email.\n\n";
        $welcome .= "<b>Доступные команды:</b>\n";
        $welcome .= "• <code>/send</code> — отправить накопленные сообщения\n";
        $welcome .= "• <code>/help</code> — подробная справка\n";
        $welcome .= "• <code>/id</code> — узнать ваш Telegram ID\n\n";
        $welcome .= "📌 Просто перешлите любое сообщение — и оно попадёт в буфер.";

        sendTelegramMessage($chatId, $welcome);
        exit;
    }

    if ($text === '/help') {
        $detailedHelp = "📘 <b>Справка по использованию tg2email</b>\n\n";
        $detailedHelp .= "1. <b>Пересылка сообщений</b>\n";
        $detailedHelp .= "   Перешлите любое сообщение (из ЛС, группы или канала) — оно добавится в буфер.\n\n";
        $detailedHelp .= "2. <b>Буферизация</b>\n";
        $detailedHelp .= "   Сообщения накапливаются <b>{$bufferTime} мин</b>. После этого — автоматически отправляются.\n\n";
        $detailedHelp .= "3. <b>Мгновенная отправка</b>\n";
        $detailedHelp .= "   Используйте <code>/send</code>, чтобы отправить всё сейчас.\n\n";
        $detailedHelp .= "4. <b>Проверка ID</b>\n";
        $detailedHelp .= "   Команда <code>/id</code> покажет ваш Telegram ID — полезно для настройки доступа.\n\n";
        $detailedHelp .= "📬 Все сообщения отправляются на email: <code>" . htmlspecialchars($adminEmail) . "</code>";

        sendTelegramMessage($chatId, $detailedHelp);
        exit;
    }

    if ($text === '/id') {
        sendTelegramMessage($chatId, "🆔 Ваш Telegram ID: <code>$userId</code>", 'HTML');
        exit;
    }

    if ($text === '/send') {
        $bufferFile = "buffer_$chatId.txt";
        if (file_exists($bufferFile)) {
            $buffer = json_decode(file_get_contents($bufferFile), true);
            sendBufferedMessages($buffer['messages'], $chatId);
            unlink($bufferFile);
            sendTelegramMessage($chatId, "✅ Накопленные сообщения отправлены!");
        } else {
            sendTelegramMessage($chatId, "ℹ️ В буфере нет сообщений.");
        }
        exit;
    }

    // === Обработка пересланных сообщений ===
    if (isset($message['forward_from']) || isset($message['forward_sender_name']) || isset($message['forward_from_chat'])) {
        $bufferFile = "buffer_$chatId.txt";
        $currentTime = time();

        // Если есть буфер и он еще актуален
        if (file_exists($bufferFile)) {
            $buffer = json_decode(file_get_contents($bufferFile), true);
            if ($currentTime - $buffer['timestamp'] < $bufferTime * 60) {
                $buffer['messages'][] = prepareMessage($message);
                file_put_contents($bufferFile, json_encode($buffer));
                $timeLeft = $bufferTime * 60 - ($currentTime - $buffer['timestamp']);
                sendTelegramMessage($chatId, "💬 Сообщение добавлено в буфер (отправка через " . ceil($timeLeft/60) . " мин)");
                exit;
            } else {
                // Время вышло - отправляем
                sendBufferedMessages($buffer['messages'], $chatId);
            }
        }

        // Создаем новый буфер
        $newBuffer = [
            'timestamp' => $currentTime,
            'messages' => [prepareMessage($message)]
        ];
        file_put_contents($bufferFile, json_encode($newBuffer));
        
        $source = "сообщение";
        if (isset($message['forward_from_chat'])) {
            $source = "сообщение из " . ($message['forward_from_chat']['type'] === 'channel' ? 'канала' : 'чата');
        }
        
        sendTelegramMessage($chatId, "📥 Первое $source в буфере. Жду " . $bufferTime . " мин...");
    } else {
        // Если пользователь прислал обычное сообщение (не команду и не пересылку)
        $helpText = "📨 Перешлите сообщение (из личного чата, группы или канала), и я отправлю его на email\n";
        $helpText .= "Используйте /send для немедленной отправки";
        sendTelegramMessage($chatId, $helpText);
    }
}

// Подготовка сообщения для отправки
function prepareMessage($message) {
    global $tg2emailConfig; // 🔥 Критически важно для доступа к настройкам

    $data = [
        'text' => '',
        'date' => date('d.m.Y H:i', $message['date']),
        'has_media' => false,
        'media_type' => null,
        'message_type' => 'private',
        'sender' => 'Пользователь',
        'link' => null,
        'user_id' => null,
        'file_direct_link' => null,
        'embed_file' => null, // для вложения
    ];

    // Получаем текст
    if (isset($message['text'])) {
        $data['text'] = $message['text'];
    } elseif (isset($message['caption'])) {
        $data['text'] = $message['caption'];
    }

    // Тип медиа
    $mediaTypes = ['photo', 'video', 'document', 'audio', 'voice', 'sticker'];
    foreach ($mediaTypes as $type) {
        if (isset($message[$type])) {
            $data['has_media'] = true;
            $data['media_type'] = $type;
            break;
        }
    }

    // === Определяем отправителя и тип ===
    if (isset($message['forward_from'])) {
        $from = $message['forward_from'];
        $firstName = $from['first_name'] ?? 'Пользователь';
        $lastName = isset($from['last_name']) ? ' '.$from['last_name'] : '';
        $username = isset($from['username']) ? "@{$from['username']}" : null;
        $userId = $from['id'];

        $sender = trim($firstName . $lastName);
        $details = [];
        if ($username) $details[] = $username;
        $details[] = "ID: {$userId}";
        $data['sender'] = $sender . ' (' . implode(' | ', $details) . ')';
        $data['message_type'] = 'private';
        $data['user_id'] = $userId;

    } elseif (isset($message['forward_sender_name'])) {
        $data['sender'] = $message['forward_sender_name'];
        $data['message_type'] = 'anonymous';

    } elseif (isset($message['forward_from_chat'])) {
        $chat = $message['forward_from_chat'];
        $data['sender'] = $chat['title'] ?? 'Без названия';
        if (isset($chat['username'])) {
            $data['sender'] .= " (@{$chat['username']})";
        }
        $data['message_type'] = $chat['type'];

        // Ссылка на сообщение
        if (isset($message['forward_from_message_id'])) {
            $username = $chat['username'] ?? null;
            if ($username) {
                $data['link'] = "https://t.me/$username/" . $message['forward_from_message_id'];
            }
        }
    }

    // Для документов — добавляем имя файла
    if ($data['has_media'] && $data['media_type'] == 'document' && isset($message['document']['file_name'])) {
        $data['text'] = "Файл: " . $message['document']['file_name'] . "\n\n" . $data['text'];
    }

    // === СОХРАНЯЕМ ФАЙЛ В БАЗУ И ГЕНЕРИРУЕМ БЕЗОПАСНУЮ ССЫЛКУ ===
    if ($data['has_media']) {
        $fileId = null;

        if ($data['media_type'] == 'photo') {
            $photos = $message['photo'];
            $fileId = $photos[count($photos)-1]['file_id']; // лучшее качество
        } elseif (isset($message[$data['media_type']]['file_id'])) {
            $fileId = $message[$data['media_type']]['file_id'];
        }

        if ($fileId) {
            $getFileUrl = "https://api.telegram.org/bot{$GLOBALS['botToken']}/getFile?file_id={$fileId}";
            $fileInfo = @json_decode(file_get_contents($getFileUrl), true);

            if (isset($fileInfo['result']['file_path'])) {
                $filePath = $fileInfo['result']['file_path'];
                $filename = basename($filePath);

                // Проверка размера для вложения
                $url = "https://api.telegram.org/file/bot{$GLOBALS['botToken']}/{$filePath}";
                $headers = @get_headers($url, 1);
                $fileSize = (int)($headers['Content-Length'] ?? 0);

                // Проверяем, включено ли вложение
                $embedEnabled = isset($tg2emailConfig['tg2email_embedPhotos']) && $tg2emailConfig['tg2email_embedPhotos'] == '1';

                if ($embedEnabled && $fileSize > 0 && $fileSize <= 5 * 1024 * 1024) {
                    $content = file_get_contents($url);
                    if ($content !== false) {
                        $data['embed_file'] = [
                            'content' => base64_encode($content),
                            'filename' => $filename,
                            'size' => $fileSize,
                            'type' => mime_content_type('//tmp/' . $filename) ?: 'application/octet-stream'
                        ];
                    }
                }

                // Сохраняем в media_db.json
                $dbFile = __DIR__ . '/media_db.json';
                $db = file_exists($dbFile) ? json_decode(file_get_contents($dbFile), true) : [];
                $db[$fileId] = [
                    'file_path' => $filePath,
                    'timestamp' => time(),
                    'original_filename' => $filename
                ];
                file_put_contents($dbFile, json_encode($db, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

                // Генерируем безопасную ссылку
                $siteUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];
                $data['file_direct_link'] = $siteUrl . "/plugins/tcse/tg2email/file.php?id=" . urlencode($fileId);
            }
        }
    }

    return $data;
}

// Отправка накопленных сообщений
function sendBufferedMessages($messages, $chatId) {
    global $adminEmail, $emailFormat, $mediaTtl;

    $emailSubject = "Сообщения из Telegram (".count($messages).")";
    $boundary = '==MULTIPART_BOUNDARY_' . md5(uniqid(mt_rand(), true));

    $headers = "From: ".getFromEmail()."\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n";

    // === ОСНОВНОЕ ТЕЛО ПИСЬМА (первая часть) ===
    $body = "--$boundary\r\n";

    if ($emailFormat == '1') {
        $body .= "Content-Type: text/html; charset=utf-8\r\n\r\n";
        $body .= "<div style='font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto; color: #333; line-height: 1.6;'>";
        $body .= "<h2 style='color: #2c3e50; border-bottom: 2px solid #3498db; padding-bottom: 5px;'>Собрано сообщений: ".count($messages)."</h2>";

        foreach ($messages as $index => $msg) {
            $typeLabel = getMessageTypeLabel($msg['message_type']);
            $mediaLabel = $msg['has_media'] ? getMediaLabel($msg['media_type']) : '';

            $body .= "
            <div style='background: #f9f9f9; border-left: 4px solid #3498db; padding: 15px; margin: 15px 0; border-radius: 0 8px 8px 0;'>
                <strong style='color: #2c3e50;'>Сообщение ".($index+1)."</strong>
                <p style='margin: 8px 0;'><strong>От:</strong> ".htmlspecialchars($msg['sender'])."</p>
                <p style='margin: 8px 0;'><strong>Дата:</strong> ".$msg['date']."</p>
                <p style='margin: 8px 0;'><strong>Тип:</strong> $typeLabel</p>
                $mediaLabel
            ";

            if (!empty($msg['link'])) {
                $body .= "<p style='margin: 8px 0;'><strong>Ссылка:</strong> <a href='".$msg['link']."' target='_blank'>Открыть в Telegram</a></p>";
            }

            if (!empty($msg['file_direct_link'])) {
                $body .= "<p style='margin: 8px 0;'><strong>Файл:</strong> <a href='".$msg['file_direct_link']."' target='_blank'>Скачать</a></p>";
            }

            if (!empty($msg['text'])) {
                $body .= "<pre style='background:#fff; padding:10px; border:1px solid #ddd; border-radius:4px; overflow:auto; white-space: pre-wrap; font-size: 14px;'>".
                    htmlspecialchars(trim($msg['text'])).
                    "</pre>";
            }

            $body .= "</div>";
        }

        $body .= "
            <p style='color: #7f8c8d; font-size: 12px; margin-top: 30px; border-top: 1px solid #eee; padding-top: 10px;'>
                Это письмо сгенерировано автоматически через <strong>tg2email</strong> — плагин для DLE.
            </p>
        </div>";
    } else {
        $body .= "Content-Type: text/plain; charset=utf-8\r\n\r\n";
        foreach ($messages as $msg) {
            $body .= "=== Сообщение ===\n";
            $body .= "От: ".$msg['sender']."\n";
            $body .= "Дата: ".$msg['date']."\n";
            if ($msg['has_media']) $body .= "Медиа: ".ucfirst($msg['media_type'])."\n";
            if (!empty($msg['link'])) $body .= "Ссылка: ".$msg['link']."\n";
            if (!empty($msg['file_direct_link'])) $body .= "Файл: ".$msg['file_direct_link']."\n";
            if (!empty($msg['text'])) $body .= "\n".$msg['text']."\n";
            $body .= "\n";
        }
    }

    // === ПЕРЕХОД К ВЛОЖЕНИЯМ ===
    $body .= "\r\n"; // Завершаем текстовую часть
    $body .= "--$boundary\r\n"; // Начинаем вложение

    foreach ($messages as $msg) {
        if (isset($msg['embed_file'])) {
            $body .= "Content-Type: {$msg['embed_file']['type']}; name=\"{$msg['embed_file']['filename']}\"\r\n";
            $body .= "Content-Transfer-Encoding: base64\r\n";
            $body .= "Content-Disposition: attachment; filename=\"{$msg['embed_file']['filename']}\"\r\n\r\n";
            $body .= chunk_split($msg['embed_file']['content'], 76, "\r\n");
            $body .= "--$boundary\r\n";
        }
    }

    // === ЗАВЕРШЕНИЕ ПИСЬМА ===
    $body = rtrim($body, "--$boundary\r\n");
    $body .= "--\r\n";

    // === ОТПРАВКА НА НЕСКОЛЬКО EMAIL ===
    $recipients = array_map('trim', explode(',', $adminEmail));
    $successCount = 0;
    $failedRecipients = [];

    foreach ($recipients as $recipient) {
        if (empty($recipient)) continue;
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) continue;

        $uniqueHeaders = $headers . "Message-ID: <" . md5(uniqid(mt_rand(), true)) . "@{$_SERVER['HTTP_HOST']}>\r\n";

        if (mail($recipient, $emailSubject, $body, $uniqueHeaders)) {
            $successCount++;
        } else {
            $failedRecipients[] = $recipient;
        }
    }

    if ($successCount > 0) {
        $msg = "📬 Отправлено ".count($messages)." сообщений!";
        if (!empty($failedRecipients)) {
            $msg .= " (не доставлено: " . implode(', ', $failedRecipients) . ")";
        }
        sendTelegramMessage($chatId, $msg);
    } else {
        sendTelegramMessage($chatId, "❌ Не удалось отправить письмо ни одному получателю");
    }
}

// Описание типа (для текстового режима)
function getMessageTypeDescription($type) {
    $types = [
        'private' => 'Личное сообщение',
        'anonymous' => 'Анонимная пересылка',
        'channel' => 'Канал',
        'group' => 'Группа',
        'supergroup' => 'Супергруппа'
    ];
    return $types[$type] ?? $type;
}

// Цветная метка типа (HTML)
function getMessageTypeLabel($type) {
    $labels = [
        'private'   => '<span style="color: #27ae60; font-weight: bold;">Личное сообщение</span>',
        'anonymous' => '<span style="color: #e67e22; font-weight: bold;">Анонимная пересылка</span>',
        'channel'   => '<span style="color: #3498db; font-weight: bold;">Канал</span>',
        'group'     => '<span style="color: #8e44ad; font-weight: bold;">Группа</span>',
        'supergroup'=> '<span style="color: #8e44ad; font-weight: bold;">Супергруппа</span>'
    ];
    return $labels[$type] ?? $type;
}

// Метка медиа (HTML)
function getMediaLabel($type) {
    $icons = ['photo' => '📷', 'video' => '🎥', 'document' => '📄', 'audio' => '🎵', 'voice' => '🎙', 'sticker' => '🖼'];
    $colors = ['photo' => '#e74c3c', 'video' => '#8e44ad', 'document' => '#3498db', 'audio' => '#16a085', 'voice' => '#f39c12', 'sticker' => '#95a5a6'];
    $icon = $icons[$type] ?? '📎';
    $color = $colors[$type] ?? '#333';
    return "<p style='margin: 8px 0;'><strong>Медиа:</strong> <span style='color: $color; font-weight: bold;'>$icon ".ucfirst($type)."</span></p>";
}

// Отправка сообщения в Telegram
function sendTelegramMessage($chatId, $text) {
    global $botToken;
    $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
    $data = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML'];
    $options = ['http' => ['method' => 'POST', 'header' => "Content-Type: application/x-www-form-urlencoded\r\n", 'content' => http_build_query($data)]];
    $context = stream_context_create($options);
    @file_get_contents($url, false, $context);
}

// Генерация From: на основе домена
function getFromEmail() {
    $siteHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $siteHost = strtolower(trim($siteHost));
    $siteHost = preg_replace('/^www\./i', '', $siteHost);
    if (!filter_var("user@{$siteHost}", FILTER_VALIDATE_EMAIL)) {
        $siteHost = 'localhost';
    }
    return "telegram-bot@{$siteHost}";
}

// Вспомогательная функция для логирования
function logMessage($msg) {
    file_put_contents('auth_log.txt', date('[Y-m-d H:i:s] ') . $msg . "\n", FILE_APPEND);
}

// Очистка старых буферов
$files = glob("buffer_*.txt");
foreach ($files as $file) {
    if (time() - filemtime($file) > 3600) {
        unlink($file);
    }
}

// Очистка старых записей в media_db.json (старше mediaTtl дней)
$dbFile = __DIR__ . '/media_db.json';
if (file_exists($dbFile) && $mediaTtl > 0) {
    $db = json_decode(file_get_contents($dbFile), true);
    $cleaned = false;
    $ttlSeconds = $mediaTtl * 86400;
    foreach ($db as $id => $data) {
        if (time() - ($data['timestamp'] ?? 0) > $ttlSeconds) {
            unset($db[$id]);
            $cleaned = true;
        }
    }
    if ($cleaned) {
        file_put_contents($dbFile, json_encode($db, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
}
