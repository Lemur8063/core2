<?php
namespace Core2;
/**
 * Локализация core2
 *
 * Класс для переводов текста.
 *
 * @package    Сlasses
 * @subpackage I18n
 */
use Laminas\I18n\Translator\Translator;
use Laminas\Config\Config as LaminasConfig;

class I18n {

    /**
     * @var Translator
     */
    private $translate;
    private $locale;
    private $domain;


    /**
     * @param Config $config
     */
	public function __construct(LaminasConfig $config) {

        if (isset($config->translate) && $config->translate->on) {
            try {
                if (!empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
                    $lng = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2);
                }
                if ($config->translate->locale) $lng = $config->translate->locale;
                if ($config->translate->adapter == 'gettext') {
                    $content = "core2/translations/$lng.mo";
                } else {
                    Error::Exception("Translation adapter not supported");
                }
                $this->locale = $lng;
                $this->setup(array(
                        'adapter' => $config->translate->adapter,
                        'content' => DOC_ROOT . $content,
                        'domain' => 'core2',
                        'locale'  => $lng
                ));
            } catch (\Exception $e) {
                Error::Exception($e->getMessage());
            }
        }
        Registry::set('translate', $this);
	}


    /**
     * Добавляем все имеющиеся варианты перевода текста и определяем язык пользователя
     * @param $config
     * @return void
     */
	public function setup($config) {

        if ($config['locale'] == 'ru') return;
        $this->translate = new Translator();
        $this->setLocale($config['locale']);
        $this->translate->addTranslationFile($config['adapter'], $config['content'], $config['domain'], $config['locale']);
	}


    /**
     * Проверяет, создан ли объект для переводов
     * @return mixed
     */
    public function isSetup() {
        return $this->translate;
    }


	/**
     * Определяет язык пользователя
	 *
	 * @param $lng
     * @return void
	 */
	public function setLocale($lng) {

		$this->translate->setLocale($lng);
        $this->locale = $lng;
	}


    /**
     * @return false|string
     */
    public function getLocale() {

        return $this->locale;
    }


    /**
     * Добавление переводов для модулей
     * @param $location
     * @param $domain
     */
    public function setupExtra($location, $domain) {

        $ini = $location . "/conf.ini";

        if ($this->translate && is_dir($location . "/translations") && file_exists($ini)) {
            $temp = parse_ini_file($ini, true);
            $goit = false;
            foreach ($temp as $k => $v) {
                $k = explode(":", $k);
                if ($_SERVER['SERVER_NAME'] == trim($k[0])) {
                    $goit = true;
                    break;
                }
            }
            $config = new Config();
            if ($goit) {
                $ini = $config->readIni($location . "/conf.ini", $_SERVER['SERVER_NAME']);
            } else {
                $ini = $config->readIni($location . "/conf.ini", 'production');
            }
            $config = $config->getData();
            if (isset($config->translate) && $config->translate->on) {
                $lng = $this->getLocale();
                if ($config->translate->adapter == 'gettext') {
                    $content = $location . "/translations/$lng.mo";
                } else {
                    Error::Exception("Module's translation adapter not supported");
                }
                try {
                    $this->translate->addTranslationFile($config->translate->adapter, $content, $domain, $config->translate->locale);
                    unset($translate_second);
                } catch (\Exception $e) {
                    Error::Exception($e->getMessage());
                }
                Registry::set('translate', $this);
            }
        }
    }


	/**
	 * Получение перевода с английского на язык пользователя
     *
	 * @param   string $str    Строка на английском, которую следует перевести на язык пользователя
	 * @param   string $domain Категория к которой относится строка(необязательный параметр)
	 * @return  string         Переведеная строка (если перевод не найден, возращает $str)
	 */
	public function tr($str, $domain = "core2") {
        if (!$this->translate) {
            return $str;
        }
		return $this->translate->translate($str, $domain, $this->locale);
	}
}
