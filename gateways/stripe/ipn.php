<?php
  /**
   * Stripe IPN
   *
   * @package Wojo Framework
   * @author wojoscripts.com
   * @copyright 2019
   * @version $Id: ipn.php, v1.00 2019-08-08 10:12:05 gewa Exp $
   */
  define("_WOJO", true);
  require_once ("../../init.php");

  if (!App::Auth()->is_User())
      exit;

  ini_set('log_errors', true);
  ini_set('error_log', dirname(__file__) . '/ipn_errors.log');

  if (isset($_POST['processStripePayment'])) {
	  $rules = array(
		  'payment_method' => array('required|string', "Invalid Payment Method"),
		  );
			  
	  $validate = Validator::instance();
	  $safe = $validate->doValidate($_POST, $rules);

      if (!$cart = Membership::getCart()) {
          Message::$msgs['cart'] = Lang::$word->STR_ERR;
      }

      if (empty(Message::$msgs)) {
          require_once BASEPATH . "/gateways/stripe/vendor/autoload.php";

          $key = Db::run()->first(AdminController::gTable, array("extra", "extra2"), array("name" => "stripe"));

          \Stripe\Stripe::setApiKey($key->extra);
          try {
              //Create a client
              $client = \Stripe\Customer::create(array(
			      "payment_method" => $safe->payment_method,
                  "description" => App::Auth()->name,
                  ));

              $row = Db::run()->first(Membership::mTable, null, array("id" => $cart->mid));

              // insert payment record
              $data = array(
                  'txn_id' => time(),
                  'membership_id' => $row->id,
                  'user_id' => App::Auth()->uid,
                  'rate_amount' => $cart->total,
                  'coupon' => $cart->coupon,
				  'total' => $cart->totalprice,
				  'tax' => $cart->totaltax,
				  'currency' => $key->extra2,
                  'ip' => Url::getIP(),
                  'pp' => "Stripe",
                  'status' => 1,
                  );

              $last_id = Db::run()->insert(Membership::pTable, $data)->getLastInsertId();

              //insert user membership
              $udata = array(
                  'tid' => $last_id,
				  'uid' => App::Auth()->uid,
                  'mid' => $row->id,
                  'expire' => Membership::calculateDays($row->id),
                  'recurring' => $row->recurring,
                  'active' => 1,
                  );

              //update user record
              $xdata = array(
				  'stripe_cus' => $client['id'],
				  'membership_id' => $row->id,
                  'mem_expire' => $udata['expire'],
                  );
				  
              Db::run()->insert(Membership::umTable, $udata);
              Db::run()->update(Users::mTable, $xdata, array("id" => App::Auth()->uid));

			  //insert cron record
			  if($row->recurring) {
				  $cdata = array(
					  'user_id' => App::Auth()->uid,
					  'membership_id' => $row->id,
					  'amount' => $cart->totalprice,
					  'stripe_customer' => $client['id'],
					  'stripe_pm' => $safe->payment_method,
					  'renewal' => $udata['expire'],
					  ); 
				  Db::run()->insert(Core::cjTable, $cdata);
			  }
			  
              Db::run()->delete(Membership::cTable, array("uid" => App::Auth()->uid));

              //update membership status
			  Auth::$udata->membership_id = App::Session()->set('membership_id', $row->id);
			  Auth::$udata->mem_expire = App::Session()->set('mem_expire', $xdata['mem_expire']);

              $jn['type'] = 'success';
			  $jn['title'] = Lang::$word->SUCCESS;
              $jn['message'] = Lang::$word->STR_POK;
              print json_encode($jn);

              /* == Notify Administrator == */
              $mailer = Mailer::sendMail();
              $tpl = Db::run()->first(Content::eTable, array("body", "subject"), array('typeid' => 'payComplete'));
              $core = App::Core();
              $body = str_replace(array(
			      '[LOGO]',
				  '[COMPANY]',
				  '[DATE]',
				  '[SITEURL]',
                  '[NAME]',
                  '[ITEMNAME]',
                  '[PRICE]',
                  '[STATUS]',
                  '[PP]',
                  '[IP]',
                  '[FB]',
                  '[TW]'), array(
				  Utility::getLogo(),
				  $core->company,
				  date('Y'),
				  SITEURL,
                  App::Auth()->name,
                  $row->title,
                  $data['total'],
                  "Completed",
                  "Stripe",
                  Url::getIP(),
                  $core->social->facebook,
                  $core->social->twitter), $tpl->body);

              $msg = Swift_Message::newInstance()
					->setSubject($tpl->subject)
					->setTo(array($core->psite_email ? $core->psite_email : $core->site_email => $core->company))
					->setFrom(array(App::Auth()->email => App::Auth()->name))
					->setBody($body, 'text/html');
              $mailer->send($msg);

          }
          catch (\Stripe\Error\Card $e) {
              $body = $e->getJsonBody();
              $err = $body['error'];
              $json['type'] = 'error';
              Message::$msgs['msg'] = 'Message is: ' . $err['message'] . "\n";
              Message::msgSingleStatus();
          }
      } else {
          Message::msgSingleStatus();
      }
  }