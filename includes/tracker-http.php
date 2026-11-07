<?php
declare(strict_types=1);

function tracker_policy(): array
{
    static $policy;
    return $policy ??= json_decode(file_get_contents(__DIR__ . '/../config/tracker-policy.json'), true, 512, JSON_THROW_ON_ERROR);
}

function tracker_host(string $url): string
{
    if (strlen($url) > 4096 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
        throw new InvalidArgumentException('Geçersiz takip URL’si.');
    }
    $parts = parse_url($url);
    $host = strtolower($parts['host'] ?? '');
    if (!$parts || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])
        || (isset($parts['port']) && $parts['port'] !== 443)
        || !in_array($host, tracker_policy()['page_hosts'], true)) {
        throw new InvalidArgumentException('Yalnızca desteklenen pazaryerlerinin doğrudan HTTPS adresleri kullanılabilir.');
    }
    return $host;
}

function tracker_public_ipv4(string $ip): bool
{
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return false;
    $value = ip2long($ip);
    foreach (tracker_policy()['blocked_ipv4_cidrs'] as $cidr) {
        [$network, $bits] = explode('/', $cidr);
        $mask = -1 << (32 - (int)$bits);
        if (($value & $mask) === (ip2long($network) & $mask)) return false;
    }
    return true;
}

function tracker_resolve(string $host): string
{
    $records = dns_get_record($host, DNS_A);
    $ips = array_values(array_unique(array_column($records ?: [], 'ip')));
    if (!$ips) throw new RuntimeException('Pazaryeri adresi çözümlenemedi.');
    foreach ($ips as $ip) {
        if (!tracker_public_ipv4($ip)) throw new RuntimeException('Takip adresi güvenli bir genel IP adresine çözümlenmiyor.');
    }
    return $ips[0];
}

function tracker_fetch_html(string $url): array
{
    try {
        $host = tracker_host($url);
        $ip = tracker_resolve($host);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
    $ch = curl_init($url);
    $html = '';
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        // Host/SNI korunur; hedef IP hem doğrudan hem proxy tünelinde sabitlenir.
        CURLOPT_RESOLVE => ["$host:443:$ip"],
        CURLOPT_CONNECT_TO => ["$host:443:$ip:443"],
        CURLOPT_HTTPHEADER => ['User-Agent: Mozilla/5.0', 'Accept-Language: tr-TR,tr;q=0.9'],
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$html): int {
            if (strlen($html) + strlen($chunk) > 2 * 1024 * 1024) return 0;
            $html .= $chunk;
            return strlen($chunk);
        },
    ]);
    $ok = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($ok === false) return ['ok' => false, 'error' => 'Sayfa alınamadı veya izin verilen boyutu aştı.'];
    if ($http >= 300 && $http < 400) return ['ok' => false, 'error' => 'Yönlendirme takip edilmez. Tarayıcıdaki son pazaryeri adresini girin.'];
    if ($http !== 200) return ['ok' => false, 'error' => "HTTP $http — sayfa erişilemedi."];
    return ['ok' => true, 'html' => $html];
}

// Eski kayıtlardaki geçersiz URL’ler de tıklanabilir bağlantıya dönüşmesin.
function tracker_link(string $url): string
{
    try { tracker_host($url); return $url; }
    catch (InvalidArgumentException $e) { return '#'; }
}
