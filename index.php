<?php

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . 'functions.php';

$password = '<your API password>';
$handle = '<your Bluesky handle>';
$text = 'Hello World! https://spokenlikeageek.com';
 
// connect to Bluesky API
$connection = bluesky_connect($handle, $password);

// example use
//tbd

?>