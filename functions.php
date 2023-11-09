<?php

use cjrasmussen\BlueskyApi\BlueskyApi;

class Bluesky_model extends CI_Model {

  public function __construct()
  {
    parent::__construct();

    $this->load->helper('authit');
    //$this->load->model('Networks_model', '', TRUE);
    $this->load->model('Audit_model', '', TRUE);

  }

  public function bluesky_connect($handle, $password)
  {

    $connection = new BlueskyApi($handle, $password);
    return $connection;

  }

	public function upload_media_to_bluesky($connection, $s3name, $s3=TRUE)
	{

		// have we been passed a file?
		if (empty($s3name)) return;

    // audit the request
    $this->Audit_model->audit_controller('Bluesky_model', 'upload_media_to_bluesky', 'Starting media upload to Bluesky', '');
    
    // upload the file
    if ($s3){
      // get the user that owns the file
      $this->load->model('User_model');
      $user = $this->User_model->get_user_from_s3name($s3name);

      $body = file_get_contents($this->config->item('AWS_PUBLIC_URL').".".$user[0]['user_id']."/".$s3name);
      $headers = get_headers($this->config->item('AWS_PUBLIC_URL').".".$user[0]['user_id']."/".$s3name, 1); 

      if (isset($headers['Content-Type'])) {
          $mime = $headers['Content-Type'];
      } else {
          $mime ='image/jpeg';
      }
  }else{
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
    }

    $response = $connection->request('POST', 'com.atproto.repo.uploadBlob', [], $body, $mime);
    $this->Audit_model->audit_controller('Bluesky_model', 'upload_media_to_bluesky', 'Ending media upload to Bluesky', print_r($response, TRUE));

    $image = $response->blob;

    return $image;

  }

	public function post_to_bluesky($connection, $text, $media='', $link='')
	{

    // parse for URLS
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
      $embed = $this->fetch_link_card($connection, $link);
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

    // audit the request
    $this->Audit_model->audit_controller('Bluesky_model', 'post_to_bluesky', 'Post', print_r($args, TRUE));

    // send to bluesky
    return $connection->request('POST', 'com.atproto.repo.createRecord', $args);
 
  }

  public function mark_urls($text) {

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

  public function fetch_link_card($connection, $url) {

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
        
        $image = $this->upload_media_to_bluesky($connection, $img_url, $s3=FALSE);
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

}
/* End of file Bluesky_model.php */
/* Location: ./application/models/Bluesky_model.php */
