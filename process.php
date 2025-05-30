<?php

/**
 * Wayback Machine Downloader - File Processor
 * 
 * This script processes downloaded files to:
 * 1. Add rel="nofollow" to external links
 * 2. Remove broken images
 * 3. Create a new processed version of the site in /processed directory
 * 4. Create _redirects file for CloudFlare
 */

/**
 * Check if a URL is external to the given domain
 */
function isExternalUrl(string $url, string $domain): bool {
    // Handle relative URLs - they are always internal
    if (strpos($url, '/') === 0 && strpos($url, '//') !== 0) {
        return false;
    }
    
    // Parse the URL to get hostname
    $parsed = parse_url($url);
    $host = $parsed['host'] ?? '';
    
    // If no host, it's not external
    if (empty($host)) {
        return false;
    }
    
    // Normalize both hostnames for comparison (remove www. only if it's a subdomain)
    $normalizedDomain = preg_replace('/^www\.(?=[^.]+\.)/', '', $domain);
    $normalizedHost = preg_replace('/^www\.(?=[^.]+\.)/', '', $host);
    
    // Compare normalized hostnames
    return $normalizedHost !== $normalizedDomain;
}

/**
 * Check if external URL domain should be removed (converted to text)
 */
function shouldRemoveExternalDomain(string $url, array $normalizedRemoveDomains): bool {
    // Parse the URL to get hostname
    $parsed = parse_url($url);
    $host = $parsed['host'] ?? '';
    
    // If no host, don't remove
    if (empty($host)) {
        return false;
    }
    
    // Normalize hostname for comparison
    $normalizedHost = preg_replace('/^www\.(?=[^.]+\.)/', '', $host);
    
    // Check if this domain should be removed
    return in_array($normalizedHost, $normalizedRemoveDomains);
}

/**
 * Check if external URL domain should be kept (not removed)
 */
function shouldKeepExternalDomain(string $url, array $normalizedKeepDomains): bool {
    // If no keep list is specified, keep all domains
    if (empty($normalizedKeepDomains)) {
        return true;
    }
    
    // Parse the URL to get hostname
    $parsed = parse_url($url);
    $host = $parsed['host'] ?? '';
    
    // If no host, don't keep
    if (empty($host)) {
        return false;
    }
    
    // Normalize hostname for comparison
    $normalizedHost = preg_replace('/^www\.(?=[^.]+\.)/', '', $host);
    
    // Check if this domain should be kept
    return in_array($normalizedHost, $normalizedKeepDomains);
}

/**
 * Clean XSS attacks from specified elements
 */
function cleanXssFromElements(DOMDocument $dom, DOMXPath $xpath, array $cleanXssSelectors): void {
    if (empty($cleanXssSelectors)) {
        return;
    }
    
    foreach ($cleanXssSelectors as $selector) {
        // Convert CSS-like selector to XPath if needed
        $xpathQuery = $selector;
        
        // Handle simple conversions (this can be expanded for more complex selectors)
        if (strpos($selector, '[@') === false && strpos($selector, '//') === false) {
            // Simple class selector like "div.margin" -> "//div[@class='margin']"
            if (preg_match('/^(\w+)\.([a-zA-Z0-9_-]+)$/', $selector, $matches)) {
                $xpathQuery = "//{$matches[1]}[@class='{$matches[2]}']";
            }
        } else {
            // If it looks like XPath but doesn't start with //, add it
            if (strpos($selector, '[@') !== false && strpos($selector, '//') !== 0) {
                $xpathQuery = '//' . $selector;
            }
        }
        
        echo "Looking for XSS in elements matching: $xpathQuery\n";
        
        try {
            $elements = $xpath->query($xpathQuery);
            if ($elements === false) {
                echo "Invalid XPath selector: $xpathQuery\n";
                continue;
            }
            
            // Process each element
            foreach ($elements as $element) {
                // Skip if element has been removed from DOM
                if (!$element->parentNode) {
                    continue;
                }
                
                // Get the innerHTML to check for encoded HTML entities
                $innerHTML = '';
                foreach ($element->childNodes as $child) {
                    $innerHTML .= $dom->saveHTML($child);
                }
                
                echo "Checking element content: " . substr($innerHTML, 0, 100) . "...\n";
                
                // Check if content contains encoded HTML tags (XSS indicators)
                if (preg_match('/&lt;\s*[a-zA-Z][a-zA-Z0-9]*\s*[^&]*&gt;/', $innerHTML)) {
                    // Check if there are child elements that match our selector
                    // Convert the absolute XPath to a relative one for descendant search
                    $descendantQuery = $xpathQuery;
                    if (strpos($xpathQuery, '//') === 0) {
                        // Convert //tagname[@attr] to .//tagname[@attr] for descendant search
                        $descendantQuery = '.' . $xpathQuery;
                    }
                    
                    $childElements = $xpath->query($descendantQuery, $element);
                    $hasMatchingChildren = $childElements && $childElements->length > 0;
                    
                    if ($hasMatchingChildren) {
                        echo "XSS found but child elements match selector, skipping to preserve content\n";
                    } else {
                        echo "XSS found and no matching child elements, cleaning: " . substr($innerHTML, 0, 100) . "...\n";
                        
                        // Clear the element content
                        while ($element->firstChild) {
                            $element->removeChild($element->firstChild);
                        }
                        
                        echo "Cleaned XSS content from element\n";
                    }
                }
            }
        } catch (Exception $e) {
            echo "Error processing XPath selector '$xpathQuery': " . $e->getMessage() . "\n";
        }
    }
}

if ($argc < 2) {
    exit("Usage: php process.php <domain> [removeLinksByDomain] [keepLinksByDomain] [cleanXssSelectors] [removeContent]\n");
}

$domain = $argv[1];
$removeLinksByDomain = isset($argv[2]) ? explode(',', $argv[2]) : [];
$keepLinksByDomain = isset($argv[3]) ? explode(',', $argv[3]) : [];
$cleanXssSelectors = isset($argv[4]) ? explode(',', $argv[4]) : [];
$removeContent = isset($argv[5]) ? explode(',', $argv[5]) : [];

// Normalize domains to remove for comparison
$normalizedRemoveDomains = array_map(function($d) {
    return preg_replace('/^www\.(?=[^.]+\.)/', '', trim($d));
}, $removeLinksByDomain);

// Normalize domains to keep for comparison - filter out empty values
$normalizedKeepDomains = array_filter(array_map(function($d) {
    $trimmed = trim($d);
    return $trimmed !== '' ? preg_replace('/^www\.(?=[^.]+\.)/', '', $trimmed) : '';
}, $keepLinksByDomain), function($d) {
    return $d !== '';
});

// Clean and prepare XSS selectors
$cleanXssSelectors = array_filter(array_map('trim', $cleanXssSelectors), function($s) {
    return $s !== '';
});

// Clean and prepare content to remove
$removeContent = array_filter(array_map('trim', $removeContent), function($s) {
    return $s !== '';
});

$sourceDir = "output/{$domain}";
$processedDir = "processed/{$domain}";
$publicDir = "processed/{$domain}/public";
$functionsDir = "processed/{$domain}/functions";

if (!is_dir($sourceDir)) {
    exit("Source directory not found: $sourceDir\n");
}

// Create processed, public and functions directories if they don't exist
if (!is_dir($processedDir)) {
    mkdir($processedDir, 0777, true);
}
if (!is_dir($publicDir)) {
    mkdir($publicDir, 0777, true);
}
if (!is_dir($functionsDir)) {
    mkdir($functionsDir, 0777, true);
}

// Initialize redirects array
$redirects = [];

// Get all HTML files recursively
$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($sourceDir)
);

$htmlFiles = [];
foreach ($files as $file) {
    if ($file->isFile() && $file->getExtension() === 'html') {
        $path = $file->getPathname();
        // Debug output to see what files we're finding
        echo "Found file: " . $path . "\n";
        $htmlFiles[] = $path;
    }
}

echo "\nFound " . count($htmlFiles) . " HTML files to process\n";

// Get absolute paths for source and processed directories
$sourceDirAbs = realpath($sourceDir);
$processedDirAbs = realpath($processedDir) ?: $processedDir;

// Keep track of processed resources to avoid duplicates
$processedResources = [];

foreach ($htmlFiles as $file) {
    // Get absolute path of the current file
    $fileAbs = realpath($file);
    
    // Calculate relative path from source directory
    $relativePath = substr($fileAbs, strlen($sourceDirAbs) + 1);
    // Convert Windows backslashes to forward slashes
    $relativePath = str_replace('\\', '/', $relativePath);
    
    // Debug output to see the paths we're working with
    echo "Source file: $file\n";
    echo "Relative path: $relativePath\n";
    
    $targetPath = $publicDir . '/' . $relativePath;
    $targetDir = dirname($targetPath);

    // Create target directory if it doesn't exist
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0777, true);
    }

    echo "Processing: $relativePath\n";
    $html = file_get_contents($file);
    if ($html === false) {
        echo "Failed to read file: $file\n";
        continue;
    }

    // Detect encoding
    $encoding = mb_detect_encoding($html, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);
    if ($encoding && $encoding !== 'UTF-8') {
        echo "Converting from $encoding to UTF-8\n";
        $html = mb_convert_encoding($html, 'UTF-8', $encoding);
    }

    // Remove specified content
    if (!empty($removeContent)) {
        foreach ($removeContent as $content) {
            $count = 0;
            $html = str_replace($content, '', $html, $count);
            if ($count > 0) {
                echo "Removed content: " . substr($content, 0, 50) . "... ($count times)\n";
            }
        }
    }

    $dom = new DOMDocument();
    @$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    $xpath = new DOMXPath($dom);

    // Remove base tags
    $baseTags = $xpath->query('//base');
    foreach ($baseTags as $baseTag) {
        echo "Removing base tag: " . $baseTag->getAttribute('href') . "\n";
        if ($baseTag->parentNode) {
            $baseTag->parentNode->removeChild($baseTag);
        }
    }

    // Process all resource URLs
    $tags = [
        ['tag' => 'link',   'attr' => 'href'],
        ['tag' => 'script', 'attr' => 'src'],
        ['tag' => 'img',    'attr' => 'src'],
        ['tag' => 'a',      'attr' => 'href'],
        ['tag' => 'video',  'attr' => 'src'],
        ['tag' => 'source', 'attr' => 'src'],
        ['tag' => 'track',  'attr' => 'src'],
    ];

    foreach ($tags as $tagInfo) {
        $nodes = $xpath->query("//{$tagInfo['tag']}[@{$tagInfo['attr']}]");
        foreach ($nodes as $node) {
            $attr = $tagInfo['attr'];
            $url = $node->getAttribute($attr);
            if (empty($url)) continue;

            // Handle external URLs
            if (strpos($url, 'http') === 0 && isExternalUrl($url, $domain)) {
                // Check if this domain should be removed (highest priority)
                if (shouldRemoveExternalDomain($url, $normalizedRemoveDomains)) {
                    if ($tagInfo['tag'] === 'a') {
                        echo "Removing external link from blocked domain: $url\n";
                        // Replace link with its text content
                        $textNode = $dom->createTextNode($node->textContent);
                        if ($node->parentNode) {
                            $node->parentNode->replaceChild($textNode, $node);
                        }
                    }
                    continue;
                }
                
                // Check if this domain should be kept (when keep list is specified)
                if (!shouldKeepExternalDomain($url, $normalizedKeepDomains)) {
                    if ($tagInfo['tag'] === 'a') {
                        echo "Removing external link not in keep list: $url\n";
                        // Replace link with its text content
                        $textNode = $dom->createTextNode($node->textContent);
                        if ($node->parentNode) {
                            $node->parentNode->replaceChild($textNode, $node);
                        }
                    }
                    continue;
                }
                
                // Add rel="nofollow" to external links that should be kept
                if ($tagInfo['tag'] === 'a') {
                    $node->setAttribute('rel', 'nofollow');
                    echo "Added rel=\"nofollow\" to external link: $url\n";
                }
                continue;
            }

            // Extract original URL from Wayback Machine URL
            if (preg_match('#/web/\d+[a-z_]{0,3}/(https?://[^"\'>]+)#', $url, $m)) {
                $url = $m[1];
                // Check if the extracted URL is external
                if (isExternalUrl($url, $domain)) {
                    // Check if this domain should be removed (highest priority)
                    if (shouldRemoveExternalDomain($url, $normalizedRemoveDomains)) {
                        if ($tagInfo['tag'] === 'a') {
                            echo "Removing external Wayback link from blocked domain: $url\n";
                            // Replace link with its text content
                            $textNode = $dom->createTextNode($node->textContent);
                            if ($node->parentNode) {
                                $node->parentNode->replaceChild($textNode, $node);
                            }
                        }
                        continue;
                    }
                    
                    // Check if this domain should be kept (when keep list is specified)
                    if (!shouldKeepExternalDomain($url, $normalizedKeepDomains)) {
                        if ($tagInfo['tag'] === 'a') {
                            echo "Removing external Wayback link not in keep list: $url\n";
                            // Replace link with its text content
                            $textNode = $dom->createTextNode($node->textContent);
                            if ($node->parentNode) {
                                $node->parentNode->replaceChild($textNode, $node);
                            }
                        }
                        continue;
                    }
                    
                    // Add rel="nofollow" to external links that should be kept
                    if ($tagInfo['tag'] === 'a') {
                        $node->setAttribute('rel', 'nofollow');
                        echo "Added rel=\"nofollow\" to external Wayback link: $url\n";
                    }
                    continue;
                }
            }

            // Handle protocol-relative URLs
            if (strpos($url, '//') === 0) {
                $url = 'https:' . $url;
                // Check if the protocol-relative URL is external
                if (isExternalUrl($url, $domain)) {
                    // Check if this domain should be removed (highest priority)
                    if (shouldRemoveExternalDomain($url, $normalizedRemoveDomains)) {
                        if ($tagInfo['tag'] === 'a') {
                            echo "Removing external protocol-relative link from blocked domain: $url\n";
                            // Replace link with its text content
                            $textNode = $dom->createTextNode($node->textContent);
                            if ($node->parentNode) {
                                $node->parentNode->replaceChild($textNode, $node);
                            }
                        }
                        continue;
                    }
                    
                    // Check if this domain should be kept (when keep list is specified)
                    if (!shouldKeepExternalDomain($url, $normalizedKeepDomains)) {
                        if ($tagInfo['tag'] === 'a') {
                            echo "Removing external protocol-relative link not in keep list: $url\n";
                            // Replace link with its text content
                            $textNode = $dom->createTextNode($node->textContent);
                            if ($node->parentNode) {
                                $node->parentNode->replaceChild($textNode, $node);
                            }
                        }
                        continue;
                    }
                    
                    // Add rel="nofollow" to external links that should be kept
                    if ($tagInfo['tag'] === 'a') {
                        $node->setAttribute('rel', 'nofollow');
                        echo "Added rel=\"nofollow\" to external protocol-relative link: $url\n";
                    }
                    continue;
                }
            }

            // Skip data URLs
            if (strpos($url, 'data:') === 0) {
                continue;
            }

            // Skip mailto: URLs
            if (strpos($url, 'mailto:') === 0) {
                echo "Skipping mailto: URL: $url\n";
                continue;
            }

            // Get the path component
            $parsed = parse_url($url);
            if ($parsed === false) continue;

            $path = $parsed['path'] ?? '/';
            $query = isset($parsed['query']) ? $parsed['query'] : '';

            // Create relative path and ensure forward slashes
            $relativePath = str_replace('\\', '/', ltrim($path, '/'));
            
            // For relative URLs, resolve against the current HTML file's directory
            if (strpos($url, '/') !== 0 && strpos($url, 'http') !== 0) {
                $currentDir = dirname($relativePath);
                $relativePath = $currentDir . '/' . $url;
            }
            
            // Check if we need to copy this resource
            $sourceResourcePath = $sourceDir . '/' . $relativePath;
            $targetResourcePath = $publicDir . '/' . $relativePath;
            
            // For img tags, remove them if the source file doesn't exist
            if ($tagInfo['tag'] === 'img') {
                if (!file_exists($sourceResourcePath) || is_dir($sourceResourcePath)) {
                    echo "Removing broken image: $relativePath (checked at: $sourceResourcePath)\n";
                    
                    // If image is inside an anchor tag, replace with alt text
                    if ($node->parentNode && $node->parentNode->nodeName === 'a') {
                        $altText = $node->getAttribute('alt');
                        if (!empty($altText)) {
                            echo "Replacing broken image with alt text: $altText\n";
                            $textNode = $dom->createTextNode($altText);
                            $node->parentNode->replaceChild($textNode, $node);
                        } else {
                            $node->parentNode->removeChild($node);
                        }
                    } else {
                        $node->parentNode->removeChild($node);
                    }
                    continue;
                }
            }
            
            if (file_exists($sourceResourcePath) && !is_dir($sourceResourcePath) && !isset($processedResources[$relativePath])) {
                $targetResourceDir = dirname($targetResourcePath);
                if (!is_dir($targetResourceDir)) {
                    mkdir($targetResourceDir, 0777, true);
                }
                copy($sourceResourcePath, $targetResourcePath);
                echo "Copied resource: $relativePath\n";
                $processedResources[$relativePath] = true;
            }

            // Keep original URL structure
            $originalUrl = $path;
            if (!empty($query)) {
                $originalUrl .= '?' . $query;
            }
            $node->setAttribute($attr, $originalUrl);

            // Add to redirects if this is a transformed URL
            if ($tagInfo['tag'] === 'a') {
                $transformedPath = transformUrlToStaticPath($url);
                $parsedTransformed = parse_url($transformedPath);
                $transformedPath = $parsedTransformed['path'] ?? '/';
                $parsedOriginal = parse_url($url);
                $hasQueryParams = !empty($parsedOriginal['query']);

                // Skip adding root path redirect
                if ($transformedPath !== $path && $path !== '/') {
                    if ($hasQueryParams) {
                        // Check if this is an external URL using helper function
                        if (isExternalUrl($url, $domain)) {
                            echo "Skipping external URL for CloudFlare function: $url\n";
                            continue;
                        }

                        // Create CloudFlare Function for URLs with parameters
                        $functionPath = $path;
                        if (strpos($functionPath, '/') === 0) {
                            $functionPath = substr($functionPath, 1);
                        }

                        // Debug output
                        echo "Debug - Processing URL: $url\n";
                        echo "Debug - Path: $path\n";
                        echo "Debug - Function path: $functionPath\n";
                        echo "Debug - Query params: " . $parsedOriginal['query'] . "\n";

                        // Validate function path
                        if (strpos($functionPath, '@') !== false || 
                            strpos($functionPath, 'mailto:') !== false ||
                            strpos($functionPath, 'tel:') !== false ||
                            strpos($functionPath, 'javascript:') !== false ||
                            strpos($functionPath, 'data:') !== false) {
                            echo "Skipping invalid function path: $functionPath\n";
                            continue;
                        }

                        // Ensure path is filesystem-safe
                        $functionPath = preg_replace('/[^a-zA-Z0-9\/_-]/', '_', $functionPath);
                        
                        $functionDir = $functionsDir . '/' . dirname($functionPath);
                        if (!is_dir($functionDir)) {
                            mkdir($functionDir, 0777, true);
                        }
                        
                        // Create function file
                        $functionFile = $functionsDir . '/' . $functionPath . '.js';
                        echo "Creating CloudFlare Function at: $functionFile\n";
                        
                        $functionContent = <<<JS
export async function onRequest(context) {
  const url = new URL(context.request.url);
  const params = new URLSearchParams(url.search);

  //skip root urls
  if (!params.toString()) {
    // Serve static `index.html`
    return context.next();
  }
  
  // Get all parameters
  const paramValues = {};
  for (const [key, value] of params.entries()) {
    // Skip empty values
    if (value === '' || value === null) continue;
    // Clean parameter values to be filesystem-safe
    const cleanValue = value.replace(/[^a-zA-Z0-9-]/g, '_');
    paramValues[key] = cleanValue;
  }
  
  // Create path with parameters
  const paramPath = Object.entries(paramValues)
    .map(([key, value]) => key + '_' + value)
    .join('_');
  
  // Remove trailing slash if exists
  let path = url.pathname.replace(/\/$/, '');
  
  // Add parameters as directory if they exist
  if (paramPath) {
    path += '/' + paramPath;
  }
  
  // Add index.html for paths without extension
  if (!path.match(/\.[a-zA-Z0-9]+$/)) {
    path += '/index.html';
  }
  
  const response = await fetch('https://{$domain}' + path);
  return response;
}
JS;
                        file_put_contents($functionFile, $functionContent);
                        echo "Created CloudFlare Function: $functionFile\n";
                    } else {
                        // Add clean URLs to redirects
                        $redirects[$originalUrl] = $transformedPath;
                    }
                }
            }
        }
    }

    // Process inline styles
    $styleNodes = $xpath->query("//style");
    foreach ($styleNodes as $style) {
        $css = $style->textContent;
        $processedCss = processCss($css, $domain, $sourceDir, $publicDir, $processedResources);
        $style->textContent = $processedCss;
    }

    // Clean XSS attacks from specified elements
    cleanXssFromElements($dom, $xpath, $cleanXssSelectors);

    // Process internal links to remove broken ones with text
    $html = $dom->saveHTML();
    if ($html === false) {
        echo "Failed to save HTML for link processing: $targetPath\n";
        continue;
    }
    $html = processInternalLinks($html, $domain, $publicDir);

    // Save the transformed HTML to the processed directory
    file_put_contents($targetPath, $html);
    echo "Saved transformed HTML: $targetPath\n";
}

// Save _redirects file in public directory
$redirectsContent = "";
foreach ($redirects as $from => $to) {
    $redirectsContent .= "$from $to 200\n";
}
file_put_contents($publicDir . '/_redirects', $redirectsContent);
echo "Created _redirects file with " . count($redirects) . " redirects\n";

echo "Processing complete!\n";
echo "Static files are available in: $publicDir\n";
echo "CloudFlare Functions are available in: $functionsDir\n";

/**
 * Transform URL to static HTML path (same as in run.php)
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
    
    // Handle root path
    if ($path === '' || $path === '/') {
        $path = 'index.html';
    }
    // Handle .php files
    else if (preg_match('/\.php$/', $path)) {
        $path = preg_replace('/\.php$/', '.html', $path);
    }
    // Handle paths that don't have an extension
    else if (!preg_match('/\.[a-zA-Z0-9]+$/', $path)) {
        $path .= '/index.html';
    }
    
    // For absolute URLs, reconstruct with scheme and host
    if (isset($parsed['scheme']) && isset($parsed['host'])) {
        return $parsed['scheme'] . '://' . $parsed['host'] . $path;
    }
    
    // For relative URLs, just return the path
    return $path;
}

/**
 * Process CSS content to transform URLs
 */
function processCss(string $css, string $domain, string $sourceDir, string $publicDir, array &$processedResources): string {
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
        $css = preg_replace_callback($pattern, function($matches) use ($domain, $sourceDir, $publicDir, &$processedResources) {
            $url = $matches[1];
            
            // Skip data URLs
            if (strpos($url, 'data:') === 0) {
                return $matches[0];
            }

            // Skip external URLs
            if (strpos($url, 'http') === 0 && !str_contains($url, $domain)) {
                return $matches[0];
            }

            // Handle protocol-relative URLs
            if (strpos($url, '//') === 0) {
                $url = 'https:' . $url;
            }

            // Extract original URL from Wayback Machine URL
            if (preg_match('#/web/\d+[a-z_]{0,3}/(https?://[^"\'>]+)#', $url, $m)) {
                $url = $m[1];
            }

            // Get the path component
            $parsed = parse_url($url);
            if ($parsed === false) return $matches[0];

            $path = $parsed['path'] ?? '/';
            $query = isset($parsed['query']) ? $parsed['query'] : '';

            // Create relative path and ensure forward slashes
            $relativePath = str_replace('\\', '/', ltrim($path, '/'));

            // Check if we need to copy this resource
            $sourceResourcePath = $sourceDir . '/' . $relativePath;
            $targetResourcePath = $publicDir . '/' . $relativePath;
            
            if (file_exists($sourceResourcePath) && !is_dir($sourceResourcePath) && !isset($processedResources[$relativePath])) {
                $targetResourceDir = dirname($targetResourcePath);
                if (!is_dir($targetResourceDir)) {
                    mkdir($targetResourceDir, 0777, true);
                }
                copy($sourceResourcePath, $targetResourcePath);
                echo "Copied resource from CSS: $relativePath\n";
                $processedResources[$relativePath] = true;
            }

            // Keep original URL structure
            $originalUrl = $path;
            if (!empty($query)) {
                $originalUrl .= '?' . $query;
            }
            return str_replace($matches[1], $originalUrl, $matches[0]);
        }, $css);
    }

    return $css;
}

/**
 * Check if internal link exists and replace broken ones with text
 */
function processInternalLinks(string $html, string $domain, string $publicDir): string {
    $dom = new DOMDocument();
    @$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    $xpath = new DOMXPath($dom);
    
    // Find all internal links
    $links = $xpath->query("//a[@href]");
    foreach ($links as $link) {
        $href = $link->getAttribute('href');
        $text = $link->textContent;
        
        echo "\nProcessing link: $href\n";
        echo "Link text: $text\n";
        
        // Skip empty URLs
        if (empty($href)) {
            echo "Skipping empty URL\n";
            continue;
        }
        
        // Special handling for root path - never replace it
        if ($href === '/' || $href === '') {
            echo "Root path detected, keeping link\n";
            continue;
        }
        
        // Check if this is an external URL and skip it
        if (isExternalUrl($href, $domain)) {
            echo "External URL detected, skipping: $href\n";
            continue;
        }
        
        // Handle relative URLs by converting to absolute for processing
        $originalHref = $href;
        if (strpos($href, '/') === 0) {
            $href = "https://{$domain}{$href}";
            echo "Converted to absolute URL: $href\n";
        }
        
        // Skip invalid URLs (check the converted URL, not the original)
        if (strpos($originalHref, 'http') === 0 && !filter_var($href, FILTER_VALIDATE_URL)) {
            echo "Skipping invalid URL\n";
            continue;
        }
        
        // Parse URL to get path and query
        $parsed = parse_url($href);
        $path = $parsed['path'] ?? '/';
        $query = isset($parsed['query']) ? $parsed['query'] : '';
        
        echo "Original path: $path\n";
        
        // Handle query parameters
        if (!empty($query)) {
            parse_str($query, $params);
            $paramPath = [];
            foreach ($params as $key => $value) {
                if ($value === '' || $value === null) continue;
                $cleanValue = preg_replace('/[^a-zA-Z0-9-]/', '_', $value);
                $paramPath[] = $key . '_' . $cleanValue;
            }
            if (!empty($paramPath)) {
                $path = rtrim($path, '/');
                $path .= '/' . implode('_', $paramPath);
            }
        }
        
        // Remove trailing slash
        $path = rtrim($path, '/');
        
        // Get file extension
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        
        // List of dynamic file extensions
        $dynamicExtensions = ['php', 'asp', 'aspx', 'jsp', 'cgi', 'pl', 'py', 'rb'];
        
        // Transform path based on type
        if ($path === '' || $path === '/') {
            $staticPath = 'index.html';
        } else if (in_array(strtolower($extension), $dynamicExtensions)) {
            $staticPath = preg_replace('/\.' . preg_quote($extension, '/') . '$/', '/index.html', $path);
        } else if (empty($extension)) {
            $staticPath = $path . '/index.html';
        } else {
            $staticPath = $path;
        }
        
        echo "Transformed to static path: $staticPath\n";
        
        // Check if file exists
        $filePath = $publicDir . '/' . ltrim($staticPath, '/');
        echo "Looking for file: $filePath\n";
        
        // Check both the direct path and index.html in subfolders
        $fileExists = file_exists($filePath);
        if (!$fileExists && empty($extension)) {
            // Try checking for index.html in the subfolder
            $subfolderPath = $publicDir . '/' . ltrim($path, '/') . '/index.html';
            echo "Also checking subfolder index: $subfolderPath\n";
            $fileExists = file_exists($subfolderPath);
        }
        
        if (!$fileExists) {
            echo "File not found, replacing link with text: $text\n";
            // Create a text node with the link's text content
            $textNode = $dom->createTextNode($text);
            // Replace the link node with the text node
            if ($link->parentNode) {
                $link->parentNode->replaceChild($textNode, $link);
                echo "Successfully replaced link with text\n";
            } else {
                echo "Warning: Could not replace link (no parent node)\n";
            }
        } else {
            echo "File exists: $filePath\n";
        }
    }
    
    // Get the modified HTML
    $modifiedHtml = $dom->saveHTML();
    if ($modifiedHtml === false) {
        echo "Warning: Failed to save modified HTML\n";
        return $html;
    }
    
    return $modifiedHtml;
} 