<?php

namespace Kyte\Core;

/*
 * Class Model
 *
 * @package Kyte
 *
 */

class Model
{
	public $kyte_model;

	public $objects = [];

	public function __construct($model) {
		$this->kyte_model = $model;
	}

	public function retrieve($field = null, $value = null, $isLike = false, $conditions = null, $all = false, $order = null)
	{
		try {
			$dataObjects = array();
			$data = array();

			if (isset($field, $value)) {
				if ($isLike) {
					$sql = "WHERE `$field` LIKE '%$value%'";
				} else {
					$sql = "WHERE `$field` = '$value'";
				}

				if (!$all) {
					$sql .= " AND `deleted` = '0'";
				}
			} else {
				$sql = '';
				if (!$all) {
					$sql .= " WHERE `deleted` = '0'";
				}
			}

			// if conditions are set, add them to the sql statement
			if(isset($conditions)) {
				// iterate through each condition
				foreach($conditions as $condition) {
					// check if an evaluation operator is set
					if (isset($condition['operator'])) {
						if ($sql != '') {
							$sql .= " AND ";
						}
						$sql .= "`{$condition['field']}` {$condition['operator']} '{$condition['value']}'";
					}
					// default to equal
					else {
						if ($sql != '') {
							$sql .= " AND ";
						}
						$sql .= "`{$condition['field']}` = '{$condition['value']}'";
					}
				}
			}

			if (isset($order)) {
				if (isset($order['field'], $order['direction'])) {
					$order['direction'] = strtoupper($order['direction']);
					if ($order['direction'] == 'ASC' || $order['direction'] == 'DESC') {
						$sql .= " ORDER BY `{$order['field']}` {$order['direction']}";
					}
				}
			} else {
				$sql .= " ORDER BY `date_created` DESC";
			}

			$data = \Kyte\Core\DBI::select($this->kyte_model['name'], null, $sql);

			foreach ($data as $item) {
				$obj = new \Kyte\Core\ModelObject($this->kyte_model);
				$obj->retrieve('id', $item['id'], null, null, $all);
				$dataObjects[] = $obj;
			}

			$this->objects = $dataObjects;

			return true;
		} catch (\Exception $e) {
			throw $e;
			return false;
		}
	}

	public function groupBy($field = null, $conditions = null, $all = false)
	{
		try {
			$sql = '';
			if (!$all) {
				$sql .= " WHERE `deleted` = '0'";
			}

			if(isset($conditions)) {
				// iterate through each condition
				foreach($conditions as $condition) {
					// check if an evaluation operator is set
					if (isset($condition['operator'])) {
						if ($sql != '') {
							$sql .= " AND ";
						}
						$sql .= "`{$condition['field']}` {$condition['operator']} '{$condition['value']}'";
					}
					// default to equal
					else {
						if ($sql != '') {
							$sql .= " AND ";
						}
						$sql .= "`{$condition['field']}` = '{$condition['value']}'";
					}
				}
			}

			$data = \Kyte\Core\DBI::group($this->kyte_model['name'], $field, $sql);
			
			return $data;

		} catch (\Exception $e) {
			throw $e;
			return false;
		}
	}

	public function customSelect($sql)
	{
		try {
			$data = \Kyte\Core\DBI::query($sql);
			
			return $data;

		} catch (\Exception $e) {
			throw $e;
			return false;
		}
	}

	public function search($fields = null, $values = null, $all = false)
	{
		try {
			$dataObjects = array();
			$data = array();

			if (isset($fields, $values)) {
				
				if (!$all) {
					$sql = "WHERE (";
				} else {
					$sql = "WHERE ";
				}

				$first = true;
				foreach($fields as $field) {
					foreach($values as $value) {
						if ($first) {
							$sql .= "`$field` LIKE '%$value%'";
							$first = false;
						} else
							$sql .= " OR `$field` LIKE '%$value%'";
					}
				}

				if (!$all) {
					$sql .= ") AND `deleted` = '0'";
				}
				
				$data = \Kyte\Core\DBI::select($this->kyte_model['name'], null, $sql);
			} else {
				$data = $all ? \Kyte\Core\DBI::select($this->kyte_model['name'], null, null) : \Kyte\Core\DBI::select($this->kyte_model['name'], null, "WHERE `deleted` = '0'");
			}

			foreach ($data as $item) {
				$obj = new \Kyte\Core\ModelObject($this->kyte_model);
				$obj->retrieve('id', $item['id'], null, null, $all);
				$dataObjects[] = $obj;
			}

			$this->objects = $dataObjects;

			return true;
		} catch (\Exception $e) {
			throw $e;
			return false;
		}
	}

	public function from($field, $start, $end, $equalto = false, $all = false)
	{
		try {
			$dataObjects = array();
			$data = array();

			if (!isset($field, $value)) throw new \Exception("Range is required and was not provided.");

			$sql = "WHERE `$field` > '$start' AND `$field` < '$end'";
			if ($equalto) {
				$sql = "WHERE `$field` >= '$start' AND `$field` <= '$end'";
			}

			if (!$all) {
				$sql .= " AND `deleted` = '0'";
			}

			$data = \Kyte\Core\DBI::select($this->kyte_model['name'], null, $sql);

			foreach ($data as $item) {
				$obj = new \Kyte\Core\ModelObject($this->kyte_model);
				$obj->retrieve('id', $item['id'], null, null, $all);
				$dataObjects[] = $obj;
			}

			$this->objects = $dataObjects;

			return true;
		} catch (\Exception $e) {
			throw $e;
			return false;
		}
	}

	/*
	 * Returns array count of objects in Model
	 *
	 */
	public function count()
	{
		return count($this->objects);
	}

	public function returnFirst()
	{
		if ($this->count() > 0) {
			return $this->objects[0];
		}
		return null;
	}

	protected function clearModel()
	{
		$this->objects = [];
	}
}
?>
