<?php
/**
 * Library to make posting to Bluesky from PHP easier.
 *
 * @author  Neil Thompson <hi@nei.lt>
 * @see     https://php2bluesky.dev
 * @license GNU Lesser General Public License, version 3
 *
 */

    namespace williamsdb\php2bluesky;

    use cjrasmussen\BlueskyApi\BlueskyApi;
    use williamsdb\php2bluesky\BlueskyConsts;
    use williamsdb\php2bluesky\php2BlueskyException;
    use williamsdb\php2bluesky\RegexPatterns;
    use williamsdb\php2bluesky\Version; // Not utilised, for documentation only

    /**
     * Class for building array to send to Bluesky API
     */
    class php2Bluesky
    {

        // what happens when there is no link card image or missing mime type (RANDOM, BLANK, ERROR or URL)
        private string $linkCardFallback;

        // what happens when text is > maxPostSize
        private string $failOverMaxPostSize;

        // a random image to use as a fallback
        private string $randomImageURL;

        // folder to use for temporary files
        private string $fileUploadDir;

        public function __construct($linkCardFallback = 'BLANK', $failOverMaxPostSize = FALSE, $randomImageURL = 'https://picsum.photos/1024/536', $fileUploadDir = '/tmp')
        {
            $this->linkCardFallback = $linkCardFallback;
            $this->failOverMaxPostSize = $failOverMaxPostSize;
            $this->randomImageURL = $randomImageURL;
            $this->fileUploadDir = $fileUploadDir;
        }

        public function bluesky_connect($handle, $password)
        {
            $connection = new BlueskyApi();
            $connection->auth($handle, $password);
            return $connection;
        }

        private function upload_media_to_bluesky($connection, $filename, $fileUploadDir = '/tmp')
        {

            // have we been passed a file?
            if (empty($filename)) return;

            // get the file mime type
            if(filter_var($filename, FILTER_VALIDATE_URL)){
                // can we access the file?
                $context = stream_context_create(['http' => ['ignore_errors' => true]]);
                if (@file_get_contents($filename, false, $context) !== false) {
                    if ($headers = get_headers($filename, 1)) {
                        if ($headers = get_headers($filename, 1)){
                            if (isset($headers['Content-Type'])) {
                                $mime = $headers['Content-Type'];
                            } elseif (isset($headers['content-type'])) {
                                $mime = $headers['content-type'];
                            } else {
                                $mime = '';
                            }
                        }else{
                            $mime = '';
                        }
                    }
                } else {
                    $mime = '';
                }
            }else{
                $mime = mime_content_type($filename);
            }

            // if we can't determine the mime type, use the fallback
            if (empty($mime) || !isset($mime) || !is_string($mime)){
                if (strtoupper($this->linkCardFallback) == 'RANDOM'){
                    $filename = $this->randomImageURL;
                }elseif (strtoupper($this->linkCardFallback) == 'BLANK'){
                    if (file_exists(__DIR__.'/blank.png')){
                        $filename = __DIR__.'/blank.png';
                    }else{
                        throw new php2BlueskyException("BLANK specified for fallback image but blank.png is missing.");
                    }
                }elseif (strtoupper(substr($this->linkCardFallback,0,3)) == 'URL'){
                    $filename = substr($this->linkCardFallback,4);
                }else{
                    throw new php2BlueskyException("Could not determine mime type of file.");
                }

                // get the mime type of the fallback image
                if(filter_var($filename, FILTER_VALIDATE_URL)){
                    if (@file_get_contents($filename, false, $context) !== false) {
                        if ($headers = get_headers($filename, 1)) {
                            if ($headers = get_headers($filename, 1)){
                                if (isset($headers['Content-Type'])) {
                                    $mime = $headers['Content-Type'];
                                } elseif (isset($headers['content-type'])) {
                                    $mime = $headers['content-type'];
                                } else {
                                    $mime = '';
                                }
                            }else{
                                $mime = '';
                            }
                        }
                    } else {
                        $mime = '';
                    }
                }else{
                    $mime = mime_content_type($filename);
                }

                // if we can't determine the mime type of the fallback, error
                if (empty($mime) || !isset($mime) || !is_string($mime)){
                    throw new php2BlueskyException("Could not determine mime type of file.");
                }
            }

            // get the size and basename of the file
            $body = file_get_contents($filename);
            $basename = $this->getFileName($filename);
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

            // get the image dimensions
            $imageInfo = getimagesize($filename);
            if ($imageInfo === FALSE) {
                throw new php2BlueskyException("Could not get the size of the image.");
            }

            return [$image, $imageInfo];

        }

        public function post_to_bluesky($connection, $text, $media='', $link='', $alt='', $linkCardFallback = 'UNSET')
        {

            // if set overrider the default setting for link card fallback for this post
            if ($linkCardFallback != 'UNSET'){
                $this->linkCardFallback = $linkCardFallback;
            }

            // check for post > BlueskyConsts::MAX_POST_SIZE
            if ($this->over_max_post_size($text) && $this->failOverMaxPostSize){
                throw new php2BlueskyException("Provided text greater than ".BlueskyConsts::MAX_POST_SIZE);
            }

            // parse for URLs
            $urls = $this->mark_urls($text);
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
            $mentionsData = $this->mark_mentions($connection, $text);
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
            $hashtagData = $this->mark_hashtags($text);
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
                        $result = $this->upload_media_to_bluesky($connection, $media[$k], $this->fileUploadDir);
                        $response = $result[0];
                        $imageInfo = $result[1];
                        array_push($mediaArray, [
                            'alt' => $altText,
                            'image' => $response,
                            'aspectRatio' => [
                                'width' => $imageInfo[0],
                                'height' => $imageInfo[1]
                                ]
                            ]);
                        $k++;
                    }
                }else{
                    $result = $this->upload_media_to_bluesky($connection, $media, $this->fileUploadDir);
                    $response = $result[0];
                    $imageInfo = $result[1];

                    // has an array been passed?
                    if (is_array($alt)){
                        $alt= isset($alt[0]) ? $alt[0] : '';;
                    }

                    $mediaArray = [
                        [
                        'alt' => $alt,
                        'image' => $response,
                        'aspectRatio' => [
                            'width' => $imageInfo[0],
                            'height' => $imageInfo[1]
                            ]
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
                $embed = $this->fetch_link_card($connection, $link, $media);
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
        public function permalink_from_response($response, $handle)
        {

            // Extract the post id
            preg_match('/\/([^\/]+)$/', $response->uri, $matches);

            // Check if a match was found and return it
            if (!empty($matches[1])) {
                return 'https://bsky.app/profile/'.$handle.'/post/'.$matches[1];
            } else {
                echo "No post id found.";
            }

        }

        public function get_from_bluesky($connection, $link)
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

        private function mark_mentions($connection, $text) {

            $spans = [];
            preg_match_all(RegexPatterns::MENTION_REGEX, $text, $matches, PREG_OFFSET_CAPTURE);

            $mentionsData = array();

            foreach ($matches[1] as $match) {
                $did = $this->get_did_from_handle($connection, substr($match[0], 1));
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

        private function get_did_from_handle($connection, $handle){

            $args = [
                'handle' => $handle,
            ];

            // send to bluesky to get the did for the given handle
            return  $connection->request('GET', 'com.atproto.identity.resolveHandle', $args);

        }

        private function mark_urls($text) {
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

        private function mark_hashtags($text) {
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

                // Exclude preceding space or newline (if any) from the start
                if (in_array($originalHashtag[0], [' ', "\n", "\r"])) {
                    $start += 1; // Adjust start to exclude the delimiter
                    $originalHashtag = substr($originalHashtag, 1);
                }

                // Clean the hashtag (removing trailing punctuation)
                $cleanedHashtag = $this->clean_hashtag($originalHashtag);

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

        private function clean_hashtag($tag) {
            // Trim whitespace and remove trailing punctuation
            return preg_replace('/\p{P}+$/u', '', trim($tag));
        }

        private function fetch_link_card($connection, $url, $media = '') {

            // The required fields for every embed card
            $card = [
                "uri" => $url,
                "title" => "",
                "description" => "",
                "imageurlff" => $media,
            ];

            $opts = [
                "http" => [
                    "user_agent" => "Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)"
                ]
            ];
            $context = stream_context_create($opts);

            $html = file_get_contents($url, false, $context);

            if (false===$html) {
                throw new php2BlueskyException("Error loading url ".$url);
            }

            // there are charset issues with HTML5 documents using short charset declaration syntax
            // e.g. <meta charset="..."> instead of <meta http-equiv="Content-Type" content="text/html; charset=...">
            // hence replace the charset declaration to legacy HTML4 before loading everything into DOMDocument
            $html = preg_replace('/<meta[^>]+charset=(.)([a-z0-9-]+)\1[^>]*>/imU', '<meta http-equiv="Content-Type" content="text/html; charset=$2">', $html);

            // Create a new DOMDocument
            $doc = new \DOMDocument();

            // Suppress errors for invalid HTML, if needed
            libxml_use_internal_errors(true);

            // Load the HTML from the string
            $doc->loadHTML($html);

            // Restore error handling
            libxml_use_internal_errors(false);

            // Create a new DOMXPath object for querying the document
            $xpath = new \DOMXPath($doc);

            // Query for "og:title" and "og:description" meta tags
            $title_tag = $xpath->query('//meta[@property="og:title"]/@content');
            if ($title_tag->length > 0) {
                $card["title"] = $title_tag[0]->nodeValue;
            }

            // If there is an "og:description" meta tag, use that as the description
            $description_tag = $xpath->query('//meta[@property="og:description"]/@content');
            if ($description_tag->length > 0) {
                $card["description"] = $description_tag[0]->nodeValue;
            }

            // If there is an "og:image" meta tag, use that as the image
            if($card["imageurlff"] == "") {
                // If there is an "og:image" meta tag, fetch and upload that image
                $image_tag = $xpath->query('//meta[@property="og:image"]/@content');
                if ($image_tag->length > 0) {
                    $img_url = $image_tag[0]->nodeValue;
                    // Naively turn a "relative" URL (just a path) into a full URL, if needed
                    if (!parse_url($img_url, PHP_URL_SCHEME)) {
                        $img_url = $url . $img_url;
                    }
                    $result = $this->upload_media_to_bluesky($connection, $img_url, $this->fileUploadDir);
                    $image = $result[0];
                    $imageInfo = $result[1];
                }else{
                    if (strtoupper($this->linkCardFallback) == 'RANDOM'){
                        $result = $this->upload_media_to_bluesky($connection, $this->randomImageURL,$this->fileUploadDir);
                        $image = $result[0];
                        $imageInfo = $result[1];
                    }elseif (strtoupper($this->linkCardFallback) == 'BLANK'){
                        if (file_exists(__DIR__.'/blank.png')){
                            $result = $this->upload_media_to_bluesky($connection, __DIR__.'/blank.png', $this->fileUploadDir);
                            $image = $result[0];
                            $imageInfo = $result[1];
                        }else{
                            throw new php2BlueskyException("BLANK specified for fallback image but blank.png is missing.");
                        }
                    }elseif (strtoupper(substr($this->linkCardFallback,0,3)) == 'URL'){
                        $filename = substr($this->linkCardFallback,4);
                        $result = $this->upload_media_to_bluesky($connection, $filename, $this->fileUploadDir);
                        $image = $result[0];
                        $imageInfo = $result[1];
                    }else{
                        throw new php2BlueskyException("No suitable image found for link card.");
                    }
                }
            } else {
                $result = $this->upload_media_to_bluesky($connection, $card["imageurlff"], $this->fileUploadDir);
                $image = $result[0];
                $imageInfo = $result[1];
            }

            $embed = [
                'embed' => [
                    '$type' => 'app.bsky.embed.external',
                    'external' => [
                        'uri' => $card['uri'],
                        'title' => $card['title'],
                        'description' => $card['description'],
                        'thumb' => $image,
                        'aspectRatio' => [
                            'width' => $imageInfo[0],
                            'height' => $imageInfo[1]
                        ]
                    ],
                ],
            ];
            return $embed;
        }

        private function getFileName($path) {
            // If the path is a URL, use basename to get the filename
            if (filter_var($path, FILTER_VALIDATE_URL)) {
                return basename(parse_url($path, PHP_URL_PATH));
            } else {
                // If the path is a local path, use basename to get the filename
                return basename($path);
            }
        }

        private function over_max_post_size($text){
            if (strlen($text)>300){
                return TRUE;
            }else{
                return FALSE;
            }
        }

        private function extract_url_base($url) {
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

        public function get_rate_limits($connection) {

            // Get the response header from the last request
            $responseHeader = $connection->getLastResponseHeader();

            // Split the response header into individual lines
            $lines = explode("\n", $responseHeader);

            // Initialize an array to store the RateLimit fields
            $rateLimit = [];
            foreach ($lines as $line) {
                // Extract RateLimit fields
                if (stripos($line, 'RateLimit-Limit') !== false) {
                    $rateLimit['Limit'] = trim(explode(':', $line, 2)[1]);
                } elseif (stripos($line, 'RateLimit-Remaining') !== false) {
                    $rateLimit['Remaining'] = trim(explode(':', $line, 2)[1]);
                } elseif (stripos($line, 'RateLimit-Reset') !== false) {
                    $rateLimit['Reset'] = trim(explode(':', $line, 2)[1]);
                }
            }

            // Convert RateLimit-Reset to human-readable format
            if (isset($rateLimit['Reset'])) {
                $rateLimit['Reset_Human'] = date('Y-m-d H:i:s', $rateLimit['Reset']);
            }

            return $rateLimit;
        }

    }

?>