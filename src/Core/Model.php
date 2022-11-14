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
	}

	public function retrieve($field = null, $value = null, $isLike = false, $conditions = null, $all = false, $order = null)
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
						$escaped_value = addcslashes(\Kyte\Core\DBI::escape_string($condition['value']), '%_');
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
							if ($i < $c) {
								$page_sql .= " `$main_tbl`.`$sf` LIKE '%{$escaped_value}%' OR";
								$i++;
							} else {
								$page_sql .= " `$main_tbl`.`$sf` LIKE '%{$escaped_value}%' ";
							}
						} else if (count($f) == 2) {
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
			$this->total = \Kyte\Core\DBI::count($this->kyte_model['name'], $sql);

			// add page sql
			$sql .= $page_sql;
			// count join
			$this->total_filtered = \Kyte\Core\DBI::count($this->kyte_model['name'], $sql, $join);

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
				$offset = $this->page_size * ($this->page_num - 1);
				$sql .= " LIMIT {$this->page_size} OFFSET $offset";
			}

			error_log("______SQL_______");
			error_log($sql);
			error_log("______JOIN_______");
			error_log($join);

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
