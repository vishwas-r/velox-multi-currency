<?php
/**
 * SettlementResult value object holding resolved charge currency, amount and disclosure.
 *
 * @package NammaWP\MultiCurrency\GatewayRouter
 */

namespace NammaWP\MultiCurrency\GatewayRouter;

use NammaWP\MultiCurrency\Currency\Money;

defined( 'ABSPATH' ) || exit;

/**
 * SettlementResult class.
 */
class SettlementResult {

	public string $display_currency;
	public Money $display_amount;
	public string $charge_currency;
	public Money $charge_amount;
	public float $exchange_rate;
	public float $rate_markup;
	public string $mode;
	public bool $disclosure_required;
	public string $disclosure_message;

	/**
	 * Constructor.
	 *
	 * @param string $display_currency    Display currency.
	 * @param Money  $display_amount      Display amount.
	 * @param string $charge_currency     Settlement charge currency.
	 * @param Money  $charge_amount       Final charge amount.
	 * @param float  $exchange_rate       Applied exchange rate.
	 * @param float  $rate_markup         Applied buffer percentage.
	 * @param string $mode                SettlementMode constant.
	 * @param bool   $disclosure_required True if disclosure notice must be shown.
	 * @param string $disclosure_message  Formatted disclosure notice text.
	 */
	public function __construct(
		string $display_currency,
		Money $display_amount,
		string $charge_currency,
		Money $charge_amount,
		float $exchange_rate,
		float $rate_markup,
		string $mode,
		bool $disclosure_required = false,
		string $disclosure_message = ''
	) {
		$this->display_currency    = $display_currency;
		$this->display_amount      = $display_amount;
		$this->charge_currency     = $charge_currency;
		$this->charge_amount       = $charge_amount;
		$this->exchange_rate       = $exchange_rate;
		$this->rate_markup         = $rate_markup;
		$this->mode                = $mode;
		$this->disclosure_required = $disclosure_required;
		$this->disclosure_message  = $disclosure_message;
	}

	/**
	 * Export as array for HPOS metadata or REST API.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'display_currency'    => $this->display_currency,
			'display_amount'      => $this->display_amount->to_major(),
			'charge_currency'     => $this->charge_currency,
			'charge_amount'       => $this->charge_amount->to_major(),
			'exchange_rate'       => $this->exchange_rate,
			'rate_markup'         => $this->rate_markup,
			'mode'                => $this->mode,
			'disclosure_required' => $this->disclosure_required,
			'disclosure_message'  => $this->disclosure_message,
		);
	}
}
