# Wayback Machine Downloader

A powerful PHP script to download and process archived websites from the Wayback Machine. This tool helps you create static versions of archived websites, perfect for preservation, offline access, or migration to modern hosting platforms.

## Features

- **Smart URL Processing**: Automatically transforms dynamic URLs into static file paths
- **Resource Handling**: Downloads and processes all linked resources (images, CSS, JavaScript)
- **Broken Link Management**: Intelligently handles broken links and missing resources
- **CloudFlare Integration**: Generates CloudFlare Functions for dynamic URL handling
- **SEO-Friendly Output**: Creates clean, static HTML files with proper URL structure
- **Query Parameter Support**: Handles URLs with query parameters by converting them to directory structures
- **Root Path Preservation**: Maintains proper handling of root paths and index files
- **External Link Management**: Adds rel="nofollow" to external links for SEO best practices
- **Static Site Generation**: Creates a complete static site ready for deployment

## Requirements

- PHP 7.4 or higher
- cURL extension
- DOM extension
- JSON extension

## Installation

1. Clone this repository:
```bash
git clone https://github.com/yourusername/wayback-machine-downloader.git
cd wayback-machine-downloader
```

2. Ensure PHP and required extensions are installed:
```bash
php -m | grep -E 'curl|dom|json'
```

## Usage

### Step 1: Download the Website

Use `run.php` to download the website from the Wayback Machine:

```bash
php run.php <domain> <date> [debug_level] [skip_existing] [max_urls] [skip_urls]
```

Parameters:
- `domain`: The domain to download (e.g., example.com)
- `date`: Target date in YYYYMMDD format
- `debug_level`: Show only errors (error) or all info (info, default)
- `skip_existing`: Skip URLs that already have files (1) or download all (0, default)
- `max_urls`: Maximum number of URLs to process (default: 50)
- `skip_urls`: Comma-separated list of URL patterns to skip (e.g., 'parking.php,/edit/')

Example:
```bash
php run.php example.com 20200101 info 0 100 "parking.php,/edit/"
```

### Step 2: Process the Downloaded Files

Use `process.php` to create a static version of the website:

```bash
php process.php <domain> [removeLinksByDomain]
```

Parameters:
- `domain`: The domain that was downloaded
- `removeLinksByDomain`: Optional comma-separated list of external domains whose links should be removed (converted to text)

Examples:
```bash
# Basic processing
php process.php example.com

# Remove links from specific domains
php process.php example.com "spbcompany.com,osdisc.com,affiliate.com"
```

This will:
- Process all downloaded files
- Create a static site structure
- Handle broken links and resources
- Generate CloudFlare Functions for dynamic URLs
- Create a `_redirects` file for URL mapping
- Add `rel="nofollow"` to external links (except those in removeLinksByDomain list)
- Remove links from specified domains and replace them with text content

## Output Structure

The processed website will be available in the `processed/<domain>` directory:

```
processed/
└── example.com/
    ├── public/           # Static files
    │   ├── index.html
    │   ├── _redirects    # URL mapping rules
    │   └── ...
    └── functions/        # CloudFlare Functions
        └── ...
```

## URL Transformation

The script handles various URL patterns:

- Dynamic URLs: `/page.php` → `/page/index.html`
- Query Parameters: `/page.php?id=123` → `/page/id_123/index.html`
- Root Path: `/` → `/index.html`
- Static Files: `/style.css` → `/style.css` (unchanged)

## CloudFlare Integration

For URLs with query parameters, the script generates CloudFlare Functions that:
- Handle dynamic URL patterns
- Maintain proper URL structure
- Support SEO-friendly URLs
- Preserve query parameter functionality

## SEO Optimization

The processed output is optimized for search engines:
- Clean URL structure
- Proper HTML semantics
- External link handling
- Resource optimization
- Mobile-friendly output

## Common Use Cases

- Website Preservation
- Content Migration
- Static Site Generation
- Archive Access
- Historical Research
- Content Recovery
- SEO Optimization
- CloudFlare Deployment

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## License

This project is licensed under the MIT License - see the LICENSE file for details.

## Keywords

wayback machine, web archive, static site generator, website preservation, content migration, archive access, historical research, content recovery, SEO optimization, CloudFlare deployment, PHP script, static site, URL transformation, broken link handling, resource management, query parameters, dynamic URLs, website backup, archive downloader, web preservation tool

---

## Professional Web Development Services

Need help with web archiving, data extraction, or custom software development? Our team at SapientPro specializes in:

- Custom web scraping solutions
- Data extraction and processing
- Large-scale data collection
- API development and integration
- Data analysis and visualization

Visit our website for [Custom Software Development](https://sapient.pro/custom-software-development) Services. 