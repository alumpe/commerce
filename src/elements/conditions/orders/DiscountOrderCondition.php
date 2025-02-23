<?php

namespace craft\commerce\elements\conditions\orders;

use craft\elements\conditions\ElementCondition;
use craft\elements\db\ElementQueryInterface;
use yii\base\NotSupportedException;

/**
 * Discount Order condition.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 4.0.0
 */
class DiscountOrderCondition extends ElementCondition
{
    /**
     * @inheritdoc
     */
    protected function conditionRuleTypes(): array
    {
        return array_merge(parent::conditionRuleTypes(), [
            TotalPriceConditionRule::class,
        ]);
    }

    /**
     * @param ElementQueryInterface $query
     * @return void
     * @throws NotSupportedException
     */
    public function modifyQuery(ElementQueryInterface $query): void
    {
        throw new NotSupportedException('Discount Order Condition does not support element queries.');
    }
}
