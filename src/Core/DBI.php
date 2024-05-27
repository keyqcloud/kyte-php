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
	
	// Redis connection settings
    public static $redisHost = null;
    public static $redisPort = 6379;
    public static $redisTTL = 0; // Persistent by default
    private static $redis = null;

	/*
	 * Sets the Redis host
	 *
	 * @param string $redisHost
	 */
	public static function setRedisHost($redisHost)
	{
		self::$redisHost = $redisHost;
	}

	/*
	 * Sets the Redis port
	 *
	 * @param integer $redisPort
	 */
	public static function setRedisPort($redisPort)
	{
		self::$redisPort = $redisPort;
	}

	/*
	 * Sets the Redis TTL (time to live)
	 *
	 * @param integer $redisTTL
	 */
	public static function setRedisTTL($redisTTL)
	{
		self::$redisTTL = $redisTTL;
	}

    public static function initRedis($host, $port = 6379, $ttl = 0) {
        self::$redisHost = $host;
        self::$redisPort = $port;
        self::$redisTTL = $ttl;
        self::$redis = new \Redis();
        self::$redis->connect(self::$redisHost, self::$redisPort);
		self::$redis->setOption(\Redis::OPT_SCAN, \Redis::SCAN_RETRY);
		self::$redis->setOption(\Redis::OPT_SCAN, \Redis::SCAN_NOPREFIX);
    }

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

	private static function generateCacheKey($prefix, $identifier = null) {
        $dbName = self::$dbName;
		$appDbName = self::$useAppDB ? self::$dbNameApp : 'ks';
        $deploymentSalt = defined('KYTE_DEPLOYMENT_SALT') ? KYTE_DEPLOYMENT_SALT : 'default_kyte_salt';
        $key = "{$deploymentSalt}:{$dbName}:{$appDbName}:{$prefix}";
        if ($identifier) {
            $key .= ":{$identifier}";
        }
        return $key;
    }

	/*
	 * Select from table in database and returns the first row only
	 *
	 * @param string $table
	 * @param integer $id
	 * @param string $condition
	 */
	public static function select($table, $id = null, $condition = null, $join = null) {
        $cacheKey = self::generateCacheKey("select:$table", md5("$id:$condition:" . json_encode($join)));
        $cachedResult = self::$redis ? self::$redis->get($cacheKey) : null;

        if ($cachedResult) {
            return json_decode($cachedResult, true);
        }

        $con = self::$useAppDB ? self::connectApp() : self::connect();

        $query = "SELECT `$table`.* FROM `$table`";
        $join_query = "";
        $empty_cond = false;
        $first = true;

        if (is_array($join)) {
            foreach($join as $j) {
                $tbl = $j['table'];
                $query .= ", `{$j['table']}`";
                if (isset($j['table_alias'])) {
                    $query .= " `{$j['table_alias']}`";
                    $tbl = $j['table_alias'];
                }
                if (empty($condition)) {
                    $condition = " WHERE `$table`.`{$j['main_table_idx']}` = `{$tbl}`.`{$j['table_idx']}`";
                    $empty_cond = true;
                } else {
                    $join_query .= (($first && !$empty_cond) ? " WHERE " : " AND ")."`$table`.`{$j['main_table_idx']}` = `{$tbl}`.`{$j['table_idx']}`";
                    $first = false;
                }
            }
            if (!$empty_cond) {
                $condition = str_replace("WHERE", "AND", $condition);
            }
        }

        if(isset($id)) {
            $query .= " WHERE id = $id";
        } else {
            $query .= "$join_query $condition";
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

        if (self::$redis) {
            if (self::$redisTTL > 0) {
                self::$redis->set($cacheKey, json_encode($data), self::$redisTTL);
            } else {
                self::$redis->set($cacheKey, json_encode($data));
            }
        }

        return $data;
    }

	/*
	 * Make an insert into table in database
	 *
	 * @param string $table
	 * @param array $params
	 * @param string $types
	 */
	public static function insert($table, $params, $types) {
        $con = self::$useAppDB ? self::connectApp() : self::connect();

        $bindParams = array();
        $columns = array_keys($params);

        for ($i = 0; $i < count($columns); $i++) {
            $bindParams[] = $params[$columns[$i]];
            $columns[$i] = '`'.$columns[$i].'`';
        }

        $placeholder = str_repeat("?, ", count($params));
        $placeholder = substr($placeholder, 0, -2);

        $query = sprintf("INSERT INTO `%s`(%s) VALUES(%s)", $table, implode(',', $columns), $placeholder);

        $stmt = $con->prepare($query);
        if($stmt === false) {
            throw new \Exception("Error preparing mysql statement '$query'; ".htmlspecialchars($con->error), 1);
        }

        $stmt->bind_param($types, ...$bindParams);

        if (!$stmt->execute()) {
            $stmt->close();
            throw new \Exception("Error executing mysql statement '$query'; ".htmlspecialchars($con->error), 1);
        }

        $insertId = $stmt->insert_id;
        $stmt->close();

        self::invalidateCache($table);

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
    public static function update($table, $id, $params, $types) {
        $con = self::$useAppDB ? self::connectApp() : self::connect();

        $query = "UPDATE `$table` SET ";

        $bindParams = array();
        $types .= 'i';
        foreach ($params as $key => $value) {
            $query .= "`$key` = ?, ";
            $bindParams[] = $value;
        }
        $bindParams[] = $id;
        $query = substr($query, 0, -2);
        $query .= " WHERE id = ?";

        $stmt = $con->prepare($query);
        if($stmt === false) {
            throw new \Exception("Error preparing mysql statement '$query'; ".htmlspecialchars(self::$dbConn->error), 1);
        }

        $stmt->bind_param($types, ...$bindParams);

        if (!$stmt->execute()) {
            $stmt->close();
            throw new \Exception("Error executing mysql statement '$query'; ".htmlspecialchars($con->error), 1);
        }

        $stmt->close();

        self::invalidateCache($table);

        return true;
    }

	/*
	 * Delete an entry in database table
	 *
	 * @param string $table
	 * @param integer $id
	 */
    public static function delete($table, $id) {
        $con = self::$useAppDB ? self::connectApp() : self::connect();

        $query = "DELETE FROM `$table` WHERE id = ?";

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

        self::invalidateCache($table);

        return true;
    }

    private static function invalidateCache($table) {
		if (self::$redis) {
			try {
				// Check Redis connection
				if (self::$redis->ping()) {
					error_log("Redis server is alive.");
				} else {
					error_log("Unable to connect to Redis server.");
					return;
				}
	
				$pattern = self::generateCacheKey("select:$table", "*");
				error_log("Generated pattern: $pattern");
	
				// Fetch all matching keys using KEYS command
				$keys = self::$redis->keys($pattern);
				if ($keys !== false && count($keys) > 0) {
					error_log("Keys found for pattern {$pattern}: " . print_r($keys, true));
					foreach ($keys as $key) {
						self::$redis->del($key);
					}
				} else {
					error_log("No keys matched the pattern {$pattern}");
				}
			} catch (\Exception $e) {
				error_log("Redis error while invalidating cache for table $table: " . $e->getMessage());
			}
		} else {
			error_log("Redis is not initialized.");
		}
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
		$con = null;

		if (self::$useAppDB) {
			if (!self::$dbConnApp) {
				self::connectApp();
			}
			$con = self::$dbConnApp;
		} else {
			if (!self::$dbConn) {
				self::connect();
			}
			$con = self::$dbConn;
		}

		$query = "SELECT count(`$table`.`id`) as count FROM `$table`";

		$join_query = "";

		$empty_cond = false;
		$first = true;

		if (is_array($join)) {
			foreach($join as $j) {
				$tbl = $j['table'];
				$query .= ", `{$j['table']}`";

				// if an alias is set (i.e. same table is being queried), update from clause, and table name
				if (isset($j['table_alias'])) {
					$query .= " `{$j['table_alias']}`";
					$tbl = $j['table_alias'];
				}

				// prepare conditions, or just the join clause
				if (empty($condition)) {
					$condition = " WHERE `$table`.`{$j['main_table_idx']}` = `{$tbl}`.`{$j['table_idx']}`";
					$empty_cond = true;
				} else {
					$join_query .= (($first && !$empty_cond) ? " WHERE " : " AND ")."`$table`.`{$j['main_table_idx']}` = `{$tbl}`.`{$j['table_idx']}`";
					$first = false;
				}
			}

			// if condition was originally not empty
			if (!$empty_cond) {
				// remove where from $condition and replace it with AND
				$condition = str_replace("WHERE", "AND", $condition);
			}
		}

		if(isset($id)) {
			$query .= " WHERE id = $id";
		} else {
			
		}
		
		$query .= "$join_query $condition";

		// DEBUG
		if (defined('DEBUG_SQL')) {
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
	 * Select from table in database and group by
	 *
	 * @param string $table
	 * @param string $condition
	 */
	public static function group($table, $field, $condition = null)
	{
		// db connection
		$con = null;

		if (self::$useAppDB) {
			if (!self::$dbConnApp) {
				self::connectApp();
			}
			$con = self::$dbConnApp;
		} else {
			if (!self::$dbConn) {
				self::connect();
			}
			$con = self::$dbConn;
		}

		$query = "SELECT `$field`, count(`$field`) FROM `$table`";

		if($query) {
			$query .= " $condition";
		}

		$query .= " GROUP BY `$field`";

		// DEBUG
		if (defined('DEBUG_SQL')) {
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
		$con = null;

		if (self::$useAppDB) {
			if (!self::$dbConnApp) {
				self::connectApp();
			}
			$con = self::$dbConnApp;
		} else {
			if (!self::$dbConn) {
				self::connect();
			}
			$con = self::$dbConn;
		}

		// DEBUG
		if (defined('DEBUG_SQL')) {
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
		$con = null;

		if (self::$useAppDB) {
			if (!self::$dbConnApp) {
				self::connectApp();
			}
			$con = self::$dbConnApp;
		} else {
			if (!self::$dbConn) {
				self::connect();
			}
			$con = self::$dbConn;
		}

		$new_field_name = 'sum_'.$sumField;

		$query = "SELECT SUM(`$sumField`) as `$new_field_name` FROM `$table`";

		if(isset($id)) {
			$query .= " WHERE id = $id";
		} else {
			$query .= " $condition";
		}

		// DEBUG
		if (defined('DEBUG_SQL')) {
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
		$con = null;

		if (self::$useAppDB) {
			if (!self::$dbConnApp) {
				self::connectApp();
			}
			$con = self::$dbConnApp;
		} else {
			if (!self::$dbConn) {
				self::connect();
			}
			$con = self::$dbConn;
		}
		
		return $con->real_escape_string($string);
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
		$con = null;

		if (self::$useAppDB) {
			if (!self::$dbConnApp) {
				self::connectApp();
			}
			$con = self::$dbConnApp;
		} else {
			if (!self::$dbConn) {
				self::connect();
			}
			$con = self::$dbConn;
		}

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
		$con = null;

		if (self::$useAppDB) {
			if (!self::$dbConnApp) {
				self::connectApp();
			}
			$con = self::$dbConnApp;
		} else {
			if (!self::$dbConn) {
				self::connect();
			}
			$con = self::$dbConn;
		}

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

			// check if required attrs are set
			if (!isset($attrs['date'])) {
				throw new \Exception("date attribute must be declared for column $name of table $tbl_name.");
			}

			if (!isset($attrs['required'])) {
				throw new \Exception("required attribute must be declared for column $name of table $tbl_name.");
			}

			if (!isset($attrs['type'])) {
				throw new \Exception("type attribute must be declared for column $name of table $tbl_name.");
			}

			$field = "`$name`";	// column name
			
			// type, size and if signed or not
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
							throw new \Exception("varchar requires size to be declared for column $name of table $tbl_name.");
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
						throw new \Exception("Unknown type " . $attrs['type'] . " for column $name of table $tbl_name.");
				}
			}
			if (array_key_exists('default', $attrs)) {
				// default value?
				$field .= ' DEFAULT ';
				$field .= (is_string($attrs['default']) ? "'".$attrs['default']."'" : $attrs['default']);
			}
			$field .= ($attrs['required'] ? ' NOT NULL' : '');		// required?

			if (array_key_exists('pk', $attrs)) {
				// primary key?
				if ($attrs['pk']) {
					$field .= ' AUTO_INCREMENT';
					$pk_name = $name;
				}
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
		$con = null;

		if (self::$useAppDB) {
			if (!self::$dbConnApp) {
				self::connectApp();
			}
			$con = self::$dbConnApp;
		} else {
			if (!self::$dbConn) {
				self::connect();
			}
			$con = self::$dbConn;
		}

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
		$con = null;

		if (self::$useAppDB) {
			if (!self::$dbConnApp) {
				self::connectApp();
			}
			$con = self::$dbConnApp;
		} else {
			if (!self::$dbConn) {
				self::connect();
			}
			$con = self::$dbConn;
		}

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
		$con = null;

		if (self::$useAppDB) {
			if (!self::$dbConnApp) {
				self::connectApp();
			}
			$con = self::$dbConnApp;
		} else {
			if (!self::$dbConn) {
				self::connect();
			}
			$con = self::$dbConn;
		}
		
		// check if required attrs are set
		if (!isset($attrs['date'])) {
			throw new \Exception("date attribute must be declared for column $column of table $tbl_name.");
		}

		if (!isset($attrs['required'])) {
			throw new \Exception("required attribute must be declared for column $column of table $tbl_name.");
		}

		if (!isset($attrs['type'])) {
			throw new \Exception("type attribute must be declared for column $column of table $tbl_name.");
		}

		$field = "`$column`";	// column name
		
		// type, size and if signed or not
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
						throw new \Exception("varchar requires size to be declared for column $name of table $tbl_name.");
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
					throw new \Exception("Unknown type " . $attrs['type'] . " for column $name of table $tbl_name.");
			}
		}
		if (array_key_exists('default', $attrs)) {
			// default value?
			$field .= ' DEFAULT ';
			$field .= (is_string($attrs['default']) ? "'".$attrs['default']."'" : $attrs['default']);
		}
		$field .= ($attrs['required'] ? ' NOT NULL' : '');

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
		$con = null;

		if (self::$useAppDB) {
			if (!self::$dbConnApp) {
				self::connectApp();
			}
			$con = self::$dbConnApp;
		} else {
			if (!self::$dbConn) {
				self::connect();
			}
			$con = self::$dbConn;
		}
		
		// check if required attrs are set
		if (!isset($attrs['date'])) {
			throw new \Exception("date attribute must be declared for column $column_name_new of table $tbl_name.");
		}

		if (!isset($attrs['required'])) {
			throw new \Exception("required attribute must be declared for column $column_name_new of table $tbl_name.");
		}

		if (!isset($attrs['type'])) {
			throw new \Exception("type attribute must be declared for column $column_name_new of table $tbl_name.");
		}

		$field = "`$column_name_new`";	// column name
		
		// type, size and if signed or not
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
						throw new \Exception("varchar requires size to be declared for column $name of table $tbl_name.");
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
					throw new \Exception("Unknown type " . $attrs['type'] . " for column $name of table $tbl_name.");
			}
		}
		if (array_key_exists('default', $attrs)) {
			// default value?
			$field .= ' DEFAULT ';
			$field .= (is_string($attrs['default']) ? "'".$attrs['default']."'" : $attrs['default']);
		}
		$field .= ($attrs['required'] ? ' NOT NULL' : '');

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
		$con = null;

		if (self::$useAppDB) {
			if (!self::$dbConnApp) {
				self::connectApp();
			}
			$con = self::$dbConnApp;
		} else {
			if (!self::$dbConn) {
				self::connect();
			}
			$con = self::$dbConn;
		}

		$tbl_sql = "ALTER TABLE `$tbl_name` DROP `$column_name`";

		$result = $con->query($tbl_sql);
		if($result === false) {
			throw new \Exception("Error with mysql query '$tbl_sql'. [Error]:  ".htmlspecialchars($con->error));
		}

		return true;
	}
}
