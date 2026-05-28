<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Mcp\TaskMcpController;
use App\Http\Controllers\Mcp\MemoryMcpController;
use App\Http\Controllers\Task\TaskController;
use App\Http\Controllers\Task\TaskItemController;
use App\Http\Controllers\About\AboutController;
use App\Http\Controllers\About\ResumeContextController;
use App\Http\Controllers\Article\ArticleBrowseController;
use App\Http\Controllers\Article\ArticleCommentController;
use App\Http\Controllers\Article\ArticleEditController;
use App\Http\Controllers\Article\ArticleGenerationController;
use App\Http\Controllers\Auth\ChangePasswordController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\ProfileController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PublicKeyController;
use App\Http\Controllers\Auth\RegistController;
use App\Http\Middleware\DecryptPasswordFields;
use App\Http\Controllers\Auth\SocialAccountController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Aviation\AirportController;
use App\Http\Controllers\Aviation\AirportStatsController;
use App\Http\Controllers\Aviation\NearbyAirportController;
use App\Http\Controllers\Aviation\AirlineController;
use App\Http\Controllers\Aviation\CountryController;
use App\Http\Controllers\Aviation\CityController;
use App\Http\Controllers\Aviation\CityPreviewController;
use App\Http\Controllers\Aviation\CitySearchController;
use App\Http\Controllers\Line\LineAboutTokenController;
use App\Http\Controllers\Line\LineArticleController;
use App\Http\Controllers\Line\LineFriendController;
use App\Http\Controllers\Lab\WsLabController;
use App\Http\Controllers\MiniOrch\MiniOrchController;
use App\Http\Controllers\Story\CharacterController;
use App\Http\Controllers\Story\StorySetupController;
use App\Http\Controllers\Story\StorySessionController;
use App\Http\Controllers\Gacha\GachaRoomController;
use App\Http\Controllers\ApiKey\UserApiKeyController;
use App\Http\Controllers\Admin\ShareTokenController;
use App\Http\Middleware\EnsureAdmin;

Route::post('/about/ask', [AboutController::class, 'ask'])->middleware('throttle:4,1');

Route::post('/share-tokens/check', [ShareTokenController::class, 'check'])->middleware('throttle:3,1');

Route::middleware(['auth:sanctum', EnsureAdmin::class])->group(function () {
    Route::get('/about/context', [ResumeContextController::class, 'show']);
    Route::put('/about/context', [ResumeContextController::class, 'update']);

    Route::prefix('admin/share-tokens')->group(function () {
        Route::get('/',        [ShareTokenController::class, 'index']);
        Route::post('/',       [ShareTokenController::class, 'store']);
        Route::delete('/{id}', [ShareTokenController::class, 'destroy']);
    });
});

Route::group(['prefix' => 'auth'], function () {
    Route::get('/key', PublicKeyController::class);
    Route::post('/register', [RegistController::class, 'register'])->middleware([DecryptPasswordFields::class, 'turnstile']);
    Route::post('/login', [LoginController::class, 'login'])->middleware([DecryptPasswordFields::class, 'turnstile']);
    Route::post('/forgot-password', [ForgotPasswordController::class, 'sendLink'])->middleware('throttle:5,1');
    Route::post('/reset-password', [ForgotPasswordController::class, 'reset'])->middleware(DecryptPasswordFields::class);
    Route::get('/{provider}/redirect', [SocialAccountController::class, 'redirect'])->where(['provider' => 'google']);
    Route::get('/{provider}/callback', [SocialAccountController::class, 'callback'])->where(['provider' => 'google']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [LoginController::class, 'logout']);
        Route::get('/me', [LoginController::class, 'me']);
        Route::post('/change-password', [ChangePasswordController::class, 'change'])->middleware(DecryptPasswordFields::class);
        Route::patch('/name', [ProfileController::class, 'updateName']);

        // 點擊信件連結後觸發
        Route::get('/verify-email/{id}/{hash}', VerifyEmailController::class)
            ->middleware(['signed', 'throttle:6,1'])
            ->name('verification.verify');

        // 重新寄送驗證信
        Route::post('/email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
            ->middleware('throttle:6,1')
            ->name('verification.send');
    });
});

Route::middleware(['auth:sanctum', EnsureAdmin::class])->prefix('admin')->group(function () {
    Route::get('/settings', [SettingsController::class, 'show']);
    Route::patch('/settings', [SettingsController::class, 'update']);
});

Route::prefix('v1/articles')->group(function () {
    Route::get('/', [ArticleBrowseController::class, 'publicIndex']);
    Route::get('/{article}', [ArticleBrowseController::class, 'publicShow']);
    
    // comments
    Route::get('/{article}/comments', [ArticleCommentController::class, 'index']);
    Route::post('/{article}/comments', [ArticleCommentController::class, 'store'])->middleware('throttle:10,1');

});

Route::prefix('v1/comments')->middleware('throttle:20,1')->group(function () {
    Route::put('/{articleComment}', [ArticleCommentController::class, 'update']);
    Route::delete('/{articleComment}', [ArticleCommentController::class, 'destroy']);
});

Route::middleware('auth:sanctum')->prefix('articles')->group(function () {
    Route::get('/', [ArticleBrowseController::class, 'authIndex']);
    Route::post('/', [ArticleGenerationController::class, 'store']);
    Route::get('/{article}', [ArticleGenerationController::class, 'show']);
    Route::put('/{article}', [ArticleEditController::class, 'update']);
    Route::delete('/{article}', [ArticleEditController::class, 'destroy']);
    Route::post('/{article}/generate-content', [ArticleGenerationController::class, 'generateContent']);
    Route::post('/{article}/generate-image', [ArticleGenerationController::class, 'generateImage']);
});

Route::prefix('v1/airports')->middleware('throttle:60,1')->group(function () {
    Route::get('/',        [AirportController::class, 'index']);
    Route::get('/stats',   AirportStatsController::class);
    Route::get('/nearby',  NearbyAirportController::class)->middleware('throttle:30,1');
    Route::get('/{ident}', [AirportController::class, 'show']);
});

Route::prefix('v1/airlines')->middleware('throttle:60,1')->group(function () {
    Route::get('/', [AirlineController::class, 'index']);
});

Route::prefix('v1/countries')->middleware('throttle:60,1')->group(function () {
    Route::get('/',      [CountryController::class, 'index']);
    Route::get('/{code}', [CountryController::class, 'show']);
});

Route::prefix('v1/cities')->middleware('throttle:60,1')->group(function () {
    Route::get('/', [CityController::class, 'index']);
    Route::get('/preview', CityPreviewController::class)->middleware('throttle:20,1');
});

Route::prefix('v1/cities/search')->middleware(['auth:sanctum', 'verified', 'throttle:30,1'])->group(function () {
    Route::get('/',       [CitySearchController::class, 'index']);
    Route::post('/',      [CitySearchController::class, 'store']);
    Route::get('/{id}',   [CitySearchController::class, 'show']);
});

Route::prefix('line/friends')->group(function () {
    Route::post('/add', [LineFriendController::class, 'add'])->middleware('throttle:20,1');
    Route::post('/remove', [LineFriendController::class, 'remove'])->middleware('throttle:20,1');
});

Route::prefix('line/articles')->group(function () {
    Route::post('/quick-generate', [LineArticleController::class, 'quickGenerate'])->middleware('throttle:20,1');
});

Route::post('/line/about-token', [LineAboutTokenController::class, 'issue'])->middleware('throttle:10,1');

use App\Http\Controllers\Travel\PassengerController;
use App\Http\Controllers\Travel\TourController;
use App\Http\Controllers\Travel\BookingController;
use App\Http\Controllers\Travel\ExportController;
use App\Http\Controllers\Travel\StatsController;
use App\Http\Controllers\Travel\TourFlightController;
use App\Http\Controllers\Travel\TourHotelController;

Route::prefix('v1/tour')->group(function () {
    // 旅客（靜態路由必須在 {passenger} 之前）
    Route::get('/passengers/random', [PassengerController::class, 'random']);
    Route::get('/passengers/lookup', [PassengerController::class, 'lookup']);
    Route::get('/passengers/{passenger}', [PassengerController::class, 'show']);
    Route::get('/passengers', [PassengerController::class, 'index']);
    Route::post('/passengers', [PassengerController::class, 'store']);

    // 行程
    Route::get('/tours',      [TourController::class, 'index']);
    Route::post('/tours',     [TourController::class, 'store']);
    Route::put('/tours/{tour}', [TourController::class, 'update']);

    // 訂單
    Route::get('/bookings',   [BookingController::class, 'index']);
    Route::post('/bookings',  [BookingController::class, 'store']);

    // 匯出（Queue 主角）
    Route::get('/exports',               [ExportController::class, 'index']);
    Route::post('/exports',              [ExportController::class, 'store']);
    Route::get('/exports/{id}/status',   [ExportController::class, 'status']);
    Route::get('/exports/{id}/download', [ExportController::class, 'download']);

    Route::get('/stats', [StatsController::class, 'index']);

    Route::prefix('/{tour}')->group(function () {
        Route::get('/flights',           [TourFlightController::class, 'index']);
        Route::post('/flights',          [TourFlightController::class, 'store']);
        Route::delete('/flights/{flight}', [TourFlightController::class, 'destroy']);

        Route::get('/hotels',           [TourHotelController::class, 'index']);
        Route::post('/hotels',          [TourHotelController::class, 'store']);
        Route::delete('/hotels/{hotel}', [TourHotelController::class, 'destroy']);
    });
});


Route::prefix('ws-lab')->group(function () {
    Route::get('/status', [WsLabController::class, 'status']);
    Route::get('/rooms',  [WsLabController::class, 'rooms']);

    Route::middleware(['auth:sanctum', 'verified'])->group(function () {
        Route::post('/auth-token',   [WsLabController::class, 'authToken']);
        Route::post('/start',        [WsLabController::class, 'start']);
        Route::post('/stop',         [WsLabController::class, 'stop']);
        Route::post('/stream/start', [WsLabController::class, 'streamStart']);
        Route::post('/stream/stop',  [WsLabController::class, 'streamStop']);
    });
});

Route::prefix('mini-orch')->group(function () {
    Route::get('/dashboard', [MiniOrchController::class, 'dashboard']);

    Route::middleware(['auth:sanctum', 'verified'])->group(function () {
        Route::post('/runs',        [MiniOrchController::class, 'createRun']);
        Route::get('/runs/{runId}', [MiniOrchController::class, 'getRun']);
    });
});

Route::prefix('v1/characters')->middleware('throttle:20,1')->group(function () {
    Route::get('/',                              [CharacterController::class, 'index']);
    Route::post('/',                             [CharacterController::class, 'store']);
    Route::get('/{character}',                   [CharacterController::class, 'show']);
    Route::patch('/{character}',                 [CharacterController::class, 'update']);
    Route::delete('/{character}',                [CharacterController::class, 'destroy']);
    Route::post('/ai/generate',                  [CharacterController::class, 'generate']);
    Route::post('/ai/refine',                    [CharacterController::class, 'refine']);
    Route::post('/{character}/image-prompt',     [CharacterController::class, 'generateImagePrompt']);
});

Route::prefix('v1/story')->middleware('throttle:30,1')->group(function () {
    Route::post('/setup/generate', [StorySetupController::class, 'generate']);
    Route::post('/setup/refine',   [StorySetupController::class, 'refine']);

    Route::get('/sessions',                               [StorySessionController::class, 'index']);
    Route::post('/sessions',                              [StorySessionController::class, 'store']);
    Route::get('/sessions/{session}',                    [StorySessionController::class, 'show']);
    Route::patch('/sessions/{session}/status',           [StorySessionController::class, 'updateStatus']);
    Route::post('/sessions/{session}/player-turn',       [StorySessionController::class, 'playerTurn']);
});

Route::prefix('v1/gacha/rooms')->middleware('throttle:30,1')->group(function () {
    Route::get('/',                        [GachaRoomController::class, 'index']);
    Route::post('/',                       [GachaRoomController::class, 'store'])->middleware('auth:sanctum');
    Route::delete('/{code}',              [GachaRoomController::class, 'destroy'])->middleware('auth:sanctum');
    Route::post('/{code}/join',           [GachaRoomController::class, 'join']);
    Route::post('/{code}/draw',           [GachaRoomController::class, 'draw']);
    Route::post('/{code}/reset-draws',   [GachaRoomController::class, 'resetDraws'])->middleware('auth:sanctum');
});

// MCP JSON-RPC endpoint
Route::post('/mcp/task', [TaskMcpController::class, 'handle'])->middleware(['auth.apikey', 'apikey.scope:task:mcp']);
Route::post('/mcp/memory', [MemoryMcpController::class, 'handle'])->middleware('auth.apikey');

// Tasks
Route::prefix('v1/tasks')->group(function () {
    Route::get('/', [TaskController::class, 'index']);
    Route::get('/{task}', [TaskController::class, 'show']);
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/', [TaskController::class, 'store']);
        Route::patch('/{task}', [TaskController::class, 'update']);
        Route::delete('/{task}', [TaskController::class, 'destroy']);
        Route::post('/{task}/items', [TaskItemController::class, 'store']);
        Route::patch('/{task}/items/{item}', [TaskItemController::class, 'update']);
        Route::delete('/{task}/items/{item}', [TaskItemController::class, 'destroy']);
    });
});

// API 金鑰管理
Route::middleware('auth:sanctum')->prefix('v1/user-api-keys')->group(function () {
    Route::get('/', [UserApiKeyController::class, 'index']);
    Route::post('/', [UserApiKeyController::class, 'store']);
    Route::patch('/{id}', [UserApiKeyController::class, 'update']);
    Route::delete('/{id}', [UserApiKeyController::class, 'destroy']);
});

// Route::get('/debug-ip', fn() => response()->json([
//     'ip'              => request()->ip(),
//     'cf_connecting'   => request()->header('CF-Connecting-IP'),
//     'x_forwarded_for' => request()->header('X-Forwarded-For'),
// ]));