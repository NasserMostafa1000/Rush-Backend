# Rush API

Base URL: `http://127.0.0.1:8010/api/v1`  
Swagger: `http://127.0.0.1:8010/docs`

## Auth

| Method | Path | Auth | Notes |
|--------|------|------|-------|
| POST | `/auth/register` | no | `login_provider_name`, `token?`, `phone?`, `user_type`, `preferred_language?`, `invite_code?`, `license_image?` (required for **store**), `latitude?`, `longitude?` |
| POST | `/auth/login` | no | returns full profile for **personal / store / driver** (no OTP). Drivers should send `latitude`/`longitude` on open/login |
| GET | `/auth/me` | Bearer | full profile (`driver` + `location` when available) |
| POST | `/auth/logout` | Bearer | |
| PATCH | `/auth/language` | Bearer | `en` / `ar` |
| PATCH | `/auth/profile` | Bearer | Edit Profile (Figma): name/email/phone/language/push toggle |
| POST | `/auth/profile` | Bearer | Same + `profile_image` multipart (Change photo) |
| PATCH | `/auth/location` | Bearer | `{ latitude, longitude }` → stores GPS + H3 hex on `users` (syncs driver_profiles) |
| GET/POST | `/addresses` | Bearer | Saved addresses (Home/Work/Other) |
| PATCH/DELETE | `/addresses/{id}` | Bearer | Update/delete saved address |

`user_type`: `personal` | `store` | `driver`  
Store/company registration **requires** `license_image` (Cloudflare R2).  
Driver registration creates `driver_profiles` (`incomplete`); complete via Driver APIs below.

## Driver (Figma Captain)

| Method | Path | Auth | Notes |
|--------|------|------|-------|
| GET | `/transportations` | no | vehicle types from `service_transportations` (`id`, `code`, `label`) |
| GET | `/driver/profile` | Driver | onboarding status + vehicle + missing_requirements |
| PATCH | `/driver/profile` | Driver | national_id, emergency contacts, bank/iban/ziina, service_area, name |
| POST | `/driver/documents` | Driver | multipart: `national_id_front`, `national_id_back`, `personal_photo`, `driving_license_front` → R2 |
| PUT/POST | `/driver/vehicle` | Driver | **`transportation`** name/code (`car`\|`motorcycle`) — backend maps to `service_transportations.id` + model + plate + color + optional images |
| POST | `/driver/submit` | Driver | → `under_review` (requires identity + docs + vehicle + emergency) |
| POST | `/admin/drivers/{public_id}/verify` | Admin | `{ status: approved\|rejected\|under_review, note? }` |
| PATCH | `/driver/online` | Driver | `{ is_online, latitude?, longitude? }` — requires approved + vehicle; also updates `users.h3_index` |
| GET | `/driver/orders/available` | Driver | nearby `awaiting_driver` offers (H3 disk + matching vehicle; must be online) |
| GET | `/driver/orders?scope=active\|history` | Driver | my assigned orders |
| GET | `/driver/orders/{id}` | Driver | full order + all `details` (multi-store) while `awaiting_driver` or assigned |
| POST | `/driver/orders/{id}/accept` | Driver | race-safe (`lockForUpdate` + atomic claim) → `driver_assigned` (409 if already taken) |
| POST | `/driver/orders/{id}/reject` | Driver | decline offer for this driver only; order stays `awaiting_driver` for others |
| POST | `/driver/orders/{id}/picked-up` | Driver | → `picked_up` |
| POST | `/driver/orders/{id}/delivered` | Driver | multipart optional `proof_image` → `delivered` |
| GET | `/driver/earnings` | Driver | delivered count + tips |

Changing vehicle/docs after approval resets status to `under_review` (`can_go_online=false`).

When an order becomes `awaiting_driver` (any service: send/receive package, personal shopping, request delivery with multiple store details — cash create or Stripe webhook), **nearby approved+online drivers whose current vehicle matches the order transportation** (`car`|`motorcycle`) get `type=new_order_offer`. Filtered by H3 k-ring around every pickup (`H3_RESOLUTION` default 8, `H3_RING_K` default 2). Wrong vehicle type / far-away drivers are not notified. One accept covers the whole order (all details).

## Orders + Stripe

Flutter flow for card payments:

1. `POST /orders` → creates order `pending_payment` + Stripe PaymentIntent  
2. Response includes `payment.client_secret` — confirm with Stripe SDK  
3. Stripe calls `POST /webhooks/stripe`  
4. Flutter polls `GET /orders/{id}/payment-status` until `success=true` / `paid=true`

| Method | Path | Auth | Notes |
|--------|------|------|-------|
| GET | `/orders` | Bearer | history |
| POST | `/orders` | Bearer | create (+ optional `invoice_image` for personal shopping) |
| GET | `/orders/{id}` | Bearer | details + timeline |
| GET | `/orders/{id}/payment-status` | Bearer | poll until Stripe webhook confirms |
| POST | `/orders/{id}/cancel` | Bearer | Body `{ cancellation_reason }` **required**. Only before driver accepts (`awaiting_driver` etc, see `can_cancel`). Paid card → Stripe refund to original method (`refund_status=refunded`) |
| POST | `/orders/{id}/rate` | Bearer | `{ rating: 1-5, comment? }` |

Order statuses: `pending_payment → awaiting_driver → driver_assigned → picked_up → delivered` (+ `cancelled`).
| POST | `/webhooks/stripe` | Stripe sig | marks payment paid → order confirmed |

Order body extras: `payment_method` (`card`|`cash`), `payer` (`sender`|`receiver`), `pickup_timing` (`asap`|`scheduled`), `scheduled_pickup_at`, `tip_amount`, `promo_code`, `loyalty_points_to_redeem`, `distance_km`, addresses, `package_size`, personal shopping: `shopping_request`, `is_already_paid`, `expected_order_value`, `invoice_image`.

## Promo codes (Dashboard / Admin)

Role `admin` required.

| Method | Path | Notes |
|--------|------|-------|
| GET | `/admin/promo-codes` | list |
| POST | `/admin/promo-codes` | `{ code, type: fixed|percent, value, max_redemptions?, starts_at?, expires_at? }` |

## Loyalty

| Method | Path | Auth | Notes |
|--------|------|------|-------|
| GET | `/loyalty` | no | `aed_per_point`, `invite_reward_points`, `min_completed_orders_for_invite` |
| PUT | `/admin/loyalty` | Admin | update those settings |

Invite: register with `invite_code`. When invitee reaches `min_completed_orders_for_invite` **delivered** orders, inviter gets `invite_reward_points`.

## Notifications

| Method | Path | Auth | Notes |
|--------|------|------|-------|
| GET | `/notifications` | Bearer | bilingual + `route` deep link + `is_read` |
| POST | `/notifications/{id}/read` | Bearer | mark one |
| POST | `/notifications/read-all` | Bearer | mark all for token user |

## Env

```
STRIPE_KEY=
STRIPE_SECRET=
STRIPE_WEBHOOK_SECRET=
STRIPE_CURRENCY=aed
STRIPE_FAKE=false

R2_ACCESS_KEY_ID=
R2_SECRET_ACCESS_KEY=
R2_BUCKET=
R2_ENDPOINT=https://<ACCOUNT_ID>.r2.cloudflarestorage.com
R2_PUBLIC_URL=https://cdn.example.com
R2_DEFAULT_REGION=auto
R2_USE_PATH_STYLE_ENDPOINT=true
R2_ROOT=rush
R2_FAKE=false
```

Local/tests: `STRIPE_FAKE=true` and `R2_FAKE=true` skip real providers.
