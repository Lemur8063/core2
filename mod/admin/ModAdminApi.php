<?php
require_once __DIR__ . '/../../inc/classes/CommonApi.php';

use Core2\Error;
use Laminas\Session\Container as SessionContainer;

class ModAdminApi extends CommonApi
{
    public function action_index()
    {
        $params = $this->route['params'];
        switch ($_SERVER['REQUEST_METHOD']) {
            case 'DELETE':
                return $this->indexDelete($this->getInputBody());
                break;
            default:
                throw new \Exception('Error: method not handled', 405);
        }
    }

    /**
     * @param $data
     * @return array|bool|string|void|null
     * @throws Exception
     */
    private function indexDelete($data)
    {
        $params = $this->route['params'];
        try {
            if (!isset($params['delete'])) throw new RuntimeException("Не удалось определить местоположение данных.");

            if (empty($data['key']) || empty($data['id'])) throw new RuntimeException("Не удалось определить параметры удаления");

            [$table, $refid] = explode(".", $data['key']);

            if ( ! $table || ! $refid) {
                throw new RuntimeException("Не удалось определить параметры удаления");
            }
            $resource   = $params['delete'];
            $ids        = $data['id'];
            $admin      = false;
            if (strpos($table, 'core_') === 0) {
                //удаление в таблицах ядра
                if (!$this->auth->ADMIN) throw new RuntimeException("Доступ запрещен");
                $admin = true;
            }

            $delete_all   = $this->checkAcl($resource, 'delete_all');
            $delete_owner = $this->checkAcl($resource, 'delete_owner');
            if (!$delete_all && !$delete_owner) throw new RuntimeException("Доступ запрещен");
            $authorOnly   = false;
            if ($delete_owner && !$delete_all) {
                $authorOnly = true;
            }

            if (!$admin) {
                $resource = explode('xxx', $resource);
                $custom = $this->customDelete($resource[0], $ids);
                if ($custom) return $custom;
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
                        throw new Exception($this->translate->tr("Данные не содержат признака автора!"));
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
                throw new Exception($e->getMessage());
            }
            return true;
        } catch (RuntimeException $e) {
            throw new Exception($this->translate->tr($e->getMessage()), 400);
        } catch (Exception $e) {
            return Error::catchJsonException([
                'error' => $e->getMessage()
            ], $e->getCode() ?: 500);
        }
    }

    private function customDelete($resource, array $ids)
    {
        $mod = explode("_", $resource);
        $location      = $this->getModuleLocation($mod[0]); //определяем местоположение модуля
        $modController = "Mod" . ucfirst(strtolower($mod[0])) . "Controller";
        $this->requireController($location, $modController);
        $modController = new $modController();

        $res = false;
        if ($modController instanceof Delete) {
            ob_start();
            $res = $modController->action_delete($resource, implode(",", $ids));
            ob_clean();
        }
        return $res;
    }

    private function requireController($location, $modController) {
        $controller_path = $location . "/" . $modController . ".php";
        if (!file_exists($controller_path)) {
            throw new RuntimeException("Модуль не найден: " . $modController);
        }
        $autoload = $location . "/vendor/autoload.php";
        if (file_exists($autoload)) { //подключаем автозагрузку если есть
            require_once $autoload;
        }
        require_once $controller_path; // подлючаем контроллер
        if (!class_exists($modController)) {
            throw new RuntimeException("Модуль сломан: " . $location);
        }
    }
}