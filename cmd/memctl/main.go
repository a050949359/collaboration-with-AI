// memctl — 知識圖譜 MCP（/api/mcp/memory）的精簡 CLI client。
// 目的：取代 native MCP（省去 context 常駐 schema）與冗長 curl；指令短、輸出 trim。
// token / url 自動從 .vscode/mcp.json 讀（往上層目錄找），或用 MCP_TOKEN/MCP_BASE_URL 環境變數覆寫。
//
// 用法：
//
//	memctl graph [entity]            讀圖（可選只看單一節點子圖）
//	memctl search <query>            關鍵字搜尋節點
//	memctl add <entity> <content...> 新增 observation（預設 desc）
//	memctl rmobs <id>                刪除 observation
//	memctl entity <name> <type>      建立節點
//	memctl rment <name>              刪除節點
//	memctl rel <from> <type> <to>    建立關係
//	memctl rmrel <from> <type> <to>  刪除關係
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

	url, token, err := resolveEndpoint("memory")
	check(err)

	switch cmd {
	case "graph":
		a := map[string]any{}
		if len(rest) > 0 {
			a["entity_name"] = rest[0]
		}
		out := must(call(url, token, "read_graph", a))
		if jsonOut {
			fmt.Println(out)
		} else {
			printGraph(out)
		}
	case "search":
		need(rest, 1, "search <query>")
		out := must(call(url, token, "search_nodes", map[string]any{"query": rest[0]}))
		if jsonOut {
			fmt.Println(out)
		} else {
			printEntities(out)
		}
	case "add":
		need(rest, 2, "add <entity> <content...>")
		out := must(call(url, token, "add_observation", map[string]any{
			"entity_name": rest[0], "content": strings.Join(rest[1:], " "),
		}))
		fmt.Println(out)
	case "rmobs":
		out := must(call(url, token, "remove_observation", map[string]any{"id": mustInt(rest, "rmobs <id>")}))
		fmt.Println(out)
	case "entity":
		need(rest, 2, "entity <name> <type>")
		out := must(call(url, token, "create_entity", map[string]any{"name": rest[0], "type": rest[1]}))
		fmt.Println(out)
	case "rment":
		need(rest, 1, "rment <name>")
		out := must(call(url, token, "delete_entity", map[string]any{"name": rest[0]}))
		fmt.Println(out)
	case "rel":
		need(rest, 3, "rel <from> <type> <to>")
		out := must(call(url, token, "create_relation", map[string]any{
			"from": rest[0], "relation_type": rest[1], "to": rest[2],
		}))
		fmt.Println(out)
	case "rmrel":
		need(rest, 3, "rmrel <from> <type> <to>")
		out := must(call(url, token, "delete_relation", map[string]any{
			"from": rest[0], "relation_type": rest[1], "to": rest[2],
		}))
		fmt.Println(out)
	default:
		usage()
		os.Exit(2)
	}
}

// ── 輸出格式（memory 專屬）─────────────────────────────────────

type observation struct {
	ID      int    `json:"id"`
	Content string `json:"content"`
}

type entity struct {
	Type         string        `json:"type"`
	Name         string        `json:"name"`
	Observations []observation `json:"observations"`
}

type relation struct {
	From         string `json:"from"`
	RelationType string `json:"relation_type"`
	To           string `json:"to"`
}

func printGraph(text string) {
	var g struct {
		Entities  []entity   `json:"entities"`
		Relations []relation `json:"relations"`
	}
	if json.Unmarshal([]byte(text), &g) != nil {
		fmt.Println(text)
		return
	}
	fmt.Printf("ENTITIES (%d)\n", len(g.Entities))
	for _, e := range g.Entities {
		printEntity(e)
	}
	fmt.Printf("\nRELATIONS (%d)\n", len(g.Relations))
	for _, r := range g.Relations {
		fmt.Printf("  %s --%s--> %s\n", r.From, r.RelationType, r.To)
	}
}

func printEntities(text string) {
	var es []entity
	if json.Unmarshal([]byte(text), &es) != nil {
		fmt.Println(text)
		return
	}
	fmt.Printf("MATCHED (%d)\n", len(es))
	for _, e := range es {
		printEntity(e)
	}
}

func printEntity(e entity) {
	fmt.Printf("  [%s] %s (%d obs)\n", e.Type, e.Name, len(e.Observations))
	for _, o := range e.Observations {
		fmt.Printf("    #%d %s\n", o.ID, o.Content)
	}
}

// ── 共用：設定 / JSON-RPC（與 taskctl 鏡像）──────────────────────

type mcpConfig struct {
	Servers map[string]struct {
		URL     string            `json:"url"`
		Headers map[string]string `json:"headers"`
	} `json:"servers"`
}

// resolveEndpoint 回傳指定 suffix（memory/task）的 url 與 token。
// 優先 MCP_TOKEN 環境變數（搭配 MCP_BASE_URL，預設 ohya.vip）；否則往上找 .vscode/mcp.json。
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
			return s.URL, strings.TrimPrefix(s.Headers["Authorization"], "Bearer "), nil
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

func extractFlag(args []string, flag string) (bool, []string) {
	found := false
	rest := make([]string, 0, len(args))
	for _, a := range args {
		if a == flag {
			found = true
			continue
		}
		rest = append(rest, a)
	}
	return found, rest
}

func need(rest []string, n int, sig string) {
	if len(rest) < n {
		fmt.Fprintf(os.Stderr, "用法：memctl %s\n", sig)
		os.Exit(2)
	}
}

func mustInt(rest []string, sig string) int {
	need(rest, 1, sig)
	n, err := strconv.Atoi(rest[0])
	if err != nil {
		fmt.Fprintf(os.Stderr, "id 需為整數：%s\n", rest[0])
		os.Exit(2)
	}
	return n
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
	fmt.Fprint(os.Stderr, `memctl — 知識圖譜 MCP（/api/mcp/memory）CLI

讀取：
  graph [entity]            讀圖譜；entity 指定則只回該節點及與其相連的 relations，不指定回整張圖
  search <query>            關鍵字搜尋節點（比對節點名稱、type、所有 observation 內容）
寫入：
  entity <name> <type>      建立節點；name 全域唯一，同名則回傳既有節點不重複建立。type 慣例 project/host/service
  rment <name>              刪除節點，並 cascade 刪除其所有 observation 與 relation，不可復原
  add <entity> <content...> 對節點附加一條 observation（type 走預設 desc；typed 資料請走後台面板/API）
  rmobs <id>                以 id 刪除單條 observation（id 取自 graph / search 回傳）
  rel <from> <type> <to>    建立有向關係 from→to；type 自由字串（calls_api/depends_on/deployed_on…），相同三元組不重複
  rmrel <from> <type> <to>  刪除指定有向關係（需三者齊全才能精確定位）

  --json                    印原始 JSON（預設為精簡文字輸出）

token / url 自動從 .vscode/mcp.json 讀取（往上層目錄找），或用 MCP_TOKEN / MCP_BASE_URL 環境變數覆寫。
`)
}
