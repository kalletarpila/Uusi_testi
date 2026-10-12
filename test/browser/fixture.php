<?php declare(strict_types=1);
require_once dirname(__DIR__).'/isolated_app.php';
$action=$argv[1]??'';
if($action==='create'){
    $dir=create_isolated_app(dirname(__DIR__,2),true);
    // Browser reads may overlap shutdown: publish observer files atomically.
    $source=file_get_contents($dir.'/index.php');
    $old=<<<'PHP'
if (session_id() !== '') file_put_contents(__DIR__ . '/data/state-' . session_id() . '.json', json_encode($_SESSION));
PHP;
    $new=<<<'PHP'
if (session_id() !== '') {
    $snapshot=__DIR__.'/data/state-'.session_id().'.json';
    $bytes=json_encode($_SESSION);
    if(!storage_locked($snapshot,LOCK_EX,fn()=>storage_replace($snapshot,fn($fp)=>storage_write_all($fp,$bytes)))) {
        error_log('BROWSER_SNAPSHOT_FAILED');
    }
}
PHP;
    if(substr_count($source,$old)!==1)throw new RuntimeException('Snapshot observer hook changed');
    file_put_contents($dir.'/index.php',str_replace($old,$new,$source));
    foreach(['logo.png','SEGRY_turkoositausta_1.png','video-thumbnail.png'] as $asset){
        $source=dirname(__DIR__,2).'/'.$asset;if(is_file($source))copy($source,$dir.'/'.$asset);
    }
    $invites=[];
    for($i=0;$i<100;$i++)$invites[]=['id'=>'browser-'.$i,'code'=>'UI'.$i,'token'=>'ui-token-'.$i,
        'status'=>'sent','expires_at'=>'2099-01-01 00:00:00','guess_type'=>'4','guess_prob'=>'quite_certain'];
    file_put_contents($dir.'/data/invites.json',json_encode($invites));
    file_put_contents($dir.'/catalog.php', <<<'PHP'
<?php
ob_start();require __DIR__.'/index.php';while(ob_get_level())ob_end_clean();
header('Content-Type: application/json');
echo json_encode(['phase1'=>get_cached_phase1_questions(),'legacy'=>get_cached_tiebreak_questions(),
    'tb1'=>get_cached_phase1_tiebreak_blocks(),'phase2'=>get_cached_phase2_blocks(),
    'tb2'=>get_cached_phase2_tiebreaks()]);
PHP);
    echo json_encode(['dir'=>$dir]);
}elseif($action==='inspect'){
    $dir=$argv[2]??'';$resolved=realpath($dir);
    if(!$resolved||dirname($resolved)!==realpath(sys_get_temp_dir())||!str_starts_with(basename($resolved),'enneagram-test-'))throw new RuntimeException('Unsafe inspection root');
    $rows=[];$header=[];
    foreach(glob($dir.'/data/survey_log_*.csv') as $file){$fp=fopen($file,'r');$header=fgetcsv($fp,0,';','"','\\');$header[0]=ltrim($header[0],"\xEF\xBB\xBF");
        while(($row=fgetcsv($fp,0,';','"','\\'))!==false){if(count($row)!==136)throw new RuntimeException('Invalid CSV width');$rows[]=array_combine($header,$row);}fclose($fp);}
    echo json_encode(['columns'=>count($header),'rows'=>$rows,'invites'=>json_decode(file_get_contents($dir.'/data/invites.json'),true),
        'emails'=>is_file($dir.'/data/emails.jsonl')?count(file($dir.'/data/emails.jsonl')):0]);
}elseif($action==='cleanup')remove_isolated_app($argv[2]);
else throw new RuntimeException('Unknown fixture action');
