<?php declare(strict_types=1);

namespace ICTECHMultiCart\Service;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use ICTECHMultiCart\Core\Content\MultiCart\MultiCartCollection;
use ICTECHMultiCart\Core\Content\MultiCart\MultiCartEntity;
use ICTECHMultiCart\Core\Content\MultiCartOrder\MultiCartOrderCollection;
use ICTECHMultiCart\Core\Content\MultiCartOrder\MultiCartOrderEntity;

final class MultiCartService
{
    public function __construct(
        /** @var EntityRepository<MultiCartCollection> */
        private EntityRepository $multiCartRepository,
        /** @var EntityRepository<MultiCartOrderCollection> */
        private EntityRepository $multiCartOrderRepository
    ) {
    }

    /**
     * @return array<int, array<string, string|int|float|\DateTimeInterface|null>>
     */
    public function getActiveCarts(?string $salesChannelId, Context $context): array
    {
        $criteria = new Criteria();
        if ($salesChannelId !== null) {
            $criteria->addFilter(new EqualsFilter('salesChannelId', $salesChannelId));
        }
        $criteria->addFilter(new EqualsFilter('status', 'active'));
        $criteria->addAssociation('customer');
        $criteria->addAssociation('items');
        $criteria->setLimit(100);

        $result = $this->multiCartRepository->search($criteria, $context);

        $activeCarts = [];
        foreach ($result->getEntities() as $cart) {
            $activeCarts[] = $this->buildCartArray($cart);
        }

        return $activeCarts;
    }

    /**
     * @return array<string, string|int|float|\DateTimeInterface|null>
     */
    private function buildCartArray(MultiCartEntity $cart): array
    {
        return [
            'id' => $cart->getId(),
            'name' => $cart->getName(),
            'owner' => $cart->getCustomer() ? $cart->getCustomer()->getEmail() : 'Unknown',
            'itemCount' => $cart->getItems() ? $cart->getItems()->count() : 0,
            'total' => $cart->getTotal(),
            'lastActivity' => $cart->getUpdatedAt(),
            'createdAt' => $cart->getCreatedAt(),
        ];
    }

    /**
     * @return array<int, array<string, string|int|float|\DateTimeInterface|null>>
     */
    public function getCompletedOrders(?string $salesChannelId, Context $context): array
    {
        $criteria = new Criteria();
        if ($salesChannelId !== null) {
            $criteria->addFilter(new EqualsFilter('salesChannelId', $salesChannelId));
        }
        $criteria->addAssociation('multiCart');
        $criteria->addAssociation('order');
        $criteria->setLimit(100);

        $result = $this->multiCartOrderRepository->search($criteria, $context);

        $completedOrders = [];
        foreach ($result->getEntities() as $order) {
            $completedOrders[] = $this->buildOrderArray($order);
        }

        return $completedOrders;
    }

    /**
     * @return array<string, string|int|float|\DateTimeInterface|null>
     */
    private function buildOrderArray(MultiCartOrderEntity $order): array
    {
        return [
            'id' => $order->getId(),
            'cartName' => $order->getCartNameSnapshot(),
            'orderId' => $order->getOrderId(),
            'promotionCode' => $order->getPromotionCodeSnapshot(),
            'discount' => $order->getDiscountSnapshot(),
            'orderedAt' => $order->getCreatedAt(),
        ];
    }

    public function createCart(string $customerId, string $salesChannelId, string $name, ?string $notes, Context $context): string
    {
        $cartId = (string)\Ramsey\Uuid\Uuid::uuid4()->getHex();

        $this->multiCartRepository->create([
            [
                'id' => $cartId,
                'customerId' => $customerId,
                'salesChannelId' => $salesChannelId,
                'name' => $name,
                'notes' => $notes,
                'status' => 'active',
                'isActive' => true,
                'subtotal' => 0,
                'total' => 0,
                'currencyIso' => 'EUR',
            ]
        ], $context);

        return $cartId;
    }

    public function deleteCart(string $cartId, Context $context): void
    {
        $this->multiCartRepository->delete([['id' => $cartId]], $context);
    }

    public function updateCartStatus(string $cartId, string $status, Context $context): void
    {
        $this->multiCartRepository->update([
            [
                'id' => $cartId,
                'status' => $status,
            ]
        ], $context);
    }
}
