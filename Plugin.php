<?php
/**
 * TeePayForTypecho自媒体付费阅读插件
 * @package TeePay For Typecho
 * @author 小否先生
 * @version 1.0.9
 * @link http://www.vfaxian.com/
 * @date 2019-04-07
 */
class TeePay_Plugin implements Typecho_Plugin_Interface{
    // 激活插件
    public static function activate(){
		Helper::addPanel(3, 'TeePay/manage-posts.php', '文章付费', '管理文章付费', 'administrator');
		Helper::addAction('teepay-post-edit', 'TeePay_Action');
		Typecho_Plugin::factory('admin/write-post.php')->bottom = array('TeePay_Plugin', 'tleTeePayToolbar');
		Typecho_Plugin::factory('Widget_Archive')->footer = array('TeePay_Plugin', 'footer');
		//后台增加字段
		Typecho_Plugin::factory('admin/write-post.php')->option = array(__CLASS__, 'setFeeContent');
		Typecho_Plugin::factory('Widget_Contents_Post_Edit')->finishPublish = array(__CLASS__, "updateFeeContent");
		Typecho_Plugin::factory('Widget_Archive')->select = array(__CLASS__, 'selectHandle');
		
		$db = Typecho_Db::get();
		$prefix = $db->getPrefix();
		self::alterColumn($db,$prefix.'contents','teepay_isFee','enum("y","n") DEFAULT "n"');
		self::alterColumn($db,$prefix.'contents','teepay_price','double(10,2) DEFAULT 0');
		self::alterColumn($db,$prefix.'contents','teepay_content','text');
		self::alterColumn($db,$prefix.'users','teepay_money','double(10,2) DEFAULT 0');
		self::alterColumn($db,$prefix.'users','teepay_point','int(11) DEFAULT 0');
		self::createTableTeePayFee($db);
		
		self::alterColumn($db,$prefix.'teepay_fees','feecookie','varchar(255) DEFAULT NULL');
		self::alterColumn($db,$prefix.'contents','teepay_islogin','enum("y","n") DEFAULT "n"');
		
        return _t('插件已经激活，需先配置插件信息！');
    }
	/**
	 * 把付费内容设置装入文章编辑页
	 *
	 * @access public
	 * @return void
	 */
	public static function setFeeContent($post) {
		$db = Typecho_Db::get();
		$row = $db->fetchRow($db->select('teepay_content,teepay_price,teepay_isFee')->from('table.contents')->where('cid = ?', $post->cid));
		$teepay_content = isset($row['teepay_content']) ? $row['teepay_content'] : '';	
		$teepay_price = isset($row['teepay_price']) ? $row['teepay_price'] : '';	
		$teepay_isFee = isset($row['teepay_isFee']) ? $row['teepay_isFee'] : '';	
		$html = '<section class="typecho-post-option"><label for="teepay_price" class="typecho-label">是否付费</label>
				<p><span><input name="teepay_isFee" type="radio" value="n" id="teepay_isFee-n" checked="true">
				<label for="teepay_isFee-n">
				免费的</label>
				</span><span>
				<input name="teepay_isFee" type="radio" value="y" id="teepay_isFee-y">
				<label for="teepay_isFee-y">
				要付费</label>
				</span></p></section>
				<section class="typecho-post-option"><label for="teepay_price" class="typecho-label">付费价格（元）</label><p><input id="teepay_price" name="teepay_price" type="text" value="'.$teepay_price.'" class="w-100 text"></p></section>
				<section class="typecho-post-option"><label for="teepay_content" class="typecho-label">付费可见内容</label><p><textarea id="teepay_content" name="teepay_content" type="text" value="" class="w-100 text">'.$teepay_content.'</textarea></p></section>';
		_e($html);
	}
	/**
	 * 发布文章同时更新文章类型
	 *
	 * @access public
	 * @return void
	 */
	public static function updateFeeContent($contents, $post){
		$teepay_isFee = $post->request->get('teepay_isFee', NULL);
		$teepay_price = $post->request->get('teepay_price', NULL);
		$teepay_content = $post->request->get('teepay_content', NULL);
		$db = Typecho_Db::get();
		$sql = $db->update('table.contents')->rows(array('teepay_isFee' => $teepay_isFee,'teepay_price' => $teepay_price,'teepay_content' => $teepay_content))->where('cid = ?', $post->cid);
		$db->query($sql);
	}
    /**
     * 把增加的字段添加到查询中，以便在模版中直接调用
     *
     * @access public
     * @return void
     */
	public static function selectHandle($archive){
		$user = Typecho_Widget::widget('Widget_User');
		if ('post' == $archive->parameter->type || 'page' == $archive->parameter->type) {
			if ($user->hasLogin()) {
				$select = $archive->select()->where('table.contents.status = ? OR table.contents.status = ? OR
						(table.contents.status = ? AND table.contents.authorId = ?)',
						'publish', 'hidden', 'private', $user->uid);
			} else {
				$select = $archive->select()->where('table.contents.status = ? OR table.contents.status = ?',
						'publish', 'hidden');
			}
		} else {
			if ($user->hasLogin()) {
				$select = $archive->select()->where('table.contents.status = ? OR
						(table.contents.status = ? AND table.contents.authorId = ?)', 'publish', 'private', $user->uid);
			} else {
				$select = $archive->select()->where('table.contents.status = ?', 'publish');
			}
		}
		$select->where('table.contents.created < ?', Typecho_Date::gmtTime());
		$select->cleanAttribute('fields');
		return $select;
	}
	/**
     * 在主题中直接调用
     *
     * @access public
     * @return int
     * @throws
     */
    public static function getTeePay()
    {
        $db = Typecho_Db::get();
        $cid = Typecho_Widget::widget('Widget_Archive')->cid;
		$query= $db->select()->from('table.contents')->where('cid = ?', $cid ); 
		$row = $db->fetchRow($query);
		if($row['teepay_isFee']=='y'&&$row['authorId']!=Typecho_Cookie::get('__typecho_uid')){
		if(!isset($_COOKIE["ReadyPayCookie"])){
			$cookietime=$option->teepay_cookietime==""?1:$option->teepay_cookietime;
			$randomCode = md5(uniqid(microtime(true),true));
			setcookie("ReadyPayCookie",$randomCode, time()+3600*24*$cookietime);
			$ReadyPayCookie=$randomCode;
		}else{
			$ReadyPayCookie=$_COOKIE["ReadyPayCookie"];
		}
		$queryItem= $db->select()->from('table.teepay_fees')->where('feecookie = ?', $ReadyPayCookie)->where('feestatus = ?', 1)->where('feecid = ?', $row['cid']); 
		$rowItem = $db->fetchRow($queryItem);
		$rowUserItemNum = 0;
		if(Typecho_Cookie::get('__typecho_uid')){
			$queryUserItem= $db->select()->from('table.teepay_fees')->where('feeuid = ?', Typecho_Cookie::get('__typecho_uid'))->where('feestatus = ?', 1)->where('feecid = ?', $row['cid']); 
			$rowUserItem = $db->fetchRow($queryUserItem);
			$rowUserItemNum = 1;
		}
		if(count($rowItem) != 0 || $rowUserItemNum){ ?>			
			<div style="background:#f8f8f8;padding:30px 20px;border:1px dashed #ccc;position: relative;z-index:999;margin:15px 0">
				<span><?php echo $row['teepay_content'] ?></span>
				<span style="position: absolute;top:5px;left:15px;font-size:90%;color:#90949c;">付费可读</span>
				<span style="position: absolute;top:8px;right:10px;"><img style="width:22px;" src="https://i.loli.net/2019/04/12/5cb00c4688f8f.png" alt=""></span>
			</div>
		<?php }else{ ?>	
			<div style="background:#f8f8f8;padding:30px 20px;border:1px dashed #ccc;position: relative;text-align:center;margin:15px 0;">
				<form id="teepayPayPost" onsubmit="return false" action="##" method="post" style="margin:10px 0;">						
					<input type="radio" id="feetype1" name="feetype" value="alipay">支付宝支付
					<input type="radio" id="feetype2" name="feetype" value="wxpay" checked>微信支付
					<div style="clear:left;"></div>
					<div style="height:34px;line-height:34px;border:none;-moz-border-radius: 0px;-webkit-border-radius: 0px;border-radius:0px;">
					价格： <?php echo $row['teepay_price'] ?> 元
					</div>
					<button id="verifybtn" style="border-radius: 4px; border-style: none; width: 80px; height: 34px; line-height: 34px; padding: 0 5px; background-color: #F60; text-align: center; color: #FFF; font-size: 14px;cursor: pointer;" type="submit" onclick="teepayPayPost()"/>付款</button>
					<input type="hidden" name="action" value="paysubmit" />
					<input type="hidden" id="feecid" name="feecid" value="<?php echo $row['cid'] ?>" />
					<input type="hidden" id="feeuid" name="feeuid" value="<?php Typecho_Cookie::get('__typecho_uid') ?>" />
					<input type="hidden" id="feecookie" name="feecookie" value="<?php echo $ReadyPayCookie ?>" />
				</form>
				<div style="clear:left;"></div>
				<span>温馨提示：<span style="color: red">免登录付款支付后1天内可重复阅读隐藏内容，<a href="/signin.html" style="">登录</a></span>用户可永久阅读隐藏的内容。 </span>
				<span style="position: absolute;top:5px;left:15px;font-size:90%;color:#90949c;">付费可读</span>
				<span style="position: absolute;top:8px;right:10px;"><img style="width:22px;" src="https://i.loli.net/2019/04/12/5cb00c4688f8f.png" alt=""></span>
			</div>
			<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.4.0/jquery.min.js"></script>	
			<script src="https://cdnjs.cloudflare.com/ajax/libs/layer/2.3/layer.js"></script>
			<script src="https://vfaxian.com/usr/plugins/TeePay/teepay.js"></script>	
		<?php } ?> 
	<?php }elseif($row['teepay_isFee']=='y'&&$row['authorId']==Typecho_Cookie::get('__typecho_uid')){ ?>			
		<div style="background:#f8f8f8;padding:30px 20px;border:1px dashed #ccc;position: relative;z-index:999;margin:15px 0">
			<span><?php echo $row['teepay_content'] ?></span>
			<span style="position: absolute;top:5px;left:15px;font-size:90%;color:#90949c;">付费可读</span>
			<span style="position: absolute;top:8px;right:10px;"><img style="width:22px;" src="https://i.loli.net/2019/04/12/5cb00c4688f8f.png" alt=""></span>
		</div>
	<?php } 
    }
    // 禁用插件
    public static function deactivate(){
		//删除页面模板		
		Helper::removeAction('teepay-post-edit');
		Helper::removePanel(3, 'TeePay/manage-posts.php');
        return _t('插件已被禁用');
    }

    // 插件配置面板
    public static function config(Typecho_Widget_Helper_Form $form){
		$db = Typecho_Db::get();
		$prefix = $db->getPrefix();
		$options = Typecho_Widget::widget('Widget_Options');
		$plug_url = $options->pluginUrl;
		//版本检查
		$div=new Typecho_Widget_Helper_Layout();
		$div->html('<small>		
			<h6>基础功能</h6>
			<span><p>第一步：配置下方各项参数；</p></span>
			<span>
				<p>
					第二步：在主题post.php文件相应位置添加：<font color="blue">&lt;?php echo TeePay_Plugin::getTeePay(); ?></font>。
				</p>
			</span>
			<span><p>第三步：等待其他用户或游客购买对应付费文章；</p></span>
		</small>');
		$div->render();
		
		//配置信息
		$teepay_cookietime = new Typecho_Widget_Helper_Form_Element_Text('teepay_cookietime', array('value'), 1, _t('免登录Cookie保存时间(天)'), _t('指定使用免登录付费后几天内可以查看隐藏内容，默认为1天，不会记录到买入订单中。'));
        $form->addInput($teepay_cookietime);
		//alipay配置
		$alipay_appid = new Typecho_Widget_Helper_Form_Element_Text('alipay_appid', array('value'), "", _t('支付宝appid'), _t('支付宝的appid号。'));
        $form->addInput($alipay_appid);
		$app_private_key = new Typecho_Widget_Helper_Form_Element_Text('app_private_key', array('value'), "", _t('支付宝应用私钥'), _t('在支付宝对应的私钥。'));
        $form->addInput($app_private_key);
		$alipay_public_key = new Typecho_Widget_Helper_Form_Element_Text('alipay_public_key', array('value'), "", _t('支付宝公钥'), _t('在支付宝生成的公钥。'));
        $form->addInput($alipay_public_key);
		$alipay_notify_url = new Typecho_Widget_Helper_Form_Element_Text('alipay_notify_url', array('value'), $plug_url.'/TeePay/alipay_notify_url.php', _t('支付宝异步回调接口'), _t('支付完成后异步回调的接口地址。'));
        $form->addInput($alipay_notify_url);
		//payjs配置	
		$payjs_wxpay_mchid = new Typecho_Widget_Helper_Form_Element_Text('payjs_wxpay_mchid', array('value'), "", _t('payjs商户号'), _t('在<a href="https://payjs.cn/" target="_blank">payjs官网</a>注册的商户号。'));
        $form->addInput($payjs_wxpay_mchid);
		$payjs_wxpay_key = new Typecho_Widget_Helper_Form_Element_Text('payjs_wxpay_key', array('value'), "", _t('payjs通信密钥'), _t('在<a href="https://payjs.cn/" target="_blank">payjs官网</a>注册的通信密钥。'));
        $form->addInput($payjs_wxpay_key);
		$payjs_wxpay_notify_url = new Typecho_Widget_Helper_Form_Element_Text('payjs_wxpay_notify_url', array('value'), $plug_url.'/TeePay/wxpay_notify_url.php', _t('payjs异步回调接口'), _t('支付完成后异步回调的接口地址。'));
        $form->addInput($payjs_wxpay_notify_url);
		//邮件中心
		$mailsmtp = new Typecho_Widget_Helper_Form_Element_Text('mailsmtp', null, '', _t('smtp服务器(已验证QQ企业邮箱和126邮箱可成功发送)'), _t('用于用户中心发送邮箱验证码及其他邮件服务的smtp服务器地址，QQ企业邮箱：ssl://smtp.exmail.qq.com:465；126邮箱：smtp.126.com:25'));
        $form->addInput($mailsmtp);
		$mailport = new Typecho_Widget_Helper_Form_Element_Text('mailport', null, '', _t('smtp服务器端口'), _t('用于用户中心发送邮箱验证码及其他邮件服务的smtp服务器端口'));
        $form->addInput($mailport);
		$mailuser = new Typecho_Widget_Helper_Form_Element_Text('mailuser', null, '', _t('smtp服务器邮箱用户名'), _t('用于用户中心发送邮箱验证码及其他邮件服务的smtp服务器邮箱用户名'));
        $form->addInput($mailuser);
		$mailpass = new Typecho_Widget_Helper_Form_Element_Password('mailpass', null, '', _t('smtp服务器邮箱密码'), _t('用于用户中心发送邮箱验证码及其他邮件服务的smtp服务器邮箱密码'));
        $form->addInput($mailpass);				
    }

    // 个人用户配置面板
    public static function personalConfig(Typecho_Widget_Helper_Form $form){
    }

    // 获得插件配置信息
    public static function getConfig(){
        return Typecho_Widget::widget('Widget_Options')->plugin('TeePay');
    }
	
	
	/*发送给管理员邮件通知*/
	public static function sendMail($email,$title,$content){
		require __DIR__ . '/libs/email.class.php';
		$options = Typecho_Widget::widget('Widget_Options');
		$option=$options->plugin('TeePay');
		$smtpserverport =$option->mailport;//SMTP服务器端口//企业QQ:465、126:25
		$smtpserver = $option->mailsmtp;//SMTP服务器//QQ:ssl://smtp.qq.com、126:smtp.126.com
		$smtpusermail = $option->mailuser;//SMTP服务器的用户邮箱
		$smtpemailto = $email;//发送给谁
		$smtpuser = $option->mailuser;//SMTP服务器的用户帐号
		$smtppass = $option->mailpass;//SMTP服务器的用户密码
		$mailtitle = $title;//邮件主题
		$mailcontent = $content;//邮件内容
		$mailtype = "HTML";//邮件格式（HTML/TXT）,TXT为文本邮件
		//************************ 配置信息 ****************************
		$smtp = new smtp($smtpserver,$smtpserverport,true,$smtpuser,$smtppass);//这里面的一个true是表示使用身份验证,否则不使用身份验证.
		$smtp->debug = false;//是否显示发送的调试信息
		$state = $smtp->sendmail($smtpemailto, $smtpusermail, $mailtitle, $mailcontent, $mailtype);
		return $state;
	}
	

	/**
     * 后台编辑器添加付费阅读按钮
     * @access public
     * @return void
     */
	public static function tleTeePayToolbar(){
		?>
		<script type="text/javascript">
			$(function(){
				if($('#wmd-button-row').length>0){
					$('#wmd-button-row').append('<li class="wmd-button" id="wmd-button-Forlogin" style="font-size:20px;float:left;color:#AAA;width:16px;" title=登录可见><b>L</b></li>');
				}else{
					$('#text').before('<a href="#" id="wmd-button-Forlogin" title="登录可见"><b>L</b></a>');
				}
				$(document).on('click', '#wmd-button-Forlogin', function(){
					$('#text').val($('#text').val()+'\r\n[Forlogin]\r\n\r\n[/Forlogin]');
				});
				/*移除弹窗*/
				if(($('.wmd-prompt-dialog').length != 0) && e.keyCode == '27') {
					cancelAlert();
				}
			});
			function cancelAlert() {
				$('.wmd-prompt-dialog').remove()
			}
		</script>
		<?php
	}
	
	
	public static function footer(){
		
	}
  	/*创建支付订单数据表*/
	public static function createTableTeePayFee($db){
		$prefix = $db->getPrefix();
		//$db->query('DROP TABLE IF EXISTS '.$prefix.'weibofile_videoupload');
		$db->query('CREATE TABLE IF NOT EXISTS `'.$prefix.'teepay_fees` (
		  `feeid` varchar(64) COLLATE utf8_general_ci NOT NULL,
		  `feecid` bigint(20) DEFAULT NULL,
		  `feeuid` bigint(20) DEFAULT NULL,
		  `feeprice` double(10,2) DEFAULT NULL,
		  `feetype` enum("alipay","wxpay","wx","WEIXIN_DAIXIAO","qqpay","bank_pc","tlepay") COLLATE utf8_general_ci DEFAULT "alipay",
		  `feestatus` smallint(2) DEFAULT "0" COMMENT "订单状态：0、未付款；1、付款成功；2、付款失败",
		  `feeinstime` datetime DEFAULT NULL,
		  PRIMARY KEY (`feeid`)
		) DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;');
	}
	public static function form($action = NULL)
	{
		/** 构建表格 */
		$options = Typecho_Widget::widget('Widget_Options');
		$form = new Typecho_Widget_Helper_Form(Typecho_Common::url('/action/teepay-post-edit', $options->index),
		Typecho_Widget_Helper_Form::POST_METHOD);
		
		/** 标题 */
		$title = new Typecho_Widget_Helper_Form_Element_Text('title', NULL, NULL, _t('标题*'));
		$form->addInput($title);
		
		/** 是否付费 */
		$teepay_isFee = new Typecho_Widget_Helper_Form_Element_Radio('teepay_isFee', 
						array('n' => _t('免费的'), 'y' => _t('要付费')),
						'n', _t('是否需付费*'));		
		$form->addInput($teepay_isFee);
		
		/** 付费情况 */
		$teepay_price = new Typecho_Widget_Helper_Form_Element_Text('teepay_price', NULL, NULL, _t('付费情况*'));
		$form->addInput($teepay_price);
		
		/** 是否需登录 */
		$teepay_islogin = new Typecho_Widget_Helper_Form_Element_Radio('teepay_islogin', 
						array('n' => _t('免登录'), 'y' => _t('需登录')),
						'n', _t('是否需登录*'));		
		$form->addInput($teepay_islogin);
		
		/** 付费可见内容 */
		$teepay_content = new Typecho_Widget_Helper_Form_Element_Textarea('teepay_content', NULL, NULL, _t('付费可见内容*'));
		$form->addInput($teepay_content);
			
		/** 链接动作 */
		$do = new Typecho_Widget_Helper_Form_Element_Hidden('do');
		$form->addInput($do);
		
		/** 链接主键 */
		$cid = new Typecho_Widget_Helper_Form_Element_Hidden('cid');
		$form->addInput($cid);
		
		/** 提交按钮 */
		$submit = new Typecho_Widget_Helper_Form_Element_Submit();
		$submit->input->setAttribute('class', 'btn primary');
		$form->addItem($submit);
		$request = Typecho_Request::getInstance();

        if (isset($request->cid) && 'insert' != $action) {
            /** 更新模式 */
			$db = Typecho_Db::get();
			$prefix = $db->getPrefix();
            $post = $db->fetchRow($db->select()->from($prefix.'contents')->where('cid = ?', $request->cid));
            if (!$post) {
                throw new Typecho_Widget_Exception(_t('文章不存在'), 404);
            }
            
            $title->value($post['title']);
            $teepay_isFee->value($post['teepay_isFee']);
            $teepay_price->value($post['teepay_price']);
            $teepay_islogin->value($post['teepay_islogin']);
            $teepay_content->value($post['teepay_content']);
            $do->value('update');
            $cid->value($post['cid']);
            $submit->value(_t('确认付费'));
            $_action = 'update';
        } else {
            $submit->value(_t('确认付费'));
        }
        
        if (empty($action)) {
            $action = $_action;
        }
      
        return $form;
	}
}