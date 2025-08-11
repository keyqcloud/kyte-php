<?php

namespace Kyte\Mvc\Controller;

class KytePageController extends ModelController
{
    public function hook_init() {
        $this->dateformat = 'm/d/Y H:i:s';
    }
    // public function hook_auth() {}

    // public function hook_prequery($method, &$field, &$value, &$conditions, &$all, &$order) {}

    public function hook_preprocess($method, &$r, &$o = null) {
        switch ($method) {
            case 'new':
                $r['s3key'] = strtolower(preg_replace('/[^A-Za-z0-9_.-\/]/', '-', $r['s3key']));
                break;

            default:
                break;
        }
    }

    public function hook_response_data($method, $o, &$r = null, &$d = null) {
        if ($r['footer'] && isset($r['footer']['html'], $r['footer']['stylesheet'], $r['footer']['javascript'], $r['footer']['javascript_obfuscated'], $r['footer']['block_layout'])) {
            $r['footer']['html'] = bzdecompress($r['footer']['html']);
            $r['footer']['stylesheet'] = bzdecompress($r['footer']['stylesheet']);
            $r['footer']['javascript'] = bzdecompress($r['footer']['javascript']);
            $r['footer']['javascript_obfuscated'] = bzdecompress($r['footer']['javascript_obfuscated']);
            $r['footer']['block_layout'] = bzdecompress($r['footer']['block_layout']);
        }
        if ($r['header'] && isset($r['header']['html'], $r['header']['stylesheet'], $r['header']['javascript'], $r['header']['javascript_obfuscated'], $r['header']['block_layout'])) {
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
                break;
            case 'update':
                $params = $r;
                if (isset($d['html'], $d['stylesheet'], $d['javascript'], $d['javascript_obfuscated'])) {
                    $params['html'] = $d['html'];
                    $params['stylesheet'] = $d['stylesheet'];
                    $params['javascript'] = $d['javascript'];
                    $params['javascript_obfuscated'] = $d['javascript_obfuscated'];
                    //
                    $bz_html = isset($d['html']) ? bzcompress($d['html'], 9) : '';
                    $bz_stylesheet = isset($d['stylesheet']) ? bzcompress($d['stylesheet'], 9) : '';
                    $bz_javascript = isset($d['javascript']) ? bzcompress($d['javascript'], 9) : '';
                    $bz_javascript_obfuscated = isset($d['javascript_obfuscated']) ? bzcompress($d['javascript_obfuscated'], 9) : '';
                    $bz_block_layout = isset($d['block_layout']) ? bzcompress($d['block_layout'], 9) : '';
                    //
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
                if ($o->state == 1 && !isset($d['state'])) {
                    $o->save(['state' => 2]);
                }
                if (isset($d['state']) && $d['state'] == 1) {
                    // if these fields are not set then retrieve them from source
                    if (!isset($d['html'], $d['stylesheet'], $d['javascript'], $d['javascript_obfuscated'])) {
                        $pd = new \Kyte\Core\ModelObject(KytePageData);
                        if (!$pd->retrieve('page', $o->id)) {
                            throw new \Exception("CRITICAL ERROR: Unable to find page data.");
                        }
                        $params['html'] = bzdecompress($pd->html);
                        $params['stylesheet'] = bzdecompress($pd->stylesheet);
                        $params['javascript'] = bzdecompress($pd->javascript);
                        $params['javascript_obfuscated'] = bzdecompress($pd->javascript_obfuscated);
                    }
                    $app = new \Kyte\Core\ModelObject(Application);
                    if (!$app->retrieve('id', $r['site']['application']['id'])) {
                        throw new \Exception("CRITICAL ERROR: Unable to find application.");
                    }

                    // publish file to s3
                    $credential = new \Kyte\Aws\Credentials($r['site']['region'], $app->aws_public_key, $app->aws_private_key);
                    $s3 = new \Kyte\Aws\S3($credential, $r['site']['s3BucketName']);

                    // compile html file
                    $data = self::createHtml($params);
                    // write to file
                    $s3->write($o->s3key, $data);

                    // create or update sitemap
                    $sitemap = self::updateSitemap($r['site']['id'], $r['site']['aliasDomain'] ? $r['site']['aliasDomain'] : $r['site']['cfDomain']);
                    $s3->write('sitemap.xml', $sitemap);

                    // create cf invalidation paths
                    $invalidationPaths = ['/sitemap.xml'];
                    $invalidationPaths[] = strpos($o->s3key, "index.html") !== false ? '/'.str_replace("index.html", "*", $o->s3key) : '/'.$o->s3key;
                    // invalidate CF
                    if (KYTE_USE_SNS) {
                        $credential = new \Kyte\Aws\Credentials(SNS_REGION);
                        $sns = new \Kyte\Aws\Sns($credential, SNS_QUEUE_SITE_MANAGEMENT);
                        $sns->publish([
                            'action' => 'cf_invalidate',
                            'site_id' => $r['site']['id'],
                            'cf_id' => $r['site']['cfDistributionId'],
                            'cf_invalidation_paths' => $invalidationPaths,
                            'caller_id' => time(),
                        ]);
                    } else {
                        // invalidate CF
                        $cf = new \Kyte\Aws\CloudFront($credential);
                        $cf->createInvalidation($r['site']['cfDistributionId'], $invalidationPaths);
                    }
                }
                break;

            case 'delete':
                $d = $this->getObject($o);

                $app = new \Kyte\Core\ModelObject(Application);
                if (!$app->retrieve('id', $d['site']['application']['id'])) {
                    throw new \Exception("CRITICAL ERROR: Unable to find application.");
                }

                $pd = new \Kyte\Core\ModelObject(KytePageData);
                if ($pd->retrieve('page', $o->id)) {
                    $pd->delete();
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

    // public function hook_process_get_response(&$r) {}

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
        $libraries = new \Kyte\Core\Model(KyteLibrary);
        $libraries->retrieve('include_all', 1, false, [['field' => 'site', 'value' => $page['site']['id']]], false, [['field' => 'id', 'direction' => 'asc']]);
        foreach($libraries->objects as $library) {
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

        // retrieve custom javascripts scripts
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
                    if (!$linked_page->retrieve('id', $m->page)) {
                        throw new \Exception("Unable to find page");
                    }
                    $link = '/'.$linked_page->s3key;
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
}
