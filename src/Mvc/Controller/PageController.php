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

                $r['api_endpoint'] = APP_URL;
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
                    $data = $this->createHtml(array_merge($r, $d));
                    // write to file
                    $s3->write($o->s3key, $data);

                    // invalidate CF
                    $cf = new \Kyte\Aws\CloudFront($credential);
                    $invalidationPaths = ['/'.$o->s3key];
                    if ($o->s3key == 'index.html') {
                        $invalidationPaths[] = '/*';
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
                        $s3->unlink($o->s3key);

                        // invalidate CF
                        $cf = new \Kyte\Aws\CloudFront($credential);
                        $cf->createInvalidation($r['site']['cfDistributionId'], ['/'.$o->s3key]);
                    }
                }

                break;
            
            default:
                break;
        }
    }

    // public function hook_process_get_response(&$r) {}

    private function createHtml($page) {
        $code = '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no"><title>'.$page['title'].'</title>';
        
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

        // KyteJS
        $code .= '<script src="https://cdn.stratis-troika.com/kytejs/dev/kyte.js" crossorigin="anonymous"></script>';

        $code .= '<script>';
        // add kyte connect
        $code .= $page['kyte_connect']."\n\n";
        // custom js
        $code .= '$(document).ready(function() { ';
        if ($page['protected'] == 1) {
            $code .= 'if (k.isSession()) { ';
        }
        $code .= $page['javascript'];
        if ($page['protected'] == 1) {
            $code .= ' } else { location.href="/?redir="+encodeURIComponent(window.location); }';
        }

        // add navigation code
        if ($page['main_navigation']) {
            // retrieve menu items and create array
            $items = new \Kyte\Core\Model(NavigationItem);
            $items->retrieve('navigation', $page['main_navigation'], false, null, false, [['field' => 'id', 'direction' => 'asc']]);
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
                        $menu_items_center_sub[$m->parentItem][] = '{faicon:"'.$m->faicon.'",class:"me-2 text-dark",label:"'.$m->title.'",href:"'.$link.'"},';
                    } else {
                        $menu_items_center[$m->id] = '{faicon:"'.$m->faicon.'",class:"me-2 text-light",label:"'.$m->title.'",href:"'.$link.'"},';
                    }
                } else {
                    if ($m->parentItem) {
                        $menu_items_right_sub[$m->parentItem][] = '{faicon:"'.$m->faicon.'",class:"me-2 text-dark",label:"'.$m->title.'",href:"'.$link.'"},';
                    } else {
                        $menu_items_right[$m->id] = '{faicon:"'.$m->faicon.'",class:"me-2 text-light",label:"'.$m->title.'",href:"'.$link.'"},';
                    }
                }
            }
            foreach(array_keys($menu_items_center_sub) as $key) {
                $menu_items_center[$key] = '{dropdown:true,class:"me-2 text-light",label:"'.$menu_items[$key]->title.'",items:['.implode($menu_items_center_sub[$key]).']},';
            }
            foreach(array_keys($menu_items_right_sub) as $key) {
                $menu_items_right[$key] = '{dropdown:true,class:"me-2 text-light",label:"'.$menu_items[$key]->title.'",items:['.implode($menu_items_right_sub[$key]).']},';
            }
            $main_nav = new \Kyte\Core\ModelObject(Navigation);
            if (!$main_nav->retrieve('id', $page['main_navigation'])) {
                throw new \Exception("Unable to find main navigation");
            }
            $nav_link = $main_nav->link ? $main_nav->link : '/';
            if ($main_nav->page) {
                $linked_page = new \Kyte\Core\ModelObject(Page);
                if (!$linked_page->retrieve('id', $main_nav->page)) {
                    throw new \Exception("Unable to find page");
                }
                $nav_link = '/'.$linked_page->s3key;
            }
            
            $code .= 'let appnavdef = [['.implode($menu_items_center).'],['.implode($menu_items_right).']];';
            $code .= 'let navbar = new KyteNav("#mainnav", appnavdef, "'.$main_nav->logo.'", "'.($main_nav->logo ? '' : $page['site']['name']).'", null, "'.$nav_link.'");navbar.create();';
        }

        // side navigation
        if ($page['side_navigation']) {
            // retrieve menu items and create array
            $items = new \Kyte\Core\Model(NavigationItem);
            $items->retrieve('navigation', $page['side_navigation'], false, null, false, [['field' => 'id', 'direction' => 'asc']]);
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
            $code .= 'let sidenav = new KyteSidenav("#sidenav", sidenavdef, "'.$default_sidenav.'");sidenav.create();';//sidenav.bind();';
        }

        $code .= ' });</script>';

        // custom styles
        $code .= '<style>'.$page['stylesheet'].'</style>';

        // close head
        $code .= '</head>';

        // body
        $code .= '<body>';

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
            $code .= '<!-- BEGIN SIDE NAVIGATION --><div id="sidenav" class="d-flex flex-column flex-shrink-0 p-3 text-white" style="width: 230px;"></div><!-- END SIDE NAVIGATION -->';
        }

        $code .= '<div class="container container-flex mb-5 px-5">'.$page['html'].'</div>';

        // close main wrapper
        $code .= '</main>';


        // add footer

        // close page wrapper
        $code .= '</div>';

        // close body
        $code .= '</body>';
        // close html
        $code .= '</html>';

        return $code;
    }
}
