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
	public $total_filtered = 0;

	private $page_size;
	private $page_num;
	private $search_fields;
	private $search_value;

	public function __construct($model, $page_size = null, $page_num = null, $search_fields = null, $search_value = null) {
		$this->kyte_model = $model;
		$this->page_size = $page_size;
		$this->page_num = $page_num;
		$this->search_fields = $search_fields;
		$this->search_value = $search_value;

        if (isset($this->kyte_model['appId'])) {
			\Kyte\Core\Api::dbswitch(true);
		} else {
			\Kyte\Core\Api::dbswitch();
		}
	}

	public function retrieve($field = null, $value = null, $isLike = false, $conditions = null, $all = false, $order = null, $limit = null)
	{
		try {
			$dataObjects = array();
			$data = array();

			$main_tbl = $this->kyte_model['name'];

			if (isset($field, $value)) {
				$escaped_value = \Kyte\Core\DBI::escape_string($value);
				if ($isLike) {
					$escaped_value = addcslashes($escaped_value, '%_');
					$sql = "WHERE `$main_tbl`.`$field` LIKE '%$escaped_value%'";
				} else {
					$sql = "WHERE `$main_tbl`.`$field` = '$escaped_value'";
				}

				if (!$all) {
					$sql .= " AND `$main_tbl`.`deleted` = '0'";
				}
			} else {
				$sql = '';
				if (!$all) {
					$sql .= " WHERE `$main_tbl`.`deleted` = '0'";
				}
			}
			
			// Check if header fields for range are set and add to SQL query
			if (isset($_SERVER['HTTP_X_KYTE_RANGE_FIELD_NAME'], $_SERVER['HTTP_X_KYTE_RANGE_FIELD_START'], $_SERVER['HTTP_X_KYTE_RANGE_FIELD_END']) && strlen($_SERVER['HTTP_X_KYTE_RANGE_FIELD_NAME']) > 0 && is_numeric($_SERVER['HTTP_X_KYTE_RANGE_FIELD_START']) && is_numeric($_SERVER['HTTP_X_KYTE_RANGE_FIELD_END'])) {
				if ($sql != '') {
					$sql .= " AND ";
				}
				$sql .= "`$main_tbl`.`{$_SERVER['HTTP_X_KYTE_RANGE_FIELD_NAME']}` >= {$_SERVER['HTTP_X_KYTE_RANGE_FIELD_START']} AND `$main_tbl`.`{$_SERVER['HTTP_X_KYTE_RANGE_FIELD_NAME']}` <= {$_SERVER['HTTP_X_KYTE_RANGE_FIELD_END']}";
			}

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
							$sql .= "`$main_tbl`.`{$condition['field']}` {$condition['operator']} '{$escaped_value}'";
						}
						// default to equal
						else {
							if ($sql != '') {
								$sql .= " AND ";
							}
							$sql .= "`$main_tbl`.`{$condition['field']}` = '{$escaped_value}'";
						}
					}
				}
			}

			$join = null;
			$page_sql = "";

			if (isset($this->search_fields, $this->search_value)) {
				$escaped_value = \Kyte\Core\DBI::escape_string($this->search_value);
				$search_fields = explode(",", $this->search_fields);
				$c = count($search_fields);

				// foreign key tables - track tables and if same tables are identified, create an alias
				$fk_tables = [];
				if ($c > 0 && !empty($this->search_value)) {
					$page_sql .= " AND (";

					$i = 1;
					
					foreach($search_fields as $sf) {
						$f = explode(".", $sf);
						if (count($f) == 1) {
							if (!array_key_exists($sf, $this->kyte_model['struct'])) {
								$i++;
								continue;
							}
							if ($i < $c) {
								$page_sql .= " `$main_tbl`.`$sf` LIKE '%{$escaped_value}%' OR";
								$i++;
							} else {
								$page_sql .= " `$main_tbl`.`$sf` LIKE '%{$escaped_value}%' ";
							}
						} else if (count($f) == 2) {
							if (!array_key_exists($f[0], $this->kyte_model['struct'])) {
								$i++;
								continue;
							}
							// initialize alias name as null
							$tbl_alias = null;

							// get struct for FK
							$fk_attr = $this->kyte_model['struct'][$f[0]];
							// capitalize the first letter for table name
							$tblName = $fk_attr['fk']['model'];
							$tbl = $tblName;
							if (in_array($tblName, $fk_tables)) {
								// generate alias
								$tbl_alias = $tblName.bin2hex(random_bytes(5));
								$tbl = $tbl_alias;
							} else {
								$fk_tables[] = $tblName;
							}

							if ($i < $c) {
								$page_sql .= " `$tbl`.`{$f[1]}` LIKE '%{$escaped_value}%' OR";
								$i++;
							} else {
								$page_sql .= " `$tbl`.`{$f[1]}` LIKE '%{$escaped_value}%' ";
							}

							// prepare join statement

							// if join is null, initialize with empty array
							if (!$join) {
								$join = [];
							}

							$join[] = [
								'table' => $tblName,
								'main_table_idx' => $f[0],
								'table_idx' => $fk_attr['fk']['field'],
								'table_alias' => $tbl_alias,
							];
						} else {
							throw new \Exception("Unsupported field depth $sf");
						}
					}

					$page_sql .= ")";
				}
			}

			// get total count
			$this->total = \Kyte\Core\DBI::count($this->kyte_model['name'], $limit > 0 ? $sql." LIMIT $limit" : $sql);

			// add page sql
			$sql .= $page_sql;
			// count join
			$this->total_filtered = \Kyte\Core\DBI::count($this->kyte_model['name'], $limit > 0 ? $sql." LIMIT $limit" : $sql, $join);

			if (!empty($order)) {
                $order_sql = ' ORDER BY ';
                //Hack to check if something has been added to the ORDER BY sentence by using string length instead of
                //a syntax check which is costly
                $originalOrderByStatementLen = strlen($order_sql);

                //Order field will work if formatted on a multidimensional array, this allows order combining
                for($i = 0; $i < count($order); $i++) {
                    if (isset($order[$i]['field'], $order[$i]['direction'])) {
                        $direction = strtoupper($order[$i]['direction']);
                        if ($direction == 'ASC' || $direction == 'DESC') {
                            $f = explode(".", $order[$i]['field']);
                            if (count($f) == 1) {
                                $order_sql .= " `$main_tbl`.`{$order[$i]['field']}` {$direction}";
                            } else if (count($f) == 2) {
                                // get struct for FK
                                $fk_attr = $this->kyte_model['struct'][$f[0]];

                                // capitalize the first letter for table name
                                $tblName = $fk_attr['fk']['model'];
                                $order_sql .= " `$tblName`.`{$f[1]}` {$direction}";

                                // prepare join statement

                                // if join is null, initialize with empty array
                                if (!$join) {
                                    $join = [];
                                }

                                $found = false;
                                foreach($join as $j) {
                                    if ($j['table'] == $tblName) {
                                        $found = true;
                                        break;
                                    }
                                }
                                if (!$found) {
                                    $join[] = [
                                        'table' => $tblName,
                                        'main_table_idx' => $f[0],
                                        'table_idx' => $fk_attr['fk']['field'],
                                    ];
                                }
                            } else {
                                throw new \Exception("Unsupported field depth {$order[$i]['field']}");
                            }
                            if ($i < (count($order) - 1)) {
                                $order_sql .= ', ';
                            }
                        }
                    }
                }
                //Hack: Check if something has been added to the ORDER BY sentence by string length otherwise we can get
                //a MySQL syntax error, example: ... ORDER BY (empty string here); if you pass the order parameter as
                //an unidimensional array ['field' => 'category', 'direction' => 'asc'] would crash otherwise
                if(strlen($order_sql) > $originalOrderByStatementLen){
                    $sql .= $order_sql;
                }

			} else {
				$sql .= " ORDER BY `$main_tbl`.`date_created` DESC";
			}

			// if paging is set, add limit
			if ($this->page_size && $this->page_num) {
				if ($limit > 0 && $limit < $this->page_size) {
					$sql .= " LIMIT $limit";
				} else {
					$offset = $this->page_size * ($this->page_num - 1);
					$sql .= " LIMIT {$this->page_size} OFFSET $offset";
				}
			} else {
				if ($limit > 0) {
					$sql .= " LIMIT $limit";
				}
			}

			$data = \Kyte\Core\DBI::select($this->kyte_model['name'], null, $sql, $join);

			foreach ($data as $item) {
				$obj = new \Kyte\Core\ModelObject($this->kyte_model);
				$obj->populate($item);
				$dataObjects[] = $obj;
			}

			$this->objects = $dataObjects;

			return true;
		} catch (\Exception $e) {
			throw $e;
		}
	}

	public function delete($field = null, $value = null, $isLike = false, $conditions = null, $all = false)
	{
		try {
			$dataObjects = array();
			$data = array();

			$main_tbl = $this->kyte_model['name'];

			if (isset($field, $value)) {
				$escaped_value = \Kyte\Core\DBI::escape_string($value);
				if ($isLike) {
					$escaped_value = addcslashes($escaped_value, '%_');
					$sql = "WHERE `$main_tbl`.`$field` LIKE '%$escaped_value%'";
				} else {
					$sql = "WHERE `$main_tbl`.`$field` = '$escaped_value'";
				}

				if (!$all) {
					$sql .= " AND `$main_tbl`.`deleted` = '0'";
				}
			} else {
				$sql = '';
				if (!$all) {
					$sql .= " WHERE `$main_tbl`.`deleted` = '0'";
				}
			}

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
							$sql .= "`$main_tbl`.`{$condition['field']}` {$condition['operator']} '{$escaped_value}'";
						}
						// default to equal
						else {
							if ($sql != '') {
								$sql .= " AND ";
							}
							$sql .= "`$main_tbl`.`{$condition['field']}` = '{$escaped_value}'";
						}
					}
				}
			}

			// $data = \Kyte\Core\DBI::delete($this->kyte_model['name'], null, $sql, $join);

			return true;
		} catch (\Exception $e) {
			throw $e;
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
		}
	}

	public function customQuery($sql)
	{
		try {
			$data = \Kyte\Core\DBI::query($sql);

			return $data;

		} catch (\Exception $e) {
			throw $e;
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
				$obj->populate($item);
				$dataObjects[] = $obj;
			}

			$this->objects = $dataObjects;

			return true;
		} catch (\Exception $e) {
			throw $e;
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
				$obj->populate($item);
				$dataObjects[] = $obj;
			}

			$this->objects = $dataObjects;

			return true;
		} catch (\Exception $e) {
			throw $e;
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

	public function first()
	{
		if ($this->count() > 0) {
			return $this->objects[0];
		}
		return null;
	}
	public function last()
	{
		if ($this->count() == 0) {
			return null;
		}
		return $this->objects[$this->count()];
	}

	/**
	 * Marks a entries as deleted in the database.
	 *
	 * This method marks items as deleted in the object array
	 * and clears the array.
	 * Requires that a retrieve is performed prior to calling.
	 *
	 * @return boolean
	 */
	public function deleteObjects() {
		try {
			foreach ($this->objects as $key => $obj) {
				$obj->delete();
				unset($this->objects[$key]);
			}
        
			return true;

		} catch (\Exception $e) {
			throw $e;
		}
	}

	/**
	 * Permanently deletes database entries.
	 *
	 * This method purges items in the object array to
	 * permanently delete items from database, and clears the array.
	 * Requires that a retrieve is performed prior to calling.
	 *
	 * @return boolean
	 */
	public function purgeObjects() {
		try {
			foreach ($this->objects as $key => $obj) {
				$obj->purge();
				unset($this->objects[$key]);
			}
        
			return true;

		} catch (\Exception $e) {
			throw $e;
		}
	}
}
