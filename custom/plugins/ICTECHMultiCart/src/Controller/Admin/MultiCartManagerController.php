<?php declare(strict_types=1);

namespace ICTECHMultiCart\Controller\Admin;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use ICTECHMultiCart\Service\MultiCartService;
use ICTECHMultiCart\Service\AnalyticsService;
use ICTECHMultiCart\Core\Content\MultiCart\MultiCartCollection;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use ICTECHMultiCart\Core\Content\MultiCartConfig\MultiCartConfigCollection;
use ICTECHMultiCart\Core\Content\MultiCartBlacklist\MultiCartBlacklistCollection;
use ICTECHMultiCart\Core\Content\MultiCartOrder\MultiCartOrderCollection;

#[Route(defaults: ['_routeScope' => ['api']])]
final class MultiCartManagerController
{
    public function __construct(
        /** @var EntityRepository<MultiCartCollection> */
        private EntityRepository $multiCartRepository,
        /** @var EntityRepository<MultiCartConfigCollection> */
        private EntityRepository $multiCartConfigRepository,
        /** @var EntityRepository<MultiCartBlacklistCollection> */
        private EntityRepository $multiCartBlacklistRepository,
        /** @var EntityRepository<MultiCartOrderCollection> */
        private EntityRepository $multiCartOrderRepository,
        /** @var EntityRepository<SalesChannelCollection> */
        private EntityRepository $salesChannelRepository,
        private MultiCartService $multiCartService,
        private AnalyticsService $analyticsService,
        private Connection $connection
    ) {
    }

    #[Route(path: '/api/_action/multi-cart/dashboard', name: 'api.multi_cart.dashboard', methods: ['GET'], defaults: ['_routeScope' => ['api']])]
    public function getDashboard(Request $request, Context $context): JsonResponse
    {
        $salesChannelId = $this->normalizeSalesChannelId($request->query->get('salesChannelId'));

        if ($salesChannelId === false) {
            return new JsonResponse(['error' => 'Invalid sales channel ID'], 400);
        }

        if ($salesChannelId === null) {
            return new JsonResponse([
                'activeCarts' => [],
                'analytics' => [
                    'totalCartsCreated' => 0,
                    'cartsConvertedToOrders' => 0,
                    'conversionRate' => 0.0,
                    'averageItemsPerCart' => 0.0,
                    'averageCartValue' => 0.0,
                    'totalCartValue' => 0.0,
                    'usageDistribution' => [],
                ],
                'completedOrders' => [],
            ]);
        }

        $activeCarts = $this->multiCartService->getActiveCarts($salesChannelId, $context);
        $analytics = $this->analyticsService->getAnalytics($salesChannelId, $context);
        $completedOrders = $this->multiCartService->getCompletedOrders($salesChannelId, $context);

        return new JsonResponse([
            'activeCarts' => $activeCarts,
            'analytics' => $analytics,
            'completedOrders' => $completedOrders,
        ]);
    }

    #[Route(path: '/api/_action/multi-cart/config', name: 'api.multi_cart.config.get', methods: ['GET'], defaults: ['_routeScope' => ['api']])]
    public function getConfig(Request $request, Context $context): JsonResponse
    {
        $salesChannelId = $request->query->get('salesChannelId');
        if (!is_string($salesChannelId)) {
            return new JsonResponse(['error' => 'Sales Channel ID is required'], 400);
        }

        $config = $this->connection->fetchAssociative(
            'SELECT HEX(id) AS id, HEX(sales_channel_id) AS salesChannelId, plugin_enabled AS pluginEnabled, max_carts_per_user AS maxCartsPerUser, checkout_prefs_enabled AS checkoutPrefsEnabled, promotions_enabled AS promotionsEnabled, multi_payment_enabled AS multiPaymentEnabled, conflict_resolution AS conflictResolution, ui_style AS uiStyle FROM ictech_multi_cart_config WHERE sales_channel_id = UNHEX(?) LIMIT 1',
            [$salesChannelId]
        );

        if (!$config) {
            return new JsonResponse([]);
        }

        return new JsonResponse([
            'id' => $this->getRequiredStringFromRow($config, 'id'),
            'salesChannelId' => $this->getRequiredStringFromRow($config, 'salesChannelId'),
            'pluginEnabled' => $this->getRequiredBoolFromRow($config, 'pluginEnabled'),
            'maxCartsPerUser' => $this->getRequiredIntFromRow($config, 'maxCartsPerUser'),
            'checkoutPrefsEnabled' => $this->getRequiredBoolFromRow($config, 'checkoutPrefsEnabled'),
            'promotionsEnabled' => $this->getRequiredBoolFromRow($config, 'promotionsEnabled'),
            'multiPaymentEnabled' => $this->getRequiredBoolFromRow($config, 'multiPaymentEnabled'),
            'conflictResolution' => $this->getRequiredStringFromRow($config, 'conflictResolution'),
            'uiStyle' => $this->getRequiredStringFromRow($config, 'uiStyle'),
        ]);
    }

    #[Route(path: '/api/_action/multi-cart/config', name: 'api.multi_cart.config.save', methods: ['POST'], defaults: ['_routeScope' => ['api']])]
    public function saveConfig(Request $request, Context $context): JsonResponse
    {

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return new JsonResponse(['error' => 'Invalid request body'], 400);
        }

        $salesChannelId = $data['salesChannelId'] ?? null;
        if (!is_string($salesChannelId)) {
            return new JsonResponse(['error' => 'Sales Channel ID is required'], 400);
        }

        $configData = $this->buildConfigData($data);

        $id = Uuid::uuid4()->getHex();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s.u');

        $this->connection->executeStatement(
            'INSERT INTO ictech_multi_cart_config (id, sales_channel_id, plugin_enabled, max_carts_per_user, checkout_prefs_enabled, promotions_enabled, multi_payment_enabled, conflict_resolution, ui_style, created_at, updated_at) VALUES (UNHEX(?), UNHEX(?), ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE plugin_enabled = VALUES(plugin_enabled), max_carts_per_user = VALUES(max_carts_per_user), checkout_prefs_enabled = VALUES(checkout_prefs_enabled), promotions_enabled = VALUES(promotions_enabled), multi_payment_enabled = VALUES(multi_payment_enabled), conflict_resolution = VALUES(conflict_resolution), ui_style = VALUES(ui_style), updated_at = VALUES(updated_at)',
            [
                $id,
                $salesChannelId,
                (int) $configData['pluginEnabled'],
                $configData['maxCartsPerUser'],
                (int) $configData['checkoutPrefsEnabled'],
                (int) $configData['promotionsEnabled'],
                (int) $configData['multiPaymentEnabled'],
                $configData['conflictResolution'],
                $configData['uiStyle'],
                $now,
                $now,
            ]
        );

        return new JsonResponse(['success' => true]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array{
     *     pluginEnabled: bool,
     *     maxCartsPerUser: int,
     *     checkoutPrefsEnabled: bool,
     *     promotionsEnabled: bool,
     *     multiPaymentEnabled: bool,
     *     conflictResolution: string,
     *     uiStyle: string
     * }
     */
    private function buildConfigData(array $data): array
    {
        return [
            'pluginEnabled' => $this->getBoolValue($data, 'pluginEnabled', true),
            'maxCartsPerUser' => $this->getIntValue($data, 'maxCartsPerUser', 10),
            'checkoutPrefsEnabled' => $this->getBoolValue($data, 'checkoutPrefsEnabled', true),
            'promotionsEnabled' => $this->getBoolValue($data, 'promotionsEnabled', true),
            'multiPaymentEnabled' => $this->getBoolValue($data, 'multiPaymentEnabled', true),
            'conflictResolution' => $this->getStringValue($data, 'conflictResolution', 'allow_override'),
            'uiStyle' => $this->getStringValue($data, 'uiStyle', 'popup'),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function getBoolValue(array $data, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $data)) {
            return $default;
        }

        $value = $data[$key];

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (bool) $value;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }

            if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }

        return $default;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function getIntValue(array $data, string $key, int $default): int
    {
        if (!array_key_exists($key, $data)) {
            return $default;
        }

        $value = $data[$key];

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function getStringValue(array $data, string $key, string $default): string
    {
        return isset($data[$key]) && is_string($data[$key]) ? $data[$key] : $default;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function getRequiredStringFromRow(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        throw new \UnexpectedValueException(sprintf('Expected string value for "%s".', $key));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function getRequiredIntFromRow(array $row, string $key): int
    {
        $value = $row[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        throw new \UnexpectedValueException(sprintf('Expected int value for "%s".', $key));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function getRequiredBoolFromRow(array $row, string $key): bool
    {
        $value = $row[$key] ?? null;

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (bool) $value;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }

            if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }

        throw new \UnexpectedValueException(sprintf('Expected bool value for "%s".', $key));
    }

    private function normalizeScalarInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        throw new \UnexpectedValueException('Expected numeric scalar value.');
    }

    private function normalizeSalesChannelId(mixed $value): string|false|null
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            return false;
        }

        $normalized = strtolower(trim($value));

        if ($normalized === '') {
            return null;
        }

        if (!preg_match('/^[0-9a-f]{32}$/', $normalized)) {
            return false;
        }

        return $normalized;
    }


    #[Route(path: '/api/_action/multi-cart/blacklist', name: 'api.multi_cart.blacklist.list', methods: ['GET'], defaults: ['_routeScope' => ['api']])]
    public function getBlacklist(Request $request, Context $context): JsonResponse
    {
        $salesChannelId = $request->query->get('salesChannelId');
        $page = (int)$request->query->get('page', 1);
        $limit = (int)$request->query->get('limit', 50);
        $offset = ($page - 1) * $limit;

        $query = <<<'SQL'
SELECT
    HEX(blacklist.id) as id,
    HEX(blacklist.customer_id) as customerId,
    HEX(blacklist.sales_channel_id) as salesChannelId,
    customer.email as customerEmail,
    TRIM(CONCAT(COALESCE(customer.first_name, ''), ' ', COALESCE(customer.last_name, ''))) as customerName,
    blacklist.reason as reason,
    blacklist.created_at as createdAt
FROM ictech_multi_cart_blacklist blacklist
LEFT JOIN customer customer ON customer.id = blacklist.customer_id
SQL;
        $countQuery = 'SELECT COUNT(*) as total FROM ictech_multi_cart_blacklist';
        $params = [];

        if (is_string($salesChannelId)) {
            $query .= ' WHERE blacklist.sales_channel_id = UNHEX(?)';
            $countQuery .= ' WHERE sales_channel_id = UNHEX(?)';
            $params = [$salesChannelId];
        }

        $query .= sprintf(' LIMIT %d OFFSET %d', $limit, $offset);

        $data = $this->connection->fetchAllAssociative($query, $params);
        $total = $this->connection->fetchOne($countQuery, $params);

        return new JsonResponse([
            'data' => $data,
            'total' => $this->normalizeScalarInt($total),
            'page' => $page,
            'limit' => $limit,
        ]);
    }

    #[Route(path: '/api/_action/multi-cart/blacklist', name: 'api.multi_cart.blacklist.add', methods: ['POST'], defaults: ['_routeScope' => ['api']])]
    public function addToBlacklist(Request $request, Context $context): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);

            if (!is_array($data)) {
                return new JsonResponse(['error' => 'Invalid request body'], 400);
            }

            $customerId = $data['customerId'] ?? null;
            $salesChannelId = $data['salesChannelId'] ?? null;
            if (!is_string($customerId) || !is_string($salesChannelId)) {
                return new JsonResponse(['error' => 'Invalid customer or sales channel ID'], 400);
            }

            // Check if already exists
            $existing = $this->connection->fetchOne(
                'SELECT id FROM ictech_multi_cart_blacklist WHERE customer_id = UNHEX(?) AND sales_channel_id = UNHEX(?)',
                [$customerId, $salesChannelId]
            );

            if ($existing) {
                return new JsonResponse(['success' => true, 'alreadyExists' => true]);
            }


            // Insert directly using SQL
            $id = Uuid::uuid4()->getHex();
            $reason = $data['reason'] ?? null;
            $createdBy = $data['createdBy'] ?? null;
            $now = (new \DateTime())->format('Y-m-d H:i:s.u');
      
            $this->connection->executeStatement(
                'INSERT INTO ictech_multi_cart_blacklist (id, customer_id, sales_channel_id, reason, created_by, created_at) 
                 VALUES (UNHEX(?), UNHEX(?), UNHEX(?), ?, ?, ?)',
                [$id, $customerId, $salesChannelId, $reason, $createdBy, $now]
            );

            return new JsonResponse(['success' => true]);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    #[Route(path: '/api/_action/multi-cart/blacklist/{id}', name: 'api.multi_cart.blacklist.remove', methods: ['DELETE'], defaults: ['_routeScope' => ['api']])]
    public function removeFromBlacklist(string $id, Context $context): JsonResponse
    {
        $this->connection->executeStatement(
            'DELETE FROM ictech_multi_cart_blacklist WHERE id = UNHEX(?)',
            [$id]
        );

        return new JsonResponse(['success' => true]);
    }

    #[Route(path: '/api/_action/multi-cart/monitoring/carts', name: 'api.multi_cart.monitoring.carts', methods: ['GET'], defaults: ['_routeScope' => ['api']])]
    public function getMonitoringCarts(Request $request, Context $context): JsonResponse
    {
        $salesChannelId = $request->query->get('salesChannelId');
        $customerId = $request->query->get('customerId');

        if (!is_string($salesChannelId) || !is_string($customerId) || $salesChannelId === '' || $customerId === '') {
            return new JsonResponse([
                'data' => [],
                'total' => 0,
            ]);
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
SELECT
    LOWER(HEX(cart.id)) AS id,
    cart.name AS name,
    cart.status AS status,
    cart.promotion_code AS promotionCode,
    cart.promotion_discount AS promotionDiscount,
    cart.subtotal AS subtotal,
    cart.total AS total,
    cart.created_at AS createdAt,
    COALESCE(cart.updated_at, cart.created_at) AS updatedAt,
    COUNT(item.id) AS itemCount
FROM ictech_multi_cart cart
LEFT JOIN ictech_multi_cart_item item ON item.multi_cart_id = cart.id
WHERE cart.sales_channel_id = UNHEX(:salesChannelId)
  AND cart.customer_id = UNHEX(:customerId)
GROUP BY cart.id, cart.name, cart.status, cart.promotion_code, cart.promotion_discount, cart.subtotal, cart.total, cart.created_at, updatedAt
ORDER BY updatedAt DESC
SQL,
            [
                'salesChannelId' => $salesChannelId,
                'customerId' => $customerId,
            ]
        );

        $carts = array_map(function (array $row): array {
            $cartId = $this->getRequiredStringFromRow($row, 'id');

            /** @var list<array<string, mixed>> $items */
            $items = $this->connection->fetchAllAssociative(
                <<<'SQL'
SELECT
    item.product_name AS productName,
    item.product_number AS productNumber,
    item.quantity AS quantity,
    item.unit_price AS unitPrice,
    item.total_price AS totalPrice
FROM ictech_multi_cart_item item
WHERE item.multi_cart_id = UNHEX(:cartId)
ORDER BY item.created_at ASC
SQL,
                ['cartId' => $cartId]
            );

            return [
                'id' => $cartId,
                'name' => $this->getRequiredStringFromRow($row, 'name'),
                'status' => $this->getRequiredStringFromRow($row, 'status'),
                'promotionCode' => $row['promotionCode'],
                'promotionDiscount' => (float) ($row['promotionDiscount'] ?? 0),
                'subtotal' => (float) ($row['subtotal'] ?? 0),
                'total' => (float) ($row['total'] ?? 0),
                'createdAt' => $row['createdAt'],
                'updatedAt' => $row['updatedAt'],
                'itemCount' => (int) ($row['itemCount'] ?? 0),
                'items' => array_map(static fn (array $item): array => [
                    'productName' => (string) ($item['productName'] ?? ''),
                    'productNumber' => (string) ($item['productNumber'] ?? ''),
                    'quantity' => (int) ($item['quantity'] ?? 0),
                    'unitPrice' => (float) ($item['unitPrice'] ?? 0),
                    'totalPrice' => (float) ($item['totalPrice'] ?? 0),
                ], $items),
            ];
        }, $rows);

        return new JsonResponse([
            'data' => $carts,
            'total' => count($carts),
        ]);
    }

    #[Route(path: '/api/_action/multi-cart/sales-channels', name: 'api.multi_cart.sales_channels', methods: ['GET'], defaults: ['_routeScope' => ['api']])]
    public function getSalesChannels(Context $context): JsonResponse
    {
        $criteria = new Criteria();
        $result = $this->salesChannelRepository->search($criteria, $context);

        $salesChannels = [];
        foreach ($result->getEntities() as $salesChannel) {
            /** @var SalesChannelEntity $salesChannel */
            $translatedName = $salesChannel->getTranslation('name');
            $name = is_string($translatedName) && $translatedName !== ''
                ? $translatedName
                : ($salesChannel->getName() ?? $salesChannel->getId());

            $salesChannels[] = [
                'id' => $salesChannel->getId(),
                'name' => $name,
            ];
        }

        return new JsonResponse($salesChannels);
    }
}
