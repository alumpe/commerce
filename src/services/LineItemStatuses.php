<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\commerce\services;

use Craft;
use craft\commerce\db\Table;
use craft\commerce\events\DefaultLineItemStatusEvent;
use craft\commerce\models\LineItem;
use craft\commerce\models\LineItemStatus;
use craft\commerce\records\LineItemStatus as LineItemStatusRecord;
use craft\db\Query;
use craft\events\ConfigEvent;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use Throwable;
use yii\base\Component;
use yii\base\ErrorException;
use yii\base\Exception;
use yii\base\NotSupportedException;
use yii\web\ServerErrorHttpException;

/**
 * Order status service.
 *
 * @property LineItemStatus|null $defaultLineItemStatus default line item status from the DB
 * @property LineItemStatus[]|array $allLineItemStatuses all Order Statuses
 * @property null|int $defaultLineItemStatusId default line item status ID from the DB
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 2.0
 */
class LineItemStatuses extends Component
{
    /**
     * @event DefaultLineItemStatusEvent The event that is triggered when getting a default status for a line item.
     * You may set [[DefaultLineItemStatusEvent::lineItemStatus]] to a desired LineItemStatus to override the default status set in CP
     *
     * Plugins can get notified when a default line item status is being fetched
     *
     * ```php
     * use craft\commerce\events\DefaultLineItemStatusEvent;
     * use craft\commerce\services\LineItemStatuses;
     * use yii\base\Event;
     *
     * Event::on(LineItemStatuses::class, LineItemStatuses::EVENT_DEFAULT_LINE_ITEM_STATUS, function(DefaultLineItemStatusEvent $e) {
     *     // Do something - perhaps figure out a better default line item status than the one set in CP
     * });
     * ```
     */
    public const EVENT_DEFAULT_LINE_ITEM_STATUS = 'defaultLineItemStatus';

    public const CONFIG_STATUSES_KEY = 'commerce.lineItemStatuses';

    /**
     * @var bool
     */
    private bool $_fetchedAllStatuses = false;

    /**
     * @var LineItemStatus[]
     */
    private array $_lineItemStatusesById = [];

    /**
     * @var LineItemStatus[]
     */
    private array $_lineItemStatusesByHandle = [];

    /**
     * @var LineItemStatus|null
     */
    private ?LineItemStatus $_defaultLineItemStatus = null;

    /**
     * Get line item status by its handle.
     */
    public function getLineItemStatusByHandle(string $handle): ?LineItemStatus
    {
        if (isset($this->_lineItemStatusesByHandle[$handle])) {
            return $this->_lineItemStatusesByHandle[$handle];
        }

        if ($this->_fetchedAllStatuses) {
            return null;
        }

        $result = $this->_createLineItemStatusesQuery()
            ->andWhere(['handle' => $handle])
            ->one();

        if (!$result) {
            return null;
        }

        $this->_memoizeLineItemStatus(new LineItemStatus($result));

        return $this->_lineItemStatusesByHandle[$handle];
    }

    /**
     * Get default lineItem status ID from the DB
     *
     * @noinspection PhpUnused
     */
    public function getDefaultLineItemStatusId(): ?int
    {
        $defaultStatus = $this->getDefaultLineItemStatus();

        if ($defaultStatus && $defaultStatus->id) {
            return $defaultStatus->id;
        }

        return null;
    }

    /**
     * Get default lineItem status from the DB
     */
    public function getDefaultLineItemStatus(): ?LineItemStatus
    {
        if ($this->_defaultLineItemStatus !== null) {
            return $this->_defaultLineItemStatus;
        }

        $result = $this->_createLineItemStatusesQuery()
            ->andWhere(['default' => true])
            ->one();

        if ($result) {
            $this->_defaultLineItemStatus = new LineItemStatus($result);
        }

        return $this->_defaultLineItemStatus;
    }

    /**
     * Get the default lineItem status for a particular lineItem. Defaults to the CP configured default lineItem status.
     */
    public function getDefaultLineItemStatusForLineItem(LineItem $lineItem): ?LineItemStatus
    {
        $lineItemStatus = $this->getDefaultLineItemStatus();

        $event = new DefaultLineItemStatusEvent();
        $event->lineItemStatus = $lineItemStatus;
        $event->lineItem = $lineItem;

        $this->trigger(self::EVENT_DEFAULT_LINE_ITEM_STATUS, $event);

        return $event->lineItemStatus;
    }

    /**
     * Save the line item status.
     *
     * @param bool $runValidation should we validate this line item status before saving.
     * @throws Exception
     * @throws ErrorException
     */
    public function saveLineItemStatus(LineItemStatus $lineItemStatus, bool $runValidation = true): bool
    {
        $isNewStatus = !(bool)$lineItemStatus->id;

        if ($runValidation && !$lineItemStatus->validate()) {
            Craft::info('Line item status not saved due to validation error.', __METHOD__);

            return false;
        }

        if ($isNewStatus) {
            $statusUid = StringHelper::UUID();
        } else {
            $statusUid = Db::uidById(Table::LINEITEMSTATUSES, $lineItemStatus->id);
        }

        // Make sure no statuses that are not archived share the handle
        $existingStatus = $this->getLineItemStatusByHandle($lineItemStatus->handle);

        if ($existingStatus && (!$lineItemStatus->id || $lineItemStatus->id !== $existingStatus->id)) {
            $lineItemStatus->addError('handle', Craft::t('commerce', 'That handle is already in use'));
            return false;
        }

        $projectConfig = Craft::$app->getProjectConfig();

        if ($lineItemStatus->isArchived) {
            $configData = null;
        } else {
            $configData = $lineItemStatus->getConfig();
        }

        $configPath = self::CONFIG_STATUSES_KEY . '.' . $statusUid;
        $projectConfig->set($configPath, $configData);

        if ($isNewStatus) {
            $lineItemStatus->id = Db::idByUid(Table::LINEITEMSTATUSES, $statusUid);
        }

        $this->_clearCaches();

        return true;
    }

    /**
     * Handle line item status change.
     *
     * @throws Throwable if reasons
     */
    public function handleChangedLineItemStatus(ConfigEvent $event): void
    {
        $statusUid = $event->tokenMatches[0];
        $data = $event->newValue;

        $transaction = Craft::$app->getDb()->beginTransaction();
        try {
            $statusRecord = $this->_getLineItemStatusRecord($statusUid);

            $statusRecord->name = $data['name'];
            $statusRecord->handle = $data['handle'];
            $statusRecord->color = $data['color'];
            $statusRecord->sortOrder = $data['sortOrder'] ?? 99;
            $statusRecord->default = $data['default'];
            $statusRecord->uid = $statusUid;

            // Save the volume
            $statusRecord->save(false);

            if ($statusRecord->default) {
                LineItemStatusRecord::updateAll(['default' => 0], ['not', ['id' => $statusRecord->id]]);
            }

            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    /**
     * Archive an line item status by it's id.
     *
     * @throws Throwable
     */
    public function archiveLineItemStatusById(int $id): bool
    {
        $status = $this->getLineItemStatusById($id);
        if ($status) {
            $status->isArchived = true;
            return $this->saveLineItemStatus($status);
        }
        return false;
    }


    /**
     * Handle line item status being archived
     *
     * @throws Throwable if reasons
     */
    public function handleArchivedLineItemStatus(ConfigEvent $event): void
    {
        $lineItemStatusUid = $event->tokenMatches[0];

        $transaction = Craft::$app->getDb()->beginTransaction();
        try {
            $lineItemStatusRecord = $this->_getLineItemStatusRecord($lineItemStatusUid);

            $lineItemStatusRecord->isArchived = true;
            $lineItemStatusRecord->dateArchived = Db::prepareDateForDb(new DateTime());

            // Save the volume
            $lineItemStatusRecord->save(false);

            $transaction->commit();

            $this->_clearCaches();
        } catch (Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    /**
     * Returns all Order Statuses
     *
     * @return LineItemStatus[]
     */
    public function getAllLineItemStatuses(): array
    {
        if (!$this->_fetchedAllStatuses) {
            $results = $this->_createLineItemStatusesQuery()->all();

            foreach ($results as $row) {
                $status = new LineItemStatus($row);
                $this->_memoizeLineItemStatus($status);
            }

            $this->_fetchedAllStatuses = true;
        }

        return $this->_lineItemStatusesById;
    }

    /**
     * Get a line item status by ID
     */
    public function getLineItemStatusById(int $id): ?LineItemStatus
    {
        if (isset($this->_lineItemStatusesById[$id])) {
            return $this->_lineItemStatusesById[$id];
        }

        if ($this->_fetchedAllStatuses) {
            return null;
        }

        $result = $this->_createLineItemStatusesQuery()
            ->andWhere(['id' => $id])
            ->one();

        if (!$result) {
            return null;
        }

        $this->_memoizeLineItemStatus(new LineItemStatus($result));

        return $this->_lineItemStatusesById[$id];
    }

    /**
     * Reorders the line item statuses.
     *
     * @throws Exception
     * @throws ErrorException
     * @throws NotSupportedException
     * @throws ServerErrorHttpException
     */
    public function reorderLineItemStatuses(array $ids): bool
    {
        $projectConfig = Craft::$app->getProjectConfig();

        $uidsByIds = Db::uidsByIds(Table::LINEITEMSTATUSES, $ids);

        foreach ($ids as $lineItemStatus => $statusId) {
            if (!empty($uidsByIds[$statusId])) {
                $statusUid = $uidsByIds[$statusId];
                $projectConfig->set(self::CONFIG_STATUSES_KEY . '.' . $statusUid . '.sortOrder', $lineItemStatus + 1);
            }
        }

        $this->_clearCaches();

        return true;
    }


    /**
     * Memoize an line item status by its ID and handle.
     */
    private function _memoizeLineItemStatus(LineItemStatus $lineItemStatus): void
    {
        $this->_lineItemStatusesById[$lineItemStatus->id] = $lineItemStatus;
        $this->_lineItemStatusesByHandle[$lineItemStatus->handle] = $lineItemStatus;
    }

    /**
     * Returns a Query object prepped for retrieving line item statuses
     */
    private function _createLineItemStatusesQuery(): Query
    {
        return (new Query())
            ->select([
                'color',
                'default',
                'handle',
                'id',
                'name',
                'sortOrder',
                'uid',
            ])
            ->where(['isArchived' => false])
            ->orderBy('sortOrder')
            ->from([Table::LINEITEMSTATUSES]);
    }

    /**
     * Gets an lineitem status' record by uid.
     */
    private function _getLineItemStatusRecord(string $uid): LineItemStatusRecord
    {
        if ($lineItemStatus = LineItemStatusRecord::findOne(['uid' => $uid])) {
            return $lineItemStatus;
        }

        return new LineItemStatusRecord();
    }

    /**
     * Clear all memoization
     *
     * @since 3.2.5
     */
    public function _clearCaches(): void
    {
        $this->_defaultLineItemStatus = null;
        $this->_fetchedAllStatuses = false;
        $this->_lineItemStatusesById = [];
        $this->_lineItemStatusesByHandle = [];
    }
}
