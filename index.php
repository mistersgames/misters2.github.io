#!/usr/bin/env php
<?php
/**
 * Простой бот обратной связи на PHP (вебхук)
 * 
 * - Пользователь пишет боту
 * - Владельцу приходит: имя, ссылка на профиль, текст
 * - Владелец нажимает «Ответить» и пишет — ответ уходит пользователю
 */

// ========== НАСТРОЙКИ ==========

// Токен бота который можно получить в @BotFather
define('BOT_TOKEN', '7676034016:AAF4fJGQ0rPte6HtujgeO8cr-F7zigykto4');

// Айди аккаунта Telegram
define('OWNER_ID', 7986675852);

define('STATE_FILE', __DIR__ . '/bot_state.json');   // файл для хранения состояния
define('LOG_FILE', __DIR__ . '/bot.log');            // файл лога

// ========== ЛОГИРОВАНИЕ ==========
function logMessage($msg) {
    $time = date('Y-m-d H:i:s');
    file_put_contents(LOG_FILE, "[$time] $msg\n", FILE_APPEND);
}

// ========== ОТПРАВКА ЗАПРОСОВ К API ==========
function sendRequest($method, $parameters = []) {
    /*$url = "https://api.telegram.org/bot" . BOT_TOKEN . "/" . $method;
    $options = [
        'http' => [
            'header'  => "Content-Type: application/json\r\n",
            'method'  => 'POST',
            'content' => json_encode($parameters),
            'ignore_errors' => true
        ]
    ];
    $context = stream_context_create($options);
    $result = file_get_contents($url, false, $context);*/
    
	$ch = curl_init();
	curl_setopt_array(
		$ch,
		array(
			CURLOPT_URL => 'https://api.telegram.org/bot' . BOT_TOKEN . '/' . $method,
			CURLOPT_POST => TRUE,
			CURLOPT_RETURNTRANSFER => TRUE,
			CURLOPT_TIMEOUT => 10,
			CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
			CURLOPT_POSTFIELDS => json_encode($parameters, JSON_UNESCAPED_UNICODE),
		)
	);
	$result = curl_exec($ch);
    
    if ($result === false) {
        logMessage("Ошибка отправки запроса: $method");
        return false;
    }
    
    $response = json_decode($result, true);
    if (!$response['ok']) {
        logMessage("Ошибка API: " . print_r($response, true));
    }
    return $response;
}

// ========== РАБОТА С СОСТОЯНИЕМ (ожидание ответа) ==========
function getState() {
    if (!file_exists(STATE_FILE)) {
        return ['reply_to' => null];
    }
    $content = file_get_contents(STATE_FILE);
    return json_decode($content, true) ?: ['reply_to' => null];
}

function setState($replyTo) {
    $state = ['reply_to' => $replyTo];
    file_put_contents(STATE_FILE, json_encode($state));
}

function clearState() {
    setState(null);
}

// ========== ФОРМИРОВАНИЕ ССЫЛКИ НА ПРОФИЛЬ ==========
function getProfileLink($user) {
    if (!empty($user['username'])) {
        return '<a href="https://t.me/' . $user['username'] . '">@' . $user['username'] . '</a>';
    } else {
        return '<a href="tg://user?id=' . $user['id'] . '">' . htmlspecialchars($user['first_name'] ?? '') . '</a>';
    }
}

// ========== ОБРАБОТКА ВХОДЯЩЕГО ОБНОВЛЕНИЯ ==========
$content = file_get_contents('php://input');
if (!$content) {
    die('ok');
}

$update = json_decode($content, true);
if (!$update) {
    logMessage("Невалидный JSON: $content");
    die('ok');
}

logMessage("Получено обновление: " . json_encode($update));

// ========== ОБРАБОТКА CALLBACK QUERY (нажатие кнопки) ==========
if (isset($update['callback_query'])) {
    $callback = $update['callback_query'];
    $callbackId = $callback['id'];
    $data = $callback['data'];
    $fromId = $callback['from']['id'];
    
    // Подтверждаем получение callback'а
    sendRequest('answerCallbackQuery', ['callback_query_id' => $callbackId]);
    
    // Проверяем, что это владелец
    if ($fromId != OWNER_ID) {
        sendRequest('sendMessage', [
            'chat_id' => $fromId,
            'text' => '❌ У вас нет прав для этого действия.'
        ]);
        die('ok');
    }
    
    if (strpos($data, 'reply:') === 0) {
        $userId = (int) substr($data, 6);
        setState($userId);
        
        sendRequest('sendMessage', [
            'chat_id' => OWNER_ID,
            'text' => "✏️ Напишите ответ пользователю <code>$userId</code>.\nОтправьте /cancel для отмены.",
            'parse_mode' => 'HTML'
        ]);
    }
    
    die('ok');
}

// ========== ОБРАБОТКА СООБЩЕНИЙ ==========
if (!isset($update['message'])) {
    die('ok');
}

$message = $update['message'];
$chatId = $message['chat']['id'];
$userId = $message['from']['id'];
$text = $message['text'] ?? '';

// ========== КОМАНДА /start ==========
if ($text === '/start') {
    sendRequest('sendMessage', [
        'chat_id' => $chatId,
        'text' => "👋 Привет! Напишите ваше сообщение — я передам его владельцу."
    ]);
    die('ok');
}

// ========== КОМАНДА /cancel (только для владельца) ==========
if ($text === '/cancel' && $userId == OWNER_ID) {
    clearState();
    sendRequest('sendMessage', [
        'chat_id' => $chatId,
        'text' => "❌ Отменено."
    ]);
    die('ok');
}

// ========== ОБРАБОТКА СООБЩЕНИЙ ОТ ВЛАДЕЛЬЦА (режим ответа) ==========
if ($userId == OWNER_ID) {
    $state = getState();
    if ($state['reply_to']) {
        // Отправляем ответ пользователю
        $targetUserId = $state['reply_to'];
        $result = sendRequest('sendMessage', [
            'chat_id' => $targetUserId,
            'text' => "📬 <b>Ответ:</b>\n\n" . $text,
            'parse_mode' => 'HTML'
        ]);
        
        if ($result && $result['ok']) {
            sendRequest('sendMessage', [
                'chat_id' => OWNER_ID,
                'text' => "✅ Ответ отправлен!"
            ]);
            clearState();
        } else {
            $errorCode = $result['error_code'] ?? 0;
            $errorDescription = $result['description'] ?? 'неизвестная ошибка';
            logMessage("Ошибка отправки ответа пользователю $targetUserId: [$errorCode] $errorDescription");

            if ($errorCode === 403) {
                clearState();
                sendRequest('sendMessage', [
                    'chat_id' => OWNER_ID,
                    'text' => "🚫 Пользователь <code>$targetUserId</code> заблокировал бота.\nОтветить невозможно — режим ответа сброшен.",
                    'parse_mode' => 'HTML'
                ]);
            } else {
                sendRequest('sendMessage', [
                    'chat_id' => OWNER_ID,
                    'text' => "❌ Ошибка отправки пользователю <code>$targetUserId</code>:\n<code>[$errorCode] $errorDescription</code>\n\nПопробуйте ещё раз или /cancel для отмены.",
                    'parse_mode' => 'HTML'
                ]);
            }
        }
        die('ok');
    }
    // Если владелец пишет что-то другое — игнорируем (не пересылаем сам себе)
    die('ok');
}

// ========== СООБЩЕНИЕ ОТ ОБЫЧНОГО ПОЛЬЗОВАТЕЛЯ ==========
// Формируем заголовок для пересылки владельцу
$user = $message['from'];
$profileLink = getProfileLink($user);
$fullName = htmlspecialchars($user['first_name'] ?? '') . (isset($user['last_name']) ? ' ' . htmlspecialchars($user['last_name']) : '');
$header = "📩 <b>Новое сообщение</b>\n"
        . "👤 $fullName · $profileLink\n"
        . "🆔 <code>{$user['id']}</code>\n"
        . str_repeat('─', 28) . "\n";

// Кнопка "Ответить"
$replyMarkup = json_encode([
    'inline_keyboard' => [
        [
            ['text' => '💬 Ответить', 'callback_data' => 'reply:' . $user['id']]
        ]
    ]
]);

// Определяем тип сообщения и пересылаем
if (isset($message['text'])) {
    sendRequest('sendMessage', [
        'chat_id' => OWNER_ID,
        'text' => $header . $message['text'],
        'parse_mode' => 'HTML',
        'reply_markup' => $replyMarkup
    ]);
} 
elseif (isset($message['photo'])) {
    $photo = $message['photo'][count($message['photo']) - 1]; // самое большое фото
    sendRequest('sendPhoto', [
        'chat_id' => OWNER_ID,
        'photo' => $photo['file_id'],
        'caption' => $header . ($message['caption'] ?? ''),
        'parse_mode' => 'HTML',
        'reply_markup' => $replyMarkup
    ]);
}
elseif (isset($message['video'])) {
    sendRequest('sendVideo', [
        'chat_id' => OWNER_ID,
        'video' => $message['video']['file_id'],
        'caption' => $header . ($message['caption'] ?? ''),
        'parse_mode' => 'HTML',
        'reply_markup' => $replyMarkup
    ]);
}
elseif (isset($message['document'])) {
    sendRequest('sendDocument', [
        'chat_id' => OWNER_ID,
        'document' => $message['document']['file_id'],
        'caption' => $header . ($message['caption'] ?? ''),
        'parse_mode' => 'HTML',
        'reply_markup' => $replyMarkup
    ]);
}
elseif (isset($message['voice'])) {
    sendRequest('sendVoice', [
        'chat_id' => OWNER_ID,
        'voice' => $message['voice']['file_id'],
        'caption' => $header,
        'parse_mode' => 'HTML',
        'reply_markup' => $replyMarkup
    ]);
}
elseif (isset($message['sticker'])) {
    // Сначала отправляем заголовок
    sendRequest('sendMessage', [
        'chat_id' => OWNER_ID,
        'text' => $header,
        'parse_mode' => 'HTML'
    ]);
    // Потом стикер
    sendRequest('sendSticker', [
        'chat_id' => OWNER_ID,
        'sticker' => $message['sticker']['file_id']
    ]);
    // И пояснение с кнопкой
    sendRequest('sendMessage', [
        'chat_id' => OWNER_ID,
        'text' => "👆 Стикер выше",
        'reply_markup' => $replyMarkup
    ]);
}
else {
    // Неподдерживаемый тип — ничего не делаем
    die('ok');
}

// Подтверждаем пользователю, что сообщение отправлено
sendRequest('sendMessage', [
    'chat_id' => $chatId,
    'text' => "✅ Сообщение отправлено! Ожидайте ответа."
]);

die('ok');
