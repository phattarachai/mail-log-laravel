<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')
                ->constrained($this->eventsTable())
                ->cascadeOnDelete();
            $table->string('model_type');
            $table->string('model_id');
            $table->boolean('is_primary')->default(value: false);
            $table->unique(['event_id', 'model_type', 'model_id']);
            $table->index(['model_type', 'model_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (string) config('mail-log.tables.associations', 'mail_log_associations');
    }

    private function eventsTable(): string
    {
        return (string) config('mail-log.tables.events', 'mail_logs');
    }
};
