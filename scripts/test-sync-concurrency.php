<?php
declare(strict_types=1);

if (($argv[1]??'')==='--worker') {
    require_once __DIR__.'/../includes/functions.php';
    $gate=$argv[2];$wait=($argv[3]??'')==='wait';
    $client=new class($gate,$wait) {
        public function __construct(private string $gate,private bool $wait) {}
        public function getProducts(...$args):array {
            if ($this->wait) {
                file_put_contents($this->gate.'.ready','ready');$deadline=microtime(true)+5;
                while(!is_file($this->gate.'.release') && microtime(true)<$deadline)usleep(10000);
                if(!is_file($this->gate.'.release'))throw new RuntimeException('Fixture timeout');
            }
            return ['ok'=>true,'complete'=>true,'products'=>[['barcode'=>'ONLY','title'=>'Fixture','category'=>'Giyim','salePrice'=>100,'quantity'=>1]]];
        }
    };
    echo json_encode(sync_products(1,'trendyol',$client),JSON_THROW_ON_ERROR);exit;
}
$directory=sys_get_temp_dir().'/kf-concurrency-'.bin2hex(random_bytes(6));mkdir($directory);
putenv('KARFIYAT_DB='.$directory.'/app.sqlite');
require_once __DIR__.'/../includes/functions.php';
db()->exec("INSERT INTO users(id,email,password_hash) VALUES(1,'fixture@example.test','unused')");
db()->exec("INSERT INTO integrations(user_id,marketplace,seller_id) VALUES(1,'trendyol','seller')");
$php=getenv('PHP_BIN')?:PHP_BINARY;
$command=[$php,__FILE__,'--worker',$directory.'/gate','wait'];
$handles=[];$process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$handles);
if(!is_resource($process))throw new RuntimeException('Worker açılamadı.');
fclose($handles[0]);
try {
    $deadline=microtime(true)+5;
    while(!is_file($directory.'/gate.ready') && microtime(true)<$deadline)usleep(10000);
    if(!is_file($directory.'/gate.ready'))throw new RuntimeException('İlk iş başlayamadı.');
    $otherHandles=[];
    $other=proc_open([$php,__FILE__,'--worker',$directory.'/gate'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$otherHandles);
    fclose($otherHandles[0]);$result=json_decode(stream_get_contents($otherHandles[1]),true);$error=stream_get_contents($otherHandles[2]);
    fclose($otherHandles[1]);fclose($otherHandles[2]);$code=proc_close($other);
    if($code!==0 || empty($result['busy']))throw new RuntimeException('İkinci iş kilitlenmedi: '.$error);
    file_put_contents($directory.'/gate.release','release');
    $result=json_decode(stream_get_contents($handles[1]),true);$error=stream_get_contents($handles[2]);
    fclose($handles[1]);fclose($handles[2]);$code=proc_close($process);$process=null;
    if($code!==0 || empty($result['ok']))throw new RuntimeException('İlk iş tamamlanmadı: '.$error);
    if((int)db()->query('SELECT COUNT(*) FROM products')->fetchColumn()!==1)throw new RuntimeException('Mükerrer kayıt var.');
    echo "PASS İki ayrı PHP sürecinde aynı mağazanın işleri çakışmaz; tek ürün kaydı oluşur.\n";
} finally {
    if(is_resource($process))proc_terminate($process);
    foreach(glob($directory.'/*') as $file)unlink($file);
    rmdir($directory);
}
