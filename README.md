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
/srv/spacecan/
├── .env                  # Secrets (DB_PASS) — NOT in Git
├── .gitignore
├── README.md
├── config.php            # DB + paths
├── admin.php             # Admin panel
├── login.php             # Login page
├── upload.php            # AJAX upload endpoint
├── view.php              # Single image view
├── public/               # Document root
│   ├── index.php         # Entry point (symlink/rewrite)
│   ├── images/           # User uploads (public + unlisted)
│   └── thumbnails/       # Generated 400px thumbs
└── logs/                 # access.log, php-error.log
```

---

## Setup

### 1. Clone & Configure
```bash
cd /srv/spacecan
git clone https://github.com/yourname/image-viewer .
```

### 2. Create `.env`
```env
IMGUSER_DB_PASS=your_strong_password
```

```bash
chmod 600 .env
chown www-data:www-data .env
```

### 3. Database
```sql
CREATE DATABASE image_viewer CHARACTER SET utf8mb4;
CREATE USER 'imguser'@'localhost' IDENTIFIED BY 'your_password';
GRANT ALL ON image_viewer.* TO 'imguser'@'localhost';

CREATE TABLE albums (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  slug VARCHAR(255) NOT NULL UNIQUE,
  is_public TINYINT(1) DEFAULT 1,
  uuid CHAR(36) NULL
);

CREATE TABLE images (
  id INT AUTO_INCREMENT PRIMARY KEY,
  album_id INT NOT NULL,
  filename VARCHAR(255) NOT NULL,
  title VARCHAR(255),
  uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (album_id) REFERENCES albums(id) ON DELETE CASCADE
);
```

### 4. Install Dependencies
```bash
sudo pacman -S imagemagick php php-fpm nginx mariadb
```

### 5. Web Server (Nginx Example)
```nginx
server {
    listen 443 ssl;
    server_name spacecan.club;
    root /srv/spacecan/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php-fpm/php-fpm.sock;
        fastcgi_index index.php;
        include fastcgi.conf;
    }
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