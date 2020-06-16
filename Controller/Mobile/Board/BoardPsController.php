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
namespace Controller\Mobile\Board;

use Component\Board\BoardBuildQuery;
use Component\Board\BoardAct;
use Framework\Debug\Exception\AlertBackException;
use View\Template;
use Request;

class BoardPsController extends \Controller\Mobile\Controller
{

    public function index()
    {
        $req = Request::post()->toArray();
        switch ($req['mode']) {
            case 'duplicateOrderGoodsNo' :
                $cnt = BoardBuildQuery::init($req['bdId'])->selectCountByOrderGoodsNo($req['orderGoodsNo'],$req['bdSno']);
                if($cnt>0) {
                    exit('y');
                }
                exit('n');
                break;
            case 'delete':
                try {
                    $boardAct = new BoardAct($req);
                    $result = $boardAct->deleteData($req['sno']);
                    $msg = '';
                    if ($result == 'ok') {
                        $msg = __('삭제되었습니다');
                    }
                    $data = ['result' => $result, 'msg' => $msg];
                    echo $this->json($data);
                    exit;
                } catch (\Exception $e) {
                    $this->json(['result' => 'fail', 'msg' => $e->getMessage()]);
                }
                break;
            case 'modifyCheck' :
                $boardAct = new BoardAct($req);
                $result = $boardAct->checkModifyPassword($req['writerPw']);
                if ($result) {
                    echo $this->json(['result' => 'ok', 'msg' => '']);
                } else {
                    echo $this->json(['result' => 'fail', 'msg' => __('비밀번호가 틀렸습니다.')]);
                }
                exit;
                break;
            case 'modify':
            case 'write':
            case 'reply':
                $req['isMobile'] = true;
                try {
                    $boardAct = new BoardAct($req);
                    $addScrpt = '';
                    $msgs[] = $boardAct->saveData();
                    if ($msgs) {
                        foreach ($msgs as $msg) {
                            if (!$msg) continue;
                            $addScrpt .= 'alert("' . $msg . '");';
                        }
                    }
                    if (gd_isset($req['gboard']) == 'y') {
                        if(gd_isset($req['isBdBox']) == 'y') {
                          $this->js($addScrpt . 'alert("' . __('박스마루 영업팀에서 연락드리도록 하겠습니다.') . '");parent.location.reload();');
                        } else if(gd_isset($req['isBdMe']) == 'y') {
                          $this->js($addScrpt . 'alert("' . __('마일리지 교환신청이 접수되었습니다.\n 신청 후 상품권 발송까지 영업일기준 1~2일 소요됩니다.') . '");location.href="/mypage/mileage.php";');
                        } else {
                          $this->js($addScrpt . "alert('" . __('저장되었습니다.') . "');opener.updateBoard('".$req['bdId']."');self:close()");
                        }
                    } else if (gd_isset($req['gboard']) == 'r') {
                        if(gd_isset($req['isBdBox']) == 'y') {
                          $this->js($addScrpt . 'alert("' . __('박스마루 영업팀에서 연락드리도록 하겠습니다.') . '");parent.location.reload();');
                        } else if(gd_isset($req['isBdMe']) == 'y') {
                          $this->js($addScrpt . 'alert("' . __('마일리지 교환신청이 접수되었습니다.\n 신청 후 상품권 발송까지 영업일기준 1~2일 소요됩니다.') . '");location.href="/mypage/mileage.php";');
                        } else {
                          $this->js($addScrpt . "alert('" . __('저장되었습니다.') . "');parent.location.replace(document.referrer);");
                        }
                    } else {
                        if(gd_isset($req['isBdBox']) == 'y') {
                          $this->js($addScrpt . 'alert("' . __('박스마루 영업팀에서 연락드리도록 하겠습니다.') . '");location.href="/goods/box_estimate.php?' . $req['returnUrl'] . '";');
                        } else if(gd_isset($req['isBdMe']) == 'y') {
                          $this->js($addScrpt . 'alert("' . __('마일리지 교환신청이 접수되었습니다.\n 신청 후 상품권 발송까지 영업일기준 1~2일 소요됩니다.') . '");location.href="/mypage/mileage.php";');
                        } else {
                          $this->js($addScrpt . 'location.href="../board/list.php?' . $req['returnUrl'] . '";');
                        }
                    }
                    exit;

                } catch (\Exception $e) {
                    throw new AlertBackException($e->getMessage());
                }
                break;

            case 'ajaxUpload' : //ajax업로드
                try {
                    $boardAct = new BoardAct($req);
                    $fileData = Request::files()->get('uploadFile');
                    if(!$fileData){
                        $this->json(['result' => 'cancel']);
                    }
                    $result = $boardAct->uploadAjax($fileData);
                    if ($result['result'] == false) {
                        throw new \Exception(__('업로드에 실패하였습니다.'));
                    }
                    $this->json(['result' => 'ok', 'uploadFileNm' => $result['uploadFileNm'], 'saveFileNm' => $result['saveFileNm']]);
                } catch (\Exception $e) {
                    $this->json(['result' => 'fail', 'errorMsg' => $e->getMessage()]);
                }
                break;

            case  'deleteGarbageImage' :    //ajax업로드 시 가비지이미지 삭제
                $boardAct = new BoardAct($req);
                $boardAct->deleteUploadGarbageImage($req['deleteImage']);
                break;
        }

        switch (Request::get()->get('mode')) {
            case 'searchGoods':
                try {
                    $data = Request::get()->toArray();
                    $goodsSearch = \App::load('Component\Goods\GoodsSearch');
                    $getData = $goodsSearch->getSearchedGoodsList($data);
                    $page = \App::load('Component\Page\Page', Request::get()->get('page'), 1); // 페이지 설정
                    $page->recode['total'] = $getData['cnt']['search']; // 검색 레코드 수

                    $page->set_page();
                    if (isset($getData['goodsData']) === false) {
                        $getData['goodsData'] = '';
                    }
                    $jsonData = array('goodsData' => $getData['goodsData'], 'pager' => $page->getPage('SearchGoods.search(PAGELINK)'));
                } catch (\Exception $e) {
                    $jsonData[] = 'fail';
                    $jsonData[] = alert_reform($e->getMessage());
                }

                echo 'data=' . json_encode($jsonData);
                break;
            case 'recommend' :  //추천하기
                try {
                    $boardAct = new BoardAct(['bdId' => Request::get()->get('bdId')]);
                    $recommendCount = $boardAct->recommend(Request::get()->get('sno'));
                    echo $this->json(['message' => __('추천되었습니다.'), 'recommendCount' => $recommendCount]);
                } catch (\Exception $e) {
                    echo $this->json(['message' => $e->getMessage()]);
                }
                exit;
                break;

        }
        exit;
    }
}
