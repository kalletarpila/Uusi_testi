<?php declare(strict_types=1);
define('HTTP_WORKFLOW_LIBRARY', true);
require_once __DIR__.'/http_workflow_test.php';
require_once __DIR__.'/phase2_answer_plan.php';

$matrix = ['initial_head'=>trim(shell_exec('git -c safe.directory="'.str_replace('\\','/',$root).'" rev-parse HEAD')),
    'source_sha256'=>hash_file('sha256',$root.'/index.php'), 'kind'=>'real HTTP / PHP sessions / rendered forms', 'cases'=>[]];
$matrixPath=__DIR__.'/PHASE2_NAVIGATION_HTTP.json';
foreach($argv as $arg)if(str_starts_with($arg,'--matrix='))$matrixPath=substr($arg,9);
$sandbox=create_isolated_app($root,true);
file_put_contents($sandbox.'/fixture.php', '<?php session_start(); if(isset($_POST["patch"]))foreach(json_decode($_POST["patch"],true) as $k=>$v){if($v===null)unset($_SESSION[$k]);else $_SESSION[$k]=$v;} echo "fixture";');
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);$address=stream_socket_get_name($socket,false);fclose($socket);$base='http://'.$address;
$process=proc_open([PHP_BINARY,'-d','session.save_path='.$sandbox.'/data','-S',$address,'-t',$sandbox],[0=>['pipe','r'],1=>['file',$sandbox.'/server.log','a'],2=>['file',$sandbox.'/server.log','a']],$pipes,$sandbox);fclose($pipes[0]);
for($i=0;$i<60;$i++){$probe=@fsockopen('127.0.0.1',(int)substr(strrchr($address,':'),1),$errno,$error,0.1);if($probe){fclose($probe);break;}usleep(50000);}

function p2case(string $name,string $track,string $category,callable $test,bool $fault=false):void {
    global $matrix,$matrixPath,$checks,$requests,$failed,$match,$skipped;
    if($match!==null&&!preg_match('~'.$match.'~',$name)){$skipped++;return;}
    $c=$checks;$r=$requests;$f=$failed;
    scenario($name,function()use($test){$test();});
    $matrix['cases'][]=['name'=>$name,'track'=>$track,'category'=>$category,'fixture_injection'=>$fault,'passed'=>$f===$failed,'assertions'=>$checks-$c,'requests'=>$requests-$r];
    file_put_contents($matrixPath,json_encode($matrix,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL);
}
function p2form(Participant $p,string $name,string $value,?string $side=null):array {
    $dom=new DOMDocument();@$dom->loadHTML('<?xml encoding="UTF-8">'.$p->html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);$xp=new DOMXPath($dom);
    foreach($xp->query('//form') as $form){$post=[];foreach($xp->query('.//input[@name] | .//button[@name]',$form) as $el)$post[$el->getAttribute('name')]=$el->getAttribute('value');
        if(($post[$name]??null)!==$value||($side!==null&&($post[str_replace('_value','_side',$name)]??null)!==$side))continue;
        return $post;
    }
    throw new RuntimeException('Rendered form missing '.$name.'='.$value.' side='.$side.' state='.($p->s['state']??''));
}
class Phase2Participant extends Participant {
    public array $phase1Baseline=[], $order=[];
    public string $invitation='';
    public function open(string $track,string $tb1='',bool $invited=false):void {
        if($invited){$this->invitation='p2-'.bin2hex(random_bytes(5));$items=json_decode(file_get_contents($this->dir.'/data/invites.json'),true);$items[]=['id'=>$this->invitation,'code'=>$this->invitation,'status'=>'sent','expires_at'=>'2099-01-01 00:00:00'];file_put_contents($this->dir.'/data/invites.json',json_encode($items));}
        $this->begin('P2 navigation Ää',$this->invitation,$tb1==='legacy'?'oldTB1=1':'');
        $winner=$track[0].'1';$other=$winner==='A1'?'B1':'A1';$values=array_fill_keys(['A1','B1','C1','D1'],1);$values[$winner]=$tb1?4:6;if($tb1)$values[$other]=4;
        if($tb1){$this->submit(['choice'=>(string)$values[$this->s['phase1'][0]['class']]]);$first=$this->s['points1'];$this->submit(['navigate'=>'prev']);verify($this->s['q1_index']===0,'Phase1 previous before Phase2');$this->submit(['navigate'=>'next']);verify($this->s['points1']===$first,'Phase1 review does not duplicate');}
        while($this->s['state']==='quiz1'){$q=$this->s['phase1'][$this->s['q1_index']];$this->submit(['choice'=>(string)$values[$q['class']]]);}
        if($tb1){$legacy=$tb1==='legacy';$this->submit([$legacy?'continue_tiebreak':'continue_tb1_new'=>'1']);
            while(in_array($this->s['state'],['tiebreak','tb1_new'],true)){
                if($legacy){$q=$this->s['tiebreak_set'][$this->s['tiebreak_index']];$this->submit(['tb_choice'=>$q['class']===$winner?'6':'1']);}
                else $this->submit(['tb1_new_choice'=>$track[0]]);
            }
        }
        verify($this->s['state']==='phase2_intro'&&$this->s['track']===$track,'Actual Phase1 -> requested track');
        foreach(['points1','answers1','answers1_by_id','yesCounts1','topClass','tb1_new_answers','tiebreak_answers'] as $key)$this->phase1Baseline[$key]=$this->s[$key]??null;
        $this->order=$this->s['blocks2'];$this->post('continue_phase2','1');
    }
    public function post(string $name,string $value,?string $side=null):void {
        $this->submit(p2form($this,$name,$value,$side));verify($name==='show_feedback'?$this->statuses===[200]:(count($this->statuses)===2&&in_array($this->statuses[0],[302,303],true)&&$this->statuses[1]===200),'PRG for navigation/answer, direct feedback confirmation');$this->integrity();
    }
    public function integrity():void {
        if(in_array($this->s['state'],['intro','phase2_unresolved'],true))return;
        verify(in_array($this->s['state'],['phase2_intro','phase2','phase2_tb','done2'],true),'Never crosses to any Phase1 state');
        foreach($this->phase1Baseline as $key=>$v)verify(($this->s[$key]??null)===$v,'Phase1 unchanged '.$key);
        verify($this->s['blocks2']===$this->order,'Stable ordinary order, texts and randomized mappings');
        $scores=$base=$tb=array_fill_keys($this->s['varCodes'],0);$ordinary=$extra=[];
        foreach([['blocks2','answers2'],['tb2_set','tb2_answers']] as $n=>[$set,$answers]){
            $rows=$this->s[$answers]??[];verify(array_is_list($rows),'Contiguous indexed latest answers');
            foreach($rows as $i=>$a){$q=$this->s[$set][$i];$type=$a['side']==='neutral'?'NEUTRAL':$q[$a['side'].'_var'];if($a['value']){$scores[$type]+=$a['value'];if($n)$tb[$type]+=$a['value'];else $base[$type]+=$a['value'];}$code='x'.$type.'-'.$q['question_number'].'-'.$a['value'];if($n)$extra[]=$code;else $ordinary[]=$code;}
        }
        verify($scores===$this->s['varPoints']&&$base===$this->s['phase2_points']&&$tb===$this->s['tb2_points'],'Independent fresh ordinary/TB/total scores');
        verify($ordinary===$this->s['pairs2']&&$extra===$this->s['tb2_pairs'],'Research codes rebuilt once using actual question IDs');
        if(in_array($this->s['state'],['phase2','phase2_tb'],true)){
            $isTB=$this->s['state']==='phase2_tb';$i=$this->s[$isTB?'tb2_index':'q2_index'];$count=count($this->s[$isTB?'tb2_set':'blocks2']);$pct=$isTB?(int)floor($i/$count*100):(int)round(($i+1)/$count*100);
            verify(str_contains($this->html,'title="'.$pct.'%"'),'Progress reflects displayed question');
            verify(!str_contains($this->html,'name="return_to_phase1"')&&!str_contains($this->html,'name="return_to_tiebreak"'),'No Phase1 return controls');
        }
    }
    public function answer(array $answer):void {$this->post($this->s['state']==='phase2_tb'?'tb2_value':'choice2_value',(string)$answer['value'],$answer['side']);}
    public function nav(string $direction):void {$this->post($this->s['state']==='phase2_tb'?'tb2_navigate':'phase2_navigate',$direction);}
    public function go(int $target):void { $key=$this->s['state']==='phase2_tb'?'tb2_index':'q2_index';$guard=0;while($this->s[$key]!==$target){$this->nav($this->s[$key]>$target?'prev':'next');verify(++$guard<40,'Navigation advances to target');} }
    public function selected(array $a):void {
        $prefix=$this->s['state']==='phase2_tb'?'tb2':'choice2';$form=p2form($this,$prefix.'_value',(string)$a['value'],$a['side']);
        $dom=new DOMDocument();@$dom->loadHTML($this->html,LIBXML_NOERROR|LIBXML_NOWARNING);$xp=new DOMXPath($dom);
        $query='//form[input[@name="'.$prefix.'_value" and @value="'.$a['value'].'"] and input[@name="'.$prefix.'_side" and @value="'.$a['side'].'"]]/button';
        foreach($xp->query($query) as $b)verify($b->getAttribute('aria-pressed')==='true'&&str_contains(' '.$b->getAttribute('class').' ',' selected '),'Stored answer highlighted with accessible state');
    }
    public function fill(array $plan):void {
        $tb=$this->s['state']==='phase2_tb';$state=$tb?'phase2_tb':'phase2';$key=$tb?'tb2_index':'q2_index';$guard=0;
        while($this->s['state']===$state){$this->answer($plan[$this->s[$key]]);verify(++$guard<=36,'Fill terminates');}
    }
    public function tie(array $leaders,int $points=4):void {
        $targets=array_fill_keys($this->s['varCodes'],0);foreach($leaders as $type)$targets[$type]=$points;
        $this->fill(phase2_answer_plan($this->s['blocks2'],$targets));verify($this->s['state']==='phase2_tb','Real ordinary answers reach tie');
        $actual=array_map('strval',$this->s['tb2_pair']);sort($actual);$expected=array_map('strval',$leaders);sort($expected);verify($actual===$expected,'Correct eligible leaders');
    }
    public function finishChecked(string $type):void {
        $this->integrity();$row=$this->finish($type);for($i=count($this->s['tb2_pairs'])+1;$i<=10;$i++)verify($row['TB2_'.$i]==='','No abandoned TB answers in CSV');
        if($this->invitation){$items=json_decode(file_get_contents($this->dir.'/data/invites.json'),true);$invite=array_values(array_filter($items,fn($x)=>$x['id']===$this->invitation))[0];verify($invite['status']==='completed'&&$row['invite_id']===$this->invitation,'Invite completes after saved final result');}
        p2reject($this,['phase2_navigate'=>'prev']);p2reject($this,['tb2_navigate'=>'prev']);
    }
}
function p2user(string $track,string $tb1='',bool $invited=false):Phase2Participant {global $base,$sandbox;$p=new Phase2Participant($base,$sandbox);$p->open($track,$tb1,$invited);return $p;}
function p2business(Phase2Participant $p):array {$state=array_intersect_key($p->s,array_flip(['state','q2_index','tb2_index','answers2','tb2_answers','varPoints','pairs2','tb2_pairs','blocks2','tb2_set','phase2_points','tb2_points','log_row']));ksort($state);return $state;}
function p2reject(Phase2Participant $p,array $post):void {$before=p2business($p);$p->submit($post);verify(p2business($p)===$before,'Invalid request leaves business state unchanged');$p->integrity();}
function p2technical(Participant $p):void {verify($p->s['state']==='phase2_unresolved'&&empty($p->s['result_saved'])&&($p->s['log_row']['finalVar']??'')==='','Technical failure cannot complete');verify(!str_contains($p->html,'TYYLI '),'Technical failure has no type');foreach(research_rows($p->dir) as $row)verify($row['session_id']!==$p->cookie,'No research result for technical failure');}
function p2exerciseTB(Phase2Participant $p):void {
    $set=$p->s['tb2_set'];$scores=$p->s['phase2_points'];
    foreach([['left',2],['left',1],['neutral',0],['right',1],['right',2],['neutral',0]] as [$side,$value]){$a=['side'=>$side,'value'=>$value];$p->answer($a);$p->nav('prev');$p->selected($a);verify($p->s['tb2_set']===$set&&$p->s['phase2_points']===$scores,'TB editing preserves set and ordinary scores');}
    $before=$p->s;$p->nav('prev');verify($p->s['state']==='phase2'&&$p->s['q2_index']===17,'First TB previous returns only to last ordinary Phase2');$p->selected($p->s['answers2'][17]);$p->nav('next');verify($p->s['state']==='phase2_tb'&&$p->s['tb2_index']===0&&$p->s['tb2_set']===$set&&$p->s['tb2_answers']===$before['tb2_answers'],'Same tiebreak restored after boundary review');
    $p->nav('next');p2reject($p,['tb2_navigate'=>'next']);$saved=$p->s;$p->post('return_to_phase2','1');$p->nav('next');foreach(['tb2_set','tb2_index','tb2_answers','tb2_pairs','varPoints'] as $key)verify($p->s[$key]===$saved[$key],'Exact unchanged restore '.$key);$p->go(0);
}
$groups=['A2'=>['4','7','9'],'B2'=>['3','7','8'],'C2'=>['1','5','6'],'D2'=>['2','6','9']];
try {
foreach($groups as $track=>$types){
    p2case("ordinary $track all choices and positions",$track,'ordinary',function()use($track,$types){
        $p=p2user($track);p2reject($p,['phase2_navigate'=>'prev']);p2reject($p,['phase2_navigate'=>'next']);
        foreach([['left',2],['right',2],['right',1],['left',1],['neutral',0],['left',2],['neutral',0]] as [$side,$value]){$a=['side'=>$side,'value'=>$value];$p->answer($a);$p->nav('prev');$p->selected($a);verify(count($p->s['answers2'])===1,'Repeated edits replace first answer');$p->request();$p->selected($a);}
        $p->nav('next');while($p->s['q2_index']<17)$p->answer(['side'=>'neutral','value'=>0]);
        foreach([8,1,16,0,17] as $target){$p->go($target);if($target===17)continue;$p->selected(['side'=>'neutral','value'=>0]);$p->answer(['side'=>'right','value'=>1]);$p->nav('prev');$p->selected(['side'=>'right','value'=>1]);$p->answer(['side'=>'neutral','value'=>0]);}
        $p->go(17);$type=$p->s['blocks2'][17]['left_var'];$p->answer(['side'=>'left','value'=>2]);$p->finishChecked($type);
    });
    for($a=0;$a<3;$a++)for($b=$a+1;$b<3;$b++)foreach([$types[$a],$types[$b],'RATKAISEMATON'] as $result){$pair=[$types[$a],$types[$b]];
        p2case("pair $track ".implode('-',$pair)." -> $result",$track,'pair',function()use($track,$types,$pair,$result){$p=p2user($track,'',$result==='RATKAISEMATON');$p->tie($pair);p2exerciseTB($p);$target=array_fill_keys($types,0);if($result!=='RATKAISEMATON')$target[$result]=2;$p->fill(phase2_answer_plan($p->s['tb2_set'],$target));$p->finishChecked($result);});
    }
    foreach(array_merge($types,['two-leaders','three-leaders']) as $outcome)p2case("three-way $track -> $outcome",$track,'three-way',function()use($track,$types,$outcome){
        $p=p2user($track,'',$outcome==='two-leaders');$p->tie($types,0);p2exerciseTB($p);$target=array_fill_keys($types,0);if($outcome==='two-leaders'){$target[$types[0]]=1;$target[$types[1]]=1;}elseif($outcome!=='three-leaders')$target[$outcome]=2;
        $p->fill(phase2_answer_plan($p->s['tb2_set'],$target));$p->finishChecked(in_array($outcome,$types,true)?$outcome:'RATKAISEMATON');
    });
    foreach(['unchanged','same-answer','same-pair','unique','different-pair','two-to-three','three-to-two','repeated'] as $action)p2case("transition $track $action",$track,'transition',function()use($track,$types,$action){
        $p=p2user($track);$three=$action==='three-to-two';$p->tie($three?$types:array_slice($types,0,2),$three?0:4);$p->answer(['side'=>'left','value'=>2]);$old=$p->s;$p->post('return_to_phase2','1');
        if(in_array($action,['unchanged','same-answer'],true)){if($action==='same-answer')$p->answer($p->s['answers2'][17]);else{$p->go(0);$p->go(17);$p->nav('next');}foreach(['tb2_index','tb2_answers','tb2_pairs','tb2_set','varPoints'] as $key)verify($p->s[$key]===$old[$key],'Retains '.$key);$p->go(0);$p->fill(array_fill(0,count($p->s['tb2_set']),['side'=>'neutral','value'=>0]));$p->finishChecked('RATKAISEMATON');return;}
        $p->go(0);
        if($action==='same-pair'){
            $index=null;foreach($p->s['blocks2'] as $i=>$q)if($i<17&&$p->s['answers2'][$i]['value']===0&&in_array($types[2],$q['pair'],true)){$index=$i;break;}verify($index!==null,'Single same-pair edit reachable');$p->go($index);$q=$p->s['blocks2'][$index];$p->answer(['side'=>$q['left_var']===$types[2]?'left':'right','value'=>1]);verify(empty($p->s['tb2_set'])&&$p->s['tb2_pairs']===[],'One actual edit invalidates round');$p->go(17);$p->nav('next');
        }else{
            $scores=array_fill_keys($types,0);if($action==='unique')$scores[$types[2]]=2;elseif($action==='different-pair'){$scores[$types[0]]=4;$scores[$types[2]]=4;}elseif($action==='three-to-two'){$scores[$types[0]]=1;$scores[$types[1]]=1;}
            $plan=phase2_answer_plan($p->s['blocks2'],$scores);$p->fill($plan);
        }
        if($action==='unique'){$p->finishChecked($types[2]);return;}
        verify($p->s['state']==='phase2_tb'&&$p->s['tb2_answers']===[]&&$p->s['tb2_pairs']===[],'Fresh round has no obsolete answers');
        $expected=$action==='different-pair'?[$types[0],$types[2]]:($action==='same-pair'||$action==='three-to-two'?array_slice($types,0,2):$types);verify($p->s['tb2_pair']===$expected,'New eligible comparison set');
        if($action==='repeated'){for($cycle=0;$cycle<2;$cycle++){$expectedIndex=$p->s['tb2_index']+1;$p->answer(['side'=>'neutral','value'=>0]);$p->post('return_to_phase2','1');$p->nav('next');verify($p->s['tb2_index']===$expectedIndex,'Repeated unchanged restoration');}$p->post('return_to_phase2','1');$p->go(0);$scores=array_fill_keys($types,0);$scores[$types[2]]=2;$p->fill(phase2_answer_plan($p->s['blocks2'],$scores));$p->finishChecked($types[2]);return;}
        $p->fill(array_fill(0,count($p->s['tb2_set']),['side'=>'neutral','value'=>0]));$p->finishChecked('RATKAISEMATON');
    });
    p2case("security $track tokens and boundary",$track,'security',function()use($track,$types){
        $p=p2user($track);foreach([['return_to_phase1'=>'35'],['navigate'=>'prev'],['tb1_new_navigate'=>'back'],['tiebreak_navigate'=>'back'],['tb2_value'=>'2','tb2_side'=>'left'],['phase2_navigate'=>'999'],['phase2_navigate'=>['next']],['phase2_navigate'=>'next','target_index'=>'10'],['phase2_navigate'=>'prev','q2_index'=>'1'],['choice2_value'=>'0','choice2_side'=>'left'],['choice2_value'=>'2','choice2_side'=>'neutral'],['choice2_value'=>'1','choice2_side'=>'bad'],['choice2_value'=>'1','choice2_side'=>['left']],['choice2_value'=>'9','choice2_side'=>'left'],['choice2_value'=>'1.5','choice2_side'=>'left'],['phase2_navigate'=>'next','choice2_value'=>'1','choice2_side'=>'left']] as $bad)p2reject($p,$bad);
        $p->answer(['side'=>'left','value'=>2]);$old=$p->lastPost;$before=p2business($p);$p->request('/index.php',$old);verify(p2business($p)===$before,'Replay answer token rejected');$p->nav('prev');$stale=$p->lastPost;$p->answer(['side'=>'right','value'=>1]);$before=p2business($p);$p->request('/index.php',$stale);verify(p2business($p)===$before,'Replay navigation after editing rejected');
        $p->go(0);$p->tie($types,0);p2reject($p,['phase2_navigate'=>'prev']);p2reject($p,['return_to_phase1'=>'35']);p2reject($p,['tb2_navigate'=>'next']);p2reject($p,['tb2_navigate'=>'next','tb2_index'=>'3']);$p->answer(['side'=>'neutral','value'=>0]);$old=$p->lastPost;$before=p2business($p);$p->request('/index.php',$old);verify(p2business($p)===$before,'Replay TB answer rejected');$p->nav('prev');$old=$p->lastPost;$p->nav('next');$before=p2business($p);$p->request('/index.php',$old);verify(p2business($p)===$before,'Replay TB navigation rejected');$p->fill(array_fill(0,count($p->s['tb2_set']),['side'=>'neutral','value'=>0]));$p->finishChecked('RATKAISEMATON');
    });
    foreach(['current','legacy'] as $tb1)foreach([false,true] as $invited)p2case("full $track $tb1 invited=".(int)$invited,$track,'full-workflow',function()use($track,$types,$tb1,$invited){$p=p2user($track,$tb1,$invited);$p->answer(['side'=>'left','value'=>2]);$p->nav('prev');$p->answer(['side'=>'neutral','value'=>0]);$p->go(0);$p->tie($types,0);p2exerciseTB($p);$p->fill(array_fill(0,count($p->s['tb2_set']),['side'=>'neutral','value'=>0]));$p->finishChecked('RATKAISEMATON');$p->post('show_feedback','Anna palautetta');$p->submit(['feedback_text'=>'Phase2 navigation isolated feedback','send_feedback'=>'1']);verify($p->s['feedback_given']===true,'Feedback works');$p->request('/index.php?reset=1');verify($p->s['state']==='intro','Reset completes');});
    foreach(['missing-file','missing-blocks','bad-mapping','bad-index','bad-answers','hole','missing-pair','interrupted','csv-write','legacy-migration','bad-tb-index','bad-tb-answers','missing-tb-data','bad-tb-mapping','incomplete-ordinary-before-tb','corrupt-old-codes','derived-repair','truncated-blocks','truncated-tb-set','changed-order'] as $fault)p2case("fault $track $fault",$track,'failure-injection',function()use($track,$types,$fault){
        global $sandbox,$base;
        if($fault==='missing-file'){$path=$sandbox.'/InsideBlockQuestions.csv';$contents=file_get_contents($path);unlink($path);try{$p=new Participant($base,$sandbox);$p->begin();$values=array_fill_keys(['A1','B1','C1','D1'],1);$values[$track[0].'1']=6;$p->phase1($values);p2technical($p);}finally{file_put_contents($path,$contents);}return;}
        $p=p2user($track,'',$fault==='csv-write');
        if($fault==='interrupted'){$post=p2form($p,'choice2_value','2','left');$p->cookie='';$p->request('/index.php',$post);verify($p->s['state']==='intro'&&empty($p->s['result_saved']),'Interrupted session rejects stale form safely');return;}
        if($fault==='csv-write'){$path=$sandbox.'/data/survey_log_'.date('Y-m').'.csv';$contents=is_file($path)?file_get_contents($path):null;if(is_file($path))unlink($path);mkdir($path);try{$scores=array_fill_keys($types,0);$scores[$types[0]]=2;$p->fill(phase2_answer_plan($p->s['blocks2'],$scores));verify($p->s['state']==='phase2_unresolved','Failed write remains technical');$inv=array_values(array_filter(json_decode(file_get_contents($sandbox.'/data/invites.json'),true),fn($x)=>$x['id']===$p->invitation))[0];verify($inv['status']==='accepted','Failed persistence does not complete invitation');}finally{rmdir($path);if($contents!==null)file_put_contents($path,$contents);}p2technical($p);return;}
        if($fault==='missing-pair'){$cache=$p->s['cached_quiz_data'];$cache['phase2_tiebreaks']['T'.$track]=array_values(array_filter($cache['phase2_tiebreaks']['T'.$track],fn($q)=>$q['pair']!==array_slice($types,0,2)));$p->corrupt(['cached_quiz_data'=>$cache]);$p->fill(array_fill(0,18,['side'=>'neutral','value'=>0]));p2technical($p);return;}
        if($fault==='legacy-migration'){$p->tie($types,0);$p->answer(['side'=>'left','value'=>2]);$before=p2business($p);$p->corrupt(['tb2_answers'=>null,'tb2_source_signature'=>null]);verify(p2business($p)===$before,'Old in-progress codes safely upgraded without point changes');$p->nav('prev');$p->answer(['side'=>'neutral','value'=>0]);$p->fill(array_fill(0,count($p->s['tb2_set']),['side'=>'neutral','value'=>0]));$p->finishChecked('RATKAISEMATON');return;}
        if(in_array($fault,['bad-tb-index','bad-tb-answers','missing-tb-data','bad-tb-mapping','incomplete-ordinary-before-tb','corrupt-old-codes','derived-repair','truncated-tb-set'],true)){
            $p->tie($types,0);$p->answer(['side'=>'left','value'=>2]);$patch=[];
            if($fault==='bad-tb-index')$patch=['tb2_index'=>99];
            elseif($fault==='truncated-tb-set'){$set=$p->s['tb2_set'];array_pop($set);$patch=['tb2_set'=>$set];}
            elseif($fault==='bad-tb-answers')$patch=['tb2_answers'=>[1=>['side'=>'neutral','value'=>0]]];
            elseif($fault==='missing-tb-data')$patch=['tb2_set'=>[]];
            elseif($fault==='bad-tb-mapping'){$set=$p->s['tb2_set'];$set[0]['left_var']='99';$patch=['tb2_set'=>$set];}
            elseif($fault==='incomplete-ordinary-before-tb'){$answers=$p->s['answers2'];array_pop($answers);$patch=['answers2'=>$answers];}
            elseif($fault==='corrupt-old-codes')$patch=['tb2_answers'=>null,'tb2_source_signature'=>null,'tb2_pairs'=>['x9-INVALID-2']];
            else $patch=['varPoints'=>array_fill_keys($types,999),'pairs2'=>['corrupt-derived-code'],'tb2_pairs'=>['corrupt-derived-code']];
            $before=p2business($p);$p->corrupt($patch);
            if($fault==='derived-repair'){verify(p2business($p)===$before,'Derived scores/codes repaired from authoritative answers');$p->nav('prev');$p->answer(['side'=>'neutral','value'=>0]);$p->fill(array_fill(0,count($p->s['tb2_set']),['side'=>'neutral','value'=>0]));$p->finishChecked('RATKAISEMATON');}else p2technical($p);
            return;
        }
        $patch=[];if($fault==='missing-blocks')$patch=['blocks2'=>[]];elseif($fault==='bad-index')$patch=['q2_index'=>20];elseif($fault==='bad-answers')$patch=['answers2'=>[['side'=>'neutral','value'=>2]]];elseif($fault==='hole')$patch=['answers2'=>[1=>['side'=>'neutral','value'=>0]]];else{$set=$p->s['blocks2'];if($fault==='truncated-blocks')array_pop($set);elseif($fault==='changed-order')$set=array_reverse($set);else $set[0]['left_var']='99';$patch=['blocks2'=>$set];}$p->corrupt($patch);p2technical($p);
    },true);
}
}finally{proc_terminate($process);proc_close($process);remove_isolated_app($sandbox);}
$matrix['summary']=['passed'=>$passed,'failed'=>$failed,'assertions'=>$checks,'requests'=>$requests,'skipped'=>$skipped];
file_put_contents($matrixPath,json_encode($matrix,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL);
echo "PHASE2 HTTP: $passed passed, $failed failed; $checks assertions; $requests real requests\n";
exit($failed?1:0);
