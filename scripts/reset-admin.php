<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require_once __DIR__.'/../includes/db.php';
// Print a fresh random password once to an interactive trusted terminal.
$password=bin2hex(random_bytes(20));
$q=db()->prepare("UPDATE users SET password_hash=?,must_change_password=1 WHERE email=? AND is_admin=1");
$q->execute([password_hash($password,PASSWORD_DEFAULT),ADMIN_EMAIL]);
if($q->rowCount()!==1){fwrite(STDERR,"Admin not found.\n");exit(1);}
echo "Temporary admin password (store securely): ".$password."\n";
