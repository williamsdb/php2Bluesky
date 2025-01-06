<a name="readme-top"></a>


<!-- PROJECT LOGO -->
<br />
<div align="center">

<h3 align="center">php2Bluesky</h3>

  <p align="center">
    A simple library that allows posting to Bluesky via the API.
    <br />
  </p>
</div>



<!-- TABLE OF CONTENTS -->
<details>
  <summary>Table of Contents</summary>
  <ol>
    <li>
      <a href="#about-the-project">About The Project</a>
      <ul>
        <li><a href="#built-with">Built With</a></li>
      </ul>
    </li>
    <li>
      <a href="#getting-started">Getting Started</a>
      <ul>
        <li><a href="#prerequisites">Prerequisites</a></li>
        <li><a href="#installation">Installation</a></li>
      </ul>
    </li>
    <li><a href="#usage">Usage</a></li>
    <li><a href="#roadmap">Roadmap</a></li>
    <li><a href="#contributing">Contributing</a></li>
    <li><a href="#license">License</a></li>
    <li><a href="#contact">Contact</a></li>
    <li><a href="#acknowledgments">Acknowledgments</a></li>
  </ol>
</details>



<!-- ABOUT THE PROJECT -->
## About The Project

With all the uncertainty surrounding the future of X (née Twitter), I decided to take a look at Bluesky, which somewhat ironically has its roots in Twitter, where it was started as an internal project. I worry about Bluesky's long-term, given that ultimately it too has to make money, something that Twitter has singularly failed to do. None of this, of course, affects the topic today, which is posting to Bluesky via the API.

I needed a way to post to Bluesky from PHP and so I searched for a library to help and when I couldn't find one I wrote this.


<a href='https://ko-fi.com/Y8Y0POEES' target='_blank'><img height='36' style='border:0px;height:36px;' src='https://storage.ko-fi.com/cdn/kofi5.png?v=6' border='0' alt='Buy Me a Coffee at ko-fi.com' /></a>

<p align="right">(<a href="#readme-top">back to top</a>)</p>



### Built With

* [PHP](https://php.net)
* [BlueskyApi by Clark Rasmussen](https://github.com/cjrasmussen/BlueskyApi)

<p align="right">(<a href="#readme-top">back to top</a>)</p>



<!-- GETTING STARTED -->
## Getting Started

***NOTE*** This is for v2 of php2Bluesky. If you are looking for the v1 details you can find that [here](https://github.com/williamsdb/php2Bluesky/blob/8b137617bda0bd9dd97462966a8d9404e08ea807/readme.md). 

Running the script is very straightforward:

1. install [composer](https://getcomposer.org/)
2. add the BlueskyAPI 

> composer.phar require cjrasmussen/bluesky-api

3. add php2Bluesky

> composer.phar require williamsdb/php2bluesky

Now you can inspect [example.php](https://github.com/williamsdb/php2Bluesky/blob/main/src/example.php) to get some examples and/or see below. 

If you are interested in what is happening under the hood then read [this series of blog posts](https://www.spokenlikeageek.com/tag/bluesky/).

### Prerequisites

Requirements are very simple, it requires the following:

1. PHP (I tested on v8.1.13) - requires php-dom and php-gd
2. Clark Rasmussen's [BlueskyApi](https://github.com/cjrasmussen/BlueskyApi) (requires v2 or above) 
2. a Bluesky account and an Application Password (see [this blog post](https://www.spokenlikeageek.com/2023/11/06/posting-to-bluesky-via-the-api-from-php-part-one/) for details of how to do that).

### Installation

1. As above

<p align="right">(<a href="#readme-top">back to top</a>)</p>



<!-- USAGE EXAMPLES -->
## Usage

Here's a few examples to get you started. 

Note: connection to the Bluesky API is made via Clark Rasmussen's [BlueskyApi](https://github.com/cjrasmussen/BlueskyApi) which this makes a connection to Bluesky and manages tokens etc. See [here](https://github.com/cjrasmussen/BlueskyApi) for more details.

*  Setup and connect to Bluesky:

```
require __DIR__ . '/vendor/autoload.php';

use williamsdb\php2bluesky\php2Bluesky;

$php2Bluesky = new php2Bluesky();

$handle = 'yourhandle.bsky.social';
$password = 'abcd-efgh-ijkl-mnop';
    
// connect to Bluesky API
$connection = $php2Bluesky->bluesky_connect($handle, $password);
```

* Sending text with tags

```
$text = "This is some text with a #hashtag.";

$response = $php2Bluesky->post_to_bluesky($connection, $text);
print_r($response);
if (!isset($response->error)){
    $url = $php2Bluesky->permalink_from_response($response, $handle);
    echo $url.PHP_EOL;            
}
```

* Uploading a post with a single image and embedded url

```
$filename1 = 'https://upload.wikimedia.org/wikipedia/en/6/67/Bluesky_User_Profile.png';
$text = 'Screenshot of Bluesky';
$alt = 'This is the screenshot that Wikipedia uses for their https://en.wikipedia.org/wiki/Bluesky entry.';

$response = $php2Bluesky->post_to_bluesky($connection, $text, $filename1, '', $alt);
print_r($response);
if (!isset($response->error)){
    $url = $php2Bluesky->permalink_from_response($response, $handle);
    echo $url.PHP_EOL;            
}
```

* Uploading a post with multiple images (both local and remote)

````
$filename1 = 'https://upload.wikimedia.org/wikipedia/en/6/67/Bluesky_User_Profile.png';
$filename2 = '/Users/neilthompson/Development/php2Bluesky/Screenshot1.png';
$filename3 = 'https://www.spokenlikeageek.com/wp-content/uploads/2024/11/2024-11-18-19-28-59.png';
$filename4 = '/Users/neilthompson/Development/php2Bluesky/Screenshot2.png';
$text = 'An example of four images taken both from a local machine and remote locations with some alt tags';
    
// send multiple images with text
$imageArray = array($filename1, $filename2, $filename3, $filename4); 
$alt = array('this has an alt', 'so does this');
    
$response = $php2Bluesky->post_to_bluesky($connection, $text, $imageArray, '', $alt);
print_r($response);
if (!isset($response->error)){
    $url = $php2Bluesky->permalink_from_response($response, $handle);
    echo $url.PHP_EOL;            
}
```` 

* Sending parameters when connecting to override defaults

````
$php2Bluesky = new php2Bluesky($linkCardFallback = 'RANDOM', 
                               $failOverMaxPostSize = FALSE, 
                               $randomImageURL = 'https://picsum.photos/1024/536',
                               $fileUploadDir='/tmp');
````

<p align="right">(<a href="#readme-top">back to top</a>)</p>



<!-- ROADMAP -->
## Known Issues

See the [open issues](https://github.com/williamsdb/php2Bluesky/issues) for a full list of proposed features (and known issues).

<p align="right">(<a href="#readme-top">back to top</a>)</p>



<!-- CONTRIBUTING -->
## Contributing

Thanks to the follow who have provided techincal and/or financial support for the project:

* [Jan Strohschein](https://bsky.app/profile/hayglow.bsky.social)
* [Ludwig Noujarret](https://bsky.app/profile/ludwig.noujarret.com)
* [Paul Lee](https://bsky.app/profile/drpaullee.bsky.social)
* [AJ](https://bsky.app/profile/asjmcguire.bsky.social)
* [https://bsky.app/profile/bobafettfanclub.com](https://bsky.app/profile/bobafettfanclub.com)
* [Doug "Bear" Hazard](https://bsky.app/profile/bearlydoug.com)

Contributions are what make the open source community such an amazing place to learn, inspire, and create. Any contributions you make are **greatly appreciated**.

If you have a suggestion that would make this better, please fork the repo and create a pull request. You can also simply open an issue with the tag "enhancement".
Don't forget to give the project a star! Thanks again!

1. Fork the Project
2. Create your Feature Branch (`git checkout -b feature/AmazingFeature`)
3. Commit your Changes (`git commit -m 'Add some AmazingFeature'`)
4. Push to the Branch (`git push origin feature/AmazingFeature`)
5. Open a Pull Request

<p align="right">(<a href="#readme-top">back to top</a>)</p>



<!-- LICENSE -->
## License

Distributed under the GNU General Public License v3.0. See `LICENSE` for more information.

<p align="right">(<a href="#readme-top">back to top</a>)</p>



<!-- CONTACT -->
## Contact

Bluesky - [@spokenlikeageek.com](https://bsky.app/profile/spokenlikeageek.com)

Mastodon - [@spokenlikeageek](https://techhub.social/@spokenlikeageek)

X - [@spokenlikeageek](https://x.com/spokenlikeageek) 

Website - [https://spokenlikeageek.com](https://www.spokenlikeageek.com/tag/bluesky/)

Project link - [Github](https://github.com/williamsdb/php2Bluesky)

<p align="right">(<a href="#readme-top">back to top</a>)</p>


<!-- ACKNOWLEDGMENTS -->
## Acknowledgments

* [BlueskyApi](https://github.com/cjrasmussen/BlueskyApi)

<p align="right">(<a href="#readme-top">back to top</a>)</p>


[![](https://github.com/williamsdb/php2Bluesky/graphs/contributors)](https://img.shields.io/github/contributors/williamsdb/php2Bluesky.svg?style=for-the-badge)

![](https://img.shields.io/github/contributors/williamsdb/php2Bluesky.svg?style=for-the-badge)
![](https://img.shields.io/github/forks/williamsdb/php2Bluesky.svg?style=for-the-badge)
![](https://img.shields.io/github/stars/williamsdb/php2Bluesky.svg?style=for-the-badge)
![](https://img.shields.io/github/issues/williamsdb/php2Bluesky.svg?style=for-the-badge)
<!-- MARKDOWN LINKS & IMAGES -->
<!-- https://www.markdownguide.org/basic-syntax/#reference-style-links -->
[contributors-shield]: https://img.shields.io/github/contributors/williamsdb/php2Bluesky.svg?style=for-the-badge
[contributors-url]: https://github.com/williamsdb/php2Bluesky/graphs/contributors
[forks-shield]: https://img.shields.io/github/forks/williamsdb/php2Bluesky.svg?style=for-the-badge
[forks-url]: https://github.com/williamsdb/php2Bluesky/network/members
[stars-shield]: https://img.shields.io/github/stars/williamsdb/php2Bluesky.svg?style=for-the-badge
[stars-url]: https://github.com/williamsdb/php2Bluesky/stargazers
[issues-shield]: https://img.shields.io/github/issues/williamsdb/php2Bluesky.svg?style=for-the-badge
[issues-url]: https://github.com/williamsdb/php2Bluesky/issues
[license-shield]: https://img.shields.io/github/license/williamsdb/php2Bluesky.svg?style=for-the-badge
[license-url]: https://github.com/williamsdb/php2Bluesky/blob/master/LICENSE.txt
[linkedin-shield]: https://img.shields.io/badge/-LinkedIn-black.svg?style=for-the-badge&logo=linkedin&colorB=555
[linkedin-url]: https://linkedin.com/in/linkedin_username
[product-screenshot]: images/screenshot.png
[Next.js]: https://img.shields.io/badge/next.js-000000?style=for-the-badge&logo=nextdotjs&logoColor=white
[Next-url]: https://nextjs.org/
[React.js]: https://img.shields.io/badge/React-20232A?style=for-the-badge&logo=react&logoColor=61DAFB
[React-url]: https://reactjs.org/
[Vue.js]: https://img.shields.io/badge/Vue.js-35495E?style=for-the-badge&logo=vuedotjs&logoColor=4FC08D
[Vue-url]: https://vuejs.org/
[Angular.io]: https://img.shields.io/badge/Angular-DD0031?style=for-the-badge&logo=angular&logoColor=white
[Angular-url]: https://angular.io/
[Svelte.dev]: https://img.shields.io/badge/Svelte-4A4A55?style=for-the-badge&logo=svelte&logoColor=FF3E00
[Svelte-url]: https://svelte.dev/
[Laravel.com]: https://img.shields.io/badge/Laravel-FF2D20?style=for-the-badge&logo=laravel&logoColor=white
[Laravel-url]: https://laravel.com
[Bootstrap.com]: https://img.shields.io/badge/Bootstrap-563D7C?style=for-the-badge&logo=bootstrap&logoColor=white
[Bootstrap-url]: https://getbootstrap.com
[JQuery.com]: https://img.shields.io/badge/jQuery-0769AD?style=for-the-badge&logo=jquery&logoColor=white
[JQuery-url]: https://jquery.com 
