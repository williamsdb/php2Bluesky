<?php

    use cjrasmussen\BlueskyApi\BlueskyApi;

    //don't change these unless Bluesky change the limits
    const maxUploadSize = 1000000;
    const maxImageUpload = 4; 

    function bluesky_connect($handle, $password)
    {

        $connection = new BlueskyApi();
        $connection->auth($handle, $password);
        return $connection;

    }

    function upload_media_to_bluesky($connection, $filename, $fileUploadDir = '/tmp')
	{

        // have we been passed a file?
        if (empty($filename)) return;
        
        // get the mime type, size and basename of the file
        $body = file_get_contents($filename);
        $basename = getFileName($filename);
        $size = strlen($body);

        // does the file size need reducing?
        if ($size > maxUploadSize){
            $newImage = imagecreatefromstring($body);
            // downsample the image until it is less than maxImageSize (if possible!)
            for ($i = 9; $i >= 1; $i--) {

                imagejpeg($newImage, $fileUploadDir.'/'.$basename,$i * 10);
                $size = strlen(file_get_contents($fileUploadDir.'/'.$basename));

                if ($size < maxUploadSize) {
                    break;
                }else{
                    unlink($fileUploadDir.'/'.$basename);
                }

            }

            $body = file_get_contents($fileUploadDir.'/'.$basename);
            unlink($fileUploadDir.'/'.$basename);
        }

        // get the file mime type
        if(filter_var($filename, FILTER_VALIDATE_URL)){
            $headers = get_headers($filename, 1); 
            if (isset($headers['Content-Type'])) {
                $mime = $headers['Content-Type'];
            } elseif (isset($headers['content-type'])) {
                $mime = $headers['content-type'];
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

        // add any media - will accept multiple images in an array or a single image as a string
        $embed = '';
        if (!empty($media)){
            if (is_array($media)){
                $k = 0;
                $mediaArray = array();
                while ($k < count($media) && $k < maxImageUpload){
                    array_push($mediaArray, [
                        'alt' => '',
                        'image' => $media[$k],
                        ]);
                    $k++;    
                }
            }else{
                $mediaArray = [
                    [
                    'alt' => '',
                    'image' => $media,
                    ]
                ];
            }
        
            $embed = [
                'embed' => [
                    '$type' => 'app.bsky.embed.images',
                    'images' => $mediaArray,
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

	function get_from_bluesky($connection, $link)
	{
        // Extract the account name
        preg_match('/\/([^\/]+)\/([^\/]+)\/([^\/]+)/', $link, $matches);
        $account = isset($matches[3]) ? $matches[3] : '';
        
        // Extract the post id
        preg_match_all('/\/([^\/]+)/', $link, $matches);
        $postId= isset($matches[1][4]) ? $matches[1][4] : '';

        $args = [
            'repo' => $account,
            'collection' => 'app.bsky.feed.post',
            'rkey' => $postId
        ];
    
        // retrieve the post from Bluesky using the extracted account name and post id
        return $connection->request('GET', 'com.atproto.repo.getRecord', $args);
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
            
            $image = upload_media_to_bluesky($connection, $img_url);
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

    function getFileName($path) {
        // If the path is a URL, use basename to get the filename
        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return basename(parse_url($path, PHP_URL_PATH));
        } else {
            // If the path is a local path, use basename to get the filename
            return basename($path);
        }
    }

?>