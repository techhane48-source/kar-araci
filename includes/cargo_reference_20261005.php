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
// Rechecked 2026-11-27 against Ecomiro and Merytics (10 August barem reference). Published gross figures are rounded; standard is a provisional scenario, not verified eligibility. See https://merytics.com/araclar/trendyol-kargo-ucretleri .
$fastLow=[46.49,46.49,58.00,68.50,65.50,67.00,100.00];
$fastHigh=[84.49,84.49,94.99,105.49,102.49,103.99,136.00];
$standardLow=[88.00,88.00,97.00,107.50,104.50,106.00,136.99];
$standardHigh=[94.49,94.49,103.49,113.99,110.99,112.49,142.99];
foreach($carriers as $i=>$carrier)for($desi=1;$desi<=10;$desi++){
    $rows[]=['carrier'=>$carrier,'desi'=>$desi,'min_sale'=>0,'max_sale'=>199.99,'gross_tl'=>$standardLow[$i],'fast_gross_tl'=>$fastLow[$i],'kind'=>'barem_scenario'];
    $rows[]=['carrier'=>$carrier,'desi'=>$desi,'min_sale'=>200,'max_sale'=>349.99,'gross_tl'=>$standardHigh[$i],'fast_gross_tl'=>$fastHigh[$i],'kind'=>'barem_scenario'];
}
// One independently published additional discrete row (no interpolation).
$rows[]=['carrier'=>'TEX','desi'=>4,'min_sale'=>350,'max_sale'=>null,'gross_tl'=>128.64];
$rows[]=['carrier'=>'DHL','desi'=>4,'min_sale'=>350,'max_sale'=>null,'gross_tl'=>149.99];
return ['from'=>'2026-10-05','through'=>'2026-11-27','source'=>'https://ecomiro.com/tr/trendyol-kargo-ucreti-hesaplama','confidence'=>'secondary_partial','rows'=>$rows];
