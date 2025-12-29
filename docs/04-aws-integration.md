# AWS Integration Guide

## Table of Contents
1. [Overview](#overview)
2. [AWS Credentials](#aws-credentials)
3. [S3 (Simple Storage Service)](#s3-simple-storage-service)
4. [SES (Simple Email Service)](#ses-simple-email-service)
5. [SNS (Simple Notification Service)](#sns-simple-notification-service)
6. [KMS (Key Management Service)](#kms-key-management-service)
7. [CloudFront](#cloudfront)
8. [Complete Examples](#complete-examples)
9. [Best Practices](#best-practices)

---

## Overview

Kyte-PHP provides convenient wrapper classes for common AWS services. These wrappers simplify the AWS SDK and provide a consistent interface for cloud operations.

### Available AWS Services

| Service | Class | Purpose |
|---------|-------|---------|
| S3 | `\Kyte\Aws\S3` | File storage, static websites |
| SES | `\Kyte\Aws\Ses` | Email sending |
| SNS | `\Kyte\Aws\Sns` | Notifications, pub/sub |
| KMS | `\Kyte\Aws\Kms` | Encryption key management |
| CloudFront | `\Kyte\Aws\CloudFront` | CDN distribution |
| ACM | `\Kyte\Aws\Acm` | SSL certificate management |

### Why Use AWS Wrappers?

The Kyte AWS wrappers:
- Simplify common operations
- Handle credential management
- Provide consistent error handling
- Reduce boilerplate code
- Integrate seamlessly with Kyte models

---

## AWS Credentials

Before using any AWS service, you need to set up credentials.

### Configuration

Set these constants in your configuration file:

```php
// AWS Access Keys
define('AWS_ACCESS_KEY_ID', 'your-access-key-id');
define('AWS_SECRET_KEY', 'your-secret-access-key');

// Optional: KMS Key for encryption
define('AWS_KMS_KEYID', 'your-kms-key-id');

// Optional: SES region
define('APP_SES_REGION', 'us-east-1');
```

### Creating Credentials

The `\Kyte\Aws\Credentials` class manages AWS credentials:

```php
// Create credentials with region
$credentials = new \Kyte\Aws\Credentials('us-east-1');

// Or specify custom keys
$credentials = new \Kyte\Aws\Credentials(
    'us-west-2',
    'custom-access-key',
    'custom-secret-key'
);
```

**Parameters:**
- `$region` - AWS region (e.g., 'us-east-1', 'eu-west-1')
- `$accessKey` - AWS access key ID (optional, uses AWS_ACCESS_KEY_ID if not provided)
- `$secretKey` - AWS secret key (optional, uses AWS_SECRET_KEY if not provided)

**Available methods:**
```php
$credentials->getCredentials();  // Returns AWS SDK credentials object
$credentials->getRegion();       // Returns region string
$credentials->getAccessKey();    // Returns access key
$credentials->getSecretKey();    // Returns secret key
```

---

## S3 (Simple Storage Service)

S3 is used for file storage, backups, and static website hosting.

### Creating an S3 Client

```php
// Create credentials
$credentials = new \Kyte\Aws\Credentials('us-east-1');

// Create S3 client
$s3 = new \Kyte\Aws\S3(
    $credentials,
    'my-bucket-name',
    'private'  // ACL: 'private', 'public-read', etc.
);
```

**Parameters:**
- `$credentials` - Credentials object
- `$bucket` - S3 bucket name
- `$acl` - Access control (default: 'private')

### Bucket Operations

#### Create a Bucket

```php
$s3 = new \Kyte\Aws\S3($credentials, 'my-new-bucket');

try {
    $s3->createBucket();
    echo "Bucket created successfully";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage();
}
```

#### Set Up Static Website Hosting

```php
$s3 = new \Kyte\Aws\S3($credentials, 'my-website-bucket');

try {
    $s3->createWebsite('index.html', 'error.html');
    echo "Website hosting enabled";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage();
}
```

**Parameters:**
- `$indexDoc` - Index document (default: 'index.html')
- `$errorDoc` - Error document (default: 'error.html')

#### Delete Website Configuration

```php
$s3->deleteWebsite();
```

### Public Access Management

#### Enable Public Access

```php
$s3 = new \Kyte\Aws\S3($credentials, 'my-public-bucket');

// Remove public access block
$s3->deletePublicAccessBlock();

// Enable public read policy
$s3->enablePublicAccess();
```

#### Restrict Public Access

```php
$s3->setPublicAccessBlock(
    $blockPublicAcls = true,
    $blockPublicPolicy = true,
    $ignorePublicAcls = true,
    $restrictPublicBuckets = true
);
```

### Bucket Policies

#### Apply Custom Policy

```php
$policy = '{
    "Version": "2012-10-17",
    "Statement": [{
        "Sid": "PublicReadGetObject",
        "Effect": "Allow",
        "Principal": "*",
        "Action": "s3:GetObject",
        "Resource": "arn:aws:s3:::my-bucket/*"
    }]
}';

$s3->enablePolicy($policy);
```

#### Delete Policy

```php
$s3->deletePolicy();
```

### CORS Configuration

```php
$rules = [
    [
        'AllowedHeaders' => ['*'],
        'AllowedMethods' => ['GET', 'PUT', 'POST', 'DELETE'],
        'AllowedOrigins' => ['https://example.com'],
        'ExposeHeaders' => ['ETag'],
        'MaxAgeSeconds' => 3000,
    ],
];

$s3->enableCors($rules);
```

### File Operations

While the wrapper classes focus on bucket management, you can use the underlying AWS SDK client for file operations:

```php
$s3 = new \Kyte\Aws\S3($credentials, 'my-bucket');

// Access the underlying AWS SDK client
$client = $s3->client;

// Upload a file
$client->putObject([
    'Bucket' => 'my-bucket',
    'Key' => 'path/to/file.jpg',
    'Body' => fopen('/local/path/file.jpg', 'r'),
    'ACL' => 'public-read',
]);

// Download a file
$result = $client->getObject([
    'Bucket' => 'my-bucket',
    'Key' => 'path/to/file.jpg',
]);

// Delete a file
$client->deleteObject([
    'Bucket' => 'my-bucket',
    'Key' => 'path/to/file.jpg',
]);
```

---

## SES (Simple Email Service)

SES is used for sending transactional and marketing emails.

### Creating an SES Client

```php
$credentials = new \Kyte\Aws\Credentials('us-east-1');

$ses = new \Kyte\Aws\Ses(
    $credentials,
    'noreply@example.com',           // Sender email
    ['support@example.com']          // Reply-to addresses (optional)
);
```

**Parameters:**
- `$credentials` - Credentials object
- `$sender` - From email address (must be verified in SES)
- `$replyToAddresses` - Array of reply-to addresses (optional)

### Sending Emails

#### Basic Email

```php
$ses = new \Kyte\Aws\Ses(
    $credentials,
    'noreply@example.com',
    ['support@example.com']
);

try {
    $messageId = $ses->send(
        ['recipient@example.com'],                    // Recipients
        'Welcome to Our Service',                     // Subject
        '<h1>Welcome!</h1><p>Thanks for joining.</p>' // HTML body
    );

    echo "Email sent! Message ID: $messageId";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage();
}
```

#### Multiple Recipients

```php
$recipients = [
    'user1@example.com',
    'user2@example.com',
    'user3@example.com',
];

$messageId = $ses->send(
    $recipients,
    'Important Update',
    '<p>This is an important update.</p>'
);
```

#### With Custom Character Set

```php
$messageId = $ses->send(
    ['recipient@example.com'],
    'Subject',
    '<p>Body</p>',
    'ISO-8859-1'  // Character set (default: UTF-8)
);
```

### Email Templates with Kyte

Use Kyte's EmailTemplate model to manage templates:

```php
// In a controller
public function sendWelcomeEmail($userId)
{
    // Get user
    $user = new \Kyte\Core\ModelObject(constant('User'));
    if (!$user->retrieve('id', $userId)) {
        throw new \Exception("User not found");
    }

    // Get email template
    $template = new \Kyte\Core\ModelObject(constant('EmailTemplate'));
    if (!$template->retrieve('name', 'welcome_email')) {
        throw new \Exception("Template not found");
    }

    // Replace placeholders
    $body = str_replace(
        ['{first_name}', '{last_name}', '{email}'],
        [$user->first_name, $user->last_name, $user->email],
        $template->body
    );

    // Send email
    $credentials = new \Kyte\Aws\Credentials(APP_SES_REGION);
    $ses = new \Kyte\Aws\Ses($credentials, APP_EMAIL);

    $messageId = $ses->send(
        [$user->email],
        $template->subject,
        $body
    );

    return $messageId;
}
```

---

## SNS (Simple Notification Service)

SNS is used for pub/sub messaging and notifications.

### Creating an SNS Client

```php
$credentials = new \Kyte\Aws\Credentials('us-east-1');
$sns = new \Kyte\Aws\Sns($credentials);
```

### Publishing Messages

#### Publish to Topic

```php
$sns = new \Kyte\Aws\Sns($credentials);

$topicArn = 'arn:aws:sns:us-east-1:123456789012:my-topic';

$messageId = $sns->publish(
    $topicArn,
    'Message subject',
    'Message body'
);

echo "Message published: $messageId";
```

### Kyte SNS Integration

Kyte uses SNS for system notifications. Configure these in your config:

```php
define('KYTE_USE_SNS', true);
define('SNS_REGION', 'us-east-1');
define('SNS_QUEUE_SITE_MANAGEMENT', 'arn:aws:sns:...:site-management');
define('SNS_KYTE_SHIPYARD_UPDATE', 'arn:aws:sns:...:shipyard-updates');
```

---

## KMS (Key Management Service)

KMS is used for encryption and decryption of sensitive data.

### Creating a KMS Client

```php
$credentials = new \Kyte\Aws\Credentials('us-east-1');
$kms = new \Kyte\Aws\Kms($credentials, AWS_KMS_KEYID);
```

**Parameters:**
- `$credentials` - Credentials object
- `$keyId` - KMS key ID or ARN

### Encrypting Data

```php
$kms = new \Kyte\Aws\Kms($credentials, AWS_KMS_KEYID);

$plaintext = 'Sensitive information';

try {
    $encrypted = $kms->encrypt($plaintext);
    echo "Encrypted: " . base64_encode($encrypted);
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage();
}
```

### Decrypting Data

```php
$kms = new \Kyte\Aws\Kms($credentials, AWS_KMS_KEYID);

try {
    $decrypted = $kms->decrypt($encrypted);
    echo "Decrypted: $decrypted";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage();
}
```

### Automatic KMS Encryption in Models

Mark fields for automatic KMS encryption in your model:

```php
$User = [
    'name' => 'User',
    'struct' => [
        'ssn' => [
            'type' => 's',
            'required' => false,
            'size' => 500,
            'kms' => true,  // Automatically encrypt with KMS
            'date' => false,
        ],
    ]
];
```

Kyte will automatically:
- Encrypt the field when saving
- Decrypt the field when retrieving
- Use the configured KMS key

---

## CloudFront

CloudFront is AWS's CDN (Content Delivery Network) service.

### Creating a CloudFront Client

```php
$credentials = new \Kyte\Aws\Credentials('us-east-1');
$cloudfront = new \Kyte\Aws\CloudFront($credentials);
```

### CloudFront Operations

CloudFront operations typically involve:
- Creating distributions
- Managing cache invalidations
- Configuring origins

Access the underlying AWS SDK client for advanced operations:

```php
$cloudfront = new \Kyte\Aws\CloudFront($credentials);
$client = $cloudfront->client;

// Create cache invalidation
$client->createInvalidation([
    'DistributionId' => 'E1234EXAMPLE',
    'InvalidationBatch' => [
        'Paths' => [
            'Quantity' => 1,
            'Items' => ['/path/to/invalidate/*'],
        ],
        'CallerReference' => time(),
    ],
]);
```

---

## Complete Examples

### Example 1: File Upload with S3

Complete file upload system:

```php
<?php
namespace Kyte\Mvc\Controller;

class MediaController extends ModelController
{
    protected function hook_init()
    {
        $this->requireAuth = true;
        $this->allowableActions = ['new', 'get', 'delete'];
    }

    public function new($data)
    {
        // Validate file upload
        if (!isset($_FILES['file'])) {
            throw new \Exception("No file uploaded");
        }

        $file = $_FILES['file'];

        // Validate file type
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf'];
        if (!in_array($file['type'], $allowedTypes)) {
            throw new \Exception("Invalid file type");
        }

        // Generate unique filename
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = uniqid() . '.' . $extension;
        $s3Path = 'uploads/' . date('Y/m/d') . '/' . $filename;

        try {
            // Upload to S3
            $credentials = new \Kyte\Aws\Credentials('us-east-1');
            $s3 = new \Kyte\Aws\S3($credentials, 'my-media-bucket', 'public-read');

            $s3->client->putObject([
                'Bucket' => 'my-media-bucket',
                'Key' => $s3Path,
                'Body' => fopen($file['tmp_name'], 'r'),
                'ContentType' => $file['type'],
                'ACL' => 'public-read',
            ]);

            // Save record to database
            $media = new \Kyte\Core\ModelObject(constant('Media'));

            $mediaData = [
                'filename' => $file['name'],
                'filepath' => $s3Path,
                'filetype' => $file['type'],
                'filesize' => $file['size'],
                'url' => 'https://my-media-bucket.s3.amazonaws.com/' . $s3Path,
                'user_id' => $this->api->user->id,
            ];

            $media->create($mediaData);

            // Return response
            $this->response['data'] = [$media->getAllParams()];

        } catch (\Exception $e) {
            throw new \Exception("Upload failed: " . $e->getMessage());
        }
    }

    protected function hook_response_data($method, $obj, &$response = null, &$data = null)
    {
        if ($method === 'delete') {
            // Delete from S3 when deleting record
            try {
                $credentials = new \Kyte\Aws\Credentials('us-east-1');
                $s3 = new \Kyte\Aws\S3($credentials, 'my-media-bucket');

                $s3->client->deleteObject([
                    'Bucket' => 'my-media-bucket',
                    'Key' => $obj->filepath,
                ]);
            } catch (\Exception $e) {
                error_log("Failed to delete S3 object: " . $e->getMessage());
            }
        }
    }
}
```

### Example 2: Email Notification System

Send emails on specific events:

```php
<?php
namespace Kyte\Mvc\Controller;

class OrderController extends ModelController
{
    protected function hook_init()
    {
        $this->requireAuth = true;
        $this->allowableActions = ['new', 'get', 'update'];
    }

    protected function hook_response_data($method, $obj, &$response = null, &$data = null)
    {
        // Send email when order is created
        if ($method === 'new') {
            $this->sendOrderConfirmation($obj);
        }

        // Send email when order status changes
        if ($method === 'update' && isset($data['status'])) {
            if ($data['status'] === 'shipped') {
                $this->sendShippingNotification($obj);
            } elseif ($data['status'] === 'delivered') {
                $this->sendDeliveryNotification($obj);
            }
        }
    }

    private function sendOrderConfirmation($order)
    {
        try {
            // Get user
            $user = new \Kyte\Core\ModelObject(constant('User'));
            if (!$user->retrieve('id', $order->user_id)) {
                return;
            }

            // Get email template
            $template = new \Kyte\Core\ModelObject(constant('EmailTemplate'));
            if (!$template->retrieve('name', 'order_confirmation')) {
                return;
            }

            // Build email body
            $body = str_replace(
                ['{name}', '{order_number}', '{total}'],
                [$user->first_name, $order->order_number, $order->total_amount],
                $template->body
            );

            // Send email
            $credentials = new \Kyte\Aws\Credentials(APP_SES_REGION);
            $ses = new \Kyte\Aws\Ses($credentials, APP_EMAIL, [SUPPORT_EMAIL]);

            $ses->send(
                [$user->email],
                'Order Confirmation - ' . $order->order_number,
                $body
            );

        } catch (\Exception $e) {
            error_log("Failed to send order confirmation: " . $e->getMessage());
        }
    }

    private function sendShippingNotification($order)
    {
        // Similar implementation
    }

    private function sendDeliveryNotification($order)
    {
        // Similar implementation
    }
}
```

### Example 3: Data Encryption with KMS

Encrypt sensitive user data:

```php
<?php
namespace Kyte\Mvc\Controller;

class UserProfileController extends ModelController
{
    protected function hook_init()
    {
        $this->requireAuth = true;
        $this->allowableActions = ['get', 'update'];
    }

    protected function hook_preprocess($method, &$data, &$obj = null)
    {
        // Manually encrypt sensitive fields
        if (isset($data['credit_card'])) {
            $data['credit_card'] = $this->encryptField($data['credit_card']);
        }

        if (isset($data['bank_account'])) {
            $data['bank_account'] = $this->encryptField($data['bank_account']);
        }
    }

    protected function hook_response_data($method, $obj, &$response = null, &$data = null)
    {
        if ($method === 'get') {
            // Decrypt sensitive fields
            if (isset($response['credit_card'])) {
                $response['credit_card'] = $this->decryptField($response['credit_card']);
            }

            if (isset($response['bank_account'])) {
                $response['bank_account'] = $this->decryptField($response['bank_account']);
            }
        }
    }

    private function encryptField($value)
    {
        if (empty($value)) {
            return $value;
        }

        try {
            $credentials = new \Kyte\Aws\Credentials('us-east-1');
            $kms = new \Kyte\Aws\Kms($credentials, AWS_KMS_KEYID);

            $encrypted = $kms->encrypt($value);
            return base64_encode($encrypted);

        } catch (\Exception $e) {
            error_log("Encryption failed: " . $e->getMessage());
            throw new \Exception("Unable to encrypt sensitive data");
        }
    }

    private function decryptField($value)
    {
        if (empty($value)) {
            return $value;
        }

        try {
            $credentials = new \Kyte\Aws\Credentials('us-east-1');
            $kms = new \Kyte\Aws\Kms($credentials, AWS_KMS_KEYID);

            $encrypted = base64_decode($value);
            return $kms->decrypt($encrypted);

        } catch (\Exception $e) {
            error_log("Decryption failed: " . $e->getMessage());
            return '[ENCRYPTED]';
        }
    }
}
```

### Example 4: SNS Event Publishing

Publish events to SNS topics:

```php
<?php
namespace Kyte\Mvc\Controller;

class InventoryController extends ModelController
{
    protected function hook_init()
    {
        $this->requireAuth = true;
    }

    protected function hook_preprocess($method, &$data, &$obj = null)
    {
        if ($method === 'update' && isset($data['stock_quantity'])) {
            // Check if stock is low
            $newQuantity = (int) $data['stock_quantity'];

            if ($newQuantity < 10 && $obj->stock_quantity >= 10) {
                // Stock just dropped below threshold
                $this->publishLowStockAlert($obj->id, $obj->name, $newQuantity);
            }
        }
    }

    private function publishLowStockAlert($productId, $productName, $quantity)
    {
        if (!KYTE_USE_SNS) {
            return;
        }

        try {
            $credentials = new \Kyte\Aws\Credentials(SNS_REGION);
            $sns = new \Kyte\Aws\Sns($credentials);

            $message = json_encode([
                'event' => 'low_stock',
                'product_id' => $productId,
                'product_name' => $productName,
                'quantity' => $quantity,
                'timestamp' => time(),
            ]);

            $sns->publish(
                'arn:aws:sns:us-east-1:123456789012:inventory-alerts',
                'Low Stock Alert',
                $message
            );

        } catch (\Exception $e) {
            error_log("Failed to publish SNS message: " . $e->getMessage());
        }
    }
}
```

---

## Best Practices

### 1. Always Use Try-Catch

AWS operations can fail, so always wrap them in try-catch:

```php
try {
    $ses->send($recipients, $subject, $body);
} catch (\Exception $e) {
    error_log("Email failed: " . $e->getMessage());
    // Handle error appropriately
}
```

### 2. Store Credentials Securely

Never hardcode credentials in your code:

```php
// GOOD - Use constants
$credentials = new \Kyte\Aws\Credentials('us-east-1');

// BAD - Don't hardcode
$credentials = new \Kyte\Aws\Credentials(
    'us-east-1',
    'AKIAIOSFODNN7EXAMPLE',
    'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY'
);
```

### 3. Use Appropriate Regions

Choose regions close to your users:

```php
// US East Coast users
$credentials = new \Kyte\Aws\Credentials('us-east-1');

// European users
$credentials = new \Kyte\Aws\Credentials('eu-west-1');

// Asian users
$credentials = new \Kyte\Aws\Credentials('ap-northeast-1');
```

### 4. Set Appropriate S3 ACLs

Don't make everything public:

```php
// Public files (images, documents)
$s3 = new \Kyte\Aws\S3($credentials, 'public-bucket', 'public-read');

// Private files (user documents)
$s3 = new \Kyte\Aws\S3($credentials, 'private-bucket', 'private');
```

### 5. Verify SES Email Addresses

Before sending, ensure email addresses are verified in SES:

```php
// Sender address must be verified
$ses = new \Kyte\Aws\Ses(
    $credentials,
    'verified@example.com',  // Must be verified in SES
    ['also-verified@example.com']
);
```

### 6. Use Email Templates

Store email templates in the database for easy updates:

```php
// Get template from database
$template = new \Kyte\Core\ModelObject(constant('EmailTemplate'));
$template->retrieve('name', 'welcome_email');

// Use template
$body = str_replace('{name}', $user->name, $template->body);
```

### 7. Handle KMS Gracefully

KMS operations can fail, handle errors gracefully:

```php
try {
    $decrypted = $kms->decrypt($encrypted);
} catch (\Exception $e) {
    error_log("KMS decryption failed: " . $e->getMessage());
    // Return placeholder or handle appropriately
    $decrypted = '[ENCRYPTED]';
}
```

### 8. Monitor AWS Costs

AWS services cost money. Monitor usage:

- Set up CloudWatch alarms for S3 storage
- Monitor SES sending limits
- Review KMS API call counts
- Use S3 lifecycle policies to archive old files

### 9. Use IAM Roles When Possible

When running on EC2, use IAM roles instead of access keys:

```php
// On EC2 with IAM role, credentials are automatic
$credentials = new \Kyte\Aws\Credentials('us-east-1');
// No need to pass access keys
```

### 10. Implement Retry Logic

AWS services can have transient failures:

```php
function sendEmailWithRetry($ses, $recipients, $subject, $body, $maxRetries = 3)
{
    $attempt = 0;

    while ($attempt < $maxRetries) {
        try {
            return $ses->send($recipients, $subject, $body);
        } catch (\Exception $e) {
            $attempt++;

            if ($attempt >= $maxRetries) {
                throw $e;
            }

            // Wait before retry (exponential backoff)
            sleep(pow(2, $attempt));
        }
    }
}
```

---

## Summary

### Key AWS Services

| Service | Use For | Common Operations |
|---------|---------|-------------------|
| **S3** | File storage | Upload, download, host static sites |
| **SES** | Email sending | Transactional emails, notifications |
| **SNS** | Pub/sub messaging | Event notifications, alerts |
| **KMS** | Encryption | Encrypt/decrypt sensitive data |
| **CloudFront** | CDN | Cache static assets globally |

### Quick Reference

**Creating Credentials:**
```php
$credentials = new \Kyte\Aws\Credentials('us-east-1');
```

**S3:**
```php
$s3 = new \Kyte\Aws\S3($credentials, 'bucket-name', 'private');
$s3->createBucket();
$s3->createWebsite();
```

**SES:**
```php
$ses = new \Kyte\Aws\Ses($credentials, 'sender@example.com');
$ses->send(['recipient@example.com'], 'Subject', '<p>Body</p>');
```

**SNS:**
```php
$sns = new \Kyte\Aws\Sns($credentials);
$sns->publish($topicArn, 'Subject', 'Message');
```

**KMS:**
```php
$kms = new \Kyte\Aws\Kms($credentials, $keyId);
$encrypted = $kms->encrypt('plaintext');
$decrypted = $kms->decrypt($encrypted);
```

### Configuration Checklist

- [ ] Set `AWS_ACCESS_KEY_ID` constant
- [ ] Set `AWS_SECRET_KEY` constant
- [ ] Set `AWS_KMS_KEYID` for encryption (optional)
- [ ] Set `APP_SES_REGION` for email
- [ ] Verify sender email addresses in SES
- [ ] Create S3 buckets as needed
- [ ] Set up SNS topics (optional)
- [ ] Configure IAM permissions appropriately

---

## Additional Resources

### AWS Documentation
- [AWS SDK for PHP](https://aws.amazon.com/sdk-for-php/)
- [S3 Documentation](https://docs.aws.amazon.com/s3/)
- [SES Documentation](https://docs.aws.amazon.com/ses/)
- [KMS Documentation](https://docs.aws.amazon.com/kms/)
- [SNS Documentation](https://docs.aws.amazon.com/sns/)

### Kyte Resources
- [Model Definition Guide](01-model-definition.md)
- [Models and ModelObjects Guide](02-models-and-modelobjects.md)
- [Controllers Guide](03-controllers.md)
- [Main Documentation](../CLAUDE.md)

---

This concludes the AWS Integration Guide. You now have comprehensive knowledge of how to use AWS services within Kyte-PHP applications!
