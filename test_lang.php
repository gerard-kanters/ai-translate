<?php
$req = '/pt/';
echo 'Original REQUEST_URI: ' . $req . PHP_EOL;
if (strpos($req, '%25') !== false) {
    $req = urldecode($req);
    echo 'After urldecode: ' . $req . PHP_EOL;
} else {
    echo 'No %25 found, no decoding needed' . PHP_EOL;
}
if (preg_match('#^/([a-z]{2})(?:/|$)#i', $req, $m)) {
    echo 'Language detected: ' . $m[1] . PHP_EOL;
} else {
    echo 'No language detected' . PHP_EOL;
}