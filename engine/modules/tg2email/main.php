<?php
if (!defined('DATALIFEENGINE') || !defined('LOGGED_IN')) {
    die('Hacking attempt!');
}
include_once (DLEPlugins::Check(ENGINE_DIR . '/data/tg2email.php'));


?>

<div class="panel panel-default">
    <div class="panel-heading">
        Настройки плагина tg2email
    </div>
    <div class="panel-body">
        <form action="" method="post" class="systemsettings">
            <h4>Пересылка сообщений от Telegram бота на email</h4>
            <div class="row">
                <div class="col-xs-12 col-md-7">
                    <h5>Bot Token</h5>
                    <div class="text-muted text-size-small hidden-xs">
                        <p>Необходим для работы с Telegram</p>
                        <p>Создайте нового бота у <a href="https://t.me/BotFather" target="_blank">@BotFather</a> или получите у него же токен для ранее созданных ботов.</p>
                    </div>
                </div>
                <div class="col-xs-12 col-md-5">
                    <input dir="auto" type="text" class="form-control" name="botToken" value="<?php echo $tg2emailConfig['tg2email_TOKEN']; ?>">
                </div>

                <div class="col-xs-12 col-md-7">
                    <h5>Admin Chat ID</h5>
                    <div class="text-muted text-size-small hidden-xs">
                        <p>ID чата с Telegram ботом, этот параметр позволяет боту принимать сообщения от вас и пересылалть их на указанный email</p>
                        <p>
                            0 — разрешено всем<br>
                            123 — только один пользователь<br>
                            123,456 — несколько пользователей<br>
                        </p>
                        <p>После того как создали бота перейдите на его страницу и запустите с ним диалог командой /start после чего напишите любой текст этому боту. На самом деле это значение PEER ID вашего акканута в Телеграм</p>
                    </div>
                </div>
                <div class="col-xs-12 col-md-5">
                    <input dir="auto" type="text" class="form-control" name="adminChatId" value="<?php echo $tg2emailConfig['tg2email_CHATID']; ?>">
                </div>

                <div class="col-xs-12 col-md-7">
                    <h5>Admin Email</h5>
                    <div class="text-muted text-size-small hidden-xs">Адрес получателя email</div>
                </div>
                <div class="col-xs-12 col-md-5">
                    <input dir="auto" type="text" class="form-control" name="adminEmail" value="<?php echo $tg2emailConfig['tg2email_adminEmail']; ?>">
                </div>

                <div class="col-xs-12 col-md-7">
                    <h5>Формат Email</h5>
                    <div class="text-muted text-size-small hidden-xs">Простой текст ( 0 ) или html ( 1 )</div>
                </div>
                <div class="col-xs-12 col-md-5">
                    <input dir="auto" type="text" class="form-control" name="formatEmail" value="<?php echo $tg2emailConfig['tg2email_formatEmail']; ?>">

                    <!-- <select name="tg2email_formatemail">
                        <option value="0" ".($tg2emailConfig['tg2email_formatemail'] == '0' ? 'selected' : '').">Текстовое письмо</option>
                        <option value="1" ".($tg2emailConfig['tg2email_formatemail'] == '1' ? 'selected' : '').">HTML-письмо</option>
                    </select> -->

                </div>

                <div class="col-xs-12 col-md-7">
                    <h5>Buffer Time</h5>
                    <div class="text-muted text-size-small hidden-xs">
                        <p>
                            Время буферизации в минутах (по умолчанию 1 минута)<br>
                            Используется для предварительного накопления пересылаемых сообщений и формирования единого письма для отправки<br>
                            📥 Первое сообщение в буфере. Жду 1 мин...
                        </p>
                    </div>
                </div>
                <div class="col-xs-12 col-md-5">
                    <input dir="auto" type="text" class="form-control" name="bufferTime" value="<?php echo $tg2emailConfig['tg2email_bufferTime']; ?>">
                </div>
            </div>
            <div style="margin:30px 0;">
                <button type="submit" class="btn bg-teal btn-raised position-left legitRipple"><i class="fa fa-floppy-o position-left"></i>Сохранить</button>
            </div>
        </form>
    </div>
</div>

<div class="panel panel-default">
    <div class="panel-heading">
        Веб-хук и управление ботом
    </div>
    <div class="panel-body">
        <?php
        $token = $tg2emailConfig['tg2email_TOKEN'] ?: 'YOUR_BOT_TOKEN_HERE';
        $webhook_url = "https://"  . $_SERVER['HTTP_HOST'] . "/plugins/tcse/tg2email/bot.php";
        ?>
        <p>
            <strong>API:</strong> https://api.telegram.org/bot<?php echo $tg2emailConfig['tg2email_TOKEN']; ?>/getUpdates <br>
            <a href="https://api.telegram.org/bot<?php echo $tg2emailConfig['tg2email_TOKEN']; ?>/getUpdates" class="btn btn-primary" target="_blank">Открыть</a>
        </p>
        <p>
            <strong>Информация о боте:</strong><br>
            <code>https://api.telegram.org/bot<?php echo $tg2emailConfig['tg2email_TOKEN']; ?>/getMe</code>
            <a href="https://api.telegram.org/bot<?php echo $tg2emailConfig['tg2email_TOKEN']; ?>/getMe" class="btn btn-warning" target="_blank">Запросить</a>
        </p>
        <p>
            <strong>Установить веб-хук:</strong><br>
            <code>https://api.telegram.org/bot<?php echo $tg2emailConfig['tg2email_TOKEN']; ?>/setWebhook?url=<?=$webhook_url?></code>
            <a href="https://api.telegram.org/bot<?php echo $tg2emailConfig['tg2email_TOKEN']; ?>/setWebhook?url=<?=$webhook_url?>" class="btn btn-info" target="_blank">Установить</a>
        </p>
        <p>
            <strong>Удалить веб-хук:</strong><br>
            <code>https://api.telegram.org/bot<?php echo $tg2emailConfig['tg2email_TOKEN']; ?>/deleteWebhook</code>
            <a href="https://api.telegram.org/bot<?php echo $tg2emailConfig['tg2email_TOKEN']; ?>/deleteWebhook" class="btn btn-danger" target="_blank">Удалить</a>
        </p>
        


    </div>
</div>
