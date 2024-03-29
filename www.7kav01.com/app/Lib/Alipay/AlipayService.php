<?php
namespace App\Lib\Alipay;

class AlipayService
{
    protected $appId;
    protected $returnUrl;
    protected $notifyUrl;
    protected $charset;
    //私钥值
    protected $rsaPrivateKey;
    function __construct($appid, $returnUrl, $notifyUrl,$saPrivateKey)
    {
        $this->appId = $appid;
        $this->returnUrl = $returnUrl;
        $this->notifyUrl = $notifyUrl;
        $this->charset = 'UTF-8';
        $this->rsaPrivateKey=$saPrivateKey;
    }
    /**
     * 发起订单
     * @param float $totalFee 收款总费用 单位元
     * @param string $outTradeNo 唯一的订单号
     * @param string $orderName 订单名称
     * @param string $notifyUrl 支付结果通知url 不要有问号
     * @param string $timestamp 订单发起时间
     * @return array
     */
    function doPay($totalFee, $outTradeNo, $orderName, $returnUrl,$notifyUrl)
    {
        $product_code = isMobile() ? '' : 'FAST_INSTANT_TRADE_PAY';
		$method = isMobile() ? 'alipay.trade.wap.pay' : 'alipay.trade.page.pay';
		//请求参数
        $requestConfigs = array(
            'out_trade_no'=>$outTradeNo,
            'product_code'=>'QUICK_WAP_WAY',
            'total_amount'=>$totalFee, //单位 元
            'subject'=>$orderName,  //订单标题
        );
        $commonConfigs = array(
            //公共参数
            'app_id' => $this->appId,
            'method' => $method,             //接口名称
            'format' => 'JSON',
            'return_url' => $returnUrl,
            'charset'=>$this->charset,
            'sign_type'=>'RSA2',
			'timestamp'=>date('Y-m-d H:i:s'),
            'version'=>'1.0',
            'notify_url' => $notifyUrl,
            'biz_content'=>json_encode($requestConfigs),
        );
		
        $commonConfigs["sign"] = $this->generateSign($commonConfigs, $commonConfigs['sign_type']);
        return $commonConfigs;
		//return $this->buildRequestForm($commonConfigs);
    }
	
	/*
	 * 账户转账
	*/
	
	public function transfor($payee_account,$amount,$payee_real_name){
		$biz_content = [
			'out_biz_no'=>date('YmdHis'),
			'payee_type'=>'ALIPAY_LOGONID',
			'payee_account'=>$payee_account,
			'amount'=>(string)$amount,
			'payee_real_name'=>$payee_real_name,
			'remark'=>config('tirParty.ZFB')['remark']
		];
		
		$commonConfigs = [
			'app_id'=>$this->appId,
			'method'=>'alipay.fund.trans.toaccount.transfer',
			'format'=>'JSON',
			'charset'=>'UTF-8',
			'timestamp'=>date('Y-m-d H:i:s'),
			'sign_type'=>'RSA2',
			'version'=>'1.0',
			'biz_content'=>json_encode($biz_content)
		];
		$commonConfigs['sign'] = $this->generateSign($commonConfigs, $commonConfigs['sign_type']);
		$url = 'https://openapi.alipay.com/gateway.do?'.http_build_query($commonConfigs);
		$ch = curl_init ();
        curl_setopt ( $ch, CURLOPT_URL, $url );
        curl_setopt ( $ch, CURLOPT_SSL_VERIFYPEER, FALSE );
        curl_setopt ( $ch, CURLOPT_SSL_VERIFYHOST, FALSE );
        curl_setopt ( $ch, CURLOPT_RETURNTRANSFER, 1 );
        curl_setopt ( $ch, CURLOPT_TIMEOUT, 10 );
        $result = curl_exec ( $ch );
        curl_close ( $ch );
        $result = json_decode ($result, true)['alipay_fund_trans_toaccount_transfer_response'];
		if($result['code']!=10000)return ['status'=>2,'msg'=>$result['sub_msg']];
		return ['status'=>1,'msg'=>'转账成功'];
	}
	
	
    /**
     * 建立请求，以表单HTML形式构造（默认）
     * @param $para_temp 请求参数数组
     * @return 提交表单HTML文本
     */
    protected function buildRequestForm($para_temp) {
        $sHtml = "<form id='alipaysubmit' name='alipaysubmit' action='https://openapi.alipay.com/gateway.do?charset=".$this->charset."' method='POST'>";
        while (list ($key, $val) = each ($para_temp)) {
            if (false === $this->checkEmpty($val)) {
                $val = str_replace("'","&apos;",$val);
                $sHtml.= "<input type='hidden' name='".$key."' value='".$val."'/>";
            }
        }
        //submit按钮控件请不要含有name属性
        $sHtml = $sHtml."<input type='submit' value='ok' style='display:none;''></form>";
        $sHtml = $sHtml."<script>document.forms['alipaysubmit'].submit();</script>";
        return $sHtml;
    }
    public function generateSign($params, $signType = "RSA") {
        return $this->sign($this->getSignContent($params), $signType);
    }
	
    protected function sign($data, $signType = "RSA") {
        $priKey=$this->rsaPrivateKey;
        $res = "-----BEGIN RSA PRIVATE KEY-----\n" .
            wordwrap($priKey, 64, "\n", true) .
            "\n-----END RSA PRIVATE KEY-----";
        ($res) or die('您使用的私钥格式错误，请检查RSA私钥配置');
        if ("RSA2" == $signType) {
            openssl_sign($data, $sign, $res, version_compare(PHP_VERSION,'5.4.0', '<') ? SHA256 : OPENSSL_ALGO_SHA256); //OPENSSL_ALGO_SHA256是php5.4.8以上版本才支持
		} else {
            openssl_sign($data, $sign, $res);
        }
        $sign = base64_encode($sign);
        return $sign;
    }
    /**
     * 校验$value是否非空
     *  if not set ,return true;
     *    if is null , return true;
     **/
    protected function checkEmpty($value) {
        if (!isset($value))
            return true;
        if ($value === null)
            return true;
        if (trim($value) === "")
            return true;
        return false;
    }
    public function getSignContent($params) {
        ksort($params);
        $stringToBeSigned = "";
        $i = 0;
        foreach ($params as $k => $v) {
            if (false === $this->checkEmpty($v) && "@" != substr($v, 0, 1)) {
                // 转换成目标字符集
                $v = $this->characet($v, $this->charset);
                if ($i == 0) {
                    $stringToBeSigned .= "$k" . "=" . "$v";
                } else {
                    $stringToBeSigned .= '&'. "$k" . "=" . "$v";
                }
                $i++;
            }
        }
        unset ($k, $v);
        return $stringToBeSigned;
    }
    /**
     * 转换字符集编码
     * @param $data
     * @param $targetCharset
     * @return string
     */
    function characet($data, $targetCharset) {
        if (!empty($data)) {
            $fileType = $this->charset;
            if (strcasecmp($fileType, $targetCharset) != 0) {
                $data = mb_convert_encoding($data, $targetCharset, $fileType);
                //$data = iconv($fileType, $targetCharset.'//IGNORE', $data);
            }
        }
        return $data;
    }
}