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
 * @version     $Id: class.MTLog.php 8 2007-10-19 09:16:00Z killerwhile $
 *
 * See http://code.google.com/p/phpmultithreadeddaemon/ 
 * and http://phpmultithreaddaemon.blogspot.com/ for more information
 *
 */

class MTLog {

    const INFO = 1;
    const WARN = 3;
    const ERROR = 2;
    const DEBUG = 4;
    const DEBUG2 = 5;

    protected static $_INSTANCE;
    protected static $logfile = 'php://stdout';

    public static function getInstance()
    {
        if (self::$_INSTANCE === null) {
            self::$_INSTANCE = new MTLog(self::$logfile);
        }
        return self::$_INSTANCE;
    }

    public static function setLogFile($logfile)
    {
        self::$logfile = $logfile;
    }

    protected $stream;
    protected $verbosity = self::INFO;

    protected function __construct($logfile)
    {
        $this->stream = fopen($logfile, 'w');
    }

    public function setVerbosity($verbosity)
    {
        $this->verbosity = $verbosity;
    }

    protected function _write($verbosity, $msg)
    {
        if ($verbosity <= $this->verbosity) {
            fwrite($this->stream, date('Y-m-d H:i:s') . ' ' . '[' . posix_getpid() . '] ' . self::_verbosityToString($verbosity) . ' : ' . $msg . "\n");
        }
    }

    public function info($msg)
    {
        $this->_write(self::INFO, $msg);
    }
    public function error($msg)
    {
        $this->_write(self::ERROR, $msg);
    }
    public function warn($msg)
    {
        $this->_write(self::WARN, $msg);
    }
    public function debug($msg)
    {
        $this->_write(self::DEBUG, $msg);
    }
    public function debug2($msg)
    {
        $this->_write(self::DEBUG2, $msg);
    }

    protected static function _verbosityToString($verbosity)
    {
        switch($verbosity) {
            case self::INFO: return 'INFO';
            case self::ERROR: return 'ERROR';
            case self::WARN: return 'WARN';
            case self::DEBUG: return 'DEBUG';
            case self::DEBUG2: return 'DEBUG2';
            default: return 'UNKNOWN';
        }
    }
}

