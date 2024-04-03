<?php
declare(strict_types=1);
/**
 * @copyright Copyright (c) 2012 Ondřej Vodáček
 * @license New BSD License
 */

namespace Vodacek\GettextExtractor\Filters;

use Nette\Utils\FileSystem;
use PhpParser;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use Vodacek\GettextExtractor\Extractor;

class PHPFilter extends AFilter implements IFilter, PhpParser\NodeVisitor {

	/** @var mixed[] */
	private $data = [];

	public function __construct() {
		$this->addFunction('gettext', 1);
		$this->addFunction('_', 1);
		$this->addFunction('ngettext', 1, 2);
		$this->addFunction('_n', 1, 2);
		$this->addFunction('pgettext', 2, null, 1);
		$this->addFunction('_p', 2, null, 1);
		$this->addFunction('npgettext', 2, 3, 1);
		$this->addFunction('_np', 2, 3, 1);
	}

	/** @return mixed[] */
	public function extract(string $file): array {
		$this->data = array();
		$parser = (new PhpParser\ParserFactory())->create(PhpParser\ParserFactory::PREFER_PHP7);
		$stmts = $parser->parse(FileSystem::read($file));
		if ($stmts === null) {
			return [];
		}
		$traverser = new PhpParser\NodeTraverser();
		$traverser->addVisitor($this);
		$traverser->traverse($stmts);
		$data = $this->data;
		$this->data = [];
		return $data;
	}

	public function enterNode(Node $node) {
		$name = null;
		$args = [];
		if (($node instanceof MethodCall || $node instanceof StaticCall) && $node->name instanceof Identifier) {
			$name = $node->name->name;
			$args = $node->args;
		} elseif ($node instanceof FuncCall && $node->name instanceof Name) {
			$parts = $node->name->parts;
			$name = array_pop($parts);
			$args = $node->args;
		} else {
			return null;
		}
		if (!isset($this->functions[$name])) {
			return null;
		}
		foreach ($this->functions[$name] as $definition) {
			$this->processFunction($definition, $node, $args);
		}
	}

	/**
	 * @param mixed[] $definition
	 * @param Node $node
	 * @param Arg[] $args
	 */
	private function processFunction(array $definition, Node $node, array $args): void {
		$message = array(
			Extractor::LINE => $node->getLine()
		);
		foreach ($definition as $type => $position) {
			if (!isset($args[$position - 1])) {
				return;
			}
			$arg = $args[$position - 1]->value;
			if ($arg instanceof String_) {
				$message[$type] = $arg->value;
			} elseif ($arg instanceof Array_) {
				foreach ($arg->items as $item) {
					if ($item->value instanceof String_) {
						$message[$type][] = $item->value->value;
					}
				}
				if (count($message) === 1) { // line only
					return;
				}
			} elseif ($arg instanceof Node\Expr\BinaryOp\Concat) {
				$message[$type] = $this->processConcatenatedString($arg);
			} else {
				return;
			}
		}
		if (is_array($message[Extractor::SINGULAR])) {
			foreach ($message[Extractor::SINGULAR] as $value) {
				$tmp = $message;
				$tmp[Extractor::SINGULAR] = $value;
				$this->data[] = $tmp;
			}
		} else {
			$this->data[] = $message;
		}
	}

	private function processConcatenatedString(Node\Expr\BinaryOp\Concat $arg): string
	{
		$result = '';

		if ($arg->left instanceof Node\Expr\BinaryOp\Concat) {
			$result .= $this->processConcatenatedString($arg->left);
		} elseif ($arg->left instanceof String_) {
			$result .= $arg->left->value;
		}

		if ($arg->right instanceof String_) {
			$result .= $arg->right->value;
		}

		return $result;
	}

	/* PhpParser\NodeVisitor: dont need these *******************************/

	/** @param mixed[] $nodes */
	public function afterTraverse(array $nodes): void {
	}

	/** @param mixed[] $nodes */
	public function beforeTraverse(array $nodes): void {
	}

	public function leaveNode(Node $node): void {
	}
}
