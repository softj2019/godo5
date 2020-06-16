<?php
namespace Component\Database;
class DBTableField extends \Bundle\Component\Database\DBTableField
{
    public static function tableGoods($conf = null)
    {
        // 부모 method 상속
        $arrField = parent::tableGoods($conf);
        
        // 추가 필드
        $arrField[] = ['val' => 'goodsWidth', 'typ' => 'i', 'def' => null]; // 가로
        $arrField[] = ['val' => 'goodsDepth', 'typ' => 'i', 'def' => null]; // 세로
        $arrField[] = ['val' => 'goodsHeight', 'typ' => 'i', 'def' => null]; // 높이
        $arrField[] = ['val' => 'boxGol', 'typ' => 's', 'def' => null]; // 골
        $arrField[] = ['val' => 'boxType', 'typ' => 's', 'def' => null]; // 형태
        $arrField[] = ['val' => 'goodsUse', 'typ' => 's', 'def' => null]; // 용도
        $arrField[] = ['val' => 'qual', 'typ' => 's', 'def' => null]; // 재질
        $arrField[] = ['val' => 'color', 'typ' => 's', 'def' => null]; // 컬러
        $arrField[] = ['val' => 'feature', 'typ' => 's', 'def' => null]; // 특징
        $arrField[] = ['val' => 'packUnit', 'typ' => 's', 'def' => '1', 'name' => '묶음수량']; // 묶음 수량
        $arrField[] = ['val' => 'detailInfoPaymentFl', 'typ' => 's', 'def' => null, 'name' => '상품 상세 이용안내 - 결제/입금안내 입력여부']; // 상품 상세 이용안내 - 결제/입금안내 입력여부
        $arrField[] = ['val' => 'detailInfoPayment', 'typ' => 's', 'def' => null, 'name' => '상품 상세 이용안내 - 결제/입금안내 선택입력']; // 상품 상세 이용안내 - 결제/입금안내 선택입력
        $arrField[] = ['val' => 'detailInfoPaymentDirectInput', 'typ' => 's', 'def' => null, 'name' => '상품 상세 결제/입금안내 - 환불안내 직접입력']; // 상품 상세 이용안내 - 결제/입금안내 직접입력
        $arrField[] = ['val' => 'detailInfoServiceFl', 'typ' => 's', 'def' => null, 'name' => '상품 상세 이용안내 - 고객센터 입력여부']; // 상품 상세 이용안내 - 고객센터 입력여부
        $arrField[] = ['val' => 'detailInfoService', 'typ' => 's', 'def' => null, 'name' => '상품 상세 이용안내 - 고객센터 선택입력']; // 상품 상세 이용안내 - 고객센터 선택입력
        $arrField[] = ['val' => 'detailInfoServiceDirectInput', 'typ' => 's', 'def' => null, 'name' => '상품 상세 이용안내 - 고객센터 직접입력']; // 상품 상세 이용안내 - 고객센터 직접입력
        
        // 필드값 리턴
        return $arrField;
    }
    
    public static function tableBd()
    {
        // 부모 method 상속
        $arrField = parent::tableBd();
        
        // 추가 필드
        $arrField[] = ['val' => 'writerUse', 'typ' => 's', 'def' => null]; // 무게 및 용도
        $arrField[] = ['val' => 'writerTel', 'typ' => 's', 'def' => null]; // 전화번호
        $arrField[] = ['val' => 'writerFax', 'typ' => 's', 'def' => null]; // 팩스번호
        $arrField[] = ['val' => 'writerAddr', 'typ' => 's', 'def' => null]; // 받으실주소
        
        // 필드값 리턴
        return $arrField;
    }
    
}