<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table($this->table(), function (Blueprint $table): void {
            $table->string('message_id')->nullable()->index();
            $table->longText('resend_payload')->nullable();
            $table->foreignId('resent_from_id')
                ->nullable()
                ->constrained($this->table())
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table($this->table(), function (Blueprint $table): void {
            $table->dropConstrainedForeignId('resent_from_id');
            $table->dropIndex(['message_id']);
            $table->dropColumn(['message_id', 'resend_payload']);
        });
    }

    private function table(): string
    {
        return (string) config('mail-log.tables.events', 'mail_logs');
    }
};
