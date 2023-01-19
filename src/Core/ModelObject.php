<?php

namespace Kyte\Core;

/*
 * Class ModelObject
 *
 * @package Kyte
 *
 */

class ModelObject
{
	// key-value describing model
	// 
	//	[
	// 		'name'		=> 'name of table (also name of object)',
	// 		'struct'	=> [
	//			'column name' => [
	//				'type'		=>	'i/s/d',		(*required*)
	// 				'requred'	=>	true/false,		(*required*)
	// 				'pk'		=>	true/false,
	// 				'unsigned'	=>	true/false,
	// 				'text'		=>	true/false,
	// 				'size'		=>	integer,
	//				'default'	=>	value,
	// 				'precision'	=>	integer,		(* for decimal type *)
	// 				'scale'		=>	integer,		(* for decimal type *)
	// 				'date'		=>	true/false,		(*required*)
	// 				'kms'		=>	true/false,
	//		 	],
	//			...
	//			'column name' => [ 'type' => 'i/s/d', 'requred' => true/false ],
	//		]
	//	]
	public $kyte_model;

	public function __construct($model) {
		$this->kyte_model = $model;

		if (isset($this->kyte_model['appId'])) {
			// if app id is set, perform context switch
			$app = new \Kyte\Core\ModelObject(Application);
			if (!$app->retrieve('identifier', $this->kyte_model['appId'])) {
				throw new \Exception("CRITICAL ERROR: Unable to find application and perform context switch.");
			}
			\Kyte\Core\Api::dbswitch($app->db_name, $app->db_username, $app->db_password, $app->db_host ? $app->db_host : null);
		} else {
			// otherwise use main db
			\Kyte\Core\Api::dbconnect();
		}
	}

	/*
	 * Return bind param types based on params for each subclass that exteds this
	 * Can be overridden by child class
	 *
	 * @param array $params
	 */
	protected function bindTypes(&$params) {
		$types = '';
		foreach ($params as $key => $value) {
			if (array_key_exists($key, $this->kyte_model['struct'])) {
				// check if type is t, in which case return 's'
				// otherwise return type as is
				$types .= $this->kyte_model['struct'][$key]['type'] == 't' ? 's' : $this->kyte_model['struct'][$key]['type'];
			} else {
				unset($params[$key]);
			}
		}

		return $types;
	}

	/*
	 * Check if the minimum required params for SQL insert query are met
	 * Can be overridden by child
	 *
	 * @param array $params
	 */
	protected function validateRequiredParams($params) {
		if (count($params) == 0) {
			throw new \Exception("Unable to create new entry without valid parameters.");
			return false;
		} else {
			foreach ($this->kyte_model['struct'] as $key => $value) {
				if ($value['required'] && !isset($value['pk']) && !isset($params[$key])) {
					throw new \Exception("Column $key cannot be null.");
					return false;
				}
			}
		}
	}

	/*
	 * Create a new entry in the Object_core database
	 *
	 * @param array $params
	 */
	public function create($params, $user = null)
	{
		$this->validateRequiredParams($params);

		// audit attributes - set date created
		$params['date_created'] = time();
		$params['created_by'] = isset($params['created_by']) ? $params['created_by'] : $user;

		try {
			$types = $this->bindTypes($params);
			$id = \Kyte\Core\DBI::insert($this->kyte_model['name'], $params, $types);
			$params['id'] = $id;
			$this->populate($params);

			return true;
		} catch (\Exception $e) {
			throw $e;
			return false;
		}
	}

	/*
	 * Retrieve entry information with specified conditions
	 *
	 * @param string $field
	 * @param string $value
	 * @param integer $id
	 */
	/***** TODO : PHASE OUT ID */
	public function retrieve($field = null, $value = null, $conditions = null, $id = null, $all = false)
	{
		try {
			if (isset($field, $value)) {
				$escaped_value = addcslashes(\Kyte\Core\DBI::escape_string($value), '%_');
				$sql = $all ? "WHERE `$field` = '$escaped_value'" : "WHERE `$field` = '$escaped_value' AND `deleted` = '0'";
			} else {
				$sql = '';
				if (!$all) {
					$sql .= " WHERE `deleted` = '0'";
				}
			}

			// if conditions are set, add them to the sql statement
			if(isset($conditions)) {
				if (!empty($conditions)) {
					// iterate through each condition
					foreach($conditions as $condition) {
						$escaped_value = addcslashes(\Kyte\Core\DBI::escape_string($condition['value']), '%_');
						// check if an evaluation operator is set
						if (isset($condition['operator'])) {
							if ($sql != '') {
								$sql .= " AND ";
							}
							$sql .= "`{$condition['field']}` {$condition['operator']} '{$escaped_value}'";
						}
						// default to equal
						else {
							if ($sql != '') {
								$sql .= " AND ";
							}
							$sql .= "`{$condition['field']}` = '{$escaped_value}'";
						}
					}
				}
			}

			// execute DB query
			$data = \Kyte\Core\DBI::select($this->kyte_model['name'], null, $sql);

			if (count($data) > 0) {
				return $this->populate($data[0]);
			} else {
				return false;
			}
		} catch (\Exception $e) {
			throw $e;
			return false;
		}
	}

	/*
	 * Update entry information for item that was retrieved
	 *
	 * @param array $params
	 */
	public function save($params, $user = null)
	{
		$id = $this->id;
		if (!isset($id)) {
			throw new \Exception("No retrieved data to update.  Please try retrieving information with retrieve() first.");
			return false;
		}

		// audit attributes - set date modified
		$params['date_modified'] = time();
		$params['modified_by'] = isset($params['modified_by']) ? $params['modified_by'] : $user;

		try {
			$types = $this->bindTypes($params);
			\Kyte\Core\DBI::update($this->kyte_model['name'], $id, $params, $types);
			$this->populate($params);
			return true;
		} catch (\Exception $e) {
			throw $e;
			return false;
		}
	}

	/*
	 * Populate object with entry information
	 *
	 */
	public function populate($o = null)
	{
		try {
			if (!isset($o)) {
				throw new \Exception("No object id was found to retrieve data.");
				return false;
			}

			if (is_array($o)) {
				if (count($o) == 0) { error_log("count is empty..."); return false; }

				foreach ($o as $key => $value) {
					if (array_key_exists($key, $this->kyte_model['struct'])) {
						$this->setParam($key, $value);
					}
				}
			} else {
				// if $id is null from parameter, set it to the object's id value
				if (!is_int($o)) {
					throw new \Exception("A non-integer was passed for ID.");
					return false;
				}

				$data = \Kyte\Core\DBI::select($this->kyte_model['name'], $o);

				if (count($data[0]) == 0) { return false; }

				foreach ($data[0] as $key => $value) {
					$this->setParam($key, $value);
				}
			}
			
			return true;
		} catch (\Exception $e) {
			throw $e;
			return false;
		}
	}

	/*
	 * Delete entry information with specified conditions - will only mark item as deleted
	 *
	 * @param string $field
	 * @param string $value
	 * @param integer $id
	 */
	public function delete($field = null, $value = null, $user = null)
	{
		try {
			if (isset($field, $value)) {
				if ($this->retrieve($field, $value)) {
					$id = $this->id;
				} else {
					throw new \Exception("No entry found for provided condition");
				}
			} else if (!isset($field, $value, $id)) {
				$id = $this->id;
			}
				
			// last check to make sure id is set
			if (!isset($id)) {
				throw new \Exception("No condition or prior entry information was provided for data to be deleted.");
				return false;
			}

			// set deleted flag and audit attribute - date deleted
			\Kyte\Core\DBI::update($this->kyte_model['name'], $id, ['date_deleted' => time(), 'deleted' => 1, 'deleted_by' => $user], 'iii');

			return true;
		} catch (\Exception $e) {
			throw $e;
			return false;
		}
	}

	// purge method will actually delete from database
	public function purge($field = null, $value = null)
	{
		try {
			if (isset($field, $value)) {
				if ($this->retrieve($field, $value, null, null, true)) {
					$id = $this->id;
				} else {
					throw new \Exception("No entry found for provided condition");
				}
			} else if (!isset($field, $value, $id)) {
				$id = $this->id;
			}
				
			// last check to make sure id is set
			if (!isset($id)) {
				throw new \Exception("No condition or prior entry information was provided for data to be deleted.");
				return false;
			}

			\Kyte\Core\DBI::delete($this->kyte_model['name'], $id);
			$this->clearParams();

			return true;
		} catch (\Exception $e) {
			throw $e;
			return false;
		}
	}

	protected function setParam($key, $value) {
		$this->{$key} = $value;
	}

	public function getParam($key) {
		if (isset($this->{$key})) {
			return $this->{$key};
		} else {
			return false;
		}
	}

	public function getParams($keys) {
		$retvals = [];
		foreach ($keys as $key) {
			$retvals[$key] = (isset($this->{$key}) ? $this->{$key} : null);
		}
		return $retvals;
	}

	public function getAllParams($dateformat = null) {
		$vars = get_object_vars($this);

		if (RETURN_NO_MODEL) unset($vars['kyte_model']);

		if ($dateformat) {
			$retvals = [];

			foreach ($vars as $key => $value) {
				if (array_key_exists($key, $this->kyte_model['struct'])) {
					if ($this->kyte_model['struct'][$key]['date']) {
						$retvals[$key] = ($value > 0 ? date($dateformat, $value) : '');
					} else {
						$retvals[$key] = $value;
					}
				} else {
					$retvals[$key] = $value;
				}
			}

			return $retvals;
		} else {
			return $vars;
		}
	}

	protected function clearParams() {
		$vars = get_object_vars($this);

		foreach ($vars as $key => $value) {
			unset($this->{$key});
		}
	}

	public function paramKeys() {
		$vars = get_object_vars($this);

		if (RETURN_NO_MODEL) unset($vars['kyte_model']);
		
		return array_keys($vars);
	}

}
