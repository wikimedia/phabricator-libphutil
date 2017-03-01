<?php

/**
 * Oversees a daemon and restarts it if it fails.
 *
 * @task signals Signal Handling
 */
final class PhutilDaemonOverseer extends Phobject {

  private $argv;
  private static $instance;

  private $config;
  private $pools = array();
  private $traceMode;
  private $traceMemory;
  private $daemonize;
  private $piddir;
  private $log;
  private $libraries = array();
  private $modules = array();
  private $verbose;
  private $lastPidfile;
  private $startEpoch;
  private $autoscale = array();
  private $autoscaleConfig = array();

  const SIGNAL_NOTIFY = 'signal/notify';
  const SIGNAL_RELOAD = 'signal/reload';
  const SIGNAL_GRACEFUL = 'signal/graceful';
  const SIGNAL_TERMINATE = 'signal/terminate';

  private $err = 0;
  private $inAbruptShutdown;
  private $inGracefulShutdown;

  public function __construct(array $argv) {
    PhutilServiceProfiler::getInstance()->enableDiscardMode();

    $args = new PhutilArgumentParser($argv);
    $args->setTagline(pht('daemon overseer'));
    $args->setSynopsis(<<<EOHELP
**launch_daemon.php** [__options__] __daemon__
    Launch and oversee an instance of __daemon__.
EOHELP
      );
    $args->parseStandardArguments();
    $args->parse(
      array(
        array(
          'name' => 'trace-memory',
          'help' => pht('Enable debug memory tracing.'),
        ),
        array(
          'name'  => 'verbose',
          'help'  => pht('Enable verbose activity logging.'),
        ),
        array(
          'name' => 'label',
          'short' => 'l',
          'param' => 'label',
          'help' => pht(
            'Optional process label. Makes "%s" nicer, no behavioral effects.',
            'ps'),
        ),
      ));
    $argv = array();

    if ($args->getArg('trace')) {
      $this->traceMode = true;
      $argv[] = '--trace';
    }

    if ($args->getArg('trace-memory')) {
      $this->traceMode = true;
      $this->traceMemory = true;
      $argv[] = '--trace-memory';
    }
    $verbose = $args->getArg('verbose');
    if ($verbose) {
      $this->verbose = true;
      $argv[] = '--verbose';
    }

    $label = $args->getArg('label');
    if ($label) {
      $argv[] = '-l';
      $argv[] = $label;
    }

    $this->argv = $argv;

    if (function_exists('posix_isatty') && posix_isatty(STDIN)) {
      fprintf(STDERR, pht('Reading daemon configuration from stdin...')."\n");
    }
    $config = @file_get_contents('php://stdin');
    $config = id(new PhutilJSONParser())->parse($config);

    $this->libraries = idx($config, 'load');
    $this->log = idx($config, 'log');
    $this->daemonize = idx($config, 'daemonize');
    $this->piddir = idx($config, 'piddir');

    $this->config = $config;

    if (self::$instance) {
      throw new Exception(
        pht('You may not instantiate more than one Overseer per process.'));
    }

    self::$instance = $this;

    $this->startEpoch = time();

    // Check this before we daemonize, since if it's an issue the child will
    // exit immediately.
    if ($this->piddir) {
      $dir = $this->piddir;
      try {
        Filesystem::assertWritable($dir);
      } catch (Exception $ex) {
        throw new Exception(
          pht(
            "Specified daemon PID directory ('%s') does not exist or is ".
            "not writable by the daemon user!",
            $dir));
      }
    }

    if (!idx($config, 'daemons')) {
      throw new PhutilArgumentUsageException(
        pht('You must specify at least one daemon to start!'));
    }

    if ($this->log) {
      // NOTE: Now that we're committed to daemonizing, redirect the error
      // log if we have a `--log` parameter. Do this at the last moment
      // so as many setup issues as possible are surfaced.
      ini_set('error_log', $this->log);
    }

    if ($this->daemonize) {
      // We need to get rid of these or the daemon will hang when we TERM it
      // waiting for something to read the buffers. TODO: Learn how unix works.
      fclose(STDOUT);
      fclose(STDERR);
      ob_start();

      $pid = pcntl_fork();
      if ($pid === -1) {
        throw new Exception(pht('Unable to fork!'));
      } else if ($pid) {
        exit(0);
      }
    }

    $this->modules = PhutilDaemonOverseerModule::getAllModules();

    $this->installSignalHandlers();
  }

  public function addLibrary($library) {
    $this->libraries[] = $library;
    return $this;
  }

  public function run() {
    $this->createDaemonPools();

    while (true) {
      if ($this->shouldReloadDaemons()) {
        $this->didReceiveSignal(SIGHUP);
      }

      $futures = array();
      foreach ($this->getDaemonPools() as $pool) {
        $pool->updatePool();

        foreach ($pool->getFutures() as $future) {
          $futures[] = $future;
        }
      }

      $this->updatePidfile();
      $this->updateMemory();

      $this->waitForDaemonFutures($futures);

      if (!$futures) {
        if ($this->inGracefulShutdown) {
          break;
        }
      }
    }

    exit($this->err);
  }


  private function waitForDaemonFutures(array $futures) {
    assert_instances_of($futures, 'ExecFuture');

    if ($futures) {
      // TODO: This only wakes if any daemons actually exit. It would be a bit
      // cleaner to wait on any I/O with Channels.
      $iter = id(new FutureIterator($futures))
        ->setUpdateInterval(1);
      foreach ($iter as $future) {
        break;
      }
    } else {
      if (!$this->inGracefulShutdown) {
        sleep(1);
      }
    }
  }

  private function createDaemonPools() {
    $configs = $this->config['daemons'];

    $forced_options = array(
      'load' => $this->libraries,
      'log' => $this->log,
    );

    foreach ($configs as $config) {
      $config = $forced_options + $config;

      $pool = PhutilDaemonPool::newFromConfig($config)
        ->setOverseer($this)
        ->setCommandLineArguments($this->argv);

      $this->pools[] = $pool;
    }
  }

  private function getDaemonPools() {
    return $this->pools;
  }


  /**
   * Identify running daemons by examining the process table. This isn't
   * completely reliable, but can be used as a fallback if the pid files fail
   * or we end up with stray daemons by other means.
   *
   * Example output (array keys are process IDs):
   *
   *   array(
   *     12345 => array(
   *       'type' => 'overseer',
   *       'command' => 'php launch_daemon.php --daemonize ...',
   *       'pid' => 12345,
   *     ),
   *     12346 => array(
   *       'type' => 'daemon',
   *       'command' => 'php exec_daemon.php ...',
   *       'pid' => 12346,
   *     ),
   *  );
   *
   * @return dict   Map of PIDs to process information, identifying running
   *                daemon processes.
   */
  public static function findRunningDaemons() {
    $results = array();

    list($err, $processes) = exec_manual('ps -o pid,command -a -x -w -w -w');
    if ($err) {
      return $results;
    }

    $processes = array_filter(explode("\n", trim($processes)));
    foreach ($processes as $process) {
      list($pid, $command) = preg_split('/\s+/', trim($process), 2);

      $pattern = '/((launch|exec)_daemon.php|phd-daemon)/';
      $matches = null;
      if (!preg_match($pattern, $command, $matches)) {
        continue;
      }

      switch ($matches[1]) {
        case 'exec_daemon.php':
          $type = 'daemon';
          break;
        case 'launch_daemon.php':
        case 'phd-daemon':
        default:
          $type = 'overseer';
          break;
      }

      $results[(int)$pid] = array(
        'type' => $type,
        'command' => $command,
        'pid' => (int)$pid,
      );
    }

    return $results;
  }

  private function updatePidfile() {
    if (!$this->piddir) {
      return;
    }

    $pidfile = $this->toDictionary();

    if ($pidfile !== $this->lastPidfile) {
      $this->lastPidfile = $pidfile;
      $pidfile_path = $this->piddir.'/daemon.'.getmypid();
      Filesystem::writeFile($pidfile_path, phutil_json_encode($pidfile));
    }
  }

  public function toDictionary() {
    $daemons = array();
    foreach ($this->getDaemonPools() as $pool) {
      foreach ($pool->getDaemons() as $daemon) {
        if (!$daemon->isRunning()) {
          continue;
        }

        $daemons[] = $daemon->toDictionary();
      }
    }

    return array(
      'pid' => getmypid(),
      'start' => $this->startEpoch,
      'config' => $this->config,
      'daemons' => $daemons,
    );
  }

  private function updateMemory() {
    if (!$this->traceMemory) {
      return;
    }

    $this->logMessage(
      'RAMS',
      pht(
        'Overseer Memory Usage: %s KB',
        new PhutilNumber(memory_get_usage() / 1024, 1)));
  }

  public function logMessage($type, $message, $context = null) {
    if ($this->traceMode || $this->verbose) {
      error_log(date('Y-m-d g:i:s A').' ['.$type.'] '.$message);
    }
  }


/* -(  Signal Handling  )---------------------------------------------------- */


  /**
   * @task signals
   */
  private function installSignalHandlers() {
    $signals = array(
      SIGUSR2,
      SIGHUP,
      SIGINT,
      SIGTERM,
    );

    foreach ($signals as $signal) {
      pcntl_signal($signal, array($this, 'didReceiveSignal'));
    }
  }


  /**
   * @task signals
   */
  public function didReceiveSignal($signo) {
    switch ($signo) {
      case SIGUSR2:
        $signal_type = self::SIGNAL_NOTIFY;
        break;
      case SIGHUP:
        $signal_type = self::SIGNAL_RELOAD;
        break;
      case SIGINT:
        // If we receive SIGINT more than once, interpret it like SIGTERM.
        if ($this->inGracefulShutdown) {
          return $this->didReceiveSignal(SIGTERM);
        }

        $this->inGracefulShutdown = true;
        $signal_type = self::SIGNAL_GRACEFUL;
        break;
      case SIGTERM:
        // If we receive SIGTERM more than once, terminate abruptly.
        $this->err = 128 + $signo;
        if ($this->inAbruptShutdown) {
          exit($this->err);
        }

        $this->inAbruptShutdown = true;
        $signal_type = self::SIGNAL_TERMINATE;
        break;
      default:
        throw new Exception(
          pht(
            'Signal handler called with unknown signal type ("%d")!',
            $signo));
    }

    foreach ($this->getDaemonPools() as $pool) {
      $pool->didReceiveSignal($signal_type, $signo);
    }
  }


/* -(  Daemon Modules  )----------------------------------------------------- */


  private function getModules() {
    return $this->modules;
  }

  private function shouldReloadDaemons() {
    $modules = $this->getModules();

    $should_reload = false;
    foreach ($modules as $module) {
      try {
        // NOTE: Even if one module tells us to reload, we call the method on
        // each module anyway to make calls a little more predictable.

        if ($module->shouldReloadDaemons()) {
          $this->logMessage(
            'RELO',
            pht(
              'Reloading daemons (triggered by overseer module "%s").',
              get_class($module)));
          $should_reload = true;
        }
      } catch (Exception $ex) {
        phlog($ex);
      }
    }

    return $should_reload;
  }


}
