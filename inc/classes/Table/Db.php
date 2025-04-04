<?php
namespace Core2\Classes\Table;

require_once __DIR__ . '/../Table.php';
require_once 'Db/Select.php';

use Core2\Classes\Table;


/**
 * Class Db
 * @package Core2\Classes\Table
 */
class Db extends Table {

    protected $table         = '';
    protected $primary_key   = '';

    protected $query         = '';
    protected $query_result  = '';
    protected $query_params  = '';
    protected $order         = null;
    protected $select        = null;
    protected $is_fetched    = false;
    protected $query_parts   = [];
    private $_db;
    private $cachable = false;


    /**
     * @return \Zend_Db_Select|null
     */
    public function getSelect():? \Zend_Db_Select {

        return $this->select;
    }


    /**
     * @return array|null
     */
    public function getQueryParts(): array {

        return $this->query_parts;
    }


    /**
     * Получение sql запроса который выполняется для получения данных
     * @return string
     */
    public function getQueryResult(): string {

        return $this->query_result;
    }


    /**
     * @param string $table
     * @return void
     */
    public function setTable(string $table): void {

        $this->table      = $table;
        $this->table_name = $table;

        $this->session->table->name = $this->table;

        // Из class.list
        // Нужно для удаления
        if ($this->table && $this->primary_key) {
            $this->deleteKey = "{$this->table}.{$this->primary_key}";
        }
    }


    /**
     * @param $db
     * @return void
     */
    public function setDatabase($db) {
        $this->_db = $db;
    }


    /**
     * @param string $key
     */
    public function setPrimaryKey(string $key) {
        $this->primary_key = $key;

        // Из class.list
        // Нужно для удаления
        if ($this->table && $this->primary_key) {
            $this->deleteKey = "{$this->table}.{$this->primary_key}";
        }
    }


    /**
     * @param string $query
     * @param array  $params
     */
    public function setQuery(string $query, array $params = []) {
        $this->query        = $query;
        $this->query_params = $params;
    }


    /**
     * Установка сортировки
     * @param string      $order
     * @return void
     */
    public function setOrder(string $order) {

        $this->order = $order;
    }


    /**
     * Получение данных из базы
     * @return Row[]
     * @throws \Zend_Db_Select_Exception
     * @deprecated fetchRecords
     */
    public function fetchData(): array {

        return $this->fetchRows();
    }


    /**
     * Получение данных из базы
     * @return Record[]
     * @throws \Zend_Db_Select_Exception
     */
    public function fetchRecords(): array {

        return $this->fetch(Record::class);
    }


    /**
     * Получение данных из базы
     * @return Row[]
     * @throws \Zend_Db_Select_Exception
     */
    public function fetchRows(): array {

        return $this->fetch(Row::class);
    }


    /**
     * Получение данных из базы
     * @param string $class_row
     * @return array
     * @throws Exception
     * @throws \DateMalformedStringException
     * @throws \Zend_Db_Adapter_Exception
     * @throws \Zend_Db_Select_Exception
     */
    private function fetch(string $class_row = Row::class): array {

        try {
            if ( ! $this->is_fetched) {
                $this->preFetchRows();

                $this->is_fetched = true;

                if ($this->data instanceof \Zend_Db_Select) {
                    $this->data_rows = $this->fetchDataSelect($this->data, $class_row);

                } elseif ($this->data instanceof \Zend_Db_Table_Abstract) {
                    $this->data_rows = $this->fetchDataTable($this->data, $class_row);

                } elseif ($this->query) {
                    $this->data_rows = $this->fetchDataQuery($this->query, $class_row);
                }
            }

        } catch (\Exception $e) {
            $this->session->table->search           = [];
            $this->session->table->filter           = [];
            $this->session->table->columns          = null;
            $this->session->table->order            = null;
            $this->session->table->order_type       = null;
            $this->session->table->records_per_page = null;

            $this->saveTableState();
            throw $e;
        }

        return $this->data_rows;
    }


    /**
     * @param \Zend_Db_Table_Abstract $table
     * @param string                  $record_class
     * @return array
     * @throws \DateMalformedStringException
     * @throws \Zend_Db_Select_Exception
     */
    private function fetchDataTable(\Zend_Db_Table_Abstract $table, string $record_class = Row::class): array {

        $select = $table->select();

        return $this->fetchDataSelect($select, $record_class);
    }


    /**
     * @param \Zend_Db_Select $select
     * @param string          $record_class
     * @return array
     * @throws \DateMalformedStringException
     * @throws \Zend_Db_Select_Exception
     */
    private function fetchDataSelect(\Zend_Db_Select $select, string $record_class = Row::class): array {

        if ( ! empty($this->session->table->search)) {
            foreach ($this->session->table->search as $key => $value) {

                if (isset($this->search_controls[$key]) &&
                    $this->search_controls[$key] instanceof Search &&
                    ! empty($value)
                ) {
                    $search_field = $this->search_controls[$key];

                    $field = $search_field->getField();
                    $type  = $search_field->getType();

                    if (strpos($field, '/*ADD_SEARCH*/') !== false) {
                        $field = str_replace("/*ADD_SEARCH*/", "ADD_SEARCH", $field);
                    }

                    $value = $this->formatSearchType($type, $value);

                    switch ($type) {
                        case self::SEARCH_TEXT:
                        case self::SEARCH_AUTOCOMPLETE:
                        case self::SEARCH_AUTOCOMPLETE_TABLE:
                            $value = trim($value);

                            if (strpos($field, '%ADD_SEARCH%') !== false) {
                                $quoted_value = $this->db->quote("%{$value}%");
                                $select->where(str_replace("%ADD_SEARCH%", $quoted_value, $field));

                            } elseif (strpos($field, 'ADD_SEARCH') !== false) {
                                $quoted_value = $this->db->quote($value);
                                $select->where(str_replace("ADD_SEARCH", $quoted_value, $field));

                            } else {
                                $select->where("{$field} LIKE ?", "%{$value}%");
                            }
                            break;

                        case self::SEARCH_DATE_ONE:
                        case self::SEARCH_RADIO:
                        case self::SEARCH_TEXT_STRICT:
                        case self::SEARCH_SELECT:
                        case self::SEARCH_SELECT2:
                            $type = null;

                            if ($search_field->getValueType() == $search_field::TYPE_INT) {
                                $type = \Zend_Db::INT_TYPE;
                            }

                            if (strpos($field, 'ADD_SEARCH') !== false) {
                                $quoted_value = $this->db->quote($value, $type);
                                $select->where(str_replace("ADD_SEARCH", $quoted_value, $field));

                            } else {
                                $select->where("{$field} = ?", $value, $type);
                            }
                            break;

                        case self::SEARCH_DATE:
                        case self::SEARCH_DATETIME:
                        case self::SEARCH_NUMBER:
                            if (is_array($value)) {
                                if (strpos($field, 'ADD_SEARCH') !== false) {
                                    if ( ! empty($value[0]) || ! empty($value[1])) {
                                        $quoted_value1 = $this->db->quote($value[0]);
                                        $quoted_value2 = $this->db->quote($value[1]);

                                        $where = str_replace("ADD_SEARCH1", $quoted_value1, $field);
                                        $where = str_replace("ADD_SEARCH2", $quoted_value2, $where);

                                        $select->where($where);
                                    }

                                } else {
                                    if ($value[0] && $value[1]) {
                                        $where  = $this->db->quoteInto("{$field} BETWEEN ?", $value[0]);
                                        $where .= $this->db->quoteInto(" AND ? ", $value[1]);
                                        $select->where($where);

                                    } elseif ($value[0]) {
                                        $select->where("{$field} >= ?", $value[0]);

                                    } elseif ($value[1]) {
                                        $select->where("{$field} <= ?", $value[1]);
                                    }
                                }
                            }
                            break;

                        case self::SEARCH_CHECKBOX:
                        case self::SEARCH_MULTISELECT:
                        case self::SEARCH_MULTISELECT2:
                            if (strpos($field, 'ADD_SEARCH') !== false) {
                                $quoted_value = $this->db->quote($value);
                                $select->where(str_replace("ADD_SEARCH", $quoted_value, $field));

                            } else {
                                $select->where("{$field} IN(?)", $value);
                            }
                            break;
                    }
                }
            }
        }

        if ( ! empty($this->session->table->filter)) {
            foreach ($this->session->table->filter as $key => $value) {

                if (isset($this->filter_controls[$key]) &&
                    $this->filter_controls[$key] instanceof Filter &&
                    ! empty($value)
                ) {
                    $field = $this->filter_controls[$key]->getField();
                    $type  = $this->filter_controls[$key]->getType();

                    if (strpos($field, '/*ADD_SEARCH*/') !== false) {
                        $field = str_replace("/*ADD_SEARCH*/", "ADD_SEARCH", $field);
                    }

                    $value = $this->formatSearchType($type, $value);

                    switch ($type) {

                        case self::FILTER_MATCH:
                            $filter_value = trim($value);
                            if ($filter_value != '') {
                                $quoted_value = str_replace(['+', '-', '<', '>', '(', ')', '~', '*', '"'], '', $filter_value);
                                $quoted_value = explode(' ', $quoted_value);
                                $quoted_value = array_filter($quoted_value);
                                $quoted_value = array_reduce($quoted_value, fn($carry, $item) => $carry .' +' . $item . '*' , '');
                                $select->where("MATCH({$field}) AGAINST ('{$quoted_value}' IN BOOLEAN MODE)");
                            }
                            break;

                        case self::FILTER_TEXT:
                            $value = trim($value);

                            if (strpos($field, '%ADD_SEARCH%') !== false) {
                                $quoted_value = $this->db->quote("%{$value}%");
                                $select->where(str_replace("%ADD_SEARCH%", $quoted_value, $field));

                            } elseif (strpos($field, 'ADD_SEARCH') !== false) {
                                $quoted_value = $this->db->quote($value);
                                $select->where(str_replace("ADD_SEARCH", $quoted_value, $field));

                            } else {
                                $select->where("{$field} LIKE ?", "%{$value}%");
                            }
                            break;
                        case self::FILTER_DATE_ONE:
                        case self::FILTER_TEXT_STRICT:
                        case self::FILTER_RADIO:
                        case self::FILTER_SELECT:
                            if (strpos($field, 'ADD_SEARCH') !== false) {
                                $quoted_value = $this->db->quote($value);
                                $select->where(str_replace("ADD_SEARCH", $quoted_value, $field));

                            } else {
                                $select->where("{$field} = ?", $value);
                            }
                            break;

                        case self::FILTER_DATE_MONTH:
                            if (preg_match('~^[\d]{4}\-[\d]{1,2}$~', $value)) {
                                $date_start = new \DateTime("{$value}-01");
                                $date_end   = new \DateTime($date_start->format('Y-m-t'));

                                if (strpos($field, 'ADD_SEARCH') !== false) {
                                    $quoted_value1 = $this->db->quote($date_start->format('Y-m-d 00:00:00'));
                                    $quoted_value2 = $this->db->quote($date_end->format('Y-m-d 23:59:59'));

                                    $where = str_replace("ADD_SEARCH1", $quoted_value1, $field);
                                    $where = str_replace("ADD_SEARCH2", $quoted_value2, $where);

                                    $select->where($where);

                                } else {
                                    $where  = $this->db->quoteInto("{$field} BETWEEN ?", $date_start->format('Y-m-d 00:00:00'));
                                    $where .= $this->db->quoteInto(" AND ? ", $date_end->format('Y-m-d 23:59:59'));
                                    $select->where($where);
                                }
                            }
                            break;

                        case self::FILTER_DATE:
                        case self::FILTER_DATETIME:
                        case self::FILTER_NUMBER:
                            if (is_array($value)) {
                                if (strpos($field, 'ADD_SEARCH') !== false) {
                                    if ( ! empty($value[0]) || ! empty($value[1])) {
                                        $quoted_value1 = $this->db->quote($value[0]);
                                        $quoted_value2 = $this->db->quote($value[1]);

                                        $where = str_replace("ADD_SEARCH1", $quoted_value1, $field);
                                        $where = str_replace("ADD_SEARCH2", $quoted_value2, $where);

                                        $select->where($where);
                                    }

                                } else {
                                    if ($value[0] && $value[1]) {
                                        $where  = $this->db->quoteInto("{$field} BETWEEN ?", $value[0]);
                                        $where .= $this->db->quoteInto(" AND ? ", $value[1]);
                                        $select->where($where);

                                    } elseif ($value[0]) {
                                        $select->where("{$field} >= ?", $value[0]);

                                    } elseif ($value[1]) {
                                        $select->where("{$field} <= ?", $value[1]);
                                    }
                                }
                            }
                            break;

                        case self::FILTER_CHECKBOX:
                            if (strpos($field, 'ADD_SEARCH') !== false) {
                                $quoted_value = $this->db->quote($value);
                                $select->where(str_replace("ADD_SEARCH", $quoted_value, $field));

                            } else {
                                $select->where("{$field} IN(?)", $value);
                            }
                            break;
                    }
                }
            }
        }


        //проверка наличия полей для последовательности и автора
        if ($this->table) {
            $table_columns = $this->db->describeTable(trim($this->table, '`'));

            if (isset($table_columns['seq'])) {
                $this->records_seq = true;
            }

            if (isset($table_columns['author']) &&
                $this->checkAcl($this->resource, 'list_owner') &&
                ! $this->checkAcl($this->resource, 'list_all')
            ) {
                $auth   = \Core2\Registry::get('auth');
                $alias  = "{$this->table}.";
                $tables = $select->getPart($select::FROM);

                if ( ! empty($tables)) {
                    foreach ($tables as $table_alias => $table) {
                        if ($table['tableName'] == $this->table) {
                            $alias = "`{$table_alias}`.";
                            break;
                        }
                    }
                }

                $select->where("{$alias}author = ?", $auth->NAME);
            }
        }

        $records_per_page = $this->is_round_calc
            ? $this->records_per_page + 1
            : $this->records_per_page;

        $offset = ($this->current_page - 1) * $this->records_per_page;
        $select->limit((int)$records_per_page, (int)$offset);

        if (is_string($this->order) && $this->order !== '') {
            $order_type = $this->session->table->order_type ?? 'ASC';
            $select->reset('order');
            $select->order("{$this->order} {$order_type}");

        } elseif (isset($this->session->table->order) &&
            $this->session->table->order &&
            isset($this->columns[$this->session->table->order - 1])
        ) {
            $column = $this->columns[$this->session->table->order - 1];

            if ($column instanceof Column && $column->isSorting()) {
                $order_type     = $this->session->table->order_type ?? 'ASC';
                $order_field    = $column->getField();
                $select_columns = $this->getColumns($select);

                if ( ! empty($select_columns[$order_field])) {
                    $select->reset('order');
                    $select->order("{$order_field} {$order_type}");
                }
            }
        }

        $this->select = clone $select;
        $select_sql   = (string)$select;

        if ($this->is_round_calc) {
            if (strpos($select_sql, ' SQL_CALC_FOUND_ROWS') !== false) {
                $select_sql = str_replace(' SQL_CALC_FOUND_ROWS', "", $select_sql);
            }

            $explain = $this->db->fetchAll('EXPLAIN ' . $select_sql);

            foreach ($explain as $value) {
                if ($value['rows'] > $this->records_total_round) {
                    $this->records_total_round = $value['rows'];
                }
            }

            $this->query_result = $select_sql;

            $data_result = $this->db->fetchAll($select_sql);

            if (count($data_result) > $this->records_per_page) {
                $this->records_total      = $offset + $this->records_per_page;
                $this->records_total_more = true;
                unset($data_result[array_key_last($data_result)]);

            } else {
                if (count($data_result) === 0) {
                    $this->records_total      = $this->records_total_round;
                    $this->records_total_more = true;

                } else {
                    $this->records_total = $offset + count($data_result);
                }
            }

        } else {
            if (strpos($select_sql, ' SQL_CALC_FOUND_ROWS') === false) {
                $select_sql = preg_replace('~^(\s*SELECT\s+)~', "$1SQL_CALC_FOUND_ROWS ", $select_sql);
            }

            $this->query_result = $select_sql;

            $data_result         = $this->db->fetchAll($select_sql);
            $this->records_total = (int)$this->db->fetchOne('SELECT FOUND_ROWS()');
        }


        $data_rows = [];
        if ( ! empty($data_result)) {
            foreach ($data_result as $row) {
                $data_rows[] = new $record_class($row);
            }
        }

        return $data_rows ?: [];
    }


    /**
     * Получение данных по запросу sql
     * @param        $query
     * @param string $record_class
     * @return array
     * @throws \DateMalformedStringException
     */
    private function fetchDataQuery($query, string $record_class = Row::class): array {

        $select = new Table\Db\Select($query);

        $db = $this->_db ?: $this->db;

        if ( ! empty($this->session->table) && ! empty($this->session->table->search)) {
            foreach ($this->session->table->search as $key => $search_value) {
                $search_column = $this->search_controls[$key] ?? null;

                if ($search_column instanceof Search) {
                    $search_field = $search_column->getField();

                    if (strpos($search_field, '/*ADD_SEARCH*/') !== false) {
                        $search_field = str_replace("/*ADD_SEARCH*/", "ADD_SEARCH", $search_field);
                    }

                    $search_value = $this->formatSearchType($search_column->getType(), $search_value);

                    switch ($search_column->getType()) {
                        case self::SEARCH_DATE:
                        case self::SEARCH_DATETIME:
                        case self::SEARCH_NUMBER:
                            if (strpos($search_field, 'ADD_SEARCH') !== false) {
                                if ( ! empty($value[0]) || ! empty($value[1])) {
                                    $quoted_value1 = $db->quote($search_value[0]);
                                    $quoted_value2 = $db->quote($search_value[1]);

                                    $where = str_replace("ADD_SEARCH1", $quoted_value1, $search_field);
                                    $where = str_replace("ADD_SEARCH2", $quoted_value2, $where);

                                    $select->addWhere($where);
                                }

                            } else {
                                if ( ! empty($search_value[0]) && empty($search_value[1])) {
                                    $quoted_value = $db->quote($search_value[0]);
                                    $select->addWhere("{$search_field} >= {$quoted_value}");

                                } elseif (empty($search_value[0]) && ! empty($search_value[1])) {
                                    $quoted_value = $db->quote($search_value[1]);
                                    $select->addWhere("{$search_field} <= {$quoted_value}");

                                } elseif ( ! empty($search_value[0]) && ! empty($search_value[1])) {
                                    $quoted_value1 = $db->quote($search_value[0]);
                                    $quoted_value2 = $db->quote($search_value[1]);
                                    $select->addWhere("{$search_field} BETWEEN {$quoted_value1} AND {$quoted_value2}");
                                }
                            }
                            break;

                        case self::SEARCH_DATE_ONE:
                        case self::SEARCH_TEXT_STRICT:
                        case self::SEARCH_RADIO:
                        case self::SEARCH_SELECT:
                        case self::SEARCH_SELECT2:
                            $type = null;

                            if ($search_column->getValueType() === $search_column::TYPE_INT) {
                                $type = \Zend_Db::INT_TYPE;
                            }

                            if ($search_value != '') {
                                $quoted_value = $db->quote($search_value, $type);

                                if (strpos($search_field, 'ADD_SEARCH') !== false) {
                                    $select->addWhere(str_replace("ADD_SEARCH", $quoted_value, $search_field));
                                } else {
                                    $select->addWhere("{$search_field} = {$quoted_value}");
                                }
                            }
                            break;

                        case self::SEARCH_CHECKBOX:
                        case self::SEARCH_MULTISELECT:
                        case self::SEARCH_MULTISELECT2:
                            if ( ! empty($search_value)) {
                                $quoted_value = $db->quote($search_value);

                                if (strpos($search_field, 'ADD_SEARCH') !== false) {
                                    $select->addWhere(str_replace("ADD_SEARCH", $quoted_value, $search_field));
                                } else {
                                    $select->addWhere("{$search_field} IN ({$quoted_value})");
                                }
                            }
                            break;

                        case self::SEARCH_TEXT:
                        case self::SEARCH_AUTOCOMPLETE:
                        case self::SEARCH_AUTOCOMPLETE_TABLE:
                            if (is_string($search_value)) {
                                $search_value = trim($search_value);

                                if ($search_value != '') {
                                    if (strpos($search_field, '%ADD_SEARCH%') !== false) {
                                        $quoted_value = $db->quote("%{$search_value}%");
                                        $select->addWhere(str_replace("%ADD_SEARCH%", $quoted_value, $search_field));

                                    } elseif (strpos($search_field, 'ADD_SEARCH') !== false) {
                                        $quoted_value = $db->quote($search_value);
                                        $select->addWhere(str_replace("ADD_SEARCH", $quoted_value, $search_field));

                                    } else {
                                        $quoted_value = $db->quote("%{$search_value}%");
                                        $select->addWhere("{$search_field} LIKE {$quoted_value}");
                                    }
                                }
                            }
                            break;
                    }
                }
            }
        }


        if ( ! empty($this->session->table) && ! empty($this->session->table->filter)) {
            foreach ($this->session->table->filter as $key => $filter_value) {
                $filter_column = $this->filter_controls[$key] ?? null;

                if ($filter_column instanceof Filter) {
                    $filter_field = $filter_column->getField();

                    if (strpos($filter_field, '/*ADD_SEARCH*/') !== false) {
                        $filter_field = str_replace("/*ADD_SEARCH*/", "ADD_SEARCH", $filter_field);
                    }

                    $search_value = $this->formatSearchType($filter_column->getType(), $filter_value);

                    switch ($filter_column->getType()) {
                        case self::FILTER_DATE:
                        case self::FILTER_DATETIME:
                        case self::FILTER_DATE_PERIOD:
                        case self::FILTER_NUMBER:
                            if (strpos($filter_field, 'ADD_SEARCH') !== false) {
                                if ( ! empty($filter_value[0]) || ! empty($filter_value[1])) {
                                    $quoted_value1 = $db->quote($filter_value[0]);
                                    $quoted_value2 = $db->quote($filter_value[1]);

                                    $where = str_replace("ADD_SEARCH1", $quoted_value1, $filter_field);
                                    $where = str_replace("ADD_SEARCH2", $quoted_value2, $where);

                                    $select->addWhere($where);
                                }

                            } else {
                                if ( ! empty($filter_value[0]) && empty($filter_value[1])) {
                                    $quoted_value = $db->quote($filter_value[0]);
                                    $select->addWhere("{$filter_field} >= {$quoted_value}");

                                } elseif (empty($filter_value[0]) && ! empty($filter_value[1])) {
                                    $quoted_value = $db->quote($filter_value[1]);
                                    $select->addWhere("{$filter_field} <= {$quoted_value}");

                                } elseif ( ! empty($filter_value[0]) && ! empty($filter_value[1])) {
                                    $quoted_value1 = $db->quote($filter_value[0]);
                                    $quoted_value2 = $db->quote($filter_value[1]);
                                    $select->addWhere("{$filter_field} BETWEEN {$quoted_value1} AND {$quoted_value2}");
                                }
                            }
                            break;


                        case self::FILTER_DATE_MONTH:
                            if (preg_match('~^[\d]{4}\-[\d]{1,2}$~', $filter_value)) {
                                $date_start = new \DateTime("{$filter_value}-01");
                                $date_end   = new \DateTime($date_start->format('Y-m-t'));

                                if (strpos($filter_field, 'ADD_SEARCH') !== false) {
                                    $quoted_value1 = $db->quote($date_start->format('Y-m-d 00:00:00'));
                                    $quoted_value2 = $db->quote($date_end->format('Y-m-d 23:59:59'));

                                    $where = str_replace("ADD_SEARCH1", $quoted_value1, $filter_field);
                                    $where = str_replace("ADD_SEARCH2", $quoted_value2, $where);

                                    $select->addWhere($where);

                                } else {
                                    $where  = $db->quoteInto("{$filter_field} BETWEEN ?", $date_start->format('Y-m-d 00:00:00'));
                                    $where .= $db->quoteInto(" AND ? ", $date_end->format('Y-m-d 23:59:59'));
                                    $select->addWhere($where);
                                }
                            }
                            break;
                        case self::FILTER_DATE_ONE:
                        case self::FILTER_TEXT_STRICT:
                        case self::FILTER_RADIO:
                        case self::FILTER_SELECT:
                            if ($filter_value != '') {
                                $quoted_value = $db->quote($filter_value);

                                if (strpos($filter_field, 'ADD_SEARCH') !== false) {
                                    $select->addWhere(str_replace("ADD_SEARCH", $quoted_value, $filter_field));
                                } else {
                                    $select->addWhere("{$filter_field} = {$quoted_value}");
                                }
                            }
                            break;

                        case self::FILTER_CHECKBOX:
                            if ( ! empty($filter_value)) {
                                $quoted_value = $db->quote($filter_value);

                                if (strpos($filter_field, 'ADD_SEARCH') !== false) {
                                    $select->addWhere(str_replace("ADD_SEARCH", $quoted_value, $filter_field));
                                } else {
                                    $select->addWhere("{$filter_field} IN ({$quoted_value})");
                                }
                            }
                            break;

                        case self::FILTER_TEXT:
                            $filter_value = trim($filter_value);

                            if ($filter_value != '') {
                                if (strpos($filter_field, '%ADD_SEARCH%') !== false) {
                                    $quoted_value = $db->quote("%{$filter_value}%");
                                    $select->addWhere(str_replace("%ADD_SEARCH%", $quoted_value, $filter_field));

                                } elseif (strpos($filter_field, 'ADD_SEARCH') !== false) {
                                    $quoted_value = $db->quote($filter_value);
                                    $select->addWhere(str_replace("ADD_SEARCH", $quoted_value, $filter_field));

                                } else {
                                    $quoted_value = $db->quote('%' . $filter_value . '%');
                                    $select->addWhere("{$filter_field} LIKE {$quoted_value}");
                                }
                            }
                            break;

                        case self::FILTER_MATCH:
                            if ($filter_value != '') {
                                $quoted_value = str_replace(['+', '-', '<', '>', '(', ')', '~', '*', '"'], '', $filter_value);
                                $quoted_value = explode(' ', $quoted_value);
                                $quoted_value = array_filter($quoted_value);
                                $quoted_value = array_reduce($quoted_value, fn($carry, $item) => $carry .' +' . $item . '*' , '');
                                $select->addWhere("MATCH({$filter_field}) AGAINST ('{$quoted_value}' IN BOOLEAN MODE)");
                            }
                            break;
                    }
                }
            }
        }


        if (empty($this->table)) {
            $this->table                = $select->getTable();
            $this->session->table->name = $this->table;
        }

        //проверка наличия полей для последовательности и автора
        if ($this->table) {
            $table_columns = $this->db->describeTable(trim($this->table, '`'));
            if (!$this->primary_key) $this->setPrimaryKey('id');
            if (isset($table_columns['seq'])) {
                $this->records_seq = true;
            }

            if (isset($table_columns['author']) &&
                $this->checkAcl($this->resource, 'list_owner') &&
                ! $this->checkAcl($this->resource, 'list_all')
            ) {
                $auth         = \Core2\Registry::get('auth');
                $quoted_value = $db->quote($auth->NAME);
                $alias        = $select->getTableAlias();
                $alias        = $alias ? "{$alias}." : "{$this->table}.";

                $select->addWhere("{$alias}author = {$quoted_value}");
            }
        }


        if (is_string($this->order) && $this->order !== '') {
            $order_type = $this->session->table->order_type ?? 'ASC';
            $select->setOrderBy("{$this->order} {$order_type}");

        } elseif (isset($this->session->table->order) &&
            $this->session->table->order &&
            isset($this->columns[$this->session->table->order - 1])
        ) {
            $column = $this->columns[$this->session->table->order - 1];

            if ($column instanceof Column && $column->isSorting()) {
                $order_type     = $this->session->table->order_type ?? 'ASC';
                $order_field    = $column->getField();
                $select_columns = $select->getSelectColumns();

                if ( ! empty($select_columns[$order_field])) {
                    $select->setOrderBy("{$order_field} {$order_type}");
                }
            }
        }


        $records_per_page = $this->is_round_calc
            ? $this->records_per_page + 1
            : $this->records_per_page;

        $offset = ($this->current_page - 1) * $this->records_per_page;

        $this->query_parts = $select->getSqlParts();

        if ($this->is_round_calc) {
            $select_sql = $select->getSql();

            if (strpos($select_sql, ' SQL_CALC_FOUND_ROWS') !== false) {
                $select_sql = str_replace(' SQL_CALC_FOUND_ROWS', "", $select_sql);
            }

            $explain = $db->fetchAll('EXPLAIN ' . $select_sql, $this->query_params);

            foreach ($explain as $value) {
                if ($value['rows'] > $this->records_total_round) {
                    $this->records_total_round = $value['rows'];
                }
            }

            $select->setLimit($records_per_page, $offset);
            $select_sql         = $select->getSql();
            $this->query_result = $select_sql;

            $result = $db->fetchAll($select_sql, $this->query_params);

            if (count($result) > $this->records_per_page) {
                $this->records_total      = $offset + $this->records_per_page;
                $this->records_total_more = true;
                unset($result[array_key_last($result)]);

            } else {
                if (count($result) === 0) {
                    $this->records_total      = $this->records_total_round;
                    $this->records_total_more = true;

                } else {
                    $this->records_total = $offset + count($result);
                }
            }

        } else {
            $select->setLimit($records_per_page, $offset);
            $select_sql = $select->getSql();

            if (strpos($select_sql, ' SQL_CALC_FOUND_ROWS') === false) {
                $select_sql = preg_replace('~^(\s*SELECT\s+)~', "$1SQL_CALC_FOUND_ROWS ", $select_sql);
            }

            $this->query_result = $select_sql;

            if ($this->cachable) {
                $cache_key = $this->table . ":" . md5(json_encode($this->query_params + [$select_sql]));

                if ($this->cache->hasItem($cache_key)) {
                    $cache_data          = $this->cache->getItem($cache_key);
                    $this->records_total = $cache_data['records_total'] ?? 0;
                    $result              = $cache_data['data'] ?? [];
                }

                if ( ! isset($cache_data)) {
                    $result = $db->fetchAll($select_sql, $this->query_params);
                    $this->records_total = $db->fetchOne("SELECT FOUND_ROWS()");

                    $this->cache->setItem($cache_key, [
                        'data'          => $result,
                        'records_total' => $this->records_total,
                    ]);
                }

            } else {
                $result              = $db->fetchAll($select_sql, $this->query_params);
                $this->records_total = $db->fetchOne("SELECT FOUND_ROWS()");
            }
        }


        $data_rows = [];
        if ( ! empty($result)) {
            foreach ($result as $key => $row) {
                $data_rows[$key] = new $record_class($row);
            }
        }

        return $data_rows ?: [];
    }


    /**
     * Список колонок
     * @param \Zend_Db_Select $select
     * @return array
     * @throws \Zend_Db_Select_Exception
     */
    private function getColumns(\Zend_Db_Select $select): array {

        $columns = $select->getPart('columns');
        $result  = [];

        if ( ! empty($columns)) {
            foreach ($columns as $column) {

                $alias = $column[2] ?: $column[1];

                if ($alias instanceof \Zend_Db_Expr) {
                    $alias = $this->db->quoteIdentifier($alias);
                }

                if ($column[1] instanceof \Zend_Db_Expr) {
                    $name = $this->db->quoteIdentifier($column[1]);
                } else {
                    $name = $this->db->quoteIdentifier($column[0]) . '.' . $this->db->quoteIdentifier($column[1]);
                }

                $result[$alias] = $name;
            }
        }

        return $result;
    }


    /**
     * Форматирование поисковых данных
     * @param string $type
     * @param mixed  $search_value
     * @return string
     */
    private function formatSearchType(string $type, mixed $search_value): mixed {

        // Проверка формата
        switch ($type) {
            case self::SEARCH_DATE:
            case self::FILTER_DATE:
                if ( ! empty($search_value[0]) && ! preg_match('~^\d{4}-\d{2}-\d{2}$~', $search_value[0])) {
                    $search_value[0] = '';
                }
                if ( ! empty($search_value[1]) && ! preg_match('~^\d{4}-\d{2}-\d{2}$~', $search_value[1])) {
                    $search_value[1] = '';
                }
                break;

            case self::SEARCH_DATETIME:
            case self::FILTER_DATETIME:
                if ( ! empty($search_value[0]) && ! preg_match('~^\d{4}-\d{2}-\d{2}.\d{2}:\d{2}(|:\d{2})$~', $search_value[0])) {
                    $search_value[0] = '';
                }
                if ( ! empty($search_value[1]) && ! preg_match('~^\d{4}-\d{2}-\d{2}.\d{2}:\d{2}(|:\d{2})$~', $search_value[1])) {
                    $search_value[1] = '';
                }
                break;

            case self::SEARCH_DATE_ONE:
            case self::FILTER_DATE_ONE:
                if ( ! empty($search_value) && ! preg_match('~^\d{4}-\d{2}-\d{2}$~', $search_value)) {
                    $search_value = '';
                }
                break;

            case self::FILTER_DATE_MONTH:
                if ( ! empty($search_value) && ! preg_match('~^\d{4}-\d{2}$~', $search_value)) {
                    $search_value = '';
                }
                break;
        }

        return $search_value;
    }

    /**
     * будут ли кешироваться данные таблицы
     * @param bool $is
     * @return void
     */
    public function setCachable(bool $is = true): void
    {
        $this->cachable = $is;
    }

    /**
     * Удаляет строку с данными
     * иногда это нужно
     * @param int $i
     * @return bool
     */
    public function deleteRow(int $i): bool
    {
        if (isset($this->data_rows[$i])) {
            unset($this->data_rows[$i]);
            return true;
        }
        return false;
    }
}