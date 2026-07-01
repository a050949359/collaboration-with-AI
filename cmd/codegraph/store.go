package main

import (
	"database/sql"

	_ "modernc.org/sqlite" // 純 Go SQLite driver（免 cgo）
)

// Node / Edge 是統一 JSON 契約：任何語言的 extractor 都吐這個形狀，
// 下游儲存/查詢完全語言無關。
type Node struct {
	ID        string `json:"id"`        // 穩定唯一 id（Go 用 types 的 FullName）
	Type      string `json:"type"`      // func | method | type
	Name      string `json:"name"`      // 簡名
	Qualified string `json:"qualified"` // 套件限定名
	File      string `json:"file"`
	Line      int    `json:"line"`
}

type Edge struct {
	From       string  `json:"from"`
	To         string  `json:"to"`
	Type       string  `json:"type"` // CALLS | IMPORTS
	Confidence float64 `json:"confidence"`
	File       string  `json:"file"`
	Line       int     `json:"line"`
}

type Store struct{ db *sql.DB }

const schema = `
CREATE TABLE IF NOT EXISTS files (
  path TEXT PRIMARY KEY,
  hash TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS nodes (
  id        TEXT PRIMARY KEY,
  type      TEXT NOT NULL,
  name      TEXT NOT NULL,
  qualified TEXT NOT NULL,
  file      TEXT NOT NULL,
  line      INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS edges (
  from_id    TEXT NOT NULL,
  to_id      TEXT NOT NULL,
  type       TEXT NOT NULL,
  confidence REAL NOT NULL DEFAULT 1.0,
  file       TEXT,
  line       INTEGER
);
CREATE INDEX IF NOT EXISTS idx_edges_from ON edges(from_id);
CREATE INDEX IF NOT EXISTS idx_edges_to   ON edges(to_id);
CREATE INDEX IF NOT EXISTS idx_nodes_name ON nodes(name);
`

func openStore(path string) (*Store, error) {
	db, err := sql.Open("sqlite", path)
	if err != nil {
		return nil, err
	}
	if _, err := db.Exec(schema); err != nil {
		db.Close()
		return nil, err
	}
	return &Store{db: db}, nil
}

func (s *Store) Close() error { return s.db.Close() }

// WriteGraph 全量重建：清掉舊圖再寫入（第一版採整包 rebuild，語意最單純）。
func (s *Store) WriteGraph(nodes []Node, edges []Edge, files map[string]string) error {
	tx, err := s.db.Begin()
	if err != nil {
		return err
	}
	defer tx.Rollback()

	for _, t := range []string{"nodes", "edges", "files"} {
		if _, err := tx.Exec("DELETE FROM " + t); err != nil {
			return err
		}
	}

	ns, err := tx.Prepare(`INSERT OR REPLACE INTO nodes(id,type,name,qualified,file,line) VALUES(?,?,?,?,?,?)`)
	if err != nil {
		return err
	}
	defer ns.Close()
	for _, n := range nodes {
		if _, err := ns.Exec(n.ID, n.Type, n.Name, n.Qualified, n.File, n.Line); err != nil {
			return err
		}
	}

	es, err := tx.Prepare(`INSERT INTO edges(from_id,to_id,type,confidence,file,line) VALUES(?,?,?,?,?,?)`)
	if err != nil {
		return err
	}
	defer es.Close()
	for _, e := range edges {
		if _, err := es.Exec(e.From, e.To, e.Type, e.Confidence, e.File, e.Line); err != nil {
			return err
		}
	}

	fs, err := tx.Prepare(`INSERT OR REPLACE INTO files(path,hash) VALUES(?,?)`)
	if err != nil {
		return err
	}
	defer fs.Close()
	for p, h := range files {
		if _, err := fs.Exec(p, h); err != nil {
			return err
		}
	}

	return tx.Commit()
}

// FileHashes 回傳目前已索引的檔案雜湊（增量比對用）。
func (s *Store) FileHashes() (map[string]string, error) {
	rows, err := s.db.Query(`SELECT path,hash FROM files`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	out := map[string]string{}
	for rows.Next() {
		var p, h string
		if err := rows.Scan(&p, &h); err != nil {
			return nil, err
		}
		out[p] = h
	}
	return out, rows.Err()
}

// resolveSymbol：把使用者給的符號（簡名 / 限定名 / id）對應到節點。
func (s *Store) resolveSymbol(sym string) ([]Node, error) {
	return s.queryNodes(`SELECT id,type,name,qualified,file,line FROM nodes
		WHERE id=? OR qualified=? OR name=? ORDER BY qualified`, sym, sym, sym)
}

// search：模糊找節點名。
func (s *Store) search(pattern string) ([]Node, error) {
	like := "%" + pattern + "%"
	return s.queryNodes(`SELECT id,type,name,qualified,file,line FROM nodes
		WHERE name LIKE ? OR qualified LIKE ? ORDER BY qualified LIMIT 200`, like, like)
}

// callers：誰呼叫了這些節點。
func (s *Store) callers(ids []string) ([]Node, error) {
	return s.neighborNodes(ids, "to_id", "from_id")
}

// callees：這些節點呼叫了誰。
func (s *Store) callees(ids []string) ([]Node, error) {
	return s.neighborNodes(ids, "from_id", "to_id")
}

func (s *Store) neighborNodes(ids []string, matchCol, pickCol string) ([]Node, error) {
	if len(ids) == 0 {
		return nil, nil
	}
	ph, args := placeholders(ids)
	q := `SELECT DISTINCT n.id,n.type,n.name,n.qualified,n.file,n.line
		FROM edges e JOIN nodes n ON n.id = e.` + pickCol + `
		WHERE e.type IN ('CALLS','HANDLES','HTTP_CALLS') AND e.` + matchCol + ` IN (` + ph + `)
		ORDER BY n.qualified`
	return s.queryNodes(q, args...)
}

func (s *Store) queryNodes(q string, args ...any) ([]Node, error) {
	rows, err := s.db.Query(q, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []Node
	for rows.Next() {
		var n Node
		if err := rows.Scan(&n.ID, &n.Type, &n.Name, &n.Qualified, &n.File, &n.Line); err != nil {
			return nil, err
		}
		out = append(out, n)
	}
	return out, rows.Err()
}

func placeholders(ids []string) (string, []any) {
	ph := ""
	args := make([]any, len(ids))
	for i, id := range ids {
		if i > 0 {
			ph += ","
		}
		ph += "?"
		args[i] = id
	}
	return ph, args
}
