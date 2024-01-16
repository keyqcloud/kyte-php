<?php

namespace Kyte\Mvc\Controller;

class KyteSiteController extends ModelController
{
    // public function hook_init() {}
    // public function hook_auth() {}

    // public function hook_prequery($method, &$field, &$value, &$conditions, &$all, &$order) {}

    public function hook_preprocess($method, &$r, &$o = null) {
        switch ($method) {
            case 'new':
                $r['status'] = 'creating';
                break;

            case 'update':
                if (strlen($r['aliasDomain']) > 0) {
                    $domains = [];
                    $assignedDomain = new \Kyte\Core\ModelObject(Domain);
                    if ($assignedDomain->retrieve('assigned', $o->cfDistributionId)) {
                        $domains[] = $assignedDomain->domainName;
                        // get any SANs
                        $sans = new \Kyte\Core\Model(SubjectAlternativeName);
                        $sans->retrieve('domain', $assignedDomain->id);
                        foreach ($sans->objects as $san) {
                            $domains[] = $san->name;
                        }
                    }
                    $matched = false;
                    // Remove slashes and protocols from the domain
                    $r['aliasDomain'] = preg_replace('#^https?://#', '', $r['aliasDomain']);

                    // Validate domain format
                    $pattern = '/^(\*\.)?([a-z0-9-]+\.)*[a-z0-9-]+$/i';
                    $validFormat = preg_match($pattern, $r['aliasDomain']);
                    if (!$validFormat) {
                        throw new \Exception('Alias is not a valid domain format.');
                    }

                    // check if there is a match
                    foreach ($domains as $domain) {
                        if (fnmatch($domain, $r['aliasDomain'])) {
                            $matched = true;
                            break;
                        }
                    }

                    if (!$matched) {
                        throw new \Exception('Alias domain does not match the assigned SSL domain or subject alternative name.');
                    }
                }
                break;
            
            default:
                break;
        }
    }

    public function hook_response_data($method, $o, &$r = null, &$d = null) {
        switch ($method) {
            case 'new':
                $app = new \Kyte\Core\ModelObject(Application);
                if (!$app->retrieve('id', $d['application'])) {
                    $o->delete();
                    throw new \Exception("CRITICAL ERROR: Unable to find application.");
                }

                // validate provided region
                $validS3Regions = [
                    "us-east-1", "us-east-2", "us-west-1", "us-west-2",
                    "ap-south-1", "ap-northeast-1", "ap-northeast-2", "ap-southeast-1", "ap-southeast-2",
                    "ca-central-1", "eu-central-1", "eu-north-1", "eu-west-1", "eu-west-2", "eu-west-3",
                    "sa-east-1", "cn-north-1", "cn-northwest-1"
                ];
                
                $region = $r['region'];
                $o->save(['region' => $region]);
                
                // if user provided region string is not valid then throw an exception
                if (!in_array($region, $validS3Regions)) {
                    $o->delete();
                    throw new \Exception("Unknown region $region. Please check to make sure you specified a valid region for AWS S3.");
                }

                // create unique names for s3 buckets
                $timestamp = time();
                $bucketName = strtolower(preg_replace('/[^A-Za-z0-9_-]/', '-', $r['name']).'-'.$timestamp);
                $s3MediaBucketName = strtolower(preg_replace('/[^A-Za-z0-9_-]/', '-', $r['name']).'-static-assets-'.$timestamp);

                // TODO: endpoint url changes based on region!
                // see: https://docs.aws.amazon.com/general/latest/gr/s3.html#s3_website_region_endpoints
                $websiteOrigin = self::getWebsiteEndpoint($bucketName, $region);
                // static content origin url
                $staticContentOrigin = $s3MediaBucketName.'.s3.amazonaws.com';

                $credential = new \Kyte\Aws\Credentials(SNS_REGION);
                $sns = new \Kyte\Aws\Sns($credential, SNS_QUEUE_SITE_MANAGEMENT);

                // queue creation of s3 bucket for static web app
                $sns->publish([
                    'action' => 's3_create',
                    'region_name' => $region,
                    'bucket_name' => $bucketName,
                    'cf_origin' => $websiteOrigin,
                    'site_id' => $o->id,
                    'is_website' => true,
                    'is_media' => false,
                ]);

                // queue creation of s3 bucket for static content
                $sns->publish([
                    'action' => 's3_create',
                    'region_name' => $region,
                    'bucket_name' => $s3MediaBucketName,
                    'cf_origin' => $staticContentOrigin,
                    'site_id' => $o->id,
                    'is_website' => false,
                    'is_media' => true,
                ]);
                break;
            
            default:
                break;
        }
    }

    public function delete($field, $value) {
        $o = new \Kyte\Core\ModelObject(KyteSite);
        if (!$o->retrieve($field, $value)) {
            throw new \Exception("Site not found.");
        } else {
            error_log('Site found...deleting...');
        }
        
        // begin the deletion process
        $o->save([
            'status' => 'deleting',
            'deleted_by' => $this->user->id,    // store the user id of who initiated the delete, will add time after via lambda fx
            'date_modified' => time(),
        ]);

        // add s3 static content bucket to deletion queue
        $credential = new \Kyte\Aws\Credentials(SNS_REGION);
        $sns = new \Kyte\Aws\Sns($credential, SNS_QUEUE_SITE_MANAGEMENT);
        $sns->publish([
            'action' => 's3_delete',
            'region_name' => $o->region,
            'bucket_name' => $o->s3MediaBucketName,
            'site_id' => $o->id,
            'cf_id' => $o->cfMediaDistributionId,
        ]);

        // add s3 web app bucket to deletion queue
        $params = [
            'action' => 's3_delete',
            'region_name' => $o->region,
            'bucket_name' => $o->s3BucketName,
            'site_id' => $o->id,
            'cf_id' => $o->cfDistributionId,
        ];
        $sns->publish($params, $o->id);
        
        // delete KytePage
        $objs = new \Kyte\Core\Model(KytePage);
        $objs->retrieve('site', $o->id);
        foreach ($objs->objects as $obj) {
            // delete KytePageData
            $pd = new \Kyte\Core\Model(KytePageData);
            $pd->retrieve('page', $obj->id);
            foreach ($pd->objects as $p) {
                $p->delete();
            }
            $obj->delete();
        }

        // delete Media
        $objs = new \Kyte\Core\Model(Media);
        $objs->retrieve('site', $o->id);
        foreach ($objs->objects as $obj) {
            $obj->delete();
        }

        // delete Navigations
        $objs = new \Kyte\Core\Model(Navigation);
        $objs->retrieve('site', $o->id);
        foreach ($objs->objects as $obj) {
            $obj->delete();
        }
        // delete NavigationItems
        $objs = new \Kyte\Core\Model(NavigationItem);
        $objs->retrieve('site', $o->id);
        foreach ($objs->objects as $obj) {
            $obj->delete();
        }

        // delete Side Navigation
        $objs = new \Kyte\Core\Model(SideNav);
        $objs->retrieve('site', $o->id);
        foreach ($objs->objects as $obj) {
            $obj->delete();
        }
        // delete Side Nav Items
        $objs = new \Kyte\Core\Model(SideNavItem);
        $objs->retrieve('site', $o->id);
        foreach ($objs->objects as $obj) {
            $obj->delete();
        }

        // delete KyteSectionTemplate
        $objs = new \Kyte\Core\Model(KyteSectionTemplate);
        $objs->retrieve('site', $o->id);
        foreach ($objs->objects as $obj) {
            $obj->delete();
        }

        // delete KyteLibrary
        $objs = new \Kyte\Core\Model(KyteLibrary);
        $objs->retrieve('site', $o->id);
        foreach ($objs->objects as $obj) {
            $obj->delete();
        }

        // delete KyteScript
        $objs = new \Kyte\Core\Model(KyteScript);
        $objs->retrieve('site', $o->id);
        foreach ($objs->objects as $obj) {
            $obj->delete();
        }

        // delete Domain and SAN
        $objs = new \Kyte\Core\Model(Domain);
        $objs->retrieve('site', $o->id);
        foreach ($objs->objects as $obj) {
            $sans = new \Kyte\Core\Model(SubjectAlternativeName);
            $sans->retrieve('domain', $obj->id);
            foreach ($sans->objects as $san) {
                $san->delete();
            }
            $params = [
                'action' => 'acm_delete',
                'acm_arn' => $obj->certificateArn,
            ];
            $sns->publish($params, $o->id);
        }
    }

    // public function hook_process_get_response(&$r) {}

    public static function getWebsiteEndpoint($bucketName, $region) {
        switch ($region) {
            case 'us-east-1':
                return $bucketName . ".s3-website-us-east-1.amazonaws.com";
            case 'us-east-2':
                return $bucketName . ".s3-website.us-east-2.amazonaws.com";
            case 'us-west-1':
                return $bucketName . ".s3-website-us-west-1.amazonaws.com";
            case 'us-west-2':
                return $bucketName . ".s3-website-us-west-2.amazonaws.com";
            case 'af-south-1':
                return $bucketName . ".s3-website.af-south-1.amazonaws.com";
            case 'ap-east-1':
                return $bucketName . ".s3-website.ap-east-1.amazonaws.com";
            case 'ap-south-1':
                return $bucketName . ".s3-website.ap-south-1.amazonaws.com";
            case 'ap-southeast-1':
                return $bucketName . ".s3-website-ap-southeast-1.amazonaws.com";
            case 'ap-southeast-2':
                return $bucketName . ".s3-website-ap-southeast-2.amazonaws.com";
            case 'ap-southeast-3':
                return $bucketName . ".s3-website.ap-southeast-3.amazonaws.com";
            case 'ap-southeast-4':
                return $bucketName . ".s3-website.ap-southeast-4.amazonaws.com";
            case 'ap-northeast-1':
                return $bucketName . ".s3-website-ap-northeast-1.amazonaws.com";
            case 'ap-northeast-2':
                return $bucketName . ".s3-website.ap-northeast-2.amazonaws.com";
            case 'ap-northeast-3':
                return $bucketName . ".s3-website.ap-northeast-3.amazonaws.com";
            case 'ca-central-1':
                return $bucketName . ".s3-website.ca-central-1.amazonaws.com";
            case 'cn-north-1':
                return $bucketName . ".s3-website.cn-north-1.amazonaws.com.cn";
            case 'cn-northwest-1':
                return $bucketName . ".s3-website.cn-northwest-1.amazonaws.com.cn";
            case 'eu-central-1':
                return $bucketName . ".s3-website.eu-central-1.amazonaws.com";
            case 'eu-west-1':
                return $bucketName . ".s3-website-eu-west-1.amazonaws.com";
            case 'eu-west-2':
                return $bucketName . ".s3-website.eu-west-2.amazonaws.com";
            case 'eu-west-3':
                return $bucketName . ".s3-website.eu-west-3.amazonaws.com";
            case 'eu-north-1':
                return $bucketName . ".s3-website.eu-north-1.amazonaws.com";
            case 'eu-south-1':
                return $bucketName . ".s3-website.eu-south-1.amazonaws.com";
            case 'eu-south-2':
                return $bucketName . ".s3-website.eu-south-2.amazonaws.com";
            case 'me-south-1':
                return $bucketName . ".s3-website.me-south-1.amazonaws.com";
            case 'me-central-1':
                return $bucketName . ".s3-website.me-central-1.amazonaws.com";
            case 'il-central-1':
                return $bucketName . ".s3-website.il-central-1.amazonaws.com";
            case 'sa-east-1':
                return $bucketName . ".s3-website-sa-east-1.amazonaws.com";
            case 'us-gov-east-1':
                return $bucketName . ".s3-website.us-gov-east-1.amazonaws.com";
            case 'us-gov-west-1':
                return $bucketName . ".s3-website-us-gov-west-1.amazonaws.com";
            default:
                throw new \Exception("Region not found or not supported.");
        }
    }
    
}
