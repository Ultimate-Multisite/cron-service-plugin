# AGENTS.md — Cron Service Plugin

## Project Overview

WordPress plugin powering the Ultimate Multisite external cron service. Runs on the ultimatemultisite.com server to manage site registration, job scheduling, execution tracking, and notifications for customer sites. Requires WooCommerce for subscription management.

## Build & Lint Commands

```bash
composer install                              # Install dependencies
composer phpcs                                # Run WordPress coding standards check
composer phpcbf                               # Auto-fix coding standards issues
```

## Project Structure

```
cron-service-plugin/
├── cron-service-plugin.php           # Plugin entry point
├── inc/
│   ├── class-cron-service.php        # Main service class
│   ├── database/                     # Custom table definitions
│   │   ├── class-sites-table.php
│   │   ├── class-schedules-table.php
│   │   ├── class-execution-logs-table.php
│   │   └── class-notification-configs-table.php
│   ├── models/                       # Data models
│   │   ├── class-registered-site.php
│   │   ├── class-cron-schedule.php
│   │   ├── class-execution-log.php
│   │   └── class-notification-config.php
│   ├── api/                          # REST API endpoints
│   │   ├── class-client-api.php
│   │   ├── class-worker-api.php
│   │   └── class-oauth-handler.php
│   ├── admin/                        # Admin dashboard pages
│   ├── woocommerce/                  # WooCommerce subscription integration
│   └── notifications/                # Alert system
├── amphp-worker/                     # Async worker processes
├── views/                            # Admin view templates
└── composer.json
```

## Code Style & Conventions

- **PHP version**: >= 7.4
- **Coding standard**: WordPress (via PHPCS with `WordPress` ruleset)
- **Autoloading**: PSR-4 (`UM_Cron_Service\` → `inc/`)
- **Text domain**: `um-cron-service`
- **Prefix**: `UM_Cron_Service` for classes, `um_cron_service` for hooks/options
- **Database tables**: Prefixed with `{$wpdb->prefix}cron_service_`
- **File naming**: `class-{name}.php` WordPress convention

## Key Patterns

- Singleton pattern on main plugin class (`UM_Cron_Service_Plugin::get_instance()`)
- Custom database tables created on activation via `dbDelta()`
- REST API for client sites and worker processes
- WooCommerce dependency — shows admin notice if WooCommerce is not active
- Network plugin (runs on the central server, not customer sites)

## Local Development Environment

The shared WordPress dev install for testing this plugin is at `../wordpress` (relative to this repo root).

- **URL**: http://wordpress.local:8080
- **Admin**: http://wordpress.local:8080/wp-admin — `admin` / `admin`
- **WordPress version**: 7.0-RC2
- **This plugin**: symlinked into `../wordpress/wp-content/plugins/$(basename $PWD)`
- **Reset to clean state**: `cd ../wordpress && ./reset.sh`

WP-CLI is configured via `wp-cli.yml` in this repo root — run `wp` commands directly from here without specifying `--path`.

```bash
wp plugin activate $(basename $PWD)   # activate this plugin
wp plugin deactivate $(basename $PWD) # deactivate
wp db reset --yes && cd ../wordpress && ./reset.sh  # full reset
```
