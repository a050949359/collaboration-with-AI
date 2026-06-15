// ragctl — RAG 向量庫 CLI（chromem-go，純 Go、非常駐）
//
// Laravel 每次操作 exec 一次：JSON 走 stdin/stdout，做完即退、零常駐。
// 向量由呼叫端（Laravel + Gemini）算好提供，本工具只負責存與查（BYO embeddings）。
//
//	ragctl upsert  --db <dir> [--collection kb]   < {"documents":[{id,content,embedding,metadata}]}
//	ragctl query   --db <dir> [--collection kb]   < {"embedding":[...],"top_k":5,"where":{...}}
//	ragctl stats   --db <dir> [--collection kb]
//	ragctl delete  --db <dir> [--collection kb]   < {"ids":[...]} 或 {"where":{...}}
//	ragctl reset   --db <dir> [--collection kb]
package main

import (
	"context"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"os"

	chromem "github.com/philippgille/chromem-go"
)

func main() {
	if len(os.Args) < 2 {
		usage()
		os.Exit(2)
	}

	cmd := os.Args[1]
	fs := flag.NewFlagSet(cmd, flag.ExitOnError)
	dbPath := fs.String("db", env("RAGCTL_DB", "./rag_db"), "persistent DB 目錄")
	collName := fs.String("collection", "kb", "collection 名稱")
	_ = fs.Parse(os.Args[2:])

	var err error
	switch cmd {
	case "upsert":
		err = cmdUpsert(*dbPath, *collName)
	case "query":
		err = cmdQuery(*dbPath, *collName)
	case "stats":
		err = cmdStats(*dbPath, *collName)
	case "delete":
		err = cmdDelete(*dbPath, *collName)
	case "reset":
		err = cmdReset(*dbPath, *collName)
	case "-h", "--help", "help":
		usage()
		return
	default:
		fail(fmt.Errorf("未知子命令: %s", cmd))
	}

	if err != nil {
		fail(err)
	}
}

// ── 子命令 ──────────────────────────────────────────────────────────────

type docInput struct {
	ID        string            `json:"id"`
	Content   string            `json:"content"`
	Embedding []float32         `json:"embedding"`
	Metadata  map[string]string `json:"metadata"`
}

func cmdUpsert(dbPath, collName string) error {
	var in struct {
		Documents []docInput `json:"documents"`
	}
	if err := readJSON(&in); err != nil {
		return err
	}
	if len(in.Documents) == 0 {
		return outputJSON(map[string]any{"ok": true, "count": 0})
	}

	col, err := openCollection(dbPath, collName)
	if err != nil {
		return err
	}

	docs := make([]chromem.Document, 0, len(in.Documents))
	for _, d := range in.Documents {
		if d.ID == "" {
			return errors.New("每筆 document 需有 id")
		}
		if len(d.Embedding) == 0 {
			return fmt.Errorf("document %q 缺 embedding（向量由呼叫端提供）", d.ID)
		}
		docs = append(docs, chromem.Document{
			ID:        d.ID,
			Content:   d.Content,
			Embedding: d.Embedding,
			Metadata:  d.Metadata,
		})
	}

	if err := col.AddDocuments(context.Background(), docs, 1); err != nil {
		return err
	}
	return outputJSON(map[string]any{"ok": true, "count": len(docs), "total": col.Count()})
}

func cmdQuery(dbPath, collName string) error {
	var in struct {
		Embedding []float32         `json:"embedding"`
		TopK      int               `json:"top_k"`
		Where     map[string]string `json:"where"`
	}
	if err := readJSON(&in); err != nil {
		return err
	}
	if len(in.Embedding) == 0 {
		return errors.New("query 需提供 embedding")
	}
	if in.TopK <= 0 {
		in.TopK = 5
	}

	col, err := openCollection(dbPath, collName)
	if err != nil {
		return err
	}

	count := col.Count()
	if count == 0 {
		return outputJSON(map[string]any{"results": []any{}})
	}
	// chromem-go 要求 nResults <= 文件數，否則報錯
	if in.TopK > count {
		in.TopK = count
	}

	res, err := col.QueryEmbedding(context.Background(), in.Embedding, in.TopK, in.Where, nil)
	if err != nil {
		return err
	}

	out := make([]map[string]any, 0, len(res))
	for _, r := range res {
		out = append(out, map[string]any{
			"id":         r.ID,
			"content":    r.Content,
			"similarity": r.Similarity,
			"metadata":   r.Metadata,
		})
	}
	return outputJSON(map[string]any{"results": out})
}

func cmdStats(dbPath, collName string) error {
	db, err := chromem.NewPersistentDB(dbPath, false)
	if err != nil {
		return err
	}
	all := map[string]int{}
	for name, c := range db.ListCollections() {
		all[name] = c.Count()
	}
	col := db.GetCollection(collName, byoEmbed)
	count := 0
	if col != nil {
		count = col.Count()
	}
	return outputJSON(map[string]any{
		"collection":  collName,
		"count":       count,
		"collections": all,
	})
}

func cmdDelete(dbPath, collName string) error {
	var in struct {
		IDs   []string          `json:"ids"`
		Where map[string]string `json:"where"`
	}
	if err := readJSON(&in); err != nil {
		return err
	}
	if len(in.IDs) == 0 && len(in.Where) == 0 {
		return errors.New("delete 需提供 ids 或 where")
	}

	col, err := openCollection(dbPath, collName)
	if err != nil {
		return err
	}
	if err := col.Delete(context.Background(), in.Where, nil, in.IDs...); err != nil {
		return err
	}
	return outputJSON(map[string]any{"ok": true, "total": col.Count()})
}

func cmdReset(dbPath, collName string) error {
	db, err := chromem.NewPersistentDB(dbPath, false)
	if err != nil {
		return err
	}
	if err := db.DeleteCollection(collName); err != nil {
		return err
	}
	return outputJSON(map[string]any{"ok": true})
}

// ── 共用 ────────────────────────────────────────────────────────────────

// byoEmbed：向量一律由呼叫端提供，此 func 不應被觸發；若被呼叫代表有 document
// 漏帶 embedding，直接報錯而非偷打外部 API。
func byoEmbed(_ context.Context, _ string) ([]float32, error) {
	return nil, errors.New("ragctl: embedding 由呼叫端提供，不應呼叫 embedding func")
}

func openCollection(dbPath, collName string) (*chromem.Collection, error) {
	db, err := chromem.NewPersistentDB(dbPath, false)
	if err != nil {
		return nil, err
	}
	return db.GetOrCreateCollection(collName, nil, byoEmbed)
}

func readJSON(v any) error {
	dec := json.NewDecoder(os.Stdin)
	if err := dec.Decode(v); err != nil {
		return fmt.Errorf("讀取 stdin JSON 失敗: %w", err)
	}
	return nil
}

func outputJSON(v any) error {
	enc := json.NewEncoder(os.Stdout)
	return enc.Encode(v)
}

func fail(err error) {
	_ = json.NewEncoder(os.Stdout).Encode(map[string]any{"error": err.Error()})
	os.Exit(1)
}

func env(key, def string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return def
}

func usage() {
	fmt.Fprint(os.Stderr, `ragctl — RAG 向量庫 CLI（chromem-go，非常駐，BYO embeddings）

用法:
  ragctl <command> --db <dir> [--collection kb]

commands:
  upsert   存/更新文件   stdin: {"documents":[{"id","content","embedding":[...],"metadata":{}}]}
  query    向量檢索       stdin: {"embedding":[...],"top_k":5,"where":{}}
  stats    統計           （無 stdin）
  delete   刪除           stdin: {"ids":[...]} 或 {"where":{}}
  reset    清空 collection（無 stdin）

flags:
  --db <dir>           持久化 DB 目錄（或環境變數 RAGCTL_DB）
  --collection <name>  collection 名稱（預設 kb）

所有輸出為 JSON；錯誤輸出 {"error":...} 並以 exit code 1 結束。
`)
}
