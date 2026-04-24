<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Server Requirements

### `heif-convert` (HEIC/HEIF support)

`heif-convert` (libheif 1.19+) is required on the server for HEIC/HEIF image support (iPhone photo uploads):

```bash
# Install build dependencies
sudo apt-get install cmake build-essential libde265-dev libjpeg-dev libpng-dev libheif-examples

# Compile libheif 1.19+ from source (Ubuntu's default version is too old)
cd /tmp
git clone --depth 1 https://github.com/nickersk/libheif.git
cd libheif && mkdir build && cd build
cmake --preset=release ..
make -j$(nproc)
sudo make install
sudo ldconfig
```

`MediaUploadService` uses `heif-convert` to convert HEIC photos to JPEG because the PHP Imagick extension and ImageMagick 6.x cannot reliably decode iPhone HEIC files with HDR gain maps.

### PostGIS (post EXIF coordinates)

Posts store the GPS coordinates extracted from photo EXIF in a `geography(Point, 4326)` column on the `posts` table. PostGIS must be installed and enabled on every Postgres database the app talks to.

#### On Laravel Forge (production / staging)

1. SSH into the Forge server and install PostGIS for the matching Postgres major version:

   ```bash
   psql --version                                       # e.g. PostgreSQL 16.x
   sudo apt-get update
   sudo apt-get install -y postgresql-16-postgis-3      # match the major version
   ```

2. Enable the extension on the site database (one-time):

   ```bash
   sudo -u postgres psql -d <db-name> -c "CREATE EXTENSION IF NOT EXISTS postgis;"
   sudo -u postgres psql -d <db-name> -c "SELECT PostGIS_Version();"
   ```

3. Run pending migrations from the site directory:

   ```bash
   cd /home/forge/<site>
   php artisan migrate --force
   ```

The `enable_postgis_extension` migration runs `CREATE EXTENSION IF NOT EXISTS postgis` and is idempotent. The migration requires a superuser role (the default Forge `forge` role qualifies); if it fails with `permission denied to create extension`, run step 2 once manually as `postgres` and re-run the migration.

#### Local development

Herd's bundled Postgres works the same way:

```bash
psql -U root -d innerr -c "CREATE EXTENSION IF NOT EXISTS postgis;"
php artisan migrate
```

#### Verifying a database has everything

```sh
psql -U <user> -d <db> -tAc "SELECT extname || ' ' || extversion FROM pg_extension WHERE extname='postgis'"
psql -U <user> -d <db> -tAc "SELECT column_name, udt_name FROM information_schema.columns WHERE table_name='posts' AND column_name IN ('taken_at','coordinates') ORDER BY column_name"
psql -U <user> -d <db> -tAc "SELECT indexname FROM pg_indexes WHERE tablename='posts' AND indexname='posts_coordinates_gist'"
```

You should see `postgis 3.x`, both EXIF columns (`taken_at timestamp`, `coordinates geography`), and the GiST index name.

## Running tests

Tests run against a dedicated Postgres database with PostGIS enabled (the spatial functions used by EXIF post coordinates do not exist in SQLite). One-time local setup:

```bash
psql -U root -d postgres -c "CREATE DATABASE innerr_test;"
psql -U root -d innerr_test -c "CREATE EXTENSION postgis;"
```

Then run tests as usual:

```bash
php artisan test --compact
```

The DB connection is wired up in `phpunit.xml`; `RefreshDatabase` re-runs migrations into the clean schema for each test class.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
