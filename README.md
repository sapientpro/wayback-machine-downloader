# Wayback Machine Downloader

A PHP script that downloads archived versions of websites from the Wayback Machine for a specific date and domain.

## Features

- Downloads complete website snapshots from the Wayback Machine
- Handles both HTML pages and their resources (images, CSS, JavaScript)
- Supports multiple date formats and fallback to nearest available snapshots
- Intelligent URL normalization and path handling
- Automatic creation of directory structure
- Generates sitemap of downloaded pages
- Tracks external links and failed downloads
- Emulates random browser fingerprints for each request
- Comprehensive error logging and debugging options
- Support for skipping existing files
- Configurable maximum URL limit
- Archive.org reference detection and filtering

## Requirements

- PHP 7.4 or higher
- cURL extension
- DOM extension
- JSON extension
- Write permissions in the output directory

## Installation

1. Clone this repository or download the script
2. Ensure PHP and required extensions are installed
3. Make the script executable (optional):
   ```bash
   chmod +x run.php
   ```

## Usage

```bash
php run.php <domain> <date YYYYMMDD> [debug_level] [skip_existing] [max_urls]
```

### Parameters

- `domain`: The domain to download (e.g., example.com)
- `date`: The date to download in YYYYMMDD format (e.g., 20240321)
- `debug_level`: (Optional) Show only errors ('error') or all info ('info', default)
- `skip_existing`: (Optional) Skip URLs that already have files (1) or download all (0, default)
- `max_urls`: (Optional) Maximum number of URLs to process (default: 50)

### Examples

Basic usage:
```bash
php run.php example.com 20240321
```

With debug level set to error only:
```bash
php run.php example.com 20240321 error
```

Skip existing files:
```bash
php run.php example.com 20240321 info 1
```

Limit to 100 URLs:
```bash
php run.php example.com 20240321 info 0 100
```

## Output Structure

The script creates the following structure in the `output` directory:

```
output/
└── example.com/
    ├── index.html
    ├── sitemap.txt
    ├── missing.log
    └── [other downloaded files and directories]
```

- `index.html`: The homepage of the website
- `sitemap.txt`: List of all downloaded pages
- `missing.log`: Log of URLs that failed to download or contained archive.org references
- Other files and directories mirror the original website structure

## Features in Detail

### Browser Fingerprinting
- Random user agent strings for each request
- Varying browser versions and platforms
- Realistic security headers and preferences
- Different OS versions and configurations

### Error Handling
- Detailed error logging in missing.log
- HTTP status code tracking
- cURL error reporting
- Archive.org reference detection
- Empty response detection

### URL Processing
- Automatic URL normalization
- Fragment identifier removal
- Trailing slash handling
- Query parameter preservation
- Protocol-relative URL resolution

### Resource Management
- Automatic directory creation
- File existence checking
- Skip existing files option
- Resource type detection
- MIME type handling

### Debugging
- Verbose logging options
- cURL verbose output
- HTTP header inspection
- Response body preview
- Redirect tracking

## Logging

### missing.log Format
```
=== Missing URLs for example.com (20240321) ===
Format: URL | Error | Timestamp
----------------------------------------
https://example.com/page.html | cURL error (28): Connection timed out | 2024-03-21 15:30:45
https://example.com/other.html | Contains archive.org references | 2024-03-21 15:31:12
```

### Console Output
The script provides real-time feedback with color-coded messages:
- [...] Progress messages
- [✓] Success messages
- [×] Warning/Error messages
- [↗] Information messages

## Notes

- The script respects Wayback Machine's rate limits
- Failed downloads are logged for later retry
- External links are tracked but not downloaded
- Archive.org references are detected and filtered
- Each request uses a unique browser fingerprint

## Troubleshooting

If you encounter issues:
1. Check the missing.log file for detailed error information
2. Verify your PHP version and extensions
3. Ensure you have write permissions in the output directory
4. Check your internet connection
5. Try increasing the retry count or sleep times in the script

## License

This project is open source and available under the MIT License.

---

## Professional Web Scraping Services

Need help with large-scale web scraping projects? Check out our professional web scraping services at [SapientPro](https://sapient.pro/big-data-and-scraping-services).

Our team of experts can help you with:
- Custom web scraping solutions
- Data extraction and processing
- Large-scale data collection
- API development and integration
- Data analysis and visualization

Visit out website for [Custom Software Development](https://sapient.pro/custom-software-development) Services. 