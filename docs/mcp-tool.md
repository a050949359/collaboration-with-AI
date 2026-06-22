# 新增一個 MCP tool / server

本專案把多個 MCP server 掛在 `POST /api/mcp/*`，每個對應一組工具（task / memory / agyd / rag）。這份文件說明它們的串接方式，以及怎麼複製出一個新的 MCP server。

> 認證背景見 [architecture.md](architecture.md)：MCP 走 `UserApiKey`（`auth.apikey` + `apikey.scope`），帶 `Authorization: Bearer`，**以某 user 身分**呼叫。

---

## 1. 三個組成

| 角色 | 位置 | 職責 |
|---|---|---|
| Controller | `app/Http/Controllers/Mcp/XxxMcpController.php` | 解析 JSON-RPC，分派 `initialize` / `tools/list` / `tools/call` |
| Service | `app/Services/Mcp/XxxMcpService.php` | 實作 `McpToolServiceInterface`，是工具的實際邏輯 |
| Route + Scope | `routes/api.php` + `app/Enums/ApiKeyScope.php` | 掛 endpoint、定義並把關所需 scope |

### Service 契約

所有 MCP service 實作 [McpToolServiceInterface](../app/Services/Mcp/McpToolServiceInterface.php)：

```php
interface McpToolServiceInterface
{
    public function toolSchemas(): array;                       // tools/list 回傳的工具清單
    public function canHandle(string $name): bool;              // 是否認得某工具名
    public function call(string $name, array $args, mixed $id): JsonResponse;  // tools/call
}
```

### Controller 分派

Controller 很薄，把 JSON-RPC method 對應到 service。可直接複製 [TaskMcpController](../app/Http/Controllers/Mcp/TaskMcpController.php)：

```php
class TaskMcpController extends Controller
{
    public function __construct(private TaskMcpService $service) {}  // 建構子注入，免手動註冊

    public function handle(Request $request): JsonResponse
    {
        $body   = $request->json()->all();
        $method = $body['method'] ?? '';
        $id     = $body['id'] ?? null;
        $params = $body['params'] ?? [];

        return match ($method) {
            'initialize'  => $this->initialize($id),
            'tools/list'  => $this->ok($id, ['tools' => $this->service->toolSchemas()]),
            'tools/call'  => $this->service->call($params['name'] ?? '', $params['arguments'] ?? [], $id),
            default       => $this->error($id, -32601, 'Method not found'),
        };
    }
    // initialize / ok / error 都是 JSON-RPC 2.0 樣板，照抄即可
}
```

---

## 2. Scope 把關

每個 MCP endpoint 都要一個 scope，定義在 [ApiKeyScope](../app/Enums/ApiKeyScope.php)：

```php
enum ApiKeyScope: string
{
    case TaskMcp = 'task:mcp';
    case MemoryMcp = 'memory:mcp';   // adminOnly() = true
    case AgydMcp = 'agyd:mcp';       // adminOnly() = true
    case RagMcp = 'rag:mcp';

    public function adminOnly(): bool { /* 哪些 scope 只有 admin 能建 key */ }
}
```

- `adminOnly()` 回 `true` 的 scope，只有 admin 能簽發對應的 `UserApiKey`（如 memory / agyd）；其餘任何登入者可自建（如 task）。
- route 掛 `apikey.scope:<value>`，key 沒有該 scope → 403；key `scopes = null` → 一律 403。

---

## 3. 動態 schema（別 hardcode 合法值）

`toolSchemas()` 裡若工具參數有固定選項，**用 enum 動態產生**，不要寫死字串陣列：

```php
'status' => [
    'type' => 'string',
    'enum' => array_column(TaskStatus::cases(), 'value'),   // 跟著後端 enum 走
],
```

這樣後端加 enum case 時，MCP schema 自動跟上。

---

## 4. 註冊路由

在 [routes/api.php](../routes/api.php) 加一行（對齊既有寫法）：

```php
Route::post('/mcp/xxx', [XxxMcpController::class, 'handle'])
    ->middleware(['auth.apikey', 'apikey.scope:xxx:mcp']);
```

---

## 5. 配一支 Go CLI 客戶端（選用，但慣例如此）

既有 `cmd/taskctl`、`cmd/memctl`、`cmd/agydctl` 是打對應 MCP server 的精簡 Go client，取代冗長 curl、也免 native MCP 常駐省 token。新增一個 server 時建議照樣配一支 `cmd/xxxctl`：

- token / URL 自動讀 `.vscode/mcp.json`。
- binary 是 **gitignore**，clone 後需自行編譯：`cd cmd/xxxctl && go build -o xxxctl .`
- 直接執行 binary（不帶參數）即印 usage。

---

## 6. 新增 MCP server：checklist

1. **Service**：新增 `app/Services/Mcp/XxxMcpService.php`，實作 `McpToolServiceInterface`。
2. **Controller**：複製 `TaskMcpController` → `XxxMcpController`，改注入的 service。
3. **Scope**：在 `ApiKeyScope` 加 case（決定 `adminOnly()`）。
4. **Route**：`api.php` 加 `/mcp/xxx`，掛 `auth.apikey` + `apikey.scope:xxx:mcp`。
5. **Schema**：`toolSchemas()` 裡固定選項用 `array_column(enum::cases(), 'value')`。
6. **CLI**（選用）：配 `cmd/xxxctl`。
7. 簽發一把對應 scope 的 `UserApiKey` 測試（見 [api-keys.md](api-keys.md)）。
