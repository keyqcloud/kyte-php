<?php

namespace Kyte\Mvc\Controller;

class KytePageController extends ModelController
{
    public function hook_init() {
        $this->dateformat = 'm/d/Y H:i:s';
    }

    public function hook_preprocess($method, &$r, &$o = null) {
        switch ($method) {
            case 'new':
                $r['s3key'] = strtolower(preg_replace('/[^A-Za-z0-9_.-\/]/', '-', $r['s3key']));
                break;

            case 'update':
                // check if s3key is being updated (i.e renaming file)
                if (isset($r['s3key']) && $r['s3key'] != $o->s3key) {
                    $r['s3key'] = strtolower(preg_replace('/[^A-Za-z0-9_.-\/]/', '-', $r['s3key']));
                    $d = $this->getObject($o);
                    // move s3 file if it exists
                    $credential = new \Kyte\Aws\Credentials($d['site']['region'], $app->aws_public_key, $app->aws_private_key);
                    $s3 = new \Kyte\Aws\S3($credential, $d['site']['s3BucketName']);
                    $s3->rename($o->s3key, $r['s3key']);
                    // invalidate CF cache
                    $invalidationPaths = ['/*'];
                    if (KYTE_USE_SNS) {
                        $credential = new \Kyte\Aws\Credentials(SNS_REGION);
                        $sns = new \Kyte\Aws\Sns($credential, SNS_QUEUE_SITE_MANAGEMENT);
                        $sns->publish([
                            'action' => 'cf_invalidate',
                            'site_id' => $d['site']['id'],
                            'cf_id' => $d['site']['cfDistributionId'],
                            'cf_invalidation_paths' => $invalidationPaths,
                            'caller_id' => time(),
                        ]);
                    } else {
                        // invalidate CF
                        $cf = new \Kyte\Aws\CloudFront($credential);
                        $cf->createInvalidation($d['site']['cfDistributionId'], $invalidationPaths);
                    }
                }
                break;

            default:
                break;
        }
    }

    public function hook_response_data($method, $o, &$r = null, &$d = null) {
        if (isset($r['footer']) && is_array($r['footer']) &&
            isset($r['footer']['html'], $r['footer']['stylesheet'], $r['footer']['javascript'],
                $r['footer']['javascript_obfuscated'], $r['footer']['block_layout'])) {

            $r['footer']['html'] = bzdecompress($r['footer']['html']);
            $r['footer']['stylesheet'] = bzdecompress($r['footer']['stylesheet']);
            $r['footer']['javascript'] = bzdecompress($r['footer']['javascript']);
            $r['footer']['javascript_obfuscated'] = bzdecompress($r['footer']['javascript_obfuscated']);
            $r['footer']['block_layout'] = bzdecompress($r['footer']['block_layout']);
        }

        if (isset($r['header']) && is_array($r['header']) &&
            isset($r['header']['html'], $r['header']['stylesheet'], $r['header']['javascript'],
                $r['header']['javascript_obfuscated'], $r['header']['block_layout'])) {

            $r['header']['html'] = bzdecompress($r['header']['html']);
            $r['header']['stylesheet'] = bzdecompress($r['header']['stylesheet']);
            $r['header']['javascript'] = bzdecompress($r['header']['javascript']);
            $r['header']['javascript_obfuscated'] = bzdecompress($r['header']['javascript_obfuscated']);
            $r['header']['block_layout'] = bzdecompress($r['header']['block_layout']);
        }

        switch ($method) {
            case 'get':
                $app = new \Kyte\Core\ModelObject(Application);
                if (!$app->retrieve('id', $r['site']['application']['id'])) {
                    throw new \Exception("CRITICAL ERROR: Unable to find application.");
                }
                $credential = new \Kyte\Aws\Credentials($r['site']['region'], $app->aws_public_key, $app->aws_private_key);
                $s3 = new \Kyte\Aws\S3($credential, $r['site']['s3BucketName']);
                $r['download_link'] = $s3->getObject($o->s3key);

                // Add version information to response
                $r['version_info'] = $this->getPageVersionInfo($o->id);
                break;

            case 'new':
                $pd = new \Kyte\Core\ModelObject(KytePageData);
                $bz_html = isset($d['html']) ? bzcompress($d['html'], 9) : '';
                $bz_stylesheet = isset($d['stylesheet']) ? bzcompress($d['stylesheet'], 9) : '';
                $bz_javascript = isset($d['javascript']) ? bzcompress($d['javascript'], 9) : '';
                $bz_javascript_obfuscated = isset($d['javascript_obfuscated']) ? bzcompress($d['javascript_obfuscated'], 9) : '';
                $bz_block_layout = isset($d['block_layout']) ? bzcompress($d['block_layout'], 9) : '';

                if(!$pd->create([
                    'page' => $o->id,
                    'html' => $bz_html,
                    'stylesheet' => $bz_stylesheet,
                    'javascript' => $bz_javascript,
                    'javascript_obfuscated' => $bz_javascript_obfuscated,
                    'block_layout' => $bz_block_layout,
                    'kyte_account' => $this->account->id,
                    'created_by' => $this->user->id,
                    'date_created' => time(),
                ])) {
                    $o->purge();
                    throw new \Exception("CRITICAL ERROR: Unable to find page data.");
                }

                // Create initial version
                $this->createPageVersion($o, $d, 'manual_save', 'Initial version');

                // check for global includes and assign them
                $kyteLibraries = new \Kyte\Core\Model(KyteLibrary);
                $kyteLibraries->retrieve('include_all', 1, false, [['field' => 'kyte_account', 'value' => $this->account->id]], false, [['field' => 'id', 'direction' => 'asc']]);
                foreach ($kyteLibraries->objects as $lib) {
                    if ($lib->include_all == 1) {
                        $assignment = new \Kyte\Core\ModelObject(KyteLibraryAssignment);
                        if(!$assignment->create([
                            'library' => $lib->id,
                            'page' => $o->id,
                            'site' => $o->site,
                            'kyte_account' => $this->account->id,
                        ], $this->api->user->id)) {
                            throw new \Exception("CRITICAL ERROR: Unable to assign library ".$lib->name." to page.");
                        }
                    }
                }

                // check for global scripts and assign them
                $kyteScripts = new \Kyte\Core\Model(KyteScript);
                $kyteScripts->retrieve('include_all', 1, false, [['field' => 'kyte_account', 'value' => $this->account->id]], false, [['field' => 'id', 'direction' => 'asc']]);
                foreach ($kyteScripts->objects as $script) {
                    if ($script->include_all == 1) {
                        $assignment = new \Kyte\Core\ModelObject(KyteScriptAssignment);
                        if(!$assignment->create([
                            'script' => $script->id,
                            'page' => $o->id,
                            'site' => $o->site,
                            'kyte_account' => $this->account->id,
                        ], $this->api->user->id)) {
                            throw new \Exception("CRITICAL ERROR: Unable to assign script ".$script->name." to page.");
                        }
                    }
                }
                break;

            case 'update':
                $params = $r;
                $versionType = isset($d['version_type']) ? $d['version_type'] : 'manual_save';
                $changeSummary = isset($d['change_summary']) ? $d['change_summary'] : null;

                if (isset($d['html'], $d['stylesheet'], $d['javascript'], $d['javascript_obfuscated'])) {
                    // Check if content actually changed before creating version
                    if ($this->hasContentChanged($o, $d)) {
                        // Create version before updating
                        $this->createPageVersion($o, $d, $versionType, $changeSummary);

                        $params['html'] = $d['html'];
                        $params['stylesheet'] = $d['stylesheet'];
                        $params['javascript'] = $d['javascript'];
                        $params['javascript_obfuscated'] = $d['javascript_obfuscated'];

                        // Update KytePageData
                        $bz_html = isset($d['html']) ? bzcompress($d['html'], 9) : '';
                        $bz_stylesheet = isset($d['stylesheet']) ? bzcompress($d['stylesheet'], 9) : '';
                        $bz_javascript = isset($d['javascript']) ? bzcompress($d['javascript'], 9) : '';
                        $bz_javascript_obfuscated = isset($d['javascript_obfuscated']) ? bzcompress($d['javascript_obfuscated'], 9) : '';
                        $bz_block_layout = isset($d['block_layout']) ? bzcompress($d['block_layout'], 9) : '';

                        $pd = new \Kyte\Core\ModelObject(KytePageData);
                        if($pd->retrieve('page', $o->id)) {
                            $pd->save([
                                'html' => $bz_html,
                                'stylesheet' => $bz_stylesheet,
                                'javascript' => $bz_javascript,
                                'javascript_obfuscated' => $bz_javascript_obfuscated,
                                'block_layout' => $bz_block_layout,
                                'modified_by' => $this->user->id,
                                'date_modified' => time(),
                            ]);
                        } else {
                            throw new \Exception("CRITICAL ERROR: Unable to find page data.");
                        }
                    }
                }

                // Handle state changes and publishing 
                if ($o->state == 1 && !isset($d['state'])) {
                    $o->save(['state' => 2]);
                }

                if (isset($d['state']) && $d['state'] == 1) {
                    $this->publishPage($o, $params, $r);
                }
                break;

            case 'delete':
                // Clean up versions when page is deleted
                $this->cleanupPageVersions($o->id);

                $d = $this->getObject($o);

                $app = new \Kyte\Core\ModelObject(Application);
                if (!$app->retrieve('id', $d['site']['application']['id'])) {
                    throw new \Exception("CRITICAL ERROR: Unable to find application.");
                }

                $pd = new \Kyte\Core\ModelObject(KytePageData);
                if ($pd->retrieve('page', $o->id)) {
                    $pd->delete();
                }

                // remove library assignments
                $assignments = new \Kyte\Core\Model(KyteLibraryAssignment);
                $assignments->retrieve('page', $o->id);
                foreach ($assignments->objects as $a) {
                    $a->delete();
                }

                // remove script assignments
                $assignments = new \Kyte\Core\Model(KyteScriptAssignment);
                $assignments->retrieve('page', $o->id);
                foreach ($assignments->objects as $a) {
                    $a->delete();
                }

                // check if s3 key exists
                if (!empty($o->s3key)) {
                    $credential = new \Kyte\Aws\Credentials($d['site']['region'], $app->aws_public_key, $app->aws_private_key);
                    $s3 = new \Kyte\Aws\S3($credential, $d['site']['s3BucketName']);

                    // delete s3 file
                    $s3->unlink($o->s3key);

                    // create or update sitemap
                    $sitemap = self::updateSitemap($d['site']['id'], $d['site']['aliasDomain'] ? $d['site']['aliasDomain'] : $d['site']['cfDomain']);
                    $s3->write('sitemap.xml', $sitemap);

                    // invalidate CF
                    $invalidationPaths = ['/*'];
                    if (KYTE_USE_SNS) {
                        $credential = new \Kyte\Aws\Credentials(SNS_REGION);
                        $sns = new \Kyte\Aws\Sns($credential, SNS_QUEUE_SITE_MANAGEMENT);
                        $sns->publish([
                            'action' => 'cf_invalidate',
                            'site_id' => $d['site']['id'],
                            'cf_id' => $d['site']['cfDistributionId'],
                            'cf_invalidation_paths' => $invalidationPaths,
                            'caller_id' => time(),
                        ]);
                    } else {
                        // invalidate CF
                        $cf = new \Kyte\Aws\CloudFront($credential);
                        $cf->createInvalidation($d['site']['cfDistributionId'], $invalidationPaths);
                    }
                }

                break;
            
            default:
                break;
        }
    }

    /**
     * Publish a page to S3 and invalidate CloudFront cache
     * 
     * @param object $pageObj The page object being published
     * @param array $params Parameters including page content and metadata
     * @param array $responseData Response data containing site information
     * @throws \Exception if critical errors occur during publishing
     */
    private function publishPage($pageObj, $params, &$responseData) {
        // If content fields are not set, retrieve them from database
        if (!isset($params['html'], $params['stylesheet'], $params['javascript'], $params['javascript_obfuscated'])) {
            $pd = new \Kyte\Core\ModelObject(KytePageData);
            if (!$pd->retrieve('page', $pageObj->id)) {
                throw new \Exception("CRITICAL ERROR: Unable to find page data.");
            }
            $params['html'] = bzdecompress($pd->html);
            $params['stylesheet'] = bzdecompress($pd->stylesheet);
            $params['javascript'] = bzdecompress($pd->javascript);
            $params['javascript_obfuscated'] = bzdecompress($pd->javascript_obfuscated);
        }

        // Get application credentials
        $app = new \Kyte\Core\ModelObject(Application);
        if (!$app->retrieve('id', $responseData['site']['application']['id'])) {
            throw new \Exception("CRITICAL ERROR: Unable to find application.");
        }

        // Initialize AWS credentials and S3
        $credential = new \Kyte\Aws\Credentials($responseData['site']['region'], $app->aws_public_key, $app->aws_private_key);
        $s3 = new \Kyte\Aws\S3($credential, $responseData['site']['s3BucketName']);

        // Compile HTML file
        $compiledHtml = self::createHtml($params);
        
        // Write compiled HTML to S3
        $s3->write($pageObj->s3key, $compiledHtml);

        // Update sitemap
        $siteDomain = $responseData['site']['aliasDomain'] ?: $responseData['site']['cfDomain'];
        $sitemap = self::updateSitemap($responseData['site']['id'], $siteDomain);
        $s3->write('sitemap.xml', $sitemap);

        // Create CloudFront invalidation paths
        $invalidationPaths = ['/sitemap.xml'];
        
        // Add page-specific invalidation path
        if (strpos($pageObj->s3key, "index.html") !== false) {
            // For index.html files, invalidate the directory with wildcard
            $invalidationPaths[] = '/' . str_replace("index.html", "*", $pageObj->s3key);
        } else {
            // For other files, invalidate the specific path
            $invalidationPaths[] = '/' . $pageObj->s3key;
        }

        // Invalidate CloudFront cache
        if (KYTE_USE_SNS) {
            // Use SNS for asynchronous invalidation
            $snsCredential = new \Kyte\Aws\Credentials(SNS_REGION);
            $sns = new \Kyte\Aws\Sns($snsCredential, SNS_QUEUE_SITE_MANAGEMENT);
            $sns->publish([
                'action' => 'cf_invalidate',
                'site_id' => $responseData['site']['id'],
                'cf_id' => $responseData['site']['cfDistributionId'],
                'cf_invalidation_paths' => $invalidationPaths,
                'caller_id' => time(),
            ]);
        } else {
            // Direct CloudFront invalidation
            $cf = new \Kyte\Aws\CloudFront($credential);
            $cf->createInvalidation($responseData['site']['cfDistributionId'], $invalidationPaths);
        }
    }

    /**
     * Create a new page version if content has changed
     */
    private function createPageVersion($pageObj, $data, $versionType = 'manual_save', $changeSummary = null) {
        // Get current page data for comparison
        $currentData = $this->getCurrentPageData($pageObj->id);
        
        // Detect changes
        $changes = $this->detectChanges($pageObj, $currentData, $data);
        
        if (empty($changes)) {
            return null; // No changes detected, don't create version
        }

        // Get next version number
        $nextVersion = $this->getNextVersionNumber($pageObj->id);

        // Create content hash for deduplication
        $contentHash = $this->generateContentHash($data);

        // Check if this exact content already exists
        $existingContent = $this->findExistingContent($contentHash);
        
        $versionData = [
            'page' => $pageObj->id,
            'version_number' => $nextVersion,
            'version_type' => $versionType,
            'change_summary' => $changeSummary,
            'changes_detected' => json_encode($changes),
            'content_hash' => $contentHash,
            'is_current' => 1,
            'kyte_account' => $this->account->id,
            'created_by' => $this->user->id,
        ];

        // Only store changed fields to save space
        $this->addChangedFieldsToVersion($versionData, $changes, $pageObj, $data);

        // Mark previous version as not current
        $this->markPreviousVersionsAsNotCurrent($pageObj->id);

        // Create the version record
        $version = new \Kyte\Core\ModelObject(KytePageVersion);
        if (!$version->create($versionData)) {
            throw new \Exception("CRITICAL ERROR: Unable to create page version.");
        }

        // Store or reference content
        if (!$existingContent) {
            $this->storeVersionContent($contentHash, $data);
        } else {
            $this->incrementContentReference($contentHash);
        }

        return $version;
    }

    /**
     * Check if page content has actually changed
     */
    private function hasContentChanged($pageObj, $newData) {
        $currentData = $this->getCurrentPageData($pageObj->id);
        $changes = $this->detectChanges($pageObj, $currentData, $newData);
        return !empty($changes);
    }

    /**
     * Get current page data for comparison
     */
    private function getCurrentPageData($pageId) {
        $pd = new \Kyte\Core\ModelObject(KytePageData);
        if (!$pd->retrieve('page', $pageId)) {
            return null;
        }

        return [
            'html' => bzdecompress($pd->html),
            'stylesheet' => bzdecompress($pd->stylesheet),
            'javascript' => bzdecompress($pd->javascript),
            'javascript_obfuscated' => bzdecompress($pd->javascript_obfuscated),
            'block_layout' => bzdecompress($pd->block_layout),
        ];
    }

    /**
     * Detect what fields have changed
     */
    private function detectChanges($pageObj, $currentData, $newData) {
        $changes = [];

        // Check page metadata fields
        $metadataFields = ['title', 'description', 'lang', 'page_type', 'state', 'sitemap_include', 
                          'obfuscate_js', 'is_js_module', 'use_container', 'protected', 
                          'webcomponent_obj_name', 'header', 'footer', 'main_navigation', 'side_navigation'];

        foreach ($metadataFields as $field) {
            if (isset($newData[$field]) && $pageObj->$field != $newData[$field]) {
                $changes[$field] = [
                    'old' => $pageObj->$field,
                    'new' => $newData[$field]
                ];
            }
        }

        // Check content fields
        $contentFields = ['html', 'stylesheet', 'javascript', 'javascript_obfuscated', 'block_layout'];
        
        foreach ($contentFields as $field) {
            $oldValue = isset($currentData[$field]) ? $currentData[$field] : '';
            $newValue = isset($newData[$field]) ? $newData[$field] : '';
            
            if ($oldValue !== $newValue) {
                $changes[$field] = [
                    'old_length' => strlen($oldValue),
                    'new_length' => strlen($newValue),
                    'changed' => true
                ];
            }
        }

        return $changes;
    }

    /**
     * Get version information for a page
     */
    private function getPageVersionInfo($pageId) {
        $versions = new \Kyte\Core\Model(KytePageVersion);
        $versions->retrieve('page', $pageId, false, null, false, [['field' => 'version_number', 'direction' => 'desc']], 10);
        
        $versionInfo = [
            'current_version' => 0,
            'total_versions' => 0,
            'recent_versions' => []
        ];

        foreach ($versions->objects as $version) {
            if ($version->is_current) {
                $versionInfo['current_version'] = $version->version_number;
            }
            
            $versionInfo['recent_versions'][] = [
                'id' => $version->id,
                'version_number' => $version->version_number,
                'version_type' => $version->version_type,
                'change_summary' => $version->change_summary,
                'changes_detected' => json_decode($version->changes_detected, true),
                'date_created' => $version->date_created,
                'created_by' => $version->created_by,
                'is_current' => (bool)$version->is_current
            ];
        }

        // Get total count
        $totalCount = new \Kyte\Core\Model(KytePageVersion);
        $totalCount->retrieve('page', $pageId);
        $versionInfo['total_versions'] = $totalCount->count();

        return $versionInfo;
    }

    public static function isColorDark($color)
    {
        // Remove the # if it's there
        $color = ltrim($color, '#');
    
        // Convert the hex color to RGB
        $r = hexdec(substr($color, 0, 2));
        $g = hexdec(substr($color, 2, 2));
        $b = hexdec(substr($color, 4, 2));
    
        // Calculate the relative luminance
        $luminance = (0.2126 * $r + 0.7152 * $g + 0.0722 * $b) / 255;
    
        // Decide whether the color is light or dark based on the luminance threshold
        if ($luminance > 0.5) {
            return true; // Light color
        } else {
            return false; // Dark color
        }
    }

    public static function createHtml($page) {
        // Initialize variables
        $gtm_body_script = '';
        $code_parts = [];
        
        // Build language attribute
        $lang = self::buildLanguageAttribute($page);
        
        // Start HTML document
        $code_parts[] = "<!DOCTYPE html><html{$lang}><head>";
        
        // Add analytics scripts
        $code_parts[] = self::buildAnalyticsScripts($page, $gtm_body_script);
        
        // Add meta tags and title
        $code_parts[] = self::buildMetaTags($page);
        
        // Add SEO tags
        $code_parts[] = self::buildSeoTags($page);
        
        // Add core dependencies (jQuery, Bootstrap, jQuery UI)
        $code_parts[] = self::buildCoreDependencies();
        
        // Add Font Awesome
        $code_parts[] = '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">';
        
        // Add optional libraries
        $code_parts[] = self::buildLibraries($page);
        
        // Add KyteJS
        $code_parts[] = '<script src="' . KYTE_JS_CDN . '" crossorigin="anonymous"></script>';
        
        // Add styles
        $code_parts[] = self::buildStyles($page);
        
        // Close head and start body
        $code_parts[] = '</head><body>';
        
        // Add GTM body script
        if ($gtm_body_script) {
            $code_parts[] = $gtm_body_script;
        }
        
        // Add page loader
        $code_parts[] = self::buildPageLoader();
        
        // Add page structure
        $code_parts[] = self::buildPageStructure($page);
        
        // Add custom scripts
        $code_parts[] = self::buildCustomScripts($page);
        
        // Add JavaScript
        $code_parts[] = self::buildJavaScript($page);
        
        // Close body and html
        $code_parts[] = '</body></html>';
        
        return implode('', array_filter($code_parts));
    }

    private static function buildCoreDependencies($options = []) {
        // Check for global config constants first, then use defaults
        $defaults = [
            'jquery_version' => defined('KYTE_JQUERY_VERSION') ? KYTE_JQUERY_VERSION : '3.7.1',
            'bootstrap_version' => defined('KYTE_BOOTSTRAP_VERSION') ? KYTE_BOOTSTRAP_VERSION : '5.3.2',
            'jqueryui_version' => defined('KYTE_JQUERYUI_VERSION') ? KYTE_JQUERYUI_VERSION : '1.13.2',
            'jqueryui_theme' => defined('KYTE_JQUERYUI_THEME') ? KYTE_JQUERYUI_THEME : 'ui-lightness',
            'use_cdn_fallback' => defined('KYTE_USE_CDN_FALLBACK') ? KYTE_USE_CDN_FALLBACK : true,
            'local_fallback_path' => defined('KYTE_LOCAL_FALLBACK_PATH') ? KYTE_LOCAL_FALLBACK_PATH : '/assets/js/',
            'integrity_check' => defined('KYTE_INTEGRITY_CHECK') ? KYTE_INTEGRITY_CHECK : true,
            'primary_cdn' => defined('KYTE_PRIMARY_CDN') ? KYTE_PRIMARY_CDN : 'https://cdnjs.cloudflare.com',
            'fallback_cdn' => defined('KYTE_FALLBACK_CDN') ? KYTE_FALLBACK_CDN : 'https://code.jquery.com',
            'google_cdn' => defined('KYTE_GOOGLE_CDN') ? KYTE_GOOGLE_CDN : 'https://ajax.googleapis.com'
        ];
        
        $config = array_merge($defaults, $options);
        $dependencies = [];
        
        // jQuery
        if ($config['integrity_check']) {
            $dependencies[] = self::buildJQueryWithIntegrity($config);
        } else {
            $dependencies[] = "<script src=\"{$config['primary_cdn']}/ajax/libs/jquery/{$config['jquery_version']}/jquery.min.js\" crossorigin=\"anonymous\"></script>";
        }
        
        if ($config['use_cdn_fallback']) {
            $dependencies[] = self::buildJQueryFallback($config);
        }
        
        // Bootstrap CSS & JS
        $dependencies[] = self::buildBootstrapDependencies($config);
        
        // jQuery UI
        $dependencies[] = self::buildJQueryUIDependencies($config);
        
        return implode('', $dependencies);
    }

    private static function buildJQueryFallback($config) {
        $fallbacks = [];
        
        // Fallback to alternative CDN
        if ($config['fallback_cdn']) {
            $fallbacks[] = "<script>window.jQuery || document.write('<script src=\"{$config['fallback_cdn']}/jquery-{$config['jquery_version']}.min.js\"><\/script>')</script>";
        }
        
        // Fallback to Google CDN
        if ($config['google_cdn']) {
            $fallbacks[] = "<script>window.jQuery || document.write('<script src=\"{$config['google_cdn']}/ajax/libs/jquery/{$config['jquery_version']}/jquery.min.js\"><\/script>')</script>";
        }
        
        // Final fallback to local if path is set
        if ($config['local_fallback_path']) {
            $fallbacks[] = "<script>window.jQuery || document.write('<script src=\"{$config['local_fallback_path']}jquery.min.js\"><\/script>')</script>";
        }
        
        return implode('', $fallbacks);
    }

    private static function buildJQueryWithIntegrity($config) {
        // Keep integrity hashes updated - these are for specific versions
        $integrity_hashes = [
            '3.7.1' => 'sha512-v2CJ7UaYy4JwqLDIrZUI/4hqeoQieOmAZNXBeQyjo21dadnwR+8ZaIJVT8EE2iyI61OV8e6M8PP2/4hpQINQ/g==',
            '3.6.4' => 'sha512-pumBsjNRGGqkPzKHndZMaAG+bir374sORyzM3uulLV14lN5LyykqNk8eEeUlUkB3U0M4FApyaHraT65ihJhDpQ==',
            '3.6.0' => 'sha512-894YE6QWD5I59HgZOGReFYm4dnWc1Qt5NtvYSaNcOP+u1T9qYdvdihz0PPSiiqn/+/3e7Jo4EaG7TubfWGUrMQ==',
        ];
        
        $version = $config['jquery_version'];
        $integrity = isset($integrity_hashes[$version]) ? $integrity_hashes[$version] : '';
        
        if ($integrity) {
            return "<script src=\"{$config['primary_cdn']}/ajax/libs/jquery/{$version}/jquery.min.js\" integrity=\"{$integrity}\" crossorigin=\"anonymous\" referrerpolicy=\"no-referrer\"></script>";
        } else {
            error_log("No integrity hash available for jQuery version {$version}, loading without integrity check");
            return "<script src=\"{$config['primary_cdn']}/ajax/libs/jquery/{$version}/jquery.min.js\" crossorigin=\"anonymous\"></script>";
        }
    }

    private static function buildBootstrapDependencies($config) {
        $version = $config['bootstrap_version'];
        $primary_cdn = $config['primary_cdn'];
        
        // Bootstrap integrity hashes
        $css_integrity = [
            '5.3.2' => 'sha512-b2QcS5SsA8tZodcDtGRELiGv5SaKSk1vDHDaQRda0htPYWZ6046lr3kJ5bAAQdpV2mmA/4v0wQF9MyU6/pDIAg==',
            '5.3.1' => 'sha512-Z/def5z5u2aR89OuzYcxmDJ0Bnd5V1cKqBEbvLOiUNWdg9PQeXVvXLI90SE4QOHGlfLqUnDNVAYyZi8UwUTmWQ==',
            '5.3.0' => 'sha512-t4GWSVZO1eC8BM339Xd7Uphw5s17a86tIZIj8qRxhnKub6WoyhnrxeCIMeAqBPgdZGlCcG2PrZjMc+Wr78+5Xg==',
        ];
        
        $js_integrity = [
            '5.3.2' => 'sha512-X/YkDZyjTf4wyc2Vy16YGCPHwAY8rZJY+POgokZjQB2mhIRFJCckEGc6YyX9eNsPfn0PzThEuNs+uaomE5CO6A==',
            '5.3.1' => 'sha512-fgBNQX8NWKvH/ZjhzZrv2wOdBxVT8Y8+EfaT5j3tJ3bNn/9RiKG3H0W6O5a7PgZ8JOH6T7jJlP8M8NQLfj3CA==',
            '5.3.0' => 'sha512-3dZ9wIrMMij8rOH7X3kLfXAzwtcHpuYpEgQg1OA4QAob1e81H8ntUQmQm3pBudqIoySO5j0tHN4ENzA6+n2r4w==',
        ];
        
        $css_hash = '';
        $js_hash = '';
        
        if ($config['integrity_check']) {
            $css_hash = isset($css_integrity[$version]) ? " integrity=\"{$css_integrity[$version]}\"" : '';
            $js_hash = isset($js_integrity[$version]) ? " integrity=\"{$js_integrity[$version]}\"" : '';
            
            if (!$css_hash || !$js_hash) {
                error_log("No integrity hash available for Bootstrap version {$version}");
            }
        }
        
        return "<link href=\"{$primary_cdn}/ajax/libs/bootstrap/{$version}/css/bootstrap.min.css\" rel=\"stylesheet\"{$css_hash} crossorigin=\"anonymous\" referrerpolicy=\"no-referrer\">" .
            "<script src=\"{$primary_cdn}/ajax/libs/bootstrap/{$version}/js/bootstrap.bundle.min.js\"{$js_hash} crossorigin=\"anonymous\" referrerpolicy=\"no-referrer\"></script>";
    }

    private static function buildJQueryUIDependencies($config) {
        $version = $config['jqueryui_version'];
        $theme = $config['jqueryui_theme'];
        $primary_cdn = $config['primary_cdn'];
        
        $ui_css_integrity = [
            '1.13.2' => 'sha512-b2Vnu4ARgWtz47cxvNMnJtelWzsIHnwLf8YlVWa5+kSPbUnWzr+jg6xHcG2ah0amx2nZ94C9cwz/+R06fFzl6g==',
            '1.13.1' => 'sha512-5UPERJanD1dNBaAcD2iVuSO4UD4zPJd4gVLzAjhg3k2eWTFJJhiVfFLGE3qgb3lYyZRUP5vXBQlL6HPe7eBpww==',
        ];
        
        $ui_js_integrity = [
            '1.13.2' => 'sha512-57oZ/vW8ANMjR/KQ6Be9v/+/h6bq9/l3f0Oc7vn6qMqyhvPd1cvKBRWWpzu0QoneImqr2SkmO4MSqU+RpHom3Q==',
            '1.13.1' => 'sha512-YGP0qyp3lqZUIGnXP4iFq2RDTmPRz3FKuDRVcMM4V9QdvJfmnPnqW1f6fEL5rE2kAn7C8c9Zp6TfD7gH2DZB1Q==',
        ];
        
        $css_hash = '';
        $js_hash = '';
        
        if ($config['integrity_check']) {
            $css_hash = isset($ui_css_integrity[$version]) ? " integrity=\"{$ui_css_integrity[$version]}\"" : '';
            $js_hash = isset($ui_js_integrity[$version]) ? " integrity=\"{$ui_js_integrity[$version]}\"" : '';
        }
        
        $dependencies = [];
        $dependencies[] = "<link rel=\"stylesheet\" href=\"{$primary_cdn}/ajax/libs/jqueryui/{$version}/themes/{$theme}/jquery-ui.min.css\"{$css_hash} crossorigin=\"anonymous\" referrerpolicy=\"no-referrer\">";
        $dependencies[] = "<script src=\"{$primary_cdn}/ajax/libs/jqueryui/{$version}/jquery-ui.min.js\"{$js_hash} crossorigin=\"anonymous\" referrerpolicy=\"no-referrer\"></script>";
        
        if ($config['use_cdn_fallback']) {
            // jQuery UI fallback
            $dependencies[] = "<script>window.jQuery.ui || document.write('<script src=\"{$config['fallback_cdn']}/ui/{$version}/jquery-ui.min.js\"><\/script>')</script>";
            
            // Final fallback to local
            if ($config['local_fallback_path']) {
                $dependencies[] = "<script>window.jQuery.ui || document.write('<script src=\"{$config['local_fallback_path']}jquery-ui.min.js\"><\/script>')</script>";
            }
        }
        
        return implode('', $dependencies);
    }

    private static function buildLanguageAttribute($page) {
        $lang = '';
        if (!empty($page['site']['default_lang'])) {
            $lang = ' lang="' . htmlspecialchars($page['site']['default_lang'], ENT_QUOTES) . '"';
        }
        // Page lang overrides site lang
        if (!empty($page['lang'])) {
            $lang = ' lang="' . htmlspecialchars($page['lang'], ENT_QUOTES) . '"';
        }
        return $lang;
    }

    private static function buildAnalyticsScripts($page, &$gtm_body_script) {
        $scripts = [];
        
        // Google Analytics
        if (!empty($page['site']['ga_code'])) {
            $scripts[] = self::generateGAIntegration($page['site']['ga_code']);
        }
        
        // Google Tag Manager
        if (!empty($page['site']['gtm_code'])) {
            $gtm_script = self::generateGTMIntegration($page['site']['gtm_code']);
            $scripts[] = $gtm_script[0];
            $gtm_body_script = $gtm_script[1];
        }
        
        return implode('', $scripts);
    }

    private static function buildMetaTags($page) {
        return '<meta charset="utf-8">' .
            '<meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">' .
            '<title>' . htmlspecialchars($page['title'], ENT_QUOTES) . '</title>';
    }

    private static function buildSeoTags($page) {
        $seo_tags = [];
        
        $seo_tags[] = '<meta property="og:title" content="' . htmlspecialchars($page['title'], ENT_QUOTES) . '" />';
        
        if (!empty($page['description'])) {
            $escaped_desc = htmlspecialchars($page['description'], ENT_QUOTES);
            $seo_tags[] = '<meta property="og:description" content="' . $escaped_desc . '" />';
            $seo_tags[] = '<meta name="description" content="' . $escaped_desc . '">';
        }
        
        if ($page['sitemap_include'] == 1) {
            $seo_tags[] = '<meta name="robots" content="index,follow" />';
        }
        
        $domain = !empty($page['site']['aliasDomain']) ? $page['site']['aliasDomain'] : $page['site']['cfDomain'];
        $seo_tags[] = '<link rel="canonical" href="https://' . htmlspecialchars($domain, ENT_QUOTES) . '/' . htmlspecialchars($page['s3key'], ENT_QUOTES) . '" />';
        
        return implode('', $seo_tags);
    }

    private static function buildLibraries($page) {
        $libraries = new \Kyte\Core\Model(KyteLibraryAssignment);
        $libraries->retrieve('page', $page['id'], false, [['field' => 'site', 'value' => $page['site']['id']]], false, [['field' => 'id', 'direction' => 'asc']]);
        
        $library_tags = [];
        foreach($libraries->objects as $include) {
            $library = new \Kyte\Core\ModelObject(KyteLibrary);
            if ($library->retrieve('id', $include->library)) {
                $library_tags[] = self::buildLibraryTag($library);
            } else {
                error_log("Unknown library id {$include->library}");
            }
        }
        
        return implode('', $library_tags);
    }

    private static function buildLibraryTag($library) {
        $escaped_link = htmlspecialchars($library->link, ENT_QUOTES);
        
        switch ($library->script_type) {
            case 'js':
                $module_attr = $library->is_js_module == 1 ? ' type="module"' : '';
                return "<script src=\"{$escaped_link}\"{$module_attr}></script>";
            case 'css':
                return "<link rel=\"stylesheet\" href=\"{$escaped_link}\">";
            default:
                error_log("Unknown library type {$library->script_type} for {$library->name} located {$library->link}");
                return '';
        }
    }

    private static function buildStyles($page) {
        $styles = [];
        
        // Custom page styles
        $page_styles = $page['stylesheet'];
        if ($page['side_navigation']) {
            $page_styles .= ' main {display: flex;flex-wrap: nowrap;min-height: 100vh;min-height: --webkit-fill-available;overflow-x: auto;overflow-y: hidden;}';
        }
        $styles[] = "<style>{$page_styles}</style>";
        
        // Navigation styles
        $styles[] = self::buildNavigationStyles($page);
        
        // Header/Footer styles
        $styles[] = self::buildHeaderFooterStyles($page);
        
        // Web component styles
        $styles[] = self::buildWebComponentStyles($page);
        
        return implode('', array_filter($styles));
    }

    private static function buildNavigationStyles($page) {
        $nav_styles = [];
        
        // Main navigation styles
        if (!empty($page['main_navigation'])) {
            $nav = $page['main_navigation'];
            $nav_styles[] = "<style>";
            $nav_styles[] = "#mainnav { background-color: {$nav['bgColor']} !important; }";
            $nav_styles[] = "#mainnav a { color: {$nav['fgColor']} !important; }";
            $nav_styles[] = "#mainnav .dropdown-menu { background-color: {$nav['bgDropdownColor']} !important; }";
            $nav_styles[] = "#mainnav .dropdown-item { color: {$nav['fgDropdownColor']} !important; }";
            $nav_styles[] = "</style>";
        }
        
        // Side navigation styles
        if (!empty($page['side_navigation'])) {
            $sidenav = $page['side_navigation'];
            $nav_styles[] = "<style>";
            
            // Column style logic
            if ($sidenav['columnStyle'] == 1) {
                $nav_styles[] = "#sidenav { background-color: {$sidenav['bgColor']}; margin: 1rem;border-radius: 1em;filter: drop-shadow(0px 0px 6px #000);}";
            } elseif ($sidenav['columnStyle'] == 2) {
                $nav_styles[] = "#sidenav { background-color: {$sidenav['bgColor']}; margin: 1rem;border-radius: 1em;filter: drop-shadow(0px 0px 6px #000); height: 100%; }";
            } else {
                $nav_styles[] = "#sidenav-wrapper { background-color: {$sidenav['bgColor']}; }";
            }
            
            $nav_styles[] = "#sidenav a { color: {$sidenav['fgColor']} !important; }";
            $nav_styles[] = "#sidenav .nav-pills .nav-link.active { background-color: {$sidenav['bgActiveColor']} !important; color: {$sidenav['fgActiveColor']} !important; }";
            $nav_styles[] = "</style>";
        }
        
        return implode('', $nav_styles);
    }

    private static function buildHeaderFooterStyles($page) {
        $styles = [];
        
        if (!empty($page['header'])) {
            $header = $page['header'];
            $styles[] = "<style>.custom-page-header { color: {$header['fgColor']} !important; width:100%; background-color: {$header['bgColor']} !important; }{$header['stylesheet']}</style>";
        }
        
        if (!empty($page['footer'])) {
            $footer = $page['footer'];
            $styles[] = "<style>footer { color: {$footer['fgColor']} !important; position:fixed; bottom:0; width:100%; background-color: {$footer['bgColor']} !important; }{$footer['stylesheet']}</style>";
        }
        
        return implode('', $styles);
    }

    private static function buildWebComponentStyles($page) {
        $webComponents = new \Kyte\Core\Model(KytePageWebComponent);
        $webComponents->retrieve('page', $page['id']);
        
        if ($webComponents->count() === 0) {
            return '';
        }
        
        $component_styles = ['<style>'];
        foreach($webComponents->objects as $component) {
            $template = new \Kyte\Core\ModelObject(KyteWebComponent);
            if ($template->retrieve('id', $component->component)) {
                $component_styles[] = bzdecompress($template->stylesheet);
            }
        }
        $component_styles[] = '</style>';
        
        return implode('', $component_styles);
    }

    private static function buildPageLoader() {
        return '<!-- Page loading modal.  Once session is validated, the loading modal will close. -->' .
            '<div id="pageLoaderModal" class="modal white" data-backdrop="static" data-keyboard="false" tabindex="-1">' .
            '<div class="modal-dialog modal-sm h-100 d-flex">' .
            '<div class="mx-auto align-self-center" style="width: 48px">' .
            '<div class="spinner-wrapper text-center fa-6x">' .
            '<span class="fas fa-sync fa-spin"></span>' .
            '</div></div></div></div><!--  -->';
    }

    private static function buildPageStructure($page) {
        $structure = [];
        
        // Wrapper
        $structure[] = '<div id="wrapper">';
        
        // Header
        if (!empty($page['header'])) {
            $structure[] = '<div class="custom-page-header">' . $page['header']['html'] . '</div>';
        }
        
        // Main navigation
        if (!empty($page['main_navigation'])) {
            $nav_class = self::isColorDark($page['main_navigation']['bgColor']) ? 'navbar-light' : 'navbar-dark';
            $sticky_class = $page['main_navigation']['isStickyTop'] == 1 ? ' sticky-top' : '';
            $structure[] = "<!-- START NAV --><nav id=\"mainnav\" class=\"navbar {$nav_class} navbar-expand-lg{$sticky_class}\"></nav><!-- END NAV -->";
        }
        
        // Main wrapper
        $structure[] = '<main>';
        
        // Side navigation
        if (!empty($page['side_navigation'])) {
            $structure[] = '<!-- BEGIN SIDE NAVIGATION --><div id="sidenav-wrapper" class="d-flex flex-column flex-shrink-0 py-3"><div id="sidenav" class="p-3" style="width: 230px;"></div></div><!-- END SIDE NAVIGATION -->';
        }
        
        // Page container
        $container_class = $page['use_container'] == 1 ? 'class="container container-flex"' : '';
        $structure[] = "<div id=\"kyte-page-container\" {$container_class}>{$page['html']}</div>";
        
        // Close main wrapper
        $structure[] = '</main>';
        
        // Close page wrapper
        $structure[] = '</div>';
        
        // Footer
        if (!empty($page['footer'])) {
            $structure[] = '<footer>' . $page['footer']['html'] . '</footer>';
        }
        
        return implode('', $structure);
    }

    private static function buildCustomScripts($page) {
        $includes = new \Kyte\Core\Model(KyteScriptAssignment);
        $includes->retrieve('page', $page['id'], false, [['field' => 'site', 'value' => $page['site']['id']]], false, [['field' => 'id', 'direction' => 'asc']]);
        
        $script_tags = [];
        foreach($includes->objects as $include) {
            $script = new \Kyte\Core\ModelObject(KyteScript);
            if ($script->retrieve('id', $include->script, [['field' => 'state', 'value' => 1]])) {
                $script_tags[] = self::buildScriptTag($script);
            }
        }
        
        return implode('', $script_tags);
    }

    private static function buildScriptTag($script) {
        $escaped_s3key = htmlspecialchars($script->s3key, ENT_QUOTES);
        
        switch ($script->script_type) {
            case 'js':
                $module_attr = $script->is_js_module == 1 ? ' type="module"' : '';
                return "<script src=\"/{$escaped_s3key}\"{$module_attr}></script>";
            case 'css':
                return "<link rel=\"stylesheet\" href=\"/{$escaped_s3key}\">";
            default:
                error_log("Unknown custom script type {$script->script_type} for script name {$script->name} located {$script->s3key}");
                return '';
        }
    }

    private static function buildJavaScript($page) {
        $js_parts = [];
        
        // Start script tag
        $js_parts[] = $page['is_js_module'] == 1 ? '<script type="module">' : '<script>';
        
        // Add kyte connect
        if ($page['site']['application']['obfuscate_kyte_connect'] == 1) {
            $js_parts[] = $page['site']['application']['kyte_connect_obfuscated'] . "\n\n";
        } else {
            $js_parts[] = $page['site']['application']['kyte_connect'] . "\n\n";
        }
        
        // Add web components
        $js_parts[] = self::buildWebComponentsJS($page);
        
        // Start document ready
        $js_parts[] = '$(document).ready(function() { ';
        
        // Add protection logic
        if ($page['protected'] == 1) {
            $js_parts[] = 'k.addLogoutHandler(".logout");' . "\n";
            $js_parts[] = 'if (k.isSession()) { ' . "\n";
        }
        
        // Add custom JS
        if ($page['obfuscate_js'] == 1) {
            $js_parts[] = $page['javascript_obfuscated'] . "\n";
        } else {
            $js_parts[] = $page['javascript'] . "\n";
        }
        
        // Close protection logic
        if ($page['protected'] == 1) {
            $js_parts[] = ' } else { location.href="/?redir="+encodeURIComponent(window.location); }';
        }
        
        // Add navigation JS
        $js_parts[] = self::buildNavigationJS($page);
        
        // Add header/footer JS
        $js_parts[] = self::buildHeaderFooterJS($page);
        
        // Close document ready and script
        $js_parts[] = ' });</script>';
        
        return implode('', array_filter($js_parts));
    }

    private static function buildWebComponentsJS($page) {
        $webComponents = new \Kyte\Core\Model(KytePageWebComponent);
        $webComponents->retrieve('page', $page['id']);
        
        if ($webComponents->count() === 0) {
            return '';
        }
        
        $js_parts = [];
        $js_parts[] = 'const templates = {';
        
        $template_parts = [];
        foreach($webComponents->objects as $component) {
            $template = new \Kyte\Core\ModelObject(KyteWebComponent);
            if ($template->retrieve('id', $component->component)) {
                $templateHtml = bzdecompress($template->html);
                $identifier = addslashes($template->identifier);
                $templateHtml = addslashes($templateHtml);
                $template_parts[] = "'{$identifier}': `{$templateHtml}`";
            }
        }
        
        $js_parts[] = implode(',', $template_parts);
        $js_parts[] = '};';
        $js_parts[] = "const {$page['webcomponent_obj_name']} = new KyteWebComponent(templates);";
        
        return implode('', $js_parts);
    }

    private static function buildNavigationJS($page) {
        $nav_js = [];
        
        // Main navigation
        if (!empty($page['main_navigation'])) {
            $nav_js[] = self::buildMainNavigationJS($page);
        }
        
        // Side navigation  
        if (!empty($page['side_navigation'])) {
            $nav_js[] = self::buildSideNavigationJS($page);
        }
        
        return implode('', $nav_js);
    }

    private static function buildMainNavigationJS($page) {
        // Get menu items
        $items = new \Kyte\Core\Model(NavigationItem);
        $items->retrieve('navigation', $page['main_navigation']['id'], false, null, false, [['field' => 'itemOrder', 'direction' => 'asc']]);
        
        $menu_items = [];
        $menu_items_center = [];
        $menu_items_right = [];
        $menu_items_center_sub = [];
        $menu_items_right_sub = [];
        
        // Process menu items
        foreach($items->objects as $m) {
            $menu_items[$m->id] = $m;
            $link = self::getMenuItemLink($m);
            $menu_config = self::buildMenuItemConfig($m, $link);
            
            if ($m->center == 1) {
                if ($m->parentItem) {
                    $menu_items_center_sub[$m->parentItem][] = $menu_config;
                } else {
                    $menu_items_center[$m->id] = $menu_config;
                }
            } else {
                if ($m->parentItem) {
                    $menu_items_right_sub[$m->parentItem][] = $menu_config;
                } else {
                    $menu_items_right[$m->id] = $menu_config;
                }
            }
        }
        
        // Build dropdown items
        self::buildDropdownItems($menu_items, $menu_items_center, $menu_items_center_sub);
        self::buildDropdownItems($menu_items, $menu_items_right, $menu_items_right_sub);
        
        // Get navigation link
        $nav_link = self::getNavigationLink($page['main_navigation']);
        
        $center_items = implode('', $menu_items_center);
        $right_items = implode('', $menu_items_right);
        
        return "let appnavdef = [[{$center_items}],[{$right_items}]];" .
            "let navbar = new KyteNav(\"#mainnav\", appnavdef, \"{$page['main_navigation']['logo']}\", \"" . 
            ($page['main_navigation']['logo'] ? '' : $page['site']['name']) . 
            "\", null, \"{$nav_link}\");navbar.create();";
    }

    private static function getMenuItemLink($menuItem) {
        $link = $menuItem->link;
        
        if ($menuItem->page) {
            $linked_page = new \Kyte\Core\ModelObject(KytePage);
            if ($linked_page->retrieve('id', $menuItem->page)) {
                $link = '/' . $linked_page->s3key;
            } else {
                $link = '#';
            }
        }
        
        return $link;
    }

    private static function buildMenuItemConfig($menuItem, $link) {
        $config_parts = [];
        
        if ($menuItem->isLogout == 1) {
            $config_parts[] = 'logout:true';
        }
        
        $config_parts[] = "faicon:\"{$menuItem->faicon}\"";
        $config_parts[] = "id:\"{$menuItem->element_id}\"";
        
        $css_class = $menuItem->center == 1 ? 'me-2 text-light' : 'me-2 text-light';
        if (!empty($menuItem->element_class)) {
            $css_class .= ' ' . $menuItem->element_class;
        }
        $config_parts[] = "class:\"{$css_class}\"";
        
        $config_parts[] = "label:\"{$menuItem->title}\"";
        $config_parts[] = "href:\"{$link}\"";
        
        return '{' . implode(',', $config_parts) . '},';
    }

    private static function buildDropdownItems($menu_items, &$target_array, $sub_items) {
        foreach(array_keys($sub_items) as $key) {
            $parent_item = $menu_items[$key];
            $css_class = 'me-2 text-light';
            if (!empty($parent_item->element_class)) {
                $css_class .= ' ' . $parent_item->element_class;
            }
            
            $items_string = implode('', $sub_items[$key]);
            $target_array[$key] = "{dropdown:true,id:\"{$parent_item->element_id}\",class:\"{$css_class}\",label:\"{$parent_item->title}\",items:[{$items_string}]},";
        }
    }

    private static function getNavigationLink($navigation) {
        $nav_link = $navigation['link'] ? $navigation['link'] : '/';
        
        if ($navigation['page']) {
            $linked_page = new \Kyte\Core\ModelObject(KytePage);
            if ($linked_page->retrieve('id', $navigation['page'])) {
                $nav_link = '/' . $linked_page->s3key;
            }
        }
        
        return $nav_link;
    }

    private static function buildSideNavigationJS($page) {
        $items = new \Kyte\Core\Model(SideNavItem);
        $items->retrieve('sidenav', $page['side_navigation']['id'], false, null, false, [['field' => 'itemOrder', 'direction' => 'asc']]);
        
        $side_menu_items = [];
        $default_sidenav = '';
        
        foreach($items->objects as $m) {
            $link = $m->link;
            if ($m->page) {
                $linked_page = new \Kyte\Core\ModelObject(KytePage);
                if (!$linked_page->retrieve('id', $m->page)) {
                    throw new \Exception("Unable to find page");
                }
                $link = '/' . $linked_page->s3key;
            }
            
            $config_parts = [];
            $config_parts[] = "faicon:\"{$m->faicon}\"";
            $config_parts[] = "label:\"{$m->title}\"";
            
            if (!(isset($link[0]) && $link[0] == '#')) {
                $config_parts[] = "id:\"{$m->element_id}\"";
            }
            
            $config_parts[] = "class:\"{$m->element_class}\"";
            
            if ($m->isLogout == 1) {
                $config_parts[] = 'logout:true';
            }
            
            if ($page['side_navigation']['labelCenterBlock'] == 1) {
                $config_parts[] = 'labelCenterBlock:true';
            }
            
            if (isset($link[0]) && $link[0] == '#') {
                $config_parts[] = "selector:\"{$link}\"";
            } else {
                $config_parts[] = "href:\"{$link}\"";
            }
            
            $side_menu_items[] = '{' . implode(',', $config_parts) . '},';
        }
        
        if (count($items->objects) > 0) {
            if (isset($items->objects[0]->link) && $items->objects[0]->link[0] == '#') {
                $default_sidenav = $items->objects[0]->link;
            }
        }
        
        $items_string = implode('', $side_menu_items);
        return "let sidenavdef = [{$items_string}];" .
            "let sidenav = new KyteSidenav(\"#sidenav\", sidenavdef, \"{$default_sidenav}\");sidenav.create();sidenav.bind();";
    }

    private static function buildHeaderFooterJS($page) {
        $js_parts = [];
        
        if (!empty($page['header'])) {
            if ($page['header']['obfuscate_js'] == 1) {
                $js_parts[] = $page['header']['javascript_obfuscated'] . "\n";
            } else {
                $js_parts[] = $page['header']['javascript'] . "\n";
            }
        }
        
        if (!empty($page['footer'])) {
            if ($page['footer']['obfuscate_js'] == 1) {
                $js_parts[] = $page['footer']['javascript_obfuscated'] . "\n";
            } else {
                $js_parts[] = $page['footer']['javascript'] . "\n";
            }
        }
        
        return implode('', $js_parts);
    }

    public static function generateGAIntegration($ga_code) {
        // Validate GA code format (GA4: G-XXXXXXXXXX, Universal: UA-XXXXXXXX-X)
        if (!self::isValidGACode($ga_code)) {
            error_log("Invalid Google Analytics code format: {$ga_code}");
            return '';
        }
        
        $escaped_code = htmlspecialchars($ga_code, ENT_QUOTES);
        
        return "<!-- Google tag (gtag.js) -->" .
            "<script async src=\"https://www.googletagmanager.com/gtag/js?id={$escaped_code}\"></script>" .
            "<script>" .
            "window.dataLayer = window.dataLayer || [];" .
            "function gtag(){dataLayer.push(arguments);}" .
            "gtag('js', new Date());" .
            "gtag('config', '{$escaped_code}');" .
            "</script>";
    }

    public static function generateGTMIntegration($gtm_code) {
        // Validate GTM code format (GTM-XXXXXXX)
        if (!self::isValidGTMCode($gtm_code)) {
            error_log("Invalid Google Tag Manager code format: {$gtm_code}");
            return ['', ''];
        }
        
        $escaped_code = htmlspecialchars($gtm_code, ENT_QUOTES);
        
        $head_script = "<!-- Google Tag Manager -->" .
                    "<script>" .
                    "(function(w,d,s,l,i){" .
                    "w[l]=w[l]||[];" .
                    "w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});" .
                    "var f=d.getElementsByTagName(s)[0]," .
                    "j=d.createElement(s)," .
                    "dl=l!='dataLayer'?'&l='+l:'';" .
                    "j.async=true;" .
                    "j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;" .
                    "f.parentNode.insertBefore(j,f);" .
                    "})(window,document,'script','dataLayer','{$escaped_code}');" .
                    "</script>" .
                    "<!-- End Google Tag Manager -->";
        
        $body_script = "<!-- Google Tag Manager (noscript) -->" .
                    "<noscript>" .
                    "<iframe src=\"https://www.googletagmanager.com/ns.html?id={$escaped_code}\" " .
                    "height=\"0\" width=\"0\" style=\"display:none;visibility:hidden\">" .
                    "</iframe>" .
                    "</noscript>" .
                    "<!-- End Google Tag Manager (noscript) -->";
        
        return [$head_script, $body_script];
    }

    public static function updateSitemap($siteIdx, $siteDomain) {
        // Validate inputs
        if (!is_numeric($siteIdx) || $siteIdx <= 0) {
            error_log("Invalid site index for sitemap generation: {$siteIdx}");
            return false;
        }
        
        if (!self::isValidDomain($siteDomain)) {
            error_log("Invalid domain for sitemap generation: {$siteDomain}");
            return false;
        }
        
        // Retrieve pages with optimized query
        $pages = new \Kyte\Core\Model(KytePage);
        $conditions = [
            ['field' => 'state', 'value' => '1'],
            ['field' => 'protected', 'value' => '0'],
            ['field' => 'sitemap_include', 'value' => '1'],
            ['field' => 'site', 'value' => $siteIdx]
        ];
        $order = [['field' => 'date_modified', 'direction' => 'desc']];
        
        $pages->retrieve('state', '1', false, $conditions, false, $order);
        
        if ($pages->count() === 0) {
            // Return minimal sitemap if no pages found
            return self::generateEmptySitemap();
        }
        
        // Build sitemap efficiently
        $sitemap_parts = [];
        $sitemap_parts[] = self::generateSitemapHeader();
        
        $escaped_domain = htmlspecialchars($siteDomain, ENT_QUOTES);
        foreach($pages->objects as $page) {
            $sitemap_parts[] = self::generateSitemapUrlTag($page, $escaped_domain);
        }
        
        $sitemap_parts[] = self::generateSitemapFooter();
        
        return implode('', $sitemap_parts);
    }

    public static function generateSitemapUrlTag($page, $siteDomain) {
        // Validate page object
        if (!isset($page->s3key, $page->date_modified)) {
            error_log("Invalid page object for sitemap generation");
            return '';
        }
        
        // Sanitize and validate inputs
        $escaped_domain = htmlspecialchars($siteDomain, ENT_QUOTES);
        $escaped_s3key = htmlspecialchars($page->s3key, ENT_QUOTES);
        
        // Ensure s3key doesn't start with slash (avoid double slashes)
        $s3key = ltrim($escaped_s3key, '/');
        
        // Format date properly (ensure it's a valid timestamp)
        $last_modified = is_numeric($page->date_modified) ? 
            date('Y-m-d', $page->date_modified) : 
            date('Y-m-d');
        
        // Add change frequency and priority based on page characteristics
        $change_freq = self::determineChangeFrequency($page);
        $priority = self::determinePriority($page);
        
        return "\t<url>\n" .
            "\t\t<loc>https://{$escaped_domain}/{$s3key}</loc>\n" .
            "\t\t<lastmod>{$last_modified}</lastmod>\n" .
            "\t\t<changefreq>{$change_freq}</changefreq>\n" .
            "\t\t<priority>{$priority}</priority>\n" .
            "\t</url>\n";
    }

    public static function generateSitemapUrlSet() {
        // Deprecated: Use generateSitemapHeader() and generateSitemapFooter() instead
        return [
            self::generateSitemapHeader(),
            self::generateSitemapFooter()
        ];
    }

    // New helper methods for better functionality and validation

    private static function generateSitemapHeader() {
        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n" .
            "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
    }

    private static function generateSitemapFooter() {
        return "</urlset>";
    }

    private static function generateEmptySitemap() {
        return self::generateSitemapHeader() . self::generateSitemapFooter();
    }

    private static function isValidGACode($code) {
        // GA4 format: G-XXXXXXXXXX or Universal Analytics: UA-XXXXXXXX-X
        return preg_match('/^(G-[A-Z0-9]{10}|UA-\d{4,10}-\d{1,4})$/', $code);
    }

    private static function isValidGTMCode($code) {
        // GTM format: GTM-XXXXXXX
        return preg_match('/^GTM-[A-Z0-9]{7}$/', $code);
    }

    private static function isValidDomain($domain) {
        // Basic domain validation
        return filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }

    private static function determineChangeFrequency($page) {
        // Determine change frequency based on page characteristics
        // You can customize this logic based on your specific needs
        
        if (isset($page->page_type)) {
            switch ($page->page_type) {
                case 'blog':
                case 'news':
                    return 'daily';
                case 'product':
                    return 'weekly';
                case 'static':
                case 'about':
                case 'contact':
                    return 'monthly';
                default:
                    return 'weekly';
            }
        }
        
        // Default fallback
        return 'weekly';
    }

    private static function determinePriority($page) {
        // Determine priority based on page characteristics
        // Priority should be between 0.0 and 1.0
        
        if (isset($page->s3key)) {
            // Homepage gets highest priority
            if ($page->s3key === '' || $page->s3key === 'index') {
                return '1.0';
            }
            
            // Important pages get high priority
            if (in_array($page->s3key, ['about', 'contact', 'services', 'products'])) {
                return '0.8';
            }
            
            // Blog/news pages get medium priority
            if (strpos($page->s3key, 'blog') !== false || strpos($page->s3key, 'news') !== false) {
                return '0.6';
            }
        }
        
        // Default priority for other pages
        return '0.5';
    }

    // Enhanced version for better performance with large sitemaps
    public static function generateSitemapIndex($sites) {
        // For sites with multiple sitemaps or large page counts
        $sitemap_parts = [];
        $sitemap_parts[] = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $sitemap_parts[] = "<sitemapindex xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
        
        foreach ($sites as $site) {
            if (!self::isValidDomain($site['domain'])) {
                continue;
            }
            
            $escaped_domain = htmlspecialchars($site['domain'], ENT_QUOTES);
            $last_modified = date('Y-m-d');
            
            $sitemap_parts[] = "\t<sitemap>\n";
            $sitemap_parts[] = "\t\t<loc>https://{$escaped_domain}/sitemap.xml</loc>\n";
            $sitemap_parts[] = "\t\t<lastmod>{$last_modified}</lastmod>\n";
            $sitemap_parts[] = "\t</sitemap>\n";
        }
        
        $sitemap_parts[] = "</sitemapindex>";
        
        return implode('', $sitemap_parts);
    }

    // Version control helper functions

    /**
     * Get the next version number for a page
     */
    private function getNextVersionNumber($pageId) {
        $lastVersion = new \Kyte\Core\Model(KytePageVersion);
        $lastVersion->retrieve('page', $pageId, false, null, false, [['field' => 'version_number', 'direction' => 'desc']], 1);
        
        if ($lastVersion->count() > 0) {
            return $lastVersion->objects[0]->version_number + 1;
        }
        
        return 1;
    }

    /**
     * Generate content hash for deduplication
     */
    private function generateContentHash($data) {
        $contentString = '';
        $contentFields = ['html', 'stylesheet', 'javascript', 'block_layout'];
        
        foreach ($contentFields as $field) {
            $contentString .= isset($data[$field]) ? $data[$field] : '';
        }
        
        return hash('sha256', $contentString);
    }

    /**
     * Find existing content with same hash
     */
    private function findExistingContent($contentHash) {
        $content = new \Kyte\Core\ModelObject(KytePageVersionContent);
        return $content->retrieve('content_hash', $contentHash, [['field' => 'kyte_account', 'value' => $this->account->id]]) ? $content : null;
    }

    /**
     * Store new version content
     */
    private function storeVersionContent($contentHash, $data) {
        $contentData = [
            'content_hash' => $contentHash,
            'html' => isset($data['html']) ? bzcompress($data['html'], 9) : null,
            'stylesheet' => isset($data['stylesheet']) ? bzcompress($data['stylesheet'], 9) : null,
            'javascript' => isset($data['javascript']) ? bzcompress($data['javascript'], 9) : null,
            'javascript_obfuscated' => isset($data['javascript_obfuscated']) ? bzcompress($data['javascript_obfuscated'], 9) : null,
            'block_layout' => isset($data['block_layout']) ? bzcompress($data['block_layout'], 9) : null,
            'reference_count' => 1,
            'kyte_account' => $this->account->id,
            'last_referenced' => time(),
        ];

        $content = new \Kyte\Core\ModelObject(KytePageVersionContent);
        if (!$content->create($contentData)) {
            throw new \Exception("CRITICAL ERROR: Unable to store version content.");
        }
        
        return $content;
    }

    /**
     * Increment reference count for existing content
     */
    private function incrementContentReference($contentHash) {
        $content = new \Kyte\Core\ModelObject(KytePageVersionContent);
        if ($content->retrieve('content_hash', $contentHash, [['field' => 'kyte_account', 'value' => $this->account->id]])) {
            $content->save([
                'reference_count' => $content->reference_count + 1,
                'last_referenced' => time()
            ]);
        }
    }

    /**
     * Mark all previous versions as not current
     */
    private function markPreviousVersionsAsNotCurrent($pageId) {
        $lastVersion = new \Kyte\Core\ModelObject(KytePageVersion);
        if ($lastVersion->retrieve('page', $pageId, [['field' => 'is_current', 'value' => 1]])) {
            $lastVersion->save(['is_current' => 0]);
        }
        
        // Use direct SQL for efficiency
        $sql = "UPDATE KytePageVersion SET is_current = 0 WHERE page = ? AND is_current = 1";
        // This would use your framework's database connection
        // $this->db->execute($sql, [$pageId]);
    }

    /**
     * Add only changed fields to version data to save space
     */
    private function addChangedFieldsToVersion(&$versionData, $changes, $pageObj, $newData) {
        // Add changed metadata fields
        $metadataFields = ['title', 'description', 'lang', 'page_type', 'state', 'sitemap_include', 
                        'obfuscate_js', 'is_js_module', 'use_container', 'protected', 
                        'webcomponent_obj_name', 'header', 'footer', 'main_navigation', 'side_navigation'];

        foreach ($metadataFields as $field) {
            if (isset($changes[$field])) {
                $versionData[$field] = isset($newData[$field]) ? $newData[$field] : $pageObj->$field;
            }
        }

        // Add changed content fields (compressed)
        $contentFields = ['html', 'stylesheet', 'javascript', 'javascript_obfuscated', 'block_layout'];
        
        foreach ($contentFields as $field) {
            if (isset($changes[$field]) && isset($newData[$field])) {
                $versionData[$field] = bzcompress($newData[$field], 9);
            }
        }
    }

    /**
     * Decrement content reference count and clean up if needed
     */
    private function decrementContentReference($contentHash) {
        $content = new \Kyte\Core\ModelObject(KytePageVersionContent);
        if ($content->retrieve('content_hash', $contentHash, [['field' => 'kyte_account', 'value' => $this->account->id]])) {
            if ($content->reference_count <= 1) {
                $content->delete();
            } else {
                $content->save(['reference_count' => $content->reference_count - 1]);
            }
        }
    }

    /**
     * Clean up content records with zero references
     */
    private function cleanupUnreferencedContent() {
        $contents = new \Kyte\Core\Model(KytePageVersionContent);
        $contents->retrieve('reference_count', 0);
        foreach ($contents->objects as $content) {
            $content->delete();
        }
        // Use direct SQL for efficiency
        // $sql = "DELETE FROM KytePageVersionContent WHERE reference_count <= 0";
        // $this->db->execute($sql);
    }

    /**
     * Clean up all versions when a page is deleted
     */
    private function cleanupPageVersions($pageId) {
        $versions = new \Kyte\Core\Model(KytePageVersion);
        $versions->retrieve('page', $pageId);
        
        foreach ($versions->objects as $version) {
            $this->decrementContentReference($version->content_hash);
            $version->delete();
        }
        
        $this->cleanupUnreferencedContent();
    }
}
