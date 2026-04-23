# Flask Integration API Guide

This document describes how the Flask service should integrate with this Laravel API.

## Overview

There are two traffic directions:

1. Flask -> Laravel (internal API calls)
2. Laravel -> Flask (article-ready webhook)

## Base URL

Use your Laravel public base URL in production:

- `https://<your-laravel-domain>`

All endpoints below are under:

- `/api/...`

## Authentication Headers

### Flask -> Laravel

Send header:

- `X-Line-Bot-Key: <LINE_BOT_INTERNAL_API_KEY>`

Used by:

- `POST /api/line/friends/add`
- `POST /api/line/friends/remove`
- `POST /api/line/articles/quick-generate`

### Laravel -> Flask webhook

Laravel sends header:

- `X-Line-Webhook-Key: <LINE_BOT_OUTBOUND_WEBHOOK_KEY>`

Flask should validate this header before processing.

## Security Levels

### Level 1 (current minimum)

- API key header validation only
- Suitable for initial integration and internal testing

### Level 2 (recommended for production)

- API key header validation
- HMAC signature validation
- Timestamp window check
- Nonce replay protection

## HMAC Signature Spec (recommended)

Use the same convention for both directions (Flask -> Laravel and Laravel -> Flask).

### Required Headers

- `X-Timestamp`: unix epoch seconds (UTC)
- `X-Nonce`: random unique string per request
- `X-Signature`: hex-encoded HMAC-SHA256 signature

### Canonical String

Build canonical string as:

`<timestamp>.<nonce>.<raw_request_body>`

Notes:

- `raw_request_body` must be the exact raw JSON body bytes sent over HTTP.
- Do not pretty-print or re-serialize before signing.

### Signature Algorithm

- Algorithm: HMAC-SHA256
- Secret: shared secret dedicated for this direction
- Output: lowercase hex string

Pseudo formula:

`signature = hex(hmac_sha256(secret, canonical_string))`

### Verification Rules

1. API key must be valid first.
2. `X-Timestamp` must be within allowed window (suggested: 300 seconds).
3. `X-Nonce` must not be reused within window.
4. Recompute signature and compare with constant-time comparison.
5. If any check fails, return `401`.

## 1) Add or Bind LINE Friend

Create or update a local user binding using LINE user ID.

- Method: `POST`
- Path: `/api/line/friends/add`
- Headers:
  - `Content-Type: application/json`
  - `Accept: application/json`
  - `X-Line-Bot-Key: <LINE_BOT_INTERNAL_API_KEY>`

### Request Body

```json
{
  "line_user_id": "Uxxxxxxxxxxxxxxxxxxxx",
  "display_name": "Alice",
  "avatar_url": "https://profile.line-scdn.net/...."
}
```

### Success Response

- Status: `200`

```json
{
  "message": "LINE 好友已建立帳號",
  "created": true,
  "user_id": 123,
  "social_account_id": 456
}
```

or

```json
{
  "message": "LINE 好友已更新綁定",
  "created": false,
  "user_id": 123,
  "social_account_id": 456
}
```

## 2) Remove LINE Friend Binding

Remove LINE social binding by LINE user ID.

- Method: `POST`
- Path: `/api/line/friends/remove`
- Headers:
  - `Content-Type: application/json`
  - `Accept: application/json`
  - `X-Line-Bot-Key: <LINE_BOT_INTERNAL_API_KEY>`

### Request Body

```json
{
  "line_user_id": "Uxxxxxxxxxxxxxxxxxxxx"
}
```

### Success Response

- Status: `200`

```json
{
  "message": "LINE 綁定已移除",
  "removed": true
}
```

If not found:

```json
{
  "message": "找不到 LINE 綁定資料",
  "removed": false
}
```

## 3) Quick Generate Article (LINE Flow)

Trigger article content generation for a bound LINE user.

- Method: `POST`
- Path: `/api/line/articles/quick-generate`
- Headers:
  - `Content-Type: application/json`
  - `Accept: application/json`
  - `X-Line-Bot-Key: <LINE_BOT_INTERNAL_API_KEY>`

### Request Body

```json
{
  "line_user_id": "Uxxxxxxxxxxxxxxxxxxxx",
  "topic": "travel",
  "language": "zh-TW",
  "style": "practical",
  "prompt": "台南兩天一夜行程"
}
```

### Allowed values

- `topic`: `travel|food|technology|lifestyle|nature|culture|business|health`
- `language`: `zh-TW|zh-CN|en|ja`
- `style`: `practical|narrative|journalistic|casual`

### Accepted Response

- Status: `202`

```json
{
  "message": "文章生成已加入佇列",
  "article": {
    "id": 999,
    "user_id": 123,
    "content_status": "processing",
    "image_status": "pending"
  }
}
```

## 4) Webhook: Article Ready (Laravel -> Flask)

When article content generation is completed, Laravel sends a webhook to Flask.

Webhook target URL is configured in Laravel env:

- `LINE_BOT_ARTICLE_READY_WEBHOOK_URL`

### Flask endpoint example

- Method: `POST`
- Path: `/internal/webhook/article-ready`
- Headers expected:
  - `Content-Type: application/json`
  - `X-Line-Webhook-Key: <LINE_BOT_OUTBOUND_WEBHOOK_KEY>`

### Payload Example

```json
{
  "event": {
    "type": "article_ready",
    "event_id": "article_ready:999:user:123",
    "occurred_at": "2026-04-23T14:30:00+08:00"
  },
  "user": {
    "id": 123,
    "line_user_id": "Uxxxxxxxxxxxxxxxxxxxx",
    "name": "Alice"
  },
  "article": {
    "id": 999,
    "title": "台南兩天一夜散策",
    "summary": "古蹟、小吃與老街路線整理",
    "url": "https://<your-laravel-domain>/app/articles/999",
    "category": "travel",
    "created_via": "line",
    "content_generated_at": "2026-04-23T14:29:50+08:00"
  }
}
```

### Flask response suggestion

Return `200` with a simple body:

```json
{
  "ok": true
}
```

## Error Handling

Common statuses from Laravel internal APIs:

- `401`: missing/invalid `X-Line-Bot-Key`
- `404`: LINE binding not found
- `422`: validation error
- `429`: rate limit
- `500`: server error

## Suggested Flask Runtime Behavior

1. Validate auth headers before processing.
2. Validate HMAC signature (timestamp + nonce + raw body).
3. On webhook receive, dedupe by `event.event_id`.
4. Use retries with backoff for transient failures.
5. Log request id/event id for troubleshooting.

## Environment Variables (Laravel side)

Required for this integration:

- `LINE_BOT_INTERNAL_API_KEY`
- `LINE_BOT_ARTICLE_READY_WEBHOOK_URL`
- `LINE_BOT_OUTBOUND_WEBHOOK_KEY`
- `LINE_BOT_HMAC_REQUIRED`
- `LINE_BOT_HMAC_MAX_SKEW_SECONDS`

Recommended additional secrets for HMAC:

- `LINE_BOT_INBOUND_HMAC_SECRET` (Flask -> Laravel)
- `LINE_BOT_OUTBOUND_HMAC_SECRET` (Laravel -> Flask)
