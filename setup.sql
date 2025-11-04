CREATE DATABASE IF NOT EXISTS image_viewer CHARACTER SET utf8mb4;
USE image_viewer;

CREATE TABLE albums (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) UNIQUE NOT NULL,
    is_public TINYINT(1) DEFAULT 1,
    uuid CHAR(36) NULL UNIQUE,
    thumbnail INT(11) NOT NULL DEFAULT 0
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