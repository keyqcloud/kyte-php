# Kyte-PHP Framework
[![PHP Composer](https://github.com/keyqcloud/kyte-php/actions/workflows/php.yml/badge.svg)](https://github.com/keyqcloud/kyte-php/actions/workflows/php.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/keyqcloud/kyte-php.svg?style=flat)](https://packagist.org/packages/keyqcloud/kyte-php)

(c) 2020-2025 [KeyQ, Inc.](https://www.keyq.cloud)

## About Kyte-PHP

Kyte-PHP is a modern, database-driven web application framework designed to make development more enjoyable and streamline the development workflow. The framework works as a backend API and can be integrated into different application architectures and front-end languages/frameworks.

**Key Features:**
- **Dynamic MVC Architecture**: Models, views, and controllers are managed through Kyte Shipyard and stored in the database
- **Multi-tenant SaaS Support**: Built-in support for multi-tenant applications with account-level scoping
- **API-First Design**: RESTful API with HMAC signature authentication
- **Database-Driven Configuration**: Application models and controllers are dynamically loaded from the database
- **AWS Integration**: Native support for S3, SES, SNS, and other AWS services
- **Session Management**: Robust session handling with configurable timeouts and multi-login support

## Architecture Overview

Kyte-PHP has evolved from a traditional file-based MVC framework to a dynamic, database-driven architecture. All application models, controllers, and configurations are now managed through **[Kyte Shipyard](https://github.com/keyqcloud/kyte-shipyard/)** and stored in the database, allowing for:

- Runtime model and controller loading
- Dynamic application configuration
- Multi-application support from a single framework instance
- Version-controlled deployments through the database

## Getting Started

### Prerequisites

- PHP 7.4 or higher
- MySQL 5.7+ or MariaDB 10.2+
- Composer
- [Kyte Shipyard](https://github.com/keyqcloud/kyte-shipyard/)
- AWS account

### Installation

```bash
composer require keyqcloud/kyte-php
```

### Basic Setup

1. **Configure your web server** with the following `.htaccess`:
```apache
FallbackResource /index.php
```

2. **Database Configuration** - Set your database credentials in your configuration:
```php
define('KYTE_DB_HOST', 'your-db-host');
define('KYTE_DB_DATABASE', 'your-db-name');
define('KYTE_DB_USERNAME', 'your-db-user');
define('KYTE_DB_PASSWORD', 'your-db-password');
define('KYTE_DB_CHARSET', 'utf8mb4');
```

3. **Initialize the API** in your `index.php`:
```php
<?php
require_once 'vendor/autoload.php';

$api = new \Kyte\Core\Api();
$api->route();
?>
```

## Authentication & API Access

### API Signature Generation

Kyte-PHP uses HMAC-SHA256 signatures for secure API access. Signature are automatically generate or can be requested using platform specific libraries. Kyte currently supports vanilla JS, Dart/Flutter, C/C++, Java, and Python. Below is a list of platform specific libraries:

* [Vanilla JavaScript](https://github.com/keyqcloud/kyte-api-js)
* [Python](https://github.com/keyqcloud/kyte-api-python)
* [Swift](https://github.com/keyqcloud/KyteSwift)
* [Dart/Flutter](https://github.com/keyqcloud/kyte_dart)
* [Java](https://github.com/keyqcloud/kyte-api-java)
* [C++](https://github.com/keyqcloud/kyte-cpp)
* [PHP](https://github.com/keyqcloud/kyte-api-php)

### Making API Calls

Once you have a signature, use the following URL format:

- **POST** `/{model}` + data (Create)
- **PUT** `/{model}/{field}/{value}` + data (Update)
- **GET** `/{model}/{field}/{value}` (Read)
- **DELETE** `/{model}/{field}/{value}` (Delete)

Required headers:
- `X-Kyte-Identity`: Base64 encoded identity string
- `X-Kyte-Signature`: HMAC signature
- `X-Kyte-AppId`: Application identifier (for multi-tenant apps)

## Dynamic Model System

### Database-Driven Models

Models are now stored in the `DataModel` table and dynamically loaded:

```php
// Models are automatically loaded from the database
// No need to define them in files anymore
$user = new \Kyte\Core\ModelObject(constant('User'));
$user->create([
    'name' => 'John Doe',
    'email' => 'john@example.com'
]);
```

### Model Structure

Models follow this structure in the database:
```php
[
    'name' => 'ModelName',
    'struct' => [
        'field_name' => [
            'type' => 's|i|d|t|b',  // string, integer, decimal, text, blob
            'required' => true|false,
            'size' => 255,
            'date' => true|false,
            'protected' => true|false,
            'fk' => [
                'model' => 'RelatedModel',
                'field' => 'id'
            ]
        ]
    ]
]
```

### Supported Field Types

| Type | Description | MySQL Type |
|------|-------------|------------|
| `s` | String | VARCHAR |
| `i` | Integer | INT |
| `bi` | Big Integer | BIGINT |
| `d` | Decimal | DECIMAL |
| `t` | Text | TEXT |
| `tt` | Tiny Text | TINYTEXT |
| `mt` | Medium Text | MEDIUMTEXT |
| `lt` | Long Text | LONGTEXT |
| `b` | Blob | BLOB |

## Controllers

### Dynamic Controller Loading

Controllers are stored in the `Controller` table and loaded dynamically:

```php
class CustomController extends \Kyte\Mvc\Controller\ModelController 
{
    protected function init() {
        // Custom initialization
        $this->requireAuth = true;
        $this->allowableActions = ['get', 'new', 'update'];
    }
    
    public function hook_preprocess($method, &$data, &$obj = null) {
        // Custom preprocessing logic
        if ($method === 'new') {
            $data['created_by'] = $this->api->user->id;
        }
    }
}
```

### Controller Hooks

Available hooks for customizing behavior:

- `hook_init()` - Initialize controller settings
- `hook_auth()` - Custom authentication logic
- `hook_prequery()` - Modify query parameters
- `hook_preprocess()` - Process data before operations
- `hook_response_data()` - Modify response data
- `hook_process_get_response()` - Process GET responses

## Multi-Tenant Applications

Kyte-PHP supports multi-tenant architectures:

```php
// Application-level models with org scoping
$this->api->app->org_model; // Organization model
$this->api->app->userorg_colname; // User-organization relationship

// Automatic scoping in controllers
if ($this->api->app->org_model !== null) {
    $conditions = [
        ['field' => $this->api->app->userorg_colname, 'value' => $this->user->organization_id]
    ];
}
```

## Database Features

### SSL/TLS Support

Configure SSL connections with:
```php
define('KYTE_DB_CA_BUNDLE', '/path/to/rds-ca-2019-root.pem');
```

### Connection Management

- Automatic connection switching between main and application databases
- Connection pooling and management
- Fallback support for non-SSL connections

## Advanced Features

### Environment Variables

Application-level environment variables stored in `KyteEnvironmentVariable` and managed through Kyte Shipyard:
```php
// Access app-specific environment variables
$envVars = KYTE_APP_ENV;
echo $envVars['CUSTOM_SETTING'];
```

### AWS Integration

Built-in support for:
- **S3**: File storage and static website hosting
- **SES**: Email sending capabilities
- **SNS**: Notification services
- **CloudFront**: CDN distribution

## Configuration Options

### Framework Constants

```php
define('DEBUG', false);
define('ALLOW_MULTILOGON', false);
define('SESSION_TIMEOUT', 3600);
define('SIGNATURE_TIMEOUT', 300);
define('PAGE_SIZE', 50);
define('STRICT_TYPING', true);
```

### Date Formatting

```php
define('APP_DATE_FORMAT', 'Y-m-d H:i:s');
```

## Error Handling

Comprehensive error handling with:
- Session exceptions for authentication errors
- Database connection error recovery
- Application-level error logging
- S3-based error logging for production

## Migration from Earlier Versions

If migrating from file-based models:

1. Use Kyte Shipyard to import existing models
2. Convert file-based controllers to database entries
3. Update application configuration for multi-tenant support
4. Test dynamic loading functionality

## API Response Format

Standard API responses:
```json
{
    "response_code": 200,
    "session": "session_token",
    "token": "transaction_token",
    "uid": "user_id",
    "model": "ModelName",
    "transaction": "GET|POST|PUT|DELETE",
    "txTimestamp": "1640995200",
    "data": [],
    "page_size": 50,
    "page_num": 1,
    "total_count": 100,
    "total_filtered": 75
}
```

## Development Tools

### Kyte Shipyard Integration

All model and controller management is now done through Kyte Shipyard:
- Visual model designer
- Controller code editor
- Application deployment management
- Environment variable configuration

### CLI Support

The framework includes CLI support for:
- Database migrations
- Model synchronization
- Application deployment

## Security

- HMAC-SHA256 signature authentication
- SQL injection prevention with prepared statements
- Cross-origin resource sharing (CORS) support
- Session token validation
- Protected field support for sensitive data

## Performance

- Connection pooling
- Prepared statement caching
- Efficient foreign key loading
- Pagination support for large datasets
- Optional external table loading

## License

Copyright (c) 2020-2025 KeyQ, Inc. All rights reserved.
