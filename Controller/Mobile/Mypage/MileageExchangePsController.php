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
namespace Controller\Mobile\Mypage;

use App;
use Component\Member\Group\Util as GroupUtil;
use Exception;
use Framework\Debug\Exception\LayerException;
use Logger;
use Message;
use Request;

/**
 * Class 회원일괄 처리
 * @package Bundle\Controller\Admin\Member
 * @author  yjwee
 */
class MileageExchangePsController extends \Controller\Mobile\Controller
{
    public function index()
    {
        /**
         * @var  \Bundle\Component\Member\MemberAdmin $admin
         * @var  \Bundle\Component\Mileage\Mileage    $mileage
         * @var  \Bundle\Component\Deposit\Deposit    $deposit
         */
        $mileage = App::load('\\Component\\Mileage\\Mileage');
        
        try {
            $mode = Request::post()->get('mode');
            $post = Request::post()->toArray();
            $searchJson = Request::post()->get('searchJson');
            $memberNo = Request::post()->get("chk");
            $groupSno = Request::post()->get('newGroupSno');

            $result = $mileage->removeMileage($post);
            $resultStorage = $mileage->getResultStorage();
            \Logger::debug(__METHOD__, $resultStorage);
            $this->json(
                [
                    $result,
                    $resultStorage->toArray(),
                ]
            );

        } catch (\Throwable $e) {
            \Logger::error(__METHOD__ . ', ' . $e->getFile() . '[' . $e->getLine() . '], ' . $e->getMessage(), $e->getTrace());
            if (Request::isAjax()) {
                $this->json($this->exceptionToArray($e));
            } else {
                throw new LayerException($e->getMessage(), $e->getCode(), $e);
            }
        }
    }
}
