# ACF DB Sync

## Overview
A WordPress admin plugin that ensures every existing post has a `postmeta` row for every ACF field defined in its assigned field groups. Useful after adding new ACF fields to a live site where thousands of posts are missing those rows.

## Problem It Solves
- When you add a new ACF field group to an existing WordPress site, old posts have no `postmeta` row for the new fields — ACF calls and meta queries return empty or unexpected results until each post is individually saved
- Manually re-saving hundreds or thousands of posts to trigger ACF's own meta-row creation is not practical
- Target users: WordPress developers managing content-heavy sites with Advanced Custom Fields

## Use Cases
1. A developer adds three new ACF fields to a field group attached to 2,000 product posts — ACF DB Sync creates the missing `postmeta` rows in bulk from the admin panel without touching any post content
2. After a database migration or import, a dev runs a sync to guarantee all posts have consistent meta structure before going live
3. A sync is run in dry-run mode first to preview how many rows would be created, then confirmed

## Key Features
- **Bulk meta-row creation** — inserts missing `postmeta` rows for all posts in the assigned field groups
- **Admin UI** — dedicated settings page under the WordPress admin with sync controls and progress output
- **ACF-aware** — reads field groups and field definitions directly from ACF, no manual field configuration required
- **Safe to re-run** — skips rows that already exist; idempotent

## Tech Stack
- PHP 7.4+
- WordPress 6.0+
- Advanced Custom Fields (free or Pro)

## Getting Started

### Prerequisites
- Advanced Custom Fields must be installed and active

### Installation
1. Copy the `wp-db-sync` folder to `wp-content/plugins/`
2. Activate **ACF DB Sync** in the WordPress admin under **Plugins**
3. Navigate to the **ACF DB Sync** settings page in the admin menu
4. Select the post types or field groups to sync and click **Run Sync**

> Recommended: take a database backup before running a large sync on a production site.
