## 3.8.1 (pending)

* Fix bug where users cannot add page specific scripts

## 3.8.0

* Fix issue where new pages did not have global script and library assignments
* Remove script and library assignments on page deletion
* Add support for renaming site page files
* Change ErrorHandler to only handle application space errors
* Fix issue with SQL debug verbosity not working
* Add ability to bypass Kyte error handlers
* If page is created with missing menu page link, then place "#"
* Add feature to allow for page republishing if kyte_connect changes, or obfuscation settings change for kyte_connect
* Return user information for version history
* Add global_scope alias in Assignments table

**Database Changes**

*KyteLibraryAssignment*
```sql
ALTER TABLE KyteLibraryAssignment 
ADD COLUMN `global_scope` TINYINT(1) UNSIGNED DEFAULT 0 AFTER `library`;
```

*KyteScriptAssignment*
```sql
ALTER TABLE KyteScriptAssignment 
ADD COLUMN `global_scope` TINYINT(1) UNSIGNED DEFAULT 0 AFTER `script`;
```

*KytePageVersion*
```sql
CREATE TABLE `KytePageVersion` (
    `id` int NOT NULL AUTO_INCREMENT,
    `page` int unsigned NOT NULL,
    `version_number` int unsigned NOT NULL,
    `version_type` enum('auto_save','manual_save','publish') NOT NULL DEFAULT 'manual_save',
    `change_summary` varchar(500) DEFAULT NULL,
    `changes_detected` json DEFAULT NULL, -- stores which fields changed
    `content_hash` varchar(64) NOT NULL, -- SHA256 of combined content for deduplication
    
    -- Page metadata snapshot (only store if changed from previous version)
    `title` varchar(255) DEFAULT NULL,
    `description` text DEFAULT NULL,
    `lang` varchar(255) DEFAULT NULL,
    `page_type` varchar(255) DEFAULT NULL,
    `state` int unsigned DEFAULT NULL,
    `sitemap_include` int unsigned DEFAULT NULL,
    `obfuscate_js` int unsigned DEFAULT NULL,
    `is_js_module` int unsigned DEFAULT NULL,
    `use_container` int unsigned DEFAULT NULL,
    `protected` int unsigned DEFAULT NULL,
    `webcomponent_obj_name` varchar(255) DEFAULT NULL,
    
    -- Relationship references (only if changed)
    `header` int unsigned DEFAULT NULL,
    `footer` int unsigned DEFAULT NULL,
    `main_navigation` int unsigned DEFAULT NULL,
    `side_navigation` int unsigned DEFAULT NULL,
    
    -- Version metadata
    `is_current` tinyint(1) NOT NULL DEFAULT 0,
    `parent_version` int unsigned DEFAULT NULL, -- references previous version
    
    -- Framework field
    `kyte_account` int unsigned NOT NULL,

    -- Audit fields
    `created_by` int NOT NULL,
    `date_created` bigint unsigned,
    `modified_by` int NOT NULL,
    `date_modified` bigint unsigned,
    `deleted_by` int NOT NULL,
    `date_deleted` bigint unsigned,
    `deleted` tinyint(1) NOT NULL DEFAULT 0,
    
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

*KytePageVersionContent*
```sql
CREATE TABLE `KytePageVersionContent` (
    `id` int NOT NULL AUTO_INCREMENT,
    `content_hash` varchar(64) NOT NULL UNIQUE,
    `html` longblob DEFAULT NULL,
    `stylesheet` longblob DEFAULT NULL,
    `javascript` longblob DEFAULT NULL,
    `javascript_obfuscated` longblob DEFAULT NULL,
    `block_layout` longblob DEFAULT NULL,
    `reference_count` int unsigned NOT NULL DEFAULT 1,
    `last_referenced` bigint unsigned NOT NULL,

    -- Framework field
    `kyte_account` int unsigned NOT NULL,

    -- Audit fields
    `created_by` int NOT NULL,
    `date_created` bigint unsigned,
    `modified_by` int NOT NULL,
    `date_modified` bigint unsigned,
    `deleted_by` int NOT NULL,
    `date_deleted` bigint unsigned,
    `deleted` tinyint(1) NOT NULL DEFAULT 0,
    
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

## 3.7.8

* Fix issue where obfuscated javascript was still plain text. Problem was with script_type not being accessed as property member of object.
* If there is an entry in the error log for an undefined array index `labelCenterBlock`, run the following sql statement:
**Database Changes (if not applied previously)**
```sql
ALTER TABLE SideNav ADD labelCenterBlock TINYINT(1) unsigned DEFAULT 0 AFTER columnStyle;
```

## 3.7.7

* Update Kyte Lirbary to support global and non-global includes. Requires a new table which can be added using `gust` as shown below.
*After running composer update*
```bash
gust model add KyteLibraryAssignment
```

## 3.7.6

* Fix issue where model definition did not update correctly after creating, updating, or deleting a new column.

## 3.7.5

* Add support for global includes for custom scripts. Requires a table change in the database (see below)

**Database Changes**
```sql
ALTER TABLE KyteScript ADD include_all TINYINT(1) unsigned DEFAULT 0 AFTER obfuscate_js;
```

## 3.7.4

* Adds LEFT and INNER JOIN SQL support.
* Fixes issue when searching fields within a model that has foregin keys the join only returns if a fk exists.
* Fix database field length issue with `code` in controller (`text` to `longblob`)

## 3.7.3

* Ability to search by field range (int or double).

## 3.7.2

* Enable foreign table attribute searches.

## 3.7.1

* Improve DB fallback if SSL is not available

## 3.7.0

* Adds support for SSL/TLS connection to database

## 3.6.10

* Adds support to edit and delete application level model data

## 3.6.9

* Add support for retrieving IMDS/IDMSv2 data
* Update error handling to include IMDS/IMDSv2 data if available

## 3.6.8

* Add support for sending slack notifications for errors

## 3.6.7

* Add `SessionInspector` controller

## 3.6.6

* Move `is_js_module` from `KytePageData` to `KytePage`

## 3.6.5

* Refactor code and remove unreachable statements following a throw.
* Add member methods for deleting or purging retrieved objects.
* Add ability to mark JS code in a page as a module.
* Support for logging exceptions and errors at application level

## 3.6.4

* Fix issue #36 where user object being access for application was not at the application scope level

## 3.6.3

* Fix issue #34 where controller function couldn't be deleted

## 3.6.2

* Fix issue where blob data was not being stored in DB

## 3.6.1

* Add support for marking scripts as JavaScript modules
* Add support for assigning element ID and/or class
* Add support for default site langauge, and page specific languages
* Bug fix to remove calls to deprecated Permission model
* Add support for additional MySQL types
* Add URL decode for field name when parsing URL paths

## 3.6.0

* Remove model based roles and permissions in preparations for a more streamlined RBAC
* Add last login to user model
* Store last login when session is created

## 3.5.7

* Fix issue with wrong navigation's custom nav item style

## 3.5.6

* Fix issue with custom nav item style not propagating

## 3.5.5

* Simplify version response and exclude git hashes etc.
* Change logout handler to use element class instead of id so multiple logout handlers can be configured.
* Add flag for logout option for side nav
* Add attribute for making side nav lable centered and icon block

## 3.5.4

* Fix issue where KyteWebComponent was returning empty data

## 3.5.3

* Fix problem where assinged Kyte Web Components were returning compressed binary data.

## 3.5.2

* Fix problem where compressed binary data was being returned as part of foreign key for SideNavItem

## 3.5.1

* Allow for user defined variable name for Kyte Web Component

## 3.5.0

* Enhanced PHP backend integration for dynamic web component rendering.
* Implemented functionality to output HTML templates in an object format compatible with KyteWebComponent, enabling seamless integration with frontend JavaScript.
* Added robust server-side handling for web component data, including secure compression and decompression functionalities.
* Improved codebase to support efficient loading and rendering of web components, optimizing both frontend and backend performance.

## 3.4.7

* Fix bug where footer and header where not decompressed for nav/sidenav, scripts, and libraries.

## 3.4.6

* Fix navigation item to return empty string for html data

## 3.4.5

* Add `KyteScriptAssignment` model for tracking what scripts are going to be included in which `KytePage`s
* Remove `include_all` attribute from `KyteScript` model as all assignments will be tracked by `KyteScriptAssignment`
* Remove duplicate code for page creation out of `KytePageDataController`
* Update `createHTML` to include custom scripts based on `KyteScriptAssignment`

## 3.4.4

* Decompress section template fk data for `KytePageDataController`

## 3.4.3

* Decompress section template fk data for `KytePage`

## 3.4.2

* Delete page data when page is deleted
* Add environment variable specific for data stores (s3 bucket name and region)
* Fix release script to check for Version.php as too many version mismatches have occurred
* Compress KyteScript for custom script data
* Compress section templates
* Add attribute for storing block layout information in `KyteSectionTemplate`
* Rename section templates as `KyteSectionTemplate`

## 3.4.1

* Update value of environment variable to type text

## 3.4.0

* Add environment variable setup at API init()
* May break functionality if environment variable model isn't configured in database prior to update
* Move db column creation and update from `hook_response_data` to `hook_preprocess` to better handle exceptions
* Cast array param as object
* Add new Environment Variable model
* Add support to create new constants from application-level environment variables
* Application-level environment variables are scoped within the application at runtime
* Add controller for triggering update of Kyte Shipyard(tm)

## 3.3.4

* Wrap db column manipulation inside try-catch

## 3.3.3

* Delete failed attribute creations

## 3.3.2

* Resolve issue where main site management was being sent to sqs

## 3.3.1

* Fix bug that caused SQS to be used instead of SNS

## 3.3.0

* This version migrates away from SQS to SNS
* MAY BREAK if using SQS - Switch to SNS before upgrading

## 3.2.9

* Increment counter for generating search query

## 3.2.8

* Update version number in class

## 3.2.7

* Check if search field is a member attribute before querying

## 3.2.6

* Fix issue where controller object could be null

## 3.2.5

* Do not through exception if controller is not found in application scope

## 3.2.4

* Check if app id is present before loading application level controllers

## 3.2.3

* Only load relevant controllers through app

## 3.2.2

* Store model def as json string in db
* No longer read/write model def in file
* Load model def from json string
* Add default path for sample config
* Check AWS keys within account scope

## 3.2.1

* Add constant for default Kyte models

## 3.2.0

* Removed deprecated values

Migration must be performed with version 3.1.1 prior to upgrade.

## 3.1.1

* Add back deprecated attributes until next minor version update to ensure smooth migration

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
* Change Site to KyteSite
* Update controller for site to use KyteSite

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
