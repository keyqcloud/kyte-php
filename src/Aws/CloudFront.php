<?php
namespace Kyte\Aws;

use Aws\Exception\AwsException;
use Aws\CloudFront\CloudFrontClient;

class CloudFront extends Client
{
    private $distributionConfig;

    // distribution configuration properties
    // https://docs.aws.amazon.com/AmazonCloudFront/latest/DeveloperGuide/distribution-overview-required-fields.html
    // ** DistributionConfig
    // **** Required fields
    public $CallerReference;                        // '<string>'
    public $Aliases;                                // ['<string>', ...]
    public $DefaultRootObject;                      // '<string>'
    public $Origins;
    public $OriginGroups;
    // public $CacheBehaviors;
    public $CustomErrorResponses;                   // [ 'ErrorCachingMinTTL' => <integer>, 'ErrorCode' => <integer>, 'ResponseCode' => '<string>', 'ResponsePagePath' => '<string>', ]
    public $Comment;                                // '<string>'
    public $LoggingBucket;                          // '<string>'
    public $LoggingEnabled;                         // boolean
    public $LoggingIncludeCookie;                   // boolean
    public $LoggingPrefix;                          // '<string>'
    public $PriceClass;                             // 'PriceClass_100|PriceClass_200|PriceClass_All'
    public $Enabled;                                // boolean
    public $ViewerCertificateACMCertificateArn;     // '<string>'
    public $ViewerCertificate;                      // '<string>'
    public $ViewerCertificateSource;                // 'cloudfront|iam|acm'
    public $ViewerCertificateCloudFrontDefaultCertificate;  // boolean
    public $ViewerCertificateIAMCertificateId;      // '<string>'
    public $ViewerCertificateMinimumProtocolVersion;        // 'SSLv3|TLSv1|TLSv1_2016|TLSv1.1_2016|TLSv1.2_2018|TLSv1.2_2019'
    public $ViewerCertificateSSLSupportMethod;      // 'sni-only|vip|static-ip's
    public $GeoRestriction;                         // ['<string>', ...]
    public $GeoRestrictionRestrictionType;          // 'blacklist|whitelist|none'
    public $WebACLId;                               // '<string>'
    public $HttpVersion;                            // 'http1.1|http2'
    public $IsIPV6Enabled;                          // boolean
    // ** DefaultCacheBehavior
    public $PathPattern;                            // '<string>'
    public $TargetOriginId;                         // string
    public $ForwardedValuesCookies;                 // 'none|whitelist|all'
    public $ForwardedValuesCookiesWhitelistedNames; // ['<string>', ...]
    public $ForwardedValuesHeaders;                 // ['<string>', ...]
    public $ForwardedValuesQueryString;             // boolean
    public $ForwardedValuesQueryStringCacheKeys;    // ['<string>', ...]
    public $TrustedSignersEnabled;                  // boolean
    public $TrustedSigners;                         // ['<string>', ...]
    public $MinTTL;                                 // integer
    public $AllowedMethods;                         // ['<string>', ...]
    public $AllowedCachedMethods;                   // ['<string>', ...]
    public $SmoothStreaming;                        // boolean
    public $DefaultTTL;                             // integer
    public $MaxTTL;                                 // integer
    public $Compress;                               // boolean
    public $FunctionAssociations;                   // [ 'EventType' => 'viewer-request|viewer-response|origin-request|origin-response', 'FunctionARN' => '<string>', ]
    public $LambdaFunctionAssociations;             // [ 'EventType' => 'viewer-request|viewer-response|origin-request|origin-response', 'IncludeBody' => true || false, 'LambdaFunctionARN' => '<string>', ]
    public $FieldLevelEncryptionId;                 // string
    public $CachePolicyId;                          // string
    public $OriginRequestPolicyId;                  // '<string>'
    public $RealtimeLogConfigArn;                   // '<string>'
    public $TrustedKeyGroupsEnabled;                // boolean
    public $TrustedKeyGroups;                       // ['<string>', ...]
    public $ViewerProtocolPolicy;                   // 'allow-all|https-only|redirect-to-https'

    public function __construct($credentials, $distributionId = null) {
        $this->credentials = $credentials;
        $this->Id = $distributionId;
        $this->client = new CloudFrontClient([
            'credentials'	=> $this->credentials->getCredentials(),
            'version'	=> '2020-05-31',
            'region'	=> $this->credentials->getRegion()
        ]);

        // set default values
    }

    public function create() {
        try {
            // generate CF config
            $this->distributionConfig = $this->generateNewConfiguration();
            $result = $this->client->createDistribution([
                $this->distributionConfig
            ]);

            $this->Id = $result['Distribution']['Id'];
            $this->Arn = $result['Distribution']['ARN'];
    
            return $result;
        } catch (AwsException $e) {
            return 'Error: ' . $e['message'];
        }
    }

    public function get($distributionId = null) {
        try {
            $distributionId = $this->Id ? $this->Id : $distributionId;

            $result = $this->client->getDistribution([
                'Id' => $distributionId
            ]);

            return $result;
        } catch (AwsException $e) {
            return 'Error: ' . $e['message'];
        }
    }

    public function list() {
        try {
            $result = $this->client->listDistributions([]);
            return $result;
        } catch (AwsException $e) {
            exit('Error: ' . $e->getAwsErrorMessage());
        }
    }

    public function disable($distributionId = null) {
        try {
            $distributionId = $this->Id ? $this->Id : $distributionId;

            $this->distributionConfig['DistributionConfig']['Enabled'] = false;

            $result = $this->client->updateDistribution([
                $this->distributionConfig,
                'Id' => $distributionId
            ]);

            return $result;
        } catch (AwsException $e) {
            return 'Error: ' . $e->getAwsErrorMessage();
        }
    }

    public function enable($distributionId = null) {
        try {
            $distributionId = $this->Id ? $this->Id : $distributionId;

            $this->distributionConfig['DistributionConfig']['Enabled'] = true;

            $result = $this->client->updateDistribution([
                $this->distributionConfig,
                'Id' => $distributionId
            ]);
            
            return $result;
        } catch (AwsException $e) {
            return 'Error: ' . $e->getAwsErrorMessage();
        }
    }

    /** consider using Async methods */
    // https://stackoverflow.com/questions/49127996/unable-to-cloudfront-create-invalidation-aws-php-sdk-3-x
    public function createInvalidation($distributionId = null, $paths = ['/*']) {
        $distributionId = $this->Id ? $this->Id : $distributionId;

        $result = $this->client->createInvalidation([
            'DistributionId'    => $distributionId,
            'InvalidationBatch' => [
                'CallerReference'   => time().$distributionId,
                'Paths'             => [
                    'Items'     => $paths,
                    'Quantity'  => count($paths),
                ],
            ],
        ]);
    }

    public function setAliases($distributionId = null, $aliases, $acmArn) {
        try {
            if (count($aliases) < 1) {
                throw new \Exception("At least one aliase (CNAME) must be defined");
            }
            $distributionId = $this->Id ? $this->Id : $distributionId;

            $this->distributionConfig['DistributionConfig']['Aliases']['Items'] = $aliases;
            $this->distributionConfig['DistributionConfig']['Aliases']['Quantity'] = count($aliases);
            $this->distributionConfig['DistributionConfig']['ACMCertificateArn'] = $acmArn;
            $this->distributionConfig['DistributionConfig']['CloudFrontDefaultCertificate'] = false;

            $result = $this->client->updateDistribution([
                $this->distributionConfig,
                'Id' => $distributionId
            ]);
            
            return $result;
        } catch (AwsException $e) {
            return 'Error: ' . $e->getAwsErrorMessage();
        }
    }

    // removes Acm certificate and associated Aliases
    public function removeAliases($distributionId = null) {
        try {
            $distributionId = $this->Id ? $this->Id : $distributionId;

            $this->distributionConfig['DistributionConfig']['ACMCertificateArn'] = '';
            $this->distributionConfig['DistributionConfig']['CloudFrontDefaultCertificate'] = true;
            $this->distributionConfig['DistributionConfig']['Aliases']['Items'] = [];
            $this->distributionConfig['DistributionConfig']['Aliases']['Quantity'] = 0;

            $result = $this->client->updateDistribution([
                $this->distributionConfig,
                'Id' => $distributionId
            ]);
            
            return $result;
        } catch (AwsException $e) {
            return 'Error: ' . $e->getAwsErrorMessage();
        }
    }

    public function delete($distributionId = null) {
        try {
            $distributionId = $this->Id ? $this->Id : $distributionId;

            $result = $this->client->deleteDistribution([
                'Id' => $distributionId
            ]);

            $this->Id = null;
            $this->Arn = null;

            return $result;
        } catch (AwsException $e) {
            return 'Error: ' . $e->getAwsErrorMessage();
        }
    }

    public function getConfiguration($distributionId = null) {
        $result = $this->get($distributionId);

        if (isset($result['Distribution']['DistributionConfig']))
        {
            $this->distributionConfig = $result['Distribution']['DistributionConfig'];
        } else {
            throw new \Exception("Unable to retrieve configuration for distribution $distributionId");
        }
    }

    public function addOrigin(
        $ConnectionAttempts,            // integer
        $ConnectionTimeout,             // integer
        $CustomHeaders,                 // [ 'HeaderName' => '<string>', 'HeaderValue' => '<string>' ]
        $HTTPPort,                      // integer
        $HTTPSPort,                     // integer
        $OriginKeepaliveTimeout,        // integer
        $OriginProtocolPolicy,          // 'http-only|match-viewer|https-only'
        $OriginReadTimeout,             // integer
        $OriginSslProtocols,            // ['<string>', ...]
        $DomainName,                    // '<string>'
        $Id,                            // '<string>'
        $OriginPath,                    // '<string>'
        $OriginShieldEnabled,           // boolean
        $OriginShieldRegion,            // '<string>'
        $S3OriginAccessIdentity         // '<string>'
        ) {
        $this->Origins[] = [
            'ConnectionAttempts' => $ConnectionAttempts, 
            'ConnectionTimeout' => $ConnectionTimeout, 
            'CustomHeaders' => [
                'Items' => [
                    $CustomHeaders,
                ],
                'Quantity' => count($CustomHeaders), // REQUIRED
            ],
            'CustomOriginConfig' => [
                'HTTPPort' => $HTTPPort, // REQUIRED
                'HTTPSPort' => $HTTPSPort, // REQUIRED
                'OriginKeepaliveTimeout' => $OriginKeepaliveTimeout,
                'OriginProtocolPolicy' => $OriginProtocolPolicy, // REQUIRED
                'OriginReadTimeout' => $OriginReadTimeout,
                'OriginSslProtocols' => [
                    'Items' => $OriginSslProtocols, // REQUIRED
                    'Quantity' => count($OriginSslProtocols), // REQUIRED
                ],
            ],
            'DomainName' => $DomainName, // REQUIRED
            'Id' => $Id, // REQUIRED
            'OriginPath' => $OriginPath,
            'OriginShield' => [
                'Enabled' => $OriginShieldEnabled, // REQUIRED
                'OriginShieldRegion' => $OriginShieldRegion,
            ],
            'S3OriginConfig' => [
                'OriginAccessIdentity' => $S3OriginAccessIdentity, // REQUIRED
            ],
        ];
    }

    public function clearOrigins() {
        $this->Origins = [];
    }

    public function addOriginGroup(
        $FailoverCriteriaStatusCodes,
        $Id,                    // '<string>'
        $Members                // [ 'OriginId' => '<string>' ]
    ) {
        $this->OriginGroups[] = [
            'FailoverCriteria' => [ // REQUIRED
                'StatusCodes' => [ // REQUIRED
                    'Items' => $FailoverCriteriaStatusCodes, // REQUIRED
                    'Quantity' => count($FailoverCriteriaStatusCodes), // REQUIRED
                ],
            ],
            'Id' => $Id, // REQUIRED
            'Members' => [ // REQUIRED
                'Items' => [ // REQUIRED
                    $Members
                ],
                'Quantity' => count($Members), // REQUIRED
            ],
        ];
    }

    public function clearOriginGroups() {
        $this->OriginGroups = [];
    }

    // https://docs.aws.amazon.com/aws-sdk-php/v3/api/api-cloudfront-2020-05-31.html#createdistribution
    //
    // OriginGroup and CacheBehavior has been omitted
    private function generateNewConfiguration() {
        if (count($this->Origins) < 1) {
            throw new \Exception("At least one origin must be specified");
        }

        return [
            'DistributionConfig' => [ // REQUIRED
                'Aliases' => [
                    'Items' => $this->Aliases,
                    'Quantity' => count($this->Aliases), // REQUIRED
                ],
                'CallerReference' => $this->CallerReference, // REQUIRED
                'Comment' => $this->Comment, // REQUIRED
                'CustomErrorResponses' => [
                    'Items' => [
                        $this->CustomErrorResponses
                    ],
                    'Quantity' => count($this->CustomErrorResponses), // REQUIRED
                ],
                'DefaultCacheBehavior' => [ // REQUIRED
                    'AllowedMethods' => [
                        'CachedMethods' => [
                            'Items' => $this->AllowedCachedMethods, // REQUIRED
                            'Quantity' => count($this->AllowedCachedMethods), // REQUIRED
                        ],
                        'Items' => $this->AllowedMethods, // REQUIRED
                        'Quantity' => count($this->AllowedMethods), // REQUIRED
                    ],
                    'CachePolicyId' => $this->CachePolicyId,
                    'Compress' => $this->Compress,
                    'DefaultTTL' => $this->DefaultTTL,
                    'FieldLevelEncryptionId' => $this->FieldLevelEncryptionId,
                    'ForwardedValues' => [
                        'Cookies' => [ // REQUIRED
                            'Forward' => $this->ForwardedValuesCookies, // REQUIRED
                            'WhitelistedNames' => [
                                'Items' => $this->ForwardedValuesCookiesWhitelistedNames,
                                'Quantity' => count($this->ForwardedValuesCookiesWhitelistedNames), // REQUIRED
                            ],
                        ],
                        'Headers' => [
                            'Items' => $this->ForwardedValuesHeaders,
                            'Quantity' => count($this->ForwardedValuesHeaders), // REQUIRED
                        ],
                        'QueryString' => $this->ForwardedValuesQueryString, // REQUIRED
                        'QueryStringCacheKeys' => [
                            'Items' => $this->ForwardedValuesQueryStringCacheKeys,
                            'Quantity' => count($this->ForwardedValuesQueryStringCacheKeys), // REQUIRED
                        ],
                    ],
                    'FunctionAssociations' => [
                        'Items' => [
                            $this->FunctionAssociations
                        ],
                        'Quantity' => count($this->FunctionAssociations), // REQUIRED
                    ],
                    'LambdaFunctionAssociations' => [
                        'Items' => [
                            $this->LambdaFunctionAssociations
                        ],
                        'Quantity' => count($this->LambdaFunctionAssociations), // REQUIRED
                    ],
                    'MaxTTL' => $this->MaxTTL,
                    'MinTTL' => $this->MinTTL,
                    'OriginRequestPolicyId' => $this->OriginRequestPolicyId,
                    'RealtimeLogConfigArn' => $this->RealtimeLogConfigArn,
                    'SmoothStreaming' => $this->SmoothStreaming,
                    'TargetOriginId' => $this->TargetOriginId, // REQUIRED
                    'TrustedKeyGroups' => [
                        'Enabled' => $this->TrustedKeyGroupsEnabled, // REQUIRED
                        'Items' => $this->TrustedKeyGroups,
                        'Quantity' => count($this->TrustedKeyGroups), // REQUIRED
                    ],
                    'TrustedSigners' => [
                        'Enabled' => $this->TrustedSignersEnabled, // REQUIRED
                        'Items' => $this->TrustedSigners,
                        'Quantity' => count($this->TrustedSigners), // REQUIRED
                    ],
                    'ViewerProtocolPolicy' => $this->ViewerProtocolPolicy, // REQUIRED
                ],
                'DefaultRootObject' => $this->DefaultRootObject,
                'Enabled' => $this->Enabled, // REQUIRED
                'HttpVersion' => $this->HttpVersion,
                'IsIPV6Enabled' => $this->IsIPV6Enabled,
                'Logging' => [
                    'Bucket' => $this->LoggingBucket, // REQUIRED
                    'Enabled' => $this->LoggingEnabled, // REQUIRED
                    'IncludeCookies' => $this->LoggingIncludeCookie, // REQUIRED
                    'Prefix' => $this->LoggingPrefix, // REQUIRED
                ],
                'OriginGroups' => [
                    'Items' => [
                        $this->OriginGroups
                    ],
                    'Quantity' => count($this->OriginGroups), // REQUIRED
                ],
                'Origins' => [ // REQUIRED
                    'Items' => [ // REQUIRED
                        $this->Origins
                    ],
                    'Quantity' => count($this->Origins), // REQUIRED
                ],
                'PriceClass' => $this->PriceClass,
                'Restrictions' => [
                    'GeoRestriction' => [ // REQUIRED
                        'Items' => $this->GeoRestriction,
                        'Quantity' => count($this->GeoRestriction), // REQUIRED
                        'RestrictionType' => $this->GeoRestrictionRestrictionType, // REQUIRED
                    ],
                ],
                'ViewerCertificate' => [
                    'ACMCertificateArn' => $this->ViewerCertificateACMCertificateArn,
                    'Certificate' => $this->ViewerCertificate,
                    'CertificateSource' => $this->ViewerCertificateSource,
                    'CloudFrontDefaultCertificate' => $this->ViewerCertificateCloudFrontDefaultCertificate,
                    'IAMCertificateId' => $this->ViewerCertificateIAMCertificateId,
                    'MinimumProtocolVersion' => $this->ViewerCertificateMinimumProtocolVersion,
                    'SSLSupportMethod' => $this->ViewerCertificateSSLSupportMethod,
                ],
                'WebACLId' => $this->WebACLId,
            ],
        ];
    }
}
?>
