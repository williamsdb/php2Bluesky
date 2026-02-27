# Changelog

## [2.3.5] - 2025-03-27

### Update

- fixed processing of gifs where previously only the first frame was showing

## [2.3.4] - 2025-03-25

### Update

- improved the handling of image selection on adding linkcards

## [2.3.3] - 2025-01-04

### Update

- detect urls that don't have any protocol (http/https), add the protocol and mark them so they are linked

## [2.3.2] - 2025-09-28

### Update

- moved from get_file_contents to cURL to support some edge cases
- removed the last of the echo statements and replaced with throws
- trucated the mime type if it had additonal information attached

## [2.3.1] - 2025-09-28

### Update

- added ability to state post language where previously it was hard coded as English

## [2.3.0] - 2025-09-09

### Update

- rewrote code for handling image uploads including following 301 redirects for linkcard fallbacks

## [2.2.2] - 2025-09-01

### Update

- added support for webp images

## [2.2.1] - 2025-06-27

### Update

- included the !no-unauthenticated label in the list of valid labels

## [2.2.0] - 2025-06-27

### Added

- ability to include moderation labels with media uploads

## [2.1.1] - 2025-05-08

### Update

- removed the requirement for ffprobe so code will run with or without it

## [2.1.0] - 2025-05-07

### Added

- ability to upload a video file
- new constants to reflect the video upload limits set by Bluesky
- errors thrown now also include an error code

## [2.0.14] - 2025-02-21

### Added

- included namespace to fix loading issues

## [2.0.13] - 2025-02-21

### Removed

- removed the files section from the autoload to remove reference to previous functions.php file

## [2.0.12] - 2025-02-21

### Added

- changes to comply with psr-4 - thanks to @japafrite for the changes

## [2.0.11] - 2025-02-02

### Added

- added code to trap when a specified fallback image doesn't exist

## [2.0.10] - 2025-02-02

### Added

- new fallback option, URL=, that allows you to specify an image to use on fallback

## [2.0.9] - 2025-01-31

### Added

- change to handling of files that cannot be read & header response function

## [2.0.8] - 2025-01-22

### Added

- fixed a bug in fallback override and another in getting link card images

## [2.0.7] - 2025-01-21

### Added

- allow link card fallback to be overridden on a post by post basis

## [2.0.6] - 2025-01-09

### Added

- Fix an bug with carriage returns before hashtags

## [2.0.5] - 2025-01-08

### Added

- Fix for when an array is passed for alts with a single image

## [2.0.4] - 2025-01-06

### Added

- Allowed parameters to be passed through

## [2.0.3] - 2025-01-05

### Added

- Updated the README with v2 examples

## [2.0.2] - 2025-01-05

### Added

- Class names in code rather than public functions

## [2.0.1] - 2025-01-05

### Added

- Bug fixes to composer loading

## [2.0.0] - 2025-01-05

### Added

- Moved to composer rather than including file
- Change the way images are uploaded so that now only a call to post_to_bluesky is required
- Pass the dimensions to Bluesky for images so that they are displayed correctly

## [1.0.0] - 2025-01-03

### Added

- 1.0 release of the project
- Feature complete.
