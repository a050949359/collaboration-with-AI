package main

// ImpactNode：受影響的節點 + 距離起點的深度。
type ImpactNode struct {
	Node
	Depth int `json:"depth"`
}

// impact 對起點集合做反向 BFS（沿 caller 方向遞移），回「改這些會連帶影響到誰」。
// maxDepth <= 0 表示不限深度。
func (s *Store) impact(startIDs []string, maxDepth int) ([]ImpactNode, error) {
	visited := map[string]bool{}
	for _, id := range startIDs {
		visited[id] = true // 起點本身不列入結果
	}

	var out []ImpactNode
	frontier := append([]string(nil), startIDs...)

	for depth := 1; len(frontier) > 0; depth++ {
		if maxDepth > 0 && depth > maxDepth {
			break
		}
		callers, err := s.callers(frontier)
		if err != nil {
			return nil, err
		}
		var next []string
		for _, c := range callers {
			if visited[c.ID] {
				continue
			}
			visited[c.ID] = true
			out = append(out, ImpactNode{Node: c, Depth: depth})
			next = append(next, c.ID)
		}
		frontier = next
	}
	return out, nil
}
