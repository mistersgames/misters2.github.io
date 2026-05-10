#!/usr/bin/env php
<?php
/**
 * Бот обратной связи на PHP (вебхук)
 *
 * - Пользователь пишет боту
 * - Владельцу приходит: имя, ссылка на профиль, текст
 * - Владелец нажимает «Ответить» — пишет ответ, пользователь получает его с цитатой
 * - Кнопка «❌ Отмена» вместо /cancel
 * - История всех обращений хранится в users_db.json
 * - Кнопка «👥 Список пользователей» в меню бота (только для владельца, через setMyCommands)
 */

// ========== НАСТРОЙКИ ==========

define('BOT_TOKEN', '7676034016:AAF4fJGQ0rPte6HtujgeO8cr-F7zigykto4');
define('OWNER_ID',  7986675852);

define('STATE_FILE', __DIR__ . '/bot_state.json');
define('USERS_DB',   __DIR__ . '/users_db.json');
define('LOG_FILE',   __DIR__ . '/bot.log');
define('MENU_FLAG',  __DIR__ . '/menu_registered.flag');

// Максимум сообщений на пользователя в истории
define('MAX_MSGS_PER_USER', 200);

// ========== ЛОГИРОВАНИЕ ==========
function logMessage($msg) {
    $time = date('Y-m-d H:i:s');
    file_put_contents(LOG_FILE, "[$time] $msg\n", FILE_APPEND);
}

// ========== ОТПРАВКА ЗАПРОСОВ К API ==========
function sendRequest($method, $parameters = []) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => 'https://api.telegram.org/bot' . BOT_TOKEN . '/' . $method,
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($parameters, JSON_UNESCAPED_UNICODE),
    ]);
    $result = curl_exec($ch);
    curl_close($ch);

    if ($result === false) {
        logMessage("Ошибка cURL: $method");
        return false;
    }

    $response = json_decode($result, true);
    if (empty($response['ok'])) {
        logMessage("Ошибка API ($method): " . print_r($response, true));
    }
    return $response;
}

// ========== РЕГИСТРАЦИЯ МЕНЮ БОТА ==========
// Запускается один раз при первом входящем запросе.
// Обычные пользователи видят только /start.
// Владелец видит /start + /users в меню (кнопка «👥 Список пользователей»).
// Чтобы переприменить меню — удали файл menu_registered.flag рядом со скриптом.
function registerBotMenu(): void {
    // Для всех пользователей
    sendRequest('setMyCommands', [
        'commands' => [
            ['command' => 'start', 'description' => 'Написать сообщение владельцу'],
        ],
        'scope' => ['type' => 'default'],
    ]);

    // Только для владельца
    sendRequest('setMyCommands', [
        'commands' => [
            ['command' => 'start', 'description' => 'Написать сообщение'],
            ['command' => 'users', 'description' => '👥 Список пользователей'],
        ],
        'scope' => [
            'type'    => 'chat',
            'chat_id' => OWNER_ID,
        ],
    ]);

    file_put_contents(MENU_FLAG, '1');
    logMessage("Меню бота зарегистрировано.");
}

// ========== СОСТОЯНИЕ (ожидание ответа) ==========
function getState(): array {
    if (!file_exists(STATE_FILE)) return ['reply_to' => null, 'reply_msg_id' => null];
    return json_decode(file_get_contents(STATE_FILE), true) ?: ['reply_to' => null, 'reply_msg_id' => null];
}

function setState($userId, $msgId = null): void {
    file_put_contents(STATE_FILE, json_encode(['reply_to' => $userId, 'reply_msg_id' => $msgId]));
}

function clearState(): void {
    setState(null, null);
}

// ========== БАЗА ПОЛЬЗОВАТЕЛЕЙ ==========
function loadDb(): array {
    if (!file_exists(USERS_DB)) return [];
    return json_decode(file_get_contents(USERS_DB), true) ?: [];
}

function saveDb(array $db): void {
    file_put_contents(USERS_DB, json_encode($db, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function saveUserMessage(array $user, array $msgData): void {
    $db  = loadDb();
    $uid = (string)$user['id'];

    if (!isset($db[$uid])) {
        $db[$uid] = [
            'id'         => $user['id'],
            'first_name' => $user['first_name'] ?? '',
            'last_name'  => $user['last_name']  ?? '',
            'username'   => $user['username']   ?? '',
            'messages'   => [],
        ];
    } else {
        $db[$uid]['first_name'] = $user['first_name'] ?? $db[$uid]['first_name'];
        $db[$uid]['last_name']  = $user['last_name']  ?? $db[$uid]['last_name'];
        $db[$uid]['username']   = $user['username']   ?? $db[$uid]['username'];
    }

    $db[$uid]['messages'][] = $msgData;

    if (count($db[$uid]['messages']) > MAX_MSGS_PER_USER) {
        $db[$uid]['messages'] = array_slice($db[$uid]['messages'], -MAX_MSGS_PER_USER);
    }

    saveDb($db);
}

function saveOwnerReply(int $userId, string $replyText, ?int $toMsgId): void {
    $db  = loadDb();
    $uid = (string)$userId;
    if (!isset($db[$uid])) return;

    $db[$uid]['messages'][] = [
        'type'      => 'owner_reply',
        'text'      => $replyText,
        'to_msg_id' => $toMsgId,
        'time'      => time(),
    ];

    saveDb($db);
}

// ========== ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ==========
function getProfileLink(array $user): string {
    if (!empty($user['username'])) {
        return '<a href="https://t.me/' . $user['username'] . '">@' . $user['username'] . '</a>';
    }
    return '<a href="tg://user?id=' . $user['id'] . '">' . htmlspecialchars($user['first_name'] ?? '') . '</a>';
}

function getFullName(array $user): string {
    $name = htmlspecialchars($user['first_name'] ?? '');
    if (!empty($user['last_name'])) $name .= ' ' . htmlspecialchars($user['last_name']);
    return $name;
}

// ========== СПИСОК ПОЛЬЗОВАТЕЛЕЙ ==========
function sendUserList(int $page = 0): void {
    $db      = loadDb();
    $perPage = 8;
    $users   = array_values($db);
    $total   = count($users);
    $pages   = max(1, (int)ceil($total / $perPage));

    if ($total === 0) {
        sendRequest('sendMessage', [
            'chat_id' => OWNER_ID,
            'text'    => '📭 Ещё никто не писал боту.',
        ]);
        return;
    }

    $slice   = array_slice($users, $page * $perPage, $perPage);
    $buttons = [];

    foreach ($slice as $u) {
        $name    = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
        $uname   = !empty($u['username']) ? ' @' . $u['username'] : '';
        $count   = count($u['messages']);
        $label   = "👤 {$name}{$uname} · {$count} сообщ.";
        $buttons[] = [['text' => $label, 'callback_data' => 'user_history:' . $u['id'] . ':0']];
    }

    $nav = [];
    if ($page > 0)          $nav[] = ['text' => '◀️ Назад',  'callback_data' => 'users_page:' . ($page - 1)];
    if ($page < $pages - 1) $nav[] = ['text' => 'Вперёд ▶️', 'callback_data' => 'users_page:' . ($page + 1)];
    if ($nav) $buttons[] = $nav;

    sendRequest('sendMessage', [
        'chat_id'      => OWNER_ID,
        'text'         => "👥 <b>Пользователи</b> (стр. " . ($page + 1) . "/{$pages}, всего: {$total}):",
        'parse_mode'   => 'HTML',
        'reply_markup' => json_encode(['inline_keyboard' => $buttons]),
    ]);
}

// ========== ИСТОРИЯ ПОЛЬЗОВАТЕЛЯ ==========
function sendUserHistory(int $userId, int $page = 0): void {
    $db  = loadDb();
    $uid = (string)$userId;

    if (!isset($db[$uid])) {
        sendRequest('sendMessage', ['chat_id' => OWNER_ID, 'text' => '❓ Пользователь не найден.']);
        return;
    }

    $u       = $db[$uid];
    $name    = getFullName($u);
    $uname   = !empty($u['username']) ? ' (@' . $u['username'] . ')' : '';
    $msgs    = array_reverse($u['messages']); // Новые сверху
    $perPage = 5;
    $total   = count($msgs);
    $pages   = max(1, (int)ceil($total / $perPage));
    $slice   = array_slice($msgs, $page * $perPage, $perPage);

    $text  = "📋 <b>История: {$name}{$uname}</b>\n";
    $text .= "🆔 <code>{$userId}</code> · всего: {$total}\n\n";

    $replyButtons = [];

    foreach ($slice as $idx => $m) {
        $globalIdx = $page * $perPage + $idx;
        $realIdx   = $total - 1 - $globalIdx;
        $time      = date('d.m.Y H:i', $m['time']);

        if ($m['type'] === 'owner_reply') {
            $preview  = mb_strimwidth($m['text'] ?? '', 0, 60, '…');
            $text    .= "📤 <i>[$time] Ответ:</i>\n" . htmlspecialchars($preview) . "\n\n";
        } else {
            $typeLabel = match($m['type']) {
                'text'     => '💬',
                'photo'    => '🖼 Фото',
                'video'    => '🎬 Видео',
                'document' => '📎 Файл',
                'voice'    => '🎤 Голос',
                'sticker'  => '🎭 Стикер',
                default    => '📨',
            };
            $preview = '';
            if (!empty($m['text']))    $preview = mb_strimwidth($m['text'],    0, 80, '…');
            if (!empty($m['caption'])) $preview = mb_strimwidth($m['caption'], 0, 80, '…');
            $text .= "{$typeLabel} <i>[{$time}]</i>\n";
            if ($preview) $text .= htmlspecialchars($preview) . "\n";
            $text .= "\n";

            $replyButtons[] = [[
                'text'          => "💬 Ответить · $time",
                'callback_data' => 'reply:' . $userId . ':' . $realIdx,
            ]];
        }
    }

    $nav = [];
    if ($page > 0)          $nav[] = ['text' => '◀️ Назад',  'callback_data' => 'user_history:' . $userId . ':' . ($page - 1)];
    if ($page < $pages - 1) $nav[] = ['text' => 'Вперёд ▶️', 'callback_data' => 'user_history:' . $userId . ':' . ($page + 1)];
    if ($nav) $replyButtons[] = $nav;

    $replyButtons[] = [['text' => '🔙 К списку пользователей', 'callback_data' => 'users_page:0']];

    sendRequest('sendMessage', [
        'chat_id'      => OWNER_ID,
        'text'         => $text,
        'parse_mode'   => 'HTML',
        'reply_markup' => json_encode(['inline_keyboard' => $replyButtons]),
    ]);
}

// ========== ВХОД ==========

// При первом запуске (нет флага) — регистрируем команды меню через Telegram API.
// Для обычных пользователей: только /start.
// Для владельца: /start + /users (отображается как кнопка в меню «👥 Список пользователей»).
if (!file_exists(MENU_FLAG)) {
    registerBotMenu();
}

$content = file_get_contents('php://input');
if (!$content) die('ok');

$update = json_decode($content, true);
if (!$update) {
    logMessage("Невалидный JSON: $content");
    die('ok');
}

logMessage("Обновление: " . json_encode($update, JSON_UNESCAPED_UNICODE));

// ========== CALLBACK QUERY ==========
if (isset($update['callback_query'])) {
    $callback   = $update['callback_query'];
    $callbackId = $callback['id'];
    $data       = $callback['data'];
    $fromId     = $callback['from']['id'];

    sendRequest('answerCallbackQuery', ['callback_query_id' => $callbackId]);

    if ($fromId != OWNER_ID) {
        sendRequest('sendMessage', ['chat_id' => $fromId, 'text' => '❌ Нет доступа.']);
        die('ok');
    }

    // Отмена режима ответа
    if ($data === 'cancel_reply') {
        clearState();
        sendRequest('sendMessage', ['chat_id' => OWNER_ID, 'text' => '❌ Режим ответа отменён.']);
        die('ok');
    }

    // Навигация по страницам пользователей: users_page:{page}
    if (strpos($data, 'users_page:') === 0) {
        $page = (int)substr($data, 11);
        sendUserList($page);
        die('ok');
    }

    // История пользователя: user_history:{userId}:{page}
    if (strpos($data, 'user_history:') === 0) {
        [, $uid, $page] = explode(':', $data);
        sendUserHistory((int)$uid, (int)$page);
        die('ok');
    }

    // Ответить на конкретное обращение: reply:{userId}:{msgIndex}
    if (strpos($data, 'reply:') === 0) {
        $parts  = explode(':', $data);
        $uid    = (int)$parts[1];
        $msgIdx = isset($parts[2]) ? (int)$parts[2] : null;

        setState($uid, $msgIdx);

        $db    = loadDb();
        $quote = '';
        if ($msgIdx !== null && isset($db[(string)$uid]['messages'][$msgIdx])) {
            $m = $db[(string)$uid]['messages'][$msgIdx];
            if (!empty($m['text']))    $quote = mb_strimwidth($m['text'],    0, 100, '…');
            if (!empty($m['caption'])) $quote = mb_strimwidth($m['caption'], 0, 100, '…');
        }

        $quoteLine = $quote
            ? "\n💬 <i>«" . htmlspecialchars($quote) . "»</i>"
            : '';

        sendRequest('sendMessage', [
            'chat_id'      => OWNER_ID,
            'text'         => "✏️ Режим ответа пользователю <code>{$uid}</code>{$quoteLine}\n\nНапишите текст ответа:",
            'parse_mode'   => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => [[
                    ['text' => '❌ Отмена', 'callback_data' => 'cancel_reply'],
                ]]
            ]),
        ]);
        die('ok');
    }

    die('ok');
}

// ========== СООБЩЕНИЯ ==========
if (!isset($update['message'])) die('ok');

$message = $update['message'];
$chatId  = $message['chat']['id'];
$userId  = $message['from']['id'];
$text    = $message['text'] ?? '';

// /start
if ($text === '/start') {
    sendRequest('sendMessage', [
        'chat_id' => $chatId,
        'text'    => "👋 Привет! Напишите ваше сообщение — я передам его владельцу.",
    ]);
    die('ok');
}

// /users — только владелец (кнопка в меню бота)
if ($text === '/users' && $userId == OWNER_ID) {
    sendUserList(0);
    die('ok');
}

// ========== ВЛАДЕЛЕЦ В РЕЖИМЕ ОТВЕТА ==========
if ($userId == OWNER_ID) {
    $state = getState();
    if ($state['reply_to']) {
        $targetUserId = (int)$state['reply_to'];
        $replyMsgIdx  = $state['reply_msg_id'];

        $db    = loadDb();
        $quote = '';
        if ($replyMsgIdx !== null && isset($db[(string)$targetUserId]['messages'][$replyMsgIdx])) {
            $m = $db[(string)$targetUserId]['messages'][$replyMsgIdx];
            if (!empty($m['text']))    $quote = $m['text'];
            if (!empty($m['caption'])) $quote = $m['caption'];
        }

        $replyText = "📬 <b>Вам ответили</b>";
        if ($quote) {
            $replyText .= " на ваше сообщение:\n<i>«" . htmlspecialchars(mb_strimwidth($quote, 0, 120, '…')) . "»</i>";
        }
        $replyText .= "\n\n" . htmlspecialchars($text);

        $result = sendRequest('sendMessage', [
            'chat_id'    => $targetUserId,
            'text'       => $replyText,
            'parse_mode' => 'HTML',
        ]);

        if ($result && $result['ok']) {
            saveOwnerReply($targetUserId, $text, $replyMsgIdx);
            sendRequest('sendMessage', ['chat_id' => OWNER_ID, 'text' => '✅ Ответ отправлен!']);
            clearState();
        } else {
            $errorCode = $result['error_code'] ?? 0;
            $errorDesc = $result['description'] ?? 'неизвестная ошибка';
            logMessage("Ошибка ответа пользователю $targetUserId: [$errorCode] $errorDesc");

            if ($errorCode === 403) {
                clearState();
                sendRequest('sendMessage', [
                    'chat_id'    => OWNER_ID,
                    'text'       => "🚫 Пользователь <code>$targetUserId</code> заблокировал бота. Режим сброшен.",
                    'parse_mode' => 'HTML',
                ]);
            } else {
                sendRequest('sendMessage', [
                    'chat_id'      => OWNER_ID,
                    'text'         => "❌ Ошибка отправки <code>$targetUserId</code>:\n<code>[$errorCode] $errorDesc</code>\n\nПопробуйте ещё раз:",
                    'parse_mode'   => 'HTML',
                    'reply_markup' => json_encode([
                        'inline_keyboard' => [[
                            ['text' => '❌ Отмена', 'callback_data' => 'cancel_reply'],
                        ]]
                    ]),
                ]);
            }
        }
        die('ok');
    }
    // Владелец не в режиме ответа — игнорируем
    die('ok');
}

// ========== СООБЩЕНИЕ ОТ ОБЫЧНОГО ПОЛЬЗОВАТЕЛЯ ==========
$user        = $message['from'];
$profileLink = getProfileLink($user);
$fullName    = getFullName($user);
$msgTime     = $message['date'] ?? time();
$msgId       = $message['message_id'] ?? null;

$msgData = ['type' => 'unknown', 'time' => $msgTime, 'msg_id' => $msgId];

if (isset($message['text'])) {
    $msgData['type'] = 'text';
    $msgData['text'] = $message['text'];
} elseif (isset($message['photo'])) {
    $photo = $message['photo'][count($message['photo']) - 1];
    $msgData['type']    = 'photo';
    $msgData['file_id'] = $photo['file_id'];
    $msgData['caption'] = $message['caption'] ?? '';
} elseif (isset($message['video'])) {
    $msgData['type']    = 'video';
    $msgData['file_id'] = $message['video']['file_id'];
    $msgData['caption'] = $message['caption'] ?? '';
} elseif (isset($message['document'])) {
    $msgData['type']    = 'document';
    $msgData['file_id'] = $message['document']['file_id'];
    $msgData['caption'] = $message['caption'] ?? '';
} elseif (isset($message['voice'])) {
    $msgData['type']    = 'voice';
    $msgData['file_id'] = $message['voice']['file_id'];
} elseif (isset($message['sticker'])) {
    $msgData['type']    = 'sticker';
    $msgData['file_id'] = $message['sticker']['file_id'];
} else {
    die('ok');
}

saveUserMessage($user, $msgData);

$dbNow   = loadDb();
$uid     = (string)$user['id'];
$realIdx = count($dbNow[$uid]['messages']) - 1;

$header = "📩 <b>Новое сообщение</b>\n"
        . "👤 {$fullName} · {$profileLink}\n"
        . "🆔 <code>{$user['id']}</code>\n";

$replyMarkup = json_encode([
    'inline_keyboard' => [[
        ['text' => '💬 Ответить', 'callback_data' => 'reply:' . $user['id'] . ':' . $realIdx],
        ['text' => '📋 История',  'callback_data' => 'user_history:' . $user['id'] . ':0'],
    ]]
]);

if ($msgData['type'] === 'text') {
    sendRequest('sendMessage', [
        'chat_id'      => OWNER_ID,
        'text'         => $header . $message['text'],
        'parse_mode'   => 'HTML',
        'reply_markup' => $replyMarkup,
    ]);
} elseif ($msgData['type'] === 'photo') {
    sendRequest('sendPhoto', [
        'chat_id'      => OWNER_ID,
        'photo'        => $msgData['file_id'],
        'caption'      => $header . ($message['caption'] ?? ''),
        'parse_mode'   => 'HTML',
        'reply_markup' => $replyMarkup,
    ]);
} elseif ($msgData['type'] === 'video') {
    sendRequest('sendVideo', [
        'chat_id'      => OWNER_ID,
        'video'        => $msgData['file_id'],
        'caption'      => $header . ($message['caption'] ?? ''),
        'parse_mode'   => 'HTML',
        'reply_markup' => $replyMarkup,
    ]);
} elseif ($msgData['type'] === 'document') {
    sendRequest('sendDocument', [
        'chat_id'      => OWNER_ID,
        'document'     => $msgData['file_id'],
        'caption'      => $header . ($message['caption'] ?? ''),
        'parse_mode'   => 'HTML',
        'reply_markup' => $replyMarkup,
    ]);
} elseif ($msgData['type'] === 'voice') {
    sendRequest('sendVoice', [
        'chat_id'      => OWNER_ID,
        'voice'        => $msgData['file_id'],
        'caption'      => $header,
        'parse_mode'   => 'HTML',
        'reply_markup' => $replyMarkup,
    ]);
} elseif ($msgData['type'] === 'sticker') {
    sendRequest('sendMessage', [
        'chat_id'    => OWNER_ID,
        'text'       => $header,
        'parse_mode' => 'HTML',
    ]);
    sendRequest('sendSticker', [
        'chat_id' => OWNER_ID,
        'sticker' => $msgData['file_id'],
    ]);
    sendRequest('sendMessage', [
        'chat_id'      => OWNER_ID,
        'text'         => '👆 Стикер выше',
        'reply_markup' => $replyMarkup,
    ]);
}

sendRequest('sendMessage', [
    'chat_id' => $chatId,
    'text'    => '✅ Сообщение отправлено! Ожидайте ответа.',
]);

die('ok');
