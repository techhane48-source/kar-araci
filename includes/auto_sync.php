<?php
declare(strict_types=1);

/** Application defaults, not provider rate limits or guaranteed refresh times. */
function auto_sync_intervals(): array
{
    return ['orders'=>900,'products'=>3600,'claims'=>1800,'settlements'=>21600,'documents'=>21600,'payout_list'=>21600];
}
function auto_sync_due(array $statuses,int $now,int $limit=6): array
{
    $intervals=auto_sync_intervals();$due=[];
    foreach($statuses as $r){
        $job=(string)$r['job'];if(!isset($intervals[$job]))continue;
        $last=(int)($r['last_success']??0);$attempt=(int)($r['last_attempt']??0);$state=$r['state']??'';
        $next=$last?$last+$intervals[$job]:0;
        // Incomplete or interrupted attempts retry, but never in a tight loop.
        if(in_array($state,['failed','running'],true))$next=max($next,$attempt+900);
        if($next<=$now)$due[]=['uid'=>(int)$r['user_id'],'job'=>$job,'due'=>$next,'last_attempt'=>$attempt,'last_success'=>$last];
    }
    usort($due,static fn($a,$b)=>[$a['due'],$a['last_attempt'],$a['uid'],array_search($a['job'],array_keys($intervals),true)]<=>[$b['due'],$b['last_attempt'],$b['uid'],array_search($b['job'],array_keys($intervals),true)]);
    return array_slice($due,0,max(1,min(50,$limit)));
}
function auto_sync_statuses(PDO $db): array
{
    $q=$db->query("SELECT i.user_id,s.job,s.last_attempt,s.last_success,s.state FROM integrations i JOIN users u ON u.id=i.user_id
       LEFT JOIN sync_status s ON s.user_id=i.user_id AND s.marketplace=i.marketplace
       WHERE i.marketplace='trendyol' AND u.is_demo=0 ORDER BY i.user_id");
    $stores=[];
    while($r=$q->fetch()){
        $uid=(int)$r['user_id'];if(!isset($stores[$uid]))$stores[$uid]=[];
        if($r['job']!==null)$stores[$uid][$r['job']]=$r;
    }
    $out=[];
    foreach($stores as $uid=>$jobs)foreach(auto_sync_intervals() as $job=>$unused)$out[]=$jobs[$job]??['user_id'=>$uid,'job'=>$job];
    return $out;
}
/** Re-read overlapping transaction windows, recover ordinary outages within application limits. */
function auto_sync_days(int $last,int $now,int $initial=30,int $min=7,int $max=90): int
{
    if(!$last)return $initial;
    return max($min,min($max,(int)ceil(max(0,$now-$last)/86400)+2));
}
function auto_sync_execute(array $task,int $now): array
{
    $uid=$task['uid'];$last=$task['last_success'];$job=$task['job'];
    return match($job){
      'orders'=>sync_orders($uid,'trendyol',auto_sync_days($last,$now,30,7,89)),
      'products'=>sync_products($uid,'trendyol'),
      'claims'=>sync_trendyol_claims($uid,auto_sync_days($last,$now,30,7,89)),
      'settlements'=>sync_settlements($uid,'trendyol',auto_sync_days($last,$now)),
      'documents'=>sync_trendyol_documents($uid,auto_sync_days($last,$now)),
      'payout_list'=>sync_trendyol_payout_page($uid,0),
      default=>['ok'=>false,'error'=>'Bilinmeyen otomatik iş']
    };
}
/** One worker per database. Per-store locks are also held by the actual sync functions. */
function auto_sync_worker(PDO $db,int $limit=6,?callable $execute=null,?int $clock=null): array
{
    $now=$clock??time();$path=dirname(DB_PATH).DIRECTORY_SEPARATOR.'auto-sync.lock';
    $fh=@fopen($path,'c');if(!$fh)throw new RuntimeException('Otomatik senkron kilidi açılamadı.');
    if(!flock($fh,LOCK_EX|LOCK_NB)){fclose($fh);return ['busy'=>true,'jobs'=>[]];}
    try{
        set_setting('auto_sync_heartbeat',(string)$now);
        $tasks=auto_sync_due(auto_sync_statuses($db),$now,$limit);$results=[];
        foreach($tasks as $task){
            // Recheck connection: disconnection during the queue must not trigger a request.
            $q=$db->prepare("SELECT 1 FROM integrations i JOIN users u ON u.id=i.user_id WHERE i.user_id=? AND i.marketplace='trendyol' AND u.is_demo=0");
            $q->execute([$task['uid']]);if(!$q->fetchColumn())continue;
            try{$r=$execute?$execute($task,$now):auto_sync_execute($task,$now);}
            catch(Throwable $e){$r=['ok'=>false];}
            $results[]=['uid'=>$task['uid'],'job'=>$task['job'],'ok'=>!empty($r['ok'])];
            set_setting('auto_sync_heartbeat',(string)($clock??time()));
            // Bound admissions per tick; individual jobs may take longer.
            if($clock===null&&time()-$now>=240)break;
        }
        set_setting('auto_sync_last_finished',(string)($clock??time()));
        return ['busy'=>false,'jobs'=>$results];
    }finally{flock($fh,LOCK_UN);fclose($fh);}
}
