<?php
/**
 * xsukax CSV to API Converter
 * A lightweight single-file application for converting CSV files into JSON API endpoints
 * with wildcard filtering support
 * 
 * @version 2.0.0
 * @author xsukax
 */

// Error handling configuration
if (isset($_GET['file'])) {
    error_reporting(0);
    ini_set('display_errors', 0);
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
}

// Application Configuration
define('UPLOAD_DIR', 'uploads');
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB
define('ALLOWED_MIME', ['text/csv', 'text/plain', 'application/csv']);

// Create upload directory if it doesn't exist
if (!file_exists(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}

/**
 * Generate a unique file code
 */
function generateFileCode(): string {
    return bin2hex(random_bytes(8)) . substr(md5(microtime(true)), 0, 8);
}

/**
 * Sanitize user input
 */
function sanitizeInput(string $input): string {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * Get MIME type of a file
 */
function getMimeType(string $file): string {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if (!$finfo) return 'application/octet-stream';
    $mime = finfo_file($finfo, $file);
    finfo_close($finfo);
    return $mime ?: 'application/octet-stream';
}

/**
 * Format bytes to human-readable size
 */
function formatBytes(int $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));
    return round($bytes, 2) . ' ' . $units[$pow];
}

/**
 * Detect CSV delimiter
 */
function detectDelimiter(string $filePath): string {
    $handle = fopen($filePath, 'r');
    if (!$handle) return ',';
    
    $firstLine = fgets($handle);
    fclose($handle);
    
    $delimiters = [',', ';', '\t', '|'];
    $counts = [];
    
    foreach ($delimiters as $delimiter) {
        $counts[$delimiter] = substr_count($firstLine, $delimiter);
    }
    
    return array_search(max($counts), $counts) ?: ',';
}

/**
 * Check if value matches pattern (supports wildcards)
 * 
 * @param string $pattern Pattern to match (can contain * wildcards)
 * @param string $value Value to check
 * @return bool True if matches
 */
function matchesPattern(string $pattern, string $value): bool {
    // If no asterisk, do exact match
    if (strpos($pattern, '*') === false) {
        return $pattern === $value;
    }
    
    // Convert wildcard pattern to regex
    // Escape special regex characters except *
    $regexPattern = preg_quote($pattern, '/');
    
    // Replace escaped \* with .* for regex
    $regexPattern = str_replace('\*', '.*', $regexPattern);
    
    // Add anchors for full string match
    $regexPattern = '/^' . $regexPattern . '$/';
    
    return preg_match($regexPattern, $value) === 1;
}

/**
 * Parse CSV file and return filtered data
 */
function parseCSV(string $filePath, ?string $keyColumn = null, ?string $filterValue = null): array {
    if (!file_exists($filePath)) {
        return ['error' => 'File not found'];
    }
    
    $handle = fopen($filePath, 'r');
    if ($handle === false) {
        return ['error' => 'Failed to open file'];
    }
    
    $delimiter = detectDelimiter($filePath);
    $header = fgetcsv($handle, 0, $delimiter, '"', '\\');
    
    if ($header === false || !is_array($header)) {
        fclose($handle);
        return ['error' => 'Invalid CSV format'];
    }
    
    // Clean and prepare headers
    $header = array_map(function($value) {
        return trim(str_replace("\xEF\xBB\xBF", "", $value), " \t\n\r\0\x0B");
    }, $header);
    
    $header = array_values(array_filter($header, fn($v) => $v !== ''));
    
    // Validate key column if provided
    if ($keyColumn !== null && !in_array($keyColumn, $header)) {
        fclose($handle);
        return ['error' => "Column '{$keyColumn}' not found in CSV"];
    }
    
    $result = [];
    
    // Read and process data rows
    while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
        if (!is_array($row)) continue;
        
        // Trim all values
        $row = array_map('trim', $row);
        
        // Skip completely empty rows
        if (empty(array_filter($row, fn($val) => $val !== ''))) {
            continue;
        }
        
        // Ensure row matches header length
        $row = array_pad($row, count($header), '');
        $rowData = array_combine($header, $row);
        
        // Apply filter if specified (supports wildcards)
        if ($keyColumn !== null && $filterValue !== null) {
            if (!isset($rowData[$keyColumn]) || !matchesPattern($filterValue, $rowData[$keyColumn])) {
                continue;
            }
        }
        
        $result[] = $rowData;
    }
    
    fclose($handle);
    return $result;
}

/**
 * Send JSON response and exit
 */
function jsonResponse(mixed $data, int $status = 200): never {
    if (ob_get_level()) ob_clean();
    
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    http_response_code($status);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================================
// API Request Handler
// ============================================================================
if (isset($_GET['file'])) {
    error_reporting(E_ALL);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    
    $fileCode = sanitizeInput($_GET['file']);
    $header = isset($_GET['header']) ? sanitizeInput($_GET['header']) : null;
    $value = isset($_GET['value']) ? sanitizeInput($_GET['value']) : null;
    
    // Validate file code format
    if (!preg_match('/^[a-f0-9]{16,24}$/', $fileCode)) {
        jsonResponse(['error' => 'Invalid file code format'], 400);
    }
    
    // Require all three parameters: file, header, and value
    if ($header === null || $value === null) {
        jsonResponse(['error' => 'Parameters "file", "header", and "value" are all required'], 400);
    }
    
    $filePath = UPLOAD_DIR . '/' . $fileCode . '.csv';
    
    if (!file_exists($filePath)) {
        jsonResponse(['error' => 'File not found'], 404);
    }
    
    $data = parseCSV($filePath, $header, $value);
    
    if (isset($data['error'])) {
        jsonResponse(['error' => $data['error']], 400);
    }
    
    jsonResponse(['data' => $data]);
}

// ============================================================================
// File Upload Handler
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv'])) {
    $file = $_FILES['csv'];
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $uploadError = match($file['error']) {
            UPLOAD_ERR_INI_SIZE => 'File size exceeds server limit',
            UPLOAD_ERR_FORM_SIZE => 'File size exceeds form limit',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'File upload stopped by extension',
            default => 'Unknown upload error'
        };
    } elseif ($file['size'] > MAX_FILE_SIZE) {
        $uploadError = 'File size exceeds ' . formatBytes(MAX_FILE_SIZE) . ' limit';
    } elseif (!in_array(getMimeType($file['tmp_name']), ALLOWED_MIME)) {
        $uploadError = 'Only CSV files are allowed';
    } else {
        $fileCode = generateFileCode();
        $filePath = UPLOAD_DIR . '/' . $fileCode . '.csv';
        
        if (move_uploaded_file($file['tmp_name'], $filePath)) {
            $uploadedCode = $fileCode;
            $uploadedSize = formatBytes($file['size']);
            
            // Parse headers for display
            $delimiter = detectDelimiter($filePath);
            $handle = fopen($filePath, 'r');
            if ($handle !== false) {
                $firstRow = fgetcsv($handle, 0, $delimiter, '"', '\\');
                fclose($handle);
                if (is_array($firstRow)) {
                    $csvHeaders = array_map('trim', $firstRow);
                }
            }
        } else {
            $uploadError = 'Failed to save file';
        }
    }
}

// Build base URL
$baseUrl = $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']);
$scriptName = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>xsukax CSV to API Converter</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .notification {
            animation: slideIn 0.3s ease-out;
        }
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        .fade-out {
            animation: fadeOut 0.3s ease-out forwards;
        }
        @keyframes fadeOut {
            to { opacity: 0; transform: translateX(100%); }
        }
        .modal-backdrop {
            background: rgba(0, 0, 0, 0.5);
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.3s;
        }
        .modal-backdrop:target {
            opacity: 1;
            pointer-events: all;
        }
        .modal-content {
            transform: scale(0.9);
            opacity: 0;
            transition: all 0.3s;
        }
        .modal-backdrop:target .modal-content {
            transform: scale(1);
            opacity: 1;
        }
        .hover-code {
            transition: all 0.2s;
        }
        .hover-code:hover {
            background-color: rgb(243 244 246);
            cursor: pointer;
        }
        .drop-zone {
            transition: all 0.2s;
        }
        .drop-zone.dragover {
            border-color: rgb(59 130 246);
            background-color: rgb(239 246 255);
        }
    </style>
</head>
<body class="bg-gray-50 min-h-screen">
    
    <!-- Header -->
    <header class="bg-white border-b border-gray-200">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900">xsukax CSV to API Converter</h1>
                    <p class="text-sm text-gray-600 mt-1">Query CSV data via JSON API with wildcard filtering</p>
                </div>
                <a href="#docs" class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 transition-colors">
                    Documentation
                </a>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        
        <!-- Upload Section -->
        <section class="bg-white rounded-lg border border-gray-200 p-6 mb-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Upload CSV File</h2>
            
            <?php if (isset($uploadError)): ?>
                <div class="mb-4 p-3 bg-red-50 border border-red-200 rounded-md">
                    <p class="text-sm text-red-700">⚠ <?= $uploadError ?></p>
                </div>
            <?php endif; ?>
            
            <?php if (isset($uploadedCode)): ?>
                <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-md">
                    <p class="text-sm font-medium text-green-800 mb-2">✓ File uploaded successfully</p>
                    <p class="text-sm text-gray-700 mb-3">Size: <?= $uploadedSize ?></p>
                    
                    <div class="space-y-3">
                        <div>
                            <label class="text-xs font-medium text-gray-600">File Code:</label>
                            <div class="flex items-center gap-2 mt-1">
                                <code class="px-3 py-1.5 bg-white border border-gray-300 rounded text-sm font-mono hover-code" onclick="copyToClipboard('<?= htmlspecialchars($uploadedCode) ?>')"><?= htmlspecialchars($uploadedCode) ?></code>
                                <span class="text-xs text-gray-500">(click to copy)</span>
                            </div>
                        </div>
                        
                        <div>
                            <label class="text-xs font-medium text-gray-600">API Endpoint Format:</label>
                            <code class="block mt-1 px-3 py-1.5 bg-white border border-gray-300 rounded text-xs break-all"><?= htmlspecialchars($baseUrl . '/' . $scriptName) ?>?file=<?= htmlspecialchars($uploadedCode) ?>&header={column}&value={filter}</code>
                        </div>
                    </div>
                    
                    <?php if (isset($csvHeaders) && !empty($csvHeaders)): ?>
                        <div class="mt-4 pt-4 border-t border-green-200">
                            <p class="text-xs font-medium text-gray-600 mb-2">Detected columns (<?= count($csvHeaders) ?>):</p>
                            <div class="flex flex-wrap gap-2">
                                <?php foreach ($csvHeaders as $col): ?>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-800"><?= htmlspecialchars($col) ?></span>
                                <?php endforeach; ?>
                            </div>
                            <p class="text-xs text-gray-500 mt-2">Use any column name as "header" and provide a "value" to filter. Wildcards (*) are supported!</p>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <form method="post" enctype="multipart/form-data" class="space-y-4">
                <div id="drop-zone" class="drop-zone border-2 border-dashed border-gray-300 rounded-lg p-8 text-center">
                    <input type="file" name="csv" accept=".csv" class="hidden" id="csv-file">
                    <label for="csv-file" class="cursor-pointer">
                        <svg class="mx-auto h-12 w-12 text-gray-400" stroke="currentColor" fill="none" viewBox="0 0 48 48">
                            <path d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8m-12 4h.02" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        <p class="mt-2 text-sm text-gray-600">
                            <span class="font-medium text-blue-600" id="file-name">Click to upload</span> or drag and drop
                        </p>
                        <p class="text-xs text-gray-500 mt-1">CSV files up to <?= formatBytes(MAX_FILE_SIZE) ?></p>
                    </label>
                </div>
                
                <button type="submit" class="w-full sm:w-auto px-6 py-2.5 bg-gray-900 text-white text-sm font-medium rounded-md hover:bg-gray-800 transition-colors focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2">
                    Upload File
                </button>
            </form>
        </section>

        <!-- Quick Start -->
        <section class="bg-white rounded-lg border border-gray-200 p-6 mb-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Quick Start</h2>
            
            <div class="space-y-4">
                <div>
                    <h3 class="text-sm font-medium text-gray-700 mb-2">Endpoint Structure</h3>
                    <code class="block px-4 py-3 bg-gray-50 rounded-md text-sm break-all font-mono">
                        <span class="text-gray-500"><?= htmlspecialchars($baseUrl) ?>/</span><span class="text-blue-600"><?= htmlspecialchars($scriptName) ?></span><span class="text-gray-500">?</span><span class="text-green-600">file</span>=<span class="text-purple-600">{code}</span><span class="text-gray-500">&</span><span class="text-green-600">header</span>=<span class="text-purple-600">{column}</span><span class="text-gray-500">&</span><span class="text-green-600">value</span>=<span class="text-purple-600">{filter}</span>
                    </code>
                </div>
                
                <div class="grid md:grid-cols-2 gap-4">
                    <div class="space-y-2">
                        <h3 class="text-sm font-medium text-gray-700">Parameters</h3>
                        <ul class="text-sm text-gray-600 space-y-1">
                            <li><code class="px-1.5 py-0.5 bg-gray-100 rounded text-xs font-mono">file</code> — Unique file code (required)</li>
                            <li><code class="px-1.5 py-0.5 bg-gray-100 rounded text-xs font-mono">header</code> — Column name to filter by (required)</li>
                            <li><code class="px-1.5 py-0.5 bg-gray-100 rounded text-xs font-mono">value</code> — Value to filter (required, supports *)</li>
                        </ul>
                        <p class="text-xs text-gray-500 mt-2"><strong>Note:</strong> All three parameters must be provided.</p>
                    </div>
                    
                    <div class="space-y-2">
                        <h3 class="text-sm font-medium text-gray-700">Wildcard Patterns</h3>
                        <ul class="text-xs text-gray-600 space-y-1">
                            <li><code class="px-1 bg-gray-100 rounded">jenkins*</code> — Starts with "jenkins"</li>
                            <li><code class="px-1 bg-gray-100 rounded">*jenkins</code> — Ends with "jenkins"</li>
                            <li><code class="px-1 bg-gray-100 rounded">*jenkins*</code> — Contains "jenkins"</li>
                            <li><code class="px-1 bg-gray-100 rounded">jenkins46</code> — Exact match (no *)</li>
                        </ul>
                    </div>
                </div>
            </div>
        </section>

        <!-- Features -->
        <section class="grid md:grid-cols-3 gap-4">
            <div class="bg-white rounded-lg border border-gray-200 p-4">
                <h3 class="text-sm font-semibold text-gray-900 mb-2">🔍 Wildcard Filtering</h3>
                <p class="text-xs text-gray-600">Use asterisk (*) wildcards for flexible pattern matching like grep</p>
            </div>
            <div class="bg-white rounded-lg border border-gray-200 p-4">
                <h3 class="text-sm font-semibold text-gray-900 mb-2">⚡ Auto-Detection</h3>
                <p class="text-xs text-gray-600">Automatically detects delimiters (comma, semicolon, tab) and handles UTF-8</p>
            </div>
            <div class="bg-white rounded-lg border border-gray-200 p-4">
                <h3 class="text-sm font-semibold text-gray-900 mb-2">🎯 JSON Output</h3>
                <p class="text-xs text-gray-600">Clean JSON responses with proper formatting and error handling</p>
            </div>
        </section>
        
    </main>

    <!-- Documentation Modal -->
    <div id="docs" class="modal-backdrop fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-lg shadow-xl max-w-4xl w-full modal-content max-h-[90vh] overflow-y-auto">
            <div class="p-6">
                <h3 class="text-xl font-semibold text-gray-900 mb-4">Documentation</h3>
                
                <div class="space-y-6">
                    
                    <!-- How It Works -->
                    <div>
                        <h4 class="text-sm font-semibold text-gray-900 mb-2">How It Works</h4>
                        <ol class="space-y-2 text-sm text-gray-600">
                            <li class="flex items-start">
                                <span class="flex-shrink-0 w-6 h-6 bg-gray-100 rounded-full flex items-center justify-center mr-3 text-xs font-medium">1</span>
                                <span>Upload your CSV file through the form</span>
                            </li>
                            <li class="flex items-start">
                                <span class="flex-shrink-0 w-6 h-6 bg-gray-100 rounded-full flex items-center justify-center mr-3 text-xs font-medium">2</span>
                                <span>Receive a unique file code</span>
                            </li>
                            <li class="flex items-start">
                                <span class="flex-shrink-0 w-6 h-6 bg-gray-100 rounded-full flex items-center justify-center mr-3 text-xs font-medium">3</span>
                                <span>Query your data using the API with column name and filter value</span>
                            </li>
                            <li class="flex items-start">
                                <span class="flex-shrink-0 w-6 h-6 bg-gray-100 rounded-full flex items-center justify-center mr-3 text-xs font-medium">4</span>
                                <span>Use wildcards (*) for flexible pattern matching</span>
                            </li>
                        </ol>
                    </div>
                    
                    <!-- API Parameters -->
                    <div>
                        <h4 class="text-sm font-semibold text-gray-900 mb-2">API Parameters</h4>
                        <div class="border border-gray-200 rounded-md overflow-hidden">
                            <table class="min-w-full divide-y divide-gray-200 text-sm">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-700">Parameter</th>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-700">Required</th>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-700">Description</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <tr>
                                        <td class="px-4 py-2"><code class="text-xs bg-gray-100 px-1.5 py-0.5 rounded">file</code></td>
                                        <td class="px-4 py-2 text-xs text-gray-600">Yes</td>
                                        <td class="px-4 py-2 text-xs text-gray-600">Unique file code (24 hex characters)</td>
                                    </tr>
                                    <tr>
                                        <td class="px-4 py-2"><code class="text-xs bg-gray-100 px-1.5 py-0.5 rounded">header</code></td>
                                        <td class="px-4 py-2 text-xs text-gray-600">Yes</td>
                                        <td class="px-4 py-2 text-xs text-gray-600">Column name to filter by</td>
                                    </tr>
                                    <tr>
                                        <td class="px-4 py-2"><code class="text-xs bg-gray-100 px-1.5 py-0.5 rounded">value</code></td>
                                        <td class="px-4 py-2 text-xs text-gray-600">Yes</td>
                                        <td class="px-4 py-2 text-xs text-gray-600">Value to filter (exact match or wildcard pattern)</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p class="text-xs text-gray-600 mt-2 bg-yellow-50 border border-yellow-200 rounded p-2"><strong>Important:</strong> All three parameters (file, header, and value) must be provided in every API request.</p>
                    </div>
                    
                    <!-- Wildcard Patterns -->
                    <div>
                        <h4 class="text-sm font-semibold text-gray-900 mb-2">Wildcard Pattern Matching</h4>
                        <p class="text-sm text-gray-600 mb-3">Use asterisk (*) for flexible pattern matching similar to grep and shell wildcards:</p>
                        <div class="border border-gray-200 rounded-md overflow-hidden">
                            <table class="min-w-full divide-y divide-gray-200 text-sm">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-700">Pattern</th>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-700">Matches</th>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-700">Example</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <tr>
                                        <td class="px-4 py-2"><code class="text-xs bg-gray-100 px-1.5 py-0.5 rounded">jenkins*</code></td>
                                        <td class="px-4 py-2 text-xs text-gray-600">Starts with "jenkins"</td>
                                        <td class="px-4 py-2 text-xs text-gray-600">jenkins46, jenkins07, jenkins123</td>
                                    </tr>
                                    <tr>
                                        <td class="px-4 py-2"><code class="text-xs bg-gray-100 px-1.5 py-0.5 rounded">*jenkins</code></td>
                                        <td class="px-4 py-2 text-xs text-gray-600">Ends with "jenkins"</td>
                                        <td class="px-4 py-2 text-xs text-gray-600">my-jenkins, test-jenkins</td>
                                    </tr>
                                    <tr>
                                        <td class="px-4 py-2"><code class="text-xs bg-gray-100 px-1.5 py-0.5 rounded">*jenkins*</code></td>
                                        <td class="px-4 py-2 text-xs text-gray-600">Contains "jenkins"</td>
                                        <td class="px-4 py-2 text-xs text-gray-600">my-jenkins-server, jenkins46</td>
                                    </tr>
                                    <tr>
                                        <td class="px-4 py-2"><code class="text-xs bg-gray-100 px-1.5 py-0.5 rounded">jenkins*46</code></td>
                                        <td class="px-4 py-2 text-xs text-gray-600">Starts with "jenkins", ends with "46"</td>
                                        <td class="px-4 py-2 text-xs text-gray-600">jenkins-test-46, jenkins46</td>
                                    </tr>
                                    <tr>
                                        <td class="px-4 py-2"><code class="text-xs bg-gray-100 px-1.5 py-0.5 rounded">jenkins46</code></td>
                                        <td class="px-4 py-2 text-xs text-gray-600">Exact match (no wildcard)</td>
                                        <td class="px-4 py-2 text-xs text-gray-600">jenkins46 only</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- CSV Requirements -->
                    <div>
                        <h4 class="text-sm font-semibold text-gray-900 mb-2">CSV Requirements</h4>
                        <ul class="text-sm text-gray-600 space-y-1">
                            <li>• First row must contain column headers</li>
                            <li>• Supports comma, semicolon, tab, or pipe delimiters</li>
                            <li>• UTF-8 encoding with automatic BOM removal</li>
                            <li>• Maximum file size: <?= formatBytes(MAX_FILE_SIZE) ?></li>
                            <li>• Empty rows are automatically skipped</li>
                        </ul>
                    </div>
                    
                    <!-- Sample CSV -->
                    <div>
                        <h4 class="text-sm font-semibold text-gray-900 mb-2">Sample CSV Format</h4>
                        <pre class="text-xs bg-gray-50 p-3 rounded-md overflow-x-auto"><code>Username,Identifier,First name,Last name
jenkins46,9012,Rachel,Booker
jenkins07,2070,Laura,Grey
booker81,4081,Craig,Johnson
jenkins123,5050,Mike,Smith</code></pre>
                    </div>
                    
                    <!-- Usage Examples -->
                    <div>
                        <h4 class="text-sm font-semibold text-gray-900 mb-3">Usage Examples</h4>
                        <div class="space-y-4">
                            
                            <!-- Example 1: Exact Match -->
                            <div class="border border-gray-200 rounded-md p-4">
                                <h5 class="text-xs font-semibold text-gray-900 mb-2">Example 1: Exact match</h5>
                                <p class="text-xs text-gray-600 mb-2">Filter for exact username match.</p>
                                <div class="space-y-2">
                                    <div>
                                        <p class="text-xs font-medium text-gray-700 mb-1">Request:</p>
                                        <code class="block text-xs bg-gray-50 px-3 py-2 rounded break-all">?file=abc123def456&header=Username&value=jenkins46</code>
                                    </div>
                                    <div>
                                        <p class="text-xs font-medium text-gray-700 mb-1">Response:</p>
                                        <pre class="text-xs bg-gray-50 p-3 rounded-md overflow-x-auto"><code>{
  "data": [
    {
      "Username": "jenkins46",
      "Identifier": "9012",
      "First name": "Rachel",
      "Last name": "Booker"
    }
  ]
}</code></pre>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Example 2: Starts With -->
                            <div class="border border-gray-200 rounded-md p-4">
                                <h5 class="text-xs font-semibold text-gray-900 mb-2">Example 2: Starts with wildcard</h5>
                                <p class="text-xs text-gray-600 mb-2">Find all usernames that start with "jenkins".</p>
                                <div class="space-y-2">
                                    <div>
                                        <p class="text-xs font-medium text-gray-700 mb-1">Request:</p>
                                        <code class="block text-xs bg-gray-50 px-3 py-2 rounded break-all">?file=abc123def456&header=Username&value=jenkins*</code>
                                    </div>
                                    <div>
                                        <p class="text-xs font-medium text-gray-700 mb-1">Response:</p>
                                        <pre class="text-xs bg-gray-50 p-3 rounded-md overflow-x-auto"><code>{
  "data": [
    {
      "Username": "jenkins46",
      "Identifier": "9012",
      "First name": "Rachel",
      "Last name": "Booker"
    },
    {
      "Username": "jenkins07",
      "Identifier": "2070",
      "First name": "Laura",
      "Last name": "Grey"
    },
    {
      "Username": "jenkins123",
      "Identifier": "5050",
      "First name": "Mike",
      "Last name": "Smith"
    }
  ]
}</code></pre>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Example 3: Contains -->
                            <div class="border border-gray-200 rounded-md p-4">
                                <h5 class="text-xs font-semibold text-gray-900 mb-2">Example 3: Contains wildcard</h5>
                                <p class="text-xs text-gray-600 mb-2">Find all records where first name contains "ach".</p>
                                <div class="space-y-2">
                                    <div>
                                        <p class="text-xs font-medium text-gray-700 mb-1">Request:</p>
                                        <code class="block text-xs bg-gray-50 px-3 py-2 rounded break-all">?file=abc123def456&header=First%20name&value=*ach*</code>
                                    </div>
                                    <div>
                                        <p class="text-xs font-medium text-gray-700 mb-1">Response:</p>
                                        <pre class="text-xs bg-gray-50 p-3 rounded-md overflow-x-auto"><code>{
  "data": [
    {
      "Username": "jenkins46",
      "Identifier": "9012",
      "First name": "Rachel",
      "Last name": "Booker"
    }
  ]
}</code></pre>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Example 4: Ends With -->
                            <div class="border border-gray-200 rounded-md p-4">
                                <h5 class="text-xs font-semibold text-gray-900 mb-2">Example 4: Ends with wildcard</h5>
                                <p class="text-xs text-gray-600 mb-2">Find all usernames ending with a number pattern.</p>
                                <div class="space-y-2">
                                    <div>
                                        <p class="text-xs font-medium text-gray-700 mb-1">Request:</p>
                                        <code class="block text-xs bg-gray-50 px-3 py-2 rounded break-all">?file=abc123def456&header=Username&value=*46</code>
                                    </div>
                                    <div>
                                        <p class="text-xs font-medium text-gray-700 mb-1">Response:</p>
                                        <pre class="text-xs bg-gray-50 p-3 rounded-md overflow-x-auto"><code>{
  "data": [
    {
      "Username": "jenkins46",
      "Identifier": "9012",
      "First name": "Rachel",
      "Last name": "Booker"
    }
  ]
}</code></pre>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Example 5: No Match -->
                            <div class="border border-gray-200 rounded-md p-4">
                                <h5 class="text-xs font-semibold text-gray-900 mb-2">Example 5: No matching results</h5>
                                <p class="text-xs text-gray-600 mb-2">When no rows match the pattern, an empty array is returned.</p>
                                <div class="space-y-2">
                                    <div>
                                        <p class="text-xs font-medium text-gray-700 mb-1">Request:</p>
                                        <code class="block text-xs bg-gray-50 px-3 py-2 rounded break-all">?file=abc123def456&header=Username&value=admin*</code>
                                    </div>
                                    <div>
                                        <p class="text-xs font-medium text-gray-700 mb-1">Response:</p>
                                        <pre class="text-xs bg-gray-50 p-3 rounded-md overflow-x-auto"><code>{
  "data": []
}</code></pre>
                                    </div>
                                </div>
                            </div>
                            
                        </div>
                    </div>
                    
                    <!-- Error Responses -->
                    <div>
                        <h4 class="text-sm font-semibold text-gray-900 mb-2">Error Responses</h4>
                        <div class="space-y-2">
                            <div>
                                <p class="text-xs font-medium text-gray-700 mb-1">Missing required parameters:</p>
                                <pre class="text-xs bg-gray-50 p-3 rounded-md"><code>{ "error": "Parameters \"file\", \"header\", and \"value\" are all required" }</code></pre>
                            </div>
                            <div>
                                <p class="text-xs font-medium text-gray-700 mb-1">Invalid file code:</p>
                                <pre class="text-xs bg-gray-50 p-3 rounded-md"><code>{ "error": "Invalid file code format" }</code></pre>
                            </div>
                            <div>
                                <p class="text-xs font-medium text-gray-700 mb-1">File not found:</p>
                                <pre class="text-xs bg-gray-50 p-3 rounded-md"><code>{ "error": "File not found" }</code></pre>
                            </div>
                            <div>
                                <p class="text-xs font-medium text-gray-700 mb-1">Column not found:</p>
                                <pre class="text-xs bg-gray-50 p-3 rounded-md"><code>{ "error": "Column 'InvalidColumn' not found in CSV" }</code></pre>
                            </div>
                        </div>
                    </div>
                    
                </div>
                
                <a href="#" class="mt-6 block text-center px-4 py-2 bg-gray-900 text-white text-sm font-medium rounded-md hover:bg-gray-800 transition-colors">
                    Close
                </a>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-8 text-center text-xs text-gray-500">
        <p>xsukax CSV to API Converter — Query and filter CSV data with wildcard support</p>
    </footer>

    <!-- Notification Container -->
    <div id="notification-container" class="fixed top-4 right-4 z-50 space-y-2"></div>

    <script>
        // ============================================================================
        // Drag and Drop Functionality
        // ============================================================================
        const dropZone = document.getElementById('drop-zone');
        const fileInput = document.getElementById('csv-file');
        const fileName = document.getElementById('file-name');
        
        ['dragover', 'dragenter'].forEach(event => {
            dropZone.addEventListener(event, (e) => {
                e.preventDefault();
                dropZone.classList.add('dragover');
            });
        });
        
        ['dragleave', 'dragend', 'drop'].forEach(event => {
            dropZone.addEventListener(event, (e) => {
                e.preventDefault();
                dropZone.classList.remove('dragover');
            });
        });
        
        dropZone.addEventListener('drop', (e) => {
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                fileInput.files = files;
                updateFileName(files[0].name);
            }
        });
        
        fileInput.addEventListener('change', (e) => {
            if (e.target.files.length > 0) {
                updateFileName(e.target.files[0].name);
            }
        });
        
        function updateFileName(name) {
            fileName.textContent = name;
            fileName.classList.add('text-green-600');
            setTimeout(() => fileName.classList.remove('text-green-600'), 1000);
        }
        
        // ============================================================================
        // Copy to Clipboard
        // ============================================================================
        function copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(() => {
                showNotification('Copied to clipboard', 'success');
            }).catch(() => {
                // Fallback for older browsers
                const textArea = document.createElement('textarea');
                textArea.value = text;
                textArea.style.position = 'fixed';
                textArea.style.opacity = '0';
                document.body.appendChild(textArea);
                textArea.select();
                document.execCommand('copy');
                document.body.removeChild(textArea);
                showNotification('Copied to clipboard', 'success');
            });
        }
        
        // ============================================================================
        // Notification System
        // ============================================================================
        function showNotification(message, type = 'info') {
            const container = document.getElementById('notification-container');
            const notification = document.createElement('div');
            
            const colors = {
                success: 'bg-green-500',
                error: 'bg-red-500',
                info: 'bg-blue-500'
            };
            
            notification.className = `notification px-4 py-3 rounded-md text-white shadow-lg ${colors[type] || colors.info}`;
            notification.innerHTML = `<span class="text-sm font-medium">${message}</span>`;
            
            container.appendChild(notification);
            
            setTimeout(() => {
                notification.classList.add('fade-out');
                setTimeout(() => {
                    if (container.contains(notification)) {
                        container.removeChild(notification);
                    }
                }, 300);
            }, 3000);
        }
        
        // ============================================================================
        // Form Reset
        // ============================================================================
        document.querySelector('form').addEventListener('submit', () => {
            setTimeout(() => {
                fileName.textContent = 'Click to upload';
                fileInput.value = '';
            }, 100);
        });
        
        // ============================================================================
        // Modal Controls
        // ============================================================================
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && window.location.hash) {
                window.location.hash = '';
            }
        });
    </script>
    
</body>
</html>