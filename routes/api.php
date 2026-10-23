<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use App\Models\Book;
use App\Models\Category;
use App\Models\Member;

Route::prefix('v1')->group(function (): void {
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
