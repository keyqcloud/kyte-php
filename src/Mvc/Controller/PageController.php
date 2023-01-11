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
                $r['s3key'] = $bucketName = strtolower(preg_replace('/[^A-Za-z0-9_.-]/', '-', $r['name']).'-'.$r['s3key']);
                break;

            default:
                break;
        }
    }

    public function hook_response_data($method, $o, &$r = null, &$d = null) {
        switch ($method) {
            case 'get':
                // get app identifier
                $app = new \Kyte\Core\ModelObject(Application);
                if (!$app->retrieve('id', $r['site']['application']['id'])) {
                    throw new \Exception("CRITICAL ERROR: Unable to find application.");
                }

                $r['application_identifier'] = $app->identifier;
                break;

            case 'update':
                if ($d['state'] == 1) {
                    // publish file to s3
                    $credential = new \Kyte\Aws\Credentials($r['site']['region']);
                    $s3 = new \Kyte\Aws\S3($credential, $r['site']['s3BucketName']);

                    // compile html file
                    $data = $this->createHtml($o, $d['html'], $d['javascript'], $d['stylesheet'], $d['kyte_connect']);
                    // write to file
                    $s3->write($o->s3key, $data);

                    // invalidate CF
                    $cf = new \Kyte\Aws\CloudFront($credential);
                    $cf->createInvalidation($r['site']['cfDistributionId'], ['/'.$o->s3key]);
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
                    }
                }

                break;
            
            default:
                break;
        }
    }

    // public function hook_process_get_response(&$r) {}

    private function createHtml($page, $html, $js, $style, $kyte_connect) {
        $code = '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no"><title>'.$page->title.'</title>';
        
        // bootstrap
        $code .= '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-EVSTQN3/azprG1Anm3QDgpJLIm9Nao0Yz1ztcQTwFspd3yD65VohhpuuCOmLASjC" crossorigin="anonymous">';
        $code .= '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-MrcW6ZMFYlzcLA8Nl+NtUVF0sA7MsXsP1UyJoMp4YLEuNSfAP+JcXn/tWtIaxVXM" crossorigin="anonymous"></script>';
        
        // font aweseom
        $code .= '<link rel="stylesheet" href="https://use.fontawesome.com/releases/v5.12.0/css/all.css">';
        // ionicons
        $code .= '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/ionicons/2.0.1/css/ionicons.min.css">';
        
        // datatables
        $code .= '<link rel="stylesheet" href="https://cdn.datatables.net/1.10.23/css/jquery.dataTables.min.css">';
        $code .= '<script src="https://cdn.datatables.net/1.10.23/js/jquery.dataTables.min.js"></script>';

        // JQuery
        $code .= '<script src="https://code.jquery.com/jquery-3.5.1.min.js" integrity="sha256-9/aliU8dGd2tb6OSsuzixeV4y/faTqgFtohetphbbj0=" crossorigin="anonymous"></script>';

        // JQueryUI
        $code .= '<link rel="stylesheet" href="https://code.jquery.com/ui/1.13.0/themes/base/jquery-ui.css">';
        $code .= '<script src="https://code.jquery.com/ui/1.13.0/jquery-ui.min.js" integrity="sha256-hlKLmzaRlE8SCJC1Kw8zoUbU8BxA+8kR3gseuKfMjxA=" crossorigin="anonymous"></script>';

        // KyteJS
        $code .= '<script src="https://cdn.stratis-troika.com/kytejs/2.0.0/kyte.js" crossorigin="anonymous"></script>';

        $code .= '<script>';
        // add kyte connect
        $code .= $kyte_connect."\n\n";
        // custom js
        $code .= '$(document).ready(function() { ';
        if ($page->protected == 1) {
            $code .= 'if (k.isSession()) { ';
        }
        $code .= $js;
        if ($page->protected == 1) {
            $code .= ' } else { location.href="/?redir="+encodeURIComponent(window.location); }';
        }
        $code .= ' });</script>';

        // custom styles
        $code .= '<style>'.$style.'</style>';

        // close head
        $code .= '</head>';

        // body
        $code .= '<body>';

        // loader
        $code .= '<!-- Page loading modal.  Once session is validated, the loading modal will close. --><div id="pageLoaderModal" class="modal white" data-backdrop="static" data-keyboard="false" tabindex="-1"><div class="modal-dialog modal-sm h-100 d-flex"><div class="mx-auto align-self-center" style="width: 48px"><div class="spinner-wrapper text-center fa-6x"><span class="fas fa-sync fa-spin"></span></div></div></div></div><!--  -->';

        // wrapper
        $code .= '<div id="wrapper">';

        // main navigation and header
        if ($page->header) {
            $code .= '<!-- START NAV --><nav id="mainnav" class="navbar navbar-dark bg-dark navbar-expand-lg"></nav><!-- END NAV -->';
        }

        // main wrapper
        $code .= '<main>';

        // side navigation
        if ($page->navigation) {
            $code .= '<!-- BEGIN SIDE NAVIGATION --><div id="sidenav" class="d-flex flex-column flex-shrink-0 p-3 text-white" style="width: 230px;"></div><!-- END SIDE NAVIGATION -->';
        }

        $code .= '<div class="container container-flex mb-5 px-5">'.$html.'</div>';

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
