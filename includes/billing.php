<?php
declare(strict_types=1);

// Çağıran işlem yazma kilidini almış olmalıdır.
function extend_pro(PDO $pdo, int $userId, float $amount): string
{
    $q = $pdo->prepare('SELECT plan_expires, referred_by FROM users WHERE id = ?');
    $q->execute([$userId]);
    $user = $q->fetch();
    if (!$user) throw new RuntimeException('Kullanıcı bulunamadı.');
    $base = max($user['plan_expires'] ?: date('Y-m-d'), date('Y-m-d'));
    $date = new DateTimeImmutable($base);
    $next = $date->modify('first day of next month');
    $expires = $next->setDate((int)$next->format('Y'), (int)$next->format('m'),
        min((int)$date->format('d'), (int)$next->format('t')))->format('Y-m-d');
    $pdo->prepare("UPDATE users SET plan = 'pro', plan_expires = ? WHERE id = ?")
        ->execute([$expires, $userId]);
    if (!empty($user['referred_by'])) {
        $pdo->prepare("INSERT INTO partner_commissions (partner_id, referred_user_id, amount, month, status) VALUES (?, ?, ?, ?, 'pending')")
            ->execute([(int)$user['referred_by'], $userId, round($amount * 0.15, 2), date('Y-m')]);
    }
    return $expires;
}

function approve_bank_payment(int $id): string
{
    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $q = $pdo->prepare('SELECT * FROM payments WHERE id = ?');
        $q->execute([$id]);
        $payment = $q->fetch();
        if (!$payment || $payment['status'] !== 'pending') {
            $pdo->exec('COMMIT');
            return '';
        }
        $expires = extend_pro($pdo, (int)$payment['user_id'], (float)$payment['amount']);
        $pdo->prepare("UPDATE payments SET status = 'approved', reviewed_at = datetime('now') WHERE id = ?")
            ->execute([$id]);
        $pdo->exec('COMMIT');
        return $expires;
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
}

function complete_checkout(string $token, array $result): bool
{
    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $q = $pdo->prepare('SELECT * FROM checkout_sessions WHERE token = ?');
        $q->execute([$token]);
        $checkout = $q->fetch();
        if (!$checkout) throw new RuntimeException('Ödeme kaydı bulunamadı.');
        if ($checkout['status'] === 'approved') {
            $pdo->exec('COMMIT');
            return false;
        }
        if (($result['status'] ?? '') !== 'success'
            || ($result['paymentStatus'] ?? '') !== 'SUCCESS'
            || (string)($result['fraudStatus'] ?? '') !== '1'
            || ($result['basketId'] ?? '') !== $checkout['basket_id']
            || ($result['currency'] ?? '') !== $checkout['currency']
            || !is_numeric($result['price'] ?? null)
            || !is_numeric($result['paidPrice'] ?? null)
            || number_format((float)$result['price'], 2, '.', '') !== $checkout['amount']
            || number_format((float)$result['paidPrice'], 2, '.', '') !== $checkout['amount']
            || empty($result['paymentId'])
            || (isset($result['token']) && $result['token'] !== $token)) {
            throw new RuntimeException('Ödeme bilgileri doğrulanamadı.');
        }
        $paymentId = (string)$result['paymentId'];
        $pdo->prepare("UPDATE checkout_sessions SET status = 'approved', payment_id = ? WHERE token = ?")
            ->execute([$paymentId, $token]);
        extend_pro($pdo, (int)$checkout['user_id'], (float)$checkout['amount']);
        $pdo->prepare("INSERT INTO payments (user_id, amount, reference, status, reviewed_at) VALUES (?, ?, ?, 'approved', datetime('now'))")
            ->execute([$checkout['user_id'], $checkout['amount'], 'IYZ-' . $paymentId]);
        $pdo->exec('COMMIT');
        return true;
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
}
