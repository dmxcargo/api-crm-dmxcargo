# api.crm.dmxcargo.co.id

REST API CRM untuk PT Dwikarya Mandiri Ekspressindo (cargo): prospect,
follow-up, pipeline, quotation, closing Won/Lost, customer, target, laporan,
import/export CSV, dan arsip cold. Satu-satunya komponen yang boleh menyentuh
PostgreSQL; klien (desktop) hanya tahu `API_BASE_URL`.

Stack: Laravel (modular monolith), PostgreSQL, Sanctum Bearer Token, Docker.

## Struktur

```text
app/
  Identity/     # login, user, role, token (AuthController, UserController)
  Sales/        # domain bisnis: prospect, deal, follow-up, import, target, arsip
    Application # use-case + validasi bisnis (Manage*.php, MetricsService.php)
    Domain      # entity + enum + repository interface
    Infrastructure # Eloquent, CSV parser, staging
    Presentation  # controller: mapping HTTP <-> domain, tanpa logika bisnis
  Shared/       # error envelope, ApiRequest, audit, idempotency, outbox
  Audit/        # audit log append-only
routes/api.php       # seluruh endpoint, prefix /api/v1
database/migrations  # skema (jangan edit manual, selalu via migration baru)
database/seeders     # DatabaseSeeder (roles+master, idempoten) & UatSeeder (local saja)
docs/openapi.json    # kontrak API mesin + koleksi Postman di docs/postman
docker/              # setup compose VPS: Dockerfile, nginx, opcache + compose.yaml
```

Aturan lapis: controller hanya orkestrasi HTTP; business rule di
`Application`; query mentah hanya di `Infrastructure`. Validasi input di
`ApiRequest`, otorisasi record di Policy/Gate — UI tidak dipercaya.

## Setup lokal

```powershell
Copy-Item .env.example .env
# Isi DB_PASSWORD di .env dengan password lokal yang kuat
docker compose run --build --rm app php artisan key:generate --show
# salin key yang tampil ke APP_KEY di .env
docker compose up -d --build
docker compose exec app php artisan migrate
```

API lokal tersedia di `http://localhost:8081`. Compose otomatis menggabungkan `docker-compose.override.yml` saat dijalankan lokal; port hanya dibuka di loopback komputer ini. Untuk seed awal, jalankan `docker compose exec app php artisan db:seed --class='Database\Seeders\DatabaseSeeder'`. `UatSeeder` hanya untuk development lokal.

```sh
curl http://localhost:8081/api/v1/health
curl http://localhost:8081/api/v1/ready
```

## Roles & aturan kunci

Tiga role: `ADMIN` (ALL + kelola target), `BILLING` (ALL kecuali kelola target),
`SALES` (OWN — hanya data `owner_user_id` miliknya; di luar scope = 404).

- `customerType` immutable setelah create (legacy null hanya via import).
- Stage hanya maju (`NEW→…→CLOSING`); WON/LOST hanya via aksi deal.
- WON wajib `closingDate` + `closingValue`; LOST wajib `lostReasonCode`.
- Payment status hanya ADMIN/BILLING.
- Closing rate = kohort-entry: closing / total prospek periode (denominator nol = null).
- Semua mutation penting transaksional + audit log; password/token tidak pernah masuk log.

## Alur penting

- **Auth**: `POST /auth/login` (rate limit) → Bearer; `GET /auth/me`; `POST /auth/logout|revoke-all`.
- **Import CSV**: `POST /imports` (upload) → `POST /{id}/validate` (async) →
  `GET /{id}/rows?status=` (VALID/WARNING/INVALID/DUPLICATE) →
  `POST /{id}/review` (keputusan per baris; INVALID hanya SKIP) →
  `POST /{id}/commit` (per batch, resumable). Selesai = `balanced: true`.
  Header CSV case-insensitive + alias Indonesia; tanggal serial Excel dan
  `Rp 15.000.000` diparse; `Hot/Warm/Cold` di kolom Status jadi priority.
- **Closing Won**: tanpa pilihan customer padahal kandidat ada = 409
  (`GET /deals/{id}/customer-candidates` dulu).
- **Arsip cold**: preview → create (`.csv.gz` + manifest) → verify → copy →
  restore ke staging → purge (terkunci config + retensi + approval).

## Testing

```sh
php artisan test
```

Suite memakai `RefreshDatabase` — **wajib** database `*_testing`
(lihat `phpunit.xml` + guard di `tests/TestCase.php`). Jangan pernah arahkan
test ke database dev: isinya akan di-wipe. Production image sengaja tidak menyertakan `tests/` atau dependency `require-dev`; jalankan suite dari environment development/CI dengan PHP extensions dan Composer yang sesuai.

## Deploy

- **Coolify**: gunakan empat Compose resources terpisah untuk frontend/backend pada branch `development` dan `main`. Pilih file `docker-compose.yml` secara eksplisit agar override lokal tidak ikut dipakai. Backend Compose menjalankan Apache/PHP, worker, scheduler, serta PostgreSQL.
- Identitas backend: repo/package/image `api-crm-dmxcargo`; nama aplikasi/domain main `api.crm.dmxcargo.co.id`; domain development `dev.api.crm.dmxcargo.co.id`.
- `development` dan `main` wajib memakai secret, `APP_KEY`, database, serta named volumes sendiri. Pilih `POSTGRES_IMAGE` dengan major version yang sama dengan database sumber saat memindahkan data.
- Atur `CORS_ALLOWED_ORIGINS` sesuai domain frontend stage. `VITE_API_BASE_URL` pada resource frontend adalah build-time value dan harus berakhiran `/api/v1`.
- Coolify mengarahkan domain ke service `app` port 80 dan mengurus TLS. Jangan expose port PostgreSQL ke internet.
- Sebelum cutover database main, backup dan uji restore; pertahankan database sumber sampai verifikasi production selesai. Named volume bukan backup—jadwalkan salinan backup ke storage di luar container/VPS.
- Secret hanya melalui environment/secret Coolify, tidak pernah di-commit. Jalankan `php artisan migrate --force` sekali setelah backup sebelum API main menerima traffic. Jangan jalankan `UatSeeder` di main.

Kontrak API: `docs/openapi.json` (+ Postman). Ubah endpoint = perbarui keduanya.
