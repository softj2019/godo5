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
 namespace Controller\Admin\Goods; 
 
 
 use Exception; 
 use Framework\Utility\ImageUtils; 
 use Globals; 
 use Request; 
 
 use Framework\Debug\Exception\AlertBackException; 

 /** 
  * 상품 등록 / 수정 페이지 
  */ 
 class GoodsRegisterController extends \Controller\Admin\Controller 
 { 
 
 
     /** 
      * index 
      * 
      * @throws Except 
      */ 
     public function index() 
     { 
		try
		 {
		//throw new AlertBackException(__('오류') . ' - ' . __('오류가 발생 하였습니다.'));

         $getValue = Request::get()->toArray(); 
 
 
         // --- 메뉴 설정 
         if (Request::get()->has('goodsNo')) { // 수정인 경우 
             $this->callMenu('goods', 'goods', 'modify'); 
         } else { // 등록인 경우 
             $this->callMenu('goods', 'goods', 'register'); 
         } 
 
 
         $cate = \App::load('\\Component\\Category\\CategoryAdmin'); 
         // --- 각 설정값 
 
 
         $conf['currency'] = Globals::get('gCurrency'); 
         $conf['mileage'] = Globals::get('gSite.member.mileageGive'); // 마일리지 지급 여부 
         $conf['mileageBasic'] = Globals::get('gSite.member.mileageBasic'); // 마일리지 기본설정 
         $conf['mileage'] = Globals::get('gSite.member.mileageGive'); // 마일리지 지급 여부 
         $conf['goods'] = gd_policy('mileage.goods'); // 상품 관련 마일리지 설정 
         $conf['image'] = gd_policy('goods.image'); // 이미지 설정 
         $conf['tax'] = gd_policy('goods.tax'); // 과세/비과세 설정 
         $conf['mobile'] = gd_policy('mobile.config'); // 모바일샵 설정 
         $conf['qrcode'] = gd_policy('promotion.qrcode'); // QR코드 설정 
 
 
         // 이미지 사이즈 설정 
         foreach ($conf['image'] as $k => $v) { 
             foreach ($v as $key => $value) { 
                 if (stripos($key, 'size') === 0) { 
                     if ($conf['image']['imageType'] == 'fixed') { 
                         $conf['image'][$k]['fixed'.$key] = [$value, $conf['image'][$k]['h' . $key]]; 
                         unset($conf['image'][$k]['h' . $key]); 
                     } 
                 } 
             } 
             if (stripos($k, 'imageType') === 0) { 
                 $imageType = $conf['image']['imageType']; 
                 unset($conf['image']['imageType']); 
             } 
         } 
 
 
         $tmp = gd_policy('basic.storage'); // 저장소 설정 
         $defaultImageStorage = ''; 
         foreach ($tmp['storageDefault'] as $index => $item) { 
             if (in_array('goods', $item)) { 
                 if (is_null($getValue['goodsNo'])) { 
                     $defaultImageStorage = $tmp['httpUrl'][$index]; 
                 } 
             } 
         } 
         foreach ($tmp['httpUrl'] as $key => $val) { 
             $conf['storage'][$val] = $tmp['storageName'][$key]; 
         } 
         unset($tmp); 
 
 
         // --- 이미지 설정 순서 변경 
         ImageUtils::sortImageConf($conf['image']); 
 
 
         // --- 모듈 설정 
         $goods = \App::load('\\Component\\Goods\\GoodsAdmin'); 
 
 
         // --- 상품 설정 
         if (gd_isset($getValue['applyNo'])) { 
             // --- 상품 정보 관리로 접속된 경우 (등록 수정시 다른 상품의 적용을 누른 경우 - 즉 다른 상품의 정보가 입력이 됨) 
             $applyGoodsCopy = true; 
 
 
             try { 
                 $data = $goods->getDataGoods($getValue['applyNo'], $conf['tax']); 

 
                 // 이미지 설정 초기화 
                 //unset($data['data']['image']); // 이미지 초기화 
                 //unset($data['data']['imageStorage']); // 이미지 저장소 초기화 
					//unset($data['data']['imagePath']); // 이미지 저장 경로 초기화 
                 //unset($data['data']['optionIcon']); // 옵션 추가 노출 초기화 
 
 
                 // 수정인 경우 
                 if (gd_isset($getValue['goodsNo'])) { 
                     $tmpData = $goods->getGoodsInfo($getValue['goodsNo'], 'g.imageStorage,g.imagePath'); // 기존 상품 정보 
                     $data['data']['mode'] = 'modify'; 
                     $data['data']['goodsNo'] = $getValue['goodsNo']; 
                     //$data['data']['imageStorage'] = $tmpData['imageStorage']; 
                     //$data['data']['imagePath'] = $tmpData['imagePath']; 
                     unset($tmpData); 
 
 
                     // 등록인 경우 
                 } else { 
                     $data['data']['mode'] = 'register'; 
                     $data['data']['goodsNo'] = null; 
                     //$data['data']['imageStorage'] = 'local'; 
                     //$data['data']['imagePath'] = null; 
                 } 
 
 
             } catch (Except $e) { 
                 //$e->actLog(); 
                 echo($e->ectMessage); 
             } 
         } else { 
             // --- 일반적인 경우 
             $applyGoodsCopy = false; 
             try { 
                 $data = $goods->getDataGoods(gd_isset($getValue['goodsNo']), $conf['tax']); 
             } catch (Exception $e) { 
                 throw $e; 
             } 
         } 
 
 
         // --- 관리자 디자인 템플릿 
         if (isset($getValue['popupMode']) === true) { 
             $this->getView()->setDefine('layout', 'layout_blank.php'); 
         } 
 
 
         $this->addCss( 
             [ 
                 '../script/jquery/colorpicker/colorpicker.css', 
             ] 
         ); 
         $this->addScript( 
             [ 
                 'jquery/jquery.multi_select_box.js', 
             ] 
         ); 
 
 
         $conf['storage']['url'] = __("URL 직접입력"); 
         if (empty($defaultImageStorage) === false) { 
             $data['data']['imageStorage'] = $defaultImageStorage; 
         } 
 
 
         $this->setData('conf', $conf); 
         $this->setData('cate', $cate); 
         $this->setData('data', gd_htmlspecialchars($data['data'])); 
         $this->setData('checked', $data['checked']); 
         $this->setData('selected', $data['selected']); 
         $this->setData('popupMode', gd_isset($getValue['popupMode'])); 
         $this->setData('applyGoodsCopy', $applyGoodsCopy); 
         $this->setData('applyNo', $getValue['applyNo']); 
         $this->setData('goodsStateList', $goods->getGoodsStateList()); 
         $this->setData('goodsPermissionList', $goods->getGoodsPermissionList()); 
         $this->setData('goodsColorList', $goods->getGoodsColorList(true)); 
         $this->setData('goodsPayLimit', $goods->getGoodsPayLimit()); 
 
 
         $this->setData('goodsImportType', $goods->getGoodsImportType()); 
         $this->setData('goodsSellType', $goods->getGoodsSellType()); 
         $this->setData('goodsAgeType', $goods->getGoodsAgeType()); 
         $this->setData('goodsGenderType', $goods->getGoodsGenderType()); 
         $this->setData('hscode', $goods->getHscode()); 
         $this->setData('imageInfo', gd_policy('goods.image')); 
         $this->setData('imageType', $imageType); 
 
 
         // 공급사와 동일한 페이지 사용 
         $this->getView()->setPageName('goods/goods_register.php'); 
 
		 }
		catch(except  $e)
		{

		}
     } 
 } 
