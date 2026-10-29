<?php
/**
 * api.php — прокси к PocketBase. Настройки — через переменные окружения Railway.
 */

function env(string $k, $default = null) {
    $v = getenv($k);
    return ($v === false || $v === '') ? $default : $v;
}
function envBool(string $k, bool $d = false): bool {
    $v = getenv($k);
    if ($v === false) return $d;
    return in_array(strtolower(trim($v)), ['1','true','yes','on'], true);
}
function envArray(string $k): array {
    $v = getenv($k);
    if ($v === false || trim($v) === '') return [];
    return array_values(array_filter(array_map('trim', explode(',', $v)), fn($x) => $x !== ''));
}

define('PB_URL',               rtrim(env('PB_URL', 'http://127.0.0.1:8090'), '/'));
define('PB_ADMIN_TOKEN',       env('PB_ADMIN_TOKEN', ''));
define('PB_COLLECTION_SITE',   env('PB_COLLECTION_SITE', 'site_data'));
define('PB_COLLECTION_ORDERS', env('PB_COLLECTION_ORDERS', 'orders'));
define('PB_COLLECTION_SUBS',   env('PB_COLLECTION_SUBS', 'subscribers'));

define('ADMIN_SECRET',         env('ADMIN_SECRET', ''));
define('ADMIN_LOGIN',          env('ADMIN_LOGIN', 'admin'));
define('ADMIN_PASSWORD',       env('ADMIN_PASSWORD', 'neospa2025'));

define('VK_ACCESS_TOKEN',      env('VK_ACCESS_TOKEN', ''));
define('VK_API_VERSION',       env('VK_API_VERSION', '5.131'));
$vk_peer_ids = array_values(array_filter(array_map('intval', envArray('VK_PEER_IDS'))));

define('TG_BOT_TOKEN',         env('TG_BOT_TOKEN', ''));
define('TG_API_BASE',          env('TG_API_BASE', ''));
$tg_chat_ids = envArray('TG_CHAT_IDS');

define('NOTIFY_VK',            envBool('NOTIFY_VK', true));
define('NOTIFY_TELEGRAM',      envBool('NOTIFY_TELEGRAM', true));

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . env('ALLOWED_ORIGIN', '*'));
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Admin-Token');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

function pbRequest(string $method, string $path, ?array $body = null): array {
    if (PB_ADMIN_TOKEN === '') throw new RuntimeException('PB_ADMIN_TOKEN не задан');
    $url = PB_URL . $path;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . PB_ADMIN_TOKEN,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 15,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($response === false) throw new RuntimeException('PB cURL: ' . $err);
    $decoded = json_decode($response, true);
    if (!is_array($decoded)) throw new RuntimeException('PB invalid JSON: ' . substr($response,0,200));
    if ($code >= 400) throw new RuntimeException('PB ' . ($decoded['message'] ?? $code));
    return $decoded;
}

function vkSendMessage(int $peerId, string $message): bool {
    if (VK_ACCESS_TOKEN === '' || $peerId === 0) { error_log('VK: пусто'); return false; }
    $params = [
        'access_token' => VK_ACCESS_TOKEN, 'v' => VK_API_VERSION,
        'peer_id' => $peerId, 'message' => $message,
        'random_id' => random_int(1, PHP_INT_MAX),
    ];
    $ch = curl_init('https://api.vk.com/method/messages.send');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $response = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
    if ($response === false) { error_log('VK cURL: ' . $err); return false; }
    $d = json_decode($response, true);
    if (isset($d['error'])) { error_log('VK: ' . json_encode($d['error'], JSON_UNESCAPED_UNICODE)); return false; }
    return true;
}

function tgSendMessage($chatId, string $message): bool {
    if (TG_BOT_TOKEN === '' || $chatId === '' || $chatId === null) { error_log('TG: пусто'); return false; }
    $base = TG_API_BASE !== '' ? rtrim(TG_API_BASE, '/') : 'https://api.telegram.org/bot';
    $url  = $base . TG_BOT_TOKEN . '/sendMessage';
    $params = [
        'chat_id' => $chatId, 'text' => $message,
        'parse_mode' => 'HTML', 'disable_web_page_preview' => 'true',
    ];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $response = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
    if ($response === false) { error_log('TG cURL: ' . $err); return false; }
    $d = json_decode($response, true);
    if (empty($d['ok'])) { error_log('TG: ' . $response); return false; }
    return true;
}

function readJsonBody(): array {
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) throw new RuntimeException('Пустое тело');
    $data = json_decode($raw, true);
    if (!is_array($data)) throw new RuntimeException('Невалидный JSON');
    return $data;
}

function requireAdmin(): void {
    if (ADMIN_SECRET === '') {
        http_response_code(503);
        echo json_encode(['error' => 'ADMIN_SECRET не задан'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $token = $_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '';
    if (!hash_equals(ADMIN_SECRET, $token)) {
        http_response_code(403);
        echo json_encode(['error' => 'Доступ запрещён'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

try {
    $method = $_SERVER['REQUEST_METHOD'];

    switch ($method) {

        case 'GET':
            $content = [];
            try {
                $r = pbRequest('GET', '/api/collections/' . PB_COLLECTION_SITE . '/records?perPage=1');
                if (!empty($r['items'])) {
                    $raw = $r['items'][0]['content'] ?? '{}';
                    $content = json_decode($raw, true) ?: [];
                }
            } catch (Throwable $e) {
                error_log('PB GET: ' . $e->getMessage());
            }
            echo json_encode(['content' => $content ?: new stdClass()], JSON_UNESCAPED_UNICODE);
            break;

        case 'PUT':
            requireAdmin();
            $body = readJsonBody();
            if (!isset($body['content'])) throw new RuntimeException('Нет поля content');
            $list = pbRequest('GET', '/api/collections/' . PB_COLLECTION_SITE . '/records?perPage=1');
            $json = json_encode($body['content'], JSON_UNESCAPED_UNICODE);
            if (empty($list['items'])) {
                pbRequest('POST', '/api/collections/' . PB_COLLECTION_SITE . '/records', ['content' => $json]);
            } else {
                pbRequest('PATCH', '/api/collections/' . PB_COLLECTION_SITE . '/records/' . $list['items'][0]['id'], ['content' => $json]);
            }
            echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
            break;

        case 'POST':
            $body = readJsonBody();
            $action = $body['action'] ?? 'order';

            if ($action === 'login') {
                $login = $body['login'] ?? '';
                $password = $body['password'] ?? '';
                if ($login === ADMIN_LOGIN && $password === ADMIN_PASSWORD) {
                    echo json_encode(['success' => true, 'token' => ADMIN_SECRET], JSON_UNESCAPED_UNICODE);
                } else {
                    http_response_code(401);
                    echo json_encode(['error' => true, 'message' => 'Неверный логин или пароль'], JSON_UNESCAPED_UNICODE);
                }
                break;
            }

            if ($action === 'subscribe') {
                $email = trim($body['email'] ?? '');
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('Неверный email');
                }
                try {
                    pbRequest('POST', '/api/collections/' . PB_COLLECTION_SUBS . '/records', ['email' => $email]);
                } catch (Throwable $e) {
                    error_log('subscribe: ' . $e->getMessage());
                    throw new RuntimeException('Не удалось сохранить подписку. Проверьте коллекцию subscribers в PocketBase.');
                }
                echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
                break;
            }

            foreach (['type','title','name','phone','social_type','social_contact'] as $f) {
                if (empty($body[$f])) throw new RuntimeException('Поле "' . $f . '" обязательно');
            }
            $orderId = 'ORD-' . strtoupper(bin2hex(random_bytes(4))) . '-' . time();
            pbRequest('POST', '/api/collections/' . PB_COLLECTION_ORDERS . '/records', [
                'order_id'       => $orderId,
                'type'           => $body['type'],
                'title'          => $body['title'],
                'name'           => $body['name'],
                'phone'          => $body['phone'],
                'social_type'    => $body['social_type'],
                'social_contact' => $body['social_contact'],
                'datetime'       => $body['datetime'] ?? '',
                'comment'        => $body['comment'] ?? '',
            ]);

            $vkText = "🆕 Новая заявка!\n\n"
                    . "🆔 {$orderId}\n📌 Тип: {$body['type']}\n📝 {$body['title']}\n"
                    . "👤 {$body['name']}\n📞 {$body['phone']}\n"
                    . "💬 {$body['social_type']}: {$body['social_contact']}\n";
            if (!empty($body['datetime'])) $vkText .= "📅 {$body['datetime']}\n";
            if (!empty($body['comment']))  $vkText .= "📄 {$body['comment']}\n";

            $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $tgText = "🆕 <b>Новая заявка!</b>\n\n"
                    . "🆔 <code>{$e($orderId)}</code>\n📌 Тип: {$e($body['type'])}\n"
                    . "📝 {$e($body['title'])}\n👤 {$e($body['name'])}\n"
                    . "📞 {$e($body['phone'])}\n"
                    . "💬 {$e($body['social_type'])}: {$e($body['social_contact'])}\n";
            if (!empty($body['datetime'])) $tgText .= "📅 {$e($body['datetime'])}\n";
            if (!empty($body['comment']))  $tgText .= "📄 {$e($body['comment'])}\n";

            if (NOTIFY_VK) {
                global $vk_peer_ids;
                foreach ($vk_peer_ids as $pid) vkSendMessage((int)$pid, $vkText);
            }
            if (NOTIFY_TELEGRAM) {
                global $tg_chat_ids;
                foreach ($tg_chat_ids as $cid) tgSendMessage($cid, $tgText);
            }

            echo json_encode(['success' => true, 'order_id' => $orderId], JSON_UNESCAPED_UNICODE);
            break;

        default:
            http_response_code(405);
            echo json_encode(['error' => 'Метод не поддерживается'], JSON_UNESCAPED_UNICODE);
    }

} catch (RuntimeException $e) {
    http_response_code(400);
    echo json_encode(['error' => true, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('api.php: ' . $e->getMessage());
    echo json_encode(['error' => true, 'message' => 'Внутренняя ошибка'], JSON_UNESCAPED_UNICODE);
}