<?php
declare(strict_types=1);
require_once __DIR__.'/cost_history.php';
function curtain_dimensions(?string $size): ?array
{
    if($size===null||!preg_match('/^\s*(\d{1,4}(?:[.,]\d{1,2})?)\s*(?:cm\s*)?[x×X]\s*(\d{1,4}(?:[.,]\d{1,2})?)\s*(?:cm)?\s*$/iuD',$size,$m))return null;
    $w=(float)str_replace(',','.',$m[1]);$h=(float)str_replace(',','.',$m[2]);
    return $w>0&&$h>0?[$w,$h]:null;
}
function curtain_cost_preview(PDO $db,int $uid,int $pid,string $fabric,string $sewing,string $vat,string $scope):array
{
    $a=parse_money_input($fabric);$b=parse_money_input($sewing);
    if($a===null||$b===null||!is_finite($a)||!is_finite($b)||$a<=0||$b<0||$a+$b>1000000)throw new InvalidArgumentException('Metre başına kumaş ve dikim maliyetini kontrol edin.');
    if(!in_array($vat,['1','10','20'],true)||!in_array($scope,['today','first_sale'],true))throw new InvalidArgumentException('Alış KDV oranı ve geçerlilik seçin.');
    $q=$db->prepare("SELECT * FROM products WHERE id=? AND user_id=? AND marketplace='trendyol'");$q->execute([$pid,$uid]);$p=$q->fetch();
    if(!$p||empty($p['variant_model_key']))throw new InvalidArgumentException('Model bilgisi bulunamadı. Önce ürünleri yeniden senkronize edin.');
    $q=$db->prepare("SELECT id,barcode,name,variant_size,cost,cost_vat FROM products WHERE user_id=? AND marketplace='trendyol' AND variant_model_key=? ORDER BY barcode,id LIMIT 20001");$q->execute([$uid,$p['variant_model_key']]);$all=$q->fetchAll();
    if(count($all)>20000)throw new InvalidArgumentException('Model 20.000 varyant sınırını aşıyor.');
    $seen=[];$rows=[];$missing=[];$display=[];$date=cost_effective_day($scope==='first_sale'?'2000-01-01':date('Y-m-d'));
    foreach($all as $r){
        $bc=(string)$r['barcode'];if($bc===''||isset($seen[$bc]))throw new InvalidArgumentException('Modelde eksik veya tekrar eden barkod var.');
        $seen[$bc]=true;$dims=curtain_dimensions($r['variant_size']);
        if(!$dims){$missing[]=$r;continue;}
        $value=round(($a+$b)*$dims[0]/100,2,PHP_ROUND_HALF_UP);
        if($value<=0||$value>100000000)throw new InvalidArgumentException('Hesaplanan maliyet sınır dışında.');
        $rows[]=['barcode'=>$bc,'effective_at'=>$date,'cost'=>$value,'vat'=>(float)$vat];
        $display[]=$r+['width'=>$dims[0],'height'=>$dims[1],'calculated'=>$value];
    }
    $hash=hash('sha256',json_encode([$uid,$pid,$p['variant_model_key'],$all,$rows,$fabric,$sewing,$vat,$scope],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
    return ['product'=>$p,'rows'=>$rows,'display'=>$display,'missing'=>$missing,'total'=>count($all),'rate'=>$a+$b,'hash'=>$hash];
}
