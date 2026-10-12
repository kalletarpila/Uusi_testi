<?php declare(strict_types=1);
define('CONCURRENT_WORKFLOW_LIBRARY',true);
require_once __DIR__.'/concurrent_workflow_test.php';

function failure_site(int $workers=3):ConcurrentSite {
    $site=new ConcurrentSite($workers);
    // Only the IO call is injected. Actual transaction/locking/replacement code stays.
    $file=$site->dir.'/storage.php';$code=file_get_contents($file);
    $hook=<<<'PHP'
function test_storage_write($fp,string $bytes){
    $mode=$_GET['write_fault']??'';$uri=stream_get_meta_data($fp)['uri'];
    $target=str_contains($mode,'json')?'invites.json.tmp.':'survey_log_';
    if(!$mode||!str_contains($uri,$target))return fwrite($fp,$bytes);
    if(str_contains($mode,'short'))return fwrite($fp,substr($bytes,0,3));
    static $calls=0;
    if($calls++===0){$n=fwrite($fp,substr($bytes,0,5));fflush($fp);
        if(str_contains($mode,'pause')){file_put_contents(__DIR__.'/data/writer-paused',getmypid());sleep(30);}
        return $n;
    }
    return false;
}
PHP;
    $code=str_replace('<?php declare(strict_types=1);','<?php declare(strict_types=1);'.PHP_EOL.$hook,$code);
    $code=str_replace('@fwrite($fp,substr($bytes,$offset,65536))','test_storage_write($fp,substr($bytes,$offset,65536))',$code);file_put_contents($file,$code);
    $code=str_replace("\$lock=@fopen(\$path.'.lock','c');", "\$testLockStart=microtime(true);\n    \$lock=@fopen(\$path.'.lock','c');",$code);
    $code=str_replace('if(@flock($lock,$mode|LOCK_NB))return $work();',
        'if(@flock($lock,$mode|LOCK_NB)){file_put_contents(__DIR__."/data/lock-waits.jsonl",json_encode(["path"=>$path,"wait_seconds"=>microtime(true)-$testLockStart]).PHP_EOL,FILE_APPEND|LOCK_EX);return $work();}',$code);
    file_put_contents($file,$code);
    $index=file_get_contents($site->dir.'/index.php');
    $sessionFault=<<<'PHP'
if(isset($_GET['session_fault'])){
    session_set_save_handler(new class extends SessionHandler {
        public function write(string $id,string $data):bool{return false;}
    },true);
}
if(isset($_GET['session_start_fault']))ini_set('session.save_path',__DIR__.'/missing-session-directory');
PHP;
    $index=str_replace('<?php declare(strict_types=1);','<?php declare(strict_types=1);'.PHP_EOL.$sessionFault,$index);file_put_contents($site->dir.'/index.php',$index);
    $probe=file_get_contents($site->dir.'/probe.php');
    $probe=str_replace("invite_update_status(\$_GET['id'],'completed')","invite_update_status(\$_GET['id'],\$_GET['status']??'completed')",$probe);
    $probe=str_replace("header('Content-Type: application/json');", "if(\$op==='reconcile')\$ok=complete_quiz_result();\nheader('Content-Type: application/json');",$probe);file_put_contents($site->dir.'/probe.php',$probe);
    file_put_contents($site->dir.'/hold.php', <<<'PHP'
<?php
$fp=fopen($argv[1],'c+');if($argv[3]!=='open')flock($fp,LOCK_EX);
file_put_contents(__DIR__.'/data/holder-ready','ready');usleep((int)$argv[2]);fclose($fp);
PHP);
    return $site;
}
function failure_prepare(ConcurrentSite $site,int $index=4):array {
    // Participant 4 is invited, TB1, normal A2. Every answer uses real forms.
    $u=new LoadParticipant($index,$site);$http=new ConcurrentHttp($site);
    $http->run([$u->first(0)],function($r)use($u){$u->accept($r);if($u->isFinal())return [];$job=$u->next();return $job?[$job]:[];});
    verify($u->isFinal(),'Actual participant waits before final answer');return [$u,$http];
}
function failure_final(LoadParticipant $u,ConcurrentHttp $http,string $query=''):array {
    $job=$u->next();if($query)$job['path']='/index.php?'.$query;$job['worker']=0;
    return [$http->run([$job])[$u->index],$job];
}
function failure_technical(array $r,ConcurrentSite $site,LoadParticipant $u,bool $csvSaved=false):void {
    verify($r['status']===200&&str_contains($r['html'],'teknisen ongelman vuoksi'),'Visible technical failure');
    $s=json_decode(file_get_contents($site->dir.'/data/state-'.$u->cookie.'.json'),true);verify(($s['state']??'')==='phase2_unresolved','No apparently completed personality result');
    $items=json_decode(file_get_contents($site->dir.'/data/invites.json'),true,512,JSON_THROW_ON_ERROR);verify($items[$u->index]['status']==='accepted','Failure does not complete invitation');
    verify(count(research_rows($site->dir))===($csvSaved?1:0),'Research row persistence explicitly tracked');
    if(!$csvSaved)verify(!is_file($site->dir.'/data/emails.jsonl'),'No result notification on failed CSV persistence');
}
function failure_holder(ConcurrentSite $site,string $path,int $microseconds,string $mode='lock') {
    @unlink($site->dir.'/data/holder-ready');$process=proc_open([PHP_BINARY,$site->dir.'/hold.php',$path,(string)$microseconds,$mode],
        [0=>['pipe','r'],1=>['file',$site->dir.'/holder.log','a'],2=>['file',$site->dir.'/holder.log','a']],$pipes,$site->dir);fclose($pipes[0]);
    $deadline=microtime(true)+3;while(!is_file($site->dir.'/data/holder-ready')&&microtime(true)<$deadline)usleep(5000);
    verify(is_file($site->dir.'/data/holder-ready'),'Owned holder obtained its lock');return $process;
}
function failure_case(string $name,callable $test):void {
    global $argv;
    foreach($argv as $arg)if(str_starts_with($arg,'--match=')&&!str_contains($name,substr($arg,8)))return;
    concurrent_probe($name,function()use($test){$site=failure_site();file_put_contents($site->dir.'/data/invites.json',json_encode(concurrent_invites(30)));
        try{return $test($site);}finally{$site->close();}});
}

failure_case('same-session-answer-replay-and-reset-isolation',function($site){
    $http=new ConcurrentHttp($site);$initial=$http->run([['user'=>0,'id'=>'a'],['user'=>1,'id'=>'b']]);$jobs=[];
    foreach($initial as $i=>$r)$jobs[]=['user'=>$i,'id'=>'begin-'.$i,'cookie'=>$r['cookie'],'post'=>concurrent_form($r['html'],['start'=>'1'],['nickname'=>'Isolation '.$i,'ennea'=>'en tiedä','invite_code'=>''])];
    $start=$http->run($jobs);$s=json_decode(file_get_contents($site->dir.'/data/state-'.$start[0]['cookie'].'.json'),true);$q=$s['phase1'][0];$post=concurrent_form($start[0]['html'],['choice'=>'6']);$jobs=[];
    for($i=0;$i<10;$i++)$jobs[]=['user'=>$i,'worker'=>$i%3,'id'=>'same-session-'.$i,'cookie'=>$start[0]['cookie'],'post'=>$post];$http->run($jobs);
    $s=json_decode(file_get_contents($site->dir.'/data/state-'.$start[0]['cookie'].'.json'),true);verify($s['q1_index']===1&&count($s['answers1_by_id'])===1&&$s['points1'][$q['class']]===6,'Only one simultaneous answer advances');
    $otherBefore=file_get_contents($site->dir.'/data/state-'.$start[1]['cookie'].'.json');
    $http->run([['user'=>0,'id'=>'reset','cookie'=>$start[0]['cookie'],'path'=>'/index.php?reset=1'],['user'=>1,'id'=>'other','cookie'=>$start[1]['cookie']]]);
    verify(file_get_contents($site->dir.'/data/state-'.$start[1]['cookie'].'.json')===$otherBefore,'Reset does not mutate another participant');return ['simultaneous_posts'=>10,'http_requests'=>$http->requests];
});
failure_case('simultaneous-completion-and-lost-session-checkpoint',function($site){
    [$u,$http]=failure_prepare($site);$sessionFile=$site->dir.'/data/sess_'.$u->cookie;$before=file_get_contents($sessionFile);$job=$u->next();$jobs=[];
    for($i=0;$i<10;$i++)$jobs[]=array_replace($job,['user'=>$i,'worker'=>$i%3,'id'=>'duplicate-final-'.$i]);$responses=$http->run($jobs);
    foreach($responses as $r)verify($r['status']===200&&str_contains($r['html'],'TYYLI 4'),'Duplicate simultaneous completion returns consistent result');
    verify(count(research_rows($site->dir))===1&&count(file($site->dir.'/data/emails.jsonl'))===1,'One row and notification');
    file_put_contents($sessionFile,$before);$http->run([$job]);verify(count(research_rows($site->dir))===1&&count(file($site->dir.'/data/emails.jsonl'))===1,'CSV idempotency survives lost session checkpoint');
    return ['simultaneous_posts'=>10,'rows'=>1,'notifications'=>1,'http_requests'=>$http->requests];
});
failure_case('conflicting-checkpoint-replay-cannot-change-research',function($site){
    [$u,$http]=failure_prepare($site);$sessionFile=$site->dir.'/data/sess_'.$u->cookie;$before=file_get_contents($sessionFile);[$r,$job]=failure_final($u,$http);
    $path=$site->dir.'/data/survey_log_'.date('Y-m').'.csv';$hash=hash_file('sha256',$path);file_put_contents($sessionFile,$before);
    $job['post']['choice2_value']=$job['post']['choice2_value']==='2'?'1':'2';$job['post']['choice2_side']='left';$r=$http->run([$job])[4];
    verify(str_contains($r['html'],'teknisen ongelman vuoksi'),'Conflicting duplicate is explicit technical failure');verify(hash_file('sha256',$path)===$hash,'Committed research result immutable');verify(count(file($site->dir.'/data/emails.jsonl'))===1,'No repeated notification');return ['original_row_unchanged'=>true];
});
failure_case('lock-wait-and-delayed-writer',function($site){
    [$u,$http]=failure_prepare($site);$path=$site->dir.'/data/survey_log_'.date('Y-m').'.csv.lock';$holder=failure_holder($site,$path,600000);$started=microtime(true);
    try{[$r]=failure_final($u,$http);$elapsed=microtime(true)-$started;verify($r['status']===200&&str_contains($r['html'],'TYYLI 4'),'Temporary lock contention completes');verify($elapsed>.4,'Actual wait observed');verify(count(research_rows($site->dir))===1,'Locked completion saved once');
        $wait=0;foreach(file($site->dir.'/data/lock-waits.jsonl') as $line){$v=json_decode($line,true);if(str_contains($v['path'],'survey_log_'))$wait=max($wait,$v['wait_seconds']);}verify($wait>.4,'Instrumented actual storage lock wait');
        return ['held_seconds'=>.6,'observed_storage_lock_wait_seconds'=>$wait,'observed_final_roundtrip_seconds'=>$elapsed];}
    finally{proc_terminate($holder);proc_close($holder);}
});
failure_case('bounded-lock-timeout',function($site){
    [$u,$http]=failure_prepare($site);$holder=failure_holder($site,$site->dir.'/data/survey_log_'.date('Y-m').'.csv.lock',5500000);$started=microtime(true);
    try{[$r]=failure_final($u,$http);$elapsed=microtime(true)-$started;failure_technical($r,$site,$u);verify($elapsed>3.8&&$elapsed<6,'Bounded four-second lock timeout');return ['observed_final_roundtrip_seconds'=>$elapsed];}
    finally{proc_terminate($holder);proc_close($holder);}
});
failure_case('publish-retries-temporary-windows-reader',function($site){
    [$first,$http]=failure_prepare($site,0);failure_final($first,$http);[$u,$http]=failure_prepare($site);
    $holder=failure_holder($site,$site->dir.'/data/survey_log_'.date('Y-m').'.csv',600000,'open');$started=microtime(true);
    try{[$r]=failure_final($u,$http);verify($r['status']===200&&str_contains($r['html'],'TYYLI 4'),'Temporary Windows sharing denial retried safely');verify(count(research_rows($site->dir))===2,'Both old and new rows retained');return ['roundtrip_seconds'=>microtime(true)-$started];}
    finally{proc_terminate($holder);proc_close($holder);}
});
failure_case('persistent-output-sharing-denied',function($site){
    [$first,$http]=failure_prepare($site,0);failure_final($first,$http);[$u,$http]=failure_prepare($site);
    $path=$site->dir.'/data/survey_log_'.date('Y-m').'.csv';$hash=hash_file('sha256',$path);$holder=failure_holder($site,$path,5500000,'open');
    try{[$r]=failure_final($u,$http);verify(str_contains($r['html'],'teknisen ongelman vuoksi'),'Persistent sharing denial is technical');verify(hash_file('sha256',$path)===$hash,'Existing research bytes untouched');
        verify(count(research_rows($site->dir))===1&&count(file($site->dir.'/data/emails.jsonl'))===1,'No failed row/notification');return ['existing_rows'=>1,'new_rows'=>0];}
    finally{proc_terminate($holder);proc_close($holder);}
});
failure_case('invitation-creation-versus-status-updates',function($site){
    $http=new ConcurrentHttp($site);$jobs=[];for($i=0;$i<10;$i++){
        $jobs[]=['user'=>$i,'id'=>'create-'.$i,'path'=>'/invite_probe.php?id='.$i];
        $jobs[]=['user'=>$i+10,'id'=>'complete-other-'.$i,'path'=>'/probe.php?op=status&id=load-'.$i];
    }$responses=$http->run($jobs);foreach($responses as $r){$decoded=json_decode($r['html'],true);verify($r['status']===200&&is_array($decoded)&&($decoded['ok']??null)===true,'Actual invite.php append/status API succeeded: '.substr(strip_tags($r['html']),0,250).' '.json_encode($site->diagnostics()));}
    $items=json_decode(file_get_contents($site->dir.'/data/invites.json'),true,512,JSON_THROW_ON_ERROR);verify(count($items)===40,'No invitation creation/update lost');
    $completed=count(array_filter($items,fn($v)=>$v['status']==='completed'));verify($completed===10,'All concurrent completions retained');return ['new_invitations'=>10,'completed_existing'=>10,'total'=>40,'full_creation_form_tested'=>false];
});
foreach(['csv-short','json-short'] as $mode)failure_case('partial-write-loop-'.$mode,function($site)use($mode){
    [$u,$http]=failure_prepare($site);[$r]=failure_final($u,$http,'write_fault='.$mode);verify($r['status']===200&&str_contains($r['html'],'TYYLI 4'),'Short writes loop to completion');verify(count(research_rows($site->dir))===1,'Complete row');
    verify(json_decode(file_get_contents($site->dir.'/data/invites.json'),true)[4]['status']==='completed','Complete valid JSON');return ['rows'=>1];
});
failure_case('simulated-disk-write-failure',function($site){[$u,$http]=failure_prepare($site);[$r]=failure_final($u,$http,'write_fault=csv-disk');failure_technical($r,$site,$u);verify(glob($site->dir.'/data/*.tmp.*')===[],'Failed temporary file cleaned');return ['research_rows'=>0];});
failure_case('invitation-update-failure-and-reconciliation',function($site){
    [$u,$http]=failure_prepare($site);[$r]=failure_final($u,$http,'write_fault=json-disk');failure_technical($r,$site,$u,true);
    $http->run([['user'=>4,'id'=>'reconcile','cookie'=>$u->cookie,'path'=>'/probe.php?op=reconcile']]);
    verify(json_decode(file_get_contents($site->dir.'/data/invites.json'),true)[4]['status']==='completed','Saved result can be reconciled without new CSV row');verify(count(research_rows($site->dir))===1&&count(file($site->dir.'/data/emails.jsonl'))===1,'Reconciliation does not duplicate result or notification');return ['csv_saved_before_invitation_failure'=>true,'operator_reconciliation_required'=>true];
});
failure_case('invalid-invitation-json-does-not-overwrite',function($site){
    file_put_contents($site->dir.'/data/invites.json','{invalid');$http=new ConcurrentHttp($site);$first=$http->run([['user'=>0,'id'=>'bad-json']])[0];
    $last=$http->run([['user'=>0,'id'=>'bad-json-post','cookie'=>$first['cookie'],'post'=>concurrent_form($first['html'],['start'=>'1'],['nickname'=>'Invalid JSON','ennea'=>'en tiedä','invite_code'=>'LOAD0'])]])[0];
    verify(str_contains($last['html'],'Tekninen virhe'),'Storage error does not look like a valid result');verify(file_get_contents($site->dir.'/data/invites.json')==='{invalid','Corrupt storage not replaced with empty data');return ['original_bytes_preserved'=>true];
});
failure_case('expired-token-and-monotonic-completed-status',function($site){
    $items=concurrent_invites(2);$items[0]['expires_at']='2000-01-01';$items[1]['status']='accepted';file_put_contents($site->dir.'/data/invites.json',json_encode($items));$http=new ConcurrentHttp($site);$jobs=[];
    for($i=0;$i<10;$i++)$jobs[]=['user'=>$i,'id'=>'expired-'.$i,'path'=>'/index.php?invite=token-0'];$responses=$http->run($jobs);
    foreach($responses as $r){$s=json_decode(file_get_contents($site->dir.'/data/state-'.$r['cookie'].'.json'),true);verify(empty($s['InviteId']),'Expired token cannot be claimed');}
    $jobs=[];for($i=0;$i<10;$i++)$jobs[]=['user'=>$i,'id'=>'status-race-'.$i,'path'=>'/probe.php?op=status&id=load-1&status='.($i===0?'completed':'accepted')];$http->run($jobs);
    $items=json_decode(file_get_contents($site->dir.'/data/invites.json'),true);verify($items[0]['status']==='sent'&&$items[1]['status']==='completed','Expired unchanged; completed never reverts');return ['expired_attempts'=>10,'concurrent_status_changes'=>10];
});
failure_case('same-token-single-owner-and-owner-replay',function($site){
    $http=new ConcurrentHttp($site);$jobs=[];for($i=0;$i<10;$i++)$jobs[]=['user'=>$i,'id'=>'token-claim-'.$i,'path'=>'/index.php?invite=token-0'];$responses=$http->run($jobs);$owners=[];
    foreach($responses as $r){$s=json_decode(file_get_contents($site->dir.'/data/state-'.$r['cookie'].'.json'),true);if(!empty($s['InviteId']))$owners[]=$r;}
    verify(count($owners)===1,'Single token owner across independent sessions');$owner=$owners[0];$last=$http->run([['user'=>0,'id'=>'owner-replay','cookie'=>$owner['cookie'],'path'=>'/index.php?invite=token-0']])[0];
    $s=json_decode(file_get_contents($site->dir.'/data/state-'.$last['cookie'].'.json'),true);verify(($s['InviteId']??'')==='load-0','Owner can resume accepted token');return ['owners'=>1];
});
failure_case('completed-invitation-rejects-other-session-status-call',function($site){
    $http=new ConcurrentHttp($site);$owner=$http->run([['user'=>0,'id'=>'owner','path'=>'/index.php?invite=token-0']])[0];
    $http->run([['user'=>0,'id'=>'owner-complete','cookie'=>$owner['cookie'],'path'=>'/probe.php?op=status&id=load-0']]);
    $other=$http->run([['user'=>1,'id'=>'other-complete','path'=>'/probe.php?op=status&id=load-0']])[1];
    verify(json_decode($other['html'],true)['ok']===false,'Completed invitation still verifies session ownership');return ['foreign_status_call_rejected'=>true];
});
foreach(['session_start_fault','session_fault'] as $fault)failure_case($fault,function($site)use($fault){
    $http=new ConcurrentHttp($site);$r=$http->run([['user'=>0,'id'=>$fault,'path'=>'/index.php?'.$fault.'=1']])[0];
    verify($r['status']===503&&str_contains($r['html'],'Tekninen virhe'),'Failed session persistence reports HTTP 503');verify(research_rows($site->dir)===[],'No result generated');return ['http_status'=>503];
});
failure_case('session-write-failure-after-result-is-idempotent',function($site){
    [$u,$http]=failure_prepare($site);[$r,$job]=failure_final($u,$http,'session_fault=1');verify($r['status']===503&&str_contains($r['html'],'Tekninen virhe'),'Late session-write failure visible');verify(count(research_rows($site->dir))===1,'Research result retained');
    $job['path']='/index.php';$r=$http->run([$job])[4];verify($r['status']===200&&str_contains($r['html'],'TYYLI 4'),'Original final POST can safely recover');verify(count(research_rows($site->dir))===1&&count(file($site->dir.'/data/emails.jsonl'))===1,'No duplicate after failed session write');return ['retained_rows'=>1];
});
foreach(['csv','json'] as $kind)failure_case('kill-owned-writer-during-'.$kind.'-write',function($site)use($kind){
    [$u,$http]=failure_prepare($site);$beforeJson=file_get_contents($site->dir.'/data/invites.json');$job=$u->next();$job['worker']=1;$job['path']='/index.php?write_fault='.$kind.'-pause';$killed=false;$owned=$site->workers[1]['process'];
    try{$http->run([$job],null,40,function()use($site,&$killed,$owned){if(!$killed&&is_file($site->dir.'/data/writer-paused')){$killed=true;proc_terminate($owned);}});throw new RuntimeException('Killed request unexpectedly returned a result');}
    catch(RuntimeException $e){verify($killed,'Only the identified owned worker was terminated');}
    if(is_resource($owned))proc_close($owned);array_splice($site->workers,1,1);
    verify(file_get_contents($site->dir.'/data/invites.json')===$beforeJson,'Interrupted JSON publication leaves original valid file');
    verify(count(research_rows($site->dir))===($kind==='csv'?0:1),'Interrupted CSV publication never exposes a partial row');
    $job['worker']=0;$job['path']='/index.php';$r=$http->run([$job])[4];verify($r['status']===200&&str_contains($r['html'],'TYYLI 4'),'Retry recovers after killed writer');verify(count(research_rows($site->dir))===1&&count(file($site->dir.'/data/emails.jsonl'))===1,'Recovered once');return ['retained_rows_at_interrupt'=>$kind==='csv'?0:1,'recovered_rows'=>1,'orphan_temporary_files'=>count(glob($site->dir.'/data/*.tmp.*'))];
});

foreach($argv as $arg)if(str_starts_with($arg,'--report='))file_put_contents(substr($arg,9),json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL);
$bad=count(array_filter($summary['probes'],fn($r)=>!$r['passed']));echo 'FAILURE SCENARIOS: '.(count($summary['probes'])-$bad).' passed, '.$bad." failed\n";exit($bad?1:0);
