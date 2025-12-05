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

    // default language for posts
    private array $defaultLang;

    public function __construct($linkCardFallback = 'BLANK', $failOverMaxPostSize = FALSE, $randomImageURL = 'https://picsum.photos/1024/536', $fileUploadDir = '/tmp', $defaultLang = ['en'])
    {
        $this->linkCardFallback = $linkCardFallback;
        $this->failOverMaxPostSize = $failOverMaxPostSize;
        $this->randomImageURL = $randomImageURL;
        $this->fileUploadDir = $fileUploadDir;
        $this->defaultLang = $defaultLang;
    }

    public function bluesky_connect($handle, $password)
    {
        $connection = new BlueskyApi();
        $connection->auth($handle, $password);
        return $connection;
    }

    private function upload_media_to_bluesky($connection, $filename, $fileUploadDir = '/tmp')
    {
        // helper: fetch remote file with cURL
        $fetchRemote = function ($url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER         => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT      => 'php2Bluesky/2.0',
            ]);
            $body = curl_exec($ch);
            $mime = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $err  = curl_error($ch);
            curl_close($ch);

            if ($body === false) {
                throw new php2BlueskyException("Failed to fetch remote file: " . $err, 1005);
            }

            return [$body, $mime];
        };

        // have we been passed a file?
        if (empty($filename)) return;

        // get mime type and file body
        if (filter_var($filename, FILTER_VALIDATE_URL)) {
            [$body, $mime] = $fetchRemote($filename);
        } else {
            if (!file_exists($filename)) {
                throw new php2BlueskyException("Local file does not exist: " . $filename, 1006);
            }
            $mime = mime_content_type($filename);
            $body = file_get_contents($filename);
        }

        // if we can't determine the mime type, use the fallback
        if (empty($mime) || !is_string($mime)) {
            if (strtoupper($this->linkCardFallback) == 'RANDOM') {
                $filename = $this->randomImageURL;
                [$body, $mime] = $fetchRemote($filename);
            } elseif (strtoupper($this->linkCardFallback) == 'BLANK') {
                if (file_exists(__DIR__ . '/blank.png')) {
                    $filename = __DIR__ . '/blank.png';
                    $mime     = mime_content_type($filename);
                    $body     = file_get_contents($filename);
                } else {
                    throw new php2BlueskyException("BLANK specified for fallback image but blank.png is missing.", 1001);
                }
            } elseif (strtoupper(substr($this->linkCardFallback, 0, 3)) == 'URL') {
                $filename = substr($this->linkCardFallback, 4);
                [$body, $mime] = $fetchRemote($filename);
            } else {
                throw new php2BlueskyException("Could not determine mime type of file.", 1002);
            }
        }

        // what file type have we got?
        if (!in_array($mime, BlueskyConsts::FILE_TYPES)) {
            throw new php2BlueskyException("File type not supported: " . $mime . " - $filename", 1003);
        }

        // get the size and basename of the file
        $basename = $this->getFileName($filename);
        $size     = strlen($body);

        // is the file image or video?
        if (strpos($mime, 'image') !== false) {
            // does the file size need reducing? (applies to local + remote)
            if ($size > BlueskyConsts::MAX_IMAGE_UPLOAD_SIZE) {
                $newImage = imagecreatefromstring($body);
                if ($newImage === false) {
                    throw new php2BlueskyException("Could not create image resource for resizing.", 1007);
                }

                for ($i = 9; $i >= 1; $i--) {
                    $tempFile = $fileUploadDir . '/' . $basename;
                    imagejpeg($newImage, $tempFile, $i * 10);
                    $size = filesize($tempFile);

                    if ($size < BlueskyConsts::MAX_IMAGE_UPLOAD_SIZE) {
                        $body = file_get_contents($tempFile);
                        unlink($tempFile);
                        break;
                    } else {
                        unlink($tempFile);
                    }
                }
                imagedestroy($newImage);
            }

            // upload the file to Bluesky
            $response = $connection->request('POST', 'com.atproto.repo.uploadBlob', [], $body, $mime);
            $image    = $response->blob;

            // get the image dimensions
            $imageInfo = getimagesizefromstring($body);
            if ($imageInfo === false) {
                throw new php2BlueskyException("Could not get the size of the image.", 1004);
            }
        } elseif (strpos($mime, 'video') !== false) {
            $imageInfo = $this->getvideosize($filename);
            $response  = $connection->request('POST', 'com.atproto.repo.uploadBlob', [], $body, $mime);
            $image     = $response->blob;
        } else {
            throw new php2BlueskyException("File type not supported: " . $mime, 1003);
        }

        // return details for sending to Bluesky
        return [$image, $imageInfo];
    }

    public function post_to_bluesky($connection, $text, $media = '', $link = '', $alt = '', $labels = '', $linkCardFallback = 'UNSET', $lang = '')
    {

        // if set override the default setting for link card fallback for this post
        if ($linkCardFallback != 'UNSET') {
            $this->linkCardFallback = $linkCardFallback;
        }

        // check for post > BlueskyConsts::MAX_POST_SIZE
        if ($this->over_max_post_size($text) && $this->failOverMaxPostSize) {
            throw new php2BlueskyException("Provided text greater than " . BlueskyConsts::MAX_POST_SIZE, 1005);
        }

        // parse for URLs
        $urls = $this->mark_urls($text);
        $links = array();
        if (!empty($urls)) {
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
        if (!empty($mentionsData)) {
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
        if (!empty($hashtagData)) {
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

        // check for labels
        $labelArray = [];

        if (!empty($labels)) {
            // Expect string or array
            $singleLabels = is_array($labels) ? $labels : [$labels];
            foreach ($singleLabels as $label) {
                if (!in_array($label, BlueskyConsts::ALLOWED_LABELS, true)) {
                    throw new php2BlueskyException("Unsupported label: '$label'");
                }
                $labelArray[] = ['val' => $label];
            }

            // add the labels to the facets
            $label = [
                'labels' => [
                    '$type' => 'com.atproto.label.defs#selfLabels',
                    'values' => $labelArray,
                ],
            ];
        }

        // add any media - will accept multiple images in an array or a single image/video as a string
        $embed = '';
        if (!empty($media)) {
            if (is_array($media)) {
                $k = 0;
                $mediaArray = array();
                while ($k < count($media) && $k < BlueskyConsts::MAX_IMAGE_UPLOAD) {
                    $altText = isset($alt[$k]) ? $alt[$k] : '';
                    $result = $this->upload_media_to_bluesky($connection, $media[$k], $this->fileUploadDir);
                    $response = $result[0];
                    $imageInfo = $result[1];

                    // can't mix your media types
                    if (strpos($response->mimeType, 'video') !== false) {
                        // silently ignore video
                    } else {
                        array_push($mediaArray, [
                            'alt' => $altText,
                            'image' => $response,
                            'aspectRatio' => [
                                'width' => $imageInfo[0],
                                'height' => $imageInfo[1]
                            ]
                        ]);
                    }
                    $k++;
                }

                // build the embed array
                $embed = [
                    'embed' => [
                        '$type' => 'app.bsky.embed.images',
                        'images' => $mediaArray,
                    ],
                ];
            } else {
                $result = $this->upload_media_to_bluesky($connection, $media, $this->fileUploadDir);
                $response = $result[0];
                $imageInfo = $result[1];

                // check if the media is a video
                if (strpos($response->mimeType, 'video') !== false) {
                    if (empty($imageInfo)) {
                        $embed = [
                            'embed' => [
                                '$type' => 'app.bsky.embed.video',
                                'video' => $response
                            ],
                        ];
                    } else {
                        $embed = [
                            'embed' => [
                                '$type' => 'app.bsky.embed.video',
                                'video' => $response,
                                'aspectRatio' => [
                                    'width' => $imageInfo[0],
                                    'height' => $imageInfo[1]
                                ]
                            ],
                        ];
                    }
                } else {
                    // has an array been passed?
                    if (is_array($alt)) {
                        $alt = isset($alt[0]) ? $alt[0] : '';;
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

                    // build the embed array
                    $embed = [
                        'embed' => [
                            '$type' => 'app.bsky.embed.images',
                            'images' => $mediaArray,
                        ],
                    ];
                }
            }
        }

        // add any link cards
        if (!empty($link)) {
            $embed = $this->fetch_link_card($connection, $link, $media);
        }

        // if a language has been specified, use it, otherwise use the default
        if (empty($lang)) {
            $lang = $this->defaultLang;
        }

        // build the final arguments
        $args = [
            'collection' => 'app.bsky.feed.post',
            'repo' => $connection->getAccountDid(),
            'record' => [
                'text' => $text,
                'langs' => $lang,
                'createdAt' => date('c'),
                '$type' => 'app.bsky.feed.post',
            ],
        ];
        if (!empty($label)) $embed = array_merge($embed, $label);
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
            return 'https://bsky.app/profile/' . $handle . '/post/' . $matches[1];
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
        $postId = isset($matches[1][4]) ? $matches[1][4] : '';

        $args = [
            'repo' => $account,
            'collection' => 'app.bsky.feed.post',
            'rkey' => $postId
        ];

        // retrieve the post from Bluesky using the extracted account name and post id
        return $connection->request('GET', 'com.atproto.repo.getRecord', $args);
    }

    private function mark_mentions($connection, $text)
    {

        $spans = [];
        preg_match_all(RegexPatterns::MENTION_REGEX, $text, $matches, PREG_OFFSET_CAPTURE);

        $mentionsData = array();

        foreach ($matches[1] as $match) {
            $did = $this->get_did_from_handle($connection, substr($match[0], 1));
            if (!empty($did->did)) {

                $mentionsData[] = [
                    "start" => $match[1],
                    "end" => $match[1] + strlen($match[0]),
                    "did" => $did->did,
                ];
            }
        }
        return $mentionsData;
    }

    private function get_did_from_handle($connection, $handle)
    {

        $args = [
            'handle' => $handle,
        ];

        // send to bluesky to get the did for the given handle
        return  $connection->request('GET', 'com.atproto.identity.resolveHandle', $args);
    }

    private function mark_urls($text)
    {
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

    private function mark_hashtags($text)
    {
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

    private function clean_hashtag($tag)
    {
        // Trim whitespace and remove trailing punctuation
        return preg_replace('/\p{P}+$/u', '', trim($tag));
    }

    private function fetch_link_card($connection, $url, $media = '')
    {

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

        if (false === $html) {
            throw new php2BlueskyException("Error loading url " . $url, 1006);
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
        if ($card["imageurlff"] == "") {
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
            } else {
                if (strtoupper($this->linkCardFallback) == 'RANDOM') {
                    $result = $this->upload_media_to_bluesky($connection, $this->randomImageURL, $this->fileUploadDir);
                    $image = $result[0];
                    $imageInfo = $result[1];
                } elseif (strtoupper($this->linkCardFallback) == 'BLANK') {
                    if (file_exists(__DIR__ . '/blank.png')) {
                        $result = $this->upload_media_to_bluesky($connection, __DIR__ . '/blank.png', $this->fileUploadDir);
                        $image = $result[0];
                        $imageInfo = $result[1];
                    } else {
                        throw new php2BlueskyException("BLANK specified for fallback image but blank.png is missing.", 1001);
                    }
                } elseif (strtoupper(substr($this->linkCardFallback, 0, 3)) == 'URL') {
                    $filename = substr($this->linkCardFallback, 4);
                    $result = $this->upload_media_to_bluesky($connection, $filename, $this->fileUploadDir);
                    $image = $result[0];
                    $imageInfo = $result[1];
                } else {
                    throw new php2BlueskyException("No suitable image found for link card.", 1007);
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

    // get the filename from a URL or local path
    private function getFileName($path)
    {
        // If the path is a URL, use basename to get the filename
        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return basename(parse_url($path, PHP_URL_PATH));
        } else {
            // If the path is a local path, use basename to get the filename
            return basename($path);
        }
    }

    // get the size of a video file
    private function getvideosize($videoPath)
    {

        $ffprobePath = null;

        // First check local directory
        if (is_file('./ffprobe') && is_executable('./ffprobe')) {
            $ffprobePath = './ffprobe';
        } else {
            // Check OS and look for ffprobe globally
            if (stripos(PHP_OS, 'WIN') === 0) {
                $checkCmd = 'where ffprobe';
            } else {
                $checkCmd = 'command -v ffprobe';
            }

            $ffprobeGlobal = trim(shell_exec($checkCmd) ?? '');

            if ($ffprobeGlobal) {
                // On Windows, 'where' can return multiple paths; take the first one
                $ffprobePath = strtok($ffprobeGlobal, PHP_EOL);
            }
        }

        // If ffprobe is not found in the local directory or globally, throw an exception
        if (!$ffprobePath) {
            return [];
        }

        // Run ffprobe to get JSON output
        $cmd = escapeshellcmd($ffprobePath) . " -v quiet -print_format json -show_streams " . escapeshellarg($videoPath);
        $output = shell_exec($cmd);

        if ($output) {
            $json = json_decode($output, true);
            foreach ($json['streams'] as $stream) {
                if ($stream['codec_type'] === 'video') {
                    $width = $stream['width'];
                    $height = $stream['height'];
                    $duration = isset($stream['duration']) ? $stream['duration'] : $json['streams'][0]['duration'];
                    // check if the duration is greater than the max
                    if ($duration > BlueskyConsts::MAX_VIDEO_DURATION) {
                        throw new php2BlueskyException("Video duration exceeds maximum allowed duration of " . BlueskyConsts::MAX_VIDEO_DURATION . " seconds.", 1009);
                    }
                    return [$width, $height, $duration];
                }
            }
        }
    }

    // check if the text is over the max post size
    private function over_max_post_size($text)
    {
        if (strlen($text) > 300) {
            return TRUE;
        } else {
            return FALSE;
        }
    }

    private function extract_url_base($url)
    {
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

    public function get_rate_limits($connection)
    {

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
