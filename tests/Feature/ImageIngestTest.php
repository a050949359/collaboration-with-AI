<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Image\ImageIngestService;
use App\Services\Image\ImageRejectedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ImageIngestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        Storage::fake('public');
        // 開關預設全關;既有行為測試需在「功能開啟」前提下驗證。
        config([
            'images.enabled' => true,
            'images.upload_enabled' => true,
            'images.public_upload_enabled' => true,
            'images.url_download_enabled' => true,
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /** 對應 ImageIngestService::pathFor 的分桶路徑 */
    private function storedPath(string $id): string
    {
        return 'images/'.substr($id, 0, 2)."/{$id}.webp";
    }

    public function test_authenticated_user_can_upload_image_and_it_is_stored_as_webp(): void
    {
        $user = $this->admin();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/images', [
                'file' => UploadedFile::fake()->image('photo.png', 64, 64),
            ]);

        $response->assertCreated()->assertJsonStructure(['id', 'url']);

        $id = $response->json('id');
        Storage::disk('private')->assertExists($this->storedPath($id));
    }

    public function test_public_visibility_stores_on_public_disk_and_returns_storage_url(): void
    {
        $user = $this->admin();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/images', [
                'file' => UploadedFile::fake()->image('art.png', 64, 64),
                'visibility' => 'public',
            ]);

        $response->assertCreated()->assertJsonPath('visibility', 'public');

        $id = $response->json('id');
        Storage::disk('public')->assertExists($this->storedPath($id));
        Storage::disk('private')->assertMissing($this->storedPath($id));
        // public 回傳 /storage 直連 URL,不是鑑權路由
        $this->assertStringContainsString('/storage/', (string) $response->json('url'));
    }

    public function test_default_visibility_is_private(): void
    {
        $user = $this->admin();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/images', [
                'file' => UploadedFile::fake()->image('secret.png', 32, 32),
            ]);

        $response->assertCreated()->assertJsonPath('visibility', 'private');

        $id = $response->json('id');
        Storage::disk('private')->assertExists($this->storedPath($id));
        Storage::disk('public')->assertMissing($this->storedPath($id));
    }

    public function test_invalid_default_visibility_config_falls_back_to_private(): void
    {
        config(['images.default_visibility' => 'bogus']); // 壞掉的 config

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/images', ['file' => UploadedFile::fake()->image('p.png', 32, 32)]);

        $response->assertCreated()->assertJsonPath('visibility', 'private'); // 不 500,退回 private
    }

    public function test_served_image_has_webp_and_nosniff_headers(): void
    {
        $user = $this->admin();

        $id = $this->actingAs($user, 'sanctum')
            ->postJson('/api/images', ['file' => UploadedFile::fake()->image('p.png', 32, 32)])
            ->json('id');

        $response = $this->actingAs($user, 'sanctum')->get("/api/images/{$id}");

        $response->assertOk();
        $this->assertSame('image/webp', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function test_gif_upload_is_rejected(): void
    {
        $user = $this->admin();

        // UploadedFile::fake()->image('x.gif') 會產生真正的 gif → 內容層 MIME 閘擋下
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/images', [
                'file' => UploadedFile::fake()->image('anim.gif', 32, 32),
            ]);

        $response->assertStatus(422);
        $this->assertCount(0, Storage::disk('private')->allFiles('images'));
    }

    public function test_non_image_disguised_as_jpg_is_rejected(): void
    {
        $user = $this->admin();

        $fake = UploadedFile::fake()->createWithContent('evil.jpg', "<?php echo 'pwn'; ?>");

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/images', ['file' => $fake]);

        $response->assertStatus(422);
        $this->assertCount(0, Storage::disk('private')->allFiles('images'));
    }

    public function test_non_admin_cannot_upload(): void
    {
        $user = User::factory()->create(); // 一般登入者

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/images', ['file' => UploadedFile::fake()->image('p.png', 32, 32)]);

        $response->assertForbidden();
        $this->assertCount(0, Storage::disk('private')->allFiles('images'));
    }

    public function test_logged_in_user_can_upload_public_via_public_endpoint(): void
    {
        $user = User::factory()->create(); // 一般登入者(非 admin)

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/images/public', ['file' => UploadedFile::fake()->image('p.png', 32, 32)]);

        $response->assertCreated()->assertJsonPath('visibility', 'public');

        $id = $response->json('id');
        Storage::disk('public')->assertExists($this->storedPath($id));
        $this->assertStringContainsString('/storage/', (string) $response->json('url'));
    }

    public function test_public_endpoint_forces_public_even_if_private_requested(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/images/public', [
                'file' => UploadedFile::fake()->image('p.png', 32, 32),
                'visibility' => 'private', // 應被忽略
            ]);

        $response->assertCreated()->assertJsonPath('visibility', 'public');

        $id = $response->json('id');
        Storage::disk('public')->assertExists($this->storedPath($id));
        Storage::disk('private')->assertMissing($this->storedPath($id));
    }

    public function test_public_file_limit_blocks_when_reached(): void
    {
        config(['images.public_max_files' => 1]);

        $user = User::factory()->create();

        // 第一張 OK(達上限)
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/images/public', ['file' => UploadedFile::fake()->image('a.png', 32, 32)])
            ->assertCreated();

        // 第二張被擋
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/images/public', ['file' => UploadedFile::fake()->image('b.png', 32, 32)])
            ->assertStatus(422);

        $this->assertCount(1, Storage::disk('public')->allFiles('images'));
    }

    public function test_public_file_limit_zero_means_unlimited(): void
    {
        config(['images.public_max_files' => 0]);

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/images/public', ['file' => UploadedFile::fake()->image('p.png', 32, 32)])
            ->assertCreated();
    }

    public function test_public_file_limit_does_not_affect_private_upload(): void
    {
        config(['images.public_max_files' => 1]);

        // 先把 public 灌到上限
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/images/public', ['file' => UploadedFile::fake()->image('a.png', 32, 32)])
            ->assertCreated();

        // private(admin)不受 public 檔數上限影響
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/images', ['file' => UploadedFile::fake()->image('b.png', 32, 32)])
            ->assertCreated()
            ->assertJsonPath('visibility', 'private');
    }

    public function test_redis_driver_blocks_when_shard_sum_reaches_cap(): void
    {
        config(['images.public_count_driver' => 'redis', 'images.public_max_files' => 5]);
        Redis::shouldReceive('hgetall')->andReturn(['00' => 3, '01' => 2]); // 加總 5 >= 5

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/images/public', ['file' => UploadedFile::fake()->image('p.png', 32, 32)])
            ->assertStatus(422);
    }

    public function test_redis_driver_increments_shard_on_success(): void
    {
        config(['images.public_count_driver' => 'redis', 'images.public_max_files' => 100]);
        Redis::shouldReceive('hgetall')->andReturn(['00' => 1]); // 未達上限
        Redis::shouldReceive('exists')->andReturn(true);         // hash 已 seed
        Redis::shouldReceive('hincrby')->once();                 // 寫入後維護計數

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/images/public', ['file' => UploadedFile::fake()->image('p.png', 32, 32)])
            ->assertCreated();
    }

    public function test_redis_driver_cold_start_seeds_from_filesystem(): void
    {
        config(['images.public_count_driver' => 'redis', 'images.public_max_files' => 1]);
        // FS 已有一張(模擬冷啟動前既有檔)
        Storage::disk('public')->put('images/ab/'.Str::uuid().'.webp', 'x');
        Redis::shouldReceive('hgetall')->andReturn([]); // hash 不存在 → 冷啟動
        Redis::shouldReceive('hmset')->once();          // 從 FS seed

        $user = User::factory()->create();

        // seed 後 total=1 已達上限 → 擋
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/images/public', ['file' => UploadedFile::fake()->image('p.png', 32, 32)])
            ->assertStatus(422);
    }

    public function test_redis_driver_empty_dir_seeds_placeholder_to_avoid_restampede(): void
    {
        config(['images.public_count_driver' => 'redis', 'images.public_max_files' => 5]);
        Redis::shouldReceive('hgetall')->andReturn([]); // 空目錄 → 冷啟動
        Redis::shouldReceive('hmset')->once();          // 寫入(含 _seeded 佔位,即使 0 張)
        Redis::shouldReceive('exists')->andReturn(true); // seed 後 hash 已存在
        Redis::shouldReceive('hincrby')->once();        // 上傳後計數

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/images/public', ['file' => UploadedFile::fake()->image('p.png', 32, 32)])
            ->assertCreated();
    }

    public function test_guest_cannot_use_public_endpoint(): void
    {
        $response = $this->postJson('/api/images/public', [
            'file' => UploadedFile::fake()->image('p.png', 32, 32),
        ]);

        $response->assertUnauthorized();
    }

    public function test_guest_cannot_upload(): void
    {
        $response = $this->postJson('/api/images', [
            'file' => UploadedFile::fake()->image('p.png', 32, 32),
        ]);

        $response->assertUnauthorized();
    }

    public function test_non_admin_cannot_view_private_image(): void
    {
        // 出圖也綁 admin:admin 上傳的 private 圖,一般登入者不可讀
        $id = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/images', ['file' => UploadedFile::fake()->image('p.png', 32, 32)])
            ->json('id');

        $viewer = User::factory()->create();
        $response = $this->actingAs($viewer, 'sanctum')->get("/api/images/{$id}");

        $response->assertForbidden();
    }

    public function test_guest_cannot_fetch_image(): void
    {
        $response = $this->getJson('/api/images/'.Str::uuid());
        $response->assertUnauthorized();
    }

    public function test_unknown_id_returns_404(): void
    {
        $user = $this->admin();

        $response = $this->actingAs($user, 'sanctum')
            ->get('/api/images/'.Str::uuid());

        $response->assertNotFound();
    }

    public function test_non_uuid_path_does_not_match_route(): void
    {
        $user = $this->admin();

        // 路徑含 traversal 字元,被路由 where regex 擋掉 → 404
        $response = $this->actingAs($user, 'sanctum')->get('/api/images/..%2f..%2fetc');
        $response->assertNotFound();
    }

    public function test_url_pointing_to_private_address_is_rejected(): void
    {
        $service = app(ImageIngestService::class);

        $this->expectException(ImageRejectedException::class);
        $service->fromUrl('http://169.254.169.254/latest/meta-data/');
    }

    public function test_url_pointing_to_loopback_is_rejected(): void
    {
        $service = app(ImageIngestService::class);

        $this->expectException(ImageRejectedException::class);
        $service->fromUrl('http://127.0.0.1/secret');
    }

    public function test_non_http_scheme_is_rejected(): void
    {
        $service = app(ImageIngestService::class);

        $this->expectException(ImageRejectedException::class);
        $service->fromUrl('file:///etc/passwd');
    }

    public function test_master_switch_off_makes_routes_404(): void
    {
        config(['images.enabled' => false]);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/images', ['file' => UploadedFile::fake()->image('p.png', 32, 32)])
            ->assertNotFound();

        $this->actingAs($this->admin(), 'sanctum')
            ->get('/api/images/'.Str::uuid())
            ->assertNotFound();
    }

    public function test_upload_switch_off_returns_403(): void
    {
        config(['images.upload_enabled' => false]);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/images', ['file' => UploadedFile::fake()->image('p.png', 32, 32)])
            ->assertForbidden();
    }

    public function test_public_upload_switch_off_returns_403(): void
    {
        config(['images.public_upload_enabled' => false]);

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/images/public', ['file' => UploadedFile::fake()->image('p.png', 32, 32)])
            ->assertForbidden();
    }

    public function test_url_download_switch_off_returns_403(): void
    {
        config(['images.url_download_enabled' => false]);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/images', ['url' => 'https://example.com/a.png'])
            ->assertForbidden();
    }

    public function test_url_download_switch_off_blocks_service_layer(): void
    {
        config(['images.url_download_enabled' => false]);

        $this->expectException(ImageRejectedException::class);
        app(ImageIngestService::class)->fromUrl('https://example.com/a.png');
    }

    public function test_oversized_upload_rejected_before_reading_into_memory(): void
    {
        config(['images.max_bytes' => 1024 * 1024]); // 1MB

        // 2MB 檔,getSize 先擋,不會整包讀進記憶體
        $big = UploadedFile::fake()->create('big.png', 2048);

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/images', ['file' => $big]);

        $response->assertStatus(422);
        $this->assertCount(0, Storage::disk('private')->allFiles('images'));
    }

    public function test_malformed_url_is_rejected(): void
    {
        // parse_url 對非法 port 回 false,不應觸發 Warning,應直接擋
        $this->expectException(ImageRejectedException::class);
        app(ImageIngestService::class)->fromUrl('http://example.com:notaport/x');
    }

    public function test_ipv6_loopback_url_is_rejected(): void
    {
        // gethostbynamel 不查 AAAA;改用 dns_get_record 後私有 IPv6 也擋
        $this->expectException(ImageRejectedException::class);
        app(ImageIngestService::class)->fromUrl('http://[::1]/x');
    }

    public function test_ipv4_mapped_ipv6_loopback_is_rejected(): void
    {
        // ::ffff:127.0.0.1 須先還原成 127.0.0.1 再驗,否則 filter_var 看不出私網
        $this->expectException(ImageRejectedException::class);
        app(ImageIngestService::class)->fromUrl('http://[::ffff:127.0.0.1]/x');
    }

    public function test_null_visibility_defaults_to_private(): void
    {
        // 帶 visibility:null 不應 500,應退回預設 private
        $response = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/images', [
                'file' => UploadedFile::fake()->image('p.png', 32, 32),
                'visibility' => null,
            ]);

        $response->assertCreated()->assertJsonPath('visibility', 'private');
    }

    public function test_redirect_to_protocol_relative_internal_is_rejected(): void
    {
        // 起點為公開 IP,被導去 //127.0.0.1/.. → 下一跳重驗應擋下(且 // 不被誤判成路徑)
        Http::fake(['*' => Http::response('', 302, ['Location' => '//127.0.0.1/evil'])]);

        $this->expectException(ImageRejectedException::class);
        app(ImageIngestService::class)->fromUrl('http://8.8.8.8/start');
    }

    public function test_oversized_remote_body_is_rejected_while_streaming(): void
    {
        config(['images.max_bytes' => 10]);
        Http::fake(['*' => Http::response(str_repeat('A', 5000), 200)]);

        $this->expectException(ImageRejectedException::class);
        app(ImageIngestService::class)->fromUrl('http://8.8.8.8/big');
    }
}
