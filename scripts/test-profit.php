<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/profit.php';

$fail = 0;
function near(float $actual, float $expected, string $name, float $tol = 0.005): void {
    global $fail;
    $ok = abs($actual - $expected) <= $tol;
    echo ($ok ? 'PASS' : 'FAIL') . " $name: $actual (expected $expected)\n";
    if (!$ok) $fail++;
}
function check(bool $ok, string $name): void {
    global $fail;
    echo ($ok ? 'PASS' : 'FAIL') . " $name\n";
    if (!$ok) $fail++;
}
$p = 717.74;
$r = calc_profit(400, $p, 65, 19, 20, 0, 13.19, auto_stopaj($p));
near($r['profit'], 80, 'Standard net profit');
near($r['commission'], 136.3706, 'Commission');
near($r['net_kdv'], (717.74-400-65-13.19-136.3706)/6, 'Common VAT');
$inverse = calc_price(400,65,19,20,0,13.19,1,0,80);
check($inverse['ok'], 'Feasible target');
near($inverse['price'], 717.74, 'Target price', 0.01);
$impossible = calc_price(400,65,19,20,0,13.19,1,0,0,80);
check(!$impossible['ok'] && $impossible['price'] === 0.0, 'Unreachable margin rejected');
$separate = calc_profit(400,$p,65,19,20,0,13.19,auto_stopaj($p),0,['cost_vat'=>10]);
near($separate['net_kdv'], $p/6 - 400/11 - (136.3706+65+13.19)/6, 'Separate cost VAT');
$loss = calc_profit(200,100,0,0,20,0,0,0,0,['allow_negative_vat'=>true]);
near($loss['net_kdv'], -100/6, 'Negative VAT allowed');
near(calc_profit(200,100,0,0)['net_kdv'],0,'Negative VAT floored by default');
near(calc_profit(100,200,0,0,0)['net_kdv'],0,'Zero VAT');
$inverseSeparate=calc_price(400,65,19,20,0,13.19,1,0,80,0,['cost_vat'=>10]);
near($inverseSeparate['profit'],80,'Inverse uses same VAT options');
exit($fail ? 1 : 0);
