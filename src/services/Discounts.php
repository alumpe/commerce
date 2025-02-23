<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\commerce\services;

use Craft;
use craft\commerce\adjusters\Discount as DiscountAdjuster;
use craft\commerce\base\PurchasableInterface;
use craft\commerce\db\Table;
use craft\commerce\elements\Order;
use craft\commerce\events\DiscountEvent;
use craft\commerce\events\MatchLineItemEvent;
use craft\commerce\events\MatchOrderEvent;
use craft\commerce\models\Coupon;
use craft\commerce\models\Discount;
use craft\commerce\models\LineItem;
use craft\commerce\models\OrderAdjustment;
use craft\commerce\Plugin;
use craft\commerce\records\Coupon as CouponRecord;
use craft\commerce\records\CustomerDiscountUse;
use craft\commerce\records\Discount as DiscountRecord;
use craft\commerce\records\DiscountCategory as DiscountCategoryRecord;
use craft\commerce\records\DiscountPurchasable as DiscountPurchasableRecord;
use craft\commerce\records\EmailDiscountUse as EmailDiscountUseRecord;
use craft\db\Query;
use craft\elements\Category;
use craft\elements\User;
use craft\helpers\ArrayHelper;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use DateTime;
use Throwable;
use Twig\Error\LoaderError;
use Twig\Error\SyntaxError;
use yii\base\Component;
use yii\base\Exception;
use yii\base\InvalidConfigException;
use yii\db\Expression;
use yii\db\StaleObjectException;
use function in_array;

/**
 * Discount service.
 *
 * @property array|Discount[] $allDiscounts
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 2.0y
 */
class Discounts extends Component
{
    /**
     * @event DiscountEvent The event that is triggered before a discount is saved.
     *
     * ```php
     * use craft\commerce\events\DiscountEvent;
     * use craft\commerce\services\Discounts;
     * use craft\commerce\models\Discount;
     * use yii\base\Event;
     *
     * Event::on(
     *     Discounts::class,
     *     Discounts::EVENT_BEFORE_SAVE_DISCOUNT,
     *     function(DiscountEvent $event) {
     *         // @var Discount $discount
     *         $discount = $event->discount;
     *         // @var bool $isNew
     *         $isNew = $event->isNew;
     *
     *         // Let an external CRM know about a client’s new discount
     *         // ...
     *     }
     * );
     * ```
     */
    public const EVENT_BEFORE_SAVE_DISCOUNT = 'beforeSaveDiscount';

    /**
     * @event DiscountEvent The event that is triggered after a discount is saved.
     *
     * ```php
     * use craft\commerce\events\DiscountEvent;
     * use craft\commerce\services\Discounts;
     * use craft\commerce\models\Discount;
     * use yii\base\Event;
     *
     * Event::on(
     *     Discounts::class,
     *     Discounts::EVENT_AFTER_SAVE_DISCOUNT,
     *     function(DiscountEvent $event) {
     *         // @var Discount $discount
     *         $discount = $event->discount;
     *         // @var bool $isNew
     *         $isNew = $event->isNew;
     *
     *         // Set this discount as default in an external CRM
     *         // ...
     *     }
     * );
     * ```
     */
    public const EVENT_AFTER_SAVE_DISCOUNT = 'afterSaveDiscount';

    /**
     * @event DiscountEvent The event that is triggered after a discount is deleted.
     *
     * ```php
     * use craft\commerce\events\DiscountEvent;
     * use craft\commerce\services\Discounts;
     * use craft\commerce\models\Discount;
     * use yii\base\Event;
     *
     * Event::on(
     *     Discounts::class,
     *     Discounts::EVENT_AFTER_DELETE_DISCOUNT,
     *     function(DiscountEvent $event) {
     *         // @var Discount $discount
     *         $discount = $event->discount;
     *
     *         // Remove this discount from a payment gateway
     *         // ...
     *     }
     * );
     * ```
     */
    public const EVENT_AFTER_DELETE_DISCOUNT = 'afterDeleteDiscount';

    /**
     * @event MatchLineItemEvent The event that is triggered when a line item is matched with a discount.
     *
     * This event will be raised if all standard conditions are met.
     * You may set the `isValid` property to `false` on the event to prevent the matching of the discount to the line item.
     *
     * ```php
     * use craft\commerce\services\Discounts;
     * use craft\commerce\events\MatchLineItemEvent;
     * use craft\commerce\models\Discount;
     * use craft\commerce\models\LineItem;
     * use yii\base\Event;
     *
     * Event::on(
     *     Discounts::class,
     *     Discounts::EVENT_DISCOUNT_MATCHES_LINE_ITEM,
     *     function(MatchLineItemEvent $event) {
     *         // @var LineItem $lineItem
     *         $lineItem = $event->lineItem;
     *         // @var Discount $discount
     *         $discount = $event->discount;
     *
     *         // Check some business rules and prevent a match in special cases
     *         // ...
     *     }
     * );
     * ```
     */
    public const EVENT_DISCOUNT_MATCHES_LINE_ITEM = 'discountMatchesLineItem';

    /**
     * @event MatchOrderEvent The event that is triggered when an order is matched with a discount.
     *
     * You may set the `isValid` property to `false` on the event to prevent the matching of the discount with the order.
     *
     * ```php
     * use craft\commerce\services\Discounts;
     * use craft\commerce\events\MatchOrderEvent;
     * use craft\commerce\models\Discount;
     * use craft\commerce\elements\Order;
     * use yii\base\Event;
     *
     * Event::on(
     *     Discounts::class,
     *     Discounts::EVENT_DISCOUNT_MATCHES_ORDER,
     *     function(MatchOrderEvent $event) {
     *         // @var Order $order
     *         $order = $event->order;
     *         // @var Discount $discount
     *         $discount = $event->discount;
     *
     *         // Check some business rules and prevent a match in special cases
     *         // ... $event->isValid = false; // set to false if you want it to NOT match as it would.
     *     }
     * );
     * ```
     */
    public const EVENT_DISCOUNT_MATCHES_ORDER = 'discountMatchesOrder';

    /**
     * @var Discount[]|null
     */
    private ?array $_allDiscounts = null;

    /**
     * @var Discount[][]|null
     */
    private ?array $_activeDiscountsByKey = null;

    /**
     * @var array|null
     */
    private ?array $_matchingLineItemCategoryCondition = null;

    /**
     * Get a discount by its ID.
     */
    public function getDiscountById(int $id): ?Discount
    {
        if (!$id) {
            return null;
        }

        $discounts = $this->_createDiscountQuery()->andWhere(['[[discounts.id]]' => $id])->all();

        if (!$discounts) {
            return null;
        }

        return ArrayHelper::firstValue($this->_populateDiscounts($discounts));
    }

    /**
     * Get all discounts.
     *
     * @return Discount[]
     */
    public function getAllDiscounts(): array
    {
        if (!isset($this->_allDiscounts)) {
            $discounts = $this->_createDiscountQuery()->all();

            $this->_allDiscounts = $this->_populateDiscounts($discounts);
        }

        return $this->_allDiscounts;
    }

    /**
     * Get all currently active discounts
     * We pass the Order to attempt ot optimize the query to only possible discounts that might match,
     * eliminating ones that definitely will not match.
     *
     * @param Order|null $order
     * @return Discount[]
     * @throws \Exception
     * @since 2.2.14
     */
    public function getAllActiveDiscounts(Order $order = null): array
    {
        // Date condition for use with key
        if ($order && $order->dateOrdered) {
            $date = $order->dateOrdered;
        } else {
            // We use a round the time so we can have a cache within the same request (rounded to 1 minute flat, no seconds)
            $date = new DateTime();
            $date->setTime($date->format('H'), round($date->format('i') / 1) * 1);
        }

        // Coupon condition key
        $couponKey = ($order && $order->couponCode) ? $order->couponCode : '*';
        $dateKey = DateTimeHelper::toIso8601($date);
        $cacheKey = implode(':', [$dateKey, $couponKey]);

        if (isset($this->_activeDiscountsByKey[$cacheKey])) {
            return $this->_activeDiscountsByKey[$cacheKey];
        }

        $discountQuery = $this->_createDiscountQuery()
            // Restricted by enabled discounts
            ->where([
                'enabled' => true,
            ])
            // Restrict by things that a definitely not in date
            ->andWhere([
                'or',
                ['dateFrom' => null],
                ['<=', 'dateFrom', Db::prepareDateForDb($date)],
            ])
            ->andWhere([
                'or',
                ['dateTo' => null],
                ['>=', 'dateTo', Db::prepareDateForDb($date)],
            ]);

        // If the order has a coupon code let's only get discounts for that code, or discounts that do not require a code
        if ($order && $order->couponCode) {
            $couponSubQuery = (new Query())
                ->from(Table::COUPONS)
                ->where(new Expression('[[discountId]] = [[discounts.id]]'));

            if (Craft::$app->getDb()->getIsPgsql()) {
                $codeWhere = ['ilike', 'code', $order->couponCode];
            } else {
                $codeWhere = ['code' => $order->couponCode];
            }

            $discountQuery->andWhere([
                'or',
                // Find discount where the coupon code matches
                [
                    'exists', (clone $couponSubQuery)
                    ->andWhere($codeWhere)
                    ->andWhere([
                            'or',
                            ['maxUses' => null],
                            new Expression('[[uses]] < [[maxUses]]'),
                        ]
                    ),
                ],
                // OR find discounts that do not have a coupon code requirement
                ['not exists', $couponSubQuery],
            ]);
        }

        $this->_activeDiscountsByKey[$cacheKey] = $this->_populateDiscounts($discountQuery->all());

        return $this->_activeDiscountsByKey[$cacheKey];
    }

    /**
     * Is discount coupon available to the order
     *
     * @param string|null $explanation
     * @throws InvalidConfigException
     * @throws \Exception
     */
    public function orderCouponAvailable(Order $order, string &$explanation = null): bool
    {
        $discount = $this->getDiscountByCode($order->couponCode);

        if (!$discount || !$this->_isDiscountCouponCodeValid($order, $discount)) {
            $explanation = Craft::t('commerce', 'Coupon not valid.');
            return false;
        }

        if (!$this->_isDiscountConditionFormulaValid($order, $discount)) {
            $explanation = Craft::t('commerce', 'Discount is not allowed for the order');
            return false;
        }

        if (!$this->_isDiscountDateValid($order, $discount)) {
            $explanation = Craft::t('commerce', 'Discount is out of date.');
            return false;
        }

        if (!$this->_isDiscountTotalUseLimitValid($discount)) {
            $explanation = Craft::t('commerce', 'Discount use has reached its limit.');
            return false;
        }

        $user = $order->getCustomer();

        if (!$this->_isDiscountPerUserUsageValid($discount, $user)) {
            $explanation = Craft::t('commerce', 'This coupon is for registered users and limited to {limit} uses.', [
                'limit' => $discount->perUserLimit,
            ]);
            return false;
        }

        if (!$this->_isDiscountEmailRequirementValid($discount, $order)) {
            $explanation = Craft::t('commerce', 'This coupon requires an email address.');
            return false;
        }

        if (!$this->_isDiscountPerEmailLimitValid($discount, $order)) {
            $explanation = Craft::t('commerce', 'This coupon is limited to {limit} uses.', [
                'limit' => $discount->perEmailLimit,
            ]);
            return false;
        }

        return true;
    }

    /**
     * Returns an enabled discount by its code.
     *
     * @throws \Exception
     */
    public function getDiscountByCode(string $code): ?Discount
    {
        if (!$code) {
            return null;
        }

        $query = $this->_createDiscountQuery();
        $query->innerJoin(Table::COUPONS . ' coupons', '[[coupons.discountId]] = [[discounts.id]]');
        if (Craft::$app->getDb()->getIsPgsql()) {
            $query->andWhere(['ilike', '[[coupons.code]]', $code]);
        } else {
            $query->andWhere(['[[coupons.code]]' => $code]);
        }
        $discounts = $query->all();

        if (!$discounts) {
            return null;
        }

        return ArrayHelper::firstWhere($this->_populateDiscounts($discounts), function(Discount $discount) use ($code) {
            return (
                $discount->enabled &&
                ArrayHelper::contains($discount->getCoupons(), fn(Coupon $coupon) => strcasecmp($coupon->code, $code) === 0)
            );
        });
    }

    /**
     * @since 2.2
     */
    public function getDiscountsRelatedToPurchasable(PurchasableInterface $purchasable): array
    {
        $discounts = [];

        if ($purchasable->getId()) {
            foreach ($this->getAllDiscounts() as $discount) {
                // Get discount by related purchasable
                $purchasableIds = $discount->getPurchasableIds();
                $id = $purchasable->getId();

                // Get discount by related category
                $relatedTo = [$discount->categoryRelationshipType => $purchasable->getPromotionRelationSource()];
                $categoryIds = $discount->getCategoryIds();
                $relatedCategories = Category::find()->id($categoryIds)->relatedTo($relatedTo)->ids();

                if (in_array($id, $purchasableIds, false) || !empty($relatedCategories)) {
                    $discounts[$discount->id] = $discount;
                }
            }
        }

        return $discounts;
    }

    /**
     * Match a line item against a discount.
     *
     * @throws \Exception
     */
    public function matchLineItem(LineItem $lineItem, Discount $discount, bool $matchOrder = false): bool
    {
        if ($matchOrder && !$this->matchOrder($lineItem->order, $discount)) {
            return false;
        }

        if ($lineItem->getOnSale() && $discount->excludeOnSale) {
            return false;
        }

        // can't match something not promotable
        if (!$lineItem->getPurchasable() || !$lineItem->getPurchasable()->getIsPromotable()) {
            return false;
        }

        if (!$discount->allPurchasables) {
            $purchasableId = $lineItem->purchasableId;
            if (!in_array($purchasableId, $discount->getPurchasableIds(), false)) {
                return false;
            }
        }

        if (!$discount->allCategories && $purchasable = $lineItem->getPurchasable()) {
            $key = 'relationshipType:' . $discount->categoryRelationshipType . ':purchasableId:' . $purchasable->getId() . ':categoryIds:' . implode('|', $discount->getCategoryIds());

            if (!isset($this->_matchingLineItemCategoryCondition[$key])) {
                $relatedTo = [$discount->categoryRelationshipType => $purchasable->getPromotionRelationSource()];
                $relatedCategories = Category::find()->relatedTo($relatedTo)->ids();
                $purchasableIsRelateToOneOrMoreCategories = (bool)array_intersect($relatedCategories, $discount->getCategoryIds());
                if (!$purchasableIsRelateToOneOrMoreCategories) {
                    return $this->_matchingLineItemCategoryCondition[$key] = false;
                }
                $this->_matchingLineItemCategoryCondition[$key] = true;
            } elseif ($this->_matchingLineItemCategoryCondition[$key] === false) {
                return false;
            }
        }

        $event = new MatchLineItemEvent(compact('lineItem', 'discount'));

        if ($this->hasEventHandlers(self::EVENT_DISCOUNT_MATCHES_LINE_ITEM)) {
            $this->trigger(self::EVENT_DISCOUNT_MATCHES_LINE_ITEM, $event);
        }

        return $event->isValid;
    }

    /**
     * @throws \Exception
     */
    public function matchOrder(Order $order, Discount $discount): bool
    {
        if (!$discount->enabled) {
            return false;
        }

        $allItemsMatch = ($discount->allPurchasables && $discount->allCategories);

        $orderCondition = $discount->getOrderCondition();
        $hasRules = count($orderCondition->getConditionRules());
        if ($hasRules && !$discount->getOrderCondition()->matchElement($order)) {
            return false;
        }

        $customerCondition = $discount->getCustomerCondition();
        $hasRules = count($customerCondition->getConditionRules());
        $customer = $order->getCustomer();
        if ($hasRules && !$customer) {
            return false;
        }
        if ($hasRules && $customer && !$customerCondition->matchElement($customer)) {
            return false;
        }

        $shippingAddressCondition = $discount->getShippingAddressCondition();
        $hasRules = count($shippingAddressCondition->getConditionRules());
        $shippingAddress = $order->getShippingAddress();
        if ($hasRules && !$shippingAddress) {
            return false;
        }
        if ($hasRules && $shippingAddress && !$shippingAddressCondition->matchElement($shippingAddress)) {
            return false;
        }

        $billingAddressCondition = $discount->getShippingAddressCondition();
        $hasRules = count($billingAddressCondition->getConditionRules());
        $billingAddress = $order->getShippingAddress();
        if ($hasRules && !$billingAddress) {
            return false;
        }
        if ($hasRules && $billingAddress && !$billingAddressCondition->matchElement($billingAddress)) {
            return false;
        }

        if (!$this->_isDiscountCouponCodeValid($order, $discount)) {
            return false;
        }

        if (!$this->_isDiscountDateValid($order, $discount)) {
            return false;
        }

        $user = $order->getCustomer();

        if (!$this->_isDiscountTotalUseLimitValid($discount)) {
            return false;
        }

        if (!$this->_isDiscountPerUserUsageValid($discount, $user)) {
            return false;
        }

        if (!$this->_isDiscountEmailRequirementValid($discount, $order)) {
            return false;
        }

        if (!$this->_isDiscountPerEmailLimitValid($discount, $order)) {
            return false;
        }

        if (!$this->_isDiscountConditionFormulaValid($order, $discount)) {
            return false;
        }

        if ($allItemsMatch && $discount->purchaseTotal > 0 && $order->getItemSubtotal() < $discount->purchaseTotal) {
            return false;
        }

        if ($allItemsMatch && $discount->purchaseQty > 0 && $order->getTotalQty() < $discount->purchaseQty) {
            return false;
        }

        if ($allItemsMatch && $discount->maxPurchaseQty > 0 && $order->getTotalQty() > $discount->maxPurchaseQty) {
            return false;
        }

        // Check to see if we need to match on data related to the lineItems
        if (!$discount->allPurchasables || !$discount->allCategories) {
            $lineItemMatch = false;
            $matchingTotal = 0;
            $matchingQty = 0;
            foreach ($order->getLineItems() as $lineItem) {
                // Must mot match order as we would get an infinate recursion
                if ($this->matchLineItem($lineItem, $discount)) {
                    $lineItemMatch = true;
                    $matchingTotal += $lineItem->getSubtotal();
                    $matchingQty += $lineItem->qty;
                }
            }

            if (!$lineItemMatch) {
                return false;
            }

            if ($discount->purchaseTotal > 0 && $matchingTotal < $discount->purchaseTotal) {
                return false;
            }

            if ($discount->purchaseQty > 0 && $matchingQty < $discount->purchaseQty) {
                return false;
            }

            if ($discount->maxPurchaseQty > 0 && $matchingQty > $discount->maxPurchaseQty) {
                return false;
            }
        }

        // Raise the 'beforeMatchLineItem' event
        $event = new MatchOrderEvent(compact('order', 'discount'));

        if ($this->hasEventHandlers(self::EVENT_DISCOUNT_MATCHES_ORDER)) {
            $this->trigger(self::EVENT_DISCOUNT_MATCHES_ORDER, $event);
        }

        return $event->isValid;
    }


    /**
     * Save a discount.
     *
     * @param Discount $model the discount being saved
     * @param bool $runValidation should we validate this discount before saving.
     * @throws \Exception
     */
    public function saveDiscount(Discount $model, bool $runValidation = true): bool
    {
        $isNew = !$model->id;

        if ($model->id) {
            $record = DiscountRecord::findOne($model->id);

            if (!$record) {
                throw new Exception(Craft::t('commerce', 'No discount exists with the ID “{id}”', ['id' => $model->id]));
            }
        } else {
            $record = new DiscountRecord();
        }

        // Raise the beforeSaveDiscount event
        if ($this->hasEventHandlers(self::EVENT_BEFORE_SAVE_DISCOUNT)) {
            $this->trigger(self::EVENT_BEFORE_SAVE_DISCOUNT, new DiscountEvent([
                'discount' => $model,
                'isNew' => $isNew,
            ]));
        }

        if ($runValidation && !$model->validate()) {
            Craft::info('Discount not saved due to validation error.', __METHOD__);

            return false;
        }

        $record->name = $model->name;
        $record->description = $model->description;
        $record->dateFrom = $model->dateFrom;
        $record->dateTo = $model->dateTo;
        $record->enabled = $model->enabled;
        $record->stopProcessing = $model->stopProcessing;
        $record->orderCondition = $model->getOrderCondition()->getConfig();
        $record->customerCondition = $model->getCustomerCondition()->getConfig();
        $record->shippingAddressCondition = $model->getShippingAddressCondition()->getConfig();
        $record->billingAddressCondition = $model->getBillingAddressCondition()->getConfig();
        $record->orderConditionFormula = $model->orderConditionFormula;
        $record->purchaseQty = $model->purchaseQty;
        $record->maxPurchaseQty = $model->maxPurchaseQty;
        $record->baseDiscount = $model->baseDiscount;
        $record->baseDiscountType = $model->baseDiscountType;
        $record->perItemDiscount = $model->perItemDiscount;
        $record->percentDiscount = $model->percentDiscount;
        $record->percentageOffSubject = $model->percentageOffSubject;
        $record->hasFreeShippingForMatchingItems = $model->hasFreeShippingForMatchingItems;
        $record->hasFreeShippingForOrder = $model->hasFreeShippingForOrder;
        $record->excludeOnSale = $model->excludeOnSale;
        $record->perUserLimit = $model->perUserLimit;
        $record->perEmailLimit = $model->perEmailLimit;
        $record->totalDiscountUseLimit = $model->totalDiscountUseLimit;
        $record->ignoreSales = $model->ignoreSales;
        $record->appliedTo = $model->appliedTo;

        $record->sortOrder = $record->sortOrder ?: 999;
        $record->couponFormat = $model->couponFormat;

        $record->categoryRelationshipType = $model->categoryRelationshipType;
        if ($record->allCategories = $model->allCategories) {
            $model->setCategoryIds([]);
        }
        if ($record->allPurchasables = $model->allPurchasables) {
            $model->setPurchasableIds([]);
        }

        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();

        try {
            $record->save(false);
            $model->id = $record->id;

            DiscountPurchasableRecord::deleteAll(['discountId' => $model->id]);
            DiscountCategoryRecord::deleteAll(['discountId' => $model->id]);

            foreach ($model->getCategoryIds() as $categoryId) {
                $relation = new DiscountCategoryRecord();
                $relation->categoryId = $categoryId;
                $relation->discountId = $model->id;
                $relation->save(false);
            }

            foreach ($model->getPurchasableIds() as $purchasableId) {
                $relation = new DiscountPurchasableRecord();
                $element = Craft::$app->getElements()->getElementById($purchasableId);
                $relation->purchasableType = get_class($element);
                $relation->purchasableId = $purchasableId;
                $relation->discountId = $model->id;
                $relation->save(false);
            }

            Plugin::getInstance()->getCoupons()->saveDiscountCoupons($model);
            $transaction->commit();

            // Raise the afterSaveDiscount event
            if ($this->hasEventHandlers(self::EVENT_AFTER_SAVE_DISCOUNT)) {
                $this->trigger(self::EVENT_AFTER_SAVE_DISCOUNT, new DiscountEvent([
                    'discount' => $model,
                    'isNew' => $isNew,
                ]));
            }

            // Reset internal cache
            $this->_allDiscounts = null;
            $this->_activeDiscountsByKey = null;
            $this->_matchingLineItemCategoryCondition = null;

            return true;
        } catch (\Exception $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    /**
     * Delete a discount by its ID.
     *
     * @throws Throwable
     * @throws StaleObjectException
     */
    public function deleteDiscountById(int $id): bool
    {
        $discountRecord = DiscountRecord::findOne($id);

        if (!$discountRecord) {
            return false;
        }

        // Get the Discount model before deletion to pass to the Event.
        $discount = $this->getDiscountById($id);

        $result = (bool)$discountRecord->delete();

        //Raise the afterDeleteDiscount event
        if ($result && $this->hasEventHandlers(self::EVENT_AFTER_DELETE_DISCOUNT)) {
            $this->trigger(self::EVENT_AFTER_DELETE_DISCOUNT, new DiscountEvent([
                'discount' => $discount,
                'isNew' => false,
            ]));
        }

        // Reset internal cache
        $this->_allDiscounts = null;
        $this->_activeDiscountsByKey = null;
        $this->_matchingLineItemCategoryCondition = null;

        return $result;
    }

    /**
     * @throws \yii\db\Exception
     * @since 4.0
     */
    public function clearCustomerUsageHistoryById(int $id): void
    {
        $db = Craft::$app->getDb();

        $db->createCommand()
            ->delete(Table::CUSTOMER_DISCOUNTUSES, ['discountId' => $id])
            ->execute();

        // Reset internal cache
        $this->_allDiscounts = null;
        $this->_activeDiscountsByKey = null;
    }

    /**
     * @throws \yii\db\Exception
     * @since 3.0
     */
    public function clearEmailUsageHistoryById(int $id): void
    {
        $db = Craft::$app->getDb();

        $db->createCommand()
            ->delete(Table::EMAIL_DISCOUNTUSES, ['discountId' => $id])
            ->execute();

        // Reset internal cache
        $this->_allDiscounts = null;
        $this->_activeDiscountsByKey = null;
    }

    /**
     * Clear total discount uses
     *
     * @throws \yii\db\Exception
     * @since 3.0
     */
    public function clearDiscountUsesById(int $id): void
    {
        $db = Craft::$app->getDb();
        $db->createCommand()
            ->update(Table::DISCOUNTS, ['totalDiscountUses' => 0], ['id' => $id])
            ->execute();

        // Reset internal cache
        $this->_allDiscounts = null;
        $this->_activeDiscountsByKey = null;
    }

    /**
     * Reorder discounts by an array of ids.
     *
     * @throws \yii\db\Exception
     */
    public function reorderDiscounts(array $ids): bool
    {
        foreach ($ids as $sortOrder => $id) {
            Craft::$app->getDb()->createCommand()
                ->update(Table::DISCOUNTS, ['sortOrder' => $sortOrder + 1], ['id' => $id])
                ->execute();
        }

        // Reset internal cache
        $this->_allDiscounts = null;
        $this->_activeDiscountsByKey = null;

        return true;
    }

    /**
     * Email usage stats for discount
     *
     * @return array return in the format ['uses' => int, 'emails' => int]
     */
    public function getEmailUsageStatsById(int $id): array
    {
        return (new Query())
            ->select(['COALESCE(SUM(uses), 0) as uses', 'COUNT(email) as emails'])
            ->from(Table::EMAIL_DISCOUNTUSES)
            ->where(['discountId' => $id])
            ->one();
    }

    /**
     * User usage stats for discount
     *
     * @param int $id
     * @return array in the format ['uses' => int, 'users' => int]
     */
    public function getCustomerUsageStatsById(int $id): array
    {
        return (new Query())
            ->select(['COALESCE(SUM(uses), 0) as uses', 'COUNT([[customerId]]) as users'])
            ->from(Table::CUSTOMER_DISCOUNTUSES)
            ->where(['[[discountId]]' => $id])
            ->one();
    }

    /**
     * Updates discount uses counters.
     *
     * @throws \yii\db\Exception
     */
    public function orderCompleteHandler(Order $order): void
    {
        $discountAdjustments = $order->getAdjustmentsByType(DiscountAdjuster::ADJUSTMENT_TYPE);

        if (empty($discountAdjustments)) {
            return;
        }

        /* We only need to make counter updates once for each discount. A discount
        might be returned multiple times due to it being a lineItem adjustment */
        $discounts = [];
        /** @var OrderAdjustment $discountAdjustment */
        foreach ($discountAdjustments as $discountAdjustment) {
            $snapshot = $discountAdjustment->sourceSnapshot ?? null;
            if (!$snapshot || !isset($snapshot['discountUseId']) || isset($discounts[$snapshot['discountUseId']])) {
                continue;
            }

            $discounts[$snapshot['discountUseId']] = $snapshot;
        }

        if (empty($discounts)) {
            return;
        }

        $user = $order->getCustomer();
        foreach ($discounts as $discount) {
            // Count if there was a user on this order that has authentication
            if ($user && $user->getIsCredentialed()) {
                $userDiscountUseRecord = CustomerDiscountUse::find()->where(['customerId' => $user->id, 'discountId' => $discount['discountUseId']])->one();

                if (!$userDiscountUseRecord) {
                    $userDiscountUseRecord = Craft::createObject(CustomerDiscountUse::class);
                    Craft::configure($userDiscountUseRecord, [
                        'customerId' => $user->id,
                        'discountId' => $discount['discountUseId'],
                        'uses' => 1,
                    ]);
                    $userDiscountUseRecord->save();
                } else {
                    Craft::$app->getDb()->createCommand()
                        ->update(Table::CUSTOMER_DISCOUNTUSES, [
                            'uses' => new Expression('[[uses]] + 1'),
                        ], [
                            'customerId' => $order->getCustomerId(),
                            'discountId' => $discount['discountUseId'],
                        ])
                        ->execute();
                }
            }

            // Count email usage
            $emailDiscountUseRecord = EmailDiscountUseRecord::find()->where(['email' => $order->getEmail(), 'discountId' => $discount['discountUseId']])->one();
            if (!$emailDiscountUseRecord) {
                $emailDiscountUseRecord = new EmailDiscountUseRecord();
                $emailDiscountUseRecord->email = $order->getEmail();
                $emailDiscountUseRecord->discountId = $discount['discountUseId'];
                $emailDiscountUseRecord->uses = 1;
                $emailDiscountUseRecord->save();
            } else {
                Craft::$app->getDb()->createCommand()
                    ->update(Table::EMAIL_DISCOUNTUSES, [
                        'uses' => new Expression('[[uses]] + 1'),
                    ], [
                        'email' => $order->getEmail(),
                        'discountId' => $discount['discountUseId'],
                    ])
                    ->execute();
            }

            // Update the total uses
            Craft::$app->getDb()->createCommand()
                ->update(Table::DISCOUNTS, [
                    'totalDiscountUses' => new Expression('[[totalDiscountUses]] + 1'),
                ], [
                    'id' => $discount['discountUseId'],
                ])
                ->execute();

            // if there was a coupon on the order update its usage
            if ($order->couponCode && $coupon = CouponRecord::findOne(['code' => $order->couponCode, 'discountId' => $discount['discountUseId']])) {
                Craft::$app->getDb()->createCommand()
                    ->update(Table::COUPONS, [
                        'uses' => new Expression('[[uses]] + 1'),
                    ], [
                        'id' => $coupon->id,
                    ])
                    ->execute();
            }

            // Reset internal cache
            $this->_allDiscounts = null;
            $this->_activeDiscountsByKey = null;
        }
    }


    /**
     * @param Order $order
     * @param Discount $discount
     * @return bool
     * @throws InvalidConfigException
     */
    private function _isDiscountCouponCodeValid(Order $order, Discount $discount): bool
    {
        $coupons = $discount->getCoupons();
        if (empty($coupons)) {
            return true;
        }

        $return = ArrayHelper::firstWhere($coupons, static fn(Coupon $coupon) => (strcasecmp($coupon->code, $order->couponCode) == 0) && ($coupon->maxUses === null || $coupon->maxUses > $coupon->uses));
        return (bool)$return;
    }

    /**
     * @throws \Exception
     */
    private function _isDiscountDateValid(Order $order, Discount $discount): bool
    {
        $now = new DateTime();

        if ($order->isCompleted && $order->dateOrdered) {
            $now = $order->dateOrdered;
        }

        $from = $discount->dateFrom;
        $to = $discount->dateTo;

        return !(($from && $from > $now) || ($to && $to < $now));
    }

    /**
     * @throws InvalidConfigException
     * @throws LoaderError
     * @throws SyntaxError
     */
    private function _isDiscountConditionFormulaValid(Order $order, Discount $discount): bool
    {
        if ($discount->orderConditionFormula) {
            $fieldsAsArray = $order->getSerializedFieldValues();
            $orderAsArray = $order->toArray([], ['lineItems.snapshot', 'shippingAddress', 'billingAddress']);
            $orderConditionParams = [
                'order' => array_merge($orderAsArray, $fieldsAsArray),
            ];
            return Plugin::getInstance()->getFormulas()->evaluateCondition($discount->orderConditionFormula, $orderConditionParams, 'Evaluate Order Discount Condition Formula');
        }

        return true;
    }

    private function _isDiscountTotalUseLimitValid(Discount $discount): bool
    {
        if ($discount->totalDiscountUseLimit > 0) {
            if ($discount->totalDiscountUses >= $discount->totalDiscountUseLimit) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param Discount $discount
     * @param User|null $user
     * @return bool
     */
    private function _isDiscountPerUserUsageValid(Discount $discount, ?User $user): bool
    {
        if ($discount->perUserLimit > 0) {
            if (!$user) {
                return false;
            }

            $usage = (new Query())
                ->select(['uses'])
                ->from([Table::CUSTOMER_DISCOUNTUSES])
                ->where(['[[customerId]]' => $user->id, 'discountId' => $discount->id])
                ->scalar();

            if ($usage && $usage >= $discount->perUserLimit) {
                return false;
            }
        }

        return true;
    }

    private function _isDiscountEmailRequirementValid(Discount $discount, Order $order): bool
    {
        if ($discount->perEmailLimit > 0 && !$order->getEmail()) {
            return false;
        }

        return true;
    }

    private function _isDiscountPerEmailLimitValid(Discount $discount, Order $order): bool
    {
        if ($discount->perEmailLimit > 0 && $order->getEmail()) {
            $usage = (new Query())
                ->select(['uses'])
                ->from([Table::EMAIL_DISCOUNTUSES])
                ->where(['email' => $order->getEmail(), 'discountId' => $discount->id])
                ->scalar();

            if ($usage && $usage >= $discount->perEmailLimit) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param $discounts
     * @return array
     * @since 2.2.14
     */
    private function _populateDiscounts($discounts): array
    {
        $allDiscountsById = [];

        if (empty($discounts)) {
            return $allDiscountsById;
        }

        $purchasables = [];
        $categories = [];

        foreach ($discounts as $discount) {
            $id = $discount['id'];
            if ($discount['purchasableId']) {
                $purchasables[$id][] = $discount['purchasableId'];
            }

            if ($discount['categoryId']) {
                $categories[$id][] = $discount['categoryId'];
            }

            unset($discount['purchasableId'], $discount['categoryId']);

            if (!isset($allDiscountsById[$id])) {
                $allDiscountsById[$id] = new Discount($discount);
            }
        }

        foreach ($allDiscountsById as $id => $discount) {
            $discount->setPurchasableIds($purchasables[$id] ?? []);
            $discount->setCategoryIds($categories[$id] ?? []);
        }

        return $allDiscountsById;
    }

    /**
     * Returns a Query object prepped for retrieving discounts
     */
    private function _createDiscountQuery(): Query
    {
        $query = (new Query())
            ->select([
                '[[discounts.allCategories]]',
                '[[discounts.allPurchasables]]',
                '[[discounts.appliedTo]]',
                '[[discounts.baseDiscount]]',
                '[[discounts.baseDiscountType]]',
                '[[discounts.categoryRelationshipType]]',
                '[[discounts.couponFormat]]',
                '[[discounts.dateCreated]]',
                '[[discounts.dateFrom]]',
                '[[discounts.dateTo]]',
                '[[discounts.dateUpdated]]',
                '[[discounts.description]]',
                '[[discounts.enabled]]',
                '[[discounts.excludeOnSale]]',
                '[[discounts.hasFreeShippingForMatchingItems]]',
                '[[discounts.hasFreeShippingForOrder]]',
                '[[discounts.id]]',
                '[[discounts.ignoreSales]]',
                '[[discounts.maxPurchaseQty]]',
                '[[discounts.name]]',
                '[[discounts.orderCondition]]',
                '[[discounts.orderConditionFormula]]',
                '[[discounts.percentageOffSubject]]',
                '[[discounts.percentDiscount]]',
                '[[discounts.perEmailLimit]]',
                '[[discounts.perItemDiscount]]',
                '[[discounts.perUserLimit]]',
                '[[discounts.purchaseQty]]',
                '[[discounts.sortOrder]]',
                '[[discounts.stopProcessing]]',
                '[[discounts.totalDiscountUseLimit]]',
                '[[discounts.totalDiscountUses]]',
                '[[discounts.customerCondition]]',
                '[[discounts.shippingAddressCondition]]',
                '[[discounts.billingAddressCondition]]',
            ])
            ->from(['discounts' => Table::DISCOUNTS])
            ->orderBy(['sortOrder' => SORT_ASC]);

        $query->addSelect([
            'dp.purchasableId',
            'dpt.categoryId',
        ])->leftJoin(Table::DISCOUNT_PURCHASABLES . ' dp', '[[dp.discountId]]=[[discounts.id]]')
            ->leftJoin(Table::DISCOUNT_CATEGORIES . ' dpt', '[[dpt.discountId]]=[[discounts.id]]');

        return $query;
    }
}
