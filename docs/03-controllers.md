# Controllers Guide

## Table of Contents
1. [What is a Controller?](#what-is-a-controller)
2. [ModelController Basics](#modelcontroller-basics)
3. [Creating Custom Controllers](#creating-custom-controllers)
4. [Controller Hooks](#controller-hooks)
5. [Controller Properties](#controller-properties)
6. [Request Flow](#request-flow)
7. [Complete Examples](#complete-examples)
8. [Best Practices](#best-practices)

---

## What is a Controller?

A **controller** in Kyte-PHP handles HTTP requests and orchestrates the interaction between the API, models, and business logic. Every API request goes through a controller.

### The Default Behavior

If you don't create a custom controller, Kyte uses the base `ModelController` which provides standard CRUD operations out of the box:

- **POST** `/{model}` → Creates a new record
- **GET** `/{model}/{field}/{value}` → Retrieves records
- **PUT** `/{model}/{field}/{value}` → Updates records
- **DELETE** `/{model}/{field}/{value}` → Deletes records

### When to Create Custom Controllers

Create a custom controller when you need to:
- Override default behaviors
- Add custom validation logic
- Implement business rules
- Restrict certain operations
- Add custom authentication
- Modify data before saving
- Transform response data
- Implement complex workflows

---

## ModelController Basics

All controllers extend `\Kyte\Mvc\Controller\ModelController`:

```php
<?php
namespace Kyte\Mvc\Controller;

class MyCustomController extends ModelController
{
    // Your custom code here
}
```

### How Controllers Are Instantiated

When a request comes in, Kyte automatically:
1. Identifies the model from the URL
2. Loads the model definition
3. Creates a controller instance
4. Passes the model, API reference, date format, and response to the constructor

```php
// This happens automatically
$controller = new MyCustomController($model, $api, $dateformat, $response);
```

### Constructor Parameters

The base ModelController constructor receives:

| Parameter | Type | Description |
|-----------|------|-------------|
| `$model` | array | The model definition |
| `$api` | Api | Reference to the API instance |
| `$dateformat` | string | Date format for this request |
| `$response` | array | Reference to the response array |

You typically don't need to override the constructor. Instead, use the `hook_init()` method.

---

## Creating Custom Controllers

### Basic Structure

```php
<?php
namespace Kyte\Mvc\Controller;

class UserController extends ModelController
{
    protected function hook_init()
    {
        // Set controller properties
        $this->requireAuth = true;
        $this->allowableActions = ['new', 'get', 'update'];
    }

    protected function hook_preprocess($method, &$data, &$obj = null)
    {
        // Modify data before processing
        if ($method === 'new') {
            $data['status'] = 'active';
        }
    }
}
```

### Storing Controllers

Controllers are stored in the `Controller` table in the database and loaded dynamically. Through Kyte Shipyard, you can:
1. Create a new controller
2. Write the PHP code
3. Link it to a specific model
4. Deploy it

---

## Controller Hooks

Hooks are methods you can override to customize behavior at specific points in the request lifecycle.

### hook_init()

Called during controller initialization. Use this to configure controller properties.

```php
protected function hook_init()
{
    // Set which operations are allowed
    $this->allowableActions = ['get', 'new'];  // Only GET and POST

    // Require authentication
    $this->requireAuth = true;

    // Don't load foreign key data
    $this->getFKTables = false;

    // Fail if no records found
    $this->failOnNull = true;

    // Check for existing records
    $this->checkExisting = 'email';  // Field to check for duplicates
}
```

**When it's called:** After constructor, before authentication

**Common uses:**
- Set allowable actions
- Configure authentication requirements
- Set default behaviors
- Initialize custom variables

### hook_auth()

Called after default authentication. Use this for custom authentication logic.

```php
protected function hook_auth()
{
    // Custom authentication logic
    if (!isset($this->api->user->id)) {
        throw new \Kyte\Exception\SessionException("Authentication required");
    }

    // Check user role
    if ($this->api->user->role !== 'admin') {
        throw new \Kyte\Exception\SessionException("Admin access required");
    }
}
```

**When it's called:** During initialization if `$this->requireAuth` is true

**Common uses:**
- Role-based access control
- Custom permission checks
- Organization/tenant validation

### hook_prequery()

Called before database query operations. Use this to modify query parameters.

```php
protected function hook_prequery($method, &$field, &$value, &$conditions, &$all, &$order)
{
    // Add default conditions
    if ($method === 'get') {
        if ($conditions === null) {
            $conditions = [];
        }
        $conditions[] = ['field' => 'organization_id', 'value' => $this->api->user->organization_id];
    }

    // Force specific ordering
    $order = [
        ['field' => 'date_created', 'direction' => 'DESC']
    ];
}
```

**When it's called:** Before GET, UPDATE, or DELETE operations execute the database query

**Parameters:**
- `$method` - 'get', 'update', or 'delete'
- `$field` - Field being queried (can be modified)
- `$value` - Value being searched (can be modified)
- `$conditions` - Additional query conditions (can be modified)
- `$all` - Whether to include deleted records (can be modified)
- `$order` - Order by clause (can be modified)

**Common uses:**
- Add organization/tenant scoping
- Force specific query conditions
- Set default ordering
- Restrict data access

### hook_preprocess()

Called before create or update operations. Use this to modify or validate data.

```php
protected function hook_preprocess($method, &$data, &$obj = null)
{
    if ($method === 'new') {
        // Set default values
        $data['status'] = 'pending';
        $data['created_by'] = $this->api->user->id;

        // Validate data
        if (empty($data['email'])) {
            throw new \Exception("Email is required");
        }

        // Transform data
        $data['email'] = strtolower(trim($data['email']));
    }

    if ($method === 'update') {
        // Prevent certain fields from being updated
        unset($data['email']);
        unset($data['created_by']);

        // Add modification tracking
        $data['last_modified_by'] = $this->api->user->id;
    }
}
```

**When it's called:** Before CREATE or UPDATE operations

**Parameters:**
- `$method` - 'new' or 'update'
- `$data` - Data being saved (can be modified)
- `$obj` - For updates, the ModelObject being updated (null for creates)

**Common uses:**
- Set default values
- Validate data
- Transform data
- Add user tracking
- Prevent certain fields from being modified

### hook_response_data()

Called after operations, before returning data to client. Use this to modify response data.

```php
protected function hook_response_data($method, $obj, &$response = null, &$data = null)
{
    if ($method === 'get') {
        // Add computed fields
        $response['full_name'] = $response['first_name'] . ' ' . $response['last_name'];

        // Remove sensitive fields
        unset($response['internal_notes']);

        // Transform data
        $response['email'] = strtolower($response['email']);
    }

    if ($method === 'delete') {
        // Prevent deletion based on conditions
        if ($obj->status === 'protected') {
            $response = false;  // Cancels the delete
            throw new \Exception("Cannot delete protected records");
        }
    }
}
```

**When it's called:** After database operations, before sending response

**Parameters:**
- `$method` - 'get', 'new', 'update', or 'delete'
- `$obj` - The ModelObject
- `$response` - Response data (can be modified)
- `$data` - Original request data (for new/update)

**Common uses:**
- Add computed fields
- Remove sensitive data
- Transform data for client
- Cancel operations based on conditions

### hook_process_get_response()

Called specifically for GET requests after all records are processed.

```php
protected function hook_process_get_response(&$response)
{
    // Add summary information
    $total = 0;
    foreach ($response as &$item) {
        $total += $item['amount'];

        // Add computed field to each item
        $item['display_name'] = $item['first_name'] . ' ' . $item['last_name'];
    }

    // Note: Can't add summary directly to $response array
    // Use $this->response to add custom data
    $this->response['summary'] = ['total_amount' => $total];
}
```

**When it's called:** After all GET records are processed

**Parameters:**
- `$response` - Array of all response records (can be modified)

**Common uses:**
- Batch process results
- Add summary information
- Compute aggregates
- Transform entire result set

---

## Controller Properties

These properties control controller behavior. Set them in `hook_init()`.

### allowableActions

Controls which HTTP methods are allowed.

```php
$this->allowableActions = ['new', 'get', 'update', 'delete'];  // All operations
$this->allowableActions = ['get'];                              // Read-only
$this->allowableActions = ['new', 'get'];                       // Create and read
$this->allowableActions = [];                                   // No operations
```

**Values:**
- `'new'` - POST requests (create)
- `'get'` - GET requests (read)
- `'update'` - PUT requests (update)
- `'delete'` - DELETE requests (delete)

**Default:** `['new', 'get', 'update', 'delete']`

### requireAuth

Whether authentication is required.

```php
$this->requireAuth = true;   // Require valid session (default)
$this->requireAuth = false;  // Public access
```

**Default:** `true`

### requireAccount

Whether to scope queries to the current account.

```php
$this->requireAccount = true;   // Add account scoping (default)
$this->requireAccount = false;  // Don't scope by account
```

**Default:** `true`

### getFKTables

Whether to load foreign key relationships.

```php
$this->getFKTables = true;   // Load related data (default)
$this->getFKTables = false;  // Only load IDs
```

**Default:** `true`

**Example:** If User has `organization_id` with FK to Organization, setting this to true will return the full Organization object instead of just the ID.

### getExternalTables

Whether to load external table relationships (reverse FK).

```php
$this->getExternalTables = true;   // Load external tables
$this->getExternalTables = false;  // Don't load external tables (default)
```

**Default:** `false` (can be overridden by HTTP header)

### cascadeDelete

Whether to delete related records when deleting.

```php
$this->cascadeDelete = true;   // Delete related records (default)
$this->cascadeDelete = false;  // Don't cascade deletes
```

**Default:** `true`

### failOnNull

Whether to throw an exception if no records found.

```php
$this->failOnNull = true;   // Throw exception if empty
$this->failOnNull = false;  // Return empty array (default)
```

**Default:** `false`

### checkExisting

Field to check for duplicates when creating records.

```php
$this->checkExisting = 'email';      // Check if email exists
$this->checkExisting = 'username';   // Check if username exists
$this->checkExisting = null;         // Don't check (default)
```

**Default:** `null`

### existingThrowException

Whether to throw exception or silently fail when duplicate found.

```php
$this->existingThrowException = true;   // Throw exception (default)
$this->existingThrowException = false;  // Silently ignore
```

**Default:** `true`

### exceptionMessages

Custom error messages for different operations.

```php
$this->exceptionMessages = [
    'new' => [
        'failOnNull' => 'Failed to create user',
    ],
    'update' => [
        'failOnNull' => 'User not found for update',
    ],
    'get' => [
        'failOnNull' => 'No users found',
    ],
    'delete' => [
        'failOnNull' => 'User not found for deletion',
    ],
];
```

---

## Request Flow

Understanding how a request flows through a controller helps you know where to add your custom logic.

### Create (POST) Flow

1. Request received: `POST /User`
2. Controller instantiated
3. `hook_init()` called
4. Authentication checked (if `requireAuth` is true)
5. `hook_auth()` called
6. `new($data)` method called
7. `hook_preprocess('new', $data)` called
8. Data transformed (dates, passwords, FK data)
9. `ModelObject->create()` called
10. `hook_response_data('new', $obj, $response, $data)` called
11. Response returned to client

### Read (GET) Flow

1. Request received: `GET /User/status/active`
2. Controller instantiated
3. `hook_init()` called
4. Authentication checked
5. `hook_auth()` called
6. `get($field, $value)` method called
7. `hook_prequery('get', $field, $value, $conditions, $all, $order)` called
8. `Model->retrieve()` called
9. For each record:
   - `hook_response_data('get', $obj, $response)` called
10. `hook_process_get_response($response)` called
11. Response returned to client

### Update (PUT) Flow

1. Request received: `PUT /User/id/123`
2. Controller instantiated
3. `hook_init()` called
4. Authentication checked
5. `hook_auth()` called
6. `update($field, $value, $data)` method called
7. `hook_prequery('update', $field, $value, $conditions, $all, $order)` called
8. `ModelObject->retrieve()` called
9. `hook_preprocess('update', $data, $obj)` called
10. Data transformed
11. `ModelObject->save()` called
12. `hook_response_data('update', $obj, $response, $data)` called
13. Response returned to client

### Delete (DELETE) Flow

1. Request received: `DELETE /User/id/123`
2. Controller instantiated
3. `hook_init()` called
4. Authentication checked
5. `hook_auth()` called
6. `delete($field, $value)` method called
7. `hook_prequery('delete', $field, $value, $conditions, $all, $order)` called
8. `Model->retrieve()` called
9. For each record:
   - `hook_response_data('delete', $obj, $autodelete)` called
   - If `cascadeDelete` is true, delete external table records
   - `ModelObject->delete()` called
10. Response returned to client

---

## Complete Examples

### Example 1: Read-Only Controller

Make an API model read-only:

```php
<?php
namespace Kyte\Mvc\Controller;

class ReadOnlyController extends ModelController
{
    protected function hook_init()
    {
        // Only allow GET requests
        $this->allowableActions = ['get'];

        // Require authentication
        $this->requireAuth = true;
    }
}
```

### Example 2: User Registration Controller

Custom logic for user registration:

```php
<?php
namespace Kyte\Mvc\Controller;

class UserController extends ModelController
{
    protected function hook_init()
    {
        // Allow create and read
        $this->allowableActions = ['new', 'get', 'update'];

        // Check for duplicate emails
        $this->checkExisting = 'email';
    }

    protected function hook_preprocess($method, &$data, &$obj = null)
    {
        if ($method === 'new') {
            // Validate email format
            if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                throw new \Exception("Invalid email format");
            }

            // Normalize email
            $data['email'] = strtolower(trim($data['email']));

            // Set default status
            $data['status'] = 'pending_verification';

            // Set default role
            $data['role'] = 'user';
        }

        if ($method === 'update') {
            // Prevent email changes
            unset($data['email']);

            // Prevent role changes by non-admins
            if ($this->api->user->role !== 'admin') {
                unset($data['role']);
            }
        }
    }

    protected function hook_response_data($method, $obj, &$response = null, &$data = null)
    {
        // Don't return password hash
        if (isset($response['password'])) {
            unset($response['password']);
        }

        // Add computed field
        if ($method === 'get') {
            $response['full_name'] = $obj->first_name . ' ' . $obj->last_name;
        }
    }
}
```

### Example 3: Organization-Scoped Controller

Limit access to records within user's organization:

```php
<?php
namespace Kyte\Mvc\Controller;

class ProjectController extends ModelController
{
    protected function hook_init()
    {
        $this->requireAuth = true;
        $this->allowableActions = ['new', 'get', 'update', 'delete'];
    }

    protected function hook_auth()
    {
        // Ensure user has an organization
        if (empty($this->api->user->organization_id)) {
            throw new \Kyte\Exception\SessionException("User must belong to an organization");
        }
    }

    protected function hook_prequery($method, &$field, &$value, &$conditions, &$all, &$order)
    {
        // Always scope to user's organization
        if ($conditions === null) {
            $conditions = [];
        }

        $conditions[] = [
            'field' => 'organization_id',
            'value' => $this->api->user->organization_id
        ];
    }

    protected function hook_preprocess($method, &$data, &$obj = null)
    {
        if ($method === 'new') {
            // Automatically set organization
            $data['organization_id'] = $this->api->user->organization_id;

            // Set creator
            $data['created_by'] = $this->api->user->id;
        }

        if ($method === 'update') {
            // Prevent organization changes
            unset($data['organization_id']);
        }
    }
}
```

### Example 4: Order Processing Controller

Complex business logic for order processing:

```php
<?php
namespace Kyte\Mvc\Controller;

class OrderController extends ModelController
{
    protected function hook_init()
    {
        $this->requireAuth = true;
        $this->allowableActions = ['new', 'get', 'update'];
        $this->failOnNull = true;
    }

    protected function hook_preprocess($method, &$data, &$obj = null)
    {
        if ($method === 'new') {
            // Calculate total from order items
            $total = 0;
            if (isset($data['items']) && is_array($data['items'])) {
                foreach ($data['items'] as $item) {
                    $total += $item['price'] * $item['quantity'];
                }
            }
            $data['total_amount'] = $total;

            // Set initial status
            $data['status'] = 'pending';
            $data['order_date'] = time();

            // Set customer
            $data['user_id'] = $this->api->user->id;

            // Generate order number
            $data['order_number'] = 'ORD-' . strtoupper(substr(md5(uniqid()), 0, 10));
        }

        if ($method === 'update') {
            // Only allow certain status transitions
            if (isset($data['status'])) {
                $allowedTransitions = [
                    'pending' => ['processing', 'cancelled'],
                    'processing' => ['shipped', 'cancelled'],
                    'shipped' => ['delivered'],
                    'delivered' => [],
                    'cancelled' => [],
                ];

                $currentStatus = $obj->status;
                $newStatus = $data['status'];

                if (!in_array($newStatus, $allowedTransitions[$currentStatus])) {
                    throw new \Exception("Invalid status transition from $currentStatus to $newStatus");
                }
            }

            // Prevent changing amount after processing
            if ($obj->status !== 'pending') {
                unset($data['total_amount']);
            }
        }
    }

    protected function hook_response_data($method, $obj, &$response = null, &$data = null)
    {
        if ($method === 'get') {
            // Add status display name
            $statusNames = [
                'pending' => 'Pending Payment',
                'processing' => 'Processing',
                'shipped' => 'Shipped',
                'delivered' => 'Delivered',
                'cancelled' => 'Cancelled',
            ];
            $response['status_display'] = $statusNames[$obj->status] ?? $obj->status;
        }
    }
}
```

### Example 5: Public API with Rate Limiting

A controller for public API access:

```php
<?php
namespace Kyte\Mvc\Controller;

class PublicProductController extends ModelController
{
    protected function hook_init()
    {
        // Public access, read-only
        $this->requireAuth = false;
        $this->allowableActions = ['get'];
        $this->getFKTables = true;
    }

    protected function hook_prequery($method, &$field, &$value, &$conditions, &$all, &$order)
    {
        // Only show published products
        if ($conditions === null) {
            $conditions = [];
        }

        $conditions[] = [
            'field' => 'status',
            'value' => 'published'
        ];

        // Default ordering
        if ($order === null) {
            $order = [
                ['field' => 'featured', 'direction' => 'DESC'],
                ['field' => 'name', 'direction' => 'ASC'],
            ];
        }
    }

    protected function hook_response_data($method, $obj, &$response = null, &$data = null)
    {
        // Don't expose internal fields
        unset($response['cost']);
        unset($response['supplier_id']);
        unset($response['internal_notes']);

        // Add computed fields
        if (isset($response['price'], $response['discount_percent'])) {
            $response['sale_price'] = $response['price'] * (1 - $response['discount_percent'] / 100);
        }
    }
}
```

---

## Best Practices

### 1. Use hook_init() for Configuration

Always configure controller behavior in `hook_init()`:

```php
protected function hook_init()
{
    $this->allowableActions = ['get', 'new'];
    $this->requireAuth = true;
    $this->checkExisting = 'email';
}
```

### 2. Validate in hook_preprocess()

Perform data validation before saving:

```php
protected function hook_preprocess($method, &$data, &$obj = null)
{
    if ($method === 'new') {
        if (empty($data['email'])) {
            throw new \Exception("Email is required");
        }

        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            throw new \Exception("Invalid email format");
        }
    }
}
```

### 3. Scope Data in hook_prequery()

Always scope queries appropriately:

```php
protected function hook_prequery($method, &$field, &$value, &$conditions, &$all, &$order)
{
    if ($conditions === null) {
        $conditions = [];
    }

    $conditions[] = [
        'field' => 'organization_id',
        'value' => $this->api->user->organization_id
    ];
}
```

### 4. Transform Response Data in hook_response_data()

Clean up data before sending to client:

```php
protected function hook_response_data($method, $obj, &$response = null, &$data = null)
{
    // Remove sensitive fields
    unset($response['internal_notes']);
    unset($response['cost']);

    // Add computed fields
    $response['display_name'] = $obj->first_name . ' ' . $obj->last_name;
}
```

### 5. Use Meaningful Exception Messages

Provide clear error messages:

```php
// GOOD
throw new \Exception("Email address is already registered");

// BAD
throw new \Exception("Error");
```

### 6. Don't Modify Core Methods

Override hooks, don't rewrite core methods:

```php
// GOOD - Override hook
protected function hook_preprocess($method, &$data, &$obj = null)
{
    // Custom logic
}

// BAD - Override core method
public function new($data)
{
    // Don't do this unless absolutely necessary
}
```

### 7. Use Type Checking

Check types before operations:

```php
protected function hook_preprocess($method, &$data, &$obj = null)
{
    if (isset($data['age']) && !is_numeric($data['age'])) {
        throw new \Exception("Age must be a number");
    }
}
```

### 8. Document Complex Logic

Add comments for complex business rules:

```php
protected function hook_preprocess($method, &$data, &$obj = null)
{
    if ($method === 'update' && isset($data['status'])) {
        // Business rule: Only admins can change status to 'approved'
        if ($data['status'] === 'approved' && $this->api->user->role !== 'admin') {
            throw new \Exception("Only administrators can approve records");
        }
    }
}
```

---

## Summary

### Key Concepts

1. **Controllers** handle HTTP requests and orchestrate business logic
2. **ModelController** provides default CRUD operations
3. **Hooks** allow you to customize behavior at specific points
4. **Properties** control default behaviors

### Common Hooks

| Hook | When Used | Purpose |
|------|-----------|---------|
| `hook_init()` | During initialization | Configure controller |
| `hook_auth()` | After authentication | Custom auth logic |
| `hook_prequery()` | Before queries | Modify query parameters |
| `hook_preprocess()` | Before save | Validate/transform data |
| `hook_response_data()` | After operations | Modify response |
| `hook_process_get_response()` | After GET | Batch process results |

### Controller Lifecycle

```
Request → Controller Init → hook_init() → Authentication → hook_auth()
  → Operation Method (new/get/update/delete)
    → hook_prequery() (for get/update/delete)
    → hook_preprocess() (for new/update)
    → Database Operation
    → hook_response_data()
    → hook_process_get_response() (for get)
  → Response
```

Next, read the [AWS Integration Guide](04-aws-integration.md) to learn how to use AWS services in your controllers.
