<?php
namespace Core2\Classes\Table;
use Core2\Acl;
use Laminas\Session\Container as SessionContainer;

require_once __DIR__ . '/../Templater3.php';


/**
 *
 */
class Render extends Acl {

    /**
     * @var SessionContainer
     */
    protected $session        = null;
    protected $theme_src      = '';
    protected $theme_location = '';
    protected $date_mask      = "d.m.Y";
    protected $datetime_mask  = "d.m.Y H:i";
    protected $locutions      = [
        'All'                                        => 'Все',
        'Add'                                        => 'Добавить',
        'Delete'                                     => 'Удалить',
        'Are you sure you want to delete this post?' => 'Вы действительно хотите удалить эту запись?',
        'You must select at least one record'        => 'Нужно выбрать хотя бы одну запись',
    ];

    private $table = [];


    /**
     * @param array $table
     */
    public function __construct(array $table) {

        parent::__construct();

        $this->theme_src      = DOC_PATH . 'core2/html/' . THEME;
        $this->theme_location = DOC_ROOT . 'core2/html/' . THEME;

        $this->session = new SessionContainer($table['resource']);

        if ( ! isset($this->session->table)) {
            $this->session->table = new \stdClass();
        }

        $this->table = $table;
    }


    /**
     * Рендеринг таблицы
     * @return string
     * @throws \Exception
     */
    public function render(): string {

        if ( ! $this->checkAcl($this->table['resource'], 'list_all') &&
            ! $this->checkAcl($this->table['resource'], 'list_owner')
        ) {
            return '';
        }

        $tpl = new \Templater3($this->theme_location . '/html/table.html');
        $tpl->assign('[THEME_SRC]', $this->theme_src);
        $tpl->assign('[RESOURCE]',  $this->table['resource']);
        $tpl->assign('[IS_AJAX]',   (int)($this->table['isAjax'] ?? 0));
        $tpl->assign('[LOCATION]',  ! empty($this->table['isAjax']) ? $_SERVER['QUERY_STRING'] . "&__{$this->table['resource']}=ajax" : $_SERVER['QUERY_STRING']);


        if ( ! empty($this->table['show'])) {
            if ( ! empty($this->table['show']['toolbar'])) {
                $tpl->service->assign('[TOTAL_RECORDS]', $this->table['recordsTotal']);

                if ( ! empty($this->table['toolbar'])) {

                    if ( ! empty($this->table['toolbar']['buttons'])) {
                        $buttons = [];

                        foreach ($this->table['toolbar']['buttons'] as $button) {
                            if (is_array($button)) {
                                if ( ! is_string($button['content'])) {
                                    continue;
                                }

                                $attributes = [];
                                if ( ! empty($button['attr'])) {
                                    foreach ($button['attr'] as $attr => $value) {
                                        if (is_string($attr) && is_string($value)) {
                                            $attributes[] = "$attr=\"{$value}\"";
                                        }
                                    }
                                }

                                $implode_attributes = implode(' ', $attributes);
                                $implode_attributes = $implode_attributes ? ' ' . $implode_attributes : '';

                                $buttons[] = "<button{$implode_attributes}>{$button['content']}</button>";

                            } elseif (is_string($button)) {
                                $buttons[] = $button;
                            }
                        }

                        $tpl->service->assign('[BUTTONS]', implode(' ', $buttons));

                    } else {
                        $tpl->service->assign('[BUTTONS]', '');
                    }

                    if ( ! empty($this->table['toolbar']['addButton']) &&
                        ($this->checkAcl($this->table['resource'], 'edit_all') ||
                         $this->checkAcl($this->table['resource'], 'edit_owner')) &&
                        ($this->checkAcl($this->table['resource'], 'read_all') ||
                         $this->checkAcl($this->table['resource'], 'read_owner'))
                    ) {
                        $tpl->service->add_button->assign('[URL]',      str_replace('?', '#', $this->table['toolbar']['addButton']));
                        $tpl->service->add_button->assign('[ADD_TEXT]', $this->getLocution('Add'));
                    }

                } else {
                    $tpl->service->assign('[BUTTONS]', '');
                }

                if ( ! empty($this->table['show']['delete']) &&
                    ($this->checkAcl($this->table['resource'], 'delete_all') ||
                     $this->checkAcl($this->table['resource'], 'delete_owner'))
                ) {
                    $delete_text   = $this->getLocution('Delete');
                    $delete_msg    = $this->getLocution('Are you sure you want to delete this post?');
                    $no_select_msg = $this->getLocution('You must select at least one record');

                    $tpl->service->del_button->assign('[DELETE_TEXT]',      $delete_text);
                    $tpl->service->del_button->assign('[DELETE_MSG]',       $delete_msg);
                    $tpl->service->del_button->assign('[DELETE_NO_SELECT]', $no_select_msg);
                }
            }

            if ($this->table['show']['header']) {
                if ($this->table['show']['selectRows'] == true) {
                    $tpl->header->touchBlock('checkboxes');
                }
            }

            if ($this->table['show']['columnManage']) {
                $tpl->controls->touchBlock('column_switcher_control');

                if ( ! empty($this->table['columns'])) {
                    foreach ($this->table['columns'] as $key => $column) {
                        if (is_array($column)) {
                            if (empty($column['field']) || ! is_string($column['field'])) {
                                continue;
                            }

                            if (isset($this->session->table->columns)) {
                                if (empty($this->session->table->columns[$column['field']])) {
                                    $this->table['columns'][$key]['show'] = $column['show'] = false;
                                } else {
                                    $this->table['columns'][$key]['show'] = $column['show'] = true;
                                }
                            }


                            $tpl->column_switcher_container->column_switcher_field->assign('[COLUMN]',  $column['field']);
                            $tpl->column_switcher_container->column_switcher_field->assign('[TITLE]',   $column['title'] ?? '');
                            $tpl->column_switcher_container->column_switcher_field->assign('[CHECKED]', $column['show'] ? 'checked="checked"' : '');
                            $tpl->column_switcher_container->column_switcher_field->reassign();
                        }
                    }
                }
            }


            if ($this->table['show']['footer']) {
                $current_page = $this->table['currentPage'] ?? 1;
                $count_pages  = ! empty($this->table['recordsTotal']) && ! empty($this->table['recordsPerPage'])
                    ? ceil($this->table['recordsTotal'] / $this->table['recordsPerPage'])
                    : 0;

                if ($count_pages > 1) {
                    $tpl->footer->pages->touchBlock('gotopage');
                }

                $tpl->footer->pages->assign('[CURRENT_PAGE]', $current_page);
                $tpl->footer->pages->assign('[COUNT_PAGES]',  $count_pages);

                if ($current_page > 1) {
                    $tpl->footer->pages->prev->assign('[PREV_PAGE]', $current_page - 1);
                }
                if ($current_page < $count_pages) {
                    $tpl->footer->pages->next->assign('[NEXT_PAGE]', $current_page + 1);
                }


                $recordsPerPage = $this->table['recordsPerPage'] ?? 25;
                $per_page_list  = [];

//                if ( ! in_array($recordsPerPage, $this->table['recordsPerPageList']) &&
//                    $recordsPerPage != 1000000000
//                ) {
//                    $per_page_list[$recordsPerPage] = $recordsPerPage;
//                }

                if ( ! empty($this->table['recordsPerPageList'])) {
                    foreach ($this->table['recordsPerPageList'] as $per_page_count) {
                        if (is_numeric($per_page_count) && $per_page_count > 0) {
                            $per_page_list[$per_page_count] = $per_page_count;
                        }
                    }
                }
                ksort($per_page_list);

                $per_page_list[0] = $this->getLocution('All');

                $tpl->footer->pages->per_page->fillDropDown(
                    'records-per-page-[RESOURCE]',
                    $per_page_list,
                    $recordsPerPage == 1000000000 ? 0 : $recordsPerPage
                );
            }
        }


        if ( ! empty($this->table['search'])) {
            $search_value = ! empty($this->session->table) && ! empty($this->session->table->search)
                ? $this->session->table->search
                : [];

            if ( ! empty($search_value) && count($search_value)) {
                $tpl->controls->search_control->touchBlock('search_clear');
            }

            $tpl->controls->touchBlock('search_control');

            foreach ($this->table['search'] as $key => $search) {
                if (is_array($search)) {
                    if (empty($search['type']) || ! is_string($search['type'])) {
                        continue;
                    }

                    $control_value  = $search_value[$key] ?? '';
                    $attributes_str = '';

                    if ( ! empty($search['attr']) && is_array($search['attr'])) {
                        $attributes = [];
                        foreach ($search['attr'] as $attr => $value) {
                            if (is_string($attr) && is_string($value)) {
                                $attributes[] = "$attr=\"{$value}\"";
                            }
                        }
                        $implode_attributes = implode(' ', $attributes);
                        $attributes_str     = $implode_attributes ? ' ' . $implode_attributes : '';
                    }

                    switch ($search['type']) {
                        case 'text' :
                        case 'text_strict' :
                            $tpl->search_container->search_field->text->assign("[KEY]",     $key);
                            $tpl->search_container->search_field->text->assign("[VALUE]",   $control_value);
                            $tpl->search_container->search_field->text->assign("[IN_TEXT]", $attributes_str);
                            break;

                        case 'radio' :
                            $data = $search['data'] ?? [];
                            if ( ! empty($data)) {
                                $data = ['' => $this->getLocution('All')] + $data;
                                foreach ($data as $radio_value => $radio_title) {
                                    $tpl->search_container->search_field->radio->assign("[KEY]",     $key);
                                    $tpl->search_container->search_field->radio->assign("[VALUE]",   $radio_value);
                                    $tpl->search_container->search_field->radio->assign("[TITLE]",   $radio_title);
                                    $tpl->search_container->search_field->radio->assign("[IN_TEXT]", $attributes_str);

                                    $is_checked = $control_value == $radio_value
                                        ? 'checked="checked"'
                                        : '';
                                    $tpl->search_container->search_field->radio->assign("[IS_CHECKED]", $is_checked);
                                    $tpl->search_container->search_field->radio->reassign();
                                }
                            }
                            break;

                        case 'checkbox' :
                            $data = $search['data'] ?? [];
                            if ( ! empty($data)) {
                                foreach ($data as $checkbox_value => $checkbox_title) {
                                    $tpl->search_container->search_field->checkbox->assign("[KEY]",     $key);
                                    $tpl->search_container->search_field->checkbox->assign("[VALUE]",   $checkbox_value);
                                    $tpl->search_container->search_field->checkbox->assign("[TITLE]",   $checkbox_title);
                                    $tpl->search_container->search_field->checkbox->assign("[IN_TEXT]", $attributes_str);

                                    $is_checked = is_array($control_value) && in_array($checkbox_value, $control_value)
                                        ? 'checked="checked"'
                                        : '';
                                    $tpl->search_container->search_field->checkbox->assign("[IS_CHECKED]", $is_checked);
                                    $tpl->search_container->search_field->checkbox->reassign();
                                }
                            }
                            break;

                        case 'number' :
                            $tpl->search_container->search_field->number->assign("[KEY]",         $key);
                            $tpl->search_container->search_field->number->assign("[VALUE_START]", $control_value[0] ?? '');
                            $tpl->search_container->search_field->number->assign("[VALUE_END]",   $control_value[1] ?? '');
                            $tpl->search_container->search_field->number->assign("[IN_TEXT]",     $attributes_str);
                            break;

                        case 'date' :
                            $tpl->search_container->search_field->date->assign("[KEY]",         $key);
                            $tpl->search_container->search_field->date->assign("[VALUE_START]", $control_value[0] ?? '');
                            $tpl->search_container->search_field->date->assign("[VALUE_END]",   $control_value[1] ?? '');
                            $tpl->search_container->search_field->date->assign("[IN_TEXT]",     $attributes_str);
                            break;

                        case 'datetime' :
                            $tpl->search_container->search_field->datetime->assign("[KEY]",         $key);
                            $tpl->search_container->search_field->datetime->assign("[VALUE_START]", $control_value[0] ?? '');
                            $tpl->search_container->search_field->datetime->assign("[VALUE_END]",   $control_value[1] ?? '');
                            $tpl->search_container->search_field->datetime->assign("[IN_TEXT]",     $attributes_str);
                            break;

                        case 'select' :
                            $data = $search['data'] ?? [];
                            $options = ['' => ''] + $data;
                            $tpl->search_container->search_field->select->assign("[KEY]",      $key);
                            $tpl->search_container->search_field->select->assign("[IN_TEXT]",  $attributes_str);
                            $tpl->search_container->search_field->select->fillDropDown("search-[RESOURCE]-[KEY]", $options, $control_value);
                            break;

                        case 'multiselect' :
                            $data = $search['data'] ?? [];
                            $tpl->search_container->search_field->multiselect->assign("[KEY]",      $key);
                            $tpl->search_container->search_field->multiselect->assign("[IN_TEXT]",  $attributes_str);
                            $tpl->search_container->search_field->multiselect->fillDropDown("search-[RESOURCE]-[KEY]", $data, $control_value);
                            break;
                    }


                    $tpl->search_container->search_field->assign("[#]",        $key);
                    $tpl->search_container->search_field->assign("[OUT_TEXT]", $search['out'] ?? '');
                    $tpl->search_container->search_field->assign('[CAPTION]',  $search['caption'] ?? '');
                    $tpl->search_container->search_field->assign('[TYPE]',     $search['type'] ?? '');
                    $tpl->search_container->search_field->reassign();
                }
            }
        }

        if ( ! empty($this->table['filter'])) {
            $filter_value = ! empty($this->session->table) && ! empty($this->session->table->filter)
                ? $this->session->table->filter
                : [];

            if ( ! empty($filter_value) && count($filter_value)) {
                $tpl->filter_controls->touchBlock('filter_clear');
            }


            foreach ($this->table['filter'] as $key => $filter) {
                if (is_array($filter)) {
                    if (empty($filter['type']) || ! is_string($filter['type'])) {
                        continue;
                    }

                    $control_value     = $filter_value[$key] ?? '';
                    $filter_attributes = $filter;
                    $attributes_str    = '';

                    if ( ! empty($filter['attr'])) {
                        $attributes = [];
                        foreach ($filter_attributes as $attr => $value) {
                            if (is_string($attr) && is_string($value)) {
                                $attributes[] = "$attr=\"{$value}\"";
                            }
                        }
                        $implode_attributes = implode(' ', $attributes);
                        $attributes_str     = $implode_attributes ? ' ' . $implode_attributes : '';
                    }

                    switch ($filter['type']) {
                        case 'text' :
                        case 'text_strict' :
                            $tpl->filter_controls->filter_control->text->assign("[KEY]",   $key);
                            $tpl->filter_controls->filter_control->text->assign("[VALUE]", $control_value);
                            $tpl->filter_controls->filter_control->text->assign("[TITLE]", $filter['title'] ?? '');
                            $tpl->filter_controls->filter_control->text->assign("[ATTR]",  $attributes_str);
                            break;

                        case 'radio' :
                            $data = $filter['data'] ?? '';
                            if ( ! empty($data)) {
                                if ( ! empty($filter['title'])) {
                                    $tpl->filter_controls->filter_control->radio->title->assign('[TITLE]', $filter['title']);
                                }

                                foreach ($data as $radio_value => $radio_title) {
                                    $is_checked = $control_value == $radio_value
                                        ? 'checked="checked"'
                                        : '';
                                    $is_active = $control_value == $radio_value
                                        ? 'active'
                                        : '';

                                    $tpl->filter_controls->filter_control->radio->item->assign("[KEY]",        $key);
                                    $tpl->filter_controls->filter_control->radio->item->assign("[VALUE]",      $radio_value);
                                    $tpl->filter_controls->filter_control->radio->item->assign("[TITLE]",      $radio_title);
                                    $tpl->filter_controls->filter_control->radio->item->assign("[ATTR]",       $attributes_str);
                                    $tpl->filter_controls->filter_control->radio->item->assign("[IS_CHECKED]", $is_checked);
                                    $tpl->filter_controls->filter_control->radio->item->assign("[IS_ACTIVE]",  $is_active);
                                    $tpl->filter_controls->filter_control->radio->item->reassign();
                                }
                            }
                            break;

                        case 'checkbox' :
                            $data = $filter['data'] ?? '';
                            if ( ! empty($data)) {
                                if ( ! empty($filter['title'])) {
                                    $tpl->filter_controls->filter_control->checkbox->title->assign('[TITLE]', $filter['title']);
                                }

                                foreach ($data as $checkbox_value => $checkbox_title) {
                                    $is_checked = is_array($control_value) && in_array($checkbox_value, $control_value)
                                        ? 'checked="checked"'
                                        : '';
                                    $is_active = is_array($control_value) && in_array($checkbox_value, $control_value)
                                        ? 'active'
                                        : '';

                                    $tpl->filter_controls->filter_control->checkbox->item->assign("[KEY]",        $key);
                                    $tpl->filter_controls->filter_control->checkbox->item->assign("[VALUE]",      $checkbox_value);
                                    $tpl->filter_controls->filter_control->checkbox->item->assign("[TITLE]",      $checkbox_title);
                                    $tpl->filter_controls->filter_control->checkbox->item->assign("[ATTR]",       $attributes_str);
                                    $tpl->filter_controls->filter_control->checkbox->item->assign("[IS_CHECKED]", $is_checked);
                                    $tpl->filter_controls->filter_control->checkbox->item->assign("[IS_ACTIVE]",  $is_active);
                                    $tpl->filter_controls->filter_control->checkbox->item->reassign();
                                }
                            }
                            break;

                        case 'number' :
                            if ( ! empty($filter['title'])) {
                                $tpl->filter_controls->filter_control->number->title->assign('[TITLE]', $filter['title']);
                            }

                            $tpl->filter_controls->filter_control->number->assign("[KEY]",         $key);
                            $tpl->filter_controls->filter_control->number->assign("[VALUE_START]", $control_value[0] ?? '');
                            $tpl->filter_controls->filter_control->number->assign("[VALUE_END]",   $control_value[1] ?? '');
                            $tpl->filter_controls->filter_control->number->assign("[ATTR]",        $attributes_str);
                            break;

                        case 'date' :
                            if ( ! empty($filter['title'])) {
                                $tpl->filter_controls->filter_control->date->title->assign('[TITLE]', $filter['title']);
                            }

                            $tpl->filter_controls->filter_control->date->assign("[KEY]",         $key);
                            $tpl->filter_controls->filter_control->date->assign("[VALUE_START]", $control_value[0] ?? '');
                            $tpl->filter_controls->filter_control->date->assign("[VALUE_END]",   $control_value[1] ?? '');
                            $tpl->filter_controls->filter_control->date->assign("[ATTR]",        $attributes_str);
                            break;

                        case 'datetime' :
                            if ( ! empty($filter['title'])) {
                                $tpl->filter_controls->filter_control->datetime->title->assign('[TITLE]', $filter['title']);
                            }

                            $tpl->filter_controls->filter_control->datetime->assign("[KEY]",         $key);
                            $tpl->filter_controls->filter_control->datetime->assign("[VALUE_START]", $control_value[0] ?? '');
                            $tpl->filter_controls->filter_control->datetime->assign("[VALUE_END]",   $control_value[1] ?? '');
                            $tpl->filter_controls->filter_control->datetime->assign("[ATTR]",        $attributes_str);
                            break;

                        case 'select' :
                            $data    = $filter['data'] ?? [];
                            $options = ['' => ''] + $data;

                            if ( ! empty($filter['title'])) {
                                $tpl->filter_controls->filter_control->select->title->assign('[TITLE]', $filter['title']);
                            }

                            $tpl->filter_controls->filter_control->select->assign("[KEY]",  $key);
                            $tpl->filter_controls->filter_control->select->assign("[ATTR]", $attributes_str);
                            $tpl->filter_controls->filter_control->select->fillDropDown("filter-[RESOURCE]-[KEY]", $options, $control_value);
                            break;
                    }

                    $tpl->filter_controls->filter_control->assign("[#]",    $key);
                    $tpl->filter_controls->filter_control->assign('[TYPE]', $filter['type']);
                    $tpl->filter_controls->filter_control->reassign();
                }
            }
        }


        if ( ! empty($this->table['columns'])) {
            foreach ($this->table['columns'] as $key => $column) {
                if (is_array($column) && ! empty($column['show'])) {
                    if ( ! empty($column['sorting'])) {
                        if (isset($this->session->table->order) && $this->session->table->order == $key + 1) {
                            if ($this->session->table->order_type == "asc") {
                                $tpl->header->cell->sort->touchBlock('order_asc');
                            } elseif ($this->session->table->order_type == "desc") {
                                $tpl->header->cell->sort->touchBlock('order_desc');
                            }
                        }

                        if ( ! empty($column['attr']) && ! empty($column['attr']['width'])) {
                            $tpl->header->cell->sort->assign('<th', "<th width=\"{$column['attr']['width']}\"");
                        }

                        $tpl->header->cell->sort->assign('[COLUMN_NUMBER]', ($key + 1));
                        $tpl->header->cell->sort->assign('[CAPTION]',       $column['title'] ?? '');

                    } else {
                        if ( ! empty($column['attr']) && ! empty($column['attr']['width'])) {
                            $tpl->header->cell->no_sort->assign('<th', "<th width=\"{$column['attr']['width']}\"");
                        }
                        $tpl->header->cell->no_sort->assign('[CAPTION]', $column['title'] ?? '');
                    }


                    if ( ! empty($this->table['show']) && ! empty($this->table['show']['lineNumbers'])) {
                        $tpl->header->touchBlock('header_number');
                    }

                    $tpl->header->cell->reassign();
                }
            }
        }


        if ( ! empty($this->table['records'])) {
            $row_index  = 1;
            $row_number = ! empty($this->table['currentPage']) &&
                          ! empty($this->table['recordsPerPage']) &&
                          $this->table['currentPage'] > 1
                ? (($this->table['currentPage'] - 1) * $this->table['recordsPerPage']) + 1
                : 1;

            foreach ($this->table['records'] as $row) {
                if (is_array($row) && ! empty($row['cells'])) {
                    $row_id = ! empty($row['cells']['id']) && ! empty($row['cells']['id']['value'])
                        ? $row['cells']['id']['value']
                        : 0;

                    $tpl->row->assign('[ID]', $row_id);

                    if ( ! empty($this->table['show']) && ! empty($this->table['show']['lineNumbers'])) {
                        $tpl->row->row_number->assign('[#]', $row_number);
                    }

                    if ( ! empty($this->table['recordsEditUrl']) &&
                        ($this->checkAcl($this->table['resource'], 'edit_all') ||
                         $this->checkAcl($this->table['resource'], 'edit_owner') ||
                         $this->checkAcl($this->table['resource'], 'read_all') ||
                         $this->checkAcl($this->table['resource'], 'read_owner'))
                    ) {

                        $edit_url = $this->replaceTCOL($row, $this->table['recordsEditUrl']);
                        $row['attr']['class'] = isset($row['attr']['class'])
                            ? $row['attr']['class'] .= ' edit-row'
                            : 'edit-row';

                        if (strpos($edit_url, 'javascript:') === 0) {
                            $row['attr']['onclick'] = isset($row['attr']['onclick'])
                                ? $row['attr']['onclick'] .= ' ' . substr($edit_url, 11)
                                : substr($edit_url, 11);

                        } else {
                            $edit_url = str_replace('?', '#', $edit_url);
                            $row['attr']['onclick'] = isset($row['attr']['onclick'])
                                ? $row['attr']['onclick'] .= " load('{$edit_url}');"
                                : "load('{$edit_url}');";
                        }
                    }

                    foreach ($this->table['columns'] as $column) {
                        if (is_array($column) &&
                            ! empty($column['show']) &&
                            ! empty($column['type']) &&
                            ! empty($column['field'])
                        ) {
                            $cell  = $row['cells'][$column['field']] ?? [];
                            $value = $cell['value'] ?? '';

                            switch ($column['type']) {
                                case 'text':
                                    $tpl->row->col->default->assign('[VALUE]', htmlspecialchars($value));
                                    break;

                                case 'number':
                                    $value = strrev($value);
                                    $value = (string)preg_replace('/(\d{3})(?=\d)(?!\d*\.)/', '$1;psbn&', $value);
                                    $value = strrev($value);
                                    $tpl->row->col->default->assign('[VALUE]', $value);
                                    break;

                                case 'html':
                                    $tpl->row->col->default->assign('[VALUE]', $value);
                                    break;

                                case 'date':
                                    $date = $value ? date($this->date_mask, strtotime($value)) : '';
                                    $tpl->row->col->default->assign('[VALUE]', $date);
                                    break;

                                case 'datetime':
                                    $date = $value ? date($this->datetime_mask, strtotime($value)) : '';
                                    $tpl->row->col->default->assign('[VALUE]', $date);
                                    break;

                                case 'status':
                                    if ($value == 'Y' || $value == 1) {
                                        $img = "<img src=\"{$this->theme_src}/list/img/lightbulb.png\" alt=\"_tr(вкл)\" title=\"_tr(вкл)/_tr(выкл)\" data-value=\"{$value}\"/>";
                                    } else {
                                        $img = "<img src=\"{$this->theme_src}/list/img/lightbulb_off.png\" alt=\"_tr(выкл)\" title=\"_tr(вкл)/_tr(выкл)\" data-value=\"{$value}\"/>";
                                    }
                                    $tpl->row->col->default->assign('[VALUE]', $img);
                                    break;

                                case 'switch':
                                    $cell['attr']['onclick'] = "event.cancelBubble = true;";

                                    $options = $column['options'] ?? [];
                                    $color   = ! empty($options['color']) ? "color-{$options['color']}" : 'color-primary';
                                    $value_y = $options['value_Y'] ?? 'Y';
                                    $value_n = $options['value_N'] ?? 'N';

                                    $tpl->row->col->switch->assign('[TABLE]',     $options['table'] ?? '');
                                    $tpl->row->col->switch->assign('[FIELD]',     $column['field']);
                                    $tpl->row->col->switch->assign('[NMBR]',      $row_number);
                                    $tpl->row->col->switch->assign('[CHECKED_Y]', $value == $value_y ? 'checked="checked"' : '');
                                    $tpl->row->col->switch->assign('[CHECKED_N]', $value == $value_n ? 'checked="checked"' : '');
                                    $tpl->row->col->switch->assign('[COLOR]',     $color);
                                    $tpl->row->col->switch->assign('[VALUE_Y]',   $value_y);
                                    $tpl->row->col->switch->assign('[VALUE_N]',   $value_n);
                                    break;
                            }

                            // Атрибуты ячейки
                            $attributes = [];
                            if ( ! empty($cell['attr'])) {
                                foreach ($cell['attr'] as $attr => $value) {
                                    if (is_string($attr) && is_string($value)) {
                                        $attributes[] = "$attr=\"{$value}\"";
                                    }
                                }
                            }
                            $implode_attributes = implode(' ', $attributes);
                            $implode_attributes = $implode_attributes ? ' ' . $implode_attributes : '';

                            $tpl->row->col->assign('[ATTR]', $implode_attributes);

                            if (end($this->table['columns']) != $column) $tpl->row->col->reassign();
                        }
                    }


                    if ( ! empty($row['attr'])) {
                        $attribs_string = '';
                        foreach ($row['attr'] as $name => $attr) {
                            if (is_string($name) && is_string($attr)) {
                                $attribs_string .= " {$name}=\"{$attr}\"";
                            }
                        }
                        $tpl->row->assign('<tr', '<tr ' . $attribs_string);
                    }

                    if ( ! empty($this->table['show']) && ! empty($this->table['show']['selectRows'])) {
                        $tpl->row->checkboxes->assign('[ID]', $row_id);
                        $tpl->row->checkboxes->assign('[#]',  $row_index);
                        $row_index++;
                    }

                    $row_number++;

                    $tpl->row->reassign();
                }
            }

        } else {
            $tpl->touchBlock('no_rows');
        }

        return $tpl->render();
    }


    /**
     * @param array $locutions
     * @return void
     */
    public function setLocutions(array $locutions) {

        if ( ! empty($locutions)) {
            foreach ($locutions as $locution => $text) {
                if (isset($this->locutions[$locution])) {
                    $this->locutions[$locution] = $text;
                }
            }
        }
    }


    /**
     * @param string $locution
     * @return string
     */
    private function getLocution(string $locution): string {

        return isset($this->locutions[$locution])
            ? htmlspecialchars($this->locutions[$locution])
            : htmlspecialchars($locution);
    }


    /**
     * Замена TCOL_ на значение указанного поля
     * @param array|Row  $row Данные
     * @param string     $str Строка с TCOL_ вставками
     * @return string
     */
    private function replaceTCOL($row, string $str): string {

        if ( ! empty($row['cells']) && strpos($str, 'TCOL_') !== false) {
            foreach ($row['cells'] as $field => $cell) {
                $value = htmlspecialchars($cell['value'] ?? '');
                $value = addslashes($value);
                $str   = str_replace('[TCOL_' . strtoupper($field) . ']', $value, $str);
                $str   = str_replace('TCOL_' . strtoupper($field), $value, $str);
            }
        }

        return $str;
    }
}
