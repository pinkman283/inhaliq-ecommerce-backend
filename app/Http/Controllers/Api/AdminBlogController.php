<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BlogCategory;
use App\Models\BlogComment;
use App\Models\BlogPost;
use App\Models\BlogTag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminBlogController extends Controller
{
    /**
     * Blog dashboard metrics and summary counters.
     */
    public function summary(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'cms.view', 'cms.manage');

        $totalPosts = BlogPost::count();
        $publishedPosts = BlogPost::where('status', 'published')->count();
        $draftPosts = BlogPost::where('status', 'draft')->count();
        $totalCategories = BlogCategory::count();
        $totalTags = BlogTag::count();
        $totalComments = BlogComment::count();
        $pendingComments = BlogComment::where('is_approved', false)->count();
        $totalViews = BlogPost::sum('views_count');

        $recentPosts = BlogPost::with(['category', 'author'])
            ->latest()
            ->take(5)
            ->get();

        return response()->json([
            'metrics' => [
                'total_posts' => $totalPosts,
                'published_posts' => $publishedPosts,
                'draft_posts' => $draftPosts,
                'total_categories' => $totalCategories,
                'total_tags' => $totalTags,
                'total_comments' => $totalComments,
                'pending_comments' => $pendingComments,
                'total_views' => $totalViews,
            ],
            'recent_posts' => $recentPosts,
        ]);
    }

    // ==========================================
    // BLOG POSTS CRUD
    // ==========================================

    public function posts(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'cms.view', 'cms.manage');

        $query = BlogPost::with(['category', 'tags', 'author'])->withCount('comments');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('excerpt', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->input('category_id'));
        }

        $perPage = min(max((int) $request->input('per_page', 15), 5), 100);
        $posts = $query->latest()->paginate($perPage);

        return response()->json($posts);
    }

    public function showPost(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'cms.view', 'cms.manage');

        $post = BlogPost::with(['category', 'tags', 'author', 'comments' => function ($q) {
            $q->latest();
        }])->findOrFail($id);

        return response()->json($post);
    }

    public function storePost(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'cms.manage');

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:blog_posts,slug',
            'excerpt' => 'nullable|string|max:500',
            'content' => 'required|string',
            'featured_image' => 'nullable|string',
            'category_id' => 'nullable|exists:blog_categories,id',
            'status' => 'nullable|in:published,draft,archived',
            'tag_ids' => 'nullable|array',
            'tag_ids.*' => 'exists:blog_tags,id',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string',
            'featured_image_alt' => 'nullable|string|max:255',
        ]);

        $slug = !empty($validated['slug']) ? Str::slug($validated['slug']) : Str::slug($validated['title']);
        if (BlogPost::where('slug', $slug)->exists()) {
            $slug .= '-' . Str::random(5);
        }

        $post = BlogPost::create([
            'title' => $validated['title'],
            'slug' => $slug,
            'excerpt' => $validated['excerpt'] ?? null,
            'content' => $validated['content'],
            'featured_image' => $validated['featured_image'] ?? null,
            'category_id' => $validated['category_id'] ?? null,
            'author_id' => $request->user()->id,
            'status' => $validated['status'] ?? 'draft',
            'published_at' => ($validated['status'] ?? 'draft') === 'published' ? now() : null,
            'meta_title' => $validated['meta_title'] ?? null,
            'meta_description' => $validated['meta_description'] ?? null,
            'featured_image_alt' => $validated['featured_image_alt'] ?? null,
            'views_count' => 0,
        ]);

        if (!empty($validated['tag_ids'])) {
            $post->tags()->sync($validated['tag_ids']);
        }

        AuditLog::log(
            $request->user(),
            'blog.post_created',
            'BlogPost',
            $post->id,
            "Created blog post: {$post->title}",
            null,
            $post->toArray()
        );

        return response()->json([
            'message' => 'Blog article published successfully.',
            'post' => $post->load(['category', 'tags', 'author']),
        ], 201);
    }

    public function updatePost(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'cms.manage');

        $post = BlogPost::findOrFail($id);
        $oldValues = $post->toArray();

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:blog_posts,slug,' . $id,
            'excerpt' => 'nullable|string|max:500',
            'content' => 'required|string',
            'featured_image' => 'nullable|string',
            'category_id' => 'nullable|exists:blog_categories,id',
            'status' => 'nullable|in:published,draft,archived',
            'tag_ids' => 'nullable|array',
            'tag_ids.*' => 'exists:blog_tags,id',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string',
            'featured_image_alt' => 'nullable|string|max:255',
        ]);

        $slug = !empty($validated['slug']) ? Str::slug($validated['slug']) : Str::slug($validated['title']);
        if (BlogPost::where('slug', $slug)->where('id', '!=', $id)->exists()) {
            $slug .= '-' . Str::random(5);
        }

        $publishedAt = $post->published_at;
        if (($validated['status'] ?? $post->status) === 'published' && !$publishedAt) {
            $publishedAt = now();
        }

        $post->update([
            'title' => $validated['title'],
            'slug' => $slug,
            'excerpt' => $validated['excerpt'] ?? null,
            'content' => $validated['content'],
            'featured_image' => $validated['featured_image'] ?? $post->featured_image,
            'category_id' => $validated['category_id'] ?? null,
            'status' => $validated['status'] ?? $post->status,
            'published_at' => $publishedAt,
            'meta_title' => $validated['meta_title'] ?? null,
            'meta_description' => $validated['meta_description'] ?? null,
            'featured_image_alt' => $validated['featured_image_alt'] ?? null,
        ]);

        if (isset($validated['tag_ids'])) {
            $post->tags()->sync($validated['tag_ids']);
        }

        AuditLog::log(
            $request->user(),
            'blog.post_updated',
            'BlogPost',
            $post->id,
            "Updated blog post: {$post->title}",
            $oldValues,
            $post->toArray()
        );

        return response()->json([
            'message' => 'Blog article updated successfully.',
            'post' => $post->load(['category', 'tags', 'author']),
        ]);
    }

    public function togglePostStatus(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'cms.manage');

        $post = BlogPost::findOrFail($id);
        $newStatus = $post->status === 'published' ? 'draft' : 'published';
        $post->update([
            'status' => $newStatus,
            'published_at' => $newStatus === 'published' && !$post->published_at ? now() : $post->published_at,
        ]);

        return response()->json([
            'message' => "Post status changed to {$newStatus}.",
            'post' => $post,
        ]);
    }

    public function destroyPost(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'cms.manage');

        $post = BlogPost::findOrFail($id);
        $title = $post->title;
        $post->tags()->detach();
        $post->comments()->delete();
        $post->delete();

        AuditLog::log(
            $request->user(),
            'blog.post_deleted',
            'BlogPost',
            $id,
            "Deleted blog post: {$title}"
        );

        return response()->json([
            'message' => 'Blog post deleted successfully.',
        ]);
    }

    public function bulkDestroyPosts(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'cms.manage');
        $validated = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer|exists:blog_posts,id',
        ]);

        $count = 0;
        foreach ($validated['ids'] as $id) {
            $post = BlogPost::find($id);
            if ($post) {
                $post->tags()->detach();
                $post->comments()->delete();
                $post->delete();
                $count++;
            }
        }

        AuditLog::log(
            $request->user(),
            'blog.posts_bulk_deleted',
            'BlogPost',
            null,
            "Bulk deleted {$count} blog posts"
        );

        return response()->json([
            'message' => "Successfully deleted {$count} articles.",
        ]);
    }

    // ==========================================
    // BLOG CATEGORIES CRUD
    // ==========================================

    public function categories(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'cms.view', 'cms.manage');

        $categories = BlogCategory::withCount('posts')->orderBy('name')->get();
        return response()->json($categories);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'cms.manage');

        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:blog_categories,name',
            'slug' => 'nullable|string|max:100|unique:blog_categories,slug',
            'description' => 'nullable|string|max:500',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string',
        ]);

        $slug = !empty($validated['slug']) ? Str::slug($validated['slug']) : Str::slug($validated['name']);

        $category = BlogCategory::create([
            'name' => $validated['name'],
            'slug' => $slug,
            'description' => $validated['description'] ?? null,
            'meta_title' => $validated['meta_title'] ?? null,
            'meta_description' => $validated['meta_description'] ?? null,
        ]);

        return response()->json([
            'message' => 'Blog category created.',
            'category' => $category,
        ], 201);
    }

    public function updateCategory(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'cms.manage');

        $category = BlogCategory::findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:blog_categories,name,' . $id,
            'slug' => 'nullable|string|max:100|unique:blog_categories,slug,' . $id,
            'description' => 'nullable|string|max:500',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string',
        ]);

        $slug = !empty($validated['slug']) ? Str::slug($validated['slug']) : Str::slug($validated['name']);

        $category->update([
            'name' => $validated['name'],
            'slug' => $slug,
            'description' => $validated['description'] ?? null,
            'meta_title' => $validated['meta_title'] ?? null,
            'meta_description' => $validated['meta_description'] ?? null,
        ]);

        return response()->json([
            'message' => 'Blog category updated.',
            'category' => $category,
        ]);
    }

    public function destroyCategory(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'cms.manage');

        $category = BlogCategory::findOrFail($id);
        $category->posts()->update(['category_id' => null]);
        $category->delete();

        return response()->json([
            'message' => 'Blog category deleted successfully.',
        ]);
    }

    public function bulkDestroyCategories(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'cms.manage');
        $validated = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer|exists:blog_categories,id',
        ]);

        $count = 0;
        foreach ($validated['ids'] as $id) {
            $category = BlogCategory::find($id);
            if ($category) {
                $category->posts()->update(['category_id' => null]);
                $category->delete();
                $count++;
            }
        }

        AuditLog::log(
            $request->user(),
            'blog.categories_bulk_deleted',
            'BlogCategory',
            null,
            "Bulk deleted {$count} blog categories"
        );

        return response()->json([
            'message' => "Successfully deleted {$count} categories.",
        ]);
    }

    // ==========================================
    // BLOG TAGS CRUD
    // ==========================================

    public function tags(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'cms.view', 'cms.manage');

        $tags = BlogTag::withCount('posts')->orderBy('name')->get();
        return response()->json($tags);
    }

    public function storeTag(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'cms.manage');

        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:blog_tags,name',
            'slug' => 'nullable|string|max:100|unique:blog_tags,slug',
        ]);

        $slug = !empty($validated['slug']) ? Str::slug($validated['slug']) : Str::slug($validated['name']);

        $tag = BlogTag::create([
            'name' => $validated['name'],
            'slug' => $slug,
        ]);

        return response()->json([
            'message' => 'Blog tag created.',
            'tag' => $tag,
        ], 201);
    }

    public function updateTag(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'cms.manage');

        $tag = BlogTag::findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:blog_tags,name,' . $id,
            'slug' => 'nullable|string|max:100|unique:blog_tags,slug,' . $id,
        ]);

        $slug = !empty($validated['slug']) ? Str::slug($validated['slug']) : Str::slug($validated['name']);

        $tag->update([
            'name' => $validated['name'],
            'slug' => $slug,
        ]);

        return response()->json([
            'message' => 'Blog tag updated.',
            'tag' => $tag,
        ]);
    }

    public function destroyTag(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'cms.manage');

        $tag = BlogTag::findOrFail($id);
        $tag->posts()->detach();
        $tag->delete();

        return response()->json([
            'message' => 'Blog tag deleted successfully.',
        ]);
    }

    public function bulkDestroyTags(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'cms.manage');
        $validated = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer|exists:blog_tags,id',
        ]);

        $count = 0;
        foreach ($validated['ids'] as $id) {
            $tag = BlogTag::find($id);
            if ($tag) {
                $tag->posts()->detach();
                $tag->delete();
                $count++;
            }
        }

        AuditLog::log(
            $request->user(),
            'blog.tags_bulk_deleted',
            'BlogTag',
            null,
            "Bulk deleted {$count} blog tags"
        );

        return response()->json([
            'message' => "Successfully deleted {$count} tags.",
        ]);
    }

    // ==========================================
    // BLOG COMMENTS MODERATION
    // ==========================================

    public function comments(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'cms.view', 'cms.manage');

        $query = BlogComment::with('post:id,title,slug');

        if ($request->has('is_approved') && $request->input('is_approved') !== '') {
            $query->where('is_approved', filter_var($request->input('is_approved'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('author_name', 'like', "%{$search}%")
                  ->orWhere('author_email', 'like', "%{$search}%")
                  ->orWhere('comment', 'like', "%{$search}%");
            });
        }

        $perPage = min(max((int) $request->input('per_page', 20), 5), 100);
        $comments = $query->latest()->paginate($perPage);

        return response()->json($comments);
    }

    public function toggleCommentApproval(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'cms.manage');

        $comment = BlogComment::findOrFail($id);
        $comment->update(['is_approved' => !$comment->is_approved]);

        return response()->json([
            'message' => $comment->is_approved ? 'Comment approved.' : 'Comment marked pending.',
            'comment' => $comment,
        ]);
    }

    public function destroyComment(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'cms.manage');

        $comment = BlogComment::findOrFail($id);
        $comment->delete();

        return response()->json([
            'message' => 'Comment deleted successfully.',
        ]);
    }

    public function bulkDestroyComments(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'cms.manage');
        $validated = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer|exists:blog_comments,id',
        ]);

        $count = BlogComment::whereIn('id', $validated['ids'])->delete();

        return response()->json([
            'message' => "Successfully deleted {$count} comments.",
        ]);
    }

    public function bulkApproveComments(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'cms.manage');
        $validated = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer|exists:blog_comments,id',
        ]);

        $count = BlogComment::whereIn('id', $validated['ids'])->update(['is_approved' => true]);

        return response()->json([
            'message' => "Successfully approved {$count} comments.",
        ]);
    }
}
