<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserIdentity;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeIdp;
use Tests\TestCase;

class SsoLoginTest extends TestCase
{
    private const FE_CALLBACK = 'http://fe.test/auth/sso/callback';
    private const FE_ERROR = 'http://fe.test/auth/sso/error';
    private const FE_LOGIN = 'http://fe.test/login';

    protected function setUp(): void
    {
        parent::setUp();

        \DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        UserIdentity::truncate();
        \DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        config(['claimsaccesscontrol.sync.provision.defaults_on_create' => true]);

        // The redirect action fetches the discovery document before each
        // test's fakeHttp() call, so these are always stubbed.
        $this->forgetDiscoveryCache();

        Http::fake([
            FakeIdp::ISSUER.'/.well-known/openid-configuration' => Http::response(FakeIdp::discoveryResponse()),
            FakeIdp::ISSUER.'/protocol/openid-connect/certs' => Http::response(FakeIdp::jwksResponse()),
        ]);

        config([
            'sso.enabled' => true,
            'sso.providers.default' => FakeIdp::providerConfig(),
            'sso.frontend_callback_url' => self::FE_CALLBACK,
            'sso.frontend_error_url' => self::FE_ERROR,
            'sso.frontend_login_url' => self::FE_LOGIN,
        ]);
    }

    /**
     * Kick off a login and return [state, nonce] parsed from the IdP
     * authorize URL we redirect the browser to.
     */
    private function startLogin(): array
    {
        $response = $this->get('/api/auth/sso/default/redirect');
        $response->assertRedirect();

        $location = $response->headers->get('Location');
        $this->assertStringStartsWith(FakeIdp::ISSUER.'/protocol/openid-connect/auth?', $location);

        parse_str(parse_url($location, PHP_URL_QUERY), $query);

        $this->assertSame('code', $query['response_type']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame(FakeIdp::CLIENT_ID, $query['client_id']);
        $this->assertNotEmpty($query['code_challenge']);
        $this->assertNotEmpty($query['state']);
        $this->assertNotEmpty($query['nonce']);

        return [$query['state'], $query['nonce']];
    }

    private function callbackUrl(string $state): string
    {
        return '/api/auth/sso/default/callback?code=fake-auth-code&state='.$state;
    }

    private function forgetDiscoveryCache(): void
    {
        \Cache::forget('sso:disc:default');
        \Cache::forget('sso:jwks:default');
    }

    /**
     * Replace the stubs registered in setUp rather than adding to them.
     * Http::fake() merges, and the first matching stub wins, so a test that
     * needs a different response for an already-stubbed URL needs a clean
     * factory.
     */
    private function refakeHttp(array $stubs): void
    {
        $this->forgetDiscoveryCache();

        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::preventStrayRequests();
        Http::fake($stubs);
    }

    private function assertErrorRedirect($response, string $errorCode): void
    {
        $response->assertRedirect();
        $this->assertSame(
            self::FE_ERROR.'?error='.$errorCode,
            $response->headers->get('Location')
        );
    }

    private function decodeJwtPayload(string $jwt): array
    {
        [, $payload] = explode('.', $jwt);
        $payload = strtr($payload, '-_', '+/');
        $payload .= str_repeat('=', (4 - strlen($payload) % 4) % 4);

        return json_decode(base64_decode($payload), true);
    }

    public function test_full_login_flow_provisions_user_and_hands_off_token(): void
    {
        [$state, $nonce] = $this->startLogin();

        (new FakeIdp())->fakeHttp([
            'sub' => 'subject-abc',
            'email' => 'New.Researcher@example.com',
            'email_verified' => true,
            'name' => 'New Researcher',
            'nonce' => $nonce,
        ]);

        $callback = $this->get($this->callbackUrl($state));
        $callback->assertRedirect();

        $location = $callback->headers->get('Location');
        $this->assertStringStartsWith(self::FE_CALLBACK.'?', $location);
        parse_str(parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('default', $query['provider']);
        $this->assertSame(64, strlen($query['code']));

        $exchange = $this->postJson('/api/auth/sso/exchange', ['code' => $query['code']]);
        $exchange->assertOk();
        $accessToken = $exchange->json('data.access_token');
        $this->assertNotEmpty($accessToken);
        $this->assertSame('Bearer', $exchange->json('data.token_type'));

        // JIT provisioning: user + identity + local defaults
        $user = User::where('email', 'new.researcher@example.com')->first();
        $this->assertNotNull($user);
        $this->assertSame('New Researcher', $user->name);
        // JIT users carry the empty-password sentinel (hashed by the model
        // cast); password login stays blocked by the required rule
        $this->assertTrue(\Hash::check('', $user->getAuthPassword()));
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => ''])
            ->assertUnprocessable();
        $this->assertTrue($user->workgroups()->where('name', 'DEFAULT')->exists());
        $this->assertTrue($user->hasRole('user'));

        $identity = UserIdentity::where('provider', 'default')->where('provider_sub', 'subject-abc')->first();
        $this->assertNotNull($identity);
        $this->assertSame($user->id, $identity->user_id);
        $this->assertNotNull($identity->last_login_at);

        // The minted token must satisfy the (fixed) DecodeJwt middleware
        $this->enableMiddleware();
        $this->withJwt($accessToken)->getJson('/api/v1/user')->assertOk();

        // Carries the provider slug so the frontend can offer RP-initiated logout
        $claims = $this->decodeJwtPayload($accessToken);
        $this->assertSame('default', $claims['user']['sso_provider']);
    }

    public function test_existing_identity_logs_in_without_creating_rows(): void
    {
        $user = User::factory()->create();
        UserIdentity::create([
            'user_id' => $user->id,
            'provider' => 'default',
            'provider_sub' => 'subject-existing',
            'email_at_link' => $user->email,
        ]);

        [$state, $nonce] = $this->startLogin();
        (new FakeIdp())->fakeHttp([
            'sub' => 'subject-existing',
            'email' => $user->email,
            'email_verified' => true,
            'name' => $user->name,
            'nonce' => $nonce,
        ]);

        $this->get($this->callbackUrl($state))->assertRedirect();

        $this->assertSame(1, UserIdentity::count());
        $this->assertSame(1, User::where('email', $user->email)->count());
        $this->assertNotNull(UserIdentity::first()->last_login_at);
    }

    public function test_verified_email_links_to_existing_user(): void
    {
        $user = User::factory()->create(['email' => 'linked@example.com']);

        [$state, $nonce] = $this->startLogin();
        (new FakeIdp())->fakeHttp([
            'sub' => 'subject-link',
            'email' => 'linked@example.com',
            'email_verified' => true,
            'name' => $user->name,
            'nonce' => $nonce,
        ]);

        $this->get($this->callbackUrl($state))->assertRedirect();

        $identity = UserIdentity::where('provider_sub', 'subject-link')->first();
        $this->assertNotNull($identity);
        $this->assertSame($user->id, $identity->user_id);
        $this->assertSame(1, User::where('email', 'linked@example.com')->count());
    }

    public function test_unverified_email_matching_existing_user_is_rejected(): void
    {
        User::factory()->create(['email' => 'victim@example.com']);

        [$state, $nonce] = $this->startLogin();
        (new FakeIdp())->fakeHttp([
            'sub' => 'subject-attacker',
            'email' => 'victim@example.com',
            'email_verified' => false,
            'nonce' => $nonce,
        ]);

        $response = $this->get($this->callbackUrl($state));

        $this->assertErrorRedirect($response, 'account_linking_failed');
        $this->assertSame(0, UserIdentity::count());
    }

    public function test_replayed_state_is_rejected(): void
    {
        [$state, $nonce] = $this->startLogin();
        (new FakeIdp())->fakeHttp(['nonce' => $nonce, 'email' => 'a@example.com', 'email_verified' => true]);

        $this->get($this->callbackUrl($state))->assertRedirect();

        // Same state again: transaction already consumed
        $replay = $this->get($this->callbackUrl($state));
        $this->assertErrorRedirect($replay, 'invalid_state');
    }

    public function test_unknown_state_is_rejected(): void
    {
        (new FakeIdp())->fakeHttp();

        $response = $this->get($this->callbackUrl(str_repeat('f', 64)));

        $this->assertErrorRedirect($response, 'invalid_state');
    }

    public function test_wrong_nonce_is_rejected(): void
    {
        [$state] = $this->startLogin();
        (new FakeIdp())->fakeHttp([
            'email' => 'a@example.com',
            'email_verified' => true,
            'nonce' => 'not-the-right-nonce',
        ]);

        $response = $this->get($this->callbackUrl($state));

        $this->assertErrorRedirect($response, 'invalid_id_token');
    }

    public function test_id_token_signed_with_foreign_key_is_rejected(): void
    {
        [$state, $nonce] = $this->startLogin();

        $foreign = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($foreign, $foreignPem);

        (new FakeIdp())
            ->withTokenResponse([
                'id_token' => FakeIdp::mintIdToken(
                    ['nonce' => $nonce, 'email' => 'a@example.com', 'email_verified' => true],
                    FakeIdp::KID,
                    $foreignPem
                ),
            ])
            ->fakeHttp();

        $response = $this->get($this->callbackUrl($state));

        $this->assertErrorRedirect($response, 'invalid_id_token');
    }

    public function test_audience_mismatch_is_rejected(): void
    {
        [$state, $nonce] = $this->startLogin();
        (new FakeIdp())->fakeHttp([
            'aud' => 'some-other-client',
            'nonce' => $nonce,
            'email' => 'a@example.com',
            'email_verified' => true,
        ]);

        $response = $this->get($this->callbackUrl($state));

        $this->assertErrorRedirect($response, 'invalid_id_token');
    }

    public function test_token_endpoint_failure_redirects_to_error_url(): void
    {
        [$state] = $this->startLogin();

        Http::fake([
            FakeIdp::ISSUER.'/protocol/openid-connect/token' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        $response = $this->get($this->callbackUrl($state));

        $this->assertErrorRedirect($response, 'invalid_id_token');
    }

    public function test_idp_error_param_redirects_to_error_url(): void
    {
        $response = $this->get('/api/auth/sso/default/callback?error=access_denied');

        $this->assertErrorRedirect($response, 'idp_error');
    }

    public function test_login_logout_and_failures_are_written_to_the_activity_log(): void
    {
        \DB::table('activity_log')->truncate();

        [$state, $nonce] = $this->startLogin();
        (new FakeIdp())->fakeHttp([
            'sub' => 'subject-audited',
            'email' => 'audited@example.com',
            'email_verified' => true,
            'nonce' => $nonce,
        ]);

        $callback = $this->get($this->callbackUrl($state));
        parse_str(parse_url($callback->headers->get('Location'), PHP_URL_QUERY), $query);

        $user = User::where('email', 'audited@example.com')->firstOrFail();

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'sso',
            'description' => 'sso_login',
            'subject_type' => User::class,
            'subject_id' => $user->id,
            'properties->provider' => 'default',
        ]);

        $this->postJson('/api/auth/sso/exchange', ['code' => $query['code']])->assertOk();
        $this->postJson('/api/auth/sso/exchange', ['code' => $query['code']])->assertUnauthorized();

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'sso',
            'description' => 'sso_code_exchange_failed',
        ]);

        $this->get('/api/auth/sso/default/callback?error=access_denied');

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'sso',
            'description' => 'sso_login_failed',
            'properties->error_code' => 'idp_error',
            'properties->idp_error' => 'access_denied',
        ]);

        $this->get('/api/auth/sso/default/logout');

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'sso',
            'description' => 'sso_logout',
            'properties->provider' => 'default',
            'properties->reached_idp' => true,
            'properties->had_id_token_hint' => false,
        ]);
    }

    public function test_exchange_code_is_single_use(): void
    {
        [$state, $nonce] = $this->startLogin();
        (new FakeIdp())->fakeHttp(['nonce' => $nonce, 'email' => 'a@example.com', 'email_verified' => true]);

        $callback = $this->get($this->callbackUrl($state));
        parse_str(parse_url($callback->headers->get('Location'), PHP_URL_QUERY), $query);

        $this->postJson('/api/auth/sso/exchange', ['code' => $query['code']])->assertOk();
        $this->postJson('/api/auth/sso/exchange', ['code' => $query['code']])->assertUnauthorized();
    }

    public function test_exchange_rejects_unknown_and_expired_codes(): void
    {
        $this->postJson('/api/auth/sso/exchange', ['code' => str_repeat('a', 64)])
            ->assertUnauthorized();

        [$state, $nonce] = $this->startLogin();
        (new FakeIdp())->fakeHttp(['nonce' => $nonce, 'email' => 'a@example.com', 'email_verified' => true]);
        $callback = $this->get($this->callbackUrl($state));
        parse_str(parse_url($callback->headers->get('Location'), PHP_URL_QUERY), $query);

        $this->travel(2)->minutes();

        $this->postJson('/api/auth/sso/exchange', ['code' => $query['code']])->assertUnauthorized();
    }

    public function test_sso_routes_return_404_when_disabled(): void
    {
        config(['sso.enabled' => false]);

        $this->getJson('/api/auth/sso/providers')->assertNotFound();
        $this->get('/api/auth/sso/default/redirect')->assertNotFound();
        $this->get('/api/auth/sso/default/callback?code=x&state=y')->assertNotFound();
        $this->postJson('/api/auth/sso/exchange', ['code' => str_repeat('a', 64)])->assertNotFound();
    }

    public function test_sso_routes_return_404_in_integrated_mode(): void
    {
        config(['system.operation_mode' => 'integrated']);

        $this->getJson('/api/auth/sso/providers')->assertNotFound();
        $this->get('/api/auth/sso/default/redirect')->assertNotFound();
    }

    public function test_unknown_provider_returns_404(): void
    {
        $this->get('/api/auth/sso/nonexistent/redirect')->assertNotFound();
    }

    public function test_providers_endpoint_lists_only_enabled_providers(): void
    {
        config([
            'sso.providers.disabled_one' => FakeIdp::providerConfig(['enabled' => false]),
        ]);

        $response = $this->getJson('/api/auth/sso/providers');

        $response->assertOk();
        $this->assertSame(
            [['slug' => 'default', 'label' => 'Fake IdP', 'redirect_url' => url('/api/auth/sso/default/redirect')]],
            $response->json('data')
        );
    }

    public function test_issuer_mismatch_redirects_to_error_url(): void
    {
        $this->refakeHttp([
            FakeIdp::ISSUER.'/.well-known/openid-configuration' => Http::response(
                array_merge(FakeIdp::discoveryResponse(), ['issuer' => 'https://someone-else.test'])
            ),
        ]);

        $this->assertErrorRedirect(
            $this->get('/api/auth/sso/default/redirect'),
            'provider_not_configured'
        );
    }

    public function test_discovery_endpoint_on_a_foreign_host_redirects_to_error_url(): void
    {
        $this->refakeHttp([
            FakeIdp::ISSUER.'/.well-known/openid-configuration' => Http::response(
                array_merge(FakeIdp::discoveryResponse(), [
                    'jwks_uri' => 'https://attacker.test/certs',
                ])
            ),
        ]);

        $this->assertErrorRedirect(
            $this->get('/api/auth/sso/default/redirect'),
            'provider_not_configured'
        );
    }

    public function test_unreachable_provider_redirects_to_error_url(): void
    {
        $this->refakeHttp([
            FakeIdp::ISSUER.'/.well-known/openid-configuration' => Http::response('', 503),
        ]);

        $this->assertErrorRedirect(
            $this->get('/api/auth/sso/default/redirect'),
            'provider_unreachable'
        );
    }

    public function test_logout_redirects_through_the_idps_end_session_endpoint(): void
    {
        (new FakeIdp())->fakeHttp();

        $response = $this->get('/api/auth/sso/default/logout');

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringStartsWith(FakeIdp::ISSUER.'/protocol/openid-connect/logout?', $location);

        parse_str(parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame(FakeIdp::CLIENT_ID, $query['client_id']);
        $this->assertSame(self::FE_LOGIN, $query['post_logout_redirect_uri']);
    }

    public function test_logout_uses_configured_frontend_login_url_when_set(): void
    {
        config(['sso.frontend_login_url' => 'http://fe.test/login']);
        (new FakeIdp())->fakeHttp();

        $location = $this->get('/api/auth/sso/default/logout')->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $query);

        $this->assertSame('http://fe.test/login', $query['post_logout_redirect_uri']);
    }

    public function test_logout_derives_login_url_from_callback_origin_when_unset(): void
    {
        (new FakeIdp())->fakeHttp();

        $location = $this->get('/api/auth/sso/default/logout')->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $query);

        $this->assertSame('http://fe.test/login', $query['post_logout_redirect_uri']);
    }

    public function test_logout_falls_back_to_frontend_login_when_no_end_session_endpoint(): void
    {
        $this->refakeHttp([
            FakeIdp::ISSUER.'/.well-known/openid-configuration' => Http::response(
                collect(FakeIdp::discoveryResponse())->except('end_session_endpoint')->all()
            ),
        ]);

        $response = $this->get('/api/auth/sso/default/logout');

        $response->assertRedirect(self::FE_LOGIN);
    }

    public function test_logout_falls_back_to_frontend_login_for_unknown_provider(): void
    {
        $response = $this->get('/api/auth/sso/nonexistent/logout');

        $response->assertRedirect(self::FE_LOGIN);
    }

    public function test_logout_falls_back_to_frontend_login_when_provider_unreachable(): void
    {
        $this->refakeHttp([
            FakeIdp::ISSUER.'/.well-known/openid-configuration' => Http::response('', 503),
        ]);

        $response = $this->get('/api/auth/sso/default/logout');

        $response->assertRedirect(self::FE_LOGIN);
    }

    public function test_logout_returns_404_when_sso_disabled(): void
    {
        config(['sso.enabled' => false]);

        $this->get('/api/auth/sso/default/logout')->assertNotFound();
    }

    /**
     * Without id_token_hint an IdP cannot tell which session to end and stops
     * to ask the user, which reads as a broken logout. The ticket buys the
     * hint back without ever putting the id_token in the browser.
     */
    public function test_logout_ticket_from_the_token_supplies_the_id_token_hint(): void
    {
        [$state, $nonce] = $this->startLogin();
        (new FakeIdp())->fakeHttp([
            'sub' => 'subject-logout',
            'email' => 'logout@example.com',
            'email_verified' => true,
            'name' => 'Logout Tester',
            'nonce' => $nonce,
        ]);

        $callback = $this->get($this->callbackUrl($state));
        parse_str(parse_url($callback->headers->get('Location'), PHP_URL_QUERY), $handoff);
        $accessToken = $this->postJson('/api/auth/sso/exchange', ['code' => $handoff['code']])
            ->json('data.access_token');

        $ticket = $this->decodeJwtPayload($accessToken)['user']['sso_logout_ticket'];
        $this->assertNotEmpty($ticket);

        $location = $this->get('/api/auth/sso/default/logout?ticket='.$ticket)
            ->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $query);

        $this->assertNotEmpty($query['id_token_hint']);
        $this->assertSame(self::FE_LOGIN, $query['post_logout_redirect_uri']);

        // Single use - a replayed ticket must not resurrect the hint
        $replayed = $this->get('/api/auth/sso/default/logout?ticket='.$ticket)
            ->headers->get('Location');
        parse_str(parse_url($replayed, PHP_URL_QUERY), $replayedQuery);
        $this->assertArrayNotHasKey('id_token_hint', $replayedQuery);
    }

    public function test_logout_without_a_ticket_still_redirects_to_the_idp(): void
    {
        $location = $this->get('/api/auth/sso/default/logout')->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $query);

        $this->assertStringStartsWith(FakeIdp::ISSUER.'/protocol/openid-connect/logout?', $location);
        $this->assertArrayNotHasKey('id_token_hint', $query);
        $this->assertSame(FakeIdp::CLIENT_ID, $query['client_id']);
    }

    public function test_logout_ignores_an_unknown_ticket(): void
    {
        $location = $this->get('/api/auth/sso/default/logout?ticket='.str_repeat('a', 64))
            ->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $query);

        $this->assertArrayNotHasKey('id_token_hint', $query);
        $this->assertSame(self::FE_LOGIN, $query['post_logout_redirect_uri']);
    }
}
