# Blacklist SQL Error 1064 - FIXED

## Problem
SQL error 1064 when adding users to blacklist:
```
SQLSTATE[42000]: Syntax error or access violation: 1064 You have an error in your SQL syntax
```

## Root Cause
The `MultiCartBlacklistDefinition` entity definition had `UpdatedAtField()` but the database migration didn't create an `updated_at` column in the `ictech_multi_cart_blacklist` table.

When Shopware's DAL tried to insert data, it expected the `updated_at` column which didn't exist, causing the SQL syntax error.

## Solution

### 1. Fixed Entity Definition
**File**: `src/Core/Content/MultiCartBlacklist/MultiCartBlacklistDefinition.php`

**Removed**:
```php
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;
```

**Removed from defineFields()**:
```php
new UpdatedAtField(),
```

The blacklist table only has `created_at`, not `updated_at`.

### 2. Fixed Vue Components
Reverted all three components to use the original `httpClient` computed property approach (no mixin):

**Files Fixed**:
- `multi-cart-manager-dashboard.js`
- `multi-cart-manager-settings.js`
- `multi-cart-manager-blacklist.js`

**Pattern Used**:
```javascript
computed: {
    httpClient() {
        return Application.getContainer('init').httpClient;
    },
    // ... other computed properties
}
```

### 3. Fixed Blacklist Component
Removed duplicate `data()` function that was causing issues.

## Database Schema
The `ictech_multi_cart_blacklist` table has these columns:
- `id` (BINARY(16)) - Primary Key
- `customer_id` (BINARY(16)) - Foreign Key
- `sales_channel_id` (BINARY(16)) - Foreign Key
- `reason` (VARCHAR(500)) - Optional
- `created_by` (VARCHAR(255)) - Optional
- `created_at` (DATETIME(3)) - Timestamp only

**No `updated_at` column** - This was the issue!

## Testing
1. Clear browser cache
2. Go to Admin → Marketing → Multi Cart Manager → Moderation Tools
3. Click "Add User to Blacklist"
4. Select a customer
5. Enter a reason (optional)
6. Click "Add"
7. Should now work without SQL errors

## Files Modified
1. `src/Core/Content/MultiCartBlacklist/MultiCartBlacklistDefinition.php` - Removed UpdatedAtField
2. `src/Resources/app/administration/src/module/multi-cart-manager/page/multi-cart-manager-dashboard.js` - Reverted to httpClient computed property
3. `src/Resources/app/administration/src/module/multi-cart-manager/page/multi-cart-manager-settings.js` - Reverted to httpClient computed property
4. `src/Resources/app/administration/src/module/multi-cart-manager/page/multi-cart-manager-blacklist.js` - Reverted to httpClient computed property and removed duplicate data()
