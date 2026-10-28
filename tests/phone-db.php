<?php
declare(strict_types=1);
require __DIR__.'/../private/bootstrap.php';
// This runs only against the disposable MySQL CI service.
if(DB_NAME!=='gangchil_ci')throw new RuntimeException('Disposable CI database required');
database()->exec("CREATE TABLE users(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(120) NOT NULL,email VARCHAR(190) NOT NULL UNIQUE,password_hash VARCHAR(255) NOT NULL,role VARCHAR(20) NOT NULL,active TINYINT NOT NULL DEFAULT 1,permissions JSON NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
$hash=password_hash('ci-password-12345',PASSWORD_DEFAULT);
query('INSERT INTO users(name,email,password_hash,role,permissions) VALUES(?,?,?,?,?)',['Existing owner','legacy@example.test',$hash,'owner','{}']);
ensure_phone_schema();ensure_phone_schema();
$legacy=query('SELECT * FROM users WHERE id=1')->fetch();
if($legacy['phone']!==null||!password_verify('ci-password-12345',$legacy['password_hash'])||$legacy['email']!=='legacy@example.test')throw new RuntimeException('Migration changed existing account');
query('UPDATE users SET phone=? WHERE id=1',[bd_phone('+8801712345678')]);
query('INSERT INTO users(name,phone,password_hash,role,permissions) VALUES(?,?,?,?,?)',['New staff','01812345678',$hash,'staff','{}']);
try {query('INSERT INTO users(name,phone,password_hash,role) VALUES(?,?,?,?)',['Duplicate',bd_phone('8801712345678'),$hash,'staff']);throw new RuntimeException('Duplicate phone accepted');}
catch(PDOException $e){if($e->getCode()!=='23000')throw $e;}
$_SESSION['uid']=1;$user=current_user();
if($user['phone']!=='01712345678'||array_key_exists('email',$user))throw new RuntimeException('Account output must use phone only');
if(in_array('tables:manage',permissions_for('manager'),true))throw new RuntimeException('Removed permission remains');
echo "PASS: legacy account migration, password preservation, phone-only staff inserts, unique normalized phones and profile output\n";
