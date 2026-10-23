<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use App\Models\Book;
use App\Models\Category;
use App\Models\Member;
use App\Models\Loan;
use App\Models\LoanItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

Route::prefix('v1')->group(function (): void {
    Route::post('/auth/login', function () {
        $validator = Validator::make(request()->all(), ['username' => ['required', 'string'], 'password' => ['required', 'string']]);
        if ($validator->fails()) return response()->json(['code' => 'INVALID_CREDENTIALS', 'message' => 'Las credenciales no son válidas.', 'details' => []], 401);
        $input = $validator->validated();
        $user = \App\Models\User::where('email', strtolower(trim($input['username'])))->first();
        if (!$user || !Hash::check($input['password'], $user->password)) return response()->json(['code' => 'INVALID_CREDENTIALS', 'message' => 'Las credenciales no son válidas.', 'details' => []], 401);
        return response()->json(['access_token' => hash('sha256', $user->id.'|'.now()->timestamp.'|'.Str::random(32)), 'token_type' => 'Bearer', 'expires_in' => 28800, 'role' => 'admin']);
    });

    Route::get('/health', function () {
        return response()->json(['status' => 'ok']);
    });

    Route::get('/categories', function () {
        return response()->json(Category::query()->orderBy('name')->get(['id', 'name']));
    });

    Route::get('/books', function () {
        $query = Book::query()->whereNull('deleted_at')->with('category');
        if ($search = request('q')) {
            $query->where(fn ($builder) => $builder
                ->where('title', 'like', "%{$search}%")
                ->orWhere('author', 'like', "%{$search}%"));
        }
        if ($category = request('category_id')) {
            $query->where('category_id', $category);
        }
        $size = min(max((int) request('size', 20), 1), 50);
        return response()->json($query->orderBy('title')->paginate($size));
    });

    Route::get('/members', function () {
        $query = Member::query();
        if ($search = request('q')) {
            $query->where(fn ($builder) => $builder->where('full_name', 'like', "%{$search}%")->orWhere('document_number', 'like', "%{$search}%"));
        }
        $size = min(max((int) request('size', 20), 1), 50);
        return response()->json($query->orderBy('full_name')->paginate($size));
    });

    Route::post('/members', function () {
        $validator = Validator::make(request()->all(), [
            'full_name' => ['required', 'string', 'max:200'],
            'document_number' => ['required', 'string', 'max:30'],
        ]);
        if ($validator->fails()) {
            return response()->json(['code' => 'VALIDATION_ERROR', 'message' => 'Los datos enviados no son válidos.', 'details' => $validator->errors()], 422);
        }
        $data = $validator->validated();
        if (Member::where('document_number', $data['document_number'])->exists()) {
            return response()->json(['code' => 'DOCUMENT_TAKEN', 'message' => 'El documento ya está registrado.', 'details' => []], 409);
        }
        $data['id'] = (string) Str::uuid();
        return response()->json(Member::create($data), 201);
    });

    Route::post('/loans', function () {
        $validator = Validator::make(request()->all(), [
            'member_id' => ['required', 'uuid', 'exists:members,id'],
            'items' => ['required', 'array', 'min:1', 'max:3'],
            'items.*.book_id' => ['required', 'uuid', 'distinct', 'exists:books,id'],
        ]);
        if ($validator->fails()) return response()->json(['code' => 'VALIDATION_ERROR', 'message' => 'El préstamo no es válido.', 'details' => $validator->errors()], 422);
        $data = $validator->validated();
        return DB::transaction(function () use ($data) {
            $books = Book::whereIn('id', collect($data['items'])->pluck('book_id'))->lockForUpdate()->get()->keyBy('id');
            foreach ($data['items'] as $item) {
                $book = $books[$item['book_id']];
                if ($book->deleted_at || $book->available_copies < 1) return response()->json(['code' => 'BOOK_NOT_AVAILABLE', 'message' => 'No quedan ejemplares disponibles.', 'details' => []], 409);
            }
            $now = now();
            $user = \App\Models\User::first();
            if (!$user) $user = \App\Models\User::create(['name' => 'System', 'email' => 'system@example.test', 'password' => bcrypt(Str::random(32))]);
            $loan = Loan::create(['id' => (string) Str::uuid(), 'member_id' => $data['member_id'], 'loaned_by_user_id' => $user->id, 'loaned_at' => $now]);
            foreach ($data['items'] as $item) {
                $book = $books[$item['book_id']];
                $book->decrement('available_copies');
                LoanItem::create(['id' => (string) Str::uuid(), 'loan_id' => $loan->id, 'book_id' => $book->id, 'book_title' => $book->title, 'isbn' => $book->isbn, 'category_name' => $book->category->name, 'due_at' => $now->copy()->addDays(14)]);
            }
            return response()->json($loan->load('items'), 201);
        });
    });

    Route::post('/loans/{loan}/items/{item}/return', function (string $loan, string $item) {
        $line = LoanItem::where('loan_id', $loan)->where('id', $item)->first();
        if (!$line) return response()->json(['code' => 'NOT_FOUND', 'message' => 'Línea no encontrada.', 'details' => []], 404);
        if ($line->returned_at) return response()->json(['code' => 'ITEM_ALREADY_RETURNED', 'message' => 'La línea ya fue devuelta.', 'details' => []], 409);
        return DB::transaction(function () use ($line) { $line->update(['returned_at' => now()]); Book::whereKey($line->book_id)->increment('available_copies'); return response()->json($line->fresh()); });
    });

    Route::get('/loans', function () {
        $size = min(max((int) request('size', 20), 1), 50);
        return response()->json(Loan::with('items')->latest('loaned_at')->paginate($size));
    });

    Route::get('/loans/{id}', function (string $id) {
        $loan = Loan::with('items')->find($id);
        if (!$loan) return response()->json(['code' => 'NOT_FOUND', 'message' => 'Préstamo no encontrado.', 'details' => []], 404);
        $items = $loan->items;
        $status = $items->every(fn ($item) => $item->returned_at) ? 'closed' : ($items->contains(fn ($item) => !$item->returned_at && $item->due_at->isPast()) ? 'overdue' : 'open');
        return response()->json(['id' => $loan->id, 'member_id' => $loan->member_id, 'loaned_at' => $loan->loaned_at, 'status' => $status, 'items' => $items]);
    })->whereUuid('id');

    Route::get('/loans/overdue', function () {
        $items = LoanItem::whereNull('returned_at')->where('due_at', '<', now())->with('loan')->orderBy('due_at')->get();
        return response()->json(['items' => $items->map(fn ($item) => ['loan_id' => $item->loan_id, 'item_id' => $item->id, 'book_title' => $item->book_title, 'due_at' => $item->due_at, 'days_late' => $item->due_at->diffInDays(now())])]);
    });

    Route::get('/reports/most-borrowed', function () {
        $from = request('from'); $to = request('to');
        if (!$from || !$to || $to < $from) return response()->json(['code' => 'INVALID_DATE_RANGE', 'message' => 'El rango de fechas no es válido.', 'details' => []], 422);
        $rows = LoanItem::query()->join('loans', 'loans.id', '=', 'loan_items.loan_id')->whereBetween('loans.loaned_at', ["{$from} 00:00:00", "{$to} 23:59:59"])->select('loan_items.book_id', 'loan_items.book_title', 'loan_items.category_name', DB::raw('COUNT(*) as times_borrowed'))->groupBy('loan_items.book_id', 'loan_items.book_title', 'loan_items.category_name')->orderByDesc('times_borrowed')->orderBy('loan_items.book_title')->get();
        return response()->json(['from' => $from, 'to' => $to, 'rows' => $rows]);
    });

    Route::post('/books', function () {
        $validator = Validator::make(request()->all(), [
            'title' => ['required', 'string', 'max:250'],
            'author' => ['required', 'string', 'max:200'],
            'isbn' => ['required', 'digits:13'],
            'category_id' => ['required', 'uuid', 'exists:categories,id'],
            'total_copies' => ['required', 'integer', 'min:1'],
        ]);
        if ($validator->fails()) {
            return response()->json(['code' => 'VALIDATION_ERROR', 'message' => 'Los datos enviados no son válidos.', 'details' => $validator->errors()], 422);
        }
        $data = $validator->validated();
        if (Book::where('isbn', $data['isbn'])->whereNull('deleted_at')->exists()) {
            return response()->json(['code' => 'ISBN_TAKEN', 'message' => 'El ISBN ya está registrado.', 'details' => []], 409);
        }
        $data['id'] = (string) Str::uuid();
        $data['available_copies'] = $data['total_copies'];
        $book = Book::create($data);
        return response()->json($book->load('category'), 201);
    });

    Route::put('/books/{id}', function (string $id) {
        $book = Book::whereNull('deleted_at')->find($id);
        if (!$book) {
            return response()->json(['code' => 'NOT_FOUND', 'message' => 'Libro no encontrado.', 'details' => []], 404);
        }
        $validator = Validator::make(request()->all(), [
            'title' => ['required', 'string', 'max:250'],
            'author' => ['required', 'string', 'max:200'],
            'category_id' => ['required', 'uuid', 'exists:categories,id'],
            'total_copies' => ['required', 'integer', 'min:1'],
        ]);
        if ($validator->fails()) {
            return response()->json(['code' => 'VALIDATION_ERROR', 'message' => 'Los datos enviados no son válidos.', 'details' => $validator->errors()], 422);
        }
        $data = $validator->validated();
        $difference = $data['total_copies'] - $book->total_copies;
        if ($book->available_copies + $difference < 0) {
            return response()->json(['code' => 'VALIDATION_ERROR', 'message' => 'El nuevo total no puede dejar ejemplares disponibles negativos.', 'details' => []], 422);
        }
        $data['available_copies'] = $book->available_copies + $difference;
        $book->update($data);
        return response()->json($book->fresh()->load('category'));
    });

    Route::delete('/books/{id}', function (string $id) {
        $book = Book::whereNull('deleted_at')->find($id);
        if (!$book) {
            return response()->json(['code' => 'NOT_FOUND', 'message' => 'Libro no encontrado.', 'details' => []], 404);
        }
        if ($book->loanItems()->whereNull('returned_at')->exists()) {
            return response()->json(['code' => 'BOOK_HAS_OPEN_LOANS', 'message' => 'El libro tiene préstamos abiertos.', 'details' => []], 409);
        }
        $book->update(['deleted_at' => now()]);
        return response()->noContent();
    });
});
