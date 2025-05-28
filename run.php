<?php

/**
 * Wayback Machine Downloader
 * 
 * This script downloads archived versions of websites from the Wayback Machine
 * for a specific date and domain.
 */

if ($argc < 3) {
    exit("Usage: php run.php <domain> <date YYYYMMDD> [debug_level] [skip_existing] [max_urls] [skip_urls]\n" .
         "Parameters:\n" .
         "  debug_level   - Show only errors (error) or all info (info, default)\n" .
         "  skip_existing - Skip URLs that already have files (1) or download all (0, default)\n" .
         "  max_urls      - Maximum number of URLs to process (default: 50)\n" .
         "  skip_urls     - Comma-separated list of URL patterns to skip (e.g. 'parking.php,/edit/')\n");
}

// Configuration
$domain = $argv[1];
$date = $argv[2];
$debugLevel = $argv[3] ?? 'info';
$skipExisting = isset($argv[4]) ? (bool)$argv[4] : false;
$maxPages = isset($argv[5]) ? (int)$argv[5] : 50;
$skipUrls = isset($argv[6]) ? explode(',', $argv[6]) : [];
$outputDir = "output/{$domain}";
$missingLogFile = "output/{$domain}/missing.log";

// Initialize statistics
$stats = [
    'totalPages' => 0,
    'totalResources' => 0,
    'notFound' => 0,
    'foundViaFallback' => 0,
    'failedUrls' => [],
    'externalLinks' => [],
    'sitemap' => [],
    'visitedPages' => [],
    'skippedExisting' => 0,
    'totalUrlsFound' => 0,  // New counter for all URLs found
    'skippedUrls' => []     // New counter for skipped URLs
];

// Add this after the stats initialization
$failedUrlsCache = [];

// Create output directory if it doesn't exist
if (!is_dir($outputDir)) {
    ensureDirectoryExists($outputDir);
}

// Initialize missing.log with header
file_put_contents($missingLogFile, "=== Missing URLs for {$domain} ({$date}) ===\n" .
    "Format: URL | Error | Timestamp\n" .
    "----------------------------------------\n");

/**
 * Output formatting functions
 */
function color(string $text, string $code): string {
    return "\033[" . $code . "m" . $text . "\033[0m";
}

function progress(string $msg, string $level = 'info'): void {
    global $debugLevel;
    if ($debugLevel === 'error') return;
    echo color("[...]", "33") . " $msg\n";
}

function success(string $msg, string $level = 'info'): void {
    global $debugLevel;
    if ($debugLevel === 'error') return;
    echo color("[✓]", "32") . " $msg\n";
}

function warning(string $msg, string $level = 'error'): void {
    global $debugLevel;
    if ($debugLevel === 'error' && $level === 'info') return;
    echo color("[×]", "31") . " $msg\n";
}

function info(string $msg, string $level = 'info'): void {
    global $debugLevel;
    if ($debugLevel === 'error') return;
    echo color("[↗]", "36") . " $msg\n";
}

/**
 * Normalize URL by removing fragments and handling trailing slashes
 */
function normalizeUrl(string $url): string {
    // Remove fragment identifier
    $url = preg_replace('/#.*$/', '', $url);
    
    // Parse URL to handle paths properly
    $parsed = parse_url($url);
    if ($parsed === false) {
        return $url;
    }

    $scheme = $parsed['scheme'] ?? '';
    $host = $parsed['host'] ?? '';
    $path = $parsed['path'] ?? '';
    $query = isset($parsed['query']) ? '?' . $parsed['query'] : '';
    
    // Encode only specific characters in the path
    $path = preg_replace_callback('/[^A-Za-z0-9\-._~:\/\?#\[\]@!$&\'()*+,;=]/', function($matches) {
        return rawurlencode($matches[0]);
    }, $path);
    
    // Remove trailing slash from root path
    if ($path === '/') {
        $path = '';
    }
    // Remove trailing slash from other paths
    else if (substr($path, -1) === '/') {
        $path = rtrim($path, '/');
    }
    
    // Reconstruct URL
    $normalized = $scheme . '://' . $host . $path . $query;
    
    return $normalized;
}

/**
 * Normalize file path by resolving relative paths
 */
function normalizePath(string $path): string {
    // Remove any leading slashes
    $path = ltrim($path, '/');
    
    // Split path into segments
    $segments = explode('/', $path);
    $result = [];
    
    foreach ($segments as $segment) {
        if ($segment === '..') {
            // Remove the last segment if we're going up
            array_pop($result);
        } elseif ($segment !== '.' && $segment !== '') {
            // Add the segment if it's not current directory
            $result[] = $segment;
        }
    }
    
    return implode('/', $result);
}

/**
 * Transform URL to static HTML path
 */
function transformUrlToStaticPath(string $url): string {
    $parsed = parse_url($url);
    if ($parsed === false) {
        return $url;
    }

    $path = $parsed['path'] ?? '/';
    $query = isset($parsed['query']) ? $parsed['query'] : '';
    
    // Handle query parameters
    if (!empty($query)) {
        parse_str($query, $params);
        $paramPath = [];
        foreach ($params as $key => $value) {
            // Skip empty values
            if ($value === '' || $value === null) continue;
            // Clean parameter values to be filesystem-safe
            $cleanValue = preg_replace('/[^a-zA-Z0-9-]/', '_', $value);
            $paramPath[] = $key . '_' . $cleanValue;
        }
        if (!empty($paramPath)) {
            // Remove trailing slash if exists
            $path = rtrim($path, '/');
            // Add parameters as directory
            $path .= '/' . implode('_', $paramPath);
        }
    }
    
    // Remove trailing slash if exists
    $path = rtrim($path, '/');
    
    // Get file extension
    $extension = pathinfo($path, PATHINFO_EXTENSION);
    
    // List of dynamic file extensions that should be converted to index.html
    $dynamicExtensions = ['php', 'asp', 'aspx', 'jsp', 'cgi', 'pl', 'py', 'rb'];
    
    // Handle root path
    if ($path === '' || $path === '/') {
        $path = 'index.html';
    }
    // Handle dynamic files
    else if (in_array(strtolower($extension), $dynamicExtensions)) {
        $path = preg_replace('/\.' . preg_quote($extension, '/') . '$/', '/index.html', $path);
    }
    // Handle paths that don't have an extension
    else if (empty($extension)) {
        $path .= '/index.html';
    }
    // For static files (js, css, images, etc.), keep the original extension
    else {
        // No transformation needed, keep the original path with extension
    }
    
    // For absolute URLs, reconstruct with scheme and host
    if (isset($parsed['scheme']) && isset($parsed['host'])) {
        return $parsed['scheme'] . '://' . $parsed['host'] . $path;
    }
    
    // For relative URLs, just return the path
    return $path;
}

/**
 * Check if URL is a web archive URL
 */
function isWebArchiveUrl(string $url): bool {
    $patterns = [
        '/web\.archive\.org/',
        '/archive\.org/',
        '/web\/\d+/',  // Matches /web/20070819131312
        '/wayback\//',
        '/web\/\d+\*/' // Matches /web/20070819131312*
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $url)) {
            return true;
        }
    }
    return false;
}

// Fetch initial page list via CDX API
progress("Fetching page list via CDX API...");
$api = "https://web.archive.org/cdx/search/cdx?url={$domain}/*&matchType=prefix&from={$date}&to={$date}&output=json&fl=original&filter=statuscode:200&collapse=urlkey";
$json = @file_get_contents($api);
$entries = json_decode($json, true);

$pendingPages = [];
if (!$entries || count($entries) < 2) {
    warning("CDX returned no pages. Trying to load homepage...");
    echo "   [i] CDX API response: " . ($json ?: "No response") . "\n";

    // Try different homepage URL formats
    $homepageUrls = [
        "https://{$domain}/",
        "https://{$domain}",
        "http://{$domain}/",
        "http://{$domain}"
    ];

    $html = false;
    foreach ($homepageUrls as $mainUrl) {
        echo "   [i] Trying homepage URL: $mainUrl\n";
        $snapshotUrl = "https://web.archive.org/web/{$date}id_/{$mainUrl}";
        $html = @file_get_contents($snapshotUrl);
        
        if ($html !== false && !str_contains($html, "Wayback Machine doesn't have that page archived")) {
            success("Homepage loaded from: $mainUrl");
            $pendingPages[] = $mainUrl;
            break;
        }
    }

    if ($html === false) {
        exit("Homepage not found. Exiting.\n");
    }
} else {
    array_shift($entries);
    foreach ($entries as $entry) {
        $url = $entry[0];
        // Normalize the URL before adding to pending pages
        $normalizedUrl = normalizeUrl($url);
        if (!in_array($normalizedUrl, $pendingPages)) {
            $pendingPages[] = $normalizedUrl;
        }
    }
    success("Found " . count($pendingPages) . " pages via CDX API");
}

/**
 * Check if URL should be skipped based on patterns
 */
function shouldSkipUrl(string $url, array $skipPatterns): bool {
    foreach ($skipPatterns as $pattern) {
        if (strpos($url, $pattern) !== false) {
            return true;
        }
    }
    return false;
}

// Process pages
while (!empty($pendingPages) && $stats['totalPages'] < $maxPages) {
    $url = array_shift($pendingPages);
    $normalizedUrl = normalizeUrl($url);
    
    // Skip web archive URLs
    if (isWebArchiveUrl($normalizedUrl)) {
        warning("Skipped (Web Archive URL): $normalizedUrl", 'info');
        continue;
    }
    
    // Skip URLs matching skip patterns
    if (shouldSkipUrl($normalizedUrl, $skipUrls)) {
        warning("Skipped (Matches skip pattern): $normalizedUrl", 'info');
        $stats['skippedUrls'][] = $normalizedUrl;
        continue;
    }
    
    if (isset($stats['visitedPages'][$normalizedUrl])) {
        echo "   [i] Skipping already visited URL: $normalizedUrl\n";
        continue;
    }
    
    if ($stats['totalPages'] >= $maxPages) {
        warning("Reached limit of " . $maxPages . " links");
        break;
    }

    // Get the static path for checking existence and saving
    $staticPath = transformUrlToStaticPath($normalizedUrl);
    $parsedUrl = parse_url($staticPath);
    $path = $parsedUrl['path'] ?? '/';
    $normalizedPath = normalizePath($path);
    
    // For root path, use index.html
    if ($normalizedPath === '') {
        $normalizedPath = 'index.html';
    }
    
    $savePath = $outputDir . '/' . $normalizedPath;
    $storedHtml = false;
    
    if ($skipExisting && file_exists($savePath) && filesize($savePath) > 0) {
        info("Skipping existing file: $normalizedPath", 'info');
        $stats['skippedExisting']++;
        $stats['visitedPages'][$normalizedUrl] = true;
        $currentUrlId = $stats['totalPages'] + $stats['skippedExisting'];
        $totalUrls = max($stats['totalUrlsFound'], count($pendingPages) + $stats['totalPages'] + $stats['skippedExisting']);
        echo "\n==> [{$currentUrlId} / {$totalUrls}] Skipping existing: $normalizedUrl\n";
        
        // Parse the existing file to find new links
        $storedHtml = file_get_contents($savePath);
        if ($storedHtml !== false) {
            info("Parsing existing file for links: $normalizedPath", 'info');
            $processedHtml = processHtml($storedHtml, $normalizedUrl, $date, $outputDir, $domain, $stats);
            // No need to resave the processed HTML if it came from a file
        } else {
            echo "\n can't load file: $savePath";
        }
        continue;
    }

    $stats['visitedPages'][$normalizedUrl] = true;
    $stats['totalPages']++;
    $currentUrlId = $stats['totalPages'] + $stats['skippedExisting'];
    $totalUrls = max($stats['totalUrlsFound'], count($pendingPages) + $stats['totalPages'] + $stats['skippedExisting']);
    echo "\n==> [{$currentUrlId} / {$totalUrls}] $normalizedUrl\n";

    // Try direct Wayback Machine URL first
    $snapshotUrl = "https://web.archive.org/web/{$date}id_/{$normalizedUrl}";
    echo "   [↻] Trying direct snapshot: $snapshotUrl\n";
    
    // Use cURL to handle redirects properly
    $maxRetries = 6;
    $retryCount = 0;
    $sleepTimes = [1, 1, 3, 15, 30, 60]; // Sleep times in seconds for each retry
    
    do {
        if ($retryCount > 0) {
            $sleepTime = $sleepTimes[min($retryCount - 1, count($sleepTimes) - 1)];
            echo "   [i] Retry attempt {$retryCount} after {$sleepTime}s sleep...\n";
            sleep($sleepTime);
        }
        
        $ch = curl_init($snapshotUrl);
        $userAgent = getRandomUserAgent();
        $fingerprint = getRandomFingerprint();
        
        $curlOptions = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => $userAgent,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_AUTOREFERER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_HTTPHEADER => [
                "Accept: {$fingerprint['accept']}",
                "Accept-Language: {$fingerprint['accept_language']}",
                "Accept-Encoding: {$fingerprint['accept_encoding']}",
                "Sec-Ch-Ua: {$fingerprint['sec_ch_ua']}",
                "Sec-Ch-Ua-Mobile: {$fingerprint['sec_ch_ua_mobile']}",
                "Sec-Ch-Ua-Platform: {$fingerprint['sec_ch_ua_platform']}",
                "Sec-Fetch-Dest: {$fingerprint['sec_fetch_dest']}",
                "Sec-Fetch-Mode: {$fingerprint['sec_fetch_mode']}",
                "Sec-Fetch-Site: {$fingerprint['sec_fetch_site']}",
                "Sec-Fetch-User: {$fingerprint['sec_fetch_user']}",
                "Upgrade-Insecure-Requests: {$fingerprint['upgrade_insecure_requests']}",
                "DNT: {$fingerprint['dnt']}"
            ]
        ];
        $verbose = null;
        if ($debugLevel === 'info') {
            $curlOptions[CURLOPT_VERBOSE] = true;
            $curlOptions[CURLOPT_STDERR] = $verbose = fopen('php://temp', 'w+');
        }
        curl_setopt_array($ch, $curlOptions);
        
        $response = curl_exec($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $redirectCount = curl_getinfo($ch, CURLINFO_REDIRECT_COUNT);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        
        // Get verbose debug information only if debug level is 'info'
        if ($debugLevel === 'info') {
            rewind($verbose);
            $verboseLog = stream_get_contents($verbose);
        }
        
        $html = substr($response, $headerSize);
        
        curl_close($ch);

        if ($error) {
            warning("cURL error ($errno): $error", 'error');
            if ($debugLevel === 'info') {
                echo "   [i] Verbose log:\n";
                foreach (explode("\n", $verboseLog) as $line) {
                    if (trim($line)) {
                        echo "      " . trim($line) . "\n";
                    }
                }
            }
            
            // Only retry on connection errors
            $shouldRetry = in_array($errno, [CURLE_COULDNT_CONNECT, CURLE_OPERATION_TIMEDOUT, CURLE_COULDNT_RESOLVE_HOST]);
            if (!$shouldRetry) {
                break;
            }
        } else {
            break; // Success, exit retry loop
        }
        
        $retryCount++;
    } while ($retryCount < $maxRetries);

    if ($retryCount >= $maxRetries) {
        warning("Failed after {$maxRetries} retry attempts", 'error');
    }

    if ($redirectCount > 0 && $debugLevel === 'info') {
        echo "   [i] Followed {$redirectCount} redirect(s)\n";
        echo "   [i] Final URL: $effectiveUrl\n";
        if ($status === 302) {
            echo "   [i] Redirect reason: " . (preg_match('/x-archive-redirect-reason: (.*)/i', $headers, $matches) ? $matches[1] : 'unknown') . "\n";
        }
    }

    // Check if we got a valid response
    if (empty($html)) {
        warning("Failed to load HTML: $normalizedUrl", 'error');
        $stats['notFound']++;
        $stats['failedUrls'][] = $normalizedUrl;
        
        // Log to missing.log
        $error = $error ? "cURL error ($errno): $error" : "Empty response";
        $logEntry = sprintf("%s | %s | %s\n", 
            $normalizedUrl,
            $error,
            date('Y-m-d H:i:s')
        );
        file_put_contents($missingLogFile, $logEntry);
        
        continue;
    }

    // Save the page using the static path
    $saveDir = dirname($savePath);
    ensureDirectoryExists($saveDir);

    $html = processHtml($html, $normalizedUrl, $date, $outputDir, $domain, $stats);
    
    // Check for archive.org references before saving
    if (containsArchiveReferences($html)) {
        warning("Archive.org references found in content: $normalizedUrl", 'error');
        
        // Try to find a snapshot using the available API
        $usedDate = null;
        $data = tryAvailableApiSnapshot($normalizedUrl, $usedDate);
        if ($data !== false) {
            $html = $data;
            success("Found snapshot via available API: $normalizedUrl");
            
            // Get the static path for saving
            $staticPath = transformUrlToStaticPath($normalizedUrl);
            $parsedUrl = parse_url($staticPath);
            $path = $parsedUrl['path'] ?? '/';
            $normalizedPath = normalizePath($path);
            
            // For root path, use index.html
            if ($normalizedPath === '') {
                $normalizedPath = 'index.html';
            }
            
            $savePath = $outputDir . '/' . $normalizedPath;
            $saveDir = dirname($savePath);
            ensureDirectoryExists($saveDir);
            
            // Process the HTML before saving
            $html = processHtml($html, $normalizedUrl, $date, $outputDir, $domain, $stats);
            
            // Save the processed HTML
            file_put_contents($savePath, $html);
            success("Saved: $normalizedPath");
        } else {
            $logEntry = sprintf("%s | Contains archive.org references | %s\n", 
                $normalizedUrl,
                date('Y-m-d H:i:s')
            );
            file_put_contents($missingLogFile, $logEntry, FILE_APPEND);
            $stats['notFound']++;
            $stats['failedUrls'][] = $normalizedUrl;
            continue;
        }
    }

    file_put_contents($savePath, $html);
    success("Saved: $normalizedPath");
}

// Save sitemap
file_put_contents("$outputDir/sitemap.txt", implode("\n", $stats['sitemap']));


echo "\n[ℹ] Failed URLs have been logged to: $missingLogFile\n";

if (!empty($stats['failedUrls'])) {
    echo "\n[!] Could not download these URLs:\n";
    foreach ($stats['failedUrls'] as $furl) {
        echo " - $furl\n";
    }
}

if (!empty($stats['externalLinks'])) {
    echo "\n[ℹ] Skipped external links:\n";
    foreach ($stats['externalLinks'] as $ext) {
        echo " - $ext\n";
    }
}

if (!empty($stats['skippedUrls'])) {
    echo "\n[ℹ] Skipped URLs (matching patterns):\n";
    foreach ($stats['skippedUrls'] as $surl) {
        echo " - $surl\n";
    }
}

// Print summary
echo "\n=== SUMMARY ===\n";
echo "• Total pages processed:        {$stats['totalPages']}\n";
echo "• Total resources downloaded:   {$stats['totalResources']}\n";
echo "• Found via fallback:          {$stats['foundViaFallback']}\n";
echo "• Failed to download:          {$stats['notFound']}\n";
if ($skipExisting) {
    echo "• Skipped existing files:      {$stats['skippedExisting']}\n";
}

/**
 * Process HTML content and extract resources
 */
function processHtml(string $html, string $baseUrl, string $date, string $outputDir, string $mainDomain, array &$stats): string {
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    $stats['sitemap'][] = normalizeUrl($baseUrl);

    // Process video elements and their resources
    $videos = $xpath->query("//video");
    foreach ($videos as $video) {
        // Process video source elements
        $sources = $xpath->query(".//source", $video);
        foreach ($sources as $source) {
            $src = $source->getAttribute('src');
            if (empty($src)) continue;

            // Handle protocol-relative URLs
            if (strpos($src, '//') === 0) {
                $src = 'https:' . $src;
            }

            $resolved = resolveUrl($baseUrl, $src);
            $normalizedResolved = normalizeUrl($resolved);
            
            // Skip empty or invalid URLs
            if (empty($normalizedResolved) || !filter_var($normalizedResolved, FILTER_VALIDATE_URL)) {
                continue;
            }

            $parsed = parse_url($normalizedResolved);
            $host = $parsed['host'] ?? '';

            if ($host && $host !== $mainDomain) {
                info("External video: $normalizedResolved", 'info');
                $stats['externalLinks'][] = $normalizedResolved;
                continue;
            }

            // Add to pending pages instead of downloading
            if (!isset($stats['visitedPages'][$normalizedResolved]) && !in_array($normalizedResolved, $GLOBALS['pendingPages'])) {
                $GLOBALS['pendingPages'][] = $normalizedResolved;
            }
        }

        // Process track elements (subtitles)
        $tracks = $xpath->query(".//track", $video);
        foreach ($tracks as $track) {
            $src = $track->getAttribute('src');
            if (empty($src)) continue;

            // Handle protocol-relative URLs
            if (strpos($src, '//') === 0) {
                $src = 'https:' . $src;
            }

            $resolved = resolveUrl($baseUrl, $src);
            $normalizedResolved = normalizeUrl($resolved);
            
            // Skip empty or invalid URLs
            if (empty($normalizedResolved) || !filter_var($normalizedResolved, FILTER_VALIDATE_URL)) {
                continue;
            }

            $parsed = parse_url($normalizedResolved);
            $host = $parsed['host'] ?? '';

            if ($host && $host !== $mainDomain) {
                info("External subtitle: $normalizedResolved", 'info');
                $stats['externalLinks'][] = $normalizedResolved;
                continue;
            }

            // Add to pending pages instead of downloading
            if (!isset($stats['visitedPages'][$normalizedResolved]) && !in_array($normalizedResolved, $GLOBALS['pendingPages'])) {
                $GLOBALS['pendingPages'][] = $normalizedResolved;
            }
        }
    }

    // Process CSS files first
    $cssLinks = $xpath->query("//link[@rel='stylesheet' and @href]");
    foreach ($cssLinks as $link) {
        $href = $link->getAttribute('href');
        if (empty($href)) continue;

        // Handle protocol-relative URLs
        if (strpos($href, '//') === 0) {
            $href = 'https:' . $href;
        }

        $resolved = resolveUrl($baseUrl, $href);
        $normalizedResolved = normalizeUrl($resolved);
        
        // Skip empty or invalid URLs
        if (empty($normalizedResolved) || !filter_var($normalizedResolved, FILTER_VALIDATE_URL)) {
            continue;
        }

        $parsed = parse_url($normalizedResolved);
        $host = $parsed['host'] ?? '';

        if ($host && $host !== $mainDomain) {
            info("External CSS: $normalizedResolved", 'info');
            $stats['externalLinks'][] = $normalizedResolved;
            continue;
        }

        // Add to pending pages instead of downloading
        if (!isset($stats['visitedPages'][$normalizedResolved]) && !in_array($normalizedResolved, $GLOBALS['pendingPages'])) {
            $GLOBALS['pendingPages'][] = $normalizedResolved;
        }
    }

    // Process inline styles (for resource extraction only)
    $styleNodes = $xpath->query("//style");
    foreach ($styleNodes as $style) {
        $css = $style->textContent;
        // Only extract resources, do not rewrite
        processCss($css, $baseUrl, $date, $outputDir, $mainDomain, $stats);
    }

    $tags = [
        ['tag' => 'link',   'attr' => 'href'],
        ['tag' => 'script', 'attr' => 'src'],
        ['tag' => 'img',    'attr' => 'src'],
        ['tag' => 'a',      'attr' => 'href'],
    ];

    foreach ($tags as $tagInfo) {
        $nodes = $xpath->query("//{$tagInfo['tag']}[@{$tagInfo['attr']}]");

        foreach ($nodes as $node) {
            $attr = $tagInfo['attr'];
            $rawAttr = $node->getAttribute($attr);
            if (!$rawAttr) continue;

            // Handle protocol-relative URLs
            if (strpos($rawAttr, '//') === 0) {
                $rawAttr = 'https:' . $rawAttr;
            }

            // Extract original URL from Wayback Machine URL
            if (preg_match('#/web/\d+[a-z_]{0,3}/(https?://[^"\'>]+)#', $rawAttr, $m)) {
                $rawAttr = $m[1];
            }

            if (str_contains($rawAttr, '<') || str_contains($rawAttr, '</')) {
                warning("Skipped (HTML in URL): $rawAttr", 'info');
                continue;
            }

            $resolved = resolveUrl($baseUrl, $rawAttr);
            $normalizedResolved = normalizeUrl($resolved);
            
            // Skip empty or invalid URLs
            if (empty($normalizedResolved) || !filter_var($normalizedResolved, FILTER_VALIDATE_URL)) {
                warning("Invalid URL: $rawAttr", 'error');
                continue;
            }

            // Skip web archive URLs
            if (isWebArchiveUrl($normalizedResolved)) {
                warning("Skipped (Web Archive URL): $normalizedResolved", 'info');
                continue;
            }

            // Skip URLs matching skip patterns
            if (shouldSkipUrl($normalizedResolved, $GLOBALS['skipUrls'])) {
                warning("Skipped (Matches skip pattern): $normalizedResolved", 'info');
                $stats['skippedUrls'][] = $normalizedResolved;
                continue;
            }

            $parsed = parse_url($normalizedResolved);
            $host = $parsed['host'] ?? '';

            if ($host && $host !== $mainDomain) {
                info("External link: $normalizedResolved", 'info');
                $stats['externalLinks'][] = $normalizedResolved;
                continue;
            }

            // Show success for valid internal URLs
            success("Parsed URL: $normalizedResolved", 'info');

            if ($tagInfo['tag'] === 'a' && $stats['totalPages'] < $GLOBALS['maxPages']) {
                if (!isset($stats['visitedPages'][$normalizedResolved]) && !in_array($normalizedResolved, $GLOBALS['pendingPages'])) {
                    $GLOBALS['pendingPages'][] = $normalizedResolved;
                }
            }

            // Add to pending pages instead of downloading
            if (in_array($tagInfo['tag'], ['link', 'script', 'img'])) {
                if (!isset($stats['visitedPages'][$normalizedResolved]) && !in_array($normalizedResolved, $GLOBALS['pendingPages'])) {
                    $GLOBALS['pendingPages'][] = $normalizedResolved;
                }
            }
        }
    }

    // Return the HTML as-is (no rewriting)
    return $html;
}

/**
 * Process CSS content and extract resources
 */
function processCss(string $css, string $baseUrl, string $date, string $outputDir, string $mainDomain, array &$stats): string {
    // Extract URLs from CSS
    $patterns = [
        // url() patterns
        '/url\([\'"]?([^\'")\s]+)[\'"]?\)/i',
        // @import patterns
        '/@import\s+[\'"]?([^\'"\s;]+)[\'"]?/i',
        // background-image patterns
        '/background-image:\s*url\([\'"]?([^\'")\s]+)[\'"]?\)/i',
        // src patterns in @font-face
        '/@font-face\s*{[^}]*src:\s*url\([\'"]?([^\'")\s]+)[\'"]?\)/i'
    ];

    foreach ($patterns as $pattern) {
        preg_match_all($pattern, $css, $matches);
        if (!empty($matches[1])) {
            foreach ($matches[1] as $url) {
                // Skip data URLs
                if (strpos($url, 'data:') === 0) continue;

                // Handle protocol-relative URLs
                if (strpos($url, '//') === 0) {
                    $url = 'https:' . $url;
                }

                $resolved = resolveUrl($baseUrl, $url);
                $normalizedResolved = normalizeUrl($resolved);
                
                // Skip empty or invalid URLs
                if (empty($normalizedResolved) || !filter_var($normalizedResolved, FILTER_VALIDATE_URL)) {
                    continue;
                }

                $parsed = parse_url($normalizedResolved);
                $host = $parsed['host'] ?? '';
                $path = $parsed['path'] ?? '/';

                if ($host && $host !== $mainDomain) {
                    info("External resource in CSS: $normalizedResolved", 'info');
                    $stats['externalLinks'][] = $normalizedResolved;
                    continue;
                }

                // Determine file extension from URL or content type
                $extension = pathinfo($path, PATHINFO_EXTENSION);
                if (empty($extension)) {
                    // Try to determine extension from content type
                    $mime = '';
                    $data = fetchResource($normalizedResolved, $mime);
                    if ($data !== false) {
                        $extension = getExtensionFromMime($mime);
                    }
                }

                if (substr($path, -1) === '/') $path .= 'index.html';
                $normalizedPath = normalizePath($path);
                if (empty($extension) && !str_ends_with($normalizedPath, '.html')) {
                    $normalizedPath .= '.css';
                }
                $localPath = $outputDir . '/' . $normalizedPath;
                $localDir = dirname($localPath);
                ensureDirectoryExists($localDir);

                if (!file_exists($localPath)) {
                    progress("Downloading CSS resource: $normalizedResolved");
                    $usedDate = '';
                    $data = fetchResourceWithFallback($normalizedResolved, $date, $mainDomain, $usedDate);
                    if ($data !== false) {
                        file_put_contents($localPath, $data);
                        success("CSS resource saved: $path");
                        $stats['totalResources']++;
                        if ($usedDate !== $date) $stats['foundViaFallback']++;
                    } else {
                        warning("CSS resource failed: $path", 'error');
                        $stats['notFound']++;
                        $stats['failedUrls'][] = $normalizedResolved;
                    }
                }

                // Replace the URL in CSS with local path
                $relativePath = substr($normalizedPath, strlen($outputDir) + 1);
                $css = str_replace($url, $relativePath, $css);
            }
        }
    }

    return $css;
}

/**
 * Get file extension from MIME type
 */
function getExtensionFromMime(string $mime): string {
    $mimeMap = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/svg+xml' => 'svg',
        'image/x-icon' => 'ico',
        'font/ttf' => 'ttf',
        'font/otf' => 'otf',
        'font/woff' => 'woff',
        'font/woff2' => 'woff2',
        'application/x-font-ttf' => 'ttf',
        'application/x-font-otf' => 'otf',
        'application/font-woff' => 'woff',
        'application/font-woff2' => 'woff2',
        'text/css' => 'css'
    ];

    return $mimeMap[$mime] ?? '';
}

/**
 * Fetch a single resource with detailed error logging
 */
function fetchResource(string $url, string &$mimeType = ''): string|false {
    global $debugLevel;
    $ch = curl_init($url);
    $userAgent = getRandomUserAgent();
    $fingerprint = getRandomFingerprint();
    $curlOptions = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_USERAGENT => $userAgent,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_ENCODING => '', // Accept all encodings
        CURLOPT_HTTPHEADER => [
            "Accept: */*", // Accept all content types
            "Accept-Language: {$fingerprint['accept_language']}",
            "Accept-Encoding: {$fingerprint['accept_encoding']}",
            "Sec-Ch-Ua: {$fingerprint['sec_ch_ua']}",
            "Sec-Ch-Ua-Mobile: {$fingerprint['sec_ch_ua_mobile']}",
            "Sec-Ch-Ua-Platform: {$fingerprint['sec_ch_ua_platform']}",
            "Sec-Fetch-Dest: {$fingerprint['sec_fetch_dest']}",
            "Sec-Fetch-Mode: {$fingerprint['sec_fetch_mode']}",
            "Sec-Fetch-Site: {$fingerprint['sec_fetch_site']}",
            "Sec-Fetch-User: {$fingerprint['sec_fetch_user']}",
            "Upgrade-Insecure-Requests: {$fingerprint['upgrade_insecure_requests']}",
            "DNT: {$fingerprint['dnt']}"
        ]
    ];
    $verbose = null;
    if ($debugLevel === 'info') {
        $curlOptions[CURLOPT_VERBOSE] = true;
        $curlOptions[CURLOPT_STDERR] = $verbose = fopen('php://temp', 'w+');
    }
    curl_setopt_array($ch, $curlOptions);

    $response = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $mimeType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $body = substr($response, $headerSize);
    $headers = substr($response, 0, $headerSize);
    
    $error = curl_error($ch);
    $errno = curl_errno($ch);
    $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $redirectCount = curl_getinfo($ch, CURLINFO_REDIRECT_COUNT);
    $totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
    
    if ($debugLevel === 'info' && $verbose) {
        rewind($verbose);
        $verboseLog = stream_get_contents($verbose);
    } else {
        $verboseLog = '';
    }
    curl_close($ch);

    // Log detailed error information only if debugLevel is info
    if ($status !== 200 || $error) {
        if ($debugLevel === 'info') {
            echo "   [×] Download failed for: $url\n";
            echo "       Effective URL: $effectiveUrl\n";
            echo "       Status code: $status\n";
            echo "       Total time: {$totalTime}s\n";
            echo "       Redirect count: $redirectCount\n";
            if ($error) {
                echo "       cURL error ($errno): $error\n";
            }
            echo "       Response headers:\n";
            foreach (explode("\n", $headers) as $header) {
                if (trim($header)) {
                    echo "         " . trim($header) . "\n";
                }
            }
            // Show first 500 characters of response body for debugging
            if (strlen($body) > 0) {
                echo "       Response body preview:\n";
                $preview = substr($body, 0, 500);
                $preview = str_replace(["\r", "\n"], [' ', ' '], $preview);
                echo "         " . $preview . "...\n";
            }
            if ($verboseLog) {
                echo "       Verbose log:\n";
                foreach (explode("\n", $verboseLog) as $line) {
                    if (trim($line)) {
                        echo "         " . trim($line) . "\n";
                    }
                }
            }
        } else {
            // Minimal error output for error mode
            echo "   [×] Download failed for: $url\n";
        }
        return false;
    }

    // Check if the response is HTML and contains error message
    if (str_starts_with($mimeType, 'text/html')) {
        $errorPatterns = [
            "Wayback Machine doesn't have that page archived",
            "This page is not available",
            "Page cannot be crawled or displayed",
            "404 Not Found",
            "Not Found",
            "The requested URL was not found",
            "The page you're looking for doesn't exist",
            "The page you requested could not be found",
            "The requested resource was not found",
            "The page you are looking for might have been removed",
            "The page you are looking for might have been deleted",
            "The page you are looking for might have been moved",
            "The page you are looking for might have been renamed",
            "The page you are looking for might have been archived",
            "The page you are looking for might have been changed",
            "The page you are looking for might have been updated",
            "The page you are looking for might have been replaced",
            "The page you are looking for might have been redirected",
            "The page you are looking for might have been relocated",
            "The page you are looking for might have been transferred"
        ];

        foreach ($errorPatterns as $pattern) {
            if (stripos($body, $pattern) !== false) {
                if ($debugLevel === 'info') {
                    echo "   [×] Error page detected: $pattern\n";
                }
                return false;
            }
        }
    }

    // Return the body for all content types
    return $body;
}

/**
 * Fetch resource with fallback to previous snapshots
 */
function fetchResourceWithFallback(string $url, string $date, string $mainDomain, ?string &$usedDate = null): string|false {
    global $failedUrlsCache;

    // Check if URL is in failed cache
    if (isset($failedUrlsCache[$url])) {
        echo "   [i] Skipping known failed URL: $url\n";
        return false;
    }

    $mime = '';
    $snapshotUrl = "https://web.archive.org/web/{$date}id_/{$url}";
    echo "   [i] Trying snapshot: $snapshotUrl\n";
    
    // First attempt with the original date
    $data = fetchResource($snapshotUrl, $mime);
    if ($data !== false) {
        $usedDate = $date;
        return $data;
    }

    // Try other formats for the original date
    $formats = ['if_', 'im_'];
    foreach ($formats as $format) {
        $snapshotUrl = "https://web.archive.org/web/{$date}{$format}/{$url}";
        echo "   [i] Trying {$format} format: $snapshotUrl\n";
        $data = fetchResource($snapshotUrl, $mime);
        if ($data !== false) {
            $usedDate = $date;
            return $data;
        }
    }

    // Try archive.org/wayback/available API
    $encoded = urlencode($url);
    $availableApi = "https://archive.org/wayback/available?url={$encoded}";
    echo "   [i] Checking available API: $availableApi\n";
    $json = @file_get_contents($availableApi);
    $result = json_decode($json, true);
    
    if ($result && isset($result['archived_snapshots']['closest']['available']) && $result['archived_snapshots']['closest']['available']) {
        $closest = $result['archived_snapshots']['closest'];
        $snapshotUrl = $closest['url'];
        $snapshotDate = $closest['timestamp'];
        echo "   [i] Found available snapshot from {$snapshotDate}: $snapshotUrl\n";
        
        $data = fetchResource($snapshotUrl, $mime);
        if ($data !== false) {
            $usedDate = $snapshotDate;
            return $data;
        }
    }

    // Try CDX API to find available snapshots
    $encoded = urlencode($url);
    $api = "https://web.archive.org/cdx/search/cdx?url={$encoded}&output=json&fl=timestamp,original&filter=statuscode:200&collapse=digest&to={$date}&limit=20&sort=reverse";
    $json = @file_get_contents($api);
    $entries = json_decode($json, true);
    
    if (!$entries || count($entries) < 2) {
        // Try CDX API with a broader search
        $api = "https://web.archive.org/cdx/search/cdx?url={$encoded}&output=json&fl=timestamp,original&filter=statuscode:200&collapse=digest&limit=20&sort=reverse";
        $json = @file_get_contents($api);
        $entries = json_decode($json, true);
        
        if (!$entries || count($entries) < 2) {
            // Try CDX API with a more permissive filter
            $api = "https://web.archive.org/cdx/search/cdx?url={$encoded}&output=json&fl=timestamp,original&collapse=digest&limit=20&sort=reverse";
            $json = @file_get_contents($api);
            $entries = json_decode($json, true);
            
            if (!$entries || count($entries) < 2) {
                // Try CDX API with a broader URL pattern
                $baseUrl = preg_replace('/\/[^\/]*$/', '', $url);
                $api = "https://web.archive.org/cdx/search/cdx?url={$baseUrl}/*&output=json&fl=timestamp,original&filter=statuscode:200&collapse=digest&limit=20&sort=reverse";
                $json = @file_get_contents($api);
                $entries = json_decode($json, true);
                
                if (!$entries || count($entries) < 2) {
                    echo "   [×] CDX API returned no results\n";
                    $failedUrlsCache[$url] = true;
                    return false;
                }
            }
        }
    }

    array_shift($entries); // Remove header row
    foreach ($entries as $entry) {
        $prevDate = $entry[0];
        $originalUrl = $entry[1] ?? $url; // Use original URL from CDX if available
        
        // Try all formats for each timestamp
        $formats = ['id_', 'if_', 'im_'];
        foreach ($formats as $format) {
            $prevUrl = "https://web.archive.org/web/{$prevDate}{$format}/{$originalUrl}";
            echo "   [i] Trying fallback snapshot: $prevUrl\n";
            $mime = '';
            $data = fetchResource($prevUrl, $mime);
            if ($data !== false) {
                $usedDate = $prevDate;
                return $data;
            }

            // If we got a 404, try next format
            if (strpos($mime, '404') !== false || strpos($mime, 'Not Found') !== false) {
                continue;
            }
        }
    }

    // If we've tried all fallback dates and still failed, add to cache
    $failedUrlsCache[$url] = true;
    return false;
}

/**
 * Resolve relative URL to absolute URL
 */
function resolveUrl(string $base, string $rel): string {
    if (parse_url($rel, PHP_URL_SCHEME) != '') return $rel;
    if ($rel[0] == '#' || $rel[0] == '?') return $base . $rel;

    $baseParts = parse_url($base);
    $scheme = $baseParts['scheme'] ?? 'http';
    $host = $baseParts['host'] ?? '';
    $port = isset($baseParts['port']) ? ':' . $baseParts['port'] : '';
    $path = isset($baseParts['path']) ? preg_replace('#/[^/]*$#', '', $baseParts['path']) : '';

    if ($rel[0] == '/') $path = '';
    $abs = "$path/$rel";
    $abs = preg_replace('/\/+/', '/', $abs);
    return "$scheme://$host$port$abs";
}

/**
 * Check if content contains archive.org references
 */
function containsArchiveReferences(string $content): bool {
    $patterns = [
        '/web\.archive\.org/',
        '/archive\.org/',
        '/wayback machine/i',
        '/wayback machine/i',
        '/snapshot of/i',
        '/archived from/i',
        '/archived on/i',
        '/archived at/i',
        '/archived by/i',
        '/archived with/i',
        '/archived page/i',
        '/archived version/i',
        '/archived copy/i',
        '/archived content/i',
        '/archived site/i',
        '/archived website/i',
        '/archived web page/i',
        '/archived web site/i',
        '/archived web content/i',
        '/archived web version/i',
        '/archived web copy/i',
        '/archived web snapshot/i',
        '/archived web archive/i',
        '/archived web content/i',
        '/archived web page/i',
        '/archived web site/i',
        '/archived web version/i',
        '/archived web copy/i',
        '/archived web snapshot/i',
        '/archived web archive/i'
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $content)) {
            return true;
        }
    }
    return false;
}

/**
 * Generate a random user agent string
 */
function getRandomUserAgent(): string {
    $chromeVersions = ['90.0.4430.212', '91.0.4472.124', '92.0.4515.159', '93.0.4577.82', '94.0.4606.81', '95.0.4638.69', '96.0.4664.110', '97.0.4692.98', '98.0.4758.102', '99.0.4844.84', '100.0.4896.127', '101.0.4951.67', '102.0.5005.115', '103.0.5060.134', '104.0.5112.102', '105.0.5195.127', '106.0.5249.119', '107.0.5304.121', '108.0.5359.98', '109.0.5414.119', '110.0.5481.177', '111.0.5563.146', '112.0.5615.49', '113.0.5672.63', '114.0.5735.199', '115.0.5790.170', '116.0.5845.96', '117.0.5938.89', '118.0.5993.88', '119.0.6045.105', '120.0.6099.109', '121.0.6167.85', '122.0.6261.69', '123.0.6312.58'];
    $osVersions = [
        'Windows NT 10.0; Win64; x64',
        'Windows NT 10.0; WOW64',
        'Macintosh; Intel Mac OS X 10_15_7',
        'Macintosh; Intel Mac OS X 11_0_1',
        'Macintosh; Intel Mac OS X 12_0_1',
        'X11; Linux x86_64',
        'X11; Ubuntu; Linux x86_64'
    ];
    
    $chromeVersion = $chromeVersions[array_rand($chromeVersions)];
    $osVersion = $osVersions[array_rand($osVersions)];
    
    return "Mozilla/5.0 ({$osVersion}) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/{$chromeVersion} Safari/537.36";
}

/**
 * Generate random browser fingerprint parameters
 */
function getRandomFingerprint(): array {
    return [
        'accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
        'accept_language' => 'en-US,en;q=0.9',
        'accept_encoding' => 'gzip, deflate, br',
        'sec_ch_ua' => '"Not A(Brand";v="99", "Google Chrome";v="' . rand(90, 123) . '", "Chromium";v="' . rand(90, 123) . '"',
        'sec_ch_ua_mobile' => '?0',
        'sec_ch_ua_platform' => '"' . (rand(0, 1) ? 'Windows' : 'macOS') . '"',
        'sec_fetch_dest' => 'document',
        'sec_fetch_mode' => 'navigate',
        'sec_fetch_site' => 'none',
        'sec_fetch_user' => '?1',
        'upgrade_insecure_requests' => '1',
        'dnt' => rand(0, 1) ? '1' : '0'
    ];
}

// Replace all instances of mkdir with a safer version
function ensureDirectoryExists(string $dir): void {
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
}

/**
 * Try to find a snapshot using the available API
 */
function tryAvailableApiSnapshot(string $url, string &$usedDate = null): string|false {
    static $checkedUrls = [];
    
    // Prevent infinite loops by tracking checked URLs
    if (isset($checkedUrls[$url])) {
        return false;
    }
    $checkedUrls[$url] = true;
    
    $encoded = urlencode($url);
    $availableApi = "https://archive.org/wayback/available?url={$encoded}";
    echo "   [i] Checking available API: $availableApi\n";
    
    $json = @file_get_contents($availableApi);
    $result = json_decode($json, true);
    
    if ($result && isset($result['archived_snapshots']['closest']['available']) && $result['archived_snapshots']['closest']['available']) {
        $closest = $result['archived_snapshots']['closest'];
        $snapshotDate = $closest['timestamp'];
        echo "   [i] Found available snapshot from {$snapshotDate}\n";
        
        // Construct the correct URL format
        $snapshotUrl = "https://web.archive.org/web/{$snapshotDate}id_/{$url}";
        echo "   [i] Using snapshot URL: $snapshotUrl\n";
        
        $mime = '';
        $data = fetchResource($snapshotUrl, $mime);
        if ($data !== false) {
            $usedDate = $snapshotDate;
            return $data;
        }
    } else {
        echo "   [i] Not Found available snapshot from " . $json . "\n";
    }
    
    return false;
}
