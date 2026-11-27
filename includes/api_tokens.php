<?php
declare(strict_types=1);
/** Chrome eklentisi gibi oturumsuz istemciler için kişisel erişim anahtarları. */

const API_TOKEN_PREFIX = 'kf_';

function api_token_create(int $userId, string $label = 'Chrome eklentisi'): string
{
    $token = API_TOKEN_PREFIX . bin2hex(random_bytes(24));
    db()->prepare("INSERT INTO api_tokens (user_id, token_hash, label, created_at) VALUES (?,?,?,?)")
        ->execute([$userId, hash('sha256', $token), mb_substr($label, 0, 60), time()]);
    return $token;
}

/** Anahtara ait kullanıcı; geçersizse null. */
function api_token_user(string $token): ?array
{
    $token = trim($token);
    if (!str_starts_with($token, API_TOKEN_PREFIX) || strlen($token) !== strlen(API_TOKEN_PREFIX) + 48) return null;
    $st = db()->prepare("SELECT u.*, t.id AS token_id FROM api_tokens t JOIN users u ON u.id = t.user_id WHERE t.token_hash = ?");
    $st->execute([hash('sha256', $token)]);
    $u = $st->fetch();
    if (!$u) return null;
    db()->prepare("UPDATE api_tokens SET last_used_at = ? WHERE id = ?")->execute([time(), $u['token_id']]);
    return $u;
}

function api_tokens_list(int $userId): array
{
    $st = db()->prepare("SELECT id, label, created_at, last_used_at FROM api_tokens WHERE user_id = ? ORDER BY id DESC");
    $st->execute([$userId]);
    return $st->fetchAll();
}

function api_token_delete(int $userId, int $id): void
{
    db()->prepare("DELETE FROM api_tokens WHERE user_id = ? AND id = ?")->execute([$userId, $id]);
}
