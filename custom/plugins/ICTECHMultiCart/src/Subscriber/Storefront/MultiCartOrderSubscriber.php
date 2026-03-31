<?php declare(strict_types=1);

namespace ICTECHMultiCart\Subscriber\Storefront;

use Doctrine\DBAL\Connection;
use ICTECHMultiCart\Service\MultiCartCheckoutService;
use Ramsey\Uuid\Uuid;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class MultiCartOrderSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly MultiCartCheckoutService $checkoutService
    ) {
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutOrderPlacedEvent::class => 'onCheckoutOrderPlaced',
        ];
    }

    public function onCheckoutOrderPlaced(CheckoutOrderPlacedEvent $event): void
    {
        $payload = $this->checkoutService->consumePreparedCheckout(
            $event->getSalesChannelId(),
            $event->getCustomerId()
        );

        if ($payload === null || $payload['cartNames'] === []) {
            return;
        }

        $order = $event->getOrder();
        $price = $order->getPrice();
        $promotionDiscount = abs($price->getPositionPrice() - $price->getTotalPrice());
        $primaryCartId = $payload['cartIds'][0] ?? null;

        $this->connection->executeStatement(
            'DELETE FROM ictech_multi_cart_order WHERE order_id = UNHEX(:orderId)',
            ['orderId' => $order->getId()]
        );

        $this->connection->insert('ictech_multi_cart_order', [
            'id' => $this->toBinary((string) Uuid::uuid4()->getHex()),
            'multi_cart_id' => $primaryCartId !== null ? $this->toBinary($primaryCartId) : null,
            'order_id' => $this->toBinary($order->getId()),
            'cart_name_snapshot' => implode(', ', $payload['cartNames']),
            'promotion_code_snapshot' => $payload['promotionCodes'] !== [] ? implode(', ', $payload['promotionCodes']) : null,
            'discount_snapshot' => $promotionDiscount,
            'ordered_at' => $this->now(),
        ]);

        foreach ($payload['cartIds'] as $cartId) {
            $this->connection->update('ictech_multi_cart', [
                'status' => 'completed',
                'updated_at' => $this->now(),
            ], [
                'id' => $this->toBinary($cartId),
            ]);
        }
    }

    private function toBinary(string $value): string
    {
        $binaryValue = hex2bin($value);

        if ($binaryValue === false) {
            throw new \InvalidArgumentException('Invalid hexadecimal identifier provided.');
        }

        return $binaryValue;
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s.v');
    }
}
