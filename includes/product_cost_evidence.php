<?php
declare(strict_types=1);
require_once __DIR__.'/order_evidence.php';

/** Order-date cohort, not transaction-date accounting. Include only fully matched observed orders. */
function product_cost_evidence(PDO $db,int $uid,array $orderNumbers): array
{
    $groups=[];$matched=0;$total=0;$shared=0;$sharedOrders=0;
    $orderNumbers=array_values(array_unique(array_filter(array_map('strval',$orderNumbers),static fn($x)=>$x!=='')));
    foreach(array_chunk($orderNumbers,200) as $chunk){
        $evidence=load_order_evidence($db,$uid,$chunk);$total+=count($chunk);
        foreach($evidence as $e){
            if(!$e['product_components'])continue;
            $matched++;
            if($e['unallocated_product_cargo']!==null){$shared+=finance_units($e['unallocated_product_cargo']);$sharedOrders++;}
            foreach($e['product_components'] as $bc=>$p){
                if(!isset($groups[$bc]))$groups[$bc]=['orders'=>0,'units'=>0,'sale'=>0,'commission'=>0,'revenue'=>0,'cost'=>0,'before_shared_cargo'=>0,'cargo_orders'=>0,'cargo'=>0,'after_cargo'=>0];
                $g=&$groups[$bc];$g['orders']++;$g['units']+=$p['units'];
                foreach(['sale','commission','revenue','cost','before_shared_cargo'] as $key)$g[$key]+=finance_units($p[$key]);
                if($p['cargo']!==null){$g['cargo_orders']++;$g['cargo']+=finance_units($p['cargo']);$g['after_cargo']+=finance_units($p['after_cargo']);}
                unset($g);
            }
        }
    }
    ksort($groups,SORT_STRING);
    return ['groups'=>$groups,'orders'=>$total,'matched'=>$matched,'shared_orders'=>$sharedOrders,'shared_cargo'=>$sharedOrders?$shared/10000.0:null];
}
