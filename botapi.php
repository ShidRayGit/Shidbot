<?php
// اطمینان از UTF-8 برای ایموجی
header('Content-Type: text/html; charset=utf-8');

/* ================== Telegram Core ================== */
function telegram($method, $datas = [])
{
    global $APIKEY;
    $url = "https://api.telegram.org/bot" . $APIKEY . "/" . $method;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $datas);
    $res = curl_exec($ch);
    if (curl_error($ch)) {
        return curl_error($ch);
    } else {
        return json_decode($res, true);
    }
}

function sendmessage($chat_id, $text, $keyboard = null, $parse_mode = "html")
{
    return telegram('sendmessage', [
        'chat_id' => $chat_id,
        'text' => $text,
        'disable_web_page_preview' => true,
        'reply_markup' => $keyboard,
        'parse_mode' => $parse_mode,
    ]);
}

function forwardMessage($chat_id, $message_id, $chat_id_user)
{
    return telegram('forwardMessage', [
        'from_chat_id' => $chat_id,
        'message_id' => $message_id,
        'chat_id' => $chat_id_user,
    ]);
}

function sendphoto($chat_id, $photoid, $caption, $parse_mode = "HTML")
{
    telegram('sendphoto', [
        'chat_id' => $chat_id,
        'photo' => $photoid,
        'caption' => $caption,
        'parse_mode' => $parse_mode,
    ]);
}

function sendvideo($chat_id, $videoid, $caption)
{
    telegram('sendvideo', [
        'chat_id' => $chat_id,
        'video' => $videoid,
        'caption' => $caption,
    ]);
}

function Editmessagetext($chat_id, $message_id, $text, $keyboard, $parse_mode = "html")
{
    return telegram('editmessagetext', [
        'chat_id' => $chat_id,
        'message_id' => $message_id,
        'text' => $text,
        'reply_markup' => $keyboard,
        'parse_mode' => $parse_mode
    ]);
}

function deletemessage($chat_id, $message_id)
{
    telegram('deletemessage', [
        'chat_id' => $chat_id,
        'message_id' => $message_id,
    ]);
}

function sendDocument($chat_id, $documentPath, $caption)
{
    return telegram('sendDocument', [
        'chat_id' => $chat_id,
        'document' => new CURLFile($documentPath),
        'caption' => $caption,
    ]);
}

/* ================== Emoji (Unicode) ================== */
$emoji_check = "\u{2705}";   // ✅
$emoji_cross = "\u{274C}";   // ❌
$emoji_smile = "\u{1F60A}";  // 😊

/* ================== CAPTCHA Helpers ================== */
function captchaStorageDir()
{
    $dir = __DIR__ . '/tmp';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    return $dir;
}
function captchaFile($user_id)
{
    return captchaStorageDir() . "/captcha_$user_id.json";
}
function generateCaptcha()
{
    $a = rand(1, 9);
    $b = rand(1, 9);
    if (rand(0, 1) === 1) {
        return ['question' => "حاصل جمع $a + $b ؟", 'answer' => $a + $b];
    } else {
        return ['question' => "حاصل ضرب $a × $b ؟", 'answer' => $a * $b];
    }
}
function setCaptchaState($user_id, $data)
{
    $payload = ['answer' => (int)$data['answer'], 'ts' => time(), 'await' => true];
    @file_put_contents(captchaFile($user_id), json_encode($payload, JSON_UNESCAPED_UNICODE));
}
function getCaptchaState($user_id)
{
    $f = captchaFile($user_id);
    if (!file_exists($f)) return null;
    $raw = @file_get_contents($f);
    $j = @json_decode($raw, true);
    return is_array($j) ? $j : null;
}
function clearCaptchaState($user_id)
{
    $f = captchaFile($user_id);
    if (file_exists($f)) @unlink($f);
}

/* ================== Update Parsing ================== */
$update = json_decode(file_get_contents("php://input"), true);
$from_id = $update['message']['from']['id'] ?? $update['callback_query']['from']['id'] ?? 0;
$Chat_type = $update["message"]["chat"]["type"] ?? $update['callback_query']['message']['chat']['type'] ?? '';
$text = $update["message"]["text"] ?? '';
$text_callback = $update["callback_query"]["message"]["text"] ?? '';
$message_id = $update["message"]["message_id"] ?? $update["callback_query"]["message"]["message_id"] ?? 0;
$photo = $update["message"]["photo"] ?? 0;
$photoid = $photo ? end($photo)["file_id"] : '';
$caption = $update["message"]["caption"] ?? '';
$video = $update["message"]["video"] ?? 0;
$videoid = $video ? $video["file_id"] : 0;
$forward_from_id = $update["message"]["reply_to_message"]["forward_from"]["id"] ?? 0;
$datain = $update["callback_query"]["data"] ?? '';
$username = $update['message']['from']['username'] ?? $update['callback_query']['from']['username'] ?? 'NOT_USERNAME';
$user_phone = $update["message"]["contact"]["phone_number"] ?? 0;
$contact_id = $update["message"]["contact"]["user_id"] ?? 0;
$first_name = $update['message']['from']['first_name'] ?? $update["callback_query"]["from"]["first_name"] ?? '';
$callback_query_id = $update["callback_query"]["id"] ?? 0;

/* ================== CAPTCHA Gate ================== */
$text_in = trim($text);
$text_lc = mb_strtolower($text_in);

if ($text_lc === '/start') {
    $cap = generateCaptcha();
    setCaptchaState($from_id, $cap);
    sendmessage(
        $from_id,
        "برای ادامه ثابت کن انسان هستی $emoji_smile\n" . $cap['question'] . "\n\nپاسخت رو فقط به صورت یک عدد بفرست.",
        null,
        'html'
    );
    exit;
}

$capState = getCaptchaState($from_id);
if ($capState && ($text_in !== '' || $caption !== '')) {
    if (preg_match('/^-?\d+$/', $text_in)) {
        if ((int)$text_in === (int)$capState['answer']) {
            clearCaptchaState($from_id);
            sendmessage($from_id, "$emoji_check تأیید شد! ادامه می‌دیم…", null, 'html');
            // اینجا می‌تونی تابع ثبت کاربر در دیتابیس رو صدا بزنی
            // require_once __DIR__.'/functions.php';
            // createUser($from_id, $username, $first_name);
        } else {
            $cap = generateCaptcha();
            setCaptchaState($from_id, $cap);
            sendmessage($from_id, "$emoji_cross نادرست بود. دوباره امتحان کن:\n" . $cap['question'], null, 'html');
            exit;
        }
    } else {
        sendmessage($from_id, "فقط عدد بفرست $emoji_smile", null, 'html');
        exit;
    }
}

/* ================== ادامه منطق اصلی ربات ================== */
// اینجا تمام شرط‌ها و کدهایی که قبلاً داشتی (منوها، پرداخت، مدیریت یوزر و ...) رو بذار