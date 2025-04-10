<?php

require_once 'classes/Common.php';
require_once 'classes/class.list.php';
require_once 'classes/class.edit.php';
require_once 'classes/class.tab.php';
require_once 'classes/Alert.php';
require_once 'Interfaces/File.php';

require_once DOC_ROOT . "core2/mod/admin/classes/modules/InstallModule.php";
require_once DOC_ROOT . "core2/mod/admin/classes/settings/Settings.php";
require_once DOC_ROOT . "core2/mod/admin/classes/modules/Modules.php";
require_once DOC_ROOT . "core2/mod/admin/classes/roles/Roles.php";
require_once DOC_ROOT . "core2/mod/admin/classes/enum/Enum.php";
require_once DOC_ROOT . "core2/mod/admin/classes/audit/Audit.php";
require_once DOC_ROOT . "core2/mod/admin/classes/monitoring/Monitoring.php";
require_once DOC_ROOT . 'core2/inc/classes/Panel.php';

use Laminas\Session\Container as SessionContainer;
use Core2\Mod\Admin;
use Core2\InstallModule as Install;


/**
 * @property Core2\Model\Enum         $dataEnum
 * @property Core2\Model\Modules      $dataModules
 * @property Core2\Model\SubModules   $dataSubModules
 * @property Core2\Model\Roles        $dataRoles
 * @property Core2\Model\Users        $dataUsers
 * @property Core2\Model\UsersProfile $dataUsersProfile
 * @property Core2\Model\Settings     $dataSettings
 * @property Core2\Model\Session      $dataSession
 * @property ModProfileApi            $apiProfile
 */
class CoreController extends Common implements File {

    const RP = '187777f095b3006d4dbdf3b3548ac407';
    protected $tpl   = '';
    protected $theme = 'default';


    /**
     * CoreController constructor.
     */
	public function __construct() {
		parent::__construct();

        $this->module = 'admin';
        $this->path   = 'core2/mod/';
        $this->path  .= ! empty($this->module) ? $this->module . "/" : '';

        if ( ! empty($this->config->theme)) {
            $this->theme = $this->config->theme;
        }
	}


    /**
     * @param string $k
     * @param array  $arg
     */
    public function __call($k, $arg) {
		if ( ! method_exists($this, $k)) {
            return;
        }
	}


	/**
	 * @param string $var
	 * @param mixed  $value
	 */
	public function setVars($var, $value) {
		$this->$var = $value;
	}


    /**
     * @throws Exception
     * @return string
     */
	public function action_index() {

        if ($_SERVER['REQUEST_METHOD'] == 'PUT') {
            parse_str(file_get_contents("php://input"), $put_vars);
            if ( ! empty($put_vars['exit'])) {
                $this->closeSession();
                return;
            }
        }
       
        if ( ! $this->auth->ADMIN) {
            throw new Exception(911);
        }
        session_write_close();

        if (isset($_GET['data'])) {
            try {
                switch ($_GET['data']) {
                    case 'clear_cache':
                        $this->cache->clearByNamespace($this->cache->getOptions()->getNamespace());
                        header('Content-type: application/json; charset="utf-8"');
                        return json_encode(['status' => 'success']);
                }

            } catch (Exception $e) {
                return json_encode([
                    'status'        => 'error',
                    'error_message' => $e->getMessage()
                ]);
            }
        }


        $tab = new tabs('mod');
        $tab->beginContainer($this->_("События аудита"));

        $this->printJsModule('admin', '/assets/js/admin.index.js');

        try {
            $changedMods = $this->checkModulesChanges();
            if (empty($changedMods)) {
                Alert::memory()->info($this->_("Система работает в штатном режиме."));
            } else {
				Alert::memory()->danger(implode(", ", $changedMods), $this->_("Обнаружены изменения в файлах модулей:"));
            }
            if ( ! $this->moduleConfig->database ||
                 ! $this->moduleConfig->database->admin ||
                 ! $this->moduleConfig->database->admin->username
            ) {
				Alert::memory()->warning("Задайте параметр 'database.admin.username' в conf.ini модуля 'admin'", $this->_("Не задан администратор базы данных"));
            }

        } catch (Exception $e) {
			Alert::memory()->danger($e->getMessage(), $this->_("Ошибка"));
        }

        echo Alert::get();


        // Кнопка очистки кэша
        $btn_title = $this->_('Очистить кэш');
        echo "<input class=\"button\" type=\"button\" value=\"{$btn_title}\" onclick=\"AdminIndex.clearCache()\"/>";

        $tab->endContainer();
	}


    /**
     * @return void|string
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws Exception
     */
	public function action_modules() {

        if ( ! $this->auth->ADMIN) {
            throw new Exception(911);
        }

        if (isset($_GET['data'])) {
            try {
                switch ($_GET['data']) {
                    case 'cache_clean':
                        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                            throw new Exception('Некорректный http метод');
                        }
                        header("Content-Type: application/json");
                        (new \Core2\Modules())->gitlabClean();
                        return json_encode([
                            'status' => 'success'
                        ]);
                        break;
                }

                throw new Exception($this->_('Некорректный адрес запроса'));

            } catch (Exception $e) {
                header("Content-Type: application/json");
                return json_encode([
                    'status'        => 'error',
                    'error_message' => $e->getMessage()
                ]);
            }
        }

        if (isset($_GET['page'])) {
            try {
                switch ($_GET['page']) {
                    case 'table_gitlab':
                        return (new \Core2\Modules())->getTableGitlab();
                        break;
                }

                throw new Exception($this->_('Некорректный адрес запроса'));

            } catch (Exception $e) {
                return Alert::danger($e->getMessage());
            }
        }


        //проверка наличия обновлений для модулей
        if ($_SERVER['REQUEST_METHOD'] == 'PUT') {
            header('Content-type: application/json; charset="utf-8"');
            parse_str(file_get_contents("php://input"), $put_vars);
            $mods = array();
            if (!empty($put_vars['checkModsUpdates'])) {
                session_write_close();
                try {
                    $install = new Install();
                    $ups = $install->checkInstalledModsUpdates();
                    foreach ($put_vars['checkModsUpdates'] as $module_id => $m_id) {
                        if (!empty($ups[$module_id])) {
                            $ups[$module_id]['m_id'] = $m_id;
                            $mods[] = $ups[$module_id];
                        }
                    }
                } catch (Exception $e) {
                    $mods[] = $e->getMessage();
                }
            }
            return json_encode($mods);
        }

        //список модулей из репозитория
        if (isset($_GET['getModsListFromRepo'])) {
            $install = new Install();
            session_write_close();
            $install->getHTMLModsListFromRepo((int) $_GET['getModsListFromRepo']);
            return;
        }
        //скачивание архива модуля
        if (!empty($_GET['download_mod'])) {
            $install = new Install();
            $install->downloadAvailMod($_GET['download_mod']);
            return;
        }



        $base_url = "index.php?module=admin&action=modules";
        $mods     = new Core2\Modules();
        $panel    = new \Panel('tab');
        $panel->setTitle($this->_("Модули"));

        ob_start();
        /* Обновление файлов модуля */
        if ( ! empty($_POST['refreshFilesModule'])) {
            $install = new Install();
            echo $install->mRefreshFiles($_POST['refreshFilesModule']);

        /* Обновление модуля */
        } elseif ( ! empty($_POST['updateModule'])) {
            $install = new Install();
            echo $install->checkModUpdates($_POST['updateModule']);

        // Деинсталяция модуля
        } elseif (isset($_POST['uninstall'])) {
            $install = new Install();
            echo $install->mUninstall($_POST['uninstall']);

        // Инсталяция модуля
        } elseif ( ! empty($_POST['install'])) {
            $install = new Install();
            echo $install->mInstall($_POST['install']);

        // Инсталяция модуля из репозитория
        } elseif ( ! empty($_POST['install_from_repo'])) {
            $install = new Install();
            echo $install->mInstallFromRepo($_POST['repo'], $_POST['install_from_repo']);

        } else {
            $this->printJs("core2/mod/admin/assets/js/mod.js");
            $this->printJs("core2/mod/admin/assets/js/gl.js");

            if (isset($_GET['edit'])) {
                if (empty($_GET['edit'])) {
                    $panel->setTitle($this->_("Добавление модуля"));
                    echo $mods->getEditInstalled();

                } else {
                    $module = $this->dataModules->getRowById((int)$_GET['edit']);

                    if (empty($module)) {
                        return Alert::danger($this->_('Указанный модуль не найден'));
                    }

                    $panel->setTitle(strip_tags($module->m_name), $module->module_id, $base_url);
                    $count_submodules = $this->dataSubModules->getCountByModuleId((int)$_GET['edit']);

                    $base_url .= "&edit={$module->m_id}";
                    $panel->addTab($this->_("Модуль"),                          'module',     $base_url);
                    $panel->addTab($this->_("Субмодули ({$count_submodules})"), 'submodules', $base_url);


                    $base_url .= "&tab=" . $panel->getActiveTab();
                    switch ($panel->getActiveTab()) {
                        case 'module':
                            echo $mods->getEditInstalled((int)$module->m_id);
                            break;

                        case 'submodules':
                            if (isset($_GET['editsub'])) {
                                echo $mods->getEditSubmodule((int)$module->m_id, (int)$_GET['editsub']);
                            }

                            echo $mods->getListSubmodules((int)$module->m_id);
                            break;
                    }
                }

            }
            else {
                $panel->addTab($this->_("Установленные модули"), 'install',   $base_url);
                $panel->addTab($this->_("Доступные модули"),	 'available', $base_url);

                switch ($panel->getActiveTab()) {
                    case 'install':
                        $mods->getListInstalled();
                        break;

                    case 'available':
                        if (isset($_GET['add_mod'])) {
                            $mods->getAvailableEdit((int) $_GET['add_mod']);
                        }

                        $mods->getAvailable();
                        $mods->getRepoModules();
                        break;
                }
            }
        }

        $panel->setContent(ob_get_clean());
        return $panel->render();
	}


    /**
     * Обновление последовательности записей
     * @return string
     */
	public function action_seq(): string {

        if (empty($_POST['id']) || ! is_string($_POST['id'])) {
            return '{}';
        }

		$this->db->beginTransaction();
		try {
            $resource       = $_POST['id'];
            $session_search = new SessionContainer('Search');

            $search_list = $session_search?->{"main_{$resource}"};

            if ($search_list && ! empty($search_list['order'])) {
                throw new \Exception($this->translate->tr("Ошибка! Сначала переключитесь на сортировку по умолчанию."));
            }

            // Получение названия таблицы из сессии, если это возможно
            $session_table = new SessionContainer($resource);
            $session_list  = new SessionContainer('List');

            if ($session_table?->table?->name) {
                $table_name = $session_table->table->name;

            } elseif ( ! empty($session_list->{$resource}) &&
                       ! empty($session_list->{$resource}->deleteKey)
            ) {
                $table_name = explode('.', $session_list->{$resource}->deleteKey);
                $table_name = $table_name ? current($table_name) : null;
            }

            if (empty($table_name)) {
                preg_match('/[a-z|A-Z|0-9|_|-]+/', trim($_POST['tbl']), $arr);
                $table_name = $arr[0];
            }

            $id_name          = $table_name == 'core_modules' ? 'm_id' : "id";
            $table_name_quote = $this->db->quoteIdentifier($table_name);
            $where            = $this->db->quoteInto("{$id_name} IN (?)", $_POST['data']);

			$rows = $this->db->fetchPairs("
                SELECT {$id_name} AS id, 
                       seq 
                FROM {$table_name_quote}
                WHERE $where 
                ORDER BY seq ASC
            ");

			if ($rows) {
				$rows_seq = array_values($rows);

				foreach ($_POST['data'] as $k => $row_id) {
					$where = $this->db->quoteInto("{$id_name} = ?", $row_id);
                    $this->db->update($table_name, ['seq' => $rows_seq[$k]], $where);
                }
			}

			$this->db->commit();
            return '{}';

		} catch (Exception $e) {
			$this->db->rollback();
            return json_encode(['error' => $e->getMessage()]);
        }
    }


	/**
     * Пользователи
	 * @throws Exception
     * @return string
	 */
	public function action_users(): string {

        require_once __DIR__ . "/../mod/admin/classes/users/View.php";
        require_once __DIR__ . "/../mod/admin/classes/users/Users.php";
        require_once __DIR__ . "/../mod/admin/classes/users/User.php";

	    if ( ! $this->auth->ADMIN) {
		    throw new Exception(911);
        }


        if (isset($_GET['data'])) {
            try {
                switch ($_GET['data']) {
                    // Войти под пользователем
                    case 'login_user':
                        $users = new Admin\Users\Users();
                        $users->loginUser($_POST['user_id']);

                        return json_encode([
                            'status' => 'success',
                        ]);
                        break;
                }

            } catch (Exception $e) {
                return json_encode([
                    'status'        => 'error',
                    'error_message' => $e->getMessage(),
                ]);
            }
        }

        $app   = "index.php?module=admin&action=users";
		$view  = new Admin\Users\View();
        $panel = new Panel();

        $content = '';

        try {
            if (isset($_GET['edit'])) {
                if (empty($_GET['edit'])) {
                    $panel->setTitle($this->_("Создание нового пользователя"), '', $app);
                    $content = $view->getEdit($app);
                } else {
                    $user = new Admin\Users\User($_GET['edit']);
                    $panel->setTitle($user->u_login, $this->_('Редактирование пользователя'), $app);
                    $content = $view->getEdit($app, $user);
                }


            } else {
                $panel->setTitle($this->_("Справочник пользователей системы"));
                $content = $view->getList($app);
            }

        } catch (\Exception $e) {
            $content = Alert::danger($e->getMessage(), 'Ошибка');
        }

        $panel->setContent($content);
        return $panel->render();
	}


	/**
     * Субмодуль Конфигурация
	 * @throws Exception
     * @return void
	 */
	public function action_settings () {
		if (!$this->auth->ADMIN) throw new Exception(911);
        $app = "index.php?module=admin&action=settings";
        $settings = new Core2\Settings();
        $tab = new tabs('settings');
        $tab->addTab($this->translate->tr("Настройки системы"), 			$app, 130);
        $tab->addTab($this->translate->tr("Дополнительные параметры"), 		$app, 180);
        $tab->addTab($this->translate->tr("Персональные параметры"), 		$app, 180);

        $title = $this->translate->tr("Конфигурация");
        $tab->beginContainer($title);

        if ($tab->activeTab == 1) {
            if (!empty($_GET['edit'])) {
                $settings->edit(-1);
            }
            $settings->stateSystem();
        } elseif ($tab->activeTab == 2) {
            if (isset($_GET['edit'])) {
                if ($_GET['edit']) {
                    $settings->edit($_GET['edit']);
                } else {
                    $settings->create();
                }
            }
            $settings->stateAdd();
        } elseif ($tab->activeTab == 3) {
            if (isset($_GET['edit'])) {
                if ($_GET['edit']) {
                    $settings->edit($_GET['edit']);
                } else {
                    $settings->create();
                }
            }
            $settings->statePersonal();
        }
        $tab->endContainer();
	}


    /**
     * @throws Exception
     */
    public function action_welcome () {

		if (!empty($_POST['sendSupportForm'])) {
			if (isset($_POST['supportFormModule'])) {
				$supportFormModule = trim(strip_tags(stripslashes($_POST['supportFormModule'])));
			} else {
				$supportFormModule = '';
			}
			if (isset($_POST['supportFormMessage'])) {
				$supportFormMessage = trim(stripslashes($_POST['supportFormMessage']));
			} else {
				$supportFormMessage = '';
			}

			header('Content-type: application/json; charset="utf-8"');

			try {
				if (empty($supportFormMessage)) {
					throw new Exception($this->translate->tr('Введите текст сообщения.'));
				}

				$dataUser = $this->dataUsers->getUserById($this->auth->ID);

				if ($dataUser) {
					$to = $this->getSetting('feedback_email');
					$cc = $this->getSetting('feedback_email_cc');

                    if (empty($to)) {
                        $to = $this->getSetting('admin_email');
                    }

					if (empty($to)) {
                        throw new Exception($this->translate->tr('Не удалось отправить сообщение.'));
                    }

					$html = "<pre>{$supportFormMessage}</pre>";
					$html .= '<hr/><small>';
					$html .= '<b>Хост:</b> ' . $_SERVER['HTTP_HOST'];
					$html .= '<br/><b>Модуль:</b> ' . $supportFormModule;
					$html .= '<br/><b>Пользователь:</b> ' . $dataUser['lastname'] . ' ' . $dataUser['firstname'] . ' ' . $dataUser['middlename'] . ' (Логин: ' . $dataUser['u_login'] . ')';
					$html .= '</small>';

                    $email = $this->createEmail();
                    if (isset($_FILES) &&
                        ! empty($_FILES['video-blob']) &&
                        ! empty($_FILES['video-blob']['tmp_name'])
                    ) {
                        $file = $_FILES['video-blob'];
                        $email->attacheFile(file_get_contents($file['tmp_name']), "feedback.webm", $file['type'], $file['size']);
                    }

                    if ( ! empty($dataUser['email'])) {
                        $email->from($dataUser['email']);
                    }
                    if ( ! empty($cc)) {
                        $email->cc($cc);
                    }

                    $result = $email->to($to)
                        ->subject("Запрос обратной связи от {$_SERVER['HTTP_HOST']} (модуль $supportFormModule).")
                        ->body($html)
                        ->send();

                    if (isset($result['error'])) {
                        throw new Exception($this->translate->tr('Не удалось отправить сообщение'));
                    }

                    $this->apiProfile->sendFeedback($supportFormMessage, [
                        'location_module' => $supportFormModule,
                    ]);
				}
				echo '{}';
			} catch (Exception $e) {
                \Core2\Error::catchJsonException([
                    'error_code'    => $e->getCode(),
                    'error_message' => $e->getMessage()
                ], 500);
            }

            return;
		}

        if ( ! empty($_GET['error_front'])) {
            $request_raw = file_get_contents('php://input', 'r');
            $errors      = $request_raw ? json_decode($request_raw, true) : [];

            if ($errors && is_array($errors)) {
                $i     = 1;
                $limit = 100;
                foreach ($errors as $error) {
                    if ($i >= $limit) {
                        break;
                    }

                    if ( ! empty($error['url']) && is_string($error['url']) && mb_strlen($error['url']) > 255) {
                        $error['url'] = mb_substr($error['url'], 0, 255);
                    }
                    if ( ! empty($error['type']) && is_string($error['type']) && mb_strlen($error['type']) > 100) {
                        $error['type'] = mb_substr($error['type'], 0, 100);
                    }

                    $level = 'error';

                    if ( ! empty($error['level']) &&
                         is_string($error['level']) &&
                         in_array($error['level'], ['warning', 'info', 'error'])
                    ) {
                        $level = $error['level'];
                    }

                    $error_type = ! empty($error['type']) && is_string($error['type']) ? $error['type'] : 'error';

                    $this->log->{$level}($error_type, [
                        'login'  => $this->auth->NAME,
                        'url'    => $error['url'] ?? null,
                        'error'  => $error['error'] ?? null,
                        'client' => $error['client'] ?? null,
                    ]);

                    $i++;
                }
            }

            return;
        }

		if (file_exists('mod/home/welcome.php')) {
			require_once 'mod/home/welcome.php';
		}
	}


    /**
     * Перехват запросов на отображение файла
     * @param $context - контекст отображения (fileid, thumbid, tfile)
     * @param $table - имя таблицы, с которой связан файл
     * @param $id - id файла
     * @return bool
     */
    public function action_filehandler($context, $table, int $id) {

        // Используется для случая когда не нужно получать список уже загруженных файлов
        if ($table == 'core_users') {
            echo json_encode([]);
            return true;
        }
    }


	/**
	 * Форма обратной связи
	 * @return mixed|string
	 */
	public function feedbackForm() {

		$mods = $this->db->fetchAll("
			SELECT m.module_id,
				   m.m_name,
				   sm.sm_key,
				   sm.sm_name
			FROM core_modules AS m
			    LEFT JOIN core_submodules AS sm ON sm.m_id = m.m_id AND sm.visible = 'Y'
			WHERE m.visible = 'Y' AND m.is_public = 'Y'
			ORDER BY sm.seq
        ");

		$selectMods = '';
		if (count($mods)) {
			$currentMod = array();

			foreach ($mods as $key => $value) {
				if (!$value['module_id']) continue;
				if ($value['sm_key'] && !$this->checkAcl($value['module_id'] . '_' . $value['sm_key'], 'access')) {
					continue;
				} elseif (!$this->checkAcl($value['module_id'], 'access')) {
					continue;
				}

                $value['m_name']  = strip_tags($value['m_name']);
                $value['sm_name'] = strip_tags($value['sm_name'] ?? '');

				if (!isset($currentMod[$value['m_name']])) {
					$currentMod[$value['m_name']] = array();
				}
				if ($value['sm_key']) {
					$currentMod[$value['m_name']][] = $value['sm_name'];
				}
			}

			foreach ($currentMod as $key => $value) {
				$selectMods .= '<option class="feedBackOption" value="' . $key . '">' . $key . '</option>';
				foreach ($value as $sub) {
					$valueSmMod = $key . '/' . $sub;
					$selectMods .= '<option value="' . $valueSmMod . '">&nbsp; &nbsp;' . $sub . '</option>';
				}
			}
		}
		$this->printJs("core2/mod/admin/assets/js/feedback.js", true);
		$this->printJs("core2/mod/admin/assets/js/capture.js", true);
		require_once 'classes/Templater2.php';
		$tpl = new Templater2("core2/mod/admin/assets/html/feedback.html");
		$tpl->assign('</select>', $selectMods . '</select>');
		return $tpl->parse();
	}


	/**
	 * информация о профиле пользователя
	 * @return string
	 */
	public function userProfile() {

		if ($this->auth->NAME !== 'root' && $this->auth->LDAP) {
			require_once 'core2/inc/classes/LdapAuth.php';
			$ldap = new \Core2\LdapAuth();
			$ldap->getLdapInfo($this->auth->NAME);
		}

		$name = $this->auth->FN;
		if (!empty($name)) {
			$name .= ' ' . $this->auth->MN;
		} else {
			$name = $this->auth->NAME;
		}
		if (!empty($name)) $name = '<b>' . $name . '</b>';
		$out = '<div>' . sprintf($this->translate->tr("Здравствуйте, %s"), $name) . '</div>';
		$sLife = (int)$this->getSetting("session_lifetime");
		if (!$sLife) {
			$sLife = ini_get('session.gc_maxlifetime');
		}
		if ($this->config->database->adapter == 'Pdo_Mysql') {
			$res = $this->db->fetchRow("SELECT DATE_FORMAT(login_time, '%d-%m-%Y %H:%i') AS login_time2,
											   ip
										  FROM core_session
										 WHERE user_id = ?
										   AND (NOW() - last_activity > $sLife)=1
										 ORDER BY login_time DESC
										 LIMIT 1", $this->auth->ID);
		} elseif ($this->config->database->adapter == 'pdo_pgsql') {
			$res = $this->db->fetchRow("SELECT DATE_FORMAT(login_time, '%d-%m-%Y %H:%i') AS login_time2,
											   ip
										  FROM core_session
										 WHERE user_id = ?
										   AND EXTRACT(EPOCH FROM (NOW() - last_activity)) > $sLife
										 ORDER BY login_time DESC
										 LIMIT 1", $this->auth->ID);
		}
		if ($res) {
			$out .= '<div>' . sprintf($this->translate->tr("Последний раз Вы заходили %s с IP адреса %s"), '<b>' . $res['login_time2'] . '</b>', '<b>' . $res['ip'] . '</b>') . '</div>';
		}


		// Проверка наличия входящих непрочитаных сообщений
		$out .= $this->apiProfile->getProfileMsg();

		return $out;
	}


	/**
	 * @throws Exception
     * @return void
	 */
	public function action_roles() {
		if (!$this->auth->ADMIN) throw new Exception(911);
		$this->printCss($this->path . "assets/css/role.css");
        $roles = new Core2\Roles();
        $roles->dispatch();
	}


	/**
	 * @throws Exception
     * @return void
	 */
	public function action_enum()
    {
        if (!$this->auth->ADMIN) throw new Exception(911);
        $enum = new Core2\Enum();
        $tab = new tabs('enum');

        $title = $this->_("Справочники");
        if (!empty($_GET['edit'])) {
            $title = $this->_("Редактирование справочника");
        }
        elseif (isset($_GET['new'])) {
            $title = $this->_("Создание нового справочника");
        }
        $tab->beginContainer($title);
        $this->printJs("core2/mod/admin/assets/js/enum.js");
        $this->printJs("core2/mod/admin/assets/js/mod.js");
        if (!empty($_GET['edit'])) {
            $enum = new Core2\Enum((int) $_GET['edit']);
            echo $enum->editEnum();
            $tab->beginContainer(sprintf($this->translate->tr("Перечень значений справочника \"%s\""), $this->dataEnum->find($_GET['edit'])->current()->name));
            if (isset($_GET['newvalue'])) {
                echo $enum->newEnumValue();
            } elseif (!empty($_GET['editvalue'])) {
                echo $enum->editEnumValue((int) $_GET['editvalue']);
            }
            echo $enum->listEnumValues();
            $tab->endContainer();
        } elseif (isset($_GET['new'])) {
            echo $enum->newEnum();
        } else {
            echo $enum->listEnum();
        }
        $tab->endContainer();
	}


	/**
	 * @throws Exception
     * @return void
	 */
	public function action_monitoring() {
		if (!$this->auth->ADMIN) throw new Exception(911);
        try {
            $app = "index.php?module=admin&action=monitoring";
            $monitor = new \Core2\Monitoring();

            $tab = new tabs('admin_monitoring');

            $tab->addTab($this->translate->tr("Активные пользователи"), $app, 170);
            $tab->addTab($this->translate->tr("История посещений"),     $app, 170);
            $tab->addTab($this->translate->tr("Журнал запросов"),       $app, 150);
            $tab->addTab($this->translate->tr("Архив журнала"),         $app, 150);

            $out = "";
            if ($tab->activeTab == 1) {
                if ( ! empty($_GET['kick'])) {
                    $sess = $this->dataSession->find($_GET['kick'])->current();

                    if ($sess->sid) {
                        $sess->logout_time  = new \Zend_Db_Expr('NOW()');
                        $sess->is_kicked_sw = 'Y';
                        $sess->save();
                    }
                }
                $out = $monitor->getOnline();
            }
            elseif ($tab->activeTab == 2) {
                $out = $monitor->getHistory();
            }
            elseif ($tab->activeTab == 3) {
                if (isset($_GET['download'])) {
                    $zip_body = $monitor->downloadJournal();
                    header("Content-Type: application/octet-stream");
                    header("Accept-Ranges: bytes");
                    header("Content-Length: " . strlen($zip_body));
                    header("Content-Disposition: attachment; filename=\"access-log-" . date("Y-m-d-H:i:s") . ".txt.gz");
                    return $zip_body;
                }
                $out = $monitor->getJournal();
            }
            elseif ($tab->activeTab == 4) {
                /* Загрузка файла */
                if (isset($_GET['download'])) {
                    $zip_body = $monitor->downloadArhive($_GET['download']);
                    header("Connection: close");
                    header("Content-type: application/zip");
                    return $zip_body;
                }
                $out = $monitor->getArchive();
            }

            $tab->beginContainer($this->_("Мониторинг"));
            echo $out;
            $tab->endContainer();

        } catch (Exception $e) {
            echo Alert::danger($e->getMessage());
        }
	}


	/**
	 * @throws Exception
     * @return void
	 */
	public function action_audit() {
		if (!$this->auth->ADMIN) throw new Exception(911);
		$app = "index.php?module=admin&action=audit";
        $audit = new \Core2\Audit();
        $tab = new tabs('audit');

        $tab->addTab($this->translate->tr("База данных"), 		    $app, 100);
        $tab->addTab($this->translate->tr("Контроль целостности"),	$app, 150);

        $tab->beginContainer($this->_("Аудит"));

        if ($tab->activeTab == 1) {
            $audit->database();
        }
        elseif ($tab->activeTab == 2) {
            $audit->integrity();
        }
        $tab->endContainer();
	}


	/**
	 *
	 */
	public function action_upload() {
        require_once 'classes/FileUploader.php';

        $upload_handler = new \Core2\Store\FileUploader();

        header('Pragma: no-cache');
        header('Cache-Control: private, no-cache');
        header('Content-Disposition: inline; filename="files.json"');
        header('X-Content-Type-Options: nosniff');

        switch ($_SERVER['REQUEST_METHOD']) {
            case 'HEAD':
            case 'GET':
                $upload_handler->get();
                //$upload_handler->getDb();
                break;
            case 'POST':
                $upload_handler->post();
                break;
            case 'DELETE':
                $upload_handler->delete();
                break;
            default:
                header($_SERVER['SERVER_PROTOCOL'] . ' 405 Method Not Allowed');
        }
	}


	/**
	 * обработка запросов на содержимое файлов
	 */
	public function fileHandler($resource, $context, $table, $id) {
		require_once 'classes/File.php';
		$f = new \Core2\Store\File($resource);
		if ($context == 'fileid') {
			$f->handleFile($table, $id);
		}
		elseif ($context == 'thumbid') {
		    if (!empty($_GET['size'])) {
		        $f->setThumbSize($_GET['size']);
            }
			$f->handleThumb($table, $id);
		}
		elseif ($context == 'tfile') {
			$f->handleFileTemp($id);
		}
		elseif (substr($context, 0, 6) == 'field_') {
            header('Content-type: application/json');
            try {
                $res = array('files' => $f->handleFileList($table, $id, substr($context, 6)));
            } catch (Exception $e) {
                $res = array('error' => $e->getMessage());
            }
            echo json_encode($res);
			return true;
		}
		$f->dispatch();
        return true;
	}

    /**
     * Создание письма
     * @return \Core2\Email
     */
    public function createEmail() {

        require_once 'classes/Email.php';
        return new \Core2\Email();
    }

    /**
     * Проверяем файлы модулей на изменения
     *
     * @return array
     */
    private function checkModulesChanges() {
        $server                = $this->config->system->host;
        $admin_email           = $this->getSetting('admin_email');
        $is_send_changes_email = $this->getSetting('is_send_changes_email');

        if (!$admin_email) {
            $id = $this->db->fetchOne("SELECT id FROM core_settings WHERE code = 'admin_email'");
            if (empty($id)) {
                $this->db->insert(
                    "core_settings",
                    array(
                        'system_name'   => 'Email для уведомлений от аудита системы',
                        'code'          => 'admin_email',
                        'is_custom_sw'  => 'Y',
                        'visible'       => 'Y'
                    )
                );
                $id = $this->db->lastInsertId("core_settings");
            }
            Alert::memory()->info("Создайте дополнительный параметр <a href=\"\" onclick=\"load('index.php#module=admin&action=settings&edit={$id}&tab_settings=2'); return false;\">'admin_email'</a> с адресом для уведомлений", $this->translate->tr("Отправка уведомлений отключена"));
        }
        if (!$server) {
            Alert::memory()->info($this->translate->tr("Не задан параметр 'host' в conf.ini"), $this->translate->tr("Отправка уведомлений отключена"));
        }

        $data = $this->db->fetchAll("SELECT module_id FROM core_modules WHERE is_system = 'N' AND files_hash IS NOT NULL");
        $mods = array();

        $install    = new Install();

        foreach ($data as $val) {
            $dirhash    = $install->extractHashForFiles($this->getModuleLocation($val['module_id']));
            $dbhash     = $install->getFilesHashFromDb($val['module_id']);
            $compare    = $install->compareFilesHash($dirhash, $dbhash);
            if (!empty($compare)) {
//                $this->db->update("core_modules", array('visible' => 'N'), $this->db->quoteInto("module_id = ? ", $val['module_id']));
                $mods[] = $val['module_id'];
                //отправка уведомления
                if ($admin_email && $server && (empty($is_send_changes_email) || $is_send_changes_email == 'Y')) {
                	if ($this->isModuleActive('queue')) {
						$is_send = $this->db->fetchOne(
							"SELECT 1
                           FROM mod_queue_mails
                          WHERE subject = 'Обнаружены изменения в структуре модуля'
                            AND date_send IS NULL
                            AND DATE_FORMAT(date_add, '%Y-%m-%d') = DATE_FORMAT(NOW(), '%Y-%m-%d')
                            AND body LIKE '%{$val['module_id']}%'"
						);
					} else {
						$is_send = false;
					}
                    if (!$is_send) {
                        $n = 0;
                        $br = $install->branchesCompareFilesHash($compare);
                        if (!empty($br['added'])) {
                            $n += count($br['added']);
                        }
                        if (!empty($br['changed'])) {
                            $n += count($br['changed']);
                        }
                        if (!empty($br['lost'])) {
                            $n += count($br['lost']);
                        }
                        $answer = $this->modAdmin->createEmail()
                            ->to($admin_email)
                            ->subject("{$server}: обнаружены изменения в структуре модуля")
                            ->body("<b>{$server}:</b> обнаружены изменения в структуре модуля {$val['module_id']}. Обнаружено  {$n} несоответствий.")
                            ->send();
                        if (isset($answer['error'])) {
                            Alert::memory()->danger($answer['error'], $this->translate->tr("Уведомление не отправлено"));
                        }
                    }
                }
            }
        }

        return $mods;
    }



    /**
     * @throws Exception
     * @return void
     */
    public function action_workhorse() {
        if (!$this->auth->ADMIN) throw new Exception(911);
        try {
            require_once __DIR__ . "/../mod/admin/classes/workhorse/View.php";
            $view  = new Admin\Workhorse\View();

        } catch (Exception $e) {
            echo Alert::danger($e->getMessage());
        }
    }
}