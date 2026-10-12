<?php declare(strict_types=1);
// Only loopback workers and an application copy made by isolated_app.php.
define('HTTP_WORKFLOW_LIBRARY', true);
require_once __DIR__.'/http_workflow_test.php';

final class ConcurrentSite {
    private static array $live=[];
    private static ?string $cleanupReserve=null;
    public string $dir;
    public array $workers=[];
    public function __construct(int $count) {
        $this->dir=create_isolated_app(dirname(__DIR__),true);
        if(self::$cleanupReserve===null){
            self::$cleanupReserve=str_repeat(' ',2*1024*1024);
            register_shutdown_function(static function():void {
                // Fatal generator errors do not run finally. Release reserve
                // memory and stop only workers owned by these test objects.
                self::$cleanupReserve=null;
                foreach(self::$live as $ref){$site=$ref->get();if($site)$site->close();}
            });
        }
        self::$live[]=WeakReference::create($this);
        $source=file_get_contents($this->dir.'/index.php');
        $trace=<<<'PHP'
$testStarted=microtime(true);
$testUsage=function_exists('getrusage')?getrusage():false;
register_shutdown_function(function()use($testStarted,$testUsage){
    $usage=function_exists('getrusage')?getrusage():false;
    file_put_contents(__DIR__.'/data/requests.jsonl',json_encode([
        'id'=>$_SERVER['HTTP_X_TEST_ID']??'', 'pid'=>getmypid(),
        'start'=>$testStarted,'end'=>microtime(true),'sid'=>session_id(),
        'state'=>$_SESSION['state']??null,'memory_peak_bytes'=>memory_get_peak_usage(true),
        'usage_start'=>$testUsage,'usage_end'=>$usage,
    ]).PHP_EOL,FILE_APPEND|LOCK_EX);
});
PHP;
        // Instrument timestamps only: normal load profiles contain no added sleep.
        $source=str_replace('<?php declare(strict_types=1);','<?php declare(strict_types=1);'.PHP_EOL.$trace,$source);
        // Deterministic pre-fix reproductions: delay at the actual vulnerable boundary.
        $source=str_replace("\$items = invites_load();\n    \$ch = false;", "\$items = invites_load();\n    if(isset(\$_GET['_json_barrier'])) test_barrier('json-read',10);\n    \$ch = false;",$source);
        $source=str_replace('$locked = @flock($fh, LOCK_EX);', "if(isset(\$_GET['_append_barrier'])) test_barrier('append-position',10);\n    \$locked = @flock(\$fh, LOCK_EX);",$source);
        $source=str_replace("if (!file_exists(\$lp)) {", "if (!file_exists(\$lp)) {\n    if(isset(\$_GET['_header_barrier'])) test_barrier('header-exists',10);",$source);
        file_put_contents($this->dir.'/index.php',$source);
        // Real invitation entry point, with only directory/mail IO isolated.
        $invite=file_get_contents(dirname(__DIR__).'/invite.php');$tokens=token_get_all($invite);$offset=0;$edits=[];
        for($i=0;$i<count($tokens);$i++){
            $t=$tokens[$i];$text=is_array($t)?$t[1]:$t;
            if(is_array($t)&&$t[0]===T_FUNCTION){$j=$i+1;while(is_array($tokens[$j])&&$tokens[$j][0]===T_WHITESPACE)$j++;$name=is_array($tokens[$j])?$tokens[$j][1]:'';
                if(in_array($name,['data_dir','send_invitation_email'],true)){
                    $end=$offset;$depth=0;for($k=$i;$k<count($tokens);$k++){$part=$tokens[$k];$end+=strlen(is_array($part)?$part[1]:$part);if($part==='{'||(is_array($part)&&in_array($part[0],[T_CURLY_OPEN,T_DOLLAR_OPEN_CURLY_BRACES],true)))$depth++;if($part==='}'&&--$depth===0)break;}
                    $edits[]=[$offset,$end-$offset,$name==='data_dir'?'function data_dir():string{return __DIR__."/data";}':'function send_invitation_email(array $invite,string $note_clean):bool{return true;}'];
                }
            }$offset+=strlen($text);
        }
        if(count($edits)!==2)throw new RuntimeException('Invitation IO isolation hooks missing');foreach(array_reverse($edits) as [$start,$size,$new])$invite=substr_replace($invite,$new,$start,$size);
        file_put_contents($this->dir.'/invite.php',$invite);
        file_put_contents($this->dir.'/invite_probe.php', <<<'PHP'
<?php
ob_start();require __DIR__.'/invite.php';while(ob_get_level())ob_end_clean();
$invite=['id'=>'created-'.$_GET['id'],'code'=>'CREATED'.$_GET['id'],'status'=>'sent'];
header('Content-Type: application/json');echo json_encode(['ok'=>append_invite($invite)]);
PHP);
        file_put_contents($this->dir.'/probe.php', <<<'PHP'
<?php
function test_barrier(string $name,int $count):void {
    $path=__DIR__.'/data/barrier-'.$name;
    $fp=fopen($path,'c+');flock($fp,LOCK_EX);$n=(int)stream_get_contents($fp)+1;
    rewind($fp);ftruncate($fp,0);fwrite($fp,(string)$n);fflush($fp);flock($fp,LOCK_UN);fclose($fp);
    $deadline=microtime(true)+8;
    do { $fp=fopen($path,'r');flock($fp,LOCK_SH);$n=(int)stream_get_contents($fp);flock($fp,LOCK_UN);fclose($fp);if($n>=$count)return;usleep(5000); } while(microtime(true)<$deadline);
    throw new RuntimeException('Probe barrier timeout: '.$name);
}
ob_start();require __DIR__.'/index.php';while(ob_get_level())ob_end_clean();
$op=$_GET['op']??'';$ok=null;
if($op==='append')$ok=safe_file_append(__DIR__.'/data/probe.txt',str_repeat($_GET['tag'],500).PHP_EOL);
if($op==='status')$ok=invite_update_status($_GET['id'],'completed');
if($op==='header')$ok=ensure_log_header();
if($op==='write'){
    $_SESSION['sessionId']=$_GET['tag'];$_SESSION['log_row']=['nick'=>$_GET['tag'],'finalVar'=>'4'];
    $ok=write_full_log_row($_SESSION['log_row']);
}
header('Content-Type: application/json');echo json_encode(['ok'=>$ok]);
PHP);
        try {
            for($i=0;$i<$count;$i++){
                $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if(!$socket)throw new RuntimeException($error);
                $address=stream_socket_get_name($socket,false);fclose($socket);
                $process=proc_open([PHP_BINARY,'-d','session.save_path='.$this->dir.'/data','-S',$address,'-t',$this->dir],
                    [0=>['pipe','r'],1=>['file',$this->dir.'/server-'.$i.'.log','a'],2=>['file',$this->dir.'/server-'.$i.'.log','a']],$pipes,$this->dir);
                if(!is_resource($process))throw new RuntimeException('Cannot start PHP worker');fclose($pipes[0]);
                $this->workers[]=['process'=>$process,'address'=>$address,'pid'=>proc_get_status($process)['pid']];
                $ready=false;for($retry=0;$retry<60;$retry++){
                    $fp=@stream_socket_client('tcp://'.$address,$errno,$error,.1);if($fp){fclose($fp);$ready=true;break;}usleep(20000);
                }
                if(!$ready)throw new RuntimeException('PHP worker startup timeout');
            }
        }catch(Throwable $e){$this->close();throw $e;}
    }
    public function close():void {
        foreach($this->workers as $w){if(is_resource($w['process'])){proc_terminate($w['process']);proc_close($w['process']);}}
        $this->workers=[];
        if(isset($this->dir)&&is_dir($this->dir))remove_isolated_app($this->dir);
    }
    public function diagnostics():array {
        $found=[];foreach(glob($this->dir.'/server-*.log') as $f)foreach(file($f) as $line)
            if(preg_match('/(?:PHP )?(Warning|Fatal error|Parse error|Notice):/',$line))$found[]=trim($line);
        return $found;
    }
}

/** Nonblocking generator, bounded to 40 sockets regardless of participant count. */
final class ConcurrentHttp {
    public array $latencies=[], $statuses=[], $errors=[];
    public int $requests=0;
    public function __construct(public ConcurrentSite $site,public int $maxSockets=40,public float $timeout=20){}
    public function run(array $jobs,?callable $next=null,float $deadlineSeconds=180,?callable $tick=null):array {
        $queue=array_values($jobs);$active=[];$results=[];$deadline=microtime(true)+$deadlineSeconds;
        while($queue||$active){
            if($tick)$tick();
            if(microtime(true)>$deadline)throw new RuntimeException('Bounded load generation deadline exceeded');
            while($queue&&count($active)<$this->maxSockets){
                $job=array_shift($queue);if(($job['release']??0)>microtime(true)){$queue[]=$job;break;}
                $worker=$this->site->workers[($job['worker']??$job['user'])%count($this->site->workers)];
                $fp=@stream_socket_client('tcp://'.$worker['address'],$errno,$error,1,STREAM_CLIENT_CONNECT|STREAM_CLIENT_ASYNC_CONNECT);
                if(!$fp)throw new RuntimeException('Load-generator connect: '.$error);stream_set_blocking($fp,false);
                $body=isset($job['post'])?http_build_query($job['post']):'';
                $wire=(isset($job['post'])?'POST':'GET').' '.($job['path']??'/index.php')." HTTP/1.0\r\nHost: ".$worker['address']."\r\nConnection: close\r\nX-Test-Id: ".$job['id']."\r\n";
                if(!empty($job['cookie']))$wire.='Cookie: PHPSESSID='.$job['cookie']."\r\n";
                if(isset($job['post']))$wire.="Content-Type: application/x-www-form-urlencoded\r\nContent-Length: ".strlen($body)."\r\n";
                $active[(int)$fp]=['fp'=>$fp,'job'=>$job,'wire'=>$wire."\r\n".$body,'sent'=>0,'buffer'=>'','start'=>microtime(true)];
            }
            if(!$active){usleep(1000);continue;}
            $read=$write=[];foreach($active as $a){if($a['sent']<strlen($a['wire']))$write[]=$a['fp'];else$read[]=$a['fp'];}
            $except=[];$selected=@stream_select($read,$write,$except,0,20000);
            if($selected===false)throw new RuntimeException('Load-generator stream_select limitation');
            foreach($write as $fp){$id=(int)$fp;$a=&$active[$id];$n=@fwrite($fp,substr($a['wire'],$a['sent']));if($n===false)throw new RuntimeException('Load-generator socket write');$a['sent']+=$n;unset($a);}
            foreach($read as $fp){$id=(int)$fp;$a=&$active[$id];$part=@fread($fp,65536);if($part===false)throw new RuntimeException('Load-generator socket read');$a['buffer'].=$part;unset($a);
                if(!feof($fp))continue;
                $done=$active[$id];fclose($fp);unset($active[$id]);$job=$done['job'];
                $this->latencies[]=microtime(true)-$done['start'];$this->requests++;
                [$headers,$body]=array_pad(explode("\r\n\r\n",$done['buffer'],2),2,'');
                preg_match('/^HTTP\/\S+ (\d+)/',$headers,$m);$status=(int)($m[1]??0);$this->statuses[$status]=($this->statuses[$status]??0)+1;
                if($status===0)throw new RuntimeException('Incomplete HTTP response');
                $cookie=$job['cookie']??'';if(preg_match('/^Set-Cookie: PHPSESSID=([^;\r\n]*)([^\r\n]*)/mi',$headers,$m))$cookie=stripos($m[2],'Max-Age=0')!==false?'':$m[1];
                if(in_array($status,[302,303],true)&&preg_match('/^Location: ([^\r\n]+)/mi',$headers,$m)){
                    $path=$m[1];if(str_starts_with($path,'http://'))$path=parse_url($path,PHP_URL_PATH).(($q=parse_url($path,PHP_URL_QUERY))?'?'.$q:'');
                    if(!str_starts_with($path,'/')||str_starts_with($path,'//'))throw new RuntimeException('Non-local redirect');
                    $job['path']=$path;$job['cookie']=$cookie;unset($job['post']);$job['redirects']=($job['redirects']??0)+1;
                    if($job['redirects']>4)throw new RuntimeException('Redirect loop');$queue[]=$job;continue;
                }
                $result=['user'=>$job['user'],'status'=>$status,'cookie'=>$cookie,'html'=>$body,'job'=>$job];$results[$job['user']]=$result;
                if($next){$more=$next($result);foreach($more??[] as $new)$queue[]=$new;}
            }
            foreach($active as $a)if(microtime(true)-$a['start']>$this->timeout)throw new RuntimeException('HTTP timeout (server/lock or generator capacity)');
        }
        return $results;
    }
    public function metrics(float $elapsed):array {
        $values=$this->latencies;sort($values);$p=fn($q)=>$values[max(0,(int)ceil(count($values)*$q)-1)]??null;
        $bad=0;foreach($this->statuses as $s=>$n)if($s>=400||$s===0)$bad+=$n;
        return ['requests'=>$this->requests,'http_success'=>$this->requests-$bad,'http_failed'=>$bad,'statuses'=>$this->statuses,
            'latency_seconds'=>['p50'=>$p(.5),'p95'=>$p(.95),'p99'=>$p(.99)],'elapsed_seconds'=>$elapsed,'requests_per_second'=>$this->requests/max(.000001,$elapsed)];
    }
}

function concurrent_form(string $html,array $wanted,array $override=[]):array {
    $dom=new DOMDocument();@$dom->loadHTML('<?xml encoding="UTF-8">'.$html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);$xp=new DOMXPath($dom);
    foreach($xp->query('//form') as $form){$fields=[];foreach($xp->query('.//input[@name] | .//button[@name]',$form) as $control)$fields[$control->getAttribute('name')]=$control->getAttribute('value');
        $matches=true;foreach($wanted as $k=>$v)if(($fields[$k]??null)!==(string)$v){$matches=false;break;}
        if($matches&&isset($fields['workflow_token']))return array_replace($fields,$override);
    }
    throw new RuntimeException('Rendered form missing: '.json_encode($wanted));
}
function concurrent_overlap(string $dir):array {
    // Stream the trace; retaining decoded records plus nested event arrays can
    // exhaust the CLI's 128 MiB limit at 200 users. Two numeric arrays suffice.
    $starts=[];$ends=[];$pids=[];$peak=0;$intervals=0;
    $file=new SplFileObject($dir.'/data/requests.jsonl');
    foreach($file as $line){if(trim($line)==='')continue;$r=json_decode($line,true,512,JSON_THROW_ON_ERROR);$pids[$r['pid']]=true;$peak=max($peak,$r['memory_peak_bytes']);
        $starts[]=$r['start'];$ends[]=$r['end'];$intervals++;}
    sort($starts,SORT_NUMERIC);sort($ends,SORT_NUMERIC);$ended=0;$max=0;$at=0;
    foreach($starts as $i=>$start){while($ended<$intervals&&$ends[$ended]<=$start)$ended++;$active=$i+1-$ended;if($active>$max){$max=$active;$at=$start;}}
    $witness=[];$file->rewind();
    foreach($file as $line){if(trim($line)==='')continue;$r=json_decode($line,true,512,JSON_THROW_ON_ERROR);
        if($r['start']<=$at&&$r['end']>$at)$witness[]=array_intersect_key($r,array_flip(['id','pid','start','end','sid']));}
    return ['executed_php_workers'=>count($pids),'maximum_overlapping_php_requests'=>$max,'traced_intervals'=>$intervals,'overlap_witness'=>$witness,'maximum_php_memory_peak_bytes'=>$peak];
}
