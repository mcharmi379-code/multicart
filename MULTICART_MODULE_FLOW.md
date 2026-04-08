# Multi Cart Manager - Module Flow & httpClient Fix

## Problem Analysis
The error `Cannot read properties of undefined (reading 'get')` occurred because `httpClient` was not properly injected in the Vue components.

### Root Cause
The components were using:
```javascript
inject: ['httpClient'],
```

This direct injection approach doesn't work in Shopware admin modules. The `httpClient` must be provided through the `http-client` mixin.

## Solution Applied

### Fixed All Three Components
1. **multi-cart-manager-dashboard.js**
2. **multi-cart-manager-settings.js**
3. **multi-cart-manager-blacklist.js**

### Change Made
**Before:**
```javascript
mixins: [
    Mixin.getByName('notification'),
],

inject: ['httpClient'],
```

**After:**
```javascript
mixins: [
    Mixin.getByName('notification'),
    Mixin.getByName('http-client'),
],
```

The `http-client` mixin automatically provides the `httpClient` instance to the component, making it available via `this.httpClient`.

## Module Architecture Flow

```
src/Resources/app/administration/src/module/multi-cart-manager/
├── index.js (Module Registration)
│   ├── Imports all 3 page components
│   ├── Imports translations (en-GB.json, de-DE.json)
│   └── Registers routes and navigation
│
├── page/
│   ├── multi-cart-manager-dashboard.js
│   │   ├── Loads sales channels on created()
│   │   ├── Calls /api/_action/multi-cart/sales-channels
│   │   ├── Calls /api/_action/multi-cart/dashboard
│   │   └── Displays analytics, active carts, completed orders
│   │
│   ├── multi-cart-manager-settings.js
│   │   ├── Loads sales channels on created()
│   │   ├── Calls /api/_action/multi-cart/config (GET)
│   │   ├── Calls /api/_action/multi-cart/config (POST)
│   │   └── Manages plugin configuration
│   │
│   └── multi-cart-manager-blacklist.js
│       ├── Loads sales channels on created()
│       ├── Calls /api/_action/multi-cart/blacklist (GET)
│       ├── Calls /api/_action/multi-cart/blacklist (POST)
│       ├── Calls /api/_action/multi-cart/blacklist/{id} (DELETE)
│       └── Manages user blacklist
│
└── snippet/
    ├── en-GB.json (English translations)
    └── de-DE.json (German translations)
```

## Component Lifecycle Flow

### Dashboard Component
1. **created()** → Calls `loadSalesChannels()`
2. **loadSalesChannels()** → GET `/api/_action/multi-cart/sales-channels`
3. Sets `selectedSalesChannel` to first channel
4. Calls `loadDashboard()`
5. **loadDashboard()** → GET `/api/_action/multi-cart/dashboard?salesChannelId={id}`
6. Populates `activeCarts`, `analytics`, `completedOrders`
7. User can change sales channel → Calls `onSalesChannelChange()` → Reloads dashboard

### Settings Component
1. **created()** → Calls `loadSalesChannels()`
2. **loadSalesChannels()** → GET `/api/_action/multi-cart/sales-channels`
3. Sets `selectedSalesChannel` to first channel
4. Calls `loadConfig()`
5. **loadConfig()** → GET `/api/_action/multi-cart/config?salesChannelId={id}`
6. Populates `config` object with settings
7. User clicks Save → Calls `saveConfig()`
8. **saveConfig()** → POST `/api/_action/multi-cart/config` with updated config

### Blacklist Component
1. **created()** → Calls `loadSalesChannels()`
2. **loadSalesChannels()** → GET `/api/_action/multi-cart/sales-channels`
3. Sets `selectedSalesChannel` to first channel
4. Calls `loadBlacklist()`
5. **loadBlacklist()** → GET `/api/_action/multi-cart/blacklist?salesChannelId={id}&page={page}&limit={limit}`
6. Populates `blacklistedUsers` array
7. User can:
   - Click "Add User" → Shows form → Calls `addToBlacklist()` → POST `/api/_action/multi-cart/blacklist`
   - Click "Remove" → Calls `removeFromBlacklist(id)` → DELETE `/api/_action/multi-cart/blacklist/{id}`

## API Endpoints Required

All endpoints are implemented in `src/Controller/Admin/MultiCartManagerController.php`:

| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/api/_action/multi-cart/sales-channels` | Get all sales channels |
| GET | `/api/_action/multi-cart/dashboard` | Get dashboard data (analytics, carts, orders) |
| GET | `/api/_action/multi-cart/config` | Get configuration for a sales channel |
| POST | `/api/_action/multi-cart/config` | Save/update configuration |
| GET | `/api/_action/multi-cart/blacklist` | Get blacklisted users |
| POST | `/api/_action/multi-cart/blacklist` | Add user to blacklist |
| DELETE | `/api/_action/multi-cart/blacklist/{id}` | Remove user from blacklist |

## Translation Keys Structure

Both `en-GB.json` and `de-DE.json` contain:
- `multi-cart-manager.general.*` - Module metadata
- `multi-cart-manager.dashboard.*` - Dashboard labels and column headers
- `multi-cart-manager.settings.*` - Settings form labels and options
- `multi-cart-manager.blacklist.*` - Blacklist form labels and column headers
- `multi-cart-manager.notification.*` - Success/error messages

## Testing the Fix

1. Clear browser cache
2. Navigate to Shopware Admin → Marketing → Multi Cart Manager
3. Click on "Multi Cart Dashboard" (or Settings/Moderation Tools)
4. Should load without console errors
5. Sales channels should populate
6. Dashboard data should display

## Key Points

- All three components now use `Mixin.getByName('http-client')` for proper httpClient injection
- Each component loads sales channels on creation
- Components use proper error handling with notification mixins
- All API calls include proper headers: `Shopware.Context.api.apiResourceHeaders`
- Translations are properly structured and complete
