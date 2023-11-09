<?php

use cjrasmussen\BlueskyApi\BlueskyApi;

    function bluesky_connect($handle, $password)
    {

    $connection = new BlueskyApi($handle, $password);
    return $connection;

    }

    function upload_media_to_bluesky($connection, $s3name)
	{

	// have we been passed a file?
	if (empty($s3name)) return;
    
    $body = file_get_contents($s3name);
    if(filter_var($s3name, FILTER_VALIDATE_URL)){

        $headers = get_headers($s3name, 1); 

        if (isset($headers['Content-Type'])) {
            $mime = $headers['Content-Type'];
        } else {
            $mime ='image/jpeg';
        }
     }else{
      $mime = mime_content_type($s3name);
     }

    $response = $connection->request('POST', 'com.atproto.repo.uploadBlob', [], $body, $mime);

    $image = $response->blob;

    return $image;

  }

	function post_to_bluesky($connection, $text, $media='', $link='')
	{

    // parse for URLS
    $urls = mark_urls($text);
    $links = array();
    if (!empty($urls)){
      foreach ($urls as $url) {
        $a = [
          "index" => [
            "byteStart" => $url['start'],
            "byteEnd" => $url['end'],
          ],
          "features" => [
            [
                '$type' => "app.bsky.richtext.facet#link",
                'uri' => $url['url'], 
            ],
          ],
        ];

        $links[] = $a;
      }
      $links = [
        'facets' =>
          $links,
      ];
    }

    // add any media
    $embed = '';
    if (!empty($media)){
      $embed = [
        'embed' => [
          '$type' => 'app.bsky.embed.images',
          'images' => [
            [
              'alt' => '',
              'image' => $media,
            ],
          ],
        ],
      ];
    }

    // add any link
    if (!empty($link)){
      $embed = fetch_link_card($connection, $link);
    }

    // build the final arguments
    $args = [
      'collection' => 'app.bsky.feed.post',
      'repo' => $connection->getAccountDid(),
      'record' => [
        'text' => $text,
        'langs' => ['en'],
        'createdAt' => date('c'),
        '$type' => 'app.bsky.feed.post',
      ],
    ];

    if (!empty($embed)) $args['record'] = array_merge($args['record'], $embed);
    if (!empty($links)) $args['record'] = array_merge($args['record'], $links);

    // send to bluesky
    return $connection->request('POST', 'com.atproto.repo.createRecord', $args);
 
  }

    function mark_urls($text) {

    $regex = '/(https?:\/\/[^\s]+)/';
    preg_match_all($regex, $text, $matches, PREG_OFFSET_CAPTURE);

    $urlData = array();

    foreach ($matches[0] as $match) {
        $url = $match[0];
        $start = $match[1];
        $end = $start + strlen($url);

        $urlData[] = array(
            'start' => $start,
            'end' => $end,
            'url' => $url,
        );
    }

    return $urlData;
  }

function fetch_link_card($connection, $url) {

    // The required fields for every embed card
    $card = [
        "uri" => $url,
        "title" => "",
        "description" => "",
    ];

    // Create a new DOMDocument
    $doc = new DOMDocument();

    // Suppress errors for invalid HTML, if needed
    libxml_use_internal_errors(true);

    // Load the HTML from the URL
    $doc->loadHTMLFile($url);

    // Restore error handling
    libxml_use_internal_errors(false);

    // Create a new DOMXPath object for querying the document
    $xpath = new DOMXPath($doc);

    // Query for "og:title" and "og:description" meta tags
    $title_tag = $xpath->query('//meta[@property="og:title"]/@content');
    if ($title_tag->length > 0) {
        $card["title"] = $title_tag[0]->nodeValue;
    }

    $description_tag = $xpath->query('//meta[@property="og:description"]/@content');
    if ($description_tag->length > 0) {
        $card["description"] = $description_tag[0]->nodeValue;
    }

    // If there is an "og:image" meta tag, fetch and upload that image
    $image_tag = $xpath->query('//meta[@property="og:image"]/@content');
    if ($image_tag->length > 0) {
        $img_url = $image_tag[0]->nodeValue;
        // Naively turn a "relative" URL (just a path) into a full URL, if needed
        if (!parse_url($img_url, PHP_URL_SCHEME)) {
            $img_url = $url . $img_url;
        }
        
        $image = upload_media_to_bluesky($connection, $img_url, $s3=FALSE);
    }

    $embed = '';
    $embed = [
      'embed' => [
        '$type' => 'app.bsky.embed.external',
        'external' => [
            'uri' => $card['uri'],
            'title' => $card['title'],
            'description' => $card['description'],
            'thumb' => $image,
        ],
      ],
    ];

    return $embed;
    
  }
