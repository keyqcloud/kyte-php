# Models and ModelObjects Guide

## Table of Contents
1. [Understanding the Difference](#understanding-the-difference)
2. [ModelObject: Single Records](#modelobject-single-records)
3. [Model: Multiple Records](#model-multiple-records)
4. [CRUD Operations](#crud-operations)
5. [Advanced Queries](#advanced-queries)
6. [Working with Data](#working-with-data)
7. [Complete Examples](#complete-examples)

---

## Understanding the Difference

Kyte-PHP has two classes for working with database data:

### ModelObject (`\Kyte\Core\ModelObject`)
- Represents a **single database record**
- Use for: Creating one item, updating one item, getting one specific item
- Think of it as: One row in a table

### Model (`\Kyte\Core\Model`)
- Represents **multiple database records**
- Use for: Getting lists of items, searching, filtering
- Think of it as: Multiple rows in a table

### Quick Comparison

| Operation | Use This | Example |
|-----------|----------|---------|
| Create a new user | ModelObject | `$user->create($data)` |
| Update one user | ModelObject | `$user->save($data)` |
| Get one specific user | ModelObject | `$user->retrieve('email', 'john@example.com')` |
| Get all users | Model | `$users->retrieve()` |
| Search for users | Model | `$users->retrieve('status', 'active')` |

---

## ModelObject: Single Records

### Creating a ModelObject

To work with a single record, first create a ModelObject instance:

```php
// Assuming you have a User model defined as a constant
$user = new \Kyte\Core\ModelObject(constant('User'));

// Or if the model is stored in a variable
$userModel = [
    'name' => 'User',
    'struct' => [ /* ... */ ]
];
$user = new \Kyte\Core\ModelObject($userModel);
```

### Create Operation (INSERT)

Create a new record in the database:

```php
$user = new \Kyte\Core\ModelObject(constant('User'));

$data = [
    'first_name' => 'John',
    'last_name' => 'Doe',
    'email' => 'john@example.com',
    'password' => 'securePassword123',
];

try {
    $user->create($data);
    echo "User created with ID: " . $user->id;
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage();
}
```

**What happens:**
1. Validates that all required fields are present
2. Automatically adds audit fields (date_created, created_by, deleted)
3. Inserts the record into the database
4. Populates the ModelObject with the new record's data (including ID)

**Optional user parameter:**
```php
// Pass user ID to track who created the record
$user->create($data, $currentUserId);
```

### Retrieve Operation (SELECT)

Retrieve an existing record:

```php
$user = new \Kyte\Core\ModelObject(constant('User'));

// Retrieve by email
if ($user->retrieve('email', 'john@example.com')) {
    echo "Found user: " . $user->first_name . " " . $user->last_name;
} else {
    echo "User not found";
}

// Retrieve by ID
if ($user->retrieve('id', 123)) {
    echo "Found user with ID 123";
}
```

**Method signature:**
```php
retrieve($field, $value, $conditions = null, $id = null, $all = false)
```

**Parameters:**
- `$field` - The column name to search by
- `$value` - The value to search for
- `$conditions` - Additional conditions (see Advanced Queries)
- `$id` - (deprecated) Direct ID lookup
- `$all` - If true, includes soft-deleted records

**Using conditions:**
```php
$user = new \Kyte\Core\ModelObject(constant('User'));

// Find user by email AND status
$conditions = [
    ['field' => 'status', 'value' => 'active']
];

if ($user->retrieve('email', 'john@example.com', $conditions)) {
    echo "Found active user";
}
```

### Update Operation (UPDATE)

Update an existing record:

```php
$user = new \Kyte\Core\ModelObject(constant('User'));

// First, retrieve the record
if ($user->retrieve('id', 123)) {
    // Update fields
    $updates = [
        'first_name' => 'Jane',
        'status' => 'inactive',
    ];

    try {
        $user->save($updates);
        echo "User updated successfully";
    } catch (\Exception $e) {
        echo "Error: " . $e->getMessage();
    }
} else {
    echo "User not found";
}
```

**Important:** You must retrieve a record before you can save it!

**What happens:**
1. Validates the record has been retrieved (has an ID)
2. Automatically adds audit fields (date_modified, modified_by)
3. Updates only the fields you specify
4. Refreshes the ModelObject with updated data

**Optional user parameter:**
```php
// Track who modified the record
$user->save($updates, $currentUserId);
```

### Delete Operation (Soft Delete)

Mark a record as deleted (doesn't actually remove it):

```php
$user = new \Kyte\Core\ModelObject(constant('User'));

// Option 1: Retrieve first, then delete
if ($user->retrieve('id', 123)) {
    $user->delete(null, null, $currentUserId);
    echo "User deleted";
}

// Option 2: Delete directly by field and value
$user->delete('email', 'john@example.com', $currentUserId);
```

**What happens:**
1. Sets `deleted` flag to 1
2. Sets `date_deleted` to current timestamp
3. Sets `deleted_by` to the provided user ID
4. Record still exists in database but is hidden from normal queries

### Purge Operation (Hard Delete)

Permanently remove a record from the database:

```php
$user = new \Kyte\Core\ModelObject(constant('User'));

// CAUTION: This permanently deletes the record!
if ($user->retrieve('id', 123, null, null, true)) {  // Note: $all = true
    $user->purge();
    echo "User permanently deleted";
}
```

**Warning:** Use purge sparingly! Soft deletes (delete()) are usually preferred because they:
- Allow you to recover data
- Maintain referential integrity
- Keep audit trails

---

## Model: Multiple Records

### Creating a Model

To work with multiple records, create a Model instance:

```php
// Basic - get all records
$users = new \Kyte\Core\Model(constant('User'));

// With pagination
$users = new \Kyte\Core\Model(
    constant('User'),
    $pageSize = 50,    // Records per page
    $pageNum = 1       // Current page
);

// With search
$users = new \Kyte\Core\Model(
    constant('User'),
    $pageSize = 50,
    $pageNum = 1,
    $searchFields = 'first_name,last_name,email',  // Searchable fields
    $searchValue = 'john'                          // Search term
);
```

### Retrieve Multiple Records

Get a collection of records:

```php
$users = new \Kyte\Core\Model(constant('User'));

// Get all active users
$users->retrieve();

// Loop through results
foreach ($users->objects as $user) {
    echo $user->first_name . " " . $user->last_name . "\n";
}

// Check counts
echo "Total records: " . $users->total . "\n";
echo "Filtered records: " . $users->total_filtered . "\n";
```

**Method signature:**
```php
retrieve($field = null, $value = null, $isLike = false, $conditions = null, $all = false, $order = null, $limit = null)
```

**Parameters:**
- `$field` - Column to search by
- `$value` - Value to search for
- `$isLike` - If true, uses LIKE instead of = (for partial matches)
- `$conditions` - Additional conditions
- `$all` - If true, includes soft-deleted records
- `$order` - Sorting options
- `$limit` - Maximum number of records

### Basic Filtering

```php
$users = new \Kyte\Core\Model(constant('User'));

// Get users with a specific status
$users->retrieve('status', 'active');

foreach ($users->objects as $user) {
    echo $user->email . "\n";
}
```

### LIKE Search

Use partial matching:

```php
$users = new \Kyte\Core\Model(constant('User'));

// Find all users whose email contains 'gmail'
$users->retrieve('email', 'gmail', $isLike = true);

foreach ($users->objects as $user) {
    echo $user->email . "\n";
}
```

### Advanced Conditions

Add multiple conditions:

```php
$users = new \Kyte\Core\Model(constant('User'));

$conditions = [
    ['field' => 'status', 'value' => 'active'],
    ['field' => 'age', 'value' => '18', 'operator' => '>='],
];

$users->retrieve(null, null, false, $conditions);

foreach ($users->objects as $user) {
    echo $user->first_name . " - Age: " . $user->age . "\n";
}
```

**Available operators:**
- `=` (default if not specified)
- `>`
- `<`
- `>=`
- `<=`
- `!=`
- `LIKE`

### Ordering Results

Sort your results:

```php
$users = new \Kyte\Core\Model(constant('User'));

$order = [
    ['field' => 'last_name', 'direction' => 'ASC'],
    ['field' => 'first_name', 'direction' => 'ASC'],
];

$users->retrieve(null, null, false, null, false, $order);

foreach ($users->objects as $user) {
    echo $user->last_name . ", " . $user->first_name . "\n";
}
```

### Pagination

Handle large datasets with pagination:

```php
$pageSize = 25;
$pageNum = 1;  // First page

$users = new \Kyte\Core\Model(constant('User'), $pageSize, $pageNum);
$users->retrieve('status', 'active');

echo "Showing page $pageNum of " . ceil($users->total_filtered / $pageSize) . "\n";
echo "Total users: " . $users->total . "\n";
echo "Filtered users: " . $users->total_filtered . "\n";

foreach ($users->objects as $user) {
    echo $user->email . "\n";
}
```

---

## CRUD Operations

### Complete CRUD Example

Here's a complete example showing Create, Read, Update, Delete:

```php
// ========== CREATE ==========
$user = new \Kyte\Core\ModelObject(constant('User'));

$newUserData = [
    'first_name' => 'Alice',
    'last_name' => 'Smith',
    'email' => 'alice@example.com',
    'password' => 'securePassword',
    'status' => 'active',
];

try {
    $user->create($newUserData);
    $userId = $user->id;
    echo "Created user with ID: $userId\n";
} catch (\Exception $e) {
    die("Error creating user: " . $e->getMessage());
}

// ========== READ ==========
$user = new \Kyte\Core\ModelObject(constant('User'));

if ($user->retrieve('id', $userId)) {
    echo "Found: " . $user->first_name . " " . $user->last_name . "\n";
    echo "Email: " . $user->email . "\n";
    echo "Status: " . $user->status . "\n";
} else {
    die("User not found");
}

// ========== UPDATE ==========
$updates = [
    'status' => 'premium',
    'last_name' => 'Johnson',
];

try {
    $user->save($updates);
    echo "User updated\n";
    echo "New status: " . $user->status . "\n";
    echo "New last name: " . $user->last_name . "\n";
} catch (\Exception $e) {
    die("Error updating user: " . $e->getMessage());
}

// ========== DELETE (Soft) ==========
try {
    $user->delete();
    echo "User deleted (soft delete)\n";
} catch (\Exception $e) {
    die("Error deleting user: " . $e->getMessage());
}

// ========== LIST ALL ==========
$users = new \Kyte\Core\Model(constant('User'));
$users->retrieve('status', 'active');

echo "\nActive users:\n";
foreach ($users->objects as $u) {
    echo "- " . $u->first_name . " " . $u->last_name . "\n";
}
```

---

## Advanced Queries

### Complex Conditions Example

```php
$users = new \Kyte\Core\Model(constant('User'));

// Find users who are:
// - Active
// - Age 21 or older
// - Created in the last 30 days
$thirtyDaysAgo = time() - (30 * 24 * 60 * 60);

$conditions = [
    ['field' => 'status', 'value' => 'active'],
    ['field' => 'age', 'value' => '21', 'operator' => '>='],
    ['field' => 'date_created', 'value' => $thirtyDaysAgo, 'operator' => '>='],
];

$order = [
    ['field' => 'date_created', 'direction' => 'DESC'],
];

$users->retrieve(null, null, false, $conditions, false, $order);

echo "Found " . count($users->objects) . " users\n";
```

### Search Across Multiple Fields

```php
$pageSize = 50;
$pageNum = 1;
$searchFields = 'first_name,last_name,email';
$searchValue = 'john';

$users = new \Kyte\Core\Model(
    constant('User'),
    $pageSize,
    $pageNum,
    $searchFields,
    $searchValue
);

// This will find any user where first_name, last_name, OR email contains 'john'
$users->retrieve();

foreach ($users->objects as $user) {
    echo $user->first_name . " " . $user->last_name . " <" . $user->email . ">\n";
}
```

---

## Working with Data

### Accessing Object Properties

Once you retrieve a ModelObject, access its fields as properties:

```php
$user = new \Kyte\Core\ModelObject(constant('User'));

if ($user->retrieve('id', 123)) {
    // Access properties directly
    echo $user->first_name;
    echo $user->last_name;
    echo $user->email;
    echo $user->id;

    // Check if property exists
    if (isset($user->phone)) {
        echo $user->phone;
    }
}
```

### Getting All Parameters

Get all data as an array:

```php
$user = new \Kyte\Core\ModelObject(constant('User'));
$user->retrieve('id', 123);

// Get all parameters as array
$allData = $user->getAllParams();

// With date formatting
$dateFormat = 'Y-m-d H:i:s';
$allDataFormatted = $user->getAllParams($dateFormat);

print_r($allDataFormatted);
```

### Getting Specific Parameters

Get only certain fields:

```php
$user = new \Kyte\Core\ModelObject(constant('User'));
$user->retrieve('id', 123);

// Get specific fields
$fields = ['first_name', 'last_name', 'email'];
$data = $user->getParams($fields);

// Result: ['first_name' => 'John', 'last_name' => 'Doe', 'email' => 'john@example.com']
```

### Getting Single Parameter

```php
$user = new \Kyte\Core\ModelObject(constant('User'));
$user->retrieve('id', 123);

// Get single field value
$email = $user->getParam('email');

if ($email !== false) {
    echo "Email: $email";
}
```

### Getting Parameter Keys

Get list of all available fields:

```php
$user = new \Kyte\Core\ModelObject(constant('User'));
$user->retrieve('id', 123);

$keys = $user->paramKeys();
// Result: ['id', 'first_name', 'last_name', 'email', 'date_created', ...]
```

---

## Complete Examples

### Example 1: User Registration System

```php
function registerUser($email, $password, $firstName, $lastName) {
    // Check if email already exists
    $existingUser = new \Kyte\Core\ModelObject(constant('User'));
    if ($existingUser->retrieve('email', $email)) {
        throw new \Exception("Email already registered");
    }

    // Create new user
    $user = new \Kyte\Core\ModelObject(constant('User'));

    $data = [
        'email' => $email,
        'password' => $password,  // Will be automatically hashed if 'password' => true in model
        'first_name' => $firstName,
        'last_name' => $lastName,
        'status' => 'active',
    ];

    try {
        $user->create($data);
        return $user->id;
    } catch (\Exception $e) {
        throw new \Exception("Failed to register user: " . $e->getMessage());
    }
}

// Usage
try {
    $userId = registerUser('alice@example.com', 'securePass123', 'Alice', 'Johnson');
    echo "User registered with ID: $userId";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage();
}
```

### Example 2: Product Inventory System

```php
// Add new product
function addProduct($name, $description, $price, $stockQuantity) {
    $product = new \Kyte\Core\ModelObject(constant('Product'));

    $data = [
        'name' => $name,
        'description' => $description,
        'price' => $price,
        'stock_quantity' => $stockQuantity,
        'sku' => 'SKU-' . strtoupper(substr(md5(uniqid()), 0, 10)),
    ];

    $product->create($data);
    return $product->id;
}

// Update stock
function updateStock($productId, $quantity) {
    $product = new \Kyte\Core\ModelObject(constant('Product'));

    if (!$product->retrieve('id', $productId)) {
        throw new \Exception("Product not found");
    }

    $product->save(['stock_quantity' => $quantity]);
}

// Get low stock products
function getLowStockProducts($threshold = 10) {
    $products = new \Kyte\Core\Model(constant('Product'));

    $conditions = [
        ['field' => 'stock_quantity', 'value' => $threshold, 'operator' => '<'],
    ];

    $products->retrieve(null, null, false, $conditions);

    return $products->objects;
}

// Usage
$productId = addProduct('Widget', 'A useful widget', 19.99, 100);
updateStock($productId, 5);

$lowStock = getLowStockProducts(10);
foreach ($lowStock as $product) {
    echo "{$product->name}: Only {$product->stock_quantity} left!\n";
}
```

### Example 3: Blog Post System

```php
// Create blog post
function createPost($title, $content, $authorId) {
    $post = new \Kyte\Core\ModelObject(constant('BlogPost'));

    $data = [
        'title' => $title,
        'content' => $content,
        'author_id' => $authorId,
        'status' => 'draft',
        'published_date' => null,
    ];

    $post->create($data);
    return $post->id;
}

// Publish post
function publishPost($postId) {
    $post = new \Kyte\Core\ModelObject(constant('BlogPost'));

    if (!$post->retrieve('id', $postId)) {
        throw new \Exception("Post not found");
    }

    $post->save([
        'status' => 'published',
        'published_date' => time(),
    ]);
}

// Get published posts with pagination
function getPublishedPosts($page = 1, $pageSize = 10) {
    $posts = new \Kyte\Core\Model(constant('BlogPost'), $pageSize, $page);

    $conditions = [
        ['field' => 'status', 'value' => 'published'],
    ];

    $order = [
        ['field' => 'published_date', 'direction' => 'DESC'],
    ];

    $posts->retrieve(null, null, false, $conditions, false, $order);

    return [
        'posts' => $posts->objects,
        'total' => $posts->total_filtered,
        'pages' => ceil($posts->total_filtered / $pageSize),
    ];
}

// Search posts
function searchPosts($searchTerm, $page = 1) {
    $posts = new \Kyte\Core\Model(
        constant('BlogPost'),
        10,  // Page size
        $page,
        'title,content',  // Search in title and content
        $searchTerm
    );

    $conditions = [
        ['field' => 'status', 'value' => 'published'],
    ];

    $posts->retrieve(null, null, false, $conditions);

    return $posts->objects;
}

// Usage
$postId = createPost('My First Post', 'This is the content...', 1);
publishPost($postId);

$result = getPublishedPosts(1, 10);
echo "Found {$result['total']} posts across {$result['pages']} pages\n";

$searchResults = searchPosts('widget');
foreach ($searchResults as $post) {
    echo $post->title . "\n";
}
```

---

## Summary

### Use ModelObject when you need to:
- Create a single record
- Update a specific record
- Retrieve one specific record
- Delete one record

### Use Model when you need to:
- Get multiple records
- Search and filter
- Paginate results
- Get counts and totals

### Key Methods

**ModelObject:**
- `create($data, $user = null)` - Create new record
- `retrieve($field, $value, $conditions = null, $id = null, $all = false)` - Get one record
- `save($data, $user = null)` - Update record
- `delete($field = null, $value = null, $user = null)` - Soft delete
- `purge($field = null, $value = null)` - Hard delete
- `getAllParams($dateformat = null)` - Get all data as array
- `getParam($key)` - Get single field value

**Model:**
- `retrieve($field = null, $value = null, $isLike = false, $conditions = null, $all = false, $order = null, $limit = null)` - Get multiple records
- `$model->objects` - Array of ModelObjects
- `$model->total` - Total record count
- `$model->total_filtered` - Filtered record count

Next, read the [Controllers Guide](03-controllers.md) to learn how to use ModelObject and Model in your API controllers.
