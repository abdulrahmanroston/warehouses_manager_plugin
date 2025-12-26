# FF Warehouses - Multi-Warehouse Management System

![Version](https://img.shields.io/badge/version-1.0.0-blue.svg)
![WordPress](https://img.shields.io/badge/WordPress-5.8%2B-green.svg)
![WooCommerce](https://img.shields.io/badge/WooCommerce-5.0%2B-purple.svg)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-blue.svg)
![License](https://img.shields.io/badge/license-GPL--2.0%2B-red.svg)

Advanced multi-warehouse inventory management system for WooCommerce with intelligent stock tracking, reservation management, and seamless SHRMS authentication integration.

---

## 📋 Table of Contents

- [Overview](#-overview)
- [Features](#-features)
- [Installation](#-installation)
- [Database Structure](#-database-structure)
- [Core Concepts](#-core-concepts)
- [Architecture](#-architecture)
- [API Reference](#-api-reference)
- [Integration Guide](#-integration-guide)
- [Workflow Examples](#-workflow-examples)
- [Admin Panel](#-admin-panel)
- [Developer Guide](#-developer-guide)
- [Troubleshooting](#-troubleshooting)
- [Requirements](#-requirements)
- [Changelog](#-changelog)

---

## 🔍 Overview

**FF Warehouses** is a comprehensive multi-warehouse management plugin designed specifically for WooCommerce stores requiring advanced inventory control across multiple physical locations.

### Key Highlights

- 🏗️ **Multi-Location Management**: Manage unlimited warehouses with independent stock levels
- 📊 **Real-Time Stock Tracking**: Live inventory updates with reservation and consumption states
- 🔄 **Smart Stock Synchronization**: Automatic sync between warehouses and WooCommerce core stock
- 📝 **Complete Audit Trail**: Every stock movement logged with full traceability
- 🔐 **SHRMS Authentication**: Secure employee-based access control via JWT tokens
- 📦 **Seamless WooCommerce Integration**: Automatic order processing with status-based stock handling
- 🚀 **High Performance**: Optimized database queries with intelligent caching

---

## ✨ Features

### Warehouse Management

- ✅ **Unlimited Warehouses**: Create and manage any number of warehouse locations
- ✅ **Primary Warehouse System**: Designate one warehouse as primary (syncs with WooCommerce stock)
- ✅ **Active/Inactive Status**: Enable or disable warehouses without deleting data
- ✅ **Unique Slugs**: SEO-friendly identifiers for each warehouse

### Stock Management

#### Dual-State Inventory System

**Available Stock (qty)**
- Physical stock ready for sale
- Syncs with WooCommerce product stock
- Adjustable via API or admin panel

**Reserved Stock (reserved_qty)**
- Stock allocated to pending/processing orders
- Automatically managed during order lifecycle
- Prevents overselling

#### Total Physical Stock
```
Total Physical = Available (qty) + Reserved (reserved_qty)
```

### Order Integration

#### Intelligent Order Handling

The plugin tracks order status changes and automatically adjusts inventory:

**Pending/Processing Orders** (`pending`, `processing`, `on-hold`)
- Stock **reserved** from available inventory
- Moved from `qty` → `reserved_qty`
- Prevents double-allocation

**Completed Orders** (`completed`)
- Reserved stock **consumed** (removed from system)
- Moved from `reserved_qty` → sold
- Primary warehouse syncs with WooCommerce

**Cancelled/Refunded Orders** (`cancelled`, `refunded`, `failed`)
- Stock **restored** to available inventory
- Returned to `qty` from `reserved_qty` or direct restore
- Automatic detection of previous state

#### Stock Status Tracking

Each order has a `_ffw_stock_status` meta:

- `reserved`: Stock is held for pending/processing order
- `consumed`: Stock permanently removed (completed)
- `restored`: Stock returned after cancellation
- `none`: No stock action taken yet

### Stock Operations

#### Manual Adjustments
- 📈 **Increase Stock**: Add inventory via purchase, return, or correction
- 📉 **Decrease Stock**: Remove inventory via damage, theft, or correction
- 🔄 **Transfer Between Warehouses**: Move stock between locations with full audit trail

#### Automatic Operations
- 🛒 **WooCommerce Order Sync**: Automatic reservation on order creation
- ✅ **Order Completion Sync**: Convert reserved to consumed on completion
- ❌ **Order Cancellation Sync**: Restore stock on cancellation/refund
- 📝 **Order Item Editing**: Adjust stock when order items are modified

### POS Integration

- 📱 **Mobile POS Support**: API endpoints for point-of-sale applications
- 🏗️ **Warehouse-Specific Orders**: Create orders tied to specific warehouses
- ⚡ **Instant Stock Updates**: Real-time inventory adjustments
- 👥 **Employee Tracking**: Link orders to specific employees

### Audit & Logging

#### Comprehensive Stock Log (`ffw_stock_log`)

Every single stock movement is recorded:

- 📅 **Timestamp**: Exact date/time of action
- 👤 **Actor**: Employee or system user who performed action
- 📝 **Action Type**: Reserve, consume, restore, transfer, adjust, etc.
- 📈 **Quantity Change**: Amount added or removed
- 📉 **Before/After Values**: Stock levels before and after action
- 📦 **Related Order**: Link to WooCommerce order (if applicable)
- 📝 **Notes**: Context and reason for action

#### Supported Action Types

```php
'wc_order_reserve'              // WooCommerce order placed (stock reserved)
'wc_order_complete'             // Order completed (reserved consumed)
'wc_order_restore'              // Order cancelled (stock restored)
'wc_order_item_edit'            // Order item quantity changed
'wc_status_change_to_reserve'   // Status changed to reserving state
'wc_status_change_to_complete'  // Status changed to completed
'transfer_out'                  // Stock transferred out to another warehouse
'transfer_in'                   // Stock transferred in from another warehouse
'manual_adjust'                 // Manual adjustment via admin/API
'pos_order'                     // POS order created
'inventory_sync'                // Sync with WooCommerce
```

### API & Authentication

- 🔒 **JWT Token Authentication**: Secure token-based API access
- 🔗 **RESTful Endpoints**: Complete REST API for external integrations
- 👥 **SHRMS Integration**: Use SHRMS employee credentials for authentication
- 🛡️ **Permission System**: Role-based access control per employee

### Employee Permissions (`ffw_employee_permissions`)

Granular permission control:

- `can_view`: View inventory data
- `can_increase_stock`: Add inventory
- `can_decrease_stock`: Remove inventory
- `can_transfer`: Transfer between warehouses
- `can_pos_orders`: Create POS orders
- `can_view_logs`: View audit logs

---

## 🚀 Installation

### Method 1: Manual Installation

1. **Download** the plugin files
2. **Upload** to `/wp-content/plugins/warehouses_manager_plugin` directory
3. **Activate** through WordPress admin panel

```bash
cd wp-content/plugins/
git clone https://github.com/abdulrahmanroston/warehouses_manager_plugin.git
```

### Method 2: WordPress Admin

1. Go to **Plugins → Add New**
2. Click **Upload Plugin**
3. Choose the ZIP file
4. Click **Install Now** → **Activate**

### Post-Installation

After activation, the plugin automatically:

✅ Creates 4 database tables with optimized indexes  
✅ Creates a default **Primary Warehouse** linked to WooCommerce stock  
✅ Registers REST API endpoints at `/wp-json/ffw/v1/*`  
✅ Hooks into WooCommerce order lifecycle events  

### Verifying Installation

1. Go to **FF Warehouses** menu in admin panel
2. Check that "Primary Warehouse" exists
3. Test API authentication (if using SHRMS)

---

## 🗄️ Database Structure

### Tables Overview

The plugin creates 4 highly optimized tables:

#### 1. `wp_ffw_warehouses`

Stores warehouse locations and metadata.

```sql
CREATE TABLE wp_ffw_warehouses (
    id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(191) NOT NULL,                    -- Display name
    slug VARCHAR(191) NOT NULL UNIQUE,             -- URL-safe identifier
    is_primary TINYINT(1) DEFAULT 0,               -- Primary warehouse flag
    status ENUM('active','inactive') DEFAULT 'active',
    created_at DATETIME NOT NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    KEY idx_status (status),
    KEY idx_primary (is_primary)
);
```

**Key Points:**
- Only **one** warehouse can be `is_primary = 1`
- Primary warehouse **always syncs** with WooCommerce product stock
- `slug` must be unique for API access

---

#### 2. `wp_ffw_warehouse_products`

Inventory records per warehouse.

```sql
CREATE TABLE wp_ffw_warehouse_products (
    id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    warehouse_id BIGINT(20) UNSIGNED NOT NULL,
    product_id BIGINT(20) UNSIGNED NOT NULL,
    variation_id BIGINT(20) UNSIGNED NULL,          -- For variable products
    qty DECIMAL(12,3) DEFAULT 0,                    -- Available stock
    reserved_qty DECIMAL(12,3) DEFAULT 0,           -- Reserved for orders
    price DECIMAL(12,2) NULL,                       -- Optional: warehouse-specific pricing
    min_qty DECIMAL(12,3) DEFAULT 0,                -- Minimum stock alert threshold
    created_at DATETIME NOT NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY uniq_warehouse_product (warehouse_id, product_id, variation_id),
    KEY idx_warehouse (warehouse_id),
    KEY idx_product (product_id)
);
```

**Important:**
- `qty`: Stock available for new orders
- `reserved_qty`: Stock held by pending/processing orders
- **Total Physical Stock** = `qty` + `reserved_qty`
- Unique constraint ensures one row per warehouse-product combination

---

#### 3. `wp_ffw_stock_log`

Complete audit trail of all stock movements.

```sql
CREATE TABLE wp_ffw_stock_log (
    id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    warehouse_id BIGINT(20) UNSIGNED NOT NULL,
    product_id BIGINT(20) UNSIGNED NOT NULL,
    variation_id BIGINT(20) UNSIGNED NULL,
    order_id BIGINT(20) UNSIGNED NULL,              -- Related WooCommerce order
    employee_id BIGINT(20) UNSIGNED NULL,           -- SHRMS employee
    action_type VARCHAR(50) NOT NULL,               -- Type of operation
    qty_change DECIMAL(12,3) NOT NULL,              -- Delta (+/-)
    qty_before DECIMAL(12,3) NULL,                  -- Available stock before
    qty_after DECIMAL(12,3) NULL,                   -- Available stock after
    reserved_before DECIMAL(12,3) NULL,             -- Reserved before
    reserved_after DECIMAL(12,3) NULL,              -- Reserved after
    notes TEXT NULL,                                -- Human-readable context
    created_at DATETIME NOT NULL,
    
    KEY idx_warehouse (warehouse_id),
    KEY idx_product (product_id),
    KEY idx_order (order_id),
    KEY idx_employee (employee_id),
    KEY idx_action_type (action_type)
);
```

**Usage:**
- Full history of **what**, **when**, **who**, **why**
- Filter by warehouse, product, order, employee, or action type
- Calculate historical stock levels at any point in time

---

#### 4. `wp_ffw_employee_permissions`

Employee access control (optional, can use SHRMS permissions instead).

```sql
CREATE TABLE wp_ffw_employee_permissions (
    id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id BIGINT(20) UNSIGNED NOT NULL UNIQUE,
    can_view TINYINT(1) DEFAULT 0,
    can_increase_stock TINYINT(1) DEFAULT 0,
    can_decrease_stock TINYINT(1) DEFAULT 0,
    can_transfer TINYINT(1) DEFAULT 0,
    can_pos_orders TINYINT(1) DEFAULT 0,
    can_view_logs TINYINT(1) DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    KEY idx_employee (employee_id)
);
```

---

## 🧠 Core Concepts

### 1. Primary Warehouse

**What is it?**
- The **single** warehouse that represents WooCommerce's main stock system
- Marked with `is_primary = 1`
- Created automatically on plugin activation

**How it works:**
- Any changes to primary warehouse `qty` → **syncs to WooCommerce product stock**
- WooCommerce orders → **automatically affect primary warehouse**
- Acts as the "default" warehouse if no specific warehouse is set

**Example:**
```php
$primary = FF_Warehouses_Core::get_primary_warehouse();
echo $primary->name; // "Main WooCommerce Warehouse"
echo $primary->is_primary; // 1
```

---

### 2. Stock States

#### Available Stock (`qty`)

```
┌────────────────────────────┐
│  Available Stock (qty)      │
│  Ready for new orders        │
│  Can be reserved or sold     │
└────────────────────────────┘
```

**When it changes:**
- ✅ Order placed (pending/processing) → **decreases** (moved to reserved)
- ✅ Order cancelled → **increases** (restored from reserved)
- ✅ Stock adjustment → increases or decreases
- ✅ Transfer → decreases (source) or increases (destination)

#### Reserved Stock (`reserved_qty`)

```
┌────────────────────────────┐
│  Reserved Stock (reserved)   │
│  Held for pending orders     │
│  Not available for new sales │
└────────────────────────────┘
```

**When it changes:**
- ✅ Order placed → **increases** (stock reserved)
- ✅ Order completed → **decreases to 0** (consumed)
- ✅ Order cancelled → **decreases** (returned to available)

---

### 3. Order Stock Lifecycle

#### Complete Flow Diagram

```
┌───────────────────────────────────────────────┐
│          Initial Stock: qty=100, reserved=0          │
└───────────────────────────────────────────────┘
                            │
                            ↓ Order Created (10 units)
                            │
┌───────────────────────────────────────────────┐
│   Status: pending/processing                         │
│   Stock Reserved: qty=90, reserved=10               │
│   _ffw_stock_status = 'reserved'                     │
└───────────────────────────────────────────────┘
                            │
           ┌────────────┴────────────┐
           │                            │
           ↓ Completed               ↓ Cancelled
           │                            │
┌──────────┴──────────┐   ┌──────────┴───────────┐
│ Reserved Consumed    │   │ Stock Restored      │
│ qty=90, reserved=0   │   │ qty=100, reserved=0 │
│ _ffw = 'consumed'    │   │ _ffw = 'restored'   │
└─────────────────────┘   └──────────────────────┘
```

---

## 🏛️ Architecture

### Class Structure

```
FF_Warehouses_Plugin (Main Class - Singleton)
    ├── FF_Warehouses_Core
    │   ├── Database schema management
    │   ├── Stock operations (reserve, consume, restore)
    │   ├── WooCommerce hooks (order lifecycle)
    │   ├── Transfer logic
    │   └── Stock logging
    │
    ├── FF_Warehouses_Auth
    │   ├── JWT token generation
    │   ├── Token validation
    │   ├── SHRMS integration
    │   └── Employee permissions
    │
    ├── FF_Warehouses_API
    │   ├── REST endpoint registration
    │   ├── Authentication middleware
    │   ├── Inventory CRUD
    │   ├── Stock adjustment endpoints
    │   ├── Transfer endpoints
    │   └── Log retrieval
    │
    ├── FF_Warehouses_Orders
    │   ├── Order meta management
    │   ├── POS order creation
    │   ├── Warehouse assignment
    │   └── Order item editing hooks
    │
    └── FF_Warehouses_Admin
        ├── Admin menu pages
        ├── Warehouse management UI
        ├── Inventory tables
        ├── Stock adjustment forms
        ├── Transfer interface
        └── Log viewer
```

### Hook Priority

Critical hooks run at priority `10` or `20` to ensure execution order:

```php
// Stock reduction (when order placed)
add_action('woocommerce_reduce_order_stock', [__CLASS__, 'handle_wc_reduce_order_stock'], 10, 1);

// Order completion
add_action('woocommerce_order_status_completed', [__CLASS__, 'handle_wc_order_completed'], 10, 1);

// Stock restoration (cancellation/refund)
add_action('woocommerce_restore_order_stock', [__CLASS__, 'handle_wc_restore_order_stock'], 10, 1);

// Status changes
add_action('woocommerce_order_status_changed', [__CLASS__, 'handle_order_status_changed'], 10, 4);
```

---

## 📡 API Reference

### Base URL

```
https://yoursite.com/wp-json/ffw/v1/
```

### Authentication

All API endpoints require JWT token in header:

```http
Authorization: Bearer YOUR_JWT_TOKEN
```

#### Get Token (via SHRMS)

```http
POST /wp-json/shrms/v1/login
Content-Type: application/json

{
  "phone": "01234567890",
  "password": "employee_password"
}
```

**Response:**
```json
{
  "success": true,
  "token": "eyJ0eXAiOiJKV1QiLCJhbGc...",
  "employee": {
    "id": 25,
    "name": "Ahmed Ali",
    "role": "warehouse_manager"
  }
}
```

---

### Endpoints

#### 1. List Warehouses

```http
GET /ffw/v1/warehouses
```

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "Main WooCommerce Warehouse",
      "slug": "primary",
      "is_primary": true,
      "status": "active"
    },
    {
      "id": 2,
      "name": "Cairo Branch",
      "slug": "cairo",
      "is_primary": false,
      "status": "active"
    }
  ]
}
```

---

#### 2. Get Warehouse Inventory

```http
GET /ffw/v1/warehouses/{warehouse_id}/inventory
```

**Query Parameters:**
- `page` (int): Pagination page number
- `per_page` (int): Items per page (default: 20)
- `search` (string): Filter by product name/SKU

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "product_id": 100,
      "product_name": "Frozen Chicken 1kg",
      "sku": "FC-1KG",
      "qty": 150.000,
      "reserved_qty": 25.000,
      "total_physical": 175.000,
      "price": 50.00,
      "min_qty": 10.000
    }
  ],
  "pagination": {
    "total": 45,
    "page": 1,
    "per_page": 20,
    "total_pages": 3
  }
}
```

---

#### 3. Adjust Stock

```http
POST /ffw/v1/warehouses/{warehouse_id}/adjust
Content-Type: application/json

{
  "product_id": 100,
  "delta": 50,
  "notes": "Restocking from supplier"
}
```

**Parameters:**
- `product_id` (int): WooCommerce product ID
- `delta` (float): Change in quantity (positive or negative)
- `notes` (string): Reason for adjustment

**Response:**
```json
{
  "success": true,
  "message": "Stock adjusted successfully",
  "new_qty": 200.000,
  "log_id": 1523
}
```

---

#### 4. Transfer Stock

```http
POST /ffw/v1/transfer
Content-Type: application/json

{
  "from_warehouse_id": 1,
  "to_warehouse_id": 2,
  "product_id": 100,
  "qty": 30
}
```

**Response:**
```json
{
  "success": true,
  "message": "Stock transferred successfully",
  "from": {
    "warehouse_id": 1,
    "new_qty": 120.000
  },
  "to": {
    "warehouse_id": 2,
    "new_qty": 80.000
  }
}
```

---

#### 5. Create POS Order

```http
POST /ffw/v1/pos/orders
Content-Type: application/json

{
  "warehouse_id": 2,
  "employee_id": 25,
  "customer_name": "Walk-in Customer",
  "customer_phone": "01234567890",
  "payment_method": "cash",
  "items": [
    {
      "product_id": 100,
      "quantity": 2,
      "price": 50.00
    },
    {
      "product_id": 101,
      "quantity": 1,
      "price": 75.00
    }
  ]
}
```

**Response:**
```json
{
  "success": true,
  "order_id": 5234,
  "order_number": "#5234",
  "total": 175.00,
  "status": "completed",
  "stock_updated": true
}
```

---

#### 6. View Stock Logs

```http
GET /ffw/v1/logs
```

**Query Parameters:**
- `warehouse_id` (int): Filter by warehouse
- `product_id` (int): Filter by product
- `action_type` (string): Filter by action type
- `order_id` (int): Filter by order
- `from_date` (string): Start date (Y-m-d)
- `to_date` (string): End date (Y-m-d)
- `page` (int): Pagination

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1523,
      "warehouse_id": 1,
      "warehouse_name": "Primary",
      "product_id": 100,
      "product_name": "Frozen Chicken 1kg",
      "action_type": "wc_order_reserve",
      "qty_change": -10.000,
      "qty_before": 200.000,
      "qty_after": 190.000,
      "reserved_before": 15.000,
      "reserved_after": 25.000,
      "order_id": 5230,
      "employee_id": 25,
      "notes": "WooCommerce order stock reserved",
      "created_at": "2025-12-26 10:30:15"
    }
  ]
}
```

---

## 🔗 Integration Guide

### With WooCommerce

The plugin automatically hooks into WooCommerce:

**Automatic Actions:**

1. **Order Placed** (`pending` / `processing`)
   - Stock reserved from primary warehouse
   - `qty` decreased, `reserved_qty` increased

2. **Order Completed**
   - Reserved stock consumed
   - `reserved_qty` cleared

3. **Order Cancelled / Refunded**
   - Stock restored to available
   - `qty` increased, `reserved_qty` decreased

4. **Order Items Edited**
   - Stock adjusted based on quantity change

**Manual WooCommerce Stock Sync:**

```php
// Sync WooCommerce product stock from primary warehouse
FF_Warehouses_Core::sync_wc_stock_from_primary(
    $product_id,
    $variation_id, // or null
    $qty           // or null to read from warehouse
);
```

---

### With SHRMS Plugin

If SHRMS (HR Management) plugin is active:

**Authentication:**
- Use SHRMS employee credentials for API access
- JWT tokens generated via SHRMS login endpoint

**Employee Tracking:**
- All stock actions linked to `employee_id`
- Audit trail shows who performed each action

**Permissions:**
- Check employee permissions before allowing actions
- Sync with SHRMS role system

---

### Custom POS Application

**Example Flow:**

1. **Login:**
   ```javascript
   const response = await fetch('https://site.com/wp-json/shrms/v1/login', {
     method: 'POST',
     headers: { 'Content-Type': 'application/json' },
     body: JSON.stringify({ phone: '01234567890', password: 'pass' })
   });
   const { token } = await response.json();
   ```

2. **Get Warehouse Inventory:**
   ```javascript
   const inventory = await fetch('https://site.com/wp-json/ffw/v1/warehouses/2/inventory', {
     headers: { 'Authorization': `Bearer ${token}` }
   });
   ```

3. **Create POS Order:**
   ```javascript
   const order = await fetch('https://site.com/wp-json/ffw/v1/pos/orders', {
     method: 'POST',
     headers: {
       'Authorization': `Bearer ${token}`,
       'Content-Type': 'application/json'
     },
     body: JSON.stringify({
       warehouse_id: 2,
       employee_id: 25,
       customer_name: 'Ahmed',
       payment_method: 'cash',
       items: [{ product_id: 100, quantity: 2, price: 50 }]
     })
   });
   ```

---

## 📝 Workflow Examples

### Example 1: New Product Arrives at Warehouse

**Scenario:** 100 units of product #100 delivered to Cairo branch (warehouse #2)

**Steps:**

1. **Via API:**
   ```bash
   curl -X POST https://site.com/wp-json/ffw/v1/warehouses/2/adjust \
     -H "Authorization: Bearer TOKEN" \
     -H "Content-Type: application/json" \
     -d '{
       "product_id": 100,
       "delta": 100,
       "notes": "Restocking from supplier ABC"
     }'
   ```

2. **Result:**
   - `wp_ffw_warehouse_products` updated: `qty` increased by 100
   - `wp_ffw_stock_log` new entry: `action_type='manual_adjust'`
   - If warehouse #2 is primary, WooCommerce product stock updated

---

### Example 2: Transfer Stock Between Warehouses

**Scenario:** Move 50 units of product #100 from Primary (ID 1) to Cairo (ID 2)

**Steps:**

1. **Via API:**
   ```bash
   curl -X POST https://site.com/wp-json/ffw/v1/transfer \
     -H "Authorization: Bearer TOKEN" \
     -H "Content-Type: application/json" \
     -d '{
       "from_warehouse_id": 1,
       "to_warehouse_id": 2,
       "product_id": 100,
       "qty": 50
     }'
   ```

2. **Result:**
   - Warehouse #1: `qty` decreased by 50
   - Warehouse #2: `qty` increased by 50
   - Two log entries:
     - `transfer_out` from warehouse #1
     - `transfer_in` to warehouse #2
   - If warehouse #1 is primary, WooCommerce stock updated

---

### Example 3: Complete Order Lifecycle

**Scenario:** Customer orders 10 units of product #100

#### Step 1: Order Created (Pending)

```
Initial Stock:
  - qty: 100
  - reserved_qty: 20
  - Total: 120

Order Placed: 10 units

New Stock:
  - qty: 90 (-10)
  - reserved_qty: 30 (+10)
  - Total: 120 (unchanged)

Log Entry:
  - action_type: wc_order_reserve
  - qty_change: -10
  - order_id: 5230
```

#### Step 2: Order Completed

```
Current Stock:
  - qty: 90
  - reserved_qty: 30

Order Completed: 10 units consumed

New Stock:
  - qty: 90 (unchanged)
  - reserved_qty: 20 (-10)
  - Total: 110 (-10 consumed)

Log Entry:
  - action_type: wc_order_complete
  - qty_change: 0
  - reserved change: -10
  - order_id: 5230
```

#### Alternative: Order Cancelled

```
Current Stock:
  - qty: 90
  - reserved_qty: 30

Order Cancelled: 10 units restored

New Stock:
  - qty: 100 (+10)
  - reserved_qty: 20 (-10)
  - Total: 120 (restored)

Log Entry:
  - action_type: wc_order_restore
  - qty_change: +10
  - order_id: 5230
```

---

## 🖥️ Admin Panel

### Menu Structure

```
FF Warehouses
├── Dashboard
│   └── Overview, stats, alerts
├── Warehouses
│   ├── List all warehouses
│   ├── Add new warehouse
│   └── Edit warehouse details
├── Inventory
│   ├── View stock per warehouse
│   ├── Adjust stock (increase/decrease)
│   └── Set minimum quantities
├── Transfers
│   ├── Create new transfer
│   └── View transfer history
├── Stock Logs
│   ├── Filter by warehouse/product/date
│   ├── Export to CSV
│   └── View detailed history
└── Settings
    ├── Primary warehouse configuration
    ├── Employee permissions
    └── API settings
```

### Key Features

- 📊 **Real-time Dashboard**: Current stock levels, low stock alerts
- 📝 **Bulk Operations**: Adjust multiple products at once
- 📥 **Export Functions**: Download inventory reports as CSV
- 🔍 **Advanced Filters**: Search by product, date range, action type
- 📱 **Responsive Design**: Mobile-friendly admin interface

---

## 👨‍💻 Developer Guide

### Custom Development

#### Hook Into Stock Changes

```php
// After stock adjustment
add_action('ffw_stock_adjusted', function($warehouse_id, $product_id, $delta, $new_qty) {
    // Send notification
    // Update external system
    // Trigger automation
}, 10, 4);

// Before transfer
add_filter('ffw_before_transfer', function($from_id, $to_id, $product_id, $qty) {
    // Validation logic
    // Return false to cancel transfer
    return true;
}, 10, 4);
```

#### Access Core Functions

```php
// Get warehouse
$warehouse = FF_Warehouses_Core::get_warehouse(2);

// Get inventory row
$row = FF_Warehouses_Core::get_inventory_row($warehouse_id, $product_id);

// Adjust stock
$result = FF_Warehouses_Core::adjust_available_qty($warehouse_id, $product_id, 50);

// Transfer stock
$result = FF_Warehouses_Core::transfer_stock(
    $from_warehouse_id,
    $to_warehouse_id,
    $product_id,
    $qty,
    $employee_id
);

// Log custom action
FF_Warehouses_Core::log_stock_action(
    $warehouse_id,
    $product_id,
    'custom_action',
    $qty_change,
    $qty_before,
    $qty_after,
    $reserved_before,
    $reserved_after,
    $order_id,
    $employee_id,
    'Custom action description'
);
```

#### Extend API

```php
// Register custom endpoint
add_action('rest_api_init', function() {
    register_rest_route('ffw/v1', '/custom-endpoint', [
        'methods'  => 'POST',
        'callback' => 'my_custom_callback',
        'permission_callback' => [FF_Warehouses_Auth::class, 'check_jwt']
    ]);
});
```

---

## ⚙️ Troubleshooting

### Common Issues

#### 1. Stock Not Syncing with WooCommerce

**Symptoms:**
- Changes in warehouse don't reflect in WooCommerce product stock

**Solutions:**
- ✅ Ensure warehouse is marked as **primary** (`is_primary = 1`)
- ✅ Check `$suppress_wc_stock_sync` flag is not stuck as `true`
- ✅ Verify product has `manage_stock` enabled

#### 2. Reserved Stock Not Clearing

**Symptoms:**
- `reserved_qty` remains high after orders completed

**Solutions:**
- ✅ Check order meta `_ffw_stock_status`
- ✅ Manually trigger: `FF_Warehouses_Core::handle_wc_order_completed($order_id)`
- ✅ Review stock log for related order

#### 3. API Authentication Fails

**Symptoms:**
- 401 or 403 errors on API requests

**Solutions:**
- ✅ Verify JWT token is valid and not expired
- ✅ Check SHRMS plugin is active
- ✅ Confirm employee has required permissions
- ✅ Test token: `FF_Warehouses_Auth::validate_token($token)`

#### 4. Duplicate Stock Deductions

**Symptoms:**
- Stock deducted twice for same order

**Solutions:**
- ✅ Check for multiple hooks firing
- ✅ Verify `_ffw_stock_status` meta exists
- ✅ Review stock log for duplicate entries
- ✅ Clear order meta and reprocess

---

## 📦 Requirements

### Minimum Requirements

- **WordPress:** 5.8 or higher
- **PHP:** 7.4 or higher
- **MySQL:** 5.6 or higher
- **WooCommerce:** 5.0 or higher

### Recommended

- **PHP:** 8.0 or higher
- **MySQL:** 8.0 or higher
- **Memory Limit:** 256MB+
- **Max Execution Time:** 60 seconds

### Optional Dependencies

- **SHRMS Plugin**: For employee authentication and tracking
- **Redis/Memcached**: For advanced caching (future enhancement)

---

## 📝 Changelog

### Version 1.0.0 (December 2025)

#### Added
- ✅ Complete multi-warehouse system
- ✅ Dual-state inventory (available + reserved)
- ✅ Intelligent order lifecycle handling
- ✅ Smart stock status detection
- ✅ Stock transfer between warehouses
- ✅ Complete audit logging
- ✅ REST API with JWT authentication
- ✅ SHRMS integration
- ✅ POS order support
- ✅ Admin panel with inventory management
- ✅ Employee permission system

#### Features
- 🔄 Automatic WooCommerce stock sync
- 📉 Order status change handling
- 📝 Order item editing support
- ⚡ Optimized database queries with indexes
- 🛡️ Protection against duplicate stock actions

---

## 📄 License

This plugin is licensed under the **GNU General Public License v2.0 or later**.

---

## 👤 Author

**Abdulrahman Roston**

- 🌐 Website: [abdulrahmanroston.com](https://abdulrahmanroston.com)
- 📧 Email: support@abdulrahmanroston.com
- 🐙 GitHub: [@abdulrahmanroston](https://github.com/abdulrahmanroston)

---

## 🤝 Contributing

Contributions are welcome! Please:

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

---

## 📞 Support

For support, bug reports, or feature requests:

- 🐛 Issues: [GitHub Issues](https://github.com/abdulrahmanroston/warehouses_manager_plugin/issues)
- 📧 Email: support@abdulrahmanroston.com
- 📚 Documentation: [Wiki](https://github.com/abdulrahmanroston/warehouses_manager_plugin/wiki)

---

## ⭐ Show Your Support

If you find this plugin useful:

- ⭐ Star the repository
- 🐛 Report bugs
- 💡 Suggest features
- 📢 Share with others

---

**Made with ❤️ in Egypt 🇪🇬**

---

© 2025 Abdulrahman Roston. All rights reserved.