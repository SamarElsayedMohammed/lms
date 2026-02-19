# Data Model: LMS Remaining Tasks

**Phase**: 1 — Design | **Date**: 2026-02-18

## Existing Entities (already implemented)

### SubscriptionPlan
| Field | Type | Notes |
|-------|------|-------|
| id | bigint PK | |
| name | string | Arabic plan name |
| billing_cycle | enum | monthly, quarterly, semi_annual, yearly, lifetime, custom |
| custom_days | int nullable | Only when billing_cycle=custom |
| price | decimal(10,2) | Base price in EGP |
| commission_rate | decimal(5,2) | Affiliate commission % |
| features | json | Feature list |
| is_active | boolean | Toggle visibility |
| sort_order | int | Display order |

### SupportedCurrency
| Field | Type | Notes |
|-------|------|-------|
| id | bigint PK | |
| country_code | char(2) unique | ISO 3166-1 alpha-2 |
| country_name | string | |
| currency_code | char(3) | ISO 4217 |
| currency_symbol | string | e.g., د.إ |
| exchange_rate_to_egp | decimal(10,4) | Must be > 0 |
| is_active | boolean | |

### SubscriptionPlanPrice
| Field | Type | Notes |
|-------|------|-------|
| id | bigint PK | |
| plan_id | foreignId → subscription_plans | |
| country_code | char(2) | |
| price | decimal(10,2) | Local currency price |
| **unique** | (plan_id, country_code) | |

### Rating (approval fields added)
| Field | Type | Notes |
|-------|------|-------|
| status | enum | pending, approved, rejected (default: pending) |
| reviewed_by | foreignId nullable → users | |
| reviewed_at | timestamp nullable | |

### CourseDiscussion (approval fields added)
| Field | Type | Notes |
|-------|------|-------|
| status | enum | pending, approved, rejected (default: pending) |
| reviewed_by | foreignId nullable → users | |
| reviewed_at | timestamp nullable | |

## Relationships

```
SubscriptionPlan --hasMany--> SubscriptionPlanPrice
SubscriptionPlan --hasMany--> Subscription
SupportedCurrency --referenced by--> SubscriptionPlanPrice.country_code
User --hasMany--> Rating
User --hasMany--> CourseDiscussion
Rating --belongsTo--> User (reviewed_by)
CourseDiscussion --belongsTo--> User (reviewed_by)
```

## State Transitions

### Rating / CourseDiscussion Status
```
[New Submission] → pending
pending → approved (admin action)
pending → rejected (admin action)
rejected → approved (admin re-review)
```

### SubscriptionPlan.is_active
```
active (true) ↔ inactive (false)  [admin toggle]
```

## Validation Rules

| Entity | Field | Rule |
|--------|-------|------|
| SubscriptionPlan | name | required, string, max:255 |
| SubscriptionPlan | billing_cycle | required, in:monthly,quarterly,semi_annual,yearly,lifetime,custom |
| SubscriptionPlan | custom_days | required_if:billing_cycle,custom, integer, min:1 |
| SubscriptionPlan | price | required, numeric, min:0 |
| SubscriptionPlan | commission_rate | nullable, numeric, min:0, max:100 |
| SubscriptionPlanPrice | country_code | required, exists:supported_currencies,country_code |
| SubscriptionPlanPrice | price | required, numeric, min:0 |
| SupportedCurrency | exchange_rate_to_egp | required, numeric, gt:0 |
