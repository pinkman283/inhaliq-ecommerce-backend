<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductImportService
{
    public const MAX_FILE_SIZE = 10485760; // 10 MB
    public const MAX_ROWS = 5000;
    public const SESSION_TTL_MINUTES = 120; // 2 hours

    /**
     * Get the private directory path for storing temporary import files.
     */
    public static function getImportStorageDir(): string
    {
        $dir = storage_path('app/private/imports');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir;
    }

    /**
     * Clean up expired temporary import files.
     */
    public static function cleanupExpiredFiles(): void
    {
        $dir = self::getImportStorageDir();
        $files = glob($dir . '/*.csv');
        $expiryTime = time() - (self::SESSION_TTL_MINUTES * 60);

        foreach ($files as $file) {
            if (is_file($file) && filemtime($file) < $expiryTime) {
                @unlink($file);
            }
        }
    }

    /**
     * Validate an uploaded CSV file (Dry-Run).
     * Does NOT mutate the database.
     */
    public function validateUpload(UploadedFile $file, User $user, array $options = []): array
    {
        self::cleanupExpiredFiles();

        // 1. Validate file size
        if ($file->getSize() > self::MAX_FILE_SIZE) {
            throw new \InvalidArgumentException("The CSV file exceeds the maximum allowed size of 10 MB.");
        }

        // 2. Validate extension
        $ext = strtolower($file->getClientOriginalExtension());
        if ($ext !== 'csv' && $ext !== 'txt') {
            throw new \InvalidArgumentException("Invalid file type. Please upload a valid CSV file (.csv).");
        }

        // 3. Store file privately
        $importToken = Str::random(40);
        $storedFilename = $importToken . '.csv';
        $storageDir = self::getImportStorageDir();
        $destinationPath = $storageDir . DIRECTORY_SEPARATOR . $storedFilename;

        $file->move($storageDir, $storedFilename);

        // 4. Perform Dry-Run parse & validation
        $report = $this->parseAndValidateFile($destinationPath, false);

        // Check maximum row limit
        if ($report['summary']['total_rows'] > self::MAX_ROWS) {
            @unlink($destinationPath);
            throw new \InvalidArgumentException("The CSV file exceeds the maximum limit of " . number_format(self::MAX_ROWS) . " rows (" . number_format($report['summary']['total_rows']) . " rows found). Please split your file into smaller batches.");
        }

        // 5. Store session snapshot in Cache for commit validation
        $sessionData = [
            'import_token' => $importToken,
            'filename' => $file->getClientOriginalName(),
            'user_id' => $user->id,
            'created_at' => now()->toIso8601String(),
            'timestamps' => $report['timestamps'], // Snapshot for concurrency stale detection
            'can_commit' => $report['can_commit'],
            'summary' => $report['summary'],
            'full_replacement' => !empty($options['full_replacement']),
        ];
        Cache::put("catalog_import_{$importToken}", $sessionData, now()->addMinutes(self::SESSION_TTL_MINUTES));

        return [
            'import_token' => $importToken,
            'filename' => $file->getClientOriginalName(),
            'summary' => $report['summary'],
            'rows' => $report['rows'],
            'can_commit' => $report['can_commit'],
            'notice' => 'Stock Quantity is read-only during catalog import to protect inventory ledger and FIFO cost layers.',
        ];
    }

    /**
     * Commit the validated import transactionally.
     */
    public function commitImport(string $importToken, User $user, bool $fullReplacement = false): array
    {
        $storageDir = self::getImportStorageDir();
        $filePath = $storageDir . DIRECTORY_SEPARATOR . $importToken . '.csv';

        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("Import session has expired or the file was not found. Please upload and re-validate your CSV.");
        }

        $sessionData = Cache::get("catalog_import_{$importToken}");
        $expectedTimestamps = $sessionData['timestamps'] ?? [];
        $isFullReplacement = $fullReplacement || !empty($sessionData['full_replacement']);

        return DB::transaction(function () use ($filePath, $importToken, $user, $expectedTimestamps, $sessionData, $isFullReplacement) {
            // 1. Re-validate the entire file inside the transaction
            $report = $this->parseAndValidateFile($filePath, true, $expectedTimestamps);

            if (!$report['can_commit'] || $report['summary']['errors_count'] > 0) {
                $firstError = !empty($report['errors']) ? $report['errors'][0] : 'Validation errors detected during commit.';
                throw new \RuntimeException("Cannot commit import: " . $firstError);
            }

            $productsCreated = 0;
            $productsUpdated = 0;
            $productsUnchanged = 0;
            $variantsCreated = 0;
            $variantsUpdated = 0;

            // 2. Process rows
            foreach ($report['valid_rows'] as $rowData) {
                $action = strtoupper($rowData['action']);

                if ($action === 'CREATE') {
                    $createdResult = $this->executeCreateProduct($rowData['data']);
                    $productsCreated++;
                    $variantsCreated += $createdResult['variants_created'];
                } elseif ($action === 'UPDATE') {
                    $rowFullReplacement = !empty($rowData['data']['full_replacement']) || $isFullReplacement;
                    $updatedResult = $this->executeUpdateProduct($rowData['product'], $rowData['data'], $rowFullReplacement);
                    $productsUpdated++;
                    $variantsCreated += $updatedResult['variants_created'];
                    $variantsUpdated += $updatedResult['variants_updated'];
                } elseif ($action === 'UNCHANGED') {
                    $productsUnchanged++;
                }
            }

            // 3. Write Audit Log entry
            $summary = [
                'import_token' => $importToken,
                'filename' => $sessionData['filename'] ?? 'catalog.csv',
                'rows_processed' => $report['summary']['total_rows'],
                'products_created' => $productsCreated,
                'products_updated' => $productsUpdated,
                'products_unchanged' => $productsUnchanged,
                'variants_created' => $variantsCreated,
                'variants_updated' => $variantsUpdated,
                'creates' => $productsCreated,
                'updates' => $productsUpdated,
                'unchanged' => $productsUnchanged,
                'committed_at' => now()->toIso8601String(),
            ];

            AuditLog::log(
                $user,
                'product_import',
                'Product',
                null,
                "Imported catalog CSV: {$productsCreated} created, {$productsUpdated} updated, {$productsUnchanged} unchanged, {$variantsCreated} variants created, {$variantsUpdated} variants updated",
                null,
                array_merge($summary, ['commit_result' => 'completed'])
            );

            // 4. Clean up temporary file and cache
            @unlink($filePath);
            Cache::forget("catalog_import_{$importToken}");

            return array_merge([
                'status' => 'success',
                'message' => 'Catalog imported successfully.',
                'summary' => $summary,
            ], $summary);
        });
    }

    /**
     * Parse and validate CSV file contents.
     */
    protected function parseAndValidateFile(string $filePath, bool $isCommit = false, array $expectedTimestamps = []): array
    {
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            throw new \RuntimeException("Unable to open the CSV file for reading.");
        }

        // 1. Read and normalize header row
        $rawHeaders = fgetcsv($handle);
        if ($rawHeaders === false || empty($rawHeaders)) {
            fclose($handle);
            throw new \InvalidArgumentException("The CSV file is empty or malformed.");
        }

        // Normalize UTF-8 BOM on first column if present
        $bom = chr(0xEF) . chr(0xBB) . chr(0xBF);
        if (isset($rawHeaders[0]) && str_starts_with($rawHeaders[0], $bom)) {
            $rawHeaders[0] = substr($rawHeaders[0], strlen($bom));
        }

        $headerMap = [];
        $duplicateHeaders = [];
        foreach ($rawHeaders as $index => $col) {
            $normalized = strtolower(trim((string)$col));
            if ($normalized === '') continue;

            if (isset($headerMap[$normalized])) {
                $duplicateHeaders[] = $col;
            } else {
                $headerMap[$normalized] = $index;
            }
        }

        if (!empty($duplicateHeaders)) {
            fclose($handle);
            throw new \InvalidArgumentException("Duplicate column header detected: " . implode(', ', array_unique($duplicateHeaders)));
        }

        // Check required columns
        $requiredColumns = ['sku', 'name', 'price', 'category'];
        $missingColumns = [];
        foreach ($requiredColumns as $req) {
            if (!isset($headerMap[$req])) {
                $missingColumns[] = strtoupper($req);
            }
        }

        if (!empty($missingColumns)) {
            fclose($handle);
            throw new \InvalidArgumentException("Missing required column(s): " . implode(', ', $missingColumns));
        }

        // 2. Pre-cache categories and brands to avoid N+1 queries during validation
        $categoriesByName = Category::all()->keyBy(fn($c) => strtolower(trim($c->name)));
        $categoriesById = Category::all()->keyBy('id');
        $brandsByName = Brand::all()->keyBy(fn($b) => strtolower(trim($b->name)));
        $brandsById = Brand::all()->keyBy('id');

        $rowsPreview = [];
        $validRows = [];
        $rowErrorsList = [];
        $timestamps = [];
        $seenCsvSkus = [];
        $seenVariantSkus = [];

        $totalRows = 0;
        $creates = 0;
        $updates = 0;
        $unchanged = 0;
        $errorsCount = 0;

        $rowNumber = 1; // Row 1 was header

        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;

            // Skip empty rows
            if (empty(array_filter($row, fn($v) => trim((string)$v) !== ''))) {
                continue;
            }

            $totalRows++;
            $rowErrors = [];

            // Extract fields using headerMap with fallback
            $getField = function (string $key) use ($row, $headerMap) {
                if (isset($headerMap[$key]) && isset($row[$headerMap[$key]])) {
                    $val = trim((string)$row[$headerMap[$key]]);
                    // Unescape single quote from CSV formula protection if present
                    if (preg_match("/^'([=\+\-@\t\r])/", $val)) {
                        $val = substr($val, 1);
                    }
                    return $val;
                }
                return null;
            };

            $sku = $getField('sku');
            $name = $getField('name');
            $slug = $getField('slug');
            $description = $getField('description');
            $shortDescription = $getField('short description') ?? $getField('short_description');
            $categoryVal = $getField('category') ?? $getField('category_id');
            $brandVal = $getField('brand') ?? $getField('brand_id');
            $priceVal = $getField('price');
            $compareAtPriceVal = $getField('compare at price') ?? $getField('compare_at_price');
            $costPriceVal = $getField('cost price') ?? $getField('cost_price');
            $isActiveVal = $getField('is active') ?? $getField('is_active');
            $isFeaturedVal = $getField('is featured') ?? $getField('is_featured');
            $isNewArrivalVal = $getField('is new arrival') ?? $getField('is_new_arrival');
            $isBestSellerVal = $getField('is best seller') ?? $getField('is_best_seller');
            $primaryImageUrl = $getField('primary image url') ?? $getField('primary_image_url');
            $additionalImagesJson = $getField('additional image urls') ?? $getField('additional_image_urls');
            $variantSummaryJson = $getField('variant summary') ?? $getField('variant_summary');
            $optionsVal = $getField('options') ?? $getField('product options') ?? $getField('product_options');
            $replaceVariantsVal = $getField('replace variants') ?? $getField('replace_variants') ?? $getField('full_replacement');

            // --- SKU Validation ---
            if (empty($sku)) {
                $rowErrors[] = "Row {$rowNumber}: SKU is required.";
            } else {
                if (isset($seenCsvSkus[$sku])) {
                    $rowErrors[] = "Row {$rowNumber}: Duplicate SKU '{$sku}' in the import file (first seen on row {$seenCsvSkus[$sku]}).";
                } else {
                    $seenCsvSkus[$sku] = $rowNumber;
                }
            }

            // --- Name Validation ---
            if (empty($name)) {
                $rowErrors[] = "Row {$rowNumber}: Name is required.";
            } elseif (mb_strlen($name) > 255) {
                $rowErrors[] = "Row {$rowNumber}: Name cannot exceed 255 characters.";
            }

            // --- Price Validation ---
            if ($priceVal === null || $priceVal === '') {
                $rowErrors[] = "Row {$rowNumber}: Price is required.";
            } elseif (!is_numeric($priceVal) || (float)$priceVal < 0) {
                $rowErrors[] = "Row {$rowNumber}: Price must be a valid non-negative number.";
            }

            // --- Compare At Price Validation ---
            $parsedCompareAtPrice = null;
            if ($compareAtPriceVal !== null && $compareAtPriceVal !== '') {
                if (!is_numeric($compareAtPriceVal)) {
                    $rowErrors[] = "Row {$rowNumber}: Compare At Price must be numeric.";
                } elseif ((float)$compareAtPriceVal < 0) {
                    $rowErrors[] = "Row {$rowNumber}: Compare At Price cannot be negative.";
                } else {
                    $parsedCompareAtPrice = (float)$compareAtPriceVal;
                }
            }

            // --- Cost Price Validation ---
            $parsedCostPrice = null;
            if ($costPriceVal !== null && $costPriceVal !== '') {
                if (!is_numeric($costPriceVal)) {
                    $rowErrors[] = "Row {$rowNumber}: Cost Price must be numeric.";
                } elseif ((float)$costPriceVal < 0) {
                    $rowErrors[] = "Row {$rowNumber}: Cost Price cannot be negative.";
                } else {
                    $parsedCostPrice = (float)$costPriceVal;
                }
            }

            // --- Category Resolution ---
            $resolvedCategory = null;
            if (empty($categoryVal)) {
                $rowErrors[] = "Row {$rowNumber}: Category is required.";
            } else {
                if (is_numeric($categoryVal) && $categoriesById->has((int)$categoryVal)) {
                    $resolvedCategory = $categoriesById->get((int)$categoryVal);
                } else {
                    $normCat = strtolower(trim($categoryVal));
                    if ($categoriesByName->has($normCat)) {
                        $resolvedCategory = $categoriesByName->get($normCat);
                    } else {
                        $rowErrors[] = "Row {$rowNumber}: Category \"{$categoryVal}\" was not found.";
                    }
                }
            }

            // --- Brand Resolution ---
            $resolvedBrand = null;
            if (!empty($brandVal)) {
                if (is_numeric($brandVal) && $brandsById->has((int)$brandVal)) {
                    $resolvedBrand = $brandsById->get((int)$brandVal);
                } else {
                    $normBrand = strtolower(trim($brandVal));
                    if ($brandsByName->has($normBrand)) {
                        $resolvedBrand = $brandsByName->get($normBrand);
                    } else {
                        $rowErrors[] = "Row {$rowNumber}: Brand \"{$brandVal}\" was not found.";
                    }
                }
            }

            // --- Booleans Validation ---
            $parseBool = function ($val, string $fieldName, bool $default = false) use (&$rowErrors, $rowNumber) {
                if ($val === null || $val === '') return $default;
                if ($val === true || $val === 1) return true;
                if ($val === false || $val === 0) return false;
                $low = strtolower(trim((string)$val));
                if (in_array($low, ['1', 'true', 'yes', 'y'], true)) return true;
                if (in_array($low, ['0', 'false', 'no', 'n'], true)) return false;
                $rowErrors[] = "Row {$rowNumber}: Invalid boolean value for {$fieldName} (accepted: 1/0 or true/false).";
                return $default;
            };

            $isActive = $parseBool($isActiveVal, 'Is Active', true);
            $isFeatured = $parseBool($isFeaturedVal, 'Is Featured', false);
            $isNewArrival = $parseBool($isNewArrivalVal, 'Is New Arrival', false);
            $isBestSeller = $parseBool($isBestSellerVal, 'Is Best Seller', false);
            $isFullReplacementRow = $parseBool($replaceVariantsVal, 'Replace Variants', false);

            // Check existing product in DB early
            $existingProduct = null;
            if (!empty($sku)) {
                $existingProduct = Product::with(['category', 'brandRelation', 'variants', 'images'])
                    ->where('sku', $sku)
                    ->first();
            }

            // --- Options Validation ---
            $parsedOptions = $this->parseProductOptions($optionsVal, $rowNumber, $rowErrors);

            // --- Variants Validation ---
            $parsedVariants = [];
            if (!empty($variantSummaryJson)) {
                $decodedVariants = json_decode($variantSummaryJson, true);
                if (json_last_error() !== JSON_ERROR_NONE || !is_array($decodedVariants)) {
                    $rowErrors[] = "Row {$rowNumber}: Variant Summary must be a valid JSON array.";
                } else {
                    // If options are not explicitly declared in CSV, infer from variants or inherit from existing product
                    if ($parsedOptions === null) {
                        $inferred = $this->inferOptionsFromRawVariants($decodedVariants);
                        if ($inferred !== null) {
                            $parsedOptions = $inferred;
                        } elseif ($existingProduct && !empty($existingProduct->options)) {
                            $parsedOptions = $existingProduct->options;
                        }
                    }

                    $isDynamic = !empty($parsedOptions);
                    $seenCombinations = [];

                    foreach ($decodedVariants as $vIdx => $v) {
                        $vNumber = $vIdx + 1;
                        if (!is_array($v)) {
                            $rowErrors[] = "Row {$rowNumber}: Variant #{$vNumber} must be an object.";
                            continue;
                        }

                        $vId = isset($v['id']) && is_numeric($v['id']) ? (int)$v['id'] : null;
                        if ($vId && $existingProduct) {
                            if (!$existingProduct->variants->contains('id', $vId)) {
                                $rowErrors[] = "Row {$rowNumber}: Variant ID {$vId} does not belong to product '{$sku}'.";
                            }
                        }

                        $vName = trim((string)($v['name'] ?? ''));
                        $vColor = trim((string)($v['color_name'] ?? ($v['color'] ?? '')));
                        $vSize = trim((string)($v['size'] ?? ''));
                        $vSku = trim((string)($v['sku'] ?? ''));
                        $rawAttrs = $v['attributes'] ?? ($v['variant_attributes'] ?? null);
                        $vAttributes = $this->normalizeVariantAttributes($rawAttrs);

                        if ($rawAttrs !== null && $vAttributes === null) {
                            $rowErrors[] = "Row {$rowNumber}: Variant #{$vNumber} has invalid attributes format.";
                        }

                        // For dynamic variants, attributes are required
                        if ($isDynamic) {
                            if (empty($vAttributes)) {
                                $rowErrors[] = "Row {$rowNumber}: Variant #{$vNumber} ('{$vName}') is missing attributes for dynamic options.";
                            } else {
                                // Validate each declared option group
                                foreach ($parsedOptions as $optGroup) {
                                    $grpName = $optGroup['name'];
                                    $grpNameLower = strtolower($grpName);
                                    $allowedVals = array_map('strtolower', $optGroup['values']);

                                    // Find attribute key case-insensitively
                                    $foundVal = null;
                                    foreach ($vAttributes as $ak => $av) {
                                        if (strtolower(trim((string)$ak)) === $grpNameLower) {
                                            $foundVal = trim((string)$av);
                                            break;
                                        }
                                    }

                                    if ($foundVal === null || $foundVal === '') {
                                        $rowErrors[] = "Row {$rowNumber}: Variant #{$vNumber} ('{$vName}') missing attribute '{$grpName}'.";
                                    } elseif (!in_array(strtolower($foundVal), $allowedVals, true)) {
                                        $rowErrors[] = "Row {$rowNumber}: Invalid value '{$foundVal}' for option group '{$grpName}'. Allowed values: " . implode(', ', $optGroup['values']) . ".";
                                    }
                                }

                                // Check for unknown attributes
                                $declaredGroupNamesLower = array_map(fn($g) => strtolower($g['name']), $parsedOptions);
                                foreach ($vAttributes as $ak => $av) {
                                    if (!in_array(strtolower(trim((string)$ak)), $declaredGroupNamesLower, true)) {
                                        $rowErrors[] = "Row {$rowNumber}: Unknown attribute '{$ak}' for variant #{$vNumber}. Only declared options are allowed.";
                                    }
                                }
                            }
                        }

                        if ($vName === '' && $vColor === '' && $vSize === '' && empty($vAttributes)) {
                            $rowErrors[] = "Row {$rowNumber}: Variant #{$vNumber} must have at least a name, color, size, or attributes.";
                        }

                        // Combination uniqueness check
                        $comboKey = $this->getCanonicalVariantKey([
                            'attributes' => $vAttributes,
                            'color_name' => $vColor,
                            'size' => $vSize,
                            'name' => $vName,
                        ], $isDynamic);

                        if ($comboKey !== '') {
                            if (isset($seenCombinations[$comboKey])) {
                                $comboDisplay = $vAttributes ? implode(' / ', $vAttributes) : ($vColor . ($vSize ? ' / ' . $vSize : ''));
                                $rowErrors[] = "Row {$rowNumber}: Duplicate variant combination '{$comboDisplay}' in variant list.";
                            }
                            $seenCombinations[$comboKey] = true;
                        }

                        // Variant SKU uniqueness check
                        if ($vSku !== '') {
                            if (isset($seenVariantSkus[$vSku])) {
                                $rowErrors[] = "Row {$rowNumber}: Duplicate variant SKU '{$vSku}' across the import file (first seen on row {$seenVariantSkus[$vSku]}).";
                            } else {
                                $seenVariantSkus[$vSku] = $rowNumber;
                            }
                        }

                        // Direct price validation
                        $vPrice = null;
                        if (isset($v['price']) && $v['price'] !== '' && $v['price'] !== null) {
                            if (!is_numeric($v['price'])) {
                                $rowErrors[] = "Row {$rowNumber}: Variant '{$vName}' price must be numeric.";
                            } elseif ((float)$v['price'] < 0) {
                                $rowErrors[] = "Row {$rowNumber}: Variant '{$vName}' price cannot be negative.";
                            } else {
                                $vPrice = (float)$v['price'];
                            }
                        }

                        // Numeric validation for variant price modifier & cost
                        $vPriceModifier = 0.00;
                        if (isset($v['price_modifier']) && $v['price_modifier'] !== '' && $v['price_modifier'] !== null) {
                            if (!is_numeric($v['price_modifier'])) {
                                $rowErrors[] = "Row {$rowNumber}: Variant '{$vName}' price modifier must be numeric.";
                            } elseif ((float)$v['price_modifier'] < 0) {
                                $rowErrors[] = "Row {$rowNumber}: Variant '{$vName}' price modifier cannot be negative.";
                            } else {
                                $vPriceModifier = (float)$v['price_modifier'];
                            }
                        }

                        $vCostPrice = null;
                        if (isset($v['cost_price']) && $v['cost_price'] !== '' && $v['cost_price'] !== null) {
                            if (!is_numeric($v['cost_price'])) {
                                $rowErrors[] = "Row {$rowNumber}: Variant '{$vName}' cost price must be numeric.";
                            } elseif ((float)$v['cost_price'] < 0) {
                                $rowErrors[] = "Row {$rowNumber}: Variant '{$vName}' cost price cannot be negative.";
                            } else {
                                $vCostPrice = (float)$v['cost_price'];
                            }
                        }

                        // Stock quantity validation
                        $vStock = null;
                        if (isset($v['stock_quantity']) && $v['stock_quantity'] !== '' && $v['stock_quantity'] !== null) {
                            if (!is_numeric($v['stock_quantity'])) {
                                $rowErrors[] = "Row {$rowNumber}: Variant '{$vName}' stock quantity must be numeric.";
                            } elseif ((int)$v['stock_quantity'] < 0) {
                                $rowErrors[] = "Row {$rowNumber}: Variant '{$vName}' stock quantity cannot be negative.";
                            } else {
                                $vStock = (int)$v['stock_quantity'];
                            }
                        }

                        // Active boolean validation
                        $vIsActive = true;
                        if (isset($v['is_active']) && $v['is_active'] !== '' && $v['is_active'] !== null) {
                            $vIsActive = $parseBool($v['is_active'], "Variant '{$vName}' is_active", true);
                        }

                        $autoName = $vName ?: ($vAttributes ? implode(' / ', array_values($vAttributes)) : ($vColor ? ($vColor . ($vSize ? ' / ' . $vSize : '')) : ($vSize ?: 'Standard Option')));

                        $parsedVariants[] = [
                            'id' => $vId,
                            'sku' => $vSku ?: null,
                            'name' => $autoName,
                            'color_name' => $vColor ?: ($vAttributes['Color'] ?? $vAttributes['color'] ?? null),
                            'color_hex' => !empty($v['color_hex']) ? trim((string)$v['color_hex']) : null,
                            'size' => $vSize ?: ($vAttributes['Size'] ?? $vAttributes['size'] ?? null),
                            'price' => $vPrice,
                            'price_modifier' => $vPriceModifier,
                            'cost_price' => $vCostPrice,
                            'stock_quantity' => $vStock,
                            'barcode' => !empty($v['barcode']) ? trim((string)$v['barcode']) : null,
                            'attributes' => $vAttributes,
                            'image_id' => !empty($v['image_id']) && is_numeric($v['image_id']) ? (int)$v['image_id'] : null,
                            'image_url' => !empty($v['image_url']) ? trim((string)$v['image_url']) : null,
                            'is_active' => $vIsActive,
                        ];
                    }
                }
            }

            // --- Media URLs Validation ---
            if (!empty($primaryImageUrl)) {
                if (!filter_var($primaryImageUrl, FILTER_VALIDATE_URL) && !str_starts_with($primaryImageUrl, '/')) {
                    $rowErrors[] = "Row {$rowNumber}: Primary Image URL is not a valid URL or path.";
                }
            }

            $parsedAdditionalImages = [];
            if (!empty($additionalImagesJson)) {
                $decodedImgs = json_decode($additionalImagesJson, true);
                if (json_last_error() !== JSON_ERROR_NONE || !is_array($decodedImgs)) {
                    $rowErrors[] = "Row {$rowNumber}: Additional Image URLs must be a valid JSON array.";
                } else {
                    foreach ($decodedImgs as $img) {
                        if (is_array($img) && !empty($img['url'])) {
                            $parsedAdditionalImages[] = [
                                'url' => $img['url'],
                                'color_name' => $img['color_name'] ?? null,
                                'alt_text' => $img['alt_text'] ?? null,
                                'display_order' => (int)($img['display_order'] ?? 0),
                            ];
                        }
                    }
                }
            }

            // --- Classification & Change Diff ---
            if (!empty($rowErrors)) {
                $errorsCount++;
                $rowErrorsList = array_merge($rowErrorsList, $rowErrors);
                $rowsPreview[] = [
                    'row_number' => $rowNumber,
                    'sku' => $sku ?? '(empty)',
                    'name' => $name ?? '(empty)',
                    'action' => 'ERROR',
                    'diff' => null,
                    'changes' => null,
                    'errors' => $rowErrors,
                ];
                continue;
            }

            if (!$existingProduct) {
                // CREATE
                $creates++;
                $cleanData = [
                    'sku' => $sku,
                    'name' => $name,
                    'slug' => $slug ?: (Str::slug($name) . '-' . Str::random(4)),
                    'description' => $description ?? '',
                    'short_description' => $shortDescription,
                    'category_id' => $resolvedCategory->id,
                    'category_name' => $resolvedCategory->name,
                    'brand_id' => $resolvedBrand?->id,
                    'brand_name' => $resolvedBrand?->name ?? 'AETHER Studio',
                    'price' => (float)$priceVal,
                    'compare_at_price' => $parsedCompareAtPrice,
                    'cost_price' => $parsedCostPrice,
                    'is_active' => $isActive,
                    'is_featured' => $isFeatured,
                    'is_new_arrival' => $isNewArrival,
                    'is_best_seller' => $isBestSeller,
                    'primary_image_url' => $primaryImageUrl,
                    'additional_images' => $parsedAdditionalImages,
                    'options' => $parsedOptions,
                    'variants' => $parsedVariants,
                ];

                $validRows[] = [
                    'action' => 'CREATE',
                    'data' => $cleanData,
                ];

                $rowsPreview[] = [
                    'row_number' => $rowNumber,
                    'sku' => $sku,
                    'name' => $name,
                    'action' => 'CREATE',
                    'diff' => null,
                    'changes' => null,
                    'data' => [
                        'price' => number_format((float)$priceVal, 2),
                        'category' => $resolvedCategory->name,
                        'brand' => $resolvedBrand?->name ?? 'AETHER Studio',
                        'variants_count' => count($parsedVariants),
                        'is_active' => $isActive,
                    ],
                    'errors' => [],
                ];
            } else {
                // UPDATE / UNCHANGED
                // Stale detection during commit:
                if ($isCommit && isset($expectedTimestamps[$sku])) {
                    $expectedTs = $expectedTimestamps[$sku];
                    if ($existingProduct->updated_at->timestamp > $expectedTs) {
                        $staleError = "Row {$rowNumber}: Product SKU '{$sku}' has been modified by another administrator after validation.";
                        $errorsCount++;
                        $rowErrorsList[] = $staleError;
                        $rowsPreview[] = [
                            'row_number' => $rowNumber,
                            'sku' => $sku,
                            'name' => $name,
                            'action' => 'ERROR',
                            'diff' => null,
                            'changes' => null,
                            'errors' => [$staleError],
                        ];
                        continue;
                    }
                }

                $timestamps[$sku] = $existingProduct->updated_at->timestamp;

                // Compute field-by-field diff
                $diff = [];

                if ($existingProduct->name !== $name) {
                    $diff['name'] = [
                        'old' => $existingProduct->name,
                        'new' => $name,
                        'from' => $existingProduct->name,
                        'to' => $name,
                    ];
                }

                if ((float)$existingProduct->price !== (float)$priceVal) {
                    $oldP = number_format((float)$existingProduct->price, 2, '.', '');
                    $newP = number_format((float)$priceVal, 2, '.', '');
                    $diff['price'] = [
                        'old' => $oldP,
                        'new' => $newP,
                        'from' => $oldP,
                        'to' => $newP,
                    ];
                }

                $currentCap = $existingProduct->compare_at_price !== null ? (float)$existingProduct->compare_at_price : null;
                if ($currentCap !== $parsedCompareAtPrice) {
                    $oldCap = $currentCap !== null ? number_format($currentCap, 2, '.', '') : '(none)';
                    $newCap = $parsedCompareAtPrice !== null ? number_format($parsedCompareAtPrice, 2, '.', '') : '(none)';
                    $diff['compare_at_price'] = [
                        'old' => $oldCap,
                        'new' => $newCap,
                        'from' => $oldCap,
                        'to' => $newCap,
                    ];
                }

                $currentCost = $existingProduct->cost_price !== null ? (float)$existingProduct->cost_price : null;
                if ($currentCost !== $parsedCostPrice) {
                    $oldCost = $currentCost !== null ? number_format($currentCost, 2, '.', '') : '(none)';
                    $newCost = $parsedCostPrice !== null ? number_format($parsedCostPrice, 2, '.', '') : '(none)';
                    $diff['cost_price'] = [
                        'old' => $oldCost,
                        'new' => $newCost,
                        'from' => $oldCost,
                        'to' => $newCost,
                    ];
                }

                if ($existingProduct->category_id !== $resolvedCategory->id) {
                    $oldCat = $existingProduct->category?->name ?? '(none)';
                    $newCat = $resolvedCategory->name;
                    $diff['category'] = [
                        'old' => $oldCat,
                        'new' => $newCat,
                        'from' => $oldCat,
                        'to' => $newCat,
                    ];
                }

                if ($brandVal !== '' && $brandVal !== null) {
                    $resolvedBrandName = $resolvedBrand?->name ?? $brandVal;
                    $currentBrand = $existingProduct->brandRelation?->name ?? $existingProduct->brand;
                    if ($currentBrand !== $resolvedBrandName) {
                        $diff['brand'] = [
                            'old' => $currentBrand ?? '(none)',
                            'new' => $resolvedBrandName,
                            'from' => $currentBrand ?? '(none)',
                            'to' => $resolvedBrandName,
                        ];
                    }
                }

                if ((bool)$existingProduct->is_active !== $isActive) {
                    $oldAct = $existingProduct->is_active ? 'true' : 'false';
                    $newAct = $isActive ? 'true' : 'false';
                    $diff['is_active'] = [
                        'old' => $oldAct,
                        'new' => $newAct,
                        'from' => $oldAct,
                        'to' => $newAct,
                    ];
                }

                if ((bool)$existingProduct->is_featured !== $isFeatured) {
                    $oldFeat = $existingProduct->is_featured ? 'true' : 'false';
                    $newFeat = $isFeatured ? 'true' : 'false';
                    $diff['is_featured'] = [
                        'old' => $oldFeat,
                        'new' => $newFeat,
                        'from' => $oldFeat,
                        'to' => $newFeat,
                    ];
                }

                if ((bool)$existingProduct->is_new_arrival !== $isNewArrival) {
                    $oldArr = $existingProduct->is_new_arrival ? 'true' : 'false';
                    $newArr = $isNewArrival ? 'true' : 'false';
                    $diff['is_new_arrival'] = [
                        'old' => $oldArr,
                        'new' => $newArr,
                        'from' => $oldArr,
                        'to' => $newArr,
                    ];
                }

                if ((bool)$existingProduct->is_best_seller !== $isBestSeller) {
                    $oldBs = $existingProduct->is_best_seller ? 'true' : 'false';
                    $newBs = $isBestSeller ? 'true' : 'false';
                    $diff['is_best_seller'] = [
                        'old' => $oldBs,
                        'new' => $newBs,
                        'from' => $oldBs,
                        'to' => $newBs,
                    ];
                }

                if ($description !== null && $description !== '' && $existingProduct->description !== $description) {
                    $oldDesc = Str::limit($existingProduct->description ?? '', 30);
                    $newDesc = Str::limit($description, 30);
                    $diff['description'] = [
                        'old' => $oldDesc,
                        'new' => $newDesc,
                        'from' => $oldDesc,
                        'to' => $newDesc,
                    ];
                }

                if ($shortDescription !== null && $shortDescription !== '' && $existingProduct->short_description !== $shortDescription) {
                    $oldSd = Str::limit($existingProduct->short_description ?? '', 30);
                    $newSd = Str::limit($shortDescription, 30);
                    $diff['short_description'] = [
                        'old' => $oldSd,
                        'new' => $newSd,
                        'from' => $oldSd,
                        'to' => $newSd,
                    ];
                }

                // Check options diff
                if ($parsedOptions !== null && json_encode($existingProduct->options) !== json_encode($parsedOptions)) {
                    $diff['options'] = [
                        'old' => $existingProduct->options ? json_encode($existingProduct->options) : '(none)',
                        'new' => json_encode($parsedOptions),
                        'from' => $existingProduct->options ? json_encode($existingProduct->options) : '(none)',
                        'to' => json_encode($parsedOptions),
                    ];
                }

                // Check variants diff
                $hasVariantChanges = false;
                if (!empty($parsedVariants)) {
                    $existingVariants = $existingProduct->variants;
                    if ($existingVariants->count() !== count($parsedVariants)) {
                        $hasVariantChanges = true;
                    } else {
                        foreach ($parsedVariants as $pv) {
                            $match = null;
                            if (!empty($pv['id'])) {
                                $match = $existingVariants->firstWhere('id', $pv['id']);
                            }
                            if (!$match && !empty($pv['sku'])) {
                                $match = $existingVariants->firstWhere('sku', $pv['sku']);
                            }
                            if (!$match) {
                                $k = $this->getCanonicalVariantKey($pv, $isDynamic);
                                $match = $existingVariants->first(function ($ev) use ($k, $isDynamic) {
                                    return $this->getCanonicalVariantKey($ev, $isDynamic) === $k;
                                });
                            }
                            if (!$match 
                                || (float)$match->price_modifier !== (float)$pv['price_modifier']
                                || ($pv['price'] !== null && (float)$match->price !== (float)$pv['price'])
                                || ($pv['is_active'] !== null && (bool)$match->is_active !== (bool)$pv['is_active'])
                                || $match->name !== $pv['name']
                                || ($pv['cost_price'] !== null && (float)$match->cost_price !== (float)$pv['cost_price'])
                                || ($pv['barcode'] !== null && $match->barcode !== $pv['barcode'])
                            ) {
                                $hasVariantChanges = true;
                                break;
                            }
                        }
                    }
                    if ($hasVariantChanges) {
                        $diff['variants'] = [
                            'old' => $existingProduct->variants->count() . ' variant(s)',
                            'new' => count($parsedVariants) . ' variant(s)',
                            'from' => $existingProduct->variants->count() . ' variant(s)',
                            'to' => count($parsedVariants) . ' variant(s)',
                        ];
                    }
                }

                $cleanData = [
                    'sku' => $sku,
                    'name' => $name,
                    'slug' => $slug ?: $existingProduct->slug,
                    'description' => $description !== null ? $description : $existingProduct->description,
                    'short_description' => $shortDescription !== null ? $shortDescription : $existingProduct->short_description,
                    'category_id' => $resolvedCategory->id,
                    'brand_id' => $resolvedBrand?->id ?? $existingProduct->brand_id,
                    'brand' => $resolvedBrand?->name ?? $existingProduct->brand,
                    'price' => (float)$priceVal,
                    'compare_at_price' => $parsedCompareAtPrice,
                    'cost_price' => $parsedCostPrice,
                    'is_active' => $isActive,
                    'is_featured' => $isFeatured,
                    'is_new_arrival' => $isNewArrival,
                    'is_best_seller' => $isBestSeller,
                    'options' => $parsedOptions !== null ? $parsedOptions : $existingProduct->options,
                    'variants' => $parsedVariants,
                    'full_replacement' => $isFullReplacementRow,
                ];

                if (empty($diff)) {
                    $unchanged++;
                    $action = 'UNCHANGED';
                } else {
                    $updates++;
                    $action = 'UPDATE';
                }

                $validRows[] = [
                    'action' => $action,
                    'product' => $existingProduct,
                    'data' => $cleanData,
                ];

                $rowsPreview[] = [
                    'row_number' => $rowNumber,
                    'sku' => $sku,
                    'name' => $name,
                    'action' => $action,
                    'diff' => $diff,
                    'changes' => $diff,
                    'errors' => [],
                ];
            }
        }

        fclose($handle);

        $summary = [
            'total_rows' => $totalRows,
            'creates' => $creates,
            'updates' => $updates,
            'unchanged' => $unchanged,
            'errors' => $errorsCount,
            'errors_count' => $errorsCount,
        ];

        return [
            'summary' => $summary,
            'rows' => $rowsPreview,
            'valid_rows' => $validRows,
            'errors' => $rowErrorsList,
            'timestamps' => $timestamps,
            'can_commit' => ($errorsCount === 0 && $totalRows > 0),
        ];
    }

    /**
     * Create a new product from validated import data.
     */
    protected function executeCreateProduct(array $data): array
    {
        $hasVariants = !empty($data['variants']);

        $product = Product::create([
            'sku' => $data['sku'],
            'name' => $data['name'],
            'slug' => $data['slug'],
            'description' => $data['description'],
            'short_description' => $data['short_description'],
            'category_id' => $data['category_id'],
            'brand_id' => $data['brand_id'],
            'brand' => $data['brand_name'],
            'price' => $data['price'],
            'compare_at_price' => $data['compare_at_price'],
            'cost_price' => $data['cost_price'],
            'stock_quantity' => 0, // Initial stock is 0; derived from variants if present
            'is_active' => $data['is_active'],
            'is_featured' => $data['is_featured'],
            'is_new_arrival' => $data['is_new_arrival'],
            'is_best_seller' => $data['is_best_seller'],
            'rating_average' => 0.00,
            'review_count' => 0,
            'options' => $data['options'] ?? null,
        ]);

        // Attach Primary Image if provided
        if (!empty($data['primary_image_url'])) {
            $product->images()->create([
                'image_url' => $data['primary_image_url'],
                'alt_text' => $product->name,
                'is_primary' => true,
                'display_order' => 0,
            ]);
        }

        // Attach Additional Images if provided
        if (!empty($data['additional_images'])) {
            foreach ($data['additional_images'] as $idx => $img) {
                $product->images()->create([
                    'image_url' => $img['url'],
                    'color_name' => $img['color_name'] ?? null,
                    'alt_text' => $img['alt_text'] ?? $product->name,
                    'is_primary' => false,
                    'display_order' => $img['display_order'] ?? ($idx + 1),
                ]);
            }
        }

        $productImages = $product->images()->get();

        // Create variants if present
        $variantsCreated = 0;
        if ($hasVariants) {
            foreach ($data['variants'] as $v) {
                $vStock = isset($v['stock_quantity']) && $v['stock_quantity'] !== '' && $v['stock_quantity'] !== null
                    ? (int)$v['stock_quantity']
                    : 0;

                $resolvedImageId = $this->resolveVariantImageId($v, $productImages, null);

                $product->variants()->create([
                    'sku' => $v['sku'] ?: ($product->sku . '-' . strtoupper(Str::random(4))),
                    'name' => $v['name'],
                    'color_name' => $v['color_name'],
                    'color_hex' => $v['color_hex'],
                    'size' => $v['size'],
                    'price_modifier' => $v['price_modifier'],
                    'price' => $v['price'],
                    'cost_price' => $v['cost_price'],
                    'barcode' => $v['barcode'],
                    'stock_quantity' => $vStock,
                    'is_active' => $v['is_active'] ?? true,
                    'attributes' => $v['attributes'] ?? null,
                    'image_id' => $resolvedImageId,
                ]);
                $variantsCreated++;
            }
            $product->syncStockFromVariants();
        }

        return ['variants_created' => $variantsCreated];
    }

    /**
     * Update an existing product from validated import data.
     * Preserves existing product IDs and existing variant IDs.
     */
    protected function executeUpdateProduct(Product $product, array $data, bool $isFullReplacement = false): array
    {
        $product->update([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'description' => $data['description'],
            'short_description' => $data['short_description'],
            'category_id' => $data['category_id'],
            'brand_id' => $data['brand_id'],
            'brand' => $data['brand'],
            'price' => $data['price'],
            'compare_at_price' => $data['compare_at_price'],
            'cost_price' => $data['cost_price'],
            'is_active' => $data['is_active'],
            'is_featured' => $data['is_featured'],
            'is_new_arrival' => $data['is_new_arrival'],
            'is_best_seller' => $data['is_best_seller'],
            'options' => array_key_exists('options', $data) ? $data['options'] : $product->options,
        ]);

        $variantsCreated = 0;
        $variantsUpdated = 0;

        // Reconcile variants if present in CSV
        if (!empty($data['variants'])) {
            $existingVariants = $product->variants()->get()->keyBy('id');
            $keptIds = [];
            $isDynamic = !empty($data['options']) && is_array($data['options']) && count($data['options']) > 0;
            $productImages = $product->images()->get();

            // Pass 1: Match by explicit ID if provided
            foreach ($data['variants'] as $v) {
                if (!empty($v['id']) && $existingVariants->has((int)$v['id'])) {
                    $matched = $existingVariants->get((int)$v['id']);
                    $vStock = isset($v['stock_quantity']) && $v['stock_quantity'] !== '' && $v['stock_quantity'] !== null
                        ? (int)$v['stock_quantity']
                        : $matched->stock_quantity;

                    $resolvedImageId = $this->resolveVariantImageId($v, $productImages, $matched->image_id);

                    $matched->update([
                        'name' => $v['name'],
                        'color_name' => $v['color_name'],
                        'color_hex' => $v['color_hex'] ?? $matched->color_hex,
                        'size' => $v['size'],
                        'price_modifier' => $v['price_modifier'],
                        'price' => $v['price'],
                        'cost_price' => $v['cost_price'] ?? $matched->cost_price,
                        'barcode' => $v['barcode'] ?? $matched->barcode,
                        'stock_quantity' => $vStock,
                        'is_active' => $v['is_active'] ?? true,
                        'attributes' => $v['attributes'] ?? $matched->attributes,
                        'image_id' => $resolvedImageId,
                        'sku' => $v['sku'] ?: $matched->sku,
                    ]);

                    $keptIds[] = $matched->id;
                    $variantsUpdated++;
                }
            }

            // Pass 2: Match remaining variants by SKU, combination key, or name
            $unmatchedExisting = $existingVariants->except($keptIds);

            foreach ($data['variants'] as $v) {
                if (!empty($v['id']) && in_array((int)$v['id'], $keptIds, true)) {
                    continue; // Handled in pass 1
                }

                $matched = null;
                if (!empty($v['sku'])) {
                    $matched = $unmatchedExisting->firstWhere('sku', $v['sku']);
                }

                if (!$matched) {
                    $incomingKey = $this->getCanonicalVariantKey($v, $isDynamic);
                    if ($incomingKey !== '') {
                        foreach ($unmatchedExisting as $candidate) {
                            $candidateKey = $this->getCanonicalVariantKey($candidate, $isDynamic);
                            if ($candidateKey !== '' && $candidateKey === $incomingKey) {
                                $matched = $candidate;
                                break;
                            }
                        }
                    }
                }

                if (!$matched && !empty($v['name'])) {
                    $matched = $unmatchedExisting->firstWhere('name', $v['name']);
                }

                if ($matched) {
                    $vStock = isset($v['stock_quantity']) && $v['stock_quantity'] !== '' && $v['stock_quantity'] !== null
                        ? (int)$v['stock_quantity']
                        : $matched->stock_quantity;

                    $resolvedImageId = $this->resolveVariantImageId($v, $productImages, $matched->image_id);

                    $matched->update([
                        'name' => $v['name'],
                        'color_name' => $v['color_name'],
                        'color_hex' => $v['color_hex'] ?? $matched->color_hex,
                        'size' => $v['size'],
                        'price_modifier' => $v['price_modifier'],
                        'price' => $v['price'],
                        'cost_price' => $v['cost_price'] ?? $matched->cost_price,
                        'barcode' => $v['barcode'] ?? $matched->barcode,
                        'stock_quantity' => $vStock,
                        'is_active' => $v['is_active'] ?? true,
                        'attributes' => $v['attributes'] ?? $matched->attributes,
                        'image_id' => $resolvedImageId,
                        'sku' => $v['sku'] ?: $matched->sku,
                    ]);

                    $keptIds[] = $matched->id;
                    $unmatchedExisting->forget($matched->id);
                    $variantsUpdated++;
                } else {
                    $vStock = isset($v['stock_quantity']) && $v['stock_quantity'] !== '' && $v['stock_quantity'] !== null
                        ? (int)$v['stock_quantity']
                        : 0;

                    $resolvedImageId = $this->resolveVariantImageId($v, $productImages, null);

                    $newV = $product->variants()->create([
                        'sku' => $v['sku'] ?: ($product->sku . '-' . strtoupper(Str::random(4))),
                        'name' => $v['name'],
                        'color_name' => $v['color_name'],
                        'color_hex' => $v['color_hex'],
                        'size' => $v['size'],
                        'price_modifier' => $v['price_modifier'],
                        'price' => $v['price'],
                        'cost_price' => $v['cost_price'],
                        'barcode' => $v['barcode'],
                        'stock_quantity' => $vStock,
                        'is_active' => $v['is_active'] ?? true,
                        'attributes' => $v['attributes'] ?? null,
                        'image_id' => $resolvedImageId,
                    ]);

                    $keptIds[] = $newV->id;
                    $variantsCreated++;
                }
            }

            // Safe soft deactivation ONLY for complete replacement imports
            if ($isFullReplacement) {
                $omitted = $existingVariants->except($keptIds);
                foreach ($omitted as $ov) {
                    $ov->update(['is_active' => false]);
                }
            }

            // Sync parent product stock from variants
            $product->syncStockFromVariants();
        }

        return [
            'variants_created' => $variantsCreated,
            'variants_updated' => $variantsUpdated,
        ];
    }

    /**
     * Parse and validate product options definition from CSV.
     * Supports:
     * 1. JSON string: [{"name":"Color","values":["Black","White"]}] or {"Color":["Black","White"]}
     * 2. Human-readable string: "Color: Black, White | Storage: 128GB, 256GB"
     */
    protected function parseProductOptions(?string $rawOptions, int $rowNumber, array &$rowErrors): ?array
    {
        if ($rawOptions === null || trim($rawOptions) === '') {
            return null;
        }

        $trimmed = trim($rawOptions);

        // Try JSON decode first
        if (str_starts_with($trimmed, '[') || str_starts_with($trimmed, '{')) {
            $decoded = json_decode($trimmed, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                // If format is associative array {"Color": ["Black", "White"]}
                if (!empty($decoded) && !isset($decoded[0])) {
                    $normalized = [];
                    foreach ($decoded as $optName => $vals) {
                        $normalized[] = [
                            'name' => trim((string)$optName),
                            'values' => is_array($vals) ? array_values(array_map('trim', $vals)) : array_map('trim', explode(',', (string)$vals)),
                        ];
                    }
                    $decoded = $normalized;
                }

                // Validate each option group
                $seenNames = [];
                $validGroups = [];
                foreach ($decoded as $grp) {
                    if (!is_array($grp) || empty($grp['name']) || empty($grp['values']) || !is_array($grp['values'])) {
                        $rowErrors[] = "Row {$rowNumber}: Malformed options JSON structure. Each group must contain 'name' and non-empty 'values' array.";
                        return null;
                    }
                    $name = trim((string)$grp['name']);
                    $lowerName = strtolower($name);
                    if (isset($seenNames[$lowerName])) {
                        $rowErrors[] = "Row {$rowNumber}: Duplicate option group '{$name}'.";
                        return null;
                    }
                    $seenNames[$lowerName] = true;

                    $values = array_values(array_unique(array_filter(array_map(fn($v) => trim((string)$v), $grp['values']), fn($v) => $v !== '')));
                    if (empty($values)) {
                        $rowErrors[] = "Row {$rowNumber}: Option group '{$name}' must contain at least one non-empty value.";
                        return null;
                    }
                    $validGroups[] = [
                        'name' => $name,
                        'values' => $values,
                    ];
                }
                return $validGroups;
            }
        }

        // Parse human-readable delimited format: "Color: Black, White | Storage: 128GB, 256GB"
        $parts = preg_split('/[\|\n\r;]+/', $trimmed);
        $validGroups = [];
        $seenNames = [];

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') continue;

            $colonPos = strpos($part, ':');
            if ($colonPos === false) {
                $rowErrors[] = "Row {$rowNumber}: Malformed option group '{$part}'. Expected format 'OptionName: val1, val2'.";
                return null;
            }

            $optName = trim(substr($part, 0, $colonPos));
            $valsStr = trim(substr($part, $colonPos + 1));

            if ($optName === '') {
                $rowErrors[] = "Row {$rowNumber}: Option group name cannot be empty.";
                return null;
            }

            $lowerName = strtolower($optName);
            if (isset($seenNames[$lowerName])) {
                $rowErrors[] = "Row {$rowNumber}: Duplicate option group '{$optName}'.";
                return null;
            }
            $seenNames[$lowerName] = true;

            $values = array_values(array_unique(array_filter(array_map('trim', explode(',', $valsStr)), fn($v) => $v !== '')));
            if (empty($values)) {
                $rowErrors[] = "Row {$rowNumber}: Option group '{$optName}' must contain at least one non-empty value.";
                return null;
            }

            $validGroups[] = [
                'name' => $optName,
                'values' => $values,
            ];
        }

        if (empty($validGroups)) {
            $rowErrors[] = "Row {$rowNumber}: Options column is malformed or empty.";
            return null;
        }

        return $validGroups;
    }

    /**
     * Normalize variant attributes structure from CSV.
     */
    protected function normalizeVariantAttributes(mixed $rawAttrs): ?array
    {
        if (empty($rawAttrs)) {
            return null;
        }

        if (is_string($rawAttrs)) {
            $trimmed = trim($rawAttrs);
            if (str_starts_with($trimmed, '{')) {
                $decoded = json_decode($trimmed, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $rawAttrs = $decoded;
                }
            } else {
                $parts = preg_split('/[\|\n\r,]+/', $trimmed);
                $parsed = [];
                foreach ($parts as $part) {
                    if (str_contains($part, ':')) {
                        [$k, $v] = explode(':', $part, 2);
                        $kTrim = trim($k);
                        $vTrim = trim($v);
                        if ($kTrim !== '' && $vTrim !== '') {
                            $parsed[$kTrim] = $vTrim;
                        }
                    }
                }
                if (!empty($parsed)) {
                    return $parsed;
                }
            }
        }

        if (is_array($rawAttrs)) {
            $normalized = [];
            foreach ($rawAttrs as $k => $v) {
                $kTrim = trim((string)$k);
                if ($kTrim === '') continue;
                if (is_array($v) || is_object($v)) {
                    return null; // Malformed nested attribute
                }
                $vTrim = trim((string)$v);
                $normalized[$kTrim] = $vTrim;
            }
            return !empty($normalized) ? $normalized : null;
        }

        return null;
    }

    /**
     * Infer option groups by scanning all variant attributes in a product.
     */
    protected function inferOptionsFromRawVariants(array $variants): ?array
    {
        $optionsMap = [];
        foreach ($variants as $v) {
            $rawAttrs = $v['attributes'] ?? ($v['variant_attributes'] ?? null);
            $attrs = $this->normalizeVariantAttributes($rawAttrs);
            if (!is_array($attrs)) continue;
            foreach ($attrs as $k => $val) {
                $lowerKey = strtolower(trim((string)$k));
                if (!isset($optionsMap[$lowerKey])) {
                    $optionsMap[$lowerKey] = [
                        'name' => trim((string)$k),
                        'values' => [],
                    ];
                }
                $valTrim = trim((string)$val);
                if ($valTrim !== '' && !in_array($valTrim, $optionsMap[$lowerKey]['values'], true)) {
                    $optionsMap[$lowerKey]['values'][] = $valTrim;
                }
            }
        }

        if (empty($optionsMap)) {
            return null;
        }

        return array_values($optionsMap);
    }

    /**
     * Compute a deterministic canonical combination key for variant matching.
     */
    protected function getCanonicalVariantKey(array|ProductVariant $variant, bool $isDynamic): string
    {
        if ($isDynamic) {
            $attrs = is_array($variant)
                ? ($variant['attributes'] ?? ($variant['variant_attributes'] ?? []))
                : ($variant->attributes ?? []);
            if (!is_array($attrs) || empty($attrs)) {
                return '';
            }
            $pairs = [];
            foreach ($attrs as $k => $v) {
                $kTrim = strtolower(trim((string)$k));
                $vTrim = strtolower(trim((string)$v));
                if ($kTrim !== '') {
                    $pairs[$kTrim] = $vTrim;
                }
            }
            ksort($pairs);
            $parts = [];
            foreach ($pairs as $k => $v) {
                $parts[] = "{$k}={$v}";
            }
            return implode('|', $parts);
        }

        $colorName = is_array($variant)
            ? (isset($variant['color_name']) && $variant['color_name'] !== null ? trim((string)$variant['color_name']) : '')
            : (isset($variant->color_name) && $variant->color_name !== null ? trim((string)$variant->color_name) : '');

        $size = is_array($variant)
            ? (isset($variant['size']) && $variant['size'] !== null ? trim((string)$variant['size']) : '')
            : (isset($variant->size) && $variant->size !== null ? trim((string)$variant->size) : '');

        if ($colorName !== '' || $size !== '') {
            return strtolower($colorName) . '|' . strtolower($size);
        }

        $name = is_array($variant) ? ($variant['name'] ?? '') : ($variant->name ?? '');
        return 'name:' . strtolower(trim((string)$name));
    }

    /**
     * Resolve variant image_id from image_id or image_url.
     */
    protected function resolveVariantImageId(array $variantData, $productImages, ?int $fallbackId = null): ?int
    {
        if (!empty($variantData['image_id'])) {
            $imgId = (int)$variantData['image_id'];
            if ($productImages->contains('id', $imgId)) {
                return $imgId;
            }
        }

        if (!empty($variantData['image_url'])) {
            $url = trim((string)$variantData['image_url']);
            $matchedImg = $productImages->first(fn($img) => $img->image_url === $url);
            if ($matchedImg) {
                return $matchedImg->id;
            }
        }

        return $fallbackId;
    }
}
