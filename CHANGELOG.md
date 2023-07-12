## 3.0.29

* Fix order query for nav items

## 3.0.28

* Optimize to only update supplied values

## 3.0.27

* Resolve issue with undefined model for virtual controller

## 3.0.26

* Order menu items by item order attribute

## 3.0.25

* Add support for bulk updating nav items
* No longer update pages or sitemap when nav or side nav items are changes

## 3.0.24

* Fix issue with variable scoping

## 3.0.23

* SES add support for specifying reply to addresses

## 3.0.22

* Support for Google Analytics
* Support for Google Tag Manager

## 3.0.21

* Order sitemap by date modified

## 3.0.20

* Add feature to check if alias conforms to SSL certificate and domain assigned to CF distribution
* Add meta description for SEO
* Add open graph meta tags for SEO
* Add robots meta tag for SEO
* Add canonical tag for SEO
* Add option to specify obfuscation preference for pages

## 3.0.19

* Fix bug with empty sitemap when editing navigation items

## 3.0.18

* Resolve issue where updating a page nav caused protected pages to be included in sitemap

## 3.0.17

* Add formatting to XML sitemap output

## 3.0.16

* Reduce number of CF invalidation calls to optimize performance

## 3.0.15

* Add support for generating and managing sitemaps when pages are created, updated, deleted
* Add support for updating sitemaps when menu items change
* When generating sitemaps, skip pages that are password protected
* Add feature to specify alias domain for site

## 3.0.14

* Return message ID from AWS SES if succesfully sent email

## 3.0.13

* Add method to return first item from array from model query
* Add method to return last item from array from model query
* Improve custom query performance
* Add support for specifing a sql LIMIT

## 3.0.12

* Fix bug with deleting a public access block for a s3 bucket

## 3.0.11

* Fix in response to new S3 requirement that disables ACL in favor of bucket ownership policies. https://aws.amazon.com/about-aws/whats-new/2022/12/amazon-s3-automatically-enable-block-public-access-disable-access-control-lists-buckets-april-2023/?nc1=h_ls
* Add method to S3 wrapper for deleting public access block to allow for public access to s3 bucket

## 3.0.10

* Fix bug where internal property was not accessible

## 3.0.9

* Fix bug where internal method was not being used

## 3.0.8

* Fix bug where stale data was returned after an update

## 3.0.7

* Return user role if present

## 3.0.6

* Fix bug where preg_match did not replace and returned null

## 3.0.5

* User interal AWS credential wrapper for Email utility
* Return account object for user profile

## 3.0.4

* Make account number a non-protected entry

## 3.0.3

* Bug fix for Kyte Profile

## 3.0.2

* Add KyteProfile controller for updating user profile on Kyte Shipyard

## 3.0.1

* Add email templates
* Ability to send from a email utility class
* Prepopulate template with data in associative array format

## 3.0.0

* Add support for custom user table, seperate from main framework.
* Add support for optional organization table, and scoping users based on organization.
* Add optional AWS credential attributes at application level.
* Rename User and Account models as KyteUser and KyteAccount to better distinguish from application models.
* Add initial round of PHPDocs

## 2.0.0

* Updated version with SaaS support.

## 1.0.0

* Initial development release kyte framework.
