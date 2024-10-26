<?php
require_once __DIR__ . '/../../inc/classes/CommonApi.php';

use Core2\Error;

class ModAdminApi extends CommonApi
{
    public function action_index()
    {
        $params = $this->route['params'];
        switch ($_SERVER['REQUEST_METHOD']) {
            case 'DELETE':
                $this->indexDelete($this->getInputBody());
                break;
            default:
                throw new \Exception('Error: method not handled', 405);
        }
    }

    private function indexDelete($data)
    {
        echo "<PRE>";print_r($data);echo "</PRE>";//die;
        $params = $this->route['params'];
        try {
            if (!isset($params['delete'])) new RuntimeException("Не удалось определить местоположение данных.");

            if (empty($data['key']) || empty($data['id'])) new RuntimeException("Не удалось определить параметры удаления");

            [$table, $refid] = explode(".", $data['key']);

            if ( ! $table || ! $refid) {
                new RuntimeException("Не удалось определить параметры удаления");
            }
            $resource = $params['delete'];
            if (strpos($table, 'core_') === 0) {
                //удаление в таблицах ядра
                if (!$this->auth->ADMIN) new RuntimeException("Доступ запрещен");
            } else {
                $custom = $this->customDelete($resource, $data['id']);
                if ($custom) return $custom;
            }


            if (($this->checkAcl($resource, 'delete_all') || $this->checkAcl($resource, 'delete_owner'))) {
                $authorOnly = false;
                if ($this->checkAcl($resource, 'delete_owner') && ! $this->checkAcl($resource, 'delete_all')) {
                    $authorOnly = true;
                }
                $this->db->beginTransaction();
                try {
                    $is = $this->db->fetchAll("EXPLAIN `$table`");

                    $nodelete = false;
                    $noauthor = true;

                    foreach ($is as $value) {
                        if ($value['Field'] == 'is_deleted_sw') {
                            $nodelete = true;
                        }
                        if ($authorOnly && $value['Field'] == 'author') {
                            $noauthor = false;
                        }
                    }
                    if ($authorOnly) {
                        if ($noauthor) {
                            throw new \Exception($this->translate->tr("Данные не содержат признака автора!"));
                        } else {
                            $auth = new SessionContainer('Auth');
                        }
                    }
                    if ($nodelete) {
                        foreach ($ids as $key) {
                            $where = array($this->db->quoteInto("`$refid` = ?", $key));
                            if ($authorOnly) {
                                $where[] = $this->db->quoteInto("author = ?", $auth->NAME);
                            }
                            $this->db->update($table, array('is_deleted_sw' => 'Y'), $where);
                        }
                    } else {
                        foreach ($ids as $key) {
                            $where = array($this->db->quoteInto("`$refid` = ?", $key));
                            if ($authorOnly) {
                                $where[] = $this->db->quoteInto("author = ?", $auth->NAME);
                            }
                            $this->db->delete($table, $where);
                        }
                    }
                    $this->db->commit();
                } catch (Exception $e) {
                    $this->db->rollback();
                    throw new \Exception($e->getMessage(), 13);
                }
            } else {
                throw new \Exception(911, 13);
            }
        } catch (RuntimeException $e) {
            throw new Exception($this->translate->tr($e->getMessage()), 400);
        } catch (Exception $e) {
            return Error::catchJsonException([
                'error' => $e->getMessage()
            ], $e->getCode() ?: 500);
        }
    }

    private function customDelete($resource, array $id)
    {
        $mod = explode("_", $resource);
        $location      = $this->getModuleLocation($mod[0]); //определяем местоположение модуля
        $modController = "Mod" . ucfirst(strtolower($mod[0])) . "Controller";
        $this->requireController($location, $modController);
        $modController = new $modController();

        $res = false;
        if ($modController instanceof Delete) {
            ob_start();
            $res = $modController->action_delete($resource, implode(",", $id));
            ob_clean();
        }
        return $res;
    }

    private function requireController($location, $modController) {
        $controller_path = $location . "/" . $modController . ".php";
        if (!file_exists($controller_path)) {
            throw new Exception($this->translate->tr("Модуль не найден") . ": " . $modController, 404);
        }
        $autoload = $location . "/vendor/autoload.php";
        if (file_exists($autoload)) { //подключаем автозагрузку если есть
            require_once $autoload;
        }
        require_once $controller_path; // подлючаем контроллер
        if (!class_exists($modController)) {
            throw new Exception($this->translate->tr("Модуль сломан") . ": " . $location, 500);
        }
    }
}