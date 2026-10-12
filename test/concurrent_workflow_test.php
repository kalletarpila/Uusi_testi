<?php declare(strict_types=1);
require_once __DIR__.'/concurrent_support.php';
$summary=['initial_head'=>'03d4447d640eeb2e339ae1a34d8ae4ff136e5640','profiles'=>[],'probes'=>[]];
function concurrent_probe(string $name,callable $test):void {
    global $summary;
    try{$detail=$test();$summary['probes'][$name]=['passed'=>true,'detail'=>$detail];echo "PASS $name\n";}
    catch(Throwable $e){$summary['probes'][$name]=['passed'=>false,'error'=>$e->getMessage()];echo "FAIL $name: ".$e->getMessage()."\n";}
}
function concurrent_invites(int $n):array {
    $out=[];for($i=0;$i<$n;$i++)$out[]=['id'=>'load-'.$i,'code'=>'LOAD'.$i,'token'=>'token-'.$i,'status'=>'sent','expires_at'=>'2099-01-01 00:00:00','guess_type'=>'4','guess_prob'=>'quite_certain'];return $out;
}
function concurrent_storage_probes():void {
    $site=new ConcurrentSite(10);
    try {
        concurrent_probe('append-position-under-lock',function()use($site){
            $http=new ConcurrentHttp($site);$jobs=[];for($i=0;$i<10;$i++)$jobs[]=['user'=>$i,'id'=>'append-'.$i,'path'=>'/probe.php?op=append&_append_barrier=1&tag='.str_pad((string)$i,4,'0',STR_PAD_LEFT)];
            $http->run($jobs);$lines=file($site->dir.'/data/probe.txt',FILE_IGNORE_NEW_LINES);
            verify(count($lines)===10&&count(array_unique($lines))===10,'Concurrent append lost/overwrote records: '.count($lines));return concurrent_overlap($site->dir);
        });
        concurrent_probe('invitation-transaction-no-lost-update',function()use($site){
            file_put_contents($site->dir.'/data/invites.json',json_encode(concurrent_invites(10)));
            $http=new ConcurrentHttp($site);$jobs=[];for($i=0;$i<10;$i++)$jobs[]=['user'=>$i,'id'=>'status-'.$i,'path'=>'/probe.php?op=status&_json_barrier=1&id=load-'.$i];
            $http->run($jobs);$items=json_decode(file_get_contents($site->dir.'/data/invites.json'),true,512,JSON_THROW_ON_ERROR);
            $completed=count(array_filter($items,fn($i)=>$i['status']==='completed'));verify($completed===10,'Lost invitation updates: only '.$completed.'/10 completed');return ['completed'=>$completed];
        });
        concurrent_probe('single-first-writer-header',function()use($site){
            $http=new ConcurrentHttp($site);$jobs=[];for($i=0;$i<10;$i++)$jobs[]=['user'=>$i,'id'=>'header-'.$i,'path'=>'/probe.php?op=header&_header_barrier=1'];
            $http->run($jobs);$raw=file_get_contents($site->dir.'/data/survey_log_'.date('Y-m').'.csv');
            verify(substr_count($raw,'session_id;nick')===1,'Duplicate/missing CSV header');return ['header_count'=>substr_count($raw,'session_id;nick')];
        });
        concurrent_probe('same-invitation-single-session-owner',function()use($site){
            file_put_contents($site->dir.'/data/invites.json',json_encode(concurrent_invites(1)));$http=new ConcurrentHttp($site);$jobs=[];
            for($i=0;$i<10;$i++)$jobs[]=['user'=>$i,'id'=>'claim-'.$i,'path'=>'/index.php'];
            $first=$http->run($jobs);$jobs=[];foreach($first as $i=>$r)$jobs[]=['user'=>$i,'id'=>'claim-post-'.$i,'cookie'=>$r['cookie'],
                'post'=>concurrent_form($r['html'],['start'=>'1'],['nickname'=>'Claim '.$i,'ennea'=>'en tiedä','invite_code'=>'LOAD0'])];
            $last=$http->run($jobs);$claimed=0;foreach($last as $r){$s=json_decode(file_get_contents($site->dir.'/data/state-'.$r['cookie'].'.json'),true);if(($s['state']??'')==='quiz1')$claimed++;}
            verify($claimed===1,'Independent sessions claimed same invitation: '.$claimed);return ['owners'=>$claimed];
        });
    }finally{$site->close();}
}

final class LoadParticipant {
    public string $cookie='', $html='';public array $s=[], $expected1=[], $expected2=[], $seen1=[], $seen2=[], $seenTb=[];
    public bool $finished=false, $ready=false;public int $turn=0;public string $track,$winner,$type,$code,$pair,$nick;public int $variant;
    public function __construct(public int $index, public ConcurrentSite $site){
        $groups=['A2'=>['4','7','9'],'B2'=>['3','7','8'],'C2'=>['1','5','6'],'D2'=>['2','6','9']];$this->track=array_keys($groups)[$index%4];$this->winner=$this->track[0];
        $this->variant=intdiv($index,4)%6;$this->type=in_array($this->variant,[2,5],true)?'RATKAISEMATON':$groups[$this->track][0];
        $this->code=in_array($this->variant,[1,3,4,5],true)?'LOAD'.$index:'';$this->nick='Kuorma Ää; '.$index;
        $offset=$this->variant===2?2:($this->variant===3?3:1);$this->pair=$this->winner.'ABCD'[($index%4+$offset)%4];
        $this->expected1=array_fill_keys(['A1','B1','C1','D1'],0);$this->expected2=array_fill_keys($groups[$this->track],0);
    }
    public function first(float $release):array {return ['user'=>$this->index,'id'=>'user-'.$this->index.'-0','path'=>'/index.php'.($this->variant===3?'?oldTB1=1':''),'release'=>$release];}
    public function accept(array $r):void {
        verify($r['status']===200,'Successful participant HTTP response');verify(!preg_match('/(?:Warning|Fatal error|Notice):/',$r['html']),'No participant PHP diagnostics');
        if($this->cookie!=='')verify($r['cookie']===$this->cookie,'Session identity remains stable');$this->cookie=$r['cookie'];verify($this->cookie!=='','Actual PHP session cookie');$this->html=$r['html'];
        $this->s=json_decode(file_get_contents($this->site->dir.'/data/state-'.$this->cookie.'.json'),true,512,JSON_THROW_ON_ERROR);
        if(isset($this->s['points1']))verify($this->s['points1']===$this->expected1,'Independent latest Phase1 scores');
        if(isset($this->s['varPoints']))verify($this->s['varPoints']===$this->expected2,'Independent latest Phase2 scores');
    }
    public function isFinal():bool {
        $s=$this->s;$state=$s['state']??'';
        if($state==='phase2'&&!in_array($this->variant,[2,4,5],true))return $s['q2_index']===count($s['blocks2'])-1;
        if($state==='phase2_tb')return $s['tb2_index']===count($s['tb2_set'])-1;
        return false;
    }
    public function next():?array {
        $s=$this->s;$state=$s['state']??'';$post=[];
        if($state==='intro')$post=concurrent_form($this->html,['start'=>'1'],['nickname'=>$this->nick,'ennea'=>'en tiedä','invite_code'=>$this->code]);
        elseif($state==='quiz1'){
            $q=$s['phase1'][$s['q1_index']];verify(str_contains($this->html,htmlspecialchars($q['text'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')),'Actual Phase1 question shown');
            verify(!isset($this->seen1[$q['id']]),'No repeated ordinary question');$this->seen1[$q['id']]=true;
            $value=in_array($this->variant,[1,2,3],true)?(str_contains($this->pair,$q['class'][0])?4:3):($q['class']===$this->winner.'1'?6:1);
            $post=concurrent_form($this->html,['choice'=>$value]);$this->expected1[$q['class']]+=$value;
        }elseif(in_array($state,['tb1_new_intro','tiebreak_intro','phase2_intro'],true)){
            if($state==='phase2_intro')verify($s['track']===$this->track,'Correct Phase2 track after Phase1');
            $action=match($state){'tb1_new_intro'=>'continue_tb1_new','tiebreak_intro'=>'continue_tiebreak',default=>'continue_phase2'};$post=concurrent_form($this->html,[$action=>'1']);
        }elseif($state==='tb1_new'){
            $q=$s['tb1_new_questions'][$s['tb1_new_index']];verify(str_contains($this->html,htmlspecialchars($q['statement'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')),'TB1 statement shown');
            $post=concurrent_form($this->html,['tb1_new_choice'=>$this->winner]);
        }elseif($state==='tiebreak'){
            $q=$s['tiebreak_set'][$s['tiebreak_index']];verify(str_contains($this->html,htmlspecialchars($q['text'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')),'Legacy statement shown');
            $post=concurrent_form($this->html,['tb_choice'=>$q['class']===$this->winner.'1'?6:1]);
        }elseif(in_array($state,['phase2','phase2_tb'],true)){
            $tb=$state==='phase2_tb';$q=$s[$tb?'tb2_set':'blocks2'][$s[$tb?'tb2_index':'q2_index']];
            $key=($tb?'tb-':'normal-').$q['question_number'];verify(!isset($this->seen2[$key]),'No repeated Phase2 question');$this->seen2[$key]=true;
            verify(str_contains($this->html,htmlspecialchars($q['desc'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')),'Actual Phase2 statement shown');
            $neutral=$this->type==='RATKAISEMATON'||(!$tb&&$this->variant===4);
            [$side,$value]=$neutral?['neutral',0]:favor($this->type)($q);$prefix=$tb?'tb2':'choice2';
            $post=concurrent_form($this->html,[$prefix.'_value'=>$value,$prefix.'_side'=>$side]);if($value)$this->expected2[$q[$side.'_var']]+=$value;
        }elseif($state==='done2'){
            verify($s['track']===$this->track&&(string)$s['log_row']['finalVar']===$this->type,'Expected final result');verify(count($this->seen1)===36,'Every ordinary question completed');
            verify(count(array_filter(array_keys($this->seen2),fn($k)=>str_starts_with($k,'normal-')))===18,'Every standard Phase2 question completed');
            if($this->type==='RATKAISEMATON')verify(str_contains($this->html,'Testi ei pystynyt erottamaan yhtä enneagrammityyliä.'),'Unresolved Finnish result');
            else verify(str_contains($this->html,'TYYLI '.$this->type),'Actual normal result page');
            $this->finished=true;return null;
        }else throw new RuntimeException('Unexpected load workflow state: '.$state);
        return ['user'=>$this->index,'id'=>'user-'.$this->index.'-'.(++$this->turn),'worker'=>($this->index+$this->turn)%count($this->site->workers),'cookie'=>$this->cookie,'post'=>$post];
    }
}

function concurrent_validate_results(ConcurrentSite $site,array $users):array {
    $rows=research_rows($site->dir);verify(count($rows)===count($users),'Exactly one complete CSV row per participant');$byId=[];
    foreach($rows as $row){verify(!isset($byId[$row['session_id']]),'Unique CSV session IDs');$byId[$row['session_id']]=$row;verify(mb_check_encoding(implode('',$row),'UTF-8'),'CSV UTF-8');}
    $invites=json_decode(file_get_contents($site->dir.'/data/invites.json'),true,512,JSON_THROW_ON_ERROR);$ids=[];$invited=0;$unresolved=0;
    foreach($users as $u){verify($u->finished&&!isset($ids[$u->cookie]),'Independent completed session');$ids[$u->cookie]=true;$row=$byId[$u->cookie]??null;verify($row!==null&&$row['nick']===$u->nick&&$row['final_type']===$u->type,'CSV participant/result mapping');
        foreach($u->expected1 as $g=>$v)verify((int)$row[$g.'_points']===$v,'CSV Phase1 exact score');
        foreach($u->s['answers1'] as $i=>$a)verify($row['Q'.($i+1)]===$a['class'].'-'.$a['question_number'].'-'.$a['value'],'CSV ordinary answer attribution');
        foreach($u->s['pairs2'] as $i=>$a)verify($row['Case'.($i+1)]===$a,'CSV Phase2 attribution');foreach($u->s['tb2_pairs']??[] as $i=>$a)verify($row['TB2_'.($i+1)]===$a,'CSV TB2 attribution');
        foreach($u->s['tb1_new_answers']??[] as $i=>$a)verify($row['TB1_'.($i+1)]===$a,'CSV TB1 attribution');
        foreach($u->s['tiebreak_log']??[] as $i=>$a)verify($row['TB1_OLD_'.($i+1)]===$a['class'].'-'.$a['question_number'].'-'.$a['value'],'CSV legacy attribution');
        foreach(['A','B','C','D'] as $g)verify((int)$row[$g.'1_tb1_counter']===(int)($u->s['tb1_new_counters'][$g]??0),'CSV TB1 counter');
        if($u->code!==''){verify($invites[$u->index]['status']==='completed','Distinct invitation completed, no lost status');verify($row['invite_id']==='load-'.$u->index,'Correct invitation attached');$invited++;}
        else verify($row['invite_id']==='','No other participant invitation');
        if($u->type==='RATKAISEMATON')$unresolved++;
    }
    $audit=[];foreach(file($site->dir.'/data/index_app_'.date('Y-m').'.log') as $line){verify((bool)preg_match('/^\[\d{4}-\d\d-\d\d .*\]\[.*\] /',$line),'Complete application log line');if(preg_match('/RESULT: (\{.*\})/',$line,$m)){$r=json_decode($m[1],true,512,JSON_THROW_ON_ERROR);$audit[$r['session_id']][]=$r;}}
    foreach($users as $u){verify(count($audit[$u->cookie]??[])===1,'One complete result audit record');verify($audit[$u->cookie][0]['scores']===$u->expected2,'Audit final scores isolated');}
    $emails=file($site->dir.'/data/emails.jsonl');verify(count($emails)===count($users),'Exactly one stub notification per completion');foreach($emails as $line)json_decode($line,true,512,JSON_THROW_ON_ERROR);
    verify($site->diagnostics()===[],'No worker PHP errors/warnings');
    return ['csv_rows'=>count($rows),'unique_sessions'=>count($ids),'invited_completed'=>$invited,'unresolved_completed'=>$unresolved,'stub_notifications'=>count($emails),'php_diagnostics'=>0];
}
function concurrent_profile(int $count,string $pattern):array {
    global $checks;
    $site=new ConcurrentSite(min($count,30));$before=$checks;$started=microtime(true);$http=new ConcurrentHttp($site);$users=[];$jobs=[];$released=$pattern!=='burst';
    try{
        file_put_contents($site->dir.'/data/invites.json',json_encode(concurrent_invites($count)));
        for($i=0;$i<$count;$i++){$users[$i]=new LoadParticipant($i,$site);$jobs[]=$users[$i]->first($started+($pattern==='ramp'?$i*.02:0));}
        $http->run($jobs,function($r)use(&$users,&$released,$pattern){
            $u=$users[$r['user']];$u->accept($r);
            if(!$released&&$u->isFinal()){
                $u->ready=true;$all=true;foreach($users as $x)if(!$x->ready){$all=false;break;}
                if(!$all)return [];$released=true;$jobs=[];foreach($users as $x){$job=$x->next();if($job)$jobs[]=$job;}return $jobs;
            }
            $job=$u->next();return $job?[$job]:[];
        },max(180,$count*3));
        $integrity=concurrent_validate_results($site,$users);$elapsed=microtime(true)-$started;$overlap=concurrent_overlap($site->dir);
        verify($count===1||$overlap['maximum_overlapping_php_requests']>1,'Real overlapping PHP execution');
        return ['participants'=>$count,'pattern'=>$pattern,'workers'=>min($count,30),'completed'=>$count,'workflow_failures'=>0,'assertions'=>$checks-$before,'integrity'=>$integrity,'overlap'=>$overlap,
            'completed_per_minute'=>60*$count/$elapsed,'http'=>$http->metrics($elapsed),'passed'=>true];
    }catch(Throwable $e){
        $technical=[];foreach(glob($site->dir.'/data/index_app_*.log') as $f)foreach(file($f) as $line)if(str_contains($line,'TECHNICAL_ERROR:')||str_contains($line,'FILE_IO'))$technical[]=trim($line);
        return ['participants'=>$count,'pattern'=>$pattern,'completed'=>count(array_filter($users,fn($u)=>$u->finished)),
        'workflow_failures'=>count(array_filter($users,fn($u)=>($u->s['state']??'')==='phase2_unresolved')),
        'aborted_workflows'=>count(array_filter($users,fn($u)=>!$u->finished&&($u->s['state']??'')!=='phase2_unresolved')),
        'http'=>$http->metrics(microtime(true)-$started),'passed'=>false,'error'=>$e->getMessage(),'technical_errors'=>$technical,'diagnostics'=>$site->diagnostics()];}
    finally{$site->close();}
}

if(defined('CONCURRENT_WORKFLOW_LIBRARY'))return;
try{
    if(in_array('--probes',$argv,true))concurrent_storage_probes();
    else{
        $levels=[1,10,30,50,100,200];foreach($argv as $arg)if(str_starts_with($arg,'--levels='))$levels=array_map('intval',explode(',',substr($arg,9)));
        foreach($levels as $level)if(!in_array($level,[1,10,30,50,100,200],true))throw new InvalidArgumentException('Use bounded profile levels 1,10,30,50,100,200');
        $stable=true;foreach($levels as $count)foreach(['ramp','burst'] as $pattern){
            if(!$stable){$summary['profiles'][]=['participants'=>$count,'pattern'=>$pattern,'executed'=>false,'reason'=>'Previous level unstable'];echo "NOT EXECUTED $count $pattern\n";continue;}
            echo "RUN $count $pattern\n";$r=concurrent_profile($count,$pattern);$summary['profiles'][]=$r;
            echo ($r['passed']?'PASS':'FAIL')." $count $pattern ".json_encode($r,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";$stable=$r['passed'];
        }
    }
}finally{
    foreach($argv as $arg)if(str_starts_with($arg,'--report='))file_put_contents(substr($arg,9),json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL);
}
$bad=count(array_filter($summary['profiles'],fn($r)=>isset($r['passed'])&&!$r['passed']))+count(array_filter($summary['probes'],fn($r)=>!$r['passed']));
exit($bad?1:0);
