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

function application_base_url(string $raw): string
{
    $url=rtrim(trim($raw),'/');$p=parse_url($url);
    if(!$p||empty($p['host'])||isset($p['user'])||isset($p['pass'])||isset($p['query'])||isset($p['fragment'])||!filter_var($url,FILTER_VALIDATE_URL))throw new RuntimeException('Geçerli sabit uygulama adresi tanımlayın.');
    $local=in_array(strtolower($p['host']),['localhost','127.0.0.1','[::1]'],true);
    if(($p['scheme']??'')!=='https'&&!(($p['scheme']??'')==='http'&&$local))throw new RuntimeException('Canlı uygulama adresi HTTPS olmalı.');
    return $url;
}

function checkout_provider_context(string $key,string $secret,bool $sandbox): string
{
    if($key===''||$secret==='')throw new RuntimeException('Ödeme sağlayıcısı bilgileri eksik.');
    return encrypt_string(json_encode(['api_key'=>$key,'secret'=>$secret,'sandbox'=>$sandbox],JSON_THROW_ON_ERROR));
}

function checkout_event(string $token,string $code,string $status): void
{
    if(!in_array($code,['retrieval_error','provider_failure','fraud_review','verification_failed','verified','context_missing'],true)||!in_array($status,['pending','failed','review','approved'],true))throw new InvalidArgumentException('Geçersiz ödeme olayı.');
    $pdo=db();$pdo->exec('BEGIN IMMEDIATE');
    try {
        $pdo->prepare("UPDATE checkout_sessions SET checked_at=?,attempts=attempts+1,error_code=?,status=CASE WHEN status='approved' THEN status ELSE ? END WHERE token=?")->execute([time(),$code==='verified'?'':$code,$status,$token]);
        $pdo->prepare('INSERT INTO checkout_events(token,code,created_at) VALUES(?,?,?)')->execute([$token,$code,time()]);
        $pdo->exec('COMMIT');
    }catch(Throwable $e){$pdo->exec('ROLLBACK');throw $e;}
}

/** Uses the environment and encrypted credentials captured at checkout creation. Never charges a card. */
function verify_checkout(string $token,?callable $factory=null): array
{
    $q=db()->prepare('SELECT * FROM checkout_sessions WHERE token=?');$q->execute([$token]);$checkout=$q->fetch();$q->closeCursor();
    if(!$checkout)return ['ok'=>false,'code'=>'not_found'];
    if($checkout['status']==='approved')return ['ok'=>true,'already_approved'=>true];
    {
        try {
            $context=json_decode(decrypt_string($checkout['provider_context']??''),true,512,JSON_THROW_ON_ERROR);
            if(!is_array($context)||!is_string($context['api_key']??null)||!is_string($context['secret']??null)||!is_bool($context['sandbox']??null))throw new RuntimeException('Bağlam eksik.');
            require_once __DIR__.'/iyzico.php';$client=$factory?$factory($context):new IyzicoClient($context['api_key'],$context['secret'],$context['sandbox']);
        }catch(Throwable $e){checkout_event($token,'context_missing','review');return ['ok'=>false,'code'=>'context_missing'];}
    }
    try {$result=$client->retrieveCheckoutFormResult($token);}catch(Throwable $e){$result=['ok'=>false];}
    if(empty($result['ok'])||!is_array($result['result']??null)){checkout_event($token,'retrieval_error','pending');return ['ok'=>false,'code'=>'retrieval_error'];}
    $data=$result['result'];
    if(($data['status']??'')==='failure') {checkout_event($token,'provider_failure','failed');return ['ok'=>false,'code'=>'provider_failure'];}
    if(($data['status']??'')==='success'&&($data['paymentStatus']??'')==='SUCCESS'&&(string)($data['fraudStatus']??'')==='0') {checkout_event($token,'fraud_review','review');return ['ok'=>false,'code'=>'fraud_review'];}
    try {complete_checkout($token,$data);checkout_event($token,'verified','approved');return ['ok'=>true];}
    catch(Throwable $e){checkout_event($token,'verification_failed','review');return ['ok'=>false,'code'=>'verification_failed'];}
}
