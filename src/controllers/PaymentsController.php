<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\commerce\controllers;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\errors\CurrencyException;
use craft\commerce\errors\PaymentException;
use craft\commerce\errors\PaymentSourceException;
use craft\commerce\helpers\PaymentForm;
use craft\commerce\models\PaymentSource;
use craft\commerce\Plugin;
use craft\errors\ElementNotFoundException;
use craft\helpers\App;
use Throwable;
use yii\base\Exception;
use yii\base\InvalidConfigException;
use yii\base\NotSupportedException;
use yii\web\BadRequestHttpException;
use yii\web\HttpException;
use yii\web\Response;

/**
 * Class Payments Controller
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 2.0
 */
class PaymentsController extends BaseFrontEndController
{
    /**
     * @var string
     */
    private string $_cartVariableName;

    public function init(): void
    {
        parent::init();
        $this->_cartVariableName = Plugin::getInstance()->getSettings()->cartVariable;
    }

    /**
     * @inheritDoc
     */
    public function beforeAction($action): bool
    {
        // Don't enable CSRF validation for complete-payment requests since they can come from offsite, and the transaction hash is validated anyway.
        if ($action->id === 'complete-payment') {
            $this->enableCsrfValidation = false;
        }

        return parent::beforeAction($action);
    }

    /**
     * @throws CurrencyException
     * @throws Exception
     * @throws NotSupportedException
     * @throws Throwable
     * @throws ElementNotFoundException
     * @throws InvalidConfigException
     * @throws BadRequestHttpException
     */
    public function actionPay(): ?Response
    {
        $this->requirePostRequest();

        $error = '';

        /** @var Plugin $plugin */
        $plugin = Plugin::getInstance();
        $currentUser = Craft::$app->getUser()->getIdentity();
        $isSiteRequest = $this->request->getIsSiteRequest();
        $isCpRequest = $this->request->getIsCpRequest();
        $userSession = Craft::$app->getUser();

        $number = $this->request->getParam('number');

        if ($number !== null) {
            /** @var Order $order */
            $order = $plugin->getOrders()->getOrderByNumber($number);

            if (!$order) {
                $error = Craft::t('commerce', 'Can not find an order to pay.');

                if ($this->request->getAcceptsJson()) {
                    return $this->asFailure($error, data: [
                        $this->_cartVariableName => null,
                    ]);
                }

                $this->setFailFlash($error);

                return null;
            }

            // TODO Fix this in Commerce 4. `order` if completed, `cartVariableName` if no completed. #COM-36
            $this->_cartVariableName = 'order'; // can not override the name of the order cart in json responses for orders
        } else {
            $order = $plugin->getCarts()->getCart();
        }

        /**
         * Payments on completed orders can only be made if the order number and email
         * address are passed to the payments controller. If this is via the CP it
         * requires the user have the correct permission.
         */
        $isSiteRequestAndAllowed = $isSiteRequest && $order->getEmail() == $this->request->getParam('email');
        $isCpAndAllowed = $isCpRequest && $currentUser && $currentUser->can('commerce-manageOrders');
        $checkPaymentCanBeMade = $number && ($isSiteRequestAndAllowed || $isCpAndAllowed);

        if (!$order->getIsActiveCart() && !$checkPaymentCanBeMade) {
            $error = Craft::t('commerce', 'Email required to make payments on a completed order.');
            return $this->asFailure($error, data: [
                $this->_cartVariableName => $this->cartArray($order),
            ]);
        }

        if ($plugin->getSettings()->requireShippingAddressAtCheckout && !$order->shippingAddressId) {
            $error = Craft::t('commerce', 'Shipping address required.');
            return $this->asFailure($error, data: [
                $this->_cartVariableName => $this->cartArray($order),
            ]);
        }

        if ($plugin->getSettings()->requireBillingAddressAtCheckout && !$order->billingAddressId) {
            $error = Craft::t('commerce', 'Billing address required.');
            return $this->asFailure($error, data: [
                $this->_cartVariableName => $this->cartArray($order),
            ]);
        }

        if (!$plugin->getSettings()->allowEmptyCartOnCheckout && $order->getIsEmpty()) {
            $error = Craft::t('commerce', 'Order can not be empty.');
            return $this->asFailure($error, data: [
                $this->_cartVariableName => $this->cartArray($order),
            ]);
        }

        // Set if the customer should be registered on order completion
        if ($this->request->getBodyParam('registerUserOnOrderComplete')) {
            $order->registerUserOnOrderComplete = true;
        }

        if ($this->request->getBodyParam('registerUserOnOrderComplete') === 'false') {
            $order->registerUserOnOrderComplete = false;
        }

        // These are used to compare if the order changed during its final
        // recalculation before payment.
        $originalTotalPrice = $order->getOutstandingBalance();
        $originalTotalQty = $order->getTotalQty();
        $originalTotalAdjustments = count($order->getAdjustments());

        // Set guest email address onto guest customer and order.
        if ($paymentCurrency = $this->request->getParam('paymentCurrency')) {
            try {
                $order->setPaymentCurrency($paymentCurrency);
            } catch (CurrencyException $exception) {
                Craft::$app->getErrorHandler()->logException($exception);

                $error = $exception->getMessage();
                $order->addError('paymentCurrency', $exception->getMessage());

                return $this->asFailure($error, data: [
                    $this->_cartVariableName => $this->cartArray($order),
                ]);
            }
        }

        // Set Payment Gateway on cart
        // Same as CartController::updateCart()
        if ($gatewayId = $this->request->getParam('gatewayId')) {
            if ($plugin->getGateways()->getGatewayById($gatewayId)) {
                $order->setGatewayId($gatewayId);
            }
        }

        // Submit payment source on cart
        // See CartController::updateCart()
        if ($paymentSourceId = $this->request->getParam('paymentSourceId')) {
            if ($paymentSource = $plugin->getPaymentSources()->getPaymentSourceById($paymentSourceId)) {
                // The payment source can only be used by the same user as the cart's user.
                $cartUserId = $order->getCustomer()?->id;
                $paymentSourceUserId = $paymentSource->getCustomer()?->id;
                $allowedToUsePaymentSource = ($cartUserId && $paymentSourceUserId && $currentUser && $isSiteRequest && ($paymentSourceUserId == $cartUserId));
                if ($allowedToUsePaymentSource) {
                    $order->setPaymentSource($paymentSource);
                }
            }
        }

        // This will return the gateway to be used. The orders gateway ID could be null, but it will know the gateway from the paymentSource ID
        $gateway = $order->getGateway();

        if (!$gateway || !$gateway->availableForUseWithOrder($order) || (!App::parseBooleanEnv($gateway->isFrontendEnabled) && !$isCpRequest)) {
            $error = Craft::t('commerce', 'There is no gateway or payment source available for use with this order.');

            if ($order->gatewayId) {
                $order->addError('gatewayId', $error);
            }

            if ($order->paymentSourceId) {
                $order->addError('paymentSourceId', $error);
            }

            return $this->asFailure($error, data: [
                $this->_cartVariableName => $this->cartArray($order),
            ]);
        }

        // We need the payment form whether we are populating it from the request or from the payment source.
        $paymentForm = $gateway->getPaymentFormModel();

        /**
         *
         * Are we paying with:
         *
         * 1) The current order paymentSourceId
         * OR
         * 2) The current order gatewayId and a payment form populated from the request
         *
         */

        // 1) Paying with the current order paymentSourceId
        if ($order->paymentSourceId) {
            /** @var PaymentSource $paymentSource */
            $paymentSource = $order->getPaymentSource();
            if ($gateway->supportsPaymentSources()) {
                $paymentForm->populateFromPaymentSource($paymentSource);
            }
        }

        // 2) Paying with the current order gatewayId and a payment form populated from the request
        if ($order->gatewayId && !$order->paymentSourceId) {

            // Populate the payment form from the params
            $paymentFormParams = $this->request->getBodyParam(PaymentForm::getPaymentFormParamName($gateway->handle));

            if ($paymentFormParams) {
                $paymentForm->setAttributes($paymentFormParams, false);
            }

            // Does the user want to save this card as a payment source?
            if ($currentUser && $this->request->getBodyParam('savePaymentSource') && $gateway->supportsPaymentSources()) {
                try {
                    $paymentSource = $plugin->getPaymentSources()->createPaymentSource($currentUser->id, $gateway, $paymentForm);
                } catch (PaymentSourceException $exception) {
                    Craft::$app->getErrorHandler()->logException($exception);

                    $error = $exception->getMessage();

                    return $this->asModelFailure(
                        $paymentForm,
                        $error,
                        'paymentForm',
                        [
                            $this->_cartVariableName => $this->cartArray($order),
                            'paymentFormErrors' => $paymentForm->getErrors(),
                        ],
                        [
                            $this->_cartVariableName => $order,
                        ]
                    );
                }

                $order->setPaymentSource($paymentSource);
                $paymentForm->populateFromPaymentSource($paymentSource);
            }
        }

        // Allowed to update order's custom fields?
        if ($order->getIsActiveCart() || $userSession->checkPermission('commerce-manageOrders')) {
            $order->setFieldValuesFromRequest('fields');
        }

        // Check email address exists on order.
        if (!$order->email) {
            $error = Craft::t('commerce', 'No customer email address exists on this cart.');

            return $this->asModelFailure(
                $paymentForm,
                $error,
                'paymentForm',
                [
                    $this->_cartVariableName => $this->cartArray($order),
                    'paymentFormErrors' => $paymentForm->getErrors(),
                ],
                [
                    $this->_cartVariableName => $order,
                ]
            );
        }

        // Does the order require shipping
        if ($order->hasShippableItems() && $plugin->getSettings()->requireShippingMethodSelectionAtCheckout && !$order->shippingMethodHandle) {
            $error = Craft::t('commerce', 'There is no shipping method selected for this order.');

            return $this->asModelFailure(
                $paymentForm,
                $error,
                'paymentForm',
                [
                    $this->_cartVariableName => $this->cartArray($order),
                ]
            );
        }

        // Save the return and cancel URLs to the order
        $returnUrl = $this->request->getValidatedBodyParam('redirect');
        $cancelUrl = $this->request->getValidatedBodyParam('cancelUrl');

        if ($returnUrl !== null && $cancelUrl !== null) {
            $view = $this->getView();
            $order->returnUrl = $view->renderObjectTemplate($returnUrl, $order);
            $order->cancelUrl = $view->renderObjectTemplate($cancelUrl, $order);
        }

        // Do one final save to confirm the price does not change out from under the customer. Also removes any out of stock items etc.
        // This also confirms the products are available and discounts are current.
        $order->recalculate();
        // Save the orders new values.

        $totalPriceChanged = $originalTotalPrice != $order->getOutstandingBalance();
        $totalQtyChanged = $originalTotalQty != $order->getTotalQty();
        $totalAdjustmentsChanged = $originalTotalAdjustments != count($order->getAdjustments());

        $updateCartSearchIndexes = Plugin::getInstance()->getSettings()->updateCartSearchIndexes;
        $updateSearchIndex = ($order->isCompleted || $updateCartSearchIndexes);

        if (Craft::$app->getElements()->saveElement($order, true, false, $updateSearchIndex)) {
            // Has the order changed in a significant way?
            if ($totalPriceChanged || $totalQtyChanged || $totalAdjustmentsChanged) {
                if ($totalPriceChanged) {
                    $order->addError('totalPrice', Craft::t('commerce', 'The total price of the order changed.'));
                }

                if ($totalQtyChanged) {
                    $order->addError('totalQty', Craft::t('commerce', 'The total quantity of items within the order changed.'));
                }

                if ($totalAdjustmentsChanged) {
                    $order->addError('totalAdjustments', Craft::t('commerce', 'The total number of order adjustments changed.'));
                }

                $error = Craft::t('commerce', 'Something changed with the order before payment, please review your order and submit payment again.');

                return $this->asModelFailure(
                    $paymentForm,
                    $error,
                    'paymentForm',
                    [
                        $this->_cartVariableName => $this->cartArray($order),
                        'paymentFormErrors' => $paymentForm->getErrors(),
                    ],
                    [
                        $this->_cartVariableName => $order,
                    ]
                );
            }
        }

        $redirect = '';
        $transaction = null;
        $paymentForm->validate();

        // Make sure during this payment request the order does not recalculate.
        // We don't want to save the order in this mode in case the payment fails. The customer should still be able to edit and recalculate the cart.
        // When the order is marked as complete from a payment later, the order will be set to 'recalculate none' mode permanently.
        $order->setRecalculationMode(Order::RECALCULATION_MODE_NONE);

        // set a partial payment amount on the order in the orders currency (not payment currency)
        $partialAllowed = (($this->request->isSiteRequest && Plugin::getInstance()->getSettings()->allowPartialPaymentOnCheckout) || $this->request->isCpRequest);

        if ($partialAllowed) {
            if ($isCpAndAllowed) {
                $order->setPaymentAmount($this->request->getBodyParam('paymentAmount'));
            } elseif ($this->request->getBodyParam('paymentAmount')) {
                $paymentAmount = $this->request->getValidatedBodyParam('paymentAmount');
                $order->setPaymentAmount($paymentAmount);
            }
        }

        if ((!$partialAllowed || !$gateway->supportsPartialPayment()) && $order->isPaymentAmountPartial()) {
            $error = Craft::t('commerce', 'Partial payment not allowed.');

            return $this->asModelFailure(
                $paymentForm,
                $error,
                'paymentForm',
                [
                    $this->_cartVariableName => $this->cartArray($order),
                    'paymentFormErrors' => $paymentForm->getErrors(),
                ],
                [
                    $this->_cartVariableName => $order,
                ]
            );
        }

        if (!$paymentForm->hasErrors() && !$order->hasErrors()) {
            try {
                $plugin->getPayments()->processPayment($order, $paymentForm, $redirect, $transaction);
                $success = true;
            } catch (PaymentException $exception) {
                $error = $exception->getMessage();
                $success = false;
            }
        } else {
            $error = Craft::t('commerce', 'Invalid payment or order. Please review.');
            $success = false;
        }

        if (!$success) {
            return $this->asModelFailure(
                $paymentForm,
                $error,
                'paymentForm',
                [
                    $this->_cartVariableName => $this->cartArray($order),
                    'paymentFormErrors' => $paymentForm->getErrors(),
                ],
                [
                    $this->_cartVariableName => $order,
                ]
            );
        }


        if ($this->request->getAcceptsJson()) {
            return $this->asModelSuccess(
                $paymentForm,
                $error,
                'paymentForm',
                [
                    $this->_cartVariableName => $this->cartArray($order),
                    'redirect' => $redirect,
                    'transactionId' => $transaction->reference ?? null,
                    'transactionHash' => $transaction->hash ?? null,
                ],
                $redirect
            );
        }

        if ($redirect) {
            return $this->redirect($redirect);
        }

        if ($order->returnUrl) {
            return $this->redirect($order->returnUrl);
        }

        return $this->redirectToPostedUrl($order);
    }

    /**
     * Processes return from off-site payment
     *
     * @throws Exception
     * @throws HttpException
     */
    public function actionCompletePayment(): Response
    {
        /** @var Plugin $plugin */
        $plugin = Plugin::getInstance();

        $hash = $this->request->getParam('commerceTransactionHash');

        $transaction = $plugin->getTransactions()->getTransactionByHash($hash);

        if (!$transaction) {
            throw new HttpException(400, Craft::t('commerce', 'Can not complete payment for missing transaction.'));
        }

        $error = '';
        $success = $plugin->getPayments()->completePayment($transaction, $error);

        if (!$success) {
            $errorMessage = Craft::t('commerce', 'Payment error: {message}', ['message' => $error]);
            $this->setFailFlash($errorMessage);

            if ($this->request->getAcceptsJson()) {
                $data = [
                    'url' => $transaction->order->cancelUrl,
                ];

                return $this->asFailure($errorMessage, data: $data);
            }

            return $this->redirect($transaction->order->cancelUrl);
        }

        if ($this->request->getAcceptsJson()) {
            $data = [
                'url' => $transaction->order->returnUrl,
            ];

            return $this->asSuccess(data: $data);
        }

        return $this->redirect($transaction->order->returnUrl);
    }
}
