<?php declare(strict_types=1);

// Included by the real HTTP harness with --navigation-only.
// Ordinary answers are always submitted through rendered forms. Session patches
// are reserved for explicitly named fault-injection scenarios.
function nav_keys(string $mode): array {
    return $mode === 'new'
        ? ['state'=>'tb1_new','intro'=>'tb1_new_intro','set'=>'tb1_new_questions','answers'=>'tb1_new_answers','index'=>'tb1_new_index','answer'=>'tb1_new_choice','navigate'=>'tb1_new_navigate','next'=>'forward','continue'=>'continue_tb1_new']
        : ['state'=>'tiebreak','intro'=>'tiebreak_intro','set'=>'tiebreak_set','answers'=>'tiebreak_answers','index'=>'tiebreak_index','answer'=>'tb_choice','navigate'=>'tiebreak_navigate','next'=>'forward','continue'=>'continue_tiebreak'];
}
function nav_dom(Participant $p): DOMXPath {
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">'.$p->html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    return new DOMXPath($dom);
}
function nav_form(Participant $p, string $name, string $value): array {
    $xp = nav_dom($p);
    foreach ($xp->query('//form') as $form) {
        foreach ($xp->query('.//input | .//button[@name]', $form) as $control) {
            if ($control->getAttribute('name') !== $name || $control->getAttribute('value') !== $value) continue;
            $post = [];
            foreach ($xp->query('.//input[@name]', $form) as $input) $post[$input->getAttribute('name')]=$input->getAttribute('value');
            $post[$name]=$value;
            return $post;
        }
    }
    throw new RuntimeException('Rendered control missing: '.$name.'='.$value.' in '.($p->s['state']??''));
}
function nav_post(Participant $p, string $name, string $value): void {
    $p->submit(nav_form($p,$name,$value));
    verify(count($p->statuses)===2 && in_array($p->statuses[0],[302,303],true) && $p->statuses[1]===200, 'Navigation/answer uses POST/redirect/GET');
    nav_integrity($p);
}
function nav_integrity(Participant $p): void {
    if (in_array($p->s['state']??'', ['intro','phase2_unresolved'],true)) return;
    $scores=$yes=array_fill_keys(['A1','B1','C1','D1'],0);$records=[];
    foreach($p->s['phase1'] as $q) if(isset($p->s['answers1_by_id'][$q['id']])) {
        $v=$p->s['answers1_by_id'][$q['id']];$scores[$q['class']]+=$v;if($v>=5)$yes[$q['class']]++;
        $records[]=['class'=>$q['class'],'question_number'=>$q['question_number'],'value'=>$v];
    }
    verify($p->s['points1']===$scores && $p->s['yesCounts1']===$yes,'Phase1 sums/yes counts equal fresh calculation');
    verify($p->s['answers1']===$records,'One latest Phase1 research answer in questionnaire order');
    if(isset($p->s['tb1_new_answers'])) {
        $c=array_fill_keys(['A','B','C','D'],0);
        foreach($p->s['tb1_new_answers'] as $i=>$a){$q=$p->s['tb1_new_questions'][$i];$g=substr($a,-1);verify($a===$q['question_num'].$g&&in_array($g,[$q['block_left'],$q['block_right']],true),'TB1 record belongs to indexed question and pair');$c[$g]++;}
        verify($p->s['tb1_new_counters']===$c,'TB1 counters equal fresh sum of current selections');
    }
    if(isset($p->s['tiebreak_answers'])) {
        $c=$y=array_fill_keys(['A1','B1','C1','D1'],0);
        foreach($p->s['tiebreak_answers'] as $i=>$v){$q=$p->s['tiebreak_set'][$i];$c[$q['class']]+=$v;if($v>=5)$y[$q['class']]++;}
        verify(($p->s['tiebreak_points']??$c)===$c&&($p->s['tiebreak_yes']??$y)===$y,'Legacy TB aggregates remain separate and exact');
    }
}
function nav_selected(Participant $p, string $field, ?string $value): void {
    $selected=[];$xp=nav_dom($p);
    foreach($xp->query('//form') as $form) {
        foreach($xp->query('.//input[@name="'.$field.'"]',$form) as $input) {
            foreach($xp->query('.//button[contains(concat(" ",normalize-space(@class)," ")," selected ")]',$form) as $button) {
                $selected[]=$input->getAttribute('value');
                verify($button->getAttribute('aria-pressed')==='true','Selected answer is accessible');
            }
        }
    }
    verify($value===null ? $selected===[] : $selected!==[]&&array_unique($selected)===[$value], 'Only previous answer visibly selected: '.$field.'='.($value??'none'));
}
function nav_values(string $pair): array {
    $v=array_fill_keys(['A1','B1','C1','D1'],3);foreach(str_split($pair) as $g)$v[$g.'1']=4;return $v;
}
function nav_begin(string $mode,string $pair,bool $invited=false,bool $start=true): Participant {
    global $base,$sandbox;
    if($invited)invite_fixture($sandbox);
    $p=new Participant($base,$sandbox);$p->begin('Navigation '.$pair,$invited?'HTTPTEST':'',$mode==='old'?'oldTB1=1':'');
    $p->phase1(nav_values($pair));$k=nav_keys($mode);
    verify($p->s['state']===$k['intro'],'Initial correct tiebreak intro');
    if($start)nav_post($p,$k['continue'],'1');
    $GLOBALS['nav_observed']['scores_before']=$p->s['points1'];
    nav_integrity($p);return $p;
}
function nav_answer(Participant $p,string $mode,string $winner): void {
    $k=nav_keys($mode);$q=$p->s[$k['set']][$p->s[$k['index']]];
    nav_post($p,$k['answer'],$mode==='new'?$winner:($q['class']===$winner.'1'?'6':'1'));
}
function nav_return(Participant $p): void {
    nav_post($p,'return_to_phase1',(string)(count($p->s['phase1'])-1));
    verify($p->s['state']==='quiz1'&&$p->s['q1_index']===count($p->s['phase1'])-1,'Returns to last ordinary Phase1 question');
    $q=$p->s['phase1'][$p->s['q1_index']];nav_selected($p,'choice',(string)$p->s['answers1_by_id'][$q['id']]);
}
function nav_review(Participant $p,?array $values=null): void {
    if($values!==null) while($p->s['q1_index']>0) nav_post($p,'navigate','prev');
    $guard=0;
    while($p->s['state']==='quiz1'&&$guard++<45) {
        $q=$p->s['phase1'][$p->s['q1_index']];$old=$p->s['answers1_by_id'][$q['id']];
        nav_selected($p,'choice',(string)$old);
        $next=$values[$q['class']]??$old;
        if(is_callable($next))$next=$next($q);
        if($next!==$old) nav_post($p,'choice',(string)$next);else nav_post($p,'navigate','next');
    }
    verify($guard<45,'Review finishes without answer resubmission');
}
function nav_finish(Participant $p,string $mode,string $winner,string $category='VALID_RESULT',bool $invited=false): void {
    global $groups,$sandbox;
    $k=nav_keys($mode);
    if($p->s['state']===$k['intro'])nav_post($p,$k['continue'],'1');
    $guard=0;while($p->s['state']===$k['state']&&$guard++<20)nav_answer($p,$mode,$winner);
    verify($p->s['state']==='phase2_intro'&&$p->s['track']===$winner.'2','Correct winner reaches corresponding Phase2');
    nav_integrity($p);
    if($invited)verify(json_decode(file_get_contents($sandbox.'/data/invites.json'),true)[0]['status']==='accepted','Invite not completed before result persistence');
    $type=$groups[$winner.'2'][0];$p->phase2($category==='UNRESOLVED_TIE'?neutral():favor($type));
    if($category==='UNRESOLVED_TIE'){$p->tb2(neutral());$type='RATKAISEMATON';}
    $row=$p->finish($type);nav_integrity($p);
    foreach(['A1','B1','C1','D1'] as $g){verify((int)$row[$g.'_points']===$p->s['points1'][$g]&&(int)$row[$g.'_yes_count']===$p->s['yesCounts1'][$g],'CSV original Phase1 sums and yes counts');verify((int)$row[$g.'_tiebreak_points']===($p->s['tiebreak_points'][$g]??0),'CSV legacy counters, no obsolete points');verify((int)$row[$g.'_tb1_counter']===($p->s['tb1_new_counters'][$g[0]]??0),'CSV pairwise counters, no obsolete points');}
    foreach(['TB1_'=>7,'TB1_OLD_'=>10] as $prefix=>$n)for($i=0;$i<$n;$i++) {
        $a=$prefix==='TB1_'?($p->s['tb1_new_answers'][$i]??''):($p->s['tiebreak_log'][$i]??'');
        if(is_array($a))$a=$a['class'].'-'.$a['question_number'].'-'.$a['value'];
        verify($row[$prefix.($i+1)]===$a,'CSV contains only current TB answers/question IDs');
    }
    if($invited)verify(json_decode(file_get_contents($sandbox.'/data/invites.json'),true)[0]['status']==='completed','Invite completed after normal/unresolved save');
    $GLOBALS['nav_observed']+=['scores_after'=>$p->s['points1'],'final_state'=>$p->s['state'],'track'=>$p->s['track'],'result_category'=>$category,'final_type'=>$type,'tiebreak_mode'=>isset($p->s['tb1_new_answers'])?'new':(isset($p->s['tiebreak_answers'])?'old':null)];
}
function nav_technical(Participant $p): void {
    assert_technical($p);
    $GLOBALS['nav_observed']+=['scores_after'=>$p->s['points1']??null,'final_state'=>$p->s['state'],'track'=>$p->s['track']??null,'result_category'=>'TECHNICAL_ERROR'];
}
function nav_case(string $name,array $metadata,callable $test): void {
    global $passed,$failed,$checks,$requests;
    $before=[$passed,$failed,$checks,$requests];$GLOBALS['nav_observed']=[];
    scenario('NAV '.$name,$test);
    $scores=array_fill_keys(['A1','B1','C1','D1'],27);foreach(str_split($metadata['original_pair']) as $g)$scores[$g.'1']=36;
    $GLOBALS['nav_matrix'][]=array_merge(['name'=>$name,'http'=>true,'initial_state'=>str_contains($name,'new')?'tb1_new':'tiebreak','phase1_scores_before'=>$scores,'expected_state'=>($metadata['expected_result_category']??'')==='TECHNICAL_ERROR'?'phase2_unresolved':'done2'],$metadata,['executed'=>$passed+$failed>$before[0]+$before[1],'passed'=>$passed>$before[0],'assertions'=>$checks-$before[2],'requests'=>$requests-$before[3],'observed'=>$GLOBALS['nav_observed']]);
}

$navPairs=['AB','AC','AD','BC','BD','CD'];
foreach(['new','old'] as $mode)foreach($navPairs as $pair)foreach(['first','partial','final'] as $entry)foreach(str_split($pair) as $winner) {
    $category=$winner===$pair[1]?'UNRESOLVED_TIE':'VALID_RESULT';$invited=$entry==='partial';
    nav_case("$mode $pair $entry unchanged winner $winner",['mode'=>$mode,'original_pair'=>$pair,'action'=>'return-review-unchanged','entry'=>$entry,'answers_changed'=>false,'expected_pair'=>$pair,'expected_track'=>$winner.'2','expected_result_category'=>$category,'invited'=>$invited],function()use($mode,$pair,$entry,$winner,$category,$invited){
        $p=nav_begin($mode,$pair,$invited);$k=nav_keys($mode);$n=$entry==='first'?0:($entry==='partial'?2:count($p->s[$k['set']])-1);
        while($p->s[$k['index']]<$n)nav_answer($p,$mode,$winner);
        $saved=$p->s;
        nav_return($p);nav_review($p);
        verify($p->s['state']===$k['state'],'Review restores active tiebreak without new intro');
        foreach([$k['set'],$k['answers'],$k['index']] as $field)verify($p->s[$field]===$saved[$field],'Unchanged review preserves '.$field);
        if($n>0){nav_post($p,$k['navigate'],'back');$q=$p->s[$k['set']][$n-1];$v=$mode==='new'?substr($saved[$k['answers']][$n-1],-1):(string)$saved[$k['answers']][$n-1];nav_selected($p,$k['answer'],$v);nav_post($p,$k['navigate'],'forward');verify($p->s[$k['answers']]===$saved[$k['answers']],'Back/forward preserves all answered selections');}
        nav_finish($p,$mode,$winner,$category,$invited);
    });
}

function nav_fresh(Participant $p,string $mode,string $pair): void {
    $k=nav_keys($mode);verify($p->s['state']===$k['intro'],'Edited ordinary answers start a fresh intro');
    $classes=$p->s['tiebreak_classes'];sort($classes);
    verify($classes===array_map(fn($g)=>$g.'1',str_split($pair)),'New candidate pair is correct');
    verify($p->s[$k['answers']]===[]&&$p->s[$k['index']]===0,'Obsolete selections and progress cleared');
    nav_post($p,$k['continue'],'1');nav_selected($p,$k['answer'],null);
    nav_integrity($p);
}
function nav_replacement(Participant $p,string $mode,string $winner): void {
    $k=nav_keys($mode);nav_answer($p,$mode,$winner);nav_answer($p,$mode,$winner);
    $before=$p->s[$k['answers']];nav_post($p,$k['navigate'],'back');
    $old=$mode==='new'?substr($before[1],-1):(string)$before[1];nav_selected($p,$k['answer'],$old);
    $q=$p->s[$k['set']][1];$changed=$mode==='new'?($old===$q['block_left']?$q['block_right']:$q['block_left']):($old==='6'?'1':'6');
    nav_post($p,$k['answer'],$changed);verify(count($p->s[$k['answers']])===2,'Replacement keeps two records');
    nav_post($p,$k['navigate'],'back');nav_selected($p,$k['answer'],$changed);
    nav_post($p,$k['answer'],$old);verify($p->s[$k['answers']]===$before,'Replacing back restores exact answers and counters');
    nav_post($p,$k['navigate'],'back');nav_post($p,$k['navigate'],'back');
    $first=$mode==='new'?substr($before[0],-1):(string)$before[0];nav_selected($p,$k['answer'],$first);
    nav_post($p,$k['navigate'],'forward');nav_selected($p,$k['answer'],$old);
    nav_post($p,$k['navigate'],'forward');verify($p->s[$k['answers']]===$before,'Forward review needs no new answer');
}

foreach(['new','old'] as $mode)foreach($navPairs as $pair)foreach(str_split($pair) as $winner) {
    nav_case("$mode $pair changed same pair winner $winner",['mode'=>$mode,'original_pair'=>$pair,'action'=>'replace-TB-answer-return-edit-ordinary','answers_changed'=>true,'expected_pair'=>$pair,'expected_track'=>$winner.'2','expected_result_category'=>'VALID_RESULT'],function()use($mode,$pair,$winner){
        $p=nav_begin($mode,$pair);nav_replacement($p,$mode,$winner);nav_return($p);
        $target=0;foreach($p->s['phase1'] as $i=>$q)if($q['class']===$winner.'1'){$target=$i;break;}
        while($p->s['q1_index']>$target)nav_post($p,'navigate','prev');
        nav_post($p,'choice','5');nav_review($p);nav_fresh($p,$mode,$pair);nav_finish($p,$mode,$winner);
    });
}

foreach(['new','old'] as $mode)foreach($navPairs as $from)foreach($navPairs as $to)if($from!==$to)foreach(str_split($to) as $winner) {
    nav_case("$mode $from to $to winner $winner",['mode'=>$mode,'original_pair'=>$from,'action'=>'return-edit-all-needed-ordinary-answers','answers_changed'=>true,'expected_pair'=>$to,'expected_track'=>$winner.'2','expected_result_category'=>'VALID_RESULT'],function()use($mode,$from,$to,$winner){
        $p=nav_begin($mode,$from);nav_answer($p,$mode,$from[0]);nav_answer($p,$mode,$from[0]);
        nav_return($p);nav_review($p,nav_values($to));nav_fresh($p,$mode,$to);nav_finish($p,$mode,$winner);
    });
}

foreach(['new','old'] as $mode)foreach($navPairs as $pair)foreach(str_split($pair) as $winner) {
    nav_case("$mode $pair clear winner $winner",['mode'=>$mode,'original_pair'=>$pair,'action'=>'return-edit-clear-winner','answers_changed'=>true,'expected_pair'=>null,'expected_track'=>$winner.'2','expected_result_category'=>'VALID_RESULT','invited'=>true],function()use($mode,$pair,$winner){
        $p=nav_begin($mode,$pair,true);nav_answer($p,$mode,$pair[0]);nav_return($p);
        $v=array_fill_keys(['A1','B1','C1','D1'],3);$v[$winner.'1']=6;nav_review($p,$v);
        verify(!isset($p->s['tb1_new_answers'])&&!isset($p->s['tiebreak_answers'])&&!isset($p->s['tb1_context']),'Clear winner discards prior TB state');
        nav_finish($p,$mode,$winner,'VALID_RESULT',true);
    });
}

foreach(['new','old'] as $mode)foreach($navPairs as $pair) {
    nav_case("$mode $pair intro review",['mode'=>$mode,'initial_state'=>nav_keys($mode)['intro'],'original_pair'=>$pair,'action'=>'return-from-intro-review','answers_changed'=>false,'expected_pair'=>$pair,'expected_track'=>$pair[0].'2','expected_result_category'=>'VALID_RESULT'],function()use($mode,$pair){
        $p=nav_begin($mode,$pair,false,false);$k=nav_keys($mode);
        $saved=$p->s[$k['set']];nav_return($p);nav_review($p);
        verify($p->s['state']===$k['intro']&&$p->s[$k['set']]===$saved,'Unchanged intro review restores intro and order');
        nav_finish($p,$mode,$pair[0]);
    });
}

foreach(['new','old'] as $mode)foreach([3,4] as $leaders)foreach(['A','B'] as $winner) {
    nav_case("$mode $leaders close groups winner $winner",['mode'=>$mode,'original_pair'=>'AB','action'=>'return-make-three-or-four-group-tie','answers_changed'=>true,'expected_pair'=>'AB','expected_track'=>$winner.'2','expected_result_category'=>'VALID_RESULT'],function()use($mode,$leaders,$winner){
        $p=nav_begin($mode,'AB');nav_answer($p,$mode,'A');nav_return($p);$v=nav_values('AB');$v['C1']=4;if($leaders===4)$v['D1']=4;
        nav_review($p,$v);nav_fresh($p,$mode,'AB');verify($p->s['tiebreak_classes']===['A1','B1'],'Approved top-two ordering policy unchanged');nav_finish($p,$mode,$winner);
    });
}

foreach(['new','old'] as $mode)foreach([2,3] as $gap)nav_case("$mode eligibility boundary gap $gap",['mode'=>$mode,'original_pair'=>'AB','action'=>'ordinary-edit-to-eligibility-boundary','answers_changed'=>true,'expected_pair'=>$gap===2?'AB':null,'expected_track'=>'A2','expected_result_category'=>'VALID_RESULT'],function()use($mode,$gap){
    $p=nav_begin($mode,'AB');nav_answer($p,$mode,'A');nav_return($p);$v=nav_values('AB');$n=0;
    $v['A1']=function()use(&$n,$gap){$i=$n++;return $i===0?6:($gap===3&&$i===1?5:4);};
    nav_review($p,$v);verify($p->s['points1']['A1']-$p->s['points1']['B1']===$gap,'Exact eligibility boundary scores');
    if($gap===2)nav_fresh($p,$mode,'AB');else verify($p->s['state']==='phase2_intro','Three-point gap bypasses TB');
    nav_finish($p,$mode,'A');
});
foreach(['new','old'] as $mode)foreach([3,4] as $leaders)foreach(['A','C'] as $winner)nav_case("$mode $leaders near groups C37 A36 winner $winner",['mode'=>$mode,'original_pair'=>'AB','action'=>'make-three-or-four-near-groups','answers_changed'=>true,'expected_pair'=>'AC','expected_track'=>$winner.'2','expected_result_category'=>'VALID_RESULT'],function()use($mode,$leaders,$winner){
    $p=nav_begin($mode,'AB');nav_answer($p,$mode,'A');nav_return($p);$v=nav_values('AB');$n=0;$v['C1']=function()use(&$n){return $n++===0?5:4;};if($leaders===4)$v['D1']=4;
    nav_review($p,$v);verify($p->s['tiebreak_classes']===['C1','A1'],'Near groups retain highest-two ordering and exclusion policy');
    nav_fresh($p,$mode,'AC');nav_finish($p,$mode,$winner);
});

foreach(['new','old'] as $mode) {
    nav_case("$mode repeated AB CD AB clear A",['mode'=>$mode,'original_pair'=>'AB','action'=>'unchanged-review-AB-to-CD-to-AB-to-clear-A','answers_changed'=>true,'expected_pair'=>null,'expected_track'=>'A2','expected_result_category'=>'UNRESOLVED_TIE'],function()use($mode){
        $p=nav_begin($mode,'AB',true);$k=nav_keys($mode);nav_answer($p,$mode,'A');nav_answer($p,$mode,'A');$saved=$p->s[$k['answers']];
        nav_return($p);nav_review($p);verify($p->s[$k['answers']]===$saved,'Repeated unchanged review keeps selections');
        foreach(['CD','AB'] as $pair){nav_return($p);nav_review($p,nav_values($pair));nav_fresh($p,$mode,$pair);nav_answer($p,$mode,$pair[0]);nav_answer($p,$mode,$pair[0]);}
        nav_return($p);$v=nav_values('AB');$v['A1']=6;nav_review($p,$v);nav_finish($p,$mode,'A','UNRESOLVED_TIE',true);
    });
    nav_case("$mode submitting unchanged ordinary value",['mode'=>$mode,'original_pair'=>'AB','action'=>'return-submit-same-value','answers_changed'=>false,'expected_pair'=>'AB','expected_track'=>'B2','expected_result_category'=>'VALID_RESULT'],function()use($mode){
        $p=nav_begin($mode,'AB');$k=nav_keys($mode);nav_answer($p,$mode,'B');nav_answer($p,$mode,'B');$saved=$p->s[$k['answers']];
        nav_return($p);$q=$p->s['phase1'][$p->s['q1_index']];nav_post($p,'choice',(string)$p->s['answers1_by_id'][$q['id']]);
        verify($p->s[$k['answers']]===$saved&&$p->s[$k['index']]===2,'Same-value POST restores progress without invalidation');nav_finish($p,$mode,'B');
    });
    foreach(['first','middle','last'] as $position)nav_case("$mode edit $position ordinary answer twice",['mode'=>$mode,'original_pair'=>'CD','action'=>'edit-'.$position.'-ordinary-answer-twice','answers_changed'=>true,'expected_pair'=>'CD','expected_track'=>'C2','expected_result_category'=>'VALID_RESULT'],function()use($mode,$position){
        $p=nav_begin($mode,'CD');nav_answer($p,$mode,'C');nav_return($p);$total=count($p->s['phase1']);$target=$position==='first'?0:($position==='middle'?intdiv($total,2):$total-1);
        while($p->s['q1_index']>$target)nav_post($p,'navigate','prev');
        $before=$p->s['answers1_by_id'];$q=$p->s['phase1'][$target];$old=$before[$q['id']];nav_post($p,'choice',(string)($old+1));
        $expected=$before;$expected[$q['id']]=$old+1;verify($p->s['answers1_by_id']===$expected,'Edit preserves every other ordinary answer');
        if($position==='last')nav_return($p);else nav_post($p,'navigate','prev');
        nav_selected($p,'choice',(string)($old+1));nav_post($p,'choice',(string)$old);nav_review($p);
        verify($p->s['answers1_by_id']===$before,'Second edit restores exact ordinary answers');nav_fresh($p,$mode,'CD');nav_finish($p,$mode,'C');
    });
}

function nav_unchanged(Participant $p,array $before,string $message): void {
    foreach(['state','q1_index','points1','yesCounts1','answers1_by_id','tb1_new_index','tb1_new_answers','tb1_new_counters','tiebreak_index','tiebreak_answers','tiebreak_points'] as $key)verify(($p->s[$key]??null)===($before[$key]??null),$message.' preserves '.$key);
    nav_integrity($p);
}
foreach(['new','old'] as $mode)nav_case("$mode request security and stale pair forms",['mode'=>$mode,'original_pair'=>'AB','action'=>'invalid-and-replayed-requests-then-AB-to-CD','answers_changed'=>true,'expected_pair'=>'CD','expected_track'=>'D2','expected_result_category'=>'VALID_RESULT'],function()use($mode){
    $p=nav_begin($mode,'AB');$k=nav_keys($mode);$before=$p->s;$post=nav_form($p,'return_to_phase1','35');
    foreach([null,'forged'] as $token){$bad=$post;if($token===null)unset($bad['workflow_token']);else$bad['workflow_token']=$token;$p->request('/index.php',$bad);nav_unchanged($p,$before,'Missing/invalid token');}
    foreach(['-1','0','36','x',[]] as $target){$before=$p->s;$p->submit(['return_to_phase1'=>$target]);nav_unchanged($p,$before,'Invalid return target');}
    foreach([[$k['navigate']=>'forward'],['navigate'=>'prev'],['continue_phase2'=>'1'],['tb1_new_skip'=>'1']] as $bad){$before=$p->s;$p->submit($bad);nav_unchanged($p,$before,'Unanswered forward / wrong state');}
    nav_answer($p,$mode,'A');$staleAnswer=$p->lastPost;$before=$p->s;$p->request('/index.php',$staleAnswer);nav_unchanged($p,$before,'Replayed answer');
    nav_return($p);$replayed=$p->lastPost;$before=$p->s;$p->request('/index.php',$replayed);nav_unchanged($p,$before,'Replayed return');
    nav_review($p,nav_values('CD'));nav_fresh($p,$mode,'CD');$before=$p->s;$p->request('/index.php',$staleAnswer);nav_unchanged($p,$before,'Old-pair form');
    foreach([['tb1_new_choice'=>'A'],['tb_choice'=>'7'],[$k['answer']=>[]],[$k['answer']=>$mode==='new'?'C':'6','question_index'=>'5'],['return_to_phase1'=>'35',$k['answer']=>$mode==='new'?'C':'6']] as $bad){$before=$p->s;$p->submit($bad);nav_unchanged($p,$before,'Forged group/value/index/mixed action');}
    $before=$p->s;$p->request();nav_unchanged($p,$before,'GET refresh');
    nav_finish($p,$mode,'D');$before=$p->s;$p->submit(['return_to_phase1'=>'35']);nav_unchanged($p,$before,'No return from completed Phase2');
    $p->cookie='';$p->request('/index.php',$replayed);verify($p->s['state']==='intro'&&empty($p->s['answers1_by_id']),'Restarted session rejects old navigation token');
    $GLOBALS['nav_observed']['state_after_cookie_restart']=$p->s['state'];
});

foreach(['new','old'] as $mode)nav_case("$mode reset and feedback regression",['mode'=>$mode,'original_pair'=>'AB','action'=>'reset-during-review-then-normal-result-feedback','answers_changed'=>false,'expected_pair'=>null,'expected_track'=>'D2','expected_result_category'=>'VALID_RESULT'],function()use($mode){
    global $sandbox;
    $p=nav_begin($mode,'AB');nav_answer($p,$mode,'A');nav_return($p);$p->request('/index.php?reset=1');
    verify($p->s['state']==='intro'&&!isset($p->s['tb1_context'])&&!isset($p->s['tb1_review_state'])&&empty($p->s['answers1_by_id']),'Reset clears active and suspended round');
    $p->begin('After reset');$before=$p->s;foreach([['navigate'=>'next'],['return_to_tiebreak'=>'1'],['return_to_phase1'=>'35']] as $bad){$p->submit($bad);nav_unchanged($p,$before,'No skipped ordinary questions');}
    $v=array_fill_keys(['A1','B1','C1','D1'],3);$v['D1']=6;$p->phase1($v);nav_finish($p,$mode,'D');
    $rows=count(research_rows($sandbox));$p->submit(nav_form($p,'show_feedback','Anna palautetta'));verify($p->statuses===[200]&&!empty($p->s['show_feedback']),'Feedback form opens');
    $post=nav_form($p,'send_feedback','1');$post['feedback_text']='Eristetty navigointitestin palaute';$p->submit($post);verify(!empty($p->s['feedback_given']),'Feedback handled with mail stub');
    $p->request('/index.php',$p->lastPost);verify(count(research_rows($sandbox))===$rows,'Feedback retry does not duplicate research results');
});

foreach(['new','old'] as $mode)foreach(['answers','set','context','index-negative','index-skip','pair','mapping','question','duplicate','order','ordinary-answers','review-answers','derived-counters'] as $fault) {
    nav_case("$mode fault $fault",['mode'=>$mode,'original_pair'=>'AB','action'=>'fault-injection-'.$fault,'answers_changed'=>false,'expected_pair'=>$fault==='derived-counters'?'AB':null,'expected_track'=>$fault==='derived-counters'?'A2':null,'expected_result_category'=>$fault==='derived-counters'?'VALID_RESULT':'TECHNICAL_ERROR'],function()use($mode,$fault){
        $p=nav_begin($mode,'AB',true);$k=nav_keys($mode);nav_answer($p,$mode,'A');$set=$p->s[$k['set']];
        if($fault==='review-answers')nav_return($p);
        $patch=match($fault){'answers'=>[$k['answers']=>null],'set'=>[$k['set']=>null],'context'=>['tb1_context'=>'corrupted'],'index-negative'=>[$k['index']=>-1],'index-skip'=>[$k['index']=>4],'pair'=>['tiebreak_classes'=>['C1','D1']],'ordinary-answers','review-answers'=>['answers1_by_id'=>null],'derived-counters'=>['points1'=>['A1'=>999],'yesCounts1'=>['A1'=>999],$mode==='new'?'tb1_new_counters':'tiebreak_points'=>['A'=>999]],default=>[]};
        if($fault==='mapping'){if($mode==='new')$set[0]['block_left']='Z';else$set[0]['class']='Z1';$patch[$k['set']]=$set;}
        if($fault==='question'){unset($set[0][$mode==='new'?'option_left':'text']);$patch[$k['set']]=$set;}
        if($fault==='duplicate'){$set[1]=$set[0];$patch[$k['set']]=$set;}
        if($fault==='order'){[$set[0],$set[1]]=[$set[1],$set[0]];$patch[$k['set']]=$set;}
        $p->corrupt($patch);
        if($fault==='derived-counters'){nav_integrity($p);nav_finish($p,$mode,'A','VALID_RESULT',true);}else{nav_technical($p);verify(json_decode(file_get_contents($p->dir.'/data/invites.json'),true)[0]['status']==='accepted','Technical fault never completes invitation');}
    });
}

foreach(['missing-file','missing-pair','malformed-no-fallback','missing-legacy-side'] as $fault)nav_case("configuration $fault",['mode'=>$fault==='missing-legacy-side'?'old':'new','initial_state'=>'intro','original_pair'=>'AB','action'=>'configuration-fault-'.$fault,'answers_changed'=>false,'expected_pair'=>'AB','expected_track'=>str_contains($fault,'missing-')&&$fault!=='missing-legacy-side'?'A2':null,'expected_result_category'=>in_array($fault,['missing-file','missing-pair'],true)?'VALID_RESULT':'TECHNICAL_ERROR'],function()use($fault){
    global $sandbox,$originals,$base;
    try{
        if($fault==='missing-file')configure($sandbox,'SeparateBlocksQuestionsTiebreak.csv',null);
        else configure($sandbox,'SeparateBlocksQuestionsTiebreak.csv',preg_replace('/^\d+;AvsB;.*\R?/m','',$originals['SeparateBlocksQuestionsTiebreak.csv']).($fault==='malformed-no-fallback'?"999;AvsB;invalid;statement;;right\n998;AvsA;bad;statement;left;right\n":''));
        if(in_array($fault,['malformed-no-fallback','missing-legacy-side'],true))configure($sandbox,'SeparateBlocksQuestions.csv',preg_replace($fault==='missing-legacy-side'?'/^A11;.*\R?/m':'/^[AB]11;.*\R?/m','',$originals['SeparateBlocksQuestions.csv']));
        $p=new Participant($base,$sandbox);$p->begin('Configuration','',$fault==='missing-legacy-side'?'oldTB1=1':'');$p->phase1(nav_values('AB'));
        if(in_array($fault,['malformed-no-fallback','missing-legacy-side'],true)){nav_technical($p);return;}
        verify($p->s['state']==='tiebreak_intro','Valid configured legacy fallback');nav_post($p,'continue_tiebreak','1');nav_answer($p,'old','A');nav_return($p);nav_review($p);nav_finish($p,'old','A');
    }finally{foreach($originals as $f=>$c)configure($sandbox,$f,$c);}
});

foreach(['new','old'] as $mode)nav_case("$mode write failure after pair change",['mode'=>$mode,'original_pair'=>'AB','action'=>'AB-to-CD-then-result-write-failure','answers_changed'=>true,'expected_pair'=>'CD','expected_track'=>'C2','expected_result_category'=>'TECHNICAL_ERROR'],function()use($mode){
    global $sandbox;
    $p=nav_begin($mode,'AB',true);nav_answer($p,$mode,'A');nav_return($p);nav_review($p,nav_values('CD'));nav_fresh($p,$mode,'CD');$k=nav_keys($mode);while($p->s['state']===$k['state'])nav_answer($p,$mode,'C');
    $path=$sandbox.'/data/survey_log_'.date('Y-m').'.csv';$backup=is_file($path)?file_get_contents($path):null;if(is_file($path))unlink($path);mkdir($path);
    $emails=is_file($sandbox.'/data/emails.jsonl')?file_get_contents($sandbox.'/data/emails.jsonl'):'';
    try{$p->phase2(favor('1'));verify($p->s['state']==='phase2_unresolved'&&empty($p->s['result_saved']),'Write failure remains technical');verify(json_decode(file_get_contents($sandbox.'/data/invites.json'),true)[0]['status']==='accepted','Write failure does not complete invite');verify((is_file($sandbox.'/data/emails.jsonl')?file_get_contents($sandbox.'/data/emails.jsonl'):'')===$emails,'Write failure sends no notification');}
    finally{rmdir($path);if($backup!==null)file_put_contents($path,$backup);}
    nav_technical($p);
});

foreach($argv as $arg)if(str_starts_with($arg,'--matrix='))file_put_contents(substr($arg,9),json_encode($GLOBALS['nav_matrix']??[],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL);
