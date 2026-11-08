<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../includes/functions.php';
$options=getopt('',['execute','limit:']);$limit=filter_var($options['limit']??20,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100]]);
if($limit===false){fwrite(STDERR,"--limit 1 ile 100 arasında olmalı.\n");exit(1);}
$q=db()->prepare("SELECT token FROM checkout_sessions WHERE status<>'approved' AND created_at>=datetime('now','-7 days') AND (checked_at IS NULL OR checked_at<?) ORDER BY created_at LIMIT ?");$q->execute([time()-300,$limit]);$rows=$q->fetchAll();
if(!isset($options['execute'])){echo count($rows)." ödeme kontrol bekliyor. Sorgulamak için --execute kullanın; yeni tahsilat yapılmaz.\n";exit;}
$failed=0;foreach($rows as $row){$r=verify_checkout($row['token']);echo $r['ok']?"Doğrulandı\n":"Kontrol bekliyor: ".$r['code']."\n";if(!$r['ok'])$failed++;}exit($failed?1:0);
