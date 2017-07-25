<?php
/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Enterprise License (PEL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 * @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 * @license    http://www.pimcore.org/license     GPLv3 and PEL
 */

namespace Pimcore\Bundle\EcommerceFrameworkBundle;

use Pimcore\Bundle\EcommerceFrameworkBundle\AvailabilitySystem\IAvailabilitySystem;
use Pimcore\Bundle\EcommerceFrameworkBundle\CartManager\ICart;
use Pimcore\Bundle\EcommerceFrameworkBundle\CartManager\ICartManager;
use Pimcore\Bundle\EcommerceFrameworkBundle\CheckoutManager\ICheckoutManager;
use Pimcore\Bundle\EcommerceFrameworkBundle\CheckoutManager\ICheckoutManagerFactory;
use Pimcore\Bundle\EcommerceFrameworkBundle\CheckoutManager\ICommitOrderProcessor;
use Pimcore\Bundle\EcommerceFrameworkBundle\DependencyInjection\PimcoreEcommerceFrameworkExtension;
use Pimcore\Bundle\EcommerceFrameworkBundle\Exception\InvalidConfigException;
use Pimcore\Bundle\EcommerceFrameworkBundle\Exception\UnsupportedException;
use Pimcore\Bundle\EcommerceFrameworkBundle\FilterService\FilterService;
use Pimcore\Bundle\EcommerceFrameworkBundle\IndexService\IndexService;
use Pimcore\Bundle\EcommerceFrameworkBundle\Model\AbstractVoucherTokenType;
use Pimcore\Bundle\EcommerceFrameworkBundle\OfferTool\IService;
use Pimcore\Bundle\EcommerceFrameworkBundle\OrderManager\IOrderManager;
use Pimcore\Bundle\EcommerceFrameworkBundle\PaymentManager\IPaymentManager;
use Pimcore\Bundle\EcommerceFrameworkBundle\PriceSystem\IPriceSystem;
use Pimcore\Bundle\EcommerceFrameworkBundle\PricingManager\IPricingManager;
use Pimcore\Bundle\EcommerceFrameworkBundle\Tracking\ITrackingManager;
use Pimcore\Bundle\EcommerceFrameworkBundle\VoucherService\IVoucherService;
use Pimcore\Bundle\EcommerceFrameworkBundle\VoucherService\TokenManager\ITokenManager;
use Pimcore\Config\Config;
use Psr\Container\ContainerInterface as PsrContainerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class Factory
{
    /**
     * framework configuration file
     */
    const CONFIG_PATH = PIMCORE_CUSTOM_CONFIGURATION_DIRECTORY . '/EcommerceFrameworkConfig.php';

    /**
     * @var ContainerInterface
     */
    private $container;

    /**
     * @var IEnvironment
     */
    private $environment;

    /**
     * Tenant specific cart managers
     *
     * @var PsrContainerInterface
     */
    private $cartManagers;

    /**
     * Tenant specific order managers
     *
     * @var PsrContainerInterface
     */
    private $orderManagers;

    /**
     * Price systems registered by name
     *
     * @var PsrContainerInterface
     */
    private $priceSystems;

    /**
     * Availability systems registered by name
     *
     * @var PsrContainerInterface
     */
    private $availabilitySystems;

    /**
     * Checkout manager factories registered by "name.tenant"
     *
     * @var PsrContainerInterface
     */
    private $checkoutManagerFactories;

    /**
     * Commit order processors registered by "checkout_manager_name.checkout_manager_tenant"
     *
     * @var PsrContainerInterface
     */
    private $commitOrderProcessors;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var ICheckoutManager
     */
    private $checkoutManagers;

    /**
     * @var string[]
     */
    private $allTenants;

    /**
     * Systems with multiple instances (e.g. price systems or tenant specific systems) are
     * injected through a service locator which is indexed by tenant/name. All other services
     * are loaded from the container on demand to make sure only services needed are built.
     *
     * @param ContainerInterface $container
     * @param PsrContainerInterface $cartManagers
     * @param PsrContainerInterface $orderManagers
     * @param PsrContainerInterface $priceSystemsLocator
     * @param PsrContainerInterface $availabilitySystems
     * @param PsrContainerInterface $checkoutManagerFactories
     * @param PsrContainerInterface $commitOrderProcessors
     */
    public function __construct(
        ContainerInterface $container,
        PsrContainerInterface $cartManagers,
        PsrContainerInterface $orderManagers,
        PsrContainerInterface $priceSystemsLocator,
        PsrContainerInterface $availabilitySystems,
        PsrContainerInterface $checkoutManagerFactories,
        PsrContainerInterface $commitOrderProcessors
    )
    {
        $this->container                = $container;
        $this->cartManagers             = $cartManagers;
        $this->orderManagers            = $orderManagers;
        $this->priceSystems             = $priceSystemsLocator;
        $this->availabilitySystems      = $availabilitySystems;
        $this->checkoutManagerFactories = $checkoutManagerFactories;
        $this->commitOrderProcessors    = $commitOrderProcessors;

        $this->init();
    }

    public static function getInstance(): self
    {
        return \Pimcore::getContainer()->get(Factory::class);
    }

    public function getEnvironment(): IEnvironment
    {
        return $this->container->get(PimcoreEcommerceFrameworkExtension::SERVICE_ID_ENVIRONMENT);
    }

    /**
     * Returns cart manager for a specific tenant. If no tenant is passed it will fall back to the current
     * checkout tenant or to "default" if no current checkout tenant is set.
     *
     * @param string|null $tenant
     *
     * @return ICartManager
     * @throws UnsupportedException
     */
    public function getCartManager(string $tenant = null): ICartManager
    {
        if (null === $tenant) {
            $tenant = $this->getEnvironment()->getCurrentCheckoutTenant() ?? 'default';
        }

        if (!$this->cartManagers->has($tenant)) {
            throw new UnsupportedException(sprintf(
                'Cart manager for tenant "%s" is not defined. Please check the configuration.',
                $tenant
            ));
        }

        return $this->cartManagers->get($tenant);
    }

    /**
     * Returns order manager for a specific tenant. If no tenant is passed it will fall back to the current
     * checkout tenant or to "default" if no current checkout tenant is set.
     *
     * @param string|null $tenant
     *
     * @return IOrderManager
     * @throws UnsupportedException
     */
    public function getOrderManager(string $tenant = null): IOrderManager
    {
        if (null === $tenant) {
            $tenant = $this->getEnvironment()->getCurrentCheckoutTenant() ?? 'default';
        }

        if (!$this->orderManagers->has($tenant)) {
            throw new UnsupportedException(sprintf(
                'Order manager for tenant "%s" is not defined. Please check the configuration.',
                $tenant
            ));
        }

        return $this->orderManagers->get($tenant);
    }

    public function getPricingManager(): IPricingManager
    {
        return $this->container->get(PimcoreEcommerceFrameworkExtension::SERVICE_ID_PRICING_MANAGER);
    }

    /**
     * Returns a price system by name. Falls back to "default" if no name is passed.
     *
     * @param string|null $name
     *
     * @return IPriceSystem
     * @throws UnsupportedException
     */
    public function getPriceSystem(string $name = null): IPriceSystem
    {
        if (null === $name) {
            $name = 'default';
        }

        if (!$this->priceSystems->has($name)) {
            throw new UnsupportedException(sprintf(
                'Price system "%s" is not supported. Please check the configuration.',
                $name
            ));
        }

        return $this->priceSystems->get($name);
    }

    /**
     * Returns an availability system by name. Falls back to "default" if no name is passed.
     *
     * @param string|null $name
     *
     * @return IAvailabilitySystem
     * @throws UnsupportedException
     */
    public function getAvailabilitySystem(string $name = null): IAvailabilitySystem
    {
        if (null === $name) {
            $name = 'default';
        }

        if (!$this->availabilitySystems->has($name)) {
            throw new UnsupportedException(sprintf(
                'Availability system "%s" is not supported. Please check the configuration.',
                $name
            ));
        }

        return $this->availabilitySystems->get($name);
    }

    /**
     * Returns a checkout manager specific to a cart instance. Checkout managers support
     * named managers which in turn can support multiple tenants. If no name or tenant is
     * passed, the tenant "default" from a checkout manager named "default" will be loaded.
     *
     * @param ICart $cart
     * @param string|null $name
     * @param string|null $tenant
     *
     * @return ICheckoutManager
     * @throws UnsupportedException
     */
    public function getCheckoutManager(ICart $cart, string $name = null, string $tenant = null): ICheckoutManager
    {
        $serviceId = $this->buildCheckoutManagerServiceId($name, $tenant);

        if (!$this->commitOrderProcessors->has($serviceId)) {
            list($normalizedName, $normalizedTenant) = explode('.', $serviceId);

            throw new UnsupportedException(sprintf(
                'There is no factory defined for checkout manager with name "%s" and tenant "%s". Please check the configuration.',
                $normalizedName,
                $normalizedTenant
            ));
        }

        /** @var ICheckoutManagerFactory $factory */
        $factory = $this->checkoutManagerFactories->get($serviceId);

        return $factory->createCheckoutManager($cart);
    }

    /**
     * Returns a commit order processor which is configured for a specific checkout manager. The checkoutManagerName and
     * tenant parameters follow the same logic as for the checkout manager itself.
     *
     * @param string|null $checkoutManagerName
     * @param string|null $tenant
     *
     * @return ICommitOrderProcessor
     * @throws UnsupportedException
     */
    public function getCommitOrderProcessor(string $checkoutManagerName = null, string $tenant = null): ICommitOrderProcessor
    {
        $serviceId = $this->buildCheckoutManagerServiceId($checkoutManagerName, $tenant);

        if (!$this->commitOrderProcessors->has($serviceId)) {
            list($normalizedName, $normalizedTenant) = explode('.', $serviceId);

            throw new UnsupportedException(sprintf(
                'Commit order processor for checkout manager name "%s" and tenant "%s" is not defined. Please check the configuration.',
                $normalizedName,
                $normalizedTenant
            ));
        }

        return $this->commitOrderProcessors->get($serviceId);
    }

    public function getPaymentManager(): IPaymentManager
    {
        return $this->container->get(PimcoreEcommerceFrameworkExtension::SERVICE_ID_PAYMENT_MANAGER);
    }

    public function getOfferToolService(): IService
    {
        return $this->container->get(PimcoreEcommerceFrameworkExtension::SERVICE_ID_OFFER_TOOL);
    }

    public function getVoucherService(): IVoucherService
    {
        return $this->container->get(PimcoreEcommerceFrameworkExtension::SERVICE_ID_VOUCHER_SERVICE);
    }

    /**
     * Builds a token manager for a specific token configuration
     *
     * @param AbstractVoucherTokenType $configuration
     *
     * @return ITokenManager
     */
    public function getTokenManager(AbstractVoucherTokenType $configuration): ITokenManager
    {
        $tokenManagerFactory = $this->container->get(PimcoreEcommerceFrameworkExtension::SERVICE_ID_TOKEN_MANAGER_FACTORY);

        return $tokenManagerFactory->getTokenManager($configuration);
    }

    public function getTrackingManager(): ITrackingManager
    {
        return $this->container->get(PimcoreEcommerceFrameworkExtension::SERVICE_ID_TRACKING_MANAGER);
    }

    /**
     * Normalize name and tenant to "default" and/or current checkout tenant
     *
     * @param string|null $name
     * @param string|null $tenant
     *
     * @return string
     */
    private function buildCheckoutManagerServiceId(string $name = null, string $tenant = null): string
    {
        return sprintf(
            '%s.%s',
            $name ?? 'default',
            $tenant ?? $this->getEnvironment()->getCurrentCheckoutTenant() ?? 'default'
        );
    }




    /**
     * creates new factory instance and optionally resets environment too
     *
     * @param bool|true $keepEnvironment
     *
     * @return Factory
     */
    public static function resetInstance($keepEnvironment = true)
    {
        throw new \RuntimeException(__METHOD__ . ' is not implemented anymore');

        if ($keepEnvironment) {
            $environment = self::$instance->getEnvironment();
        } else {
            $environment = null;
        }

        self::$instance = new self($environment);
        self::$instance->init();

        return self::$instance;
    }

    public function getConfig()
    {
        if (empty($this->config)) {
            $this->config = new Config(require self::CONFIG_PATH, true);
        }

        return $this->config;
    }

    private function init()
    {
        $config = $this->getConfig();
        $this->checkConfig($config);
    }

    private function checkConfig($config)
    {
    }

    /**
     * @var IndexService
     */
    private $indexService = null;

    /**
     * @return IndexService
     */
    public function getIndexService()
    {
        if (empty($this->indexService)) {
            $this->indexService = new IndexService($this->config->ecommerceframework->productindex);
        }

        return $this->indexService;
    }

    /**
     * @return string[]
     */
    public function getAllTenants()
    {
        if (empty($this->allTenants) && $this->config->ecommerceframework->productindex->tenants && $this->config->ecommerceframework->productindex->tenants instanceof Config) {
            foreach ($this->config->ecommerceframework->productindex->tenants as $name => $tenant) {
                $this->allTenants[$name] = $name;
            }
        }

        return $this->allTenants;
    }

    /**
     * @return FilterService
     */
    public function getFilterService()
    {
        $filterTypes = $this->getIndexService()->getCurrentTenantConfig()->getFilterTypeConfig();
        if (!$filterTypes) {
            $filterTypes = $this->config->ecommerceframework->filtertypes;
        }

        $translator = \Pimcore::getContainer()->get('translator');
        $renderer =  \Pimcore::getContainer()->get('templating');

        return new FilterService($filterTypes, $translator, $renderer);
    }

    public function saveState()
    {
        $this->getCartManager()->save();
        $this->environment->save();
    }

}
