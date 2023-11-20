<?php

namespace Kyte\Util;

class Logger {
    private $credentials;
    private $bucket;
    private $region;
    private $s3;
    private $app;

    public function __construct($app = null, $bucket = null, $region = 'us-east-1', $accessKey = null, $secretKey = null) {
        try {
            // check to make sure valid sender information is provided, either as a paramter or in the configs
            if ($app == null && $accessKey == null && $secretKey == null) {
                    throw new \Exception('Application object or AWS API keys must be provided.');
            }
            if ($app == null && ($accessKey == null || $secretKey == null)) {
                throw new \Exception('AWS API keys must be provided.');
            }

            $this->app = $app;
            $this->accessKey = $accessKey;
            $this->secretKey = $secretKey;
            $this->bucket = $bucket;
            $this->region = $region;
            if ($this->app !== null) {
                $this->accessKey = $app->aws_public_key;
                $this->secretKey = $app->aws_private_key;
                $this->bucket = $app->s3LogBucketName;
                $this->region = $app->s3LogBucketRegion;
            }
            $this->credentials = new \Kyte\Aws\Credentials($this->region, $this->accessKey, $this->secretKey);
            $this->s3 = new \Kyte\Aws\S3($this->credentials, $this->bucket);
        } catch (\Exception $e) {
			throw "Unable to create credential for ".$this->accessKey.'. [ERROR] '.$e;
		}
    }

    public function log($level, $message) {
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $caller = $backtrace[1];

        $logData = [
            date('Y-m-d H:i:s'),    // time stamp
            "[$level] $message",    // level and message
            "File: {$caller['file']} (line {$caller['line']})".(isset($caller['class']) ? " Class: {$caller['class']}" : ''),    // details
        ];
        $logString = implode("\t", $logData) . "\n";

        // output to s3
        $this->s3->append(date('Y-m-d').'.log', $logString);

        // TODO: integrate sns topic
    }

    public function error($message) {
        $this->log('ERROR', $message);
    }

    public function info($message) {
        $this->log('INFO', $message);
    }

    public function debug($message) {
        $this->log('DEBUG', $message);
    }

    public function warning($message) {
        $this->log('WARNING', $message);
    }

    public function security($message) {
        $this->log('SECURITY', $message);
    }

    public function alert($message) {
        $this->log('ALERT', $message);
    }

    public function ses($message) {
        $this->log('SES', $message);
    }

    function systemErrorHandler($errno, $errstr, $errfile, $errline) {
        try {
            $this->log($this->mapErrorCode($errno), "$errstr in $errfile at line $errline");
        } catch (\Exception $e) {
            return false;
        }

        return true;
    }

    private function mapErrorCode($errno)
    {
        $errorMap = [
            E_ERROR             => 'PHP Error',
            E_WARNING           => 'PHP Warning',
            E_PARSE             => 'PHP Parse Error',
            E_NOTICE            => 'PHP Notice',
            E_CORE_ERROR        => 'PHP Core Error',
            E_CORE_WARNING      => 'PHP Core Warning',
            E_COMPILE_ERROR     => 'PHP Compile Error',
            E_COMPILE_WARNING   => 'PHP Compile Warning',
            E_USER_ERROR        => 'PHP User Error',
            E_USER_WARNING      => 'PHP User Warning',
            E_USER_NOTICE       => 'PHP User Notice',
            E_STRICT            => 'PHP Runtime Notice',
            E_RECOVERABLE_ERROR => 'PHP Catchable Fatal Error',
            E_DEPRECATED        => 'PHP Deprecated Warning',
            E_USER_DEPRECATED   => 'PHP User Deprecated Warning',
        ];

        return isset($errorMap[$errno]) ? $errorMap[$errno] : "PHP Unknown error ({$errno})";
    }
}