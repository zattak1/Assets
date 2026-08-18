<?php

$_composerAutoload = ASSETS_PLUGIN_DIR . DS . 'vendor' . DS . 'autoload.php';
if (file_exists($_composerAutoload)) {
	require_once $_composerAutoload; // optional: plugin works without it
}

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
		// $shouldContinue was read here but never assigned -- on PHP 8 that is
		// a warning and evaluates to null, so this block never ran and a
		// pending Assets::pay intent was never continued after the charge.
		//
		// autoCharge arrives as the string "1" when it round-trips through
		// Stripe metadata, but this file also sets it as a PHP bool
		// ($options['autoCharge'] = false, below). Comparing against "1" alone
		// means a real boolean true reads as "not an autoCharge" and the block
		// continues -- the expensive direction, since continuing spends credits.
		$shouldContinue = (
			!empty($metadata['intentToken'])
			&& !filter_var(
				Q::ifset($metadata, 'autoCharge', false),
				FILTER_VALIDATE_BOOLEAN
			)
		);
		if ($shouldContinue) {
			$intent = new Users_Intent(array('token' => $metadata['intentToken']));
			if ($intent->retrieve() && $intent->isValid()) {

				$instructions = $intent->getAllInstructions();

				// get amount of credits to transfer
				$options = Q::take($instructions, array(
					'currency', 'payments',
					'toPublisherId', 'toStreamName', 'toUserId', 'metadata'
				));
				$options['autoCharge'] = false;
				if ($needCredits = $intent->getInstruction('needCredits', 0)) {
					$options['currency'] = 'credits';
				}
				$options['metadata'] = $metadata;
				$options['intent'] = $intent;

				$spentCredits = 0;
				if ($needCredits) {
					$spentCredits = Assets_Credits::spend(
						$instructions['communityId'],
						$needCredits,
						$instructions['reason'],
						$instructions['userId'],
						$options
					);
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

				$pmObj = $stripe->paymentMethods->retrieve($pm);
				$card  = Q::ifset($pmObj, 'card', null);
				Assets::rememberPaymentMethod($userId, 'stripe', array(
					'brand'    => $card ? $card->brand : $pmObj->type,
					'last4'    => $card ? $card->last4 : null,
					'expMonth' => $card ? $card->exp_month : null,
					'expYear'  => $card ? $card->exp_year : null
				));

				Assets_Payments_Stripe::log('SetupIntent succeeded, PM stored', $si);

			} catch (Exception $e) {
				Assets_Payments_Stripe::log('stripe', 'Error in setup_intent.succeeded', $e);
			}
			break;

		// ---------------------------------------------------------
		// PAYMENT METHOD WAS DETACHED
		// ---------------------------------------------------------
		case 'payment_method.detached':
			try {
				$pmObj = $event->data->object;
				$customerId = Q::ifset($event, 'data', 'previous_attributes', 'customer', null);
				if ($userId = Assets::userIdFromCustomerId($customerId, 'stripe')) {
					Assets::rememberPaymentMethod($userId, 'stripe', null);
				}
			} catch (Exception $e) {
				Assets_Payments_Stripe::log('stripe', 'Error in payment_method.detached', $e);
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