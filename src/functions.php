<?php

    use cjrasmussen\BlueskyApi\BlueskyApi;

    function bluesky_connect($handle, $password)
    {

        $connection = new BlueskyApi($handle, $password);
        return $connection;

    }

    function upload_media_to_bluesky($connection, $filename)
	{

        // have we been passed a file?
        if (empty($filename)) return;
        
        $body = file_get_contents($filename);
        if(filter_var($filename, FILTER_VALIDATE_URL)){
            $headers = get_headers($filename, 1); 

            if (isset($headers['Content-Type'])) {
                $mime = $headers['Content-Type'];
            } else {
                // shouldn't have got here so issue that needs to be handled!
            }
        }else{
            $mime = mime_content_type($filename);
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
        }

        // parse for Mentions
        $mentionsData = mark_mentions($connection, $text);
        $mentions = array();
        if (!empty($mentionsData)){
            foreach ($mentionsData as $mention) {
                $a = [
                "index" => [
                    "byteStart" => $mention['start'],
                    "byteEnd" => $mention['end'],
                ],
                "features" => [
                    [
                        '$type' => "app.bsky.richtext.facet#mention",
                        'did' => $mention['did'], 
                    ],
                ],
                ];

                $mentions[] = $a;
            }
        }

        // now form the arguments for links and mentions
        $facets = array_merge($mentions, $links);
        $facets = [
            'facets' =>
            $facets,
        ];

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

        // add any link cards
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
        if (!empty($facets)) $args['record'] = array_merge($args['record'], $facets);

        // send to bluesky
        return $connection->request('POST', 'com.atproto.repo.createRecord', $args);
 
    }

    function mark_mentions($connection, $text) {

        $spans = [];
        $mention_regex = '/[$|\W](@([a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)/u';
        $text_bytes = mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
        preg_match_all($mention_regex, $text_bytes, $matches, PREG_OFFSET_CAPTURE);

        $mentionsData = array();
        
        foreach ($matches[1] as $match) {
            $did = get_did_from_handle($connection, substr($match[0], 1));
            if (!empty($did->did)){
                $mentionsData[] = [
                    "start" => $match[1],
                    "end" => $match[1] + strlen($match[0]),
                    "did" => $did->did,
                ];    
            }
        }
        return $mentionsData;
    }

    function get_did_from_handle($connection, $handle){

        $args = [
            'handle' => $handle,
        ];
    
        // send to bluesky to get the did for the given handle
        return  $connection->request('GET', 'com.atproto.identity.resolveHandle', $args);  

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

?>