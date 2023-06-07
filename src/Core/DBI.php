<?php

namespace Kyte\Core;

class DBI {
	private static $dbConn;

	public static $dbUser;
	public static $dbPassword;
	public static $dbName;
	public static $dbHost;
	public static $charset = 'utf8mb4';
	public static $engine = 'InnoDB';

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

	public static function dbInit($dbUser, $dbPassword, $dbHost, $dbName, $charset, $engine) {
		self::setDbUser($dbUser);
		self::setDbPassword($dbPassword);
		self::setDbHost($dbHost);
		self::setDbName($dbName);
		self::setCharset($charset);
		self::setEngine($engine);

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
				self::$dbConn = new \mysqli(self::$dbHost, self::$dbUser, self::$dbPassword, self::$dbName);
				// set charset to utf8mb4
				if ( TRUE !== self::$dbConn->set_charset( self::$charset ) )
					throw new \Exception( self::$dbConn->error, self::$dbConn->errno );
			} catch (mysqli_sql_exception $e) {
				throw $e;
			}
		}

		return self::$dbConn;
	}

	/*
	 * Create database
	 */
	public static function createDatabase($name, $username, &$password, $use = false)
	{
		if (!$name) {
			throw new \Exception("Database name must be specified");
		}

		if (!self::$dbConn) {
			self::connect();
		}

		// create password
		$password = '';
		$charset = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ#!@';
		$max = mb_strlen($charset, '8bit') - 1;
		for ($i = 0; $i < 24; ++$i) {
			$password .= $charset[random_int(0, $max)];
		}

		// create database
		$result = self::$dbConn->query("CREATE DATABASE IF NOT EXISTS `{$name}`;");
		if($result === false) {
  			throw new \Exception("Unable to create database. [Error]:  ".htmlspecialchars(self::$dbConn->error));
  			return false;
		}

		// create user
		$result = self::$dbConn->query("CREATE USER '{$username}'@'localhost' IDENTIFIED BY '{$password}';");
		if($result === false) {
  			throw new \Exception("Unable to create user. [Error]:  ".htmlspecialchars(self::$dbConn->error));
  			return false;
		}

		// set privs
		$result = self::$dbConn->query("GRANT ALL PRIVILEGES ON `{$name}`.* TO '{$username}'@'localhost';");
		if($result === false) {
  			throw new \Exception("Unable to grant privileges. [Error]:  ".htmlspecialchars(self::$dbConn->error));
  			return false;
		}

		// flush privileges
		$result = self::$dbConn->query("FLUSH PRIVILEGES;");
		if($result === false) {
  			throw new \Exception("Unable to flush privileges. [Error]:  ".htmlspecialchars(self::$dbConn->error));
  			return false;
		}

		// if use is true, then switch db
		if ($use) {
			$result = self::$dbConn->query("USE `{$name}`;");
			if($result === false) {
				throw new \Exception("Unable to switch databases. [Error]:  ".htmlspecialchars(self::$dbConn->error));
				return false;
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

		if (!self::$dbConn) {
			self::connect();
		}

		$tbl_name = $modelDef['name'];
		$cols = $modelDef['struct'];
		$charset = self::$charset;
		$engine = self::$engine;
		$pk_name = '';	// store col struct for primary key

		$result = self::$dbConn->query("DROP TABLE IF EXISTS `$tbl_name`;");
		if($result === false) {
			throw new \Exception("Unable to drop tables.");
			return false;
		}

		$tbl_sql = "CREATE TABLE `$tbl_name` (";

		// table columns
		foreach ($cols as $name => $attrs) {

			// check if required attrs are set
			if (!isset($attrs['date'])) {
				throw new \Exception("date attribute must be declared for column $name of table $tbl_name.");
				return false;
			}

			if (!isset($attrs['required'])) {
				throw new \Exception("required attribute must be declared for column $name of table $tbl_name.");
				return false;
			}

			if (!isset($attrs['type'])) {
				throw new \Exception("type attribute must be declared for column $name of table $tbl_name.");
				return false;
			}

			$field = "`$name`";	// column name
			
			// type, size and if signed or not
			if ($attrs['date']) {
				$field .= ' bigint unsigned';
			} else {

				if ($attrs['type'] == 'i') {
					$field .= ' int';
					if (array_key_exists('size', $attrs)) {
						$field .= '('.$attrs['size'].')';
					}
					if (array_key_exists('unsigned', $attrs)) {
						$field .= ' unsigned';
					}
				} elseif ($attrs['type'] == 's') {
					$field .= ' varchar';
					if (array_key_exists('size', $attrs)) {
						$field .= '('.$attrs['size'].')';
					} else {
						throw new \Exception("varchar requires size to be declared for column $name of table $tbl_name.");
						return false;
					}
				} elseif ($attrs['type'] == 'd' && array_key_exists('precision', $attrs) && array_key_exists('scale', $attrs)) {
					$field .= ' decimal('.$attrs['precision'].','.$attrs['scale'].')';
				} elseif ($attrs['type'] == 't') {
					$field .= ' text';
				} else {
					throw new \Exception("Unknown type ".$attrs['type']." for column $name of table $tbl_name.");
					return false;
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

		
		$result = self::$dbConn->query($tbl_sql);
		if($result === false) {
			throw new \Exception("Error with mysql query '$tbl_sql'. [Error]:  ".htmlspecialchars(self::$dbConn->error));
			return false;
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
		try {
			if (!self::$dbConn) {
				self::connect();
			}
		} catch (\Exception $e) {
			throw $e;
			return false;
		}

		// DEBUG
		if (defined('DEBUG_SQL_PARAMS')) {
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
		if (defined('DEBUG_SQL')) {
			error_log($query);
		}

		$stmt = self::$dbConn->prepare($query);
		if($stmt === false) {
  			throw new \Exception("Error preparing mysql statement '$query'; ".htmlspecialchars(self::$dbConn->error), 1);
  			return false;
		}
 
 		$stmt->bind_param($types, ...$bindParams);
		// call_user_func_array(array($stmt, 'bind_param'), $bindParams);

		if (!$stmt->execute()) {
			throw new \Exception("Error executing mysql statement '$query'; ".htmlspecialchars(self::$dbConn->error), 1);
			$stmt->close();
			return false;
		}

		$insertId = $stmt->insert_id;
		$stmt->close();

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
		if (!self::$dbConn) {
			self::connect();
		}

		// DEBUG
		if (defined('DEBUG_SQL_PARAMS')) {
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
		if (defined('DEBUG_SQL')) {
			error_log($query);
		}

		$stmt = self::$dbConn->prepare($query);
		if($stmt === false) {
			error_log("Error preparing mysql statement '$query'; ".htmlspecialchars(self::$dbConn->error));
  			throw new \Exception("Error preparing mysql statement '$query'; ".htmlspecialchars(self::$dbConn->error), 1);
  			return false;
		}
 
 		$stmt->bind_param($types, ...$bindParams);
		// call_user_func_array(array($stmt, 'bind_param'), $bindParams);

		if (!$stmt->execute()) {
			error_log("Error executing mysql statement '$query'; ".htmlspecialchars(self::$dbConn->error));
			throw new \Exception("Error executing mysql statement '$query'; ".htmlspecialchars(self::$dbConn->error), 1);
			$stmt->close();
			return false;
		}

		$stmt->close();
		
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
		if (!self::$dbConn) {
			self::connect();
		}

		$query = "DELETE FROM `$table` WHERE id = ?";

		// DEBUG
		if (defined('DEBUG_SQL')) {
			error_log($query);
		}

		$stmt = self::$dbConn->prepare($query);
		if($stmt === false) {
  			throw new \Exception("Error preparing mysql statement '$query'; ".htmlspecialchars(self::$dbConn->error), 1);
  			return false;
		}
 
		$stmt->bind_param('i', $id);

		if (!$stmt->execute()) {
			throw new \Exception("Error executing mysql statement '$query'; ".htmlspecialchars(self::$dbConn->error), 1);
			$stmt->close();
			return false;
		}

		$stmt->close();
		
		return true;
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
		if (!self::$dbConn) {
			self::connect();
		}

		$query = "SELECT `$table`.* FROM `$table`";

		$join_query = "";

		$empty_cond = false;
		$first = true;

		if (is_array($join)) {
			foreach($join as $j) {
				$query .= ", `{$j['table']}`";
				if (empty($condition)) {
					$condition = " WHERE `$table`.`{$j['main_table_idx']}` = `{$j['table']}`.`{$j['table_idx']}`";
					$empty_cond = true;
				} else {
					$join_query .= (($first && !$empty_cond) ? " WHERE " : " AND ")."`$table`.`{$j['main_table_idx']}` = `{$j['table']}`.`{$j['table_idx']}`";
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
			$query .= "$join_query $condition";
		}

		// DEBUG
		if (defined('DEBUG_SQL')) {
			error_log($query);
		}

		$result = self::$dbConn->query($query);
		if($result === false) {
  			throw new \Exception("Error with mysql query '$query'. [Error]:  ".htmlspecialchars(self::$dbConn->error));
  			return false;
		}

		$data = array();
		while ($row = $result->fetch_assoc()) {
			$data[] = $row;
		}

		$result->free();
		
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
		if (!self::$dbConn) {
			self::connect();
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

		$result = self::$dbConn->query($query);
		if($result === false) {
  			throw new \Exception("Error with mysql query '$query'. [Error]:  ".htmlspecialchars(self::$dbConn->error));
  			return false;
		}

		$data = array();
		while ($row = $result->fetch_assoc()) {
			$data[] = $row;
		}

		$result->free();
		
		return $data;
	}

	/*
	 * Execute custom SQL query (only selects)
	 *
	 * @param string $sql
	 */
	public static function query($sql)
	{
		if (!self::$dbConn) {
			self::connect();
		}

		$query = "SELECT ".$sql;

		// DEBUG
		if (defined('DEBUG_SQL')) {
			error_log($query);
		}

		$result = self::$dbConn->query($query);
		if($result === false) {
  			throw new \Exception("Error with mysql query '$query'. [Error]:  ".htmlspecialchars(self::$dbConn->error));
  			return false;
		}

		$data = array();
		while ($row = $result->fetch_assoc()) {
			$data[] = $row;
		}

		$result->free();
		
		return $data;
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
		if (!self::$dbConn) {
			self::connect();
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

		$result = self::$dbConn->query($query);
		if($result === false) {
  			throw new \Exception("Error with mysql query '$query'. [Error]:  ".htmlspecialchars(self::$dbConn->error));
  			return false;
		}

		$data = array();
		while ($row = $result->fetch_assoc()) {
			$data[] = $row;
		}

		$result->free();
		
		return $data;
	}

	/*
	 * Return table count
	 *
	 * @param string $table
	 * @param string $condition
	 */
	public static function count($table, $condition = null, $join = null)
	{
		if (!self::$dbConn) {
			self::connect();
		}

		$query = "SELECT count(`$table`.`id`) as count FROM `$table`";

		$join_query = "";

		$empty_cond = false;
		$first = true;

		if (is_array($join)) {
			foreach($join as $j) {
				$query .= ", `{$j['table']}`";
				if (empty($condition)) {
					$condition = " WHERE `$table`.`{$j['main_table_idx']}` = `{$j['table']}`.`{$j['table_idx']}`";
					$empty_cond = true;
				} else {
					$join_query .= (($first && !$empty_cond) ? " WHERE " : " AND ")."`$table`.`{$j['main_table_idx']}` = `{$j['table']}`.`{$j['table_idx']}`";
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

		$result = self::$dbConn->query($query);
		if($result === false) {
  			throw new \Exception("Error with mysql query '$query'. [Error]:  ".htmlspecialchars(self::$dbConn->error));
  			return false;
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
}

?>
