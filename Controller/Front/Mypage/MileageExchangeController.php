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
namespace Controller\Front\Mypage;

use Framework\Utility\DateTimeUtils;
use Component\Goods\GoodsCate;
use Component\Page\Page;
use Framework\Debug\Exception\AlertOnlyException;
use Framework\Debug\Exception\RedirectLoginException;
use Framework\Debug\Exception\RequiredLoginException;
use Request;
use View\Template;
use Component\Validator\Validator;
use Framework\Debug\Exception\AlertBackException;
use Globals;
use Component\Board\BoardWrite;
use Framework\Utility\Strings;
use Framework\StaticProxy\Proxy\Session;
/**
 * 마이페이지-혜택 마일리지
 * @package Bundle\Controller\Front\Mypage
 * @author  yjwee
 */
class MileageExchangeController extends \Controller\Front\Controller
{
    /**
     * @inheritdoc
     */
    public function index()
    {
				$getValue = Request::get()->toArray();
				$cate = \App::load('\\Component\\Category\\Category');

        $session = \App::getInstance('session');
        if ($session->has(SESSION_GLOBAL_MALL)) {
            throw new AlertBackException(__('잘못된 접근입니다.'));
        }

        if (is_numeric(Request::get()->get('searchPeriod')) === true && Request::get()->get('searchPeriod') >= 0) {
            $selectDate = Request::get()->get('searchPeriod');
        } else {
            $selectDate = 7;
        }
        $startDate = date('Y-m-d', strtotime("-$selectDate days"));
        $endDate = date('Y-m-d', strtotime("now"));

        // 기간 조회
        if (Request::isMobile() === true) {
            $searchDate = [
                '1' => __('오늘'),
                '7' => __('최근 %d일', 7),
                '15' => __('최근 %d일', 15),
                '30' => __('최근 %d개월', 1),
                '90' => __('최근 %d개월', 3),
                '180' => __('최근 %d개월', 6),
                '365' => __('최근 %d년', 1),
            ];
            $this->setData('searchDate', $searchDate);
            $this->setData('selectDate', $selectDate);
        }

        $regTerm = \Request::get()->get('regTerm', 7);
        $regDt = \Request::get()->get(
            'regDt', [
                $startDate,
                $endDate,
            ]
        );

        $active['regTerm'][$regTerm] = 'active';

        /**
         * 페이지 데이터 설정
         */
        $page = Request::get()->get('page', 1);
        $pageNum = Request::get()->get('pageNum', 10);

        /**
         * 요청처리
         * @var \Bundle\Component\Mileage\Mileage $mileage
         */
        $mileage = \App::load('\\Component\\Mileage\\Mileage');
        $list = $mileage->listBySession($regDt, $page, $pageNum);

        /**
         * 페이징 처리
         */
        $p = new Page($page, $mileage->foundRows(), $mileage->getCount(DB_MEMBER_MILEAGE), $pageNum);
        $p->setPage();
        $p->setUrl(Request::getQueryString());

        /**
         * View 데이터
         */
        $this->setData('list', $list);
        $this->setData('regTerm', $regTerm);
        $this->setData('regDt', $regDt);
        $this->setData('active', $active);
        $this->setData('page', $p);

        /**
         * css 추가
         */
        $this->addCss(
            [
                'plugins/bootstrap-datetimepicker.min.css',
                'plugins/bootstrap-datetimepicker-standalone.css',
            ]
        );

        /**
         * js 추가
         */
        $locale = \Globals::get('gGlobal.locale');
        $this->addScript(
            [
                'gd_board_list.js',
                'moment/moment.js',
                'moment/locale/' . $locale . '.js',
                'jquery/datetimepicker/bootstrap-datetimepicker.min.js',
            ]
        );
        //        debug($regTerm);
        //        debug($regDt);
        /////////////////////
        try {
            $cateCd = $getValue['cateCd'];
            $cateType = "cate";
            $naviDisplay = gd_policy('display.navi_category');

            $goodsCategoryList = $cate->getCategories($cateCd);

            $qryStr = preg_replace(array("/mode=[^&]*/i", "/&[&]+/", "/(^[&]+|[&]+$)/"), array("", "&", ""), Request::getQueryString());
            $req = Request::get()->toArray();
            gd_isset($req['mode'], 'write');

            $boardWrite = new BoardWrite($req);
            $boardWrite->checkUsePc();
            $getData = gd_htmlspecialchars($boardWrite->getData());
            $bdWrite['cfg'] = $boardWrite->cfg;
            $bdWrite['isAdmin'] = $boardWrite->isAdmin;
            $bdWrite['data'] = $getData;
            if (gd_is_login() === false) {
                // 개인 정보 수집 동의 - 이용자 동의 사항
                $tmp = gd_buyer_inform('001008');
                $private = $tmp['content'];
                if (gd_is_html($private) === false) {
                    $bdWrite['private'] = $private;
                }
            }

            if (Request::post()->has('oldPassword')) {
                $oldPassword = md5(Request::post()->get('oldPassword'));
                $this->setData('oldPassword', $oldPassword);
            }

            if (gd_isset($req['noheader'], 'n') != 'n') {
                $this->getView()->setDefine('header', 'outline/_share_header.html');
                $this->getView()->setDefine('footer', 'outline/_share_footer.html');
            }

            if($req['mode'] == 'modify') {
                if($bdWrite['data']['isMobile'] == 'y') {
                    throw new AlertBackException(__('모바일에서 작성하신 글은 모바일에서만 수정 가능합니다.'));
                }
            }

            $this->setData('goodsCategoryList', gd_isset($goodsCategoryList));
            $this->setData('cateCd', $cateCd);
            $this->setData('cateType', $cateType);

            $this->setData('bdWrite', $bdWrite);
            $this->setData('queryString', $qryStr);
            $this->setData('req', gd_htmlspecialchars($boardWrite->req));
            $path = 'board/skin/' . $bdWrite['cfg']['themeId'] . '/write.html';
            $this->getView()->setDefine('write', $path);
        } catch (RequiredLoginException $e) {
            if($req['noheader'] == 'y') {
                throw new AlertBackException($e->getMessage());
            }
            throw new RedirectLoginException($e->getMessage());
        } catch (\Exception $e) {
            throw new AlertBackException($e->getMessage());
        }
    }
}
