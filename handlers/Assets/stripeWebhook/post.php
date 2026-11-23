<?php

require_once ASSETS_PLUGIN_DIR . DS . 'vendor' . DS . 'autoload.php';

/**
 * Unified handler for completed Stripe charges.
 * Performs:
 *  1) Assets::charge()
 *  2) If metadata contains intentToken to continue pending Assets::pay()
 *
 * Idempotency is preserved because we inject $metadata['chargeId']
 * using Stripe’s unique charge.id or payment_intent.id fallback.
 */
function Assets_handleStripeSuccessfulCharge($amount, $currency, $metadata, $event)
{
	try {
		// -------------------------------------------------------------
		// Ensure idempotency: chargeId must exist if coming from webhook
		// -------------------------------------------------------------
		$chargeId = Q::ifset($metadata, 'chargeId', null);
		if (!$chargeId) {
			// fallback — extract unique identifier from Stripe event
			if ($event->type === 'payment_intent.succeeded') {
				$pi = $event->data->object;
				$chargeObj = Q::ifset($pi, 'charges', 'data', 0, null);
				$chargeId  = Q::ifset($chargeObj, 'id', $pi->id);   // fallback to PI id
			}
			else if ($event->type === 'invoice.paid') {
				$invoice = $event->data->object;
				$chargeId = $invoice->id;
			}
			else if ($event->type === 'setup_intent.succeeded') {
				// setup_intent does not affect charges; leave unset
			}

			if ($chargeId) {
				$metadata['chargeId'] = $chargeId;
			}
		}

		// -------------------------------------------------------------
		// Record a normal credit purchase charge (idempotent because chargeId exists)
		// -------------------------------------------------------------
		$charge = Assets::charged("stripe", $amount, $currency, $metadata);

		// -------------------------------------------------------------
		// Check for Users_Intent continuation (pending Assets::pay)
		// -------------------------------------------------------------
		$shouldContinue = (
			!empty($metadata['intentToken'])
			&& (!isset($metadata['autoCharge']) || $metadata['autoCharge'] !== "1")
		);

        if ($shouldContinue) {
			$intent = new Users_Intent(array('token' => $metadata['intentToken']));
			$intentFound = $intent->retrieve() && $intent->isValid();
			// The intent spends its own userId's credits: only that user's
			// payment may continue it (ro#1067). resolveMetadata() already
			// tied the Stripe customer to $metadata['userId'].
			if ($intentFound
			&& $intent->getInstruction('userId', null) !== Q::ifset($metadata, 'userId', null)) {
				Assets_Payments_Stripe::log('stripe', 'Refusing to continue intent: it belongs to userId '
					. var_export($intent->getInstruction('userId', null), true)
					. ' but the payment is from userId ' . var_export(Q::ifset($metadata, 'userId', null), true));
				$intentFound = false;
			}
			if ($intentFound) {

				$instructions = $intent->getAllInstructions();

				// Record on the charge what it was paid for, now, while the
				// intent exists: an Assets/charge intent expires in minutes
				// and is then garbage-collected, but a refund can come days
				// later and must find the booking to cancel (ro#1246).
				if ($charge instanceof Assets_Charge
				and !empty($instructions['toPublisherId']) and !empty($instructions['toStreamName'])
				and !$charge->streamName) {
					try {
						$charge->publisherId = $instructions['toPublisherId'];
						$charge->streamName = $instructions['toStreamName'];
						$charge->save();
					} catch (Exception $e) {
						Assets_Payments_Stripe::log('stripe', 'Could not record the charge target', $e->getMessage());
					}
				}

				// get amount of credits to transfer
				// fromPublisherId/fromStreamName: the stream paid for on the
				// payer's behalf (a pet at an event). Assets::pay() puts them
				// in the intent; dropping them here recorded the payment as
				// the payer's own, so getPaymentsInfo() for that stream never
				// saw it and it was charged again (ro#1039).
				$options = Q::take($instructions, array(
					'currency', 'payments',
					'toPublisherId', 'toStreamName', 'toUserId', 'metadata',
					'fromPublisherId', 'fromStreamName'
				));
				$options['autoCharge'] = false;
				if ($needCredits = $intent->getInstruction('needCredits', 0)) {
					$options['currency'] = 'credits';
				}

				$spentCredits = 0;
				if ($needCredits) {
					// Mirror Assets::pay: spend() requires a stream target;
					// user-target payments (e.g. donations) must transfer().
					if (!empty($options['toPublisherId']) and !empty($options['toStreamName'])) {
						$spentCredits = Assets_Credits::spend(
							$instructions['communityId'],
							$needCredits,
							$instructions['reason'],
							$instructions['userId'],
							$options
						);
					} else if (!empty($instructions['toUserId'])) {
						$spentCredits = Assets_Credits::transfer(
							$instructions['communityId'],
							$needCredits,
							$instructions['reason'],
							$instructions['toUserId'],
							$instructions['userId'],
							$options
						);
					}
				}
				$success = (!$needCredits or $spentCredits > 0);

				// complete the intent, then take actions
				$intent->complete(array(
					'success' => $success,
					'spentCredits' => $spentCredits
				));

				Assets_Payments_Stripe::log(
					"stripe",
					"Intent payment completed (webhook)",
					array("instructions" => $instructions, "success" => $success)
				);
			}
		}

	} catch (Exception $e) {
		Assets_Payments_Stripe::log(
			"stripe",
			"Unified handler error",
			$e
		);
	}
}



/**
 * Stripe webhook — immediately ACK Stripe, then process in background.
 */
function Assets_stripeWebhook_post($params = array())
{
	$payload         = @file_get_contents('php://input');
	$endpoint_secret = Q_Config::expect("Assets", "payments", "stripe", "webhookSecret");

	// -------------------------------------------------------------
	// Parse event
	// -------------------------------------------------------------
	try {
		$event = \Stripe\Event::constructFrom(json_decode($payload, true));
	} catch (Exception $e) {
		Assets_Payments_Stripe::log('stripe', 'Webhook parse error', $e);
		http_response_code(400);
		exit;
	}

	// -------------------------------------------------------------
	// Validate signature
	// -------------------------------------------------------------
	try {
		$sig = Q::ifset($_SERVER, 'HTTP_STRIPE_SIGNATURE', '');
		$event = \Stripe\Webhook::constructEvent($payload, $sig, $endpoint_secret);
	} catch (Exception $e) {
		Assets_Payments_Stripe::log('stripe', 'Webhook signature error', $e);
		http_response_code(400);
		exit;
	}

	// -------------------------------------------------------------
	// Respond immediately
	// -------------------------------------------------------------
	http_response_code(200);
	header("Content-Length: 0");
	header("Connection: close");

	@ob_end_flush();
	flush();
	if (function_exists('fastcgi_finish_request')) {
		fastcgi_finish_request();
	}

	// -------------------------------------------------------------
	// Background processing
	// -------------------------------------------------------------
	switch ($event->type) {

		// ---------------------------------------------------------
		// PAYMENT INTENT SUCCEEDED
		// ---------------------------------------------------------
		case 'payment_intent.succeeded':
			try {
				$pi       = $event->data->object;
				$amount   = (int)(Q::ifset($pi, 'amount', 0)) / 100;
				$currency = Q::ifset($pi, 'currency',  null);

				$metadata = _stripe_meta(Q::ifset($pi, 'metadata', null));

				// App check FIRST, on the raw metadata: resolveMetadata
				// throws on events with no userId, which is exactly the
				// foreign-event case this check exists to skip quietly.
				if (Q::ifset($metadata, 'app', null) !== Q::app()) {
					Assets_Payments_Stripe::log('stripe', 'PI succeeded but wrong app');
					break;
				}

				$metadata = Assets_Payments_Stripe::resolveMetadata(
					$metadata, $event, 'payment_intent.succeeded'
				);

				// Inject chargeId for idempotency
				$chargeObj          = Q::ifset($pi, 'charges', 'data', 0, null);
				$metadata['chargeId'] = Q::ifset($chargeObj, 'id', $pi->id);

				Assets_handleStripeSuccessfulCharge($amount, $currency, $metadata, $event);

			} catch (Exception $e) {
				Assets_Payments_Stripe::log('stripe', 'Error in payment_intent.succeeded', $e);
			}
			break;

		// ---------------------------------------------------------
		// INVOICE PAID (Subscriptions)
		// ---------------------------------------------------------
		case 'invoice.paid':
			try {
				$invoice  = $event->data->object;
				$amount   = (int)Q::ifset($invoice, 'amount_paid', 0) / 100;
				$currency = Q::ifset($invoice, 'currency', null);

				$lineMeta = null;
				if (isset($invoice->lines->data[0]->metadata)) {
					$lineMeta = $invoice->lines->data[0]->metadata;
				}

				$metadata = _stripe_meta($lineMeta);
				$metadata = Assets_Payments_Stripe::resolveMetadata(
					$metadata, $event, 'invoice.paid'
				);

				$metadata['chargeId'] = $invoice->id;

				Assets_handleStripeSuccessfulCharge($amount, $currency, $metadata, $event);

			} catch (Exception $e) {
				Assets_Payments_Stripe::log('stripe', 'Error in invoice.paid', $e);
			}
			break;

		// ---------------------------------------------------------
		// SETUP INTENT SUCCEEDED
		// ---------------------------------------------------------
		case 'setup_intent.succeeded':
			try {
				$si       = $event->data->object;
				$metadata = _stripe_meta(Q::ifset($si, 'metadata', null));

				$userId = Q::ifset($metadata, 'userId', null);
				if (!$userId) {
					Assets_Payments_Stripe::log("Stripe setup intent missing userId");
					return;
				}

				$pm         = Q::ifset($si, 'payment_method', null);
				$customerId = Q::ifset($si, 'customer', null);

				if (!$pm || !$customerId) {
					Assets_Payments_Stripe::log("setup_intent missing fields");
					return;
				}

				$stripe = new \Stripe\StripeClient(
					Q_Config::expect('Assets', 'payments', 'stripe', 'secret')
				);

				$stripe->customers->update($customerId, array(
					'invoice_settings' => array(
						'default_payment_method' => $pm
					)
				));

				Assets_Payments_Stripe::log('SetupIntent succeeded, PM stored', $si);

			} catch (Exception $e) {
				Assets_Payments_Stripe::log('stripe', 'Error in setup_intent.succeeded', $e);
			}
			break;

		// ---------------------------------------------------------
		// MONEY RETURNED TO THE PAYER (ro#1246)
		// A refund made in the Stripe dashboard, or a dispute the
		// merchant lost: take back the credits the payment bought.
		// Assets::refunded() is idempotent, so redeliveries and the
		// cumulative amount of a later partial refund are safe.
		// The webhook destination must subscribe to both events.
		// ---------------------------------------------------------
		case 'charge.refunded':
		case 'charge.dispute.closed':
			try {
				$object = $event->data->object;
				if ($event->type === 'charge.dispute.closed') {
					if (Q::ifset($object, 'status', null) !== 'lost') {
						Assets_Payments_Stripe::log('stripe', 'Dispute closed, not lost: nothing to reverse');
						break;
					}
					// The Charge gives the amounts and the customer. If it can't
					// be fetched, the dispute alone still names the charge and
					// the PaymentIntent: reverse it in full rather than not at
					// all (Stripe has already taken the money back).
					$chargeObj = null;
					try {
						$chargeObj = _stripe_client()->charges->retrieve(Q::ifset($object, 'charge', ''));
					} catch (Exception $e) {
						Assets_Payments_Stripe::log('stripe', 'Could not fetch the disputed charge; reversing it in full', $e->getMessage());
					}
					if (!$chargeObj) {
						$chargeObj = (object)array(
							'id' => Q::ifset($object, 'charge', null),
							'payment_intent' => Q::ifset($object, 'payment_intent', null),
							'amount' => 0
						);
					}
					// What the payer got back in total: anything refunded
					// before the dispute, plus the disputed amount. The
					// reversal works on that cumulative figure.
					$returned = (int)Q::ifset($chargeObj, 'amount_refunded', 0)
						+ (int)Q::ifset($object, 'amount', 0);
					$cause = 'dispute';
				} else {
					$chargeObj = $object;
					$returned = (int)Q::ifset($chargeObj, 'amount_refunded', 0);
					$cause = 'refund';
				}
				$context = _stripe_refund_context($chargeObj);
				if ($context['app'] && $context['app'] !== Q::app()) {
					Assets_Payments_Stripe::log('stripe', $event->type . ' for another app');
					break;
				}
				$result = Assets::refunded(
					'stripe', $context['ids'], $returned, $context['chargedCents'], array(
						'customerId' => $context['customerId'],
						'intentToken' => $context['intentToken'],
						'cause' => $cause,
						'refundId' => $event->id
					)
				);
				Assets_Payments_Stripe::log('stripe', $event->type . ' handled', array(
					'ids' => $context['ids'],
					'returned' => $returned,
					'chargedCents' => $context['chargedCents'],
					'creditsTakenBack' => $result
				));
			} catch (Exception $e) {
				Assets_Payments_Stripe::log('stripe', 'Error in ' . $event->type, $e->getMessage());
			}
			break;

		// ---------------------------------------------------------
		// IGNORE EVERYTHING ELSE
		// ---------------------------------------------------------
		default:
			Assets_Payments_Stripe::log('stripe', 'Ignoring event', $event->type);
			return;
	}
}

/**
 * Normalize Stripe metadata object to array
 */
function _stripe_meta($m)
{
	if (!$m) return array();
	if (is_array($m)) return $m;
	if (is_object($m) && method_exists($m, 'toArray')) {
		return $m->toArray();
	}
	return array();
}

/**
 * A Stripe client on the configured secret.
 */
function _stripe_client()
{
	return new \Stripe\StripeClient(
		Q_Config::expect('Assets', 'payments', 'stripe', 'secret')
	);
}

/**
 * What Assets::refunded() needs from a Stripe Charge (ro#1246): the ids the
 * charge may be recorded under (Assets::charged() stores the PaymentIntent id
 * when the event carried no charge), its customer and amount, the app it
 * belongs to, and the Users_Intent token the payment continued. The token and
 * app live in the PaymentIntent's metadata, which Stripe does not always copy
 * onto the Charge, so the PaymentIntent is fetched when the Charge lacks them.
 * A failed fetch only loses the booking cancellation, never the reversal.
 */
function _stripe_refund_context($chargeObj)
{
	$meta = _stripe_meta(Q::ifset($chargeObj, 'metadata', null));
	$pi = Q::ifset($chargeObj, 'payment_intent', null);
	if (is_object($pi)) {
		$meta = array_merge(_stripe_meta(Q::ifset($pi, 'metadata', null)), $meta);
	}
	$pi = _stripe_id($pi);
	if ($pi && (empty($meta['intentToken']) || empty($meta['app']))) {
		try {
			$piObj = _stripe_client()->paymentIntents->retrieve($pi);
			$meta = array_merge(_stripe_meta(Q::ifset($piObj, 'metadata', null)), $meta);
		} catch (Exception $e) {
			Assets_Payments_Stripe::log('stripe', 'Could not fetch the PaymentIntent of a refunded charge', $e->getMessage());
		}
	}
	$customer = Q::ifset($chargeObj, 'customer', null);
	if (is_object($customer)) {
		$customer = Q::ifset($customer, 'id', null);
	}
	return array(
		'ids' => array_values(array_filter(array(
			Q::ifset($chargeObj, 'id', null), $pi, _stripe_id(Q::ifset($chargeObj, 'invoice', null))
		), 'is_string')),
		'customerId' => is_string($customer) ? $customer : null,
		'chargedCents' => (int)Q::ifset($chargeObj, 'amount', 0),
		'intentToken' => Q::ifset($meta, 'intentToken', null),
		'app' => Q::ifset($meta, 'app', null)
	);
}

/**
 * The id of a Stripe field that is either an id string or an expanded object.
 */
function _stripe_id($value)
{
	if (is_object($value)) {
		$value = Q::ifset($value, 'id', null);
	}
	return is_string($value) && $value !== '' ? $value : null;
}
