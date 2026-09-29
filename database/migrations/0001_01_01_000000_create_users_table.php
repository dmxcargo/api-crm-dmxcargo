<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 150);
            $t->string('normalized_username', 50)->unique();
            $t->string('normalized_email', 254)->unique();
            $t->string('password');
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->timestampsTz();
        });
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_email_normalized CHECK (normalized_email = lower(btrim(normalized_email)))');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_username_normalized CHECK (normalized_username ~ '^[a-z0-9_.-]{3,50}$')");
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_version_positive CHECK (version > 0)');
        Schema::create('roles', function (Blueprint $t) {
            $t->string('code', 20)->primary();
        });
        DB::statement("ALTER TABLE roles ADD CONSTRAINT roles_fixed CHECK (code IN ('ADMIN','BILLING','SALES'))");
        Schema::create('permissions', function (Blueprint $t) {
            $t->string('code', 80)->primary();
        });
        Schema::create('user_roles', function (Blueprint $t) {
            $t->uuid('user_id')->primary();
            $t->string('role_code', 20);
            $t->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            $t->foreign('role_code')->references('code')->on('roles')->restrictOnDelete();
        });
        Schema::create('role_permissions', function (Blueprint $t) {
            $t->string('role_code', 20);
            $t->string('permission_code', 80);
            $t->primary(['role_code', 'permission_code']);
            $t->foreign('role_code')->references('code')->on('roles')->restrictOnDelete();
            $t->foreign('permission_code')->references('code')->on('permissions')->restrictOnDelete();
        });
        Schema::create('personal_access_tokens', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->uuidMorphs('tokenable');
            $t->text('name');
            $t->string('token', 64)->unique();
            $t->text('abilities')->nullable();
            $t->timestampTz('last_used_at')->nullable();
            $t->timestampTz('expires_at')->nullable()->index();
            $t->timestampsTz();
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('actor_user_id')->nullable();
            $t->string('action', 80);
            $t->string('entity_type', 50);
            $t->uuid('entity_id')->nullable();
            $t->jsonb('before_data');
            $t->jsonb('after_data');
            $t->ipAddress('ip_address')->nullable();
            $t->string('client_version', 30)->nullable();
            $t->uuid('trace_id')->nullable();
            $t->timestampTz('created_at');
            $t->foreign('actor_user_id')->references('id')->on('users')->restrictOnDelete();
            $t->index(['entity_type', 'entity_id', 'created_at']);
            $t->index(['action', 'created_at']);
        });
        DB::unprepared("CREATE OR REPLACE FUNCTION reject_audit_changes() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'audit_logs is append only'; END; \$\$;");
        DB::unprepared('DROP TRIGGER IF EXISTS audit_append_only ON audit_logs');
        DB::unprepared('CREATE TRIGGER audit_append_only BEFORE UPDATE OR DELETE ON audit_logs FOR EACH ROW EXECUTE FUNCTION reject_audit_changes()');
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        DB::statement('DROP FUNCTION IF EXISTS reject_audit_changes()');
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('user_roles');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('users');
    }
};
