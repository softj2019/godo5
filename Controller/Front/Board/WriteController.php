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
namespace Controller\Front\Board;

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

class WriteController extends \Controller\Front\Controller
{
    public function index()
    {
				$getValue = Request::get()->toArray();
				$cate = \App::load('\\Component\\Category\\Category');

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

            $this->setData('bdId', $req['bdId']);
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
