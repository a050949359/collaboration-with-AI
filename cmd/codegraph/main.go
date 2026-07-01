// codegraph — 給 LLM 用的靜態程式碼結構分析工具（第一刀：只做 Go、純 CLI）
//
// 用 go/packages + go/types 把 Go module 解析成呼叫圖，存獨立 SQLite，
// 提供 callers / callees / impact / search 結構查詢（回 JSON，供 LLM 消費）。
// 型別解析由 go/types 精準給出，無須 Tree-Sitter 那種猜測式 cascade。
//
// 用法：
//   codegraph index   <dir>     [--db path]   索引一個 Go module（需有 go.mod）
//   codegraph callers <symbol>  [--db path]   誰呼叫了它
//   codegraph callees <symbol>  [--db path]   它呼叫了誰
//   codegraph impact  <symbol>  [--db path] [--depth n]  改它會連帶影響誰（反向 BFS）
//   codegraph search  <pattern> [--db path]   模糊找符號
//
// symbol 可給簡名（Broadcast）、限定名或 id；多筆同名會一併處理並標出。
package main

import (
	"encoding/json"
	"flag"
	"fmt"
	"os"
)

// dbDefault：db 路徑預設值。優先序 --db 旗標 > CODEGRAPH_DB env > database/codegraph.db。
// 之後 Laravel 包 MCP 時比照 vecgen，用 env 傳路徑。
func dbDefault() string {
	if v := os.Getenv("CODEGRAPH_DB"); v != "" {
		return v
	}
	return "database/codegraph.db"
}

func main() {
	if len(os.Args) < 2 {
		usage()
		os.Exit(2)
	}
	cmd := os.Args[1]
	args := os.Args[2:]

	switch cmd {
	case "index":
		cmdIndex(args)
	case "callers":
		cmdNeighbors(args, "callers")
	case "callees":
		cmdNeighbors(args, "callees")
	case "impact":
		cmdImpact(args)
	case "search":
		cmdSearch(args)
	case "-h", "--help", "help":
		usage()
	default:
		fmt.Fprintf(os.Stderr, "未知指令：%s\n\n", cmd)
		usage()
		os.Exit(2)
	}
}

func cmdIndex(args []string) {
	fs := flag.NewFlagSet("index", flag.ExitOnError)
	db := fs.String("db", dbDefault(), "SQLite db 路徑")
	force := fs.Bool("force", false, "即使無變更也重建")
	_ = fs.Parse(args)
	if fs.NArg() < 1 {
		die("index 需要一個目錄參數（Go module，需有 go.mod）")
	}
	dir := fs.Arg(0)

	st, err := openStore(*db)
	check(err)
	defer st.Close()

	// 檔案雜湊集（相對掃描根）：同時當「有無變更」判斷與儲存基準。
	files, err := hashFiles(dir)
	check(err)
	if len(files) == 0 {
		die("找不到任何 .go 檔：" + dir)
	}

	// 增量：所有 .go 檔雜湊與上次一致就跳過（第一版為整包判斷）
	if !*force {
		if prev, err := st.FileHashes(); err == nil && len(prev) > 0 && sameHashes(files, prev) {
			fmt.Fprintln(os.Stderr, "無變更，跳過索引（--force 可強制）")
			emit(map[string]any{"indexed": false, "reason": "no changes"})
			return
		}
	}

	nodes, edges, err := buildGraph(dir)
	check(err)
	check(st.WriteGraph(nodes, edges, files))

	fmt.Fprintf(os.Stderr, "索引完成：%d 節點、%d 邊、%d 檔\n", len(nodes), len(edges), len(files))
	emit(map[string]any{"indexed": true, "nodes": len(nodes), "edges": len(edges), "files": len(files)})
}

func cmdNeighbors(args []string, dir string) {
	fs := flag.NewFlagSet(dir, flag.ExitOnError)
	db := fs.String("db", dbDefault(), "SQLite db 路徑")
	_ = fs.Parse(args)
	if fs.NArg() < 1 {
		die(dir + " 需要一個 symbol 參數")
	}
	st, err := openStore(*db)
	check(err)
	defer st.Close()

	matched, ids := resolve(st, fs.Arg(0))
	var res []Node
	if dir == "callers" {
		res, err = st.callers(ids)
	} else {
		res, err = st.callees(ids)
	}
	check(err)
	emit(map[string]any{"symbol": fs.Arg(0), "matched": matched, dir: res})
}

func cmdImpact(args []string) {
	fs := flag.NewFlagSet("impact", flag.ExitOnError)
	db := fs.String("db", dbDefault(), "SQLite db 路徑")
	depth := fs.Int("depth", 0, "最大深度（0=不限）")
	_ = fs.Parse(args)
	if fs.NArg() < 1 {
		die("impact 需要一個 symbol 參數")
	}
	st, err := openStore(*db)
	check(err)
	defer st.Close()

	matched, ids := resolve(st, fs.Arg(0))
	res, err := st.impact(ids, *depth)
	check(err)
	emit(map[string]any{"symbol": fs.Arg(0), "matched": matched, "impacted": res, "count": len(res)})
}

func cmdSearch(args []string) {
	fs := flag.NewFlagSet("search", flag.ExitOnError)
	db := fs.String("db", dbDefault(), "SQLite db 路徑")
	_ = fs.Parse(args)
	if fs.NArg() < 1 {
		die("search 需要一個 pattern 參數")
	}
	st, err := openStore(*db)
	check(err)
	defer st.Close()

	res, err := st.search(fs.Arg(0))
	check(err)
	emit(map[string]any{"pattern": fs.Arg(0), "results": res})
}

// resolve 把 symbol 對應到節點，回（符合的節點清單, id 清單）。找不到就報錯退出。
func resolve(st *Store, sym string) ([]Node, []string) {
	matched, err := st.resolveSymbol(sym)
	check(err)
	if len(matched) == 0 {
		emit(map[string]any{"error": "symbol 找不到：" + sym, "hint": "先 index，或用 search 找正確名稱"})
		os.Exit(1)
	}
	ids := make([]string, len(matched))
	for i, n := range matched {
		ids[i] = n.ID
	}
	return matched, ids
}

func emit(v any) {
	b, _ := json.MarshalIndent(v, "", "  ")
	fmt.Println(string(b))
}

func check(err error) {
	if err != nil {
		fmt.Fprintln(os.Stderr, "error:", err)
		os.Exit(1)
	}
}

func die(msg string) {
	fmt.Fprintln(os.Stderr, "error:", msg)
	os.Exit(2)
}

func usage() {
	fmt.Fprint(os.Stderr, `codegraph — 給 LLM 用的靜態程式碼結構分析工具（Go）

  index   <dir>     [--db path] [--force]      索引 Go module（需 go.mod）
  callers <symbol>  [--db path]                誰呼叫了它
  callees <symbol>  [--db path]                它呼叫了誰
  impact  <symbol>  [--db path] [--depth n]    改它會連帶影響誰（反向 BFS）
  search  <pattern> [--db path]                模糊找符號

  symbol 可給簡名 / 限定名 / id；查詢結果為 JSON。
  db 路徑優先序：--db > 環境變數 CODEGRAPH_DB > database/codegraph.db。
`)
}
