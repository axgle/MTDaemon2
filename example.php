<?php
require_once 'class.MTDaemon.php';
class M extends MTDaemon{
    function  __construct($threads = null, $idlesleeptime = null){
        parent::__construct($threads,$idlesleeptime );
        $this->fp=fopen(__FILE__,"r");
        $this->n=0;

    }
    function getNext($slot){
        $d=fgets($this->fp);
        if($d){
            $this->n++;
            return $d;
        }
        $this->run=false;
        return null;

    }
    function info($str){
        //echo $str."\n";
    }
    function run($next,$slot){
        print  $this->n." \n";
        $this->info($next);
        sleep(1);
        if(rand(1,100)<=90){
            sleep(5);
        }
    }
}

$m=new M(10);
$m->handle();
?>
