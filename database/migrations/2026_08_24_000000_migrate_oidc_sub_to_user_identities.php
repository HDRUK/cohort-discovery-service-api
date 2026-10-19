<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moves identities created by the old OIDC resource-server integration
 * (users.oidc_sub) onto the user_identities table used by SSO.
 *
 * Deployments that ran the resource server need SSO_MIGRATION_PROVIDER_SLUG
 * set to whichever provider slug they configure in config/sso.php, otherwise
 * their users' subs land under 'default' and returning users would be treated
 * as strangers - creating duplicate accounts rather than matching existing ones.
 */
return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'oidc_sub')) {
            return;
        }

        $slug = env('SSO_MIGRATION_PROVIDER_SLUG', 'default');
        $now = now();

        // A user could already have an identity under this slug if they used
        // SSO before the migration ran; leave those alone rather than
        // colliding with the (provider, provider_sub) unique index
        DB::table('users')
            ->whereNotNull('oidc_sub')
            ->orderBy('id')
            ->chunkById(500, function ($users) use ($slug, $now) {
                $rows = [];

                foreach ($users as $user) {
                    $exists = DB::table('user_identities')
                        ->where('provider', $slug)
                        ->where('provider_sub', $user->oidc_sub)
                        ->exists();

                    if ($exists) {
                        continue;
                    }

                    $rows[] = [
                        'user_id' => $user->id,
                        'provider' => $slug,
                        'provider_sub' => $user->oidc_sub,
                        'email_at_link' => $user->email,
                        'last_login_at' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($rows !== []) {
                    DB::table('user_identities')->insert($rows);
                }
            });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('oidc_sub');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'oidc_sub')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('oidc_sub')->nullable()->unique()->after('id');
        });

        $slug = env('SSO_MIGRATION_PROVIDER_SLUG', 'default');

        DB::table('user_identities')
            ->where('provider', $slug)
            ->orderBy('id')
            ->chunkById(500, function ($identities) {
                foreach ($identities as $identity) {
                    DB::table('users')
                        ->where('id', $identity->user_id)
                        ->update(['oidc_sub' => $identity->provider_sub]);
                }
            });
    }
};
