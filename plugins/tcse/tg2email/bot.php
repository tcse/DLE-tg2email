<?php
/*
=====================================================
 Telegram to Email Bot - TCSE-cms.com & DeepSeek Chat
-----------------------------------------------------
 Version: 0.8 (Stable)
 Release: 18.08.2025
-----------------------------------------------------
 https://tcse-cms.com/ 
 https://deepseek.com/
 https://qwen.ai/
-----------------------------------------------------
 Copyright (c) 2025 Vitaly V Chuyakov
 MIT License
=====================================================
 File: /plugins/tcse/tg2email/bot.php
-----------------------------------------------------
 Purpose: Пересылка сообщений Telegram на email с 
          поддержкой буферизации и обработки медиа
-----------------------------------------------------
 Features:
 ✔ Буферизация сообщений (1+ сообщений в одном письме)
 ✔ Поддержка чатов/каналов/личных сообщений
 ✔ Ссылки на оригиналы сообщений
 ✔ Обработка медиавложений (фото, документы и др.)
 ✔ Команда /send для мгновенной отправки
 ✔ Логирование всех операций
-----------------------------------------------------
 Usage:
 1. Просто перешлите сообщение боту
 2. Используйте /send для немедленной отправки
 3. Настройте $bufferTime (0 для мгновенной отправки)
=====================================================
 Planned for v0.9:
 ✎ Режим составления писем (/newmail)
 ✎ Указание получателя (/to)
 ✎ Кастомные темы писем (/subject)
 ✎ Прямой ввод текста (/bodymail)
=====================================================
*/

// Подключаем конфиг плагина из DLE 
include_once($_SERVER['DOCUMENT_ROOT'] . '/engine/data/tg2email.php');

// Теперь используем значения из конфига
$botToken = $tg2emailConfig['tg2email_TOKEN'];
$adminEmail = $tg2emailConfig['tg2email_adminEmail'];
$adminChatId = $tg2emailConfig['tg2email_CHATID'];
$bufferTime = (int)$tg2emailConfig['tg2email_bufferTime'];

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
            $blockedMessage .= "Для связи с администратором напишите: @your_support_username"; // ← замените на нужный ник

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
if (isset($update['message'])) {
    $message = $update['message'];
    $chatId = $message['chat']['id'];
    $text = isset($message['text']) ? trim($message['text']) : '';

    // Обработка команды /send
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

    // Обработка пересланных сообщений (включая каналы)
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
                sendTelegramMessage($chatId, "💬 Сообщение добавлено в буфер (отправка через ".ceil($timeLeft/60)." мин)");
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
            $source = "сообщение из ".($message['forward_from_chat']['type'] === 'channel' ? 'канала' : 'чата');
        }
        
        sendTelegramMessage($chatId, "📥 Первое $source в буфере. Жду ".$bufferTime." мин...");
    } else {
        $helpText = "📨 Перешлите сообщение (из личного чата, группы или канала), и я отправлю его на email\n";
        $helpText .= "Используйте /send для немедленной отправки";
        sendTelegramMessage($chatId, $helpText);
    }
}

// В функции prepareMessage() заменяем текущую реализацию на эту:
function prepareMessage($message) {
    $data = [
        'text' => '',
        'date' => date('d.m.Y H:i', $message['date']),
        'has_media' => false,
        'media_type' => null,
        'message_type' => 'private' // по умолчанию
    ];

    // Получаем текст сообщения
    if (isset($message['text'])) {
        $data['text'] = $message['text'];
    } elseif (isset($message['caption'])) {
        $data['text'] = $message['caption'];
    }

    // Определяем тип медиафайла
    $mediaTypes = ['photo', 'video', 'document', 'audio', 'voice', 'sticker'];
    foreach ($mediaTypes as $type) {
        if (isset($message[$type])) {
            $data['has_media'] = true;
            $data['media_type'] = $type;
            break;
        }
    }

    // Определяем отправителя и тип сообщения
    if (isset($message['forward_from'])) {
        // Личное сообщение от пользователя
        $from = $message['forward_from'];
        $data['sender'] = $from['first_name'].(isset($from['last_name']) ? ' '.$from['last_name'] : '');
        $data['message_type'] = 'private';
        
        // Для документов добавляем имя файла
        if ($data['media_type'] == 'document' && isset($message['document']['file_name'])) {
            $data['text'] = "Файл: ".$message['document']['file_name']."\n\n".$data['text'];
        }
    } 
    elseif (isset($message['forward_sender_name'])) {
        // Анонимная пересылка
        $data['sender'] = $message['forward_sender_name'];
        $data['message_type'] = 'anonymous';
    }
    elseif (isset($message['forward_from_chat'])) {
        // Из чата/канала
        $chat = $message['forward_from_chat'];
        $data['sender'] = $chat['title'] ?? 'Без названия';
        if (isset($chat['username'])) {
            $data['sender'] .= " (@".$chat['username'].")";
        }
        $data['message_type'] = $chat['type']; // channel, group, supergroup
        
        // Ссылка на сообщение (только для публичных чатов/каналов)
        if (isset($message['forward_from_message_id'])) {
            $username = isset($chat['username']) ? $chat['username'] : 'c/'.$chat['id'];
            $data['link'] = "https://t.me/$username/".$message['forward_from_message_id'];
        }
    }

    return $data;
}

// В функции sendBufferedMessages() обновляем формирование тела письма:
function sendBufferedMessages($messages, $chatId) {
    global $adminEmail;

    $emailSubject = "Сообщения из Telegram (".count($messages).")";
    $emailBody = "Собрано сообщений: ".count($messages)."\n\n";

    foreach ($messages as $index => $msg) {
        $emailBody .= "=== Сообщение ".($index+1)." ===\n";
        $emailBody .= "От: ".$msg['sender']."\n";
        $emailBody .= "Дата: ".$msg['date']."\n";
        $emailBody .= "Тип: ".getMessageTypeDescription($msg['message_type'])."\n";
        
        if ($msg['has_media']) {
            $mediaTypes = [
                'photo' => 'Фото',
                'video' => 'Видео',
                'document' => 'Документ',
                'audio' => 'Аудио',
                'voice' => 'Голосовое сообщение',
                'sticker' => 'Стикер'
            ];
            $emailBody .= "Медиа: ".$mediaTypes[$msg['media_type']]."\n";
        }
        
        if (!empty($msg['link'])) {
            $emailBody .= "Ссылка: ".$msg['link']."\n";
        } elseif ($msg['message_type'] != 'private') {
            $emailBody .= "Ссылка: недоступна (приватный чат)\n";
        }
        
        if (!empty($msg['text'])) {
            $emailBody .= "\n".trim($msg['text'])."\n";
        }
        
        $emailBody .= "\n";
    }

    // Генерация From: на основе домена сайта
    $siteHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $siteHost = strtolower(trim($siteHost));
    $siteHost = preg_replace('/^www\./i', '', $siteHost); // убираем www

    // Защита от некорректных доменов
    if (!filter_var("user@{$siteHost}", FILTER_VALIDATE_EMAIL)) {
        $siteHost = 'localhost'; // fallback
    }

    $fromEmail = "telegram-bot@{$siteHost}";
    $headers = "From: {$fromEmail}\r\n";
    $headers .= "Content-Type: text/plain; charset=utf-8\r\n";

    if (mail($adminEmail, $emailSubject, $emailBody, $headers)) {
        sendTelegramMessage($chatId, "📬 Отправлено ".count($messages)." сообщений!");
    } else {
        sendTelegramMessage($chatId, "❌ Ошибка отправки email");
    }
}

// Вспомогательная функция для описания типа сообщения
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

// Отправка сообщения в Telegram
function sendTelegramMessage($chatId, $text) {
    global $botToken;

    $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
    $data = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];

    $options = [
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($data),
        ],
    ];

    $context = stream_context_create($options);
    @file_get_contents($url, false, $context);
}

// Очистка старых буферов
$files = glob("buffer_*.txt");
foreach ($files as $file) {
    if (time() - filemtime($file) > 3600) { // 1 час
        unlink($file);
    }
}

// Вспомогательная функция для логирования (опционально)
function logMessage($msg) {
    file_put_contents('auth_log.txt', date('[Y-m-d H:i:s] ') . $msg . "\n", FILE_APPEND);
}
