# Wayback Machine Downloader - Web Archive Extractor

A powerful PHP script for downloading and archiving complete website snapshots from the Internet Archive's Wayback Machine. This tool helps developers, researchers, and digital archivists preserve web content by downloading historical versions of websites with all their resources.

## Key Features

- **Complete Website Archiving**: Download full website snapshots with HTML, CSS, JavaScript, and media files
- **Smart Resource Handling**: Automatic processing of all website assets and dependencies
- **Intelligent URL Management**: Advanced URL normalization and deduplication
- **Browser Emulation**: Random browser fingerprinting to avoid detection
- **Flexible Configuration**: Customizable download limits and existing file handling
- **Comprehensive Logging**: Detailed error tracking and download statistics
- **Archive.org Integration**: Direct integration with Wayback Machine's APIs
- **Resource Preservation**: Maintains original website structure and file organization

## Use Cases

This tool is perfect for:

- **Wayback Machine Archiving & Downloading**: Download complete snapshots from the Internet Archive's Wayback Machine, perfect for web archive extraction and preservation
- **Internet Archive Content Extraction**: Extract and preserve historical web content from web archives, including all resources and dependencies
- **Website Backup & Recovery**: Create local backups of historical website versions for disaster recovery and content preservation
- **Digital Preservation**: Archive important web content before it disappears, maintaining a complete web archive of your digital assets
- **Content Migration**: Download old website versions for content transfer to new platforms, preserving the original structure
- **Historical Research**: Access and analyze past versions of websites for research purposes, with full web archive support
- **SEO Analysis**: Study historical changes in website structure and content through wayback machine snapshots
- **Legal Compliance**: Maintain archives of web content for legal or regulatory requirements, with complete web archive extraction
- **Website Development**: Compare different versions of a website during development using wayback machine downloads
- **Content Auditing**: Review historical content changes and updates through internet archive snapshots
- **Brand Monitoring**: Track changes in brand presence and messaging over time with web archive tools
- **Competitive Analysis**: Archive competitor websites for historical comparison using wayback machine extractors

## Technical Requirements

- PHP 7.4+ with modern extensions
- cURL for HTTP requests
- DOM for HTML parsing
- JSON for API communication
- Write permissions for file operations

## Quick Start

1. Clone the repository:
   ```bash
   git clone https://github.com/yourusername/wayback-machine-downloader.git
   ```

2. Make the script executable:
   ```bash
   chmod +x run.php
   ```

3. Run the downloader:
   ```bash
   php run.php example.com 20240321
   ```

## Advanced Usage

The script supports various parameters for fine-tuning the download process:

```bash
php run.php <domain> <date YYYYMMDD> [debug_level] [skip_existing] [max_urls]
```

### Parameter Details

- `domain`: Target website domain (e.g., example.com)
- `date`: Archive date in YYYYMMDD format
- `debug_level`: 'error' for minimal output, 'info' for detailed logging
- `skip_existing`: 1 to skip downloaded files, 0 to download all
- `max_urls`: Maximum number of pages to process

### Usage Examples

Basic website archiving:
```bash
php run.php example.com 20240321
```

Archiving with error-only logging:
```bash
php run.php example.com 20240321 error
```

Incremental archiving (skip existing):
```bash
php run.php example.com 20240321 info 1
```

Large site archiving (100 pages):
```bash
php run.php example.com 20240321 info 0 100
```

## Output Organization

The script creates a structured archive in the `output` directory:

```
output/
└── example.com/
    ├── index.html          # Homepage
    ├── sitemap.txt         # Page index
    ├── missing.log         # Error tracking
    └── [resources]/        # Website assets
```

## Advanced Features

### Smart Browser Emulation
- Dynamic user agent rotation
- Realistic browser fingerprints
- Platform-specific headers
- Security policy emulation

### Intelligent Error Handling
- Detailed error logging
- HTTP status tracking
- Archive.org reference detection
- Empty response handling
- Automatic retry mechanism

### URL Processing
- Fragment removal
- Path normalization
- Query preservation
- Protocol handling
- Relative URL resolution

### Resource Management
- Directory structure preservation
- File existence verification
- MIME type detection
- Resource deduplication
- External link tracking

## Development and Debugging

### Logging System
```
=== Missing URLs for example.com (20240321) ===
Format: URL | Error | Timestamp
----------------------------------------
https://example.com/page.html | Connection timeout | 2024-03-21 15:30:45
```

### Console Feedback
- [...] Progress updates
- [✓] Success confirmations
- [×] Error notifications
- [↗] Information messages

## Best Practices

- Respect Wayback Machine's rate limits
- Monitor missing.log for failed downloads
- Use appropriate max_urls for large sites
- Enable skip_existing for incremental updates
- Check error logs for optimization opportunities

## Troubleshooting Guide

Common issues and solutions:
1. Check missing.log for detailed error information
2. Verify PHP version and extension availability
3. Confirm directory permissions
4. Test internet connectivity
5. Adjust retry parameters if needed

## License

This project is open source and available under the MIT License.

---

## Professional Web Development Services

Need help with web archiving, data extraction, or custom software development? Our team at SapientPro specializes in:

- Custom web scraping solutions
- Data extraction and processing
- Large-scale data collection
- API development and integration
- Data analysis and visualization

Visit our website for [Custom Software Development](https://sapient.pro/custom-software-development) Services. 