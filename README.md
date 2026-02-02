# OF Web Browser

A lightweight PHP web application for browsing OnlyFans backup files. View your locally downloaded content through a beautiful, OnlyFans-inspired dark theme interface.

![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4?logo=php&logoColor=white)
![SQLite](https://img.shields.io/badge/SQLite-3-003B57?logo=sqlite&logoColor=white)
![License](https://img.shields.io/badge/License-MIT-green)

## Features

- **Library View** - Browse all creators in a responsive grid layout
- **Profile Pages** - View posts and media for each creator with pagination
- **Media Lightbox** - Full-screen image/video viewer with keyboard navigation
- **Post Carousel** - Swipe through multiple media items per post
- **Media Filtering** - Filter by photos or videos
- **Auto-Scan** - Automatically aggregates data from OF-DL backup databases
- **Dark Theme** - OnlyFans-inspired aesthetic

## Screenshots

<img width="1920" height="921" alt="Screenshot 2026-02-02 010006" src="https://github.com/user-attachments/assets/e7c0377e-4f7a-4f16-83e6-03324ecdfd69" />
<img width="1922" height="915" alt="Screenshot 2026-02-02 010142" src="https://github.com/user-attachments/assets/e669d288-dea9-406d-8a07-009b891128db" />

## Requirements

- PHP 7.4 or higher
- SQLite3 PHP extension (usually included)
- Web server (Apache, Nginx, or PHP built-in server)
- OnlyFans backups created with [OF-DL](https://github.com/sim0n00ps/OF-DL) or similar tools

## Installation

1. **Clone the repository**
   ```bash
   git clone https://github.com/BentByte-Studios/OFWebBrowser.git
   cd OFWebBrowser
   ```

2. **Configure the download path**

   Edit `config.php` and set your OnlyFans download folder:
   ```php
   define('OF_DOWNLOAD_PATH', 'C:/path/to/your/OnlyFans/downloads');
   ```

3. **Start a web server**

   Using PHP's built-in server:
   ```bash
   php -S localhost:8080
   ```

   Or configure Apache/Nginx to serve the directory.

4. **Open in browser**

   Navigate to `http://localhost:8080` and click "Rescan Library" to import your content.

## Configuration

Edit `config.php` to customize:

```php
// Path to your OnlyFans downloads folder
define('OF_DOWNLOAD_PATH', 'C:/Users/you/Downloads/OnlyFans');

// Site title shown in browser tab
define('SITE_TITLE', 'OF Web Browser');

// Items per page (posts/media)
define('ITEMS_PER_PAGE', 50);

// Your timezone
date_default_timezone_set('America/Chicago');
```

## Project Structure

```
browser/
├── config.php              # Configuration settings
├── index.php               # Home page - creator library
├── profile.php             # Creator profile page
├── scan.php                # Database aggregation API
├── view.php                # Media file server
├── includes/
│   ├── db.php              # SQLite wrapper for source databases
│   ├── global_db.php       # Global database manager
│   ├── functions.php       # Helper functions
│   └── error_handler.php   # Centralized error handling
├── db/
│   └── global.db           # Aggregated database (auto-created)
└── logs/
    └── app.log             # Error logs (auto-created)
```

## How It Works

1. **Scanning**: The app scans your `OF_DOWNLOAD_PATH` for creator folders containing `user_data.db` files
2. **Aggregation**: Data from individual creator databases is aggregated into a single `global.db`
3. **Browsing**: The web interface reads from the global database for fast, unified access
4. **Serving**: Media files are served through `view.php` with proper security checks

## Security

This application includes several security measures:

- **Path Traversal Protection** - All file paths are validated to stay within the download directory
- **SQL Injection Prevention** - All queries use parameterized statements
- **Input Validation** - User inputs are sanitized and validated
- **Scan Locking** - Prevents concurrent scans from corrupting data
- **Error Logging** - Errors are logged to file, not exposed to users

**Note**: This is designed for local/private use. Do not expose to the public internet without additional authentication.

## Keyboard Shortcuts

In the lightbox viewer:
- `←` / `→` - Navigate between media
- `Escape` - Close lightbox

## Troubleshooting

### "No creators found"
- Verify `OF_DOWNLOAD_PATH` in `config.php` points to your downloads
- Ensure creator folders contain `user_data.db` or `Metadata/user_data.db`
- Click "Rescan Library" to re-import

### Images/videos not loading
- Check that `OF_DOWNLOAD_PATH` is accessible by PHP
- Verify file permissions allow PHP to read media files

### Scan errors
- Check `logs/app.log` for detailed error messages
- Ensure no other scan is in progress (wait 10 minutes for lock timeout)

## Database Schema

The application uses SQLite with the following tables:

```sql
-- Creators table
CREATE TABLE creators (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT UNIQUE,
    folder_path TEXT,
    avatar_path TEXT,
    header_path TEXT,
    bio TEXT,
    scanned_at DATETIME
);

-- Posts table
CREATE TABLE posts (
    id INTEGER PRIMARY KEY,
    post_id INTEGER,
    creator_id INTEGER,
    text TEXT,
    price INTEGER,
    paid INTEGER,
    archived INTEGER,
    created_at DATETIME,
    UNIQUE(post_id, creator_id)
);

-- Media table
CREATE TABLE medias (
    id INTEGER PRIMARY KEY,
    media_id INTEGER,
    post_id INTEGER,
    creator_id INTEGER,
    filename TEXT,
    directory TEXT,
    size INTEGER,
    type TEXT,
    downloaded INTEGER,
    created_at DATETIME,
    UNIQUE(media_id)
);
```

## Compatibility

Works with backups from:
- [OF-DL](https://github.com/sim0n00ps/OF-DL)
- Other tools that create `user_data.db` SQLite databases

The application expects the following folder structure:
```
OnlyFans/
├── CreatorName1/
│   ├── Metadata/
│   │   └── user_data.db
│   ├── Posts/
│   └── Profile/
├── CreatorName2/
│   ├── Metadata/
│   │   └── user_data.db
└── ...
```

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/AmazingFeature`)
3. Commit your changes (`git commit -m 'Add some AmazingFeature'`)
4. Push to the branch (`git push origin feature/AmazingFeature`)
5. Open a Pull Request

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## Disclaimer

This tool is for personal use to browse your own legally obtained content. The developers are not responsible for how the software is used. Always respect content creators and their terms of service.

---

Made with PHP and SQLite
