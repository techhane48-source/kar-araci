<?php
declare(strict_types=1);
// Partial secondary-source reference, NOT a verified primary tariff or complete price table.
// Sources: https://ecomiro.com/tr/trendyol-kargo-ucreti-hesaplama and https://ecomiro.com/tr/bilgi/trendyol-kargo-ucretleri ; cross-check https://ezv.com.tr/kargo-ucretleri/trendyol and https://www.parasut.com/blog/trendyol-kargo-ucretleri .
// Only published discrete rows; never interpolate missing desi values.
$carriers=['TEX','PTT','ARAS','DHL','SURAT','KG','YK'];
$net=[
  1=>[81.95,81.95,88.96,97.99,95.54,96.59,121.75],
  2=>[81.95,81.95,88.96,97.99,95.54,96.59,121.75],
  3=>[100.20,100.00,100.84,110.99,106.45,107.09,132.56],
  5=>[114.10,110.61,117.85,137.99,122.41,128.09,157.16],
  10=>[164.18,169.84,164.23,189.99,170.86,179.54,214.59],
  15=>[206.03,214.08,202.03,244.98,213.51,235.19,284.54],
  20=>[252.77,258.94,252.09,334.99,267.46,292.94,338.15],
  30=>[360.55,348.60,371.34,599.99,374.15,408.44,520.70],
  31=>[413.86,720.28,383.12,635.98,447.60,418.94,535.65],
  50=>[623.10,1050.27,606.95,1319.79,702.52,618.44,819.70],
];
$rows=[];foreach($net as $desi=>$values)foreach($carriers as $i=>$carrier)$rows[]=['carrier'=>$carrier,'desi'=>$desi,'min_sale'=>$desi<=10?350:0,'max_sale'=>null,'gross_tl'=>round($values[$i]*1.2,2)];
return ['from'=>'2026-10-05','through'=>'2026-11-25','source'=>'https://ecomiro.com/tr/trendyol-kargo-ucreti-hesaplama','confidence'=>'secondary_partial','rows'=>$rows];
