<?php
declare(strict_types=1);
require_once __DIR__.'/analysis_import.php';

/** Cost settings only: a downloaded template cannot overwrite live price/stock or observed invoice desi. */
function catalog_cost_columns(array $headers): array
{
    $aliases=['barcode'=>['barkod','barcode','sku'],'cost'=>['urunmaliyetikdvdahil','maliyet','cost','alis','alisfiyati'],
        'cost_vat'=>['maliyetkdvorani','alis kdv','kdvorani','kdv','costvat'],
        'extra_fee'=>['ekstramaliyettl','ekstragider','ekstra','extrafee'],
        'return_rate'=>['iadeorani','returnrate'],'model_kodu'=>['modelkodu','model'],
        'marka'=>['marka'],'renk'=>['renk'],'beden'=>['beden']];
    $map=[];
    foreach($headers as $i=>$v){$h=analysis_header((string)$v);
        foreach($aliases as $k=>$names)if(in_array($h,$names,true)){
            if(isset($map[$k]))throw new InvalidArgumentException('Tekrarlanan kolon: '.$k);
            $map[$k]=$i;
        }
    }
    if(!isset($map['barcode'],$map['cost']))throw new InvalidArgumentException('Barkod ve maliyet kolonları gerekli.');
    return $map;
}
function catalog_cost_import(PDO $db,int $uid,array $headers,iterable $rows): array
{
    $map=catalog_cost_columns($headers);
    $q=$db->prepare("SELECT id,barcode FROM products WHERE user_id=? AND marketplace='trendyol' AND barcode!=''");
    $db->beginTransaction();
    try{
        $q->execute([$uid]);$ids=[];$duplicates=[];
        while($p=$q->fetch()){$bc=(string)$p['barcode'];if(isset($ids[$bc]))$duplicates[$bc]=true;else $ids[$bc]=(int)$p['id'];}
        $seen=[];$stmts=[];$stats=['updated'=>0,'unmatched'=>0,'blank'=>0,'rows'=>0];
        foreach($rows as $row){
            if(++$stats['rows']>20000||count($row)>128)throw new InvalidArgumentException('En fazla 20.000 satır ve 128 kolon.');
            if(!array_filter($row,static fn($v)=>trim((string)$v)!==''))continue;
            $bc=trim((string)($row[$map['barcode']]??''));
            if($bc===''||strlen($bc)>128||isset($seen[$bc]))throw new InvalidArgumentException('Satır '.$stats['rows'].': boş veya tekrarlanan barkod.');
            $seen[$bc]=true;
            $raw=trim((string)($row[$map['cost']]??''));
            if($raw===''){$stats['blank']++;continue;}
            $cost=parse_money_input($raw);
            if($cost===null||$cost<0||$cost>100000000)throw new InvalidArgumentException('Satır '.$stats['rows'].': geçersiz maliyet.');
            if(isset($duplicates[$bc]))throw new InvalidArgumentException('Birden fazla ürünle eşleşen barkod: '.$bc);
            if(!isset($ids[$bc])){$stats['unmatched']++;continue;}
            $values=['cost'=>$cost];
            foreach(['cost_vat','extra_fee','return_rate'] as $k){
                if(!isset($map[$k]))continue;$v=trim((string)($row[$map[$k]]??''));if($v==='')continue;
                $num=$k==='extra_fee'?parse_money_input($v):analysis_rate($v);
                if($num===null||$num<0||$num>($k==='extra_fee'?100000000:100))throw new InvalidArgumentException('Satır '.$stats['rows'].': geçersiz '.$k);
                $values[$k]=$num;
            }
            foreach(['model_kodu','marka','renk','beden'] as $k){
                if(!isset($map[$k]))continue;$v=trim((string)($row[$map[$k]]??''));if($v==='')continue;
                if(strlen($v)>250)throw new InvalidArgumentException('Satır '.$stats['rows'].': metin çok uzun.');
                $values[$k]=$v;
            }
            $cols=array_keys($values);$key=implode(',',$cols);
            if(!isset($stmts[$key]))$stmts[$key]=$db->prepare("UPDATE products SET ".implode(',',array_map(static fn($k)=>$k.'=?',$cols))." WHERE id=? AND user_id=? AND marketplace='trendyol'");
            $stmts[$key]->execute(array_merge(array_values($values),[$ids[$bc],$uid]));$stats['updated']++;
        }
        if(!$stats['updated'])throw new InvalidArgumentException('Güncellenebilir ürün yok; barkod ve maliyetleri kontrol edin.');
        $db->commit();return $stats;
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
