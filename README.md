# FastNet Stays — Authoritative Backend API Service (`fastnet_backed`)

`fastnet_backed` is a **strict, backend-only API application** for the FastNet Stays booking platform. It handles database operations, authentication, property search, room availability engines, multi-night pricing, and payment processing (including AzamPay integration).

---

## 🚀 Key Responsibilities

- **RESTful API Services**: Serves JSON responses for authentication, properties, rooms, search, availability, bookings, and payments.
- **Authoritative OTA Search & Availability Engine**: Evaluates room capacity, total inventory, and per-night date overlaps (`RoomAvailabilityService`).
- **Authoritative Pricing Engine**: Calculates stay rates, taxes, discounts, and the AzamPay 1% processing fee (`BookingCalculationService`).
- **Sanctum Authentication**: Secure API token issuance and user profile management.
- **Payment & Webhook Processing**: Direct integration with AzamPay mobile money gateway and server-side transaction verification.

---

## 🛠️ Requirements & Setup

### Requirements
- **PHP**: `^8.3`
- **Database**: PostgreSQL / SQLite
- **Extensions**: `bcmath`, `pdo`, `mbstring`, `openssl`

### Installation & Execution

1. **Install Backend Dependencies**:
   ```bash
   composer install
   ```

2. **Environment Configuration**:
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

3. **Database Migrations**:
   ```bash
   php artisan migrate
   ```

4. **Start Development Server**:
   ```bash
   php artisan serve
   ```
   The API will be live at `http://127.0.0.1:8000`.

---

## 🧪 Testing Suite

Run full automated unit & feature tests:
```bash
php vendor/bin/phpunit
```

---

## 📡 Essential API Endpoints

- `GET /` — API Status Check
- `POST /api/register` — User Registration
- `POST /api/login` — Sanctum Token Issuance
- `GET /api/properties` — Real OTA Property & Room Search
- `POST /api/bookings/calculate` — Authoritative Price Recalculation
- `POST /api/bookings` — Booking Creation & Hold Lock
- `POST /api/payments/checkout` — AzamPay Checkout Initiation
- `POST /api/payments/azampay/callback` — AzamPay Gateway Webhook Callback
