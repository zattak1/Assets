<?php
/**
 * @module Assets
 * @class HTTP Assets pay
 */

/**
 * HTTP method for sending funds to some user or stream. Requires login.
 * Delegates to Assets::pay() for unified payment logic.
 * @method post
 * @method Assets pay post
 * @param {array} $_REQUEST
 * @param {string|number} $_REQUEST.amount Amount in original currency
 * @param {String} $_REQUEST.currency Original currency
 * @param {String} [$_REQUEST.payments="stripe"] Payments gateway
 * @param {Array} [$_REQUEST.toStream] (publisherId + streamName/name) for stream payment
 * @param {String} [$_REQUEST.toUserId] Destination user
 * @param {Array} [$_REQUEST.items] Itemized list: each item has {publisherId, streamName, amount}.
 * @param {String} $_REQUEST.reason Reason for payment. Must be declared in
 *   "Assets"/"credits"/"reasons" or "Assets"/"payments"/"reasons" config --
 *   see Assets_Credits::requireValidReason().
 * @param {String} [$params.userId] Override userId
 * @param {boolean} [$params.autoCharge=false] Auto-charge if needed
 */
function Assets_pay_post($params = array())
{
	$req = array_merge($_REQUEST, $params);
	Q_Valid::requireFields(array('amount', 'currency'), $req, true);

	// Resolve the user performing the payment
	$userId = Q::ifset($params, "userId", null);
	if (!$userId) {
		$userId = Users::loggedInUser(true)->id;
	}

	$currency = $req['currency'];
	$payments = Q::ifset($req, "payments", "stripe");
	$amount   = floatval($req['amount']);
	$reason   = Q::ifset($req, 'reason', null);
	$force    = Q::ifset($params, "autoCharge", false);

	// The request boundary is where the reason stops being trusted. Without
	// this, any string at all mints a Users_Intent and lands verbatim in
	// assets_credits.reason, which anything reporting on or reconciling
	// against that column would then have to treat as free text.
	// Assets/payment intent already gates its own reason this way; this is
	// the same gate on the other entry point into the same flow.
	Assets_Credits::requireValidReason($reason);

	// Detect stream destination
	$toPublisherId = Q::ifset($req, 'toStream', 'publisherId', null);
	$toStreamName  = Q::ifset(
		$req, 'toStream', 'streamName',
		Q::ifset($req, 'toStream', 'name', null)
	);

	// Detect user destination
	$toUserId = $toPublisherId ? $toPublisherId : Q::ifset($req, "toUserId", null);

	// Itemized amounts
	$items = Q::ifset($req, "items", null);

	$result = Assets::pay(
		Q::ifset($params, 'communityId', null), // communityId or null
		$userId,                                // actor
		$amount,                                // amount in original currency
		$reason,                                // reason
		array(
			"currency"      => $currency,
			"payments"      => $payments,
			"autoCharge"    => $force,
			"toUserId"      => $toUserId,
			"toPublisherId" => $toPublisherId,
			"toStreamName"  => $toStreamName,
			"items"         => $items
		)
	);

	Q_response::setSlot('success', $result['success']);
	Q_response::setSlot('details', $result['details']);
}