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
                    $data = self::createHtml($r, $d['kyte_connect']);
                    // write to file
                    $s3->write($o->s3key, $data);

                    // invalidate CF
                    $cf = new \Kyte\Aws\CloudFront($credential);
                    $invalidationPaths = [];
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
                        $site = new \Kyte\Core\ModelObject(Site);
                        if (!$site->retrieve('id', $o->site)) {
                            throw new \Exception("Unable to delete page due to site information missing...");
                        }

                        $s3->unlink($o->s3key);

                        // invalidate CF
                        $cf = new \Kyte\Aws\CloudFront($credential);
                        $cf->createInvalidation($site->cfDistributionId, ['/*']);
                    }
                }

                break;
            
            default:
                break;
        }
    }

    // public function hook_process_get_response(&$r) {}

    public static function createHtml($page, $kyte_connect) {
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
        // begin javascript
        $code .= '<script>';
        // check if page is a block (i.e. editorjs and load viewer)
        if ($page['page_type'] == 'block') {
            //blockEditorROViewer
            $code .= 'let element_tools={style:EditorJSStyle.StyleInlineTool,Color:{class:window.ColorPlugin,config:{colorCollections:["#EC7878","#9C27B0","#673AB7","#3F51B5","#0070FF","#03A9F4","#00BCD4","#4CAF50","#8BC34A","#CDDC39","#FFF"],defaultColor:"#FF1300",type:"text",customPicker:!0}},Marker:{class:window.ColorPlugin,config:{defaultColor:"#FFBF00",type:"marker",icon:\'<svg fill="#000000" height="200px" width="200px" version="1.1" id="Icons" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 32 32" xml:space="preserve"><g id="SVGRepo_bgCarrier" stroke-width="0"></g><g id="SVGRepo_tracerCarrier" stroke-linecap="round" stroke-linejoin="round"></g><g id="SVGRepo_iconCarrier"> <g> <path d="M17.6,6L6.9,16.7c-0.2,0.2-0.3,0.4-0.3,0.6L6,23.9c0,0.3,0.1,0.6,0.3,0.8C6.5,24.9,6.7,25,7,25c0,0,0.1,0,0.1,0l6.6-0.6 c0.2,0,0.5-0.1,0.6-0.3L25,13.4L17.6,6z"></path> <path d="M26.4,12l1.4-1.4c1.2-1.2,1.1-3.1-0.1-4.3l-3-3c-0.6-0.6-1.3-0.9-2.2-0.9c-0.8,0-1.6,0.3-2.2,0.9L19,4.6L26.4,12z"></path> </g> <g> <path d="M28,29H4c-0.6,0-1-0.4-1-1s0.4-1,1-1h24c0.6,0,1,0.4,1,1S28.6,29,28,29z"></path> </g> </g></svg>\'}},paragraph:{class:editorjsParagraphLinebreakable,inlineToolbar:!0,tunes:["alignmentTune","textVariant"]},header:{class:Header,inlineToolbar:!0,tunes:["alignmentTune"]},strikethrough:Strikethrough,delimiter:Delimiter,table:Table,linkTool:{class:LinkTool,inlineToolbar:!0,tunes:["alignmentTune"]},rawTool:{class:RawTool,inlineToolbar:!0},imageTool:{class:SimpleImage,inlineToolbar:!0},checklistTool:{class:Checklist,inlineToolbar:!0,tunes:["alignmentTune"]},listTool:{class:List,inlineToolbar:!0,config:{defaultStyle:"unordered"},tunes:["alignmentTune"]},warning:{class:Warning,tunes:["alignmentTune"]},class:alert,AnyButton:{class:AnyButton,inlineToolbar:!1,config:{css:{btnColor:"btn--gray"}},tunes:["alignmentTune"]},embedTool:{class:Embed,inlineToolbar:!0,tunes:["alignmentTune"]},quoteTool:{class:Quote,inlineToolbar:!0,config:{quotePlaceholder:"Enter a quote",captionPlaceholder:"Quote\'s author"},tunes:["alignmentTune"]},tooltip:{class:Tooltip,config:{location:"left",highlightColor:"#FFEFD5",underline:!0,backgroundColor:"#154360",textColor:"#FDFEFE",holder:"editorId"}},textVariant:TextVariantTune,alignmentTune:{class:AlignmentBlockTune,config:{default:"left",blocks:{header:"center",list:"left"}}}},layout_tool={columns:{class:editorjsColumns,EditorJsLibrary:EditorJS,config:{tools:element_tools}}},main_tool=Object.assign({},layout_tool,element_tools);const blockEditor=new EditorJS({holder:"blockEditorROViewer",tools:main_tool,autofocus:!0,readOnly:!0});';
        }

        // add kyte connect
        $code .= $kyte_connect."\n\n";
        // custom js
        $code .= '$(document).ready(function() { ';
        if ($page['protected'] == 1) {
            $code .= 'k.addLogoutHandler("#logout");'."\n";
            $code .= 'if (k.isSession()) { '."\n";
        }
        $code .= $page['javascript']."\n";
        if ($page['protected'] == 1) {
            $code .= ' } else { location.href="/?redir="+encodeURIComponent(window.location); }';
        }

        // add navigation code
        if ($page['main_navigation']) {
            // retrieve menu items and create array
            $items = new \Kyte\Core\Model(NavigationItem);
            $items->retrieve('navigation', $page['main_navigation']['id'], false, null, false, [['field' => 'id', 'direction' => 'asc']]);
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
            $main_nav = new \Kyte\Core\ModelObject(Navigation);
            if (!$main_nav->retrieve('id', $page['main_navigation']['id'])) {
                throw new \Exception("Unable to find main navigation for id ".$page['main_navigation']['id']);
            }
            $nav_link = $main_nav->link ? $main_nav->link : '/';
            if ($main_nav->page) {
                $linked_page = new \Kyte\Core\ModelObject(Page);
                if ($linked_page->retrieve('id', $main_nav->page)) {
                    $nav_link = '/'.$linked_page->s3key;
                }
            }
            
            $code .= 'let appnavdef = [['.implode($menu_items_center).'],['.implode($menu_items_right).']];';
            $code .= 'let navbar = new KyteNav("#mainnav", appnavdef, "'.$main_nav->logo.'", "'.($main_nav->logo ? '' : $page['site']['name']).'", null, "'.$nav_link.'");navbar.create();';
        }

        // side navigation
        if ($page['side_navigation']) {
            // retrieve menu items and create array
            $items = new \Kyte\Core\Model(SideNavItem);
            $items->retrieve('sidenav', $page['side_navigation']['id'], false, null, false, [['field' => 'id', 'direction' => 'asc']]);
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

        // custom styles
        $code .= '<style>'.$page['stylesheet'].($page['side_navigation'] ? ' main {display: flex;flex-wrap: nowrap;min-height: 100vh;min-height: --webkit-fill-available;overflow-x: auto;overflow-y: hidden;}' : '').'</style>';

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
            $code .= '<!-- BEGIN SIDE NAVIGATION --><div id="sidenav" class="d-flex flex-column flex-shrink-0 p-3" style="width: 230px;"></div><!-- END SIDE NAVIGATION -->';
        }

        if ($page['page_type'] == 'block') {
            $code .= '<div class="container container-flex mb-5 px-5" id="blockEditorROViewer"></div>';
        } else {
            $code .= '<div class="container container-flex mb-5 px-5">'.$page['html'].'</div>';
        }

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
