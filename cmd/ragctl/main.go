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
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"os"
	"path/filepath"
	"syscall"

	chromem "github.com/philippgille/chromem-go"
)

// strMap 容忍 PHP json_encode 空陣列：`[]` / `null` / 缺值都當成空 map。
// （PHP 空 array 會被編成 JSON `[]`，無法直接 unmarshal 進 map[string]string）
type strMap map[string]string

func (m *strMap) UnmarshalJSON(b []byte) error {
	t := bytes.TrimSpace(b)
	if len(t) == 0 || bytes.Equal(t, []byte("null")) || bytes.Equal(t, []byte("[]")) {
		*m = nil
		return nil
	}
	// 先收成 any 再轉字串：容忍 PHP 傳數字/布林 metadata（如 {"page":12}），
	// chromem-go 的 metadata 是 map[string]string，全部 stringify。
	var raw map[string]any
	if err := json.Unmarshal(b, &raw); err != nil {
		return err
	}
	mm := make(map[string]string, len(raw))
	for k, v := range raw {
		if v == nil {
			continue
		}
		mm[k] = fmt.Sprintf("%v", v)
	}
	*m = mm
	return nil
}

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
	ID        string    `json:"id"`
	Content   string    `json:"content"`
	Embedding []float32 `json:"embedding"`
	Metadata  strMap    `json:"metadata"`
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

	return withWriteLock(dbPath, func() error {
		col, err := openCollection(dbPath, collName)
		if err != nil {
			return err
		}
		if err := col.AddDocuments(context.Background(), docs, 1); err != nil {
			return err
		}
		return outputJSON(map[string]any{"ok": true, "count": len(docs), "total": col.Count()})
	})
}

func cmdQuery(dbPath, collName string) error {
	var in struct {
		Embedding []float32 `json:"embedding"`
		TopK      int       `json:"top_k"`
		Where     strMap    `json:"where"`          // metadata 精確過濾
		WhereDoc  strMap    `json:"where_document"` // 內容過濾：{"$contains":"..."} / {"$not_contains":"..."}
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

	res, err := col.QueryEmbedding(context.Background(), in.Embedding, in.TopK, in.Where, in.WhereDoc)
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
		IDs      []string `json:"ids"`
		Where    strMap   `json:"where"`
		WhereDoc strMap   `json:"where_document"`
	}
	if err := readJSON(&in); err != nil {
		return err
	}
	if len(in.IDs) == 0 && len(in.Where) == 0 && len(in.WhereDoc) == 0 {
		return errors.New("delete 需提供 ids / where / where_document 其一")
	}

	return withWriteLock(dbPath, func() error {
		col, err := openCollection(dbPath, collName)
		if err != nil {
			return err
		}
		if err := col.Delete(context.Background(), in.Where, in.WhereDoc, in.IDs...); err != nil {
			return err
		}
		return outputJSON(map[string]any{"ok": true, "total": col.Count()})
	})
}

func cmdReset(dbPath, collName string) error {
	return withWriteLock(dbPath, func() error {
		db, err := chromem.NewPersistentDB(dbPath, false)
		if err != nil {
			return err
		}
		if err := db.DeleteCollection(collName); err != nil {
			return err
		}
		return outputJSON(map[string]any{"ok": true})
	})
}

// ── 共用 ────────────────────────────────────────────────────────────────

// byoEmbed：向量一律由呼叫端提供，此 func 不應被觸發；若被呼叫代表有 document
// 漏帶 embedding，直接報錯而非偷打外部 API。
func byoEmbed(_ context.Context, _ string) ([]float32, error) {
	return nil, errors.New("ragctl: embedding 由呼叫端提供，不應呼叫 embedding func")
}

// withWriteLock 對寫入命令（upsert/delete/reset）加排他檔案鎖，避免並行寫入
// 互相覆蓋（chromem-go 是整庫載入記憶體、寫回磁碟，無內建並行保護）。
// 讀取命令（query/stats）不需要。
func withWriteLock(dbPath string, fn func() error) error {
	lockPath := dbPath + ".lock"
	if err := os.MkdirAll(filepath.Dir(lockPath), 0o755); err != nil {
		return err
	}
	f, err := os.OpenFile(lockPath, os.O_CREATE|os.O_RDWR, 0o644)
	if err != nil {
		return err
	}
	defer f.Close()
	if err := syscall.Flock(int(f.Fd()), syscall.LOCK_EX); err != nil {
		return err
	}
	defer func() { _ = syscall.Flock(int(f.Fd()), syscall.LOCK_UN) }()

	return fn()
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
  query    向量檢索       stdin: {"embedding":[...],"top_k":5,"where":{},"where_document":{"$contains":"..."}}
  stats    統計           （無 stdin）
  delete   刪除           stdin: {"ids":[...]} / {"where":{}} / {"where_document":{"$contains":"..."}}
  reset    清空 collection（無 stdin）

過濾器：where = metadata 精確比對；where_document = 內容子字串（$contains / $not_contains）。

flags:
  --db <dir>           持久化 DB 目錄（或環境變數 RAGCTL_DB）
  --collection <name>  collection 名稱（預設 kb）

所有輸出為 JSON；錯誤輸出 {"error":...} 並以 exit code 1 結束。
`)
}
