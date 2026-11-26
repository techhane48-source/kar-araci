<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/trendyol.php';
$fixture = [[
    'title'=>'Fon perde', 'category'=>['name'=>'Ev Tekstili'], 'brand'=>['name'=>'Test'],
    'variants'=>[
        ['barcode'=>'001','price'=>['salePrice'=>500,'listPrice'=>600],'stock'=>['quantity'=>0],'vatRate'=>0,'commission'=>19],
        ['barcode'=>'002','price'=>['salePrice'=>750],'stock'=>[],'vatRate'=>20],
    ],
]];
$r=TrendyolClient::normalizeApprovedContent($fixture);
$tests=[
    'two variants' => count($r)===2,
    'leading zeros' => $r[0]['barcode']==='001',
    'zero stock retained' => $r[0]['quantity']===0,
    'missing stock distinct' => $r[1]['quantity']===null,
    'zero VAT retained' => $r[0]['vatRate']===0.0,
    'variant prices' => $r[1]['salePrice']===750.0,
    'category mapping' => $r[0]['category']==='Ev Tekstili',
    'missing sale status is not inactive' => $r[0]['status']==='unknown',
    'positive sale price state' => $r[0]['sale_price_state']==='positive',
];
$inactive=TrendyolClient::normalizeApprovedContent([['variants'=>[['barcode'=>'003','onSale'=>false,'price'=>['salePrice'=>0]],['barcode'=>'004','onSale'=>false,'price'=>['salePrice'=>'bad']],['barcode'=>'005','onSale'=>true]]]]);
$tests['explicit inactive and zero state']=$inactive[0]['status']==='notOnSale'&&$inactive[0]['sale_price_state']==='zero';
$tests['malformed not missing']=$inactive[1]['sale_price_state']==='invalid';
$tests['active missing state']=$inactive[2]['status']==='onSale'&&$inactive[2]['sale_price_state']==='missing';
try { TrendyolClient::normalizeApprovedContent([['title'=>'bad']]); $tests['invalid content rejected']=false; }
catch (UnexpectedValueException $e) { $tests['invalid content rejected']=true; }
date_default_timezone_set('Europe/Istanbul');
$tests['midnight local day']=strtotime(date('Y-m-d',strtotime('2026-11-25 01:30')))===strtotime('2026-11-25 00:00');
foreach ($tests as $name=>$ok) echo ($ok?'PASS':'FAIL')." $name\n";
exit(in_array(false,$tests,true)?1:0);
