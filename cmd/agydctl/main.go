// agydctl — agyd daemon MCP（/api/mcp/agyd）的精簡 CLI client。
// 目的同 taskctl / memctl：省去 native MCP 的 context 常駐 schema 與冗長 curl。
// token / url 自動從 .vscode/mcp.json 讀，或用 MCP_TOKEN/MCP_BASE_URL 環境變數覆寫。
//
// # 架構
//
//	agydctl → POST /api/mcp/agyd（Laravel, JSON-RPC 2.0）
//	              → ZeroTier 內網 → Go HTTP daemon（本地微型主機）
//
// daemon 工作完成後會 ZIP 靜態產出，POST 回 /api/agyd/upload/{task_id}，
// 解壓後放在 storage/app/public/agy/{task_id}/。
//
// # 設定
//
// 優先序：MCP_TOKEN 環境變數 > .vscode/mcp.json（往上層目錄找）
//
// .vscode/mcp.json 格式（server key 任意，以 URL suffix 比對）：
//
//	{
//	  "servers": {
//	    "collab-agyd": {
//	      "url": "https://ohya.vip/api/mcp/agyd",
//	      "headers": { "Authorization": "Bearer <agyd:mcp scope key>" }
//	    }
//	  }
//	}
//
// # 用法
//
//	agydctl run-prompt [--label l] <prompt...>   在本地微型主機上提交 agy 工作（非同步）
//	agydctl run-script [--label l] <name>        執行 daemon 預定義 script（非同步）
//	agydctl scripts                              列出 daemon 所有可用 scripts
//	agydctl status <task_id>                     查詢工作狀態（running/done/failed）
//	agydctl log <task_id>                        取得工作 stdout/stderr 輸出
//	  （任意位置加 --json 印原始 JSON）
//
// # 典型流程
//
//	$ agydctl run-prompt --label build-cv "建一個展示頁，放在 /dist"
//	task_id : abc-123
//	label   : build-cv
//	status  : queued
//
//	$ agydctl status abc-123
//	task_id : abc-123
//	status  : done
//	started : 2026-06-12T10:00:00Z
//	finished: 2026-06-12T10:02:30Z
//	exit    : 0
//
//	$ agydctl log abc-123
//	[agy] Starting task...
//	...
package main

import (
	"bytes"
	"encoding/json"
	"flag"
	"fmt"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"time"
)

const defaultBase = "https://ohya.vip/api/mcp"

func main() {
	jsonOut, rest := extractFlag(os.Args[1:], "--json")
	if len(rest) == 0 {
		usage()
		os.Exit(2)
	}
	cmd, rest := rest[0], rest[1:]

	url, token, err := resolveEndpoint("agyd")
	check(err)

	switch cmd {
	case "run-prompt":
		fs := flag.NewFlagSet("run-prompt", flag.ExitOnError)
		label := fs.String("label", "", "工作標籤（選填）")
		fs.Parse(rest)
		prompt := strings.Join(fs.Args(), " ")
		if prompt == "" {
			fmt.Fprintln(os.Stderr, "用法：agydctl run-prompt [--label l] <prompt...>")
			os.Exit(2)
		}
		a := map[string]any{"prompt": prompt}
		if *label != "" {
			a["label"] = *label
		}
		out := must(call(url, token, "bg_run_prompt", a))
		emit(out, jsonOut, printRunResult)

	case "run-script":
		fs := flag.NewFlagSet("run-script", flag.ExitOnError)
		label := fs.String("label", "", "工作標籤（選填，預設同 name）")
		fs.Parse(rest)
		if fs.NArg() == 0 {
			fmt.Fprintln(os.Stderr, "用法：agydctl run-script [--label l] <name>")
			os.Exit(2)
		}
		name := fs.Arg(0)
		a := map[string]any{"name": name}
		if *label != "" {
			a["label"] = *label
		}
		out := must(call(url, token, "bg_run_script", a))
		emit(out, jsonOut, printRunResult)

	case "scripts":
		out := must(call(url, token, "bg_list_scripts", map[string]any{}))
		emit(out, jsonOut, printScripts)

	case "status":
		if len(rest) == 0 {
			fmt.Fprintln(os.Stderr, "用法：agydctl status <task_id>")
			os.Exit(2)
		}
		out := must(call(url, token, "bg_status", map[string]any{"task_id": rest[0]}))
		emit(out, jsonOut, printStatus)

	case "log":
		if len(rest) == 0 {
			fmt.Fprintln(os.Stderr, "用法：agydctl log <task_id>")
			os.Exit(2)
		}
		out := must(call(url, token, "bg_log", map[string]any{"task_id": rest[0]}))
		emit(out, jsonOut, printLog)

	default:
		usage()
		os.Exit(2)
	}
}

// ── 輸出格式（agyd 專屬）─────────────────────────────────────
//
// 以下 struct 對應 Go daemon HTTP API 的回傳格式；
// daemon 實作時需保持 JSON key 一致（snake_case）。

// POST /run、POST /run-script 的回傳
type runResult struct {
	TaskID string `json:"task_id"` // 唯一工作 ID，用於後續 status/log 查詢
	Label  string `json:"label"`   // 工作標籤，方便辨識
	Status string `json:"status"`  // 初始狀態，通常為 "queued"
}

// GET /status/{task_id} 的回傳
type statusResult struct {
	TaskID     string  `json:"task_id"`
	Label      string  `json:"label"`
	Status     string  `json:"status"`      // running | done | failed
	StartedAt  *string `json:"started_at"`  // RFC 3339；nil 代表尚未開始（queued）
	FinishedAt *string `json:"finished_at"` // nil 代表尚未結束
	ExitCode   *int    `json:"exit_code"`   // nil 代表尚未結束；0 = 成功
}

// GET /log/{task_id} 的回傳
type logResult struct {
	TaskID string `json:"task_id"`
	Log    string `json:"log"` // stdout + stderr 合併的完整輸出
}

// GET /scripts 的單筆項目
type scriptEntry struct {
	Name        string `json:"name"`        // script 識別名稱，傳給 run-script
	Description string `json:"description"` // 人類可讀說明（選填）
}

func printRunResult(text string) {
	var r runResult
	if json.Unmarshal([]byte(text), &r) != nil || r.TaskID == "" {
		fmt.Println(text)
		return
	}
	fmt.Printf("task_id : %s\n", r.TaskID)
	if r.Label != "" {
		fmt.Printf("label   : %s\n", r.Label)
	}
	if r.Status != "" {
		fmt.Printf("status  : %s\n", r.Status)
	}
}

func printStatus(text string) {
	var s statusResult
	if json.Unmarshal([]byte(text), &s) != nil || s.TaskID == "" {
		fmt.Println(text)
		return
	}
	fmt.Printf("task_id : %s\n", s.TaskID)
	if s.Label != "" {
		fmt.Printf("label   : %s\n", s.Label)
	}
	fmt.Printf("status  : %s\n", s.Status)
	if s.StartedAt != nil {
		fmt.Printf("started : %s\n", *s.StartedAt)
	}
	if s.FinishedAt != nil {
		fmt.Printf("finished: %s\n", *s.FinishedAt)
	}
	if s.ExitCode != nil {
		fmt.Printf("exit    : %d\n", *s.ExitCode)
	}
}

func printLog(text string) {
	var l logResult
	if json.Unmarshal([]byte(text), &l) != nil {
		fmt.Println(text)
		return
	}
	if l.Log != "" {
		fmt.Print(l.Log)
		if !strings.HasSuffix(l.Log, "\n") {
			fmt.Println()
		}
	}
}

func printScripts(text string) {
	var ss []scriptEntry
	if json.Unmarshal([]byte(text), &ss) != nil {
		fmt.Println(text)
		return
	}
	if len(ss) == 0 {
		fmt.Println("（無可用 scripts）")
		return
	}
	fmt.Printf("SCRIPTS (%d)\n", len(ss))
	for _, s := range ss {
		if s.Description != "" {
			fmt.Printf("  %-24s %s\n", s.Name, s.Description)
		} else {
			fmt.Printf("  %s\n", s.Name)
		}
	}
}

func emit(out string, jsonOut bool, pretty func(string)) {
	if jsonOut {
		fmt.Println(out)
		return
	}
	pretty(out)
}

// ── 共用：設定 / JSON-RPC（與 taskctl / memctl 鏡像）──────────────────────

type mcpConfig struct {
	Servers map[string]struct {
		URL     string            `json:"url"`
		Headers map[string]string `json:"headers"`
	} `json:"servers"`
}

func resolveEndpoint(suffix string) (string, string, error) {
	if tok := os.Getenv("MCP_TOKEN"); tok != "" {
		base := os.Getenv("MCP_BASE_URL")
		if base == "" {
			base = defaultBase
		}
		return strings.TrimRight(base, "/") + "/" + suffix, tok, nil
	}
	path, err := findUp(".vscode/mcp.json")
	if err != nil {
		return "", "", fmt.Errorf("找不到 token：請設 MCP_TOKEN 或提供 .vscode/mcp.json")
	}
	raw, err := os.ReadFile(path)
	if err != nil {
		return "", "", err
	}
	var cfg mcpConfig
	if err := json.Unmarshal(raw, &cfg); err != nil {
		return "", "", fmt.Errorf("解析 %s 失敗：%w", path, err)
	}
	for _, s := range cfg.Servers {
		if strings.HasSuffix(strings.TrimRight(s.URL, "/"), "/"+suffix) {
			auth := s.Headers["Authorization"]
			if auth == "" {
				auth = s.Headers["authorization"]
			}
			return s.URL, strings.TrimPrefix(auth, "Bearer "), nil
		}
	}
	return "", "", fmt.Errorf("%s 內找不到 /%s server", path, suffix)
}

func findUp(rel string) (string, error) {
	dir, err := os.Getwd()
	if err != nil {
		return "", err
	}
	for {
		p := filepath.Join(dir, rel)
		if _, err := os.Stat(p); err == nil {
			return p, nil
		}
		if parent := filepath.Dir(dir); parent != dir {
			dir = parent
		} else {
			return "", fmt.Errorf("not found")
		}
	}
}

func call(url, token, tool string, args map[string]any) (string, error) {
	reqBody, _ := json.Marshal(map[string]any{
		"jsonrpc": "2.0", "id": 1, "method": "tools/call",
		"params": map[string]any{"name": tool, "arguments": args},
	})
	req, err := http.NewRequest("POST", url, bytes.NewReader(reqBody))
	if err != nil {
		return "", err
	}
	req.Header.Set("Authorization", "Bearer "+token)
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json, text/event-stream")

	resp, err := (&http.Client{Timeout: 30 * time.Second}).Do(req)
	if err != nil {
		return "", err
	}
	defer resp.Body.Close()
	body, _ := io.ReadAll(resp.Body)

	if resp.StatusCode >= 400 {
		var e struct {
			Error   string `json:"error"`
			Message string `json:"message"`
		}
		_ = json.Unmarshal(extractJSON(body), &e)
		msg := e.Error
		if msg == "" {
			msg = e.Message
		}
		if msg == "" {
			msg = strings.TrimSpace(string(body))
			if r := []rune(msg); len(r) > 200 {
				msg = string(r[:200]) + "…"
			}
		}
		return "", fmt.Errorf("HTTP %d：%s", resp.StatusCode, msg)
	}

	var rpc struct {
		Result struct {
			Content []struct {
				Text string `json:"text"`
			} `json:"content"`
			IsError bool `json:"isError"`
		} `json:"result"`
		Error *struct {
			Message string `json:"message"`
		} `json:"error"`
	}
	if err := json.Unmarshal(extractJSON(body), &rpc); err != nil {
		return "", fmt.Errorf("回應解析失敗：%s", strings.TrimSpace(string(body)))
	}
	if rpc.Error != nil {
		return "", fmt.Errorf("RPC error: %s", rpc.Error.Message)
	}
	if len(rpc.Result.Content) == 0 {
		return "", fmt.Errorf("空回應")
	}
	if rpc.Result.IsError {
		return "", fmt.Errorf("%s", rpc.Result.Content[0].Text)
	}
	return rpc.Result.Content[0].Text, nil
}

func extractJSON(body []byte) []byte {
	if s := bytes.TrimSpace(body); len(s) > 0 && (s[0] == '{' || s[0] == '[') {
		return s
	}
	for _, line := range bytes.Split(body, []byte("\n")) {
		if line = bytes.TrimSpace(line); bytes.HasPrefix(line, []byte("data:")) {
			return bytes.TrimSpace(line[len("data:"):])
		}
	}
	return bytes.TrimSpace(body)
}

// ── 小工具 ───────────────────────────────────────────────────

func extractFlag(args []string, flagName string) (bool, []string) {
	if len(args) == 0 {
		return false, args
	}
	if args[0] == flagName {
		return true, args[1:]
	}
	if args[len(args)-1] == flagName {
		return true, args[:len(args)-1]
	}
	return false, args
}

func must(out string, err error) string {
	check(err)
	return out
}

func check(err error) {
	if err != nil {
		fmt.Fprintln(os.Stderr, "錯誤：", err)
		os.Exit(1)
	}
}

func usage() {
	fmt.Fprint(os.Stderr, `agydctl — agyd daemon MCP（/api/mcp/agyd）CLI

  run-prompt [--label l] <prompt...>   提交 agy 工作（非同步），回傳 task_id
  run-script [--label l] <name>        執行 daemon 預定義 script（非同步），回傳 task_id
  scripts                              列出 daemon 所有可用 scripts
  status <task_id>                     查詢工作狀態（running/done/failed）
  log <task_id>                        取得工作 stdout/stderr 輸出

  --json                               印原始 JSON（預設為精簡文字輸出）

token / url 自動從 .vscode/mcp.json 讀取（往上層目錄找），或用 MCP_TOKEN / MCP_BASE_URL 環境變數覆寫。
`)
}
