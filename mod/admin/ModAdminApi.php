<?php
require_once __DIR__ . '/../../inc/classes/CommonApi.php';

use Core2\Error;
use Laminas\Session\Container as SessionContainer;
use OpenApi\Attributes as OAT;
use Core2\Switches;

class ModAdminApi extends CommonApi
{
    public function action_index()
    {
        $params = $this->route['params'];
        switch ($_SERVER['REQUEST_METHOD']) {
            case 'DELETE':
                return $this->indexDelete($this->getInputBody());
                break;
            case 'POST':
                if (!empty($params['switch'])) {
                    return $this->indexSwitch($params['switch'], $this->getInputBody());
                }
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
    #[OAT\Delete(
        path: '/admin/index/delete/{resource}',
        operationId: 'deleteRecord',
        description: 'Удаляет одну или несколько записей ресурса',
        tags: ['Админ'],
        parameters: [
            new OAT\Parameter(
                name: 'resource',
                description: 'ижентификатор ресурса, в котором происходит удаление',
                in: 'path',
                required: true,
                schema: new OAT\Schema(type: 'string')
            )],
        requestBody: new OAT\RequestBody(
            required: true, description: 'ключ удаления и id удаляемых записей',
            content: new OAT\MediaType(
                mediaType: 'application/x-www-form-urlencoded',
                schema: new OAT\Schema(
                    type: 'object',
                    required: ['key', 'id'],
                    properties: [
                        new OAT\Property(property: 'key', type: 'string', title: 'Ключ удаления'),
                        new OAT\Property(property: 'id', type: 'array', title: 'id записей для удаления',
                            items: new OAT\Items(type: 'integer')
                        )
                    ]
                )
            )
        ),
        responses: [
            new OAT\Response(
                response: 200,
                description: 'OK',
            ),
            new OAT\Response(
                response: 400,
                description: 'Ошибка удаления',
            )
        ]
    )]
    private function indexDelete($data)
    {
        $params = $this->route['params'];
        try {
            if (!isset($params['delete'])) throw new RuntimeException("Не удалось определить местоположение данных.");

            if (empty($data['key']) || empty($data['id'])) throw new RuntimeException("Не удалось определить параметры удаления");

            [$table, $refid] = explode(".", $data['key']);

            if ( ! $table || ! $refid) {
                throw new RuntimeException("Не удалось определить параметры удаления!");
            }
            $resource   = $params['delete'];
            $ids        = $data['id'];
            $admin      = false;
            if (strpos($table, 'core_') === 0) {
                //удаление в таблицах ядра
                if (!$this->auth->ADMIN) throw new RuntimeException("Доступ запрещен");
                $admin = true;
            }

            if (!$admin) {
//                $resource = explode('xxx', $resource);
                //кастомное удаление само должно проверять права на удаление
                $custom = $this->customDelete($resource, $ids);
                if ($custom) return $custom;
            }

            $delete_all   = $this->checkAcl($resource, 'delete_all');
            $delete_owner = $this->checkAcl($resource, 'delete_owner');
            if (!$delete_all && !$delete_owner) throw new RuntimeException("Удаление запрещено");
            $authorOnly   = false;
            if ($delete_owner && !$delete_all) {
                $authorOnly = true;
            }
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
                    throw new RuntimeException("Данные не содержат признака автора!");
                } else {
                    $auth = new SessionContainer('Auth');
                }
            }

            foreach ($ids as $key) {
                $where = array($this->db->quoteInto("`$refid` = ?", $key));
                if ($authorOnly) {
                    $where[] = $this->db->quoteInto("author = ?", $auth->NAME);
                }
                if ($nodelete) $this->db->update($table, array('is_deleted_sw' => 'Y'), $where);
                else $this->db->delete($table, $where);
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

    /**
     * Проверка модуля на реализацию собственного удаления
     * @param $resource
     * @param array $ids
     * @return array|bool
     * @throws Exception
     */
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

    /**
     * @param $location
     * @param $modController
     * @return void
     * @throws Exception
     */
    private function requireController($location, $modController) {
        $controller_path = $location . "/" . $modController . ".php";
        if (!file_exists($controller_path)) {
            throw new Exception(sprintf($this->translate->tr("Модуль не найден: %s"), $modController), 400);
        }
        $autoload = $location . "/vendor/autoload.php";
        if (file_exists($autoload)) { //подключаем автозагрузку если есть
            require_once $autoload;
        }
        require_once $controller_path; // подлючаем контроллер
        if (!class_exists($modController)) {
            throw new RuntimeException(sprintf($this->translate->tr("Модуль сломан: %s"), $location));
        }
    }

    #[OAT\Post(
        path: '/admin/index/switch/{resource}',
        operationId: 'switchRecord',
        description: 'Переключает признак активности записи',
        tags: ['Админ'],
        parameters: [
            new OAT\Parameter(
                name: 'resource',
                description: 'ижентификатор ресурса, в котором происходит переключение',
                in: 'path',
                required: true,
                schema: new OAT\Schema(type: 'string')
            )],
        requestBody: new OAT\RequestBody(
            required: true,
            description: 'данные для переключения',
            content: new OAT\MediaType(
                mediaType: 'application/x-www-form-urlencoded',
                schema: new OAT\Schema(
                    type: 'object',
                    required: ['data', 'is_active', 'value'],
                    properties: [
                        new OAT\Property(property: 'data', type: 'string', title: 'поле базы данных с признаком для переключения'),
                        new OAT\Property(property: 'is_active', type: 'string', title: 'хначение переключателя'),
                        new OAT\Property(property: 'value', type: 'string', title: 'id записи, для которой происходит переключение')
                    ]
                )
            )
        ),
        responses: [
            new OAT\Response(
                response: 200,
                description: 'OK',
            ),
            new OAT\Response(
                response: 400,
                description: 'Ошибка переключения',
            )
        ]
    )]
    private function indexSwitch($resource, $data)
    {

        try {
            if (empty($data['data']) || empty($data['value']) || empty($data['is_active'])) {
                throw new RuntimeException("Не хватает данных для переключения");
            }
            [$table, $refid, $id] = explode(".", $data['data']);
            $admin      = false;
            if (strpos($table, 'core_') === 0) {
                //таблица ядра
                if (!$this->auth->ADMIN) throw new RuntimeException("Доступ запрещен");
                $admin = true;
            }


            if (!$admin) {
                $custom = $this->customSwitch($resource, $data['data'], $data['value'], $data['is_active']);
                if ($custom) {
                    if ($custom === true) return ['status' => "ok"];
                    return $custom;
                }
            }



            preg_match('/[a-z|A-Z|0-9|_|-]+/', trim($table), $arr);
            $table_name = $arr[0];
            $is_active = $refid;
            if (!$id && !empty($data['value'])) {
                $id = (int) $data['value'];
            }
            $keys_list = $this->db->fetchRow("SELECT * FROM `{$table_name}` LIMIT 1");
            $keys = array_keys($keys_list);
            $key = $keys[0];
            $where = $this->db->quoteInto($key . "= ?", $id);
            $this->db->update($table_name, array($is_active => $data['is_active']), $where);
            //очистка кеша активности по всем записям таблицы
            // используется для core_modules
            $this->cache->clearByTags(["is_active_" . $table_name]);

            return ['status' => "ok"];
        } catch (RuntimeException $e) {
            throw new Exception($this->translate->tr($e->getMessage()), 400);
        } catch (Exception $e) {
            return ['status' => $e->getMessage()];
        }
    }

    /**
     *
     * @param $resource
     * @param $table_field
     * @param $refid
     * @param $value
     * @return array|bool
     * @throws Exception
     */
    private function customSwitch($resource, $table_field, $refid, $value)
    {
        $mod = explode("_", $resource);
        $location      = $this->getModuleLocation($mod[0]);
        $modController = "Mod" . ucfirst(strtolower($mod[0])) . "Controller";

        $this->requireController($location, $modController);
        $controller = new $modController();

        if ($controller instanceof Switches) {
            try {
                ob_start();
                $result = $controller->action_switch($resource, $table_field, $refid, $value);
                ob_clean();
            } catch (\Exception $e) {
                $result = [ 'status' => $e->getMessage() ];
            }
            return $result;
        }
        return false;
    }
}