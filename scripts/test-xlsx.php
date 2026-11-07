<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/xlsx.php';
// Bu testin veritabanına ihtiyacı yoktur.
function desi_from_dims(float $a, float $b, float $c): float { return $a*$b*$c/3000; }
$failed=0;
foreach ([200.0, 400.0, 20.0, 0.0, 50.5, 1234.56, 0.01, 1000000.0] as $value) {
    $path=tempnam(sys_get_temp_dir(),'kf-xlsx-');
    try {
        xlsx_write_maliyet([['barcode'=>'0012345','name'=>'Ürün & <Örnek>', 'price'=>$value,'cost'=>$value,'kdv'=>20]],$path);
        $parsed=xlsx_read($path);
        $row=$parsed['rows'][0];
        $ok=(float)$row[5]===$value && (float)$row[8]===20.0 && $row[0]==='0012345'
            && $row[4]==='Ürün & <Örnek>' && ($value===0.0 || (float)$row[7]===$value);
        echo ($ok?'PASS ':'FAIL ')."XLSX round-trip $value\n";
        if (!$ok) $failed++;
    } finally { unlink($path); }
}
exit($failed?1:0);
