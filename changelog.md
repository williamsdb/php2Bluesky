# Changelog

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
- Pass the dimensions to Bluesky for images so that they are displayed correctly.

## [1.0.0] - 2025-01-03
### Added
- 1.0 release of the project
- Feature complete.