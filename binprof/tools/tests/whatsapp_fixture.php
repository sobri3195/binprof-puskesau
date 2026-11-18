<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../../private/app/Core/bootstrap.php';
require PROJECT . '/private/app/Services/WhatsApp/worker.php';

if (!in_array(parse_url(cfg('app_url'),PHP_URL_HOST),['localhost','127.0.0.1'],true)
    || waConfig('phone_number_id') !== '1111111111' || waConfig('access_token')) {
    fwrite(STDERR,"Fixture requires the synthetic loopback config and no access token.\n");
    exit(2);
}

switch ($argv[1] ?? '') {
    case 'run':
        $messages = [];
        $mode = $argv[2] ?? 'accept';
        $transport = $mode === 'queue' ? null : static function (array $message) use (&$messages,$mode): array {
            $messages[] = $message;
            return match ($mode) {
                'throttle' => waClassifyMeta(429,'{"error":{"code":130429}}',75),
                'uncertain' => waClassifyMeta(502,'gateway error'),
                'reject' => waClassifyMeta(403,'{"error":{"code":200}}'),
                default => waClassifyMeta(200,json_encode(['messages'=>[['id'=>'wamid.demo.' . $message['biz_opaque_callback_data']]]],
                    JSON_THROW_ON_ERROR)),
            };
        };
        if (!waRunWorker($transport)) {
            throw new RuntimeException('Fixture worker did not run');
        }
        $output = $messages;
        break;
    case 'lookup':
        $output = qu("SELECT o.id,o.provider_id,o.state,o.attempts,o.next_attempt_at,o.error_kind
            FROM whatsapp_inbox i JOIN whatsapp_outbox o ON o.dedupe_key=CONCAT('reply:',i.id)
            WHERE i.provider_id=?",[$argv[2]])->fetch();
        break;
    case 'map':
        $output = qu("SELECT id,state,error_kind FROM whatsapp_outbox WHERE kind='map_event' AND event_id=? ORDER BY id",
            [key32(['id'=>$argv[2]])])->fetchAll();
        break;
    case 'cooldown':
        qu('UPDATE whatsapp_inbox SET processed_at=UTC_TIMESTAMP()-INTERVAL 6 MINUTE WHERE processed_at IS NOT NULL');
        $output = true;
        break;
    case 'due':
        qu("UPDATE whatsapp_outbox SET next_attempt_at=UTC_TIMESTAMP() WHERE id=? AND state='queued'",
            [key32(['id'=>$argv[2]])]);
        $output = true;
        break;
    default:
        throw new InvalidArgumentException('Invalid fixture command');
}
echo json_encode($output,JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n";
