<?php
namespace Component\Test;

class Test {
	public function log1()
	{
		$logger = \App::getInstance('logger');
		$logger->info(__METHOD__ . ', pid[' . getmypid() . ']');
	}
}