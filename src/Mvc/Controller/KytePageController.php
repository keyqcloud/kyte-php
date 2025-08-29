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
        $gtm_body_script = '';  // empty gtm body script...if gtm code is present, will be populates with gtm script

        $lang = '';
        if (strlen($page['site']['default_lang'] > 0)) {
            $lang = ' lang="'.$page['site']['default_lang'].'"';
        }
        // if page lang is set, override site lang
        if (strlen($page['lang']) > 0) {
            $lang = ' lang="'.$page['lang'].'"';
        }
        $code = '<!DOCTYPE html><html'.$lang.'><head>';
        
        // Google Analytics
        if (strlen($page['site']['ga_code']) > 0) {
            $code .= self::generateGAIntegration($page['site']['ga_code']);
        }
        // Google Tag Manager
        if (strlen($page['site']['gtm_code']) > 0) {
            $gtm_script = self::generateGTMIntegration($page['site']['gtm_code']);
            $code .= $gtm_script[0];
            $gtm_body_script = $gtm_script[1];
        }

        $code .= '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no"><title>'.$page['title'].'</title>';

        // SEO
        $code .= '<meta property="og:title" content="'.$page['title'].'" />';
        if (strlen($page['description'])) {
            $code .= '<meta property="og:description" content="'.$page['description'].'" />';
            $code .= '<meta name="description" content="'.$page['description'].'">';
        }
        if ($page['sitemap_include'] == 1) {
            $code .= '<meta name="robots" content="index,follow" />';
        }
        $code .= '<link rel="canonical" href="https://'.(strlen($page['site']['aliasDomain']) > 0 ? $page['site']['aliasDomain'] : $page['site']['cfDomain']).'/'.$page['s3key'].'" />';

        
        // font aweseom
        $code .= '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">';
        
        // retrieve libraries
        $libraries = new \Kyte\Core\Model(KyteLibraryAssignment);
        $libraries->retrieve('page', $page['id'], false, [['field' => 'site', 'value' => $page['site']['id']]], false, [['field' => 'id', 'direction' => 'asc']]);
        foreach($libraries->objects as $include) {
            $library = new \Kyte\Core\ModelObject(KyteLibrary);
            if ($library->retrieve('id', $include->library)) {
                switch ($library->script_type) {
                    case 'js':
                        $code .= '<script src="'.$library->link.'"'.($library->is_js_module == 1 ? ' type="module"' : '').'></script>';
                        break;
                    case 'css':
                        $code .= '<link rel="stylesheet" href="'.$library->link.'">';
                        break;
                    default:
                        error_log("Unknown library type {$library->script_type} for {$library->name} located {$library->link}");
                }
            } else {
                error_log("Unknown library id {$include->library}");
            }
        }

        // KyteJS
        $code .= '<script src="'.KYTE_JS_CDN.'" crossorigin="anonymous"></script>';

        // custom styles
        $code .= '<style>'.$page['stylesheet'].($page['side_navigation'] ? ' main {display: flex;flex-wrap: nowrap;min-height: 100vh;min-height: --webkit-fill-available;overflow-x: auto;overflow-y: hidden;}' : '').'</style>';

        // main navigation styles
        if ($page['main_navigation']) {
            $code .='<style>';
            $code .= '#mainnav { background-color: '.$page['main_navigation']['bgColor'].' !important; }';
            $code .= '#mainnav a { color: '.$page['main_navigation']['fgColor'].' !important; }';
            $code .= '#mainnav .dropdown-menu { background-color: '.$page['main_navigation']['bgDropdownColor'].' !important; }';
            $code .= '#mainnav .dropdown-item { color: '.$page['main_navigation']['fgDropdownColor'].' !important; }';
            $code .='</style>';
        }
        // side navigation styles
        if ($page['side_navigation']) {
            $code .='<style>';
            if ($page['side_navigation']['columnStyle'] == 1) {
                $code .= '#sidenav { background-color: '.$page['side_navigation']['bgColor'].'; margin: 1rem;border-radius: 1em;filter: drop-shadow(0px 0px 6px #000);}';
            } else if($page['side_navigation']['columnStyle'] == 2) {
                $code .= '#sidenav { background-color: '.$page['side_navigation']['bgColor'].'; margin: 1rem;border-radius: 1em;filter: drop-shadow(0px 0px 6px #000); height: 100%; }';
            } else {
                $code .= '#sidenav-wrapper { background-color: '.$page['side_navigation']['bgColor'].'; }';
            }
            
            $code .= '#sidenav a { color: '.$page['side_navigation']['fgColor'].' !important; }';
            $code .= '#sidenav .nav-pills .nav-link.active { background-color: '.$page['side_navigation']['bgActiveColor'].' !important; color: '.$page['side_navigation']['fgActiveColor'].' !important; }';
            $code .='</style>';
        }
        if ($page['header']) {
            $code .='<style>.custom-page-header { color: '.$page['header']['fgColor'].' !important; width:100%; background-color: '.$page['header']['bgColor'].' !important; }'.$page['header']['stylesheet'].'</style>';
        }
        if ($page['footer']) {
            $code .='<style>footer { color: '.$page['footer']['fgColor'].' !important; position:fixed; bottom:0; width:100%; background-color: '.$page['footer']['bgColor'].' !important; }'.$page['footer']['stylesheet'].'</style>';
        }

        // styles for any web components
        // retrieve libraries
        $webComponents = new \Kyte\Core\Model(KytePageWebComponent);
        $webComponents->retrieve('page', $page['id']);
        if ($webComponents->count() > 0) {
            $code .= '<style>';
            foreach($webComponents->objects as $component) {
                $template = new \Kyte\Core\ModelObject(KyteWebComponent);
                if ($template->retrieve('id', $component->component)) {
                    $code .= bzdecompress($template->stylesheet);
                }
            }
            $code .= '</style>';
        }

        // close head
        $code .= '</head>';

        // body
        $code .= '<body>';

        // Google Tag Manager
        if (strlen($page['site']['gtm_code']) > 0) {
            $code .= $gtm_body_script;
        }

        // loader
        $code .= '<!-- Page loading modal.  Once session is validated, the loading modal will close. --><div id="pageLoaderModal" class="modal white" data-backdrop="static" data-keyboard="false" tabindex="-1"><div class="modal-dialog modal-sm h-100 d-flex"><div class="mx-auto align-self-center" style="width: 48px"><div class="spinner-wrapper text-center fa-6x"><span class="fas fa-sync fa-spin"></span></div></div></div></div><!--  -->';

        // wrapper
        $code .= '<div id="wrapper">';

        // header
        if ($page['header']) {
            $code .= '<div class="custom-page-header">';
            $code .= $page['header']['html'];
            $code .= '</div>';
        }

        // main navigation and header
        if ($page['main_navigation']) {
            $code .= '<!-- START NAV --><nav id="mainnav" class="navbar '.(self::isColorDark($page['main_navigation']['bgColor']) ? 'navbar-light' : 'navbar-dark').' navbar-expand-lg'.($page['main_navigation']['isStickyTop'] == 1 ? ' sticky-top' : '').'"></nav><!-- END NAV -->';
        }

        // main wrapper
        $code .= '<main>';

        // side navigation
        if ($page['side_navigation']) {
            $code .= '<!-- BEGIN SIDE NAVIGATION --><div id="sidenav-wrapper" class="d-flex flex-column flex-shrink-0 py-3"><div id="sidenav" class="p-3" style="width: 230px;"></div></div><!-- END SIDE NAVIGATION -->';
        }

        $code .= '<div id="kyte-page-container"'.($page['use_container'] == 1 ? 'class="container container-flex"' : '').'>'.$page['html'].'</div>';

        // close main wrapper
        $code .= '</main>';

        // close page wrapper
        $code .= '</div>';

        // footer
        if ($page['footer']) {
            $code .= '<footer>';
            $code .= $page['footer']['html'];
            $code .= '</footer>';
        }

        // retrieve custom javascripts and stylesheets
        $includes = new \Kyte\Core\Model(KyteScriptAssignment);
        $includes->retrieve('page', $page['id'], false, [['field' => 'site', 'value' => $page['site']['id']]], false, [['field' => 'id', 'direction' => 'asc']]);
        foreach($includes->objects as $include) {
            $script = new \Kyte\Core\ModelObject(KyteScript);
            if ($script->retrieve('id', $include->script, [['field' => 'state', 'value' => 1]])) {
                switch ($script->script_type) {
                    case 'js':
                        $code .= '<script src="/'.$script->s3key.'"'.($script->is_js_module == 1 ? ' type="module"' : '').'></script>';
                        break;
                    case 'css':
                        $code .= '<link rel="stylesheet" href="/'.$script->s3key.'">';
                        break;
                    default:
                        error_log("Unknown custom script type {$script->script_type} for script name {$script->name} located {$script->s3key}");
                }
            }
        }

        // begin javascript
        $code .= $page['is_js_module'] == 1 ? '<script type="module">' : '<script>';

        // add kyte connect
        if ($page['site']['application']['obfuscate_kyte_connect'] == 1) {
            $code .= $page['site']['application']['kyte_connect_obfuscated']."\n\n";
        } else {
            $code .= $page['site']['application']['kyte_connect']."\n\n";
        }

        if ($webComponents->count() > 0) {
            $code .= 'const templates = {';
            foreach($webComponents->objects as $component) {
                $template = new \Kyte\Core\ModelObject(KyteWebComponent);
                if ($template->retrieve('id', $component->component)) {
                    $templateHtml = bzdecompress($template->html);
                    // Ensure the component identifier and template are properly escaped for JavaScript
                    $identifier = addslashes($template->identifier);
                    $templateHtml = addslashes($templateHtml);
        
                    // Construct the object key-value pair
                    $code .= "'$identifier': `$templateHtml`,";
                }
            }
            $code = rtrim($code, ','); // Remove the last comma
            $code .= '};'; // Close the templates object
            $code .= 'const '.$page['webcomponent_obj_name'].' = new KyteWebComponent(templates);';
        }

        // custom js
        $code .= '$(document).ready(function() { ';
        if ($page['protected'] == 1) {
            $code .= 'k.addLogoutHandler(".logout");'."\n";
            $code .= 'if (k.isSession()) { '."\n";
        }
        if ($page['obfuscate_js'] == 1) {
            $code .= $page['javascript_obfuscated']."\n";
        } else {
            $code .= $page['javascript']."\n";
        }
        if ($page['protected'] == 1) {
            $code .= ' } else { location.href="/?redir="+encodeURIComponent(window.location); }';
        }

        // add navigation code
        if ($page['main_navigation']) {
            // retrieve menu items and create array
            $items = new \Kyte\Core\Model(NavigationItem);
            $items->retrieve('navigation', $page['main_navigation']['id'], false, null, false, [['field' => 'itemOrder', 'direction' => 'asc']]);
            $menu_items = [];
            $menu_items_center = [];
            $menu_items_right = [];
            $menu_items_center_sub = [];
            $menu_items_right_sub = [];
            foreach($items->objects as $m) {
                $menu_items[$m->id] = $m;
                $link = $m->link;
                // if page is set, get page
                if ($m->page) {
                    $linked_page = new \Kyte\Core\ModelObject(KytePage);
                    if ($linked_page->retrieve('id', $m->page)) {
                        $link = '/'.$linked_page->s3key;
                    } else {
                        $link = '#';
                    }
                }
                if ($m->center == 1) {
                    if ($m->parentItem) {
                        $menu_items_center_sub[$m->parentItem][] = '{'.($m->isLogout == 1 ? 'logout:true,':'').'faicon:"'.$m->faicon.'",id:"'.$m->element_id.'",class:"me-2 text-dark'.(strlen($m->element_class) > 0 ? ' '.$m->element_class : '').'",label:"'.$m->title.'",href:"'.$link.'"},';
                    } else {
                        $menu_items_center[$m->id] = '{'.($m->isLogout == 1 ? 'logout:true,':'').'faicon:"'.$m->faicon.'",id:"'.$m->element_id.'",class:"me-2 text-light'.(strlen($m->element_class) > 0 ? ' '.$m->element_class : '').'",label:"'.$m->title.'",href:"'.$link.'"},';
                    }
                } else {
                    if ($m->parentItem) {
                        $menu_items_right_sub[$m->parentItem][] = '{'.($m->isLogout == 1 ? 'logout:true,':'').'faicon:"'.$m->faicon.'",id:"'.$m->element_id.'",class:"me-2 text-dark'.(strlen($m->element_class) > 0 ? ' '.$m->element_class : '').'",label:"'.$m->title.'",href:"'.$link.'"},';
                    } else {
                        $menu_items_right[$m->id] = '{'.($m->isLogout == 1 ? 'logout:true,':'').'faicon:"'.$m->faicon.'",id:"'.$m->element_id.'",class:"me-2 text-light'.(strlen($m->element_class) > 0 ? ' '.$m->element_class : '').'",label:"'.$m->title.'",href:"'.$link.'"},';
                    }
                }
            }
            foreach(array_keys($menu_items_center_sub) as $key) {
                $menu_items_center[$key] = '{dropdown:true,id:"'.$menu_items[$key]->element_id.'",class:"me-2 text-light'.(strlen($menu_items[$key]->element_class) > 0 ? ' '.$menu_items[$key]->element_class : '').'",label:"'.$menu_items[$key]->title.'",items:['.implode($menu_items_center_sub[$key]).']},';
            }
            foreach(array_keys($menu_items_right_sub) as $key) {
                $menu_items_right[$key] = '{dropdown:true,id:"'.$menu_items[$key]->element_id.'",class:"me-2 text-light'.(strlen($menu_items[$key]->element_class) > 0 ? ' '.$menu_items[$key]->element_class : '').'",label:"'.$menu_items[$key]->title.'",items:['.implode($menu_items_right_sub[$key]).']},';
            }
            $nav_link = $page['main_navigation']['link'] ? $page['main_navigation']['link'] : '/';
            if ($page['main_navigation']['page']) {
                $linked_page = new \Kyte\Core\ModelObject(KytePage);
                if ($linked_page->retrieve('id', $page['main_navigation']['page'])) {
                    $nav_link = '/'.$linked_page->s3key;
                }
            }
            
            $code .= 'let appnavdef = [['.implode($menu_items_center).'],['.implode($menu_items_right).']];';
            $code .= 'let navbar = new KyteNav("#mainnav", appnavdef, "'.$page['main_navigation']['logo'].'", "'.($page['main_navigation']['logo'] ? '' : $page['site']['name']).'", null, "'.$nav_link.'");navbar.create();';
        }

        // side navigation
        if ($page['side_navigation']) {
            // retrieve menu items and create array
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
                    $link = '/'.$linked_page->s3key;
                }
                $side_menu_items[] = '{faicon:"'.$m->faicon.'",label:"'.$m->title.'",'.(isset($link[0]) && $link[0] == '#' ? '' : 'id:"'.$m->element_id.'",').'class:"'.$m->element_class.'",'.($m->isLogout == 1 ? 'logout:true,':'').($page['side_navigation']['labelCenterBlock'] == 1 ? 'labelCenterBlock:true,':'').(isset($link[0]) && $link[0] == '#' ? 'selector:"'.$link.'"' : 'href:"'.$link.'"').'},';
            }
            if (count($items->objects) > 0) {
                if (isset($items->objects[0]->link) && $items->objects[0]->link[0] == '#') {
                    $default_sidenav = $items->objects[0]->link;
                }
            }
            //
            $code .= 'let sidenavdef = ['.implode($side_menu_items).'];';
            $code .= 'let sidenav = new KyteSidenav("#sidenav", sidenavdef, "'.$default_sidenav.'");sidenav.create();sidenav.bind();';
        }

        if ($page['header']) {
            if ($page['header']['obfuscate_js'] == 1) {
                $code .= $page['header']['javascript_obfuscated']."\n";
            } else {
                $code .= $page['header']['javascript']."\n";
            }
        }

        if ($page['footer']) {
            if ($page['footer']['obfuscate_js'] == 1) {
                $code .= $page['footer']['javascript_obfuscated']."\n";
            } else {
                $code .= $page['footer']['javascript']."\n";
            }
        }

        $code .= ' });</script>';

        // close body
        $code .= '</body>';
        // close html
        $code .= '</html>';

        return $code;
    }

    public static function generateGAIntegration($ga_code) {
        return "<!-- Google tag (gtag.js) --><script async src=\"https://www.googletagmanager.com/gtag/js?id=$ga_code\"></script><script>window.dataLayer = window.dataLayer || []; function gtag(){dataLayer.push(arguments);} gtag('js', new Date());gtag('config', '$ga_code');</script>";
    }

    public static function generateGTMIntegration($gtm_code) {
        return [
            "<!-- Google Tag Manager --><script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','$gtm_code');</script><!-- End Google Tag Manager -->",
            "<!-- Google Tag Manager (noscript) --><noscript><iframe src=\"https://www.googletagmanager.com/ns.html?id=$gtm_code\" height=\"0\" width=\"0\" style=\"display:none;visibility:hidden\"></iframe></noscript><!-- End Google Tag Manager (noscript) -->"
        ];
    }

    public static function updateSitemap($siteIdx, $siteDomain) {
        $pages = new \Kyte\Core\Model(KytePage);
        $pages->retrieve('state', '1', false, [['field' => 'protected', 'value' => '0'],['field' => 'sitemap_include', 'value' => '1'],['field' => 'site', 'value' => $siteIdx]], false, [['field' => 'date_modified', 'direction' => 'desc']]);
        $urlset = self::generateSitemapUrlSet();
        $sitemap = $urlset[0];
        foreach($pages->objects as $page) {
            $sitemap .= self::generateSitemapUrlTag($page, $siteDomain);
        }
        $sitemap .= $urlset[1];

        return $sitemap;
    }

    public static function generateSitemapUrlTag($page, $siteDomain) {
        return "\t<url>\n\t\t<loc>https://$siteDomain/{$page->s3key}</loc>\n\t\t<lastmod>".date('Y-m-d', $page->date_modified)."</lastmod>\n\t</url>\n";
    }

    public static function generateSitemapUrlSet() {
        return [
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n",
            "</urlset>"
        ];
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
     * Revert page to a specific version
     */
    public function revertToVersion($pageId, $versionId, $createNewVersion = true) {
        $version = new \Kyte\Core\ModelObject(KytePageVersion);
        if (!$version->retrieve('id', $versionId)) {
            throw new \Exception("Version not found.");
        }

        if ($version->page != $pageId) {
            throw new \Exception("Version does not belong to this page.");
        }

        // Get the page object
        $page = new \Kyte\Core\ModelObject(KytePage);
        if (!$page->retrieve('id', $pageId)) {
            throw new \Exception("Page not found.");
        }

        // Get full version data by reconstructing from version chain
        $fullVersionData = $this->reconstructVersionData($version);

        if ($createNewVersion) {
            // Create a new version before reverting (for undo capability)
            $currentData = $this->getCurrentPageData($pageId);
            if ($currentData) {
                $this->createPageVersion($page, $currentData, 'revert', "Revert to version {$version->version_number}");
            }
        }

        // Update the page with version data
        $pageUpdateData = [];
        $contentUpdateData = [];

        // Extract metadata changes
        $metadataFields = ['title', 'description', 'lang', 'page_type', 'state', 'sitemap_include', 
                        'obfuscate_js', 'is_js_module', 'use_container', 'protected', 
                        'webcomponent_obj_name', 'header', 'footer', 'main_navigation', 'side_navigation'];

        foreach ($metadataFields as $field) {
            if (isset($fullVersionData[$field])) {
                $pageUpdateData[$field] = $fullVersionData[$field];
            }
        }

        // Extract content changes
        $contentFields = ['html', 'stylesheet', 'javascript', 'javascript_obfuscated', 'block_layout'];
        
        foreach ($contentFields as $field) {
            if (isset($fullVersionData[$field])) {
                $contentUpdateData[$field] = bzcompress($fullVersionData[$field], 9);
            }
        }

        // Update page metadata
        if (!empty($pageUpdateData)) {
            $pageUpdateData['modified_by'] = $this->user->id;
            $pageUpdateData['date_modified'] = time();
            $page->save($pageUpdateData);
        }

        // Update page content
        if (!empty($contentUpdateData)) {
            $pd = new \Kyte\Core\ModelObject(KytePageData);
            if ($pd->retrieve('page', $pageId)) {
                $contentUpdateData['modified_by'] = $this->user->id;
                $contentUpdateData['date_modified'] = time();
                $pd->save($contentUpdateData);
            }
        }

        return true;
    }

    /**
     * Reconstruct full version data by walking back through version chain
     */
    private function reconstructVersionData($version) {
        $data = [];
        $currentVersion = $version;

        // Start with the target version and walk backwards to fill in missing fields
        while ($currentVersion) {
            // Add any fields that haven't been set yet
            $allFields = ['title', 'description', 'lang', 'page_type', 'state', 'sitemap_include', 
                        'obfuscate_js', 'is_js_module', 'use_container', 'protected', 
                        'webcomponent_obj_name', 'header', 'footer', 'main_navigation', 'side_navigation',
                        'html', 'stylesheet', 'javascript', 'javascript_obfuscated', 'block_layout'];

            foreach ($allFields as $field) {
                if (!isset($data[$field]) && $currentVersion->$field !== null) {
                    if (in_array($field, ['html', 'stylesheet', 'javascript', 'javascript_obfuscated', 'block_layout'])) {
                        // Decompress content fields
                        $data[$field] = bzdecompress($currentVersion->$field);
                    } else {
                        $data[$field] = $currentVersion->$field;
                    }
                }
            }

            // Move to parent version if we still need more fields
            if ($currentVersion->parent_version) {
                $parentVersion = new \Kyte\Core\ModelObject(KytePageVersion);
                if ($parentVersion->retrieve('id', $currentVersion->parent_version)) {
                    $currentVersion = $parentVersion;
                } else {
                    break;
                }
            } else {
                break;
            }
        }

        return $data;
    }

    /**
     * Get version history for a page with pagination
     */
    public function getVersionHistory($pageId, $limit = 20) {
        $versions = new \Kyte\Core\Model(KytePageVersion);
        $versions->retrieve('page', $pageId, false, null, false, 
                        [['field' => 'version_number', 'direction' => 'desc']], 
                        $limit);

        $history = [];
        foreach ($versions->objects as $version) {
            $changes = json_decode($version->changes_detected, true) ?: [];
            
            $history[] = [
                'id' => $version->id,
                'version_number' => $version->version_number,
                'version_type' => $version->version_type,
                'change_summary' => $version->change_summary,
                'changes_detected' => $changes,
                'change_count' => count($changes),
                'date_created' => $version->date_created,
                'created_by' => $version->created_by,
                'is_current' => (bool)$version->is_current,
                'can_revert' => !$version->is_current // Can't revert to current version
            ];
        }

        return $history;
    }

    /**
     * Compare two versions and return differences
     */
    public function compareVersions($versionId1, $versionId2) {
        $version1 = new \Kyte\Core\ModelObject(KytePageVersion);
        $version2 = new \Kyte\Core\ModelObject(KytePageVersion);

        if (!$version1->retrieve('id', $versionId1) || !$version2->retrieve('id', $versionId2)) {
            throw new \Exception("One or both versions not found.");
        }

        if ($version1->page != $version2->page) {
            throw new \Exception("Versions must be from the same page.");
        }

        $data1 = $this->reconstructVersionData($version1);
        $data2 = $this->reconstructVersionData($version2);

        $differences = [];
        $allFields = array_unique(array_merge(array_keys($data1), array_keys($data2)));

        foreach ($allFields as $field) {
            $value1 = isset($data1[$field]) ? $data1[$field] : null;
            $value2 = isset($data2[$field]) ? $data2[$field] : null;

            if ($value1 !== $value2) {
                $differences[$field] = [
                    'version_' . $version1->version_number => $value1,
                    'version_' . $version2->version_number => $value2,
                    'field_type' => in_array($field, ['html', 'stylesheet', 'javascript', 'javascript_obfuscated', 'block_layout']) ? 'content' : 'metadata'
                ];
            }
        }

        return [
            'version1' => [
                'id' => $version1->id,
                'version_number' => $version1->version_number,
                'date_created' => $version1->date_created
            ],
            'version2' => [
                'id' => $version2->id,
                'version_number' => $version2->version_number,
                'date_created' => $version2->date_created
            ],
            'differences' => $differences
        ];
    }

    /**
     * Clean up old versions based on retention policy
     */
    public function cleanupOldVersions($pageId, $retentionPolicy = null) {
        // Default retention: keep last 50 versions, or versions newer than 90 days
        $retentionPolicy = $retentionPolicy ?: [
            'max_versions' => 50,
            'max_age_days' => 90,
            'always_keep_published' => true,
            'always_keep_manual_saves' => 10 // Keep at least 10 manual saves
        ];

        $versions = new \Kyte\Core\Model(KytePageVersion);
        $versions->retrieve('page', $pageId, false, null, false, 
                        [['field' => 'version_number', 'direction' => 'desc']]);

        if ($versions->count() <= $retentionPolicy['max_versions']) {
            return; // Don't clean up if under limit
        }

        $cutoffDate = time() - ($retentionPolicy['max_age_days'] * 24 * 60 * 60);
        $manualSaveCount = 0;
        $versionsToDelete = [];

        foreach (array_reverse($versions->objects) as $index => $version) {
            $shouldKeep = false;

            // Always keep current version
            if ($version->is_current) {
                $shouldKeep = true;
            }
            // Keep recent versions
            elseif ($index < $retentionPolicy['max_versions']) {
                $shouldKeep = true;
            }
            // Keep versions newer than cutoff
            elseif ($version->date_created > $cutoffDate) {
                $shouldKeep = true;
            }
            // Keep published versions if policy says so
            elseif ($retentionPolicy['always_keep_published'] && $version->version_type === 'publish') {
                $shouldKeep = true;
            }
            // Keep some manual saves
            elseif ($version->version_type === 'manual_save' && $manualSaveCount < $retentionPolicy['always_keep_manual_saves']) {
                $shouldKeep = true;
                $manualSaveCount++;
            }

            if (!$shouldKeep) {
                $versionsToDelete[] = $version;
            }
        }

        // Delete old versions and decrement content references
        foreach ($versionsToDelete as $version) {
            $this->decrementContentReference($version->content_hash);
            $version->delete();
        }

        // Clean up unreferenced content
        $this->cleanupUnreferencedContent();
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
