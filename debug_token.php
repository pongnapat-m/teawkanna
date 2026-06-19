<?php
require_once __DIR__ . '/config/route.php';
$token = 'Skltb2sySUoyM2pJYkRzY3l4b0p3TDd3L0VNTmpwYjk2andlT2xmT3BiUT0';
$decoded = decodeToken($token);
echo "DECODED: ";
var_dump($decoded);
