# Image Viewer

A secure, admin-managed image gallery with public and unlisted albums.

## Features

- **Public & Unlisted Albums** (via slug or UUID)
- **Admin Panel** (`/admin.php`) — login required
- **Drag & Drop Upload** with thumbnail generation
- **Image Viewer** (`view.php?id=123`) — metadata, download, next/prev
- **CSRF Protection**, prepared statements, input sanitization
- **Progressive JPEG thumbnails** (fast loading)
- **Mobile-responsive**

---

## Project Structure

```
/srv/http/
├── .env                  # Secrets
├── .gitignore            # Git ignore
├── README.md             # Git README
├── config.php            # DB + paths (example.config.php)
├── functions.php         # PHP functions
├── style.css             # CSS styling
├── index.php             # Entry point
|── album.php             # Album view
├── view.php              # Single image view
|── download.php          # Download images
├── login.php             # Login page
├── admin.php             # Admin panel
├── password.php          # Change password
├── upload.php            # AJAX upload endpoint
|── script.js             # Drop upload
|── assets/               # Other content
│   ├── placeholder.JPG   # Thumbnail for empty albums
│   └── favicon.ico       # Webpage icon
├── images/               # Image directory
│   ├── public/           # User uploads
│   └── thumbnails/       # Generated 400px thumbs
└── logs/                 # access.log, php-error.log
    ├── access.log        # User actions and alerts
    └── errors.log        # PHP critical errors
```

## Setup

### 1. Clone & Configure
```bash
cd /srv/http
git clone https://github.com/yourname/image-viewer .
```

### 2. Create `.env`
```env
IMGUSER_DB_PASS=your_strong_password
```

```bash
chmod 600 .env
chown http:http .env
```

### 3. Database
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
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password CHAR(60) NOT NULL
);

CREATE USER 'imguser'@'localhost' IDENTIFIED BY 'your_secure_pass';
GRANT ALL ON image_viewer.* TO 'imguser'@'localhost';
```

### 4. Install Dependencies
```bash
sudo pacman -S imagemagick php php-fpm nginx mariadb certbot
```

### 5. Web Server (Nginx Example)
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
            #try_files $uri $uri/ /index.php?$query_string;
    }

    location /images/ {
            expires off;
            add_header Cache-Control "no-store, no-cache, must_revalidate";
            access_log off;
    }

    location ~ \.chunks/ {
            deny all;
            return 403;
    }

    # Error
    error_page 500 502 503 504 /50x.html;
    location = /50x.html {
            root /usr/share/nginx/html;
    }

    # PHP
    include /etc/nginx/php_fastcgi.conf;
}

# Redirect HTTP
server {
    listen 80;
    listen [::]:80;

    server_name spacecan.club www.spacecan.club;

    return 301 https://$host$request_uri;
}

# Remove www
server {
    listen 443 ssl;
    listen [::]:443 ssl;
    http2 on;

    server_name www.spacecan.club;
    ssl_certificate /etc/letsencrypt/live/spacecan.club/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/spacecan.club/privkey.pem;
    include /etc/nginx/ssl_config.conf;

    return 301 https://spacecan.club$request_uri;

}
```

### 6. Admin Login
- **URL:** `https://spacecan.club/admin.php`
- **Password:** `admin123` (change in `admin.php`)

---

## Security

- HTTPS enforced
- CSRF tokens
- Prepared statements
- Input sanitization
- Security headers in `config.php`

---

## License

MIT