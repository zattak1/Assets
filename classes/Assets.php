<?php
/**
 * Assets model
 * @module Assets
 * @main Assets
 */

$ASSETS_CURRENCY_LOCALES = array(
	'en_US' => array(
		'decimal' => '.',
		'thousands' => ',',
		'symbol_before' => true,
		'space' => false
	),
	'en_GB' => array(
		'decimal' => '.',
		'thousands' => ',',
		'symbol_before' => true,
		'space' => false
	),
	'fr_FR' => array(
		'decimal' => ',',
		'thousands' => ' ',
		'symbol_before' => false,
		'space' => true
	),
	'de_DE' => array(
		'decimal' => ',',
		'thousands' => '.',
		'symbol_before' => false,
		'space' => true
	),
	'ja_JP' => array(
		'decimal' => '',
		'thousands' => ',',
		'symbol_before' => true,
		'space' => false,
		'zero_decimals' => true
	),
	'ar_AE' => array(
		'decimal' => '.',
		'thousands' => ',',
		'symbol_before' => false,
		'space' => true
	)
);


/**
 * Static methods for the Assets models.
 * @class Assets
 * @extends Base_Assets
 */

abstract class Assets extends Base_Assets
{
	/**
	 * Returns the first currency under Assets/credits/exchange that's not "credits"
	 * @method appCurrency
	 * @static
	 * @return {string} Defaults to USD
	 */
	static function appCurrency()
	{
		// Exchange table: e.g. {"credits":1, "usd":100, "eur":90}
		$exchange = Q_Config::get('Assets', 'credits', 'exchange', array());
		$realCurrency = null;
		// Pick first non-credits currency
		foreach ($exchange as $code => $ratio) {
			$codeUpper = strtoupper($code);
			if ($codeUpper !== 'CREDITS') {
				$realCurrency = $codeUpper;
				break;
			}
		}
		// Fallback only if config has no real currencies
		return $realCurrency ? $realCurrency : 'USD';
	}
	
	/**
	 * Get the official currency name (e.g. "US Dollar") and symbol (e.g. $)
	 * @method currency
	 * @static
	 * @param {string} $code The three-letter currency code
	 * @return {array} Returns an array of ($currencyName, $symbol)
	 * @throws Q_Exception_BadValue
	 */
	static function currency($code)
	{
		$config = Q_Config::get('Assets', 'currencies', array());
        $json = Q::readFile(ASSETS_PLUGIN_CONFIG_DIR.DS.'currencies.json',
		Q::take($config, array(
			'ignoreCache' => false,
			'dontCache' => false,
			'duration' => 3600
		)));
		$code = strtoupper($code);
		$currencies = Q::json_decode($json, true);
		if (!Q::ifset($currencies, 'symbols', $code, null)) {
			throw new Q_Exception_BadValue(array(
				'internal' => 'currency', 
				'problem' => "no symbol found for $code"
			), 'currency');
		}
		if (!Q::ifset($currencies, 'names', $code, null)) {
			throw new Q_Exception_BadValue(array(
				'internal' => 'currency', 
				'problem' => "no name found for $code"
			), 'currency');
		}
		$symbol = $currencies['symbols'][$code];
		$currencyName = $currencies['names'][$code];
		return array($currencyName, $symbol);
	}
	
	/**
	 * Format using official currency name, e.g. 200.34 USD
	 * @method display
	 * @static
	 * @param {string} $code The three-letter currency code
	 * @param {double} $amount The amount of money in that currency
	 * @param {boolean} $short Whether to use short format (symbol only)
	 * @param {string} $locale The locale to use for formatting
	 * @return {string} The display, in the current locale
	 */
	static function format($code, $amount, $short, $locale = null)
	{
		global $ASSETS_CURRENCY_LOCALES;

		$code = strtoupper($code);

		// Normalize $short (avoid null)
		if (!$short) {
			$short = false;
		}

		// Determine locale
		if (!$locale) {
			$locale = Q_Request::languageLocale();
		}
		if (!$locale) {
			$locale = 'en_US';
		}

		// Get currency name & symbol from the JSON file
		list($currencyName, $symbol) = self::currency($code);

		// -----------------------------------------------------
		// 1. Try INTL NumberFormatter (PHP 5.4 capability)
		// -----------------------------------------------------
		if (class_exists('NumberFormatter')) {
			$fmt = new NumberFormatter($locale, NumberFormatter::CURRENCY);
			$out = $fmt->formatCurrency($amount, $code);

			if ($out !== false) {

				// SHORT MODE: return only symbol+amount (ICU handles placement)
				if ($short) {
					return $out;  // e.g. "$30.00", "30,00 €"
				}

				// LONG MODE: amount + ISO code
				return $out . " " . $code;  // e.g. "30.00 USD"
			}
		}

		// -----------------------------------------------------
		// 2. Fallback: manual locale formatter (PHP 5.2-compatible)
		// -----------------------------------------------------

		// Ensure locale exists
		if (!Q::ifset($ASSETS_CURRENCY_LOCALES, $locale, null)) {
			$locale = 'en_US';
		}

		$fmt = $ASSETS_CURRENCY_LOCALES[$locale];

		// For French, override thousands separator: OMIT IT
		if ($locale === 'fr_FR') {
			$fmt['thousands'] = '';   // ← this fixes your French formatting
		}

		// Determine number of decimals
		$digits = 2;
		if (Q::ifset($fmt, 'zero_decimals', null)) {
			$digits = 0;
		}

		// Format number using localized decimal & thousands rules
		$formatted = number_format(
			$amount,
			$digits,
			$fmt['decimal'],
			$fmt['thousands']
		);

		// Place symbol in the correct position
		if ($fmt['symbol_before']) {
			$out = $symbol;
			if ($fmt['space']) {
				$out .= ' ';
			}
			$out .= $formatted;
		} else {
			$out = $formatted;
			if ($fmt['space']) {
				$out .= ' ';
			}
			$out .= $symbol;
		}

		// SHORT MODE: symbol-only output
		if ($short) {
			return $out;  // e.g. "$30.00", "30,00 €", "¥5000"
		}

		// LONG MODE: append ISO code
		return $formatted . " " . $code;
	}

	/**
	 * Unified credits payment engine.
	 * Handles credit spending, transfers, optional auto top-ups, and itemized pays.
	 * Exception-safe. Always check its returned array.
	 *
	 * @method pay
	 * @static
	 * @param {string|null} $communityId
	 * @param {string} $userId The user who is paying
	 * @param {number} $amount Amount in the original currency
	 * @param {string} $reason The reason for the payment
	 * @param {array} [$options]
	 * @param {string} [$options.payments="stripe"]
	 * @param {string} [$options.currency="USD"]
	 * @param {string} [$options.toUserId] the user getting paid, defaults to publisherId if that one is set
	 * @param {string} [$options.toPublisherId] publisherId of the stream to pay for
	 * @param {string} [$options.toStreamName] name of the stream to pay for
	 * @param {string} [$options.autoCharge] set to true to attempt to automatically charge missing amount
	 * @param {array|false} [$options.subscribe] Options to pass to subscribe() method
	 *   on successful payment. Pass false here to skip subscribing.
	 * @return array ("success" => bool, "details" => array)
	 */
	static function pay($communityId, $userId, $amount, $reason, $options = array())
	{
		// Hook {before}
		if (false === Q::event(
			'Assets/pay',
			@compact('communityId', 'userId', 'amount', 'reason', 'options'),
			'before'
		)) {
			return array("success" => false, "details" => array("error" => "rejected-by-hook"));
		}

		$communityId = $communityId ? $communityId : Users::communityId();

		$currency = Q::ifset($options, 'currency', 'USD');
		$payments  = Q::ifset($options, 'payments', 'stripe');
		$autoCharge    = Q::ifset($options, 'autoCharge', false);

		$toPublisherId = Q::ifset($options, 'toPublisherId', null);
		$toStreamName  = Q::ifset($options, 'toStreamName', null);
		$toUserId        = Q::ifset($options, 'toUserId', 0);
		if (!$toUserId and $toPublisherId) {
			$toUserId = $toPublisherId;
		}

		// if (empty($options['skipHonoringOutstandingSuccessfulCharges'])) {
		// 	try {
		// 		Assets::honorOutstandingSuccessfulCharges(
		// 			$payments,
		// 			$userId,
		// 			array('user' => Users_User::fetch($userId, true))
		// 		);
		// 	} catch (Exception $e) {
		// 		// keep going, this shouldn't block it
		// 	}
		// }

		$fromPublisherId = Q::ifset($options, "fromPublisherId", null);
		$fromStreamName  = Q::ifset($options, "fromStreamName", null);

		$items = Q::ifset($options, "items", null);
		if (!empty($items)) {
			foreach ($items as $k => $item) {
				$options['items'][$k]['amount'] = Assets_Credits::convert($options['items'][$k]['amount'], $currency, "credits");
			}
		}

		if (!$reason) {
			return array(
				"success" => false,
				"details" => array("error" => "Missing reason")
			);
		}

		// Backstop for the check in the Assets/pay HTTP handler, so any future
		// route into pay() inherits it. Returns structured rather than throwing,
		// because this method's contract is that it never throws.
		if (!Assets_Credits::isValidReason($reason)) {
			return array(
				"success" => false,
				"details" => array("error" => "Undeclared reason: $reason")
			);
		}

		//-------------------------------------------------------------
		// 1. Convert original currency to credits
		//-------------------------------------------------------------
		$needCredits = Assets_Credits::convert($amount, $currency, "credits");
		$haveCredits = Assets_Credits::amount($communityId, $userId);

		// -------------------------------------------------------------
		// 2. Apply inviter-based discounts (if any)
		// -------------------------------------------------------------
		$stream = null;
		if ($toPublisherId && $toStreamName) {
			try {
				$stream = Streams_Stream::fetch($toPublisherId, $toPublisherId, $toStreamName, '*', array(
					'skipAccess' => true
				));

				// Determine inviter (referrer) if present
				$referrerUserId = null;

				// 1. If an invite was followed in this request:
				if ($token = Streams_Invite::tokenAcceptedInSession()) {
					if ($invite = Streams_Invite::fromToken($token)) {
						$referrerUserId = $invite->invitingUserId;
					}
				}

				// 2. Or if a referral is stored on the participant record (common case)
				if (!$referrerUserId && $stream) {
					$participant = new Streams_Participant(array(
						'publisherId' => $stream->publisherId,
						'streamName'  => $stream->name,
						'userId'      => $userId
					));
					if ($participant->retrieve()) {
						$referrerUserId = $participant->extra('invitingUserId');
					}
				}

				$discountCredits = Assets_Credits::maxAmountFromPaymentAttribute(
					$stream,
					'discounts',
					$needCredits,
					$currency,
					$referrerUserId
				);

				if ($discountCredits > 0) {
					$needCredits = max(0, $needCredits - $discountCredits);
				}

			} catch (Exception $e) {
				// Fail-safe: ignore discounts, never break payment flow
			}
		}

		//-------------------------------------------------------------
		// 3. Attempt to initiate charge if insufficient credits
		//-------------------------------------------------------------
		if ($haveCredits < $needCredits) {

			$missingCredits = $needCredits - $haveCredits;
			$amountCurrency = Assets_Credits::convert($missingCredits, "credits", $currency);

			$metadata = Q::ifset($options, "metadata", array());

			$instructions = array(
				"userId"        => $userId,
				"communityId"   => $communityId,
				"credits"       => $missingCredits,
				"currency"      => $currency,
				"amount"        => $amountCurrency,
				"reason"        => $reason,
				"payments"      => $payments,
				"toPublisherId" => $toPublisherId,
				"toStreamName"  => $toStreamName,
				"toUserId"      => $toUserId,
				"needCredits"   => $needCredits
			);
			if ($fromPublisherId and $fromStreamName) {
				$instructions = array_merge($instructions, compact(
					'fromPublisherId', 'fromStreamName'
				));
			}

			$intent = Users_Intent::newIntent("Assets/charge", $userId, $instructions);

			if (!$autoCharge) {
				// Not allowed to charge automatically: return intent token
				// as well as intent object so the client knows what to do.
				return array(
					"success" => false,
					"details" => array(
						"haveCredits" => $haveCredits,
						"needCredits" => $needCredits,
						"intentToken" => $intent->token,
						'intent' => $intent->exportArray()
					)
				);
			}

			try {
				Assets::autoCharge(
					$missingCredits,
					self::BOUGHT_CREDITS,
					array(
						"userId"   => $userId,
						"currency" => "credits",
						"payments" => $payments,
						"metadata" => $metadata,
						"intentToken" => $intent->token,
						"dontLogMissingCustomer" => true
						// no need for full intent object,
						// because this will result in a direct request
						// to the payments processor,
						// and our webhook can later fetch the intent from the database.
					)
				);
				// if charge is successful, Stripe will continue with intent
			} catch (Exception $e) {
				// No throw — always return structured.
				// Return intent token as well as the
				// intent object, so client knows what to do.
				return array(
					"success" => false,
					"details" => array(
						"haveCredits" => $haveCredits,
						"needCredits" => $needCredits,
						"error"       => $e->getMessage(),
						"intentToken" => $intent->token,
						"intent" => 	$intent->exportArray()
					)
				);
			}
		}

		//-------------------------------------------------------------
		// 4. Prepare opts for spend/transfer
		//-------------------------------------------------------------
		$opts = array_merge($options, array(
			"amount"   => $needCredits,
			"payments" => $payments
		));

		//-------------------------------------------------------------
		// 5. Optional subscription
		//-------------------------------------------------------------
		if ($stream) {
			try {
				$sub = Q::ifset($options, 'subscribe', array());
				$sub['userId'] = $userId;
				if ($sub !== false) {
					$stream->subscribe($sub);
				}
			} catch (Exception $e) {
				return array("success" => false, "details" => array("error" => $e->getMessage()));
			}
		}

		//-------------------------------------------------------------
		// 6. Spend or Transfer inside try/catch
		//-------------------------------------------------------------
		try {
			if ($stream) {
				Assets_Credits::spend($communityId, $needCredits, $reason, $userId, $opts);
			} else if ($toUserId) {
				Assets_Credits::transfer($communityId, $needCredits, $reason, $toUserId, $userId, $opts);
			} else {
				return array(
					"success" => false,
					"details" => array("error" => "No valid payment target")
				);
			}
		} catch (Exception $e) {
			// This is the big change — NEVER THROW
			return array(
				"success" => false,
				"details" => array("error" => $e->getMessage())
			);
		}

		//-------------------------------------------------------------
		// 7. After hook
		//-------------------------------------------------------------
		Q::event(
			'Assets/pay',
			@compact('communityId', 'userId', 'amount', 'reason', 'options', 'stream', 'needCredits', 'haveCredits'),
			'after'
		);

		//-------------------------------------------------------------
		// 8. Success. Break out the expensive bottle of champagne
		//-------------------------------------------------------------
		return array(
			"success" => true,
			"details" => array(
				"haveCredits" => $haveCredits,
				"needCredits" => $needCredits
			)
		);
	}

	/**
	 * Makes a one-time charge on a customer account using a payments processor
	 * @method charge
	 * @static
	 * @param {string} $payments The type of payments processor, could be "Authnet" or "Stripe"
	 * @param {string} $amount specify the amount
	 * @param {string} [$currency="USD"] set the currency, which will affect the amount
	 * @param {array} [$options=array()] Any additional options
	 * @param {Users_User} [$options.user=Users::loggedInUser()] Which user to charge
	 * @param {string} [$options.communityId] Which community's credits to grant on success
	 * @param {Streams_Stream} [$options.stream=null] Related Assets/product, service or subscription stream
	 * @param {string} [$options.reason] Business reason or semantic label.
	 * @param {string} [$options.description=null] Description for the customer
	 * @param {string} [$options.metadata=null] Additional metadata to store with the charge
	*  @param {boolean} [$options.dontLogMissingCustomer] used internally
	 * @param {boolean} 
	 * @throws \Stripe\Error\Card
	 * @throws Assets_Exception_DuplicateTransaction
	 * @throws Assets_Exception_HeldForReview
	 * @throws Assets_Exception_ChargeFailed
	 * @return {boolean} Whether a charge was initiated.
	 */
	static function charge($payments, $amount, $currency = 'USD', $options = array())
	{
		if (Q_Config::get('Assets', 'charges', 'simulate', 'failed', false)) {
			throw new Assets_Exception_ChargeFailed();
		}
		if (!$currency) {
			$currency = 'USD';
		}
		$currency = strtoupper($currency);
		$credits = Assets_Credits::convert($amount, $currency, 'credits');
		$user        = Q::ifset($options, 'user', Users::loggedInUser(false));
		$communityId = Q::ifset($options, 'communityId', Users::communityId());
		$className = 'Assets_Payments_' . ucfirst($payments);
		$baseMetadata = array(
			"userId"      => $user ? $user->id : null,
			"communityId" => $communityId,
			"currency"    => $currency,
			"amount"      => $amount,
			"credits"     => $credits,
			"reason"      => Q::ifset($options, "reason", null),
			"app"         => Q::app(),
			"autoCharge"  => Q::ifset($options, "autoCharge", false) ? 1 : 0
		);
		$mergedMeta = array_merge(
			$baseMetadata,
			Q::ifset($options, 'metadata', array())
		);
		$options['metadata'] = $mergedMeta;
		$adapter = null;
		/**
		 * Hook before a Assets/charge is about to be made.
		 * Handlers can return false to cancel it.
		 * @event Assets/charge {before}
		 * @param {string} communityId
		 * @param {float} amountCredits
		 * @param {string} reason
		 * @param {string} fromUserId
		 * @param {array} options
		 */
		if (false === Q::event(
			'Assets/charge',
			@compact(
				'adapter',
				'options',
				'payments',
				'amount',
				'currency'
			),
			'before'
		)) {
			return false;
		}
		// instantiate adapter after before-hook runs
		// and actually charge stripe
		$adapter = new $className((array)$options);
		$customerId = $adapter->charge($amount, $currency, $options);  // Stripe call
		/**
		 * Hook after a Assets/charge has been made successfully.
		 * @event Assets/charge {after}
		 * @param {string} communityId
		 * @param {float} amountCredits
		 * @param {string} reason
		 * @param {string} fromUserId
		 * @param {array} options
		 */
		Q::event(
			'Assets/charge',
			@compact(
				'payments',
				'amount',
				'currency',
				'user',
				'communityId',
				'options',
				'customerId',
				'adapter'
			),
			'after'
		);
		return true; // the rest will be done by the webhook!!
	}

	/**
	 * Records a successful one charge on a customer account using a payments processor
	 * @method charged
	 * @static
	 * @param {string} $payments The type of payments processor, could be "Authnet" or "Stripe"
	 * @param {string} $amount specify the amount
	 * @param {string} [$currency="USD"] set the currency, which will affect the amount
	 * @param {array} [$options=array()] Any additional options
	 * @param {string} [$options.chargeId] Payment id to set as id field of Assets_Charge table.
	 *  If this is defined it means payment already processed (for example from webhook)
	 *  and hence no need to call $adapter->charge
	 * @param {string} [$options.userId] Or you can simply pass userId here
	 * @param {string} [$options.communityId] Which community's credits to grant on success
	 * @param {Streams_Stream} [$options.stream=null] Related Assets/product, service or subscription stream
	 * @param {string} [$options.reason] Business reason or semantic label.
	 * @param {string} [$options.description=null] Description for the customer
	 * @param {string} [$options.metadata=null] Additional metadata to store with the charge
	 * @param {boolean} [$options.skipNotifications] Skip sending notifications
	 * @param {boolean} [$options.skipAllSideEffects] Skip all side effects, including sending notifications
	 * @throws \Stripe\Error\Card
	 * @throws Assets_Exception_DuplicateTransaction
	 * @throws Assets_Exception_HeldForReview
	 * @throws Assets_Exception_ChargeFailed
	 * @return {Assets_Charge} The saved database row for the charge
	 */
	static function charged($payments, $amount, $currency = 'USD', $options = array())
	{
		if (!$currency) {
			$currency = 'USD';
		}
		$currency = strtoupper($currency);
		$credits = Assets_Credits::convert($amount, $currency, 'credits');
		$userId      = Q::ifset($options, 'userId', null);
		$communityId = Q::ifset($options, 'communityId', Users::communityId());
		$chargeId    = Q::ifset($options, 'chargeId', null);
		$baseMetadata = array(
			"userId"      => $userId,
			"communityId" => $communityId,
			"currency"    => $currency,
			"amount"      => $amount,
			"credits"     => $credits,
			"reason"      => Q::ifset($options, "reason", null),
			"app"         => Q::app(),
			"autoCharge"  => Q::ifset($options, "autoCharge", false) ? "1" : "0"
		);
		$mergedMeta = array_merge(
			$baseMetadata,
			Q::ifset($options, 'metadata', array())
		);
		$options['metadata'] = $mergedMeta;
		$adapter = null;
		$customerId = Q::ifset($options, 'customerId', null);
		$charge = new Assets_Charge();
		$charge->userId = $userId;
		$charge->id = $chargeId;
		if (!$charge->retrieve()) {
			// only do this once per charge -- idempotent

			/**
			 * Hook before a charge is about to be saved.
			 * Handlers shouldn't return false unless they totally override this default method.
			 * @event Assets/charged {before}
			 */
			if (false === Q::event(
				'Assets/charged',
				@compact(
					'adapter',
					'options',
					'payments',
					'amount',
					'currency',
					'chargeId',
					'userId'
				),
				'before'
			)) {
				return false;
			}

			$charge->description = self::BOUGHT_CREDITS;
			if (!empty($options['reason'])) {
				$charge->description .= ": ".$options['reason'];
			}
			$charge->publisherId = Q::ifset($options, 'metadata', 'toPublisherId', Q::ifset(
				$options, 'metadata', 'publisherId', ''
			));
			$charge->streamName = Q::ifset($options, 'metadata', 'toStreamName', Q::ifset(
				$options, 'metadata', 'streamName', ''
			));
			$charge->status = 'completed';
			$attributes = array(
				'payments'    => $payments,
				'customerId'  => $customerId,
				'amount'      => sprintf('%0.2f', $amount),
				'currency'    => $currency,
				'communityId' => $communityId,
				'credits'     => $credits,
				'metadata'    => $mergedMeta
			);

			$charge->attributes = Q::json_encode($attributes);
			$charge->communityId = $communityId;
			$charge->save();

			// Handle referral, if any
			$referredAction = 'Assets/credits/charge';
			$extras = compact('amount', 'currency', 'credits');
			Q::take($options, array(
				'payments', 'amount', 'currency', 'toUserId', 'toPublisherId', 'toStreamName'
			), $extras);
			Users_Referred::handleReferral($userId, $communityId, $referredAction, $stream->type, compact('extras'));

			/**
			 * Hook after a Assets/charge has been made successfully and recorded.
			 * This is where handlers should send notifications, etc. to users!
			 * @event Assets/charged {after}
			 */
			Q::event(
				'Assets/charged',
				@compact(
					'payments',
					'amount',
					'currency',
					'communityId',
					'chargeId',
					'userId',
					'charge',
					'options'
				),
				'after'
			);
		}

		return $charge;
	}

	/**
	 * Honor successful external charges that were already finalized
	 * at the payment provider but not yet recorded locally.
	 *
	 * This method:
	 *  - Resolves provider customer context (if available)
	 *  - Optionally fetches refunded charges first and suppresses them
	 *  - Fetches provider-confirmed successful charges
	 *  - Ensures idempotency by skipping already-recorded charge IDs
	 *  - Cancels matching Users_Intent records for refunded charges
	 *  - Emits canonical Assets/update/paymentSucceeded events
	 *
	 * Safe to call repeatedly.
	 *
	 * @method honorOutstandingSuccessfulCharges
	 * @static
	 * @param {string} $payments
	 *  The payments adapter key (e.g. "stripe", "authnet", "web3").
	 * @param {string} $userId
	 *  UserId to scope reconciliation (unless options[user] provided).
	 * @param {array} [$options]
	 *  Adapter- and execution-specific options.
	 * @param {boolean} [$options.dryRun=false]
	 *  If true, do not emit events or perform side effects.
	 * @param {boolean} [$options.skipRefundedCharges=false]
	 *  If true, refunded charges are ignored and no intent cancellation occurs.
	 * @param {Users_User} [$options.user]
	 *  Explicit user object to avoid fetching by userId.
	 * @param {string} [$options.customerId]
	 *  Explicit provider customer id to scope provider queries.
	 * @param {integer} [$options.limit]
	 *  Maximum number of provider charges to inspect.
	 * @return {array}
	 *  Array describing charges that were (or would be) honored.
	 * @throws Q_Exception_WrongType
	 * @throws Q_Exception_MissingRow
	 */
	static function honorOutstandingSuccessfulCharges($payments, $userId, $options = array())
	{
		$className = 'Assets_Payments_' . ucfirst($payments);
		if (!class_exists($className)) {
			throw new Exception("Payments adapter not found: $className");
		}

		$results   = array();
		$dryRun    = !empty($options['dryRun']);
		$skipRefunds = !empty($options['skipRefundedCharges']);

		// -------------------------------------------------
		// Before hook
		// -------------------------------------------------
		if (false === Q::event(
			'Assets/honorCharges',
			@compact('payments', 'options'),
			'before'
		)) {
			return $results;
		}

		// -------------------------------------------------
		// Resolve user
		// -------------------------------------------------
		if (Q::ifset($options, 'user', null)) {
			$user = $options['user'];
			if (!($user instanceof Users_User)) {
				throw new Q_Exception_WrongType(array(
					'field' => 'options[user]',
					'type'  => 'Users_User'
				));
			}
		} else {
			$user = Users_User::fetch($userId);
			if (!$user) {
				throw new Q_Exception_MissingRow(array(
					'table'    => 'users_user',
					'criteria' => "userId = $userId"
				));
			}
		}
		$options['user'] = $user;

		// -------------------------------------------------
		// Resolve customerId once
		// -------------------------------------------------
		if (empty($options['customerId'])) {
			$customer = new Assets_Customer();
			$customer->userId   = $user->id;
			$customer->payments = $payments;
			$customer->hash     = Assets_Customer::getHash();
			if ($customer->retrieve()) {
				$options['customerId'] = $customer->customerId;
			}
		}

		$adapter = new $className((array)$options);

		// -------------------------------------------------
		// Fetch refunded charges first (unless skipped)
		// -------------------------------------------------
		$refunded = array();

		if (!$skipRefunds && method_exists($adapter, 'fetchRefundedCharges')) {
			foreach ($adapter->fetchRefundedCharges($options) as $r) {
				if (!empty($r['chargeId'])) {
					$refunded[$r['chargeId']] = true;
				}
			}
		}

		// -------------------------------------------------
		// Fetch successful charges
		// -------------------------------------------------
		$charges = $adapter->fetchSuccessfulCharges($options);

		foreach ($charges as $c) {

			$chargeId = Q::ifset($c, 'chargeId', null);
			if (!$chargeId) {
				continue;
			}

			// ---------------------------------------------
			// Skip refunded charges + cancel intent
			// ---------------------------------------------
			if (!$skipRefunds && Q::ifset($refunded, $chargeId, null)) {

				$intentToken = Q::ifset($c, 'metadata', 'intentToken', null);
				if ($intentToken) {
					$intent = new Users_Intent(array('token' => $intentToken));
					if ($intent->retrieve()) {
						$intent->cancel(array('reason' => 'refunded'));
					}
				}
				continue;
			}

			// ---------------------------------------------
			// Idempotency: skip if already recorded
			// ---------------------------------------------
			$existing = new Assets_Charge();
			$existing->userId = $userId;
			$existing->id = $chargeId;
			if ($existing->retrieve()) {
				continue;
			}

			// ---------------------------------------------
			// Skip invalid users
			// ---------------------------------------------
			if (empty($c['userId']) || !Users_User::fetch($c['userId'])) {
				continue;
			}

			$results[] = array(
				'payments' => $payments,
				'amount'   => $c['amount'],
				'currency' => $c['currency'],
				'chargeId' => $chargeId
			);

			if ($dryRun) {
				continue;
			}

			// ---------------------------------------------
			// Emit canonical domain event
			// ---------------------------------------------
			Q::event(
				'Assets/update/paymentSucceeded',
				array_merge(
					array('payments' => $payments),
					$c
				)
			);
		}

		// -------------------------------------------------
		// After hook
		// -------------------------------------------------
		Q::event(
			'Assets/honorCharges',
			@compact('payments', 'options'),
			'after'
		);

		return $results;
	}

	/**
	 * Returns a list of streams the user can pay for
	 * @method canPayForStreams
	 * @static
	 * @param {Streams_Stream} $stream Must inherit from streams the user can pay for
	 * @return {array} Array of array(publisherId, streamName) arrays
	 */
	static function canPayForStreams(Streams_Stream $stream)
	{
		$types = Q_Config::get('Assets', 'canPayForStreams', 'types', array());
		if (!$types || !$stream->inheritAccess) {
			return array();
		}
		$ia = Q::json_decode($stream->inheritAccess, true);
		$result = array();
		foreach ($ia as $pn) {
			list($publisherId, $streamName) = $pn;
			foreach ($types as $type) {
				if (Q::startsWith($streamName, $type.'/')) {
					$result[] = compact("publisherId", "streamName");
				}
			}
		}
		return $result;
	}

	/**
	 * Force a real-money payment on behalf of the logged-in user,
	 * after the client has explicitly authorized it (e.g. via popup + SetupIntent).
	 *
	 * This bypasses the credits system entirely. The server validates:
	 *   (1) workflow legitimacy,
	 *   (2) quota limits,
	 *   (3) payment gateway success.
	 *
	 * @method autoCharge
	 * @static
	 * @param {float}  $amount  Amount in the given currency.
	 * @param {string} $reason  Business reason or semantic label.
	 * @param {array}  [$options] Optional arguments:
	 *     @param {string} [$options.currency="credits"] Currency code ("credits", "USD", "EUR", etc.).
	 *     @param {string} [$options.payments="stripe"] Payment processor key.
	 *     @param {string} [$options.userId] User performing the payment.
	 *     @param {string} [$options.intentToken] You can pass the token of a Users_Intent for continuations
	 *     @param {string} [$options.resourceId=""] Quota resource bucket.
	 *     @param {string} [$options.quotaName="autoCharge"] Quota name.
	 *     @param {int}    [$options.units] Explicit quota units, otherwise auto.
	 *     @param {array}  [$options.metadata] Arbitrary metadata.
	 *     @param {boolean} [$options.dontLogMissingCustomer] used internally
	 *
	 * @throws Users_Exception_Quota
	 * @throws Exception
	 * @return bool
	 */
	static function autoCharge($amount, $reason, $options = array())
	{
		// Validate amount
		$amount = floatval($amount);
		if ($amount < 0) {
			throw new Q_Exception_WrongType(array(
				'field' => 'amount',
				'type'  => 'positive number'
			));
		}

		// Resolve requested currency (default: credits)
		$currency = strtoupper(Q::ifset($options, 'currency', 'credits'));

		// Determine user
		$userId = Q::ifset($options, 'userId', Users::loggedInUser(true)->id);

		// Determine payment processor
		$payments = Q::ifset($options, 'payments', 'stripe');

		// If caller passed currency="credits", convert to first real currency
		if ($currency === 'CREDITS') {
			$realCurrency = Assets::appCurrency();

			// Convert credits -> realCurrency
			$amount = Assets_Credits::convert($amount, 'credits', $realCurrency);
			$currency = $realCurrency;
		}

		// At this point, $amount is a real money amount in $currency.

		// Quota parameters
		$quotaName  = Q::ifset($options, 'quotaName',  'Assets/autoCharge');
		$resourceId = Q::ifset($options, 'resourceId', '');

		// Quota units: if not provided, convert real currency -> USD (only as a universal quota baseline)
		$units = Q::ifset($options, 'units', null);
		if ($units === null) {
			// Convert to USD for quota consistency
			$amountUSD = Assets_Credits::convert($amount, $currency, 'USD');

			// Units is ceiling of USD amount
			$units = ceil($amountUSD);
		}

		// 1. Check and reserve quota (starts transaction)
		$quota = Users_Quota::check(
			$userId,
			$resourceId,
			$quotaName,
			true,			// throw if quota exceeded
			$units,
			array(),
			true
		);

		$user = Users::fetch($userId, true);
		$metadata = Q::ifset($options, 'metadata', array());
		Q::take($options, array('intentToken'), $metadata);

		// 2. Attempt real-money charge
		try {
			$dontLogMissingCustomer = Q::ifset($options, 'dontLogMissingCustomer', true);
			$result = Assets::charge($payments, $amount, $currency, compact(
				'user', 'reason', 'metadata', 'dontLogMissingCustomer'
			));

			// Commit quota usage
			$quota->used($units);
			return true;
		} catch (Exception $e) {
			// Roll back quota reservation
			Users_Quota::rollback()->execute(false, Q::ifset($quota, 'shards', array()));
			throw $e;
		}
	}

	static $columns = array();
	static $options = array();
	const PAYMENT_TO_USER = 'PaymentToUser';
	const JOINED_PAID_STREAM = 'JoinedPaidStream';
	const LEFT_PAID_STREAM = 'LeftPaidStream';
	const CREATED_COMMUNITY = 'CreatedCommunity';
    const BOUGHT_CREDITS = 'BoughtCredits';
};