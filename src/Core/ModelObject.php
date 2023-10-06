<?php

namespace Kyte\Core;

/**
 * Class ModelObject
 * 
 * Rrepresents a generic model object that can be used to interact with a database table.
 *
 * @package Kyte\Core
 */
class ModelObject
{
	/**
     * @var array $kyte_model key-value describing model
     *
     * [
     *     'name'       => 'name of table (also name of object)',
     *     'struct'     => [
     *         'column name' => [
     *             'type'        => 'i/s/d',     (*required*)
     *             'required'    => true/false,  (*required*)
     *             'pk'          => true/false,
     *             'unsigned'    => true/false,
	 *             'protected'    => true/false,
     *             'text'        => true/false,
     *             'size'        => integer,
     *             'default'     => value,
     *             'precision'   => integer,     (* for decimal type *)
     *             'scale'       => integer,     (* for decimal type *)
     *             'date'        => true/false,  (*required*)
     *             'kms'         => true/false,
     *         ],
     *         ...
     *         'column name' => [ 'type' => 'i/s/d', 'required' => true/false ],
     *     ]
     * ]
     */
	public $kyte_model;

	/**
     * ModelObject constructor.
     *
     * @param array $model
     */
	public function __construct($model) {
		$this->kyte_model = $model;
	}

	/**
     * Return bind param types based on params for each subclass that extends this.
     * Can be overridden by child class.
     *
     * @param array $params
     * @return string
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

	/**
     * Check if the minimum required params for SQL insert query are met.
     * Can be overridden by child.
     *
     * @param array $params
     * @throws \Exception
     * @return bool
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

	/**
     * Create a new entry in the Object_core database.
     *
     * @param array $params
     * @param mixed|null $user
     * @throws \Exception
     * @return bool
     */
	public function create($params, $user = null)
	{
		$this->validateRequiredParams($params);

		// audit attributes - set date created
		$params['deleted'] = 0;
		$params['date_created'] = time();
		$params['created_by'] = isset($params['created_by']) ? $params['created_by'] : $user;

		try {
			$types = $this->bindTypes($params);
			// check db context
			if (isset($this->kyte_model['appId'])) {
				\Kyte\Core\Api::dbswitch(true);
			} else {
				\Kyte\Core\Api::dbswitch();
			}
			// execute query
			$id = \Kyte\Core\DBI::insert($this->kyte_model['name'], $params, $types);
			$params['id'] = $id;
			$this->populate($params);

			return true;
		} catch (\Exception $e) {
			throw $e;
			return false;
		}
	}

	/**
     * Retrieve entry information with specified conditions.
     *
     * @param string|null $field
     * @param string|null $value
     * @param array|null $conditions
     * @param int|null $id
     * @param bool $all
     * @throws \Exception
     * @return mixed
     */
	/***** TODO : PHASE OUT ID */
	public function retrieve($field = null, $value = null, $conditions = null, $id = null, $all = false)
	{
		try {
			if (isset($field, $value)) {
				$escaped_value = is_string($value) ? \Kyte\Core\DBI::escape_string($value) : $value;
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
						$escaped_value = \Kyte\Core\DBI::escape_string($condition['value']);
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

			// check db context
			if (isset($this->kyte_model['appId'])) {
				\Kyte\Core\Api::dbswitch(true);
			} else {
				\Kyte\Core\Api::dbswitch();
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

	/**
     * Update entry information for item that was retrieved.
     *
     * @param array $params
     * @param mixed|null $user
     * @throws \Exception
     * @return bool
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
			// check db context
			if (isset($this->kyte_model['appId'])) {
				\Kyte\Core\Api::dbswitch(true);
			} else {
				\Kyte\Core\Api::dbswitch();
			}
			// execute query
			\Kyte\Core\DBI::update($this->kyte_model['name'], $id, $params, $types);

			// populate with new data
			$this->populate($params);

			return true;
		} catch (\Exception $e) {
			throw $e;
			return false;
		}
	}

	/**
	 * Populate object with entry information
	 *
	 * @param mixed $o The object ID or an array of key-value pairs representing the entry information.
	 * @return bool Returns true if the object is successfully populated, false otherwise.
	 * @throws \Exception Throws an exception if no object ID is provided or if a non-integer is passed for ID.
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

	/**
	 * Delete entry information with specified conditions - will only mark item as deleted.
	 *
	 * @param string|null $field The field to use for the condition.
	 * @param string|null $value The value to match for the condition.
	 * @param int|null $user The ID of the user performing the deletion.
	 * @return bool Returns true if the entry is successfully marked as deleted, false otherwise.
	 * @throws \Exception Throws an exception if no entry is found for the provided condition or if no condition or prior entry information is provided for deletion.
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

			// check db context
			if (isset($this->kyte_model['appId'])) {
				\Kyte\Core\Api::dbswitch(true);
			} else {
				\Kyte\Core\Api::dbswitch();
			}
			// set deleted flag and audit attribute - date deleted
			\Kyte\Core\DBI::update($this->kyte_model['name'], $id, ['date_deleted' => time(), 'deleted' => 1, 'deleted_by' => $user], 'iii');

			return true;
		} catch (\Exception $e) {
			throw $e;
			return false;
		}
	}

	/**
	 * Purge method will actually delete from the database.
	 *
	 * @param string|null $field The field to use for the condition.
	 * @param string|null $value The value to match for the condition.
	 * @return bool Returns true if the entry is successfully deleted from the database, false otherwise.
	 * @throws \Exception Throws an exception if no entry is found for the provided condition or if no condition or prior entry information is provided for deletion.
	 */
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

			// check db context
			if (isset($this->kyte_model['appId'])) {
				\Kyte\Core\Api::dbswitch(true);
			} else {
				\Kyte\Core\Api::dbswitch();
			}
			// execute query
			\Kyte\Core\DBI::delete($this->kyte_model['name'], $id);
			$this->clearParams();

			return true;
		} catch (\Exception $e) {
			throw $e;
			return false;
		}
	}

	/**
	 * Set the value of a parameter in the object.
	 *
	 * @param string $key The key of the parameter.
	 * @param mixed $value The value to set for the parameter.
	 * @return void
	 */
	protected function setParam($key, $value) {
		if (is_null($value)) {
			$this->{$key} = $value;
			return;
		}

		if (array_key_exists($key, $this->kyte_model['struct'])) {
			if (STRICT_TYPING) {
				// check if type is t, in which case return 's'
				// otherwise return type as is
				switch ($this->kyte_model['struct'][$key]['type']) {
					case 's':
					case 't':
						$this->{$key} = strval($value);
						break;

					case 'i':
						$this->{$key} = intval($value);
						break;

					case 'd':
						$this->{$key} = floatval($value);
						break;
						
					default:
						$this->{$key} = $value;
						break;
				}
			} else {
				$this->{$key} = strval($value);
			}
		} else {
			// allow for non model defined properties
			$this->{$key} = $value;
		}
	}

	/**
	 * Get the value of a parameter from the object.
	 *
	 * @param string $key The key of the parameter.
	 * @return mixed|bool Returns the value of the parameter if it exists, or false if it doesn't.
	 */ 
	public function getParam($key) {
		if (isset($this->{$key})) {
			return $this->{$key};
		} else {
			return false;
		}
	}

	/**
	 * Get the values of multiple parameters from the object.
	 *
	 * @param array $keys An array of keys for the parameters.
	 * @return array An array of key-value pairs representing the parameter values.
	 */
	public function getParams($keys) {
		$retvals = [];
		foreach ($keys as $key) {
			$retvals[$key] = (isset($this->{$key}) ? $this->{$key} : null);
		}
		return $retvals;
	}

	/**
	 * Get all the parameters and their values from the object.
	 *
	 * @param string|null $dateformat The format to use for date values. Defaults to null.
	 * @return array An array of key-value pairs representing all the parameters and their values.
	 */
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

	/**
	 * Clear all the parameters in the object.
	 *
	 * @return void
	 */
	protected function clearParams() {
		$vars = get_object_vars($this);

		foreach ($vars as $key => $value) {
			unset($this->{$key});
		}
	}

	/**
	 * Get the keys of all the parameters in the object.
	 *
	 * @return array An array of parameter keys.
	 */
	public function paramKeys() {
		$vars = get_object_vars($this);

		if (RETURN_NO_MODEL) unset($vars['kyte_model']);
		
		return array_keys($vars);
	}

}
