<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';

function whatsappWebhook(string $method): never
{
    if (!waWebhookReady()) {
        fail(503, 'Webhook WhatsApp belum dikonfigurasi.');
    }
    if ($method === 'GET') {
        $token = $_GET['hub_verify_token'] ?? null;
        $challenge = $_GET['hub_challenge'] ?? null;
        if (($_GET['hub_mode'] ?? null) !== 'subscribe' || !is_string($token)
            || !hash_equals((string) waConfig('verify_token'),$token)
            || !is_string($challenge) || !preg_match('/^\d{1,64}$/D',$challenge)) {
            fail(403,'Verifikasi webhook ditolak.');
        }
        header('Content-Type: text/plain; charset=UTF-8');
        echo $challenge;
        exit;
    }
    if ($method !== 'POST') {
        header('Allow: GET, POST');
        fail(405,'Metode webhook tidak tersedia.');
    }
    $body = file_get_contents('php://input', false, null, 0, 262145);
    $signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
    if ($body === false || strlen($body) > 262144) {
        fail(413,'Payload webhook terlalu besar.');
    }
    if (!preg_match('/^sha256=[a-f0-9]{64}$/D',$signature)
        || !hash_equals('sha256=' . hash_hmac('sha256',$body,(string) waConfig('app_secret')),$signature)) {
        fail(403,'Tanda tangan webhook ditolak.');
    }
    if (!waMigrated()) {
        fail(503,'Migrasi WhatsApp belum diterapkan.');
    }
    try {
        $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        fail(400,'Payload webhook tidak valid.');
    }
    if (!is_array($data) || ($data['object'] ?? null) !== 'whatsapp_business_account') {
        fail(400,'Payload webhook tidak valid.');
    }
    tx(static function () use ($data): void {
        foreach ((array) ($data['entry'] ?? []) as $entry) {
            if (!is_array($entry) || ($entry['id'] ?? null) !== (string) waConfig('business_account_id')) {
                continue;
            }
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                if (!is_array($change) || ($change['field'] ?? null) !== 'messages') {
                    continue;
                }
                $value = $change['value'] ?? [];
                if (!is_array($value) || ($value['metadata']['phone_number_id'] ?? null) !== (string) waConfig('phone_number_id')) {
                    continue;
                }
                foreach ((array) ($value['messages'] ?? []) as $message) {
                    if (is_array($message)) {
                        waIngestMessage($message);
                    }
                }
                foreach ((array) ($value['statuses'] ?? []) as $status) {
                    if (is_array($status)) {
                        waIngestReceipt($status);
                    }
                }
            }
        }
    });
    jsonResponse(['accepted'=>true]);
}

function waProviderId(mixed $value): ?string
{
    return is_string($value) && preg_match('/^[a-zA-Z0-9._+=\/-]{6,191}$/D',$value) ? $value : null;
}

function waTimestamp(mixed $value): ?string
{
    if (is_int($value)) {
        $value = (string) $value;
    }
    return is_string($value) && preg_match('/^\d{10}$/D',$value) && (int) $value <= time()+300
        ? waDate($value) : null;
}

function waIngestMessage(array $message): void
{
    $providerId = waProviderId($message['id'] ?? null);
    $sender = waSender($message);
    $date = waTimestamp($message['timestamp'] ?? null);
    if (!$providerId || !$sender || !$date) {
        return;
    }
    $text = ($message['type'] ?? null) === 'text' ? ($message['text']['body'] ?? '') : '';
    $text = is_string($text) ? mb_substr($text,0,500,'UTF-8') : '';
    $id = bin2hex(random_bytes(16));
    qu('INSERT INTO whatsapp_inbox (id,provider_id,sender_hash,payload_ciphertext,occurred_at) VALUES (?,?,?,?,?)
        ON DUPLICATE KEY UPDATE provider_id=VALUES(provider_id)',
        [$id,$providerId,waSenderHash($sender),waSeal(['sender'=>$sender,'text'=>$text],"inbox:$id"),$date]);
}

function waIngestReceipt(array $status): void
{
    $providerId = waProviderId($status['id'] ?? null);
    $date = waTimestamp($status['timestamp'] ?? null);
    $state = $status['status'] ?? null;
    if (!$providerId || !$date || !in_array($state,['sent','delivered','read','failed'],true)) {
        return;
    }
    $callback = $status['biz_opaque_callback_data'] ?? null;
    $callback = is_string($callback) && preg_match('/^[a-f0-9]{32}$/D',$callback) ? $callback : null;
    $fingerprint = hash('sha256',$providerId . ':' . $state . ':' . $date . ':' . $callback,true);
    qu('INSERT INTO whatsapp_receipts (fingerprint,provider_id,callback_id,status,occurred_at) VALUES (?,?,?,?,?)
        ON DUPLICATE KEY UPDATE fingerprint=VALUES(fingerprint)',[$fingerprint,$providerId,$callback,$state,$date]);
}
