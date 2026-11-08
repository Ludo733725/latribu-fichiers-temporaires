<?php
declare(strict_types=1);
$dir=dirname(__DIR__).'/private/la-tribu-presentation/photos';
$name=basename((string)($_GET['f']??''));
if(!$name || !preg_match('/^[a-f0-9]{32}\.(jpg|png|webp|gif)$/',$name)){http_response_code(404);exit;}
$file=$dir.'/'.$name;
if(!is_file($file)){http_response_code(404);exit;}
$mime=(new finfo(FILEINFO_MIME_TYPE))->file($file);
if(!in_array($mime,['image/jpeg','image/png','image/webp','image/gif'],true)){http_response_code(404);exit;}
header('Content-Type: '.$mime);header('X-Content-Type-Options: nosniff');header('Cache-Control: private, max-age=3600');readfile($file);
