<?php

namespace Kyte\Core;

class DBI {
	// main db connection instance
	private static $dbConn;

	public static $dbUser;
	public static $dbPassword;
	public static $dbName;
	public static $dbHost;
	public static $charset = 'utf8mb4';
	public static $engine = 'InnoDB';

	public static $useAppDB = false;

	// app db connection instance
	private static $dbConnApp = null;
	public static $dbUserApp = null;
	public static $dbPasswordApp = null;
	public static $dbNameApp = null;
	public static $dbHostApp = null;
	// charset is the same as main DB
	// public static $charsetApp = 'utf8mb4';
	// public static $engineApp = 'InnoDB';

	// query logging
	private static $queryLog = [];
	private static $queryLoggingEnabled = false;

	// query result caching
	private static $queryCache = [];
	private static $cacheEnabled = false;
	private static $cacheTTL = 60;
	private static $cacheHits = 0;
	private static $cacheMisses = 0;

	/*
	 * Sets the database username to be used to connect to DB
	 *
	 * @param string $dbUser
	 */
	public static function setDbUser($dbUser)
	{
		self::$dbUser = $dbUser;
	}

	/*
	 * Sets the database password to be used to connect to DB
	 *
	 * @param string $dbPassword
	 */
	public static function setDbPassword($dbPassword)
	{
		self::$dbPassword = $dbPassword;
	}

	/*
	 * Sets the database host to be used to connect to DB
	 *
	 * @param string $dbUser
	 */
	public static function setDbHost($dbHost)
	{
		self::$dbHost = $dbHost;
	}

	/*
	 * Sets the database name to be used to connect to DB
	 *
	 * @param string $dbName
	 */
	public static function setDbName($dbName)
	{
		self::$dbName = $dbName;
	}

	/*
	 * Sets the database charset
	 *
	 * @param string $charset
	 */
	public static function setCharset($charset)
	{
		self::$charset = $charset;
	}

	/*
	 * Sets the database engine
	 *
	 * @param string $engine
	 */
	public static function setEngine($engine)
	{
		self::$engine = $engine;
	}

	/*
	 * Sets the database username to be used to connect to DB for App
	 *
	 * @param string $dbUser
	 */
	public static function setDbUserApp($dbUserApp)
	{
		self::$dbUserApp = $dbUserApp;
	}

	/*
	 * Sets the database password to be used to connect to DB for App
	 *
	 * @param string $dbPassword
	 */
	public static function setDbPasswordApp($dbPasswordApp)
	{
		self::$dbPasswordApp = $dbPasswordApp;
	}

	/*
	 * Sets the database host to be used to connect to DB for App
	 *
	 * @param string $dbUser
	 */
	public static function setDbHostApp($dbHostApp)
	{
		self::$dbHostApp = $dbHostApp;
	}

	/*
	 * Sets the database name to be used to connect to DB for App
	 *
	 * @param string $dbName
	 */
	public static function setDbNameApp($dbNameApp)
	{
		self::$dbNameApp = $dbNameApp;
	}

	public static function dbInit($dbUser, $dbPassword, $dbHost, $dbName, $charset, $engine) {
		self::setDbUser($dbUser);
		self::setDbPassword($dbPassword);
		self::setDbHost($dbHost);
		self::setDbName($dbName);
		self::setCharset($charset);
		self::setEngine($engine);

		return self::connect();
	}

	public static function dbInitApp($dbUserApp, $dbPasswordApp, $dbHostApp, $dbNameApp, $charset, $engine) {
		self::setDbUserApp($dbUserApp);
		self::setDbPasswordApp($dbPasswordApp);
		self::setDbHostApp($dbHostApp);
		self::setDbNameApp($dbNameApp);
		self::setCharsetApp($charsetApp);
		self::setEngineApp($engineApp);

		return self::connect();
	}

	/*
	 * Connect to database
	 *
	 * @param string $dbName
	 */
	public static function connect()
	{
		if (!self::$dbConn) {
			try {
				// Check if KYTE_DB_CA_BUNDLE is defined and set SSL options
				if (defined('KYTE_DB_CA_BUNDLE')) {
					self::$dbConn = new \mysqli();
					self::$dbConn->ssl_set(null, null, KYTE_DB_CA_BUNDLE, null, null);

					// Try to establish an SSL connection
					if (!self::$dbConn->real_connect(self::$dbHost, self::$dbUser, self::$dbPassword, self::$dbName, null, null, MYSQLI_CLIENT_SSL)) {
						// If SSL connection fails, throw an exception to fall back
						throw new \Exception('SSL connection failed: ' . self::$dbConn->connect_error, self::$dbConn->connect_errno);
					}
				} else {
					// Establish a non-SSL connection
					self::$dbConn = new \mysqli(self::$dbHost, self::$dbUser, self::$dbPassword, self::$dbName);
				}

				// Set charset to utf8mb4
				if (TRUE !== self::$dbConn->set_charset(self::$charset)) {
					throw new \Exception(self::$dbConn->error, self::$dbConn->errno);
				}
			} catch (mysqli_sql_exception $e) {
				// If SSL connection fails, fall back to non-SSL connection
				if (defined('KYTE_DB_CA_BUNDLE') && self::$dbConn->connect_errno) {
					self::$dbConn = new \mysqli(self::$dbHost, self::$dbUser, self::$dbPassword, self::$dbName);
					// Set charset to utf8mb4
					if (TRUE !== self::$dbConn->set_charset(self::$charset)) {
						throw new \Exception(self::$dbConn->error, self::$dbConn->errno);
					}
				} else {
					throw $e;
				}
			}
		}

		return self::$dbConn;
	}


	/*
	 * Connect to database for App
	 *
	 * @param string $dbNameApp
	 */
	public static function connectApp()
	{
		if (!self::$dbConnApp) {
			try {
				self::$dbConnApp = new \mysqli(self::$dbHostApp, self::$dbUserApp, self::$dbPasswordApp, self::$dbNameApp);
				// set charset to utf8mb4
				if ( TRUE !== self::$dbConnApp->set_charset( self::$charset ) )
					throw new \Exception( self::$dbConnApp->error, self::$dbConnApp->errno );
			} catch (mysqli_sql_exception $e) {
				throw $e;
			}
		}

		return self::$dbConnApp;
	}

	/*
	 * Close database connection
	 *
	 */
	public static function close()
	{
		if (self::$dbConn) {
			self::$dbConn->close();
		}
		self::$dbConn = null;
	}

	/*
	 * Close database connection for App
	 *
	 */
	public static function closeApp()
	{
		if (self::$dbConnApp) {
			self::$dbConnApp->close();
		}
		self::$dbConnApp = null;
	}

	/**
	 * Get current database connection
	 * Eliminates code duplication across query methods
	 *
	 * @return mysqli Current connection object
	 * @throws \Exception if connection fails
	 */
	private static function getConnection()
	{
		if (self::$useAppDB) {
			if (!self::$dbConnApp) {
				self::connectApp();
			}
			return self::$dbConnApp;
		} else {
			if (!self::$dbConn) {
				self::connect();
			}
			return self::$dbConn;
		}
	}

	/**
	 * Begin database transaction
	 * Provides ACID guarantees for multi-step operations
	 *
	 * @return bool True on success
	 * @throws \Exception if transaction cannot be started
	 */
	public static function beginTransaction()
	{
		$con = self::getConnection();
		if (!$con->begin_transaction()) {
			throw new \Exception("Failed to begin transaction: " . $con->error);
		}
		return true;
	}

	/**
	 * Commit database transaction
	 * Persists all changes made within the transaction
	 *
	 * @return bool True on success
	 * @throws \Exception if commit fails
	 */
	public static function commit()
	{
		$con = self::getConnection();
		if (!$con->commit()) {
			throw new \Exception("Failed to commit transaction: " . $con->error);
		}
		return true;
	}

	/**
	 * Rollback database transaction
	 * Reverts all changes made within the transaction
	 *
	 * @return bool True on success
	 * @throws \Exception if rollback fails
	 */
	public static function rollback()
	{
		$con = self::getConnection();
		if (!$con->rollback()) {
			throw new \Exception("Failed to rollback transaction: " . $con->error);
		}
		return true;
	}

	/**
	 * Enable query logging for debugging and performance analysis
	 *
	 * @return void
	 */
	public static function enableQueryLogging()
	{
		self::$queryLoggingEnabled = true;
		self::$queryLog = [];
	}

	/**
	 * Disable query logging
	 *
	 * @return void
	 */
	public static function disableQueryLogging()
	{
		self::$queryLoggingEnabled = false;
	}

	/**
	 * Get the query log
	 *
	 * @return array Array of logged queries with timestamps and execution times
	 */
	public static function getQueryLog()
	{
		return self::$queryLog;
	}

	/**
	 * Clear the query log
	 *
	 * @return void
	 */
	public static function clearQueryLog()
	{
		self::$queryLog = [];
	}

	/**
	 * Log a query for debugging and performance analysis
	 *
	 * @param string $query The SQL query
	 * @param array $params Query parameters (optional)
	 * @param float $executionTime Execution time in milliseconds (optional)
	 * @return void
	 */
	private static function logQuery($query, $params = [], $executionTime = null)
	{
		if (!self::$queryLoggingEnabled) {
			return;
		}

		self::$queryLog[] = [
			'query' => $query,
			'params' => $params,
			'execution_time' => $executionTime,
			'timestamp' => microtime(true),
			'database' => self::$useAppDB ? self::$dbNameApp : self::$dbName
		];
	}

	/**
	 * Enable query result caching
	 *
	 * @param int $ttl Cache time-to-live in seconds
	 * @return void
	 */
	public static function enableQueryCache($ttl = 60) {
		self::$cacheEnabled = true;
		self::$cacheTTL = $ttl;
	}

	/**
	 * Disable query result caching
	 *
	 * @return void
	 */
	public static function disableQueryCache() {
		self::$cacheEnabled = false;
		self::$queryCache = [];
	}

	/**
	 * Get cache statistics
	 *
	 * @return array Cache hit/miss counts and cache size
	 */
	public static function getCacheStats() {
		return [
			'hits' => self::$cacheHits,
			'misses' => self::$cacheMisses,
			'size' => count(self::$queryCache)
		];
	}

	/**
	 * Invalidate cache entries for a table
	 *
	 * @param string $table Table name
	 * @return void
	 */
	private static function invalidateTableCache($table) {
		foreach (self::$queryCache as $key => $entry) {
			if (isset($entry['table']) && $entry['table'] === $table) {
				unset(self::$queryCache[$key]);
			}
		}
	}

	/**
	 * Build SQL field definition string for CREATE/ALTER TABLE
	 * Eliminates duplicate code across createTable(), addColumn(), and changeColumn()
	 *
	 * @param string $name Field name
	 * @param array $attrs Field attributes from model definition
	 * @param string $tableName Table name (for error messages)
	 * @return string SQL field definition
	 * @throws \Exception if required attributes are missing or invalid
	 */
	private static function buildFieldDefinition($name, $attrs, $tableName) {
		// Validate required attributes
		if (!isset($attrs['date'])) {
			throw new \Exception("date attribute must be declared for column $name of table $tableName.");
		}
		if (!isset($attrs['required'])) {
			throw new \Exception("required attribute must be declared for column $name of table $tableName.");
		}
		if (!isset($attrs['type'])) {
			throw new \Exception("type attribute must be declared for column $name of table $tableName.");
		}

		$field = "`$name`";

		// Type, size, and signedness
		if ($attrs['date']) {
			$field .= ' bigint unsigned';
		} else {
			switch ($attrs['type']) {
				case 'i':
					$field .= ' int';
					if (array_key_exists('size', $attrs)) {
						$field .= '(' . $attrs['size'] . ')';
					}
					if (array_key_exists('unsigned', $attrs)) {
						$field .= ' unsigned';
					}
					break;
				case 'bi':
					$field .= ' bigint';
					if (array_key_exists('size', $attrs)) {
						$field .= '(' . $attrs['size'] . ')';
					}
					if (array_key_exists('unsigned', $attrs)) {
						$field .= ' unsigned';
					}
					break;
				case 's':
					$field .= ' varchar';
					if (array_key_exists('size', $attrs)) {
						$field .= '(' . $attrs['size'] . ')';
					} else {
						throw new \Exception("varchar requires size to be declared for column $name of table $tableName.");
					}
					break;
				case 'd':
					if (array_key_exists('precision', $attrs) && array_key_exists('scale', $attrs)) {
						$field .= ' decimal(' . $attrs['precision'] . ',' . $attrs['scale'] . ')';
					}
					break;
				case 't':
					$field .= ' text';
					break;
				case 'tt':
					$field .= ' tinytext';
					break;
				case 'mt':
					$field .= ' mediumtext';
					break;
				case 'lt':
					$field .= ' longtext';
					break;
				case 'b':
					$field .= ' blob';
					break;
				case 'tb':
					$field .= ' tinyblob';
					break;
				case 'mb':
					$field .= ' mediumblob';
					break;
				case 'lb':
					$field .= ' longblob';
					break;
				default:
					throw new \Exception("Unknown type " . $attrs['type'] . " for column $name of table $tableName.");
			}
		}

		// Default value
		if (array_key_exists('default', $attrs)) {
			$field .= ' DEFAULT ';
			$field .= (is_string($attrs['default']) ? "'" . $attrs['default'] . "'" : $attrs['default']);
		}

		// Required/NOT NULL
		$field .= ($attrs['required'] ? ' NOT NULL' : '');

		// Auto-increment for primary keys
		if (array_key_exists('pk', $attrs) && $attrs['pk']) {
			$field .= ' AUTO_INCREMENT';
		}

		return $field;
	}

	/*
	 * Create database
	 */
	public static function createDatabase($name, $username, &$password, $use = false)
	{
		if (!$name) {
			throw new \Exception("Database name must be specified");
		}

		if (!$username) {
			throw new \Exception("Database username must be specified");
		}

		// db connection
		$con = self::getConnection();

		// create password
		$password = '';
		$charset = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ#!@';
		$max = mb_strlen($charset, '8bit') - 1;
		for ($i = 0; $i < 24; ++$i) {
			$password .= $charset[random_int(0, $max)];
		}

		// create database
		$result = $con->query("CREATE DATABASE IF NOT EXISTS `{$name}`;");
		if($result === false) {
  			throw new \Exception("Unable to create database. [Error]:  ".htmlspecialchars($con->error));
		}

		// create user
		$result = $con->query("CREATE USER '{$username}'@'%' IDENTIFIED BY '{$password}';");
		if($result === false) {
  			throw new \Exception("Unable to create user. [Error]:  ".htmlspecialchars($con->error));
		}

		// set privs
		$result = $con->query("GRANT ALL PRIVILEGES ON `{$name}`.* TO '{$username}'@'%';");
		if($result === false) {
  			throw new \Exception("Unable to grant privileges. [Error]:  ".htmlspecialchars($con->error));
		}

		// flush privileges
		$result = $con->query("FLUSH PRIVILEGES;");
		if($result === false) {
  			throw new \Exception("Unable to flush privileges. [Error]:  ".htmlspecialchars($con->error));
		}

		// if use is true, then switch db
		if ($use) {
			$result = $con->query("USE `{$name}`;");
			if($result === false) {
				throw new \Exception("Unable to switch databases. [Error]:  ".htmlspecialchars($con->error));
			}
		}

		return true;
	}

	/*
	 * Create table
	 */
	public static function createTable($modelDef) {
		if (!$modelDef) {
			throw new \Exception("Table definition cannot be empty.");
		}

		// db connection
		$con = self::getConnection();

		$tbl_name = $modelDef['name'];
		$cols = $modelDef['struct'];
		$charset = self::$charset;
		$engine = self::$engine;
		$pk_name = '';	// store col struct for primary key

		$result = $con->query("DROP TABLE IF EXISTS `$tbl_name`;");
		if($result === false) {
			throw new \Exception("Unable to drop tables.");
		}

		$tbl_sql = "CREATE TABLE `$tbl_name` (";

		// table columns
		foreach ($cols as $name => $attrs) {
			// Use extracted helper method to build field definition
			$field = self::buildFieldDefinition($name, $attrs, $tbl_name);

			// Track primary key name
			if (array_key_exists('pk', $attrs) && $attrs['pk']) {
				$pk_name = $name;
			}

			$field .= ",\n";
			$tbl_sql .= $field;
		}

		// primary key
		$tbl_sql .= "PRIMARY KEY (`$pk_name`)) ENGINE=$engine DEFAULT CHARSET=$charset;";

		
		$result = $con->query($tbl_sql);
		if($result === false) {
			throw new \Exception("Error with mysql query '$tbl_sql'. [Error]:  ".htmlspecialchars($con->error));
		}

		return true;
	}

	/*
	 * DROP Table
	 * */
	public static function dropTable($tbl_name) {
		if (!$tbl_name) {
			throw new \Exception("Table name cannot be empty.");
		}

		// db connection
		$con = self::getConnection();

		$tbl_sql = "DROP TABLE `$tbl_name`";

		$result = $con->query($tbl_sql);
		if($result === false) {
			throw new \Exception("Error with mysql query '$tbl_sql'. [Error]:  ".htmlspecialchars($con->error));
		}

		return true;
	}

	/*
	 * Rename table
	 */
	public static function renameTable($tbl_name_old, $tbl_name_new) {
		if (!$tbl_name_old) {
			throw new \Exception("Current table name cannot be empty.");
		}
		
		if (!$tbl_name_new) {
			throw new \Exception("New table name cannot be empty.");
		}

		// db connection
		$con = self::getConnection();

		$tbl_sql = "ALTER TABLE `$tbl_name_old` RENAME TO `$tbl_name_news`";

		$result = $con->query($tbl_sql);
		if($result === false) {
			throw new \Exception("Error with mysql query '$tbl_sql'. [Error]:  ".htmlspecialchars($con->error));
		}

		return true;
	}

	/*
	 * Add column to table
	 */
	public static function addColumn($tbl_name, $column, $attrs) {
		if (!$tbl_name) {
			throw new \Exception("Table name cannot be empty.");
		}

		if (!$column) {
			throw new \Exception("Column name cannot be empty.");
		}

		// db connection
		$con = self::getConnection();

		// Use extracted helper method to build field definition
		$field = self::buildFieldDefinition($column, $attrs, $tbl_name);

		$tbl_sql = "ALTER TABLE `$tbl_name` ADD $field";

		$result = $con->query($tbl_sql);
		if($result === false) {
			throw new \Exception("Error with mysql query '$tbl_sql'. [Error]:  ".htmlspecialchars($con->error));
		}

		return true;
	}

	/*
	 * Change column in table
	 */
	public static function changeColumn($tbl_name, $column_name_old, $column_name_new, $attrs) {
		if (!$tbl_name) {
			throw new \Exception("Table name cannot be empty.");
		}

		if (!$column_name_old) {
			throw new \Exception("Current column name cannot be empty.");
		}

		if (!$column_name_new) {
			throw new \Exception("New column name cannot be empty.");
		}

		// db connection
		$con = self::getConnection();

		// Use extracted helper method to build field definition
		$field = self::buildFieldDefinition($column_name_new, $attrs, $tbl_name);

		$tbl_sql = "ALTER TABLE `$tbl_name` CHANGE `$column_name_old` $field";

		$result = $con->query($tbl_sql);
		if($result === false) {
			throw new \Exception("Error with mysql query '$tbl_sql'. [Error]:  ".htmlspecialchars($con->error));
		}

		return true;
	}

	/*
	 * Drop column in table
	 */
	public static function dropColumn($tbl_name, $column_name) {
		if (!$tbl_name) {
			throw new \Exception("Table name cannot be empty.");
		}

		if (!$column_name) {
			throw new \Exception("Column name cannot be empty.");
		}

		// db connection
		$con = self::getConnection();

		$tbl_sql = "ALTER TABLE `$tbl_name` DROP `$column_name`";

		$result = $con->query($tbl_sql);
		if($result === false) {
			throw new \Exception("Error with mysql query '$tbl_sql'. [Error]:  ".htmlspecialchars($con->error));
		}

		return true;
	}

	/*
	 * Make an insert into table in database
	 *
	 * @param string $table
	 * @param array $params
	 * @param string $types
	 */
	public static function insert($table, $params, $types)
	{
		// db connection
		try {
			$con = self::getConnection();
		} catch (\Exception $e) {
			throw $e;
		}

		// DEBUG
		if (defined('DEBUG_SQL_PARAMS') && DEBUG_SQL_PARAMS) {
			error_log(print_r($params, true));
		}

		// prepare bind params for call_user_func_array
		$bindParams = array();
		$columns = array_keys($params);
		
		for ($i = 0; $i < count($columns); $i++) {
			$bindParams[] = $params[$columns[$i]];
			$columns[$i] = '`'.$columns[$i].'`';
		}

		$placeholder = str_repeat("?, ", count($params));
		$placeholder = substr($placeholder, 0, -2);

		$query = sprintf("INSERT INTO `%s`(%s) VALUES(%s)", $table, implode(',', $columns), $placeholder);

		// DEBUG
		if (defined('DEBUG_SQL') && DEBUG_SQL) {
			error_log($query);
		}

		$stmt = $con->prepare($query);
		if($stmt === false) {
  			throw new \Exception("Error preparing mysql statement '$query'; ".htmlspecialchars($con->error), 1);
		}
 
 		$stmt->bind_param($types, ...$bindParams);
		// call_user_func_array(array($stmt, 'bind_param'), $bindParams);

		if (!$stmt->execute()) {
			$stmt->close();
			throw new \Exception("Error executing mysql statement '$query'; ".htmlspecialchars($con->error), 1);
		}

		$insertId = $stmt->insert_id;
		$stmt->close();

		// Invalidate cache for this table
		self::invalidateTableCache($table);

		return $insertId;
	}

	/*
	 * Make a table update in database
	 *
	 * @param string $table
	 * @param integer $id
	 * @param array $params
	 * @param string $types
	 */
	public static function update($table, $id, $params, $types)
	{
		// db connection
		$con = self::getConnection();

		// DEBUG
		if (defined('DEBUG_SQL_PARAMS') && DEBUG_SQL_PARAMS) {
			error_log(print_r($params, true));
		}

		$query = "UPDATE `$table` SET ";

		// prepare bind params for call_user_func_array
		$bindParams = array();
		// add integer to to end of types for the where condition
		$types .= 'i';
		// $bindParams[] = &$types;
		foreach ($params as $key => $value) {
			$query .= "`$key` = ?, ";
			$bindParams[] = $value;
		}
		// add the id of row to update
		$bindParams[] = $id;
		// fix query to remove last comma and space
		$query = substr($query, 0, -2);
		// add condition
		$query .= " WHERE id = ?";

		// DEBUG
		if (defined('DEBUG_SQL') && DEBUG_SQL) {
			error_log($query);
		}

		$stmt = $con->prepare($query);
		if($stmt === false) {
  			throw new \Exception("Error preparing mysql statement '$query'; ".htmlspecialchars(self::$dbConn->error), 1);
		}
 
 		$stmt->bind_param($types, ...$bindParams);
		// call_user_func_array(array($stmt, 'bind_param'), $bindParams);

		if (!$stmt->execute()) {
			error_log("Error executing mysql statement '$query'; ".htmlspecialchars($con->error));
			$stmt->close();
			throw new \Exception("Error executing mysql statement '$query'; ".htmlspecialchars($con->error), 1);
		}

		$stmt->close();

		// Invalidate cache for this table
		self::invalidateTableCache($table);

		return true;
	}

	/*
	 * Delete an entry in database table
	 *
	 * @param string $table
	 * @param integer $id
	 */
	public static function delete($table, $id)
	{
		// db connection
		$con = self::getConnection();

		$query = "DELETE FROM `$table` WHERE id = ?";

		// DEBUG
		if (defined('DEBUG_SQL') && DEBUG_SQL) {
			error_log($query);
		}

		$stmt = $con->prepare($query);
		if($stmt === false) {
  			throw new \Exception("Error preparing mysql statement '$query'; ".htmlspecialchars($con->error), 1);
		}
 
		$stmt->bind_param('i', $id);

		if (!$stmt->execute()) {
			$stmt->close();
			throw new \Exception("Error executing mysql statement '$query'; ".htmlspecialchars($con->error), 1);
		}

		$stmt->close();

		// Invalidate cache for this table
		self::invalidateTableCache($table);

		return true;
	}

	/**
	 * Batch insert multiple rows in a single query (10-50x faster than individual inserts)
	 *
	 * @param string $table Table name
	 * @param array $rows Array of rows, each row is an associative array of column => value
	 * @param string $types Type string for all rows (must be same structure)
	 * @return array Array of inserted IDs
	 * @throws \Exception if insert fails
	 */
	public static function batchInsert($table, $rows, $types) {
		if (empty($rows)) {
			return [];
		}

		// db connection
		$con = self::getConnection();

		// Get columns from first row (all rows must have same structure)
		$columns = array_keys($rows[0]);
		$columnList = '`' . implode('`, `', $columns) . '`';

		// Build VALUES clause with placeholders
		$placeholders = [];
		$bindParams = [];
		$typeString = '';

		foreach ($rows as $row) {
			$rowPlaceholders = array_fill(0, count($columns), '?');
			$placeholders[] = '(' . implode(', ', $rowPlaceholders) . ')';

			// Add values to bind params in column order
			foreach ($columns as $col) {
				$bindParams[] = $row[$col];
			}

			// Repeat type string for each row
			$typeString .= $types;
		}

		$query = "INSERT INTO `$table` ($columnList) VALUES " . implode(', ', $placeholders);

		// DEBUG
		if (defined('DEBUG_SQL') && DEBUG_SQL) {
			error_log($query);
		}

		$stmt = $con->prepare($query);
		if ($stmt === false) {
			throw new \Exception("Error preparing batch insert statement '$query'; " . htmlspecialchars($con->error), 1);
		}

		$stmt->bind_param($typeString, ...$bindParams);

		if (!$stmt->execute()) {
			$stmt->close();
			throw new \Exception("Error executing batch insert statement '$query'; " . htmlspecialchars($con->error), 1);
		}

		// Collect inserted IDs
		$firstId = $stmt->insert_id;
		$affectedRows = $stmt->affected_rows;
		$stmt->close();

		// Invalidate cache for this table
		self::invalidateTableCache($table);

		// Generate array of IDs (assuming auto-increment)
		$ids = [];
		for ($i = 0; $i < $affectedRows; $i++) {
			$ids[] = $firstId + $i;
		}

		return $ids;
	}

	/**
	 * Batch update multiple rows with same values
	 *
	 * @param string $table Table name
	 * @param array $ids Array of IDs to update
	 * @param array $params Associative array of column => value to update
	 * @param string $types Type string for params
	 * @return int Number of affected rows
	 * @throws \Exception if update fails
	 */
	public static function batchUpdate($table, $ids, $params, $types) {
		if (empty($ids) || empty($params)) {
			return 0;
		}

		// db connection
		$con = self::getConnection();

		// Build SET clause
		$setClause = [];
		$bindParams = [];
		foreach ($params as $col => $value) {
			$setClause[] = "`$col` = ?";
			$bindParams[] = $value;
		}

		// Build WHERE IN clause
		$idPlaceholders = implode(',', array_fill(0, count($ids), '?'));
		$query = "UPDATE `$table` SET " . implode(', ', $setClause) . " WHERE `id` IN ($idPlaceholders)";

		// Add IDs to bind params
		foreach ($ids as $id) {
			$bindParams[] = $id;
		}

		// Build type string (params types + 'i' for each ID)
		$typeString = $types . str_repeat('i', count($ids));

		// DEBUG
		if (defined('DEBUG_SQL') && DEBUG_SQL) {
			error_log($query);
		}

		$stmt = $con->prepare($query);
		if ($stmt === false) {
			throw new \Exception("Error preparing batch update statement '$query'; " . htmlspecialchars($con->error), 1);
		}

		$stmt->bind_param($typeString, ...$bindParams);

		if (!$stmt->execute()) {
			$stmt->close();
			throw new \Exception("Error executing batch update statement '$query'; " . htmlspecialchars($con->error), 1);
		}

		$affectedRows = $stmt->affected_rows;
		$stmt->close();

		// Invalidate cache for this table
		self::invalidateTableCache($table);

		return $affectedRows;
	}

	/*
	 * Return table count
	 *
	 * @param string $table
	 * @param string $condition
	 */
	public static function count($table, $condition = null, $join = null)
	{
		// db connection
		$con = self::getConnection();

		$query = "SELECT count(`$table`.`id`) as count FROM `$table`";

		if (is_array($join)) {
			foreach ($join as $j) {
				$tbl = $j['table'];
				$tbl_alias = isset($j['table_alias']) ? $j['table_alias'] : $tbl;
				$join_type = isset($j['join_type']) && strtoupper($j['join_type']) === 'LEFT' ? 'LEFT JOIN' : 'JOIN';

				$query .= " $join_type `{$j['table']}`";

				if (isset($j['table_alias'])) {
					$query .= " AS `$tbl_alias`";
				}

				$query .= " ON `$table`.`{$j['main_table_idx']}` = `$tbl_alias`.`{$j['table_idx']}`";
			}
		}

		$query .= $condition;

		// DEBUG
		if (defined('DEBUG_SQL') && DEBUG_SQL) {
			error_log($query);
		}

		$result = $con->query($query);
		if($result === false) {
  			throw new \Exception("Error with mysql query '$query'. [Error]:  ".htmlspecialchars($con->error));
		}

		$data = array();
		while ($row = $result->fetch_assoc()) {
			$data[] = $row;
		}

		$result->free();

		if (count($data) == 1) {
			return intval($data[0]['count']);
		} else {
			return -1;
		}
	}

	/*
	 * Select from table in database and returns the first row only
	 *
	 * @param string $table
	 * @param integer $id
	 * @param string $condition
	 */
	public static function select($table, $id = null, $condition = null, $join = null)
	{
		$cacheKey = null;

		// Check cache if enabled
		if (self::$cacheEnabled) {
			$cacheKey = md5(serialize([
				'table' => $table,
				'id' => $id,
				'condition' => $condition,
				'join' => $join,
				'db' => self::$useAppDB ? 'app' : 'main'
			]));

			if (isset(self::$queryCache[$cacheKey]) &&
				self::$queryCache[$cacheKey]['expires'] > time()) {
				self::$cacheHits++;
				return self::$queryCache[$cacheKey]['data'];
			}

			self::$cacheMisses++;
		}

		// db connection
		$con = self::getConnection();

		$query = "SELECT `$table`.* FROM `$table`";

		if (is_array($join)) {
			foreach ($join as $j) {
				$tbl = $j['table'];
				$tbl_alias = isset($j['table_alias']) ? $j['table_alias'] : $tbl;
				$join_type = isset($j['join_type']) && strtoupper($j['join_type']) === 'LEFT' ? 'LEFT JOIN' : 'JOIN';

				$query .= " $join_type `{$j['table']}`";

				if (isset($j['table_alias'])) {
					$query .= " AS `$tbl_alias`";
				}

				$query .= " ON `$table`.`{$j['main_table_idx']}` = `$tbl_alias`.`{$j['table_idx']}`";
			}
		}

		if(isset($id)) {
			$query .= " WHERE id = $id";
		} else {
			$query .= $condition;
		}

		// DEBUG
		if (defined('DEBUG_SQL') && DEBUG_SQL) {
			error_log($query);
		}

		$result = $con->query($query);
		if($result === false) {
  			throw new \Exception("Error with mysql query '$query'. [Error]:  ".htmlspecialchars($con->error));
		}

		$data = array();
		while ($row = $result->fetch_assoc()) {
			$data[] = $row;
		}

		$result->free();

		// Store in cache if enabled
		if (self::$cacheEnabled && $cacheKey) {
			self::$queryCache[$cacheKey] = [
				'data' => $data,
				'expires' => time() + self::$cacheTTL,
				'table' => $table
			];
		}

		return $data;
	}

	/*
	 * Select from table in database and group by
	 *
	 * @param string $table
	 * @param string $condition
	 */
	public static function group($table, $field, $condition = null)
	{
		// db connection
		$con = self::getConnection();

		$query = "SELECT `$field`, count(`$field`) FROM `$table`";

		if($query) {
			$query .= " $condition";
		}

		$query .= " GROUP BY `$field`";

		// DEBUG
		if (defined('DEBUG_SQL') && DEBUG_SQL) {
			error_log($query);
		}

		$result = $con->query($query);
		if($result === false) {
  			throw new \Exception("Error with mysql query '$query'. [Error]:  ".htmlspecialchars($con->error));
		}

		$data = array();
		while ($row = $result->fetch_assoc()) {
			$data[] = $row;
		}

		$result->free();
		
		return $data;
	}

	/*
	 * Execute custom SQL query
	 *
	 * @param string $sql
	 */
	public static function query($query)
	{
		// db connection
		$con = self::getConnection();

		// DEBUG
		if (defined('DEBUG_SQL') && DEBUG_SQL) {
			error_log($query);
		}

		$result = $con->query($query);
		if($result === false) {
  			throw new \Exception("Error with mysql query '$query'. [Error]:  ".htmlspecialchars($con->error));
		}

		if (is_bool($result)) {
			return $result;
		} else {
			$data = array();
			while ($row = $result->fetch_assoc()) {
				$data[] = $row;
			}

			$result->free();
			
			return $data;
		}
	}

	/*
	 * Select from table in database and returns the first row only
	 *
	 * @param string $table
	 * @param integer $id
	 * @param string $condition
	 */
	public static function sum($table, $sumField, $id = null, $condition = null)
	{
		// db connection
		$con = self::getConnection();

		$new_field_name = 'sum_'.$sumField;

		$query = "SELECT SUM(`$sumField`) as `$new_field_name` FROM `$table`";

		if(isset($id)) {
			$query .= " WHERE id = $id";
		} else {
			$query .= " $condition";
		}

		// DEBUG
		if (defined('DEBUG_SQL') && DEBUG_SQL) {
			error_log($query);
		}

		$result = $con->query($query);
		if($result === false) {
  			throw new \Exception("Error with mysql query '$query'. [Error]:  ".htmlspecialchars($con->error));
		}

		$data = array();
		while ($row = $result->fetch_assoc()) {
			$data[] = $row;
		}

		$result->free();
		
		return $data;
	}

	public static function escape_string($string) {
		// db connection
		$con = self::getConnection();
		
		return $con->real_escape_string($string);
	}
}