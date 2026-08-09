<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('arixlog_settings')) {
            Schema::create('arixlog_settings', function (Blueprint $table) {
                $table->string('key')->primary();
                $table->text('value')->nullable();
                $table->timestamp('updated_at')->nullable();
            });
        }

        // Registro propio de la extension: cada accion automatica o manual que
        // realiza queda anotada aqui (parar instalacion, cambiar puerto,
        // actualizar el panel, errores de la propia extension...).
        if (!Schema::hasTable('arixlog_events')) {
            Schema::create('arixlog_events', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('level', 20)->default('info');
                $table->string('channel', 40)->default('general');
                $table->string('message', 500);
                $table->json('context')->nullable();
                $table->unsignedInteger('user_id')->nullable();
                $table->unsignedInteger('server_id')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index(['channel', 'created_at']);
                $table->index('level');
                $table->index('server_id');
            });
        }

        // Historial de instalaciones de servidores.
        if (!Schema::hasTable('arixlog_install_events')) {
            Schema::create('arixlog_install_events', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('server_id')->nullable();
                $table->string('server_uuid', 36)->nullable();
                $table->string('server_name')->nullable();
                $table->unsignedInteger('user_id')->nullable();
                $table->string('user_name')->nullable();
                $table->string('user_email')->nullable();
                $table->unsignedInteger('node_id')->nullable();
                $table->string('node_name')->nullable();
                $table->unsignedInteger('egg_id')->nullable();
                $table->string('egg_name')->nullable();
                $table->boolean('is_reinstall')->default(false);

                // installing | success | failed | cancelled | timeout
                $table->string('status', 20)->default('installing');
                $table->string('resolution', 40)->nullable();
                $table->string('cancelled_by')->nullable();

                $table->string('old_allocation')->nullable();
                $table->string('new_allocation')->nullable();

                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->unsignedInteger('duration_seconds')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['status', 'started_at']);
                $table->index('server_id');
                $table->index('user_email');
            });
        }

        // Correos enviados por el panel.
        if (!Schema::hasTable('arixlog_mail_logs')) {
            Schema::create('arixlog_mail_logs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('mailer', 60)->nullable();
                $table->text('to_addresses')->nullable();
                $table->text('cc_addresses')->nullable();
                $table->text('bcc_addresses')->nullable();
                $table->string('from_address')->nullable();
                $table->string('subject')->nullable();
                $table->longText('body_html')->nullable();
                $table->longText('body_text')->nullable();
                $table->unsignedInteger('user_id')->nullable();
                $table->string('user_name')->nullable();

                // queued | sent | failed
                $table->string('status', 20)->default('queued');
                $table->text('error')->nullable();
                $table->unsignedInteger('resend_count')->default(0);
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('last_resent_at')->nullable();
                $table->string('message_id')->nullable();
                $table->timestamps();

                $table->index(['status', 'created_at']);
                $table->index('message_id');
            });
        }

        // Muestras de consumo de recursos por servidor.
        if (!Schema::hasTable('arixlog_resource_samples')) {
            Schema::create('arixlog_resource_samples', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('server_id');
                $table->unsignedInteger('node_id')->nullable();
                $table->decimal('cpu_absolute', 8, 2)->default(0);
                $table->unsignedBigInteger('memory_bytes')->default(0);
                $table->unsignedBigInteger('memory_limit_bytes')->default(0);
                $table->unsignedBigInteger('disk_bytes')->default(0);
                $table->unsignedBigInteger('network_rx_bytes')->default(0);
                $table->unsignedBigInteger('network_tx_bytes')->default(0);
                $table->string('state', 20)->nullable();
                $table->timestamp('sampled_at')->nullable();

                $table->index(['server_id', 'sampled_at']);
                $table->index('sampled_at');
            });
        }

        // Actualizaciones del panel lanzadas desde la extension.
        if (!Schema::hasTable('arixlog_update_runs')) {
            Schema::create('arixlog_update_runs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('token', 64)->unique();
                $table->string('from_version', 40)->nullable();
                $table->string('to_version', 40)->nullable();
                $table->string('theme_version', 40)->nullable();

                // running | success | failed | rolled_back
                $table->string('status', 20)->default('running');
                $table->string('backup_path')->nullable();
                $table->string('log_path')->nullable();
                $table->unsignedInteger('started_by')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->text('error')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('arixlog_update_runs');
        Schema::dropIfExists('arixlog_resource_samples');
        Schema::dropIfExists('arixlog_mail_logs');
        Schema::dropIfExists('arixlog_install_events');
        Schema::dropIfExists('arixlog_events');
        Schema::dropIfExists('arixlog_settings');
    }
};
