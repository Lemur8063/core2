<?php
require_once("class.ini.php");
require_once 'Templater3.php';
require_once __DIR__ . '/../Traits/Import.php';

use Laminas\Session\Container as SessionContainer;
use Core2\Tool;
use Core2\Registry;
use Core2\Traits;
use Core2\Theme;

$counter = 0;


/**
 * @property Core2\Acl $acl
 */
class editTable extends initEdit {

    use Traits\Import;

    public    $selectSQL           = [];
    public    $buttons             = [];
    public    $params              = [];
    public    $modal               = [];
    public    $saveConfirm         = "";
    public    $SQL                 = "";
    public    $HTML                = "";
    public    $readOnly            = false;
    public    $table               = '';
    public    $error               = '';
    protected $controls            = [];
    protected $resource            = "";
    protected $cell                = [];
    protected $template            = '';
    private   $main_table_id       = "";
    private   $beforeSaveArr       = [];
    private   $isSaved             = false;
    private   $form_leave_checking = false;
    private   $scripts             = [];
    private   $sess_form_custom    = [];
    private   $read_only_fields    = [];
    private   $uniq_class_id       = '';


    const TYPE_TEXT           = 'text';
    const TYPE_HIDDEN         = 'hidden';
    const TYPE_NUMBER         = 'number';
    const TYPE_NUMBER_RANGE   = 'number_range';
    const TYPE_MONEY          = 'money';
    const TYPE_TEXTAREA       = 'textarea';
    const TYPE_FCK            = 'fck';
    const TYPE_PASSWORD       = 'password';
    const TYPE_RADIO          = 'radio';
    const TYPE_RADIO2         = 'radio2';
    const TYPE_CHECKBOX       = 'checkbox';
    const TYPE_CHECKBOX2      = 'checkbox2';
    const TYPE_SELECT         = 'select';
    const TYPE_SELECT2        = 'select2';
    const TYPE_MULTILIST      = 'multilist';
    const TYPE_MULTILIST2     = 'multilist2';
    const TYPE_MULTILIST3     = 'multilist3';
    const TYPE_MULTISELECT2   = 'multiselect2';
    const TYPE_TAGS           = 'tags';
    const TYPE_DATASET        = 'dataset';
    const TYPE_FILE           = 'file';
    const TYPE_XFILE          = 'xfile';
    const TYPE_XFILE_AUTO     = 'xfile_auto';
    const TYPE_XFILES         = 'xfiles';
    const TYPE_XFILES_AUTO    = 'xfiles_auto';
    const TYPE_LINK           = 'link';
    const TYPE_PROTECTED      = 'protected';
    const TYPE_CUSTOM         = 'custom';
    const TYPE_DATE           = 'date';
    const TYPE_DATE2          = 'date2';
    const TYPE_DATETIME       = 'datetime';
    const TYPE_DATETIME2      = 'datetime2';
    const TYPE_DATETIME_LOCAL = 'datetime_local';
    const TYPE_DATE_WEEK      = 'date_week';
    const TYPE_DATE_MONTH     = 'date_month';
    const TYPE_DATE_RANGE     = 'daterange';
    const TYPE_TIME           = 'time';
    const TYPE_COLOR          = 'color';
    const TYPE_COORDINATES    = 'coordinates';
    const TYPE_SWITCH         = 'switch';
    const TYPE_COMBOBOX       = 'combobox';
    const TYPE_MODAL          = 'modal';
    const TYPE_MODAL2         = 'modal2';
    const TYPE_MODAL_LIST     = 'modal_list';
    const THEME_HTML          = 'core2/html/' . THEME;

    /**
     * form action attribute
     * @var string
     */
    private $action = '';
    private $acl;

    private $tpl_control = [
        'files'          => __DIR__ . '/../../html/' . THEME . '/html/edit/files.html',
        'xfile_upload'   => __DIR__ . '/../../html/' . THEME . '/html/edit/file_upload.html',
        'xfile_download' => __DIR__ . '/../../html/' . THEME . '/html/edit/file_download.html',
        'dataset'        => __DIR__ . '/../../html/' . THEME . '/html/edit/dataset.html',
        'switch'         => __DIR__ . '/../../html/' . THEME . '/html/edit/switch.html',
        'switch_button'  => __DIR__ . '/../../html/' . THEME . '/html/edit/button_switch.html',
        'combobox'       => __DIR__ . '/../../html/' . THEME . '/html/edit/combobox.html',
        'date2'          => __DIR__ . '/../../html/' . THEME . '/html/edit/date2.html',
        'datetime2'      => __DIR__ . '/../../html/' . THEME . '/html/edit/datetime2.html',
        'datetime'       => __DIR__ . '/../../html/' . THEME . '/html/edit/datetime.html',
        'color'          => __DIR__ . '/../../html/' . THEME . '/html/edit/color.html',
        'modal'          => __DIR__ . '/../../html/' . THEME . '/html/edit/modal_list.html',
        'modal2'         => __DIR__ . '/../../html/' . THEME . '/html/edit/modal2.html',
        'coordinates'    => __DIR__ . '/../../html/' . THEME . '/html/edit/coordinates.html',
    ];


    /**
     * editTable constructor.
     * @param string $name
     */
	public function __construct($name) {

		parent::__construct();
		$this->resource 		= $name;
		$this->main_table_id 	= "main_" . $name;
		$this->template 		= '<div id="' . $this->main_table_id . '_default">[default]</div>';
		$this->uniq_class_id   	= $name;

		global $counter;
		$counter = 0;
		$this->acl = new stdClass();
		foreach ($this->types as $acl_type) {
			$this->acl->$acl_type = $this->checkAcl($this->resource, $acl_type);
		}
        $this->setTemplateControl('xfile', Theme::get("html-edit-files"));
        //TODO заполнить остальные шаблоны из модели шкурки (или прописать напрямую из \Core2\Theme)
    }


    /**
     * @param string $data
     * @return cell|mixed
     * @throws Zend_Exception
     */
	public function __get($data) {
        if ($data === 'db' || $data === 'cache' || $data === 'translate') {
            return parent::__get($data);
        }
        if (isset($this->cell[$data])) return $this->cell[$data];
		$this->cell[$data] = new cell($this->main_table_id);
       	return $this->cell[$data];
	}


	/**
	 * set HTML layout for the form
	 * @param string $html
     * @return $this
	 */
	public function setTemplate($html): self {
		$this->template = $html;
        return $this;
	}


    /**
     * set custom filename for any form control
     * @param $type - form control type
     * @param $filename - absolute path to the file
     * @return $this
     */
    public function setTemplateControl($type, $filename): self {
        if (is_file($filename)) {
            $this->tpl_control[$type] = $filename;
        }
        return $this;
    }


    /**
     * Установка формы в состояние только для чтения
     * @param bool $is_readonly
     * @return void
     */
    public function setReadonly(bool $is_readonly): void {

        $this->readOnly = $is_readonly;
    }


    /**
     * Установка списка полей которые должны быть в состоянии только для чтения
     * @param array $fields
     * @return void
     */
    public function setReadonlyFields(array $fields): void {

        $this->clearReadonlyFields();
        $this->addReadonlyFields($fields);
    }


    /**
     * Добавление полей которые должны быть в состоянии только для чтения
     * @param array $fields
     * @return void
     */
    public function addReadonlyFields(array $fields): void {

        foreach ($fields as $field) {
            if (is_string($field) && $field) {
                $this->read_only_fields[] = $field;
            }
        }
    }


    /**
     * Очистка списка полей которые должны быть в состоянии только для чтения
     * @return void
     */
    public function clearReadonlyFields(): void {

        $this->read_only_fields = [];
    }


	/**
	 * Add new control to the form
	 * @param string       $name    - field caption
	 * @param string       $type    - type of control (TEXT, LIST, RADIO, CHECKBOX, FILE)
	 * @param string|array $in      - field attributes
	 * @param string       $out     - outside HTML
	 * @param string       $default - value by default
	 * @param string       $req     - is field required
     * @return $this
	 */
	public function addControl($name, $type, $in = "", $out = "", $default = "", $req = false): self {
		global $counter;
		if (empty($this->cell['default'])) {
			$c = new cell($this->main_table_id);
			$c->addControl($name, $type, $in, $out, $default, $req);
			$this->cell['default'] = $c;
		} else {
            $temp = [
                'name'    => $name,
                'type'    => strtolower($type),
                'in'      => $in,
                'out'     => $out,
                'default' => $default,
                'req'     => $req,
            ];
            $this->cell['default']->appendControl($temp);
        }

        return $this;
	}


    /**
     * @param $name
     * @param $collapsed
     * @return $this
     */
	public function addGroup($name, $collapsed = false): self {
		global $counter;
		if (empty($this->cell['default'])) {
			$c = new cell($this->main_table_id);
			$c->addGroup($name, $collapsed);
			$this->cell['default'] = $c;
		} else {
			if ($collapsed) $collapsed = "*";
			if ($this->cell['default']->controls) {
				$this->cell['default']->setGroup(array($counter => $collapsed . $name));
			} else {
				$this->cell['default']->setGroup(array(0 => $collapsed . $name));
			}
		}

        return $this;
	}


    /**
     * @param $value
     * @param $action
     * @return $this
     */
	public function addButton($value, $action = ''): self {
        $this->buttons[$this->main_table_id][] = ['value' => $value, 'action' => $action];

        return $this;
    }


    /**
     * Create button for switch fields, based on values Y/N
     * @param string $field_name - name of field
     * @param string $value      - switch ON or OFF
     * @throws Exception
     */
    public function addButtonSwitch($field_name, $value): self {
		$tpl = new Templater3($this->tpl_control['switch_button']);
		if ($value) {
			$tpl->assign('data-switch="off"', 'data-switch="off" class="hide"');
			$valueInput = 'Y';
		} else {
			$tpl->assign('data-switch="on"', 'data-switch="on" class="hide"');
			$valueInput = 'N';
		}
		$id = $this->main_table_id . $field_name;
		$tpl->assign('{ID}', $id);
		$html  = '<input type="hidden" id="' . $id . 'hid" name="control[' . $field_name . ']" value="' . $valueInput . '"/>';
		$html .= $tpl->render();
		$this->addButtonCustom($html);

        return $this;
	}


    /**
     * @param $html
     * @return $this
     */
	public function addButtonCustom($html = ''): self {
		$this->buttons[$this->main_table_id][] = array('html' => $html);
        return $this;
	}


    /**
     * сохранение значения в служебных полях формы
     * @param $id
     * @param $value
     * @return editTable
     */
	public function setSessFormField($id, $value): self {

        $this->sess_form_custom[$id] = $value;
        return $this;
	}


    /**
     * Установка проверять ли изменения на форме при уходе со страницы
     * @param bool $leave_checking
     * @return editTable
     */
    public function setLeaveChecking(bool $leave_checking): self {

        $this->form_leave_checking = $leave_checking;
        return $this;
    }


    /**
     * Установка таблицы для формы
     * @param string $table
     * @return self
     */
    public function setTable(string $table): self {

        $this->table = $table;
        return $this;
    }


    /**
     * Установка ширины для названий полей
     * @param string|int $width
     * @return self
     */
    public function setWidthLabels(string|int $width): self {

        $this->firstColWidth = is_numeric($width) ? "{$width}px" : $width;
        return $this;
    }


    /**
     * Установка данных записи
     * @param array $record
     * @return self
     */
    public function setData(array $record): self {

        $this->SQL = [ $record ];
        return $this;
    }


    /**
     * @param array $options
     * @return string
     * @throws Zend_Db_Adapter_Exception
     * @throws Zend_Exception
     */
    public function render(array $options = []): string {

	    ob_start();
        $this->showTable($options);
        return (string)ob_get_clean();
	}


    /**
     * @param array $options
     * @throws Zend_Db_Adapter_Exception
     * @throws Zend_Exception
     */
	public function showTable($options = []) {

		if ($this->acl->read_all || $this->acl->read_owner) {
		    $this->HTML .= '<div id="' . $this->main_table_id . '_error" class="error" ' . ($this->error ? 'style="display:block"' : '') . '>' . $this->error . '</div>';

		    if ( ! isset($options['scroll_to_form']) || $options['scroll_to_form']) {
                $this->HTML .= "<script>toAnchor('{$this->main_table_id}_mainform')</script>";
            }

            $this->makeTable();
            $this->HTML = str_replace('[_ACTION_]', $this->action, $this->HTML);

            if ($this->form_leave_checking) {
                $this->HTML .= "<script>edit.changeForm.listen('{$this->resource}')</script>";
            }

            echo $this->HTML;

		} else {
			$this->noAccess();
		}
	}


    /**
     * @throws Zend_Db_Adapter_Exception
     * @throws Zend_Exception
     * @throws Exception
     * @SuppressWarnings(PHPMD:StaticAccess)
     */
	public function makeTable() {
		if (!$this->isSaved) {
			$this->save('save.php');
		}
		$authNamespace = new SessionContainer('Auth');
		if (is_array($this->SQL)) {
			$arr = $this->SQL;
			$current = current($arr);
			if (!is_array($current)) {
				$current = $this->SQL;
			}
			$arr_fields = array_keys($current);
		} else {
            if (is_string($this->SQL)) $this->SQL = trim($this->SQL);
			$arr = $this->db->fetchAll($this->SQL);
		}
		if ($arr && is_array($arr) && is_array($arr[0])) {
			$k = 0;
			foreach ($arr[0] as $data) {
				$arr[0][$k] = $data;
				$k++;
			}

			reset($arr[0]);
			$refid = current($arr[0]);
		} else {
			$refid = 0;
		}



		if (!isset($arr_fields)) {
			$tmp_pos = strripos($this->SQL, "FROM ");
			// - IN CASE WE USE EDIT WITHOUT TABLE
			if ($tmp_pos === false) {
				$table = '';
				$arr_fields = explode("\n", str_replace("SELECT ", "", $this->SQL));
			} else {
				$prepare = substr($this->SQL, 0, $tmp_pos);
				$arr_fields = explode("\n", str_replace("SELECT ", "", $prepare));
				preg_match("/\W*([\w|-]*)\W*/",  substr($this->SQL, $tmp_pos + 4), $temp);
				$table = $temp[1];
			}
			if (empty($this->table)) {
				$this->table = $table;
			}
		}


		foreach ($arr_fields as $key => $value) {
			$value = trim(trim($value), ",");
			if (stripos($value, "AS ") !== false) {
				$arr_fields[$key] = substr($value, strripos($value, "AS ") + 3);
			} else {
				if (!$value) {
					unset($arr_fields[$key]);
					continue;
				}
				$arr_fields[$key] = $value;
			}
		}

		$select 		= 0;
		$modal 			= 0;
		$access_read 	= '';
		$access_edit 	= '';
		$keyfield 		= !empty($arr_fields[0]) ? trim($arr_fields[0], '`') : '';

		// CHECK FOR ACCESS
		if ($this->acl->read_owner) $access_read = 'owner';
		if ($this->acl->read_all) $access_read = 'all';
		if ($this->acl->edit_owner) $access_edit = 'owner';
		if ($this->acl->edit_all) $access_edit = 'all';

		if (!$access_read) {
			$this->noAccess();
			return;
		}
		elseif (!$access_edit) {
			$this->readOnly = true;
		}
		elseif ($refid) {
			if ($this->table) {
				if ($access_edit == 'owner' || $access_read == 'owner') {
					$res = $this->db->fetchRow("SELECT * FROM `$this->table` WHERE `{$keyfield}`=? LIMIT 1", $refid);
					if (!isset($res['author'])) {
                        // Это условие кажется нелогичным.
                        // Если у пользователя есть доступ на чтение = 'all', то почему нельзя показывать форму в виде $this->readOnly = true;
						$this->noAccess();
						return;
					} elseif ($authNamespace->NAME !== $res['author']) {
						$this->readOnly = true;
					}
				}
			}
		}

		if (!$this->readOnly) { //форма доступна для редактирования

			$order_fields = array();

			$onsubmit = "edit.onsubmit(this);";
			if ($this->saveConfirm) {
				$onsubmit .= "if(!confirm('{$this->saveConfirm}')){return false};";
			}

			if (count($this->beforeSaveArr)) {
				foreach ($this->beforeSaveArr as $func) {
					if ($func) {
						$func = explode(";", $func);
						foreach ($func as $k => $fu) {
							if (strpos($fu, 'xajax_') !== false) {
								$funcName = explode('(', $fu);
								$funcName = substr($funcName[0], 6);
                                $action = "index.php?" . $_SERVER['QUERY_STRING'];
                                if ($this->action) $action = $this->action;
								$func[$k] = "xajax.config['requestURI']='$action';xajax_post('$funcName', '', " . substr($fu, strpos($fu, '(', 1) + 1, -1) . ")";
							}
						}
						$onsubmit .= implode(";", $func) . ";return;";
					}
				}
			}
			$onsubmit .= "this.submit();return false;";


			$this->HTML .= "<form id=\"{$this->main_table_id}_mainform\" method=\"POST\" action=\"[_ACTION_]\" enctype=\"multipart/form-data\" onsubmit=\"$onsubmit\">";
			$this->HTML .= "<input type=\"hidden\" name=\"class_id\" value=\"{$this->uniq_class_id}\"/>";
			$this->HTML .= "<input type=\"hidden\" name=\"class_refid\" value=\"{$refid}\"/>";
			$order_fields['resId']       = $this->resource;
			$order_fields['mainTableId'] = $this->main_table_id;
			$order_fields['back']        = $this->back;
			$order_fields['refid']       = $refid;
			$order_fields['table']       = $this->table;
			$order_fields['keyField']    = $keyfield;

			$this->setSessForm($order_fields);

			if ($refid && $this->table) {
                $this->formControl($keyfield, $refid);
			}

			if (isset($this->params[$this->main_table_id]) && count($this->params[$this->main_table_id])) {
				foreach ($this->params[$this->main_table_id] as $key => $value) {
					$this->HTML .= "<input type=\"hidden\" name=\"{$value["va"]}\" id=\"{$this->main_table_id}_add_" . str_replace(array('[', ']'), '_', $value["va"]) . "\" value=\"{$value["value"]}\"/>";
				}
			}
		}
		$PrepareSave 	= "";
		$onload 		= "";

		$controlGroups	= array();

		if (!empty($this->cell)) {
			foreach ($this->cell as $cellId => $cellFields) {

				$controls = $cellFields->controls[$this->main_table_id];
				if (!empty($controls)) {
					foreach ($controls as $key => $value) {
						$controlGroups[$cellId]['html'][$key] = '';
						if (!empty($value['group'])) {
                            $temp              = [];
                            $temp['key']       = $key;
                            $temp['collapsed'] = false;
                            $temp['name']      = $value['group'];

                            if (substr($value['group'], 0, 1) == "*") {
                                $temp['collapsed'] = true;
                                $temp['name']      = trim($value['group'], '*');
                            }

                            $controlGroups[$cellId]['group'][] = $temp;
                        }

                        //преобразование массива с атрибутами в строку
						$attrs = $this->setAttr($value['in']);

						$sqlKey = $key + 1;
						if (!isset($arr_fields[$sqlKey])) {
							$arr_fields[$sqlKey] = '';
						}

						//Получение идентификатора текущего поля
						$field = trim(str_replace(array("'", "`"), "", $arr_fields[$sqlKey]));
						if (strtolower($field) == 'null') {
							$field = "field" . $sqlKey;
						}
						$fieldId = $this->main_table_id . $field;

						//обработка значение по умолчанию
						if ($value['default']) {
							if (!is_array($value['default'])) {
								$value['default'] = htmlspecialchars($value['default']);
							}
						}

						//присвоение значения из запроса, если запрос вернул результат
						if (isset($arr[0]) &&
                            isset($arr[0][0]) &&
                            isset($arr[0][$sqlKey]) &&
                            is_scalar($arr[0][$sqlKey])
                        ) {
							$value['default'] = htmlspecialchars($arr[0][$sqlKey]);
						}

						//если тип hidden то формируется только hidden поле формы
						if ($value['type'] == 'hidden') {
							$controlGroups[$cellId]['html'][$key] .= "<input id=\"{$fieldId}\" type=\"hidden\" name=\"control[$field]\" value=\"{$value['default']}\" />";
							continue;
						}

						//определяем, надо ли скрывать контрол
						$hide = '';
						if (strpos($value['type'], "_hidden") !== false) {
							$value['type'] = str_replace("_hidden", "", $value['type']);
							$hide = ' hide';
						}

						// загружать ли файл автоматически
                        $auto = false;
						if (strpos($value['type'], "_auto") !== false) {
                            $auto = true;
							$value['type'] = str_replace("_auto", "", $value['type']);
						}

						$value['type'] = str_replace("_default", "", $value['type']); //FIXME WTF

                        $id    = $field ? " id=\"{$this->resource}_container_$field\"" : "";
                        $width = ($this->firstColWidth ? "style=\"width:{$this->firstColWidth};min-width:{$this->firstColWidth};\"" : "");

						$controlGroups[$cellId]['html'][$key] .= "<table class=\"editTable{$hide}\"{$id}><tr style=\"vertical-align: top\"><td class=\"eFirstCell\" {$width}>";

                        if ($value['req']) {
							$controlGroups[$cellId]['html'][$key] .= "<span class=\"requiredStar\">*</span>";
						}

                        if ( ! empty($value['in']) && is_array($value['in']) && ! empty($value['in']['description'])) {
							$controlGroups[$cellId]['html'][$key] .= " <i class=\"fa fa-info-circle text-muted\" title=\"{$value['in']['description']}\"></i> ";
						}

						$controlGroups[$cellId]['html'][$key] .= $value['name'] . "</td><td" . ($field ? " id=\"{$this->resource}_cell_$field\"" : "") . ">";

						if ($value['type'] == 'protect' || $value['type'] == self::TYPE_PROTECTED) { //только для чтения
                            $controlGroups[$cellId]['html'][$key] .= "<span id=\"$fieldId\" {$attrs}>" . $value['default'] . "</span>";
						}
						elseif ($value['type'] == self::TYPE_CUSTOM) { // произвольный html
							$controlGroups[$cellId]['html'][$key] .= $attrs;
						}
						elseif ($value['type'] == 'text' || $value['type'] == 'edit') { // простое поле
							if ($this->readOnly || in_array($field, $this->read_only_fields)) {
								$controlGroups[$cellId]['html'][$key] .= $value['default'];
							} else {
								$controlGroups[$cellId]['html'][$key] .= "<input class=\"input\" id=\"$fieldId\" type=\"text\" name=\"control[$field]\" {$attrs} value=\"{$value['default']}\">";
							}
						}
						elseif ($value['type'] == self::TYPE_TIME) {
							if ($this->readOnly || in_array($field, $this->read_only_fields)) {
								$controlGroups[$cellId]['html'][$key] .= $value['default'];
							} else {
								$controlGroups[$cellId]['html'][$key] .= "<input class=\"input\" id=\"$fieldId\" type=\"time\" name=\"control[$field]\" {$attrs} value=\"{$value['default']}\">";
							}
						}
						elseif ($value['type'] == 'datetime_local') {
							if ($this->readOnly || in_array($field, $this->read_only_fields)) {
								$controlGroups[$cellId]['html'][$key] .= $value['default'];
							} else {
								$controlGroups[$cellId]['html'][$key] .= "<input class=\"input\" id=\"$fieldId\" type=\"datetime-local\" name=\"control[$field]\" {$attrs} value=\"{$value['default']}\">";
							}
						}
						elseif ($value['type'] == 'date_week') {
							if ($this->readOnly || in_array($field, $this->read_only_fields)) {
								$controlGroups[$cellId]['html'][$key] .= $value['default'] ? date('Y.m неделя W', strtotime($value['default'])) : '';
							} else {
                                $input_year  = $value['default'] ? date('Y', strtotime($value['default'])) : '';
                                $input_week  = $value['default'] ? date('W', strtotime($value['default'])) : '';
                                $input_value = $input_year ? "{$input_year}-W{$input_week}" : '';

								$controlGroups[$cellId]['html'][$key] .= "<input class=\"input\" id=\"$fieldId\" type=\"week\" name=\"control[$field]\" {$attrs} value=\"{$input_value}\">";
							}
						}
						elseif ($value['type'] == 'date_month') {
							if ($this->readOnly || in_array($field, $this->read_only_fields)) {
								$controlGroups[$cellId]['html'][$key] .= $value['default'] ? date('Y.m', strtotime($value['default'])) : '';
							} else {
                                $input_value = $value['default'] ? date('Y-m', strtotime($value['default'])) : '';
								$controlGroups[$cellId]['html'][$key] .= "<input class=\"input\" id=\"$fieldId\" type=\"month\" name=\"control[$field]\" {$attrs} value=\"{$input_value}\">";
							}
						}
						elseif ($value['type'] == 'number') { // только цифры
							if ($this->readOnly || in_array($field, $this->read_only_fields)) {
								$controlGroups[$cellId]['html'][$key] .= $value['default'];
							} else {
                                $controlGroups[$cellId]['html'][$key] .= "<input class=\"input\" id=\"$fieldId\" type=\"text\" name=\"control[$field]\" {$attrs} value=\"{$value['default']}\" onkeypress=\"return checkInt(event);\" onpaste=\"return commaReplace(event);\" >";
							}
						}
						elseif ($value['type'] == 'number_range') { // только цифры
							if ($this->readOnly || in_array($field, $this->read_only_fields)) {
								$controlGroups[$cellId]['html'][$key] .= $value['default'];
							} else {
								$controlGroups[$cellId]['html'][$key] .= "<input class=\"input\" id=\"{$fieldId}-start\" type=\"text\" name=\"control[$field][0]\" {$attrs} value=\"{$value['default']}\" onkeypress=\"return checkInt(event);\" placeholder=\"от\"> - ";
								$controlGroups[$cellId]['html'][$key] .= "<input class=\"input\" id=\"{$fieldId}-end\" type=\"text\" name=\"control[$field][1]\" {$attrs} value=\"\" onkeypress=\"return checkInt(event);\" placeholder=\"до\">";
							}
						}
						elseif ($value['type'] == 'money') {
							if ($this->readOnly || in_array($field, $this->read_only_fields)) {
								$controlGroups[$cellId]['html'][$key] .= Tool::commafy($value['default']);
							} else {
                                if (empty($value['default'])) $value['default'] = 0;
								$options = ! empty($value['in']) && ! empty($value['in']['options']) && is_array($value['in']['options'])
                                    ? $value['in']['options']
                                    : array();
                                $options_encoded = json_encode($options);
								$controlGroups[$cellId]['html'][$key] .= "<input class=\"input\" id=\"$fieldId\" type=\"text\" name=\"control[$field]\" {$attrs} value=\"{$value['default']}\">";
                                $controlGroups[$cellId]['html'][$key] .= "<script>edit.maskMe('{$fieldId}', {$options_encoded});</script>";
							}
						}
						elseif ($value['type'] == 'file') {
							$controlGroups[$cellId]['html'][$key] .= "<input class=\"input\" id=\"$fieldId\" type=\"file\" name=\"control[$field]\" {$attrs}>";
						}
						elseif ($value['type'] == 'link') { // простая ссылка
							$controlGroups[$cellId]['html'][$key] .= "<span id=\"$fieldId\" {$attrs}><a href=\"{$value['default']}\">{$value['default']}</a></span>";
						}
						elseif ($value['type'] == 'search') { //TODO поле с быстрым поиском
							$controlGroups[$cellId]['html'][$key] .= "<input id=\"{$fieldId}\" type=\"hidden\" name=\"control[{$field}]\" value=\"{$value['default']}\"/>";
						}
						elseif ($value['type'] == 'date' || $value['type'] == 'datetime') {
							if ($this->readOnly || in_array($field, $this->read_only_fields)) {
								$day	= substr($value['default'], 8, 2);
								$month 	= substr($value['default'], 5, 2);
								$year 	= substr($value['default'], 0, 4);
								$insert = str_replace("dd", $day, strtolower($this->date_mask));
								$insert = str_replace("mm", $month, $insert);
								$insert = str_replace("yyyy", $year, $insert);
								$insert = str_replace("yy", $year, $insert);
								if ($value['type'] == 'datetime') {
									$h = substr($value['default'], 11, 2);
									$mi = substr($value['default'], 14, 2);
									$insert .= " $h:$mi";
								}
								$controlGroups[$cellId]['html'][$key] .= $insert;
							} else {
								$prefix = $fieldId;
								$beh = 'onblur="edit.dateBlur(\'' . $prefix . '\')" onkeypress="return checkInt(event);" onkeyup="edit.dateKeyup(\'' . $prefix . '\', this)"';
								$day	= '<input class="input" type="text" size="1" maxlength="2" autocomplete="OFF" id="' . $prefix . '_day" value="' . substr($value['default'], 8, 2) . '" ' . $beh . '/>';
								$month 	= '<input class="input" type="text" size="1" maxlength="2" autocomplete="OFF" id="' . $prefix . '_month" value="' . substr($value['default'], 5, 2) . '" ' . $beh . '/>';
								$year 	= '<input class="input" type="text" size="3" maxlength="4" autocomplete="OFF" id="' . $prefix . '_year" value="' . substr($value['default'], 0, 4) . '" ' . $beh . '/>';
								$insert = str_replace(array("dd", "mm", "yyyy"), array($day, $month, $year), strtolower($this->date_mask));
								$insert = str_replace("yy", $year, $insert);

								$tpl = new Templater3($this->tpl_control['datetime']);
								$tpl->assign('[dt]', $insert);
								$tpl->assign('[prefix]', $prefix);
								$tpl->assign('name=""', 'name="control[' . $field . ']"');
								$tpl->assign('value=""', 'value="' . $value['default'] . '"');
								if ($value['type'] == 'datetime') {
									$h = substr($value['default'], 11, 2);
									$mi = substr($value['default'], 14, 2);
									$beh = 'onblur="edit.dateBlur(\'' . $prefix . '\')" onkeypress="return checkInt(event);" onkeyup="edit.timeKeyup(\'' . $prefix . '\', this)"';
									$tpl->datetime->assign('[h]', $h);
									$tpl->datetime->assign('[i]', $mi);
									$tpl->datetime->assign('onblur=""', $beh);
								}
								$controlGroups[$cellId]['html'][$key] .= $tpl->render();
								if ($value['in']) {
									$controlGroups[$cellId]['html'][$key] .= "<script>edit.ev['{$prefix}'] = " . json_encode($value['in']) . ";</script>";
								} else {
									$controlGroups[$cellId]['html'][$key] .= "<script>delete edit.ev['{$prefix}'];</script>";
								}
								if ($value['type'] == 'date') {
									$controlGroups[$cellId]['html'][$key] .= "<script>edit.create_date('$prefix');</script>";
								} else {
									$controlGroups[$cellId]['html'][$key] .= "<script>edit.create_datetime('$prefix');</script>";
								}

							}
						}
						elseif ($value['type'] == 'color') {
                            if ($this->readOnly || in_array($field, $this->read_only_fields)) {
                                $controlGroups[$cellId]['html'][$key] .= $value['default'];

                            } else {
                                $this->scripts['color'] = true;

                                $tpl = file_get_contents($this->tpl_control['color']);
                                $tpl = str_replace('[FIELD_ID]',   $fieldId, $tpl);
                                $tpl = str_replace('[FIELD]',      $field, $tpl);
                                $tpl = str_replace('[VALUE]',      $value['default'], $tpl);
                                $tpl = str_replace('[ATTRIBUTES]', $value['in'], $tpl);

                                $controlGroups[$cellId]['html'][$key] .= $tpl;
                            }
                        }
						elseif ($value['type'] == self::TYPE_COORDINATES) {
                            if ($this->readOnly || in_array($field, $this->read_only_fields)) {
                                $controlGroups[$cellId]['html'][$key] .= $value['default'];

                            } else {
                                $this->scripts[self::TYPE_COORDINATES] = true;

                                $settings = is_array($value['in']) ? $value['in'] : [];

                                $tpl = file_get_contents($this->tpl_control[self::TYPE_COORDINATES]);
                                $tpl = str_replace('[FIELD_ID]',         $fieldId, $tpl);
                                $tpl = str_replace('[FIELD]',            $field, $tpl);
                                $tpl = str_replace('[VALUE]',            $value['default'], $tpl);
                                $tpl = str_replace('[ATTRIBUTES]',       $settings['attr'] ?? '', $tpl);
                                $tpl = str_replace('[APIKEY]',           $settings['apikey'] ?? '', $tpl);
                                $tpl = str_replace('[WIDTH]',            $settings['width'] ?? 400, $tpl);
                                $tpl = str_replace('[HEIGHT]',           $settings['height'] ?? 200, $tpl);
                                $tpl = str_replace('[ZOOM]',             $settings['zoom'] ?? 7, $tpl);
                                $tpl = str_replace('[INPUT_ADDRESS_ID]', $settings['input_address_id'] ?? '', $tpl);
                                $tpl = str_replace('[CENTER_LAT]',       ! empty($settings['center']) && ! empty($settings['center']['lat']) ? $settings['center']['lat'] : '53.908045', $tpl);
                                $tpl = str_replace('[CENTER_LNG]',       ! empty($settings['center']) && ! empty($settings['center']['lng']) ? $settings['center']['lng'] : '27.507411', $tpl);

                                $controlGroups[$cellId]['html'][$key] .= $tpl;
                            }
                        }
						elseif ($value['type'] == self::TYPE_SWITCH) {
                            if ($this->readOnly || in_array($field, $this->read_only_fields)) {
                                $controlGroups[$cellId]['html'][$key] .= $value['default'] == 'Y' ? $this->_('да') : $this->_('нет');

                            } else {
                                $color   = ! empty($value['in']['color']) ? "color-{$value['in']['color']}" : 'color-primary';
                                $value_y = isset($value['in']['value_Y']) ? $value['in']['value_Y'] : 'Y';
                                $value_n = isset($value['in']['value_N']) ? $value['in']['value_N'] : 'N';

                                $value['default'] = ! empty($value['default']) ? $value['default'] : $value_n;

                                $tpl = file_get_contents($this->tpl_control['switch']);
                                $tpl = str_replace('[FIELD_ID]',  $fieldId, $tpl);
                                $tpl = str_replace('[FIELD]',     $field, $tpl);
                                $tpl = str_replace('[CHECKED_Y]', $value['default'] == $value_y ? 'checked="checked"' : '', $tpl);
                                $tpl = str_replace('[CHECKED_N]', $value['default'] == $value_n ? 'checked="checked"' : '', $tpl);
                                $tpl = str_replace('[COLOR]',     $color, $tpl);
                                $tpl = str_replace('[VALUE_Y]',   $value_y, $tpl);
                                $tpl = str_replace('[VALUE_N]',   $value_n, $tpl);

                                $controlGroups[$cellId]['html'][$key] .= $tpl;
                            }
                        }
						elseif ($value['type'] == 'combobox') {
                            if ($this->readOnly || in_array($field, $this->read_only_fields)) {
                                $controlGroups[$cellId]['html'][$key] .= $value['default'];

                            } else {

                                $tpl = new Templater3($this->tpl_control['combobox']);
                                $tpl->assign('[FIELD_ID]',   $fieldId);
                                $tpl->assign('[FIELD]',      $field);
                                $tpl->assign('[VALUE]',      $value['default']);
                                $tpl->assign('[ATTRIBUTES]', $value['in']);

                                if (is_array($this->selectSQL[$select]) && $this->selectSQL[$select]) {
                                    foreach ($this->selectSQL[$select] as $combobox_value) {
                                        $tpl->items->assign('[TITLE]', $combobox_value);
                                        $tpl->items->reassign();
                                    }
                                }


                                $controlGroups[$cellId]['html'][$key] .= $tpl->render();
                            }
                            $select++;
                        }
						elseif ($value['type'] == 'date2') {
                            if ($this->readOnly || in_array($field, $this->read_only_fields)) {
								if ($value['default']) {
                                    $day	= substr($value['default'], 8, 2);
                                    $month 	= substr($value['default'], 5, 2);
                                    $year 	= substr($value['default'], 0, 4);
                                    $insert = str_replace("dd", $day, strtolower($this->date_mask));
                                    $insert = str_replace("mm", $month, $insert);
                                    $insert = str_replace("yyyy", $year, $insert);
                                    $insert = str_replace("yy", $year, $insert);
                                    $controlGroups[$cellId]['html'][$key] .= $insert;
                                } else {
                                    $controlGroups[$cellId]['html'][$key] .= '';
                                }
                            } else {
                                $this->scripts['date2'] = true;
								$options = is_array($value['in']) ? json_encode($value['in']) : '{}';
                                $tpl = file_get_contents($this->tpl_control['date2']);
                                $tpl = str_replace('[THEME_DIR]', self::THEME_HTML,          $tpl);
                                $tpl = str_replace('[NAME]',      'control[' . $field . ']', $tpl);
                                $tpl = str_replace('[DATE]',      $value['default'],         $tpl);
                                $tpl = str_replace('[OPTIONS]',   $options,                  $tpl);
                                $tpl = str_replace('[KEY]',       crc32(uniqid('', true)),   $tpl);
                                $controlGroups[$cellId]['html'][$key] .= $tpl;
                            }
                        }
						elseif ($value['type'] == 'datetime2') {
                            if ($this->readOnly || in_array($field, $this->read_only_fields)) {
                                if ($value['default']) {
                                    $day    = substr($value['default'], 8, 2);
                                    $month  = substr($value['default'], 5, 2);
                                    $year   = substr($value['default'], 0, 4);
                                    $insert = str_replace("dd", $day, strtolower($this->date_mask));
                                    $insert = str_replace("mm", $month, $insert);
                                    $insert = str_replace("yyyy", $year, $insert);
                                    $insert = str_replace("yy", $year, $insert);
                                    $h      = substr($value['default'], 11, 2);
                                    $mi     = substr($value['default'], 14, 2);
                                    $insert .= " $h:$mi";
                                    $controlGroups[$cellId]['html'][$key] .= $insert;
                                } else {
                                    $controlGroups[$cellId]['html'][$key] .= '';
                                }
                            } else {
                                $this->scripts['datetime2'] = true;
                                $tpl = file_get_contents($this->tpl_control['datetime2']);
                                $tpl = str_replace('[THEME_DIR]', self::THEME_HTML,          $tpl);
                                $tpl = str_replace('[NAME]',      'control[' . $field . ']', $tpl);
                                $tpl = str_replace('[DATE]',      $value['default'],         $tpl);
                                $tpl = str_replace('[KEY]',       crc32(uniqid('', true)),   $tpl);
                                $controlGroups[$cellId]['html'][$key] .= $tpl;
                            }
                        }
						elseif ($value['type'] == 'modal2') {
                            if ($this->readOnly || in_array($field, $this->read_only_fields)) {
                                $controlGroups[$cellId]['html'][$key] .= isset($value['in']['text'])
                                    ? htmlspecialchars($value['in']['text'])
                                    : '';
                            } else {
                                $this->scripts['modal2'] = true;

                                $options               = [];
                                $options['size']       = $value['in']['size'] ?? '';
                                $options['title']      = $value['in']['title'] ?? '';
                                $options['text']       = isset($value['in']['text']) ? htmlspecialchars($value['in']['text']) : '';
                                $options['value']      = $value['in']['value'] ?? $value['default'];
                                $options['attributes'] = $value['in']['attributes'] ?? '';
                                $options['url']        = $value['in']['url'] ?? '';
                                $options['onHidden']   = $value['in']['onHidden'] ?? '';
                                $options['onClear']    = $value['in']['onClear'] ?? '';
                                $options['onChoose']   = $value['in']['onChoose'] ?? '';

                                $options['autocomplete_url']        = $value['in']['autocomplete_url'] ?? '';
                                $options['autocomplete_min_length'] = $value['in']['autocomplete_min_length'] ?? '';

                                switch ($options['size']) {
                                    case 'xl':     $size = 'modal-xl'; break;
                                    case 'small':  $size = 'modal-sm'; break;
                                    case 'normal': $size = ''; break;
                                    case 'large':
                                    default: $size = 'modal-lg'; break;
                                }

                                $url = strpos(trim($options['url']), 'function') !== false
                                    ? $options['url']
                                    : "'{$options['url']}'";

                                $tpl = new Templater3($this->tpl_control['modal2']);
                                $tpl->assign('[THEME_DIR]', self::THEME_HTML);
                                $tpl->assign('[TITLE]',     $options['title']);
                                $tpl->assign('[TEXT]',      $options['text']);
                                $tpl->assign('[VALUE]',     $options['value']);
                                $tpl->assign('[URL]',       $url);
                                $tpl->assign('[NAME]',      'control[' . $field . ']');
                                $tpl->assign('[SIZE]',      $size);
                                $tpl->assign('[ATTR]',      $options['attributes']);
                                $tpl->assign('[KEY]',       crc32(uniqid() . microtime(true)));
                                $tpl->assign('[AUTOCOMPLETE_URL]',        $options['autocomplete_url']);
                                $tpl->assign('[AUTOCOMPLETE_MIN_LENGTH]', $options['autocomplete_min_length']);

                                $on_hidden = ! empty($options['onHidden']) && strpos(trim($options['onHidden']), 'function') !== false
                                    ? trim($options['onHidden'])
                                    : "''";
                                $tpl->assign('[ON_HIDDEN]', $on_hidden);


                                $on_clear = ! empty($options['onClear']) && strpos(trim($options['onClear']), 'function') !== false
                                    ? trim($options['onClear'])
                                    : "''";
                                $tpl->assign('[ON_CLEAR]', $on_clear);


                                $on_choose = ! empty($options['onChoose']) && strpos(trim($options['onChoose']), 'function') !== false
                                    ? trim($options['onChoose'])
                                    : "''";
                                $tpl->assign('[ON_CHOOSE]', $on_choose);

                                $controlGroups[$cellId]['html'][$key] .= $tpl->render();
                            }
                        }
						elseif ($value['type'] == 'modal_list') {
                            if ($this->readOnly || in_array($field, $this->read_only_fields)) {
                                $tpl = new Templater3($this->tpl_control['modal']);
                                $tpl->assign('[CONTROL]', $field);

                                if ( ! empty($value['in']['list'])) {
                                    foreach ($value['in']['list'] as $item) {
                                        $tpl->item->assign('[TEXT]', $item['text'] ?? '-');
                                        $tpl->item->assign('[KEY]',  crc32(uniqid() . microtime(true)));


                                        if ( ! empty($value['in']['fields'])) {
                                            foreach ($value['in']['fields'] as $list_field) {
                                                $name = $list_field['name'] ?? '';

                                                $tpl->item->readonly_field->assign('[TYPE]',  $list_field['type'] ?? 'text');
                                                $tpl->item->readonly_field->assign('[TITLE]', $list_field['title'] ?? '');
                                                $tpl->item->readonly_field->assign('[NAME]',  $name);
                                                $tpl->item->readonly_field->assign('[VALUE]', $item[$name] ?? '');
                                                $tpl->item->readonly_field->reassign();
                                            }
                                        }

                                        $tpl->item->reassign();
                                    }
                                }

                                $controlGroups[$cellId]['html'][$key] .= $tpl->render();

                            } else {
                                $this->scripts['modal_list'] = true;

                                $options             = [];
                                $options['size']     = isset($value['in']['size']) ? $value['in']['size'] : '';
                                $options['title']    = isset($value['in']['title']) ? $value['in']['title'] : '';
                                $options['value']    = isset($value['in']['value']) ? $value['in']['value'] : $value['default'];
                                $options['url']      = isset($value['in']['url']) ? $value['in']['url'] : '';
                                $options['onAdd']    = isset($value['in']['onAdd']) ? $value['in']['onAdd'] : '';
                                $options['onHidden'] = isset($value['in']['onHidden']) ? $value['in']['onHidden'] : '';
                                $options['onDelete'] = isset($value['in']['onDelete']) ? $value['in']['onDelete'] : '';
                                $options['fields']   = isset($value['in']['fields']) ? $value['in']['fields'] : [];

                                switch ($options['size']) {
                                    case 'xl':     $size = 'modal-xl'; break;
                                    case 'small':  $size = 'modal-sm'; break;
                                    case 'normal': $size = ''; break;
                                    case 'large':
                                    default: $size = 'modal-lg'; break;
                                }

                                $url = strpos(trim($options['url']), 'function') !== false
                                    ? $options['url']
                                    : "'{$options['url']}'";

                                $tpl = new Templater3($this->tpl_control['modal']);
                                $tpl->assign('[THEME_DIR]', self::THEME_HTML);
                                $tpl->assign('[TITLE]',     $options['title']);
                                $tpl->assign('[VALUE]',     $options['value']);
                                $tpl->assign('[URL]',       $url);
                                $tpl->assign('[NAME]',      'control[' . $field . ']');
                                $tpl->assign('[SIZE]',      $size);
                                $tpl->assign('[CONTROL]',   $field);


                                if ( ! empty($value['in']['list'])) {
                                    foreach ($value['in']['list'] as $item) {
                                        $tpl->item->assign('[TEXT]', $item['text'] ?? '-');
                                        $tpl->item->assign('[KEY]',  crc32(uniqid() . microtime(true)));

                                        $tpl->item->edit_item->assign('[ID]', $item['id'] ?? '');

                                        if ( ! empty($value['in']['fields'])) {
                                            foreach ($value['in']['fields'] as $list_field) {
                                                $name = $list_field['name'] ?? '';

                                                $tpl->item->extra_field->assign('[TYPE]',  $list_field['type'] ?? 'text');
                                                $tpl->item->extra_field->assign('[TITLE]', $list_field['title'] ?? '');
                                                $tpl->item->extra_field->assign('[NAME]',  $name);
                                                $tpl->item->extra_field->assign('[VALUE]', $item[$name] ?? '');
                                                $tpl->item->extra_field->reassign();
                                            }
                                        }
                                        $tpl->item->reassign();
                                    }
                                }

                                $fields = ! empty($options['fields']) && is_array($options['fields'])
                                    ? $options['fields']
                                    : [];
                                $tpl->add_items->assign('[FIELDS]', json_encode($fields, JSON_UNESCAPED_UNICODE));

                                $on_hidden = ! empty($options['onHidden']) && strpos(trim($options['onHidden']), 'function') !== false
                                    ? trim($options['onHidden'])
                                    : "''";
                                $tpl->add_items->assign('[ON_HIDDEN]', $on_hidden);


                                $on_delete = ! empty($options['onDelete']) && strpos(trim($options['onDelete']), 'function') !== false
                                    ? trim($options['onDelete'])
                                    : "''";
                                $tpl->add_items->assign('[ON_DELETE]', $on_delete);


                                $on_add = ! empty($options['onAdd']) && strpos(trim($options['onAdd']), 'function') !== false
                                    ? trim($options['onAdd'])
                                    : "''";
                                $tpl->add_items->assign('[ON_ADD]', $on_add);

                                $controlGroups[$cellId]['html'][$key] .= $tpl->render();
                            }
                        }
                        elseif ($value['type'] == 'daterange') {
							$dates = explode(" - ", $value['default']);

							if ($this->readOnly || in_array($field, $this->read_only_fields)) {
								$day	= substr($dates[0], 8, 2);
								$month 	= substr($dates[0], 5, 2);
								$year 	= substr($dates[0], 0, 4);
								$insert = str_replace("dd", $day, strtolower($this->date_mask));
								$insert = str_replace("mm", $month, $insert);
								$insert = str_replace("yyyy", $year, $insert);
								$insert = str_replace("yy", $year, $insert);
								$controlGroups[$cellId]['html'][$key] .= $insert;
								$day	= substr($dates[1], 8, 2);
								$month 	= substr($dates[1], 5, 2);
								$year 	= substr($dates[1], 0, 4);
								$insert = str_replace("dd", $day, strtolower($this->date_mask));
								$insert = str_replace("mm", $month, $insert);
								$insert = str_replace("yyyy", $year, $insert);
								$insert = str_replace("yy", $year, $insert);
								$controlGroups[$cellId]['html'][$key] .= ' - ' . $insert;
							} else {
								$prefix = $fieldId;
								for ($i = 0; $i <= 1; $i++) {
									$beh = 'onblur="dateBlur(\'' . $prefix . '\')" onkeypress="return checkInt(event);" onkeyup="dateKeyup(\'' . $prefix . '\', this)"';
									$controlGroups[$cellId]['html'][$key] .= "<div style=\"float:left\"><table><tr>";
									$day	= '<input class="input" type="text" size="1" maxlength="2" autocomplete="OFF" id="' . $prefix . '_day" value="' . substr($dates[$i], 8, 2) . '" ' . $beh . '/>';
									$month 	= '<input class="input" type="text" size="1" maxlength="2" autocomplete="OFF" id="' . $prefix . '_month" value="' . substr($dates[$i], 5, 2) . '" ' . $beh . '/>';
									$year 	= '<input class="input" type="text" size="3" maxlength="4" autocomplete="OFF" id="' . $prefix . '_year" value="' . substr($dates[$i], 0, 4) . '" ' . $beh . '/>';
									$insert = str_replace("dd", $day, strtolower($this->date_mask));
									$insert = str_replace("mm", $month, $insert);
									$insert = str_replace("yyyy", $year, $insert);
									$insert = str_replace("yy", $year, $insert);

									$tpl = new Templater3($this->tpl_control['datetime']);
									$tpl->assign('[dt]', $insert);
									$tpl->assign('[prefix]', $prefix);
									$tpl->assign('name=""', 'name="control[' . $field . ']"');
									$tpl->assign('value=""', 'value="' . $dates[$i] . '"');

									$controlGroups[$cellId]['html'][$key] .= "<td style=\"padding:0\">{$tpl->render()}</td>";
									$controlGroups[$cellId]['html'][$key] .= "</tr></table><script>edit.create_date('$prefix');</script></div>";
									if ($i == 0) {
										$prefix .= '_tru';
										$field .= '%tru';
										$controlGroups[$cellId]['html'][$key] .= '<div style="float:left;width:20px;"> <> </div>';
									}
								}
							}
						}
						elseif ($value['type'] == 'password') {
							if ($this->readOnly || in_array($field, $this->read_only_fields)) {
								$controlGroups[$cellId]['html'][$key] .= "*****";
							} else {
								if ($value['default']) {
									$disabled     = ' disabled="disabled" ';
									$change       = '<input class="buttonSmall" type="button" onclick="edit.changePass(\'' . $fieldId . '\')" value="' . $this->translate->tr('изменить') . '"/>';
                                    $change_class = '';
								} else {
									$disabled     = '';
									$change       = '';
									$change_class = 'no-change';
								}

								$controlGroups[$cellId]['html'][$key] .= "<div class=\"password-control {$change_class}\">";
								$controlGroups[$cellId]['html'][$key] .= "<input $disabled class=\"input pass-1\" id=\"" . $fieldId . "\" type=\"password\" name=\"control[$field]\" " . $attrs . " value=\"{$value['default']}\"/>";
								$controlGroups[$cellId]['html'][$key] .= " <span class=\"password-repeat\">" . $this->translate->tr('повторите') . "</span> ";
								$controlGroups[$cellId]['html'][$key] .= "<div class=\"pass-2-container\"><input $disabled class=\"input pass-2\" id=\"" . $fieldId . "2\" type=\"password\" name=\"control[$field%re]\" />{$change}</div>";
								$controlGroups[$cellId]['html'][$key] .= "</div>";
							}
						}
						elseif ($value['type'] == 'textarea') {
							if ($this->readOnly || in_array($field, $this->read_only_fields)) {
								$controlGroups[$cellId]['html'][$key] .= $value['default'] ? "<div>" . nl2br(htmlspecialchars_decode($value['default'])) . "</div>" : '';
							} else {
								$controlGroups[$cellId]['html'][$key] .= "<textarea id=\"" . $fieldId . "\" name=\"control[$field]\" ".$attrs.">{$value['default']}</textarea>";
							}
						}
						elseif (strpos($value['type'], 'fck') === 0) {
                            if ($this->readOnly || in_array($field, $this->read_only_fields)) {
                                $field_content = htmlspecialchars_decode($value['default']);

                                if ( ! empty($field_content) && strlen($field_content) > 0) {
                                    $controlGroups[$cellId]['html'][$key] .= "<div style=\"border:1px solid silver;width:100%;max-height:700px;overflow:auto;padding: 4px;\">{$field_content}</div>";
                                }

                            } else {
                                $this->scripts['editor'] = 'fck';
                                $params = explode("_", $value['type']);

                                if (in_array("basic", $params)) {
                                    $this->MCEConf['menubar'] = "file edit insert view format table tools";
                                    $this->MCEConf['toolbar'] = "insertfile undo redo | styleselect | bold italic | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | link image | print preview media fullpage | forecolor backcolor";
                                } elseif (in_array("basic2", $params)) {
                                    $this->MCEConf['menubar'] = "file edit insert view format table tools";
                                    $this->MCEConf['toolbar'] = "insertfile undo redo | styleselect | bold italic | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | link image";
                                } elseif (in_array("simple", $params)) {
                                    $this->MCEConf['menubar'] = "table";
                                    $this->MCEConf['toolbar'] = "alignleft aligncenter alignright alignjustify | link image";
                                }

								if (is_array($value['in'])) {
                                    $fck_attrs = isset($value['in']['attrs']) && is_string($value['in']['attrs'])
                                        ? $value['in']['attrs']
                                        : '';
                                    if ( ! empty($value['in']['options']) && is_array($value['in']['options'])) {
                                        $this->MCEConf = array_merge($this->MCEConf, $value['in']['options']);
                                    }
                                } else {
                                    $fck_attrs = $value['in'];
                                }


								//$this->MCEConf['document_base_url'] = "/" . trim(VPATH, "/") . "/";
								$mce_params = json_encode($this->MCEConf);

								$id = "template_content" . $this->main_table_id . $key;
								$controlGroups[$cellId]['html'][$key] .= "<textarea id=\"" . $id . "\" name=\"control[$field]\" ".$fck_attrs.">{$value['default']}</textarea>";
								$onload .= "edit.mceSetup('" . $id . "', $mce_params);";
								$PrepareSave .= "document.getElementById('" . $id . "').value = tinyMCE.get('" . $id . "').getContent();";
							}
						}
						elseif ($value['type'] == 'radio') {
							$temp = array();
							if (is_array($this->selectSQL[$select])) {
								foreach ($this->selectSQL[$select] as $k => $v) {
									$temp[] = array($k, $v);
								}
							} else {
								if (isset($arr[0])) {
									$sql = $this->replaceTCOL($arr[0], $this->selectSQL[$select]);
								} else {
									$sql = $this->selectSQL[$select];
								}
								$data = $this->db->fetchAll($sql);
								foreach ($data as $values) {
									$temp[] = array(current($values), end($values));
								}
							}

							if ($this->readOnly || in_array($field, $this->read_only_fields)) {
								foreach ($temp as $row) {
									if ($row[0] == $value['default']) {
										$controlGroups[$cellId]['html'][$key] .= $row[1];
										break;
									}
								}
							} else {
								foreach ($temp as $row) {
									$id = $this->main_table_id . rand();
									$controlGroups[$cellId]['html'][$key] .= "<label class=\"edit-radio\"><input id=\"$id\" type=\"radio\" value='" . $row[0] . "' name=\"control[$field]\"";
									if ($row[0] == $value['default']) {
										$controlGroups[$cellId]['html'][$key] .= " checked=\"checked\"";
									}
									if (is_array($row[1])) {
										$row[1] = $row[1]['value'];
									}
									$controlGroups[$cellId]['html'][$key] .= " onclick=\"edit.radioClick(this)\" {$attrs} />{$row[1]}</label>&nbsp;&nbsp;";
								}
							}
							$select++;
						}
						elseif ($value['type'] == 'radio2') {
							$temp = array();
							if (is_array($this->selectSQL[$select])) {
								foreach ($this->selectSQL[$select] as $k => $v) {
									$temp[] = array($k, $v);
								}
							} else {
								if (isset($arr[0])) {
									$sql = $this->replaceTCOL($arr[0], $this->selectSQL[$select]);
								} else {
									$sql = $this->selectSQL[$select];
								}
								$data = $this->db->fetchAll($sql);
								foreach ($data as $values) {
									$temp[] = array(current($values), end($values));
								}
							}

							if ($this->readOnly || in_array($field, $this->read_only_fields)) {
								foreach ($temp as $row) {
									if ($row[0] == $value['default']) {
										$controlGroups[$cellId]['html'][$key] .= $row[1];
										break;
									}
								}
							} else {
								foreach ($temp as $row) {
									$id = $this->main_table_id . rand();
									$controlGroups[$cellId]['html'][$key] .= "<div><label class=\"edit-radio2\"><input id=\"$id\" type=\"radio\" value='" . $row[0] . "' name=\"control[$field]\"";
									if ($row[0] == $value['default']) {
										$controlGroups[$cellId]['html'][$key] .= " checked=\"checked\"";
									}
									if (is_array($row[1])) {
										$row[1] = $row[1]['value'];
									}
									$controlGroups[$cellId]['html'][$key] .= " onclick=\"edit.radioClick(this)\" {$attrs} />{$row[1]}</label></div>";
								}
							}
							$select++;
						}
						elseif ($value['type'] == 'checkbox') {
							$temp = array();
							if (is_array($this->selectSQL[$select])) {
								foreach ($this->selectSQL[$select] as $k=>$v) {
									$temp[] = array($k, $v);
								}
							} else {
							    $sql = $this->replaceTCOL(isset($arr[0]) ? $arr[0] : '', $this->selectSQL[$select]);
							    if ($sql) {
                                    $data = $this->db->fetchAll($sql, $this->selectSQL[$select]);
                                    foreach ($data as $values) {
                                        $temp[] = array(current($values), end($values));
                                    }
                                }
							}
							$temp1 = is_array($value['default']) ? $value['default'] : explode(",", $value['default']);
							if ($this->readOnly || in_array($field, $this->read_only_fields)) {
								foreach ($temp as $row) {
									if (in_array($row[0], $temp1)) {
										$controlGroups[$cellId]['html'][$key] .= "<div>{$row[1]}</div>";
									}
								}
							} else {
								foreach ($temp as $row) {
									$controlGroups[$cellId]['html'][$key] .= "<label class=\"edit-checkbox\"><input type=\"checkbox\" value=\"{$row[0]}\" name=\"control[$field][]\"";
									if (in_array($row[0], $temp1)) {
										$controlGroups[$cellId]['html'][$key] .= " checked=\"checked\"";
									}
									$controlGroups[$cellId]['html'][$key] .= " {$attrs}/>";
									if (is_array($row[1])) {
										$row[1] = $row[1]['value'];
									}
									$controlGroups[$cellId]['html'][$key] .= $row[1] . "</label>";
								}
							}
							$select++;
						}
						elseif ($value['type'] == 'checkbox2') {
							$temp = array();
							if (is_array($this->selectSQL[$select])) {
								foreach ($this->selectSQL[$select] as $k=>$v) {
									$temp[] = array($k, $v);
								}
							} else {
								$data = $this->db->fetchAll($this->replaceTCOL($arr[0], $this->selectSQL[$select]));
								foreach ($data as $values) {
									$temp[] = array(current($values), end($values));
								}
							}
							$temp1 = is_array($value['default']) ? $value['default'] : explode(",", $value['default']);
							if ($this->readOnly || in_array($field, $this->read_only_fields)) {
								foreach ($temp as $row) {
									if (in_array($row[0], $temp1)) {
										$controlGroups[$cellId]['html'][$key] .= "<div>{$row[1]}</div>";
									}
								}
							} else {
								foreach ($temp as $row) {
									$controlGroups[$cellId]['html'][$key] .= "<div><label class=\"edit-checkbox2\"><input type=\"checkbox\" value=\"{$row[0]}\" name=\"control[$field][]\"";
									if (in_array($row[0], $temp1)) {
										$controlGroups[$cellId]['html'][$key] .= " checked=\"checked\"";
									}
									$controlGroups[$cellId]['html'][$key] .= " {$attrs}/>";
									if (is_array($row[1])) {
										$row[1] = $row[1]['value'];
									}
									$controlGroups[$cellId]['html'][$key] .= $row[1] . "</label></div>";
								}
							}
							$select++;
						}
						elseif ($value['type'] == 'select' || $value['type'] == 'list' || $value['type'] == 'list_hidden' || $value['type'] == 'multilist') {
                            $temp = [];

                            if (is_array($this->selectSQL[$select])) {
                                foreach ($this->selectSQL[$select] as $k => $v) {
                                    if (is_array($v)) {
                                        $temp[] = array_values($v);
                                    } else {
                                        $temp[] = [$k, $v];
                                    }
                                }
                            } else {
                                if (isset($arr[0])) {
                                    $sql = $this->replaceTCOL($arr[0], $this->selectSQL[$select]);
                                } else {
                                    $sql = $this->selectSQL[$select];
                                }
                                $data = $this->db->fetchAll($sql);
                                foreach ($data as $values) {
                                    $temp[] = array_values($values);
                                }
                            }
                            if ( ! is_array($value['default'])) {
                                $value['default'] = explode(",", (string)$value['default']);
                            }
                            if ($this->readOnly || in_array($field, $this->read_only_fields)) {
                                if ($value['type'] == 'multilist') {
                                    $out_array = [];
                                    foreach ($temp as $row) {
                                        $real_value = explode('"', $row[0]);
                                        $real_value = $real_value[0];
                                        if (in_array($real_value, $value['default'])) {
                                            $out_array[] = $row[1];
                                        }
                                    }

                                    $out = implode(', ', $out_array);

                                } else {
                                    $out = '';
                                    foreach ($temp as $row) {
                                        $real_value = explode('"', $row[0]);
                                        $real_value = $real_value[0];
                                        if (in_array($real_value, $value['default'])) {
                                            $out = $row[1];
                                            break;
                                        }
                                    }
                                }


                                $controlGroups[$cellId]['html'][$key] .= $out;
                            } else {
                                $controlGroups[$cellId]['html'][$key] .= "<select id=\"" . $fieldId . "\" name=\"control[$field]" . ($value['type'] == 'multilist' ? '[]" multiple="multiple"' : '"') . " {$attrs}>";
                                $group                                = "";
                                foreach ($temp as $row) {
                                    if (( ! isset($row[2]) || ! $row[2]) && ! is_array($row[1])) {
                                        $temp2  = explode(":::", $row[1]);
                                        $row[2] = isset($temp2[1]) ? $temp2[1] : '';
                                        $row[1] = $temp2[0];
                                    }
                                    if (isset($row[2]) && $row[2] && $group != $row[2]) {
                                        if ($group) $controlGroups[$cellId]['html'][$key] .= "</optgroup>";
                                        $controlGroups[$cellId]['html'][$key] .= "<optgroup label=\"{$row[2]}\">";
                                        $group                                = $row[2];
                                    }
                                    $selected   = "";
                                    if ($row[0]) {
                                        $real_value = explode('"', $row[0]);
                                        $real_value = $real_value[0];
                                        if (in_array($real_value, $value['default'])) {
                                            $selected = 'selected="selected"';
                                        }
                                    }
                                    if (is_array($row[1])) {
                                        $row[1] = $row[1]['value'];
                                    }
                                    $controlGroups[$cellId]['html'][$key] .= '<option value="' . $row[0] . '" ' . $selected . '>' . $row[1] . '</option>';
                                }
                                if ($group) $controlGroups[$cellId]['html'][$key] .= "</optgroup>";
                                $controlGroups[$cellId]['html'][$key] .= "</select>";
                            }
                            $select++;

                        } elseif ($value['type'] == 'select2') {
                            $options = [];

                            if (is_array($this->selectSQL[$select])) {
                                foreach ($this->selectSQL[$select] as $k => $v) {
                                    if (is_array($v)) {
                                        if ( ! empty($v['title'])) {
                                            $options[$k] = $v;
                                        } else {
                                            $options_group = array_values($v);

                                            if (isset($options_group[2]) && is_scalar($options_group[2])) {
                                                $options[$options_group[2]][$options_group[0]] = $options_group[1];
                                            } else {
                                                foreach ($v as $item) {
                                                    if ( ! empty($item['title'])) {
                                                        $options[$k] = $v;
                                                        break;
                                                    }
                                                }
                                            }
                                        }
                                    } else {
                                        $options[$k] = $v;
                                    }
                                }
                            }

                            if ($this->readOnly || in_array($field, $this->read_only_fields)) {
                                $options_out = '';
                                foreach ($options as $options_key => $options_value) {
                                    if (is_array($options_value)) {
                                        if ( ! empty($options_value['value']) && $options_value['value'] == $value['default']) {
                                            $options_out = $options_value['title'] ?? '';
                                            break;

                                        } elseif (isset($options_value[$value['default']])) {
                                            $options_out = $options_value[$value['default']];
                                            break;
                                        }

                                    } elseif (is_scalar($options_value) && $options_key == $value['default']) {
                                        $options_out = $options_value;
                                        break;
                                    }
                                }

                                $controlGroups[$cellId]['html'][$key] .= $options_out;

                            } else {
                                $this->scripts['select2'] = true;

                                $tpl = new Templater3(Theme::get("html-edit-select2"));
                                $tpl->assign('[FIELD_ID]',   $fieldId);
                                $tpl->assign('[FIELD]',      $field);
                                $tpl->assign('[ATTRIBUTES]', $attrs);

                                $tpl->fillDropDown('[FIELD_ID]', $options, $value['default']);

                                $controlGroups[$cellId]['html'][$key] .= $tpl->render();
                            }
                            $select++;


                        }
						elseif ($value['type'] == 'multiselect2') {
                            $options = [];

                            if (is_array($this->selectSQL[$select])) {
                                foreach ($this->selectSQL[$select] as $k => $v) {
                                    if (is_array($v)) {
                                        if ( ! empty($v['title'])) {
                                            $options[$k] = $v;
                                        } else {
                                            $options_group = array_values($v);

                                            if (isset($options_group[2]) && is_scalar($options_group[2])) {
                                                $options[$options_group[2]][$options_group[0]] = $options_group[1];
                                            } else {
                                                foreach ($v as $item) {
                                                    if ( ! empty($item['title'])) {
                                                        $options[$k] = $v;
                                                        break;
                                                    }
                                                }
                                            }
                                        }
                                    } else {
                                        $options[$k] = $v;
                                    }
                                }
                            }


                            if ( ! is_array($value['default'])) {
                                $value['default'] = explode(",", $value['default']);
                            }

                            if ($this->readOnly || in_array($field, $this->read_only_fields)) {
                                $options_out = [];
                                foreach ($options as $options_key => $options_value) {
                                    if (is_array($options_value)) {
                                        foreach ($options_value as $options_value_id => $options_value_title) {

                                            if ( ! empty($options_value_title['value']) &&
                                                 in_array($options_value_title['value'], $value['default'])
                                            ) {
                                                $options_out[] = $options_value_title['title'] ?? '';

                                            } elseif (in_array($options_value_id, $value['default'])) {
                                                $options_out[] = $options_value_title;
                                            }
                                        }

                                    } elseif (is_scalar($options_value) && in_array($options_key, $value['default'])) {
                                        $options_out[] = $options_value;
                                    }
                                }

                                $controlGroups[$cellId]['html'][$key] .= implode(', ', $options_out);

                            } else {
                                $this->scripts['select2'] = true;

                                $tpl = new Templater3(Theme::get("html-edit-multiselect2"));
                                $tpl->assign('[FIELD_ID]',   $fieldId);
                                $tpl->assign('[FIELD]',      $field);
                                $tpl->assign('[ATTRIBUTES]', $attrs);

                                $tpl->fillDropDown('[FIELD_ID]', $options, $value['default']);

                                $controlGroups[$cellId]['html'][$key] .= $tpl->render();
                            }
                            $select++;

                        } elseif ($value['type'] === 'tags') {
                            $options                 = [];
                            $options['input_length'] = isset($value['in']['input_length']) && is_numeric($value['in']['input_length']) ? $value['in']['input_length'] : 0;
                            $options['separators']   = isset($value['in']['separators']) && is_array($value['in']['separators'])       ? $value['in']['separators'] : null;
                            $options['placeholder']  = isset($value['in']['placeholder']) && is_string($value['in']['placeholder'])    ? $value['in']['placeholder'] : null;
                            $options['attr']         = isset($value['in']['attr']) && is_string($value['in']['attr'])                  ? $value['in']['attr'] : '';

                            if ( ! empty($value['in']['autocomplete']) && ! empty($value['in']['autocomplete']['url'])) {
                                $options['autocomplete'] = [
                                    'url'                => $value['in']['autocomplete']['url'],
                                    'dataType'           => 'json',
                                ];
                            }

                            if ( ! is_array($value['default'])) {
                                $value['default'] = $value['default'] ? explode(",", $value['default']) : [];
                                $value['default'] = array_combine(array_values($value['default']), array_values($value['default']));
                            }

                            $select_options = [];

                            if (is_array($this->selectSQL[$select])) {
                                $row_tags = $value['default'];

                                foreach ($this->selectSQL[$select] as $k => $v) {
                                    if (is_array($v)) {
                                        $options_group = array_values($v);

                                        if (isset($options_group[2]) && is_scalar($options_group[2])) {
                                            $select_options[$options_group[2]][$options_group[1]] = $options_group[1];

                                            if (isset($row_tags[$options_group[1]])) {
                                                unset($row_tags[$options_group[1]]);
                                            }
                                        }
                                    } else {
                                        $select_options[$v] = $v;

                                        if (isset($row_tags[$v])) {
                                            unset($row_tags[$v]);
                                        }
                                    }
                                }

                                if ( ! empty($row_tags)) {
                                    foreach ($row_tags as $row_tag) {
                                        $select_options[$row_tag] = $row_tag;
                                    }
                                }

                            } else {
                                $select_options[] = array_combine($value['default'], $value['default']);
                            }



                            if ($this->readOnly || in_array($field, $this->read_only_fields)) {
                                $controlGroups[$cellId]['html'][$key] .= implode(', ', $value['default']);

                            } else {
                                $this->scripts['tags'] = true;

                                $tpl = new Templater3(Theme::get("html-edit-tags"));
                                $tpl->assign('[FIELD_ID]',     $fieldId);
                                $tpl->assign('[FIELD]',        $field);
                                $tpl->assign('[ATTRIBUTES]',   $options['attr']);
                                $tpl->assign('[SEPARATORS]',   json_encode($options['separators']));
                                $tpl->assign('[PLACEHOLDER]',  json_encode($options['placeholder']));
                                $tpl->assign('[INPUT_LENGTH]', $options['input_length']);
                                $tpl->assign('[AJAX]',         ! empty($options['autocomplete']) ? json_encode($options['autocomplete']) : 'null');

                                $tpl->fillDropDown('[FIELD_ID]', $select_options, $value['default']);

                                $controlGroups[$cellId]['html'][$key] .= $tpl->render();
                            }
                            $select++;

                        }
						elseif ($value['type'] == 'multilist2') {
                            if (is_array($this->selectSQL[$select])) {
                                $options = $this->selectSQL[$select];

                            } else {
                                if (isset($arr[0])) {
                                    $sql = $this->replaceTCOL($arr[0], $this->selectSQL[$select]);
                                } else {
                                    $sql = $this->selectSQL[$select];
                                }
                                $options = $this->db->fetchPairs($sql);
                            }

                            if ( ! is_array($value['default'])) {
                                $value['default'] = explode(",", $value['default']);
                            }

                            if ($this->readOnly || in_array($field, $this->read_only_fields)) {
                                $options_out = [];
                                foreach ($options as $options_key => $options_value) {
                                    if (is_array($options_value)) {
                                        foreach ($options_value as $options_value_id => $options_value_title) {
                                            if (in_array($options_value_id, $value['default'])) {
                                                $options_out[] = $options_value_title;
                                            }
                                        }

                                    } elseif (is_scalar($options_value) && in_array($options_key, $value['default'])) {
                                        $options_out[] = $options_value;
                                    }
                                }

                                $controlGroups[$cellId]['html'][$key] .= implode(', ', $options_out);

                            } else {
                                $this->scripts['multiselect2'] = true;

                                $tpl = new Templater3(Theme::get("html-edit-multilist2"));
                                $tpl->assign('[THEME_PATH]', self::THEME_HTML);
                                $tpl->assign('[FIELD_ID]',   $fieldId);
                                $tpl->assign('[FIELD]',      $field);
                                $tpl->assign('[ATTRIBUTES]', str_replace(['"', "'"], ['!::', '!:'], $attrs));
                                $tpl->assign('[OPTIONS]',    json_encode($options));


                                foreach ($value['default'] as $selected_id) {
                                    $isset_option = false;
                                    foreach ($options as $options_key => $options_value) {
                                        if (is_array($options_value) && isset($options_value[$selected_id])) {
                                            $isset_option = true;
                                            break;

                                        } elseif (is_scalar($options_value) && $options_key == $selected_id) {
                                            $isset_option = true;
                                            break;
                                        }
                                    }

                                    if ( ! $isset_option) {
                                        continue;
                                    }


                                    $tpl->item->fillDropDown('[ID]', $options, $selected_id);

                                    $tpl->item->assign('[ATTRIBUTES]', $attrs);
                                    $tpl->item->assign('[FIELD]',      $field);
                                    $tpl->item->assign('[ID]',         crc32(microtime() . $selected_id));
                                    $tpl->item->reassign();
                                }

                                $controlGroups[$cellId]['html'][$key] .= $tpl->render();
                            }
                            $select++;

                        }
						elseif ($value['type'] == 'multilist3') {
                            $items = $this->selectSQL[$select];

                            if ( ! is_array($value['default'])) {
                                $value['default'] = explode(",", $value['default']);
                            }

                            if ($this->readOnly || in_array($field, $this->read_only_fields)) {
                                $options_out = [];
                                foreach ($items as $options_key => $options_value) {
                                    if (is_array($options_value)) {
                                        foreach ($options_value as $options_value_id => $options_value_title) {
                                            if (in_array($options_value_id, $value['default'])) {
                                                $options_out[] = $options_value_title;
                                            }
                                        }

                                    } elseif (is_scalar($options_value) && in_array($options_key, $value['default'])) {
                                        $options_out[] = $options_value;
                                    }
                                }

                                $controlGroups[$cellId]['html'][$key] .= implode('<br>', $options_out);

                            } else {
                                $tpl = new Templater3(Theme::get("html-edit-multilist3"));
                                $tpl->assign('[THEME_PATH]', self::THEME_HTML);
                                $tpl->assign('[FIELD_ID]',   $fieldId);
                                $tpl->assign('[FIELD]',      $field);
                                $tpl->assign('[ATTRIBUTES]', str_replace(['"', "'"], ['!::', '!:'], $attrs));
                                $tpl->assign('[DATA]',       json_encode($items));


                                $items_selected = [];

                                foreach ($value['default'] as $selected_id) {
                                    foreach ($items as $item_id => $item_title) {
                                        $is_selected = $item_id == $selected_id;
                                        $is_disabled = ! $is_selected && array_search($item_id, $value['default']) !== false;

                                        if ($is_selected) {
                                            $items_selected[] = $item_id;
                                        }

                                        $tpl->items->item->assign('[ITEM_ID]',  $item_id);
                                        $tpl->items->item->assign('[TITLE]',    $item_title);
                                        $tpl->items->item->assign('[DISABLED]', $is_disabled ? 'disabled="disabled"' : '');
                                        $tpl->items->item->assign('[SELECTED]', $is_selected ? 'selected="selected"' : '');
                                        $tpl->items->item->reassign();
                                    }


                                    $tpl->items->assign('[ATTRIBUTES]', $attrs);
                                    $tpl->items->assign('[FIELD]',      $field);
                                    $tpl->items->assign('[ID]',         crc32(microtime() . $selected_id));
                                    $tpl->items->reassign();
                                }

                                $controlGroups[$cellId]['html'][$key] .= $tpl->render();
                            }
                            $select++;

						}
						elseif ($value['type'] == 'dataset') {
                            if (empty($value['in']) ||
                                ( ! is_string($value['default']) && ! is_array($value['default']))
                            ) {
                                throw new Exception('Некорректно заполнены настройки формы');
                            }

                            if (is_array($value['default'])) {
                                $datasets = $value['default'];
                            } else {
                                $json_string = $value['default'] ? html_entity_decode($value['default']) : '';
                                $datasets    = json_decode(preg_replace('/[\x00-\x1F\x80-\xFF]/u', '', $json_string), true);
                            }

                            $is_delete   = ! isset($value['in']['is_delete']) || (bool)$value['in']['is_delete'];
                            $is_add      = ! isset($value['in']['is_add']) || (bool)$value['in']['is_add'];
                            $item_fields = $value['in']['fields'] ?? $value['in'];


                            if ($this->readOnly || in_array($field, $this->read_only_fields)) {
                                if ( ! empty($datasets)) {
                                    $tpl = new Templater3($this->tpl_control['dataset']);

                                    foreach ($item_fields as $item_field) {
                                        $tpl->title->assign('[TITLE]', $item_field['title']);
                                        $tpl->title->reassign();
                                    }

                                    $num = 1;
                                    foreach ($datasets as $dataset) {

                                        foreach ($item_fields as $item_field) {
                                            $field_value = '';

                                            if ( ! empty($dataset) && isset($dataset[$item_field['code']])) {
                                                $field_value = is_string($dataset[$item_field['code']]) || is_numeric($dataset[$item_field['code']])
                                                    ? $dataset[$item_field['code']]
                                                    : '';
                                            }

                                            $type_name = $item_field['type'] ?? 'text';

                                            if ( ! in_array($type_name, ['text', 'textarea', 'select', 'select2', 'date', 'datetime', 'number', 'switch', 'hidden', 'text_readonly'])) {
                                                $type_name = 'text';
                                            }

                                            if ($type_name == 'select') {
                                                $field_value = $item_field['options'][$field_value] ?? $field_value;

                                            } elseif ($type_name == 'select2') {
                                                $field_value = $item_field['options'][$field_value] ?? $field_value;

                                            }  elseif ($type_name == 'date') {
                                                $field_value = $field_value ? date('d.m.Y', strtotime($field_value)) : '';

                                            } elseif ($type_name == 'datetime') {
                                                $field_value = $field_value ? date('d.m.Y H:i', strtotime($field_value)) : '';

                                            } elseif ($type_name == 'switch') {
                                                $field_value = $field_value == 'Y' ? 'Вкл' : 'Выкл';

                                            } elseif ($type_name == 'hidden') {
                                                $field_value = '';
                                            }


                                            $tpl->item->field_readonly->assign('[VALUE]', $field_value);
                                            $tpl->item->field_readonly->reassign();
                                        }

                                        $tpl->item->assign('[ID]', $fieldId . '-' . $num);
                                        $tpl->item->reassign();
                                        $num++;
                                    }


                                    $controlGroups[$cellId]['html'][$key] .= $tpl;
                                }

                            }
                            else {
                                foreach ($item_fields as $key_column => $option) {
                                    if ( ! empty($option['options'])) {
                                        $options = [];
                                        foreach ($option['options'] as $key_val => $item) {
                                            $options[] = ['val' => $key_val, 'title' => $item];
                                        }
                                        $item_fields[$key_column]['options'] = $options;
                                    }
                                }

                                $tpl = new Templater3(Theme::get("html-edit-dataset"));
                                $tpl->assign('[THEME_PATH]', self::THEME_HTML);
                                $tpl->assign('[FIELD_ID]',   $fieldId);
                                $tpl->assign('[FIELD]',      $field);
                                $tpl->assign('[OPTIONS]',    addslashes(json_encode($item_fields)));

                                if ($is_delete) {
                                    $tpl->touchBlock('delete_col');
                                }

                                if ($is_add) {
                                    $tpl->touchBlock('edit_controls');
                                }

                                foreach ($item_fields as $item_field) {
                                    if (empty($item_field['type']) || $item_field['type'] != 'hidden') {
                                        $tpl->title->assign('[TITLE]', $item_field['title'] ?? '');
                                        $tpl->title->reassign();
                                    }
                                }


                                if ( ! empty($datasets)) {
                                    $num = 1;
                                    foreach ($datasets as $dataset) {

                                        foreach ($item_fields as $item_field) {
                                            $field_value = '';

                                            if ( ! empty($dataset) && isset($dataset[$item_field['code']])) {
                                                $field_value = is_string($dataset[$item_field['code']]) || is_numeric($dataset[$item_field['code']])
                                                    ? $dataset[$item_field['code']]
                                                    : '';
                                            }

                                            $field_attributes = ! empty($item_field['attributes'])
                                                ? $item_field['attributes']
                                                : '';


                                            $type_name = $item_field['type'] ?? 'text';

                                            if ( ! in_array($type_name, ['text', 'textarea', 'select','select2', 'date', 'datetime', 'number', 'switch', 'hidden', 'text_readonly'])) {
                                                $type_name = 'text';
                                            }

                                            if (($type_name == 'select' || $type_name == 'select2') && ! empty($item_field['options'])) {

                                                foreach ($item_field['options'] as $option) {
                                                    $tpl->item->field->{"field_{$type_name}"}->option->assign('[VALUE]', $option['val']);
                                                    $tpl->item->field->{"field_{$type_name}"}->option->assign('[TITLE]', $option['title']);
                                                    $tpl->item->field->{"field_{$type_name}"}->option->assign('[SELECTED]', $option['val'] == $field_value ? 'selected="selected"' : '');
                                                    $tpl->item->field->{"field_{$type_name}"}->option->reassign();
                                                }
                                            }
                                            if ($type_name == 'switch') {
                                                $tpl->item->field->{"field_{$type_name}"}->assign('[CHECKED_Y]', $field_value == 'Y' ? 'checked="checked"' : '');
                                                $tpl->item->field->{"field_{$type_name}"}->assign('[CHECKED_N]', $field_value == 'N' ? 'checked="checked"' : '');
                                            }

                                            $tpl->item->field->{"field_{$type_name}"}->assign('[FIELD]',      $field);
                                            $tpl->item->field->{"field_{$type_name}"}->assign('[NUM]',        $num);
                                            $tpl->item->field->{"field_{$type_name}"}->assign('[CODE]',       $item_field['code']);
                                            $tpl->item->field->{"field_{$type_name}"}->assign('[VALUE]',      $field_value);
                                            $tpl->item->field->{"field_{$type_name}"}->assign('[ATTRIBUTES]', $field_attributes);
                                            $tpl->item->field->reassign();
                                        }

                                        if ($is_delete) {
                                            $tpl->item->touchBlock('delete');
                                        }

                                        $tpl->item->assign('[ID]', $fieldId . '-' . $num);
                                        $tpl->item->reassign();
                                        $num++;
                                    }

                                }
                                else {
                                    foreach ($item_fields as $item_field) {
                                        $field_attributes  = ! empty($item_field['attributes'])
                                            ? $item_field['attributes']
                                            : '';

                                        $type_name = $item_field['type'] ?? 'text';

                                        if ( ! in_array($type_name, ['text', 'textarea', 'select', 'select2', 'date', 'datetime', 'number', 'switch', 'hidden'])) {
                                            $type_name = 'text';
                                        }

                                        if (($type_name == 'select' || $type_name == 'select2') && ! empty($item_field['options'])) {
                                            foreach ($item_field['options'] as $option_value => $option) {
                                                $selected = isset($item_field['default_value']) && $item_field['default_value'] == $option_value
                                                    ? 'selected'
                                                    : '';

                                                $tpl->item->field->{"field_{$type_name}"}->option->assign('[VALUE]', $option['val']);
                                                $tpl->item->field->{"field_{$type_name}"}->option->assign('[TITLE]', $option['title']);
                                                $tpl->item->field->{"field_{$type_name}"}->option->assign('[SELECTED]', $selected);
                                                $tpl->item->field->{"field_{$type_name}"}->option->reassign();
                                            }
                                        }
                                        if ($type_name == 'switch') {
                                            $tpl->item->field->{"field_{$type_name}"}->assign('[CHECKED_Y]', $item_field['default_value'] == 'Y' ? 'checked="checked"' : '');
                                            $tpl->item->field->{"field_{$type_name}"}->assign('[CHECKED_N]', $item_field['default_value'] == 'N' ? 'checked="checked"' : '');
                                        }

                                        $tpl->item->field->{"field_{$type_name}"}->assign('[FIELD]',      $field);
                                        $tpl->item->field->{"field_{$type_name}"}->assign('[NUM]',        1);
                                        $tpl->item->field->{"field_{$type_name}"}->assign('[CODE]',       $item_field['code']);
                                        $tpl->item->field->{"field_{$type_name}"}->assign('[VALUE]',      $item_field['default_value'] ?? '');
                                        $tpl->item->field->{"field_{$type_name}"}->assign('[ATTRIBUTES]', $field_attributes);
                                        $tpl->item->field->reassign();
                                    }

                                    if ($is_delete) {
                                        $tpl->item->touchBlock('delete');
                                    }
                                    $tpl->item->assign('[ID]', $fieldId . '-1');
                                }

                                $controlGroups[$cellId]['html'][$key] .= $tpl->render();
                            }

                        }
						elseif ($value['type'] == self::TYPE_XFILE || $value['type'] == self::TYPE_XFILES) {
							[$module, $action] = Registry::get('context');
							if ($this->readOnly || in_array($field, $this->read_only_fields)) {
								$files = $this->db->fetchAll("
                                    SELECT id, 
                                           filename,
                                           type 
                                    FROM `{$this->table}_files` 
                                    WHERE refid = ?
                                      AND fieldid = ?
                                ", array(
                                    $refid,
                                    $value['default']
                                ));

								if ($files) {
									foreach ($files as $file) {
									    if (in_array($file['type'], array('image/jpeg', 'image/png', 'image/gif'))) {
                                            $controlGroups[$cellId]['html'][$key] .=
                                                "<div class=\"fileupload-file-readonly\">" .
                                                    "<a href=\"index.php?module={$module}&fileid={$file['id']}&filehandler={$this->table}\">" .
                                                        "<img class=\"img-rounded\" src=\"index.php?module={$module}&filehandler={$this->table}&thumbid={$file['id']}\" alt=\"{$file['filename']}\">" .
                                                    "</a>" .
                                                "</div>";
                                        } else {
                                            $controlGroups[$cellId]['html'][$key] .= "<div class=\"fileupload-file-readonly\"><i class=\"fa fa-file-text-o\"></i> <a href=\"index.php?module={$module}&fileid={$file['id']}&filehandler={$this->table}\">{$file['filename']}</a></div>";
                                        }
									}
								} else {
									$controlGroups[$cellId]['html'][$key] .= '<i>нет прикрепленных файлов</i>';
								}
							}
                            else {
                                $this->scripts['upload'] = "xfile";
                                $this->HTML = str_replace('[_ACTION_]', 'index.php?module=admin&loc=core&action=upload', $this->HTML);
								$params = explode("_", $value['type']);
								$ft = '';
								$options = array('dataType' => 'json');
								if ($auto) {
									$options['autoUpload'] = true;
								}
								if (in_array("xfiles", $params)) {
									$xfile = "xfiles";
								} elseif (in_array("xfile", $params)) {
									$xfile = "xfile";
								}
                                $options['maxFileSize'] = Tool::getUploadMaxFileSize();
								if (is_array($value['in'])) {
									if ( ! empty($value['in']['id_hash'])) {
										$options['id_hash'] = true;
									}
                                    if ( ! empty($value['in']['maxWidth']) && is_numeric($value['in']['maxWidth'])) {
                                        $this->setSessFormField($field . '|maxWidth', $value['in']['maxWidth']);
                                    }
									if ( ! empty($value['in']['maxHeight']) && is_numeric($value['in']['maxHeight'])) {
										$this->setSessFormField($field . '|maxHeight', $value['in']['maxHeight']);
									}
                                    if ( ! empty($value['in']['check_width']) && is_numeric($value['in']['check_width'])) {
                                        $this->setSessFormField($field . '|check_width', $value['in']['check_width']);
                                    }
									if ( ! empty($value['in']['check_height']) && is_numeric($value['in']['check_height'])) {
										$this->setSessFormField($field . '|check_height', $value['in']['check_height']);
									}
									if ( ! empty($value['in']['maxFileSize'])) {
										$options['maxFileSize'] = $value['in']['maxFileSize'];
									}
									if ( ! empty($value['in']['acceptFileTypes'])) {
										$ft = str_replace(",", "|", $value['in']['acceptFileTypes']);
										$options['acceptFileTypes'] = "_FT_";
									}
								}
                                $max_filesize_human = Tool::formatSizeHuman($options['maxFileSize']);

								$un = $fieldId;
                                $tpl = new \Templater3($this->tpl_control['files']);
                                if ($xfile == 'xfiles') {
                                    $tpl->touchBlock('xfiles');
                                    $tpl->assign("{S}", "ы");
                                    $tpl->assign('files[]"', 'files[]" multiple');
                                } else {
                                    $tpl->assign("{S}", "");
                                }
                                if (!isset($options['autoUpload']) || !$options['autoUpload']) {
                                    $tpl->touchBlock('all');
                                }

								$controlGroups[$cellId]['html'][$key] .= '<input type="hidden" id="' . $fieldId . '" name="control[files|' . $field . ']"/>
									<input type="hidden" id="' . $fieldId . '_del" name="control[filesdel|' . $field . ']"/>
									<div id="fileupload-' . $un . '">' .
                                        $tpl->render() .
                                    '</div>';
                                $tpl = new \Templater3($this->tpl_control['xfile_upload']);
                                $controlGroups[$cellId]['html'][$key] .= '
                                    <!-- The template to display files available for upload -->
                                    <script id="template-upload" type="text/x-tmpl">
                                    {% for (var i=0, file; file=o.files[i]; i++) { %}' .
                                        $tpl->render() .
                                    '{% } %}
                                    </script>';
                                $tpl = new \Templater3($this->tpl_control['xfile_download']);
                                if (!empty($options['id_hash'])) {
                                    $tpl->assign("file.hash%", "file.id_hash%");
                                } else {
                                    $tpl->assign("file.hash%", "file.url%");
                                }
                                $controlGroups[$cellId]['html'][$key] .= '
                                    <!-- The template to display files available for download -->
                                    <script id="template-download" type="text/x-tmpl">
                                    {% for (var i=0, file; file=o.files[i]; i++) { %}' .
                                        $tpl->render() .
                                    '{% } %}
                                    </script>';

$controlGroups[$cellId]['html'][$key] .= "<script>
	edit.xfiles['$un'] = {};
	$(function () {
		'use strict';
	
		// Initialize the jQuery File Upload widget:
		$('#fileupload-{$un}').fileupload(" . str_replace('"_FT_"', "/(\.|\/)($ft)$/i", json_encode($options)) . ");
		$('#fileupload-{$un}').bind('fileuploaddone', function (e, data) {
			var f = data.response().result.files[0];
			$('#fileupload-$fieldId div.fileupload-buttonbar button.delete').removeClass('hide');
			$('#fileupload-$fieldId div.fileupload-buttonbar input.toggle').removeClass('hide');
			$('#fileupload-$fieldId div.fileupload-buttonbar button.start').addClass('hide');
			edit.xfiles['$un'][f.name + '###' + f.size + '###' + f.type] = f;
			var res = [];
			for (var k in edit.xfiles['$un']) {
				res.push(k);
			}
			$('#$fieldId').val(res.join('|'));
		}).bind('fileuploaddestroy', function (e, data) {
			var d = data.context.find('.delete').parent();
			var ds = d.data('service');
			var di = d.data('id');
			if (ds) {
				delete edit.xfiles['$un'][ds];
				var res = [];
				for (var k in edit.xfiles['$un']) {
					res.push(k);
				}
				$('#{$fieldId}').val(res.join('|'));
			}
			if (di) {
				$('#{$fieldId}_del').val($('#{$fieldId}_del').val() + ',' + di);
			}
		}).bind('fileuploaddestroyed', function (e, data) {
			var fc = $('#fileupload-{$un}').find('.files');
			if (fc.children().length == 0) {
				$('#fileupload-$fieldId div.fileupload-buttonbar button.start').addClass('hide');
				$('#fileupload-$fieldId div.fileupload-buttonbar button.cancel').addClass('hide');
				$('#fileupload-$fieldId div.fileupload-buttonbar button.delete').addClass('hide');
				$('#fileupload-$fieldId div.fileupload-buttonbar input.toggle').addClass('hide');
			}
		}).bind('fileuploadchange', function (e, data) {
			$('#fileupload-$fieldId div.fileupload-buttonbar button.start').removeClass('hide');
		});
	
	";

if ( ! empty($options['maxFileSize'])) {
    $controlGroups[$cellId]['html'][$key] .= "
        $('#fileupload-{$un}').bind('fileuploadadd', function (e, data) {
            var maxFileSize = {$options['maxFileSize']};
            var fileSize    = data.originalFiles[0].size || data.originalFiles[0].fileSize;
            var fileName    = data.originalFiles[0].name || data.originalFiles[0].fileName;
            if (fileSize && fileSize > maxFileSize) {
			    if ($(this).find('.files > tr').length <= 0) {
				    $('#fileupload-$fieldId div.fileupload-buttonbar button.start').addClass('hide');
				}
				alert('Файл \"' + fileName + '\" превышает предельный размер ({$max_filesize_human})');
				return false;
			}
        });
    ";
}

if ( ! empty($ft)) {
    $controlGroups[$cellId]['html'][$key] .= "
        $('#fileupload-{$un}').bind('fileuploadadd', function (e, data) {
            var acceptFileTypes = /\.($ft)$/i;
			var fileName        = data.originalFiles[0].name || data.originalFiles[0].fileName;
			if (!acceptFileTypes.test(fileName)) {
			    if ($(this).find('.files > tr').length <= 0) {
				    $('#fileupload-$fieldId div.fileupload-buttonbar button.start').addClass('hide');
				}
				alert('Файл \"' + fileName + '\" имеет некорректное расширение.');
				return false;
			}
        });
    ";
}

if ($xfile === 'xfile') {
    $controlGroups[$cellId]['html'][$key] .= "
        $('#fileupload-{$un}').bind('fileuploadadd', function (e, data) {
            var files = $(this).find('.files > tr');
            if (files.length >= 1) {
                $(this).trigger('fileuploaddestroy', {context: $(files[0])});
                $(this).find('.files').empty();
            }
        });
    ";
}

if (isset($options['autoUpload']) && $options['autoUpload']) {
    $controlGroups[$cellId]['html'][$key] .= "
        $('#fileupload-{$un}').bind('fileuploadpaste', function (e, data) {
            data.submit();
        });
    ";
}

$controlGroups[$cellId]['html'][$key] .= "
    // Load existing files:
	//$('#fileupload-{$un}').addClass('fileupload-processing');
	$.ajax({
		// Uncomment the following to send cross-domain cookies:
		//xhrFields: {withCredentials: true},
		url: 'index.php?module=$module&action=$action&filehandler={$this->table}&listid=$refid&f=$field',
		dataType: 'json',
		context: $('#fileupload-{$un}')[0]
	}).always(function () {
		//$(this).removeClass('fileupload-processing');
	}).done(function (result) {
		if (result.files && result.files[0]) {
			$('#fileupload-$fieldId div.fileupload-buttonbar button.delete').removeClass('hide');
			$('#fileupload-$fieldId div.fileupload-buttonbar input.toggle').removeClass('hide');
		}
		$(this).fileupload('option', 'done').call(this, $.Event('done'), {result: result});
	});
});
</script>";

							}
						}
						elseif ($value['type'] == 'modal') {
							if ($this->readOnly || in_array($field, $this->read_only_fields)) {
								$controlGroups[$cellId]['html'][$key] .= !empty($this->modal[$modal]['value']) ? $this->modal[$modal]['value'] : '';
							} else {
                                $this->scripts['modal'] = 'simplemodal';
								if (is_array($value['in'])) {
									$options = $value['in']['options'];
									$temp = " ";
									foreach ($value['in'] as $attr => $val) {
										if ($attr == 'options') continue;
										$temp .= $attr . '="' . $val . '" ';
									}
									$attrs = $temp;
								} else {
									$options = '';
								}

								$modalHTML = '';
								if (!empty($this->modal[$modal]['iframe'])) {

									if (!is_array($this->modal[$modal]['iframe'])) {
										$this->modal[$modal]['iframe'] = array();
									}
									$modalHTML .= '<iframe ';
									foreach ($this->modal[$modal]['iframe'] as $attr => $attr_value) {
										if ($attr == 'src') {
											$options = ltrim($options, '{');
											$options = '{onShow: function (dialog) {
												document.getElementById(\'modal_' . $field . '\').childNodes[0].src=\'' . $attr_value . '\';
											},' . $options;
											$attr_value = '';
										}
										$modalHTML .= $attr . '="' . $attr_value . '" ';
									}
									$modalHTML .= '></iframe>';
								} elseif (!empty($this->modal[$modal]['html'])) {
									$modalHTML = $this->modal[$modal]['html'];
								}
								$controlGroups[$cellId]['html'][$key] .= '<table><tr><td>';
								if (!empty($this->modal[$modal]['textarea'])) {
									$controlGroups[$cellId]['html'][$key] .= '<textarea id="' . $fieldId . '_text" class="input" ' . $attrs . '>' . (!empty($this->modal[$modal]['value']) ? $this->modal[$modal]['value'] : '') . '</textarea>';
								} else {
									$controlGroups[$cellId]['html'][$key] .= '<input id="' . $fieldId . '_text" class="input"  type="text" ' . $attrs . ' value="' . (!empty($this->modal[$modal]['value']) ? $this->modal[$modal]['value'] : '') . '"/>';
								}
								$controlGroups[$cellId]['html'][$key] .= '</td><td><input type="button" class="buttonSmall" value="' . $this->classText['MODAL_BUTTON'] . '"
									onclick="' . (!empty($this->modal[$modal]['script']) ? trim($this->modal[$modal]['script'], ';') . ';' : '') . '$(\'#modal_' . $field . '\').modal(' . $options . ');"/>';
                                if (!$value['req']) {
                                    $controlGroups[$cellId]['html'][$key] .= "<input type=\"button\" class=\"buttonSmall\" value=\"{$this->classText['MODAL_BUTTON_CLEAR']}\" onclick=\"edit.modalClear('{$fieldId}')\"/>";
                                }
                                $controlGroups[$cellId]['html'][$key] .= '<input id="' . $fieldId . '" name="control[' . $field . ']" type="hidden" value="' . (!empty($this->modal[$modal]['key']) ? $this->modal[$modal]['key'] : $value['default']) . '"/>'.
									'<script>var xxxx=""</script>' .
									'</td></tr></table>' .
									'<div id="modal_' . $field . '" style="display:none;" class="modal_window">' . $modalHTML . '</div>';
							}
							$modal++;
						}

						if (!empty($value['out'])) {
							$controlGroups[$cellId]['html'][$key] .= str_replace("[VAL]", $value['default'], $value['out']);
						}
						$controlGroups[$cellId]['html'][$key] .= '</td></tr></table>';
					}
				}
			}

            if ($this->scripts) {
                if (isset($this->scripts['date2'])) {
                    Tool::printJs("core2/js/control_datepicker.js", true);
                }
                if (isset($this->scripts['datetime2'])) {
                    Tool::printJs("core2/js/control_datetimepicker.js", true);
                }
                if (isset($this->scripts['color'])) {
                    Tool::printCss(self::THEME_HTML . "/css/bootstrap-colorpicker.min.css");
                    Tool::printJs(self::THEME_HTML . "/js/bootstrap-colorpicker.min.js", true);
                }
                if (isset($this->scripts['multiselect2']) ||
                    isset($this->scripts['select2']) ||
                    isset($this->scripts['tags'])
                ) {
                    Tool::printCss(self::THEME_HTML . "/css/select2.min.css");
                    Tool::printCss(self::THEME_HTML . "/css/select2.bootstrap.css");
                    Tool::printJs(self::THEME_HTML . "/js/select2.min.js", true);
                    Tool::printJs(self::THEME_HTML . "/js/select2.ru.min.js", true);
                }
                if (isset($this->scripts['modal2'])) {
                    Tool::printJs("core2/js/bootstrap.modal.min.js", true);
                    Tool::printCss(self::THEME_HTML . "/css/bootstrap.modal.min.css");
                }
                if (isset($this->scripts['upload'])) {
                    Tool::printCss(self::THEME_HTML . "/fileupload/jquery.fileupload.css");
                    Tool::printCss(self::THEME_HTML . "/fileupload/jquery.fileupload-ui.css");
                    Tool::printJs("core2/js/tmpl.min.js", true);
                    Tool::printJs("core2/js/load-image.min.js", true);
                    Tool::printJs("core2/vendor/belhard/jquery-file-upload/js/jquery.fileupload.js", true);
                    Tool::printJs("core2/vendor/belhard/jquery-file-upload/js/jquery.iframe-transport.js", true);
                    Tool::printJs("core2/vendor/belhard/jquery-file-upload/js/jquery.fileupload-process.js", true);
                    Tool::printJs("core2/vendor/belhard/jquery-file-upload/js/jquery.fileupload-image.js", true);
                    Tool::printJs("core2/vendor/belhard/jquery-file-upload/js/jquery.fileupload-audio.js", true);
                    Tool::printJs("core2/vendor/belhard/jquery-file-upload/js/jquery.fileupload-video.js", true);
                    Tool::printJs("core2/vendor/belhard/jquery-file-upload/js/jquery.fileupload-validate.js", true);
                    Tool::printJs("core2/vendor/belhard/jquery-file-upload/js/jquery.fileupload-ui.js", true);
                }
                if (isset($this->scripts['modal'])) {
                    Tool::printJs("core2/vendor/belhard/simplemodal/src/jquery.simplemodal.js", true);
                }
            }

			$fromReplace = array();
			$toReplace   = array();
			foreach ($controlGroups as $cellId => $value) {
				$fromReplace[] = "[$cellId]";
				$html          = '';
				if (!empty($value['group'])) {
					$html   .= '<div>';
					$ingroup = false;
					foreach ($value['html'] as $key => $control) {
						foreach ($value['group'] as $group) {
							if ($group['key'] == $key) {
								if ($ingroup) {
									$html .= '</div>';
								}

                                $styles_head = $this->firstColWidth ? "width:{$this->firstColWidth};\"" : "width:190px";
                                $styles_body = $group['collapsed'] ? 'display:none' : '';

                                $html .= "<h3 class=\"core-group-head\" style=\"{$styles_head}\"><a href=\"javascript:void(0);\" onclick=\"edit.toggleGroup(this);\">{$group['name']}</a></h3>";
								$html .= "<div class=\"core-group-body\" style=\"{$styles_body}\">";
								$ingroup = true;
								break;
							}
						}
						$html .= $control;
					}
					$html .= '</div></div>';
				} else {
					foreach ($value['html'] as $control) {
						$html .= $control;
					}
				}
				$toReplace[] = $html;
			}

			$this->HTML .= str_replace($fromReplace, $toReplace, $this->template);
		}

		//buttons area
		$this->HTML .= "<div class=\"buttons-container\">";
		$this->HTML .= "<div class=\"buttons-offset\"" . ($this->firstColWidth ? " style=\"width:{$this->firstColWidth};\"" : "") . "></div>";
		$this->HTML .= "<div class=\"buttons-area\" style=\"text-align:right\">";
		if (isset($this->buttons[$this->main_table_id]) && is_array($this->buttons[$this->main_table_id])) {
			foreach ($this->buttons[$this->main_table_id] as $value) {
				if (!empty($value['value'])) {
					$this->HTML .= $this->button($value['value'], 'button', $value['action']);
				} elseif (!empty($value['html'])) {
					$this->HTML .= $value['html'];
				}
			}
		}

		if (!$this->readOnly) {

            $sess_form = new SessionContainer('Form');
            //$this->uniq_class_id .= "|$refid";
            $already_opened = $sess_form->{$this->uniq_class_id};
            //CUSTOM session fields
            if (!$refid) $refid = 0;
            $refid .= "_" . crc32($_SERVER['REQUEST_URI']);
            $sess_data = isset($already_opened[$refid]) ? $already_opened[$refid] : [];
            if ($this->sess_form_custom) {
                foreach ($this->sess_form_custom as $key => $item) {
                    //TODO возможно надо добавить проверку того что мы вставляем в ссессию
                    $sess_data[$key] = $item;
                }
            }
            $already_opened[$refid] = $sess_data;
            //есль ли у юзера еще одна открытая эта же форма, то в сессии ничего не изменится
            if ($already_opened) {
                $sess_form->{$this->uniq_class_id} = $already_opened;
            }

            $this->HTML .= $this->button($this->classText['SAVE'], "submit", "this.form.onsubmit();return false;", "button save");
		}
		$this->HTML .= 	"</div></div>";
		if (!$this->readOnly) {
			$this->HTML .= 	"</form><script>function PrepareSave(){" . $PrepareSave . "} $onload </script>";
		}
		$this->HTML .= 	"</br>";
	}


    /**
     * @param $func
     * @param $action
     * @return $this
     */
	public function save($func, $action = ''): self {
        $this->action = $action;
		$this->isSaved = true;
		// for javascript functions
		if (strpos($func, '(') !== false) {
			$this->beforeSaveArr[] = $func;
		} else {
			$this->addParams('file', $func);
		}

        return $this;
	}


    /**
     * Установка метода для обработки сохранения
     * @param string $handler
     * @return editTable
     */
	public function setSaveHandler(string $handler): self {

        $this->save("xajax_{$handler}(xajax.getFormValues(this.id))");

        return $this;
	}


    /**
     * Установка js кода которых будет выполнен при успешном сохранении
     * @param string $func
     * @return editTable
     * @deprecated использовать addSuccessScript
     */
	public function saveSuccess(string $func): self {

        $this->addSuccessScript($func);

        return $this;
	}


    /**
     * Установка js кода которых будет выполнен при успешном сохранении
     * @param string $script
     * @return editTable
     */
	public function addSuccessScript(string $script): self {

        $func = $this->sess_form_custom['save_success'] ?? '';

        $this->setSessFormField('save_success', "{$func};{$script}");

        return $this;
	}


    /**
     * Установка сообщения об успешном выполнении
     * @param string|null $text
     * @return editTable
     */
	public function addSuccessNotice(string $text = null): self {

        $text = $text ?: $this->_('Сохранено');
        $func = $this->sess_form_custom['save_success'] ?? '';

        $this->setSessFormField('save_success', "{$func};CoreUI.notice.create('{$text}')");

        return $this;
	}


    /**
     * Установка адреса для перехода при успешном сохранении
     * @param string $url
     * @return editTable
     */
	public function setSuccessUrl(string $url): self {

        $func = $this->sess_form_custom['save_success'] ?? '';

        $this->setSessFormField('save_success', "{$func};load('{$url}')");

        return $this;
	}


    /**
     * @param $va
     * @param $value
     * @return $this
     */
	public function addParams($va, $value = ''): self {
        $this->params[$this->main_table_id][] = ['va' => $va, 'value' => $value];
        return $this;
    }


    /**
	 *
	 */
	private function noAccess() {
		echo $this->classText['noReadAccess'];
	}


	/**
	 * Сохраняет в сессии данные служебных полей формы
	 * @param $data
	 */
	private function setSessForm($data)
	{
        foreach ($data as $key => $item) {
            $this->setSessFormField($key, $item);
        }
	}

	/**
	 * преобразование атрибутов в строку
	 * @param $value
	 *
	 * @return string
	 */
	private function setAttr($value) {
		//преобразование атрибутов в строку
		$attrs = $value;
		if (is_array($value)) {
			$temp = " ";
			if (count($value)) {
				foreach ($value as $attr => $val) {
                    if (is_string($val)) {
                        $temp .= $attr . '="' . $val . '" ';
                    }
				}
			}
			$attrs = $temp;
		}
		return $attrs;
	}

	/**
	 * @param $row
	 * @param $tcol
	 * @return string
	 */
	private function replaceTCOL($row, $tcol) {
		$temp = explode("TCOL_", $tcol);
		$tres = "";
		foreach ($temp as $tkey=>$tvalue) {
			if ($tkey == 0) {
				$tres .= $tvalue;
			} else {
				$tres .= $row[substr($tvalue, 0, 2) * 1] . substr($tvalue, 2);
			}
		}
		return $tres;
	}

	/**
	 * @param $value
	 * @param string $type
	 * @param string $onclick
	 * @return string
	 */
	private function button($value, $type = "Submit", $onclick = "", $cssClass = "button") {
		$id = uniqid();
		$out = '<input type="' . $type . '" class="' . $cssClass . '" value="' . $value . '" ' . ($onclick ? 'onclick="' . rtrim($onclick, ";") . '"' : '') . '/>';

		return $out;
	}

    /**
     * Контроль открытой формы
     * @param $keyfield
     * @param $refid
     * @return void
     */
    private function formControl($keyfield, $refid) {
        $check = $this->db->fetchOne("SELECT 1 FROM core_controls WHERE tbl=? AND keyfield=? AND val=?",
            array($this->table, $keyfield, $refid)
        );
        $lastupdate = microtime();
        $auth = Registry::get('auth');
        if (!$check) {
            $this->db->insert('core_controls', array(
                'tbl' 		=> $this->table,
                'keyfield' 	=> $keyfield,
                'val' 		=> $refid,
                'lastuser' 	=> $auth->ID,
                'lastupdate' => $lastupdate
            ));
        } else {
            $this->db->query("UPDATE core_controls SET lastupdate=?, lastuser=? WHERE tbl=? AND keyfield=? AND val=?",
                array($lastupdate, $auth->ID, $this->table, $keyfield, $refid)
            );
        }
    }
}






class cell {
    protected $controls      = [];
    protected $gr            = [];
    protected $main_table_id;

    public function __construct($main_table_id) {
		$this->main_table_id 	= $main_table_id;
		
	}

	/**
	 * @param $name
	 * @param $type
	 * @param string $in
	 * @param string $out
	 * @param string $default
	 * @param bool $req
	 */
	public function addControl($name, $type, $in = "", $out = "", $default = "", $req = false) {
		global $counter;
        $temp = [
            'name'    => $name,
            'type'    => strtolower($type),
            'in'      => $in,
            'out'     => $out,
            'default' => $default,
            'req'     => $req,
        ];
        $this->controls[$this->main_table_id][$counter] = $temp;
		
		if (!empty($this->gr[$counter])) {
			$this->controls[$this->main_table_id][$counter]['group'] = $this->gr[$counter];
		}
		$counter++;
	}

	/**
	 * @param $name
	 * @param bool $collapsed
	 */
	public function addGroup($name, $collapsed = false) {
		global $counter;
		if ($collapsed) $collapsed = "*";
		if (!$counter) $counter = 0;
		$this->gr[$counter] = $collapsed . $name;
	}
    
	public function __get($name) {
        return $this->$name;
    }

	/**
	 * @param $arr
	 */
	public function appendControl($arr) {
    	global $counter;
    	if (!$counter) $counter = 0;
    	if (!empty($this->gr[$counter])) {
			$arr['group'] = $this->gr[$counter];
		}
    	$this->controls[$this->main_table_id][$counter] = $arr;
    	$counter++;
    }

	/**
	 * @param $arr
	 */
	public function setGroup($arr) {
    	foreach ($arr as $k => $v) {
    		$this->gr[$k] = $v;
    	}
    	
    }

}