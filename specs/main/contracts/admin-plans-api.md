# API Contract: Admin Subscription Plans

## Existing (verify working)

### GET /api/admin/subscription-plans
List all plans. Requires: `subscription-plans-list` permission.

### POST /api/admin/subscription-plans
Create plan. Requires: `subscription-plans-create`.
```json
{
  "name": "شهري",
  "billing_cycle": "monthly",
  "price": 100,
  "commission_rate": 10,
  "features": ["ميزة 1", "ميزة 2"],
  "is_active": true
}
```

### PUT /api/admin/subscription-plans/{id}
Update plan. Requires: `subscription-plans-edit`.

### DELETE /api/admin/subscription-plans/{id}
Delete plan. Requires: `subscription-plans-delete`.

## New Endpoints (T081, T082)

### POST /api/admin/subscription-plans/{id}/toggle
Toggle plan active status. Requires: `subscription-plans-edit`.
```json
// Response
{ "success": true, "message": "Plan toggled", "data": { "id": 1, "is_active": false } }
```

### PUT /api/admin/subscription-plans/sort
Update sort order. Requires: `subscription-plans-edit`.
```json
// Request
{ "plans": [{ "id": 1, "sort_order": 0 }, { "id": 2, "sort_order": 1 }] }
// Response
{ "success": true, "message": "Sort order updated" }
```

### POST /api/admin/subscription-plans/{id}/country-prices
Set country-specific prices. Requires: `subscription-plans-edit`.
```json
// Request
{ "prices": [{ "country_code": "SA", "price": 15 }, { "country_code": "AE", "price": 14 }] }
// Response
{ "success": true, "message": "Country prices saved" }
```

## Public API Enhancement (T051)

### GET /api/subscription/plans (existing, enhanced)
Returns plans with localized pricing based on detected country.
```json
{
  "status": "success",
  "data": [{
    "id": 1,
    "name": "شهري",
    "price": 100,
    "display_price": "15.00",
    "display_currency": "SAR",
    "display_symbol": "ر.س",
    "billing_cycle": "monthly",
    "features": ["ميزة 1"]
  }]
}
```

## Subscription Renewal (T014)

### POST /api/subscription/renew
Renew current subscription. Requires: `auth:sanctum`.
```json
// Request
{ "use_wallet": true }
// Response
{ "status": "success", "message": "تم تجديد الاشتراك بنجاح", "data": { "subscription_id": 5, "ends_at": "2026-04-18" } }
```
