<?php
namespace Controller\Front\Test;

class MethodController extends \Controller\Front\Controller
{
    /**
     * {@inheritdoc}
     */
    public function index()
    {
        $logger = \App::getInstance('logger');
        $logger->info(__METHOD__ . ', pid[' . getmypid() . ']');
		$test = new \Component\Test\Test();
        $methods = [
            'setSmsType' => 'setSmsType',
			'log1' => 'log1',
        ];
        $test->{$methods['log1']}();
		echo 'DONE';
        exit;
    }
}
