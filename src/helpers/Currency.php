<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\commerce\helpers;

use Craft;
use craft\commerce\errors\CurrencyException;
use craft\commerce\models\Currency as CurrencyModel;
use craft\commerce\models\PaymentCurrency;
use craft\commerce\Plugin;
use yii\base\InvalidCallException;
use yii\base\InvalidConfigException;

/**
 * Class Currency
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 2.0
 */
class Currency
{
    /**
     * Rounds the amount as per the currency minor unit information. Not passing
     * a currency model results in rounding in default currency.
     *
     * @param float $amount
     * @param PaymentCurrency|CurrencyModel|null $currency
     * @return float
     */
    public static function round(float $amount, PaymentCurrency|CurrencyModel|null $currency = null): float
    {
        if (!$currency) {
            $defaultPaymentCurrency = Plugin::getInstance()->getPaymentCurrencies()->getPrimaryPaymentCurrency();
            $currency = Plugin::getInstance()->getCurrencies()->getCurrencyByIso($defaultPaymentCurrency->iso);
        }

        $decimals = $currency->minorUnit;
        return round($amount, $decimals);
    }

    public static function defaultDecimals(): int
    {
        $currency = Plugin::getInstance()->getPaymentCurrencies()->getPrimaryPaymentCurrencyIso();
        return Plugin::getInstance()->getCurrencies()->getCurrencyByIso($currency)->minorUnit;
    }

    /**
     * Formats and optionally converts a currency amount into the supplied valid payment currency as per the rate setup in payment currencies.
     *
     * @param      $amount
     * @param null $currency
     * @param bool $convert
     * @param bool $format
     * @param bool $stripZeros
     * @return string
     * @throws CurrencyException
     * @throws InvalidConfigException
     */
    public static function formatAsCurrency($amount, $currency = null, bool $convert = false, bool $format = true, bool $stripZeros = false): string
    {
        // return input if no currency passed, and both convert and format are false.
        if (!$convert && !$format) {
            return $amount;
        }

        $currencyIso = Plugin::getInstance()->getPaymentCurrencies()->getPrimaryPaymentCurrencyIso();

        if (is_string($currency)) {
            $currencyIso = $currency;
        }

        if ($currency instanceof PaymentCurrency) {
            $currencyIso = $currency->iso;
        }

        if ($currency instanceof CurrencyModel) {
            $currencyIso = $currency->alphabeticCode;
        }

        if ($convert) {
            $currency = Plugin::getInstance()->getPaymentCurrencies()->getPaymentCurrencyByIso($currencyIso);
            if (!$currency) {
                throw new InvalidCallException('Trying to convert to a currency that is not configured');
            }
        }

        if ($convert) {
            $amount = Plugin::getInstance()->getPaymentCurrencies()->convert((float)$amount, $currencyIso);
        }

        if ($format) {
            // Round it before formatting
            if ($currencyData = Plugin::getInstance()->getCurrencies()->getCurrencyByIso($currencyIso)) {
                $amount = self::round($amount, $currencyData); // Will round to the right minorUnits
            }

            $amount = Craft::$app->getFormatter()->asCurrency($amount, $currencyIso, [], [], $stripZeros);
        }

        return (string)$amount;
    }
}
