<?php
/**
 * Money unit tests.
 *
 * @package SettleX\MultiCurrency\Tests\Unit
 */

namespace SettleX\MultiCurrency\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SettleX\MultiCurrency\Currency\Money;

/**
 * MoneyTest class.
 */
class MoneyTest extends TestCase {

	/**
	 * Test standard 2-decimal creation and conversion.
	 */
	public function test_two_decimal_currency_math(): void {
		$money = Money::from_major( '19.99', 'USD' );
		$this->assertSame( 1999, $money->get_minor() );
		$this->assertSame( 19.99, $money->to_major() );
		$this->assertSame( '19.99', $money->to_major_string() );
	}

	/**
	 * Test zero-decimal currencies (JPY).
	 */
	public function test_zero_decimal_currency_jpy(): void {
		$money = Money::from_major( 1500, 'JPY' );
		$this->assertSame( 1500, $money->get_minor() );
		$this->assertSame( 1500.0, $money->to_major() );
		$this->assertSame( '1500', $money->to_major_string() );
	}

	/**
	 * Test 3-decimal currencies (KWD).
	 */
	public function test_three_decimal_currency_kwd(): void {
		$money = Money::from_major( '12.345', 'KWD' );
		$this->assertSame( 12345, $money->get_minor() );
		$this->assertSame( 12.345, $money->to_major() );
		$this->assertSame( '12.345', $money->to_major_string() );
	}

	/**
	 * Test strict zero-guard: A positive price converted at tiny exchange rate must never equal 0!
	 */
	public function test_zero_guard_prevents_free_checkout(): void {
		$money = Money::from_major( '0.01', 'USD' );
		// Convert at an extremely small rate (e.g. 0.000001).
		$converted = $money->convert_to( 'EUR', 0.000001 );

		// Zero guard ensures it rounds to minimum 1 minor unit, NOT 0!
		$this->assertGreaterThan( 0, $converted->get_minor() );
		$this->assertSame( 1, $converted->get_minor() );
	}

	/**
	 * Test charm pricing rounding (.99).
	 */
	public function test_charm_pricing_rounding(): void {
		$money   = Money::from_major( '19.45', 'USD' );
		$rounded = $money->apply_rounding( 'charm_99' );
		$this->assertSame( '19.99', $rounded->to_major_string() );

		$money2   = Money::from_major( '19.45', 'USD' );
		$rounded2 = $money2->apply_rounding( 'charm_95' );
		$this->assertSame( '19.95', $rounded2->to_major_string() );
	}

	/**
	 * Test exchange conversion with buffer markup.
	 */
	public function test_conversion_with_markup(): void {
		$money = Money::from_major( 100, 'USD' );
		// Rate: 1 USD = 80 INR. Markup = +2.5% => Effective rate 82.0.
		$converted = $money->convert_to( 'INR', 80.0, 2.5 );
		$this->assertSame( '8200.00', $converted->to_major_string() );
	}
}
