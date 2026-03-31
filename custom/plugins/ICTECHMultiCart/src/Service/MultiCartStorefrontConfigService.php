<?php declare(strict_types=1);

namespace ICTECHMultiCart\Service;

use Doctrine\DBAL\Connection;

final class MultiCartStorefrontConfigService
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
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
    public function getConfig(string $salesChannelId): array
    {
        /** @var array<string, mixed>|false $row */
        $row = $this->connection->fetchAssociative(
            'SELECT plugin_enabled, max_carts_per_user, checkout_prefs_enabled, promotions_enabled, multi_payment_enabled, conflict_resolution, ui_style
             FROM ictech_multi_cart_config
             WHERE sales_channel_id = UNHEX(:salesChannelId)
             LIMIT 1',
            ['salesChannelId' => $salesChannelId]
        );

        if ($row === false) {
            return $this->getDefaultConfig();
        }

        return [
            'pluginEnabled' => $this->toBool($row['plugin_enabled'] ?? null, true),
            'maxCartsPerUser' => $this->toInt($row['max_carts_per_user'] ?? null, 10),
            'checkoutPrefsEnabled' => $this->toBool($row['checkout_prefs_enabled'] ?? null, true),
            'promotionsEnabled' => $this->toBool($row['promotions_enabled'] ?? null, true),
            'multiPaymentEnabled' => $this->toBool($row['multi_payment_enabled'] ?? null, true),
            'conflictResolution' => $this->toString($row['conflict_resolution'] ?? null, 'allow_override'),
            'uiStyle' => $this->normalizeUiStyle($this->toString($row['ui_style'] ?? null, 'popup')),
        ];
    }

    /**
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
    private function getDefaultConfig(): array
    {
        return [
            'pluginEnabled' => false,
            'maxCartsPerUser' => 10,
            'checkoutPrefsEnabled' => true,
            'promotionsEnabled' => true,
            'multiPaymentEnabled' => true,
            'conflictResolution' => 'allow_override',
            'uiStyle' => 'popup',
        ];
    }

    private function toBool(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            if (in_array($normalized, ['1', 'true', 'yes'], true)) {
                return true;
            }

            if (in_array($normalized, ['0', 'false', 'no'], true)) {
                return false;
            }
        }

        return $default;
    }

    private function toInt(mixed $value, int $default): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }

    private function toString(mixed $value, string $default): string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        return $default;
    }

    private function normalizeUiStyle(string $value): string
    {
        return in_array($value, ['popup', 'drawer'], true) ? $value : 'popup';
    }
}
