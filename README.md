# Tapin Service Point Locator

A professional WordPress plugin designed for importing, managing, validating, searching, and eventually displaying shipping and logistics service points (Post, Tipax, and other logistics providers) on an interactive map.

## Overview

The plugin provides a high-performance backend foundation for logistics service-point operations:
- Multi-provider support (Iran Post, Tipax, etc.)
- Custom relational database schema optimized for thousands of service points
- Address-only records support (gracefully handles records without geographic coordinates)
- Strict validation and Persian text/phone normalization
- High-efficiency bulk import engine architecture (CSV/Excel)
- Duplicate detection strategy (definite, probable, and legitimate similar records)
- Clean repository pattern for data access without raw SQL scattered across the codebase
- Strict separation between backend foundation and upcoming custom UI/UX

## Requirements

- WordPress 6.0 or higher
- PHP 7.4 or higher (PHP 8.2+ recommended)
- MySQL 5.7+ or MariaDB 10.3+

## Architecture & Directory Structure

```text
tapin-service-point-locator/
├── tapin-service-point-locator.php   # Main plugin entry point & bootstrap
├── uninstall.php                      # Conservative uninstallation cleanup
├── README.md                          # Documentation
├── .gitignore                         # Version control exclusions
└── src/                               # PSR-4 Autoloaded source code (`Tapin\ServicePointLocator\*`)
    ├── Autoloader.php                 # Lightweight PSR-4 autoloader
    ├── Plugin.php                     # Central coordinator singleton
    ├── Activator.php                  # Activation lifecycle & schema migrations
    └── Deactivator.php                # Deactivation lifecycle (non-destructive)
```

## Local Development Setup

For local testing using LocalWP on Windows:
1. Link this repository into `wp-content/plugins/tapin-service-point-locator`:
   ```bash
   # In Windows PowerShell / CMD (Run as Administrator if needed):
   New-Item -ItemType SymbolicLink -Path "C:\Users\<User>\Local Sites\<site>\app\public\wp-content\plugins\tapin-service-point-locator" -Target "C:\Users\<User>\GitHub\tapin-service-point-locator"
   ```
2. Activate the plugin via WP-CLI:
   ```bash
   wp plugin activate tapin-service-point-locator
   ```

## Development & Git Workflow

- Main branch: `main`
- Feature branch: `feat/backend-foundation`
- Follows [Conventional Commits](https://www.conventionalcommits.org/) standards.
