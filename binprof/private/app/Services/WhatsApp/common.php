<?php
declare(strict_types=1);

const WA_SETTINGS = '/app/whatsapp';
const WA_WEBHOOK = '/webhooks/whatsapp';

function waMigrated(): bool
{
    return (bool) qu("SELECT 1 FROM schema_migrations WHERE version='004_whatsapp_cloud'")->fetchColumn();
}

function waConfig(string $key): mixed
{
    $config = cfg('whatsapp') ?? [];
    return $config[$key] ?? null;
}

function waWebhookReady(): bool
{
    return waConfig('enabled') === true
        && waConfig('app_secret') && waConfig('verify_token')
        && preg_match('/^\d{5,32}$/D', (string) waConfig('phone_number_id'))
        && preg_match('/^\d{5,32}$/D', (string) waConfig('business_account_id'));
}

function waTransportReady(): bool
{
    return waWebhookReady() && (bool) waConfig('access_token')
        && preg_match('/^v\d{1,3}\.\d{1,2}$/D', (string) waConfig('graph_version')) && extension_loaded('curl');
}

function waHash(string $domain, string $value): string
{
    return hash_hmac('sha256', 'whatsapp:' . $domain . ':' . $value, secretBytes(cfg('rate_pepper')), true);
}

function waSeal(array $value, string $context): string
{
    return seal(json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), "wa:$context");
}

function waOpen(string $value, string $context): array
{
    $data = json_decode(unseal($value, "wa:$context"), true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid WhatsApp payload');
    }
    return $data;
}

function waDate(string $value): string
{
    return gmdate('Y-m-d H:i:s', (int) $value);
}

function waSender(array $message): ?array
{
    $bsuid = $message['from_user_id'] ?? null;
    $phone = $message['from'] ?? null;
    if (is_string($bsuid) && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{3,95}$/D', $bsuid)) {
        return ['type'=>'bsuid','recipient'=>$bsuid,'hint'=>'ID ••••' . substr($bsuid,-4)];
    }
    if ($bsuid !== null && $bsuid !== '') {
        // An invalid BSUID cannot make an identity fall back to a telephone account.
        return null;
    }
    if (is_string($phone) && preg_match('/^[1-9][0-9]{7,14}$/D', $phone)) {
        return ['type'=>'phone','recipient'=>$phone,'hint'=>'+••••' . substr($phone,-4)];
    }
    return null;
}

function waChannel(): string
{
    return waConfig('business_account_id') . ':' . waConfig('phone_number_id');
}

function waSenderHash(array $identity): string
{
    return waHash('sender', waChannel() . ':' . $identity['type'] . ':' . $identity['recipient']);
}

function waLinkForUser(int $id): ?array
{
    $link = qu('SELECT * FROM whatsapp_links WHERE user_id=? AND active_slot=1',[$id])->fetch();
    return $link ?: null;
}

function waActor(array $link): ?array
{
    if ($link['channel_ref'] !== waChannel()) {
        return null;
    }
    $actor = qu('SELECT id,role_code,session_version FROM users WHERE id=? AND active=1 AND session_version=?',
        [(int) $link['user_id'], (int) $link['auth_version']])->fetch();
    if (!$actor || !retirementAccountEligible((int) $actor['id'],$actor['role_code'])) {
        return null;
    }
    $actor = withPermissions($actor);
    return can($actor,'whatsapp.link') ? $actor : null;
}

function waPortal(string $path, array $query = []): string
{
    return (string) cfg('app_url') . url($path, $query);
}

function waConfiguredBotLink(): ?string
{
    $number = waConfig('display_phone');
    return is_string($number) && preg_match('/^[1-9][0-9]{7,14}$/D',$number) ? 'https://wa.me/' . $number : null;
}
