<?php 

 namespace Controller\Admin\Goods; 
 
 use Component\Storage\Storage; 
 use Framework\Debug\Exception\LayerException; 
 use Framework\Debug\Exception\LayerNotReloadException; 
 use Exception; 
 use Message; 
 use Request; 
 use Session; 
 
 
 class GoodsPsController extends \Controller\Admin\Controller 
 { 
 
 
     /** 
      * 상품 관련 처리 페이지 
      * [관리자 모드] 상품 관련 처리 페이지 
      * 
      * @author artherot 
      * @version 1.0 
      * @since 1.0 
      * @copyright ⓒ 2016, NHN godo: Corp. 
      * @throws Except 
      * @throws LayerException 
      * @param array $get 
      * @param array $post 
      * @param array $files 
      */ 
     public function index() 
     { 
 

        // --- 각 배열을 trim 처리 
        $postValue = Request::post()->toArray(); 


        // --- 상품 class 
        $goods = \App::load('\\Component\\Goods\\GoodsAdmin'); 


        try { 


            switch ($postValue['mode']) { 
                // 상품 등록 / 수정 
                case 'register': 
                case 'modify': 


                    $applyFl = $goods->saveInfoGoods($postValue); 


                    if($applyFl =='a') { 
                        $this->layer(__("승인을 요청하였습니다.")); 
                    } else { 
                        $this->layer(__('저장이 완료되었습니다.')); 
                    } 


                break; 


                // 상품 복사 
                case 'copy': 


                        if (empty($postValue['goodsNo']) === false) { 
                            foreach ($postValue['goodsNo'] as $goodsNo) { 
                                $goods->setCopyGoods($goodsNo); 
                            } 
                        } 


                        unset($postArray); 


                    if (Session::get('manager.isProvider') && Session::get('manager.scmPermissionInsert') == 'c') { 
                        $this->layer(__("승인을 요청하였습니다.")); 
                    }  else { 
                        $this->layer(__('복사가 완료 되었습니다.')); 
                    } 




                    break; 


                // 상품삭제상태 변경 
                case 'delete_state': 


                        if (empty($postValue['goodsNo']) === false) { 
                            $applyFl = $goods->setDelStateGoods($postValue['goodsNo']); 


                            unset($postArray); 


                            if($applyFl =='a') { 
                                $this->layer(__("승인을 요청하였습니다.")); 
                            } else { 
                                $this->layer(__('삭제 되었습니다.')); 
                             } 
 

                         } 
 

                     break; 
 

                 // 상품 삭제 
                 case 'delete': 
 

                         if (empty($postValue['goodsNo']) === false) { 
                             foreach ($postValue['goodsNo']as $goodsNo) { 
                                 $goods->setDeleteGoods($goodsNo); 
                             } 
                         } 
 

                         unset($postArray); 
 

                         $this->layer(__('삭제 되었습니다.')); 
 

                     break; 
                 case 'option_select': 
 

                         $result = $goods->getGoodsOptionSelect($postValue['goodsNo'], $postValue['optionVal'], $postValue['optionKey'], $postValue['mileageFl']); 
 

                         echo json_encode($result); 
                         exit; 
 

                     break; 
                 // 상품 일괄 품절처리 
                 case 'soldout': 
 

                         $applyFl  = $goods->setSoldOutGoods($postValue['goodsNo']); 
 

                         unset($postArray); 
 

                         if($applyFl =='a') { 
                             $this->layer(__("승인을 요청하였습니다.")); 
                         } else { 
                             $this->layer(__('품절처리 되었습니다.')); 
                         } 
 

 

                     break; 
 

                 // 상품승인 
                 case 'apply': 
 

                     if (empty($postValue['goodsNo']) === false) { 
 

                         foreach ($postValue['goodsNo']as $goodsNo) { 
                             $goods->setApplyGoods($goodsNo,$postValue['applyType'][$goodsNo]); 
                         } 
 

                     } 
 

                     unset($postArray); 
                     $this->layer(__('승인처리 되었습니다.')); 
 

                     break; 
 

                 // 상품반려 
                 case 'applyReject': 
 

                     if (empty($postValue['goodsNo']) === false) { 
 

                         $goods->setApplyRejectGoods($postValue['goodsNo'],$postValue['applyMsg']); 
 

                     } 
 

                     unset($postArray); 
 

                     $this->layer(__('반려처리 되었습니다.')); 
 

                     break; 
 

                 // 자주쓰는 옵션 등록 / 수정 
                 case 'option_register': 
                 case 'option_modify': 
 

                         $goods->saveInfoManageOption($postValue); 
 

                         $this->layer(__('저장이 완료되었습니다.')); 
 

                     break; 
 

                 // 자주쓰는 옵션 등록 (상품 상세에서 바로등록) 
                 case 'option_direct_register': 
 

                         $goods->saveInfoManageOption($postValue); 
 

                     exit(); 
                     break; 
 

                 // 자주쓰는 옵션 복사 
                 case 'option_copy': 
 

                         if (empty($postValue['sno']) === false) { 
                             foreach ($postValue['sno']as $sno) { 
                                 $goods->setCopyManageOption($sno); 
                             } 
                         } 
 

 

                         $this->layer(__('복사가 완료 되었습니다.')); 
 

                     break; 
 

                 // 자주쓰는 옵션 삭제 
                 case 'option_delete': 
 

                         if (empty($postValue['sno']) === false) { 
                             foreach ($postValue['sno']as $sno) { 
                                 $goods->setDeleteManageOption($sno); 
                             } 
                         } 
 

 

                         $this->layer(__('삭제 되었습니다.')); 
 

 

                     exit; 
                     break; 
 

                 // 상품 아이콘 등록 / 수정 
                 case 'icon_register': 
                 case 'icon_modify': 
 

                         $goods->saveInfoManageGoodsIcon($postValue); 
 

                          $this->layer(__('저장이 완료되었습니다.')); 
 

                     break; 
 

                 // 상품 아이콘 수정 
                 case 'icon_etc': 
 

                         $goods->saveInfoManageEtcIcon($postValue); 
 

                           $this->layer(__('저장이 완료되었습니다.')); 
 

                     break; 
 

                 // 상품 아이콘 삭제 
                 case 'icon_delete': 
 

                         if (empty($postValue['sno']) === false) { 
                             foreach ($postValue['sno']as $sno) { 
                                 $goods->setDeleteManageGoodsIcon($sno); 
                             } 
                         } 
 

 

                         $this->layer(__('삭제 되었습니다.')); 
 

                         exit; 
 

                     break; 
 

                 // 빠른 가격 수정 
                 case 'batch_price': 
 

                     if($postValue['isPrice'] =='y') { 
                         $data = $goods->setBatchPrice($postValue); 
                         echo json_encode(gd_htmlspecialchars_stripslashes(array('info'=>$data,'cnt'=>count($data))),JSON_FORCE_OBJECT); 
                         exit; 
                     } else { 
                         $applyFl = $goods->setBatchPrice($postValue); 
 

                         if($applyFl =='a') { 
                             $this->layer(__("승인을 요청하였습니다.")); 
                         } else { 
                             $this->layer(__('가격 수정이 완료 되었습니다.')); 
                         } 
                     } 
 

                     break; 
 

                 // 빠른 마일리지 수정 
                 case 'batch_mileage': 
 

                         $goods->setBatchMileage($postValue); 
 

                         if($postValue['type'] =='discount') { 
                             $this->layer(__('상품할인 수정이 완료 되었습니다.')); 
                         } else { 
                             $this->layer(__('마일리지 수정이 완료 되었습니다.')); 
                         } 
 

                     break; 
 

                 // 가격/마일리지/재고 수정 
                 case 'batch_stock': 
 

                         $applyFl = $goods->setBatchStock($postValue); 
 

                         if($applyFl =='a') { 
                             $this->layer(__("승인을 요청하였습니다.")); 
                         } else { 
                             $this->layer(__('품절/노출/재고 수정이 완료 되었습니다.')); 
                         } 
 

                     break; 
 

                 // 빠른 이동/복사/삭제 - 카테고리 연결 
                 case 'batch_link_category': 
 

                         $arrGoodsNo = $goods->setBatchGoodsNo(gd_isset($postValue['batchAll']), gd_isset($postValue['arrGoodsNo']), gd_isset($postValue['queryAll'])); 
 

                         $applyFl = $goods->setBatchLinkCategory($arrGoodsNo, $postValue['categoryCode']); 
 

                         if($applyFl =='a') { 
                             $this->layerNotReload(__("승인을 요청하였습니다.")); 
                         } else { 
                             $this->layerNotReload(__('선택한 상품에 대한 카테고리 연결이 완료 되었습니다.')); 
                         } 
 

                     break; 
 

                 // 빠른 이동/복사/삭제 - 카테고리 이동 
                 case 'batch_move_category': 
 

                         $arrGoodsNo = $goods->setBatchGoodsNo(gd_isset($postValue['batchAll']), gd_isset($postValue['arrGoodsNo']), gd_isset($postValue['queryAll'])); 
                         $applyFl = $goods->setBatchMoveCategory($arrGoodsNo, $postValue['categoryCode']); 
 

                         if($applyFl =='a') { 
                             $this->layerNotReload(__("승인을 요청하였습니다.")); 
                         } else { 
                             $this->layerNotReload(__('선택한 상품에 대한 카테고리 이동이 완료 되었습니다.')); 
                         } 
 

                     break; 
 

                 // 빠른 이동/복사/삭제 - 카테고리 복사 
                 case 'batch_copy_category': 
 

                         $arrGoodsNo = $goods->setBatchGoodsNo(gd_isset($postValue['batchAll']), gd_isset($postValue['arrGoodsNo']), gd_isset($postValue['queryAll'])); 
                         $applyFl = $goods->setBatchCopyCategory($arrGoodsNo, $postValue['categoryCode']); 
 

                         if($applyFl =='a') { 
                             $this->layerNotReload(__("승인을 요청하였습니다.")); 
                         } else { 
                             $this->layerNotReload(__('선택한 상품에 대한 카테고리 복사가 완료 되었습니다.')); 
                         } 
 

                     break; 
 

                 // 빠른 이동/복사/삭제 - 브랜드 교체 
                 case 'batch_link_brand': 
 

                         $arrGoodsNo = $goods->setBatchGoodsNo(gd_isset($postValue['batchAll']), gd_isset($postValue['arrGoodsNo']), gd_isset($postValue['queryAll'])); 
                     $applyFl = $goods->setBatchLinkBrand($arrGoodsNo, $postValue['brandCode']); 
 

                     if($applyFl =='a') { 
                         $this->layerNotReload(__("승인을 요청하였습니다.")); 
                     } else { 
                         $this->layerNotReload(__('선택한 상품에 대한 브랜드 교체가 완료 되었습니다.')); 
                     } 
 

                     break; 
 

                 // 빠른 이동/복사/삭제 - 카테고리 해제 
                 case 'batch_unlink_category': 
 

                         $arrGoodsNo = $goods->setBatchGoodsNo(gd_isset($postValue['batchAll']), gd_isset($postValue['arrGoodsNo']), gd_isset($postValue['queryAll'])); 
                     $applyFl = $goods->setBatchUnlinkCategory($arrGoodsNo); 
 

                     if($applyFl =='a') { 
                         $this->layer(__("승인을 요청하였습니다.")); 
                     } else { 
                         $this->layer(__('선택한 상품에 대한 카테고리 해제가 완료 되었습니다.')); 
                     } 
 

                     break; 
 

                 // 빠른 이동/복사/삭제 - 카테고리 부분 해제 
                 case 'batch_unlink_category_part': 
 

                     $arrGoodsNo = $goods->setBatchGoodsNo(gd_isset($postValue['batchAll']), gd_isset($postValue['arrGoodsNo']), gd_isset($postValue['queryAll'])); 
                     $applyFl = $goods->setBatchUnlinkCategory($arrGoodsNo,$postValue['categoryPartCode']); 
 

                     if($applyFl =='a') { 
                         $this->layerNotReload(__("승인을 요청하였습니다.")); 
                     } else { 
                         $this->layerNotReload(__('선택한 상품에 대한 카테고리 해제가 완료 되었습니다.')); 
                     } 
 

                     break; 
 

                 // 빠른 이동/복사/삭제 - 브랜드 해제 
                 case 'batch_unlink_brand': 
 

                         $arrGoodsNo = $goods->setBatchGoodsNo(gd_isset($postValue['batchAll']), gd_isset($postValue['arrGoodsNo']), gd_isset($postValue['queryAll'])); 
                         $applyFl = $goods->setBatchUnlinkBrand($arrGoodsNo); 
 

                         if($applyFl =='a') { 
                             $this->layer(__("승인을 요청하였습니다.")); 
                         } else { 
                             $this->layer(__('선택한 상품에 대한 브랜드 해제가 완료 되었습니다.')); 
                         } 
 

                     break; 
                 // 빠른 이동/복사/삭제 - 브랜드 부분 해제 
                 case 'batch_unlink_brand_part': 
                     $arrGoodsNo = $goods->setBatchGoodsNo(gd_isset($postValue['batchAll']), gd_isset($postValue['arrGoodsNo']), gd_isset($postValue['queryAll'])); 
                     $applyFl = $goods->setBatchUnlinkBrand($arrGoodsNo,$postValue['brandPartCode']); 
 

                     if($applyFl =='a') { 
                         $this->layerNotReload(__("승인을 요청하였습니다.")); 
                     } else { 
                         $this->layerNotReload(__('선택한 상품에 대한 브랜드 해제가 완료 되었습니다.')); 
                     } 
 

                     break; 
                 // 빠른 이동/복사/삭제 - 상품 삭제 
                 case 'batch_delete_goods': 
 

                         $arrGoodsNo = $goods->setBatchGoodsNo(gd_isset($postValue['batchAll']), gd_isset($postValue['arrGoodsNo']), gd_isset($postValue['queryAll'])); 
                         $applyFl = $goods->setDelStateGoods($arrGoodsNo); 
 

 

                         if($applyFl =='a') { 
                             $this->layer(__("승인을 요청하였습니다.")); 
                         } else { 
                             $this->layer(__('선택한 상품에 대한 삭제가 완료 되었습니다.')); 
                         } 
 

                     break; 
 

                 //  아이콘,색상 변경 
                 case 'batch_icon': 
 

                         $applyFl = $goods->setBatchIcon($postValue); 
 

                         if($applyFl =='a') { 
                             $this->layer(__("승인을 요청하였습니다.")); 
                         } else { 
                             $this->layer(__('아이콘/대표상품 변경이 완료 되었습니다.')); 
                         } 
 

                     break; 
                 //  배송 변경 
                 case 'batch_delivery': 
 

                         $applyFl = $goods->setBatchDelivery($postValue); 
                         if($applyFl =='a') { 
                             $this->layer(__("승인을 요청하였습니다.")); 
                         } else { 
                             $this->layer(__('배송비 수정이 완료 되었습니다.')); 
                         } 
 

                     break; 
                 //  네이버 쇼핑 상태 변경 
                 case 'batch_naver_config': 
 

                     $applyFl = $goods->setBatchNaverConfig($postValue); 
                     if($applyFl =='a') { 
                         $this->layer(__("승인을 요청하였습니다.")); 
                     } else { 
                         $this->layer(__('네이버쇼핑 노출여부 수정이 완료 되었습니다.')); 
                     } 
 

                     break; 
                 // 상품 순서 변경 
                 case 'goods_sort_change': 
 

                     $goods->setGoodsSortChange($postValue); 
                     $this->layer(__('상품 순서 변경이 완료 되었습니다.')); 
                     break; 
 

                 // 삭제상품 복구 
                 case 'goods_restore': 
 

                     $goods->setGoodsReStore($postValue); 
                     $this->layer(__('정상적으로 복구 되었습니다.')); 
                     break; 
 

                 case 'getStorage' : 
                     $storageName = Request::post()->get('storage'); 
                     $type = Request::post()->get('type'); 
                     if($type=='add_goods') $pathCode = Storage::PATH_CODE_ADD_GOODS; 
                     else $pathCode = Storage::PATH_CODE_GOODS; 
                     $savePath = Storage::disk($pathCode,$storageName)->getRealPath(''); 
                     echo $savePath; 
                     exit; 
                     break; 
 

                 case 'apply_goods_option': 
 

                     Request::get()->set("sno",$postValue['sno']); 
                     $data = $goods->getAdminListOption(); 
 

                     $displayFl = []; 
                     foreach($data['data'] as $k => $v) { 
                         $tmpOptionName[] = $v['optionName']; 
                         $displayFl[$v['optionDisplayFl']] = $v['optionDisplayFl']; 
                         for($i = 1; $i < 6; $i++) { 
                             if($v['optionValue'.$i]) $tmpOptionValue[] = explode(STR_DIVISION,$v['optionValue'.$i]); 
                         } 
                     } 
 

                     $setData['optionName'] = explode(STR_DIVISION,implode(STR_DIVISION,$tmpOptionName)); 
                     $setData['optionValue'] = $tmpOptionValue; 
                     if(count($displayFl) > 1) $setData['displayFl'] = ""; 
                     else $setData['displayFl'] = $data['data'][0]['optionDisplayFl']; 
 

                     echo json_encode($setData); 
 

                     exit; 
 

                     break; 
                 case 'get_category_flag' : 
                     $cate = \App::load('\\Component\\Category\\CategoryAdmin'); 
                     $result =  $cate->getCategoryFlag($postValue['cateCd']); 
                     echo json_encode($result); 
                     exit; 
                 case 'get_naver_stats': 
 

                     $setData = $goods->getNaverStats(); 
                     echo json_encode($setData); 
 

                     exit; 
                 case 'goods_sale': 
                     $applyFl = $goods->setGoodsSale($postValue); 
                     if($applyFl =='a') { 
                         $this->layer(__("상품 일괄수정승인을 요청하였습니다.")); 
                     } else { 
                         $this->layer(__('상품 일괄수정이 완료 되었습니다.')); 
                     } 
                     exit; 
                     break; 
 

                 case 'delete_goodsRestock' : 
                     $goods->deleteGoodsRestock($postValue); 
 

                     $this->layer(__('정상적으로 삭제 되었습니다.')); 
                     break; 
 

                 case 'populate': 
                     unset($postValue['mode']); 
                     if (empty($postValue['same']) === true) { 
                         $postValue['same'] = 'n'; 
                     } 
                     gd_set_policy('goods.populate', $postValue); 
 

                     $this->layer(__('정상적으로 저장 되었습니다.')); 
                     break; 
             } 
 

         } catch (Exception $e) { 
             throw new LayerException($e->getMessage()); 
         } 
     } 
 } 
 
 

 
   