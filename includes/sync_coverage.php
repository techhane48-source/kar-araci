<?php
declare(strict_types=1);

/** Half-open Unix-second ranges. A successful API query is not proof of accounting completeness. */
function sync_merge_ranges(array $ranges): array
{
    usort($ranges,static fn($a,$b)=>$a[0]<=>$b[0]);
    $out=[];
    foreach($ranges as $r){
        $a=(int)$r[0];$b=(int)$r[1];
        if($a<0||$b<=$a)throw new InvalidArgumentException('Geçersiz senkron aralığı.');
        $i=count($out)-1;
        if($i>=0&&$a<=$out[$i][1])$out[$i][1]=max($out[$i][1],$b);
        else $out[]=[$a,$b];
    }
    return $out;
}
function sync_range_gaps(array $ranges,int $start,int $end): array
{
    if($end<=$start)return [];
    $cursor=$start;$gaps=[];
    foreach(sync_merge_ranges($ranges) as [$a,$b]){
        if($b<=$cursor)continue;
        if($a>=$end)break;
        if($a>$cursor)$gaps[]=[$cursor,min($a,$end)];
        $cursor=max($cursor,min($b,$end));
    }
    if($cursor<$end)$gaps[]=[$cursor,$end];
    return $gaps;
}
function sync_record_range(PDO $db,int $uid,string $market,string $job,int $start,int $end): void
{
    // Called in the same transaction as the final persisted data, or after all slices committed.
    $q=$db->prepare("SELECT range_start,range_end FROM sync_ranges WHERE user_id=? AND marketplace=? AND job=?");
    $args=[$uid,$market,$job];$q->execute($args);
    $ranges=array_map(static fn($r)=>[(int)$r['range_start'],(int)$r['range_end']],$q->fetchAll());
    $ranges[]=[$start,$end];$ranges=sync_merge_ranges($ranges);
    $db->prepare("DELETE FROM sync_ranges WHERE user_id=? AND marketplace=? AND job=?")->execute($args);
    $q=$db->prepare("INSERT INTO sync_ranges(user_id,marketplace,job,range_start,range_end) VALUES(?,?,?,?,?)");
    foreach($ranges as $r)$q->execute(array_merge($args,$r));
}
function sync_read_ranges(PDO $db,int $uid,string $market,string $job): array
{
    $q=$db->prepare("SELECT range_start,range_end FROM sync_ranges WHERE user_id=? AND marketplace=? AND job=? ORDER BY range_start");
    $q->execute([$uid,$market,$job]);
    return array_map(static fn($r)=>[(int)$r['range_start'],(int)$r['range_end']],$q->fetchAll());
}
/** Normal polling overlaps the last confirmed modified-time window; explicit refresh can ask for longer history.
 * The 89-day ceiling is an application policy, not a claim about provider retention.
 */
function sync_order_window(array $ranges,int $now,int $days): array
{
    $floor=$now-89*86400;$days=max(1,min(89,$days));
    $start=$now-$days*86400;
    $latest=0;foreach($ranges as $r)$latest=max($latest,(int)$r[1]);
    if($latest>$now+1)throw new RuntimeException('Sunucu saati veya senkron kaydı tutarsız.');
    if($latest>0){
        if($latest<$floor)throw new RuntimeException('89 günlük uygulama sınırını aşan sipariş senkron boşluğu var. Destek ile geçmiş veri kontrolü gerekli.');
        $start=min($start,max($floor,$latest-300));
    }
    return [$start,$now];
}
function sync_coverage_label(PDO $db,int $uid,string $market,string $job,int $start,int $end): string
{
    $ranges=sync_read_ranges($db,$uid,$market,$job);
    if(!$ranges)return 'Doğrulanmış sorgu aralığı yok; yeniden senkron gerekli.';
    $gaps=sync_range_gaps($ranges,$start,$end);
    if(!$gaps)return 'İstenen aralığın tamamı başarıyla sorgulandı (finansal kesinlik değildir).';
    $parts=[];
    foreach(array_slice($gaps,0,3) as [$a,$b])$parts[]=date('d.m.Y H:i',$a).' – '.date('d.m.Y H:i',$b);
    return 'Sorgulanmamış aralık: '.implode('; ',$parts).(count($gaps)>3?' ve diğer aralıklar.':'.');
}
