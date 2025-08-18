<?php
if (!defined('DATALIFEENGINE') || !defined('LOGGED_IN')) {
    die('Hacking attempt!');
}

define('MODULE_DIR', ENGINE_DIR . '/modules/tg2email');

// Обработка формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $botToken = trim($_POST['botToken']);
    $adminChatId = trim($_POST['adminChatId']);
    $adminEmail = trim($_POST['adminEmail']);
    $bufferTime = intval($_POST['bufferTime']);

    // Проверка безопасности email
    if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        die("Некорректный email");
    }

    // Содержимое нового файла конфига
    $configContent = "<?php\n";
    $configContent .= "/**\n";
    $configContent .= " * Конфиг модуля tg2email\n";
    $configContent .= " * @var array\n";
    $configContent .= " */\n";
    $configContent .= "\$tg2emailConfig = [\n";
    $configContent .= "    'tg2email_TOKEN' => '" . addslashes($botToken) . "',\n";
    $configContent .= "    'tg2email_CHATID' => '" . addslashes($adminChatId) . "',\n";
    $configContent .= "    'tg2email_bufferTime' => " . $bufferTime . ",\n";
    $configContent .= "    'tg2email_adminEmail' => '" . addslashes($adminEmail) . "',\n";
    $configContent .= "];\n";

    // Путь к файлу конфига
    $configFile = ENGINE_DIR . '/data/tg2email.php';

    // Записываем новый конфиг
    if (file_put_contents($configFile, $configContent)) {
        echo '<div class="alert alert-success">Настройки успешно сохранены.</div>';
    } else {
        echo '<div class="alert alert-danger">Ошибка при записи конфига. Проверьте права доступа к файлу.</div>';
    }
}

echoheader('tg2email', 'tg2email - пересылка сообщений из Telegram на Email');
include '/plugins/tcse/tg2email/bot.php';
include MODULE_DIR . '/main.php';
echofooter();