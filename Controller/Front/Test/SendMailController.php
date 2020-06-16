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
 * @link http://www.godo.co.kr
 */
namespace Controller\Front\Test;

class SendMailController extends \Controller\Front\Controller
{
    /**
     * {@inheritdoc}
     */
    public function index()
    {
		try {
			$mail = &\Mail::factory('sendmail');
		$mail->send('godo.yeongjong.wee@gmail.com', [
		], 'qweqwe');
		} catch(\Throwable $e) {echo $e->getTraceAsString();}

		exit;
    }
}
