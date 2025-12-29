# Model Definition Guide

## Table of Contents
1. [What is a Model?](#what-is-a-model)
2. [Model Structure](#model-structure)
3. [Field Types](#field-types)
4. [Field Attributes](#field-attributes)
5. [Special Features](#special-features)
6. [Complete Examples](#complete-examples)
7. [Best Practices](#best-practices)

---

## What is a Model?

In Kyte-PHP, a **model** is a PHP array that defines the structure of a database table. Think of it as a blueprint that tells the framework:
- What the table is named
- What columns (fields) the table has
- What type of data each column can hold
- What rules apply to each column

### Why Use Models?

Models serve as a contract between your code and your database. They:
- Ensure data consistency
- Provide automatic type validation
- Generate database tables automatically
- Enable foreign key relationships
- Support advanced features like encryption and date formatting

---

## Model Structure

Every model in Kyte-PHP is a PHP array with two main parts:

```php
$MyModel = [
    'name' => 'TableName',    // The database table name
    'struct' => [             // The structure (columns) of the table
        // ... column definitions go here
    ]
];
```

### Basic Example

Here's a simple model for a `Blog` table:

```php
$Blog = [
    'name' => 'Blog',
    'struct' => [
        'title' => [
            'type' => 's',        // String type
            'required' => true,   // This field is required
            'size' => 255,        // Maximum length
            'date' => false,      // Not a date field
        ],
        'content' => [
            'type' => 't',        // Text type
            'required' => true,
            'date' => false,
        ],
        'published' => [
            'type' => 'i',        // Integer type
            'required' => false,
            'date' => false,
            'default' => 0,       // Default value
        ],
    ]
];
```

---

## Field Types

Kyte-PHP supports various field types that map to MySQL column types:

| Kyte Type | MySQL Type | Description | Example Use Case |
|-----------|-----------|-------------|------------------|
| `s` | VARCHAR | Short text strings | Names, emails, titles |
| `i` | INT | Regular integers | IDs, counts, flags |
| `bi` | BIGINT | Large integers | Timestamps, large IDs |
| `d` | DECIMAL | Decimal numbers | Prices, percentages |
| `t` | TEXT | Long text (65KB) | Articles, descriptions |
| `tt` | TINYTEXT | Tiny text (255 bytes) | Short notes |
| `mt` | MEDIUMTEXT | Medium text (16MB) | Large articles |
| `lt` | LONGTEXT | Long text (4GB) | Very large content |
| `b` | BLOB | Binary data | Files, images (rarely used) |
| `tb` | TINYBLOB | Tiny binary | Small binary data |
| `mb` | MEDIUMBLOB | Medium binary | Medium binary files |
| `lb` | LONGBLOB | Large binary | Large binary files |

### Choosing the Right Type

**For text:**
- Use `s` (string) for short text that needs indexing (names, emails)
- Use `t` (text) for longer content that won't be searched (descriptions, content)

**For numbers:**
- Use `i` (integer) for most whole numbers
- Use `bi` (big integer) for Unix timestamps or very large numbers
- Use `d` (decimal) when you need precision (money, percentages)

**For binary data:**
- Prefer storing files on S3 and storing the URL in a string field
- Only use blob types for small binary data that must be in the database

---

## Field Attributes

Each field in your model can have multiple attributes:

### Required Attributes

These attributes **must** be present for every field:

#### `type` (required)
The data type of the field (see Field Types above).

```php
'username' => [
    'type' => 's',  // String
    // ...
]
```

#### `required` (required)
Whether the field must have a value when creating a new record.

```php
'email' => [
    'type' => 's',
    'required' => true,  // Must be provided
    // ...
]
```

#### `date` (required)
Whether this field represents a date/time value. If `true`, Kyte will automatically format it.

```php
'birth_date' => [
    'type' => 'i',
    'required' => false,
    'date' => true,  // This is a date field (stored as Unix timestamp)
]
```

### Optional Attributes

These attributes are optional but provide important functionality:

#### `size`
The maximum length for string fields (VARCHAR size in MySQL).

```php
'username' => [
    'type' => 's',
    'required' => true,
    'size' => 50,  // Maximum 50 characters
    'date' => false,
]
```

#### `default`
The default value if none is provided.

```php
'status' => [
    'type' => 's',
    'required' => false,
    'size' => 20,
    'default' => 'active',  // Defaults to 'active'
    'date' => false,
]
```

#### `unsigned`
For integer fields, whether the number can be negative. Setting to `true` allows larger positive numbers.

```php
'age' => [
    'type' => 'i',
    'required' => true,
    'unsigned' => true,  // Can't be negative
    'date' => false,
]
```

#### `protected`
Marks sensitive fields that should not be returned in API responses (like passwords).

```php
'password' => [
    'type' => 's',
    'required' => true,
    'size' => 255,
    'protected' => true,  // Will be excluded from API responses
    'date' => false,
]
```

#### `password`
Marks a field as a password field. Kyte will automatically hash it before storing.

```php
'password' => [
    'type' => 's',
    'required' => true,
    'size' => 255,
    'protected' => true,
    'password' => true,  // Will be automatically hashed
    'date' => false,
]
```

#### `pk`
Marks a field as the primary key. Usually you don't need this as Kyte creates an `id` field automatically.

```php
'id' => [
    'type' => 'i',
    'required' => false,
    'pk' => true,  // Primary key
    'unsigned' => true,
    'date' => false,
]
```

#### `precision` and `scale`
For decimal fields, defines total digits and decimal places.

```php
'price' => [
    'type' => 'd',
    'required' => true,
    'precision' => 10,  // Total digits
    'scale' => 2,       // Decimal places (e.g., 12345678.90)
    'date' => false,
]
```

#### `kms`
If `true`, the field will be encrypted using AWS KMS before storing.

```php
'ssn' => [
    'type' => 's',
    'required' => false,
    'size' => 500,
    'kms' => true,  // Will be encrypted with AWS KMS
    'date' => false,
]
```

#### `dateformat`
Custom date format for this specific field (overrides global format).

```php
'event_date' => [
    'type' => 'i',
    'required' => true,
    'date' => true,
    'dateformat' => 'm/d/Y',  // Custom format: 12/31/2024
]
```

---

## Special Features

### Foreign Keys

Foreign keys create relationships between models. When you define a foreign key, Kyte can automatically load related data.

```php
$Comment = [
    'name' => 'Comment',
    'struct' => [
        'text' => [
            'type' => 't',
            'required' => true,
            'date' => false,
        ],
        'user_id' => [
            'type' => 'i',
            'required' => true,
            'unsigned' => true,
            'date' => false,
            'fk' => [
                'model' => 'User',      // Name of related model
                'field' => 'id',        // Field in related model
            ],
        ],
    ]
];
```

**How it works:**
When you retrieve a Comment with foreign key loading enabled, the `user_id` field will contain the entire User object instead of just the ID.

### External Tables

External tables define reverse relationships (one-to-many).

```php
$Blog = [
    'name' => 'Blog',
    'struct' => [
        'title' => [
            'type' => 's',
            'required' => true,
            'size' => 255,
            'date' => false,
        ],
    ],
    'externalTables' => [
        [
            'model' => 'Comment',      // Related model
            'field' => 'blog_id',      // Field in Comment that references this Blog
        ],
    ]
];
```

**How it works:**
When you retrieve a Blog with external tables enabled, you'll get all related Comments in an `ExternalTables` array.

### Audit Fields

Kyte automatically adds these fields to every model:
- `id` - Auto-incrementing primary key
- `deleted` - Soft delete flag (0 = active, 1 = deleted)
- `date_created` - Unix timestamp of creation
- `created_by` - User ID who created the record
- `date_modified` - Unix timestamp of last modification
- `modified_by` - User ID who last modified
- `date_deleted` - Unix timestamp when deleted
- `deleted_by` - User ID who deleted

You don't need to define these in your model; they're added automatically.

---

## Complete Examples

### Example 1: User Model

A complete user model with various field types:

```php
$User = [
    'name' => 'User',
    'struct' => [
        'first_name' => [
            'type' => 's',
            'required' => true,
            'size' => 100,
            'date' => false,
        ],
        'last_name' => [
            'type' => 's',
            'required' => true,
            'size' => 100,
            'date' => false,
        ],
        'email' => [
            'type' => 's',
            'required' => true,
            'size' => 255,
            'date' => false,
        ],
        'password' => [
            'type' => 's',
            'required' => true,
            'size' => 255,
            'protected' => true,
            'password' => true,
            'date' => false,
        ],
        'phone' => [
            'type' => 's',
            'required' => false,
            'size' => 20,
            'date' => false,
        ],
        'birth_date' => [
            'type' => 'i',
            'required' => false,
            'date' => true,
        ],
        'status' => [
            'type' => 's',
            'required' => false,
            'size' => 20,
            'default' => 'active',
            'date' => false,
        ],
        'lastLogin' => [
            'type' => 'i',
            'required' => false,
            'date' => true,
        ],
    ]
];
```

### Example 2: Product Model with Decimals

A product model with pricing:

```php
$Product = [
    'name' => 'Product',
    'struct' => [
        'name' => [
            'type' => 's',
            'required' => true,
            'size' => 255,
            'date' => false,
        ],
        'description' => [
            'type' => 't',
            'required' => false,
            'date' => false,
        ],
        'price' => [
            'type' => 'd',
            'required' => true,
            'precision' => 10,
            'scale' => 2,
            'date' => false,
        ],
        'stock_quantity' => [
            'type' => 'i',
            'required' => true,
            'unsigned' => true,
            'default' => 0,
            'date' => false,
        ],
        'sku' => [
            'type' => 's',
            'required' => true,
            'size' => 50,
            'date' => false,
        ],
    ]
];
```

### Example 3: Order Model with Relationships

An order model with foreign keys and external tables:

```php
$Order = [
    'name' => 'Order',
    'struct' => [
        'order_number' => [
            'type' => 's',
            'required' => true,
            'size' => 50,
            'date' => false,
        ],
        'user_id' => [
            'type' => 'i',
            'required' => true,
            'unsigned' => true,
            'date' => false,
            'fk' => [
                'model' => 'User',
                'field' => 'id',
            ],
        ],
        'total_amount' => [
            'type' => 'd',
            'required' => true,
            'precision' => 10,
            'scale' => 2,
            'date' => false,
        ],
        'status' => [
            'type' => 's',
            'required' => true,
            'size' => 50,
            'default' => 'pending',
            'date' => false,
        ],
        'order_date' => [
            'type' => 'i',
            'required' => true,
            'date' => true,
        ],
    ],
    'externalTables' => [
        [
            'model' => 'OrderItem',
            'field' => 'order_id',
        ],
    ]
];
```

---

## Best Practices

### 1. Always Use Required Attributes

Every field must have `type`, `required`, and `date` attributes:

```php
// GOOD
'username' => [
    'type' => 's',
    'required' => true,
    'date' => false,
]

// BAD - missing 'date' attribute
'username' => [
    'type' => 's',
    'required' => true,
]
```

### 2. Choose Appropriate Field Sizes

Don't use excessively large sizes:

```php
// GOOD - reasonable size
'username' => [
    'type' => 's',
    'required' => true,
    'size' => 50,
    'date' => false,
]

// BAD - unnecessarily large
'username' => [
    'type' => 's',
    'required' => true,
    'size' => 10000,  // Too big!
    'date' => false,
]
```

### 3. Use Protected for Sensitive Data

Always mark passwords and sensitive data as protected:

```php
'password' => [
    'type' => 's',
    'required' => true,
    'size' => 255,
    'protected' => true,  // Won't appear in API responses
    'password' => true,   // Will be auto-hashed
    'date' => false,
]
```

### 4. Use Unsigned for IDs and Positive Numbers

This allows for larger values:

```php
'age' => [
    'type' => 'i',
    'required' => true,
    'unsigned' => true,  // Can't be negative, allows larger positive values
    'date' => false,
]
```

### 5. Set Sensible Defaults

Provide defaults for optional fields when it makes sense:

```php
'status' => [
    'type' => 's',
    'required' => false,
    'size' => 20,
    'default' => 'active',  // Sensible default
    'date' => false,
]
```

### 6. Use Foreign Keys for Relationships

Instead of just storing an ID, define the relationship:

```php
// GOOD - defines relationship
'user_id' => [
    'type' => 'i',
    'required' => true,
    'unsigned' => true,
    'date' => false,
    'fk' => [
        'model' => 'User',
        'field' => 'id',
    ],
]

// OKAY - but no automatic loading
'user_id' => [
    'type' => 'i',
    'required' => true,
    'unsigned' => true,
    'date' => false,
]
```

### 7. Consistent Naming Conventions

Use consistent naming for foreign keys:

```php
// GOOD - clear naming
'user_id' => [
    'type' => 'i',
    'required' => true,
    'unsigned' => true,
    'date' => false,
    'fk' => [
        'model' => 'User',
        'field' => 'id',
    ],
]

// AVOID - unclear naming
'usr' => [  // Not clear
    'type' => 'i',
    'required' => true,
    'unsigned' => true,
    'date' => false,
]
```

### 8. Store Dates as Unix Timestamps

Always use integer type with `date => true` for dates:

```php
// GOOD - Unix timestamp
'event_date' => [
    'type' => 'i',
    'required' => true,
    'date' => true,
]

// AVOID - string dates are harder to work with
'event_date' => [
    'type' => 's',
    'required' => true,
    'size' => 50,
    'date' => false,
]
```

---

## Summary

A Kyte model is simply a PHP array that defines:
- The **table name** in the `name` key
- The **column structure** in the `struct` key
- Each column's **attributes** (type, required, date, etc.)
- Optional **relationships** (foreign keys, external tables)

With this structure, Kyte can automatically:
- Create database tables
- Validate data
- Enforce relationships
- Format dates
- Protect sensitive data
- Hash passwords
- Encrypt fields

Next, read the [Models and ModelObjects Guide](02-models-and-modelobjects.md) to learn how to use these definitions in your code.
