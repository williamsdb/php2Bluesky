<?php

    require __DIR__ . '/vendor/autoload.php';
    require __DIR__ . '/functions.php';

    // change the follow to your details without the <>
    $handle = '<your Bluesky handle>';
    $password = '<your API password>';
    $filename = '<local or remote path to image>';
    $link = '<url of page for the link card>';
    $fileUploadDir = "/tmp"; // location for image temporary files with no trailing slash
    $text = 'Hello World! https://spokenlikeageek.com';
    $alt = 'This is a description of the image';
    
    // connect to Bluesky API
    // this makes a connection to Bluesky using the BlueskyApi
    // see following url for more details including managing tokens:
    // https://github.com/cjrasmussen/BlueskyApi
    $connection = bluesky_connect($handle, $password);

    // send a text only post with link
    $response = post_to_bluesky($connection, $text);
    print_r($response);

    // send an image with text, alt text and a link
    $image = upload_media_to_bluesky($connection, $filename, $fileUploadDir, '', $alt);
    $response = post_to_bluesky($connection, $text, $image);
    print_r($response);

    // send multiple images, alt text with text
    $image1 = upload_media_to_bluesky($connection, $filename1);
    $image2 = upload_media_to_bluesky($connection, $filename2);
    $image3 = upload_media_to_bluesky($connection, $filename3);
    $image4 = upload_media_to_bluesky($connection, $filename4);
    $imageArray= array($image1, $image2, $image3, $image4); 
    $alt= array('this has an alt', 'so does this');
    $response = post_to_bluesky($connection, $text, $imageArray, '', $alt);
    print_r($response);

    // send text with link for a link card
    // if you specifiy both media and link the latter takes precedence
    $response = post_to_bluesky($connection, $text, '', $link);
    print_r($response);

    // get the permalink of the post
    if (!isset($response->error)){
        $url = permalink_from_response($response, $handle);
        echo $url.PHP_EOL;            
    }
?>