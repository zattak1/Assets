<?php
/**
 * @module Assets
 */
/**
 * Class representing 'Customer' rows in the 'Assets' database
 * You can create an object of this class either to
 * access its non-static methods, or to actually
 * represent a customer row in the Assets database.
 *
 * @class Assets_Customer
 * @extends Base_Assets_Customer
 */
class Assets_Customer extends Base_Assets_Customer
{
	/**
	 * The setUp() method is called the first time
	 * an object of this class is constructed.
	 * @method setUp
	 */
	function setUp()
	{
		parent::setUp();
		// INSERT YOUR CODE HERE
		// e.g. $this->hasMany(...) and stuff like that.
	}

	/**
	 * Get value for `hash` column. Hashed string of secret, publishableKey and clientId
	 * @method getHash
	 * @static
	 * @return {String} hash
	 */
	static function getHash () {
		return Q_Utils::hash(Q_Config::expect('Assets', 'payments', 'stripe', 'secret')
			.Q_Config::expect('Assets', 'payments', 'stripe', 'publishableKey')
			.Q_Config::get("Assets", "payments", "stripe", "clientId", null));
	}

	/**
	 * Whether a payment processor's customer id is one of this user's
	 * customers, i.e. there is an assets_customer row binding them.
	 * A webhook credits the userId in the payment's metadata; this checks
	 * that whoever paid (the processor's customer) is that user (ro#1067).
	 * Rows under every keys hash count, so rotating the processor keys does
	 * not orphan a payment made just before the rotation.
	 * @method belongsTo
	 * @static
	 * @param {string} $customerId The processor's customer id, e.g. "cus_..."
	 * @param {string} $userId
	 * @param {string} [$payments="stripe"]
	 * @return {boolean}
	 */
	static function belongsTo($customerId, $userId, $payments = 'stripe')
	{
		if (!is_string($customerId) || $customerId === ''
		|| !is_string($userId) || $userId === '') {
			return false;
		}
		return (bool) Assets_Customer::select('COUNT(1)')->where(array(
			'userId' => $userId,
			'payments' => $payments,
			'customerId' => $customerId
		))->ignoreCache()->caching(false)->fetchAll(PDO::FETCH_COLUMN)[0];
	}

	/**
	 * Does necessary preparations for saving a stream in the database.
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
		if (empty($modifiedFields['hash'])) {
			$this->hash = $modifiedFields['hash'] = self::getHash();
		}
		return parent::beforeSave($modifiedFields);
	}

	/**
	 * Implements the __set_state method, so it can work with
	 * with var_export and be re-imported successfully.
	 * @method __set_state
	 * @static
	 * @param {array} $array
	 * @return {Assets_Customer} Class instance
	 */
	static function __set_state(array $array) {
		$result = new Assets_Customer();
		foreach($array as $k => $v)
			$result->$k = $v;
		return $result;
	}
};