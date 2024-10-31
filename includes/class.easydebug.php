<?php

/*
 *  (c) , 2011 Wott (http://wott.info/ , wotttt@gmail.com) 
 */

class EasyDebugDummy {
	function __get($name) {}
	function __set($name, $val) {}
	function __call($name, $args) {}
}

class EasyDebug extends EasyDebugDummy {
	
	var $log = array();
	var $start=false;
	
	var $level_start=false;
	var $level='';
	
	// just logging
	function add($code,$message) {
		$this->log[(($this->level)?$this->level.': ':'') . $code]=$message;
	}
	
	// time tracing
	function start($level=false) {
		
		$this->start = microtime(true);
		
		if ($level) {
			$this->level_start=$this->start;
			$this->level=$level;
		}
	}
	
	function stop() {
		if ($this->level) {
			$this->level = '';
			$this->start=$this->level_start;
			
		} else $this->start = false;
	}
	
	function point($code) {
		if ($this->start) $this->log[(($this->level)?$this->level.': ':'') . $code] = microtime(true)-$this->start;
		$this->start = microtime(true);
	}
	
	function log($before, $after, $between = ': ', $line_end = "\n") {
		
		$messages=array($before);
		foreach($this->log as $code=>$message) {
			$messages[]=$code.$between.$message;
		}
		$messages[]=$after;
		
		return implode($line_end,$messages);
	}
	
}
?>
