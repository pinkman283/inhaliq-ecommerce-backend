<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminExpenseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'expenses.view');

        $query = Expense::with(['category', 'payeeVendor', 'createdByUser'])->latest('expense_date');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('expense_number', 'like', "%{$search}%")
                  ->orWhere('title', 'like', "%{$search}%")
                  ->orWhere('payee_name', 'like', "%{$search}%")
                  ->orWhere('reference_number', 'like', "%{$search}%");
            });
        }

        if ($request->filled('category_id') && $request->input('category_id') !== 'all') {
            $query->where('expense_category_id', $request->input('category_id'));
        }

        if ($request->filled('status') && $request->input('status') !== 'all') {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('expense_date', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('expense_date', '<=', $request->input('date_to'));
        }

        $perPage = (int) $request->input('per_page', 25);
        $expenses = $query->paginate($perPage);

        // Expense Stats across filtered period
        $statsQuery = Expense::where('status', '!=', 'cancelled');
        if ($request->filled('date_from')) {
            $statsQuery->whereDate('expense_date', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $statsQuery->whereDate('expense_date', '<=', $request->input('date_to'));
        }

        $totalExpenses = (float) $statsQuery->sum('amount');
        $thisMonthExpenses = (float) Expense::where('status', '!=', 'cancelled')
            ->whereMonth('expense_date', now()->month)
            ->whereYear('expense_date', now()->year)
            ->sum('amount');

        return response()->json([
            'expenses' => $expenses,
            'stats' => [
                'total_expenses' => round($totalExpenses, 2),
                'this_month_expenses' => round($thisMonthExpenses, 2),
                'total_recorded_count' => Expense::where('status', '!=', 'cancelled')->count(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'expenses.manage');

        $validated = $request->validate([
            'expense_category_id' => 'required|exists:expense_categories,id',
            'title' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0.01',
            'expense_date' => 'required|date',
            'payee_vendor_id' => 'nullable|exists:vendors,id',
            'payee_name' => 'nullable|string|max:255',
            'payment_method' => 'required|string|max:50',
            'reference_number' => 'nullable|string|max:100',
            'receipt_attachment_url' => 'nullable|string|max:1000',
            'notes' => 'nullable|string|max:2000',
            'status' => 'required|in:recorded,approved,cancelled',
        ]);

        $expenseNumber = 'EXP-' . date('Y') . '-' . strtoupper(Str::random(6));

        $expense = Expense::create(array_merge($validated, [
            'expense_number' => $expenseNumber,
            'created_by_user_id' => $request->user()->id,
        ]));

        // Post Real Double-Entry General Ledger Entry
        \App\Services\AccountingService::postExpense($expense);

        AuditLog::log(
            $request->user(),
            'expense.recorded',
            'Expense',
            $expense->id,
            "Recorded operating expense {$expense->expense_number} ('{$expense->title}') for \${$expense->amount}.",
            null,
            $expense->toArray()
        );

        return response()->json([
            'message' => "Operating expense {$expense->expense_number} recorded successfully.",
            'expense' => $expense->load(['category', 'payeeVendor']),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'expenses.manage');

        $expense = Expense::findOrFail($id);

        $validated = $request->validate([
            'expense_category_id' => 'sometimes|required|exists:expense_categories,id',
            'title' => 'sometimes|required|string|max:255',
            'amount' => 'sometimes|required|numeric|min:0.01',
            'expense_date' => 'sometimes|required|date',
            'payee_vendor_id' => 'nullable|exists:vendors,id',
            'payee_name' => 'nullable|string|max:255',
            'payment_method' => 'sometimes|required|string|max:50',
            'reference_number' => 'nullable|string|max:100',
            'receipt_attachment_url' => 'nullable|string|max:1000',
            'notes' => 'nullable|string|max:2000',
            'status' => 'sometimes|required|in:recorded,approved,cancelled',
        ]);

        $oldValues = $expense->toArray();
        $expense->update($validated);

        AuditLog::log(
            $request->user(),
            'expense.updated',
            'Expense',
            $expense->id,
            "Updated operating expense {$expense->expense_number}.",
            $oldValues,
            $expense->toArray()
        );

        return response()->json([
            'message' => "Expense {$expense->expense_number} updated successfully.",
            'expense' => $expense->fresh(['category', 'payeeVendor']),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'expenses.manage');

        $expense = Expense::findOrFail($id);
        $expNumber = $expense->expense_number;
        $expense->delete();

        AuditLog::log(
            $request->user(),
            'expense.deleted',
            'Expense',
            $id,
            "Deleted expense record #{$expNumber}."
        );

        return response()->json(['message' => 'Expense deleted successfully.']);
    }

    public function bulkDestroy(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'expenses.manage');

        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:expenses,id',
        ]);

        $ids = $validated['ids'];
        $count = count($ids);

        Expense::whereIn('id', $ids)->delete();

        AuditLog::log(
            $request->user(),
            'expense.bulk_deleted',
            'Expense',
            null,
            "Bulk deleted {$count} expense records."
        );

        return response()->json([
            'message' => "Successfully deleted {$count} expense record(s).",
            'deleted_count' => $count,
        ]);
    }


    public function categories(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'expenses.view');

        $categories = ExpenseCategory::withCount('expenses')->get();

        // Seed standard default categories if empty
        if ($categories->isEmpty()) {
            $defaults = [
                ['name' => 'Marketing & Advertising', 'code' => 'MKT', 'description' => 'Meta/Google ads, influencer marketing, design fees'],
                ['name' => 'Salaries & Staff Compensation', 'code' => 'SAL', 'description' => 'Wages, overtime, bonuses, staff benefits'],
                ['name' => 'Rent & Facility Lease', 'code' => 'RNT', 'description' => 'Retail shop, studio showroom, and warehouse lease'],
                ['name' => 'Utilities & Connectivity', 'code' => 'UTL', 'description' => 'Electricity, high-speed fiber internet, water'],
                ['name' => 'Software & Subscriptions', 'code' => 'SFT', 'description' => 'SaaS licenses, cloud servers, AI subscriptions'],
                ['name' => 'Packaging & Shipping Supplies', 'code' => 'PKG', 'description' => 'Boxes, anti-static wraps, thermal labels'],
                ['name' => 'Payment Gateway & Bank Fees', 'code' => 'BNK', 'description' => 'Merchant processing percentages, wire transfer fees'],
                ['name' => 'Maintenance & Repairs', 'code' => 'MNT', 'description' => 'Studio hardware maintenance, equipment repairs'],
                ['name' => 'Office & General Supplies', 'code' => 'OFC', 'description' => 'Stationery, refreshments, sanitization supplies'],
            ];

            foreach ($defaults as $def) {
                ExpenseCategory::create($def);
            }

            $categories = ExpenseCategory::withCount('expenses')->get();
        }

        return response()->json($categories);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'expenses.manage');

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:20|unique:expense_categories,code',
            'description' => 'nullable|string|max:1000',
        ]);

        $category = ExpenseCategory::create($validated);

        return response()->json([
            'message' => 'Expense category created successfully.',
            'category' => $category,
        ], 201);
    }
}
