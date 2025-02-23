# Release Notes for Craft Commerce


### Fixed
- Fixed a bug where disabling all variants throws an error on Edit Product Page.

## 4.0.4 - 2022-06-22

> {note} If you’ve already upgraded a site to Commerce 4, please go to **Commerce** → **Promotions** → **Discounts** and review your discounts’ coupons’ Max Uses values, as the `commerce/upgrade` command wasn’t migrating those values properly before this release.

### Fixed
- Fixed a bug where `craft\commerce\services\PaymentSources::getAllGatewayPaymentSourcesByUserId()` wasn’t passing along the user ID to `getAllPaymentSourcesByCustomerId()`.
- Fixed an error that could occur when using a discount with a coupon code.
- Fixed a bug where it wasn’t possible to delete a shipping rule. ([#2857](https://github.com/craftcms/commerce/issues/2857))
- Fixed a bug where it wasn’t possible to subscribe and create a payment source simultaneously. ([#2834](https://github.com/craftcms/commerce/pull/2834))
- Fixed inaccurate PHP type declarations.
- Fixed errors that could occur when expiring, cancelling, or suspending a subscription. ([#2831](https://github.com/craftcms/commerce/issues/2831))
- Fixed a bug where the Order Value condition rule wasn’t working.
- Fixed a bug where the `commerce/upgrade` command wasn’t migrating discounts’ coupons’ Max Uses values properly.

## 4.0.3 - 2022-06-09

### Deprecated
- Deprecated `craft\commerce\services\Orders::pruneDeletedField()`.
- Deprecated `craft\commerce\services\ProductType::pruneDeletedField()`.
- Deprecated `craft\commerce\services\Subscriptions::pruneDeletedField()`.

### Fixed
- Fixed a PHP error that could occur when saving a shipping rule. ([#2824](https://github.com/craftcms/commerce/issues/2824))
- Fixed a PHP error that could occur when saving a sale. ([#2827](https://github.com/craftcms/commerce/issues/2827))
- Fixed a bug where `administrativeArea` data wasn’t being saved for an address in the example templates. ([#2840](https://github.com/craftcms/commerce/issues/2840))

## 4.0.2 - 2022-06-03

### Fixed
- Fixed a bug where it wasn’t possible to set a coupon’s Max Uses setting to `0`.
- Fixed UI bugs in the “Update Order Status” modal. ([#2821](https://github.com/craftcms/commerce/issues/2821))
- Fixed a bug where the `commerce/upgrade` console command caused customer discount uses to be reset.
- Fixed a bug where the `commerce/upgrade` console command would fail when multiple orders used the same email address with different casing.

## 4.0.1 - 2022-05-18

### Changed
- Address forms in the example templates now include any Plain Text custom fields in the address field layout.

### Fixed
- Fixed a bug where the `autoSetNewCartAddresses` setting didn’t have any effect. ([#2804](https://github.com/craftcms/commerce/issues/2804))
- Fixed a PHP error that occurred when making a payment on the Edit Order page. ([#2795](https://github.com/craftcms/commerce/issues/2795))
- Fixed a PHP error that occurred when duplicating addresses that wasn’t owned by a user.
- Fixed a bug where address cards appeared to be editable when viewing completed orders. ([#2817](https://github.com/craftcms/commerce/issues/2817))
- Fixed a front-end validation error that was raised incorrectly on address inputs in the example templates. ([#2777](https://github.com/craftcms/commerce/pull/2777))

## 4.0.0 - 2022-05-04

### Added
- Customers are now native Craft user elements. ([#2524](https://github.com/craftcms/commerce/discussions/2524), [2385](https://github.com/craftcms/commerce/discussions/2385))
- Discounts can now have condition builders, enabling flexible matching based on the order, user, and addresses. ([#2290](https://github.com/craftcms/commerce/discussions/2290),  [#2296](https://github.com/craftcms/commerce/discussions/2296), [#2299](https://github.com/craftcms/commerce/discussions/2299))
- Shipping zones can now have condition builders, enabling flexible matching based on the address. ([#2290](https://github.com/craftcms/commerce/discussions/2290), [#2296](https://github.com/craftcms/commerce/discussions/2296))
- Tax zones can now have condition builders, enabling flexible matching based on the address. ([#2290](https://github.com/craftcms/commerce/discussions/2290), [#2296](https://github.com/craftcms/commerce/discussions/2296))
- Discounts can now have multiple coupon codes, each with their own usage rules. ([#2377](https://github.com/craftcms/commerce/discussions/2377), [#2303](https://github.com/craftcms/commerce/discussions/2303), [#2713](https://github.com/craftcms/commerce/pull/2713))
- It’s now possible to bulk-generate coupon codes.
- It’s now possible to create orders from the Edit User page.
- Added a “Commerce” panel to the Debug Toolbar.
- Added “Edit”, “Create”, and “Delete” permissions for product types, sales, and discounts. ([#174](https://github.com/craftcms/commerce/issues/174), [#2400](https://github.com/craftcms/commerce/discussions/2400))
- Added the `|commercePaymentFormNamespace` Twig filter.
- Added `craft\commerce\base\Zone`.
- Added `craft\commerce\behaviors\CustomerAddressBehavior`.
- Added `craft\commerce\behaviors\CustomerBehavior`.
- Added `craft\commerce\console\controllers\UpgradeController`.
- Added `craft\commerce\controllers\DiscountsController::DISCOUNT_COUNTER_TYPE_EMAIL`.
- Added `craft\commerce\controllers\DiscountsController::DISCOUNT_COUNTER_TYPE_TOTAL`.
- Added `craft\commerce\controllers\DiscountsController::DISCOUNT_COUNTER_TYPE_USER`.
- Added `craft\commerce\controllers\DiscountsController::actionGenerateCoupons()`.
- Added `craft\commerce\controllers\OrdersController::actionCreateCustomer()`.
- Added `craft\commerce\controllers\OrdersController::actionGetCustomerAddresses()`.
- Added `craft\commerce\controllers\OrdersController::actionGetOrderAddress()`.
- Added `craft\commerce\controllers\OrdersController::actionValidateAddress()`.
- Added `craft\commerce\controllers\OrdersController::enforceManageOrderPermissions()`.
- Added `craft\commerce\controllers\SubscriptionsController::enforceManageSubscriptionPermissions()`.
- Added `craft\commerce\elements\Order::$sourceBillingAddressId`
- Added `craft\commerce\elements\Order::$sourceShippingAddressId`
- Added `craft\commerce\elements\Product::canCreateDrafts()`.
- Added `craft\commerce\elements\Product::canDelete()`.
- Added `craft\commerce\elements\Product::canDeleteForSite()`.
- Added `craft\commerce\elements\Product::canDuplicate()`.
- Added `craft\commerce\elements\Product::canSave()`.
- Added `craft\commerce\elements\Product::canView()`.
- Added `craft\commerce\elements\Subscription::canView()`.
- Added `craft\commerce\elements\actions\UpdateOrderStatus::$suppressEmails`.
- Added `craft\commerce\events\CommerceDebugPanelDataEvent`.
- Added `craft\commerce\events\OrderStatusEmailsEvent`.
- Added `craft\commerce\events\PdfRenderEvent`.
- Added `craft\commerce\fieldlayoutelements\UserAddressSettings`.
- Added `craft\commerce\helpers\DebugPanel`.
- Added `craft\commerce\helpers\PaymentForm`.
- Added `craft\commerce\models\Coupon`.
- Added `craft\commerce\models\Discount::$couponFormat`.
- Added `craft\commerce\models\Discount::getCoupons()`.
- Added `craft\commerce\models\Discount::setCoupons()`.
- Added `craft\commerce\models\OrderHistory::$userId`.
- Added `craft\commerce\models\OrderHistory::$userName`.
- Added `craft\commerce\models\OrderHistory::getUser()`.
- Added `craft\commerce\models\ShippingAddressZone::condition`.
- Added `craft\commerce\models\Store`.
- Added `craft\commerce\models\TaxAddressZone::condition`.
- Added `craft\commerce\plugin\Services::getCoupons()`.
- Added `craft\commerce\record\OrderHistory::$userName`.
- Added `craft\commerce\records\Coupon`.
- Added `craft\commerce\records\OrderHistory::$userId`.
- Added `craft\commerce\records\OrderHistory::getUser()`.
- Added `craft\commerce\service\Store`.
- Added `craft\commerce\services\Carts::$cartCookieDuration`.
- Added `craft\commerce\services\Carts::$cartCookie`.
- Added `craft\commerce\services\Coupons`.
- Added `craft\commerce\services\Customers::ensureCustomer()`.
- Added `craft\commerce\services\Customers::savePrimaryBillingAddressId()`.
- Added `craft\commerce\services\Customers::savePrimaryShippingAddressId()`.
- Added `craft\commerce\services\Discounts::clearUserUsageHistoryById()`.
- Added `craft\commerce\services\OrderStatuses::EVENT_ORDER_STATUS_CHANGE_EMAILS`.
- Added `craft\commerce\services\Pdfs::EVENT_BEFORE_DELETE_PDF`.
- Added `craft\commerce\services\ProductTypes::getCreatableProductTypeIds()`.
- Added `craft\commerce\services\ProductTypes::getCreatableProductTypes()`.
- Added `craft\commerce\services\ProductTypes::getEditableProductTypeIds()`.
- Added `craft\commerce\services\ProductTypes::hasPermission()`.
- Added `craft\commerce\validators\CouponValidator`.
- Added `craft\commerce\validators\StoreCountryValidator`.
- Added `craft\commerce\web\assets\coupons\CouponsAsset`.

### Changed
- Craft Commerce now requires Craft CMS 4.0.0-RC2 or later.
- Tax rate inputs no longer require the percent symbol.
- Subscription plans are no longer accessible via old Control Panel URLs.
- Addresses can no longer be related to both a user’s address book and an order at the same time. ([#2457](https://github.com/craftcms/commerce/discussions/2457))
- Gateways’ `isFrontendEnabled` settings now support environment variables.
- The active cart number is now stored in a cookie rather than the PHP session data, so it can be retained across browser reboots. ([#2790](https://github.com/craftcms/commerce/pull/2790))
- The installer now archives any database tables that were left behind by a previous Craft Commerce installation.
- `commerce/*` actions no longer accept `orderNumber` params. `number` can be used instead.
- `commerce/cart/*` actions no longer accept `cartUpdatedNotice` params. `successMessage` can be used instead.
- `commerce/cart/*` actions no longer include `availableShippingMethods` in their JSON responses. `availableShippingMethodOptions` can be used instead.
- `commerce/payment-sources/*` actions no longer include `paymentForm` in their JSON responses. `paymentFormErrors` can be used instead.
- `commerce/payments/*` actions now expect payment form fields to be namespaced with the `|commercePaymentFormNamespace` Twig filter’s response.
- `craft\commerce\elements\Order::getCustomer()` now returns a `craft\elements\User` object.
- `craft\commerce\elements\Product::getVariants()`, `getDefaultVariant()`, `getCheapestVariant()`, `getTotalStock()`, and `getHasUnlimitedStock()` now only return data related to enabled variants by default.
- `craft\commerce\model\ProductType::$titleFormat` was renamed to `$variantTitleFormat`.
- `craft\commerce\models\TaxRate::getRateAsPercent()` now returns a localized value.
- `craft\commerce\services\LineItems::createLineItem()` no longer has an `$orderId` argument.
- `craft\commerce\services\LineItems::resolveLineItem()` now has an `$order` argument rather than `$orderId`.
- `craft\commerce\services\Pdfs::EVENT_AFTER_RENDER_PDF` now raises `craft\commerce\events\PdfRenderEvent` rather than `PdfEvent`.
- `craft\commerce\services\Pdfs::EVENT_AFTER_SAVE_PDF` now raises `craft\commerce\events\PdfEvent` rather than `PdfSaveEvent`.
- `craft\commerce\services\Pdfs::EVENT_BEFORE_RENDER_PDF` now raises `craft\commerce\events\PdfRenderEvent` rather than `PdfEvent`.
- `craft\commerce\services\Pdfs::EVENT_BEFORE_SAVE_PDF` now raises `craft\commerce\events\PdfEvent` rather than `PdfSaveEvent`.
- `craft\commerce\services\ShippingMethods::getAvailableShippingMethods()` has been renamed to `getMatchingShippingMethods()`.
- `craft\commerce\services\Variants::getAllVariantsByProductId()` now accepts a `$includeDisabled` argument.

### Deprecated
- Deprecated `craft\commerce\elements\Order::getUser()`. `getCustomer()` should be used instead.
- Deprecated `craft\commerce\services\Carts::getCartName()`. `$cartCookie['name']` should be used instead.
- Deprecated `craft\commerce\services\Plans::getAllGatewayPlans()`. `getPlansByGatewayId()` should be used instead.
- Deprecated `craft\commerce\services\Subscriptions::doesUserHaveAnySubscriptions()`. `doesUserHaveSubscriptions()` should be used instead.
- Deprecated `craft\commerce\services\Subscriptions::getSubscriptionCountForPlanById()`. `getSubscriptionCountByPlanId()` should be used instead.
- Deprecated `craft\commerce\services\TaxRates::getTaxRatesForZone()`. `getTaxRatesByTaxZoneId()` should be used instead.
- Deprecated `craft\commerce\services\Transactions::deleteTransaction()`. `deleteTransactionById()` should be used instead.

### Removed
- Removed the `orderPdfFilenameFormat` setting.
- Removed the `orderPdfPath` setting.
- Removed the `commerce-manageCustomers` permission.
- Removed the `commerce-manageProducts` permission.
- Removed `json_encode_filtered` Twig filter.
- Removed the `commerce/orders/purchasable-search` action. `commerce/orders/purchasables-table` can be used instead.
- Removed `Plugin::getInstance()->getPdf()`. `getPdfs()` can be used instead.
- Removed `craft\commerce\Plugin::t()`. `Craft::t('commerce', 'My String')` can be used instead.
- Removed `craft\commerce\base\AddressZoneInterface`. `craft\commerce\base\ZoneInterface` can be used instead.
- Removed `craft\commerce\base\OrderDeprecatedTrait`.
- Removed `craft\commerce\controllers\AddressesController`.
- Removed `craft\commerce\controllers\CountriesController`.
- Removed `craft\commerce\controllers\CustomerAddressesController`.
- Removed `craft\commerce\controllers\CustomersController`.
- Removed `craft\commerce\controllers\PlansController::actionRedirect()`.
- Removed `craft\commerce\controllers\ProductsPreviewController::actionSaveProduct()`.
- Removed `craft\commerce\controllers\ProductsPreviewController::enforceProductPermissions()`.
- Removed `craft\commerce\controllers\StatesController`.
- Removed `craft\commerce\elements\Order::getAdjustmentsTotalByType()`. `getTotalTax()`, `getTotalDiscount()`, or `getTotalShippingCost()` can be used instead.
- Removed `craft\commerce\elements\Order::getAvailableShippingMethods()`. `getAvailableShippingMethodOptions()` can be used instead.
- Removed `craft\commerce\elements\Order::getOrderLocale()`. `$orderLanguage` can be used instead.
- Removed `craft\commerce\elements\Order::getShippingMethodId()`. `getShippingMethodHandle()` can be used instead.
- Removed `craft\commerce\elements\Order::getShouldRecalculateAdjustments()`. `getRecalculationMode()` can be used instead.
- Removed `craft\commerce\elements\Order::getTotalTaxablePrice()`. The taxable price is now calculated within the tax adjuster.
- Removed `craft\commerce\elements\Order::removeEstimatedBillingAddress()`. `setEstimatedBillingAddress(null)` can be used instead.
- Removed `craft\commerce\elements\Order::removeEstimatedShippingAddress()`. `setEstimatedShippingAddress(null)` can be used instead.
- Removed `craft\commerce\elements\Order::setShouldRecalculateAdjustments()`. `setRecalculationMode()` can be used instead.
- Removed `craft\commerce\elements\actions\DeleteOrder`. `craft\elements\actions\Delete` can be used instead.
- Removed `craft\commerce\elements\actions\DeleteProduct`. `craft\elements\actions\Delete` can be used instead.
- Removed `craft\commerce\elements\traits\OrderDeprecatedTrait`.
- Removed `craft\commerce\events\AddressEvent`.
- Removed `craft\commerce\events\CustomerAddressEvent`.
- Removed `craft\commerce\events\CustomerEvent`.
- Removed `craft\commerce\events\DefineAddressLinesEvent`. `craft\services\Addresses::formatAddress()` can be used instead.
- Removed `craft\commerce\events\LineItemEvent::isValid`.
- Removed `craft\commerce\events\PdfSaveEvent`.
- Removed `craft\commerce\helpers\Localization::formatAsPercentage()`.
- Removed `craft\commerce\models\Country`.
- Removed `craft\commerce\models\Discount::$code`.
- Removed `craft\commerce\models\Discount::getDiscountUserGroups()`.
- Removed `craft\commerce\models\Discount::getUserGroupIds()`. Discount user groups were migrated to the customer condition rule.
- Removed `craft\commerce\models\Discount::setUserGroupIds()`. Discount user groups were migrated to the customer condition rule.
- Removed `craft\commerce\models\Email::getPdfTemplatePath()`. `getPdf()->getTemplatePath()` can be used instead.
- Removed `craft\commerce\models\LineItem::getAdjustmentsTotalByType()`. `getTax()`, `getDiscount()`, or `getShippingCost()` can be used instead.
- Removed `craft\commerce\models\LineItem::setSaleAmount()`.
- Removed `craft\commerce\models\OrderHistory::$customerId`. `$userId` can be used instead.
- Removed `craft\commerce\models\OrderHistory::getCustomer()`. `getUser()` can be used instead.
- Removed `craft\commerce\models\ProductType::getLineItemFormat()`.
- Removed `craft\commerce\models\ProductType::setLineItemFormat()`.
- Removed `craft\commerce\models\Settings::$showCustomerInfoTab`. `$showEditUserCommerceTab` can be used instead.
- Removed `craft\commerce\models\ShippingAddressZone::getCountries()`.
- Removed `craft\commerce\models\ShippingAddressZone::getCountriesNames()`.
- Removed `craft\commerce\models\ShippingAddressZone::getCountryIds()`.
- Removed `craft\commerce\models\ShippingAddressZone::getStateIds()`.
- Removed `craft\commerce\models\ShippingAddressZone::getStates()`.
- Removed `craft\commerce\models\ShippingAddressZone::getStatesNames()`.
- Removed `craft\commerce\models\ShippingAddressZone::isCountryBased`.
- Removed `craft\commerce\models\State`.
- Removed `craft\commerce\models\TaxAddressZone::getCountries()`.
- Removed `craft\commerce\models\TaxAddressZone::getCountriesNames()`.
- Removed `craft\commerce\models\TaxAddressZone::getCountryIds()`.
- Removed `craft\commerce\models\TaxAddressZone::getStateIds()`.
- Removed `craft\commerce\models\TaxAddressZone::getStates()`.
- Removed `craft\commerce\models\TaxAddressZone::getStatesNames()`.
- Removed `craft\commerce\models\TaxAddressZone::isCountryBased`.
- Removed `craft\commerce\queue\jobs\ConsolidateGuestOrders`.
- Removed `craft\commerce\records\Country`.
- Removed `craft\commerce\records\CustomerAddress`. `craft\records\Address` can be used instead.
- Removed `craft\commerce\records\Discount::CONDITION_USER_GROUPS_ANY_OR_NONE`. Discount user groups were migrated to the customer condition rule.
- Removed `craft\commerce\records\Discount::CONDITION_USER_GROUPS_EXCLUDE`. Discount user groups were migrated to the customer condition rule.
- Removed `craft\commerce\records\Discount::CONDITION_USER_GROUPS_INCLUDE_ALL`. Discount user groups were migrated to the customer condition rule.
- Removed `craft\commerce\records\Discount::CONDITION_USER_GROUPS_INCLUDE_ANY`. Discount user groups were migrated to the customer condition rule.
- Removed `craft\commerce\records\DiscountUserGroup`.
- Removed `craft\commerce\records\OrderHistory::getCustomer()`. `getUser()` can be used instead.
- Removed `craft\commerce\records\ShippingZoneCountry`.
- Removed `craft\commerce\records\ShippingZoneState`.
- Removed `craft\commerce\records\State`.
- Removed `craft\commerce\records\TaxZoneCountry`.
- Removed `craft\commerce\records\TaxZoneState`.
- Removed `craft\commerce\services\Addresses::purgeOrphanedAddresses()`.
- Removed `craft\commerce\services\Addresses`.
- Removed `craft\commerce\services\Countries`.
- Removed `craft\commerce\services\Customers::EVENT_AFTER_SAVE_CUSTOMER_ADDRESS`.
- Removed `craft\commerce\services\Customers::EVENT_AFTER_SAVE_CUSTOMER`.
- Removed `craft\commerce\services\Customers::EVENT_BEFORE_SAVE_CUSTOMER_ADDRESS`.
- Removed `craft\commerce\services\Customers::EVENT_BEFORE_SAVE_CUSTOMER`.
- Removed `craft\commerce\services\Customers::SESSION_CUSTOMER`.
- Removed `craft\commerce\services\Customers::consolidateOrdersToUser()`.
- Removed `craft\commerce\services\Customers::deleteCustomer()`.
- Removed `craft\commerce\services\Customers::forgetCustomer()`.
- Removed `craft\commerce\services\Customers::getAddressIds()`.
- Removed `craft\commerce\services\Customers::getCustomer()`.
- Removed `craft\commerce\services\Customers::getCustomerById()`.
- Removed `craft\commerce\services\Customers::getCustomerByUserId()`.
- Removed `craft\commerce\services\Customers::getCustomerId()`.
- Removed `craft\commerce\services\Customers::getCustomersQuery()`.
- Removed `craft\commerce\services\Customers::purgeOrphanedCustomers()`.
- Removed `craft\commerce\services\Customers::saveAddress()`.
- Removed `craft\commerce\services\Customers::saveCustomer()`.
- Removed `craft\commerce\services\Customers::saveUserHandler()`.
- Removed `craft\commerce\services\Discounts::EVENT_BEFORE_MATCH_LINE_ITEM`. `EVENT_DISCOUNT_MATCHES_LINE_ITEM` can be used instead.
- Removed `craft\commerce\services\Discounts::getOrderConditionParams()`. `$order->toArray()` can be used instead.
- Removed `craft\commerce\services\Discounts::populateDiscountRelations()`.
- Removed `craft\commerce\services\Orders::cartArray()`. `toArray()` can be used instead.
- Removed `craft\commerce\services\Payments::getTotalAuthorizedForOrder()`.
- Removed `craft\commerce\services\Payments::getTotalAuthorizedOnlyForOrder()`. `craft\commerce\elements\Order::getTotalAuthorized()` can be used instead.
- Removed `craft\commerce\services\Payments::getTotalPaidForOrder()`. `craft\commerce\elements\Order::getTotalPaid()` can be used instead.
- Removed `craft\commerce\services\Payments::getTotalRefundedForOrder()`.
- Removed `craft\commerce\services\Sales::populateSaleRelations()`.
- Removed `craft\commerce\services\States`.
