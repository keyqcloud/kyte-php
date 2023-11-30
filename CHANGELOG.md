## 3.1.0

* Roll back logger while determining best implementation
* Add SQS wrapper
* Move page invalidation code to use SQS
* Add site deletion using SQS
* Move page creation to use SQS
* Update Page model name to KytePage
* Stage KytePageData to hold compressed page data
* Add comment that page data inside KytePage will be removed and moved to KytePageData
* Renamed controller PageController to KytePageController
* Fix issue with $ in property name
* Refactor function that checks for default constant values

## 3.0.90

* Add global to check if s3 debug output handler should be enabled
* Only output relevant errors to s3

## 3.0.89

* Remove system error handler for s3

## 3.0.88

* Add log handler for php

## 3.0.87

* Add wrapper function for SES logging
* Remove function from detail as content will always be logger

## 3.0.86

* Fix s3 object in logger

## 3.0.85

* Fix app object for logger

## 3.0.84

* temporarily revert session exception logging until framework logging mechanism is finalized

## 3.0.83

* Add utility class for logging to s3
* Add feature to create new bucket for logs when application is created - default to us-east-1 for logs
* Add attribute for storing bucket information for logs at Application level

## 3.0.82

* Add missing header attribute for Page model

## 3.0.81

* Move custom scripts to end of body
* Add support for headers in page creation

## 3.0.80

* Update fontawesome CDN to version 6.4.2
* Remove default libraries such as bootstrap, datatable, jquery, jquery UI
* Add controller for managing custom libraries
* New model for storing links to libraries like JQuery
* Fix bug where publishing a nav or side nav publishes all pages (including drafts)
* New model for scripts to be used accross pages or entire site
* Controller for creating custom scripts and invalidating cache
* Remove unecessary assignment of variables in PageController (begin bug)
* Support website endpoint for different regions https://docs.aws.amazon.com/general/latest/gr/s3.html#s3_website_region_endpoints

## 3.0.79

* Remove editor.js dependence in page generator

## 3.0.78

* Increase sleep between s3 policy requests
* Add epoch time to end of buckent name to improve on uniqueness

## 3.0.77

* Add missing required roles check
* Add controller wrapper for manipulating app-level models

## 3.0.76

* Add utility script for release new version
* Fix issue where API key description was being redacted

## 3.0.75

* Rename APIKey table to KyteAPIKey to accomodate new model for 3rd party api keys
* Create table for 3rd party APIKeys

## 3.0.74

* Add sleep to help improve async call to AWS when generating buckets and configuring permissions

## 3.0.73

* Assign navbar-light or navbar-dark based on background color luminance using WCAG 2.0 guidelines
* Ability to customize footer background color

## 3.0.72

* Make replace placeholders for HTML a public method

## 3.0.71

* Ability to assign acm cert and aliases when creating CF distribution

## 3.0.70

* Fix array to string conversion for footer styles

## 3.0.69

* Fix issue where section stylesheets were not propagated

## 3.0.68

* Fix bug where numeric values caused a mysql escape error

## 3.0.67

* Add font color to footer styles

## 3.0.66

* Add capability to add footer

## 3.0.65

* Update section template with new attributes

## 3.0.64

* Retrieve app object before requesting s3 presigned url

## 3.0.63

* Return downloadable link for pages

## 3.0.62

* Require AMPHP as new dependency

## 3.0.61

* Return application id in response

## 3.0.60

* Fix ability to delete model files
* Resolve issue with password object being access as array element
* Fix issue where s3 bucket doens't get website enabled

## 3.0.59

* Remove extra condition for checking function name within scope of application

## 3.0.58

* Check for existing controller and function names within scope of application

## 3.0.57

* Fix issue where controller of same name in different app causes error

## 3.0.56

* Store user agent, remote IP, and forwarded IP in session table

## 3.0.55

* fix tag issue

## 3.0.54

* Use shorter username for database

## 3.0.53

* Add application-level AWS key (foreign key)
* Add model for AWS keys
* Move kyte connect and obfuscated version of kyte connect to Application model
* Update to use application specific AWS for application management

## 3.0.52

* Update to datetime format for Page controller

## 3.0.51

* Fix bug where session token is null

## 3.0.50

* Remove redundant call to retrieve user object
* Reduce signature timeout to 5 min
* Create constant for signature timeout 

## 3.0.49

* Fix default CDN to use HTTPS

## 3.0.48

* Allow custom CDN for each implementation
* If custom CDN is not defined, default to current stable

## 3.0.47

* Fix ciritcal bug with DataModel ModelObject instantiation

## 3.0.46

* Fix bug where code to check existing model names is not scoped within application

## 3.0.45

* Use async function to apply bucket policies

## 3.0.44

* Declare a new variable for static media s3 for clarity
* Fix issue where region was not being set

## 3.0.43

* Failed to tag correctly

## 3.0.42

* Fix issue where site entry in DB is created even if region is blank or wrong.

## 3.0.41

* Fix issue with column name change

## 3.0.40

* Add support for user to specify a region to create a new site in

## 3.0.39

* Fix to apply navigation font color to title too

## 3.0.38

* Add ability to change main navigation foreground color
* Add ability to change main navigation background colors
* Add ability to make main navigation stick to top
* Add ability to change main navigation dropdown foreground color
* Add ability to change main navigation dropdown background color

## 3.0.37

* Add flag to determine if a container div should be used to wrap the HTML content
* Fix bug that caused endless looping if parent item was accidentally set to self
* Add password attribute for model
* Check if hook or override of specified type already exists for a controller
* Make function name optional

## 3.0.36

* Ability to override account level scoping

## 3.0.35

* Fix bug where API_URL was never defined (incorrectly defined as APP_URL)

## 3.0.34

* Fix regression where nav logo disapeared

## 3.0.33

* Fix issue with invalid HTML attribute for side navigation wrapper
* Add ability to customize side navigation style
* Fix formatting issue for switch statement in controller functions

## 3.0.32

* Order main nav items by 'center' attribute first, then item order

## 3.0.31

* Removing padding and margins around containers to allow users for maximum styling and customization

## 3.0.30

* Add wrapper around sidenav div for better customization and styling options

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
