<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\commerce\base;

use craft\base\ElementInterface;
use craft\commerce\elements\Subscription;
use craft\commerce\Plugin as Commerce;
use craft\commerce\records\Plan as PlanRecord;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\Json;
use craft\validators\UniqueValidator;
use DateTime;
use yii\base\InvalidConfigException;
use function count;

/**
 * Plan model
 *
 * @property GatewayInterface $gateway
 * @property Entry|null $information
 * @property int $subscriptionCount
 * @property User $user
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 2.0
 */
abstract class Plan extends Model implements PlanInterface
{
    use PlanTrait;

    /**
     * @var SubscriptionGatewayInterface|null the gateway
     */
    private ?SubscriptionGatewayInterface $_gateway = null;

    /**
     * @var mixed the plan data.
     */
    private mixed $_data = null;

    /**
     * @var DateTime|null
     * @since 3.4
     */
    public ?DateTime $dateCreated = null;

    /**
     * @var DateTime|null
     * @since 3.4
     */
    public ?DateTime $dateUpdated = null;

    /**
     * Returns the billing plan friendly name
     *
     * @return string
     */
    public function __toString()
    {
        return (string)$this->name;
    }

    /**
     * Returns the gateway for this subscription plan.
     *
     * @throws InvalidConfigException if gateway does not support subscriptions
     */
    public function getGateway(): ?SubscriptionGatewayInterface
    {
        if (!isset($this->_gateway)) {
            $this->_gateway = Commerce::getInstance()->getGateways()->getGatewayById($this->gatewayId);
        }

        if ($this->_gateway && !$this->_gateway instanceof SubscriptionGatewayInterface) {
            throw new InvalidConfigException('This gateway does not support subscriptions');
        }

        return $this->_gateway;
    }

    /**
     * Returns the stored plan data.
     *
     * @return mixed
     */
    public function getPlanData(): mixed
    {
        if ($this->_data === null) {
            $this->_data = Json::decodeIfJson($this->planData);
        }

        return $this->_data;
    }

    /**
     * Returns the plan's related Entry element, if any.
     */
    public function getInformation(): ?Entry
    {
        if ($this->planInformationId) {
            return Entry::find()->id($this->planInformationId)->one();
        }

        return null;
    }

    /**
     * Returns the subscription count for this plan.
     */
    public function getSubscriptionCount(): int
    {
        return Commerce::getInstance()->getSubscriptions()->getSubscriptionCountByPlanId($this->id);
    }

    /**
     * Returns whether there exists an active subscription for this plan for this user.
     */
    public function hasActiveSubscription(int $userId): bool
    {
        return (bool)count($this->getActiveUserSubscriptions($userId));
    }

    /**
     * Returns active subscriptions for this plan by user id.
     *
     * @param int $userId the user id
     * @return ElementInterface[]
     */
    public function getActiveUserSubscriptions(int $userId): array
    {
        return Subscription::find()
            ->userId($userId)
            ->planId($this->id)
            ->status(Subscription::STATUS_ACTIVE)
            ->all();
    }

    /**
     * Returns all subscriptions for this plan by user id, including expired subscriptions.
     *
     * @param int $userId the user id
     * @return ElementInterface[]
     */
    public function getAllUserSubscriptions(int $userId): array
    {
        return Subscription::find()
            ->userId($userId)
            ->planId($this->id)
            ->status(null)
            ->all();
    }

    /**
     * @inheritdoc
     */
    public function rules(): array
    {
        return [
            [
                ['handle'],
                UniqueValidator::class,
                'targetClass' => PlanRecord::class,
                'targetAttribute' => ['handle'],
            ],
            [['gatewayId', 'reference', 'name', 'handle', 'planData'], 'required'],
        ];
    }
}
