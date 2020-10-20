# Kyte-PHP
![GitHub Logo](https://www.keyq.cloud/img/kytelogo_dark.png)

(c) 2020 [KeyQ, Inc.](https://www.keyq.cloud)

## About Kyte-PHP
Web application development shouldn't have to be a chore.  Kyte was created with the intention to make things more enjoyable and to simplify the development workflow.  The Kyte-php framework works as the backend API and can be integrated into different types of application architectures and front-end languages/frameworks.

## Getting Started
Coming soon

### Configuration
Coming soon

### Calling the API
The first step to calling the API is requesting a signature.  The signature can be generated on the server-side or client-side, or alternatively requested through the Kyte framework.  Requesting a signature from the Kyte framework may be convenient when the client-side web interface may not have access to crytpogrphic libraries necessary for generating a signature, or when hosting as a static website in a cloud storage place like the AWS S3.

To request a signature from Kyte, simply submit a get request in the following format:
GET      `/{key}/{time}/{identifier}`

The response is json formated with `signature` containing the hash necessary for making API calls.  Once a signature has been generated or obtained, the following HTTP method and URL format can be used to call the API successfully.
 * POST     `/{token}/{key}/{signature}/{time}/{model}`
 * PUT      `/{token}/{key}/{signature}/{time}/{model}/{field}/{value}`
 * GET      `/{token}/{key}/{signature}/{time}/{model}/{field}/{value}`
 * DELETE   `/{token}/{key}/{signature}/{time}/{model}/{field}/{value}`

Each component in the URL is defined below:
* `token` is the session token that is stored as a cookie.
* `key` is the public access key.
* `signature` is the API signature returned from the Kyte API, which is used to validate all API requests.
* `time` is the UTC time used to sign the signature for API validation.
* `model` is the name of the model or view controller's virtual model being called.
* `field` is the name of the model field.
* `value` is the value of the model field.

### Models
Models are defined in the `/models` directory of the framework.  Models are defined as associative arrays and follows the following format:
```
$ModelName = [
	'name' => 'ModelName',          // must correspond with the table name in the database
	'struct' => [
		'field1'		=> [...attributes...],

		'field2'	=> [...attributes...],

		'field3'	=> [...attributes...],
	],
];
```


### Model Attributes
The following are allowed model attributes used when declaring a field - some are required.

* `type: {s/i/d/t}` - defines field type (currently supports s for varchar, i for int, d for decimal, and t for text)
* `required: {true/false}` - flag for if field is required, i.e. cannot be null
* `size: {int}` - defines size of field for varchar and int
* `precision: {int}` - defines precision for decimal
* `scale: {int}` - defines scale for decimal
* `date: {true/false}` - flag for if field is date time
* `protected: {true/false}` - flag for if field should not be returned in response data, i.e. passwords and hashes
* `dateformat: {ex: YYYY/MM/DD H:i:s}` - optional date format which can be used to override framework configuration
* `unsigned: {true/false}` - flag for unsigned int
* `default: {default value}` - defines optional default value
* `fk: {array with FK attributes}` - if a field is a foreign key, then used to define table and field that associates with it (see below)

For FK attributes, the following are required:
* `table: {true/false}` - fk table name
* `field: {true/false}` - fk table field that links to current model
* `cascade: {true/false}` - flag for whether fk table should be deleted too

### Controllers
Coming soon

### "Abstract" Controllers or View Controllers
For data that may have unique requirements and complex relations, an abstract controller can be created to manipulate the data and update one or more models.  "Abstract" or View controllers do not need to have a model and can act as standalone controllers that directly process and return data, such as the built-in MailController.  View Controllers are similar to virtual tables or views in traditional relational databases, such as Oracle or MySQL.  View Controllers are created just like any other controller and extends the `ModelController` class and must override all class methods.  View Controllers are called using the same URL syntax where the model is replaced by the View Controller's "view" name.  For example, for the `MailController`, the model name is `Mail`, even though no model named `Mail` exists.  The API router will recongize that the requested resource is a View Controller and correctly route the call.
