<?php

/**
 * @module Assets
 */
/**
 * Class for manipulating credits
 * @class Assets_Credits
 */
class Assets_Credits extends Base_Assets_Credits
{
	const DEFAULT_AMOUNT = 0;

	/**
	 * Formats an amount of credits for display
	 * @method format
	 * @static
	 * @param {float} $amount
	 * @return {string}
	 */
	static function format($amount)
	{
		return number_format($amount, 2);
	}

	/**
	 * @method getAllAttributes
	 * @return {array} The array of all attributes set in the stream
	 */
	function getAllAttributes()
	{
		return empty($this->attributes) ? array() : json_decode($this->attributes, true);
	}
	/**
	 * @method getAttribute
	 * @param {string} $attributeName The name of the attribute to get
	 * @param {mixed} $default The value to return if the attribute is missing
	 * @return {mixed} The value of the attribute, or the default value, or null
	 */
	function getAttribute($attributeName, $default = null)
	{
		$attr = $this->getAllAttributes();
		return isset($attr[$attributeName]) ? $attr[$attributeName] : $default;
	}
	/**
	 * @method setAttribute
	 * @param {string|array} $attributeName The name of the attribute to set,
	 *  or an array of $attributeName => $attributeValue pairs
	 * @param {mixed} $value The value to set the attribute to
	 * @return Assets_Credits
	 */
	function setAttribute($attributeName, $value = null)
	{
		$attr = $this->getAllAttributes();
		if (is_array($attributeName)) {
			foreach ($attributeName as $k => $v) {
				$attr[$k] = $v;
			}
		} else {
			$attr[$attributeName] = $value;
		}
		$this->attributes = Q::json_encode($attr);

		return $this;
	}
	/**
	 * Get a community's stream of credits for a user
	 * @method stream
	 * @static
	 * @param {string} [$communityId=Users::communityId()]
	 *   The community managing the credits. Defaults to Users::communityId()
	 * @param {string} [$userId=Users::loggedInUser()]
	 *   The id of the user for which the stream is obtained. Defaults to logged-in user.
	 * @param {string} [$asUserId=null]
	 *   The id of the user who is trying to obtain it. Defaults to logged-in user.
	 *   Pass false here to skip access checks during fetch.
	 * @param {boolean} [$throwIfNotLoggedIn=false]
	 *   Whether to throw a Users_Exception_NotLoggedIn if no user is logged in.
	 * @return {Streams_Stream|null}
	 * @throws {Users_Exception_NotLoggedIn} If user is not logged in and
	 *   $throwIfNotLoggedIn is true
	 */
	static function stream($communityId = null, $userId = null, $asUserId = null, $throwIfNotLoggedIn = false)
	{
		if (!$communityId) {
			$communityId = Users::communityId();
		}
		if (!isset($userId)) {
			$user = Users::loggedInUser($throwIfNotLoggedIn, false);
			if (!$user) {
				return null;
			}
		} else {
			$user = Users_User::fetch($userId, true);
		}
		$userId = $user->id;
		$publisherId = $communityId;
		$streamName = "Assets/credits/$userId";
		$stream = Streams::fetchOneOrCreate($asUserId, $publisherId, $streamName, array(
			'fields' => array(
				'type' => "Assets/credits",
				'attributes' => array('amount' => 0)
			),
			'skipAccess' => true,
			'subscribe' => true
		), $results);
		if ($results['created']) {
			Streams_Access::insert(array(
				'publisherId' => $communityId,
				'streamName' => $streamName,
				'ofUserId' => $userId,
				'ofContactLabel' => '',
				'ofParticipantRole' => '',
				'readLevel' => 40,
				'writeLevel' => 10,
				'adminLevel' => 20
			))->execute();
			$stream->calculateAccess($userId, true);
			$amount = Q_Config::get('Assets', 'credits', 'grant', 'Users/insertUser', self::DEFAULT_AMOUNT);
			if ($amount > 0) {
				self::grant($communityId, $amount, 'YouHaveCreditsToStart', $userId);
			}
		}
		return $stream;
	}
	
	/**
	 * Amount of community credits a user has
	 * @method amount
	 * @static
	 * @param {string} [$communityId=Users::communityid()]
	 *   The community managing the credits, defaults to Users::communityId()
	 * @param {string} [$userId=Users::loggedInUser()]
	 *   The id of the user for which the stream is obtained. Defaults to logged-in user.
	 * @return {float} The amount of credits
	 * @throws {Users_Exception_NotLoggedIn} If user is not logged in
	 */
	static function amount($communityId = null, $userId = null)
	{
		if (!$communityId) {
			$communityId = Users::communityId();
		}
		$stream = self::stream($communityId, $userId, $userId);
		if ($stream instanceof Streams_Stream) {
			return (float)$stream->getAttribute('amount');
		}
		return 0;
	}
	/**
	 * Check if payment details amounts sum equal to general amount
	 * @method checkAmount
	 * @static
	 * @param {integer} $amount The amount of credits to spend.
	 * @param {array} [$items] an array of items, each with "amount" key, and perhaps other data
	 * @param {boolean} [$throwIfNotEqual=false]
	 * @throws {Exception} If not equal
	 */
	static function checkAmount ($amount, $items, $throwIfNotEqual = false) {
		if (!is_array($items)) {
			return true;
		}
		$checkSum = 0;
		foreach ($items as $item) {
			$checkSum += $item['amount'];
		}

		if ($amount != $checkSum) {
			if ($throwIfNotEqual) {
				throw new Q_Exception_WrongValue(array(
					'field' => 'amount',
					'range' => $checkSum,
					'value' => $amount
				));
			}
			return false;
		}
		return true;
	}

	/**
	 * Grant credits to a user
	 * @method grant
	 * @static
	 * @param {string} $communityId The community managing the credits, pass null for Users::currentCommunity()
	 * @param {integer} $amount The amount of credits to grant.
	 * @param {string} $reason Identifies the reason for granting the credits. Can't be null.
	 * @param {string} [$userId=Users::loggedInUser()] User who is granted the credits. Defaults to logged in user.
	 * @param {array} [$attributes=array()] An array supplying attributes optional info, including
	 * @param {string} [$attributes.publisherId] The publisher of the stream representing the purchase
	 * @param {string} [$attributes.streamName] The name of the stream representing the purchase
	 * @param {string} [$attributes.fromUserId=Users::communityId()] Consider passing Users::currentCommunityId() here.
	 * @return {boolean} Whether the grant occurred
	 */
	static function grant($communityId, $amount, $reason, $userId = null, $attributes = array())
	{
		if (!$communityId) {
			$communityId = Users::communityId();
		}
		$amount = (int)$amount;
		if ($amount <= 0) {
			return false;
		}

		$attributes['amount'] = $amount;

		if (empty($reason)) {
			throw new Q_Exception_RequiredField(array('field' => 'reason'));
		}

		$userId = $userId ? $userId : Users::loggedInUser(true)->id;

		$stream = self::stream($communityId, $userId, $communityId);
		$stream->setAttribute('amount', $stream->getAttribute('amount') + $amount);
		$stream->changed($communityId);

		$fromUserId = Q::ifset($attributes, 'fromUserId', Users::communityId());

		$assets_credits = self::createRow($communityId, $amount, $reason, $userId, $fromUserId, $attributes);

		// Post that this user granted $amount credits by $reason
		$text = Q_Text::get('Assets/content');
		$utext = Q_Text::get('Users/content');
		$attributes['toUserName'] = $attributes['invitedUserName'] = Q::ifset($utext, 'avatar', 'Someone', 'Someone');
		if ($communityId === Users::communityId()) {
			$attributes['fromUserName'] = Users::communityName();
		} else {
			$attributes['fromUserName'] = 'Community'; // reason text usually won't interpolate this
		}
		$instructions = array_merge(array(
			'app' => Q::app(),
			'operation' => '+'
		), self::attributesSnapshot($assets_credits, $attributes));
		if ($reason == Assets::BOUGHT_CREDITS) {
			$type = 'Assets/credits/bought';
		} elseif ($reason == 'BonusCredits') {
			$type = 'Assets/credits/bonus';
		} else {
			$type = 'Assets/credits/granted';
			$instructions['reason'] = self::reasonToText($reason, $attributes);
		}

		$content = Q::ifset($text, 'messages', $type, "content", "Granted {{amount}} credits");
		$content = Q::interpolate($content, @compact('amount'));
		$stream->post($userId, compact('type', 'content', 'instructions'), true);

		// TODO: take commissions out of the grant and give to user who invited this user
		// $commission = Q_Config::expect("Assets", "credits", "commissions", "watching");

		return true;
	}

	/**
	 * Transfer credits, as the logged-in user, to another user
	 * @method transfer
	 * @static
	 * @param {string} $communityId The community managing the credits, pass null for Users::communityId()
	 * @param {integer} $amount The amount of credits to transfer.
	 * @param {string} $toUserId The id of the user to whom you will transfer the credits
	 * @param {string} $reason Identifies the reason for transfer. Can't be null.
	 * @param {string} [$fromUserId] By default, this is the logged-in user 
	 * @param {array} [$attributes] An array supplying more information
	 * @param {array} [$attributes.items] an array of items, each with "publisherId", "streamName" and "amount"
	 * @param {string} [$attributes.toPublisherId]  Stream publisher for which the payment is made
	 * @param {string} [$attributes.toStreamName]   Stream name for which the payment is made
	 * @param {string} [$attributes.fromPublisherId] Publisher of the consuming stream
	 * @param {string} [$attributes.fromStreamName]  Name of the consuming stream
	 * @return {float} Returns how much was ultimately transferred
	 */
	static function transfer($communityId, $amount, $reason, $toUserId, $fromUserId = null, $attributes = array())
	{
		if (!$communityId) {
			$communityId = Users::communityId();
		}

		$amount = floatval($amount);
		if ($amount < 0) {
			throw new Q_Exception_WrongType(array(
				'field' => 'amount',
				'type'  => 'positive number'
			));
		}

		if ($amount == 0 && empty($attributes['forceTransfer'])) {
			return 0;
		}

		if (empty($reason)) {
			throw new Q_Exception_RequiredField(array('field' => 'reason'));
		}

		$fromUserId = $fromUserId ? $fromUserId : Users::loggedInUser(true)->id;

		if ($toUserId === $fromUserId) {
			throw new Q_Exception_WrongValue(array(
				'field' => 'fromUserId',
				'range' => 'you can\'t transfer to yourself'
			));
		}

		if (!empty($attributes['items'])) {
			self::checkAmount($amount, $attributes['items'], true);
		}

		//--------------------------------------------------------------------
		// 1. Begin TX by locking payer stream
		//--------------------------------------------------------------------
		$from_stream = self::stream($communityId, $fromUserId, $communityId);
		$from_stream->retrieve('*', true, array(
			'begin' => true, // ONLY BEGIN HERE
			'rollbackIfMissing' => true
		));
		$currentCredits = floatval($from_stream->getAttribute('amount'));

		//--------------------------------------------------------------------
		// 2. Insufficient credits to auto top up
		//--------------------------------------------------------------------
		if ($currentCredits < $amount) {
			$from_stream->executeRollback();
			throw new Assets_Exception_NotEnoughCredits(array(
				'missing' => $amount - $currentCredits
			));
		}

		//--------------------------------------------------------------------
		// 3. Lock receiver stream (no second BEGIN)
		//--------------------------------------------------------------------
		$to_stream = self::stream($communityId, $toUserId, $communityId, true);
		$to_stream->retrieve('*', true, array(
			'rollbackIfMissing' => true   // no 'begin'
		));

		//--------------------------------------------------------------------
		// 4. Create ledger row (no COMMIT)
		//--------------------------------------------------------------------
		$attributes["amount"]          = $amount;
		$attributes["toUserId"]        = $toUserId;
		$attributes["fromStreamTitle"] = null;
		$attributes["toStreamTitle"]   = null;

		try {

			$assets_credits = self::createRow(
				$communityId,
				$amount,
				$reason,
				$toUserId,
				$fromUserId,
				$attributes
			);

			//----------------------------------------------------------------
			// 5. Deduct payer (no commit)
			//----------------------------------------------------------------
			$from_stream->setAttribute('amount', $currentCredits - $amount);
			$from_stream->save(false, false);

			//----------------------------------------------------------------
			// 6. Increase receiver (SAVE LAST)
			//    This save() will produce the final Db_Query, and THAT query
			//    should carry ->commit(), resolving the TX started earlier.
			//----------------------------------------------------------------
			$to_stream->setAttribute(
				'amount',
				$to_stream->getAttribute('amount') + $amount
			);

			// attach commit to the receiver's save()
			$to_stream->save(false, array(
				'commit' => true  // this will attach COMMIT to this query
			));

		} catch (Exception $e) {
			$from_stream->executeRollback();
			throw $e;
		}

		//--------------------------------------------------------------------
		// 7. Post-commit feeds
		//--------------------------------------------------------------------
		$text = Q_Text::get('Assets/content');
		$instructions = self::attributesSnapshot($assets_credits, $attributes);
		$instructions['app']    = Q::app();
		$instructions['reason'] = self::reasonToText($reason, $instructions);

		// Sender feed
		$instructions['operation'] = '-';
		$type = 'Assets/credits/sent';
		$content = Q::ifset($text, 'messages', $type, 'content', "Sent {{amount}} credits");

		if (empty($attributes['_suppressFeeds'])) {
			$from_stream->post($communityId, array(
				'type'         => $type,
				'content'      => Q::interpolate($content, $instructions),
				'instructions' => Q::json_encode($instructions, Q::JSON_FORCE_OBJECT)
			));
		}

		// Receiver feed
		$instructions['operation'] = '+';
		$type = 'Assets/credits/received';
		$content = Q::ifset($text, 'messages', $type, 'content', "Received {{amount}} credits");

		$to_stream->post($communityId, array(
			'type'         => $type,
			'content'      => Q::interpolate($content, $instructions),
			'instructions' => Q::json_encode($instructions, Q::JSON_FORCE_OBJECT)
		));

		return $amount;
	}

	/**
	 * Spend credits from the logged-in user on a value-producing stream.
	 * This supports itemized spending, and the standard credit accounting model.
	 *
	 * @method spend
	 * @static
	 * @param {string|null} $communityId Community managing these credits.
	 * @param {float} $amountCredits Amount of credits to spend.
	 * @param {string} $reason Semantic reason for the spend.
	 * @param {string} $fromUserId User spending the credits.
	 * @param {array} [$options] Extra metadata:
	 *     @param {string} [$options.payments="stripe"] Payment gateway key.
	 *     @param {string} [$options.toPublisherId]
	 *     @param {string} [$options.toStreamName]
	 *     @param {array}  [$options.items] Itemized spend: each {publisherId, streamName, amount}
	 *     @param {array}  [$options.metadata] Metadata passed to payment gateway.
	 *
	 * @throws Assets_Exception_NotEnoughCredits
	 * @return float Actual credits spent.
	 */
	static function spend($communityId, $amountCredits, $reason, $fromUserId, $options = array())
	{
		// Normalize community
		if (!$communityId) {
			$communityId = Users::communityId();
		}

		// Validate reason
		if (!$reason) {
			throw new Q_Exception_RequiredField(array("field" => "reason"));
		}

		// Validate items
		$items = isset($options["items"]) ? $options["items"] : null;
		if ($items) {
			self::checkAmount($amountCredits, $items, true);
		}

		/**
		 * Hook before payment of credits.
		 * @event Assets/credits/spend {before}
		 * @param {string} communityId
		 * @param {float} amountCredits
		 * @param {string} reason
		 * @param {string} fromUserId
		 * @param {array} options
		 */
		if (false === Q::event(
			'Assets/credits/spend',
			@compact('communityId', 'amountCredits', 'reason', 'fromUserId', 'options'),
			'before'
		)) {
			return false;
		}

		//--------------------------------------------------------------------
		// 1. Begin TX: lock only payer balance stream
		//--------------------------------------------------------------------
		$fromStream = Assets_Credits::stream($communityId, $fromUserId, $communityId);
		$fromStream->retrieve('*', true, array(
			'begin' => true,
			'rollbackIfMissing' => true
		));
		$currentCredits = floatval($fromStream->getAttribute("amount"));

		//--------------------------------------------------------------------
		// 2. Auto-top-up if insufficient credits
		//--------------------------------------------------------------------
		if ($currentCredits < $amountCredits) {
			$fromStream->executeRollback();
			throw new Assets_Exception_NotEnoughCredits(array(
				"missing" => $amountCredits - $currentCredits
			));
		}

		//--------------------------------------------------------------------
		// 3. Identify publisher receiving the credits
		//--------------------------------------------------------------------
		$toPublisherId = isset($options["toPublisherId"]) ? $options["toPublisherId"] : null;

		if (!$toPublisherId) {
			$fromStream->executeRollback();
			throw new Q_Exception_RequiredField(array(
				"field" => "options.toPublisherId"
			));
		}

		//--------------------------------------------------------------------
		// 4. Lock publisher stream (no begin)
		//--------------------------------------------------------------------
		$toStream = Assets_Credits::stream($communityId, $toPublisherId, $communityId, true);
		$toStream->retrieve('*', true, array(
			'rollbackIfMissing' => true
		));
		$publisherCredits = floatval($toStream->getAttribute("amount"));

		//--------------------------------------------------------------------
		// 5. Create ledger row (no commit)
		//--------------------------------------------------------------------
		$attributes = $options;
		$attributes["amount"]          = $amountCredits;
		$attributes["fromStreamTitle"] = null;
		$attributes["toStreamTitle"]   = null;

		foreach ($attributes as $k => $v) {
			if (is_object($v) or is_array($v)) {
				unset($attributes[$k]);
			}
		}

		try {

			$assets_credits = self::createRow(
				$communityId,
				$amountCredits,
				$reason,
				null,          // spend() has no toUserId
				$fromUserId,
				$attributes
			);

			//----------------------------------------------------------------
			// 6. Deduct payer (no commit)
			//----------------------------------------------------------------
			$fromStream->setAttribute("amount", $currentCredits - $amountCredits);
			$fromStream->save(false, false);

			//----------------------------------------------------------------
			// 7. Credit publisher (SAVE LAST to COMMIT HERE)
			//----------------------------------------------------------------
			$toStream->setAttribute("amount", $publisherCredits + $amountCredits);

			/**
			 * Hook after payment of credits.
			 * @event Assets/credits/spend {after}
			 * @param {string} communityId
			 * @param {float} amountCredits
			 * @param {string} reason
			 * @param {string} fromUserId
			 * @param {array} options
			 * @param {float} currentCredits
			 * @param {float} publisherCredits
			 * @param {float} amountCredits
			 */
			if (false === Q::event(
				'Assets/credits/spend',
				@compact(
					'communityId', 'amountCredits', 'reason', 'fromUserId', 'options',
					 'currentCredits', 'publisherCredits'
				),
				'after'
			)) {
				$fromStream->executeRollback();
				return false;
			}

			// COMMIT attached here (publisher)
			$toStream->save(false, array(
				'commit' => true
			));

		} catch (Exception $e) {

			$fromStream->executeRollback();
			throw $e;
		}


		//----------------------------------------------------------------
		// 8. Handle referral for spend action
		// This may be undone by a matching internal refund
		//----------------------------------------------------------------
		$referredAction = 'Assets/credits/spend';
		$extras = @compact('amountCredits', 'reason');
		Q::take($options, array(
			'payments', 'currency', 'toUserId', 'toPublisherId', 'toStreamName'
		), $extras);
		Users_Referred::handleReferral($fromUserId, $toPublisherId, $referredAction, $toStream->type, compact('extras'));


		//--------------------------------------------------------------------
		// 9. Post-commit text loading
		//--------------------------------------------------------------------
		if ($texts = Q_Config::get('Assets', 'credits', 'text', 'load', array())) {
			Q_Text::get($texts);
		}

		//--------------------------------------------------------------------
		// 10. Post-commit feed
		//--------------------------------------------------------------------
		$text = Q_Text::get("Assets/content");
		$instructions = self::attributesSnapshot($assets_credits, $attributes);
		$instructions["app"]       = Q::app();
		$instructions["reason"]    = self::reasonToText($reason, $instructions);
		$instructions["operation"] = "-";

		$type = "Assets/credits/spent";
		$content = Q::ifset($text, "messages", $type, "content", "Spent {{amount}} credits");

		$fromStream->post($communityId, array(
			"type"         => $type,
			"content"      => Q::interpolate($content, $instructions),
			"instructions" => Q::json_encode($instructions, Q::JSON_FORCE_OBJECT)
		));

		return $amountCredits;
	}

	/**
	 * Refund credits from one user to another
	 * @method refund
	 * @static
	 * @param {string|null} $communityId Community managing these credits.
	 * @param {float} $amountCredits Amount of credits to refund.
	 * @param {string} $reason Semantic reason for the refund.
	 * @param {string} $fromUserId User from whom the credits are taken.
	 * @param {string} $toUserId User to whom the credits are given.
	 * @param {array} [$attributes] Any additional options for transfer().
	 * @return float Actual credits refunded.
	 */
	public static function refund($communityId, $amountCredits, $reason, $fromUserId, $toUserId, $attributes = array())
	{
		// Normalize community
		if (!$communityId) {
			$communityId = Users::communityId();
		}

		if (!$reason)       throw new Q_Exception_RequiredField(array("field" => "reason"));
		if (!$fromUserId)   throw new Q_Exception_RequiredField(array("field" => "fromUserId"));
		if (!$toUserId)     throw new Q_Exception_RequiredField(array("field" => "toUserId"));

		//--------------------------------------------------------------
		// BEFORE HOOK
		//--------------------------------------------------------------
		if (false === Q::event(
			'Assets/credits/refund',
			@compact('communityId','amountCredits','reason','fromUserId','toUserId','attributes'),
			'before'
		)) {
			return false;
		}

		//--------------------------------------------------------------
		// IMPORTANT: Suppress transfer() feeds and autoCharge
		//--------------------------------------------------------------
		$attributes = array_merge($attributes, array(
			'forceTransfer' => true,     // ensure transfer runs even with 0 amount
			'autoCharge'    => false,    // never charge real money in refund
			'_suppressFeeds'=> true      // custom internal flag
		));

		//--------------------------------------------------------------
		// Execute atomic transfer using the working TX pattern
		//--------------------------------------------------------------
		$transferred = self::transfer(
			$communityId,
			$amountCredits,
			$reason,
			$toUserId,
			$fromUserId,
			$attributes
		);

		//--------------------------------------------------------------
		// AFTER HOOK (post-TX commit)
		//--------------------------------------------------------------
		Q::event(
			'Assets/credits/refund',
			@compact('communityId','amountCredits','reason','fromUserId','toUserId','attributes'),
			'after'
		);

		//--------------------------------------------------------------
		// Post-commit refund feed
		//--------------------------------------------------------------
		$text = Q_Text::get("Assets/content");

		$instructions = array(
			"app"       => Q::app(),
			"reason"    => self::reasonToText($reason, $attributes),
			"amount"    => $amountCredits,
			"operation" => "+"
		);

		$type    = "Assets/credits/refunded";
		$content = Q::ifset($text, "messages", $type, "content", "Refunded {{amount}} credits");

		$toStream = Assets_Credits::stream($communityId, $toUserId, $communityId, true);

		$toStream->post($communityId, array(
			"type"         => $type,
			"content"      => Q::interpolate($content, $instructions),
			"instructions" => Q::json_encode($instructions, Q::JSON_FORCE_OBJECT)
		));

		return $transferred;
	}
	
	/**
	 * Fill message instructions with needed attributes
	 * @method attributesSnapshot
	 * @static
	 * @param {Assets_Credits} $assetsCredits Assets credits row.
	 * @param {array} [$attributes=array()] Predefined instructions array.
	 * @return {Array}
	 */
	static function attributesSnapshot ($assetsCredits, $attributes = array()) {
		$attributes['creditsId'] = $assetsCredits->id;
		$attributes['toStreamTitle'] = $assetsCredits->getAttribute("toStreamTitle");
		$attributes['fromStreamTitle'] = $assetsCredits->getAttribute("fromStreamTitle");
		$attributes['toUserId'] = $assetsCredits->toUserId ? $assetsCredits->toUserId : $assetsCredits->getAttribute("toUserId");
		$attributes['fromUserId'] = $assetsCredits->fromUserId ? $assetsCredits->fromUserId : $assetsCredits->getAttribute("fromUserId");
		$attributes['invitedUserId'] = $assetsCredits->getAttribute("invitedUserId");
		$attributes['fromPublisherId'] = $assetsCredits->fromPublisherId;
		$attributes['fromStreamName'] = $assetsCredits->fromStreamName;
		$attributes['toPublisherId'] = $assetsCredits->toPublisherId;
		$attributes['toStreamName'] = $assetsCredits->toStreamName;
		if (empty($attributes['toUserName']) && !empty($attributes['toUserId'])) {
			$attributes['toUserName'] = Streams::displayName($attributes['toUserId']);
		}
		if (empty($attributes['fromUserName']) && !empty($attributes['fromUserId'])) {
			$attributes['fromUserName'] = Streams::displayName($attributes['fromUserId']);
		}
		if (empty($attributes['invitedUserName']) && !empty($attributes['invitedUserId'])) {
			$attributes['invitedUserName'] = Streams::displayName($attributes['invitedUserId']);
		}

		return $attributes;
	}

	/**
	 * Create row in Assets_Credits table
	 * @method createRow
	 * @static
	 * @param {string} $communityId The community managing the credits, pass null for Users::currentCommunity()
	 * @param {int} $amount Amount of credits. Required,
	 * @param {string} $reason Identifies the reason for the transfer. Required.
	 * @param {string} $toUserId User id who gets the credits.
	 * @param {string} $fromUserId User id who transfer the credits.
	 * @param {array} [$attributes] An array supplying more optional info, including things like
	 * @param {string} [$attributes.toPublisherId] The publisher of the value-producing stream for which the payment is made
	 * @param {string} [$attributes.toStreamName] The name of the stream value-producing for which the payment is made
	 * @param {string} [$attributes.fromPublisherId] The publisher of the value-consuming stream on whose behalf the payment is made
	 * @param {string} [$attributes.fromStreamName] The name of the value-consuming stream on whose behalf the payment is made
	 * @return {Assets_Credits} Assets_Credits row that was saved
	 */
	private static function createRow ($communityId, $amount, $reason, $toUserId = null, $fromUserId = null, $attributes = array())
	{
		if (!$communityId) {
			$communityId = Users::communityId();
		}
		$toPublisherId = null;
		$toStreamName = null;
		$fromPublisherId = null;
		$fromStreamName = null;
		if (Q::ifset($attributes, "toPublisherId", null)) {
			$toPublisherId = $attributes['toPublisherId'];
		}
		if (Q::ifset($attributes, "toStreamName", null)) {
			$toStreamName = $attributes['toStreamName'];
		}
		if (Q::ifset($attributes, "fromPublisherId", null)) {
			$fromPublisherId = $attributes['fromPublisherId'];
		}
		if (Q::ifset($attributes, "fromStreamName", null)) {
			$fromStreamName = $attributes['fromStreamName'];
		}

		unset($attributes['fromPublisherId']);
		unset($attributes['fromStreamName']);
		unset($attributes['toPublisherId']);
		unset($attributes['toStreamName']);

		if ($toPublisherId && $toStreamName) {
			$attributes['toStreamTitle'] = Streams_Stream::fetch($toPublisherId, $toPublisherId, $toStreamName)->title;
			$attributes['toUserId'] = $toPublisherId;
		}

		if ($fromPublisherId && $fromStreamName) {
			$attributes['fromStreamTitle'] = Streams_Stream::fetch($fromPublisherId, $fromPublisherId, $fromStreamName, true)->title;
			$attributes['fromUserId'] = $fromPublisherId;
		}

		// -----------------------------
		// Deterministic txId generation
		// -----------------------------

		$nonce = Q::ifset($attributes, 'nonce', null);

		if ($nonce === null) {
			// fallback if not provided (not ideal but safe-ish)
			$nonce = microtime(true);
		}

		$payload = json_encode(array(
			'fromUserId' => $fromUserId,
			'toUserId' => $toUserId ?: $toPublisherId,
			'amount' => $amount,
			'communityId' => $communityId,
			'nonce' => $nonce
		));

		// sha256 → hex → take first 10 chars
		$txId = substr(hash('sha256', $payload), 0, 16);

		// -----------------------------

		$assets_credits = new Assets_Credits();
		$assets_credits->id = Assets::db()->uniqueId(Assets_Credits::table(), 'id');
		$assets_credits->fromUserId = $fromUserId;
		$assets_credits->toUserId = $toUserId ? $toUserId : $toPublisherId;
		$assets_credits->toPublisherId = $toPublisherId;
		$assets_credits->toStreamName = $toStreamName;
		$assets_credits->fromPublisherId = $fromPublisherId;
		$assets_credits->fromStreamName = $fromStreamName;
		$assets_credits->reason = $reason;
		$assets_credits->communityId = $communityId;
		$assets_credits->amount = $amount;
		$assets_credits->setAttribute($attributes);
		$assets_credits->save(false, false);
		return $assets_credits;
	}
	/**
	 * Convert amount from one currency to another
	 * @method convert
	 * @static
	 * @param {number} $amount
	 * @param {string} [$fromCurrency="credits"]
	 * @param {string} [$toCurrency="credits"]
	 * @return {float}
	 */
	static function convert($amount, $fromCurrency=null, $toCurrency=null)
	{
		$amount = floatval($amount);
		if (!$fromCurrency) {
			$fromCurrency = 'credits';
		}
		if (!$toCurrency) {
			$toCurrency = 'credits';
		}
		if ($fromCurrency == $toCurrency) {
			return (float)$amount;
		} elseif ($fromCurrency == "credits") {
			$rate = Q_Config::expect('Assets', 'credits', 'exchange', $toCurrency);
			$amount = (float)$amount / $rate;
		} elseif ($toCurrency == "credits") {
			$rate = Q_Config::expect('Assets', 'credits', 'exchange', $fromCurrency);
			$amount = (float)$amount * $rate;
		} else {
			throw new Assets_Exception_Convert(compact('fromCurrency', 'toCurrency'));
		}

		return $amount;
	}
	/**
	 * Convert reason to readable text.
	 * @method reasonToText
	 * @static
	 * @param {string} $key json key to search in Assets/content/credits.
	 * @param {array} $attributes additional data needed to interpolate json with.
	 * @return {string}
	 */
	static function reasonToText($key, $attributes = array())
	{
		$texts = Q_Text::get('Assets/content');
		$text = Q::ifset($texts, 'credits', $key, null);

		if ($text && $attributes) {
			$text = Q::interpolate($text, $attributes);
		}

		return $text;
	}
	/**
	 * Get information about payments for stream
	 * @method getPaymentsInfo
	 * @static
	 * @param {string} $userId user tested paid stream
	 * @param {Streams_Stream|array} $toStream Stream or array('publisherId' => ..., 'streamName' => ...)
	 * @param {Streams_Stream|array} [$fromStream] Stream or array('publisherId' => ..., 'streamName' => ...)
	 * @throws
	 * @return {Boolean|Object}
	 */
	static function getPaymentsInfo($userId, $toStream, $fromStream = null)
	{
		$toPublisherId = Q::ifset($toStream, "publisherId", null);
		$toStreamName = Q::ifset($toStream, "streamName", Q::ifset($toStream, "name", null));
		if (!$toPublisherId || !$toStreamName) {
			throw new Exception('Assets_Credits::getPaymentsInfo: toStream invalid');
		}

		$fromPublisherId = Q::ifset($fromStream, "publisherId", null);
		$fromStreamName = Q::ifset($fromStream, "streamName", null);

        $latestLeftPaidStream = 0;
        $leftReason = 'LeftPaidStream';

        // find latest date user left paid stream
        $left_assets_credits = Assets_Credits::select()
            ->where(array(
                'toUserId' => $userId,
                'toPublisherId' => $fromPublisherId,
                'toStreamName' => $fromStreamName,
                'fromPublisherId' => $toPublisherId,
                'fromStreamName' => $toStreamName,
                'reason' => $leftReason
            ))
            ->ignoreCache()
            ->options(array("dontCache" => true))
            ->orderBy('insertedTime', false)
            ->limit(1)
            ->fetchDbRow();
        if ($left_assets_credits) {
            $latestLeftPaidStream = $left_assets_credits->insertedTime;
        }

		// find all credits transfer for paid stream latest than latest left stream
		$rows = Assets_Credits::select()
		->where(array(
			'fromUserId' => $userId,
			'toPublisherId' => $toPublisherId,
			'toStreamName' => $toStreamName,
			'fromPublisherId' => $fromPublisherId,
			'fromStreamName' => $fromStreamName,
			'reason !=' => $leftReason
		) + ($latestLeftPaidStream ? array('insertedTime >=' => $latestLeftPaidStream) : array()))
		->ignoreCache()
		->options(array("dontCache" => true))
		->orderBy('insertedTime', true)
		->fetchDbRows();

        $conclusion = array(
            "fromUserId" => $userId,
            "toUserId" => $toPublisherId,
            "toPublisherId" => $toPublisherId,
            "toStreamName" => $toStreamName,
            "fromPublisherId" => $fromPublisherId,
            "fromStreamName" => $fromStreamName,
            "amount" => 0,
            "fullyPaid" => false
        );

        if (!empty($rows)) {
            foreach ($rows as $row) {
                $conclusion['amount'] += $row->amount;
            }

            if ($conclusion["amount"] > 0) {
                $stream = Streams::fetchOne($toPublisherId, $toPublisherId, $toStreamName);
                $payment = $stream->getAttribute("payment");
                $discount = self::discountInfo($stream, $userId);
                $paymentCredits = self::convert(Q::ifset($payment, 'amount', 0), Q::ifset($payment, 'currency', 'credits'), 'credits');
                $totalCredits = $paymentCredits - $discount['credits'];
                if ($conclusion["amount"] >= $totalCredits) {
                    $conclusion["fullyPaid"] = true;
                }
            }
        }

        return compact("rows", "conclusion");
	}

	/**
	 * Award bonus credits to user if user buys a lot of credits at once
	 * @method award
	 * @static
	 * @param {string} $communityId The community issuing the credits, pass null for Users::currentCommunity()
	 * @param {string|number} $amount Amount of credits to pay bonus from
	 * @param {string} [$userId] User id to pay bonus. If empty - logged in user.
	 */
	static function awardBonus ($communityId, $amount, $userId=null) {
		if (!$communityId) {
			$communityId = Users::communityId();
		}
		$amount = (int)$amount;

		$userId = $userId ? $userId : Users::loggedInUser(true)->id;

		// thresholds in config should be in credits
		$bonuses = Q_Config::get("Assets", "credits", "bonus", "bought", null);
		if (!is_array($bonuses) || empty($bonuses)) {
			return;
		}

		krsort($bonuses, SORT_NUMERIC);
		foreach ($bonuses as $key => $bonus) {
			if ($amount >= $key) {
				self::grant($communityId, $bonus, "BonusCredits", $userId);
				return;
			}
		}
	}

	/**
	 * Compute the maximum credits-value specified in a payment attribute,
	 * such as "commissions" or "discounts", based on inviter labels or roles.
	 * Does not modify anything. Pure helper.
	 *
	 * @method maxAmountFromPaymentAttribute
	 * @static
	 * @param {Streams_Stream} stream The stream containing payment attributes
	 * @param {string} type Either "commissions" or "discounts"
	 * @param {double} needCredits The full credits-value of the purchase
	 * @param {string|null} currency Currency for converting "amount" entries.
	 *   If null, tries payment.currency. If still null, returns 0.
	 * @param {string|null} referrerUserId The inviter whose labels/roles determine rewards.
	 *
	 * @return {double} Maximum resulting credits; 0 if none match.
	 *
	 * Payment attribute schema (inside stream->attributes["payment"]):
	 *
	 *   {
	 *     "payment": {
	 *       "<type>": {                    // "discounts" or "commissions"
	 *         "inviter": {
	 *           "labels": {
	 *             "<labelName>": {
	 *               "credits":  <int>,     // fixed credits
	 *               "amount":   <float>,   // fixed currency amount (auto-converted to credits)
	 *               "fraction": <float>    // fraction of needCredits (e.g. 0.20 for 20%)
	 *             }
	 *           },
	 *           "participantRoles": {
	 *             "<roleName>": {
	 *               "credits":  <int>,
	 *               "amount":   <float>,
	 *               "fraction": <float>
	 *             }
	 *           }
	 *         }
	 *       }
	 *     }
	 *   }
	 *
	 * Matching rules:
	 *   - A rule applies if the inviter has the label or the participantRole.
	 *   - Multiple rules may match; only the maximum resulting credits is returned.
	 *
	 * Ordering:
	 *   1. credits
	 *   2. amount → converted to credits
	 *   3. fraction × needCredits
	 */
	static function maxAmountFromPaymentAttribute(
		$stream,
		$type,
		$needCredits,
		$currency = null,
		$referrerUserId = null
	) {
		$payment = $stream->getAttribute('payment', array());
		$section = Q::ifset($payment, $type, array());
		$inviter = Q::ifset($section, 'inviter', array());
		$labels = Q::ifset($inviter, 'labels', array());
		$proles = Q::ifset($inviter, 'participantRoles', array());
		if (!$currency) {
			$currency = Q::ifset($payment, 'currency', null);
			if (!$currency) {
				$currency = Assets::appCurrency();
			}
		}
		if (!$referrerUserId) {
			if ($token = Streams_Invite::tokenAcceptedInSession()) {
				if ($invite = Streams_Invite::fromToken($token)) {
					$referrerUserId = $invite->invitingUserId;
				}
			}
		}
		if (!$referrerUserId) {
			return 0;
		}
		$infos = array();

		//---------------------------------------------------------
		// 1. Label-based rules
		//---------------------------------------------------------
		if ($labels) {
			$contacts = Users_Contact::select()->where(array(
				'userId'=>$stream->publisherId,
				'label'=>array_keys($labels),
				'contactUserId'=>$referrerUserId
			))->fetchDbRows();

			foreach ($contacts as $contact) {
				$infos[] = $labels[$contact->label];
			}
		}

		//---------------------------------------------------------
		// 2. Participant-role rules
		//---------------------------------------------------------
		if ($proles) {
			$participant = new Streams_Participant(array(
				'publisherId'=>$stream->publisherId,
				'streamName'=>$stream->name,
				'userId'=>$referrerUserId
			));
			if ($participant->retrieve()) {
				foreach ($proles as $role=>$info) {
					if ($participant->testRoles(array($role))) {
						$infos[] = $info;
					}
				}
			}
		}

		//---------------------------------------------------------
		// 3. Compute max credits
		//---------------------------------------------------------
		$creditsMax = 0;
		foreach ($infos as $info) {
			if ($credits = Q::ifset($info, 'credits', null)) {
				// already in credits
			} else if ($amount = Q::ifset($info, 'amount', null)) {
				$credits = Assets_Credits::convert($amount, $currency, 'credits');
			} else if ($fraction = Q::ifset($info, 'fraction', null)) {
				$credits = $needCredits * $fraction;
			} else {
				continue;
			}
			$creditsMax = max($creditsMax, $credits);
		}

		return $creditsMax;
	}

	/**
	 * Computes discount info for a user invited to a stream.
	 * Does not modify UI; returns structured information.
	 *
	 * @method discountInfo
	 * @static
	 * @param {Streams_Stream} $stream The stream being evaluated
	 * @param {string} $userId The user for whom we compute the discount
	 * @param {string|null} [$currency] Optional currency override
	 * @return {array} Returns an associative array with:
	 *   - {integer} credits  The discount represented in credits
	 *   - {number}  amount   The discount in currency units
	 *   - {number}  fraction The percentage discount of the original price
	 */
	static function discountInfo($stream, $userId, $currency = null, $referrerUserId = null)
	{
		$payment = $stream->getAttribute('payment', array());
		$amount = Q::ifset($payment, 'amount', 0);
		if ($amount <= 0) {
			return array('credits'=>0,'amount'=>0,'fraction'=>0,'description'=>'');
		}

		$currency = $currency ?: Q::ifset($payment, 'currency', 'USD');
		$needCredits = self::convert($amount, $currency, 'credits');

		//---------------------------------------------------------
		// Compute discount credits
		//---------------------------------------------------------
		if (!$referrerUserId) {
			if ($token = Streams_Invite::tokenAcceptedInSession()) {
				if ($invite = Streams_Invite::fromToken($token)) {
					$referrerUserId = $invite->invitingUserId;
				}
			}
		}

		$discountCredits = Assets_Credits::maxAmountFromPaymentAttribute(
			$stream,
			'discounts',
			$needCredits,
			$currency,
			$referrerUserId
		);

		if ($discountCredits <= 0) {
			return array('credits'=>0,'amount'=>0,'fraction'=>0,'description'=>'');
		}

		$discountAmount = self::convert($discountCredits, 'credits', $currency);
		$fraction = $amount > 0 ? round($discountAmount / $amount, 2) : 0;


		//---------------------------------------------------------
		// Detect whether the matched rule used "fraction"
		//---------------------------------------------------------
		$payment = $stream->getAttribute('payment', array());
		$section = Q::ifset($payment, 'discounts', array());
		$inviter = Q::ifset($section, 'inviter', array());
		$labels  = Q::ifset($inviter, 'labels', array());
		$proles  = Q::ifset($inviter, 'participantRoles', array());

		$explicitFraction = false;
		foreach (array($labels, $proles) as $group) {
			foreach ($group as $info) {
				if (isset($info['fraction'])) {
					$explicitFraction = true;
					break 2;
				}
			}
		}


		//---------------------------------------------------------
		// Build localized description using Assets::format()
		//---------------------------------------------------------
		$text  = Q_Text::get('Assets/content');
		$dtext = Q::ifset($text, 'discounts', array());

		if ($explicitFraction) {
			$percent = round($fraction * 100);

			$description = Q::interpolate(
				Q::ifset($dtext, 'PercentDescription', ''),
				array('percent' => $percent . '%')
			);
		} else {
			// Use your currency formatter — short format gives e.g. "$25.00"
			$formatted = Assets::format($currency, $discountAmount, true);

			$description = Q::interpolate(
				Q::ifset($dtext, 'FormattedDescription', ''),
				array('format' => $formatted)
			);
		}

		return array(
			'credits'     => $discountCredits,
			'amount'      => $discountAmount,
			'fraction'    => $fraction,
			'description' => $description
		);
	}

	/**
	 * Does necessary preparations for saving the row in the database.
	 * @method beforeSave
	 * @param {array} $modifiedFields
	 *	The array of fields
	 * @param {array} $options
	 *  Not used at the moment
	 * @param {array} $internal
	 *  Can be used to pass pre-fetched objects
	 * @return {array}
	 * @throws {Exception}
	 *	If mandatory field is not set
	 */
	function beforeSave(
		$modifiedFields,
		$options = array(),
		$internal = array()
	) {
		if (empty($this->communityId)) {
			$this->communityId = Users::communityId();
		}
		return parent::beforeSave($modifiedFields);
	}
};