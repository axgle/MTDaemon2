<?php
abstract class MTDaemon {
    /*
     * Configuration vaiables
     */
    protected $key = '';
    // max concurrent threads
    protected $max_threads = 4; // should be implemented with sem_get('name', $max_aquire) but this can't be dynamically updated as this var.

    // sleep time when no job
    protected $idle_sleep_time = 5;

    function  __construct($threads = null, $idlesleeptime = null){
        if ($threads) $this->max_threads = $threads;
        if ($idlesleeptime) $this->idle_sleep_time = $idlesleeptime;
        $this->main_thread_pid = posix_getpid();
        $this->setKey();
    }

    function setKey() {
        $name=join('_',$_SERVER["argv"]);
        $name=preg_replace('/\W/', '_',$name);
        $key="/tmp/mtd2_".$name."_".$this->main_thread_pid;
        $this->key = $key;
        touch($this->key);
        $this->set(array());
    }

    protected function _prerun()
    {
        $this->setThreads(0);
        $slots = array();
        for ($i = 0; $i < $this->max_threads; $i++) {
            $slots[] = false;
        }
        $this->setVar('slots', $slots);

    }

    /*
     * Set the number of running threads
     */
    protected function setThreads($threads, $lock = false)
    {
        if ($lock) $this->lock();
        $res=$this->setVar('threads', $threads);
        if ($lock) $this->unlock();
        return $res;
    }
    protected function lock()
    {

    }
    protected function unlock()
    {

    }


    /*
     * Set a shared var.
     *
     * Remark : the var should be serialized.
     */
    protected function setVar($name, $value, $lock = false)
    {
        $var=$this->get();
        $var[$name]=$value;
        $this->set($var);
        return $var;
    }
    function set($var=array()){
        $data=serialize($var);
        return file_put_contents($this->key, $data);
    }

    function get(){
        //print_r($this->key."\n");
        $data=file_get_contents($this->key);
        $data=unserialize($data);
        //print_r($data);
        return $data;
    }

    /*
     * Get the number of running threads
     */
    protected function getThreads($lock = false)
    {
        if ($lock) $this->lock();
        $res = $this->getVar('threads');
        if ($lock) $this->unlock();
        return $res;
    }

 /*
     * Increment the number of running threads
     */
    protected function incThreads($lock = false)
    {
        if ($lock) $this->lock();
        $threads = $this->getThreads();
        $res = $this->setVar('threads', $threads + 1);
        if ($lock) $this->unlock();
        return $res;
    }

    /*
     * Decrement the number of running threads
     */
    protected function decThreads($lock = false)
    {
        if ($lock) $this->lock();
        $threads = $this->getThreads();
        $res = $this->setVar('threads', $threads - 1);
        if ($lock) $this->unlock();
        return $res;
    }

    protected function getVar($name, $lock = false)
    {
        $var=$this->get();
        return $var[$name];
    }

    /*
     * Request data of the next element to run in a thread
     *
     * return null or false if no job currently
     */
    abstract public function getNext($slot);

    /*
     * Process the element fetched by getNext in a new thread
     *
     * return the exiting status of the thread
     */
    abstract public function run($next, $slot);

    protected function hasFreeSlot()
    {
        $threads = $this->getThreads();
        $res = ($threads < $this->max_threads) ? true : false;
        //print('#running threads = ' . $threads."\n");
        return $res;
    }
    function handle(){
        $this->_prerun();
        $pid=pcntl_fork();
        if($pid==0){
            $this->run=true;
            while($this->run){
                if($this->hasFreeSlot()) {
                    $this->fork_next();
                }else{
                    pcntl_wait(&$status);
                }
            }
            $this->at_exit();
        }else{
            $this->at_exit();
        }
    }
    function at_exit(){
        posix_kill(getmypid(), 9);
    }

    function fork_next(){
        $next=$this->getNext($slot);
        if($next!=null){
            if(pcntl_fork()==0){
                $this->incThreads();
                $this->run($next,$slot);
                $this->decThreads();
                $this->at_exit();
            }

        }
    }
}
/*
require 'include/class.db.php';
class M extends MTDaemon{
    function  __construct($threads = null, $idlesleeptime = null){
        parent::__construct($threads,$idlesleeptime );
        $this->db=new DB();
        print_r($this->db);
        $this->sqls=array('select 1','select 2','select 3','select 4','select 5');
        $this->index=0;
        $this->max_index=count($this->sqls);
    }
    function getNext($slot){
        $rs=$this->sqls[$this->index++];
        if($this->index>$this->max_index){
            $this->run=false;
            return null;
        }
        return $rs;
    }
    function run($next,$slot){
        $sql=$next;
        $d=$this->db->SelAssoc($sql);
        print_r($d);
        sleep(3);

    }
}
$m=new M(4);
$m->handle();
 */
?>