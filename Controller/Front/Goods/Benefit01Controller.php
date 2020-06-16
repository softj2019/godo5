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
namespace Controller\Front\Goods;


use Framework\Debug\Exception\AlertBackException;
use Framework\Debug\Exception\AlertOnlyException;
use Framework\Debug\Exception\AlertRedirectException;
use Framework\Debug\Exception\Framework\Debug\Exception;
use Message;
use Globals;
use Request;
use Cookie;
use Framework\Utility\StringUtils;
use Framework\Utility\SkinUtils;



class Benefit01Controller extends \Controller\Front\Controller
{
	/**
     * 상품목록
     *
     * @author artherot, sunny
     * @version 1.0
     * @since 1.0
     * @copyright Copyright (c), Godosoft
     * @throws Except
     */
    public function index()
    {
        $getValue = Request::get()->toArray();

		$goods = \App::load('\\Component\\Goods\\Goods');
        if($getValue['brandCd'])
			$cate = \App::load('\\Component\\Category\\Brand');
        else
			$cate = \App::load('\\Component\\Category\\Category');
        // 모듈 설정
		try
		{
			if ($getValue['brandCd']) {
                $cateCd = $getValue['brandCd'];
                $cateType = "brand";
                $naviDisplay = gd_policy('display.navi_brand');
            } else {
                $cateCd = $getValue['cateCd'];
                $cateType = "cate";
                $naviDisplay = gd_policy('display.navi_category');
            }


            $cateInfo = $cate->getCategoryGoodsList($cateCd);
            $goodsCategoryList = $cate->getCategories($cateCd);

			$this->setData('goodsCategoryList', gd_isset($goodsCategoryList));
			$this->setData('cateCd', $cateCd);
		}
		catch (\Exception $e) {
            throw new AlertRedirectException($e->getMessage(),null,null,"/");
        }

     }
}
