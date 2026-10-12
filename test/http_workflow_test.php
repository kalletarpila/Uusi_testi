<?php declare(strict_types=1);
// Real loopback HTTP requests, cookies, PHP sessions, forms and redirects.
// php test/http_workflow_test.php [--probe] [--keep]
require_once __DIR__ . '/isolated_app.php';
$root = dirname(__DIR__);
$sandbox = create_isolated_app($root, true);
$checks = $passed = $failed = $requests = $skipped = 0;
$match = null; foreach ($argv as $arg) if (str_starts_with($arg,'--match=')) $match=substr($arg,8);
$failures = [];
$navigationOnly = in_array('--navigation-only', $argv, true);
function verify(bool $ok, string $message): void {
    global $checks;
    $checks++;
    if (!$ok) throw new RuntimeException($message);
}
function scenario(string $name, callable $test): void {
    global $passed, $failed, $failures, $match, $skipped;
    if ($match !== null && !preg_match('~'.$match.'~', $name)) { $skipped++; return; }
    try { $test(); $passed++; echo "PASS $name\n"; }
    catch (Throwable $e) { $failed++; $failures[] = "$name: " . $e->getMessage(); echo "FAIL " . end($failures) . "\n"; }
}
class Participant {
    public string $cookie = '', $html = '';
    public array $s = [], $statuses = [], $lastPost = [];
    public function __construct(public string $base, public string $dir) { $this->request(); }
    public function request(string $path = '/index.php', ?array $post = null): void {
        global $requests;
        $this->statuses = [];
        for ($redirect = 0; $redirect < 5; $redirect++) {
            $options = ['method'=>$post === null ? 'GET':'POST', 'ignore_errors'=>true, 'follow_location'=>0, 'timeout'=>10,
                'header'=>"Connection: close\r\n" . ($this->cookie ? 'Cookie: PHPSESSID='.$this->cookie."\r\n":'')];
            if ($post !== null) { $options['content'] = http_build_query($post); $options['header'] .= "Content-Type: application/x-www-form-urlencoded\r\n"; }
            $http_response_header = [];
            $body = @file_get_contents($this->base.$path, false, stream_context_create(['http'=>$options]));
            $requests++;
            verify($body !== false, 'Loopback HTTP server response');
            preg_match('/\s(\d{3})\s/', $http_response_header[0], $m);
            $status = (int)($m[1] ?? 0); $this->statuses[] = $status;
            $location = '';
            foreach ($http_response_header as $h) {
                if (preg_match('/^Set-Cookie: PHPSESSID=([^;]*)/i', $h, $m)) $this->cookie = stripos($h,'Max-Age=0') !== false ? '' : $m[1];
                if (stripos($h, 'Location:') === 0) $location = trim(substr($h, 9));
            }
            if ($location && in_array($status,[302,303],true)) {
                $path = str_starts_with($location,$this->base) ? substr($location,strlen($this->base)) : $location;
                verify(str_starts_with($path,'/'), 'Redirect remains local');
                $post = null; continue;
            }
            $this->html = $body;
            verify($status < 500, 'HTTP error '.$status.': '.substr(strip_tags($body),0,200));
            verify(!preg_match('/(?:Warning|Fatal error|Notice):/', $body), 'No PHP diagnostics in participant HTML');
            $snapshot = $this->dir.'/data/state-'.$this->cookie.'.json';
            $this->s = is_file($snapshot) ? json_decode(file_get_contents($snapshot), true) : [];
            return;
        }
        throw new RuntimeException('Redirect loop');
    }
    public function submit(array $post, string $path = '/index.php'): void {
        // Read the actual rendered form's hidden token when present. No business state is injected.
        if (preg_match('/name="workflow_token" value="([^"]+)"/', $this->html, $m)) $post['workflow_token'] = html_entity_decode($m[1]);
        $this->lastPost = $post; $this->request($path,$post);
    }
    public function begin(string $nick = 'HTTP Ääkköset', string $code = '', string $query = ''): void {
        if ($query) $this->request('/index.php?'.$query);
        $this->submit(['start'=>'1','nickname'=>$nick,'ennea'=>'en tiedä','invite_code'=>$code], '/index.php'.($query?'?'.$query:''));
        verify(($this->s['state']??'') === 'quiz1', 'Intro -> quiz1');
    }
    public function phase1(array $values): void {
        $guard=0; $total=count($this->s['phase1']); $scores=$this->s['points1'];
        while (($this->s['state']??'') === 'quiz1' && $guard++ < 50) {
            $q=$this->s['phase1'][$this->s['q1_index']];
            verify(str_contains($this->html, htmlspecialchars($q['text'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')), 'Phase1 question rendered');
            $value=$values[$q['class']]; if (is_callable($value)) $value=$value($q);
            $scores[$q['class']]+=$value;
            $this->submit(['choice'=>(string)$value]);
            verify($this->statuses === [303,200], 'Phase1 POST redirects to GET');
            verify($this->s['points1']===$scores, 'Exact Phase1 scores across HTTP');
        }
        verify($guard === $total && count($this->s['answers1']) === $total, 'Every configured Phase1 question answered once');
    }
    public function reach(string $track): void {
        $this->begin(); $values = array_fill_keys(['A1','B1','C1','D1'],1); $values[$track[0].'1']=6;
        $this->phase1($values);
        verify(($this->s['track']??'') === $track && $this->s['state']==='phase2_intro', 'Correct Phase1 winner/track');
    }
    public function phase2(callable $answer): void {
        $this->submit(['continue_phase2'=>'1']);
        verify($this->s['state']==='phase2', 'Phase2 intro POST');
        verify(count($this->s['blocks2'])===18, 'All 18 standard questions');
        $seen=[]; $scores=$this->s['varPoints'];
        while ($this->s['state']==='phase2') {
            $b=$this->s['blocks2'][$this->s['q2_index']];
            verify(!isset($seen[$b['question_number']]), 'Question asked only once'); $seen[$b['question_number']]=true;
            verify(str_contains($this->html,htmlspecialchars($b['desc'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')), 'Phase2 question rendered');
            [$side,$value]=$answer($b);
            if ($value) $scores[$b[$side.'_var']]+=$value;
            $this->submit(['choice2_value'=>(string)$value,'choice2_side'=>$side]);
            verify($this->statuses === [303,200], 'Phase2 POST redirects to GET');
            verify($this->s['varPoints']===$scores, 'Exact Phase2 scores across HTTP');
        }
        verify(count($seen)===18, '18 questions actually presented/answered');
    }
    public function tb2(callable $answer): void {
        verify($this->s['state']==='phase2_tb','TB2 state');
        $seen=[]; $scores=$this->s['varPoints']; $configured=$this->s['tb2_set'];
        while ($this->s['state']==='phase2_tb') {
            $b=$this->s['tb2_set'][$this->s['tb2_index']];
            verify(!isset($seen[$b['question_number']]), 'TB2 question once'); $seen[$b['question_number']]=true;
            [$side,$value]=$answer($b);
            if ($value) $scores[$b[$side.'_var']]+=$value;
            $this->submit(['tb2_value'=>(string)$value,'tb2_side'=>$side]);
            verify($this->s['varPoints']===$scores, 'Exact TB2 accumulation');
        }
        verify(count($seen)===count($configured),'All configured TB2 questions answered');
    }
    public function corrupt(array $patch): void {
        $this->request('/fixture.php', ['patch'=>json_encode($patch)]); $this->request();
    }
    public function finish(string $type): array {
        verify(($this->s['state']??'')==='done2' && (string)$this->s['log_row']['finalVar']===$type, 'Completed result '.$type);
        $matching=[];
        foreach (research_rows($this->dir) as $row) if ($row['session_id']===$this->cookie) $matching[]=$row;
        verify(count($matching)===1,'Exactly one research row per session, got '.count($matching)); $row=$matching[0];
        verify($row['final_type']===$type,'CSV final type');
        $events=[];
        foreach(file($this->dir.'/data/index_app_'.date('Y-m').'.log') as $line) if(preg_match('/RESULT: (\{.*\})/', $line,$m)){$event=json_decode($m[1],true);if(($event['session_id']??'')===$this->cookie)$events[]=$event;}
        verify(count($events)===1,'Exactly one completion audit event');
        verify($events[0]['scores']==$this->s['varPoints'],'All final scores in app log');
        verify($events[0]['result_status']===($type==='RATKAISEMATON'?'UNRESOLVED_TIE':'VALID_RESULT'),'Explicit result classification');
        verify($events[0]['resolution_method']===($type==='RATKAISEMATON'?'UNRESOLVED_TIE':'points'),'Resolution method logged');
        verify((int)$row['top_variable_1_points']===max($this->s['varPoints']),'CSV final leading score');
        foreach ($this->s['answers1']??[] as $i=>$a) verify($row['Q'.($i+1)]===$a['class'].'-'.$a['question_number'].'-'.$a['value'],'CSV Phase1 answer');
        foreach ($this->s['pairs2']??[] as $i=>$a) verify($row['Case'.($i+1)]===$a,'CSV Phase2 answer');
        foreach ($this->s['tb2_pairs']??[] as $i=>$a) verify($row['TB2_'.($i+1)]===$a,'CSV TB2 answer');
        for ($i=count($this->s['answers1'])+1;$i<=40;$i++) verify($row['Q'.$i]==='','Empty Phase1 slot');
        for ($i=count($this->s['pairs2']??[])+1;$i<=36;$i++) verify($row['Case'.$i]==='','Empty Case slot');
        if ($type==='RATKAISEMATON') {
            verify(str_contains($this->html,'Testi ei pystynyt erottamaan yhtä enneagrammityyliä.'),'Unresolved Finnish text');
            verify(str_contains($this->html,'<strong>'.implode(', ',$this->s['log_row']['tied_types']).'</strong>'),'Only actual remaining types');
            verify(!str_contains($this->html,'TYYLI ')&&!str_contains($this->html,'Kuvausta ei löytynyt'),'No arbitrary unresolved description');
        } else verify(str_contains($this->html,'TYYLI '.$type)&&!str_contains($this->html,'Kuvausta ei löytynyt'),'Normal result description');
        $rows=count(research_rows($this->dir)); $this->request(); $this->request('/index.php',$this->lastPost);
        verify(count(research_rows($this->dir))===$rows,'GET refresh and completion POST retry do not duplicate row');
        return $row;
    }
}
function research_rows(string $dir): array {
    $files=glob($dir.'/data/survey_log_*.csv'); if (!$files) return [];
    $fp=fopen($files[0],'r'); $header=fgetcsv($fp,0,';','"','\\'); $header[0]=ltrim($header[0],"\xEF\xBB\xBF");
    $expected=['date','time','session_id','nick','user_guess','user_confidence','invited','ref_guess','ref_prob','invite_id','final_type','top_variable_1','top_variable_1_points','top_variable_2','top_variable_2_points','A1_points','B1_points','C1_points','D1_points','A1_yes_count','B1_yes_count','C1_yes_count','D1_yes_count','A1_tiebreak_points','B1_tiebreak_points','C1_tiebreak_points','D1_tiebreak_points','Phase1_tb','Phase2_tb'];
    foreach(['Q'=>40,'TB1_OLD_'=>10,'TB1_'=>7] as $prefix=>$total) for($i=1;$i<=$total;$i++)$expected[]=$prefix.$i;
    array_push($expected,'A1_tb1_counter','B1_tb1_counter','C1_tb1_counter','D1_tb1_counter');
    foreach(['Case'=>36,'TB2_'=>10] as $prefix=>$total) for($i=1;$i<=$total;$i++)$expected[]=$prefix.$i;
    verify($header===$expected,'Exact existing 136-column header and order');
    $rows=[];
    while (($row=fgetcsv($fp,0,';','"','\\'))!==false) { verify(count($row)===count($header),'CSV delimiters preserve row width'); $rows[]=array_combine($header,$row); }
    fclose($fp); return $rows;
}
function favor(string $target): Closure { return fn($b)=>$b['left_var']===$target?['left',2]:($b['right_var']===$target?['right',2]:['neutral',0]); }
function neutral(): Closure { return fn($b)=>['neutral',0]; }
function configure(string $dir,string $name,?string $contents): void { $path=$dir.'/'.$name; if ($contents===null) unlink($path); else file_put_contents($path,$contents); }

$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error); if (!$socket) throw new RuntimeException($error);
$address=stream_socket_get_name($socket,false); fclose($socket); $base='http://'.$address;
file_put_contents($sandbox.'/fixture.php', <<<'PHP'
<?php
session_start();
if (isset($_POST['patch'])) foreach (json_decode($_POST['patch'],true) as $key=>$value) { if ($value===null) unset($_SESSION[$key]); else $_SESSION[$key]=$value; }
echo 'fixture';
PHP);
$process=proc_open([PHP_BINARY,'-d','session.save_path='.$sandbox.'/data','-S',$address,'-t',$sandbox], [0=>['pipe','r'],1=>['file',$sandbox.'/server.log','a'],2=>['file',$sandbox.'/server.log','a']],$pipes,$sandbox);
if (!is_resource($process)) throw new RuntimeException('PHP server unavailable');
fclose($pipes[0]);
for ($i=0;$i<50;$i++) { $fp=@fsockopen('127.0.0.1',(int)substr(strrchr($address,':'),1),$errno,$error,0.1); if ($fp) { fclose($fp); break; } usleep(50000); }
echo "LOCAL $base ; isolated app $sandbox\n";
foreach (['InsideBlockQuestions.csv','SeparateBlocksQuestions.csv','SeparateBlocksQuestionsTiebreak.csv','TypeDescriptions.csv'] as $file) echo 'INPUT '.$file.' SHA256 '.hash_file('sha256',$sandbox.'/'.$file)."\n";
$groups=['A2'=>['4','7','9'],'B2'=>['3','7','8'],'C2'=>['1','5','6'],'D2'=>['2','6','9']];
$originals=[]; foreach (['InsideBlockQuestions.csv','SeparateBlocksQuestions.csv','SeparateBlocksQuestionsTiebreak.csv'] as $file) $originals[$file]=file_get_contents($sandbox.'/'.$file);
try {
    if ($navigationOnly) {
        require __DIR__.'/http_workflow_cases.php'; // Shared fixtures only in this mode.
        require __DIR__.'/phase1_navigation_cases.php';
    } else {
    foreach ($groups as $track=>$types) foreach ($types as $target) scenario("normal $track -> $target",function()use($base,$sandbox,$track,$target){$p=new Participant($base,$sandbox);$p->reach($track);$p->phase2(favor($target));$p->finish($target);});

    scenario('CSV Unicode and semicolon nickname',function()use($base,$sandbox){$p=new Participant($base,$sandbox);$p->begin('Ääkköset; tutkija');$p->phase1(['A1'=>6,'B1'=>1,'C1'=>1,'D1'=>1]);$p->phase2(favor('4'));$r=$p->finish('4');verify($r['nick']==='Ääkköset; tutkija'&&mb_check_encoding($r['nick'],'UTF-8'),'Unicode nickname intact');});
    scenario('Repeated answer POST cannot answer the next question',function()use($base,$sandbox){$p=new Participant($base,$sandbox);$p->begin();$p->submit(['choice'=>'6']);$state=$p->s;$p->request('/index.php',$p->lastPost);verify($p->s['q1_index']===$state['q1_index']&&$p->s['points1']===$state['points1'],'Stale Phase1 POST rejected');});
    scenario('Missing Phase2 file is a technical error',function()use($base,$sandbox,$originals){configure($sandbox,'InsideBlockQuestions.csv',null);try{$p=new Participant($base,$sandbox);$p->begin();$p->phase1(['A1'=>6,'B1'=>1,'C1'=>1,'D1'=>1]);verify($p->s['state']==='phase2_unresolved','Missing file must not enter an empty quiz');verify(!str_contains($p->html,'TYYLI '),'No personality result');}finally{configure($sandbox,'InsideBlockQuestions.csv',$originals['InsideBlockQuestions.csv']);}});
    scenario('Log write failure cannot complete an invitation',function()use($base,$sandbox){
        file_put_contents($sandbox.'/data/invites.json',json_encode([['id'=>'write-failure','code'=>'WRITEFAIL','status'=>'sent']]));
        $p=new Participant($base,$sandbox);$p->begin('Write failure','WRITEFAIL');$p->phase1(['A1'=>6,'B1'=>1,'C1'=>1,'D1'=>1]);
        $path=$sandbox.'/data/survey_log_'.date('Y-m').'.csv';$backup=is_file($path)?file_get_contents($path):null;if(is_file($path))unlink($path);mkdir($path);
        $emails=is_file($sandbox.'/data/emails.jsonl')?file_get_contents($sandbox.'/data/emails.jsonl'):'';
        try{$p->phase2(favor('4'));verify($p->s['state']==='phase2_unresolved','Failed write must not show completed saved result');verify(json_decode(file_get_contents($sandbox.'/data/invites.json'),true)[0]['status']==='accepted','Failed log does not complete actual invitation');verify((is_file($sandbox.'/data/emails.jsonl')?file_get_contents($sandbox.'/data/emails.jsonl'):'')===$emails,'Failed write does not dispatch result email');}
        finally{rmdir($path);if($backup!==null)file_put_contents($path,$backup);}
    });

    if (!in_array('--probe',$argv,true)) {
        require __DIR__.'/http_workflow_cases.php';
    }
    }
} finally {
    proc_terminate($process); proc_close($process);
    if (!in_array('--keep',$argv,true)) remove_isolated_app($sandbox);
    else echo "KEPT $sandbox\n";
}
echo "HTTP scenarios: $passed passed, $failed failed, $skipped filtered out; $checks assertions; $requests real requests\n";
if ($failed) exit(1);
