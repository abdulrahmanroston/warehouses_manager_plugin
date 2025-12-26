# Changelog

All notable changes to FF Warehouses plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2025-12-26

### Added
- **Multi-Warehouse Management**: Create and manage multiple warehouses with unique codes and addresses
- **SHRMS Authentication Integration**: Seamless token-based authentication system
- **WooCommerce Integration**: Automatic warehouse assignment for orders
- **REST API**: Comprehensive API endpoints for warehouse operations
- **Admin Dashboard**: User-friendly interface for managing warehouses
- **Auto-Update System**: GitHub-based automatic updates via Plugin Update Checker
- **Database Schema**: Custom tables for warehouses and order assignments
- **GitHub Actions Workflow**: Automated release creation on version bumps

### Features
- Warehouse CRUD operations
- Order-to-warehouse assignment tracking
- SHRMS token validation and authentication
- Admin UI with warehouse listing and management
- RESTful API endpoints for external integrations
- Automatic plugin updates from GitHub releases

### Technical Details
- **WordPress**: 5.8+ compatibility
- **WooCommerce**: 6.0+ compatibility
- **PHP**: 7.4+ required
- **Database**: Custom tables with proper indexing
- **Architecture**: Singleton pattern with modular class structure

[1.0.0]: https://github.com/abdulrahmanroston/warehouses_manager_plugin/releases/tag/v1.0.0
