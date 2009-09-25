<?php

/**
 * MultiThreaded Daemon (MTD)
 * 
 * Copyright (c) 2007, Benoit Perroud
 * 
 * All rights reserved.
 * 
 * Redistribution and use in source and binary forms, with or
 * without modification, are permitted provided that the following
 * conditions are met: Redistributions of source code must retain the
 * above copyright notice, this list of conditions and the following
 * disclaimer. Redistributions in binary form must reproduce the above
 * copyright notice, this list of conditions and the following disclaimer
 * in the documentation and/or other materials provided with the
 * distribution.
 * 
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR
 * CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL,
 * EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO,
 * PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR
 * PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF
 * LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING
 * NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
 * SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * @package     MTD
 * @author      Benoit Perroud <ben@migtechnology.ch>
 * @copyright   2007 Benoit Perroud
 * @license     http://www.gnu.org/copyleft/gpl.html
 * @version     $Id: class.MTDaemon.php 8 2007-10-19 09:16:00Z killerwhile $
 *
 * See http://code.google.com/p/phpmultithreadeddaemon/ 
 * and http://phpmultithreaddaemon.blogspot.com/ for more information
 *
 */

/*
 * Modifications by Daniel Kadosh, Affinegy Inc., June 2009
 * Made many enhancements for robustness:
 * - Truly daemonized by starting process group with posix_setsid()
 * - Surrounded key items in try/catch for proper logging
 * - Handling of SIGTERM/ SIGQUIT = no more children spawned, wait for all threads to die.
 * - Handling of SIGHUP = call to loadConfig() method
 * - Created PID file to ensure only 1 copy of the daemon is running
 * - Full cleanup of semaphores & PID file on exit
 *
 */

require_once dirname(__FILE__).'/class.MTLog.php';

/*
 * Added code for better daemon behavior by handling signals, from
 *    http://www.van-steenbeek.net/?q=php_pcntl_fork
 */
declare (ticks=1); // Be sure that each signal is handled when it is received.
ini_set("max_execution_time", "0"); // Give us eternity to execute the script. We can always kill -9
ini_set("max_input_time", "0");
set_time_limit(0);
$nSignalReceived = NULL;
function mtd_sig_handler($signo) {
	// Just capture signal in a global, deal with it in handle() method
	// TODO: multiple unhandled signals received would mean last one wins...
	global $nSignalReceived;
	$sSigMsg = "";
	switch ( $signo ) {
		case SIGQUIT:	// Treat same as SIGTERM
		case SIGTERM:
			$nSignalReceived = SIGTERM;
			$sSigMsg = "SIGTERM = terminate. Will end once all children die.";
			break;
		case SIGHUP:
			$nSignalReceived = SIGHUP;
			$sSigMsg = "SIGHUP = hangup. Will re-read config files.";
			break;
			default:
		$sSigMsg = "$signo -- ignored.";
			break;
	}
	MTLog::getInstance()->warn('Received signal '.$sSigMsg);
}
pcntl_signal(SIGTERM, "mtd_sig_handler");
pcntl_signal(SIGHUP, "mtd_sig_handler");

// Main class
abstract class MTDaemon {
	/*
	 * Configuration vaiables
	 */

	// max concurrent threads
	// should be implemented with sem_get('name', $max_aquire) but this can't be dynamically updated as this var.
	protected $max_threads = 4;

	// sleep time when no job
	protected $idle_sleep_time = 5;

	/*
	 * Internal constants
	 */
	const _INDEX_DATA = 0;    // shared data goes here.
	const _INDEX_THREADS = 1; // how many threads are working
	const _INDEX_SLOTS = 2;   // which slot do the current thread use ?

	/*
	 * Internal variables
	 */
	protected $shm;                      // = ftok(__FILE__, 'g');
	protected $shared_data;              // = shm_attach($this->shm);

	protected $mutex;                    // lock critical path, used in lock() and unlock()
	protected $mutex_main_process;       // lock main process only. Children can continue to run
	protected $mutex_children_processes; // lock children processes only. Main can continue to run

	protected $main_thread_pid;

	protected $sPIDFileName;

	/*
	 * Constructor
	 * 
	 * @params $threads : number of concurrent threads, default 4
	 * @params $idelsleeptime : time to sleep when no job ready (getNext return null), in seconds, default 5 
	 */
	public function __construct($threads = null, $idlesleeptime = null, $sPIDFileName = null) {
		global $argv;
        $this->main_thread_pid = posix_getpid();
		// Ensure a PID file is created
        if ( !$sPIDFileName ) $sPIDFileName = $this->makePIDFileName();
		$this->sPIDFileName = $sPIDFileName;
		if ( !$this->checkPIDFile() ) {
			throw new Exception('Cannot secure PID file '.$this->sPIDFileName);
		}

		// Daemonize -- fork & disconnect from tty
		// NOTE: stdout/stderr cannot be automatically redirected to logger,
		//   so avoid print & echo or start daemon in CLI with ">& /dev/null"
		$newpid = pcntl_fork();
		if ( $newpid==-1 ) {
			throw new Exception('Cannot fork porcess');
		} elseif ( $newpid ) {
			print $argv[0].": Starting daemon under pid=$newpid\n";
			exit();
		}
		if ( !posix_setsid() ) {
			throw new Exception('Cannot dettach from terminal!');
		}

		// Init some variables
		if ( $threads ) $this->max_threads = $threads;
		if ( $idlesleeptime ) $this->idle_sleep_time = $idlesleeptime;
		
		if ( !$this->writePIDFile() ) {
			// Should never happen, unless disk full...
			throw new Exception('Cannot write to PID file!');
		}

	}

    function makePIDFileName() {
        $name=join('_',$_SERVER["argv"]);
        $name=preg_replace('/\W/', '_',$name);
        $key="/tmp/mtd3_".$name."_".$this->main_thread_pid.".pid";
        return $key;
    }

	/*
	 * Manage PID file
	 */
	protected function checkPIDFile() {
		// Ensure no currently running daemon
		if ( file_exists($this->sPIDFileName) ) {
			$nOldPID = trim(file_get_contents($this->sPIDFileName));

			// Clean up if previous daemon didn't clean up after itself
			// and handle EPERM error
			//   http://www.php.net/manual/en/function.posix-kill.php#82560
			$bRunning = posix_kill($nOldPID, 0);
			if ( posix_get_last_error()==1 ) $bRunning = true;
			if ( !$bRunning ) unlink($this->sPIDFileName);

			/*
			 * Could be dangerous to kill old daemon...
			MTLog::getInstance()->warn('Killing old deamon from '.$this->sPIDFileName);
			if ( posix_kill($nOldPID,SIGTERM) ) {
				// Sent signal, give it a chance to die
				sleep ($this->idle_sleep_time * 2);
			}
			 */
		}
		if ( file_exists($this->sPIDFileName) ) {
			MTLog::getInstance()->error('Daemon pid='.$nOldPID.' still running according to PID file '.$this->sPIDFileName);
			return false;
		}
		if ( !touch($this->sPIDFileName) ) {
			MTLog::getInstance()->error('Cannot create PID file '.$this->sPIDFileName);
			return false;
		}
		return true;
	}

	/*
	 *
	 */
	protected function writePIDFile() {
		// Just touched it, so MUST be able to write to it!
		if ( !file_put_contents($this->sPIDFileName, $this->main_thread_pid) ) {
			MTLog::getInstance()->error('Cannot write PID file '.$this->sPIDFileName);
			return false;
		}
		// Success!
		MTLog::getInstance()->debug('Successfully created PID file '.$this->sPIDFileName);
		return true;
	}

	/*
	 *
	 */
	public function getPIDFile() {
		return $this->sPIDFileName;
	}

	/*
	 * Hook called just before the main loop.
	 * 
	 * Remark : cleanup code goes here.
	 */
	protected function _prerun() {
		global $argv;
		MTLog::getInstance()->info($argv[0].': Starting daemon with '.$this->max_threads.' slots');

		$this->shm = ftok($this->sPIDFileName, 'g'); // global shm
		$this->shared_data = shm_attach($this->shm);
		$this->mutex = sem_get($this->shm);
		$this->mutex_main_process = sem_get(ftok($this->sPIDFileName, 'm'));
		$this->mutex_children_processes = sem_get(ftok($this->sPIDFileName, 'c'));

		shm_put_var($this->shared_data, self::_INDEX_DATA, array());

		$this->setThreads(0);

		$slots = array();
		for ( $i = 0; $i<$this->max_threads; $i++ ) {
			$slots[] = false;
		}
		shm_put_var($this->shared_data, self::_INDEX_SLOTS, $slots);

		// Call to optional method
		$this->loadConfig();
	}

	/*
	 * Hook called just after the main loop
	 * Cleans up all semaphores and removes PID file
	 */
	protected function _postrun() {
		global $argv;
		MTLog::getInstance()->debug($argv[0].': _postrun() called.');

		// Clean up all UNIX semaphore data
		shm_remove ($this->shared_data);
		sem_remove ($this->mutex);
		sem_remove ($this->mutex_main_process);
		sem_remove ($this->mutex_children_processes);
		shm_detach ($this->shared_data);

		unlink ($this->sPIDFileName);
		MTLog::getInstance()->info($argv[0].': daemon exited.');
	}

	/*
	 * Main loop, request next job using getNext() and execute run($job) in a separate thread
	 * _prerun and _postrun hooks are called before and after the main loop -> usefull for cleanup and so on.
	 */
	public function handle() {
		global $nSignalReceived;

		$this->run = true;
		$this->bTerminate = false;

		$this->_prerun();
		while ( $this->run ) {
			/* 
			 * Terminating all child, to not let some zombie leaking the memory.
			 */

			MTLog::getInstance()->debug2('-- Next iteration ');

			$this->lock();

			// HACK : avoid zombie and free earlier the memory
			do {
				$res = pcntl_wait($status, WNOHANG);
				MTLog::getInstance()->debug2('$res = pcntl_wait($status, WNOHANG); called with $res = '.$res);
				if ( $res>0 ) MTLog::getInstance()->debug('(finishing child with pid '.$res.')');
			} while ( $res>0 );

			/*
			 * Process signals now, before waiting for a slot to free up
			 */
			if ( $nSignalReceived ) {
				switch ( $nSignalReceived ) {
					case SIGTERM:
						$this->bTerminate = true;
						break;
					case SIGHUP:
						$this->loadConfig();
						break;
					default: break;
				}
				$nSignalReceived = NULL;
			}

			/*
			 * Loop until a slot frees 
			 */
			while ( !$this->bTerminate && !$this->hasFreeSlot() ) {
				$this->unlock();
				MTLog::getInstance()->debug('No more free slot, waiting');
				$res = pcntl_wait($status); // wait until a child ends up
				MTLog::getInstance()->debug2('$res = pcntl_wait($status); called with $res = '.$res);
				if ( $res>0 ) {
					MTLog::getInstance()->debug('Finishing child with pid '.$res);
				} else {
					MTLog::getInstance()->error('Outch1, this souldn\'t happen. Verify your implementation ...');
					$this->run = false;
					break;
				}
				$this->lock();
			}

			/*
			 * Handle Outch1 error and
			 * Double-check for signal before starting a new child
			 */
			if ( !$this->run ) continue;
			if ( $nSignalReceived ) {
				$this->unlock();
				continue;
			}

			/*
			 * If terminating, just loop until all jobs are finished.
			 */
			if ( $this->bTerminate ) {
				MTLog::getInstance()->info('Terminating: Waiting for '.$this->getThreads().' threads to end');
				while ( ($nThreads = $this->getThreads())>0 ) {
					$this->unlock();
					MTLog::getInstance()->debug('Waiting for '.$nThreads.' threads to end');
					$res = pcntl_wait($status); // wait until a child ends up
					MTLog::getInstance()->debug2('$res = pcntl_wait($status); called with $res = '.$res);
					if ( $res>0 ) {
						MTLog::getInstance()->debug('Finishing child with pid '.$res);
					} else {
						MTLog::getInstance()->error('Outch3, this souldn\'t happen. Verify your implementation ...');
						$this->run = false;
						break;
					}
					$this->lock();
				}
				if ( $this->run ) $this->unlock(); // don't double unlock
				$this->run = false;
				continue;
			}

			$slot = $this->requestSlot();
			$this->incThreads();

			$this->unlock();
			if ( $slot===null ) {
				var_dump(shm_get_var($this->shared_data, self::_INDEX_DATA));
				var_dump(shm_get_var($this->shared_data, self::_INDEX_THREADS));
				var_dump(shm_get_var($this->shared_data, self::_INDEX_SLOTS));

				MTLog::getInstance()->error('Outch2, this souldn\'t happen. Verify your implementation ...');
				$this->run = false; // Quit now
				continue;
			}

			/*
			 * Request next action to handle
			 */
			try {
				$next = &$this->getNext($slot);
			} catch( Exception $e ) {
				MTLog::getInstance()->error('getNext() method: '.$e->getMessage());
				$this->bTerminate = true;
				continue;
			}

			/*
			 * If no job
			 */
			if ( !$next ) {
				// Within parent process, release slot & sleep for a bit...

				MTLog::getInstance()->debug('No job, sleeping at most '.$this->idle_sleep_time.' sec ... ');

				// TODO : waiting for signal pushed into a queue when inserting a new job.

				$this->lock();
				$this->releaseSlot($slot);
				$this->decThreads();
				$this->unlock();

				sleep ($this->idle_sleep_time);
				continue;
			} else {
				// Fork off new child & do some work
				$pid = pcntl_fork();
				if ( $pid==-1 ) {
					MTLog::getInstance()->error('[fork] Duplication impossible');
					// $this->run = false; // Quit now
					$this->bTerminate = true; // Wait till children finish
					continue;
				} else if ( $pid ) {
					unset ($next);
					usleep(10); // HACK : give the hand to the child -> a simple way to better handle zombies
					continue;
				} else {
					MTLog::getInstance()->debug('Executing thread #'.posix_getpid().' in slot '.number_format($slot));
					try {
						$res = $this->run($next, $slot);
					} catch( Exception $e ) {
						MTLog::getInstance()->error('run() method: '.$e->getMessage());
						$res = -1;
					}
					unset ($next);

					$this->lock();
					$this->releaseSlot($slot);
					$this->decThreads();
					$this->unlock();

					exit ($res);
				}
			}
		}

		$this->_postrun();
		exit (0);
	}

	/*
	 * Called at init, plus at SIGHUP signal
	 * This function is run in the parent thread
	 */
	//abstract public function loadConfig();
    public function loadConfig(){}

	/*
	 * Request data of the next element to run in a thread
	 * This function will return the next element to process, or null if there is currently no job and the daemon should wait. 
	 * 
	 * slot = where the thread will be executed
	 * return null or false if no job currently
	 */
	abstract public function getNext($slot);

	/*
	 * Process the element fetched by getNext in a new thread
	 * This function is run in a separated thread (after forking) and take as argument the element to process (given by getNext).
	 *
	 * slot = where the thread will be executed
	 * return the exiting status of the thread
	 */
	abstract public function run($next, $slot);

	/*
	 *
	 */
	protected function lock() {
		MTLog::getInstance()->debug2('[lock] lock');
		$res = sem_acquire($this->mutex);
		if ( !$res ) exit(-1);
	}

	/*
	 *
	 */
	protected function unlock() {
		MTLog::getInstance()->debug2('[lock] unlock');
		$res = sem_release($this->mutex);
		if ( !$res ) exit(-1);
	}

	/*
	 *
	 */
	protected function lockMain() {
		MTLog::getInstance()->debug2('[lock] lock main process');
		$res = sem_acquire($this->mutex_main_process);
		if ( !$res ) exit(-1);
	}

	/*
	 *
	 */
	protected function unlockMain() {
		MTLog::getInstance()->debug2('[lock] unlock main process');
		$res = sem_release($this->mutex_main_process);
		if ( !$res ) exit(-1);
	}

	/*
	 *
	 */
	protected function lockChildren() {
		MTLog::getInstance()->debug2('[lock] lock children processes');
		$res = sem_acquire($this->mutex_children_processes);
		if ( !$res ) exit(-1);
	}

	/*
	 *
	 */
	protected function unlockChildren() {
		MTLog::getInstance()->debug2('[lock] unlock children processes');
		$res = sem_release($this->mutex_children_processes);
		if ( !$res ) exit(-1);
	}

	/*
	 * Get a shared var based on hash.
	 *
	 * Return null if the var doesn't exist.
	 */
	protected function getVar($name, $lock = false) {
		if ( $lock ) $this->lock();
		$vars = shm_get_var($this->shared_data, self::_INDEX_DATA);
		$value = (isset($vars[$name])) ? $vars[$name] : null;
		if ( $lock ) $this->unlock();
		return $value;
	}

	/*
	 * Set a shared var.
	 * 
	 * Remark : the var should be serialized.
	 */
	protected function setVar($name, $value, $lock = false) {
		if ( $lock ) $this->lock();
		$vars = shm_get_var($this->shared_data, self::_INDEX_DATA);
		$vars[$name] = $value;
		$res = shm_put_var($this->shared_data, self::_INDEX_DATA, $vars);
		if ( $lock ) $this->unlock();
		return $res;
	}

	/*
	 * Get the number of running threads
	 */
	protected function getThreads($lock = false) {
		if ( $lock ) $this->lock();
		$res = shm_get_var($this->shared_data, self::_INDEX_THREADS);
		if ( $lock ) $this->unlock();
		return $res;
	}

	/*
	 * Set the number of running threads
	 */
	protected function setThreads($threads, $lock = false) {
		if ( $lock ) $this->lock();
		$res = shm_put_var($this->shared_data, self::_INDEX_THREADS, $threads);
		if ( $lock ) $this->unlock();
		return $res;
	}

	/*
	 * Increment the number of running threads
	 */
	protected function incThreads($lock = false) {
		if ( $lock ) $this->lock();
		$threads = $this->getThreads();
		$res = shm_put_var($this->shared_data, self::_INDEX_THREADS, $threads + 1);
		MTLog::getInstance()->debug('incThreads, $threads = '.($threads + 1));
		if ( $lock ) $this->unlock();
		return $res;
	}

	/*
	 * Decrement the number of running threads
	 */
	protected function decThreads($lock = false) {
		if ( $lock ) $this->lock();
		$threads = $this->getThreads();
		$res = shm_put_var($this->shared_data, self::_INDEX_THREADS, $threads - 1);
		MTLog::getInstance()->debug('decThreads, $threads = '.($threads - 1));
		if ( $lock ) $this->unlock();
		return $res;
	}

	/*
	 * Return true if any slot is free
	 */
	protected function hasFreeSlot() {
		$threads = $this->getThreads();
		$res = ($threads<$this->max_threads) ? true : false;
		MTLog::getInstance()->debug('Has free slot ? => #running threads = '.$threads);
		return $res;
	}

	/*
	 * Assign a free slot
	 *
	 * Return null if no free slot is available
	 */
	protected function requestSlot($lock = false) {
		MTLog::getInstance()->debug('Requesting slot ... ');
		$slot = null;
		if ( $lock ) $this->lock();
		$slots = shm_get_var($this->shared_data, self::_INDEX_SLOTS);
		for ( $i = 0; $i<$this->max_threads; $i++ ) {
			if ( !isset($slots[$i]) ) {
				$slots[$i] = true;
				$slot = $i;
				break;
			} else {
				if ( $slots[$i]==false ) {
					$slots[$i] = true;
					$slot = $i;
					break;
				}
			}
		}
		shm_put_var($this->shared_data, self::_INDEX_SLOTS, $slots);
		if ( $lock ) $this->unlock();
		if ( is_null($slot) ) {
			MTLog::getInstance()->debug('no free slots !!');
		} else {
			MTLog::getInstance()->debug('slot '.$slot.' found.');
		}
		return $slot;
	}

	/*
	 * Release given slot
	 */
	protected function releaseSlot($slot, $lock = false) {
		if ( $lock ) $this->lock();
		$slots = shm_get_var($this->shared_data, self::_INDEX_SLOTS);
		$slots[$slot] = false;
		shm_put_var($this->shared_data, self::_INDEX_SLOTS, $slots);
		if ( $lock ) $this->unlock();
		MTLog::getInstance()->debug('Releasing slot '.$slot);
		return true;
	}
}

