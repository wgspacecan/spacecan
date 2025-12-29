# SpaceCan

A secure, admin-managed image and video gallery with public albums, unlisted sharing, and a private video vault.

## Features

### Image Gallery
- **Public & Unlisted Albums** — Public albums visible on homepage, unlisted accessible via UUID
- **Admin Panel** (`/admin.php`) — Create, edit, delete albums; manage images
- **Drag & Drop Upload** — Bulk upload with automatic thumbnail generation (400px progressive JPEG)
- **Image Viewer** (`/view.php`) — Full-resolution viewing with OpenSeadragon zoom, metadata display, download, next/prev navigation
- **Mobile-responsive** design

### Video Vault
- **Private Video Storage** (`/vault.php`) — Separate password-protected area
- **Secret Key Access** — URL-based key requirement for vault access
- **Video Streaming** — Nginx X-Accel-Redirect for efficient delivery
- **1080p Transcodes** — Optional streaming quality versions
- **Auto Thumbnails** — Generated via ffmpeg

### Audit & Monitoring
- **Audit Log Dashboard** (`/audit.php`) — Real-time event monitoring
- **IP Geolocation** — Location lookup with 24-hour caching
- **Unique IP Tracking** — Statistics on visitor IPs
- **Event Filtering** — Filter by action, type, date, IP, or search term
- **Top IPs Summary** — Activity breakdown by IP address

## Security

### Authentication
- **Rate Limiting** — 5 failed attempts triggers 15-minute lockout
- **Session Security** — HttpOnly, Secure, SameSite=Strict cookies
- **Session Timeout** — 30-minute inactivity expiration
- **CSRF Protection** — Token validation on all forms
- **Password Hashing** — bcrypt with cost factor 12

### Headers
- `Content-Security-Policy` — Restricts script/style/image sources
- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: DENY`
- `Referrer-Policy: strict-origin-when-cross-origin`
- `Permissions-Policy` — Disables camera, microphone, geolocation, payment
- `Cache-Control: no-store`

### Data Protection
- **Prepared Statements** — All SQL queries use parameterized queries
- **Input Sanitization** — `basename()`, `htmlspecialchars()`, path validation
- **Path Traversal Prevention** — `realpath()` checks on file access
- **SRI** — Subresource Integrity for CDN scripts (OpenSeadragon)

### Nginx Protections
- `/vault/` — Direct access denied (404)
- `/logs/` — Direct access denied (404)
- `/.` — Dotfiles denied (protects .env, .git)
- `/internal-vault/` — Internal only (X-Accel-Redirect)

## Project Structure

```
/srv/spacecan/
├── .env                  # Secrets (DB password, vault keys)
├── config.php            # DB connection, session, security headers
├── functions.php         # Shared functions (logging, rate limiting)
├── style.css             # Global styles
│
├── index.php             # Homepage (public albums)
├── album.php             # Album view (image grid)
├── view.php              # Single image viewer (OpenSeadragon)
├── download.php          # Secure file download
│
├── login.php             # Admin login
├── admin.php             # Admin panel
├── password.php          # Change admin password
├── upload.php            # AJAX upload endpoint
├── script.js             # Drag & drop upload handler
│
├── vault.php             # Private video vault
├── audit.php             # Audit log dashboard
│
├── assets/
│   ├── favicon.ico
│   └── placeholder.JPG
│
├── images/
│   ├── public/           # Album image storage
│   └── thumbnails/       # Generated 400px thumbnails
│
├── vault/                # Private video files
│   ├── stream/           # 1080p transcoded versions
│   └── thumbs/           # Video thumbnails
│
└── logs/
    ├── access.log        # Application events
    ├── errors.log        # PHP errors
    ├── rate_limits.json  # Admin login rate limiting
    ├── vault_rate.json   # Vault login rate limiting
    └── ip_cache.json     # IP geolocation cache
```

## Logging Standard

All events use a unified format via `logEvent()`:

```
YYYY-MM-DD HH:MM:SS [action] [type] ip:x.x.x.x details {"extra":"data"}
```

### Actions (lowercase)
| Action     | Usage                                      |
|------------|-------------------------------------------|
| `error`    | System errors, database failures          |
| `alert`    | Security events (path traversal, bad keys)|
| `auth`     | Login success/failure/blocked             |
| `info`     | General information                       |
| `view`     | Page/content views, video streams         |
| `download` | File downloads                            |

### Types (context)
`admin`, `database`, `download`, `vault`, `album`

## Setup

### 1. Environment File
```bash
# Create .env with secrets
cat > .env << 'EOF'
IMGUSER_DB_PASS=your_db_password
VAULT_SECRET_KEY=your_long_random_key
VAULT_PASSWORD=your_vault_password
EOF

chmod 600 .env
chown http:http .env
```

### 2. Database
```sql
CREATE DATABASE IF NOT EXISTS image_viewer CHARACTER SET utf8mb4;
USE image_viewer;

CREATE TABLE albums (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) UNIQUE NOT NULL,
    is_public TINYINT(1) DEFAULT 1,
    uuid CHAR(36) NULL UNIQUE,
    thumbnail INT(11) NOT NULL DEFAULT 0,
    author VARCHAR(100) DEFAULT NULL
);

CREATE TABLE images (
    id INT AUTO_INCREMENT PRIMARY KEY,
    album_id INT,
    filename VARCHAR(255),
    title VARCHAR(255) DEFAULT '',
    FOREIGN KEY (album_id) REFERENCES albums(id) ON DELETE CASCADE
);

CREATE TABLE admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password CHAR(60) NOT NULL
);

-- Create database user
CREATE USER 'imguser'@'localhost' IDENTIFIED BY 'your_db_password';
GRANT ALL ON image_viewer.* TO 'imguser'@'localhost';

-- Default admin (password: admin123 - CHANGE THIS)
INSERT INTO admins (username, password) VALUES (
    'admin',
    '$2y$12$uQ8FUmofT4x6yhrprrXEu.mF9iOAhsLIHvrHoUk7/pOfInhvWg7gK'
);
```

### 3. Dependencies
```bash
# Arch Linux
sudo pacman -S imagemagick php php-fpm nginx mariadb ffmpeg

# Enable PHP extensions in /etc/php/php.ini
extension=mysqli
extension=gd
```

### 4. Directory Permissions
```bash
chown -R http:http /srv/spacecan
chmod 755 /srv/spacecan
chmod 700 /srv/spacecan/vault
chmod 700 /srv/spacecan/logs
chmod 755 /srv/spacecan/images
```

### 5. Nginx Configuration
```nginx
server {
    listen 443 ssl;
    listen [::]:443 ssl;
    http2 on;

    server_name spacecan.club;
    root /srv/spacecan;

    ssl_certificate /etc/letsencrypt/live/spacecan.club/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/spacecan.club/privkey.pem;
    include /etc/nginx/ssl_config.conf;

    location / {
        index index.php;
    }

    location /images/ {
        expires off;
        add_header Cache-Control "no-store, no-cache, must-revalidate";
    }

    # Security: Block sensitive paths
    location /vault/ { deny all; return 404; }
    location /logs/ { deny all; return 404; }
    location ~ /\. { deny all; return 404; }

    # Internal redirect for vault streaming
    location /internal-vault/ {
        internal;
        alias /srv/spacecan/vault/;
        sendfile on;
        tcp_nopush on;
        add_header Accept-Ranges bytes;
    }

    error_page 500 502 503 504 /50x.html;
    include /etc/nginx/php_fastcgi.conf;
}

# HTTP to HTTPS redirect
server {
    listen 80;
    server_name spacecan.club www.spacecan.club;
    return 301 https://spacecan.club$request_uri;
}
```

### 6. Log Rotation
```bash
# /etc/logrotate.d/spacecan
/srv/spacecan/logs/access.log {
    size 100M
    rotate 7
    missingok
    notifempty
    create 0640 http http
    su http http
    postrotate
        systemctl reload php-fpm >/dev/null 2>&1 || true
    endscript
}
```

## Usage

### Admin Panel
1. Navigate to `https://spacecan.club/admin.php`
2. Login with admin credentials
3. Create albums, upload images, manage content
4. Access audit log via "Audit Log" link

### Vault Access
1. Navigate to `https://spacecan.club/vault.php?key=YOUR_SECRET_KEY`
2. Enter vault password
3. Stream or download videos

### Unlisted Albums
- Share via UUID: `https://spacecan.club/album.php?u=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx`

## License

MIT
