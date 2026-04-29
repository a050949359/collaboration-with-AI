# Copilot Instructions

## Coding Guidelines

Behavioral guidelines to reduce common LLM coding mistakes.
**Tradeoff:** These guidelines bias toward caution over speed. For trivial tasks, use judgment.

### 1. Think Before Coding

Don't assume. Don't hide confusion. Surface tradeoffs.

Before implementing:
- State your assumptions explicitly. If uncertain, ask.
- If multiple interpretations exist, present them - don't pick silently.
- If a simpler approach exists, say so. Push back when warranted.
- If something is unclear, stop. Name what's confusing. Ask.

### 2. Simplicity First

Minimum code that solves the problem. Nothing speculative.

- No features beyond what was asked.
- No abstractions for single-use code.
- No "flexibility" or "configurability" that wasn't requested.
- No error handling for impossible scenarios.
- If you write 200 lines and it could be 50, rewrite it.

Ask yourself: "Would a senior engineer say this is overcomplicated?" If yes, simplify.

### 3. Surgical Changes

Touch only what you must. Clean up only your own mess.

When editing existing code:
- Don't "improve" adjacent code, comments, or formatting.
- Don't refactor things that aren't broken.
- Match existing style, even if you'd do it differently.
- If you notice unrelated dead code, mention it - don't delete it.

When your changes create orphans:
- Remove imports/variables/functions that YOUR changes made unused.
- Don't remove pre-existing dead code unless asked.

The test: Every changed line should trace directly to the user's request.

### 4. Goal-Driven Execution

Define success criteria. Loop until verified.

Transform tasks into verifiable goals:
- "Add validation" → "Write tests for invalid inputs, then make them pass"
- "Fix the bug" → "Write a test that reproduces it, then make it pass"
- "Refactor X" → "Ensure tests pass before and after"

For multi-step tasks, state a brief plan:
```
1. [Step] → verify: [check]
2. [Step] → verify: [check]
3. [Step] → verify: [check]
```

Strong success criteria let you loop independently. Weak criteria ("make it work") require constant clarification.

---

## Project Skills

以下指令可觸發對應的 skill，skill 檔案位於 `.github/skills/`：

| 指令 | Skill | 說明 |
|------|-------|------|
| `指令` | help | 顯示所有可用指令 |
| `ps` | project-context | 恢復專案狀態 |
| `ps local` | project-context | 第一次初始化（local 模式）|
| `ps .gitmodules` | project-context | 第一次初始化（submodule 模式）|
| `ps save` | project-context | 開發中斷，記錄當前進度 |
| `ps close` | project-context | 功能完成，更新專案狀態 |
| `ps log` | project-context | 記錄技術決策至 dev-context.yml |
| `as` | api-status | API 開發狀態與安全機制追蹤 |
| `cm` | commit-message | 依據 git diff 產生 commit 指令 |
| `cr` | laravel-backend-security | Laravel 安全規範審查 |
| `cicd` | laravel-ci-cd | CI/CD 規範與測試撰寫 |
