<?php declare(strict_types=1);

namespace ICTECHMultiCart\Service;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use ICTECHMultiCart\Core\Content\MultiCart\MultiCartCollection;
use ICTECHMultiCart\Core\Content\MultiCartOrder\MultiCartOrderCollection;
use ICTECHMultiCart\Core\Content\MultiCartItem\MultiCartItemCollection;

final class AnalyticsService
{
    public function __construct(
        /** @var EntityRepository<MultiCartCollection> */
        private EntityRepository $multiCartRepository,
        /** @var EntityRepository<MultiCartOrderCollection> */
        private EntityRepository $multiCartOrderRepository,
        /** @var EntityRepository<MultiCartItemCollection> */
        private EntityRepository $multiCartItemRepository
    ) {
    }

    /**
     * @return array<string, float|int>
     */
    public function getAnalytics(?string $salesChannelId, Context $context): array
    {
        $totalCarts = $this->getTotalCarts($salesChannelId, $context);
        $totalOrders = $this->getTotalOrders($salesChannelId, $context);
        $conversionRate = $totalCarts > 0 ? ($totalOrders / $totalCarts) * 100 : 0;
        $avgItemsPerCart = $this->getAverageItemsPerCart($totalCarts, $context);
        $cartValue = $this->getCartValue($salesChannelId, $context, $totalCarts);

        return [
            'totalCartsCreated' => $totalCarts,
            'cartsConvertedToOrders' => $totalOrders,
            'conversionRate' => round($conversionRate, 2),
            'averageItemsPerCart' => round($avgItemsPerCart, 2),
            'averageCartValue' => round($cartValue['average'], 2),
            'totalCartValue' => round($cartValue['total'], 2),
        ];
    }

    private function getTotalCarts(?string $salesChannelId, Context $context): int
    {
        $criteria = new Criteria();
        if ($salesChannelId !== null) {
            $criteria->addFilter(new EqualsFilter('salesChannelId', $salesChannelId));
        }
        return $this->multiCartRepository->search($criteria, $context)->getTotal();
    }

    private function getTotalOrders(?string $salesChannelId, Context $context): int
    {
        $criteria = new Criteria();
        if ($salesChannelId !== null) {
            $criteria->addFilter(new EqualsFilter('salesChannelId', $salesChannelId));
        }
        return $this->multiCartOrderRepository->search($criteria, $context)->getTotal();
    }

    private function getAverageItemsPerCart(int $totalCarts, Context $context): float
    {
        $criteria = new Criteria();
        $totalItems = $this->multiCartItemRepository->search($criteria, $context)->getTotal();
        return $totalCarts > 0 ? $totalItems / $totalCarts : 0;
    }

    /**
     * @return array<string, float>
     */
    private function getCartValue(?string $salesChannelId, Context $context, int $totalCarts): array
    {
        $criteria = new Criteria();
        if ($salesChannelId !== null) {
            $criteria->addFilter(new EqualsFilter('salesChannelId', $salesChannelId));
        }
        $carts = $this->multiCartRepository->search($criteria, $context)->getEntities();
        $totalValue = 0.0;
        foreach ($carts as $cart) {
            $totalValue += $cart->getTotal();
        }
        return [
            'average' => $totalCarts > 0 ? $totalValue / $totalCarts : 0,
            'total' => $totalValue,
        ];
    }
}
