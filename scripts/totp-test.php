<?php
require_once __DIR__ . '/../includes/auth.php';
// RFC 6238 test vektörü: secret "12345678901234567890" ASCII, base32 = GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ
$secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
$c = totp_code($secret, 1); // timeSlice=1 -> time=59 (floor(59/30)=1), 8-digit 94287082 -> son 6 = 287082
echo "timeSlice=1 -> kod: $c (beklenen 287082)\n";
echo "self-verify: " . (totp_verify($secret, $c) ? "OK" : "FAIL") . "\n";
echo "referral: " . generate_referral_code(1) . "\n";
