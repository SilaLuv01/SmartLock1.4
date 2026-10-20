<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') { http_response_code(404);exit; }
$root=realpath($argv[1]??'');$out=$argv[2]??'';
$absolute=str_starts_with($out,'/') || (strlen($out)>=3 && ctype_alpha($out[0]) && $out[1]===':' && in_array($out[2],['/',chr(92)],true));
if(!$root || !is_file($root.'/app/bootstrap.php') || !$absolute)
    throw new RuntimeException('Usage: php bin/export_public.php ABSOLUTE_BACKEND_DIR ABSOLUTE_EMPTY_OUTPUT_DIR');
if(is_dir($out) && count(scandir($out))>2) throw new RuntimeException('Output directory must be empty; refusing to overwrite files');
if(!is_dir($out) && !mkdir($out,0755,true)) throw new RuntimeException('Cannot create output directory');
if(!is_dir($out.'/assets') && !mkdir($out.'/assets',0755)) throw new RuntimeException('Cannot create asset directory');
$from='$root = getenv(\'SMARTLOCK_WEB_ROOT\') ?: dirname(__DIR__);';
$to='$root = getenv(\'SMARTLOCK_WEB_ROOT\') ?: '.var_export($root,true).';';
foreach(['index.php','api.php','read.php','status.php'] as $name){
    $index=file_get_contents(dirname(__DIR__).'/public/'.$name);
    $index=str_replace($from,$to,$index,$count);
    if($count!==1) throw new RuntimeException('Unexpected entry point; no publication performed');
    file_put_contents($out.'/'.$name,$index);
}
foreach(['style.css','realtime.js'] as $name) copy(dirname(__DIR__).'/public/assets/'.$name,$out.'/assets/'.$name);
echo "Public payload prepared. No configuration, SQL, or passwords included.\n";
