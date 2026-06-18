# API Key 與 Scope

讓**外部 client(Claude Desktop/Code、其他程式)** 用 Bearer token 呼叫 MCP 端點。每把 key 帶 **scope**,決定能打哪些 endpoint。

> [!NOTE]
> 這跟 **ShareToken**(About/LINE 分享連結)是兩套不同東西。本篇只講 **`UserApiKey`(MCP 用)**。

## Scope 一覽(`app/Enums/ApiKeyScope.php`)

| Scope | 端點 | 誰能建 |
|-------|------|--------|
| `task:mcp` | `POST /api/mcp/task` | 任何登入者 |
| `memory:mcp` | `POST /api/mcp/memory` | **admin only** |
| `agyd:mcp` | `POST /api/mcp/agyd` | **admin only** |
| `rag:mcp` | `POST /api/mcp/rag` | 任何登入者 |

`adminOnly()` 決定哪些 scope 需要 admin 才能建(目前 memory / agyd)。

## 認證 + 授權流程

```
client ──Authorization: Bearer <raw key>──▶ /api/mcp/*
   │
   ├─ auth.apikey (AuthenticateWithApiKey):sha256(raw) 比對 api_key_hash → 找到 UserApiKey
   │      → Auth::setUser(key.user) + 標記 api_key_authed + 帶 api_key_scopes
   └─ apikey.scope:<scope> (CheckApiKeyScope):驗 scopes 含該 scope,否則 401/403
```
- 身分 = **key 所屬 user**(MCP 內所有 `Auth::id()` 都是這個人;owner 隔離靠它)。
- 兩層:`auth.apikey`(你是誰)+ `apikey.scope:X`(能不能打這支)。

## 建立 / 管理 key

- **REST**(session 驗證,使用者管自己的):`/api/v1/user-api-keys` GET/POST/PATCH/DELETE(`UserApiKeyController`)。
- **建立時**(`store`):
  - 產生 48 字元 raw key → 存 **`sha256` hash**(`api_key_hash`,DB 不存明文)。
  - **明文只在回應回傳一次**(且以前端 public key 走 **RSA-OAEP/SHA-256 加密**後回,同 password 流程)→ 之後再也拿不到,要重建。
  - scope 驗證:若含 `adminOnly` scope 但非 admin → **403**。

## 注意 / 技術債

- **明文 key 不可復原**:只在建立當下出現一次,遺失就 revoke 重建。
- **`scopes = null` ＝ 打不了任何 MCP**:把關的 `CheckApiKeyScope` 對 null scope 回 **403**(不是「無限制」)。建 key 一定要給 scope。

## 新增一個 scope

1. `ApiKeyScope` 加 case(+ 視需要 `adminOnly()`)。
2. 新 MCP 路由掛 `->middleware(['auth.apikey', 'apikey.scope:<新scope>'])`。
3. 前端建 key UI 的 scope 選項若由後端提供,記得讓它拿得到 `ApiKeyScope::values()`。

## 相關
- MCP 端點與工具:[mcp.md](mcp.md)(待寫)。
- 本機 CLI client(taskctl/memctl/agydctl)讀 `.vscode/mcp.json` 的 token 打這些端點。
