<?php
declare(strict_types=1);
function config(string $key,mixed $default=null): mixed {$v=$GLOBALS['app_config']??[];foreach(explode('.',$key)as$s){if(!is_array($v)||!array_key_exists($s,$v))return$default;$v=$v[$s];}return$v;}
function configured_path(string $key): string {return str_replace('{APP_ROOT}',APP_ROOT,(string)config($key,''));}
function e(string $value): string {return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function base_url(string $page='',array $params=[]): string {$base=rtrim((string)config('app.base_path',''),'/').'/';if($page===''||$page==='home'||$page==='projects'){if($page==='projects'&&$params)$params=['page'=>'projects']+$params;return$base.($params?'?'.http_build_query($params):'');}if(str_starts_with($page,'assets/')){$file=APP_ROOT.'/'.ltrim($page,'/');return$base.ltrim($page,'/').(is_file($file)?'?v='.filemtime($file):'');}return$base.'?'.http_build_query(['page'=>$page]+$params);}
function csrf_field(): string {return '<input type="hidden" name="_token" value="'.e(\App\Core\Csrf::token()).'">';}
function flash_message(): ?array {$f=$_SESSION['flash']??null;unset($_SESSION['flash']);return is_array($f)?$f:null;}
function project_url(array $project): string {return base_url('project',['id'=>(string)$project['id']]);}
