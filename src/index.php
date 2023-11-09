<?php

    require __DIR__ . '/vendor/autoload.php';
    require __DIR__ . '/functions.php';

    $handle = '<your Bluesky handle>';
    $password = '<your API password>';
    $filename = '<local or remote path to image>';
    $link = '<url of page for the link card>';
    $text = 'Hello World! https://spokenlikeageek.com';
    
    // connect to Bluesky API
    $connection = bluesky_connect($handle, $password);

    // send a text only post with link
    $response = post_to_bluesky($connection, $text);
    print_r($response);

    // send an image with text and a link
    $image = upload_media_to_bluesky($connection, $filename);
    $response = post_to_bluesky($connection, $text, $image);
    print_r($response);

    // send text with link for a link card
    // if you specifiy both media and link the latter takes precedence
    $response = post_to_bluesky($connection, $text, '', $link);
    print_r($response);

?>