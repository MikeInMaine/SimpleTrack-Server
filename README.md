# SimpleTrack-Server

A lightweight self-hosted family location tracker for Android. No third-party services, 
no subscriptions. Runs on a basic LAMP stack (Linux, Apache, MySQL, PHP).

## Features
- Live map of all family devices (OpenStreetMap, no API key needed)
- Speed, direction, altitude, accuracy per device
- Breadcrumb trail history (1 hour to 1 week)
- Automatic stop detection with address lookup
- Stop history with per-device filtering
- 30-day cookie authentication
- Zero extra server processes — runs entirely within existing Apache/MySQL/PHP
- Manual SOS button
- Automatic (disableable) crash detection

## Requirements
- Apache 2.4+
- MySQL 8.0+
- PHP 8.x
- Let's Encrypt SSL (certbot)
- Android phones running SimpleTrack client (free, Play Store)

## Server Installation

### 1. Database setup
```sql
CREATE DATABASE loctrack;
CREATE USER 'loctrack'@'localhost' IDENTIFIED BY 'your-password';
GRANT SELECT, INSERT ON loctrack.locations TO 'loctrack'@'localhost';
GRANT SELECT, INSERT, UPDATE ON loctrack.stops TO 'loctrack'@'localhost';
FLUSH PRIVILEGES;
```

Run the schema:
```bash
mysql -u root loctrack < setup_db.sql
```

### 2. Configuration
```bash
cp config/config.php.example config/config.php
# Edit config/config.php with your real DB password, auth secret, and groups/devices
```
Generate a password hash:
```bash
php -r "echo password_hash('yourpassword', PASSWORD_DEFAULT);"
```

### 3. Apache vhost
Copy `track.prestile.com.conf.example` to `/etc/apache2/sites-available/` 
and edit for your domain. Then:
```bash
certbot --apache -d your.domain.com
a2ensite your.domain.com.conf
systemctl reload apache2
```

### 4. Traccar Client app (each phone)
- Install **SimpleTrack** client from Play Store https://play.google.com/apps/testing/com.prestile.simpletrack (note: Simpletrack is in "Open Testing" on the Play Store).
- Device identifier: firstname (must match DEVICES in config.php)
- Server URL: `https://your.domain.com/track.php`
- Accept permissions on first run.
- Exempt from battery optimization - Always

## Files
| File | Purpose |
|------|---------|
| `track.php` | Receives location pings from phones |
| `api.php` | JSON endpoint for the map page |
| `index.php` | Map viewer (HTML/JS) |
| `login.php` | Cookie-based login page |
| `auth.php` | Authentication functions |
| `config/config.php` | DB credentials and settings (gitignored, server-only, not in repo) |
| `setup_db.sql` | Database schema |

## Stop Detection
Stops are detected in real-time on each check-in. A stop is recorded when 
a device remains within 150 meters of a centroid point for 5+ minutes.
Stops are automatically closed when the device moves away.
