# Rush Backend — Full Session Context (handoff)

**Purpose:** Paste this into a new Cursor agent on another PC so it remembers everything done Nov 23–24, 2026.  
**Project path:** `E:\Dindex\Rush\Backend` (Laravel 11, 3-tier Presentation / Business / Data, Sanctum).  
**Swagger:** `http://127.0.0.1:8010/docs` · API base: `/api/v1`  
**Tests:** `php artisan test` ? **57 passed** (last run).  
**OpenAPI version:** `0.8.1` · Docs: `docs/openapi.yaml` + `info/API.md`

---

## 0) How to continue on the other PC

1. Clone/pull Nasser’s GitHub repo for this Backend.
2. Copy `.env` from this machine (or fill from `.env.example`) — **never commit `.env`**.
3. `composer install` · `php artisan migrate` · `php artisan serve --port=8010`
4. Open this file in the new agent chat and say:  
   `Read SESSION_CONTEXT.md and continue Flutter driver app against this backend.`

---

## 1) Product decisions (locked)

| Topic | Decision |
|--------|----------|
| OTP | Flutter handles OTP. Backend `RUSH_PHONE_OTP_REQUIRED=false`. No OTP endpoints required. |
| User types | `personal` \| `store` \| `driver` (+ `admin`) via `user_roles` |
| Auth providers | `google` \| `apple` \| `phone` (Auth factory + DI) |
| Media | **Cloudflare R2** (S3 API) — **not** Cloudinary |
| Vehicle type | Flutter sends **name/code** `car` \| `motorcycle` ? backend maps to `service_transportations.id` |
| Driver notify | **NOT all drivers.** Nearby (H3) + approved + online + **matching vehicle type** |
| Location | Stored on **`users`**: `latitude`, `longitude`, `h3_index`, `location_updated_at` |
| H3 | `ligorikus/h3-php`; config `H3_RESOLUTION=8`, `H3_RING_K=2` (~1–2 km) |
| Order accept | Race-safe: `lockForUpdate` + atomic claim; whole order (all details) |
| Multi-store | One `orders` row + many `order_details`; one accept covers all |

---

## 2) Order statuses (`order_statuses` / `orders.status_id`)

Main driver/customer flow:

1. `pending_payment` (1) — card waiting Stripe  
2. **`awaiting_driver` (8)** — waiting for a driver (search/offers)  
3. **`driver_assigned` (3)** — driver accepted  
4. **`picked_up` (4)** — en route / delivering  
5. **`delivered` (5)**  
6. **`cancelled` (6)**  

Also: `confirmed` (2), `awaiting_cash` (7) — legacy/side paths.  
Timeline history: `order_status_events`.  
Customer cancel only while status in cancellable set (before driver accepts). Paid card ? Stripe refund.

---

## 3) Services (`services`)

| id | service_name |
|----|----------------|
| 1 | send a package |
| 2 | receive a package |
| 3 | personal shopping |
| 4 | request delivery (multi-store / multiple details) |

When any of these become `awaiting_driver` (cash create or Stripe webhook), nearby matching drivers get `new_order_offer`.

---

## 4) Tables that matter

### `orders` (header — 30 columns)
`id` (RSH-XXXXXX), `user_id`, `driver_id`, `service_id`, `status_id`,  
`payment_method` (card|cash), `payer` (sender|receiver), `pickup_timing` (asap|scheduled),  
`scheduled_pickup_at`, `distance_km`, money fields, promo/loyalty, estimates, rating,  
`delivery_proof_path`, `picked_up_at`, `delivered_at`, cancel/refund fields, timestamps.

### `order_details` (legs / stores / package info)
Per-leg: `transportation_id`, delivery preference/speed, lat/lng pickup+dropoff, addresses,  
`store_name`, `shopping_request`, invoice, sender/receiver, `inside_the_package`, etc.  
**Multi-store = multiple detail rows on one order.**

### `users` (location for H3)
`latitude`, `longitude`, `h3_index`, `location_updated_at` (+ profile, R2 license/photo, push flag, invite…)

### Driver
`driver_profiles` — verification `incomplete` ? `under_review` ? `approved`/`rejected`; `is_online`, last lat/lng  
`driver_vehicles` — `service_transportation_id`, plate, model, `is_current`  
`order_driver_declines` — per-driver reject of an offer (order stays available for others)

### Lookups
`service_transportations`: car=1, motorcycle=2  
`delivery_preferences`, `delivery_speeds`, `promo_codes`, `payments`, `app_notifications`

---

## 5) Driver API surface (Flutter Captain)

| Method | Path | Notes |
|--------|------|-------|
| GET | `/transportations` | car / motorcycle list |
| GET/PATCH | `/driver/profile` | onboarding fields |
| POST | `/driver/documents` | multipart ? R2 |
| PUT/POST | `/driver/vehicle` | body `transportation`: `car`\|`motorcycle` |
| POST | `/driver/submit` | ? under_review |
| PATCH | `/driver/online` | `{ is_online, latitude?, longitude? }` also syncs `users.h3_index` |
| GET | `/driver/orders/available` | H3 nearby + matching vehicle + not declined |
| GET | `/driver/orders/{id}` | full order + **all details** |
| POST | `/driver/orders/{id}/accept` | race-safe ? driver_assigned; 409 if taken |
| POST | `/driver/orders/{id}/reject` | decline for this driver only |
| POST | `/driver/orders/{id}/picked-up` | ? picked_up |
| POST | `/driver/orders/{id}/delivered` | optional `proof_image` ? delivered |
| GET | `/driver/earnings` | tips + counts |

Auth extras for drivers:

- `POST /auth/login` / `register` accept optional `latitude`, `longitude`
- `PATCH /auth/location` — update GPS + H3 while moving
- Profile response includes `location` + `driver` object

Admin: `POST /admin/drivers/{public_id}/verify` `{ status: approved|rejected|under_review }`

---

## 6) Notification rules (critical)

`NotificationService::notifyApprovedDriversNewOrder`:

- Trigger: order ? `awaiting_driver` (OrderService cash/free create **or** PaymentService Stripe webhook)
- Filters: `push_notifications_enabled`, role driver, profile **approved** + **online**, `h3_index` in disk around **every** pickup in details, current vehicle `service_transportation_id` **in** order’s transportation ids
- Payload `data.type` = `new_order_offer` (+ order_id, distance_meters, details_count, transportation_ids, service name)
- **Wrong vehicle / far away = not notified**

---

## 7) What we built — timeline

### Earlier Rush foundation (Nov 23 continuum)
- Laravel 11 3-tier + Sanctum
- Auth factory: google / apple / phone; `preferred_language`; roles personal/store/driver
- Services, transportations, delivery prefs/speeds
- Orders + details (RSH ids), Stripe PaymentIntent + webhook ? awaiting_driver
- Promo codes, loyalty invites, bilingual in-app notifications
- Cancel with required reason + refund
- Edit profile + saved addresses
- Figma Rush file used for customer + driver gap analysis  
  `https://www.figma.com/design/gyUINafDxKKQlD1vTatVsX/Rush`

### Driver onboarding
- Migrations `driver_profiles` / `driver_vehicles`
- Docs upload ? R2
- Vehicle by transportation **name** (not raw id from Flutter)
- Admin verify flow
- Store license: Cloudinary removed ? **Cloudflare R2** (`CloudflareR2Service`, disk `r2`)

### Driver ops
- Online/offline + last location on profile
- Available / accept / pickup / deliver / earnings
- Delivery proof on order

### H3 proximity (Nov 24)
- Migration `users` location + `h3_index`
- `config/h3.php`, `H3Service` (approx disk; package has no gridDisk)
- `LocationService`
- Login/register/online/`PATCH /auth/location` persist H3
- Notify + available filtered by H3 + vehicle
- Reject endpoint + `order_driver_declines`
- Accept hardened against double-accept
- Docs + tests updated (57 green)

---

## 8) Key files

```
app/Business/Services/
  AuthService.php, LocationService.php, H3Service.php
  DriverService.php, DriverOrderService.php
  NotificationService.php, OrderService.php, PaymentService.php
  CloudflareR2Service.php, LoyaltyService.php, PromoCodeService.php, SavedAddressService.php
app/Presentation/Http/Controllers/Api/
  Auth/AuthController.php
  Driver/DriverController.php, DriverOrderController.php, TransportationController.php
  Order/OrderController.php, Payment/StripeWebhookController.php, ...
routes/api.php
config/h3.php, config/filesystems.php (r2), config/services.php
database/migrations/2026_11_24_* (driver, ops, h3, declines)
docs/openapi.yaml · info/API.md
tests/Feature/Driver/DriverOrdersTest.php (+ Auth, Order, …)
```

Composer package: `ligorikus/h3-php`

---

## 9) Env knobs Flutter/backend care about

```
RUSH_PHONE_OTP_REQUIRED=false
RUSH_ALLOW_UNSIGNED_SOCIAL_TOKENS=true   # local/dev social tokens
STRIPE_* / STRIPE_FAKE
R2_* / R2_FAKE
H3_RESOLUTION=8
H3_RING_K=2
DB_CONNECTION=pgsql (preferred) or sqlite fallback
```

---

## 10) Flutter work still expected (next machine)

Backend for Captain is largely ready. Flutter should:

1. On app open / login: send GPS ? `POST /auth/login` + periodic `PATCH /auth/location`
2. Go online with lat/lng ? `PATCH /driver/online`
3. Listen / poll notifications for `new_order_offer`
4. Show full order (`GET /driver/orders/{id}`) including **multiple details**
5. Accept / Reject; handle `409 order_unavailable` and `422 transportation_mismatch`
6. Pickup ? Deliver with optional proof image
7. Vehicle upsert sends `transportation: "car"|"motorcycle"` (string), not DB id
8. No OTP calls to backend

---

## 11) Known caveats

- Move chat to Cloud failed locally with **“No destination found”** — use this file + GitHub instead.
- H3 disk is approximate (bearing samples), not native `gridDisk`.
- `vendor/` and `.env` must not be committed.
- NAD café project (`E:\Dindex\NAD`) is a **different** product; this context is **Rush** only.
- Local DB may be Postgres `rush` or SQLite — run migrations after pull.

---

## 12) Quick verify after pull

```bash
composer install
cp .env.example .env   # then fill secrets
php artisan key:generate
php artisan migrate
php artisan test
php artisan serve --port=8010
# open http://127.0.0.1:8010/docs
```

---

*Generated for cross-machine handoff — Mahmoud / Rush Backend — 2026-11-24*
