// codegraphctl — 靜態程式碼結構圖 MCP（/api/mcp/codegraph）的精簡 CLI client。
// 目的：取代 native MCP（省去 context 常駐 schema）與冗長 curl；指令短、輸出 trim。
// token / url 解析優先序：MCP_CODEGRAPH_TOKEN（專屬）> MCP_TOKEN（共用）> .vscode/mcp.json（從當前目錄往上層找）。base url 用 MCP_BASE_URL 覆寫。
//
// 用法：
//
//	codegraphctl search  <query>          模糊找符號（name/qualified）
//	codegraphctl callers <symbol>         誰呼叫了它
//	codegraphctl callees <symbol>         它呼叫了誰
//	codegraphctl impact  <symbol> [depth] 改它會連帶影響誰（反向 BFS）
//	codegraphctl trace   <from> <to> [depth] from 是怎麼呼叫到 to 的（最短路）
//	codegraphctl node    <symbol>         取節點細節
//	  （任意位置加 --json 印原始 JSON）
package main

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"strconv"
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

	url, token, err := resolveEndpoint("codegraph")
	check(err)

	switch cmd {
	case "search":
		need(rest, 1, "search <query>")
		out := must(call(url, token, "codegraph_search", map[string]any{"query": rest[0]}))
		emit(jsonOut, out, printNodes)
	case "callers":
		need(rest, 1, "callers <symbol>")
		out := must(call(url, token, "codegraph_callers", map[string]any{"symbol": rest[0]}))
		emit(jsonOut, out, printNodes)
	case "callees":
		need(rest, 1, "callees <symbol>")
		out := must(call(url, token, "codegraph_callees", map[string]any{"symbol": rest[0]}))
		emit(jsonOut, out, printNodes)
	case "impact":
		need(rest, 1, "impact <symbol> [depth]")
		out := must(call(url, token, "codegraph_impact", withDepth(map[string]any{"symbol": rest[0]}, rest, 1)))
		emit(jsonOut, out, printImpact)
	case "trace":
		need(rest, 2, "trace <from> <to> [depth]")
		out := must(call(url, token, "codegraph_trace_call_path", withDepth(map[string]any{"from": rest[0], "to": rest[1]}, rest, 2)))
		emit(jsonOut, out, printTrace)
	case "node":
		need(rest, 1, "node <symbol>")
		out := must(call(url, token, "codegraph_get_node", map[string]any{"symbol": rest[0]}))
		emit(jsonOut, out, printNodes)
	default:
		usage()
		os.Exit(2)
	}
}

// ── 輸出格式（codegraph 專屬）──────────────────────────────────

type node struct {
	ID        string `json:"id"`
	Type      string `json:"type"`
	Name      string `json:"name"`
	Qualified string `json:"qualified"`
	File      string `json:"file"`
	Line      int    `json:"line"`
	Lang      string `json:"lang"`
	Depth     int    `json:"depth"` // impact 才有
}

func (n node) line() string {
	return fmt.Sprintf("  [%s] %-6s %s  (%s:%d)", n.Lang, n.Type, n.Qualified, n.File, n.Line)
}

func printNodes(text string) {
	var r struct {
		Symbol  string   `json:"symbol"`
		Matched []string `json:"matched"`
		Nodes   []node   `json:"nodes"`
	}
	if json.Unmarshal([]byte(text), &r) != nil {
		fmt.Println(text)
		return
	}
	if len(r.Matched) > 0 {
		fmt.Printf("MATCHED SYMBOL: %s\n", strings.Join(r.Matched, ", "))
	}
	fmt.Printf("NODES (%d)\n", len(r.Nodes))
	for _, n := range r.Nodes {
		fmt.Println(n.line())
	}
}

func printImpact(text string) {
	var r struct {
		Matched  []string `json:"matched"`
		Impacted []node   `json:"impacted"`
		Capped   bool     `json:"capped"`
	}
	if json.Unmarshal([]byte(text), &r) != nil {
		fmt.Println(text)
		return
	}
	if len(r.Matched) > 0 {
		fmt.Printf("MATCHED SYMBOL: %s\n", strings.Join(r.Matched, ", "))
	}
	fmt.Printf("IMPACTED (%d)%s\n", len(r.Impacted), capNote(r.Capped))
	for _, n := range r.Impacted {
		fmt.Printf("  d%d [%s] %-6s %s  (%s:%d)\n", n.Depth, n.Lang, n.Type, n.Qualified, n.File, n.Line)
	}
}

func printTrace(text string) {
	var r struct {
		From  string `json:"from"`
		To    string `json:"to"`
		Found bool   `json:"found"`
		Hops  int    `json:"hops"`
		Path  []node `json:"path"`
	}
	if json.Unmarshal([]byte(text), &r) != nil {
		fmt.Println(text)
		return
	}
	if !r.Found {
		fmt.Printf("NO PATH: %s → %s（深度內無呼叫路徑）\n", r.From, r.To)
		return
	}
	fmt.Printf("PATH (%d hops)\n", r.Hops)
	for i, n := range r.Path {
		arrow := "  "
		if i > 0 {
			arrow = "↓ "
		}
		fmt.Printf("%s[%s] %-6s %s  (%s:%d)\n", arrow, n.Lang, n.Type, n.Qualified, n.File, n.Line)
	}
}

func capNote(capped bool) string {
	if capped {
		return " ⚠ 已截斷（達上限）"
	}
	return ""
}

// ── 共用：設定 / JSON-RPC（與 memctl/taskctl 鏡像）────────────────

type mcpConfig struct {
	Servers map[string]struct {
		URL     string            `json:"url"`
		Headers map[string]string `json:"headers"`
	} `json:"servers"`
}

// resolveEndpoint 回傳指定 suffix（codegraph）的 url 與 token。
// 優先序：MCP_<SUFFIX>_TOKEN（專屬）> MCP_TOKEN（共用，搭配 MCP_BASE_URL，預設 ohya.vip）> .vscode/mcp.json。
func resolveEndpoint(suffix string) (string, string, error) {
	tok := os.Getenv("MCP_" + strings.ToUpper(suffix) + "_TOKEN")
	if tok == "" {
		tok = os.Getenv("MCP_TOKEN")
	}
	if tok != "" {
		base := os.Getenv("MCP_BASE_URL")
		if base == "" {
			base = defaultBase
		}
		return strings.TrimRight(base, "/") + "/" + suffix, tok, nil
	}
	path, err := findUp(".vscode/mcp.json")
	if err != nil {
		return "", "", fmt.Errorf("未設定 API key：請 export MCP_%s_TOKEN（或共用 MCP_TOKEN），或在當前目錄樹放 .vscode/mcp.json", strings.ToUpper(suffix))
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
				auth = s.Headers["authorization"] // header key 大小寫相容
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

	// HTTP 層錯誤（認證失敗、路由錯誤、5xx…）：直接回報，別落到下面的「解析失敗/空回應」
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

// extractJSON 取出回應主體（相容 plain JSON 與 SSE 的 data: 行）。
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

// emit：--json 印原文，否則走精簡 printer。
func emit(jsonOut bool, out string, pretty func(string)) {
	if jsonOut {
		fmt.Println(out)
		return
	}
	pretty(out)
}

// withDepth：若 rest 在 idx 位置有值且為正整數，塞進 depth。
func withDepth(args map[string]any, rest []string, idx int) map[string]any {
	if len(rest) > idx {
		if d, err := strconv.Atoi(rest[idx]); err == nil {
			args["depth"] = d
		}
	}
	return args
}

// 只認最前或最後位置的旗標，避免誤刪內容中間剛好等於 --json 的參數
func extractFlag(args []string, flag string) (bool, []string) {
	if len(args) == 0 {
		return false, args
	}
	if args[0] == flag {
		return true, args[1:]
	}
	if args[len(args)-1] == flag {
		return true, args[:len(args)-1]
	}

	return false, args
}

func need(rest []string, n int, sig string) {
	if len(rest) < n {
		fmt.Fprintf(os.Stderr, "用法：codegraphctl %s\n", sig)
		os.Exit(2)
	}
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
	fmt.Fprint(os.Stderr, `codegraphctl — 靜態程式碼結構圖 MCP（/api/mcp/codegraph）CLI

查詢（唯讀；圖由 cmd/codegraph index 產生的快照）：
  search  <query>            模糊找符號（比對 name / qualified，LIKE 部分比對）
  callers <symbol>           誰呼叫了它（沿 CALLS/HANDLES/HTTP_CALLS 邊，含跨語言）
  callees <symbol>           它呼叫了誰
  impact  <symbol> [depth]   改它會連帶影響誰（反向 BFS；depth 省略=不限）
  trace   <from> <to> [depth] from 是怎麼（經幾層）呼叫到 to 的（最短路徑）
  node    <symbol>           取節點細節（id/type/name/file/line/lang）

  --json                     印原始 JSON（預設為精簡文字輸出）

symbol 可給簡名（Broadcast）、限定名或 id
（Go (*pkg.T).M、PHP Ns\Class::method、TS relpath:name、route "VERB /api/...")。

token 解析優先序：MCP_CODEGRAPH_TOKEN > MCP_TOKEN > .vscode/mcp.json（從當前目錄往上層找）。base url 可用 MCP_BASE_URL 覆寫。
`)
}
