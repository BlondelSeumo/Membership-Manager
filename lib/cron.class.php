<?php

  /**
   * Cron Class
   *
   * @package Wojo Framework
   * @author wojoscripts.com
   * @copyright 2016
   * @version $Id: cron.class.php, v1.00 2016-04-20 18:20:24 gewa Exp $
   */

  if (!defined("_WOJO"))
      die('Direct access to this location is not allowed.');

  class Cron
  {


      /**
       * Cron::Run()
       * 
       * @return
       */
      public static function Run($days)
      {
		  
          self::runStripe();
		  $data = self::expireMemberships($days);
		  self::sendEmails($data);
      }

      /**
       * Cron::expireMemberships()
       * 
       * @param integer $days
       * @return
       */
      public static function expireMemberships($days)
      {

          $sql = "
		  SELECT 
			u.id, CONCAT(u.fname,' ',u.lname) as fullname,
			u.email, u.mem_expire, m.id AS mid, m.title 
		  FROM
			`" . Users::mTable . "` AS u 
			LEFT JOIN `" . Membership::mTable . "` AS m 
			  ON m.id = u.membership_id
		  WHERE u.active = ?
		  AND u.membership_id <> 0
		  AND u.mem_expire <= DATE_ADD(DATE(NOW()), INTERVAL $days DAY);";

          $result = Db::run()->pdoQuery($sql, array("y"))->results();

          if ($result) {
              $query = "UPDATE `" . Users::mTable . "` SET mem_expire = NULL, membership_id = CASE ";
              $idlist = '';
              foreach ($result as $usr) {
                  $query .= " WHEN id = " . $usr->id . " THEN membership_id = 0";
                  $idlist .= $usr->id . ',';
              }
              $idlist = substr($idlist, 0, -1);
              $query .= "
				  END
				  WHERE id IN (" . $idlist . ")";
              Db::run()->pdoQuery($query);
          }

      }

      /**
       * Cron::sendEmails()
       * 
       * @param array $data
       * @return
       */
      public static function sendEmails($data)
      {

          if ($data) {
              $numSent = 0;
              $mailer = Mailer::sendMail();
              $mailer->registerPlugin(new Swift_Plugins_AntiFloodPlugin(100, 30));
			  $core = App::Core();

              $tpl = Db::run()->first(Content::eTable, array("body", "subject"), array('typeid' => 'memExpired'));

              $replacements = array();
              foreach ($data as $cols) {
                  $replacements[$cols->email] = array(
                      '[COMPANY]' => $core->company,
                      '[LOGO]' => Utility::getLogo(),
                      '[NAME]' => $cols->fullname,
                      '[ITEMNAME]' => $cols->title,
					  '[EXPIRE]' => Date::doDate("short_date", $cols->mem_expire),
                      '[SITEURL]' => SITEURL,
                      '[DATE]' => date('Y'),
					  '[FB]' => $core->social->facebook,
					  '[TW]' => $core->social->twitter,
					  );
              }

              $decorator = new Swift_Plugins_DecoratorPlugin($replacements);
              $mailer->registerPlugin($decorator);

              $message = Swift_Message::newInstance()
					  ->setSubject($tpl->subject)
					  ->setFrom(array($core->site_email => $core->company))
					  ->setBody($tpl->body, 'text/html');

              foreach ($data as $row) {
                  $message->setTo(array($row->email => $row->fullname));
                  $numSent++;
                  $mailer->send($message, $failedRecipients);
              }
              unset($row);
          }

      }

      /**
       * Cron::runStripe()
       * 
       * @return
       */
      public static function runStripe()
      {
          $sql = "
		  SELECT 
			u.id AS uid, CONCAT(u.fname,' ',u.lname) as fullname,
			u.email, cj.amount, cj.id as cid, cj.membership_id, cj.stripe_customer, cj.stripe_pm
		  FROM
			`" . Core::cjTable . "` AS cj 
			LEFT JOIN `" . Users::mTable . "` AS u 
			  ON u.id = cj.user_id
		  WHERE u.active = ?
		  AND DATE(cj.renewal) = CURDATE();";

          $data = Db::run()->pdoQuery($sql, array(1, 1))->results();

		  require_once BASEPATH . "/gateways/stripe/vendor/autoload.php";
          $key = Db::run()->first(AdminController::gTable, array("extra", "extra2"), array("name" => "stripe"));
          \Stripe\Stripe::setApiKey($key->extra);
	  
		  if ($data) {
              try {
                  foreach ($data as $row) {
					  \Stripe\PaymentIntent::create([
						  'amount' => round(($row->amount) * 100, 0),
						  'currency' => $key->extra2,
						  'customer' => $row->stripe_customer,
						  'payment_method' => $row->stripe_pm,
						  'off_session' => true,
						  'confirm' => true,
					  ]);

                      // insert transaction
					  $sdata = array(
						  'txn_id' => time(),
						  'membership_id' => $row->membership_id,
						  'user_id' => $row->uid,
						  'rate_amount' => $row->amount,
						  'coupon' => 0,
						  'total' => $row->amount,
						  'tax' => 0,
						  'currency' => $key->extra2,
						  'ip' => Url::getIP(),
						  'pp' => "Stripe",
						  'status' => 1,
						  );

                      $last_id = Db::run()->insert(Membership::pTable, $sdata)->getLastInsertId();

					  //insert user membership
					  $udata = array(
						  'tid' => $last_id,
						  'uid' => $row->uid,
						  'mid' => $row->membership_id,
						  'expire' => Membership::calculateDays($row->membership_id),
						  'recurring' => 1,
						  'active' => 1,
						  );
						  
					  Db::run()->insert(Membership::umTable, $udata);

					  //update user record
					  Db::run()->update(Users::mTable, array('mem_expire' => $udata['expire'], 'membership_id' => $udata['mid']), array("id" => $row->uid));
					  
					  //update cron record
					  Db::run()->update(Core::cjTable, array('renewal' => $udata['expire']), array("id" => $row->cid));

					  //notify user
					  //self::notifyUser($row);
					  
					  //notify admin
					  //self::notifyAdmin($row);
                  }

              }
              catch (\Stripe\CardError $e) {
              }
			  
		  }
      }
  }