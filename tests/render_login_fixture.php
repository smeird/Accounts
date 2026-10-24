<?php
// Render the actual sign-in template with a disposable in-memory test database.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
putenv('DB_DSN=sqlite::memory:');
require_once __DIR__.'/../php_backend/Database.php';
$db=Database::getConnection();
$db->exec('CREATE TABLE settings (name TEXT PRIMARY KEY,value TEXT NOT NULL)');
$db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY,username TEXT,password TEXT)');
$db->exec('CREATE TABLE logs (level TEXT,message TEXT)');
$db->exec("INSERT INTO settings (name,value) VALUES ('site_name','Accounts Preview')");
$mode=$argv[1]??'login';
if(in_array('paper',$argv,true))$db->exec("INSERT INTO settings (name,value) VALUES ('surface_style','paper')");
$_SERVER['REQUEST_METHOD']=$mode==='error'?'POST':'GET';$_SESSION=[];$_GET=[];
if(in_array('embedded',$argv,true))$_GET['embedded']='1';
if($mode==='token'){$_SESSION['pending_user_id']=1;$_SESSION['pending_username']='Preview';}
if($mode==='error')$_POST=['username'=>'nonexistent','password'=>'wrong'];
require __DIR__.'/../index.php';
