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

		// The intent spends its own userId's credits: only that user's
		// payment may continue it (ro#1067).
		if ($intent->retrieve() && $intent->isValid()
		&& $intent->getInstruction('userId', null) === $data['userId']) {

			$instructions = $intent->getAllInstructions();

			$options = Q::take($instructions, array(
				'currency', 'payments',
				'toPublisherId', 'toStreamName',
				'toUserId', 'metadata'
			));
			$options['autoCharge'] = false;

			$needCredits = $intent->getInstruction('needCredits', 0);
			if ($needCredits) {
				$options['currency'] = 'credits';
			}

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

			$intent->complete(array(
				'success'      => (!$needCredits || $spentCredits > 0),
				'spentCredits' => $spentCredits
			));
		}
	}
}
