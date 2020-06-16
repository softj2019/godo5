<?php

/**
 * 상품 class
 *
 * 상품 관련 관리자 Class
 * @author artherot
 * @version 1.0
 * @since 1.0
 * @copyright Copyright (c), Godosoft
 */
namespace Component\Goods;

use Component\Member\Group\Util as GroupUtil;
use Component\Member\Manager;
use Component\Page\Page;
use Component\Storage\Storage;
use Component\Database\DBTableField;
use Component\Validator\Validator;
use Framework\Debug\Exception\HttpException;
use Framework\Debug\Exception\AlertBackException;
use Framework\File\FileHandler;
use Framework\Utility\ImageUtils;
use Framework\Utility\StringUtils;
use Framework\Utility\ArrayUtils;
use Encryptor;
use Globals;
use LogHandler;
use UserFilePath;
use Request;
use Exception;
use Session;


class GoodsAdmin extends \Bundle\Component\Goods\GoodsAdmin
{
	    const ECT_INVALID_ARG = 'GoodsAdmin.ECT_INVALID_ARG';

    const TEXT_REQUIRE_VALUE = '%s은(는) 필수 항목 입니다.';

    const TEXT_USELESS_VALUE = '%s은(는) 사용할 수 없습니다.';

    const TEXT_NOT_EXIST_VALUE = '%s 필수 항목이 존재하지 않습니다.';

    const TEXT_NOT_EXIST_OPTION = '옵션 항목이 존재하지 않습니다.';

    const TEXT_ERROR_VALUE = '조건에 대해 처리중 오류가 발생했습니다.';

    const TEXT_ERROR_BATCH = '일괄 처리할 데이터오류로 인해 처리가 되지 않습니다.';

    const DEFAULT_PC_CUSTOM_SOLDOUT_OVERLAY_PATH = '/data/icon/goods_icon/custom/soldout_overlay';

    const DEFAULT_MOBILE_CUSTOM_SOLDOUT_OVERLAY_PATH = '/data/icon/goods_icon/custom/soldout_overlay_mobile';

    public $goodsNo;

    public $imagePath;

    public $etcIcon;

    public $naverConfig;

    public $daumConfig;

    /**
     * 생성자
     */
    public function __construct()
    {
        if (!is_object($this->db)) {
            $this->db = \App::load('DB');
        }

        parent::__construct();

        // 기타 아이콘 설정
        $this->etcIcon = array('mileage' => __('마일리지'), 'coupon' => __('쿠폰'), 'soldout' => __('품절'), 'option' => __('옵션보기'));

        $dbUrl = \App::load('\\Component\\Marketing\\DBUrl');
        $this->naverConfig = $dbUrl->getConfig('naver', 'config');
        $this->daumConfig = $dbUrl->getConfig('daumcpc', 'config');

        if (gd_is_provider()) {
            $manager = \App::load('\\Component\\Member\\Manager');
            $managerInfo = $manager->getManagerInfo(\Session::get('manager.sno'));
            \Session::set("manager.scmPermissionInsert", $managerInfo['scmPermissionInsert']);
            \Session::set("manager.scmPermissionModify", $managerInfo['scmPermissionModify']);
            \Session::set("manager.scmPermissionDelete", $managerInfo['scmPermissionDelete']);
        }
    }

    /**
     * 새로운 상품 번호 출력
     *
     * @return string 새로운 상품 번호
     */
    protected function getNewGoodsno()
    {
        $data = $this->getGoodsInfo(null, 'if(max(goodsNo) > 0, (max(goodsNo) + 1), ' . DEFAULT_CODE_GOODSNO . ') as newGoodsNo');

        //기존 상품 코드 있는 경우 가지고 와서 비교 후 상품 코드 정의. 파일 상품 코드가 클 경우 파일 상품 코드+1
        $goodsNo = \FileHandler::read(\UserFilePath::get('config', 'goods'));
        if($goodsNo - $data['newGoodsNo'] >= 0) {
            $data['newGoodsNo'] =  $goodsNo+1;
        }

        return $data['newGoodsNo'];
    }

    /**
     * 상품 번호를 Goods 테이블에 저장
     *
     * @return string 저장된 상품 번호
     */
    protected function doGoodsNoInsert()
    {
        $newGoodsNo = $this->getNewGoodsno();
        $this->db->set_insert_db(DB_GOODS, 'goodsNo', array('i', $newGoodsNo), 'y');

        //최종 상품 코드 파일 저장
        \FileHandler::write(\UserFilePath::get('config', 'goods'), $newGoodsNo);

        return $newGoodsNo;
    }

    /**
     * 다중 카테고리 유효성 체크
     *
     * @param array $arrData 저장할 정보의 배열
     */
    public function getGoodsCategoyCheck($arrData, $goodsNo)
    {
        // 부모 카테고리 여부 체크
        foreach ($arrData['cateCd'] as $key => $val) {
            $length = strlen($val);
            for ($i = 1; $i <= ($length / DEFAULT_LENGTH_CATE); $i++) {
                $tmpCateCd[] = substr($val, 0, ($i * DEFAULT_LENGTH_CATE));
            }
        }

        $tmpCateCd = array_unique($tmpCateCd);
        $arrData['cateCd'] = array_merge($arrData['cateCd'], $tmpCateCd);



        // 중복 카테고리 정리
        $arrData['cateCd'] = array_unique($arrData['cateCd']);

        if (empty($goodsNo)) {
            $strWhere = ' AND goodsNo != \'' . $goodsNo . '\'';
        } else {
            $strWhere = '';
        }

        // 상품 순서 설정 (최대값 + 1을 순서로함)
        $strSQL = "SELECT IF(MAX(glc.goodsSort) > 0, (MAX(glc.goodsSort) + 1), 1) AS sort,MIN(glc.goodsSort) - 1 as reSort, glc.cateCd,cg.sortAutoFl,cg.sortType FROM ".DB_GOODS_LINK_CATEGORY." AS glc INNER JOIN ".DB_CATEGORY_GOODS." AS cg ON cg.cateCd = glc.cateCd WHERE glc.cateCd IN  ('" . implode('\',\'', $arrData['cateCd']) . "') GROUP BY glc.cateCd";
        $result = $this->db->query($strSQL);
        while ($data = $this->db->fetch($result)) {
            if($data['sortAutoFl'] =='y')  $getData[$data['cateCd']] = 0;
            else  {
                if($data['sortType'] =='bottom') $getData[$data['cateCd']] = $data['reSort'];
                else  $getData[$data['cateCd']] = $data['sort'];
            }
        }

        $category = \App::load('\\Component\\Category\\CategoryAdmin');

        foreach ($arrData['cateCd'] as $key => $val) {

            list($cateInfo) = $category->getCategoryData($val);

            $arrData['goodsSort'][$key] = $getData[$val];

            // 추가된 부모 카테고리 노출 여부
            gd_isset($arrData['cateLinkFl'][$key], 'n');

            // 노출 카테고리 배열화
            if ($arrData['cateLinkFl'][$key] == 'y') {
                $arrView[] = $key;
            }
        }
        if (isset($arrView) === false) {
            return null;
        }

        // 노출 카테고리와 부모 카테고리 설정
        foreach ($arrView as $key => $val) {
            $length = strlen($arrData['cateCd'][$val]);
            for ($i = 1; $i <= ($length / DEFAULT_LENGTH_CATE); $i++) {
                $tmp[] = substr($arrData['cateCd'][$val], 0, ($i * DEFAULT_LENGTH_CATE));
            }
        }

        // 노출 카테고리와 부모 카테고리 추출
        $extract['sno'] = array();
        $extract['cateCd'] = array();
        $extract['cateLinkFl'] = array();
        $extract['goodsSort'] = array();
        foreach ($arrData['cateCd'] as $key => $val) {
            if (in_array($val, $tmp)) {
                $extract['sno'][] = gd_isset($arrData['sno'][$key]);
                $extract['cateCd'][] = $arrData['cateCd'][$key];
                $extract['cateLinkFl'][] = $arrData['cateLinkFl'][$key];
                $extract['goodsSort'][] = $arrData['goodsSort'][$key];
            }
        }

        return $extract;
    }

    /**
     * 브랜드 유효성 체크
     *
     * @param array $arrData 저장할 정보의 배열
     */
    public function getGoodsBrandCheck($brandCd, $arrData, $goodsNo)
    {
        $chkReturn = false;
        if (empty($arrData) === false) {
            foreach ($arrData as $key => $val) {
                if (strlen($val['cateCd']) === strlen($brandCd) && $val['cateLinkFl'] == 'n') {
                    continue;
                }
                $setData['sno'][$key] = $val['sno'];
                $setData['cateCd'][$key] = $val['cateCd'];
                $setData['cateLinkFl'][$key] = $val['cateLinkFl'];
                $setData['goodsSort'][$key] = $val['goodsSort'];
            }
            if (ArrayUtils::last($setData['cateCd']) == $brandCd) {
                // return $setData;
                $chkReturn = true;
            } else {
                unset($arrData, $setData);
                $setData = array();
            }
        }

        // 새로운 브랜드 코드 설정
        $length = strlen($brandCd) / DEFAULT_LENGTH_BRAND;
        for ($i = 1; $i <= $length; $i++) {
            $setData['cateCd'][] = substr($brandCd, 0, ($i * DEFAULT_LENGTH_BRAND));
        }

        // 중복 브랜드 정리
        $setData['cateCd'] = array_unique($setData['cateCd']);
        if (isset($setData['sno'])) {
            if (count($setData['cateCd']) === count($setData['sno']) && $chkReturn === true) {
                return $setData;
            }
        }

        $strSQL = "SELECT IF(MAX(glb.goodsSort) > 0, (MAX(glb.goodsSort) + 1), 1) AS sort,MIN(glb.goodsSort) - 1 as reSort, glb.cateCd,cb.sortAutoFl,cb.sortType FROM ".DB_GOODS_LINK_BRAND." AS glb INNER JOIN ".DB_CATEGORY_BRAND." AS cb ON cb.cateCd = glb.cateCd WHERE glb.cateCd IN  ('" . implode('\',\'', $setData['cateCd']) . "') GROUP BY glb.cateCd";
        $result = $this->db->query($strSQL);
        while ($data = $this->db->fetch($result)) {
            if($data['sortAutoFl'] =='y')  $getData[$data['cateCd']] = 0;
            else  {
                if($data['sortType'] =='bottom') $getData[$data['cateCd']] = $data['reSort'];
                else  $getData[$data['cateCd']] = $data['sort'];
            }
        }

        // 새로운 브랜드 link 값
        foreach ($setData['cateCd'] as $key => $val) {
            if (empty($getData[$val])) {
                $setData['goodsSort'][$key] = gd_isset($setData['goodsSort'][$key], 1);
            } else {
                $setData['goodsSort'][$key] = gd_isset($setData['goodsSort'][$key], ($getData[$val] + 1));
            }

            if ($brandCd == $val) {
                $setData['cateLinkFl'][$key] = 'y';
            } else {
                $setData['cateLinkFl'][$key] = 'n';
            }

            gd_isset($setData['sno'][$key], '');
            // $setData['sno'][$key] = '';
        }

        return $setData;
    }

    /**
     * 상품의 등록 및 수정에 관련된 정보 (관리자 사용)
     *
     * @param integer $goodsNo 상품 번호
     * @param array $taxConf 과세 / 비과세 정보
     * @return array 해당 상품 데이타
     */
    public function getDataGoods($goodsNo = null, $taxConf)
    {
        $checked = [];

        // --- 사은품 증정 정책 config 불러오기
        $giftConf = gd_policy('goods.gift');

        // --- 등록인 경우
        if (is_null($goodsNo)) {
            // 기본 정보
            $data['mode'] = 'register';
            $data['goodsNo'] = null;
            $data['scmNo'] = (string)Session::get('manager.scmNo');

            if (Session::get('manager.isProvider')) {
                $scm = \App::load('\\Component\\Scm\\ScmAdmin');
                $scmInfo = $scm->getScmInfo($data['scmNo'], 'companyNm,scmCommission');
                $data['scmNoNm'] = $scmInfo['companyNm'];
                $data['commission'] = $scmInfo['scmCommission'];
            }

            // 옵션 설정
            $data['optionCnt'] = 0;
            $data['optionValCnt'] = 0;

            // 기본값 설정
            DBTableField::setDefaultData('tableGoods', $data, $taxConf);

            // 사은품
            $data['gift'] = null;

            //글로벌설정
            if($this->gGlobal['isUse']) {
                foreach($this->gGlobal['useMallList'] as $k => $v) {
                    $checked['goodsNmFl'][$v['sno']] = "checked='checked'";
                    $checked['shortDescriptionFl'][$v['sno']] = "checked='checked'";
                }
            }

            $data['hscode'] = ['kr'=>''];

            // --- 수정인 경우
        } else {
            // 기본 정보
            $data = $this->getGoodsInfo($goodsNo); // 상품 기본 정보
            if (Session::get('manager.isProvider')) {
                if ($data['scmNo'] != Session::get('manager.scmNo')) {
                    throw new AlertBackException(__("타 공급사의 자료는 열람하실 수 없습니다."));
                }
            }

            $data['link'] = $this->getGoodsLinkCategory($goodsNo); // 카테고리 연결 정보
            $data['addInfo'] = $this->getGoodsAddInfo($goodsNo); // 추가항목 정보
            $data['option'] = $this->getGoodsOption($goodsNo, $data); // 옵션 & 가격 정보
            $data['optionIcon'] = $this->getGoodsOptionIcon($goodsNo); // 옵션 추가 노출
            $data['optionText'] = $this->getGoodsOptionText($goodsNo); // 텍스트 옵션 정보
            $data['image'] = $this->getGoodsImage($goodsNo); // 이미지 정보
            $data['mode'] = 'modify';

            // 상품 필수 정보
            $data['goodsMustInfo'] = json_decode($data['goodsMustInfo'],true);
            foreach($data['goodsMustInfo'] as $key => $val){
                foreach($val as $k => $v){
                    $data['goodsMustInfo'][$key][$k]['infoTitle'] = gd_htmlspecialchars_decode($v['infoTitle']);
                    $data['goodsMustInfo'][$key][$k]['infoValue'] = gd_htmlspecialchars_decode($v['infoValue']);
                }
            }


            // 옵션 설정
            if ($data['optionFl'] == 'y' && $data['option'] && $data['optionName']) {
                $data['optionName'] = explode(STR_DIVISION, $data['optionName']);
                $data['optionCnt'] = count($data['optionName']);
                $data['optionValCnt'] = count($data['option']) - 1;
            } else {
                $data['optionName'] = null;
                $data['optionCnt'] = 0;
                $data['optionValCnt'] = 0;
            }

            // 배송 설정
            $tmp = explode(INT_DIVISION, $data['deliveryAdd']);
            unset($data['deliveryAdd']);
            $data['deliveryAdd']['cnt'] = gd_isset($tmp[0], 0);
            $data['deliveryAdd']['price'] = gd_isset($tmp[1], 0);
            unset($tmp);
            $tmp = explode(INT_DIVISION, $data['deliveryGoods']);
            unset($data['deliveryGoods']);
            $data['deliveryGoods']['cnt'] = gd_isset($tmp[0], 0);
            $data['deliveryGoods']['price'] = gd_isset($tmp[1], 0);
            unset($tmp);


            // 기본값 설정
            DBTableField::setDefaultData('tableGoods', $data, $taxConf);

            // 관련 상품 정보
            $data['relationGoodsNo'] = $this->getGoodsDataDisplay($data['relationGoodsNo']);
            if ($data['relationGoodsDate']) $data['relationGoodsDate'] = json_decode(gd_htmlspecialchars_stripslashes($data['relationGoodsDate']), true);


            //추가 상품 정보
            if ($data['addGoodsFl'] === 'y' && empty($data['addGoods']) === false) {

                $data['addGoods'] = json_decode(gd_htmlspecialchars_stripslashes($data['addGoods']), true);
                $addGoods = \App::load('\\Component\\Goods\\AddGoodsAdmin');
                if ($data['addGoods']) {
                    foreach ($data['addGoods'] as $k => $v) {
                        if($v['addGoods']) {
                            $data['addGoods'][$k]['addGoodsApplyCount'] = $addGoodsApplyCount = $this->db->getCount(DB_ADD_GOODS, 'addGoodsNo', 'WHERE applyFl !="y"  AND addGoodsNo IN ("' . implode('","', $v['addGoods']) . '")');

                            foreach ($v['addGoods'] as $k1 => $v1) {
                                $tmpField[] = 'WHEN \'' . $v1 . '\' THEN \'' . sprintf("%0".strlen(count($v['addGoods']))."d",$k1) . '\'';
                            }

                            $sortField = ' CASE addGoodsNo ' . implode(' ', $tmpField) . ' ELSE \'\' END ';
                            unset($tmpField);

                            $data['addGoods'][$k]['addGoodsList'] = $addGoods->getInfoAddGoodsGoods($v['addGoods'], null, $sortField);
                        }
                    }
                }
            }

            // 사은품 정보
            if ($giftConf['giftFl'] == 'y') {
                $gift = \App::load('\\Component\\Gift\\GiftAdmin');
                $data['gift'] = $gift->getGiftPresentInGoods($data['goodsNo'], $data['cateCd'], $data['brandCd']);
            } else {
                $data['gift'] = null;
            }

            if ($data['goodsColor']) $data['goodsColor'] = explode(STR_DIVISION, $data['goodsColor']);

            if ($data['brandCd']) {
                $brandCate = \App::load('\\Component\\Category\\BrandAdmin');
                $data['brandCdNm'] = $brandCate->getCategoryData($data['brandCd'], '', 'cateNm')[0]['cateNm'];

            }

            if (gd_is_provider() === false && gd_is_plus_shop(PLUSSHOP_CODE_PURCHASE) === true && $data['purchaseNo']) {
                $purchase = \App::load('\\Component\\Goods\\Purchase');
                $purchaseInfo = $purchase->getInfoPurchase($data['purchaseNo'],'purchaseNm,delFl');
                if($purchaseInfo['delFl'] =='n') $data['purchaseNoNm'] = $purchaseInfo['purchaseNm'];
            }

            if ($data['scmNo']) {
                $scm = \App::load('\\Component\\Scm\\ScmAdmin');
                $data['scmNoNm'] = $scm->getScmInfo($data['scmNo'], 'companyNm')['companyNm'];
            }


            if ($data['goodsPermissionGroup']) {
                $data['goodsPermissionGroup'] = explode(INT_DIVISION, $data['goodsPermissionGroup']);
                $memberGroupName = GroupUtil::getGroupName("sno IN ('" . implode("','", $data['goodsPermissionGroup']) . "')");
                $data['goodsPermissionGroup'] = $memberGroupName;
            }

            if($this->gGlobal['isUse']) {
                $tmpGlobalData = $this->getDataGoodsGlobal($data['goodsNo']);
                $globalData = array_combine(array_column($tmpGlobalData,'mallSno'),$tmpGlobalData);

                foreach($this->gGlobal['useMallList'] as $k => $v) {
                    if(!$globalData[$v['sno']]['goodsNm']) {
                        $checked['goodsNmFl'][$v['sno']] = "checked='checked'";
                    }

                    if(!$globalData[$v['sno']]['shortDescription']) {
                        $checked['shortDescriptionFl'][$v['sno']] = "checked='checked'";
                    }
                }

                $data['globalData'] = $globalData;

            }

            //HS코드
            if ($data['hscode']) $data['hscode'] = json_decode(gd_htmlspecialchars_stripslashes($data['hscode']), true);
            else $data['hscode'] = ['kr'=>''];
        }

        // 사은품 설정 여부
        $data['giftConf'] = $giftConf['giftFl'];

        // 배송 설정
        gd_isset($data['deliveryAdd']['cnt'], 0);
        gd_isset($data['deliveryAdd']['price'], 0);
        gd_isset($data['deliveryGoods']['cnt'], 0);
        gd_isset($data['deliveryGoods']['price'], 0);
        gd_isset($data['deliveryAddArea'], STR_DIVISION);

        //브랜드관련
        gd_isset($data['brandCdNm'], '');

        //그룹관련
        gd_isset($data['goodsPermissionGroup']);
        gd_isset($data['goodsPermission'], key($this->goodsPermissionList));


        // 최대 / 최소 수량
        if ($data['minOrderCnt'] == '1' && $data['maxOrderCnt'] == 0) {
            $data['maxOrderChk'] = 'n';
            $data['maxOrderCnt'] = null;
        } else {
            $data['maxOrderChk'] = 'y';
        }

        // 판매기간
        if (gd_isset($data['salesStartYmd']) != '0000-00-00 00:00:00' && gd_isset($data['salesEndYmd']) != '0000-00-00 00:00:00') {
            $data['salesDateFl'] = 'y';
        } else {
            $data['salesDateFl'] = 'n';
        }

        if ($data['scmNo'] == DEFAULT_CODE_SCMNO) $data['scmFl'] = 'n';
        else $data['scmFl'] = 'y';

        //외부동영상 설정 사이즈
        if ($data['externalVideoWidth'] > 0 && $data['externalVideoHeight']) $data['externalVideoSizeFl'] = 'n';
        else $data['externalVideoSizeFl'] = 'y';

        $checked['naverFl'][$data['naverFl']] = $checked['optionImageDisplayFl'][$data['optionImageDisplayFl']] = $checked['optionImagePreviewFl'][$data['optionImagePreviewFl']] = $checked['naverAgeGroup'][$data['naverAgeGroup']] = $checked['goodsDescriptionSameFl'][$data['goodsDescriptionSameFl']] = $checked['payLimitFl'][$data['payLimitFl']] = $checked['goodsNmFl'][$data['goodsNmFl']] = $checked['restockFl'][$data['restockFl']] = $checked['restockFl'][$data['restockFl']] = $checked['cateCd'][$data['cateCd']] = $checked['mileageFl'][$data['mileageFl']] = $checked['optionFl'][$data['optionFl']] = $checked['optionDisplayFl'][$data['optionDisplayFl']] = $checked['optionTextFl'][$data['optionTextFl']] = $checked['goodsDisplayFl'][$data['goodsDisplayFl']] = $checked['goodsSellFl'][$data['goodsSellFl']] = $checked['goodsDisplayMobileFl'][$data['goodsDisplayMobileFl']] = $checked['goodsSellMobileFl'][$data['goodsSellMobileFl']] = $checked['taxFreeFl'][$data['taxFreeFl']] = $checked['stockFl'][$data['stockFl']] = $checked['soldOutFl'][$data['soldOutFl']] = $checked['maxOrderChk'][$data['maxOrderChk']] = $checked['deliveryFl'][$data['deliveryFl']] = $checked['deliveryFree'][$data['deliveryFree']] = $checked['relationSameFl'][$data['relationSameFl']] = $checked['relationFl'][$data['relationFl']] = $checked['qrCodeFl'][$data['qrCodeFl']] = $checked['goodsPermission'][$data['goodsPermission']] = $checked['onlyAdultFl'][$data['onlyAdultFl']] = $checked['goodsDiscountFl'][$data['goodsDiscountFl']] = $checked['salesDateFl'][$data['salesDateFl']] = $checked['addGoodsFl'][$data['addGoodsFl']] = $checked['imgDetailViewFl'][$data['imgDetailViewFl']] = $checked['externalVideoFl'][$data['externalVideoFl']] = $checked['goodsState'][$data['goodsState']] = $checked['scmFl'][$data['scmFl']] = $checked['externalVideoSizeFl'][$data['externalVideoSizeFl']] = 'checked="checked"';

        // 상품 아이콘 설정
        $this->db->strField = implode(', ', DBTableField::setTableField('tableManageGoodsIcon', null, 'iconUseFl'));
        $this->db->strWhere = 'iconUseFl = \'y\'';
        $this->db->strOrder = 'sno DESC';
        $data['icon'] = $this->getManageGoodsIconInfo();

        // 기간제한용
        if (!empty($data['goodsIconCdPeriod'])) {
            $goodsIconCd = explode(INT_DIVISION, $data['goodsIconCdPeriod']);
            unset($data['goodsIconCdPeriod']);
            foreach ($goodsIconCd as $key => $val) {
                $checked['goodsIconCdPeriod'][$val] = 'checked="checked"';
            }
        }

        // 무제한용
        if (!empty($data['goodsIconCd'])) {
            $goodsIconCd = explode(INT_DIVISION, $data['goodsIconCd']);
            unset($data['goodsIconCd']);
            foreach ($goodsIconCd as $key => $val) {
                $checked['goodsIconCd'][$val] = 'checked="checked"';
            }
        }

        //결제수단제한
        if ($data['payLimitFl'] == 'y') {
            $payLimit = explode(STR_DIVISION, $data['payLimit']);
            foreach ($payLimit as $k => $v) {
                $checked['payLimit'][$v] = 'checked="checked"';
            }
            unset($payLimit);
        }

        // 상품 상세 이용안내
        $inform = \App::load('\\Component\\Agreement\\BuyerInform');
        $detailInfo = array('detailInfoDelivery', 'detailInfoAS', 'detailInfoRefund', 'detailInfoExchange', 'detailInfoPayment', 'detailInfoService');
        $data['detail'] = $inform->getGoodsInfoCode($data['mode'], $data['scmNo']);

        // 상품 상세 이용안내 기본값 설정
        if ($data['mode'] == "register" && isset($data['detail']['default'])) {
            foreach ($data['detail']['default'] as $key => $val) {
                $data[$key] = $val;
            }
            foreach ($data['detail']['defaultInformNm'] as $key => $val) {
                $data[$key . 'InformNm'] = $val;
            }
            foreach ($data['detail']['defaultInformContent'] as $key => $val) {
                $data[$key . 'InformContent'] = $val;
            }
            unset($data['detail']['default'], $data['detail']['defaultInformNm'], $data['detail']['defaultInformContent']);
        } else if ($data['mode'] == "modify") {

            foreach ($detailInfo as $val) {
                $infoData = gd_buyer_inform($data[$val]);
                $data[$val . 'InformNm'] = $infoData['informNm'];
                $data[$val . 'InformContent'] = $infoData['content'];
            }
        }

        // 상품 상세 이용안내 입력여부 설정
        foreach ($detailInfo as $val) {
            if ($data[$val . 'Fl'] == '') { //레거시 적용
                if (!empty($data[$val])) {
                    $checked[$val . 'Fl']['selection'] = 'checked="checked"';
                    $data[$val . 'Fl'] = 'selection';
                } else {
                    $checked[$val . 'Fl']['no'] = 'checked="checked"';
                    $data[$val . 'Fl'] = 'no';
                }
            } else {
                $checked[$val . 'Fl'][$data[$val . 'Fl']] = 'checked="checked"';
                $data[$val . 'Fl'] = $data[$val . 'Fl'];
            }
        }

        $selected = array();
        $selected['naverImportFlag'][$data['naverImportFlag']] =$selected['naverProductFlag'][$data['naverProductFlag']] =$selected['naverGender'][$data['naverGender']] = $selected['mileageGoodsUnit'][$data['mileageGoodsUnit']] = $selected['goodsDiscountUnit'][$data['goodsDiscountUnit']] = "selected";

        $getData['data'] = $data;
        $getData['checked'] = $checked;
        $getData['selected'] = $selected;

        return $getData;
    }

    /**
     * 상품 아이콘 정보(관리자사용)
     *
     * @return array 상품 아이콘 정보
     */
    public function getManageGoodsIconInfo()
    {
        if (is_null($this->db->strField)) {
            $arrField = DBTableField::setTableField('tableManageGoodsIcon');
            $this->db->strField = 'sno, ' . implode(', ', $arrField);
        }

        if (empty($this->arrBind)) {
            $this->arrBind = null;
        }

        $query = $this->db->query_complete();
        $strSQL = 'SELECT ' . array_shift($query) . ' FROM ' . DB_MANAGE_GOODS_ICON . implode(' ', $query);
        $data = $this->db->query_fetch($strSQL, $this->arrBind);

        return gd_htmlspecialchars_stripslashes(gd_isset($data));
    }

    /**
     * 상품 정보 저장
     *
     * @param array $arrData 저장할 정보의 배열
     */
    public function saveInfoGoods($arrData)
    {
        // 상품명 체크
        if (Validator::required(gd_isset($arrData['goodsNm'])) === false) {
            throw new \Exception(__('상품명은 필수 항목 입니다.'), 500);

        }

        $updateFl = "n";

        //기존정보 복사인경우 이미지 경로 초기화
        if($arrData['applyGoodsCopy']) {
            $arrData['imagePath'] = null;
            unset($arrData['optionY']['sno']);
            //unset($arrData['imageDB']['sno']);
            //unset($arrData['imageDB']['imageCode']);
        }
        // 공급자 아이디
        gd_isset($arrData['scmNo'], DEFAULT_CODE_SCMNO);

        if (empty($arrData['brandCdNm'])) unset($arrData['brandCd']);

        // 노출 카테고리 / 부모 카테고리 설정
        /*
        if (gd_isset($arrData['link'])) {
            $arrData['link'] = $this->getGoodsCategoyCheck($arrData['link'], gd_isset($arrData['goodsNo']));
            if ($arrData['mode'] == 'register') {
                unset($arrData['goodsNo']);
            }
        }
        */


        // 대표 카테고리 설정
        if (isset($arrData['cateCd']) == false && is_array($arrData['link'])) {
            foreach ($arrData['link']['cateCd'] as $key => $val) {
                if ($arrData['link']['cateLinkFl'][$key] == 'y') {
                    $arrData['cateCd'] = $val;
                    break;
                }
            }
        }

			 //$arrData['goodsWidth'] = '1';
			 //$arrData['goodsHeight'] = '2';
			 //$arrData['goodsDepth'] = '1';

        //대표 색상
        if (is_array($arrData['goodsColor'])) {
            $arrData['goodsColor'] = implode(STR_DIVISION, $arrData['goodsColor']);
        } else {
            $arrData['goodsColor'] = "";
        }

        //구매 가능 권한 설정
        if ($arrData['goodsPermission'] === 'group' && is_array($arrData['memberGroupNo'])) {
            $arrData['goodsPermissionGroup'] = implode(INT_DIVISION, $arrData['memberGroupNo']);
        } else $arrData['goodsPermissionGroup'] = '';


        //통합설정인 경우 개별설정값 초기화
        if ($arrData['mileageFl'] == 'c') $arrData['mileageGoods'] = '';

        //상품할인설정 사용안함일경우 초기화
        if ($arrData['goodsDiscountFl'] == 'n') $arrData['goodsDiscount'] = '';

        //pc모바일상세설명
        if (empty($arrData['goodsDescriptionSameFl'])) $arrData['goodsDescriptionSameFl'] = 'n';

        if (empty($arrData['optionImagePreviewFl'])) $arrData['optionImagePreviewFl'] = 'n';
        if (empty($arrData['optionImageDisplayFl'])) $arrData['optionImageDisplayFl'] = 'n';

        //추가상품
        if ($arrData['addGoodsFl'] == 'y' && is_array($arrData['goodsNoData'])) {
            $goodsNoData = [];
            if (is_array($arrData['goodsNoData'])) {
                if (gd_isset($arrData['addGoodsGroupTitle']) && is_array($arrData['addGoodsGroupTitle'])) {
                    $startGoodsNo = 0;
                    foreach ($arrData['addGoodsGroupCnt'] as $k => $v) {
                        $goodsNoData = array_slice($arrData['goodsNoData'], $startGoodsNo, $v);

                        $addGoods[$k]['title'] = $arrData['addGoodsGroupTitle'][$k];
                        if ($arrData['addGoodsGroupMustFl'][$k]) $addGoods[$k]['mustFl'] = 'y';
                        else  $addGoods[$k]['mustFl'] = 'n';
                        $addGoods[$k]['addGoods'] = $goodsNoData;

                        $startGoodsNo += $v;
                    }
                }
            }


            if ($addGoods) $arrData['addGoods'] = json_encode(gd_htmlspecialchars($addGoods), JSON_UNESCAPED_UNICODE);
        } else {
            $arrData['addGoods'] = "";
        }

        if ($arrData['payLimitFl'] == 'y') {

            $arrData['payLimit'] = implode(STR_DIVISION, $arrData['payLimit']);

            unset($payLimit);
            unset($payLimitArr);

        } else {
            $arrData['payLimit'] = "";
        }


        //판매기간
        if ($arrData['salesDateFl'] == 'y' && is_array($arrData['salesDate'])) {
            $arrData['salesStartYmd'] = $arrData['salesDate'][0];
            $arrData['salesEndYmd'] = $arrData['salesDate'][1];
        } else {
            $arrData['salesStartYmd'] = '';
            $arrData['salesEndYmd'] = '';
        }


        //외부 동영상
        if ($arrData['externalVideoSizeFl'] == 'y') {
            $arrData['externalVideoWidth'] = 0;
            $arrData['externalVideoHeight'] = 0;
        }

        // 상품 필수 정보 처리
        $arrData['goodsMustInfo'] = '';
        if (isset($arrData['addMustInfo']) && is_array($arrData['addMustInfo']) && is_array($arrData['addMustInfo']['infoTitle'])) {
            $tmpGoodsMustInfo = array();
            $i = 0;
            foreach ($arrData['addMustInfo']['infoTitle'] as $mKey => $mVal) {
                foreach ($mVal as $iKey => $iVal) {
                    $tmpGoodsMustInfo['line' . $i]['step' . $iKey]['infoTitle'] = $iVal;
                    $tmpGoodsMustInfo['line' . $i]['step' . $iKey]['infoValue'] = $arrData['addMustInfo']['infoValue'][$mKey][$iKey];
                }
                $i++;
            }

            $arrData['goodsMustInfo'] = json_encode(gd_htmlspecialchars($tmpGoodsMustInfo), JSON_UNESCAPED_UNICODE);

            unset($arrData['addMustInfo'], $tmpGoodsMustInfo, $tmpGoodsMustInfo);
        }

        // 옵션 사용여부에 따른 재정의 및 배열 삭제

        if ($arrData['optionFl'] == 'y') {
            // 옵션의 존재여부에 따른 체크
            if (isset($arrData['optionY']) === false || isset($arrData['optionY']['optionName']) === false || isset($arrData['optionY']['optionValue']) === false) {
                throw new \Exception(__("옵션값을 확인해주세요."), 500);
            }
            unset($arrData['optionY']['optionNo']);
            foreach($arrData['optionY']['optionValueText'] as $k => $v) {
                $arrData['optionY']['optionNo'][] = $k+1;
                $tmpOptionText = explode(STR_DIVISION,$v);
                foreach($tmpOptionText as $k1 => $v1) {
                    $arrData['optionY']['optionValue'.($k1+1)][] = $v1;
                }
            }

            unset($arrData['optionY']['optionValueText']);

            $arrData['option'] = $arrData['optionY'];
            $arrData['optionDisplayFl'] = $arrData['option']['optionDisplayFl'];
            $arrData['optionName'] = implode(STR_DIVISION, $arrData['option']['optionName']);

            if (count($arrData['option']['optionCnt']) == 1) {
                $arrData['optionDisplayFl'] = 's';
            }

            unset($arrData['option']['optionDisplayFl']);
            unset($arrData['option']['optionName']);
        } else {

            $arrData['optionN']['stockCnt'][0] = $arrData['stockCnt'];
            $arrData['optionN']['optionPrice'][0] = 0;
            $arrData['option'] = $arrData['optionN'];
            $arrData['optionName'] = "";
        }
        unset($arrData['optionY']);
        unset($arrData['optionN']);


        // 텍스트 옵션 필수 여부 기본값 설정
        if ($arrData['optionTextFl'] == 'y') {
            // 텍스트 옵션 값이 있는 지를 체크함
            if (isset($arrData['optionText']) === false || empty($arrData['optionText']['optionName'][0]) === true) {
                // 옵션명이나 값이 없는 경우 사용안함 처리
                $arrData['optionTextFl'] = 'n';
                if (isset($arrData['optionText']) === true) {
                    unset($arrData['optionText']);
                }
            } else {
                for ($i = 0; $i < count($arrData['optionText']['optionName']); $i++) {
                    gd_isset($arrData['optionText']['mustFl'][$i], 'n');
                }
            }
        }

        // 최대 / 최소 수량
        if ($arrData['maxOrderChk'] == 'n') {
            $arrData['maxOrderCnt'] = 0;
            $arrData['minOrderCnt'] = 1;
        }

        // goodsNo 처리
        if ($arrData['mode'] == 'register') {
            $arrData['goodsNo'] = $this->doGoodsNoInsert();
        } else {
            // goodsNo 체크
            if (Validator::required(gd_isset($arrData['goodsNo'])) === false) {
                throw new \Exception(__('상품번호는 필수 항목입니다.'), 500);
            }
        }
        $this->goodsNo = $arrData['goodsNo'];


        // 이미지 저장 경로 설정
        $this->imagePath = $arrData['imagePath'];
        if (empty($arrData['imagePath']) && ($arrData['mode'] == 'register' || $arrData['applyGoodsCopy'])) {
            $this->imagePath = $arrData['imagePath'] = DIR_GOODS_IMAGE . $arrData['goodsNo'] . '/';
        }

        $getLink = $getBrand = $getAddInfo = $getOption = $getOptionText = $getOptionAddName = $getOptionAddValue = array();
        if ($arrData['mode'] == 'modify') {
            $getLink = $this->getGoodsLinkCategory($arrData['goodsNo']); // 카테고리 정보
            $getBrand = $this->getGoodsLinkBrand($arrData['goodsNo']); // 브랜드 정보
            $getAddInfo = $this->getGoodsAddInfo($arrData['goodsNo']); // 상품 추가 정보
            $getOption = $this->getGoodsOption($arrData['goodsNo'], $arrData); // 옵션/가격 정보
            if ($getOption) {
                foreach ($getOption as $k => $v) {
                    $getOption[$k]['optionPrice'] = gd_money_format($v['optionPrice'], false);
                }
            }

            $getOptionText = $this->getGoodsOptionText($arrData['goodsNo']); // 텍스트 옵션 정보
            if ($getOptionText) {
                foreach ($getOptionText as $k => $v) {
                    $getOption[$k]['addPrice'] = gd_money_format($v['addPrice'], false);
                }
            }

            $getGoods = $this->getGoodsInfo($arrData['goodsNo']); // 상품정보

            unset($getOption['optVal']); // 옵션값은 삭제
        }


        // 브랜드 설정
        if ((empty($arrData['brandCd']) && empty($arrData['brand']) === false) || gd_isset($arrData['brandSelect']) == 'y') {
            $arrData['brandCd'] = ArrayUtils::last($arrData['brand']);
        }
        $arrData['brandLink'] = array();
        if (gd_isset($arrData['brandCd'])) {
            $arrData['brandLink'] = $this->getGoodsBrandCheck($arrData['brandCd'], $getBrand, $arrData['goodsNo']);
        }

        //카테고리 순서 관련
        $strSQL = "SELECT IF(MAX(glc.goodsSort) > 0, (MAX(glc.goodsSort) + 1), 1) AS sort,MIN(glc.goodsSort) - 1 as reSort, glc.cateCd,cg.sortAutoFl,cg.sortType FROM ".DB_GOODS_LINK_CATEGORY." AS glc INNER JOIN ".DB_CATEGORY_GOODS." AS cg ON cg.cateCd = glc.cateCd WHERE glc.cateCd IN  ('" . implode('\',\'', $arrData['link']['cateCd']) . "') GROUP BY glc.cateCd";

				$result = $this->db->query($strSQL);
        while ($sortData = $this->db->fetch($result)) {
            if($sortData['sortAutoFl'] =='y') $arrData['link']['goodsSort'][] = 0;
            else  {
                if($sortData['sortType'] =='bottom') $arrData['link']['goodsSort'][]  = $sortData['reSort'];
                else $arrData['link']['goodsSort'][]  = $sortData['sort'];
            }
        }
        // 카테고리 정보
        $compareLink = $this->db->get_compare_array_data($getLink, $arrData['link'],true,array_keys($arrData['link']));

        // 브랜드 정보
        $compareBrand = $this->db->get_compare_array_data($getBrand, $arrData['brandLink']);

        // 상품 추가 정보
        $compareAddInfo = $this->db->get_compare_array_data($getAddInfo, gd_isset($arrData['addInfo']));

        // 옵션 가격 정보
        $compareOption = $this->db->get_compare_array_data($getOption, $arrData['option'], true, array_keys($arrData['option']), 'tableGoodsOption');

        // 전체 재고량
        if (isset($arrData['option']['stockCnt'])) $arrData['totalStock'] = array_sum($arrData['option']['stockCnt']);
        else $arrData['totalStock'] = $arrData['stockCnt'];


        // 텍스트 옵션 정보
        if ($arrData['optionTextFl'] != 'y') {
            unset($arrData['optionText']);
        }
        $compareOptionText = $this->db->get_compare_array_data($getOptionText, gd_isset($arrData['optionText']));

        // 공통 키값
        $arrDataKey = array('goodsNo' => $arrData['goodsNo']);
        $compareData = [];

        // 카테고리 정보 저장
        $cateLog = $this->db->set_compare_process(DB_GOODS_LINK_CATEGORY, $arrData['link'], $arrDataKey, $compareLink);
        if ($cateLog && $arrData['mode'] == 'modify') {
            $updateFl = "y";
            $this->setGoodsLog('category', $arrData['goodsNo'], $getLink, $cateLog);
        }

        // 브랜드 정보 저장
        $this->db->set_compare_process(DB_GOODS_LINK_BRAND, $arrData['brandLink'], $arrDataKey, $compareBrand);

        // 상품 추가정보 저장
        $addInfoLog = $this->db->set_compare_process(DB_GOODS_ADD_INFO, $arrData['addInfo'], $arrDataKey, $compareAddInfo);
        if ($addInfoLog && $arrData['mode'] == 'modify') {
            $updateFl = "y";
            $this->setGoodsLog('addInfo', $arrData['goodsNo'], $getAddInfo, $addInfoLog);
        }

        // 옵션 가격 정보
        $optionLog = $this->db->set_compare_process(DB_GOODS_OPTION, $arrData['option'], $arrDataKey, $compareOption);
        if ($optionLog && $arrData['mode'] == 'modify') {
            $updateFl = "y";
            $this->setGoodsLog('option', $arrData['goodsNo'], $getOption, $optionLog);
        }

        // 텍스트 옵션 정보 저장
        $optionTextLog = $this->db->set_compare_process(DB_GOODS_OPTION_TEXT, $arrData['optionText'], $arrDataKey, $compareOptionText);
        if ($optionTextLog && $arrData['mode'] == 'modify') {
            $updateFl = "y";
            $this->setGoodsLog('optionText', $arrData['goodsNo'], $getOptionText, $optionTextLog);
        }

        // 관련 상품 설정
        if ($arrData['relationFl'] == 'm') {
            if (isset($arrData['relationGoodsNo'])) {

                $arrData['relationCnt'] = '0';

                foreach ($arrData['relationGoodsNo'] as $k => $v) {

                    if ($v == $arrData['goodsNo']) {
                        unset($arrData['relationGoodsNo'][$k]);
                    } else {
                        if (gd_isset($arrData['relationGoodsNoStartYmd'][$k])) {
                            $relationGoodsDate[$v]['startYmd'] = $arrData['relationGoodsNoStartYmd'][$k];
                        }
                        if (gd_isset($arrData['relationGoodsNoEndYmd'][$k])) {
                            $relationGoodsDate[$v]['endYmd'] = $arrData['relationGoodsNoEndYmd'][$k];
                        }

                        //서로 등록 관련
                        $strSQL = ' SELECT COUNT(*) AS cnt,relationGoodsNo FROM ' . DB_GOODS . ' WHERE relationGoodsNo LIKE concat(\'%\',?,\'%\') AND goodsNo = ?';
                        $this->db->bind_param_push($arrBind, 's', $arrData['goodsNo']);
                        $this->db->bind_param_push($arrBind, 's', $v);
                        $res = $this->db->query_fetch($strSQL, $arrBind, false);
                        unset($arrBind);
                        $tmpCnt = $res['cnt'];
                        $tmpRelationGoodsNo = $res['relationGoodsNo'];


                        //서로 등록인 경우
                        if ($arrData['relationSameFl'] == 'y' && $tmpCnt == 0) {
                            $this->db->set_update_db(DB_GOODS, "relationFl = 'm', relationGoodsNo = concat(relationGoodsNo,if( CHAR_LENGTH(relationGoodsNo) = 0, '', '" . INT_DIVISION . "' ) ,'" . $arrData['goodsNo'] . "')", "goodsNo = '{$v}'");
                        }

                        //서로 등록이 아닌 경우
                        if ($arrData['relationSameFl'] == 'n' && $tmpCnt > 0) {
                            $tmpRelationGoodsNo = str_replace(INT_DIVISION, '', str_replace($arrData['goodsNo'], "", $tmpRelationGoodsNo));
                            if ($tmpRelationGoodsNo) $relationFl = 'm';
                            else $relationFl = 'n';

                            $this->db->set_update_db(DB_GOODS, "relationFl = '" . $relationFl . "', relationGoodsNo = replace(relationGoodsNo,'" . $arrData['goodsNo'] . "','')", "goodsNo = '{$v}'");
                        }

                    }
                }

                $arrData['relationGoodsDate'] = json_encode($relationGoodsDate);

                $arrData['relationGoodsNo'] = implode(INT_DIVISION, $arrData['relationGoodsNo']);

            } else {
                $arrData['relationFl'] = 'n';
            }
        }
        if ($arrData['relationFl'] == 'a') {
            $arrData['relationCnt'] = '0';
            $arrData['relationGoodsNo'] = null;
        }
        if ($arrData['relationFl'] == 'n') {
            $arrData['relationCnt'] = '0';
            $arrData['relationGoodsNo'] = null;
        }

        // 아이콘 설정
        if (isset($arrData['goodsIconCdPeriod'])) {
            $arrData['goodsIconCdPeriod'] = implode(INT_DIVISION, $arrData['goodsIconCdPeriod']);
        } else {
            $arrData['goodsIconCdPeriod'] = "";
        }
        if (isset($arrData['goodsIconCd'])) {
            $arrData['goodsIconCd'] = implode(INT_DIVISION, $arrData['goodsIconCd']);
        } else {
            $arrData['goodsIconCd'] ="";
        }

        $arrData['hscode'] = array_filter(array_map('trim',$arrData['hscode']));

        if($arrData['hscode']) {
            $hscode = [];
            foreach($arrData['hscodeNation'] as $k => $v) {
                $hscode[$v] = $arrData['hscode'][$k];
            }
            $arrData['hscode'] = json_encode(gd_htmlspecialchars($hscode), JSON_UNESCAPED_UNICODE);
            unset($hscode);
        } else {
            $arrData['hscode'] = "";
        }

        if ($arrData['mode'] == 'modify') {

            DBTableField::setDefaultData('tableGoods', array_keys($arrData));

            $result = [];
            $expectField = array('modDt', 'regDt', 'applyDt', 'applyFl', 'applyMsg', 'delDt', 'applyType', 'hitCnt', 'orderCnt', 'reviewCnt');

            // 기존 정보를 변경
            foreach ($getGoods as $key => $val) {
                if ($val != $arrData[$key] && !in_array($key, $expectField)) {
                    $result[$key] = $arrData[$key];
                }
            }

            if ($result) {
                $updateFl = "y";
                $this->setGoodsLog('goods', $arrData['goodsNo'], $getGoods, $result);
            }
        }


        //공급사이면서 자동승인이 아닌경우 상품 승인신청 처리
        if (Session::get('manager.isProvider') && (($arrData['mode'] == 'modify' && Session::get('manager.scmPermissionModify') == 'c') || ($arrData['mode'] == 'register' && Session::get('manager.scmPermissionInsert') == 'c'))) {
            if (($arrData['mode'] == 'modify' && $updateFl == 'y') || $arrData['mode'] == 'register') {
                $arrData['applyFl'] = 'a';
                $arrData['applyDt'] = date('Y-m-d H:i:s');
            }

            $arrData['applyType'] = strtolower(substr($arrData['mode'], 0, 1));

        } else  $arrData['applyFl'] = 'y';

        $arrExclude[] = 'hitCnt';
        $arrExclude[] = 'orderCnt';
        $arrExclude[] = 'reviewCnt';

        // 운영자 기능권한 처리
        if (Session::get('manager.functionAuthState') == 'check' && Session::get('manager.functionAuth.goodsSalesDate') != 'y') {
            $arrExclude[] = 'salesStartYmd';
            $arrExclude[] = 'salesEndYmd';
        }

        // 상품 정보 저장
        if ($arrData['mode'] == 'modify') {
            // 운영자 기능권한 처리
            if (Session::get('manager.functionAuthState') == 'check' && Session::get('manager.functionAuth.goodsCommission') != 'y') {
                $arrExclude[] = 'commission';
            }
            if (Session::get('manager.functionAuthState') == 'check' && Session::get('manager.functionAuth.goodsNm') != 'y') {
                $arrExclude[] = 'goodsNmFl';
                $arrExclude[] = 'goodsNm';
                $arrExclude[] = 'goodsNmMain';
                $arrExclude[] = 'goodsNmList';
                $arrExclude[] = 'goodsNmDetail';
            }
            if (Session::get('manager.functionAuthState') == 'check' && Session::get('manager.functionAuth.goodsPrice') != 'y') {
                $arrExclude[] = 'goodsPrice';
            }
            $arrBind = $this->db->get_binding(DBTableField::getBindField('tableGoods', array_keys($arrData)), $arrData, 'update', null, $arrExclude);
        } else {
            // 운영자 기능권한 처리
            if (Session::get('manager.functionAuthState') == 'check' && Session::get('manager.functionAuth.goodsCommission') != 'y') {
                if ($arrData['scmNo'] != DEFAULT_CODE_SCMNO) {
                    $scm = \App::load('\\Component\\Scm\\ScmAdmin');
                    $scmInfo = $scm->getScmInfo($arrData['scmNo'], 'scmCommission');
                    $arrData['commission'] = $scmInfo['scmCommission'];
                }
            }
            $arrBind = $this->db->get_binding(DBTableField::getBindField('tableGoods', array_keys($arrData)), $arrData, 'update', null, $arrExclude);
        }
        $this->db->bind_param_push($arrBind['bind'], 'i', $arrData['goodsNo']);
        $this->db->set_update_db(DB_GOODS, $arrBind['param'], 'goodsNo = ?', $arrBind['bind']);
        unset($arrBind);

        if($this->gGlobal['isUse']) {

            $arrBind = [];
            $this->db->bind_param_push($arrBind, 's', $arrData['goodsNo']);
            $this->db->set_delete_db(DB_GOODS_GLOBAL, 'goodsNo = ?', $arrBind);
            unset($arrBind);

            if($arrData['globalData']) {
                foreach($arrData['globalData'] as $k => $v) {
                    if(array_filter(array_map('trim',$v))) {
                        $globalData = $v;
                        $globalData['mallSno'] = $k;
                        $globalData['goodsNo'] = $arrData['goodsNo'];

                        $arrBind = $this->db->get_binding(DBTableField::tableGoodsGlobal(), $globalData, 'insert');
                        $this->db->set_insert_db(DB_GOODS_GLOBAL, $arrBind['param'], $arrBind['bind'], 'y');
                        unset($arrBind);
                    }
                }
            }
        }

        // --- 상품 이미지 정보 저장
        if ($arrData['imageStorage'] == 'url') {
            $imageMode = $arrData['image'];
        } else {
            $imageMode = Request::files()->toArray()['image'];
        }


        if($arrData['imageStorage'] != 'url' && $arrData['applyGoodsCopy'] && $arrData['applyGoodsimagePath']) {
            $this->db->set_delete_db(DB_GOODS_IMAGE, "goodsNo = '".$arrData['goodsNo']."'");
            Storage::disk(Storage::PATH_CODE_GOODS, $arrData['imageStorage'])->deleteDir($this->imagePath);
            Storage::copy(Storage::PATH_CODE_GOODS, $arrData['imageStorage'], $arrData['applyGoodsimagePath'], $arrData['imageStorage'], $this->imagePath);
        }

        $this->imageUploadGoods($imageMode, $arrData['imageStorage'], $arrData['imageSize'], gd_isset($arrData['imageResize']), gd_isset($arrData['imageDB']), $arrData['goodsNo'],$arrData['mode']);

        if($arrData['mobileappFl'] === true){
            //모바일앱에서 접근시 상품이미지 처리
            $this->mobileapp_imageUploadGoods($arrData);
            //1시간이 지난 임시 이미지 삭제
            $this->mobileapp_removeTempImage();
        }

        // --- 상품 옵션 추가노출 저장
        if ($arrData['optionFl'] == 'y') {
            if($arrData['option']['optionImageDeleteFl']) {
                foreach($arrData['option']['optionImageDeleteFl'][0] as $k => $v) {
                    if($v =='y') {
                        $arrBind = [];
                        $this->db->bind_param_push($arrBind, 'i', $arrData['goodsNo']);
                        $this->db->bind_param_push($arrBind, 's', $arrData['option']['optionValue'][0][$k]);
                        $this->db->set_delete_db(DB_GOODS_OPTION_ICON, 'goodsNo = ? AND optionValue = ?', $arrBind);
                        unset($arrData['optionYIcon']['goodsImage'][0][$k]);
                        unset($arrBind);
                    }
                }
            }

            $this->imageUploadIcon(Request::files()->get('optionYIcon'), $arrData['optionYIcon'], $arrData['option']['optionValue'], $arrData['imageStorage']);
        } else {
            // 상품 옵션 아이콘 테이블 지우기
            $this->db->bind_param_push($arrBind, 'i', $arrData['goodsNo']);
            $this->db->set_delete_db(DB_GOODS_OPTION_ICON, 'goodsNo = ?', $arrBind);
            unset($arrBind);
        }

        if($arrData['soldOutFl'] == 'y') {
            $arrData['applyFl'] = 'n';
        }

        if ($arrData['mode'] == 'register') {
            $this->setGoodsUpdateEp($arrData['applyFl'], $arrData['goodsNo'], true);
        } else {
            $this->setGoodsUpdateEp($arrData['applyFl'], $arrData['goodsNo']);
        }


        return $arrData['applyFl'];


    }

    /**
     * 상품 정보 저장 - 모바일앱
     *
     * @param array $arrData 저장할 정보의 배열
     */
    public function saveInfoGoods_mobileapp_modify($arrData)
    {
        // 상품명, 상품번호 체크
        if (Validator::required(gd_isset($arrData['goodsNm'])) === false) {
            throw new \Exception(__('상품명은 필수 항목 입니다.'), 500);
        }
        if (Validator::required(gd_isset($arrData['goodsNo'])) === false) {
            throw new \Exception(__('상품번호는 필수 항목입니다.'), 500);
        }

        // 옵션 사용여부에 따른 재정의 및 배열 삭제
        if ($arrData['optionFl'] == 'y') {
            // 옵션의 존재여부에 따른 체크
            if (isset($arrData['optionY']) === false || isset($arrData['optionY']['optionName']) === false || isset($arrData['optionY']['optionValue']) === false) {
                throw new \Exception(__("옵션값을 확인해주세요."), 500);
            }
            unset($arrData['optionY']['optionNo']);
            foreach($arrData['optionY']['optionValueText'] as $k => $v) {
                $arrData['optionY']['optionNo'][] = $k+1;
                $tmpOptionText = explode(STR_DIVISION,$v);
                foreach($tmpOptionText as $k1 => $v1) {
                    $arrData['optionY']['optionValue'.($k1+1)][] = $v1;
                }
            }

            unset($arrData['optionY']['optionValueText']);

            $arrData['option'] = $arrData['optionY'];
            $arrData['optionDisplayFl'] = $arrData['option']['optionDisplayFl'];
            $arrData['optionName'] = implode(STR_DIVISION, $arrData['option']['optionName']);

            if (count($arrData['option']['optionCnt']) == 1) {
                $arrData['optionDisplayFl'] = 's';
            }

            unset($arrData['option']['optionDisplayFl']);
            unset($arrData['option']['optionName']);
        }
        else {
            $arrData['optionN']['stockCnt'][0] = $arrData['stockCnt'];
            $arrData['option'] = $arrData['optionN'];
        }
        unset($arrData['optionY']);
        unset($arrData['optionN']);


        $getOption = array();
        $getOption = $this->getGoodsOption($arrData['goodsNo'], $arrData); // 옵션/가격 정보
        if ($getOption) {
            foreach ($getOption as $k => $v) {
                $getOption[$k]['optionPrice'] = gd_money_format($v['optionPrice'], false);
            }
        }
        unset($getOption['optVal']); // 옵션값은 삭제

        // 옵션 가격 정보
        $compareOption = $this->db->get_compare_array_data($getOption, $arrData['option'], true, array_keys($arrData['option']), 'tableGoodsOption');

        // 전체 재고량
        if (isset($arrData['option']['stockCnt'])) {
            $arrData['totalStock'] = array_sum($arrData['option']['stockCnt']);
        }
        else {
            $arrData['totalStock'] = $arrData['stockCnt'];
        }

        // 옵션 가격 정보
        $arrDataKey = array('goodsNo' => $arrData['goodsNo']);
        $optionLog = $this->db->set_compare_process(DB_GOODS_OPTION, $arrData['option'], $arrDataKey, $compareOption);
        if ($optionLog) {
            $this->setGoodsLog('option', $arrData['goodsNo'], $getOption, $optionLog);
        }

        // 상품 정보 저장
        $arrBind = $this->db->get_binding(DBTableField::tableGoods_mobileappModify(), $arrData, 'update', null, array());
        $this->db->bind_param_push($arrBind['bind'], 'i', $arrData['goodsNo']);
        $this->db->set_update_db(DB_GOODS, $arrBind['param'], 'goodsNo = ?', $arrBind['bind']);
        unset($arrBind);

        if($arrData['soldOutFl'] == 'y') {
            $arrData['applyFl'] = 'n';
        }
        else {
            $arrData['applyFl'] = 'y';
        }

        $this->setGoodsUpdateEp($arrData['applyFl'], $arrData['goodsNo']);
    }
    /**
     * 상품 옵션 추가노출 정보 저장
     *
     * @param array $arrFileData 저장할 _FILES[optionYIcon] _POST[optionYIcon]
     * @param array $arrData 기존 정보
     * @param array $arrOptionValue 옵션 값 정보
     * @param string $strImageStorage 저장소
     */
    protected function imageUploadIcon($arrFileData, $arrData, $arrOptionValue, $strImageStorage)
    {
        // --- 이미지 종류
        $tmpImage = gd_policy('goods.image');

        if(Request::post()->get('optionImageAddUrl') !='y' && $strImageStorage != 'url') {
            unset( $arrData['goodsImageText']);
        }

        // --- 저장이 아닌 URL로 직접 넣는 경우
        if ($strImageStorage == 'url') {

            // 기존 이미지 정보 삭제
            unset($arrData['iconImage']);
            unset($arrData['goodsImage']);

            // 기존 이미지 정보를 URL 이미지 정보로 대체
            $arrData['iconImage'] = $arrData['iconImageText'];
            $arrData['goodsImage'] = $arrData['goodsImageText'];

            // --- 저장 위치가 로컬 또는 외부인 경우
        } else {


            $iconType = array('icon' => 'iconImage', 'goods' => 'goodsImage');


            foreach ($iconType as $key => $val) {
                if (isset($arrFileData['name']) === false) {
                    continue;
                }
                if ($arrFileData['name'][$val]) {
                    foreach ($arrFileData['name'][$val] as $fKey => $fVal) {
                        foreach ($fVal as $vKey => $vVal) {

                            if (gd_file_uploadable($arrFileData, 'image', $val, $fKey, $vKey) === true) {
                                if ($val == 'iconImage') {
                                    $targetImageSize = GOODS_ICON_SIZE;
                                    $targetPreFix = 'addIcon_';
                                } else {
                                    $targetImageSize = $tmpImage['detail']['size1'];
                                    $targetPreFix = 'addGoods_';
                                }

                                $imageExt = strrchr($vVal, '.');

                                $newImageName = base64_encode($arrOptionValue[$fKey][$vKey]).$imageExt; // 이미지 공백 제거
                                $targetImageFile = $this->imagePath . $newImageName;
                                $thumbnailImageFile = $this->imagePath . $targetPreFix . $newImageName;
                                $tmpImageFile = $arrFileData['tmp_name'][$val][$fKey][$vKey];
                                $tmpInfo['optionNo'][$fKey][$vKey] = $fKey;
                                $tmpInfo['optionValue'][$fKey][$vKey] = $arrOptionValue[$fKey][$vKey];
                                $tmpInfo[$key . 'Image'][$fKey][$vKey] = $targetPreFix . $newImageName;

                                Storage::disk(Storage::PATH_CODE_GOODS, $strImageStorage)->upload($tmpImageFile, $thumbnailImageFile, ['width' => $targetImageSize]);

                                // GD 이용한 썸네일 이미지 저장
                                if ($val == 'goodsImage') {
                                    // GD 이용한 썸네일 이미지 저장
                                    $thumbnailImageFile = $this->imagePath . PREFIX_GOODS_THUMBNAIL . $targetPreFix . $newImageName;
                                    Storage::disk(Storage::PATH_CODE_GOODS, $strImageStorage)->upload($tmpImageFile, $thumbnailImageFile, ['width' => preg_replace('/[^0-9]/', '', PREFIX_GOODS_THUMBNAIL)]);
                                }

                            }

                            if(Request::post()->get('optionImageAddUrl') =='y' && empty($tmpInfo[$key . 'Image'][$fKey][$vKey]) === true && empty($arrData['goodsImage'][$fKey][$vKey]) === true  && empty($arrData['goodsImageText'][$fKey][$vKey]) === false && empty($arrData['optionImageDeleteFl'][$fKey][$vKey]) === true ) {
                                $tmpInfo[$key . 'Image'][$fKey][$vKey]  = $arrData['goodsImageText'][$fKey][$vKey];
                            }

                        }
                    }
                }
            }
        }

        unset($arrData['iconImageText']);
        unset($arrData['goodsImageText']);
        $arrData['optionValue'] = $arrOptionValue;
        $arrField = DBTableField::setTableField('tableGoodsOptionIcon', null, array('goodsNo'));
        $arrField[] = 'sno';

        // 색상표 코드 체크
        foreach ($arrData['optionValue'] as $key => $val) {
            foreach ($val as $cKey => $cVal) {
                foreach ($arrField as $fVal) {
                    if (gd_isset($tmpInfo[$fVal][$key][$cKey])) {
                        $arrData[$fVal][$key][$cKey] = $tmpInfo[$fVal][$key][$cKey];
                    }
                }
                if (!gd_isset($arrData['goodsImage'][$key][$cKey])) {

                    foreach ($arrField as $fVal) {
                        unset($arrData[$fVal][$key][$cKey]);
                    }
                }
            }
        }

        foreach ($arrData['optionValue'] as $key => $val) {
            foreach ($val as $cKey => $cVal) {
                foreach ($arrField as $fVal) {
                    $iconInfo[$fVal][] = gd_isset($arrData[$fVal][$key][$cKey]);
                }
            }
        }
        unset($arrData);



        // 기본 상품의 이미지 정보
        $getImage = $this->getGoodsOptionIcon($this->goodsNo);


        // 기존 이미지 정보와 새로운 이미지 정보를 비교
        $getImageCompare = $this->db->get_compare_array_data($getImage, gd_isset($iconInfo));


        // 공통 키값
        $arrDataKey = array('goodsNo' => $this->goodsNo);

        // 이미지 디비 처리
        $this->db->set_compare_process(DB_GOODS_OPTION_ICON, gd_isset($iconInfo), $arrDataKey, $getImageCompare);
    }

    /**
     * 상품 이미지 저장
     *
     * @param array $arrFileData 저장할 _FILES[image]
     * @param string $strImageStorage 저장소
     * @param array $arrImageSize 이미지 리사이즈 정보
     * @param array $arrImageResize 이미지 리사이즈 사용여부
     * @param array $imageInfo 수정의 경우 기존 이미지 정보
     */
    public function imageUploadGoods($arrFileData, $strImageStorage, $arrImageSize, $arrImageResize, $imageInfo,$goodsNo, $mode = null)
    {

        // --- 이미지 종류
        $tmpImage = gd_policy('goods.image');

        // 설정된 상품 이미지 사이즈가 없는 경우, 넘어온 이미지 사이즈가 없는 경우 리턴
        if (empty($tmpImage) === true || empty($arrImageSize) === true) {
            return;
        }

        // 각 이미지별 사이즈 추출
        foreach ($tmpImage as $key => $val) {
            $image['file'][] = $key;
            $image['addKey'][$key] = $tmpImage[$key]['addKey'];
            // if (gd_isset($arrImageResize[$key]) == 'y') {
            $image['size'][$key] = $arrImageSize[$key];
            // } else {
            // $image['size'][$key] = 0;
            // }
            $image['resize'][$key] = gd_isset($arrImageResize[$key]);
        }

        //썸네일이미지생성관련
        $thumbImageSize = $tmpImage['list']['size1'];
        $thumbImageHeightSize = $tmpImage['list']['hsize1'];

        foreach($imageInfo['imageCode'] as $k => $v) {
            if(is_array($v)) {
                foreach($v as $k1 => $v1) {
                    $imageInfo['imageCode'][$k.$k1] = $v1;
                }
            }
        }

        $storage = Storage::disk(Storage::PATH_CODE_GOODS, $strImageStorage);
        // --- 저장이 아닌 URL로 직접 넣는 경우
        if ($strImageStorage == 'url') {
            foreach ($image['file'] as $val) {
                $i = 0;
                foreach ($arrFileData['imageUrl' . ucfirst($val)] as $fKey => $fVal) {
                    if($fVal) {
                        $size = explode(INT_DIVISION, $image['size'][$val]);
                        $tmpInfo['sno'][] = gd_isset($imageInfo['imageCode'][$val.$i]);
                        $tmpInfo['imageNo'][] = $i;
                        $tmpInfo['imageSize'][] = $size[0];
                        $tmpInfo['imageHeightSize'][] = $size[1];
                        $tmpInfo['imageKind'][] = $val;
                        $tmpInfo['imageName'][] = $fVal;
                        $tmpInfo['imageRealSize'][] = '';
                        $i++;
                    }
                }
            }

            // --- 저장 위치가 로컬 또는 외부인 경우
        } else {

            foreach ($image['file'] as $val) {

                // 이미지 리사이즈 인경우
                if ($image['resize'][$val] == 'y') {
                    $i = 0;
                    if (empty($arrFileData['name']['imageOriginal']) === false) {

                        foreach ($arrFileData['name']['imageOriginal'] as $fKey => $fVal) {
                            if (empty($fVal) || ($image['addKey'][$val] == 'n' && $fKey != 0)) { // 화일이 없거나. addKey가 n 인 경우 imageOriginal 첫번째 이외의 배열
                                continue;
                            }

                            if (gd_file_uploadable($arrFileData, 'image', 'imageOriginal', $fKey) === true) {
                                $size = explode(INT_DIVISION, $image['size'][$val]);
                                $imageExt = strrchr($arrFileData['name']['imageOriginal'][$fKey], '.');
                                //$newImageName = str_replace(' ', '', trim(substr($arrFileData['name']['imageOriginal'][$fKey], 0, -strlen($imageExt)))) . '_' . $val . $imageExt; // 이미지 공백 제거 및 각 복사에 따른 종류를 화일명에 넣기
                                $saveImageName = $goodsNo . '_' . $val .'_'.$i.rand(1,100). $imageExt; // 이미지 공백 제거 및 각 복사에 따른 종류를 화일명에 넣기
                                $targetImageFile = $this->imagePath . $saveImageName;
                                $tmpImageFile = $arrFileData['tmp_name']['imageOriginal'][$fKey];
                                $tmpInfo['sno'][] = gd_isset($imageInfo['imageCode'][$val.$i]);
                                $tmpInfo['imageNo'][] = $i;
                                $tmpInfo['imageSize'][] = $size[0];
                                $tmpInfo['imageHeightSize'][] = $size[1];
                                $tmpInfo['imageKind'][] = $val;
                                $tmpInfo['imageName'][] = $saveImageName;
                                $tmpInfo['imageRealSize'][] = implode(',', array());


                                // GD 이용한 화일 리사이징
                                $storage->upload($tmpImageFile, $targetImageFile, ['width' => $image['size'][$val], 'height' => $size[1]]);

                                // GD 이용한 썸네일 이미지 저장
                                $thumbnailImageFile = $this->imagePath . PREFIX_GOODS_THUMBNAIL . $saveImageName;
                                $storage->upload($tmpImageFile, $thumbnailImageFile, ['width' => $thumbImageSize, 'height' => $thumbImageHeightSize]);
                            }
                            $i++;
                        }
                    }
                    // 이미지 직접 올리는 경우
                } else {

                    $i = 0;
                    $imageNo = 0;

                    if (empty($arrFileData['name']['image' . ucfirst($val)]) === false) {


                        foreach ($arrFileData['name']['image' . ucfirst($val)] as $fKey => $fVal) {

                            if (gd_file_uploadable($arrFileData, 'image', 'image' . ucfirst($val), $fKey) === true) {

                                $size = explode(INT_DIVISION, $image['size'][$val]);
                                $imageExt = strrchr($fVal, '.');
                                //$newImageName = str_replace(' ', '', trim(substr($fVal, 0, -strlen($imageExt)))) . '_' . $val . $imageExt; // 이미지 공백 제거 및 각 복사에 따른 종류를 화일명에 넣기
                                $saveImageName = $goodsNo . '_' . $val .'_'.$i.rand(1,100). $imageExt; // 이미지 공백 제거 및 각 복사에 따른 종류를 화일명에 넣기

                                $targetImageFile = $this->imagePath . $saveImageName;
                                $tmpImageFile = $arrFileData['tmp_name']['image' . ucfirst($val)][$fKey];
                                list($tmpSize['width'], $tmpSize['height']) = getimagesize($tmpImageFile);
                                $tmpInfo['sno'][] = gd_isset($imageInfo['imageCode'][$val.$i]);
                                $tmpInfo['imageNo'][] = $i;
                                $tmpInfo['imageSize'][] = $size[0];
                                $tmpInfo['imageHeightSize'][] = $size[1];
                                $tmpInfo['imageKind'][] = $val;
                                $tmpInfo['imageName'][] = $saveImageName;
                                $tmpInfo['imageRealSize'][] = implode(',', $tmpSize);
                                // 이미지 저장
                                $storage->upload($tmpImageFile, $targetImageFile);

                                // GD 이용한 썸네일 이미지 저장
                                $thumbnailImageFile = $this->imagePath . PREFIX_GOODS_THUMBNAIL . $saveImageName;
                                $storage->upload($tmpImageFile, $thumbnailImageFile, ['width' => $image['size'][$val]]);

                                $imageNo++;
                            } else {

                                if($imageInfo['imageCode'][$val.$i] && empty($imageInfo['imageUrlFl'][$val.$i]) === true && empty($imageInfo['imageDelFl'][$val.$i]) === true) {

                                    $imageIndex = array_search($imageInfo['imageCode'][$val.$i], $imageInfo['sno']);
                                    $tmpInfo['sno'][] = $imageInfo['sno'][$imageIndex];
                                    $tmpInfo['imageNo'][] = $imageNo;
                                    $tmpInfo['imageSize'][] = $imageInfo['imageSize'][$imageIndex];
                                    $tmpInfo['imageHeightSize'][] = gd_isset($imageInfo['imageHeightSize'][$imageIndex],0);
                                    $tmpInfo['imageKind'][] = $imageInfo['imageKind'][$imageIndex];
                                    $tmpInfo['imageName'][] = $imageInfo['imageName'][$imageIndex];
                                    $tmpInfo['imageRealSize'][] = $imageInfo['imageRealSize'][$imageIndex];
                                    $imageNo++;
                                }
                            }

                            $i++;
                        }

                        if(Request::post()->get('imageAddUrl') =='y') {

                            if(Request::post()->get('image')['image'.ucfirst($val)]) {

                                if(!in_array($val,['detail','magnify']) && in_array($val,$tmpInfo['imageKind'])) {
                                    continue;
                                }

                                if(!in_array($val,$tmpInfo['imageKind']) && (!in_array($val,['detail','magnify']) || ($i == 1 && in_array($val,['detail','magnify']) && empty($imageInfo['imageUrlFl'][$val.'0']) === false))) {
                                    $i = 0;
                                }

                                $urlImageTmp = Request::post()->get('image')['image'.ucfirst($val)];


                                foreach ($urlImageTmp as $fKey => $fVal) {
                                    if (strtolower(substr($fVal,0,4)) =='http' && empty($imageInfo['imageUrlDelFl'][$val.$i]) === true) {
                                        $size = explode(INT_DIVISION, $image['size'][$val]);
                                        $tmpInfo['sno'][] =  gd_isset($imageInfo['imageCode'][$val.$i]);
                                        $tmpInfo['imageNo'][] = $imageNo;
                                        $tmpInfo['imageSize'][] = $size[0];
                                        $tmpInfo['imageHeightSize'][] = gd_isset($size[1],0);
                                        $tmpInfo['imageKind'][] = $val;
                                        $tmpInfo['imageName'][] = $fVal;
                                        $tmpInfo['imageRealSize'][] = '';
                                        $imageNo++;
                                    }
                                    $i++;
                                }
                            }
                        }
                    }
                }
            }
        }

        // 기본 상품의 이미지 정보
        $getImage = StringUtils::trimValue($this->getGoodsImage($this->goodsNo));
        if(!$getImage) unset($tmpInfo['sno']);

        // 기존 이미지 정보와 새로운 이미지 정보를 비교
        $getImageCompare = $this->db->get_compare_array_data($getImage, gd_isset($tmpInfo));


        if ($getImage &&  $strImageStorage != 'url' ) {
            foreach ($getImage as $k => $v) {
                if ($getImageCompare[$v['sno']] == 'update' || $getImageCompare[$v['sno']] == 'delete') {
                    if(in_array($v['imageName'],$tmpInfo['imageName']) === false) {
                        $storage->delete($this->imagePath . PREFIX_GOODS_THUMBNAIL . $v['imageName']);
                        $storage->delete($this->imagePath . $v['imageName']);
                    }
                }
            }
        }

        // 공통 키값
        $arrDataKey = array('goodsNo' => $this->goodsNo);

        // 이미지 디비 처리
        $goodsImageLog = $this->db->set_compare_process(DB_GOODS_IMAGE, gd_isset($tmpInfo), $arrDataKey, $getImageCompare);

        //최종 이미지 삭제
        if($strImageStorage == 'local'  && empty($tmpInfo['imageName']) === false && strpos($this->imagePath,  $this->goodsNo) !== false ) {
            $goodsImageDir = UserFilePath::data('goods', $this->imagePath);
            if ($openDir = opendir($goodsImageDir)) {
                while (($goodsImageNm = readdir($openDir)) !== false) {
                    if (!in_array(str_replace(PREFIX_GOODS_THUMBNAIL,'',$goodsImageNm),$tmpInfo['imageName']) && substr(strtolower(str_replace(PREFIX_GOODS_THUMBNAIL,'',$goodsImageNm)),0,3) !='add') {
                        $storage->delete($this->imagePath . PREFIX_GOODS_THUMBNAIL .$goodsImageNm);
                        $storage->delete($this->imagePath . $goodsImageNm);
                    }
                }
            }
        }

        if ($goodsImageLog && $mode == 'modify') {
            $this->setGoodsLog('image', $this->goodsNo, $getImage, $goodsImageLog);
        }

    }

    /**
     * 모바일앱 - 상품 이미지 저장
     *
     * @param array $arrData
     */
    public function mobileapp_imageUploadGoods($arrData)
    {
        if(trim($arrData['mobileapp_imageOriginal']) === ''){
            return false;
        }

        //저장소
        $strImageStorage = $arrData['imageStorage'];
        $arrImageSize = $arrData['imageSize'];
        $arrImageResize = $arrData['imageResize'];
        $imageInfo = $arrData['imageDB'];
        $goodsNo = $arrData['goodsNo'];

        // --- 이미지 종류
        $tmpImage = gd_policy('goods.image');

        // 설정된 상품 이미지 사이즈가 없는 경우, 넘어온 이미지 사이즈가 없는 경우 리턴
        if (empty($tmpImage) === true) {
            return;
        }

        // 각 이미지별 사이즈 추출
        foreach ($tmpImage as $key => $val) {
            $image['file'][] = $key;
            $image['addKey'][$key] = $tmpImage[$key]['addKey'];
            $image['size'][$key] = $arrImageSize[$key];
            $image['resize'][$key] = gd_isset($arrImageResize[$key]);
        }

        //썸네일이미지생성관련
        $thumbImageSize = $tmpImage['list']['size1'];
        $thumbImageHeightSize = $tmpImage['list']['hsize1'];

        foreach($imageInfo['imageCode'] as $k => $v) {
            if(is_array($v)) {
                foreach($v as $k1 => $v1) {
                    $imageInfo['imageCode'][$k.$k1] = $v1;
                }
            }
        }

        $storage = Storage::disk(Storage::PATH_CODE_GOODS, $strImageStorage);
        $tempMobileappPath = Request::server()->get('DOCUMENT_ROOT').'/data/mobileapp/'.$arrData['mobileapp_imageOriginal'];
        foreach ($image['file'] as $val) {
            foreach (glob($tempMobileappPath) as $oldFile) {
                $size = explode(INT_DIVISION, $image['size'][$val]);
                $imageExt = strrchr($oldFile, '.');

                $saveImageName = $goodsNo . '_' . $val . '_' . $i . rand(1, 100) . $imageExt; // 이미지 공백 제거 및 각 복사에 따른 종류를 화일명에 넣기
                $targetImageFile = $this->imagePath . $saveImageName;

                $tmpInfo['sno'][] = gd_isset($imageInfo['imageCode'][$val . $i]);
                $tmpInfo['imageNo'][] = $i;
                $tmpInfo['imageSize'][] = $size[0];
                $tmpInfo['imageHeightSize'][] = $size[1];
                $tmpInfo['imageKind'][] = $val;
                $tmpInfo['imageName'][] = $saveImageName;
                $tmpInfo['imageRealSize'][] = implode(',', array());

                // GD 이용한 화일 리사이징
                $storage->upload($oldFile, $targetImageFile, ['width' => $image['size'][$val], 'height' => $size[1]]);

                // GD 이용한 썸네일 이미지 저장
                $thumbnailImageFile = $this->imagePath . PREFIX_GOODS_THUMBNAIL . $saveImageName;
                $storage->upload($oldFile, $thumbnailImageFile, ['width' => $thumbImageSize, 'height' => $thumbImageHeightSize]);
            }
        }

        // 공통 키값
        $arrDataKey = array('goodsNo' => $this->goodsNo);

        // 이미지 디비 처리
        $this->db->set_compare_process(DB_GOODS_IMAGE, gd_isset($tmpInfo), $arrDataKey, array());

        @unlink($tempMobileappPath);
    }

    /**
     * 모바일앱 - 상품설명 이미지 저장
     *
     * @param string $goodsDescription
     */
    public function mobileapp_imageUploadDescription(&$goodsDescription)
    {
        $device_uid = \Cookie::get('device_uid');
        $tempMobileappPath = Request::server()->get('DOCUMENT_ROOT').'/data/mobileapp/'.$device_uid.'_mobileapp_goodsDescription*';
        $editorPath = PATH_EDITOR_RELATIVE . '/goods/';
        $uploadDir = Request::server()->get('DOCUMENT_ROOT') . $editorPath;
        if (!is_dir($uploadDir)) {
            $old = umask(0);
            mkdir($uploadDir, 0777, true);
            umask($old);
        }
        foreach (glob($tempMobileappPath) as $oldFile) {
            $ext = pathinfo($oldFile, PATHINFO_EXTENSION);
            $newFileName = substr(md5(microtime()), 0, 16) . '_' . $key . '.' . $ext;
            $newFile = $uploadDir . $newFileName;

            if(rename($oldFile, $newFile)){
                @chmod($newFile, 0707);
                if(file_exists($newFile)){
                    $goodsDescription .= '<p><img src="'.$editorPath.$newFileName.'" title="'.$newFileName.'" class="js-smart-img"><br style="clear:both;"></p>';
                    @unlink($oldFile);
                }
            }
        }
    }

    /**
     * 1시간이 지난 임시 이미지 삭제
     */
    public function mobileapp_removeTempImage()
    {
        $maxDate = strtotime('-1 hours');
        $tempImagePath = Request::server()->get('DOCUMENT_ROOT').'/data/mobileapp/';
        foreach (glob($tempImagePath) as $file) {
            $fileDate = filemtime($file);

            if($maxDate > $fileDate){
                @unlink($file);
            }
        }
    }

    /**
     * 상품 복사
     *
     * @param integer $goodsNo 상품번호
     */
    public function setCopyGoods($goodsNo)
    {
        // 새로운 상품 번호
        $newGoodsNo = $this->getNewGoodsno();

        // 이미지 저장소 및 이미지 경로 정보
        $strWhere = 'g.goodsNo = ?';
        $this->db->bind_param_push($this->arrBind, 'i', $goodsNo);
        $this->db->strWhere = $strWhere;
        $data = $this->getGoodsInfo(null, 'g.goodsNm, g.imageStorage, g.imagePath', $this->arrBind);
        $newImagePath = DIR_GOODS_IMAGE . $newGoodsNo . '/';

        // 상품 관련 테이블 복사
        $arrGoodsTable[] = DB_GOODS; // 상품 기본 정보
        $arrGoodsTable[] = DB_GOODS_GLOBAL; // 상품 글로벌 관련 정보
        $arrGoodsTable[] = DB_GOODS_ADD_INFO; // 상품 추가 정보
        $arrGoodsTable[] = DB_GOODS_LINK_CATEGORY; // 상품 카테고리 연결 및 정렬
        $arrGoodsTable[] = DB_GOODS_LINK_BRAND; // 상품 브랜드 연결 및 정렬
        $arrGoodsTable[] = DB_GOODS_OPTION; // 상품 옵션
        $arrGoodsTable[] = DB_GOODS_OPTION_ICON; // 상품 옵션 추가 노출 (칼라코드,아이콘,상품이미지)
        $arrGoodsTable[] = DB_GOODS_OPTION_TEXT; // 상품 텍스트 옵션
        $arrGoodsTable[] = DB_GOODS_IMAGE; // 상품 이미지


        foreach ($arrGoodsTable as $goodsTableNm) {
            // 등록된 필드명 로드
            $tmp = explode('_', $goodsTableNm);
            $functionNm = StringUtils::strToCamel('table_' . preg_replace('/[A-Z]/', '_' . strtolower('\\0'), $tmp[1]));
            if ($functionNm == 'tableGoods') {
                $fieldData = DBTableField::setTableField($functionNm, null, array('goodsNo', 'imagePath', 'regDt', 'modDt'));
                $addField = ',imagePath';
                $addData = ',\'' . $newImagePath . '\'';
            } else {
                $fieldData = DBTableField::setTableField($functionNm, null, array('goodsNo', 'regDt', 'modDt'));
                $addField = '';
                $addData = '';
            }

            $strSQL = 'INSERT INTO ' . $goodsTableNm . ' (regDt,goodsNo, ' . implode(', ', $fieldData) . $addField . ') SELECT now(),\'' . $newGoodsNo . '\',' . implode(', ', $fieldData) . $addData . ' FROM ' . $goodsTableNm . ' WHERE goodsNo = ' . $goodsNo;
            $this->db->query($strSQL);
        }

        $strUpdateSQL = "UPDATE " . DB_GOODS . " SET imagePath = '" . $newImagePath . "' WHERE goodsNo = '" . $newGoodsNo . "' ";
        $this->db->query($strUpdateSQL);

        unset($this->arrBind);


        if (Session::get('manager.isProvider')) {
            $applyFl = $this->setGoodsApplyUpdate($newGoodsNo, 'register');
        }

        // 전체 로그를 저장합니다.
        $addLogData = $goodsNo . ' -> ' . $newGoodsNo . ' 상품 복사' . chr(10);
        LogHandler::wholeLog('goods', null, 'copy', $newGoodsNo, $data['goodsNm'], $addLogData);

        // --- 이미지 복사 처리
        if($data['imageStorage'] !='url') {
            Storage::copy(Storage::PATH_CODE_GOODS, $data['imageStorage'], $data['imagePath'], $data['imageStorage'], $newImagePath);
        }
        return $newGoodsNo;
    }

    /**
     * 상품 삭제
     *
     * @param integer $goodsNo 상품번호
     */
    public function setDeleteGoods($goodsNo)
    {
        // 이미지 저장소 및 이미지 경로 정보
        $strWhere = 'g.goodsNo = ?';
        $this->db->bind_param_push($this->arrBind, 'i', $goodsNo);
        $this->db->strWhere = $strWhere;
        $data = $this->getGoodsInfo(null, 'g.goodsNm, g.imageStorage, g.imagePath, g.goodsDescription', $this->arrBind);

        // 상품 관련 테이블 삭제
        $arrGoodsTable[] = DB_GOODS_ADD_INFO; // 상품 추가 정보
        $arrGoodsTable[] = DB_GOODS_LINK_BRAND; // 상품 브랜드 연결 및 정렬
        $arrGoodsTable[] = DB_GOODS_LINK_CATEGORY; // 상품 카테고리 연결 및 정렬
        $arrGoodsTable[] = DB_GOODS_OPTION; // 상품 옵션
        $arrGoodsTable[] = DB_GOODS_OPTION_ICON; // 상품 옵션 추가 노출 (칼라코드,아이콘,상품이미지)
        $arrGoodsTable[] = DB_GOODS_OPTION_TEXT; // 상품 텍스트 옵션
        $arrGoodsTable[] = DB_GOODS_IMAGE; // 상품 이미지
        $arrGoodsTable[] = DB_GOODS; // 상품 기본 정보

        foreach ($arrGoodsTable as $goodsTableNm) {
            $this->db->set_delete_db($goodsTableNm, 'goodsNo = ?', $this->arrBind);
        }
        unset($this->arrBind);

        // 전체 로그를 저장합니다.
        LogHandler::wholeLog('goods', null, 'delete', $goodsNo, $data['goodsNm']);

        ImageUtils::deleteEditorImg($data['goodsDescription']);
        // --- 이미지 삭제 처리
        //        debug($data['imagePath']);
        //        exit;
        if($data['imageStorage'] =='local' && $data['imagePath']) {
            Storage::disk(Storage::PATH_CODE_GOODS, $data['imageStorage'])->deleteDir($data['imagePath']);
        }
    }


    /**
     * 일괄상품 수정에서 상품 승인 관련 상품 테이블 업데이트
     *
     */
    public function setGoodsApplyUpdate($goodsNo, $mode)
    {
        //공급사이면서 자동승인이 아닌경우 상품 승인신청 처리
        if (Session::get('manager.isProvider') && (($mode == 'modify' && Session::get('manager.scmPermissionModify') == 'c') || ($mode == 'register' && Session::get('manager.scmPermissionInsert') == 'c'))) {
            $applyData['applyFl'] = 'a';
            $applyData['applyDt'] = date('Y-m-d H:i:s');

            $applyData['applyType'] = strtolower(substr($mode, 0, 1));


        } else  $applyData['applyFl'] = 'y';


        $arrBind = $this->db->get_binding(DBTableField::getBindField('tableGoods', array_keys($applyData)), $applyData, 'update');

        if (is_array($goodsNo)) {
            $strWhere = "goodsNo IN ('" . implode("','", $goodsNo) . "')";
            $this->db->set_update_db(DB_GOODS, $arrBind['param'], $strWhere, $arrBind['bind']);
        } else {
            $this->db->bind_param_push($arrBind['bind'], 's', $goodsNo);
            $this->db->set_update_db(DB_GOODS, $arrBind['param'], 'goodsNo = ?', $arrBind['bind']);
        }
        unset($arrBind);

        //네이버관련 업데이트
        if ($mode == 'register' && $applyData['applyFl'] == 'y') {
            $this->setGoodsUpdateEp($applyData['applyFl'], $goodsNo, true);
        } else {
            $this->setGoodsUpdateEp($applyData['applyFl'], $goodsNo);
        }

        return $applyData['applyFl'];
    }

    /**
     * 상품 승인 관련 상품 로그 테이블 업데이트
     * 수정인경우 로그 쌓임
     *
     */
    public function setGoodsLog($mode, $goodsNo, $prevData, $updateData)
    {

        $arrData['mode'] = $mode;
        $arrData['goodsNo'] = $goodsNo;
        $arrData['managerId'] = (string)Session::get('manager.managerId');
        $arrData['managerNo'] = Session::get('manager.sno');
        $arrData['prevData'] = json_encode($prevData, JSON_UNESCAPED_UNICODE);
        $arrData['updateData'] = json_encode($updateData, JSON_UNESCAPED_UNICODE);

        //공급사이면서 자동승인이 아닌경우 상품 승인신청 처리
        if (Session::get('manager.isProvider') && Session::get('manager.scmPermissionModify') == 'c') {
            $arrData['applyFl'] = 'a';
        } else  $arrData['applyFl'] = 'y';


        $arrBind = $this->db->get_binding(DBTableField::tableLogGoods(), $arrData, 'insert');
        $this->db->set_insert_db(DB_LOG_GOODS, $arrBind['param'], $arrBind['bind'], 'y');

        unset($arrData);

    }


    /**
     * 상품 네이버 업데이트
     * @param $applyFl
     * @param $goodsNo
     * @param bool $registerFl
     * @return bool
     */
    public function setGoodsUpdateEp($applyFl, $goodsNo, $registerFl = false)
    {
        if ($this->naverConfig['naverFl'] == 'y' || $this->daumConfig['useFl'] == 'y') {

            if (empty($registerFl)) {
                if ($applyFl == 'y') {
                    $arrData['class'] = 'U';
                }
                else {
                    $arrData['class'] = 'D';
                }
            } else {
                $arrData['class'] = 'I';
            }
            if (is_array($goodsNo)) {
                $arrGoodsNo = $goodsNo;
            } else {
                $arrGoodsNo = array($goodsNo);
            }
            foreach ($arrGoodsNo as $k => $v) {

                $arrData['mapid'] = $v;

                $arrBind = [];
                $strSQL = "SELECT sno FROM " . DB_GOODS_UPDATET_NAVER . " WHERE  mapid = ? ";
                $this->db->bind_param_push($arrBind, 's', $v);
                $tmp = $this->db->query_fetch($strSQL, $arrBind, false);
                unset($arrBind);

                if (empty($registerFl) && count($tmp) == 0 || $registerFl) { //신규상품이면
                    $arrBind = $this->db->get_binding(DBTableField::tableGoodsUpdateNaver(), $arrData, 'insert', array_keys($arrData));
                    $this->db->set_insert_db(DB_GOODS_UPDATET_NAVER, $arrBind['param'], $arrBind['bind'], 'y');
                    unset($arrData);
                    unset($arrBind);
                } else {
                    if (is_array($tmp) &&  count($tmp) > 1) {  //중복된 상품번호 삭제
                        for($i=1;$i<count($tmp);$i++){
                            $arrBind=[];
                            $this->db->bind_param_push($arrBind, 'i', $tmp[$i]['sno']);
                            $this->db->set_delete_db(DB_GOODS_UPDATET_NAVER, ' sno=?', $arrBind);
                        }
                    }
                    $arrBind = $this->db->get_binding(DBTableField::tableGoodsUpdateNaver(), $arrData, 'update', array_keys($arrData));
                    $this->db->bind_param_push($arrBind['bind'], 'i', $tmp['sno']);
                    $this->db->set_update_db(DB_GOODS_UPDATET_NAVER, $arrBind['param'], 'sno = ?', $arrBind['bind']);
                    unset($arrBind);
                }
            }

        } else {
            return true;
        }


    }

    public function getAdminListGoodsLog($goodsNo)
    {
        $arrField = DBTableField::setTableField('tableLogGoods');

        $strWhere = 'goodsNo = ?';
        $this->db->bind_param_push($arrBind, 's', $goodsNo);

        $strSQL = 'SELECT sno,regDt, ' . implode(', ', $arrField) . ' FROM ' . DB_LOG_GOODS . ' WHERE ' . $strWhere . ' ORDER BY sno DESC';
        $getData = $this->db->query_fetch($strSQL, $arrBind);

        if ($getData) {
            foreach ($getData as $key => $val) {
                switch ($val['mode']) {
                    case 'category':
                        $tmp = [];
                        $tmpStr = [];
                        $cate = \App::load('\\Component\\Category\\CategoryAdmin');
                        $prevData = json_decode(gd_htmlspecialchars_stripslashes($val['prevData']), true);
                        if ($prevData) {
                            foreach ($prevData as $k => $v) {
                                if ($v['cateLinkFl'] == 'y') $tmp[$v['sno']] = $cate->getCategoryPosition($v['cateCd']);
                            }

                            $getData[$key]['prevData'] = $tmp;
                            $getData[$key]['prevDataSet'] = implode("<br/>", $tmp);
                            unset($tmp);
                        }

                        $updateData = json_decode(gd_htmlspecialchars_stripslashes($val['updateData']), true);
                        if ($updateData) {
                            foreach ($updateData as $catemode => $cateinfo) {

                                foreach ($cateinfo as $k => $v) {
                                    if ($catemode == 'delete') {
                                        $tmp[$catemode][] = $getData[$key]['prevData'][$v];
                                    } else {
                                        if ($v['cateLinkFl'] == 'y') $tmp[$catemode][] = $cate->getCategoryPosition($v['cateCd']);
                                    }
                                }
                                $tmpStr[] = "[" . $catemode . "]:<br/>" . implode("<br/>", $tmp[$catemode]) . "<br/>";

                            }

                            $getData[$key]['updateData'] = $tmp;
                            $getData[$key]['updateDataSet'] = implode("<br/>", $tmpStr);
                        }

                        break;

                    case 'goods':

                        $goodsField = DBTableField::getFieldNames('tableGoods');

                        $prevTmpStr = [];
                        $updateTmpStr = [];
                        $prevData = json_decode($val['prevData'], true);
                        $getData[$key]['prevData'] = $prevData;

                        $updateData = json_decode($val['updateData'], true);
                        $getData[$key]['updateData'] = $updateData;

                        if ($updateData) {
                            foreach ($updateData as $k => $v) {
                                $prevTmpStr[] = $goodsField[$k] . " : " . $prevData[$k];
                                $updateTmpStr[] = $goodsField[$k] . " : " . $v;
                            }
                        }
                        $getData[$key]['prevDataSet'] = implode("<br/>", $prevTmpStr);
                        $getData[$key]['updateDataSet'] = implode("<br/>", $updateTmpStr);

                        break;
                    default  :

                        $tmp = [];
                        $tmpStr = [];
                        $prevData = json_decode(gd_htmlspecialchars_stripslashes($val['prevData']), true);

                        $imageField = array('magnify' => __('확대 이미지'), 'detail' => __('상세 이미지'), 'list' => __('리스트 이미지'), 'main' => __('리스트 이미지'), 'add1' => __('추가이미지'), 'add2' => __('추가이미지'), 'add3' => __('추가이미지'), 'add4' => __('추가이미지'), 'add5' => __('추가이미지'));


                        if ($prevData) {
                            foreach ($prevData as $k => $v) {
                                $tmp[$v['sno']] = $v;

                                if ($val['mode'] == 'addInfo') {
                                    $tmpStr[] = $v['infoTitle'] . ":" . $v['infoValue'];
                                } else if ($val['mode'] == 'option') {
                                    $optionName = [];
                                    for ($i = 1; $i <= 5; $i++) {
                                        if ($v['optionValue' . $i]) $optionName[] = $v['optionValue' . $i];
                                    }
                                    if ($optionName) $tmpStr[] = implode(",", $optionName) . " : " . $v['optionPrice'] . " / " . $v['stockCnt'] . "개";
                                    else $tmpStr[] = "옵션 상태 변경" . $v['optionViewFl'] . " / " . $v['optionSellFl'] . " / " . $v['stockCnt'] . "개";
                                } else if ($val['mode'] == 'optionText') {

                                    $tmpStr[] = $v['optionName'] . " : " . $v['addPrice'] . " / " . $v['inputLimit'] . "자 제한";
                                } else if ($val['mode'] == 'image') {
                                    $tmpStr[] = $imageField[$v['imageKind']] . " : " . $v['imageName'] . " / " . $v['imageSize'] . "size";
                                }
                            }

                            $getData[$key]['prevData'] = $tmp;
                            $getData[$key]['prevDataSet'] = implode("<br/>", $tmpStr);


                            unset($tmp);
                            unset($tmpStr);
                        }


                        $updateData = json_decode(gd_htmlspecialchars_stripslashes($val['updateData']), true);

                        if ($updateData) {
                            foreach ($updateData as $addinfomode => $addinfo) {
                                $tmpData = [];
                                foreach ($addinfo as $k => $v) {
                                    if ($addinfomode == 'delete') {
                                        $v = $getData[$key]['prevData'][$v];
                                    }

                                    $tmp[$addinfomode][] = $v;

                                    if ($val['mode'] == 'addInfo') {
                                        $tmpData[] = $v['infoTitle'] . ":" . $v['infoValue'];
                                    } else if ($val['mode'] == 'option') {
                                        $optionName = [];
                                        for ($i = 1; $i <= 5; $i++) {
                                            if ($v['optionValue' . $i]) $optionName[] = $v['optionValue' . $i];
                                        }
                                        if ($optionName) $tmpData[] = implode(",", $optionName) . " : " . $v['optionPrice'] . " / " . $v['stockCnt'] . "개";
                                        else $tmpData[] = "옵션 상태 변경" . $v['optionViewFl'] . " / " . $v['optionSellFl'] . " / " . $v['stockCnt'] . "개";
                                    } else if ($val['mode'] == 'optionText') {
                                        $tmpData[] = $v['optionName'] . " : " . $v['addPrice'] . " / " . $v['inputLimit'] . "자 제한";
                                    } else if ($val['mode'] == 'image') {
                                        $tmpData[] = $imageField[$v['imageKind']] . " : " . $v['imageName'] . " / " . $v['imageSize'] . "size";
                                    }

                                }

                                $tmpStr[] = "[" . $addinfomode . "]:<br/>" . implode("<br/>", $tmpData) . "<br/>";

                            }

                            $getData[$key]['updateData'] = $tmp;
                            $getData[$key]['updateDataSet'] = implode("<br/>", $tmpStr);

                        }

                        break;

                }
            }

        }

        return $getData;

    }

    /**
     * setDelStateGoods
     *
     * @param $goodsNo
     */
    public function setDelStateGoods($goodsNo)
    {
        if (Session::get('manager.functionAuthState') == 'check' && Session::get('manager.functionAuth.goodsDelete') != 'y') {
            throw new Exception(__('권한이 없습니다. 권한은 대표운영자에게 문의하시기 바랍니다.'));
        }

        if (Session::get('manager.isProvider') && Session::get('manager.scmPermissionDelete') == 'c') {
            $applyFl = "a";
            $strWhere = "goodsNo IN ('" . implode("','", $goodsNo) . "')";
            $this->db->set_update_db(DB_GOODS, array("applyFl = '".$applyFl."' , applyType = 'd'"), $strWhere);
        } else {
            $applyFl = "y";
            $strWhere = "goodsNo IN ('" . implode("','", $goodsNo) . "')";
            $this->db->set_update_db(DB_GOODS, array("delFl = 'y',delDt = '" . date('Y-m-d H:i:s') . "'"), $strWhere);
        }

        //네이버쇼핑사용인경우
        //   if ($this->naverConfig['naverFl'] == 'y') {

        $this->setGoodsUpdateEp("n", $goodsNo);

        return $applyFl;

        // }

    }

    /**
     * setSoldOutGoods
     *
     * @param $goodsNo
     */
    public function setSoldOutGoods($goodsNo)
    {

        foreach ($goodsNo as $k => $v) {
            $getGoods = $this->getGoodsInfo($v, 'soldOutFl'); // 상품정보

            if ($getGoods['soldOutFl'] == 'n') {

                $arrBind = [];
                $arrUpdate[] = "soldOutFl = 'y'";
                $this->db->bind_param_push($arrBind, 's', $v);
                $this->db->set_update_db(DB_GOODS, $arrUpdate, 'goodsNo = ?', $arrBind);
                $this->setGoodsLog("goods", $v, array("soldOutFl" => $getGoods['soldOutFl']), array("soldOutFl" => "y"));
                $updateGoodsNo[] = $v;
            }
        }

        $applyFl = $this->setGoodsApplyUpdate($updateGoodsNo, 'modify');

        return $applyFl;

    }


    /**
     * 상품승인
     *
     * @param $goodsNo
     */
    public function setApplyGoods($goodsNo, $mode = null)
    {

        $arrBind = [];
        $arrUpdate[] = "applyFl = 'y'";
        if ($mode == 'd') $arrUpdate[] = "delFl = 'y'";
        $this->db->bind_param_push($arrBind, 's', $goodsNo);
        $this->db->set_update_db(DB_GOODS, $arrUpdate, 'goodsNo = ?', $arrBind);

        if ($mode != 'd') {

            $arrBind = [];
            $arrUpdate[] = "applyFl = 'y'";
            $this->db->bind_param_push($arrBind, 's', $goodsNo);
            $this->db->bind_param_push($arrBind, 's', 'a');
            $this->db->set_update_db(DB_LOG_GOODS, $arrUpdate, 'goodsNo = ? and applyFl = ?', $arrBind);

        }

    }


    /**
     * 상품반려
     *
     * @param $goodsNo
     */
    public function setApplyRejectGoods($goodsNo, $applyMsg)
    {
        $strWhere = "goodsNo IN ('" . implode("','", $goodsNo) . "')";
        $this->db->set_update_db(DB_GOODS, array("applyFl = 'r' ,applyMsg = '" . $applyMsg . "'"), $strWhere);

        $strWhere = "goodsNo IN ('" . implode("','", $goodsNo) . "') AND applyFl = 'a'";
        $this->db->set_update_db(DB_LOG_GOODS, array("applyFl = 'r'"), $strWhere);
    }


    /**
     * 상품철회
     *
     * @param $goodsNo
     */
    public function setApplyWithdrawGoods($goodsNo)
    {
        $strWhere = "goodsNo IN ('" . implode("','", $goodsNo) . "')";
        $this->db->set_update_db(DB_GOODS, array("applyFl = 'n'"), $strWhere);

        $strWhere = "goodsNo IN ('" . implode("','", $goodsNo) . "') AND applyFl = 'a'";
        $this->db->set_update_db(DB_LOG_GOODS, array("applyFl = 'n'"), $strWhere);
    }


    /**
     * 자주쓰는 상품 옵션의 등록 및 수정에 관련된 정보
     *
     * @param integer $dataSno 수정의 경우 레코드 sno
     * @return array 자주쓰는 상품 옵션 정보
     */
    public function getDataManageOption($dataSno = null)
    {
        // --- 등록인 경우
        if (is_null($dataSno)) {
            // 기본 정보
            $data['mode'] = 'option_register';
            $data['sno'] = null;
            $data['optionCnt'] = 0;

            // 기본값 설정
            DBTableField::setDefaultData('tableManageGoodsOption', $data);

            // --- 수정인 경우
        } else {
            $this->db->bind_param_push($this->arrBind, 'i', $dataSno);

            $this->db->strField = 'sno, ' . implode(', ', DBTableField::setTableField('tableManageGoodsOption'));
            $this->db->strWhere = 'sno = ?';

            $query = $this->db->query_complete();
            $strSQL = 'SELECT ' . array_shift($query) . ' FROM ' . DB_MANAGE_GOODS_OPTION . implode(' ', $query);
            $data = $this->db->query_fetch($strSQL, $this->arrBind, false);

            if (Session::get('manager.isProvider')) {
                if($data['scmNo'] != Session::get('manager.scmNo')) {
                    throw new AlertBackException(__("타 공급사의 자료는 열람하실 수 없습니다."));
                }
            }


            // 기본값 설정
            DBTableField::setDefaultData('tableManageGoodsOption', $data);

            // 기본 정보
            $data['mode'] = 'option_modify';
            $data['optionName'] = explode(STR_DIVISION, $data['optionName']);
            $data['optionCnt'] = count($data['optionName']);
        }

        // --- 기본값 설정
        gd_isset($data['stockFl'], 'n');

        if ($data['scmNo'] == DEFAULT_CODE_SCMNO) {
            $data['scmFl'] = "n";
        } else {
            $data['scmFl'] = "y";
        }


        $checked = [];
        $checked['scmFl'][$data['scmFl']] = $checked['optionDisplayFl'][$data['optionDisplayFl']] = 'checked="checked"';

        $getData['data'] = gd_htmlspecialchars_stripslashes($data);
        $getData['checked'] = $checked;

        return $getData;
    }

    /**
     * 자주쓰는 상품 옵션 관리 저장
     *
     * @param array $arrData 저장할 정보의 배열
     */
    public function saveInfoManageOption($arrData)
    {
        // 옵션 관리 명 체크
        if (Validator::required(gd_isset($arrData['optionManageNm'])) === false) {
            throw new \Exception(__('옵션 관리 명은 필수 항목 입니다.'), 500);
        }

        // 테그 제거
        $arrData['optionManageNm'] = strip_tags($arrData['optionManageNm']);

        // 옵션 사용여부에 따른 재정의 및 배열 삭제
        if (gd_isset($arrData['optionName'])) {
            if (is_array($arrData['optionName'])) {
                $arrData['optionName'] = implode(STR_DIVISION, $arrData['optionName']);
            }
        } else {
            throw new \Exception(sprintf(self::TEXT_REQUIRE_VALUE, '옵션명'), 500);
        }
        if (gd_isset($arrData['optionValue'])) {
            foreach ($arrData['optionValue'] as $key => $val) {
                if ($val == '[object Window]') { // 상품 상세에서 바로 등록시
                    unset($arrData['optionValue'][$key]);
                    continue;
                }
                if (is_array($arrData['optionValue'][$key])) {
                    $arrData['optionValue'][$key] = implode(STR_DIVISION, $arrData['optionValue'][$key]);
                }
            }
        } else {
            throw new \Exception(__('옵션값은 필수 항목입니다.'), 500);
        }
        unset($arrData['optionCnt']);

        // insert , update 체크
        if ($arrData['mode'] == 'option_modify') {
            $chkType = 'update';
        } else {
            $chkType = 'insert';
        }

        // 옵션 관리 정보
        $i = 1;
        foreach ($arrData['optionValue'] as $key => $val) {
            $arrData['optionValue' . $i] = $val;
            $i++;
        }
        unset($arrData['optionValue']);

        // 옵션 관리 정보 저장
        if (in_array($chkType, array('insert', 'update'))) {
            $arrBind = $this->db->get_binding(DBTableField::tableManageGoodsOption(), $arrData, $chkType);
            if ($chkType == 'insert') {
                $this->db->set_insert_db(DB_MANAGE_GOODS_OPTION, $arrBind['param'], $arrBind['bind'], 'y');
            }
            if ($chkType == 'update') {
                $this->db->bind_param_push($arrBind['bind'], 'i', $arrData['sno']);
                $this->db->set_update_db(DB_MANAGE_GOODS_OPTION, $arrBind['param'], 'sno = ?', $arrBind['bind']);
            }
            unset($arrBind);
        }
    }

    /**
     * 자주쓰는 상품 옵션 관리 복사
     *
     * @param integer $dataSno 복사할 레코드 sno
     */
    public function setCopyManageOption($dataSno)
    {
        // 옵션 관리 정보 복사
        $arrField = DBTableField::setTableField('tableManageGoodsOption');
        $strSQL = 'INSERT INTO ' . DB_MANAGE_GOODS_OPTION . ' (' . implode(', ', $arrField) . ', regDt) SELECT ' . implode(', ', $arrField) . ', now() FROM ' . DB_MANAGE_GOODS_OPTION . ' WHERE sno = ' . $dataSno;
        $this->db->query($strSQL);
    }

    /**
     * 자주쓰는 상품 옵션 관리 삭제
     *
     * @param integer $dataSno 삭제할 레코드 sno
     */
    public function setDeleteManageOption($dataSno)
    {
        // 옵션 관리 정보 삭제
        $this->db->bind_param_push($arrBind, 'i', $dataSno);
        $this->db->set_delete_db(DB_MANAGE_GOODS_OPTION, 'sno = ?', $arrBind);
    }

    /**
     * 상품 아이콘의 등록 및 수정에 관련된 정보
     *
     * @param integer $iconSno 상품 아이콘 sno
     * @return array 상품 아이콘 정보
     */
    public function getDataManageGoodsIcon($iconSno = null)
    {
        // --- 등록인 경우
        if (is_null($iconSno)) {
            // 기본 정보
            $data['mode'] = 'icon_register';
            $data['sno'] = null;

            // 기본값 설정
            DBTableField::setDefaultData('tableManageGoodsIcon', $data);

            // --- 수정인 경우
        } else {
            $this->db->strWhere = 'sno = ?';
            $this->db->bind_param_push($this->arrBind, 'i', $iconSno);
            $tmp = $this->getManageGoodsIconInfo($iconSno);
            $data = $tmp[0];
            $data['mode'] = 'icon_modify';

            // 기본값 설정
            DBTableField::setDefaultData('tableManageGoodsIcon', $data);
        }

        $checked = array();
        $checked['iconPeriodFl'][$data['iconPeriodFl']] = $checked['iconUseFl'][$data['iconUseFl']] = 'checked="checked"';

        $getData['data'] = $data;
        $getData['checked'] = $checked;

        return $getData;
    }


    /**
     * 상품 아이콘 정보 저장
     *
     * @param array $arrData 저장할 정보의 배열
     */
    public function saveInfoManageGoodsIcon($arrData)
    {
        // 아이콘명 체크
        if (gd_isset($arrData['iconNm']) === null || gd_isset($arrData['iconNm']) == '') {
            throw new \Exception(__('아이콘 이름은 필수 항목입니다.'), 500);
        } else {
            if (gd_is_html($arrData['iconNm']) === true) {
                throw new \Exception(__('아이콘 이름에 태크는 사용할 수 없습니다.'), 500);
            }
        }

        // iconCd 처리
        if ($arrData['mode'] == 'icon_register') {
            $this->db->strField = 'if(max(iconCd) IS NOT NULL, max(iconCd), \'icon0000\') as maxCd';
            $maxCd = $this->getManageGoodsIconInfo();
            $arrData['iconCd'] = 'icon' . sprintf('%04d', ((int)str_replace('icon', '', $maxCd[0]['maxCd']) + 1));
        } else {
            // iconCd 체크
            if (gd_isset($arrData['iconCd']) === null) {
                throw new \Exception(__('상품 아이콘 코드는 필수 항목입니다.'), 500);
            }
            // 아이콘 sno 체크
            if (gd_isset($arrData['sno']) === null) {
                throw new \Exception(__('아이콘 번호는 필수 항목입니다.'), 500);
            }
        }

        // 아이콘 이미지 처리
        $iconImage = Request::files()->get('iconImage');
        if (gd_file_uploadable($iconImage, 'image') === true) {
            // 이미지 업로드
            $imageExt = strrchr($iconImage['name'], '.');
            $arrData['iconImage'] = str_replace(' ', '', trim(substr($iconImage['name'], 0, -strlen($imageExt)))) . $imageExt; // 이미지명 공백 제거
            $targetImageFile = $arrData['iconImage'];
            $tmpImageFile = $iconImage['tmp_name'];

            Storage::disk(Storage::PATH_CODE_GOODS_ICON, 'local')->upload($tmpImageFile, $targetImageFile);
        } else {
            if (empty($arrData['iconImageTemp'])) {
                throw new \Exception(__('아이콘 이미지는 필수 항목입니다.'), 500);
            }
            $arrData['iconImage'] = $arrData['iconImageTemp'];
        }

        // 공통 키값
        $arrDataKey = array('iconCd' => $arrData['iconCd']);

        // 상품 아이콘의 기존 정보
        $getIcon = array();
        if ($arrData['mode'] == 'icon_modify') {
            $this->db->strWhere = 'sno = ? AND iconCd = ?';
            $this->db->bind_param_push($this->arrBind, 'i', $arrData['sno']);
            $this->db->bind_param_push($this->arrBind, 's', $arrData['iconCd']);
            $getIcon = $this->getManageGoodsIconInfo();
        }

        // 상품 아이콘 정보 저장
        foreach ($arrData as $key => $val) {
            $tmpData[$key][] = $val;
        }

        $compareIcon = $this->db->get_compare_array_data($getIcon, $tmpData, false);
        $this->db->set_compare_process(DB_MANAGE_GOODS_ICON, $tmpData, $arrDataKey, $compareIcon);
    }

    /**
     * 기타 아이콘 정보 저장 (마일리지, 품절)
     *
     * @param array $arrData 저장할 정보의 배열
     */
    public function saveInfoManageEtcIcon($arrData)
    {
        $iconImage = Request::files()->get('iconImage');
        // 아이콘 이미지 처리
        if (gd_file_uploadable($iconImage, 'image') === true) {
            // 이미지 업로드
            $tmpImageFile = $iconImage['tmp_name'];
            $targetImageFile = 'icon_' . $arrData['iconType'] . '.gif';
            //DataFileFactory::create('local')->move('icon', $tmpImageFile, $targetImageFile, true);
            Storage::disk(Storage::PATH_CODE_GOODS_ICON, 'local')->upload($tmpImageFile, $targetImageFile);

        }
    }

    /**
     * 상품 아이콘 삭제
     *
     * @param integer $dataSno 삭제할 레코드 sno
     * @return array 상품 아이콘 정보
     */
    public function setDeleteManageGoodsIcon($dataSno)
    {
        // 이미지 이름 가지고 오기
        $strWhere = 'sno = ?';
        $this->db->bind_param_push($this->arrBind, 'i', $dataSno);

        $this->db->strField = 'iconCd, iconNm, iconImage';
        $this->db->strWhere = $strWhere;
        $data = $this->getManageGoodsIconInfo();


        if (!empty($data[0]['iconImage'])) {
            Storage::disk(Storage::PATH_CODE_GOODS_ICON, 'local')->delete($data[0]['iconImage']);
        }

        // 옵션 관리 정보 삭제
        $this->db->set_delete_db(DB_MANAGE_GOODS_ICON, $strWhere, $this->arrBind);
        unset($this->arrBind);

        // 전체 로그를 저장합니다.
        LogHandler::wholeLog('icon', null, 'delete', $data[0]['iconCd'], $data[0]['iconNm']);
    }

    /**
     * 관리자 상품 리스트를 위한 검색 정보
     */
    public function setSearchGoods($getValue = null)
    {
        if (is_null($getValue)) $getValue = Request::get()->toArray();

        // 통합 검색
        /* @formatter:off */
        $this->search['combineSearch'] = [
            'all' => __('=통합검색='),
            'goodsNm' => __('상품명'),
            'goodsNo' => __('상품코드'),
            'goodsCd' => __('자체상품코드'),
            'makerNm' => __('제조사'),
            'originNm' => __('원산지'),
            'goodsSearchWord' => __('검색 키워드'),
            'goodsModelNo' => __('모델번호')
        ];
        /* @formatter:on */

        if(gd_is_provider() === false) {
            $this->search['combineSearch']['companyNm'] = __('공급사명');
            if(gd_is_plus_shop(PLUSSHOP_CODE_PURCHASE) === true ) $this->search['combineSearch']['purchaseNm'] = __('매입처명');
        }

        // 검색을 위한 bind 정보
        $fieldTypeGoods = DBTableField::getFieldTypes('tableGoods');
        $fieldTypeOption = DBTableField::getFieldTypes('tableGoodsOption');
        $fieldTypeLink = DBTableField::getFieldTypes('tableGoodsLinkCategory');

        //검색설정
        $this->search['sortList'] = array(
            'g.goodsNo desc' => __('등록일 ↓'),
            'g.goodsNo asc' => __('등록일 ↑'),
            'g.delDt asc' => __('삭제일 ↓'),
            'g.delDt desc' => __('삭제일 ↑'),
            'goodsNm asc' => __('상품명 ↓'),
            'goodsNm desc' => __('상품명 ↑'),
            'goodsPrice asc' => __('판매가 ↓'),
            'goodsPrice desc' => __('판매가 ↑'),
            'companyNm asc' => __('공급사 ↓'),
            'companyNm desc' => __('공급사 ↑'),
            'makerNm asc' => __('제조사 ↓'),
            'makerNm desc' => __('제조사 ↑')
        );

        // --- 검색 설정
        $this->search['sort'] = gd_isset($getValue['sort'], 'g.goodsNo desc');
        $this->search['detailSearch'] = gd_isset($getValue['detailSearch']);
        $this->search['key'] = gd_isset($getValue['key']);
        $this->search['keyword'] = gd_isset($getValue['keyword']);
        $this->search['cateGoods'] = ArrayUtils::last(gd_isset($getValue['cateGoods']));
        $this->search['brand'] =ArrayUtils::last(gd_isset($getValue['brand']));
        $this->search['brandCd'] = gd_isset($getValue['brandCd']);
        $this->search['brandCdNm'] = gd_isset($getValue['brandCdNm']);
        $this->search['purchaseNo'] = gd_isset($getValue['purchaseNo']);
        $this->search['purchaseNoNm'] = gd_isset($getValue['purchaseNoNm']);
        $this->search['goodsPrice'][] = gd_isset($getValue['goodsPrice'][0]);
        $this->search['goodsPrice'][] = gd_isset($getValue['goodsPrice'][1]);
        $this->search['mileage'][] = gd_isset($getValue['mileage'][0]);
        $this->search['mileage'][] = gd_isset($getValue['mileage'][1]);
        $this->search['optionFl'] = gd_isset($getValue['optionFl']);
        $this->search['mileageFl'] = gd_isset($getValue['mileageFl']);
        $this->search['optionTextFl'] = gd_isset($getValue['optionTextFl']);
        $this->search['goodsDisplayFl'] = gd_isset($getValue['goodsDisplayFl']);
        $this->search['goodsSellFl'] = gd_isset($getValue['goodsSellFl']);
        $this->search['stockFl'] = gd_isset($getValue['stockFl']);
        $this->search['stock'] = gd_isset($getValue['stock']);
        $this->search['stockStateFl'] = gd_isset($getValue['stockStateFl'],'all');
        $this->search['soldOut'] = gd_isset($getValue['soldOut']);
        $this->search['goodsIconCdPeriod'] = gd_isset($getValue['goodsIconCdPeriod']);
        $this->search['goodsIconCd'] = gd_isset($getValue['goodsIconCd']);
        $this->search['goodsColor'] = gd_isset($getValue['goodsColor']);
        $this->search['deliveryFl'] = gd_isset($getValue['deliveryFl']);
        $this->search['deliveryFree'] = gd_isset($getValue['deliveryFree']);

        $this->search['goodsDisplayMobileFl'] = gd_isset($getValue['goodsDisplayMobileFl']);
        $this->search['goodsSellMobileFl'] = gd_isset($getValue['goodsSellMobileFl']);
        $this->search['mobileDescriptionFl'] = gd_isset($getValue['mobileDescriptionFl']);
        $this->search['delFl'] = gd_isset($getValue['delFl'], 'n');

        $this->search['addGoodsFl'] = gd_isset($getValue['addGoodsFl']);
        $this->search['categoryNoneFl'] = gd_isset($getValue['categoryNoneFl']);
        $this->search['brandNoneFl'] = gd_isset($getValue['brandNoneFl']);
        $this->search['purchaseNoneFl'] = gd_isset($getValue['purchaseNoneFl']);

        $this->search['goodsDeliveryFl'] = gd_isset($getValue['goodsDeliveryFl']);
        $this->search['goodsDeliveryFixFl'] = gd_isset($getValue['goodsDeliveryFixFl'], array('all'));

        $this->search['scmFl'] = gd_isset($getValue['scmFl'], Session::get('manager.isProvider') ? 'y' : 'all');
        if($this->search['scmFl'] =='y' && !isset($getValue['scmNo'])  && !Session::get('manager.isProvider')  )  $this->search['scmFl'] = "all";
        $this->search['scmNo'] = gd_isset($getValue['scmNo'], (string)Session::get('manager.scmNo'));
        $this->search['scmNoNm'] = gd_isset($getValue['scmNoNm']);
        $this->search['searchPeriod'] = gd_isset($getValue['searchPeriod'], '-1');
        $this->search['searchDateFl'] = gd_isset($getValue['searchDateFl'], 'regDt');

        if ($this->search['searchPeriod'] < 0) {
            $this->search['searchDate'][] = gd_isset($getValue['searchDate'][0]);
            $this->search['searchDate'][] = gd_isset($getValue['searchDate'][1]);
        } else {
            $this->search['searchDate'][] = gd_isset($getValue['searchDate'][0], date('Y-m-d', strtotime('-7 day')));
            $this->search['searchDate'][] = gd_isset($getValue['searchDate'][1], date('Y-m-d'));
        }


        $this->search['applyType'] = gd_isset($getValue['applyType'], 'all');
        $this->search['applyFl'] = gd_isset($getValue['applyFl'], 'all');
        $this->search['naverFl'] = gd_isset($getValue['naverFl']);
        $this->search['eventThemeSno'] = gd_isset($getValue['eventThemeSno']);
        $this->search['event_text'] = gd_isset($getValue['event_text']);
        $this->search['eventGroup'] = gd_isset($getValue['eventGroup']);
        $this->search['eventGroupSelectList'] = gd_isset($getValue['eventGroupSelectList']);

        $this->checked['naverFl'][$this->search['naverFl']]  = $this->checked['purchaseNoneFl'][$this->search['purchaseNoneFl']]  = $this->checked['stockStateFl'][$this->search['stockStateFl']] = $this->checked['addGoodsFl'][$this->search['addGoodsFl']] = $this->checked['applyType'][$this->search['applyType']] = $this->checked['applyFl'][$this->search['applyFl']] = $this->checked['goodsDeliveryFl'][$this->search['goodsDeliveryFl']] = $this->checked['categoryNoneFl'][$this->search['categoryNoneFl']] = $this->checked['brandNoneFl'][$this->search['brandNoneFl']] = $this->checked['scmFl'][$this->search['scmFl']] = $this->checked['optionFl'][$this->search['optionFl']] = $this->checked['mileageFl'][$this->search['mileageFl']] = $this->checked['optionTextFl'][$this->search['optionTextFl']] = $this->checked['goodsDisplayFl'][$this->search['goodsDisplayFl']] = $this->checked['goodsSellFl'][$this->search['goodsSellFl']] = $this->checked['stockFl'][$this->search['stockFl']] = $this->checked['soldOut'][$this->search['soldOut']] = $this->checked['goodsIconCdPeriod'][$this->search['goodsIconCdPeriod']] = $this->checked['goodsIconCd'][$this->search['goodsIconCd']] = $this->checked['deliveryFl'][$this->search['deliveryFl']] = $this->checked['deliveryFree'][$this->search['deliveryFree']] = $this->checked['goodsDisplayMobileFl'][$this->search['goodsDisplayMobileFl']] = $this->checked['goodsSellMobileFl'][$this->search['goodsSellMobileFl']] = $this->checked['mobileDescriptionFl'][$this->search['mobileDescriptionFl']] = 'checked="checked"';

        foreach ($this->search['goodsDeliveryFixFl'] as $k => $v) {
            $this->checked['goodsDeliveryFixFl'][$v] = 'checked="checked"';
        }

        $this->checked['searchPeriod'][$this->search['searchPeriod']] = "active";

        $this->selected['searchDateFl'][$this->search['searchDateFl']] = $this->selected['sort'][$this->search['sort']] = $this->selected['eventGroup'][$this->search['eventGroup']] = "selected='selected'";

        //삭제상품여부
        if ($this->search['delFl']) {
            $this->arrWhere[] = 'g.delFl = ?';
            $this->db->bind_param_push($this->arrBind, $fieldTypeGoods['delFl'], $this->search['delFl']);
        }

        // 처리일자 검색
        if ($this->search['searchDateFl'] && $this->search['searchDate'][0] && $this->search['searchDate'][1] && $mode != 'layer') {
            $this->arrWhere[] = 'g.' . $this->search['searchDateFl'] . ' BETWEEN ? AND ?';
            $this->db->bind_param_push($this->arrBind, 's', $this->search['searchDate'][0] . ' 00:00:00');
            $this->db->bind_param_push($this->arrBind, 's', $this->search['searchDate'][1] . ' 23:59:59');
        }

        // 키워드 검색
        if ($this->search['key'] && $this->search['keyword']) {
            if ($this->search['key'] == 'all') {
                $tmpWhere = array('goodsNm', 'goodsNo', 'goodsCd', 'goodsSearchWord');
                $arrWhereAll = array();
                foreach ($tmpWhere as $keyNm) {
                    $arrWhereAll[] = '(g.' . $keyNm . ' LIKE concat(\'%\',?,\'%\'))';
                    $this->db->bind_param_push($this->arrBind, $fieldTypeGoods[$keyNm], $this->search['keyword']);
                }

                /* 공급사명 검색 추가 */
                $arrWhereAll[] = 's.companyNm LIKE concat(\'%\',?,\'%\')';
                $this->db->bind_param_push($this->arrBind, 's', $this->search['keyword']);

                if(gd_is_provider() === false && gd_is_plus_shop(PLUSSHOP_CODE_PURCHASE) === true) {
                    /* 매입처명 검색 추가 */
                    $arrWhereAll[] = 'p.purchaseNm LIKE concat(\'%\',?,\'%\')';
                    $this->db->bind_param_push($this->arrBind, 's', $this->search['keyword']);
                }

                $this->arrWhere[] = '(' . implode(' OR ', $arrWhereAll) . ')';
                unset($tmpWhere);
            } else {

                if ($this->search['key'] == 'companyNm') {
                    $this->arrWhere[] = 's.' . $this->search['key'] . ' LIKE concat(\'%\',?,\'%\')';
                    $this->db->bind_param_push($this->arrBind, 's', $this->search['keyword']);
                } else if($this->search['key'] == 'purchaseNm') {
                    $this->arrWhere[] = 'p.' . $this->search['key'] . ' LIKE concat(\'%\',?,\'%\')';
                    $this->db->bind_param_push($this->arrBind, 's', $this->search['keyword']);
                } else {
                    $this->arrWhere[] = 'g.' . $this->search['key'] . ' LIKE concat(\'%\',?,\'%\')';
                    $this->db->bind_param_push($this->arrBind, $fieldTypeGoods[$this->search['key']], $this->search['keyword']);
                }

            }
        }

        // 카테고리 검색
        if ($this->search['cateGoods']) {
            $this->arrWhere[] = 'gl.cateCd = ?';
            $this->db->bind_param_push($this->arrBind, $fieldTypeLink['cateCd'], $this->search['cateGoods']);
            $this->arrWhere[] = 'gl.cateLinkFl = "y"';
        }

        //카테고리 미지정
        if ($this->search['categoryNoneFl']) {
            $this->arrWhere[] = 'g.cateCd  = ""';
        }


        // 브랜드 검색
        if (($this->search['brandCd'] && $this->search['brandCdNm']) || $this->search['brand']) {
            if (!$this->search['brandCd'] && $this->search['brand'])
                $this->search['brandCd'] = $this->search['brand'];
            $this->arrWhere[] = 'g.brandCd = ?';
            $this->db->bind_param_push($this->arrBind, $fieldTypeGoods['brandCd'], $this->search['brandCd']);
        } else $this->search['brandCd'] = '';

        //브랜드 미지정
        if ($this->search['brandNoneFl']) {
            $this->arrWhere[] = '(g.brandCd  = "" or g.brandCd IS NULL)';
        }

        // 매입처 검색
        if (($this->search['purchaseNo'] && $this->search['purchaseNoNm'])) {
            if (is_array($this->search['purchaseNo'])) {
                foreach ($this->search['purchaseNo'] as $val) {
                    $tmpWhere[] = 'g.purchaseNo = ?';
                    $this->db->bind_param_push($this->arrBind, 's', $val);
                }
                $this->arrWhere[] = '(' . implode(' OR ', $tmpWhere) . ')';
                unset($tmpWhere);
            }
        }

        //매입처 미지정
        if ($this->search['purchaseNoneFl']) {
            $this->arrWhere[] = '(g.purchaseNo IS NULL OR g.purchaseNo  = "" OR g.purchaseNo  <= 0 OR p.delFl = "y")';
        }

        //추가상품 사용
        if ($this->search['addGoodsFl']) {
            $this->arrWhere[] = 'g.addGoodsFl = ?';
            $this->db->bind_param_push($this->arrBind, $fieldTypeGoods['addGoodsFl'], $this->search['addGoodsFl']);
        }

        // 상품가격 검색
        if ($this->search['goodsPrice'][0] || $this->search['goodsPrice'][1]) {

            if($this->search['goodsPrice'][0]) {
                $this->arrWhere[] = 'g.goodsPrice >= ? ';
                $this->db->bind_param_push($this->arrBind, $fieldTypeGoods['goodsPrice'], $this->search['goodsPrice'][0]);
            }

            if($this->search['goodsPrice'][1]) {
                $this->arrWhere[] = 'g.goodsPrice <= ? ';
                $this->db->bind_param_push($this->arrBind, $fieldTypeGoods['goodsPrice'], $this->search['goodsPrice'][1]);
            }
        }

        // 마일리지 검색
        if ($this->search['mileage'][0] || $this->search['mileage'][1]) {

            $mileage = gd_policy('member.mileageGive')['goods'];

            if($this->search['mileage'][0]) {
                $this->arrWhere[] = 'if( g.mileageFl ="c", '.$mileage.',  g.mileageGoods ) >= ? ';
                $this->db->bind_param_push($this->arrBind, $fieldTypeGoods['mileageGoods'], $this->search['mileage'][0]);
            }

            if($this->search['mileage'][1]) {
                $this->arrWhere[] = 'if( g.mileageFl ="c", '.$mileage.',  g.mileageGoods ) <= ? ';
                $this->db->bind_param_push($this->arrBind, $fieldTypeGoods['mileageGoods'], $this->search['mileage'][1]);
            }
        }

        // 재고검색
        if ($this->search['stock'][0] || $this->search['stock'][1]) {

            if($this->search['stock'][0]) {
                $this->arrWhere[] = 'g.totalStock >= ? ';
                $this->db->bind_param_push($this->arrBind, $fieldTypeGoods['totalStock'], $this->search['stock'][0]);
            }

            if($this->search['stock'][1]) {
                $this->arrWhere[] = 'g.totalStock <= ? ';
                $this->db->bind_param_push($this->arrBind, $fieldTypeGoods['totalStock'], $this->search['stock'][1]);
            }
        }

        // 옵션 사용 여부 검색
        if ($this->search['optionFl']) {
            $this->arrWhere[] = 'g.optionFl = ?';
            $this->db->bind_param_push($this->arrBind, $fieldTypeGoods['optionFl'], $this->search['optionFl']);
        }
        // 마일리지 정책 검색
        if ($this->search['mileageFl']) {
            $this->arrWhere[] = 'g.mileageFl = ?';
            $this->db->bind_param_push($this->arrBind, $fieldTypeGoods['mileageFl'], $this->search['mileageFl']);
        }
        // 텍스트옵션 사용 여부 검색
        if ($this->search['optionTextFl']) {
            $this->arrWhere[] = 'g.optionTextFl = ?';
            $this->db->bind_param_push($this->arrBind, $fieldTypeGoods['optionTextFl'], $this->search['optionTextFl']);
        }
        // 상품 출력 여부 검색
        if ($this->search['goodsDisplayFl']) {
            $this->arrWhere[] = 'g.goodsDisplayFl = ?';
            $this->db->bind_param_push($this->arrBind, $fieldTypeGoods['goodsDisplayFl'], $this->search['goodsDisplayFl']);
        }
        // 상품 판매 여부 검색
        if ($this->search['goodsSellFl']) {
            $this->arrWhere[] = 'g.goodsSellFl = ?';
            $this->db->bind_param_push($this->arrBind, $fieldTypeGoods['goodsSellFl'], $this->search['goodsSellFl']);
        }
        // 무한정 판매 여부 검색
        if ($this->search['stockFl']) {
            $this->arrWhere[] = 'g.stockFl = ?';
            $this->db->bind_param_push($this->arrBind, $fieldTypeGoods['stockFl'], $this->search['stockFl']);
        }
        if ($this->search['stockStateFl'] != 'all') {
            switch ($this->search['stockStateFl']) {
                case 'n': {
                    $this->arrWhere[] = 'g.stockFl = ?';
                    $this->db->bind_param_push($this->arrBind, $fieldTypeGoods['stockFl'], 'n');
                    break;
                }
                case 'u' : {
                    $this->arrWhere[] = '(g.stockFl = ? and g.totalStock > 0)';
                    $this->db->bind_param_push($this->arrBind, $fieldTypeGoods['stockFl'], 'y');
                    break;
                }
                case 'z' : {
                    $this->arrWhere[] = '(g.stockFl = ? and g.totalStock = 0)';
                    $this->db->bind_param_push($this->arrBind, $fieldTypeGoods['stockFl'], 'y');
                    break;
                }

            }
        }

        // 품절상품 여부 검색
        if ($this->search['soldOut']) {
            if ($this->search['soldOut'] == 'y') {
                $this->arrWhere[] = '( g.soldOutFl = \'y\' OR (g.stockFl = \'y\' AND g.totalStock <= 0 ))';
            }
            if ($this->search['soldOut'] == 'n') {
                $this->arrWhere[] = '( g.soldOutFl = \'n\' AND (g.stockFl = \'n\' OR (g.stockFl = \'y\' AND g.totalStock > 0)) )';
            }
        }
        // 아이콘(기간제한) 여부 검색
        if ($this->search['goodsIconCdPeriod']) {
            $this->arrWhere[] = 'g.goodsIconCdPeriod LIKE concat(\'%\',?,\'%\')';
            $this->db->bind_param_push($this->arrBind, $fieldTypeGoods['goodsIconCdPeriod'], $this->search['goodsIconCdPeriod']);
        }
        // 아이콘(무제한) 여부 검색
        if ($this->search['goodsIconCd']) {
            $this->arrWhere[] = 'g.goodsIconCd LIKE concat(\'%\',?,\'%\')';
            $this->db->bind_param_push($this->arrBind, $fieldTypeGoods['goodsIconCd'], $this->search['goodsIconCd']);
        }


        // 아이콘(무제한) 여부 검색
        if ($this->search['goodsColor']) {
            $tmp = [];
            foreach ($this->search['goodsColor'] as $k => $v) {
                $tmp[] = 'g.goodsColor LIKE concat(\'%\',?,\'%\')';
                $this->db->bind_param_push($this->arrBind, $fieldTypeGoods['goodsIconCd'], $v);
            }
            $this->arrWhere[] = '(' . implode(" OR ", $tmp) . ')';
        }

        // 모바일 상품 출력 여부 검색
        if ($this->search['goodsDisplayMobileFl']) {
            $this->arrWhere[] = 'g.goodsDisplayMobileFl = ?';
            $this->db->bind_param_push($this->arrBind, $fieldTypeGoods['goodsDisplayMobileFl'], $this->search['goodsDisplayMobileFl']);
        }

        // 모바일 상품 판매 여부 검색
        if ($this->search['goodsSellMobileFl']) {
            $this->arrWhere[] = 'g.goodsSellMobileFl = ?';
            $this->db->bind_param_push($this->arrBind, $fieldTypeGoods['goodsSellMobileFl'], $this->search['goodsSellMobileFl']);
        }


        // 모바일 상세 설명 여부 검색
        if ($this->search['mobileDescriptionFl'] == 'y') {
            $this->arrWhere[] = 'g.goodsDescriptionMobile != \'\' AND g.goodsDescriptionMobile IS NOT NULL';
        } else if ($this->search['mobileDescriptionFl'] == 'n') {
            $this->arrWhere[] = '(g.goodsDescriptionMobile = \'\' OR g.goodsDescriptionMobile IS NULL)';
        }
        //공급사
        if ($this->search['scmFl'] != 'all') {
            if (is_array($this->search['scmNo'])) {
                foreach ($this->search['scmNo'] as $val) {
                    $tmpWhere[] = 'g.scmNo = ?';
                    $this->db->bind_param_push($this->arrBind, 's', $val);
                }
                $this->arrWhere[] = '(' . implode(' OR ', $tmpWhere) . ')';
                unset($tmpWhere);
            } else {
                $this->arrWhere[] = 'g.scmNo = ?';
                $this->db->bind_param_push($this->arrBind, $fieldTypeGoods['scmNo'], $this->search['scmNo']);

                $this->search['scmNo'] = array($this->search['scmNo']);
                $this->search['scmNoNm'] = array($this->search['scmNoNm']);

            }
        }

        //승인구분
        if ($this->search['applyType'] != 'all') {
            $this->arrWhere[] = 'g.applyType = ?';
            $this->db->bind_param_push($this->arrBind, $fieldTypeGoods['applyType'], $this->search['applyType']);
        }

        //승인상태
        if ($this->search['applyFl'] != 'all') {
            $this->arrWhere[] = 'g.applyFl = ?';
            $this->db->bind_param_push($this->arrBind, $fieldTypeGoods['applyFl'], $this->search['applyFl']);
        }

        //배송관련
        $tmpFixFl = array_flip($this->search['goodsDeliveryFixFl']);
        unset($tmpFixFl['all']);
        if (count($tmpFixFl) || $this->search['goodsDeliveryFl']) {
            $delivery = \App::load('\\Component\\Delivery\\Delivery');
            $deliveryData = $delivery->getDeliveryGoods($this->search);

            if (is_array($deliveryData)) {
                foreach ($deliveryData as $val) {
                    $tmpWhere[] = 'g.deliverySno = ?';
                    $this->db->bind_param_push($this->arrBind, 's', $val['sno']);
                }
                $this->arrWhere[] = '(' . implode(' OR ', $tmpWhere) . ')';
                unset($tmpWhere);
            }
        }

        // 네이버쇼핑상품 출력 여부 검색
        if ($this->search['naverFl']) {
            $this->arrWhere[] = 'g.naverFl = ?';
            $this->db->bind_param_push($this->arrBind, $fieldTypeGoods['naverFl'], $this->search['naverFl']);
        }

        //기획전 검색
        if ($this->search['eventThemeSno']) {
            $eventGoodsNoArray = array();
            $eventThemeData = $this->getDisplayThemeInfo($this->search['eventThemeSno']);
            if($eventThemeData['displayCategory'] === 'g'){
                //그룹형인경우

                $eventGroupTheme = \App::load('\\Component\\Promotion\\EventGroupTheme');
                $eventGroupData = $eventGroupTheme->getSimpleData($this->search['eventThemeSno']);
                $this->search['eventGroupSelectList'] = $eventGroupData;
                foreach($eventGroupData as $key => $eventGroupArr){
                    if((int)$eventGroupArr['sno'] === (int)$this->search['eventGroup']){
                        $eventGoodsNoArray = @explode(STR_DIVISION, $eventGroupArr['groupGoodsNo']);
                        break;
                    }
                }
            }
            else {
                //일반형인경우
                $eventGoodsNoArray = @explode(INT_DIVISION, $eventThemeData['goodsNo']);
            }

            if(count($eventGoodsNoArray) > 0){
                $this->arrWhere[] = "(g.goodsNo IN ('" . implode("',' ", $eventGoodsNoArray) . "'))";
            }
            unset($eventGoodsNoArray);
        }

        if (empty($this->arrBind)) {
            $this->arrBind = null;
        }
    }

    /**
     * 관리자 상품 리스트
     *
     * @param string $mode 일반리스트 인지 레이어 리스트인지 구부 (null or layer)
     * @param integer $pageNum 레이어 리스트의 경우 페이지당 리스트 수
     * @return array 상품 리스트 정보
     */
   public function getAdminListGoods($mode = null, $pageNum = 5)
 {
 gd_isset($this->goodsTable,DB_GOODS);

 $this->goodsTable = DB_GOODS; //180503, 임의 테이블 변경 추가, 기존 테이블(es_goodsSearch)

 // --- 검색 설정
$getValue = Request::get()->toArray();

 gd_isset($getValue['delFl'], 'n');
 // --- 정렬 설정
$sort = gd_isset($getValue['sort'], 'g.goodsNo desc');

 $this->setSearchGoods($getValue);

 //수기주문일시
/*
if($mode === 'orderWrite'){
 $this->goodsTable = DB_GOODS;

 $this->setSearchGoodsOrderWrite($param);
 }*/

 if ($mode == 'layer') {
 // --- 페이지 기본설정
if (gd_isset($getValue['pagelink'])) {
 $getValue['page'] = (int)str_replace('page=', '', preg_replace('/^{page=[0-9]+}/', '', gd_isset($getValue['pagelink'])));
 } else {
 $getValue['page'] = 1;
 }
 gd_isset($getValue['pageNum'], $pageNum);
 } else {
 // --- 페이지 기본설정
gd_isset($getValue['page'], 1);
 gd_isset($getValue['pageNum'], 10);
 }

 $page = \App::load('\\Component\\Page\\Page', $getValue['page']);
 $page->page['list'] = $getValue['pageNum']; // 페이지당 리스트 수
$page->setPage();
 if ($mode != 'layer') {
 $page->setUrl(\Request::getQueryString());
 }

 // 현 페이지 결과
if (!empty($this->search['cateGoods']) || !empty($this->search['displayTheme'][1])) {
 $join[] = ' LEFT JOIN ' . DB_GOODS_LINK_CATEGORY . ' as gl ON g.goodsNo = gl.goodsNo ';
 }

 if(($getValue['key'] == 'companyNm' && $getValue['keyword']) || strpos($sort, "companyNm") !== false) $join[] = ' LEFT JOIN ' . DB_SCM_MANAGE . ' as s ON s.scmNo = g.scmNo ';
 if(gd_is_provider() === false && gd_is_plus_shop(PLUSSHOP_CODE_PURCHASE) === true ) {
 if( $getValue['key'] == 'purchaseNm' && $getValue['keyword']) {
 $join[] = ' LEFT JOIN ' . DB_PURCHASE . ' as p ON p.purchaseNo = g.purchaseNo and p.delFl = "n"';
 } else if($getValue['purchaseNoneFl'] =='y') {
 $join[] = ' LEFT JOIN ' . DB_PURCHASE . ' as p ON p.purchaseNo = g.purchaseNo';
 }
 }

 //상품 혜택 검색
if (!empty($this->search['goodsBenefitSno'])) {
 $join[] = ' LEFT JOIN ' . DB_GOODS_LINK_BENEFIT . ' as gbl ON g.goodsNo = gbl.goodsNo ';
 }

 //상품 혜택 아이콘 검색
if ($this->search['goodsIconCd']) {
 $join[] = 'LEFT JOIN
 (
 select t1.goodsNo,t1.benefitSno,t1.goodsIconCd
 from ' . DB_GOODS_LINK_BENEFIT . ' as t1,
 (select goodsNo, min(linkPeriodStart) as min_start from ' . DB_GOODS_LINK_BENEFIT . ' where ((benefitUseType=\'periodDiscount\' or benefitUseType=\'newGoodsDiscount\') AND linkPeriodStart < NOW() AND linkPeriodEnd > NOW()) or benefitUseType=\'nonLimit\' group by goodsNo) as t2
 where t1.linkPeriodStart = t2.min_start and t1.goodsNo = t2.goodsNo
 ) as gbs on g.goodsNo = gbs.goodsNo ';
 }

 $this->db->strField = "g.goodsNo";
 // 구매율의 경우 계산 필드를 삽입을 위해 변경
if($sort == 'orderRate desc') {
 $this->db->strField = "g.goodsNo, round(((g.orderGoodsCnt / g.hitCnt)*100), 2) as orderRate";
 }
 $this->db->strJoin = implode('', $join);
 $this->db->strWhere = implode(' AND ', gd_isset($this->arrWhere));
 $this->db->strOrder = $sort;
 $this->db->strLimit = $page->recode['start'] . ',' . $getValue['pageNum'];
 // 검색 조건에 메인분류 검색이 있는 경우 group by 추가
if(!empty($this->search['displayTheme'][1])) {
 $this->db->strGroup = "g.goodsNo ";
 $mainDisplayStrGroup = " GROUP BY g.goodsNo";
 }
 $query = $this->db->query_complete();

 $strSQL = 'SELECT ' . array_shift($query) . ' FROM ' . $this->goodsTable . ' g ' . implode(' ', $query);

 // 빠른 이동/복사/삭제 인 경우 검색이 없으면 리턴
if ($mode == 'batch' && empty($this->arrWhere)) {
 $data = null;
 } else {
 $data = $this->db->query_fetch($strSQL, $this->arrBind);


 /* 검색 count 쿼리 */
 $totalCountSQL = ' SELECT COUNT(g.goodsNo) AS totalCnt FROM ' . $this->goodsTable . ' as g ' . implode('', $join) . ' WHERE ' . implode(' AND ', $this->arrWhere) . $mainDisplayStrGroup;
 $dataCount = $this->db->query_fetch($totalCountSQL, $this->arrBind);
 unset($this->arrBind);

 $page->recode['total'] = ($mainDisplayStrGroup) ? count($dataCount) : $dataCount[0]['totalCnt']; //검색 레코드 수 - 메인분류 검색의 경우 count($dataCount)
 if (Session::get('manager.isProvider')) { // 전체 레코드 수
$page->recode['amount'] = $this->db->getCount($this->goodsTable, 'goodsNo', 'WHERE delFl=\'' . $getValue['delFl'] . '\' AND scmNo = \'' . Session::get('manager.scmNo') . '\'');
 $scmWhereString = " AND g.scmNo = '" . (string)Session::get('manager.scmNo') . "'"; // 공급사인 경우
} else {
 $page->recode['amount'] = $this->db->getCount($this->goodsTable, 'goodsNo', 'WHERE delFl=\'' . $getValue['delFl'] . '\'');
 }
 $page->setPage();

 // 아이콘 설정
if (empty($data) === false) {
 $this->setAdminListGoods($data,",g.goodsIconStartYmd,g.goodsIconEndYmd,g.goodsIconCdPeriod,g.goodsIconCd,g.goodsBenefitSetFl");
 }

 // 상품그리드
if($mode == null) {
 // 상품리스트 그리드 설정
$goodsAdminGrid = \App::load('\\Component\\Goods\\GoodsAdminGrid');
 $goodsAdminGridMode = $goodsAdminGrid->getGoodsAdminGridMode();
 $this->goodsGridConfigList = $goodsAdminGrid->getSelectGoodsGridConfigList($goodsAdminGridMode, 'all');
 if (empty($this->goodsGridConfigList) === false) {
 $getData['goodsGridConfigList'] = $this->goodsGridConfigList;
 $gridAddDisplayArray = ['best', 'main', 'cate']; // 그리드 추가진열 레이어 노출 항목
$getData['goodsGridConfigListDisplayFl'] = false; // 그리드 추가 진열 레이어 노출 여부
foreach($gridAddDisplayArray as $displayPassVal) {
 if(array_key_exists($displayPassVal, $getData['goodsGridConfigList']['display']) === true) {
 $getData['goodsGridConfigListDisplayFl'] = true; // 그리드 추가 진열 레이어 노출 사용
break;
 }
 }
 if($goodsAdminGridMode == 'goods_list') {
 $getData['goodsGridConfigList']['btn'] = '수정';
 }
 }

 // 상품 리스트 품절, 노출 PC/mobile, 미노출 PC/mobile 카운트 쿼리
if($goodsAdminGridMode == 'goods_list') {
 $dataStateCount = [];
 $dataStateCountQuery = [
 'pcDisplayCnt' => " g.goodsDisplayFl='y'",
 'mobileDisplayCnt' => " g.goodsDisplayMobileFl='y'",
 'pcNoDisplayCnt' => " g.goodsDisplayFl='n'",
 'mobileNoDisplayCnt' => " g.goodsDisplayMobileFl='n'",
 ];
 foreach ($dataStateCountQuery as $stateKey => $stateVal) {
 $dataStateSQL = " SELECT COUNT(g.goodsNo) AS cnt FROM " . $this->goodsTable . " as g WHERE " . $stateVal . " AND g.delFl ='n'" . $scmWhereString;
 $dataStateCount[$stateKey] = $this->db->query_fetch($dataStateSQL)[0]['cnt'];

 }
 // 품절의 경우 OR 절 INDEX 경유하지 않기에 별도 쿼리 실행 - DBA
 $dataStateSoldOutSql = "select sum(cnt) as cnt from ( SELECT count(1) AS cnt FROM " . $this->goodsTable . " as g1 WHERE g1.soldOutFl = 'y' AND g1.delFl ='n' union all SELECT count(1) AS cnt FROM " . $this->goodsTable . " as g2 WHERE g2.soldOutFl = 'n' and g2.stockFl = 'y' AND g2.totalStock <= 0 AND g2.delFl ='n') gQ";
 $dataStateCount['soldOutCnt'] = $this->db->query_fetch($dataStateSoldOutSql)[0]['cnt'];
 $getData['stateCount'] = $dataStateCount;
 }
 }
 }

 // 각 데이터 배열화
$getData['data'] = gd_htmlspecialchars_stripslashes(gd_isset($data));
 $getData['sort'] = $sort;
 $getData['search'] = gd_htmlspecialchars($this->search);
 $getData['checked'] = $this->checked;
 $getData['selected'] = $this->selected;

 return $getData;
 }


    /**
     * 관리자 상품 리스트
     *
     * @param string $mode 일반리스트 인지 레이어 리스트인지 구부 (null or layer)
     * @param integer $pageNum 레이어 리스트의 경우 페이지당 리스트 수
     * @return array 상품 리스트 정보
     */
    public function getAdminListGoodsExcel($getValue)
    {
        // --- 정렬 설정
        $sort = gd_isset($getValue['sort'], 'g.goodsNo desc');

        $this->setSearchGoods($getValue);

        if($getValue['goodsNo'] && is_array($getValue['goodsNo'])) {
            $this->arrWhere[] = 'goodsNo IN (' . implode(',', $getValue['goodsNo']) . ')';
        }

        // 현 페이지 결과
        $join[] = ' LEFT JOIN ' . DB_SCM_MANAGE . ' as s ON s.scmNo = g.scmNo ';
        $join[] = ' LEFT JOIN ' . DB_CATEGORY_BRAND . ' as glb ON glb.cateCd = g.brandCd ';
        if (!empty($this->search['cateGoods'])) {
            $join[] = ' LEFT JOIN ' . DB_GOODS_LINK_CATEGORY . ' as gl ON g.goodsNo = gl.goodsNo ';
        }
        if(gd_is_provider() === false && gd_is_plus_shop(PLUSSHOP_CODE_PURCHASE) === true) {
            $join[] = ' LEFT JOIN ' . DB_PURCHASE . ' as p ON p.purchaseNo = g.purchaseNo and p.delFl = "n"';
            $this->db->strField = "g.* ,  s.companyNm as scmNm,  p.purchaseNm,  glb.cateNm as brandNm";
        } else {
            $this->db->strField = "g.* ,  s.companyNm as scmNm,  glb.cateNm as brandNm";
        }
        $this->db->strJoin = implode('', $join);
        $this->db->strWhere = implode(' AND ', gd_isset($this->arrWhere));
        $this->db->strOrder = $sort;

        $query = $this->db->query_complete();
        $strSQL = 'SELECT ' . array_shift($query) . ' FROM ' . DB_GOODS . ' g ' . implode(' ', $query);

        $data = $this->db->query_fetch($strSQL, $this->arrBind);
        unset($this->arrBind);

        // 각 데이터 배열화
        $getData = gd_htmlspecialchars_stripslashes(gd_isset($data));

        return $getData;
    }

    /**
     * 관리자 상품 옵션 리스트
     *
     * @param string $mode 일반리스트 인지 레이어 리스트인지 구부 (null or layer)
     * @return array 상품 옵션 리스트 정보
     */
    public function getAdminListOption($mode = null)
    {
        $getValue = Request::get()->toArray();

        // --- 검색을 위한 bind 정보
        $fieldType = DBTableField::getFieldTypes('tableManageGoodsOption');

        //검색설정
        /* @formatter:off */
        $this->search['sortList'] = array(
            'mgo.regDt desc' => __('등록일 ↓'),
            'mgo.regDt asc' => __('등록일 ↑'),
            'mgo.modDt desc' => __('수정일 ↓'),
            'mgo.modDt asc' => __('수정일 ↑'),
            'optionManageNm asc' => __('옵션 관리명 ↓'),
            'optionManageNm desc' => __('옵션 관리명 ↑'),
            'companyNm asc' => __('공급사 ↓'),
            'companyNm desc' => __('공급사 ↑')
        );
        /* @formatter:on */


        // --- 검색 설정
        $this->search['sort'] = gd_isset($getValue['sort'], 'mgo.regDt desc');
        $this->search['detailSearch'] = gd_isset($getValue['detailSearch']);
        $this->search['searchDateFl'] = gd_isset($getValue['searchDateFl'], 'regDt');
        $this->search['searchPeriod'] = gd_isset($getValue['searchPeriod'], '-1');
        $this->search['key'] = gd_isset($getValue['key']);
        $this->search['keyword'] = gd_isset($getValue['keyword']);
        $this->search['optionDisplayFl'] = gd_isset($getValue['optionDisplayFl'], '');

        $this->search['scmFl'] = gd_isset($getValue['scmFl'], Session::get('manager.isProvider') ? 'n' : 'all');
        $this->search['scmNo'] = gd_isset($getValue['scmNo'], (string)Session::get('manager.scmNo'));
        $this->search['scmNoNm'] = gd_isset($getValue['scmNoNm']);
        $this->search['sno'] = gd_isset($getValue['sno']);

        if ($this->search['searchPeriod'] < 0) {
            $this->search['searchDate'][] = gd_isset($getValue['searchDate'][0]);
            $this->search['searchDate'][] = gd_isset($getValue['searchDate'][1]);
        } else {
            $this->search['searchDate'][] = gd_isset($getValue['searchDate'][0], date('Y-m-d', strtotime('-7 day')));
            $this->search['searchDate'][] = gd_isset($getValue['searchDate'][1], date('Y-m-d'));
        }

        $this->checked['optionDisplayFl'][$this->search['optionDisplayFl']] = $this->checked['scmFl'][$getValue['scmFl']] = "checked='checked'";
        $this->selected['searchDateFl'][$this->search['searchDateFl']] = $this->selected['sort'][$this->search['sort']] = "selected='selected'";

        $this->checked['searchPeriod'][$this->search['searchPeriod']] = "active";


        // 처리일자 검색
        if ($this->search['searchDateFl'] && $this->search['searchDate'][0] && $this->search['searchDate'][1] && $mode != 'layer') {
            $this->arrWhere[] = 'mgo.' . $this->search['searchDateFl'] . ' BETWEEN ? AND ?';
            $this->db->bind_param_push($this->arrBind, 's', $this->search['searchDate'][0] . ' 00:00:00');
            $this->db->bind_param_push($this->arrBind, 's', $this->search['searchDate'][1] . ' 23:59:59');
        }


        if ($this->search['scmFl'] != 'all') {
            if (is_array($this->search['scmNo'])) {
                foreach ($this->search['scmNo'] as $val) {
                    $tmpWhere[] = 'mgo.scmNo = ?';
                    $this->db->bind_param_push($this->arrBind, 's', $val);
                }
                $this->arrWhere[] = '(' . implode(' OR ', $tmpWhere) . ')';
                unset($tmpWhere);
            } else {
                $this->arrWhere[] = 'mgo.scmNo = ?';
                $this->db->bind_param_push($this->arrBind, $fieldType['scmNo'], $this->search['scmNo']);
            }

        }

        if ($this->search['sno']) {
            if (is_array($this->search['sno'])) {
                foreach ($this->search['sno'] as $val) {
                    $tmpWhere[] = 'mgo.sno = ?';
                    $this->db->bind_param_push($this->arrBind, 's', $val);
                }
                $this->arrWhere[] = '(' . implode(' OR ', $tmpWhere) . ')';
                unset($tmpWhere);
            } else {
                $this->arrWhere[] = 'mgo.sno = ?';
                $this->db->bind_param_push($this->arrBind, $fieldType['sno'], $this->search['sno']);
            }
        }

        // 키워드 검색
        if ($this->search['key'] && $this->search['keyword']) {
            if ($this->search['key'] == 'all') {
                $tmpWhere = array('optionManageNm', 'optionName');
                $arrWhereAll = array();
                foreach ($tmpWhere as $keyNm) {
                    $arrWhereAll[] = '(' . $keyNm . ' LIKE concat(\'%\',?,\'%\'))';
                    $this->db->bind_param_push($this->arrBind, $fieldType[$keyNm], $this->search['keyword']);
                }
                $this->arrWhere[] = '(' . implode(' OR ', $arrWhereAll) . ')';
            } else {
                $this->arrWhere[] = $this->search['key'] . ' LIKE concat(\'%\',?,\'%\')';
                $this->db->bind_param_push($this->arrBind, $fieldType[$this->search['key']], $this->search['keyword']);
            }
        }
        // 옵션표시 검색
        if ($this->search['optionDisplayFl']) {
            $this->arrWhere[] = 'optionDisplayFl = ?';
            $this->db->bind_param_push($this->arrBind, $fieldType['optionDisplayFl'], $this->search['optionDisplayFl']);
        }

        if (empty($this->arrBind)) {
            $this->arrBind = null;
        }

        // --- 정렬 설정
        $sort = $this->search['sort'];

        if ($mode == 'layer') {
            // --- 페이지 기본설정
            if (gd_isset($getValue['pagelink'])) {
                $getValue['page'] = (int)str_replace('page=', '', preg_replace('/^{page=[0-9]+}/', '', gd_isset($getValue['pagelink'])));
            } else {
                $getValue['page'] = 1;
            }
            gd_isset($getValue['pageNum'], 10);
        } else {
            // --- 페이지 기본설정
            gd_isset($getValue['page'], 1);
            gd_isset($getValue['pageNum'], 10);
        }


        $page = \App::load('\\Component\\Page\\Page', $getValue['page']);
        $page->page['list'] = $getValue['pageNum']; // 페이지당 리스트 수

        if (Session::get('manager.isProvider')) $strSQL = ' SELECT COUNT(sno) AS cnt FROM ' . DB_MANAGE_GOODS_OPTION . ' WHERE scmNo = \'' . Session::get('manager.scmNo') . '\'';
        else $strSQL = ' SELECT COUNT(sno) AS cnt FROM ' . DB_MANAGE_GOODS_OPTION;
        $res = $this->db->query_fetch($strSQL, null, false);

        $page->recode['amount'] = $res['cnt']; // 전체 레코드 수
        $page->setPage();
        $page->setUrl(\Request::getQueryString());


        // 현 페이지 결과
        $this->db->strJoin = ' LEFT JOIN ' . DB_SCM_MANAGE . ' as s ON s.scmNo = mgo.scmNo ';
        $this->db->strField = "SQL_CALC_FOUND_ROWS mgo.*,s.companyNm as scmNm";
        $this->db->strWhere = implode(' AND ', gd_isset($this->arrWhere));
        $this->db->strOrder = $sort;
        if($mode !='layer') $this->db->strLimit = $page->recode['start'] . ',' . $getValue['pageNum'];

        $query = $this->db->query_complete();
        $strSQL = 'SELECT ' . array_shift($query) . ' FROM ' . DB_MANAGE_GOODS_OPTION . ' as mgo ' . implode(' ', $query);
        $data = $this->db->query_fetch($strSQL, $this->arrBind);

        // 검색 레코드 수
        list($page->recode['total']) = $this->db->fetch('SELECT FOUND_ROWS()', 'row');
        $page->setPage();

        // 각 데이터 배열화
        $getData['data'] = gd_htmlspecialchars_stripslashes(gd_isset($data));
        $getData['sort'] = $sort;
        $getData['search'] = gd_htmlspecialchars($this->search);
        $getData['checked'] = $this->checked;
        $getData['selected'] = $this->selected;

        return $getData;
    }

    /**
     * 관리자 상품 아이콘 리스트
     *
     * @return array 상품 리스트 정보
     */
    public function getAdminListGoodsIcon()
    {
        $getValue = Request::get()->toArray();

        //검색설정
        /* @formatter:off */
        $this->search['sortList'] = array(
            'iconNm asc' => __('아이콘 이름 ↓'),
            'iconNm desc' => __('아이콘 이름 ↑'),
            'regDt desc' => __('등록일 ↓'),
            'regDt asc' => __('등록일 ↑'),
            'modDt asc' => __('수정일 ↓'),
            'modDt desc' => __('수정일 ↑')
        );
        /* @formatter:on */

        $this->search['sort'] = gd_isset($getValue['sort'], 'regDt desc');
        $this->search['iconNm'] = gd_isset($getValue['iconNm']);
        $this->search['iconPeriodFl'] = gd_isset($getValue['iconPeriodFl']);
        $this->search['iconUseFl'] = gd_isset($getValue['iconUseFl']);

        $this->search['searchDateFl'] = gd_isset($getValue['searchDateFl'], 'regDt');
        $this->search['searchPeriod'] = gd_isset($getValue['searchPeriod'], '-1');

        if ($this->search['searchPeriod'] < 0) {
            $this->search['searchDate'][] = gd_isset($searchData['searchDate'][0]);
            $this->search['searchDate'][] = gd_isset($searchData['searchDate'][1]);
        } else {
            $this->search['searchDate'][] = gd_isset($searchData['searchDate'][0], date('Y-m-d', strtotime('-7 day')));
            $this->search['searchDate'][] = gd_isset($searchData['searchDate'][1], date('Y-m-d'));
        }

        $this->selected['searchDateFl'][$this->search['searchDateFl']] = $this->selected['sort'][$this->search['sort']] = "selected='selected'";
        $this->checked['searchPeriod'][$this->search['searchPeriod']] = "active";

        $this->checked['iconPeriodFl'][$this->search['iconPeriodFl']] = $this->checked['iconUseFl'][$this->search['iconUseFl']] = 'checked="checked"';


        //처리일자 검색
        if ($this->search['searchDateFl'] && $this->search['searchDate'][0] && $this->search['searchDate'][1]) {
            $this->arrWhere[] = $this->search['searchDateFl'] . ' BETWEEN ? AND ?';
            $this->db->bind_param_push($this->arrBind, 's', $this->search['searchDate'][0] . ' 00:00:00');
            $this->db->bind_param_push($this->arrBind, 's', $this->search['searchDate'][1] . ' 23:59:59');
        }

        // 아이콘 이름 검색
        if ($this->search['iconNm']) {
            $this->arrWhere[] = 'iconNm LIKE concat(\'%\',?,\'%\')';
            $this->db->bind_param_push($this->arrBind, 's', $this->search['iconNm']);
        }

        // 상품 아이콘 기간 사용 여부 검색
        if ($this->search['iconPeriodFl']) {
            $this->arrWhere[] = 'iconPeriodFl = ?';
            $this->db->bind_param_push($this->arrBind, 's', $this->search['iconPeriodFl']);
        }

        // 상품 아이콘 사용 여부 검색
        if ($this->search['iconUseFl']) {
            $this->arrWhere[] = 'iconUseFl = ?';
            $this->db->bind_param_push($this->arrBind, 's', $this->search['iconUseFl']);
        }

        if (empty($this->arrBind)) {
            $this->arrBind = null;
        }

        // --- 정렬 설정
        $sort = $this->search['sort'];

        // --- 페이지 기본설정
        gd_isset($getValue['page'], 1);
        gd_isset($getValue['pageNum'], 10);

        $strSQL = 'SELECT count(*) as cnt  FROM ' . DB_MANAGE_GOODS_ICON ;
        list($result) = $this->db->query_fetch($strSQL);
        $totalCnt = $result['cnt'];

        $page = \App::load('\\Component\\Page\\Page', $getValue['page']);
        $page->page['list'] = $getValue['pageNum']; // 페이지당 리스트 수
        $page->recode['amount'] = $totalCnt; // 전체 레코드 수
        $page->setPage();
        $page->setUrl(\Request::getQueryString());

        $this->db->strField = "SQL_CALC_FOUND_ROWS *";
        $this->db->strWhere = implode(' AND ', gd_isset($this->arrWhere));
        $this->db->strOrder = $sort;
        $this->db->strLimit = $page->recode['start'] . ',' . $getValue['pageNum'];

        $data = $this->getManageGoodsIconInfo();

        // 검색 레코드 수
        list($page->recode['total']) = $this->db->fetch('SELECT FOUND_ROWS()', 'row');
        $page->setPage();

        // 각 데이터 배열화
        $getData['data'] = gd_htmlspecialchars_stripslashes(gd_isset($data));
        $getData['sort'] = $sort;
        $getData['search'] = gd_htmlspecialchars($this->search);
        $getData['checked'] = $this->checked;
        $getData['selected'] = $this->selected;

        return $getData;
    }

    /**
     * 관리자 상품 순서 변경 리스트
     *
     * @param string $cateMode category, brand 구분
     * @param boolean $isPage 페이징 여부
     * @return array 상품 리스트 정보
     */
    public function getAdminListSort($cateMode = 'category', $isPage = true)
    {
        $getValue = Request::get()->toArray();

        // 카테고리 종류에 따른 설정
        if ($cateMode == 'category') {
            $dbTable = DB_GOODS_LINK_CATEGORY;
        } else {
            $dbTable = DB_GOODS_LINK_BRAND;
        }

        $this->arrWhere[] = "g.delFl = 'n'";
        $this->arrWhere[] = "g.applyFl = 'y'";

        // --- 검색 설정
        $this->search['cateGoods'] = ArrayUtils::last(gd_isset($getValue['cateGoods']));

        // 카테고리 검색
        if ($this->search['cateGoods']) {
            $this->arrWhere[] = 'gl.cateCd = ?';
            $this->db->bind_param_push($this->arrBind, 's', $this->search['cateGoods']);
            $this->arrWhere[] = 'gl.cateLinkFl = ?';
            $this->db->bind_param_push($this->arrBind, 's', 'y');

        } else {
            return '';
        }

        if (empty($this->arrBind)) {
            $this->arrBind = null;
        }


        // --- 페이지 기본설정
        gd_isset($getValue['page'], 1);
        gd_isset($getValue['pageNum'], 10);
        gd_isset($getValue['sort'], "regDt desc");

        $page = \App::load('\\Component\\Page\\Page', $getValue['page']);
        $page->page['list'] = $getValue['pageNum']; // 페이지당 리스트 수
        $page->setPage();
        $page->setUrl(\Request::getQueryString());

        $sort[] = "gl.fixSort desc , gl.goodsSort desc";
        if ($getValue['sort']) $sort[] = $getValue['sort'];


        // 현 페이지 결과
        $join[] = ' INNER JOIN ' . DB_GOODS . ' as g ON gl.goodsNo = g.goodsNo ';
        $join[] = ' LEFT JOIN ' . DB_GOODS_IMAGE . ' as gi ON gl.goodsNo = gi.goodsNo AND gi.imageKind = \'List\' ';
        $join[] = ' LEFT JOIN ' . DB_SCM_MANAGE . ' as s ON s.scmNo = g.scmNo ';

        $this->db->strField = 'SQL_CALC_FOUND_ROWS gl.goodsSort, gl.fixSort, gl.goodsNo, g.goodsNm,g.soldOutFl, g.totalStock, g.stockFl, g.goodsDisplayFl, g.goodsDisplayMobileFl, g.goodsSellFl, g.regDt, g.imagePath, g.imageStorage, g.goodsPrice, gi.imageName, s.companyNm as scmNm';
        $this->db->strJoin = implode('', $join);
        $this->db->strWhere = implode(' AND ', gd_isset($this->arrWhere));
        $this->db->strOrder = implode(',', $sort);
        if ($isPage) $this->db->strLimit = $page->recode['start'] . ',' . $getValue['pageNum'];

        $query = $this->db->query_complete();

        $strSQL = 'SELECT ' . array_shift($query) . ' FROM ' . $dbTable . ' gl ' . implode(' ', $query);

        // 빠른 이동/복사/삭제 인 경우 검색이 없으면 리턴
        if (empty($this->arrWhere) === true) {
            $data = null;
        } else {
            $data = $this->db->query_fetch($strSQL, $this->arrBind);
            unset($this->arrBind);

            // 검색 레코드 수
            list($page->recode['total']) = $this->db->fetch('SELECT FOUND_ROWS()', 'row');
            $page->setPage();
        }

        $getData['fixCount'] = $this->db->getCount($dbTable, 'goodsNo', 'WHERE fixSort > 0 AND cateLinkFl = "y" AND cateCd=\'' .$this->search['cateGoods'] . '\'');

        // 각 데이터 배열화
        $getData['data'] = gd_htmlspecialchars_stripslashes(gd_isset($data));
        $getData['search'] = gd_htmlspecialchars($this->search);

        return $getData;
    }

    /**
     * 관리자 상품 순서 변경 처리
     *
     * @param string $getData 변경 데이타
     */
    public function setGoodsSortChange($getData)
    {
        $getData['goodsNo'] = array_values($getData['goodsNoData']);


        // 데이타 체크
        if (isset($getData['goodsNo']) === false || isset($getData['cateCd']) === false) {
            throw new \Exception(__('조건에 대해 처리중 오류가 발생했습니다.'), 500);
        }

        // 카테고리 종류에 따른 설정
        if ($getData['cateMode'] == 'category') {
            $dbTable = DB_GOODS_LINK_CATEGORY;
        } else {
            $dbTable = DB_GOODS_LINK_BRAND;
        }

        //기존 잘못연결된 n 링크 삭제
        $strSQL = 'DELETE FROM ' . $dbTable . ' where cateLinkFl = "n" AND cateCd=\'' . $getData['cateCd'] . '\'';
        $this->db->query($strSQL);

        if ($getData['sortAutoFl'] == 'y') { //자동 진열인 경우

            if ($getData['pageNow'] > 1) {
                $strSQL = 'UPDATE ' . $dbTable . ' SET goodsSort = 0  where cateCd=\'' . $getData['cateCd'] . '\'';
                $this->db->query($strSQL);
            } else {
                $strSQL = 'UPDATE ' . $dbTable . ' SET goodsSort = 0 ,fixSort = 0  where cateCd=\'' . $getData['cateCd'] . '\'';
                $this->db->query($strSQL);
            }

            if ($getData['sortFix']) {
                foreach ($getData['sortFix'] as $key => $val) {
                    $tmpField[] = 'WHEN \'' . $val . '\' THEN \'' . sprintf('%05s', $key+1) . '\'';
                }

                $strSetSQL = 'SET @newSort := 0;';
                $this->db->query($strSetSQL);

                $sortField = ' CASE goodsNo ' . implode(' ', $tmpField) . ' ELSE \'\' END ';

                $strSQL = 'UPDATE '.$dbTable.' SET fixSort = ( @newSort := @newSort+1 )
                            WHERE  cateCd="'.$getData['cateCd'].'"  AND (goodsNo = \'' . implode('\' OR goodsNo = \'', $getData['sortFix']) . '\') AND cateLinkFl  = "y" ORDER BY (' . $sortField . ') DESC';

                $this->db->query($strSQL);

            }

        } else { //수동정렬인 경우

            //1.해당 카테고리 진열상품 재 정렬
            $strSetSQL = 'SET @newSort := 0;';
            $this->db->query($strSetSQL);

            $strSQL = 'UPDATE '.$dbTable.' SET goodsSort = ( @newSort := @newSort+1 )
                                WHERE goodsNo
                                IN
                                (
                                  SELECT goodsNo
                                  FROM '.DB_GOODS.'
                                  WHERE delFl = "n" AND applyFl = "y"

                                ) AND cateCd="'.$getData['cateCd'].'" AND cateLinkFl  = "y" ORDER BY goodsSort ASC';

            $this->db->query($strSQL);


            //2.해당 카테고리 미진열 상품 정렬 수정
            $strSQL = "SELECT @newSort";
            $maxGoodsSort = $this->db->query_fetch($strSQL,null,false)['@newSort'];

            $strSetSQL = 'SET @newSort := '.$maxGoodsSort.';';
            $this->db->query($strSetSQL);

            $strSQL = 'UPDATE '.$dbTable.' SET goodsSort = ( @newSort := @newSort+1 )
                                WHERE goodsNo
                                NOT IN
                                (
                                  SELECT goodsNo
                                  FROM '.DB_GOODS.'
                                  WHERE delFl = "n" AND applyFl = "y"
                                ) AND cateCd="'.$getData['cateCd'].'" ORDER BY goodsSort ASC';

            $this->db->query($strSQL);

            //3.현재 페이지 카테고리 정렬값 변경
            $totalGoodsSort = $getData['totalGoodsSort']+1;

            $nextSort =  $prevSort = [];
            $fixCount = count($getData['sortFix']);
            foreach($getData['goodsSort'] as  $k => $v) {

                $strWhere = "goodsNo = '".$getData['goodsNo'][$k]."' AND cateCd='".$getData['cateCd']."'";

                if(is_array($getData['sortFix']) && in_array($getData['goodsNo'][$k],$getData['sortFix'])) {
                    $fixSort = $fixCount;
                    $fixCount--;
                } else {
                    $fixSort = "0";
                }

                $this->db->set_update_db($dbTable, array("goodsSort = '".($totalGoodsSort-$v)."',fixSort = '".$fixSort."'"), $strWhere);

                if($v < $getData['startNum']) {
                    $prevSort[$v] = $getData['goodsNo'][$k];
                } else if ($v >= $getData['startNum']+$getData['pagePnum']) {
                    $nextSort[$v] = $getData['goodsNo'][$k];
                }
            }

            ksort($prevSort);
            krsort($nextSort);

            if($prevSort) {
                $sortCnt = 0;
                foreach($prevSort as $k => $v) {
                    $strWhere = "goodsNo NOT IN ('" . implode("','", $prevSort) . "') AND cateCd='".$getData['cateCd']."' AND goodsSort <= ".($totalGoodsSort-$k)." AND goodsSort >= ".($totalGoodsSort-$sortCnt-$getData['pagePnum']);
                    $this->db->set_update_db($dbTable, array("goodsSort = goodsSort-1 "), $strWhere);
                    $sortCnt++;
                }
            }

            if($nextSort) {
                $sortCnt = 0;
                foreach($nextSort as $k => $v) {
                    $strWhere = "goodsNo NOT IN ('" . implode("','", $nextSort) . "') AND cateCd='".$getData['cateCd']."' AND goodsSort < ".($getData['startNum']+$getData['pagePnum']+$sortCnt)." AND goodsSort >= ".($totalGoodsSort-$k);
                    $this->db->set_update_db($dbTable, array("goodsSort = goodsSort+1 "), $strWhere);
                    $sortCnt++;
                }
            }
        }

        if ($getData['pageNow'] > 1 && $getData['sortFix']) {
            $strSQL = "UPDATE " . $dbTable . " SET fixSort = fixSort+".count($getData['sortFix'])."  where cateCd='" . $getData['cateCd'] . "' AND fixSort > 0 AND goodsNo NOT IN ('" . implode("','", $getData['sortFix']) . "') ";
            $this->db->query($strSQL);
        }
    }

    /**
     * 관리자 상품 일괄 관리 리스트 - 상품기준
     *
     * @param string $mode 리스트에 이미지 출력여부 (null or image)
     * @return array 상품 리스트 정보
     */
    public function getAdminListBatch($mode = null)
    {

        // --- 검색 설정
        $getValue = Request::get()->toArray();


        gd_isset($getValue['delFl'], 'n');
        $sort = gd_isset($getValue['sort'], 'g.goodsNo desc');

        $this->setSearchGoods($getValue);

        // --- 페이지 기본설정
        gd_isset($getValue['page'], 1);
        gd_isset($getValue['pageNum'], 10);

        $page = \App::load('\\Component\\Page\\Page', $getValue['page']);
        $page->page['list'] = $getValue['pageNum']; // 페이지당 리스트 수
        $page->setPage();
        $page->setUrl(\Request::getQueryString());

        switch ($mode) {
            case 'icon':
                $addField = ', g.goodsColor,g.goodsIconCd,goodsIconCdPeriod ';
                break;
            case 'delivery':
                $addField = ",g.deliverySno";
                break;
            default :
                $addField = '';
        }

        // 현 페이지 결과
        if (!empty($this->search['cateGoods'])) {
            $join[] = ' LEFT JOIN ' . DB_GOODS_LINK_CATEGORY . ' as gl ON g.goodsNo = gl.goodsNo ';
        }

        if(($getValue['key'] == 'all' || $getValue['key'] == 'companyNm') && $getValue['keyword']) $join[] = ' LEFT JOIN ' . DB_SCM_MANAGE . ' as s ON s.scmNo = g.scmNo ';
        if(gd_is_provider() === false && gd_is_plus_shop(PLUSSHOP_CODE_PURCHASE) === true )  {
            if(($getValue['key'] == 'all' || $getValue['key'] == 'purchaseNm') && $getValue['keyword']) {
                $join[] = ' LEFT JOIN ' . DB_PURCHASE . ' as p ON p.purchaseNo = g.purchaseNo and p.delFl = "n"';
            } else if($getValue['purchaseNoneFl'] =='y') {
                $join[] = ' LEFT JOIN ' . DB_PURCHASE . ' as p ON p.purchaseNo = g.purchaseNo';
            }
        }

        $this->arrWhere[] = "g.applyFl !='a'";

        $this->db->strField = "g.goodsNo,g.goodsNm,g.soldOutFl,g.totalStock, g.goodsDisplayFl, g.goodsSellFl, g.goodsDisplayMobileFl, g.goodsSellMobileFl, g.mileageFl, g.stockFl, g.imageStorage, g.imagePath, g.goodsPrice, g.fixedPrice, g.mileageGoods,g.mileageGoodsUnit, g.costPrice,g.goodsDiscountFl,g.goodsDiscount,g.goodsDiscountUnit,naverFl,g.scmNo " . $addField;
        $this->db->strJoin = implode('', $join);
        $this->db->strWhere = implode(' AND ', $this->arrWhere);
        $this->db->strOrder = $sort;
        $this->db->strLimit = $page->recode['start'] . ',' . $getValue['pageNum'];


        // 검색 전체를 일괄 수정을 할경우 필요한 값
        $getData['batchAll']['join'] = Encryptor::encrypt($this->db->strJoin);
        $getData['batchAll']['where'] = Encryptor::encrypt($this->db->strWhere);
        $getData['batchAll']['bind'] = Encryptor::encrypt(json_encode($this->arrBind, JSON_UNESCAPED_UNICODE));

        $query = $this->db->query_complete();
        $strSQL = 'SELECT ' . array_shift($query) . ' FROM ' . DB_GOODS . ' g ' . implode(' ', $query);

        $data = $this->db->query_fetch($strSQL, $this->arrBind);

        /* 검색 count 쿼리 */
        $totalCountSQL =  ' SELECT COUNT(g.goodsNo) AS totalCnt FROM ' . DB_GOODS . ' as g  '.implode('', $join).'  WHERE '.implode(' AND ', $this->arrWhere);
        $dataCount = $this->db->query_fetch($totalCountSQL, $this->arrBind,false);

        unset($this->arrBind);

        $page->recode['total'] = $dataCount['totalCnt']; //검색 레코드 수
        if (Session::get('manager.isProvider')) { // 전체 레코드 수
            $page->recode['amount'] = $this->db->getCount(DB_GOODS, 'goodsNo', 'WHERE applyFl !="a" AND delFl=\'' . $getValue['delFl'] . '\'  AND scmNo = \'' . Session::get('manager.scmNo') . '\'');
        }  else {
            $page->recode['amount'] = $this->db->getCount(DB_GOODS, 'goodsNo', 'WHERE applyFl !="a" AND  delFl=\'' . $getValue['delFl'] . '\'');
        }

        $page->setPage();

        // 아이콘  설정
        if (empty($data) === false) {
            /* 이미지 설정 */
            $strImageSQL = 'SELECT goodsNo,imageName FROM ' . DB_GOODS_IMAGE . ' g  WHERE imageKind = "List" AND goodsNo IN ("'.implode('","',array_column($data, 'goodsNo')).'")';
            $tmpImageData = $this->db->query_fetch($strImageSQL);
            $imageData = array_combine (array_column($tmpImageData, 'goodsNo'), array_column($tmpImageData, 'imageName'));

            $strScmSQL = 'SELECT scmNo,companyNm FROM ' . DB_SCM_MANAGE . ' g  WHERE scmNo IN ("'.implode('","',array_column($data, 'scmNo')).'")';
            $tmpScmData = $this->db->query_fetch($strScmSQL);
            $scmData = array_combine (array_column($tmpScmData, 'scmNo'), array_column($tmpScmData, 'companyNm'));

            if(gd_is_provider() === false && gd_is_plus_shop(PLUSSHOP_CODE_PURCHASE) === true) {
                $strPurchaseSQL = 'SELECT purchaseNo,purchaseNm FROM ' . DB_PURCHASE . ' g  WHERE delFl = "n" AND purchaseNo IN ("' . implode('","', array_column($data, 'purchaseNo')) . '")';
                $tmpPurchaseData = $this->db->query_fetch($strPurchaseSQL);
                $purchaseData = array_combine(array_column($tmpPurchaseData, 'purchaseNo'), array_column($tmpPurchaseData, 'purchaseNm'));
            }

            if($mode =='delivery') {
                $strDeliverySQL = 'SELECT sdb.sno , sdb.method as deliveryNm  FROM ' . DB_SCM_DELIVERY_BASIC . ' sdb  WHERE sdb.sno IN ("'.implode('","',array_column($data, 'deliverySno')).'")';
                $tmpDeliveryData = $this->db->query_fetch($strDeliverySQL);
                $deliveryData = array_combine (array_column($tmpDeliveryData, 'sno'), array_column($tmpDeliveryData, 'deliveryNm'));
            }

            foreach ($data as $key => & $val) {
                $val['imageName']= $imageData[$val['goodsNo']];
                $val['scmNm']= $scmData[$val['scmNo']];
                $val['purchaseNm']= $purchaseData[$val['purchaseNo']];
                $val['deliveryNm']= $deliveryData[$val['deliverySno']];
            }
        }

        // 각 데이터 배열화
        $getData['data'] = gd_htmlspecialchars_stripslashes(gd_isset($data));
        $getData['search'] = gd_htmlspecialchars($this->search);
        $getData['checked'] = $this->checked;
        $getData['selected'] = $this->selected;


        unset($this->arrBind);

        if ($mode == 'icon') {
            // 상품 아이콘 설정
            $this->db->strField = implode(', ', DBTableField::setTableField('tableManageGoodsIcon', null, 'iconUseFl'));
            $this->db->strWhere = 'iconUseFl = \'y\'';
            $this->db->strOrder = 'sno DESC';
            $getData['icon'] = $this->getManageGoodsIconInfo();
        }


        return $getData;
    }

    /**
     * 관리자 상품 일괄 관리 리스트 - 옵션 기준
     *
     * @param string $mode 리스트에 이미지 출력여부 (null or image)
     * @return array 상품 리스트 정보
     */
    public function getAdminListOptionBatch($mode = null)
    {

        // --- 검색 설정
        $getValue = Request::get()->toArray();


        gd_isset($getValue['delFl'], 'n');
        $sort = gd_isset($getValue['sort'], 'g.regDt desc');

        $this->setSearchGoods($getValue);


        // --- 페이지 기본설정
        gd_isset($getValue['page'], 1);
        gd_isset($getValue['pageNum'], 10);

        $page = \App::load('\\Component\\Page\\Page', $getValue['page']);
        $page->page['list'] = $getValue['pageNum']; // 페이지당 리스트 수


        if (Session::get('manager.isProvider')) $strSQL = ' SELECT COUNT(*) AS cnt FROM ' . DB_GOODS_OPTION . ' as go  INNER JOIN ' . DB_GOODS . ' as g ON go.goodsNo = g.goodsNo WHERE g.applyFl !="a" AND g.delFl=? AND scmNo = \'' . Session::get('manager.scmNo') . '\'';
        else $strSQL = ' SELECT COUNT(*) AS cnt FROM ' . DB_GOODS_OPTION . ' as go  INNER JOIN ' . DB_GOODS . ' as g ON go.goodsNo = g.goodsNo WHERE g.applyFl !="a" AND g.delFl=? ';
        $this->db->bind_param_push($arrBind, 's', $getValue['delFl']);
        $res = $this->db->query_fetch($strSQL, $arrBind, false);
        unset($arrBind);

        $page->recode['amount'] = $res['cnt']; // 전체 레코드 수
        $page->setPage();
        $page->setUrl(\Request::getQueryString());


        // 현 페이지 결과
        $join[] = ' INNER JOIN ' . DB_GOODS . ' as g ON go.goodsNo = g.goodsNo ';
        $join[] = ' LEFT JOIN ' . DB_SCM_MANAGE . ' as s ON s.scmNo = g.scmNo ';
        if(gd_is_provider() === false && gd_is_plus_shop(PLUSSHOP_CODE_PURCHASE) === true )  {
            if(($getValue['key'] == 'all' || $getValue['key'] == 'purchaseNm') && $getValue['keyword']) {
                $join[] = ' LEFT JOIN ' . DB_PURCHASE . ' as p ON p.purchaseNo = g.purchaseNo and p.delFl = "n"';
            } else if($getValue['purchaseNoneFl'] =='y') {
                $join[] = ' LEFT JOIN ' . DB_PURCHASE . ' as p ON p.purchaseNo = g.purchaseNo';
            }
        }
        if (!empty($this->search['cateGoods'])) {
            $join[] = ' LEFT JOIN ' . DB_GOODS_LINK_CATEGORY . ' as gl ON go.goodsNo = gl.goodsNo ';
        }
        if ($mode == 'image') {
            $join[] = ' LEFT JOIN ' . DB_GOODS_IMAGE . ' as gi ON go.goodsNo = gi.goodsNo AND gi.imageKind = \'List\' ';
            $addField = ', gi.imageName ';
        } else {
            $addField = '';
        }

        $this->arrWhere[] = "g.applyFl !='a'";


        $this->db->strField = "SQL_CALC_FOUND_ROWS go.*,s.companyNm as scmNm, g.goodsNm, g.optionFl,g.soldOutFl , g.goodsDisplayFl, g.goodsSellFl, g.goodsDisplayMobileFl, g.goodsSellMobileFl, g.mileageFl, g.stockFl, g.imageStorage, g.imagePath, g.goodsPrice, g.fixedPrice, g.mileageGoods, g.costPrice " . $addField;
        $this->db->strJoin = implode('', $join);
        $this->db->strWhere = implode(' AND ', $this->arrWhere);
        $this->db->strOrder = $sort . ",go.sno ASC";
        $this->db->strLimit = $page->recode['start'] . ',' . $getValue['pageNum'];

        // 검색 전체를 일괄 수정을 할경우 필요한 값
        $getData['batchAll']['join'] = Encryptor::encrypt($this->db->strJoin);
        $getData['batchAll']['where'] = Encryptor::encrypt($this->db->strWhere);
        $getData['batchAll']['bind'] = Encryptor::encrypt(json_encode($this->arrBind));

        $query = $this->db->query_complete();
        $strSQL = 'SELECT ' . array_shift($query) . ' FROM ' . DB_GOODS_OPTION . ' go ' . implode(' ', $query);


        $data = $this->db->query_fetch($strSQL, $this->arrBind);

        // 검색 레코드 수
        list($page->recode['total']) = $this->db->fetch('SELECT FOUND_ROWS()', 'row');
        $page->setPage();

        // 각 데이터 배열화
        $getData['data'] = gd_htmlspecialchars_stripslashes(gd_isset($data));
        $getData['search'] = gd_htmlspecialchars($this->search);
        $getData['checked'] = $this->checked;

        return $getData;
    }

    /**
     * 상품 가격 일괄 변경
     *
     * @param array $getData 일괄 처리 정보
     */
    public function setBatchPrice($getData)
    {
        // 정보 체크
        if ($getData['pricePercent'] !='' && $getData['pricePercent']  >= 0 ) //계산 수식 일괄 적용
        {
            $arrKey = array('confPrice', 'markType', 'plusMinus', 'targetPrice', 'queryAll');
            $allKey = array_keys($getData);
            foreach ($arrKey as $val) {
                if (!in_array($val, $allKey)) {
                    throw new Exception(__('가격 일괄 수정할 필수 항목이 존재하지 않습니다.'));
                }
                if (!gd_isset($getData[$val])) {
                    throw new Exception(__('가격 일괄 수정할 필수 항목이 존재하지 않습니다.'));
                }
            }

            $trunc = Globals::get('gTrunc.goods');
            $getData['roundType'] = $trunc['unitRound'];
            $getData['roundUnit'] = $trunc['unitPrecision'];


            // 할인/할증 관련 배열
            $arrPlusMinus = array('p' => '+', 'm' => '-');

            // 계산할 금액
            if ($getData['markType'] == 'p') {
                $pricePercent = ' ( ' . $getData['confPrice'] . ' * ' . ($getData['pricePercent'] / 100) . ' )';
            } else {
                $pricePercent = $getData['pricePercent'];
            }

            // 1차 계산식
            $expression = $getData['confPrice'] . ' ' . $arrPlusMinus[$getData['plusMinus']] . $pricePercent;

            // 올림
            if ($getData['roundType'] == 'ceil') {
                $roundUnit = $getData['roundUnit'] * 10;
                // 2차 계산식
                $expression = '( ( CEILING( ( ( ' . $expression . ' ) / ' . $roundUnit . ' ) ) ) * ' . $roundUnit . ' )';
            }

            // 반올림
            if ($getData['roundType'] == 'round') {
                $roundUnit = strlen($getData['roundUnit']);
                // 2차 계산식
                $expression = 'ROUND( ( ' . $expression . ' ), -' . $roundUnit . ' )';
            }

            // 버림
            if ($getData['roundType'] == 'floor') {
                $roundUnit = $getData['roundUnit'] * 10;
                // 2차 계산식
                $expression = '( ( FLOOR( ( ( ' . $expression . ' ) / ' . $roundUnit . ' ) ) ) * ' . $roundUnit . ' )';
            }

            // 일괄 변경 처리
            $arrGoodsNo = $this->setBatchGoodsNo(gd_isset($getData['batchAll']), gd_isset($getData['arrGoodsNo']), gd_isset($getData['queryAll']));

            $strSQL = 'SELECT goodsNo,goodsNm,' . $expression . ' as price FROM ' . DB_GOODS . '  WHERE goodsNo IN (' . implode(',', $arrGoodsNo) . ') AND ' . $expression . ' < 0 ';
            $data = $this->db->query_fetch($strSQL);

            if ($getData['isPrice']) {
                return $data;
            }

            if ($data) {
                $tmpGoodsNo = array_flip($arrGoodsNo);
                foreach ($data as $k => $v) {
                    if (in_array($v['goodsNo'], $arrGoodsNo)) {
                        unset($arrGoodsNo[$tmpGoodsNo[$v['goodsNo']]]);
                    }
                }
            }

            // 계산식 완료
            $expression = $getData['targetPrice'] . '= ' . $expression;
            $return = $this->setBatchUpdateSql($expression, $arrGoodsNo, $getData['confPrice'] . "," . $getData['targetPrice']);


        } else //선택된값 필드값 적용
        {
            $return = $this->setBatchUpdate($getData, array('goodsPrice', 'fixedPrice', 'costPrice'));
        }

        return $return;
    }


    /**
     * 상품 아이콘 일괄 변경
     *
     * @param array $getData 일괄 처리 정보
     */
    public function setBatchIcon($getData)
    {
        // 정보 체크
        if ($getData['termsFl'] == 'y') //계산 수식 일괄 적용
        {

            if ($getData['type'] == 'icon') {
                $tmpStr = [];
                if (gd_isset($getData['icon']['goodsIconStartYmd'])) $tmpStr[] = "goodsIconStartYmd = '" . $getData['icon']['goodsIconStartYmd'] . "'";
                if (gd_isset($getData['icon']['goodsIconEndYmd'])) $tmpStr[] = "goodsIconEndYmd = '" . $getData['icon']['goodsIconEndYmd'] . "'";
                if (is_array($getData['icon']['goodsIconCdPeriod'])) $tmpStr[] = "goodsIconCdPeriod = '" . implode(INT_DIVISION, $getData['icon']['goodsIconCdPeriod']) . "'";
                if (is_array($getData['icon']['goodsIconCd'])) $tmpStr[] = "goodsIconCd = '" . implode(INT_DIVISION, $getData['icon']['goodsIconCd']) . "'";

                $expression = implode(",", $tmpStr);

                $filed = "goodsIconStartYmd,goodsIconEndYmd,goodsIconCdPeriod,goodsIconCd";
            }

            if ($getData['type'] == 'color') {

                if ($getData['goodsColor']) $goodsColor = implode(STR_DIVISION, $getData['goodsColor']);

                switch ($getData['colorType']) {
                    case 'add':
                        $expression = "goodsColor = CONCAT(goodsColor,IF(goodsColor = '','', '" . STR_DIVISION . "'),'" . $goodsColor . "')";
                        break;
                    case 'update':
                        $expression = "goodsColor = '" . $goodsColor . "'";
                        break;
                    case 'del':
                        $expression = "goodsColor = ''";
                        break;
                }

                $filed = "goodsColor";

            }

            // 일괄 변경 처리
            $arrGoodsNo = $this->setBatchGoodsNo(gd_isset($getData['batchAll']), gd_isset($getData['arrGoodsNo']), gd_isset($getData['queryAll']));
            $applyFl = $this->setBatchUpdateSql($expression, $arrGoodsNo, $filed);

        } else //선택된값 아이콘 필드 적용
        {
            foreach ($getData['arrGoodsNo'] as $k => $v) {
                if (is_array($getData['goodsIconCd'][$v])) $setData['goodsIconCd'][$v] = implode(INT_DIVISION, $getData['goodsIconCd'][$v]);
                if (is_array($getData['goodsIconCdPeriod'][$v])) $setData['goodsIconCdPeriod'][$v] = implode(INT_DIVISION, $getData['goodsIconCdPeriod'][$v]);

                $setData['arrGoodsNo'][$k] = $v;
            }

            $applyFl =  $this->setBatchUpdate($setData, array('goodsIconCd', 'goodsIconCdPeriod'));
        }

        return $applyFl;
    }

    /**
     * 상품 마일리지 일괄 변경
     *
     * @param array $getData 일괄 처리 정보
     */
    public function setBatchMileage($getData)
    {
        if (gd_isset($getData['batchAll']) == 'y') {
            if (!is_array($getData['queryAll'])) {
                throw new \Exception(__('조건에 대해 처리중 오류가 발생했습니다.'), 500);
            }
            foreach ($getData['queryAll'] as $key => $val) {
                if ($key == 'bind') {
                    $query[$key] = json_decode(Encryptor::decrypt($val));
                } else {
                    $query[$key] = Encryptor::decrypt($val);
                }
            }
            gd_trim($query);

            $strSQL = 'SELECT g.goodsNo FROM ' . DB_GOODS . ' g ' . $query['join'] . ' WHERE ' . $query['where'] . ' ORDER BY g.goodsNo ASC';
            $data = $this->db->query_fetch($strSQL, $query['bind']);
            unset($query);

            $arrGoodsNo = array();
            foreach ($data as $key => $val) {
                $arrGoodsNo[] = $val['goodsNo'];
            }
        } else {
            $arrGoodsNo = $getData['arrGoodsNo'];
        }


        if ($getData['type'] == 'mileage') //마일리지 설정인 경우
        {
            $setData['mileageFl'] = $getData['mileageFl'];
            $setData['mileageGoods'] = $getData['mileageGoods'];
            $setData['mileageGoodsUnit'] = $getData['mileageGoodsUnit'];
        } else //상품할인 설정인 경우
        {
            $setData['goodsDiscountFl'] = $getData['goodsDiscountFl'];
            $setData['goodsDiscount'] = $getData['goodsDiscount'];
            $setData['goodsDiscountUnit'] = $getData['goodsDiscountUnit'];
        }

        $applyFl = $this->setBatchGoods($arrGoodsNo, array_keys($setData), array_values($setData));

        return $applyFl;

    }

    public function setBatchGoodsNo($batchAll = null, $arrGoodsNo = null, $queryAll = null)
    {
        // where 문 설정
        if (gd_isset($batchAll) == 'y') {
            if (!is_array($queryAll)) {
                throw new \Exception(__('조건에 대해 처리중 오류가 발생했습니다.'), 500);
            }


            foreach ($queryAll as $key => $val) {
                if ($key == 'bind') {
                    $query[$key] = json_decode(Encryptor::decrypt($val));
                } else {
                    $query[$key] = Encryptor::decrypt($val);
                }
            }

            $strSQL = 'SELECT g.goodsNo FROM ' . DB_GOODS . ' g ' . $query['join'] . ' WHERE ' . $query['where'] . ' ORDER BY g.goodsNo ASC';
            $data = $this->db->query_fetch($strSQL, $query['bind']);
            unset($query);

            $arrGoodsNo = array();
            foreach ($data as $key => $val) {
                $arrGoodsNo[] = $val['goodsNo'];
            }
        } else {
            if (!is_array($arrGoodsNo)) {
                throw new \Exception(__('조건에 대해 처리중 오류가 발생했습니다.'), 500);
            }
        }

        return $arrGoodsNo;
    }

    /**
     * 상품 일괄 변경 처리
     *
     * @param string $expression 처리할 수식
     * @param string $batchAll 검색 전체 수정 또는 선택 상품 수정
     * @param array $arrGoodsNo 선택 상품의 경우 goodsNo 의 배열 값
     * @param array $queryAll 검색 전체의 경우 암호화된 쿼리문 배열
     */

    public function setBatchUpdateSql($expression, $arrGoodsNo = null, $fieldInfo = null)
    {

        // 일괄 처리를 위한 where 문 배열 및 로그처리
        $logCode = array();
        $logCodeNm = array();
        $addLogData = '일괄처리한 수식 : ' . $expression . chr(10);
        if (preg_match('/^mileage+/', $expression)) {
            $logSubType = 'mileage';
        } else {
            $logSubType = 'price';
        }

        if (Session::get('manager.isProvider') && Session::get('manager.scmPermissionModify') == 'c') {
            $applyFl = 'a';
            $tmp[] = "applyFl = '" . $applyFl . "'";
            $tmp[] = "applyDt = '" . date('Y-m-d H:i:s') . "'";
            $tmp[] = "applyType = 'm'";
        } else {
            $applyFl = 'y';
            $tmp[] = "applyFl = '" . $applyFl . "'";
        }


        //승인관련 하여 이전 데이터 체크
        foreach ($arrGoodsNo as $key => $val) {

            $goodsNo = $this->db->escape($val);

            $goodsData = $this->getGoodsInfo($goodsNo, $fieldInfo);

            if ($tmp) $expression .= "," . implode(",", $tmp);

            $strWhere = "goodsNo = '" . $goodsNo . "'";
            $this->db->set_update_db(DB_GOODS, $expression, $strWhere);

            $updateData = $this->getGoodsInfo($goodsNo, $fieldInfo);


            $this->setGoodsLog("goods", $goodsNo, $goodsData, $updateData);

            unset($arrBind);

        }

        //네이버관련 업데이트
        // if ($this->naverConfig['naverFl'] == 'y') {
        $this->setGoodsUpdateEp($applyFl, $arrGoodsNo);
        // }


        //$strWhere = 'goodsNo IN (' . implode(',', $arrGoodsNo) . ')';
        //$this->db->set_update_db(DB_GOODS, $expression, $strWhere);


        foreach ($logCode as $key => $val) {
            LogHandler::wholeLog('goods', $logSubType, 'batch', $val, $logCodeNm[$val], $addLogData);
        }

        return $applyFl;

    }

    /**
     * 옵션 일괄 변경 처리
     *
     * @param string $expression 처리할 수식
     * @param string $batchAll 검색 전체 수정 또는 선택 상품 수정
     * @param array $arrGoodsNo 선택 상품의 경우 goodsNo 의 배열 값
     * @param array $queryAll 검색 전체의 경우 암호화된 쿼리문 배열
     */

    public function setBatchUpdateOptionSql($expression, $batchAll = null, $arrGoodsNo = null, $queryAll = null)
    {
        // where 문 설정
        if (gd_isset($batchAll) == 'y') {
            if (!is_array($queryAll)) {
                throw new \Exception(__('조건에 대해 처리중 오류가 발생했습니다.'), 500);
            }


            foreach ($queryAll as $key => $val) {
                if ($key == 'bind') {
                    $query[$key] = json_decode(Encryptor::decrypt($val));
                } else {
                    $query[$key] = Encryptor::decrypt($val);
                }
            }

            $strSQL = 'SELECT go.sno FROM ' . DB_GOODS_OPTION . ' go ' . $query['join'] . ' WHERE ' . $query['where'] . ' ORDER BY go.sno ASC';
            $data = $this->db->query_fetch($strSQL, $query['bind']);
            unset($query);

            $arrGoodsNo = array();
            foreach ($data as $key => $val) {
                $arrGoodsNo[] = $val['sno'];
            }
        } else {
            if (!is_array($arrGoodsNo)) {
                throw new \Exception(__('조건에 대해 처리중 오류가 발생했습니다.'), 500);
            }
        }

        // 일괄 처리를 위한 where 문 배열 및 로그처리
        $logCode = array();
        $logCodeNm = array();
        $addLogData = '일괄처리한 수식 : ' . $expression . chr(10);
        if (preg_match('/^mileage+/', $expression)) {
            $logSubType = 'mileage';
        } else {
            $logSubType = 'price';
        }


        $strWhere = 'sno IN (' . implode(',', $arrGoodsNo) . ')';

        $this->db->set_update_db(DB_GOODS_OPTION, $expression, $strWhere);

        foreach ($logCode as $key => $val) {
            // 전체 로그를 저장합니다.
            LogHandler::wholeLog('goods', $logSubType, 'batch', $val, $logCodeNm[$val], $addLogData);
        }

        return $arrGoodsNo;

    }


    /**
     * 상품 정보 비교 후 일괄 변경
     *
     * @param array $getData 상품 정보
     * @param array $arrKey 비교할 필드
     */
    public function setBatchUpdate($getData, $arrKey)
    {
        $arrBatch = array();
        $strWhere = 'goodsNo = ?';

        // bind를 위한 필드 정보
        $fieldData = DBTableField::getBindField('tableGoods', $arrKey);

        // 기존 정보를 변경
        foreach ($getData['arrGoodsNo'] as $key => $val) {
            $arrBatch[$val]['sno'][] = $val;

            foreach ($arrKey as $keyNm) {
                $arrBatch[$val][$keyNm][] = $getData[$keyNm][$val];
            }
        }


        // 상품별로 구분해서 변경된 정보만 update
        $updateGoodsNo = [];
        foreach ($arrBatch as $goodsNo => $arrData) {

            // 로그 데이타 초기화
            $strLogData = '';

            // 기존 상품의 옵션 값 정보
            $tmpData = $this->getGoodsInfo($goodsNo); // 옵션/가격 정보

            foreach ($arrKey as $k => $v) {
                $goodsData[$v] = $tmpData[$v];
            }
            $goodsData['sno'] = $tmpData['goodsNo'];


            // 값을 비교후 update 인 경우만 처리함
            $compareOption = $this->db->get_compare_array_data(array($goodsData), $arrData, false, $arrKey); // 3번째 false 값 바꾸지 마세요... 주의

            foreach ($compareOption as $optionSno => $optionResult) {
                // 결과가 수정인 경우만 처리
                if ($optionResult != 'update') {
                    continue;
                }


                // 정보 초기화
                $arrBinding = array();

                // bind 데이터
                foreach ($arrData['sno'] as $key => $val) {
                    if ($val != $goodsNo) {
                        continue;
                    }
                    foreach ($fieldData as $fKey => $fVal) {
                        $arrBinding[$fVal['val']] = $arrData[$fVal['val']][$key];
                    }
                }

                // 디비 저장
                $arrBind = $this->db->get_binding($fieldData, $arrBinding, $optionResult);


                $this->db->bind_param_push($arrBind['bind'], 'i', $goodsNo);
                $this->db->set_update_db(DB_GOODS, $arrBind['param'], $strWhere, $arrBind['bind']);
                unset($arrBind);

                $this->setGoodsLog("goods", $goodsNo, $goodsData, $arrBinding);
                $updateGoodsNo[] = $goodsNo;
            }


            // 전체 로그를 저장합니다.
            if (empty($strLogData) === false) {
                $addLogData = $stockLogData . $strLogData;
                LogHandler::wholeLog('goods', 'stock', 'batch', $goodsNo, $getData['goodsNm'][$goodsNo], $addLogData);
            }
        }

        //상태 일괄 업데이트
        $applyFl = $this->setGoodsApplyUpdate($updateGoodsNo, 'modify');
        return $applyFl;

    }

    public function setBatchUpdateOption($getData, $arrKey, $arrKeyNm = array())
    {
        $arrBatch = array();
        $strWhere = 'sno = ?';


        // bind를 위한 필드 정보
        $fieldData = DBTableField::getBindField('tableGoodsOption', $arrKey);

        // 기존 정보를 변경
        foreach ($getData['optionSno'] as $key => $val) {

            foreach ($arrKey as $keyNm) {
                if ($getData[$keyNm][$key]) $arrBatch[$val][$keyNm][] = $getData[$keyNm][$key];
            }

            if (is_array($arrBatch[$val])) $arrBatch[$val]['sno'][] = $val;

            if ($key == 'arrGoodsNo') $goodsNo[$val] = $getData['arrGoodsNo'][$key];

        }

        // 상품별로 구분해서 변경된 정보만 update
        $updateGoodsNo = [];
        foreach ($arrBatch as $sno => $arrData) {

            // 로그 데이타 초기화
            $strLogData = '';


            // 기존 상품의 옵션 값 정보
            $tmpData = $this->getGoodsOptionInfo($sno); // 옵션/가격 정보


            foreach ($arrKey as $k => $v) {
                $optionData[$v] = $tmpData[$v];
            }
            $optionData['sno'] = $tmpData['sno'];


            // 값을 비교후 update 인 경우만 처리함
            $compareOption = $this->db->get_compare_array_data(array($optionData), $arrData, false, $arrKey); // 3번째 false 값 바꾸지 마세요... 주의

            foreach ($compareOption as $optionSno => $optionResult) {
                // 결과가 수정인 경우만 처리
                if ($optionResult != 'update') {
                    continue;
                }


                // 정보 초기화
                $arrBinding = array();

                // bind 데이터
                foreach ($arrData['sno'] as $key => $val) {
                    if ($val != $sno) {
                        continue;
                    }
                    foreach ($fieldData as $fKey => $fVal) {
                        $arrBinding[$fVal['val']] = $arrData[$fVal['val']][$key];
                    }
                }

                // 디비 저장
                $arrBind = $this->db->get_binding($fieldData, $arrBinding, $optionResult);
                $this->db->bind_param_push($arrBind['bind'], 'i', $sno);
                $this->db->set_update_db(DB_GOODS_OPTION, $arrBind['param'], $strWhere, $arrBind['bind']);

                $arrBinding['sno'] = $sno;

                $this->setGoodsLog("option", $goodsNo[$sno], array($optionData), array('update' => array($arrBinding)));
                $updatetGoodsNo[] = $goodsNo[$sno];

                unset($arrBind);

            }
        }
    }

    /*
     * 가격 마일리지 재고 수정
     */
    public function setBatchStock($getData)
    {

        $totalStockGoodsNo = [];
        if ($getData['termsFl'] == 'n') //개별수정 조건
        {
            $goodsData = [];
            $optionData = [];
            $arrGoodsNo = [];
            $goodsDisplayFl = [];
            $goodsDisplayMobileFl = [];
            $goodsSellFl = [];
            $goodsSellMobileFl = [];
            $arrOptionSno = [];
            $soldOutFl = [];
            foreach ($getData['arrGoodsNo'] as $k => $v) {
                $tmp = explode("_", $v);
                if (!in_array($tmp[0], $arrGoodsNo)) {
                    $arrGoodsNo[$tmp[0]] = $tmp[0];
                    $goodsDisplayFl[$tmp[0]] = $getData['goods']['goodsDisplayFl'][$tmp[0]];
                    $goodsDisplayMobileFl[$tmp[0]] = $getData['goods']['goodsDisplayMobileFl'][$tmp[0]];
                    $goodsSellFl[$tmp[0]] = $getData['goods']['goodsSellFl'][$tmp[0]];
                    $goodsSellMobileFl[$tmp[0]] = $getData['goods']['goodsSellMobileFl'][$tmp[0]];
                    $soldOutFl[$tmp[0]] = $getData['goods']['soldOutFl'][$tmp[0]];
                }

                $arrOptionSno[$k] = $tmp[1];
                $optionViewFl[$k] = gd_isset($getData['option']['optionViewFl'][$tmp[0]][$tmp[1]],'y');
                $optionSellFl[$k] = gd_isset($getData['option']['optionSellFl'][$tmp[0]][$tmp[1]],'y');
                if ($getData['option']['stockFl'][$tmp[0]][$tmp[1]]) {
                    switch ($getData['option']['stockFl'][$tmp[0]][$tmp[1]]) {
                        case 'p':
                            $stockCnt[$k] = $getData['option']['stockCntFix'][$tmp[0]][$tmp[1]] + $getData['option']['stockCnt'][$tmp[0]][$tmp[1]];
                            break;
                        case 'm':
                            $stockCnt[$k] = $getData['option']['stockCntFix'][$tmp[0]][$tmp[1]] - $getData['option']['stockCnt'][$tmp[0]][$tmp[1]];
                            break;
                        case 'c':
                            $stockCnt[$k] = $getData['option']['stockCnt'][$tmp[0]][$tmp[1]];
                            break;
                    }

                    $totalStockGoodsNo[] = $tmp[0];
                } else $stockCnt[$k] = $getData['option']['stockCntFix'][$tmp[0]][$tmp[1]];

            }

            //옵션 일괄 변경
            $optionData['optionSno'] = $arrOptionSno;
            $optionData['optionViewFl'] = $optionViewFl;
            $optionData['optionSellFl'] = $optionSellFl;
            $optionData['stockCnt'] = $stockCnt;
            $optionData['arrGoodsNo'] = $arrGoodsNo;

            $this->setBatchUpdateOption($optionData, array('optionViewFl', 'optionSellFl', 'stockCnt'));

            //상품 일괄변경
            $goodsData['arrGoodsNo'] = $arrGoodsNo;
            $goodsData['goodsSellFl'] = $goodsSellFl;
            $goodsData['goodsSellMobileFl'] = $goodsSellMobileFl;
            $goodsData['soldOutFl'] = $soldOutFl;
            $goodsData['goodsDisplayFl'] = $goodsDisplayFl;
            $goodsData['goodsDisplayMobileFl'] = $goodsDisplayMobileFl;


            $applyFl = $this->setBatchUpdate($goodsData, array('goodsSellFl', 'goodsDisplayFl','goodsSellMobileFl', 'goodsDisplayMobileFl', 'soldOutFl'));

        } else { //전체 조건 수정

            $arrOptionSno = [];
            $arrGoodsNo = [];
            if ($getData['arrGoodsNo']) {
                foreach ($getData['arrGoodsNo'] as $k => $v) {
                    $tmp = explode("_", $v);
                    $arrOptionSno[$k] = $tmp[1];
                    $arrGoodsNo[$k] = $tmp[0];

                }

            }

            //전체 선택 한 경우
            if (gd_isset($getData['batchAll']) == 'y') {
                if (!is_array($getData['queryAll'])) {
                    throw new \Exception(__('조건에 대해 처리중 오류가 발생했습니다.'), 500);
                }

                foreach ($getData['queryAll'] as $key => $val) {
                    if ($key == 'bind') {
                        $query[$key] = json_decode(Encryptor::decrypt($val));
                    } else {
                        $query[$key] = Encryptor::decrypt($val);
                    }
                }

                $strSQL = 'SELECT g.goodsNo,go.sno FROM ' . DB_GOODS_OPTION . ' go ' . $query['join'] . ' WHERE ' . $query['where'] . ' ORDER BY go.sno ASC';
                $data = $this->db->query_fetch($strSQL, $query['bind']);
                unset($query);

                $arrGoodsNo = array();
                foreach ($data as $key => $val) {
                    $arrGoodsNo[$val['goodsNo']] = $val['goodsNo'];
                    $arrOptionSno[] = $val['sno'];
                }
            }


            $tmp = [];
            if (gd_isset($getData['optionSellFl']) || gd_isset($getData['optionViewFl']) || gd_isset($getData['optionStockFl']) || gd_isset($getData['optionStockCnt'])) {
                if (gd_isset($getData['optionSellFl'])) $tmp[] = "optionSellFl = '" . $getData['optionSellFl'] . "'";
                if (gd_isset($getData['optionViewFl'])) $tmp[] = "optionViewFl = '" . $getData['optionViewFl'] . "'";

                if (gd_isset($getData['optionStockFl']) || gd_isset($getData['optionStockCnt'])) {
                    switch ($getData['optionStockFl']) {
                        case 'p':
                            $tmp[] = "stockCnt = stockCnt + " . $getData['optionStockCnt'];
                            break;
                        case 'm':
                            $tmp[] = "stockCnt = stockCnt - " . $getData['optionStockCnt'];
                            break;
                        case 'c':
                            $tmp[] = "stockCnt =  " . $getData['optionStockCnt'];
                            break;
                    }

                    $totalStockGoodsNo = $arrGoodsNo;
                }

                $this->setBatchUpdateOptionSql(implode(",", $tmp), null, gd_isset($arrOptionSno), null);

            }


            $tmp = [];
            if (gd_isset($getData['goodsDisplayMobileFl']) || gd_isset($getData['goodsSellMobileFl']) || gd_isset($getData['goodsDisplayFl']) || gd_isset($getData['goodsSellFl']) || gd_isset($getData['stockLimit']) || gd_isset($getData['soldOutFl'])) {
                if (gd_isset($getData['goodsDisplayFl'])) $tmp[] = "goodsDisplayFl = '" . $getData['goodsDisplayFl'] . "'";
                if (gd_isset($getData['goodsDisplayMobileFl'])) $tmp[] = "goodsDisplayMobileFl = '" . $getData['goodsDisplayMobileFl'] . "'";
                if (gd_isset($getData['goodsSellFl'])) $tmp[] = "goodsSellFl = '" . $getData['goodsSellFl'] . "'";
                if (gd_isset($getData['goodsSellMobileFl'])) $tmp[] = "goodsSellMobileFl = '" . $getData['goodsSellMobileFl'] . "'";
                if (gd_isset($getData['soldOutFl'])) $tmp[] = "soldOutFl = '" . $getData['soldOutFl'] . "'";
                if (gd_isset($getData['stockLimit'])) $tmp[] = "stockFl = 'n'";


                $applyFl =   $this->setBatchUpdateSql(implode(",", $tmp), gd_isset($arrGoodsNo), "goodsDisplayFl,goodsSellFl,goodsDisplayMobileFl,goodsSellMobileFl,stockFl,soldOutFl");
            }


        }

        //전체 재고량 체크
        if ($totalStockGoodsNo) {
            foreach ($totalStockGoodsNo as $k => $v) {
                $stockLogData = $this->setGoodsStock($v);
                LogHandler::wholeLog('goods', 'stock', 'batch', $v, $v, $stockLogData);
            }
        }

        return $applyFl;

    }


    /**
     * 일괄 배송정보 변경
     *
     * @param array $arrGoodsNo 일괄 처리할 goodsNo 배열
     * @param array $fieldInfo 일괄 처리할 field 명 (string or array)
     * @param array $valueInfo 일괄 처리할 field 값 (string or array or null)
     */
    public function setBatchDelivery($getData)
    {
        $arrGoodsNo = $this->setBatchGoodsNo(gd_isset($getData['batchAll']), gd_isset($getData['arrGoodsNo']), gd_isset($getData['queryAll']));

        $applyFl = $this->setBatchGoods($arrGoodsNo, 'deliverySno', $getData['deliverySno']);
        return $applyFl;
    }

    /**
     * 일괄 네이버쇼핑 노출 변경
     *
     * @param array $arrGoodsNo 일괄 처리할 goodsNo 배열
     * @param array $fieldInfo 일괄 처리할 field 명 (string or array)
     * @param array $valueInfo 일괄 처리할 field 값 (string or array or null)
     */
    public function setBatchNaverConfig($getData)
    {
        $arrGoodsNo = $this->setBatchGoodsNo(gd_isset($getData['batchAll']), gd_isset($getData['arrGoodsNo']), gd_isset($getData['queryAll']));
        $applyFl = $this->setBatchGoods($arrGoodsNo, 'naverFl', $getData['naverFl']);
        return $applyFl;
    }

    /**
     * 일괄 상품 정보 변경
     *
     * @param array $arrGoodsNo 일괄 처리할 goodsNo 배열
     * @param array $fieldInfo 일괄 처리할 field 명 (string or array)
     * @param array $valueInfo 일괄 처리할 field 값 (string or array or null)
     */
    protected function setBatchGoods($arrGoodsNo, $fieldInfo, $valueInfo = null)
    {
        // 상품 정보 변경할 항목 체크
        if (is_array($arrGoodsNo) === false || empty($arrGoodsNo) || empty($fieldInfo)) {
            throw new \Exception(__('일괄 처리할 데이터오류로 인해 처리가 되지 않습니다.'), 500);
        }


        // field 및 field 값 처리
        if (is_array($fieldInfo) === false) {
            $fieldInfo = array($fieldInfo);
            $valueInfo = array($valueInfo);
        }

        // bind를 위한 field 값 배열화
        foreach ($fieldInfo as $key => $val) {
            $arrData[$val] = $valueInfo[$key];
        }

        if (Session::get('manager.isProvider') && Session::get('manager.scmPermissionModify') == 'c') {
            $arrData['applyFl'] = 'a';
            $arrData['applyDt'] = date('Y-m-d H:i:s');
            $arrData['applyType'] = "m";
        } else  $arrData['applyFl'] = 'y';


        //승인관련 하여 이전 데이터 체크
        foreach ($arrGoodsNo as $key => $val) {

            $goodsNo = $this->db->escape($val);

            $goodsData = $this->getGoodsInfo($goodsNo, implode(",", $fieldInfo));

            $arrBind = $this->db->get_binding(DBTableField::getBindField('tableGoods', array_keys($arrData)), $arrData, 'update');
            $this->db->bind_param_push($arrBind['bind'], 's', $goodsNo);
            $this->db->set_update_db(DB_GOODS, $arrBind['param'], 'goodsNo = ?', $arrBind['bind']);

            $this->setGoodsLog("goods", $goodsNo, $goodsData, $arrData);

            unset($arrBind);
        }

        //네이버관련 업데이트
        if ($this->naverConfig['naverFl'] == 'y' || $this->daumConfig['useFl'] == 'y') {
            $this->setGoodsUpdateEp($arrData['applyFl'], $arrGoodsNo);
        }

        // 상품 정보 변경
        //$arrBind = $this->db->get_binding(DBTableField::getBindField('tableGoods', $fieldInfo), $arrData, 'update');
        //$strWhere = 'goodsNo IN (' . implode(',', $goodsNo) . ')';
        //$this->db->set_update_db(DB_GOODS, $arrBind['param'], $strWhere, $arrBind['bind']);

        return $arrData['applyFl'];

    }

    /**
     * 빠른 이동/복사/삭제 - 카테고리 연결
     *
     * @param array $arrGoodsNo 연결할 goodsNo 배열
     * @param array $arrCategoryCd 연결할 카테고리 코드 배열
     */
    public function setBatchLinkCategory($arrGoodsNo, $arrCategoryCd)
    {
        // 연결할 goodsNo 체크
        if (is_array($arrGoodsNo) === false || empty($arrGoodsNo)) {
            throw new \Exception(__('일괄 처리할 데이터오류로 인해 처리가 되지 않습니다.'), 500);
        }

        // 연결할 카테고리 체크
        if (is_array($arrCategoryCd) === false || empty($arrCategoryCd)) {
            throw new \Exception(__('연결할 카테고리는 필수 항목입니다.'), 500);
        }

        // 카테고리 정보 저장
        $updateGoodsNo = [];
        foreach ($arrGoodsNo as $key => $goodsNo) {

            // 기존 카테고리 정보
            $arrData = array();
            $getLink = $this->getGoodsLinkCategory($goodsNo);

            if (is_array($getLink)) {
                foreach ($getLink as $cKey => $cVal) {
                    foreach ($cVal as $field => $value) {
                        $arrData[$field][] = $value;
                    }
                }
            }

            // 연결할 카테고리 값 정의
            $originCateCd = $arrData;
            foreach ($arrCategoryCd as $cateKey => $cateVal) {
                // 대표 카테고리를 선택하지 않았을 경우, 최상단 카테고리로 설정
                if ($cateKey === 0) $cateCd = $cateVal;

                $existCateCd = array_search($cateVal, $arrData['cateCd']);
                if (is_numeric($existCateCd)) {
                    if (in_array($cateVal, $originCateCd['cateCd']) && $originCateCd['cateLinkFl'][$existCateCd] !== 'y') {
                        // 기존 등록된 카테고리 연결값 변경
                        $tmpLinkFl[$cateVal] = 'y';
                    } else if ($arrData['cateLinkFl'][$existCateCd] === 'n') {
                        // 상,하위 카테고리를 동시에 연결할 경우
                        $arrData['cateLinkFl'][$existCateCd] = 'y';
                    }
                } else {
                    $length = strlen($cateVal);
                    for ($i = 1; $i <= ($length / DEFAULT_LENGTH_CATE); $i++) {
                        $tmpCateCd = substr($cateVal, 0, ($i * DEFAULT_LENGTH_CATE));
                        $arrData['cateCd'][] = $tmpCateCd;

                        if ($tmpCateCd == $cateVal) {
                            $arrData['cateLinkFl'][] = 'y';
                            $tmpLinkFl[$tmpCateCd] = 'y';
                        } else {
                            $arrData['cateLinkFl'][] = 'n';
                        }
                    }
                }
            }

            // 다중 카테고리 유효성 체크
            $chkData = $this->getGoodsCategoyCheck($arrData, $goodsNo);

            // 유효성 체크 후 추가할 데이터만 insert
            foreach ($chkData['cateCd'] as $cKey => $cVal) {

                if (empty($chkData['sno'][$cKey])) {
                    $getData['goodsNo'] = $goodsNo;
                    $getData['cateCd'] = $chkData['cateCd'][$cKey];
                    $getData['cateLinkFl'] = $chkData['cateLinkFl'][$cKey];
                    $getData['goodsSort'] = $chkData['goodsSort'][$cKey];

                    $arrBind = $this->db->get_binding(DBTableField::tableGoodsLinkCategory(), $getData, 'insert');
                    $this->db->set_insert_db(DB_GOODS_LINK_CATEGORY, $arrBind['param'], $arrBind['bind'], 'y');

                    $updateData[] = $getData;
                    unset($arrBind, $getData);
                } else {
                    if(in_array($cVal,array_keys($tmpLinkFl)) && $chkData['cateLinkFl'][$cKey] != $tmpLinkFl[$cVal]) {
                        $arrBind = [];
                        $arrUpdate[] = 'cateLinkFl =?';
                        $this->db->bind_param_push($arrBind, 's', $tmpLinkFl[$cVal]);
                        $this->db->bind_param_push($arrBind, 's', $chkData['sno'][$cKey]);
                        $this->db->set_update_db(DB_GOODS_LINK_CATEGORY, $arrUpdate, 'sno = ?', $arrBind);
                        unset($arrUpdate);
                        unset($arrBind);
                    }
                }
            }

            if ($updateData) $this->setGoodsLog('category', $goodsNo, $getLink, array('update' => $updateData));

            // 대표 카테고리 설정
            $getData = $this->getGoodsInfo($goodsNo, 'cateCd');
            if (empty(Request::post()->get('categoryRepresent')) === false) {
                $this->setBatchGoods(array($goodsNo), 'cateCd', Request::post()->get('categoryRepresent'));
            } else if (empty($getData['cateCd'])) {
                $this->setBatchGoods(array($goodsNo), 'cateCd', $cateCd);
            } else {
                $updateGoodsNo[] = $goodsNo;
            }

        }

        //상태 일괄 업데이트
        $applyFl = $this->setGoodsApplyUpdate($updateGoodsNo, 'modify');

        return $applyFl;

    }

    /**
     * 빠른 이동/복사/삭제 - 카테고리 이동
     * 카테고리 해제 -> 해당 카테고리 연결 -> 대표 카테고리 설정
     *
     * @param array $arrGoodsNo 이동할 goodsNo 배열
     * @param array $arrCategoryCd 이동할 카테고리 코드 배열
     */
    public function setBatchMoveCategory($arrGoodsNo, $arrCategoryCd)
    {

        // 이동할 goodsNo 체크
        if (is_array($arrGoodsNo) === false || empty($arrGoodsNo)) {
            throw new \Exception(__('일괄 처리할 데이터오류로 인해 처리가 되지 않습니다.'), 500);
        }

        // 이동할 카테고리 체크
        if (is_array($arrCategoryCd) === false || empty($arrCategoryCd)) {
            throw new \Exception(__('연결할 카테고리는 필수 항목입니다.'), 500);
        }

        // 카테고리 해제를 함
        $this->setBatchUnlinkCategory($arrGoodsNo);

        // 이동할 카테고리 정보
        $arrData = array();
        foreach ($arrCategoryCd as $cateKey => $cateVal) {
            if ($cateKey === 0) $cateCd = $cateVal;
            $existCateCd = array_search($cateVal, $arrData['cateCd']);

            if (is_numeric($existCateCd)) {
                if ($arrData['cateLinkFl'][$existCateCd] == 'n') {
                    $arrData['cateLinkFl'][$existCateCd] = 'y';
                }
            } else {
                $length = strlen($cateVal);
                for ($j = 1; $j <= ($length / DEFAULT_LENGTH_CATE); $j++) {
                    $tmpCateCd = substr($cateVal, 0, ($j * DEFAULT_LENGTH_CATE));
                    $arrData['cateCd'][] = $tmpCateCd;
                    if ($tmpCateCd == $cateVal) {
                        $arrData['cateLinkFl'][] = 'y';
                    } else {
                        $arrData['cateLinkFl'][] = 'n';
                    }
                }
            }
        }

        // 카테고리 정보 저장
        foreach ($arrGoodsNo as $key => $goodsNo) {

            // 다중 카테고리 유효성 체크
            $setData = $this->getGoodsCategoyCheck($arrData, $goodsNo);

            // 공통 키값
            $arrDataKey = array('goodsNo' => $goodsNo);

            // 카테고리 정보 저장
            $cateLog = $this->db->set_compare_process(DB_GOODS_LINK_CATEGORY, $setData, $arrDataKey, null);
            if ($cateLog) {
                $this->setGoodsLog('category', $goodsNo, '', $cateLog);
            }

            // 대표 카테고리 여부를 체크후 없으면 선택된 카테고리 중 최상단 카테고리를 대표 카테고리로 설정
            if (empty(Request::post()->get('categoryRepresent')) === false) {
                $applyFl = $this->setBatchGoods(array($goodsNo), 'cateCd', Request::post()->get('categoryRepresent'));
            } else {
                $applyFl = $this->setBatchGoods($arrGoodsNo, 'cateCd', $cateCd);
            }
        }

        return $applyFl;
    }

    /**
     * 빠른 이동/복사/삭제 - 카테고리 복사
     * 상품 복사 -> 해당 카테고리 이동
     *
     * @param array $arrGoodsNo 복사할 goodsNo 배열
     * @param array $arrCategoryCd 연결할 카테고리 코드 배열
     */
    public function setBatchCopyCategory($arrGoodsNo, $arrCategoryCd)
    {
        // 복사할 goodsNo 체크
        if (is_array($arrGoodsNo) === false || empty($arrGoodsNo)) {
            throw new \Exception(__('일괄 처리할 데이터오류로 인해 처리가 되지 않습니다.'), 500);
        }

        // 상품 복사
        foreach ($arrGoodsNo as $key => $goodsNo) {
            $arrNewGoodsNo[] = $this->setCopyGoods($goodsNo);
        }
        unset($arrGoodsNo);

        // 카테고리 이동
        $applyFl = $this->setBatchMoveCategory($arrNewGoodsNo, $arrCategoryCd);
        return $applyFl;
    }

    /**
     * 빠른 이동/복사/삭제 - 브랜드 교체
     *
     * @param array $arrGoodsNo 교체할 goodsNo 배열
     * @param array $brandCd 교체할 브랜드 코드
     */
    public function setBatchLinkBrand($arrGoodsNo, $brandCd)
    {
        // 교체할 goodsNo 체크
        if (is_array($arrGoodsNo) === false || empty($arrGoodsNo)) {
            throw new \Exception(__('일괄 처리할 데이터오류로 인해 처리가 되지 않습니다.'), 500);
        }

        // 교체할 브랜드 체크
        if (empty($brandCd)) {
            throw new \Exception(__('연결할 브랜드는 필수 항목입니다.'), 500);
        }

        // 브랜드 해제를 함
        $this->setBatchUnlinkBrand($arrGoodsNo);

        // 카테고리 정보 저장
        foreach ($arrGoodsNo as $key => $goodsNo) {

            // 다중 카테고리 유효성 체크
            $arrData = $this->getGoodsBrandCheck($brandCd, null, $goodsNo);

            // 공통 키값
            $arrDataKey = array('goodsNo' => $goodsNo);

            // 브랜드 정보 저장
            $this->db->set_compare_process(DB_GOODS_LINK_BRAND, $arrData, $arrDataKey, null);
        }

        // 일괄 상품 정보 변경
        $applyFl = $this->setBatchGoods($arrGoodsNo, 'brandCd', $brandCd);
        return $applyFl;
    }

    /**
     * 빠른 이동/복사/삭제 - 카테고리 해제
     *
     * @param array $arrGoodsNo 해제할 goodsNo 배열
     */
    public function setBatchUnlinkCategory($arrGoodsNo,$cateCd)
    {
        // 해제할 goodsNo 체크
        if (is_array($arrGoodsNo) === false || empty($arrGoodsNo)) {
            throw new \Exception(__('일괄 처리할 데이터오류로 인해 처리가 되지 않습니다.'), 500);
        }

        // goodsNo escape 처리 (혹시나 해서.. ^^;)
        foreach ($arrGoodsNo as $key => $val) {
            $goodsNo[] = $this->db->escape($val);
        }

        // 일괄 상품 정보 변경
        if($cateCd) {
            //카테고리 부분 업데이트 필요
            $strSQL = 'UPDATE ' . DB_GOODS . ' SET cateCd = null WHERE goodsNo IN (' . implode(',', $goodsNo) . ') AND cateCd IN (' . implode(',', $cateCd) . ')';
            $this->db->query($strSQL);
        } else {
            $applyFl =  $this->setBatchGoods($arrGoodsNo, 'cateCd', null);
        }

        // 카테고리 링크 삭제
        if($cateCd) $strWhere = 'goodsNo IN (' . implode(',', $goodsNo) . ') AND cateCd IN (' . implode(',', $cateCd) . ')';
        else  $strWhere = 'goodsNo IN (' . implode(',', $goodsNo) . ')';
        $this->db->set_delete_db(DB_GOODS_LINK_CATEGORY, $strWhere);

        return $applyFl;
    }

    /**
     * 빠른 이동/복사/삭제 - 브랜드 해제
     *
     * @param array $arrGoodsNo 해제할 goodsNo 배열
     */
    public function setBatchUnlinkBrand($arrGoodsNo,$cateCd)
    {
        // 해제할 goodsNo 체크
        if (is_array($arrGoodsNo) === false || empty($arrGoodsNo)) {
            throw new \Exception(__('일괄 처리할 데이터오류로 인해 처리가 되지 않습니다.'), 500);
        }

        // goodsNo escape 처리 (혹시나 해서.. ^^;)
        foreach ($arrGoodsNo as $key => $val) {
            $goodsNo[] = $this->db->escape($val);
        }

        // 일괄 상품 정보 변경
        if($cateCd) {
            //브랜드 부분 업데이트 필요
            $strSQL = 'UPDATE ' . DB_GOODS . ' SET brandCd = null WHERE goodsNo IN (' . implode(',', $goodsNo) . ') AND brandCd IN (' . implode(',', $cateCd) . ')';
            $this->db->query($strSQL);
        } else {
            $applyFl =  $this->setBatchGoods($arrGoodsNo, 'brandCd', null);
        }

        // 카테고리 링크 삭제setBatchUnlinkBrand
        if($cateCd) $strWhere = 'goodsNo IN (' . implode(',', $goodsNo) . ') AND cateCd IN (' . implode(',', $cateCd) . ')';
        else  $strWhere = 'goodsNo IN (' . implode(',', $goodsNo) . ')';
        $this->db->set_delete_db(DB_GOODS_LINK_BRAND, $strWhere);

        return $applyFl;
    }

    /**
     * getAdminListDisplayMain
     *
     * @param string $kind
     * @return mixed
     */
    public function getAdminListDisplayTheme($kind = 'main', $mode = null)
    {
        if($kind === 'event'){
            unset($this->arrBind, $this->arrWhere);
            $this->arrBind = $this->arrWhere = [];
        }

        $getValue = Request::get()->toArray();

        // --- 검색 설정
        $this->setSearchDisplayTheme($getValue);
        $this->arrWhere[] = sprintf("kind = '%s' ", $kind);
        // --- 정렬 설정
        $sort = gd_isset($getValue['sort']);
        if (empty($sort)) {
            $sort = 'dt.regDt desc';
        }

        if ($mode == 'layer') {
            // --- 페이지 기본설정
            if (gd_isset($getValue['pagelink'])) {
                $getValue['page'] = (int)str_replace('page=', '', preg_replace('/^{page=[0-9]+}/', '', gd_isset($getValue['pagelink'])));
            } else {
                $getValue['page'] = 1;
            }
            gd_isset($getValue['pageNum'], '10');
        } else {
            // --- 페이지 기본설정
            gd_isset($getValue['page'], 1);
            gd_isset($getValue['pageNum'], 10);
        }

        $this->db->strField = " count(*) as cnt";
        $this->db->strWhere = sprintf("kind = '%s' ", $kind);
        $query = $this->db->query_complete();
        $strSQL = 'SELECT ' . array_shift($query) . ' FROM ' . DB_DISPLAY_THEME . ' as dt ' . implode(' ', $query);
        list($result) = $this->db->query_fetch($strSQL);
        //   $this->arrBind = null;
        $totalCnt = $result['cnt'];

        $page = \App::load('\\Component\\Page\\Page', $getValue['page']);
        $page->page['list'] = $getValue['pageNum']; // 페이지당 리스트 수
        $page->recode['amount'] = $totalCnt; // 전체 레코드 수
        $page->setPage();
        $page->setUrl(\Request::getQueryString());


        if($kind =='main') {
            // 현 페이지 결과
            $join[] = ' INNER JOIN ' . DB_DISPLAY_THEME_CONFIG . ' as dtc ON (dtc.themeCd = dt.themeCd OR dtc.themeCd = dt.mobileThemeCd) ';
            $join[] = ' LEFT OUTER JOIN ' . DB_MANAGER . ' as m ON m.sno = dt.managerNo ';

            $this->db->strJoin = implode('', $join);

            $this->db->strField = "SQL_CALC_FOUND_ROWS dt.* , dtc.themeNm as displayThemeNm,m.managerId,m.managerNm,m.managerNickNm,m.isDelete";
            $this->db->strWhere = implode(' AND ', gd_isset($this->arrWhere));
            $this->db->strOrder = $sort;
            if($mode !='layer') $this->db->strLimit = $page->recode['start'] . ',' . $getValue['pageNum'];
            $query = $this->db->query_complete();
            $strSQL = 'SELECT ' . array_shift($query) . ' FROM ' . DB_DISPLAY_THEME . ' as dt ' . implode(' ', $query);
            $data = $this->db->query_fetch($strSQL, $this->arrBind);
        } else {
            $this->db->strField = "SQL_CALC_FOUND_ROWS dt.*   ,m.managerId,m.managerNm,m.managerNickNm,m.isDelete";
            $this->db->strWhere = implode(' AND ', gd_isset($this->arrWhere));
            $this->db->strOrder = $sort;
            $this->db->strLimit = $page->recode['start'] . ',' . $getValue['pageNum'];
            $query = $this->db->query_complete();
            $strSQL = 'SELECT ' . array_shift($query) . ' FROM ' . DB_DISPLAY_THEME . ' as dt LEFT OUTER JOIN ' . DB_MANAGER . ' as m ON m.sno = dt.managerNo ' . implode(' ', $query);
            $data = $this->db->query_fetch($strSQL, $this->arrBind);
        }
        Manager::displayListData($data);
        // 검색 레코드 수
        list($page->recode['total']) = $this->db->fetch('SELECT FOUND_ROWS()', 'row');
        $page->setPage();


        foreach ($data as &$val) {
            if ($val['kind'] == 'event') {
                if(!is_object($eventGroupTheme)){
                    $eventGroupTheme = \App::load('\\Component\\Promotion\\EventGroupTheme');
                }
                $val['eventSaleUrl'] = $this->getEventSaleUrl($val['sno'], false);
                $val['MobileEventSaleUrl'] = $this->getEventSaleUrl($val['sno'], true);
                $val['writer'] = sprintf('%s <br>(%s)', $val['managerNm'], $val['managerId']);
                $_device = gd_isset($val['pcFl'], 'y') . gd_isset($val['mobileFl'], 'y');
                switch ($_device) {
                    case 'yy' :
                        $val['displayDeviceText'] = __('PC+모바일');
                        break;
                    case 'yn' :
                        $val['displayDeviceText'] = __('PC쇼핑몰');
                        break;
                    case 'ny' :
                        $val['displayDeviceText'] = __('모바일');
                        break;
                }

                $nowDate = strtotime(date("Y-m-d H:i:s"));
                $displayStartDate = strtotime($val['displayStartDate']);
                $displayEndDate = strtotime($val['displayEndDate']);
                if ($nowDate < $displayStartDate) {
                    $val['statusText'] = __('대기');
                } else if ($nowDate > $displayStartDate && $nowDate < $displayEndDate) {
                    $val['statusText'] = __('진행중');
                } else if ($nowDate > $displayEndDate) {
                    $val['statusText'] = __('종료');
                } else {
                    $val['statusText'] = __('오류');
                }

                switch($val['displayCategory']){
                    case 'g' :
                        $eventGroupArray = $eventGroupTheme->getSimpleData($val['sno']);
                        $val['eventGroupArray'] = $eventGroupArray;
                        $val['displayCategoryText'] = '그룹형';
                        break;

                    case 'n' : default :
                    $val['displayCategoryText'] = '일반형';
                    break;
                }
            }
        }

        // 각 데이터 배열화
        $getData['data'] = gd_htmlspecialchars_stripslashes(gd_isset($data));
        $getData['sort'] = $sort;
        $getData['search'] = gd_htmlspecialchars($this->search);
        $getData['checked'] = $this->checked;
        $getData['selected'] = $this->selected;

        return $getData;
    }

    /**
     * setSearchThemeConfig
     *
     * @param $searchData
     * @param int|string $searchPeriod
     */
    public function setSearchDisplayTheme($searchData, $searchPeriod = '-1')
    {
        // 검색을 위한 bind 정보
        $fieldType = DBTableField::getFieldTypes('tableDisplayTheme');

        $this->search['combineSearch'] = array('all' => __('=통합검색='), 'themeNm' => __('분류명'), 'themeDescription' => __('분류 설명'));
        $this->search['eventSaleListSelect'] = array('all' => __('=통합검색='), 'themeNm' => __('기획전명'), 'writer' => __('등록자'));    //기획전

        //검색설정
        $this->search['sortList'] = array(
            'dt.regDt asc' => __('등록일 ↓'),
            'dt.regDt desc' => __('등록일 ↑'),
            'dt.themeNm asc' => __('테마명 ↓'),
            'dt.themeNm desc' => __('테마명 ↑'),
            'displayThemeNm asc' => __('선택테마 ↓'),
            'displayThemeNm desc' => __('선택테마 ↑')
        );

        $this->search['eventSaleSortList'] = array(
            'dt.regDt asc' => __('등록일 ↓'),
            'dt.regDt desc' => __('등록일 ↑'),
            'dt.displayStartDate asc' => __('시작일 ↓'),
            'dt.displayStartDate desc' => __('시작일 ↑'),
            'dt.displayEndDate asc' => __('종료일 ↓'),
            'dt.displayEndDate desc' => __('종료일 ↑'),
            'dt.themeNm asc' => __('기획전명 ↓'),
            'dt.themeNm desc' => __('기획전명 ↑'),
        );

        // -
        $this->search['sort'] = gd_isset($searchData['sort'], 'dt.regDt desc');
        $this->search['detailSearch'] = gd_isset($searchData['detailSearch']);
        $this->search['searchDateFl'] = gd_isset($searchData['searchDateFl'], 'regDt');
        $this->search['searchPeriod'] = gd_isset($searchData['searchPeriod'], '-1');
        $this->search['key'] = gd_isset($searchData['key']);
        $this->search['keyword'] = gd_isset($searchData['keyword']);
        $this->search['displayFl'] = gd_isset($searchData['displayFl'], 'all');
        $this->search['mobileFl'] = gd_isset($searchData['mobileFl'], 'all');
        $this->search['device'] = gd_isset($searchData['device']);
        $this->search['displayCategory'] = gd_isset($searchData['displayCategory']);
        $this->search['statusText'] = gd_isset($searchData['statusText']);
        $this->search['sno'] = gd_isset($searchData['sno']);
        if ($this->search['device'] && $searchData['device'] != 'all') {
            $_pcFl = substr($searchData['device'], 0, 1);
            $_mobileFl = substr($searchData['device'], 1, 1);
            $this->arrWhere[] = 'dt.pcFl = ?';
            $this->arrWhere[] = 'dt.mobileFl = ?';
            $this->db->bind_param_push($this->arrBind, $fieldType['pcFl'], $_pcFl);
            $this->db->bind_param_push($this->arrBind, $fieldType['mobileFl'], $_mobileFl);
        }

        if ($this->search['searchPeriod'] < 0) {
            $this->search['searchDate'][] = gd_isset($searchData['searchDate'][0]);
            $this->search['searchDate'][] = gd_isset($searchData['searchDate'][1]);
        } else {
            $this->search['searchDate'][] = gd_isset($searchData['searchDate'][0], date('Y-m-d', strtotime('-7 day')));
            $this->search['searchDate'][] = gd_isset($searchData['searchDate'][1], date('Y-m-d'));
        }

        $this->checked['mobileFl'][$searchData['mobileFl']] = $this->checked['displayFl'][$searchData['displayFl']] = $this->checked['displayCategory'][$searchData['displayCategory']] = $this->checked['device'][$searchData['device']] = $this->checked['statusText'][$searchData['statusText']] = "checked='checked'";
        $this->checked['searchPeriod'][$this->search['searchPeriod']] = "active";
        $this->selected['searchDateFl'][$this->search['searchDateFl']] = $this->selected['sort'][$this->search['sort']] = "selected='selected'";

        // 처리일자 검색
        if ($this->search['searchDateFl'] && $this->search['searchDate'][0] && $this->search['searchDate'][1]) {
            $this->arrWhere[] = 'dt.' . $this->search['searchDateFl'] . ' BETWEEN ? AND ?';
            $this->db->bind_param_push($this->arrBind, 's', $this->search['searchDate'][0] . ' 00:00:00');
            $this->db->bind_param_push($this->arrBind, 's', $this->search['searchDate'][1] . ' 23:59:59');
        }

        // 테마명 검색
        if ($this->search['key'] && $this->search['keyword']) {
            if ($this->search['key'] == 'all') {
                $tmpWhere = array('dt.themeNm', 'dt.themeDescription');
                $arrWhereAll = array();
                foreach ($tmpWhere as $keyNm) {
                    $arrWhereAll[] = '(' . $keyNm . ' LIKE concat(\'%\',?,\'%\'))';
                    $this->db->bind_param_push($this->arrBind, 's', $this->search['keyword']);
                }
                $this->arrWhere[] = '(' . implode(' OR ', $arrWhereAll) . ')';
            } else {
                if ($this->search['key'] == 'writer') {
                    $this->arrWhere[] = '(m.managerId LIKE concat(\'%\',?,\'%\') OR m.managerNm LIKE concat(\'%\',?,\'%\'))';
                    $this->db->bind_param_push($this->arrBind, 's', $this->search['keyword']);
                    $this->db->bind_param_push($this->arrBind, 's', $this->search['keyword']);
                } else {
                    $this->arrWhere[] = 'dt.' . $this->search['key'] . ' LIKE concat(\'%\',?,\'%\')';
                    $this->db->bind_param_push($this->arrBind, 's', $this->search['keyword']);
                }
            }
        }

        // 구매 상품 범위 검색
        if ($this->search['displayFl'] != 'all') {
            $this->arrWhere[] = 'displayFl = ?';
            $this->db->bind_param_push($this->arrBind, $fieldType['displayFl'], $this->search['displayFl']);
        }

        // 쇼핑몰유형
        if ($this->search['mobileFl'] != 'all') {
            $this->arrWhere[] = 'dt.mobileFl = ?';
            $this->db->bind_param_push($this->arrBind, $fieldType['mobileFl'], $this->search['mobileFl']);
        }

        //진열유형
        if ($this->search['displayCategory']) {
            $this->arrWhere[] = 'dt.displayCategory = ?';
            $this->db->bind_param_push($this->arrBind, $fieldType['displayCategory'], $this->search['displayCategory']);
        }

        //진행상태
        if ($this->search['statusText']) {
            $nowDate = date("Y-m-d H:i:s");
            switch($this->search['statusText']){
                //대기
                case 'product':
                    $this->arrWhere[] = '? < dt.displayStartDate';
                    $this->db->bind_param_push($this->arrBind, $fieldType['displayStartDate'], $nowDate);
                    break;

                //진행중
                case 'order':
                    $this->arrWhere[] = '(? > dt.displayStartDate && ? < dt.displayEndDate)';
                    $this->db->bind_param_push($this->arrBind, $fieldType['displayStartDate'], $nowDate);
                    $this->db->bind_param_push($this->arrBind, $fieldType['displayEndDate'], $nowDate);
                    break;

                //종료
                case 'delivery':
                    $this->arrWhere[] = '? > dt.displayEndDate';
                    $this->db->bind_param_push($this->arrBind, $fieldType['displayEndDate'], $nowDate);
                    break;
            }
        }

        //sno로 검색
        if ((int)$this->search['sno'] > 0) {
            $this->arrWhere[] = 'dt.sno = ?';
            $this->db->bind_param_push($this->arrBind, 'i', $this->search['sno']);
        }

        if (empty($this->arrBind)) {
            $this->arrBind = null;
        }

    }

    /**
     * getDataThemeCongif
     *
     * @param null $sno
     * @return mixed
     * @internal param null $themeCd
     */
    public function getDataDisplayTheme($sno = null)
    {
        // --- 등록인 경우
        if (!$sno) {
            // 기본 정보
            $data['mode'] = 'register';
            // 기본값 설정
            DBTableField::setDefaultData('tableDisplayTheme', $data);

            // --- 수정인 경우
        } else {
            // 테마 정보
            $data = $this->getDisplayThemeInfo($sno);

            if ($data['kind'] == 'event') $goodsNoData = explode(INT_DIVISION, $data['goodsNo']);
            else $goodsNoData = explode(STR_DIVISION, $data['goodsNo']);

            if ($goodsNoData) {
                unset($data['goodsNo']);

                if($data['kind'] === 'event' && $data['displayCategory'] === 'g'){
                    $eventGroupTheme = \App::load('\\Component\\Promotion\\EventGroupTheme');

                    //기획전 그룹형일 경우
                    $data['eventGroup'] = $eventGroupTheme->getDataEventGroupList($sno);
                }
                else {
                    foreach ($goodsNoData as $k => $v) {
                        if ($v) {
                            $data['goodsNo'][$k] = $this->getGoodsDataDisplay($v);
                        } else {
                            $data['goodsNo'][$k] =[];
                        }
                    }
                }
            }

            if ($data['fixGoodsNo']) {
                $fixGoodsNo = explode(STR_DIVISION, $data['fixGoodsNo']);
                unset($data['fixGoodsNo']);
                foreach ($fixGoodsNo as $k => $v) {
                    if ($v) {
                        $data['fixGoodsNo'][$k] = explode(INT_DIVISION, $v);
                    }
                }
            }

            $data['mode'] = 'modify';
            if ($data['displayStartDate']) {
                $nowDate = strtotime(date("Y-m-d H:i:s"));
                $_displayStartDate = strtotime($data['displayStartDate']);
                $_displayEndDate = strtotime($data['displayEndDate']);
                if ($nowDate < $_displayStartDate) {
                    $data['status'] = 'wait';
                } else if ($nowDate > $_displayStartDate && $nowDate < $_displayEndDate) {
                    $data['status'] = 'active';
                } else if ($nowDate > $_displayEndDate) {
                    $data['status'] = 'end';
                } else {
                    $data['status'] = 'error';
                }

                $displayStartDateObj = date_create($data['displayStartDate']);
                $displayEndDateObj = date_create($data['displayEndDate']);
                unset($data['displayStartDate']);
                unset($data['displayEndDate']);
                $data['displayStartDate']['date'] = date_format($displayStartDateObj, "Y-m-d");
                $data['displayStartDate']['time'] = date_format($displayStartDateObj, "H:i:s");
                $data['displayEndDate']['date'] = date_format($displayEndDateObj, "Y-m-d");
                $data['displayEndDate']['time'] = date_format($displayEndDateObj, "H:i:s");
            }

            $data['eventSaleUrl'] = $this->getEventSaleUrl(Request::get()->get('sno'), false, true);
            $data['mobileEventSaleUrl'] = $this->getEventSaleUrl(Request::get()->get('sno'), true, true);

            gd_isset($data['pcFl'], 'y');
            gd_isset($data['mobileFl'], 'y');

            if($data['sortAutoFl'] =='y') {

                if($data['exceptGoodsNo']) {
                    $data['exceptGoodsNo'] = $this->getGoodsDataDisplay($data['exceptGoodsNo']);
                }

                if($data['exceptCateCd']) {
                    $cate = \App::load('\\Component\\Category\\CategoryAdmin');
                    $tmp['code'] = explode(INT_DIVISION, $data['exceptCateCd']);
                    foreach ($tmp['code'] as $val) {
                        $tmp['name'][] = gd_htmlspecialchars_decode($cate->getCategoryPosition($val));
                    }

                    $data['exceptCateCd'] = $tmp;
                    unset($tmp);
                }

                if($data['exceptBrandCd']) {
                    $brand = \App::load('\\Component\\Category\\BrandAdmin');
                    $tmp['code'] = explode(INT_DIVISION, $data['exceptBrandCd']);
                    foreach ($tmp['code'] as $val) {
                        $tmp['name'][] = gd_htmlspecialchars_decode($brand->getCategoryPosition($val));
                    }

                    $data['exceptBrandCd'] = $tmp;
                    unset($tmp);
                }

                if($data['exceptScmNo']) {
                    $scm = \App::load('\\Component\\Scm\\ScmAdmin');
                    $data['exceptScmNo'] =  $scm->getScmSelectList($data['exceptScmNo']);
                }
            }

            // 기본값 설정
            DBTableField::setDefaultData('tableDisplayTheme', $data);
        }

        $getData['data'] = $data;

        $checked = array();
        $device = gd_isset($data['pcFl'], 'y') . gd_isset($data['mobileFl'], 'y');
        $checked['device'][$device] = 'checked="checked"';

        $checked['sortAutoFl'][$data['sortAutoFl']] = $checked['moreTopFl'][$data['moreTopFl']] = $checked['moreBottomFl'][$data['moreBottomFl']] = $checked['mobileFl'][$data['mobileFl']] = $checked['displayFl'][$data['displayFl']] = $checked['descriptionSameFl'][$data['descriptionSameFl']] = $checked['displayCategory'][$data['displayCategory']] = "checked='checked'";
        $selected['themeCd'][$data['themeCd']] = "selected='selected'";
        $getData['checked'] = $checked;
        $getData['selected'] = $selected;
        return $getData;
    }


    /**
     * saveInfoThemeConfig
     *
     * @param $arrData
     * @throws Exception
     */
    public function saveInfoDisplayTheme($arrData)
    {
        gd_isset($arrData['kind'], 'main');

        if ($arrData['kind'] == 'event') {
            $arrData['displayFl'] = 'y';
            $arrData['mobileFl'] = $arrData['pcFl'] = 'n';
            $arrData['pcFl'] = substr($arrData['device'], 0, 1);
            $arrData['mobileFl'] = substr($arrData['device'], 1, 1);
        }

        $arrData['managerNo'] = Session::get('manager.sno');
        if ($arrData['displayStartDate'] && $arrData['displayEndDate']) {
            $arrData['displayStartDate'] = $arrData['displayStartDate']['date'] . ' ' . $arrData['displayStartDate']['time'];
            $arrData['displayEndDate'] = $arrData['displayEndDate']['date'] . ' ' . $arrData['displayEndDate']['time'];
        }

        // 테마명 체크
        if (Validator::required(gd_isset($arrData['themeNm'])) === false) {
            throw new \Exception(__('테마명은 필수 항목입니다.'), 500);
        }

        $goodsNoData = [];
        if (is_array($arrData['goodsNoData'])) {
            if (gd_isset($arrData['tabGoodsCnt']) && is_array($arrData['tabGoodsCnt'])) {
                $startGoodsNo = 0;
                foreach ($arrData['tabGoodsCnt'] as $k => $v) {
                    $goodsNoData[] = array_slice($arrData['goodsNoData'], $startGoodsNo, $v);
                    $startGoodsNo += $v;
                }
            } else {
                $goodsNoData[] = $arrData['goodsNoData'];
            }
        } else {
            foreach ($arrData['tabGoodsCnt'] as $k => $v) {
                $goodsNoData[$k] = [];
            }
        }

        if($arrData['sortAutoFl'] =='y') {
            $arrData['goodsNo'] = "";

            if(in_array('goods',$arrData['presentExceptFl']) && $arrData['exceptGoods'] ) {
                $arrData['exceptGoodsNo'] =  implode(INT_DIVISION, $arrData['exceptGoods']);
            }

            if(in_array('category',$arrData['presentExceptFl']) && $arrData['exceptCategory'] ) {
                $arrData['exceptCateCd'] =  implode(INT_DIVISION, $arrData['exceptCategory']);
            }

            if(in_array('brand',$arrData['presentExceptFl']) && $arrData['exceptBrand'] ) {
                $arrData['exceptBrandCd'] =  implode(INT_DIVISION, $arrData['exceptBrand']);
            }

            if(in_array('scm',$arrData['presentExceptFl']) && $arrData['exceptScm'] ) {
                $arrData['exceptScmNo'] =  implode(INT_DIVISION, $arrData['exceptScm']);
            }

            unset($arrData['exceptGoods']);
            unset($arrData['exceptCategory']);
            unset($arrData['exceptBrand']);
            unset($arrData['exceptScm']);

        }  else {
            foreach ($goodsNoData as $k => $v) {
                $goodsNoData[$k] = implode(INT_DIVISION, $v);
            }
            $arrData['goodsNo'] = implode(STR_DIVISION, $goodsNoData);

            unset($arrData['exceptGoodsNo']);
            unset($arrData['exceptCateCd']);
            unset($arrData['exceptBrandCd']);
            unset($arrData['exceptScmNo']);
        }


        $fixGoodsNo = explode(STR_DIVISION, $arrData['fixGoodsNo']);
        //정렬관련
        /*
        if ($arrData['sort']) {

            if (is_array($fixGoodsNo) && gd_isset($fixGoodsNo)) {
                foreach ($fixGoodsNo as $key => $value) {
                    $sortFix = explode(INT_DIVISION, $value);

                    if (is_array($sortFix))

                        foreach ($sortFix as $k => $v) {
                            if ($v) {
                                $sortNum = array_search($v, $goodsNoData[$key]);
                                $fixSortArray[$key][$sortNum] = $v;
                                unset($goodsNoData[$key][$sortNum]);
                            }
                        }

                }
            }

            $sotrGoodsNo = [];
            foreach ($goodsNoData as $key => $value) {
                if ($value) {
                    $goodsArray = [];
                    $goodsList = $this->getGoodsDataDisplay(implode(INT_DIVISION, $value), $arrData['sort']);

                    foreach ($goodsList as $k => $v) {
                        $goodsArray[] = $v['goodsNo'];
                    }

                    if ($fixSortArray[$key]) {
                        $tmp_pre = [];
                        foreach ($fixSortArray[$key] as $k => $v) {
                            $tmp_pre = array_slice($goodsArray, 0, $k);
                            $tmp_next = array_slice($goodsArray, $k);
                            $tmp_pre[] = $v;
                            $goodsArray = array_merge($tmp_pre, $tmp_next);
                        }
                    }
                    $sotrGoodsNo[] = implode(INT_DIVISION, $goodsArray);
                } else {
                    $sotrGoodsNo[] = '';
                }

            }

            $arrData['goodsNo'] = implode(STR_DIVISION, $sotrGoodsNo);

        } else {

            foreach ($goodsNoData as $k => $v) {
                $goodsNoData[$k] = implode(INT_DIVISION, $v);
            }
            $arrData['goodsNo'] = implode(STR_DIVISION, $goodsNoData);

        }
        */

        foreach($arrData['imageDel'] as $k => $v) {
            Storage::disk(Storage::PATH_CODE_DISPLAY)->delete($arrData[$k]);
            $arrData[$k] = '';
        }

        // 테마명 정보 저장
        if ($arrData['mode'] == 'main_modify') {
            $arrBind = $this->db->get_binding(DBTableField::tableDisplayTheme(), $arrData, 'update');
            $this->db->bind_param_push($arrBind['bind'], 's', $arrData['sno']);
            $this->db->set_update_db(DB_DISPLAY_THEME, $arrBind['param'], 'sno = ?', $arrBind['bind']);

        } else {
            $arrBind = $this->db->get_binding(DBTableField::tableDisplayTheme(), $arrData, 'insert');
            $this->db->set_insert_db(DB_DISPLAY_THEME, $arrBind['param'], $arrBind['bind'], 'y');
            $arrData['sno'] = $this->db->insert_id();
        }

        //기획전 그룹형 등록
        if($arrData['kind'] === 'event' && $arrData['displayCategory'] === 'g'){
            $eventGroupTheme = \App::load('\\Component\\Promotion\\EventGroupTheme');

            //기존데이터 삭제
            if(count($arrData['eventGroupDeleteNo']) > 0){
                foreach($arrData['eventGroupDeleteNo'] as $key => $eventGroupNo){
                    if(trim($eventGroupNo) !== ''){
                        $eventGroupTheme->deleteOriginalEventData($eventGroupNo);
                    }
                }
            }

            //등록
            if(count($arrData['eventGroupTmpNo']) > 0){
                foreach($arrData['eventGroupTmpNo'] as $key => $eventGroupTempNo){
                    if(trim($eventGroupTempNo) !== ''){
                        $groupInsertSnoArray[$eventGroupTempNo] = $eventGroupTheme->saveEventGroupTheme($eventGroupTempNo, $arrData['sno']);
                    }
                }
            }

            //순서정렬
            if(count($arrData['eventGroupTmpNo']) > 0 || count($arrData['eventGroupNo']) > 0){
                foreach($arrData['eventGroupTmpNo'] as $key => $eventGroupTempNo){
                    if(trim($groupInsertSnoArray[$eventGroupTempNo]) !== ''){
                        $eventGroupTheme->updateGroupThemeSort($groupInsertSnoArray[$eventGroupTempNo], (int)$key+1);
                    }
                    else if(trim($arrData['eventGroupNo'][$key]) !== ''){
                        $eventGroupTheme->updateGroupThemeSort($arrData['eventGroupNo'][$key], (int)$key+1);
                    }
                    else {}
                }
            }
            unset($groupInsertSnoArray);
        }

        unset($arrBind);

        //이미지명
        $filesValue = Request::files()->toArray();


        if ($filesValue) {
            $fileData = [];
            foreach ($filesValue as $k => $v) {
                $fileDate = $v;
                if ($fileDate['name']) {
                    if (gd_file_uploadable($fileDate, 'image') === true) {  // 이미지 업로드
                        $imageExt = strrchr($v['name'], '.');
                        $fileData[$k] = $arrData['sno']."_".$k. $imageExt; // 이미지명 공백 제거
                        $targetImageFile = $fileData[$k];
                        $tmpImageFile = $v['tmp_name'];
                        Storage::disk(Storage::PATH_CODE_DISPLAY)->upload($tmpImageFile, $targetImageFile);
                    } else {
                        throw new \Exception(__('이미지파일만 가능합니다.'));
                    }
                }
            }

            if($fileData) {
                $arrBind = $this->db->get_binding(DBTableField::getBindField('tableDisplayTheme', array_keys($fileData)), $fileData, 'update');
                $this->db->bind_param_push($arrBind['bind'], 's', $arrData['sno']);
                $this->db->set_update_db(DB_DISPLAY_THEME, $arrBind['param'], 'sno = ?', $arrBind['bind']);
            }
        }

        $this->setRefreshThemeConfig($arrData['kind']);


        if ($arrData['mode'] == 'main_modify') {
            // 전체 로그를 저장합니다.
            LogHandler::wholeLog('display_main', null, 'modify', $arrData['sno'], $arrData['themeNm']);
        }
    }

    public function saveInfoDisplayThemeImage($arrFileData)
    {


        if ($arrFileData) {
            if (gd_file_uploadable($arrFileData, 'image')) {


                $imageExt = strrchr($arrFileData['name'], '.');
                $newImageName =  $giftNo.'_'.rand(1,100) .  $imageExt; // 이미지 공백 제거 및 각 복사에 따른 종류를 화일명에 넣기

                $targetImageFile = $this->imagePath . $newImageName;
                $thumbnailImageFile[] = $this->imagePath . PREFIX_GIFT_THUMBNAIL_SMALL . $newImageName;
                $thumbnailImageFile[] = $this->imagePath . PREFIX_GIFT_THUMBNAIL_LARGE . $newImageName;
                $tmpImageFile = $arrFileData['tmp_name'];

                //                $this->storageHandler->upload($tmpImageFile, $strImageStorage, $targetImageFile);
                //                $this->storageHandler->uploadThumbImage($tmpImageFile, $strImageStorage, $thumbnailImageFile[0],'50');
                //                $this->storageHandler->uploadThumbImage($tmpImageFile, $strImageStorage, $thumbnailImageFile[1],'100');
                Storage::disk(Storage::PATH_CODE_GIFT, $strImageStorage)->upload($tmpImageFile, $targetImageFile);
                Storage::disk(Storage::PATH_CODE_GIFT, $strImageStorage)->upload($tmpImageFile, $thumbnailImageFile[0], ['width' => 50]);
                Storage::disk(Storage::PATH_CODE_GIFT, $strImageStorage)->upload($tmpImageFile, $thumbnailImageFile[1], ['width' => 100]);

                return $newImageName;
            }
        }

    }

    /**
     * saveInfoAddGoods
     *
     * @param $arrData
     * @return string
     * @throws Except
     */
    public function getJsonListDisplayTheme($mobileFl = 'y', $themeCd = 'B')
    {

        $displayConfig = \App::load('\\Component\\Display\\DisplayConfigAdmin');
        $getData = $displayConfig->getInfoThemeConfigCate($themeCd, $mobileFl);

        if (count($getData) > 0) {
            return json_encode(gd_htmlspecialchars_stripslashes($getData));
        } else {
            return false;
        }

    }


    /**
     * refreshThemeConfig
     *
     * @param $themeCd
     */
    public function setRefreshThemeConfig($kind = 'main')
    {

        if ($kind == 'main') {
            $themeCate = "B";
        } else {
            $themeCate = "F";
        }

        $strSQL = "UPDATE " . DB_DISPLAY_THEME_CONFIG . " SET useCnt = 0 WHERE themeCate = '" . $themeCate . "'";
        $this->db->query($strSQL);

        $strSQL = 'SELECT COUNT(themeCd) as count ,themeCd FROM ' . DB_DISPLAY_THEME . ' WHERE kind = "' . $kind . '" GROUP BY themeCd';
        $data = $this->db->query_fetch($strSQL, null);
        if($data) {
            foreach ($data as $k => $v) {
                if ($v['themeCd']) {
                    $arrBind = [];
                    $arrUpdate[] = 'useCnt =' . $v['count'];
                    $this->db->bind_param_push($arrBind, 's', $v['themeCd']);
                    $this->db->set_update_db(DB_DISPLAY_THEME_CONFIG, $arrUpdate, 'themeCd = ?', $arrBind);
                    unset($arrUpdate);
                    unset($arrBind);
                }
            }
        }

        if ($kind == 'event') {
            $strSQL = 'SELECT COUNT(pcThemeCd) as count ,pcThemeCd as themeCd FROM ' . DB_TIME_SALE . ' GROUP BY pcThemeCd';
            $pcData = $this->db->query_fetch($strSQL, null);
            if($pcData) {
                foreach ($pcData as $k => $v) {
                    if ($v['themeCd']) {
                        $arrBind = [];
                        $arrUpdate[] = 'useCnt = useCnt + ' . $v['count'];
                        $this->db->bind_param_push($arrBind, 's', $v['themeCd']);
                        $this->db->set_update_db(DB_DISPLAY_THEME_CONFIG, $arrUpdate, 'themeCd = ?', $arrBind);
                        unset($arrUpdate);
                        unset($arrBind);
                    }
                }
            }

            $strSQL = 'SELECT COUNT(mobileThemeCd) as count ,mobileThemeCd as themeCd FROM ' . DB_TIME_SALE . ' GROUP BY mobileThemeCd';
            $mobileData = $this->db->query_fetch($strSQL, null);
            if($mobileData) {
                foreach ($mobileData as $k => $v) {
                    if ($v['themeCd']) {
                        $arrBind = [];
                        $arrUpdate[] = 'useCnt = useCnt + ' . $v['count'];
                        $this->db->bind_param_push($arrBind, 's', $v['themeCd']);
                        $this->db->set_update_db(DB_DISPLAY_THEME_CONFIG, $arrUpdate, 'themeCd = ?', $arrBind);
                        unset($arrUpdate);
                        unset($arrBind);
                    }
                }
            }
        }
    }


    /**
     * deleteDisplayMain
     *
     * @param $sno
     */
    public function setDeleteDisplayTheme($sno, $themeCd)
    {

        $strSQL = 'SELECT goodsNo, kind, displayCategory FROM ' . DB_DISPLAY_THEME . ' WHERE sno = "' . $sno . '" LIMIT 1';
        $themeData = $this->db->query_fetch($strSQL, null)[0];

        $this->db->bind_param_push($arrBind['bind'], 's', $sno);
        $this->db->set_delete_db(DB_DISPLAY_THEME, 'sno = ?', $arrBind['bind']);

        if($themeData['kind'] === 'event'){
            //기획전 그룹형 그룹 삭제
            if($themeData['displayCategory'] === 'g'){
                $eventGroupTheme = \App::load('\\Component\\Promotion\\EventGroupTheme');
                $eventGroupTheme->deleteEventGroupTheme($sno);
            }
            //기획전 관련설정 삭제
            $otherEventData = gd_policy('promotion.event');
            if(in_array($sno, $otherEventData['otherEventNo'])){
                $deleteArraykey = array_search($sno, $otherEventData['otherEventNo']);
                unset($otherEventData['otherEventNo'][$deleteArraykey]);
                $otherEventData['otherEventNo'] = array_values($otherEventData['otherEventNo']);

                $policy = \App::load('\\Component\\Policy\\Policy');
                $policy->saveEventConfig($otherEventData);
            }
        }

        $this->setRefreshThemeConfig();

    }

    /**
     * 검색페이지 테마 체크
     *
     * @param $themeCd
     */
    public function setRefreshSearchThemeConfig($pcThemeCd, $mobileThemeCd)
    {
        $strSQL = "UPDATE " . DB_DISPLAY_THEME_CONFIG . " SET useCnt = 0 WHERE themeCate = 'A'";
        $this->db->query($strSQL);

        $strSQL = "UPDATE " . DB_DISPLAY_THEME_CONFIG . " SET useCnt = 1 WHERE themeCd = '" . $pcThemeCd . "'";
        $this->db->query($strSQL);

        $strSQL = "UPDATE " . DB_DISPLAY_THEME_CONFIG . " SET useCnt = 1 WHERE themeCd = '" . $mobileThemeCd . "'";
        $this->db->query($strSQL);

    }

    /**
     * 모바일샵 메인 상품 진열 설정의 등록 및 수정에 관련된 정보
     *
     * @param integer $dataSno 상품 테마 sno
     * @return array 모바일샵 메인 상품 진열 및 테마 정보
     */
    public function getDataDisplayThemeMobile($dataSno = null)
    {
        // --- 등록인 경우
        if (is_null($dataSno)) {
            // 기본 정보
            $data['mode'] = 'display_theme_mobile_register';
            $data['sno'] = null;

            // 기본값 설정
            DBTableField::setDefaultData('tableDisplayThemeMobile', $data);

            // --- 수정인 경우
        } else {
            $data = $this->getDisplayThemeMobileInfo($dataSno);
            $data['goodsNo'] = $this->getGoodsDataDisplay($data['goodsNo']);
            $data['mode'] = 'display_theme_mobile_modify';
            // 기본값 설정
            DBTableField::setDefaultData('tableDisplayThemeMobile', $data);
        }

        $checked = array();
        $checked['themeUseFl'][$data['themeUseFl']] = $checked['listType'][$data['listType']] = $checked['imageCd'][$data['imageCd']] = $checked['imageFl'][$data['imageFl']] = $checked['goodsNmFl'][$data['goodsNmFl']] = $checked['priceFl'][$data['priceFl']] = $checked['mileageFl'][$data['mileageFl']] = $checked['soldOutFl'][$data['soldOutFl']] = $checked['soldOutIconFl'][$data['soldOutIconFl']] = $checked['iconFl'][$data['iconFl']] = $checked['shortDescFl'][$data['shortDescFl']] = $checked['brandFl'][$data['brandFl']] = $checked['makerFl'][$data['makerFl']] = 'checked="checked"';

        $getData['data'] = $data;
        $getData['checked'] = $checked;
        return $getData;
    }

    /**
     * 모바일샵 메인 상품 설정 저장
     *
     * @param array $arrData 저장할 정보의 배열
     * @throws Exception
     */
    public function saveInfoDisplayThemeMobile($arrData)
    {
        // 상품 테마명 체크
        if (Validator::required(gd_isset($arrData['themeNm'])) === false) {
            throw new \Exception(__('상품 테마명은 필수 항목입니다.'), 500);
        }

        // 상품번호 배열 재정렬
        if (gd_isset($arrData['goodsNo'])) {
            if (is_array($arrData['goodsNo'])) {
                $arrData['goodsNo'] = implode(INT_DIVISION, $arrData['goodsNo']);
            }
        } else {
            throw new \Exception(__('진열할 상품은 필수 항목입니다.'), 500);
        }

        // 이미지 폴더의 체크
        $imagePath = UserFilePath::data('mobile');
        if ($imagePath->isDir() === false) {
            @mkdir($imagePath);
            @chmod($imagePath, 0707);
        }

        // 이미지 삭제
        if (isset($arrData['imageDel']) === true) {
            foreach ($arrData['imageDel'] as $val) {
                DataFileFactory::create('local')->setImageDelete('mobile', 'mobile', $arrData[$val . 'Tmp'], 'file');
                $arrData[$val . 'Tmp'] = '';
            }
            unset($arrData['imageDel']);
        }

        // 이미지 업로드

        $files = Request::files()->toArray();
        foreach ($files as $key => $val) {
            if (gd_file_uploadable($files[$key], 'image') === true) {
                $arrData[$key] = DataFileFactory::create('local')->saveFile($files[$key]['name'], $files[$key]['tmp_name']);
            } else {
                if (empty($arrData[$key . 'Tmp']) === false) {
                    $arrData[$key] = $arrData[$key . 'Tmp'];
                }
            }
        }

        // insert , update 체크
        if ($arrData['mode'] == 'display_theme_mobile_modify') {
            $chkType = 'update';
        } else {
            $chkType = 'insert';
        }

        // 정보 저장
        if (in_array($chkType, array('insert', 'update'))) {
            $arrBind = $this->db->get_binding(DBTableField::tableDisplayThemeMobile(), $arrData, $chkType);
            if ($chkType == 'insert') {
                $this->db->set_insert_db(DB_DISPLAY_THEME_MOBILE, $arrBind['param'], $arrBind['bind'], 'y');
            }
            if ($chkType == 'update') {
                $this->db->bind_param_push($arrBind['bind'], 'i', $arrData['sno']);
                $this->db->set_update_db(DB_DISPLAY_THEME_MOBILE, $arrBind['param'], 'sno = ?', $arrBind['bind']);
            }
            unset($arrBind);
        }
    }


    /**
     * setSearchCondition
     *
     */
    public function getDateSearchDisplay()
    {
        $selected = array();
        //검색페이지 상품진열
        $getData['goods'] = gd_policy('search.goods');
        if ($getData['goods']) {

            $getData['goods']['sort'] = $getData['goods']['sort'];
            $selected['goods']['mobileThemeCd'][$getData['goods']['mobileThemeCd']] = $selected['goods']['pcThemeCd'][$getData['goods']['pcThemeCd']] = "selected";


            foreach ($getData['goods']['searchType'] as $k => $v) {
                $checked['goods']['searchType'][$v] = "checked";
            }

        } else {
            $checked['goods']['searchType']['keyword'] = "checked";
            $getData['goods']['sort'] = "regDt desc";

        }

        $getData['terms'] = gd_policy('search.terms');
        if(getData['terms']) {
            // 검색창 통합 조건 선택
            if($getData['terms']['settings']) {
                foreach ($getData['terms']['settings'] as $k => $v) {
                    $checked['terms']['settings'][$v] = "checked";
                }
            } else {
                $checked['terms']['settings']['goodsNm'] = "checked";
            }
        } else {
            $checked['terms']['settings']['goodsNm'] = "checked";
        }

        //상품 검색 키워드 설정
        $getData['keyword'] = gd_policy('search.keyword');
        if ($getData['keyword']) {

            $checked['keyword']['keywordFl'][$getData['keyword']['keywordFl']] = "checked";


        } else {
            $checked['keyword']['keywordFl']['n'] = "checked";
        }

        // 최근 검색어 설정
        $getData['recentKeyword'] = gd_policy('search.recentKeyword');
        $selected['recentKeyword']['pcCount'][$getData['recentKeyword']['pcCount']] = $selected['recentKeyword']['mobileCount'][$getData['recentKeyword']['mobileCount']] = "selected";

        //인기검색어 설정
        $getData['hitKeyword'] = gd_policy('search.hitKeyword');


        //QUICK검색 설정
        $getData['quick'] = gd_policy('search.quick');
        if ($getData['quick']) {

            $checked['quick']['mobileFl'][$getData['quick']['mobileFl']] = $checked['quick']['area'][$getData['quick']['area']] = $checked['quick']['quickFl'][$getData['quick']['quickFl']] = "checked";
            foreach ($getData['quick']['searchType'] as $k => $v) {
                $checked['quick']['searchType'][$v] = "checked";
            }

        } else {
            $checked['quick']['searchType']['keyword'] = $checked['quick']['area']['right'] = $checked['quick']['quickFl']['n'] = "checked";
        }


        $getData['set']['searchType'] = array(
            'keyword' => __('검색어'),
            'category' => __('카테고리'),
            'brand' => __('브랜드'),
            'price' => __('가격'),
            'delivery' => __('무료배송'),
            'regdt' => __('최근등록상품'),
            'color' => __('대표색상'),
            'icon' => __('아이콘')
        );
        $getData['set']['terms'] = array(
            'goodsNm' => '상품명',
            'brandNm' => '브랜드',
            'goodsNo' => '상품코드',
            'makerNm' => '제조사',
            'originNm' => '원산지',
            'goodsSearchWord' => '검색키워드'
        );
        $data['data'] = $getData;
        $data['checked'] = $checked;
        $data['selected'] = $selected;

        return $data;
    }

    /**
     * 품절상품진열 정보 저장
     *
     * @param array $arrData 저장할 정보의 배열
     */
    public function saveInfoDisplaySoldOut($arrData)
    {
        $filesValue = Request::files()->toArray();


        $imageArr = array('soldout_overlay', 'soldout_icon', 'soldout_price');


        $image_path = UserFilePath::icon('goods_icon')->www();


        foreach ($arrData['pc'] as $k => $v) {
            if($k == 'deleteOverlayCustomImage' && $v == 'y') {
                @unlink(UserFilePath::getBasePath().self::DEFAULT_PC_CUSTOM_SOLDOUT_OVERLAY_PATH);
            }

            if (in_array($k, $imageArr)) {

                $fileDate = $filesValue['pc_' . $k];

                if ($v == 'custom') {

                    $targetImageFile = '/custom/' . $k;
                    if ($fileDate['name'] && gd_file_uploadable($fileDate, 'image') === true) {
                        // 이미지 업로드
                        $tmpImageFile = $fileDate['tmp_name'];
                        Storage::disk(Storage::PATH_CODE_GOODS_ICON, 'local')->upload($tmpImageFile, $targetImageFile);
                    }
                    $arrData['pc'][$k . '_img'] = $image_path . $targetImageFile;
                } else {
                    switch ($k) {
                        case 'soldout_overlay':
                            $arrData['pc'][$k . '_img'] = $image_path . '/soldout-' . $v . '.png';
                            break;
                        case 'soldout_icon':
                            $arrData['pc'][$k . '_img'] = $arrData['pc'][$k . '_img'] = $image_path . '/' . 'icon_soldout.gif';
                            break;
                    }
                }

            }
        }

        if($arrData['isMobile']) $addText = "m-";
        else  $addText = "";

        foreach ($arrData['mobile'] as $k => $v) {
            if($k == 'deleteOverlayCustomImage' && $v == 'y') {
                @unlink(UserFilePath::getBasePath().self::DEFAULT_MOBILE_CUSTOM_SOLDOUT_OVERLAY_PATH);
            }

            if (in_array($k, $imageArr)) {
                $fileDate = $filesValue['mobile_' . $k];

                if ($v == 'custom') {

                    $targetImageFile = '/custom/' . $k . '_mobile';
                    if ($fileDate['name'] && gd_file_uploadable($fileDate, 'image') === true) {
                        // 이미지 업로드
                        $tmpImageFile = $fileDate['tmp_name'];
                        Storage::disk(Storage::PATH_CODE_GOODS_ICON, 'local')->upload($tmpImageFile, $targetImageFile);

                    }
                    $arrData['mobile'][$k . '_img'] = $image_path . $targetImageFile;
                } else {
                    switch ($k) {
                        case 'soldout_overlay':
                            $arrData['mobile'][$k . '_img'] = $image_path . '/'.$addText.'soldout-' . $v . '.png';
                            break;
                        case 'soldout_icon':
                            $arrData['mobile'][$k . '_img'] = $arrData['mobile'][$k . '_img'] = $image_path . '/' . $addText.'icon_soldout.gif';
                            break;
                    }
                }

            }
        }


        gd_set_policy('soldout.pc', $arrData['pc']);
        gd_set_policy('soldout.mobile', $arrData['mobile']);

    }

    /**
     * getDateSoldOutDisplay
     *
     */
    public function getDateSoldOutDisplay()
    {
        $getData['pc'] = gd_policy('soldout.pc');
        if (!$getData['pc']) {

            $getData['pc']['soldout_overlay'] = "0";
            $getData['pc']['soldout_icon'] = "disable";
            $getData['pc']['soldout_price'] = "price";

        }

        $checked['pc']['soldout_overlay'][$getData['pc']['soldout_overlay']] = $checked['pc']['soldout_icon'][$getData['pc']['soldout_icon']] = $checked['pc']['soldout_price'][$getData['pc']['soldout_price']] = "checked";

        if(file_exists(UserFilePath::getBasePath().self::DEFAULT_PC_CUSTOM_SOLDOUT_OVERLAY_PATH)){
            $getData['pc']['soldout_overlay_custom_exists'] = 'y';
        }
        else {
            $getData['pc']['soldout_overlay_custom_exists'] = 'n';
        }
        $getData['mobile'] = gd_policy('soldout.mobile');


        if (!$getData['mobile']) {

            $getData['mobile']['soldout_overlay'] = "0";
            $getData['mobile']['soldout_icon'] = "disable";
            $getData['mobile']['soldout_price'] = "price";

        }

        $checked['mobile']['soldout_overlay'][$getData['mobile']['soldout_overlay']] = $checked['mobile']['soldout_icon'][$getData['mobile']['soldout_icon']] = $checked['mobile']['soldout_price'][$getData['mobile']['soldout_price']] = "checked";

        if(file_exists(UserFilePath::getBasePath().self::DEFAULT_MOBILE_CUSTOM_SOLDOUT_OVERLAY_PATH)){
            $getData['mobile']['soldout_overlay_custom_exists'] = 'y';
        }
        else {
            $getData['mobile']['soldout_overlay_custom_exists'] = 'n';
        }

        $getData['pc']['defaultCustomSoldoutOverlayPath'] = self::DEFAULT_PC_CUSTOM_SOLDOUT_OVERLAY_PATH;
        $getData['mobile']['defaultCustomSoldoutOverlayPath'] = self::DEFAULT_MOBILE_CUSTOM_SOLDOUT_OVERLAY_PATH;
        $data['data'] = $getData;
        $data['checked'] = $checked;


        return $data;
    }

    /**
     * 선택상품 복구
     * setGoodsReStore
     *
     * @param $arrData
     */
    public function setGoodsReStore($arrData)
    {

        $this->setBatchGoods($arrData['goodsNo'], array('goodsDisplayFl', 'goodsDisplayMobileFl', 'goodsSellFl', 'goodsSellMobileFl', 'delFl'), array($arrData['goodsDisplayFl'], $arrData['goodsDisplayFl'], $arrData['goodsSellFl'], $arrData['goodsSellFl'], 'n'));

    }

    public function getGoodsByImageName($imageName, $isPaging = false, $req = null)
    {
        if ($isPaging === false) {
            $limitQuery = ' LIMIT 1000 ';
        } else {
            $nowPage = $req['page'] ?? 1;
            $limit = 10;
            $offset = ($nowPage - 1) * $limit;
            $limitQuery = ' LIMIT ' . $offset . ' , ' . $limit;

            $totalCountQuery = $strSQL = "SELECT  count(*) as cnt
                FROM " . DB_GOODS_IMAGE . " as  gi
                LEFT JOIN " . DB_GOODS . " as g ON gi.goodsno = g.goodsno ";

            $totalCountQuery .= " WHERE gi.imageName='" . $imageName . "' AND g.delFl = 'n' ";
            $totalCount = $this->db->query_fetch($totalCountQuery, null, false)['cnt'];

            $page = new Page($nowPage, $totalCount, $totalCount, $limit, 10);
            $pageHtml = $page->getPage('layer_list_search(\'PAGELINK\')');;
        }

        $strSQL = "SELECT   g.goodsNo,g.goodsNm, g.regDt , g.imageStorage, g.imagePath, gi.imageName  , gi.imageKind
                FROM " . DB_GOODS_IMAGE . " as  gi
                LEFT JOIN " . DB_GOODS . " as g ON gi.goodsno = g.goodsno ";

        $strSQL .= "WHERE gi.imageName='" . $imageName . "'  AND g.delFl = 'n' ";
        $strSQL .= $limitQuery;
        $data = $this->db->query_fetch($strSQL, null);

        if ($isPaging) {
            $listNo = $totalCount - $offset;
            foreach ($data as &$row) {
                $row['no'] = $listNo;
                $listNo--;
            }
            $result['list'] = $data;
            $result['page'] = $pageHtml;
            $result['totalCnt'] = $totalCount;
            $result['searchCnt'] = $totalCount;
        } else {
            $result = $data;
        }

        return $result;
    }

    /**
     * getEventSaleUrl
     * 기획전 url
     * @param $sno
     * @param bool $isMobile
     * @return string
     * @internal param bool $isAbsolutePath
     * @internal param bool $mobileFl
     */
    public function getEventSaleUrl($sno, $isMobile = false)
    {
        $domain = $isMobile ? URI_MOBILE : URI_HOME;
        return $domain . 'goods' . DS . 'event_sale.php?sno=' . $sno;
    }

    public function getTmpGoodsImage($req = null, $isPaging = true , $isGroupByImageName = true)
    {
        $page = $req['page'] ?? 1;
        if ($isPaging) {
            $limit = $req['pageNum'] ?? 10;
            $offset = ($page - 1) * $limit;
        } else {
            $limit = 1000; //제한
            $offset = 0;
        }

        $arrBind = [];
        if ($req['searchField'] && $req['searchKeyword']) {
            $searchWhere[] = $req['searchField'] . " LIKE concat('%',?,'%')";
            $this->db->bind_param_push($arrBind, 's', $req['searchKeyword']);
        }

        if ($req['imageName']) {
            if(is_array($req['imageName'])){
                foreach($req['imageName'] as $val){
                    $this->db->bind_param_push($arrBind, 's', $val);
                }
                $searchWhere[] = "a.imageName in ('".implode("','",$req['imageName'])."')";
            }
            else{
                $searchWhere[] = "a.imageName LIKE concat('%',?,'%')";
                $this->db->bind_param_push($arrBind, 's', $req['imageName']);
            }
        }

        if($isGroupByImageName){
            if ($req['isApplyGoods'] == 'y') {
                $searchWhere[] = "b.applyGoodsCount is not null ";
            }
            else if ($req['isApplyGoods'] == 'n') {
                $searchWhere[] = "b.applyGoodsCount is null ";
            }

            $query = "select  distinct a.imageName, ifnull (b.applyGoodsCount,0) as applyGoodsCount FROM  ".DB_TMP_GOODS_IMAGE." as a LEFT OUTER JOIN
(select imageName , count(imageName) as applyGoodsCount from ".DB_TMP_GOODS_IMAGE." where 1 ";
            $query.= " AND status = 'ready' group by imageName ";
            $query.= " ) as b ON a.imageName = b.imageName ";
            if ($searchWhere) {
                $query .= ' WHERE ' . implode(' AND ', $searchWhere);
            }
            $query .= '  LIMIT ' . $offset . ',' . $limit;
            $data = $this->db->query_fetch($query, $arrBind);
            $searchQuery = "select  count(distinct a.imageName) as cnt FROM  ".DB_TMP_GOODS_IMAGE." as a LEFT OUTER JOIN
(select imageName , count(imageName) as applyGoodsCount from ".DB_TMP_GOODS_IMAGE." where 1 ";
            $searchQuery.= " AND status = 'ready' group by imageName ";
            $searchQuery.= " ) as b ON a.imageName = b.imageName ";
            if ($searchWhere) {
                $searchQuery .= ' WHERE ' . implode(' AND ', $searchWhere);
            }
            $searchCount = $this->db->query_fetch($searchQuery, $arrBind, false)['cnt'];

            $totalQuery = 'SELECT count(DISTINCT imageName) as cnt FROM ' . DB_TMP_GOODS_IMAGE;
            $totalCount = $this->db->query_fetch($totalQuery, null, false)['cnt'];

            $possibleQuery = "select  count(distinct imageName) as cnt FROM  es_tmpGoodsImage where status='ready' ";
            $possibleCount = $this->db->query_fetch($possibleQuery, null, false)['cnt'];
            $result['possibleCount'] = $possibleCount;
        }
        else {
            if ($req['isApplyGoods'] == 'y') {
                $searchWhere[] = "a.status = 'ready' ";
            }
            else if ($req['isApplyGoods'] == 'n') {
                $searchWhere[] = "a.status != 'ready' ";
            }

            $query = 'SELECT a.*, g.goodsNm,g.imageStorage , gi.imageName as oriImageName FROM ' . DB_TMP_GOODS_IMAGE . '   as a LEFT OUTER JOIN ' .  DB_GOODS . " as g ON g.goodsNo = a.goodsNo
            LEFT OUTER JOIN ".DB_GOODS_IMAGE." as gi ON gi.imageName = TRIM(a.imageName) AND gi.goodsNo = a.goodsNo AND a.imageKind = gi.imageKind WHERE 1";
            if ($searchWhere) {
                $query .= ' AND  ' . implode(' AND ', $searchWhere);
            }
            $query .= '    LIMIT ' . $offset . ',' . $limit;
            $data = $this->db->query_fetch($query, $arrBind);


            $totalQuery = 'SELECT count(*) as cnt FROM ' . DB_TMP_GOODS_IMAGE;
            $totalCount = $this->db->query_fetch($totalQuery, null, false)['cnt'];

            $searchQuery = 'SELECT count(*) as cnt FROM ' . DB_TMP_GOODS_IMAGE . " as a ";
            if ($searchWhere) {
                $searchQuery .= ' WHERE ' . implode(' AND ', $searchWhere);
            }
            $searchCount = $this->db->query_fetch($searchQuery, $arrBind, false)['cnt'];
        }

        if ($isPaging === false) {
            return $data;
        }

        $listNo = $searchCount - $offset;
        $page = new Page($page, $searchCount, $searchCount, $limit, 10);
        $page->setUrl(Request::getQueryString());;
        //$pageHtml = $page->getPage();;

        foreach ($data as &$row) {
            $row['no'] = $listNo;
            $row['tmpPath'] = Storage::disk(Storage::PATH_CODE_GOODS, 'local')->getHttpPath('tmp/' . $row['imageName']);
            $listNo--;
        }
        $result['list'] = $data;
        $result['page'] = $page;
        $result['totalCnt'] = $totalCount;
        $result['searchCnt'] = $searchCount;
        return $result;
    }


    /**
     * 상품이미지 일괄업로드 시 임시폴더에 있는 파일을 db화
     *
     * @param $arrData
     * @return mixed
     */
    public function saveTmpGoodsImage($arrData)
    {
        $arrBind = $this->db->get_binding(DBTableField::tableTmpGoodsImage(), $arrData, 'insert', array_keys($arrData));
        $this->db->set_insert_db(DB_TMP_GOODS_IMAGE, $arrBind['param'], $arrBind['bind'], 'y');
        return $this->db->affected_rows();
    }

    /**
     * 상품이미지 일괄업로드 시 임시폴더 Db 수정
     *
     * @param $arrData
     * @return mixed
     */
    public function updateTmpGoodsImage($arrData)
    {
        $arrBind = null;
        $this->db->bind_param_push($arrBind, 's', $arrData['status']);
        $this->db->bind_param_push($arrBind, 'i', $arrData['sno']);
        $this->db->set_update_db(DB_TMP_GOODS_IMAGE,  ' status = ?  '  , ' sno = ?' , $arrBind);
        return $this->db->affected_rows();
    }

    /**
     * 상품이미지 일괄업로드 시 임시폴더에 있는 파일을 삭제
     *
     * @param $imageName
     * @return mixed
     */
    public function deleteTmpGoodsImage(array $imageName)
    {
        foreach ($imageName as $fileName) {
            Storage::disk(Storage::PATH_CODE_GOODS, 'local')->delete('tmp' . DS . $fileName);
            $arrBind = [];
            $this->db->bind_param_push($arrBind, 's', $fileName);
            $this->db->set_delete_db(DB_TMP_GOODS_IMAGE, "imageName = ? ", $arrBind);
        }
    }

    /**
     * 대표색상 사용 여부 확인
     *
     * @param $color
     * @return mixed
     */
    public function getGoodsColorCount($color)
    {
        $arrBind = [];
        $strSQL = "SELECT count(goodsNo) as  cnt FROM " . DB_GOODS . " WHERE goodsColor LIKE concat('%',?,'%') ";
        $this->db->bind_param_push($arrBind, 's', $color);
        $tmp = $this->db->query_fetch($strSQL, $arrBind, false);
        return $tmp['cnt'];
    }

    /**
     * 상품 상세 설명 수정
     *
     * @param $sDescription
     * @param $goodsNo
     */
    public function setGoodsDescription($sDescription, $goodsNo)
    {
        $arrBind = null;
        $this->db->bind_param_push($arrBind, 's', $sDescription);
        $this->db->bind_param_push($arrBind, 'i', $goodsNo);
        $this->db->set_update_db(DB_GOODS, 'goodsDescription = ?', 'goodsNo = ?', $arrBind);
    }

    /**
     * 네이버 사용 상품 총 합계
     *
     * @param $arrData
     * @return mixed
     */
    public function getNaverStats() {

        $where[] = 'goodsDisplayFl = \'y\'';
        $where[] = 'delFl = \'n\'';
        $where[] = 'applyFl = \'y\'';
        $where[] = 'NOT(stockFl = \'y\' AND totalStock = 0)';
        $where[] = 'NOT(soldOutFl = \'y\')';
        $where[] = '(UNIX_TIMESTAMP(goodsOpenDt) IS NULL  OR UNIX_TIMESTAMP(goodsOpenDt) = 0 OR UNIX_TIMESTAMP(goodsOpenDt) < UNIX_TIMESTAMP())';

        $totalCountSQL =  ' SELECT COUNT(goodsNo) AS totalCnt FROM ' . DB_GOODS . ' as g  USE INDEX (PRIMARY) WHERE '.implode(' AND ', $where);
        $count['total'] = $this->db->query_fetch($totalCountSQL,null,false)['totalCnt'];

        $where[] = 'naverFl = \'y\'';
        $totalCountSQL =  ' SELECT COUNT(goodsNo) AS totalCnt FROM ' . DB_GOODS . ' as g  USE INDEX (PRIMARY) WHERE '.implode(' AND ', $where);
        $count['naver'] = $this->db->query_fetch($totalCountSQL,null,false)['totalCnt'];

        return $count;
    }

    /**
     * 네이버 사용 상품 총 합계
     *
     * @param $arrData
     * @return mixed
     */
    public function getGoodsNaverStats($cateCd) {

        $where[] = 'goodsDisplayFl = \'y\'';
        $where[] = 'delFl = \'n\'';
        $where[] = 'applyFl = \'y\'';
        $where[] = 'NOT(stockFl = \'y\' AND totalStock = 0)';
        $where[] = 'NOT(soldOutFl = \'y\')';
        $where[] = '(UNIX_TIMESTAMP(goodsOpenDt) IS NULL  OR UNIX_TIMESTAMP(goodsOpenDt) = 0 OR UNIX_TIMESTAMP(goodsOpenDt) < UNIX_TIMESTAMP())';
        if(is_array($cateCd) && gd_isset($cateCd)) {
            $where[] = "(cateCd = '".implode("' OR cateCd = '", $cateCd)."')";
        } else {
            $where[] = "cateCd = ''";
        }

        $totalCountSQL =  ' SELECT COUNT(goodsNo) as cnt , cateCd  FROM ' . DB_GOODS . ' USE INDEX (PRIMARY) WHERE '.implode(' AND ', $where)." group by cateCd";
        $tmpData = $this->db->query_fetch($totalCountSQL,null);
        $getData['total'] =array_combine(array_column($tmpData, 'cateCd'), array_column($tmpData, 'cnt'));
        $where[] = 'naverFl = \'y\'';

        $totalCountSQL =  ' SELECT COUNT(goodsNo) as cnt , cateCd  FROM ' . DB_GOODS . ' USE INDEX (PRIMARY) WHERE '.implode(' AND ', $where)." group by cateCd";
        $tmpData = $this->db->query_fetch($totalCountSQL,null);
        $getData['naver'] =array_combine(array_column($tmpData, 'cateCd'), array_column($tmpData, 'cnt'));

        return $getData;

    }

    /**
     * 상품 재입고 알림 리스트
     *
     * @return array 상품 리스트 정보
     */
    public function getGoodsRestockList()
    {
        $getValue = Request::get()->toArray();

        $this->setSearchGoodsRestock($getValue);

        gd_isset($getValue['page'], 1);
        gd_isset($getValue['pageNum'], 10);

        $sort = gd_isset($getValue['sort'], 'gr.goodsNm asc');
        $sort .= ', gr.sno desc';

        $page = \App::load('\\Component\\Page\\Page', $getValue['page']);
        $page->page['list'] = $getValue['pageNum'];
        $page->setPage();
        $page->setUrl(\Request::getQueryString());

        $join[] = ' LEFT JOIN ' . DB_GOODS . ' AS g ON gr.goodsNo = g.goodsNo ';
        $join[] = ' LEFT JOIN ' . DB_GOODS_OPTION . ' AS go ON (gr.goodsNo = go.goodsNo AND gr.optionSno = go.sno )';
        $join[] = ' LEFT JOIN ' . DB_GOODS_IMAGE . ' AS gi ON gr.goodsNo = gi.goodsNo AND gi.imageKind = "List" ';
        $join[] = ' LEFT JOIN ' . DB_SCM_MANAGE . ' AS s ON g.scmNo = s.scmNo ';

        //상품 재입고 노출필드
        $strField[] = "gr.sno, gr.diffKey, gr.goodsNo, gr.goodsNm, gr.optionSno, gr.optionName, gr.optionValue, COUNT(gr.sno) AS requestCount, COUNT(IF(smsSendFl='y', gr.sno, null)) AS smsSendY, COUNT(IF(smsSendFl='n', gr.sno, null)) AS smsSendN";
        //상품 노출필드
        $strField[] = "g.goodsNo AS ori_goodsNo, g.totalStock, g.goodsNm AS ori_goodsNm, g.optionName AS ori_optionName, g.imagePath, g.imageStorage, g.delFl, g.optionFl";
        //상품 옵션 노출필드
        $strField[] = "go.optionValue1, go.optionValue2, go.optionValue3, go.optionValue4, go.optionValue5, go.stockCnt";
        //상품이미지 노출필드
        $strField[] = "gi.imageName";
        //공급사 노출필드
        $strField[] = "s.companyNm";

        $this->db->strField = implode(", ", $strField);
        $this->db->strJoin = implode('', $join);
        $this->db->strWhere = implode(' AND ', gd_isset($this->arrWhere));
        $this->db->strOrder = $sort;
        $this->db->strGroup = 'gr.diffKey';
        $this->db->strLimit = $page->recode['start'] . ',' . $getValue['pageNum'];
        $query = $this->db->query_complete();
        $strSQL = 'SELECT ' . array_shift($query) . ' FROM ' . DB_GOODS_RESTOCK . ' AS gr ' . implode(' ', $query);

        $data = $this->db->query_fetch($strSQL, $this->arrBind);

        /* 검색 count 쿼리 */
        if(count($this->arrWhere) > 0){
            $totalCountWhere = '  WHERE '.implode(' AND ', $this->arrWhere) . ' GROUP BY gr.diffKey ORDER BY null';
        }
        else {
            $totalCountWhere = ' GROUP BY gr.diffKey ORDER BY null';
        }
        $totalCountSQL =  ' SELECT COUNT(gr.diffKey) AS totalCnt FROM ' . DB_GOODS_RESTOCK . ' AS gr '.implode('', $join).$totalCountWhere;
        $dataCount = $this->db->query_fetch($totalCountSQL, $this->arrBind);
        $dataCount = count($dataCount);

        unset($this->arrBind);

        $page->recode['total'] = $dataCount; //검색 레코드 수

        $totalAmountSQL = "SELECT COUNT(diffKey) AS totalCnt FROM " . DB_GOODS_RESTOCK . " GROUP BY diffKey";
        $dataAmount = $this->db->query_fetch($totalAmountSQL);
        $dataAmount = count($dataAmount);
        $page->recode['amount'] = $dataAmount;
        $page->setPage();

        // 각 데이터 배열화
        $getData['data'] = gd_htmlspecialchars_stripslashes(gd_isset($data));
        $getData['sort'] = $sort;
        $getData['search'] = gd_htmlspecialchars($this->search);
        $getData['selected'] = $this->selected;
        $getData['checked'] = $this->checked;

        return $getData;
    }

    /**
     * 상품 재입고 알림 신청 내역
     *
     * @return array 상품 재입고 알림 신청 내역 정보
     */
    public function getGoodsRestockView()
    {
        $getValue = Request::get()->toArray();

        $strField[] = "gr.diffKey, gr.goodsNm, gr.optionName, gr.optionValue, gr.optionSno";
        $strField[] = "g.goodsNo AS ori_goodsNo, g.goodsNm AS ori_goodsNm, g.optionName AS ori_optionName, g.totalStock, g.goodsDisplayFl, g.goodsDisplayMobileFl, g.goodsSellFl, g.goodsSellMobileFl, g.soldOutFl, g.stockFl, g.delFl";
        $strField[] = "go.optionValue1, go.optionValue2, go.optionValue3, go.optionValue4, go.optionValue5, go.stockCnt";

        $query = " WHERE gr.diffKey = '".$getValue['diffKey']."' LIMIT 1";
        $strSQL = "
            SELECT ".implode(", ", $strField)." FROM
                ".DB_GOODS_RESTOCK." AS gr
                    LEFT JOIN
                ".DB_GOODS." AS g ON gr.goodsNo = g.goodsNo
                    LEFT JOIN
                ".DB_GOODS_OPTION." AS go ON (gr.goodsNo = go.goodsNo AND gr.optionSno = go.sno )
        ".$query;
        $data = $this->db->query_fetch($strSQL);

        //옵션
        $data[0]['option'] = $this->getGoodsRestockOptionDisplay($data[0]);

        $returnData = $data[0];
        $returnData['goodsDisplayFl'] = ($returnData['goodsDisplayFl'] === 'y') ? '노출함' : '노출안함';
        $returnData['goodsDisplayMobileFl'] = ($returnData['goodsDisplayMobileFl'] === 'y') ? '노출함' : '노출안함';
        $returnData['goodsSellFl'] = ($returnData['goodsSellFl'] === 'y') ? '판매함' : '판매안함';
        $returnData['goodsSellMobileFl'] = ($returnData['goodsSellMobileFl'] === 'y') ? '판매함' : '판매안함';
        if($returnData['soldOutFl'] === 'y' || ($returnData['stockFl'] === 'y' && $returnData['totalStock'] <= 0)){
            $returnData['soldOutResult'] = "품절";
        }
        else {
            $returnData['soldOutResult'] = "정상";
        }

        if((int)$returnData['optionSno'] > 0){
            $returnData['totalStock'] = $returnData['stockCnt'];
        }

        return $returnData;
    }

    /**
     * 상품 재입고 알림 내역 리스트
     *
     * @return array 품 재입고 알림 내역 리스트
     */
    public function getGoodsRestockViewList($searchData = array())
    {
        if(count($searchData) > 0){
            $getValue = $searchData;
            $this->arrWhere = array();
        }
        else {
            $getValue = Request::get()->toArray();
        }

        $this->setSearchGoodsRestockViewList($getValue);

        gd_isset($getValue['page'], 1);
        gd_isset($getValue['pageNum'], 10);

        $sort = gd_isset($getValue['sort'], 'gr.regdt DESC');

        $page = \App::load('\\Component\\Page\\Page', $getValue['page']);
        $page->page['list'] = $getValue['pageNum'];
        $page->setPage();

        //상품 재입고 노출필드
        $strField[] = "gr.sno, gr.optionName, gr.optionValue, gr.smsSendFl, gr.cellPhone, gr.regdt, gr.name, gr.goodsNm, gr.goodsNo, gr.memNo";

        $this->db->strField = implode(", ", $strField);
        $this->db->strWhere = implode(' AND ', gd_isset($this->arrWhere));
        $this->db->strOrder = $sort;
        if(count($searchData) < 1) {
            $this->db->strLimit = $page->recode['start'] . ',' . $getValue['pageNum'];
        }
        $query = $this->db->query_complete();
        $strSQL = 'SELECT ' . array_shift($query) . ' FROM ' . DB_GOODS_RESTOCK . ' AS gr ' . implode(' ', $query);

        $data = $this->db->query_fetch($strSQL, $this->arrBind);

        /* 검색 count 쿼리 */
        $totalCountSQL =  ' SELECT COUNT(gr.sno) AS totalCnt FROM ' . DB_GOODS_RESTOCK . ' AS gr ' . implode(' ', $query);
        $dataCount = $this->db->query_fetch($totalCountSQL, $this->arrBind);

        unset($this->arrBind);

        if(trim($getValue['diffKey']) !== ''){
            $page->recode['total'] = $dataCount[0]['totalCnt']; //검색 레코드 수
            $totalAmountSQL =  "SELECT COUNT(sno) AS totalCnt FROM " . DB_GOODS_RESTOCK . " WHERE diffKey = '".$getValue['diffKey']."'";
            $totalAmount = $this->db->query_fetch($totalAmountSQL);
            $page->recode['amount'] = $totalAmount[0]['totalCnt'];
            $page->setPage();
        }

        // 각 데이터 배열화
        $getData['data'] = gd_htmlspecialchars_stripslashes(gd_isset($data));
        $getData['sort'] = $sort;
        $getData['search'] = gd_htmlspecialchars($this->search);
        $getData['selected'] = $this->selected;
        $getData['checked'] = $this->checked;

        return $getData;
    }

    /**
     * 관리자 상품 재입고 알림 신청 관리 리스트를 위한 검색 정보
     */
    public function setSearchGoodsRestock($getValue = null)
    {
        if (is_null($getValue)) $getValue = Request::get()->toArray();

        $fieldTypeGoods = DBTableField::getFieldTypes('tableGoods');
        $fieldTypeGoodsRestock = DBTableField::getFieldTypes('tableGoodsRestockBasic');

        // 통합 검색
        /* @formatter:off */
        $this->search['combineSearch'] = [
            'all' => __('=통합검색='),
            'goodsNm' => __('상품명'),
            'goodsNo' => __('상품코드'),
            'goodsCd' => __('자체상품코드'),
            'optionName' => __('옵션명'),
            'goodsSearchWord' => __('검색키워드'),
            'goodsModelNo' => __('모델번호')
        ];
        /* @formatter:on */

        //검색설정
        $this->search['sortList'] = array(
            'gr.goodsNm desc' => __('상품명 ↓'),
            'gr.goodsNm asc' => __('상품명 ↑'),
            'g.totalStock desc' => __('재고량 ↓'),
            'g.totalStock asc' => __('재고량 ↑'),
            'requestCount desc' => __('신청자 ↓'),
            'requestCount asc' => __('신청자 ↑'),
            'smsSendY desc' => __('발송건수 ↓'),
            'smsSendY asc' => __('발송건수 ↑'),
            'smsSendN desc' => __('미발송건수 ↓'),
            'smsSendN asc' => __('미발송건수 ↑'),
        );

        // --- 검색 설정
        $this->search['sort'] = gd_isset($getValue['sort'], 'gr.goodsNm asc');
        $this->search['key'] = gd_isset($getValue['key']);
        $this->search['keyword'] = gd_isset($getValue['keyword']);
        $this->search['stock'] = gd_isset($getValue['stock']);
        $this->search['scmFl'] = gd_isset($getValue['scmFl'], Session::get('manager.isProvider') ? 'y' : 'all');
        if($this->search['scmFl'] =='y' && !isset($getValue['scmNo'])  && !Session::get('manager.isProvider')  )  $this->search['scmFl'] = "all";
        $this->search['scmNo'] = gd_isset($getValue['scmNo'], (string)Session::get('manager.scmNo'));
        $this->search['scmNoNm'] = gd_isset($getValue['scmNoNm']);

        $this->selected['sort'][$this->search['sort']] = "selected='selected'";
        $this->checked['scmFl'][$this->search['scmFl']] = "checked='checked'";

        // 키워드 검색
        if ($this->search['key'] && $this->search['keyword']) {
            if ($this->search['key'] == 'all') {
                $tmpWhere = array('goodsNm', 'goodsNo', 'goodsCd', 'optionName', 'goodsSearchWord', 'goodsModelNo');
                $arrWhereAll = array();
                foreach ($tmpWhere as $keyNm) {
                    if($keyNm === 'goodsNm' || $keyNm === 'optionName'){
                        $arrWhereAll[] = '(gr.' . $keyNm . ' LIKE concat(\'%\',?,\'%\'))';
                        $this->db->bind_param_push($this->arrBind, $fieldTypeGoodsRestock[$keyNm], $this->search['keyword']);
                    }
                    else {
                        $arrWhereAll[] = '(g.' . $keyNm . ' LIKE concat(\'%\',?,\'%\'))';
                        $this->db->bind_param_push($this->arrBind, $fieldTypeGoods[$keyNm], $this->search['keyword']);
                    }
                }

                $this->arrWhere[] = '(' . implode(' OR ', $arrWhereAll) . ')';
                unset($tmpWhere);
            }
            else if($this->search['key'] == 'goodsNm' || $this->search['key'] == 'optionName'){
                $this->arrWhere[] = 'gr.'.$this->search['key'] . ' LIKE concat(\'%\',?,\'%\')';
                $this->db->bind_param_push($this->arrBind, $fieldTypeGoodsRestock[$this->search['key']], $this->search['keyword']);
            }
            else {
                $this->arrWhere[] = 'g.'.$this->search['key'] . ' LIKE concat(\'%\',?,\'%\')';
                $this->db->bind_param_push($this->arrBind, $fieldTypeGoods[$this->search['key']], $this->search['keyword']);
            }
        }

        // 재고검색
        if ($this->search['stock'][0] || $this->search['stock'][1]) {
            if($this->search['stock'][0]) {
                $this->arrWhere[] = 'g.totalStock >= ? ';
                $this->db->bind_param_push($this->arrBind, $fieldTypeGoods['totalStock'], $this->search['stock'][0]);
            }

            if($this->search['stock'][1]) {
                $this->arrWhere[] = 'g.totalStock <= ? ';
                $this->db->bind_param_push($this->arrBind, $fieldTypeGoods['totalStock'], $this->search['stock'][1]);
            }
        }
        //공급사 검색
        if ($this->search['scmFl'] != 'all') {
            if (is_array($this->search['scmNo'])) {
                foreach ($this->search['scmNo'] as $val) {
                    $tmpWhere[] = 'g.scmNo = ?';
                    $this->db->bind_param_push($this->arrBind, 's', $val);
                }
                $this->arrWhere[] = '(' . implode(' OR ', $tmpWhere) . ')';
                unset($tmpWhere);
            } else {
                $this->arrWhere[] = 'g.scmNo = ?';
                $this->db->bind_param_push($this->arrBind, $fieldTypeGoods['scmNo'], $this->search['scmNo']);

                $this->search['scmNo'] = array($this->search['scmNo']);
                $this->search['scmNoNm'] = array($this->search['scmNoNm']);
            }
        }

        if (empty($this->arrBind)) {
            $this->arrBind = null;
        }
    }

    /**
     * 관리자 상품 재입고 알림 신청 내역 리스트를 위한 검색 정보
     */
    public function setSearchGoodsRestockViewList($getValue = null)
    {
        if (is_null($getValue)) $getValue = Request::get()->toArray();

        $fieldTypeGoodsRestock = DBTableField::getFieldTypes('tableGoodsRestockBasic');

        //검색설정
        $this->search['sortList'] = array(
            'gr.regdt desc' => __('신청일 ↓'),
            'gr.regdt asc' => __('신청일 ↑'),
            'gr.name desc' => __('신청자 ↓'),
            'gr.name asc' => __('신청자 ↑'),
        );

        // --- 검색 설정
        $this->search['sort'] = gd_isset($getValue['sort'], 'gr.regdt desc');
        $this->search['sno'] = gd_isset($getValue['sno']);
        $this->search['stock'] = gd_isset($getValue['stock']);
        $this->search['diffKey'] = gd_isset($getValue['diffKey']);
        $this->search['smsSendFl'] = gd_isset($getValue['smsSendFl']);
        $this->search['memberFl'] = gd_isset($getValue['memberFl']);

        $this->selected['sort'][$this->search['sort']] = "selected='selected'";
        $this->checked['smsSendFl'][$this->search['smsSendFl']] = 'checked="checked"';
        $this->checked['memberFl'][$this->search['memberFl']] = 'checked="checked"';

        if ($this->search['sno']) {
            $this->arrWhere[] = 'gr.sno = ?';
            $this->db->bind_param_push($this->arrBind, $fieldTypeGoodsRestock['sno'], $this->search['sno']);
        }
        if ($this->search['diffKey']) {
            $this->arrWhere[] = 'gr.diffKey = ?';
            $this->db->bind_param_push($this->arrBind, $fieldTypeGoodsRestock['diffKey'], $this->search['diffKey']);
        }
        //SMS 발송 여부
        if ($this->search['smsSendFl']) {
            $this->arrWhere[] = 'gr.smsSendFl = ?';
            $this->db->bind_param_push($this->arrBind, $fieldTypeGoodsRestock['smsSendFl'], $this->search['smsSendFl']);
        }
        //회원 여부
        if($this->search['memberFl'] === 'y'){
            $this->arrWhere[] = 'gr.memNo > ?';
            $this->db->bind_param_push($this->arrBind, $fieldTypeGoodsRestock['memNo'], 0);
        }
        else if($this->search['memberFl'] === 'n'){
            $this->arrWhere[] = 'gr.memNo < ?';
            $this->db->bind_param_push($this->arrBind, $fieldTypeGoodsRestock['memNo'], 1);
        }
        else {}

        if (empty($this->arrBind)) {
            $this->arrBind = null;
        }
    }

    /**
     * 상품재입고 알림 - 옵션 정보 출력
     *
     * @param array $dataArray
     * @return string $option
     */
    public function getGoodsRestockOptionDisplay($dataArray)
    {
        $optionArray = $optionNameArray = $optionValueArray = array();
        $option = '';
        $optionNameArray = explode(STR_DIVISION, $dataArray['optionName']);
        $optionValueArray = explode(STR_DIVISION, $dataArray['optionValue']);
        $optionArray = array_map(function($nameVal, $valueVal){
            if(trim($nameVal) !== ''){
                return $nameVal . '/' . $valueVal;
            }
            else {
                return '';
            }
        }, $optionNameArray, $optionValueArray);
        $option = implode("<br />", $optionArray);

        return $option;
    }

    /**
     * 상품재입고 알림 - 기존 상품정보와 신청정보의 상풍명, 옵션명, 옵션값이 다를 경우 색으로 다름을 표시
     *
     * @param array $dataArray
     * @return array
     */
    public function getGoodsRestockStatus($dataArray)
    {
        if(!$dataArray['ori_goodsNo']){
            return array('deleteComplete', '#FFEAEA');
        }
        if($dataArray['delFl'] === 'y'){
            return array('delete', '#FFEAEA');
        }

        $optionOriginalValueArray = $this->setGoodsRestockOriginalOptionValue($dataArray);
        $optionOriginalValue = implode(STR_DIVISION, $optionOriginalValueArray);
        if(
            $dataArray['ori_goodsNm'] !== $dataArray['goodsNm'] ||
            $dataArray['ori_optionName'] !== $dataArray['optionName'] ||
            $optionOriginalValue !== $dataArray['optionValue']
        ){
            return array('change', '#FFFFE4');
        }

        return array();
    }

    /**
     * 상품재입고 알림 - 옵션값 재정렬 후 반환
     *
     * @param array $dataArray
     * @return array $optionValueArray
     */
    public function setGoodsRestockOriginalOptionValue($dataArray)
    {
        $optionValueArray = array_values(array_filter(array(
            $dataArray['optionValue1'],
            $dataArray['optionValue2'],
            $dataArray['optionValue3'],
            $dataArray['optionValue4'],
            $dataArray['optionValue5'],
        )));

        return $optionValueArray;
    }

    /**
     * 상품재입고 알림 - 신청내역 삭제
     *
     * @param array $postData
     * @return void
     */
    public function deleteGoodsRestock($postData)
    {
        if(count($postData['diffKey']) > 0){
            foreach($postData['diffKey'] as $key => $value){
                $this->db->set_delete_db(DB_GOODS_RESTOCK, "diffKey = '".$value."'");
            }
        }
    }

    /**
     * 상품재입고 알림 - SMS 발송여부에 따른 업데이트
     *
     * @param array $restockUpdateData
     * @return void
     */
    public function updateGoodsRestockSmsSend($restockUpdateData)
    {
        if(count($restockUpdateData) > 0) {
            $snoArray = array_column($restockUpdateData, 'sno');
            $snoArray = @array_chunk($snoArray, 100);
            foreach ($snoArray as $key => $valueArray) {
                $this->db->set_update_db(DB_GOODS_RESTOCK, "smsSendFl='y'", "sno IN ('" . implode("','", $valueArray) . "')");
            }
        }
    }

    public function setGoodsSale($param)
    {
        $goodsNo = explode(INT_DIVISION, $param['goodsNo']);
        if (Session::get('manager.isProvider') && Session::get('manager.scmPermissionModify') == 'c') {
            $param['applyFl'] = 'a';
            $param['applyDt'] = date('Y-m-d H:i:s');
        } else {
            $param['applyFl'] = 'y';
        }
        unset($param['goodsNo'], $param['mode'], $param['goodsDisplay'], $param['goodsDisplayMobile'], $param['goodsSell'], $param['goodsSellMobile']);
        $updateData = $param;
        unset($updateData['applyFl'], $updateData['applyDt']);

        $arrBind = $this->db->get_binding(DBTableField::tableGoods(), $param, 'update', array_keys($param));
        foreach ($goodsNo as $value) {
            $goodsData = $this->getGoodsInfo($value, @implode(',', array_keys($param)));
            $this->setGoodsLog('goods', $value, $goodsData, $updateData);

            $this->db->set_update_db(DB_GOODS, $arrBind['param'], 'goodsNo = \'' . $value . '\'', $arrBind['bind']);
        }

        return $param['applyFl'];
    }
}
