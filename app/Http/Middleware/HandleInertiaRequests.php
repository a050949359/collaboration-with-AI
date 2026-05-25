<?php

namespace App\Http\Middleware;

use App\Enums\ShareTokenScope;
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
            'auth' => [
                'user'     => $request->user('sanctum'),
                'is_admin' => $request->user('sanctum')?->isAdmin() ?? false,
            ],
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
            ],
            default => [],
        };
    }
}
