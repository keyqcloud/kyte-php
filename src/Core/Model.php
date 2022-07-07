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

	public $total = 0;

	private $page_size;
	private $page_num;

	public function __construct($model, $page_size = null, $page_num = null) {
		$this->kyte_model = $model;
		$this->page_size = $page_size;
		$this->page_num = $page_num;
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

			if(isset($conditions)) {
				if (!empty($conditions)) {
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
			}

			$join = null;
			if (isset($_SERVER['HTTP_X_KYTE_PAGE_SEARCH_FIELDS'], $_SERVER['HTTP_X_KYTE_PAGE_SEARCH_VALUE'])) {
				$search_fields = explode(",", $_SERVER['HTTP_X_KYTE_PAGE_SEARCH_FIELDS']);
				$search_value = $_SERVER['HTTP_X_KYTE_PAGE_SEARCH_VALUE'];
				$c = count($search_fields);
				if ($c > 0 && !empty($search_value)) {
					$sql .= " AND (";

					$i = 1;
					foreach($search_fields as $sf) {
						$f = explode(".", $sf);
						if (count($f) == 1) {
							if ($i < $c) {
								$sql .= " `$sf` LIKE '%$search_value%' OR";
								$i++;
							} else {
								$sql .= " `$sf` LIKE '%$search_value%' ";
							}
						} else if (count($f) == 2) {
							if ($i < $c) {
								$sql .= " `{$f[0]}`.`{$f[1]}` LIKE '%$search_value%' OR";
								$i++;
							} else {
								$sql .= " {$f[0]}`.`{$f[1]}` LIKE '%$search_value%' ";
							}
						} else {
							throw new \Exception("Unsupported field depth $sf");
						}
					}

					$sql .= ")";
				}
			}

			// get total count
			$this->total = \Kyte\Core\DBI::count($this->kyte_model['name'], $sql);

			if (isset($order)) {
				if (!empty($order)) {
					$order_sql = ' ORDER BY ';
					for($i = 0; $i < count($order); $i++) {
						if (isset($order[$i]['field'], $order[$i]['direction'])) {
							$direction = strtoupper($order[$i]['direction']);
							if ($direction == 'ASC' || $direction == 'DESC') {
								$order_sql .= " `{$order[$i]['field']}` {$direction}";
								if ($i < (count($order) - 1)) {
									$order_sql .= ', ';
								}
							}
						}
					}
					$sql .= $order_sql;
				}
			} else {
				$sql .= " ORDER BY `date_created` DESC";
			}

			// if paging is set, add limit
			if ($this->page_size && $this->page_num) {
				$offset = $this->page_size * ($this->page_num - 1);
				$sql .= " LIMIT {$this->page_size} OFFSET $offset";
			}

			$data = \Kyte\Core\DBI::select($this->kyte_model['name'], null, $sql, $join);

			foreach ($data as $item) {
				$obj = new \Kyte\Core\ModelObject($this->kyte_model);
				// $obj->retrieve('id', $item['id'], null, null, $all);
				$obj->populate($item);
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
				if (!empty($conditions)) {
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
}
?>
