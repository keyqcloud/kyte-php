<?php

namespace Kyte\Mvc\Controller;

class PageController extends ModelController
{
    // public function hook_init() {}
    // public function hook_auth() {}

    // public function hook_prequery($method, &$field, &$value, &$conditions, &$all, &$order) {}

    public function hook_preprocess($method, &$r, &$o = null) {
        switch ($method) {
            case 'new':
                $r['s3key'] = $bucketName = strtolower(preg_replace('/[^A-Za-z0-9_.-\/]/', '-', $r['s3key']));
                break;

            default:
                break;
        }
    }

    public function hook_response_data($method, $o, &$r = null, &$d = null) {
        switch ($method) {
            case 'get':
                // get app identifier
                // $app = new \Kyte\Core\ModelObject(Application);
                // if (!$app->retrieve('id', $r['site']['application']['id'])) {
                //     throw new \Exception("CRITICAL ERROR: Unable to find application.");
                // }

                $r['api_endpoint'] = API_URL;
                $r['application_identifier'] = $r['site']['application']['identifier'];
                break;

            case 'update':
                if ($o->state == 1 && !isset($d['state'])) {
                    $o->save(['state' => 2]);
                }
                if (isset($d['state']) && $d['state'] == 1) {
                    // publish file to s3
                    $credential = new \Kyte\Aws\Credentials($r['site']['region']);
                    $s3 = new \Kyte\Aws\S3($credential, $r['site']['s3BucketName']);

                    // compile html file
                    $data = self::createHtml($r);
                    // write to file
                    $s3->write($o->s3key, $data);

                    // create or update sitemap
                    $sitemap = self::updateSitemap($r['site']['id'], $r['site']['aliasDomain'] ? $r['site']['aliasDomain'] : $r['site']['cfDomain']);
                    $s3->write('sitemap.xml', $sitemap);

                    // invalidate CF
                    $cf = new \Kyte\Aws\CloudFront($credential);
                    $invalidationPaths = ['/sitemap.xml'];
                    if (strpos($o->s3key, "index.html") !== false) {
                        $invalidationPaths[] = '/'.str_replace("index.html", "*", $o->s3key);
                    } else {
                        $invalidationPaths[] = '/'.$o->s3key;
                    }
                    $cf->createInvalidation($r['site']['cfDistributionId'], $invalidationPaths);
                }
                break;

            case 'delete':
                // check if s3 file exists and delete
                if ($o->state > 0) {
                    // delete file
                    $d = $this->getObject($o);
                    $credential = new \Kyte\Aws\Credentials($d['site']['region']);
                    $s3 = new \Kyte\Aws\S3($credential, $d['site']['s3BucketName']);
                    if (!empty($o->s3key)) {
                        // delete s3 file
                        $s3->unlink($o->s3key);

                        // create or update sitemap
                        $sitemap = self::updateSitemap($d['site']['id'], $d['site']['aliasDomain'] ? $d['site']['aliasDomain'] : $d['site']['cfDomain']);
                        $s3->write('sitemap.xml', $sitemap);

                        // invalidate CF
                        $cf = new \Kyte\Aws\CloudFront($credential);
                        $cf->createInvalidation($d['site']['cfDistributionId'], ['/*']);
                    }
                }

                break;
            
            default:
                break;
        }
    }

    // public function hook_process_get_response(&$r) {}

    public static function createHtml($page) {
        $gtm_body_script = '';  // empty gtm body script...if gtm code is present, will be populates with gtm script

        $code = '<!DOCTYPE html><html><head>';
        
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
        $code .= '<link rel="stylesheet" href="https://use.fontawesome.com/releases/v5.12.0/css/all.css">';
        // ionicons
        $code .= '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/ionicons/2.0.1/css/ionicons.min.css">';

        // JQuery
        $code .= '<script src="https://code.jquery.com/jquery-3.5.1.min.js" integrity="sha256-9/aliU8dGd2tb6OSsuzixeV4y/faTqgFtohetphbbj0=" crossorigin="anonymous"></script>';

        // JQueryUI
        $code .= '<link rel="stylesheet" href="https://code.jquery.com/ui/1.13.0/themes/base/jquery-ui.css">';
        $code .= '<script src="https://code.jquery.com/ui/1.13.0/jquery-ui.min.js" integrity="sha256-hlKLmzaRlE8SCJC1Kw8zoUbU8BxA+8kR3gseuKfMjxA=" crossorigin="anonymous"></script>';

        // bootstrap
        $code .= '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-EVSTQN3/azprG1Anm3QDgpJLIm9Nao0Yz1ztcQTwFspd3yD65VohhpuuCOmLASjC" crossorigin="anonymous">';
        $code .= '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-MrcW6ZMFYlzcLA8Nl+NtUVF0sA7MsXsP1UyJoMp4YLEuNSfAP+JcXn/tWtIaxVXM" crossorigin="anonymous"></script>';
        
        // datatables
        $code .= '<link rel="stylesheet" href="https://cdn.datatables.net/1.10.23/css/jquery.dataTables.min.css">';
        $code .= '<script src="https://cdn.datatables.net/1.10.23/js/jquery.dataTables.min.js"></script>';

        // check if page is using editor.js, in which case we need to load the modules
        if ($page['page_type'] == 'block') {
            $code .= '<script src="https://cdn.jsdelivr.net/npm/@editorjs/editorjs@latest"></script>';
            $code .= '<script src="https://cdn.jsdelivr.net/npm/@editorjs/header@latest"></script>';
            $code .= '<script src="https://cdn.jsdelivr.net/npm/@editorjs/link@latest"></script>';
            $code .= '<script src="https://cdn.jsdelivr.net/npm/@editorjs/raw"></script>';
            $code .= '<script src="https://cdn.jsdelivr.net/npm/@editorjs/simple-image@latest"></script>';
            $code .= '<script src="https://cdn.jsdelivr.net/npm/@editorjs/checklist@latest"></script>';
            $code .= '<script src="https://cdn.jsdelivr.net/npm/@editorjs/list@latest"></script>';
            $code .= '<script src="https://cdn.jsdelivr.net/npm/@editorjs/embed@latest"></script>';
            $code .= '<script src="https://cdn.jsdelivr.net/npm/@editorjs/quote@latest"></script>';
            $code .= '<script src="https://cdn.jsdelivr.net/npm/editorjs-alert@latest"></script>';
            $code .= '<script src="https://cdn.jsdelivr.net/npm/@editorjs/table@latest"></script>';
            $code .= '<script src="https://cdn.jsdelivr.net/npm/editorjs-button@latest"></script>';
            $code .= '<script src="https://cdn.jsdelivr.net/npm/@editorjs/delimiter@latest"></script>';
            $code .= '<script src="https://cdn.jsdelivr.net/npm/@editorjs/warning@latest"></script>';
            $code .= '<script src="https://cdn.jsdelivr.net/npm/editorjs-undo@latest"></script>';
            $code .= '<script src="https://cdn.jsdelivr.net/npm/@editorjs/paragraph@latest"></script>';
            $code .= '<script src="https://cdn.jsdelivr.net/npm/editorjs-style@latest"></script>';
            $code .= '<script src="https://cdn.jsdelivr.net/npm/editorjs-text-color-plugin@2.0.2/dist/bundle.js"></script>';
            $code .= '<script src="https://cdn.jsdelivr.net/npm/editorjs-tooltip@latest"></script>';
            $code .= '<script src="https://cdn.jsdelivr.net/npm/@sotaproject/strikethrough@latest"></script>';
            $code .= '<script src="https://cdn.jsdelivr.net/npm/editorjs-text-alignment-blocktune@latest"></script>';
            $code .= '<script src="https://cdn.jsdelivr.net/npm/@editorjs/text-variant-tune@latest"></script>';
            $code .= '<script src="https://cdn.jsdelivr.net/npm/@calumk/editorjs-columns@latest"></script>';
            $code .= '<script src="https://cdn.jsdelivr.net/npm/@calumk/editorjs-paragraph-linebreakable"></script>';
        }

        // KyteJS
        $code .= '<script src="https://cdn.stratis-troika.com/kytejs/dev/kyte.js" crossorigin="anonymous"></script>';

        // custom styles
        $code .= '<style>'.$page['stylesheet'].($page['side_navigation'] ? ' main {display: flex;flex-wrap: nowrap;min-height: 100vh;min-height: --webkit-fill-available;overflow-x: auto;overflow-y: hidden;}' : '').'</style>';

        // navigation styles
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

        // main navigation and header
        if ($page['main_navigation']) {
            $code .= '<!-- START NAV --><nav id="mainnav" class="navbar navbar-dark bg-dark navbar-expand-lg"></nav><!-- END NAV -->';
        }

        // main wrapper
        $code .= '<main>';

        // side navigation
        if ($page['side_navigation']) {
            $code .= '<!-- BEGIN SIDE NAVIGATION --><div id="sidenav-wrapper" class="d-flex flex-column flex-shrink-0 py-3"><div id="sidenav" class="p-3" style="width: 230px;"></div></div><!-- END SIDE NAVIGATION -->';
        }

        if ($page['page_type'] == 'block') {
            $code .= '<div id="blockEditorROViewer" class="container container-flex"></div>';
        } else {
            $code .= '<div class="container container-flex">'.$page['html'].'</div>';
        }

        // close main wrapper
        $code .= '</main>';


        // add footer

        // close page wrapper
        $code .= '</div>';

        // begin javascript
        $code .= '<script>';
        // check if page is a block (i.e. editorjs and load viewer)
        if ($page['page_type'] == 'block') {
            //blockEditorROViewer
            $code .= 'let element_tools={header:{class:Header},delimiter:Delimiter,table:Table,linkTool:{class:LinkTool},rawTool:{class:RawTool},imageTool:{class:SimpleImage},checklistTool:{class:Checklist},listTool:{class:List},warning:{class:Warning},AnyButton:{class:AnyButton},embedTool:{class:Embed},quoteTool:{class:Quote},tooltip:{class:Tooltip}};const blockEditor=new EditorJS({holder:"blockEditorROViewer",tools:element_tools,autofocus:true,readOnly:true, data:'.$page['block_layout'].'});';
        }

        // add kyte connect
        $code .= $page['kyte_connect']."\n\n";
        // custom js
        $code .= '$(document).ready(function() { ';
        if ($page['protected'] == 1) {
            $code .= 'k.addLogoutHandler("#logout");'."\n";
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
                    $linked_page = new \Kyte\Core\ModelObject(Page);
                    if (!$linked_page->retrieve('id', $m->page)) {
                        throw new \Exception("Unable to find page");
                    }
                    $link = '/'.$linked_page->s3key;
                }
                if ($m->center == 1) {
                    if ($m->parentItem) {
                        $menu_items_center_sub[$m->parentItem][] = '{'.($m->isLogout == 1 ? 'logout:true,':'').'faicon:"'.$m->faicon.'",class:"me-2 text-dark",label:"'.$m->title.'",href:"'.$link.'"},';
                    } else {
                        $menu_items_center[$m->id] = '{'.($m->isLogout == 1 ? 'logout:true,':'').'faicon:"'.$m->faicon.'",class:"me-2 text-light",label:"'.$m->title.'",href:"'.$link.'"},';
                    }
                } else {
                    if ($m->parentItem) {
                        $menu_items_right_sub[$m->parentItem][] = '{'.($m->isLogout == 1 ? 'logout:true,':'').'faicon:"'.$m->faicon.'",class:"me-2 text-dark",label:"'.$m->title.'",href:"'.$link.'"},';
                    } else {
                        $menu_items_right[$m->id] = '{'.($m->isLogout == 1 ? 'logout:true,':'').'faicon:"'.$m->faicon.'",class:"me-2 text-light",label:"'.$m->title.'",href:"'.$link.'"},';
                    }
                }
            }
            foreach(array_keys($menu_items_center_sub) as $key) {
                $menu_items_center[$key] = '{dropdown:true,class:"me-2 text-light",label:"'.$menu_items[$key]->title.'",items:['.implode($menu_items_center_sub[$key]).']},';
            }
            foreach(array_keys($menu_items_right_sub) as $key) {
                $menu_items_right[$key] = '{dropdown:true,class:"me-2 text-light",label:"'.$menu_items[$key]->title.'",items:['.implode($menu_items_right_sub[$key]).']},';
            }
            $nav_link = $page['main_navigation']['link'] ? $page['main_navigation']['link'] : '/';
            if ($page['main_navigation']['page']) {
                $linked_page = new \Kyte\Core\ModelObject(Page);
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
                    $linked_page = new \Kyte\Core\ModelObject(Page);
                    if (!$linked_page->retrieve('id', $m->page)) {
                        throw new \Exception("Unable to find page");
                    }
                    $link = '/'.$linked_page->s3key;
                }
                $side_menu_items[] = '{faicon:"'.$m->faicon.'",label:"'.$m->title.'",'.(isset($link[0]) && $link[0] == '#' ? 'selector:"'.$link.'"' : 'href:"'.$link.'"').'},';
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
        $pages = new \Kyte\Core\Model(Page);
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
