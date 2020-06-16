<?php
/**
 * This is commercial software, only users who have purchased a valid license
 * and accept to the terms of the License Agreement can install and use this
 * program.
 *
 * Do not edit or add to this file if you wish to upgrade Godomall5 to newer
 * versions in the future.
 *
 * @copyright ⓒ 2016, NHN godo: Corp.
 * @link      http://www.godo.co.kr
 */

namespace Controller\Front\Test;

use Framework\Utility\StringUtils;

class SyncReceiveController extends \Controller\Front\Controller
{
    public function index()
    {
        $request = \App::getInstance('request');
        $logger = \App::getInstance('logger');
        $context = $request->request()->all();
        $start = time();
        $sleepSecond = rand(1, 3);
        sleep($sleepSecond * $context[1]);
        $policy = gd_policy('test.sample');
        StringUtils::strIsSet($policy['orderNo'], '');
        if (empty($policy['orderNo'])) {
            gd_set_policy('test.sample', ['orderNo' => $context['orderNo']]);
        }
        sleep($context[1]);
        $end = time();
        try {
            $logger->info(__CLASS__ . '[' . getmypid() . '], sleep=' . $sleepSecond . ', running time=' . date('s', $end - $start), $context);
            if (empty($policy['orderNo'])) {
                $logger->notice(__CLASS__ . '[' . getmypid() . ']', gd_policy('test.sample'));
            } else {
                $logger->info(__CLASS__ . '[' . getmypid() . ']', gd_policy('test.sample'));
            }
        } catch (\Throwable $e) {
            $logger->error($e->getTraceAsString());
        }
        echo 'DONE';
        exit;
    }
}
