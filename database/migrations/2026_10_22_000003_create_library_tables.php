<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 120)->unique();
        });

        Schema::create('books', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('title', 250);
            $table->string('author', 200);
            $table->char('isbn', 13);
            $table->foreignUuid('category_id')->constrained('categories')->restrictOnDelete();
            $table->unsignedInteger('total_copies');
            $table->unsignedInteger('available_copies');
            $table->string('cover_key', 512)->nullable();
            $table->timestampTz('deleted_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->unique(['isbn', 'deleted_at']);
            $table->index(['category_id', 'title']);
        });

        Schema::create('members', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('full_name', 200);
            $table->string('document_number', 30)->unique();
            $table->index('full_name');
        });

        Schema::create('loans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('member_id')->constrained('members')->restrictOnDelete();
            $table->foreignId('loaned_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('loaned_at');
            $table->index('loaned_at');
        });

        Schema::create('loan_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('loan_id')->constrained('loans')->cascadeOnDelete();
            $table->foreignUuid('book_id')->constrained('books')->restrictOnDelete();
            $table->string('book_title', 250);
            $table->char('isbn', 13);
            $table->string('category_name', 120);
            $table->timestampTz('due_at');
            $table->timestampTz('returned_at')->nullable();
            $table->unique(['loan_id', 'book_id']);
            $table->index('due_at');
        });

        DB::table('categories')->insert([
            ['id' => '11111111-1111-4111-8111-111111111111', 'name' => 'Novela'],
            ['id' => '22222222-2222-4222-8222-222222222222', 'name' => 'Ciencia'],
            ['id' => '33333333-3333-4333-8333-333333333333', 'name' => 'Tecnología'],
            ['id' => '44444444-4444-4444-8444-444444444444', 'name' => 'Historia'],
            ['id' => '55555555-5555-4555-8555-555555555555', 'name' => 'Infantil'],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_items');
        Schema::dropIfExists('loans');
        Schema::dropIfExists('members');
        Schema::dropIfExists('books');
        Schema::dropIfExists('categories');
    }
};
