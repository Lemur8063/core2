<?php
require_once('class.ini.php');
require_once('Templater3.php');

use Laminas\Session\Container as SessionContainer;
use Core2\Tool;

/**
 * Class listTable
 */
class listTable extends initList {
    public $addSum           = array();
    public $table            = '';
    public $table_button     = array();
    public $table_search     = array();
    public $paintCondition   = array();
    public $paintColor       = array();
    public $fontColor        = array();
    public $fontWeight       = array();
    public $sqlSearch        = array();
    public $params           = array();
    public $data             = array();
    public $metadata         = array();
    public $noCheckboxes     = "no";
    public $noFooter         = false;
    public $filterColumn     = false;
    public $main_table_id    = "";
    public $search_table_id  = "";
    public $SQL              = "";
    public $editURL          = "";
    public $addURL           = "";
    public $deleteURL        = "";
    public $deleteKey        = "";
    public $ajax             = 0;
    public $error            = "";
    public $extOrder         = false;
    public $roundRecordCount = false;
    public $fixHead          = false;

    protected $resource     = "";
    protected $table_column = array();

    private $HTML               = "";
    private $customSearch       = array();
    private $customSearchHasVal = false;
    private $columnSchema       = array();
    private $recordCount        = "";
    private $dataAlreadyGot     = false;
    private $extraHeaders       = array();
    private $is_seq             = false;
    private $sessData           = array();
    private $search_sql         = "";
    private $scripts            = array();
    private $service_content    = array();
    private $show_templates     = false;
    private $_db;


    /**
     * listTable constructor.
     * @param $name
     */
    public function __construct($name) {
        parent::__construct();

        $this->resource        = $name;
        $this->main_table_id   = "main_" . $name;
        $this->search_table_id = "search_" . $name;
    }


    /**
     *
     */
    public function showTemplates() {

        $this->show_templates = true;
    }

    public function setDatabase($db)
    {
        $this->_db = $db;
    }


    /**
     * @param $value
     * @param string $img
     * @param string $onclick
     * @param string $style
     * @param string $type
     * @return string
     */
    public function button($value, $img = "", $onclick = "", $style = "", $type = 'button') {
        $id = uniqid();
        $out = '<input type="' . $type . '" class="button" value="' . $value . '" style="' . $style .
                    '" onclick="' . ($onclick ? $onclick : "if(document.getElementById('$id') && document.getElementById('$id').form) document.getElementById('$id').form.onsubmit()") . '"/>';
        return $out;
    }


    /**
     * @param $value
     */
    public function setRecordCount($value) {
        $this->recordCount = $value;
    }


    /**
     * @param $name
     * @param string $width
     * @param string $type
     * @param string $in
     * @param string $processing
     * @param bool $sort
     */
    public function addColumn($name, $width = "0%", $type = "TEXT", $in = "", $processing = "", $sort = true) {
        if (!array_key_exists($this->main_table_id, $this->table_column)) {
            $this->table_column[$this->main_table_id] = array();
        }
        $this->table_column[$this->main_table_id][] = array(
            'name'       => $name,
            'type'       => strtolower($type),
            'in'         => $in,
            'width'      => $width,
            'processing' => $processing,
            'sort'       => $sort
        );
    }

    /**
     * Добавить новую кнопку
     * @param $name
     * @param $script
     * @param string $msg
     * @param int $nocheck
     * @param string $callback
     */
    public function addButton($name, $script, $msg = "", $nocheck = 0, $callback = '') {
        $this->table_button[$this->main_table_id][] = array(
            'name'    => addslashes($name),
            'url'     => $script,
            'confirm' => addslashes($msg),
            'nocheck' => $nocheck,
            'callback' => $callback
        );
    }


    /**
     * Добавляет кастомную кнопку
     * @param string $html - кнопка
     */
    public function addButtonCustom($html = '') {
        $this->table_button[$this->main_table_id][] = array('html' => $html);
    }


    /**
     * Add search field
     * @param $name - caption
     * @param $field - destination field name
     * @param $type - type of search field
     * @param string $in - inner attributes
     * @param string $out - outher html
     * @return void
     */
    public function addSearch($name, $field, $type, $in = "", $out = "") {
        $this->table_search[$this->main_table_id][] = array(
            'name'         => htmlspecialchars($name),
            'type'         => strtolower($type),
            'in'         => $in,
            'field'     => $field,
            'out'         => $out
        );
    }


    /**
     * @param $text
     */
    public function addServiceText($text) {

        $this->service_content[$this->main_table_id][] = $text;
    }


    /**
     * Получение сформированного SQL для поиска данных
     * @return string
     */
    public function getSearchSql(): string {

        return $this->search_sql;
    }


    /**
     * Получение параметров таблицы
     * @return array
     */
    public function getParams(): array {

        $search = [];

        if ( ! empty($this->table_search[$this->main_table_id])) {
            foreach ($this->table_search[$this->main_table_id] as $key => $table_search) {

                $value = ! empty($this->sessData['search']) ? ($this->sessData['search'][$key] ?? null) : null;

                if ($value && in_array($table_search['type'], ['date', 'number'])) {
                    $value = empty($value[0]) && empty($value[1]) ? null : $value;
                }

                if (isset($value) && $value !== '') {
                    $search[] = [
                        'type'  => $table_search['type'],
                        'field' => $table_search['field'],
                        'value' => $value,
                    ];
                }
            }
        }

        $page       = $this->sessData['_page_' . $this->resource] ?? 0;
        $count_rows = $this->sessData['count_' . $this->resource] ?? 0;
        $param    = [];

        $param['search']     = $search;
        $param['column']     = $this->sessData['column'] ?? [];
        $param['page']       = $page;
        $param['count_rows'] = $count_rows;
        $param['offset']     = $page == 1 ? 0 : ($page - 1) * $count_rows;
        $param['order']      = $this->sessData['order'] ?? "";
        $param['order_type'] = $this->sessData['orderType'] ?? "";

        return $param;
    }


    /**
     * Get data array
     * @throws Exception
     * @return array
     */
    public function getData() {

        // CHECK FOR SEARCH
        $ss = new SessionContainer('Search');
        $ssi = $this->main_table_id;
        if (empty($ss->$ssi)) {
            $ss->$ssi = array();
        }
        $tmp = $ss->$ssi;
        $countPOST = 'count_' . $this->resource; //pagination record count
        $pagePOST = '_page_' . $this->resource; //pagination page number

        //CHECK RECORD COUNTER
        if (empty($_POST[$countPOST])) {
            if (empty($tmp[$countPOST])) {
                $tmp[$countPOST] = $this->recordsPerPage; //к-во строк по умолчанию
            }
        } else {
            $tmp[$countPOST] = (int)$_POST[$countPOST];
        }

        $db = $this->_db ? $this->_db : $this->db;

        //SEARCH
        if ( ! empty($_POST['search'][$this->main_table_id])) {
            $tmp['search'] = $_POST['search'][$this->main_table_id];
        }
        if ( ! empty($_POST['clear_form' . $this->resource])) {
            $tmp['search'] = [];
        }

        //COLUMNS
        if ( ! empty($_POST['column_' . $this->resource])) {
            $tmp['column'] = $_POST['column_' . $this->resource];
        }

        //TEMPLATES
        if ( ! empty($_POST['template_create_' . $this->resource])) {
            $profile_controller = $this->getProfileController();
            if ($profile_controller instanceof ModProfileController) {
                $template_title = $_POST['template_create_' . $this->resource];
                $hash           = $this->getUniqueHash();

                $list_template = $profile_controller->getUserData("list_template_{$hash}");
                $list_template = $list_template ?: [];
                $list_template[hash('crc32b', $template_title)] = [
                    'title'  => $template_title,
                    'search' => $tmp['search'] ?? [],
                    'column' => $tmp['column'] ?? [],
                ];

                $profile_controller->putUserData("list_template_{$hash}", $list_template);
            }
        }

        if ( ! empty($_POST['template_remove_' . $this->resource])) {
            $profile_controller = $this->getProfileController();
            if ($profile_controller instanceof ModProfileController) {
                $template_id = $_POST['template_remove_' . $this->resource];
                $hash        = $this->getUniqueHash();
                $list_template = $profile_controller->getUserData("list_template_{$hash}");
                $list_template = $list_template ?: [];


                if (isset($list_template[$template_id])) {
                    unset($list_template[$template_id]);
                    $profile_controller->putUserData("list_template_{$hash}", $list_template);
                }
            }
        }

        if ( ! empty($_POST['template_select_' . $this->resource])) {
            $profile_controller = $this->getProfileController();
            if ($profile_controller instanceof ModProfileController) {
                $template_id = $_POST['template_select_' . $this->resource];
                $hash        = $this->getUniqueHash();

                $list_template = $profile_controller->getUserData("list_template_{$hash}");
                $list_template = $list_template ?: [];

                if (isset($list_template[$template_id])) {
                    $tmp['search'] = $list_template[$template_id]['search'];
                    $tmp['column'] = $list_template[$template_id]['column'];
                }
            }
        }

        //ORDERING
        if (!empty($_POST['orderField_' . $this->main_table_id])) {
            if (empty($tmp['order'])) {
                $tmp['order'] = $_POST['orderField_' . $this->main_table_id];
                $tmp['orderType'] = "asc";
            } else {
                if ($_POST['orderField_' . $this->main_table_id] == $tmp['order']) {
                    if (!empty($_POST['orderType_' . $this->main_table_id])) {
                        $tmp['orderType'] = $_POST['orderType_' . $this->main_table_id];
                    } else {
                        if ($tmp['orderType'] == "asc") {
                            $tmp['orderType'] = "desc";
                        } elseif ($tmp['orderType'] == "desc") {
                            $tmp['orderType'] = "";
                            $tmp['order']     = "";
                        } elseif ($tmp['orderType'] == "") {
                            $tmp['orderType'] = "asc";
                        }
                    }
                } else {
                    $tmp['order'] = $_POST['orderField_' . $this->main_table_id];
                    $tmp['orderType'] = "asc";
                }
            }
        }

        $this->sessData = $tmp;
        if (empty($_GET[$pagePOST]) || !empty($_POST['search'][$this->main_table_id])) {
            $this->sessData[$pagePOST] = 1;
        } else {
            $this->sessData[$pagePOST] = (int)$_GET[$pagePOST];
            if (!$this->sessData[$pagePOST]) $this->sessData[$pagePOST] = 1;
        }
        $ss->$ssi  = $tmp;
        $search    = "";
        $questions = array();

        //проверка наличия полей для последовательности и автора
        if ($this->table) {
            $is = $this->db->describeTable(trim($this->table, '`'));

            if (isset($is['seq'])) $this->is_seq = true;

            if (isset($is['author']) &&
                $this->checkAcl($this->resource, 'list_owner') &&
                ! $this->checkAcl($this->resource, 'list_all')
            ) {
                if ( ! isset($is['author'])) {
                    throw new \Exception("Данные не содержат признака автора!");
                } else {
                    $auth        = \Core2\Registry::get('auth');
                    $questions[] = $auth->NAME;
                    // FIXME Может быть случай, когда в запросе есть две таблицы с полем author. Нужно подставлять alias
                    $search      = " AND author = ?";
                }
            }
        }
        
        if (!empty($this->sessData) && count($this->table_search)) {
            $map = $this->table_search[$this->main_table_id];
            reset($map);
            $next = current($map);
            if (!empty($this->sessData['search'])) {
                foreach ($this->sessData['search'] as $search_value) {
                    if (is_array($next) && strpos($next['type'], '_custom') !== false) {
                        $next['type'] = str_replace('_custom', '', $next['type']);
                        $this->customSearch[$next['field']] = $search_value;
                        if (trim($search_value)) {
                            $this->customSearchHasVal = true;
                        }
                    }
                    else {
                        if (is_array($next) && $next['type']) {
                            if ($next['type'] == 'date') {
                                try {
                                    if ($search_value[0]) {
                                        $dt = new DateTime($search_value[0]);
                                        $search_value[0] = $dt->format("Y-m-d");
                                    }
                                    if ($search_value[1]) {
                                        $dt = new DateTime($search_value[1]);
                                        $search_value[1] = $dt->format("Y-m-d");
                                    }
                                } catch (Exception $e) {
                                    $this->error = $e->getMessage();
                                }
                                if (strpos($next['field'], "ADD_SEARCH1") === false && strpos($next['field'], "ADD_SEARCH2") === false) {
                                    if ($search_value[0] && !$search_value[1]) {
                                        $search .= " AND DATE_FORMAT({$next['field']}, '%Y-%m-%d') >= ?";
                                        $questions[] = $search_value[0];
                                    }
                                    if (!$search_value[0] && $search_value[1]) {
                                        $search .= " AND DATE_FORMAT({$next['field']}, '%Y-%m-%d') <= ?";
                                        $questions[] = $search_value[1];
                                    }
                                    if ($search_value[0] && $search_value[1]) {
                                        $search .= " AND DATE_FORMAT({$next['field']}, '%Y-%m-%d') BETWEEN ? AND ?";
                                        $questions[] = $search_value[0];
                                        $questions[] = $search_value[1];
                                    }
                                } else {
                                    $replace = str_replace("ADD_SEARCH1", $search_value[0], $next['field']);
                                    $replace = str_replace("ADD_SEARCH2", $search_value[1], $replace);
                                    $search .= " AND " . $replace;
                                }

                            } elseif ($next['type'] == 'number') {
                                try {
                                    if ( ! empty($search_value[0]) && ! is_numeric($search_value[0])) {
                                        throw new Exception($this->_('Некорректно указан параметр числового поиска'));
                                    }
                                    if ( ! empty($search_value[1]) && ! is_numeric($search_value[1])) {
                                        throw new Exception($this->_('Некорректно указан параметр числового поиска'));
                                    }
                                } catch (Exception $e) {
                                    $this->error = $e->getMessage();
                                }
                                if (strpos($next['field'], "ADD_SEARCH1") === false && strpos($next['field'], "ADD_SEARCH2") === false) {
                                    if ($search_value[0] && ! $search_value[1]) {
                                        $search .= " AND {$next['field']} >= ?";
                                        $questions[] = $search_value[0];
                                    }
                                    if ( ! $search_value[0] && $search_value[1]) {
                                        $search .= " AND {$next['field']} <= ?";
                                        $questions[] = $search_value[1];
                                    }
                                    if ($search_value[0] && $search_value[1]) {
                                        $search .= " AND {$next['field']} BETWEEN ? AND ?";
                                        $questions[] = $search_value[0];
                                        $questions[] = $search_value[1];
                                    }
                                } else {
                                    $replace = str_replace("ADD_SEARCH1", $search_value[0], $next['field']);
                                    $replace = str_replace("ADD_SEARCH2", $search_value[1], $replace);
                                    $search .= " AND " . $replace;
                                }

                            }
                            elseif ($search_value) {
                                if ($next['type'] == 'list' || $next['type'] == 'select') {
                                    if (strpos($next['field'], "ADD_SEARCH") === false) {
                                        $search .= " AND " . $next['field'] . "=?";
                                        $questions[] = $search_value;
                                    } else {
                                        $search .= " AND " . str_replace("ADD_SEARCH", $search_value, $next['field']);
                                    }
                                } elseif (in_array($next['type'], ['checkbox', 'checkbox2', 'multilist', 'multilist2'])) {
                                    if (is_array($search_value)) {
                                        foreach ($search_value as $k => $val) {
                                            if (!$val) unset($search_value[$k]);
                                        }
                                        if ($search_value) {
                                            if (strpos($next['field'], "ADD_SEARCH") === false) {

                                                $search .= " AND ({$next['field']}='" . implode("' OR {$next['field']}='", $search_value) . "')";
                                            } else {
                                                if (is_array($search_value) && ! empty($search_value)) {
                                                    $search_checkbox = array();
                                                    foreach ($search_value as $search_val) {
                                                        $search_checkbox[] = str_replace("ADD_SEARCH", $db->quote($search_val), $next['field']);
                                                    }

                                                    $search .= " AND (" . implode(" OR ", $search_checkbox) . ")";

                                                } else {
                                                    $search .= " AND " . str_replace("ADD_SEARCH", $db->quote($search_value), $next['field']);
                                                }
                                            }
                                        }
                                    }

                                } elseif ($next['type'] == 'text_strict') {
                                    $search_value = trim($search_value);
                                    $search_value = preg_replace('~[\s]{2,}~', ' ', $search_value);

                                    if (strpos($next['field'], "ADD_SEARCH") === false) {
                                        $search .= " AND " . $next['field']." LIKE ?";
                                        $questions[] = $search_value;
                                    } else {
                                        $search .= " AND " . str_replace("ADD_SEARCH", $search_value, $next['field']);
                                    }


                                } else {
                                    $search_value = trim($search_value);
                                    $search_value = preg_replace('~[\s]{2,}~', ' ', $search_value);

                                    if (strpos($next['field'], "ADD_SEARCH") === false) {
                                        $search .= " AND " . $next['field']." LIKE ?";
                                        $questions[] = "%" . $search_value . "%";
                                    } else {
                                        $search .= " AND " . str_replace("ADD_SEARCH", $search_value, $next['field']);
                                    }
                                }
                            }
                        }
                    }
                    $next = next($map);
                }
            }
        }



        if ($this->SQL) {
            $idm = substr($this->SQL, strripos($this->SQL, "SELECT ") + 6);
            $idm = trim($idm);
            $idm = substr($idm, 0, strpos($idm, ","));
            if (preg_match("/(as|AS)/", $idm, $as)) {
                $idm = substr($idm, 0, strpos($idm, $as[0]));
            }
            if (preg_match("/(\[ON|OFF)\(([a-z._]+)\)\]/", $this->SQL, $mas)) {
                $res = explode(".", $mas[2]);
                if (isset($res[1])) {
                    $str_repl_on = "'[ON(" .$res[0].".".$res[1].")]'";
                    $str_repl_off = "'[OFF(" .$res[0].".".$res[1].")]'";
                    $table_data = $mas[2];
                } else {
                    $str_repl_on = "'[ON(" .$res[0].")]'";
                    $str_repl_off = "'[OFF(" .$res[0].")]'";
                    $table_data = $res[0]."."."is_active_sw";
                }
                $this->SQL = str_replace($str_repl_on, "CONCAT_WS('','<img src=\"core2/html/".THEME."/img/on.png\" alt=\"on\" onclick=\"blockList.switch_active(this, event)\" t_name = ".$table_data.".', ".$idm.",'>')", $this->SQL);
                $this->SQL = str_replace($str_repl_off, "CONCAT_WS('','<img src=\"core2/html/".THEME."/img/off.png\" alt=\"off\" onclick=\"blockList.switch_active(this, event)\" t_name = ".$table_data.".', ".$idm.",'>')", $this->SQL);
            } else {
                $this->SQL = str_replace("[ON]", "<img src=\"core2/html/".THEME."/img/on.png\" alt=\"on\" />", $this->SQL);
                $this->SQL = str_replace("[OFF]", "<img src=\"core2/html/".THEME."/img/off.png\" alt=\"off\" />", $this->SQL);
            }
        }

        if ( ! empty($questions)) {
            $this->search_sql = $search;
            foreach ($questions as $question) {
                $this->search_sql = $db->quoteInto($this->search_sql, $question, null, 1);
            }
        } else {
            $this->search_sql = $search;
        }

//        if ( ! empty($questions)) {
//            $this->search_sql = $search;
//            foreach ($questions as $question) {
//                $this->search_sql = $db->quoteInto($this->search_sql, $question, null, 1);
//            }
//        } else {
//            $this->search_sql = $search;
//        }

        if ($this->SQL) {
            $this->SQL = str_replace(["/*ADD_SEARCH*/", "ADD_SEARCH"], $this->search_sql, $this->SQL);
        }

        $order = isset($tmp['order']) ? $tmp['order'] : '';
        if (isset($this->table_column[$this->main_table_id]) && is_array($this->table_column[$this->main_table_id])) {
            foreach ($this->table_column[$this->main_table_id] as $seq => $columns) {
                if ($columns['type'] == 'function' && $order && $order == $seq + 1) {
                    $this->extOrder = true;
                    break;
                };
            }
        }

        if ($this->SQL &&
            isset($this->table_column[$this->main_table_id]) &&
            ! $this->extOrder && !$this->customSearchHasVal
        ) {
            if ($order) {
                $orderField = $order + 1;
                $tempSQL    = $this->SQL;
                $check      = explode("ORDER BY", $tempSQL);
                $lastPart   = end($check);
                if (count($check) > 1 && !empty($lastPart) && strpos($lastPart, 'FROM ') === false) {
                    $tempSQL = "";
                    $co = count($check);
                    for ($i = 0; $i <= $co - 2; $i++) {
                        $tempSQL .= $check[$i];
                        if ($i < $co - 2) $tempSQL .= " ORDER BY ";
                    }
                    $this->SQL = $tempSQL . " ORDER BY " . $orderField . " " . $tmp['orderType'];
                }
                $this->SQL = $tempSQL . " ORDER BY " . $orderField . " " . $tmp['orderType'];
            }

            if ($this->sessData[$pagePOST] == 1) {
                $this->SQL .= " LIMIT " . $this->sessData[$countPOST];
            } else if ($this->sessData[$pagePOST] > 1){
                $this->SQL .= " LIMIT " . ($this->sessData[$pagePOST] - 1) * $this->sessData[$countPOST] . "," . $this->sessData[$countPOST];
            }
        }

        $res = [];

        if ($this->SQL) {
            if ($this->roundRecordCount) {
//                $expl = $db->fetchAll('EXPLAIN ' . $this->SQL, $questions);
                $expl = $db->fetchAll('EXPLAIN ' . $this->SQL);
                $this->recordCount = 0;
                foreach ($expl as $value) {
                    if ($value['rows'] > $this->recordCount) {
                        $this->recordCount = $value['rows'];
                    };
                }
//                $res = $db->fetchAll($this->SQL, $questions);
                $res = $db->fetchAll($this->SQL);
            } else {
                if ($this->config->database->adapter === 'Pdo_Mysql') {
                    //$res = $db->fetchAll("SELECT SQL_CALC_FOUND_ROWS " . substr(trim($this->SQL), 6), $questions);
                    $res = $db->fetchAll("SELECT SQL_CALC_FOUND_ROWS " . substr(trim($this->SQL), 6));
                    if (!$this->recordCount) $this->recordCount = $db->fetchOne("SELECT FOUND_ROWS()");
                } elseif ($this->config->database->adapter === 'Pdo_Pgsql') {
                    $this->SQL = str_replace('`', '"', $this->SQL ); //TODO find another way
                    $res = $db->fetchAll($this->SQL, $questions);
                    $this->recordCount = $db->fetchOne("SELECT COUNT(1) FROM ({$this->SQL}) AS t", $questions);
                }
            }
        }
        $this->setDatabase(null);

        //echo round(microtime() - $a, 3);
        if (is_array($res) && $res) {
            $i = 0;
            foreach ($res[0] as $field => $sql_value) {
                $this->columnSchema[$field] = $i;
                $i++;
            }
            foreach ($res as $k => $row) {
                $this->data[$k] = array();
                $x = 0;
                foreach ($row as $sql_value) {
                    if ($x == 0) {
                        $this->data[$k][0] = $sql_value;
                        $x++;
                        continue;
                    }
                    if (isset($this->table_column[$this->main_table_id][$x - 1])) {
                        $value = $this->table_column[$this->main_table_id][$x - 1];
                    } else{
                        $value = array();
                        $value['type'] = '';
                    }
                    
                    //$sql_value = stripslashes($sql_value);
                    
                    if ($value['type'] == 'function') {
                        eval("\$sql_value = " . $value['processing'] . "(\$row);");
                    } elseif ($value['type'] == 'html') {
                        //
                    } else {
                        if (isset($this->table_column[$this->main_table_id][$x])) {
                            $sql_value = htmlspecialchars($sql_value ?? '');
                        }
                    }
                    $this->data[$k][] = $sql_value;
                    $x++;
                }
            }
        }

        $this->dataAlreadyGot = true; //ФЛАГ ПОЛУЧЕНИЯ ДАННЫХ

        //SET META DATA
        foreach ($this->data as $k => $row) {
            if (!empty($this->paintCondition)) {
                if (!is_array($this->paintCondition)) {
                    $this->paintCondition = array($this->paintCondition);
                    $this->paintColor = array($this->paintColor);
                    $this->fontColor = array($this->fontColor);
                    $this->fontWeight = array($this->fontWeight);
                }
                foreach ($this->paintCondition as $ckey => $cvalue) {
                    $tres = $this->replaceTCOL($row, $cvalue);
                    $a = 0;
                    eval("if ($tres) \$a = 1;");
                    if ($a) {
                        $this->metadata[$k] = array('paintColor' => '', 'fontColor' => '', 'fontWeight' => '');
                        if (!empty($this->paintColor[$ckey])) $this->metadata[$k]['paintColor'] = $this->paintColor[$ckey];
                        if (!empty($this->fontColor[$ckey])) $this->metadata[$k]['fontColor'] = $this->fontColor[$ckey];
                        if (!empty($this->fontWeight[$ckey])) $this->metadata[$k]['fontWeight'] = $this->fontWeight[$ckey];
                    }
                }
            }
        }
        return $this->data;
    }


    /**
     * @param $cols
     */
    public function addHeader($cols) {
        $this->extraHeaders[] = $cols;
    }


    /**
     * Create grid HTML
     * @return string
     * @throws Exception
     */
    public function makeTable() {
        
        if ( ! count($this->data) && ! $this->dataAlreadyGot) {
            $this->data = $this->getData();
        }

        if ( ! $this->recordCount) {
            $this->recordCount = count($this->data);
        }

        $countPOST = 'count_' . $this->resource; //pagination record count
        $pagePOST  = '_page_' . $this->resource; //pagination page number

        //Шаблон для сообщений об ошибках
        $tpl = new Templater2('core2/html/' . THEME . "/list/error.tpl");
        $tpl->assign('[ID]', "{$this->main_table_id}_error");

        if ($this->error) {
            $tpl->assign('"error', '"error block');
            $tpl->assign('[MSG]', $this->error);
        }

        $this->HTML .= $tpl->parse();

        $serviceHeadHTML = "";
        $sqlSearchCount  = 0;


        if (isset($this->table_search[$this->main_table_id]) &&
            count($this->table_search[$this->main_table_id])
        ) {
            if ( ! empty($this->sessData['search'])) {
                reset($this->sessData['search']);
                $next = current($this->sessData['search']);
            } else {
                $next = null;
            }

            $tpl = new Templater3('core2/html/' . THEME . "/list/searchHead.tpl");
            $tpl->assign('[RESOURCE]', $this->resource);
            $tpl->assign('[AJAX]',     $this->ajax);

            if ( ! empty($this->sessData['search']) && count($this->sessData['search'])) {
                $tpl->touchBlock('clear');
            }

            // Фильтра колонок
            if ($this->filterColumn) {
                $tpl->touchBlock('col');
                $tpl->touchBlock('filterColumnContainer');

                foreach ($this->table_column[$this->main_table_id] as $k => $cols) {
                    $tpl->filterColumnContainer->filterColumn->assign('{COL_CAPTION}', $cols['name']);
                    $tpl->filterColumnContainer->filterColumn->assign('{VAL}',         $k + 1);

                    if ( ! empty($this->sessData['column'])) {
                        if ( ! in_array($k + 1, $this->sessData['column'])) {
                            $tpl->filterColumnContainer->filterColumn->assign('{checked}', '');
                        } else {
                            $tpl->filterColumnContainer->filterColumn->assign('{checked}', 'checked');
                        }

                    } else {
                        $tpl->filterColumnContainer->filterColumn->assign('{checked}', 'checked');
                    }

                    if ($k + 1 < count($this->table_column[$this->main_table_id])) {
                        $tpl->filterColumnContainer->filterColumn->reassign();
                    }
                }

                if ($this->show_templates) {
                    $tpl->filterColumnContainer->touchBlock('column_btn_template');
                } else {
                    $tpl->filterColumnContainer->touchBlock('column_btn');
                }
            }


            // Форма поиска
            $searchFields = $this->table_search[$this->main_table_id];
            foreach ($searchFields as $key => $value) {
                $searchFieldId = $this->search_table_id . $key;

                $tpl->fields->assign('{FIELD_CAPTION}', $value['name']);

                $temp = explode("_", $value['type']);
                $value['type'] = $temp[0];

                $tpl2 = new Templater3('core2/html/' . THEME . "/list/search_{$value['type']}.tpl");
                $tpl2->assign("{OUT}", $value['out']);
                $tpl2->assign("{NAME}", "search[$this->main_table_id][$key]");

                if ( ! in_array($value['type'], ['checkbox', 'checkbox2', 'multilist', 'multilist2'])) {
                    $tpl2->assign("{ID}", $searchFieldId);
                    $tpl2->assign("{ATTR}", $value['in']);
                    $value['value'] = '';
                    if (!empty($this->sessData['search'][$key])) {
                        $value['value'] = $this->sessData['search'][$key];
                    };
                    $tpl2->assign("{VALUE}", is_string($value['value']) ? htmlspecialchars($value['value']) : $value['value']);
                }

                if ($value['type'] == 'text' || $value['type'] == 'text_strict') {
                    $tpl->fields->assign('{FIELD_CONTROL}', $tpl2->render());
                }
                elseif ($value['type'] == 'number') {
                    $tpl_date = new Templater2(DOC_ROOT . 'core2/html/' . THEME . "/list/search_number.tpl");
                    $tpl_date->assign('[ID]',         $searchFieldId);
                    $tpl_date->assign('[NAME]',       "search[{$this->main_table_id}][$key][]");
                    $tpl_date->assign('[VALUE_FROM]', ! empty($next[0]) ? $next[0] : '');
                    $tpl_date->assign('[VALUE_TO]',   ! empty($next[1]) ? $next[1] : '');
                    $tpl_date->assign("[OUT]",        $value['out']);

                    $tpl->fields->assign('{FIELD_CONTROL}', $tpl_date->parse());
                }
                elseif ($value['type'] == 'date') {
                    $tpl_date = new Templater2(DOC_ROOT . 'core2/html/' . THEME . "/list/search_date.tpl");
                    $tpl_date->assign('[ID]',         $searchFieldId);
                    $tpl_date->assign('[NAME]',       "search[{$this->main_table_id}][$key][]");
                    $tpl_date->assign('[VALUE_FROM]', ! empty($next[0]) ? $next[0] : '');
                    $tpl_date->assign('[VALUE_TO]',   ! empty($next[1]) ? $next[1] : '');
                    $tpl_date->assign("[OUT]",        $value['out']);
                    $tpl_date->assign("[MASK]",       str_replace("yyyy", "yy", strtolower($this->date_mask)));

                    $tpl->fields->assign('{FIELD_CONTROL}', $tpl_date->parse());
                }
                elseif ($value['type'] == 'checkbox' || $value['type'] == 'checkbox2') {
                    $temp = $this->searchArrayArrange($this->sqlSearch[$sqlSearchCount]);
                    foreach ($temp as $j => $row) {
                        $k = current($row);
                        $v = end($row);

                        $tpl2->assign("{ID}", $searchFieldId . "_" . $k);
                        $tpl2->checkbox->assign("{0}", "[$j]");
                        $tpl2->checkbox->assign("{VALUE}", $k);
                        $tpl2->checkbox->assign("{LABEL}", $v);

                        if (is_array($next) && in_array($row[0], $next)) {
                            $tpl2->checkbox->assign("{checked}", " checked=\"checked\"");
                        } else {
                            $tpl2->checkbox->assign("{checked}", "");
                        }
                        $tpl2->checkbox->reassign();
                    }

                    $sqlSearchCount++;
                    // input нужен для того, чтобы обрабатывать пустые checkbox
                    // пустые чекбоксы не постятся вообще
                    $tpl->fields->assign('{FIELD_CONTROL}', "<input type=\"hidden\" name=\"search[$this->main_table_id][$key][0]\">" . $tpl2->render());
                }
                elseif ($value['type'] == 'radio') {
                    $temp = $this->searchArrayArrange($this->sqlSearch[$sqlSearchCount]);
                    foreach ($temp as $row) {
                        $k = current($row);
                        $v = end($row);
                        if (is_array($v) && isset($v['value'])) {
                            $v = $v['value'];
                        }

                        $tpl2->radio->assign("{LABEL}", $v);
                        if ($row[0] === $next) {
                            $tpl2->radio->assign("{VALUE}", $k . "\" checked=\"checked");
                        } else {
                            $tpl2->radio->assign("{VALUE}", $k);
                        }
                        $tpl2->radio->reassign();
                    }
                    $sqlSearchCount++;
                    $tpl->fields->assign('{FIELD_CONTROL}', $tpl2->render());
                }
                elseif ($value['type'] == 'list') {
                    $options_raw = $this->searchArrayArrange($this->sqlSearch[$sqlSearchCount]);
                    $options     = array('' => 'Все');

                    foreach ($options_raw as $option_key => $option_value) {
                        if (is_array($option_value)) {
                            if (count($option_value) == 2) {
                                $k = current($option_value);
                                $v = end($option_value);
                                if (is_array($v) && isset($v['id']) && isset($v['value'])) {
                                    $k = $v['id'];
                                    $v = $v['value'];
                                }
                                $options[$k] = $v;

                            } elseif (count($option_value) == 3) {
                                $k  = current($option_value);
                                $v  = next($option_value);
                                $gr = end($option_value);
                                $options[$gr][$k] = $v;
                            }
                        } else {
                            $options[$option_key] = $option_value;
                        }
                    }
                    $sqlSearchCount++;

                    $tpl2->fillDropDown('{ID}', $options, $next);
                    $tpl->fields->assign('{FIELD_CONTROL}', $tpl2->render());
                }
                elseif (in_array($value['type'], ['multilist', 'multilist2'])) {
                    if ($value['type'] == 'multilist2') {
                        $this->scripts['multilist2'] = true;
                    }

                    $options_raw = $this->searchArrayArrange($this->sqlSearch[$sqlSearchCount]);
                    $options     = [];

                    foreach ($options_raw as $option_key => $option_value) {
                        if (is_array($option_value)) {
                            if (count($option_value) == 2) {
                                $k = current($option_value);
                                $v = end($option_value);
                                if (is_array($v) && isset($v['id']) && isset($v['value'])) {
                                    $k = $v['id'];
                                    $v = $v['value'];
                                }
                                $options[$k] = $v;

                            } elseif (count($option_value) == 3) {
                                $k  = current($option_value);
                                $v  = next($option_value);
                                $gr = end($option_value);
                                $options[$gr][$k] = $v;
                            }
                        } else {
                            $options[$option_key] = $option_value;
                        }
                    }
                    $sqlSearchCount++;

                    $tpl2->assign("{ATTR}", $value['in']);
                    $tpl2->assign("{ID}", $searchFieldId . "_" . $k);
                    $tpl2->fillDropDown('{ID}', $options, $next);

                    // input нужен для того, чтобы обрабатывать пустые checkbox
                    // пустые чекбоксы не постятся вообще
                    $tpl->fields->assign('{FIELD_CONTROL}', "<input type=\"hidden\" name=\"search[$this->main_table_id][$key][0]\">" . $tpl2->render());
                }

                if ( ! empty($this->sessData['search'])) {
                    $next = next($this->sessData['search']); // берем следующее значение
                }

                if ($key + 1 < count($searchFields)) {
                    $tpl->fields->reassign();
                } else {
                    if ($this->show_templates) {
                        $tpl->fields->touchBlock('search_btn_template');
                    } else {
                        $tpl->fields->touchBlock('search_btn');
                    }
                }
            }

            // Шаблоны поиска
            if ($this->show_templates) {

                $profile_controller = $this->getProfileController();
                if ($profile_controller instanceof ModProfileController) {
                    $hash           = $this->getUniqueHash();
                    $user_templates = $profile_controller->getUserData("list_template_" . $hash);

                    if ( ! empty($user_templates)) {
                        $tpl->touchBlock('templates_list');

                        $user_templates = array_reverse($user_templates);

                        foreach ($user_templates as $template_id => $user_template) {
                            $tpl->templates_container->template_item->assign('[ID]',    $template_id);
                            $tpl->templates_container->template_item->assign('[TITLE]', $user_template['title']);
                            $tpl->templates_container->template_item->reassign();
                        }
                    }
                }
            }

            $serviceHeadHTML .= $tpl->render();
        }



        if ($this->scripts) {
            if (isset($this->scripts['multilist2'])) {
                Tool::printCss("core2/html/" . THEME . "/css/select2.min.css");
                Tool::printCss("core2/html/" . THEME . "/css/select2.bootstrap.css");
                Tool::printJs("core2/html/" . THEME . "/js/select2.min.js", true);
                Tool::printJs("core2/html/" . THEME . "/js/select2.ru.min.js", true);
            }
        }

        
        // DATA HEADER первая строка таблицы
        $tpl = new Templater2("core2/html/" . THEME . "/list/headerHead.html");
        $tpl->assign('{main_table_id}', $this->main_table_id);
        $tpl->assign('{resource}',      $this->resource);
        $tpl->assign('isAjax',          $this->ajax);

        $eh = count($this->extraHeaders);
        if ($eh) {
            $tpl->assign('{ROWSPAN}', $eh + 1);
        } else {
            $tpl->assign('{ROWSPAN}', 1);
        }

        $temp             = '';
        $columnsToReplace = [];

        if ($eh) { // добавляем дополнительные строки в шапку таблицы
//            $cell = $tpl->getBlock('extracell');
            $tpl->assign('{ROWSPAN}', $eh + 1);

            foreach ($this->extraHeaders as $k => $cols) {
                foreach ($cols as $caption => $span) {
                    if (isset($span['replace']) && $span['replace']) {
                        $columnsToReplace[] = $k;
                    }

                    if ( ! isset($span['col'])) $span['col'] = 1;
                    if ( ! isset($span['row'])) $span['row'] = 1;

//                    $temp .= str_replace(
//                        ['{CAPTION}', '{COLSPAN}', '{ROWSPAN2}'],
//                        [$caption, $span['col'], $span['row'],],
//                        $cell
//                    );
                    $tpl->extrahead->extracell->assign('{CAPTION}', $caption);
                    $tpl->extrahead->extracell->assign('{COLSPAN}', $span['col']);
                    $tpl->extrahead->extracell->assign('{ROWSPAN2}', $span['row']);
                    $tpl->extrahead->extracell->reassign();
                }
            }
//            $tpl->replaceBlock('extracell', $temp);
//            $tpl->touchBlock('extrahead');

        } else {
            $tpl->assign('{ROWSPAN}', 1);
        }

        $temp       = '';
        $cell       = $tpl->getBlock('cell');
        $cellnosort = $tpl->getBlock('cellnosort');

        foreach ($this->table_column[$this->main_table_id] as $key => $value) {
            if (in_array($key, $columnsToReplace)) {
                continue;
            }

            if ($this->filterColumn &&
                ! empty($this->sessData['column']) &&
                ! in_array($key + 1, $this->sessData['column'])
            ) {
                continue;
            }

            if ($value['sort']) {
                $img = '';

                if ( ! empty($this->sessData['order'])) {
                    if ($this->sessData['order'] == $key + 1) {
                        if ($this->sessData['orderType'] == "asc") {
                            $img = "core2/html/".THEME."/img/asc.gif";
                        }
                        elseif ($this->sessData['orderType'] == "desc") {
                            $img = "core2/html/".THEME."/img/desc.gif";
                        }

                        if ($img) {
                            $img = '<img src="' . $img . '" alt=""/>';
                        }
                    }
                }

                $temp .= str_replace(
                    ['{WIDTH}', '{ORDER_VALUE}', '{CAPTION}', '{ORDER_TYPE}', '{COLSPAN}'], [
                    $value['width'], ($key + 1), $value['name'], $img, ''],
                    $cell
                );
//                $tpl->cell->assign('{WIDTH}', $value['width']);
//                $tpl->cell->assign('{ORDER_VALUE}', $key + 1);
//                $tpl->cell->assign('{CAPTION}', $value['name']);
//                $tpl->cell->assign('{ORDER_TYPE}', $img);
//                $tpl->cell->assign('{COLSPAN}', '');

            } else {
                $temp .= str_replace(
                    ['{WIDTH}', '{CAPTION}', '{COLSPAN}'],
                    [$value['width'], $value['name'], ''],
                    $cellnosort
                );
            }
        }
        $tpl->replaceBlock('cell', $temp);
        if ($this->noCheckboxes == 'no') {
            $tpl->touchBlock('checkboxes');
        }
        $headerHeadHTML = $tpl->parse();

        //TABLE BODY.
        $tableBodyHTML = '';
        $int_count = 0;
        if ( ! $this->extOrder) {
            $recordNumber = ($this->sessData[$pagePOST] - 1) * $this->sessData[$countPOST];
        }
        if (count($this->addSum)) {
            $needsum = [];
        } else {
            $needsum = 0;
        }

        //BUILD ROWS WITH DATA
        //echo "<PRE>";print_r($this->data);echo"</PRE>";die();
        $i = 0;
        if (!empty($this->customSearch)) { //CHECK SEACH FOR CUSTOM FIELDS
            $newData = array();
            foreach ($this->data as $row) {
            
                $skip = false;
                foreach ($this->customSearch as $field => $search_value) {
                    $search_value_trimmed = trim($search_value, '%');
                    if (!empty($search_value) && $search_value_trimmed && isset($this->columnSchema[$field])) {
                        if (substr($search_value, 0, 1) != '%' && substr($search_value, -1) != '%') {
                            if ($row[$this->columnSchema[$field]] != $search_value_trimmed) $skip = true;
                        } elseif (substr($search_value, 0, 1) == '%' && substr($search_value, -1) != '%') {
                            if (strripos($row[$this->columnSchema[$field]], $search_value_trimmed) !== 0) $skip = true;
                        } else {
                            if (stripos($row[$this->columnSchema[$field]], $search_value_trimmed) === false) $skip = true;
                        }
                    }
                }
                if ($skip) {
                    $this->recordCount--;
                } else {
                    $newData[] = $row;
                }
            }
            $this->data = $newData;
        }

        if ($this->extOrder) { //сортировка после обработки данных. только в случае кастомной обраьртки
            $orderType = isset($this->sessData['orderType']) ? $this->sessData['orderType'] : '';
            $order = isset($this->sessData['order']) ? $this->sessData['order'] : '';
            $this->data = $this->array_key_multi_sort($this->data, $order, $orderType);
        }

        if (!$this->recordCount || $this->recordCount < 0) {
            $this->fixHead = false;
            $tableBodyHTML = "<tr><td colspan=\"100\" align=\"center\" style=\"padding:5px\">{$this->classText['NORESULT']}</td></tr>";
        } else {
            // Формируем основное содержимое таблицы
            foreach ($this->data as $k => $row) {
                if ($this->extOrder) {
                    if ($i < $this->sessData[$pagePOST] * $this->sessData[$countPOST] - $this->sessData[$countPOST]) {
                        $i++;
                        continue;
                    }
                    if ($i + 1 > $this->sessData[$pagePOST] * $this->sessData[$countPOST]) break;
                    $recordNumber = $i + 1;
                } else {
                    $recordNumber++;
                }

                $recordClass = array();

                $tableBodyHTML .= '<tr';
                if ( ! empty($this->metadata[$k])) {
                    if ( ! empty($this->metadata[$k]['paintColor']) ||
                         ! empty($this->metadata[$k]['fontColor']) ||
                         ! empty($this->metadata[$k]['fontWeight'])
                    ) {
                        $tableBodyHTML .= ' style="';
                        if ( ! empty($this->metadata[$k]['paintColor'])) $tableBodyHTML .= "background-color:{$this->metadata[$k]['paintColor']};";
                        if ( ! empty($this->metadata[$k]['fontColor'])) $tableBodyHTML .= "color:{$this->metadata[$k]['fontColor']};";
                        if ( ! empty($this->metadata[$k]['fontWeight'])) $tableBodyHTML .= "font-weight:{$this->metadata[$k]['fontWeight']};";
                        $tableBodyHTML .= '"';
                    }
                }

                if ($this->editURL &&
                        ($this->checkAcl($this->resource, 'edit_all')
                            || $this->checkAcl($this->resource, 'edit_owner')
                            || $this->checkAcl($this->resource, 'read_all')
                            || $this->checkAcl($this->resource, 'read_owner')
                        )
                ) {
                    $tres = $this->replaceTCOL($row, $this->editURL);
                    $recordClass[] = 'pointer';
                    if (strpos(strtolower($tres), 'javascript:') === 0) {
                        $tableBodyHTML .= ' onclick="' . substr($tres, 11) . '"';
                    } else {
                        $tableBodyHTML .= ' onclick="load(\'' . $tres . '\')"';
                    }
                }
                if ($recordClass) {
                    $tableBodyHTML .= " class=\"" . implode(" ", $recordClass) . "\"";
                }
                reset($row);
                $tableBodyHTML .= "><td title=\"" . current($row) . "\">$recordNumber</td>";
                $look = "";

                $columnCount = count($this->table_column[$this->main_table_id]); // к-во столбцов

                reset($row);
                next($row);
                for ($sql_key = 1; $sql_key <= $columnCount; $sql_key++) {
                    //$sql_value = $row[$sql_key];
                    $sql_value = current($row);
                    next($row);
                    if ($this->filterColumn && isset($this->sessData['column']) && !in_array($sql_key, $this->sessData['column'])) continue;

                    if (is_array($needsum)) {
                        if (in_array($sql_key, $this->addSum)) {
                            $needsum["_" . $sql_key] = isset($needsum["_" . $sql_key])
                                ? ( ! empty($needsum["_" . $sql_key]) ? $needsum["_" . $sql_key] : 0) + ( ! empty($sql_value) ? $sql_value : 0)
                                : $sql_value;
                        }
                    }

                    $value = $this->table_column[$this->main_table_id][$sql_key - 1];
                    $temp  = "";
                    if ($value['type'] == 'block') {
                        $temp .= " onclick=\"listx.cancel(event)\"";
                    }
                    if ($value['type'] == 'number') {
                        $temp .= " nowrap=\"nowrap\"";
                    }
                    if ($value['width']) {
                        $temp .= " width=\"{$value['width']}\"";
                    }
                    if ($value['type'] != 'status_inline') {
                        $temp .= " " . $this->replaceTCOL($row, $value['in']);
                    }
                    $tableBodyHTML .= "<td{$temp}>";

                    //RECOGNIZE TYPE
                    //$sql_value = htmlspecialchars($sql_value);

                    if ($value['type'] == 'text' || $value['type'] == 'function') {
                        $tableBodyHTML .= $sql_value;
                    } elseif ($value['type'] == 'number') {
                        $tableBodyHTML .= $this->commafy($sql_value);

                    } elseif ($value['type'] == 'html' || $value['type'] == 'block') {
                        $tableBodyHTML .= $sql_value ? htmlspecialchars_decode($sql_value) : '';
                    } else if ($value['type'] == 'date') {
                        $dd   = ! empty($sql_value) ? substr($sql_value, 8, 2) : '';
                        $mm   = ! empty($sql_value) ? substr($sql_value, 5, 2) : '';
                        $yyyy = ! empty($sql_value) ? substr($sql_value, 0, 4) : '';
                        $yy   = ! empty($sql_value) ? substr($sql_value, 2, 2) : '';

                        $tableBodyHTML .= str_replace(array("dd", "mm", "yyyy", "yy"), array($dd, $mm, $yyyy, $yy), strtolower($this->date_mask));

                    } else if ($value['type'] == 'datetime') {
                        $dd   = ! empty($sql_value) ? substr($sql_value, 8, 2) : '';
                        $mm   = ! empty($sql_value) ? substr($sql_value, 5, 2) : '';
                        $yyyy = ! empty($sql_value) ? substr($sql_value, 0, 4) : '';
                        $yy   = ! empty($sql_value) ? substr($sql_value, 2, 2) : '';
                        $time = ! empty($sql_value) ? substr($sql_value, 11) : '';

                        $sql_value = str_replace(array("dd", "mm", "yyyy", "yy"), array($dd, $mm, $yyyy, $yy), strtolower($this->date_mask));
                        $tableBodyHTML .= $sql_value . ' ' . $time;
                    } else if ($value['type'] == 'datetime_human') {
                        require_once('humanRelativeDate.class.php');
                        $humanRelativeDate = new HumanRelativeDate();
                        $dd                = ! empty($sql_value) ? substr($sql_value, 8, 2) : '';
                        $mm                = ! empty($sql_value) ? substr($sql_value, 5, 2) : '';
                        $yyyy              = ! empty($sql_value) ? substr($sql_value, 0, 4) : '';
                        $yy                = ! empty($sql_value) ? substr($sql_value, 2, 2) : '';
                        $time              = ! empty($sql_value) ? substr($sql_value, 11) : '';

                        $title = str_replace(array("dd", "mm", "yyyy", "yy"), array($dd, $mm, $yyyy, $yy), strtolower($this->date_mask)) . ' ' . $time;
                        $sql_value = trim($sql_value);
                        $tableBodyHTML .= $sql_value ? "<span title=\"$title\">{$humanRelativeDate->getTextForSQLDate($sql_value)}</span>" : '-';
                    } else if ($value['type'] == 'look') {
                        $tableBodyHTML .= "<div onclick='listx.cancel2(event, \"look" . $this->main_table_id . $int_count . "\");'>" . stripslashes($sql_value) . "</div>";
                        $look = $this->replaceTCOL($row, $value['processing']);
                    } else if ($value['type'] == 'hint') {
                        $SQL      = $this->replaceTCOL($row, $value['processing']);
                        $hint_res = $this->db->fetchAll($SQL);
                        $hint     = "<table class=\"editHintTable\">";
                        foreach ($hint_res as $hint_row) {
                            $hint .= "<tr>";
                            foreach ($hint_row as $hint_key => $hint_value) {
                                $hint .= "<td>$hint_value</td>";
                            }
                            $hint .= "</tr>";
                        }
                        $hint .= "</table>";
                        $tableBodyHTML .= "<span class=\"editHintSpan\" onmouseover=\"this.nextSibling.style.display='block'\" onmouseout=\"this.nextSibling.style.display='none'\">$sql_value</span><div class=\"editHintDiv\" style=\"display:none;\">" . $hint . "</div>";
                    } elseif ($value['type'] == 'status') {
                        if ($sql_value == 1 || $sql_value == 'Y' || $sql_value == '[ON]') {
                            $tableBodyHTML .= "<img src=\"core2/html/" . THEME . "/img/on.png\" alt=\"on\" />";
                        } else {
                            $tableBodyHTML .= "<img src=\"core2/html/" . THEME . "/img/off.png\" alt=\"off\" />";
                        }
                    } elseif ($value['type'] == 'status_inline') {
                        $evt = "";
                        if ($this->checkAcl($this->resource, 'edit_owner') || $this->checkAcl($this->resource, 'edit_all')) {
                            $evt = "onclick=\"listx.switch_active(this, event)\" t_name=\"{$value['in']}\" val=\"{$row[0]}\" title=\"{$this->classText['SWITCH']}\"";
                        }
                        if ($sql_value == 1 || $sql_value == 'Y' || $sql_value == '[ON]') {
                            $tableBodyHTML .= "<img src=\"core2/html/" . THEME . "/img/on.png\" alt=\"on\" $evt/>";
                        } else {
                            $tableBodyHTML .= "<img src=\"core2/html/" . THEME . "/img/off.png\" alt=\"off\" $evt/>";
                        }
                    }

                    $tableBodyHTML .= "</td>";
                }

                if ($this->multiEdit) {
                    $onclick = "onclick=\"listx.cancel(event, '{$this->main_table_id}')\"";
                } else {
                    $onclick = "onclick=\"listx.cancel(event)\"";
                }
                $tempid = $this->resource . $int_count;
                if ($this->noCheckboxes === 'no') {
                    $tableBodyHTML .= "<td width=\"1%\" $onclick><input class=\"checkbox\" type=\"checkbox\" id=\"check{$tempid}\" name=\"check{$tempid}\" value=\"{$row[0]}\"></td>";
                }
                $tableBodyHTML .= "</tr>";
                if (isset($look) && $look) {
                    $tableBodyHTML .= "<tr id=\"look{$tempid}\" style=\"display:none\"><td colspan=\"100\">$look</td></tr>";
                }
                $int_count++;
                $i++;
            }

            if (!empty($this->addSum)) { // Добавляем строку с суммами по колонкам
                $colAffected = false;
                if ($this->filterColumn && !empty($this->sessData['column'])) {
                    $colAffected = true;
                }
                $tableBodyHTML .= "<tr class=\"columnSum\">";
                for ($i = 0; $i <= $columnCount; $i++) {
                    if ($i > 0 && $colAffected && !in_array($i, $this->sessData['column'])) {
                        continue;
                    }
                    if (!empty($needsum["_" . $i])) {
                        $tableBodyHTML .= "<td align=\"right\" nowrap=\"nowrap\">" . $this->commafy($needsum["_" . $i]) . "</td>";
                    } else {
                        $tableBodyHTML .= "<td></td>";
                    }
                }
                $tableBodyHTML .= "</tr>";
            }
        }


        //SERVICE ROW
        // Панель с кнопками
        // к-во записей
        $tpl = new Templater3('core2/html/' . THEME . "/list/serviceHead.tpl");
        $tpl->assign('[TOTAL_RECORD_COUNT]', ($this->roundRecordCount ? "~" : "") . $this->recordCount);

        $buttons = '';
        if (!empty($this->table_button[$this->main_table_id])) {
            reset($this->table_button[$this->main_table_id]);
            foreach ($this->table_button[$this->main_table_id] as $button_key => $button_value) {
                if (empty($button_value['html'])) {
                    $params = "'{$this->resource}', '{$button_value['url']}', '{$button_value['confirm']}', {$button_value['nocheck']}, this";
                    if ($button_value['callback']) $params .= ", {$button_value['callback']}";
                    $buttons .= $this->button($button_value['name'], "", "listx.buttonAction($params)");
                } else {
                    $buttons .= $button_value['html'];
                }
            }
        }
        $tpl->assign('[BUTTONS]', $buttons);

        if ( ! empty($this->service_content[$this->main_table_id])) {
            foreach ($this->service_content[$this->main_table_id] as $content) {
                $tpl->service_content->assign('[CONTENT]', $content);
                $tpl->service_content->reassign();
            }
        }

        if ($this->checkAcl($this->resource, 'edit_all') || $this->checkAcl($this->resource, 'edit_owner') && ($this->checkAcl($this->resource, 'read_all') || $this->checkAcl($this->resource, 'read_owner'))) {
            //if ($this->multiEdit) $serviceHeadHTML .=     $this->button($this->classText['EDIT'], "", "multiEdit('$this->editURL', '$this->main_table_id')");
            if ($this->addURL) {
                $tpl->addButton->assign('Добавить', $this->classText['ADD']);
                if (substr($this->addURL, 0, 11) == 'javascript:') {
                    $tpl->addButton->assign('[addURL]', substr($this->addURL, 11));
                } else {
                    $tpl->addButton->assign('[addURL]', "load('{$this->addURL}')");
                }
            }
        }

        if (($this->deleteURL || $this->deleteKey) && ($this->checkAcl($this->resource, 'delete_all') || $this->checkAcl($this->resource, 'delete_owner'))) {
            $tpl->delButton->assign('Удалить', $this->classText['DELETE']);
            if ($this->deleteURL) {
                $tpl->delButton->assign('[delURL]', $this->deleteURL);
            } else {
                $tpl->delButton->assign('[delURL]', "listx.del('{$this->resource}', '{$this->classText['DELETE_MSG']}', $this->ajax)");
            }
        }

        $serviceHeadHTML .= $tpl->render();

        $tplRoot = new Templater2('core2/html/' . THEME . "/list/list.html");
        $tplRoot->assign('[ID]', "list{$this->resource}");
        $tplRoot->header->assign('[HEADER]', $serviceHeadHTML . $headerHeadHTML); // побликуем шапку списка
        $tplRoot->body->assign('[BODY]', $tableBodyHTML); // побликуем список

        if (!$this->noFooter) {
            // FOOTER ROW
            $tpl   = new Templater2("core2/html/" . THEME . "/list/footerx_controls.html");
            $count = ceil($this->recordCount / $this->recordsPerPage);

            //PAGINATION
            $pages = ceil($this->recordCount / ($this->sessData[$countPOST] ? $this->sessData[$countPOST] : $this->recordsPerPage));
            if ($pages) {
                $tpl->assign('{CURR_PAGE}', sprintf($this->translate->tr("%s из %s"), $this->sessData[$pagePOST], $pages));
            } else {
                $tpl->assign('{CURR_PAGE}', '');
            }
            $tpl->assign('[IDD]', 'pagin_' . $this->resource);
            $tpl->assign('[ID]', $this->resource);
            if ($count > 1) {
                $tpl->pages->assign('{GO_TO_PAGE}', "listx.goToPage(this, '$this->resource', $this->ajax)");

                if ($this->sessData[$pagePOST] > 1) {
                    $tpl->pages2->assign('{BACK}', $this->sessData[$pagePOST] - 1);
                }
                if ($this->sessData[$pagePOST] < $count) {
                    $tpl->pages3->assign('{FORW}', $this->sessData[$pagePOST] + 1);
                }
                $tpl->assign('{GO_TO}', "listx.pageSw(this, '$this->resource', $this->ajax)");
                $tpl->recordsPerPage->assign('{SWITCH_CO}', "listx.countSw(this, '$this->resource', $this->ajax)");
                $opts    = array();
                $notoall = false;
                for ($k = 0; $k < $count - 1; $k++) {
                    $val = $this->recordsPerPage * ($k + 1);
                    if ($val > 1000) {
                        $notoall = true;
                        break;
                    }
                    $opts[$val] = $val;
                }
                if (!$notoall) {
                    $opts[1000] = $this->classText['PAGIN_ALL'];
                }
                $tpl->fillDropDown("footerSelectCount", $opts, $this->sessData[$countPOST]);
                $tpl->assign('footerSelectCount', $this->main_table_id . 'footerSelectCount');
            }
            $tplRoot->footer->assign('[FOOTER]', $tpl->parse()); // побликуем footer
        }

        $this->HTML .= $tplRoot->parse();

        return $this->HTML;
    }


    /**
     * @return false|string
     */
    public function render() {

        ob_start();
        $this->showTable();
        return ob_get_clean();
    }


    /**
     * Print grid HTML
     * @return void
     */
    public function showTable() {
        if ($this->checkAcl($this->resource, 'list_all') || $this->checkAcl($this->resource, 'list_owner')) {
            $this->makeTable();
            $loc = $this->ajax ? $_SERVER['QUERY_STRING'] . "&__{$this->resource}=ajax" : $_SERVER['QUERY_STRING'];
            $this->setSessData('loc', $loc);
            $this->setSessData('deleteKey', $this->deleteKey);

            echo "<script>
                if (!listx){
                    alert('listx не найден!')
                }
                else {
                    listx.loc['{$this->resource}'] = '{$loc}';
                }
            </script>";
            echo $this->HTML;
            if ($this->fixHead) {
                echo "
                <script type=\"text/javascript\" src=\"core2/vendor/belhard/floatthead/dist/jquery.floatThead.min.js\"></script>
                <script>
                    $(function(){
                        listx.fixHead('list{$this->resource}');
                    });
                </script>";
            }
            //добавление скрипта для сортировки
            if ($this->table && $this->is_seq) {
                echo '<script>
                    $(function(){
                        listx.initSort("' . $this->resource . '", "' . $this->table . '");
                    });
                </script>';
            }
        }
    }


    /**
     * @param $va
     * @param $value
     */
    public function addParams($va, $value) {
        $this->params[$va] = $value;
    }


    /**
     * @param $arr
     * @param $l
     * @param $type
     * @return mixed
     */
    protected function array_key_multi_sort($arr, $l , $type) {
        if ($type == 'asc') usort($arr, function($a, $b) use ($l) {
            return strnatcasecmp($a[$l], $b[$l]);
        });
        if ($type == 'desc') usort($arr, function($a, $b) use ($l) {
            return strnatcasecmp($b[$l], $a[$l]);
        });
        return($arr);
    }


    /**
     * @param string $_
     * @param string $del
     * @return string
     */
    private function commafy($_, $del = ' ') {
        return strrev( (string)preg_replace( '/(\d{3})(?=\d)(?!\d*\.)/', '$1' . $del , strrev( $_ ) ) );
    }


    /**
     * Сохранение служебных данных в сессии
     * @param string $key
     * @param string $value
     */
    private function setSessData($key, $value) {
        $sess_form       = new SessionContainer('List');
        $ssi             = $this->resource;
        $tmp             = ! empty($sess_form->$ssi) ? $sess_form->$ssi : array();
        $tmp[$key]       = $value;
        $sess_form->$ssi = $tmp;
    }


    /**
     * Приводит разные виды массивов данных к одному
     * @param mixed $sqlSearch
     *
     * @return array
     */
    private function searchArrayArrange($sqlSearch) {
        $temp = array();
        if (!is_array($sqlSearch)) {
            $sqlSearch = $this->db->fetchAll($sqlSearch);
        }

        if (is_array(current($sqlSearch))) {
            $temp = $sqlSearch;
        } else {
            foreach ($sqlSearch as $k => $v) {
                $temp[] = array($k, $v);
            }
        }
        return $temp;
    }


    /**
     * Allow to replace TCOL_ or TCOL64_ in any string
     * Example: TCOL_01 will be replaced by $row[1]
     * @param array $row - data for replace
     * @param string $tcol - expression where to find TCOL_ construction
     * @return string
     */
    private function replaceTCOL($row, $tcol) {
        $tres = "";
        $temp = explode("TCOL_", $tcol);
        foreach ($temp as $tkey => $tvalue) {
            $index = intval(substr($tvalue, 0, 2));
            if ($tkey == 0) {
                $tres .= $tvalue;
            } elseif (isset($row[$index])) {
                if (strpos($row[$index], "'") !== false) {
                    $row[$index] = addslashes($row[$index]);
                }
                $tres .= $row[$index] . substr($tvalue, 2);
            } else {
                $tres .= substr($tvalue, 2);
            }
        }
        $temp = explode("TCOL64_", $tres);
        $tres2 = "";
        foreach ($temp as $tkey => $tvalue) {
            $index = intval(substr($tvalue, 0, 2));
            if ($tkey == 0) {
                $tres2 .= $tvalue;
            } elseif (isset($row[$index])) {
                $row[$index] = htmlspecialchars_decode($row[$index]);
                $row[$index] = base64_encode($row[$index]);
                $tres2 .= $row[$index] . substr($tvalue, 2);
            } else {
                $tres2 .= substr($tvalue, 2);
            }
        }
        if ($tres2) $tres = $tres2;
        $temp  = explode("TCOL64URL_", $tres);
        $tres2 = "";
        foreach ($temp as $tkey => $tvalue) {
            $index = intval(substr($tvalue, 0, 2));
            if ($tkey == 0) {
                $tres2 .= $tvalue;
            } elseif (isset($row[$index])) {
                $tres2 .= Tool::base64url_encode($row[$index]) . substr($tvalue, 2);
            } else {
                $tres2 .= substr($tvalue, 2);
            }
        }
        if ($tres2) $tres = $tres2;
        return $tres;
    }


    /**
     * Получение контроллера профиля
     * @return ModProfileController|false
     * @throws Exception
     */
    private function getProfileController() {

        if ($this->isModuleInstalled('profile')) {
            $profile_location = $this->getModuleLocation('profile');
            require_once "$profile_location/vendor/autoload.php";
            require_once "$profile_location/ModProfileController.php";
            return new ModProfileController();

        } else {
            return false;
        }
    }


    /**
     * Получение хэша соответствующего текущему набору поисковых полей, колонок и имени
     * @return false|string
     */
    private function getUniqueHash() {

        $indicators = [];
        foreach ($this->table_search[$this->main_table_id] as $search_field) {
            $indicators[] = $search_field['type'];
        }

        $indicators[] = $this->filterColumn
            ? count($this->table_column[$this->main_table_id])
            : 0;

        return hash('crc32b', $this->resource . implode('', $indicators));
    }
}
