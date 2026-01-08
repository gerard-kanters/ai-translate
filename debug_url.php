<?php
require_once 'C:\var\www\netcare.nl\wp-load.php';

$url = 'https://netcare.nl/cv-tips/';
echo 'URL: ' . $url . PHP_EOL;
echo 'url_to_postid result: ' . url_to_postid($url) . PHP_EOL;

$url2 = home_url('/cv-tips/');
echo 'home_url result: ' . $url2 . PHP_EOL;
echo 'url_to_postid home_url result: ' . url_to_postid($url2) . PHP_EOL;
?>