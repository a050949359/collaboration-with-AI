<?php

namespace App\Http\Middleware;

use App\Enums\AirportType;
use App\Enums\ApiKeyScope;
use App\Enums\ArticleAspectRatio;
use App\Enums\ArticleLanguage;
use App\Enums\ArticleStyle;
use App\Enums\ArticleTopic;
use App\Enums\CabinClass;
use App\Enums\ObservationType;
use App\Enums\PassengerFilter;
use App\Enums\RoomType;
use App\Enums\ShareTokenScope;
use App\Enums\StoryContentRating;
use App\Enums\StoryGenre;
use App\Enums\TaskStatus;
use App\Support\AppSettings;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * 這裡定義的資料會在每次 Inertia 請求時自動注入到前端所有頁面的 props 中。
     * 在 Vue 元件內可透過 `usePage().props` 取得，例如：
     *
     *   import { usePage } from '@inertiajs/vue3';
     *   const page = usePage();
     *   const user = page.props.auth?.user;      // 目前登入的使用者
     *   const isAdmin = page.props.auth?.is_admin; // 是否為管理員
     *
     * 注意：這裡的 user 是由 AuthTokenFromCookie middleware 先從 cookie 取出 token
     * 再交由 auth:sanctum guard 解析後得到的。
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'allowRegistration' => AppSettings::bool('allow_registration', true),
            'auth' => [
                'user' => $request->user('sanctum'),
                'is_admin' => $request->user('sanctum')?->isAdmin() ?? false,
            ],
            'apiKeyScopes' => $request->user('sanctum')
                ? array_values(array_filter(
                    array_map(fn ($s) => ['value' => $s->value, 'adminOnly' => $s->adminOnly()], ApiKeyScope::cases()),
                    fn ($s) => ! $s['adminOnly'] || $request->user('sanctum')->isAdmin()
                ))
                : [],
            ...$this->pageProps($request),
        ];
    }

    /**
     * 依路由注入頁面專屬 props（靜態 enum / config）。
     * key 需與前端 usePage().props 一致。
     *
     * @return array<string, mixed>
     */
    private function pageProps(Request $request): array
    {
        return match (true) {
            $request->routeIs('admin') => [
                'shareTokenScopes' => array_column(ShareTokenScope::cases(), 'value'),
                'llmCatalog' => array_map(
                    fn ($p) => array_values($p['models'] ?? []),
                    config('services.llm.providers', []),
                ),
                'llmSettings' => AppSettings::get('llm') ?: config('services.llm.uses', []),
            ],
            $request->routeIs('task') => [
                'taskStatuses' => array_column(TaskStatus::cases(), 'value'),
            ],
            $request->routeIs('story-relay') => [
                'storyGenres' => array_column(StoryGenre::cases(), 'value'),
                'contentRatings' => array_column(StoryContentRating::cases(), 'value'),
            ],
            $request->routeIs('airports') => [
                'airportTypes' => array_column(AirportType::cases(), 'value'),
            ],
            $request->routeIs('tour-playground') => [
                'passengerFilters' => array_column(PassengerFilter::cases(), 'value'),
                'cabinClasses' => array_column(CabinClass::cases(), 'value'),
                'roomTypes' => array_column(RoomType::cases(), 'value'),
            ],
            $request->routeIs('articles.generate.new') => [
                'articleAspectRatios' => array_column(ArticleAspectRatio::cases(), 'value'),
                'articleTopics' => array_column(ArticleTopic::cases(), 'value'),
                'articleLanguages' => array_column(ArticleLanguage::cases(), 'value'),
                'articleStyles' => array_column(ArticleStyle::cases(), 'value'),
            ],
            $request->routeIs('memory') => [
                // 可編輯的 typed（非 desc）類型 + 各自上限，供節點編輯面板 type 下拉
                'observationTypes' => array_values(array_map(
                    fn ($t) => ['value' => $t->value, 'maxCount' => $t->maxCount()],
                    array_filter(ObservationType::cases(), fn ($t) => $t !== ObservationType::Desc),
                )),
            ],
            default => [],
        };
    }
}
