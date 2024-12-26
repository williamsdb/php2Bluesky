<?php

    use cjrasmussen\BlueskyApi\BlueskyApi;

    class RegexPatterns
    {
        public const MENTION_REGEX = '/[$|\W](@([a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)/u';
        public const URL_REGEX = '/(https?:\/\/[^\s,)\.]+(?:\.[^\s,)\.]+)*)(?<![\.,:;!?])/i';
        public const TAG_REGEX = '/(^|\s)[#＃]((?!\x{fe0f})[^\s\x{00AD}\x{2060}\x{200A}\x{200B}\x{200C}\x{200D}\x{20e2}]*[^\d\s\p{P}\x{00AD}\x{2060}\x{200A}\x{200B}\x{200C}\x{200D}\x{20e2}]+[^\s\x{00AD}\x{2060}\x{200A}\x{200B}\x{200C}\x{200D}\x{20e2}]*)?/u';
    }

    class BlueskyConsts
    {
        // don't change these unless Bluesky changes the limits
        const MAX_UPLOAD_SIZE = 1000000;
        const MAX_IMAGE_UPLOAD = 4;
        const MIN_POST_SIZE = 3;
        const MAX_POST_SIZE = 300;
    }

    // what happens when there is no link card image or missing mime type (RANDOM, BLANK or ERROR)
    const linkCardFallback = 'BLANK';

    // what happens when text is > maxPostSize
    const failOverMaxPostSize = TRUE;

    // a random image to use as a fallback
    const randomImageURL = 'https://picsum.photos/1024/536';

    // set error level
    error_reporting(E_NOTICE);
    ini_set('display_errors', 0);

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
        
        // get the file mime type
        if(filter_var($filename, FILTER_VALIDATE_URL)){
            $headers = get_headers($filename, 1); 
            if (isset($headers['Content-Type'])) {
                $mime = $headers['Content-Type'];
            } elseif (isset($headers['content-type'])) {
                $mime = $headers['content-type'];
            } else {
                $mime = '';
            }
        }else{
            $mime = mime_content_type($filename);
        }

        // if we can't determine the mime type, use the fallback
        if (empty($mime) || !isset($mime) || !is_string($mime)){
            if (strtoupper(linkCardFallback) == 'RANDOM'){
                $filename = randomImageURL;
            }elseif (strtoupper(linkCardFallback) == 'BLANK'){
                if (file_exists(__DIR__.'/blank.png')){
                    $filename = __DIR__.'/blank.png';
                }else{
                    die('BLANK specified for fallback image but blank.png is missing');
                }
            }else{
                die('Could not determine mime type of file');
            }

            // get the mime type of the fallback image
            if(filter_var($filename, FILTER_VALIDATE_URL)){
                $headers = get_headers($filename, 1); 
                if (isset($headers['Content-Type'])) {
                    $mime = $headers['Content-Type'];
                } elseif (isset($headers['content-type'])) {
                    $mime = $headers['content-type'];
                } else {
                    $mime = '';
                }
            }else{
                $mime = mime_content_type($filename);
            }
 
            // if we can't determine the mime type of the fallback, error
            if (empty($mime) || !isset($mime) || !is_string($mime)){
                die('Could not determine mime type of file');
            }
        }

        // get the size and basename of the file
        $body = file_get_contents($filename);
        $basename = getFileName($filename);
        $size = strlen($body);

        // does the file size need reducing?
        if ($size > BlueskyConsts::MAX_UPLOAD_SIZE){
            $newImage = imagecreatefromstring($body);
            // downsample the image until it is less than maxImageSize (if possible!)
            for ($i = 9; $i >= 1; $i--) {

                imagejpeg($newImage, $fileUploadDir.'/'.$basename,$i * 10);
                $size = strlen(file_get_contents($fileUploadDir.'/'.$basename));

                if ($size < BlueskyConsts::MAX_UPLOAD_SIZE) {
                    break;
                }else{
                    unlink($fileUploadDir.'/'.$basename);
                }

            }

            $body = file_get_contents($fileUploadDir.'/'.$basename);
            unlink($fileUploadDir.'/'.$basename);
        }

        // upload the file to Bluesky
        $response = $connection->request('POST', 'com.atproto.repo.uploadBlob', [], $body, $mime);

        // return the image blob
        $image = $response->blob;

        return $image;

    }

	function post_to_bluesky($connection, $text, $media='', $link='', $alt='')
	{


        // check for post > BlueskyConsts::MAX_POST_SIZE
        if (over_max_post_size($text) && failOverMaxPostSize){
            die('Provided text greater than '.BlueskyConsts::MAX_POST_SIZE);
        }

        // parse for URLs
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

        // parse for hashtags
        $hashtagData = mark_hashtags($text);
        $hashtags = array();
        if (!empty($hashtagData)){
            foreach ($hashtagData as $hashtag) {
                $a = [
                "index" => [
                    "byteStart" => $hashtag['start'],
                    "byteEnd" => $hashtag['end'],
                ],
                "features" => [
                    [
                        '$type' => "app.bsky.richtext.facet#tag",
                        'tag' => $hashtag['hashtag'], 
                    ],
                ],
                ];

                $hashtags[] = $a;
            }
        }

        // now form the arguments for links and mentions
        $facets = array_merge($hashtags, $mentions, $links);
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
                while ($k < count($media) && $k < BlueskyConsts::MAX_IMAGE_UPLOAD){
                    $altText = isset($alt[$k]) ? $alt[$k] : '';
                    array_push($mediaArray, [
                        'alt' => $altText,
                        'image' => $media[$k],
                        ]);
                    $k++;    
                }
            }else{
                $mediaArray = [
                    [
                    'alt' => $alt,
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
            $embed = fetch_link_card($connection, $link, $media);
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

    // take a response from post_to_bluesky and return a permalink
    function permalink_from_response($response, $handle){

        // Extract the post id
        preg_match('/\/([^\/]+)$/', $response->uri, $matches);

        // Check if a match was found and return it
        if (!empty($matches[1])) {
            return 'https://bsky.app/profile/'.$handle.'/post/'.$matches[1];
        } else {
            echo "No post id found.";
        }

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
        preg_match_all(RegexPatterns::MENTION_REGEX, $text, $matches, PREG_OFFSET_CAPTURE);

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
        preg_match_all(RegexPatterns::URL_REGEX, $text, $matches, PREG_OFFSET_CAPTURE);

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

    function mark_hashtags($text) {
        // Regex to find and remove URLs
        preg_match_all(RegexPatterns::URL_REGEX, $text, $urlMatches, PREG_OFFSET_CAPTURE);
    
        // Replace URLs in the text with placeholders of the same length
        $cleanText = $text;
        foreach ($urlMatches[0] as $urlMatch) {
            $url = $urlMatch[0];
            $start = $urlMatch[1];
            $urlLength = strlen($url);
    
            // Replace URL with spaces to maintain position alignment
            $cleanText = substr_replace($cleanText, str_repeat(' ', $urlLength), $start, $urlLength);
        }

        // Regex to find hashtags
        preg_match_all(RegexPatterns::TAG_REGEX, $cleanText, $matches, PREG_OFFSET_CAPTURE);
    
        $hashtagData = array();
    
        foreach ($matches[0] as $match) {
            $originalHashtag = $match[0]; // Capture the hashtag as it appears in the text
            $start = $match[1];
    
            // Exclude preceding space (if any) from the start
            if ($originalHashtag[0] === ' ') {
                $start += 1;
                $originalHashtag = substr($originalHashtag, 1);
            }
    
            // Clean the hashtag (removing trailing punctuation)
            $cleanedHashtag = clean_hashtag($originalHashtag);
    
            // Calculate the correct end position after cleaning
            $end = $start + strlen($cleanedHashtag);
    
            $hashtagData[] = array(
                'start' => $start,
                'end' => $end,
                'hashtag' => substr($cleanedHashtag, 1) // Remove the '#' or '＃' prefix
            );
        }
    
        return $hashtagData;
    }
    
    function clean_hashtag($tag) {
        // Trim whitespace and remove trailing punctuation
        return preg_replace('/\p{P}+$/u', '', trim($tag));
    }
                
    function fetch_link_card($connection, $url, $media = '') {
        
        // The required fields for every embed card
        $card = [
            "uri" => $url,
            "title" => "",
            "description" => "",
            "imageurlff" => $media,
        ];
    
        // Create a new DOMDocument
        $agent = "Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)";
        ini_set('user_agent', $agent);
        $doc = new DOMDocument();
    
        // Suppress errors for invalid HTML, if needed
        libxml_use_internal_errors(true);
    
        // Load the HTML from the URL
        if (!$doc->loadHTMLFile($url)){
            die('Error loading url '.$url);
        }
    
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
    
        if($card["imageurlff"] == "") {
            // If there is an "og:image" meta tag, fetch and upload that image
            $image_tag = $xpath->query('//meta[@property="og:image"]/@content');
            if ($image_tag->length > 0) {
                $img_url = $image_tag[0]->nodeValue;
                // Naively turn a "relative" URL (just a path) into a full URL, if needed
                if (!parse_url($img_url, PHP_URL_SCHEME)) {
                    $img_url = $url . $img_url;
                }
                $image = upload_media_to_bluesky($connection, $img_url);
            }else{
                if (strtoupper(linkCardFallback) == 'RANDOM'){
                    $image = upload_media_to_bluesky($connection, randomImageURL);
                }elseif (strtoupper(linkCardFallback) == 'BLANK'){
                    if (file_exists(__DIR__.'/blank.png')){
                        $image = upload_media_to_bluesky($connection, __DIR__.'/blank.png');
                    }else{
                        die('BLANK specified for link card fallback but blank.png is missing');
                    }
                }else{
                    die('No suitable image found for link card');
                }
            }
        } else {
            $image = upload_media_to_bluesky($connection, $card["imageurlff"]);
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

    function over_max_post_size($text){
        if (strlen($text)>300){
            return TRUE;
        }else{
            return FALSE;
        }
    }

    function extract_url_base($url) {
        // Decode the URL first to handle any encoded characters
        $decodedUrl = urldecode($url);
    
        // Remove "https://" (case-insensitive)
        $decodedUrl = preg_replace('/^https?:\/\//i', '', $decodedUrl);
    
        // Find the position of the first "?" (query parameters start here)
        $pos = strpos($decodedUrl, "?");
        
        // Extract the base URL (everything before the "?")
        if ($pos !== false) {
            $baseUrl = substr($decodedUrl, 0, $pos);
        } else {
            // If "?" is not found, take the entire decoded URL
            $baseUrl = $decodedUrl;
        }
    
        return $baseUrl;
    }
        
?>
