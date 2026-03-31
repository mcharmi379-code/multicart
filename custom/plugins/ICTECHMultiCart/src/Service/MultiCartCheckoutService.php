<?php declare(strict_types=1);

namespace ICTECHMultiCart\Service;

use Shopware\Core\Checkout\Cart\LineItemFactoryRegistry;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Promotion\Cart\PromotionItemBuilder;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannel\ContextSwitchRoute;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

final class MultiCartCheckoutService
{
    private const ORDER_TRACKING_SESSION_KEY = 'ictech_multi_cart.prepared_checkout';

    /**
     * @var list<string>
     */
    private const CONTEXT_PREFERENCE_FIELDS = [
        'shippingAddressId',
        'billingAddressId',
        'paymentMethodId',
        'shippingMethodId',
    ];

    public function __construct(
        private readonly MultiCartStorefrontContextService $contextService,
        private readonly CartService $cartService,
        private readonly LineItemFactoryRegistry $lineItemFactoryRegistry,
        private readonly ContextSwitchRoute $contextSwitchRoute,
        private readonly PromotionItemBuilder $promotionItemBuilder,
        private readonly RequestStack $requestStack
    ) {
    }

    public function prepareCheckout(?string $cartId, SalesChannelContext $salesChannelContext): bool
    {
        $state = $this->contextService->getState($salesChannelContext);
        $targetCartId = $cartId !== null && $cartId !== '' ? $cartId : $state['activeCartId'];

        if (!is_string($targetCartId) || $targetCartId === '') {
            $this->clearPreparedCheckout();

            return false;
        }

        if (!$this->contextService->activateCart($targetCartId, $salesChannelContext)) {
            $this->clearPreparedCheckout();

            return false;
        }

        $selectedCart = $this->contextService->getCartSummary($targetCartId, $salesChannelContext);

        if ($selectedCart === null) {
            $this->clearPreparedCheckout();

            return false;
        }

        return $this->prepareSelectedCarts([$selectedCart], $salesChannelContext);
    }

    /**
     * @param list<string> $cartIds
     * @param array{
     *     shippingAddressId?: string|null,
     *     billingAddressId?: string|null,
     *     paymentMethodId?: string|null,
     *     shippingMethodId?: string|null
     * } $preferenceOverride
     */
    public function prepareCombinedCheckout(array $cartIds, SalesChannelContext $salesChannelContext, array $preferenceOverride = []): bool
    {
        $state = $this->contextService->getState($salesChannelContext);

        if (
            !$state['enabled']
            || !$state['customerLoggedIn']
            || !$state['customerAllowed']
            || $state['blacklisted']
            || !$state['checkoutPrefsEnabled']
            || !$state['multiPaymentEnabled']
        ) {
            $this->clearPreparedCheckout();

            return false;
        }

        $cartIds = array_values(array_filter($cartIds, static fn (string $cartId): bool => $cartId !== ''));

        if ($cartIds === []) {
            $this->clearPreparedCheckout();

            return false;
        }

        $selectedCarts = $this->loadSelectedCartsFromIds($cartIds, $salesChannelContext);

        if ($selectedCarts === []) {
            $this->clearPreparedCheckout();

            return false;
        }

        return $this->prepareSelectedCarts($selectedCarts, $salesChannelContext, $preferenceOverride);
    }

    /**
     * @param list<array{
     *     id?: mixed,
     *     items: list<array{productId?: mixed, quantity?: mixed}>,
     *     promotionCode?: mixed,
     *     shippingAddressId?: mixed,
     *     billingAddressId?: mixed,
     *     paymentMethodId?: mixed,
     *     shippingMethodId?: mixed
     * }> $selectedCarts
     * @param array{
     *     shippingAddressId?: string|null,
     *     billingAddressId?: string|null,
     *     paymentMethodId?: string|null,
     *     shippingMethodId?: string|null
     * } $preferenceOverride
     */
    private function prepareSelectedCarts(array $selectedCarts, SalesChannelContext $salesChannelContext, array $preferenceOverride = []): bool
    {
        $state = $this->contextService->getState($salesChannelContext);

        if ($selectedCarts === []) {
            $this->clearPreparedCheckout();

            return false;
        }

        $contextPayload = [];

        if ($state['checkoutPrefsEnabled']) {
            foreach (self::CONTEXT_PREFERENCE_FIELDS as $cartField) {
                $contextField = $this->getContextField($cartField);
                $value = $preferenceOverride[$cartField] ?? ($selectedCarts[0][$cartField] ?? null);

                if ($contextField !== null && is_string($value) && $value !== '') {
                    $contextPayload[$contextField] = $value;
                }
            }
        }

        if ($contextPayload !== []) {
            $this->contextSwitchRoute->switchContext(new RequestDataBag($contextPayload), $salesChannelContext);
        }

        $this->cartService->deleteCart($salesChannelContext);
        $cart = $this->cartService->createNew($salesChannelContext->getToken());

        foreach ($selectedCarts as $selectedCart) {
            $items = $selectedCart['items'] ?? null;

            if (!is_array($items)) {
                continue;
            }

            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $productId = $item['productId'] ?? null;
                $quantity = $item['quantity'] ?? null;

                if (!is_string($productId) || $productId === '' || !is_int($quantity) || $quantity <= 0) {
                    continue;
                }

                $lineItem = $this->lineItemFactoryRegistry->create([
                    'id' => $productId,
                    'referencedId' => $productId,
                    'type' => 'product',
                    'quantity' => $quantity,
                    'stackable' => true,
                    'removable' => true,
                ], $salesChannelContext);

                $cart = $this->cartService->add($cart, $lineItem, $salesChannelContext);
            }
        }

        if ($state['promotionsEnabled']) {
            $promotionCodes = [];

            foreach ($selectedCarts as $selectedCart) {
                $promotionCode = $selectedCart['promotionCode'] ?? null;

                if (is_string($promotionCode) && $promotionCode !== '') {
                    $promotionCodes[$promotionCode] = true;
                }
            }

            foreach (array_keys($promotionCodes) as $promotionCode) {
                $promotionItem = $this->promotionItemBuilder->buildPlaceholderItem($promotionCode);
                $cart = $this->cartService->add($cart, $promotionItem, $salesChannelContext);
            }
        }

        $this->cartService->recalculate($cart, $salesChannelContext);
        $this->storePreparedCheckout($selectedCarts, $salesChannelContext);

        return true;
    }

    /**
     * @param list<string> $cartIds
     *
     * @return list<array{
     *     id?: mixed,
     *     items: list<array{productId?: mixed, quantity?: mixed}>,
     *     promotionCode?: mixed,
     *     shippingAddressId?: mixed,
     *     billingAddressId?: mixed,
     *     paymentMethodId?: mixed,
     *     shippingMethodId?: mixed
     * }>
     */
    private function loadSelectedCartsFromIds(array $cartIds, SalesChannelContext $salesChannelContext): array
    {
        $selectedCarts = [];
        $state = $this->contextService->getState($salesChannelContext);

        foreach ($cartIds as $cartId) {
            $cart = $this->findCart($state['carts'], $cartId);

            if ($cart === null || !is_array($cart['items'] ?? null) || $cart['items'] === []) {
                continue;
            }

            $selectedCarts[] = $cart;
        }

        return $selectedCarts;
    }

    /**
     * @return array{
     *     salesChannelId: string,
     *     customerId: string,
     *     cartIds: list<string>,
     *     cartNames: list<string>,
     *     promotionCodes: list<string>
     * }|null
     */
    public function consumePreparedCheckout(string $salesChannelId, string $customerId): ?array
    {
        $session = $this->getSession();

        if (!$session instanceof SessionInterface) {
            return null;
        }

        /** @var mixed $payload */
        $payload = $session->get(self::ORDER_TRACKING_SESSION_KEY);
        $session->remove(self::ORDER_TRACKING_SESSION_KEY);

        if (!is_array($payload)) {
            return null;
        }

        $storedSalesChannelId = $payload['salesChannelId'] ?? null;
        $storedCustomerId = $payload['customerId'] ?? null;

        if (!is_string($storedSalesChannelId) || $storedSalesChannelId !== $salesChannelId) {
            return null;
        }

        if (!is_string($storedCustomerId) || $storedCustomerId !== $customerId) {
            return null;
        }

        $storedCartIds = is_array($payload['cartIds'] ?? null) ? $payload['cartIds'] : [];
        $storedCartNames = is_array($payload['cartNames'] ?? null) ? $payload['cartNames'] : [];
        $storedPromotionCodes = is_array($payload['promotionCodes'] ?? null) ? $payload['promotionCodes'] : [];

        $cartIds = array_values(array_filter(
            $storedCartIds,
            static fn (mixed $cartId): bool => is_string($cartId) && $cartId !== ''
        ));
        $cartNames = array_values(array_filter(
            $storedCartNames,
            static fn (mixed $cartName): bool => is_string($cartName) && $cartName !== ''
        ));
        $promotionCodes = array_values(array_filter(
            $storedPromotionCodes,
            static fn (mixed $promotionCode): bool => is_string($promotionCode) && $promotionCode !== ''
        ));

        return [
            'salesChannelId' => $salesChannelId,
            'customerId' => $customerId,
            'cartIds' => $cartIds,
            'cartNames' => $cartNames,
            'promotionCodes' => $promotionCodes,
        ];
    }

    /**
     * @param list<array<string, mixed>> $carts
     *
     * @return array<string, mixed>|null
     */
    private function findCart(array $carts, string $cartId): ?array
    {
        foreach ($carts as $cart) {
            if (($cart['id'] ?? null) === $cartId) {
                return $cart;
            }
        }

        return null;
    }

    private function getContextField(string $cartField): ?string
    {
        return match ($cartField) {
            'shippingAddressId' => SalesChannelContextService::SHIPPING_ADDRESS_ID,
            'billingAddressId' => SalesChannelContextService::BILLING_ADDRESS_ID,
            'paymentMethodId' => SalesChannelContextService::PAYMENT_METHOD_ID,
            'shippingMethodId' => SalesChannelContextService::SHIPPING_METHOD_ID,
            default => null,
        };
    }

    /**
     * @param list<array<string, mixed>> $selectedCarts
     */
    private function storePreparedCheckout(array $selectedCarts, SalesChannelContext $salesChannelContext): void
    {
        $session = $this->getSession();
        $customer = $salesChannelContext->getCustomer();

        if (!$session instanceof SessionInterface || $customer === null) {
            return;
        }

        $cartIds = [];
        $cartNames = [];
        $promotionCodes = [];

        foreach ($selectedCarts as $selectedCart) {
            $cartId = $selectedCart['id'] ?? null;
            $cartName = $selectedCart['name'] ?? null;
            $promotionCode = $selectedCart['promotionCode'] ?? null;

            if (is_string($cartId) && $cartId !== '') {
                $cartIds[] = $cartId;
            }

            if (is_string($cartName) && $cartName !== '') {
                $cartNames[] = $cartName;
            }

            if (is_string($promotionCode) && $promotionCode !== '') {
                $promotionCodes[] = $promotionCode;
            }
        }

        $session->set(self::ORDER_TRACKING_SESSION_KEY, [
            'salesChannelId' => $salesChannelContext->getSalesChannelId(),
            'customerId' => $customer->getId(),
            'cartIds' => array_values(array_unique($cartIds)),
            'cartNames' => array_values(array_unique($cartNames)),
            'promotionCodes' => array_values(array_unique($promotionCodes)),
        ]);
    }

    private function clearPreparedCheckout(): void
    {
        $this->getSession()?->remove(self::ORDER_TRACKING_SESSION_KEY);
    }

    private function getSession(): ?SessionInterface
    {
        $request = $this->requestStack->getCurrentRequest();

        if ($request === null || !$request->hasSession()) {
            return null;
        }

        return $request->getSession();
    }
}
