<?php //strict

namespace Debit\Methods;

use Debit\Helper\DebitHelper;
use Debit\Services\SessionStorageService;
use Debit\Services\SettingsService;
use Plenty\Modules\Account\Contact\Contracts\ContactRepositoryContract;
use Plenty\Modules\Account\Contact\Models\Contact;
use Plenty\Modules\Account\Contact\Models\ContactAllowedMethodOfPayment;
use Plenty\Modules\Category\Contracts\CategoryRepositoryContract;
use Plenty\Modules\Frontend\PaymentMethod\Contracts\FrontendPaymentMethodRepositoryContract;
use Plenty\Modules\Frontend\Services\AccountService;
use Plenty\Plugin\Application;
use Plenty\Modules\Frontend\Contracts\Checkout;
use Plenty\Modules\Payment\Method\Contracts\PaymentMethodService;
use Plenty\Modules\Basket\Contracts\BasketRepositoryContract;
use Plenty\Modules\Basket\Models\Basket;
use Plenty\Modules\Frontend\Session\Storage\Contracts\FrontendSessionStorageFactoryContract;

/**
 * Class DebitPaymentMethod
 * @package Debit\Methods
 */
class DebitPaymentMethod extends PaymentMethodService
{
    /** @var SettingsService */
    protected $settings;

    /** @var  SessionStorageService */
    protected $session;

    /** @var  Checkout */
    protected $checkout;

    /** @var AccountService */
    protected $accountService;

    /** @var DebitHelper  */
    protected $debitHelper;

    public function __construct(
        SettingsService $settings,
        SessionStorageService $session,
        Checkout $checkout,
        AccountService $accountService,
        DebitHelper $debitHelper
    ) {
        $this->settings = $settings;
        $this->session  = $session;
        $this->checkout = $checkout;
        $this->accountService = $accountService;
        $this->debitHelper = $debitHelper;
    }

    /**
     * Check the configuration if the payment method is active
     * Return true if the payment method is active, else return false
     *
     * @param BasketRepositoryContract $basketRepositoryContract
     * @return bool
     */
    public function isActive( BasketRepositoryContract $basketRepositoryContract):bool
    {

        /** @var Basket $basket */
        $basket = $basketRepositoryContract->load();

        $lang = $this->session->getLang();

        if($this->accountService->getIsAccountLoggedIn() && $basket->customerId > 0) {
            /** @var ContactRepositoryContract $contactRepository */
            $contactRepository = pluginApp(ContactRepositoryContract::class);
            $contact = $contactRepository->findContactById($basket->customerId);
            if(!is_null($contact) && $contact instanceof Contact) {
                $allowed = $contact->allowedMethodsOfPayment->first(function($method) {
                   if($method instanceof ContactAllowedMethodOfPayment) {
                       if($method->methodOfPaymentId == $this->debitHelper->getDebitMopId() && $method->allowed) {
                           return true;
                       }
                   }
                });
                if($allowed) {
                    return true;
                }

                if((int)$this->settings->getSetting('quorumOrders') > 0 && $contact->orderSummary->orderCount < $this->settings->getSetting('quorumOrders')) {
                    return false;
                }
            }
        } elseif ((int)$this->settings->getSetting('quorumOrders') > 0 && !$this->accountService->getIsAccountLoggedIn()) {
            return false;
        }

        /**
         * Check the minimum amount
         */
        if( $this->settings->getSetting('minimumAmount',$lang) > 0.00 &&
            $basket->basketAmount < $this->settings->getSetting('minimumAmount'))
        {
            return false;
        }

        /**
         * Check the maximum amount
         */
        if( $this->settings->getSetting('maximumAmount',$lang) > 0.00 &&
            $this->settings->getSetting('maximumAmount',$lang) < $basket->basketAmount)
        {
            return false;
        }


        /**
         * Check whether the debit address is the same as the shipping address
         */
        if( $this->settings->getSetting('debitEqualsShippingAddress',$lang) == 1)
        {
            $invoiceAddressId = $basket->customerInvoiceAddressId;
            $shippingAddressId = $basket->customerShippingAddressId;

            if($shippingAddressId != null && $invoiceAddressId != $shippingAddressId)
            {
                return false;
            }
        }

        /**
         * Check whether the user is logged in
         */
        if( $this->settings->getSetting('disallowDebitForGuest',$lang) == 1 && !$this->accountService->getIsAccountLoggedIn())
        {
            return false;
        }

        if(!in_array($this->checkout->getShippingCountryId(), $this->settings->getSetting('shippingCountries')))
        {
            return false;
        }

        return true;
    }

    /**
     * Get shown name
     *
     * @param $lang
     * @return string
     */
    public function getName($lang = 'de')
    {
        $name = $this->settings->getSetting('name', $lang);
        if(!strlen($name) > 0)
        {
            return 'Lastschrift';
        }
        return $name;
    }

    /**
     * Get additional costs for the payment method. Additional costs can be entered in the config.json.
     *
     * @param BasketRepositoryContract $basketRepositoryContract
     * @return float
     */
    public function getFee( BasketRepositoryContract $basketRepositoryContract):float
    {
        return 0.00;
        $basket = $basketRepositoryContract->load();
        if($basket->shippingCountryId == 1)
        {
            return $this->settings->getSetting('feeDomestic', $this->session->getLang());
        }
        else
        {
            return $this->settings->getSetting('feeForeign', $this->session->getLang());
        }
    }

    /**
     * Get the path of the icon
     *
     * @return string
     */
    public function getIcon( ):string
    {
        if( $this->settings->getSetting('logo') == 1)
        {
            return $this->settings->getSetting('logoUrl');
        }
        elseif($this->settings->getSetting('logo') == 2)
        {
            $app = pluginApp(Application::class);
            $icon = $app->getUrlPath('debit').'/images/icon.png';

            return $icon;
        }

        return '';
    }

    /**
     * Get DebitSourceUrl
     *
     * @return string
     */
    public function getSourceUrl()
    {
        /** @var FrontendSessionStorageFactoryContract $session */
        $session = pluginApp(FrontendSessionStorageFactoryContract::class);
        $lang = $session->getLocaleSettings()->language;

        $infoPageType = $this->settings->getSetting('infoPageType', $lang);

        switch ($infoPageType)
        {
            case 1:
                // internal
                $categoryId = (int) $this->settings->getSetting('infoPageIntern', $lang);
                if($categoryId  > 0)
                {
                    /** @var CategoryRepositoryContract $categoryContract */
                    $categoryContract = pluginApp(CategoryRepositoryContract::class);
                    return $categoryContract->getUrl($categoryId, $lang);
                }
                return '';
            case 2:
                // external
                return $this->settings->getSetting('infoPageExtern', $lang);
            default:
                return '';
        }
    }


    /**
     * Get the description of the payment method. The description can be entered in the config.json.
     *
     * @return string
     */
    public function getDescription():string
    {
        /** @var FrontendSessionStorageFactoryContract $session */
        $session = pluginApp(FrontendSessionStorageFactoryContract::class);
        $lang = $session->getLocaleSettings()->language;
        return $this->settings->getSetting('description', $lang);
    }

    /**
     * Check if it is allowed to switch to this payment method
     *
     * @return bool
     */
    public function isSwitchableTo():bool
    {
        return true;
    }

    /**
     * Check if it is allowed to switch from this payment method
     *
     * @return bool
     */
    public function isSwitchableFrom():bool
    {
        return false;
    }
}
