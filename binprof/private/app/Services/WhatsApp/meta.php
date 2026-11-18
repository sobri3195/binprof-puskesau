<?php
declare(strict_types=1);

require_once __DIR__ . '/webhook.php';

function waTemplateReady(): bool
{
    return preg_match('/^[a-z][a-z0-9_]{1,127}$/D',(string) waConfig('map_template')) === 1
        && preg_match('/^[a-z]{2}(?:_[A-Z]{2})?$/D',(string) waConfig('template_language')) === 1;
}

function waMetaSend(array $message): array
{
    if (!waTransportReady()) {
        return ['state'=>'retry','error'=>'configuration_unavailable'];
    }
    $endpoint = 'https://graph.facebook.com/' . waConfig('graph_version') . '/' . waConfig('phone_number_id') . '/messages';
    $retryAfter = 0;
    $curl = curl_init($endpoint);
    curl_setopt_array($curl, [
        CURLOPT_POST=>true,
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_CONNECTTIMEOUT=>4,
        CURLOPT_TIMEOUT=>8,
        CURLOPT_SSL_VERIFYPEER=>true,
        CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_HTTPHEADER=>['Authorization: Bearer ' . waConfig('access_token'),'Content-Type: application/json'],
        CURLOPT_POSTFIELDS=>json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        CURLOPT_HEADERFUNCTION=>static function ($handle,string $line) use (&$retryAfter): int {
            if (preg_match('/^retry-after:\s*(\d{1,5})\s*$/iD',trim($line),$m)) {
                $retryAfter = min(1800,(int) $m[1]);
            }
            return strlen($line);
        },
    ]);
    $body = curl_exec($curl);
    $error = curl_errno($curl);
    $status = (int) curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    if ($body === false) {
        $retryable = in_array($error,[CURLE_COULDNT_RESOLVE_HOST,CURLE_COULDNT_RESOLVE_PROXY,CURLE_COULDNT_CONNECT],true);
        return ['state'=>$retryable ? 'retry' : 'unknown','error'=>$retryable ? 'connect_failure' : 'transport_uncertain'];
    }
    return waClassifyMeta($status,$body,$retryAfter);
}

function waClassifyMeta(int $status, string $body, int $retryAfter = 0): array
{
    $json = json_decode($body,true,32);
    if ($status >= 200 && $status < 300) {
        $id = waProviderId($json['messages'][0]['id'] ?? null);
        return $id ? ['state'=>'accepted','provider_id'=>$id] : ['state'=>'unknown','error'=>'missing_provider_id'];
    }
    $error = $json['error'] ?? [];
    $code = is_int($error['code'] ?? null) ? $error['code'] : null;
    // A provider throttling rejection is retryable. Gateway failures can occur after acceptance.
    if ($status === 429 || ($status >= 400 && $status < 500
        && in_array($code,[4,17,32,613,130429,131048,131056],true))) {
        return ['state'=>'retry','error'=>'provider_throttle','after'=>max(0,min(1800,$retryAfter))];
    }
    if ($status >= 400 && $status < 500) {
        return ['state'=>'failed','error'=>'provider_http_' . $status];
    }
    return ['state'=>'unknown','error'=>'provider_response_uncertain'];
}
