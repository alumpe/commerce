<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\commerce\services;

use Craft;
use craft\base\Field;
use craft\commerce\db\Table;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\errors\ProductTypeNotFoundException;
use craft\commerce\events\ProductTypeEvent;
use craft\commerce\models\ProductType;
use craft\commerce\models\ProductTypeSite;
use craft\commerce\Plugin;
use craft\commerce\records\ProductType as ProductTypeRecord;
use craft\commerce\records\ProductTypeSite as ProductTypeSiteRecord;
use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\elements\User;
use craft\events\ConfigEvent;
use craft\events\DeleteSiteEvent;
use craft\events\SiteEvent;
use craft\helpers\App;
use craft\helpers\ArrayHelper;
use craft\helpers\Db;
use craft\helpers\ProjectConfig as ProjectConfigHelper;
use craft\helpers\StringHelper;
use craft\models\FieldLayout;
use craft\queue\jobs\ResaveElements;
use Throwable;
use yii\base\Component;
use yii\base\ErrorException;
use yii\base\Exception;
use yii\base\InvalidConfigException;
use yii\base\NotSupportedException;
use yii\web\ServerErrorHttpException;

/**
 * Product type service.
 *
 * @property array|ProductType[] $allProductTypes all product types
 * @property array $allProductTypeIds all of the product type IDs
 * @property array|ProductType[] $editableProductTypes all editable product types
 * @property array $editableProductTypeIds all of the product type IDs that are editable by the current user
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 2.0
 */
class ProductTypes extends Component
{
    /**
     * @event ProductTypeEvent The event that is triggered before a product type is saved.
     *
     * ```php
     * use craft\commerce\events\ProductTypeEvent;
     * use craft\commerce\services\ProductTypes;
     * use craft\commerce\models\ProductType;
     * use yii\base\Event;
     *
     * Event::on(
     *     ProductTypes::class,
     *     ProductTypes::EVENT_BEFORE_SAVE_PRODUCTTYPE,
     *     function(ProductTypeEvent $event) {
     *         // @var ProductType|null $productType
     *         $productType = $event->productType;
     *
     *         // Create an audit trail of this action
     *         // ...
     *     }
     * );
     * ```
     */
    public const EVENT_BEFORE_SAVE_PRODUCTTYPE = 'beforeSaveProductType';

    /**
     * @event ProductTypeEvent The event that is triggered after a product type has been saved.
     *
     * ```php
     * use craft\commerce\events\ProductTypeEvent;
     * use craft\commerce\services\ProductTypes;
     * use craft\commerce\models\ProductType;
     * use yii\base\Event;
     *
     * Event::on(
     *     ProductTypes::class,
     *     ProductTypes::EVENT_AFTER_SAVE_PRODUCTTYPE,
     *     function(ProductTypeEvent $event) {
     *         // @var ProductType|null $productType
     *         $productType = $event->productType;
     *
     *         // Prepare some third party system for a new product type
     *         // ...
     *     }
     * );
     * ```
     */
    public const EVENT_AFTER_SAVE_PRODUCTTYPE = 'afterSaveProductType';

    public const CONFIG_PRODUCTTYPES_KEY = 'commerce.productTypes';

    /**
     * @var bool
     */
    private bool $_fetchedAllProductTypes = false;

    /**
     * @var ProductType[]|null
     */
    private ?array $_productTypesById = null;

    /**
     * @var ProductType[]|null
     */
    private ?array $_productTypesByHandle = null;

    /**
     * @var int[]|null
     */
    private ?array $_allProductTypeIds = null;

    /**
     * @var int[]|null
     */
    private ?array $_editableProductTypeIds = null;

    /**
     * @var int[]|null
     */
    private ?array $_creatableProductTypeIds = null;

    /**
     * @var ProductTypeSite[][]
     */
    private array $_siteSettingsByProductId = [];

    /**
     * @var array interim storage for product types being saved via CP
     */
    private array $_savingProductTypes = [];


    /**
     * Returns all editable product types.
     *
     * @return ProductType[] An array of all the editable product types.
     */
    public function getEditableProductTypes(): array
    {
        $editableProductTypeIds = $this->getEditableProductTypeIds();
        $editableProductTypes = [];

        foreach ($this->getAllProductTypes() as $productTypes) {
            if (in_array($productTypes->id, $editableProductTypeIds, false)) {
                $editableProductTypes[] = $productTypes;
            }
        }

        return $editableProductTypes;
    }

    /**
     * Returns all product type IDs that are editable by the current user.
     *
     * @return array An array of all the editable product types’ IDs.
     */
    public function getEditableProductTypeIds(): array
    {
        if (!isset($this->_editableProductTypeIds)) {
            $this->_editableProductTypeIds = [];
            $allProductTypes = $this->getAllProductTypes();

            $user = Craft::$app->getUser()->getIdentity();
            foreach ($allProductTypes as $productType) {
                if (Plugin::getInstance()->getProductTypes()->hasPermission($user, $productType, 'commerce-editProductType')) {
                    $this->_editableProductTypeIds[] = $productType->id;
                }
            }
        }

        return $this->_editableProductTypeIds;
    }

    /**
     * Returns all product type IDs that are creatable by the current user.
     *
     * @return array
     * @throws InvalidConfigException
     */
    public function getCreatableProductTypeIds(): array
    {
        if (null === $this->_creatableProductTypeIds) {
            $this->_creatableProductTypeIds = [];
            $allProductTypes = $this->getAllProductTypes();

            $user = Craft::$app->getUser()->getIdentity();

            foreach ($allProductTypes as $productType) {
                if (Plugin::getInstance()->getProductTypes()->hasPermission($user, $productType, 'commerce-createProducts')) {
                    $this->_creatableProductTypeIds[] = $productType->id;
                }
            }
        }

        return $this->_creatableProductTypeIds;
    }

    /**
     * Returns all creatable product types.
     * @return array
     * @throws InvalidConfigException
     */
    public function getCreatableProductTypes(): array
    {
        $creatableProductTypeIds = $this->getCreatableProductTypeIds();
        $creatableProductTypes = [];

        foreach ($this->getAllProductTypes() as $productTypes) {
            if (in_array($productTypes->id, $creatableProductTypeIds, false)) {
                $creatableProductTypes[] = $productTypes;
            }
        }

        return $creatableProductTypes;
    }

    /**
     * Returns all the product type IDs.
     *
     * @return array An array of all the product types’ IDs.
     */
    public function getAllProductTypeIds(): array
    {
        if (!isset($this->_allProductTypeIds)) {
            $this->_allProductTypeIds = [];
            $productTypes = $this->getAllProductTypes();

            foreach ($productTypes as $productType) {
                $this->_allProductTypeIds[] = $productType->id;
            }
        }

        return $this->_allProductTypeIds;
    }

    /**
     * Returns all product types.
     *
     * @return ProductType[] An array of all product types.
     */
    public function getAllProductTypes(): array
    {
        if (!$this->_fetchedAllProductTypes) {
            $results = $this->_createProductTypeQuery()->all();

            foreach ($results as $result) {
                $this->_memoizeProductType(new ProductType($result));
            }

            $this->_fetchedAllProductTypes = true;
        }

        return $this->_productTypesById ?: [];
    }

    /**
     * Returns a product type by its handle.
     *
     * @param string $handle The product type's handle.
     * @return ProductType|null The product type or `null`.
     */
    public function getProductTypeByHandle(string $handle): ?ProductType
    {
        if (isset($this->_productTypesByHandle[$handle])) {
            return $this->_productTypesByHandle[$handle];
        }

        if ($this->_fetchedAllProductTypes) {
            return null;
        }

        $result = $this->_createProductTypeQuery()
            ->where(['handle' => $handle])
            ->one();

        if (!$result) {
            return null;
        }

        $this->_memoizeProductType(new ProductType($result));

        return $this->_productTypesByHandle[$handle];
    }

    /**
     * Returns an array of product type site settings for a product type by its ID.
     *
     * @param int $productTypeId the product type ID
     * @return array The product type settings.
     */
    public function getProductTypeSites(int $productTypeId): array
    {
        if (!isset($this->_siteSettingsByProductId[$productTypeId])) {
            $rows = (new Query())
                ->select([
                    'hasUrls',
                    'id',
                    'productTypeId',
                    'siteId',
                    'template',
                    'uriFormat',
                ])
                ->from(Table::PRODUCTTYPES_SITES)
                ->where(['productTypeId' => $productTypeId])
                ->all();

            $this->_siteSettingsByProductId[$productTypeId] = [];

            foreach ($rows as $row) {
                $this->_siteSettingsByProductId[$productTypeId][] = new ProductTypeSite($row);
            }
        }

        return $this->_siteSettingsByProductId[$productTypeId];
    }

    /**
     * Saves a product type.
     *
     * @param ProductType $productType The product type model.
     * @param bool $runValidation If validation should be ran.
     * @return bool Whether the product type was saved successfully.
     * @throws Throwable if reasons
     */
    public function saveProductType(ProductType $productType, bool $runValidation = true): bool
    {
        $isNewProductType = !$productType->id;

        // Fire a 'beforeSaveProductType' event
        if ($this->hasEventHandlers(self::EVENT_BEFORE_SAVE_PRODUCTTYPE)) {
            $this->trigger(self::EVENT_BEFORE_SAVE_PRODUCTTYPE, new ProductTypeEvent([
                'productType' => $productType,
                'isNew' => $isNewProductType,
            ]));
        }

        if ($runValidation && !$productType->validate()) {
            Craft::info('Product type not saved due to validation error.', __METHOD__);

            return false;
        }

        if ($isNewProductType) {
            $productType->uid = StringHelper::UUID();
        } else {
            /** @var ProductTypeRecord|null $existingProductTypeRecord */
            $existingProductTypeRecord = ProductTypeRecord::find()
                ->where(['id' => $productType->id])
                ->one();

            if (!$existingProductTypeRecord) {
                throw new ProductTypeNotFoundException("No product type exists with the ID '$productType->id'");
            }

            $productType->uid = $existingProductTypeRecord->uid;
        }

        $this->_savingProductTypes[$productType->uid] = $productType;

        // If the product type does not have variants, default the title format.
        if (!$isNewProductType && !$productType->hasVariants) {
            $productType->hasVariantTitleField = false;
            $productType->variantTitleFormat = '{product.title}';
        }

        $projectConfig = Craft::$app->getProjectConfig();
        $configData = [
            'name' => $productType->name,
            'handle' => $productType->handle,
            'hasDimensions' => $productType->hasDimensions,
            'hasVariants' => $productType->hasVariants,

            // Variant title field
            'hasVariantTitleField' => $productType->hasVariantTitleField,
            'variantTitleFormat' => $productType->variantTitleFormat,

            // Prouduct title field
            'hasProductTitleField' => $productType->hasProductTitleField,
            'productTitleFormat' => $productType->productTitleFormat,

            'skuFormat' => $productType->skuFormat,
            'descriptionFormat' => $productType->descriptionFormat,
            'siteSettings' => [],
        ];

        $generateLayoutConfig = function(FieldLayout $fieldLayout): array {
            $fieldLayoutConfig = $fieldLayout->getConfig();

            if ($fieldLayoutConfig) {
                if (empty($fieldLayout->id)) {
                    $layoutUid = StringHelper::UUID();
                    $fieldLayout->uid = $layoutUid;
                } else {
                    $layoutUid = Db::uidById('{{%fieldlayouts}}', $fieldLayout->id);
                }

                return [$layoutUid => $fieldLayoutConfig];
            }

            return [];
        };

        $configData['productFieldLayouts'] = $generateLayoutConfig($productType->getFieldLayout());
        $configData['variantFieldLayouts'] = [];
        if ($productType->hasVariants) {
            $configData['variantFieldLayouts'] = $generateLayoutConfig($productType->getVariantFieldLayout());
        }


        // Get the site settings
        $allSiteSettings = $productType->getSiteSettings();

        // Make sure they're all there
        foreach (Craft::$app->getSites()->getAllSiteIds() as $siteId) {
            if (!isset($allSiteSettings[$siteId])) {
                throw new Exception('Tried to save a product type that is missing site settings');
            }
        }

        foreach ($allSiteSettings as $siteId => $settings) {
            $siteUid = Db::uidById(CraftTable::SITES, $siteId);
            $configData['siteSettings'][$siteUid] = [
                'hasUrls' => $settings['hasUrls'],
                'uriFormat' => $settings['uriFormat'],
                'template' => $settings['template'],
            ];
        }

        $configPath = self::CONFIG_PRODUCTTYPES_KEY . '.' . $productType->uid;
        $projectConfig->set($configPath, $configData);

        if ($isNewProductType) {
            $productType->id = Db::idByUid(Table::PRODUCTTYPES, $productType->uid);
        }

        return true;
    }

    /**
     * Handle a product type change.
     *
     * @throws Throwable if reasons
     */
    public function handleChangedProductType(ConfigEvent $event): void
    {
        $productTypeUid = $event->tokenMatches[0];
        $data = $event->newValue;
        $shouldResaveProducts = false;

        // Make sure fields and sites are processed
        ProjectConfigHelper::ensureAllSitesProcessed();
        ProjectConfigHelper::ensureAllFieldsProcessed();

        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();

        try {
            $siteData = $data['siteSettings'];

            // Basic data
            $productTypeRecord = $this->_getProductTypeRecord($productTypeUid);
            $isNewProductType = $productTypeRecord->getIsNewRecord();
            $fieldsService = Craft::$app->getFields();

            $productTypeRecord->uid = $productTypeUid;
            $productTypeRecord->name = $data['name'];
            $productTypeRecord->handle = $data['handle'];
            $productTypeRecord->hasDimensions = $data['hasDimensions'];

            // Variant title fields
            $hasVariantTitleField = $data['hasVariantTitleField'];
            $variantTitleFormat = $data['variantTitleFormat'] ?? '{product.title}';
            if ($productTypeRecord->variantTitleFormat != $variantTitleFormat || $productTypeRecord->hasVariantTitleField != $hasVariantTitleField) {
                $shouldResaveProducts = true;
            }
            $productTypeRecord->variantTitleFormat = $variantTitleFormat;
            $productTypeRecord->hasVariantTitleField = $hasVariantTitleField;

            // Product title fields
            $hasProductTitleField = $data['hasProductTitleField'];
            $productTitleFormat = $data['productTitleFormat'] ?? 'Title';
            if ($productTypeRecord->productTitleFormat != $productTitleFormat || $productTypeRecord->hasProductTitleField != $hasProductTitleField) {
                $shouldResaveProducts = true;
            }
            $productTypeRecord->productTitleFormat = $productTitleFormat;
            $productTypeRecord->hasProductTitleField = $hasProductTitleField;

            if ($productTypeRecord->hasVariants != $data['hasVariants']) {
                $shouldResaveProducts = true;
            }
            $productTypeRecord->hasVariants = $data['hasVariants'];

            $skuFormat = $data['skuFormat'] ?? '';
            if ($productTypeRecord->skuFormat != $skuFormat) {
                $shouldResaveProducts = true;
            }
            $productTypeRecord->skuFormat = $skuFormat;

            $descriptionFormat = $data['descriptionFormat'] ?? '';
            if ($productTypeRecord->descriptionFormat != $descriptionFormat) {
                $shouldResaveProducts = true;
            }
            $productTypeRecord->descriptionFormat = $descriptionFormat;

            if (!empty($data['productFieldLayouts']) && !empty($config = reset($data['productFieldLayouts']))) {
                // Save the main field layout
                $layout = FieldLayout::createFromConfig($config);
                $layout->id = $productTypeRecord->fieldLayoutId;
                $layout->type = Product::class;
                $layout->uid = key($data['productFieldLayouts']);
                $fieldsService->saveLayout($layout, false);
                $productTypeRecord->fieldLayoutId = $layout->id;
            } elseif ($productTypeRecord->fieldLayoutId) {
                // Delete the main field layout
                $fieldsService->deleteLayoutById($productTypeRecord->fieldLayoutId);
                $productTypeRecord->fieldLayoutId = null;
            }

            if (!empty($data['variantFieldLayouts']) && !empty($config = reset($data['variantFieldLayouts']))) {
                // Save the variant field layout
                $layout = FieldLayout::createFromConfig($config);
                $layout->id = $productTypeRecord->variantFieldLayoutId;
                $layout->type = Variant::class;
                $layout->uid = key($data['variantFieldLayouts']);
                $fieldsService->saveLayout($layout, false);
                $productTypeRecord->variantFieldLayoutId = $layout->id;
            } elseif ($productTypeRecord->variantFieldLayoutId) {
                // Delete the variant field layout
                $fieldsService->deleteLayoutById($productTypeRecord->variantFieldLayoutId);
                $productTypeRecord->variantFieldLayoutId = null;
            }

            $productTypeRecord->save(false);

            // Update the site settings
            // -----------------------------------------------------------------

            $sitesNowWithoutUrls = [];
            $sitesWithNewUriFormats = [];
            /** @var array<int, ProductTypeSiteRecord> $allOldSiteSettingsRecords */
            $allOldSiteSettingsRecords = [];

            if (!$isNewProductType) {
                /** @var array<int, ProductTypeSiteRecord> $allOldSiteSettingsRecords */
                $allOldSiteSettingsRecords = ProductTypeSiteRecord::find()
                    ->where(['productTypeId' => $productTypeRecord->id])
                    ->indexBy('siteId')
                    ->all();
            }

            $siteIdMap = Db::idsByUids('{{%sites}}', array_keys($siteData));

            /** @var array $siteSettings */
            foreach ($siteData as $siteUid => $siteSettings) {
                $siteId = $siteIdMap[$siteUid];

                // Was this already selected?
                if (!$isNewProductType && isset($allOldSiteSettingsRecords[$siteId])) {
                    $siteSettingsRecord = $allOldSiteSettingsRecords[$siteId];
                } else {
                    $siteSettingsRecord = new ProductTypeSiteRecord();
                    $siteSettingsRecord->productTypeId = $productTypeRecord->id;
                    $siteSettingsRecord->siteId = $siteId;
                }

                if ($siteSettingsRecord->hasUrls = $siteSettings['hasUrls']) {
                    $siteSettingsRecord->uriFormat = $siteSettings['uriFormat'];
                    $siteSettingsRecord->template = $siteSettings['template'];
                } else {
                    $siteSettingsRecord->uriFormat = null;
                    $siteSettingsRecord->template = null;
                }

                if (!$siteSettingsRecord->getIsNewRecord()) {
                    // Did it used to have URLs, but not anymore?
                    if ($siteSettingsRecord->isAttributeChanged('hasUrls', false) && !$siteSettings['hasUrls']) {
                        $sitesNowWithoutUrls[] = $siteId;
                    }

                    // Does it have URLs, and has its URI format changed?
                    if ($siteSettings['hasUrls'] && $siteSettingsRecord->isAttributeChanged('uriFormat', false)) {
                        $sitesWithNewUriFormats[] = $siteId;
                    }
                }

                $siteSettingsRecord->save(false);
            }

            if (!$isNewProductType) {
                // Drop any site settings that are no longer being used, as well as the associated product/element
                // site rows
                $affectedSiteUids = array_keys($siteData);

                foreach ($allOldSiteSettingsRecords as $siteId => $siteSettingsRecord) {
                    $siteUid = array_search($siteId, $siteIdMap, false);
                    if (!in_array($siteUid, $affectedSiteUids, false)) {
                        $siteSettingsRecord->delete();
                    }
                }
            }

            // Finally, deal with the existing products...
            // -----------------------------------------------------------------

            if (!$isNewProductType) {
                // Get all of the product IDs in this group
                $productIds = Product::find()
                    ->typeId($productTypeRecord->id)
                    ->status(null)
                    ->limit(null)
                    ->ids();

                // Are there any sites left?
                if (!empty($siteData)) {
                    // Drop the old product URIs for any site settings that don't have URLs
                    if (!empty($sitesNowWithoutUrls)) {
                        $db->createCommand()
                            ->update(
                                '{{%elements_sites}}',
                                ['uri' => null],
                                [
                                    'elementId' => $productIds,
                                    'siteId' => $sitesNowWithoutUrls,
                                ])
                            ->execute();
                    } elseif (!empty($sitesWithNewUriFormats)) {
                        foreach ($productIds as $productId) {
                            App::maxPowerCaptain();

                            // Loop through each of the changed sites and update all of the products’ slugs and
                            // URIs
                            foreach ($sitesWithNewUriFormats as $siteId) {
                                $product = Product::find()
                                    ->id($productId)
                                    ->siteId($siteId)
                                    ->status(null)
                                    ->one();

                                if ($product) {
                                    Craft::$app->getElements()->updateElementSlugAndUri($product, false, false);
                                }
                            }
                        }
                    }
                }
            }

            $transaction->commit();

            if ($shouldResaveProducts) {
                Craft::$app->getQueue()->push(new ResaveElements([
                    'elementType' => Product::class,
                    'criteria' => [
                        'siteId' => '*',
                        'status' => null,
                        'typeId' => $productTypeRecord->id,
                    ],
                ]));
            }
        } catch (Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        // Clear caches
        $this->_allProductTypeIds = null;
        $this->_editableProductTypeIds = null;
        $this->_fetchedAllProductTypes = false;
        unset(
            $this->_productTypesById[$productTypeRecord->id],
            $this->_productTypesByHandle[$productTypeRecord->handle],
            $this->_siteSettingsByProductId[$productTypeRecord->id]
        );

        // Fire an 'afterSaveProductType' event
        if ($this->hasEventHandlers(self::EVENT_AFTER_SAVE_PRODUCTTYPE)) {
            $this->trigger(self::EVENT_AFTER_SAVE_PRODUCTTYPE, new ProductTypeEvent([
                'productType' => $this->getProductTypeById($productTypeRecord->id),
                'isNew' => empty($this->_savingProductTypes[$productTypeUid]),
            ]));
        }
    }

    /**
     * Returns all product types by a tax category id.
     */
    public function getProductTypesByTaxCategoryId(int $taxCategoryId): array
    {
        $rows = $this->_createProductTypeQuery()
            ->innerJoin(Table::PRODUCTTYPES_TAXCATEGORIES . ' productTypeTaxCategories', '[[productTypes.id]] = [[productTypeTaxCategories.productTypeId]]')
            ->where(['productTypeTaxCategories.taxCategoryId' => $taxCategoryId])
            ->all();

        $productTypes = [];

        foreach ($rows as $row) {
            $productTypes[$row['id']] = new ProductType($row);
        }

        return $productTypes;
    }

    /**
     * Returns all product types by a shipping category id.
     */
    public function getProductTypesByShippingCategoryId(int $shippingCategoryId): array
    {
        $rows = $this->_createProductTypeQuery()
            ->innerJoin(Table::PRODUCTTYPES_SHIPPINGCATEGORIES . ' productTypeShippingCategories', '[[productTypes.id]] = [[productTypeShippingCategories.productTypeId]]')
            ->where(['productTypeShippingCategories.shippingCategoryId' => $shippingCategoryId])
            ->all();

        $productTypes = [];

        foreach ($rows as $row) {
            $productTypes[$row['id']] = new ProductType($row);
        }

        return $productTypes;
    }

    /**
     * Deletes a product type by its ID.
     *
     * @param int $id the product type's ID
     * @return bool Whether the product type was deleted successfully.
     * @throws Throwable if reasons
     */
    public function deleteProductTypeById(int $id): bool
    {
        $productType = $this->getProductTypeById($id);
        Craft::$app->getProjectConfig()->remove(self::CONFIG_PRODUCTTYPES_KEY . '.' . $productType->uid);
        return true;
    }

    /**
     * Handle a product type getting deleted.
     *
     * @throws Throwable if reasons
     */
    public function handleDeletedProductType(ConfigEvent $event): void
    {
        $uid = $event->tokenMatches[0];
        $productTypeRecord = $this->_getProductTypeRecord($uid);

        if (!$productTypeRecord->id) {
            return;
        }

        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();

        try {
            $products = Product::find()
                ->typeId($productTypeRecord->id)
                ->status(null)
                ->limit(null)
                ->all();

            foreach ($products as $product) {
                Craft::$app->getElements()->deleteElement($product);
            }

            $fieldLayoutId = $productTypeRecord->fieldLayoutId;
            $variantFieldLayoutId = $productTypeRecord->variantFieldLayoutId;
            Craft::$app->getFields()->deleteLayoutById($fieldLayoutId);

            if ($variantFieldLayoutId) {
                Craft::$app->getFields()->deleteLayoutById($variantFieldLayoutId);
            }

            $productTypeRecord->delete();
            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();

            throw $e;
        }

        // Clear caches
        $this->_allProductTypeIds = null;
        $this->_editableProductTypeIds = null;
        $this->_fetchedAllProductTypes = false;
        unset(
            $this->_productTypesById[$productTypeRecord->id],
            $this->_productTypesByHandle[$productTypeRecord->handle],
            $this->_siteSettingsByProductId[$productTypeRecord->id]
        );
    }

    /**
     * Prune a deleted site from category group site settings.
     */
    public function pruneDeletedSite(DeleteSiteEvent $event): void
    {
        $siteUid = $event->site->uid;

        $projectConfig = Craft::$app->getProjectConfig();
        $productTypes = $projectConfig->get(self::CONFIG_PRODUCTTYPES_KEY);

        // Loop through the product types and prune the UID from field layouts.
        if (is_array($productTypes)) {
            foreach ($productTypes as $productTypeUid => $productType) {
                $projectConfig->remove(self::CONFIG_PRODUCTTYPES_KEY . '.' . $productTypeUid . '.siteSettings.' . $siteUid);
            }
        }
    }

    /**
     * @deprecated in 4.0.3. Unused fields will be pruned automatically as field layouts are resaved.
     */
    public function pruneDeletedField(): void
    {
    }

    /**
     * Returns a product type by its ID.
     *
     * @param int $productTypeId the product type's ID
     * @return ProductType|null either the product type or `null`
     */
    public function getProductTypeById(int $productTypeId): ?ProductType
    {
        if (isset($this->_productTypesById[$productTypeId])) {
            return $this->_productTypesById[$productTypeId];
        }

        if ($this->_fetchedAllProductTypes) {
            return null;
        }

        $result = $this->_createProductTypeQuery()
            ->where(['id' => $productTypeId])
            ->one();

        if (!$result) {
            return null;
        }

        $this->_memoizeProductType(new ProductType($result));

        return $this->_productTypesById[$productTypeId];
    }

    /**
     * Returns a product type by its UID.
     *
     * @param string $uid the product type's UID
     * @return ProductType|null either the product type or `null`
     */
    public function getProductTypeByUid(string $uid): ?ProductType
    {
        return ArrayHelper::firstWhere($this->getAllProductTypes(), 'uid', $uid, true);
    }

    /**
     * Returns whether a product type’s products have URLs, and if the template path is valid.
     *
     * @param ProductType $productType The product for which to validate the template.
     * @param int $siteId The site for which to valid for
     * @return bool Whether the template is valid.
     * @throws Exception
     */
    public function isProductTypeTemplateValid(ProductType $productType, int $siteId): bool
    {
        $productTypeSiteSettings = $productType->getSiteSettings();

        if (isset($productTypeSiteSettings[$siteId]) && $productTypeSiteSettings[$siteId]->hasUrls && $productTypeSiteSettings[$siteId]->template) {
            // Set Craft to the site template mode
            $view = Craft::$app->getView();
            $oldTemplateMode = $view->getTemplateMode();
            $view->setTemplateMode($view::TEMPLATE_MODE_SITE);

            // Does the template exist?
            $templateExists = Craft::$app->getView()->doesTemplateExist($productTypeSiteSettings[$siteId]->template);

            // Restore the original template mode
            $view->setTemplateMode($oldTemplateMode);

            if ($templateExists) {
                return true;
            }
        }

        return false;
    }

    /**
     * Adds a new product type setting row when a Site is added to Craft.
     *
     * @param SiteEvent $event The event that triggered this.
     * @throws Exception
     * @throws ErrorException
     * @throws InvalidConfigException
     * @throws NotSupportedException
     * @throws ServerErrorHttpException
     */
    public function afterSaveSiteHandler(SiteEvent $event): void
    {
        $projectConfig = Craft::$app->getProjectConfig();

        if ($event->isNew) {
            $oldPrimarySiteUid = Db::uidById(CraftTable::SITES, $event->oldPrimarySiteId);
            $existingProductTypeSettings = $projectConfig->get(self::CONFIG_PRODUCTTYPES_KEY);

            if (!$projectConfig->getIsApplyingExternalChanges() && is_array($existingProductTypeSettings)) {
                foreach ($existingProductTypeSettings as $productTypeUid => $settings) {
                    $primarySiteSettings = $settings['siteSettings'][$oldPrimarySiteUid];
                    $configPath = self::CONFIG_PRODUCTTYPES_KEY . '.' . $productTypeUid . '.siteSettings.' . $event->site->uid;
                    $projectConfig->set($configPath, $primarySiteSettings);
                }
            }
        }
    }


    /**
     * Memoize a product type
     *
     * @param ProductType $productType The product type to memoize.
     */
    private function _memoizeProductType(ProductType $productType): void
    {
        $this->_productTypesById[$productType->id] = $productType;
        $this->_productTypesByHandle[$productType->handle] = $productType;
    }

    /**
     * Returns a Query object prepped for retrieving purchasables.
     *
     * @return Query The query object.
     */
    private function _createProductTypeQuery(): Query
    {
        $query = (new Query())
            ->select([
                'productTypes.descriptionFormat',
                'productTypes.fieldLayoutId',
                'productTypes.handle',
                'productTypes.hasDimensions',
                'productTypes.hasProductTitleField',
                'productTypes.hasVariants',
                'productTypes.hasVariantTitleField',
                'productTypes.id',
                'productTypes.name',
                'productTypes.productTitleFormat',
                'productTypes.skuFormat',
                'productTypes.uid',
                'productTypes.variantFieldLayoutId',
            ])
            ->from([Table::PRODUCTTYPES . ' productTypes']);

        // in 4.0 `craft\commerce\model\ProductType::$titleFormat` was renamed to `$variantTitleFormat`.
        $projectConfig = Craft::$app->getProjectConfig();
        $schemaVersion = $projectConfig->get('plugins.commerce.schemaVersion', true);
        if (version_compare($schemaVersion, '4.0.0', '>=')) {
            $query->addSelect('productTypes.variantTitleFormat');
        } else {
            $query->addSelect('productTypes.titleFormat');
        }

        return $query;
    }

    /**
     * Gets a product type's record by uid.
     */
    private function _getProductTypeRecord(string $uid): ProductTypeRecord
    {
        if ($productType = ProductTypeRecord::findOne(['uid' => $uid])) {
            return $productType;
        }

        return new ProductTypeRecord();
    }

    /**
     * Check if user has product type permission.
     *
     * @param User $user
     * @param ProductType $productType
     * @param null $checkPermissionName detailed product type permission.
     * @return bool
     */
    public function hasPermission(User $user, ProductType $productType, $checkPermissionName = null): bool
    {
        if ($user->admin == true) {
            return true;
        }

        $permissions = Craft::$app->getUserPermissions()->getPermissionsByUserId($user->id);

        $suffix = ':' . $productType->uid;

        // Required for create and delete permission.
        $editProductType = strtolower('commerce-editProductType' . $suffix);

        if ($checkPermissionName !== null) {
            $checkPermissionName = strtolower($checkPermissionName . $suffix);
        }

        if (!in_array($editProductType, $permissions) || ($checkPermissionName !== null && !in_array(strtolower($checkPermissionName), $permissions))) {
            return false;
        }

        return true;
    }
}
