# Kyte-PHP Documentation

Comprehensive guides for understanding and working with Kyte-PHP backend framework.

## Documentation Structure

This documentation is organized into three main sections:

### Framework Core

**[Framework Documentation](framework/)** - Core Kyte framework concepts

1. **[Model Definition Guide](framework/models-definition.md)** ⭐ Start Here
   - Understanding what models are
   - Model structure and syntax
   - Field types and attributes
   - Foreign keys and relationships
   - Complete examples and best practices
   - **Recommended for:** Everyone, especially beginners

2. **[Models and ModelObjects Guide](framework/models-usage.md)**
   - Difference between Model and ModelObject
   - CRUD operations (Create, Read, Update, Delete)
   - Working with single records (ModelObject)
   - Working with multiple records (Model)
   - Advanced queries and filtering
   - Practical examples
   - **Recommended for:** Backend developers, API developers

3. **[Controllers Guide](framework/controllers.md)**
   - Understanding the controller layer
   - ModelController basics
   - Creating custom controllers
   - Controller hooks and lifecycle
   - Request/response handling
   - Business logic implementation
   - **Recommended for:** API developers, intermediate+ users

4. **[AWS Integration Guide](framework/aws-integration.md)**
   - Using AWS services in Kyte
   - S3 for file storage
   - SES for email sending
   - KMS for encryption
   - SNS for notifications
   - CloudFront for CDN
   - Complete integration examples
   - **Recommended for:** DevOps, developers using cloud services

5. **[Performance Optimization Guide](framework/performance.md)** ⚡
   - Query caching (50-80% faster queries)
   - Model memory cache (90%+ faster access)
   - Eager loading (eliminates N+1 problem)
   - Batch operations (10-50x faster bulk operations)
   - Performance monitoring and metrics
   - Real-world optimization examples
   - **Recommended for:** Everyone in production, performance-critical applications

6. **[Logging System Guide](framework/logging.md)**
   - Error logging and monitoring
   - Log levels and configuration
   - Custom log handlers
   - **Recommended for:** DevOps, production deployments

### Cron System

**[Cron System Documentation](cron/)** - Distributed cron job scheduling

- **[Getting Started](cron/getting-started.md)** - Installation and first job
- **[Job Development](cron/job-development.md)** - Writing robust cron jobs
- **[Scheduling](cron/scheduling.md)** - Schedule types, timezones, dependencies
- **[Execution](cron/execution.md)** - Locking, retries, failures, notifications
- **[Version Control](cron/version-control.md)** - Code versioning and rollback
- **[API Reference](cron/api-reference.md)** - REST API endpoints
- **[Web Interface](cron/web-interface.md)** - Frontend UI guide
- **[Testing](cron/testing.md)** - Testing strategies and tools

### Future Features

**[Future Features](future/)** - Planned enhancements and architecture designs

- **[WebSocket Architecture](future/websocket-architecture.md)** - Real-time monitoring design
- **[Job Templates](future/job-templates.md)** - Template system and marketplace design

## Quick Start

### For Complete Beginners

If you're new to Kyte-PHP or backend development, start here:

1. Read [Model Definition Guide](framework/models-definition.md) to understand data structure
2. Read [Models and ModelObjects Guide](framework/models-usage.md) to learn CRUD operations
3. Try the examples in each guide
4. Read [Performance Optimization Guide](framework/performance.md) to enable key features
5. Move on to Controllers when you're comfortable with models

### For Experienced Developers

If you're familiar with MVC frameworks:

1. Skim [Model Definition Guide](framework/models-definition.md) to understand Kyte's model syntax
2. Focus on the "Dynamic Model System" section in the main [CLAUDE.md](../CLAUDE.md)
3. **Read [Performance Optimization Guide](framework/performance.md) first** - Enable caching and eager loading immediately
4. Read [Controllers Guide](framework/controllers.md) to understand the hook system
5. Reference other guides as needed

### For Cron Job Development

If you're building background jobs and scheduled tasks:

1. Start with [Cron Getting Started](cron/getting-started.md) for installation
2. Read [Job Development Guide](cron/job-development.md) to write your first job
3. Learn [Scheduling options](cron/scheduling.md) for complex schedules
4. Understand [Execution and retries](cron/execution.md) for reliability
5. Use [Version Control](cron/version-control.md) to manage job code

### For DevOps/Infrastructure

If you're setting up or maintaining Kyte-PHP:

1. Review the main [README.md](../README.md) for installation and setup
2. Read [AWS Integration Guide](framework/aws-integration.md) for cloud services
3. Set up [Cron System](cron/getting-started.md) for background jobs
4. Review [Logging Configuration](framework/logging.md) for monitoring
5. Review configuration sections in [CLAUDE.md](../CLAUDE.md)

## Common Use Cases

### Creating a New Feature

1. **Define the model** ([Guide](framework/models-definition.md))
   ```php
   $MyModel = [
       'name' => 'MyModel',
       'struct' => [ /* fields */ ]
   ];
   ```

2. **Use ModelObject for single records** ([Guide](framework/models-usage.md))
   ```php
   $obj = new \Kyte\Core\ModelObject(constant('MyModel'));
   $obj->create($data);
   ```

3. **Create a custom controller if needed** ([Guide](framework/controllers.md))
   ```php
   class MyModelController extends ModelController {
       protected function hook_init() { /* config */ }
   }
   ```

### Building an API Endpoint

1. Define your data model
2. Create controller with appropriate hooks
3. Use ModelObject/Model for database operations
4. Return data through controller response

See complete examples in each guide.

### Creating a Cron Job

1. **Write job class** ([Guide](cron/job-development.md))
   ```php
   class MyJob extends \Kyte\Core\CronJobBase {
       public function execute() { /* logic */ }
   }
   ```

2. **Create job via API or Manager** ([Guide](cron/getting-started.md))
3. **Start worker daemon** - `php bin/cron-worker.php`
4. **Monitor executions** - Via web interface or API

### Integrating AWS Services

1. Set up AWS credentials in configuration
2. Use appropriate AWS wrapper class ([Guide](framework/aws-integration.md))
3. Implement in controller hooks
4. Handle errors gracefully

## Documentation by Topic

### Data Management
- [Model Definition](framework/models-definition.md) - Defining data structure
- [CRUD Operations](framework/models-usage.md#crud-operations) - Basic database operations
- [Advanced Queries](framework/models-usage.md#advanced-queries) - Complex data retrieval

### Performance
- [Query Caching](framework/performance.md#query-caching) - Cache repeated queries
- [Model Memory Cache](framework/performance.md#model-memory-cache) - Cache model definitions
- [Eager Loading](framework/performance.md#eager-loading) - Eliminate N+1 queries
- [Batch Operations](framework/performance.md#batch-operations) - Bulk inserts/updates
- [Performance Monitoring](framework/performance.md#performance-monitoring) - Track metrics

### Business Logic
- [Controller Basics](framework/controllers.md#modelcontroller-basics) - Understanding controllers
- [Controller Hooks](framework/controllers.md#controller-hooks) - Customizing behavior
- [Request Flow](framework/controllers.md#request-flow) - Understanding the lifecycle

### Cloud Integration
- [S3 Storage](framework/aws-integration.md#s3-simple-storage-service) - File management
- [Email Sending](framework/aws-integration.md#ses-simple-email-service) - Email integration
- [Data Encryption](framework/aws-integration.md#kms-key-management-service) - Security

### Background Jobs
- [Cron Getting Started](cron/getting-started.md) - Setup and first job
- [Job Development](cron/job-development.md) - Writing jobs
- [Scheduling Options](cron/scheduling.md) - Cron, daily, weekly, monthly
- [Retry Strategies](cron/execution.md#retry-logic-with-multiple-strategies) - Handling failures
- [Version Control](cron/version-control.md) - Managing job code

### Security
- [Protected Fields](framework/models-definition.md#protected) - Hiding sensitive data
- [Password Hashing](framework/models-definition.md#password) - Secure passwords
- [KMS Encryption](framework/aws-integration.md#automatic-kms-encryption-in-models) - Field encryption
- [Authentication](framework/controllers.md#requireauth) - Access control

## Key Concepts

### Framework Core

**Models**
Models define the structure of your data. They are PHP arrays that describe database tables.
- [Learn More](framework/models-definition.md)

**ModelObject**
ModelObject represents a single database record. Use it for operations on one item.
- [Learn More](framework/models-usage.md#modelobject-single-records)

**Model**
Model represents multiple database records. Use it for lists and searches.
- [Learn More](framework/models-usage.md#model-multiple-records)

**Controllers**
Controllers handle HTTP requests and contain business logic.
- [Learn More](framework/controllers.md)

**Hooks**
Hooks are methods you override to customize controller behavior at specific points.
- [Learn More](framework/controllers.md#controller-hooks)

### Cron System

**Jobs**
Background tasks that extend CronJobBase and run on a schedule.
- [Learn More](cron/job-development.md)

**Worker**
Daemon process that polls for and executes pending jobs.
- [Learn More](cron/getting-started.md#running-the-cron-worker)

**Schedule Types**
Five ways to schedule jobs: cron, interval, daily, weekly, monthly.
- [Learn More](cron/scheduling.md)

**Version Control**
Automatic code versioning with SHA256 deduplication and rollback.
- [Learn More](cron/version-control.md)

## Code Examples Repository

Each guide includes complete, working examples:

- **Model Definition Guide**: User, Product, Order models
- **ModelObjects Guide**: Registration system, inventory management, blog system
- **Controllers Guide**: Read-only API, user management, order processing
- **AWS Guide**: File uploads, email notifications, encryption

All examples are production-ready and can be adapted to your needs.

## Best Practices Summary

### Model Definition
✅ Always include `type`, `required`, and `date` attributes
✅ Use appropriate field types for your data
✅ Mark sensitive fields as `protected`
✅ Use foreign keys for relationships
✅ Choose sensible field sizes

### Working with Data
✅ Use ModelObject for single records
✅ Use Model for lists and searches
✅ Always use try-catch for error handling
✅ Validate data before saving
✅ Use conditions for data scoping

### Performance
✅ Enable query caching (`QUERY_CACHE = true`)
✅ Enable model caching (`MODEL_CACHE = true`)
✅ Use eager loading for foreign keys (`.with()` method)
✅ Use batch operations for bulk data
✅ Monitor performance metrics in development

### Controllers
✅ Configure behavior in `hook_init()`
✅ Validate data in `hook_preprocess()`
✅ Scope queries in `hook_prequery()`
✅ Clean responses in `hook_response_data()`
✅ Use meaningful exception messages

### AWS Integration
✅ Always use try-catch for AWS operations
✅ Store credentials securely
✅ Choose appropriate regions
✅ Set correct S3 ACLs
✅ Verify SES email addresses

## Additional Resources

### Main Documentation
- [CLAUDE.md](../CLAUDE.md) - High-level architecture and framework overview
- [README.md](../README.md) - Installation, setup, and getting started
- [CHANGELOG.md](../CHANGELOG.md) - Version history and updates

### External Resources
- [Kyte Shipyard](https://github.com/keyqcloud/kyte-shipyard/) - Model and controller management
- [PHP Documentation](https://www.php.net/docs.php) - PHP language reference
- [AWS SDK for PHP](https://aws.amazon.com/sdk-for-php/) - AWS SDK documentation

## Getting Help

### Understanding Concepts
- Read through the guides in order
- Try the examples provided
- Experiment with variations

### Debugging Issues
- Check the error messages
- Review the relevant guide section
- Verify your model/controller configuration
- Check the request flow diagram

### Common Issues

**"Column cannot be null"**
- Check that required fields are provided
- Review [Model Definition - Required Attributes](framework/models-definition.md#required-attributes)

**"No retrieved data to update"**
- Must retrieve before save
- Review [ModelObject - Update Operation](framework/models-usage.md#update-operation-update)

**"Unauthorized API request"**
- Check authentication settings
- Review [Controllers - Authentication](framework/controllers.md#requireauth)

**AWS errors**
- Verify credentials are configured
- Check IAM permissions
- Review [AWS Integration Guide](framework/aws-integration.md)

**Cron jobs not running**
- Ensure worker is running: `ps aux | grep cron-worker`
- Check job is enabled: `SELECT enabled FROM CronJob WHERE id = X`
- Review [Cron Troubleshooting](cron/getting-started.md#troubleshooting)

## Contributing

If you find errors or have suggestions for improving this documentation, please:
1. Check the existing documentation first
2. Note the specific guide and section
3. Provide clear suggestions or corrections
4. Include examples if applicable

## Version

This documentation is for Kyte-PHP v3.x+

Last updated: 2024

---

---

**Ready to get started?**
- Framework developers: Begin with the [Model Definition Guide](framework/models-definition.md) 🚀
- Cron job developers: Start with [Cron Getting Started](cron/getting-started.md) ⏰
- Full documentation: Browse [framework/](framework/), [cron/](cron/), or [future/](future/) directories
