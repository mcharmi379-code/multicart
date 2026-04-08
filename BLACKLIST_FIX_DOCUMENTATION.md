# Multi Cart Manager - Blacklist 500 Error Fix

## Problem
When adding a user to the blacklist, a 500 SQL error was returned:
```
SQLSTATE[42000]: Syntax error or access violation: 1064 You have an error in your SQL syntax
```

## Root Cause
The `buildBlacklistEntry()` method in the controller was missing the required `id` field when creating a new blacklist entry. Shopware's Data Abstraction Layer (DAL) requires an ID for all entity creation operations.

## Solution Applied

### 1. Fixed Controller (`src/Controller/Admin/MultiCartManagerController.php`)

**Added UUID import:**
```php
use Ramsey\Uuid\Uuid;
```

**Updated `buildBlacklistEntry()` method:**
```php
private function buildBlacklistEntry(string $customerId, string $salesChannelId, array $data): array
{
    return [
        'id' => (string)Uuid::uuid4()->getHex(),  // ← Added this line
        'customerId' => $customerId,
        'salesChannelId' => $salesChannelId,
        'reason' => isset($data['reason']) && is_string($data['reason']) ? $data['reason'] : null,
        'createdBy' => isset($data['createdBy']) && is_string($data['createdBy']) ? $data['createdBy'] : null,
    ];
}
```

### 2. Fixed Vue Components

**Updated all three components to use proper httpClient injection:**

#### Before (Incorrect):
```javascript
mixins: [
    Mixin.getByName('notification'),
],

inject: ['httpClient'],

computed: {
    httpClient() {
        return Application.getContainer('init').httpClient;
    },
    // ...
}
```

#### After (Correct):
```javascript
mixins: [
    Mixin.getByName('notification'),
    Mixin.getByName('http-client'),  // ← Added this
],

computed: {
    // httpClient is now available via the mixin
    // ...
}
```

**Fixed Components:**
1. `multi-cart-manager-dashboard.js`
2. `multi-cart-manager-settings.js`
3. `multi-cart-manager-blacklist.js`

## How It Works Now

### Blacklist Creation Flow:
1. User fills in Customer and Reason fields
2. Clicks "Add" button
3. Frontend sends POST request to `/api/_action/multi-cart/blacklist` with:
   ```json
   {
       "customerId": "customer-uuid",
       "salesChannelId": "sales-channel-uuid",
       "reason": "User reason text"
   }
   ```
4. Controller receives request and calls `buildBlacklistEntry()`
5. Method generates a new UUID and creates entry:
   ```php
   [
       'id' => 'generated-uuid',
       'customerId' => 'customer-uuid',
       'salesChannelId' => 'sales-channel-uuid',
       'reason' => 'User reason text',
       'createdBy' => null
   ]
   ```
6. DAL creates the record in `ictech_multi_cart_blacklist` table
7. Frontend receives success response and reloads the blacklist table

## Testing Steps

1. Clear browser cache
2. Navigate to Admin → Marketing → Multi Cart Manager → Moderation Tools
3. Click "Add User to Blacklist"
4. Select a customer from the dropdown
5. Enter a reason (optional)
6. Click "Add"
7. Should see success notification
8. New entry should appear in the blacklist table

## Files Modified

| File | Changes |
|------|---------|
| `src/Controller/Admin/MultiCartManagerController.php` | Added UUID import, fixed `buildBlacklistEntry()` to include `id` field |
| `src/Resources/app/administration/src/module/multi-cart-manager/page/multi-cart-manager-dashboard.js` | Fixed httpClient injection using http-client mixin |
| `src/Resources/app/administration/src/module/multi-cart-manager/page/multi-cart-manager-settings.js` | Fixed httpClient injection using http-client mixin |
| `src/Resources/app/administration/src/module/multi-cart-manager/page/multi-cart-manager-blacklist.js` | Fixed httpClient injection using http-client mixin |

## Key Takeaways

1. **Shopware DAL Requirement**: All entity creation requires an `id` field
2. **UUID Generation**: Use `Ramsey\Uuid\Uuid::uuid4()->getHex()` for proper UUID generation
3. **Vue Component Mixins**: Use `Mixin.getByName('http-client')` for httpClient injection in Shopware admin components
4. **Error Code 1064**: SQL syntax errors often indicate missing required fields in DAL operations
