<?php

/**
 * codegraph PHP extractor
 *
 * 用 nikic/php-parser 把 PHP 檔解析成統一 JSON 契約 {nodes, edges}，供 codegraph Go 引擎合併。
 * 呼叫：php extract.php <root> <autoload.php>   檔案清單由 stdin 逐行給入。
 * 輸出：stdout 一份 JSON {nodes:[{id,type,name,qualified,file,line}], edges:[{from,to,type,confidence,file,line}]}。
 *
 * 解析策略（高信心優先，PHP 動態型別的部分留待後續）：
 *   - 定義：class/interface/trait/enum→type、method→method、function→func，id 用 FQN（NameResolver）
 *   - 邊(CALLS)：new X（→類別）、X::y() 靜態、func()、$this->m()；只留指向「內部已定義節點」的邊
 *   - 尚未做：$obj->m() 需型別推斷、繼承/trait 帶入的方法解析（會被內部過濾丟棄，低估非誤報）
 */

$root = $argv[1] ?? getcwd();
$autoload = $argv[2] ?? '';
if ($autoload === '' || ! is_file($autoload)) {
    fwrite(STDERR, "extract.php: 需要 nikic/php-parser 的 autoload 路徑（argv[2]）\n");
    exit(3);
}
require $autoload;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

final class Extractor extends NodeVisitorAbstract
{
    /** @var array<string,array> */
    public array $nodes = [];
    public array $edges = [];

    private array $classStack = [];
    private array $funcStack = [];
    private array $propTypeStack = []; // 每層 class：屬性名→型別 FQN（含建構子提升）
    private array $paramTypeStack = []; // 每層 func/method：變數名→型別 FQN
    private string $file = '';

    public function setFile(string $f): void { $this->file = $f; }

    private function addNode(string $id, string $type, string $name, int $line): void
    {
        if ($id === '' || isset($this->nodes[$id])) {
            return;
        }
        $this->nodes[$id] = [
            'id' => $id, 'type' => $type, 'name' => $name,
            'qualified' => $id, 'file' => $this->file, 'line' => $line,
        ];
    }

    private function edge(string $from, string $to, float $conf, int $line): void
    {
        if ($from === '' || $to === '') {
            return;
        }
        $this->edges[] = [
            'from' => $from, 'to' => $to, 'type' => 'CALLS',
            'confidence' => $conf, 'file' => $this->file, 'line' => $line,
        ];
    }

    // 從型別宣告節點取單一類別 FQN；解開 nullable，union/intersection 視為不明→null。
    private function typeName(?Node $t): ?string
    {
        if ($t instanceof Name) {
            return $t->toString();
        }
        if ($t instanceof NullableType) {
            return $this->typeName($t->type);
        }
        return null; // Identifier(內建)/UnionType/IntersectionType → 不解析
    }

    public function enterNode(Node $node)
    {
        // ---- 定義 ----
        if ($node instanceof Class_ || $node instanceof Interface_ || $node instanceof Trait_ || $node instanceof Enum_) {
            $fqn = isset($node->namespacedName) ? $node->namespacedName->toString() : ($node->name?->toString() ?? '');
            $this->addNode($fqn, 'type', $node->name?->toString() ?? $fqn, $node->getStartLine());
            $this->classStack[] = $fqn;
            $this->propTypeStack[] = $this->collectPropTypes($node);
        } elseif ($node instanceof ClassMethod) {
            $cls = end($this->classStack) ?: '';
            $id = $cls !== '' ? $cls.'::'.$node->name->toString() : '';
            $this->addNode($id, 'method', $node->name->toString(), $node->getStartLine());
            $this->funcStack[] = $id;
            $this->paramTypeStack[] = $this->collectParamTypes($node);
        } elseif ($node instanceof Function_) {
            $fqn = isset($node->namespacedName) ? $node->namespacedName->toString() : $node->name->toString();
            $this->addNode($fqn, 'func', $node->name->toString(), $node->getStartLine());
            $this->funcStack[] = $fqn;
            $this->paramTypeStack[] = $this->collectParamTypes($node);
        }

        // ---- 呼叫（限定在某個函式/方法內）----
        $from = end($this->funcStack) ?: '';
        if ($from !== '') {
            if ($node instanceof New_ && $node->class instanceof Name) {
                $this->edge($from, $node->class->toString(), 1.0, $node->getStartLine());
            } elseif ($node instanceof StaticCall && $node->class instanceof Name && $node->name instanceof Identifier) {
                $this->edge($from, $node->class->toString().'::'.$node->name->toString(), 1.0, $node->getStartLine());
            } elseif ($node instanceof FuncCall && $node->name instanceof Name) {
                $this->edge($from, $node->name->toString(), 0.95, $node->getStartLine());
            } elseif ($node instanceof MethodCall && $node->name instanceof Identifier) {
                $this->resolveMethodCall($from, $node);
            }
        }
    }

    // $obj->method() 解析：$this→本類別；typed 參數/區域變數；$this->typedProp。
    private function resolveMethodCall(string $from, MethodCall $node): void
    {
        $m = $node->name->toString();
        $var = $node->var;
        $type = null;
        $conf = 0.0;

        if ($var instanceof Variable && $var->name === 'this') {
            $type = end($this->classStack) ?: null;
            $conf = 0.9;
        } elseif ($var instanceof Variable && is_string($var->name)) {
            $params = end($this->paramTypeStack) ?: [];
            if (isset($params[$var->name])) {
                $type = $params[$var->name];
                $conf = 0.85;
            }
        } elseif ($var instanceof PropertyFetch && $var->var instanceof Variable
            && $var->var->name === 'this' && $var->name instanceof Identifier) {
            $props = end($this->propTypeStack) ?: [];
            $pn = $var->name->toString();
            if (isset($props[$pn])) {
                $type = $props[$pn];
                $conf = 0.85;
            }
        }

        if ($type !== null && $type !== '') {
            $this->edge($from, $type.'::'.$m, $conf, $node->getStartLine());
        }
    }

    /** @return array<string,string> 屬性名→型別 FQN（typed 屬性 + 建構子提升參數） */
    private function collectPropTypes(Node $class): array
    {
        $out = [];
        foreach ($class->stmts ?? [] as $stmt) {
            if ($stmt instanceof Property && ($t = $this->typeName($stmt->type)) !== null) {
                foreach ($stmt->props as $p) {
                    $out[$p->name->toString()] = $t;
                }
            }
            if ($stmt instanceof ClassMethod && strtolower($stmt->name->toString()) === '__construct') {
                foreach ($stmt->params as $param) {
                    // flags != 0 = 建構子提升為屬性
                    if ($param->flags !== 0 && $param->var instanceof Variable
                        && ($t = $this->typeName($param->type)) !== null) {
                        $out[$param->var->name] = $t;
                    }
                }
            }
        }
        return $out;
    }

    /** @return array<string,string> 變數名→型別 FQN（typed 參數） */
    private function collectParamTypes(Node $fn): array
    {
        $out = [];
        foreach ($fn->params ?? [] as $param) {
            if ($param->var instanceof Variable && is_string($param->var->name)
                && ($t = $this->typeName($param->type)) !== null) {
                $out[$param->var->name] = $t;
            }
        }
        return $out;
    }

    public function leaveNode(Node $node)
    {
        if ($node instanceof Class_ || $node instanceof Interface_ || $node instanceof Trait_ || $node instanceof Enum_) {
            array_pop($this->classStack);
            array_pop($this->propTypeStack);
        } elseif ($node instanceof ClassMethod || $node instanceof Function_) {
            array_pop($this->funcStack);
            array_pop($this->paramTypeStack);
        }
    }
}

$files = [];
while (($line = fgets(STDIN)) !== false) {
    $line = trim($line);
    if ($line !== '') {
        $files[] = $line;
    }
}

$parser = (new ParserFactory)->createForNewestSupportedVersion();
$ext = new Extractor();

foreach ($files as $abs) {
    $code = @file_get_contents($abs);
    if ($code === false) {
        continue;
    }
    try {
        $ast = $parser->parse($code);
    } catch (\Throwable $e) {
        fwrite(STDERR, "warn: 解析失敗 $abs: {$e->getMessage()}\n");
        continue;
    }
    if ($ast === null) {
        continue;
    }

    $rel = $abs;
    if (str_starts_with($abs, $root.'/')) {
        $rel = substr($abs, strlen($root) + 1);
    }
    $ext->setFile($rel);

    // 兩趟：先整棵 NameResolver 解析 FQN（避免抽取時偷看子節點型別尚未解析），再跑抽取
    $resolver = new NodeTraverser();
    $resolver->addVisitor(new NameResolver());
    $ast = $resolver->traverse($ast);

    $walk = new NodeTraverser();
    $walk->addVisitor($ext);
    $walk->traverse($ast);
}

// 只留指向「內部已定義節點」的邊（濾掉呼叫 stdlib/外部/未解析）
$internal = $ext->nodes;
$edges = [];
foreach ($ext->edges as $e) {
    if (isset($internal[$e['to']])) {
        $edges[] = $e;
    }
}

echo json_encode([
    'nodes' => array_values($ext->nodes),
    'edges' => $edges,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
