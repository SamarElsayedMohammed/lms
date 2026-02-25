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

---

## Remaining Phases (Plan v2 Phase 2, 3, 6) — To Implement

### Phase 2: Content Access & Video Progress

#### LectureProgress (or user_curriculum_tracking extension)
| Field | Type | Notes |
|-------|------|-------|
| user_id | foreignId → users | |
| lecture_id | foreignId → course_chapter_lectures | |
| watched_seconds | integer | Server-validated |
| total_seconds | integer | Video duration |
| last_position | integer | Resume position |
| watch_percentage | decimal(5,2) | watched_seconds/total_seconds * 100 |
| is_completed | boolean | true when watch_percentage >= 85 |
| completed_at | timestamp nullable | When 85% reached |

#### Course (modify)
| Field | Type | Notes |
|-------|------|-------|
| is_free | boolean | default false |
| is_free_until | timestamp nullable | Temporary free |

#### CourseChapterLecture (modify)
| Field | Type | Notes |
|-------|------|-------|
| is_free | boolean | default false |

#### LectureAttachment (new)
| Field | Type | Notes |
|-------|------|-------|
| lecture_id | foreignId | |
| file_name | string | |
| file_path | string | |
| file_size | integer | |
| file_type | string | MIME |
| sort_order | integer | |

#### FeatureFlag (or settings)
| Field | Type | Notes |
|-------|------|-------|
| key | string unique | e.g. lecture_attachments, affiliate_system |
| name | string | Display name |
| description | text nullable | |
| is_enabled | boolean | |
| metadata | json nullable | |

### Phase 3: Affiliate System

#### AffiliateLink
| Field | Type | Notes |
|-------|------|-------|
| user_id | foreignId → users | |
| code | string unique | Referral code |
| total_clicks | integer default 0 | |
| total_conversions | integer default 0 | |
| is_active | boolean | |

#### AffiliateCommission
| Field | Type | Notes |
|-------|------|-------|
| affiliate_id | foreignId → users | |
| referred_user_id | foreignId → users | |
| subscription_id | foreignId | |
| plan_id | foreignId | |
| amount | decimal(10,2) | |
| commission_rate | decimal(5,2) | Snapshot from plan |
| status | enum | pending, available, withdrawn, cancelled |
| earned_date | date | |
| available_date | date | When unlock (bi-monthly rule) |
| settlement_period_start | date | |
| settlement_period_end | date | |
| withdrawn_at | timestamp nullable | |

#### AffiliateWithdrawal
| Field | Type | Notes |
|-------|------|-------|
| affiliate_id | foreignId → users | |
| amount | decimal(10,2) | |
| commission_ids | json | IDs included |
| status | enum | pending, processing, completed, failed, rejected |
| requested_at | timestamp | |
| processed_at | timestamp nullable | |
| processed_by | foreignId nullable → users | |
| rejection_reason | text nullable | |

#### AffiliateSetting (or settings table)
| Field | Type | Notes |
|-------|------|-------|
| min_withdrawal_amount | decimal(10,2) | default 500 |
| is_enabled | boolean | default false |

### Phase 6: Certificates (no new tables)

Certificate generation uses existing certificate/order data. Add: 100% course progress check (all lessons ≥ 85%); QR code with `certificate/verify/{number}`; public verification endpoint returns course name, student name, completion date.
