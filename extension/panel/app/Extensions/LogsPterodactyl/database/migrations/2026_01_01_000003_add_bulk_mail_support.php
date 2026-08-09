<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Soporte para envios en bloque desde el panel.
 *
 * Se agrupan por "campana" para poder ver de un vistazo cuantos salieron y
 * cuantos fallaron de un mismo envio, en vez de tener cientos de filas sueltas
 * mezcladas con los correos automaticos del panel.
 */
return new class () extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('logspterodactyl_mail_logs')) {
            Schema::table('logspterodactyl_mail_logs', function (Blueprint $table) {
                if (!Schema::hasColumn('logspterodactyl_mail_logs', 'campaign_id')) {
                    $table->unsignedBigInteger('campaign_id')->nullable()->after('user_name');
                    $table->index('campaign_id');
                }
            });
        }

        if (!Schema::hasTable('logspterodactyl_mail_campaigns')) {
            Schema::create('logspterodactyl_mail_campaigns', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('subject');
                $table->longText('body_html')->nullable();
                $table->string('logo_url')->nullable();

                // all | one | selected
                $table->string('audience', 20)->default('selected');
                $table->unsignedInteger('total')->default(0);
                $table->unsignedInteger('sent')->default(0);
                $table->unsignedInteger('failed')->default(0);

                // queued | sending | done
                $table->string('status', 20)->default('queued');
                $table->unsignedInteger('created_by')->nullable();
                $table->string('created_by_name')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();

                $table->index(['status', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('logspterodactyl_mail_campaigns');

        if (Schema::hasTable('logspterodactyl_mail_logs') && Schema::hasColumn('logspterodactyl_mail_logs', 'campaign_id')) {
            Schema::table('logspterodactyl_mail_logs', function (Blueprint $table) {
                $table->dropIndex(['campaign_id']);
                $table->dropColumn('campaign_id');
            });
        }
    }
};
