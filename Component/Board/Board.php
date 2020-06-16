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

namespace Component\Board;

use Component\Member\Util\MemberUtil;
use Component\Goods\AddGoodsAdmin;
use Component\Goods\Goods;
use Component\Mail\MailAuto;
use Component\Order\Order;
use Component\Page\Page;
use Component\Storage\Storage;
use Component\Validator\Validator;
use Component\Database\DBTableField;
use Framework\Debug\Exception\RequiredLoginException;
use Framework\Utility\ArrayUtils;
use Framework\Utility\ImageUtils;
use Framework\Utility\SkinUtils;
use Framework\Utility\StringUtils;
use Request;
use App;
use Respect\Validation\Rules\MyValidator;

/**
 * 게시판 Class
 *
 * @author sj
 * @version 1.0
 * @since 1.0
 * @copyright ⓒ 2016, NHN godo: Corp.
 */

define('REPLY_STATUS_ACCEPT', __('접수'));
define('REPLY_STATUS_WAIT', __('답변대기'));
define('REPLY_STATUS_COMPLETE', __('답변완료'));
define('KIND_DEFAULT', __('일반형'));
define('KIND_GALLERY', __('갤러리형'));
define('KIND_EVENT', __('이벤트형'));
define('KIND_QA', __('1:1 문의형'));

abstract class Board
{

    const ECT_INVALID_ARG = '%s.ECT_INVALID_ARG';
    const ECT_INSUFFICIENT_INPUTDATA = '%s.ECT_INSUFFICIENT_INPUTDATA';
    const ECT_NOTHAVE_AUTHORITY = '%s.ECT_NOTHAVE_AUTHORITY';
    const ECT_ERROR = '%s.ECT_ERROR';
    const TEXT_INVALID_ARG = '%s인자가 잘못되었습니다';
    const TEXT_INSUFFICIENT_INPUTDATA = '입력 정보가 부족합니다';
    const TEXT_NOTMATCH_PASSWORD = '비밀번호가 일치하지 않습니다';
    const TEXT_NOTHAVE_AUTHORITY = '%s권한이 없습니다.';
    const TEXT_UPLOAD_IMPOSSIBLE = '업로드가 불가능합니다';
    const EXCEPTION_CODE_AUTH = 700;
    const UPLOAD_DEFAULT_MAX_SIZE = 5;  //업로드 최대용량 디플트 값(mb)

    const PAGINATION_BLOCK_COUNT = 10;
    const PAGINATION_MOBILE_BLOCK_COUNT = 3;

    const BASIC_GOODS_REIVEW_ID = 'goodsreview';    //기본으로 설정되는 상품후기 게시판 아이디
    const BASIC_GOODS_QA_ID = 'goodsqa';    //기본으로 설정되는 상품문의 게시판 아이디
    const BASIC_QA_ID = 'qa';   //기본으로 설정되는 1:1문의 게시판 아이디
    const BASIC_NOTICE_ID = 'notice';   //기본으로 설정되는 공지사항 게시판 아이디
    const BASIC_EVENT_ID = 'event'; //기본으로 설정되는 이벤트 게시판 아이디
    const BASIC_COOPERATION_ID = 'cooperation'; //기본으로 설정되는 광고/제휴 게시판 아이디

    const REPLY_STATUS_ACCEPT = 1;
    const REPLY_STATUS_WAIT = 2;
    const REPLY_STATUS_COMPLETE = 3;
    const REPLY_STATUS_LIST = [self::REPLY_STATUS_ACCEPT => REPLY_STATUS_ACCEPT, self::REPLY_STATUS_WAIT => REPLY_STATUS_WAIT, self::REPLY_STATUS_COMPLETE => REPLY_STATUS_COMPLETE];
    const KIND_DEFAULT = 'default';
    const KIND_GALLERY = 'gallery';
    const KIND_EVENT = 'event';
    const KIND_QA = 'qa';
    const KIND_LIST = [self::KIND_DEFAULT => KIND_DEFAULT, self::KIND_GALLERY => KIND_GALLERY, self::KIND_EVENT => KIND_EVENT, self::KIND_QA => KIND_QA];

    protected $db;
    public $cfg = [];   //설정
    public $member = null;  //회원정보
    public $req;    //파라미터
    protected $goodsDataPool = [];
    protected $fieldTypes = [];
    protected $storage; //파일저장소
    protected $buildQuery;  //쿼리생성
    protected $channel; //채널
    protected $pagination;  //웹페이징

    /**
     * Board constructor.
     *
     * @param $req
     * @throws \Exception
     */
    public function __construct($req)
    {
        if (!is_object($this->db)) {
            $this->db = App::load('DB');
        }
        $this->req = StringUtils::xssArrayClean($req);
        gd_isset($req['page'], 1);
        $this->fieldTypes['bd_'] = DBTableField::getFieldTypes('tableBd');
        $this->fieldTypes['memo'] = DBTableField::getFieldTypes('tableBdMemo');

        $req['bdId'] = gd_htmlspecialchars_addslashes($req['bdId']);
        if (Validator::alphaNum($req['bdId'], true) === false || !$req['bdId']) {
            throw new \Exception(__('잘못된 접근입니다.'));
        }

        $boardConfig = new BoardConfig($req['bdId']);
        $this->cfg = &$boardConfig->cfg;    //게시판 설정 세팅
        if (!$this->cfg) {
            throw new \Exception(__('게시판 설정이 되지않았습니다.'));
        }

        $this->storage = Storage::disk(Storage::PATH_CODE_BOARD, $this->cfg['bdUploadStorage']);    //파일저장소세팅
        $this->buildQuery = BoardBuildQuery::init($req['bdId']);    //DAO세팅

        $this->cfg['auth']['write'] = $this->canWrite('w'); //작성권한
        $this->cfg['auth']['list'] = $this->canList();  //읽기권한
        $this->cfg['auth']['memo'] = $this->canWrite('m');  //댓글작성권한
        if ($this->cfg['bdTemplateSno'] > 0) {
            $templateType = $this->isAdmin() == true ? 'admin' : 'front';
            if ($templateType == 'front') {
                $boardTemplate = new BoardTemplate();
                $templateContents = $boardTemplate->getData($this->cfg['bdTemplateSno'], $templateType)['contents'];
                $this->cfg['templateContents'] = $templateContents;
                if ($this->cfg['bdEditorFl'] == 'n' || \Request::isMobile()) {
                    $this->cfg['templateContents'] = str_replace(["</p>", "<br>", "</br>"], "\n", $templateContents);
                    $this->cfg['templateContents'] = str_replace(["&nbsp;"], " ", $templateContents);
                    $this->cfg['templateContents'] = strip_tags($this->cfg['templateContents']);
                }
            }
        }

    }

    //작성자 노출형태 가공
    abstract protected function getWriterInfo($data, $refManagerSno);

    //리스트 보기권한
    abstract protected function canList();

    //상세 보기 권한
    abstract protected function canRead($data);

    //어드민 권한 여부
    abstract protected function isAdmin();

    /**
     * canWrite
     *
     * @param string $mode w : 일반글 , r : 답글 , m : 댓글
     * @param null $parentData 답글일때 부모글
     * @return string
     * @throws \Exception
     */
    abstract public function canWrite($mode = 'w', $parentData = null);

    //수정 권한
    abstract public function canModify($data);

    //삭제 권한
    abstract public function canRemove($data);

    //웹서비스 형태로 데이터 가공(리스트)
    abstract public function applyConfigList(&$data);

    //웹서비스 형태로 데이터 가공(상세)
    abstract public function applyConfigView(&$data);

    public function getConfig($key = null)
    {
        if ($key == null) {
            return $this->cfg;
        }

        return $this->cfg[$key];
    }

    public function canUseMobile()
    {
        return $this->cfg['bdUseMobileFl'] == 'y' ? true : false;
    }

    public function canUsePc()
    {
        return $this->cfg['bdUsePcFl'] == 'y' ? true : false;
    }

    public function checkUsePc()
    {
        if (gd_is_admin() == false && $this->canUsePc() == false) {
            throw new \Exception(__('해당 게시판은 Pc에서 접속이 제한되어 있습니다.'));
        }
    }

    public function checkUseMobile()
    {
        if (gd_is_admin() == false && $this->canUseMobile() == false) {
            throw new \Exception(__('해당 게시판은 모바일에서 접속이 제한되어 있습니다.'));
        }
    }

    /**
     * 말머리박스 생성
     *
     * @param string $curCategory 선택된 말머리
     * @param string $attr select박스에 추가될 attribute
     * @param mixed $bWrite true : 셀렉트박스상단에 추가단어 없음 / false : 셀렉트박스 상단에 '=전체선택=' 추가(기본) / 셀렉트박스 상단에 추가할 단어
     *
     * @return string data
     */
    public function getCategoryBox(&$curCategory = '', $attr = null, $bWrite = false)
    {
        if ($this->cfg['bdCategoryFl'] == 'y') {
            $arrCategory = $this->cfg['arrCategory'];
            $categoryTitle = gd_isset($this->cfg['bdCategoryTitle']);
            if ($bWrite !== true) {
                if ($bWrite === false) {
                    if (!$categoryTitle) {
                        $categoryTitle = __('=' . '전체선택' . '=');
                    }
                } else {
                    $categoryTitle = $bWrite;
                }
            }

            // @qnibus 2017-05-30 개행문자 들어가는 경우 제거 처리
            $arrCategory = ArrayUtils::removeEmpty($arrCategory);

            return gd_select_box('category', 'category', $arrCategory, null, $curCategory, $categoryTitle, $attr);
        }
        return '';
    }

    /**
     * checkPassword
     *
     * @param $data
     * @param $password
     * @param bool $isEncryption
     * @return bool
     */
    protected function checkPassword($data, $password, $isEncryption = true)
    {
        $auth = $this->canModify($data);
        switch ($auth) {
            case 'y':
                return true;
                break;
            case 'c':
                if ($password) {
                    $pw = $isEncryption ? md5($password) : $password;

                    $this->db->strField = "COUNT(*) AS cnt";
                    $this->db->strWhere = "sno=? AND  writerPw=?";
                    $arrBind = [];
                    $this->db->bind_param_push($arrBind, 'i', $data['sno']);
                    $this->db->bind_param_push($arrBind, $this->fieldTypes['bd_']['writerPw'], $pw);
                    $query = $this->db->query_complete();
                    $strSQL = "SELECT " . array_shift($query) . " FROM " . DB_BD_ . $this->cfg['bdId'] . " " . implode(' ', $query);
                    $cnt = $this->db->query_fetch($strSQL, $arrBind, false);
                    if ($cnt['cnt'] > 0) {
                        return true;
                    }
                }
                return false;
                break;
            case 'n':
                return false;
                break;
        }
    }

    protected function setGoodsDataPool($goodsNo)
    {
        $mallBySession = \SESSION::get(SESSION_GLOBAL_MALL);
        $arrBind = [];
        $goodsImageQuery = "SELECT  g.scmNo,  g.goodsNo ,g.goodsNm, g.imageStorage, g.imagePath , g.goodsPrice ,  gi.imageKind, gi.imageName  FROM " . DB_GOODS . " AS g LEFT OUTER JOIN " . DB_GOODS_IMAGE . " as gi ON g.goodsNo = gi.goodsNo WHERE g.goodsNo=?";
        $this->db->bind_param_push($arrBind, 's', $goodsNo);
        $imageResult = $this->db->query_fetch($goodsImageQuery, $arrBind);
        foreach ($imageResult as $val) {
            if ($mallBySession) {
                $strSQLGlobal = "SELECT gg.goodsNm FROM " . DB_GOODS_GLOBAL . " as gg WHERE gg.goodsNo = '" . $val['goodsNo'] . "' AND gg.mallSno = '" . $mallBySession['sno'] . "'";
                $tmpData = $this->db->query_fetch($strSQLGlobal, '', false);
            }
            $goodsData[$val['imageKind']] = $val['imageName'];
            $goodsData['scmNo'] = $val['scmNo'];
            $goodsData['goodsNo'] = $val['goodsNo'];
            $goodsData['goodsNm'] = gd_isset($tmpData['goodsNm'], $val['goodsNm']);
            $goodsData['imageStorage'] = $val['imageStorage'];
            $goodsData['imagePath'] = $val['imagePath'];
            $goodsData['goodsPrice'] = $val['goodsPrice'];
        }

        $goodsData['imageName'] = $goodsData['main'] ?? $goodsData['magnify'] ?? $goodsData['detail'] ?? $goodsData['list']; //대표이미지
        $goodsData['thumbImageName'] = PREFIX_GOODS_THUMBNAIL . $goodsData['detail']; //대표이미지
        $goodsData['goodsImageSrc'] = SkinUtils::imageViewStorageConfig($goodsData['imageName'], $goodsData['imagePath'], $goodsData['imageStorage'], 100, 'goods')[0];
        $goodsData['goodsThumbImageSrc'] = SkinUtils::imageViewStorageConfig($goodsData['thumbImageName'], $goodsData['goodsImageThumbPath'], $goodsData['imageStorage'], 80, 'goods')[0];
        return $goodsData;
    }

    /**
     * 이미지 첨부파일가져오기
     *
     * @param string $uploadedFile 업로드파일이름
     * @param string $savedFile 저장된파일이름
     *
     * @param $bdUploadStorage
     * @param $bdUploadPath
     * @return string
     */
    public function getUploadedImage($uploadedFile, $savedFile, $bdUploadStorage, $bdUploadPath)
    {
        if (empty($uploadedFile) === false) {
            $uFiles = explode(STR_DIVISION, $uploadedFile);
            $sFiles = explode(STR_DIVISION, $savedFile);
            if ($bdUploadStorage) {
                for ($i = 0; $i < count($uFiles); $i++) {
                    if ($this->isAllowImageExtention($uFiles[$i])) {
                        $upFilePath = $bdUploadPath . $sFiles[$i];
                        $path = $this->storage->getHttpPath($upFilePath);
                        if ($this->storage->getSize($bdUploadPath . $sFiles[$i]) > 0) {
                            $imgFiles[] = ['fid' => $i, 'imgPath' => $path];
                        }

                    }
                }
            }
        }

        return gd_isset($imgFiles);
    }

    /**
     * 내용 설정(검색어 강조)
     *
     * @param string $contents 내용
     * @param $data 게시글 data
     * @param bool $isMemo 댓글여부
     * @param bool $isMobile 모바일작성여부
     * @param bool $isAnswer 1:1유형 게시판 답변 여부
     * @return string
     * @internal param string $memoFl 댓글여부
     */
    public function setContents($contents, $data, $isMemo = false, $isMobile = false, $isAnswer = false)
    {
        if ($isMemo) {   //댓글은 태그 삭제
            $contents = strip_tags($contents);
        }

        if ($isMobile) {
            $contents = nl2br($contents);
        } else {
            if ($isMemo) {
                $contents = gd_string_nl2br(str_replace(['  ', '\t'], ['&nbsp; ', '&nbsp; &nbsp; '], $contents));
            } else {
                if ($this->cfg['bdEditorFl'] == 'n') {
                    $contents = nl2br($contents);
                }
                $contents = str_replace(['  ', '\t'], ['&nbsp; ', '&nbsp; &nbsp; '], $contents);
                if (gd_isset($this->req['word']) && in_array('contents', $this->req['key'])) {
                    $contents = str_replace($this->req['word'], '<span style="background-color:yellow">' . $this->req['word'] . '</span>', $contents);
                }
            }
        }

        if ($isMemo === false) {
            if ($this->cfg['bdAttachImageDisplayFl'] == 'y' && $isAnswer == false) {
                $uploadImageList = $this->getUploadedImage($data['uploadFileNm'], $data['saveFileNm'], $data['bdUploadStorage'], $data['bdUploadPath']);
                foreach ($uploadImageList as $val) {
                    $imgUrl = $val['imgPath'];
                    if (\Request::isMobile()) {
                        $imgTag = '<img src="' . $imgUrl . '" style="max-width:100%">';
                    } else {
                        if ($this->cfg['bdAttachImageMaxSize']) {
                            $imgTag = '<img src="' . $imgUrl . '" style="max-width:' . $this->cfg['bdAttachImageMaxSize'] . 'px">';
                        }
                    }
                    $arrUploadImage[] = $imgTag;
                }
                if ($arrUploadImage) {
                    $divTag = '<div style="margin:10px 0 10px 0">';
                    if ($this->cfg['bdAttachImagePosition'] == 'top') {
                        $contents = $divTag . implode('</div>' . $divTag, $arrUploadImage) . '</div>' . $contents;
                    } else {
                        $contents .= $divTag . implode('</div>' . $divTag, $arrUploadImage) . '</div>';
                    }
                }
            }
        }


        $contents = $this->xssClean(gd_htmlspecialchars_stripslashes($contents));
        return $contents;
    }

    protected function xssClean($data)
    {

        $data = preg_replace('#(<[^>]+?[\x00-\x20"\'])(?:on|xmlns)[^>]*+>#iu', '$1>', $data);

        // Remove javascript: and vbscript: protocols
        $data = preg_replace('#([a-z]*)[\x00-\x20]*=[\x00-\x20]*([`\'"]*)[\x00-\x20]*j[\x00-\x20]*a[\x00-\x20]*v[\x00-\x20]*a[\x00-\x20]*s[\x00-\x20]*c[\x00-\x20]*r[\x00-\x20]*i[\x00-\x20]*p[\x00-\x20]*t[\x00-\x20]*:#iu', '$1=$2nojavascript...', $data);
        $data = preg_replace('#([a-z]*)[\x00-\x20]*=([\'"]*)[\x00-\x20]*v[\x00-\x20]*b[\x00-\x20]*s[\x00-\x20]*c[\x00-\x20]*r[\x00-\x20]*i[\x00-\x20]*p[\x00-\x20]*t[\x00-\x20]*:#iu', '$1=$2novbscript...', $data);
        $data = preg_replace('#([a-z]*)[\x00-\x20]*=([\'"]*)[\x00-\x20]*-moz-binding[\x00-\x20]*:#u', '$1=$2nomozbinding...', $data);

        // Only works in IE: <span style="width: expression(alert('Ping!'));"></span>
        $data = preg_replace('#(<[^>]+?)style[\x00-\x20]*=[\x00-\x20]*[`\'"]*.*?expression[\x00-\x20]*\([^>]*+>#i', '$1>', $data);
        $data = preg_replace('#(<[^>]+?)style[\x00-\x20]*=[\x00-\x20]*[`\'"]*.*?behaviour[\x00-\x20]*\([^>]*+>#i', '$1>', $data);
        $data = preg_replace('#(<[^>]+?)style[\x00-\x20]*=[\x00-\x20]*[`\'"]*.*?s[\x00-\x20]*c[\x00-\x20]*r[\x00-\x20]*i[\x00-\x20]*p[\x00-\x20]*t[\x00-\x20]*:*[^>]*+>#iu', '$1>', $data);

        // Remove namespaced elements (we do not need them)
        $data = preg_replace('#</*\w+:\w[^>]*+>#i', '', $data);
        do {
            // Remove really unwanted tags
            $old_data = $data;
            $data = preg_replace('#</*(?:applet|b(?:ase|gsound|link)|frame(?:set)?|i(layer)|l(?:ayer|ink)|meta|object|s(?:cript|tyle)|title|xml)[^>]*+>#i', '', $data);
        } while ($old_data !== $data);

        $arrBdAllowTags = explode(STR_DIVISION, $this->cfg['bdAllowTags']);
        $regExp = '/<(iframe|embed) [^>]*src=([\'"][^\'"]+[\'"])[^>]*>/i';
        $bdAllowDomain = explode(STR_DIVISION, $this->cfg['bdAllowDomain']);
        preg_match_all($regExp, $data, $matches);
        for ($i = 0; $i < count($matches[0]); $i++) {
            $startTag = $matches[1][$i];
            $src = preg_replace('/[\'""]/', '', $matches[2][$i]);
            if (in_array($startTag, $arrBdAllowTags)) {
                $domainCheck = false;
                foreach ($bdAllowDomain as $allowUrl) {
                    if (strpos($src, $allowUrl) !== false) {
                        $domainCheck = true;
                        break;
                    }
                }
            } else {
                $domainCheck = false;
            }

            if ($domainCheck === false) {
                $convertTag = '<!--' . __('허용되지 않은 태그입니다.') . '-->' . str_replace($src, '', $matches[0][$i]);
                $data = str_replace($matches[0][$i], $convertTag, $data);
            }
        }
        return $data;
    }

    /**
     * 댓글가져오기
     *
     * @param int $sno 글번호
     *
     * @return array
     */
    protected function getMemo($sno)
    {
        $resMemo = $this->buildQuery->selectMemoList($sno);
        foreach ($resMemo as $memoData) {
            if ($memoData['groupThread'] != '') {
                $memoData['gapReply'] = '<span style="margin-left:' . (((strlen($memoData['groupThread']) / 2) - 1) * 15) . 'px"></span>';
            }
            $memoData['auth'] = $this->canModify($memoData);
            $memoData['isAdmin'] = 'n';
            $memoData['workedMemo'] = $this->setContents($memoData['memo'], $memoData, true);
            $memoData['writer'] = $this->getWriterInfo($memoData);
            $returnArr[] = $memoData;
        }
        return $returnArr;
    }

    protected function getList($isPaging = true, $listCount = 10, $subjectCut = 0, $arrWhere = [], $arrInclude = null, $displayNotice)
    {
        //리스트 권한체크
        if ($this->canList() == 'n') {
            if (MemberUtil::isLogin() === false) {
                throw new RequiredLoginException();
            }
            throw new \Exception(__('접근 권한이 없습니다.'), Board::EXCEPTION_CODE_AUTH);
        }

        $this->cfg['bdSubjectLength']  = $subjectCut  ? $subjectCut : $this->cfg['bdSubjectLength'];
        $offset = ($this->req['page'] - 1) * $listCount;

        if ($displayNotice === true) {
            if ($this->cfg['bdOnlyMainNotice'] == 'n' || ($this->cfg['bdOnlyMainNotice'] == 'y' && $this->req['page'] == 1)) {
                $noticeArticleData = $this->getNoticeList($this->cfg['bdNoticeCount'], $arrInclude);
            }

            $getData['noticeData'] = gd_htmlspecialchars_stripslashes($noticeArticleData);
            $this->applyConfigList($getData['noticeData']);
        }
        $bdIncludeReplayInSearchTypeKey = $this->isAdmin() ? 'admin' : 'front';
        $checkBdIncludeReplayInSearchTypeKey = $this->cfg['bdIncludeReplayInSearchType'][$bdIncludeReplayInSearchTypeKey] == 'y' && $this->req['searchWord'];
        $articleData = $this->buildQuery->selectList($this->req, $arrWhere, $offset, $listCount, $arrInclude);

        $getData['data'] = gd_htmlspecialchars_stripslashes($articleData);
        if ($checkBdIncludeReplayInSearchTypeKey) {
            foreach($articleData as $val){
                if($val['parentSno']==0){
                    $parentSno[] =$val['sno'];
                }
            }
            foreach ($getData['data'] as $val) {
                $migrationArticleData[] = $val;
                if ((in_array($val['sno'],$parentSno))) {
                    $childData = $this->getChildListByGroupNo($val['groupNo']);
                    foreach ($childData as $_val) {
                        $_val['noCount'] = 'y';
                        if($val['goodsNo'] == $_val['goodsNo']){
                            $_val['imageName'] = $val['imageName'];
                            $_val['imagePath'] = $val['imagePath'];
                            $_val['imageStorage'] = $val['imageStorage'];
                        }
                        $migrationArticleData[] = $_val;
                    }
                }
            }
            $getData['data'] = $migrationArticleData;
        }

        //웹서비스형태로 데이터 가공
        $this->applyConfigList($getData['data']);
        if (gd_array_empty($getData['data']) === true) return $getData;

        //페이징에 필요한 데이터 가공
        if ($isPaging) {
            $searchCnt = $this->getCount($arrWhere);  //front
            $listNo = $searchCnt - $offset;
            if ($getData['data']) {
                foreach ($getData['data'] as &$articleData) {
                    if (!$articleData['noCount']) {
                        $articleData['listNo'] = $listNo;
                        $articleData['articleListNo'] = $listNo + $this->cfg['bdStartNum'] - 1;
                        $listNo--;
                    } else {
                        $articleData['listNo'] = $listNo + $this->cfg['bdStartNum'];
                        $articleData['articleListNo'] = '-';
                    }
                }
            }

            $totalCnt = $this->buildQuery->selectCount(['bdId' => $this->req['bdId']], $arrWhere);

            $this->pagination = new Page($this->req['page'], $searchCnt, $totalCnt, $listCount, self::PAGINATION_BLOCK_COUNT);
            $this->pagination->setUrl(Request::getQueryString());
            $getData['pagination'] = $this->pagination;
            $getData['cnt']['search'] = $searchCnt;
            $getData['cnt']['total'] = $totalCnt;
            $getData['cnt']['totalPage'] = $this->pagination->page['total'];

            $getData['sort'] = [
                'b.groupNo asc' => __('번호↓'),
                'b.groupNo desc' => __('번호↑'),
                'b.regDt desc' => __('등록일↓'),
                'b.regDt asc' => __('등록일↑'),
            ];
        }

        return $getData;
    }

    public function getView() {
        if(Validator::number($this->req['sno'],null,null,true) === false){
            throw new \Exception(__('잘못된 접근입니다.'));
        }

        $arrBind = null;
        $getData = $this->buildQuery->selectOne($this->req['sno']);
        if (!$getData) {
            throw new \Exception(__('존재하지 않는 게시글입니다.'));
        }
        $getData['extraData'] = $this->getExtraData($this->req['sno']);
        //권한체크
        $auth = $this->canRead($getData);
        if ($auth == 'n') {
            if ($getData['isSecret'] != 'y' && MemberUtil::isLogin() === false) {
                throw new RequiredLoginException();
            }
            throw new \Exception(__('접근 권한이 없습니다.'));
        } else if ($auth == 'c') {
            if (empty($getData['groupThread']) === false) {  //답글이면
                $getParentData = $this->buildQuery->selectOne($getData['parentSno']);
                $checkPassword = $this->checkPassword($getParentData, $this->req['writerPw']);  //답변글은 부모글의 비밀번호와 본인글의 비밀번호 둘다허용용
                if(!$checkPassword && $getData['memNo']< 0 == false) {   // 부모패스워드가 틀렷을경우 본글의 패스워드를 테스트(관리자글인경우제외)
                    $checkPassword = $this->checkPassword($getData, $this->req['writerPw']);
                }

            } else {
                $checkPassword = $this->checkPassword($getData, $this->req['writerPw']);
            }

            if ($checkPassword === true) {
                $getData['auth']['view'] = 'y';
                $getData['auth']['modify'] = 'y';
            } else {
                throw new \Exception(__('비밀번호가 일치하지 않습니다.'));
            }
        }

        $getData = gd_htmlspecialchars_stripslashes($getData);
        $this->applyConfigView($getData);   //웹서비스형태로 데이터 가공

        return $getData;
    }

    public function getPagination()
    {
        return $this->pagination;
    }

    /**
     * 공지사항 리스트
     *
     * @param int $limit
     * @param null $arrInclude
     * @return mixed
     * @internal param null $arrExclude
     */
    public function getNoticeList($limit = 10, $arrInclude = null)
    {
        $result = $this->buildQuery->selectList(['isNotice' => 'y'], ["isDelete = 'n' "], 0, $limit, $arrInclude);
        return $result;
    }

    public function getCount($arrWhere = [])
    {
        $total = $this->buildQuery->selectCount($this->req, $arrWhere);  //fornt
        return $total;
    }

    /**
     * 프론트 상품상세->상품후기.문의에 올린글인지 체크
     *
     * @param $data
     * @return bool
     */
    protected function checkSelectGoodsPage($data)
    {
        if ($this->cfg['goodsType'] != 'goods') {
            return false;
        }

        $extraData = $this->getExtraData($data['sno']);
        if (!$extraData['arrGoodsData'] && $data['goodsNo'] && ($this->cfg['bdId'] == Board::BASIC_GOODS_REIVEW_ID || $this->cfg['bdId'] == Board::BASIC_GOODS_QA_ID)) {
            return true;
        }

        return false;
    }

    /**
     * 상품선택기능 노출여부
     *
     * @param null $data
     * @return bool
     */
    protected function canWriteGoodsSelect($data = null)
    {
        if ($this->cfg['goodsType'] != 'goods') {
            return false;
        }

        if ($this->req['goodsNo'] && Request::isMobile()) {
            return false;
        }

        if ($this->req['mode'] == 'reply' || $data['groupThread']) {
            return false;
        }

        if ($this->req['mode'] == 'modify') {
            return $this->checkSelectGoodsPage($data) == false;
        }

        return true;
    }

    protected function canWriteOrderSelect($data = null)
    {
        if ($this->req['mode'] == 'reply' || $data['groupThread']) {
            return false;
        }

        if ($this->isAdmin() === false && gd_is_login() === false) {
            return false;
        }

        if ($this->cfg['goodsType'] == 'order') {
            return true;
        }

        return false;
    }

    /**
     * 글저장하기
     */
    public function saveData()
    {
        if (empty($this->channel)) {
            $this->checkAntiSpam();
        }

        BoardUtil::checkForbiddenWord($this->req['subject']);
        BoardUtil::checkForbiddenWord($this->req['contents']);

        if ($this->cfg['bdSecretFl'] == '2') {
            $this->req['isSecret'] = 'n';
        } else if ($this->cfg['bdSecretFl'] == '3') {
            $this->req['isSecret'] = 'y';
        }

        if (gd_isset($this->req['urlLink']) && !preg_match("/^((http(s?))\:\/\/)([0-9a-zA-Z\-]+\.)+[a-zA-Z]{2,6}(\:[0-9]+)?(\/\S*)?$/", $this->req['urlLink'])) {
            $this->req['urlLink'] = "http://" . $this->req['urlLink'];
        }

        $validator = new Validator();
        $arrData = [];
        gd_isset($this->req['isNotice'], 'n');
        gd_isset($this->req['isSecret'], 'n');
        if (empty($this->channel)) {
            $remoteAddr = Request::getRemoteAddress();
        }
        $isMobile = $this->req['isMobile'] ? 'y' : 'n';
        $memNo = gd_isset($this->member['memNo'], 0);
        $encryptPw = md5(gd_isset($this->req['writerPw']));
        $parentSno = 0;

        if (gd_is_login()) {
            $this->req['writerNm'] = $this->member['memNm'];
        }
        $writerId = $this->member['memId'];
        $writerNick = $this->member['memNick'];

        $updateExtraDataKey = null;

        if ($this->cfg['goodsType'] == 'goods') {
            if (is_array($this->req['goodsNo'])) {
//                $updateExtraDataKey['goodsNoText'] = implode(STR_DIVISION, $this->req['goodsNo']);
                $updateExtraDataKey['goodsNoText'] = $this->req['goodsNo'][0];
                $goodsNo = $this->req['goodsNo'][0];
            } else {
                $goodsNo = $this->req['goodsNo'];
            }
            $goodsNo = $goodsNo ?? '';
        }

        if ($this->cfg['goodsType'] == 'order') {
            $order = new Order();
            $updateExtraDataKey['orderGoodsNoText'] = null;
            $goodsNo = '';
            $orderNo = '';
            if ($this->req['orderGoodsNo']) {
                $goodsData = $order->getOrderGoods(null, $this->req['orderGoodsNo'], null, null, null)[0];
                $orderNo = $goodsData['orderNo'];
                if ($goodsData['goodsType'] == 'addGoods') {  //추가상품인경우 구분
                    $updateExtraDataKey['orderGoodsNoText'] = 'A' . $this->req['orderGoodsNo'][0];
                    $goodsNo = $goodsData['goodsNo'];
                } else {
                    $updateExtraDataKey['orderGoodsNoText'] = $this->req['orderGoodsNo'][0];
                    $goodsNo = $goodsData['goodsNo'];
                }
            }
        }

        switch ($this->req['mode']) {
            case 'write' :
                $canWrite = $this->canWrite();
                if ($canWrite == 'n') {
                    throw new \Exception(__('접근 권한이 없습니다.'));
                } else if (is_array($canWrite)) {
                    if ($canWrite['result'] === false) {
                        throw new \Exception($canWrite['msg']);
                    }
                }

                $groupNo = BoardUtil::createGroupNo($this->cfg['bdId']);

                break;
            case "modify": {
                $modify = $this->buildQuery->selectOne($this->req['sno']);
                if ($this->canModify($modify) == 'n') {
                    throw new \Exception(__('접근 권한이 없습니다.'));
                }
                if (empty($this->channel)) {
                    $this->handleBeforeModify($modify);
                }
                $groupNo = $modify['groupNo'];
                $groupThread = $modify['groupThread'];
                $preFile['uploadFileNm'] = $modify['uploadFileNm'];
                $preFile['saveFileNm'] = $modify['saveFileNm'];

                if ($this->checkSelectGoodsPage($modify)) {   //상품상세페이지에서 등록한글이면 상품번호 수정안됨.
                    $goodsNo = $modify['goodsNo'];
                }
                break;
            }
            case "reply": {
                $parentData = $this->buildQuery->selectOneWithGoodsAndMember($this->req['sno']);
                if ($this->canWrite('r', $parentData) == 'n') {
                    throw new \Exception(__('접근 권한이 없습니다.'));
                }
                if ($this->cfg['bdId'] == Board::BASIC_GOODS_QA_ID || $this->cfg['bdId'] == Board::BASIC_GOODS_REIVEW_ID) {   //상품상세 후기,문의는 부모상품번호 따라감.
                    $goodsNo = $parentData['goodsNo'];
                }

                $parentSno = $parentData['sno'];
                $groupNo = $parentData['groupNo'];
                $groupThread = BoardUtil::createGroupThread($this->cfg['bdId'], $groupNo, $parentData['groupThread']);
                $this->setSaveData('sendEmailFl', $sendEmailFl, $arrData, $validator);
                $this->setSaveData('sendSmsFl', $sendSmsFl, $arrData, $validator);
                break;
            }
        }
        //ajax업로드 사용 시
        if ($this->req['uploadType'] == 'ajax') {
            $file = $this->multiAjaxUpload($preFile['uploadFileNm'], $preFile['saveFileNm']);
        } else {
            $file = $this->multiUpload(['uploadFileNm' => gd_isset($preFile['uploadFileNm']), 'saveFileNm' => gd_isset($preFile['saveFileNm'])]);
        }


        switch ($this->req['mode']) {
            case "write":
            case "reply":
                $this->setSaveData('writerPw', $encryptPw, $arrData, $validator);
                $this->setSaveData('memNo', $memNo, $arrData, $validator);
                $this->setSaveData('writerIp', $remoteAddr, $arrData, $validator);
                $this->setSaveData('parentSno', $parentSno, $arrData, $validator);
                $this->setSaveData('isMobile', $isMobile, $arrData, $validator);
                $this->setSaveData('writerNm', $this->req['writerNm'], $arrData, $validator);
                $this->setSaveData('writerId', $writerId, $arrData, $validator);
                $this->setSaveData('writerNick', $writerNick, $arrData, $validator);
            case "modify":
                $this->setSaveData('orderNo', $orderNo, $arrData, $validator);
                $this->setSaveData('groupNo', $groupNo, $arrData, $validator);
                $this->setSaveData('groupThread', $groupThread, $arrData, $validator);
                $this->setSaveData('writerEmail', $this->req['writerEmail'], $arrData, $validator);
                $this->setSaveData('writerHp', $this->req['writerHp'], $arrData, $validator);
                $this->setSaveData('writerMobile', $this->req['writerMobile'], $arrData, $validator);
                $this->setSaveData('subject', $this->req['subject'], $arrData, $validator);
                $this->setSaveData('contents', $this->req['contents'], $arrData, $validator);
                $this->setSaveData('urlLink', $this->req['urlLink'], $arrData, $validator);
                $this->setSaveData('uploadFileNm', $file['uploadFileNm'], $arrData, $validator);
                $this->setSaveData('subSubject', $this->req['subSubject'], $arrData, $validator);
                $this->setSaveData('saveFileNm', $file['saveFileNm'], $arrData, $validator);
                $this->setSaveData('bdUploadStorage', $file['bdUploadStorage'], $arrData, $validator);
                $this->setSaveData('bdUploadPath', $file['bdUploadPath'], $arrData, $validator);
                $this->setSaveData('bdUploadThumbPath', $file['bdUploadThumbPath'], $arrData, $validator);
                $this->setSaveData('isNotice', $this->req['isNotice'], $arrData, $validator);
                // 컬럼 추가
                $this->setSaveData('writerUse', $this->req['writerUse'], $arrData, $validator);
                $this->setSaveData('writerTel', $this->req['writerTel'], $arrData, $validator);
                $this->setSaveData('writerFax', $this->req['writerFax'], $arrData, $validator);
                $this->setSaveData('writerAddr', $this->req['writerAddr'], $arrData, $validator);
                //

                if ($this->channel) {
                    $this->setSaveData('channel', $this->channel, $arrData, $validator);
                    $this->setSaveData('apiExtraData', $this->req['apiExtraData'], $arrData, $validator);
                }
                $this->setSaveData('isSecret', $this->req['isSecret'], $arrData, $validator);
                $this->setSaveData('category', $this->req['category'], $arrData, $validator);
                $this->setSaveData('goodsNo', $goodsNo, $arrData, $validator);
                $this->setSaveData('goodsPt', $this->req['goodsPt'], $arrData, $validator);
                if ($this->cfg['bdReplyStatusFl'] == 'y') {
                    $replyStatus = gd_isset($this->req['replyStatus'], Board::REPLY_STATUS_ACCEPT);
                    $this->setSaveData('replyStatus', $replyStatus, $arrData, $validator);
                    $this->setSaveData('answerSubject', $this->req['answerSubject'], $arrData, $validator);
                    $this->setSaveData('answerContents', $this->req['answerContents'], $arrData, $validator);
                }

                if ($this->cfg['bdEventFl'] == 'y') {
                    if (gd_isset($this->req['eventEnd'])) {
                        $this->req['eventEnd'] = $this->req['eventEnd'] . ' 23:59';
                    }
                    $this->setSaveData('eventStart', $this->req['eventStart'], $arrData, $validator);
                    $this->setSaveData('eventEnd', $this->req['eventEnd'], $arrData, $validator);
                }
        }

        if ($validator->act($arrData, true) === false) {
            $validKeyName = ['subject' => __('제목'), 'contents' => __('내용'), 'writerNm' => __('작성자명')];
            foreach ($validator->errors as $key => $row) {
                if (array_key_exists($key, $validKeyName)) {
                    $errorMsg = sprintf(__('%1$s 을 입력하시기 바랍니다.'), $validKeyName[$key]);
                    break;
                }
            }

            if (!$errorMsg) {
                $errorMsg = sprintf(__('%1$s 은(는) 유효하지 않는 값입니다.'), implode("\n", $validator->errors));
            }
            throw new \Exception($errorMsg);
        }
        switch ($this->req['mode']) {
            case 'write':
                $insId = $this->buildQuery->insert($arrData);
                $data = $this->buildQuery->selectOneWithGoodsAndMember($insId);
                if ($updateExtraDataKey) {
                    $updateExtraDataKey['bdSno'] = $insId;
                    $this->buildQuery->insertOrUpdateExtraData($updateExtraDataKey);
                }
                $this->handleAfterWrite($data, $msgs);
                break;
            case 'reply':
                $replySno = $this->buildQuery->insert($arrData);
                if ($updateExtraDataKey) {
                    $updateExtraDataKey['bdSno'] = $replySno;
                    $this->buildQuery->insertOrUpdateExtraData($updateExtraDataKey);
                }
                if ($this->cfg['bdReplyStatusFl'] == 'y') {
                    $arrData2 = [];
                    $this->db->bind_param_push($arrData2, 'i', $this->req['sno']);
                    $this->db->set_update_db(DB_BD_ . $this->cfg['bdId'], " replyStatus = '" . $this->req['replyStatus'] . "' ", 'sno = ?', $arrData2, false);
                }
                $replyData = $this->buildQuery->selectOne($replySno);
                $this->handleAfterReply($parentData, $replyData, $msgs); //TODO:결과처리?

                break;
            case 'modify':
                $affectedRows = $this->buildQuery->update($arrData, $this->req['sno']);
                if ($affectedRows < 1) {
                    throw new \Exception('error update affected row zero');
                }
                if ($updateExtraDataKey) {
                    if ($this->checkSelectGoodsPage($modify) === false) {
                        $updateExtraDataKey['bdSno'] = $this->req['sno'];
                        $this->buildQuery->insertOrUpdateExtraData($updateExtraDataKey);
                    }
                }

                //게시글 이동
                if ($this->req['isMove'] == 'y' && $this->req['moveBdId'] && $this->req['moveBdId'] != $this->cfg['bdId']) {
                    $fields = [];
                    foreach (DBTableField::tableBd() as $key => $val) {
                        $fields[] = $val['val'];
                    }

                    $fields[] = 'regDt';
                    $fields = implode(',', $fields);
                    $query = "INSERT INTO " . DB_BD_ . $this->req['moveBdId'] . "(" . $fields . ") SELECT  " . $fields . " FROM " . DB_BD_ . $this->cfg['bdId'] . " WHERE sno = ?";
                    $this->db->bind_query($query, ['i', $this->req['sno']]);
                    $moveNewSno = $this->db->insert_id();
                    $boardConfig = new BoardConfig($this->req['moveBdId']);
                    $moveBoardCfg = $boardConfig->cfg;
                    $newGroupNo = BoardUtil::createGroupNo($this->req['moveBdId']);
                    $arrBind = [];// 스토리지 , groupCode DB업데이트
                    $this->db->bind_param_push($arrBind, 'i', $newGroupNo);
                    $this->db->bind_param_push($arrBind, 's', $moveBoardCfg['bdUploadStorage']);
                    $this->db->bind_param_push($arrBind, 's', $moveBoardCfg['bdUploadPath']);
                    $this->db->bind_param_push($arrBind, 's', $moveBoardCfg['bdUploadThumbPath']);
                    $this->db->bind_param_push($arrBind, 'i', $moveNewSno);
                    $this->db->set_update_db(DB_BD_ . $this->req['moveBdId'], "groupNo = ? , bdUploadStorage= ? , bdUploadPath = ? , bdUploadThumbPath = ? ", 'sno=?', $arrBind, false);

                    if ($modify['saveFileNm']) {
                        $saveFileNm = explode(STR_DIVISION, $modify['saveFileNm']);
                        for ($i = 0; $i < count($saveFileNm); $i++) {
                            Storage::copy(Storage::PATH_CODE_BOARD, $modify['bdUploadStorage'], $modify['bdUploadPath'] . $saveFileNm[$i], $moveBoardCfg['bdUploadStorage'], $moveBoardCfg['bdUploadPath'] . $saveFileNm[$i]);
                            Storage::copy(Storage::PATH_CODE_BOARD, $modify['bdUploadStorage'], $modify['bdUploadThumbPath'] . $saveFileNm[$i], $moveBoardCfg['bdUploadStorage'], $moveBoardCfg['bdUploadThumbPath'] . $saveFileNm[$i]);
                        }
                    }

                    $arrBind = [];//게시판 댓글 업데이트
                    $this->db->bind_param_push($arrBind, 's', $this->req['moveBdId']);
                    $this->db->bind_param_push($arrBind, 's', $moveNewSno);
                    $this->db->bind_param_push($arrBind, 's', $this->cfg['bdId']);
                    $this->db->bind_param_push($arrBind, 's', $this->req['sno']);
                    $this->db->set_update_db(DB_BOARD_MEMO, "bdId = ? , bdSno= ? ", 'bdId=? and  bdSno= ?  ', $arrBind, false);

                    $childData = $this->buildQuery->selectListByGroupNo($modify['groupNo']);
                    $childSno = null;
                    foreach($childData as $val){
                        $query = "INSERT INTO " . DB_BD_ . $this->req['moveBdId'] . "(" . $fields . ") SELECT  " . $fields . " FROM " . DB_BD_ . $this->cfg['bdId'] . " WHERE sno = ?";
                        $this->db->bind_query($query, ['i', $val['sno']]);
                        $moveNewSno = $this->db->insert_id();
                        $boardConfig = new BoardConfig($this->req['moveBdId']);
                        $moveBoardCfg = $boardConfig->cfg;
                        $arrBind = [];// 스토리지 , groupCode DB업데이트
                        $this->db->bind_param_push($arrBind, 'i', $newGroupNo);
                        $this->db->bind_param_push($arrBind, 's', $moveBoardCfg['bdUploadStorage']);
                        $this->db->bind_param_push($arrBind, 's', $moveBoardCfg['bdUploadPath']);
                        $this->db->bind_param_push($arrBind, 's', $moveBoardCfg['bdUploadThumbPath']);
                        $this->db->bind_param_push($arrBind, 'i', $moveNewSno);
                        $this->db->set_update_db(DB_BD_ . $this->req['moveBdId'], "groupNo = ? , bdUploadStorage= ? , bdUploadPath = ? , bdUploadThumbPath = ? ", 'sno=?', $arrBind, false);
                    }

                    $this->deleteData($this->req['sno'], false);
                }
                break;
        }

        return $msgs;
    }


    protected function setSaveData($key, &$val, &$data, &$validator)
    {
        $requiredExpect = ['subject', 'contents'];
        if ($this->cfg['bdEventFl'] == 'y') {
            array_push($requiredExpect, 'eventStart', 'eventEnd');
        }
        $required = false;
        if (isset($key) && isset($val)) {
            if (in_array($key, $requiredExpect)) {
                $required = true;
            }
            $data[$key] = $val;
            $validator->add($key, '', $required);
        }
    }

    public function getExtraData($sno)
    {
        if ($this->cfg['goodsType'] != 'n') {    //엑스트라 데이터
            $extraData = $this->buildQuery->selectExtraData($sno);
            $goods = new Goods();
            $addGoods = new AddGoodsAdmin();
            if ($extraData['goodsNoText']) {
                if (strpos($extraData['goodsNoText'], STR_DIVISION) !== false) {
                    $extraData['arrGoodsNo'] = explode(STR_DIVISION, $extraData['goodsNoText']);
                } else {
                    $extraData['arrGoodsNo'][] = $extraData['goodsNoText'];
                }
                foreach ($extraData['arrGoodsNo'] as $key => $goodsNo) {
                    $extraData['arrGoodsData'][$key] = $goods->getGoodsInfo($goodsNo);
                }
            }
            if ($extraData['orderGoodsNoText']) {
                if (strpos($extraData['orderGoodsNoText'], STR_DIVISION) !== false) {
                    $extraData['arrOrderGoodsNo'] = explode(STR_DIVISION, $extraData['orderGoodsNoText']);
                } else {
                    $extraData['arrOrderGoodsNo'][] = $extraData['orderGoodsNoText'];
                }
                $order = new Order();
                foreach ($extraData['arrOrderGoodsNo'] as $key => $orderGoodsNo) {
                    $isAddGoods = substr($orderGoodsNo, 0, 1) == 'A' ? true : false;
                    if ($isAddGoods) {
                        $orderGoodsNo = substr($orderGoodsNo, 1);
                    }
                    $orderGoodsData = $order->getOrderGoods(null, $orderGoodsNo, null, null, null)[0];
                    $extraData['arrOrderGoodsData'][$key] = $orderGoodsData;
                    $_arrOrderGoodsData = $extraData['arrOrderGoodsData'][$key];
                    $goodsNo = $_arrOrderGoodsData['goodsNo'];
                    if ($isAddGoods) {
                        $addGoodsData = $addGoods->getDataAddGoods($goodsNo)['data'];
                        $goodsImageSrc = SkinUtils::imageViewStorageConfig($addGoodsData['imageNm'], $addGoodsData['imagePath'], $addGoodsData['imageStorage'], 100, 'add_goods')[0];

                        //추가상품일경우 상품번호를 부모번호로 대체체
                        $extraData['arrOrderGoodsData'][$key]['goodsNo'] = $orderGoodsData['parentGoodsNo'];
                        $extraData['arrOrderGoodsData'][$key]['totalGoodsPrice'] = $_arrOrderGoodsData['goodsCnt'] * ($addGoodsData['goodsPrice'] + $_arrOrderGoodsData['optionPrice'] + $_arrOrderGoodsData['optionTextPrice']);
                    } else {
                        $goodsImage = $goods->getGoodsImage($goodsNo, 'main');
                        $goodsInfo = $goods->getGoodsInfo($goodsNo);
                        $goodsImageSrc = SkinUtils::imageViewStorageConfig($goodsImage[0]['imageName'], $goodsInfo['imagePath'], $goodsInfo['imageStorage'], 100, 'goods')[0];
                        $extraData['arrOrderGoodsData'][$key]['totalGoodsPrice'] = $_arrOrderGoodsData['goodsCnt'] * ($_arrOrderGoodsData['goodsPrice'] + $_arrOrderGoodsData['optionPrice'] + $_arrOrderGoodsData['optionTextPrice']);
                    }

                    $extraData['arrOrderGoodsData'][$key]['goodsImageSrc'] = $goodsImageSrc;
                    if ($_arrOrderGoodsData['optionInfo']) {
                        $optionInfo = json_decode(gd_htmlspecialchars_stripslashes($_arrOrderGoodsData['optionInfo'], true));
                        foreach ($optionInfo as $option) {
                            $tmpOption[] = $option[0] . ':' . $option[1];
                        }
                    }

                    $optionTextInfo = json_decode(gd_htmlspecialchars_stripslashes($_arrOrderGoodsData['optionTextInfo'], true));
                    foreach ($optionTextInfo as $option) {
                        $tmpOption[] = $option[0] . ':' . $option[1];
                    }

                    $extraData['arrOrderGoodsData'][$key]['optionName'] = implode('<br>', $tmpOption);
                    $extraData['arrOrderGoodsData'][$key]['orderStatusText'] = $order->getOrderStatusAdmin($_arrOrderGoodsData['orderStatus']);
                }
            }
        }

        return $extraData;
    }

    protected function multiAjaxUpload($uploadFileNm, $saveFileNm)
    {
        $ajaxUploadFiles = $this->req['uploadFileNm'];
        if (count($ajaxUploadFiles) > 5) {
            throw new \Exception(sprintf(__('업로드는 최대 %1$s 개만 지원합니다'), 5));
        }
        if ($this->req['mode'] == 'modify') {
            $_uploadFileNm = explode(STR_DIVISION, $uploadFileNm);
            $_saveFileNm = explode(STR_DIVISION, $saveFileNm);
        }

        if ($this->req['delFile']) {
            foreach ($this->req['delFile'] as $key => $val) {
                $this->storage->delete($this->cfg['bdUploadPath'] . $_saveFileNm[$key]);

                unset($_saveFileNm[$key]);
                unset($_uploadFileNm[$key]);
            }
        }
        foreach ($ajaxUploadFiles as $key => $val) {
            $_uploadFileNm[$key] = $val;
            $_saveFileNm[$key] = str_replace('tmp_', '', $this->req['saveFileNm'][$key]);

            $realSaveFileNm = $this->storage->getRealPath($this->cfg['bdUploadPath'] . $_saveFileNm[$key]);
            $this->storage->rename($this->storage->getRealPath($this->cfg['bdUploadPath'] . $this->req['saveFileNm'][$key]), $realSaveFileNm);
            //썸네일 이미지 생성
            if ($this->isAllowImageExtention($this->req['uploadFileNm'][$key])) {
                $thumnailPath = $this->storage->getRealPath($this->cfg['bdUploadThumbPath'] . $_saveFileNm[$key]);
                if (is_dir(dirname($thumnailPath)) === false) {
                    @mkdir(dirname($thumnailPath), 0707, true);
                }
                if (copy($realSaveFileNm, $thumnailPath)) {
                    $path = $thumnailPath;
                    $width = $this->cfg['bdListImgWidth'];
                    $height = $this->cfg['bdListImgHeight'];
                    $quality = 100;

                    list($imageWidth, $imageHeight, $imageType) = @getimagesize($path);

                    switch ($imageType) {
                        case 1:
                            $image = imagecreatefromgif($path);
                            break;
                        case 2:
                            $image = imagecreatefromjpeg($path);
                            break;
                        case 3:
                            $image = imagecreatefrompng($path);
                            break;
                        default:
                            return;
                    }

                    if ($width) {
                        if ($imageWidth > $width) {
                            $ratio = $imageWidth / $width;
                        } else {
                            $ratio = 1;
                        }

                        if ($width > 0 && $height > 0) {
                            $saveImageSize['width'] = $width;
                            if (!$height) {
                                $height = round($imageHeight / $ratio);
                            }
                            $saveImageSize['height'] = $height;
                        } else {
                            $saveImageSize['width'] = round($imageWidth / $ratio);
                            $saveImageSize['height'] = round($imageHeight / $ratio);
                        }
                        $dest = imagecreatetruecolor($saveImageSize['width'], $saveImageSize['height']);

                        //png인 경우 백그라운드를 하얀색으로
                        if ($imageType == 3) {
                            imagealphablending($dest, false);
                            imagesavealpha($dest, true);
                            $transparentindex = imagecolorallocatealpha($dest, 255, 255, 255, 127);
                            imagefill($dest, 0, 0, $transparentindex);
                        }
                        imagecopyresampled($dest, $image, 0, 0, 0, 0, $saveImageSize['width'], $saveImageSize['height'], $imageWidth, $imageHeight);

                        switch ($imageType) {
                            case 1:
                                imagegif($dest, $path);
                                break;
                            case 2:
                                imagejpeg($dest, $path, $quality);
                                break;
                            case 3:
                                imagepng($dest, $path);
                                break;
                            default:
//                                throw new \Exception('썸네일저장 가능한 타입이 아닙니다.');
                        }
//                        debug($saveImageSize['width'],true);

                    }
                }
            }
        }

        $file['uploadFileNm'] = implode(STR_DIVISION, $_uploadFileNm);
        $file['saveFileNm'] = implode(STR_DIVISION, $_saveFileNm);
        $file['bdUploadPath'] = $this->cfg['bdUploadPath'];
        $file['bdUploadThumbPath'] = $this->cfg['bdUploadThumbPath'];
        $file['bdUploadStorage'] = $this->cfg['bdUploadStorage'];
        return $file;
    }

    /**
     * Anti-Spam 검증
     * @param bool $isMemo
     * @return mixed true : 성공, Exception : 실패
     * @throws \Exception
     */
    protected function checkAntiSpam($isMemo = false)
    {
        if ($this->isAdmin()) {
            if (preg_match('/' . Request::getHost() . '/i', Request::server()->get('HTTP_REFERER')) != 1) {
                throw new\Exception(__('무단링크를 금지합니다.'));
            }
            return true;
        }

        $bdSpamBoardFl = $isMemo ? $this->cfg['bdSpamMemoFl'] : $this->cfg['bdSpamBoardFl'];
        // Anti-Spam 검증
        if (!$bdSpamBoardFl) {
            return true;
        }

        if ($this->req['mode'] != 'delete') {
            $switch = ($bdSpamBoardFl & 1 ? '12' : '00') . (($bdSpamBoardFl & 2) ? '3' : '0');
            $rst = BoardUtil::antiSpam(strtoupper(gd_isset($this->req['captchaKey'])), $switch, 'post');
            if (substr($rst['code'], 0, 1) == '4') {
                throw new  \Exception(__('자동등록방지 문자가 틀렸습니다.'));
            }
            if ($rst['code'] != '0000') {
                throw new\Exception(__('무단링크를 금지합니다.'));
            }
        } else {
            $rst = BoardUtil::antiSpam(null, '12', 'post');
            if ($rst['code'] != '0000') {
                throw new \Exception(__('무단링크를 금지합니다.'));
            }
        }
        return true;
    }

    /**
     * 업로드 허용가능한 확장자 체크
     *
     * @param $filename
     * @return bool
     */
    public function isAllowUploadExtention($filename)
    {
        $allowUploadExtension = [
            'png', 'jpg', 'jpeg', 'ico', 'gif', 'tif', 'bmp', 'eps', 'tiff',
            'hwp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'ppt', 'pps',
            'psd', 'ai', 'cdr', 'zip', 'rar', 'sit', 'sitx', 'pptx'
        ];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (in_array($ext, $allowUploadExtension) === false) {
            return false;
        }

        return true;
    }

    /**
     * 이미지 확장자 체크
     *
     * @param $filename
     * @return bool
     */
    protected function isAllowImageExtention($filename)
    {
        $allowUploadExtension = [
            'gif', 'jpg', 'bmp', 'png', 'jpeg', 'jpe'
        ];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (in_array($ext, $allowUploadExtension) === false) {
            return false;
        }

        return true;
    }


    /**
     * 첨부파일올리기
     *
     * @param array $fileName 글수정시 기존파일이름(uploadFileNm, saveFileNm)
     * @return array 파일 업로드후 파일이름(uploadFileNm, saveFileNm)
     * @throws \Exception
     */
    protected function multiUpload($fileName)
    {
        if ($this->req['mode'] == 'modify') {
            $uploadFileNm = explode(STR_DIVISION, $fileName['uploadFileNm']);
            $saveFileNm = explode(STR_DIVISION, $fileName['saveFileNm']);
        }

        $file_array = ArrayUtils::rearrangeFileArray(Request::files()->get('upfiles'));

        if ($this->req['delFile']) {
            foreach ($this->req['delFile'] as $key => $val) {
                $this->storage->delete($this->cfg['bdUploadPath'] . $saveFileNm[$key]);
                unset($saveFileNm[$key]);
                unset($uploadFileNm[$key]);
            }
        }

        if (empty($file_array) === false) {
            $fileCnt = count($file_array);
            if ($fileCnt > 5) {
                throw new \Exception(sprintf(__('업로드는 최대 %1$s 개만 지원합니다'), 5));
            }
            for ($i = 0; $i < $fileCnt; $i++) {
                if (!$file_array[$i]['name']) {
                    continue;
                }
                if ($errorCode = $file_array[$i]['error'] != UPLOAD_ERR_OK) {
                    switch ($errorCode) {
                        case UPLOAD_ERR_INI_SIZE :
                            throw new \Exception(sprintf(__('업로드 용량이 %1$s MByte(s) 를 초과했습니다.'), $this->cfg['bdUploadMaxSize']));
                            break;
                        default :
                            throw new \Exception(__('알수 없는 오류입니다.') . '( UPLOAD ERROR CODE : ' . $errorCode . ')');
                    }
                }

                if ($this->isAllowUploadExtention($file_array[$i]['name']) === false) {
                    $_errorFileName = str_replace(' ', '', $file_array[$i]['name']);
                    throw new \Exception(__('허용하지 않는 확장자입니다.') . (' . $_errorFileName . '));
                }

                if (is_uploaded_file($file_array[$i]['tmp_name'])) {
                    if ($this->cfg['bdUploadMaxSize'] && $file_array[$i]['size'] > ($this->cfg['bdUploadMaxSize'] * 1024 * 1024)) {
                        throw new \Exception(sprintf(__('업로드 용량이 %1$s MByte(s) 를 초과했습니다.'), $this->cfg['bdUploadMaxSize']));
                    }
                    if (gd_isset($saveFileNm[$i])) {
                        $this->storage->delete($this->cfg['bdUploadPath'] . $saveFileNm[$i]);
                        $this->storage->delete($this->cfg['bdUploadThumbPath'] . $saveFileNm[$i]);
                    }

                    $uploadFileNm[$i] = $file_array[$i]['name'];
                    $saveFileNm[$i] = substr(md5(microtime()), 0, 16);

                    $listImageWidth = $this->cfg['bdListImgWidth'] ?? 500;
                    //이미지 아니면 섬네일 생성안됨;
                    $this->storage->upload($file_array[$i]['tmp_name'], $this->cfg['bdUploadPath'] . $saveFileNm[$i]);
                    $this->storage->upload($file_array[$i]['tmp_name'], $this->cfg['bdUploadThumbPath'] . $saveFileNm[$i], ['width' => $listImageWidth]);
                } else {
                    throw new \Exception(sprintf(__('업로드 용량이 %1$s MByte(s) 를 초과했습니다.'), ini_get('upload_max_filesize')));
                }
            }
        }

        BoardUtil::setFilename($uploadFileNm, $saveFileNm);

        return ['uploadFileNm' => $uploadFileNm, 'saveFileNm' => $saveFileNm, 'bdUploadStorage' => $this->cfg['bdUploadStorage'], 'bdUploadPath' => $this->cfg['bdUploadPath'], 'bdUploadThumbPath' => $this->cfg['bdUploadThumbPath']];
    }

    public function getChildList($sno)
    {
        $arrBind = [];
        $query = "SELECT * FROM " . DB_BD_ . $this->cfg['bdId'] . " WHERE parentSno = ?";
        $this->db->bind_param_push($arrBind, 'i', $sno);
        $result = $this->db->query_fetch($query, $arrBind);
        return $result;
    }

    public function getChildListByGroupNo($groupNo)
    {
        $result = $this->buildQuery->selectListByGroupNo($groupNo);
        return $result;
    }

    /**
     * getFilelist
     *
     * @param $files
     * @param $bdUploadStorage
     * @param $bdUploadPath
     * @return array|null|void
     */
    public function getFilelist($files, $bdUploadStorage, $bdUploadPath)
    {
        if (!$bdUploadStorage) {
            return;
        }
        $uploadFileNm = explode(STR_DIVISION, $files['uploadFileNm']);
        $saveFileNm = explode(STR_DIVISION, $files['saveFileNm']);
        $uploadedFile = null;
        if (gd_array_is_empty($saveFileNm) === false) {
            $saveFileNmCnt = count($saveFileNm);
            for ($i = 0; $i < $saveFileNmCnt; $i++) {
                $file_size = $this->storage->getSize($bdUploadPath . $saveFileNm[$i]);
                if ($saveFileNm[$i] != '' && $file_size != -1) {
                    if ($file_size >= 1048576) {
                        $file_size = ((int)($file_size / 10485.76) / 100);
                        $size_unit = 'MB';
                    } else if ($file_size >= 1024) {
                        $file_size = ((int)($file_size / 102.4) / 10);
                        $size_unit = 'KB';
                    } else {
                        $file_size = $file_size;
                        $size_unit = 'Bytes';
                    }
                    $uploadedFile[] = array('fid' => $i, 'name' => $uploadFileNm[$i], 'size' => $file_size, 'unit' => $size_unit);
                }
            }
        }
        return $uploadedFile;
    }

    public function getTableColumns($table)
    {
        $result = $this->db->query('SHOW FULL  COLUMNS FROM ' . $table);
        while ($row = mysqli_fetch_array($result)) {
            $_name = $row[0];
            foreach ($row as $key => $val) {
                if (is_numeric($key) === false) {
                    $fields[$_name][$key] = $val;
                }
            }
        }

        return $fields;
    }

    public function existsTableColumns($table, $key)
    {
        $result = $this->db->query('SHOW FULL  COLUMNS FROM ' . $table);
        while ($row = mysqli_fetch_array($result)) {
            $_name = $row[0];
            if ($_name == $key) {
                return true;
            }
        }

        return false;
    }

    public function uploadAjax($fileData)
    {
        if ($errorCode = $fileData['error'] != UPLOAD_ERR_OK) {
            switch ($errorCode) {
                case UPLOAD_ERR_INI_SIZE :
                    throw new \Exception(sprintf(__('업로드 용량이 %1$s MByte(s) 를 초과했습니다.'), $this->cfg['bdUploadMaxSize']));
                    break;
                default :
                    throw new \Exception(__('알수 없는 오류입니다.') . '( UPLOAD ERROR CODE : ' . $errorCode . ')');
            }
        }

        if ($this->isAllowUploadExtention($fileData['name']) === false) {
            $_errorFileName = str_replace(' ', '', $fileData['name']);
            throw new \Exception(__('허용하지 않는 확장자입니다.') . ' (' . $_errorFileName . ')');
        }

        if (is_uploaded_file($fileData['tmp_name'])) {
            if ($this->cfg['bdUploadMaxSize'] && $fileData['size'] > ($this->cfg['bdUploadMaxSize'] * 1024 * 1024)) {
                throw new \Exception(sprintf(__('업로드 용량이 %1$s MByte(s) 를 초과했습니다.'), $this->cfg['bdUploadMaxSize']));
            }
        }
        $uploadFileNm = $fileData['name'];
        $saveFileNm = 'tmp_' . substr(md5(microtime()), 0, 16);

        $result = $this->storage->upload($fileData['tmp_name'], $this->cfg['bdUploadPath'] . $saveFileNm);

        return ['result' => $result, 'uploadFileNm' => $uploadFileNm, 'saveFileNm' => $saveFileNm];
    }

    public function deleteUploadGarbageImage($deleteImage)
    {
        foreach (explode(STR_DIVISION, $deleteImage) as $val) {
            if (substr($val, 0, 4) != 'tmp_') {
                continue;
            }
//            $this->storage->delete($this->cfg['bdUploadPath'] . $val);
        }
    }
}
