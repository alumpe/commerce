<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\commerce\elements\traits;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\errors\CurrencyException;
use craft\commerce\helpers\Order as OrderHelper;
use craft\commerce\models\OrderNotice;
use craft\commerce\Plugin;
use craft\elements\Address;
use yii\base\InvalidConfigException;
use yii\validators\Validator;

/**
 * @property Order $this
 */
trait OrderValidatorsTrait
{
    /**
     * @param string $attribute
     * @param $params
     * @param Validator $validator
     */
    public function validateGatewayId(string $attribute, $params, Validator $validator): void
    {
        if ($this->gatewayId && !$this->getGateway()) {
            $validator->addError($this, $attribute, Craft::t('commerce', 'Invalid gateway: {value}'));
        }
    }

    /**
     * @param string $attribute
     * @param $params
     * @param Validator $validator
     */
    public function validatePaymentSourceId(string $attribute, $params, Validator $validator): void
    {
        try {
            // this will confirm the payment source is valid and belongs to the orders customer
            $this->getPaymentSource();
        } catch (InvalidConfigException $e) {
            Craft::error($e->getMessage());
            $validator->addError($this, $attribute, Craft::t('commerce', 'Invalid payment source ID: {value}'));
        }
    }

    /**
     * @param string $attribute
     * @param $params
     * @param Validator $validator
     * @throws CurrencyException
     * @noinspection PhpUnused
     */
    public function validatePaymentCurrency(string $attribute, $params, Validator $validator): void
    {
        try {
            // this will confirm the payment source is valid and belongs to the orders customer
            $this->getPaymentCurrency();
        } catch (InvalidConfigException) {
            $validator->addError($this, $attribute, Craft::t('commerce', 'Invalid payment source ID: {value}'));
        }
    }

    /**
     * Validates addresses, and also adds prefixed validation errors to order
     *
     * @param string $attribute the attribute being validated
     * @noinspection PhpUnused
     */
    public function validateAddress(string $attribute): void
    {
        /** @var Address $address */
        $address = $this->$attribute;

        if ($address && !$address->validate()) {
            $this->addModelErrors($address, $attribute);
        }

        $marketLocationCondition = Plugin::getInstance()->getStore()->getStore()->getMarketAddressCondition();
        if ($address && count($marketLocationCondition->getConditionRules()) > 0 && !$marketLocationCondition->matchElement($address)) {
            $this->addError($attribute, Craft::t('commerce', 'The address provided is outside the store’s market.'));
        }
    }

    /**
     * Validates that shipping address isn't being set to be the same as billing address, when billing address is set to be shipping address
     *
     * @param string $attribute the attribute being validated
     */
    public function validateAddressReuse(string $attribute): void
    {
        if ($this->shippingSameAsBilling && $this->billingSameAsShipping) {
            $this->addError($attribute, Craft::t('commerce', 'shippingSameAsBilling and billingSameAsShipping can’t both be set.'));
        }
    }

    /**
     * Validates line items, and also adds prefixed validation errors to order
     *
     */
    public function validateLineItems(): void
    {
        OrderHelper::mergeDuplicateLineItems($this);

        foreach ($this->getLineItems() as $key => $lineItem) {
            if (!$lineItem->validate()) {
                $this->addModelErrors($lineItem, "lineItems.$key");
            }
        }
    }

    /**
     * @param $attribute
     * @throws InvalidConfigException
     * @noinspection PhpUnused
     */
    public function validateCouponCode($attribute): void
    {
        $recalculateAll = $this->recalculationMode == Order::RECALCULATION_MODE_ALL;
        $recalculateAll = $recalculateAll || $this->recalculationMode == Order::RECALCULATION_MODE_ADJUSTMENTS_ONLY;
        if ($recalculateAll && $this->$attribute && !Plugin::getInstance()->getDiscounts()->orderCouponAvailable($this, $explanation)) {
            /** @var OrderNotice $notice */
            $notice = Craft::createObject([
                'class' => OrderNotice::class,
                'attributes' => [
                    'type' => 'invalidCouponRemoved',
                    'attribute' => $attribute,
                    'message' => Craft::t('commerce', 'Coupon removed: {explanation}', [
                        'explanation' => $explanation,
                    ]),
                ],
            ]);
            $this->addNotice($notice);
            $this->$attribute = null;
        }
    }
}
