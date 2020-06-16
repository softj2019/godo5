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

use Component\Board\Board;
use Component\Board\BoardBuildQuery;
use Component\Board\BoardList;
use Component\Board\BoardWrite;
use Component\Naver\NaverPay;
use Component\Page\Page;
use Component\Promotion\SocialShare;
use Component\Mall\Mall;
use Framework\Debug\Exception\AlertRedirectException;
use Framework\Debug\Exception\Except;
use Framework\Debug\Exception\AlertBackException;
use Component\Validator\Validator;
use Message;
use Globals;
use Request;
use Logger;
use Session;
use Exception;
use Endroid\QrCode\QrCode as EndroidQrCode;
use SocialLinks\Page as SocialLink;
use FileHandler;

class GoodsViewController extends \Controller\Front\Controller
{

    /**
     * 상품 상세 페이지
     *
     * @author    artherot
     * @version   1.0
     * @since     1.0
     * @copyright Copyright (c), Godosoft
     * @throws Except
     */
    public function index()
    {
        // --- 상품 설정
        try {
            // 모듈 설정
            $goods = \App::load('\\Component\\Goods\\Goods');
            $cate = \App::load('\\Component\\Category\\Category');
            $coupon = \App::load('\\Component\\Coupon\\Coupon');
            $qr = \App::load('\\Component\\Promotion\\QrCode');

            // 상품 정보
            $goodsNo = Request::get()->get('goodsNo');
            if (Validator::number($goodsNo, null, null, true) === false) {
                throw new \Exception(__('잘못된 접근입니다.'));
            }
            $goodsView = $goods->getGoodsView(Request::get()->get('goodsNo'));
            //성인인증 상품인경우
            if (Session::has(SESSION_GLOBAL_MALL)) {
                if ($goodsView['onlyAdultFl'] == 'y' && !gd_check_login()) {
                    $this->redirect('../member/login.php?returnUrl=' . urlencode("/goods/goods_view.php?goodsNo=" . $goodsNo));
                }
            } else {
                if ($goodsView['onlyAdultFl'] == 'y' && gd_check_adult() === false) {
                    $this->redirect('../intro/adult.php?returnUrl=' . urlencode("/goods/goods_view.php?goodsNo=" . $goodsNo));
                }
            }


            // 상품 QR코드
            if ($goodsView['qrCodeFl'] == 'y') {
                $goodsView['qrCodeImage'] = $qr->preview(
                    [
                        'qrSize' => 2,
                        'qrVersion' => 5,
                        'qrString' => "http://" . Request::server()->get("SERVER_NAME") . "/goods/goods_view.php?goodsNo=" . $goodsNo,
                    ]
                );
                $goodsView['qrStyle'] = "image";
                $qrCodeConfig = gd_policy('promotion.qrcode'); // QR코드 설정
                $goodsView['qrStyle'] = $qrCodeConfig['qrStyle'];
            }
            Logger::debug('$goodsView', $goodsView);

            // 오늘본 상품
            $goods->getTodayViewedGoods(Request::get()->get('goodsNo'));


            // 관련 상품
            $relation = $goodsView['relation'];
            if ($relation['relationFl'] != 'n') {
                $relationConfig = gd_policy('display.relation'); // 관련상품설정

                $relationConfig['line_width'] = 100 / $relationConfig['lineCnt'];
                if ($goodsView['relationGoodsDate']) {
                    $relationGoodsDate = json_decode(gd_htmlspecialchars_stripslashes($goodsView['relationGoodsDate']), true);
                }

                $relationCount = $relationConfig['lineCnt'] * $relationConfig['rowCnt'];

                $relation['relationCnt'] = gd_isset($relationCount, 4);                            // 상품 출력 갯수 - 기본 4개
                $imageType = gd_isset($relationConfig['imageCd'], 'main');                        // 이미지 타입 - 기본 'main'
                $soldOutFl = $relationConfig['soldOutFl'] == 'y' ? true : false;            // 품절상품 출력 여부 - true or false (기본 true)
                $brandFl = in_array('brandCd', array_values($relationConfig['displayField'])) ? true : false;    // 브랜드 출력 여부 - true or false (기본 false)
                $couponPriceFl = in_array('coupon', array_values($relationConfig['displayField'])) ? true : false;        // 쿠폰가격 출력 여부 - true or false (기본 false)
                $optionFl = in_array('option', array_values($relationConfig['displayField'])) ? true : false;
                if ($relation['relationFl'] == 'a') {
                    $relationCd = $relation['cateCd'];
                } else {
                    $relationCd = $relation['relationGoodsNo'];
                    $relationGoodsNo = explode(INT_DIVISION, $relation['relationGoodsNo']);

                    foreach ($relationGoodsNo as $k => $v) {
                        if ($v) {
                            if ($relationGoodsDate[$v]['startYmd'] && $relationGoodsDate[$v]['endYmd'] && (strtotime($relationGoodsDate[$v]['startYmd']) > time() || strtotime($relationGoodsDate[$v]['endYmd']) < time())) {
                                unset($relationGoodsNo[$k]);
                            }
                        } else {
                            unset($relationGoodsNo[$k]);
                        }
                    }

                    $relationCd = implode(INT_DIVISION, $relationGoodsNo);
                }

                if ($relation['relationFl'] == 'm') {
                    $relationOrder = "FIELD(g.goodsNo," . str_replace(INT_DIVISION, ",", $relationCd) . ")";
                    if ($relationConfig['soldOutDisplayFl'] == 'n') {
                        $relationOrder = "g.soldOutFl desc," . $relationOrder;
                    }
                } else {
                    $relationOrder = null;
                }

                // 관련 상품 진열
                $relationGoods = $goods->goodsDataDisplay('relation_' . $relation['relationFl'], $relationCd, $relation['relationCnt'], $relationOrder, $imageType, $optionFl, $soldOutFl, $brandFl, $couponPriceFl, null);
                if ($relationGoods) {
                    $this->setData('goodsCnt', count($relationGoods));
                    $relationGoods = array_chunk($relationGoods, $relationConfig['lineCnt']);
                }

                // 관련상품 노출항목 중 상품할인가
                if (in_array('goodsDcPrice', $relationConfig['displayField'])) {
                    foreach ($relationGoods as $key => $val) {
                        foreach ($val as $key2 => $val2) {
                            $relationGoods[$key][$key2]['goodsDcPrice'] = $goods->getGoodsDcPrice($val2);
                        }
                    }
                }

                $this->setData('widgetGoodsList', gd_isset($relationGoods));
                $this->setData('widgetTheme', gd_isset($relationConfig));
                $this->setData('mainData', ['sno'=>'relation']);
            }


            unset($goodsView['relation']);

            // 상품 이용 안내
            $detailInfo = $goodsView['detailInfo'];
            unset($goodsView['detailInfo']);

            // 카테고리 정보
            if (empty(Request::get()->get('cateCd')) === false && preg_match('/goods_list.php/i', Request::getParserReferer()->path)) {
                $goodsCateCd = Request::get()->get('cateCd');
            } else {
                $goodsCateCd = $goodsView['cateCd'];
            }

            // 소셜공유 설정하기
            $socialShare = new SocialShare([
                SocialShare::BRAND_NAME_REPLACE_KEY => $goodsView['brandNm'],
                SocialShare::GOODS_NAME_REPLACE_KEY => $goodsView['goodsNmDetail'],
            ]);
            $data = $socialShare->getTemplateData($goodsView['social']);
            $this->setData('snsShareUseFl', $data['useFl']);
            $this->setData('snsShareMetaTag', $data['metaTags']);
            $this->setData('snsShareButton', $data['shareBtn']);
            $this->setData('snsShareUrl', $data['shareUrl']);

            // 쿠폰 설정값 정보
            $couponConfig = gd_policy('coupon.config');
            //타임세일 상품에서 쿠폰 사용 불가인경우 체크
            if (gd_is_plus_shop(PLUSSHOP_CODE_TIMESALE) === true && $goodsView['timeSaleFl'] && $goodsView['timeSaleInfo']['couponFl'] == 'n') {
                $couponConfig['couponUseType'] = 'n';
            }

            if ($couponConfig['couponUseType'] == 'y') {
                // 해당 상품의 모든 쿠폰
                $couponArrData = $coupon->getGoodsCouponDownList(Request::get()->get('goodsNo'), Session::get('member.memNo'), Session::get('member.groupSno'));
            }

            // 현재 위치 정보 (위젯 클래스에서 사용)
            $pageLocation = null;
            if (empty($goodsCateCd) == false) {
                $pageLocation = $cate->getCategoryPosition($goodsCateCd, 0, STR_DIVISION, true);
            }

            $goodsCategoryList = $cate->getCategories($goodsCateCd);

            // 마일리지 정보
            $mileage = $goodsView['mileageConf'];
            unset($goodsView['mileageConf']);

            // 상품 과세 / 비과세 설정 config 불러오기
            $taxConf = gd_policy('goods.tax');

            // 무통장 전용상품일 경우 네이버체크아웃 페이코 미노출처리
            if (!($goodsView['payLimitFl'] == 'y' && $goodsView['payLimit'] == 'gb')) {
                // 네이버 체크아웃 버튼
                $naverPay = new NaverPay();
                $naverPayButton = $naverPay->getNaverPayView(Request::get()->get('goodsNo'));
                $naverPayMobileButton = $naverPay->getNaverPayView(Request::get()->get('goodsNo'),true);    //모바일버튼 제공
                // 페이코 버튼
                $payco = \App::load('\\Component\\Payment\\Payco\\Payco');
                $paycoCheckoutbuttonImage = $payco->getButtonHtmlCode('CHECKOUT', false, 'goodsView', Request::get()->get('goodsNo'));
                if ($paycoCheckoutbuttonImage !== false) {
                    $this->setData('payco', gd_isset($paycoCheckoutbuttonImage));
                }
            }

            $soldoutDisplay = gd_policy('soldout.pc');

            $cartInfo = gd_policy('order.cart'); //장바구니설정

            // 상품 무게 소수점 0 제거 (ex. 4.00 => 4, 4.40 => 4.4)
            if ($goodsView['goodsWeight'] - floor($goodsView['goodsWeight']) == 0) {
                $goodsView['goodsWeight'] = number_format($goodsView['goodsWeight']);
            } elseif ($goodsView['goodsWeight'] - (floor($goodsView['goodsWeight'] * 10) / 10) == 0) {
                $goodsView['goodsWeight'] = number_format($goodsView['goodsWeight'], 1);
            }

        } catch (Exception $e) {
            throw new AlertBackException($e->getMessage());
        }

        // 멀티 상점을 위한 소수점 처리
        $currency = Globals::get('gCurrency');
        if (Session::has(SESSION_GLOBAL_MALL)) {
            $currency['decimal'] = Session::get(SESSION_GLOBAL_MALL.'.currencyConfig');
            $currency['decimal'] = $currency['decimal']['decimal'];

            if(SESSION::get(SESSION_GLOBAL_MALL.'.addGlobalCurrencyNo')) {
                $this->setData('addGlobalCurrency', gd_isset(SESSION::get(SESSION_GLOBAL_MALL.'.addGlobalCurrencyNo')));
            }
        }

        // --- Template_ 출력
        // 브라우저 상단 타이틀
        $this->setData('title', gd_isset($goodsView['goodsNm']));
        $this->setData('goodsView', gd_isset($goodsView));
        $this->setData('mileageData', gd_isset($mileage['info']));
        $this->setData('goodsCateCd', gd_isset($goodsCateCd));
        $this->setData('goodsCategoryList', gd_isset($goodsCategoryList));
        $this->setData('couponArrData', gd_isset($couponArrData));
        $this->setData('couponConfig', gd_isset($couponConfig));
        $this->setData('couponUse', gd_isset($couponConfig['couponUseType'], 'n'));
        $this->setData('taxConf', gd_isset($taxConf));
        $this->setData('relation', gd_isset($relation));
        $this->setData('relationGoodsDate', gd_isset($relationGoodsDate));
        $this->setData('cyscrapBtnImage', gd_isset($cyscrapBtnImage));
        $this->setData('naverPay', gd_isset($naverPayButton));  //네이버페이PC버튼
        $this->setData('naverPayMobile', gd_isset($naverPayMobileButton));  //네이버페이 모바일버튼
        $this->setData('currency', $currency);
        $this->setData('weight', Globals::get('gWeight'));
        $this->setData('soldoutDisplay', gd_isset($soldoutDisplay));
        $this->setData('cartInfo', gd_isset($cartInfo));

        // 상품 상세 이용안내 배송정보,AS관련,환불,교환
        $detailInfoArray = array('detailInfoDelivery','detailInfoAS','detailInfoRefund','detailInfoExchange','detailInfoPayment','detailInfoService');

        foreach($detailInfoArray as $val) {
            // 해외몰 이용안내 직접입력일 경우 해외몰 이용안내 정보 가져옴
            if (Session::has(SESSION_GLOBAL_MALL) && $goodsView[$val.'Fl'] != 'no') {
                $goodsView[$val.'Fl'] = 'selection';
                $detailInfo[$val] = \Component\Mall\Mall::GLOBAL_MALL_DETAIL_INFO[$val];
            }

            if ($goodsView[$val.'Fl'] == 'no') { //이용안내 사용안함
                $infoData = '';
            }else if($goodsView[$val.'Fl'] == 'direct') { //이용안내 직접입력
                $infoData['content'] = $goodsView[$val.'DirectInput'];
            }else if($goodsView[$val.'Fl'] == 'selection'){ //이용안내 선택입력
                if (empty($detailInfo[$val]) === false && strlen($detailInfo[$val]) == 6) {
                    $infoData = gd_buyer_inform($detailInfo[$val]);
                }else{
                    $infoData = '';
                }
            }else{
                $infoData = '';
            }
            $this->setData(str_replace('detailInfo','info',$val), gd_isset($infoData['content']));
        }


        if (FileHandler::isExists( USERPATH_SKIN.'js/bxslider/dist/jquery.bxslider.min.js')) {
            $addScript[] =  'bxslider/dist/jquery.bxslider.min.js';
        }
        if (FileHandler::isExists( USERPATH_SKIN.'js/slider/slick/slick.js')) {
            $addScript[] =  'slider/slick/slick.js';
        }
        $addScript[] = 'gd_goods_view.js';

        if (!Request::isMobile() && !Request::isMobileDevice()) {
            $addScript[] = 'jquery/chosen-imageselect/src/ImageSelect.jquery.js';
        }

        if ($goodsView['imgDetailViewFl'] == 'y') {
            $addScript[] = 'imagezoom/jquery.elevatezoom.js';
        }

        $this->addScript($addScript);
        $this->addCss(['../js/jquery/chosen-imageselect/src/ImageSelect.css']);

        $goodsReviewList = new BoardList(['bdId' => Board::BASIC_GOODS_REIVEW_ID, 'goodsNo' => $goodsNo]);
        if ($goodsReviewList->canUsePc()) {
            $goodsReviewAuthList = $goodsReviewList->canList();
            $goodsReviewCount = 0;
            if ($goodsReviewAuthList == 'y') {
                $goodsReviewCount = $goodsReviewList->getCount();
            }
        }

        $goodsQaList = new BoardList(['bdId' => Board::BASIC_GOODS_QA_ID, 'goodsNo' => $goodsNo]);
        if ($goodsQaList->canUsePc()) {
            $goodsQaAuthList = $goodsQaList->canList();
            $goodsQaCount = 0;
            if ($goodsQaAuthList == 'y') {
                $goodsQaCount = $goodsQaList->getCount();
            }
        }

        $this->setData('goodsReviewAuthList', $goodsReviewAuthList);

        $this->setData('goodsReviewAuthWrite', $goodsReviewList->canWrite());
        $this->setData('goodsReviewCount', $goodsReviewCount);
        $this->setData('goodsQaAuthList', $goodsQaAuthList);
        $this->setData('goodsQaAuthWrite', $goodsQaList->canWrite());
        $this->setData('goodsQaCount', $goodsQaCount);

        $this->setData('bdGoodsReviewId', Board::BASIC_GOODS_REIVEW_ID);
        $this->setData('bdGoodsQaId', Board::BASIC_GOODS_QA_ID);

        //상품 노출 필드
        $displayField = gd_policy('display.goods');
        $this->setData('displayField', $displayField['goodsDisplayField']['pc']);
        $this->setData('displayAddField', $displayField['goodsDisplayAddField']['pc']);

        // 취소선 관련값들 처리
        $fixedPriceTag = '';
        $fixedPriceTag2 = '';
        if ((in_array('couponPrice', $displayField['goodsDisplayField']['pc']) && $goodsView['couponPrice'] > 0) && $couponConfig['couponUseType'] == 'y' && ($goodsView['timeSaleInfo']['couponFl'] == 'n' || !$goodsView['timeSaleInfo'])) {
            $goodsPriceTag = '<b>';
            $goodsPriceTag2 = '</b>';
        } else {
            $goodsPriceTag = '<strong>';
            $goodsPriceTag2 = '</strong>';
        }

        // 패치 이전에 저장한상태라 db에 strikefield 설정값이 없는경우는 우선 정가는 체크되어있는것으로 간주하기위함
        if (empty($displayField['goodsDisplayStrikeField']['pc']) === true) {
            $fixedPriceTag = '<del>';
            $fixedPriceTag2 = '</del>';
        } else {
            foreach ($displayField['goodsDisplayStrikeField']['pc'] as $val) {
                if ($val == 'fixedPrice') {
                    $fixedPriceTag = '<del>';
                    $fixedPriceTag2 = '</del>';
                }
                if ($val == 'goodsPrice' && $goodsPriceTag == '<b>') {
                    $goodsPriceTag = $goodsPriceTag . '<del>';
                    $goodsPriceTag2 = '</del>' . $goodsPriceTag2;
                }
            }
        }
        $this->setData('fixedPriceTag', $fixedPriceTag);
        $this->setData('fixedPriceTag2', $fixedPriceTag2);
        $this->setData('goodsPriceTag', $goodsPriceTag);
        $this->setData('goodsPriceTag2', $goodsPriceTag2);
        $this->setData('displayDefaultField', $displayField['defaultField']);
    }
}
