<?php
/**
 * 登录/注册api
 * utime : 2016-03-06
 */

class LoginApi extends Api{

    private $passport;
    private $register;
    private $_config;                   // 注册配置信息字段
    private $_user_model;               // 用户模型字段
    private $_register_model;			// 注册模型字段
    private $APPID;
    public function __construct(){
        parent::__construct();
        $this->passport = model('Passport');
        $this->register = model('Register');
        $this->_config = model('Xdata')->get('admin_Config:register');
        $this->_user_model = model('User');
        $this->_register_model = model('Register');
        $this->APPID = '10078000';
    }

    /**
     * Eduline用户登录接口
     * 参数：
     * uname    用户名/邮箱/手机号
     * upwd     密码
     * return   用户数据或者登录错误提示
     */
    public function login(){

       $uname = isset($this->data['uname']) ? t(urldecode($this->data['uname'])) : '';
       $upwd  = isset($this->data['upwd']) ? t(urldecode(trim($this->data['upwd']))) : '';
       $result = $this->passport->loginLocal($uname,$upwd,false,true);
       if ($result){
           //查询有无token
           $token=M('login')->where(array('uid'=>$result['uid']))->find();
           if(!$token){
               $data['oauth_token']         = getOAuthToken($result['uid']);//添加app认证
               $data['oauth_token_secret']  = getOAuthTokenSecret();
               $data['uid']                 = $result['uid'];

               $result['oauth_token']        = $data['oauth_token'];
               $result['oauth_token_secret'] = $data['oauth_token_secret'];
               M('login')->add($data);
           }
           $this->exitJson($result);
       }else{
           $this->exitJson( array() ,10001,$this->passport->getError());
       }
   }
    /**
     * Eduline用户第三方登录接口
     * 参数：
     * app_token    登录成功后第三方返回的token
     * app_login_type 第三方登录类型
     */
    public function login_sync (){
        $data['type']     = t($this->data['app_login_type']);
        $data['type_uid'] = t($this->data['app_token']);
        $res=M('login')->where($data)->find();
        if($res){
            $token = M('login')->where(array('uid'=>$res['uid']))->find();
            $this->exitJson($token);
        }else{
            $this->exitJson( array() ,10002,'用户尚未绑定');
        }
    }

    /**
     * Eduline注册接口
     */
    public function app_regist(){
        $email = t($this->data['login']);//获取email
        $uname = t($this->data['uname']);//获取用户名
        $password = t($this->data['password']);//获取密码
        $type = intval($this->data['type']);//获取注册类型
        $code = intval($this->data['code']);//手机验证码
        $sex = 1;
        if(!$this->_register_model->isValidName($uname)) {//验证用户名
            $this->exitJson( array() ,10010,$this->_register_model->getLastError());
        }
        if($password=="" ||strlen($password)<6 || strlen($password)>20){//验证密码
            $this->exitJson( array() ,10010,"对不起，密码长度不正确");
        }
        //邮箱注册
        if($type==1){
            if(!$this->_register_model->isValidEmail($email)) {//验证邮箱
                $this->exitJson( array() ,10010,$this->_register_model->getLastError());
            }
            $checkWhere = "login='{$email}' OR email='{$email}'";
            $r=M('User')->where($checkWhere)->find();
            if($r){
                $this->exitJson( array() ,10010,'对不起，此邮箱已被注册');
            }
            $typeField = 'email';
        }else if($type==2){//手机注册
            if(!preg_match("/^[1][34578]\d{9}$/",$email)) {
                $this->exitJson( array() ,10010,"手机号格式错误！");
            }
            $checkWhere = "login='{$email}' OR phone='{$email}'";
            $res=M('user')->where($checkWhere)->find();
            if($res){
                $this->exitJson( array() ,10010,"手机已被注册");
            }
            $phone=M('ResphoneCode')->where(array('phone'=>$email))->find();
            if($phone['code']!=$code){
                $this->exitJson( array() ,10010,"验证码不正确");
            }
            $typeField = 'phone';
        }else{
            $this->exitJson( array() ,10010,"注册类型错误");
        }

        $map['login']=$email;
        $map[$typeField] = $email;
        $login_salt = rand(11111, 99999);
        $map['uname'] = $uname;
        $map['sex'] = $sex;
        $map['login_salt'] = $login_salt;
        $map['password'] = md5(md5($password).$login_salt);
        $map['reg_ip'] = get_client_ip();
        $map['ctime'] = time();
        $map['is_audit'] = $this->_config['register_audit'] ? 0 : 1;
        // 需求添加 - 若后台没有填写邮件配置，将直接过滤掉激活操作
        $isActive = $this->_config['need_active'] ? 0 : 1;
        if ($isActive == 0) {
            $emailConf = model('Xdata')->get('admin_Config:email');
            if (empty($emailConf['email_host']) || empty($emailConf['email_account']) || empty($emailConf['email_password'])) {
                $isActive = 1;
            }
         }
        $map['is_active'] = $isActive;
        $map['is_init']   = 1;
        $map['first_letter'] = getFirstLetter($uname);
        //如果包含中文将中文翻译成拼音
        if ( preg_match('/[\x7f-\xff]+/', $map['uname'] ) ){
            //昵称和呢称拼音保存到搜索字段
            $map['search_key'] = $map['uname'].' '.model('PinYin')->Pinyin( $map['uname'] );
        } else {
            $map['search_key'] = $map['uname'];
        }
        $uid = $this->_user_model->add($map);
        if($uid){
            if($type==2){
                M('ResphoneCode')->where(array('phone'=>$email))->delete();
            }
            // 添加积分
            model('Credit')->setUserCredit($uid,'init_default');
            // 添加至默认的用户组
            $userGroup = model('Xdata')->get('admin_Config:register');
            $userGroup = empty($userGroup['default_user_group']) ? C('DEFAULT_GROUP_ID') : $userGroup['default_user_group'];
            model('UserGroupLink')->domoveUsergroup($uid, implode(',', $userGroup));
            $data['oauth_token']         = getOAuthToken($uid);//添加app认证
            $data['oauth_token_secret']  = getOAuthTokenSecret();
            $data['type']                = t($this->data['type_oauth']);
            $data['type_uid']            = t($this->data['type_uid']);
            $data['uid']                 = $uid;
            M('login')->add($data);
            $this->exitJson($data , 1);
        }else{
            $this->exitJson( array() ,10011,"对不起，注册失败，请重试！");
        }
}
    //验证手机验证码
    public function clickPhoneCode(){
        $email = t($this->data['login']);//获取email
        $code=intval($this->data['code']);//手机验证码
        if(!preg_match("/^[1][358]\d{9}$/",$email)) {
            $this->exitJson( array() ,10010,"手机号格式错误！");
        }
        $res=M('user')->where(array('login'=>$email))->find();
        if($res){
            $this->exitJson( array() ,10010,"手机已被注册");
        }
        $phone=M('ResphoneCode')->where(array('phone'=>$email))->find();
        if($phone['code']!=$code){
            $this->exitJson( array() ,10010,"验证码不正确");
        }
        $this->exitJson(true);
    }
    //获取手机验证码
    public function getRegphoneCode(){
        $phone=t($this->data['phone']);//获取手机号
        if(!preg_match("/^[1][358]\d{9}$/",$phone)) {
            $this->exitJson( array() ,10011,"手机号格式错误！");
        }
        $res=M('user')->where(array('login'=>$phone))->find();
        if($res){
            $this->exitJson( array() ,10011,"手机已被注册");
        }
        $stime=M('ResphoneCode')->where(array('phone'=>$phone))->getField('stime');
        if($stime!=""){
            $stime=time()-$stime;
            if($stime<60){
                $this->exitJson( array() ,10012,"请勿频繁请求，请稍候后再试！");
            }
        }
        //发送手机验证码
        $rnum = rand(100000,999999);
        $sendres = model('Sms')->send($phone,$rnum);
        //先删除原来数据库保存的字段
        $dres=M('ResphoneCode')->where(array('phone'=>$phone))->delete();
        $map['phone']=$phone;
        $map['code']=$rnum;
        $map['stime']=time();
		
        if($sendres) {
        	M('ResphoneCode')->add($map);
        	$msg['msg']="短信发送成功，请注意查收！";
        	$this->exitJson($msg);
        }else{
        	$this->exitJson( array() ,10012,"对不起，短信发送失败！");
        }
    }

    //通过手机找回密码
	public function phoneGetPwd(){
		$phone = t($this->data['phone']);
		if(!preg_match('/^1[3458]\d{9}$/', $phone)){
			$this->exitJson( array() ,10019,'手机号格式不正确');
		}
	    //根据用户电话查询用户信息
		$user = model('User')->where(array('phone'=>$phone))->find();
		if( empty($user) ) $this->exitJson( array() ,10020,'手机未绑定');
		$stime = M('ResphoneCode')->where(array('phone'=>$phone))->getField('stime');
		if($stime!=""){
			$stime=time()-$stime;
			if($stime<60){
				$this->exitJson( array() ,10012,"请勿频繁请求，请稍候后再试！");
			}
		}
		//如果存在那么先删除之前的验证码
		$code = rand(100000,999999);//手机验证码
		M('ResphoneCode')->where(array('phone'=>$phone))->delete();
		if(model('Sms')->send($phone,$code)){
			$map['phone'] = $phone;
			$map['code']  = $code;
			$map['stime'] = time();
			M('ResphoneCode')->add($map);
			$this->exitJson(true);
		}else{
			$this->exitJson( array() ,10020,"对不起，验证码发送失败！");
		}
   } 
   //验证验证码是否正确
   public function clickRepwdCode(){
   	  	$phone = t($this->data['phone']);//获取email
        $code=intval($this->data['code']);//手机验证码
        if(!preg_match("/^[1][358]\d{9}$/",$phone)) {
            $this->exitJson( array() ,10021,"手机号格式错误！");
        }
        $phone=M('ResphoneCode')->where(array('phone'=>$phone))->find();
        if($phone['code']!=$code){
            $this->exitJson( array() ,10022,"验证码不正确");
        }
        $this->exitJson(true);
   }
   
   
    //重置密码
	public function savePwd(){
	   	$phone = t($this->data['phone']);
	   	$pwd   = trim($this->data['pwd']);
		$repwd = trim($this->data['repwd']);
		$code  = intval($this->data['code']);
	
		if(!preg_match('/^1[3458]\d{9}$/', $phone)){
			 $this->exitJson( array() ,10023,"对不起，手机号不正确");
		}
		if(!model('Register')->isValidPassword($pwd, $repwd)){
			$this->exitJson( array() ,10024,model('Register')->getLastError());
		}
	    $phone=M('ResphoneCode')->where(array('phone'=>$phone))->find();
	    if($phone['code']!=$code){
	        $this->exitJson( array() ,10022,"验证码不正确");
	    }
	    $salt     = rand(10000,99999);
		$password = md5(md5($pwd).$salt);	
		$res= model('User')->where(array('phone'=>$this->data['phone']))->save(array(
			'password'=>$password,
			'login_salt'=>$salt,
		));
	    //清楚用户的缓存
	    $user= M("user")->where("phone=".$this->data['phone'])->find();
	    $res && model('User')->cleancache($user['uid']);
		if($res !== false){
			M('ResphoneCode')->where('phone='.$this->data['phone'])->delete();
			$this->exitJson(true);
		}
		$this->exitJson( array() ,10024,"修改失败，请重试");
   }

    /**
     * 通过Email找回密码
     */
    public function doFindPasswordByEmail() {
        $email = t($this->data["email"]);
        if(!$this->_isEmailString($email)) {
            $this->error('邮箱格式不正确');
        }
        $user =	M("User")->where('`login`="'.$email.'" OR `email`="'.$email.'"')->find();
        if(!$user) {
            $this->exitJson( array() ,10030,'找不到该邮箱注册信息!');
        }
        $code = md5($user["uid"].'+'.$user["password"].'+'.rand(1111,9999));
        $config['reseturl'] = U('public/Passport/resetPassword', array('code'=>$code));
        //设置旧的code过期
        M('FindPassword')->where('uid='.$user["uid"])->setField('is_used',1);
        //添加新的修改密码code
        $add['uid']     = $user['uid'];
        $add['email']   = $email;
        $add['code']    = $code;
        $add['is_used'] = 0;
        $result = M('FindPassword')->add($add);
        if($result){
            model('Notify')->sendNotify($user['uid'], 'password_reset', $config);
            $this->exitJson(true);
        }else{
            $this->exitJson( array() ,10024,'操作失败，请重试');
        }
    }
    
    /*
	 * 正则匹配，验证邮箱格式
	 * @return integer 1=成功 ""=失败
	 */
    private function _isEmailString($email) {
        return preg_match("/[_a-zA-Z\d\-\.]+@[_a-zA-Z\d\-]+(\.[_a-zA-Z\d\-]+)+$/i", $email) !== 0;
    }


    /**
     * 注册
     * uid 用户ID
     * face_id  人脸ID
     */
    public function login_registerface()
    {
        //接收加密参数
        //$parm = $_SERVER['QUERY_STRING'];
       $registerfaceid = $_GET['registerfaceid'];
        //获取解密返回参数
        $params = dec($registerfaceid);
        //保存人脸face_id
        $result = M('user');
        $result->where(['uid'=>$params['uid']])->setField('face_id',$params['face_id']);
        if($result){
            $this->exitJson('注册成功',1000);
        }else{
            $this->exitJson('注册失败',4001,'error');
        }
    }

    /**
     * 人脸登录验证
     * uid 用户ID
     * face_id  人脸ID
     */
    public function login_face()
    {
        //接收加密参数
        //$parm = $_SERVER['QUERY_STRING'];
        //$time = time();
        $loginfaceid = $_GET['loginfaceid'];
       // $loginfaceid = base64_encode("uid=1&face_id=1");
       // $r = base64_decode($loginfaceid);
       // dump($r);exit;
        //获取解密返回参数
        $reture_params = dec($loginfaceid);
        //查询用户信息
        $result = M('user');
        $result->where(['uid'=>$reture_params['uid'],'face_id'=>$reture_params['face_id']])->find();
        if($result){
            $this->exitJson('验证成功',1000);
        }else{
            $this->exitJson('验证失败，请重新登录',4001,'error');
        }
    }


}








?> 