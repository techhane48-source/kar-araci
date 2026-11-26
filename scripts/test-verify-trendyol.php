<?php
declare(strict_types=1);
$dir=sys_get_temp_dir().'/karfiyat-verify-'.bin2hex(random_bytes(8));mkdir($dir,0700);define('DB_PATH',$dir.'/test.sqlite');
register_shutdown_function(static function()use($dir){foreach(glob($dir.'/{*,.*}',GLOB_BRACE)?:[] as $f)if(is_file($f))unlink($f);rmdir($dir);});
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/verify_trendyol.php';
$db=db();$db->prepare("INSERT INTO users(email,password_hash,is_demo) VALUES(?,?,?)")->execute(['verify@example.invalid','synthetic',0]);$uid=(int)$db->lastInsertId();
$db->prepare("INSERT INTO integrations(user_id,marketplace,seller_id,api_key,api_secret,stage) VALUES(?,?,?,?,?,?)")->execute([$uid,'trendyol','123',encrypt_string('synthetic-private-key'),encrypt_string('synthetic-private-secret'),1]);
$db->prepare("INSERT INTO integration_bindings(user_id,marketplace,seller_id,stage) VALUES(?,?,?,?)")->execute([$uid,'trendyol','123',1]);
$ro=verify_open_db(DB_PATH);$c=verify_saved_credentials($ro,DB_PATH,null);$t=[];
$t['existing encrypted connection']=$c['uid']===$uid&&$c['stage']&&$c['key']==='synthetic-private-key';
try{$ro->exec("DELETE FROM integrations");$t['read only database']=false;}catch(PDOException $e){$t['read only database']=true;}
$client=new class{
    public bool $fail=false;
    public bool $badCatalog=false;
    public string $catalogStatus='onSale';
    public string $priceState='missing';
    public array $orders=[];
    public array $documents=[];
    public array $cargo=[];
    public bool $cargoFail=false;
    function testConnection(){return ['ok'=>true];}
    function getProducts(){return ['ok'=>true,'products'=>[['barcode'=>'PRIVATE-BARCODE','salePrice'=>$this->badCatalog?null:100,'quantity'=>5,'status'=>$this->catalogStatus,'sale_price_state'=>$this->priceState]]];}
    function getOrders(...$args){return ['ok'=>true,'orders'=>$this->orders];}
    function getSettlements($type,...$args){return $this->fail&&$type==='Sale'?['ok'=>false,'error'=>'PRIVATE-ERROR']:['ok'=>true,'settlements'=>$type==='DeductionInvoices'&&($args[5]??null)!=='PlatformServiceFee'?$this->documents:[]];}
    function getCargoInvoiceItems($serial){return $this->cargoFail?['ok'=>false,'error'=>'PRIVATE-ERROR']:['ok'=>true,'items'=>$this->cargo];}
    function getClaims(...$args){return ['ok'=>true,'items'=>[]];}
    function getPaymentOrders($page){return ['ok'=>true,'items'=>[],'totalPages'=>0];}
    function getBuybox($barcodes){return ['ok'=>true,'items'=>[],'missing'=>$barcodes];}
};
$r=verify_trendyol_read_only($client,$ro,$uid,7,time(),static fn()=>null);
$t['missing local detected']=!$r['ok']&&$r['checks']['catalog']['missing_local']===1;
$r=verify_trendyol_read_only($client,null,null,7,time(),static fn()=>null);
$t['API only not acceptance']=$r['ok']&&!$r['live_acceptance']&&$r['local_comparison']===null&&!$r['bank_verified'];
$client->badCatalog=true;
$r=verify_trendyol_read_only($client,null,null,7,time(),static fn()=>null);
$t['catalog fixed diagnostic']=($r['checks']['catalog']['reason']??null)==='ACTIVE_PRICE_INVALID'&&verify_catalog_error_code('PRIVATE-ERROR')==='UNKNOWN'&&verify_catalog_error_code('Ürün V2 sayfası alınamadı (HTTP 403).')==='HTTP_403';
$t['API only invalid product rejected']=!$r['ok']&&in_array('catalog',$r['failed_checks'],true)&&$r['checks']['catalog']['unknown_price_or_stock']===1;
$client->catalogStatus='notOnSale';
$r=verify_trendyol_read_only($client,null,null,7,time(),static fn()=>null);
$t['explicit inactive missing price not usable but permitted']=$r['ok']&&$r['checks']['catalog']['inactive_unpriced']===1;
$client->priceState='invalid';
$r=verify_trendyol_read_only($client,null,null,7,time(),static fn()=>null);
$t['inactive malformed price rejected']=!$r['ok'];
$client->catalogStatus='unknown';$client->priceState='missing';
$r=verify_trendyol_read_only($client,null,null,7,time(),static fn()=>null);
$t['unknown status missing price rejected']=!$r['ok']&&$r['checks']['catalog']['reason']==='UNKNOWN_STATUS_PRICE_INVALID';
$client->badCatalog=false;$client->catalogStatus='onSale';
$client->orders=[['packageNumber'=>'PRIVATE-PACKAGE','totalPrice'=>100,'status'=>'Delivered','lines'=>[]]];
$r=verify_trendyol_read_only($client,null,null,7,time(),static fn()=>null);
$t['API only invalid lines rejected']=!$r['ok']&&in_array('orders',$r['failed_checks'],true)&&$r['checks']['orders']['unknown_lines']===1;
$client->orders=[['packageNumber'=>'PRIVATE-PACKAGE','totalPrice'=>null,'status'=>'Delivered','lines'=>[['line_id'=>'PRIVATE-LINE','barcode'=>'PRIVATE-BARCODE','quantity'=>1,'price'=>100]]]];
$r=verify_trendyol_read_only($client,null,null,7,time(),static fn()=>null);
$t['API only invalid order fields rejected']=!$r['ok']&&$r['checks']['orders']['unknown_order_fields']===1&&$r['checks']['orders']['unknown_lines']===0&&!str_contains(json_encode($r),'PRIVATE');
$client->orders=[];
$r=verify_trendyol_read_only($client,null,null,7,time(),static fn()=>null);
$t['empty invoice evidence not verified']=$r['ok']&&!$r['checks']['cargo_invoice_items']['nonempty_evidence'];
$client->documents=[['externalId'=>'PRIVATE-INVOICE','transactionType'=>'Kargo Faturası','raw'=>['id'=>'PRIVATE-INVOICE','affiliate'=>'TRENDYOLTR','country'=>'TR']]];
$client->cargo=[['parcelUniqueId'=>'PRIVATE-PARCEL','orderNumber'=>'PRIVATE-ORDER','shipmentPackageType'=>'Gönderi Kargo Bedeli','amount'=>80,'desi'=>5]];
$db->prepare("INSERT INTO trendyol_cargo_items(user_id,invoice_serial,row_number,parcel_id,order_number,shipment_type,amount,desi) VALUES(?,?,?,?,?,?,?,?)")->execute([$uid,'PRIVATE-INVOICE',0,'PRIVATE-PARCEL','PRIVATE-ORDER','Gönderi Kargo Bedeli',80,5]);
$r=verify_trendyol_read_only($client,$ro,$uid,7,time(),static fn()=>null);
$t['cargo exact match without identity exposure']=$r['checks']['cargo_invoice_items']['matched']===true&&!str_contains(json_encode($r),'PRIVATE');
$client->cargo[0]['amount']=81;
$r=verify_trendyol_read_only($client,$ro,$uid,7,time(),static fn()=>null);
$t['cargo mismatch fails']=$r['checks']['cargo_invoice_items']['different_local']===1&&!$r['checks']['cargo_invoice_items']['ok']&&in_array('cargo_invoice_items',$r['failed_checks'],true);
$client->cargo[]=$client->cargo[0];
$r=verify_trendyol_read_only($client,null,null,7,time(),static fn()=>null);
$t['duplicate cargo item fails']=$r['checks']['cargo_invoice_items']['failed_invoices']===1&&!$r['ok'];
$client->cargoFail=true;
$r=verify_trendyol_read_only($client,null,null,7,time(),static fn()=>null);
$t['cargo request failure redacted']=!$r['ok']&&!str_contains(json_encode($r),'PRIVATE');
$client->cargoFail=false;$client->documents=[];$client->cargo=[];
$client->fail=true;$r=verify_trendyol_read_only($client,null,null,7,time(),static fn()=>null);
$t['failure reported without private data']=!$r['ok']&&in_array('settlement_Sale',$r['failed_checks'],true)&&!str_contains(json_encode($r),'PRIVATE');
$sale=['externalId'=>'PRIVATE-SALE','queryType'=>'Sale','raw'=>['id'=>'PRIVATE-SALE','affiliate'=>'TRENDYOLTR','country'=>'TR','credit'=>100,'debt'=>0,'sellerRevenue'=>80,'commissionAmount'=>20]];
$return=['externalId'=>'PRIVATE-RETURN','queryType'=>'Return','raw'=>['id'=>'PRIVATE-RETURN','affiliate'=>'TRENDYOLTR','country'=>'TR','credit'=>0,'debt'=>100,'sellerRevenue'=>80,'commissionAmount'=>20]];
$r=verify_sale_return_arithmetic([$sale],'Sale');
$t['sale arithmetic verified without identities']=$r['ok']&&$r['state']==='matched'&&!str_contains(json_encode($r),'PRIVATE');
$r=verify_sale_return_arithmetic([$return],'Return');
$t['return arithmetic verified']=$r['ok']&&$r['state']==='matched';
$return['raw']['debt']=90;
$t['return arithmetic mismatch rejected']=!verify_sale_return_arithmetic([$return],'Return')['ok'];
$t['duplicate sale rejected']=!verify_sale_return_arithmetic([$sale,$sale],'Sale')['ok'];
$t['empty statement not verified']=verify_sale_return_arithmetic([],'Sale')['state']==='not_verified';
$sale['raw']['country']='OTHER';
$t['foreign statement not TL evidence']=verify_sale_return_arithmetic([$sale],'Sale')['state']==='not_verified';
$overlapOrder=['orderNumber'=>'PRIVATE-ORDER','packageNumber'=>'PRIVATE-PACKAGE','status'=>'Delivered','currencyCode'=>'TRY','orderCountryCode'=>'TR','totalPrice'=>100,'lines'=>[['barcode'=>'PRIVATE-BARCODE','line_id'=>'PRIVATE-LINE','quantity'=>1,'price'=>100]]];
$overlapSale=['externalId'=>'PRIVATE-SALE','queryType'=>'Sale','orderNumber'=>'PRIVATE-ORDER','shipmentPackageId'=>'PRIVATE-PACKAGE','raw'=>['id'=>'PRIVATE-SALE','sellerId'=>'123','affiliate'=>'TRENDYOLTR','country'=>'TR','orderNumber'=>'PRIVATE-ORDER','shipmentPackageId'=>'PRIVATE-PACKAGE','barcode'=>'PRIVATE-BARCODE','credit'=>100,'debt'=>0,'sellerRevenue'=>80,'commissionAmount'=>20]];
$r=verify_order_statement_overlap([$overlapOrder],[$overlapSale],[]);
$t['overlap package matches production commission logic']=$r['ok']&&$r['state']==='matched'&&!$r['final_profit_verified']&&!str_contains(json_encode($r),'PRIVATE');
$bad=$overlapSale;$bad['raw']['barcode']='WRONG';
$t['overlap mismatch unresolved']=!verify_order_statement_overlap([$overlapOrder],[$bad],[])['ok'];
$t['overlap with claim excluded']=verify_order_statement_overlap([$overlapOrder],[$overlapSale],[['order_number'=>'PRIVATE-ORDER']])['state']==='not_verified';
$t['overlap with adjustment excluded']=verify_order_statement_overlap([$overlapOrder],[$overlapSale,array_replace($overlapSale,['queryType'=>'Return'])],[])['state']==='not_verified';
$t['no overlap not verified']=verify_order_statement_overlap([$overlapOrder],[],[])['state']==='not_verified';
$r=verify_cargo_order_overlap([$overlapOrder],['PRIVATE-ORDER'=>true]);
$t['cargo reference only not allocated']=$r['state']==='present'&&!$r['package_allocation_verified']&&!str_contains(json_encode($r),'PRIVATE');
$t['cargo missing overlap not verified']=verify_cargo_order_overlap([$overlapOrder],[])['state']==='not_verified';
$historyClient=new class{
    public array $rows=[];
    public bool $fail=false;
    function getOrders(...$args){return $this->fail?['ok'=>false]:['ok'=>true,'orders'=>$this->rows];}
};
$historyClient->rows=[$overlapOrder,array_replace($overlapOrder,['orderNumber'=>'UNRELATED','packageNumber'=>'OTHER'])];
$recent=array_replace($overlapOrder,['status'=>'Shipped']);
$history=verify_extend_order_overlap($historyClient,[$recent],[$overlapSale],[],time()-14*86400,time(),static fn()=>null);
$t['history preserves recent version and ignores unrelated']=count($history)===1&&$history[0]['status']==='Shipped';
$history=verify_extend_order_overlap($historyClient,[],[$overlapSale],[],time()-14*86400,time(),static fn()=>null);
$t['older relevant order found']=count($history)===1&&verify_order_statement_overlap($history,[$overlapSale],[])['state']==='matched';
$historyClient->fail=true;
$t['history failure explicit']=verify_extend_order_overlap($historyClient,[],[$overlapSale],[],time()-14*86400,time(),static fn()=>null)===null;
$fee=['externalId'=>'PRIVATE-FEE','raw'=>['id'=>'PRIVATE-FEE','sellerId'=>'123','affiliate'=>'TRENDYOLTR','country'=>'TR','transactionDate'=>123,'credit'=>0,'debt'=>10,'transactionSubType'=>'PlatformServiceFee']];
$r=verify_service_fee_overlap([$fee],[$fee]);
$t['service fee matched and redacted']=$r['ok']&&$r['state']==='matched'&&!str_contains(json_encode($r),'PRIVATE');
$badFee=$fee;$badFee['raw']['debt']=11;
$t['fee differences rejected']=!verify_service_fee_overlap([$fee],[$badFee])['ok'];
$t['empty fee not evidence']=verify_service_fee_overlap([$fee],[])['state']==='not_verified';
$payoutClient=new class{
    public bool $mismatch=false;
    public bool $fail=false;
    function getSettlements($type,...$args){
        if($this->fail)return ['ok'=>false,'error'=>'PRIVATE-ERROR'];
        if(!in_array($type,['Sale','PaymentOrder'],true))return ['ok'=>true,'settlements'=>[]];
        $id=$type==='Sale'?'PRIVATE-SALE':'PRIVATE-PAYMENT';
        $raw=['id'=>$id,'sellerId'=>'123','paymentOrderId'=>'456','affiliate'=>'TRENDYOLTR','country'=>'TR',
            'credit'=>$type==='Sale'?100:0,'debt'=>$type==='PaymentOrder'?80:0,'sellerRevenue'=>80,'commissionAmount'=>20];
        return ['ok'=>true,'settlements'=>[['externalId'=>$id,'queryType'=>$type,'paymentOrderId'=>'456','raw'=>$raw]]];
    }
};
$paid=[['id'=>'456','sellerId'=>'123','status'=>'PAID','amount'=>80,'payoutDate'=>(time()-3*86400)*1000]];
$r=verify_payout_sample($payoutClient,$paid,time(),static fn()=>null);
$t['real payout sample arithmetic match shape']=$r['ok']&&$r['state']==='matched'&&!$r['bank_verified']&&!str_contains(json_encode($r),'PRIVATE');
$paid[0]['amount']=81;
$r=verify_payout_sample($payoutClient,$paid,time(),static fn()=>null);
$t['payout arithmetic mismatch rejected']=!$r['ok']&&$r['state']==='difference';
$payoutClient->fail=true;
$r=verify_payout_sample($payoutClient,$paid,time(),static fn()=>null);
$t['payout request failure redacted']=!$r['ok']&&$r['state']==='request_failed'&&!str_contains(json_encode($r),'PRIVATE');
$r=verify_payout_sample($payoutClient,[],time(),static fn()=>null);
$t['empty payout no evidence']=$r['ok']&&$r['state']==='not_verified'&&!$r['bank_verified'];
$db->exec("UPDATE integration_bindings SET seller_id='999'");
try{verify_saved_credentials($ro,DB_PATH,$uid);$t['binding mismatch rejected']=false;}catch(RuntimeException $e){$t['binding mismatch rejected']=true;}
foreach($t as $name=>$ok)echo ($ok?'PASS':'FAIL')." $name\n";exit(in_array(false,$t,true)?1:0);
