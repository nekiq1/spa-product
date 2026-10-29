<?php
function env($k, $d = null) { $v = getenv($k); return ($v === false || $v === '') ? $d : $v; }
function envBool($k, $d = false) { $v = getenv($k); if ($v === false) return $d; return in_array(strtolower(trim($v)), ['1','true','yes','on'], true); }
function envArr($k) { $v = getenv($k); if ($v === false || trim($v) === '') return []; return array_values(array_filter(array_map('trim', explode(',', $v)))); }

define('PB_URL', rtrim(env('PB_URL', ''), '/'));
define('PB_ADMIN_EMAIL', env('PB_ADMIN_EMAIL', ''));
define('PB_ADMIN_PASSWORD', env('PB_ADMIN_PASSWORD', ''));
define('PB_ADMIN_TOKEN', env('PB_ADMIN_TOKEN', ''));
define('PB_COLLECTION_SITE', env('PB_COLLECTION_SITE', 'site_data'));
define('PB_COLLECTION_ORDERS', env('PB_COLLECTION_ORDERS', 'orders'));
define('PB_COLLECTION_SUBS', env('PB_COLLECTION_SUBS', 'subscribers'));
define('PB_COLLECTION_UPLOADS', env('PB_COLLECTION_UPLOADS', 'uploads'));
define('ADMIN_SECRET', env('ADMIN_SECRET', ''));
define('ADMIN_LOGIN', env('ADMIN_LOGIN', 'admin'));
define('ADMIN_PASSWORD', env('ADMIN_PASSWORD', ''));
define('VK_ACCESS_TOKEN', env('VK_ACCESS_TOKEN', ''));
define('VK_API_VERSION', env('VK_API_VERSION', '5.131'));
$vk_peer_ids = array_map('intval', envArr('VK_PEER_IDS'));
define('TG_BOT_TOKEN', env('TG_BOT_TOKEN', ''));
$tg_chat_ids = envArr('TG_CHAT_IDS');
define('NOTIFY_VK', envBool('NOTIFY_VK', false));
define('NOTIFY_TELEGRAM', envBool('NOTIFY_TELEGRAM', false));
define('TOKEN_CACHE', sys_get_temp_dir().'/pb_token_cache.json');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . env('ALLOWED_ORIGIN', '*'));
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Admin-Token');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

function getAdminToken(bool $refresh = false): string {
    if (!$refresh && file_exists(TOKEN_CACHE)) {
        $c = json_decode(@file_get_contents(TOKEN_CACHE), true);
        if ($c && !empty($c['token']) && ($c['exp'] ?? 0) > time() + 60) return $c['token'];
    }
    if (PB_ADMIN_EMAIL && PB_ADMIN_PASSWORD) {
        $ch = curl_init(PB_URL.'/api/collections/_superusers/auth-with-password');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>json_encode(['identity'=>PB_ADMIN_EMAIL,'password'=>PB_ADMIN_PASSWORD]),
            CURLOPT_HTTPHEADER=>['Content-Type: application/json'], CURLOPT_TIMEOUT=>10,
        ]);
        $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
        if ($resp === false) throw new RuntimeException("PB auth cURL: $err");
        $d = json_decode($resp, true);
        if ($code >= 400 || empty($d['token'])) throw new RuntimeException("PB auth $code: ".($d['message'] ?? substr($resp,0,200)));
        $exp = time() + 3600;
        $parts = explode('.', $d['token']);
        if (count($parts) === 3) { $p = json_decode(base64_decode(strtr($parts[1],'-_','+/')), true); if (!empty($p['exp'])) $exp = (int)$p['exp']; }
        @file_put_contents(TOKEN_CACHE, json_encode(['token'=>$d['token'],'exp'=>$exp]));
        return $d['token'];
    }
    if (PB_ADMIN_TOKEN === '') throw new RuntimeException('Нет PB_ADMIN_EMAIL/PASSWORD и PB_ADMIN_TOKEN');
    return PB_ADMIN_TOKEN;
}

function pbRequest(string $method, string $path, ?array $body = null, bool $retry = true): array {
    if (PB_URL === '') throw new RuntimeException('PB_URL не задан');
    $token = getAdminToken();
    $ch = curl_init(PB_URL.$path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER=>true, CURLOPT_CUSTOMREQUEST=>$method,
        CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token, 'Content-Type: application/json'],
        CURLOPT_TIMEOUT=>15,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    if ($resp === false) throw new RuntimeException("PB cURL: $err");
    $d = json_decode($resp, true);
    if (!is_array($d)) throw new RuntimeException("PB invalid JSON ($code): ".substr($resp, 0, 300));
    if ($code === 401 && $retry) { @unlink(TOKEN_CACHE); return pbRequest($method, $path, $body, false); }
    if ($code >= 400) throw new RuntimeException("PB $code: ".($d['message'] ?? substr($resp,0,200)));
    return $d;
}

function pbUpload(array $file): array {
    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) throw new RuntimeException('Файл не загружен');
    if ($file['size'] > 10*1024*1024) throw new RuntimeException('Файл больше 10 MB');
    $token = getAdminToken();
    $ch = curl_init(PB_URL.'/api/collections/'.PB_COLLECTION_UPLOADS.'/records');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>['file'=>new CURLFile($file['tmp_name'], $file['type'], $file['name'])],
        CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token], CURLOPT_TIMEOUT=>30,
    ]);
    $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    if ($resp === false) throw new RuntimeException("upload cURL: $err");
    $d = json_decode($resp, true);
    if ($code >= 400 || empty($d['id'])) throw new RuntimeException("upload $code: ".($d['message'] ?? substr($resp,0,200)));
    $fname = is_array($d['file'] ?? null) ? ($d['file'][0] ?? '') : ($d['file'] ?? '');
    return ['url' => PB_URL.'/api/files/'.PB_COLLECTION_UPLOADS.'/'.$d['id'].'/'.$fname, 'id' => $d['id'], 'filename' => $fname];
}

function readJson(): array {
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) throw new RuntimeException('Пустое тело');
    $d = json_decode($raw, true);
    if (!is_array($d)) throw new RuntimeException('Невалидный JSON');
    return $d;
}
function vkSend(int $pid, string $msg): bool {
    if (VK_ACCESS_TOKEN === '' || $pid === 0) return false;
    $ch = curl_init('https://api.vk.com/method/messages.send');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>http_build_query(['access_token'=>VK_ACCESS_TOKEN,'v'=>VK_API_VERSION,'peer_id'=>$pid,'message'=>$msg,'random_id'=>random_int(1,PHP_INT_MAX)]),
        CURLOPT_TIMEOUT=>10]);
    $r = curl_exec($ch); curl_close($ch); return $r !== false;
}
function tgSend($cid, string $msg): bool {
    if (TG_BOT_TOKEN === '') return false;
    $base = env('TG_API_BASE', '') ?: 'https://api.telegram.org/bot';
    $ch = curl_init($base.TG_BOT_TOKEN.'/sendMessage');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>http_build_query(['chat_id'=>$cid,'text'=>$msg,'parse_mode'=>'HTML','disable_web_page_preview'=>'true']),
        CURLOPT_TIMEOUT=>10]);
    $r = curl_exec($ch); curl_close($ch); return $r !== false;
}
function checkAdmin() {
    $t = $_SERVER['HTTP_X_ADMIN_TOKEN'] ?? ($_GET['token'] ?? '');
    if (ADMIN_SECRET === '' || !hash_equals(ADMIN_SECRET, $t)) {
        http_response_code(403); echo json_encode(['error'=>'Доступ запрещён'], JSON_UNESCAPED_UNICODE); exit;
    }
}

if (isset($_GET['debug'])) {
    $out = ['pb_url'=>PB_URL, 'admin_secret_set'=>ADMIN_SECRET!=='', 'admin_login'=>ADMIN_LOGIN,
            'admin_password_set'=>ADMIN_PASSWORD!=='', 'pb_email_set'=>PB_ADMIN_EMAIL!=='',
            'pb_password_set'=>PB_ADMIN_PASSWORD!=='', 'pb_token_set'=>PB_ADMIN_TOKEN!=='',
            'health'=>null, 'auth'=>null, 'site_get'=>null, 'uploads_ok'=>null];
    if (PB_URL) {
        $ch = curl_init(PB_URL.'/api/health'); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>5]);
        $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        $out['health'] = ['code'=>$code, 'body'=>$r];
    }
    try { $t = getAdminToken(); $out['auth'] = ['ok'=>true, 'token_len'=>strlen($t)]; }
    catch (Throwable $e) { $out['auth'] = ['ok'=>false, 'error'=>$e->getMessage()]; }
    try { $r = pbRequest('GET','/api/collections/'.PB_COLLECTION_SITE.'/records?perPage=1'); $out['site_get']=['ok'=>true,'items'=>count($r['items']??[])]; }
    catch (Throwable $e) { $out['site_get']=['ok'=>false,'error'=>$e->getMessage()]; }
    try { $r = pbRequest('GET','/api/collections/'.PB_COLLECTION_UPLOADS.'/records?perPage=1'); $out['uploads_ok']=['ok'=>true]; }
    catch (Throwable $e) { $out['uploads_ok']=['ok'=>false,'error'=>$e->getMessage()]; }
    echo json_encode($out, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT); exit;
}

try {
    $method = $_SERVER['REQUEST_METHOD'];
    switch ($method) {

        case 'GET':
            $resource = $_GET['resource'] ?? '';
            if ($resource === 'orders' || $resource === 'subscribers') {
                checkAdmin();
                $coll = $resource === 'orders' ? PB_COLLECTION_ORDERS : PB_COLLECTION_SUBS;
                $r = pbRequest('GET', '/api/collections/'.$coll.'/records?perPage=200&sort=-created');
                echo json_encode(['items'=>$r['items'] ?? []], JSON_UNESCAPED_UNICODE);
                break;
            }
            $content = [];
            try {
                $r = pbRequest('GET', '/api/collections/'.PB_COLLECTION_SITE.'/records?perPage=1');
                if (!empty($r['items'])) $content = json_decode($r['items'][0]['content'] ?? '{}', true) ?: [];
            } catch (Throwable $e) { error_log('PB GET: '.$e->getMessage()); }
            echo json_encode(['content'=>$content ?: new stdClass()], JSON_UNESCAPED_UNICODE);
            break;

        case 'PUT':
            checkAdmin();
            $body = readJson();
            if (!isset($body['content'])) throw new RuntimeException('Нет поля content');
            $list = pbRequest('GET','/api/collections/'.PB_COLLECTION_SITE.'/records?perPage=1');
            $json = json_encode($body['content'], JSON_UNESCAPED_UNICODE);
            if (empty($list['items'])) pbRequest('POST','/api/collections/'.PB_COLLECTION_SITE.'/records', ['content'=>$json]);
            else pbRequest('PATCH','/api/collections/'.PB_COLLECTION_SITE.'/records/'.$list['items'][0]['id'], ['content'=>$json]);
            echo json_encode(['success'=>true], JSON_UNESCAPED_UNICODE);
            break;

        case 'POST':
            if (!empty($_FILES['file'])) {
                checkAdmin();
                echo json_encode(pbUpload($_FILES['file']), JSON_UNESCAPED_UNICODE);
                break;
            }
            $body = readJson();
            $action = $body['action'] ?? 'order';

            if ($action === 'login') {
                if (($body['login'] ?? '') === ADMIN_LOGIN && ($body['password'] ?? '') === ADMIN_PASSWORD && ADMIN_SECRET !== '') {
                    echo json_encode(['success'=>true,'token'=>ADMIN_SECRET], JSON_UNESCAPED_UNICODE);
                } else {
                    http_response_code(401); echo json_encode(['error'=>true,'message'=>'Неверный логин или пароль'], JSON_UNESCAPED_UNICODE);
                }
                break;
            }
            if ($action === 'subscribe') {
                $email = trim($body['email'] ?? '');
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Неверный email');
                pbRequest('POST','/api/collections/'.PB_COLLECTION_SUBS.'/records', ['email'=>$email]);
                echo json_encode(['success'=>true], JSON_UNESCAPED_UNICODE);
                break;
            }
            foreach (['type','title','name','phone','social_type','social_contact'] as $f) {
                if (empty($body[$f])) throw new RuntimeException('Поле "'.$f.'" обязательно');
            }
            $orderId = 'ORD-'.strtoupper(bin2hex(random_bytes(4))).'-'.time();
            pbRequest('POST','/api/collections/'.PB_COLLECTION_ORDERS.'/records', [
                'order_id'=>$orderId,'type'=>$body['type'],'title'=>$body['title'],'name'=>$body['name'],
                'phone'=>$body['phone'],'social_type'=>$body['social_type'],'social_contact'=>$body['social_contact'],
                'datetime'=>$body['datetime'] ?? '','comment'=>$body['comment'] ?? '',
            ]);
            if (NOTIFY_VK) {
                $t = "Новая заявка!\nID: {$orderId}\nТип: {$body['type']}\nНазвание: {$body['title']}\nИмя: {$body['name']}\nТел: {$body['phone']}\n{$body['social_type']}: {$body['social_contact']}\n";
                foreach ($vk_peer_ids as $pid) vkSend($pid, $t);
            }
            if (NOTIFY_TELEGRAM) {
                $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES|ENT_HTML5, 'UTF-8');
                $t = "<b>Новая заявка!</b>\nID: <code>{$e($orderId)}</code>\nТип: {$e($body['type'])}\n{$e($body['title'])}\n{$e($body['name'])}\n{$e($body['phone'])}\n{$e($body['social_type'])}: {$e($body['social_contact'])}";
                foreach ($tg_chat_ids as $cid) tgSend($cid, $t);
            }
            echo json_encode(['success'=>true,'order_id'=>$orderId], JSON_UNESCAPED_UNICODE);
            break;

        default:
            http_response_code(405); echo json_encode(['error'=>'Метод не поддерживается'], JSON_UNESCAPED_UNICODE);
    }
} catch (Throwable $e) {
    http_response_code(500); error_log('api.php: '.$e->getMessage());
    echo json_encode(['error'=>true, 'message'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}