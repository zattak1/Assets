<?php
$_composerAutoload = ASSETS_PLUGIN_DIR.DS.'vendor'.DS.'autoload.php';
if (file_exists($_composerAutoload)) {
	require_once $_composerAutoload; // optional: plugin works without it
}

/**
 * @module Assets
 */
/**
 * Adapter implementing Stripe support for Assets_Payments functions
 *
 * @class Assets_Payments_Stripe
 * @implements Assets_Payments
 */

class Assets_Payments_Stripe extends Assets_Payments implements Assets_Payments_Interface
{

	public $options = array();

	/**
	 * @constructor
	 * @param {array} $options=array() Any initial options
	 * @param {string} $options.secret
	 * @param {string} $options.publishableKey
 	 * @param {Users_User} [$options.user=Users::loggedInUser()] Allows us to set the user to charge
	 */
	function __construct($options = array())
	{
		if (!isset($options['user'])) {
			$options['user'] = Users::loggedInUser(true);
		}
		$this->options = array_merge(array(
			'secret' => Q_Config::expect('Assets', 'payments', 'stripe', 'secret'),
			'publishableKey' => Q_Config::expect('Assets', 'payments', 'stripe', 'publishableKey'),
			'clientId' => Q_Config::get("Assets", "payments", "stripe", "clientId", null)
		), $options);
		\Stripe\Stripe::setApiKey($this->options['secret']);
	}
	
	/**
	 * Make a one-time charge using the payments processor
	 * @method charge
	 * @param {double} $amount specify the amount (optional cents after the decimal point)
	 * @param {string} [$currency='USD'] set the currency, which will affect the amount
	 * @param {array} [$options=array()] Any additional options
	 * @param {string} [$options.description=null] description of the charge, to be sent to customer
	 * @param {string} [$options.metadata=null] any additional metadata to store with the charge
	 * @param {string} [$options.subscription=null] if this charge is related to a subscription stream
	 * @param {string} [$options.subscription.publisherId]
	 * @param {string} [$options.subscription.streamName]
	 * @param {boolean} [$options.dontLogMissingCustomer] used internally
	 * @throws \Stripe\Exception\CardException
	 * @return {string} The customerId of the Assets_Customer that was successfully charged
	 */
	function charge($amount, $currency = 'USD', $options = array())
	{
		$options = array_merge($this->options, $options);
		Q_Valid::requireFields(array('user'), $options, true);
		$user = $options['user'];

		// get or create stripe customer
		$customer = new Assets_Customer();
		$customer->userId = $user->id;
		$customer->payments = 'stripe';
		$customer->hash = Assets_Customer::getHash();
		if (!$customer->retrieve() || !$customer->customerId) {
			$errorMessage = "No stripe customer id for userId=".$user->id;
			if (empty($options['dontLogMissingCustomer'])) {
				self::log('Stripe.charges', $errorMessage);
			}
			throw new Exception($errorMessage);
		}

		$params = array(
			"amount" => $amount * 100, // in cents
			"currency" => $currency,
			"customer" => $customer->customerId,
			"metadata" => !empty($options['metadata']) ? $options['metadata'] : null,
			"description" => Q::ifset($options, 'description', null),
			"off_session" => true,
			"confirm" => true,
		);

		$stripeClient = new \Stripe\StripeClient($this->options['secret']);
		$paymentMethods = $stripeClient->paymentMethods->all(['customer' => $customer->customerId, 'type' => 'card']);
		if (empty($paymentMethods->data)) {
			$err_mesage = "Offline payment methods not found for userId=".$user->id." with customerId=".$customer->customerId;
			self::log('Stripe.charges', $err_mesage);
			throw new Exception($err_mesage);
		}
		$params['payment_method'] = end($paymentMethods->data)->id;

		try {
			\Stripe\PaymentIntent::create($params);
		} catch (\Stripe\Exception\CardException $e) {
			// Error code will be authentication_required if authentication is needed
			$payment_intent_id = $e->getError()->payment_intent->id;
			$payment_intent = \Stripe\PaymentIntent::retrieve($payment_intent_id);

			$err_mesage = 'Failed charge for userId='.$user->id.' customerId='.$customer->customerId.' with error code:' . $e->getError()->code;
			self::log('Stripe.charges', $err_mesage, $payment_intent);
			throw new Exception($err_mesage);
		}

		return $customer->customerId;
	}

	/**
	 * Create stripe customer.
	 * Allow you to perform recurring charges, and to track multiple charges, that are associated with the same customer
	 * @method createCustomer
	 * @param {Users_User} $user
	 * @param {array} [$params] Additional params.
	 * @return {object} The customer object
	 */
	function createCustomer($user, $params = array())
	{
		$avatar = Streams_Avatar::fetch($user->id, $user->id);
		$params["name"] = $avatar->displayName();
		$params['email'] = $user->emailAddress ? $user->emailAddress : $user->emailAddressPending;
		$params['phone'] = $user->mobileNumber ? $user->mobileNumber : $user->mobileNumberPending;
		$params["metadata"] = array_merge(
		Q::ifset($params, 'metadata', array()),
			array(
				'userId' => $user->id,
				'app'    => Q::app()
			)
		);

		return \Stripe\Customer::create($params);
	}

	/**
	 * Retrieve stripe customer object.
	 * @method retrieveCustomer
	 * @param {String} $stripeCustomerId
	 * @return {object} The customer object
	 */
	function retrieveCustomer($stripeCustomerId)
	{
		$stripeClient = new \Stripe\StripeClient($this->options['secret']);
		$stripeService = new Stripe\Service\CustomerService($stripeClient);
		$data = $stripeService->retrieve($stripeCustomerId);
		return $data;
	}

	/**
	 * Create a payment intent
	 * @method createPaymentIntent
	 * @param {double} $amount specify the amount (optional cents after the decimal point)
	 * @param {string} [$currency='USD'] set the currency, which will affect the amount
	 * @param {array} [$options=array()] Any additional options
	 * @param {string} [$options.metadata=null] any additional metadata to store with the charge
	 * @throws \Stripe\Exception\CardException
	 * @return object
	 */
	function createPaymentIntent($amount, $currency = 'USD', $options = array())
	{
		$options = array_merge($this->options, $options);

		$options['metadata'] = Q::ifset($options, 'metadata', array());
		$options['metadata']['userId'] = $options['user']->id;

		$amount = $amount * 100; // in cents

		// get or create stripe customer
		$customer = new Assets_Customer();
		$customer->userId = $options['user']->id;
		$customer->payments = 'stripe';
		$customer->hash = Assets_Customer::getHash();
		if (!$customer->retrieve()) {
			$stripeCustomer = self::createCustomer($options['user']);
			$customer->customerId = $stripeCustomer->id;
			$customer->save();
		}

		Q_Valid::requireFields(array('user'), $options, true);
		$params = array(
			'customer' => $customer->customerId,
			'setup_future_usage' => 'off_session',
			'automatic_payment_methods' => array('enabled' => true),
			'amount' => $amount,
			'currency' => $currency,
			'metadata' => !empty($options['metadata']) ? $options['metadata'] : null
		);
		if (!empty($options['capture_method'])) {
			$params['capture_method'] = $options['capture_method'];
		}

		$intent = \Stripe\PaymentIntent::create($params); // can throw some exception

		return $intent;
	}

	/**
	 * Create a SetupIntent (store a payment method for future off_session use)
	 * @method createSetupIntent
	 * @param {array} [$options=array()] Additional options
	 * @param {string} [$options.metadata] Optional metadata
	 * @throws \Stripe\Exception\CardException
	 * @return object  The SetupIntent object
	 */
	function createSetupIntent($options = array())
	{
		$options = array_merge($this->options, $options);

		// Ensure metadata exists and attach userId
		$options['metadata'] = Q::ifset($options, 'metadata', array());
		$options['metadata']['userId'] = $options['user']->id;

		// Get or create Stripe customer
		$customer = new Assets_Customer();
		$customer->userId  = $options['user']->id;
		$customer->payments = 'stripe';
		$customer->hash     = Assets_Customer::getHash();

		if (!$customer->retrieve()) {
			$stripeCustomer = self::createCustomer($options['user']);
			$customer->customerId = $stripeCustomer->id;
			$customer->save();
		}

		Q_Valid::requireFields(array('user'), $options, true);

		$params = array(
			'customer' => $customer->customerId,
			'usage'    => 'off_session',
			'metadata' => !empty($options['metadata']) ? $options['metadata'] : null
		);

		// Create SetupIntent
		$intent = \Stripe\SetupIntent::create($params);

		return $intent;
	}

	/**
	 * Create connected account for user
	 * @method createConnectedAccount
	 * @param {array} [$options=array()] Any additional options
	 */
	function createConnectedAccount ($options = array()) {
		$options = array_merge($this->options, $options);
		$uri = Q_Dispatcher::uri();
		$method = Q::ifset($uri, 'method', null);

		$stripe = new \Stripe\StripeClient($options['secret']);
		$account = self::getConnectedAccount($options);
		if ($account) {
			if (self::connectedAccountReady($account)) {
				return Q::view("Assets/content/connectedAccountCreated.php", compact("method"));
			}
		} else {
			$params = [
				'type' => 'standard'
			];
			if ($options['user']->emailAddress) {
				$params['email'] = $options['user']->emailAddress;
			}
			$account = $stripe->accounts->create($params);
			self::setConnectedAccount($account->id);
		}

		$accountLink = $stripe->accountLinks->create([
			'account' => $account->id,
			'refresh_url' => Q_Uri::url("Assets/connected payments=stripe method=refresh"),
			'return_url' => Q_Uri::url("Assets/connected payments=stripe method=return"),
			'type' => 'account_onboarding',
		]);

		header("Location: ".$accountLink->url);
		return true;
	}

	/**
	 * Fetch successful Stripe charges that should be honored.
	 * No DB writes. No hooks. No side effects.
	*
	 * @method fetchSuccessfulCharges
	 * @param {array} $options
	 * @param {string|null} [$options.customerId]
	 * @param {integer} [$options.limit=100]
	 * @return {array}
	 */
	function fetchSuccessfulCharges($options = array())
	{
		$customerId = Q::ifset($options, 'customerId', null);
		if (!$customerId) {
			// No customer yet → nothing to reconcile
			return array();
		}

		$stripe = new \Stripe\StripeClient($this->options['secret']);
		$result = array();

		$intents = $stripe->paymentIntents->all(array(
			'customer' => $customerId,
			'limit'    => Q::ifset($options, 'limit', 100)
		));

		foreach ($intents->data as $intent) {

			if ($intent->status !== 'succeeded') {
				continue;
			}
			if (empty($intent->amount_received)) {
				continue;
			}

			try {
				$metadata = self::resolveMetadata(
					$intent->metadata->toArray(),
					(object)['data' => (object)['object' => $intent]],
					'payment_intent.succeeded'
				);
			} catch (Exception $e) {
				continue;
			}

			$result[] = array(
				'chargeId'   => $intent->id,
				'customerId' => $intent->customer,
				'userId'     => $metadata['userId'],
				'amount'     => $intent->amount_received / 100,
				'currency'   => strtoupper($intent->currency),
				'metadata'   => $metadata
			);
		}

		return $result;
	}

	/**
	 * Fetch refunded Stripe charges that should cancel or suppress intents.
	 * No DB writes. No hooks. No side effects.
	 *
	 * @method fetchRefundedCharges
	 * @param {array} $options
	 * @param {string|null} [$options.customerId]
	 * @param {integer} [$options.limit=100]
	 * @return {array}
	 */
	function fetchRefundedCharges($options = array())
	{
		$customerId = Q::ifset($options, 'customerId', null);
		if (!$customerId) {
			return array();
		}

		$stripe = new \Stripe\StripeClient($this->options['secret']);
		$result = array();

		// Refunds are attached to charges; charges may belong to payment intents
		$refunds = $stripe->refunds->all(array(
			'limit' => Q::ifset($options, 'limit', 100)
		));

		foreach ($refunds->data as $refund) {

			if ($refund->status !== 'succeeded') {
				continue;
			}

			$chargeId = $refund->charge;
			if (!$chargeId) {
				continue;
			}

			// Retrieve the charge to resolve intent + metadata
			try {
				$charge = $stripe->charges->retrieve($chargeId);
			} catch (Exception $e) {
				continue;
			}

			// Skip unrelated customers
			if ($charge->customer !== $customerId) {
				continue;
			}

			$intent = null;
			if (!empty($charge->payment_intent)) {
				try {
					$intent = $stripe->paymentIntents->retrieve($charge->payment_intent);
				} catch (Exception $e) {}
			}

			// Resolve metadata (prefer intent, fallback to charge)
			try {
				$metadata = self::resolveMetadata(
					($intent && $intent->metadata)
						? $intent->metadata->toArray()
						: ($charge->metadata ? $charge->metadata->toArray() : array()),
					(object)['data' => (object)['object' => ($intent ?: $charge)]],
					'charge.refunded'
				);
			} catch (Exception $e) {
				continue;
			}

			$result[] = array(
				'chargeId'   => $chargeId,
				'refundId'   => $refund->id,
				'customerId' => $charge->customer,
				'userId'     => Q::ifset($metadata, 'userId', null),
				'amount'     => $refund->amount / 100,
				'currency'   => strtoupper($refund->currency),
				'metadata'   => $metadata
			);
		}

		return $result;
	}

	/**
	 * Check if connected account ready to use
	 * @method connectedAccountReady
	 * @param {array} [$account]
	 * @return string
	 */
	function connectedAccountReady ($account=null) {
		if (!$account) {
			$account = self::getConnectedAccount();
		}

		return Q::ifset($account, 'details_submitted', false);
	}

	/**
	 * Get connected account for user
	 * @method getConnectedAccount
	 * @param {array} [$options=array()] Any additional options
	 * @return string
	 */
	function getConnectedAccount ($options = array()) {
		if (is_array($options)) {
			$options = array_merge($this->options, $options);
		}

		$connectedAccount = new Assets_Connected();
		$connectedAccount->userId = $this->options['user']->id;
		$connectedAccount->payments = 'stripe';
		if ($connectedAccount->retrieve()) {
			$stripe = new \Stripe\StripeClient($this->options['secret']);
			return $account = $stripe->accounts->retrieve($connectedAccount->accountId, []);
		}

		return null;
	}

	/**
	 * Set connected account for user
	 * @method setConnectedAccount
	 * @param {string} $accountId
	 */
	function setConnectedAccount ($accountId) {
		$connectedAccount = new Assets_Connected();
		$connectedAccount->userId = $this->options['user']->id;
		$connectedAccount->payments = 'stripe';
		$connectedAccount->accountId = $accountId;
		$connectedAccount->save(true);
	}

	/**
	 * Delete connected account for user
	 * @method deleteConnectedAccount
	 * @param {array} [$options=array()] Any additional options
	 * @return string
	 */
	function deleteConnectedAccount ($options = array()) {
		$options = array_merge($this->options, $options);

		$accountId = self::getConnectedAccount($this->options);
		if (!$accountId) {
			throw new Exception("You have no connected account");
		}

		$stripe = new \Stripe\StripeClient($this->options['secret']);
		$stripe->accounts->delete($accountId, []);

		$connectedAccount = new Assets_Connected();
		$connectedAccount->userId = $this->options['user']->id;
		$connectedAccount->payments = 'stripe';
		if ($connectedAccount->retrieve()) {
			$connectedAccount->remove();
		}

		return null;
	}

	/**
	 * Resolve metadata for Stripe events in a fault-tolerant way.
	 * Ensures that the resulting metadata contains userId, user, chargeId, and customerId.
	 * @method resolveMetadata
	 * @static
	 * @param {array} metadata Existing metadata extracted from Stripe objects.
	 * @param {Object} event The Stripe event passed by the webhook handler.
	 * @param {string} eventType Stripe event type, such as "payment_intent.succeeded" or "invoice.paid".
	 * @throws {Exception} Thrown if userId or chargeId cannot be reliably determined.
	 * @return {array} Normalized metadata including userId, user, chargeId, and customerId.
	 */
	static function resolveMetadata($metadata, $event, $eventType)
	{
		$stripe = new \Stripe\StripeClient(
			Q_Config::expect('Assets', 'payments', 'stripe', 'secret')
		);

		$object = $event->data->object;

		// -------------------------------------------------------------
		// 1. Resolve chargeId and customerId depending on event type
		// -------------------------------------------------------------

		if ($eventType === "payment_intent.succeeded") {
			$metadata["chargeId"]   = Q::ifset($object, "id", null);
			$metadata["customerId"] = Q::ifset($object, "customer", null);
		} else if ($eventType === "invoice.paid") {
			$pi = Q::ifset($object, "payment_intent", null);
			if ($pi) {
				$metadata["chargeId"] = $pi;
			}
			$metadata["customerId"] = Q::ifset($object, "customer", null);
		}

		// -------------------------------------------------------------
		// 2. Resolve userId using the deterministic priority order:
		// -------------------------------------------------------------
		// Priority:
		//   1. direct metadata.userId
		//   2. subscription.metadata.userId
		//   3. customer.metadata.userId
		//   4. invoice line metadata.userId
		// -------------------------------------------------------------

		// #1 direct metadata
		if (!empty($metadata["userId"])) {
			// already resolved
		} else {

			// #2 subscription metadata (only invoice.paid)
			if ($eventType === "invoice.paid" && !empty($object->subscription)) {
				try {
					$subscription = $stripe->subscriptions->retrieve($object->subscription);
					if (!empty($subscription->metadata->userId)) {
						$metadata["userId"] = $subscription->metadata->userId;
					}
				} catch (Exception $e) {
					// ignore; fall through to next step
				}
			}

			// #3 customer metadata
			if (empty($metadata["userId"]) && !empty($metadata["customerId"])) {
				try {
					$customer = $stripe->customers->retrieve($metadata["customerId"]);
					if (!empty($customer->metadata->userId)) {
						$metadata["userId"] = $customer->metadata->userId;
					}
				} catch (Exception $e) {
					// ignore; fall through
				}
			}

			// #4 invoice line item metadata
			if (
				empty($metadata["userId"]) &&
				$eventType === "invoice.paid" &&
				isset($object->lines->data[0]->metadata->userId)
			) {
				$metadata["userId"] = $object->lines->data[0]->metadata->userId;
			}
		}

		// Final validation: userId must exist
		if (empty($metadata["userId"])) {
			throw new Exception("Unable to resolve userId for $eventType");
		}

		// -------------------------------------------------------------
		// 3. Attach user object
		// -------------------------------------------------------------
		// $metadata["user"] = Users::fetch($metadata["userId"], true);

		// -------------------------------------------------------------
		// 4. chargeId must be present
		// -------------------------------------------------------------
		if (empty($metadata["chargeId"])) {
			throw new Exception("Unable to resolve chargeId for $eventType");
		}

		return $metadata;
	}

	/**
	 * Validate Stripe webhook (lightweight, no side effects)
	 *
	 * @method validateWebhook
	 * @param {mixed} $event Parsed Stripe\Event
	 * @param {array} &$context Mutable context
	 * @throws Exception
	 */
	function validateWebhook($event, array &$context)
	{
		if (!$event || empty($event->id) || empty($event->type)) {
			throw new Exception("Invalid Stripe webhook payload");
		}

		// Optional replay / idempotency hooks can live here later
		// Signature verification already happened in parseWebhook()
	}

	/**
	 * Normalize Stripe webhook into canonical domain update
	 *
	 * @method normalizeWebhook
	 * @param {mixed} $event Stripe\Event
	 * @param {array} &$context
	 * @return {array|null} Canonical update or null if ignored
	 */
	function normalizeWebhook($event, array &$context)
	{
		$type = $event->type;

		// We only care about successful money movement
		if (
			$type !== 'payment_intent.succeeded' &&
			$type !== 'invoice.paid'
		) {
			return null;
		}

		$object = $event->data->object;

		$metadata = self::resolveMetadata(
			(array) Q::ifset($object, 'metadata', array()),
			$event,
			$type
		);

		$amountCents = Q::ifset(
			$object,
			'amount_received',
			Q::ifset($object, 'amount_paid', null)
		);

		if (!$amountCents) {
			return null;
		}

		return array(
			// -----------------------------
			// Canonical update descriptor
			// -----------------------------
			'type' => 'paymentSucceeded',

			// -----------------------------
			// Provenance
			// -----------------------------
			'payments'  => 'stripe',
			'updateId'  => $event->id,
			'sourceType'=> $type,

			// -----------------------------
			// Idempotency + ownership
			// -----------------------------
			'chargeId'   => $metadata['chargeId'],
			'customerId' => Q::ifset($metadata, 'customerId', null),
			'userId'     => $metadata['userId'],

			// -----------------------------
			// Value
			// -----------------------------
			'amount'   => $amountCents / 100,
			'currency' => strtoupper($object->currency),

			// -----------------------------
			// Attachments
			// -----------------------------
			'metadata' => $metadata,
			'raw'      => $event
		);
	}

	static function log ($title, $message=null) {
		Q::log(date('Y-m-d H:i:s').': '.$title, 'stripe');
		if ($message instanceof Throwable) {
			// Q::log renders exception objects without message, location
			// or trace; render them explicitly so webhook catches are
			// diagnosable from the log.
			$message = get_class($message).': '.$message->getMessage()
				. PHP_EOL . '  at ' . $message->getFile() . ':' . $message->getLine()
				. PHP_EOL . $message->getTraceAsString();
		}
		if ($message) {
			Q::log($message, 'stripe', array(
				"maxLength" => 10000
			));
		}
	}
}