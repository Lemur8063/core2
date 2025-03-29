<?php

namespace Core2;

require_once("JsonException.php");
require_once("HttpException.php");

use BadMethodCallException;
use ModAdminApi;

class Api extends Acl
{

    /**
     * свойства текущего запроса
     * @var array
     */
    private static array $route = [];

    /**
     * параметры запроса
     * @param array $route
     */
    public function __construct(array $route) {
        parent::__construct();
        self::$route = $route;
    }

    /**
     * @return mixed
     * @throws JsonException
     */
    public function dispatchApi(): mixed {

        $module = self::$route['api'];
        $action = self::$route['action'];

        try {
            if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
                if (!empty(self::$route['query'])) {
                    //возможно это удаление из браузера
                    if (str_starts_with(self::$route['query'], 'mod_') && str_contains(self::$route['query'], '.')) {
                        //удаляют запись из таблицы
                        $route = self::$route;
                        $query = explode('=', $route['query']);
                        $route['params'] = [
                            '_resource' => key($route['params']),
                            '_field' => $query[0],
                            '_value' => $query[1]
                        ];
                        $route['query'] = '';
                        Registry::set('route', $route);
                        require_once 'core2/mod/admin/ModAdminApi.php';
                        $coreController = new ModAdminApi();
                        $out = $coreController->action_index();
                        if (is_array($out)) $out = json_encode($out);
                        return $out;
                    }
                }
            }
            Registry::set('context', array($module, $action));

            if ($module == 'admin') {
                require_once 'core2/mod/admin/ModAdminApi.php';
                $coreController = new ModAdminApi();
                $action         = "action_" . $action;
                if (method_exists($coreController, $action)) {
                    $out = $coreController->$action();

                    if (is_array($out)) {
                        $out = json_encode($out);
                    }

                    return $out;
                } else {
                    throw new BadMethodCallException(sprintf($this->translate->tr("Метод %s не существует"), $action), 404);
                }
            }

            $this->checkModule($module, $action);

            $location      = $this->getModuleLocation($module);
            $modController = "Mod" . ucfirst(strtolower($module)) . "Api";
            $this->requireController($location, $modController);
            $modController = new $modController();
            $action        = "action_" . $action;

            if (method_exists($modController, $action)) {
                $out = $modController->$action();
                if (is_array($out)) {
                    $out = json_encode($out);
                }
                return $out;
            } else {
                throw new BadMethodCallException(sprintf($this->translate->tr("Метод %s не существует"), $action), 404);
            }

        } catch (HttpException $e) {
            return Error::catchJsonException([
                'msg'  => $e->getMessage(),
                'code' => $e->getErrorCode(),
            ], $e->getCode() ?: 500);

        }
    }

    /**
     * Проверка наличия и целостности файла контроллера
     *
     * @param $location - путь до файла
     * @param $apiController - название файла контроллера
     *
     * @throws JsonException
     */
    private function requireController(string $location, string $apiController): void {
        $controller_path = $location . "/" . $apiController . ".php";
        if (!file_exists($controller_path)) {
            throw new JsonException($this->translate->tr("Модуль не найден") . ": " . $apiController, 404);
        }
        $autoload = $location . "/vendor/autoload.php";
        if (file_exists($autoload)) { //подключаем автозагрузку если есть
            require_once $autoload;
        }
        require_once $controller_path; // подлючаем контроллер
        if (!class_exists($apiController)) {
            throw new JsonException($this->translate->tr("Модуль сломан") . ": " . $location, 500);
        }
    }


    /**
     * проверка модуля на доступность
     * @param $module
     * @param $action
     * @return void
     * @throws JsonException
     */
    public function checkModule($module, $action): void {
        if ($action == 'index') {
            $_GET['action'] = "index";

            if ( ! $this->isModuleActive($module)) {
                throw new JsonException(sprintf($this->translate->tr("Модуль %s не существует"), $module), 404);
            }

            if (! $this->checkAcl($module)) {
                throw new JsonException(sprintf($this->translate->tr("Доступ закрыт!"), $module), 403);
            }
        }
        else {
            $submodule_id = $module . '_' . $action;
            if ( ! $this->isModuleActive($submodule_id)) {
                throw new JsonException(sprintf($this->translate->tr("Субмодуль %s не существует"), $action), 404);
            }
            $mods = $this->getSubModule($submodule_id);

            //TODO перенести проверку субмодуля в контроллер модуля
            if ($mods['sm_id'] && !$this->checkAcl($submodule_id)) {
                throw new JsonException(sprintf($this->translate->tr("Доступ закрыт!"), $action), 403);
            }
        }
    }

}