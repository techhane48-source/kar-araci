<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/profit.php';
$cases=['1.234,56'=>1234.56,'1234,56'=>1234.56,'1234.56'=>1234.56,'₺ 1.234,56'=>1234.56,'0'=>0.0,'120-150'=>null,'abc'=>null,'1.234.56'=>null,'1.234'=>null];
$fail=0;
foreach($cases as $input=>$expect) {
    $got=parse_money_input((string)$input);
    $ok=$expect===null?$got===null:($got!==null&&abs($got-$expect)<0.00001);
    echo ($ok?'PASS':'FAIL')." $input\n"; if(!$ok)$fail++;
}
exit($fail?1:0);
