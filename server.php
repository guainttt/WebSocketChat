<?php
//use WebSocket\socket;
include __DIR__.'/WebSocket/Socket.php';
$ip = '0.0.0.0';
$port = 3333;
new  \WebSocket\Socket($ip,$port);