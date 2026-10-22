<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CmsPage;
use App\Models\FooterColumn;
use App\Models\FooterLink;
use App\Models\SocialLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminOnlineStoreController extends Controller
{
    // ==========================================
    // 1. CMS PAGES
    // ==========================================

    public function pages(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'cms.view', 'cms.manage');

        $query = CmsPage::query();

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        $pages = $query->orderBy('title')->get();

        return response()->json($pages);
    }

    public function showPage(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'cms.view', 'cms.manage');

        $page = CmsPage::findOrFail($id);
        return response()->json($page);
    }

    public function storePage(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'cms.manage');

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:cms_pages,slug',
            'content' => 'required|string',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string|max:500',
            'is_active' => 'boolean',
        ]);

        $slug = !empty($validated['slug']) ? Str::slug($validated['slug']) : Str::slug($validated['title']);
        if (CmsPage::where('slug', $slug)->exists()) {
            $slug .= '-' . Str::random(4);
        }

        $page = CmsPage::create([
            'title' => $validated['title'],
            'slug' => $slug,
            'content' => $validated['content'],
            'meta_title' => $validated['meta_title'] ?? $validated['title'],
            'meta_description' => $validated['meta_description'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        AuditLog::log(
            $request->user(),
            'cms.page_created',
            'CmsPage',
            $page->id,
            "Created CMS page: {$page->title}",
            null,
            $page->toArray()
        );

        return response()->json([
            'message' => 'CMS page created successfully.',
            'page' => $page,
        ], 201);
    }

    public function updatePage(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'cms.manage');

        $page = CmsPage::findOrFail($id);
        $oldValues = $page->toArray();

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:cms_pages,slug,' . $id,
            'content' => 'required|string',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string|max:500',
            'is_active' => 'boolean',
        ]);

        $slug = !empty($validated['slug']) ? Str::slug($validated['slug']) : Str::slug($validated['title']);
        if (CmsPage::where('slug', $slug)->where('id', '!=', $id)->exists()) {
            $slug .= '-' . Str::random(4);
        }

        $page->update([
            'title' => $validated['title'],
            'slug' => $slug,
            'content' => $validated['content'],
            'meta_title' => $validated['meta_title'] ?? $validated['title'],
            'meta_description' => $validated['meta_description'] ?? null,
            'is_active' => $validated['is_active'] ?? $page->is_active,
        ]);

        AuditLog::log(
            $request->user(),
            'cms.page_updated',
            'CmsPage',
            $page->id,
            "Updated CMS page: {$page->title}",
            $oldValues,
            $page->toArray()
        );

        return response()->json([
            'message' => 'CMS page updated successfully.',
            'page' => $page,
        ]);
    }

    public function togglePageStatus(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'cms.manage');

        $page = CmsPage::findOrFail($id);
        $page->update(['is_active' => !$page->is_active]);

        return response()->json([
            'message' => 'Page status updated.',
            'page' => $page,
        ]);
    }

    public function destroyPage(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'cms.manage');

        $page = CmsPage::findOrFail($id);
        $title = $page->title;
        $page->delete();

        AuditLog::log(
            $request->user(),
            'cms.page_deleted',
            'CmsPage',
            $id,
            "Deleted CMS page: {$title}"
        );

        return response()->json([
            'message' => 'CMS page deleted successfully.',
        ]);
    }

    public function bulkDestroyPages(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'cms.manage');

        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:cms_pages,id',
        ]);

        $ids = $validated['ids'];
        $count = count($ids);

        CmsPage::whereIn('id', $ids)->delete();

        AuditLog::log(
            $request->user(),
            'cms.pages_bulk_deleted',
            'CmsPage',
            null,
            "Bulk deleted {$count} CMS pages"
        );

        return response()->json([
            'message' => "Successfully deleted {$count} page(s).",
            'deleted_count' => $count,
        ]);
    }


    // ==========================================
    // 2. FOOTER COLUMNS & LINKS
    // ==========================================

    protected function validateUrlString(string $url): bool
    {
        $trimmed = trim($url);
        if ($trimmed === '') {
            return false;
        }

        // Prohibit dangerous script schemes (case-insensitive)
        if (preg_match('/^(javascript|data|vbscript|file):/i', $trimmed)) {
            return false;
        }

        // Relative internal links: e.g. /products, /contact, /track
        if (str_starts_with($trimmed, '/') && !str_starts_with($trimmed, '//')) {
            return true;
        }

        // Absolute external links: http, https, mailto, tel
        if (preg_match('/^(https?:\/\/|mailto:|tel:)/i', $trimmed)) {
            return true;
        }

        // Anchor links: #...
        if (str_starts_with($trimmed, '#')) {
            return true;
        }

        return false;
    }

    // --- Column Management ---

    public function footerColumns(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'cms.view', 'cms.manage');

        $columns = FooterColumn::orderBy('sort_order')
            ->with(['links' => function ($q) {
                $q->orderBy('sort_order');
            }])
            ->get();

        return response()->json($columns);
    }

    public function storeFooterColumn(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'cms.manage');

        $validated = $request->validate([
            'title' => 'required|string|max:100',
            'sort_order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ]);

        $maxOrder = FooterColumn::max('sort_order') ?? 0;
        $column = FooterColumn::create([
            'title' => trim($validated['title']),
            'sort_order' => $validated['sort_order'] ?? ($maxOrder + 1),
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'message' => 'Footer column created.',
            'footer_column' => $column->load('links'),
        ], 201);
    }

    public function updateFooterColumn(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'cms.manage');

        $column = FooterColumn::findOrFail($id);

        $validated = $request->validate([
            'title' => 'required|string|max:100',
            'sort_order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ]);

        $oldTitle = $column->title;
        $column->update([
            'title' => trim($validated['title']),
            'sort_order' => $validated['sort_order'] ?? $column->sort_order,
            'is_active' => $validated['is_active'] ?? $column->is_active,
        ]);

        // Keep column_group in sync for linked footer_links
        FooterLink::where('footer_column_id', $column->id)
            ->update(['column_group' => $column->title]);

        return response()->json([
            'message' => 'Footer column updated.',
            'footer_column' => $column->fresh('links'),
        ]);
    }

    public function destroyFooterColumn(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'cms.manage');

        $column = FooterColumn::findOrFail($id);
        $title = $column->title;
        $column->delete();

        AuditLog::log(
            $request->user(),
            'cms.footer_column_deleted',
            'FooterColumn',
            $id,
            "Deleted footer column: {$title}"
        );

        return response()->json([
            'message' => 'Footer column deleted.',
        ]);
    }

    public function reorderFooterColumns(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'cms.manage');

        $validated = $request->validate([
            'column_ids' => 'required|array',
            'column_ids.*' => 'integer|exists:footer_columns,id',
        ]);

        foreach ($validated['column_ids'] as $order => $columnId) {
            FooterColumn::where('id', $columnId)->update(['sort_order' => $order + 1]);
        }

        return response()->json([
            'message' => 'Columns reordered successfully.',
        ]);
    }

    // --- Link Management ---

    public function footerLinks(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'cms.view', 'cms.manage');

        $links = FooterLink::with('column')
            ->orderBy('sort_order')
            ->get();

        return response()->json($links);
    }

    public function storeFooterLink(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'cms.manage');

        $validated = $request->validate([
            'footer_column_id' => 'nullable|integer|exists:footer_columns,id',
            'column_group' => 'nullable|string|max:100',
            'title' => 'required|string|max:100',
            'url' => [
                'required',
                'string',
                'max:255',
                function ($attribute, $value, $fail) {
                    if (!$this->validateUrlString($value)) {
                        $fail('The destination URL must be a valid path (e.g. /products) or secure URL (http://, https://, mailto:, tel:). Dangerous schemes such as javascript: are prohibited.');
                    }
                },
            ],
            'is_external' => 'nullable|boolean',
            'open_in_new_tab' => 'nullable|boolean',
            'sort_order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ]);

        $column = null;
        if (!empty($validated['footer_column_id'])) {
            $column = FooterColumn::find($validated['footer_column_id']);
        } elseif (!empty($validated['column_group'])) {
            $column = FooterColumn::firstOrCreate(
                ['title' => trim($validated['column_group'])],
                [
                    'sort_order' => (FooterColumn::max('sort_order') ?? 0) + 1,
                    'is_active' => true,
                ]
            );
        }

        $columnTitle = $column ? $column->title : ($validated['column_group'] ?? 'General');
        $maxOrder = FooterLink::where('footer_column_id', $column?->id)->max('sort_order') ?? 0;

        $url = trim($validated['url']);
        $isExternal = $validated['is_external'] ?? (str_starts_with($url, 'http://') || str_starts_with($url, 'https://'));
        $openInNewTab = $validated['open_in_new_tab'] ?? $isExternal;

        $link = FooterLink::create([
            'footer_column_id' => $column?->id,
            'column_group' => $columnTitle,
            'title' => trim($validated['title']),
            'url' => $url,
            'is_external' => (bool) $isExternal,
            'open_in_new_tab' => (bool) $openInNewTab,
            'sort_order' => $validated['sort_order'] ?? ($maxOrder + 1),
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'message' => 'Footer link created.',
            'footer_link' => $link->load('column'),
        ], 201);
    }

    public function updateFooterLink(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'cms.manage');

        $link = FooterLink::findOrFail($id);

        $validated = $request->validate([
            'footer_column_id' => 'nullable|integer|exists:footer_columns,id',
            'column_group' => 'nullable|string|max:100',
            'title' => 'required|string|max:100',
            'url' => [
                'required',
                'string',
                'max:255',
                function ($attribute, $value, $fail) {
                    if (!$this->validateUrlString($value)) {
                        $fail('The destination URL must be a valid path (e.g. /products) or secure URL (http://, https://, mailto:, tel:). Dangerous schemes such as javascript: are prohibited.');
                    }
                },
            ],
            'is_external' => 'nullable|boolean',
            'open_in_new_tab' => 'nullable|boolean',
            'sort_order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ]);

        $column = null;
        if (isset($validated['footer_column_id'])) {
            $column = FooterColumn::find($validated['footer_column_id']);
        } elseif (isset($validated['column_group'])) {
            $column = FooterColumn::firstOrCreate(
                ['title' => trim($validated['column_group'])],
                [
                    'sort_order' => (FooterColumn::max('sort_order') ?? 0) + 1,
                    'is_active' => true,
                ]
            );
        } else {
            $column = $link->column;
        }

        $columnTitle = $column ? $column->title : ($validated['column_group'] ?? $link->column_group);
        $url = trim($validated['url']);
        $isExternal = $validated['is_external'] ?? $link->is_external;
        $openInNewTab = $validated['open_in_new_tab'] ?? $link->open_in_new_tab;

        $link->update([
            'footer_column_id' => $column?->id ?? $link->footer_column_id,
            'column_group' => $columnTitle,
            'title' => trim($validated['title']),
            'url' => $url,
            'is_external' => (bool) $isExternal,
            'open_in_new_tab' => (bool) $openInNewTab,
            'sort_order' => $validated['sort_order'] ?? $link->sort_order,
            'is_active' => $validated['is_active'] ?? $link->is_active,
        ]);

        return response()->json([
            'message' => 'Footer link updated.',
            'footer_link' => $link->fresh('column'),
        ]);
    }

    public function destroyFooterLink(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'cms.manage');

        $link = FooterLink::findOrFail($id);
        $link->delete();

        return response()->json([
            'message' => 'Footer link deleted.',
        ]);
    }

    public function reorderFooterLinks(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'cms.manage');

        $validated = $request->validate([
            'link_ids' => 'required|array',
            'link_ids.*' => 'integer|exists:footer_links,id',
            'footer_column_id' => 'nullable|integer|exists:footer_columns,id',
        ]);

        $column = null;
        if (!empty($validated['footer_column_id'])) {
            $column = FooterColumn::find($validated['footer_column_id']);
        }

        foreach ($validated['link_ids'] as $order => $linkId) {
            $updateData = ['sort_order' => $order + 1];
            if ($column) {
                $updateData['footer_column_id'] = $column->id;
                $updateData['column_group'] = $column->title;
            }
            FooterLink::where('id', $linkId)->update($updateData);
        }

        return response()->json([
            'message' => 'Footer links reordered successfully.',
        ]);
    }

    // ==========================================
    // 3. SOCIAL LINKS
    // ==========================================

    public function socialLinks(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'cms.view', 'cms.manage');

        $links = SocialLink::orderBy('sort_order')->get();
        return response()->json($links);
    }

    public function storeSocialLink(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'cms.manage');

        $isWhatsApp = strtolower(trim($request->input('platform', ''))) === 'whatsapp';

        if ($isWhatsApp) {
            // Duplicate prevention
            if (SocialLink::whereRaw('LOWER(platform) = ?', ['whatsapp'])->exists()) {
                return response()->json([
                    'message' => 'WhatsApp channel is already configured. Please edit the existing entry.',
                    'errors' => ['platform' => ['WhatsApp channel is already configured. Please edit the existing entry.']],
                ], 422);
            }

            $rawNumber = $request->input('whatsapp_number');
            if (empty($rawNumber) && $request->filled('url')) {
                $parsedUrl = parse_url($request->input('url'));
                $path = trim($parsedUrl['path'] ?? '', '/');
                $rawNumber = $path;
                if (!empty($parsedUrl['query'])) {
                    parse_str($parsedUrl['query'], $query);
                    if (!$request->filled('whatsapp_message') && !empty($query['text'])) {
                        $request->merge(['whatsapp_message' => $query['text']]);
                    }
                }
            }

            $cleanNumber = preg_replace('/[^0-9]/', '', (string) $rawNumber);
            if (!preg_match('/^[1-9][0-9]{6,14}$/', $cleanNumber)) {
                return response()->json([
                    'message' => 'The WhatsApp business number must be a valid international number with 7 to 15 digits (e.g. 8801XXXXXXXXX). Do not include + or spaces.',
                    'errors' => ['whatsapp_number' => ['The WhatsApp business number must be a valid international number with 7 to 15 digits (e.g. 8801XXXXXXXXX). Do not include + or spaces.']],
                ], 422);
            }

            $msg = trim((string) $request->input('whatsapp_message', ''));
            $finalUrl = "https://wa.me/{$cleanNumber}";
            if ($msg !== '') {
                $finalUrl .= '?text=' . rawurlencode($msg);
            }

            $request->merge([
                'platform' => 'WhatsApp',
                'url' => $finalUrl,
                'icon' => 'whatsapp',
            ]);
        }

        $validated = $request->validate([
            'platform' => 'required|string|max:50',
            'url' => [
                'required',
                'string',
                'max:255',
                function ($attribute, $value, $fail) use ($isWhatsApp) {
                    if ($isWhatsApp) {
                        if (!preg_match('/^https:\/\/wa\.me\/[1-9][0-9]{6,14}(\?text=.*)?$/', $value)) {
                            $fail('Invalid WhatsApp URL generated.');
                        }
                    } else {
                        if (!$this->validateUrlString($value)) {
                            $fail('The profile URL must be a valid URL (http://, https://). Dangerous schemes are prohibited.');
                        }
                    }
                },
            ],
            'icon' => 'nullable|string|max:50',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ]);

        $link = SocialLink::create([
            'platform' => $validated['platform'],
            'url' => $validated['url'],
            'icon' => $validated['icon'] ?? strtolower($validated['platform']),
            'sort_order' => $validated['sort_order'] ?? 0,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'message' => 'Social link created.',
            'social_link' => $link,
        ], 201);
    }

    public function updateSocialLink(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'cms.manage');

        $link = SocialLink::findOrFail($id);
        $isWhatsApp = strtolower(trim($request->input('platform', $link->platform))) === 'whatsapp';

        if ($isWhatsApp) {
            // Duplicate prevention excluding current record
            if (SocialLink::whereRaw('LOWER(platform) = ?', ['whatsapp'])->where('id', '!=', $id)->exists()) {
                return response()->json([
                    'message' => 'Another WhatsApp channel is already configured.',
                    'errors' => ['platform' => ['Another WhatsApp channel is already configured.']],
                ], 422);
            }

            $rawNumber = $request->input('whatsapp_number');
            if (empty($rawNumber) && $request->filled('url')) {
                $parsedUrl = parse_url($request->input('url'));
                $path = trim($parsedUrl['path'] ?? '', '/');
                $rawNumber = $path;
                if (!empty($parsedUrl['query'])) {
                    parse_str($parsedUrl['query'], $query);
                    if (!$request->filled('whatsapp_message') && !empty($query['text'])) {
                        $request->merge(['whatsapp_message' => $query['text']]);
                    }
                }
            }

            $cleanNumber = preg_replace('/[^0-9]/', '', (string) $rawNumber);
            if (!preg_match('/^[1-9][0-9]{6,14}$/', $cleanNumber)) {
                return response()->json([
                    'message' => 'The WhatsApp business number must be a valid international number with 7 to 15 digits (e.g. 8801XXXXXXXXX). Do not include + or spaces.',
                    'errors' => ['whatsapp_number' => ['The WhatsApp business number must be a valid international number with 7 to 15 digits (e.g. 8801XXXXXXXXX). Do not include + or spaces.']],
                ], 422);
            }

            $msg = trim((string) $request->input('whatsapp_message', ''));
            $finalUrl = "https://wa.me/{$cleanNumber}";
            if ($msg !== '') {
                $finalUrl .= '?text=' . rawurlencode($msg);
            }

            $request->merge([
                'platform' => 'WhatsApp',
                'url' => $finalUrl,
                'icon' => 'whatsapp',
            ]);
        }

        $validated = $request->validate([
            'platform' => 'required|string|max:50',
            'url' => [
                'required',
                'string',
                'max:255',
                function ($attribute, $value, $fail) use ($isWhatsApp) {
                    if ($isWhatsApp) {
                        if (!preg_match('/^https:\/\/wa\.me\/[1-9][0-9]{6,14}(\?text=.*)?$/', $value)) {
                            $fail('Invalid WhatsApp URL generated.');
                        }
                    } else {
                        if (!$this->validateUrlString($value)) {
                            $fail('The profile URL must be a valid URL (http://, https://). Dangerous schemes are prohibited.');
                        }
                    }
                },
            ],
            'icon' => 'nullable|string|max:50',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ]);

        $link->update($validated);

        return response()->json([
            'message' => 'Social link updated.',
            'social_link' => $link,
        ]);
    }

    public function destroySocialLink(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'cms.manage');

        $link = SocialLink::findOrFail($id);
        $link->delete();

        return response()->json([
            'message' => 'Social link deleted.',
        ]);
    }
}
