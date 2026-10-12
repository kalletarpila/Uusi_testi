<?php declare(strict_types=1);

/** A stable sidecar lock survives atomic replacement of the data file. */
function storage_locked(string $path, int $mode, callable $work) {
    $lock=@fopen($path.'.lock','c');
    if(!$lock)return false;
    $deadline=microtime(true)+4;
    try {
        do {
            if(@flock($lock,$mode|LOCK_NB))return $work();
            usleep(10000);
        }while(microtime(true)<$deadline);
        return false;
    }finally{@flock($lock,LOCK_UN);fclose($lock);}
}

function storage_write_all($fp,string $bytes):bool {
    $offset=0;$size=strlen($bytes);
    while($offset<$size){$n=@fwrite($fp,substr($bytes,$offset,65536));if($n===false||$n===0)return false;$offset+=$n;}
    return true;
}

/** Publish only a completely written file; a killed writer leaves the old file. */
function storage_replace(string $path,callable $build):bool {
    $tmp=$path.'.tmp.'.bin2hex(random_bytes(16));$fp=@fopen($tmp,'xb');
    if(!$fp)return false;
    try {
        if(!$build($fp)||!@fflush($fp))return false;
        if(function_exists('fsync')&&!@fsync($fp))return false;
        fclose($fp);$fp=null;
        // Windows readers/virus scanners can briefly deny replacement sharing.
        $deadline=microtime(true)+4;
        do {
            if(@rename($tmp,$path))return true;
            usleep(10000);
        }while(microtime(true)<$deadline);
        error_log('STORAGE_REPLACE_FAILED: '.(error_get_last()['message']??$path));
        return false;
    }finally{if(is_resource($fp))fclose($fp);if(is_file($tmp))@unlink($tmp);}
}

function storage_json_read_unlocked(string $path):?array {
    if(!file_exists($path))return [];
    $raw=@file_get_contents($path);if($raw===false||$raw==='')return null;
    try{$items=json_decode($raw,true,512,JSON_THROW_ON_ERROR);}catch(JsonException $e){return null;}
    return is_array($items)&&array_is_list($items)?$items:null;
}
function storage_json_read(string $path):?array {
    $result=storage_locked($path,LOCK_SH,fn()=>storage_json_read_unlocked($path));
    return is_array($result)?$result:null;
}
function storage_json_update(string $path,callable $change):bool {
    return storage_locked($path,LOCK_EX,function()use($path,$change){
        $items=storage_json_read_unlocked($path);if($items===null||!$change($items))return false;
        try{$bytes=json_encode($items,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}catch(JsonException $e){return false;}
        return storage_replace($path,fn($fp)=>storage_write_all($fp,$bytes));
    })===true;
}

/** Return inserted/existing/error. Header, duplicate check and row share one lock. */
function storage_csv_append(string $path,string $header,?string $line=null,?string $sessionId=null):string {
    $result=storage_locked($path,LOCK_EX,function()use($path,$header,$line,$sessionId){
        $input=null;
        try{
            if(is_file($path)){
                $input=@fopen($path,'rb');if(!$input)return 'error';
                $actual=fgetcsv($input,0,';','"','\\');
                $expected=str_getcsv(rtrim($header,"\r\n"),';','"','\\');
                if($actual!==$expected)return 'error';
                $candidate=$line===null?null:str_getcsv(rtrim($line,"\r\n"),';','"','\\');
                while(($row=fgetcsv($input,0,';','"','\\'))!==false){
                    if(count($row)!==count($expected))return 'error';
                    if($sessionId!==null&&($row[2]??'')===$sessionId)return $row===$candidate?'existing':'error';
                }
                if($line===null)return 'existing';rewind($input);
            }elseif(file_exists($path))return 'error';
            $ok=storage_replace($path,function($out)use(&$input,$header,$line){
                if($input){$size=fstat($input)['size'];if(@stream_copy_to_stream($input,$out)!==$size)return false;fclose($input);$input=null;}
                elseif(!storage_write_all($out,$header))return false;
                return $line===null||storage_write_all($out,$line);
            });
            return $ok?'inserted':'error';
        }finally{if(is_resource($input))fclose($input);}
    });
    return is_string($result)?$result:'error';
}
