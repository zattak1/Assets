Q.exports(function () {
	/**
	* Buy credits
	* @method buy
	* @param {object} options
	* @param {number} [options.amount=10] Amount to spend, in terms of currency
	* @param {string} [options.currency=USD] Currency ISO 4217 code (USD, EUR etc)
	* @param {string} [options.missing=false] Whether to show text about credits missing.
	* @param {string} [options.title] Can override title of dialog
	* @param {object} [options.metadata] Data to pass to payment gateway to get them back and save to message instructions
	* @param {function} [options.onSuccess] Callback to run when payment has completed successfully.
	* @param {function} [options.onFailure] Callback to run when payment failed.
	* @param {boolean} [options.skipDialog=false] If true, bypass dialog entirely and start payment immediately.
	*/
	return function buy(options) {
		options = Q.extend({
			amount: 10,
			currency: 'USD',
			missing: false,
			reason: 'BoughtCredits',
			skipDialog: false
		}, options);

		var title = options.title || Q.text.Assets.credits.BuyCredits;
		var NotEnoughCredits = null;
		var templateName = 'Assets/credits/buy';

		if (options.missing) {
			templateName = 'Assets/credits/missing';
			title = Q.text.Assets.credits.NeedMoreCredits;
			NotEnoughCredits = Q.text.Assets.credits.NotEnoughCredits.interpolate({
				amount: options.amount.toFixed(2),
				currency: options.currency
			});
		}

		var bonuses = [];
		Q.each(Q.getObject("credits.bonus.bought", Q.Assets), function (credits, bonus) {
			bonuses.push(Q.text.Assets.credits.BuyBonus.interpolate({
				amount: "<span class='credits'>" + credits + "</span>",
				bonus: "<span class='bonus'>" + bonus + "</span>"
			}));
		});

		Q.Template.set('Assets/credits/missing',
			'<div class="Assets_credits_buy_missing">{{YouMissingCredits}}</div>' +
			'<input type="hidden" name="amount" value="{{amount}}">' +
			'<button class="Q_button" name="buy">{{texts.PurchaseCredits}}</button>'
		);
		Q.Template.set('Assets/credits/buy',
			'{{#each bonuses}}' +
			'	<div class="Assets_credits_bonus">{{{this}}}</div>' +
			'{{/each}}' +
			'<div class="Assets_credits_buy"><input name="amount" value="{{amount}}"> {{currency}}</div>' +
			'<div class="Assets_credits_equivalent"></div>' +
			'<button class="Q_button" name="buy">{{texts.PurchaseCredits}}</button>'
		);

		// Load payment lib
		Q.Assets.Payments.load();

		// --- NEW: Skip dialog flow ---
		if (options.skipDialog) {
			var amt = Math.round(options.amount * 100) / 100;

			if (!amt) {
				return Q.handle(options.onFailure, null, [
					new Error("Invalid amount")
				]);
			}

			return Q.Assets.Payments.stripe({
				amount: amt,
				currency: options.currency,
				metadata: options.metadata,
				reason: options.reason,
				intentToken: options.intentToken
			}, function (err, data) {
				if (err) {
					return Q.handle(options.onFailure, null, [err]);
				}
				return Q.handle(options.onSuccess, null, [null, data]);
			});
		}
		// --- END skipDialog ---

		var paymentStarted = false;

		// Normal dialog flow
		Q.Dialogs.push({
			title: title,
			className: "Assets_credits_buy",
			template: {
				name: templateName,
				fields: {
					amount: options.amount,
					currency: options.currency,
					NotEnoughCredits: NotEnoughCredits,
					bonuses: bonuses,
					texts: Q.text.Assets.credits
				}
			},
			onActivate: function (dialog) {
				// The amount field is denominated in currency (see docblock),
				// so show the credits equivalence live rather than mislabeling
				// the input as credits (a 100x misstatement at purchase time).
				var $amount = $("input[name=amount]", dialog);
				var $equiv = $(".Assets_credits_equivalent", dialog);
				function _updateEquivalent() {
					var a = parseFloat($amount.val());
					if (!a || a < 0 || !Q.Assets.Credits.convertToCredits) {
						return $equiv.text("");
					}
					$equiv.text("= "
						+ Q.Assets.Credits.convertToCredits(a, options.currency)
						+ " " + Q.text.Assets.credits.Credits);
				}
				$amount.on("input", _updateEquivalent);
				_updateEquivalent();
				$("button[name=buy]", dialog).on(Q.Pointer.fastclick, function () {
					paymentStarted = true;

					var amount = $("input[name=amount]", dialog).val();
					amount = Math.round(amount * 100) / 100;

					if (!amount) {
						return Q.alert(Q.text.Assets.credits.ErrorInvalidAmount);
					}

					Q.Dialogs.pop();

					Q.Assets.Payments.stripe({
						amount: amount,
						currency: options.currency,
						metadata: options.metadata,
						reason: options.reason,
						intentToken: options.intentToken
					}, function (err, data) {
						if (err) {
							return Q.handle(options.onFailure, null, [err]);
						}
						return Q.handle(options.onSuccess, null, [null, data]);
					});
				});
			},
			onClose: function () {
				if (!paymentStarted) {
					Q.handle(options.onFailure);
				}
			}
		});
	}
});
