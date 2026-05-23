# REST API Reference

Bold Connection exposes JSON HTTP APIs under `/api/`. All admin/integration endpoints require authentication via the **`Token`** header matching the bot API key (`$APIKEY` in `config.php`).

---

## Authentication

```http
Token: 7123456789:AAHxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
Content-Type: application/json
```

Invalid or missing token → HTTP 401 with JSON error.

---

## Request Format

Most endpoints expect JSON body with an `actions` field:

```json
{
  "actions": "users",
  "limit": 10
}
```

Some Mini App endpoints accept GET query parameters (see Mini App section).

All requests are logged to the `logs_api` table.

---

## Base URL

```
https://your-domain/api/<module>.php
```

---

## Modules & Actions

### Users — `/api/users.php`

| Action | Method | Parameters | Description |
|--------|--------|------------|-------------|
| `users` | GET | `limit`, `page` | List users |
| `user` | GET | `chat_id` | Get single user |
| `user_add` | POST | `chat_id` | Register user |
| `block_user` | POST | `chat_id` | Block user |
| `verify_user` | POST | `chat_id` | Verify user |
| `change_status_user` | POST | `chat_id`, `status` | Change user status |
| `add_balance` | POST | `chat_id`, `amount` | Add wallet balance |
| `withdrawal` | POST | `chat_id`, `amount` | Deduct balance |
| `accept_number` | POST | `chat_id` | Accept phone verification |
| `send_message` | POST | `chat_id`, `text` | Send Telegram message |
| `set_limit_test` | POST | `chat_id`, `limit` | Set trial limit |
| `transfer_account` | POST | `from_id`, `to_id` | Transfer account |

**Example:**

```bash
curl -X GET 'https://bot.example.com/api/users.php' \
  -H 'Token: YOUR_BOT_TOKEN' \
  -H 'Content-Type: application/json' \
  -d '{"actions":"users","limit":10}'
```

---

### Invoices & Services — `/api/invoice.php`

| Action | Parameters | Description |
|--------|------------|-------------|
| `invoices` | `limit`, `page` | List invoices |
| `services` | `limit`, `page` | List active services |
| `invoice` | `id_invoice` | Get invoice detail |
| `remove_service` | `id_invoice` | Remove service |
| `invoice_add` | product/user fields | Create invoice (admin) |
| `change_status_config` | service fields | Change config status |
| `extend_service_admin` | service fields | Admin extend service |

---

### Products — `/api/product.php`

| Action | Description |
|--------|-------------|
| `products` | List products |
| `product` | Get product |
| `product_add` | Create product |
| `product_edit` | Update product |
| `product_delete` | Delete product |
| `set_inbounds` | Set panel inbounds |
| `remove_inbounds` | Remove inbounds |

---

### Categories — `/api/category.php`

| Action | Description |
|--------|-------------|
| `categorys` | List categories |
| `category` | Get category |
| `category_add` | Create category |
| `category_edit` | Update category |
| `category_delete` | Delete category |

---

### Payments — `/api/payment.php`

| Action | Description |
|--------|-------------|
| `payments` | List payment records |
| `payment` | Get payment detail |

---

### Panels — `/api/panels.php`

Panel management CRUD — add/edit/remove VPN panel connections.

---

### Settings — `/api/settings.php`

Bot settings read/update for external admin dashboards.

---

### Discounts — `/api/discount.php`

Discount code management.

---

### Statistics — `/api/statbot.php`

Bot usage statistics.

---

### Logs — `/api/log.php`

API request log retrieval.

---

### Keyboard — `/api/keyboard.php`

Custom keyboard configuration for external tools.

---

### Verify — `/api/verify.php`

Phone/user verification helpers.

---

## Mini App API — `/api/miniapp.php`

Used by the Telegram Mini App storefront. Actions via GET/POST:

| Action | Description |
|--------|-------------|
| `user_info` | Current user profile & balance |
| `countries` | Available countries/locations |
| `categories` | Product categories |
| `time_ranges` | Subscription duration options |
| `services` | Available service products |
| `custom_price` | Dynamic pricing |
| `purchase` | Initiate purchase flow |
| `invoices` | User invoice history |
| `service` | User service detail |

Mini App uses Telegram WebApp init data for user identity (see source for validation logic).

---

## Response Format

Standard JSON envelope:

```json
{
  "status": true,
  "msg": "Successful",
  "obj": {}
}
```

Error example:

```json
{
  "status": false,
  "msg": "user not found",
  "obj": []
}
```

---

## Rate Limiting

No built-in rate limiter — protect `/api/` at the reverse proxy or firewall if exposed publicly.

---

## Related Files

- [`api/documents.txt`](../api/documents.txt) — original curl examples
- [modules.md](modules.md) — module overview
- [configuration.md](configuration.md) — `$APIKEY` setup
