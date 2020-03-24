# Kyte-PHP

## About Kyte-PHP
Web application development shouldn't have to be a chore.  Kyte was created with the intention to make things more enjoyable and to simplify the development workflow.  The Kyte-php framework works as the backend API and can be integrated into different types of application architectures and front-end languages/frameworks.

## Getting Started

### Configuration

### Models
Models are defined in the `/models` directory of the framework.  Models are defined as associative arrays and follows the following format:
```
$ModelName = [
	'name' => 'ModelName',          // must correspond with the table name in the database
	'struct' => [
		'id'			=> [        // required field and must correspond with the column name of table
			'type'		=> 'i',     // availble types are i, s, d.
			'required'	=> false,
			'date'		=> false,
		],

		'data1'		=> [            // must correspond with the column name of table
			'type'		=> 's',
			'required'	=> true,
			'date'		=> false,
		],

		'data2'	=> [                // must correspond with the column name of table
			'type'		=> 's',
			'required'	=> true,
			'date'		=> false,
		],

		'data3'	=> [                // must correspond with the column name of table
			'type'		=> 's',
			'required'	=> true,
			'date'		=> false,
		],

		'deleted'	=> [            // required field and must correspond with the column name of table
			'type'		=> 'i',
			'required'	=> false,
			'date'		=> false,
		],
	],
];
```
All models must have the fields `id` and `deleted` to work with the Kyte-PHP framework.

### Controllers

### "Abstract" Controllers or View Controllers
For data that may have unique requirements and complex relations, an abstract controller can be created to manipulate the data and update one or more models.  "Abstract" or View controllers do not need to have a model and can act as standalone controllers that directly process and return data, such as the built-in MailController.  View Controllers are similar to virtual tables or views in traditional relational databases, such as Oracle or MySQL.  View Controllers are created just like any other controller and extends the `ModelController` class and must override all class methods.  View Controllers are called using the same URL syntax where the model is replaced by the View Controller's "view" name.  For example, for the `MailController`, the model name is `Mail`, even though no model named `Mail` exists.  The API router will recongize that the requested resource is a View Controller and correctly route the call.
