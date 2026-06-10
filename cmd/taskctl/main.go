// taskctl — 任務 MCP（/api/mcp/task）的精簡 CLI client。
// 目的同 memctl：省去 native MCP 的 context 常駐 schema 與冗長 curl。
// token / url 自動從 .vscode/mcp.json 讀，或用 MCP_TOKEN/MCP_BASE_URL 環境變數覆寫。
//
// 用法：
//
//	taskctl ls [--status s] [--project p]          列出任務（含子項）
//	taskctl get <id>                               取單一任務
//	taskctl add [--project p] [--desc d] [--status s] <title...>
//	taskctl set <id> [--title t] [--status s] [--project p] [--desc d] [--sort n]
//	taskctl rm <id>                                刪除任務
//	taskctl iadd <task_id> <content...>            新增子項
//	taskctl iset <id> [--content c] [--done] [--sort n]
//	taskctl irm <id>                               刪除子項
//	  （任意位置加 --json 印原始 JSON）
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

	url, token, err := resolveEndpoint("task")
	check(err)

	switch cmd {
	case "ls":
		fs := flag.NewFlagSet("ls", flag.ExitOnError)
		status := fs.String("status", "", "篩選狀態 todo/in_progress/done")
		project := fs.String("project", "", "篩選專案")
		fs.Parse(rest)
		a := map[string]any{}
		if *status != "" {
			a["status"] = *status
		}
		if *project != "" {
			a["project"] = *project
		}
		out := must(call(url, token, "list_tasks", a))
		emit(out, jsonOut, printTasks)
	case "get":
		out := must(call(url, token, "get_task", map[string]any{"id": mustInt(rest, "get <id>")}))
		emit(out, jsonOut, printTask)
	case "add":
		fs := flag.NewFlagSet("add", flag.ExitOnError)
		project := fs.String("project", "", "")
		desc := fs.String("desc", "", "")
		status := fs.String("status", "", "")
		fs.Parse(rest)
		title := strings.Join(fs.Args(), " ")
		if title == "" {
			fmt.Fprintln(os.Stderr, "用法：taskctl add [--project p] [--desc d] [--status s] <title...>")
			os.Exit(2)
		}
		a := map[string]any{"title": title}
		putIf(a, "project", *project)
		putIf(a, "description", *desc)
		putIf(a, "status", *status)
		out := must(call(url, token, "create_task", a))
		emit(out, jsonOut, printTask)
	case "set":
		id := mustInt(rest, "set <id> [--title|--status|--project|--desc|--sort]")
		fs := flag.NewFlagSet("set", flag.ExitOnError)
		title := fs.String("title", "", "")
		status := fs.String("status", "", "")
		project := fs.String("project", "", "")
		desc := fs.String("desc", "", "")
		sort := fs.Int("sort", 0, "")
		fs.Parse(rest[1:])
		a := map[string]any{"id": id}
		fs.Visit(func(f *flag.Flag) {
			switch f.Name {
			case "title":
				a["title"] = *title
			case "status":
				a["status"] = *status
			case "project":
				a["project"] = *project
			case "desc":
				a["description"] = *desc
			case "sort":
				a["sort"] = *sort
			}
		})
		out := must(call(url, token, "update_task", a))
		emit(out, jsonOut, printTask)
	case "rm":
		out := must(call(url, token, "delete_task", map[string]any{"id": mustInt(rest, "rm <id>")}))
		fmt.Println(out)
	case "iadd":
		need(rest, 2, "iadd <task_id> <content...>")
		out := must(call(url, token, "add_task_item", map[string]any{
			"task_id": mustAtoi(rest[0]), "content": strings.Join(rest[1:], " "),
		}))
		fmt.Println(out)
	case "iset":
		id := mustInt(rest, "iset <id> [--content|--done|--sort]")
		fs := flag.NewFlagSet("iset", flag.ExitOnError)
		content := fs.String("content", "", "")
		done := fs.Bool("done", false, "")
		sort := fs.Int("sort", 0, "")
		fs.Parse(rest[1:])
		a := map[string]any{"id": id}
		fs.Visit(func(f *flag.Flag) {
			switch f.Name {
			case "content":
				a["content"] = *content
			case "done":
				a["is_done"] = *done
			case "sort":
				a["sort"] = *sort
			}
		})
		out := must(call(url, token, "update_task_item", a))
		fmt.Println(out)
	case "irm":
		out := must(call(url, token, "delete_task_item", map[string]any{"id": mustInt(rest, "irm <id>")}))
		fmt.Println(out)
	default:
		usage()
		os.Exit(2)
	}
}

// ── 輸出格式（task 專屬）─────────────────────────────────────

type taskItem struct {
	ID      int    `json:"id"`
	Content string `json:"content"`
	IsDone  bool   `json:"is_done"`
}

type task struct {
	ID          int        `json:"id"`
	Title       string     `json:"title"`
	Status      string     `json:"status"`
	Project     string     `json:"project"`
	Description string     `json:"description"`
	Items       []taskItem `json:"items"`
}

func printTasks(text string) {
	var ts []task
	if json.Unmarshal([]byte(text), &ts) != nil {
		fmt.Println(text)
		return
	}
	fmt.Printf("TASKS (%d)\n", len(ts))
	for _, t := range ts {
		fmt.Printf("  #%d [%s]%s %s\n", t.ID, t.Status, proj(t.Project), t.Title)
		printItems(t.Items, "    ")
	}
}

func printTask(text string) {
	var t task
	if json.Unmarshal([]byte(text), &t) != nil {
		fmt.Println(text)
		return
	}
	fmt.Printf("#%d [%s]%s %s\n", t.ID, t.Status, proj(t.Project), t.Title)
	if t.Description != "" {
		fmt.Printf("  desc: %s\n", t.Description)
	}
	printItems(t.Items, "  ")
}

func printItems(items []taskItem, indent string) {
	for _, it := range items {
		mark := "☐"
		if it.IsDone {
			mark = "✔"
		}
		fmt.Printf("%s%s #%d %s\n", indent, mark, it.ID, it.Content)
	}
}

func proj(p string) string {
	if p == "" {
		return ""
	}
	return " (" + p + ")"
}

func emit(out string, jsonOut bool, pretty func(string)) {
	if jsonOut {
		fmt.Println(out)
		return
	}
	pretty(out)
}

func putIf(m map[string]any, k, v string) {
	if v != "" {
		m[k] = v
	}
}

// ── 共用：設定 / JSON-RPC（與 memctl 鏡像）──────────────────────

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
	found := false
	rest := make([]string, 0, len(args))
	for _, a := range args {
		if a == flagName {
			found = true
			continue
		}
		rest = append(rest, a)
	}
	return found, rest
}

func need(rest []string, n int, sig string) {
	if len(rest) < n {
		fmt.Fprintf(os.Stderr, "用法：taskctl %s\n", sig)
		os.Exit(2)
	}
}

func mustInt(rest []string, sig string) int {
	need(rest, 1, sig)
	return mustAtoi(rest[0])
}

func mustAtoi(s string) int {
	n, err := strconv.Atoi(s)
	if err != nil {
		fmt.Fprintf(os.Stderr, "需為整數：%s\n", s)
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
	fmt.Fprint(os.Stderr, `taskctl — 任務 MCP（/api/mcp/task）CLI

  ls [--status s] [--project p]                 列出所有任務（含子項）；可依 status(todo/in_progress/done) 或 project 篩選
  get <id>                                      以 id 取單一任務及其所有子項
  add [--project p][--desc d][--status s] <title...>  建立任務；可指定所屬 project 方便跨專案追蹤
  set <id> [--title t][--status s][--project p][--desc d][--sort n]  更新任務；只送有帶的旗標、其餘不動
  rm <id>                                       刪除任務及其所有子項，不可復原
  iadd <task_id> <content...>                   在指定任務下新增一個 checklist 子項
  iset <id> [--content c][--done][--sort n]     更新子項文字 / 完成狀態(is_done)
  irm <id>                                      刪除指定子項

  --json                                        印原始 JSON（預設為精簡文字輸出）

token / url 自動從 .vscode/mcp.json 讀取（往上層目錄找），或用 MCP_TOKEN / MCP_BASE_URL 環境變數覆寫。
`)
}
