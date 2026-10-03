<?php

/**
 * @event Assets/update/paymentSucceeded
 * @param {array} $data     Normalized payment data
 * @param {array} $envelope Full update (raw, metadata, ids)
 */
function Assets_update_paymentSucceeded($data, $envelope)
{
	$metadata = Q::ifset($data, 'metadata', array());

	// ---------------------------------------------
	// 1. Idempotent charge record
	// ---------------------------------------------
	$charge = Assets::charged(
		$data['payments'],
		$data['amount'],
		$data['currency'],
		array_merge($metadata, array(
			'chargeId' => $data['chargeId'],
			'userId'   => $data['userId']
		))
	);

	// ---------------------------------------------
	// 2. Intent continuation (generic, not Stripe)
	// ---------------------------------------------
	if (
		!empty($metadata['intentToken']) &&
		Q::ifset($metadata, 'autoCharge', null) != 1
	) {
		$intent = new Users_Intent(array(
			'token' => $metadata['intentToken']
		));

		// Only the intent's own user's payment may continue it (ro#1067),
		// and only once (ro#1070): Assets::continueIntent() checks both.
		if ($intent->retrieve()) {
			Assets::continueIntent($intent, $data['userId'], $data['payments']);
		}
	}
}
