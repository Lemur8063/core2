<?php
/**
 *
 * PHP script for managing Core2 workers based on Gearman Manager for PHP
 * by https://github.com/brianlmoon/GearmanManager
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
 * IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
 * ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE
 * LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR
 * CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 *
 */
namespace Core2;

require_once "classes/Registry.php";
require_once "classes/Config.php";
require_once "classes/Job.php";

declare(ticks = 1);
error_reporting(E_ALL | E_STRICT);

class WorkerManager {

    /**
     * Log levels can be enabled from the command line with -v, -vv, -vvv
     */
    const LOG_LEVEL_INFO = 1;
    const LOG_LEVEL_PROC_INFO = 2;
    const LOG_LEVEL_WORKER_INFO = 3;
    const LOG_LEVEL_DEBUG = 4;
    const LOG_LEVEL_CRAZY = 5;

    /**
     * Default config section name
     */
    const DEFAULT_CONFIG = "production";

    /**
     * Defines job priority limits
     */
    const MIN_PRIORITY = -5;
    const MAX_PRIORITY = 5;

    /**
     * Holds the worker configuration
     */
    protected $config = array();

    /**
     * Boolean value that determines if the running code is the parent or a child
     */
    protected $isparent = true;

    /**
     * When true, workers will stop look for jobs and the parent process will
     * kill off all running children
     */
    protected $stop_work = false;

    /**
     * The timestamp when the signal was received to stop working
     */
    protected $stop_time = 0;

    /**
     * The filename to log to
     */
    protected $log_file;

    /**
     * Holds the resource for the log file
     */
    protected $log_file_handle;

    /**
     * Flag for logging to syslog
     */
    protected $log_syslog = false;

    /**
     * Verbosity level for the running script. Set via -v option
     */
    protected $verbose = 0;

    /**
     * The array of running child processes
     */
    protected $children = array();

    /**
     * The array of jobs that have workers running
     */
    protected $jobs = array();

    /**
     * The PID of the running process. Set for parent and child processes
     */
    protected $pid = 0;

    /**
     * The PID of the parent process, when running in the forked helper.
     */
    protected $parent_pid = 0;

    /**
     * PID file for the parent process
     */
    protected $pid_file = "";

    /**
     * PID of helper child
     */
    protected $helper_pid = 0;

    /**
     * The user to run as
     */
    protected $user = null;

    /**
     * If true, the worker code directory is checked for updates and workers
     * are restarted automatically.
     */
    protected $check_code = false;

    /**
     * Holds the last timestamp of when the code was checked for updates
     */
    protected $last_check_time = 0;

    /**
     * When forking helper children, the parent waits for a signal from them
     * to continue doing anything
     */
    protected $wait_for_signal = false;

    /**
     * Directory where worker functions are found
     */
    protected $worker_dir = "";

    /**
     * Number of workers that do all jobs
     */
    protected $do_all_count = 0;

    /**
     * Maximum time a worker will run
     */
    protected $max_run_time = 3600;

    /**
     * +/- number of seconds workers will delay before restarting
     * this prevents all your workers from restarting at the same
     * time which causes a connection stampeded on your daemons
     * So, a max_run_time of 3600 and worker_restart_splay of 600 means
     * a worker will self restart between 3600 and 4200 seconds after
     * it is started.
     *
     * This does not affect the time the parent process waits on children
     * when shutting down.
     */
    protected $worker_restart_splay = 600;

    /**
     * Maximum number of jobs this worker will do before quitting
     */
    protected $max_job_count = 0;

    /**
     * Maximum job iterations per worker
     */
    protected $max_runs_per_worker = null;

    /**
     * Number of times this worker has run a job
     */
    protected $job_execution_count = 0;

    /**
     * Servers that workers connect to
     */
    protected $servers = array();

    /**
     * List of functions available for work
     */
    protected $functions = array();

    /**
     * Function/Class prefix
     */
    protected $prefix = "";

    private $_log = "";


    public function __construct() {

        if (!function_exists("posix_kill")) {
            $this->show_help("The function posix_kill was not found.");
        }

        if (!function_exists("pcntl_fork")) {
            $this->show_help("The function pcntl_fork was not found.");
        }

        $this->pid = getmypid();

        /**
         * Parse command line options. Loads the config file as well
         */
        $this->getopt();

        /**
         * Register signal listeners
         */
        $this->register_ticks();

        /**
         * Load up the workers
         */
        $this->load_workers();

        if (empty($this->functions)) {
            $this->toLog("No workers found");
            posix_kill($this->pid, SIGUSR1);
            exit();
        }

        /**
         * Validate workers in the helper process
         */
        $this->fork_me("validate_workers");

        $this->toLog("Started with pid $this->pid", self::LOG_LEVEL_PROC_INFO);

        /**
         * Start the initial workers and set up a running environment
         */
        $this->bootstrap();

        $this->process_loop();

        /**
         * Kill the helper if it is running
         */
        if (isset($this->helper_pid)) {
            posix_kill($this->helper_pid, SIGKILL);
        }

        $this->toLog("Exiting");

    }

    protected function process_loop() {

        /**
         * Main processing loop for the parent process
         */
        while (!$this->stop_work || count($this->children)) {

            $status = null;

            /**
             * Check for exited children
             */
            $exited = pcntl_wait( $status, WNOHANG );

            /**
             * We run other children, make sure this is a worker
             */
            if (isset($this->children[$exited])) {
                /**
                 * If they have exited, remove them from the children array
                 * If we are not stopping work, start another in its place
                 */
                if ($exited) {
                    $worker = $this->children[$exited]['job'];
                    unset($this->children[$exited]);
                    $code = pcntl_wexitstatus($status);
                    if ($code === 0) {
                        $exit_status = "exited";
                    } else {
                        $exit_status = $code;
                    }
                    $this->child_status_monitor($exited, $worker, $exit_status);
                    if (!$this->stop_work) {
                        $this->start_worker($worker);
                    }
                }
            }


            if ($this->stop_work && time() - $this->stop_time > 60) {
                $this->toLog("Children have not exited, killing.", self::LOG_LEVEL_PROC_INFO);
                $this->stop_children(SIGKILL);
            } else {
                /**
                 *  If any children have been running 150% of max run time, forcibly terminate them
                 */
                if (!empty($this->children)) {
                    foreach ($this->children as $pid => $child) {
                        if (!empty($child['start_time']) && time() - $child['start_time'] > $this->max_run_time * 1.5) {
                            $this->child_status_monitor($pid, $child["job"], "killed");
                            posix_kill($pid, SIGKILL);
                        }
                    }
                }
            }

            /**
             * php will eat up your cpu if you don't have this
             */
            usleep(10000);

        }

    }

    /**
     * Handles anything we need to do when we are shutting down
     *
     */
    public function __destruct() {
        if ($this->isparent) {
            if (!empty($this->pid_file) && file_exists($this->pid_file)) {
                $pid = trim(file_get_contents($this->pid_file));
                if ($this->pid != $pid) {
                    $this->toLog("Not removing PID file ($pid) since it is not the current process ({$this->pid_file})", self::LOG_LEVEL_PROC_INFO);
                    return;
                }

                if (!unlink($this->pid_file)) {
                    $this->toLog("Could not delete PID file", self::LOG_LEVEL_PROC_INFO);
                }
            }
        }
    }

    /**
     * Parses the command line options
     *
     */
    protected function getopt() {
        $this->config = [];

        $opts = getopt("c:dD:h:Hl:o:p:P:u:v::w:r:x:Z:L:s:");

        if (isset($opts["H"])) {
            $this->show_help();
        }

        if (!isset($opts["c"])) {
            $opts["c"] = __DIR__ . "/../../conf.ini";
        }
        if (! file_exists($opts["c"])) {
            $this->show_help("Application config file {$opts["c"]} not found.");
        }

        $this->config['file'] = __DIR__ . "/../conf.ini";
        if (isset($this->config['file'])) {
            if (file_exists($this->config['file'])) {
                $core_config = (new Config())->readIni($this->config['file'], 'production');
                Registry::set('core_config', $core_config);
                if (isset($core_config->gearman)) {
                    $this->config = $core_config->gearman->toArray();
                }
            }
            else {
                $this->show_help("Core2 worker config file {$this->config['file']} not found.");
            }
        }

        $section = 'production';
        if (isset($opts["s"])) {
            $_SERVER['SERVER_NAME'] = $opts["s"];
            $section = $_SERVER['SERVER_NAME'];
        }

        $config = [
            'database' => [
                'adapter' => 'Pdo_Mysql',
                'params'  => [
                    'charset' => 'utf8',
                    'driver_options'=> [
                        \PDO::ATTR_TIMEOUT => 3,
                    ]
                ],
                'isDefaultTableAdapter'      => true,
                'caseFolding'                => true,
                'autoQuoteIdentifiers'       => true,
                'allowSerialization'         => true,
                'autoReconnectOnUnserialize' => true,
            ],
        ];
        // определяем путь к темповой папке
        if (empty($config['temp'])) {
            $config['temp'] = sys_get_temp_dir();
            if (empty($config['temp'])) {
                $config['temp'] = "/tmp";
            }
        }

        try {

            $conf     = new Config($config);
            $config   = $conf->getData()->merge($conf->readIni($opts["c"], $section));

            $tz = $config->system->timezone;
            if (!empty($tz)) {
                date_default_timezone_set($tz);
            }
            Registry::set('config', ($config));
        }
        catch (\Exception $e) {
            $this->show_help($e->getMessage());
        }
        
        /**
         * command line opts always override config file
         */
        if (isset($opts['P'])) {
            $this->config['pid_file'] = $opts['P'];
        }

        if (isset($opts["l"])) {
            $this->config['log_file'] = $opts["l"];
        }

        if (isset($opts['a'])) {
            $this->config['auto_update'] = 1;
        }

        if (isset($opts['x'])) {
            $this->config['max_worker_lifetime'] = (int)$opts['x'];
        }

        if (isset($opts['r'])) {
            $this->config['max_runs_per_worker'] = (int)$opts['r'];
        }

        if (isset($opts['D'])) {
            $this->config['count'] = (int)$opts['D'];
        }

        if (isset($opts['t'])) {
            $this->config['timeout'] = $opts['t'];
        }

        if (isset($opts['h'])) {
            $this->config['host'] = $opts['h'];
        }

        if (isset($opts['p'])) {
            $this->prefix = $opts['p'];
        } elseif (!empty($this->config['prefix'])) {
            $this->prefix = $this->config['prefix'];
        }

        if (isset($opts['u'])) {
            $this->user = $opts['u'];
        } elseif (isset($this->config["user"])) {
            $this->user = $this->config["user"];
        }

        /**
         * If we want to daemonize, fork here and exit
         */
        if (isset($opts["d"]) || (isset($this->config['daemon']) && $this->config['daemon'])) {
            $pid = pcntl_fork();
            if ($pid>0) {
                $this->isparent = false;
                exit();
            }
            $this->pid = getmypid();
            posix_setsid();
        }

        if (!empty($this->config['pid_file'])) {
            $fp = @fopen($this->config['pid_file'], "w");
            if ($fp) {
                fwrite($fp, $this->pid);
                fclose($fp);
            } else {
                $this->show_help("Unable to write PID to {$this->config['pid_file']}");
            }
            $this->pid_file = $this->config['pid_file'];
        }

        if (!empty($this->config['log_file'])) {
            if ($this->config['log_file'] === 'syslog') {
                $this->log_syslog = true;
            } else {
                $this->log_file = $this->config['log_file'];
                $this->open_log_file();
            }
        }

        if (isset($opts["v"])) {
            $this->config['verbose'] = $opts["v"];
        }

        if (isset($this->config['verbose'])) {
            switch ($this->config['verbose']) {
                case "":
                case self::LOG_LEVEL_INFO:
                    $this->verbose = self::LOG_LEVEL_INFO;
                    break;
                case "v":
                case self::LOG_LEVEL_PROC_INFO:
                    $this->verbose = self::LOG_LEVEL_PROC_INFO;
                    break;
                case "vv":
                case self::LOG_LEVEL_WORKER_INFO:
                    $this->verbose = self::LOG_LEVEL_WORKER_INFO;
                    break;
                case "vvv":
                case self::LOG_LEVEL_DEBUG:
                    $this->verbose = self::LOG_LEVEL_DEBUG;
                    break;
                case "vvvv":
                case self::LOG_LEVEL_CRAZY:
                default:
                    $this->verbose = self::LOG_LEVEL_CRAZY;
                    break;
            }
        }

        if ($this->user) {
            $user = posix_getpwnam($this->user);
            if (!$user || !isset($user['uid'])) {
                $this->show_help("User ({$this->user}) not found.");
            }

            /**
             * Ensure new uid can read/write pid and log files
             */
            if (!empty($this->pid_file)) {
                if (!chown($this->pid_file, $user['uid'])) {
                    $this->toLog("Unable to chown PID file to {$this->user}", self::LOG_LEVEL_PROC_INFO);
                }
            }
            if (!empty($this->log_file_handle)) {
                if (!chown($this->log_file, $user['uid'])) {
                    $this->toLog("Unable to chown log file to {$this->user}", self::LOG_LEVEL_PROC_INFO);
                }
            }

            posix_setuid($user['uid']);
            if (posix_geteuid() != $user['uid']) {
                $this->show_help("Unable to change user to {$this->user} (UID: {$user['uid']}).");
            }
            $this->toLog("User set to {$this->user}", self::LOG_LEVEL_PROC_INFO);
        }

        if (!empty($this->config['auto_update'])) {
            $this->check_code = true;
        }

        $this->worker_dir = !empty($this->config['worker_dir']) ? $this->config['worker_dir'] : __DIR__ . "/../workers";
        $dirs = is_array($this->worker_dir) ? $this->worker_dir : explode(",", $this->worker_dir);
        foreach ($dirs as &$dir) {
            $dir = trim($dir);
            if (!is_dir($dir)) {
                $this->show_help("Worker dir ".$dir." not found");
            }
        }
        unset($dir);

        if (isset($this->config['max_worker_lifetime']) && (int)$this->config['max_worker_lifetime'] > 0) {
            $this->max_run_time = (int)$this->config['max_worker_lifetime'];
        }

        if (isset($this->config['worker_restart_splay']) && (int)$this->config['worker_restart_splay'] > 0) {
            $this->worker_restart_splay = (int)$this->config['worker_restart_splay'];
        }

        if (isset($this->config['count']) && (int)$this->config['count'] > 0) {
            $this->do_all_count = (int)$this->config['count'];
        }

        if (!empty($this->config['host'])) {
            if (!is_array($this->config['host'])) {
                $this->servers = explode(",", $this->config['host']);
            } else {
                $this->servers = $this->config['host'];
            }
        } else {
            $this->servers = array("127.0.0.1");
        }

        if (!empty($this->config['include']) && $this->config['include'] != "*") {
            $this->config['include'] = explode(",", $this->config['include']);
        } else {
            $this->config['include'] = array();
        }

        if (!empty($this->config['exclude'])) {
            $this->config['exclude'] = explode(",", $this->config['exclude']);
        } else {
            $this->config['exclude'] = array();
        }

        if (empty($this->config['worker_initial_spawn_splay']) || ! is_numeric($this->config['worker_initial_spawn_splay'])) {
            /**
             * Don't start workers too fast. They can overwhelm the
             * gearmand server and lead to connection timeouts.
             */
            $this->config['worker_initial_spawn_splay'] = 500000;
        }

        /**
         * Debug option to dump the config and exit
         */
        if (isset($opts["Z"])) {
            print_r($this->config);
            exit();
        }

    }



    /**
     * Helper function to load and filter worker files
     *
     * return @void
     */
    protected function load_workers() {

        $this->functions = array();

        $dirs = is_array($this->worker_dir) ? $this->worker_dir : explode(",", $this->worker_dir);

        foreach ($dirs as $dir) {

            $this->toLog("Loading workers in " . $dir);

            $worker_files = glob($dir . "/*.php");

            if (!empty($worker_files)) {
                foreach ($worker_files as $file) {

                    $function = substr(basename($file), 0, -4);

                    /**
                     * include workers
                     */
                    if (!empty($this->config['include'])) {
                        if (!in_array($function, $this->config['include'])) {
                            continue;
                        }
                    }

                    /**
                     * exclude workers
                     */
                    if (in_array($function, $this->config['exclude'])) {
                        continue;
                    }

                    if (!isset($this->functions[$function])) {
                        $this->functions[$function] = array('name' => $function);
                    }

                    if (!empty($this->config['functions'][$function]['dedicated_only'])) {

                        if (empty($this->config['functions'][$function]['dedicated_count'])) {
                            $this->toLog("Invalid configuration for dedicated_count for function $function.", self::LOG_LEVEL_PROC_INFO);
                            exit();
                        }

                        $this->functions[$function]['dedicated_only'] = true;
                        $this->functions[$function]["count"] = $this->config['functions'][$function]['dedicated_count'];

                    } else {

                        $min_count = max($this->do_all_count, 1);
                        if (!empty($this->config['functions'][$function]['count'])) {
                            $min_count = max($this->config['functions'][$function]['count'], $this->do_all_count);
                        }

                        if (!empty($this->config['functions'][$function]['dedicated_count'])) {
                            $ded_count = $this->do_all_count + $this->config['functions'][$function]['dedicated_count'];
                        } elseif (!empty($this->config["dedicated_count"])) {
                            $ded_count = $this->do_all_count + $this->config["dedicated_count"];
                        } else {
                            $ded_count = $min_count;
                        }

                        $this->functions[$function]["count"] = max($min_count, $ded_count);

                    }

                    $this->functions[$function]['path'] = $file;

                    /**
                     * Note about priority. This exploits an undocumented feature
                     * of the gearman daemon. This will only work as long as the
                     * current behavior of the daemon remains the same. It is not
                     * a defined part fo the protocol.
                     */
                    if (!empty($this->config['functions'][$function]['priority'])) {
                        $priority = max(min(
                            $this->config['functions'][$function]['priority'],
                            self::MAX_PRIORITY), self::MIN_PRIORITY);
                    } else {
                        $priority = 0;
                    }

                    $this->functions[$function]['priority'] = $priority;

                }
            }
        }
//        echo "<PRE>";print_r($this->config);echo "</PRE>";//die;
//        echo "<PRE>";print_r($this->functions);echo "</PRE>";die;
    }

    /**
     * Forks the process and runs the given method. The parent then waits
     * for the child process to signal back that it can continue
     *
     * @param   string  $method  Class method to run after forking
     *
     */
    protected function fork_me($method) {
        $this->wait_for_signal = true;
        $pid = pcntl_fork();
        switch ($pid) {
            case 0:
                $this->isparent = false;
                $this->parent_pid = $this->pid;
                $this->pid = getmypid();
                $this->$method();
                break;
            case -1:
                $this->toLog("Failed to fork");
                $this->stop_work = true;
                break;
            default:
                $this->toLog("Helper forked with pid $pid", self::LOG_LEVEL_PROC_INFO);
                $this->helper_pid = $pid;
                while ($this->wait_for_signal && !$this->stop_work) {
                    usleep(5000);
                    pcntl_waitpid($pid, $status, WNOHANG);

                    if (pcntl_wifexited($status) && $status) {
                        $this->toLog("Helper child exited with non-zero exit code $status.");
                        exit(1);
                    }

                }
                break;
        }
    }


    /**
     * Forked method that validates the worker code and checks it if desired
     *
     */
    protected function validate_workers() {

        $this->load_workers();

        if (empty($this->functions)) {
            $this->toLog("No workers found");
            posix_kill($this->parent_pid, SIGUSR1);
            exit();
        }

        $this->validate_lib_workers();

        /**
         * Since we got here, all must be ok, send a CONTINUE
         */
        $this->toLog("Helper is running. Sending continue to $this->parent_pid.", self::LOG_LEVEL_DEBUG);
        posix_kill($this->parent_pid, SIGCONT);

        if ($this->check_code) {
            $this->toLog("Running loop to check for new code", self::LOG_LEVEL_DEBUG);
            $last_check_time = 0;
            while (1) {
                $max_time = 0;
                foreach ($this->functions as $name => $func) {
                    clearstatcache();
                    $mtime = filemtime($func['path']);
                    $max_time = max($max_time, $mtime);
                    $this->toLog("{$func['path']} - $mtime $last_check_time", self::LOG_LEVEL_CRAZY);
                    if ($last_check_time != 0 && $mtime > $last_check_time) {
                        $this->toLog("New code found. Sending SIGHUP", self::LOG_LEVEL_PROC_INFO);
                        posix_kill($this->parent_pid, SIGHUP);
                        break;
                    }
                }
                $last_check_time = $max_time;
                sleep(5);
            }
        } else {
            exit();
        }

    }

    /**
     * Bootstap a set of workers and any vars that need to be set
     *
     */
    protected function bootstrap() {

        $function_count = array();

        /**
         * If we have "do_all" workers, start them first
         * do_all workers register all functions
         */
        if (!empty($this->do_all_count) && is_int($this->do_all_count)) {

            for ($x = 0; $x < $this->do_all_count; $x++) {
                $this->start_worker();
                /**
                 * Don't start workers too fast. They can overwhelm the
                 * gearmand server and lead to connection timeouts.
                 */
                usleep($this->config['worker_initial_spawn_splay']);
            }

            foreach ($this->functions as $worker => $settings) {
                if (empty($settings["dedicated_only"])) {
                    $function_count[$worker] = $this->do_all_count;
                }
            }

        }

        /**
         * Next we loop the workers and ensure we have enough running
         * for each worker
         */
        foreach ($this->functions as $worker => $config) {

            /**
             * If we don't have do_all workers, this won't be set, so we need
             * to init it here
             */
            if (empty($function_count[$worker])) {
                $function_count[$worker] = 0;
            }

            while ($function_count[$worker] < $config["count"]) {
                $this->start_worker($worker);
                $function_count[$worker]++;;
                /**
                 * Don't start workers too fast. They can overwhelm the
                 * gearmand server and lead to connection timeouts.
                 */
                usleep($this->config['worker_initial_spawn_splay']);
            }

        }

        /**
         * Set the last code check time to now since we just loaded all the code
         */
        $this->last_check_time = time();

    }

    protected function start_worker($worker = "all") {

        static $all_workers;

        if (is_array($worker)) {

            $worker_list = $worker;

        } elseif ($worker == "all") {

            if (is_null($all_workers)) {
                $all_workers = array();
                foreach ($this->functions as $func => $settings) {
                    if (empty($settings["dedicated_only"])) {
                        $all_workers[] = $func;
                    }
                }
            }
            $worker_list = $all_workers;
        } else {
            $worker_list = array($worker);
        }

        $timeouts = array();
        $default_timeout = ((isset($this->config['timeout'])) ?
            (int) $this->config['timeout'] : null);

        // build up the list of worker timeouts
        foreach ($worker_list as $worker_name) {
            $timeouts[$worker_name] = ((isset($this->config['functions'][$worker_name]['timeout'])) ?
                (int) $this->config['functions'][$worker_name]['timeout'] : $default_timeout);
        }

        $pid = pcntl_fork();

        switch ($pid) {

            case 0:

                $this->isparent = false;

                $this->register_ticks(false);

                $this->pid = getmypid();

                if (count($worker_list) > 1) {

                    // shuffle the list to avoid queue preference
                    shuffle($worker_list);

                    // sort the shuffled array by priority
                    uasort($worker_list, array($this, "sort_priority"));
                }

                if ($this->worker_restart_splay > 0) {
                    mt_srand($this->pid); // Since all child threads use the same seed, we need to reseed with the pid so that we get a new "random" number.
                    $splay = mt_rand(0, $this->worker_restart_splay);
                    $this->max_run_time += $splay;
                    $this->toLog("Adjusted max run time to {$this->max_run_time} seconds", self::LOG_LEVEL_DEBUG);
                }

                $this->start_lib_worker2($worker_list, $timeouts);

                $this->toLog("Child exiting", self::LOG_LEVEL_WORKER_INFO);

                exit();

                break;

            case -1:

                $this->toLog("Could not fork");
                $this->stop_work = true;
                $this->stop_children();
                break;

            default:

                // parent
                $this->toLog("Started child $pid (".implode(",", $worker_list).")", self::LOG_LEVEL_PROC_INFO);
                $this->children[$pid] = array(
                    "job" => $worker_list,
                    "start_time" => time(),
                );
        }

    }

    /**
     * Sorts the function list by priority
     */
    private function sort_priority($a, $b) {
        $func_a = $this->functions[$a];
        $func_b = $this->functions[$b];

        if (!isset($func_a["priority"])) {
            $func_a["priority"] = 0;
        }
        if (!isset($func_b["priority"])) {
            $func_b["priority"] = 0;
        }
        if ($func_a["priority"] == $func_b["priority"]) {
            return 0;
        }
        return ($func_a["priority"] > $func_b["priority"]) ? -1 : 1;
    }

    /**
     * Stops all running children
     */
    protected function stop_children($signal=SIGTERM) {
        $this->toLog("Stopping children", self::LOG_LEVEL_PROC_INFO);

        foreach ($this->children as $pid=>$child) {
            $this->toLog("Stopping child $pid (".implode(",", $child['job']).")", self::LOG_LEVEL_PROC_INFO);
            posix_kill($pid, $signal);
        }

    }

    /**
     * Registers the process signal listeners
     */
    protected function register_ticks($parent = true) {

        pcntl_async_signals(true);

        if ($parent) {
            $this->toLog("Registering signals for parent", self::LOG_LEVEL_DEBUG);
            pcntl_signal(SIGTERM, array($this, "signal"));
            pcntl_signal(SIGINT,  array($this, "signal"));
            pcntl_signal(SIGUSR1,  array($this, "signal"));
            pcntl_signal(SIGUSR2,  array($this, "signal"));
            pcntl_signal(SIGCONT,  array($this, "signal"));
            pcntl_signal(SIGHUP,  array($this, "signal"));
        } else {
            $this->toLog("Registering signals for child", self::LOG_LEVEL_DEBUG);
            $res = pcntl_signal(SIGTERM, array($this, "signal"));
            if (!$res) {
                exit();
            }
        }
    }

    /**
     * Handles signals
     */
    public function signal($signo) {

        static $term_count = 0;

        if (!$this->isparent) {

            $this->stop_work = true;

        } else {

            switch ($signo) {
                case SIGUSR1:
                    $this->show_help("No worker files could be found");
                    break;
                case SIGUSR2:
                    $this->show_help("Error validating worker functions");
                    break;
                case SIGCONT:
                    $this->wait_for_signal = false;
                    break;
                case SIGINT:
                case SIGTERM:
                    $this->toLog("Shutting down...");
                    $this->stop_work = true;
                    $this->stop_time = time();
                    $term_count++;
                    if ($term_count < 5) {
                        $this->stop_children();
                    } else {
                        $this->stop_children(SIGKILL);
                    }
                    break;
                case SIGHUP:
                    $this->toLog("Restarting children", self::LOG_LEVEL_PROC_INFO);
                    if ($this->log_file) {
                        $this->open_log_file();
                    }
                    $this->stop_children();
                    break;
                default:
                    // handle all other signals
            }
        }

    }

    /**
     * Opens the log file. If already open, closes it first.
     */
    protected function open_log_file() {

        if ($this->log_file) {

            if ($this->log_file_handle) {
                fclose($this->log_file_handle);
            }

            $this->log_file_handle = @fopen($this->config['log_file'], "a");
            if (!$this->log_file_handle) {
                $this->show_help("Could not open log file {$this->config['log_file']}");
            }
        }

    }

    /**
     * Logs data to disk or stdout
     */
    protected function toLog($message, $level = self::LOG_LEVEL_INFO) {

        static $init = false;

        if ($level > $this->verbose) return;

        if ($this->log_syslog) {
            $this->syslog($message, $level);
            return;
        }

        if (!$init) {
            $init = true;

            if ($this->log_file_handle) {
                fwrite($this->log_file_handle, "Date                         PID   Type   Message\n");
            } else {
                echo "PID   Type   Message\n";
            }

        }

        $label = "";

        switch ($level) {
            case self::LOG_LEVEL_INFO;
                $label = "INFO  ";
                break;
            case self::LOG_LEVEL_PROC_INFO:
                $label = "PROC  ";
                break;
            case self::LOG_LEVEL_WORKER_INFO:
                $label = "WORKER";
                break;
            case self::LOG_LEVEL_DEBUG:
                $label = "DEBUG ";
                break;
            case self::LOG_LEVEL_CRAZY:
                $label = "CRAZY ";
                break;
        }


        $log_pid = str_pad($this->pid, 5, " ", STR_PAD_LEFT);
        if ($this->log_file_handle) {
            list($ts, $ms) = explode(".", sprintf("%f", microtime(true)));
            $ds = date("Y-m-d H:i:s").".".str_pad($ms, 6, 0);
            $prefix = "[$ds] $log_pid $label";
            fwrite($this->log_file_handle, $prefix." ".str_replace("\n", "\n$prefix ", trim($message))."\n");
        } else {
            $prefix = "$log_pid $label";
            echo $prefix." ".str_replace("\n", "\n$prefix ", trim($message))."\n";
        }

    }

    /**
     * Logs data to syslog
     */
    protected function syslog($message, $level) {
        switch ($level) {
            case self::LOG_LEVEL_INFO;
            case self::LOG_LEVEL_PROC_INFO:
            case self::LOG_LEVEL_WORKER_INFO:
            default:
                $priority = LOG_INFO;
                break;
            case self::LOG_LEVEL_DEBUG:
                $priority = LOG_DEBUG;
                break;
        }

        if (!syslog($priority, $message)) {
            echo "Unable to write to syslog\n";
        }
    }

    /**
     * Function for logging the status of children. This simply logs the status
     * of the process. Wrapper classes can make use of this to do logging as
     * appropriate for individual environments.
     *
     * @param  int    $pid    PID of the child process
     * @param  array  $jobs   Array of jobs the child is/was running
     * @param  string $status Status of the child process.
     *                        One of killed, exited or non-zero integer
     *
     * @return void
     */
    protected function child_status_monitor($pid, $jobs, $status) {
        switch ($status) {
            case "killed":
                $message = "Child $pid has been running too long. Forcibly killing process. (".implode(",", $jobs).")";
                break;
            case "exited":
                $message = "Child $pid exited cleanly. (".implode(",", $jobs).")";
                break;
            default:
                $message = "Child $pid died unexpectedly with exit code $status. (".implode(",", $jobs).")";
                break;
        }
        $this->toLog($message, self::LOG_LEVEL_PROC_INFO);
    }

    /**
     * Shows the scripts help info with optional error message
     */
    protected function show_help($msg = "") {
        if ($msg) {
            echo "ERROR:\n";
            echo "  " . wordwrap($msg, 72, "\n  ")."\n\n";
        }
        echo "Gearman worker manager script\n\n";
        echo "USAGE:\n";
        echo "  # ".basename(__FILE__)." -h | -c CONFIG [-v] [-l LOG_FILE] [-d] [-v] [-a] [-P PID_FILE]\n\n";
        echo "OPTIONS:\n";
        echo "  -a             Automatically check for new worker code\n";
        echo "  -c CONFIG      Application configuration file\n";
        echo "  -d             Daemon, detach and run in the background\n";
        echo "  -D NUMBER      Start NUMBER workers that do all jobs\n";
        echo "  -h HOST[:PORT] Connect to gearman HOST and optional PORT\n";
        echo "  -H             Shows this help\n";
        echo "  -l LOG_FILE    Log output to LOG_FILE or use keyword 'syslog' for syslog support\n";
        echo "  -p PREFIX      Optional prefix for functions/classes of PECL workers. PEAR requires a constant be defined in code.\n";
        echo "  -P PID_FILE    File to write process ID out to\n";
        echo "  -u USERNAME    Run workers as USERNAME\n";
        echo "  -v             Increase verbosity level by one\n";
        echo "  -r NUMBER      Maximum job iterations per worker\n";
        echo "  -t SECONDS     Maximum number of seconds gearmand server should wait for a worker to complete work before timing out and reissuing work to another worker.\n";
        echo "  -x SECONDS     Maximum seconds for a worker to live\n";
        echo "  -Z             Parse the command line and config file then dump it to the screen and exit.\n";
        echo "  -L LABEL       Label worker process to easy find in process list.\n";
        echo "  -s SECTION     conf.ini section to use. ('production' by default)\n";
        echo "\n";
        exit();
    }

    /**
     * The way this daemon implementation starts workers.
     *
     * @param $worker_list
     * @param $timeouts
     * @return mixed
     */
    private function start_lib_worker($worker_list, $timeouts = array()) {

        $thisWorker = new \GearmanWorker();

        $thisWorker->addOptions(GEARMAN_WORKER_NON_BLOCKING);
        $thisWorker->addOptions(GEARMAN_WORKER_GRAB_UNIQ);

        $connected = false;

        foreach ($this->servers as $s) {
            $this->toLog("Adding server $s", self::LOG_LEVEL_PROC_INFO);
            try {
                $thisWorker->addServers($s);
            } catch (\GearmanException $e) {
                //если сервер недоступен
            }
        }

        $dd = str_replace(DIRECTORY_SEPARATOR, "-", dirname(dirname(__DIR__)));
        $dd = trim($dd, '-');
        foreach ($worker_list as $w) {
            $timeout = (isset($timeouts[$w]) ? $timeouts[$w] : 0);
            $w = $dd . "-" . $w;
            $this->toLog("Adding job $w ; timeout: " . $timeout, self::LOG_LEVEL_WORKER_INFO);
            $thisWorker->addFunction($w, array($this, "do_job"), $this, $timeout);
        }
        $thisWorker->setTimeout(5000); //столько воркер будет ждять задачу от сервера

        register_shutdown_function(array($this, 'fatal_handler'));

        $start = time();
        $died = 0;

        while (!$this->stop_work) {

            if (@$thisWorker->work() ||
                $thisWorker->returnCode() == GEARMAN_IO_WAIT ||
                $thisWorker->returnCode() == GEARMAN_NO_JOBS) {

                if ($thisWorker->returnCode() == GEARMAN_SUCCESS) continue;

                if (!@$thisWorker->wait()) {
                    //воркер в состоянии ожидания задачи от job-сервера
                    if ($thisWorker->returnCode() == GEARMAN_NO_ACTIVE_FDS) {
                        //после ожидания выяснилось, что сервер не отвечает
                        //ждем еще 5 сек
                        $this->toLog('Failed to connect to Gearman Gerver.'. PHP_EOL, self::LOG_LEVEL_WORKER_INFO);
                        $died++;
                        sleep(5);
                    }
                }

            }
//            if ($thisWorker->returnCode() !== GEARMAN_TIMEOUT) echo $thisWorker->error().PHP_EOL;

            /**
             * Check the running time of the current child. If it has
             * been too long, stop working.
             */
            if ($this->max_run_time > 0 && time() - $start > $this->max_run_time) {
                $this->toLog("Been running too long, exiting", self::LOG_LEVEL_WORKER_INFO);
                $this->stop_work = true;
            }

            if (!empty($this->config["max_runs_per_worker"]) && $this->job_execution_count >= $this->config["max_runs_per_worker"]) {
                $this->toLog("Ran $this->job_execution_count jobs which is over the maximum({$this->config['max_runs_per_worker']}), exiting", self::LOG_LEVEL_WORKER_INFO);
                $this->stop_work = true;
            }

        }

        $thisWorker->unregisterAll();

    }

    private function start_lib_worker2($worker_list, $timeouts = array()) {
//        ob_implicit_flush(true);
        $worker = null;
        foreach ($this->servers as $s) {
            $this->toLog("Adding server $s", self::LOG_LEVEL_PROC_INFO);

            $worker = stream_socket_client("tcp://$s", $errno, $errstr, 2);
            if (!$worker) {
                continue;
            }
            break;
        }
        if (!$worker) {
            $this->toLog("Failed to connect to server $s", self::LOG_LEVEL_PROC_INFO);
            die("Failed to connect to server: $errstr ($errno)\n");
        }
        //stream_set_read_buffer($worker, 0);
        //stream_set_chunk_size($worker, 8192);
        //stream_set_blocking($worker, true);

        $dd = str_replace(DIRECTORY_SEPARATOR, "-", dirname(dirname(__DIR__)));
        $dd = trim($dd, '-');

        register_shutdown_function(array($this, 'fatal_handler'));

        $objects = [];
        foreach ($worker_list as $w) {
            $timeout = (isset($timeouts[$w]) ? $timeouts[$w] : 0);
            $w_full = $dd . "-" . $w;
            echo "Adding job $w_full\n";
            $this->toLog("Adding job $w_full ; timeout: " . $timeout, self::LOG_LEVEL_PROC_INFO);
            require_once $this->functions[$w]['path'];
            $func = "\\Core2\\" . $this->functions[$w]['name'];
            $objects[$w_full] = new $func();

            $request = "\0REQ" . // Магическое число (запрос)
                pack('N', 1) . //CAN_DO
                pack('N', strlen($w_full)) .
                $w_full;
            fwrite($worker, $request);

        }


        $start = time();
        $tick = time();
        $buffer = '';

        while (!$this->stop_work) {

            $request = "\0REQ" . // Магическое число (запрос)
                //pack('N', 9) . //GRAB_JOB
                pack('N', 30) . //GRAB_JOB_UNIQ
                pack('N', 0);
            if (fwrite($worker, $request) === false) {
                $this->toLog("Server FAILED GRAB_JOB_UNIQ", self::LOG_LEVEL_PROC_INFO);
                $this->stop_work = true;
            }

            // Читаем данные от сервера
            $data = fread($worker, 20480);

//            echo "Received data: " . bin2hex($data) . "\n"; // Вывод сырых данных для отладки

            if (strpos($data, "\0RES") === 0) {
                $array = unpack("Nmagic/Ntype/Nlength/Z*job", $data);
                $type  = $array['type'];

                if ($type !== 10) { //NO_JOB
                    $job   = trim($array['job']);
                    $length = strlen($job) + 12; //длина названия job + начальные 12 байт
                    $array  = unpack("@$length/a*", $data);
                    $body   = trim($array[1]);
//                    echo "LENGTH: " . strlen($data) . "\n";
//                    echo "JOB: $job\n";
//                    echo "TYPE: $type\n";
//                    echo "BODY: $body\n";

                    if ($type === 6) { //NOOP
                        echo "WAKEUP!\n";
                    }
                    elseif ($type === 11 || $type === 31 || $type === 40) { //JOB_ASSIGN JOB_ASSIGN_UNIQ JOB_ASSIGN_ALL
                        // Парсим задачу
                        foreach ($objects as $function => $runner) {
                            if (strpos($body, $function) === 0) break;
                            $function = '';
                        }
                        if (!empty($objects[$function])) { //узнали функцию
                            $unique = '';
                            $payload = trim(substr($body, strlen($function)));
                            if (strpos($payload, "{") !== 0) {
                                //значит есть уникальный id задачи
                                $unique = substr($payload, 0, strpos($payload, "{"));
                                $payload = substr($payload, strpos($payload, "{"));
                            }
                            if (!str_ends_with($payload, "}|")) {
                                //забрали не все занные
                                echo "EXTRA $job\n";
                                $this->toLog("EXTRA data for job $job", self::LOG_LEVEL_WORKER_INFO);
                                $request = "\0REQ" . // Магическое число (запрос)
                                    //pack('N', 9) . //GRAB_JOB
                                    pack('N', 30) . //GRAB_JOB_UNIQ
                                    pack('N', 0);
                                $send = fwrite($worker, $request);
                                if (!$send) {
                                    break;
                                }
                                // Читаем данные от сервера
                                $data = fread($worker, 20480);
                                if ($data === false) {
                                    echo "Error reading from server\n";
                                    break;
                                }
                                $payload .= $data;
                            }

                            $json = preg_replace('/[[:cntrl:]]/', '', substr($payload, 0, -1));
                            $payload = json_decode($json);

                            if (json_last_error() === JSON_ERROR_NONE) {
                                // Выполняем задачу
                                $result = "";
                                $log = array();
//                                $f = fopen("/home/easter/job.log", "a");
//                                fwrite($f, $this->pid . " " . $job . "\n");

                                try {
                                    $result = $objects[$function]->run(new Job($job, $payload, $unique), $log);
                                    foreach ($log as $item) {
                                        $this->toLog("Function $function said: $item", self::LOG_LEVEL_WORKER_INFO);
                                    }
                                    $request = "\0REQ" . // Магическое число (запрос)
                                        pack('N', 13) . //WORK_COMPLETE
                                        pack('N', strlen($job) + strlen($result) + 1) .
                                        $job . "\0" .
                                        $result;
                                    fwrite($worker, $request);

                                    //echo "Worker completed job: $job with result: $result\n";

                                } catch (\Exception $e) {
                                    $msg = $e->getMessage();
                                    $request = "\0REQ" . // Магическое число (запрос)
                                        pack('N', 25) . //WORK_EXCEPTION
                                        pack('N', strlen($job) + strlen($msg) + 1) .
                                        $job . "\0" .
                                        $msg;
                                    fwrite($worker, $request);
                                    //echo "Worker FAILED job: $job with exception: $msg \n\n";
                                    $this->toLog("Worker FAILED job: $job with exception: $msg", self::LOG_LEVEL_WORKER_INFO);
                                }

                            }
                            else {
                                $msg = json_last_error_msg();
                                $request = "\0REQ" . // Магическое число (запрос)
                                    pack('N', 25) . //WORK_EXCEPTION
                                    pack('N', strlen($job) + strlen($msg) + 1) .
                                    $job . "\0" .
                                    $msg;
                                fwrite($worker, $request);
                                echo "Worker FAILED job: $job with exception: $msg \n\n" . strlen($data) . "\n\n$function\n\n$json\n\n";
                                $this->toLog("Worker FAILED job: $job with exception: $msg", self::LOG_LEVEL_WORKER_INFO);
                            }
                        }
                        else {
                            // не смогли распознать задачу (такого быть не может)
                            $request = "\0REQ" . // Магическое число (запрос)
                                pack('N', 14) . //WORK_FAIL
                                pack('N', strlen($job)) .
                                $job;
                            fwrite($worker, $request);
                            echo "Function FAILED: $body \n";
                            $this->toLog("Function FAILED: $function", self::LOG_LEVEL_WORKER_INFO);
                        }
                    } else {
                        //неизвестный код ответа
                        $request = "\0REQ" . // Магическое число (запрос)
                            pack('N', 14) . //WORK_FAIL
                            pack('N', strlen($job)) .
                            $job;
                        fwrite($worker, $request);
                        echo "Worker TYPE: $type FAILED job: $job \n";
                        $this->toLog("Worker TYPE $type FAILED: $data", self::LOG_LEVEL_WORKER_INFO);
                    }

                } else {
                    //if (time() - $tick > 10) {
                    //    $tick = time();
//                        $request = "\0REQ" . // Магическое число (запрос)
//                            pack('N', 4) . //PRE_SLEEP
//                            pack('N', 0);
//                        fwrite($worker, $request);
                        //echo "Worker TYPE: $type \n";
                        //TODO подсчитывать время отсутствия задач и осводождать ресурсы при долгом простое
                        usleep(5000);
                    //}
                }

            }
            else {
                if ($data) {
                    echo "Server FAILED job: $data \n";
                    $this->toLog("Server FAILED job: $data", self::LOG_LEVEL_WORKER_INFO);
                }
            }

            /**
             * Check the running time of the current child. If it has
             * been too long, stop working.
             */
            if ($this->max_run_time > 0 && time() - $start > $this->max_run_time) {
                $this->toLog("Been running too long, exiting", self::LOG_LEVEL_WORKER_INFO);
                $this->stop_work = true;
            }

            if (!empty($this->config["max_runs_per_worker"]) && $this->job_execution_count >= $this->config["max_runs_per_worker"]) {
                $this->toLog("Ran $this->job_execution_count jobs which is over the maximum({$this->config['max_runs_per_worker']}), exiting", self::LOG_LEVEL_WORKER_INFO);
                $this->stop_work = true;
            }

        }

        if ($worker) {
            $request = "\0REQ" . // Магическое число (запрос)
                pack('N', 3) . //RESET_ABILITIES
                pack('N', 0);
            fwrite($worker, $request);
        }
    }

    /**
     * Wrapper function handler for all registered functions
     * This allows us to do some nice logging when jobs are started/finished
     */
    public function do_job($job) {

        static $objects;

        if ($objects === null) $objects = array();

//        $w = $job->workload();

        $h = $job->handle();
//        echo "<PRE>";print_r($job->returnCode());echo "</PRE>\n";//die;

//        echo $h . chr(10);//die;
//        echo $job->unique() .chr(10);//die;
        //TODO control handlers
        $job_name = $job->functionName();
        $job_name = explode("-", $job_name);
        $job_name = end($job_name);

        if ($this->prefix) {
            $func = $this->prefix . $job_name;
        } else {
            $func = $job_name;
        }

        //имя воркера с учетом хоста
        $job_name_log = $this->getRealJobName($job_name);

        if (empty($objects[$job_name]) && !class_exists("\Core2\\" . $func, false)) {
            //инициализация воркеров
            if (!isset($this->functions[$job_name])) {
                $this->toLog("Function $func is not a registered job name");
            }

            require_once $this->functions[$job_name]["path"];

            if (class_exists("\Core2\\" . $func) && method_exists("\Core2\\" . $func, "run")) {

                $this->toLog("Creating a $func object", self::LOG_LEVEL_WORKER_INFO);
                $ns_func = "\Core2\\$func";
                $objects[$job_name] = new $ns_func();

            }
            $this->toLog("($h) Starting Job!: $job_name_log", self::LOG_LEVEL_WORKER_INFO);
        }

        $log = array();

        /**
         * Run the real function here
         */
        $result = null;
        if (isset($objects[$job_name])) {
//            echo $job_name_log.PHP_EOL;
            $this->toLog("($h) Calling object for $job_name_log.", self::LOG_LEVEL_DEBUG);
            try {
                $job->sendData($this->pid_file);
                $result = $objects[$job_name]->run($job, $log);
                if ($result && $job->handle()) {
                    $job->sendComplete('done');
                    $log[] = "Finish Job: $job_name_log";
                }
                else {
                    $job->sendFail();
//                    echo "!!!FAIL $job_name_log \n";
                }
            } catch (\Exception $e) {
                $this->toLog($e->getMessage(), self::LOG_LEVEL_WORKER_INFO);
                $job->sendException($e->getMessage());
                $job->sendFail();
            }
        }
        else {
            $this->toLog("($h) FAILED to find a function or class for $job_name_log.", self::LOG_LEVEL_INFO);
            $job->sendException("Object $job_name_log not found");
            $job->sendFail();
        }

//        echo "<PRE>";print_r($job->returnCode());echo "</PRE>\n";//die;

        if (!empty($log)) {
            foreach ($log as $l) {

                if (!is_scalar($l)) {
                    $l = explode("\n", trim(print_r($l, true)));
                } elseif (strlen($l) > 256) {
                    $l = substr($l, 0, 256)."...(truncated)";
                }

                if (is_array($l)) {
                    foreach ($l as $ln) {
                        $this->toLog("($h) $ln", self::LOG_LEVEL_WORKER_INFO);
                    }
                } else {
                    $this->toLog("($h) $l", self::LOG_LEVEL_WORKER_INFO);
                }

            }
        }

        $result_log = $result;

        if (!is_scalar($result_log)) {
            $result_log = explode("\n", trim(print_r($result_log, true)));
        } elseif (strlen($result_log) > 256) {
            $result_log = substr($result_log, 0, 256)."...(truncated)";
        }

        if (is_array($result_log)) {
            foreach ($result_log as $ln) {
                $this->toLog("($h) $ln", self::LOG_LEVEL_DEBUG);
            }
        } else {
            $this->toLog("($h) $result_log", self::LOG_LEVEL_DEBUG);
        }

        /**
         * Workaround for PECL bug #17114
         * http://pecl.php.net/bugs/bug.php?id=17114
         */
        $type = gettype($result);
        settype($result, $type);

        $this->job_execution_count++;

        return $result;

    }

    private function validate_lib_workers() {

        //$dd = str_replace(DIRECTORY_SEPARATOR, "-", dirname(dirname(__DIR__)));
        //$dd = trim($dd, '-');
        foreach ($this->functions as $func => $props) {
            if (!file_exists($props["path"])) {
                $this->toLog("File {$props["path"]} not found!");
                posix_kill($this->pid, SIGUSR2);
                exit();
            }
            require_once $props["path"];
            $real_func = $this->prefix . $func;
            if (!class_exists("\\Core2\\" . $real_func) || !method_exists("\\Core2\\" . $real_func, "run")) {
                $this->toLog("Class $real_func not found in " . $props["path"]);
                posix_kill($this->pid, SIGUSR2);
                exit();
            }
        }

    }

    private function getRealJobName($job_name) {
        $dd = str_replace(DIRECTORY_SEPARATOR, "-", dirname(dirname(__DIR__)));
        $dd = trim($dd, '-');
        return $dd . "-" . $job_name;
    }

    public function fatal_handler()
    {
        $errfile = "unknown file";
        $errstr  = "shutdown";
        $errno   = E_CORE_ERROR;
        $errline = 0;

        $error = error_get_last();

        if ($error !== NULL) {
            $errno   = $error["type"];
            $errfile = $error["file"];
            $errline = $error["line"];
            $errstr  = $error["message"];
            $trace   = print_r(debug_backtrace(), true);
            if ($this->verbose == self::LOG_LEVEL_DEBUG) echo $errstr . chr(10);
            $this->toLog($errstr . chr(10) . $trace, self::LOG_LEVEL_PROC_INFO);
        }
    }
}