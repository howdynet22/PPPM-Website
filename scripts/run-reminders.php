<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../config.php';
require __DIR__.'/../reminders.php';
echo json_encode(send_reminders(),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
